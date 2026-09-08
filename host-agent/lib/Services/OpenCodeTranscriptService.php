<?php

declare(strict_types=1);

namespace HostAgent\Services;

/**
 * Read-only access to OpenCode's own SQLite-backed transcripts
 * (~/.local/share/opencode/opencode.db, tables session/message/part) - the
 * OpenCode counterpart to TranscriptService (JSONL) and
 * AntigravityTranscriptService (JSONL). Deliberately a separate class
 * rather than teaching either of the other two a third storage shape -
 * OpenCode's storage (one session row, many message rows, many part rows
 * per message, data as JSON blobs) shares nothing structurally with either
 * of the other two, so a shared implementation would just be three parsers
 * forced through one method.
 *
 * Both this class's and AntigravityTranscriptService's parse methods produce
 * the exact same canonical {type, role, timestamp, blocks:[{kind,text,...}]}
 * shape TranscriptView already renders, so nothing in the render layer needs
 * to change - only TranscriptRouter (the dispatcher between backends).
 *
 * MVP scope: renders user text, assistant text, and tool calls/results
 * (part.type 'text'/'tool', including the tool's own call + output stored
 * together in one part's state). File parts, reasoning blocks, and synthetic
 * echoes are skipped or rendered minimally. No attachment support yet
 * (read_attachment() is a stub).
 */
class OpenCodeTranscriptService
{
    /**
     * OpenCode's own session id shape: ses_<alphanumeric> (e.g.
     * ses_fc894e9f0ffeltA15imtgaZocS), distinct from Claude Code / Antigravity's
     * UUID-with-dashes shape, so routing by shape alone is reliable without
     * needing the sidecar's agent column at every call site.
     */
    private const SESSION_ID_PATTERN = '/^ses_[a-zA-Z0-9]+$/';

    /**
     * Validates $sessionId shape and checks that a session row with that id
     * actually exists in opencode.db. Returns the id itself as the "path"
     * (opencode has no filesystem transcript path like the other two agents
     * - the id IS the transcript identifier). This keeps TranscriptRouter's
     * contract (find_transcript_path returns a string path) satisfied without
     * needing a separate find method for opencode - the router just carries
     * the id through to read_transcript_page().
     */
    public static function find_transcript_path(string $sessionId): ?string
    {
        if (preg_match(self::SESSION_ID_PATTERN, $sessionId) !== 1) {
            return null;
        }

        $pdo = self::open_db_readonly();

        if ($pdo === null) {
            return null;
        }

        $stmt = $pdo->prepare('SELECT id FROM session WHERE id = ? LIMIT 1');
        $stmt->execute([$sessionId]);

        return $stmt->fetchColumn() !== false ? $sessionId : null;
    }

    /**
     * Returns true if $sessionId or $path looks like an OpenCode session
     * (ses_*). Used by TranscriptRouter to route without touching the DB.
     * Shape alone is stable: ses_* never collides with Claude Code's or
     * Antigravity's UUID shapes.
     */
    public static function is_opencode_id(string $sessionId): bool
    {
        return preg_match(self::SESSION_ID_PATTERN, $sessionId) === 1;
    }

    /**
     * Open opencode.db read-only, WAL-safe, with busy timeout. Returns null
     * if the DB file doesn't exist or can't be opened (not an error for a
     * missing session - just means "no transcript here").
     *
     * Opened with SQLITE_OPEN_READONLY so a concurrent live TUI writer is
     * never blocked. PDO's default error mode is silently forgiving for
     * missing-file opens, hence explicit exception mode + file existence
     * check.
     */
    private static function open_db_readonly(): ?\PDO
    {
        $path = Config::opencode_db_path();

        if (!is_file($path)) {
            return null;
        }

        try {
            $pdo = new \PDO('sqlite:' . $path, null, null, [
                \PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION,
                \PDO::SQLITE_ATTR_OPEN_FLAGS => \PDO::SQLITE_OPEN_READONLY,
            ]);
            $pdo->exec('PRAGMA busy_timeout=5000');

            return $pdo;
        } catch (\PDOException $e) {
            return null;
        }
    }

