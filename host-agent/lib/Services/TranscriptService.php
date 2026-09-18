<?php

declare(strict_types=1);

namespace HostAgent\Services;

/**
 * Read-only access to Claude Code's own JSONL conversation transcripts
 * under ~/.claude/projects/<encoded-cwd>/<session-id>.jsonl - this file
 * never writes to them. Sessions created via create_agent_session() are
 * launched with an explicit --session-id (a UUID this app generates
 * itself, stored in the sidecar - see generate_uuid_v4() in Sessions.php),
 * so a transcript is found by globbing for that UUID's filename rather
 * than reimplementing Claude Code's own cwd -> directory-name encoding.
 */
class TranscriptService
{
    // A hard safety cap, not a display preview length - the UI already collapses
    // blocks by default (see App\Views\BlockedPromptView::render_collapsible_block()), so
    // this only exists to stop a truly pathological single block (e.g. a huge
    // file dump) from bloating the page. Expanding a block should show it in
    // full for any normal-sized command/tool output.
    public const TRANSCRIPT_BLOCK_HARD_CAP_LENGTH = 50000;

    // Matches BlockedPromptView::collapsible_summary()'s own single-line threshold (the
    // downstream render_collapsible_block()/renderCollapsibleBlock() rule
    // that skips the expand affordance entirely for trivial content) - the
    // decision has to be made here, not there, since once this returns a
    // multi-line string, the downstream renderer has no way to know it could
    // have fit on one line.
    public const TOOL_USE_SUMMARY_LINE_MAX = 80;

    // A hard safety cap on the base64 image DATA itself (not the whole
    // message), separate from TRANSCRIPT_BLOCK_HARD_CAP_LENGTH below (which
    // only applies to text and would corrupt an image if it truncated
    // mid-base64) - generous for a real screenshot (a few MB of actual image
    // data), just a guard against something pathological blowing up the page.
    public const TRANSCRIPT_IMAGE_MAX_BASE64_LENGTH = 8_000_000;

    // Matches UploadService::max_upload_bytes()'s default - both relay a
    // whole file as base64 JSON over the same one-shot agent socket
    // protocol, so the same rough ceiling applies to reading an attachment
    // back out as applied to writing one in.
    public const ATTACHMENT_MAX_BYTES = 64 * 1024 * 1024;

    // How much of a transcript's TAIL find_latest_ai_title() reads, rather
    // than the whole file. Verified live against real transcripts (one
    // 50MB/~10k-line session): Claude Code re-writes the ai-title line
    // repeatedly as a conversation goes on (605 occurrences in that one
    // file, not a one-time thing), always with the current value clustered
    // within the final ~100 lines - so the true latest is reliably found
    // this way without loading a multi-MB file just to read a title.
    public const AI_TITLE_TAIL_SCAN_BYTES = 262144;

    // Same reasoning/size as AI_TITLE_TAIL_SCAN_BYTES above -
    // find_latest_todo_list() scans for the tail's newest TodoWrite call,
    // which (like ai-title) Claude Code rewrites in full every time the
    // list changes rather than diffing it, so the latest is what matters.
    public const TODO_LIST_TAIL_SCAN_BYTES = 262144;

    // How many leading lines find_first_cwd() reads before giving up.
    // Verified live: a real transcript's first "user" message (the first
    // real, non-meta line) carries `cwd` and shows up by line 3 (mode,
    // file-history-snapshot, then the real message) - this leaves a wide
    // margin without risking a read anywhere near the size of a whole file.
    public const FIRST_CWD_SCAN_LINES = 20;

    // Safety cap for read_transcript_page()'s $untilRealUserMessage mode -
    // "load until the last user message" (session.js's #load-until-user-btn,
    // Andres's own ask 2026-08-24) has no other natural stopping point if a
    // session genuinely has an exchange this long (a real, if unusual, agent
    // turn - dozens of tool calls in a row) or, in the pathological case, no
    // earlier real user message at all before hitting the start of the file.
    // Behaves exactly like an ordinary page at this size if the cap is ever
    // actually hit without finding one - has_more/next_before still let the
    // client keep paging normally from there.
    public const UNTIL_USER_MESSAGE_MAX_ENTRIES = 300;

    /**
     * $profile (see Config::claude_config_dir()) - null resolves to this
     * process's own default account, same as every other call site before
     * profile support existed. A profile's transcripts live under its own
     * CLAUDE_CONFIG_DIR, not this app's own $HOME, once one is configured.
     */
    public static function claude_projects_dir(?string $profile = null): string
    {
        return Config::claude_config_dir($profile) . '/projects';
    }

    /**
     * JSONL line types that carry no `message` payload (mode switches,
     * permission-mode changes, bridge/queue bookkeeping, file-history
     * snapshots, stop-hook summaries, ...) - skipped when rendering history.
     * Verified against a real, 700+-message transcript that none of these
     * (nor any other observed type) ever carry a `message` key, so
     * parse_transcript_line()'s own `!is_array($message)` check would filter
     * them out regardless - this list exists for clarity/intent, not as the
     * only thing standing between them and getting rendered as garbage.
     *
     * @return string[]
     */
    public static function transcript_meta_only_types(): array
    {
        return [
            'mode', 'permission-mode', 'bridge-session', 'last-prompt', 'attachment', 'ai-title',
            'system', 'queue-operation', 'file-history-snapshot', 'file-history-delta',
        ];
    }

    /**
     * Finds the transcript file for a session id generated by
     * generate_uuid_v4() - never trusts $agentSessionId to already be safe,
     * since it ultimately traces back to a sidecar file, so it's validated as
     * UUID-shaped before touching the filesystem.
     */
    public static function find_transcript_path(string $agentSessionId, ?string $profile = null): ?string
    {
        if (preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $agentSessionId) !== 1) {
            return null;
        }

        $matches = glob(self::claude_projects_dir($profile) . '/*/' . $agentSessionId . '.jsonl') ?: [];

