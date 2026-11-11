<?php

declare(strict_types=1);

// Step 2 of session-flow: HostAgent\SessionFlow\FlowFacts (claude, codex, stored).
// claude() and codex() are characterization tests ported from test_advise_service.php
// (they pass on AdviseService::claude_facts/codex_facts today); stored() and the
// compact_boundary rule are new. No agent, no model call, temp files only.
// Written before the implementation: fails until the class exists.

$autoload = __DIR__ . '/../vendor/autoload.php';
if (is_file($autoload)) {
    require $autoload;
} else {
    spl_autoload_register(static function (string $c): void {
        foreach (['HostAgent\\' => '/../host-agent/lib/', 'App\\' => '/../src/lib/'] as $prefix => $dir) {
            $f = __DIR__ . $dir . str_replace('\\', '/', substr($c, strlen($prefix))) . '.php';
            if (str_starts_with($c, $prefix) && is_file($f)) {
                require $f;
            }
        }
    });
}
require __DIR__ . '/lib/assert.php';

use HostAgent\SessionFlow\FlowFacts;

$dir = sys_get_temp_dir() . '/sessioneer-test-flow-facts-' . getmypid();
mkdir($dir, 0700, true);
register_shutdown_function(static function () use ($dir): void {
    exec('rm -rf ' . escapeshellarg($dir));
});

$assistant = static fn (int $in, bool $side = false): string => json_encode([
    'type' => 'assistant', 'isSidechain' => $side, 'parent_tool_use_id' => null,
    'message' => ['model' => 'm1', 'usage' => ['input_tokens' => 10, 'cache_creation_input_tokens' => 0, 'cache_read_input_tokens' => $in - 10]],
]) . "\n";
$boundary = static fn (mixed $post): string => json_encode([
    'type' => 'system', 'subtype' => 'compact_boundary',
    'compactMetadata' => $post === null ? ['trigger' => 'auto'] : ['trigger' => 'auto', 'preTokens' => 200000, 'postTokens' => $post],
]) . "\n";
$write = static function (string $name, string $body, int $mtime = 1_900_000_000) use ($dir): string {
    $path = $dir . '/' . $name . '.jsonl';
    file_put_contents($path, $body);
    touch($path, $mtime);

    return $path;
};

echo "claude()\n";
$small = FlowFacts::claude($write('small', "not json\n" . $assistant(30000), 1_900_000_000));
assert_equal(['agent' => 'claude', 'context_tokens' => 30000, 'model' => 'm1', 'last_activity' => 1_900_000_000], $small, 'last assistant usage sum, model, file mtime');
$side = FlowFacts::claude($write('side', $assistant(200000) . $assistant(999999, true)));
assert_equal(200000, $side['context_tokens'] ?? null, 'a subagent (sidechain) snapshot is ignored');
$last = FlowFacts::claude($write('last', $assistant(50000) . $assistant(70000)));
assert_equal(70000, $last['context_tokens'] ?? null, 'the latest main-thread assistant entry wins');
assert_equal(null, FlowFacts::claude($dir . '/missing.jsonl'), 'missing transcript has no facts');
assert_equal(null, FlowFacts::claude($write('junk', "not json\n{}\n")), 'a transcript with no assistant usage has no facts');
assert_equal(null, FlowFacts::claude($write('empty', '')), 'an empty transcript has no facts');
$tail = FlowFacts::claude($write('tail', $assistant(111000) . str_repeat(json_encode(['type' => 'user', 'x' => str_repeat('a', 900)]) . "\n", 400)));
assert_equal(null, $tail, 'only the last 256 KiB is read, so an older usage entry is not found');

echo "claude() compact_boundary\n";
$fixture = __DIR__ . '/fixtures/claude_transcript_compact_boundary.jsonl';
$compact = FlowFacts::claude($fixture);
assert_equal(4000, $compact['context_tokens'] ?? null, 'fixture: a compact_boundary after the last assistant counts at its postTokens');
assert_equal('claude', $compact['agent'] ?? null, 'fixture: agent');
assert_equal((int) filemtime($fixture), $compact['last_activity'] ?? null, 'fixture: last_activity is the file mtime');
assert_equal(4000, FlowFacts::claude($write('b-after', $assistant(200000) . $boundary(4000)))['context_tokens'] ?? null, 'boundary after the assistant: postTokens');
assert_equal(80000, FlowFacts::claude($write('b-before', $boundary(4000) . $assistant(80000)))['context_tokens'] ?? null, 'an assistant entry after the boundary counts at its own usage');
assert_equal(200000, FlowFacts::claude($write('b-nopost', $assistant(200000) . $boundary(null)))['context_tokens'] ?? null, 'a boundary without postTokens is ignored');
assert_equal(0, FlowFacts::claude($write('b-zero', $assistant(200000) . $boundary(0)))['context_tokens'] ?? null, 'a postTokens of 0 is a valid reading');
assert_equal('m1', FlowFacts::claude($write('b-model', $assistant(200000) . $boundary(4000)))['model'] ?? null, 'the boundary carries no model, so the preceding assistant entry supplies it');

