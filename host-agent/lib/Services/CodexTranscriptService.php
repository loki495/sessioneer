<?php

declare(strict_types=1);

namespace HostAgent\Services;

use HostAgent\Runtimes\CodexBridgeClient;
use HostAgent\Stores\SidecarStore;

/** Canonical transcript adapter for Codex app-server thread history. */
class CodexTranscriptService
{
    private const PREFIX = 'codex:';

    /** Formats both app-server errors and errors saved by older bridges. */
    public static function turn_error_message(mixed $error): ?string
    {
        if (is_string($error)) {
            $decoded = json_decode($error, true);
            if (is_array($decoded)) return self::turn_error_message($decoded);
            return trim($error) !== '' ? $error : null;
        }
        if (!is_array($error)) return null;

        $message = is_string($error['message'] ?? null) ? trim($error['message']) : '';
        $info = $error['codexErrorInfo'] ?? null;
        $quota = $info === 'usageLimitExceeded' || $info === 'UsageLimitExceeded'
            || (is_array($info) && (array_key_exists('usageLimitExceeded', $info) || array_key_exists('UsageLimitExceeded', $info)));
        if ($quota) return 'Codex quota reached.' . ($message !== '' ? ' ' . $message : ' Try again after your usage limit resets.');
        return $message !== '' ? $message : 'Codex could not complete this turn.';
    }

    public static function find_transcript_path(string $threadId): ?string
    {
        $sidecar = SidecarStore::read_sidecar($threadId);
        if (($sidecar['agent'] ?? null) === 'codex') {
            return self::PREFIX . $threadId;
        }

        if (glob(Config::home_root() . '/.codex/archived_sessions/*-' . $threadId . '.jsonl') !== []) {
            return self::PREFIX . $threadId;
        }

        // Dormant Codex sessions deliberately lose their active sidecar.
        // Resolve them from app-server's durable thread catalog so archived
        // detail/history continues to work after the active-window handoff.
        $reply = (new CodexBridgeClient())->request('thread/read', ['threadId' => $threadId]);
        return ($reply['ok'] ?? false) === true && is_array($reply['result']['thread'] ?? null)
            ? self::PREFIX . $threadId
            : null;
    }

    /**
     * Reads Codex's own archived rollout index. app-server 0.150 can move a
     * rollout here while omitting it from thread/list(archived=true), so the
     * directory is the durable source of truth for explicit archives.
     *
     * @return array<int,array<string,mixed>>
     */
    public static function list_archived_rollouts(): array
    {
        $threads = [];
        foreach (glob(Config::home_root() . '/.codex/archived_sessions/rollout-*.jsonl') ?: [] as $path) {
            $handle = @fopen($path, 'rb');
            $first = $handle !== false ? fgets($handle) : false;
            if ($handle !== false) fclose($handle);
            $record = is_string($first) ? json_decode($first, true) : null;
            $payload = is_array($record['payload'] ?? null) ? $record['payload'] : [];
            $id = is_string($payload['session_id'] ?? null) ? $payload['session_id'] : null;
            if ($id === null || $id === '') continue;

            $created = is_string($payload['timestamp'] ?? null) ? strtotime($payload['timestamp']) : false;
            $threads[] = [
                'id' => $id,
                'cwd' => is_string($payload['cwd'] ?? null) ? $payload['cwd'] : null,
                'createdAt' => $created !== false ? $created : 0,
                'updatedAt' => filemtime($path) ?: ($created !== false ? $created : 0),
            ];
        }
        return $threads;
    }

    /** @return array<string,mixed>|null */
    public static function thread_metadata(string $threadId): ?array
    {
        $reply = (new CodexBridgeClient())->request('thread/read', ['threadId' => $threadId]);
        if (($reply['ok'] ?? false) === true && is_array($reply['result']['thread'] ?? null)) {
            return $reply['result']['thread'];
        }
        foreach (self::list_archived_rollouts() as $thread) {
            if (($thread['id'] ?? null) === $threadId) return $thread;
        }
        return null;
    }

