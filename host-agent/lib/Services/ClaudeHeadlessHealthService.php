<?php

declare(strict_types=1);

namespace HostAgent\Services;

use HostAgent\Runtimes\ClaudeHeadlessManagerClient;
use HostAgent\Stores\GlobalStateStore;

/**
 * The "Claude Code headless" section of the dashboard's health box: is the
 * manager service up, may it start sessions, which login is it using, does
 * the installed CLI match what the protocol handling was verified against,
 * is there room for another process, and is the quota footer keeping up.
 *
 * build_checks() is pure (all inputs passed in) so every branch is testable;
 * checks() gathers the real inputs.
 */
class ClaudeHeadlessHealthService
{
    public const SECTION = 'Claude Code headless';

    /**
     * The Claude Code version the stream-json handling was captured and
     * live-tested against (tests/fixtures/claude_stream_json_*_v2_1_278.ndjson,
     * tests/test_claude_headless_live.php). Older is reported as a problem,
     * newer is only noted: re-run `bash tests/run.sh --live` after an upgrade.
     */
    public const TESTED_CLAUDE_VERSION = '2.1.278';

    /** Socket timeout for the health probe: a wedged manager must not stall the dashboard. */
    private const PROBE_TIMEOUT_SECONDS = 3;

    /** How far the stored quota may lag the manager's newest reading before the footer counts as not updating. */
    private const QUOTA_LAG_SECONDS = 300;

    /**
     * @return array<int, array{key:string, section:string, label:string, ok:bool, detail:?string}>
     */
    public static function checks(): array
    {
        $bin = Config::claude_bin();
        $unit = Config::claude_headless_unit_name();

        $active = trim(ProcessRunner::run_process(['systemctl', '--user', 'is-active', $unit])['stdout']);
        $enabled = ProcessRunner::run_process(['systemctl', '--user', 'is-enabled', $unit])['exit'] === 0;

        $reply = null;

        if ($active === 'active') {
            $reply = (new ClaudeHeadlessManagerClient(null, self::PROBE_TIMEOUT_SECONDS))->request('sessioneer/health');
        }

        $capturedAt = null;

        foreach (Config::claude_profiles_to_scan() as $profile) {
            $state = GlobalStateStore::read(Config::quota_live_state_key($profile));
            $at = is_array($state) && is_int($state['captured_at'] ?? null) ? $state['captured_at'] : null;
            $capturedAt = $at !== null && ($capturedAt === null || $at > $capturedAt) ? $at : $capturedAt;
        }

        return self::build_checks($bin, $bin !== '' && is_file($bin) && is_executable($bin), $unit, $active, $enabled, $reply, $capturedAt, time());
    }

