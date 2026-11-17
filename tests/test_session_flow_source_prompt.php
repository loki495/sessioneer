<?php

declare(strict_types=1);

// Rotate receiver must be told which session it replaces (task 1786, plan step 1978).
//   Part 1: PromptBuilder::plan/handoff/selfContained take an optional source
//           ['name' => string, 'id' => ?string, 'agent' => string] and end with the
//           "Source session: ..." paragraph. Pure, no I/O.
//   Part 2: FlowService::rotate builds its prompt (--handoff, --plan, --prompt alike) with the
//           source's session name, sidecar agent_session_id and agent, so `sessioneer --rotate
//           --dry-run` shows the paragraph. Isolated temp SQLite files and fake launch/runner deps.
// Written before the implementation: every assertion reports individually (extra arguments to the
// current builders are ignored, so nothing here fatals).

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

$dir = sys_get_temp_dir() . '/sessioneer-test-flow-source-prompt-' . getmypid();
mkdir($dir, 0700, true);
register_shutdown_function(static function () use ($dir): void {
    exec('rm -rf ' . escapeshellarg($dir));
});
putenv("PUSH_SQLITE_FILE={$dir}/push.sqlite");
putenv("SESSIONS_SQLITE_FILE={$dir}/sessions.sqlite");
putenv("SIDECAR_DIR={$dir}/sidecars");
putenv("CACHE_DIR={$dir}/cache");
putenv('SESSIONEER_CLAIMS_CMD');
putenv('CLAUDE_BIN=/fake/claude');
putenv('CODEX_BIN=/fake/codex');

use HostAgent\SessionFlow\FlowService;
use HostAgent\SessionFlow\PromptBuilder as P;
use HostAgent\SessionFlow\Thresholds;
use HostAgent\Stores\SessionStatusStore;
use HostAgent\Stores\SidecarStore;

$rejects = static function (callable $fn, string $msg): void {
    try {
        $fn();
    } catch (\InvalidArgumentException) {
        assert_true(true, $msg);

        return;
    } catch (\Throwable $e) {
        assert_true(false, $msg . ' (wrong exception ' . $e::class . ')');

        return;
    }
    assert_true(false, $msg . ' (nothing thrown)');
};

$UUID = '11111111-1111-4111-8111-111111111111';
$TAIL = 'Claim your Dibs task first. Sessioneer archives the source once it is idle and holds no claim; do not archive or force-stop it yourself, and if it is still working or holds a claim, leave it and tell Andres.';
$para = static fn (string $head): string => 'Source session: ' . $head . '. ' . $TAIL;
$src = ['name' => 'Next leg', 'id' => $UUID, 'agent' => 'claude'];
$paraSrc = $para("Next leg {$UUID} (claude)");

// ---------------------------------------------------------------- part 1: PromptBuilder

echo "paragraph: exact text and position\n";
assert_equal(P::plan('1762') . "\n\n" . $paraSrc, P::plan('1762', '', $src), 'plan: source appends a blank line and the paragraph');
assert_equal(P::plan('1762', 'step 3 is next') . "\n\n" . $paraSrc, P::plan('1762', 'step 3 is next', $src), 'plan: note kept, paragraph after it');
assert_equal(P::handoff('1800') . "\n\n" . $paraSrc, P::handoff('1800', $src), 'handoff: source appends a blank line and the paragraph');
assert_equal(P::selfContained('Fix the bug in foo.') . "\n\n" . $paraSrc, P::selfContained('Fix the bug in foo.', $src), 'selfContained: source appends a blank line and the paragraph');
foreach (['plan' => P::plan('1762', '', $src), 'handoff' => P::handoff('1800', $src), 'selfContained' => P::selfContained('x', $src)] as $k => $text) {
    assert_true(str_ends_with($text, "\n\n" . $paraSrc), "$k: ends with the exact paragraph after a blank line");
    assert_equal(1, substr_count($text, 'Source session:'), "$k: the paragraph appears once");
    assert_true(!str_starts_with(ltrim($text), '/'), "$k: still no leading slash command with a source");
}

echo "paragraph: id omitted when null or empty\n";
foreach (['null id' => null, 'empty id' => ''] as $label => $id) {
    $s = ['name' => 'solo', 'id' => $id, 'agent' => 'codex'];
    $expect = $para('solo (codex)');
    assert_equal(P::plan('1') . "\n\n" . $expect, P::plan('1', '', $s), "plan: $label gives 'Source session: <name> (<agent>).'");
    assert_equal(P::handoff('1') . "\n\n" . $expect, P::handoff('1', $s), "handoff: $label omits the id part");
    assert_equal(P::selfContained('t') . "\n\n" . $expect, P::selfContained('t', $s), "selfContained: $label omits the id part");
    assert_true(!str_contains(P::handoff('1', $s), 'solo  ('), "handoff: $label leaves no double space");
}

