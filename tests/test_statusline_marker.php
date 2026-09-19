<?php
declare(strict_types=1);

/**
 * Exercises StatuslineMarkerService (parsing a live-pane session-id marker,
 * locating/installing into a statusLine script) and
 * SessionService::self_heal_agent_session_id() (the consuming side - see
 * both classes' own docblocks for why this exists: a second, independent
 * cross-check against SidecarStore's agent_session_id, on top of the
 * SessionStart hook's own transcript-existence check added the same day,
 * 2026-08-08). Uses its own fixture HOME_ROOT/SIDECAR_DIR, never the real
 * ~/.claude/settings.json, statusline script, or sidecar dir.
 */

require __DIR__ . '/lib/assert.php';
require dirname(__DIR__) . '/host-agent/lib/Sessions.php';

use HostAgent\Services\Config;
use HostAgent\Services\SessionService;
use HostAgent\Services\StatuslineMarkerService;
use HostAgent\Stores\GlobalStateStore;
use HostAgent\Stores\SidecarStore;

$realHomeRoot = Config::home_root();
$realPushSqliteFile = Config::push_sqlite_path();

$fixtureHome = sys_get_temp_dir() . '/sessioneer-test-statusline-home-' . bin2hex(random_bytes(4));
$fixtureSidecarDir = sys_get_temp_dir() . '/sessioneer-test-statusline-sidecars-' . bin2hex(random_bytes(4));
$fixturePushSqliteFile = sys_get_temp_dir() . '/sessioneer-test-statusline-quota-' . bin2hex(random_bytes(4)) . '/push.sqlite';

putenv("HOME_ROOT={$fixtureHome}");
putenv("SIDECAR_DIR={$fixtureSidecarDir}");
putenv("PUSH_SQLITE_FILE={$fixturePushSqliteFile}");

if (Config::home_root() === $realHomeRoot) {
    fwrite(STDERR, "REFUSING TO RUN: HOME_ROOT still resolves to the real home directory.\n");
    exit(1);
}

if (Config::push_sqlite_path() === $realPushSqliteFile) {
    fwrite(STDERR, "REFUSING TO RUN: PUSH_SQLITE_FILE resolves to the real host state file.\n");
    exit(1);
}

mkdir($fixtureSidecarDir, 0700, true);
mkdir("{$fixtureHome}/.claude", 0700, true);

$settingsPath = Config::claude_settings_path();

