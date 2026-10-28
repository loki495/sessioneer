<?php

declare(strict_types=1);

namespace HostAgent\Services;

/**
 * The "Ollama" section of the dashboard's health box: which server the chat
 * sessions talk to, whether it answers, what version it is, whether anything
 * on it can hold a conversation, and whether this PHP can run a turn in the
 * background at all.
 *
 * The last one is the one that surprises: a turn is a detached worker
 * process (OllamaHeadlessRuntime::spawn_worker()), so without pcntl every
 * send is refused with "needs PHP's pcntl extension" and nothing else here
 * matters. Checked here rather than discovered on the first message.
 *
 * build_checks() is pure (all inputs passed in) so every branch is testable;
 * checks() gathers the real inputs.
 */
class OllamaHealthService
{
    public const SECTION = 'Ollama';

    /**
     * @return array<int, array{key:string, section:string, label:string, ok:bool, detail:?string}>
     */
    public static function checks(): array
    {
        $client = new OllamaClient();

        return self::build_checks(
            $client->baseUrl(),
            $client->version(),
            $client->models(),
            function_exists('pcntl_fork') && function_exists('pcntl_exec')
        );
    }

    /**
     * @param string $baseUrl the server every Ollama session is sent to
     * @param string|null $version the server's own version, null when unreachable
     * @param array{ok:bool, models?:array<int, array<string, mixed>>, message?:string} $models the chat-capable catalog
     * @param bool $pcntlAvailable whether a turn can be detached from the request that started it
     * @return array<int, array{key:string, section:string, label:string, ok:bool, detail:?string}>
     */
    public static function build_checks(string $baseUrl, ?string $version, array $models, bool $pcntlAvailable): array
    {
        $reachable = $models['ok'] === true;

        $checks = [];

        $checks[] = self::check('ollama_endpoint', 'Ollama endpoint', $reachable, $reachable
            ? "{$baseUrl} reachable"
            : (is_string($models['message'] ?? null) ? $models['message'] : "{$baseUrl} is not reachable") . ' (set OLLAMA_URL in host-agent/.env if it moved)');

        $checks[] = self::check('ollama_version', 'Server version', $version !== null, $version ?? 'not reported by the server');

        $rows = $reachable ? ($models['models'] ?? []) : [];
        $count = count($rows);
        $names = array_map(static fn (array $row): string => (string)($row['id'] ?? ''), $rows);

        $checks[] = self::check('ollama_models', 'Chat models available', $count > 0, match (true) {
            !$reachable => 'unknown until the server answers',
            $count === 0 => 'nothing that can hold a conversation is pulled (run `ollama pull <model>`)',
            default => "{$count}: " . implode(', ', $names),
        });

        $checks[] = self::check('ollama_pcntl', 'Background turns (PHP pcntl)', $pcntlAvailable, $pcntlAvailable
            ? 'turns run in a detached worker'
            : "pcntl is missing from the PHP running host-agent, so every message is refused (it needs pcntl_fork/pcntl_exec)");

        return $checks;
    }

    /** @return array{key:string, section:string, label:string, ok:bool, detail:?string} */
    private static function check(string $key, string $label, bool $ok, ?string $detail): array
    {
        return ['key' => $key, 'section' => self::SECTION, 'label' => $label, 'ok' => $ok, 'detail' => $detail];
    }
}
