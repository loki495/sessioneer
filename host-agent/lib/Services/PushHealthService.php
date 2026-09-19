<?php

declare(strict_types=1);

namespace HostAgent\Services;

use HostAgent\Runtimes\CodexBridgeClient;
use HostAgent\Stores\GlobalStateStore;

/**
 * "Is everything this app needs actually installed/configured" - backs
 * the dashboard's health box, instead of leaving Andres to discover each
 * missing piece separately (a stale/never-set VAPID key, tmux's socket
 * dir wiped by a reboot, whether the sessioneer-push-check timer is actually
 * ticking, etc).
 */
class PushHealthService
{
    /**
     * @return array{ok:bool, detail:?string}
     */
    public static function codex_bridge_reachable(?CodexBridgeClient $client = null): array
    {
        $client ??= new CodexBridgeClient();
        $reply = $client->request('account/rateLimits/read', []);

        if (($reply['ok'] ?? false) !== true) {
            return [
                'ok' => false,
                'detail' => $reply['message'] ?? 'Codex bridge unavailable',
            ];
        }

        return [
            'ok' => true,
            'detail' => 'Codex bridge reachable',
        ];
    }

    /**
     * Shared by push_delivery_check() and push_quota_delivery_check() below
     * - both read a GlobalStateStore key PushDeliveryService::
     * record_push_check_result() writes every tick (a different key per
     * pass - see PushDeliveryService::push_quota_check_status_key()'s own
     * doc comment for why they can't share one), and both report the same
     * three things: never run, stale (timer stopped ticking), or ran with
     * some number of failed sends.
     *
     * @return array{key:string, label:string, ok:bool, detail:?string}
     */
    private static function push_status_file_check(string $key, string $label, string $statusKey): array
    {
        if (!PushDeliveryService::push_configured()) {
            return ['key' => $key, 'label' => $label, 'ok' => true, 'detail' => 'VAPID not configured yet - nothing to check'];
        }

        $status = GlobalStateStore::read($statusKey);

        if (!is_array($status) || !is_int($status['checked_at'] ?? null)) {
            return ['key' => $key, 'label' => $label, 'ok' => false, 'detail' => 'sessioneer-push-check timer has never run - is it installed and enabled?'];
        }

        $ageSeconds = time() - $status['checked_at'];
        $failed = (int)($status['failed'] ?? 0);

        if ($failed > 0) {
            $message = is_string($status['last_failure_message'] ?? null) ? $status['last_failure_message'] : 'unknown reason';

            return ['key' => $key, 'label' => $label, 'ok' => false, 'detail' => "Last check {$ageSeconds}s ago: {$failed} send(s) failed - {$message}"];
        }

        // A stale timestamp means the timer itself has stopped ticking, not
        // that sends are failing - worth its own message rather than reading
        // as a false "all good". 120s is generous slack over the default 10s
        // interval regardless of whatever interval is actually configured.
        if ($ageSeconds > 120) {
            return ['key' => $key, 'label' => $label, 'ok' => false, 'detail' => "Last check was {$ageSeconds}s ago - sessioneer-push-check timer may not be running"];
        }

        return ['key' => $key, 'label' => $label, 'ok' => true, 'detail' => "Last check {$ageSeconds}s ago, no failures"];
    }

    /**
     * health_check() entry for the sessioneer-push-check timer's session-transition
     * pass - not just "are VAPID keys configured" (that's its own separate
     * check, a prerequisite rather than this), but whether the timer is
     * actually running AND whether its most recent tick's sends succeeded.
     *
     * @return array{key:string, label:string, ok:bool, detail:?string}
     */
    public static function push_delivery_check(): array
    {
        return self::push_status_file_check('push_delivery', 'Push delivery', PushDeliveryService::push_check_status_key());
    }

    /**
     * Same idea as push_delivery_check(), for the timer's OTHER pass -
     * PushDeliveryService::check_and_send_quota_pushes(), its own separate
     * status key so one pass's heartbeat can't mask the other's.
     *
     * @return array{key:string, label:string, ok:bool, detail:?string}
     */
    public static function push_quota_delivery_check(): array
    {
        return self::push_status_file_check('push_quota_delivery', 'Quota push delivery', PushDeliveryService::push_quota_check_status_key());
    }

