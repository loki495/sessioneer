<?php

declare(strict_types=1);

namespace HostAgent\Services;

use HostAgent\Stores\SidecarStore;

/**
 * Schedules a one-shot "wake this session with a prompt" as an `at` job.
 *
 * The job runs `bin/sessioneer --resume <conversation id> "<prompt>"`, which sends the prompt to the
 * session when it is live and resumes it when it is archived. `at` copies the scheduling shell's whole
 * environment, and a session-spawned variable would make Sessioneer treat the job as that session, so
 * the command starts under `env -i` with only HOME, PATH and the agent socket override.
 */
final class SessionWakeService
{
    public const MAX_AHEAD_SECONDS = 30 * 86400;
    public const MAX_PROMPT_CHARS = 30000;

    /**
     * @return array{ok:bool, message:string, job?:int, at?:int}
     */
    public static function schedule(string $name, int $when, string $prompt, ?int $now = null): array
    {
        $now ??= time();
        $prompt = trim($prompt);

        if ($prompt === '') {
            return ['ok' => false, 'message' => 'Write the prompt to send'];
        }

        if (mb_strlen($prompt) > self::MAX_PROMPT_CHARS) {
            return ['ok' => false, 'message' => 'The prompt is longer than ' . self::MAX_PROMPT_CHARS . ' characters'];
        }

        if ($when <= $now + 30) {
            return ['ok' => false, 'message' => 'Pick a time at least a minute from now'];
        }

        if ($when > $now + self::MAX_AHEAD_SECONDS) {
            return ['ok' => false, 'message' => 'Pick a time within the next 30 days'];
        }

        $sidecar = SidecarStore::read_sidecar($name);
        $agentSessionId = is_string($sidecar['agent_session_id'] ?? null) ? $sidecar['agent_session_id'] : '';

        if ($sidecar === null || $agentSessionId === '') {
            return ['ok' => false, 'message' => 'This session has no conversation yet, so there is nothing to wake'];
        }

        $script = self::script(
            (string)($sidecar['agent'] ?? 'claude'),
            $agentSessionId,
            is_string($sidecar['workdir'] ?? null) ? $sidecar['workdir'] : '',
            is_string($sidecar['profile'] ?? null) ? $sidecar['profile'] : '',
            $prompt
        );

        $atBin = Config::sessioneer_config('AT_BIN', 'at');
        $result = ProcessRunner::run_process([$atBin, '-t', date('YmdHi', $when)], $script . "\n");
        $output = $result['stdout'] . "\n" . $result['stderr'];

        if ($result['exit'] !== 0 || !preg_match('/^job (\d+) at /m', $output, $m)) {
            return ['ok' => false, 'message' => 'Could not schedule the job: ' . (trim($result['stderr']) !== '' ? trim($result['stderr']) : 'at failed')];
        }

        return [
            'ok' => true,
            'job' => (int)$m[1],
            'at' => $when,
            'message' => 'Wake-up scheduled for ' . date('Y-m-d H:i', $when) . ' (at job ' . $m[1] . ')',
        ];
    }

    /** The shell command the job runs. */
    public static function script(string $agent, string $agentSessionId, string $workdir, string $profile, string $prompt): string
    {
        $home = (string)getenv('HOME');
        $env = ['HOME=' . $home, 'PATH=' . $home . '/.local/bin:/usr/local/bin:/usr/bin'];
        $socket = getenv('SESSIONEER_AGENT_SOCKET');

        if ($socket !== false && $socket !== '') {
            $env[] = 'SESSIONEER_AGENT_SOCKET=' . $socket;
        }

        $args = ['--agent', $agent, '--resume', $agentSessionId];

        if ($workdir !== '') {
            array_push($args, '--workdir', $workdir);
        }

        // Only Claude has account profiles; the CLI rejects --profile for the other agents.
        if ($agent === 'claude' && $profile !== '') {
            array_push($args, '--profile', $profile);
        }

        $args[] = $prompt;
        $log = sys_get_temp_dir() . '/sessioneer-wake.log';

        return 'env -i ' . implode(' ', array_map('escapeshellarg', $env)) . ' '
            . escapeshellarg(dirname(__DIR__, 3) . '/bin/sessioneer') . ' '
            . implode(' ', array_map('escapeshellarg', $args))
            . ' >> ' . escapeshellarg($log) . ' 2>&1';
    }
}
