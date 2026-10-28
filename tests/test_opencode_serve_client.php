<?php
declare(strict_types=1);

require __DIR__ . '/lib/assert.php';
require dirname(__DIR__) . '/host-agent/lib/Sessions.php';

use HostAgent\Runtimes\OpenCodeServeClient;

$stub = __DIR__ . '/fixtures/opencode_serve_client_stub.php';
$sock = stream_socket_server('tcp://127.0.0.1:0', $errno, $errstr);
if ($sock === false) {
    fwrite(STDERR, "could not reserve a port: {$errstr}\n");
    exit(1);
}

$address = stream_socket_get_name($sock, false);
fclose($sock);
$port = (int)substr((string)$address, strrpos((string)$address, ':') + 1);
$server = proc_open(
    ['php', '-S', "127.0.0.1:{$port}", $stub],
    [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
    $pipes
);

if (!is_resource($server)) {
    fwrite(STDERR, "failed to start serve stub\n");
    exit(1);
}

putenv("OPENCODE_SERVE_URL=http://127.0.0.1:{$port}");

try {
    for ($i = 0; $i < 20; $i++) {
        usleep(100000);
        if (@file_get_contents("http://127.0.0.1:{$port}/api/session/missing") !== false) {
            break;
        }
    }

    $client = new OpenCodeServeClient();

    $created = $client->create_session('/tmp/project', 'Stub session');
    assert_true($created['ok'] ?? false, 'create_session: decodes a successful response');
    assert_equal('ses_stub_create', $created['id'] ?? null, 'create_session: returns the session id');

    $sessions = $client->list_sessions();
    assert_equal(true, $sessions['ok'] ?? null, 'list_sessions: successful response is handled');
    assert_equal(2, count($sessions['sessions'] ?? []), 'list_sessions: returns all session records');

    $detail = $client->get_session('ses_stub_create');
    assert_equal('model', $detail['session']['model']['id'] ?? null, 'get_session: unwraps the v2 data envelope');

    $sent = $client->send_message('ses_stub_create', 'hello');
    assert_true($sent['ok'] ?? false, 'send_message: accepts the v2 prompt response');

    $statuses = $client->status_map();
    assert_equal(['ses_stub_create' => 'working'], $statuses['statuses'] ?? null, 'status_map: only running sessions are reported, as working');

    $permissions = $client->pending_permissions();
    assert_equal('per_stub', $permissions[0]['id'] ?? null, 'pending_permissions: reads the v2 request list');

    $questions = $client->pending_questions(['ses_stub_create', 'ses_stub_other']);
    assert_equal(1, count($questions), 'pending_questions: one pending question across the given sessions');
    assert_equal('frm_stub', $questions[0]['id'] ?? null, 'pending_questions: carries the form id as the request identity');
    assert_equal('Which approach?', $questions[0]['questions'][0]['question'] ?? null, 'pending_questions: normalizes the form field to a question');

    $answered = $client->answer_permission('ses_stub_create', 'per_stub', 'once');
    assert_true($answered['ok'] ?? false, 'answer_permission: v2 decision reply succeeds');

    $models = $client->available_models();
    assert_equal(2, count($models), 'available_models: dedupes the same model across providers');
    $byId = array_column($models, 'providerID', 'id');
    assert_equal('opencode-go', $byId['stub-a'] ?? null, 'available_models: keeps the higher-priority provider copy');

    $default = $client->default_model();
    assert_equal(['providerID' => 'opencode-go', 'modelID' => 'stub-a'], $default, 'default_model: reads /api/model/default');

    $todo = $client->get_todo('ses_stub_create');
    assert_equal(false, $todo['ok'] ?? null, 'get_todo: reports v2 has no todo surface');

    $missing = $client->get_session('missing');
    assert_equal(false, $missing['ok'] ?? null, 'get_session: HTTP failure is returned as a handled error');
    assert_true(str_contains($missing['message'] ?? '', 'HTTP 404'), 'get_session: failure includes the HTTP status');

    putenv('OPENCODE_SERVE_URL=http://127.0.0.1:1');
    $unavailable = (new OpenCodeServeClient())->list_sessions();
    assert_equal(false, $unavailable['ok'] ?? null, 'list_sessions: unavailable serve is handled without throwing');
    assert_true(($unavailable['message'] ?? '') !== '', 'list_sessions: unavailable serve provides an error message');
} finally {
    proc_terminate($server);
    proc_close($server);
}

test_exit();
