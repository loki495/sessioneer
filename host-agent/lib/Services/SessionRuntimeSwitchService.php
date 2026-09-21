<?php

declare(strict_types=1);

namespace HostAgent\Services;

use HostAgent\Runtimes\ClaudeHeadlessRuntime;
use HostAgent\Runtimes\RuntimeRegistry;
use HostAgent\Runtimes\RuntimeType;
use HostAgent\Stores\SessionStatusStore;
use HostAgent\Stores\SidecarStore;

/**
 * Moves a live Claude Code conversation between runtimes - out of a tmux pane
 * into a headless session, or back - without losing it: the conversation id
 * (and so the transcript) is what carries over, the process is what changes.
 *
 * Only ever ONE process may own a transcript, so the old runtime is stopped
 * completely before the new one starts. If the start then fails the
 * conversation is not lost - it is simply no longer running, and shows up in
 * the archived list to resume from - and the message says so.
 */
final class SessionRuntimeSwitchService
{
    /**
     * @return array{ok:bool, name?:string, message:string}
     */
    public static function switch_runtime(string $name, string $target): array
    {
        if (!in_array($target, RuntimeType::all(), true)) {
            return ['ok' => false, 'message' => 'Unknown runtime'];
        }

        $sidecar = SidecarStore::read_sidecar($name);

        if ($sidecar === null) {
            return ['ok' => false, 'message' => 'Session not found'];
        }

        if (($sidecar['agent'] ?? 'claude') !== 'claude') {
            return ['ok' => false, 'message' => 'Only Claude Code sessions can switch runtime'];
        }

        $current = ($sidecar['runtime'] ?? null) === RuntimeType::HEADLESS ? RuntimeType::HEADLESS : RuntimeType::TMUX;

        if ($current === $target) {
            return ['ok' => false, 'message' => 'The session is already running that way'];
        }

        $agentSessionId = is_string($sidecar['agent_session_id'] ?? null) ? $sidecar['agent_session_id'] : '';
        $workdir = is_string($sidecar['workdir'] ?? null) ? $sidecar['workdir'] : '';
        $profile = is_string($sidecar['profile'] ?? null) && $sidecar['profile'] !== '' ? $sidecar['profile'] : null;

        // A session that has not received a message yet has an id but no
        // transcript, and `claude --resume` cannot continue nothing.
        if ($agentSessionId === '' || TranscriptRouter::find_transcript_path($agentSessionId, $profile) === null) {
            return ['ok' => false, 'message' => 'This session has no conversation yet, so there is nothing to carry over'];
        }

        // Stopping mid-turn would discard whatever Claude was doing, or a
        // prompt still waiting for an answer.
        $status = SessionStatusStore::read_status($name)['status'] ?? 'idle';

        if ($status === 'working' || $status === 'blocked') {
            return ['ok' => false, 'message' => 'The session is busy - wait for the turn to finish (or interrupt it) before switching'];
        }

        $headless = RuntimeRegistry::runtime_for('claude', RuntimeType::HEADLESS);

        if (!$headless instanceof ClaudeHeadlessRuntime) {
            return ['ok' => false, 'message' => 'Headless runtime unavailable'];
        }

        return $target === RuntimeType::HEADLESS
            ? self::tmux_to_headless($name, $headless, $workdir, $agentSessionId, $profile)
            : self::headless_to_tmux($name, $headless, $workdir, $agentSessionId, $profile);
    }

    /** @return array{ok:bool, name?:string, message:string} */
    private static function tmux_to_headless(string $name, ClaudeHeadlessRuntime $headless, string $workdir, string $agentSessionId, ?string $profile): array
    {
        $killed = SessionLifecycleService::kill_agent_session($name);

        if ($killed['ok'] !== true) {
            return ['ok' => false, 'message' => 'Could not stop the terminal session: ' . self::reason($killed)];
        }

        $resumed = $headless->resume($workdir, $agentSessionId, $profile);

        if ($resumed['ok'] !== true) {
            return ['ok' => false, 'message' => 'The terminal session was stopped but the headless one did not start (' . self::reason($resumed) . '). The conversation is safe - resume it from the archived list.'];
        }

        return ['ok' => true, 'name' => (string)$resumed['name'], 'message' => 'Now running headless (no terminal)'];
    }

    /** @return array{ok:bool, name?:string, message:string} */
    private static function headless_to_tmux(string $name, ClaudeHeadlessRuntime $headless, string $workdir, string $agentSessionId, ?string $profile): array
    {
        $stopped = $headless->kill($name);

        if ($stopped['ok'] !== true) {
            return ['ok' => false, 'message' => 'Could not stop the headless session: ' . self::reason($stopped)];
        }

        // The tmux resume refuses a conversation that still has a headless
        // sidecar, so the old rows must go first.
        SidecarStore::delete_sidecar($name);
        SessionStatusStore::delete_status($name);

        $resumed = SessionLifecycleService::resume_agent_session($workdir, $agentSessionId, $profile);

        if ($resumed['ok'] !== true) {
            return ['ok' => false, 'message' => 'The headless session was stopped but the terminal one did not start (' . self::reason($resumed) . '). The conversation is safe - resume it from the archived list.'];
        }

        return ['ok' => true, 'name' => (string)($resumed['name'] ?? ''), 'message' => 'Now running in a terminal (tmux)'];
    }

    /**
     * @param array<string, mixed> $result any runtime/service result
     */
    private static function reason(array $result): string
    {
        return is_string($result['message'] ?? null) && $result['message'] !== '' ? $result['message'] : 'unknown error';
    }
}