    /**
     * Summarizes a tool part into one or two canonical blocks.
     *
     * OpenCode stores a tool call + its result together in one part's
     * `state` (not split across two entries the way Claude Code splits
     * tool_use/tool_result, or Antigravity's PLANNER_RESPONSE vs GENERIC
     * tool-result). So one part can produce two blocks: a tool_use summary
     * (the call) plus a tool_result (the output), kept as separate blocks
     * so TranscriptView's own positional pairing / collapsible grouping still
     * works rather than jamming call+output into one long string.
     *
     * @param array<string, mixed> $partData
     * @return array<int, array{kind:string, text:string, tool_name?:string, file_path?:string, command?:string, description?:string}>
     */
    private static function tool_part_to_blocks(array $partData): array
    {
        $toolName = is_string($partData['tool'] ?? null) && $partData['tool'] !== '' ? $partData['tool'] : 'tool';
        $state = is_array($partData['state'] ?? null) ? $partData['state'] : [];
        $blocks = [];

        // A `question` tool is the opencode equivalent of Claude Code's
        // AskUserQuestion. Render it as a distinct question card (question
        // text + options) rather than the generic `question: {json}` tool_use
        // summary, so it reads like the blocked-prompt card instead of a raw
        // dump. The chosen answer (state.output) is surfaced when present.
        if ($toolName === 'question') {
            $input = is_array($state['input'] ?? null) ? $state['input'] : [];
            $questions = is_array($input['questions'] ?? null) ? $input['questions'] : [];
            $first = $questions[0] ?? [];
            $questionText = is_string($first['question'] ?? null) ? $first['question'] : (is_string($input['question'] ?? null) ? $input['question'] : 'Waiting on input');
            $header = is_string($first['header'] ?? null) ? $first['header'] : (is_string($input['header'] ?? null) ? $input['header'] : '');
            $rawOptions = is_array($first['options'] ?? null) ? $first['options'] : (is_array($input['options'] ?? null) ? $input['options'] : []);
            $options = [];

            foreach ($rawOptions as $idx => $opt) {
                if (is_string($opt)) {
                    $options[] = ['number' => $idx + 1, 'label' => $opt];
                } elseif (is_array($opt) && is_string($opt['label'] ?? null)) {
                    $options[] = ['number' => $idx + 1, 'label' => $opt['label']];
                } elseif (is_array($opt) && is_string($opt['value'] ?? null)) {
                    $options[] = ['number' => $idx + 1, 'label' => $opt['value']];
                }
            }

            // opencode's question UI always offers a free-text route alongside
            // the numbered options (the serve's `custom` flag; default on) -
            // surface it as the same "Type something" option Sessioneer's
            // blocked-prompt template uses, so the free-text affordance shows
            // for opencode questions just like it does elsewhere.
            $custom = (bool)($first['custom'] ?? true);
            if ($custom && !self::question_options_include_freetext($options)) {
                $options[] = ['number' => count($options) + 1, 'label' => 'Type something'];
            }

            $status = is_string($state['status'] ?? null) ? $state['status'] : '';
            $output = $state['output'] ?? null;
            $answer = null;

            if (is_string($output) && trim($output) !== '') {
                // opencode formats a question answer as
                //   User has answered your questions: "..."="..."
                // Surface just the chosen answer (the part after the final
                // `="`), not the whole wrapper line.
                if (preg_match('/="(.*)"\s*$/s', trim($output), $m)) {
                    $answer = $m[1];
                } else {
                    $answer = trim($output);
                }
            }

            $blocks[] = [
                'kind' => 'question',
                'text' => $questionText !== '' ? $questionText : 'Waiting on input',
                'question' => $questionText,
                'questions' => $questions,
                'header' => $header,
                'options' => $options,
                'pending' => in_array($status, ['running', 'pending'], true),
                'answer' => $answer,
                'tool_name' => 'question',
            ];

            return $blocks;
        }

        $input = is_array($state['input'] ?? null) ? $state['input'] : null;

        if ($input !== null) {
            $summary = $toolName . ': ' . json_encode($input);
            // Keep the summary human-readable: prefer a few known fields if present,
            // otherwise fall back to the JSON. Overlong summaries are capped via the same
            // hard cap downstream.
            if (isset($input['filePath'])) {
                $summary = $toolName . '(' . $input['filePath'] . ')';
            } elseif (isset($input['url'])) {
                $summary = $toolName . '(' . $input['url'] . ')';
            } elseif (isset($input['command'])) {
                $summary = $toolName . '(' . $input['command'] . ')';
            } elseif (isset($input['query'])) {
                $summary = $toolName . '(' . $input['query'] . ')';
            }

            // Mirror the metadata fields TranscriptView::tool_call_entry_summary()
            // uses for Claude tool calls — file_path, command, description — so
            // the one-line summary inside a <details> shows e.g.
            // "write src/foo.php" instead of "write({filePath:...})".
            $block = array_filter([
                'kind' => 'tool_use',
                'text' => $summary,
                'tool_name' => $toolName,
                'file_path' => in_array($toolName, ['write', 'read', 'edit'], true) && is_string($input['filePath'] ?? null) && $input['filePath'] !== ''
                    ? $input['filePath']
                    : null,
                'command' => in_array($toolName, ['bash', 'execute', 'run_command'], true) && is_string($input['command'] ?? null) && $input['command'] !== ''
                    ? $input['command']
                    : null,
                'description' => is_string($input['description'] ?? null) && $input['description'] !== ''
                    ? $input['description']
                    : null,
            ], static fn(mixed $v): bool => $v !== null);

            $blocks[] = $block;
        } else {
            // Tool part with no input yet (pending) - still surface it rather than hide it.
            $blocks[] = ['kind' => 'tool_use', 'text' => $toolName, 'tool_name' => $toolName];
        }

        $output = $state['output'] ?? null;

        if (is_string($output) && trim($output) !== '') {
            $blocks[] = ['kind' => 'tool_result', 'text' => $output];
        }

        return $blocks;
    }

