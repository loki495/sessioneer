<?php

declare(strict_types=1);

namespace HostAgent\SessionFlow;

use HostAgent\Launch\SessionControl;
use HostAgent\Services\Config;
use HostAgent\Services\QuotaService;
use HostAgent\Services\SessionWakeService;
use HostAgent\Stores\SidecarStore;

/**
 * The flow_resets and flow_schedule host actions: list each agent profile's stored quota reset time, and on
 * explicit approval queue one `at` job that fires a minute after it, either waking an existing conversation or
 * starting a fresh session from a Dibs handoff or plan. Nothing here is called by a decision; a job exists only
 * after a request that carries `approved: true`.
 */
final class ResetSchedule
{
    private const DELAY_SECONDS = 60;
    // Rolling-window agents (codex, opencode) report a reset that moves a second per second, so equality never holds.
    private const RESET_TOLERANCE_SECONDS = 120;
    private const WAKE_PROMPT = 'The quota window has reset. Continue where you left off.';

    /**
     * Read-only. `reset_at` is null when a profile has no stored reset still ahead.
     *
     * @return array{ok:true, agents:array<string, array{label:string, profiles:array<string, array{reset_at:?int}>}>}
     */
    public static function resets(?int $now = null): array
    {
        $now ??= time();
        $agents = [];

        foreach (self::profiles(QuotaService::get_quota()) as $agent => $entry) {
            $profiles = [];

            foreach ($entry['profiles'] as $profile => $quota) {
                $profiles[$profile] = ['reset_at' => self::stored_reset($quota, $now)['reset_at']];
            }

            $agents[$agent] = ['label' => $entry['label'], 'profiles' => $profiles];
        }

        return ['ok' => true, 'agents' => $agents];
    }

    /**
     * @param array<string, mixed> $request
     * @return array{ok:bool, message?:string, job?:int, at?:int, existing?:bool}
     */
    public static function schedule(array $request, ?int $now = null): array
    {
        $now ??= time();

        if (($request['approved'] ?? null) !== true) {
            return self::refuse('Scheduling needs explicit approval (approved must be true); nothing was queued');
        }

        $agent = is_string($request['agent'] ?? null) ? $request['agent'] : '';
        $profile = is_string($request['profile'] ?? null) ? $request['profile'] : '';
        $known = self::profiles(QuotaService::get_quota());

        if (!isset($known[$agent])) {
            return self::refuse('Unknown agent ' . self::shown($request['agent'] ?? null));
        }

        if (!array_key_exists($profile, $known[$agent]['profiles'])) {
            return self::refuse('Unknown profile ' . self::shown($request['profile'] ?? null) . " for {$agent} (known: " . implode(', ', array_keys($known[$agent]['profiles'])) . ')');
        }

        $stored = self::stored_reset($known[$agent]['profiles'][$profile], $now);

        if ($stored['reset_at'] === null) {
            return self::refuse($stored['past']
                ? "The stored quota reset time for {$agent}/{$profile} has already passed"
                : "No quota reset time is stored for {$agent}/{$profile}");
        }

        if (!is_int($request['expected_reset_at'] ?? null)) {
            return self::refuse('expected_reset_at is required: the reset time you were shown, as a unix time');
        }

        if (abs($request['expected_reset_at'] - $stored['reset_at']) > self::RESET_TOLERANCE_SECONDS) {
            return self::refuse("The reset time for {$agent}/{$profile} changed by more than " . self::RESET_TOLERANCE_SECONDS . " seconds since it was shown (stale expected_reset_at); read it again");
        }

        $context = self::context($request);

        if (isset($context['message'])) {
            return self::refuse($context['message']);
        }

        // The next whole minute at or after the reset plus a minute, so the window has certainly rolled over.
        $at = intdiv($stored['reset_at'] + self::DELAY_SECONDS + 59, 60) * 60;
        $health = SessionWakeService::atd_health();

        if (!$health['ok']) {
            return self::refuse($health['message']);
        }

        $own = $context['kind'] === 'session' ? self::session_account($context['name']) : null;

        if ($own !== null && $own !== [$agent, $profile]) {
            return self::refuse("Session {$context['name']} belongs to {$own[0]}:{$own[1]}, not {$agent}:{$profile} (agent or profile mismatch); ask for its own reset time");
        }

        // The find and the create are one step, or two identical taps could both miss and queue two jobs.
        return self::locked(fn (): array => $context['kind'] === 'session'
            ? self::schedule_wake($context['name'], $at, $now)
            : self::schedule_start($agent, $profile, $context['prompt'], is_string($request['workdir'] ?? null) ? $request['workdir'] : '', $at, $now));
    }