echo "paragraph: without a source nothing changes\n";
assert_equal(P::plan('1762'), P::plan('1762', '', null), 'plan: null source equals no source');
assert_equal(P::handoff('1800'), P::handoff('1800', null), 'handoff: null source equals no source');
assert_equal(P::selfContained('x'), P::selfContained('x', null), 'selfContained: null source equals no source');
foreach ([P::plan('1762'), P::handoff('1800'), P::selfContained('x')] as $text) {
    assert_true(!str_contains($text, 'Source session:'), 'no source: no paragraph');
}
assert_equal('Continue Dibs plan 1762. Read the plan, claim it, then work from todo_next.', P::plan('1762'), 'plan: unchanged text without a source');
assert_equal("Complete this task. It is self-contained; no earlier conversation is available.\n\nx", P::selfContained('x'), 'selfContained: unchanged text without a source');

echo "handoff: the archive sentence is gone\n";
assert_true(!str_contains(P::handoff('1800'), 'Archive the source session once it holds no claim.'), 'handoff: the old archive sentence is gone without a source');
assert_true(!str_contains(P::handoff('1800', $src), 'Archive the source session once it holds no claim.'), 'handoff: and with a source');
assert_contains('Next steps', P::handoff('1800'), 'handoff: still ends its work on the Next steps');
assert_contains('Re-verify', P::handoff('1800'), 'handoff: the re-verify instruction stays');

echo "source: valid values\n";
foreach (['name with dots' => ['name' => 'a.b-c_d:e', 'id' => 'abc123', 'agent' => 'claude'],
    'spaces in name' => ['name' => 'My long session name', 'id' => 'ses_abc123XYZ', 'agent' => 'opencode'],
    'token agent chars' => ['name' => 'n', 'id' => $UUID, 'agent' => 'a.b_c:d-1']] as $label => $s) {
    $expected = $para("{$s['name']} {$s['id']} ({$s['agent']})");
    assert_equal(P::plan('1') . "\n\n" . $expected, P::plan('1', '', $s), "valid $label: plan");
}

echo "source: invalid values throw InvalidArgumentException\n";
$badName = ['empty' => '', 'newline' => "a\nb", 'carriage return' => "a\rb", 'double quote' => 'a"b', 'single quote' => "a'b", 'backtick' => 'a`b'];
$badToken = ['space' => 'a b', 'newline' => "a\nb", 'double quote' => 'a"b', 'single quote' => "a'b", 'backtick' => 'a`b', 'semicolon' => 'a;b', 'dollar' => 'a$(id)', 'slash' => 'a/b'];
$builders = [
    'plan' => static fn (array $s) => P::plan('1', '', $s),
    'handoff' => static fn (array $s) => P::handoff('1', $s),
    'selfContained' => static fn (array $s) => P::selfContained('t', $s),
];
foreach ($builders as $b => $build) {
    foreach ($badName as $k => $v) {
        $rejects(static fn () => $build(['name' => $v, 'id' => $UUID, 'agent' => 'claude']), "$b: source name $k throws");
    }
    foreach ($badToken as $k => $v) {
        $rejects(static fn () => $build(['name' => 'n', 'id' => $UUID, 'agent' => $v]), "$b: source agent with $k throws");
        $rejects(static fn () => $build(['name' => 'n', 'id' => $v, 'agent' => 'claude']), "$b: source id with $k throws");
    }
    $rejects(static fn () => $build(['name' => 'n', 'id' => $UUID, 'agent' => '']), "$b: empty source agent throws");
    $rejects(static fn () => $build(['id' => $UUID, 'agent' => 'claude']), "$b: source without a name key throws");
    $rejects(static fn () => $build(['name' => 'n', 'id' => $UUID]), "$b: source without an agent key throws");
    $rejects(static fn () => $build(['name' => ' ', 'id' => $UUID, 'agent' => 'claude']), "$b: whitespace-only source name throws");
    $rejects(static fn () => $build(['name' => "\t  \t", 'id' => $UUID, 'agent' => 'claude']), "$b: tab-and-space source name throws");
}

