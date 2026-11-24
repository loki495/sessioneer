<?php
declare(strict_types=1);

/**
 * The quota tint in a real headless Chrome against the canned fake agent (never tmux or a real agent), light and dark.
 * Rows come from CANNED_HIERARCHY (tests/fixtures/canned_agent.php merges each row over a minimal idle row, so the
 * `quota_exhausted` field passes through unchanged; no fixture change). Written from the Contract only. Intent notes:
 *  - markup: a dashboard card and a session-list popup row of an exhausted session carry class ui-quota-out and
 *    data-quota="out"; healthy, unknown (null) and field-less rows carry neither; card and popup agree row by row;
 *  - look: the tint is visibly red and different from a healthy row's background, beside it, in light and dark;
 *  - contrast (WCAG, computed styles composited over ancestors): title, path, meta, chips, live and greyed `waiting on`
 *    badges at least 4.5:1 on the tint; status bar at least 3:1; dot and star no worse than on a healthy row and at least 1.5:1; the current-row
 *    outline at least 1.5:1; the greyed is-ended badge still looks different from a live one.
 * Not pinned: the exact tint colour, which element of the popup row carries the class beyond the row itself.
 * Skips (exit 0) when no Chrome is installed.
 */

require __DIR__ . '/lib/assert.php';
require __DIR__ . '/lib/harness.php';
require __DIR__ . '/lib/free_port.php';
require __DIR__ . '/lib/cdp.php';

const QTB_SESSION = 'cc-20260101-1200';

function qtb_wait(array &$page, string $expression, float $timeout = 8.0): bool
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

/** JS helpers: effective background (composited over ancestors), WCAG contrast, red-ness. Returned as an IIFE prefix. */
const QTB_JS = <<<'JS'
(function () {
  var cv = document.createElement('canvas'); cv.width = cv.height = 1;
  var cx = cv.getContext('2d', { willReadFrequently: true });
  function parse(css) { cx.clearRect(0, 0, 1, 1); cx.fillStyle = '#000'; cx.fillStyle = css; cx.fillRect(0, 0, 1, 1); var d = cx.getImageData(0, 0, 1, 1).data; return [d[0], d[1], d[2], d[3] / 255]; }
  function over(top, base) { var a = top[3]; return [top[0] * a + base[0] * (1 - a), top[1] * a + base[1] * (1 - a), top[2] * a + base[2] * (1 - a), 1]; }
  function bg(el) {
    var chain = []; for (var n = el; n; n = n.parentElement) { chain.unshift(n); }
    var acc = [255, 255, 255, 1];
    chain.forEach(function (n) { var c = parse(getComputedStyle(n).backgroundColor); if (c[3] > 0) { acc = over(c, acc); } });
    return acc;
  }
  function lum(c) { var f = function (v) { v /= 255; return v <= 0.03928 ? v / 12.92 : Math.pow((v + 0.055) / 1.055, 2.4); }; return 0.2126 * f(c[0]) + 0.7152 * f(c[1]) + 0.0722 * f(c[2]); }
  function ratio(a, b) { var l1 = lum(a), l2 = lum(b); return (Math.max(l1, l2) + 0.05) / (Math.min(l1, l2) + 0.05); }
  function fg(el, prop) { var c = parse(getComputedStyle(el)[prop || 'color']); return over(c, bg(el)); }
  window.__qt = {
    bg: bg, parse: parse, over: over, ratio: ratio,
    text: function (el) { return ratio(fg(el), bg(el)); },
    textOpacity: function (el) { var o = 1; for (var n = el; n; n = n.parentElement) { o *= parseFloat(getComputedStyle(n).opacity); } return o; },
    fill: function (el) { return ratio(fg(el, 'backgroundColor'), bg(el.parentElement)); },
    red: function (el) { var c = bg(el); return c[0] - Math.max(c[1], c[2]); },
    dist: function (a, b) { var x = bg(a), y = bg(b); return Math.abs(x[0] - y[0]) + Math.abs(x[1] - y[1]) + Math.abs(x[2] - y[2]); }
  };
  return true;
}())
JS;

function qtb_js(array &$page, string $expression): mixed
{
    cdp_evaluate($page, QTB_JS);

    return cdp_evaluate($page, $expression);
}

