#!/usr/bin/env bash
set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
REPO_ROOT="$(dirname "$SCRIPT_DIR")"
UNIT_DIR="$HOME/.config/systemd/user"
RUNTIME_DIR="${XDG_RUNTIME_DIR:-/run/user/$(id -u)}"

# agent.php unconditionally requires vendor/autoload.php (lib/Push.php's
# Web Push dependency) - a missing vendor/ breaks the host agent entirely,
# not just the push feature, so this is no longer optional the way it
# might look from the push feature being itself opt-in.
if [ ! -d "$REPO_ROOT/vendor" ]; then
    echo "Installing Composer dependencies..."
    composer install --no-interaction --working-dir="$REPO_ROOT"
fi

if [ ! -f "$SCRIPT_DIR/.env" ]; then
    cp "$SCRIPT_DIR/.env.example" "$SCRIPT_DIR/.env"
    echo "Created $SCRIPT_DIR/.env from .env.example - edit it (CLAUDE_BIN at"
    echo "least - see below) before this actually works."
fi

# CLAUDE_BIN has no safe default (see Config::claude_bin()) - it's the one
# thing install.sh genuinely can't infer, so check it explicitly rather
# than letting a fresh install silently fail later with a confusing "no
# such file" the first time a session is actually created.
if ! grep -qE '^CLAUDE_BIN=\S' "$SCRIPT_DIR/.env" 2>/dev/null; then
    echo
    echo "WARNING: CLAUDE_BIN is not set in $SCRIPT_DIR/.env - New Session"
    echo "will fail until it is. Run \`which claude\` and set it, e.g.:"
    echo "  CLAUDE_BIN=$(command -v claude 2>/dev/null || echo '/path/to/claude')"
fi

# The systemd unit files are checked in as templates (@REPO_ROOT@/
# @PHP_BIN@/@SOCKET_GROUP@ placeholders, never a literal path baked in) -
# substituted here into the real files systemd actually reads, rather than
# a plain `cp`, so a fresh clone on a different machine (different
# username, different clone location) gets units that actually point at
# ITS OWN paths instead of silently carrying over the original author's.
PHP_BIN="$(command -v php)"
SOCKET_GROUP="$(id -gn)"
HOME_DIR="$HOME"
# opencode-serve.service needs the real `opencode` binary path. Prefer the
# explicitly-configured OPENCODE_BIN from .env (same source Config::
# opencode_bin() reads), falling back to PATH so a fresh clone without a
# .env yet still installs a unit whose ExecStart isn't a literal empty path.
OPENCODE_BIN="$(grep -E '^OPENCODE_BIN=\S' "$SCRIPT_DIR/.env" 2>/dev/null | head -1 | cut -d= -f2- || true)"
if [ -z "$OPENCODE_BIN" ]; then
    OPENCODE_BIN="$(command -v opencode || true)"
fi
CODEX_BIN="$(grep -E '^CODEX_BIN=\S' "$SCRIPT_DIR/.env" 2>/dev/null | head -1 | cut -d= -f2- || true)"
if [ -z "$CODEX_BIN" ]; then
    CODEX_BIN="$(command -v codex || true)"
fi

render_unit() {
    sed \
        -e "s|@REPO_ROOT@|$REPO_ROOT|g" \
        -e "s|@PHP_BIN@|$PHP_BIN|g" \
        -e "s|@SOCKET_GROUP@|$SOCKET_GROUP|g" \
        -e "s|@OPENCODE_BIN@|$OPENCODE_BIN|g" \
        -e "s|@CODEX_BIN@|$CODEX_BIN|g" \
        -e "s|@HOME@|$HOME|g" \
        "$SCRIPT_DIR/systemd/$1" > "$UNIT_DIR/$1"
}

mkdir -p "$UNIT_DIR"
render_unit sessioneer-agent.socket
render_unit sessioneer-agent@.service

systemctl --user daemon-reload
systemctl --user enable --now sessioneer-agent.socket

echo "Installed. Socket should now exist at: $RUNTIME_DIR/sessioneer-agent.sock"
ls -la "$RUNTIME_DIR/sessioneer-agent.sock"

echo
echo "The container's own .env needs APP_GID set to match SocketGroup"
echo "above (\"$SOCKET_GROUP\"): $(getent group "$SOCKET_GROUP" | cut -d: -f3)"

# Push-notification timer: files installed, deliberately NOT enabled/started
# here - it's a no-op until VAPID keys are generated and set in .env anyway
# (see push_configured() in lib/Push.php), and starting a new recurring
# background service is worth a deliberate opt-in rather than happening
# silently on every install.sh run. See the README for the full setup
# (generate keys, set them in .env, then enable the timer yourself).
render_unit sessioneer-push-check.service
cp "$SCRIPT_DIR/systemd/sessioneer-push-check.timer" "$UNIT_DIR/sessioneer-push-check.timer"
systemctl --user daemon-reload