echo "limit: 8000 characters\n";
$base = strlen(P::plan('1762', 'x', $src));
$fit = P::plan('1762', str_repeat('x', 8000 - $base + 1), $src);
assert_equal(8000, strlen($fit), 'plan: a prompt of exactly 8000 characters including the paragraph is returned');
$rejects(static fn () => P::plan('1762', str_repeat('x', 8000 - $base + 2), $src), 'plan: the paragraph counts toward the 8000 limit');
$plainBase = strlen(P::plan('1762', 'x'));
assert_equal(8000, strlen(P::plan('1762', str_repeat('x', 8000 - $plainBase + 1))), 'plan: without a source the old boundary is unchanged (8000 fits)');
$rejects(static fn () => P::plan('1762', str_repeat('x', 8000 - $plainBase + 2)), 'plan: without a source 8001 still throws');
$h = strlen(P::handoff('1')) - 1;
$exactId = str_repeat('9', 8000 - $h);
assert_equal(8000, strlen(P::handoff($exactId)), 'handoff: an id of 8000 - base digits gives exactly 8000 characters, accepted without a source');
$rejects(static fn () => P::handoff($exactId, $src), 'handoff: the same id with a source throws, the paragraph counts toward the 8000 limit');
$rejects(static fn () => P::handoff($exactId . '9'), 'handoff: 8001 characters without a source throws');
$text8000 = str_repeat('a', 8000);
$sc = P::selfContained($text8000, $src);
assert_true(strlen($sc) > 8000 && str_ends_with($sc, "\n\n" . $paraSrc), 'selfContained: 8000 characters of text plus the paragraph is accepted (paragraph not counted)');
assert_contains($text8000, $sc, 'selfContained: the text is kept whole');
$rejects(static fn () => P::selfContained(str_repeat('a', 8001), $src), 'selfContained: 8001 characters of text throws even with a source');
$rejects(static fn () => P::selfContained('', $src), 'selfContained: empty text still throws with a source');
$rejects(static fn () => P::selfContained("  \n ", $src), 'selfContained: whitespace-only text still throws with a source');

// ---------------------------------------------------------------- part 2: FlowService::rotate

$NOW = 2_000_000;
$thresholds = Thresholds::fromFile($dir . '/none.conf');
$srcOf = static fn (string $name, ?string $id, string $agent = 'claude'): array => ['name' => $name, 'id' => $id, 'agent' => $agent];

$track = static function (string $name, array $extra = [], array $status = ['status' => 'idle', 'mode' => 'plan']) use ($UUID): void {
    SidecarStore::write_sidecar($name, $extra + ['workdir' => '/w/' . $name, 'agent_session_id' => $UUID, 'agent' => 'claude', 'runtime' => 'headless', 'profile' => 'work']);
    SessionStatusStore::write_status($name, $status);
};
$factsFor = static fn (string $agent = 'claude', string $profile = 'work'): array => [
    'context_tokens' => 260000, 'idle_seconds' => 60, 'source' => ['agent' => $agent, 'profile' => $profile, 'status' => 'idle'],
];
$make = static function (array $known) use ($NOW, $thresholds): array {
    $log = new stdClass();
    $log->launch = [];
    $log->forbidden = [];
    $n = 0;
    $deps = [
        'clock' => static fn (): int => $NOW,
        'facts' => static fn (string $s, array $row): ?array => $known[$s] ?? null,
        'quota' => static fn (string $k): ?array => null,
        'receivers' => ['codex/main', 'claude/work'],
        'thresholds' => $thresholds,
        'launch' => static function (array $req) use ($log, &$n): array {
            $log->launch[] = $req;
            $n++;
            $name = 'recv-' . $n;
            SidecarStore::write_sidecar($name, ['workdir' => $req['workdir'] ?? null, 'agent' => $req['agent'] ?? 'claude', 'runtime' => 'headless']);

            return ['ok' => true, 'name' => $name, 'message' => ''];
        },
        'runner' => static fn (string $command, string $conv): array => ['exit' => 0, 'stdout' => '[]'],
        'archive' => static function () use ($log): array {
            $log->forbidden[] = 'archive';

            return ['ok' => true];
        },
        'kill' => static function () use ($log): array {
            $log->forbidden[] = 'kill';

            return ['ok' => true];
        },
    ];

    return [$deps, $log];
};

$track('rs-claude');
$track('rs-codex', ['agent' => 'codex', 'profile' => null]);
$track('rs-noid', ['agent_session_id' => null]);
$known = ['rs-claude' => $factsFor(), 'rs-codex' => $factsFor('codex', 'default'), 'rs-noid' => $factsFor()];