$scratch = sys_get_temp_dir() . '/sessioneer-test-quota-tint-' . getmypid();
mkdir($scratch, 0700);
$fixture = $scratch . '/rows.json';
$now = time();
$wait = static fn (string $name, string $state = 'live'): array => ['name' => $name, 'state' => $state, 'since' => $now - 600];
file_put_contents($fixture, json_encode([
    'rows' => [
        ['name' => QTB_SESSION, 'quota_exhausted' => true, 'working' => true],
        ['name' => 'out-star', 'quota_exhausted' => true, 'starred' => true],
        ['name' => 'out-blocked', 'quota_exhausted' => true, 'working' => true, 'blocked_reason' => 'Do you want to proceed?', 'prompt_options' => [['number' => 1, 'label' => 'Yes']]],
        ['name' => 'out-wait', 'quota_exhausted' => true, 'waiting_on' => [$wait('healthy'), $wait('ghost', 'ended')]],
        ['name' => 'out-parent', 'quota_exhausted' => true],
        ['name' => 'out-kid', 'quota_exhausted' => true, 'launched_by' => 'out-parent'],
        ['name' => 'healthy', 'quota_exhausted' => false],
        ['name' => 'healthy-star', 'quota_exhausted' => false, 'starred' => true],
        ['name' => 'unknown', 'quota_exhausted' => null],
        ['name' => 'legacy'],
    ],
    'detail' => [],
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
$outs = ['sess-current' => QTB_SESSION, 'out-star' => 'out-star', 'out-blocked' => 'out-blocked', 'out-wait' => 'out-wait', 'out-parent' => 'out-parent', 'out-kid' => 'out-kid'];
$plains = ['healthy', 'healthy-star', 'unknown', 'legacy'];

try {
    assert_true(is_resource($server), 'quota tint: isolated PHP server starts');
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
        assert_true(cdp_set_viewport($page, 390, 844, 1.0, true), 'quota tint: phone viewport set');
        $card = static fn (string $n): string => 'document.querySelector(\'.ui-card[data-session="' . $n . '"]\')';
        $setTheme = static function (string $theme) use (&$page): void {
            cdp_evaluate($page, 'document.documentElement.setAttribute("data-theme", ' . json_encode($theme) . ')');
            usleep(300000);
        };

        foreach (['light', 'dark'] as $theme) {
            echo "Dashboard ({$theme})\n";
            assert_true(cdp_navigate($page, $base . '/'), "{$theme}: dashboard loads");
            assert_true(qtb_wait($page, "document.querySelectorAll('.ui-card[data-session]').length === 10"), "{$theme}: every row renders as a card");
            $setTheme($theme);
            $cardMarkup = [];
            foreach ($outs as $n) {
                $cardMarkup[$n] = cdp_evaluate($page, "[{$card($n)}.classList.contains('ui-quota-out'), {$card($n)}.getAttribute('data-quota')]");
                assert_equal([true, 'out'], $cardMarkup[$n], "{$theme}: exhausted card {$n} has the class and data-quota=out");
            }
            foreach ($plains as $n) {
                assert_equal([false, null], cdp_evaluate($page, "[{$card($n)}.classList.contains('ui-quota-out'), {$card($n)}.getAttribute('data-quota')]"), "{$theme}: card {$n} (healthy, null or no field) is plain");
            }
            assert_equal(6, cdp_evaluate($page, "document.querySelectorAll('.ui-card.ui-quota-out').length"), "{$theme}: exactly the six exhausted cards are tinted");

            echo "Dashboard look ({$theme})\n";
            assert_true(qtb_js($page, "__qt.dist({$card('out-star')}, {$card('healthy-star')})") > 12, "{$theme}: a tinted starred card differs from a healthy starred card beside it");
            assert_true(qtb_js($page, "__qt.dist({$card('out-parent')}, {$card('healthy')})") > 12, "{$theme}: a tinted card differs from a healthy card beside it");
            assert_true(qtb_js($page, "__qt.red({$card('out-parent')}) > __qt.red({$card('healthy')}) + 6") === true, "{$theme}: the tint is reddish (red leads green and blue more than on a healthy card)");
            assert_true(qtb_js($page, "__qt.dist({$card('unknown')}, {$card('healthy')})") < 4, "{$theme}: an unknown (null) card looks like a healthy one");
            assert_true(qtb_js($page, "__qt.dist({$card('legacy')}, {$card('healthy')})") < 4, "{$theme}: a card without the field looks like a healthy one");
            assert_true(qtb_js($page, "__qt.dist({$card('out-star')}, {$card('out-parent')})") > 0, "{$theme}: the star state still shows on a tinted card (background differs from a plain tinted one)");

            echo "Dashboard contrast ({$theme})\n";
            foreach (['out-parent' => ['.ui-title', '.ui-path', '.ui-meta span', '.ui-chip'], 'out-wait' => ['.ui-chip-waiting:not(.is-ended)', '.ui-chip-waiting.is-ended', '.ui-title'], 'out-blocked' => ['.ui-prompt-title', '.ui-btn-warn', '.ui-title'], 'out-star' => ['.ui-title']] as $n => $selectors) {
                foreach ($selectors as $sel) {
                    $ratio = qtb_js($page, "(function () { var e = {$card($n)}.querySelector(" . json_encode($sel) . "); return e ? __qt.text(e) : -1; }())");
                    assert_true(is_numeric($ratio) && $ratio >= 4.5, "{$theme}: {$n} {$sel} text contrast on the tint is at least 4.5 (got " . json_encode($ratio) . ')');
                }
            }
            $muted = qtb_js($page, "(function () { var e = {$card('out-parent')}.querySelector('.ui-path'); return e ? __qt.text(e) : -1; }())");
            assert_true(is_numeric($muted) && $muted >= 4.5, "{$theme}: muted path text keeps 4.5 on the tint");
            // Dot and star already sit below 3:1 on a plain card (idle grey, amber outline), so they are held to
            // "no worse than the same element on a healthy card" rather than to an absolute figure.
            $pairs = [['out-parent', 'healthy', '.ui-dot', 'fill'], ['out-star', 'healthy-star', '.ui-dot', 'fill'], ['out-star', 'healthy-star', '.ui-star', 'text'], ['out-parent', 'healthy', '.ui-star', 'text']];
            foreach ($pairs as [$outName, $okName, $sel, $kind]) {
                $js = static fn (string $n): string => "(function () { var e = {$GLOBALS['card']($n)}.querySelector(" . json_encode($sel) . "); return e ? __qt.{$kind}(e) : -1; }())";
                $onTint = qtb_js($page, $js($outName));
                $onPlain = qtb_js($page, $js($okName));
                assert_true(is_numeric($onTint) && is_numeric($onPlain) && $onTint >= 1.5 && $onTint >= $onPlain - 0.3, "{$theme}: {$sel} of {$outName} on the tint ({$onTint}) is no worse than on a healthy card ({$onPlain}) and at least 1.5");
            }
            $bar = qtb_js($page, "(function () { var c = {$card('out-blocked')}; var p = getComputedStyle(c, '::before').backgroundColor; return __qt.ratio(__qt.over(__qt.parse(p), __qt.bg(c)), __qt.bg(c)); }())");
            assert_true(is_numeric($bar) && $bar >= 3.0, "{$theme}: the blocked status bar stays legible on the tint, at least 3 (got " . json_encode($bar) . ')');
            $border = qtb_js($page, "(function () { var c = {$card('out-blocked')}; var s = getComputedStyle(c); return [s.borderTopStyle, s.borderTopWidth, s.borderTopColor, getComputedStyle({$card('healthy')}).borderTopColor]; }())");
            assert_true(is_array($border) && $border[0] !== 'none' && $border[1] !== '0px', "{$theme}: the blocked card keeps its border outline on the tint");
            $greyed = qtb_js($page, "(function () { var a = {$card('out-wait')}.querySelector('.ui-chip-waiting.is-ended'), b = {$card('out-wait')}.querySelector('.ui-chip-waiting:not(.is-ended)'); return __qt.dist(a, b) > 6 || getComputedStyle(a).color !== getComputedStyle(b).color; }())");
            assert_equal(true, $greyed, "{$theme}: the greyed is-ended badge still looks different from a live badge on the tint");
            assert_equal(true, cdp_evaluate($page, "{$card('out-kid')}.classList.contains('ui-card-child') && {$card('out-kid')}.getAttribute('data-parent') === 'out-parent'"), "{$theme}: a tinted child keeps its nesting");
            assert_equal(true, cdp_evaluate($page, "document.documentElement.scrollWidth <= window.innerWidth + 1"), "{$theme}: the tint causes no horizontal scroll");
            assert_equal([], cdp_drain_console_errors($page), "{$theme}: dashboard has no console errors");

            echo "Session list popup ({$theme})\n";
            assert_true(cdp_navigate($page, $base . '/session?session=' . QTB_SESSION), "{$theme}: the session page loads");
            $setTheme($theme);
            cdp_evaluate($page, "document.querySelector('[data-open=\"sess-sessions\"]').click()");
            assert_true(qtb_wait($page, "document.querySelectorAll('#sess-sessions-list .sess-srow').length === 10"), "{$theme}: popup lists every row");
            $row = static fn (string $n): string => "(function () { var rows = document.querySelectorAll('#sess-sessions-list .sess-srow'); for (var i = 0; i < rows.length; i++) { if (decodeURIComponent(rows[i].querySelector('.sess-srow-main').getAttribute('href').split('session=')[1]) === " . json_encode($n) . ") { return rows[i]; } } return null; }())";
            foreach ($outs as $n) {
                assert_equal([true, 'out'], cdp_evaluate($page, "(function () { var r = {$row($n)}; return [r.classList.contains('ui-quota-out'), r.getAttribute('data-quota')]; }())"), "{$theme}: exhausted popup row {$n} has the class and data-quota=out");
                assert_equal($cardMarkup[$n], cdp_evaluate($page, "(function () { var r = {$row($n)}; return [r.classList.contains('ui-quota-out'), r.getAttribute('data-quota')]; }())"), "{$theme}: popup row {$n} agrees with its dashboard card");
            }
            foreach ($plains as $n) {
                assert_equal([false, null], cdp_evaluate($page, "(function () { var r = {$row($n)}; return [r.classList.contains('ui-quota-out'), r.getAttribute('data-quota')]; }())"), "{$theme}: popup row {$n} is plain, as its card is");
            }
            assert_equal(6, cdp_evaluate($page, "document.querySelectorAll('#sess-sessions-list .ui-quota-out').length"), "{$theme}: exactly six popup rows are tinted");

            echo "Popup look and contrast ({$theme})\n";
            assert_true(qtb_js($page, "__qt.dist({$row('out-parent')}, {$row('healthy')})") > 12, "{$theme}: a tinted popup row differs from a healthy row beside it");
            assert_true(qtb_js($page, "__qt.red({$row('out-parent')}) > __qt.red({$row('healthy')}) + 6") === true, "{$theme}: the popup tint is reddish");
            assert_true(qtb_js($page, "__qt.dist({$row('unknown')}, {$row('healthy')})") < 4, "{$theme}: an unknown popup row looks healthy");
            foreach (['out-parent' => ['.sess-srow-text b', 'small', '.sess-srow-chips .ui-chip'], 'out-wait' => ['.ui-chip-waiting:not(.is-ended)', '.ui-chip-waiting.is-ended'], 'out-star' => ['.sess-srow-text b'], QTB_SESSION => ['.sess-srow-text b', '.sess-current-pill']] as $n => $selectors) {
                foreach ($selectors as $sel) {
                    $ratio = qtb_js($page, "(function () { var e = {$row($n)}.querySelector(" . json_encode($sel) . "); return e ? __qt.text(e) : -1; }())");
                    assert_true(is_numeric($ratio) && $ratio >= 4.5, "{$theme}: popup {$n} {$sel} text contrast on the tint is at least 4.5 (got " . json_encode($ratio) . ')');
                }
            }
            foreach ([['out-parent', 'healthy', '.ui-dot', 'fill'], ['out-star', 'healthy-star', '[data-star]', 'text']] as [$outName, $okName, $sel, $kind]) {
                $js = static fn (string $n): string => "(function () { var e = {$GLOBALS['row']($n)}.querySelector(" . json_encode($sel) . "); return e ? __qt.{$kind}(e) : -1; }())";
                $onTint = qtb_js($page, $js($outName));
                $onPlain = qtb_js($page, $js($okName));
                assert_true(is_numeric($onTint) && is_numeric($onPlain) && $onTint >= 1.5 && $onTint >= $onPlain - 0.3, "{$theme}: popup {$sel} of {$outName} on the tint ({$onTint}) is no worse than on a healthy row ({$onPlain}) and at least 1.5");
            }
            $shadow = cdp_evaluate($page, "getComputedStyle({$row(QTB_SESSION)}).boxShadow");
            assert_true(is_string($shadow) && $shadow !== 'none' && str_contains($shadow, 'inset'), "{$theme}: the current-row outline survives on a tinted row (box-shadow " . json_encode($shadow) . ')');
            $outline = qtb_js($page, "(function () { var r = {$row(QTB_SESSION)}; var m = /(rgba?\\([^)]*\\)|color\\([^)]*\\))/.exec(getComputedStyle(r).boxShadow); return m ? __qt.ratio(__qt.over(__qt.parse(m[1]), __qt.bg(r)), __qt.bg(r)) : -1; }())");
            assert_true(is_numeric($outline) && $outline >= 1.5, "{$theme}: the current-row outline is visible against the tint, at least 1.5 (got " . json_encode($outline) . ')');
            $greyed = qtb_js($page, "(function () { var a = {$row('out-wait')}.querySelector('.ui-chip-waiting.is-ended'), b = {$row('out-wait')}.querySelector('.ui-chip-waiting:not(.is-ended)'); return __qt.dist(a, b) > 6 || getComputedStyle(a).color !== getComputedStyle(b).color; }())");
            assert_equal(true, $greyed, "{$theme}: the popup's greyed badge still differs from a live one");
            assert_equal([], cdp_drain_console_errors($page), "{$theme}: popup has no console errors");
        }

        echo "Background only: borders and the global accent tint are unchanged\n";
        $pairRows = static fn (bool $out): array => [
            ['name' => QTB_SESSION, 'quota_exhausted' => $out, 'starred' => true],
            ['name' => 'blk', 'quota_exhausted' => $out, 'working' => true, 'blocked_reason' => 'Do you want to proceed?', 'prompt_options' => [['number' => 1, 'label' => 'Yes']]],
            ['name' => 'star', 'quota_exhausted' => $out, 'starred' => true],
            ['name' => 'par', 'quota_exhausted' => $out],
            ['name' => 'kid', 'quota_exhausted' => $out, 'launched_by' => 'par'],
        ];
        $borders = static function (string $selectorJs): string {
            return "(function () { var e = {$selectorJs}; if (!e) { return null; } var s = getComputedStyle(e); return ['Top', 'Right', 'Bottom', 'Left'].map(function (d) { return s['border' + d + 'Color'] + '/' + s['border' + d + 'Width'] + '/' + s['border' + d + 'Style']; }).join(' | '); }())";
        };
        foreach (['light', 'dark'] as $theme) {
            $seen = ['card' => [], 'row' => []];
            foreach ([true, false] as $out) {
                file_put_contents($fixture, json_encode(['rows' => $pairRows($out), 'detail' => []]));
                assert_true(cdp_navigate($page, $base . '/'), "{$theme}: borders: dashboard loads (tinted=" . json_encode($out) . ')');
                assert_true(qtb_wait($page, "document.querySelectorAll('.ui-card[data-session]').length === 5"), "{$theme}: borders: five cards");
                cdp_evaluate($page, 'document.documentElement.setAttribute("data-theme", ' . json_encode($theme) . ')');
                usleep(300000);
                foreach (['blk', 'star', 'par', 'kid', QTB_SESSION] as $n) {
                    $seen['card'][$n][(int)$out] = cdp_evaluate($page, $borders($card($n)));
                }
                assert_equal($out ? 5 : 0, cdp_evaluate($page, "document.querySelectorAll('.ui-card.ui-quota-out').length"), "{$theme}: borders: the tint is present only in the tinted run");
                assert_true(cdp_navigate($page, $base . '/session?session=' . QTB_SESSION), "{$theme}: borders: session page loads");
                cdp_evaluate($page, 'document.documentElement.setAttribute("data-theme", ' . json_encode($theme) . ')');
                cdp_evaluate($page, "document.querySelector('[data-open=\"sess-sessions\"]').click()");
                assert_true(qtb_wait($page, "document.querySelectorAll('#sess-sessions-list .sess-srow').length === 5"), "{$theme}: borders: five popup rows");
                $rowOf = static fn (string $n): string => "(function () { var rows = document.querySelectorAll('#sess-sessions-list .sess-srow'); for (var i = 0; i < rows.length; i++) { if (decodeURIComponent(rows[i].querySelector('.sess-srow-main').getAttribute('href').split('session=')[1]) === " . json_encode($n) . ") { return rows[i]; } } return null; }())";
                foreach (['star', 'par', 'kid', QTB_SESSION] as $n) {
                    $seen['row'][$n][(int)$out] = cdp_evaluate($page, $borders($rowOf($n)));
                }
            }
            foreach (['card' => ['blk' => 'blocked', 'star' => 'starred', 'kid' => 'child (hierarchy accent border)', 'par' => 'plain', QTB_SESSION => 'current and starred'], 'row' => ['star' => 'starred', 'par' => 'plain', 'kid' => 'child', QTB_SESSION => 'current']] as $kind => $names) {
                foreach ($names as $n => $label) {
                    $pair = $seen[$kind][$n] ?? [];
                    assert_true(is_string($pair[1] ?? null) && ($pair[1] ?? null) === ($pair[0] ?? 'x'), "{$theme}: a tinted {$kind} ({$label}) has the same border colours as the identical untinted one (" . json_encode($pair) . ')');
                }
            }
        }

        // Master's value, kept as a literal: the tint must not touch the global --accent-soft (chips, buttons, session cards).
        foreach (['light', 'dark'] as $theme) {
            assert_true(cdp_navigate($page, $base . '/'), "{$theme}: accent: dashboard loads");
            cdp_evaluate($page, 'document.documentElement.setAttribute("data-theme", ' . json_encode($theme) . ')');
            usleep(300000);
            assert_true(preg_match('/^color-mix\(in srgb, [^,]+ 9%, [^,]+\)$/', (string)cdp_evaluate($page, "getComputedStyle(document.documentElement).getPropertyValue('--accent-soft').replace(/\\s+/g, ' ').trim()")) === 1, "{$theme}: --accent-soft is still 9 percent accent over the surface");
            $same = cdp_evaluate($page, "(function () { var probe = document.createElement('div'); probe.style.background = 'color-mix(in srgb, var(--accent) 9%, var(--surface))'; document.body.appendChild(probe); var want = getComputedStyle(probe).backgroundColor; var chip = document.createElement('span'); chip.className = 'ui-chip'; document.body.appendChild(chip); var btn = document.createElement('button'); btn.className = 'ui-btn'; document.body.appendChild(btn); var r = [getComputedStyle(chip).backgroundColor === want, getComputedStyle(btn).backgroundColor === want, want]; [probe, chip, btn].forEach(function (n) { n.remove(); }); return r; }())");
            assert_true(is_array($same) && $same[0] === true && $same[1] === true, "{$theme}: a plain .ui-chip and .ui-btn keep the 9 percent background (" . json_encode($same) . ')');
        }

        echo "Rows without the field render plain\n";
        file_put_contents($fixture, json_encode(['rows' => [['name' => QTB_SESSION], ['name' => 'a', 'quota_exhausted' => null], ['name' => 'b', 'quota_exhausted' => false]], 'detail' => []]));
        assert_true(cdp_navigate($page, $base . '/'), 'plain: dashboard loads');
        assert_true(qtb_wait($page, "document.querySelectorAll('.ui-card[data-session]').length === 3"), 'plain: renders');
        assert_equal(0, cdp_evaluate($page, "document.querySelectorAll('.ui-quota-out, [data-quota]').length"), 'plain: no class and no attribute on any card');
        assert_true(cdp_navigate($page, $base . '/session?session=' . QTB_SESSION), 'plain: session page loads');
        cdp_evaluate($page, "document.querySelector('[data-open=\"sess-sessions\"]').click()");
        assert_true(qtb_wait($page, "document.querySelectorAll('#sess-sessions-list .sess-srow').length === 3"), 'plain: popup renders');
        assert_equal(0, cdp_evaluate($page, "document.querySelectorAll('#sess-sessions-list .ui-quota-out, #sess-sessions-list [data-quota]').length"), 'plain: no class and no attribute on any popup row');
        assert_equal([], cdp_drain_console_errors($page), 'plain: no console errors');
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