    /**
     * opencode-serve.service - the OpenCode headless server this app's
     * OpenCode integration talks to (see .ai/PLAN.md). Checks two things:
     * the systemd unit is enabled AND currently active, and the server
     * itself answers its /global/health endpoint on the configured port.
     * Not gated on OPENCODE_BIN being set - the app never reads opencode.db
     * directly for the serve path, and a unit that's absent/stopped is
     * exactly the failure this should surface (same as every other check
     * here: a missing piece reported, not silently skipped).
     *
     * @return array{key:string, label:string, ok:bool, detail:?string}
     */
    public static function opencode_serve_check(): array
    {
        $unitName = 'opencode-serve.service';

        $activeResult = ProcessRunner::run_process(['systemctl', '--user', 'is-active', $unitName]);
        $unitActive = trim($activeResult['stdout']) === 'active';
        $unitDetail = trim($activeResult['stdout']) ?: trim($activeResult['stderr']);

        $isEnabled = trim(ProcessRunner::run_process(['systemctl', '--user', 'is-enabled', $unitName])['stdout']) !== '';

        if (!$unitActive || !$isEnabled) {
            return [
                'key' => 'opencode_serve',
                'label' => 'OpenCode server',
                'ok' => false,
                'detail' => "{$unitName}: " . ($unitDetail !== '' ? $unitDetail : 'not enabled'),
            ];
        }

        $healthResult = ProcessRunner::run_process([
            'curl', '--silent', '--max-time', '3',
            'http://localhost:4096/global/health',
        ]);

        $healthy = trim($healthResult['stdout']) !== '' && $healthResult['exit'] === 0;

        return [
            'key' => 'opencode_serve',
            'label' => 'OpenCode server',
            'ok' => $healthy,
            'detail' => $healthy
                ? "{$unitName} active, healthy on :4096"
                : "{$unitName} active but /global/health did not answer",
        ];
    }

    /**
     * The Sessioneer OpenCode plugin (host-agent/opencode-plugins/sessioneer-permissions.js)
     * - the authoritative pending-permission signal, loaded as a global plugin
     * by install.sh. A session survives this check only if the file is present
     * at the global plugin path; missing means blocked-prompts for OpenCode
     * silently won't appear (same "report a missing piece, don't skip it"
     * rule as every other check here). Not gated on OPENCODE_BIN - the file is
     * harmless to report even when no opencode_bin is configured.
     *
     * @return array{key:string, label:string, ok:bool, detail:?string}
     */
    public static function opencode_plugin_check(): array
    {
        $pluginPath = Config::home_root() . '/.config/opencode/plugins/sessioneer-permissions.js';

        if ($pluginPath === '') {
            return ['key' => 'opencode_plugin', 'label' => 'OpenCode Sessioneer plugin', 'ok' => false, 'detail' => 'home root unknown'];
        }

        $ok = is_file($pluginPath);

        return [
            'key' => 'opencode_plugin',
            'label' => 'OpenCode Sessioneer plugin',
            'ok' => $ok,
            'detail' => $ok ? $pluginPath : 'not installed (run host-agent/install.sh; restart opencode TUIs to load it)',
        ];
    }

    /**
     * @return array{key:string, label:string, ok:bool, detail:?string}
     */
    private static function codex_bridge_check(): array
    {
        $unitName = 'sessioneer-codex-bridge.service';

        $activeResult = ProcessRunner::run_process(['systemctl', '--user', 'is-active', $unitName]);
        $unitActive = trim($activeResult['stdout']) === 'active';
        $unitDetail = trim($activeResult['stdout']) ?: trim($activeResult['stderr']);

        $isEnabled = trim(ProcessRunner::run_process(['systemctl', '--user', 'is-enabled', $unitName])['stdout']) !== '';

        if (!$unitActive || !$isEnabled) {
            return [
                'key' => 'codex_bridge',
                'label' => 'Codex bridge',
                'ok' => false,
                'detail' => "{$unitName}: " . ($unitDetail !== '' ? $unitDetail : 'not enabled'),
            ];
        }

        $reachable = self::codex_bridge_reachable();

        return [
            'key' => 'codex_bridge',
            'label' => 'Codex bridge',
            'ok' => $reachable['ok'] ?? false,
            'detail' => ($reachable['ok'] ?? false)
                ? "{$unitName} active, bridge reachable"
                : "{$unitName} active but " . ($reachable['detail'] ?? 'bridge unavailable'),
        ];
    }

