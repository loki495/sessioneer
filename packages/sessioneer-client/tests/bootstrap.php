<?php

declare(strict_types=1);

/**
 * Standalone test bootstrap: the package runs without this repo's harness or a
 * Composer install, so the same tests work in a consumer's checkout.
 */

spl_autoload_register(static function (string $class): void {
    $prefix = 'Loki495\\SessioneerClient\\';

    if (!str_starts_with($class, $prefix)) {
        return;
    }

    $path = dirname(__DIR__) . '/src/' . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';

    if (is_file($path)) {
        require $path;
    }
});

$GLOBALS['client_tests'] = 0;
$GLOBALS['client_failures'] = 0;

function test(string $name, callable $body): void
{
    $GLOBALS['client_tests']++;

    try {
        $body();
    } catch (\Throwable $e) {
        $GLOBALS['client_failures']++;
        fwrite(STDOUT, "  FAIL: {$name} threw " . $e::class . ': ' . $e->getMessage() . "\n");

        return;
    }
}

function check(bool $condition, string $message): void
{
    if ($condition) {
        fwrite(STDOUT, "  PASS: {$message}\n");

        return;
    }

    $GLOBALS['client_failures']++;
    fwrite(STDOUT, "  FAIL: {$message}\n");
}

function same(mixed $expected, mixed $actual, string $message): void
{
    check(
        $expected === $actual,
        $expected === $actual
            ? $message
            : "{$message} (expected " . var_export($expected, true) . ', got ' . var_export($actual, true) . ')',
    );
}

function contains(string $needle, string $haystack, string $message): void
{
    check(str_contains($haystack, $needle), "{$message} (looked for \"{$needle}\")");
}

/**
 * A stand-in `sessioneer` executable that runs $body with $argv and logs every
 * invocation (one line of arguments) to $log.
 *
 * @return array{0: string, 1: string} binary path, invocation log path
 */
function fake_binary(string $body, ?string $log = null): array
{
    $directory = sys_get_temp_dir() . '/sessioneer-client-test-' . bin2hex(random_bytes(6));
    mkdir($directory);
    $log ??= $directory . '/calls.log';
    $binary = $directory . '/sessioneer';
    $script = "#!/usr/bin/env php\n<?php\ndeclare(strict_types=1);\n"
        . 'file_put_contents(' . var_export($log, true) . ', implode(" ", array_slice($argv, 1)) . "\n", FILE_APPEND);' . "\n"
        . $body;
    file_put_contents($binary, $script);
    chmod($binary, 0755);

    return [$binary, $log];
}

/** @return list<string> */
function invocations(string $log): array
{
    if (!is_file($log)) {
        return [];
    }

    return array_values(array_filter(explode("\n", (string) file_get_contents($log)), static fn (string $l): bool => $l !== ''));
}

function temp_workdir(): string
{
    $directory = sys_get_temp_dir() . '/sessioneer-client-work-' . bin2hex(random_bytes(6));
    mkdir($directory);

    return $directory;
}

/**
 * The `--wait` half of a fake CLI: names $session on its first stderr line, then
 * writes the `--json` envelope to stdout and exits with $exit.
 *
 * @param  array<string, mixed>  $overrides
 */
function envelope_body(?string $session, array $overrides = [], int $exit = 0): string
{
    $envelope = array_merge([
        'ok' => true,
        'answer' => 'keeper',
        'session' => $session,
        'conversation_id' => null,
        'agent' => 'claude',
        'model' => null,
        'workdir' => null,
        'exit_code' => $exit === 0 ? 0 : 1,
        'error' => null,
    ], $overrides);

    $body = $session === null ? '' : 'fwrite(STDERR, ' . var_export($session . "\n", true) . ');' . "\n";

    return $body
        . 'echo json_encode(' . var_export($envelope, true) . '), "\n";' . "\n"
        . "exit({$exit});\n";
}

/** The `--wait` half of a fake CLI that failed: `ok` false with the given kind and message. */
function failure_body(?string $session, string $kind, string $message, int $exit = 1): string
{
    return envelope_body($session, [
        'ok' => false,
        'answer' => null,
        'exit_code' => $exit,
        'error' => ['kind' => $kind, 'message' => $message],
    ], $exit);
}

/**
 * A fake CLI that stops whatever it is told to kill ($kill is the body run on a
 * kill) and otherwise completes one `--wait --json` turn.
 *
 * @param  array<string, mixed>  $envelope
 * @return array{0: string, 1: string}
 */
function fake_turn(?string $session, array $envelope = [], string $kill = 'exit(0);'): array
{
    return fake_binary("if (in_array('--kill', \$argv, true)) {\n{$kill}\n}\n" . envelope_body($session, $envelope));
}

/**
 * A fake CLI that stops whatever it is told to kill and otherwise fails one
 * `--wait --json` turn with the given kind and message.
 *
 * @return array{0: string, 1: string}
 */
function fake_failure(?string $session, string $kind, string $message, int $exit = 1): array
{
    return fake_binary("if (in_array('--kill', \$argv, true)) {\n    exit(0);\n}\n" . failure_body($session, $kind, $message, $exit));
}
