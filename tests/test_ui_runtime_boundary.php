<?php

declare(strict_types=1);

require __DIR__ . '/lib/assert.php';

$loader = require __DIR__ . '/../vendor/autoload.php';
// The deployed web container mounts src/ and vendor/, but not host-agent/.
$loader->setPsr4('HostAgent\\', []);

use App\Views\UiView;

assert_true(!class_exists(HostAgent\SessionFlow\FlowService::class), 'web runtime has no host-agent flow service');

// The band renders from the overlay stored on the row: no host-agent class, no quota reader, no model turn.
$session = ['name' => 'claude-headless-test', 'agent' => 'claude', 'runtime' => 'headless', 'status' => 'idle', 'activity' => time() - 7200];
$flow = ['state' => 'ROTATE', 'why' => 'cache is cold', 'trigger' => 'cold_cache', 'close_call' => false, 'receiver' => null, 'reset_at' => null, 'needs_approval' => false, 'warnings' => []];
$html = UiView::sessions_html([$session + ['flow' => $flow]], 'test-token');
assert_contains('data-flow-host', $html, 'cold session card with an overlay renders the band without host-agent classes');
assert_true(!str_contains(UiView::sessions_html([$session], 'test-token'), 'data-flow-host'), 'a row without an overlay renders a plain card');
assert_true(!method_exists(UiView::class, 'advise_visible'), 'UiView no longer decides advice visibility');
assert_true(!class_exists('App\\AdvisePolicy'), 'the web runtime no longer ships AdvisePolicy');

test_exit();
