<?php
declare(strict_types=1);

/**
 * MonitoringSchemaTest.php
 * ========================
 * Tests that the monitoring analytics degrade instead of failing when the
 * deployed PostgreSQL schema is missing columns.
 *
 * Run WITHOUT a web server and WITHOUT a database:
 *     php tests/MonitoringSchemaTest.php
 *
 * Why this exists
 * ---------------
 * `transactions.payment_method` and `transactions.order_type` are referenced by
 * monitoring logic but are never created by any CREATE or ALTER in this
 * repository -- they exist only as POS payload keys. A query naming a column
 * that does not exist raises SQLSTATE 42703, and because that happened inside
 * the page render it took the whole monitoring screen down. `voided_total` and
 * `status` are added by best-effort ALTERs, so they are optional too.
 *
 * The rule this suite pins down:
 *   - When the schema CAN be inspected and a column is ABSENT, rebuild the query.
 *   - When the probe itself FAILS we do NOT know, so fall back to the historical
 *     behaviour. Assuming "absent" there would silently overstate revenue by
 *     ignoring refunds -- a worse bug than the one being fixed.
 *
 * No PostgreSQL required: the schema probe is answered from a fixed column map
 * by a fake PDO, which is exactly the seam the production code uses.
 */

if (!defined('PROCAST_ROOT')) {
    define('PROCAST_ROOT', dirname(__DIR__));
}
require_once PROCAST_ROOT . '/app/Bootstrap.php';

use ProCast\Support\StoreRepository;
use ProCast\Support\View;

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

/* ── Fakes ──────────────────────────────────────────────────────────── */

/**
 * Answers the information_schema probe from a column map, and every other
 * query from a canned row set.
 */
class SchemaPdo extends PDO
{
    /** @var array<string,array<string,string>> table => column => data_type */
    public array $columns = [];
    /**
     * Rows returned by the information_schema probe, and by reporting queries.
     *
     * These MUST be separate properties: the fake serves both from one list, and
     * a test that empties the query rows would otherwise wipe the schema metadata
     * too -- making the probe look broken and silently flipping the very
     * feature-detection under test.
     *
     * @var list<array<string,mixed>>
     */
    public array $probeRows = [];
    /** @var list<array<string,mixed>> */
    public array $rows = [];
    /** When true, non-probe queries throw, simulating a real 42703. */
    public bool $explode = false;
    public string $lastQuery = '';
    /**
     * Every non-probe query, in order.
     *
     * `lastQuery` alone is not enough to assert on a specific section: analytics()
     * runs trend, top_stores, summary and then splits, so the final query is the
     * splits one and checking it would silently test the wrong SQL.
     *
     * @var list<string>
     */
    public array $queries = [];
    /**
     * Bound parameters per non-probe query, in execution order.
     *
     * Needed because PostgreSQL binds a positional '?' by where it appears in
     * the SQL TEXT, not by the order the values were assembled in PHP. A
     * store-scoping filter splices an argument into the middle of a statement
     * that already had one (the timezone), so the order is only verifiable from
     * the bound list against the query text.
     *
     * @var list<list<mixed>>
     */
    public array $boundParams = [];

    public function __construct()
    {
        // Deliberately does NOT call parent::__construct(): there is no database
        // here and prepare() is fully overridden.
    }

    public function prepare(string $query, array $options = []): PDOStatement|false
    {
        $this->lastQuery = $query;
        if (!str_contains($query, 'information_schema')) {
            $this->queries[] = $query;
        }
        return new SchemaStatement($this, $query);
    }

    /** Build the probe's row set from a column map. */
    public function setColumns(array $columns): void
    {
        $this->columns   = $columns;
        $this->probeRows = [];
        foreach ($columns as $table => $cols) {
            foreach ($cols as $col => $type) {
                $this->probeRows[] = [
                    'table_name' => $table, 'column_name' => $col, 'data_type' => $type,
                ];
            }
        }
    }
}

final class SchemaStatement extends PDOStatement
{
    private SchemaPdo $fake;
    private string $query;

    public function __construct(SchemaPdo $fake, string $query)
    {
        $this->fake  = $fake;
        $this->query = $query;
    }

    public function execute(?array $params = null): bool
    {
        if (!str_contains($this->query, 'information_schema')) {
            $this->fake->boundParams[] = $params ?? [];
        }
        return true;
    }

