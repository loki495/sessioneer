<?php

declare(strict_types=1);

namespace HostAgent\Services;

/**
 * Read-only access to Antigravity's own transcript_full.jsonl files (see
 * docs/history/antigravity-adapter-plan.md's "Transcript format" research,
 * live-verified 2026-08-24) - the Antigravity counterpart to
 * TranscriptService, not a shared base class with it. Deliberately a
 * separate, much smaller class rather than teaching TranscriptService a
 * second JSONL schema inline - Antigravity's own entry shape
 * (step_index/source/type/status/content/thinking/tool_calls) shares
 * nothing structurally with Claude Code's (type/message/content-blocks),
 * so a shared implementation would just be two parsers awkwardly forced
 * through one method. Both classes' parse_transcript_line() DO produce the
 * exact same canonical {type, role, timestamp, blocks:[{kind,text,...}]}
 * shape TranscriptView already renders, though - see that shape's own use
 * in TranscriptService for what each field means; nothing in the render
 * layer (App\Views\TranscriptView, src/partials/transcript/*) needed to
 * change for this class to work, only TranscriptRouter (the dispatcher
 * between the two backends).
 *
 * MVP scope only (docs/history/antigravity-adapter-plan.md Phase 4): renders
 * USER_INPUT/PLANNER_RESPONSE/GENERIC(tool result) entries with real
 * pagination/incremental-poll support, matching TranscriptService's own
 * read_transcript_page()/read_transcript_page_since() contracts exactly.
 * CHECKPOINT entries (a context-truncation summary marker with no Claude
 * Code equivalent) are skipped, not rendered. No attachment support yet
 * (read_attachment() is a stub) - no attachment mechanism has been
 * observed in Antigravity's own tool_calls/results yet. No ai-title/todo-
 * list/task-list equivalents either - those are Claude-Code-specific
 * features (TranscriptService::find_latest_ai_title() etc.) with nothing
 * to port yet.
 */
class AntigravityTranscriptService
{
    /**
     * Finds $conversationId's transcript file - unlike Claude Code's
     * TranscriptService::find_transcript_path() (a glob, since Claude Code
     * names its own project directories from an encoded cwd this app
     * doesn't control), Antigravity's own path is fully deterministic from
     * the conversationId alone (see Config::antigravity_transcript_path()) -
     * still validated as UUID-shaped before touching the filesystem, same
     * discipline as the Claude Code side, since this traces back to a
     * sidecar value.
     */
    public static function find_transcript_path(string $conversationId): ?string
    {
        if (preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $conversationId) !== 1) {
            return null;
        }

        $path = Config::antigravity_transcript_path($conversationId);

