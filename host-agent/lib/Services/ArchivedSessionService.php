<?php

declare(strict_types=1);

namespace HostAgent\Services;

use HostAgent\Stores\SidecarStore;
use HostAgent\Runtimes\RuntimeType;

/**
 * Archived/dormant session listing and dashboard-wide transcript search.
 * Split out of SessionService.php (2026-08-24 readability audit - see the
 * plan this followed) - depends on core SessionService (list_all_sessions,
 * title_cascade) but nothing else. Methods/bodies moved verbatim, no
 * behavior changes.
 */
class ArchivedSessionService
{
    /**
     * Every known transcript NOT in $excludeAgentSessionIds (the
     * currently-tracked sessions already shown in the main list) - the
     * dormant/archived half of the unify-claude-sessions plan's dashboard
     * segmentation. Sorted most-recently-active first (the file's own
     * mtime - the simplest available proxy for "last touched" without
     * re-parsing a potentially huge transcript).
     *
     * @param string[] $excludeAgentSessionIds
     * @return array<int, array{agent_session_id:string, cwd:?string, title:string, last_activity:int}>
     */
    public static function list_archived_sessions(array $excludeAgentSessionIds): array
    {
        $exclude = array_flip($excludeAgentSessionIds);
        $archived = [];

        foreach (TranscriptService::list_all_transcripts() as $t) {
            if (isset($exclude[$t['agent_session_id']])) {
                continue;
            }

            $archived[] = [
                'agent_session_id' => $t['agent_session_id'],
                'cwd' => $t['cwd'],
                'title' => SessionService::title_cascade($t['ai_title'], null, $t['cwd'], $t['agent_session_id']),
                'last_activity' => $t['last_activity'],
                'agent' => 'claude',
                'agent_label' => 'Claude Code',
            ];
        }

        foreach (AntigravityTranscriptService::list_all_transcripts() as $t) {
            if (isset($exclude[$t['agent_session_id']])) {
                continue;
            }

            $archived[] = [
                'agent_session_id' => $t['agent_session_id'],
                'cwd' => $t['cwd'],
                'title' => SessionService::title_cascade(null, null, $t['cwd'], $t['agent_session_id']),
                'last_activity' => $t['last_activity'],
                'agent' => $t['agent'] ?? 'antigravity',
                'agent_label' => 'Antigravity',
            ];
        }

        // OpenCode archived sessions: every session in opencode.db not
        // currently tracked (i.e. dormant). Title comes from session.title
        // directly (see OpenCodeTranscriptService::find_session_title), not
        // a transcript-file ai-title.
        $opencodeDbPath = Config::opencode_db_path();

        if (is_file($opencodeDbPath)) {
            try {
                $pdo = new \PDO('sqlite:' . $opencodeDbPath, null, null, [
                    \PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION,
                    \PDO::SQLITE_ATTR_OPEN_FLAGS => \PDO::SQLITE_OPEN_READONLY,
                ]);
                $pdo->exec('PRAGMA busy_timeout=5000');
                $stmt = $pdo->query('SELECT id, directory, title, time_updated FROM session ORDER BY time_updated DESC');
                $rows = $stmt->fetchAll(\PDO::FETCH_ASSOC) ?: [];
                foreach ($rows as $row) {
                    $id = is_string($row['id'] ?? null) ? $row['id'] : null;
                    if ($id === null || isset($exclude[$id])) {
                        continue;
                    }
                    if (!OpenCodeTranscriptService::is_opencode_id($id)) {
                        continue;
                    }
                    $cwd = is_string($row['directory'] ?? null) && $row['directory'] !== '' ? $row['directory'] : null;
                    $title = is_string($row['title'] ?? null) && trim($row['title']) !== '' ? $row['title'] : $id;
                    $archived[] = [
                        'agent_session_id' => $id,
                        'cwd' => $cwd,
                        'title' => $title,
                        'last_activity' => is_numeric($row['time_updated'] ?? null) ? (int)($row['time_updated'] / 1000) : 0,
                        'agent' => 'opencode',
                        'agent_label' => 'OpenCode',
                    ];
                }
            } catch (\Throwable $e) {
                // Best-effort: on DB error, just skip opencode archived
            }
        }

        // Codex threads live in app-server's durable catalog rather than a
        // local transcript directory. Include both dormant non-archived
        // threads (those outside Sessioneer's active window) and explicitly archived
        // threads; the tracked-id exclusion keeps recent rows in one section.
        foreach ([false, true] as $nativeArchived) {
            $catalog = CodexTranscriptService::list_threads($nativeArchived, null, true);
            if (($catalog['ok'] ?? false) !== true) continue;

            foreach (($catalog['threads'] ?? []) as $thread) {
                $id = is_string($thread['id'] ?? null) ? $thread['id'] : null;
                if ($id === null || isset($exclude[$id])) continue;

                $cwd = is_string($thread['cwd'] ?? null) && $thread['cwd'] !== '' ? $thread['cwd'] : null;
                $nativeTitle = is_string($thread['name'] ?? null) && trim($thread['name']) !== ''
                    ? $thread['name']
                    : (is_string($thread['preview'] ?? null) ? $thread['preview'] : null);
                $archived[] = [
                    'agent_session_id' => $id,
                    'cwd' => $cwd,
                    'title' => SessionService::title_cascade($nativeTitle, null, $cwd, $id),
                    'last_activity' => (int)($thread['updatedAt'] ?? $thread['createdAt'] ?? 0),
                    'agent' => 'codex',
                    'agent_label' => 'Codex',
                ];
            }
        }

        // app-server may omit a freshly thread/archive'd rollout from both
        // list partitions. Merge Codex's durable archive directory as the
        // authoritative fallback, de-duplicating catalog rows by thread id.
        $knownIds = array_flip(array_column($archived, 'agent_session_id'));
        foreach (CodexTranscriptService::list_archived_rollouts() as $thread) {
            $id = $thread['id'];
            if (isset($exclude[$id]) || isset($knownIds[$id])) continue;
            $cwd = is_string($thread['cwd'] ?? null) && $thread['cwd'] !== '' ? $thread['cwd'] : null;
            $archived[] = [
                'agent_session_id' => $id,
                'cwd' => $cwd,
                'title' => SessionService::title_cascade(null, null, $cwd, $id),
                'last_activity' => (int)($thread['updatedAt'] ?? $thread['createdAt'] ?? 0),
                'agent' => 'codex',
                'agent_label' => 'Codex',
            ];
            $knownIds[$id] = true;
        }

        usort($archived, fn(array $a, array $b) => $b['last_activity'] <=> $a['last_activity']);

        return $archived;
    }

