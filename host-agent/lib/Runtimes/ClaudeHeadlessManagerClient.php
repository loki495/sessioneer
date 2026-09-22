<?php

declare(strict_types=1);

namespace HostAgent\Runtimes;

use HostAgent\Services\Config;

/**
 * Client for sessioneer-claude-headless-manager.service (see
 * host-agent/claude_headless_manager.php). Construct with an explicit
 * socket path in tests; production callers use the configured default.
 *
 * The stop call replies only after the child process has exited (up to the
 * configured stop grace plus the SIGTERM/SIGKILL escalation), so its
 * timeout is deliberately longer than the default.
 */
class ClaudeHeadlessManagerClient extends UnixSocketJsonClient
{
    public function __construct(?string $socketPath = null, ?int $timeoutSeconds = null, bool $retryConnect = true)
    {
        parent::__construct(
            $socketPath ?? Config::claude_headless_socket(),
            'Claude headless manager',
            $timeoutSeconds ?? (Config::claude_headless_stop_grace_seconds() + 20),
            $retryConnect,
        );
    }
}
