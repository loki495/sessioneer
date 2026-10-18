#!/usr/bin/env php
<?php
declare(strict_types=1);

/**
 * Generic per-connection Unix socket harness, used by tests to stand in
 * for systemd's Accept=yes socket activation (StandardInput=socket /
 * StandardOutput=socket in sessioneer-agent@.service) without needing systemd.
 *
 * Usage: php socket_harness.php <socket-path> <command...>
 *
 * Listens on <socket-path>; for every connection, runs <command...> with
 * the accepted stream bound directly to its stdin *and* stdout (proc_open
 * accepts an existing stream resource as a descriptor and dup()s it into
 * the child), then goes back to accepting. Runs until the parent test
 * kills this process (proc_terminate) once its assertions are done.
 */

/**
 * Kills whatever process currently has $socketPath open (almost always a
 * previous, now-orphaned instance of this same script) before this
 * instance unlinks and rebinds it. Unlinking the
 * socket FILE (the one cleanup step that already existed, both here and in
 * start_harness()) only removes the directory entry - it does nothing to
 * the PROCESS still holding the old listening socket open, which just
 * keeps running, orphaned, forever (10 of them had piled up across
 * several days of ad-hoc manual-verification runs reusing the same fixed
 * socket path - harmless individually, but pure accumulating clutter, and
 * it got in the way once already: cluttering a `ps aux | grep claude` scan
 * during an unrelated live-incident investigation the same night).
 *
 * Deliberately the FIRST thing this script does, before the real unlink/
 * bind/accept-loop below - a safeguard against leaked processes has to run
 * before anything that could itself fail or throw, or it's not a
 * safeguard. Best-effort by design (a stale process that's already gone,
 * or a socket path with nothing on it yet, are both just no-ops here) -
 * this must never be the reason a legitimate harness start fails.
 */
function kill_stale_listener(string $socketPath): void
{
    // NOT the filesystem inode (fileinode()/stat() on the socket special
    // file) - a bound AF_UNIX socket's kernel-internal inode (the number
    // that shows up as socket:[N] in /proc/<pid>/fd/*, which is what
    // actually identifies it below) is a completely separate numbering
    // space. /proc/net/unix is what maps a bound path back to that real
    // socket inode after a first attempt using
    // fileinode() silently matched nothing (different number spaces, so it
    // just never found the process it was supposed to kill).
    $unixSockets = @file('/proc/net/unix', FILE_IGNORE_NEW_LINES);

    if ($unixSockets === false) {
        return;
    }

    $staleInodes = [];

    foreach ($unixSockets as $line) {
        $fields = preg_split('/\s+/', trim($line));

        if (($fields[7] ?? null) === $socketPath && isset($fields[6])) {
            $staleInodes[$fields[6]] = true;
        }
    }

    if ($staleInodes === []) {
        return; // nothing bound at this path right now - nothing stale to clean up
    }

    $myPid = getmypid();

    foreach (glob('/proc/[0-9]*/fd/*') ?: [] as $fdPath) {
        // $fdPath is /proc/<pid>/fd/<fd> - dirname() once lands on
        // /proc/<pid>/fd (basename() of THAT is just "fd"), so the pid
        // itself needs a second dirname() first. An
        // earlier version of this got this one level short, so $pid was
        // always (int)"fd" === 0 - and POSIX kill(pid, sig) treats pid 0 as
        // "signal my entire process group", not "no such process". The
        // $pid > 0 check below is a second, independent guard against ever
        // repeating that - a safeguard that can send a signal to an
        // unintended target on its own bug is worse than no safeguard.
        $pid = (int)basename(dirname(dirname($fdPath)));

        if ($pid <= 0 || $pid === $myPid) {
            continue;
        }

        $link = @readlink($fdPath);

        if ($link === false || !str_starts_with($link, 'socket:[')) {
            continue;
        }

        if (isset($staleInodes[substr($link, 8, -1)])) {
            $killProcess = @proc_open(['kill', '-TERM', (string)$pid], [], $pipes);

            if (is_resource($killProcess)) {
                proc_close($killProcess);
            }
        }
    }
}

[, $socketPath, ] = $argv;
$command = array_slice($argv, 2);

kill_stale_listener($socketPath);
@unlink($socketPath);
$server = stream_socket_server('unix://' . $socketPath, $errno, $errstr);

if ($server === false) {
    fwrite(STDERR, "socket_harness: failed to listen on {$socketPath}: {$errstr}\n");
    exit(1);
}

while (true) {
    $conn = @stream_socket_accept($server, -1);

    if ($conn === false) {
        continue;
    }

    $process = proc_open($command, [0 => $conn, 1 => $conn, 2 => ['pipe', 'w']], $pipes);

    if (is_resource($process)) {
        fclose($pipes[2]);
        proc_close($process);
    }

    fclose($conn);
}
