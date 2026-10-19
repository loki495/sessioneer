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

        return $sourceAuthority === $host;
    }

    /**
     * Starts (or resumes) the PHP session used for the CSRF token and flash
     * messages. Safe to call more than once; a second call is a no-op.
     *
     * 'private_no_expire' instead of PHP's default 'nocache': no-store plus a
     * past Expires date can't be placed in the back-forward cache by webview
     * embeds (WKWebView in an iframe card), so resuming such a page shows an
     * expired-page error. It still keeps the response out of shared caches,
     * which matters because it carries the CSRF token and flash state.
     * session_cache_expire(1) caps max-age at 1 minute so a plain navigation
     * never serves a stale session.php from PHP's 180-minute default.
     *
     * The lifetime is raised above PHP's 24-minute gc_maxlifetime and the
     * cookie matched to it: a long-open tab keeps its CSRF token, and a
     * garbage-collected session would reject its next POST. There is no login
     * here (the LAN binding is the access boundary), so nothing is gained from
     * a short-lived session.
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
