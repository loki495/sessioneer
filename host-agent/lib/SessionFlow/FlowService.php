<?php

declare(strict_types=1);

namespace HostAgent\SessionFlow;

use HostAgent\Launch\LaunchService;
use HostAgent\Services\Config;
use HostAgent\Services\ProcessRunner;
use HostAgent\Services\QuotaService;
use HostAgent\Services\TranscriptRouter;
use HostAgent\Stores\SessionOfferStore;
use HostAgent\Stores\SessionStatusStore;
use HostAgent\Stores\SidecarStore;

/**
 * The flow_next, flow_offer, flow_offer_dismiss, flow_rotate, flow_rotate_request and flow_judge
 * host actions and the list overlay.
 * $deps: clock (): int, facts (session, row): ?array, quota (key): ?array, receivers
 * (list of "agent/profile"), thresholds (Thresholds), sessions (): list<string> for next in all mode; rotate and rotate_request also launch
 * (request): array, rotate runner (command, conversation id): array{exit, stdout}; judge also
 * judge_runner (candidate, prompt): ?string and an optional transcript (session): ?string.
 * live() builds the real ones.
 */
final class FlowService
{
    /**
     * All mode ({all: true}, no session) decides every tracked session (deps 'sessions') and posts nothing.
     *
     * @param array<string, mixed> $request session, optional intent, handoff_id, plan_id, post; or all
     * @param array<string, mixed> $deps
     * @return array<string, mixed>
     */
    public static function next(array $request, array $deps): array
    {
        if (!empty($request['all'])) {
            if (isset($request['session'])) {
                return ['ok' => false, 'message' => '--all cannot be combined with a session'];
            }
            $rows = [];
            foreach ($deps['sessions']() as $name) {
                $d = self::next(['session' => $name, 'intent' => $request['intent'] ?? null], $deps);
                $rows[] = ['name' => $name, 'state' => $d['state'], 'why' => $d['why'], 'receiver' => $d['receiver'] ?? null, 'prompt_kind' => $d['prompt_kind'] ?? null];
            }

            return ['ok' => true, 'sessions' => $rows];
        }

        $session = is_string($request['session'] ?? null) ? $request['session'] : '';
        $now = $deps['clock']();
        try {
            [$prompt, $kind] = self::prompt($request);
        } catch (\InvalidArgumentException $e) {
            return ['ok' => false, 'message' => $e->getMessage()];
        }

        $intent = is_string($request['intent'] ?? null) ? $request['intent'] : null;
        $facts = $session === '' ? null : $deps['facts']($session, []);
        $decision = self::decide($facts, $intent, $deps, $now);
        $reply = ['ok' => true] + $decision + [
            'source' => $facts['source'] ?? null,
            'facts' => ['context_tokens' => $facts['context_tokens'] ?? null, 'idle_seconds' => $facts['idle_seconds'] ?? null],
        ];
        if ($prompt !== null) {
            $reply += ['prompt' => $prompt, 'prompt_kind' => $kind];
        }

        if (!empty($request['post']) && $decision['state'] !== 'UNKNOWN') {
            if (in_array($decision['state'], ['ROTATE', 'PARK'], true)) {
                SessionOfferStore::post($session, 'hook', $decision, self::id($request, 'handoff_id'), self::id($request, 'plan_id'), $prompt, $now);
            } else {
                SessionOfferStore::clear($session, 'hook');
            }
        }

        return $reply;
    }

    /**
     * An agent's own offer for itself; `session` in the request is ignored.
     *
     * @param array<string, mixed> $request self, handoff_id, plan_id, prompt, decision
     * @param array<string, mixed> $deps
     * @return array<string, mixed>
     */
    public static function offer(array $request, array $deps): array
    {
        $self = is_string($request['self'] ?? null) ? $request['self'] : '';
        if ($self === '') {
            return ['ok' => false, 'message' => 'offer needs self, the calling session'];
        }
        $now = $deps['clock']();
        $facts = $deps['facts']($self, []);
        if ($facts === null) {
            return ['ok' => false, 'message' => "Unknown session {$self}"];
        }

        try {
            $prompt = is_string($request['prompt'] ?? null) ? $request['prompt'] : self::prompt($request)[0];
            $decision = is_array($request['decision'] ?? null) ? $request['decision'] : self::decide($facts, null, $deps, $now);
            SessionOfferStore::post($self, 'agent', $decision, self::id($request, 'handoff_id'), self::id($request, 'plan_id'), $prompt, $now);
        } catch (\InvalidArgumentException $e) {
            return ['ok' => false, 'message' => $e->getMessage()];
        }

        return ['ok' => true, 'offer' => SessionOfferStore::get($self, $now)];
    }

