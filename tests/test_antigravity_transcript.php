<?php
declare(strict_types=1);

/**
 * Exercises AntigravityTranscriptService (parsing Antigravity's own
 * transcript_full.jsonl entry shapes into TranscriptView's canonical
 * {type, role, timestamp, blocks} form, plus pagination/incremental-poll
 * reading) and TranscriptRouter (dispatch to TranscriptService vs
 * AntigravityTranscriptService by path shape) - see
 * docs/history/antigravity-adapter-plan.md Phase 4. Entry shapes here are
 * modeled directly on real ones captured live 2026-08-24 (see that plan
 * doc's "Open questions" research), not guessed.
 */

require __DIR__ . '/lib/assert.php';
require dirname(__DIR__) . '/host-agent/lib/Sessions.php';

use HostAgent\Services\AntigravityTranscriptService;
use HostAgent\Services\Config;
use HostAgent\Services\TranscriptRouter;
use HostAgent\Services\TranscriptService;

$realHomeRoot = Config::home_root();

$fixtureHome = sys_get_temp_dir() . '/sessioneer-test-agy-transcript-home-' . bin2hex(random_bytes(4));
putenv("HOME_ROOT={$fixtureHome}");

if (Config::home_root() === $realHomeRoot) {
    fwrite(STDERR, "REFUSING TO RUN: HOME_ROOT still resolves to the real home directory.\n");
    exit(1);
}

$conversationId = 'a1b2c3d4-e5f6-4789-8abc-def012345678';
$transcriptDir = Config::home_root() . '/.gemini/antigravity-cli/brain/' . $conversationId . '/.system_generated/logs';
mkdir($transcriptDir, 0700, true);
$transcriptPath = $transcriptDir . '/transcript_full.jsonl';

// Real shapes, modeled on the live captures in docs/history/antigravity-adapter-plan.md.
$lines = [
    // 1: USER_INPUT - the wrapper tags must be stripped for display.
    json_encode(['step_index' => 0, 'source' => 'USER_EXPLICIT', 'type' => 'USER_INPUT', 'status' => 'DONE', 'created_at' => '2026-08-24T20:18:50Z', 'content' => "<USER_REQUEST>\nRun 'echo hi' in the shell\n</USER_REQUEST>\n<ADDITIONAL_METADATA>\nThe current local time is: 2026-08-24T13:18:50-07:00.\n</ADDITIONAL_METADATA>"]),
    // 2: CHECKPOINT - skipped entirely for v1.
    json_encode(['step_index' => 1, 'source' => 'SYSTEM', 'type' => 'CHECKPOINT', 'status' => 'DONE', 'created_at' => '2026-08-24T20:18:50Z', 'content' => '{{ CHECKPOINT 0 }}...']),
    // 3: PLANNER_RESPONSE, tool_calls only (content: null) - a real run_command call.
    json_encode(['step_index' => 2, 'source' => 'MODEL', 'type' => 'PLANNER_RESPONSE', 'status' => 'DONE', 'created_at' => '2026-08-24T20:18:52Z', 'thinking' => 'Planning the command...', 'tool_calls' => [['name' => 'run_command', 'args' => ['CommandLine' => 'echo hi', 'Cwd' => '/home/user/dev', 'toolAction' => 'Running echo command', 'toolSummary' => 'Run echo test']]], 'content' => null]),
    // 4: GENERIC tool result.
    json_encode(['step_index' => 3, 'source' => 'MODEL', 'type' => 'GENERIC', 'status' => 'DONE', 'created_at' => '2026-08-24T20:18:53Z', 'content' => "Created At: 2026-08-24T13:59:38-07:00\nCompleted At: 2026-08-24T14:00:10-07:00\n\nThe command exited with code 0.\nOutput:\nhi"]),
    // 5: PLANNER_RESPONSE, text only.
    json_encode(['step_index' => 4, 'source' => 'MODEL', 'type' => 'PLANNER_RESPONSE', 'status' => 'DONE', 'created_at' => '2026-08-24T20:18:54Z', 'content' => 'Done, it printed hi.']),
    // 6: an unrecognized/future type - skipped, not rendered as garbage.
    json_encode(['step_index' => 5, 'source' => 'SYSTEM', 'type' => 'SOME_FUTURE_TYPE', 'status' => 'DONE', 'created_at' => '2026-08-24T20:18:55Z', 'content' => 'whatever this is']),
    // 7: second real user turn, for pagination tests.
    json_encode(['step_index' => 6, 'source' => 'USER_EXPLICIT', 'type' => 'USER_INPUT', 'status' => 'DONE', 'created_at' => '2026-08-24T20:19:00Z', 'content' => "<USER_REQUEST>\nAnd now what's 2+2?\n</USER_REQUEST>"]),
    // 8: final reply, no wrapper tags at all - a shape not yet observed, should fall back to raw content trimmed.
    json_encode(['step_index' => 7, 'source' => 'MODEL', 'type' => 'PLANNER_RESPONSE', 'status' => 'DONE', 'created_at' => '2026-08-24T20:19:01Z', 'content' => '4']),
];
file_put_contents($transcriptPath, implode("\n", $lines) . "\n");

