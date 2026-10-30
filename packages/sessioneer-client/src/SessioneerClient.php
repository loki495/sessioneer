<?php

declare(strict_types=1);

namespace Loki495\SessioneerClient;

/**
 * One-shot execution of a Sessioneer CLI agent turn.
 *
 * Contract this client relies on (today's `sessioneer --wait`):
 *  - stdout is the final assistant text and nothing else;
 *  - stderr's first line is the name of the session the call created;
 *  - exit 0 means the turn finished, exit 1 means it was refused, errored or
 *    timed out, with the reason on stderr;
 *  - a timeout leaves the session running, so the caller must stop it.
 *
 * The session is always cleaned up on the way out — success or failure — and
 * only the session whose name appeared on this call's own stderr is touched.
 * Cleanup never happens on a reused or guessed name.
 */
final readonly class SessioneerClient
{
    /** A session name: one argv-safe word, so an error line is never mistaken for one. */
    private const string SESSION = '/^[A-Za-z0-9][A-Za-z0-9._-]{0,199}$/';

    public function __construct(
        private string $binary = 'sessioneer',
        private int $cleanupTimeoutSeconds = 30,
    ) {
        if ($cleanupTimeoutSeconds < 1) {
            throw new \InvalidArgumentException('cleanupTimeoutSeconds must be at least 1');
        }
    }

    /**
     * Run one turn to completion. The prompt is written to the CLI on stdin.
     *
     * @throws ExecutionFailed when the turn produced no answer; its session is already cleaned up.
     */
    public function execute(ExecutionRequest $request, string $prompt): ExecutionResult
    {
        if (trim($prompt) === '') {
            throw new \InvalidArgumentException('the prompt is empty');
        }

        $binary = $this->resolveBinary();
        $runner = new ProcessRunner;
        $outcome = $runner->run(
            $request->command($binary),
            $prompt,
            $request->workdir,
            $request->processTimeoutSeconds(),
        );

        $session = self::parseSessionName($outcome->stderr);
        $cleanup = $session === null ? null : $this->cleanup($binary, $request->agent, $session, $request->workdir);

        if ($outcome->timedOut) {
            throw new ExecutionFailed(
                FailureReason::Timeout,
                "timed out after {$request->processTimeoutSeconds()}s",
                $session,
                $cleanup,
            );
        }

        if ($outcome->exitCode !== 0) {
            throw new ExecutionFailed(
                self::reason($outcome->stderr),
                self::errorMessage($outcome->stderr, $outcome->exitCode),
                $session,
                $cleanup,
            );
        }

        return new ExecutionResult(rtrim($outcome->stdout, "\n"), $session, $outcome->exitCode, $cleanup);
    }

    /** The session the CLI named on its own stderr, or null when it never got that far. */
    public static function parseSessionName(string $stderr): ?string
    {
        $first = preg_split('/\R/', trim($stderr))[0] ?? '';

        return preg_match(self::SESSION, $first) === 1 ? $first : null;
    }

    private function resolveBinary(): string
    {
        if (str_contains($this->binary, '/')) {
            if (is_executable($this->binary)) {
                return $this->binary;
            }

            throw new ExecutionFailed(
                FailureReason::Unavailable,
                "the sessioneer binary [{$this->binary}] is not executable",
            );
        }

        foreach (explode(PATH_SEPARATOR, (string) getenv('PATH')) as $directory) {
            if ($directory === '') {
                continue;
            }

            $candidate = rtrim($directory, '/') . '/' . $this->binary;

            if (is_executable($candidate)) {
                return $candidate;
            }
        }

        throw new ExecutionFailed(
            FailureReason::Unavailable,
            "the sessioneer binary [{$this->binary}] is not on PATH",
        );
    }

    private function cleanup(string $binary, Agent $agent, string $session, string $workdir): CleanupReport
    {
        $failures = [];
        $refused = $this->kill($binary, $agent, $session, $workdir, false);

        if ($refused === null) {
            return new CleanupReport($session, true, false, []);
        }

        $failures[] = $refused;
        $forcedRefusal = $this->kill($binary, $agent, $session, $workdir, true);

        if ($forcedRefusal === null) {
            return new CleanupReport($session, true, true, $failures);
        }

        $failures[] = $forcedRefusal;

        return new CleanupReport($session, false, true, $failures);
    }

    /**
     * The kill names the agent whose launcher owns the session: without it the
     * CLI defaults to Claude and refuses a Codex, Ollama or OpenCode session.
     *
     * @return string|null null when the session is gone, the failure message otherwise.
     */
    private function kill(string $binary, Agent $agent, string $session, string $workdir, bool $force): ?string
    {
        $command = [$binary, '--agent', $agent->value, '--kill', $session];

        if ($force) {
            $command[] = '--force';
        }

        $what = 'kill ' . $session . ($force ? ' --force' : '') . " (agent {$agent->value})";

        try {
            $outcome = (new ProcessRunner)->run($command, null, $workdir, $this->cleanupTimeoutSeconds);
        } catch (\Throwable $e) {
            return "{$what} could not run: {$e->getMessage()}";
        }

        if ($outcome->timedOut) {
            return "{$what} timed out after {$this->cleanupTimeoutSeconds}s";
        }

        if ($outcome->exitCode !== 0) {
            return "{$what} exited {$outcome->exitCode}: " . self::lastLine($outcome->stderr);
        }

        return null;
    }

    private static function reason(string $stderr): FailureReason
    {
        if (str_contains($stderr, 'timed out after')) {
            return FailureReason::Timeout;
        }

        if (str_contains($stderr, ' errored: ')) {
            return FailureReason::TurnError;
        }

        return FailureReason::LaunchRefused;
    }

    private static function errorMessage(string $stderr, int $exitCode): string
    {
        $line = self::lastLine($stderr);

        return $line === '' ? "sessioneer exited {$exitCode} without a message" : $line;
    }

    private static function lastLine(string $text): string
    {
        $lines = array_values(array_filter(
            preg_split('/\R/', trim($text)) ?: [],
            static fn (string $line): bool => trim($line) !== '',
        ));

        return $lines === [] ? '' : trim($lines[count($lines) - 1]);
    }
}
