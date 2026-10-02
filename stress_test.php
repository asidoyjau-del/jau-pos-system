<?php
/**
 * ProCast POS - High-Concurrency Database & Oversell Stress Test Suite
 * Simulates 30 simultaneous checkouts competing for a single item (store_quantity = 1).
 * Verifies that pessimistic row locking and atomic decrements prevent race conditions.
 */

declare(strict_types=1);

error_reporting(E_ALL);
ini_set('display_errors', '1');

echo "\n======================================================================\n";
echo "   PROCAST POS - HIGH-CONCURRENCY RACE CONDITION & OVERSELL SUITE     \n";
echo "======================================================================\n\n";

// 1. Connect to MySQL Database
$dbHost = '127.0.0.1';
$dbPort = 3306;
$dbName = 'pos_system';
$dbUser = 'root';
$dbPass = '';

try {
    $dsn = "mysql:host={$dbHost};port={$dbPort};dbname={$dbName};charset=utf8mb4";
    $pdo = new PDO($dsn, $dbUser, $dbPass, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_TIMEOUT => 5
    ]);
    echo "[+] Database Connected: {$dbName} on {$dbHost}:{$dbPort}\n";
} catch (PDOException $e) {
    echo "[-] Database connection failed: " . $e->getMessage() . "\n";
    exit(1);
}

// 2. Create Temporary Test Cashier/Owner User
$storeRow = $pdo->query("SELECT id FROM stores LIMIT 1")->fetch();
$storeId = (int)($storeRow['id'] ?? 1);

$testUser = 'stress_' . time();
$testPass = 'StressPass123!';
$pwdHash = password_hash($testPass, PASSWORD_BCRYPT);

$pdo->prepare("DELETE FROM users WHERE username LIKE 'stress_%'")->execute();
$pdo->prepare("INSERT INTO users (username, password, full_name, role, store_id) VALUES (?, ?, 'Stress QA Runner', 'owner', ?)")
    ->execute([$testUser, $pwdHash, $storeId]);
$testUserId = (int)$pdo->lastInsertId();

echo "[+] Created Temporary QA User: {$testUser} (ID: {$testUserId}, Store: {$storeId})\n";

// 3. Setup Test Product with EXACTLY store_quantity = 1
$testBarcode = 'STRESS-CONC-' . time();
$pdo->prepare("DELETE FROM products WHERE barcode LIKE 'STRESS-CONC-%'")->execute();

$insertProd = $pdo->prepare("INSERT INTO products (store_id, name, barcode, price, quantity, store_quantity, total_sold, total_revenue) VALUES (?, ?, ?, 100.00, 1, 1, 0, 0)");
$insertProd->execute([$storeId, "STRESS TEST PRODUCT", $testBarcode]);
$testProductId = (int)$pdo->lastInsertId();

echo "[+] Created Test Product: ID #{$testProductId} with EXACTLY store_quantity = 1\n";

// 4. Authenticate via Real Login Flow & Header Extraction
$baseUrl = "http://127.0.0.1/offline_POS-System/pos_system-main/Offline_Pos_System/";

// Step A: Load login page to get initial session ID and CSRF token
$ch = curl_init($baseUrl . "?page=login");
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_HEADER => true,
    CURLOPT_TIMEOUT => 5
]);
$loginPageRes = (string)curl_exec($ch);
curl_close($ch);

$sessId = '';
if (preg_match('/Set-Cookie:\s*PHPSESSID=([^;]+)/i', $loginPageRes, $m)) {
    $sessId = trim($m[1]);
}

$loginCsrf = '';
if (preg_match('/name=["\']csrf_token["\']\s+value=["\']([a-f0-9]{64})["\']/i', $loginPageRes, $m)) {
    $loginCsrf = $m[1];
}

if (!$sessId || !$loginCsrf) {
    echo "[-] Could not retrieve session cookie or CSRF token from login page.\n";
    exit(1);
}

