<?php

declare(strict_types=1);

namespace App\Views;

use App\AdvisePolicy;

/**
 * Render helpers for the UI (templates under src/partials/ui/). Cards are
 * rendered server-side and swapped in by public/js/ui.js.
 */
class UiView extends View
{
    private const PREVIEW_CHARS = 220;

    public const AGENT_OPTIONS = ['claude' => 'Claude Code', 'antigravity' => 'Antigravity', 'opencode' => 'OpenCode', 'codex' => 'Codex', 'ollama' => 'Ollama (local)'];

    public const MODE_OPTIONS = ['manual' => 'Manual', 'accept edits' => 'Accept Edits', 'plan' => 'Plan', 'auto' => 'Auto'];

    /** The default account (null, '' or 'personal') shows as "Personal". */
    public static function profile_label(?string $profile): string
    {
        return match ($profile) {
            null, '', 'personal' => 'Personal',
            default => ucfirst($profile),
        };
    }

    public static function relative_time(int $timestamp): string
    {
        $diff = time() - $timestamp;

        if ($diff < 60) {
            return 'just now';
        }

        if ($diff < 3600) {
            return intdiv($diff, 60) . ' min ago';
        }

        if ($diff < 86400) {
            $h = intdiv($diff, 3600);
            return "{$h} hr" . ($h > 1 ? 's' : '') . ' ago';
        }

        $d = intdiv($diff, 86400);
        return "{$d} day" . ($d > 1 ? 's' : '') . ' ago';
    }

    /** @param array<string, mixed> $data */
    public static function dashboard_page(array $data): string
    {
        return self::render('ui/dashboard', $data);
    }

    /** @param array<string, mixed> $data */
    public static function session_page(array $data): string
    {
        return self::render('ui/session', $data);
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
    public static function sessions_html(array $sessions, string $csrfToken, ?callable $quota = null): string
    {
        if ($sessions === []) {
            return self::render('ui/empty', ['title' => 'No sessions yet', 'text' => 'Start one with the + button.']);
        }

        $html = '';

        foreach ($sessions as $s) {
            $html .= self::card_html($s, $csrfToken, $quota);
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
            $rows .= self::render('ui/bare-row', [
                'pid' => (int)$b['pid'],
                'title' => (string)($b['resolved_title'] ?? $b['title'] ?? 'Claude process'),
                'cwd' => $b['cwd'] ?? null,
                'tmuxSession' => $b['tmux_session'] ?? null,
                'startedAt' => ($b['started_at'] ?? null) !== null ? self::relative_time((int)$b['started_at']) : null,
            ]);
        }

        return self::render('ui/bare-list', ['rowsHtml' => $rows]);
    }

    /** @param array<int, array<string, mixed>> $archived */
    public static function archived_html(array $archived, string $csrfToken): string
    {
        if ($archived === []) {
            return self::render('ui/empty', ['title' => 'Nothing archived', 'text' => 'Finished sessions show up here.']);
        }

        $html = '';

        foreach ($archived as $a) {
            $agentId = (string)($a['agent'] ?? 'claude');
            $html .= self::render('ui/archived-row', [
                'agentSessionId' => (string)$a['agent_session_id'],
                'title' => (string)($a['title'] ?? $a['agent_session_id']),
                'cwd' => $a['cwd'] ?? null,
                'relativeTime' => self::relative_time((int)($a['last_activity'] ?? 0)),
                'agentId' => $agentId,
                'agentLabel' => (string)($a['agent_label'] ?? 'Claude Code'),
                'profile' => $a['profile'] ?? null,
                'csrfToken' => $csrfToken,
            ]);
        }

        return $html;
    }

    /** @param array<string, mixed> $s */
    public static function card_html(array $s, string $csrfToken, ?callable $quota = null): string
    {
        $name = (string)$s['name'];
        $title = (string)($s['title'] ?? $name);
        $agentId = (string)($s['agent'] ?? 'claude');
        $status = self::status_of($s);

        return self::render('ui/card', [
            'name' => $name,
            'title' => $title,
            'status' => $status,
            'agentId' => $agentId,
            'agentLabel' => (string)($s['agent_label'] ?? 'Claude Code'),
            'profile' => $agentId === 'claude' ? self::profile_label($s['profile'] ?? null) : null,
            'runtime' => (string)($s['runtime'] ?? 'tmux'),
            'kind' => (string)($s['kind'] ?? 'user'),
            'conversationId' => $s['agent_session_id'] ?? null,
            'parentSessionId' => $s['parent_session_id'] ?? null,
            'starred' => !empty($s['starred']),
            'workdir' => $s['workdir'] ?? null,
            'relativeTime' => self::relative_time((int)($s['activity'] ?? time())),
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
            'adviseShown' => self::advise_visible($s, $quota),
        ]);
    }

    /**
     * The "continue here or start a new one?" button only matters once the prompt cache is cold or the account is
     * nearly out of quota; before that the session just continues. $quota returns the host agent's `quota` reply and
     * is only called when idle time alone does not decide.
     *
     * @param array<string, mixed> $s a session row or detail (activity, agent, profile)
     */
    public static function advise_visible(array $s, ?callable $quota): bool
    {
        if (time() - (int)($s['activity'] ?? time()) >= AdvisePolicy::CACHE_TTL_SECONDS) {
            return true;
        }

        $reply = $quota !== null ? $quota() : null;
        $q = ($s['agent'] ?? 'claude') === 'claude'
            ? ($reply['claude_profiles'][(string)(($s['profile'] ?? '') ?: 'personal')]['quota'] ?? null)
            : ($reply['agents']['codex']['quota'] ?? null);

        foreach (['session', 'week_all'] as $window) {
            if (($q[$window]['pct'] ?? 0) >= AdvisePolicy::QUOTA_PARK_PCT) {
                return true;
            }
        }

        return false;
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
