<?php
declare(strict_types=1);

/**
 * ProCast Platform Super Admin — bootstrap.
 *
 * Loaded ONLY by platform-admin/index.php (after the environment fence) and by
 * the CLI tools / tests. The store POS (index.php) never includes this file,
 * so on a local XAMPP install none of this code is ever executed.
 *
 * Tiny PSR-4 autoloader:  ProCast\Foo\Bar  =>  app/Foo/Bar.php
 */

if (!defined('PROCAST_ROOT')) {
    define('PROCAST_ROOT', dirname(__DIR__));
}

spl_autoload_register(static function (string $class): void {
    $prefix = 'ProCast\\';
    if (strncmp($class, $prefix, strlen($prefix)) !== 0) {
        return;
    }
    $relative = substr($class, strlen($prefix));
    $file = __DIR__ . '/' . str_replace('\\', '/', $relative) . '.php';
    if (is_file($file)) {
        require $file;
    }
});
