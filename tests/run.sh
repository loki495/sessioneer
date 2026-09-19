#!/usr/bin/env bash
# Runs every tests/test_*.php file against isolated fixtures (see
# tests/.env.testing) and guarantees cleanup of anything they start -
# tmux sessions, the fake claude process, sidecar files - even if a test
# fails or the run is interrupted. Usage: bash tests/run.sh [--bail] [--cleanup] [--replay] [--no-browser] [--browser] [--live]
#   --bail     stop at the first failing test file instead of running the rest
#   --replay   only run the session-replay test files (test_session_replay*.php)
#              - fast iteration on tests/lib/replay_fixture.php,
#              tests/lib/cdp.php, or tests/fixtures/replay/* without paying
#              for the other 10 unrelated test files every time
#   --live     the ONLY way to run a *_live.php test file (matched by filename,
#              currently just test_claude_trust_prompt_live.php) - every other
#              flag combination, including the plain default run, always
#              excludes them. Unlike every other test file here, a *_live.php
#              file spawns a REAL agent binary (never fake_claude), so it
#              needs that binary actually installed, is slower, and depends
#              on whatever's genuinely on this host - opt-in only, on
#              purpose, to keep the default suite hermetic and fast (see
#              test_claude_trust_prompt_live.php's own header comment for
#              why it's still zero real API cost either way).
#   --no-browser  skip any test file that needs a real browser (matched by
#              filename, *_browser.php - currently just
#              test_session_replay_browser.php) - for a host with no Chrome/
#              Chromium installed, or when you just don't want to pay for a
#              browser launch on this run. Combine with --replay to run only
#              test_session_replay.php.
#   --browser  the opposite of --no-browser: only run test files that DO need
#              a real browser (*_browser.php). Combine with --replay to run
#              only test_session_replay_browser.php - the browser-only half
#              with none of test_session_replay.php's curl-based checks.
#              Contradicts --no-browser (both together is an error).
#   --headed   test_session_replay_browser.php opens a real, visible Chrome
#              window instead of a headless one, and paces itself between
#              steps so a human can actually watch the replay happen -
#              still fully automated, nothing waits on real input. No
#              effect on any other test file. Needs a real display
#              (DISPLAY/WAYLAND_DISPLAY) - run this from a real desktop
#              session, not over a plain SSH connection with no X
#              forwarding. Combine with --replay --browser for just this
#              one file, watched, nothing else running first.
#   --cleanup  don't run tests at all - just sweep any stray test-infra
#              processes for THIS checkout (see sweep_stray_processes()
#              below) and exit. A deliberate, explicit action, not run
#              automatically before a normal test run - it kills every
#              matching process by script path, which would also take out
#              a legitimate concurrent use of the same harness (e.g. a
#              scratch script for manual Playwright verification, a
#              different socket path/purpose entirely - found live
#              2026-08-08 running this automatically). Only reach for this
#              to tidy up already-existing old orphans by hand.
set -uo pipefail

bail=0
cleanup_only=0
replay_only=0
no_browser=0
browser_only=0
headed=0
live_only=0
for arg in "$@"; do
    case "$arg" in
        --bail) bail=1 ;;
        --cleanup) cleanup_only=1 ;;
        --replay) replay_only=1 ;;
        --no-browser) no_browser=1 ;;
        --browser) browser_only=1 ;;
        --headed) headed=1 ;;
        --live) live_only=1 ;;
        *)
            echo "Unknown argument: $arg" >&2
            exit 1
            ;;
    esac
done

if [ "$live_only" -eq 1 ] && { [ "$replay_only" -eq 1 ] || [ "$no_browser" -eq 1 ] || [ "$browser_only" -eq 1 ]; }; then
    echo "Contradictory flags: --live selects a different, non-overlapping set of test files than --replay/--no-browser/--browser." >&2
    exit 1
fi

if [ "$no_browser" -eq 1 ] && [ "$browser_only" -eq 1 ]; then
    echo "Contradictory flags: --no-browser and --browser can't both be set." >&2
    exit 1
fi

if [ "$headed" -eq 1 ] && [ "$no_browser" -eq 1 ]; then
    echo "Contradictory flags: --headed opens a browser, --no-browser skips the only test file that uses one." >&2
    exit 1
fi

# Read by tests/lib/cdp.php's cdp_launch() and
# test_session_replay_browser.php directly - every other test file
# ignores it, so exporting it unconditionally for the whole run is
# harmless even outside --replay/--browser.
if [ "$headed" -eq 1 ]; then
    export SESSIONEER_TEST_HEADED=1
fi

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"

