<?php
declare(strict_types=1);

/**
 * AuthService::same_origin_or_no_origin(): the Origin/Referer half of the CSRF
 * guard. The scheme is not compared: behind a TLS-terminating proxy PHP only
 * sees http, so the authority (host plus any explicit port) must match exactly.
 */

require __DIR__ . '/lib/assert.php';
require dirname(__DIR__) . '/vendor/autoload.php';

use App\Services\AuthService;

function origin_ok(?string $origin, ?string $referer, string $host): bool
{
    unset($_SERVER['HTTP_ORIGIN'], $_SERVER['HTTP_REFERER']);
    if ($origin !== null) {
        $_SERVER['HTTP_ORIGIN'] = $origin;
    }
    if ($referer !== null) {
        $_SERVER['HTTP_REFERER'] = $referer;
    }
    $_SERVER['HTTP_HOST'] = $host;

    return AuthService::same_origin_or_no_origin();
}

assert_true(origin_ok(null, null, 'sessioneer.lan'), 'no Origin or Referer defers to the CSRF token');
assert_true(origin_ok('http://sessioneer.lan', null, 'sessioneer.lan'), 'same host without a port matches');
assert_true(origin_ok('https://sessioneer.lan', null, 'sessioneer.lan'), 'a proxied https Origin matches the bare Host');
assert_true(origin_ok('http://sessioneer.lan:8091', null, 'sessioneer.lan:8091'), 'same host and port matches');
assert_true(origin_ok(null, 'http://sessioneer.lan/session.php?x=1', 'sessioneer.lan'), 'a Referer with a path matches');

assert_equal(false, origin_ok('http://sessioneer.lan:9999', null, 'sessioneer.lan'), 'an Origin port the Host lacks is rejected');
assert_equal(false, origin_ok('http://sessioneer.lan', null, 'sessioneer.lan:8091'), 'an Origin missing the Host port is rejected');
assert_equal(false, origin_ok('http://sessioneer.lan:9999', null, 'sessioneer.lan:8091'), 'a different port is rejected');
assert_equal(false, origin_ok('http://attacker.example', null, 'sessioneer.lan'), 'a different host is rejected');
assert_equal(false, origin_ok(null, 'http://attacker.example/', 'sessioneer.lan'), 'a foreign Referer is rejected');
assert_equal(false, origin_ok('null', null, 'sessioneer.lan'), 'the opaque "null" Origin is rejected');
assert_equal(false, origin_ok('http://sessioneer.lan', null, ''), 'an empty Host never matches');

test_exit();