    /**
     * Returns the complete native thread catalog, following app-server's
     * cursor rather than silently dropping everything beyond its first page.
     *
     * @return array{ok:bool,threads?:array<int,array<string,mixed>>,message?:string}
     */
    public static function list_threads(bool $archived = false, ?CodexBridgeClient $client = null, bool $allSourceKinds = false): array
    {
        $client ??= new CodexBridgeClient();
        $threads = [];
        $cursor = null;

        do {
            $params = [
                'limit' => 100,
                'archived' => $archived,
                'sortKey' => 'updated_at',
                'sortDirection' => 'desc',
            ];
            // Archived discovery must be exhaustive: Sessioneer-created persisted
            // rollouts currently report source=vscode, which the default
            // interactive subset omits after thread/archive. Active sync
            // intentionally keeps the native default because it is also the
            // only list that includes a brand-new unmaterialized thread.
            if ($allSourceKinds) {
                $params['sourceKinds'] = [
                    'cli', 'vscode', 'exec', 'appServer', 'subAgent',
                    'subAgentReview', 'subAgentCompact', 'subAgentThreadSpawn',
                    'subAgentOther', 'unknown',
                ];
            }
            if ($cursor !== null) $params['cursor'] = $cursor;

            $reply = $client->request('thread/list', $params);
            if (($reply['ok'] ?? false) !== true) {
                return ['ok' => false, 'message' => $reply['message'] ?? 'Codex thread list unavailable'];
            }

            foreach (($reply['result']['data'] ?? []) as $thread) {
                if (is_array($thread)) $threads[] = $thread;
            }
            $next = $reply['result']['nextCursor'] ?? null;
            $cursor = is_string($next) && $next !== '' ? $next : null;
        } while ($cursor !== null);

        return ['ok' => true, 'threads' => $threads];
    }

    public static function is_codex_path(string $path): bool
    {
        return str_starts_with($path, self::PREFIX);
    }

    private static function thread_id(string $path): string
    {
        return substr($path, strlen(self::PREFIX));
    }

    /**
     * Maps one retained app-server thread item to Sessioneer's canonical entry.
     * Kept public for deterministic protocol-fixture coverage: app-server
     * adds item variants independently of Sessioneer, and silently dropping an
     * unknown tool shape is otherwise easy to miss in browser smoke tests.
     *
     * @param array<string,mixed> $item
     * @return array<string,mixed>|null
     */
    public static function parse_item(array $item, ?string $timestamp, int $line): ?array
    {
        $type = (string)($item['type'] ?? '');
        $role = null;
        $blocks = [];

        if ($type === 'userMessage') {
            $role = 'user';
            foreach (($item['content'] ?? []) as $content) {
                if (is_array($content) && is_string($content['text'] ?? null) && trim($content['text']) !== '') {
                    $blocks[] = ['kind' => 'text', 'text' => $content['text']];
                }
            }
        } elseif ($type === 'agentMessage') {
            $role = 'assistant';
            if (is_string($item['text'] ?? null) && trim($item['text']) !== '') $blocks[] = ['kind' => 'text', 'text' => $item['text']];
        } elseif ($type === 'plan') {
            $role = 'assistant';
            if (is_string($item['text'] ?? null)) $blocks[] = ['kind' => 'plan', 'text' => $item['text']];
        } elseif ($type === 'commandExecution') {
            $role = 'assistant';
            $command = (string)($item['command'] ?? '');
            $blocks[] = ['kind' => 'tool_use', 'text' => $command, 'tool_name' => 'Bash', 'command' => $command];
            if (is_string($item['aggregatedOutput'] ?? null) && $item['aggregatedOutput'] !== '') $blocks[] = ['kind' => 'tool_result', 'text' => $item['aggregatedOutput']];
        } elseif ($type === 'fileChange') {
            $role = 'assistant';
            $blocks[] = ['kind' => 'tool_use', 'text' => (string)json_encode($item['changes'] ?? [], JSON_UNESCAPED_SLASHES), 'tool_name' => 'FileChange'];
        } elseif ($type === 'webSearch') {
            $role = 'assistant';
            $action = is_array($item['action'] ?? null) ? $item['action'] : [];
            $queries = is_array($action['queries'] ?? null) ? array_values(array_map('strval', $action['queries'])) : [];
            $query = $queries !== [] ? implode('; ', $queries) : (string)($item['query'] ?? '');
            $blocks[] = ['kind' => 'tool_use', 'text' => $query !== '' ? $query : 'Web search', 'tool_name' => 'WebSearch'];
            if (is_array($item['results'] ?? null) && $item['results'] !== []) {
                $blocks[] = ['kind' => 'tool_result', 'text' => (string)json_encode($item['results'], JSON_UNESCAPED_SLASHES)];
            }
        } elseif ($type === 'collabAgentToolCall') {
            $role = 'assistant';
            $tool = (string)($item['tool'] ?? 'Agent');
            $agentType = (string)($item['model'] ?? 'Codex subagent');
            $blocks[] = [
                'kind' => 'tool_use',
                'text' => (string)($item['prompt'] ?? $tool),
                'tool_name' => $tool,
                'agent_type' => $agentType,
            ];
            if (is_array($item['agentsStates'] ?? null) && $item['agentsStates'] !== []) {
                $blocks[] = [
                    'kind' => 'tool_result',
                    'text' => (string)json_encode($item['agentsStates'], JSON_UNESCAPED_SLASHES),
                    'agent_type' => $agentType,
                ];
            }
        } elseif ($type === 'subAgentActivity') {
            $role = 'assistant';
            $blocks[] = [
                'kind' => 'tool_result',
                'text' => (string)($item['kind'] ?? 'Subagent activity'),
                'agent_type' => (string)($item['agentPath'] ?? 'Codex subagent'),
            ];
        } elseif ($type === 'imageView') {
            $role = 'assistant';
            $path = (string)($item['path'] ?? '');
            $blocks[] = ['kind' => 'tool_use', 'text' => $path, 'tool_name' => 'ImageView'];
        } elseif ($type === 'sleep') {
            $role = 'assistant';
            $blocks[] = ['kind' => 'tool_use', 'text' => 'Wait ' . (int)($item['durationMs'] ?? 0) . ' ms', 'tool_name' => 'Sleep'];
        } elseif ($type === 'imageGeneration') {
            $role = 'assistant';
            $blocks[] = ['kind' => 'tool_use', 'text' => (string)($item['revisedPrompt'] ?? 'Generate image'), 'tool_name' => 'ImageGeneration'];
            $result = $item['savedPath'] ?? $item['result'] ?? $item['failure'] ?? null;
            if ($result !== null) $blocks[] = ['kind' => 'tool_result', 'text' => is_string($result) ? $result : (string)json_encode($result, JSON_UNESCAPED_SLASHES)];
        } elseif ($type === 'enteredReviewMode' || $type === 'exitedReviewMode') {
            $role = 'assistant';
            $label = $type === 'enteredReviewMode' ? 'Entered review mode' : 'Exited review mode';
            $review = is_string($item['review'] ?? null) ? trim($item['review']) : '';
            $blocks[] = ['kind' => 'text', 'text' => $review !== '' ? $label . ': ' . $review : $label];
        } elseif ($type === 'mcpToolCall' || $type === 'dynamicToolCall') {
            $role = 'assistant';
            $namespace = is_string($item['server'] ?? null) ? $item['server'] : (is_string($item['namespace'] ?? null) ? $item['namespace'] : '');
            $tool = ($namespace !== '' ? $namespace . '.' : '') . (string)($item['tool'] ?? 'tool');
            $blocks[] = ['kind' => 'tool_use', 'text' => $tool . ': ' . json_encode($item['arguments'] ?? []), 'tool_name' => $tool];
            $output = $item['result'] ?? $item['contentItems'] ?? null;
            if ($output !== null) $blocks[] = ['kind' => 'tool_result', 'text' => is_string($output) ? $output : (string)json_encode($output)];
        }

        if ($blocks === []) return null;

        return [
            'type' => $role === 'user' ? 'USER_INPUT' : 'PLANNER_RESPONSE',
            'role' => $role,
            'timestamp' => $timestamp,
            'blocks' => $blocks,
            'line' => $line,
        ];
    }

