<?php

declare(strict_types=1);

namespace HostAgent\Runtimes;

/**
 * One live `claude -p --input-format stream-json --output-format stream-json`
 * process owned by ClaudeHeadlessManager: its pipes, its partial-line output
 * buffer, and the small amount of per-session live state the manager tracks
 * (turn state, pending prompt, stop bookkeeping).
 *
 * Deliberately dumb: this class moves bytes and remembers state; deciding
 * what an event MEANS (status writes, guards, rotation) is the manager's job.
 */
final class ClaudeHeadlessChild
{
    /** Longest a single write to the child's stdin may block before we call it dead. */
    private const WRITE_TIMEOUT_SECONDS = 2.0;

    private const STDERR_TAIL_LINES = 20;

    private const STDERR_LINE_MAX_CHARS = 500;

    /** @var resource */
    private $process;

    /** @var resource|null */
    private $stdin;

    /** @var resource */
    private $stdout;

    /** @var resource */
    private $stderr;

    private string $outBuffer = '';

    private string $errBuffer = '';

    /** @var string[] */
    private array $stderrTail = [];

    private ?int $exitCode = null;

    /** starting | idle | working | blocked */
    public string $state = 'starting';

    /** @var array<string, mixed>|null the raw can_use_tool request awaiting an answer */
    public ?array $pending = null;

    public int $lastActivity;

    public bool $stopping = false;

    public ?float $stopDeadline = null;

    public bool $termSent = false;

    public bool $killSent = false;

    /** @var array<int, resource> client sockets waiting for this child to exit */
    public array $stopWaiters = [];

    /** @var array<string, array{client: resource, deadline: float}> control_request id => client awaiting the control_response */
    public array $controlWaiters = [];

    /** The Claude account (agents.php profile name) this child runs under; null is the default account. */
    public ?string $profile = null;

    /** Permission mode (Claude's own vocabulary) the manager asked for at spawn, to verify against init. */
    public ?string $requestedMode = null;

    public bool $modeChecked = false;

    /** Set when init contradicts the requested permission mode; shown until the mode changes. */
    public ?string $modeWarning = null;

    /** A guardrail condemned this child: ignore everything it still says. */
    public bool $guarded = false;

    /**
     * User messages written that have not produced a `result` yet. Claude
     * queues a message sent mid-turn and runs it after the current one, so
     * "idle" is only true when this is back to zero.
     */
    public int $openTurns = 0;

    /** Set when the manager sends a diagnostic interrupt to a silent `working` child; cleared once it shows any sign of life. */
    public ?float $stallInterruptSentAt = null;

    /** Set by the stall watchdog before it ends a wedged child, so finalize_child() reports WHY instead of a generic crash message. */
    public ?string $terminationReason = null;

    /**
     * @param resource $process
     * @param resource $stdin
     * @param resource $stdout
     * @param resource $stderr
     */
    private function __construct(
        public readonly string $name,
        public ?string $agentSessionId,
        public readonly int $pid,
        $process,
        $stdin,
        $stdout,
        $stderr,
    ) {
        $this->process = $process;
        $this->stdin = $stdin;
        $this->stdout = $stdout;
        $this->stderr = $stderr;
        $this->lastActivity = time();
    }

