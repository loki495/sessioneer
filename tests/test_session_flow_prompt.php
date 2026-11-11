<?php

declare(strict_types=1);

// Step 3 of session-flow: HostAgent\SessionFlow\PromptBuilder (pure, static prompt
// builders with strict input validation). No I/O, no agent. Written before the
// implementation: fails until the class exists.

$autoload = __DIR__ . '/../vendor/autoload.php';
if (is_file($autoload)) {
    require $autoload;
} else {
    spl_autoload_register(static function (string $c): void {
        $f = __DIR__ . '/../host-agent/lib/' . str_replace('\\', '/', substr($c, strlen('HostAgent\\'))) . '.php';
        if (str_starts_with($c, 'HostAgent\\') && is_file($f)) {
            require $f;
        }
    });
}
require __DIR__ . '/lib/assert.php';

use HostAgent\SessionFlow\PromptBuilder as P;

/** Asserts the callable throws InvalidArgumentException. */
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

$uuid = '123e4567-e89b-12d3-a456-426614174000';
$bad = [
    'double quote' => 'a"b', 'single quote' => "a'b", 'newline' => "a\nb", 'carriage return' => "a\rb",
    'backtick' => 'a`b',
];

echo "plan()\n";
$p = P::plan('1762');
assert_contains('1762', $p, 'plan: names the plan id');
assert_contains('todo_next', $p, 'plan: tells receiver to continue from todo_next');
assert_contains('Dibs', $p, 'plan: mentions Dibs');
assert_true(!str_starts_with(ltrim($p), '/'), 'plan: prompt does not start with a slash command');
$pn = P::plan('1762', 'step 3 is next');
assert_contains('step 3 is next', $pn, 'plan: includes the step note');
assert_equal(P::plan('1762'), P::plan('1762', ''), 'plan: empty note equals no note');
assert_true(!str_contains($p, 'step 3 is next'), 'plan: no note leaks without one');
$rejects(static fn () => P::plan(''), 'plan: empty id throws');
$rejects(static fn () => P::plan('12a'), 'plan: non-digit id throws');
$rejects(static fn () => P::plan('-1'), 'plan: negative id throws');
$rejects(static fn () => P::plan('12 34'), 'plan: id with space throws');
$rejects(static fn () => P::plan("12\n34"), 'plan: id with newline throws');
$rejects(static fn () => P::plan('1762; rm -rf ~'), 'plan: shell metacharacters in id throw');
$rejects(static fn () => P::plan('1762', "ok\n/takeover x"), 'plan: newline in note throws (slash command smuggling)');
$rejects(static fn () => P::plan('1762', "ok\r/rotate"), 'plan: carriage return in note throws');
foreach ($bad as $k => $v) {
    $rejects(static fn () => P::plan('1762', $v), "plan: note with $k throws");
}
$note = str_repeat('n', 8000);
$rejects(static fn () => P::plan('1762', $note), 'plan: result over 8000 chars throws');
$ok = P::plan('1762', str_repeat('n', 7000));
assert_true(strlen($ok) <= 8000, 'plan: result at or under 8000 is returned');

echo "handoff()\n";
$h = P::handoff('1800');
assert_contains('1800', $h, 'handoff: names the task id');
assert_contains('claim', strtolower($h), 'handoff: tells receiver to claim');
assert_contains('re-verify', strtolower($h), 'handoff: re-verify the State');
foreach (['git', 'tests', 'Dibs'] as $w) {
    assert_contains($w, $h, "handoff: verification mentions $w");
}
assert_contains('Decisions made', $h, 'handoff: ask only what is not under Decisions made');
assert_contains('Next steps', $h, 'handoff: continue its Next steps');
assert_contains('archive', strtolower($h), 'handoff: archive the source');
assert_contains('claim', strtolower(substr($h, (int) stripos($h, 'archive'))), 'handoff: archive condition is about claims');
assert_true(!str_starts_with(ltrim($h), '/'), 'handoff: no leading slash command');
$rejects(static fn () => P::handoff(''), 'handoff: empty id throws');
$rejects(static fn () => P::handoff('abc'), 'handoff: non-digit id throws');
$rejects(static fn () => P::handoff('18 00'), 'handoff: space in id throws');
$rejects(static fn () => P::handoff("1800\n/takeover 1"), 'handoff: newline-smuggled slash command throws');
$rejects(static fn () => P::handoff('1800$(id)'), 'handoff: command substitution throws');
$rejects(static fn () => P::handoff('1800`id`'), 'handoff: backtick throws');

