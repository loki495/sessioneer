<?php

declare(strict_types=1);

namespace HostAgent\Services;

use HostAgent\Launch\LaunchService;
use HostAgent\Runtimes\RuntimeType;
use HostAgent\Stores\SessionStatusStore;
use HostAgent\Stores\SidecarStore;

/**
 * The `advise` host-agent action: a verdict per tracked headless session
 * (Claude and Codex), and with `act` the follow-through for one of them.
 * Never sends anything to the session it judges unless the verdict is resume.
 */
final class AdviseRunner
{
    private const AGENTS = ['claude', 'codex'];

    /**
     * Request: `target` (session name or conversation id; absent = every tracked headless session),
     * `act` (bool; needs a target), `choice` (resume|fresh: a person's explicit pick that replaces the verdict when acting, the
     * dashboard buttons; the idle and quota guards still apply), `no_model` (skip the middle-band model call), plus `model`/`effort` for the fresh session `act` may start.
     * $deps replaces the collaborators in tests: quota, model_runner, launch, kill.
     *
     * @param array<string,mixed> $request
     * @param array<string,callable> $deps
     * @return array<string,mixed>
     */
    public static function run(array $request, array $deps = []): array
    {
        $target = is_string($request['target'] ?? null) ? trim($request['target']) : '';
        $act = !empty($request['act']);
        if ($act && $target === '') {
            return ['ok' => false, 'message' => '--act needs exactly one session to act on'];
        }

        $quota = isset($deps['quota']) ? $deps['quota']() : QuotaService::get_quota();
        $usage = $quota['agents'] ?? [];
        $usage['claude']['profiles'] = $quota['claude_profiles'] ?? [];

        $rows = [];
        foreach (self::sessions($target) as $session) {
            $rows[] = self::judge($session, $usage, $target !== '' && empty($request['no_model']), $deps);
        }

        if ($target !== '' && $rows === []) {
            return ['ok' => false, 'message' => "No tracked Claude or Codex headless session matches '{$target}'"];
        }
        if (!$act) {
            return ['ok' => true, 'sessions' => $rows];
        }

        return self::act($rows[0], $request, $deps) + ['sessions' => $rows];
    }

    /** @return list<array{name:string,agent:string,agent_session_id:string,workdir:?string,profile:string}> */
    private static function sessions(string $target): array
    {
        $out = [];
        foreach (SidecarStore::list_runtime_sidecars(RuntimeType::HEADLESS) as $row) {
            $agent = $row['agent'] ?? 'claude';
            $id = (string) $row['agent_session_id'];
            if (!in_array($agent, self::AGENTS, true) || $id === '') {
                continue;
            }
            if ($target !== '' && $target !== $row['session_name'] && $target !== $id) {
                continue;
            }
            $out[] = [
                'name' => $row['session_name'],
                'agent' => $agent,
                'agent_session_id' => $id,
                'workdir' => $row['workdir'],
                'profile' => (string) (SidecarStore::read_sidecar($row['session_name'])['profile'] ?? 'personal'),
            ];
        }
        return $out;
    }

    /**
     * @param array{name:string,agent:string,agent_session_id:string,workdir:?string,profile:string} $session
     * @param array<string,mixed> $usage
     * @param array<string,callable> $deps
     * @return array<string,mixed>
     */
    private static function judge(array $session, array $usage, bool $single, array $deps): array
    {
        $status = SessionStatusStore::read_status($session['name']);
        $path = TranscriptRouter::find_transcript_path($session['agent_session_id'], $session['profile']);

        if ($path === null) {
            $facts = null;
        } elseif ($session['agent'] === 'claude') {
            $facts = AdviseService::claude_facts($path);
        } else {
            $facts = AdviseService::codex_facts($status['token_usage'] ?? null, (string) ($status['model'] ?? ''), (int) @filemtime($path));
        }

        $quota = $session['agent'] === 'claude' ? ($usage['claude']['profiles'][$session['profile']]['quota'] ?? null) : ($usage['codex']['quota'] ?? null);
        $advice = AdviseService::advise($facts, $quota, $session['agent_session_id']);

        // A model call costs quota, so only an explicitly named session gets one; a table of many shows 'judgment'.
        if ($single) {
            $candidates = AdviseModelCall::candidates($usage, $session['profile']);
            $advice = AdviseModelCall::resolve($advice, $path, $candidates, $deps['model_runner'] ?? [AdviseModelCall::class, 'run_process_runner']);
        }
        if ($path !== null && isset($advice['prompts'])) {
            $advice['prompts']['fresh'] .= ' Its transcript is ' . $path . ': write the handoff from the tail of it plus the Dibs records first, without waking the old session.';
        }

        return $session + ['status' => $status['status'] ?? 'idle', 'advice' => $advice];
    }