    /**
     * The agent and profile a tracked session runs under, in the names flow_resets uses: Claude's default
     * account is `personal`, every other agent has the single profile `default`.
     *
     * @return array{0:string, 1:string}
     */
    private static function session_account(string $name): array
    {
        $sidecar = SidecarStore::read_sidecar($name) ?? [];
        $agent = is_string($sidecar['agent'] ?? null) && $sidecar['agent'] !== '' ? $sidecar['agent'] : 'claude';
        $profile = is_string($sidecar['profile'] ?? null) && $sidecar['profile'] !== '' ? $sidecar['profile'] : 'personal';

        return [$agent, $agent === 'claude' ? $profile : 'default'];
    }

    /**
     * Runs `$step` under an exclusive lock on a file in the cache directory.
     *
     * @param callable(): array{ok:bool, message?:string, job?:int, at?:int, existing?:bool} $step
     * @return array{ok:bool, message?:string, job?:int, at?:int, existing?:bool}
     */
    private static function locked(callable $step): array
    {
        $dir = Config::cache_dir();
        is_dir($dir) || @mkdir($dir, 0700, true);
        $handle = @fopen($dir . '/reset-schedule.lock', 'c');

        if ($handle === false || !flock($handle, LOCK_EX)) {
            return self::refuse('Could not take the scheduling lock in ' . $dir);
        }

        try {
            return $step();
        } finally {
            flock($handle, LOCK_UN);
            fclose($handle);
        }
    }

    /**
     * Each agent's profiles with the quota it last stored. Claude has one entry per account; every other
     * agent has the single profile `default`.
     *
     * @param array<string, mixed> $usage QuotaService::get_quota()
     * @return array<string, array{label:string, profiles:array<string, array<string, mixed>|null>}>
     */
    private static function profiles(array $usage): array
    {
        $agents = [];

        foreach ((array)($usage['agents'] ?? []) as $agent => $reading) {
            $profiles = $agent === 'claude'
                ? array_map(static fn (array $p): ?array => is_array($p['quota'] ?? null) ? $p['quota'] : null, (array)($usage['claude_profiles'] ?? []))
                : ['default' => is_array($reading['quota'] ?? null) ? $reading['quota'] : null];
            $agents[(string)$agent] = ['label' => (string)($reading['label'] ?? $agent), 'profiles' => $profiles];
        }

        return $agents;
    }

    /**
     * The latest reset among the buckets at 100% or more, else the soonest future one. Buckets whose reset has
     * passed never count (a stale full bucket has already rolled over); `past` tells "all passed" from "none".
     *
     * @param array<string, mixed>|null $quota
     * @return array{reset_at:?int, past:bool}
     */
    private static function stored_reset(?array $quota, int $now): array
    {
        $buckets = array_filter((array)$quota, static fn (mixed $b): bool => is_array($b) && is_int($b['resets_at'] ?? null));
        $future = array_filter($buckets, static fn (array $b): bool => $b['resets_at'] > $now);
        $full = array_filter($future, static fn (array $b): bool => (int)($b['pct'] ?? 0) >= 100);

        if ($full !== []) {
            return ['reset_at' => max(array_column($full, 'resets_at')), 'past' => false];
        }

        return $future !== []
            ? ['reset_at' => min(array_column($future, 'resets_at')), 'past' => false]
            : ['reset_at' => null, 'past' => $buckets !== []];
    }

