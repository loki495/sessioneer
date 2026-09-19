<?php
declare(strict_types=1);

/**
 * Real, headless-Chrome, click-through coverage for Task 7 of the
 * 2026-08-30 "ui-parity-stop-bug" orchestration ("wire up sidebar
 * prompt-answer submission" - public/js/sidebar.js's document-level
 * submit/click/change/keydown handlers, ported from index.js's dashboard
 * pattern). Same fixture stack as test_session_replay_browser.php (real
 * tmux panes, real host-agent/agent.php over a real socket, `php -S`
 * serving public/, a real headless Chrome tab over tests/lib/cdp.php) -
 * this is the app's OWN client-side JS doing the work, not this test
 * calling the backend directly, which is exactly what a "does the sidebar
 * actually answer another session's prompt" question needs: a code read
 * cannot see a browser-only failure mode (a JS exception, a click that
 * silently does nothing, or - the specific structural risk sidebar-row.php
 * carries that row.php's own docblock explicitly warns against - a
 * <button>/<form> nested inside an <a>, where a plain element.click() can
 * ALSO trigger the ANCESTOR <a>'s navigation, not just the form's own
 * submit handler).
 *
 * Three real, independent tmux sessions are live at once:
 *   - $ctx (session A, "cc-test-replay-<pid>"): the CURRENTLY-VIEWED
 *     session, driven via the existing 'full-session' replay fixture up to
 *     (and including) its own Bash "Do you want to proceed?" permission
 *     step - this is what session.php's own #blocked-prompt-section shows.
 *   - $sideB ("cc-test-sidebar-b-<pid>"): another live, blocked session -
 *     never navigated to - whose Bash-tool option-button prompt should
 *     show up in the SIDEBAR's "other sessions" list and be answerable
 *     from there.
 *   - $sideC ("cc-test-sidebar-c-<pid>"): a third live, blocked session -
 *     also sidebar-only - with a single-question AskUserQuestion "Type
 *     something." free-text prompt, covering the free-text-from-sidebar
 *     path (a structurally different code path in sidebar.js:
 *     submitFreetextReply(), not the plain-option submit handler).
 *
 * $sideB/$sideC are NOT built via replay_setup() (that helper's session
 * name is a fixed 'cc-test-replay-' . getmypid() - calling it twice in one
 * process would collide) - instead they reuse replay_fixture.php's own
 * $sessionName-parameterized primitives (replay_tmux_send_keys(),
 * replay_write_sidecar(), replay_write_session_status(),
 * replay_capture_pane(), replay_sessions_db()) directly, with this file's
 * own thin spawn/teardown wrapper - see spawn_side_session()/
 * teardown_side_session() below. Both share $ctx's own fixture_home (HOME_ROOT
 * is one process-wide env var - see replay_setup()'s own doc comment) under
 * their own separate .claude/projects/ subdirectories, since
 * TranscriptService::find_transcript_path() globs across every project dir
 * for a matching agent_session_id.jsonl rather than caring what the
 * directory is named.
 *
 * PromptInteractionService::answer_prompt()/answer_prompt_with_text() (the
 * REAL, non-mocked host-agent code every click here actually reaches)
 * validate the LIVE PANE text via PromptParser::parse_blocking_prompt(),
 * not the hook-fed status file - so $sideB/$sideC's pane_text lines below
 * are real, previously-verified-live prompt shapes (copied from
 * tests/fixtures/replay/full-session.replay.json's own "Permission prompt"
 * and "Free-text prompt" steps), not placeholders. build_session_entry()
 * (the LISTING path the sidebar itself renders from) is different - for a
 * non-AskUserQuestion tool it reads blocked_reason/prompt_context purely
 * from the hook-fed status file (PromptParser::build_prompt_from_hook_status()),
 * so $sideB's hook_status alone drives what the sidebar actually shows for
 * it, independent of pane content - both are set up correctly here anyway,
 * to also exercise the (very real, separately validated) end-to-end
 * answer path.
 *
 * Multi-question AskUserQuestion-from-the-sidebar is NOT covered by a new
 * side session here (deliberate scope decision, not an oversight) -
 * faithfully faking that shape needs a real tab-bar pane text AND a
 * PendingToolStore-shaped pending-tool file for augment_prompt_with_pending_tool()
 * to read, on top of a matching hook-fed questions[] set for
 * answer_multi_question()'s own re-validation - meaningfully more fixture
 * surface for a path that shares the exact same document-level dispatch/
 * guard code (the '#sidebar' closest() check) already proven correct here
 * by the option-button and free-text cases. Still only code-parity-reviewed
 * (Worker 7's port from index.js), not click-through-verified.
 *
 * Best-effort like test_session_replay_browser.php's own headless-Chrome
 * tier: SKIPs (exit 0) rather than failing the suite if no usable Chrome is
 * on this host.
 */

