<?php

declare(strict_types=1);

namespace HostAgent\Services;

/**
 * The session page's git-branch line (Dibs 91) - deliberately agent-agnostic:
 * every session already carries a workdir regardless of which agent runs it,
 * and a branch name is a property of that directory, not of the agent. Kept
 * separate from StatuslineMarkerService's git_worktree (Claude tmux only, read
 * from the statusline JSON, and a worktree NAME rather than a branch) - this
 * works for every runtime because it never touches the pane or a transcript.
 */
class GitBranchService
{
    /**
     * Null for a missing/non-existent workdir, a directory with no git repo
     * (including one still being cloned/initialized), or a detached HEAD
     * (`--show-current` prints nothing in that case) - never a guess, same
     * "don't guess" discipline as SelectableModel::family_from_raw_model().
     */
    public static function current_branch(?string $workdir): ?string
    {
        if ($workdir === null || $workdir === '' || !is_dir($workdir)) {
            return null;
        }

        $result = ProcessRunner::run_process(['git', '-C', $workdir, 'branch', '--show-current']);
        $branch = trim($result['stdout']);

        return $result['exit'] === 0 && $branch !== '' ? $branch : null;
    }
}