echo
echo "Push-notification timer units installed but NOT enabled - see the"
echo "README's \"Web Push notifications\" section, then run:"
echo "  systemctl --user enable --now sessioneer-push-check.timer"

# Antigravity quota-poll timer: same "installed, not auto-enabled" reasoning
# as the push-check timer above - a no-op until ANTIGRAVITY_BIN is set in
# .env anyway (see antigravity_quota_poll.php), and starting a new
# recurring background service is worth a deliberate opt-in.
render_unit sessioneer-antigravity-quota-check.service
cp "$SCRIPT_DIR/systemd/sessioneer-antigravity-quota-check.timer" "$UNIT_DIR/sessioneer-antigravity-quota-check.timer"
systemctl --user daemon-reload

echo
echo "Antigravity quota-poll timer units installed but NOT enabled - only"
echo "useful if you've set ANTIGRAVITY_BIN in .env (see"
echo "docs/antigravity-adapter-plan.md), then run:"
echo "  systemctl --user enable --now sessioneer-antigravity-quota-check.timer"

# OpenCode headless server: unlike the two timers above, this one IS
# enabled/started here - it's a live long-running daemon with no
# un-configured no-op state (a missing OPENCODE_BIN either fails the
# render or is caught by the health-check box), and the web UI/agent
# reads opencode.db directly regardless, so this server is genuinely part
# of the running setup rather than an opt-in extra. See
# PushHealthService::health_check() for the matching health-box entry.
if [ -n "$OPENCODE_BIN" ]; then
    render_unit opencode-serve.service
    render_unit sessioneer-opencode-events.service
    systemctl --user daemon-reload
    systemctl --user enable --now opencode-serve.service
    systemctl --user enable --now sessioneer-opencode-events.service
    echo
    echo "opencode-serve.service and sessioneer-opencode-events.service installed and enabled."
    systemctl --user is-active opencode-serve.service || true
    systemctl --user is-active sessioneer-opencode-events.service || true

else
    echo
    echo "WARNING: OPENCODE_BIN not set and no 'opencode' on PATH -"
    echo "opencode-serve.service NOT installed. Run \`which opencode\` and"
    echo "set OPENCODE_BIN in host-agent/.env, then re-run install.sh."
fi

# Codex is always headless in Sessioneer. The bridge owns the long-lived
# bidirectional app-server connection needed for approvals and questions;
# no Codex process is spawned into tmux.
if [ -n "$CODEX_BIN" ]; then
    if ! grep -qE '^CODEX_BIN=\S' "$SCRIPT_DIR/.env" 2>/dev/null; then
        printf '\nCODEX_BIN=%s\n' "$CODEX_BIN" >> "$SCRIPT_DIR/.env"
    fi
    render_unit sessioneer-codex-bridge.service
    systemctl --user daemon-reload
    systemctl --user enable --now sessioneer-codex-bridge.service
    echo
    echo "sessioneer-codex-bridge.service installed and enabled (native app-server; no tmux)."
else
    echo
    echo "WARNING: no 'codex' on PATH and CODEX_BIN is unset - Codex sessions are unavailable."
fi

# OpenCode Sessioneer plugin: the authoritative pending-permission signal (see
# host-agent/opencode-plugins/sessioneer-permissions.js). opencode 1.18.21 keeps
# permission state in-memory in the `opencode serve` process and exposes it
# only as a `permission.asked` bus EVENT (the plugin `permission.ask` HOOK is
# dormant, and /permission + the api return empty) - so the plugin subscribes
# to that event and records it to a store the host-agent reads. It must load
# in the SERVE process (not just the TUIs), so it's installed here as a global
# plugin and the serve enable--now below picks it up. opencode-serve.service is
# enabled/restarted earlier in this script.
if [ -n "$OPENCODE_BIN" ]; then
    mkdir -p "${XDG_CONFIG_HOME:-$HOME/.config}/opencode/plugins"
    cp "$SCRIPT_DIR/opencode-plugins/sessioneer-permissions.js" "${XDG_CONFIG_HOME:-$HOME/.config}/opencode/plugins/sessioneer-permissions.js"
    echo
    echo "Sessioneer OpenCode plugin installed to ${XDG_CONFIG_HOME:-$HOME/.config}/opencode/plugins/sessioneer-permissions.js."
    echo "NOTE: opencode-serve.service was (re)started above so the serve loads it; the load is verified by the health-check box."
fi

echo
echo "Lingering must be enabled for this user so the socket survives"
echo "logouts/reboots without an active login session:"
loginctl show-user "$(whoami)" -p Linger 2>/dev/null || true
echo "If that shows Linger=no, run: sudo loginctl enable-linger $(whoami)"
