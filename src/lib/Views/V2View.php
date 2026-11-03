<?php

declare(strict_types=1);

namespace App\Views;

/**
 * Render helpers for the /v2 UI (templates under src/partials/v2/). Cards are
 * rendered server-side and swapped in by public/js/v2.js, the same
 * one-source-of-markup arrangement the classic dashboard uses.
 */
class V2View extends View
{
    private const PREVIEW_CHARS = 220;

    /** @param array<string, mixed> $data */
    public static function dashboard_page(array $data): string
    {
        return self::render('v2/dashboard', $data);
    }

    /** @param array<string, mixed> $data */
    public static function session_page(array $data): string
    {
        return self::render('v2/session', $data);
    }

    /**
     * @param array<int, array<string, mixed>> $sessions
     * @return array{total:int, blocked:int, working:int, idle:int}
     */
    public static function counts(array $sessions): array
    {
        $counts = ['total' => count($sessions), 'blocked' => 0, 'working' => 0, 'idle' => 0];

        foreach ($sessions as $s) {
            $status = self::status_of($s);
            $counts[$status]++;
        }

        return $counts;
    }

    /** @param array<int, array<string, mixed>> $sessions */
    public static function sessions_html(array $sessions, string $csrfToken): string
    {
        if ($sessions === []) {
            return self::render('v2/empty', ['title' => 'No sessions yet', 'text' => 'Start one with the + button.']);
        }

        $html = '';

        foreach ($sessions as $s) {
            $html .= self::card_html($s, $csrfToken);
        }

        return $html;
    }

    /** @param array<int, array<string, mixed>> $bare */
    public static function bare_html(array $bare, string $csrfToken): string
    {
        if ($bare === []) {
            return '';
        }

        $rows = '';

        foreach ($bare as $b) {
            $rows .= self::render('v2/bare-row', [
                'pid' => (int)$b['pid'],
                'title' => (string)($b['resolved_title'] ?? $b['title'] ?? 'Claude process'),
                'cwd' => $b['cwd'] ?? null,
                'tmuxSession' => $b['tmux_session'] ?? null,
                'startedAt' => ($b['started_at'] ?? null) !== null ? SessionRowView::relative_time((int)$b['started_at']) : null,
            ]);
        }

        return self::render('v2/bare-list', ['rowsHtml' => $rows]);
    }

    /** @param array<int, array<string, mixed>> $archived */
    public static function archived_html(array $archived, string $csrfToken): string
    {
        if ($archived === []) {
            return self::render('v2/empty', ['title' => 'Nothing archived', 'text' => 'Finished sessions show up here.']);
        }

        $html = '';

        foreach ($archived as $a) {
            $agentId = (string)($a['agent'] ?? 'claude');
            $html .= self::render('v2/archived-row', [
                'agentSessionId' => (string)$a['agent_session_id'],
                'title' => (string)($a['title'] ?? $a['agent_session_id']),
                'cwd' => $a['cwd'] ?? null,
                'relativeTime' => SessionRowView::relative_time((int)($a['last_activity'] ?? 0)),
                'agentId' => $agentId,
                'agentLabel' => (string)($a['agent_label'] ?? 'Claude Code'),
                'profile' => $a['profile'] ?? null,
                'csrfToken' => $csrfToken,
            ]);
        }

        return $html;
    }

    /** @param array<string, mixed> $s */
    public static function card_html(array $s, string $csrfToken): string
    {
        $name = (string)$s['name'];
        $title = (string)($s['title'] ?? $name);
        $agentId = (string)($s['agent'] ?? 'claude');
        $status = self::status_of($s);

        return self::render('v2/card', [
            'name' => $name,
            'title' => $title,
            'status' => $status,
            'agentId' => $agentId,
            'agentLabel' => (string)($s['agent_label'] ?? 'Claude Code'),
            'profile' => $agentId === 'claude' ? SessionRowView::profile_label($s['profile'] ?? null) : null,
            'runtime' => (string)($s['runtime'] ?? 'tmux'),
            'kind' => (string)($s['kind'] ?? 'user'),
            'parentSessionId' => $s['parent_session_id'] ?? null,
            'starred' => !empty($s['starred']),
            'workdir' => $s['workdir'] ?? null,
            'relativeTime' => SessionRowView::relative_time((int)($s['activity'] ?? time())),
            'contextPct' => isset($s['context_used_percentage']) ? (int)round((float)$s['context_used_percentage']) : null,
            'worktree' => $s['git_worktree'] ?? null,
            'preview' => self::preview_text($s['last_message'] ?? null),
            'previewRole' => (string)($s['last_message']['role'] ?? ''),
            'blockedReason' => $status === 'blocked' ? (string)$s['blocked_reason'] : null,
            'promptContext' => (string)($s['prompt_context'] ?? ''),
            'promptOptions' => is_array($s['prompt_options'] ?? null) ? $s['prompt_options'] : [],
            'promptIsFolderTrust' => !empty($s['prompt_is_folder_trust']),
            'promptHasQuestions' => !empty($s['prompt_questions']),
            'csrfToken' => $csrfToken,
        ]);
    }

    /** @param array<string, mixed> $s */
    private static function status_of(array $s): string
    {
        if (!empty($s['blocked_reason'])) {
            return 'blocked';
        }

        return !empty($s['working']) || ($s['status'] ?? '') === 'working' ? 'working' : 'idle';
    }

    /** First text block of the newest message, shortened. */
    private static function preview_text(mixed $entry): string
    {
        if (!is_array($entry)) {
            return '';
        }

        foreach ($entry['blocks'] ?? [] as $block) {
            if (($block['kind'] ?? '') === 'text' && trim((string)($block['text'] ?? '')) !== '') {
                // Agents answer in Markdown; a plain-text preview reads better without its markers.
                $text = trim((string)preg_replace('/\s+/', ' ', str_replace(['**', '`'], '', (string)$block['text'])));

                return mb_strlen($text) > self::PREVIEW_CHARS ? mb_substr($text, 0, self::PREVIEW_CHARS) . '…' : $text;
            }
        }

        return '';
    }
}