        return is_file($path) ? $path : null;
    }

    /**
     * One entry per known Antigravity conversation under the brain
     * directory - the Antigravity counterpart to
     * TranscriptService::list_all_transcripts(), same shape plus an
     * 'agent' key so callers can tell the two pools apart.
     *
     * The conversationId IS the session id for Antigravity - stored in
     * 'agent_session_id' so the shared archived-session infrastructure
     * (ArchivedSessionService, SessionRowView, archived-row.php) needs
     * no field-name changes.
     *
     * @return array<int, array{agent_session_id:string, cwd:?string, ai_title:?string, last_activity:int, path:string, agent:string}>
     */
    public static function list_all_transcripts(): array
    {
        $brainDir = Config::home_root() . '/.gemini/antigravity-cli/brain';
        $result   = [];

        foreach (glob($brainDir . '/*', GLOB_ONLYDIR) ?: [] as $convDir) {
            $conversationId = basename($convDir);

            // Same UUID guard as find_transcript_path().
            if (preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $conversationId) !== 1) {
                continue;
            }

            $path = $convDir . '/.system_generated/logs/transcript_full.jsonl';

            if (!is_file($path)) {
                continue;
            }

            $mtime = @filemtime($path);

            // Antigravity transcripts don't embed a cwd field the way
            // Claude Code's own JSONL does - workdir is known at spawn
            // time (sidecar) but not stored in the transcript itself.
            // Returning null here is honest; the resume button is
            // suppressed for null-cwd rows (same rule as Claude Code side).
            $result[] = [
                'agent_session_id' => $conversationId,
                'cwd'               => null,
                'ai_title'          => null,
                'last_activity'     => $mtime !== false ? $mtime : 0,
                'path'              => $path,
                'agent'             => 'antigravity',
            ];
        }

        return $result;
    }

    /**
     * Extracts the real user-typed text from a USER_INPUT entry's raw
     * content, which wraps it in internal `<USER_REQUEST>...</USER_REQUEST>`
     * plus sibling `<ADDITIONAL_METADATA>`/`<USER_SETTINGS_CHANGE>` tags
     * (confirmed live) - only the request itself is worth showing, the
     * rest is bookkeeping never meant for display. Falls back to the raw
     * content if the wrapper tag isn't found (a shape not yet observed),
     * rather than silently dropping a real message.
     */
    private static function extract_user_request_text(string $content): string
    {
        if (preg_match('/<USER_REQUEST>\n?(.*?)\n?<\/USER_REQUEST>/s', $content, $m) === 1) {
            return trim($m[1]);
        }

        return trim($content);
    }

    /**
     * One tool_calls[] entry -> a 'tool_use' block, same shape
     * TranscriptService::summarize_content_block() produces for Claude
     * Code's own tool_use blocks (tool_name/command/file_path - see that
     * method's own docblock for why those specific keys exist: they feed
     * TranscriptView::tool_call_entry_summary()'s one-line collapsed
     * label). Prefers Antigravity's own toolSummary/toolAction fields
     * (present on nearly every real call observed live) for the main
     * text, rather than hand-formatting each tool name's own args the way
     * TranscriptService does for Claude Code's fixed Bash/Write/Edit/Read
     * vocabulary - Antigravity's own tool set hasn't been fully enumerated
     * yet, and these fields are already human-written one-liners.
     * run_command specifically also gets tool_name=Bash + the real command
     * so tool_call_entry_summary() renders "Ran <command>", matching
     * Claude Code's own Bash summary style exactly, for free.
     *
     * @param array<string, mixed> $call
     * @return array{kind:string, text:string, tool_name?:string, command?:string}
     */
    private static function summarize_tool_call(array $call): array
    {
        $name = is_string($call['name'] ?? null) ? $call['name'] : 'tool';
        $args = is_array($call['args'] ?? null) ? $call['args'] : [];

        $summary = is_string($args['toolSummary'] ?? null) && $args['toolSummary'] !== ''
            ? $args['toolSummary']
            : (is_string($args['toolAction'] ?? null) && $args['toolAction'] !== '' ? $args['toolAction'] : $name);

        return array_filter(
            [
                'kind' => 'tool_use',
                'text' => "{$name}: {$summary}",
                'tool_name' => $name === 'run_command' ? 'Bash' : null,
                'command' => $name === 'run_command' && is_string($args['CommandLine'] ?? null) && $args['CommandLine'] !== '' ? $args['CommandLine'] : null,
            ],
            static fn(mixed $v): bool => $v !== null
        );
    }

    /**
     * @return array{type:string, role:?string, timestamp:?string, blocks:array<int, array{kind:string, text:string}>}|null
     */
    public static function parse_transcript_line(string $line): ?array
    {
        $decoded = json_decode($line, true);

        if (!is_array($decoded)) {
            return null;
        }

        $type = (string)($decoded['type'] ?? '');

        // CHECKPOINT (a context-truncation summary) is skipped for v1 - no
        // Claude Code equivalent, and it's internal bookkeeping text aimed
        // at the model, not something Andres needs to read - see this
        // class's own docblock and docs/history/antigravity-adapter-plan.md Phase 4.
        if ($type === '' || $type === 'CHECKPOINT') {
            return null;
        }

        $timestamp = is_string($decoded['created_at'] ?? null) ? $decoded['created_at'] : null;
        $blocks = [];

        if ($type === 'USER_INPUT') {
            $rawContent = is_string($decoded['content'] ?? null) ? $decoded['content'] : '';
            $text = self::extract_user_request_text($rawContent);

            if ($text !== '') {
                $blocks[] = ['kind' => 'text', 'text' => $text];
            }
        } elseif ($type === 'PLANNER_RESPONSE') {
            $content = $decoded['content'] ?? null;

            // Thinking is never rendered as a chat entry, same convention
            // TranscriptService::parse_transcript_line() already uses for
            // Claude Code's own thinking blocks - it's a live, transient
            // "is it thinking right now" signal (SessionStatusStore),
            // never a persisted transcript entry.
            if (is_string($content) && trim($content) !== '') {
                $blocks[] = ['kind' => 'text', 'text' => $content];
            }

            $toolCalls = is_array($decoded['tool_calls'] ?? null) ? $decoded['tool_calls'] : [];

            foreach ($toolCalls as $call) {
                if (is_array($call)) {
                    $blocks[] = self::summarize_tool_call($call);
                }
            }
        } elseif ($type === 'GENERIC' && ($decoded['source'] ?? null) === 'MODEL') {
            // A tool-result entry - confirmed live shape (see this class's
            // own docblock). role:'user' matches Claude Code's own
            // tool_result-carries-role-user convention (TranscriptView::
            // entry_color_kind()'s own docblock) - harmless either way
            // here since a text-less tool_result block colors by its
            // block kind regardless of the entry's literal role, but kept
            // consistent with the established convention.
            $content = is_string($decoded['content'] ?? null) ? $decoded['content'] : '';

            if (trim($content) !== '') {
                $blocks[] = ['kind' => 'tool_result', 'text' => $content];
            }
        } else {
            return null;
        }

        if ($blocks === []) {
            return null;
        }

        foreach ($blocks as &$block) {
            if (strlen($block['text']) > TranscriptService::TRANSCRIPT_BLOCK_HARD_CAP_LENGTH) {
                $block['text'] = substr($block['text'], 0, TranscriptService::TRANSCRIPT_BLOCK_HARD_CAP_LENGTH) . "\n… (truncated)";
            }
        }
        unset($block);

        return [
            'type' => $type,
            'role' => $type === 'PLANNER_RESPONSE' ? 'assistant' : 'user',
            'timestamp' => $timestamp,
            'blocks' => $blocks,
        ];
    }

    /**
     * Same contract as TranscriptService::read_transcript_page() - see
     * that method's own docblock for the full paging behavior
     * ($before/$limit/$untilRealUserMessage, next_before/has_more). No
     * exit-plan-mode id-map equivalent needed here (Antigravity has no
     * ExitPlanMode-shaped tool), so this is a simpler walk.
     *
     * @return array{ok:bool, entries:array<int, array>, next_before:?int, has_more:bool, message?:string}
     */
    public static function read_transcript_page(string $path, ?int $before, int $limit, bool $untilRealUserMessage = false): array
    {
        $lines = @file($path, FILE_IGNORE_NEW_LINES);

        if ($lines === false) {
            return ['ok' => false, 'entries' => [], 'next_before' => null, 'has_more' => false, 'message' => 'Transcript file could not be read'];
        }

        $totalLines = count($lines);
        $upperBound = $before !== null ? max(0, min($before - 1, $totalLines)) : $totalLines;

        $entries = [];
        $index = $upperBound;
        $effectiveLimit = $untilRealUserMessage ? TranscriptService::UNTIL_USER_MESSAGE_MAX_ENTRIES : $limit;
        $foundRealUserMessage = false;

        while ($index > 0 && count($entries) < $effectiveLimit && !$foundRealUserMessage) {
            $index--;
            $parsed = self::parse_transcript_line($lines[$index]);

            if ($parsed !== null) {
                $entries[] = $parsed + ['line' => $index + 1];

                if ($untilRealUserMessage && $parsed['role'] === 'user' && $parsed['type'] === 'USER_INPUT') {
                    $foundRealUserMessage = true;
                }
            }
        }

        $entries = array_reverse($entries);

        return [
            'ok' => true,
            'entries' => $entries,
            'next_before' => $index > 0 ? $index + 1 : null,
            'has_more' => $index > 0,
        ];
    }

    /**
     * Same contract as TranscriptService::read_transcript_page_since().
     *
     * @return array{ok:bool, entries:array<int, array>, message?:string}
     */
    public static function read_transcript_page_since(string $path, int $afterLine, int $limit): array
    {
        $lines = @file($path, FILE_IGNORE_NEW_LINES);

        if ($lines === false) {
            return ['ok' => false, 'entries' => [], 'message' => 'Transcript file could not be read'];
        }

        $totalLines = count($lines);
        $entries = [];
        $index = max(0, $afterLine);

        while ($index < $totalLines && count($entries) < $limit) {
            $parsed = self::parse_transcript_line($lines[$index]);

            if ($parsed !== null) {
                $entries[] = $parsed + ['line' => $index + 1];
            }

            $index++;
        }

        return ['ok' => true, 'entries' => $entries];
    }

    /**
     * No attachment mechanism has been observed in Antigravity's own
     * tool_calls/results yet (see this class's own docblock) - an honest
     * "not supported" rather than pretending to find one.
     *
     * @return array{ok:bool, message?:string}
     */
    public static function read_attachment(string $path, int $line, string $fileUuid): array
    {
        return ['ok' => false, 'message' => 'Attachments are not supported for Antigravity sessions yet'];
    }
}
