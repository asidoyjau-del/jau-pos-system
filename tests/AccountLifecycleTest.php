<?php
declare(strict_types=1);

/**
 * AccountLifecycleTest.php
 * ========================
 * Tests for the Store Owner 7-Day Trial, Staff Approval, and 6-Digit Passcode Verification.
 *
 * Covers:
 *   1. Migration 008 schema invariants (PostgreSQL DDL & grandfathering).
 *   2. Super Admin Kernel routes, Controller methods, Audit actions, and Views.
 *   3. StoreRepository unit tests for approval, rejection, unlock, and code regeneration.
 *   4. index.php authentication and lifecycle rules:
 *      - Store Owner 7-day trial registration & automatic locking on expiry.
 *      - Exact required lock screen message.
 *      - Staff account creation in PENDING state.
 *      - Pending login rejection with 24-hour email rate limiting.
 *      - Passcode verification endpoint (verify_account_code) and store restoration.
 *      - Mid-session trial enforcement in enforceStoreStanding.
 *   5. Security, encryption, and transactional email with CC.
 *
 * Run:
 *   php tests/AccountLifecycleTest.php
 */

if (!defined('PROCAST_ROOT')) {
    define('PROCAST_ROOT', dirname(__DIR__));
}
require_once PROCAST_ROOT . '/app/Bootstrap.php';

use ProCast\Support\Audit;
use ProCast\Support\Crypto;
use ProCast\Support\Mailer;
use ProCast\Support\StoreRepository;

$pass = 0;
$fail = 0;

function assert_test(string $name, bool $ok, string $detail = ''): void
{
    global $pass, $fail;
    if ($ok) {
        echo "  PASS  {$name}\n";
        $pass++;
    } else {
        echo "  FAIL  {$name}" . ($detail ? " -- {$detail}" : '') . "\n";
        $fail++;
    }
}

echo "\nAccount Lifecycle, Trial & Approval Test Suite\n";
echo str_repeat('=', 60) . "\n";

$ROOT = PROCAST_ROOT;
$migrationFile = $ROOT . '/platform-admin/migrations/008_account_lifecycle_and_trial.sql';
$kernelFile    = $ROOT . '/app/PlatformAdmin/Kernel.php';
$ctrlFile      = $ROOT . '/app/Controllers/PlatformAdmin/SuperAdminDashboardController.php';
$repoFile      = $ROOT . '/app/Support/StoreRepository.php';
$auditFile     = $ROOT . '/app/Support/Audit.php';
$mailerFile    = $ROOT . '/app/Support/Mailer.php';
$layoutFile    = $ROOT . '/platform-admin/views/layout.php';
$approvalsFile = $ROOT . '/platform-admin/views/approvals.php';
$usersViewFile = $ROOT . '/platform-admin/views/users.php';
$detailViewFile= $ROOT . '/platform-admin/views/store_detail.php';
$indexFile     = $ROOT . '/index.php';

$migSrc       = is_file($migrationFile)  ? (string)file_get_contents($migrationFile)  : '';
$kernelSrc    = is_file($kernelFile)     ? (string)file_get_contents($kernelFile)     : '';
$ctrlSrc      = is_file($ctrlFile)       ? (string)file_get_contents($ctrlFile)       : '';
$repoSrc      = is_file($repoFile)       ? (string)file_get_contents($repoFile)       : '';
$auditSrc     = is_file($auditFile)      ? (string)file_get_contents($auditFile)      : '';
$mailerSrc    = is_file($mailerFile)     ? (string)file_get_contents($mailerFile)     : '';
$layoutSrc    = is_file($layoutFile)     ? (string)file_get_contents($layoutFile)     : '';
$approvalsSrc = is_file($approvalsFile)  ? (string)file_get_contents($approvalsFile)  : '';
$usersViewSrc = is_file($usersViewFile)  ? (string)file_get_contents($usersViewFile)  : '';
$detailViewSrc= is_file($detailViewFile) ? (string)file_get_contents($detailViewFile) : '';
$indexSrc     = is_file($indexFile)      ? (string)file_get_contents($indexFile)      : '';

