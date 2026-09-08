<?php
declare(strict_types=1);

/**
 * Exercises HostAgent\Services\OpenCodeTranscriptService — the SQLite-backed
 * transcript reader for OpenCode TUI sessions (see .ai/QUESTIONS.md Q1.4 for
 * live-verified storage details). Mirrors test_antigravity_transcript.php's
 * own fixture/pagination pattern but against a canned opencode.db SQLite
 * fixture rather than JSONL, so no live opencode process is needed.
 */

require __DIR__ . '/lib/assert.php';
require dirname(__DIR__) . '/host-agent/lib/Sessions.php';

use HostAgent\Services\Config;
use HostAgent\Services\OpenCodeTranscriptService;
use HostAgent\Services\TranscriptRouter;
use HostAgent\Services\TranscriptService;

$realOpencodeDbPath = Config::opencode_db_path();

// Build a canned opencode.db SQLite fixture in tmp, pointed at via
// OPENCODE_DB_PATH — same env-override pattern as tests/.env.testing
// for TMUX_SOCKET/CLAUDE_BIN etc.
$fixtureDir = sys_get_temp_dir() . '/sessioneer-test-opencode-transcript-' . bin2hex(random_bytes(4));
@mkdir($fixtureDir, 0700, true);
$fixtureDbPath = $fixtureDir . '/opencode.db';
putenv("OPENCODE_DB_PATH={$fixtureDbPath}");

if (Config::opencode_db_path() === $realOpencodeDbPath) {
    fwrite(STDERR, "REFUSING TO RUN: OPENCODE_DB_PATH resolves to the real host DB.\n");
    exit(1);
}

$pdo = new PDO('sqlite:' . $fixtureDbPath);
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$pdo->exec('CREATE TABLE session (id TEXT PRIMARY KEY, slug TEXT NOT NULL, directory TEXT NOT NULL, title TEXT NOT NULL, project_id TEXT NOT NULL, agent TEXT, model TEXT, version TEXT NOT NULL, time_created INTEGER NOT NULL, time_updated INTEGER NOT NULL, tokens_input INTEGER DEFAULT 0, tokens_output INTEGER DEFAULT 0, tokens_reasoning INTEGER DEFAULT 0, tokens_cache_read INTEGER DEFAULT 0, tokens_cache_write INTEGER DEFAULT 0, cost REAL DEFAULT 0)');
$pdo->exec('CREATE TABLE message (id TEXT PRIMARY KEY, session_id TEXT NOT NULL, time_created INTEGER NOT NULL, time_updated INTEGER NOT NULL, data TEXT NOT NULL)');
$pdo->exec('CREATE TABLE part (id TEXT PRIMARY KEY, message_id TEXT NOT NULL, session_id TEXT NOT NULL, time_created INTEGER NOT NULL, time_updated INTEGER NOT NULL, data TEXT NOT NULL)');

$sessionId = 'ses_testopencode01';
$missingSessionId = 'ses_missing00000';
$badShapeId = 'not-opencode-id';

function insert_session(PDO $pdo, string $id, string $title = 'Test'): void
{
    $stmt = $pdo->prepare('INSERT INTO session (id, slug, directory, title, project_id, agent, model, version, time_created, time_updated) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)');
    $stmt->execute([$id, 'test-slug', '/tmp', $title, 'proj1', 'build', 'opencode/test', '1.18.21', 1000, 2000]);
}

function insert_message(PDO $pdo, string $msgId, string $sessionId, int $timeCreated, array $data): void
{
    $stmt = $pdo->prepare('INSERT INTO message (id, session_id, time_created, time_updated, data) VALUES (?, ?, ?, ?, ?)');
    $stmt->execute([$msgId, $sessionId, $timeCreated, $timeCreated, json_encode($data)]);
}

function insert_part(PDO $pdo, string $partId, string $msgId, string $sessionId, int $timeCreated, array $data): void
{
    $stmt = $pdo->prepare('INSERT INTO part (id, message_id, session_id, time_created, time_updated, data) VALUES (?, ?, ?, ?, ?, ?)');
    $stmt->execute([$partId, $msgId, $sessionId, $timeCreated, $timeCreated, json_encode($data)]);
}