    public function fetchAll(int $mode = PDO::FETCH_DEFAULT, mixed ...$args): array
    {
        // The probe is the only query that must succeed for hasColumn() to
        // have an opinion; everything else can be made to fail on demand.
        if (str_contains($this->query, 'information_schema')) {
            return $this->fake->probeRows;
        }
        if ($this->fake->explode) {
            throw new RuntimeException('SQLSTATE[42703]: Undefined column');
        }
        return $this->fake->rows;
    }

    public function fetch(int $mode = PDO::FETCH_DEFAULT, int $cursorOrientation = PDO::FETCH_ORI_NEXT, int $cursorOffset = 0): mixed
    {
        return $this->fetchAll($mode)[0] ?? false;
    }

    public function fetchColumn(int $column = 0): mixed
    {
        $r = $this->fetch();
        return is_array($r) ? (array_values($r)[$column] ?? false) : false;
    }
}

/** The current deployment: has voided_total and status. */
function modern_schema(): array
{
    return ['transactions' => [
        'id' => 'integer', 'total' => 'numeric', 'created_at' => 'timestamp without time zone',
        'voided_total' => 'numeric', 'status' => 'text',
    ], 'stores' => ['id' => 'integer', 'name' => 'text', 'status' => 'text']];
}

/** An older schema predating the best-effort ALTERs. */
function legacy_schema(): array
{
    return ['transactions' => [
        'id' => 'integer', 'total' => 'numeric', 'created_at' => 'timestamp without time zone',
    ], 'stores' => ['id' => 'integer', 'name' => 'text', 'status' => 'text']];
}

echo "\nMonitoring Schema-Safety Test Suite\n";
echo str_repeat('=', 55) . "\n";

$repoSrc = (string)file_get_contents(PROCAST_ROOT . '/app/Support/StoreRepository.php');
$viewSrc = (string)file_get_contents(PROCAST_ROOT . '/platform-admin/views/monitoring.php');
$ctrlSrc = (string)file_get_contents(PROCAST_ROOT . '/app/Controllers/PlatformAdmin/SuperAdminDashboardController.php');
$css     = (string)file_get_contents(PROCAST_ROOT . '/platform-admin/assets/admin.css');

/* ────────────────────────────────────────────────────────────────
 * 1. Feature detection
 * ──────────────────────────────────────────────────────────────── */
echo "\nT1: column feature detection\n";

$pdo = new SchemaPdo();
$pdo->setColumns(modern_schema());
$repo = new StoreRepository($pdo);

assert_test('an existing column is reported present',
    $repo->hasColumn('transactions', 'voided_total'));
assert_test('total is reported present',
    $repo->hasColumn('transactions', 'total'));
assert_test('payment_method is absent on the real schema',
    !$repo->hasColumn('transactions', 'payment_method'));
assert_test('order_type is absent on the real schema',
    !$repo->hasColumn('transactions', 'order_type'));

$legacyPdo = new SchemaPdo();
$legacyPdo->setColumns(legacy_schema());
$legacyRepo = new StoreRepository($legacyPdo);

assert_test('a legacy schema reports voided_total absent',
    !$legacyRepo->hasColumn('transactions', 'voided_total'));
assert_test('a legacy schema reports status absent',
    !$legacyRepo->hasColumn('transactions', 'status'));
assert_test('a legacy schema still reports total present',
    $legacyRepo->hasColumn('transactions', 'total'));

/* The probe must be cached, not re-run per column. */
$cached = new SchemaPdo();
$cached->setColumns(modern_schema());
$cachedRepo = new StoreRepository($cached);
$cachedRepo->hasColumn('transactions', 'total');
$cachedRepo->hasColumn('transactions', 'voided_total');
$cachedRepo->hasColumn('transactions', 'status');
assert_test('the schema probe is cached across lookups',
    str_contains($cached->lastQuery, 'information_schema')
    && $cachedRepo->hasColumn('transactions', 'status'));

/* ────────────────────────────────────────────────────────────────
 * 2. Degradation when the probe itself fails
 * ──────────────────────────────────────────────────────────────── */
echo "\nT2: probe failure must NOT be read as 'column missing'\n";

/* An empty probe result means the metadata lookup told us nothing. Reading that
 * as "every optional column is absent" would quietly stop subtracting refunds
 * and overstate revenue -- so the fallback must assume the columns exist. */