    /**
     * @param array<string, mixed> $request self
     * @param array<string, mixed> $deps
     * @return array<string, mixed>
     */
    public static function offer_dismiss(array $request, array $deps): array
    {
        $self = is_string($request['self'] ?? null) ? $request['self'] : '';

        return $self !== '' && SessionOfferStore::dismiss($self, $deps['clock']())
            ? ['ok' => true]
            : ['ok' => false, 'message' => 'No live offer to dismiss'];
    }

    /**
     * Start a fresh receiver session for a source session. Never archives or kills the source:
     * the receiver does that once it holds the handoff.
     *
     * @param array<string, mixed> $request session; one of handoff_id, plan_id, prompt; optional agent,
     *     profile, model, effort, workdir, set_name, dry_run
     * @param array<string, mixed> $deps
     * @return array<string, mixed>
     */
    public static function rotate(array $request, array $deps): array
    {
        $session = is_string($request['session'] ?? null) ? $request['session'] : '';
        $dryRun = !empty($request['dry_run']);
        $known = self::source($session, $deps);
        if (isset($known['ok'])) {
            return $known;
        }
        $old = $known[1];

        try {
            $prompt = self::rotatePrompt($request);
            $launch = self::receiverRequest($request, $old, $prompt, (string) (SessionStatusStore::read_status($session)['mode'] ?? ''));
        } catch (\InvalidArgumentException $e) {
            return ['ok' => false, 'message' => $e->getMessage()];
        }

        $command = Config::sessioneer_config('SESSIONEER_CLAIMS_CMD', '');
        $warnings = $command === '' ? ['SESSIONEER_CLAIMS_CMD is not set, so the source session was not checked for Dibs claims'] : [];
        $reply = ['ok' => true, 'session' => null, 'source' => $session, 'warnings' => $warnings, 'prompt' => $prompt];
        if ($dryRun) {
            return ['dry_run' => true, 'launch' => $launch] + $reply;
        }

        $lock = self::lock($session);
        if ($lock === null) {
            return ['ok' => false, 'message' => "A rotate of {$session} is already running"];
        }
        try {
            $held = $command === '' ? null : self::claimsHeld($command, (string) ($old['agent_session_id'] ?? ''), $deps);
            if ($held !== null) {
                return ['ok' => false, 'message' => $held];
            }
            $stale = self::claimOffer($session, $deps['clock']());
            if ($stale !== null) {
                return ['ok' => false, 'message' => $stale];
            }

            $started = $deps['launch']($launch);
            $name = $started['name'] ?? null;
            if (($started['ok'] ?? false) !== true || !is_string($name)) {
                return ['ok' => false, 'message' => 'The receiver did not start; the source session was left as it is: ' . (string) ($started['message'] ?? '')];
            }
            SidecarStore::set_rotation($name, $session, null);
            SidecarStore::set_rotation($session, null, $name);
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }

        return ['session' => $name] + $reply;
    }

    /**
     * The offer tap with no handoff: one "/rotate ..." turn sent to the idle source, which writes
     * its own handoff and rotates itself.
     *
     * @param array<string, mixed> $request session; optional agent, model, effort, profile
     * @param array<string, mixed> $deps
     * @return array<string, mixed>
     */
    public static function rotate_request(array $request, array $deps): array
    {
        $session = is_string($request['session'] ?? null) ? $request['session'] : '';
        $known = self::source($session, $deps);
        if (isset($known['ok'])) {
            return $known;
        }
        [$facts, $old] = $known;

        $agent = (string) ($old['agent'] ?? 'claude');
        $id = (string) ($old['agent_session_id'] ?? '');
        if (($facts['source']['status'] ?? null) !== 'idle' || $id === '') {
            return ['ok' => false, 'message' => "Session {$session} must be idle and have a conversation id to receive /rotate"];
        }
        try {
            $text = PromptBuilder::rotateRequest(
                self::text($request['agent'] ?? null) ?? $agent,
                self::text($request['model'] ?? null),
                self::text($request['effort'] ?? null),
                self::text($request['profile'] ?? null),
            );
        } catch (\InvalidArgumentException $e) {
            return ['ok' => false, 'message' => $e->getMessage()];
        }

        $stale = self::claimOffer($session, $deps['clock']());
        if ($stale !== null) {
            return ['ok' => false, 'message' => $stale];
        }
        $sent = $deps['launch'](array_filter([
            'agent' => $agent,
            'workdir' => $old['workdir'] ?? null,
            'profile' => $agent === 'claude' ? ($old['profile'] ?? 'personal') : null,
            'agent_session_id' => $id,
            'prompt' => $text,
        ]));

        return ($sent['ok'] ?? false) === true
            ? ['ok' => true, 'session' => $session, 'prompt' => $text]
            : ['ok' => false, 'message' => 'The /rotate turn was not sent: ' . (string) ($sent['message'] ?? '')];
    }

