<?php
declare(strict_types=1);

/**
 * Messages delivered while the agent is working, on the session page in a real headless Chrome. The canned agent
 * reads a synthetic transcript through the real TranscriptService (CANNED_TRANSCRIPT), so this covers the whole
 * path: a user's queued message is a normal user bubble that survives a reload and replaces the optimistic
 * bubble when it arrives by polling; another agent's message is an escaped "Agent message" card that never
 * replaces a bubble and is not hidden with the subagent entries. Skips (exit 0) when no Chrome is installed.
 */

require __DIR__ . '/lib/assert.php';
require __DIR__ . '/lib/harness.php';
require __DIR__ . '/lib/free_port.php';
require __DIR__ . '/lib/cdp.php';

const MTB_SESSION = 'cc-20260101-1200';
const MTB_PNG = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNk+A8AAQUBAScY42YAAAAASUVORK5CYII=';

function mtb_wait(array &$page, string $expression, float $timeout = 8.0): bool
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

function mtb_line(array $record): string
{
    return json_encode($record + ['timestamp' => '2026-01-01T12:00:00Z'], JSON_UNESCAPED_SLASHES) . "\n";
}

function mtb_message(string $type, mixed $content): string
{
    return mtb_line(['type' => $type, 'message' => ['role' => $type, 'content' => $content]]);
}

function mtb_queued(mixed $prompt, array $extra = []): string
{
    return mtb_line([
        'type' => 'attachment',
        'attachment' => $extra + ['type' => 'queued_command', 'commandMode' => 'prompt', 'prompt' => $prompt],
        'rendered' => [['content' => "<system-reminder>\nThe user sent a new message while you were working:\nzzpreamble\n</system-reminder>"]],
    ]);
}

function mtb_peer(string $body, ?string $name = null): string
{
    $origin = ['kind' => 'peer', 'body' => $body] + ($name === null ? [] : ['name' => $name]);

    return mtb_queued("<agent-message from=\"a1\">\n{$body}\n</agent-message>", ['origin' => $origin, 'isMeta' => true]);
}

/** A JS expression for the top-level transcript node of raw line $line. */
function mtb_node(int $line): string
{
    return "document.querySelector('#sess-list > [data-lines=\"{$line}\"]')";
}

/** Textual facts about one transcript node; null when the node is absent. */
function mtb_facts(array &$page, int $line): ?array
{
    $node = mtb_node($line);
    $json = cdp_evaluate($page, <<<JS
    (function () {
        var el = {$node};
        if (!el) { return 'null'; }
        var block = el.querySelector('[data-line="{$line}"]');
        return JSON.stringify({
            entry: el.classList.contains('sess-entry'), user: el.classList.contains('sess-user'), card: el.classList.contains('sess-card'),
            msg: el.getAttribute('data-msg'), hasMsg: el.hasAttribute('data-msg'), block: !!block,
            copy: !!el.querySelector('[data-act="copy"]'), text: el.textContent,
            injected: el.querySelectorAll('script, img, iframe, svg').length, bold: Array.prototype.map.call(el.querySelectorAll('strong'), function (s) { return s.textContent; }),
            items: el.querySelectorAll('li').length, thumbs: el.querySelectorAll('.sess-thumb').length, visible: el.getClientRects().length > 0
        });
    }())
    JS);
    $facts = json_decode((string)$json, true);

    return is_array($facts) ? $facts : null;
}

function mtb_copied(array &$page, int $line): mixed
{
    $node = mtb_node($line);

    return cdp_evaluate($page, "(function () { window.__copied = null; var el = {$node}; var b = el && el.querySelector('[data-act=\"copy\"]'); if (b) { b.click(); } return window.__copied; }())");
}

function mtb_stub_clipboard(array &$page): void
{
    cdp_evaluate($page, "Object.defineProperty(navigator, 'clipboard', { configurable: true, value: { writeText: function (t) { window.__copied = t; return Promise.resolve(); } } }); true");
}