    /**
     * True if an option list already carries a free-text affordance (an
     * option whose label reads as "type something") - so a question that
     * explicitly includes one doesn't get a duplicate appended.
     * @param array<int, array{number:int, label:string}> $options
     */
    private static function question_options_include_freetext(array $options): bool
    {
        foreach ($options as $opt) {
            if (stripos($opt['label'], 'type something') !== false) {
                return true;
            }
        }

        return false;
    }

    /**
     * One DB row (message + its parts) -> one or more canonical entries.
     *
     * OpenCode stores a session as many messages, each with many parts. A
     * message's own `data.role` says user/assistant, and parts carry the
     * actual content. We produce one canonical entry per message (not per
     * part) — a message with text + a tool call becomes {role, blocks:
     * [text, tool_use]} in one entry, so TranscriptView's own grouping keeps
     * tool_use/tool_result pairing within one message's blocks rather than
     * splitting them into separate entries.
     *
     * Skips synthetic echoes ("Called the Read tool with ...") and
     * step-start/step-finish markers — those are opencode SDK bookkeeping,
     * not user-visible content. File parts are skipped for MVP (no attachment
     * mechanism observed that carries displayable text beyond the file path).
     *
     * @param array<string, mixed> $messageData the `data` JSON column of a message row
     * @param array<int, array<string, mixed>> $partsData list of `data` JSONs for parts in this message
     * @return array{type:string, role:?string, timestamp:?string, blocks:array<int, array{kind:string, text:string}>}|null null when nothing renderable remains after filtering
     */
    private static function message_to_entry(array $messageData, array $partsData): ?array
    {
        $role = is_string($messageData['role'] ?? null) ? $messageData['role'] : null;
        $timeEntry = $messageData['time'] ?? null;
        $timestamp = null;

        if (is_array($timeEntry) && is_int($timeEntry['created'] ?? null)) {
            // opencode stores created as int milliseconds since epoch
            $timestamp = gmdate('c', (int)($timeEntry['created'] / 1000));
        } elseif (is_string($timeEntry['created'] ?? null)) {
            $timestamp = $timeEntry['created'];
        }

        $type = $role === 'user' ? 'USER_INPUT' : ($role === 'assistant' ? 'PLANNER_RESPONSE' : 'GENERIC');
        $blocks = [];

        foreach ($partsData as $partData) {
            $partType = is_string($partData['type'] ?? null) ? $partData['type'] : '';

            if ($partType === 'text') {
                // Skip synthetic echoes: "Called the Read tool with following input: ..." etc.
                if (($partData['synthetic'] ?? false) === true) {
                    continue;
                }

                $text = is_string($partData['text'] ?? null) ? $partData['text'] : '';

                if (trim($text) !== '') {
                    $blocks[] = ['kind' => 'text', 'text' => $text];
                }
            } elseif ($partType === 'tool') {
                foreach (self::tool_part_to_blocks($partData) as $block) {
                    $blocks[] = $block;
                }
            } elseif ($partType === 'file') {
                // File attachment parts reference a file but carry no displayable text beyond
                // filename/path — skipped for MVP (read_attachment is a stub).
                continue;
            } elseif ($partType === 'reasoning') {
                // Reasoning/thinking is transient like Claude Code's thinking blocks —
                // not persisted as chat-visible content.
                continue;
            } elseif ($partType === 'step-start' || $partType === 'step-finish') {
                // Bookkeeping markers: "{type:step-start}" / ability to track streaming —
                // not user-visible, never rendered as chat entries.
                continue;
            } else {
                // Unknown part type — try to surface its text field if any, otherwise skip
                // rather than hide content opencode might carry under a new type in future.
                if (is_string($partData['text'] ?? null) && trim($partData['text']) !== '') {
                    $blocks[] = ['kind' => 'text', 'text' => $partData['text']];
                }
            }
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
            'role' => $role,
            'timestamp' => $timestamp,
            'blocks' => $blocks,
        ];
    }