    /**
     * The one model call for a close call: the source account resets soon, wait for it or rotate now.
     *
     * @param array<string, mixed> $request session
     * @param array<string, mixed> $deps
     * @return array<string, mixed>
     */
    public static function judge(array $request, array $deps): array
    {
        $session = is_string($request['session'] ?? null) ? $request['session'] : '';
        $now = $deps['clock']();
        $facts = $session === '' ? null : $deps['facts']($session, []);
        if ($facts === null) {
            return ['ok' => false, 'message' => $session === '' ? 'judge needs a session' : "Unknown session {$session}"];
        }

        $decision = self::decide($facts, null, $deps, $now);
        $receiver = $decision['receiver'];
        if ($decision['state'] !== 'ROTATE' || $decision['trigger'] !== 'quota' || !$decision['close_call'] || $receiver === null || $decision['reset_at'] === null) {
            return ['ok' => false, 'message' => 'Not a close call: only a quota reset less than an hour away is judged by a model'];
        }
        $model = ['claude' => CloseCallJudge::CLAUDE_MODEL, 'codex' => CloseCallJudge::CODEX_MODEL][$receiver['agent']] ?? null;
        if ($model === null) {
            return ['ok' => false, 'message' => "No judge model for {$receiver['agent']}"];
        }

        $candidate = ['agent' => $receiver['agent'], 'profile' => $receiver['agent'] === 'claude' ? $receiver['profile'] : null, 'model' => $model];
        $path = isset($deps['transcript']) ? $deps['transcript']($session) : null;
        $advice = CloseCallJudge::resolve_quota(
            ['verdict' => 'fresh', 'quota_short_wait' => true, 'quota_reset_seconds' => max(0, $decision['reset_at'] - $now)],
            $path,
            $candidate,
            $deps['judge_runner'],
        );
        if (!isset($advice['decided_by'])) {
            return ['ok' => false, 'message' => 'The model gave no usable answer; decide from the numbers'];
        }

        return ['ok' => true] + $decision + [
            'verdict' => $advice['verdict'] === 'wait' ? 'wait' : 'rotate',
            'reason' => $advice['reason'],
            'decided_by' => $advice['decided_by'],
        ];
    }

    /**
     * Adds `offer` (live offer or null) to every row, and `flow` to an idle row that is cold or
     * has a large stored context. Rows that fail that cheap check cost no facts or quota read.
     *
     * @param list<array<string, mixed>> $rows
     * @param array<string, mixed> $deps
     * @return list<array<string, mixed>>
     */
    public static function overlay(array $rows, array $deps): array
    {
        $now = $deps['clock']();
        $t = $deps['thresholds'];
        $out = [];
        foreach ($rows as $row) {
            $name = is_string($row['name'] ?? null) ? $row['name'] : '';
            $row['offer'] = $name === '' ? null : SessionOfferStore::get($name, $now);
            $idle = is_int($row['activity'] ?? null) ? $now - $row['activity'] : null;
            $cold = $idle !== null && $idle >= $t->get('IDLE_MIN') * 60;
            $heavy = is_int($row['context_tokens'] ?? null) && $row['context_tokens'] >= $t->get('CTX_GO_MAX');
            if ($name !== '' && ($row['status'] ?? null) === 'idle' && ($cold || $heavy)) {
                $facts = $deps['facts']($name, $row);
                $flow = self::decide($facts, null, $deps, $now);
                if ($flow['state'] !== 'UNKNOWN') {
                    $row['flow'] = $flow;
                }
            }
            $out[] = $row;
        }

        return $out;
    }