    /**
     * "Is everything this app needs actually installed/configured" - one
     * combined check for the dashboard's health box, instead of leaving
     * Andres to discover each missing piece separately (a stale/never-set
     * VAPID key, tmux's socket dir wiped by a reboot, etc.). Lives here rather than Sessions.php despite covering
     * plenty of non-push things, since it needs PushDeliveryService::push_configured()
     * and Sessions.php can't require this file back without a cycle - same
     * reasoning as dispatch_push_action() (in Push.php) has for staying separate.
     *
     * @return array{ok:bool, checks:array<int, array{key:string, label:string, ok:bool, detail:?string}>}
     */
    public static function health_check(): array
    {
        $checks = [];

        // One "Claude Code" section per configured profile (Config::
        // claude_profiles_to_scan()) - a work account with no hooks
        // installed in its OWN settings.json would otherwise never show up
        // here at all, and silently reports permanently idle/unknown (see
        // Dibs plan #230). The default account keeps its plain "Claude
        // Code" section label unchanged; a named profile gets its own
        // "Claude Code (work)"-style section so this stays legible once
        // more than one account is configured.
        foreach (Config::claude_profiles_to_scan() as $profile) {
            $settings = [];
            $settingsOk = true;
            $settingsMessage = null;
            $path = Config::claude_settings_path($profile);
            $raw = @file_get_contents($path);

            if ($raw !== false) {
                $decoded = json_decode($raw, true);

                if (is_array($decoded)) {
                    $settings = $decoded;
                } else {
                    $settingsOk = false;
                    $settingsMessage = $path . ' exists but is not valid JSON';
                }
            }

            $section = $profile === null ? 'Claude Code' : "Claude Code ({$profile})";

            foreach (HookService::app_hooks_status($settings) as $hook) {
                $checks[] = [
                    'key' => 'hook_' . ($profile ?? 'default') . '_' . strtolower($hook['event']),
                    'section' => $section,
                    'label' => $hook['event'] . ' hook',
                    'ok' => $settingsOk && $hook['present'],
                    'detail' => $settingsOk ? null : $settingsMessage,
                ];
            }
        }

        $tmuxSocketDir = dirname(Config::tmux_socket());
        $checks[] = [
            'key' => 'tmux_socket_dir',
            'section' => 'Global',
            'label' => 'tmux socket dir',
            'ok' => is_dir($tmuxSocketDir),
            'detail' => $tmuxSocketDir,
        ];

        $checks[] = [
            'key' => 'vapid_keys',
            'section' => 'Global',
            'label' => 'VAPID push keys',
            'ok' => PushDeliveryService::push_configured(),
            'detail' => null,
        ];

        $pushDelivery = self::push_delivery_check();
        $pushDelivery['section'] = 'Global';
        $checks[] = $pushDelivery;

        $pushQuota = self::push_quota_delivery_check();
        $pushQuota['section'] = 'Global';
        $checks[] = $pushQuota;

        $opencodeServe = self::opencode_serve_check();
        $opencodeServe['section'] = 'OpenCode';
        $checks[] = $opencodeServe;

        $opencodePlugin = self::opencode_plugin_check();
        $opencodePlugin['section'] = 'OpenCode';
        $checks[] = $opencodePlugin;

        $codexBridge = self::codex_bridge_check();
        $codexBridge['section'] = 'Codex';
        $checks[] = $codexBridge;

        $checks[] = TuiLayoutMismatchService::health_check();

        $vendorAutoload = Config::sessioneer_repo_root() . '/vendor/autoload.php';
        $checks[] = [
            'key' => 'composer_vendor',
            'section' => 'Global',
            'label' => 'Composer vendor/',
            'ok' => is_file($vendorAutoload),
            'detail' => $vendorAutoload,
        ];

        return ['ok' => true, 'checks' => $checks];
    }
}