// Step B: Submit POST login form using that PHPSESSID
$loginPostCh = curl_init($baseUrl . "?page=login");
curl_setopt_array($loginPostCh, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_HEADER => true,
    CURLOPT_POST => true,
    CURLOPT_POSTFIELDS => http_build_query([
        'username' => $testUser,
        'password' => $testPass,
        'csrf_token' => $loginCsrf
    ]),
    CURLOPT_COOKIE => 'PHPSESSID=' . $sessId,
    CURLOPT_TIMEOUT => 5
]);
$loginRes = (string)curl_exec($loginPostCh);
curl_close($loginPostCh);

// If session ID rotated on login (session_regenerate_id), capture new one
if (preg_match('/Set-Cookie:\s*PHPSESSID=([^;]+)/i', $loginRes, $m)) {
    $sessId = trim($m[1]);
}

// Step C: Fetch Dashboard to retrieve authenticated CSRF token
$dashCh = curl_init($baseUrl);
curl_setopt_array($dashCh, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_COOKIE => 'PHPSESSID=' . $sessId,
    CURLOPT_TIMEOUT => 5
]);
$dashboardHtml = (string)curl_exec($dashCh);
curl_close($dashCh);

$apiCsrfToken = '';
if (preg_match('/const CSRF_TOKEN\s*=\s*[\'"]([a-f0-9]{64})[\'"]/i', $dashboardHtml, $m)) {
    $apiCsrfToken = $m[1];
} elseif (preg_match('/name=["\']csrf_token["\']\s+value=["\']([a-f0-9]{64})["\']/i', $dashboardHtml, $m)) {
    $apiCsrfToken = $m[1];
}

if (!$apiCsrfToken) {
    echo "[-] Login verification failed. Dashboard did not contain authenticated CSRF token.\n";
    $pdo->prepare("DELETE FROM users WHERE id = ?")->execute([$testUserId]);
    $pdo->prepare("DELETE FROM products WHERE id = ?")->execute([$testProductId]);
    exit(1);
}

echo "[+] Authenticated Session Verified via Apache 2.4 (Session: " . substr($sessId, 0, 10) . "...)\n";
echo "[+] CSRF Token: " . substr($apiCsrfToken, 0, 16) . "...\n\n";

// 5. Build 30 Simultaneous Checkout Requests
$concurrencyWorkers = 30;
echo "[*] Launching {$concurrencyWorkers} simultaneous checkout workers competing for Product #{$testProductId} (Stock = 1)...\n";

$payload = json_encode([
    'items' => [
        [
            'product_id' => $testProductId,
            'name' => 'STRESS TEST PRODUCT',
            'category_name' => 'General',
            'price' => 100.00,
            'qty' => 1,
            'stock_source' => 'store'
        ]
    ],
    'cash' => 100.00
]);

$mh = curl_multi_init();
$curlHandles = [];

$startTime = microtime(true);

for ($i = 1; $i <= $concurrencyWorkers; $i++) {
    $c = curl_init($baseUrl . "?api=add_transaction");
    curl_setopt_array($c, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => $payload,
        CURLOPT_HTTPHEADER => [
            'Content-Type: application/json',
            'X-CSRF-Token: ' . $apiCsrfToken,
            'X-Worker-ID: ' . $i
        ],
        CURLOPT_COOKIE => 'PHPSESSID=' . $sessId,
        CURLOPT_TIMEOUT => 15
    ]);
    curl_multi_add_handle($mh, $c);
    $curlHandles[$i] = $c;
}

// Execute all 30 requests in parallel
$running = null;
do {
    $status = curl_multi_exec($mh, $running);
    if ($running > 0) {
        curl_multi_select($mh, 0.05);
    }
} while ($running > 0 && $status === CURLM_OK);

$elapsed = round((microtime(true) - $startTime) * 1000, 2);
echo "[+] Concurrency execution completed in {$elapsed} ms.\n\n";

// 6. Analyze Responses
$successCount = 0;
$failCount = 0;
$successfulOrderRefs = [];
$failureReasons = [];