    /**
     * The real dependencies: stored readings, the shared quota reader, thresholds from config.
     *
     * @return array<string, mixed>
     */
    public static function live(): array
    {
        $quota = null;
        $usage = static function () use (&$quota): array {
            return $quota ??= QuotaService::get_quota();
        };

        return [
            'clock' => static fn (): int => time(),
            'facts' => static fn (string $session, array $row): ?array => self::storedFacts($session, $row),
            'quota' => static fn (string $key): ?array => self::quotaReading($usage(), $key),
            'receivers' => self::receivers(),
            'thresholds' => Thresholds::fromFile(Config::session_flow_thresholds_path()),
            'sessions' => static fn (): array => array_column(SidecarStore::list_runtime_sidecars('headless'), 'session_name'),
            'launch' => [LaunchService::class, 'launch'],
            'runner' => static fn (string $command, string $conversationId): array => ProcessRunner::run_process(['sh', '-c', $command . ' "$1"', 'sh', $conversationId]),
            'judge_runner' => [CloseCallJudge::class, 'run_process_runner'],
            'transcript' => static fn (string $session): ?string => self::transcriptPath($session),
        ];
    }

    /**
     * @param array<string, mixed> $deps
     * @return array{0: array<string, mixed>, 1: array<string, mixed>}|array{ok: false, message: string} facts and sidecar of a tracked session
     */
    private static function source(string $session, array $deps): array
    {
        $facts = $session === '' ? null : $deps['facts']($session, []);
        $sidecar = $facts === null ? null : SidecarStore::read_sidecar($session);

        return $facts === null || $sidecar === null
            ? ['ok' => false, 'message' => $session === '' ? 'A session name is required' : "Unknown session {$session}"]
            : [$facts, $sidecar];
    }

    /** @param array<string, mixed> $request */
    private static function rotatePrompt(array $request): string
    {
        $given = array_filter(array_intersect_key($request, array_flip(['handoff_id', 'plan_id', 'prompt'])), static fn ($v): bool => $v !== null);
        if (count($given) !== 1) {
            throw new \InvalidArgumentException('Give exactly one of handoff_id, plan_id or prompt');
        }
        $value = reset($given);
        if (!is_string($value)) {
            throw new \InvalidArgumentException(key($given) . ' must be a string');
        }
        if (key($given) !== 'prompt' && preg_match('/^[0-9]+$/D', $value) !== 1) {
            throw new \InvalidArgumentException(key($given) . ' must be digits only');
        }

        return match (key($given)) {
            'handoff_id' => PromptBuilder::handoff($value),
            'plan_id' => PromptBuilder::plan($value),
            default => PromptBuilder::selfContained($value),
        };
    }

    /**
     * The launch request for the receiver, with the permission limits a replacement inherits from the source.
     *
     * @param array<string, mixed> $request
     * @param array<string, mixed> $old the source sidecar
     * @param string $mode the source's permission mode
     * @return array<string, mixed>
     */
    private static function receiverRequest(array $request, array $old, string $prompt, string $mode): array
    {
        $sourceAgent = (string) ($old['agent'] ?? 'claude');
        $agent = self::text($request['agent'] ?? null) ?? $sourceAgent;
        if (!in_array($agent, ['claude', 'codex'], true)) {
            throw new \InvalidArgumentException("Cannot start a {$agent} receiver; use claude or codex");
        }
        $same = $agent === $sourceAgent;
        $model = self::text($request['model'] ?? null);
        $effort = self::text($request['effort'] ?? null);
        if (!$same && ($model !== null || $effort !== null)) {
            throw new \InvalidArgumentException('A cross-agent rotate cannot reuse the source model or effort; omit them');
        }
        if (!empty($old['no_tools'])) {
            throw new \InvalidArgumentException('A tool-less source cannot be rotated: the receiver could not read the handoff or move claims');
        }
        if (!$same && (!empty($old['allowed_tools']) || !empty($old['disallowed_tools']))) {
            throw new \InvalidArgumentException('Tool rules cannot cross agents, so a source with tool rules can only rotate to the same agent');
        }

        $effort ??= $same ? ($old['effort'] ?? null) : null;
        $profile = self::text($request['profile'] ?? null) ?? ($same ? ($old['profile'] ?? null) : null) ?? 'personal';
        foreach (['model' => $model, 'effort' => $effort, 'profile' => $profile] as $name => $value) {
            if ($value !== null && preg_match('/^[A-Za-z0-9._:\[\]-]+$/D', (string) $value) !== 1) {
                throw new \InvalidArgumentException("{$name} has characters outside A-Z a-z 0-9 . _ : [ ] -");
            }
        }

        return array_filter([
            'agent' => $agent,
            'workdir' => self::text($request['workdir'] ?? null) ?? $old['workdir'] ?? null,
            'prompt' => $prompt,
            'profile' => $agent === 'claude' ? $profile : null,
            'model' => $model,
            'effort' => $effort,
            'set_name' => self::text($request['set_name'] ?? null),
            'starting_mode' => ReceiverPicker::startingMode($agent, $mode),
            'allowed_tools' => $same ? ($old['allowed_tools'] ?? null) : null,
            'disallowed_tools' => $same ? ($old['disallowed_tools'] ?? null) : null,
        ]);
    }