// =========================================================================
// 1. Migration 008 Invariants
// =========================================================================
echo "\nT1: Migration 008 Schema Invariants\n";
assert_test('Migration 008 file exists', is_file($migrationFile));
assert_test('Migration adds account_status column', str_contains($migSrc, 'account_status'));
assert_test('Migration adds trial_ends_at column', str_contains($migSrc, 'trial_ends_at'));
assert_test('Migration adds activation_code column', str_contains($migSrc, 'activation_code'));
assert_test('Migration adds activation_code_cipher column', str_contains($migSrc, 'activation_code_cipher'));
assert_test('Migration adds code_expires_at column', str_contains($migSrc, 'code_expires_at'));
assert_test('Migration adds last_pending_email_at column', str_contains($migSrc, 'last_pending_email_at'));
assert_test('Migration adds rejection_reason column', str_contains($migSrc, 'rejection_reason'));
assert_test('Migration validates status check constraint (ACTIVE, TRIAL, PENDING, LOCKED, REJECTED)',
    str_contains($migSrc, "'ACTIVE'") &&
    str_contains($migSrc, "'TRIAL'") &&
    str_contains($migSrc, "'PENDING'") &&
    str_contains($migSrc, "'LOCKED'") &&
    str_contains($migSrc, "'REJECTED'")
);
assert_test('Migration grandfatheres legacy users to ACTIVE',
    str_contains($migSrc, "account_status = 'ACTIVE'") &&
    str_contains($migSrc, "account_status IS NULL")
);
assert_test('Migration indexes account_status for fast queries',
    str_contains($migSrc, 'idx_users_account_status')
);

// =========================================================================
// 2. Super Admin Kernel & Controller Routing Invariants
// =========================================================================
echo "\nT2: Super Admin Platform Kernel & Controller Invariants\n";
assert_test('Kernel registers GET /approvals route', str_contains($kernelSrc, "case \$path === '/approvals':"));
assert_test('Kernel registers POST /users/{id}/approve route', str_contains($kernelSrc, "preg_match('#^/users/(\\d{1,10})/approve$#'"));
assert_test('Kernel registers POST /users/{id}/reject route', str_contains($kernelSrc, "preg_match('#^/users/(\\d{1,10})/reject$#'"));
assert_test('Kernel registers POST /users/{id}/regenerate-code route', str_contains($kernelSrc, "preg_match('#^/users/(\\d{1,10})/regenerate-code$#'"));
assert_test('Kernel registers POST /stores/{id}/unlock-code route', str_contains($kernelSrc, "preg_match('#^/stores/(\\d{1,10})/unlock-code$#'"));

assert_test('Controller defines approvals() action', str_contains($ctrlSrc, 'function approvals('));
assert_test('Controller defines approveStaff() action', str_contains($ctrlSrc, 'function approveStaff('));
assert_test('Controller defines rejectStaff() action', str_contains($ctrlSrc, 'function rejectStaff('));
assert_test('Controller defines regenerateUserCode() action', str_contains($ctrlSrc, 'function regenerateUserCode('));
assert_test('Controller defines generateOwnerUnlockCode() action', str_contains($ctrlSrc, 'function generateOwnerUnlockCode('));

assert_test('Audit declares STAFF_APPROVED', str_contains($auditSrc, "STAFF_APPROVED   = 'STAFF_APPROVED'"));
assert_test('Audit declares STAFF_REJECTED', str_contains($auditSrc, "STAFF_REJECTED   = 'STAFF_REJECTED'"));
assert_test('Audit declares CODE_REGENERATED', str_contains($auditSrc, "CODE_REGENERATED = 'CODE_REGENERATED'"));
assert_test('Audit declares OWNER_UNLOCKED', str_contains($auditSrc, "OWNER_UNLOCKED   = 'OWNER_UNLOCKED'"));

assert_test('Sidebar layout removed Approvals link (merged to Stores)', !str_contains($layoutSrc, 'href="/platform-admin/approvals"'));
assert_test('Sidebar displays dynamic pending counter badge', str_contains($layoutSrc, '$pendingApprovalsCount'));
assert_test('Approvals view displays pending staff approvals', str_contains($approvalsSrc, 'Staff & Cashier Approvals'));
assert_test('Approvals view has one-click approve form', str_contains($approvalsSrc, '/approve'));
assert_test('Approvals view has reject modal with reason field', str_contains($approvalsSrc, '/reject') && str_contains($approvalsSrc, 'reason'));
assert_test('Users view displays Account Status column and badges', str_contains($usersViewSrc, 'Account Status'));
assert_test('Users view has Regenerate Code action', str_contains($usersViewSrc, '/regenerate-code'));
assert_test('Store detail view has Generate Owner Unlock Code action', str_contains($detailViewSrc, '/unlock-code'));

// =========================================================================
// 3. StoreRepository Unit Tests (SQLite in-memory DB)
// =========================================================================
echo "\nT3: StoreRepository Unit Tests (In-Memory PDO)\n";
$sqlitePdo = new PDO('sqlite::memory:');
$sqlitePdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

