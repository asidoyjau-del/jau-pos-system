<?php
declare(strict_types=1);

namespace ProCast\Controllers\PlatformAdmin;

use PDO;
use ProCast\Support\Audit;
use ProCast\Support\Crypto;
use ProCast\Support\Csrf;
use ProCast\Support\Env;
use ProCast\Support\HealthCheck;
use ProCast\Support\Mailer;
use ProCast\Support\Request;
use ProCast\Support\Response;
use ProCast\Support\StoreRepository;
use ProCast\Support\View;

/**
 * Store approval queue, store lifecycle actions, global monitoring and the
 * user activity inspector. Reached only through the Kernel AFTER the guard
 * has validated session, CSRF (for POST) and admin status.
 */
final class SuperAdminDashboardController
{
    private const TIERS = ['free', 'standard', 'pro'];

    public function __construct(private PDO $db, private StoreRepository $stores)
    {
    }

    // ── Monitoring ────────────────────────────────────────────────────────
    /** @param array<string,mixed> $session @param array<string,mixed> $admin */
    public function monitoring(Request $req, array &$session, array $admin, int $now): Response
    {
        return $this->page('monitoring', $session, $admin, 'Global monitoring', 'monitoring', [
            'counts' => $this->stores->counts(),
            'sessions' => $this->stores->activeSessions($now),
            'volume' => $this->stores->volume($now),
        ]);
    }

    public function telemetry(int $now): Response
    {
        return Response::json([
            'counts'   => $this->stores->counts(),
            'sessions' => $this->stores->activeSessions($now),
            'volume'   => $this->stores->volume($now),
            'at'       => gmdate('c', $now),
        ]);
    }

    public function health(): Response
    {
        return Response::json(HealthCheck::forecastingEngine());
    }

    // ── Store queue / detail ──────────────────────────────────────────────
    /** @param array<string,mixed> $session @param array<string,mixed> $admin */
    public function stores(Request $req, array &$session, array $admin): Response
    {
        $status = $req->queryStr('status', 'pending_approval');
        $filter = $status === 'all' ? null : (in_array($status, StoreRepository::STATUSES, true) ? $status : 'pending_approval');
        $q = $req->queryStr('q');
        return $this->page('stores', $session, $admin, 'Store applications', 'stores', [
            'rows' => $this->stores->list($filter, $q),
            'status' => $filter ?? 'all',
            'q' => mb_substr($q, 0, 100),
            'counts' => $this->stores->counts(),
        ]);
    }

    /** @param array<string,mixed> $session @param array<string,mixed> $admin */
    public function storeDetail(Request $req, array &$session, array $admin, int $storeId): Response
    {
        $store = $this->stores->find($storeId);
        if ($store === null) {
            return Response::notFound();
        }
        return $this->page('store_detail', $session, $admin, (string)$store['name'], 'stores', [
            'store'    => $store,
            'pairings' => $this->stores->pairingsFor($storeId, gmdate('Y-m-d H:i:s')),
            'userCount'=> $this->stores->userCount($storeId),
            'team'     => $this->stores->usersOfStore($storeId),
        ]);
    }

    /** How the store runs ProCast: browser, installed PWA, or offline install. */
    public function setClientType(Request $req, array &$session, array $admin, int $storeId): Response
    {
        $store = $this->stores->find($storeId);
        if ($store === null) {
            return Response::notFound();
        }
        $type = trim($req->input('client_type'));
        if (!$this->stores->setClientType($storeId, $type)) {
            return $this->flashBack($session, $storeId, 'error', 'Unknown client type.');
        }
        Audit::log($this->db, (int)$admin['id'], Audit::STORE_CLIENT_SET, $storeId, $req->ip, $req->userAgent, ['client_type' => $type]);
        return $this->flashBack($session, $storeId, 'success', 'Client type set to "' . $type . '".');
    }

