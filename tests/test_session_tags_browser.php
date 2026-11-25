<?php
declare(strict_types=1);

/**
 * Session tags in a real headless Chrome against the canned fake agent (never tmux or a real agent). Written from the
 * Contract only. Intent notes:
 *  - dashboard: cards show their tag chips and `data-tags`, the tag filter lists the distinct tags;
 *  - session page: chips in the `.sess-hier` strip;
 *  - details sheet (drawn by JavaScript, so only checkable here): a Tags row naming the tags and an `Edit tags` action,
 *    the action also for a session with no tags yet.
 * Not pinned: the edit dialog or the look of an empty Tags row. Rows come from CANNED_HIERARCHY (see tests/fixtures/canned_agent.php). Skips (exit 0) without Chrome.
 */

require __DIR__ . '/lib/assert.php';
require __DIR__ . '/lib/harness.php';
require __DIR__ . '/lib/free_port.php';
require __DIR__ . '/lib/cdp.php';

const UIT_SESSION = 'cc-20260101-1200';

function uit_wait(array &$page, string $expression, float $timeout = 8.0): bool
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

$scratch = sys_get_temp_dir() . '/sessioneer-test-tags-browser-' . getmypid();
mkdir($scratch, 0700);
$fixture = $scratch . '/tags.json';
file_put_contents($fixture, json_encode([
    'rows' => [
        ['name' => UIT_SESSION, 'tags' => ['worker', 'project x']],
        ['name' => 'other', 'tags' => ['runner']],
        ['name' => 'plain', 'tags' => []],
    ],
    'detail' => ['tags' => ['worker', 'project x']],
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
    assert_true(is_resource($server), 'tags: isolated PHP server starts');
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
        assert_true(cdp_set_viewport($page, 390, 844, 1.0, true), 'tags: phone viewport set');
        $texts = static fn (string $selector): string => "Array.prototype.map.call(document.querySelectorAll(" . json_encode($selector) . "), function (e) { return e.textContent.trim(); })";
        $sheetHas = "(function (label) { var all = document.querySelectorAll('#sess-info button, #sess-info a'); for (var i = 0; i < all.length; i++) { if (all[i].textContent.trim() === label && all[i].offsetParent !== null) { return true; } } return false; }('Edit tags'))";

        echo "Dashboard\n";
        assert_true(cdp_navigate($page, $base . '/'), 'dashboard: page loads');
        assert_true(uit_wait($page, "document.querySelectorAll('.ui-card[data-session]').length === 3"), 'dashboard: cards render');
        assert_equal(['worker', 'project x'], cdp_evaluate($page, $texts('.ui-card[data-session="' . UIT_SESSION . '"] .ui-chip-tag')), 'dashboard: the card shows its tag chips');
        assert_equal('worker,project x', cdp_evaluate($page, "document.querySelector('.ui-card[data-session=\"" . UIT_SESSION . "\"]').getAttribute('data-tags')"), 'dashboard: data-tags');
        assert_equal(0, cdp_evaluate($page, "document.querySelectorAll('.ui-card[data-session=\"plain\"] .ui-chip-tag').length"), 'dashboard: no chip on an untagged card');
        assert_equal(['', 'project x', 'runner', 'worker'], cdp_evaluate($page, "Array.prototype.map.call(document.querySelectorAll('#ui-tag-filter option'), function (o) { return o.value; }).sort()"), 'dashboard: the filter lists All tags and each distinct tag');
        assert_equal('', cdp_evaluate($page, "(document.getElementById('ui-tag-filter') || {}).value"), 'dashboard: All tags is selected at first');
        assert_equal(true, cdp_evaluate($page, "(function () { var e = document.getElementById('ui-tag-filter'); return !!e && e.offsetParent !== null; }())"), 'dashboard: the filter is visible');
        assert_equal(true, cdp_evaluate($page, "document.documentElement.scrollWidth <= window.innerWidth + 1"), 'dashboard: no horizontal scroll on a phone');
        assert_equal([], cdp_drain_console_errors($page), 'dashboard: no console errors');

        $visible = "Array.prototype.filter.call(document.querySelectorAll('.ui-card[data-session]'), function (e) { return e.getClientRects().length > 0; }).map(function (e) { return e.getAttribute('data-session'); }).sort()";
        cdp_evaluate($page, "(function () { var e = document.getElementById('ui-tag-filter'); e.value = 'worker'; e.dispatchEvent(new Event('change', {bubbles:true})); }())");
        assert_equal([UIT_SESSION], cdp_evaluate($page, $visible), 'filter: only matching cards are visible');
        file_put_contents($fixture, json_encode(['rows' => [
            ['name' => UIT_SESSION, 'tags' => ['worker']],
            ['name' => 'other', 'tags' => ['worker', 'new tag']],
            ['name' => 'plain', 'tags' => []],
        ], 'detail' => ['tags' => ['worker']]]));
        assert_true(uit_wait($page, "Array.prototype.some.call(document.querySelectorAll('#ui-tag-filter option'), function (o) { return o.value === 'new tag'; })", 12.0), 'poll refreshes tag options');
        assert_equal(['', 'new tag', 'worker'], cdp_evaluate($page, "Array.prototype.map.call(document.querySelectorAll('#ui-tag-filter option'), function (o) { return o.value; }).sort()"), 'poll removes old and adds new options');
        assert_equal('worker', cdp_evaluate($page, "document.getElementById('ui-tag-filter').value"), 'poll preserves selected tag');
        assert_equal([UIT_SESSION, 'other'], cdp_evaluate($page, $visible), 'poll reapplies filter to updated cards');
        file_put_contents($fixture, json_encode(['rows' => [
            ['name' => UIT_SESSION, 'tags' => ['runner']],
            ['name' => 'other', 'tags' => []],
        ], 'detail' => ['tags' => ['runner']]]));
        assert_true(uit_wait($page, "document.querySelector('.ui-card[data-session=\"other\"]') && document.querySelector('.ui-card[data-session=\"other\"]').getAttribute('data-tags') === ''", 12.0), 'poll receives cards with no matching tag');
        assert_equal('worker', cdp_evaluate($page, "document.getElementById('ui-tag-filter').value"), 'filter retains a selected tag with no matches');
        assert_equal([], cdp_evaluate($page, $visible), 'filter keeps unrelated cards hidden when no tags match');

        assert_true(cdp_set_viewport($page, 320, 700, 1.0, true), 'narrow phone viewport set');
        $longTag = str_repeat('w', 32);
        file_put_contents($fixture, json_encode(['rows' => [
            ['name' => 'parent', 'tags' => []],
            ['name' => 'child', 'launched_by' => 'parent', 'tags' => [$longTag]],
        ], 'detail' => ['tags' => []]]));
        cdp_evaluate($page, "(function () { var e = document.getElementById('ui-tag-filter'); e.value = ''; e.dispatchEvent(new Event('change', {bubbles:true})); }())");
        assert_true(uit_wait($page, "!!document.querySelector('.ui-card[data-session=\"child\"] .ui-chip-tag')", 12.0), 'nested long tag appears after poll');
        assert_equal(true, cdp_evaluate($page, "(function () { var chip = document.querySelector('.ui-card[data-session=\"child\"] .ui-chip-tag'); var card = chip.closest('.ui-card'); return chip.getBoundingClientRect().right <= card.getBoundingClientRect().right + 1; }())"), 'long tag stays inside its phone card');
        assert_true(cdp_set_viewport($page, 390, 844, 1.0, true), 'restore phone viewport');
        file_put_contents($fixture, json_encode(['rows' => [['name' => UIT_SESSION, 'tags' => ['worker', 'project x']]], 'detail' => ['tags' => ['worker', 'project x']]]));

        echo "Session page\n";
        assert_true(cdp_navigate($page, $base . '/session?session=' . UIT_SESSION), 'session: page loads');
        assert_true(uit_wait($page, "document.querySelectorAll('.sess-hier .ui-chip-tag').length === 2"), 'session: tag chips in the hierarchy strip');
        assert_equal(['worker', 'project x'], cdp_evaluate($page, $texts('.sess-hier .ui-chip-tag')), 'session: in order');

        file_put_contents($fixture, json_encode(['rows' => [['name' => UIT_SESSION, 'tags' => ['reviewer']]], 'detail' => ['tags' => ['reviewer']]]));
        assert_true(uit_wait($page, "document.querySelector('.sess-hier .ui-chip-tag') && document.querySelector('.sess-hier .ui-chip-tag').textContent.trim() === 'reviewer'", 12.0), 'detail poll replaces existing tag chips');
        assert_equal(['reviewer'], cdp_evaluate($page, $texts('.sess-hier .ui-chip-tag')), 'detail poll removes stale chips');
        file_put_contents($fixture, json_encode(['rows' => [['name' => UIT_SESSION, 'tags' => ['worker', 'project x']]], 'detail' => ['tags' => ['worker', 'project x']]]));
        assert_true(cdp_navigate($page, $base . '/session?session=' . UIT_SESSION), 'sheet: reload restored tags');

        echo "Details sheet\n";
        cdp_evaluate($page, "document.querySelector('[data-open=\"sess-info\"]').click()");
        assert_true(uit_wait($page, "document.getElementById('sess-info').open && /Tags/.test(document.getElementById('sess-info-body').textContent)"), 'sheet: a Tags row');
        $text = (string)cdp_evaluate($page, "document.getElementById('sess-info-body').textContent");
        assert_contains('worker', $text, 'sheet: names the first tag');
        assert_contains('project x', $text, 'sheet: names the second tag');
        assert_true(uit_wait($page, $sheetHas, 2.0), 'sheet: an Edit tags action');
        assert_equal([], cdp_drain_console_errors($page), 'sheet: no console errors');

        echo "Session without tags\n";
        file_put_contents($fixture, json_encode(['rows' => [['name' => UIT_SESSION, 'tags' => []]], 'detail' => ['tags' => []]]));
        assert_true(cdp_navigate($page, $base . '/session?session=' . UIT_SESSION), 'untagged: page loads');
        cdp_evaluate($page, "document.querySelector('[data-open=\"sess-info\"]').click()");
        assert_true(uit_wait($page, "document.getElementById('sess-info').open && document.getElementById('sess-info-body').textContent.length > 0"), 'untagged: the sheet renders');
        assert_true(uit_wait($page, $sheetHas, 2.0), 'untagged: tags can still be added through Edit tags');
        assert_equal(0, cdp_evaluate($page, "document.querySelectorAll('.ui-chip-tag').length"), 'untagged: no tag chip anywhere');
        assert_equal([], cdp_drain_console_errors($page), 'untagged: no console errors');
        file_put_contents($fixture, json_encode(['rows' => [['name' => UIT_SESSION, 'tags' => ['new tag']]], 'detail' => ['tags' => ['new tag']]]));
        assert_true(uit_wait($page, "document.querySelector('.sess-hier .ui-chip-tag') && document.querySelector('.sess-hier .ui-chip-tag').textContent.trim() === 'new tag'", 12.0), 'detail poll adds a chip to an initially untagged session');

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