$transcript = (string)tempnam(sys_get_temp_dir(), 'sessioneer-test-midturn-');
$userText = 'Also check the zzlogs';
$agentBody = "Report **ready**\n\n- first\n- second\n\n<script>window.zzxss = 'body'</script><img src=x onerror=\"window.zzxss = 'bodyimg'\">";
$agentName = "<img src=x onerror=\"window.zzxss = 'name'\">worker";
file_put_contents($transcript, implode('', [
    mtb_message('user', 'Start the long task'),                                                                  // 1
    mtb_message('assistant', [['type' => 'text', 'text' => 'Working on it.']]),                                  // 2
    mtb_queued($userText, ['origin' => ['kind' => 'human']]),                                                    // 3
    mtb_queued('zztasknote finished', ['commandMode' => 'task-notification']),                                   // 4
    mtb_peer($agentBody, $agentName),                                                                            // 5
    mtb_peer('Plain report'),                                                                                    // 6
    mtb_queued([['type' => 'text', 'text' => 'See this picture'], ['type' => 'image', 'source' => ['type' => 'base64', 'media_type' => 'image/png', 'data' => MTB_PNG]]]), // 7
    mtb_message('assistant', [['type' => 'text', 'text' => 'Noted.']]),                                          // 8
]));

$agentSocket = sys_get_temp_dir() . '/sessioneer-test-midturn-agent-' . getmypid() . '.sock';
putenv("CANNED_TRANSCRIPT={$transcript}");
$agentHarness = start_harness(['php', __DIR__ . '/fixtures/canned_agent.php'], $agentSocket);
$port = test_free_port();
$server = proc_open(
    ['php', '-S', "127.0.0.1:{$port}", '-t', dirname(__DIR__) . '/public', dirname(__DIR__) . '/public/index.php'],
    [0 => ['pipe', 'r'], 1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']],
    $pipes,
    null,
    array_merge(getenv(), ['SESSIONEER_AGENT_SOCKET' => $agentSocket])
);
$base = "http://127.0.0.1:{$port}";
$url = "{$base}/session?session=" . MTB_SESSION;
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
        assert_true(cdp_navigate($page, $url), 'navigation to the session page succeeds');
        cdp_evaluate($page, "localStorage.setItem('v2-poll', '1000'); localStorage.removeItem('v2s-subagent'); true");
        assert_true(cdp_navigate($page, $url), 'the page reopens with a short poll interval');
        assert_true(mtb_wait($page, '!!' . mtb_node(8)), 'the transcript renders');
        mtb_stub_clipboard($page);

        $assertTranscript = static function (string $when) use (&$page, $agentName): void {
            assert_equal('1,2,3,5,6,7,8', cdp_evaluate($page, "Array.prototype.map.call(document.querySelectorAll('#sess-list > [data-lines]'), function (el) { return el.getAttribute('data-lines'); }).join(',')"), "{$when}: queued messages sit in file order, a task notification does not show");

            $all = (string)cdp_evaluate($page, "document.getElementById('sess-list').textContent");
            assert_true(!str_contains($all, 'zzpreamble') && !str_contains($all, 'zztasknote'), "{$when}: neither the preamble nor the task notification text is shown");

            foreach ([3 => 'Also check the zzlogs', 7 => 'See this picture'] as $line => $text) {
                $user = mtb_facts($page, $line);
                assert_true(($user['entry'] ?? false) && ($user['user'] ?? false) && !($user['card'] ?? true), "{$when}: queued line {$line} is a .sess-entry.sess-user bubble");
                assert_true($user['block'] ?? false, "{$when}: queued line {$line} has a child with data-line");
                assert_true($user['copy'] ?? false, "{$when}: queued line {$line} has a Copy button");
                assert_contains($text, (string)($user['text'] ?? ''), "{$when}: queued line {$line} shows its text");
            }

            assert_equal(1, mtb_facts($page, 7)['thumbs'] ?? null, "{$when}: a queued image is a thumbnail");

            foreach ([5 => 'Message from ' . $agentName, 6 => 'Message from another agent'] as $line => $from) {
                $card = mtb_facts($page, $line);
                assert_true(($card['entry'] ?? false) && ($card['card'] ?? false), "{$when}: agent line {$line} is a .sess-entry.sess-card");
                assert_equal(false, $card['user'] ?? null, "{$when}: agent line {$line} is not a user bubble");
                assert_equal(false, $card['hasMsg'] ?? null, "{$when}: agent line {$line} has no data-msg attribute");
                assert_true($card['block'] ?? false, "{$when}: agent line {$line} has a block with data-line");
                assert_true($card['copy'] ?? false, "{$when}: agent line {$line} has a Copy button");
                assert_contains('Agent message', (string)($card['text'] ?? ''), "{$when}: agent line {$line} is labelled Agent message");
                assert_contains($from, (string)($card['text'] ?? ''), "{$when}: agent line {$line} says who it is from, as literal text");
            }

            assert_true(!str_contains((string)(mtb_facts($page, 6)['text'] ?? ''), 'undefined'), "{$when}: no summary leaves no placeholder value");
            assert_contains('Plain report', (string)(mtb_facts($page, 6)['text'] ?? ''), "{$when}: the card shows the message text");
            $rich = mtb_facts($page, 5);
            assert_equal(['ready'], $rich['bold'] ?? null, "{$when}: the agent text is rendered as markdown (bold)");
            assert_equal(2, $rich['items'] ?? null, "{$when}: the agent text is rendered as markdown (list)");
            assert_contains("<script>window.zzxss = 'body'</script>", (string)($rich['text'] ?? ''), "{$when}: markup in the agent text shows literally");
            assert_equal(0, $rich['injected'] ?? null, "{$when}: neither the summary nor the text injects an element");
            usleep(200000);
            assert_equal('none', cdp_evaluate($page, "String(window.zzxss || 'none')"), "{$when}: no injected script ran");
        };

        $assertTranscript('first load');
        assert_equal($userText, mtb_copied($page, 3), 'copy: the queued user message is copied verbatim');
        assert_equal($agentBody, mtb_copied($page, 5), 'copy: the agent message copies its raw text');
        assert_equal('Plain report', mtb_copied($page, 6), 'copy: a card without a summary copies its text');

        // --- the "hide subagent" option leaves agent messages alone ---
        cdp_evaluate($page, "(function () { var c = document.getElementById('sess-opt-subagent'); c.checked = false; c.dispatchEvent(new Event('change')); }())");
        assert_equal(true, cdp_evaluate($page, "document.body.classList.contains('sess-hide-subagent')"), 'subagent toggle: hiding is on');
        assert_equal(true, mtb_facts($page, 5)['visible'] ?? null, 'subagent toggle: the agent message with a summary stays visible');
        assert_equal(true, mtb_facts($page, 6)['visible'] ?? null, 'subagent toggle: the agent message without a summary stays visible');
        cdp_evaluate($page, "(function () { var c = document.getElementById('sess-opt-subagent'); c.checked = true; c.dispatchEvent(new Event('change')); }())");

        assert_true(cdp_navigate($page, $url), 'reload');
        assert_true(mtb_wait($page, '!!' . mtb_node(8)), 'reload: the transcript renders');
        $assertTranscript('after reload');

        // --- arriving by polling: the optimistic bubble ---
        $sent = 'Please also run the zzlinter';
        assert_true(mtb_wait($page, "document.getElementById('sess-compose').hidden === false"), 'send: the message box is available mid-turn');
        cdp_evaluate($page, "(function () { var t = document.getElementById('sess-text'); t.value = " . json_encode($sent) . "; t.dispatchEvent(new Event('input')); document.getElementById('sess-send').click(); }())");
        $sentAt = microtime(true);
        assert_equal(1, cdp_evaluate($page, "document.querySelectorAll('#sess-list .sess-pending').length"), 'send: an optimistic bubble appears');

        file_put_contents($transcript, mtb_peer($sent, 'worker-a'), FILE_APPEND);            // 9
        assert_true(mtb_wait($page, '!!' . mtb_node(9), 6.0), 'poll: an agent message arrives');
        assert_equal(true, mtb_facts($page, 9)['card'] ?? null, 'poll: as a card');
        assert_equal(1, cdp_evaluate($page, "document.querySelectorAll('#sess-list .sess-pending').length"), 'poll: an agent message with the same text leaves the pending bubble');

        file_put_contents($transcript, mtb_queued($sent, ['origin' => ['kind' => 'human']]), FILE_APPEND); // 10
        assert_true(mtb_wait($page, '!!' . mtb_node(10), 6.0), "poll: the user's queued message arrives");
        assert_equal(true, mtb_facts($page, 10)['user'] ?? null, 'poll: as a user bubble');
        assert_true(mtb_wait($page, "document.querySelectorAll('#sess-list .sess-pending').length === 0", 3.0), 'poll: it replaces the optimistic bubble');
        assert_true(microtime(true) - $sentAt < 19.0, "poll: replaced by the match, before the page's 20 second pending expiry could do it");
        assert_equal(1, cdp_evaluate($page, "Array.prototype.filter.call(document.querySelectorAll('#sess-list > .sess-user:not(.sess-pending)'), function (el) { return el.textContent.indexOf('zzlinter') !== -1; }).length"), 'poll: the sent message shows exactly once as a real user bubble');

        $errors = array_values(array_filter(cdp_drain_console_errors($page), static fn (string $m): bool => !str_contains($m, 'Failed to load resource')));
        assert_equal([], $errors, 'the page raised no uncaught exception and logged no console error');
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
    putenv('CANNED_TRANSCRIPT');
    @unlink($transcript);
}

test_exit();
