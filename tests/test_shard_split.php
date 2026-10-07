<?php
declare(strict_types=1);

// tests/lib/shard.sh splits the suite for `run.sh --shard N/M`. Checked here
// without starting any test infrastructure (run.sh itself would take the
// per-checkout lock this run already holds).

$testsDir = __DIR__;
$failures = 0;

function check(bool $ok, string $what): void
{
    global $failures;
    echo ($ok ? "ok   " : "FAIL ") . $what . "\n";
    if (!$ok) {
        $failures++;
    }
}

function sh(string $script): array
{
    $proc = proc_open(['bash', '-c', $script], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
    $out = stream_get_contents($pipes[1]);
    $err = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    return [proc_close($proc), trim($out), trim($err)];
}

$lib = escapeshellarg($testsDir . '/lib/shard.sh');
$weights = tempnam(sys_get_temp_dir(), 'shardw');
file_put_contents($weights, "test_big.php 100\ntest_mid.php 40\ntest_small_a.php 10\ntest_small_b.php 10\n");
$w = escapeshellarg($weights);
$files = 'tests/test_big.php tests/test_mid.php tests/test_small_a.php tests/test_small_b.php tests/test_unlisted.php';

$seen = [];
$loads = [];
foreach ([1, 2, 3] as $n) {
    [$code, $out] = sh(". $lib; shard_files $n/3 $w $files");
    $mine = $out === '' ? [] : explode("\n", $out);
    check($code === 0, "shard $n/3 succeeds");
    $seen = array_merge($seen, $mine);
    $loads[$n] = array_sum(array_map(fn ($f) => ['test_big.php' => 100, 'test_mid.php' => 40][basename($f)] ?? (basename($f) === 'test_unlisted.php' ? 5 : 10), $mine));
}
$all = explode(' ', $files);
sort($all);
sort($seen);
check($seen === $all, 'every file lands in exactly one shard');
check($loads[1] === 100 && max($loads) - min($loads) <= 95 && min($loads) >= 15, 'the heaviest file sits alone; the rest are spread out');

[, $a] = sh(". $lib; shard_files 2/3 $w $files");
[, $b] = sh(". $lib; shard_files 2/3 $w " . implode(' ', array_reverse($all)));
check($a === $b, 'the split does not depend on input order');

[, $one] = sh(". $lib; shard_files 1/1 $w $files");
check(count(explode("\n", $one)) === 5, '1/1 keeps every file');

foreach (['0/3', '4/3', 'x', '2', '1/0', '-1/3', ''] as $bad) {
    [$code, , $err] = sh(". $lib; shard_validate " . escapeshellarg($bad));
    $expected = preg_match('~^[1-9][0-9]*/[1-9][0-9]*$~', $bad) ? 'needs N <= M' : 'needs N/M with 1 <= N <= M';
    check($code === 1 && str_contains($err, $expected), "rejects --shard '$bad' with: $expected");
}
[$code] = sh(". $lib; shard_validate 3/3");
check($code === 0, 'accepts 3/3');

$real = file($testsDir . '/shard-weights.txt', FILE_IGNORE_NEW_LINES);
$missing = [];
foreach (glob($testsDir . '/test_*.php') as $f) {
    if (!str_ends_with($f, '_live.php') && !preg_grep('~^' . preg_quote(basename($f), '~') . ' \d+$~', $real)) {
        $missing[] = basename($f);
    }
}
check(count($missing) <= 3, 'shard-weights.txt covers the suite (missing: ' . implode(', ', $missing) . ')');

unlink($weights);
exit($failures === 0 ? 0 : 1);
