<?php
declare(strict_types=1);

namespace ProCast\Controllers\PlatformAdmin;

use PDO;
use ProCast\Middleware\SuperAdminGuard;
use ProCast\Support\AdminRepository;
use ProCast\Support\Audit;
use ProCast\Support\AuthThrottle;
use ProCast\Support\Crypto;
use ProCast\Support\Csrf;
use ProCast\Support\Db;
use ProCast\Support\Env;
use ProCast\Support\Request;
use ProCast\Support\Response;
use ProCast\Support\Totp;
use ProCast\Support\View;
use ProCast\Support\Passwords;

/**
 * Login = password (Argon2id), then TOTP only when SUPERADMIN_REQUIRE_2FA=true.
 * With the default (2FA off) a correct password establishes the session and
 * redirects straight to /monitoring — the owner account configured in Render
 * signs in with email + password and nothing else. Failures still feed the
 * 5-per-15-minutes throttle and the append-only audit trail.
 */
final class SuperAdminAuthController
{
    private const ISSUER = 'ProCast Platform';

    public function __construct(
        private PDO $db,
        private AdminRepository $admins,
        private AuthThrottle $throttle,
    ) {
    }

    /** @param array<string,mixed> $session */
    public function showLogin(Request $req, array &$session): Response
    {
        return $this->loginView($session, null, 200);
    }

    /** @param array<string,mixed> $session */
    public function login(Request $req, array &$session, int $now): Response
    {
        $email = strtolower(trim($req->input('email')));
        $password = $req->input('password');

        // Ensure database has super admin seeded/updated
        Db::ensureSuperAdminSeeded($this->db);

        $envEmail    = Env::superAdminEnvEmail();
        $envPassword = (string)Env::get('SUPERADMIN_PASSWORD', '');

        // A published/placeholder env password is treated as if it were NOT set.
        // It must not authenticate, and -- critically -- it must not earn the
        // lockout exemption below: the one account exempt from rate limiting
        // must never be the one whose credential is public.
        $envPasswordUsable = Env::superAdminPasswordIsAcceptable($envPassword);
        $isEnvOwner        = $envEmail !== '' && $envEmail === $email;

        if ($isEnvOwner && !$envPasswordUsable) {
            error_log('[platform-admin] Owner sign-in refused for ' . $email
                . ': SUPERADMIN_PASSWORD is empty, under 12 characters, or a published placeholder.');
            Audit::log($this->db, null, Audit::LOGIN_FAILED, null, $req->ip, $req->userAgent,
                ['email' => $email, 'stage' => 'env_config']);
            return $this->loginView($session,
                'The deployment owner password is not securely configured. '
                . 'Set a unique SUPERADMIN_PASSWORD of at least 12 characters in the environment.', 503);
        }

        // The account configured in Render owns this deployment. It is exempt
        // from the lockout so a mistyped password can never strand it.
        $envCredsOk = $isEnvOwner && $envPassword !== '' && hash_equals($envPassword, $password);

        if ($this->throttle->isBlocked($req->ip, $email, $now)) {
            if ($envCredsOk) {
                // Exact owner credentials: wipe the counter and let it through.
                $this->throttle->clear($req->ip, $email);
            } else {
                Audit::log($this->db, null, Audit::LOGIN_BLOCKED, null, $req->ip, $req->userAgent, ['email' => $email, 'stage' => 'password']);
                return $this->loginView($session, 'Too many failed attempts. Try again in 15 minutes.', 429);
            }
        }

        $admin = ($email !== '' && strlen($email) <= 190) ? $this->admins->findByEmail($email) : null;
        // Always run a verification so timing doesn't reveal whether the email exists.
        $hash = is_array($admin) ? (string)$admin['password_hash'] : Passwords::dummyHash();
        $passwordOk = Passwords::verify($password, $hash);

        // Fallback: if credentials match the Render environment variables directly, sync and authenticate
        if (!$passwordOk && $envCredsOk) {
            $passwordOk = true;
            if ($admin === null) {
                $name = trim((string)\ProCast\Support\Env::get('SUPERADMIN_NAME', 'Platform Administrator'));
                $this->admins->create($name, $email, Passwords::hash($password));
                $admin = $this->admins->findByEmail($email);
            } else {
                $this->db->prepare("UPDATE platform_super_admins SET password_hash = ?, status = 'active' WHERE id = ?")
                    ->execute([Passwords::hash($password), (int)$admin['id']]);
                $admin['status'] = 'active';
            }
        }

        if (!$admin || !$passwordOk || $admin['status'] !== 'active') {
            $this->throttle->recordFailure('login', $req->ip, $email, $now);
            Audit::log($this->db, is_array($admin) ? (int)$admin['id'] : null, Audit::LOGIN_FAILED, null, $req->ip, $req->userAgent, ['email' => $email]);
            return $this->loginView($session, 'Invalid credentials.', 401);
        }

        // ── Simple login (default): password is enough, no second step ────────
        if (!Env::superAdminTwoFactorRequired()) {
            return $this->completeLogin($session, $admin, $req, $now);
        }

        // Password accepted — NOT logged in yet. Park a short-lived pending marker.
        $session = [
            'sa_pending_id' => (int)$admin['id'],
            'sa_pending_at' => $now,
            'sa_pending_ip' => SuperAdminGuard::bindIp($req->ip),
        ];
        Csrf::rotate($session);
        if (!(int)$admin['totp_enrolled']) {
            $session['sa_enroll_secret'] = Totp::generateSecret();
        }
        $resp = Response::redirect('/login/verify');
        $resp->regenerateSession = true;
        return $resp;
    }

