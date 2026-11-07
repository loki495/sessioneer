<?php

declare(strict_types=1);

// The Continue/Start-new button shows only for a cold session (idle >= 1h) or a profile/agent quota >= 90%.
// Pure view logic: no agent socket, the quota reply is a stub.

require __DIR__ . '/../vendor/autoload.php';

use App\Views\UiView;

$fail = 0;
$check = static function (bool $ok, string $what) use (&$fail): void {
    echo ($ok ? 'PASS ' : 'FAIL ') . $what . "\n";
    $fail += $ok ? 0 : 1;
};

$row = static fn (array $o = []): array => $o + ['agent' => 'claude', 'profile' => 'personal', 'activity' => time() - 60];
$calls = 0;
$quota = static function (array $claude, array $codex = []) use (&$calls): callable {
    return static function () use ($claude, &$calls, $codex): array {
        $calls++;

        return ['claude_profiles' => ['personal' => ['quota' => $claude], 'work' => ['quota' => []]], 'agents' => ['codex' => ['quota' => $codex]]];
    };
};

$check(!UiView::advise_visible($row(), $quota([])), 'fresh and quota left: hidden');
$check(!UiView::advise_visible($row(), null), 'fresh and no quota reader: hidden');
$check(UiView::advise_visible($row(['activity' => time() - 3600]), $quota([])), 'exactly 1h old: shown');
$calls = 0;
UiView::advise_visible($row(['activity' => time() - 7200]), $quota([]));
$check($calls === 0, 'cold session does not fetch quota');
$check(UiView::advise_visible($row(), $quota(['session' => ['pct' => 90]])), 'session window at 90%: shown');
$check(UiView::advise_visible($row(), $quota(['week_all' => ['pct' => 95]])), 'weekly window over 90%: shown');
$check(!UiView::advise_visible($row(), $quota(['session' => ['pct' => 89]])), '89%: hidden');
$check(!UiView::advise_visible($row(['profile' => 'work']), $quota(['session' => ['pct' => 99]])), 'another profile\'s quota is ignored');
$check(UiView::advise_visible($row(['agent' => 'codex']), $quota([], ['session' => ['pct' => 92]])), 'codex uses the codex quota');

$card = static fn (array $o): string => UiView::card_html($o + ['name' => 'c1', 'agent' => 'claude', 'runtime' => 'headless', 'status' => 'idle', 'activity' => time() - 60], 'x', $quota([]));
$check(!str_contains($card([]), 'data-advise-open'), 'card: fresh session has no button');
$check(str_contains($card(['activity' => time() - 7200]), 'data-advise-open'), 'card: cold session has the button');

exit($fail ? 1 : 0);