        return $matches[0] ?? null;
    }

    /**
     * The working directory a transcript's session ran in - read from the
     * `cwd` field Claude Code stamps on every real message-type JSONL line,
     * NOT decoded from the encoded project directory name. That decoding is
     * lossy (verified live: a real directory name like
     * "-home-andres--claude" is ambiguous - a literal "-" inside a real
     * path segment and the "/" separator both become "-"), so this is the
     * only reliable source. Streams the file line-by-line (fgets, not a
     * full file() read) and stops at the first hit, capped at
     * FIRST_CWD_SCAN_LINES - cwd shows up within the first few real lines
     * in practice (verified live: line 3 of a real transcript, after a
     * leading `mode` and `file-history-snapshot` meta line), so this is a
     * tiny read regardless of how large the rest of the file grows.
     */
    public static function find_first_cwd(string $path): ?string
    {
        $handle = @fopen($path, 'rb');

        if ($handle === false) {
            return null;
        }

        $cwd = null;

        for ($i = 0; $i < self::FIRST_CWD_SCAN_LINES; $i++) {
            $line = fgets($handle);

            if ($line === false) {
                break;
            }

            $decoded = json_decode($line, true);

            if (is_array($decoded) && is_string($decoded['cwd'] ?? null) && $decoded['cwd'] !== '') {
                $cwd = $decoded['cwd'];
                break;
            }
        }

        fclose($handle);

        return $cwd;
    }

    /**
     * The first real message line's own `timestamp` field, as a Unix
     * epoch int - Claude Code's own session-start moment, read via the
     * same bounded scan as find_first_cwd() (same file, same first few
     * lines, just a different field - kept as its own pass rather than
     * merged into find_first_cwd() to avoid changing that function's
     * already-relied-on return shape). Used by SessionService::
     * take_over_bare_process()'s heuristic: matching a bare process's own
     * `started_at` (from /proc) against each candidate transcript's
     * creation time to suggest the most likely one to resume, since a
     * bare process's OS pid is never recorded anywhere in the transcript
     * itself (checked: no `pid` field anywhere in the JSONL schema).
     */
    public static function find_first_timestamp(string $path): ?int
    {
        $handle = @fopen($path, 'rb');

        if ($handle === false) {
            return null;
        }

        $timestamp = null;

        for ($i = 0; $i < self::FIRST_CWD_SCAN_LINES; $i++) {
            $line = fgets($handle);

            if ($line === false) {
                break;
            }

            $decoded = json_decode($line, true);

            if (is_array($decoded) && is_string($decoded['timestamp'] ?? null) && $decoded['timestamp'] !== '') {
                $parsed = strtotime($decoded['timestamp']);

                if ($parsed !== false) {
                    $timestamp = $parsed;
                    break;
                }
            }
        }

        fclose($handle);

        return $timestamp;
    }

    /**
     * One entry per known transcript under claude_projects_dir(), live or
     * dormant - the raw data behind the unify-claude-sessions plan's
     * archived-session list. Deliberately returns the raw ai_title (not a
     * cascaded display title - that's a display decision, not a transcript-
     * reading one) so SessionService can apply the exact same title
     * cascade it already uses for live sessions.
     *
     * $profile: same meaning as claude_projects_dir()'s own - null scans
     * only the default account. Scanning every configured profile's
     * transcripts in one archived-session listing is a known follow-up
     * (Dibs plan #230's UI/verification tasks), not done here yet - no
     * caller passes a non-null profile today.
     *
     * @return array<int, array{agent_session_id:string, cwd:?string, ai_title:?string, last_activity:int, path:string}>
     */
    public static function list_all_transcripts(?string $profile = null): array
    {
        $paths = glob(self::claude_projects_dir($profile) . '/*/*.jsonl') ?: [];
        $result = [];

        foreach ($paths as $path) {
            $mtime = @filemtime($path);

            $result[] = [
                'agent_session_id' => basename($path, '.jsonl'),
                'cwd' => self::find_first_cwd($path),
                'ai_title' => self::find_latest_ai_title($path),
                'last_activity' => $mtime !== false ? $mtime : 0,
                'path' => $path,
            ];
        }

        return $result;
    }

    /**
     * How much of a match's rendered block text to show around the first
     * occurrence of the query, on each side - a search "result" needs just
     * enough surrounding words to recognize the moment, not the whole
     * message (that's what clicking through to it is for).
     */
    private const SEARCH_SNIPPET_CONTEXT_CHARS = 60;

    /**
     * Full-text search of one transcript file, newest match first (a
     * search is almost always "where did I last talk about X", not "where
     * did this first come up"). Two-stage matching per candidate line -
     * cheap stripos() against the RAW json line first (skips json_decode +
     * parse_transcript_line() entirely for the overwhelming majority of
     * lines in a real transcript, which won't match at all - verified live
     * against a real 50MB/~10k-line session, see AI_TITLE_TAIL_SCAN_BYTES's
     * own doc comment), then a second stripos() against the PARSED block
     * text once a line clears the first check - a raw-line hit can land
     * inside metadata this app never renders as content at all (a
     * tool_use_id, an internal path/param never surfaced as block text),
     * which would otherwise produce a "match" with nothing findable to
     * highlight once the user actually clicks through to it.
     *
     * $exitPlanModeToolUseIds is only computed once a raw-line candidate is
     * actually found (find_exit_plan_mode_tool_use_ids() itself has to walk
     * the whole file) - a query with zero raw hits anywhere in the file
     * never pays for it at all.
     *
     * @return array<int, array{line:int, snippet:string, role:?string, kind:string}>
     */
    public static function search_transcript_file(string $path, string $query, int $maxMatches): array
    {
        $trimmedQuery = trim($query);

        if ($trimmedQuery === '') {
            return [];
        }

        $lines = @file($path, FILE_IGNORE_NEW_LINES);

        if ($lines === false) {
            return [];
        }

        $exitPlanModeToolUseIds = null;
        $matches = [];

        for ($i = count($lines) - 1; $i >= 0 && count($matches) < $maxMatches; $i--) {
            if (stripos($lines[$i], $trimmedQuery) === false) {
                continue;
            }

            $exitPlanModeToolUseIds ??= self::find_exit_plan_mode_tool_use_ids($lines);
            $parsed = self::parse_transcript_line($lines[$i], $exitPlanModeToolUseIds);

            if ($parsed === null) {
                continue;
            }

            $blockText = implode(' ', array_map(static fn(array $b): string => $b['text'], $parsed['blocks']));

            if (stripos($blockText, $trimmedQuery) === false) {
                continue;
            }

            // Converted to Unix seconds here (the transcript's own
            // timestamp is an ISO 8601 string) so the client can feed it
            // straight into the same relativeTimeLabel() every other
            // "how long ago" label in this app already uses (session rows,
            // the session-info footer), rather than parsing a second date
            // format client-side.
            $timestamp = is_string($parsed['timestamp']) ? strtotime($parsed['timestamp']) : false;

            $matches[] = [
                'line' => $i + 1,
                'snippet' => self::build_search_snippet($blockText, $trimmedQuery),
                'role' => $parsed['role'],
                'kind' => $parsed['blocks'][0]['kind'],
                'timestamp' => $timestamp !== false ? $timestamp : null,
            ];
        }

        return $matches;
    }

    /**
     * A one-line, whitespace-collapsed preview centered on the query's
     * first occurrence - mirrors collapsible_summary()'s "just enough to
     * recognize it" goal (BlockedPromptView), but centered on the match
     * instead of always the start, since the interesting part of a long
     * message is wherever the query actually landed, not necessarily its
     * first line.
     */
    private static function build_search_snippet(string $text, string $query): string
    {
        $collapsed = trim(preg_replace('/\s+/', ' ', $text) ?? $text);
        $matchPos = mb_stripos($collapsed, $query);

        if ($matchPos === false) {
            $matchPos = 0;
        }

        $start = max(0, $matchPos - self::SEARCH_SNIPPET_CONTEXT_CHARS);
        $end = min(mb_strlen($collapsed), $matchPos + mb_strlen($query) + self::SEARCH_SNIPPET_CONTEXT_CHARS);
        $snippet = mb_substr($collapsed, $start, $end - $start);

        return ($start > 0 ? '… ' : '') . $snippet . ($end < mb_strlen($collapsed) ? ' …' : '');
    }

    /**
     * Field names (checked in this order) that hold a tool_use's single most
     * useful argument to show for the tool actually invoked, e.g. `command`
     * for Bash, `file_path` for Read/Edit/Write, `pattern` for Grep/Glob -
     * mirrors the "ToolName(argument)" convention Claude Code's own TUI uses
     * (verified against a real capture: "Bash(echo ... > /tmp/...)"). `element`
     * covers MCP browser-automation tools (verified against a real capture of
     * an MCP Playwright browser_click call: {"element": "...", "target": "..."}).
     * `notebook_path` covers NotebookEdit specifically - the one single-file
     * tool whose path argument isn't actually named `file_path` (found live:
     * without this, its filename only happened to lead the summary when it
     * was already first in the input's own key order, not on the strength of
     * being the primary arg - the same "wherever it falls in normal param
     * order" problem this whole list exists to avoid).
     *
     * `description` deliberately isn't in this list (removed 2026-08-21) -
     * summarize_tool_use() now promotes it onto the summary's head line
     * directly (format_tool_use_summary()'s $headSummary) whenever present,
     * ahead of and separate from whichever key here is picked as the
     * primary arg, rather than treating it as just another equally-ranked
     * candidate.
     *
     * @return string[]
     */
    public static function tool_use_primary_arg_keys(): array
    {
        return ['command', 'file_path', 'notebook_path', 'pattern', 'query', 'url', 'path', 'prompt', 'element'];
    }

    /**
     * MCP tool names come through as "mcp__<server>__<tool>" - reformatted to
     * "server.tool" (e.g. "mcp__playwright__browser_click" ->
     * "playwright.browser_click"), matching how the server groups its tools,
     * without the noisy double-underscore prefix. Returns the name unchanged
     * for anything that isn't MCP-shaped.
     */
    public static function humanize_tool_name(string $name): string
    {
        if (preg_match('/^mcp__([^_]+(?:_[^_]+)*)__(.+)$/', $name, $m) === 1) {
            return $m[1] . '.' . $m[2];
        }

        return $name;
    }

    /**
     * AskUserQuestion's input has no single scalar "primary" argument (it's a
     * nested questions/options structure), so it would otherwise fall through
     * to summarize_tool_use()'s raw-JSON-dump fallback - unreadable, and often
     * cut off mid-structure by the block-length hard cap. This turns it into
     * "question (option / option); question2 (...)" instead, matching what a
     * human actually wants to see: what was asked and what the choices were.
     *
     * @param array<string, mixed> $input
     */
    public static function summarize_ask_user_question(array $input): ?string
    {
        $questions = $input['questions'] ?? null;

        if (!is_array($questions) || $questions === []) {
            return null;
        }

        $parts = [];

        foreach ($questions as $q) {
            if (!is_array($q)) {
                continue;
            }

            $question = is_string($q['question'] ?? null) ? $q['question'] : '';
            $options = is_array($q['options'] ?? null) ? $q['options'] : [];
            $labels = [];

            foreach ($options as $opt) {
                if (is_array($opt) && is_string($opt['label'] ?? null) && $opt['label'] !== '') {
                    $labels[] = $opt['label'];
                }
            }

            if ($question === '' && $labels === []) {
                continue;
            }

            $parts[] = trim($question . ($labels !== [] ? ' (' . implode(' / ', $labels) . ')' : ''));
        }

        return $parts !== [] ? implode('; ', $parts) : null;
    }

    /**
     * A subagent launch (Claude Code's own "Agent" tool - verified live
     * 2026-08-02 against a real captured tool_use, not "Task" as the tool's
     * informal name might suggest) has real primary-arg candidates
     * (description, prompt) that already work fine individually via
     * tool_use_primary_arg_keys(), but showing both plus subagent_type and
     * run_in_background as separate "key: value" lines is noisy for
     * something a human just wants to read as "what agent, doing what" at a
     * glance. Returns "<subagent_type>: <description>" instead, or null if
     * neither is present (falls through to the generic param-dump).
     *
     * @param array<string, mixed> $input
     */
    public static function summarize_agent_tool_use(array $input): ?string
    {
        $subagentType = is_string($input['subagent_type'] ?? null) ? $input['subagent_type'] : null;
        $description = is_string($input['description'] ?? null) ? $input['description'] : null;

        if ($subagentType === null && $description === null) {
            return null;
        }

        return trim(($subagentType !== null ? "{$subagentType}: " : '') . ($description ?? ''));
    }

    /**
     * The Agent tool's own tool_result always appends a second text block of
     * pure internal bookkeeping (an agentId to resume the subagent, token/
     * duration usage) that the tool's own instructions explicitly say must
     * never be shown to or quoted in a user-facing reply - verified live
     * 2026-08-02 against a real captured subagent result, not guessed. Kept
     * out of the rendered text entirely rather than joined in as if it were
     * more of the subagent's actual output.
     *
     * The `^agentId:...<usage>` shape was the SYNCHRONOUS agent result
     * format; an async/backgrounded launch (`run_in_background`) instead
     * gets an immediate "Async agent launched successfully..." acknowledgment
     * tool_result with none of that shape at all (no `<usage>` tag anywhere)
     * - re-verified live 2026-08-22 against a real captured launch result
     * after finding the original regex no longer matched it, letting this
     * second internal-bookkeeping shape leak into the rendered transcript
     * unfiltered too.
     */
    public static function is_subagent_metadata_text(string $text): bool
    {
        return preg_match('/^agentId:\s*\S+.*<usage>/s', $text) === 1
            || str_starts_with($text, 'Async agent launched successfully.');
    }

    /**
     * A completed/failed background subagent notifies the parent
     * conversation as a plain-string `<task-notification>...</task-notification>`-
     * wrapped "user" transcript entry (Claude Code's own SendMessage/fork
     * mechanism, NOT a tool_result block at all) - verified live 2026-08-22
     * against a real captured entry. Extracts the handful of tags actually
     * worth showing rather than rendering the raw XML verbatim (found live:
     * that's exactly what parse_transcript_line() used to do, since a plain
     * string $content with no special-casing just became a 'text' block).
     * A simple tag-by-tag regex rather than a real XML parser, matching
     * this file's existing is_subagent_metadata_text()-style pragmatism -
     * good enough for Claude Code's own fixed, non-attacker-controlled
     * output shape.
     *
     * @return array{status:?string, summary:?string, result:?string}|null null if $content isn't this shape at all
     */
    public static function parse_task_notification(string $content): ?array
    {
        if (!str_starts_with(ltrim($content), '<task-notification>')) {
            return null;
        }

        $status = self::extract_task_notification_tag($content, 'status');
        $summary = self::extract_task_notification_tag($content, 'summary');
        $result = self::extract_task_notification_tag($content, 'result');

        if ($status === null && $summary === null && $result === null) {
            return null;
        }

        return ['status' => $status, 'summary' => $summary, 'result' => $result];
    }

    private static function extract_task_notification_tag(string $content, string $tag): ?string
    {
        return preg_match('/<' . $tag . '>(.*?)<\/' . $tag . '>/s', $content, $m) === 1 ? trim($m[1]) : null;
    }

    /**
     * @param array<string, mixed> $input
     * @return string[] "key: value" lines, the primary arg (see
     *   tool_use_primary_arg_keys()) first if present, then every other
     *   param in $input's own order - unlike the single-primary-arg-only
     *   format this replaced, nothing is dropped. Non-scalar values are
     *   JSON-encoded compactly rather than skipped.
     */
    public static function tool_use_param_lines(array $input): array
    {
        $primaryKey = null;

        foreach (self::tool_use_primary_arg_keys() as $key) {
            if (array_key_exists($key, $input)) {
                $primaryKey = $key;
                break;
            }
        }

        $lines = [];

        if ($primaryKey !== null) {
            $lines[] = self::tool_use_param_line($primaryKey, $input[$primaryKey]);
        }

        foreach ($input as $key => $value) {
            if ($key === $primaryKey) {
                continue;
            }

            $lines[] = self::tool_use_param_line((string)$key, $value);
        }

        return $lines;
    }

    public static function tool_use_param_line(string $key, mixed $value): string
    {
        if (is_scalar($value)) {
            return "{$key}: {$value}";
        }

        $json = json_encode($value);

        return "{$key}: " . ($json !== false ? $json : '(unrepresentable)');
    }

    /**
     * "tool: X" plus every param, e.g. "tool: Bash - command: pwd" - joined
     * onto one line for a short/simple call (mirrors the existing
     * trivial-block rule: no point in an expand affordance for something
     * already fully visible), broken out into its own "Params:" list
     * otherwise, one "- key: value" per line.
     *
     * @param string[] $paramLines
     */
    public static function format_tool_use_summary(string $displayName, array $paramLines): string
    {
        if ($paramLines === []) {
            return "tool: {$displayName}";
        }

        $singleLine = "tool: {$displayName} - " . implode(', ', $paramLines);

        if (!str_contains($singleLine, "\n") && mb_strlen($singleLine) <= self::TOOL_USE_SUMMARY_LINE_MAX) {
            return $singleLine;
        }

        $bulleted = array_map(static fn(string $line): string => "- {$line}", $paramLines);

        return "tool: {$displayName}\nParams:\n" . implode("\n", $bulleted);
    }

    /**
     * A `description` field (Bash/Glob/Grep and others all commonly carry
     * one - a short human-readable "what this call is doing", separate
     * from the often-long/cryptic raw command or pattern). Pulled out as
     * its own value rather than left to compete as just another
     * "key: value" entry in the generic param dump - see
     * summarize_content_block()'s own 'description' field, rendered as its
     * own always-visible line (transcript/block.php), never subject to
     * collapsible_summary()'s first-line-only truncation the way the rest
     * of the params are (found live 2026-08-21, Andres: wanted it visible
     * right alongside the "tool: ..." line, on its own line, not
     * competing with a long raw command for the single-line summary's
     * length budget or buried behind two levels of expansion).
     */
    public static function tool_use_description(array $input): ?string
    {
        return is_string($input['description'] ?? null) && $input['description'] !== '' ? $input['description'] : null;
    }

    /**
     * "tool: X - key: value, ..." (or the multi-line "Params:" form for
     * anything long enough to need it - see format_tool_use_summary()) - not
     * just the bare tool name, so a Bash entry shows the actual command run,
     * an Edit shows the file touched, etc., instead of requiring a click into
     * `tool_result` to guess what happened. Shows every param, not just one
     * primary argument. `description`, when present, is excluded here - it's
     * surfaced separately (see tool_use_description()/summarize_content_
     * block()) rather than duplicated as a param line too.
     */
    public static function summarize_tool_use(array $block): string
    {
        $name = (string)($block['name'] ?? 'tool');
        $displayName = self::humanize_tool_name($name);
        $input = $block['input'] ?? null;

        if (!is_array($input) || $input === []) {
            return "tool: {$displayName}";
        }

        if ($name === 'AskUserQuestion') {
            $summary = self::summarize_ask_user_question($input);

            if ($summary !== null) {
                return self::format_tool_use_summary($displayName, [$summary]);
            }
        }

        if ($name === 'Agent') {
            $summary = self::summarize_agent_tool_use($input);

            if ($summary !== null) {
                return self::format_tool_use_summary($displayName, [$summary]);
            }
        }

        $description = self::tool_use_description($input);
        $paramInput = $description !== null ? array_diff_key($input, ['description' => true]) : $input;

        return self::format_tool_use_summary($displayName, self::tool_use_param_lines($paramInput));
    }

    /**
     * @param array<string, mixed> $block
     * @return array{kind:string, text:string}
     */
    public static function summarize_content_block(array $block): array
    {
        $type = (string)($block['type'] ?? '');

        $planText = $type === 'tool_use' && (string)($block['name'] ?? '') === 'ExitPlanMode' && is_array($block['input'] ?? null) && is_string($block['input']['plan'] ?? null)
            ? trim($block['input']['plan'])
            : null;

        return match (true) {
            // ExitPlanMode gets its own block kind entirely, not the generic
            // "tool: X - key: value" summary (see summarize_tool_use()) -
            // Claude explicitly stops and asks the user to review this, so
            // the real plan content is the whole point, shown in full like
            // a real message rather than collapsed behind a one-line
            // summary the way a routine tool call's params are.
            $planText !== null && $planText !== '' => ['kind' => 'plan', 'text' => $planText],
            $type === 'text' => ['kind' => 'text', 'text' => (string)($block['text'] ?? '')],
            $type === 'tool_use' => array_filter(
                [
                    'kind' => 'tool_use',
                    'text' => self::summarize_tool_use($block),
                    // Rendered as its own always-visible line (transcript/
                    // block.php), never subject to the collapsible summary's
                    // first-line-only truncation the rest of the params are -
                    // see tool_use_description()'s own doc comment. Excluded
                    // for Agent specifically - summarize_agent_tool_use()
                    // already folds its description into the text summary
                    // itself ("<subagent_type>: <description>"), so adding it
                    // here too would just show it twice.
                    'description' => (string)($block['name'] ?? '') !== 'Agent' && is_array($block['input'] ?? null)
                        ? self::tool_use_description($block['input'])
                        : null,
                    // Lets session.php color/collapse a subagent launch as its
                    // own distinct kind instead of a generic tool call - see
                    // entry_color_kind() there. Read straight off this block's
                    // own input (available directly, unlike the matching
                    // tool_result's agent_type below, which needs the outer
                    // JSONL line's toolUseResult field instead).
                    'agent_type' => (string)($block['name'] ?? '') === 'Agent' && is_array($block['input'] ?? null) && is_string($block['input']['subagent_type'] ?? null)
                        ? $block['input']['subagent_type']
                        : null,
                    // Raw (unformatted) tool_name/file_path/command, ONLY for
                    // Bash/Write/Edit/Read - lets TranscriptView::tool_call_
                    // entry_summary() build a "Write(relative/path.php)" or
                    // "Bash(truncated command)" one-line summary for a
                    // standalone tool-call entry's closed <summary> (Andres's
                    // own ask 2026-08-22), instead of the generic collapsible-
                    // summary()-truncated "tool: X - key: value" text every
                    // other tool falls back to. Bash's own `description` param
                    // (present on nearly every real Bash call, since Claude
                    // Code's tool schema requires one) is deliberately NOT
                    // preferred here even though tool_use_description() above
                    // would otherwise win for it - the real command is more
                    // useful at a glance than a possibly-vague description.
                    'tool_name' => in_array($block['name'] ?? null, ['Bash', 'Write', 'Edit', 'Read'], true) ? $block['name'] : null,
                    'file_path' => in_array($block['name'] ?? null, ['Write', 'Edit', 'Read'], true) && is_array($block['input'] ?? null) && is_string($block['input']['file_path'] ?? null) && $block['input']['file_path'] !== ''
                        ? $block['input']['file_path']
                        : null,
                    'command' => ($block['name'] ?? null) === 'Bash' && is_array($block['input'] ?? null) && is_string($block['input']['command'] ?? null) && $block['input']['command'] !== ''
                        ? $block['input']['command']
                        : null,
                ],
                static fn(mixed $v): bool => $v !== null
            ),
            $type === 'tool_result' => array_filter(
                [
                    'kind' => 'tool_result',
                    'text' => self::transcript_tool_result_text($block['content'] ?? null),
                    'image' => self::transcript_tool_result_image($block['content'] ?? null),
                ],
                static fn(mixed $v): bool => $v !== null
            ),
            $type === 'image' => self::transcript_image_from_block($block) !== null
                ? ['kind' => 'image', 'text' => '', 'image' => self::transcript_image_from_block($block)]
                : ['kind' => 'image', 'text' => '(image could not be displayed)'],
            default => ['kind' => $type !== '' ? $type : 'unknown', 'text' => ''],
        };
    }

    /**
     * A tool_result's `content` is either a plain string or a list of blocks
     * (usually just {type: "text", text: ...}) - flattened to plain text for
     * the history view, which only ever shows a preview, never the raw shape.
     */
    public static function transcript_tool_result_text(mixed $content): string
    {
        if (is_string($content)) {
            return $content;
        }

        if (!is_array($content)) {
            return '';
        }

        $parts = [];

        foreach ($content as $item) {
            if (is_array($item) && ($item['type'] ?? null) === 'text') {
                $text = (string)($item['text'] ?? '');

                if (self::is_subagent_metadata_text($text)) {
                    continue;
                }

                $parts[] = $text;
            }
        }

        return implode("\n", $parts);
    }

    /**
     * @param array<string, mixed> $block a raw {type: "image", source: {...}} block
     * @return array{media_type:string, data:string}|null
     */
    public static function transcript_image_from_block(array $block): ?array
    {
        $source = $block['source'] ?? null;

        if (!is_array($source) || ($source['type'] ?? null) !== 'base64') {
            return null;
        }

        $data = (string)($source['data'] ?? '');

        if ($data === '' || strlen($data) > self::TRANSCRIPT_IMAGE_MAX_BASE64_LENGTH) {
            return null;
        }

        return ['media_type' => (string)($source['media_type'] ?? 'image/png'), 'data' => $data];
    }

    /**
     * A tool_result's real richer file metadata (SendUserFile, verified
     * live 2026-08-04) lives on the outer JSONL line's toolUseResult field,
     * not in the content blocks themselves - content only ever carries a
     * plain-text summary string ("Sent 2 file(s)..."), never a filename,
     * size, or a way to fetch the bytes back. Unlike an inline image block
     * (transcript_tool_result_image() above), these files were never
     * embedded as base64 in the transcript at all - only a host filesystem
     * path, deliberately dropped here (never sent to the browser) in favor
     * of a file_uuid the browser can hand back to read_attachment() to
     * fetch the real bytes through the host-agent, the same trust boundary
     * every other file-touching action in this app already goes through.
     *
     * @return array<int, array{file_uuid:string, filename:string, size:int, isImage:bool, media_type:string}>
     */
    public static function transcript_attachments_from_tool_use_result(mixed $toolUseResult): array
    {
        if (!is_array($toolUseResult) || !is_array($toolUseResult['attachments'] ?? null)) {
            return [];
        }

        $out = [];

        foreach ($toolUseResult['attachments'] as $attachment) {
            if (!is_array($attachment) || !is_string($attachment['file_uuid'] ?? null) || !is_string($attachment['path'] ?? null) || $attachment['path'] === '') {
                continue;
            }

            $out[] = [
                'file_uuid' => $attachment['file_uuid'],
                'filename' => basename($attachment['path']),
                'size' => (int)($attachment['size'] ?? 0),
                'isImage' => (bool)($attachment['isImage'] ?? false),
                'media_type' => is_string($attachment['media_type'] ?? null) && $attachment['media_type'] !== '' ? $attachment['media_type'] : 'application/octet-stream',
            ];
        }

        return $out;
    }

    /**
     * A tool_result's content can include an inline image alongside its text
     * (e.g. a browser-automation screenshot tool, verified against a real
     * capture) - the first one found, if any.
     *
     * @return array{media_type:string, data:string}|null
     */
    public static function transcript_tool_result_image(mixed $content): ?array
    {
        if (!is_array($content)) {
            return null;
        }

        foreach ($content as $item) {
            if (is_array($item) && ($item['type'] ?? null) === 'image') {
                $image = self::transcript_image_from_block($item);

                if ($image !== null) {
                    return $image;
                }
            }
        }

        return null;
    }

    /**
     * A cheap up-front scan across every raw line for ExitPlanMode tool_use
     * ids, so a later tool_result (which may come BEFORE its matching
     * tool_use when a caller walks backward for pagination - see
     * read_transcript_page()) can still be recognized as "this was a plan"
     * regardless of which direction it's read in. Needed because a
     * rejected tool's outer toolUseResult is just the generic string "User
     * rejected tool use" for ANY tool (verified live 2026-08-07 across
     * several real transcripts) - nothing in the tool_result line itself
     * says WHICH tool was rejected, only tool_use_id, which has to be
     * cross-referenced against the matching tool_use block's own name.
     * An APPROVED plan doesn't need this (its own toolUseResult is a
     * distinctive {"plan": "..."} shape, unambiguous on its own).
     *
     * @param string[] $lines
     * @return array<string, true> tool_use_id => true, one entry per ExitPlanMode call found
     */
    public static function find_exit_plan_mode_tool_use_ids(array $lines): array
    {
        $ids = [];

        foreach ($lines as $line) {
            // Cheap short-circuit before paying for a full json_decode - the
            // vast majority of lines aren't even a candidate.
            if (!str_contains($line, 'ExitPlanMode')) {
                continue;
            }

            $decoded = json_decode($line, true);
            $content = is_array($decoded) ? ($decoded['message']['content'] ?? null) : null;

            if (!is_array($content)) {
                continue;
            }

            foreach ($content as $block) {
                if (is_array($block) && ($block['type'] ?? null) === 'tool_use' && ($block['name'] ?? null) === 'ExitPlanMode' && is_string($block['id'] ?? null)) {
                    $ids[$block['id']] = true;
                }
            }
        }

        return $ids;
    }

    /**
     * The most recent ai-title Claude Code itself generated for this
     * transcript - a real {"type":"ai-title","aiTitle":"...","sessionId":
     * "..."} JSONL line it writes on its own (confirmed present in real
     * transcripts; not confirmed every session gets one, e.g. a very short
     * one might not have enough turns), normally skipped entirely by
     * transcript_meta_only_types(). This is the primary session-title
     * source for the unify-claude-sessions plan's "minimize tmux reliance"
     * goal - it works for a dormant session exactly as well as a live one,
     * unlike today's live-pane-title scrape.
     *
     * Only reads the file's last AI_TITLE_TAIL_SCAN_BYTES, not the whole
     * thing - Claude Code re-writes this line repeatedly over a long
     * conversation rather than once (the title can change), so the LATEST
     * one wins, but a full-file scan would mean loading a multi-MB+
     * transcript into memory on every dashboard poll just to read a title.
     * See that constant's own comment for the real-transcript evidence this
     * is based on. A session with no ai-title at all in that window (rare -
     * would mean nothing was written in a long stretch) just falls through
     * to null, same as a session with no ai-title anywhere.
     */
    public static function find_latest_ai_title(string $path): ?string
    {
        $size = @filesize($path);

        if ($size === false || $size === 0) {
            return null;
        }

        $handle = @fopen($path, 'rb');

        if ($handle === false) {
            return null;
        }

        $tailBytes = min($size, self::AI_TITLE_TAIL_SCAN_BYTES);
        fseek($handle, -$tailBytes, SEEK_END);
        $chunk = fread($handle, $tailBytes);
        fclose($handle);

        if ($chunk === false) {
            return null;
        }

        $latest = null;

        // The read may start mid-line when $tailBytes < $size - a truncated
        // leading fragment simply fails json_decode below and is skipped,
        // same tolerance the rest of this class already has for malformed
        // lines (see parse_transcript_line()).
        foreach (explode("\n", $chunk) as $line) {
            // Cheap short-circuit before paying for a full json_decode, same
            // reasoning as find_exit_plan_mode_tool_use_ids() above.
            if (!str_contains($line, '"ai-title"')) {
                continue;
            }

            $decoded = json_decode($line, true);

            if (!is_array($decoded) || ($decoded['type'] ?? null) !== 'ai-title') {
                continue;
            }

            $aiTitle = is_string($decoded['aiTitle'] ?? null) ? trim($decoded['aiTitle']) : '';

            if ($aiTitle !== '') {
                $latest = $aiTitle;
            }
        }

        return $latest;
    }

    /**
     * The raw model ID (e.g. "claude-sonnet-5") off the most recent
     * assistant message in the transcript - backs session.php's "Select
     * model" dropdown (Andres's own ask, 2026-08-24). Every real assistant
     * message carries its own `message.model` field (confirmed live against
     * a real transcript - far more reliable than pane-scraping a statusline,
     * which is only ever present when Andres has personally configured one;
     * see StatuslineMarkerService's own docblock for why that's opt-in, not
     * something every user of this app has). Same tail-scan-then-keep-latest
     * shape as find_latest_ai_title() above - a model switch is rare enough
     * within one session that the tail window will always contain at least
     * one recent assistant message either way.
     */
    public static function find_latest_model(string $path): ?string
    {
        $size = @filesize($path);

        if ($size === false || $size === 0) {
            return null;
        }

        $handle = @fopen($path, 'rb');

        if ($handle === false) {
            return null;
        }

        $tailBytes = min($size, self::AI_TITLE_TAIL_SCAN_BYTES);
        fseek($handle, -$tailBytes, SEEK_END);
        $chunk = fread($handle, $tailBytes);
        fclose($handle);

        if ($chunk === false) {
            return null;
        }

        $latest = null;

        foreach (explode("\n", $chunk) as $line) {
            if (!str_contains($line, '"assistant"')) {
                continue;
            }

            $decoded = json_decode($line, true);

            if (!is_array($decoded) || ($decoded['type'] ?? null) !== 'assistant') {
                continue;
            }

            $model = is_string($decoded['message']['model'] ?? null) ? $decoded['message']['model'] : null;

            if ($model !== null && $model !== '') {
                $latest = $model;
            }
        }

        return $latest;
    }

    /**
     * Finds the CURRENT todo list (TodoWrite tool call - a Claude Code
     * built-in, unrelated to this app's own todo/bugs.md files) for the
     * top-level agent's own conversation, sourced straight from the same
     * transcript this app already reads for everything else - no new hook
     * needed, unlike live subagent status (see the 2026-08-22 hook
     * reliability research in todo). Each TodoWrite call carries the FULL
     * list, not a diff (same "rewritten in full, not appended" shape as
     * ai-title above), so only the latest one matters.
     *
     * Two-phase, UNLIKE find_latest_ai_title() above: tries the cheap tail
     * window first (the common case - a list touched recently), but falls
     * back to a full-file forward stream if that comes up empty. This
     * matters here specifically because a todo list, unlike a title, can
     * legitimately go untouched for a long stretch of a long conversation
     * while still being the CURRENT list - a title only ever needs "some
     * recent occurrence" (Claude Code rewrites it near-continuously, 605
     * times in one real 50MB session), but a todo list set up early and
     * never revisited again would be wrongly reported as "none" if this
     * only ever looked at the tail. The forward fallback streams
     * line-by-line (fgets, not a full file() read - same reasoning as
     * find_first_cwd() above), so even a worst-case 50MB+ transcript with
     * no recent TodoWrite doesn't load the whole file into memory at once.
     *
     * @return array<int, array{content:string, activeForm:string, status:string}>|null
     */
    public static function find_latest_todo_list(string $path): ?array
    {
        $size = @filesize($path);

        if ($size === false || $size === 0) {
            return null;
        }

        $handle = @fopen($path, 'rb');

        if ($handle === false) {
            return null;
        }

        $tailBytes = min($size, self::TODO_LIST_TAIL_SCAN_BYTES);
        fseek($handle, -$tailBytes, SEEK_END);
        $chunk = fread($handle, $tailBytes);
        fclose($handle);

        if ($chunk === false) {
            return null;
        }

        // The read may start mid-line when $tailBytes < $size - same
        // tolerance as find_latest_ai_title() above (a truncated leading
        // fragment just fails json_decode and gets skipped).
        $fromTail = self::latest_todo_list_in_lines(explode("\n", $chunk));

        if ($fromTail !== null) {
            return $fromTail;
        }

        if ($tailBytes >= $size) {
            // The tail window already covered the whole file - nothing
            // more to find by re-scanning it.
            return null;
        }

        $handle = @fopen($path, 'rb');

        if ($handle === false) {
            return null;
        }

        $latest = null;

        while (($line = fgets($handle)) !== false) {
            $found = self::latest_todo_list_in_lines([$line]);

            if ($found !== null) {
                $latest = $found;
            }
        }

        fclose($handle);

        return $latest;
    }

    /**
     * Shared per-line matcher for find_latest_todo_list()'s two scan
     * phases (tail chunk split on "\n", and the whole-file fallback's
     * fgets() stream) - returns the LAST match found across $lines, or
     * null if none. isSidechain excludes a SUBAGENT's own TodoWrite calls
     * (if any tool restriction ever lets a subagent use it) - those live
     * in the same transcript file interleaved with the main conversation,
     * but they describe the subagent's own internal plan, not the top-
     * level agent's, so mixing them in here would show the wrong list
     * entirely.
     *
     * @param string[] $lines
     * @return array<int, array{content:string, activeForm:string, status:string}>|null
     */
    private static function latest_todo_list_in_lines(array $lines): ?array
    {
        $latest = null;

        foreach ($lines as $line) {
            if (!str_contains($line, '"TodoWrite"')) {
                continue;
            }

            $decoded = json_decode($line, true);

            if (
                !is_array($decoded)
                || ($decoded['type'] ?? null) !== 'assistant'
                || ($decoded['isSidechain'] ?? false) === true
            ) {
                continue;
            }

            $content = $decoded['message']['content'] ?? null;

            if (!is_array($content)) {
                continue;
            }

            foreach ($content as $block) {
                if (!is_array($block) || ($block['type'] ?? null) !== 'tool_use' || ($block['name'] ?? null) !== 'TodoWrite') {
                    continue;
                }

                $todos = $block['input']['todos'] ?? null;

                if (!is_array($todos)) {
                    continue;
                }

                $parsed = [];

                foreach ($todos as $todo) {
                    if (!is_array($todo)) {
                        continue;
                    }

                    $todoContent = is_string($todo['content'] ?? null) ? $todo['content'] : null;
                    $status = is_string($todo['status'] ?? null) ? $todo['status'] : null;

                    if ($todoContent === null || $status === null) {
                        continue;
                    }

                    $parsed[] = [
                        'content' => $todoContent,
                        'activeForm' => is_string($todo['activeForm'] ?? null) ? $todo['activeForm'] : $todoContent,
                        'status' => $status,
                    ];
                }

                $latest = $parsed;
            }
        }

        return $latest;
    }

    /**
     * Finds the CURRENT task list from the Task tool family (TaskCreate/
     * TaskGet/TaskUpdate/TaskList) - the TodoWrite REPLACEMENT on newer
     * models per https://code.claude.com/docs/en/tools-reference#task-tool-
     * availability (confirmed live 2026-08-22, verbatim from that page: "In
     * Claude Code v2.1.233 and later, [TodoWrite, TaskCreate, TaskGet,
     * TaskUpdate, and TaskList] aren't available on Opus 4.8, Sonnet 5,
     * Fable 5, Mythos 5, or later versions of those families unless you opt
     * in" - TodoWrite and the Task family are BOTH hidden by default on
     * those models, not one replacing the other automatically). This app's
     * own create_agent_session($enableTaskTools) opt-in checkbox names only
     * the Task family in --allowedTools, never TodoWrite (see that
     * method's own docblock in SessionService) - so a session with the
     * checkbox checked produces Task-family calls, while an older-model or
     * checkbox-unchecked session may still produce TodoWrite calls. Both
     * readers are kept side by side (see session_detail()'s cascade in
     * SessionService, which prefers this method's result and only falls
     * back to find_latest_todo_list() when the Task family was never
     * called at all) rather than one replacing the other in code.
     *
     * UNLIKE TodoWrite (one call carries the FULL list every time, so only
     * the latest call matters), the Task family is CRUD: TaskCreate adds
     * exactly one task, TaskUpdate changes ONE task's fields, or removes it
     * outright via status:"deleted" (confirmed live). There's no "latest
     * call has it all" shortcut, so the current list can only be
     * reconstructed by replaying every Task tool_use call across the WHOLE
     * file in order, keyed by task id - a cheap per-line substring
     * pre-check (same trick as latest_todo_list_in_lines() below) before
     * json_decode keeps a full streamed pass affordable even on a large
     * transcript, since (unlike the todo tail-scan) there's no way to skip
     * most of the file here - full history is genuinely needed.
     *
     * Reads the STRUCTURED `toolUseResult` field (a sibling of `message` on
     * the tool_result line, confirmed live 2026-08-22 against a real
     * Claude Code v2.1.241 transcript - NOT documented publicly, this app's
     * own capture is the source of truth) rather than regexing the
     * human-readable tool_result text, for two concrete reasons found by
     * actually exercising these tools in this session:
     * - TaskCreate's own tool_use input has NO id field (only subject/
     *   description/activeForm) - the real numeric id only appears in the
     *   matching tool_result, as structured JSON: `toolUseResult.task.id`.
     *   Creates are staged in $pending keyed by tool_use_id until their
     *   result line resolves the id.
     * - A TaskUpdate call can FAIL (`toolUseResult.success === false`,
     *   e.g. `{"error":"Task not found"}`) while still being a
     *   well-formed, plausible-looking call - live-verified: two attempts
     *   to delete already-completed tasks both failed this way ("Task not
     *   found") while a delete of a still-pending task succeeded moments
     *   later, so failure isn't simply "invalid id". Blindly applying
     *   every TaskUpdate's requested change (as an earlier version of this
     *   method did, before this was caught) would silently apply changes
     *   the real tool rejected - `toolUseResult.success` is checked before
     *   applying anything.
     * By the time an update for id N appears forward in the file, N's
     * create and its tool_result must already have been processed (the
     * model can only reference an id it already learned from an earlier
     * tool_result in the same conversation), so a single forward pass
     * covering both is enough - no second pass needed.
     *
     * Known limitation, NOT fixable from the transcript alone: live-
     * verified in this same investigation, completed tasks can go missing
     * from the tool's own live state with no corresponding transcript
     * event at all (TaskGet on a task that had earlier returned full
     * detail later returned "Task not found", despite no delete call ever
     * succeeding for it) - some kind of silent server-side pruning of old
     * completed tasks, trigger unconfirmed. This method has no way to
     * detect or replicate that silent pruning (nothing is written to the
     * transcript when it happens), so its reconstruction is a best-effort
     * replay of every call that WAS made, not a guaranteed match to
     * whatever the live tool's internal state happens to be at any given
     * moment - the same honest limitation TodoWrite-based tracking doesn't
     * have (TodoWrite has no server-side state to drift from at all).
     *
     * isSidechain excludes a SUBAGENT's own Task calls, same reasoning as
     * latest_todo_list_in_lines() below - those describe the subagent's own
     * work, not the top-level agent's list. A subagent's create is simply
     * never staged, so its own tool_result (even though still present in
     * the file) matches nothing in $pending and is a no-op.
     *
     * @return array<int, array{content:string, activeForm:string, status:string}>|null null when the session never called TaskCreate at all (distinct from [], a list explicitly emptied by deleting every task)
     */
    public static function find_current_task_list(string $path): ?array
    {
        $handle = @fopen($path, 'rb');

        if ($handle === false) {
            return null;
        }

        /** @var array<string, array{name:string, input:array<string, mixed>}> $pending tool_use_id => staged TaskCreate/TaskUpdate call, awaiting its tool_result to learn whether/how it actually applied */
        $pending = [];
        /** @var array<int, array{content:string, activeForm:string, status:string}> $tasks */
        $tasks = [];
        $everCalled = false;

        while (($line = fgets($handle)) !== false) {
            $isToolUseLine = str_contains($line, '"TaskCreate"') || str_contains($line, '"TaskUpdate"');

            // A tool_result line never contains the tool's NAME (only its
            // tool_use_id + the result) - found live 2026-08-22 debugging why
            // this method returned [] against a real transcript full of
            // genuine Task calls: the substring pre-check above skipped
            // every single tool_result line outright, so no staged call
            // ever got resolved. Only bother decoding a candidate result
            // line when something is actually pending AND the line
            // mentions one of those specific tool_use_ids - keeps the
            // common case (a transcript with no Task calls, or between
            // calls with nothing pending) just as cheap as the tool_use
            // check alone, without silently dropping every real result.
            if (!$isToolUseLine) {
                if ($pending === [] || !str_contains($line, '"tool_result"')) {
                    continue;
                }

                $matchesPending = false;

                foreach ($pending as $toolUseId => $_) {
                    if (str_contains($line, $toolUseId)) {
                        $matchesPending = true;

                        break;
                    }
                }

                if (!$matchesPending) {
                    continue;
                }
            }

            $decoded = json_decode($line, true);

            if (!is_array($decoded)) {
                continue;
            }

            $type = $decoded['type'] ?? null;
            $content = $decoded['message']['content'] ?? null;

            if (!is_array($content)) {
                continue;
            }

            if ($type === 'assistant' && ($decoded['isSidechain'] ?? false) !== true) {
                foreach ($content as $block) {
                    self::stage_task_tool_use($block, $pending, $everCalled);
                }
            } elseif ($type === 'user') {
                $toolUseResult = $decoded['toolUseResult'] ?? null;

                foreach ($content as $block) {
                    self::resolve_task_tool_result($block, is_array($toolUseResult) ? $toolUseResult : null, $pending, $tasks);
                }
            }
        }

        fclose($handle);

        if (!$everCalled) {
            return null;
        }

        return array_values($tasks);
    }

    /**
     * Stages one TaskCreate/TaskUpdate tool_use block into $pending, keyed
     * by its tool_use_id, for resolve_task_tool_result() to apply once its
     * outcome is known - see find_current_task_list() above for why this
     * can't be applied immediately. $pending/$everCalled mutated by
     * reference.
     *
     * @param mixed $block
     * @param array<string, array{name:string, input:array<string, mixed>}> $pending
     */
    private static function stage_task_tool_use(mixed $block, array &$pending, bool &$everCalled): void
    {
        if (!is_array($block) || ($block['type'] ?? null) !== 'tool_use') {
            return;
        }

        $name = $block['name'] ?? null;

        if ($name !== 'TaskCreate' && $name !== 'TaskUpdate') {
            return;
        }

        $toolUseId = is_string($block['id'] ?? null) ? $block['id'] : null;
        $input = $block['input'] ?? null;

        if ($toolUseId === null || !is_array($input)) {
            return;
        }

        if ($name === 'TaskCreate') {
            $everCalled = true;
        }

        $pending[$toolUseId] = ['name' => $name, 'input' => $input];
    }

    /**
     * A user-role tool_result block paired with that same line's top-level
     * `toolUseResult` (see find_current_task_list()'s docblock for why the
     * structured field is used instead of the human-readable text) -
     * applies a staged TaskCreate/TaskUpdate's real effect, or discards it
     * silently if the call failed or the result shape isn't what's
     * expected (malformed/partial data, or a shape this scan doesn't
     * recognize) rather than guessing. $pending/$tasks mutated by
     * reference.
     *
     * @param mixed $block
     * @param array<string, mixed>|null $toolUseResult
     * @param array<string, array{name:string, input:array<string, mixed>}> $pending
     * @param array<int, array{content:string, activeForm:string, status:string}> $tasks
     */
    private static function resolve_task_tool_result(mixed $block, ?array $toolUseResult, array &$pending, array &$tasks): void
    {
        if (!is_array($block) || ($block['type'] ?? null) !== 'tool_result') {
            return;
        }

        $toolUseId = is_string($block['tool_use_id'] ?? null) ? $block['tool_use_id'] : null;

        if ($toolUseId === null || !isset($pending[$toolUseId])) {
            return;
        }

        $staged = $pending[$toolUseId];
        unset($pending[$toolUseId]);

        if ($toolUseResult === null) {
            return;
        }

        if ($staged['name'] === 'TaskCreate') {
            self::apply_resolved_task_create($staged['input'], $toolUseResult, $tasks);
        } else {
            self::apply_resolved_task_update($staged['input'], $toolUseResult, $tasks);
        }
    }

    /**
     * @param array<string, mixed> $input the original TaskCreate call's input
     * @param array<string, mixed> $toolUseResult
     * @param array<int, array{content:string, activeForm:string, status:string}> $tasks
     */
    private static function apply_resolved_task_create(array $input, array $toolUseResult, array &$tasks): void
    {
        $subject = is_string($input['subject'] ?? null) ? $input['subject'] : null;
        $id = self::task_id_from_mixed($toolUseResult['task']['id'] ?? null);

        if ($subject === null || $id === null) {
            return;
        }

        $tasks[$id] = [
            'content' => $subject,
            'activeForm' => is_string($input['activeForm'] ?? null) ? $input['activeForm'] : $subject,
            'status' => 'pending',
        ];
    }

    /**
     * @param array<string, mixed> $input the original TaskUpdate call's input
     * @param array<string, mixed> $toolUseResult
     * @param array<int, array{content:string, activeForm:string, status:string}> $tasks
     */
    private static function apply_resolved_task_update(array $input, array $toolUseResult, array &$tasks): void
    {
        if (($toolUseResult['success'] ?? null) !== true) {
            return;
        }

        $id = self::task_id_from_mixed($toolUseResult['taskId'] ?? null);

        if ($id === null || !isset($tasks[$id])) {
            return;
        }

        $status = is_string($input['status'] ?? null) ? $input['status'] : null;

        if ($status === 'deleted') {
            unset($tasks[$id]);

            return;
        }

        if (is_string($input['subject'] ?? null)) {
            $tasks[$id]['content'] = $input['subject'];
        }

        if (is_string($input['activeForm'] ?? null)) {
            $tasks[$id]['activeForm'] = $input['activeForm'];
        }

        if ($status !== null) {
            $tasks[$id]['status'] = $status;
        }
    }

    /**
     * Task ids are observed as numeric strings ("1") in both tool_use
     * input and toolUseResult, but tolerate a real int too - anything else
     * (missing, "abc", a float, etc: malformed/partial data) is not a
     * valid id.
     */
    private static function task_id_from_mixed(mixed $taskId): ?int
    {
        if (is_int($taskId) && $taskId >= 0) {
            return $taskId;
        }

        if (is_string($taskId) && ctype_digit($taskId)) {
            return (int)$taskId;
        }

        return null;
    }

    /**
     * Parses one JSONL line into a renderable transcript entry, or null for a
     * meta-only, malformed, or content-less line. $exitPlanModeToolUseIds
     * (see find_exit_plan_mode_tool_use_ids()) is how a plan's tool_result
     * gets recognized as approved/rejected rather than rendering as a
     * generic tool_result - optional (defaults to none found), so existing
     * callers/tests that don't care about plans keep working unchanged.
     *
     * @param array<string, true> $exitPlanModeToolUseIds
     * @return array{type:string, role:?string, timestamp:?string, blocks:array<int, array{kind:string, text:string, attachments?:array<int, array{file_uuid:string, filename:string, size:int, isImage:bool, media_type:string}>}>}|null
     */
    public static function parse_transcript_line(string $line, array $exitPlanModeToolUseIds = []): ?array
    {
        $decoded = json_decode($line, true);

        if (!is_array($decoded)) {
            return null;
        }

        $type = (string)($decoded['type'] ?? '');

        if ($type === '' || in_array($type, self::transcript_meta_only_types(), true)) {
            return null;
        }

        $message = $decoded['message'] ?? null;

        if (!is_array($message)) {
            return null;
        }

        $content = $message['content'] ?? null;
        $blocks = [];
        // index within $blocks => the raw tool_use_id, tool_result blocks
        // only - kept OUTSIDE the public block shape (never merged into
        // $blocks itself) since some callers assert exact block array
        // equality; consumed below to recognize a plan's tool_result via
        // find_exit_plan_mode_tool_use_ids(), then discarded.
        $toolResultToolUseIds = [];

        if (is_string($content) && $content !== '') {
            $notification = self::parse_task_notification($content);

            $blocks[] = $notification !== null
                ? array_filter([
                    'kind' => 'task_notification',
                    'text' => $notification['result'] ?? $notification['summary'] ?? 'Subagent finished',
                    'summary' => $notification['summary'],
                    'status' => $notification['status'],
                ], static fn(mixed $v): bool => $v !== null)
                : ['kind' => 'text', 'text' => $content];
        } elseif (is_array($content)) {
            foreach ($content as $block) {
                if (is_array($block) && ($block['type'] ?? null) !== 'thinking') {
                    $summarized = self::summarize_content_block($block);
                    $blocks[] = $summarized;

                    if ($summarized['kind'] === 'tool_result' && is_string($block['tool_use_id'] ?? null)) {
                        $toolResultToolUseIds[count($blocks) - 1] = $block['tool_use_id'];
                    }
                }
            }
        }

        // Thinking is never persisted as a chat entry, even a hidden one - a
        // message that was *only* thinking (no text/tool_use alongside it, the
        // common case: Claude Code writes it as its own separate JSONL line)
        // ends up with zero blocks here and is treated the same as a
        // meta-only line, not an empty bubble with a role header and nothing
        // in it. The live "is it thinking right now" state is a separate,
        // transient signal - see SessionStatusStore/build_session_entry() in
        // host-agent/lib/Services/SessionService.php.
        if ($blocks === []) {
            return null;
        }

        // A subagent's tool_result has no direct access to what kind of agent
        // it came from (unlike the matching tool_use block, which reads its
        // own input.subagent_type directly) - Claude Code records that
        // separately on the outer JSONL line's toolUseResult field instead, so
        // it's threaded onto the tool_result block from here rather than from
        // summarize_content_block(), which only ever sees one content block
        // in isolation.
        $toolUseResult = $decoded['toolUseResult'] ?? null;
        $agentType = is_array($toolUseResult) && is_string($toolUseResult['agentType'] ?? null) ? $toolUseResult['agentType'] : null;
        $attachments = self::transcript_attachments_from_tool_use_result($toolUseResult);
        // An approved plan's own toolUseResult is a distinctive {"plan":
        // "..."} shape - unambiguous on its own, no id cross-reference
        // needed (same trust-the-outer-field simplification agent_type
        // above already makes). A rejection is NOT self-identifying (see
        // find_exit_plan_mode_tool_use_ids()'s own doc comment), so that
        // direction is only ever resolved via the id map below.
        $planApproved = is_array($toolUseResult) && is_string($toolUseResult['plan'] ?? null);

        foreach ($blocks as $i => &$block) {
            if (strlen($block['text']) > self::TRANSCRIPT_BLOCK_HARD_CAP_LENGTH) {
                $block['text'] = substr($block['text'], 0, self::TRANSCRIPT_BLOCK_HARD_CAP_LENGTH) . "\n… (truncated)";
            }

            if ($agentType !== null && $block['kind'] === 'tool_result') {
                $block['agent_type'] = $agentType;
            }

            if ($attachments !== [] && $block['kind'] === 'tool_result') {
                $block['attachments'] = $attachments;
            }

            if ($block['kind'] === 'tool_result') {
                $toolUseId = $toolResultToolUseIds[$i] ?? null;
                $isPlanResult = $planApproved || ($toolUseId !== null && isset($exitPlanModeToolUseIds[$toolUseId]));

                if ($isPlanResult) {
                    $block['plan_status'] = $planApproved ? 'approved' : 'rejected';
                    // Both real shapes are internal-instruction boilerplate,
                    // not useful shown verbatim - the approved one re-dumps
                    // the ENTIRE plan text a second time ("## Approved
                    // Plan:\n<plan again>"), already fully visible just
                    // above as its own 'plan'-kind block; the rejected one
                    // is pure "STOP what you are doing" internal wording
                    // aimed at Claude, not Andres.
                    $block['text'] = $planApproved ? 'Plan approved - starting work' : 'Plan not approved';
                }
            }
        }
        unset($block);

        return [
            'type' => $type,
            'role' => is_string($message['role'] ?? null) ? $message['role'] : null,
            'timestamp' => is_string($decoded['timestamp'] ?? null) ? $decoded['timestamp'] : null,
            'blocks' => $blocks,
        ];
    }

    /**
     * Reads one page of renderable transcript entries, newest-first request /
     * oldest-first response (natural top-to-bottom reading order once
     * rendered). Walks backward from either the end of the file (no cursor)
     * or from just before line number $before - a 1-indexed raw line count,
     * stable as a cursor since Claude Code only ever appends to its own
     * transcript files. $limit counts renderable entries, not raw lines -
     * meta-only/content-less lines are skipped for free and don't count
     * against it, so a page always has up to $limit real entries regardless
     * of how many meta lines sit between them.
     *
     * $untilRealUserMessage (Andres's own ask, 2026-08-24 - a faster way to
     * get back to "the start of the most recent real exchange" than
     * repeatedly clicking "Load older messages"): when true, $limit is
     * ignored in favor of UNTIL_USER_MESSAGE_MAX_ENTRIES, and the walk stops
     * the moment it includes a real user message (is_real_user_message()) -
     * one typed by an actual human, not Claude Code feeding a tool_result
     * back in under the same role:"user" JSONL shape. Same response shape
     * either way - the caller never needs to know WHY a page stopped where
     * it did, only where to resume (next_before/has_more), so a page that
     * hit the safety cap without finding one behaves exactly like an
     * ordinary page at that size.
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
        $upperBound = $before !== null ? max(0, min($before - 1, $totalLines)) : $totalLines; // exclusive, 0-indexed
        $exitPlanModeToolUseIds = self::find_exit_plan_mode_tool_use_ids($lines);

        $entries = [];
        $index = $upperBound;
        $effectiveLimit = $untilRealUserMessage ? self::UNTIL_USER_MESSAGE_MAX_ENTRIES : $limit;
        $foundRealUserMessage = false;

        while ($index > 0 && count($entries) < $effectiveLimit && !$foundRealUserMessage) {
            $index--;
            $parsed = self::parse_transcript_line($lines[$index], $exitPlanModeToolUseIds);

            if ($parsed !== null) {
                $entries[] = $parsed + ['line' => $index + 1];

                if ($untilRealUserMessage && self::is_real_user_message($parsed)) {
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
     * A real, human-typed user turn - role:"user" AND at least one block
     * that isn't a tool_result, since Claude Code also writes a tool's
     * result back into the transcript under the same role:"user" shape
     * (see parse_transcript_line()) even though no human typed it. A
     * text or image block (an attachment-only message, no typed text) both
     * count - either genuinely starts a new exchange.
     *
     * @param array{role:?string, blocks:array<int, array{kind:string}>} $entry
     */
    private static function is_real_user_message(array $entry): bool
    {
        if (($entry['role'] ?? null) !== 'user') {
            return false;
        }

        foreach ($entry['blocks'] as $block) {
            if (($block['kind'] ?? null) !== 'tool_result') {
                return true;
            }
        }

        return false;
    }

    /**
     * The regular-poll counterpart to read_transcript_page() above - reads
     * FORWARD from just after line number $afterLine (1-indexed, same raw
     * line count every entry already carries as its own 'line' field) to
     * the end of the file, oldest-first (already the order a poll wants to
     * append in, no reversal needed). Exists so a poll can ask the server
     * for only what's actually new since the last one it saw, instead of
     * re-fetching and re-filtering the same recent window every cycle -
     * every entry returned is guaranteed to have line > $afterLine, so the
     * caller needs no client-side re-check of its own.
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
        $exitPlanModeToolUseIds = self::find_exit_plan_mode_tool_use_ids($lines);
        $entries = [];
        $index = max(0, $afterLine); // $afterLine is 1-indexed, so this 0-indexed start is already the next unseen line

        while ($index < $totalLines && count($entries) < $limit) {
            $parsed = self::parse_transcript_line($lines[$index], $exitPlanModeToolUseIds);

            if ($parsed !== null) {
                $entries[] = $parsed + ['line' => $index + 1];
            }

            $index++;
        }

        return ['ok' => true, 'entries' => $entries];
    }

    /**
     * Re-reads a single transcript line by number and returns the real file
     * bytes for one of its attachments (see
     * transcript_attachments_from_tool_use_result() above) as base64 - the
     * browser only ever knows a file_uuid, never the real host path, so the
     * path is re-derived here from the transcript itself (a file only
     * Claude Code writes to) rather than trusted from the caller.
     *
     * @return array{ok:bool, message?:string, data?:string, media_type?:string, filename?:string, size?:int}
     */
    public static function read_attachment(string $path, int $line, string $fileUuid): array
    {
        $lines = @file($path, FILE_IGNORE_NEW_LINES);

        if ($lines === false || $line < 1 || $line > count($lines)) {
            return ['ok' => false, 'message' => 'Transcript line not found'];
        }

        $decoded = json_decode($lines[$line - 1], true);
        $toolUseResult = is_array($decoded) ? ($decoded['toolUseResult'] ?? null) : null;
        $attachments = is_array($toolUseResult) ? ($toolUseResult['attachments'] ?? null) : null;

        if (!is_array($attachments)) {
            return ['ok' => false, 'message' => 'No attachments on this line'];
        }

        $match = null;

        foreach ($attachments as $attachment) {
            if (is_array($attachment) && ($attachment['file_uuid'] ?? null) === $fileUuid) {
                $match = $attachment;
                break;
            }
        }

        if (!is_array($match) || !is_string($match['path'] ?? null) || $match['path'] === '') {
            return ['ok' => false, 'message' => 'Attachment not found'];
        }

        $filePath = $match['path'];

        if (!is_file($filePath)) {
            return ['ok' => false, 'message' => 'File no longer exists on disk'];
        }

        $size = filesize($filePath);

        if ($size === false || $size > self::ATTACHMENT_MAX_BYTES) {
            return ['ok' => false, 'message' => 'File too large to display'];
        }

        $content = @file_get_contents($filePath);

        if ($content === false) {
            return ['ok' => false, 'message' => 'Could not read file'];
        }

        return [
            'ok' => true,
            'data' => base64_encode($content),
            'media_type' => is_string($match['media_type'] ?? null) && $match['media_type'] !== '' ? $match['media_type'] : 'application/octet-stream',
            'filename' => basename($filePath),
            'size' => strlen($content),
        ];
    }
}