    /** @return array<int,array<string,mixed>> */
    private static function entries(string $path): array
    {
        $reply = (new CodexBridgeClient())->request('thread/read', ['threadId' => self::thread_id($path), 'includeTurns' => true]);
        $turns = is_array($reply['result']['thread']['turns'] ?? null) ? $reply['result']['thread']['turns'] : [];
        $entries = [];

        foreach ($turns as $turn) {
            if (!is_array($turn)) continue;
            $timestamp = isset($turn['startedAt']) ? gmdate('c', (int)$turn['startedAt']) : null;
            foreach (($turn['items'] ?? []) as $item) {
                if (!is_array($item)) continue;
                $entry = self::parse_item($item, $timestamp, count($entries) + 1);
                if ($entry !== null) $entries[] = $entry;
            }
        }

        return $entries;
    }

    /** @return array<string,mixed> */
    public static function read_transcript_page(string $path, ?int $before, int $limit, bool $untilRealUserMessage = false): array
    {
        $all = self::entries($path);
        $upper = $before !== null ? max(0, min($before - 1, count($all))) : count($all);
        $start = max(0, $upper - $limit);
        return ['ok' => true, 'entries' => array_slice($all, $start, $upper - $start), 'next_before' => $start > 0 ? $start + 1 : null, 'has_more' => $start > 0];
    }

    /** @return array<string,mixed> */
    public static function read_transcript_page_since(string $path, int $afterLine, int $limit): array
    {
        return ['ok' => true, 'entries' => array_slice(self::entries($path), max(0, $afterLine), $limit)];
    }

    /** @return array<string,mixed> */
    public static function read_attachment(string $path, int $line, string $fileUuid): array
    {
        return ['ok' => false, 'message' => 'Codex transcript attachments are not available yet'];
    }
}