    /**
     * @param string[] $argv the command as an ARRAY - never a shell string
     * @param array<string, string> $env full child environment
     */
    public static function spawn(string $name, ?string $agentSessionId, array $argv, array $env, string $cwd): ?self
    {
        $process = @proc_open($argv, [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, $cwd, $env);

        if (!is_resource($process)) {
            return null;
        }

        $status = proc_get_status($process);
        [$stdin, $stdout, $stderr] = $pipes;
        stream_set_blocking($stdin, false);
        stream_set_blocking($stdout, false);
        stream_set_blocking($stderr, false);

        return new self($name, $agentSessionId, (int)$status['pid'], $process, $stdin, $stdout, $stderr);
    }

    /** @return resource */
    public function stdout_stream()
    {
        return $this->stdout;
    }

    /** @return resource */
    public function stderr_stream()
    {
        return $this->stderr;
    }

    /**
     * Writes one JSON message plus newline to the child. False when the pipe
     * is closed, broken, or the child stops draining it for too long.
     *
     * @param array<string, mixed> $message
     */
    public function write(array $message): bool
    {
        if ($this->stdin === null) {
            return false;
        }

        $json = json_encode($message, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        if ($json === false) {
            return false;
        }

        $payload = $json . "\n";
        $offset = 0;
        $deadline = microtime(true) + self::WRITE_TIMEOUT_SECONDS;

        while ($offset < strlen($payload)) {
            $written = @fwrite($this->stdin, substr($payload, $offset));

            if ($written === false) {
                return false;
            }

            if ($written === 0) {
                if (microtime(true) > $deadline) {
                    return false;
                }
                $read = null;
                $write = [$this->stdin];
                $except = null;
                @stream_select($read, $write, $except, 0, 100000);
                continue;
            }

            $offset += $written;
        }

        return true;
    }

    /**
     * Reads whatever stdout has produced and returns every COMPLETE line,
     * decoded. A partial trailing line stays buffered for the next call
     * (a large event can arrive across several reads).
     *
     * @return array{events: array<int, array<string, mixed>>, bad: int, eof: bool}
     */
    public function read_events(): array
    {
        if (!is_resource($this->stdout)) {
            return ['events' => [], 'bad' => 0, 'eof' => true];
        }

        $chunk = @fread($this->stdout, 65536);

        if ($chunk !== false && $chunk !== '') {
            $this->outBuffer .= $chunk;
        }

        $events = [];
        $bad = 0;

        while (($newline = strpos($this->outBuffer, "\n")) !== false) {
            $line = trim(substr($this->outBuffer, 0, $newline));
            $this->outBuffer = substr($this->outBuffer, $newline + 1);

            if ($line === '') {
                continue;
            }

            $decoded = json_decode($line, true);

            if (is_array($decoded)) {
                /** @var array<string, mixed> $decoded */
                $events[] = $decoded;
            } else {
                $bad++;
            }
        }

        return ['events' => $events, 'bad' => $bad, 'eof' => feof($this->stdout)];
    }

    /** Drains stderr into a short rolling tail used for crash diagnostics. */
    public function drain_stderr(): void
    {
        if (!is_resource($this->stderr)) {
            return;
        }

        $chunk = @fread($this->stderr, 65536);

        if ($chunk === false || $chunk === '') {
            return;
        }

        $this->errBuffer .= $chunk;

        while (($newline = strpos($this->errBuffer, "\n")) !== false) {
            $line = rtrim(substr($this->errBuffer, 0, $newline));
            $this->errBuffer = substr($this->errBuffer, $newline + 1);

            if ($line !== '') {
                $this->stderrTail[] = substr($line, 0, self::STDERR_LINE_MAX_CHARS);
            }
        }

        $this->stderrTail = array_slice($this->stderrTail, -self::STDERR_TAIL_LINES);
    }

    public function stderr_tail(int $lines = 5): string
    {
        return implode(' | ', array_slice($this->stderrTail, -$lines));
    }

    public function is_running(): bool
    {
        $status = proc_get_status($this->process);

        if (!$status['running'] && $this->exitCode === null) {
            // Only the FIRST proc_get_status() after exit reports the real code.
            $this->exitCode = (int)$status['exitcode'];
        }

        return (bool)$status['running'];
    }

    /** Waits up to $seconds for the process to be reaped; true once it has exited. */
    public function wait_for_exit(float $seconds): bool
    {
        $deadline = microtime(true) + $seconds;

        while ($this->is_running()) {
            if (microtime(true) >= $deadline) {
                return false;
            }

            usleep(10000);
        }

        return true;
    }

    public function exit_code(): ?int
    {
        return $this->exitCode;
    }

    public function signal(int $signal): void
    {
        @proc_terminate($this->process, $signal);
    }

    /** Ends the child's input: Claude cancels an open prompt and exits. */
    public function close_stdin(): void
    {
        if ($this->stdin !== null) {
            @fclose($this->stdin);
            $this->stdin = null;
        }
    }

    /** Releases every handle. Call once, after the child has exited (or been killed). */
    public function dispose(): void
    {
        $this->close_stdin();
        @fclose($this->stdout);
        @fclose($this->stderr);
        @proc_close($this->process);
    }
}
