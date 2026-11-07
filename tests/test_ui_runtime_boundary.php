<?php

declare(strict_types=1);

require __DIR__ . '/lib/assert.php';

$loader = require __DIR__ . '/../vendor/autoload.php';
// The deployed web container mounts src/ and vendor/, but not host-agent/.
$loader->setPsr4('HostAgent\\', []);

use App\Views\UiView;

assert_true(!class_exists(HostAgent\Services\AdviseService::class), 'web runtime has no host-agent service');

$session = ['name' => 'claude-headless-test', 'agent' => 'claude', 'runtime' => 'headless', 'status' => 'idle', 'activity' => time() - 7200];
$html = UiView::sessions_html([$session], 'test-token');
assert_contains('data-advise-open', $html, 'cold session card renders without host-agent classes');

$session['activity'] = time();
$failedQuota = static fn (): array => ['ok' => false];
assert_true(!UiView::advise_visible($session, $failedQuota), 'unavailable quota leaves fresh session advice hidden');
assert_true(!str_contains(UiView::sessions_html([$session], 'test-token', $failedQuota), 'data-advise-open'), 'quota failure still renders a fresh session card');
assert_true(UiView::advise_visible($session, static fn (): array => ['claude_profiles' => ['personal' => ['quota' => ['session' => ['pct' => 90]]]]]), 'quota threshold works without host-agent classes');

test_exit();
