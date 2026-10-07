<?php
declare(strict_types=1);

/**
 * Creates the primary Super Admin. No password is ever stored in SQL or in the repo.
 *
 *   php platform-admin/seed_super_admin.php --gen-key        print a new PLATFORM_ENC_KEY
 *   SUPERADMIN_EMAIL=... SUPERADMIN_NAME="..." SUPERADMIN_PASSWORD=... php platform-admin/seed_super_admin.php
 *
 * (or omit SUPERADMIN_PASSWORD and type it when prompted). Refuses if an admin
 * already exists. TOTP is enrolled by the admin on first sign-in. CLI only.
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

if (in_array('--gen-key', $argv, true)) {
    echo base64_encode(random_bytes(32)) . "\n";
    exit(0);
}

define('PROCAST_ROOT', dirname(__DIR__));
require PROCAST_ROOT . '/app/Bootstrap.php';

use ProCast\Support\AdminRepository;
use ProCast\Support\Db;
use ProCast\Support\Env;
use ProCast\Support\Passwords;

if (!Env::superAdminEnabled()) {
    fwrite(STDERR, "Refusing to run: set APP_ENV=production and ENABLE_SUPER_ADMIN=true.\n");
    exit(2);
}

try {
    $email = strtolower(trim((string)Env::get('SUPERADMIN_EMAIL', '')));
    $name = trim((string)Env::get('SUPERADMIN_NAME', 'Platform Administrator'));
    $password = (string)Env::get('SUPERADMIN_PASSWORD', '');

    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        throw new RuntimeException('SUPERADMIN_EMAIL is missing or invalid.');
    }
    if ($password === '') {
        fwrite(STDOUT, 'Password (visible while typing): ');
        $password = rtrim((string)fgets(STDIN), "\r\n");
    }
    if (($err = Passwords::policyError($password)) !== null) {
        throw new RuntimeException($err);
    }
    // Encryption key must be valid BEFORE the admin tries to enroll TOTP.
    \ProCast\Support\Crypto::encrypt('self-test');

    $repo = new AdminRepository(Db::pdo());
    if ($repo->count() > 0) {
        throw new RuntimeException('A super admin already exists. Refusing to create another from the CLI.');
    }
    $id = $repo->create($name, $email, Passwords::hash($password));
    echo "Super admin #$id created for $email.\nNext: sign in at /platform-admin/login and enroll your authenticator app.\n";
    echo "IMPORTANT: remove SUPERADMIN_PASSWORD from your environment now.\n";
} catch (Throwable $e) {
    fwrite(STDERR, 'Seed error: ' . $e->getMessage() . "\n");
    exit(1);
}
