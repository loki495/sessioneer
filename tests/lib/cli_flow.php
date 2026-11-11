<?php
declare(strict_types=1);

require_once __DIR__ . '/assert.php';
require_once __DIR__ . '/harness.php';

/**
 * Shared driver for the CLI flow tests: a fake host agent on a temp socket that records every
 * request and answers a canned reply, and a runner for bin/sessioneer. Nothing here talks to a real
 * agent, launches a session or calls a model.
 */
final class CliFlowRig
{
    public string $log;
    public string $replyFile;
    public string $socket;
    private mixed $harness;

    public function __construct(string $name)
    {
        $this->socket = sys_get_temp_dir() . "/sessioneer-test-{$name}.sock";
        $this->log = sys_get_temp_dir() . "/sessioneer-test-{$name}.log";
        $this->replyFile = sys_get_temp_dir() . "/sessioneer-test-{$name}.reply";
        @unlink($this->log);
        putenv('FAKE_HOST_LOG=' . $this->log);
        putenv('FAKE_HOST_REPLY=' . $this->replyFile);
        $this->reply(['ok' => true]);
        $this->harness = start_harness(['php', __DIR__ . '/fake_flow_host.php'], $this->socket);
    }

    /** @param array<string, mixed> $reply */
    public function reply(array $reply): void
    {
        file_put_contents($this->replyFile, json_encode($reply));
    }

    /** @return list<array<string, mixed>> every request the fake host received, oldest first */
    public function requests(): array
    {
        return is_file($this->log) ? array_map(static fn (string $l): array => json_decode($l, true), array_values(array_filter(explode("\n", (string)file_get_contents($this->log))))) : [];
    }

    public function reset(): void
    {
        @unlink($this->log);
    }

    /**
     * @param list<string> $args
     * @param array<string, string|false> $env extra environment; false removes the variable
     * @return array{int, string, string} exit code, stdout, stderr
     */
    public function run(array $args, array $env = ['SESSIONEER_SELF' => 'cc-self']): array
    {
        if (!array_key_exists('CODEX_THREAD_ID', $env)) {
            $env['CODEX_THREAD_ID'] = false;
        }
        $full = $env + ['SESSIONEER_AGENT_SOCKET' => $this->socket] + getenv();
        foreach ($env as $k => $v) {
            if ($v === false) {
                unset($full[$k]);
            }
        }
        $proc = proc_open(['php', dirname(__DIR__, 2) . '/bin/sessioneer', ...$args], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, null, $full);
        $out = (string)stream_get_contents($pipes[1]);
        $err = (string)stream_get_contents($pipes[2]);

        return [proc_close($proc), $out, $err];
    }

    public function stop(): void
    {
        stop_harness($this->harness, $this->socket);
        @unlink($this->log);
        @unlink($this->replyFile);
    }
}
