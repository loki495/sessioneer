<?php
declare(strict_types=1);

/**
 * Step 7 of session-flow: the flow band in a real headless Chrome against the real PHP front controller and the
 * canned agent (CANNED_FLOW_STATE puts a stored overlay on the canned session and logs every flow_* request that
 * reaches the host). No real agent, no model turn, no paid call. Written from the Contract only. Intent notes:
 *  - a stored offer shows the band on page load and nothing is sent to the host until someone taps (no model turn);
 *  - each button posts the Contract's action through the real CSRF check: start -> flow_rotate with the offer's
 *    handoff id (then the page follows the redirect to the receiver), handoff and takeover without a handoff id ->
 *    flow_rotate_request, judge -> flow_judge, continue and wait -> flow_offer_dismiss, edit -> the New Session form
 *    opens prefilled; a tap with a wrong CSRF token never reaches the host;
 *  - the session page carries the same band twice (under the conversation and in the details sheet) and they post the same;
 *  - the band is readable on a phone (no horizontal overflow, touch-sized buttons) and in both themes, WARM_TIGHT is a
 *    chip with no button, escaped text stays text, and the console stays clean.
 * Skips (exit 0) when no Chrome is installed.
 */

require __DIR__ . '/lib/assert.php';
require __DIR__ . '/lib/harness.php';
require __DIR__ . '/lib/free_port.php';
require __DIR__ . '/lib/http.php';
require __DIR__ . '/lib/cdp.php';

const FBB_SESSION = 'cc-20260101-1200';

function fbb_wait(array &$page, string $expression, float $timeout = 8.0): bool
{
    $deadline = microtime(true) + $timeout;

    do {
        if (cdp_evaluate($page, $expression) === true) {
            return true;
        }

        usleep(100000);
    } while (microtime(true) < $deadline);

    return cdp_evaluate($page, $expression) === true;
}

/** @return list<array<string, mixed>> the flow_* requests the host saw since the log was last cleared */
function fbb_log(string $path): array
{
    $lines = is_file($path) ? (file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: []) : [];

    return array_map(static fn (string $l): array => json_decode($l, true) ?: [], $lines);
}

/** Waits up to $timeout seconds for the log to hold at least one request. */
function fbb_logged(string $path, float $timeout = 6.0): array
{
    $deadline = microtime(true) + $timeout;

    do {
        $log = fbb_log($path);
        if ($log !== []) {
            return $log;
        }

        usleep(100000);
    } while (microtime(true) < $deadline);

    return [];
}

$decision = static fn (string $state, array $over = []): array => $over + [
    'state' => $state, 'why' => "why {$state}", 'trigger' => 'cold_cache', 'close_call' => false,
    'receiver' => null, 'reset_at' => null, 'needs_approval' => false, 'warnings' => [],
];
$offer = static fn (array $over = []): array => $over + [
    'session_name' => FBB_SESSION, 'origin' => 'agent', 'handoff_id' => null, 'plan_id' => null, 'prompt' => null,
    'decision' => $decision('ROTATE'),
];

$root = sys_get_temp_dir() . '/sessioneer-test-flow-band-' . getmypid();
mkdir($root, 0700, true);
$state = $root . '/flow.json';
$log = $root . '/flow.log';
$setState = static function (array $flow, ?array $offer = null) use ($state, $log): void {
    file_put_contents($state, json_encode(['flow' => $flow, 'offer' => $offer, 'log' => $log]));
    file_put_contents($log, '');
};
$setState($decision('ROTATE'), $offer(['handoff_id' => '4486', 'prompt' => 'Use the handoff 4486']));
putenv('CANNED_FLOW_STATE=' . $state);

$socket = $root . '/agent.sock';
$harness = start_harness(['php', __DIR__ . '/fixtures/canned_agent.php'], $socket);
$port = test_free_port();
$base = 'http://127.0.0.1:' . $port;
$server = proc_open(
    ['php', '-d', 'session.save_path=' . $root, '-S', '127.0.0.1:' . $port, '-t', dirname(__DIR__) . '/public', dirname(__DIR__) . '/public/index.php'],
    [0 => ['file', '/dev/null', 'r'], 1 => ['file', $root . '/server.log', 'a'], 2 => ['file', $root . '/server.log', 'a']],
    $pipes,
    null,
    array_merge(getenv(), ['SESSIONEER_AGENT_SOCKET' => $socket])
);
$browser = $page = null;