require __DIR__ . '/lib/assert.php';
require __DIR__ . '/lib/harness.php';
require __DIR__ . '/lib/replay_fixture.php';
require __DIR__ . '/lib/cdp.php';

$realTmuxSocket = '/tmp/tmux-' . getmyuid() . '/default';

if (getenv('TMUX_SOCKET') === $realTmuxSocket || getenv('TMUX_SOCKET') === false) {
    fwrite(STDERR, "REFUSING TO RUN: TMUX_SOCKET resolves to the real host socket (or is unset). Check tests/.env.testing.\n");
    exit(1);
}

/**
 * Same polling helper as test_session_replay_browser.php's own copy (not
 * shared between browser test files today - kept local, same as that
 * file's own precedent).
 */
// Default bumped from 10.0 to 20.0 (2026-08-31, found live debugging this
// file): under real load, php -S's single-threaded dev server serializes
// every request this test's own page + fetches + the sidebar's own
// /sidebar_sessions.php round trip all compete for, and 10s wasn't always
// enough headroom - confirmed directly (see the matching note on
// cdp_navigate() above) that the underlying work genuinely completes, just
// sometimes past the old default.
function browser_wait_until(callable $check, float $timeoutSeconds = 20.0): bool
{
    $deadline = microtime(true) + $timeoutSeconds;

    do {
        if ($check()) {
            return true;
        }
        usleep(300000);
    } while (microtime(true) < $deadline);

    return $check();
}

/**
 * Spawns a real, isolated tmux session (bash -c 'stty -echo; exec cat',
 * same fixture stand-in used everywhere else in this suite) plus a real
 * sidecar row, a minimal seed transcript line (just enough for
 * TranscriptService::find_transcript_path() to resolve something - the
 * sidebar's own rendering never actually reads this session's transcript
 * content, only its blocked_reason/status from the hook-fed status file
 * below), the given pane text (real PromptParser::parse_blocking_prompt()
 * input, validated by answer_prompt()/answer_prompt_with_text() on the real
 * answer path), and a hook-fed session_status row (what
 * build_session_entry() actually renders the sidebar row FROM).
 *
 * @param string[] $paneTextLines
 * @param array<string, mixed> $hookStatus
 * @return array{session_name:string, agent_session_id:string, project_dir:string, transcript_path:string}
 */
