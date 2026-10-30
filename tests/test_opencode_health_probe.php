<?php

declare(strict_types=1);

/**
 * The dashboard's OpenCode health row probes the server it is configured to
 * talk to, not a port written into the source.
 *
 * This exists because the probe's URL was a literal (`localhost:4096`) while
 * the serve unit moved to 8889 with the v2 server: the box reported
 * "opencode-serve.service active but /global/health did not answer" for a
 * perfectly healthy server, and no test noticed because nothing asserted the
 * URL at all. The check's unit-state half still shells out to systemctl and is
 * covered by the real health box; what is pinned here is where it points.
 */

require __DIR__ . '/lib/assert.php';
require dirname(__DIR__) . '/vendor/autoload.php';

use HostAgent\Services\PushHealthService;

// tests/.env.testing pins OPENCODE_SERVE_URL at a dead port so nothing can
// reach a real serve; the probe must follow that, whichever port it is.
$argv = PushHealthService::opencode_health_argv();
$url = (string)end($argv);
assert_true(str_ends_with($url, '/global/health'), 'probe: targets the health endpoint');
assert_equal(rtrim((string)getenv('OPENCODE_SERVE_URL'), '/') . '/global/health', $url, 'probe: follows OPENCODE_SERVE_URL');
assert_true(!str_contains($url, ':4096'), 'probe: no stale hardcoded port');
assert_equal(['curl', '--silent', '--max-time', '3'], array_slice($argv, 0, 4), 'probe: stays a plain, bounded curl with no shell');

// A configured password is carried through, exactly like every other call to
// that server: without it a secured serve answers 401 and the row would read
// as unhealthy again.
putenv('OPENCODE_PASSWORD=test-password');
$authed = PushHealthService::opencode_health_argv();
assert_true(in_array('--user', $authed, true), 'probe: sends Basic auth when a password is configured');
assert_true(in_array('opencode:test-password', $authed, true), 'probe: with the server user and that password');
assert_equal(rtrim((string)getenv('OPENCODE_SERVE_URL'), '/') . '/global/health', (string)end($authed), 'probe: still ends at the configured health URL');
putenv('OPENCODE_PASSWORD');

test_exit();
