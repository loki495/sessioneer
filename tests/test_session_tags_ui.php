<?php
declare(strict_types=1);

/**
 * Session tags in the web UI, without a browser. Written from the Contract only. Intent notes:
 *  - card: `data-tags="a,b"` on the article (escaped, empty when none) and one `ui-chip ui-chip-tag` span per tag in the
 *    chips row;
 *  - dashboard page: `select#ui-tag-filter` with an `All tags` option of empty value plus one option per distinct tag
 *    among the listed sessions; the New session form has an optional `tags` input;
 *  - session page: tag chips in the `.sess-hier` strip, also for a session with no hierarchy;
 *  - endpoints: POST /session_tags.php and POST /action action=tags forward {session, mode, tags} to the host action
 *    `tags_set`, guarded by POST, same origin and CSRF like /session_rename.php (form fields, `csrf_token`, `tags[]`);
 *    POST /action action=new forwards the form's `tags` to `create`.
 * Rendering goes through UiView or the real front controller; the host side is the canned fake agent (rows from a
 * CANNED_HIERARCHY fixture) or a recording fake host: never tmux, a real agent or the network.
 * The details sheet (Tags row, Edit tags) is drawn by JavaScript and is covered by test_session_tags_browser.php.
 */

putenv('SESSIONEER_AGENT_SOCKET=' . sys_get_temp_dir() . '/sessioneer-test-ui-session-tags-never.sock');

require_once __DIR__ . '/../vendor/autoload.php';
require __DIR__ . '/lib/cli_flow.php';
require_once __DIR__ . '/lib/http.php';
require_once __DIR__ . '/lib/free_port.php';

use App\Views\UiView;

set_time_limit(90);

/** @param array<string, mixed> $extra */
function ut_row(string $name, array $extra = []): array
{
    return $extra + ['name' => $name, 'title' => 'Title ' . $name, 'agent' => 'claude', 'agent_label' => 'Claude Code', 'runtime' => 'headless', 'working' => false, 'activity' => time() - 60, 'workdir' => '/tmp/x'];
}

function ut_dom(string $html): DOMXPath
{
    $doc = new DOMDocument();
    libxml_use_internal_errors(true);
    $doc->loadHTML('<?xml encoding="utf-8"?>' . ($html === '' ? '<div></div>' : $html));
    libxml_clear_errors();

    return new DOMXPath($doc);
}

function ut_class(string $class): string
{
    return "contains(concat(' ', normalize-space(@class), ' '), ' {$class} ')";
}

/** @return list<string> the text of every node the query matches */
function ut_texts(DOMXPath $x, string $query): array
{
    $out = [];
    foreach ($x->query($query) ?: [] as $node) {
        $out[] = trim($node->textContent);
    }

    return $out;
}

function ut_card(string $name): string
{
    return "//article[@data-session='{$name}']";
}

/** The data-tags value of one card, or `missing`. */
function ut_data_tags(DOMXPath $x, string $name): string
{
    $card = $x->query(ut_card($name))->item(0);

    return $card instanceof DOMElement && $card->hasAttribute('data-tags') ? $card->getAttribute('data-tags') : 'missing';
}

echo "Cards\n";
$html = UiView::sessions_html([
    ut_row('two', ['tags' => ['worker', 'test builder']]),
    ut_row('one', ['tags' => ['solo']]),
    ut_row('none', ['tags' => []]),
    ut_row('old'),
    ut_row('eight', ['tags' => ['t1', 't2', 't3', 't4', 't5', 't6', 't7', 't8']]),
], 'tok');
$x = ut_dom($html);
assert_equal('worker,test builder', ut_data_tags($x, 'two'), 'card: data-tags is comma joined in order');
assert_equal('solo', ut_data_tags($x, 'one'), 'card: one tag, no separator');
assert_equal('', ut_data_tags($x, 'none'), 'card: data-tags is empty when there are no tags');
assert_equal('', ut_data_tags($x, 'old'), 'card: a row without the key renders an empty data-tags');
assert_equal('t1,t2,t3,t4,t5,t6,t7,t8', ut_data_tags($x, 'eight'), 'card: eight tags all present');
assert_contains('<span class="ui-chip ui-chip-tag">worker</span>', $html, 'card: the chip markup');
assert_contains('<span class="ui-chip ui-chip-tag">test builder</span>', $html, 'card: a chip for a tag with a space');
$chips = ut_card('two') . '//div[' . ut_class('ui-chips') . ']/span[' . ut_class('ui-chip-tag') . ']';
assert_equal(['worker', 'test builder'], ut_texts($x, $chips), 'card: one chip per tag, in order, in the chips row');
assert_equal(2, $x->query(ut_card('two') . '//*[' . ut_class('ui-chip-tag') . ']')->length, 'card: no tag chip anywhere else on the card');
assert_equal(8, $x->query(ut_card('eight') . '//*[' . ut_class('ui-chip-tag') . ']')->length, 'card: eight chips');
foreach (['none', 'old'] as $name) {
    assert_equal(0, $x->query(ut_card($name) . '//*[' . ut_class('ui-chip-tag') . ']')->length, "card {$name}: no tag chip");
}