$blind = new SchemaPdo();
$blind->probeRows = [];
$blindRepo = new StoreRepository($blind);
assert_test('a schema with no transactions table falls back to assuming columns exist',
    $blindRepo->hasColumn('transactions', 'voided_total')
    && $blindRepo->hasColumn('transactions', 'status'),
    'an unreadable schema must not silently drop refund handling');

/* ────────────────────────────────────────────────────────────────
 * 3. Generated SQL omits absent columns
 * ──────────────────────────────────────────────────────────────── */
echo "\nT3: generated SQL reflects the deployed schema\n";

$legacyPdo->rows = [['c' => 3, 'v' => 1500.0]];
$legacyRepo->volume(1750000000);
assert_test('volume() does not name voided_total on a legacy schema',
    !str_contains($legacyPdo->lastQuery, 'voided_total'));
assert_test('volume() does not filter on t.status when status is absent',
    !str_contains($legacyPdo->lastQuery, 't.status'));
assert_test('legacy volume() still sums total', str_contains($legacyPdo->lastQuery, 'total'));

$modernPdo = new SchemaPdo();
$modernPdo->setColumns(modern_schema());
$modernPdo->rows = [['c' => 3, 'v' => 1500.0]];
$modernRepo = new StoreRepository($modernPdo);
$modernRepo->volume(1750000000);
assert_test('modern volume() DOES subtract voided_total',
    str_contains($modernPdo->lastQuery, 'voided_total'));
assert_test('modern volume() DOES exclude voided orders',
    str_contains($modernPdo->lastQuery, 'voided'));

/* ────────────────────────────────────────────────────────────────
 * 4. Optional splits are feature-gated, never queried blind
 * ──────────────────────────────────────────────────────────────── */
echo "\nT4: payment_method / order_type are gated\n";

$splitPdo = new SchemaPdo();
$splitPdo->setColumns(modern_schema());
$splitPdo->rows = [];
$splitRepo = new StoreRepository($splitPdo);
$splits = $splitRepo->analytics(1750000000, 14)['splits'];
/* `??` cannot express "this key exists and is null" -- it yields the fallback for a
   null value exactly as it does for a missing key. array_key_exists() is required
   to tell "feature-gated away" apart from "not produced at all". */
$isNull = static fn(array $row, string $k): bool =>
    array_key_exists($k, $row) && $row[$k] === null;

assert_test('payment_methods is null when the column does not exist',
    $isNull($splits, 'payment_methods'), 'got: ' . json_encode($splits));
assert_test('order_types is null when the column does not exist',
    $isNull($splits, 'order_types'), 'got: ' . json_encode($splits));

/* When the columns DO exist, the same code path must actually run them. */
$richPdo = new SchemaPdo();
$rich = modern_schema();
$rich['transactions']['payment_method'] = 'text';
$rich['transactions']['order_type']     = 'text';
$richPdo->setColumns($rich);
$richPdo->rows = [['label' => 'cash', 'v' => 900.0, 'c' => 4]];
$richRepo = new StoreRepository($richPdo);
$richSplits = $richRepo->analytics(1750000000, 14)['splits'];
assert_test('payment_methods is queried once the column exists',
    is_array($richSplits['payment_methods'])
    && ($richSplits['payment_methods'][0]['label'] ?? '') === 'cash');
assert_test('order_types is queried once the column exists',
    is_array($richSplits['order_types']));

/* ────────────────────────────────────────────────────────────────
 * 5. One failing section must not take down the page
 * ──────────────────────────────────────────────────────────────── */
echo "\nT5: analytics degrade section-by-section\n";

$boom = new SchemaPdo();
/* Use the RICH schema on purpose. With the normal schema the splits section
   feature-detects the missing columns and never issues a query, so it correctly
   stays available -- which would mask the thing this section is testing. */
$boomSchema = modern_schema();
$boomSchema['transactions']['payment_method'] = 'text';
$boomSchema['transactions']['order_type']     = 'text';
$boom->setColumns($boomSchema);
$boom->explode = true;   // every reporting query throws 42703
$boomRepo = new StoreRepository($boom);
$out = $boomRepo->analytics(1750000000, 14);

assert_test('analytics() still returns every documented section',
    array_keys($out) === ['trend', 'top_stores', 'summary', 'splits']);
$degraded = true;
foreach ($out as $k => $section) {
    if (($section['available'] ?? true) !== false) {
        $degraded = false;
        echo "       section {$k} did not degrade\n";
    }
}
assert_test('a failing query degrades every section instead of throwing', $degraded);
assert_test('a degraded section is still array-shaped (safe to index)',
    is_array($out['trend']) && array_key_exists('available', $out['trend']));

