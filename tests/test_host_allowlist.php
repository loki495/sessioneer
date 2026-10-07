<?php
declare(strict_types=1);

/**
 * AuthService::host_allowed(), the Host-header allowlist public/index.php
 * checks before routing (the DNS-rebinding guard). The end-to-end 421 is
 * covered in test_ui_smoke.php.
 */

require __DIR__ . '/lib/assert.php';
require dirname(__DIR__) . '/vendor/autoload.php';

use App\Services\AuthService;

// --- always allowed, with and without a port ---
assert_true(AuthService::host_allowed('localhost:8091', '', '127.0.0.1'), 'localhost with port is allowed');
assert_true(AuthService::host_allowed('127.0.0.1', '', '127.0.0.1'), '127.0.0.1 without port is allowed');
assert_true(AuthService::host_allowed('[::1]:8091', '', '127.0.0.1'), 'bracketed IPv6 loopback with port is allowed');
assert_true(AuthService::host_allowed('LOCALHOST', '', '127.0.0.1'), 'hostnames compare case-insensitively');

// --- BIND_ADDR and ALLOWED_HOSTS ---
assert_true(AuthService::host_allowed('192.168.1.50:8091', '', '192.168.1.50'), 'the configured bind address is allowed');
assert_true(AuthService::host_allowed('sessioneer.example.lan', ' other.lan , Sessioneer.Example.Lan ', '127.0.0.1'), 'an ALLOWED_HOSTS entry is allowed, ignoring spaces and case');

// --- rejected ---
assert_equal(false, AuthService::host_allowed('attacker.example', '', '127.0.0.1'), 'an unlisted hostname (DNS rebinding) is rejected');
assert_equal(false, AuthService::host_allowed('attacker.example:8091', 'sessioneer.example.lan', '127.0.0.1'), 'an unlisted hostname is rejected when ALLOWED_HOSTS is set');
assert_equal(false, AuthService::host_allowed('192.168.1.51', '', '192.168.1.50'), 'a different LAN IP than BIND_ADDR is rejected');
assert_equal(false, AuthService::host_allowed('localhost.attacker.example', '', '127.0.0.1'), 'a hostname merely starting with an allowed one is rejected');
assert_equal(false, AuthService::host_allowed(null, '', '127.0.0.1'), 'a missing Host header is rejected');
assert_equal(false, AuthService::host_allowed('', ',,', ''), 'an empty Host is rejected, and empty list entries never match it');
assert_equal(false, AuthService::host_allowed('[::1', '', '127.0.0.1'), 'a malformed bracketed host is rejected');

test_exit();
