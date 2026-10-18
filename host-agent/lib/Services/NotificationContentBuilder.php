<?php

declare(strict_types=1);

namespace HostAgent\Services;

/**
 * Pure formatting - the title/body text for every push notification kind
 * this app sends, plus the session-state classification that drives
 * PushDeliveryService::check_and_send_pushes()'s transition detection. No
 * file/network I/O here, deliberately: keeps this trivially testable
 * against plain arrays, independent of the stores or the actual send.
 */
class NotificationContentBuilder
{
    /**
     * The state a session's row (as returned by build_session_entry()) is in
     * for push-transition purposes - simpler than the UI's own richer state
     * (a folder-trust dialog vs. a tool-permission prompt, etc. all just
     * count as "blocked" here), since all a push notification needs to
     * answer is "does this session need me right now or not".
     *
     * @param array{blocked_reason?:?string, working?:bool} $session
     */
    public static function push_session_state(array $session): string
    {
        if (!empty($session['blocked_reason'])) {
            return 'blocked';
        }

        if (!empty($session['working'])) {
            return 'working';
        }

        return 'idle';
    }

    /**
     * The title a push notification shows - prefers the session's own live
     * pane-title task description (see build_session_entry() in
     * Sessions.php), falling back to something friendlier than the raw
     * cc-YYYYMMDD-HHMM session name when that title isn't set yet. Found
     * live: a session can hit a blocking prompt within seconds of being
     * created, before Claude Code's own title-setting has had a chance to
     * run at all - the notification for that one showed the bare session
     * name instead of anything meaningful.
     *
     * @param array{name?:mixed, title?:mixed, workdir?:mixed} $session
     */
    public static function push_notification_title(array $session): string
    {
        $title = is_string($session['title'] ?? null) ? trim($session['title']) : '';

        if ($title !== '') {
            // Claude Code prefixes its idle/non-working pane title with a
            // static icon (e.g. "✳ Fix login bug", U+2733 - distinct from the
            // animated braille spinner PromptParser::clean_pane_title() already strips,
            // which only appears while actively working). Fine in a terminal
            // title bar, out of place at the start of a phone notification -
            // \p{So} (Symbol, other) covers this and similar single-glyph
            // icon prefixes generically rather than hardcoding this one
            // codepoint.
            return preg_replace('/^\p{So}\s*/u', '', $title) ?? $title;
        }

        $workdir = is_string($session['workdir'] ?? null) ? trim($session['workdir']) : '';

        if ($workdir !== '') {
            return basename($workdir);
        }

        return is_string($session['name'] ?? null) ? $session['name'] : 'Coding session';
    }

    /**
     * Same 140-char preview convention as App\Views\BlockedPromptView::last_message_preview_html(),
     * shared by every push body that echoes real
     * user/session-generated text (as opposed to a fixed generic string) so a
     * long command or reply doesn't blow out a notification.
     */
    public static function push_truncate(string $text, int $limit = 140): string
    {
        $text = trim($text);

        return mb_strlen($text) > $limit ? mb_substr($text, 0, $limit) . '…' : $text;
    }

    /**
     * The body for a "finished working, nothing needs your input" push - the
     * actual reply text if there is one, a generic fallback otherwise (e.g.
     * the session's last turn was only tool calls, no closing text reply).
     *
     * @param array{role?:?string, blocks?:array<int, array{kind:string, text:string}>}|null $lastMessage
     */
    public static function push_finished_body(?array $lastMessage): string
    {
        if ($lastMessage === null || ($lastMessage['role'] ?? null) !== 'assistant') {
            return 'Finished - no input needed';
        }

        foreach ($lastMessage['blocks'] ?? [] as $block) {
            if (($block['kind'] ?? null) === 'text' && is_string($block['text'] ?? null) && trim($block['text']) !== '') {
                return self::push_truncate($block['text']);
            }
        }

        return 'Finished - no input needed';
    }

