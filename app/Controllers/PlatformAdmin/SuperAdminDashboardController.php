<?php
declare(strict_types=1);

namespace ProCast\Controllers\PlatformAdmin;

use PDO;
use ProCast\Support\Audit;
use ProCast\Support\Csrf;
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
        return $this->page('store_detail', $session, $admin, (string)$store['name'], 'stores', ['store' => $store]);
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