    /**
     * Exactly one of session, handoff_id or plan_id, validated.
     *
     * @param array<string, mixed> $request
     * @return array{kind:string, name?:string, prompt?:string, message?:string}
     */
    private static function context(array $request): array
    {
        $given = array_keys(array_filter(
            array_intersect_key($request, array_flip(['session', 'handoff_id', 'plan_id'])),
            static fn (mixed $v): bool => $v !== null
        ));

        if (count($given) !== 1) {
            return ['kind' => '', 'message' => $given === []
                ? 'Give exactly one of session, handoff_id or plan_id (none was given)'
                : 'Give exactly one of session, handoff_id or plan_id (got ' . implode(', ', $given) . ')'];
        }

        $key = $given[0];
        $value = $request[$key];

        if ($key === 'session') {
            if (!is_string($value) || trim($value) === '') {
                return ['kind' => '', 'message' => 'session must be a session name or conversation id'];
            }

            if (isset($request['workdir'])) {
                return ['kind' => '', 'message' => 'workdir only applies to a fresh start (handoff_id or plan_id)'];
            }

            $name = SessionControl::resolve($value);

            if ($name === null) {
                return ['kind' => '', 'message' => "Session {$value} not found"];
            }

            $conversation = SidecarStore::read_sidecar($name)['agent_session_id'] ?? null;

            return is_string($conversation) && $conversation !== ''
                ? ['kind' => 'session', 'name' => $name]
                : ['kind' => '', 'message' => "Session {$name} has no conversation yet, so there is nothing to wake"];
        }

        if (!is_string($value) || preg_match('/^[0-9]+$/D', $value) !== 1) {
            return ['kind' => '', 'message' => "{$key} must be digits only (got " . self::shown($value) . ')'];
        }

        if (isset($request['workdir']) && (!is_string($request['workdir']) || !str_starts_with($request['workdir'], '/') || str_contains($request['workdir'], "\0"))) {
            return ['kind' => '', 'message' => 'workdir must be an absolute path'];
        }

        return ['kind' => $key, 'prompt' => $key === 'handoff_id' ? PromptBuilder::handoff($value) : PromptBuilder::plan($value)];
    }

    /** @return array{ok:bool, message?:string, job?:int, at?:int, existing?:bool} */
    private static function schedule_wake(string $name, int $at, int $now): array
    {
        $sidecar = SidecarStore::read_sidecar($name) ?? [];
        $conversation = (string)($sidecar['agent_session_id'] ?? '');
        $pending = SessionWakeService::find_pending(['agent_session_id' => $conversation, 'prompt' => self::WAKE_PROMPT], $at);

        if (!$pending['ok']) {
            return self::refuse((string)($pending['message'] ?? 'The at queue could not be read'));
        }

        if (isset($pending['job'])) {
            return ['ok' => true, 'job' => $pending['job'], 'at' => $at, 'existing' => true];
        }

        return self::created(SessionWakeService::schedule($name, $at, self::WAKE_PROMPT, $now), $at);
    }

    /** @return array{ok:bool, message?:string, job?:int, at?:int, existing?:bool} */
    private static function schedule_start(string $agent, string $profile, string $prompt, string $workdir, int $at, int $now): array
    {
        $expect = ['agent' => $agent, 'agent_session_id' => '', 'workdir' => $workdir !== '' ? $workdir : null, 'profile' => $agent === 'claude' ? $profile : null, 'prompt' => $prompt];
        $pending = SessionWakeService::find_pending($expect, $at);

        if (!$pending['ok']) {
            return self::refuse((string)($pending['message'] ?? 'The at queue could not be read'));
        }

        if (isset($pending['job'])) {
            return ['ok' => true, 'job' => $pending['job'], 'at' => $at, 'existing' => true];
        }

        return self::created(SessionWakeService::schedule_start($agent, $profile, $workdir, $prompt, $at, $now), $at);
    }

    /**
     * @param array{ok:bool, message:string, job?:int, at?:int} $result
     * @return array{ok:bool, message?:string, job?:int, at?:int, existing?:bool}
     */
    private static function created(array $result, int $at): array
    {
        return $result['ok'] && isset($result['job'])
            ? ['ok' => true, 'job' => $result['job'], 'at' => $at, 'existing' => false, 'message' => $result['message']]
            : self::refuse($result['message']);
    }

    /** @return array{ok:false, message:string} */
    private static function refuse(string $message): array
    {
        return ['ok' => false, 'message' => $message];
    }

    private static function shown(mixed $value): string
    {
        return "'" . (is_scalar($value) ? mb_substr((string)$value, 0, 60) : gettype($value)) . "'";
    }
}
