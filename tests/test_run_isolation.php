<?php

declare(strict_types=1);

// Two checkouts' test runs must not share state: paths come from a per-checkout
// id, test servers take free ports, and test children don't hold the run lock.

require_once __DIR__ . '/lib/free_port.php';

$failures = 0;
function check(bool $ok, string $what): void
{
    global $failures;
    echo ($ok ? "ok   " : "FAIL ") . $what . "\n";
    if (!$ok) {
        $failures++;
    }
}

function env_paths(string $id): array
{
    $script = '. ' . escapeshellarg(__DIR__ . '/.env.testing') . '; echo "$TMUX_SOCKET"; echo "$SIDECAR_DIR"; echo "$CACHE_DIR"';
    $env = $id === '' ? [] : ['SESSIONEER_TEST_ID' => $id];
    $proc = proc_open(['bash', '-c', 'SCRIPT_DIR=' . escapeshellarg(__DIR__) . '; ' . $script], [1 => ['pipe', 'w']], $pipes, null, $env + ['PATH' => '/usr/bin:/bin']);
    $out = stream_get_contents($pipes[1]);
    proc_close($proc);
    return explode("\n", trim($out));
}

$a = env_paths('111');
$b = env_paths('222');
$adhoc = env_paths('');
check(count(array_intersect($a, $b)) === 0, 'two checkout ids get disjoint tmux/sidecar/cache paths');
check(str_contains($a[0], '111') && str_contains($a[1], '111') && str_contains($a[2], '111'), 'the id appears in every isolated path');
check(str_contains($adhoc[0], 'adhoc') && !in_array('', $adhoc, true), 'a file run by hand still gets non-empty, non-real paths');

$ports = [test_free_port(), test_free_port(), test_free_port()];
check(count(array_unique($ports)) >= 2 && min($ports) > 1024, 'test_free_port returns usable unprivileged ports');
$held = stream_socket_server("tcp://127.0.0.1:{$ports[0]}", $errno, $errstr);
check($held !== false, 'a returned port can be bound right away');
if ($held) {
    fclose($held);
}
$busy = stream_socket_server('tcp://127.0.0.1:0');
$busyPort = (int) substr(strrchr((string) stream_socket_get_name($busy, false), ':'), 1);
check(test_free_port() !== $busyPort, 'a port that is already bound is never returned');
fclose($busy);

$runSh = file_get_contents(__DIR__ . '/run.sh');
check(str_contains($runSh, 'php "$test_file" 200>&-'), 'run.sh starts each test with the run-lock fd closed');
check(str_contains($runSh, 'SESSIONEER_TEST_ID') && str_contains($runSh, 'sessioneer-test-run-$SESSIONEER_TEST_ID.lock'), 'run.sh keys the lock by the checkout id');

exit($failures === 0 ? 0 : 1);
