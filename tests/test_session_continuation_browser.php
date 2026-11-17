<?php
declare(strict_types=1);
require __DIR__ . '/lib/assert.php';
require __DIR__ . '/lib/harness.php';
require __DIR__ . '/lib/free_port.php';
require __DIR__ . '/lib/cdp.php';

function continuation_wait(array &$page, string $js, float $seconds = 6): bool {
    $until = microtime(true) + $seconds;
    do { if (cdp_evaluate($page, $js) === true) return true; usleep(100000); } while (microtime(true) < $until);
    return false;
}
$root = sys_get_temp_dir() . '/sessioneer-continuation-browser-' . getmypid();
mkdir($root, 0700, true);
$state = $root . '/state.json'; $log = $root . '/requests.log'; $allLog = $root . '/all.log';
$set = static function (array $reply, ?array $rotate = null, ?array $flow = null) use ($state, $log, $allLog): void {
    file_put_contents($state, json_encode(['flow' => $flow, 'offer' => null, 'log' => $log, 'all_log' => $allLog, 'detail_override' => ['profile' => 'work', 'current_model' => 'sonnet'], 'continuation' => $reply, 'continue_rotate' => $rotate]));
    file_put_contents($log, '');
    file_put_contents($allLog, '');
};
$entries = static function () use ($log): array {
    return array_map(static fn ($line) => json_decode($line, true), file($log, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: []);
};
$set(['ok' => true, 'action' => 'rotate', 'can_rotate' => true, 'why' => '<img src=x onerror="window.__xss=1"> ready']);
putenv('CANNED_FLOW_STATE=' . $state);
$socket = $root . '/agent.sock';
$harness = start_harness(['php', __DIR__ . '/fixtures/canned_agent.php'], $socket);
$port = test_free_port(); $base = 'http://127.0.0.1:' . $port;
$server = proc_open(['php', '-d', 'session.save_path=' . $root, '-S', '127.0.0.1:' . $port, '-t', dirname(__DIR__) . '/public', dirname(__DIR__) . '/public/index.php'], [0 => ['file', '/dev/null', 'r'], 1 => ['file', $root . '/server.log', 'a'], 2 => ['file', $root . '/server.log', 'a']], $pipes, null, array_merge(getenv(), ['SESSIONEER_AGENT_SOCKET' => $socket]));
$browser = $page = null;
try {
    $deadline = microtime(true) + 3;
    while (microtime(true) < $deadline && !($c = @fsockopen('127.0.0.1', $port, $e, $s, 0.2))) usleep(50000);
    $chrome = cdp_find_chrome(); $browser = $chrome === null ? null : cdp_launch($chrome); $page = $browser === null ? null : cdp_open_page($browser);
    if ($page === null) { echo "  SKIP: no headless browser found\n"; }
    else {
        assert_true(cdp_navigate($page, $base . '/session?session=cc-20260101-1200'), 'session page loads');
        assert_true(continuation_wait($page, '!!document.querySelector("[data-open=sess-info]")'), 'details opener available');
        assert_equal([], $entries(), 'page load does not request or launch continuation');
        cdp_evaluate($page, '(function () { var f = new FormData(); f.append("action", "flow_continuation"); f.append("session", "cc-20260101-1200"); f.append("csrf_token", "wrong"); fetch("/action", {method:"POST", body:f}).then(function (r) { window.__badCsrf = r.status; }); }())');
        assert_true(continuation_wait($page, 'typeof window.__badCsrf === "number"'), 'invalid CSRF request completes');
        assert_equal([], $entries(), 'invalid CSRF reaches no host action');
        cdp_evaluate($page, 'document.querySelector("[data-open=sess-info]").click()');
        assert_true(continuation_wait($page, '!!document.querySelector("[data-continuation-why]")'), 'sheet displays recommendation reason');
        assert_true(continuation_wait($page, '!!document.querySelector("[data-info=rotate-new]")'), 'sheet offers rotate');
        assert_true(continuation_wait($page, 'document.querySelector("[data-continuation-why]") && document.querySelector("[data-continuation-why]").textContent.indexOf("ready") >= 0'), 'host reason arrives');
        $requests = $entries();
        assert_equal(['flow_continuation', 'cc-20260101-1200'], [$requests[0]['action'] ?? null, $requests[0]['session'] ?? null], 'sheet opens with one authenticated recommendation for source');
        assert_equal(1, count($requests), 'opening sheet makes no launch or send');
        $all = array_map(static fn ($line) => json_decode($line, true), file($allLog, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: []);
        $mutations = array_filter($all, static function ($r): bool {
            $action = (string) ($r['action'] ?? '');
            if (str_ends_with($action, '_list') || str_ends_with($action, '_show') || str_ends_with($action, '_peers')) return false;
            return preg_match('/(^|_)(launch|send|kill|archive|create)(_|$)/', $action) === 1;
        });
        assert_equal([], array_values($mutations), 'opening and recommendation call no launch, send, kill, archive or create host actions');
        assert_equal(['rotate-new', 'takeover-new'], cdp_evaluate($page, 'Array.prototype.map.call(document.querySelectorAll("#sess-info-actions [data-info=rotate-new], #sess-info-actions [data-info=takeover-new]"), function (b) { return b.getAttribute("data-info"); })'), 'recommended rotate is first and takeover remains available');
        assert_equal(true, cdp_evaluate($page, '(function () { var a = document.querySelector("[data-info=rotate-new]"), b = document.querySelector("[data-info=takeover-new]"); return !!a && !!b && a.classList.contains("ui-btn-primary") && !b.classList.contains("ui-btn-primary"); }())'), 'recommended action uses primary styling');
        assert_equal(null, cdp_evaluate($page, 'window.__xss === undefined ? null : window.__xss'), 'reason is rendered as text');
        $screens = getenv('CONTINUATION_SCREENSHOT_DIR');
        if (is_string($screens) && is_dir($screens)) {
            foreach ([[390, true, 'phone'], [1280, false, 'desktop']] as [$width, $mobile, $name]) {
                cdp_set_viewport($page, $width, 900, 1.0, $mobile);
                foreach (['light', 'dark'] as $theme) {
                    cdp_evaluate($page, 'document.documentElement.setAttribute("data-theme", ' . json_encode($theme) . ')');
                    $png = cdp_screenshot($page);
                    if ($png !== null) file_put_contents($screens . '/continuation-' . $name . '-' . $theme . '.png', $png);
                }
            }
        }
        sleep(3);
        assert_equal(1, count($entries()), 'detail polls do not repeat recommendation');
        cdp_evaluate($page, 'var star = document.querySelector("#sess-info-head-actions [data-info=star]"); if (star) star.click()');
        sleep(1);
        assert_equal(1, count($entries()), 'rebuilding details after starring does not refetch recommendation');
        assert_equal('rotate-new', cdp_evaluate($page, '(document.querySelector("#sess-info-actions [data-info=rotate-new], #sess-info-actions [data-info=takeover-new]") || {}).dataset.info || null'), 'recommendation survives details rebuild');
        cdp_evaluate($page, 'document.getElementById("sess-info").close(); window.__originalFetch = window.fetch; window.__holdOne = true; window.fetch = function (url, opts) { if (window.__holdOne && String(url).indexOf("/action") === 0 && opts && opts.body && new URLSearchParams(opts.body).get("action") === "flow_continuation") { window.__holdOne = false; return new Promise(function (resolve) { window.__releaseOld = resolve; }); } return window.__originalFetch.apply(this, arguments); }; document.querySelector("[data-open=sess-info]").click()');
        assert_true(continuation_wait($page, 'typeof window.__releaseOld === "function"'), 'opening starts the delayed request');
        assert_true((bool) cdp_evaluate($page, '!!document.querySelector("[data-continuation-why]") && document.querySelector("[data-continuation-why]").textContent.trim().length > 0'), 'pending recommendation shows a loading reason');
        cdp_evaluate($page, 'document.getElementById("sess-info").close()');
        $set(['ok' => true, 'action' => 'takeover', 'can_rotate' => false, 'why' => 'newer answer']);
        cdp_evaluate($page, 'document.querySelector("[data-open=sess-info]").click()');
        assert_true(continuation_wait($page, '!!document.querySelector("[data-continuation-why]") && document.querySelector("[data-continuation-why]").textContent.indexOf("newer answer") >= 0'), 'reopened sheet receives fresh answer');
        cdp_evaluate($page, 'if (window.__releaseOld) window.__releaseOld(new Response(JSON.stringify({ok:true, action:"rotate", can_rotate:true, why:"obsolete answer"}), {status:200, headers:{"Content-Type":"application/json"}})); window.fetch = window.__originalFetch;');
        usleep(300000);
        assert_true((bool) cdp_evaluate($page, '!!document.querySelector("[data-continuation-why]") && document.querySelector("[data-continuation-why]").textContent.indexOf("newer answer") >= 0'), 'late response from closed sheet cannot replace newer answer');
        foreach ([[390, true], [1280, false]] as [$width, $mobile]) {
            cdp_set_viewport($page, $width, 900, 1.0, $mobile);
            foreach (['light', 'dark'] as $theme) {
                cdp_evaluate($page, 'document.documentElement.setAttribute("data-theme", ' . json_encode($theme) . ')');
                assert_equal(true, cdp_evaluate($page, 'document.documentElement.scrollWidth <= innerWidth'), "$width $theme: no horizontal overflow");
            }
        }
        cdp_evaluate($page, 'document.getElementById("sess-info").close()');
        $set(['ok' => false, 'message' => 'unavailable'], ['ok' => false, 'message' => 'fixture refused rotation']);
        cdp_evaluate($page, 'window.__fallbackFetch = window.fetch; window.__fallbackDone = false; window.fetch = function (url, opts) { var p = window.__fallbackFetch.apply(this, arguments); if (String(url).indexOf("/action") === 0 && opts && opts.body && new URLSearchParams(opts.body).get("action") === "flow_continuation") { return p.then(function (response) { window.__fallbackDone = true; return response; }); } return p; }; document.querySelector("[data-open=sess-info]").click(); var reason = document.querySelector("[data-continuation-why]"); window.__fallbackLoading = reason ? reason.textContent : "";');
        assert_true(continuation_wait($page, 'window.__fallbackDone === true'), 'failed recommendation request completes before retry');
        cdp_evaluate($page, 'window.fetch = window.__fallbackFetch');
        continuation_wait($page, '!!document.querySelector("[data-continuation-why]")');
        assert_true(continuation_wait($page, '!!document.querySelector("[data-info=takeover-new]")'), 'takeover fallback remains available on error');
        assert_true(continuation_wait($page, '!!document.querySelector("[data-continuation-why]") && document.querySelector("[data-continuation-why]").textContent.trim().length > 0 && document.querySelector("[data-continuation-why]").textContent !== window.__fallbackLoading'), 'failure replaces loading with explanatory reason');
        assert_equal('takeover-new', cdp_evaluate($page, '(document.querySelector("#sess-info-actions [data-info=rotate-new], #sess-info-actions [data-info=takeover-new]") || {}).dataset.info || null'), 'fallback puts takeover first');
        cdp_evaluate($page, 'window.__originalFetch = window.fetch; window.fetch = function (url, opts) { if (String(url).indexOf("/action") === 0 && opts && opts.body && new URLSearchParams(opts.body).get("action") === "flow_continue_rotate") { return new Promise(function (resolve) { window.__releaseRotate = resolve; }); } return window.__originalFetch.apply(this, arguments); }; var b = document.querySelector("[data-info=rotate-new]"); if (b) b.click()');
        assert_true(continuation_wait($page, 'typeof window.__releaseRotate === "function"'), 'rotate starts pending request');
        assert_equal(true, cdp_evaluate($page, '(function () { var b = document.querySelectorAll("#sess-info-actions [data-info=rotate-new], #sess-info-actions [data-info=takeover-new]"); return b.length === 2 && Array.prototype.every.call(b, function (x) { return x.disabled; }); }())'), 'both choices disabled while rotation is pending');
        cdp_evaluate($page, 'if (window.__releaseRotate) window.__releaseRotate(new Response(JSON.stringify({ok:false,message:"fixture refused rotation"}), {status:200,headers:{"Content-Type":"application/json"}})); window.fetch = window.__originalFetch;');
        assert_true(continuation_wait($page, 'document.body.textContent.indexOf("fixture refused rotation") >= 0'), 'failed rotation displays host error');
        assert_equal(true, cdp_evaluate($page, 'location.search.indexOf("cc-20260101-1200") >= 0'), 'failed rotation does not navigate');
        assert_equal(true, cdp_evaluate($page, '(function () { var b = document.querySelector("[data-info=rotate-new]"); return !!b && !b.disabled; }())'), 'failed rotation re-enables controls');
        $set(['ok' => false, 'message' => 'unavailable']);
        cdp_evaluate($page, 'var b = document.querySelector("[data-info=rotate-new]"); if (b) b.click()');
        assert_true(continuation_wait($page, 'location.pathname === "/session" && location.search.indexOf("cc-fake-headless") >= 0'), 'rotate follows successful receiver redirect');
        $requests = $entries();
        assert_equal(['flow_continue_rotate', 'cc-20260101-1200'], [$requests[0]['action'] ?? null, $requests[0]['session'] ?? null], 'rotate click posts fresh action with source');
        $set(['ok' => true, 'action' => 'rotate', 'can_rotate' => true, 'why' => 'pending test']);
        cdp_navigate($page, $base . '/session?session=cc-20260101-1200');
        assert_true(continuation_wait($page, '!!document.querySelector("[data-open=sess-info]")'), 'pending test: opener available');
        cdp_evaluate($page, 'document.querySelector("[data-open=sess-info]").click()');
        assert_true(continuation_wait($page, '!!document.querySelector("[data-continuation-why]") && document.querySelector("[data-continuation-why]").textContent.indexOf("pending test") >= 0'), 'pending test: recommendation loaded');
        cdp_evaluate($page, 'window.__pendingFetch = window.fetch; window.__pendingCalls = 0; window.fetch = function (url, opts) { if (String(url).indexOf("/action") === 0 && opts && opts.body && new URLSearchParams(opts.body).get("action") === "flow_continue_rotate") { window.__pendingCalls++; return new Promise(function (resolve) { window.__resolvePending = resolve; }); } return window.__pendingFetch.apply(this, arguments); }; document.querySelector("[data-info=rotate-new]").click()');
        assert_true(continuation_wait($page, 'typeof window.__resolvePending === "function"'), 'pending test: rotate request held');
        cdp_evaluate($page, 'document.getElementById("sess-info").close(); document.querySelector("[data-open=sess-info]").click()');
        assert_true(continuation_wait($page, '!!document.querySelector("[data-info=rotate-new]") && !!document.querySelector("[data-info=takeover-new]")'), 'pending test: sheet reopened');
        assert_equal(true, cdp_evaluate($page, '(function () { var b = document.querySelectorAll("#sess-info-actions [data-info=rotate-new], #sess-info-actions [data-info=takeover-new]"); return b.length === 2 && Array.prototype.every.call(b, function (x) { return x.disabled; }); }())'), 'pending test: both actions stay disabled after reopening');
        cdp_evaluate($page, '(function () { var r = document.querySelector("[data-info=rotate-new]"), t = document.querySelector("[data-info=takeover-new]"); if (r && t && r.disabled && t.disabled) { r.click(); t.click(); } }())');
        assert_equal(1, cdp_evaluate($page, 'window.__pendingCalls'), 'pending test: another rotate cannot be dispatched');
        assert_equal('/session', cdp_evaluate($page, 'location.pathname'), 'pending test: takeover cannot navigate while rotation is pending');
        cdp_evaluate($page, 'history.pushState({}, "", "/session?session=newer"); window.__resolvePending(new Response(JSON.stringify({ok:true,redirect:"/session?session=late"}), {status:200,headers:{"Content-Type":"application/json"}})); window.fetch = window.__pendingFetch');
        usleep(400000);
        assert_equal('?session=newer', cdp_evaluate($page, 'location.search'), 'late successful rotation cannot replace a newer navigation');
        foreach ([['agent' => 'claude', 'profile' => 'work'], ['agent' => 'codex', 'profile' => 'main']] as $receiver) {
            $flow = ['state' => 'ROTATE', 'why' => 'ready', 'trigger' => 'context', 'receiver' => $receiver];
            $set(['ok' => true, 'action' => 'rotate', 'can_rotate' => true, 'why' => 'ready'], ['ok' => false, 'message' => 'fixture refused rotation'], $flow);
            $receiverReady = '(function () { var u = window.UiSession, d = u && u.api && u.api.detail(); return !!d && u.session === "cc-20260101-1200" && location.search === "?session=cc-20260101-1200" && d.name === "cc-20260101-1200" && !!d.flow && !!d.flow.receiver && d.flow.receiver.agent === ' . json_encode($receiver['agent']) . ' && d.flow.receiver.profile === ' . json_encode($receiver['profile']) . '; }())';
            assert_equal(false, cdp_evaluate($page, $receiverReady), 'receiver fixture: previous page does not satisfy current receiver readiness');
            assert_true(cdp_navigate($page, $base . '/session?session=cc-20260101-1200'), 'receiver fixture: session navigation succeeds');
            assert_true(continuation_wait($page, $receiverReady), 'receiver fixture: current detail contains the expected receiver');
            assert_true(continuation_wait($page, '!!document.querySelector("[data-open=sess-info]")'), 'receiver fixture: details opener available');
            cdp_evaluate($page, 'document.querySelector("[data-open=sess-info]").click()');
            continuation_wait($page, '!!document.querySelector("[data-info=takeover-new]")');
            assert_true(continuation_wait($page, '!!document.querySelector("[data-continuation-why]") && document.querySelector("[data-continuation-why]").textContent.indexOf("ready") >= 0'), 'receiver fixture: recommendation loaded');
            cdp_evaluate($page, 'var b = document.querySelector("[data-info=rotate-new]"); if (b) b.click()');
            continuation_wait($page, '!!document.querySelector("#ui-toast") && document.querySelector("#ui-toast").textContent.indexOf("fixture refused rotation") >= 0');
            $sent = $entries(); $rotation = end($sent) ?: [];
            assert_equal(['flow_continue_rotate', $receiver['agent'], $receiver['profile']], [$rotation['action'] ?? null, $rotation['agent'] ?? null, $rotation['profile'] ?? null], 'rotate forwards receiver agent and profile');
            cdp_evaluate($page, 'var oldSet = Storage.prototype.setItem; Storage.prototype.setItem = function (k, v) { if (k === "ui-new-prefill") oldSet.call(this, "__continuation_prefill", v); return oldSet.call(this, k, v); }; document.querySelector("[data-info=takeover-new]").click()');
            assert_true(continuation_wait($page, 'location.pathname === "/"'), 'takeover opens New Session');
            $prefill = cdp_evaluate($page, 'JSON.parse(sessionStorage.getItem("__continuation_prefill") || "{}")');
            assert_equal($receiver['agent'], $prefill['agent'] ?? null, 'takeover selects receiver agent');
            assert_equal('/home/user/www/demo-project', $prefill['workdir'] ?? null, 'takeover preserves workdir');
            assert_contains('claude work 11111111-2222-4333-8444-555555555555', (string) ($prefill['prompt'] ?? ''), 'takeover preserves source agent, account, and conversation');
            assert_equal($receiver['agent'] === 'claude' ? 'work' : null, $prefill['profile'] ?? null, 'takeover preserves applicable receiver profile');
            assert_equal($receiver['agent'] === 'claude' ? 'sonnet' : null, $prefill['model'] ?? null, 'takeover keeps model only on same account');
        }
        $set(['ok' => true, 'action' => 'takeover', 'can_rotate' => false, 'why' => 'Source ended'], ['ok' => false, 'message' => 'Source ended']);
        assert_true(cdp_navigate($page, $base . '/archived-session?agent_session_id=99999999-8888-4777-a666-555555555555'), 'archived conversation loads');
        assert_true(continuation_wait($page, 'document.querySelectorAll("#sess-list > *").length > 5 && !!document.querySelector("[data-open=sess-info]")'), 'archived transcript and details opener are ready');
        cdp_evaluate($page, 'document.querySelector("[data-open=sess-info]").click()');
        assert_true(continuation_wait($page, '!!document.querySelector("[data-info=rotate-new]") && !!document.querySelector("[data-info=takeover-new]")'), 'archived sheet offers both choices');
        assert_true(continuation_wait($page, '!!document.querySelector("[data-continuation-why]") && document.querySelector("[data-continuation-why]").textContent.indexOf("Source ended") >= 0'), 'archived sheet explains takeover recommendation');
        cdp_evaluate($page, 'var b = document.querySelector("[data-info=rotate-new]"); if (b) b.click()');
        assert_true(continuation_wait($page, '!!document.querySelector("#ui-toast") && document.querySelector("#ui-toast").textContent.indexOf("Source ended") >= 0'), 'archived rotation reports refusal');
        assert_equal('/archived-session', cdp_evaluate($page, 'location.pathname'), 'archived rotation does not navigate');
        assert_equal([], cdp_drain_console_errors($page), 'browser console stays clean');
    }
} finally {
    if ($page !== null) cdp_close_page($page);
    if ($browser !== null) cdp_shutdown($browser);
    if (is_resource($server)) { proc_terminate($server); proc_close($server); }
    stop_harness($harness, $socket);
    exec('rm -rf ' . escapeshellarg($root));
}
test_exit();
