<?php
declare(strict_types=1);

/**
 * The session hierarchy in a real headless Chrome against the canned fake agent (never tmux or a real agent):
 * the dashboard cards (nesting under the parent, indentation, `waiting on` badges and their links, a child whose
 * parent is not listed), the session list popup of the session page (same nesting and badges from the same row
 * fields, the open session pinned on top, status order never splitting a family), and the detail sheet
 * (`Launched by`, `Launched:` and badges as links). Rows come from CANNED_HIERARCHY (see tests/fixtures/canned_agent.php).
 * Written from the Contract only. Not pinned: how the depth cap shows, the popup's markup beyond data-parent,
 * ui-chip-waiting and is-ended on a row. Skips (exit 0) when no Chrome is installed.
 */

require __DIR__ . '/lib/assert.php';
require __DIR__ . '/lib/harness.php';
require __DIR__ . '/lib/free_port.php';
require __DIR__ . '/lib/cdp.php';

const UIH_SESSION = 'cc-20260101-1200';

function uih_wait(array &$page, string $expression, float $timeout = 8.0): bool
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

$scratch = sys_get_temp_dir() . '/sessioneer-test-hierarchy-' . getmypid();
mkdir($scratch, 0700);
$fixture = $scratch . '/hierarchy.json';
$now = time();
$wait = static fn (string $name, string $state = 'live'): array => ['name' => $name, 'state' => $state, 'since' => $now - 600];
file_put_contents($fixture, json_encode([
    'rows' => [
        ['name' => 'kid-one', 'launched_by' => 'par', 'waiting_on' => []],
        ['name' => 'solo', 'launched_by' => null, 'waiting_on' => [$wait('par'), $wait('ghost', 'ended')]],
        ['name' => 'par', 'launched_by' => null, 'waiting_on' => []],
        ['name' => 'kid-two', 'launched_by' => 'par', 'waiting_on' => [$wait('solo')]],
        ['name' => 'grandkid', 'launched_by' => 'kid-one', 'waiting_on' => []],
        ['name' => 'stray', 'launched_by' => 'archived-parent', 'waiting_on' => []],
        ['name' => 'loop-a', 'launched_by' => 'loop-b', 'waiting_on' => []],
        ['name' => 'loop-b', 'launched_by' => 'loop-a', 'waiting_on' => []],
        // the open session of the session page, and a blocked child that status order would float to the top
        ['name' => UIH_SESSION, 'launched_by' => null, 'waiting_on' => []],
        ['name' => 'sort-parent', 'launched_by' => null, 'waiting_on' => []],
        ['name' => 'sort-blocked-kid', 'launched_by' => 'sort-parent', 'waiting_on' => [], 'working' => true, 'blocked_reason' => 'Do you want to proceed?', 'prompt_options' => [['number' => 1, 'label' => 'Yes']]],
    ],
    'detail' => [
        'launched_by' => 'cc-parent', 'launched' => ['cc-kid-1', 'cc-kid-2'],
        'waiting_on' => [$wait('cc-w1'), $wait('cc-w2', 'ended')],
    ],
]));
putenv('CANNED_HIERARCHY=' . $fixture);

$agentSocket = $scratch . '/agent.sock';
$agentHarness = start_harness(['php', __DIR__ . '/fixtures/canned_agent.php'], $agentSocket);
$port = test_free_port();
$server = proc_open(
    ['php', '-d', 'session.save_path=' . $scratch, '-S', "127.0.0.1:{$port}", '-t', dirname(__DIR__) . '/public', dirname(__DIR__) . '/public/index.php'],
    [0 => ['pipe', 'r'], 1 => ['file', '/dev/null', 'w'], 2 => ['file', $scratch . '/server.log', 'w']],
    $pipes,
    null,
    array_merge(getenv(), ['SESSIONEER_AGENT_SOCKET' => $agentSocket])
);
$base = "http://127.0.0.1:{$port}";
$browser = null;
$page = null;

