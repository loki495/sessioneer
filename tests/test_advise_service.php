<?php

declare(strict_types=1);

// AdviseService verdicts from fixture transcripts and plain numbers. No tmux, no model call, no real transcript.
require __DIR__ . '/../vendor/autoload.php';
require __DIR__ . '/lib/assert.php';

use HostAgent\Services\AdviseService;

$dir = sys_get_temp_dir() . '/sessioneer-test-advise-' . getmypid();
mkdir($dir, 0700, true);
register_shutdown_function(static function () use ($dir): void {
    exec('rm -rf ' . escapeshellarg($dir));
});

function transcript(string $dir, string $name, int $input, int $mtime, bool $sidechainLast = false): string
{
    $line = static fn (int $in, bool $side): string => json_encode([
        'type' => 'assistant', 'isSidechain' => $side, 'parent_tool_use_id' => null,
        'message' => ['model' => 'm1', 'usage' => ['input_tokens' => 10, 'cache_creation_input_tokens' => 0, 'cache_read_input_tokens' => $in - 10]],
    ]) . "\n";
    $path = $dir . '/' . $name . '.jsonl';
    file_put_contents($path, "not json\n" . $line($input, false) . ($sidechainLast ? $line(999999, true) : ''));
    touch($path, $mtime);
    return $path;
}

$now = 2_000_000_000;
$cold = $now - 7200;
$warm = $now - 60;
$quota = ['session' => ['pct' => 3, 'resets_at' => $now + 100], 'week_all' => ['pct' => 95, 'resets_at' => $now + 5000]];

$small = AdviseService::claude_facts(transcript($dir, 'small', 30000, $cold));
assert_equal(30000, $small['context_tokens'], 'context is the last assistant usage sum');
assert_equal('resume', AdviseService::advise($small, null, 'c1', $now)['verdict'], 'small context resumes');

$big = AdviseService::claude_facts(transcript($dir, 'big', 200000, $cold, true));
assert_equal(200000, $big['context_tokens'], 'a subagent (sidechain) snapshot is ignored');
$fresh = AdviseService::advise($big, null, 'c2', $now);
assert_equal('fresh', $fresh['verdict'], 'large context goes fresh');
assert_equal(200000, $fresh['cost']['resume_rewrite_tokens'], 'cold cache re-writes the whole context');
assert_equal(true, str_contains($fresh['prompts']['fresh'], 'c2'), 'fresh prompt names the conversation');

$mid = AdviseService::claude_facts(transcript($dir, 'mid', 100000, $cold));
assert_equal('judgment', AdviseService::advise($mid, null, 'c3', $now)['verdict'], 'middle band, cold cache is a judgment call');
$midWarm = AdviseService::claude_facts(transcript($dir, 'midwarm', 100000, $warm));
$warmAdvice = AdviseService::advise($midWarm, null, 'c4', $now);
assert_equal('resume', $warmAdvice['verdict'], 'middle band, warm cache resumes');
assert_equal(0, $warmAdvice['cost']['resume_rewrite_tokens'], 'warm cache re-writes nothing');

$blocked = AdviseService::advise($small, $quota, 'c1', $now);
assert_equal(true, $blocked['quota_blocked'], 'a window at 95% blocks');
assert_equal($now + 5000, $blocked['restart_after'], 'restart waits for the blocking window reset');
assert_equal(false, AdviseService::advise($small, null, 'c1', $now)['quota_blocked'], 'no quota data does not block');
assert_equal([false, true], [AdviseService::advise($small, null, 'c1', $now)['quota_known'], $blocked['quota_known']], 'missing quota data is reported as unknown, not as fine');

assert_equal(null, AdviseService::claude_facts($dir . '/missing.jsonl'), 'missing transcript has no facts');
assert_equal('unsupported', AdviseService::advise(null, null, 'c5', $now)['verdict'], 'no facts is unsupported');
assert_equal(null, AdviseService::codex_facts(['last' => []], 'gpt', 1), 'codex without last totalTokens has no facts');
$codex = AdviseService::codex_facts(['last' => ['totalTokens' => 170000]], 'gpt-6', $cold);
assert_equal('fresh', AdviseService::advise($codex, null, 'c6', $now)['verdict'], 'codex context uses the same thresholds');

echo "ok\n";