echo "Cards: nesting keeps tags with their own card\n";
$x = ut_dom(UiView::sessions_html([ut_row('par', ['tags' => ['orchestrator']]), ut_row('kid', ['launched_by' => 'par', 'tags' => ['worker']])], 'tok'));
assert_equal(['orchestrator', 'worker'], [ut_data_tags($x, 'par'), ut_data_tags($x, 'kid')], 'a child card carries its own tags, not its parent\'s');
assert_equal(['worker'], ut_texts($x, ut_card('kid') . '//*[' . ut_class('ui-chip-tag') . ']'), 'and its own chip');

echo "Cards: escaping\n";
$html = UiView::sessions_html([ut_row('hostile', ['tags' => ['a"b', '<img src=x>', 'c&d']])], 'tok');
assert_true(!str_contains($html, '<img src=x>') && !str_contains($html, 'a"b'), 'card: tags are escaped in attribute and text');
$x = ut_dom($html);
assert_equal('a"b,<img src=x>,c&d', ut_data_tags($x, 'hostile'), 'card: the escaped attribute decodes to the tags');
assert_equal(['a"b', '<img src=x>', 'c&d'], ut_texts($x, ut_card('hostile') . '//*[' . ut_class('ui-chip-tag') . ']'), 'card: chip text decodes to the tags');
assert_equal(0, $x->query('//img')->length, 'card: no injected element');

echo "Session page: the hierarchy strip\n";
$page = static fn (array $detail): string => UiView::session_page(['sessionName' => 'cc-x', 'csrfToken' => 'tok', 'found' => true, 'detail' => $detail + ['ok' => true, 'name' => 'cc-x', 'runtime' => 'headless', 'agent' => 'claude', 'status' => 'idle'], 'jumpLine' => null]);
$stripChips = '//*[' . ut_class('sess-hier') . ']//*[' . ut_class('ui-chip-tag') . ']';
$x = ut_dom($page(['tags' => ['reviewer', 'project x']]));
assert_equal(['reviewer', 'project x'], ut_texts($x, $stripChips), 'page: tag chips in .sess-hier, with no hierarchy at all');
assert_equal(2, $x->query($stripChips . '[' . ut_class('ui-chip') . ']')->length, 'page: they are chips');
$x = ut_dom($page(['tags' => ['worker'], 'launched_by' => 'cc-parent', 'launched' => ['cc-kid'], 'waiting_on' => [['name' => 'cc-w', 'state' => 'live', 'since' => time()]]]));
assert_equal(['worker'], ut_texts($x, $stripChips), 'page: tags beside the hierarchy');
assert_equal(1, $x->query('//*[' . ut_class('sess-hier') . ']')->length, 'page: one strip');
assert_true(str_contains((string)($x->query('//*[' . ut_class('sess-hier') . ']')->item(0)?->textContent), 'Launched by'), 'page: the hierarchy is still there');
foreach (['empty tags' => ['tags' => []], 'no tags key' => [], 'hierarchy without tags' => ['launched_by' => 'cc-parent', 'tags' => []]] as $label => $detail) {
    assert_equal(0, ut_dom($page($detail))->query('//*[' . ut_class('ui-chip-tag') . ']')->length, "page, {$label}: no tag chip");
}
$html = $page(['tags' => ['"><script>x</script>']]);
assert_true(!str_contains($html, '"><script>x</script>'), 'page: tags are escaped');
assert_equal(['"><script>x</script>'], ut_texts(ut_dom($html), $stripChips), 'page: and shown as text');