    /**
     * The body for a permission prompt (Bash/Write/Edit/etc. awaiting
     * approval) - the actual command/action being asked about, not a generic
     * "do you want to proceed?" (that's what the pane-scraped blocked_reason
     * usually reduces to for this prompt shape - see push_blocked_body()).
     *
     * Deliberately does NOT prefix the session's title (push_notification_title())
     * onto this - tried that, reverted. The title comes live from Claude
     * Code's own tmux pane-title, which it doesn't necessarily keep current
     * across a long, multi-topic session (can be set once early on and never
     * updated) - prefixing a stale title onto an unrelated later command
     * read as confusing noise rather than useful context.
     *
     * A Bash call's own `description` field (the short human-readable summary
     * Claude Code itself writes alongside every Bash tool_input, e.g. "Run
     * full test suite after X changes") IS prefixed when present, though -
     * real per-command context rather than a stale session-wide label, and
     * already the exact same source PromptParser::format_pending_tool_input() uses for the
     * in-app blocked-prompt card (see there). This is the
     * same descriptive text Anthropic's own Claude app shows (without a
     * command) for the identical permission prompt - this combines both.
     *
     * @param array<string, mixed> $toolInput
     */
    public static function push_permission_body(string $toolName, array $toolInput): string
    {
        switch ($toolName) {
            case 'Bash':
                $command = is_string($toolInput['command'] ?? null) ? trim($toolInput['command']) : '';

                if ($command === '') {
                    return 'Run a Bash command';
                }

                $description = is_string($toolInput['description'] ?? null) ? trim($toolInput['description']) : '';

                return self::push_truncate($description !== '' ? "{$description}: {$command}" : $command);

            case 'Write':
                $path = is_string($toolInput['file_path'] ?? null) ? $toolInput['file_path'] : null;

                return $path !== null ? self::push_truncate("Write {$path}") : 'Write a file';

            case 'Edit':
                $path = is_string($toolInput['file_path'] ?? null) ? $toolInput['file_path'] : null;

                return $path !== null ? self::push_truncate("Edit {$path}") : 'Edit a file';

            // Without this, ExitPlanMode fell through to the generic
            // default below - "Run ExitPlanMode", a literal tool name
            // that's meaningless to the user. The
            // plan's first line is usually its own "# Title" markdown
            // heading (see real plans saved under ~/.claude/plans/) -
            // stripped of the leading #/whitespace, that alone reads as a
            // real one-line preview without needing to render markdown.
            case 'ExitPlanMode':
                $plan = is_string($toolInput['plan'] ?? null) ? trim($toolInput['plan']) : '';

                if ($plan === '') {
                    return 'Review the plan';
                }

                $firstLine = trim(explode("\n", $plan, 2)[0]);
                $firstLine = ltrim($firstLine, "# \t");

                return self::push_truncate($firstLine !== '' ? $firstLine : $plan);

            default:
                return "Run {$toolName}";
        }
    }

    /**
     * The body for a newly-blocked prompt: the real command/action for a
     * permission prompt (see push_permission_body()), or the real question
     * text for an AskUserQuestion prompt / anything else without a matched
     * pending tool (the trust dialog, a stale/missing PreToolUse record) -
     * blocked_reason is already the right thing to show for those.
     *
     * push_truncate()'d here too (real send failures were seen: "Size of payload must not be greater than 4078
     * octets", the Web Push/VAPID hard limit) - unlike every other body
     * branch in this file, this fallback used to return blocked_reason
     * completely unbounded. blocked_reason is normally just the short
     * question line (see SessionService::build_session_entry()'s own
     * 'blocked_reason' => $prompt['question'] ?? null), but a verbose
     * AskUserQuestion question, or pane-scraped text for a stale/missing
     * PreToolUse record with no cleanly-parsed question at all, can run
     * long enough on its own to be the one field that pushes the whole
     * JSON-encoded {title, body, url} payload over the limit - fixed at
     * the source here, not by truncating the assembled payload as a whole
     * at send time (which could cut a multi-byte UTF-8 character in half,
     * or land inside the JSON structure itself rather than just the text).
     *
     * @param array{blocked_reason?:mixed, prompt_tool_name?:mixed, prompt_tool_input?:mixed, prompt_external?:mixed} $session
     */
    public static function push_blocked_body(array $session): string
    {
        $toolName = is_string($session['prompt_tool_name'] ?? null) ? $session['prompt_tool_name'] : null;
        $toolInput = is_array($session['prompt_tool_input'] ?? null) ? $session['prompt_tool_input'] : null;

        // A Codex Remote-owned block has no tool of its own to describe; its
        // reason text is the notice that points back to Codex Remote.
        if (!empty($session['prompt_external'])) {
            return self::push_truncate((string)($session['blocked_reason'] ?? 'Waiting on input'));
        }

        if ($toolName !== null && $toolName !== 'AskUserQuestion' && $toolInput !== null) {
            return self::push_permission_body($toolName, $toolInput);
        }

        return self::push_truncate((string)($session['blocked_reason'] ?? 'Waiting on input'));
    }