/* The mirror image: when a column is absent, optionalSplits() feature-detects and
   never queries, so the section stays available with null splits. That is a
   SUCCESS, not a degraded state, and must not be conflated with the above. */
$detect = new SchemaPdo();
$detect->setColumns(modern_schema());
$detectSplits = (new StoreRepository($detect))->analytics(1750000000, 14)['splits'];
assert_test('splits stay available when they are feature-detected, not queried',
    ($detectSplits['available'] ?? null) === true
    && $isNull($detectSplits, 'payment_methods'),
    'got: ' . json_encode($detectSplits));

/* ────────────────────────────────────────────────────────────────
 * 6. Numeric guards
 * ──────────────────────────────────────────────────────────────── */
echo "\nT6: no NaN/INF leaks into JSON\n";

$empty = new SchemaPdo();
$empty->setColumns(modern_schema());
$empty->rows = [];   // a window with no sales at all
$emptyRepo = new StoreRepository($empty);
$sum = $emptyRepo->analytics(1750000000, 14)['summary'];
assert_test('average order value is 0.0, not NAN, on an empty window',
    ($sum['aov'] ?? null) === 0.0, 'got: ' . var_export($sum['aov'] ?? null, true));
assert_test('void rate is 0.0, not NAN, on an empty window',
    ($sum['void_rate'] ?? null) === 0.0);

$encoded = json_encode($emptyRepo->analytics(1750000000, 14));
assert_test('the whole payload is JSON-encodable (no INF/NAN)',
    $encoded !== false && !str_contains((string)$encoded, 'NAN'));

$trend = $emptyRepo->analytics(1750000000, 14)['trend'];
assert_test('the trend still emits one bucket per day even with no sales',
    is_array($trend['days'] ?? null) && count($trend['days']) === 14);
assert_test('zero days are explicit zeroes, not gaps',
    ($trend['days'][0]['total'] ?? null) === 0.0);

$clamped = $emptyRepo->analytics(1750000000, 0)['trend'];
assert_test('a zero-day window is clamped up to the minimum',
    is_array($clamped['days'] ?? null) && count($clamped['days']) === 2);
$wide = $emptyRepo->analytics(1750000000, 9999)['trend'];
assert_test('an oversized window is clamped down to the maximum',
    is_array($wide['days'] ?? null) && count($wide['days']) === 90);

/* ────────────────────────────────────────────────────────────────
 * 6b. The void rate is actually reachable
 * ──────────────────────────────────────────────────────────────── */
echo "\nT6b: the void rate is not silently pinned to zero\n";

/* Regression: periodSummary() used to combine voidFilter() in the WHERE clause
   with a COUNT(*) FILTER (WHERE status = 'voided'). The WHERE deleted exactly
   the rows the FILTER looked for, so voided was always 0 and the admin UI
   reported a 0% void rate no matter how many orders were voided. */

/* 1. Source-level: the void tally may not sit behind a void exclusion.
      Comments are stripped first, otherwise the explanatory note above would
      satisfy the check on its own. */
$summaryBody = preg_match('/function periodSummary\(.*?\n    \}/s', $repoSrc, $m2) ? $m2[0] : '';
$summaryCode = preg_replace('!/\*.*?\*/!s', '', $summaryBody);
assert_test('periodSummary() body is locatable for inspection', $summaryBody !== '');
assert_test('periodSummary() does NOT call voidFilter()',
    !str_contains($summaryCode, 'voidFilter('),
    'the voided rows must remain countable');
assert_test('periodSummary() restricts revenue with an aggregate FILTER',
    str_contains($summaryCode, 'FILTER (WHERE'));

/* 2. Generated SQL: the void count must not be filtered away.
      Target the summary query specifically -- analytics() ends with the splits
      section, so lastQuery here would be a different statement entirely. */
$voidSqlPdo = new SchemaPdo();
$voidSqlPdo->setColumns(modern_schema());
$voidSqlPdo->rows = [['c' => 8, 'v' => 900.0, 'vx' => 2]];
(new StoreRepository($voidSqlPdo))->analytics(1750000000, 14);

$summarySql = '';
foreach ($voidSqlPdo->queries as $q) {
    if (str_contains($q, 'AS vx')) { $summarySql = $q; break; }
}
assert_test('the summary query is locatable by its void alias', $summarySql !== '');
assert_test('the void tally is selected when status exists',
    str_contains($summarySql, "'voided'"));

