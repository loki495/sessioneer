<?php

declare(strict_types=1);

/**
 * Persistent manager for headless Claude Code sessions: owns one long-lived
 * `claude -p --input-format stream-json` child per active session and speaks a
 * narrow Sessioneer-shaped UNIX-socket API to the request-per-process host
 * agent. See HostAgent\Runtimes\ClaudeHeadlessManager for the design notes.
 *
 * Run by sessioneer-claude-headless-manager.service.
 */

require_once __DIR__ . '/../vendor/autoload.php';

use HostAgent\Runtimes\ClaudeHeadlessManager;
use HostAgent\Services\Config;

if (Config::claude_bin() === '') {
    fwrite(STDERR, "CLAUDE_BIN is not configured\n");
    exit(1);
}

try {
    ClaudeHeadlessManager::from_config()->run();
} catch (\RuntimeException $e) {
    fwrite(STDERR, $e->getMessage() . "\n");
    exit(1);
}
