<?php

declare(strict_types=1);

namespace HostAgent\Runtimes;

use HostAgent\Services\Config;

/** One-request/one-response client for Sessioneer's persistent Codex bridge. */
class CodexBridgeClient extends UnixSocketJsonClient
{
    public function __construct(?string $socketPath = null)
    {
        parent::__construct($socketPath ?? Config::codex_bridge_socket(), 'Codex bridge');
    }
}