# Keep every ordinary test process away from the account's real HOME even if
# a new code path consults HOME directly instead of Config::home_root(). The
# live trust smoke test is the deliberate exception: it receives the original
# home explicitly through SESSIONEER_LIVE_HOME and passes it only to the real
# Claude subprocess it is exercising.
ORIGINAL_HOME="${HOME:-}"
TEST_HOME="$(mktemp -d /tmp/sessioneer-test-home.XXXXXX)" || {
    echo "REFUSING TO RUN: could not create an isolated test HOME." >&2
    exit 1
}
export HOME="$TEST_HOME"
export SESSIONEER_LIVE_HOME="$ORIGINAL_HOME"

# Keyed by SCRIPT_DIR (not a single global path) so this only ever blocks a
# second run of THIS SAME checkout - a different worktree/clone (e.g.
# claude-session-manager-refactor) has its own tests/.env.testing pointing
# at its own isolated fixture paths, so running its suite concurrently with
# this one is genuinely safe, not something to block. Two runs of the SAME
# checkout are not safe: they'd share the exact same TMUX_SOCKET/
# SIDECAR_DIR from one tests/.env.testing, and whichever
# finishes first would tear that state down via its own EXIT trap out from
# under the one still running. -n (non-blocking) fails fast with a clear
# message instead of silently hanging behind a run that might itself be
# stuck.
LOCK_FILE="/tmp/sessioneer-test-run-$(echo -n "$SCRIPT_DIR" | cksum | cut -d' ' -f1).lock"
exec 200>"$LOCK_FILE"
if ! flock -n 200; then
    echo "REFUSING TO RUN: another tests/run.sh for this same checkout ($SCRIPT_DIR) is already in progress (lock: $LOCK_FILE). Wait for it to finish - running two at once would corrupt each other's isolated tmux/sidecar state." >&2
    exit 1
fi

set -a
# shellcheck source=/dev/null
source "$SCRIPT_DIR/.env.testing"
set +a

# Real host values (must match the defaults in host-agent/lib/Sessions.php).
# Cleanup below refuses to touch these no matter what .env.testing says, so
# a typo in .env.testing can never make this script tear down the real
# session.
REAL_UID="$(id -u)"
REAL_TMUX_SOCKET="/tmp/tmux-${REAL_UID}/default"
REAL_SIDECAR_DIR="/run/user/${REAL_UID}/sessioneer-sessions"
REAL_CACHE_DIR="/run/user/${REAL_UID}/sessioneer-cache"

if [ "$TMUX_SOCKET" = "$REAL_TMUX_SOCKET" ] || [ -z "$TMUX_SOCKET" ]; then
    echo "REFUSING TO RUN: TMUX_SOCKET in tests/.env.testing resolves to the real host socket (or is empty). Aborting before touching tmux." >&2
    exit 1
fi

if [ "$SIDECAR_DIR" = "$REAL_SIDECAR_DIR" ] || [ -z "$SIDECAR_DIR" ]; then
    echo "REFUSING TO RUN: SIDECAR_DIR in tests/.env.testing resolves to the real sidecar dir (or is empty). Aborting before deleting anything." >&2
    exit 1
fi

if [ "${CACHE_DIR:-}" = "$REAL_CACHE_DIR" ] || [ -z "${CACHE_DIR:-}" ]; then
    echo "REFUSING TO RUN: CACHE_DIR in tests/.env.testing resolves to the real cache dir (or is empty). Aborting before deleting anything." >&2
    exit 1
fi

# Explicit --cleanup only, deliberately NOT run automatically before every
# normal test run. Found live 2026-08-08, the same night this was added:
# running it unconditionally killed a legitimate, currently-in-use scratch
# verification server (a manual Playwright-verification harness, same
# socket_harness.php script, a DIFFERENT socket path/purpose entirely) as
# an unintended side effect of just running the normal suite - pkill -f
# against the script path matches every instance of it, not just genuinely
# orphaned ones, and the lock above only guards against a second run.sh of
# this checkout, not an unrelated harness use. A real fix for the
# accumulation problem this was meant to help with already exists at the
# right layer: socket_harness.php's own kill_stale_listener() only ever
# kills the ONE specific process actually blocking the exact socket path a
# new instance needs, never anything else - --cleanup here is for
# deliberately tidying up already-existing old orphans by hand, not
# something safe to run as a side effect of unrelated work.
# Scoped to this checkout's own absolute paths throughout, never a bare
# process-name pattern, so it can never reach into an unrelated project.
sweep_stray_processes() {
    pkill -f "$SCRIPT_DIR/lib/socket_harness.php" >/dev/null 2>&1 || true
    pkill -f "$SCRIPT_DIR/fixtures/fake_claude" >/dev/null 2>&1 || true

    if [ -n "${TMUX_SOCKET:-}" ] && [ "$TMUX_SOCKET" != "$REAL_TMUX_SOCKET" ]; then
        tmux -S "$TMUX_SOCKET" kill-server >/dev/null 2>&1 || true
    fi
}