try {
    // --- Setup: one session with 5 messages ---
    // msg1: user text, 1 text part
    // msg2: assistant text, 1 text part
    // msg3: assistant with tool call (one tool part: input + output)
    // msg5: user text (newer)
    insert_session($pdo, $sessionId, 'Opencode test session');
    // msg1
    insert_message($pdo, 'msg_001', $sessionId, 1001, ['role' => 'user', 'time' => ['created' => 1001000]]);
    insert_part($pdo, 'prt_001', 'msg_001', $sessionId, 1001, ['type' => 'text', 'text' => 'Hello opencode']);
    // msg2
    insert_message($pdo, 'msg_002', $sessionId, 1002, ['role' => 'assistant', 'time' => ['created' => 1002000]]);
    insert_part($pdo, 'prt_002', 'msg_002', $sessionId, 1002, ['type' => 'text', 'text' => 'Hi, how can I help?']);
    // msg3: assistant with tool
    insert_message($pdo, 'msg_003', $sessionId, 1003, ['role' => 'assistant', 'time' => ['created' => 1003000]]);
    insert_part($pdo, 'prt_003', 'msg_003', $sessionId, 1003, ['type' => 'tool', 'tool' => 'write', 'callID' => 'call_1', 'state' => ['status' => 'completed', 'input' => ['filePath' => '/tmp/foo.txt'], 'output' => 'Wrote file successfully']]);
    // msg4: filtered out (step-start only), inserted before the newest renderable message
    insert_message($pdo, 'msg_004', $sessionId, 1004, ['role' => 'assistant', 'time' => ['created' => 1004000]]);
    insert_part($pdo, 'prt_004a', 'msg_004', $sessionId, 1004, ['type' => 'step-start']);
    // msg5
    insert_message($pdo, 'msg_005', $sessionId, 1005, ['role' => 'user', 'time' => ['created' => 1005000]]);
    insert_part($pdo, 'prt_005', 'msg_005', $sessionId, 1005, ['type' => 'text', 'text' => 'Thanks!']);

    // Also add a message with synthetic text (should be skipped) and step-start (skipped)
    insert_message($pdo, 'msg_006', $sessionId, 1006, ['role' => 'assistant', 'time' => ['created' => 1006000]]);
    insert_part($pdo, 'prt_006a', 'msg_006', $sessionId, 1006, ['type' => 'step-start']);
    insert_part($pdo, 'prt_006b', 'msg_006', $sessionId, 1006, ['type' => 'text', 'synthetic' => true, 'text' => 'Called the Read tool with {"filePath":"x"}']);
    // This message should be filtered out (no renderable blocks), so it should not appear in transcript
    insert_message($pdo, 'msg_007', $sessionId, 1007, ['role' => 'assistant', 'time' => ['created' => 1007000]]);
    insert_part($pdo, 'prt_007a', 'msg_007', $sessionId, 1007, ['type' => 'reasoning', 'text' => 'internal reasoning']);

    // --- find_transcript_path ---
    assert_equal($sessionId, OpenCodeTranscriptService::find_transcript_path($sessionId), 'find_transcript_path: valid ses_* with fixture session row → id itself');
    assert_equal(null, OpenCodeTranscriptService::find_transcript_path($missingSessionId), 'find_transcript_path: ses_* with no session row → null');
    assert_equal(null, OpenCodeTranscriptService::find_transcript_path($badShapeId), 'find_transcript_path: non-ses_* shape → null immediately, no DB query');
    assert_equal(null, OpenCodeTranscriptService::find_transcript_path(''), 'find_transcript_path: empty string → null');
    assert_equal(null, OpenCodeTranscriptService::find_transcript_path('ses_'), 'find_transcript_path: bare prefix without alphanumeric → null');

    // --- is_opencode_id ---
    assert_true(OpenCodeTranscriptService::is_opencode_id($sessionId), 'is_opencode_id: ses_* → true');
    assert_true(!OpenCodeTranscriptService::is_opencode_id('12345678-1234-4234-8234-123456789abc'), 'is_opencode_id: UUID shape → false');
    assert_true(!OpenCodeTranscriptService::is_opencode_id(''), 'is_opencode_id: empty → false');

    // --- TranscriptRouter integration ---
    assert_equal($sessionId, TranscriptRouter::find_transcript_path($sessionId), 'TranscriptRouter::find_transcript_path: opencode ses_* resolves via OpenCodeTranscriptService');
    assert_true(TranscriptRouter::is_opencode_path($sessionId), 'TranscriptRouter::is_opencode_path: ses_* → true');
    assert_true(!TranscriptRouter::is_opencode_path('12345678-1234-4234-8234-123456789abc'), 'TranscriptRouter::is_opencode_path: UUID → false');
    assert_true(!TranscriptRouter::is_antigravity_path($sessionId), 'TranscriptRouter::is_antigravity_path: ses_* is not antigravity path');

    // --- read_transcript_page: full (before=null) ---
    $page = OpenCodeTranscriptService::read_transcript_page($sessionId, null, 10);
    assert_true($page['ok'] ?? false, 'read_transcript_page: ok=true for valid session');
    // msg_004, msg_006 and msg_007 are filtered (no renderable blocks), so only msg_001..003 and msg_005 render → 4 entries
    assert_equal(4, count($page['entries'] ?? []), 'read_transcript_page: 4 renderable entries (3 filtered messages skipped)');
    assert_equal(false, $page['has_more'] ?? true, 'read_transcript_page: has_more=false when all entries fit');
    assert_equal(null, $page['next_before'], 'read_transcript_page: next_before=null when all entries fit');

    // Verify entry shapes
    $first = $page['entries'][0] ?? [];
    assert_equal('USER_INPUT', $first['type'] ?? null, 'first entry type is USER_INPUT');
    assert_equal('user', $first['role'] ?? null, 'first entry role is user');
    assert_equal(1, count($first['blocks'] ?? []), 'first entry has 1 text block');
    assert_equal('text', $first['blocks'][0]['kind'] ?? null, 'first block kind is text');
    assert_true(str_contains($first['blocks'][0]['text'] ?? '', 'Hello opencode'), 'first block text contains user prompt');

    $third = $page['entries'][2] ?? [];
    assert_equal('PLANNER_RESPONSE', $third['type'] ?? null, 'third entry type is PLANNER_RESPONSE');
    assert_equal('assistant', $third['role'] ?? null, 'third entry role is assistant');
    // msg_003 has one tool part → 2 blocks (tool_use + tool_result)
    assert_equal(2, count($third['blocks'] ?? []), 'tool message has 2 blocks (tool_use + tool_result)');
    assert_equal('tool_use', $third['blocks'][0]['kind'] ?? null, 'tool block 0 kind is tool_use');
    assert_true(str_contains($third['blocks'][0]['text'] ?? '', 'write'), 'tool_use text contains tool name');
    assert_equal('tool_result', $third['blocks'][1]['kind'] ?? null, 'tool block 1 kind is tool_result');
    assert_true(str_contains($third['blocks'][1]['text'] ?? '', 'Wrote file'), 'tool_result text contains output');

    // --- read_transcript_page: pagination (before) ---
    $pageLimited = OpenCodeTranscriptService::read_transcript_page($sessionId, null, 2);
    assert_equal(2, count($pageLimited['entries'] ?? []), 'read_transcript_page limit=2: returns 2 newest entries');
    assert_true($pageLimited['has_more'] ?? false, 'limit=2 has_more=true when more entries exist');
    assert_true($pageLimited['next_before'] !== null, 'limit=2 next_before is set');

    $nextPage = OpenCodeTranscriptService::read_transcript_page($sessionId, $pageLimited['next_before'], 2);
    assert_equal(2, count($nextPage['entries'] ?? []), 'read_transcript_page next_before: returns next 2 (the earlier ones)');
    assert_equal(false, $nextPage['has_more'] ?? true, 'second page has_more=false when at start');
    assert_equal(null, $nextPage['next_before'], 'second page next_before=null at start');

    // Verify pages cover all entries with no overlap/gap
    $allLines = array_column($page['entries'], 'line');
    $page1Lines = array_column($pageLimited['entries'], 'line');
    $page2Lines = array_column($nextPage['entries'], 'line');
    sort($page1Lines);
    sort($page2Lines);
    $combinedLines = array_merge($page1Lines, $page2Lines);
    sort($combinedLines);
    assert_equal($allLines, $combinedLines, 'pagination: two pages of limit=2 cover the same 4 lines as limit=10 full read, no gap/overlap');

    // --- read_transcript_page: untilRealUserMessage ---
    $untilPage = OpenCodeTranscriptService::read_transcript_page($sessionId, null, 10, true);
    // From tail, walks until first user message (msg_005 is user, so should stop after including it)
    // msg_005 is user at line 5, so from tail backward: would find msg_005 as first user → stops
    assert_true(($untilPage['ok'] ?? false), 'read_transcript_page untilRealUserMessage: ok=true');
    assert_true(count($untilPage['entries'] ?? []) >= 1, 'untilRealUserMessage: at least 1 entry (the tail user message)');
    $lastUntil = end($untilPage['entries']);
    assert_equal('user', $lastUntil['role'] ?? null, 'untilRealUserMessage: newest entry in result is user (the one that stopped the walk)');

    // --- read_transcript_page_since: forward poll ---
    // line is 1-indexed message position; afterLine=0 means "after nothing" → first 2 entries
    $since0 = OpenCodeTranscriptService::read_transcript_page_since($sessionId, 0, 2);
    assert_true($since0['ok'] ?? false, 'read_transcript_page_since after=0: ok=true');
    assert_equal(2, count($since0['entries'] ?? []), 'after=0 limit=2: returns first 2 entries');
    assert_equal(1, $since0['entries'][0]['line'] ?? null, 'after=0: first entry line is 1');
    assert_equal(2, $since0['entries'][1]['line'] ?? null, 'after=0: second entry line is 2');

    $since2 = OpenCodeTranscriptService::read_transcript_page_since($sessionId, 2, 2);
    assert_equal(2, count($since2['entries'] ?? []), 'after=2 limit=2: returns next 2 entries (lines 3,5)');
    assert_equal(3, $since2['entries'][0]['line'] ?? null, 'after=2: first entry line is 3');
    assert_equal(5, $since2['entries'][1]['line'] ?? null, 'after=2: second entry line is 5');

    $since4 = OpenCodeTranscriptService::read_transcript_page_since($sessionId, 4, 2);
    assert_equal(1, count($since4['entries'] ?? []), 'after=4: returns the later renderable entry after the filtered row (old code returned 0)');
    assert_equal(5, $since4['entries'][0]['line'] ?? null, 'after=4: new entry line is 5');

    $sinceLarge = OpenCodeTranscriptService::read_transcript_page_since($sessionId, 999, 2);
    assert_equal(0, count($sinceLarge['entries'] ?? []), 'after=999 (far past end): returns 0 entries');

    // --- read_transcript_page on missing/empty session ---
    $missingPage = OpenCodeTranscriptService::read_transcript_page($missingSessionId, null, 10);
    assert_true($missingPage['ok'] ?? false, 'read_transcript_page on ses_* with no rows: ok=true but 0 entries (session exists check is only in find_transcript_path, not here)');
    assert_equal(0, count($missingPage['entries'] ?? []), 'missing session: 0 entries');

    $badIdPage = OpenCodeTranscriptService::read_transcript_page($badShapeId, null, 10);
    assert_equal(false, $badIdPage['ok'] ?? true, 'read_transcript_page on non-ses_* id: ok=false (shape guard)');

    $emptySessionId = '';
    $emptyPage = OpenCodeTranscriptService::read_transcript_page($emptySessionId, null, 10);
    assert_equal(false, $emptyPage['ok'] ?? true, 'read_transcript_page on empty id: ok=false');

    // --- TranscriptRouter dispatch for opencode ---
    $routerPage = TranscriptRouter::read_transcript_page($sessionId, null, 10);
    assert_true($routerPage['ok'] ?? false, 'TranscriptRouter::read_transcript_page for ses_* routes to OpenCodeTranscriptService');
    assert_equal(4, count($routerPage['entries'] ?? []), 'router page for opencode: same 4 entries as direct call');

    $routerSince = TranscriptRouter::read_transcript_page_since($sessionId, 0, 2);
    assert_true($routerSince['ok'] ?? false, 'TranscriptRouter::read_transcript_page_since for ses_* routes correctly');
    assert_equal(2, count($routerSince['entries'] ?? []), 'router since for opencode: 2 entries');

    $routerBad = TranscriptRouter::read_transcript_page($badShapeId, null, 10);
    assert_equal(false, $routerBad['ok'] ?? true, 'TranscriptRouter for non-ses_* does not dispatch to opencode');

    // --- read_attachment stub ---
    $attach = OpenCodeTranscriptService::read_attachment($sessionId, 1, 'uuid');
    assert_equal(false, $attach['ok'] ?? true, 'read_attachment: ok=false (not yet supported)');
    $routerAttach = TranscriptRouter::read_attachment($sessionId, 1, 'uuid');
    assert_equal(false, $routerAttach['ok'] ?? true, 'TranscriptRouter::read_attachment for ses_* routes to opencode stub');

    // --- question tool renders as a question block (not raw JSON) ---
    $questionSessionId = 'ses_questiontest0';
    insert_session($pdo, $questionSessionId, 'Question test');
    insert_message($pdo, 'msg_q1', $questionSessionId, 3001, ['role' => 'assistant', 'time' => ['created' => 3001000]]);
    insert_part($pdo, 'prt_q1', 'msg_q1', $questionSessionId, 3001, [
        'type' => 'tool', 'tool' => 'question', 'callID' => 'call_q1',
        'state' => [
            'status' => 'running',
            'input' => ['questions' => [[
                'header' => 'Deploy',
                'question' => 'Which environment?',
                'options' => [['label' => 'Staging'], ['label' => 'Production']],
            ]]],
        ],
    ]);
    $qPage = OpenCodeTranscriptService::read_transcript_page($questionSessionId, null, 10);
    assert_equal(1, count($qPage['entries'] ?? []), 'question session: 1 entry');
    $qBlock = $qPage['entries'][0]['blocks'][0] ?? [];
    assert_equal('question', $qBlock['kind'] ?? null, 'question tool block kind is question');
    assert_equal('Which environment?', $qBlock['question'] ?? null, 'question block carries the question text');
    assert_equal('Deploy', $qBlock['header'] ?? null, 'question block carries the header');
    assert_equal('Which environment?', $qBlock['questions'][0]['question'] ?? null, 'question block retains structured questions for interactive rendering');
    assert_true(($qBlock['pending'] ?? false), 'question block is pending while status=running');
    assert_equal(3, count($qBlock['options'] ?? []), 'question block carries the 2 options plus a "Type something" free-text option');
    assert_equal('Staging', $qBlock['options'][0]['label'] ?? null, 'question option 0 label');
    assert_equal('Type something', $qBlock['options'][2]['label'] ?? null, 'question block surfaces the free-text option');

    // --- completed question: the chosen answer is extracted from opencode's
    // "User has answered your questions: ..." wrapper, not shown verbatim ---
    $doneSessionId = 'ses_questiondone0';
    insert_session($pdo, $doneSessionId, 'Done question test');
    insert_message($pdo, 'msg_dq1', $doneSessionId, 4001, ['role' => 'assistant', 'time' => ['created' => 4001000]]);
    insert_part($pdo, 'prt_dq1', 'msg_dq1', $doneSessionId, 4001, [
        'type' => 'tool', 'tool' => 'question', 'callID' => 'call_dq1',
        'state' => [
            'status' => 'completed',
            'input' => ['questions' => [[
                'question' => 'Pick a color?',
                'options' => [['label' => 'Red'], ['label' => 'Blue']],
            ]]],
            'output' => 'User has answered your questions: "Pick a color?"="Blue"',
        ],
    ]);
    $donePage = OpenCodeTranscriptService::read_transcript_page($doneSessionId, null, 10);
    $doneBlock = $donePage['entries'][0]['blocks'][0] ?? [];
    assert_equal(false, ($doneBlock['pending'] ?? true), 'completed question block is not pending');
    assert_equal('Blue', $doneBlock['answer'] ?? null, 'completed question block extracts the answer from the opencode wrapper');

    // --- truncate: very long block is capped ---
    $longSessionId = 'ses_longtexttest0';
    insert_session($pdo, $longSessionId, 'Long text test');
    insert_message($pdo, 'msg_long', $longSessionId, 2000, ['role' => 'user', 'time' => ['created' => 2000000]]);
    $hugeText = str_repeat('A', TranscriptService::TRANSCRIPT_BLOCK_HARD_CAP_LENGTH + 100);
    insert_part($pdo, 'prt_long', 'msg_long', $longSessionId, 2000, ['type' => 'text', 'text' => $hugeText]);
    $longPage = OpenCodeTranscriptService::read_transcript_page($longSessionId, null, 10);
    assert_true(($longPage['ok'] ?? false), 'long text session: ok=true');
    $longBlock = $longPage['entries'][0]['blocks'][0] ?? [];
    assert_true(strlen($longBlock['text'] ?? '') <= TranscriptService::TRANSCRIPT_BLOCK_HARD_CAP_LENGTH + 20, 'long text block is capped via hard cap + truncation suffix');
    assert_true(str_contains($longBlock['text'] ?? '', '… (truncated)'), 'capped block ends with truncation suffix');

    // --- DB missing file returns error (find path returns null, but direct read also handles missing DB) ---
    putenv('OPENCODE_DB_PATH=/tmp/nonexistent-opencode-' . bin2hex(random_bytes(8)) . '.db');
    $missingDbFind = OpenCodeTranscriptService::find_transcript_path($sessionId);
    assert_equal(null, $missingDbFind, 'find_transcript_path with nonexistent DB file → null');
    $missingDbPage = OpenCodeTranscriptService::read_transcript_page($sessionId, null, 10);
    assert_equal(false, $missingDbPage['ok'] ?? true, 'read_transcript_page with nonexistent DB → ok=false');

    // Restore fixture DB path for cleanup assertions
    putenv("OPENCODE_DB_PATH={$fixtureDbPath}");

} finally {
    putenv('OPENCODE_DB_PATH');
    @unlink($fixtureDbPath);
    @rmdir($fixtureDir);
}

test_exit();