    /**
     * @param string $unitState `systemctl is-active` output ('active', 'inactive', 'failed', '' ...)
     * @param array<string, mixed>|null $reply the manager's sessioneer/health reply; null when it was not asked
     * @param int|null $quotaCapturedAt newest stored quota capture across the configured Claude accounts
     * @return array<int, array{key:string, section:string, label:string, ok:bool, detail:?string}>
     */
    public static function build_checks(string $claudeBin, bool $binExecutable, string $unit, string $unitState, bool $unitEnabled, ?array $reply, ?int $quotaCapturedAt, int $now): array
    {
        $checks = [];

        $checks[] = self::check('claude_headless_cli', 'Claude CLI (CLAUDE_BIN)', $binExecutable, match (true) {
            $claudeBin === '' => 'CLAUDE_BIN is not set in host-agent/.env (run host-agent/install.sh)',
            !$binExecutable => "{$claudeBin} is not an executable file",
            default => $claudeBin,
        });

        $problem = $unitState !== 'active' || !$unitEnabled
            ? "{$unit}: " . ($unitState !== 'active' ? ($unitState !== '' ? $unitState : 'not installed') : 'not enabled') . ' (run host-agent/install.sh)'
            : (($reply['ok'] ?? false) !== true ? "{$unit} active but " . (is_string($reply['message'] ?? null) ? $reply['message'] : 'the manager did not answer') : null);

        if ($problem !== null || $reply === null) {
            $checks[] = self::check('claude_headless_manager', 'Headless session manager', false, $problem ?? 'manager unavailable');

            return $checks;
        }

        $pid = is_int($reply['pid'] ?? null) ? ", pid {$reply['pid']}" : '';
        $checks[] = self::check('claude_headless_manager', 'Headless session manager', true, "{$unit} active, socket reachable{$pid}");

        $blocked = is_string($reply['spawn_blocked'] ?? null) && $reply['spawn_blocked'] !== '' ? $reply['spawn_blocked'] : null;
        $checks[] = self::check('claude_headless_spawn', 'New headless sessions allowed', $blocked === null, $blocked ?? 'no billing guardrail has tripped');

        $source = $reply['last_api_key_source'] ?? null;
        $checks[] = match (true) {
            $source === null => self::check('claude_headless_credential', 'Login used by headless sessions', true, 'no headless session started since the manager started'),
            $source === 'none' => self::check('claude_headless_credential', 'Login used by headless sessions', true, 'claude.ai login (no API key)'),
            $source === '(not reported)' => self::check('claude_headless_credential', 'Login used by headless sessions', false, 'Claude did not report apiKeySource, so the login cannot be verified'),
            default => self::check('claude_headless_credential', 'Login used by headless sessions', false, "Claude reports apiKeySource='" . (is_string($source) ? $source : 'unknown') . "' (an API key would be billed instead of the subscription)"),
        };

        $version = $reply['claude_version'] ?? null;
        $tested = self::TESTED_CLAUDE_VERSION;

        if (!is_string($version) || $version === '') {
            $checks[] = self::check('claude_headless_version', 'Claude Code version', true, "not seen yet (handling verified against {$tested})");
        } elseif (version_compare($version, $tested, '<')) {
            $checks[] = self::check('claude_headless_version', 'Claude Code version', false, "{$version} is older than {$tested}, the oldest version the headless handling was verified against");
        } else {
            $checks[] = self::check('claude_headless_version', 'Claude Code version', true, version_compare($version, $tested, '>')
                ? "{$version} (newer than the tested {$tested}: run `bash tests/run.sh --live` after an upgrade)"
                : "{$version} (the tested version)");
        }

        $children = is_int($reply['children'] ?? null) ? $reply['children'] : 0;
        $max = is_int($reply['max_children'] ?? null) ? $reply['max_children'] : 0;
        $rss = is_int($reply['rss_mb'] ?? null) ? $reply['rss_mb'] : 0;
        $full = $max > 0 && $children >= $max;
        $checks[] = self::check('claude_headless_capacity', 'Headless process capacity', !$full, "{$children}/{$max} processes, {$rss} MB in use" . ($full ? '; new sessions are refused until one stops' : ''));

        $seenAt = is_int($reply['rate_limit']['seen_at'] ?? null) ? $reply['rate_limit']['seen_at'] : null;

        if ($seenAt === null) {
            $checks[] = self::check('claude_headless_quota', 'Claude quota footer is current', true, 'no rate-limit reading from the manager yet');
        } elseif ($quotaCapturedAt === null || $quotaCapturedAt + self::QUOTA_LAG_SECONDS < $seenAt) {
            $stored = $quotaCapturedAt === null ? 'nothing stored' : 'last stored ' . self::ago($now - $quotaCapturedAt);
            $checks[] = self::check('claude_headless_quota', 'Claude quota footer is current', false, "the manager saw a reading " . self::ago($now - $seenAt) . " but {$stored}; the quota state is not being updated");
        } else {
            $checks[] = self::check('claude_headless_quota', 'Claude quota footer is current', true, 'stored ' . self::ago($now - $quotaCapturedAt));
        }

        return $checks;
    }

    /** @return array{key:string, section:string, label:string, ok:bool, detail:?string} */
    private static function check(string $key, string $label, bool $ok, ?string $detail): array
    {
        return ['key' => $key, 'section' => self::SECTION, 'label' => $label, 'ok' => $ok, 'detail' => $detail];
    }

    private static function ago(int $seconds): string
    {
        $seconds = max(0, $seconds);

        return match (true) {
            $seconds < 90 => "{$seconds}s ago",
            $seconds < 5400 => intdiv($seconds, 60) . 'm ago',
            $seconds < 172800 => intdiv($seconds, 3600) . 'h ago',
            default => intdiv($seconds, 86400) . 'd ago',
        };
    }
}
