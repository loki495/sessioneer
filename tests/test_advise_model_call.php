<?php

declare(strict_types=1);

// AdviseModelCall chain order, fallthrough and the no-call guarantee, with a fake runner. No process is spawned.
require __DIR__ . '/../vendor/autoload.php';
require __DIR__ . '/lib/assert.php';

use HostAgent\Services\AdviseModelCall;

putenv('CLAUDE_BIN=/fake/claude');
putenv('CODEX_BIN=/fake/codex');
putenv('OPENCODE_BIN=/fake/opencode');

$ok = ['session' => ['pct' => 3], 'week_all' => ['pct' => 40]];
$full = ['session' => ['pct' => 3], 'week_all' => ['pct' => 100]];
$usage = [
    'claude' => ['profiles' => ['personal' => ['quota' => $full], 'work' => ['quota' => $ok], 'extra' => ['quota' => $ok]]],
    'codex' => ['quota' => $ok],
    'opencode' => ['quota' => $ok],
];

$names = static fn (array $c): array => array_map(static fn (array $x): string => $x['agent'] . ':' . ($x['profile'] ?? '-'), $c);
assert_equal(['claude:work', 'claude:extra', 'codex:-'], $names(AdviseModelCall::candidates($usage, 'work')), 'home profile first, a full profile skipped, then codex; opencode is never a candidate');
assert_equal(['claude:extra', 'claude:work', 'codex:-'], $names(AdviseModelCall::candidates($usage, 'zzz')), 'without a home profile the others sort by name');
$usage['codex']['quota'] = $full;
unset($usage['opencode']);
assert_equal(['claude:work', 'claude:extra'], $names(AdviseModelCall::candidates($usage, 'work')), 'a full or absent agent is dropped');

$calls = [];
$runner = static function (array $c, string $prompt) use (&$calls): ?string {
    $calls[] = $c['agent'] . ':' . ($c['profile'] ?? '-');
    return null;
};
$judgment = ['verdict' => 'judgment', 'reason' => 'mid', 'context_tokens' => 90000];
$candidates = AdviseModelCall::candidates(['claude' => ['profiles' => ['work' => ['quota' => $ok]]], 'codex' => ['quota' => $ok]], 'work');

foreach (['resume', 'fresh', 'unsupported'] as $verdict) {
    $same = AdviseModelCall::resolve(['verdict' => $verdict], null, $candidates, $runner);
    assert_equal($verdict, $same['verdict'], "clear verdict $verdict is returned untouched");
}
assert_equal([], $calls, 'no model call for any clear verdict');

$failed = AdviseModelCall::resolve($judgment, null, $candidates, $runner);
assert_equal(['claude:work', 'codex:-'], $calls, 'every candidate is tried in order when all fail');
assert_equal('judgment', $failed['verdict'], 'all candidates failing leaves the judgment call to the user');
assert_equal(90000, $failed['context_tokens'], 'the numbers survive a failed call');

$calls = [];
$second = AdviseModelCall::resolve($judgment, null, $candidates, static function (array $c, string $p) use (&$calls): ?string {
    $calls[] = $c['agent'];
    return $c['agent'] === 'claude' ? 'I think... no idea' : "noise\nFRESH: long design Q&A is still open\n";
});
assert_equal(['claude', 'codex'], $calls, 'an unparseable answer falls through to the next candidate');
assert_equal('fresh', $second['verdict'], 'the first parseable answer decides');
assert_equal('codex:gpt-6-luna', $second['decided_by'], 'the deciding model is recorded');
assert_equal(90000, $second['context_tokens'], 'the advice numbers are kept alongside the model verdict');

$seen = '';
AdviseModelCall::resolve($judgment, null, $candidates, static function (array $c, string $p) use (&$seen): ?string {
    $seen = $p;
    return 'RESUME: ok';
});
assert_equal(true, str_contains($seen, 'RESUME') && str_contains($seen, 'FRESH'), 'the prompt states both answers');

assert_equal(['resume', 'fine'], AdviseModelCall::parse('resume : fine'), 'parse is case and space tolerant');
assert_equal(200, mb_strlen(AdviseModelCall::parse('FRESH: ' . str_repeat('x', 500))[1]), 'a model reason is capped');

$dir = sys_get_temp_dir() . '/sessioneer-test-advise-model-' . getmypid();
mkdir($dir);
register_shutdown_function(static function () use ($dir): void {
    exec('rm -rf ' . escapeshellarg($dir));
});
file_put_contents($dir . '/t.jsonl', json_encode(['type' => 'user', 'message' => ['content' => 'my api_key: sk-live-abc and abcdefghijklmnopqrstuvwxyz0123 ok']]) . "\n");
$prompted = '';
AdviseModelCall::resolve($judgment, $dir . '/t.jsonl', $candidates, static function (array $c, string $p) use (&$prompted): ?string {
    $prompted = $p;
    return null;
});
assert_equal(true, str_contains($prompted, 'ok') && !str_contains($prompted, 'sk-live-abc') && !str_contains($prompted, 'abcdefghijklmnopqrstuvwxyz0123'), 'secret-shaped text is redacted from the prompt');
assert_equal(true, str_contains($prompted, 'untrusted'), 'the prompt marks the transcript as untrusted');
assert_equal(null, AdviseModelCall::parse(null), 'a null answer does not parse');

echo "ok\n";