    /**
     * IRREVERSIBLE hard delete. Requires the exact store name typed back plus a
     * reason, so it cannot be triggered by a stray click or a CSRF-shaped POST.
     *
     * @param array<string,mixed> $admin
     */
    public function destroy(Request $req, array &$session, array $admin, int $storeId): Response
    {
        $store = $this->stores->find($storeId);
        if ($store === null) {
            return Response::notFound();
        }
        if (!$this->confirmed($req, (string)$store['name'])) {
            return $this->flashBack($session, $storeId, 'error', 'Type the exact store name to confirm the deletion.');
        }
        $reason = trim($req->input('reason'));
        if (mb_strlen($reason) < 10 || mb_strlen($reason) > 500) {
            return $this->flashBack($session, $storeId, 'error', 'A reason of 10-500 characters is required for a permanent delete.');
        }

        $userCount = $this->stores->userCount($storeId);

        $this->db->beginTransaction();
        try {
            // Tombstone first: if the deletes below fail, the whole thing rolls
            // back and we never end up with a deleted store and no record of it.
            $this->stores->recordDeletion($storeId, $store, $userCount, (int)$admin['id'], $reason);
            $result = $this->stores->destroy($storeId);
            if (!$result['ok']) {
                throw new \RuntimeException('Store row was not removed.');
            }
            Audit::log($this->db, (int)$admin['id'], Audit::STORE_DELETED, null, $req->ip, $req->userAgent, [
                'store_id'   => $storeId,
                'name'       => $store['name'],
                'reason'     => $reason,
                'cascaded'   => $result['deleted'],
            ]);
            $this->db->commit();
        } catch (\Throwable $t) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            return $this->flashBack($session, $storeId, 'error', 'Delete failed and was rolled back: ' . $t->getMessage());
        }