    /** @param array<string,mixed> $session */
    public function showVerify(Request $req, array &$session, int $now): Response
    {
        // 2FA turned off: there is no pending step to complete.
        if (!Env::superAdminTwoFactorRequired()) {
            return $this->abortPending($session);
        }
        $admin = $this->pendingAdmin($req, $session, $now);
        if ($admin === null) {
            return $this->abortPending($session);
        }
        return $this->verifyView($session, $admin, null, 200);
    }

    /** @param array<string,mixed> $session */
    public function verify(Request $req, array &$session, int $now): Response
    {
        // 2FA turned off: never accept a code step, even if a stale pending
        // marker somehow survives (e.g. 2FA was disabled mid-session).
        if (!Env::superAdminTwoFactorRequired()) {
            return $this->abortPending($session);
        }
        $admin = $this->pendingAdmin($req, $session, $now);
        if ($admin === null) {
            return $this->abortPending($session);
        }
        $email = (string)$admin['email'];

        if ($this->throttle->isBlocked($req->ip, $email, $now)) {
            Audit::log($this->db, (int)$admin['id'], Audit::LOGIN_BLOCKED, null, $req->ip, $req->userAgent, ['stage' => 'totp']);
            return $this->verifyView($session, $admin, 'Too many failed attempts. Try again in 15 minutes.', 429);
        }

        $enrolled = (bool)(int)$admin['totp_enrolled'];
        try {
            $secret = $enrolled ? Crypto::decrypt((string)$admin['totp_secret']) : (string)($session['sa_enroll_secret'] ?? '');
        } catch (\Throwable) {
            return $this->verifyView($session, $admin, 'Authentication is temporarily unavailable.', 503);
        }
        $last = $enrolled ? (int)$admin['totp_last_step'] : 0;
        $step = $secret !== '' ? Totp::verify($secret, $req->input('code'), $now, $last) : null;

        if ($step === null) {
            $this->throttle->recordFailure('totp', $req->ip, $email, $now);
            Audit::log($this->db, (int)$admin['id'], Audit::TOTP_FAILED, null, $req->ip, $req->userAgent, ['enrolling' => !$enrolled]);
            return $this->verifyView($session, $admin, 'Invalid or expired code.', 401);
        }

        if (!$enrolled) {
            $this->admins->saveTotpSecret((int)$admin['id'], Crypto::encrypt($secret));
            Audit::log($this->db, (int)$admin['id'], Audit::TOTP_ENROLLED, null, $req->ip, $req->userAgent);
        }
        $this->admins->setLastTotpStep((int)$admin['id'], $step);

        return $this->completeLogin($session, $admin, $req, $now);
    }

