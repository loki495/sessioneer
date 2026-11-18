#!/usr/bin/env php
<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use HostAgent\Stores\SessionStatusStore;
use HostAgent\Stores\SidecarStore;
use HostAgent\Runtimes\ClaudeHeadlessManagerClient;

$stateFile = (string) getenv('SPAWN_CLEANUP_CLAIMS_STATE');
$state = json_decode((string) file_get_contents($stateFile), true);
$id = $argv[1] ?? '';
$state['calls'][] = $id;
$count = count(array_filter($state['calls'], static fn (string $call): bool => $call === $id));
file_put_contents($stateFile, json_encode($state));
if ($id === ($state['target_id'] ?? null)) {
    if (isset($state['change_status'])) {
        SessionStatusStore::write_status($state['target_name'], ['status' => $state['change_status'], 'mode' => 'plan']);
    }
    if (isset($state['replace_identity'])) {
        $row = SidecarStore::read_sidecar($state['target_name']);
        SidecarStore::delete_sidecar($state['target_name']);
        SidecarStore::write_sidecar($state['target_name'], array_merge($row, ['agent_session_id' => $state['replace_identity']]));
    }
    if (isset($state['replace_owner'])) {
        $row = SidecarStore::read_sidecar($state['target_name']);
        SidecarStore::write_sidecar($state['target_name'], array_merge($row, ['spawned_by' => $state['replace_owner']]));
    }
    if (isset($state['live_transition'])) {
        $client = new ClaudeHeadlessManagerClient((string) getenv('CLAUDE_HEADLESS_SOCKET'), 5);
        $client->request('sessioneer/sendInput', ['session' => $state['target_name'], 'content' => $state['live_transition']]);
        $deadline = microtime(true) + 5;
        do {
            $live = $client->request('sessioneer/status', ['session' => $state['target_name']]);
            if (($live['state'] ?? null) === 'blocked') {
                break;
            }
            usleep(10000);
        } while (microtime(true) < $deadline);
        $state['live_transition_status'] = $live['state'] ?? null;
        file_put_contents($stateFile, json_encode($state));
        SessionStatusStore::write_status($state['target_name'], ['status' => 'idle', 'mode' => 'plan']);
    }
    if (($state['claim_after_first'] ?? false) && $count > 1) {
        echo '[{"id":99}]';
        exit(0);
    }
    echo $state['stdout'] ?? '[]';
    exit($state['exit'] ?? 0);
}
echo '[]';