    /**
     * Same contract as TranscriptService::read_transcript_page() — see that
     * method's own docblock for paging behavior ($before/$limit/
     * $untilRealUserMessage, next_before/has_more).
     *
     * @return array{ok:bool, entries:array<int, array>, next_before:?int, has_more:bool, message?:string}
     */
    public static function read_transcript_page(string $path, ?int $before, int $limit, bool $untilRealUserMessage = false): array
    {
        $pdo = self::open_db_readonly();

        if ($pdo === null) {
            return ['ok' => false, 'entries' => [], 'next_before' => null, 'has_more' => false, 'message' => 'Transcript database could not be read'];
        }

        if (!self::is_opencode_id($path)) {
            return ['ok' => false, 'entries' => [], 'next_before' => null, 'has_more' => false, 'message' => 'Not an OpenCode session id'];
        }

        try {
            // Fetch all message ids + time_created for this session, ordered newest-first for backward paging.
            $stmt = $pdo->prepare('SELECT id, data, time_created FROM message WHERE session_id = ? ORDER BY time_created ASC');
            $stmt->execute([$path]);
            $allMessages = $stmt->fetchAll(\PDO::FETCH_ASSOC);

            if ($allMessages === false || !is_array($allMessages)) {
                return ['ok' => false, 'entries' => [], 'next_before' => null, 'has_more' => false, 'message' => 'Failed to read messages'];
            }

            // Filter to renderable messages (those that produce at least one block).
            // Build parts index first: message_id => list of part data
            $messageIds = array_column($allMessages, 'id');
            $partsByMessage = [];

            if ($messageIds !== []) {
                // Chunk for SQLite IN clause safety (not needed for small sessions but cheap to do).
                $chunkSize = 500;
                for ($c = 0; $c < count($messageIds); $c += $chunkSize) {
                    $chunk = array_slice($messageIds, $c, $chunkSize);
                    $placeholders = implode(',', array_fill(0, count($chunk), '?'));
                    $partStmt = $pdo->prepare("SELECT message_id, data FROM part WHERE message_id IN ({$placeholders}) ORDER BY time_created ASC");
                    $partStmt->execute($chunk);
                    $partRows = $partStmt->fetchAll(\PDO::FETCH_ASSOC) ?: [];
                    foreach ($partRows as $row) {
                        $data = json_decode((string)($row['data'] ?? ''), true);
                        if (is_array($data)) {
                            $partsByMessage[$row['message_id']][] = $data;
                        }
                    }
                }
            }

            $renderable = [];
            foreach ($allMessages as $idx => $msgRow) {
                $messageData = json_decode((string)($msgRow['data'] ?? ''), true);
                if (!is_array($messageData)) {
                    continue;
                }
                $parts = $partsByMessage[$msgRow['id']] ?? [];
                $entry = self::message_to_entry($messageData, $parts);
                if ($entry !== null) {
                    // line is 1-indexed message position (like AntigravityTranscriptService's line number)
                    $entry['line'] = $idx + 1;
                    $renderable[] = $entry;
                }
            }

            $totalRenderable = count($renderable);
            $upperBound = $before !== null ? max(0, min($before - 1, $totalRenderable)) : $totalRenderable;
            $effectiveLimit = $untilRealUserMessage ? TranscriptService::UNTIL_USER_MESSAGE_MAX_ENTRIES : $limit;
            $entries = [];
            $foundRealUserMessage = false;
            $index = $upperBound;

            while ($index > 0 && count($entries) < $effectiveLimit && !$foundRealUserMessage) {
                $index--;
                $entry = $renderable[$index];
                $entries[] = $entry;
                if ($untilRealUserMessage && ($entry['role'] ?? null) === 'user') {
                    $foundRealUserMessage = true;
                }
            }

            $entries = array_reverse($entries);

            return [
                'ok' => true,
                'entries' => $entries,
                'next_before' => $index > 0 ? $index + 1 : null,
                'has_more' => $index > 0,
            ];
        } catch (\PDOException $e) {
            return ['ok' => false, 'entries' => [], 'next_before' => null, 'has_more' => false, 'message' => 'Database read failed'];
        }
    }

