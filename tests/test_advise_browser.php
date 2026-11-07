<?php

declare(strict_types=1);

// The real advice script and styles, with fixture fetch responses. No host agent or model process.
require __DIR__ . '/lib/assert.php';
require __DIR__ . '/lib/cdp.php';

function advice_wait(array &$page, string $expression): bool
{
    $deadline = microtime(true) + 3;
    do {
        if (cdp_evaluate($page, $expression) === true) {
            return true;
        }
        usleep(50000);
    } while (microtime(true) < $deadline);
    return false;
}

function advice_render(array &$page, array $advice): void
{
    $response = json_encode(['ok' => true, 'status' => 'idle', 'advice' => $advice], JSON_THROW_ON_ERROR);
    cdp_evaluate($page, '(function () { window.__fixtureAdvice = ' . $response . '; var h = document.getElementById("advice-host"); h.innerHTML = \'<button data-advise-open>Advice</button>\'; h.querySelector("button").click(); return true; }())');
    assert_true(advice_wait($page, '!!document.querySelector(".ui-advise-panel")'), 'fixture response renders an advice panel');
}

$chrome = cdp_find_chrome();
if ($chrome === null && is_executable('/opt/google/chrome/chrome')) {
    $chrome = '/opt/google/chrome/chrome';
}
if ($chrome === null) {
    echo "  SKIP: no headless browser found\n";
    test_exit();
}
$browser = cdp_launch($chrome);
$page = $browser === null ? null : cdp_open_page($browser);
try {
    if ($page !== null) {
        $css = file_get_contents(__DIR__ . '/../public/css/ui.css');
        $js = file_get_contents(__DIR__ . '/../public/js/ui-advise.js');
        $html = '<!doctype html><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><style>' . $css . '</style>'
            . '<div id="advice-host" data-advise-host data-csrf="fixture" data-session="source"></div>'
            . '<script>window.fetch = function () { return Promise.resolve({json:function () {return Promise.resolve(window.__fixtureAdvice);}}); };</script><script>' . $js . '</script>';
        assert_true(cdp_navigate($page, 'data:text/html;base64,' . base64_encode($html)), 'the advice fixture page loads');
        $reset = 2_000_000_000;
        $base = [
            'verdict' => 'fresh', 'reason' => 'The source account is blocked; take over on another account.',
            'context_tokens' => 20000, 'cache_cold' => false, 'cache_age_seconds' => 60,
            'cost' => ['resume_rewrite_tokens' => 0, 'resume_reread_per_turn' => 20000, 'fresh_handoff_tokens' => 6000, 'fresh_reread_per_turn' => 6000],
            'quota_known' => true, 'quota_blocked' => true, 'restart_after' => $reset,
            'quota_reset_seconds' => 1800, 'quota_short_wait' => true,
            'receiver' => ['agent' => 'claude', 'profile' => 'personal'],
            'prompts' => ['resume' => 'Continue', 'fresh' => 'Use global /takeover for source claude work source-conversation.'],
        ];
        foreach ([390, 1280] as $width) {
            cdp_set_viewport($page, $width, 900, 1.0, $width < 600);
            foreach (['light', 'dark'] as $theme) {
                cdp_evaluate($page, 'document.documentElement.setAttribute("data-theme", ' . json_encode($theme) . ')');
                advice_render($page, $base);
                $label = $theme . ' at ' . $width . 'px';
                assert_equal(true, cdp_evaluate($page, 'document.querySelector("[data-advise-choice=resume]").disabled'), $label . ': Continue is disabled for blocked source');
                assert_equal(false, cdp_evaluate($page, 'document.querySelector("[data-advise-choice=fresh]").disabled'), $label . ': available takeover stays active');
                assert_contains('personal', strtolower((string) cdp_evaluate($page, 'document.querySelector("[data-advise-choice=fresh]").textContent')), $label . ': fresh button identifies the receiving account');
                assert_true((bool) cdp_evaluate($page, '/remain|stay.*available/i.test(document.querySelector("[data-advise-choice=fresh]").textContent)'), $label . ': takeover explains that source stays available');
                assert_true((bool) cdp_evaluate($page, '/reset/i.test(document.querySelector(".ui-advise-panel").textContent) && !/unknown/i.test(document.querySelector(".ui-advise-panel").textContent)'), $label . ': a known reset is shown');
                assert_equal(true, cdp_evaluate($page, 'Array.prototype.every.call(document.querySelectorAll(".ui-advise-btn, .ui-advise-btn strong, .ui-advise-btn span"), function (el) { return getComputedStyle(el).color === "rgb(255, 255, 255)"; })'), $label . ': both buttons, titles and descriptions have computed white text');
                assert_equal(true, cdp_evaluate($page, 'Array.prototype.every.call(document.querySelectorAll(".ui-advise-btn"), function (el) { var c = getComputedStyle(el).backgroundColor; return c !== "rgba(0, 0, 0, 0)" && c !== "rgb(255, 255, 255)"; })'), $label . ': both buttons have visible backgrounds');
                assert_equal(true, cdp_evaluate($page, 'document.documentElement.scrollWidth <= window.innerWidth'), $label . ': advice fits the viewport');
            }
        }
        advice_render($page, array_replace($base, ['verdict' => 'wait', 'reason' => 'Wait for quota, then continue.']));
        assert_equal(true, cdp_evaluate($page, 'document.querySelector("[data-advise-choice=resume]").disabled'), 'WAIT still disables immediate Continue');
        assert_equal(false, cdp_evaluate($page, 'document.querySelector("[data-advise-choice=fresh]").disabled'), 'WAIT still allows explicit takeover');
        assert_contains('wait', strtolower((string) cdp_evaluate($page, 'document.querySelector(".ui-advise-panel").textContent')), 'WAIT is visible to the user');

        advice_render($page, array_replace($base, ['verdict' => 'manual', 'receiver' => null, 'restart_after' => null, 'quota_reset_seconds' => null, 'quota_short_wait' => false]));
        assert_equal(true, cdp_evaluate($page, 'var b = document.querySelector("[data-advise-choice=fresh]"); !b || b.disabled'), 'no receiver provides no automatic fresh action');
        $manualText = (string) cdp_evaluate($page, 'document.querySelector(".ui-advise-panel").textContent');
        assert_contains('unknown', strtolower($manualText), 'an unknown source reset is explicit');
        assert_contains('/takeover', $manualText, 'manual takeover instructions remain visible without a receiver');
        advice_render($page, array_replace($base, ['verdict' => 'manual', 'receiver' => null, 'manual_agents' => ['Antigravity']]));
        $agentText = (string) cdp_evaluate($page, 'document.querySelector(".ui-advise-panel").textContent');
        assert_contains('antigravity', strtolower($agentText), 'a manual takeover names the agent that has room');
        advice_render($page, array_replace($base, ['receiver' => ['agent' => 'opencode', 'profile' => null]]));
        $openText = strtolower((string) cdp_evaluate($page, 'document.querySelector("[data-advise-choice=fresh]").textContent'));
        assert_contains('opencode', $openText, 'the takeover button names an OpenCode receiver');
        assert_equal([], cdp_drain_console_errors($page), 'the advice states raise no browser errors');
    }
} finally {
    if ($page !== null) {
        cdp_close_page($page);
    }
    if ($browser !== null) {
        cdp_shutdown($browser);
    }
}

test_exit();
