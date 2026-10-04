<?php
declare(strict_types=1);

namespace ProCast\PlatformAdmin;

use PDO;
use ProCast\Controllers\PlatformAdmin\SuperAdminAuthController;
use ProCast\Controllers\PlatformAdmin\SuperAdminDashboardController;
use ProCast\Middleware\SuperAdminGuard;
use ProCast\Support\AdminRepository;
use ProCast\Support\Audit;
use ProCast\Support\AuthThrottle;
use ProCast\Support\Request;
use ProCast\Support\Response;
use ProCast\Support\StoreRepository;

/**
 * Front controller for /platform-admin. Pure function of (Request, session
 * array) -> Response so the whole thing can be exercised in-process by tests
 * without a web server. The environment fence is enforced by the entry point
 * (platform-admin/index.php) before this class is ever constructed.
 */
final class Kernel
{
    private const ASSETS = [
        'admin.css' => 'text/css; charset=utf-8',
        'admin.js'  => 'application/javascript; charset=utf-8',
    ];

    private ?PDO $pdo = null;

    /** @param callable():PDO $dbFactory lazily invoked so DB-free routes never open a connection */
    public function __construct(private $dbFactory)
    {
    }

    /** Static assets and unroutable requests must not start a session / emit Set-Cookie. */
    public function needsSession(Request $req): bool
    {
        return $req->path !== null && $this->assetName($req) === null;
    }

    /** @param array<string,mixed> $session */
    public function handle(Request $req, array &$session, ?int $now = null): Response
    {
        $now ??= time();
        $resp = $this->route($req, $session, $now);
        foreach (SuperAdminGuard::securityHeaders() as $k => $v) {
            $resp->headers[$k] ??= $v;
        }
        return $resp;
    }

    /** @param array<string,mixed> $session */
    private function route(Request $req, array &$session, int $now): Response
    {
        if ($req->path === null) {
            return Response::notFound();
        }
        $method = $req->method;
        $path = $req->path;

        if (($asset = $this->assetName($req)) !== null) {
            return $this->asset($asset);
        }

        // ── Public (pre-auth) routes ──────────────────────────────────────
        if ($path === '/login' || $path === '/login/verify') {
            if ($path === '/login' && $method === 'GET') {
                return $this->showLogin($session);
            }
            if ($method === 'POST' && !SuperAdminGuard::verifyCsrf($session, $req->input('csrf'))) {
                return Response::forbidden('Invalid or missing CSRF token.');
            }
            $auth = $this->auth();
            return match (true) {
                $path === '/login' && $method === 'POST'         => $auth->login($req, $session, $now),
                $path === '/login/verify' && $method === 'GET'   => $auth->showVerify($req, $session, $now),
                $path === '/login/verify' && $method === 'POST'  => $auth->verify($req, $session, $now),
                default                                          => Response::notFound(),
            };
        }

        // ── Everything else requires a valid super-admin session ──────────
        $reason = $this->guard($session)->check($session, $req->ip, $req->userAgent, $now);
        if ($reason !== null) {
            return $this->denied($req, $session, $reason);
        }
        $admin = $this->admins()->findActiveById((int)$session['sa_admin_id']);
        if ($admin === null) { // raced with deactivation
            return $this->denied($req, $session, 'inactive');
        }

        if ($method === 'POST' && !SuperAdminGuard::verifyCsrf($session, $req->input('csrf') ?: ($_SERVER['HTTP_X_CSRF_TOKEN'] ?? null))) {
            return Response::forbidden('Invalid or missing CSRF token.');
        }

        $dash = $this->dashboard();
        if ($method === 'GET') {
            switch (true) {
                case $path === '/':
                    return Response::redirect('/monitoring');
                case $path === '/monitoring':
                    return $dash->monitoring($req, $session, $admin, $now);
                case $path === '/api/telemetry':
                    return $dash->telemetry($now);
                case $path === '/api/health':
                    return $dash->health();
                case $path === '/stores':
                    return $dash->stores($req, $session, $admin);
                case $path === '/users':
                    return $dash->users($req, $session, $admin, $now);
                case (bool)preg_match('#^/stores/(\d{1,10})$#', $path, $m):
                    return $dash->storeDetail($req, $session, $admin, (int)$m[1]);
                case (bool)preg_match('#^/stores/(\d{1,10})/document$#', $path, $m):
                    return $dash->document($req, $admin, (int)$m[1]);
            }
        } elseif ($method === 'POST') {
            if ($path === '/logout') {
                return $this->auth()->logout($req, $session);
            }
            if (preg_match('#^/stores/(\d{1,10})/(approve|reject|suspend|reactivate)$#', $path, $m)) {
                $id = (int)$m[1];
                return match ($m[2]) {
                    'approve'    => $dash->approve($req, $session, $admin, $id, $now),
                    'reject'     => $dash->reject($req, $session, $admin, $id),
                    'suspend'    => $dash->suspend($req, $session, $admin, $id),
                    'reactivate' => $dash->reactivate($req, $session, $admin, $id),
                };
            }
        }
        return Response::notFound();
    }

