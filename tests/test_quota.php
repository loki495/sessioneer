<?php
declare(strict_types=1);

/**
 * QuotaService::quota_from_statusline_state()/get_quota() - pure, no tmux
 * involved, so this stays a fast isolated unit test focused on malformed-
 * input edge cases; the real end-to-end flow (a real statusline-state row
 * written, read back, combined with a live session's own context-window
 * percentage via a real tmux pane) is covered in test_sessions_lifecycle.php
 * instead, alongside everything else that needs a real tmux session anyway.
 *
 * An earlier version of this file tested a whole cache/background-refresh
 * subsystem (QuotaService::run_claude_quota()/read_quota_cache()/
 * trigger_background_quota_refresh(), an external `claude-quota` binary
 * scrape) - deleted as confirmed dead code once
 * quota_from_statusline_state() became the only source get_quota() ever
 * reads from (see QuotaService's own class docblock for why). See git
 * history if any of that ever needs resurrecting.
 *
 * quota_from_statusline_state() reads GlobalStateStore's `global_state`
 * table (Config::push_sqlite_path()), not a plain file -
 * see write_quota_live_state()/delete_quota_live_state() below for the
 * fixture-side equivalent of a real write_quota_live_state.php run.
 */

require __DIR__ . '/lib/assert.php';
require dirname(__DIR__) . '/host-agent/lib/Sessions.php';

use HostAgent\Services\Config;
use HostAgent\Services\QuotaService;
use HostAgent\Stores\GlobalStateStore;
use HostAgent\Stores\SqliteDb;

use HostAgent\Stores\SidecarStore;

$realPushSqliteFile = Config::push_sqlite_path();
$realSessionsSqliteFile = Config::sessions_sqlite_path();

$pushSqliteFixture = sys_get_temp_dir() . '/sessioneer-test-quota-live-' . bin2hex(random_bytes(4)) . '/push.sqlite';
$sessionsSqliteFixture = sys_get_temp_dir() . '/sessioneer-test-quota-sessions-' . bin2hex(random_bytes(4)) . '/sessions.sqlite';
$opencodeDbFixture = sys_get_temp_dir() . '/sessioneer-test-quota-opencode-' . bin2hex(random_bytes(4)) . '/opencode.db';
$opencodeAuthFixture = sys_get_temp_dir() . '/sessioneer-test-quota-opencode-' . bin2hex(random_bytes(4)) . '/auth.json';
putenv("PUSH_SQLITE_FILE={$pushSqliteFixture}");
putenv("SESSIONS_SQLITE_FILE={$sessionsSqliteFixture}");
putenv("OPENCODE_DB_PATH={$opencodeDbFixture}");
putenv("OPENCODE_AUTH_PATH={$opencodeAuthFixture}");
putenv('OPENCODE_GO_API_KEY=');

if (Config::push_sqlite_path() === $realPushSqliteFile || Config::sessions_sqlite_path() === $realSessionsSqliteFile) {
    fwrite(STDERR, "REFUSING TO RUN: PUSH_SQLITE_FILE or SESSIONS_SQLITE_FILE resolves to real host state file. Check tests/.env.testing.\n");
    exit(1);
}

/**
 * @param array<string, mixed> $value
 */
function write_quota_live_state(array $value): void
{
    GlobalStateStore::write(Config::quota_live_state_key(), $value);
}

function delete_quota_live_state(): void
{
    GlobalStateStore::delete(Config::quota_live_state_key());
}

/**
 * @param array<string, mixed> $value
 */
function write_antigravity_quota_live_state(array $value): void
{
    GlobalStateStore::write(Config::antigravity_quota_live_state_key(), $value);
}

function delete_antigravity_quota_live_state(): void
{
    GlobalStateStore::delete(Config::antigravity_quota_live_state_key());
}

/**
 * The one case a plain GlobalStateStore::write() can't simulate - a row
 * whose value_json is genuinely malformed (GlobalStateStore::write()
 * always json_encode()s a real PHP value, which can never produce broken
 * JSON) - so this inserts it directly, the fixture-side equivalent of
 * disk corruption or a crashed write mid-file for the old plain-file
 * version of this state.
 */
function write_malformed_quota_live_state(): void
{
    $pdo = SqliteDb::connect(Config::push_sqlite_path(), SqliteDb::push_schema());
    $stmt = $pdo->prepare(
        'INSERT INTO global_state (key, value_json, updated_at) VALUES (:key, :value_json, :updated_at)
         ON CONFLICT(key) DO UPDATE SET value_json = excluded.value_json, updated_at = excluded.updated_at'
    );
    $stmt->execute([':key' => Config::quota_live_state_key(), ':value_json' => '{not valid json', ':updated_at' => time()]);
}

