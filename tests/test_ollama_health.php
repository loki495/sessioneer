<?php

declare(strict_types=1);

/**
 * The dashboard health box's Ollama section: what it reports when the server
 * is reachable with chat models, when it is down, when nothing chattable is
 * pulled, and when PHP cannot detach a turn.
 *
 * build_checks() is pure (every input passed in) precisely so these branches
 * are testable without a server; checks() is exercised once against the dead
 * port tests/.env.testing pins OLLAMA_URL to, which is the real "server is
 * down" path every install starts from.
 */

require __DIR__ . '/lib/assert.php';
require dirname(__DIR__) . '/vendor/autoload.php';

use HostAgent\Services\OllamaHealthService;

if (!str_contains((string)getenv('OLLAMA_URL'), '127.0.0.1:1')) {
    fwrite(STDERR, "REFUSING TO RUN: OLLAMA_URL is not pinned to the fixture dead port (run via tests/run.sh).\n");
    exit(1);
}

/** @return array<string, array<string, mixed>> checks keyed by their own key */
$by_key = static function (array $checks): array {
    $out = [];
    foreach ($checks as $check) {
        $out[(string)$check['key']] = $check;
    }

    return $out;
};

$models = [
    'ok' => true,
    'models' => [
        ['id' => 'chat-one:1b', 'name' => 'chat-one:1b (1.0B)'],
        ['id' => 'chat-two:3b', 'name' => 'chat-two:3b (3.0B)'],
    ],
];
$url = 'http://127.0.0.1:11434';

// --- everything works ------------------------------------------------------------

$healthy = $by_key(OllamaHealthService::build_checks($url, '0.9.6', $models, true));

assert_equal(['ollama_endpoint', 'ollama_version', 'ollama_models', 'ollama_pcntl'], array_keys($healthy), 'healthy: reports endpoint, version, models and the worker capability');
assert_true(array_reduce($healthy, static fn (bool $ok, array $c): bool => $ok && $c['ok'] === true, true), 'healthy: every check passes');
assert_true(str_contains((string)$healthy['ollama_endpoint']['detail'], $url), 'healthy: names the endpoint it talked to');
assert_true(str_contains((string)$healthy['ollama_models']['detail'], 'chat-one:1b'), 'healthy: lists the models that can chat');
assert_equal('Ollama', $healthy['ollama_endpoint']['section'], 'healthy: every check belongs to the Ollama section');

// --- the server is down ----------------------------------------------------------

$down = $by_key(OllamaHealthService::build_checks($url, null, [
    'ok' => false,
    'message' => 'Ollama is not reachable at ' . $url,
], true));

assert_true($down['ollama_endpoint']['ok'] === false, 'down: the endpoint check fails');
assert_true(str_contains((string)$down['ollama_endpoint']['detail'], 'not reachable'), 'down: says the server is unreachable');
assert_true(str_contains((string)$down['ollama_endpoint']['detail'], 'OLLAMA_URL'), 'down: points at the setting that would fix it');
assert_true($down['ollama_version']['ok'] === false, 'down: no version is claimed');
assert_true(str_contains((string)$down['ollama_models']['detail'], 'unknown'), 'down: the model count is unknown, not zero');
assert_true($down['ollama_pcntl']['ok'] === true, 'down: a dead server says nothing about pcntl');

// --- reachable but nothing chattable ---------------------------------------------

$empty = $by_key(OllamaHealthService::build_checks($url, '0.9.6', ['ok' => true, 'models' => []], true));

assert_true($empty['ollama_endpoint']['ok'] === true, 'empty: the server itself is fine');
assert_true($empty['ollama_models']['ok'] === false, 'empty: no chat model is a warning');
assert_true(str_contains((string)$empty['ollama_models']['detail'], 'ollama pull'), 'empty: says how to get one');

// --- PHP cannot detach a turn ----------------------------------------------------

$noPcntl = $by_key(OllamaHealthService::build_checks($url, '0.9.6', $models, false));

assert_true($noPcntl['ollama_pcntl']['ok'] === false, 'no pcntl: the capability check fails');
assert_true(str_contains((string)$noPcntl['ollama_pcntl']['detail'], 'pcntl_fork'), 'no pcntl: names what is missing');
assert_true($noPcntl['ollama_endpoint']['ok'] === true, 'no pcntl: the server is still reported as reachable');

// --- the real path, against the pinned dead port ---------------------------------

$live = $by_key(OllamaHealthService::checks());

assert_equal('Ollama', $live['ollama_endpoint']['section'], 'checks(): reads the real configuration');
assert_true($live['ollama_endpoint']['ok'] === false, 'checks(): an unreachable pinned server is reported, never assumed healthy');
assert_true(str_contains((string)$live['ollama_endpoint']['detail'], '127.0.0.1:1'), 'checks(): names the pinned fixture endpoint');

test_exit();
