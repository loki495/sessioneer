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
    private const MAX_INSPECTED = 200;
    private const OVERDUE_AFTER_SECONDS = 60;
    private const PREVIEW_CHARS = 160;
    private const CHIP_CACHE_TTL_DEFAULT = 15;
    // One single-quoted shell word, the exact inverse of escapeshellarg().
    private const QUOTED = "/\\G'([^']*(?:'\\\\''[^']*)*)'/";

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

        // `at` has one-minute resolution, so any later minute is schedulable, seconds included.
        if (intdiv($when, 60) <= intdiv($now, 60)) {
            return ['ok' => false, 'message' => 'Pick a time in a later minute than now'];
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

        $local = (new \DateTimeImmutable('@' . $when))->setTimezone(new \DateTimeZone(self::system_timezone()));
        $result = self::at(['-t', $local->format('YmdHi')], $script . "\n");
        $output = $result['stdout'] . "\n" . $result['stderr'];

        if ($result['exit'] !== 0 || !preg_match('/^job (\d+) at /m', $output, $m)) {
            return ['ok' => false, 'message' => 'Could not schedule the job: ' . (trim($result['stderr']) !== '' ? trim($result['stderr']) : 'at failed')];
        }

        self::forget_chip_cache();

        return [
            'ok' => true,
            'job' => (int)$m[1],
            'at' => $when,
            'message' => 'Wake-up scheduled for ' . $local->format('Y-m-d H:i T') . ' (at job ' . $m[1] . ')',
        ];
    }

    /**
     * The machine's own time zone, which is the one `at` reads its time in. PHP's default is UTC unless
     * php.ini says otherwise, so `date()` would be hours off.
     */
    public static function system_timezone(): string
    {
        $tz = getenv('TZ');

        if ($tz !== false && $tz !== '' && @timezone_open(ltrim($tz, ':')) !== false) {
            return ltrim($tz, ':');
        }

        $link = @readlink('/etc/localtime');

        if ($link !== false && preg_match('#zoneinfo/(.+)$#', $link, $m) && @timezone_open($m[1]) !== false) {
            return $m[1];
        }

        return 'UTC';
    }

    /** The path script() embeds as the command, so a job is recognised as this checkout's. */
    public static function bin_path(): string
    {
        return dirname(__DIR__, 3) . '/bin/sessioneer';
    }

    /**
     * Sessioneer wake jobs in the `at` queue, soonest first. Only the 200 earliest queue rows are
     * inspected; `truncated` is set (and absent otherwise) when there were more.
     *
     * @return array{ok:bool, message?:string, wakes?:list<array<string,mixed>>, truncated?:bool}
     */
    public static function list(?string $agentSessionId = null, bool $includePrompt = false, ?int $now = null): array
    {
        $now ??= time();
        $queue = self::read_queue();

        if (!isset($queue['rows'])) {
            return $queue;
        }

        $rows = $queue['rows'];
        usort($rows, static fn (array $a, array $b): int => [$a['at'], $a['job']] <=> [$b['at'], $b['job']]);
        $truncated = count($rows) > self::MAX_INSPECTED;
        $wakes = [];

        foreach (array_slice($rows, 0, self::MAX_INSPECTED) as $row) {
            $body = self::at(['-c', (string)$row['job']]);
            $parsed = $body['exit'] === 0 ? self::parse_job_script($body['stdout'], self::bin_path()) : null;

            if ($parsed === null || ($agentSessionId !== null && $parsed['agent_session_id'] !== $agentSessionId)) {
                continue;
            }

            $wakes[] = self::wake_from($row, $parsed, $now, $includePrompt);
        }

        return ['ok' => true, 'wakes' => $wakes] + ($truncated ? ['truncated' => true] : []);
    }

    /** @return array{ok:bool, message?:string, gone?:bool, wake?:array<string,mixed>} */
    public static function show(int $job): array
    {
        $found = self::find($job);

        return isset($found['wake']) ? ['ok' => true, 'wake' => $found['wake']] : $found;
    }

    /** @return array{ok:bool, message:string, job?:int, name?:string, gone?:bool} */
    public static function cancel(int $job): array
    {
        $found = self::find($job);

        if (!isset($found['wake'])) {
            return $found;
        }

        $wake = $found['wake'];

        if ($wake['state'] === 'running') {
            return ['ok' => false, 'message' => "Wake-up {$job} is already running"];
        }

        $removed = self::at(['-r', (string)$job]);

        if ($removed['exit'] !== 0) {
            return ['ok' => false, 'message' => "Could not cancel wake-up {$job}: " . self::first_line($removed['stderr'])];
        }

        self::forget_chip_cache();

        return ['ok' => true, 'job' => $job, 'name' => (string)($wake['session'] ?? ''), 'message' => "Cancelled wake-up {$job} ({$wake['at_local']})"];
    }

    /**
     * The `at -l` rows as job, unix time and queue letter. `$timezone` is the zone `at` printed them in.
     *
     * @return list<array{job:int, at:int, queue:string}>
     */
    public static function parse_queue(string $atList, string $timezone): array
    {
        $zone = new \DateTimeZone($timezone);
        $rows = [];

        foreach (explode("\n", $atList) as $line) {
            if (!preg_match('/^(\d+)\s+(\w{3} \w{3}\s+\d{1,2} \d{2}:\d{2}:\d{2} \d{4})\s+(\S)\s+\S+$/', trim($line), $m)) {
                continue;
            }

            $date = \DateTimeImmutable::createFromFormat('D M j H:i:s Y', (string)preg_replace('/\s+/', ' ', $m[2]), $zone);

            if ($date !== false) {
                $rows[] = ['job' => (int)$m[1], 'at' => $date->getTimestamp(), 'queue' => $m[3]];
            }
        }

        return $rows;
    }

    /**
     * Reads `at -c` output back into the fields script() was given, or null when the job is not exactly
     * the command script() emits for this checkout. Parsed by a strict grammar of single-quoted words,
     * never by a shell, so a hand-made or tampered job is never mistaken for a wake-up.
     *
     * @return array{agent:string, agent_session_id:string, workdir:?string, profile:?string, prompt:string}|null
     */
    public static function parse_job_script(string $atC, string $binPath): ?array
    {
        $start = preg_match('/^env -i \'/m', $atC, $m, PREG_OFFSET_CAPTURE);

        if ($start !== 1 || $binPath === '') {
            return null;
        }

        $text = rtrim(substr($atC, $m[0][1]), "\r\n");
        $pos = strlen('env -i ');
        $tokens = [];

        while (true) {
            $token = self::read_quoted($text, $pos);

            if ($token === null) {
                return null;
            }

            $tokens[] = $token;

            if (substr($text, $pos, 4) === ' >> ') {
                $pos += 4;
                break;
            }

            if (($text[$pos] ?? '') !== ' ') {
                return null;
            }

            $pos++;
        }

        if (self::read_quoted($text, $pos) === null || substr($text, $pos) !== ' 2>&1') {
            return null;
        }

        if (!str_starts_with($tokens[0], 'HOME=') || !str_starts_with($tokens[1] ?? '', 'PATH=')) {
            return null;
        }

        $rest = array_slice($tokens, 2);

        if (str_starts_with($rest[0] ?? '', 'SESSIONEER_AGENT_SOCKET=')) {
            array_shift($rest);
        }

        if (array_shift($rest) !== $binPath || count($rest) < 5 || $rest[0] !== '--agent' || $rest[2] !== '--resume'
            || preg_match('/^[a-z][a-z0-9_-]*$/', $rest[1]) !== 1 || $rest[3] === '') {
            return null;
        }

        $parsed = ['agent' => $rest[1], 'agent_session_id' => $rest[3], 'workdir' => null, 'profile' => null];
        $rest = array_slice($rest, 4);

        // A pair counts only with the prompt behind it, so a prompt that is itself `--workdir` survives.
        foreach (['workdir', 'profile'] as $key) {
            if (count($rest) >= 3 && $rest[0] === '--' . $key) {
                $parsed[$key] = $rest[1];
                $rest = array_slice($rest, 2);
            }
        }

        return count($rest) === 1 ? $parsed + ['prompt' => $rest[0]] : null;
    }

    /**
     * Wake-up counts per conversation id for the session cards: `[id => [count, next_at, next_at_local]]`.
     * Running wakes are not counted. Served from a short-lived cache so the dashboard poll never spawns
     * `at` per card; a failed read is cached as "no wakes" so a broken `at` costs one spawn per TTL.
     *
     * @return array<string, array{count:int, next_at:int, next_at_local:string}>
     */
    public static function pending_by_conversation(?int $now = null): array
    {
        $now ??= time();
        $ttl = (int)Config::sessioneer_config('WAKE_CHIP_CACHE_TTL_SECONDS', (string)self::CHIP_CACHE_TTL_DEFAULT);
        $file = self::chip_cache_file();
        $cached = $ttl > 0 && is_file($file) ? json_decode((string)@file_get_contents($file), true) : null;

        if (is_array($cached) && is_int($cached['time'] ?? null) && is_array($cached['pending'] ?? null) && $now - $cached['time'] < $ttl) {
            $pending = $cached['pending'];
        } else {
            $pending = [];

            foreach (self::list(null, false, $now)['wakes'] ?? [] as $wake) {
                if ($wake['state'] !== 'running') {
                    $pending[] = ['id' => $wake['agent_session_id'], 'at' => $wake['at']];
                }
            }

            if ($ttl > 0) {
                $dir = dirname($file);
                is_dir($dir) || @mkdir($dir, 0700, true);
                $tmp = $file . '.' . getmypid();
                @file_put_contents($tmp, json_encode(['time' => $now, 'pending' => $pending]));
                @rename($tmp, $file);
            }
        }

        $zone = new \DateTimeZone(self::system_timezone());
        $chips = [];

        foreach ($pending as $p) {
            $next = min($p['at'], $chips[$p['id']]['next_at'] ?? $p['at']);
            $chips[$p['id']] = [
                'count' => ($chips[$p['id']]['count'] ?? 0) + 1,
                'next_at' => $next,
                'next_at_local' => (new \DateTimeImmutable('@' . $next))->setTimezone($zone)->format('Y-m-d H:i T'),
            ];
        }

        return $chips;
    }

    private static function chip_cache_file(): string
    {
        return Config::cache_dir() . '/wake-chip.json';
    }

    private static function forget_chip_cache(): void
    {
        @unlink(self::chip_cache_file());
    }

    /**
     * Runs the configured `at` with flags only. proc_open() warns when the binary is missing, and the
     * host agent's stdout carries the JSON reply, so the warning is swallowed and the -1 exit reported.
     *
     * @param list<string> $args
     * @return array{exit:int, stdout:string, stderr:string}
     */
    private static function at(array $args, ?string $stdin = null): array
    {
        set_error_handler(static fn (): bool => true);

        try {
            return ProcessRunner::run_process([Config::sessioneer_config('AT_BIN', 'at'), ...$args], $stdin);
        } finally {
            restore_error_handler();
        }
    }

    /** @return array{ok:bool, message?:string, rows?:list<array{job:int, at:int, queue:string}>} */
    private static function read_queue(): array
    {
        $result = self::at(['-l']);

        if ($result['exit'] === -1) {
            return self::unavailable();
        }

        if ($result['exit'] !== 0) {
            return ['ok' => false, 'message' => 'Could not read the at queue: ' . self::first_line($result['stderr'])];
        }

        return ['ok' => true, 'rows' => self::parse_queue($result['stdout'], self::system_timezone())];
    }

    /**
     * Re-validates one job fresh: it must still exist, be a wake job, and be in the queue.
     *
     * @return array{ok:bool, message?:string, gone?:bool, wake?:array<string,mixed>}
     */
    private static function find(int $job): array
    {
        if ($job < 1) {
            return ['ok' => false, 'message' => 'Needs an at job number'];
        }

        $gone = ['ok' => false, 'gone' => true, 'message' => "Wake-up {$job} no longer exists: it already ran or was cancelled"];
        $body = self::at(['-c', (string)$job]);

        if ($body['exit'] === -1) {
            return self::unavailable();
        }

        if ($body['exit'] !== 0) {
            self::forget_chip_cache();

            return $gone;
        }

        $parsed = self::parse_job_script($body['stdout'], self::bin_path());

        if ($parsed === null) {
            return ['ok' => false, 'message' => "Job {$job} is not a Sessioneer wake-up"];
        }

        $queue = self::read_queue();

        if (!isset($queue['rows'])) {
            return $queue;
        }

        foreach ($queue['rows'] as $row) {
            if ($row['job'] === $job) {
                return ['ok' => true, 'wake' => self::wake_from($row, $parsed, time(), true)];
            }
        }

        self::forget_chip_cache();

        return $gone;
    }

    /**
     * @param array{job:int, at:int, queue:string} $row
     * @param array{agent:string, agent_session_id:string, workdir:?string, profile:?string, prompt:string} $parsed
     * @return array<string, mixed>
     */
    private static function wake_from(array $row, array $parsed, int $now, bool $includePrompt): array
    {
        $prompt = $parsed['prompt'];
        $preview = trim((string)preg_replace('/\s+/u', ' ', $prompt));

        if (mb_strlen($preview) > self::PREVIEW_CHARS) {
            $preview = mb_substr($preview, 0, self::PREVIEW_CHARS) . '…';
        }

        $state = $row['queue'] === '=' ? 'running' : ($row['at'] < $now - self::OVERDUE_AFTER_SECONDS ? 'overdue' : 'scheduled');

        return [
            'job' => $row['job'],
            'at' => $row['at'],
            'at_local' => (new \DateTimeImmutable('@' . $row['at']))->setTimezone(new \DateTimeZone(self::system_timezone()))->format('Y-m-d H:i T'),
            'state' => $state,
            'agent' => $parsed['agent'],
            'agent_session_id' => $parsed['agent_session_id'],
            'workdir' => $parsed['workdir'],
            'profile' => $parsed['profile'],
            'session' => SidecarStore::find_by_agent_session_id($parsed['agent_session_id']),
            'prompt_preview' => $preview,
            'prompt_length' => mb_strlen($prompt),
        ] + ($includePrompt ? ['prompt' => $prompt] : []);
    }

    /** The decoded word at `$pos` (which moves past it), or null when none starts there or it is not UTF-8. */
    private static function read_quoted(string $text, int &$pos): ?string
    {
        if (preg_match(self::QUOTED, $text, $m, 0, $pos) !== 1) {
            return null;
        }

        $pos += strlen($m[0]);
        $word = str_replace("'\\''", "'", $m[1]);

        return mb_check_encoding($word, 'UTF-8') ? $word : null;
    }

    /** @return array{ok:false, message:string} */
    private static function unavailable(): array
    {
        return ['ok' => false, 'message' => 'The at command is not available (AT_BIN=' . Config::sessioneer_config('AT_BIN', 'at') . ')'];
    }

    private static function first_line(string $stderr): string
    {
        $line = strtok(trim($stderr), "\n");

        return $line === false ? 'at failed' : $line;
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
        // The per-user runtime directory, never a shared /tmp path another user could pre-create as a symlink.
        $logDir = Config::cache_dir();

        if (!is_dir($logDir)) {
            @mkdir($logDir, 0700, true);
        }

        $log = $logDir . '/wake.log';

        return 'env -i ' . implode(' ', array_map('escapeshellarg', $env)) . ' '
            . escapeshellarg(self::bin_path()) . ' '
            . implode(' ', array_map('escapeshellarg', $args))
            . ' >> ' . escapeshellarg($log) . ' 2>&1';
    }
}