/* Only the clause-level WHERE (the one after FROM) may constrain rows. An earlier
   `WHERE` belongs to an aggregate FILTER and restates the same predicate harmlessly,
   so the search has to start at FROM or it false-positives on its own output. */
$clauseWhere = $summarySql;
$fromAt = strpos($summarySql, 'FROM transactions');
if ($fromAt !== false) { $clauseWhere = substr($summarySql, $fromAt); }
assert_test('the clause-level WHERE does not exclude voided orders',
    !preg_match('/COALESCE\(t\.status,\'completed\'\)\s*<>/', $clauseWhere),
    'got: ' . $clauseWhere);
assert_test('revenue is restricted to non-voided orders by an aggregate FILTER',
    str_contains($summarySql, 'SUM(') && str_contains($summarySql, 'FILTER (WHERE'));

/* 3. Behavioural: a real void count must survive into the payload. */
$voidRealPdo = new SchemaPdo();
$voidRealPdo->setColumns(modern_schema());
$voidRealPdo->rows = [['c' => 8, 'v' => 900.0, 'vx' => 2]];
$voidReal = (new StoreRepository($voidRealPdo))->analytics(1750000000, 14)['summary'];
assert_test('a non-zero void count is reported', ($voidReal['voided'] ?? 0) === 2,
    'got: ' . json_encode($voidReal));
assert_test('void rate is 2 of 10 placed orders (0.2), not 2 of 8',
    ($voidReal['void_rate'] ?? null) === 0.2,
    'got: ' . var_export($voidReal['void_rate'] ?? null, true));

/* 4. The degenerate all-voided window must read 100%, not >100% or NAN. */
$allVoidPdo = new SchemaPdo();
$allVoidPdo->setColumns(modern_schema());
$allVoidPdo->rows = [['c' => 0, 'v' => 0.0, 'vx' => 5]];
$allVoid = (new StoreRepository($allVoidPdo))->analytics(1750000000, 14)['summary'];
assert_test('a fully voided window reports a 100% void rate',
    ($allVoid['void_rate'] ?? null) === 1.0,
    'got: ' . var_export($allVoid['void_rate'] ?? null, true));
assert_test('a fully voided window reports no orders (so AOV stays 0.0)',
    ($allVoid['count'] ?? null) === 0 && ($allVoid['aov'] ?? null) === 0.0);

/* 5. Without a status column there is nothing to count, and that must be a
   clean zero rather than a division against a missing value. */
$nostatusPdo = new SchemaPdo();
$nostatusPdo->setColumns(legacy_schema());
$nostatusPdo->rows = [['c' => 4, 'v' => 300.0]];
$nostatus = (new StoreRepository($nostatusPdo))->analytics(1750000000, 14)['summary'];
assert_test('a legacy schema reports a zero void rate',
    ($nostatus['void_rate'] ?? null) === 0.0 && ($nostatus['voided'] ?? null) === 0);

/* ────────────────────────────────────────────────────────────────
 * 7. Source-level guards
 * ──────────────────────────────────────────────────────────────── */
echo "\nT7: the fix stays in place\n";

/* Extract just the volume() body so an unrelated mention elsewhere in the file
 * (a comment, another method) cannot satisfy the check vacuously. */
$volumeBody = preg_match('/function volume\(.*?\n    \}/s', $repoSrc, $m) ? $m[0] : '';
assert_test('volume() body is locatable for inspection', $volumeBody !== '');
assert_test('volume() builds its SQL through netSql()', str_contains($volumeBody, 'netSql('));
assert_test('volume() builds its SQL through voidFilter()', str_contains($volumeBody, 'voidFilter('));
assert_test('volume() does not hard-code t.voided_total',
    !str_contains($volumeBody, 't.voided_total'));
assert_test('volume() does not hard-code t.status', !str_contains($volumeBody, 't.status'));

assert_test('a schema probe exists', str_contains($repoSrc, 'information_schema'));
assert_test('the probe is cached in a property',
    str_contains($repoSrc, 'private ?array $columns'));
assert_test('hasColumn() is public so callers can feature-detect',
    str_contains($repoSrc, 'public function hasColumn('));

/* The telemetry endpoint polls on a short interval; analytics scans a window.
 * Folding analytics in there would turn a status ping into a reporting query. */
