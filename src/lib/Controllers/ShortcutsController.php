<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Services\ShortcutsService;

/** Bearer-token API for Apple Shortcuts; no browser cookie or CSRF path. See docs/ios-shortcuts.md. */
final class ShortcutsController extends Controller
{
    public function sessions(): void
    {
        $this->run('GET', static fn(ShortcutsService $s): array => $s->sessions());
    }

    public function session(): void
    {
        $this->run('GET', static fn(ShortcutsService $s): array => $s->session((string)($_GET['id'] ?? '')));
    }

    public function message(): void
    {
        $this->run('POST', static fn(ShortcutsService $s): array => $s->message(self::body()));
    }

    public function answer(): void
    {
        $this->run('POST', static fn(ShortcutsService $s): array => $s->answer(self::body()));
    }

    /** @param callable(ShortcutsService):array<string, mixed> $action */
    private function run(string $method, callable $action): void
    {
        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: no-store');
        header('X-Content-Type-Options: nosniff');
        $result = ShortcutsService::guard($_SERVER, $method);
        if ($result === null) {
            try {
                $result = ShortcutsService::rate_limit() ?? $action(new ShortcutsService());
            } catch (\Throwable $e) {
                // No exception text: it may include paths or session content.
                error_log('Shortcuts API failed: ' . $e::class);
                $result = ShortcutsService::error('service_unavailable', 'The Shortcuts API is temporarily unavailable', 503);
            }
        }
        $http = (int)($result['_http'] ?? 200);
        http_response_code($http);
        if ($http === 401) header('WWW-Authenticate: Bearer');
        if ($http === 405) header('Allow: ' . $method);
        if ($http === 429) header('Retry-After: 60');
        unset($result['_http']);
        echo json_encode($result, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
    }

    private static function body(): string
    {
        $stream = fopen('php://input', 'rb');
        $raw = $stream !== false ? stream_get_contents($stream, ShortcutsService::MAX_BODY_BYTES + 1) : false;
        if (is_resource($stream)) fclose($stream);
        return $raw === false ? '' : $raw;
    }
}