/** @return array{process: resource, base: string} */
function ut_server(string $agentSocket, string $scratch): array
{
    $port = test_free_port();
    $process = proc_open(
        ['php', '-d', 'session.save_path=' . $scratch, '-S', "127.0.0.1:{$port}", '-t', dirname(__DIR__) . '/public', dirname(__DIR__) . '/public/index.php'],
        [0 => ['pipe', 'r'], 1 => ['file', '/dev/null', 'w'], 2 => ['file', $scratch . '/server.log', 'a']],
        $pipes,
        null,
        array_merge(getenv(), ['SESSIONEER_AGENT_SOCKET' => $agentSocket])
    );
    $deadline = microtime(true) + 3.0;
    do {
        $c = @fsockopen('127.0.0.1', $port, $errno, $error, 0.2);
        if (is_resource($c)) {
            fclose($c);
            break;
        }
        usleep(50000);
    } while (microtime(true) < $deadline);

    return ['process' => $process, 'base' => "http://127.0.0.1:{$port}"];
}

/** @return list<array{value: string, text: string}> the options of the tag filter */
function ut_options(DOMXPath $x): array
{
    $out = [];
    foreach ($x->query("//select[@id='ui-tag-filter']/option") ?: [] as $option) {
        $out[] = ['value' => $option->hasAttribute('value') ? $option->getAttribute('value') : trim($option->textContent), 'text' => trim($option->textContent)];
    }

    return $out;
}

$scratch = sys_get_temp_dir() . '/sessioneer-test-ui-tags-' . getmypid() . '-' . bin2hex(random_bytes(3));
mkdir($scratch, 0700);
$fixture = $scratch . '/rows.json';
$cannedSocket = $scratch . '/canned.sock';
$canned = null;
$cannedServer = null;
$rigServer = null;
$rig = null;