    /**
     * @param array<string,mixed> $row
     * @param array<string,mixed> $request
     * @param array<string,callable> $deps
     * @return array<string,mixed>
     */
    private static function act(array $row, array $request, array $deps): array
    {
        $advice = $row['advice'];
        $choice = $request['choice'] ?? null;
        if ($choice !== null && (!in_array($choice, ['resume', 'fresh'], true) || !isset($advice['prompts']))) {
            return ['ok' => false, 'message' => 'Nothing to act on: ' . ($advice['reason'] ?? 'no readable transcript')];
        }
        $verdict = $choice ?? $advice['verdict'];
        $launch = $deps['launch'] ?? [LaunchService::class, 'launch'];

        if ($row['status'] !== 'idle') {
            return ['ok' => false, 'message' => "Session {$row['name']} is {$row['status']}; --act only handles an idle one"];
        }
        if (!in_array($verdict, ['resume', 'fresh'], true)) {
            return ['ok' => false, 'message' => "No verdict to act on ({$verdict}): " . $advice['reason']];
        }
        if (!empty($advice['quota_blocked'])) {
            return ['ok' => false, 'message' => 'The ' . $row['profile'] . ' quota is at its limit; earliest restart ' . ($advice['restart_after'] !== null ? gmdate('c', (int) $advice['restart_after']) : 'unknown')];
        }

        $base = ['agent' => $row['agent'], 'workdir' => $row['workdir'], 'profile' => $row['profile']];

        if ($verdict === 'resume') {
            $sent = $launch($base + ['agent_session_id' => $row['agent_session_id'], 'prompt' => $advice['prompts']['resume']]);
            return ['ok' => ($sent['ok'] ?? false) === true, 'acted' => 'resume', 'session' => $sent['name'] ?? $row['name'], 'message' => (string) ($sent['message'] ?? '')];
        }

        // A replacement never gets wider permissions than the session it replaces; a mode the launcher cannot reproduce fails the launch, which leaves the old session alone.
        $old = SidecarStore::read_sidecar($row['name']) ?? [];
        $mode = (string) (SessionStatusStore::read_status($row['name'])['mode'] ?? '');
        $restrict = array_filter([
            'no_tools' => !empty($old['no_tools']),
            'allowed_tools' => $old['allowed_tools'] ?? null,
            'disallowed_tools' => $old['disallowed_tools'] ?? null,
            'starting_mode' => $mode !== '' && $mode !== 'default' ? $mode : null,
        ]);
        $new = $launch($base + $restrict + array_filter(['prompt' => $advice['prompts']['fresh'], 'model' => $request['model'] ?? null, 'effort' => $request['effort'] ?? null]));
        // The old session is only stopped once the new one exists AND took its prompt.
        if (($new['ok'] ?? false) !== true) {
            return ['ok' => false, 'acted' => 'fresh', 'session' => $new['name'] ?? null, 'message' => 'Fresh session not started cleanly; the old session was left as it is: ' . (string) ($new['message'] ?? '')];
        }
        $kill = $deps['kill'] ?? [LaunchService::class, 'kill'];
        $old = $kill(['agent' => $row['agent'], 'target' => $row['name']]);

        return [
            'ok' => true,
            'acted' => 'fresh',
            'session' => $new['name'] ?? null,
            'archived' => ($old['ok'] ?? false) === true ? $row['name'] : null,
            'message' => ($old['ok'] ?? false) === true ? 'Fresh session started; the old one was archived' : 'Fresh session started, but the old one could not be archived: ' . (string) ($old['message'] ?? ''),
        ];
    }
}