    /**
     * The title for a newly-blocked prompt's push notification - unlike
     * push_notification_title() alone (which only ever conveys WHICH session
     * this is about), this leads with WHAT KIND of prompt it is. No "Claude"
     * wording (unlike an earlier version of this) - iOS already attributes
     * every notification from this app to it via the icon and its own "from
     * <manifest name>" line (not something the Notification API can
     * suppress - it's OS-level attribution for any installed PWA's web
     * push), so repeating "Claude" in the title text itself was redundant.
     * Multiple simultaneous sessions is this whole app's reason to exist
     * (unlike a single-conversation mobile app), so the session's own title
     * is still folded in after the type, not dropped - "which session"
     * still matters here in a way it doesn't for a single-session app.
     * Every branch is type-labeled, including the folder-trust dialog and
     * the generic fallback - no case should read as just a bare title with
     * no hint of what kind of prompt it actually is.
     *
     * @param array{blocked_reason?:mixed, prompt_tool_name?:mixed, prompt_is_folder_trust?:mixed}&array{name?:mixed, title?:mixed, workdir?:mixed} $session
     */
    public static function push_blocked_title(array $session): string
    {
        $toolName = is_string($session['prompt_tool_name'] ?? null) ? $session['prompt_tool_name'] : null;
        $sessionTitle = self::push_notification_title($session);

        if (!empty($session['prompt_is_folder_trust'])) {
            return "Needs folder trust: {$sessionTitle}";
        }

        if ($toolName === 'AskUserQuestion') {
            return "Has a question: {$sessionTitle}";
        }

        if ($toolName === 'ExitPlanMode') {
            return "Plan ready for review: {$sessionTitle}";
        }

        if ($toolName !== null) {
            return "Needs permission: {$sessionTitle}";
        }

        return "Needs input: {$sessionTitle}";
    }

    /**
     * The title for a "finished working, nothing needs your input" push -
     * same type-labeled convention as push_blocked_title(), so every
     * notification this app sends says what KIND of event it is, not just
     * which session.
     *
     * @param array{name?:mixed, title?:mixed, workdir?:mixed} $session
     */
    public static function push_finished_title(array $session): string
    {
        return "Finished: " . self::push_notification_title($session);
    }

    /**
     * A quota bucket key ('session', 'week_all', 'week_fable', ...) -> its
     * human label. Mirrors quota-footer.js's own label() function exactly
     * (JS-side counterpart) so a quota push notification names a bucket the
     * same way the in-app footer already does.
     */
    public static function push_quota_bucket_label(string $key): string
    {
        if ($key === 'session') {
            return 'Session';
        }

        if ($key === 'week_all') {
            return 'Week';
        }

        if ($key === 'gemini-weekly') {
            return 'Gemini';
        }

        if ($key === '3p-weekly') {
            return 'Claude & GPT';
        }

        $name = ucwords(str_replace('_', ' ', preg_replace('/^week_/', '', $key) ?? $key));

        return "{$name} (week)";
    }

    /**
     * Title/body for a bucket crossing the "close to over" threshold (see
     * PushDeliveryService::push_quota_near_threshold_pct()) - fires once per
     * crossing, not every tick it stays above it (see check_and_send_quota_pushes()).
     */
    public static function push_quota_near_title(string $bucketKey): string
    {
        return 'Quota near limit: ' . self::push_quota_bucket_label($bucketKey);
    }

    public static function push_quota_near_body(string $bucketKey, int $pct): string
    {
        return "{$pct}% of your " . self::push_quota_bucket_label($bucketKey) . ' quota used';
    }

    /** Title/body for a bucket reaching (or passing) 100%. */
    public static function push_quota_over_title(string $bucketKey): string
    {
        return 'Quota limit reached: ' . self::push_quota_bucket_label($bucketKey);
    }

    public static function push_quota_over_body(string $bucketKey, int $pct): string
    {
        return self::push_quota_bucket_label($bucketKey) . " quota is at {$pct}%";
    }

    /**
     * Title/body for a bucket's window actually rolling over (resets_at
     * moved forward - see check_and_send_quota_pushes()), regardless of how
     * close to the limit it had gotten before resetting.
     */
    public static function push_quota_reset_title(string $bucketKey): string
    {
        return 'Quota reset: ' . self::push_quota_bucket_label($bucketKey);
    }

    public static function push_quota_reset_body(string $bucketKey): string
    {
        return 'Your ' . self::push_quota_bucket_label($bucketKey) . ' quota has reset';
    }
}