if [ "$cleanup_only" -eq 1 ]; then
    echo "Sweeping stray test-infrastructure processes for this checkout ($SCRIPT_DIR)..."
    sweep_stray_processes

    if [ -n "${SIDECAR_DIR:-}" ] && [ "$SIDECAR_DIR" != "$REAL_SIDECAR_DIR" ]; then
        rm -rf "$SIDECAR_DIR"
    fi

    if [ -n "${CACHE_DIR:-}" ] && [ "$CACHE_DIR" != "$REAL_CACHE_DIR" ]; then
        rm -rf "$CACHE_DIR"
    fi

    rm -rf "$(dirname "$TMUX_SOCKET")"
    rm -rf "$TEST_HOME"
    echo "Done."
    exit 0
fi

cleanup() {
    # Guard repeated here (not just above) so cleanup() is safe to call
    # standalone and never depends on the checks above having run.
    if [ -n "${TMUX_SOCKET:-}" ] && [ "$TMUX_SOCKET" != "$REAL_TMUX_SOCKET" ]; then
        tmux -S "$TMUX_SOCKET" kill-server >/dev/null 2>&1 || true
    fi

    pkill -f "$SCRIPT_DIR/fixtures/fake_claude" >/dev/null 2>&1 || true

    if [ -n "${SIDECAR_DIR:-}" ] && [ "$SIDECAR_DIR" != "$REAL_SIDECAR_DIR" ]; then
        rm -rf "$SIDECAR_DIR"
    fi

    if [ -n "${CACHE_DIR:-}" ] && [ "$CACHE_DIR" != "$REAL_CACHE_DIR" ]; then
        rm -rf "$CACHE_DIR"
    fi

    rm -rf "$(dirname "$TMUX_SOCKET")"
    rm -rf "$TEST_HOME"
}

# A trap that only runs cleanup does NOT stop the script on Ctrl-C - bash
# resumes the for-loop below right after the handler returns. That left a
# real gap: an interrupted run would tear tmux/sidecars down mid-suite via
# cleanup(), then barrel on into the next test file against now-missing
# fixtures. interrupt() must itself terminate the script.
interrupt() {
    cleanup
    trap - EXIT INT TERM
    exit 130
}

trap cleanup EXIT
trap interrupt INT TERM

mkdir -p "$(dirname "$TMUX_SOCKET")" "$SIDECAR_DIR" "$CACHE_DIR"

failures=0

if [ "$live_only" -eq 1 ]; then
    test_files=("$SCRIPT_DIR"/test_*_live.php)
elif [ "$replay_only" -eq 1 ]; then
    test_files=("$SCRIPT_DIR"/test_session_replay*.php)
else
    # *_live.php is excluded from every other selection, always - it spawns
    # a REAL agent binary (see --live's own header comment above), so it
    # only ever runs when explicitly asked for.
    test_files=()
    for test_file in "$SCRIPT_DIR"/test_*.php; do
        case "$(basename "$test_file")" in
            *_live.php) ;; # skipped by default - see --live
            *) test_files+=("$test_file") ;;
        esac
    done
fi

if [ "$no_browser" -eq 1 ]; then
    filtered_files=()
    for test_file in "${test_files[@]}"; do
        case "$(basename "$test_file")" in
            *_browser.php) ;; # skipped - needs a real browser
            *) filtered_files+=("$test_file") ;;
        esac
    done
    test_files=("${filtered_files[@]}")
elif [ "$browser_only" -eq 1 ]; then
    filtered_files=()
    for test_file in "${test_files[@]}"; do
        case "$(basename "$test_file")" in
            *_browser.php) filtered_files+=("$test_file") ;;
            *) ;; # skipped - --browser only keeps files that need one
        esac
    done
    test_files=("${filtered_files[@]}")
fi

for test_file in "${test_files[@]}"; do
    echo "== $(basename "$test_file") =="
    if php "$test_file"; then
        echo "-- $(basename "$test_file"): PASS --"
    else
        echo "-- $(basename "$test_file"): FAIL --"
        failures=$((failures + 1))

        if [ "$bail" -eq 1 ]; then
            echo
            echo "RESULT: stopping after first failure ($(basename "$test_file")) - --bail was set"
            exit 1
        fi
    fi
    echo
done

if [ "$failures" -gt 0 ]; then
    echo "RESULT: $failures test file(s) failed"
    exit 1
fi

echo "RESULT: all tests passed"
exit 0