    /** @param array<string,mixed> $session */
    private function denied(Request $req, array &$session, string $reason): Response
    {
        // A binding mismatch on a live session is a possible hijack: record it.
        if (in_array($reason, ['ip_mismatch', 'ua_mismatch'], true) && isset($session['sa_admin_id']) && is_int($session['sa_admin_id'])) {
            try {
                Audit::log($this->db(), $session['sa_admin_id'], Audit::SESSION_REJECTED, null, $req->ip, $req->userAgent, ['reason' => $reason]);
            } catch (\Throwable) {
            }
        }
        $session = [];
        if (strncmp((string)$req->path, '/api/', 5) === 0) {
            $r = Response::json(['error' => 'forbidden'], 403);
        } else {
            $r = Response::redirect('/login');
        }
        $r->destroySession = true;
        return $r;
    }

    private function assetName(Request $req): ?string
    {
        if ($req->method === 'GET' && $req->path !== null && preg_match('#^/assets/([a-z0-9.]+)$#', $req->path, $m) && isset(self::ASSETS[$m[1]])) {
            return $m[1];
        }
        return null;
    }

    private function asset(string $name): Response
    {
        $file = PROCAST_ROOT . '/platform-admin/assets/' . $name;
        if (!is_file($file)) {
            return Response::notFound();
        }
        $r = new Response(200, '', ['Content-Type' => self::ASSETS[$name], 'Cache-Control' => 'private, max-age=300']);
        $r->filePath = $file;
        return $r;
    }

    /** @param array<string,mixed> $session */
    private function showLogin(array &$session): Response
    {
        return Response::html(\ProCast\Support\View::render('login', [
            'title' => 'Sign in',
            'csrf'  => \ProCast\Support\Csrf::token($session),
            'error' => null,
            'bare'  => true,
        ]), 200);
    }

    // ── lazy wiring ───────────────────────────────────────────────────────
    private function db(): PDO
    {
        return $this->pdo ??= ($this->dbFactory)();
    }

    private function admins(): AdminRepository
    {
        return new AdminRepository($this->db());
    }

    private function guard(array $session): SuperAdminGuard
    {
        // Only touch the DB when a session claims to be authenticated.
        if (($session['super_admin_authenticated'] ?? false) !== true) {
            return new SuperAdminGuard(null);
        }
        return new SuperAdminGuard($this->admins());
    }

    private function auth(): SuperAdminAuthController
    {
        return new SuperAdminAuthController($this->db(), $this->admins(), new AuthThrottle($this->db()));
    }

    private function dashboard(): SuperAdminDashboardController
    {
        return new SuperAdminDashboardController($this->db(), new StoreRepository($this->db()));
    }
}