    /**
     * Same contract as TranscriptService::read_transcript_page_since().
     *
     * @return array{ok:bool, entries:array<int, array>, message?:string}
     */
    public static function read_transcript_page_since(string $path, int $afterLine, int $limit): array
    {
        $pdo = self::open_db_readonly();

        if ($pdo === null) {
            return ['ok' => false, 'entries' => [], 'message' => 'Transcript database could not be read'];
        }

        if (!self::is_opencode_id($path)) {
            return ['ok' => false, 'entries' => [], 'message' => 'Not an OpenCode session id'];
        }

        try {
            $stmt = $pdo->prepare('SELECT id, data, time_created FROM message WHERE session_id = ? ORDER BY time_created ASC');
            $stmt->execute([$path]);
            $allMessages = $stmt->fetchAll(\PDO::FETCH_ASSOC);

            if ($allMessages === false || !is_array($allMessages)) {
                return ['ok' => false, 'entries' => [], 'message' => 'Failed to read messages'];
            }

            $messageIds = array_column($allMessages, 'id');
            $partsByMessage = [];

            if ($messageIds !== []) {
                $chunkSize = 500;
                for ($c = 0; $c < count($messageIds); $c += $chunkSize) {
                    $chunk = array_slice($messageIds, $c, $chunkSize);
                    $placeholders = implode(',', array_fill(0, count($chunk), '?'));
                    $partStmt = $pdo->prepare("SELECT message_id, data FROM part WHERE message_id IN ({$placeholders}) ORDER BY time_created ASC");
                    $partStmt->execute($chunk);
                    $partRows = $partStmt->fetchAll(\PDO::FETCH_ASSOC) ?: [];
                    foreach ($partRows as $row) {
                        $data = json_decode((string)($row['data'] ?? ''), true);
                        if (is_array($data)) {
                            $partsByMessage[$row['message_id']][] = $data;
                        }
                    }
                }
            }

            $renderable = [];
            foreach ($allMessages as $idx => $msgRow) {
                $messageData = json_decode((string)($msgRow['data'] ?? ''), true);
                if (!is_array($messageData)) {
                    continue;
                }
                $parts = $partsByMessage[$msgRow['id']] ?? [];
                $entry = self::message_to_entry($messageData, $parts);
                if ($entry !== null) {
                    $entry['line'] = $idx + 1;
                    $renderable[] = $entry;
                }
            }

            $entries = [];
            foreach ($renderable as $entry) {
                if (($entry['line'] ?? 0) <= $afterLine) {
                    continue;
                }

                $entries[] = $entry;
                if (count($entries) >= $limit) {
                    break;
                }
            }

            return ['ok' => true, 'entries' => $entries];
        } catch (\PDOException $e) {
            return ['ok' => false, 'entries' => [], 'message' => 'Database read failed'];
        }
    }

    /**
     * The session's own title (session.title), as set by OpenCode itself
     * after the first prompt — the closest equivalent to Claude Code's
     * ai-title line. Returns null when the session doesn't exist or has
     * no title yet (falls through to the normal workdir/name cascade in
     * SessionService::session_title()).
     */
    public static function find_session_title(string $sessionId): ?string
    {
        if (!self::is_opencode_id($sessionId)) {
            return null;
        }

        $pdo = self::open_db_readonly();

        if ($pdo === null) {
            return null;
        }

        $stmt = $pdo->prepare('SELECT title FROM session WHERE id = ? LIMIT 1');
        $stmt->execute([$sessionId]);
        $title = $stmt->fetchColumn();

        return is_string($title) && trim($title) !== '' ? $title : null;
    }