echo "takeover()\n";
$old = 'Use the global /takeover skill for source claude abc123. Read the entire source conversation and Dibs state, transfer its work and claims safely, then archive the old session only when it is idle, claim-free, and a cold session can continue from Dibs. Continue its work.';
$t = P::takeover('abc123');
assert_contains('Use the global /takeover skill for source ', $t, 'takeover: old opening wording');
assert_contains('abc123', $t, 'takeover: names the source');
$tail = 'Read the entire source conversation and Dibs state, transfer its work and claims safely, then archive the old session only when it is idle, claim-free, and a cold session can continue from Dibs. Continue its work.';
assert_contains($tail, $t, 'takeover: old body wording is identical');
assert_true(str_ends_with($t, 'Continue its work.'), 'takeover: no transcript, ends with the old text');
assert_true(!str_contains($t, 'Source transcript'), 'takeover: no transcript sentence without a path');
$tp = P::takeover($uuid, '/home/a/.claude/projects/x/s.jsonl');
assert_contains($uuid, $tp, 'takeover: accepts a UUID source');
assert_true(str_ends_with($tp, ' Source transcript: /home/a/.claude/projects/x/s.jsonl.'), 'takeover: transcript appended exactly');
assert_contains($tail, $tp, 'takeover: old body kept with transcript');
$tf = P::takeover('abc123', null, 'work');
assert_contains('work', $tf, 'takeover: profile in the slash arguments');
assert_true(strpos($tf, 'work') < strpos($tf, 'abc123'), 'takeover: profile precedes the id in the source argument');
assert_true(strpos($tf, 'work') < strpos($tf, 'Read the entire'), 'takeover: profile is inside the slash arguments, before the body');
assert_contains('ses_abc123XYZ', P::takeover('ses_abc123XYZ'), 'takeover: accepts a ses_ token');
assert_contains('ABCDEF0123', P::takeover('ABCDEF0123'), 'takeover: accepts uppercase hex');
$rejects(static fn () => P::takeover(''), 'takeover: empty source throws');
$rejects(static fn () => P::takeover('xyz!'), 'takeover: bad source chars throw');
$rejects(static fn () => P::takeover('not-hex-zzz'), 'takeover: non-hex non-ses_ source throws');
$rejects(static fn () => P::takeover('ses_'), 'takeover: bare ses_ prefix throws');
$rejects(static fn () => P::takeover('ses_a b'), 'takeover: ses_ token with space throws');
$rejects(static fn () => P::takeover("abc123\n/rotate"), 'takeover: newline in source throws');
$rejects(static fn () => P::takeover('abc123; rm -rf ~'), 'takeover: shell metacharacters in source throw');
$rejects(static fn () => P::takeover('abc123', ''), 'takeover: empty transcript path throws');
foreach ($bad as $k => $v) {
    $rejects(static fn () => P::takeover('abc123', '/tmp/' . $v . '.jsonl'), "takeover: path with $k throws");
}
foreach (['w ork', 'w/ork', 'w;ork', 'w$ork', "w\nork", 'w"ork', ''] as $v) {
    $rejects(static fn () => P::takeover('abc123', null, $v), 'takeover: profile ' . json_encode($v) . ' throws');
}
$rejects(static fn () => P::takeover('abc123', '/tmp/' . str_repeat('p', 8000) . '.jsonl'), 'takeover: result over 8000 throws');

