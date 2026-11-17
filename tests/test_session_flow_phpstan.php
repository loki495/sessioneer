<?php

declare(strict_types=1);

require __DIR__ . '/lib/assert.php';
$config = file_get_contents(dirname(__DIR__) . '/phpstan.neon');
assert_true(is_string($config) && (bool) preg_match('/^\s*-\s*bin\/sessioneer\s*$/m', $config), 'PHPStan analyses bin/sessioneer');
assert_true(is_string($config) && (bool) preg_match('/^\s*level:\s*6\s*$/m', $config), 'PHPStan retains level 6');
test_exit();
