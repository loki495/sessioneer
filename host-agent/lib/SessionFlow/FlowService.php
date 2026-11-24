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
    private const QUOTA_CACHE_TTL = 30;

    /**
     * @param array<string, mixed> $request
     * @param array<string, mixed> $deps
     * @return array<string, mixed>
     */
    public static function continuation(array $request, array $deps): array
    {
        $session = $request['session'] ?? null;
        if (!is_string($session) || trim($session) === '') {
            return ['ok' => false, 'message' => 'A session name is required'];
        }
        return self::continuationFacts($session, $deps)['reply'];
    }

    /**
     * @param array<string, mixed> $request
     * @param array<string, mixed> $deps
     * @return array<string, mixed>
     */
    public static function continue_rotation(array $request, array $deps): array
    {
        $session = $request['session'] ?? null;
        if (!is_string($session) || trim($session) === '') {
            return ['ok' => false, 'message' => 'A session name is required'];
        }
        $facts = self::continuationFacts($session, $deps);
        if (!$facts['live'] || $facts['status'] !== 'idle') {
            return ['ok' => false, 'message' => 'The source session must be live and idle to rotate.'];
        }
        $params = ['session' => $session];
        foreach (['agent', 'profile'] as $key) {
            if (isset($request[$key])) { $params[$key] = $request[$key]; }
        }
        if ($facts['reply']['can_rotate']) {
            if ($facts['reply']['handoff_id'] !== null) { $params['handoff_id'] = $facts['reply']['handoff_id']; }
            else { $params['plan_id'] = $facts['reply']['plan_id']; }
            return self::rotate($params, $deps, [$facts['reply']['handoff_id'], $facts['reply']['plan_id']]);
        }
        return self::rotate_request($params, $deps, true);
    }

    /**
     * @param array<string, mixed> $deps
     * @return array<string, mixed>
     */
    private static function continuationFacts(string $session, array $deps): array
    {
        $detail = $deps['detail']($session);
        $live = ($detail['ok'] ?? false) === true;
        $status = is_string($detail['status'] ?? null) ? $detail['status'] : (!empty($detail['blocked_reason']) || !empty($detail['prompt_context']) ? 'blocked' : (($detail['working'] ?? false) === true ? 'working' : 'idle'));
        $now = $deps['clock']();
        $offer = SessionOfferStore::get($session, $now);
        [$handoff, $plan] = FlowDecider::continuationIds($offer, $now);
        $claims = null;
        $sidecar = SidecarStore::read_sidecar($session);
        $id = $sidecar['agent_session_id'] ?? null;
        $command = Config::sessioneer_config('SESSIONEER_CLAIMS_CMD', '');
        if ($live && $command !== '' && is_string($id) && $id !== '') {
            $result = $deps['runner']($command, $id);
            $decoded = json_decode((string) ($result['stdout'] ?? ''));
            $list = is_object($decoded) ? ($decoded->claims ?? null) : $decoded;
            if (($result['exit'] ?? 1) === 0 && is_array($list)) {
                $claims = [];
                foreach ($list as $claim) {
                    if (!is_object($claim)) { $claims = null; break; }
                    $claims[] = (array) $claim;
                }
            }
        }
        $policy = FlowDecider::continuation(['live' => $live, 'status' => $status], $offer, $claims, $now);
        return ['live' => $live, 'status' => $status, 'reply' => ['ok' => true] + $policy + ['handoff_id' => $handoff, 'plan_id' => $plan]];
    }

    /**
     * All mode ({all: true}, no session) decides every tracked session (deps 'sessions') and posts nothing.
     *
     * @param array<string, mixed> $request session, optional intent, handoff_id, plan_id, post, no_gates; or all
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
        $decision = self::decide($facts, $intent, $deps, $now, $session);
        $reply = ['ok' => true] + $decision + [
            'source' => $facts['source'] ?? null,
            'facts' => ['context_tokens' => $facts['context_tokens'] ?? null, 'idle_seconds' => $facts['idle_seconds'] ?? null],
        ];
        if ($prompt !== null) {
            $reply += ['prompt' => $prompt, 'prompt_kind' => $kind];
        }

        $planId = self::id($request, 'plan_id');
        $reply += $planId !== null && self::id($request, 'handoff_id') === null
            ? self::autoContinueSetting($planId, $deps)
            : ['auto_continue' => null];
        $receiver = $decision['receiver'] ?? null;
        $startable = ($request['post'] ?? null) === true && ($request['no_gates'] ?? null) === true && ($reply['auto_continue'] ?? null) === true
            && $decision['state'] === 'ROTATE' && is_array($receiver) && is_string($receiver['key'] ?? null);
        if ($startable && Config::sessioneer_config('SESSIONEER_CLAIMS_CMD', '') === '') {
            $startable = false;
            $reply['auto_continue_note'] = 'The claims check (SESSIONEER_CLAIMS_CMD) is not configured, so the receiver was not started';
        }
        if ($startable) {
            $already = (string) (SidecarStore::read_sidecar($session)['rotated_to'] ?? '');
            if ($already !== '') {
                return ['auto_continued' => false, 'auto_continue_note' => "Already rotated to {$already}; no second receiver was started"] + $reply;
            }
            [$agent, $profile] = explode('/', $receiver['key'], 2) + [1 => ''];
            try {
                $started = self::rotate(['session' => $session, 'plan_id' => $planId, 'agent' => $agent, 'profile' => $profile], $deps);
            } catch (\Throwable $e) {
                $started = ['ok' => false, 'message' => get_class($e) . ': ' . $e->getMessage()];
            }
            if (($started['ok'] ?? false) === true && is_string($started['session'] ?? null)) {
                return ['auto_continued' => true, 'session' => $started['session']] + $reply;
            }
            $reply['auto_continue_note'] = (string) ($started['message'] ?? 'The receiver did not start');
        }
        $reply['auto_continued'] = false;

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
     * Reads the plan's `Auto-continue: on` line through SESSIONEER_PLAN_CMD; null (with a note) when it cannot be read.
     *
     * @param array<string, mixed> $deps
     * @return array<string, mixed>
     */
    private static function autoContinueSetting(string $planId, array $deps): array
    {
        $command = Config::sessioneer_config('SESSIONEER_PLAN_CMD', '');
        if ($command === '') {
            return ['auto_continue' => null, 'auto_continue_note' => 'SESSIONEER_PLAN_CMD is not set, so the plan was not read'];
        }
        $result = $deps['runner']($command, $planId);
        $body = (string) ($result['stdout'] ?? '');
        if (($result['exit'] ?? 1) !== 0 || trim($body) === '') {
            return ['auto_continue' => null, 'auto_continue_note' => 'The plan command (SESSIONEER_PLAN_CMD) failed or printed nothing'];
        }

        $values = [];
        $fenced = false;
        foreach (preg_split('/\r?\n/', $body) ?: [] as $line) {
            if (str_starts_with(ltrim($line), '```')) {
                $fenced = !$fenced;
            } elseif (!$fenced && preg_match('/^\s*auto-continue\s*:(.*)$/i', $line, $m) === 1) {
                $values[] = strtolower(trim($m[1]));
            }
        }

        return ['auto_continue' => $values !== [] && array_unique($values) === ['on']];
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
            $decision = is_array($request['decision'] ?? null) ? $request['decision'] : self::decide($facts, null, $deps, $now, $self);
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
     * archive_replaced() does that once the receiver holds a claim.
     *
     * @param array<string, mixed> $request session; one of handoff_id, plan_id, prompt; optional agent,
     *     profile, model, effort, workdir, set_name, dry_run
     * @param array<string, mixed> $deps
     * @param ?array{?string, ?string} $expectedContext
     * @return array<string, mixed>
     */
    public static function rotate(array $request, array $deps, ?array $expectedContext = null): array
    {
        $session = is_string($request['session'] ?? null) ? $request['session'] : '';
        $dryRun = !empty($request['dry_run']);
        $known = self::source($session, $deps);
        if (isset($known['ok'])) {
            return $known;
        }
        $old = $known[1];

        try {
            $prompt = self::rotatePrompt($request, $session, $old);
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
        $reservation = null;
        $dispatched = false;
        try {
            $held = $command === '' ? null : self::claimsHeld($command, (string) ($old['agent_session_id'] ?? ''), $deps);
            if ($held !== null) {
                return ['ok' => false, 'message' => $held];
            }
            if ($expectedContext !== null) {
                $reservation = SessionOfferStore::reserveContext($session, $deps['clock'](), $expectedContext[0], $expectedContext[1]);
                $stale = $reservation === null ? 'The prepared offer changed or is no longer available; check the session and tap again' : null;
            } else {
                $stale = self::claimOffer($session, $deps['clock'](), $reservation);
            }
            if ($stale !== null) {
                return ['ok' => false, 'message' => $stale];
            }

            $started = $deps['launch']($launch);
            $name = $started['name'] ?? null;
            $dispatched = ($started['ok'] ?? false) === true || (is_string($name) && $name !== '');
            if (($started['ok'] ?? false) !== true || !is_string($name) || $name === '') {
                $detail = (string) ($started['message'] ?? '');
                $message = is_string($name) && $name !== ''
                    ? "Receiver {$name} exists but the launch reported an error: {$detail}"
                    : 'The receiver did not start; the source session was left as it is: ' . $detail;
                return ['ok' => false, 'message' => $message];
            }
            SidecarStore::set_rotation($name, $session, null);
            SidecarStore::set_rotation($session, null, $name);
        } finally {
            if (!$dispatched && $reservation !== null) {
                SessionOfferStore::rollback($session, $reservation, $deps['clock']());
            }
            flock($lock, LOCK_UN);
            fclose($lock);
        }

        return ['session' => $name] + $reply;
    }

    /**
     * Archives each rotated source once its receiver holds a claim and the source is idle and
     * claim-free, then closes the rotation. Every read is fresh; one candidate failing never stops
     * the others. A source is never force-stopped: a refusal leaves it waiting for the next pass.
     *
     * @param array<string, mixed> $deps clock, runner, kill
     * @return array<string, mixed> ok, results (source, receiver, outcome archived|waiting|closed|error, detail), skipped
     */
    public static function archive_replaced(array $deps): array
    {
        $lock = self::lockFile('flow-reap');
        if ($lock === null) {
            return ['ok' => true, 'results' => [], 'skipped' => true];
        }
        $results = [];
        try {
            foreach (SidecarStore::list_open_rotations() as $candidate) {
                $source = $candidate['session_name'];
                $receiver = $candidate['rotated_to'];
                try {
                    [$outcome, $detail] = self::reap($source, $receiver, $deps);
                } catch (\Throwable $e) {
                    [$outcome, $detail] = ['error', $e->getMessage()];
                }
                $results[] = ['source' => $source, 'receiver' => $receiver, 'outcome' => $outcome, 'detail' => $detail];
            }
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }

        return ['ok' => true, 'results' => $results];
    }

    /**
     * @param array<string, mixed> $deps
     * @return array{0: string, 1: string} outcome and detail
     */
    private static function reap(string $source, string $receiver, array $deps): array
    {
        $close = static function (string $outcome, string $detail) use ($source, $deps): array {
            SidecarStore::close_rotation($source, $deps['clock']());

            return [$outcome, $detail];
        };
        $old = SidecarStore::read_sidecar($source);
        if ($old === null) {
            return $close('closed', 'the source no longer exists');
        }
        if ($receiver === $source) {
            return $close('closed', 'the source is its own receiver');
        }
        $new = SidecarStore::read_sidecar($receiver);
        if ($new === null) {
            return $close('closed', 'the receiver no longer exists; the source was left as it is');
        }

        $command = Config::sessioneer_config('SESSIONEER_CLAIMS_CMD', '');
        if ($command === '') {
            return ['waiting', 'SESSIONEER_CLAIMS_CMD is not set'];
        }
        $receiverId = (string) ($new['agent_session_id'] ?? '');
        if ($receiverId === '' || (self::claimList($command, $receiverId, $deps, true) ?? []) === []) {
            return ['waiting', 'the receiver holds no claim yet'];
        }
        $sourceId = (string) ($old['agent_session_id'] ?? '');
        if (($old['agent_session_id'] ?? '') === '' || (SessionStatusStore::read_status($source)['status'] ?? null) !== 'idle') {
            return ['waiting', 'the source is not idle'];
        }
        if (self::claimList($command, $sourceId, $deps) !== []) {
            return ['waiting', 'the source still holds claims or they could not be read'];
        }

        $killed = $deps['kill'](['target' => $source, 'agent' => (string) ($old['agent'] ?? 'claude')]);
        if (($killed['ok'] ?? false) === true) {
            return $close('archived', (string) ($killed['message'] ?? ''));
        }
        $message = (string) ($killed['message'] ?? '');
        // Launcher replies for an untracked target read "No <agent> session found for ...".
        if (preg_match('/unknown|not found|\bno [a-z ]*session found for\b/i', $message) === 1) {
            return $close('closed', $message);
        }

        return ['waiting', $message !== '' ? $message : 'the stop was refused'];
    }

    /**
     * The offer tap with no handoff: one "/rotate ..." turn sent to the idle source, which writes
     * its own handoff and rotates itself.
     *
     * @param array<string, mixed> $request session; optional agent, model, effort, profile
     * @param array<string, mixed> $deps
     * @return array<string, mixed>
     */
    public static function rotate_request(array $request, array $deps, bool $clearInactive = false): array
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

        $lock = self::lock($session);
        if ($lock === null) {
            return ['ok' => false, 'message' => "A rotate of {$session} is already running"];
        }
        $reservation = null;
        $dispatched = false;
        try {
            if ($clearInactive) { SessionOfferStore::clearInactiveUnlaunched($session, $deps['clock']()); }
            $stale = self::claimOffer($session, $deps['clock'](), $reservation);
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

            $dispatched = ($sent['ok'] ?? false) === true;
            return $dispatched
                ? ['ok' => true, 'session' => $session, 'prompt' => $text]
                : ['ok' => false, 'message' => 'The /rotate turn was not sent: ' . (string) ($sent['message'] ?? '')];
        } finally {
            if (!$dispatched && $reservation !== null) {
                SessionOfferStore::rollback($session, $reservation, $deps['clock']());
            }
            flock($lock, LOCK_UN);
            fclose($lock);
        }
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

        $decision = self::decide($facts, null, $deps, $now, $session);
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
     * Adds `offer` (live offer or null) and `quota_exhausted` to every row, and `flow` to an idle row
     * that is cold or has a large stored context. Facts are read only for those idle rows; the quota
     * reader runs once per distinct account key (and is cached for QUOTA_CACHE_TTL by live()).
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
        $statuses = isset($deps['stored_statuses']) ? $deps['stored_statuses']() : null;
        $readings = [];
        foreach ($rows as $row) {
            $name = is_string($row['name'] ?? null) ? $row['name'] : '';
            if ($statuses !== null) {
                $status = $statuses[$name] ?? [];
                $agent = (string) ($row['agent'] ?? 'claude');
                $stored = FlowFacts::stored($status['context_usage'] ?? null, $status['token_usage'] ?? null, $agent, (string) ($status['model'] ?? ''), (int) ($status['updated_at'] ?? $now), null);
                $row['context_tokens'] = $stored['context_tokens'] ?? null;
                $row['_flow_status'] = $status;
            }
            $row['offer'] = $name === '' ? null : SessionOfferStore::get($name, $now);
            $idle = is_int($row['activity'] ?? null) ? $now - $row['activity'] : null;
            $cold = $idle !== null && $idle >= $t->get('IDLE_MIN') * 60;
            $heavy = is_int($row['context_tokens'] ?? null) && $row['context_tokens'] >= $t->get('CTX_GO_MAX');
            if ($name !== '' && ($row['status'] ?? null) === 'idle' && ($cold || $heavy)) {
                $facts = $deps['facts']($name, $row);
                $flow = self::decide($facts, null, $deps, $now, $name);
                if ($flow['state'] !== 'UNKNOWN') {
                    $row['flow'] = $flow;
                }
            }
            unset($row['_flow_status']);
            $row['quota_exhausted'] = self::quotaExhausted($row, $deps, $readings, $now);
            $out[] = $row;
        }

        return $out;
    }

    /**
     * Whether the row's own account has a bucket at 100 percent whose reset is unknown or still ahead; null when
     * its reading is missing, unparsable or the reader throws. One read per key per overlay call.
     *
     * @param array<string, mixed> $row
     * @param array<string, mixed> $deps
     * @param array<string, mixed> $readings per-call cache by account key
     */
    private static function quotaExhausted(array $row, array $deps, array &$readings, int $now): ?bool
    {
        $agent = is_string($row['agent'] ?? null) && $row['agent'] !== '' ? $row['agent'] : 'claude';
        $key = match ($agent) {
            'claude' => 'claude/' . (is_string($row['profile'] ?? null) && $row['profile'] !== '' ? $row['profile'] : 'personal'),
            'codex' => 'codex/default',
            default => $agent,
        };
        if (!array_key_exists($key, $readings)) {
            $reader = $deps['quota'];
            try {
                $readings[$key] = $reader($key);
            } catch (\Throwable $e) {
                error_log('FlowService quota read failed for ' . $key . ': ' . $e::class . ': ' . $e->getMessage());
                $readings[$key] = null;
            }
        }
        $r = $readings[$key];
        $at = static fn (mixed $v): ?int => is_int($v) ? $v : null;
        if (!is_array($r) || !array_key_exists('session_pct', $r) || !array_key_exists('week_pct', $r)
            || ($r['session_pct'] !== null && !is_int($r['session_pct'])) || ($r['week_pct'] !== null && !is_int($r['week_pct']))) {
            return null;
        }
        if ($r['session_pct'] === null && $r['week_pct'] === null) {
            return null;
        }
        foreach ([['session_pct', 'session_resets_at'], ['week_pct', 'week_resets_at']] as [$pct, $reset]) {
            $resetAt = $at($r[$reset] ?? null);
            if ($r[$pct] !== null && $r[$pct] >= 100 && ($resetAt === null || $resetAt > $now)) {
                return true;
            }
        }

        return false;
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
            return $quota ??= self::cachedUsage();
        };

        return [
            'clock' => static fn (): int => time(),
            'detail' => static fn (string $session): array => \dispatch_action(['action' => 'session_detail', 'session' => $session]),
            'stored_statuses' => [SessionStatusStore::class, 'read_many'],
            'facts' => static fn (string $session, array $row): ?array => self::storedFacts($session, $row),
            'quota' => static fn (string $key): ?array => self::quotaReading($usage(), $key),
            'receivers' => self::receivers(),
            'thresholds' => Thresholds::fromFile(Config::session_flow_thresholds_path()),
            'sessions' => static fn (): array => array_column(SidecarStore::list_runtime_sidecars('headless'), 'session_name'),
            'launch' => [LaunchService::class, 'launch'],
            'kill' => [LaunchService::class, 'kill'],
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

    /**
     * @param array<string, mixed> $request
     * @param array<string, mixed> $old the source sidecar
     */
    private static function rotatePrompt(array $request, string $session, array $old): string
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

        $source = ['name' => $session, 'id' => $old['agent_session_id'] ?? null, 'agent' => (string) ($old['agent'] ?? 'claude')];

        return match (key($given)) {
            'handoff_id' => PromptBuilder::handoff($value, $source),
            'plan_id' => PromptBuilder::plan($value, '', $source),
            default => PromptBuilder::selfContained($value, $source),
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
        if (!$same && !ReceiverPicker::crossAgentSafe($old, ['mode' => $mode])) {
            throw new \InvalidArgumentException('Source mode or tool rules cannot cross agents');
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
        $list = self::claimList($command, $conversationId, $deps);
        if ($list === null) {
            return 'The claims check (SESSIONEER_CLAIMS_CMD) failed, so the source was not rotated';
        }

        return $list === [] ? null : 'The source still holds Dibs claims; release or hand them off first';
    }

    /**
     * The claims a conversation holds; null when the command failed or printed something that is not a claims list.
     * Strict mode also rejects an object without a `claims` key: a wrong "holds a claim" archives a session early.
     *
     * @param array<string, mixed> $deps
     * @return list<mixed>|null
     */
    private static function claimList(string $command, string $conversationId, array $deps, bool $strict = false): ?array
    {
        $result = $deps['runner']($command, $conversationId);
        $claims = json_decode((string) ($result['stdout'] ?? ''), true);
        if (($result['exit'] ?? 1) !== 0 || !is_array($claims)) {
            return null;
        }
        if ($strict && !array_is_list($claims) && !array_key_exists('claims', $claims)) {
            return null;
        }
        $list = array_key_exists('claims', $claims) ? $claims['claims'] : $claims;
        if ($list === null) {
            return [];
        }

        return is_array($list) ? array_values($list) : null;
    }

    private static function claimOffer(string $session, int $now, ?string &$reservation): ?string
    {
        $reservation = SessionOfferStore::reserve($session, $now);
        if ($reservation !== null || !SessionOfferStore::has($session)) {
            return null;
        }
        return 'This offer was already used, dismissed or expired; check the session and tap again';
    }

    /** @return resource|null null when another rotate of this source holds the lock */
    private static function lock(string $session)
    {
        return self::lockFile('flow-rotate-' . sha1($session));
    }

    /** @return resource|null null when something else holds the named lock */
    private static function lockFile(string $name)
    {
        $dir = Config::cache_dir();
        if (!is_dir($dir) && !mkdir($dir, 0700, true) && !is_dir($dir)) {
            return null;
        }
        $handle = fopen($dir . '/' . $name . '.lock', 'c');
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
    private static function decide(?array $facts, ?string $intent, array $deps, int $now, string $session): array
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
            $old = $facts['_sidecar'] ?? SidecarStore::read_sidecar($session) ?? [];
            $mode = (string) (array_key_exists('_mode', $facts) ? $facts['_mode'] : (SessionStatusStore::read_status($session)['mode'] ?? ''));
            foreach ($deps['receivers'] as $key) {
                [$agent] = explode('/', $key, 2);
                if (!in_array($agent, ['claude', 'codex'], true) || !empty($old['no_tools']) || ($agent !== $facts['source']['agent'] && !ReceiverPicker::crossAgentSafe($old, ['mode' => $mode]))) {
                    continue;
                }
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
        $status = $row['_flow_status'] ?? SessionStatusStore::read_status($session) ?? [];
        $now = time();
        $id = (string) ($sidecar['agent_session_id'] ?? '');
        $path = !array_key_exists('_flow_status', $row) && $agent === 'claude' && $id !== '' ? TranscriptRouter::find_transcript_path($id, $profile) : null;
        $stored = FlowFacts::stored($status['context_usage'] ?? null, $status['token_usage'] ?? null, $agent, (string) ($status['model'] ?? ''), (int) ($status['updated_at'] ?? $now), $path);
        $activity = is_int($row['activity'] ?? null) ? $row['activity'] : ($stored['last_activity'] ?? null);

        return [
            '_sidecar' => $sidecar,
            '_mode' => $status['mode'] ?? '',
            'context_tokens' => $stored['context_tokens'] ?? null,
            'idle_seconds' => $activity === null ? null : max(0, $now - $activity),
            'source' => ['agent' => $agent, 'profile' => $profile, 'status' => (string) ($status['status'] ?? 'idle')],
        ];
    }

    /**
     * QuotaService::get_quota() costs about half a second here (it reads five stores, one over the
     * network), and every host request is its own process, so the result is shared through a file
     * for QUOTA_CACHE_TTL seconds.
     *
     * @return array<string, mixed>
     */
    private static function cachedUsage(): array
    {
        $path = Config::cache_dir() . '/flow-quota.json';
        $cached = json_decode((string) @file_get_contents($path), true);
        if (is_array($cached) && is_array($cached['usage'] ?? null) && is_numeric($cached['at'] ?? null) && microtime(true) - (float) $cached['at'] < self::QUOTA_CACHE_TTL) {
            return $cached['usage'];
        }
        $usage = QuotaService::get_quota();
        $dir = dirname($path);
        $tmp = $dir . '/flow-quota.json.' . getmypid() . '.tmp';
        $error = null;
        if (!is_dir($dir) && !@mkdir($dir, 0700, true) && !is_dir($dir)) {
            $error = 'cannot create ' . $dir;
        } elseif (@file_put_contents($tmp, json_encode(['at' => microtime(true), 'usage' => $usage])) === false) {
            $error = 'cannot write ' . $tmp;
        } elseif (!@rename($tmp, $path)) {
            $error = 'cannot rename ' . $tmp . ' to ' . $path;
        }
        if ($error !== null) {
            @unlink($tmp);
            error_log('FlowService flow-quota cache not written: ' . $error);
        }

        return $usage;
    }

    /**
     * @param array<string, mixed> $usage QuotaService::get_quota()
     * @return array{session_pct: ?int, week_pct: ?int, resets_at: ?int, session_resets_at: ?int, week_resets_at: ?int}|null
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
            'session_resets_at' => is_int($session['resets_at'] ?? null) ? $session['resets_at'] : null,
            'week_resets_at' => is_int($week['resets_at'] ?? null) ? $week['resets_at'] : null,
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
        foreach (Config::claude_profiles_to_scan() as $profile) {
            if (Config::claude_bin($profile) !== '') {
                $keys[] = 'claude/' . ($profile ?? 'personal');
            }
        }

        return $keys;
    }
}