    /**
     * The session's own directory (session.directory), used when archived
     * history needs to show the real cwd for an OpenCode session id.
     */
    public static function find_session_cwd(string $sessionId): ?string
    {
        if (!self::is_opencode_id($sessionId)) {
            return null;
        }

        $pdo = self::open_db_readonly();

        if ($pdo === null) {
            return null;
        }

        $stmt = $pdo->prepare('SELECT directory FROM session WHERE id = ? LIMIT 1');
        $stmt->execute([$sessionId]);
        $cwd = $stmt->fetchColumn();

        return is_string($cwd) && trim($cwd) !== '' ? $cwd : null;
    }

    /**
     * Reactive binding: finds the most recent OpenCode session row whose
     * directory matches $workdir and whose creation time is at or after
     * $spawnedAt (the tmux session's birth). This is the opencode
     * equivalent of Antigravity's pre_invocation.php reactive bind (see
     * .ai/QUESTIONS.md Q1.1) — opencode creates no DB row at spawn time,
     * only after the first prompt, so the sidecar starts with
     * agent_session_id=null and learns the real ses_* on the next poll.
     *
     * Skips ids already bound to another live oc-* sidecar to avoid two
     * tmux sessions fighting over the same transcript (same guard as
     * SessionLifecycleService::agent_session_id_already_live()).
     */
    public static function find_session_for_workdir(string $workdir, int $spawnedAt): ?string
    {
        $pdo = self::open_db_readonly();

        if ($pdo === null) {
            return null;
        }

        // Collect already-bound ses_* ids to avoid collisions
        $boundIds = [];
        try {
            $sidecarPdo = \HostAgent\Stores\SqliteDb::connect(Config::sessions_sqlite_path(), \HostAgent\Stores\SqliteDb::sessions_schema());
            $rows = $sidecarPdo->query("SELECT agent_session_id FROM sidecars WHERE agent = 'opencode' AND agent_session_id IS NOT NULL AND agent_session_id != ''")->fetchAll(\PDO::FETCH_COLUMN);
            foreach ($rows as $id) {
                if (is_string($id) && self::is_opencode_id($id)) {
                    $boundIds[$id] = true;
                }
            }
        } catch (\Throwable $e) {
            // Best-effort: if sidecar DB is unreachable, just skip the dedup
        }

        $stmt = $pdo->prepare('SELECT id, time_created FROM session WHERE directory = ? ORDER BY time_created DESC LIMIT 10');
        $stmt->execute([$workdir]);
        $candidates = $stmt->fetchAll(\PDO::FETCH_ASSOC) ?: [];

        foreach ($candidates as $row) {
            $id = is_string($row['id'] ?? null) ? $row['id'] : null;
            $created = isset($row['time_created']) ? (int)$row['time_created'] : 0;

            if ($id === null || !self::is_opencode_id($id)) {
                continue;
            }

            if (isset($boundIds[$id])) {
                continue;
            }

            // Only consider sessions created at/after the tmux session's birth
            if ($created > 0 && (int)($created / 1000) < $spawnedAt) {
                continue;
            }

            return $id;
        }

        return null;
    }

