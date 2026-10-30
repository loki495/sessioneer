<?php

declare(strict_types=1);

namespace Loki495\SessioneerClient;

/**
 * One-shot execution of a Sessioneer CLI agent turn.
 *
 * Contract this client relies on (`sessioneer --wait --json`):
 *  - stdout is one JSON envelope: `ok`, `answer`, `session`, `conversation_id`,
 *    `agent`, `model`, `workdir`, `exit_code` and `error` (`kind` + `message`),
 *    written on success and on failure alike;
 *  - stderr carries the human message, its first line being the name of the
 *    session the call created;
 *  - exit 0 means the turn finished, exit 1 means it was refused, errored or
 *    timed out;
 *  - a timeout leaves the session running, so the caller must stop it.
 *
 * The envelope is the only thing the answer is read from: nothing guesses at
 * prose. It also names the session to stop, except when the CLI was killed
 * before it could write one (this client's own outer deadline) — then the first
 * stderr line stands in for it.
 *
 * The session is always cleaned up on the way out — success or failure — and
 * only the session this call's own envelope or stderr named is touched.
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

        $envelope = self::parseEnvelope($outcome->stdout);
        $session = self::envelopeSession($envelope) ?? self::parseSessionName($outcome->stderr);
        $cleanup = $session === null ? null : $this->cleanup($binary, $request->agent, $session, $request->workdir);

        if ($outcome->timedOut) {
            throw new ExecutionFailed(
                FailureReason::Timeout,
                "timed out after {$request->processTimeoutSeconds()}s",
                $session,
                $cleanup,
            );
        }

        if ($envelope === null) {
            throw new ExecutionFailed(
                $outcome->exitCode === 0 ? FailureReason::ProcessFailure : self::reason($outcome->stderr),
                $outcome->exitCode === 0
                    ? 'sessioneer exited 0 without a --json envelope'
                    : self::errorMessage($outcome->stderr, $outcome->exitCode),
                $session,
                $cleanup,
            );
        }

        if (($envelope['ok'] ?? false) !== true) {
            throw self::envelopeFailure($envelope, $session, $cleanup);
        }

        if ($outcome->exitCode !== 0) {
            throw new ExecutionFailed(
                FailureReason::ProcessFailure,
                "sessioneer reported success but exited {$outcome->exitCode}",
                $session,
                $cleanup,
            );
        }

        $answer = $envelope['answer'] ?? null;

        if (!is_string($answer)) {
            throw new ExecutionFailed(
                FailureReason::ProcessFailure,
                'the --json envelope carried no answer',
                $session,
                $cleanup,
            );
        }

        $conversationId = $envelope['conversation_id'] ?? null;

        return new ExecutionResult(
            $answer,
            $session,
            $outcome->exitCode,
            $cleanup,
            is_string($conversationId) && $conversationId !== '' ? $conversationId : null,
        );
    }

    /**
     * The `--json` envelope, or null when stdout is not one: an older CLI, or a
     * CLI killed before it could write it.
     *
     * @return array<string, mixed>|null
     */
    public static function parseEnvelope(string $stdout): ?array
    {
        if (trim($stdout) === '') {
            return null;
        }

        $decoded = json_decode($stdout, true);

        return is_array($decoded) && array_key_exists('ok', $decoded) ? $decoded : null;
    }

    /** The session the CLI named on its own stderr, or null when it never got that far. */
    public static function parseSessionName(string $stderr): ?string
    {
        $first = preg_split('/\R/', trim($stderr))[0] ?? '';

        return preg_match(self::SESSION, $first) === 1 ? $first : null;
    }

    /**
     * The session the envelope names, if it is a well-formed one.
     *
     * @param  array<string, mixed>|null  $envelope
     */
    private static function envelopeSession(?array $envelope): ?string
    {
        $session = $envelope['session'] ?? null;

        return is_string($session) && preg_match(self::SESSION, $session) === 1 ? $session : null;
    }

    /** @param  array<string, mixed>  $envelope */
    private static function envelopeFailure(array $envelope, ?string $session, ?CleanupReport $cleanup): ExecutionFailed
    {
        $error = is_array($envelope['error'] ?? null) ? $envelope['error'] : [];
        $kind = is_string($error['kind'] ?? null) ? $error['kind'] : '';
        $message = is_string($error['message'] ?? null) ? trim($error['message']) : '';

        return new ExecutionFailed(
            FailureReason::fromCli($kind),
            $message === '' ? 'the turn failed without a message' : $message,
            $session,
            $cleanup,
        );
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
