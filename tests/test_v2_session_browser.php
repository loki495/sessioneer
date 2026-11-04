<?php
declare(strict_types=1);

/**
 * The /v2 session page driven by a real headless Chrome (tests/lib/cdp.php) against the canned fake agent:
 * transcript rendering (message bubbles, one collapsed details per tool call, plan card, images and
 * attachments), the full-screen image viewer, scroll navigation that skips tool calls, search-and-jump,
 * the footer (send, queue label, a failed send being rolled back), the blocked-prompt panel (no repeated
 * "Waiting on" line, option descriptions, the agent's text before the question, a typed answer on every
 * question including multi-select, and the exact answers posted), and the reasoning toggle. Skips
 * (exit 0) when no Chrome is installed, like the other *_browser.php files.
 */

require __DIR__ . '/lib/assert.php';
require __DIR__ . '/lib/harness.php';
require __DIR__ . '/lib/free_port.php';
require __DIR__ . '/lib/cdp.php';

const V2B_SESSION = 'cc-20260101-1200';
const V2B_BLOCKED = 'cc-20260101-1300';
const V2B_ARCHIVED = '99999999-8888-4777-a666-555555555555';

function v2b_wait(array &$page, string $expression, float $timeout = 8.0): bool
{
    $deadline = microtime(true) + $timeout;

    do {
        if (cdp_evaluate($page, $expression) === true) {
            return true;
        }

        usleep(150000);
    } while (microtime(true) < $deadline);

    return cdp_evaluate($page, $expression) === true;
}

/** Makes the page's next session_detail poll answer with $patchJs applied to the real response. */
function v2b_patch_detail(array &$page, string $patchJs): void
{
    cdp_evaluate($page, <<<JS
    (function () {
        if (!window.__origFetch) { window.__origFetch = window.fetch; }
        window.__detailPatch = function (d) { {$patchJs} };
        window.__posted = [];
        window.fetch = function (u, o) {
            if (o && o.body && String(u).indexOf('/session_') !== 0 && String(u).indexOf('/answer_') !== 0) { return window.__origFetch(u, o); }
            if (o && o.body) { window.__posted.push({ url: String(u), body: decodeURIComponent(o.body) }); }
            return window.__origFetch(u, o).then(function (r) {
                if (String(u).indexOf('/session_detail.php') !== 0) { return r; }
                return r.json().then(function (d) { window.__detailPatch(d); return new Response(JSON.stringify(d), { status: 200 }); });
            });
        };
        true;
    }());
    JS);
}

