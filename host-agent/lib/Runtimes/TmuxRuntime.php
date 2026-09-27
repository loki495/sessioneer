<?php

declare(strict_types=1);

namespace HostAgent\Runtimes;

use HostAgent\Services\PromptInteractionService;
use HostAgent\Services\PromptParser;
use HostAgent\Services\SessionDetailService;
use HostAgent\Services\SessionLifecycleService;
use HostAgent\Services\SessionService;
use HostAgent\Stores\SessionStatusStore;

/**
 * The tmux runtime: sessions are panes in a tmux server; they are driven by
 * typing into the pane and observed via hooks + capture-pane. This is a
 * *thin delegator* over the existing services that already implement all of
 * this - the point of Phase 1 is not to re-implement tmux behavior but to
 * give the contract a concrete tmux implementation so callers can go
 * through a RuntimeProvider without knowing which runtime they're talking
 * to.
 *
 * A session reference is the tmux session NAME (e.g. cc-20260824-131822),
 * consistent with how every existing tmux-facing service keys its state.
 * The agent id is injected at construction by RuntimeRegistry so create()
 * spawns the right agent.
 */
class TmuxRuntime implements RuntimeProvider
{
    private string $agentId;

    public function __construct(string $agentId)
    {
        $this->agentId = $agentId;
    }

    public function id(): string
    {
        return RuntimeType::TMUX;
    }

    public function isHeadless(): bool
    {
        return false;
    }

    public function isTmux(): bool
    {
        return true;
    }

    public function create(array $options): array
    {
        $workdir = is_string($options['workdir'] ?? null) ? $options['workdir'] : '';

        if ($workdir === '') {
            return ['ok' => false, 'message' => 'create() requires a workdir'];
        }

        return SessionLifecycleService::create_agent_session(
            $workdir,
            (bool)($options['enable_task_tools'] ?? false),
            is_string($options['starting_mode'] ?? null) ? $options['starting_mode'] : null,
            $this->agentId
        );
    }

    public function list(): array
    {
        $result = SessionService::list_all_sessions();
        $sessions = array_values(array_filter(
            $result['sessions'],
            fn(array $s): bool => ($s['agent'] ?? null) === $this->agentId
        ));

        return ['ok' => true, 'sessions' => $sessions];
    }

    public function detail(string $sessionRef): array
    {
        return SessionDetailService::session_detail($sessionRef);
    }

    public function kill(string $sessionRef): array
    {
        return SessionLifecycleService::kill_agent_session($sessionRef);
    }

    public function status(string $sessionRef): array
    {
        $status = SessionStatusStore::read_status($sessionRef);
        $statusValue = is_string($status['status'] ?? null) ? $status['status'] : 'idle';
        $blocked = is_array($status['blocked'] ?? null) ? $status['blocked'] : null;

        return [
            'ok' => true,
            'status' => $statusValue,
            'blocked' => $blocked !== null ? PromptParser::build_prompt_from_hook_status($blocked) : null,
        ];
    }

    public function send_message(string $sessionRef, string $text, array $attachmentPaths = []): array
    {
        return PromptInteractionService::send_message($sessionRef, $text, $attachmentPaths);
    }

    public function pending_prompt(string $sessionRef): ?array
    {
        $blocked = SessionStatusStore::read_status($sessionRef)['blocked'] ?? null;

        return is_array($blocked) ? PromptParser::build_prompt_from_hook_status($blocked) : null;
    }

    public function answer_prompt(string $sessionRef, array $answers): array
    {
        if (is_array($answers['answers'] ?? null)) {
            return PromptInteractionService::answer_multi_question($sessionRef, $answers['answers']);
        }

        $option = (int)($answers['option'] ?? 0);

        if ($option <= 0) {
            return ['ok' => false, 'message' => 'answer_prompt() requires a positive option number'];
        }

        $text = is_string($answers['text'] ?? null) ? $answers['text'] : '';

        return $text !== ''
            ? PromptInteractionService::answer_prompt_with_text($sessionRef, $option, $text)
            : PromptInteractionService::answer_prompt($sessionRef, $option);
    }

    public function interrupt(string $sessionRef): array
    {
        return PromptInteractionService::send_escape($sessionRef);
    }

    public function update_settings(string $sessionRef, ?string $model = null, ?string $effort = null, ?string $provider = null): array
    {
        // A tmux pane only has a model switch; effort and provider don't exist there.
        if ($model === null || $model === '') {
            return ['ok' => false, 'message' => 'No model supplied'];
        }

        return PromptInteractionService::set_model($sessionRef, $model);
    }

    public function set_mode(string $sessionRef, string $mode): array
    {
        return PromptInteractionService::set_mode($sessionRef, $mode);
    }
}
