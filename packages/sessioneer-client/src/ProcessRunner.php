<?php

declare(strict_types=1);

namespace Loki495\SessioneerClient;

/**
 * Runs one child process with a hard deadline, separate streams and no shell.
 *
 * Written on proc_open rather than a process library so the client has no
 * dependencies: the array-form command means no quoting or shell is involved,
 * and a timeout always ends in SIGTERM then SIGKILL, so a hung CLI cannot keep
 * the caller's worker blocked.
 */
final class ProcessRunner
{
    /**
     * @param  list<string>  $command
     * @param  string|null  $stdin  Written to the child and then closed; null sends an empty stdin.
     *
     * @throws \RuntimeException when the child cannot be started at all.
     */
    public function run(array $command, ?string $stdin, string $workdir, int $timeoutSeconds): ProcessOutcome
    {
        $descriptors = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];

        $process = proc_open($command, $descriptors, $pipes, $workdir, null, ['bypass_shell' => true]);

        if (!is_resource($process)) {
            throw new \RuntimeException('could not start ' . ($command[0] ?? 'the process'));
        }

        /** @var array{0: resource, 1: resource, 2: resource} $pipes */
        self::write($pipes[0], $stdin ?? '');
        fclose($pipes[0]);
        stream_set_blocking($pipes[1], false);
        stream_set_blocking($pipes[2], false);

        $stdout = '';
        $stderr = '';
        $deadline = microtime(true) + $timeoutSeconds;
        $timedOut = false;
        $exitCode = null;

        while (true) {
            self::drain($pipes[1], $stdout);
            self::drain($pipes[2], $stderr);

            $status = proc_get_status($process);

            if (!$status['running']) {
                $exitCode = $status['exitcode'];
                break;
            }

            $remaining = $deadline - microtime(true);

            if ($remaining <= 0) {
                $timedOut = true;
                break;
            }

            $read = [$pipes[1], $pipes[2]];
            $write = null;
            $except = null;
            $seconds = (int) floor($remaining);
            $microseconds = (int) (($remaining - $seconds) * 1_000_000);

            if (@stream_select($read, $write, $except, $seconds, $microseconds) === false) {
                break;
            }
        }

        if ($timedOut) {
            $exitCode = self::terminate($process, $pipes, $stdout, $stderr);
        }

        self::drain($pipes[1], $stdout);
        self::drain($pipes[2], $stderr);
        fclose($pipes[1]);
        fclose($pipes[2]);

        $closed = proc_close($process);

        if ($exitCode === null || $exitCode < 0) {
            $exitCode = $closed;
        }

        return new ProcessOutcome($stdout, $stderr, $exitCode, $timedOut);
    }

    /**
     * SIGTERM, then SIGKILL if it is still there after a short grace period.
     *
     * @param  resource  $process
     * @param  array{0: resource, 1: resource, 2: resource}  $pipes
     */
    private static function terminate($process, array $pipes, string &$stdout, string &$stderr): ?int
    {
        proc_terminate($process, 15);
        $giveUp = microtime(true) + 5;

        while (microtime(true) < $giveUp) {
            self::drain($pipes[1], $stdout);
            self::drain($pipes[2], $stderr);
            $status = proc_get_status($process);

            if (!$status['running']) {
                return $status['exitcode'];
            }

            usleep(20_000);
        }

        proc_terminate($process, 9);

        return null;
    }

    /** @param  resource  $stream */
    private static function drain($stream, string &$buffer): void
    {
        while (($chunk = fread($stream, 8192)) !== false && $chunk !== '') {
            $buffer .= $chunk;
        }
    }

    /** A pipe can take a short write, so keep going until every byte is in.
     *
     * @param  resource  $stream
     */
    private static function write($stream, string $payload): void
    {
        $offset = 0;
        $length = strlen($payload);

        while ($offset < $length) {
            $written = fwrite($stream, substr($payload, $offset, 65536));

            if ($written === false || $written === 0) {
                return;
            }

            $offset += $written;
        }
    }
}