        unset($session['sa_flash'], $session['sa_flash_secret']);
        $session['sa_flash'] = ['type' => 'success', 'msg' => sprintf(
            'Permanently deleted "%s" along with %d user(s) and %d login token(s). This cannot be undone.',
            (string)$store['name'],
            $result['deleted']['users'],
            $result['deleted']['auth_tokens']
        )];
        return Response::redirect('/stores');
    }

    /**
     * Issues a 6-digit code the store types into its OFFLINE POS to activate it.
     * Emailed to the owner AND shown once on screen (in case email is filtered).
     * Only the SHA-256 of the code is stored.
     *
     * @param array<string,mixed> $admin
     */
    public function issuePairing(Request $req, array &$session, array $admin, int $storeId): Response
    {
        $store = $this->stores->find($storeId);
        if ($store === null) {
            return Response::notFound();
        }
        $code = str_pad((string)random_int(0, 999999), 6, '0', STR_PAD_LEFT);
        $ttl  = max(300, Env::get('PAIRING_CODE_TTL') !== null ? (int)Env::get('PAIRING_CODE_TTL') : 86400);
        $expiresUtc = gmdate('Y-m-d H:i:s', time() + $ttl);

        $this->stores->issuePairing($storeId, (int)$admin['id'], hash('sha256', $code), $expiresUtc, $this->sealCode($code));
        Audit::log($this->db, (int)$admin['id'], Audit::PAIRING_ISSUED, $storeId, $req->ip, $req->userAgent, ['ttl_seconds' => $ttl]);

        // Supersede any earlier unused code so only the newest one works.
        $this->stores->invalidatePairings($storeId);

        $mailNote = $this->notifyOwner($store, $admin, $req, 'Your ProCast activation code', sprintf(
            '<p>Hello %s,</p>'
            . '<p>Your activation code for <strong>%s</strong> is:</p>'
            . '<p style="font-size:28px;font-weight:800;letter-spacing:.3em;margin:20px 0;">%s</p>'
            . '<p>Open ProCast on the computer you want to install it on and enter this code when asked. '
            . 'It works <strong>once</strong>, and expires on <strong>%s UTC</strong>.</p>'
            . '<p>If you did not request this, you can ignore this email &mdash; nothing has changed.</p>',
            View::e($store['owner_name'] ?? ''),
            View::e($store['name']),
            View::e($code),
            View::e(gmdate('Y-m-d H:i', time() + $ttl))
        ));

        $session['sa_flash_secret'] = [
            'label' => 'Local POS activation code (valid once, expires ' . gmdate('H:i', time() + $ttl) . ' UTC)',
            'value' => $code,
        ];
        return $this->flashBack($session, $storeId, 'success', 'Activation code issued. ' . $mailNote);
    }

    /**
     * Issues a 6-digit code for ONE account of a store. This is the gate every
     * person must pass to use the local POS — including cashier/staff accounts
     * created later in Settings, which is why it is per-user and not per-store.
     *
     * The code is emailed to the account holder (falling back to the owner when
     * the account has no email of its own) and shown once on screen in case the
     * mail is filtered.
     *
     * @param array<string,mixed> $admin
     */
    public function issueUserPairing(Request $req, array &$session, array $admin, int $userId): Response
    {
        $user = $this->stores->findUser($userId);
        if ($user === null) {
            return Response::notFound();
        }
        $storeId = (int)($user['store_id'] ?? 0);
        $store   = $storeId > 0 ? $this->stores->find($storeId) : null;
        if ($store === null) {
            return $this->flashBack($session, $storeId, 'error', 'That account is not linked to a store.');
        }

        $code = str_pad((string)random_int(0, 999999), 6, '0', STR_PAD_LEFT);
        $ttl  = max(300, Env::get('PAIRING_CODE_TTL') !== null ? (int)Env::get('PAIRING_CODE_TTL') : 86400);

        $this->stores->issueUserPairing($userId, $storeId, (int)$admin['id'], hash('sha256', $code), gmdate('Y-m-d H:i:s', time() + $ttl), $this->sealCode($code));
        Audit::log($this->db, (int)$admin['id'], Audit::PAIRING_ISSUED, $storeId, $req->ip, $req->userAgent, [
            'user_id' => $userId,
            'scope'   => 'user',
            'ttl_seconds' => $ttl,
        ]);

        // Send to the account holder; fall back to the owner (notifyOwner already
        // audits success/failure and handles a missing address).
        $target = trim((string)($user['email'] ?? ''));
        $mailNote = '';
        if ($target !== '') {
            [$ok, $msg] = Mailer::send($target, (string)($user['full_name'] ?? ''), 'Your ProCast activation code', sprintf(
                '<p>Hello %s,</p>'
                . '<p>Your activation code for your ProCast account <strong>%s</strong> is:</p>'
                . '<p style="font-size:28px;font-weight:800;letter-spacing:.3em;margin:20px 0;">%s</p>'
                . '<p>Open ProCast on the computer you use at the till, enter this code when asked, '
                . 'then sign in with your username and password. It works <strong>once</strong>, '
                . 'and expires on <strong>%s UTC</strong>.</p>'
                . '<p>You only need it the first time &mdash; your computer stays activated.</p>',
                View::e($user['full_name'] ?? ''),
                View::e($user['username']),
                View::e($code),
                View::e(gmdate('Y-m-d H:i', time() + $ttl))
            ));
            Audit::log($this->db, (int)$admin['id'], $ok ? Audit::EMAIL_SENT : Audit::EMAIL_FAILED, $storeId, $req->ip, $req->userAgent, ['subject' => 'activation code', 'user_id' => $userId, 'result' => $ok ? 'sent' : $msg]);
            $mailNote = $ok ? 'Code emailed to ' . $target . '.' : 'Email could not be sent (' . $msg . ') — read the code below to the user.';
        } else {
            $mailNote = 'This account has no email on file, so read the code below to the user.';
        }

        $session['sa_flash_secret'] = [
            'label' => 'Activation code for ' . (string)$user['username'] . ' (valid once, expires ' . gmdate('H:i', time() + $ttl) . ' UTC)',
            'value' => $code,
        ];
        return $this->flashBack($session, $storeId, 'success', 'Activation code issued for ' . (string)$user['username'] . '. ' . $mailNote);
    }

    /**
     * Encrypts an activation code for later re-copying by a super admin.
     *
     * Email is only a convenience here — the admin is the real fallback channel,
     * because they are the one who can re-issue or talk the user through it. So a
     * code must survive the admin closing the tab or navigating away, otherwise
     * they would have to revoke and re-mint a perfectly good code.
     *
     * Encryption is best-effort on purpose: if PLATFORM_ENC_KEY is missing or
     * broken we still issue the code (hash + email + on-screen flash), just
     * without the re-copy fallback. Refusing to issue would turn a
     * misconfiguration into an outage; losing re-copy is a far smaller problem
     * than nobody being able to activate at all.
     */
    private function sealCode(string $code): ?string
    {
        try {
            return Crypto::encrypt($code);
        } catch (\Throwable $e) {
            error_log('Pairing code could not be sealed for re-copy: ' . $e->getMessage());
            return null;
        }
    }

    /**
     * Re-shows one account's LIVE activation code so the admin can copy it by
     * hand when the email didn't arrive.
     *
     * Restricted to codes that are still unused and unexpired — once a code is
     * spent or stale there is nothing useful to hand over, and showing it would
     * only invite the admin to pass on a code that cannot work. Every reveal is
     * audited, since it hands a live credential to whoever is at the keyboard.
     *
     * @param array<string,mixed> $admin
     */
    public function revealUserPairing(Request $req, array &$session, array $admin, int $userId): Response
    {
        $user = $this->stores->findUser($userId);
        if ($user === null) {
            return Response::notFound();
        }
        $storeId = (int)($user['store_id'] ?? 0);
        $username = (string)$user['username'];

        $cipher = $this->stores->liveUserPairingCipher($userId, gmdate('Y-m-d H:i:s'));
        if ($cipher === null) {
            return $this->flashBack($session, $storeId, 'error',
                'No live activation code for ' . $username . ' — it has already been used, revoked, or expired. Issue a new one.');
        }

        try {
            $code = Crypto::decrypt($cipher);
        } catch (\Throwable $e) {
            return $this->flashBack($session, $storeId, 'error',
                'The stored code could not be decrypted. Issue a new one.');
        }

        Audit::log($this->db, (int)$admin['id'], Audit::PAIRING_REVEALED, $storeId, $req->ip, $req->userAgent, [
            'user_id' => $userId,
            'username' => $username,
        ]);

        $session['sa_flash_secret'] = [
            'label'  => 'Activation code for ' . $username . ' (valid once — hand it over and let them activate)',
            'value'  => $code,
            'isCode' => true,
        ];
        return $this->flashBack($session, $storeId, 'success', 'Code shown below. Copy it for ' . $username . '.');
    }

    // ── Lifecycle actions (POST, CSRF already verified by the Kernel) ─────
    /** @param array<string,mixed> $session @param array<string,mixed> $admin */
    public function approve(Request $req, array &$session, array $admin, int $storeId, int $now): Response
    {
        $store = $this->stores->find($storeId);
        if ($store === null) {
            return Response::notFound();
        }
        $tier = $req->input('subscription_tier', 'standard');
        if (!in_array($tier, self::TIERS, true)) {
            return $this->flashBack($session, $storeId, 'error', 'Choose a valid subscription tier.');
        }

        $apiKey = 'pk_live_' . bin2hex(random_bytes(24));
        $this->db->beginTransaction();
        try {
            if (!$this->stores->approve($storeId, (int)$admin['id'], $tier, hash('sha256', $apiKey), gmdate('Y-m-d H:i:s', $now))) {
                $this->db->rollBack();
                return $this->flashBack($session, $storeId, 'error', 'Only stores pending approval can be approved.');
            }
            $this->stores->seedDefaults($storeId, (string)$store['name']);
            Audit::log($this->db, (int)$admin['id'], Audit::STORE_APPROVED, $storeId, $req->ip, $req->userAgent, ['tier' => $tier, 'name' => $store['name']]);
            $this->db->commit();
        } catch (\Throwable $t) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            return $this->flashBack($session, $storeId, 'error', 'Approval failed and was rolled back.');
        }

        $mailNote = $this->notifyOwner($store, $admin, $req, 'Your ProCast store has been approved', sprintf(
            '<p>Hello %s,</p><p>Your store <strong>%s</strong> has been approved. You can now sign in to ProCast with the username and password you registered.</p>'
            . '<p>Your production API key (shown once — keep it secret):<br><code>%s</code></p>',
            View::e($store['owner_name'] ?? ''), View::e($store['name']), View::e($apiKey)
        ));
        $session['sa_flash_secret'] = ['label' => 'Production API key (shown once)', 'value' => $apiKey];
        return $this->flashBack($session, $storeId, 'success', 'Store approved, defaults seeded. ' . $mailNote);
    }

    /** @param array<string,mixed> $session @param array<string,mixed> $admin */
    public function reject(Request $req, array &$session, array $admin, int $storeId): Response
    {
        $store = $this->stores->find($storeId);
        if ($store === null) {
            return Response::notFound();
        }
        $reason = trim($req->input('reason'));
        if (mb_strlen($reason) < 10 || mb_strlen($reason) > 500) {
            return $this->flashBack($session, $storeId, 'error', 'A rejection reason of 10–500 characters is required.');
        }
        if (!$this->confirmed($req, (string)$store['name'])) {
            return $this->flashBack($session, $storeId, 'error', 'Type the exact store name to confirm.');
        }
        $this->db->beginTransaction();
        try {
            if (!$this->stores->reject($storeId, $reason)) {
                $this->db->rollBack();
                return $this->flashBack($session, $storeId, 'error', 'Only stores pending approval can be rejected.');
            }
            Audit::log($this->db, (int)$admin['id'], Audit::STORE_REJECTED, $storeId, $req->ip, $req->userAgent, ['reason' => $reason]);
            $this->db->commit();
        } catch (\Throwable) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            return $this->flashBack($session, $storeId, 'error', 'Rejection failed and was rolled back.');
        }
        $note = $this->notifyOwner($store, $admin, $req, 'Your ProCast store application', sprintf(
            '<p>Hello %s,</p><p>We could not approve <strong>%s</strong>.</p><p><strong>Reason:</strong> %s</p>',
            View::e($store['owner_name'] ?? ''), View::e($store['name']), View::e($reason)
        ));
        return $this->flashBack($session, $storeId, 'success', 'Application rejected. ' . $note);
    }

    /** @param array<string,mixed> $session @param array<string,mixed> $admin */
    public function suspend(Request $req, array &$session, array $admin, int $storeId): Response
    {
        $store = $this->stores->find($storeId);
        if ($store === null) {
            return Response::notFound();
        }
        $reason = trim($req->input('reason'));
        if (mb_strlen($reason) < 10 || mb_strlen($reason) > 500) {
            return $this->flashBack($session, $storeId, 'error', 'A suspension reason of 10–500 characters is required.');
        }
        if (!$this->confirmed($req, (string)$store['name'])) {
            return $this->flashBack($session, $storeId, 'error', 'Type the exact store name to confirm.');
        }
        $this->db->beginTransaction();
        try {
            if (!$this->stores->suspend($storeId, $reason)) {
                $this->db->rollBack();
                return $this->flashBack($session, $storeId, 'error', 'Only active stores can be suspended.');
            }
            $revoked = $this->stores->revokeStoreTokens($storeId);
            Audit::log($this->db, (int)$admin['id'], Audit::STORE_SUSPENDED, $storeId, $req->ip, $req->userAgent, ['reason' => $reason, 'tokens_revoked' => $revoked]);
            $this->db->commit();
        } catch (\Throwable) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            return $this->flashBack($session, $storeId, 'error', 'Suspension failed and was rolled back.');
        }
        return $this->flashBack($session, $storeId, 'success', "Store suspended. {$revoked} login token(s) revoked; cashier logins are blocked immediately.");
    }

    /** @param array<string,mixed> $session @param array<string,mixed> $admin */
    public function reactivate(Request $req, array &$session, array $admin, int $storeId): Response
    {
        $store = $this->stores->find($storeId);
        if ($store === null) {
            return Response::notFound();
        }
        $apiKey = 'pk_live_' . bin2hex(random_bytes(24));
        $this->db->beginTransaction();
        try {
            if (!$this->stores->reactivate($storeId, hash('sha256', $apiKey))) {
                $this->db->rollBack();
                return $this->flashBack($session, $storeId, 'error', 'Only suspended stores can be reactivated.');
            }
            Audit::log($this->db, (int)$admin['id'], Audit::STORE_REACTIVATED, $storeId, $req->ip, $req->userAgent, []);
            $this->db->commit();
        } catch (\Throwable) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            return $this->flashBack($session, $storeId, 'error', 'Reactivation failed and was rolled back.');
        }
        $session['sa_flash_secret'] = ['label' => 'New production API key (shown once)', 'value' => $apiKey];
        return $this->flashBack($session, $storeId, 'success', 'Store reactivated with a new API key.');
    }

    // ── Verification document (authenticated stream) ──────────────────────
    /** @param array<string,mixed> $admin */
    public function document(Request $req, array $admin, int $storeId): Response
    {
        $store = $this->stores->find($storeId);
        $file = is_array($store) ? (string)($store['verification_doc'] ?? '') : '';
        // Stored names are server-generated; re-validate so a poisoned DB value can't traverse.
        if (!preg_match('/^[a-f0-9]{32}\.(pdf|jpg|png)$/', $file)) {
            return Response::notFound();
        }
        $dir = realpath(PROCAST_ROOT . '/uploads/verification');
        $path = $dir !== false ? realpath($dir . '/' . $file) : false;
        if ($dir === false || $path === false || strncmp($path, $dir . DIRECTORY_SEPARATOR, strlen($dir) + 1) !== 0) {
            return Response::notFound();
        }
        Audit::log($this->db, (int)$admin['id'], Audit::DOCUMENT_VIEWED, $storeId, $req->ip, $req->userAgent, ['file' => $file]);
        $types = ['pdf' => 'application/pdf', 'jpg' => 'image/jpeg', 'png' => 'image/png'];
        $r = new Response(200, '', [
            'Content-Type'        => $types[pathinfo($file, PATHINFO_EXTENSION)],
            'Content-Disposition' => 'attachment; filename="verification-' . $storeId . '.' . pathinfo($file, PATHINFO_EXTENSION) . '"',
        ]);
        $r->filePath = $path;
        return $r;
    }

    // ── User activity inspector ───────────────────────────────────────────
    /** @param array<string,mixed> $session @param array<string,mixed> $admin */
    public function users(Request $req, array &$session, array $admin, int $now): Response
    {
        $q = mb_substr($req->queryStr('q'), 0, 100);
        if ($q !== '') {
            Audit::log($this->db, (int)$admin['id'], Audit::USER_SEARCH, null, $req->ip, $req->userAgent, ['q' => $q]);
        }
        return $this->page('users', $session, $admin, 'User activity', 'users', [
            'rows' => $this->stores->searchUsers($q, $now),
            'q' => $q,
            'now' => $now,
        ]);
    }

    // ── helpers ───────────────────────────────────────────────────────────
    private function confirmed(Request $req, string $storeName): bool
    {
        return hash_equals($storeName, $req->input('confirm_text'));
    }

    /**
     * @param array<string,mixed> $store
     * @param array<string,mixed> $admin
     */
    private function notifyOwner(array $store, array $admin, Request $req, string $subject, string $html): string
    {
        $to = trim((string)($store['owner_email'] ?? ''));
        if ($to === '') {
            $owner = $this->stores->ownerOf((int)$store['id']);
            $to = trim((string)($owner['email'] ?? ''));
        }
        if ($to === '') {
            return 'No owner email on file — no notification sent.';
        }
        [$ok, $msg] = Mailer::send($to, (string)($store['owner_name'] ?? ''), $subject, $html);
        Audit::log($this->db, (int)$admin['id'], $ok ? Audit::EMAIL_SENT : Audit::EMAIL_FAILED, (int)$store['id'], $req->ip, $req->userAgent, ['subject' => $subject, 'result' => $ok ? 'sent' : $msg]);
        return $ok ? 'Owner notified by email.' : 'Email could not be sent (' . $msg . ').';
    }

    /** @param array<string,mixed> $session */
    private function flashBack(array &$session, int $storeId, string $type, string $msg): Response
    {
        $session['sa_flash'] = ['type' => $type, 'msg' => $msg];
        return Response::redirect('/stores/' . $storeId);
    }

    /**
     * @param array<string,mixed> $session
     * @param array<string,mixed> $admin
     * @param array<string,mixed> $vars
     */
    private function page(string $view, array &$session, array $admin, string $title, string $nav, array $vars): Response
    {
        $flash = $session['sa_flash'] ?? null;
        $secret = $session['sa_flash_secret'] ?? null;
        unset($session['sa_flash'], $session['sa_flash_secret']);
        return Response::html(View::render($view, $vars + [
            'title' => $title, 'nav' => $nav, 'admin' => $admin,
            'csrf' => Csrf::token($session), 'flash' => $flash, 'flashSecret' => $secret,
        ]));
    }
}
