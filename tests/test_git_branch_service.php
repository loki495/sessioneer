<?php

declare(strict_types=1);

/**
 * GitBranchService and its session_detail wiring (Dibs 91) - agent-agnostic
 * by design: every session already carries a workdir, and a branch name is a
 * property of that directory, not of the agent running in it. Uses real
 * scratch git repos under sys_get_temp_dir(), never the real host state.
 */

require __DIR__ . '/lib/assert.php';
require dirname(__DIR__) . '/host-agent/lib/Sessions.php';

use HostAgent\Services\GitBranchService;

echo "GitBranchService::current_branch\n";

assert_equal(null, GitBranchService::current_branch(null), 'a null workdir has no branch');
assert_equal(null, GitBranchService::current_branch(''), 'an empty workdir has no branch');
assert_equal(null, GitBranchService::current_branch('/does/not/exist/' . bin2hex(random_bytes(4))), 'a missing directory has no branch');

$plainDir = sys_get_temp_dir() . '/sessioneer-test-not-a-repo-' . bin2hex(random_bytes(4));
mkdir($plainDir, 0700, true);
assert_equal(null, GitBranchService::current_branch($plainDir), 'a directory with no git repo has no branch');

$repo = sys_get_temp_dir() . '/sessioneer-test-git-branch-' . bin2hex(random_bytes(4));
mkdir($repo, 0700, true);
exec('git -C ' . escapeshellarg($repo) . ' init -q -b main 2>&1');
exec('git -C ' . escapeshellarg($repo) . ' -c user.email=t@t -c user.name=t commit --allow-empty -q -m init 2>&1');
assert_equal('main', GitBranchService::current_branch($repo), 'a fresh repo reports its initial branch');

exec('git -C ' . escapeshellarg($repo) . ' checkout -q -b feature/widget 2>&1');
assert_equal('feature/widget', GitBranchService::current_branch($repo), 'a checked-out branch is picked up');

exec('git -C ' . escapeshellarg($repo) . ' checkout -q main 2>&1');
exec('git -C ' . escapeshellarg($repo) . ' checkout -q --detach 2>&1');
assert_equal(null, GitBranchService::current_branch($repo), 'a detached HEAD is not reported as a branch');

register_shutdown_function(static function () use ($repo, $plainDir): void {
    exec('rm -rf ' . escapeshellarg($repo) . ' ' . escapeshellarg($plainDir));
});

echo "sessioneer_with_git_branch\n";

exec('git -C ' . escapeshellarg($repo) . ' checkout -q main 2>&1');
assert_equal(['ok' => false, 'message' => 'nope'], sessioneer_with_git_branch(['ok' => false, 'message' => 'nope']), 'a failed detail is passed through untouched');
$withBranch = sessioneer_with_git_branch(['ok' => true, 'workdir' => $repo]);
assert_equal('main', $withBranch['git_branch'], 'a successful detail with a git workdir gets git_branch');
$noWorkdir = sessioneer_with_git_branch(['ok' => true]);
assert_equal(null, $noWorkdir['git_branch'], 'a detail with no workdir gets a null git_branch, not a missing key');

test_exit();
