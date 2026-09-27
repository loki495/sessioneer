<?php

declare(strict_types=1);

namespace HostAgent\Agents;

use HostAgent\Runtimes\CodexBridgeClient;
use HostAgent\Runtimes\RuntimeType;
use HostAgent\Services\CodexHookService;
use HostAgent\Services\PushHealthService;

/** Codex is server-owned in Sessioneer; it is never spawned into tmux. */
class CodexAdapter implements AgentAdapter
{
    public function id(): string { return 'codex'; }
    public function label(): string { return 'Codex'; }
    public function session_name_prefix(): string { return 'cx'; }

    public function build_spawn_argv(array $options): array
    {
        return ['argv' => [], 'assigned_id' => null];
    }

    public function check_hooks(): array
    {
        $hooks = CodexHookService::check_session_hook();
        $reachable = PushHealthService::codex_bridge_reachable();

        if (!$hooks['ok']) {
            return $hooks;
        }

        return [
            'ok' => $reachable['ok'],
            'installed' => $hooks['installed'],
            'message' => $reachable['ok']
                ? ($hooks['installed'] ? 'Codex bridge reachable and status hooks installed' : 'Codex status hooks are not fully installed')
                : ($reachable['detail'] !== null ? $reachable['detail'] : 'Codex bridge unavailable'),
        ];
    }

    public function install_hooks(): array
    {
        return CodexHookService::install_session_hook();
    }

    public function permission_mode_map(): array { return []; }

    public function supported_runtimes(): array
    {
        return [RuntimeType::HEADLESS];
    }

    public function model_catalog(): array
    {
        $reply = (new CodexBridgeClient())->request('model/list', ['limit' => 100, 'includeHidden' => false]);

        if ($reply['ok'] !== true) {
            return $reply;
        }

        $data = is_array($reply['result']['data'] ?? null) ? $reply['result']['data'] : [];
        $models = [];

        foreach ($data as $model) {
            if (!is_array($model) || !is_string($model['model'] ?? null)) {
                continue;
            }

            $efforts = [];

            foreach ($model['supportedReasoningEfforts'] ?? [] as $option) {
                if (is_array($option) && is_string($option['reasoningEffort'] ?? null)) {
                    $efforts[] = $option['reasoningEffort'];
                }
            }

            $models[] = [
                'id' => $model['model'],
                'name' => is_string($model['displayName'] ?? null) ? $model['displayName'] : $model['model'],
                'isDefault' => (bool)($model['isDefault'] ?? false),
                'defaultEffort' => is_string($model['defaultReasoningEffort'] ?? null) ? $model['defaultReasoningEffort'] : null,
                'efforts' => $efforts,
            ];
        }

        return ['ok' => true, 'models' => $models];
    }
}