function spawn_side_session(string $suffix, string $fixtureHome, string $workdir, array $paneTextLines, array $hookStatus): array
{
    $sessionName = 'cc-test-sidebar-' . $suffix . '-' . getmypid();
    $agentSessionId = sprintf('8%07d-8888-4888-8888-%012d', getmypid() % 10000000, getmypid());

    $projectDir = $fixtureHome . '/.claude/projects/-sidebar-' . $suffix . '-project';
    @mkdir($projectDir, 0700, true);
    $transcriptPath = "{$projectDir}/{$agentSessionId}.jsonl";
    file_put_contents(
        $transcriptPath,
        json_encode([
            'type' => 'user',
            'uuid' => 'side-' . $suffix . '-u1',
            'parentUuid' => null,
            'timestamp' => '2026-08-11T00:00:00Z',
            'message' => ['role' => 'user', 'content' => 'Side session ' . $suffix . ' seed message'],
        ]) . "\n"
    );

    $tmuxSocket = (string)getenv('TMUX_SOCKET');
    $create = proc_open(
        ['tmux', '-S', $tmuxSocket, 'new-session', '-d', '-s', $sessionName, '-c', $workdir, 'bash', '-c', 'stty -echo; exec cat'],
        [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
        $pipes
    );

    if (is_resource($create)) {
        fclose($pipes[0]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        proc_close($create);
    }

    usleep(300000);

    replay_write_sidecar($sessionName, [
        'workdir' => $workdir,
        'spawned_at' => time(),
        'agent_session_id' => $agentSessionId,
        'spawned_by_app' => true,
    ]);

    foreach ($paneTextLines as $line) {
        replay_tmux_send_keys($sessionName, $line);
    }

    replay_write_session_status($sessionName, $hookStatus);

    return [
        'session_name' => $sessionName,
        'agent_session_id' => $agentSessionId,
        'project_dir' => $projectDir,
        'transcript_path' => $transcriptPath,
    ];
}

/**
 * @param array{session_name:string, project_dir:string, transcript_path:string} $side
 */
function teardown_side_session(array $side): void
{
    $tmuxSocket = (string)getenv('TMUX_SOCKET');
    $kill = proc_open(
        ['tmux', '-S', $tmuxSocket, 'kill-session', '-t', $side['session_name']],
        [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
        $pipes
    );

    if (is_resource($kill)) {
        fclose($pipes[0]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        proc_close($kill);
    }

    foreach (['sidecars', 'session_status'] as $table) {
        try {
            $pdo = replay_sessions_db();
            $pdo->prepare("DELETE FROM {$table} WHERE session_name = ?")->execute([$side['session_name']]);
        } catch (\PDOException $e) {
            // Table/file may not exist yet if setup failed early - nothing
            // to clean up in that case, same reasoning as replay_teardown().
        }
    }

    @unlink($side['transcript_path']);
    @rmdir($side['project_dir']);
}

function browser_assert(array &$page, bool $condition, string $message, string $label): void
{
    if (!$condition) {
        $html = cdp_evaluate($page, 'document.documentElement.outerHTML');
        fwrite(STDERR, "  [debug] DOM snapshot on failure ({$label}), first 2000 chars:\n" . substr((string)$html, 0, 2000) . "\n");
    }

    assert_true($condition, $message);
}

function browser_assert_no_console_errors(array &$page, string $context, string $label): void
{
    $errors = cdp_drain_console_errors($page);
    $suffix = $errors === [] ? '' : ' - ' . implode(' | ', $errors);
    browser_assert($page, $errors === [], "{$context}: no uncaught JS errors/console.error calls{$suffix}", $label);
}

$workdirA = getenv('WWW_ROOT') . '/project-a';
$workdirOther = getenv('WWW_ROOT') . '/project-b';
$ctx = replay_setup('full-session', $workdirA);

// Advance session A through steps 0..10 - lands exactly on "Permission
// prompt shown + answered (plain click)" (Bash "rm -rf
// /var/www/uploads/tmp", "Do you want to proceed?"), the SAME real, live
// prompt shape test_session_replay_browser.php already answers for this
// scenario - but here, deliberately NOT auto-answered by replay_step()'s
// own metadata: this test answers it itself via a real CDP click on
// #blocked-prompt-section, to prove the sidebar's own new document-level
// submit handler does NOT ALSO intercept it (the exact regression this
// task exists to cover - see this file's own header comment).
for ($i = 0; $i < 11; $i++) {
    $advanced = replay_step($ctx);

    if ($advanced === null) {
        fwrite(STDERR, "sidebar answer browser: replay scenario ran out of steps before reaching the permission-prompt step\n");
        replay_teardown($ctx);
        exit(1);
    }
}

// $sideB: a Bash-tool option-button prompt (Yes/No), same shape as $ctx's
// own but with a distinct command so assertions can tell the two apart.
$sideB = spawn_side_session(
    'b',
    $ctx['fixture_home'],
    $workdirOther,
    [
        '● Bash(rm -rf /tmp/other-cleanup-target)',
        '',
        'Do you want to proceed?',
        '❯ 1. Yes',
        '  2. No',
    ],
    [
        'status' => 'blocked',
        'mode' => 'manual',
        'blocked' => [
            'tool_name' => 'Bash',
            'tool_input' => ['command' => 'rm -rf /tmp/other-cleanup-target'],
            'permission_suggestions' => [],
        ],
    ]
);

// $sideC: a single-question AskUserQuestion free-text prompt (real pane
// text copied from full-session.replay.json's own "Free-text prompt shown
// + answered" step) - single-question, so build_session_entry() reads it
// via the pane-scraped path (CLAUDE.md: a single-question AskUserQuestion's
// CONTENT structurally needs the live pane, unlike a 2+-question one).
$sideC = spawn_side_session(
    'c',
    $ctx['fixture_home'],
    $workdirOther,
    [
        '● AskUserQuestion(Which environment should this run against?)',
        '',
        'Which environment should this run against?',
        '❯ 1. staging',
        '  2. production',
        '  3. Type something.',
    ],
    [
        'status' => 'blocked',
        'mode' => 'manual',
        'blocked' => [
            'tool_name' => 'AskUserQuestion',
            'tool_input' => [
                'questions' => [
                    [
                        'question' => 'Which environment should this run against?',
                        'header' => 'Environment',
                        'options' => [['label' => 'staging'], ['label' => 'production']],
                        'multiSelect' => false,
                    ],
                ],
            ],
        ],
    ]
);

$agentSocket = sys_get_temp_dir() . '/sessioneer-test-sidebar-answer-browser-agent.sock';
$agentHarness = start_harness(['php', dirname(__DIR__) . '/host-agent/agent.php'], $agentSocket);

$port = 18199;
$baseUrl = "http://127.0.0.1:{$port}";

$serverEnv = array_merge(getenv(), ['SESSIONEER_AGENT_SOCKET' => $agentSocket]);
$serverProcess = proc_open(
    [
        'php', '-S', "127.0.0.1:{$port}",
        '-t', dirname(__DIR__) . '/public',
        dirname(__DIR__) . '/public/index.php',
    ],
    [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
    $serverPipes,
    null,
    $serverEnv
);

if (!is_resource($serverProcess)) {
    fwrite(STDERR, "sidebar answer browser: failed to start php -S\n");
    stop_harness($agentHarness, $agentSocket);
    teardown_side_session($sideC);
    teardown_side_session($sideB);
    replay_teardown($ctx);
    exit(1);
}
fclose($serverPipes[0]);

$ready = false;
$deadline = microtime(true) + 3.0;
while (microtime(true) < $deadline) {
    $conn = @fsockopen('127.0.0.1', $port, $errno, $errstr, 0.2);
    if ($conn !== false) {
        fclose($conn);
        $ready = true;
        break;
    }
    usleep(50000);
}

if (!$ready) {
    fwrite(STDERR, "sidebar answer browser: server on port {$port} never became ready\n");
    proc_terminate($serverProcess);
    proc_close($serverProcess);
    stop_harness($agentHarness, $agentSocket);
    teardown_side_session($sideC);
    teardown_side_session($sideB);
    replay_teardown($ctx);
    exit(1);
}

$browser = null;
$page = null;

try {
    $chromeBin = cdp_find_chrome();

    if ($chromeBin === null) {
        echo "  SKIP: no headless browser found (checked google-chrome-stable/google-chrome/chromium/chromium-browser) - this test file has nothing else to check\n";
    } else {
        $browser = cdp_launch($chromeBin);
        $page = $browser !== null ? cdp_open_page($browser) : null;
    }

    if ($page !== null) {
        $sessionAName = $ctx['session_name'];
        $sessionBName = $sideB['session_name'];
        $sessionCName = $sideC['session_name'];
        $sessionAUrl = "{$baseUrl}/session.php?session=" . urlencode($sessionAName);

        assert_true(cdp_set_viewport($page, 1280, 800, 1.0, false), 'cdp: desktop viewport (1280x800) set');

        // Throwaway navigation first, same as test_session_replay_browser.php -
        // localStorage is per-origin, seeded before session.php's own script
        // (which reads poll-interval/confirm-toggle synchronously at load) runs.
        //
        // Found live debugging this file (2026-08-31): cdp_navigate()'s own
        // completion signal (document.readyState === 'complete') is too
        // strict a gate for THIS page under this test's load - session.php
        // now pulls in several separate <script src> files (sidebar.js,
        // session.js, ...) near the end of <body>, each its own blocking,
        // synchronous request against php -S's single-threaded dev server,
        // and with the throwaway navigation to `/` (the dashboard, its own
        // separate set of trailing scripts) run immediately before it on
        // the SAME single-threaded server, the cumulative load time for
        // every trailing resource genuinely exceeded cdp_navigate()'s 5s
        // default here - confirmed directly: readyState was STILL 'loading'
        // (not just slow to reach 'complete') at the 5s mark, yet
        // document.body.innerHTML was already 31KB and
        // getElementById('blocked-prompt-section')/('sidebar') both
        // already resolved true - the actual content this test needs was
        // present well before the WHOLE document (every trailing script)
        // finished. So: navigate without hard-asserting cdp_navigate()'s
        // own return value, and poll for the actual DOM signal instead -
        // the same "wait for what you actually need" pattern already used
        // everywhere else in this file (browser_wait_until()), rather than
        // a blanket "whole page fully loaded" gate this page's own script
        // count can no longer reliably clear within 5s under load.
        cdp_navigate($page, "{$baseUrl}/", 15.0);
        cdp_evaluate($page, "window.localStorage.setItem('sessioneer-poll-interval-ms','1000'); window.localStorage.setItem('sessioneer-confirm-before-answer','0');");

        cdp_navigate($page, $sessionAUrl, 15.0);

        $blockedSectionReady = browser_wait_until(function () use (&$page) {
            return cdp_evaluate($page, "document.getElementById('blocked-prompt-section') !== null") === true;
        }, 15.0);
        browser_assert($page, $blockedSectionReady, 'session.php: renders the blocked-prompt section container', 'blocked-section-missing');
        browser_assert($page, cdp_evaluate($page, "document.getElementById('sidebar') !== null") === true, 'session.php: renders the sidebar container', 'sidebar-missing');

        // Desktop viewport (>=1024px) opens the sidebar by default
        // (sidebar.js's own openSidebar() call, see isDesktopViewport()) -
        // that call also fires loadSidebarList(), which fetches
        // /sidebar_sessions.php and renders every OTHER live session,
        // including B and C.
        $sidebarShowsOthers = browser_wait_until(function () use (&$page, $sessionBName, $sessionCName) {
            $js = 'document.querySelector(\'#sidebar-list a[data-session="\' + ' . json_encode($sessionBName) . ' + \'"]\') !== null'
                . ' && document.querySelector(\'#sidebar-list a[data-session="\' + ' . json_encode($sessionCName) . ' + \'"]\') !== null';

            return cdp_evaluate($page, $js) === true;
        });
        browser_assert($page, $sidebarShowsOthers, 'sidebar: shows rows for BOTH other blocked sessions (B and C), not just the currently-viewed one', 'sidebar-missing-other-rows');

        // --- 1. Sidebar shows another blocked session's rich prompt ---
        $bHasOptionForm = cdp_evaluate(
            $page,
            'document.querySelector(\'#sidebar-list a[data-session="' . $sessionBName . '"] .prompt-options-wrapper form[data-confirm-label]\') !== null'
        );
        browser_assert($page, $bHasOptionForm === true, 'sidebar: session B\'s row renders a real answerable option-button form (BlockedPromptView::blocked_prompt_rich_html() rich treatment), not plain text', 'sidebar-b-no-option-form');

        $bShowsOwnContext = cdp_evaluate(
            $page,
            'document.getElementById(\'sidebar-list\').textContent.indexOf(' . json_encode('rm -rf /tmp/other-cleanup-target') . ') !== -1'
        );
        browser_assert($page, $bShowsOwnContext === true, 'sidebar: session B\'s own distinct pending-command text is visible in the sidebar (proves the RIGHT session\'s prompt content rendered, not a stale/wrong one)', 'sidebar-b-wrong-context');

        $cHasFreetextReveal = cdp_evaluate(
            $page,
            'document.querySelector(\'#sidebar-list a[data-session="' . $sessionCName . '"] .reveal-freetext-btn\') !== null'
        );
        browser_assert($page, $cHasFreetextReveal === true, 'sidebar: session C\'s row renders a free-text reveal button ("Type something.") for its AskUserQuestion prompt', 'sidebar-c-no-freetext-reveal');

        // Fetch-call counter, installed once for the whole rest of this
        // test (no further navigation happens after this point) - counts
        // real POSTs to /answer_prompt.php so a double-fire (the exact
        // regression this task guards against - see this file's own header
        // comment) is caught even if the UI otherwise looks fine.
        cdp_evaluate($page, <<<'JS'
        (function () {
            window.__answerPromptCallCount = 0;
            var __origFetch = window.fetch;
            window.fetch = function (input, init) {
                var url = typeof input === 'string' ? input : ((input && input.url) || '');
                if (url.indexOf('/answer_prompt.php') !== -1) {
                    window.__answerPromptCallCount++;
                }
                return __origFetch.apply(window, arguments);
            };
        })();
        JS);

        // --- 2 & the double-fire regression: answer session A's OWN
        // #blocked-prompt-section prompt (option 1, "Yes") FIRST, before
        // touching the sidebar at all. If sidebar.js's document-level
        // submit handler were still scoped to .closest('[data-session]')
        // (the pre-fix guard - data-session is ALSO present on
        // .prompt-options-wrapper inside #blocked-prompt-section, since
        // it's the exact same shared partial), it would ALSO intercept
        // this same click and send a SECOND, redundant POST to
        // /answer_prompt.php. ---
        cdp_evaluate($page, 'window.__answerPromptCallCount = 0;');
        $urlBeforeAClick = cdp_evaluate($page, 'window.location.href');

        $clickedA = cdp_click($page, '#blocked-prompt-section form[data-confirm-label] button[type="submit"]');
        browser_assert($page, $clickedA === true, 'session A\'s own #blocked-prompt-section: option 1 ("Yes") button found and clicked', 'session-a-option-click-failed');

        $aPaneGotAnswer = browser_wait_until(function () use ($ctx) {
            return str_ends_with(trim(replay_capture_pane($ctx['session_name'])), '1');
        });
        browser_assert($page, $aPaneGotAnswer, 'session A: the click\'s real submit reached /answer_prompt.php and sent option 1 into session A\'s OWN real tmux pane', 'session-a-option-not-sent-to-pane');

        // The regression check itself: exactly ONE POST to
        // /answer_prompt.php resulted from this ONE click - not two.
        $aFetchCallCount = browser_wait_until(function () use (&$page) {
            return cdp_evaluate($page, 'window.__answerPromptCallCount') >= 1;
        });
        browser_assert($page, $aFetchCallCount, 'session A: at least one /answer_prompt.php POST was observed', 'session-a-no-fetch-observed');
        browser_assert(
            $page,
            cdp_evaluate($page, 'window.__answerPromptCallCount') === 1,
            'session A: answering its OWN #blocked-prompt-section prompt sent EXACTLY ONE POST to /answer_prompt.php - sidebar.js\'s document-level submit handler (scoped to .closest(\'#sidebar\')) did NOT also fire for it (the regression this task fixes - see this file\'s own header comment)',
            'session-a-double-fetch'
        );

        $urlAfterAClick = cdp_evaluate($page, 'window.location.href');
        browser_assert($page, $urlAfterAClick === $urlBeforeAClick, 'session A: answering its own prompt did not navigate the page away', 'session-a-unexpected-navigation');

        browser_assert_no_console_errors($page, 'session A: after answering its own prompt', 'session-a-console-errors');

        // Sidebar rows B/C must be completely untouched by session A's own
        // answer (no accidental cross-talk) - both still show their own
        // rich prompts exactly as before.
        $sidebarStillIntact = cdp_evaluate(
            $page,
            'document.querySelector(\'#sidebar-list a[data-session="' . $sessionBName . '"] .prompt-options-wrapper form[data-confirm-label]\') !== null'
            . ' && document.querySelector(\'#sidebar-list a[data-session="' . $sessionCName . '"] .reveal-freetext-btn\') !== null'
        );
        browser_assert($page, $sidebarStillIntact === true, 'sidebar: sessions B and C are unaffected by answering session A\'s own prompt', 'sidebar-b-c-affected-by-a-answer');

        // --- 3. Clicking an option button in the SIDEBAR (for a DIFFERENT
        // session, B) actually reaches /answer_prompt.php and the row
        // updates. ---
        cdp_evaluate($page, 'window.__answerPromptCallCount = 0;');
        $urlBeforeBClick = cdp_evaluate($page, 'window.location.href');

        $clickedB = cdp_click($page, '#sidebar-list a[data-session="' . $sessionBName . '"] .prompt-options-wrapper form:first-of-type button[type="submit"]');
        browser_assert($page, $clickedB === true, 'sidebar: session B\'s option 1 ("Yes") button found and clicked', 'sidebar-b-option-click-failed');

        $bPaneGotAnswer = browser_wait_until(function () use ($sideB) {
            return str_ends_with(trim(replay_capture_pane($sideB['session_name'])), '1');
        });
        browser_assert($page, $bPaneGotAnswer, 'sidebar: session B\'s click reached /answer_prompt.php and sent option 1 into session B\'s OWN real tmux pane (a DIFFERENT session\'s pane than the one being viewed)', 'sidebar-b-option-not-sent-to-pane');

        $bFetchCallCount = browser_wait_until(function () use (&$page) {
            return cdp_evaluate($page, 'window.__answerPromptCallCount') >= 1;
        });
        browser_assert($page, $bFetchCallCount, 'sidebar: at least one /answer_prompt.php POST was observed for session B\'s click', 'sidebar-b-no-fetch-observed');
        browser_assert(
            $page,
            cdp_evaluate($page, 'window.__answerPromptCallCount') === 1,
            'sidebar: clicking session B\'s option button sent EXACTLY ONE POST to /answer_prompt.php',
            'sidebar-b-double-fetch'
        );

        $urlAfterBClick = cdp_evaluate($page, 'window.location.href');
        browser_assert(
            $page,
            $urlAfterBClick === $urlBeforeBClick,
            'sidebar: clicking session B\'s option button (a real <button type="submit"> nested inside sidebar-row.php\'s own <a data-session> wrapper) did NOT also navigate the page to session B\'s own session.php - the click\'s default bubbling to the ancestor <a> was correctly suppressed',
            'sidebar-b-unexpected-navigation'
        );

        // Real server-side effect: answer_prompt() really did
        // SessionStatusStore::update_status(name, working, blocked=null)
        // for session B specifically - confirmed by the NEXT
        // loadSidebarList() refresh (sidebar.js's own success-path refresh
        // call) showing session B's row no longer blocked. Checked
        // TOGETHER with session C's row still being fully rendered (not
        // just "B's markup is momentarily gone") - loadSidebarList()
        // replaces #sidebar-list.innerHTML with a "Loading…" placeholder
        // FIRST, synchronously, before its own fetch resolves; a wait
        // condition that only checked "B's prompt markup is absent" would
        // false-positive on that placeholder text too (nothing matches
        // ANY selector then, including C's), letting the test race ahead
        // and try to click C's reveal button before the real refreshed
        // HTML had actually landed - found live running this test.
        $bNoLongerBlocked = browser_wait_until(function () use (&$page, $sessionBName, $sessionCName) {
            $js = 'document.querySelector(\'#sidebar-list a[data-session="' . $sessionBName . '"] .prompt-options-wrapper\') === null'
                . ' && document.querySelector(\'#sidebar-list a[data-session="' . $sessionCName . '"] .reveal-freetext-btn\') !== null';

            return cdp_evaluate($page, $js) === true;
        });
        browser_assert($page, $bNoLongerBlocked, 'sidebar: after answering, session B\'s row refreshes (loadSidebarList()) and no longer shows the (now-answered) blocked prompt, while session C\'s row is still fully rendered', 'sidebar-b-still-blocked-after-answer');

        browser_assert_no_console_errors($page, 'sidebar: after answering session B', 'sidebar-b-console-errors');

        // --- 4. Free-text reply from the sidebar (session C) works the
        // same way - a structurally different code path
        // (submitFreetextReply(), not the plain form-submit handler). ---
        cdp_evaluate($page, 'window.__answerPromptCallCount = 0;');
        $urlBeforeCReveal = cdp_evaluate($page, 'window.location.href');

        $revealedC = cdp_click($page, '#sidebar-list a[data-session="' . $sessionCName . '"] .reveal-freetext-btn');
        browser_assert($page, $revealedC === true, 'sidebar: session C\'s "Type something." reveal button found and clicked', 'sidebar-c-reveal-click-failed');

        $urlAfterCReveal = cdp_evaluate($page, 'window.location.href');
        browser_assert($page, $urlAfterCReveal === $urlBeforeCReveal, 'sidebar: revealing session C\'s free-text box did not navigate the page away', 'sidebar-c-reveal-unexpected-navigation');

        $freetextRevealed = browser_wait_until(function () use (&$page, $sessionCName) {
            $js = '!document.querySelector(\'#sidebar-list a[data-session="' . $sessionCName . '"] .freetext-reply\').classList.contains(\'hidden\')';

            return cdp_evaluate($page, $js) === true;
        });
        browser_assert($page, $freetextRevealed, 'sidebar: session C\'s free-text reply box is revealed (no longer hidden) after clicking the reveal button', 'sidebar-c-freetext-not-revealed');

        $freetextReply = 'sidebar-freetext-answer';
        $setTextJs = 'var __ta = document.querySelector(\'#sidebar-list a[data-session="' . $sessionCName . '"] .freetext-reply-textarea\'); __ta.value = ' . json_encode($freetextReply) . '; true;';
        browser_assert($page, cdp_evaluate($page, $setTextJs) === true, 'sidebar: session C\'s free-text textarea found and populated', 'sidebar-c-freetext-textarea-missing');

        $sentC = cdp_click($page, '#sidebar-list a[data-session="' . $sessionCName . '"] .freetext-reply-send-btn');
        browser_assert($page, $sentC === true, 'sidebar: session C\'s free-text send button found and clicked', 'sidebar-c-send-click-failed');

        // answer_prompt_with_text() sends the option digit (3, "Type
        // something."), then pastes the reply text, then Enter - all
        // before any Enter, so cat's canonical-mode line buffering only
        // echoes it back as one completed line once Enter finally lands:
        // "3sidebar-freetext-answer" (same reasoning as
        // test_session_replay_browser.php's own equivalent check).
        $cPaneGotAnswer = browser_wait_until(function () use ($sideC, $freetextReply) {
            return str_ends_with(trim(replay_capture_pane($sideC['session_name'])), '3' . $freetextReply);
        });
        browser_assert($page, $cPaneGotAnswer, 'sidebar: session C\'s free-text submit reached /answer_prompt.php and sent "3' . $freetextReply . '" into session C\'s OWN real tmux pane', 'sidebar-c-freetext-not-sent-to-pane');

        $cFetchCallCount = browser_wait_until(function () use (&$page) {
            return cdp_evaluate($page, 'window.__answerPromptCallCount') >= 1;
        });
        browser_assert($page, $cFetchCallCount, 'sidebar: at least one /answer_prompt.php POST was observed for session C\'s free-text send', 'sidebar-c-no-fetch-observed');
        browser_assert(
            $page,
            cdp_evaluate($page, 'window.__answerPromptCallCount') === 1,
            'sidebar: sending session C\'s free-text reply sent EXACTLY ONE POST to /answer_prompt.php',
            'sidebar-c-double-fetch'
        );

        browser_assert_no_console_errors($page, 'sidebar: after answering session C', 'sidebar-c-console-errors');
    }
} finally {
    if ($page !== null) {
        cdp_close_page($page);
    }
    if ($browser !== null) {
        cdp_shutdown($browser);
    }
    proc_terminate($serverProcess);
    proc_close($serverProcess);
    stop_harness($agentHarness, $agentSocket);
    // Side sessions torn down BEFORE replay_teardown($ctx): both live under
    // $ctx['fixture_home']'s own .claude/projects/ directory (HOME_ROOT is
    // one process-wide env var - see spawn_side_session()'s own doc
    // comment), and replay_teardown() rmdir()s that directory - which only
    // succeeds once it's empty.
    teardown_side_session($sideC);
    teardown_side_session($sideB);
    replay_teardown($ctx);
}

test_exit();