try {
    putenv('CANNED_HIERARCHY=' . $fixture);
    file_put_contents($fixture, json_encode(['rows' => [
        ['name' => 'r1', 'tags' => ['worker', 'project x']],
        ['name' => 'r2', 'tags' => ['project x', 'runner']],
        ['name' => 'r3', 'tags' => []],
        ['name' => 'r4', 'tags' => ['worker']],
        ['name' => 'r5'],
    ]]));
    $canned = start_harness(['php', __DIR__ . '/fixtures/canned_agent.php'], $cannedSocket);
    $cannedServer = ut_server($cannedSocket, $scratch);

    echo "Dashboard: tag filter\n";
    $response = curl_request('GET', $cannedServer['base'] . '/');
    assert_equal(200, $response['status'], 'GET /: 200');
    $x = ut_dom($response['body']);
    assert_equal(1, $x->query("//select[@id='ui-tag-filter']")->length, 'GET /: one select#ui-tag-filter');
    assert_equal(1, $x->query("//*[@id='ui-panel-sessions']//select[@id='ui-tag-filter']")->length, 'GET /: it belongs to the sessions panel');
    $options = ut_options($x);
    assert_equal(['value' => '', 'text' => 'All tags'], $options[0] ?? null, 'filter: first option is `All tags` with an empty value');
    $values = array_column(array_slice($options, 1), 'value');
    sort($values);
    assert_equal(['project x', 'runner', 'worker'], $values, 'filter: one option per distinct tag among the listed sessions');
    assert_equal(array_column(array_slice($options, 1), 'value'), array_column(array_slice($options, 1), 'text'), 'filter: each option shows its tag');
    assert_equal('worker,project x', ut_data_tags($x, 'r1'), 'GET /: the cards carry their tags');
    assert_equal('', ut_data_tags($x, 'r5'), 'GET /: an untagged card has an empty data-tags');

    echo "Dashboard: New session form\n";
    $inputs = $x->query("//*[@id='ui-new-form']//input[@name='tags']");
    assert_equal(1, $inputs->length, 'New session form: an input named tags');
    $input = $inputs->item(0);
    assert_true($input instanceof DOMElement && !$input->hasAttribute('required'), 'New session form: it is optional');
    assert_true($input instanceof DOMElement && in_array($input->getAttribute('type'), ['', 'text'], true), 'New session form: a text input');

    echo "Dashboard: filter options with no tags, and hostile tags\n";
    file_put_contents($fixture, json_encode(['rows' => [['name' => 'r1', 'tags' => []], ['name' => 'r2']]]));
    $x = ut_dom(curl_request('GET', $cannedServer['base'] . '/')['body']);
    assert_equal([['value' => '', 'text' => 'All tags']], ut_options($x), 'no tagged session: only `All tags`');
    file_put_contents($fixture, json_encode(['rows' => [['name' => 'r1', 'tags' => ['"><script>x</script>']]]]));
    $body = curl_request('GET', $cannedServer['base'] . '/')['body'];
    assert_true(!str_contains($body, '"><script>x</script>'), 'filter: option values and text are escaped');
    assert_equal(['"><script>x</script>'], array_column(array_slice(ut_options(ut_dom($body)), 1), 'value'), 'filter: and decode to the tag');

    $rig = new CliFlowRig('ui-session-tags-' . getmypid());
    $rig->reply(['ok' => true, 'sessions' => [], 'name' => 'cc-new', 'tags' => ['alpha', 'test builder']]);
    $rigServer = ut_server($rig->socket, $scratch);
    $base = $rigServer['base'];
    $jar = $scratch . '/cookies';
    $dashboard = curl_request('GET', $base . '/', [], $jar)['body'];
    $csrf = preg_match('/id="ui-app" data-csrf="([^"]+)"/', $dashboard, $m) === 1 ? $m[1] : '';
    assert_true($csrf !== '', 'fixture: CSRF token read from the dashboard');

    $sent = static fn (string $action): array => array_values(array_filter($rig->requests(), static fn (array $r): bool => ($r['action'] ?? null) === $action));
    $post = static function (string $path, array $fields, array $headers = []) use ($base, $jar, $rig): array {
        $rig->reset();
        $args = ['--data-raw', http_build_query($fields)];
        foreach ($headers as $header) {
            array_push($args, '-H', $header);
        }

        return curl_request('POST', $base . $path, $args, $jar);
    };

    echo "POST /session_tags.php\n";
    foreach (['add', 'remove', 'set'] as $mode) {
        $r = $post('/session_tags.php', ['csrf_token' => $csrf, 'session' => 'cc-x', 'mode' => $mode, 'tags' => ['alpha', 'test builder']]);
        assert_equal(200, $r['status'], "session_tags {$mode}: 200");
        $json = json_decode($r['body'], true);
        assert_equal([true, ['alpha', 'test builder']], [$json['ok'] ?? null, $json['tags'] ?? null], "session_tags {$mode}: the host reply comes back as JSON");
        $requests = $sent('tags_set');
        assert_equal(1, count($requests), "session_tags {$mode}: one tags_set call");
        assert_equal(
            ['cc-x', $mode, ['alpha', 'test builder']],
            [$requests[0]['session'] ?? null, $requests[0]['mode'] ?? null, $requests[0]['tags'] ?? null],
            "session_tags {$mode}: session, mode and tags forwarded"
        );
    }
    $rig->reply(['ok' => true, 'tags' => []]);
    foreach (['/session_tags.php' => [], '/action' => ['action' => 'tags']] as $path => $extra) {
        $r = $post($path, ['csrf_token' => $csrf, 'session' => 'cc-x', 'mode' => 'set'] + $extra);
        $requests = $sent('tags_set');
        assert_equal([200, true, []], [$r['status'], json_decode($r['body'], true)['ok'] ?? null, json_decode($r['body'], true)['tags'] ?? 'missing'], "{$path} clear returns successful JSON");
        assert_equal([1, 'set', []], [count($requests), $requests[0]['mode'] ?? null, $requests[0]['tags'] ?? 'missing'], "{$path} explicitly forwards an empty list");
    }

    $rig->reply(['ok' => false, 'error' => 'invalid tag: bad!']);
    $r = $post('/session_tags.php', ['csrf_token' => $csrf, 'session' => 'cc-x', 'mode' => 'add', 'tags' => ['bad!']]);
    $json = json_decode($r['body'], true);
    assert_equal([false, 'invalid tag: bad!'], [$json['ok'] ?? null, $json['error'] ?? null], 'session_tags: a host refusal reaches the page');
    $rig->reply(['ok' => true, 'sessions' => [], 'name' => 'cc-new', 'tags' => ['alpha']]);

    echo "POST /session_tags.php: guards\n";
    $good = ['session' => 'cc-x', 'mode' => 'add', 'tags' => ['alpha']];
    foreach (['no token' => $good, 'a wrong token' => $good + ['csrf_token' => 'not-the-token']] as $label => $fields) {
        $r = $post('/session_tags.php', $fields);
        assert_equal(403, $r['status'], "session_tags with {$label}: 403");
        assert_equal([], $sent('tags_set'), "session_tags with {$label}: nothing reaches the host");
    }
    $r = $post('/session_tags.php', $good + ['csrf_token' => $csrf], ['Origin: http://attacker.example']);
    assert_equal(403, $r['status'], 'session_tags cross origin: 403');
    assert_equal([], $sent('tags_set'), 'session_tags cross origin: nothing reaches the host');
    $rig->reset();
    $r = curl_request('GET', $base . '/session_tags.php?session=cc-x&mode=set', [], $jar);
    // The sibling POST routes answer GET with 404; a 405 would be as good.
    assert_true(in_array($r['status'], [404, 405], true), 'GET /session_tags.php: refused');
    assert_equal([], $sent('tags_set'), 'GET /session_tags.php: nothing reaches the host');

    echo "POST /action action=tags\n";
    $r = $post('/action', ['csrf_token' => $csrf, 'action' => 'tags', 'session' => 'cc-x', 'mode' => 'add', 'tags' => ['alpha']]);
    assert_equal(true, json_decode($r['body'], true)['ok'] ?? null, 'action=tags: ok');
    $requests = $sent('tags_set');
    assert_equal(1, count($requests), 'action=tags: one tags_set call');
    assert_equal(['cc-x', 'add', ['alpha']], [$requests[0]['session'] ?? null, $requests[0]['mode'] ?? null, $requests[0]['tags'] ?? null], 'action=tags: the same request');
    $rig->reply(['ok' => false, 'error' => 'Unknown session']);
    $r = $post('/action', ['csrf_token' => $csrf, 'action' => 'tags', 'session' => 'cc-nope', 'mode' => 'add', 'tags' => ['alpha']]);
    assert_equal(false, json_decode($r['body'], true)['ok'] ?? null, 'action=tags: a host refusal is not reported as success');
    assert_equal(1, count($sent('tags_set')), 'action=tags refusal: it did ask the host');
    $r = $post('/action', ['action' => 'tags', 'session' => 'cc-x', 'mode' => 'add', 'tags' => ['alpha']]);
    assert_equal(403, $r['status'], 'action=tags without a token: 403');
    assert_equal([], $sent('tags_set'), 'action=tags without a token: nothing reaches the host');

    echo "POST /action action=new forwards tags\n";
    $rig->reply(['ok' => true, 'name' => 'cc-new']);
    $split = static fn (mixed $tags): array => array_values(array_filter(array_map('trim', is_array($tags) ? $tags : explode(',', (string)$tags)), static fn (string $t): bool => $t !== ''));
    foreach (['claude' => 'no prompt', 'codex' => 'with a first message'] as $agent => $label) {
        $r = $post('/action', ['csrf_token' => $csrf, 'action' => 'new', 'agent' => $agent, 'workdir' => '/tmp', 'tags' => 'alpha, test builder'] + ($label === 'no prompt' ? [] : ['prompt' => 'hi']));
        $requests = $sent('create');
        assert_equal(1, count($requests), "new ({$label}): one create call");
        assert_equal(['alpha', 'test builder'], $split($requests[0]['tags'] ?? ''), "new ({$label}): the form's tags go with create");
    }
    foreach ([['worker', 'test builder'], ['bad!']] as $tags) {
        $post('/action', ['csrf_token' => $csrf, 'action' => 'new', 'agent' => 'claude', 'workdir' => '/tmp', 'tags' => $tags]);
        $requests = $sent('create');
        assert_equal($tags, $requests[0]['tags'] ?? null, 'new: array tags reach host validation unchanged');
    }
    foreach (['blank' => ['tags' => ''], 'absent' => []] as $label => $extra) {
        $post('/action', ['csrf_token' => $csrf, 'action' => 'new', 'agent' => 'claude', 'workdir' => '/tmp'] + $extra);
        $requests = $sent('create');
        assert_equal(1, count($requests), "new with {$label} tags: still creates");
        assert_equal([], $split($requests[0]['tags'] ?? ''), "new with {$label} tags: no tags");
    }
} finally {
    foreach ([$cannedServer, $rigServer] as $server) {
        if ($server !== null && is_resource($server['process'])) {
            proc_terminate($server['process']);
            proc_close($server['process']);
        }
    }
    if ($canned !== null) {
        stop_harness($canned, $cannedSocket);
    }
    $rig?->stop();
    foreach (glob($scratch . '/*') ?: [] as $file) {
        unlink($file);
    }
    rmdir($scratch);
}

test_exit();