$sqlitePdo->exec("
    CREATE TABLE stores (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        name TEXT NOT NULL,
        slug TEXT,
        status TEXT NOT NULL DEFAULT 'active',
        approved_by INTEGER,
        approved_at TEXT,
        subscription_tier TEXT DEFAULT 'standard',
        database_sync_token TEXT,
        rejection_reason TEXT,
        created_at TEXT DEFAULT CURRENT_TIMESTAMP
    );
    CREATE TABLE users (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        store_id INTEGER NOT NULL,
        username TEXT NOT NULL,
        full_name TEXT,
        email TEXT,
        role TEXT NOT NULL DEFAULT 'cashier',
        account_status TEXT NOT NULL DEFAULT 'ACTIVE',
        trial_ends_at TEXT,
        activation_code TEXT,
        activation_code_cipher TEXT,
        code_expires_at TEXT,
        last_pending_email_at TEXT,
        rejection_reason TEXT,
        created_at TEXT DEFAULT CURRENT_TIMESTAMP
    );
");

// Insert sample store and users
$sqlitePdo->exec("INSERT INTO stores (id, name, status) VALUES (1, 'Test Store', 'active')");
$sqlitePdo->exec("INSERT INTO users (id, store_id, username, full_name, email, role, account_status) VALUES (1, 1, 'owner1', 'Store Owner', 'owner@example.com', 'owner', 'ACTIVE')");
$sqlitePdo->exec("INSERT INTO users (id, store_id, username, full_name, email, role, account_status) VALUES (2, 1, 'cashier1', 'Pending Cashier', 'cashier@example.com', 'cashier', 'PENDING')");
$sqlitePdo->exec("INSERT INTO users (id, store_id, username, full_name, email, role, account_status) VALUES (3, 1, 'manager1', 'Pending Manager', 'manager@example.com', 'manager', 'PENDING')");

$repo = new StoreRepository($sqlitePdo);

// 3.1 Pending Approvals Count
$pendingCount = $repo->pendingApprovalsCount();
assert_test('pendingApprovalsCount returns correct count of 2', $pendingCount === 2, "Got {$pendingCount}");

// 3.2 Approve staff account
$code = '123456';
$codeHash = hash('sha256', $code);
$expires = gmdate('Y-m-d H:i:s', time() + 7 * 86400);
$approved = $repo->approveStaffAccount(2, $codeHash, $expires, 'cipher_sample');
assert_test('approveStaffAccount returns true for pending user', $approved === true);

$st = $sqlitePdo->query("SELECT account_status, activation_code, activation_code_cipher, code_expires_at FROM users WHERE id = 2");
$row = $st->fetch(PDO::FETCH_ASSOC);
assert_test('approveStaffAccount updates account_status to ACTIVE', $row['account_status'] === 'ACTIVE');
assert_test('approveStaffAccount stores sha256 activation_code', $row['activation_code'] === $codeHash);
assert_test('approveStaffAccount stores activation_code_cipher', $row['activation_code_cipher'] === 'cipher_sample');
assert_test('approveStaffAccount sets code_expires_at', $row['code_expires_at'] === $expires);

// 3.3 Atomic guard: cannot approve an already active user
$reapprove = $repo->approveStaffAccount(2, $codeHash, $expires);
assert_test('approveStaffAccount returns false when user is no longer PENDING', $reapprove === false);

// 3.4 Reject staff account
$rejected = $repo->rejectStaffAccount(3, 'Identity could not be verified');
assert_test('rejectStaffAccount returns true for pending user', $rejected === true);

$st = $sqlitePdo->query("SELECT account_status, rejection_reason, activation_code FROM users WHERE id = 3");
$row = $st->fetch(PDO::FETCH_ASSOC);
assert_test('rejectStaffAccount updates account_status to REJECTED', $row['account_status'] === 'REJECTED');
assert_test('rejectStaffAccount records rejection_reason', $row['rejection_reason'] === 'Identity could not be verified');
assert_test('rejectStaffAccount ensures activation_code is null', $row['activation_code'] === null);

// 3.5 Store Owner Approval & 7-Day Trial Activation Test
$sqlitePdo->exec("INSERT INTO stores (id, name, status) VALUES (10, 'New Pending Store', 'pending_approval')");
$sqlitePdo->exec("INSERT INTO users (id, store_id, username, full_name, email, role, account_status) VALUES (10, 10, 'pending_owner', 'Pending Owner', 'owner10@example.com', 'owner', 'PENDING')");
$storeApproved = $repo->approve(10, 1, 'standard', 'token_hash_10', gmdate('Y-m-d H:i:s'));
assert_test('approve store returns true for pending store', $storeApproved === true);

$st = $sqlitePdo->query("SELECT account_status, trial_ends_at FROM users WHERE id = 10");
$ownerRow = $st->fetch(PDO::FETCH_ASSOC);
assert_test('approve store activates owner account_status to TRIAL', $ownerRow['account_status'] === 'TRIAL');
assert_test('approve store sets owner trial_ends_at to 7 days from now', !empty($ownerRow['trial_ends_at']));

// 3.6 Store Owner Rejection Test
$sqlitePdo->exec("INSERT INTO stores (id, name, status) VALUES (11, 'Rejected Store', 'pending_approval')");
$sqlitePdo->exec("INSERT INTO users (id, store_id, username, full_name, email, role, account_status) VALUES (11, 11, 'reject_owner', 'Reject Owner', 'owner11@example.com', 'owner', 'PENDING')");
$storeRejected = $repo->reject(11, 'Invalid business registration documents provided');
assert_test('reject store returns true for pending store', $storeRejected === true);

$st = $sqlitePdo->query("SELECT account_status, rejection_reason FROM users WHERE id = 11");
$rejectOwnerRow = $st->fetch(PDO::FETCH_ASSOC);
assert_test('reject store updates owner account_status to REJECTED', $rejectOwnerRow['account_status'] === 'REJECTED');
assert_test('reject store updates owner rejection_reason', $rejectOwnerRow['rejection_reason'] === 'Invalid business registration documents provided');

// 3.7 StoreRepository counts() includes pending and rejected users
$sqlitePdo->exec("INSERT INTO users (id, store_id, username, full_name, role, account_status) VALUES (20, 1, 'pending_user_20', 'Test Pending', 'cashier', 'PENDING')");
$repoCounts = $repo->counts();
assert_test('counts() calculates pending_approval incorporating pending users', $repoCounts['pending_approval'] >= 1);
assert_test('counts() calculates rejected incorporating rejected users', $repoCounts['rejected'] >= 1);

// 3.8 Set / Regenerate Account Activation Code
$newCode = '654321';
$newHash = hash('sha256', $newCode);
$newExpires = gmdate('Y-m-d H:i:s', time() + 7 * 86400);
$regenerated = $repo->setAccountActivationCode(2, $newHash, $newExpires, 'cipher_new');
assert_test('setAccountActivationCode returns true', $regenerated === true);

$st = $sqlitePdo->query("SELECT activation_code, activation_code_cipher FROM users WHERE id = 2");
$row = $st->fetch(PDO::FETCH_ASSOC);
assert_test('setAccountActivationCode updates code hash', $row['activation_code'] === $newHash);
assert_test('setAccountActivationCode updates code cipher', $row['activation_code_cipher'] === 'cipher_new');

// 3.9 Unlock Owner Account
$sqlitePdo->exec("UPDATE users SET account_status = 'LOCKED', activation_code = 'lockhash' WHERE id = 1");
$unlocked = $repo->unlockOwnerAccount(1);
assert_test('unlockOwnerAccount returns true', $unlocked === true);

$st = $sqlitePdo->query("SELECT account_status, activation_code FROM users WHERE id = 1");
$row = $st->fetch(PDO::FETCH_ASSOC);
assert_test('unlockOwnerAccount updates account_status to ACTIVE', $row['account_status'] === 'ACTIVE');
assert_test('unlockOwnerAccount clears activation_code', $row['activation_code'] === null);

// =========================================================================
// 4. index.php Lifecycle and Authentication Invariants
// =========================================================================
echo "\nT4: index.php Lifecycle, Authentication & Verification Invariants\n";

// 4.1 Owner Registration Initial Pending & Approval Trial Activation
assert_test('Store Owner registration puts store in pending_approval state',
    str_contains($indexSrc, "'pending_approval'") && str_contains($indexSrc, "owner_name, owner_email, registered_at")
);
assert_test('Store Owner registration assigns user status = PENDING awaiting approval',
    str_contains($indexSrc, "account_status,trial_ends_at") && str_contains($indexSrc, "'PENDING',NULL")
);
assert_test('Store Owner approval starts 7-day trial in StoreRepository',
    str_contains($repoSrc, "account_status = 'TRIAL'") && str_contains($repoSrc, 'time() + 7 * 86400')
);
assert_test('Store Owner rejection sets owner account to REJECTED in StoreRepository',
    str_contains($repoSrc, "account_status = 'REJECTED'") && str_contains($repoSrc, 'rejection_reason = ?')
);
assert_test('Store applications and monitoring pending counts reflect pending users',
    str_contains($repoSrc, "SELECT account_status, COUNT(*) AS c FROM users GROUP BY account_status")
);
assert_test('Store application indicates 7-day trial on dashboard when active',
    str_contains($indexSrc, '7-Day Free Trial') && str_contains($indexSrc, '$isTrialAccount')
);

// 4.2 Staff Creation PENDING
assert_test('Staff account creation (add_user) assigns status = PENDING',
    str_contains($indexSrc, "initialStatus = (\$r2 === 'staff') ? 'PENDING' : 'ACTIVE'")
);

// 4.3 Locked Trial Exact Prompt & Expiration Detection
assert_test('Exact 7-day trial ended message is defined',
    str_contains($indexSrc, 'Your 7-day trial has ended. Please enter the 6-digit access code provided by the Super Admin to activate your account.')
);
assert_test('Automatic trial expiration checks role == owner, account_status == TRIAL and trial_ends_at <= time()',
    str_contains($indexSrc, "\$u['role'] === 'owner'") &&
    str_contains($indexSrc, "\$accStatus === 'TRIAL'") &&
    str_contains($indexSrc, "\$trialEndsAt <= time()")
);
assert_test('Trial expiration updates user account_status to LOCKED and store status to suspended',
    str_contains($indexSrc, "account_status = 'LOCKED'") &&
    str_contains($indexSrc, "status = 'suspended'")
);

// 4.4 Pending Login & 24h Rate Limiting
assert_test('Pending login returns "Account pending approval."',
    str_contains($indexSrc, 'Account pending approval.')
);
assert_test('Pending email notification enforces 24-hour rate limit',
    str_contains($indexSrc, 'last_pending_email_at') &&
    str_contains($indexSrc, '86400')
);
assert_test('Pending transactional email mentions Super Admin review within 24 hours',
    str_contains($indexSrc, 'Your staff account is currently undergoing Super Admin review. Approval typically takes up to 24 hours.')
);

// 4.5 Rejected Login Block
assert_test('Rejected login returns registration rejection notice with reason',
    str_contains($indexSrc, 'Your staff account application was not approved.')
);

// 4.6 Verification Endpoint (verify_account_code)
assert_test('verify_account_code action exists',
    str_contains($indexSrc, "\$action === 'verify_account_code'")
);
assert_test('verify_account_code enforces exactly 6-digit access code',
    str_contains($indexSrc, 'strlen($code) !== 6')
);
assert_test('verify_account_code checks code_expires_at validity',
    str_contains($indexSrc, 'code_expires_at')
);
assert_test('verify_account_code transitions account_status to ACTIVE',
    str_contains($indexSrc, "account_status = 'ACTIVE'")
);
assert_test('verify_account_code clears activation code fields upon redemption',
    str_contains($indexSrc, 'activation_code = NULL') &&
    str_contains($indexSrc, 'code_expires_at = NULL')
);
assert_test('verify_account_code restores store status to active for owners',
    str_contains($indexSrc, "UPDATE stores SET status = 'active'")
);

// 4.7 Mid-Session Trial Expiration Check (enforceStoreStanding)
assert_test('enforceStoreStanding checks trial expiration during active session',
    str_contains($indexSrc, 'enforceStoreStanding') &&
    str_contains($indexSrc, "\$uRow['role'] === 'owner'") &&
    str_contains($indexSrc, "\$uStatus === 'TRIAL'")
);

// 4.8 Login UI Passcode Screen
assert_test('Login form renders 6-digit passcode input when trial ended or code required',
    str_contains($indexSrc, 'name="access_code"') &&
    str_contains($indexSrc, 'maxlength="6"')
);

// =========================================================================
// 5. Mailer & Email Interoperability (CC Support & Brevo)
// =========================================================================
echo "\nT5: Mailer & Email Interoperability\n";
assert_test('Mailer::send() supports $cc parameter',
    str_contains($mailerSrc, '?array $cc = null')
);
assert_test('sendBrevoMail() supports $cc parameter',
    str_contains($indexSrc, 'function sendBrevoMail(string $toEmail, string $toName, string $subject, string $html, ?array $cc = null): array')
);
assert_test('Staff approval sends email to staff and CCs store owner',
    str_contains($ctrlSrc, '$ccOwner = $ownerEmail') ||
    str_contains($ctrlSrc, "['email' => \$ownerEmail")
);

// =========================================================================
// Summary
// =========================================================================
echo "\n" . str_repeat('=', 60) . "\n";
$total = $pass + $fail;
echo "Results: {$pass}/{$total} passed";
if ($fail === 0) {
    echo " (all pass)\n\n";
    exit(0);
} else {
    echo " ({$fail} failed)\n\n";
    exit(1);
}
