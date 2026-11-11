<?php

declare(strict_types=1);

namespace HostAgent\SessionFlow;

use HostAgent\Services\Config;
use HostAgent\Services\QuotaService;
use HostAgent\Services\TranscriptRouter;
use HostAgent\Stores\SessionOfferStore;
use HostAgent\Stores\SessionStatusStore;
use HostAgent\Stores\SidecarStore;

/**
 * The flow_next, flow_offer and flow_offer_dismiss host actions and the list overlay.
 * $deps: clock (): int, facts (session, row): ?array, quota (key): ?array, receivers
 * (list of "agent/profile"), thresholds (Thresholds). live() builds the real ones.
 */
final class FlowService
{
    /**
     * @param array<string, mixed> $request session, optional intent, handoff_id, plan_id, post
     * @param array<string, mixed> $deps
     * @return array<string, mixed>
     */
    public static function next(array $request, array $deps): array
    {
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
     * The real dependencies: stored readings, the advise action's quota reader, thresholds from config.
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
        ];
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