    private static function text(mixed $value): ?string
    {
        return is_string($value) && $value !== '' ? $value : null;
    }

    /**
     * Null when the source holds no claim. Fails closed: a failed command or unreadable output refuses.
     *
     * @param array<string, mixed> $deps
     */
    private static function claimsHeld(string $command, string $conversationId, array $deps): ?string
    {
        if ($conversationId === '') {
            return 'The source has no conversation id, so its Dibs claims cannot be checked';
        }
        $result = $deps['runner']($command, $conversationId);
        $claims = json_decode((string) ($result['stdout'] ?? ''), true);
        if (($result['exit'] ?? 1) !== 0 || !is_array($claims)) {
            return 'The claims check (SESSIONEER_CLAIMS_CMD) failed, so the source was not rotated';
        }
        $list = array_key_exists('claims', $claims) ? $claims['claims'] : $claims;

        return $list === [] || $list === null ? null : 'The source still holds Dibs claims; release or hand them off first';
    }

    /**
     * Consumes a live offer so a second tap cannot launch again. A stale offer (already launched,
     * dismissed or expired) refuses this tap and is cleared, so the next tap acts on fresh state.
     */
    private static function claimOffer(string $session, int $now): ?string
    {
        if (SessionOfferStore::claimLaunch($session, $now) || !SessionOfferStore::has($session)) {
            return null;
        }
        SessionOfferStore::clear($session);

        return 'This offer was already used, dismissed or expired; check the session and tap again';
    }

    /** @return resource|null null when another rotate of this source holds the lock */
    private static function lock(string $session)
    {
        $dir = Config::cache_dir();
        if (!is_dir($dir) && !mkdir($dir, 0700, true) && !is_dir($dir)) {
            return null;
        }
        $handle = fopen($dir . '/flow-rotate-' . sha1($session) . '.lock', 'c');
        if ($handle === false || !flock($handle, LOCK_EX | LOCK_NB)) {
            return null;
        }

        return $handle;
    }

    private static function transcriptPath(string $session): ?string
    {
        $sidecar = SidecarStore::read_sidecar($session);
        $id = (string) ($sidecar['agent_session_id'] ?? '');

        return ($sidecar['agent'] ?? 'claude') === 'claude' && $id !== '' ? TranscriptRouter::find_transcript_path($id, (string) ($sidecar['profile'] ?? 'personal')) : null;
    }

    /**
     * @param ?array<string, mixed> $facts
     * @param array<string, mixed> $deps
     * @return array<string, mixed>
     */
    private static function decide(?array $facts, ?string $intent, array $deps, int $now): array
    {
        $t = $deps['thresholds'];
        if ($facts === null) {
            return self::withWarnings(FlowDecider::decide([], [], $t, $intent, $now), $t);
        }

        $source = $facts['source']['agent'] . '/' . $facts['source']['profile'];
        $facts['quota'] = $deps['quota']($source);
        $decision = FlowDecider::decide($facts, [], $t, $intent, $now);
        if (in_array($decision['state'], ['ROTATE', 'PARK'], true)) {
            $map = [];
            foreach ($deps['receivers'] as $key) {
                $reading = $key === $source ? null : $deps['quota']($key);
                if ($reading !== null) {
                    $map[$key] = $reading;
                }
            }
            $decision = FlowDecider::decide($facts, $map, $t, $intent, $now);
        }

        return self::withWarnings($decision, $t);
    }