try {
    // --- QuotaService::quota_from_statusline_state(): sad paths - malformed/incomplete state never crashes, just null or a partial read ---

    assert_equal(null, QuotaService::quota_from_statusline_state(), 'quota_from_statusline_state: null when no row exists at all');

    write_malformed_quota_live_state();
    assert_equal(null, QuotaService::quota_from_statusline_state(), 'quota_from_statusline_state: null on malformed JSON');

    write_quota_live_state(['session' => ['pct' => 50, 'resets_at' => time()]]);
    assert_equal(null, QuotaService::quota_from_statusline_state(), 'quota_from_statusline_state: null when captured_at is missing');

    write_quota_live_state(['captured_at' => 'not-an-int', 'session' => ['pct' => 50, 'resets_at' => time()]]);
    assert_equal(null, QuotaService::quota_from_statusline_state(), 'quota_from_statusline_state: null when captured_at is not an int');

    write_quota_live_state(['captured_at' => time()]);
    assert_equal(null, QuotaService::quota_from_statusline_state(), 'quota_from_statusline_state: null when neither session nor week_all is present at all');

    write_quota_live_state(['captured_at' => time(), 'session' => ['pct' => '50', 'resets_at' => time()]]);
    assert_equal(null, QuotaService::quota_from_statusline_state(), 'quota_from_statusline_state: a bucket with pct as a string, not an int, is treated as absent, not coerced');

    write_quota_live_state(['captured_at' => time(), 'session' => ['pct' => 50]]);
    assert_equal(null, QuotaService::quota_from_statusline_state(), 'quota_from_statusline_state: a bucket missing resets_at is treated as absent - still null overall if it was the only bucket present');

    // --- only ONE of the two buckets present - still a valid partial read, not all-or-nothing ---

    $fetchedAt = time();
    write_quota_live_state(['captured_at' => $fetchedAt, 'session' => ['pct' => 50, 'resets_at' => $fetchedAt + 3600]]);
    $sessionOnly = QuotaService::quota_from_statusline_state();
    assert_true($sessionOnly !== null, 'quota_from_statusline_state: non-null when only one bucket (session) is present and valid');
    assert_equal(50, $sessionOnly['quota']['session']['pct'] ?? null, 'quota_from_statusline_state: the present bucket round-trips');
    assert_true(!isset($sessionOnly['quota']['week_all']), 'quota_from_statusline_state: the absent bucket (week_all) is not fabricated');

    // --- happy path: both buckets present and valid ---

    write_quota_live_state([
        'captured_at' => $fetchedAt,
        'session' => ['pct' => 70, 'resets_at' => $fetchedAt + 3600],
        'week_all' => ['pct' => 60, 'resets_at' => $fetchedAt + 86400],
    ]);
    $both = QuotaService::quota_from_statusline_state();
    assert_equal(70, $both['quota']['session']['pct'] ?? null, 'quota_from_statusline_state: session pct');
    assert_equal($fetchedAt + 3600, $both['quota']['session']['resets_at'] ?? null, 'quota_from_statusline_state: session resets_at is the real epoch from the row, untouched');
    assert_equal(60, $both['quota']['week_all']['pct'] ?? null, 'quota_from_statusline_state: week_all pct');
    assert_equal($fetchedAt, $both['fetched_at'] ?? null, 'quota_from_statusline_state: fetched_at is captured_at from the row');

    // --- QuotaService::antigravity_quota_state(): Antigravity quota reading ---

    assert_equal(null, QuotaService::antigravity_quota_state(), 'antigravity_quota_state: null when no row exists');

    write_antigravity_quota_live_state([
        'captured_at' => $fetchedAt,
        'gemini-weekly' => ['pct' => 25, 'resets_at' => $fetchedAt + 5 * 86400, 'group_name' => 'Gemini Models'],
        '3p-weekly' => ['pct' => 0, 'resets_at' => $fetchedAt + 5 * 86400, 'group_name' => 'Claude and GPT models'],
    ]);
    $agState = QuotaService::antigravity_quota_state();
    assert_true($agState !== null, 'antigravity_quota_state: non-null when valid data exists');
    assert_equal(25, $agState['quota']['gemini-weekly']['pct'] ?? null, 'antigravity_quota_state: gemini-weekly pct');
    assert_equal('Gemini Models', $agState['quota']['gemini-weekly']['group_name'] ?? null, 'antigravity_quota_state: gemini group_name preserved');
    assert_equal(0, $agState['quota']['3p-weekly']['pct'] ?? null, 'antigravity_quota_state: 3p-weekly pct');
    assert_equal('Claude and GPT models', $agState['quota']['3p-weekly']['group_name'] ?? null, 'antigravity_quota_state: 3p group_name preserved');

    // --- QuotaService::get_quota(): per-agent session and dashboard responses ---

    delete_quota_live_state();
    delete_antigravity_quota_live_state();
    $noData = QuotaService::get_quota();
    assert_equal(false, $noData['ok'] ?? null, 'get_quota(): ok=false with no quota data at all yet');
    assert_equal(null, $noData['quota'], 'get_quota(): quota is null with no data yet');
    assert_equal(false, $noData['cached'] ?? null, 'get_quota(): cached is always false now (no cache mechanism left)');
    assert_equal(false, $noData['refreshing'] ?? null, 'get_quota(): refreshing is always false now (no background-refresh mechanism left)');
    assert_true(($noData['message'] ?? '') !== '', 'get_quota(): a "no data yet" message is included when ok=false');

    // Set up sidecars for sessions
    SidecarStore::write_sidecar('test-claude-sess', ['agent' => 'claude', 'workdir' => '/tmp']);
    SidecarStore::write_sidecar('test-agy-sess', ['agent' => 'antigravity', 'workdir' => '/tmp']);

    // Claude Code session with Claude Code quota present
    write_quota_live_state([
        'captured_at' => $fetchedAt,
        'session' => ['pct' => 70, 'resets_at' => $fetchedAt + 3600],
        'week_all' => ['pct' => 60, 'resets_at' => $fetchedAt + 86400],
    ]);
    $ccSessionQuota = QuotaService::get_quota('test-claude-sess');
    assert_equal(true, $ccSessionQuota['ok'] ?? null, 'get_quota(cc-session): ok=true');
    assert_equal('claude', $ccSessionQuota['agent'] ?? null, 'get_quota(cc-session): agent is claude');
    assert_equal('Claude Code', $ccSessionQuota['agent_label'] ?? null, 'get_quota(cc-session): agent_label is Claude Code');
    assert_equal(70, $ccSessionQuota['quota']['session']['pct'] ?? null, 'get_quota(cc-session): session pct');
    assert_true(isset($ccSessionQuota['agents']), 'get_quota(cc-session): now includes agents map (NEW)');
    assert_true(isset($ccSessionQuota['agents']['claude']), 'get_quota(cc-session): agents.claude present (NEW)');
    assert_equal(70, $ccSessionQuota['agents']['claude']['quota']['session']['pct'] ?? null, 'get_quota(cc-session): agents.claude.quota mirrors session pct (NEW)');

    // Antigravity session with Antigravity quota present
    write_antigravity_quota_live_state([
        'captured_at' => $fetchedAt,
        'gemini-weekly' => ['pct' => 25, 'resets_at' => $fetchedAt + 5 * 86400, 'group_name' => 'Gemini Models'],
        '3p-weekly' => ['pct' => 0, 'resets_at' => $fetchedAt + 5 * 86400, 'group_name' => 'Claude and GPT models'],
    ]);
    $agSessionQuota = QuotaService::get_quota('test-agy-sess');
    assert_equal(true, $agSessionQuota['ok'] ?? null, 'get_quota(agy-session): ok=true');
    assert_equal('antigravity', $agSessionQuota['agent'] ?? null, 'get_quota(agy-session): agent is antigravity');
    assert_equal('Antigravity', $agSessionQuota['agent_label'] ?? null, 'get_quota(agy-session): agent_label is Antigravity');
    assert_equal(25, $agSessionQuota['quota']['gemini-weekly']['pct'] ?? null, 'get_quota(agy-session): gemini-weekly pct');
    assert_equal(0, $agSessionQuota['quota']['3p-weekly']['pct'] ?? null, 'get_quota(agy-session): 3p-weekly pct');
    assert_true(isset($agSessionQuota['agents']), 'get_quota(agy-session): now includes agents map (NEW)');
    assert_true(isset($agSessionQuota['agents']['antigravity']), 'get_quota(agy-session): agents.antigravity present (NEW)');
    assert_true(!isset($agSessionQuota['context']), 'get_quota(agy-session): no context key (only for Claude) (NEW)');

    // Dashboard (no session given): returns both agents in `agents` map
    $dashQuota = QuotaService::get_quota();
    assert_equal(true, $dashQuota['ok'] ?? null, 'get_quota(dashboard): ok=true when at least one agent has quota');
    assert_true(isset($dashQuota['agents']['claude']), 'get_quota(dashboard): contains agents.claude');
    assert_true(isset($dashQuota['agents']['antigravity']), 'get_quota(dashboard): contains agents.antigravity');
    assert_equal(70, $dashQuota['agents']['claude']['quota']['session']['pct'] ?? null, 'get_quota(dashboard): claude session pct');
    assert_equal(25, $dashQuota['agents']['antigravity']['quota']['gemini-weekly']['pct'] ?? null, 'get_quota(dashboard): antigravity gemini pct');

    // --- opencode cost/tokens: both storage generations, no double counting ---
    // OpenCode 2.x writes new sessions only to session_v2; the aggregate must
    // include them, and a session that exists in BOTH tables (migrated) must
    // count once.
    @mkdir(dirname($opencodeDbFixture), 0700, true);
    $ocPdo = new PDO('sqlite:' . $opencodeDbFixture);
    $ocPdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $ocPdo->exec('CREATE TABLE session (id TEXT PRIMARY KEY, cost REAL DEFAULT 0, tokens_input INTEGER DEFAULT 0, tokens_output INTEGER DEFAULT 0, tokens_reasoning INTEGER DEFAULT 0, tokens_cache_read INTEGER DEFAULT 0, tokens_cache_write INTEGER DEFAULT 0, time_updated INTEGER NOT NULL)');
    $ocPdo->exec('CREATE TABLE session_v2 (id TEXT PRIMARY KEY, cost REAL DEFAULT 0, tokens_input INTEGER DEFAULT 0, tokens_output INTEGER DEFAULT 0, tokens_reasoning INTEGER DEFAULT 0, tokens_cache_read INTEGER DEFAULT 0, tokens_cache_write INTEGER DEFAULT 0, time_updated INTEGER NOT NULL)');
    $insLegacy = $ocPdo->prepare('INSERT INTO session (id, cost, tokens_input, tokens_output, time_updated) VALUES (?, ?, ?, ?, ?)');
    $insV2 = $ocPdo->prepare('INSERT INTO session_v2 (id, cost, tokens_input, tokens_output, time_updated) VALUES (?, ?, ?, ?, ?)');
    $insLegacy->execute(['ses_migrated01', 0.25, 100, 10, 1700000000000]);
    $insV2->execute(['ses_migrated01', 0.25, 100, 10, 1700000000000]);
    $insV2->execute(['ses_v2only0001', 0.50, 200, 20, 1700000100000]);
    $ocPdo = null;

    $ocV2Only = QuotaService::opencode_quota_state('ses_v2only0001');
    assert_equal(0.5, $ocV2Only['quota']['cost'] ?? null, 'opencode_quota_state: a session_v2-only session resolves (2.x storage)');
    assert_equal(200, $ocV2Only['quota']['tokens_input'] ?? null, 'opencode_quota_state: v2 token counters are read from session_v2');
    assert_equal(1700000100, $ocV2Only['fetched_at'] ?? null, 'opencode_quota_state: fetched_at is the row time_updated in seconds (v2)');

    $ocLegacy = QuotaService::opencode_quota_state('ses_migrated01');
    assert_equal(0.25, $ocLegacy['quota']['cost'] ?? null, 'opencode_quota_state: a migrated session still resolves via the legacy row');

    assert_equal(null, QuotaService::opencode_quota_state('ses_nothere001'), 'opencode_quota_state: unknown session → null, not a fabricated zero row');

    $ocDash = QuotaService::opencode_quota_state();
    assert_equal(2, $ocDash['quota']['session_count'] ?? null, 'opencode_quota_state(dashboard): counts both tables once each (migrated row not double counted)');
    assert_equal(0.75, $ocDash['quota']['cost'] ?? null, 'opencode_quota_state(dashboard): cost sums both generations');
    assert_equal(300, $ocDash['quota']['tokens_input'] ?? null, 'opencode_quota_state(dashboard): token counters sum both generations');
} finally {
    @unlink($pushSqliteFixture);
    @unlink($pushSqliteFixture . '-wal');
    @unlink($pushSqliteFixture . '-shm');
    @unlink($sessionsSqliteFixture);
    @unlink($sessionsSqliteFixture . '-wal');
    @unlink($sessionsSqliteFixture . '-shm');
    @unlink($opencodeDbFixture);
    @rmdir(dirname($opencodeDbFixture));
    @unlink($opencodeAuthFixture);
    @rmdir(dirname($opencodeAuthFixture));
}