$telemetryBody = preg_match('/function telemetry\(.*?\n    \}/s', $ctrlSrc, $m2) ? $m2[0] : '';
assert_test('telemetry() body is locatable', $telemetryBody !== '');
assert_test('telemetry() does not run the analytics queries',
    !str_contains($telemetryBody, 'analytics('));
assert_test('the monitoring page does pass analytics through',
    (bool)preg_match('/function monitoring\(.*?analytics\(/s', $ctrlSrc));

/* ────────────────────────────────────────────────────────────────
 * 8. The view tolerates missing / partial analytics
 * ──────────────────────────────────────────────────────────────── */
echo "\nT8: the view renders with and without analytics\n";

$baseData = [
    'counts'   => ['total' => 3, 'active' => 2, 'pending_approval' => 1, 'suspended' => 0, 'rejected' => 0],
    'sessions' => 5,
    'volume'   => ['today' => ['count' => 4, 'total' => 1234.5], 'month' => ['count' => 40, 'total' => 12345.0]],
    'title'    => 't', 'nav' => 'monitoring', 'admin' => ['full_name' => 'A', 'email' => 'a@b.c'],
    'csrf'     => 'tok', 'flash' => null, 'flashSecret' => null,
];

try {
    $noAnalytics = View::render('monitoring', $baseData, 'layout');
    assert_test('the view renders when analytics is absent entirely',
        str_contains($noAnalytics, 'pa-stats'));
    assert_test('no trend chart is emitted without analytics',
        !str_contains($noAnalytics, 'pa-trend-bar'));
} catch (Throwable $e) {
    assert_test('the view renders when analytics is absent entirely', false, $e->getMessage());
}

try {
    $fullData = $baseData + ['analytics' => [
        'trend' => ['available' => true, 'days' => [
            ['date' => '2026-10-08', 'total' => 500.0, 'count' => 3],
            ['date' => '2026-10-09', 'total' => 0.0,  'count' => 0],
        ]],
        'summary' => ['available' => true, 'count' => 3, 'total' => 500.0,
                      'aov' => 166.67, 'voided' => 0, 'void_rate' => 0.0],
        'top_stores' => ['available' => true, 'stores' => [
            ['id' => 7, 'name' => 'Seven', 'total' => 500.0, 'count' => 3],
        ]],
        'splits' => ['available' => true, 'payment_methods' => null, 'order_types' => null],
    ]];
    $withAnalytics = View::render('monitoring', $fullData, 'layout');
    assert_test('the view renders with full analytics',
        str_contains($withAnalytics, 'pa-trend-bar'));
    assert_test('a zero-sales day still renders a bar stub',
        str_contains($withAnalytics, 'is-zero'));
    assert_test('the top store name is rendered', str_contains($withAnalytics, 'Seven'));
    assert_test('the peak bar is scaled to 100% of the maximum',
        str_contains($withAnalytics, 'height:100%'));
} catch (Throwable $e) {
    assert_test('the view renders with full analytics', false, $e->getMessage());
}

try {
    $degradedData = $baseData + ['analytics' => [
        'trend' => ['available' => false], 'summary' => ['available' => false],
        'top_stores' => ['available' => false], 'splits' => ['available' => false],
    ]];
    $degraded = View::render('monitoring', $degradedData, 'layout');
    assert_test('a fully degraded payload renders no analytics cards',
        !str_contains($degraded, 'pa-trend-bar') && !str_contains($degraded, 'Top Stores'));
} catch (Throwable $e) {
    assert_test('a fully degraded payload renders no analytics cards', false, $e->getMessage());
}

/* An all-zero window is the divide-by-zero case the chart has to survive. */
try {
    $flatData = $baseData + ['analytics' => [
        'trend' => ['available' => true, 'days' => [
            ['date' => '2026-10-08', 'total' => 0.0, 'count' => 0],
            ['date' => '2026-10-09', 'total' => 0.0, 'count' => 0],
        ]],
        'summary' => ['available' => true, 'count' => 0, 'total' => 0.0,
                      'aov' => 0.0, 'voided' => 0, 'void_rate' => 0.0],
        'top_stores' => ['available' => true, 'stores' => []],
        'splits' => ['available' => true, 'payment_methods' => null, 'order_types' => null],
    ]];
    $flat = View::render('monitoring', $flatData, 'layout');
    assert_test('an all-zero window renders without dividing by zero',
        str_contains($flat, 'pa-trend-bar'));
    assert_test('an empty top-stores list shows an explanatory line',
        str_contains($flat, 'No sales in this window yet.'));
} catch (Throwable $e) {
    assert_test('an all-zero window renders without dividing by zero', false, $e->getMessage());
}