    /**
     * Finds a currently-pending `question` tool call (status=running) for the
     * given opencode session — the opencode equivalent of Claude Code's
     * blocked-on-AskUserQuestion. Used by SessionService to surface a
     * blocked prompt without relying on pane parsing (which is blank for
     * opencode's TUI idle state, but does show the question when blocked —
     * verified live 2026-08-25 on ses_fc8124).
     *
    /**
     * @return array{question:string, header:?string, options:array<int, array{number:int, label:string}>}|null
     */
    public static function find_pending_question(string $sessionId): ?array
    {
        if (!self::is_opencode_id($sessionId)) {
            return null;
        }

        $pdo = self::open_db_readonly();

        if ($pdo === null) {
            return null;
        }

        $stmt = $pdo->prepare("SELECT data FROM part WHERE session_id = ? AND json_extract(data, '$.type') = 'tool' AND json_extract(data, '$.tool') = 'question' ORDER BY time_updated DESC LIMIT 5");
        $stmt->execute([$sessionId]);
        $rows = $stmt->fetchAll(\PDO::FETCH_ASSOC) ?: [];

        // Staleness guard (found live 2026-08-25, cloning session): opencode
        // leaves a question tool's state.status as "running" even after the
        // question is answered, but the NEWER re-ask of the same question is
        // the one that gets flipped to "completed". The naive "first
        // running/pending by recency" picks up an OLD stale question from
        // earlier in the transcript while the newest (answered) one exists.
        // Only the NEWEST question tool (by time_updated) is authoritative:
        // if it's running/pending the session is blocked on it; if it's
        // completed the session is NOT blocked, no matter what earlier
        // "running" remnants say.
        foreach ($rows as $row) {
            $data = json_decode((string)($row['data'] ?? ''), true);
            if (!is_array($data)) {
                continue;
            }

            $state = is_array($data['state'] ?? null) ? $data['state'] : [];
            $status = is_string($state['status'] ?? null) ? $state['status'] : '';

            // The newest question tool is authoritative regardless of state;
            // if it's not currently blocking, there's no blocked question.
            if ($status !== 'running' && $status !== 'pending') {
                return null;
            }

            $input = is_array($state['input'] ?? null) ? $state['input'] : (is_array($data['input'] ?? null) ? $data['input'] : []);

            // opencode question tool input shape: {questions: [{header, question, options: [{label,value}]}]}
            // Also seen: {header, question, options} at top level
            $questions = is_array($input['questions'] ?? null) ? $input['questions'] : null;

            if ($questions !== null && count($questions) > 0) {
                $q = $questions[0];
                $questionText = is_string($q['question'] ?? null) ? $q['question'] : (is_string($input['question'] ?? null) ? $input['question'] : '');
                $header = is_string($q['header'] ?? null) ? $q['header'] : null;
                $rawOptions = is_array($q['options'] ?? null) ? $q['options'] : (is_array($input['options'] ?? null) ? $input['options'] : []);
                $options = [];

                foreach ($rawOptions as $idx => $opt) {
                    if (is_string($opt)) {
                        $options[] = ['number' => $idx + 1, 'label' => $opt];
                    } elseif (is_array($opt) && is_string($opt['label'] ?? null)) {
                        $options[] = ['number' => $idx + 1, 'label' => $opt['label']];
                    } elseif (is_array($opt) && is_string($opt['value'] ?? null)) {
                        $options[] = ['number' => $idx + 1, 'label' => $opt['value']];
                    }
                }

                if ($questionText !== '' || $options !== []) {
                    return ['question' => $questionText, 'header' => $header, 'options' => $options];
                }
            }

            // Fallback: top-level question/options
            $questionText = is_string($input['question'] ?? null) ? $input['question'] : '';
            $rawOptions = is_array($input['options'] ?? null) ? $input['options'] : [];
            $options = [];

            foreach ($rawOptions as $idx => $opt) {
                if (is_string($opt)) {
                    $options[] = ['number' => $idx + 1, 'label' => $opt];
                } elseif (is_array($opt) && is_string($opt['label'] ?? null)) {
                    $options[] = ['number' => $idx + 1, 'label' => $opt['label']];
                }
            }

            if ($questionText !== '' || $options !== []) {
                return ['question' => $questionText, 'header' => null, 'options' => $options];
            }
        }

        return null;
    }

    /**
     * No attachment mechanism has been observed in OpenCode's own parts yet
     * beyond file reference parts (which carry only a path). An honest
     * "not supported" rather than guessing.
     *
     * @return array{ok:bool, message?:string}
     */
    public static function read_attachment(string $path, int $line, string $fileUuid): array
    {
        return ['ok' => false, 'message' => 'Attachments are not supported for OpenCode sessions yet'];
    }

    /**
     * All OpenCode sessions from opencode.db, as a list of transcript
     * summaries — the OpenCode counterpart to TranscriptService::
     * list_all_transcripts() and AntigravityTranscriptService::
     * list_all_transcripts(). Used by the dashboard-wide search to iterate
     * every session's content.
     *
     * @return array<int, array{session_id:string, title:?string, cwd:?string, last_activity:int}>
     */
    public static function list_all_transcripts(): array
    {
        $pdo = self::open_db_readonly();

        if ($pdo === null) {
            return [];
        }

        try {
            $stmt = $pdo->query('SELECT id, title, time_created FROM session ORDER BY time_created DESC');

            if ($stmt === false) {
                return [];
            }

            $rows = $stmt->fetchAll(\PDO::FETCH_ASSOC);

            if (!is_array($rows)) {
                return [];
            }

            $results = [];

            foreach ($rows as $row) {
                $id = is_string($row['id'] ?? null) ? $row['id'] : null;

                if ($id === null || !self::is_opencode_id($id)) {
                    continue;
                }

                $createdAt = is_int($row['time_created'] ?? null) ? (int)$row['time_created'] : 0;

                $results[] = [
                    'session_id' => $id,
                    'title' => is_string($row['title'] ?? null) ? $row['title'] : null,
                    'cwd' => null,
                    'last_activity' => $createdAt,
                ];
            }

            return $results;
        } catch (\PDOException $e) {
            return [];
        }
    }

