<?php
declare(strict_types=1);

/**
 * Host-agent pieces the v2 session page relies on: assistant reasoning text only appears in a
 * transcript page when asked for (and never when empty), a session detail carries `starred` and a
 * terminal `resume_command`, and the resume command quotes what it embeds and refuses a
 * conversation with no id.
 */

require __DIR__ . '/lib/assert.php';

$statusDb = sys_get_temp_dir() . '/sessioneer-test-v2-agent-' . bin2hex(random_bytes(4)) . '.sqlite';
putenv("SESSIONS_SQLITE_FILE={$statusDb}");

require dirname(__DIR__) . '/host-agent/lib/Sessions.php';

use HostAgent\Services\Config;
use HostAgent\Services\TranscriptService;
use HostAgent\Stores\SidecarStore;

// --- reasoning text is opt-in ---
$thinking = json_encode(['type' => 'assistant', 'timestamp' => '2026-01-01T00:00:00Z', 'message' => ['role' => 'assistant', 'content' => [
    ['type' => 'thinking', 'thinking' => 'Weighing the two options.'],
    ['type' => 'text', 'text' => 'Done.'],
]]]);
$default = TranscriptService::parse_transcript_line($thinking);
assert_equal(['text'], array_column($default['blocks'], 'kind'), 'reasoning: dropped by default, as the classic page expects');
$with = TranscriptService::parse_transcript_line($thinking, [], true);
assert_equal(['thinking', 'text'], array_column($with['blocks'], 'kind'), 'reasoning: kept, in order, when asked for');
assert_equal('Weighing the two options.', $with['blocks'][0]['text'], 'reasoning: carries its text');

$empty = json_encode(['type' => 'assistant', 'message' => ['role' => 'assistant', 'content' => [['type' => 'thinking', 'thinking' => '', 'signature' => 'abc']]]]);
assert_equal(null, TranscriptService::parse_transcript_line($empty, [], true), 'reasoning: an empty (not persisted) block still makes no entry');
$blank = json_encode(['type' => 'assistant', 'message' => ['role' => 'assistant', 'content' => [['type' => 'thinking', 'thinking' => "  \n"]]]]);
assert_equal(null, TranscriptService::parse_transcript_line($blank, [], true), 'reasoning: whitespace-only is treated as empty');

$file = tempnam(sys_get_temp_dir(), 'v2-thinking');
file_put_contents($file, $thinking . "\n" . $empty . "\n");
assert_equal(1, count(TranscriptService::read_transcript_page($file, null, 10)['entries'][0]['blocks']), 'page: default page has no reasoning block');
$page = TranscriptService::read_transcript_page($file, null, 10, false, true);
assert_equal(2, count($page['entries'][0]['blocks']), 'page: include_thinking page has the reasoning block');
$since = TranscriptService::read_transcript_page_since($file, 0, 10, true);
assert_equal('thinking', $since['entries'][0]['blocks'][0]['kind'], 'poll page: the forward read honours the flag too');
unlink($file);

// --- starred + resume command on a detail ---
SidecarStore::write_sidecar('v2a-one', ['workdir' => '/tmp/a', 'spawned_at' => 1]);
SidecarStore::set_starred('v2a-one', true);
$detail = ['ok' => true, 'agent' => 'claude', 'agent_session_id' => 'abc-123', 'workdir' => "/tmp/it's here", 'profile' => null];
$extras = sessioneer_with_detail_extras($detail, 'v2a-one');
assert_equal(true, $extras['starred'], 'detail: starred session reports starred');
assert_equal(false, sessioneer_with_detail_extras($detail, 'v2a-other')['starred'], 'detail: an unstarred session reports false');
assert_equal("cd '/tmp/it'\\''s here' && claude --resume 'abc-123'", $extras['resume_command'], 'resume: workdir is shell-quoted, default account sets no config dir');
assert_equal(['ok' => false], sessioneer_with_detail_extras(['ok' => false], 'x'), 'detail: a failed detail passes through untouched');

assert_equal(null, sessioneer_resume_command(['agent' => 'claude', 'agent_session_id' => null, 'workdir' => '/tmp/a']), 'resume: no conversation id yet -> no command');
assert_equal(null, sessioneer_resume_command(['agent' => 'claude', 'agent_session_id' => 'abc', 'workdir' => '']), 'resume: no workdir -> no command');
assert_equal(null, sessioneer_resume_command(['agent' => 'ollama', 'agent_session_id' => 'abc', 'workdir' => '/tmp/a']), 'resume: an agent with no terminal UI -> no command');
assert_equal("cd '/tmp/a' && codex resume 'thr-1'", sessioneer_resume_command(['agent' => 'codex', 'agent_session_id' => 'thr-1', 'workdir' => '/tmp/a']), 'resume: Codex');
assert_equal("cd '/tmp/a' && opencode --session 'ses_1'", sessioneer_resume_command(['agent' => 'opencode', 'agent_session_id' => 'ses_1', 'workdir' => '/tmp/a']), 'resume: OpenCode');

// A Claude account kept in its own config dir (and binary) must be named, or the terminal reads the default account's store.
(new ReflectionProperty(Config::class, 'agentsConfig'))->setValue(null, ['claude' => ['profiles' => [
    'work' => ['config_dir' => '/home/u/.claude-work', 'bin' => '/opt/claude-work'],
    'personal' => ['config_dir' => null, 'bin' => null],
]]]);
assert_equal(
    "cd '/tmp/a' && CLAUDE_CONFIG_DIR='/home/u/.claude-work' '/opt/claude-work' --resume 'abc'",
    sessioneer_resume_command(['agent' => 'claude', 'agent_session_id' => 'abc', 'workdir' => '/tmp/a', 'profile' => 'work']),
    'resume: a work account carries its config dir and binary'
);
assert_equal(
    "cd '/tmp/a' && claude --resume 'abc'",
    sessioneer_resume_command(['agent' => 'claude', 'agent_session_id' => 'abc', 'workdir' => '/tmp/a', 'profile' => 'personal']),
    'resume: the personal (default) account sets neither'
);

@unlink($statusDb);
test_exit();