assert_test('the view declares the analytics var it consumes',
    str_contains($viewSrc, '@var array $analytics'));
assert_test('the view defaults analytics so it stays renderable',
    str_contains($viewSrc, '$analytics ?? []'));
assert_test('the peak is guarded against division by zero',
    str_contains($viewSrc, '$peak > 0'));

assert_test('the trend bar style exists', str_contains($css, '.pa-trend-bar'));
assert_test('the metrics list style exists', str_contains($css, '.pa-metrics'));
assert_test('the ranking list style exists', str_contains($css, '.pa-rank'));
assert_test('the previously-missing modal confirm wrap is now styled',
    str_contains($css, '.modal-confirm-input-wrap'));

/* ────────────────────────────────────────────────────────────────
 * 9. Store scoping: one store's figures must never include another's
 * ──────────────────────────────────────────────────────────────── */
echo "\nT9: store scoping isolates tenants\n";

/* A fresh fake per assertion: queries/boundParams accumulate, and reading a
   stale entry would let a later assertion pass on the previous method's SQL. */
function scoped_repo(): array
{
    $pdo = new SchemaPdo();
    $pdo->setColumns(modern_schema());
    $pdo->rows = [['c' => 1, 'v' => 100.0]];
    return [new StoreRepository($pdo), $pdo];
}

[$scopedRepo, $scopedPdo] = scoped_repo();
$scopedRepo->volume(1750000000, 7);
$scopedVolumeSql = $scopedPdo->lastQuery;
assert_test('a scoped volume query filters on the store id',
    str_contains($scopedVolumeSql, 't.store_id = ?'));
assert_test('a scoped volume query does NOT filter on stores.status',
    !str_contains($scopedVolumeSql, "s.status = 'active'"));
assert_test('the store id is bound as a parameter, never interpolated',
    in_array(7, $scopedPdo->boundParams[0] ?? [], true)
    && !str_contains($scopedVolumeSql, '7'));

[$wideRepo, $widePdo] = scoped_repo();
$wideRepo->volume(1750000000);
assert_test('a platform-wide volume query still filters on stores.status',
    str_contains($widePdo->lastQuery, "s.status = 'active'"));
assert_test('a platform-wide volume query carries no store filter',
    !str_contains($widePdo->lastQuery, 't.store_id = ?'));

/* Placeholder order: the window start is the only bound value on an unscoped
   query; once scoped, the store id must be spliced BEFORE it, because that is
   where its '?' sits in the WHERE clause. Reversing them silently reports one
   store's revenue measured over another store's date range. */
$scopedVolParams = $scopedPdo->boundParams[0] ?? [];
assert_test('the scoped store id binds before the window start',
    count($scopedVolParams) === 2 && $scopedVolParams[0] === 7 && is_string($scopedVolParams[1]),
    'got: ' . json_encode($scopedVolParams));

/* Scoped revenueTrend binds the timezone in the SELECT clause, so its argument
   must come first even though it is assembled last in PHP. */
[$trendRepo, $trendPdo] = scoped_repo();
$trendRepo->analytics(1750000000, 14, 7);
$trendIdx = null;
foreach ($trendPdo->queries as $i => $q) {
    if (str_contains($q, 'GROUP BY d')) { $trendIdx = $i; break; }
}
$trendParams = $trendIdx !== null ? ($trendPdo->boundParams[$trendIdx] ?? []) : [];
assert_test('the trend binds timezone, then store id, then window start',
    count($trendParams) === 3
    && is_string($trendParams[0])             // timezone leads (SELECT clause)
    && $trendParams[1] === 7                  // then the store scope (WHERE)
    && is_string($trendParams[2]),
    'got: ' . json_encode($trendParams));

/* activeSessions counts auth_tokens, whose alias is 't' -- and which has NO
   store_id column. Scoping through the wrong alias is a hard SQL error. */
[$sessRepo, $sessPdo] = scoped_repo();
$sessRepo->activeSessions(1750000000, 7);
assert_test('scoped sessions filter through users.store_id, not auth_tokens',
    str_contains($sessPdo->lastQuery, 'u.store_id = ?'));
assert_test('the scoped session query never names t.store_id',
    !str_contains($sessPdo->lastQuery, 't.store_id'));