    /**
     * Searches one OpenCode session's messages and parts for text matching
     * $query — the OpenCode counterpart to TranscriptService::
     * search_transcript_file(). Returns matches newest-first with line
     * numbers, snippets, and role/kind metadata matching the same shape
     * the dashboard search UI expects.
     *
     * @return array<int, array{line:int, snippet:string, role:?string, kind:string, timestamp:?int}>
     */
    public static function search_transcript(string $sessionId, string $query, int $maxMatches): array
    {
        $trimmedQuery = trim($query);

        if ($trimmedQuery === '' || !self::is_opencode_id($sessionId)) {
            return [];
        }

        $pdo = self::open_db_readonly();

        if ($pdo === null) {
            return [];
        }

        try {
            // Fetch all messages for this session, newest-first for search.
            $stmt = $pdo->prepare('SELECT id, data, time_created FROM message WHERE session_id = ? ORDER BY time_created DESC');
            $stmt->execute([$sessionId]);
            $messages = $stmt->fetchAll(\PDO::FETCH_ASSOC);

            if (!is_array($messages)) {
                return [];
            }

            // Build parts index: message_id => list of part data.
            $messageIds = array_column($messages, 'id');
            $partsByMessage = [];

            if ($messageIds !== []) {
                $chunkSize = 500;

                for ($c = 0; $c < count($messageIds); $c += $chunkSize) {
                    $chunk = array_slice($messageIds, $c, $chunkSize);
                    $placeholders = implode(',', array_fill(0, count($chunk), '?'));
                    $partStmt = $pdo->prepare("SELECT message_id, data FROM part WHERE message_id IN ({$placeholders}) ORDER BY time_created ASC");
                    $partStmt->execute($chunk);
                    $partRows = $partStmt->fetchAll(\PDO::FETCH_ASSOC) ?: [];

                    foreach ($partRows as $row) {
                        $data = json_decode((string)($row['data'] ?? ''), true);

                        if (is_array($data)) {
                            $partsByMessage[$row['message_id']][] = $data;
                        }
                    }
                }
            }

            $matches = [];
            $msgIdx = count($messages);

            foreach ($messages as $msgRow) {
                $msgIdx--;
                $messageData = json_decode((string)($msgRow['data'] ?? ''), true);

                if (!is_array($messageData)) {
                    continue;
                }

                $parts = $partsByMessage[$msgRow['id']] ?? [];
                $entry = self::message_to_entry($messageData, $parts);

                if ($entry === null) {
                    continue;
                }

                // Search across all block text in this entry.
                $blockText = '';

                foreach ($entry['blocks'] as $block) {
                    $blockText .= ' ' . ($block['text'] ?? '');
                }

                $blockText = trim($blockText);

                if (stripos($blockText, $trimmedQuery) === false) {
                    continue;
                }

                $createdAt = is_int($msgRow['time_created'] ?? null) ? (int)$msgRow['time_created'] : 0;

                $matches[] = [
                    'line' => $msgIdx + 1,
                    'snippet' => self::build_search_snippet($blockText, $trimmedQuery),
                    'role' => $entry['role'] ?? null,
                    'kind' => $entry['blocks'][0]['kind'] ?? 'text',
                    'timestamp' => $createdAt > 0 ? $createdAt : null,
                ];

                if (count($matches) >= $maxMatches) {
                    break;
                }
            }

            return $matches;
        } catch (\PDOException $e) {
            return [];
        }
    }

    /**
     * A one-line, whitespace-collapsed preview centered on the query's
     * first occurrence — mirrors TranscriptService::build_search_snippet().
     */
    private static function build_search_snippet(string $text, string $query): string
    {
        $collapsed = preg_replace('/\s+/', ' ', $text);
        $pos = stripos($collapsed, $query);

        if ($pos === false) {
            return mb_strimwidth($collapsed, 0, 120, '…');
        }

        $start = max(0, $pos - 40);
        $snippet = substr($collapsed, $start, 120);

        if ($start > 0) {
            $snippet = '…' . $snippet;
        }

        if ($start + 120 < strlen($collapsed)) {
            $snippet .= '…';
        }

        return $snippet;
    }
}