try {
    // --- parse_marker_from_pane(): happy/sad paths ---

    $id = 'abcdef01-2345-6789-abcd-ef0123456789';
    $empty = ['session_id' => null, 'context_used_percentage' => null, 'git_worktree' => null];

    $full = StatuslineMarkerService::parse_marker_from_pane("Opus | ctx: 12%\n\x1b[2msessioneer-data:{\"session_id\":\"{$id}\",\"ctx_pct\":42,\"git_worktree\":\"my-feature\"}\x1b[0m\n");
    assert_equal($id, $full['session_id'], 'parse_marker_from_pane: extracts session_id from a real rendered pane (ANSI codes and all)');
    assert_equal(42.0, $full['context_used_percentage'], 'parse_marker_from_pane: extracts context_used_percentage');
    assert_equal('my-feature', $full['git_worktree'], 'parse_marker_from_pane: extracts git_worktree');

    // Fields the shell side drops (jq's with_entries(select(.value != null))
    // omits null/absent ones - see StatuslineMarkerService::JQ_FILTER) come
    // back null here too, without the whole marker being rejected.
    $partial = StatuslineMarkerService::parse_marker_from_pane("sessioneer-data:{\"session_id\":\"{$id}\"}");
    assert_equal($id, $partial['session_id'], 'parse_marker_from_pane: session_id alone (no ctx_pct/git_worktree keys) still parses');
    assert_equal(null, $partial['context_used_percentage'], 'parse_marker_from_pane: a genuinely absent key is null, not 0 or an error');
    assert_equal(null, $partial['git_worktree'], 'parse_marker_from_pane: a genuinely absent key is null, not an error');

    assert_equal($empty, StatuslineMarkerService::parse_marker_from_pane('no marker here at all'), 'parse_marker_from_pane: all-null when the marker is entirely absent');
    assert_equal($empty, StatuslineMarkerService::parse_marker_from_pane('sessioneer-data:{not valid json'), 'parse_marker_from_pane: all-null on unparseable JSON after the marker, never crashes');
    assert_equal(null, StatuslineMarkerService::parse_marker_from_pane("sessioneer-data:{\"session_id\":\"not-a-real-uuid\"}")['session_id'], 'parse_marker_from_pane: session_id is null when the JSON value is not UUID-shaped');
    assert_equal(strtolower($id), StatuslineMarkerService::parse_marker_from_pane("sessioneer-data:{\"session_id\":\"" . strtoupper($id) . "\"}")['session_id'], 'parse_marker_from_pane: session_id is case-insensitive, normalized to lowercase');

    // Found live 2026-08-23: a tall pane (TMUX_PANE_HEIGHT=150 by default)
    // can still have an OLDER statusline render visible above the current
    // one after a session rotates its agent_session_id (/clear, /compact,
    // --resume) - matching the first occurrence fed self_heal_agent_session_id()
    // a stale id, repeatedly overwriting a correct sidecar with a wrong one.
    $staleId = 'aaaaaaaa-1111-2222-3333-444444444444';
    $freshId = 'bbbbbbbb-5555-6666-7777-888888888888';
    $multiMarker = StatuslineMarkerService::parse_marker_from_pane("sessioneer-data:{\"session_id\":\"{$staleId}\",\"ctx_pct\":85}\n...older output...\nsessioneer-data:{\"session_id\":\"{$freshId}\",\"ctx_pct\":12}\n");
    assert_equal($freshId, $multiMarker['session_id'], 'parse_marker_from_pane: with multiple markers visible in one pane, takes the LAST (most recent) one, not the first');
    assert_equal(12.0, $multiMarker['context_used_percentage'], 'parse_marker_from_pane: with multiple markers, the other fields come from that same last marker too');

    // --- locate_statusline_script(): happy/sad paths ---

    $scriptPath = "{$fixtureHome}/my-statusline.sh";
    file_put_contents($scriptPath, "#!/usr/bin/env bash\necho hi\n");
    chmod($scriptPath, 0700);

    assert_equal(
        $scriptPath,
        StatuslineMarkerService::locate_statusline_script(['statusLine' => ['type' => 'command', 'command' => "bash {$scriptPath}"]]),
        'locate_statusline_script: finds the script file referenced by a real, writable command'
    );
    // Found live 2026-09-18: a command written the normal way ("bash
    // $HOME/...", never shell-evaluated here) used to never resolve at
    // all - is_file() on the literal, unexpanded "$HOME/..." string can
    // never match a real file, so check_statusline_marker() reported
    // "not installed" against an already-fully-installed script forever.
    assert_equal(
        $scriptPath,
        StatuslineMarkerService::locate_statusline_script(['statusLine' => ['type' => 'command', 'command' => 'bash $HOME/my-statusline.sh']]),
        'locate_statusline_script: expands a literal $HOME reference to the real home directory'
    );
    assert_equal(
        $scriptPath,
        StatuslineMarkerService::locate_statusline_script(['statusLine' => ['type' => 'command', 'command' => 'bash ${HOME}/my-statusline.sh']]),
        'locate_statusline_script: expands a ${HOME} (braced) reference too'
    );
    assert_equal(null, StatuslineMarkerService::locate_statusline_script([]), 'locate_statusline_script: null when statusLine is not configured at all');
    assert_equal(null, StatuslineMarkerService::locate_statusline_script(['statusLine' => ['type' => 'other', 'command' => "bash {$scriptPath}"]]), 'locate_statusline_script: null when type is not "command"');
    assert_equal(null, StatuslineMarkerService::locate_statusline_script(['statusLine' => ['type' => 'command', 'command' => 'bash /does/not/exist.sh']]), 'locate_statusline_script: null when the referenced file does not exist');
    assert_equal(null, StatuslineMarkerService::locate_statusline_script(['statusLine' => ['type' => 'command', 'command' => 'echo inline-one-liner']]), 'locate_statusline_script: null for an inline command with no locatable file path');

    unlink($scriptPath);

    // --- install_statusline_marker(): appends into an EXISTING statusline script, preserving its own output ---

    file_put_contents($scriptPath, "#!/usr/bin/env bash\ninput=\$(cat)\nmodel=\$(echo \"\$input\" | jq -r '.model.display_name // \"\"')\nprintf '%s' \"\$model\"\necho \"\"\n");
    chmod($scriptPath, 0700);
    file_put_contents($settingsPath, json_encode(['statusLine' => ['type' => 'command', 'command' => "bash {$scriptPath}"], 'theme' => 'dark']));

    $preCheck = StatuslineMarkerService::check_statusline_marker();
    assert_equal(true, $preCheck['ok'], 'check_statusline_marker: ok before install');
    assert_equal(false, $preCheck['installed'], 'check_statusline_marker: not installed before install');

    $install = StatuslineMarkerService::install_statusline_marker();
    assert_equal(true, $install['ok'], 'install_statusline_marker: succeeds against an existing statusline script');
    assert_equal(true, $install['installed'], 'install_statusline_marker: reports installed');

    $postCheck = StatuslineMarkerService::check_statusline_marker();
    assert_equal(true, $postCheck['installed'], 'check_statusline_marker: installed after install_statusline_marker()');

    $settingsAfter = json_decode((string)file_get_contents($settingsPath), true);
    assert_equal('dark', $settingsAfter['theme'] ?? null, 'install_statusline_marker: preserves unrelated pre-existing settings.json content');
    assert_equal("bash {$scriptPath}", $settingsAfter['statusLine']['command'] ?? null, 'install_statusline_marker: leaves the pre-existing statusLine command untouched (appends to the script itself, not a new one)');

    // Running the patched script for real with a JSON payload must still
    // print the original script's own output (model name), AND our marker -
    // the stdin-replay trick (exec 0<<< "$sessioneer_statusline_input") must not
    // break the original script's own later `cat`.
    $descriptors = [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
    $proc = proc_open(['bash', $scriptPath], $descriptors, $pipes);
    fwrite($pipes[0], json_encode(['model' => ['display_name' => 'Opus'], 'session_id' => $id]));
    fclose($pipes[0]);
    $out = stream_get_contents($pipes[1]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    proc_close($proc);

    assert_contains('Opus', $out, 'install_statusline_marker: the patched script still produces the original script\'s own output');
    assert_equal($id, StatuslineMarkerService::parse_marker_from_pane($out)['session_id'], 'install_statusline_marker: the patched script\'s output carries a parseable session-id marker');

    // The quota-capture block runs silently (no terminal output). The
    // payload above had no rate_limits at all, so it's a harmless no-op -
    // no state row created yet.
    assert_equal(null, GlobalStateStore::read(Config::quota_live_state_key()), 'quota capture: no state row created yet when the payload has no rate_limits at all');

    // A payload WITH rate_limits: written straight through on the first
    // sighting, then a lower-pct-but-same-resets_at rewrite is ignored
    // (protects against a stale/idle session's script firing after a
    // fresher one already reported higher usage in the same window - see
    // merge_quota_bucket() in host-agent/quota_live_state_write.php's own
    // docblock and the 2026-08-21 duplicate-notification bug it exists to
    // prevent).
    $runWithQuota = static function (string $scriptPath, array $payload): void {
        $descriptors = [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
        $proc = proc_open(['bash', $scriptPath], $descriptors, $pipes);
        fwrite($pipes[0], json_encode($payload));
        fclose($pipes[0]);
        stream_get_contents($pipes[1]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        proc_close($proc);
    };

    $runWithQuota($scriptPath, [
        'session_id' => $id,
        'rate_limits' => ['five_hour' => ['used_percentage' => 51, 'resets_at' => 1738425600], 'seven_day' => ['used_percentage' => 40, 'resets_at' => 1738857600]],
    ]);
    $afterFresh = GlobalStateStore::read(Config::quota_live_state_key());
    assert_equal(51, $afterFresh['session']['pct'] ?? null, 'quota capture: writes session pct straight from rate_limits.five_hour.used_percentage');
    assert_equal(1738425600, $afterFresh['session']['resets_at'] ?? null, 'quota capture: writes the real epoch resets_at, not a reconstructed duration');
    assert_equal(40, $afterFresh['week_all']['pct'] ?? null, 'quota capture: writes week_all pct straight from rate_limits.seven_day.used_percentage');

    $runWithQuota($scriptPath, [
        'session_id' => $id,
        'rate_limits' => ['five_hour' => ['used_percentage' => 45, 'resets_at' => 1738425600], 'seven_day' => ['used_percentage' => 40, 'resets_at' => 1738857600]],
    ]);
    $afterStale = GlobalStateStore::read(Config::quota_live_state_key());
    assert_equal(51, $afterStale['session']['pct'] ?? null, 'quota capture: a lower pct with the SAME resets_at (a stale/idle session\'s write) is ignored, not overwritten');

    $runWithQuota($scriptPath, [
        'session_id' => $id,
        'rate_limits' => ['five_hour' => ['used_percentage' => 5, 'resets_at' => 1738500000], 'seven_day' => ['used_percentage' => 40, 'resets_at' => 1738857600]],
    ]);
    $afterReset = GlobalStateStore::read(Config::quota_live_state_key());
    assert_equal(5, $afterReset['session']['pct'] ?? null, 'quota capture: a lower pct with a DIFFERENT resets_at (a genuine window rollover) IS accepted');
    assert_equal(1738500000, $afterReset['session']['resets_at'] ?? null, 'quota capture: resets_at updates along with the accepted reset');

    // Idempotency: installing again does not duplicate either block.
    $secondInstall = StatuslineMarkerService::install_statusline_marker();
    assert_equal(true, $secondInstall['installed'], 'install_statusline_marker: calling twice still reports installed');
    // Distinct from the CAPTURE_* marker (which also ends in "... session-id
    // marker (managed, safe to delete) >>>") - only the exact MARKER_BEGIN
    // line itself is unique to the actual marker block being duplicated.
    $scriptContent = (string)file_get_contents($scriptPath);
    assert_equal(1, substr_count($scriptContent, '# >>> sessioneer: session-id marker (managed, safe to delete) >>>'), 'install_statusline_marker: calling twice does not duplicate the marker block');
    assert_equal(1, substr_count($scriptContent, '# >>> sessioneer: quota state capture (managed, safe to delete) >>>'), 'install_statusline_marker: calling twice does not duplicate the quota-capture block');

    GlobalStateStore::delete(Config::quota_live_state_key());
    unlink($settingsPath);
    unlink($scriptPath);

    // --- install_statusline_marker(): the full check/install/re-check
    // cycle against a command written the normal way a real settings.json
    // has it ("bash $HOME/...", never shell-evaluated here) - the exact
    // shape that used to report "not installed" forever, see
    // expand_home_token()'s own docblock. ---
    file_put_contents($scriptPath, "#!/usr/bin/env bash\ninput=\$(cat)\nmodel=\$(echo \"\$input\" | jq -r '.model.display_name // \"\"')\nprintf '%s' \"\$model\"\necho \"\"\n");
    chmod($scriptPath, 0700);
    file_put_contents($settingsPath, json_encode(['statusLine' => ['type' => 'command', 'command' => 'bash $HOME/my-statusline.sh']]));

    assert_equal(false, StatuslineMarkerService::check_statusline_marker()['installed'], 'check_statusline_marker: not installed yet, with a $HOME-style command');

    $homeStyleInstall = StatuslineMarkerService::install_statusline_marker();
    assert_equal(true, $homeStyleInstall['ok'], 'install_statusline_marker: succeeds against a $HOME-style command');
    assert_equal(true, $homeStyleInstall['installed'], 'install_statusline_marker: installed=true against a $HOME-style command');
    assert_equal(true, StatuslineMarkerService::check_statusline_marker()['installed'], 'check_statusline_marker: reports installed after installing into a $HOME-style command - the actual bug, now fixed');

    $settingsAfterHomeStyle = json_decode((string)file_get_contents($settingsPath), true);
    assert_equal('bash $HOME/my-statusline.sh', $settingsAfterHomeStyle['statusLine']['command'] ?? null, 'install_statusline_marker: leaves the $HOME-style command text itself untouched (only the script file is patched)');

    GlobalStateStore::delete(Config::quota_live_state_key());
    unlink($settingsPath);
    unlink($scriptPath);

    // --- install_statusline_marker(): UPGRADE path - a script that already
    // has the (older) session-id marker but predates the quota-capture
    // block gets just that block appended, without disturbing the marker
    // or needing a second stdin-capture prelude. This is the exact shape
    // of Andres's own real, already-installed script before this feature
    // existed. ---
    file_put_contents($scriptPath, "#!/usr/bin/env bash\ninput=\$(cat)\nmodel=\$(echo \"\$input\" | jq -r '.model.display_name // \"\"')\nprintf '%s' \"\$model\"\necho \"\"\n");
    chmod($scriptPath, 0700);
    file_put_contents($settingsPath, json_encode(['statusLine' => ['type' => 'command', 'command' => "bash {$scriptPath}"]]));
    $preUpgradeInstall = StatuslineMarkerService::install_statusline_marker();
    assert_equal(true, $preUpgradeInstall['installed'], 'install_statusline_marker: sets up a baseline old-style install for the upgrade test');
    $oldStyleContent = (string)file_get_contents($scriptPath);
    // Simulate "predates the quota-capture block" by stripping just that
    // block back out, leaving everything else (CAPTURE_BEGIN + MARKER)
    // exactly as a real pre-upgrade script would have it.
    $oldStyleContent = (string)preg_replace('/\n?# >>> sessioneer: quota state capture.*?# <<< sessioneer: quota state capture <<<\n?/s', "\n", $oldStyleContent);
    assert_true(!str_contains($oldStyleContent, 'quota state capture'), 'upgrade test setup: quota-capture block successfully stripped back out');
    file_put_contents($scriptPath, $oldStyleContent);

    assert_equal(false, StatuslineMarkerService::check_statusline_marker()['installed'], 'check_statusline_marker: not fully installed with only the session-id marker present');

    $upgradeInstall = StatuslineMarkerService::install_statusline_marker();
    assert_equal(true, $upgradeInstall['ok'], 'install_statusline_marker: upgrade succeeds');
    assert_equal(true, $upgradeInstall['installed'], 'install_statusline_marker: upgrade reports installed');
    assert_equal(true, StatuslineMarkerService::check_statusline_marker()['installed'], 'check_statusline_marker: fully installed after the upgrade');

    $upgradedContent = (string)file_get_contents($scriptPath);
    assert_equal(1, substr_count($upgradedContent, '# >>> sessioneer: session-id marker (managed, safe to delete) >>>'), 'install_statusline_marker: upgrade does not touch/duplicate the existing session-id marker');
    assert_equal(1, substr_count($upgradedContent, '# >>> sessioneer: capture stdin for session-id marker (managed, safe to delete) >>>'), 'install_statusline_marker: upgrade does not add a second stdin-capture prelude');
    assert_equal(1, substr_count($upgradedContent, '# >>> sessioneer: quota state capture (managed, safe to delete) >>>'), 'install_statusline_marker: upgrade appends exactly one quota-capture block');

    // The upgraded script still needs to actually work end to end.
    $runWithQuota($scriptPath, [
        'session_id' => $id,
        'rate_limits' => ['five_hour' => ['used_percentage' => 33, 'resets_at' => 1738425600], 'seven_day' => ['used_percentage' => 20, 'resets_at' => 1738857600]],
    ]);
    $afterUpgradeRun = GlobalStateStore::read(Config::quota_live_state_key());
    assert_equal(33, $afterUpgradeRun['session']['pct'] ?? null, 'install_statusline_marker: the upgraded script writes quota state correctly');

    // --- install_statusline_marker(): STALE quota-capture BODY - a script
    // whose block markers are already present but whose body still has the
    // old jq-merge-then-mv logic (found live 2026-08-24 in Andres's own
    // already-installed script, predating the move to
    // host-agent/quota_live_state_write.php/GlobalStateStore) gets that
    // block's body REPLACED in place, not skipped as "already installed" -
    // see quota_capture_up_to_date()'s own docblock. ---
    GlobalStateStore::delete(Config::quota_live_state_key());
    $staleQuotaBody = str_replace(
        implode("\n", [
            '# >>> sessioneer: quota state capture (managed, safe to delete) >>>',
            'sessioneer_quota_new=$(printf \'%s\' "$sessioneer_statusline_input" | jq -c \'{five_hour: .rate_limits.five_hour, seven_day: .rate_limits.seven_day} | with_entries(select(.value != null))\' 2>/dev/null)',
            'if [ -n "$sessioneer_quota_new" ] && [ "$sessioneer_quota_new" != "{}" ]; then',
            '  printf \'%s\' "$sessioneer_quota_new" | ' . Config::quota_live_state_write_command() . ' >/dev/null 2>&1',
            'fi',
            '# <<< sessioneer: quota state capture <<<',
        ]),
        implode("\n", [
            '# >>> sessioneer: quota state capture (managed, safe to delete) >>>',
            'sessioneer_quota_new=$(printf \'%s\' "$sessioneer_statusline_input" | jq -c \'{five_hour: .rate_limits.five_hour, seven_day: .rate_limits.seven_day} | with_entries(select(.value != null))\' 2>/dev/null)',
            'if [ -n "$sessioneer_quota_new" ] && [ "$sessioneer_quota_new" != "{}" ]; then',
            '  echo "$sessioneer_quota_new" > /tmp/old-jq-quota-state.json',
            'fi',
            '# <<< sessioneer: quota state capture <<<',
        ]),
        $upgradedContent
    );
    assert_true(str_contains($staleQuotaBody, 'old-jq-quota-state.json'), 'stale-body test setup: the quota-capture block body was successfully swapped for the old jq-only version');
    file_put_contents($scriptPath, $staleQuotaBody);

    assert_equal(false, StatuslineMarkerService::check_statusline_marker()['installed'], 'check_statusline_marker: a present-but-stale quota-capture body is NOT reported as installed');

    $staleUpgradeInstall = StatuslineMarkerService::install_statusline_marker();
    assert_equal(true, $staleUpgradeInstall['ok'], 'install_statusline_marker: replacing a stale quota-capture body succeeds');
    assert_equal(true, $staleUpgradeInstall['installed'], 'install_statusline_marker: reports installed after replacing the stale body');
    assert_equal(true, StatuslineMarkerService::check_statusline_marker()['installed'], 'check_statusline_marker: installed=true once the stale body has been replaced');

    $replacedContent = (string)file_get_contents($scriptPath);
    assert_true(!str_contains($replacedContent, 'old-jq-quota-state.json'), 'install_statusline_marker: the stale jq-only body is gone after the replace');
    assert_equal(1, substr_count($replacedContent, '# >>> sessioneer: quota state capture (managed, safe to delete) >>>'), 'install_statusline_marker: replacing the stale body does not duplicate the block');
    assert_equal(1, substr_count($replacedContent, '# >>> sessioneer: session-id marker (managed, safe to delete) >>>'), 'install_statusline_marker: replacing the quota-capture body leaves the session-id marker block untouched');

    $runWithQuota($scriptPath, [
        'session_id' => $id,
        'rate_limits' => ['five_hour' => ['used_percentage' => 61, 'resets_at' => 1738425600], 'seven_day' => ['used_percentage' => 22, 'resets_at' => 1738857600]],
    ]);
    $afterStaleReplaceRun = GlobalStateStore::read(Config::quota_live_state_key());
    assert_equal(61, $afterStaleReplaceRun['session']['pct'] ?? null, 'install_statusline_marker: the replaced script writes quota state to GlobalStateStore, not the old jq/file path');
    @unlink('/tmp/old-jq-quota-state.json');

    GlobalStateStore::delete(Config::quota_live_state_key());
    unlink($settingsPath);
    unlink($scriptPath);

    // --- install_statusline_marker(): no statusLine configured at all -> installs a fallback script this app owns ---

    $fallbackPath = Config::statusline_fallback_script_path();
    @unlink($fallbackPath);

    file_put_contents($settingsPath, json_encode(['theme' => 'light']));
    $fallbackInstall = StatuslineMarkerService::install_statusline_marker();
    assert_equal(true, $fallbackInstall['ok'], 'install_statusline_marker: succeeds when no statusLine was configured at all');
    assert_equal(true, $fallbackInstall['installed'], 'install_statusline_marker: installed=true after creating the fallback script');
    assert_true(is_file($fallbackPath), 'install_statusline_marker: creates the fallback script file');

    $settingsAfterFallback = json_decode((string)file_get_contents($settingsPath), true);
    assert_equal('light', $settingsAfterFallback['theme'] ?? null, 'install_statusline_marker: preserves unrelated settings when installing the fallback');
    assert_equal('bash ' . $fallbackPath, $settingsAfterFallback['statusLine']['command'] ?? null, 'install_statusline_marker: points statusLine at the new fallback script');
    assert_true(str_contains((string)file_get_contents($fallbackPath), 'quota state capture'), 'install_statusline_marker: the fallback script also carries the quota-capture block');

    $runWithQuota($fallbackPath, [
        'session_id' => $id,
        'rate_limits' => ['five_hour' => ['used_percentage' => 12, 'resets_at' => 1738425600], 'seven_day' => ['used_percentage' => 8, 'resets_at' => 1738857600]],
    ]);
    $afterFallbackRun = GlobalStateStore::read(Config::quota_live_state_key());
    assert_equal(12, $afterFallbackRun['session']['pct'] ?? null, 'install_statusline_marker: the fallback script writes quota state correctly too');

    GlobalStateStore::delete(Config::quota_live_state_key());
    unlink($fallbackPath);
    unlink($settingsPath);

    // --- install_statusline_marker(): malformed settings.json -> refuses, leaves it untouched ---

    file_put_contents($settingsPath, '{not valid json');
    $malformedInstall = StatuslineMarkerService::install_statusline_marker();
    assert_equal(false, $malformedInstall['ok'], 'install_statusline_marker: refuses a malformed settings.json');
    assert_equal('{not valid json', file_get_contents($settingsPath), 'install_statusline_marker: leaves a malformed settings.json byte-for-byte untouched');
    unlink($settingsPath);

    // --- install_statusline_marker(): statusLine configured but unrecognized shape -> refuses, does not guess ---

    file_put_contents($settingsPath, json_encode(['statusLine' => ['type' => 'command', 'command' => 'some-inline-thing --no-file-path']]));
    $unrecognized = StatuslineMarkerService::install_statusline_marker();
    assert_equal(false, $unrecognized['ok'], 'install_statusline_marker: refuses when statusLine is configured but no script file can be located');
    unlink($settingsPath);

    // --- SessionService::self_heal_agent_session_id(): the consuming side ---

    $projectsDir = "{$fixtureHome}/.claude/projects/fixture-project";
    mkdir($projectsDir, 0700, true);

    $realId = '11111111-1111-4111-8111-111111111111';
    $phantomId = '99999999-9999-4999-8999-999999999999';
    file_put_contents("{$projectsDir}/{$realId}.jsonl", json_encode(['type' => 'user', 'sessionId' => $realId]) . "\n");

    $sidecar = ['workdir' => '/fixture/workdir', 'spawned_at' => 1000, 'agent_session_id' => 'stale-old-id', 'spawned_by_app' => true];

    // Happy path: live marker disagrees and resolves to a real transcript -> heals.
    $healed = SessionService::self_heal_agent_session_id('cc-selfheal-test', $sidecar, 'stale-old-id', $realId);
    assert_equal($realId, $healed, 'self_heal_agent_session_id: returns the live id when it resolves to a real transcript');
    $healedSidecar = SidecarStore::read_sidecar('cc-selfheal-test');
    assert_equal($realId, $healedSidecar['agent_session_id'] ?? null, 'self_heal_agent_session_id: rewrites the sidecar with the healed id');
    assert_equal('/fixture/workdir', $healedSidecar['workdir'] ?? null, 'self_heal_agent_session_id: preserves workdir across the heal');
    assert_equal(1000, $healedSidecar['spawned_at'] ?? null, 'self_heal_agent_session_id: preserves spawned_at across the heal');
    assert_equal(true, $healedSidecar['spawned_by_app'] ?? null, 'self_heal_agent_session_id: preserves spawned_by_app across the heal');

    // Sad path: live marker disagrees but has NO real transcript (phantom, e.g. a nested claude invocation that never wrote one) -> not trusted, no heal.
    SidecarStore::write_sidecar('cc-selfheal-phantom', ['workdir' => '/x', 'spawned_at' => 500, 'agent_session_id' => 'original-id', 'spawned_by_app' => true]);
    $notHealed = SessionService::self_heal_agent_session_id('cc-selfheal-phantom', SidecarStore::read_sidecar('cc-selfheal-phantom'), 'original-id', $phantomId);
    assert_equal('original-id', $notHealed, 'self_heal_agent_session_id: a live id with no matching transcript is never trusted enough to override a working sidecar');
    assert_equal('original-id', SidecarStore::read_sidecar('cc-selfheal-phantom')['agent_session_id'] ?? null, 'self_heal_agent_session_id: sidecar is left untouched when the live id is phantom');

    // Sad path: no sidecar at all -> no-op, no crash.
    $noSidecarResult = SessionService::self_heal_agent_session_id('cc-selfheal-nosidecar', null, null, $realId);
    assert_equal(null, $noSidecarResult, 'self_heal_agent_session_id: no-op (returns the original null) when there is no sidecar to preserve fields from');
    assert_equal(null, SidecarStore::read_sidecar('cc-selfheal-nosidecar'), 'self_heal_agent_session_id: never creates a sidecar from a live marker alone');

    // Sad path: pane has no marker at all -> parse_marker_from_pane() would
    // hand build_session_entry() a null session_id, so this is what it
    // passes through -> no-op.
    $noMarkerResult = SessionService::self_heal_agent_session_id('cc-selfheal-nomarker', $sidecar, 'stale-old-id', null);
    assert_equal('stale-old-id', $noMarkerResult, 'self_heal_agent_session_id: no-op when there is no live id at all (pane carried no marker)');

    // Sad path: live marker already matches the current id -> no unnecessary write, same value returned.
    $alreadyMatching = SessionService::self_heal_agent_session_id('cc-selfheal-match', $healedSidecar, $realId, $realId);
    assert_equal($realId, $alreadyMatching, 'self_heal_agent_session_id: returns the same id unchanged when the live marker already matches');
} finally {
    @unlink($settingsPath);
    @unlink(Config::statusline_fallback_script_path());
    @GlobalStateStore::delete(Config::quota_live_state_key());
    @unlink($fixturePushSqliteFile);
    @unlink($fixturePushSqliteFile . '-wal');
    @unlink($fixturePushSqliteFile . '-shm');
    array_map('unlink', glob("{$fixtureSidecarDir}/*") ?: []);
    @rmdir($fixtureSidecarDir);
    array_map('unlink', glob("{$fixtureHome}/.claude/projects/fixture-project/*") ?: []);
    @rmdir("{$fixtureHome}/.claude/projects/fixture-project");
    @rmdir("{$fixtureHome}/.claude/projects");
    @rmdir("{$fixtureHome}/.claude");
    @unlink("{$fixtureHome}/my-statusline.sh");
    @rmdir($fixtureHome);
}

test_exit();
