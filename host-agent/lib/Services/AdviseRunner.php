<?php

declare(strict_types=1);

namespace HostAgent\Services;

use HostAgent\Launch\LaunchService;
use HostAgent\Runtimes\RuntimeType;
use HostAgent\SessionFlow\FlowFacts;
use HostAgent\SessionFlow\PromptBuilder;
use HostAgent\SessionFlow\ReceiverPicker;
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
     * dashboard buttons; the idle and quota guards still apply), `no_model` (skip the model call), plus `model`/`effort` for the fresh session `act` may start.
     * $deps replaces the collaborators in tests: quota, model_runner, launch.
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
            $facts = FlowFacts::claude($path);
        } else {
            $facts = FlowFacts::codex($status['token_usage'] ?? null, (string) ($status['model'] ?? ''), (int) @filemtime($path));
        }

        $quota = $session['agent'] === 'claude' ? ($usage['claude']['profiles'][$session['profile']]['quota'] ?? null) : ($usage['codex']['quota'] ?? null);
        $advice = AdviseService::advise($facts, $quota, $session['agent_session_id']);
        $advice['receiver'] = ReceiverPicker::pick($session, $usage, !empty($advice['quota_blocked']), $status);
        if (!empty($advice['quota_blocked']) && $advice['receiver'] === null) {
            $manual = ReceiverPicker::manualReceivers($usage);
            $advice['verdict'] = 'manual';
            $advice['manual_agents'] = $manual;
            $advice['reason'] .= $manual === []
                ? ' No other configured account has known room; use the takeover prompt manually when one is available.'
                : ' ' . implode(', ', $manual) . ' has quota but Sessioneer cannot start it headless; run the takeover prompt in a session there.';
        }

        // A model call costs quota, so only an explicitly named session gets one; a table of many shows 'judgment'.
        if ($single) {
            $runner = $deps['model_runner'] ?? [AdviseModelCall::class, 'run_process_runner'];
            $judgmentModel = $advice['receiver'] !== null ? self::judgment_model((string) $advice['receiver']['agent']) : null;
            if (!empty($advice['quota_blocked']) && $advice['receiver'] !== null && $judgmentModel !== null && !empty($advice['quota_short_wait'])) {
                $candidate = $advice['receiver'] + ['model' => $judgmentModel];
                $advice = AdviseModelCall::resolve_quota($advice, $path, $candidate, $runner);
            } elseif (empty($advice['quota_blocked'])) {
                $candidates = AdviseModelCall::candidates($usage, $session['profile']);
                $advice = AdviseModelCall::resolve($advice, $path, $candidates, $runner);
            }
        }
        if ($path !== null && isset($advice['prompts'])) {
            try {
                $advice['prompts']['fresh'] = PromptBuilder::takeover(
                    $session['agent_session_id'],
                    $session['agent'] === 'claude' ? $path : null,
                    $session['agent'] === 'claude' && $session['profile'] !== 'personal' ? $session['profile'] : null,
                    $session['agent'],
                );
            } catch (\InvalidArgumentException) {
                unset($advice['prompts']);
            }
        }

        return $session + ['status' => $status['status'] ?? 'idle', 'advice' => $advice];
    }

    /** The small model the short-reset judgment runs on: the receiver's own cheapest account. */
    private static function judgment_model(string $agent): ?string
    {
        return ['claude' => AdviseModelCall::CLAUDE_MODEL, 'codex' => AdviseModelCall::CODEX_MODEL][$agent] ?? null;
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
        if (!empty($advice['quota_blocked']) && $verdict === 'resume') {
            return ['ok' => false, 'message' => 'The ' . $row['profile'] . ' quota is at its limit; earliest restart ' . ($advice['restart_after'] !== null ? gmdate('c', (int) $advice['restart_after']) : 'unknown')];
        }

        $receiver = $advice['receiver'] ?? null;
        if ($verdict === 'fresh' && $receiver === null) {
            return ['ok' => false, 'message' => 'No other configured account has known room for a takeover; use the manual /takeover prompt when one is available'];
        }

        $base = ['agent' => $row['agent'], 'workdir' => $row['workdir']];
        if ($row['agent'] === 'claude') {
            $base['profile'] = $row['profile'];
        }

        if ($verdict === 'resume') {
            $sent = $launch($base + ['agent_session_id' => $row['agent_session_id'], 'prompt' => $advice['prompts']['resume']]);
            return ['ok' => ($sent['ok'] ?? false) === true, 'acted' => 'resume', 'session' => $sent['name'] ?? $row['name'], 'message' => (string) ($sent['message'] ?? '')];
        }

        if ($receiver['agent'] !== $row['agent'] && (isset($request['model']) || isset($request['effort']))) {
            return ['ok' => false, 'message' => 'A cross-agent takeover cannot reuse the source model or effort; omit --model and --effort'];
        }
        $base['agent'] = $receiver['agent'];
        if ($receiver['agent'] === 'claude') {
            $base['profile'] = $receiver['profile'];
        } else {
            unset($base['profile']);
        }

        // A replacement never gets wider permissions than the session it replaces; a mode the launcher cannot reproduce fails the launch, which leaves the old session alone.
        $old = SidecarStore::read_sidecar($row['name']) ?? [];
        $mode = (string) (SessionStatusStore::read_status($row['name'])['mode'] ?? '');
        $restrict = array_filter([
            'no_tools' => !empty($old['no_tools']),
            'allowed_tools' => $receiver['agent'] === $row['agent'] ? ($old['allowed_tools'] ?? null) : null,
            'disallowed_tools' => $receiver['agent'] === $row['agent'] ? ($old['disallowed_tools'] ?? null) : null,
            'starting_mode' => ReceiverPicker::startingMode($receiver['agent'], $mode),
        ]);
        if (!empty($restrict['no_tools'])) {
            return ['ok' => false, 'message' => 'A tool-less replacement cannot read the source conversation or transfer its Dibs claims'];
        }
        $new = $launch($base + $restrict + array_filter(['prompt' => $advice['prompts']['fresh'], 'model' => $request['model'] ?? null, 'effort' => $request['effort'] ?? null]));
        if (($new['ok'] ?? false) !== true) {
            return ['ok' => false, 'acted' => 'fresh', 'session' => $new['name'] ?? null, 'message' => 'Fresh session not started cleanly; the old session was left as it is: ' . (string) ($new['message'] ?? '')];
        }

        return [
            'ok' => true,
            'acted' => 'fresh',
            'session' => $new['name'] ?? null,
            'message' => 'Fresh takeover session started; the old session remains available until the receiver verifies and archives it',
        ];
    }
}
