<?php
declare(strict_types=1);

/**
 * The old advise stack is gone and the CLI says so. Written from the Contract only. Intent notes:
 *  - `--advise` and `--act` are rejected with exit 1 and a message naming the option; nothing reaches the host
 *    (without this a stray option would be ignored by getopt and start a launch);
 *  - --help lists --next, --rotate, --offer and their modifiers and no longer mentions --advise or --act;
 *  - deleting the advise classes, UiView::advise_visible and the host advise action is step 7 (coupled to the web UI).
 */

require __DIR__ . '/lib/cli_flow.php';

$rig = new CliFlowRig('cli-flow-removed');

try {
    foreach ([['--advise'], ['--advise', 'cc-x'], ['--advise', '--act', 'cc-x'], ['--act', 'cc-x'], ['--advise', '--json']] as $args) {
        $rig->reset();
        [$code, $out, $err] = $rig->run($args);
        $label = implode(' ', $args);
        assert_equal(1, $code, "$label: exit 1");
        assert_contains(str_starts_with($label, '--advise') ? '--advise' : '--act', $err, "$label: the message names the removed option");
        assert_equal('', $out, "$label: nothing on stdout");
        assert_equal([], $rig->requests(), "$label: no host call, so no launch");
    }

    [$code, $out] = $rig->run(['--help']);
    assert_equal(0, $code, '--help: exit 0');
    foreach (['--next', '--rotate', '--offer', '--intent', '--post', '--all', '--dry-run', '--clear', '--handoff', '--plan', 'step-end'] as $needle) {
        assert_contains($needle, $out, "--help: lists $needle");
    }
    assert_true(preg_match('/--advise\b/', $out) !== 1, '--help: no longer mentions --advise');
    assert_true(preg_match('/--act\b/', $out) !== 1, '--help: no longer mentions --act');
    assert_true(stripos($out, 'advise') === false, '--help: no advise wording left');
} finally {
    $rig->stop();
}

echo "OK\n";
test_exit();