    /** @param array<string,mixed> $session */
    public function logout(Request $req, array &$session): Response
    {
        $id = isset($session['sa_admin_id']) && is_int($session['sa_admin_id']) ? $session['sa_admin_id'] : null;
        if ($id !== null) {
            Audit::log($this->db, $id, Audit::LOGOUT, null, $req->ip, $req->userAgent);
        }
        $session = [];
        $resp = Response::redirect('/login');
        $resp->destroySession = true;
        return $resp;
    }

    /**
     * Shared tail of both login paths: record the login, clear the throttle,
     * write the audit entry, establish the session and go to /monitoring.
     *
     * @param array<string,mixed> $session
     * @param array<string,mixed> $admin
     */
    private function completeLogin(array &$session, array $admin, Request $req, int $now): Response
    {
        $id    = (int)$admin['id'];
        $email = (string)$admin['email'];

        $this->admins->recordLogin($id, $req->ip, gmdate('Y-m-d H:i:s', $now));
        $this->throttle->clear($req->ip, $email);
        Audit::log($this->db, $id, Audit::LOGIN_OK, null, $req->ip, $req->userAgent);

        SuperAdminGuard::establish($session, $id, $req->ip, $req->userAgent, $now);
        $resp = Response::redirect('/monitoring');
        $resp->regenerateSession = true;
        return $resp;
    }

    // ── internals ─────────────────────────────────────────────────────────
    /**
     * @param array<string,mixed> $session
     * @return array<string,mixed>|null
     */
    private function pendingAdmin(Request $req, array $session, int $now): ?array
    {
        $id = $session['sa_pending_id'] ?? null;
        if (!is_int($id) || $now - (int)($session['sa_pending_at'] ?? 0) > SuperAdminGuard::PENDING_TTL) {
            return null;
        }
        if (!hash_equals((string)($session['sa_pending_ip'] ?? ''), SuperAdminGuard::bindIp($req->ip))) {
            return null;
        }
        return $this->admins->findActiveById($id);
    }

    /** @param array<string,mixed> $session */
    private function abortPending(array &$session): Response
    {
        $session = [];
        $r = Response::redirect('/login');
        $r->destroySession = true;
        return $r;
    }

    /** @param array<string,mixed> $session */
    private function loginView(array &$session, ?string $error, int $status): Response
    {
        return Response::html(View::render('login', [
            'title' => 'Sign in', 'csrf' => Csrf::token($session), 'error' => $error, 'bare' => true,
            'twoFactor' => Env::superAdminTwoFactorRequired(),
            // null = NO layout: views/login.php is a complete document already.
            // See Kernel::showLogin() — wrapping it duplicated theme.js.
        ], null), $status);
    }

    /**
     * @param array<string,mixed> $session
     * @param array<string,mixed> $admin
     */
    private function verifyView(array &$session, array $admin, ?string $error, int $status): Response
    {
        $enrolling = !(int)$admin['totp_enrolled'];
        $secret = $enrolling ? (string)($session['sa_enroll_secret'] ?? '') : '';
        return Response::html(View::render('totp', [
            'title'    => $enrolling ? 'Set up two-factor' : 'Two-factor verification',
            'csrf'     => Csrf::token($session),
            'error'    => $error,
            'enrolling' => $enrolling,
            'secret'   => $secret,
            'secretGrouped' => trim(chunk_split($secret, 4, ' ')),
            'otpauth'  => $secret !== '' ? Totp::otpauthUri(self::ISSUER, (string)$admin['email'], $secret) : '',
            'bare'     => true,
            // null = NO layout: views/totp.php is a complete document already.
        ], null), $status);
    }
}