    /**
     * The dispatcher-facing wrapper around list_archived_sessions() - an
     * on-demand action (only ever called when Andres actually opens the
     * dashboard's archived-sessions toggle, never part of the regular
     * poll - see this project's own workflow reminders about being extra
     * careful with anything periodic vs explicitly user-triggered) that
     * computes the exclude set itself by re-running list_all_sessions().
     * That's a second full tracked-session scan on top of whatever poll
     * already did one moments ago, but it's cheap and only happens once
     * per toggle-open, not worth threading the caller's already-known
     * list through an extra request parameter for.
     *
     * @return array{archived: array<int, array>}
     */
    public static function list_archived_dashboard(): array
    {
        $trackedIds = [];

        foreach (SessionService::list_all_sessions()['sessions'] as $s) {
            if (is_string($s['agent_session_id'] ?? null)) {
                $trackedIds[] = $s['agent_session_id'];
            }
        }

        // Found live 2026-09-11: a bare (untracked) claude process - e.g.
        // one typed by hand directly in a terminal, no tmux involved at
        // all - is just as "not dormant" as a tracked one, but was never
        // excluded here; see BareProcessService::live_bare_agent_session_
        // ids()'s own docblock for the full incident and reasoning.
        $trackedIds = array_merge($trackedIds, BareProcessService::live_bare_agent_session_ids());

        return ['archived' => self::list_archived_sessions($trackedIds)];
    }

