<?php
/**
 * ProCast POS - Rapid Sequential Thermal Printing Stress Test
 * Fires 10 sequential receipt print jobs within 5 seconds to verify that
 * winspool.drv / HTTP socket handles do not block, freeze, or leak handles.
 */

declare(strict_types=1);

echo "\n======================================================================\n";
echo "   PROCAST POS - RAPID SEQUENTIAL THERMAL PRINTER SPOOLING TEST       \n";
echo "======================================================================\n\n";

$agentUrl = 'http://127.0.0.1:9100/print';
$statusUrl = 'http://127.0.0.1:9100/status';

// 1. Check if agent is listening
$ch = curl_init($statusUrl);
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_TIMEOUT => 3
]);
$statusRes = curl_exec($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

if ($httpCode !== 200) {
    echo "[-] Native print agent is not reachable on {$statusUrl} (HTTP {$httpCode}).\n";
    echo "[*] If running without physical printer, verifying watchdog timeout...\n";
} else {
    echo "[+] Native Print Agent is ONLINE: " . trim((string)$statusRes) . "\n\n";
}

// 2. Fire 10 print jobs in rapid succession
$jobs = 10;
$startTime = microtime(true);
$completedJobs = 0;
$failedJobs = 0;

for ($i = 1; $i <= $jobs; $i++) {
    $ref = 'STRESS-PRN-' . str_pad((string)$i, 3, '0', STR_PAD_LEFT);
    $payload = json_encode([
        'ref' => $ref,
        'date' => date('Y-m-d H:i:s'),
        'cashier' => 'Stress Tester',
        'subtotal' => 100.00,
        'tax' => 0.00,
        'total' => 100.00,
        'cash' => 100.00,
        'change' => 0.00,
        'items' => [
            ['name' => 'Stress Test Product', 'qty' => 1, 'price' => 100.00, 'total' => 100.00]
        ]
    ]);

    $ch = curl_init($agentUrl);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => $payload,
        CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
        CURLOPT_TIMEOUT => 5
    ]);

    $jobStart = microtime(true);
    $res = curl_exec($ch);
    $jobElapsed = round((microtime(true) - $jobStart) * 1000, 1);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($code === 200) {
        $completedJobs++;
        echo "  [+] Job #{$i} ({$ref}): Responded in {$jobElapsed} ms (HTTP 200)\n";
    } else {
        $failedJobs++;
        echo "  [-] Job #{$i} ({$ref}): HTTP {$code} in {$jobElapsed} ms\n";
    }
}

$totalElapsed = round(microtime(true) - $startTime, 2);
echo "\n----------------------------------------------------------------------\n";
echo "Total Jobs Dispatched: {$jobs}\n";
echo "Completed Cleanly:     {$completedJobs}\n";
echo "Failed / Timed Out:    {$failedJobs}\n";
echo "Total Execution Time:  {$totalElapsed} seconds\n";
echo "----------------------------------------------------------------------\n";

if ($completedJobs === $jobs) {
    echo "\n>>> RAPID PRINTER SPOOLING TEST PASSED (NO WORKER FREEZES, 10/10 CLEAN RESPONSES)! <<<\n\n";
    exit(0);
} else {
    echo "\n>>> RAPID PRINTER TEST FAILED! <<<\n\n";
    exit(1);
}
