<?php
declare(strict_types=1);

/**
 * Live diagnostic: snapshots EVERY candidate "is this opencode session blocked"
 * source at a single instant, so we can determine which one matches a REAL
 * permission/question block (the pane and
 * the serve HTTP API disagree, and each has its own documented failure mode:
 * the pane can show an ORPHANED/stale dialog, the serve's /permission and
 * /question can return [] for a block the TUI is genuinely waiting on).
 *
 * Usage: php host-agent/opencode_diagnose.php <tmux-session-name>
 *   e.g. php host-agent/opencode_diagnose.php oc-20260825-195736
 *
 * Prints a JSON document with:
 *   - sidecar       the tracked ses_* id + workdir + agent
 *   - pane_is_blocked / pane_tool / pane_options   (OpenCodePromptParser)
 *   - permission_api   GET /permission  (the serve's authoritative list)
 *   - question_api     GET /question     (the serve's authoritative list)
 *   - session_api       GET /session/:id (title/cost/status via serve)
 */

require dirname(__DIR__) . '/vendor/autoload.php';
require __DIR__ . '/lib/Sessions.php';

use HostAgent\Services\Config;
use HostAgent\Services\OpenCodePromptParser;
use HostAgent\Services\ProcessRunner;
use HostAgent\Services\TmuxService;
use HostAgent\Stores\SidecarStore;

$sessionName = $argv[1] ?? '';
if ($sessionName === '') {
    fwrite(STDERR, "usage: php host-agent/opencode_diagnose.php <tmux-session-name>\n");
    exit(1);
}

function http_get(string $url): mixed
{
    $result = ProcessRunner::run_process(['curl', '--silent', '--max-time', '3', $url]);
    if ($result['exit'] !== 0) {
        return null;
    }
    $decoded = json_decode($result['stdout'], true);
    return $decoded;
}

$sidecar = SidecarStore::read_sidecar($sessionName) ?? [];
$sessionId = is_string($sidecar['agent_session_id'] ?? null) ? $sidecar['agent_session_id'] : null;

$paneContent = TmuxService::tmux_capture_pane($sessionName);
$paneParsed = OpenCodePromptParser::parse_blocking_prompt($paneContent);

$server = Config::opencode_server_url();
$permissionApi = http_get($server . '/permission');
$questionApi = http_get($server . '/question');
$sessionApi = $sessionId !== null ? http_get($server . '/session/' . rawurlencode($sessionId)) : null;

// Filter the API lists to this session, and report the full lists too.
$permissionForSession = [];
if (is_array($permissionApi)) {
    foreach ($permissionApi as $p) {
        if (is_array($p) && ($p['sessionID'] ?? null) === $sessionId) {
            $permissionForSession[] = $p;
        }
    }
}

$questionForSession = [];
if (is_array($questionApi)) {
    foreach ($questionApi as $q) {
        if (is_array($q) && ($q['sessionID'] ?? null) === $sessionId) {
            $questionForSession[] = $q;
        }
    }
}

echo json_encode([
    'session_name' => $sessionName,
    'sidecar' => $sidecar,
    'session_id' => $sessionId,
    'pane' => [
        'is_blocked' => OpenCodePromptParser::is_blocked($paneContent),
        'parsed_tool' => is_array($paneParsed) ? ($paneParsed['tool_name'] ?? null) : null,
        'parsed_question' => is_array($paneParsed) ? ($paneParsed['question'] ?? null) : null,
        'parsed_options' => is_array($paneParsed) ? ($paneParsed['options'] ?? []) : [],
    ],
    'permission_api' => [
        'full_count' => is_array($permissionApi) ? count($permissionApi) : 'unreachable',
        'for_session' => $permissionForSession,
        'raw' => $permissionApi,
    ],
    'question_api' => [
        'full_count' => is_array($questionApi) ? count($questionApi) : 'unreachable',
        'for_session' => $questionForSession,
        'raw' => $questionApi,
    ],
    'session_api' => $sessionApi,
    'captured_at' => date('c'),
], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n";
