<?php

declare(strict_types=1);

require __DIR__ . '/lib/assert.php';
require dirname(__DIR__) . '/host-agent/lib/Sessions.php';

use App\Views\TranscriptView;
use HostAgent\Runtimes\CodexBridgeClient;
use HostAgent\Runtimes\CodexHeadlessRuntime;
use HostAgent\Services\CodexTranscriptService;
use HostAgent\Stores\SessionStatusStore;
use HostAgent\Stores\SqliteDb;

class TurnErrorCodexClient extends CodexBridgeClient
{
    public array $turns = [];
    public function request(string $method, array $params = []): array
    {
        return ['ok' => true, 'result' => ['thread' => ['id' => 'quota-fixture', 'status' => ['type' => 'idle'], 'turns' => $this->turns]]];
    }
}

$db = sys_get_temp_dir() . '/sessioneer-codex-errors-' . bin2hex(random_bytes(8)) . '.sqlite';
putenv('SESSIONS_SQLITE_FILE=' . $db);
SqliteDb::reset_connections_for_tests();
try {
    $error = ['message' => 'Try again at 18:30. <script>alert(1)</script>', 'codexErrorInfo' => 'usageLimitExceeded'];
    $client = new TurnErrorCodexClient();
    $runtime = new CodexHeadlessRuntime($client);
    $client->turns = [['status' => 'failed', 'error' => $error]];
    $detail = sessioneer_headless_detail_shape($runtime->detail('quota-fixture')['session'], 'codex');
    assert_contains('Codex quota reached.', $detail['last_turn_error'] ?? '', 'queued turn quota failure reaches the session response without bridge events');
    assert_contains('18:30', $detail['last_turn_error'] ?? '', 'quota reset guidance is preserved');
    $html = TranscriptView::render_turn_error_html($detail, 'Codex');
    assert_contains('Codex quota reached.', $html, 'quota failure renders in the transcript');
    assert_true(!str_contains($html, '<script>'), 'error message HTML is escaped');

    SessionStatusStore::update_status('quota-fixture', ['last_turn_error' => json_encode($error)]);
    $stored = sessioneer_headless_detail_shape(['id' => 'quota-fixture'], 'codex');
    assert_contains('Codex quota reached.', $stored['last_turn_error'] ?? '', 'legacy bridge JSON errors are readable when turns are unavailable');
    foreach (['inProgress', 'completed', 'interrupted'] as $status) {
        $client->turns[] = ['status' => $status, 'error' => null];
        $detail = sessioneer_headless_detail_shape($runtime->detail('quota-fixture')['session'], 'codex');
        assert_equal(null, $detail['last_turn_error'], $status . ' turn clears the old quota warning despite stale stored state');
        assert_equal('', TranscriptView::render_turn_error_html($detail, 'Codex'), $status . ' turn has no error card');
    }
    $client->turns = [];
    assert_equal(null, $runtime->detail('quota-fixture')['session']['lastTurnError'], 'an empty thread has no error');
    assert_equal('Network unavailable', CodexTranscriptService::turn_error_message(['message' => 'Network unavailable', 'codexErrorInfo' => 'Other']), 'non-quota errors keep their actual message');
    assert_equal('Codex could not complete this turn.', CodexTranscriptService::turn_error_message(['unexpected' => true]), 'malformed error objects have a readable fallback');
    assert_equal(null, CodexTranscriptService::turn_error_message(null), 'missing errors do not create a warning');
    assert_equal(null, CodexTranscriptService::turn_error_message(''), 'empty errors do not create a warning');
    assert_contains('Codex quota reached.', CodexTranscriptService::turn_error_message(['codexErrorInfo' => 'UsageLimitExceeded']) ?? '', 'quota code without a message gives reset guidance');
} finally {
    SqliteDb::reset_connections_for_tests();
    foreach ([$db, $db . '-wal', $db . '-shm'] as $path) if (is_file($path)) unlink($path);
}