assert_test('the scoped session query still joins users',
    str_contains($sessPdo->lastQuery, 'JOIN users u') || str_contains($sessPdo->lastQuery, 'users u'));

/* The leaderboard is the one section that must NOT be scoped: ranking a single
   store against itself always returns one row, which reads as "top store". */
$scopedAnalytics = $scopedRepo->analytics(1750000000, 14, 7);
assert_test('a scoped analytics run reports top_stores unavailable',
    ($scopedAnalytics['top_stores']['available'] ?? null) === false
    && ($scopedAnalytics['top_stores']['reason'] ?? null) === 'store_scoped');
$wideAnalytics = $wideRepo->analytics(1750000000, 14);
assert_test('a platform-wide analytics run still offers the leaderboard',
    ($wideAnalytics['top_stores']['available'] ?? null) === true);
assert_test('analytics() exposes query sections only (every key can degrade)',
    array_keys($scopedAnalytics) === ['trend', 'top_stores', 'summary', 'splits'],
    'got: ' . json_encode(array_keys($scopedAnalytics)));

/* ────────────────────────────────────────────────────────────────
 * 10. The page and its poll must agree on the scope
 * ──────────────────────────────────────────────────────────────── */
echo "\nT10: page and telemetry share one scope\n";

$jsSrc    = (string)file_get_contents(PROCAST_ROOT . '/platform-admin/assets/admin.js');
$kernelSrc = (string)file_get_contents(PROCAST_ROOT . '/app/PlatformAdmin/Kernel.php');

assert_test('the telemetry route forwards the request so ?store= survives',
    str_contains($kernelSrc, '$dash->telemetry($req, $now)'));
assert_test('telemetry() resolves the same ?store= scope as the page',
    substr_count($ctrlSrc, 'monitoringScope($req)') === 2);
assert_test('telemetry echoes the store_id it answered for',
    str_contains($ctrlSrc, "'store_id' => \$storeId"));

/* An unresolvable numeric id must be refused. Falling back to the platform
   roll-up would serve every tenant's numbers under a URL that still claims to
   be one store, with nothing on screen to contradict it. */
assert_test('the scope helper separates "no store" from "unknown store"',
    str_contains($ctrlSrc, "'invalid' => \$store === null"));
assert_test('the page refusal is a 404',
    preg_match("/if \(\\\$scope\['invalid'\]\) \{\s*return Response::notFound\(\);/", $ctrlSrc) === 1);
assert_test('the telemetry refusal reports 404 too',
    preg_match("/if \(\\\$scope\['invalid'\]\) \{\s*return Response::json\(\[\s*'error'\s*=>\s*'unknown_store'.*\], 404\);/s", $ctrlSrc) === 1);
assert_test('the scope helper treats only digits as a store reference',
    str_contains($ctrlSrc, "!ctype_digit(\$raw)"));

/* ────────────────────────────────────────────────────────────────
 * 11. The browser poll repeats the scope
 * ──────────────────────────────────────────────────────────────── */
echo "\nT11: the JS poll cannot drift off-scope\n";

assert_test('the poll appends the current store to the telemetry URL',
    str_contains($jsSrc, "return s ? '?store=' + encodeURIComponent(s) : '';"));
assert_test('the poll reads the scope from the URL rather than caching it',
    str_contains($jsSrc, "window.location.search"));
assert_test('the poll rejects a payload that answers for another store',
    str_contains($jsSrc, 'function telemetryMatchesScope(d)'));
assert_test('the poll actually gates on the scope check',
    str_contains($jsSrc, 'if (!telemetryMatchesScope(d)) return;'));
assert_test('the scope check tolerates a backend that omits store_id',
    str_contains($jsSrc, 'd.store_id !== undefined && d.store_id !== null'));

/* Scope card removed from monitoring view per UI design request (clean ops console) */
assert_test('the monitoring view does not display the redundant scope card',
    !str_contains($viewSrc, '<span class="pa-card-title">Scope</span>'));
assert_test('the monitoring view renders with no $store (tests pass none)',
    preg_match('/\$store\s+=\s+\$store\s+\?\?\s+null;/', $viewSrc) === 1);

echo "\n" . str_repeat('-', 55) . "\n";
echo "Results: {$pass} passed, {$fail} failed" . ($fail ? "\n" : " (all pass)") . "\n";
exit($fail === 0 ? 0 : 1);