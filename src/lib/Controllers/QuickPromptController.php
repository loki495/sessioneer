<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Services\QuickPromptService;

final class QuickPromptController extends Controller
{
    public function submit(): void
    {
        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: no-store');
        header('X-Content-Type-Options: nosniff');
        $result = QuickPromptService::guard($_SERVER);
        if ($result === null) {
            try {
                $result = QuickPromptService::rate_limit();
                if ($result === null) {
                    $stream = fopen('php://input', 'rb');
                    $raw = $stream !== false ? stream_get_contents($stream, QuickPromptService::MAX_BODY_BYTES + 1) : false;
                    if (is_resource($stream)) fclose($stream);
                    $result = $raw !== false ? (new QuickPromptService())->submit($raw, $_SERVER) : QuickPromptService::error('invalid_request', 'Unable to read request', 400);
                }
            } catch (\Throwable $e) {
                // No exception text: it may include paths or request contents.
                error_log('Quick Prompt failed: ' . $e::class);
                if (getenv('SESSIONEER_DEBUG') === '1') throw $e;
                $result = QuickPromptService::error('service_unavailable', 'Quick Prompt is temporarily unavailable', 503);
            }
        }
        $http = (int)($result['_http'] ?? 200);
        http_response_code($http);
        if ($http === 401) header('WWW-Authenticate: Bearer');
        if ($http === 405) header('Allow: POST');
        if ($http === 429) header('Retry-After: 60');
        unset($result['_http']);
        echo json_encode($result, JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
    }
}