try {
    $deadline = microtime(true) + 3.0;

    while (microtime(true) < $deadline && !($c = @fsockopen('127.0.0.1', $port, $e, $s, 0.2))) {
        usleep(50000);
    }

    $chrome = cdp_find_chrome();
    $browser = $chrome === null ? null : cdp_launch($chrome);
    $page = $browser === null ? null : cdp_open_page($browser);

    if ($page === null) {
        echo "  SKIP: no headless browser found - nothing else to check\n";
    } else {
        $card = static fn (string $act): string => '[data-flow-where="card"] [data-flow-act="' . $act . '"]';
        $click = static fn (string $selector): string => '(function () { var b = document.querySelector(' . json_encode($selector) . '); if (!b) { return false; } b.click(); return true; }())';
        $load = static function (string $path) use (&$page, $base): bool {
            return cdp_navigate($page, $base . $path);
        };
        $acts = static function (string $scope) use (&$page): mixed {
            return cdp_evaluate($page, 'Array.prototype.map.call(document.querySelectorAll(' . json_encode($scope . ' [data-flow-act]') . '), function (b) { return b.getAttribute("data-flow-act"); }).sort()');
        };
        // A reload can still show the previous state's band for a moment (a stale response or a navigation
        // that has not committed yet), so a state change must wait for the new state's own actions, not for
        // any band. The expression builds and sorts the card's actions in the page and compares that JSON to
        // the expected sorted set, so only a band carrying exactly $want satisfies it.
        $awaitActs = static function (array $want) use (&$page): bool {
            sort($want);

            return fbb_wait(
                $page,
                'JSON.stringify(Array.prototype.map.call(document.querySelectorAll("[data-flow-where=card] [data-flow-act]"), function (b) { return b.getAttribute("data-flow-act"); }).sort()) === ' . json_encode(json_encode($want))
            );
        };

        echo "stored offer: the band shows with no model turn (phone)\n";
        cdp_set_viewport($page, 390, 844, 1.0, true);
        assert_true($load('/'), 'the dashboard loads');
        assert_true(fbb_wait($page, 'document.querySelectorAll("[data-flow-where=card] [data-flow-act]").length > 0'), 'the card carries the band');
        assert_equal(['continue', 'edit', 'start', 'takeover'], $acts('[data-flow-where="card"]'), 'an offer with a handoff: Start receiver, Take over, Continue, Edit (no Hand off and start)');
        assert_contains('why ROTATE', (string)cdp_evaluate($page, 'document.querySelector("[data-flow-where=card]").textContent'), 'the reason is shown');
        sleep(1);
        assert_equal([], fbb_log($log), 'nothing reached the host on load and after a poll: no model turn, no flow request');
        assert_equal(true, cdp_evaluate($page, 'document.documentElement.scrollWidth <= window.innerWidth'), 'the band fits a 390px screen');
        assert_equal(true, cdp_evaluate($page, 'Array.prototype.every.call(document.querySelectorAll("[data-flow-where=card] [data-flow-act]"), function (b) { var r = b.getBoundingClientRect(); return r.height >= 32 && r.width > 0 && r.right <= window.innerWidth && r.left >= 0; })'), 'every button is touch-sized and inside the screen');

        echo "a tap with a wrong CSRF token never reaches the host\n";
        cdp_evaluate($page, 'Array.prototype.forEach.call(document.querySelectorAll("[data-csrf]"), function (el) { el.setAttribute("data-csrf", "wrong"); })');
        cdp_evaluate($page, $click($card('continue')));
        sleep(2);
        assert_equal([], fbb_log($log), 'continue with a wrong token: the server refuses it before the host sees it');

        echo "continue and wait dismiss the offer\n";
        $load('/');
        assert_true(fbb_wait($page, '(function () { var host = document.querySelector("[data-flow-where=card]"); return host !== null && host.getAttribute("data-csrf") !== "wrong"; }())'), 'the reload restores the real CSRF token');
        assert_true((bool)cdp_evaluate($page, $click($card('continue'))), 'Continue here is tappable');
        $entries = fbb_logged($log);
        assert_equal('flow_offer_dismiss', $entries[0]['action'] ?? null, 'continue posts flow_offer_dismiss');
        assert_equal(FBB_SESSION, $entries[0]['self'] ?? null, 'it names the session the host dismisses the offer of');
        assert_equal(1, count($entries), 'one tap, one request');

        echo "edit opens the New Session form prefilled\n";
        $setState($decision('ROTATE'), $offer(['handoff_id' => '4486', 'prompt' => 'Use the handoff 4486']));
        $load('/');
        fbb_wait($page, 'document.querySelectorAll("[data-flow-where=card] [data-flow-act]").length > 0');
        cdp_evaluate($page, $click($card('edit')));
        assert_true(fbb_wait($page, 'document.getElementById("ui-new-dialog").open === true'), 'the New Session dialog opens');
        assert_true(fbb_wait($page, 'document.getElementById("ui-new-prompt").value.length > 0'), 'with a first message filled in');
        assert_equal([], fbb_log($log), 'edit sends nothing to the host');

        echo "start receiver posts the handoff and follows the redirect\n";
        $load('/');
        fbb_wait($page, 'document.querySelectorAll("[data-flow-where=card] [data-flow-act]").length > 0');
        cdp_evaluate($page, $click($card('start')));
        $entries = fbb_logged($log);
        assert_equal('flow_rotate', $entries[0]['action'] ?? null, 'start posts flow_rotate');
        assert_equal('4486', (string)($entries[0]['handoff_id'] ?? ''), 'with the offer\'s handoff id');
        assert_equal(FBB_SESSION, $entries[0]['session'] ?? null, 'for the source session');
        assert_true(fbb_wait($page, 'location.pathname === "/session" && location.search.indexOf("cc-fake-headless") !== -1'), 'the page goes to the receiver the host reports');

        echo "hand off and take over, with no handoff id, ask the source for it\n";
        foreach (['handoff', 'takeover'] as $act) {
            $setState($decision('ROTATE'), null);
            $load('/');
            assert_true($awaitActs(['continue', 'edit', 'handoff', 'takeover']), "$act: the card band shows the state just written");
            assert_equal(['continue', 'edit', 'handoff', 'takeover'], $acts('[data-flow-where="card"]'), "$act: no offer, so Hand off and start rather than Start receiver");
            cdp_evaluate($page, $click($card($act)));
            $entries = fbb_logged($log);
            assert_equal('flow_rotate_request', $entries[0]['action'] ?? null, "$act posts flow_rotate_request (one turn to the source)");
            assert_equal(FBB_SESSION, $entries[0]['session'] ?? null, "$act: for the source session");
            assert_equal(1, count($entries), "$act: one request");
        }

        echo "ask a small model posts flow_judge\n";
        $close = $decision('ROTATE', ['trigger' => 'quota', 'close_call' => true, 'reset_at' => time() + 600, 'receiver' => ['key' => 'codex/main', 'agent' => 'codex', 'profile' => 'main']]);
        $setState($close, null);
        $load('/');
        assert_true($awaitActs(['continue', 'edit', 'handoff', 'judge', 'takeover']), 'judge: the card band shows the state just written');
        assert_true(in_array('judge', $acts('[data-flow-where="card"]'), true), 'a close quota call offers the model button');
        cdp_evaluate($page, $click($card('judge')));
        $entries = fbb_logged($log);
        assert_equal('flow_judge', $entries[0]['action'] ?? null, 'judge posts flow_judge');
        assert_equal(FBB_SESSION, $entries[0]['session'] ?? null, 'for the source session');
        assert_equal(1, count($entries), 'one request, the host answers without a second call');

        echo "PARK waits, WARM_TIGHT is a chip\n";
        $setState($decision('PARK', ['trigger' => 'quota', 'reset_at' => time() + 7200]), null);
        $load('/');
        assert_true($awaitActs(['wait']), 'PARK: the card band shows the state just written');
        assert_true(in_array('wait', $acts('[data-flow-where="card"]'), true) && !in_array('start', $acts('[data-flow-where="card"]'), true), 'PARK offers Wait for reset and no rotate');
        cdp_evaluate($page, $click($card('wait')));
        $entries = fbb_logged($log);
        assert_equal('flow_offer_dismiss', $entries[0]['action'] ?? null, 'wait posts flow_offer_dismiss');
        $setState($decision('WARM_TIGHT', ['trigger' => 'context']), null);
        $load('/');
        assert_true(fbb_wait($page, 'document.querySelector("[data-flow-chip]") !== null'), 'WARM_TIGHT shows the chip');
        assert_equal(0, cdp_evaluate($page, 'document.querySelectorAll("[data-flow-act]").length'), 'and no button');
        $setState($decision('GO'), null);
        $load('/');
        usleep(500000);
        assert_equal(0, cdp_evaluate($page, 'document.querySelectorAll("[data-flow-host], [data-flow-chip]").length'), 'GO shows nothing');

        echo "text from the host stays text\n";
        $setState($decision('ROTATE', ['why' => '<img src=x onerror="window.__flowXss=1">']), null);
        $load('/');
        fbb_wait($page, 'document.querySelectorAll("[data-flow-where=card] [data-flow-act]").length > 0');
        usleep(300000);
        assert_equal(null, cdp_evaluate($page, 'window.__flowXss === undefined ? null : window.__flowXss'), 'the reason never runs as markup');
        assert_contains('<img src=x', (string)cdp_evaluate($page, 'document.querySelector("[data-flow-where=card]").textContent'), 'it is shown as text');

        echo "session page: the same band twice, both themes, both widths\n";
        $setState($decision('ROTATE'), $offer(['handoff_id' => '4486', 'prompt' => 'Use the handoff 4486']));
        foreach ([[390, true], [1280, false]] as [$width, $mobile]) {
            cdp_set_viewport($page, $width, 900, 1.0, $mobile);
            foreach (['light', 'dark'] as $theme) {
                $load('/session?session=' . FBB_SESSION);
                cdp_evaluate($page, 'document.documentElement.setAttribute("data-theme", ' . json_encode($theme) . ')');
                assert_true(fbb_wait($page, 'document.querySelector("[data-flow-where=session] [data-flow-act]") !== null'), "$theme $width: the conversation column carries the band");
                assert_equal(1, cdp_evaluate($page, 'document.querySelectorAll("[data-flow-where=popup]").length'), "$theme $width: the details sheet carries one too");
                assert_equal(true, cdp_evaluate($page, 'document.documentElement.scrollWidth <= window.innerWidth'), "$theme $width: no horizontal overflow");
                assert_equal(true, cdp_evaluate($page, 'Array.prototype.every.call(document.querySelectorAll("[data-flow-where=session] [data-flow-act]"), function (b) { var r = b.getBoundingClientRect(); return r.height >= 32 && r.right <= window.innerWidth; })'), "$theme $width: buttons are touch-sized and inside the screen");
                assert_equal(true, cdp_evaluate($page, 'Array.prototype.every.call(document.querySelectorAll("[data-flow-where=session] [data-flow-act]"), function (b) { var c = getComputedStyle(b); return c.color !== c.backgroundColor; })'), "$theme $width: button text differs from its background");
            }
        }
        $setState($decision('ROTATE'), $offer(['handoff_id' => '4486', 'prompt' => 'Use the handoff 4486']));
        $load('/session?session=' . FBB_SESSION);
        fbb_wait($page, 'document.querySelector("[data-flow-where=session] [data-flow-act=continue]") !== null');
        cdp_evaluate($page, $click('[data-flow-where="session"] [data-flow-act="continue"]'));
        $entries = fbb_logged($log);
        assert_equal(['flow_offer_dismiss', FBB_SESSION], [$entries[0]['action'] ?? null, $entries[0]['self'] ?? null], 'session page: continue dismisses the offer of this session');
        file_put_contents($log, '');
        $load('/session?session=' . FBB_SESSION);
        fbb_wait($page, 'document.querySelector("[data-flow-where=popup] [data-flow-act=start]") !== null');
        cdp_evaluate($page, $click('[data-flow-where="popup"] [data-flow-act="start"]'));
        $entries = fbb_logged($log);
        assert_equal(['flow_rotate', '4486'], [$entries[0]['action'] ?? null, (string)($entries[0]['handoff_id'] ?? '')], 'session page: the details sheet band starts the receiver with the same handoff');

        assert_equal([], cdp_drain_console_errors($page), 'the console stays clean through every state');
    }
} finally {
    if ($page !== null) {
        cdp_close_page($page);
    }

    if ($browser !== null) {
        cdp_shutdown($browser);
    }

    if (is_resource($server)) {
        proc_terminate($server);
        proc_close($server);
    }

    stop_harness($harness, $socket);
    exec('rm -rf ' . escapeshellarg($root));
}

test_exit();