    /**
     * @param array<string, mixed> $decision
     * @return array<string, mixed>
     */
    private static function withWarnings(array $decision, Thresholds $t): array
    {
        $decision['warnings'] = array_merge($decision['warnings'], $t->warnings());

        return $decision;
    }

    /**
     * @param array<string, mixed> $request
     * @return array{0: ?string, 1: ?string} prompt and its kind
     */
    private static function prompt(array $request): array
    {
        $handoff = self::id($request, 'handoff_id');
        $plan = self::id($request, 'plan_id');
        if ($handoff !== null) {
            return [PromptBuilder::handoff($handoff), 'handoff'];
        }

        return $plan !== null ? [PromptBuilder::plan($plan), 'plan'] : [null, null];
    }

    /** @param array<string, mixed> $request */
    private static function id(array $request, string $key): ?string
    {
        $value = $request[$key] ?? null;
        if ($value !== null && !is_string($value)) {
            throw new \InvalidArgumentException("{$key} must be a string of digits");
        }

        return $value;
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>|null
     */
    private static function storedFacts(string $session, array $row): ?array
    {
        $sidecar = SidecarStore::read_sidecar($session);
        if ($sidecar === null) {
            return null;
        }
        $agent = (string) ($sidecar['agent'] ?? 'claude');
        $profile = $agent === 'claude' ? (string) ($sidecar['profile'] ?? 'personal') : 'default';
        $status = SessionStatusStore::read_status($session) ?? [];
        $now = time();
        $id = (string) ($sidecar['agent_session_id'] ?? '');
        $path = $agent === 'claude' && $id !== '' ? TranscriptRouter::find_transcript_path($id, $profile) : null;
        $stored = FlowFacts::stored($status['context_usage'] ?? null, $status['token_usage'] ?? null, $agent, (string) ($status['model'] ?? ''), (int) ($status['updated_at'] ?? $now), $path);
        $activity = is_int($row['activity'] ?? null) ? $row['activity'] : ($stored['last_activity'] ?? null);

        return [
            'context_tokens' => $stored['context_tokens'] ?? null,
            'idle_seconds' => $activity === null ? null : max(0, $now - $activity),
            'source' => ['agent' => $agent, 'profile' => $profile, 'status' => (string) ($status['status'] ?? 'idle')],
        ];
    }

    /**
     * @param array<string, mixed> $usage QuotaService::get_quota()
     * @return array{session_pct: ?int, week_pct: ?int, resets_at: ?int}|null
     */
    private static function quotaReading(array $usage, string $key): ?array
    {
        [$agent, $profile] = explode('/', $key, 2) + [1 => ''];
        $quota = $agent === 'claude' ? ($usage['claude_profiles'][$profile]['quota'] ?? null) : ($usage['agents'][$agent]['quota'] ?? null);
        if (!is_array($quota)) {
            return null;
        }
        $session = $quota['session'] ?? null;
        $week = $quota['week_all'] ?? null;
        $worst = ($week['pct'] ?? -1) > ($session['pct'] ?? -1) ? $week : $session;
        $pct = static fn ($w): ?int => is_array($w) && is_int($w['pct'] ?? null) ? $w['pct'] : null;

        return [
            'session_pct' => $pct($session),
            'week_pct' => $pct($week),
            'resets_at' => is_int($worst['resets_at'] ?? null) ? $worst['resets_at'] : null,
        ];
    }

    /**
     * Accounts a takeover can start headless (Antigravity cannot), Claude profiles last.
     *
     * @return list<string>
     */
    private static function receivers(): array
    {
        $keys = [];
        if (Config::codex_bin() !== '') {
            $keys[] = 'codex/default';
        }
        if (Config::opencode_bin() !== '') {
            $keys[] = 'opencode/default';
        }
        foreach (Config::claude_profiles_to_scan() as $profile) {
            if (Config::claude_bin($profile) !== '') {
                $keys[] = 'claude/' . ($profile ?? 'personal');
            }
        }

        return $keys;
    }
}
