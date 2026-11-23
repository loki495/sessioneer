<?php
declare(strict_types=1);

/**
 * One connection of a forwarding proxy for the reset-drift CLI test: relays the request to the real host agent at
 * $DRIFT_TARGET and returns its reply. Just before relaying a flow_schedule request it applies the pending drift once:
 * $DRIFT_FLAG holds the new stored personal reset (epoch), so the stored value moves after the CLI's flow_resets read and
 * before the schedule. Run through tests/lib/socket_harness.php; never touches a real agent.
 */

require dirname(__DIR__, 2) . '/vendor/autoload.php';

$request = (string)stream_get_contents(STDIN);
$flag = (string)getenv('DRIFT_FLAG');
$drift = (int)@file_get_contents($flag);

if ($drift > 0 && (json_decode($request, true)['action'] ?? '') === 'flow_schedule') {
    @unlink($flag);
    \HostAgent\Stores\GlobalStateStore::write(\HostAgent\Services\Config::quota_live_state_key(), ['captured_at' => time(), 'session' => ['pct' => 95, 'resets_at' => $drift]]);
}

$conn = stream_socket_client('unix://' . getenv('DRIFT_TARGET'), $errno, $errstr, 5);
fwrite($conn, $request);
stream_socket_shutdown($conn, STREAM_SHUT_WR);
echo (string)stream_get_contents($conn);
