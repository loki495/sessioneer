<?php

declare(strict_types=1);

namespace HostAgent\Agents;

use HostAgent\Runtimes\RuntimeType;
use HostAgent\Services\OllamaClient;
use HostAgent\Stores\GlobalStateStore;

/**
 * A local Ollama model as an agent. Deliberately the smallest adapter in the
 * set: there is no CLI, no hooks, no permission modes and no tmux argv - a
 * session is just a conversation held against the server's HTTP API by
 * OllamaHeadlessRuntime, so `supported_runtimes()` names headless only and
 * everything a coding CLI would answer here is empty or a no-op.
 *
 * Models come from the server itself rather than a list in this repo: which
 * ones are usable depends on what the operator has pulled, and a hardcoded
 * catalog would offer models that are not there.
 */
class OllamaAdapter implements AgentAdapter
{
    public function id(): string
    {
        return 'ollama';
    }

    public function label(): string
    {
        return 'Ollama (local)';
    }

    public function session_name_prefix(): string
    {
        return 'ol';
    }

    /**
     * Empty on purpose: this agent has no tmux runtime (supported_runtimes()
     * above), so nothing ever spawns an argv for it. Returning an empty argv
     * rather than throwing keeps the shared adapter contract honest - an
     * adapter that supports only headless still has to answer.
     *
     * @param array<string, mixed> $options
     */
    public function build_spawn_argv(array $options): array
    {
        return ['argv' => [], 'assigned_id' => null];
    }

    /**
     * Nothing to install: the agent is the server's API, not a CLI with a
     * hook system.
     *
     * @return array{ok:bool, installed:bool, message?:string}
     */
    public function check_hooks(): array
    {
        return ['ok' => true, 'installed' => true];
    }

    /**
     * @return array{ok:bool, installed:bool, message?:string}
     */
    public function install_hooks(): array
    {
        return ['ok' => true, 'installed' => true];
    }

    /**
     * No permission prompts exist, so there is no raw vocabulary to map.
     *
     * @return array<string, string>
     */
    public function permission_mode_map(): array
    {
        return [];
    }

    /**
     * @return array<int, string>
     */
    public function supported_runtimes(): array
    {
        return [RuntimeType::HEADLESS];
    }

    /**
     * @return array{ok:bool, models?:array<int, array<string, mixed>>, message?:string}
     */
    public function model_catalog(): array
    {
        $client = new OllamaClient();
        $catalog = $client->models();

        if ($catalog['ok'] === true) {
            GlobalStateStore::write('ollama_models', [
                'models' => $catalog['models'] ?? [],
                'url' => $client->baseUrl(),
                'updated_at' => time(),
            ]);
        }

        return $catalog;
    }
}
