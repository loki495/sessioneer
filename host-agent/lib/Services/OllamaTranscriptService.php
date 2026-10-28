<?php

declare(strict_types=1);

namespace HostAgent\Services;

/**
 * An Ollama session's conversation, stored as Sessioneer's own JSONL.
 *
 * Claude Code and Codex each own their transcript file (which is why
 * TranscriptRouter looks for a UUID in their directories); a local model has
 * no such home, so this agent keeps the only copy. The file is written in the
 * same `{type, message: {role, content}, timestamp}` shape every Claude line
 * uses, which lets the existing transcript parser, session search and the
 * dashboard's block renderer read it unchanged - no second renderer.
 */
final class OllamaTranscriptService
{
    /**
     * Where one session's turns live. Sidecar dir, not the agent's own data
     * dir: this is Sessioneer's record of the conversation, and it must
     * survive with the other per-session state.
     */
    public static function transcript_path(string $sessionName): string
    {
        return self::dir() . '/' . self::safe_name($sessionName) . '.jsonl';
    }

    public static function dir(): string
    {
        $dir = Config::sidecar_dir() . '/ollama';

        if (!is_dir($dir)) {
            @mkdir($dir, 0700, true);
        }

        return $dir;
    }

    /**
     * Appends one turn. Content is stored verbatim; the model's own
     * markdown/newlines are the point, and JSON escapes them fine.
     */
    public static function append_turn(string $sessionName, string $role, string $content): void
    {
        $line = json_encode([
            'type' => $role === 'assistant' ? 'assistant' : 'user',
            'message' => ['role' => $role, 'content' => $content],
            'timestamp' => gmdate('Y-m-d\TH:i:s\Z'),
            'sessionId' => $sessionName,
        ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        if ($line === false) {
            return;
        }

        @file_put_contents(self::transcript_path($sessionName), $line . "\n", FILE_APPEND | LOCK_EX);
    }

    /**
     * The conversation as Ollama needs it back on the next turn: Ollama is
     * stateless between HTTP requests, so every prompt carries the whole
     * history.
     *
     * @return array<int, array{role:string, content:string}>
     */
    public static function messages(string $sessionName): array
    {
        $messages = [];

        foreach (self::lines($sessionName) as $decoded) {
            $role = $decoded['message']['role'] ?? null;
            $content = $decoded['message']['content'] ?? null;

            if (is_string($role) && is_string($content) && $content !== '') {
                $messages[] = ['role' => $role, 'content' => $content];
            }
        }

        return $messages;
    }

    /**
     * The final assistant text, which the dashboard row and `--wait` both
     * treat as the session's "last message".
     */
    public static function last_assistant_text(string $sessionName): ?string
    {
        $last = null;

        foreach (self::lines($sessionName) as $decoded) {
            if (($decoded['message']['role'] ?? null) === 'assistant' && is_string($decoded['message']['content'] ?? null)) {
                $last = $decoded['message']['content'];
            }
        }

        return $last;
    }

    public static function has_turns(string $sessionName): bool
    {
        return self::lines($sessionName) !== [];
    }

    /**
     * TranscriptRouter's hook: this session id's file, or null when the id is
     * not one of ours (the router tries several backends in turn, so a
     * non-match must stay silent rather than guess).
     */
    public static function find_transcript_path(string $sessionId): ?string
    {
        if (!str_starts_with($sessionId, 'ollama-headless-')) {
            return null;
        }

        $path = self::transcript_path($sessionId);

        return is_file($path) ? $path : null;
    }

    /**
     * Parsed turns for the transcript view, with each block's own 1-based raw
     * line number so search-result jumps work exactly as they do for Claude.
     *
     * @return array<int, array{line:int, type:string, role:?string, timestamp:?string, blocks:array<int, array<string, mixed>>}>
     */
    public static function read(string $sessionName): array
    {
        $path = self::transcript_path($sessionName);

        if (!is_file($path)) {
            return [];
        }

        $handle = @fopen($path, 'r');
        if ($handle === false) {
            return [];
        }

        $entries = [];
        $lineNumber = 0;

        while (($line = fgets($handle)) !== false) {
            $lineNumber++;
            $entry = TranscriptService::parse_transcript_line(trim($line));

            if ($entry !== null) {
                $entries[] = [
                    'line' => $lineNumber,
                    'type' => $entry['type'],
                    'role' => $entry['role'],
                    'timestamp' => $entry['timestamp'],
                    'blocks' => $entry['blocks'],
                ];
            }
        }

        fclose($handle);

        return $entries;
    }

    /**
     * A crash-or-reboot-sized guard: without pruning, a busy session's file
     * grows for as long as the session is resumed, and every turn replays all
     * of it to the model. Elided middle, so the beginning and the most recent
     * turns (what the next prompt actually needs) survive.
     *
     * @return array<int, array{role:string, content:string}>
     */
    public static function messages_for_prompt(string $sessionName, int $maxMessages = 200, int $maxChars = 120000): array
    {
        $messages = self::messages($sessionName);

        if (count($messages) > $maxMessages) {
            $messages = array_slice($messages, -$maxMessages);
        }

        $total = 0;
        $kept = [];

        foreach (array_reverse($messages) as $message) {
            $total += strlen($message['content']);

            if ($total > $maxChars && $kept !== []) {
                break;
            }

            array_unshift($kept, $message);
        }

        return $kept;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private static function lines(string $sessionName): array
    {
        $path = self::transcript_path($sessionName);

        if (!is_file($path)) {
            return [];
        }

        $raw = @file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        if ($raw === false) {
            return [];
        }

        $decoded = [];

        foreach ($raw as $line) {
            $event = json_decode($line, true);
            if (is_array($event)) {
                $decoded[] = $event;
            }
        }

        return $decoded;
    }

    /**
     * Session names are minted by this app (`ollama-headless-YYYYMMDD-HHMMSS`),
     * but a transcript path is derived from one, so anything that could climb
     * out of its directory is refused rather than trusted.
     */
    private static function safe_name(string $sessionName): string
    {
        $safe = preg_replace('/[^A-Za-z0-9_.-]/', '_', $sessionName);

        return is_string($safe) && $safe !== '' ? $safe : 'unknown';
    }

    public static function delete(string $sessionName): void
    {
        @unlink(self::transcript_path($sessionName));
        @unlink(self::partial_path($sessionName));
        @unlink(self::pid_path($sessionName));
    }

    /**
     * Where a worker writes the reply as it streams. Kept out of the JSONL so
     * an in-flight (or interrupted) answer never shows up as a completed turn.
     */
    public static function partial_path(string $sessionName): string
    {
        return self::dir() . '/' . self::safe_name($sessionName) . '.partial';
    }

    public static function pid_path(string $sessionName): string
    {
        return self::dir() . '/' . self::safe_name($sessionName) . '.pid';
    }

    public static function prompt_path(string $sessionName): string
    {
        return self::dir() . '/' . self::safe_name($sessionName) . '.prompt';
    }

    /**
     * The live worker's pid, or null when no turn is running. A pid file left
     * behind by a killed worker is removed here, so status can fall back to
     * idle instead of reporting a session as busy forever.
     */
    public static function running_pid(string $sessionName): ?int
    {
        $raw = @file_get_contents(self::pid_path($sessionName));
        $pid = $raw === false ? 0 : (int)trim($raw);

        if ($pid <= 0) {
            return null;
        }

        // Signal 0 probes for existence without touching the process.
        if (function_exists('posix_kill') && !@posix_kill($pid, 0)) {
            @unlink(self::pid_path($sessionName));

            return null;
        }

        return $pid;
    }
}
