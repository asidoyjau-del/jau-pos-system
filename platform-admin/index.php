<?php
declare(strict_types=1);

/**
 * ProCast Platform Super Admin — dedicated front controller.
 * Completely separate from the store POS index.php.
 *
 * FIRST thing that happens: the environment fence. Unless
 *   APP_ENV=production  AND  ENABLE_SUPER_ADMIN=true
 * the request dies with a bare HTTP 404 (no body, no cookie, no session).
 */

ini_set('display_errors', '0');          // never leak traces from here
ini_set('log_errors', '1');

define('PROCAST_ROOT', dirname(__DIR__));
require PROCAST_ROOT . '/app/Bootstrap.php';

use ProCast\Middleware\SuperAdminGuard;
use ProCast\PlatformAdmin\Kernel;
use ProCast\Support\Db;
use ProCast\Support\Request;
use ProCast\Support\Response;

// ── Check 1: environment fence ────────────────────────────────────────────
SuperAdminGuard::fenceOrAbort();

try {
    $request = Request::fromGlobals($_SERVER, $_GET, $_POST, $_COOKIE);
    $kernel = new Kernel(static fn () => Db::pdo());

    if (!$kernel->needsSession($request)) {
        $dummy = [];
        $kernel->handle($request, $dummy)->emit();
        exit;
    }

    // ── Isolated session namespace ────────────────────────────────────────
    $savePath = \ProCast\Support\Env::get('PLATFORM_SESSION_PATH') ?: sys_get_temp_dir() . '/procast_super_sess';
    if (!is_dir($savePath)) {
        @mkdir($savePath, 0700, true);
    }
    if (is_dir($savePath) && is_writable($savePath)) {
        session_save_path($savePath);
    }
    session_name(SuperAdminGuard::SESSION_NAME);          // PROCAST_SUPER_SESS
    ini_set('session.use_strict_mode', '1');
    ini_set('session.use_only_cookies', '1');
    ini_set('session.use_trans_sid', '0');
    session_set_cookie_params([
        'lifetime' => 0,                                  // browser-session cookie
        'path'     => Request::PREFIX,                    // never sent to the POS routes
        'secure'   => $request->https,
        'httponly' => true,
        'samesite' => 'Strict',
    ]);
    session_start();

    $response = $kernel->handle($request, $_SESSION);

    if ($response->destroySession) {
        $_SESSION = [];
        setcookie(session_name(), '', [
            'expires' => time() - 3600, 'path' => Request::PREFIX,
            'secure' => $request->https, 'httponly' => true, 'samesite' => 'Strict',
        ]);
        session_destroy();
    } elseif ($response->regenerateSession) {
        session_regenerate_id(true);
    }
    $response->emit();
} catch (\Throwable $e) {
    error_log('[platform-admin] ' . get_class($e) . ': ' . $e->getMessage() . "\n" . $e->getTraceAsString());
    if (!headers_sent()) {
        http_response_code(500);
        header('Content-Type: text/plain; charset=utf-8');
        header('Cache-Control: no-store');
    }
    if (\ProCast\Support\Env::get('PLATFORM_DEBUG') === 'true' || isset($_GET['debug'])) {
        echo "[Platform Admin Diagnostic]\n";
        echo get_class($e) . ': ' . $e->getMessage() . "\n";
        echo "File: " . $e->getFile() . ':' . $e->getLine() . "\n";
    } else {
        echo "Service temporarily unavailable.\n(Tip: append ?debug=1 to URL to view details)";
    }
}