echo "rotate: --handoff, --plan and --prompt all carry the source paragraph\n";
[$deps, $log] = $make($known);
$cases = [
    'handoff' => [['handoff_id' => '123'], static fn (array $s) => P::handoff('123', $s)],
    'plan' => [['plan_id' => '456'], static fn (array $s) => P::plan('456', '', $s)],
    'prompt' => [['prompt' => 'Fix the parser, tests are in t/.'], static fn (array $s) => P::selfContained('Fix the parser, tests are in t/.', $s)],
];
foreach ($cases as $label => [$input, $build]) {
    $expected = $build($srcOf('rs-claude', $UUID));
    $launchedBefore = count($log->launch);
    $r = FlowService::rotate(['session' => 'rs-claude', 'dry_run' => true] + $input, $deps);
    assert_equal(true, $r['ok'] ?? null, "dry run --$label: ok");
    assert_equal($expected, $r['prompt'] ?? null, "dry run --$label: the printed prompt is the builder output with the source (name, sidecar id, agent)");
    assert_true(str_ends_with((string) ($r['prompt'] ?? ''), "\n\n" . $para("rs-claude {$UUID} (claude)")), "dry run --$label: ends with the exact paragraph");
    assert_equal($expected, $r['launch']['prompt'] ?? null, "dry run --$label: the launch it would make carries the same prompt");
    assert_equal($launchedBefore, count($log->launch), "dry run --$label: launches nothing");
    $real = FlowService::rotate(['session' => 'rs-claude'] + $input, $deps);
    assert_equal(true, $real['ok'] ?? null, "real --$label: ok");
    assert_equal($expected, $real['prompt'] ?? null, "real --$label: the reply prompt carries the paragraph");
    assert_equal($expected, end($log->launch)['prompt'] ?? null, "real --$label: the receiver is launched with the paragraph");
}
assert_equal([], $log->forbidden, 'rotate: the source is still never archived or killed by rotate itself');

echo "rotate: the source agent comes from the source\n";
[$deps, $log] = $make($known);
$r = FlowService::rotate(['session' => 'rs-codex', 'handoff_id' => '9', 'dry_run' => true], $deps);
assert_equal(P::handoff('9', $srcOf('rs-codex', $UUID, 'codex')), $r['prompt'] ?? null, 'codex source: paragraph names agent codex');
$r = FlowService::rotate(['session' => 'rs-codex', 'handoff_id' => '9', 'agent' => 'claude', 'dry_run' => true], $deps);
assert_equal(P::handoff('9', $srcOf('rs-codex', $UUID, 'codex')), $r['prompt'] ?? null, 'codex source rotated to claude: paragraph still names the source agent, not the receiver');

echo "rotate: a source without a conversation id\n";
[$deps, $log] = $make($known);
$r = FlowService::rotate(['session' => 'rs-noid', 'handoff_id' => '9', 'dry_run' => true], $deps);
assert_equal(true, $r['ok'] ?? null, 'no id: dry run is ok');
assert_equal(P::handoff('9', $srcOf('rs-noid', null)), $r['prompt'] ?? null, 'no id: the paragraph is present without an id');
assert_contains('Source session: rs-noid (claude). Claim your Dibs task first.', (string) ($r['prompt'] ?? ''), 'no id: exact id-less wording');
$r = FlowService::rotate(['session' => 'rs-noid', 'handoff_id' => '9'], $deps);
assert_equal(true, $r['ok'] ?? null, 'no id: the real rotate still launches');
assert_equal(P::handoff('9', $srcOf('rs-noid', null)), end($log->launch)['prompt'] ?? null, 'no id: the launched prompt has the id-less paragraph');

echo "rotate: failures and unchanged behaviour\n";
[$deps, $log] = $make($known);
$r = FlowService::rotate(['session' => 'rs-claude', 'prompt' => str_repeat('x', 8000), 'dry_run' => true], $deps);
assert_equal(true, $r['ok'] ?? null, 'rotate: an 8000 character --prompt with the paragraph appended is still accepted');
assert_true(str_ends_with((string) ($r['prompt'] ?? ''), "\n\n" . $para("rs-claude {$UUID} (claude)")), 'rotate: the long prompt still ends with the paragraph');
$r = FlowService::rotate(['session' => 'rs-claude', 'prompt' => str_repeat('x', 8001), 'dry_run' => true], $deps);
assert_equal(false, $r['ok'] ?? null, 'rotate: 8001 characters is still a handled error');
$r = FlowService::rotate(['session' => 'rs-claude', 'plan_id' => 'abc', 'dry_run' => true], $deps);
assert_equal(false, $r['ok'] ?? null, 'rotate: an invalid plan id is still a handled error with a source');
$r = FlowService::rotate(['session' => 'rs-claude', 'dry_run' => true], $deps);
assert_equal(false, $r['ok'] ?? null, 'rotate: no handoff, plan or prompt is still a handled error');
assert_equal([], $log->launch, 'rotate: nothing invalid launched anything');

test_exit();
