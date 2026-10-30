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