echo "rotateRequest() and textInstruction()\n";
$r = P::rotateRequest('codex', 'gpt-5', 'high', 'work');
assert_true(str_starts_with($r, '/rotate'), 'rotateRequest: begins with /rotate');
foreach (['codex', 'gpt-5', 'high', 'work'] as $w) {
    assert_contains($w, $r, "rotateRequest: carries $w");
}
assert_true(!str_contains($r, "\n"), 'rotateRequest: single line');
$rn = P::rotateRequest('claude', null, null, null);
assert_true(str_starts_with($rn, '/rotate'), 'rotateRequest: nulls still begin with /rotate');
assert_contains('claude', $rn, 'rotateRequest: receiver agent present with nulls');
assert_true(!str_contains($rn, 'null') && !str_contains(strtolower($rn), 'none'), 'rotateRequest: null args are omitted, not printed');
$tx = P::textInstruction('codex', 'gpt-5', 'high', 'work');
assert_true(!str_starts_with(ltrim($tx), '/'), 'textInstruction: no slash command');
foreach (['codex', 'gpt-5', 'high', 'work'] as $w) {
    assert_contains($w, $tx, "textInstruction: carries $w");
}
assert_contains('handoff', strtolower($tx), 'textInstruction: asks for a handoff');
assert_contains('rotate', strtolower($tx), 'textInstruction: asks to rotate');
$txn = P::textInstruction('codex', null, null, null);
assert_contains('codex', $txn, 'textInstruction: nulls ok');
assert_true(!str_contains($txn, 'null'), 'textInstruction: nulls omitted');
foreach (['rotateRequest' => [P::class, 'rotateRequest'], 'textInstruction' => [P::class, 'textInstruction']] as $name => $fn) {
    $rejects(static fn () => $fn('', null, null, null), "$name: empty receiver throws");
    $rejects(static fn () => $fn('co dex', null, null, null), "$name: space in receiver throws");
    $rejects(static fn () => $fn('codex', 'gpt 5', null, null), "$name: space in model throws");
    $rejects(static fn () => $fn('codex', "m\n/takeover 1", null, null), "$name: slash command smuggled in model throws");
    $rejects(static fn () => $fn('codex', null, 'high;ls', null), "$name: metacharacter in effort throws");
    $rejects(static fn () => $fn('codex', null, null, '$(id)'), "$name: command substitution in profile throws");
    $rejects(static fn () => $fn('codex', null, null, 'a`b'), "$name: backtick in profile throws");
    $rejects(static fn () => $fn('codex', '', null, null), "$name: empty (non-null) model throws");
    $rejects(static fn () => $fn('codex', str_repeat('m', 8000), null, null), "$name: result over 8000 throws");
    $ok = $fn('codex', 'claude-opus-4.5:fast_1', 'x-high', 'p.1');
    assert_contains('claude-opus-4.5:fast_1', $ok, "$name: allowed charset . : - _ accepted");
}

echo "selfContained()\n";
assert_contains('Fix the bug in foo.', P::selfContained('Fix the bug in foo.'), 'selfContained: keeps the text');
$multi = "Line one.\nLine two with `code` and \"quotes\".";
assert_contains($multi, P::selfContained($multi), 'selfContained: free text may hold newlines, quotes, backticks');
assert_true(strlen(P::selfContained(str_repeat('a', 8000))) >= 8000, 'selfContained: 8000 chars accepted');
$rejects(static fn () => P::selfContained(str_repeat('a', 8001)), 'selfContained: 8001 chars throws');
$rejects(static fn () => P::selfContained(''), 'selfContained: empty text throws');
$rejects(static fn () => P::selfContained("  \n "), 'selfContained: whitespace-only throws');

echo "Length boundary on returned prompts\n";
$base = strlen(P::plan('1762', 'x'));
$fit = P::plan('1762', str_repeat('x', 8000 - $base + 1));
assert_equal(8000, strlen($fit), 'plan: exactly 8000 chars returned');
$rejects(static fn () => P::plan('1762', str_repeat('x', 8000 - $base + 2)), 'plan: 8001 chars throws');

test_exit();
