<?php

declare(strict_types=1);

namespace HostAgent\Services;

/**
 * A generic process-execution primitive - not tmux-specific (used for
 * tmux commands, kill signals and other host commands alike), so it's
 * its own small class rather than folded into TmuxService.
 */
class ProcessRunner
{
    /**
     * @param string[] $cmd
     * @return array{exit:int,stdout:string,stderr:string}
     */
    public static function run_process(array $cmd): array
    {
        $descriptors = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];

        $process = proc_open($cmd, $descriptors, $pipes);

        if (!is_resource($process)) {
            return ['exit' => -1, 'stdout' => '', 'stderr' => 'failed to start process'];
        }

        fclose($pipes[0]);
        $stdout = stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $exit = proc_close($process);

        return ['exit' => $exit, 'stdout' => (string)$stdout, 'stderr' => (string)$stderr];
    }
}
