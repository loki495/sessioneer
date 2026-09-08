<?php

declare(strict_types=1);

namespace HostAgent\Services;

use HostAgent\Stores\SessionStatusStore;

/** Maps directory-scoped OpenCode SSE question events onto tracked sessions. */
final class OpenCodeSseConsumer
{
    /**
     * A sidecar workdir is deliberately not a fallback: /event is scoped to
     * OpenCode's own canonical v2 session directory, which can differ from cwd.
     * @param array<int,array<string,mixed>> $trackedSidecars
     * @param array<int,array<string,mixed>> $v2Sessions
     * @return array<string,array<string,string>> directory => [agent id => store id]
     */
    public static function subscriptions(array $trackedSidecars, array $v2Sessions): array
    {
        $tracked = [];
        foreach ($trackedSidecars as $sidecar) {
            if (($sidecar['agent'] ?? 'opencode') !== 'opencode') continue;
            $agentId = is_string($sidecar['agent_session_id'] ?? null) && $sidecar['agent_session_id'] !== ''
                ? $sidecar['agent_session_id'] : (is_string($sidecar['session_name'] ?? null) ? $sidecar['session_name'] : '');
            $storeId = is_string($sidecar['session_name'] ?? null) ? $sidecar['session_name'] : $agentId;
            if ($agentId !== '' && $storeId !== '') $tracked[$agentId] = $storeId;
        }
        $subscriptions = [];
        foreach ($v2Sessions as $session) {
            $id = is_string($session['id'] ?? null) ? $session['id'] : '';
            $directory = is_array($session['location'] ?? null) && is_string($session['location']['directory'] ?? null)
                ? $session['location']['directory'] : (is_string($session['directory'] ?? null) ? $session['directory'] : '');
            if ($id !== '' && $directory !== '' && isset($tracked[$id])) $subscriptions[$directory][$id] = $tracked[$id];
        }
        return $subscriptions;
    }

    /**
     * @param array<string,mixed> $event
     * @param array<string,string> $trackedSessionIds agent id => status-store id
     */
    public static function handle(array $event, string $subscriptionDirectory, array $trackedSessionIds): bool
    {
        $normalized = OpenCodeSseFrameParser::normalize($event);
        if ($normalized === null || ($normalized['directory'] !== null && $normalized['directory'] !== $subscriptionDirectory)) return false;
        $props = $normalized['properties'];
        $agentId = is_string($props['sessionID'] ?? null) ? $props['sessionID'] : '';
        $storeId = $trackedSessionIds[$agentId] ?? null;
        $requestId = is_string($props['id'] ?? null) ? $props['id'] : (is_string($props['requestID'] ?? null) ? $props['requestID'] : '');
        if ($agentId === '' || $storeId === null || $requestId === '') return false;
        if ($normalized['type'] !== 'question.asked') return SessionStatusStore::resolve_opencode_question($storeId, $requestId);
        $questions = is_array($props['questions'] ?? null) ? $props['questions'] : [];
        if ($questions === []) return false;
        $prompt = OpenCodeQuestionService::to_prompt(['requestID' => $requestId, 'questions' => $questions]);
        $prompt['source'] = 'opencode_sse';
        $prompt['request_id'] = $requestId;
        $prompt['directory'] = $subscriptionDirectory;
        $prompt['tool_input'] = ['questions' => $questions];
        SessionStatusStore::update_status($storeId, ['status' => 'blocked', 'blocked' => $prompt]);
        return true;
    }
}