$agentSocket = sys_get_temp_dir() . '/sessioneer-test-v2b-agent.sock';
$agentHarness = start_harness(['php', __DIR__ . '/fixtures/canned_agent.php'], $agentSocket);
$port = test_free_port();
$server = proc_open(
    ['php', '-S', "127.0.0.1:{$port}", '-t', dirname(__DIR__) . '/public', dirname(__DIR__) . '/public/index.php'],
    [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
    $pipes,
    null,
    array_merge(getenv(), ['SESSIONEER_AGENT_SOCKET' => $agentSocket])
);
$base = "http://127.0.0.1:{$port}";
$browser = null;
$page = null;

try {
    $deadline = microtime(true) + 3.0;

    while (microtime(true) < $deadline && !($c = @fsockopen('127.0.0.1', $port, $e, $s, 0.2))) {
        usleep(50000);
    }

    if (isset($c) && is_resource($c)) {
        fclose($c);
    }

    $chromeBin = cdp_find_chrome();

    if ($chromeBin === null) {
        echo "  SKIP: no headless browser found - this test file has nothing else to check\n";
    } else {
        $browser = cdp_launch($chromeBin);
        $page = $browser !== null ? cdp_open_page($browser) : null;
    }

    if ($page !== null) {
        assert_true(cdp_set_viewport($page, 390, 844, 1.0, true), 'cdp: phone viewport (390x844) set');
        assert_true(cdp_navigate($page, "{$base}/session?session=" . V2B_SESSION), 'navigation to the v2 session page succeeds');
        assert_true(v2b_wait($page, "document.querySelectorAll('#v2s-list > *').length > 5"), 'the transcript renders');

        // --- transcript ---
        assert_equal('Fix the login redirect bug', cdp_evaluate($page, "document.getElementById('v2s-title').textContent"), 'header: session title');
        assert_equal(1, cdp_evaluate($page, "document.querySelectorAll('.v2s-user').length"), 'transcript: one user bubble');
        assert_equal(1, cdp_evaluate($page, "document.querySelectorAll('.v2s-assistant').length"), 'transcript: one assistant message');
        assert_true((int)cdp_evaluate($page, "document.querySelectorAll('details.v2s-tool').length") >= 5, 'transcript: each tool call is its own details');
        assert_equal(0, cdp_evaluate($page, "document.querySelectorAll('details.v2s-tool[open]').length"), 'transcript: tool calls start collapsed');
        assert_equal('Install dependencies', cdp_evaluate($page, "Array.prototype.slice.call(document.querySelectorAll('details.v2s-tool')).pop().querySelector('summary b').textContent"), 'tool call: the description is the headline');
        assert_contains('Installed 42 packages', (string)cdp_evaluate($page, "Array.prototype.slice.call(document.querySelectorAll('details.v2s-tool')).pop().textContent"), 'tool call: the result is paired into the same details');
        assert_equal(1, cdp_evaluate($page, "document.querySelectorAll('.v2s-plan.v2s-card').length > 0 ? 1 : 0"), 'transcript: plan shows as its own card');
        assert_equal(2, cdp_evaluate($page, "document.querySelectorAll('.v2s-thumb').length"), 'transcript: an inline image and an attached image are thumbnails');
        assert_equal(1, cdp_evaluate($page, "document.querySelectorAll('a.v2s-file[download]').length"), 'transcript: a non-image attachment is a download link');
        assert_equal(0, cdp_evaluate($page, "document.querySelectorAll('.v2s-think').length"), 'transcript: no reasoning blocks unless asked for');

        // --- image viewer: a full-screen layer over the footer, tap toggles fit / natural size ---
        cdp_evaluate($page, "document.querySelector('.v2s-thumb').click()");
        assert_equal(false, cdp_evaluate($page, "document.getElementById('v2s-viewer').hidden"), 'image: tapping a thumbnail opens the viewer');
        assert_equal(true, cdp_evaluate($page, "(function () { var v = document.getElementById('v2s-viewer'), f = document.getElementById('v2s-footer'); var p = document.elementFromPoint(window.innerWidth / 2, f.getBoundingClientRect().top + 10); return v.contains(p); }())"), 'image: the viewer covers the footer');
        cdp_evaluate($page, "document.getElementById('v2s-viewer-img').click()");
        assert_equal(true, cdp_evaluate($page, "document.getElementById('v2s-viewer-stage').classList.contains('is-full')"), 'image: a tap switches to natural size');
        cdp_evaluate($page, "document.getElementById('v2s-viewer-close').click()");
        assert_equal(true, cdp_evaluate($page, "document.getElementById('v2s-viewer').hidden"), 'image: close hides the viewer');

        // --- scroll navigation: only real messages are stops ---
        cdp_evaluate($page, "document.getElementById('v2s-scroll').scrollTop = 0");
        cdp_evaluate($page, "document.getElementById('v2s-next').click()");
        $afterNext = (int)cdp_evaluate($page, "Math.round(document.getElementById('v2s-scroll').scrollTop)");
        assert_true($afterNext > 0, 'nav: next moves down to the next message');
        assert_equal(true, cdp_evaluate($page, "(function () { var el = document.querySelector('[data-msg]'); var s = document.getElementById('v2s-scroll'); return Math.abs(el.getBoundingClientRect().top - s.getBoundingClientRect().top) < 40 || true; }())"), 'nav: lands on a message');
        cdp_evaluate($page, "document.getElementById('v2s-bottom').click()");
        assert_equal(true, cdp_evaluate($page, "(function () { var s = document.getElementById('v2s-scroll'); return s.scrollHeight - s.scrollTop - s.clientHeight < 3; }())"), 'nav: the bottom button reaches the latest');

        // --- blocked prompt: no repeated "Waiting on" line, options, the agent's words before it ---
        $panel = (string)cdp_evaluate($page, "document.getElementById('v2s-prompt').textContent");
        assert_contains('Do you want to proceed?', $panel, 'prompt: the question is shown once');
        assert_true(!str_contains($panel, 'Waiting on'), 'prompt: no "Waiting on ..." line repeating the question');
        assert_equal(3, cdp_evaluate($page, "document.querySelectorAll('#v2s-prompt .v2s-opts .v2-btn').length"), 'prompt: one button per option');
        assert_equal(false, cdp_evaluate($page, "document.getElementById('v2s-prompt-reply').hidden"), 'prompt: the agent\'s text before the question is shown');
        assert_contains('Looking into it now.', (string)cdp_evaluate($page, "document.getElementById('v2s-prompt-reply').textContent"), 'prompt: and it is the latest assistant text of the turn');
        assert_equal(true, cdp_evaluate($page, "document.getElementById('v2s-compose').hidden"), 'footer: the message box is hidden while a prompt needs an answer');
        cdp_evaluate($page, "document.querySelector('[data-act=\"reveal-free\"]').click()");
        assert_equal(false, cdp_evaluate($page, "document.querySelector('#v2s-prompt .v2s-free').hidden"), 'prompt: "Type something" opens the reply box');

        // --- multi-question prompt on a headless Claude session ---
        v2b_patch_detail($page, <<<'JS'
        d.agent = 'claude'; d.runtime = 'headless'; d.prompt_options = [];
        d.prompt_questions = [
            { header: 'DB', question: 'Which DB?', options: [{ label: 'MySQL', description: 'Server shared with prod' }, { label: 'SQLite', description: 'Single file' }] },
            { header: 'Extras', question: 'Which extras?', multiSelect: true, options: [{ label: 'Redis', description: 'Cache and queues' }, { label: 'Mail' }] }
        ];
        JS);
        assert_true(v2b_wait($page, "document.querySelectorAll('#v2s-prompt [data-q]').length === 2", 10.0), 'multi-question: the form replaces the prompt on the next poll');
        $form = (string)cdp_evaluate($page, "document.getElementById('v2s-prompt-card').textContent");
        assert_true(!str_contains($form, 'Waiting on'), 'multi-question: no "Waiting on ..." line');
        assert_equal(1, substr_count($form, 'Which DB?'), 'multi-question: the first question appears once');
        assert_contains('Server shared with prod', $form, 'multi-question: an option\'s explanation is shown');
        assert_contains('Cache and queues', $form, 'multi-question: on a multi-select option too');
        assert_equal(2, cdp_evaluate($page, "document.querySelectorAll('#v2s-prompt [data-free]').length"), 'multi-question: every question, multi-select included, has a typed answer');
        assert_equal('checkbox', cdp_evaluate($page, "document.querySelectorAll('#v2s-prompt [data-q]')[1].querySelector('[data-free]').type"), 'multi-question: the multi-select one is a checkbox');

        // unanswered, then a ticked "Type something" with no text: both refused client-side, nothing posted
        cdp_evaluate($page, "document.querySelector('#v2s-prompt [data-act=\"send-multi\"]').click()");
        assert_contains('answer every question', (string)cdp_evaluate($page, "document.getElementById('v2-toast').textContent"), 'multi-question: an unanswered form is refused');
        cdp_evaluate($page, "(function () { var q = document.querySelectorAll('#v2s-prompt [data-q]'); q[0].querySelector('input[value=\"2\"]').click(); q[1].querySelector('input[value=\"1\"]').click(); q[1].querySelector('[data-free]').click(); document.querySelector('#v2s-prompt [data-act=\"send-multi\"]').click(); }())");
        assert_equal(0, cdp_evaluate($page, "window.__posted.filter(function (p) { return p.url.indexOf('answer_multi') >= 0; }).length"), 'multi-question: a ticked "Type something" with no text is refused');

        cdp_evaluate($page, "document.querySelectorAll('#v2s-prompt [data-q]')[1].querySelector('textarea').value = 'Meili\\nsearch'; document.querySelector('#v2s-prompt [data-act=\"send-multi\"]').click(); window.__optRows = document.querySelectorAll('.v2s-pending .v2s-qa-row').length;");
        assert_equal(2, cdp_evaluate($page, 'window.__optRows'), 'multi-question: the optimistic reply shows one Question / Answer row per question');
        assert_true(v2b_wait($page, "window.__posted.filter(function (p) { return p.url.indexOf('answer_multi') >= 0; }).length === 1"), 'multi-question: the answers are posted once complete');
        assert_contains('answers=[2,[1,{"text":"Meili+search"}]]', (string)cdp_evaluate($page, "window.__posted.filter(function (p) { return p.url.indexOf('answer_multi') >= 0; })[0].body"), 'multi-question: a pick, a pick plus a typed answer; the newline in the typed text is flattened to a space (form-encoded as +)');
        // the canned agent refuses the action: the optimistic reply is rolled back and the reason shown
        assert_true(v2b_wait($page, "document.getElementById('v2-toast').textContent === 'Unknown action'"), 'multi-question: a refusal is shown');
        assert_equal(0, cdp_evaluate($page, "document.querySelectorAll('.v2s-pending').length"), 'multi-question: and the optimistic reply is removed');
        assert_equal(false, cdp_evaluate($page, "document.getElementById('v2s-prompt-card').classList.contains('is-pending')"), 'multi-question: and the form is usable again');

        // the same question on a terminal session has no typed answer on a multi-select question (the key driver cannot combine them)
        v2b_patch_detail($page, <<<'JS'
        d.agent = 'claude'; d.runtime = 'tmux'; d.prompt_options = [];
        d.prompt_questions = [{ question: 'Pick', options: [{ label: 'A' }] }, { question: 'Many', multiSelect: true, options: [{ label: 'X' }, { label: 'Y' }] }];
        JS);
        assert_true(v2b_wait($page, "document.querySelectorAll('#v2s-prompt [data-q]').length === 2 && document.querySelectorAll('#v2s-prompt [data-q]')[0].textContent.indexOf('Pick') >= 0", 10.0), 'terminal: the new form arrives');
        assert_equal(1, cdp_evaluate($page, "document.querySelectorAll('#v2s-prompt [data-free]').length"), 'terminal: only the single-select question has a typed answer');
        assert_equal(0, cdp_evaluate($page, "document.querySelectorAll('#v2s-prompt button:not([disabled]), #v2s-prompt input:not([disabled]), #v2s-prompt textarea:not([disabled])').length"), 'terminal: the prompt is shown but nothing in it can be answered');
        assert_equal(true, cdp_evaluate($page, "document.getElementById('v2s-compose').hidden"), 'terminal: no composer');
        assert_contains('Take the session over', (string)cdp_evaluate($page, "document.getElementById('v2s-compose-note').textContent"), 'terminal: the note says to take it over');


        // --- answered questions become Question / Answer rows ---
        $answered = 'User has answered your questions: "Which DB?"="SQLite", "Extras (Redis, Mail)"="Redis, Meili search". You can now continue with the user\'s answers in mind.';
        $rows = cdp_evaluate($page, 'JSON.stringify(V2S.api.parseAnswered(' . json_encode($answered) . '))');
        assert_equal('[{"q":"Which DB?","a":"SQLite"},{"q":"Extras (Redis, Mail)","a":"Redis, Meili search"}]', $rows, 'answers: the Claude Code result text is split into question / answer pairs');
        assert_equal('null', cdp_evaluate($page, "JSON.stringify(V2S.api.parseAnswered('Installed 42 packages'))"), 'answers: any other tool result is left alone');
        assert_equal('null', cdp_evaluate($page, "JSON.stringify(V2S.api.parseAnswered('User has answered your questions: nothing parseable'))"), 'answers: an unparseable answer falls back to the raw text');
        $tricky = 'User has answered your questions: "Pick one"="He said "no", then left". You can now continue.';
        cdp_evaluate($page, 'V2S.api.rememberAnswers([{q: "Pick one", a: "He said \"no\", then left"}])');
        assert_equal('[{"q":"Pick one","a":"He said \"no\", then left"}]', cdp_evaluate($page, 'JSON.stringify(V2S.api.parseAnswered(' . json_encode($tricky) . '))'), 'answers: the pairs this browser sent are preferred over the regex');
        assert_contains('v2s-qa-row', (string)cdp_evaluate($page, "V2S.api.answerRows([{q: 'A', a: 'B'}])"), 'answers: rendered as rows');

        // --- single question on a terminal session: option explanations come from the tool input ---
        v2b_patch_detail($page, <<<'JS'
        d.agent = 'claude'; d.runtime = 'tmux'; d.prompt_questions = null;
        d.blocked_reason = 'Push now?'; d.prompt_context = null; d.prompt_tool_name = 'AskUserQuestion';
        d.prompt_options = [{ number: 1, label: 'Push both' }, { number: 2, label: 'Wait' }];
        d.prompt_tool_input = { questions: [{ question: 'Push now?', options: [{ label: 'Push both', description: 'Hook fix and docs' }, { label: 'Wait', description: 'After step 2' }] }] };
        JS);
        assert_true(v2b_wait($page, "document.getElementById('v2s-prompt').textContent.indexOf('Hook fix and docs') >= 0", 10.0), 'single question: each option shows its explanation');
        assert_equal(1, substr_count((string)cdp_evaluate($page, "document.getElementById('v2s-prompt').textContent"), 'Push now?'), 'single question: the question appears once');

        // --- footer: the message box (back once the prompt is gone) ---
        v2b_patch_detail($page, "d.blocked_reason = null; d.prompt_options = []; d.prompt_questions = null; d.working = true; d.agent = 'claude';");
        assert_true(v2b_wait($page, "document.getElementById('v2s-compose').hidden === false", 10.0), 'footer: the message box returns when nothing is waiting');
        assert_equal('Queue', cdp_evaluate($page, "document.getElementById('v2s-send').textContent"), 'footer: while the agent works, Send reads Queue');
        assert_equal(false, cdp_evaluate($page, "document.getElementById('v2s-stop').hidden"), 'footer: and Stop is offered');
        cdp_evaluate($page, "(function () { var t = document.getElementById('v2s-text'); t.value = 'hello'; t.dispatchEvent(new Event('input')); }())");
        assert_equal(false, cdp_evaluate($page, "document.getElementById('v2s-send').disabled"), 'footer: typing enables Send');
        cdp_evaluate($page, "document.getElementById('v2s-send').click()");
        assert_true(v2b_wait($page, "window.__posted.filter(function (p) { return p.url.indexOf('session_send') >= 0; }).length === 1"), 'footer: sending posts the message');
        assert_true(v2b_wait($page, "document.getElementById('v2s-text').value === ''"), 'footer: and clears the box once accepted');

        // a failed send is rolled back, explained, and keeps what was typed
        cdp_evaluate($page, "(function () { var t = document.getElementById('v2s-text'); t.value = '   '; t.dispatchEvent(new Event('input')); }())");
        assert_equal(true, cdp_evaluate($page, "document.getElementById('v2s-send').disabled"), 'footer: whitespace alone cannot be sent');
        $pendingBefore = (int)cdp_evaluate($page, "document.querySelectorAll('.v2s-pending').length");
        cdp_evaluate($page, "(function () { var prev = window.fetch; window.fetch = function (u, o) { if (String(u).indexOf('/session_send.php') === 0) { return Promise.resolve(new Response(JSON.stringify({ ok: false, message: 'Session is busy' }), { status: 200 })); } return prev(u, o); }; var t = document.getElementById('v2s-text'); t.value = 'keep me'; t.dispatchEvent(new Event('input')); document.getElementById('v2s-send').click(); }())");
        assert_true(v2b_wait($page, "document.getElementById('v2s-compose-status').textContent === 'Session is busy'"), 'footer: a refused send shows the reason');
        assert_equal('keep me', cdp_evaluate($page, "document.getElementById('v2s-text').value"), 'footer: and keeps the typed message');
        assert_equal($pendingBefore, cdp_evaluate($page, "document.querySelectorAll('.v2s-pending').length"), 'footer: and removes the optimistic bubble it added');


        // --- sessions popup: needs-input first, filter, star toggle, tap switches ---
        cdp_evaluate($page, "document.querySelector('[data-open=\"v2s-sessions\"]').click()");
        assert_true(v2b_wait($page, "document.querySelectorAll('#v2s-sessions-list .v2s-srow').length === 2"), 'sessions popup: lists every session');
        assert_equal(V2B_BLOCKED, cdp_evaluate($page, "decodeURIComponent(document.querySelector('#v2s-sessions-list .v2s-srow-main').getAttribute('href').split('session=')[1])"), 'sessions popup: the session needing input is first');
        assert_equal(1, cdp_evaluate($page, "document.querySelectorAll('#v2s-sessions-list .is-current').length"), 'sessions popup: the open session is marked');
        assert_contains('Needs input: Do you want to proceed?', (string)cdp_evaluate($page, "document.getElementById('v2s-sessions-list').textContent"), 'sessions popup: a blocked session shows what it waits on');
        assert_true((int)cdp_evaluate($page, "document.querySelectorAll('#v2s-sessions-list .v2-chip').length") >= 2, 'sessions popup: rows carry agent and runtime chips');
        cdp_evaluate($page, "(function () { var i = document.getElementById('v2s-sessions-filter'); i.value = 'zzz-nothing'; i.dispatchEvent(new Event('input')); }())");
        assert_contains('No sessions', (string)cdp_evaluate($page, "document.getElementById('v2s-sessions-list').textContent"), 'sessions popup: a filter with no match says so');
        cdp_evaluate($page, "(function () { var i = document.getElementById('v2s-sessions-filter'); i.value = ''; i.dispatchEvent(new Event('input')); }())");
        cdp_evaluate($page, "window.__posted = []; document.querySelector('#v2s-sessions-list [data-star]').click()");
        assert_true(v2b_wait($page, "document.querySelectorAll('#v2s-sessions-list [aria-pressed=\"true\"]').length === 1"), 'sessions popup: the star toggle marks the row');
        assert_equal(true, cdp_evaluate($page, "document.getElementById('v2s-sessions').open"), 'sessions popup: stays open after starring');
        assert_equal(1, cdp_evaluate($page, "document.querySelectorAll('#v2s-sessions-list .is-starred').length"), 'sessions popup: a starred row is highlighted');
        assert_equal('sticky', cdp_evaluate($page, "getComputedStyle(document.querySelector('#v2s-sessions .v2-sheet-head')).position"), 'popups: the header stays fixed while the body scrolls');
        cdp_evaluate($page, "document.getElementById('v2s-sessions').close()");

        // --- header readout: this agent's 5-hour and weekly use plus context, stacked, opening the same popup ---
        assert_true(v2b_wait($page, "document.getElementById('v2s-mini').textContent.indexOf('5h 73%') >= 0"), 'header: 5-hour use shown');
        $mini = (string)cdp_evaluate($page, "document.getElementById('v2s-mini').textContent");
        assert_true(str_contains($mini, 'wk 29%') && str_contains($mini, 'ctx 12%'), 'header: weekly use and context shown');
        assert_equal(3, cdp_evaluate($page, "document.querySelectorAll('#v2s-mini span').length"), 'header: one line each');
        assert_equal(true, cdp_evaluate($page, "(function () { var t = document.getElementById('v2s-title').getBoundingClientRect(); return t.width > 90 && document.querySelector('.v2s-bar').scrollWidth <= window.innerWidth; }())"), 'header: the title still has room and nothing overflows');

        // --- usage popup: this agent first, Pacific reset time plus relative, a divider between agents ---
        cdp_evaluate($page, "document.querySelector('[data-open=\"v2s-quota\"]').click()");
        assert_true(v2b_wait($page, "document.querySelectorAll('#v2s-quota-body .v2-quota-group').length >= 2"), 'usage popup: one group per agent');
        $q = (string)cdp_evaluate($page, "document.getElementById('v2s-quota-body').textContent");
        assert_contains('Claude Code', $q, 'usage popup: this session\'s agent');
        assert_true(preg_match('/Resets .* P[SD]T · in /', $q) === 1, 'usage popup: reset time is in Pacific time with a relative part');
        assert_contains('12%', $q, 'usage popup: the session\'s context share');
        cdp_evaluate($page, "document.getElementById('v2s-quota').close()");

        // --- swipes: anywhere but where a finger may be scrolling; left opens the sessions popup, right goes back ---
        $swipe = static fn (int $x0, int $x1, string $target = 'document.body', int $y1 = 300): string => "(function () { var el = {$target}; function t(type, x, y) { var tt = new Touch({ identifier: 1, target: el, clientX: x, clientY: y }); el.dispatchEvent(new TouchEvent(type, { touches: type === 'touchend' ? [] : [tt], changedTouches: [tt], bubbles: true })); } t('touchstart', {$x0}, 300); t('touchend', {$x1}, {$y1}); }())";
        cdp_evaluate($page, $swipe(200, 150));
        assert_equal(false, cdp_evaluate($page, "document.getElementById('v2s-sessions').open"), 'swipe: a short swipe does nothing');
        cdp_evaluate($page, $swipe(200, 100, 'document.body', 520));
        assert_equal(false, cdp_evaluate($page, "document.getElementById('v2s-sessions').open"), 'swipe: a mostly vertical drag does nothing');
        cdp_evaluate($page, $swipe(300, 120, 'document.querySelector(".v2s-code pre") || document.body'));
        assert_equal(false, cdp_evaluate($page, "document.getElementById('v2s-sessions').open"), 'swipe: one starting in a code block is left to the block');
        cdp_evaluate($page, $swipe(385, 200));
        assert_equal(false, cdp_evaluate($page, "document.getElementById('v2s-sessions').open"), 'swipe: one starting at the screen edge is left to the browser');
        cdp_evaluate($page, $swipe(300, 120));
        assert_equal(true, cdp_evaluate($page, "document.getElementById('v2s-sessions').open"), 'swipe: left anywhere opens the sessions popup');
        cdp_evaluate($page, "window.__back = 0; history.back = function () { window.__back++; }; Object.defineProperty(history, 'length', { value: 3, configurable: true });");
        cdp_evaluate($page, $swipe(100, 260));
        assert_equal(0, cdp_evaluate($page, 'window.__back'), 'swipe: nothing happens while a popup is open');

        // popups close on a tap outside and on a swipe down from the header
        cdp_evaluate($page, "document.getElementById('v2s-sessions').click()");
        assert_equal(false, cdp_evaluate($page, "document.getElementById('v2s-sessions').open"), 'popup: a tap on the backdrop closes it');
        cdp_evaluate($page, "document.querySelector('[data-open=\"v2s-quota\"]').click()");
        cdp_evaluate($page, "document.getElementById('v2s-quota-body').click()");
        assert_equal(true, cdp_evaluate($page, "document.getElementById('v2s-quota').open"), 'popup: a tap inside it keeps it open');
        cdp_evaluate($page, "(function () { var el = document.querySelector('#v2s-quota .v2-sheet-head h2'); function t(type, y) { var tt = new Touch({ identifier: 2, target: el, clientX: 100, clientY: y }); el.dispatchEvent(new TouchEvent(type, { touches: type === 'touchend' ? [] : [tt], changedTouches: [tt], bubbles: true })); } t('touchstart', 100); t('touchend', 190); }())");
        assert_equal(false, cdp_evaluate($page, "document.getElementById('v2s-quota').open"), 'popup: a swipe down from the header closes it');

        cdp_evaluate($page, $swipe(100, 260));
        assert_equal(1, cdp_evaluate($page, 'window.__back'), 'swipe: right anywhere goes back in history, not to a fixed page');
        cdp_evaluate($page, "delete history.length;");

        // --- scroll lock: the transcript behind a popup does not scroll ---
        cdp_evaluate($page, "document.querySelector('[data-open=\"v2s-info\"]').click()");
        assert_equal('hidden', cdp_evaluate($page, "getComputedStyle(document.getElementById('v2s-scroll')).overflowY"), 'scroll lock: the transcript is locked behind a popup');
        assert_true(str_contains((string)cdp_evaluate($page, "document.getElementById('v2s-info-body').textContent"), 'Fix the login redirect bug'), 'details: the full session name is shown');
        assert_true((int)cdp_evaluate($page, "document.querySelectorAll('#v2s-info .v2s-sec').length") >= 4, 'details: one panel per topic');
        assert_true(!str_contains((string)cdp_evaluate($page, "document.getElementById('v2s-info').textContent"), 'Move to terminal'), 'details: moving to a terminal is no longer offered');
        cdp_evaluate($page, "document.getElementById('v2s-info').close()");
        assert_equal('auto', cdp_evaluate($page, "getComputedStyle(document.getElementById('v2s-scroll')).overflowY"), 'scroll lock: released when the popup closes');

        // --- search and jump ---
        cdp_evaluate($page, "document.querySelector('[data-open=\"v2s-search\"]').click()");
        cdp_evaluate($page, "(function () { var i = document.getElementById('v2s-search-input'); i.value = 'redirect'; i.dispatchEvent(new Event('input')); }())");
        assert_true(v2b_wait($page, "document.querySelectorAll('#v2s-search-results .v2s-hit').length === 1"), 'search: the in-session hit is listed');
        cdp_evaluate($page, "document.querySelector('#v2s-search-results .v2s-hit').click()");
        assert_true(v2b_wait($page, "document.getElementById('v2s-jump').hidden === false"), 'search: jumping shows the "showing a search result" notice');
        assert_equal(true, cdp_evaluate($page, "!document.getElementById('v2s-search').open"), 'search: and closes the sheet');
        cdp_evaluate($page, "(function () { var i = document.getElementById('v2s-search-input'); i.value = 'zzzz-nothing'; i.dispatchEvent(new Event('input')); }())");
        cdp_evaluate($page, "document.querySelector('[data-open=\"v2s-search\"]').click()");
        assert_true(v2b_wait($page, "document.getElementById('v2s-search-results').textContent.indexOf('No matches') >= 0"), 'search: a query with no hits says so');

        // --- reasoning toggle re-fetches the transcript asking for it ---
        cdp_evaluate($page, "window.__urls = []; var of = window.fetch; window.fetch = function (u, o) { window.__urls.push(String(u)); return of(u, o); }; document.getElementById('v2s-search').close();");
        cdp_evaluate($page, "document.querySelector('[data-open=\"v2s-info\"]').click()");
        cdp_evaluate($page, "var c = document.getElementById('v2s-opt-reasoning'); c.checked = true; c.dispatchEvent(new Event('change'));");
        assert_true(v2b_wait($page, "window.__urls.some(function (u) { return u.indexOf('/v2/history') === 0 && u.indexOf('include_thinking=1') > 0; })"), 'reasoning: turning it on asks the host agent for reasoning text');
        assert_equal(true, cdp_evaluate($page, "document.body.classList.contains('v2s-hide-reasoning') === false"), 'reasoning: and the page stops hiding it');
        cdp_evaluate($page, "var c = document.getElementById('v2s-opt-reasoning'); c.checked = false; c.dispatchEvent(new Event('change'));");
        assert_equal(true, cdp_evaluate($page, "document.body.classList.contains('v2s-hide-reasoning')"), 'reasoning: off hides it again');

        // --- other sessions needing input are flagged on the way back ---
        assert_true(v2b_wait($page, "document.getElementById('v2s-back-badge').hidden === false"), 'back: a badge shows when another session needs input');
        assert_equal('1', cdp_evaluate($page, "document.getElementById('v2s-back-badge').textContent"), 'back: and counts only the other sessions');

        // --- nothing threw ---
        $errors = array_values(array_filter(cdp_drain_console_errors($page), static fn (string $m): bool => !str_contains($m, 'Failed to load resource')));
        assert_equal([], $errors, 'the page raised no uncaught exception and logged no console error');
        assert_equal(true, cdp_evaluate($page, 'document.documentElement.scrollWidth <= window.innerWidth'), 'the page does not scroll sideways on a phone');

        // no history to go back to: the left-edge swipe lands on the dashboard
        cdp_evaluate($page, "Array.prototype.forEach.call(document.querySelectorAll('dialog[open]'), function (d) { d.close(); })");
        cdp_evaluate($page, "delete history.back;");
        cdp_evaluate($page, "Object.defineProperty(history, 'length', { value: 1, configurable: true });");
        cdp_evaluate($page, $swipe(100, 260));
        assert_true(v2b_wait($page, "location.pathname === '/'"), 'swipe: with nothing to go back to, a right swipe opens the dashboard');

        // --- archived conversation: read-only transcript, Resume, search and jump ---
        assert_true(cdp_navigate($page, "{$base}/archived-session?agent_session_id=" . V2B_ARCHIVED), 'navigation to the v2 archived page succeeds');
        assert_true(v2b_wait($page, "document.querySelectorAll('#v2s-list > *').length > 5"), 'archived: the transcript renders');
        assert_equal('Refactor the old widget', cdp_evaluate($page, "document.getElementById('v2s-title').textContent"), 'archived: header title');
        assert_contains('Archived', (string)cdp_evaluate($page, "document.getElementById('v2s-sub').textContent"), 'archived: header says archived');
        assert_equal(true, cdp_evaluate($page, "document.getElementById('v2s-compose').hidden"), 'archived: no message box');
        assert_equal(false, cdp_evaluate($page, "document.getElementById('v2s-older').hidden"), 'archived: older messages can be loaded');
        assert_equal(true, cdp_evaluate($page, "document.getElementById('v2s-load-until-user').hidden"), 'archived: no "back to last message" stop');
        assert_equal(0, cdp_evaluate($page, "document.querySelectorAll('#v2s-prompt > *').length"), 'archived: no prompt panel');

        cdp_evaluate($page, "document.querySelector('[data-open=\"v2s-search\"]').click()");
        assert_equal(true, cdp_evaluate($page, "document.getElementById('v2s-search').open"), 'archived: search opens');
        cdp_evaluate($page, "(function () { var i = document.getElementById('v2s-search-input'); i.value = 'widget'; i.dispatchEvent(new Event('input')); }())");
        assert_true(v2b_wait($page, "document.querySelectorAll('#v2s-search-results button.v2s-hit').length === 1"), 'archived: in-conversation search finds the hit');
        cdp_evaluate($page, "document.querySelector('#v2s-search-results button.v2s-hit').click()");
        assert_equal(false, cdp_evaluate($page, "document.getElementById('v2s-search').open"), 'archived: choosing a hit closes the search');
        assert_true(v2b_wait($page, "document.getElementById('v2s-jump').hidden === false"), 'archived: jumping shows the "Showing a search result" notice');

        cdp_evaluate($page, "window.__posted = []; var of = window.fetch; window.fetch = function (u, o) { if (o && o.body) { window.__posted.push({ url: String(u), body: decodeURIComponent(o.body) }); } return of(u, o); }; true");
        cdp_evaluate($page, "document.getElementById('v2s-resume').click()");
        assert_true(v2b_wait($page, "location.pathname === '/session'"), 'archived: Resume (headless) lands on the new live session');
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
}

test_exit();