echo "codex()\n";
assert_equal(null, FlowFacts::codex(['last' => []], 'gpt', 1), 'no last totalTokens: no facts');
assert_equal(null, FlowFacts::codex(null, 'gpt', 1), 'no token usage: no facts');
assert_equal(null, FlowFacts::codex(['last' => ['totalTokens' => -1]], 'gpt', 1), 'negative totalTokens: no facts');
assert_equal(null, FlowFacts::codex(['last' => ['totalTokens' => '170000']], 'gpt', 1), 'non-int totalTokens: no facts');
assert_equal(['agent' => 'codex', 'context_tokens' => 170000, 'model' => 'gpt-6', 'last_activity' => 123], FlowFacts::codex(['last' => ['totalTokens' => 170000], 'total' => ['totalTokens' => 9999999]], 'gpt-6', 123), 'last.totalTokens, not the cumulative total');
assert_equal(0, FlowFacts::codex(['last' => ['totalTokens' => 0]], 'gpt', 1)['context_tokens'] ?? null, 'zero tokens is a valid reading');

echo "stored()\n";
$claudeStored = ['model' => 'claude-x', 'input_tokens' => 30000, 'context_window' => 200000];
$codexStored = ['last' => ['totalTokens' => 170000], 'modelContextWindow' => 258000];
assert_equal(
    ['agent' => 'claude', 'context_tokens' => 30000, 'model' => 'claude-x', 'last_activity' => 555],
    FlowFacts::stored($claudeStored, null, 'claude', 'ignored', 555, null),
    'claude: stored context_usage gives the facts (model from the reading)'
);
assert_equal(
    ['agent' => 'codex', 'context_tokens' => 170000, 'model' => 'gpt-6', 'last_activity' => 555],
    FlowFacts::stored(null, $codexStored, 'codex', 'gpt-6', 555, null),
    'codex: stored token_usage gives the facts, identical to codex()'
);
assert_equal(FlowFacts::codex($codexStored, 'gpt-6', 555), FlowFacts::stored(null, $codexStored, 'codex', 'gpt-6', 555, null), 'codex: stored() equals codex()');
$big = $write('stored-big', $assistant(200000));
assert_equal(30000, FlowFacts::stored($claudeStored, null, 'claude', 'm1', 555, $big)['context_tokens'] ?? null, 'the stored reading wins over a transcript');
assert_equal(170000, FlowFacts::stored(null, $codexStored, 'codex', 'gpt-6', 555, $big)['context_tokens'] ?? null, 'a stored codex reading wins over any transcript path');
assert_equal(555, FlowFacts::stored($claudeStored, null, 'claude', 'm1', 555, $big)['last_activity'] ?? null, 'last_activity is the argument, not the transcript mtime, when stored wins');
$fallback = FlowFacts::stored(null, null, 'claude', 'm1', 555, $big);
assert_equal(200000, $fallback['context_tokens'] ?? null, 'nothing stored: falls back to the transcript tail');
assert_equal(FlowFacts::claude($big), $fallback, 'the fallback equals claude()');
$compactFallback = FlowFacts::stored(null, null, 'claude', 'm1', 555, $fixture);
assert_equal(4000, $compactFallback['context_tokens'] ?? null, 'the transcript fallback honours compact_boundary');
assert_equal(null, FlowFacts::stored(null, null, 'claude', 'm1', 555, null), 'nothing stored and no transcript: null');
assert_equal(null, FlowFacts::stored(null, null, 'claude', 'm1', 555, $dir . '/missing.jsonl'), 'nothing stored and an unreadable transcript: null');
assert_equal(null, FlowFacts::stored(null, null, 'codex', 'gpt', 555, null), 'codex with nothing stored and no transcript: null');
assert_equal(null, FlowFacts::stored(null, ['last' => []], 'codex', 'gpt', 555, null), 'codex with an unreadable stored reading and no transcript: null');
assert_equal(null, FlowFacts::stored(['model' => 'claude-x'], null, 'claude', 'm1', 555, null), 'a stored claude reading without input_tokens is not readable: null');
assert_equal(0, FlowFacts::stored(['model' => 'claude-x', 'input_tokens' => 0], null, 'claude', 'm1', 555, null)['context_tokens'] ?? null, 'a stored zero is a reading, not "nothing stored"');
assert_equal(null, FlowFacts::stored(null, null, 'opencode', 'm', 555, $big), 'an agent with no reader gives null');

echo "ok\n";
test_exit();
