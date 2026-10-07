<?php

declare(strict_types=1);

namespace App\Services;

/**
 * Shared by every entry point under src/ (index.php, quota.php, ...).
 * There is no login: access control is the network binding
 * (BIND_ADDR/APP_PORT to a LAN-only interface - see README). These
 * CSRF guards just block a stray cross-site form post from a browser
 * that can reach the app.
 */
class AuthService
{
    private const SESSION_LIFETIME_SECONDS = 60 * 60 * 24 * 30;

    private const ALWAYS_ALLOWED_HOSTS = ['localhost', '127.0.0.1', '::1'];

    /* ---------- Host allowlist ---------- */
    /* same_origin_or_no_origin() compares Origin with Host, and both come from
       the browser: a DNS-rebinding page (attacker.example re-resolved to this
       app's IP) is same-origin with itself and would pass. Checking Host
       against names the operator chose closes that. */

    /**
     * @param string $configured comma-separated extra hostnames (ALLOWED_HOSTS)
     */
    public static function host_allowed(?string $hostHeader, string $configured, string $bindAddr): bool
    {
        if ($hostHeader === null || $hostHeader === '') {
            return false;
        }

        $host = strtolower(trim($hostHeader));
        $host = str_starts_with($host, '[')
            ? substr($host, 1, (int)strpos($host, ']') - 1)
            : (string)preg_replace('/:\d+$/', '', $host);

        $allowed = array_merge(self::ALWAYS_ALLOWED_HOSTS, [$bindAddr], explode(',', $configured));
        $allowed = array_map(static fn(string $h): string => strtolower(trim($h)), $allowed);

        return in_array($host, array_filter($allowed), true);
    }

    public static function request_host_allowed(): bool
    {
        return self::host_allowed(
            $_SERVER['HTTP_HOST'] ?? null,
            (string)getenv('SESSIONEER_ALLOWED_HOSTS'),
            (string)getenv('SESSIONEER_BIND_ADDR'),
        );
    }

    /* ---------- CSRF guards ---------- */
    /* Two independent layers, both required on every state-changing POST:
       same_origin_or_no_origin() (a same-origin check, no token involved) plus
       the session-bound token pair below. */

    public static function same_origin_or_no_origin(): bool
    {
        $source = $_SERVER['HTTP_ORIGIN'] ?? $_SERVER['HTTP_REFERER'] ?? null;

        if ($source === null) {
            return true;
        }

        $sourceHost = parse_url($source, PHP_URL_HOST);
        $sourcePort = parse_url($source, PHP_URL_PORT);
        $sourceAuthority = $sourcePort !== null ? "{$sourceHost}:{$sourcePort}" : $sourceHost;

        $host = $_SERVER['HTTP_HOST'] ?? null;

        return $sourceAuthority === $host || $sourceHost === $host;
    }

    /**
     * Starts (or resumes) the PHP session used for the CSRF token and flash
     * messages - session_start() itself is idempotent-safe to call more than
     * once (a second call is a silent no-op), but callers should still only
     * need to call this once per request, near the top before any output.
     *
     * Uses the 'private_no_expire' cache limiter instead of PHP's default
     * 'nocache' - the default sends Cache-Control: no-store plus an
     * already-past Expires date, which webview-based embeds (e.g. Home
     * Assistant's iframe "Webpage" card, backed by WKWebView on iOS) can't
     * place in their back-forward cache; resuming/reloading such a page then
     * surfaces as an expired-page error instead of a normal refetch.
     * private_no_expire still keeps the response out of shared/public caches
     * (correct, since it carries a CSRF token and flash state) without that
     * combination.
     *
     * session_cache_expire(1) pins the resulting max-age to 1 minute, not
     * PHP's 180-minute default - found live that the default let a browser
     * serve a 3-hour-stale copy of session.php (old HTML/JS) on a plain
     * navigation after a code change, no reload needed to trigger it. A short
     * max-age keeps normal navigations fresh while still avoiding no-store,
     * so the bfcache fix above still holds.
     *
     * Also overrides PHP's default 24-minute session.gc_maxlifetime (and
     * matches the cookie's own lifetime to it, via SESSION_LIFETIME_SECONDS)
     * - found live: a tab left open past that (e.g. backgrounded on a
     * phone, polling paused) still holds the CSRF token from its original
     * page load, but the server-side session backing it had already been
     * garbage-collected, so the next POST 403'd with "invalid or missing
     * CSRF token" for no reason visible to Andres. As this class's own doc
     * comment notes, there's no login here - the real access boundary is
     * the LAN-only network binding, and CSRF just blocks a stray cross-site
     * POST - so there's no security reason for a short-lived session.
     */
    public static function start_app_session(): void
    {
        if (session_status() !== PHP_SESSION_ACTIVE) {
            session_cache_expire(1);
            session_cache_limiter('private_no_expire');
            ini_set('session.gc_maxlifetime', (string)self::SESSION_LIFETIME_SECONDS);
            ini_set('session.cookie_lifetime', (string)self::SESSION_LIFETIME_SECONDS);
            session_start();
        }
    }

    /**
     * Flushes session changes and releases PHP's per-session file lock.
     * Long-running host-agent reads must not serialize unrelated polling,
     * navigation, or quota requests from the same browser tab.
     */
    public static function close_app_session(): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_write_close();
        }
    }

    /**
     * Returns this session's CSRF token, generating and stashing one on first
     * use. Call start_app_session() first.
     */
    public static function csrf_token(): string
    {
        if (!isset($_SESSION['csrf_token']) || !is_string($_SESSION['csrf_token'])) {
            $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
        }

        return $_SESSION['csrf_token'];
    }

    /**
     * Rejects the request with 403 unless $_POST['csrf_token'] matches this
     * session's token. Call start_app_session() first.
     */
    public static function require_csrf(): void
    {
        $provided = (string)($_POST['csrf_token'] ?? '');
        $expected = (string)($_SESSION['csrf_token'] ?? '');

        if ($expected === '' || !hash_equals($expected, $provided)) {
            http_response_code(403);
            echo "Rejected: missing or invalid CSRF token.";
            exit;
        }
    }
}
