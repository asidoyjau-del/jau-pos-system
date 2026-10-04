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
use ProCast\Support\Request;
use ProCast\Support\Response;
use ProCast\Support\Totp;
use ProCast\Support\View;
use ProCast\Support\Passwords;

/**
 * Login = password (Argon2id) THEN mandatory TOTP. A session only becomes
 * "super_admin_authenticated" after both succeed. Every failure feeds the
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

        if ($this->throttle->isBlocked($req->ip, $email, $now)) {
            Audit::log($this->db, null, Audit::LOGIN_BLOCKED, null, $req->ip, $req->userAgent, ['email' => $email, 'stage' => 'password']);
            return $this->loginView($session, 'Too many failed attempts. Try again in 15 minutes.', 429);
        }

        $admin = ($email !== '' && strlen($email) <= 190) ? $this->admins->findByEmail($email) : null;
        // Always run a verification so timing doesn't reveal whether the email exists.
        $hash = is_array($admin) ? (string)$admin['password_hash'] : Passwords::dummyHash();
        $passwordOk = Passwords::verify($password, $hash);

        if (!$admin || !$passwordOk || $admin['status'] !== 'active') {
            $this->throttle->recordFailure('login', $req->ip, $email, $now);
            Audit::log($this->db, is_array($admin) ? (int)$admin['id'] : null, Audit::LOGIN_FAILED, null, $req->ip, $req->userAgent, ['email' => $email]);
            return $this->loginView($session, 'Invalid credentials.', 401);
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
        $admin = $this->pendingAdmin($req, $session, $now);
        if ($admin === null) {
            return $this->abortPending($session);
        }
        return $this->verifyView($session, $admin, null, 200);
    }

    /** @param array<string,mixed> $session */
    public function verify(Request $req, array &$session, int $now): Response
    {
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
        $this->admins->recordLogin((int)$admin['id'], $req->ip, gmdate('Y-m-d H:i:s', $now));
        $this->throttle->clear($req->ip, $email);
        Audit::log($this->db, (int)$admin['id'], Audit::LOGIN_OK, null, $req->ip, $req->userAgent);

        SuperAdminGuard::establish($session, (int)$admin['id'], $req->ip, $req->userAgent, $now);
        $resp = Response::redirect('/monitoring');
        $resp->regenerateSession = true;
        return $resp;
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
        ]), $status);
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
        ]), $status);
    }
}