try {
    assert_true(is_resource($server), 'hierarchy: isolated PHP server starts');
    $deadline = microtime(true) + 3.0;
    do {
        $c = @fsockopen('127.0.0.1', $port, $errno, $error, 0.2);
        if (is_resource($c)) {
            fclose($c);
            break;
        }
        usleep(50000);
    } while (microtime(true) < $deadline);

    $chromeBin = cdp_find_chrome();
    if ($chromeBin === null) {
        echo "  SKIP: no headless browser found - this test file has nothing else to check\n";
    } else {
        $browser = cdp_launch($chromeBin);
        $page = $browser !== null ? cdp_open_page($browser) : null;
    }

    if ($page !== null) {
        assert_true(cdp_set_viewport($page, 390, 844, 1.0, true), 'hierarchy: phone viewport set');

        echo "Dashboard\n";
        assert_true(cdp_navigate($page, $base . '/'), 'dashboard: page loads');
        assert_true(uih_wait($page, "document.querySelectorAll('.ui-card[data-session]').length === 11"), 'dashboard: every row renders as a card, none dropped or duplicated');
        $order = cdp_evaluate($page, "Array.prototype.map.call(document.querySelectorAll('.ui-card[data-session]'), function (c) { return c.getAttribute('data-session'); })");
        $at = is_array($order) ? array_flip($order) : [];
        assert_true(isset($at['par'], $at['kid-one'], $at['kid-two'], $at['grandkid']), 'dashboard: fixture cards present');
        assert_equal(['kid-one', 'grandkid', 'kid-two'], is_array($order) ? array_slice($order, ($at['par'] ?? 0) + 1, 3) : null, 'dashboard: the family follows its parent, depth first, then the next top level card');
        assert_equal('par', cdp_evaluate($page, "document.querySelector('.ui-card[data-session=\"kid-one\"]').getAttribute('data-parent')"), 'dashboard: a child card carries data-parent');
        assert_equal('kid-one', cdp_evaluate($page, "document.querySelector('.ui-card[data-session=\"grandkid\"]').getAttribute('data-parent')"), 'dashboard: a grandchild names its direct parent');
        assert_equal(true, cdp_evaluate($page, "document.querySelector('.ui-card[data-session=\"kid-one\"]').classList.contains('ui-card-child')"), 'dashboard: a child card has ui-card-child');
        assert_equal(false, cdp_evaluate($page, "document.querySelector('.ui-card[data-session=\"par\"]').classList.contains('ui-card-child')"), 'dashboard: the parent is not a child card');
        $left = static fn (string $n): float => (float)cdp_evaluate($GLOBALS['page'], "document.querySelector('.ui-card[data-session=\"{$n}\"]').getBoundingClientRect().left");
        $width = static fn (string $n): float => (float)cdp_evaluate($GLOBALS['page'], "document.querySelector('.ui-card[data-session=\"{$n}\"]').getBoundingClientRect().width");
        assert_true($left('kid-one') > $left('par') + 4, 'dashboard: a child is visibly indented from its parent');
        assert_true($left('grandkid') > $left('kid-one') + 4, 'dashboard: a grandchild is indented further');
        assert_true($width('kid-one') < $width('par'), 'dashboard: the indented card is narrower, not pushed off screen');
        assert_equal(true, cdp_evaluate($page, "document.documentElement.scrollWidth <= window.innerWidth + 1"), 'dashboard: nesting causes no horizontal scroll on a phone');
        assert_equal(false, cdp_evaluate($page, "document.querySelector('.ui-card[data-session=\"stray\"]').classList.contains('ui-card-child') || document.querySelector('.ui-card[data-session=\"stray\"]').hasAttribute('data-parent')"), 'dashboard: a child whose parent is not listed stays at the top level');
        assert_equal(1, cdp_evaluate($page, "document.querySelectorAll('.ui-card[data-session=\"loop-a\"], .ui-card[data-session=\"loop-b\"]').length === 2 ? 1 : 0"), 'dashboard: a launch cycle renders both cards once');

        echo "Dashboard badges\n";
        assert_equal(2, cdp_evaluate($page, "document.querySelectorAll('.ui-card[data-session=\"solo\"] .ui-chip-waiting').length"), 'badges: one per target');
        assert_equal('waiting on par', cdp_evaluate($page, "document.querySelector('.ui-card[data-session=\"solo\"] a.ui-chip-waiting[href$=\"session=par\"]').textContent.replace(/\\s+/g, ' ').trim()"), 'badges: the text reads `waiting on NAME`');
        assert_equal('/session?session=par', cdp_evaluate($page, "document.querySelector('.ui-card[data-session=\"solo\"] a.ui-chip-waiting[href$=\"session=par\"]').getAttribute('href')"), 'badges: links to the target session');
        assert_equal(false, cdp_evaluate($page, "document.querySelector('.ui-card[data-session=\"solo\"] a.ui-chip-waiting[href$=\"session=par\"]').classList.contains('is-ended')"), 'badges: a live target is not greyed');
        assert_equal(true, cdp_evaluate($page, "document.querySelector('.ui-card[data-session=\"solo\"] a.ui-chip-waiting[href$=\"session=ghost\"]').classList.contains('is-ended')"), 'badges: an ended target is greyed with is-ended');
        assert_equal(false, cdp_evaluate($page, "document.querySelector('.ui-card[data-session=\"solo\"]').classList.contains('ui-card-child')"), 'badges: waiting without ancestry is not indented');
        assert_equal(0, cdp_evaluate($page, "document.querySelectorAll('.ui-card[data-session=\"kid-one\"] .ui-chip-waiting').length"), 'badges: ancestry without waiting has no badge');
        assert_equal([true, 1], cdp_evaluate($page, "[document.querySelector('.ui-card[data-session=\"kid-two\"]').classList.contains('ui-card-child'), document.querySelectorAll('.ui-card[data-session=\"kid-two\"] .ui-chip-waiting').length]"), 'badges: a card can be both indented and badged');
        $greyed = (string)cdp_evaluate($page, "(function () { var a = document.querySelector('.ui-card[data-session=\"solo\"] a.ui-chip-waiting[href\$=\"session=ghost\"]'), b = document.querySelector('.ui-card[data-session=\"solo\"] a.ui-chip-waiting[href\$=\"session=par\"]'); return getComputedStyle(a).opacity + '|' + getComputedStyle(b).opacity + '|' + getComputedStyle(a).color + '|' + getComputedStyle(b).color; }())");
        [$oa, $ob, $ca, $cb] = array_pad(explode('|', $greyed, 4), 4, '');
        assert_true($oa !== $ob || $ca !== $cb, 'badges: a greyed badge looks different from a live one');
        assert_equal([true, true, true], cdp_evaluate($page, "(function () { var a = document.querySelector('.ui-card[data-session=\"solo\"] a.ui-chip-waiting[href$=\"session=ghost\"]'); return [/ended/.test(a.getAttribute('title') || ''), /ended/.test(a.getAttribute('aria-label') || ''), !/ended/.test(document.querySelector('.ui-card[data-session=\"solo\"] a.ui-chip-waiting[href$=\"session=par\"]').outerHTML.replace(/is-ended/g, ''))]; }())"), 'badges: an ended badge has a title and aria-label with `ended`; a live one has none');
        assert_true(cdp_click($page, '.ui-card[data-session="solo"] a.ui-chip-waiting[href$="session=par"]'), 'badges: user taps a badge');
        assert_true(uih_wait($page, "location.pathname === '/session' && new URLSearchParams(location.search).get('session') === 'par'"), 'badges: the tap opens the target session, not the card\'s own');
        assert_equal([], cdp_drain_console_errors($page), 'dashboard: no console errors');

        echo "Session list popup\n";
        assert_true(cdp_navigate($page, $base . '/session?session=' . UIH_SESSION), 'popup: the session page loads');
        cdp_evaluate($page, "document.querySelector('[data-open=\"sess-sessions\"]').click()");
        assert_true(uih_wait($page, "document.querySelectorAll('#sess-sessions-list .sess-srow').length === 11"), 'popup: every row is listed once');
        $names = "Array.prototype.map.call(document.querySelectorAll('#sess-sessions-list .sess-srow'), function (r) { return decodeURIComponent(r.querySelector('.sess-srow-main').getAttribute('href').split('session=')[1]); })";
        $popup = cdp_evaluate($page, $names);
        $pat = is_array($popup) ? array_flip($popup) : [];
        assert_equal(UIH_SESSION, $popup[0] ?? null, 'popup: the open session stays pinned on top');
        assert_equal(['kid-one', 'grandkid', 'kid-two'], is_array($popup) ? array_slice($popup, ($pat['par'] ?? 0) + 1, 3) : null, 'popup: the family follows its parent');
        assert_equal(['sort-parent', 'sort-blocked-kid'], is_array($popup) ? array_slice($popup, $pat['sort-parent'] ?? 0, 2) : null, 'popup: a blocked child is not floated away from its parent by the status order');
        $row = static fn (string $n): string => "(function () { var rows = document.querySelectorAll('#sess-sessions-list .sess-srow'); for (var i = 0; i < rows.length; i++) { if (decodeURIComponent(rows[i].querySelector('.sess-srow-main').getAttribute('href').split('session=')[1]) === " . json_encode($n) . ") { return rows[i]; } } return null; }())";
        assert_equal('par', cdp_evaluate($page, $row('kid-one') . '.getAttribute("data-parent")'), 'popup: a child row carries data-parent');
        assert_equal('kid-one', cdp_evaluate($page, $row('grandkid') . '.getAttribute("data-parent")'), 'popup: a grandchild names its direct parent');
        assert_equal(null, cdp_evaluate($page, $row('par') . '.getAttribute("data-parent")'), 'popup: a top level row has no data-parent');
        assert_equal(null, cdp_evaluate($page, $row('stray') . '.getAttribute("data-parent")'), 'popup: a child whose parent is not listed is top level');
        $rowLeft = static fn (string $n): float => (float)cdp_evaluate($GLOBALS['page'], $row($n) . '.getBoundingClientRect().left');
        assert_true($rowLeft('kid-one') > $rowLeft('par') + 4, 'popup: a child row is indented');
        assert_true($rowLeft('grandkid') > $rowLeft('kid-one') + 4, 'popup: a grandchild row is indented further');
        assert_equal(2, cdp_evaluate($page, $row('solo') . '.querySelectorAll(".ui-chip-waiting").length'), 'popup: one badge per target');
        assert_equal(true, cdp_evaluate($page, $row('solo') . '.querySelector(".ui-chip-waiting[href$=\"session=ghost\"]").classList.contains("is-ended")'), 'popup: an ended target is greyed');
        assert_equal(false, cdp_evaluate($page, $row('solo') . '.querySelector(".ui-chip-waiting[href$=\"session=par\"]").classList.contains("is-ended")'), 'popup: a live target is not');
        assert_equal([true, true, true], cdp_evaluate($page, "(function () { var a = " . $row('solo') . ".querySelector('.ui-chip-waiting[href$=\"session=ghost\"]'); var b = " . $row('solo') . ".querySelector('.ui-chip-waiting[href$=\"session=par\"]'); return [/ended/.test(a.getAttribute('title') || ''), /ended/.test(a.getAttribute('aria-label') || ''), !/ended/.test(b.getAttribute('title') || '') && !/ended/.test(b.getAttribute('aria-label') || '')]; }())"), 'popup: an ended badge has a title and aria-label with `ended`; a live one has none');
        assert_equal(false, cdp_evaluate($page, $row('solo') . '.hasAttribute("data-parent")'), 'popup: waiting without ancestry is not indented');
        assert_equal(0, cdp_evaluate($page, $row('kid-one') . '.querySelectorAll(".ui-chip-waiting").length'), 'popup: ancestry without waiting has no badge');
        assert_equal([], cdp_drain_console_errors($page), 'popup: no console errors');

        echo "Detail sheet\n";
        assert_true(cdp_navigate($page, $base . '/session?session=' . UIH_SESSION), 'detail: the session page loads');
        cdp_evaluate($page, "document.querySelector('[data-open=\"sess-info\"]').click()");
        assert_true(uih_wait($page, "document.getElementById('sess-info').open && document.getElementById('sess-info-body').textContent.indexOf('Launched by') !== -1"), 'detail: the sheet shows `Launched by`');
        $text = preg_replace('/\s+/', ' ', (string)cdp_evaluate($page, "document.getElementById('sess-info-body').textContent"));
        assert_contains('Launched by cc-parent', $text, 'detail: names the parent');
        assert_contains('Launched: cc-kid-1, cc-kid-2', $text, 'detail: lists the children');
        assert_equal('/session?session=cc-parent', cdp_evaluate($page, "(function () { var a = document.querySelectorAll('#sess-info-body a'); for (var i = 0; i < a.length; i++) { if (a[i].textContent.trim() === 'cc-parent') { return a[i].getAttribute('href'); } } return null; }())"), 'detail: the parent is a link');
        assert_equal('/session?session=cc-kid-2', cdp_evaluate($page, "(function () { var a = document.querySelectorAll('#sess-info-body a'); for (var i = 0; i < a.length; i++) { if (a[i].textContent.trim() === 'cc-kid-2') { return a[i].getAttribute('href'); } } return null; }())"), 'detail: each child is a link');
        assert_equal(2, cdp_evaluate($page, "document.querySelectorAll('#sess-info-body .ui-chip-waiting').length"), 'detail: one badge per target');
        assert_equal('/session?session=cc-w1', cdp_evaluate($page, "document.querySelector('#sess-info-body a.ui-chip-waiting').getAttribute('href')"), 'detail: a badge links to its target');
        assert_equal(true, cdp_evaluate($page, "document.querySelector('#sess-info-body a.ui-chip-waiting[href$=\"session=cc-w2\"]').classList.contains('is-ended')"), 'detail: an ended target is greyed');
        assert_equal([true, true], cdp_evaluate($page, "(function () { var a = document.querySelector('#sess-info-body a.ui-chip-waiting[href$=\"session=cc-w2\"]'); return [/ended/.test(a.getAttribute('title') || ''), /ended/.test(a.getAttribute('aria-label') || '')]; }())"), 'detail: an ended badge has a title and aria-label with `ended`');
        assert_equal(false, cdp_evaluate($page, "/ended/.test(document.querySelector('#sess-info-body a.ui-chip-waiting[href$=\"session=cc-w1\"]').outerHTML.replace(/is-ended/g, ''))"), 'detail: a live badge has no `ended` text');
        assert_equal([], cdp_drain_console_errors($page), 'detail: no console errors');

        // The same session with no hierarchy data shows none of it.
        file_put_contents($fixture, json_encode(['rows' => [['name' => UIH_SESSION, 'launched_by' => null, 'waiting_on' => []]], 'detail' => ['launched_by' => null, 'launched' => [], 'waiting_on' => []]]));
        assert_true(cdp_navigate($page, $base . '/session?session=' . UIH_SESSION), 'detail without hierarchy: the page loads');
        cdp_evaluate($page, "document.querySelector('[data-open=\"sess-info\"]').click()");
        assert_true(uih_wait($page, "document.getElementById('sess-info').open && document.getElementById('sess-info-body').textContent.length > 0"), 'detail without hierarchy: the sheet renders');
        $text = (string)cdp_evaluate($page, "document.getElementById('sess-info-body').textContent");
        assert_true(!str_contains($text, 'Launched by') && !str_contains($text, 'Launched:') && !str_contains($text, 'waiting on'), 'detail without hierarchy: no Launched or waiting text');
        assert_equal(0, cdp_evaluate($page, "document.querySelectorAll('#sess-info-body .ui-chip-waiting').length"), 'detail without hierarchy: no badge');

        echo "Dashboard with no hierarchy\n";
        assert_true(cdp_navigate($page, $base . '/'), 'plain dashboard: page loads');
        assert_true(uih_wait($page, "document.querySelectorAll('.ui-card[data-session]').length === 1"), 'plain dashboard: renders');
        assert_equal(0, cdp_evaluate($page, "document.querySelectorAll('.ui-card-child, .ui-chip-waiting').length"), 'plain dashboard: no child cards and no badges');
        assert_equal([], cdp_drain_console_errors($page), 'plain dashboard: no console errors');
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
    stop_harness($agentHarness, $agentSocket);
    foreach (glob($scratch . '/*') ?: [] as $file) {
        unlink($file);
    }
    rmdir($scratch);
}

test_exit();