foreach ($curlHandles as $workerId => $c) {
    $responseBody = curl_multi_getcontent($c);
    $httpCode = curl_getinfo($c, CURLINFO_HTTP_CODE);
    curl_multi_remove_handle($mh, $c);
    curl_close($c);

    $json = json_decode((string)$responseBody, true);
    if (is_array($json) && !empty($json['success'])) {
        $successCount++;
        $successfulOrderRefs[] = $json['data']['order_ref'] ?? 'UNKNOWN';
    } else {
        $failCount++;
        $errMsg = $json['error'] ?? "HTTP {$httpCode}: " . substr(strip_tags((string)$responseBody), 0, 80);
        $failureReasons[$errMsg] = ($failureReasons[$errMsg] ?? 0) + 1;
    }
}
curl_multi_close($mh);

// 7. Verify Database State Post-Stress
$finalProd = $pdo->prepare("SELECT quantity, store_quantity, total_sold FROM products WHERE id = ?");
$finalProd->execute([$testProductId]);
$prodState = $finalProd->fetch();

$finalStock = (int)($prodState['store_quantity'] ?? -999);
$totalSold = (int)($prodState['total_sold'] ?? -999);

$txQuery = $pdo->prepare("SELECT COUNT(*) as cnt FROM transactions WHERE id IN (SELECT transaction_id FROM transaction_items WHERE product_id = ?)");
$txQuery->execute([$testProductId]);
$txCount = (int)$txQuery->fetchColumn();

// 8. Output Detailed Results
echo "----------------------------------------------------------------------\n";
echo " STRESS TEST EXECUTION RESULTS:\n";
echo "----------------------------------------------------------------------\n";
echo "Concurrent Workers Dispatched:   {$concurrencyWorkers}\n";
echo "Successful Checkouts:            {$successCount}\n";
echo "Rejected Out-of-Stock Checkouts: {$failCount}\n";
echo "Final In-Database Stock:         {$finalStock}\n";
echo "Final In-Database Total Sold:    {$totalSold}\n";
echo "Actual Orders Created in DB:     {$txCount}\n";
echo "Successful Order Refs:           " . implode(', ', $successfulOrderRefs) . "\n";
echo "Rejection Reasons Recorded:\n";
foreach ($failureReasons as $reason => $count) {
    echo "  - [{$count}x] {$reason}\n";
}
echo "----------------------------------------------------------------------\n";

// 9. Automated Verdict Checks
$passed = true;
$assertions = [];

if ($successCount === 1) {
    $assertions[] = "[PASS] Exactly 1 checkout succeeded.";
} else {
    $assertions[] = "[FAIL] Expected exactly 1 checkout to succeed, got {$successCount} (OVERSELL DETECTED).";
    $passed = false;
}

if ($failCount === ($concurrencyWorkers - 1)) {
    $assertions[] = "[PASS] Exactly " . ($concurrencyWorkers - 1) . " checkouts were cleanly rejected.";
} else {
    $assertions[] = "[FAIL] Expected " . ($concurrencyWorkers - 1) . " rejections, got {$failCount}.";
    $passed = false;
}

if ($finalStock === 0) {
    $assertions[] = "[PASS] Final store_quantity is exactly 0 (no negative inventory).";
} else {
    $assertions[] = "[FAIL] Expected store_quantity to be 0, got {$finalStock}.";
    $passed = false;
}

if ($txCount === 1) {
    $assertions[] = "[PASS] Exactly 1 transaction record exists in the database.";
} else {
    $assertions[] = "[FAIL] Expected 1 transaction record, found {$txCount}.";
    $passed = false;
}

echo "\nVERIFICATION VERDICTS:\n";
foreach ($assertions as $a) {
    echo "  {$a}\n";
}

// 10. Clean up test records
$pdo->prepare("DELETE FROM transaction_items WHERE product_id = ?")->execute([$testProductId]);
$pdo->prepare("DELETE FROM products WHERE id = ?")->execute([$testProductId]);
$pdo->prepare("DELETE FROM users WHERE id = ?")->execute([$testUserId]);
echo "\n[+] Cleaned up temporary test product #{$testProductId} and QA user #{$testUserId}.\n";

if ($passed) {
    echo "\n>>> OVERSELL STRESS TEST PASSED WITH ZERO TRANSACTION DROPS AND ZERO OVERSELLING! <<<\n\n";
    exit(0);
} else {
    echo "\n>>> OVERSELL STRESS TEST FAILED! <<<\n\n";
    exit(1);
}