    /**
     * Dashboard-wide content search - unlike the archived list's own
     * client-side title/name filter (index.js's filterArchivedRows(),
     * which only ever matches what's already rendered in a row), this
     * greps every known transcript's real message content, live and
     * archived alike, server-side. On-demand only (the dashboard's own
     * search box, debounced client-side - never part of the regular poll),
     * same "expensive, user-triggered, not periodic" reasoning as
     * list_archived_dashboard() above.
     *
     * A result's own agent_session_id doubling as a currently-live tmux
     * session name is what tells the caller which page to link to
     * (session.php vs archived_session.php) - same live-vs-archived
     * reconciliation list_archived_dashboard() already does, reused here
     * rather than a second tracked-session scan.
     *
     * @return array{ok:bool, results:array<int, array{agent_session_id:string, session_name:?string, title:string, cwd:?string, last_activity:int, matches:array<int, array{line:int, snippet:string, role:?string, kind:string}>}>}
     */
    public static function search_transcripts(string $query, int $maxSessions, int $maxMatchesPerSession): array
    {
        if (trim($query) === '') {
            return ['ok' => true, 'results' => []];
        }

        $liveNamesByClaudeId = [];

        foreach (SessionService::list_all_sessions()['sessions'] as $s) {
            if (is_string($s['agent_session_id'] ?? null)) {
                $liveNamesByClaudeId[$s['agent_session_id']] = $s['name'];
            }
        }

        // Merge headless sessions into the live-name map too.
        foreach (SidecarStore::list_runtime_sidecars(RuntimeType::HEADLESS) as $row) {
            if (is_string($row['session_name'] ?? null)) {
                $liveNamesByClaudeId[$row['session_name']] = $row['session_name'];
            }
        }

        $results = [];

        // Claude Code transcripts (JSONL files).
        $transcripts = TranscriptService::list_all_transcripts();
        usort($transcripts, fn(array $a, array $b) => $b['last_activity'] <=> $a['last_activity']);

        foreach ($transcripts as $t) {
            $matches = TranscriptService::search_transcript_file($t['path'], $query, max(1, $maxMatchesPerSession));

            if ($matches === []) {
                continue;
            }

            $results[] = [
                'agent_session_id' => $t['agent_session_id'],
                'session_name' => $liveNamesByClaudeId[$t['agent_session_id']] ?? null,
                'title' => SessionService::title_cascade($t['ai_title'], null, $t['cwd'], $t['agent_session_id']),
                'cwd' => $t['cwd'],
                'last_activity' => $t['last_activity'],
                'matches' => $matches,
            ];

            if (count($results) >= $maxSessions) {
                break;
            }
        }

        // OpenCode transcripts (opencode.db).
        if (count($results) < $maxSessions) {
            $ocTranscripts = OpenCodeTranscriptService::list_all_transcripts();

            foreach ($ocTranscripts as $t) {
                $matches = OpenCodeTranscriptService::search_transcript($t['session_id'], $query, max(1, $maxMatchesPerSession));

                if ($matches === []) {
                    continue;
                }

                $results[] = [
                    'agent_session_id' => $t['session_id'],
                    'session_name' => $liveNamesByClaudeId[$t['session_id']] ?? null,
                    'title' => SessionService::title_cascade($t['title'], null, $t['cwd'], $t['session_id']),
                    'cwd' => $t['cwd'],
                    'last_activity' => $t['last_activity'],
                    'matches' => $matches,
                ];

                if (count($results) >= $maxSessions) {
                    break;
                }
            }
        }

        return ['ok' => true, 'results' => $results];
    }

    /**
     * Per-session content search for a currently-live (tracked) session -
     * resolves $name to its agent_session_id via the sidecar, same
     * lookup session_history() already does, then defers to
     * transcript_search_for_claude_session() below.
     *
     * @return array{ok:bool, matches?:array<int, array>, message?:string}
     */
    public static function session_transcript_search(string $name, string $query, int $maxMatches): array
    {
        $sidecar = SidecarStore::read_sidecar($name);
        $agentSessionId = $sidecar['agent_session_id'] ?? null;

        if (!is_string($agentSessionId)) {
            return ['ok' => false, 'message' => 'No transcript recorded for this session'];
        }

        return self::transcript_search_for_claude_session($agentSessionId, $query, $maxMatches);
    }

    /**
     * The archived-session-view counterpart to session_transcript_search()
     * above - same search, keyed straight by $agentSessionId with no
     * sidecar/tmux-name lookup, same reasoning as archived_session_history().
     *
     * @return array{ok:bool, matches?:array<int, array>, message?:string}
     */
    public static function archived_session_transcript_search(string $agentSessionId, string $query, int $maxMatches): array
    {
        return self::transcript_search_for_claude_session($agentSessionId, $query, $maxMatches);
    }

    /**
     * Shared by session_transcript_search()/archived_session_transcript_search() -
     * both just want transcript matches once they know which agent_session_id
     * to search, same split as SessionDetailService::
     * transcript_page_for_claude_session() uses for paging.
     *
     * @return array{ok:bool, matches?:array<int, array>, message?:string}
     */
    private static function transcript_search_for_claude_session(string $agentSessionId, string $query, int $maxMatches): array
    {
        $path = TranscriptRouter::find_transcript_path($agentSessionId);

        if ($path === null) {
            return ['ok' => false, 'message' => 'Transcript file not found'];
        }

        if (TranscriptRouter::is_opencode_path($path)) {
            return ['ok' => true, 'matches' => OpenCodeTranscriptService::search_transcript($agentSessionId, $query, max(1, min($maxMatches, 100)))];
        }

        return ['ok' => true, 'matches' => TranscriptService::search_transcript_file($path, $query, max(1, min($maxMatches, 100)))];
    }
}