try {
    // --- AntigravityTranscriptService::find_transcript_path() ---

    assert_equal($transcriptPath, AntigravityTranscriptService::find_transcript_path($conversationId), 'find_transcript_path: resolves the real deterministic path for a valid UUID with a real file');
    assert_equal(null, AntigravityTranscriptService::find_transcript_path('not-a-uuid'), 'find_transcript_path: null for a non-UUID-shaped id, never touches the filesystem');
    assert_equal(null, AntigravityTranscriptService::find_transcript_path('99999999-9999-4999-8999-999999999999'), 'find_transcript_path: null for a real-shaped UUID with no actual transcript file');

    // --- parse_transcript_line(): each real shape ---

    $userInput = AntigravityTranscriptService::parse_transcript_line($lines[0]);
    assert_equal('USER_INPUT', $userInput['type'] ?? null, 'parse_transcript_line: USER_INPUT type preserved');
    assert_equal('user', $userInput['role'] ?? null, 'parse_transcript_line: USER_INPUT role is user');
    assert_equal("Run 'echo hi' in the shell", $userInput['blocks'][0]['text'] ?? null, 'parse_transcript_line: USER_INPUT strips the <USER_REQUEST> wrapper (and drops ADDITIONAL_METADATA/etc entirely)');
    assert_equal('text', $userInput['blocks'][0]['kind'] ?? null, 'parse_transcript_line: USER_INPUT block kind is text');

    assert_equal(null, AntigravityTranscriptService::parse_transcript_line($lines[1]), 'parse_transcript_line: CHECKPOINT is skipped entirely (v1 scope)');

    $toolCallEntry = AntigravityTranscriptService::parse_transcript_line($lines[2]);
    assert_equal('assistant', $toolCallEntry['role'] ?? null, 'parse_transcript_line: PLANNER_RESPONSE role is assistant');
    assert_equal(1, count($toolCallEntry['blocks']), 'parse_transcript_line: tool_calls-only PLANNER_RESPONSE (content:null) produces exactly one block, no empty text block');
    assert_equal('tool_use', $toolCallEntry['blocks'][0]['kind'] ?? null, 'parse_transcript_line: tool_calls become tool_use blocks');
    assert_equal('run_command: Run echo test', $toolCallEntry['blocks'][0]['text'] ?? null, 'parse_transcript_line: prefers toolSummary for the block text');
    assert_equal('Bash', $toolCallEntry['blocks'][0]['tool_name'] ?? null, 'parse_transcript_line: run_command maps to tool_name=Bash, matching TranscriptView\'s own Bash summary treatment');
    assert_equal('echo hi', $toolCallEntry['blocks'][0]['command'] ?? null, 'parse_transcript_line: the real CommandLine is preserved as the command field');
    assert_true(!isset($toolCallEntry['blocks'][0]['thinking']), 'parse_transcript_line: thinking is never turned into a rendered block, same convention as Claude Code');

    $toolResultEntry = AntigravityTranscriptService::parse_transcript_line($lines[3]);
    assert_equal('user', $toolResultEntry['role'] ?? null, 'parse_transcript_line: GENERIC/MODEL tool-result role is user, matching the tool_result-carries-role-user convention');
    assert_equal('tool_result', $toolResultEntry['blocks'][0]['kind'] ?? null, 'parse_transcript_line: GENERIC/MODEL becomes a tool_result block');
    assert_true(str_contains($toolResultEntry['blocks'][0]['text'], 'exited with code 0'), 'parse_transcript_line: the real formatted tool-result text is preserved');

    $textOnlyEntry = AntigravityTranscriptService::parse_transcript_line($lines[4]);
    assert_equal('text', $textOnlyEntry['blocks'][0]['kind'] ?? null, 'parse_transcript_line: a text-only PLANNER_RESPONSE produces a text block');
    assert_equal('Done, it printed hi.', $textOnlyEntry['blocks'][0]['text'] ?? null, 'parse_transcript_line: text content preserved verbatim');

    assert_equal(null, AntigravityTranscriptService::parse_transcript_line($lines[5]), 'parse_transcript_line: an unrecognized type is skipped, not rendered as garbage');

    $noWrapperEntry = AntigravityTranscriptService::parse_transcript_line(json_encode(['type' => 'USER_INPUT', 'source' => 'USER_EXPLICIT', 'content' => 'plain text, no wrapper tags at all']));
    assert_equal('plain text, no wrapper tags at all', $noWrapperEntry['blocks'][0]['text'] ?? null, 'parse_transcript_line: falls back to raw (trimmed) content when the <USER_REQUEST> wrapper is absent, rather than dropping the message');

    assert_equal(null, AntigravityTranscriptService::parse_transcript_line('not json at all'), 'parse_transcript_line: malformed JSON never crashes, just returns null');
    assert_equal(null, AntigravityTranscriptService::parse_transcript_line(json_encode(['type' => 'GENERIC', 'source' => 'USER', 'content' => 'not from MODEL'])), 'parse_transcript_line: a GENERIC entry from a source other than MODEL is not treated as a tool result');

    // --- read_transcript_page(): pagination ---

    $lastPage = AntigravityTranscriptService::read_transcript_page($transcriptPath, null, 10);
    assert_equal(true, $lastPage['ok'], 'read_transcript_page: ok=true for a real file');
    // 8 raw lines, 2 skipped (CHECKPOINT, unrecognized type) -> 6 renderable entries.
    assert_equal(6, count($lastPage['entries']), 'read_transcript_page: renderable entry count excludes CHECKPOINT/unrecognized lines, does not count against $limit consumption incorrectly');
    assert_equal('USER_INPUT', $lastPage['entries'][0]['type'] ?? null, 'read_transcript_page: entries come back oldest-first (top-to-bottom reading order)');
    assert_equal(1, $lastPage['entries'][0]['line'] ?? null, 'read_transcript_page: each entry carries its real 1-indexed raw line number');
    assert_equal(false, $lastPage['has_more'], 'read_transcript_page: has_more=false once the whole file fits in one page');
    assert_equal(null, $lastPage['next_before'], 'read_transcript_page: next_before=null once there is nothing earlier');

    $smallPage = AntigravityTranscriptService::read_transcript_page($transcriptPath, null, 2);
    assert_equal(2, count($smallPage['entries']), 'read_transcript_page: $limit counts renderable entries, honored exactly');
    assert_equal(true, $smallPage['has_more'], 'read_transcript_page: has_more=true when earlier entries remain');
    assert_true($smallPage['next_before'] !== null, 'read_transcript_page: next_before is set for the next "load older" request');

    $olderPage = AntigravityTranscriptService::read_transcript_page($transcriptPath, $smallPage['next_before'], 10);
    assert_equal(4, count($olderPage['entries']), 'read_transcript_page: paging backward via next_before picks up exactly the remaining older entries');

    $missingFilePage = AntigravityTranscriptService::read_transcript_page($transcriptPath . '.does-not-exist', null, 10);
    assert_equal(false, $missingFilePage['ok'], 'read_transcript_page: ok=false for a missing file, not a crash');

    // --- read_transcript_page_since(): incremental poll ---

    $sincePage = AntigravityTranscriptService::read_transcript_page_since($transcriptPath, 4, 10);
    assert_equal(true, $sincePage['ok'], 'read_transcript_page_since: ok=true');
    // Lines 5-8 (1-indexed): text response, unrecognized (skipped), 2nd user turn, final reply -> 3 renderable.
    assert_equal(3, count($sincePage['entries']), 'read_transcript_page_since: only entries strictly after the given line, unrecognized lines still excluded');
    assert_equal(5, $sincePage['entries'][0]['line'] ?? null, 'read_transcript_page_since: the first new entry is exactly line 5, nothing earlier leaks in');

    $noNewSince = AntigravityTranscriptService::read_transcript_page_since($transcriptPath, 8, 10);
    assert_equal([], $noNewSince['entries'], 'read_transcript_page_since: empty when already caught up to the end of the file');

    // --- read_attachment(): honest not-supported stub ---

    $attachment = AntigravityTranscriptService::read_attachment($transcriptPath, 1, 'whatever-uuid');
    assert_equal(false, $attachment['ok'], 'read_attachment: honestly reports unsupported rather than pretending to find something');

    // --- TranscriptRouter: dispatch by path shape ---

    assert_equal(true, TranscriptRouter::is_antigravity_path($transcriptPath), 'TranscriptRouter::is_antigravity_path: true for a real antigravity-cli/brain/ path');
    assert_equal(false, TranscriptRouter::is_antigravity_path('/home/user/.claude/projects/-home-user-dev/some-uuid.jsonl'), 'TranscriptRouter::is_antigravity_path: false for a Claude Code path');

    assert_equal($transcriptPath, TranscriptRouter::find_transcript_path($conversationId), 'TranscriptRouter::find_transcript_path: resolves an Antigravity id when Claude Code\'s own resolution finds nothing');

    $routedPage = TranscriptRouter::read_transcript_page($transcriptPath, null, 10);
    assert_equal($lastPage, $routedPage, 'TranscriptRouter::read_transcript_page: identical result to calling AntigravityTranscriptService directly for an antigravity path');

    $routedSince = TranscriptRouter::read_transcript_page_since($transcriptPath, 4, 10);
    assert_equal($sincePage, $routedSince, 'TranscriptRouter::read_transcript_page_since: identical result to calling AntigravityTranscriptService directly');

    $routedAttachment = TranscriptRouter::read_attachment($transcriptPath, 1, 'whatever-uuid');
    assert_equal($attachment, $routedAttachment, 'TranscriptRouter::read_attachment: identical result to calling AntigravityTranscriptService directly');

    // --- TranscriptRouter: still routes Claude Code paths to TranscriptService unchanged ---

    $claudeFixtureDir = Config::home_root() . '/.claude/projects/-fixture-project';
    mkdir($claudeFixtureDir, 0700, true);
    $agentSessionId = '11111111-1111-4111-8111-111111111111';
    $claudeTranscriptPath = "{$claudeFixtureDir}/{$agentSessionId}.jsonl";
    file_put_contents($claudeTranscriptPath, json_encode(['type' => 'user', 'message' => ['role' => 'user', 'content' => 'hello from claude code']]) . "\n");

    assert_equal($claudeTranscriptPath, TranscriptRouter::find_transcript_path($agentSessionId), 'TranscriptRouter::find_transcript_path: still resolves a real Claude Code session via TranscriptService, unaffected by AntigravityTranscriptService existing');
    assert_equal(TranscriptService::read_transcript_page($claudeTranscriptPath, null, 10), TranscriptRouter::read_transcript_page($claudeTranscriptPath, null, 10), 'TranscriptRouter::read_transcript_page: a Claude Code path routes to TranscriptService, not AntigravityTranscriptService');
} finally {
    @unlink($transcriptPath);
    array_map('unlink', glob(Config::home_root() . '/.claude/projects/-fixture-project/*') ?: []);
    @rmdir(Config::home_root() . '/.claude/projects/-fixture-project');
    @rmdir(Config::home_root() . '/.claude/projects');
    @rmdir(Config::home_root() . '/.claude');
    @rmdir($transcriptDir);
    @rmdir(dirname($transcriptDir));
    @rmdir(dirname(dirname($transcriptDir)));
    @rmdir(dirname(dirname(dirname($transcriptDir))));
    @rmdir($fixtureHome);
}

test_exit();
