<?php
declare(strict_types=1);

/**
 * Exercises HookService::check_session_hook()/HookService::install_session_hook() (the
 * ~/.claude/settings.json read-modify-write logic covering all five hooks
 * this app installs) and the actual host-agent/hooks/session_start.php,
 * pre_tool_use.php, permission_request.php, user_prompt_submit.php, and
 * stop.php scripts Claude Code invokes - both against isolated fixture
 * paths, never the real ~/.claude/settings.json or the real sidecar dir.
 * Uses its own temp HOME_ROOT/SIDECAR_DIR (overridden via putenv(), not
 * tests/.env.testing's shared ones) so a stray settings.json can never end
 * up committed under tests/fixtures.
 */

require __DIR__ . '/lib/assert.php';
require dirname(__DIR__) . '/host-agent/lib/Sessions.php';

use HostAgent\Services\Config;
use HostAgent\Services\HookService;
use HostAgent\Services\PermissionMode;
use HostAgent\Services\PromptParser;
use HostAgent\Services\TmuxService;
use HostAgent\Stores\PendingToolStore;
use HostAgent\Stores\SessionStatusStore;
use HostAgent\Stores\SidecarStore;

// Captured before any override below, so this always reflects whatever
// machine/CI environment the suite is actually running on - never hardcode
// a specific real home directory here.
$realHomeRoot = Config::home_root();

$fixtureHome = sys_get_temp_dir() . '/sessioneer-test-hook-home-' . bin2hex(random_bytes(4));
$fixtureSidecarDir = sys_get_temp_dir() . '/sessioneer-test-hook-sidecars-' . bin2hex(random_bytes(4));

putenv("HOME_ROOT={$fixtureHome}");
putenv("SIDECAR_DIR={$fixtureSidecarDir}");

if (Config::home_root() === $realHomeRoot) {
    fwrite(STDERR, "REFUSING TO RUN: HOME_ROOT still resolves to the real home directory.\n");
    exit(1);
}

mkdir($fixtureSidecarDir, 0700, true);

$settingsPath = Config::claude_settings_path();

try {
    // --- HookService::check_session_hook() / HookService::install_session_hook(): fresh machine, no settings.json yet ---

    $check = HookService::check_session_hook();
    assert_equal(true, $check['ok'], 'check_session_hook: ok on a missing settings.json');
    assert_equal(false, $check['installed'], 'check_session_hook: not installed when settings.json does not exist yet');

    $install = HookService::install_session_hook();
    assert_equal(true, $install['ok'], 'install_session_hook: succeeds on a missing settings.json');
    assert_equal(true, $install['installed'], 'install_session_hook: reports installed after creating the file');
    assert_true(is_file($settingsPath), 'install_session_hook: creates ~/.claude/settings.json');

    $decoded = json_decode((string)file_get_contents($settingsPath), true);
    assert_equal(
        Config::session_start_hook_command(),
        $decoded['hooks']['SessionStart'][0]['hooks'][0]['command'] ?? null,
        'install_session_hook: written file has our SessionStart hook command'
    );
    assert_equal('*', $decoded['hooks']['SessionStart'][0]['matcher'] ?? null, 'install_session_hook: matcher fires on every session-start source');
    assert_equal(
        Config::pre_tool_use_hook_command(),
        $decoded['hooks']['PreToolUse'][0]['hooks'][0]['command'] ?? null,
        'install_session_hook: written file has our PreToolUse hook command'
    );
    assert_equal('*', $decoded['hooks']['PreToolUse'][0]['matcher'] ?? null, 'install_session_hook: PreToolUse matcher fires on every tool');
    assert_equal(
        Config::permission_request_hook_command(),
        $decoded['hooks']['PermissionRequest'][0]['hooks'][0]['command'] ?? null,
        'install_session_hook: written file has our PermissionRequest hook command'
    );
    assert_equal(
        Config::user_prompt_submit_hook_command(),
        $decoded['hooks']['UserPromptSubmit'][0]['hooks'][0]['command'] ?? null,
        'install_session_hook: written file has our UserPromptSubmit hook command'
    );
    assert_equal(
        Config::stop_hook_command(),
        $decoded['hooks']['Stop'][0]['hooks'][0]['command'] ?? null,
        'install_session_hook: written file has our Stop hook command'
    );

    assert_equal(true, HookService::check_session_hook()['installed'], 'check_session_hook: installed after HookService::install_session_hook()');

    // --- idempotency: installing again must not duplicate any entry ---

    HookService::install_session_hook();
    $decoded = json_decode((string)file_get_contents($settingsPath), true);
    assert_equal(1, count($decoded['hooks']['SessionStart']), 'install_session_hook: calling twice does not duplicate the SessionStart entry');
    assert_equal(1, count($decoded['hooks']['PreToolUse']), 'install_session_hook: calling twice does not duplicate the PreToolUse entry');
    assert_equal(1, count($decoded['hooks']['PermissionRequest']), 'install_session_hook: calling twice does not duplicate the PermissionRequest entry');
    assert_equal(1, count($decoded['hooks']['UserPromptSubmit']), 'install_session_hook: calling twice does not duplicate the UserPromptSubmit entry');
    assert_equal(1, count($decoded['hooks']['Stop']), 'install_session_hook: calling twice does not duplicate the Stop entry');

    // --- partial install (only one of the two hooks present) is topped up, not left alone ---

    $onlySessionStart = [
        'hooks' => [
            'SessionStart' => [['matcher' => '*', 'hooks' => [['type' => 'command', 'command' => Config::session_start_hook_command()]]]],
        ],
    ];
    file_put_contents($settingsPath, json_encode($onlySessionStart, JSON_PRETTY_PRINT));

    $partialCheck = HookService::check_session_hook();
    assert_equal(false, $partialCheck['installed'], 'check_session_hook: installed=false when only one of the five hooks is present');

    HookService::install_session_hook();
    $decoded = json_decode((string)file_get_contents($settingsPath), true);
    assert_equal(1, count($decoded['hooks']['SessionStart']), 'install_session_hook: topping up the rest does not duplicate the existing SessionStart entry');
    assert_equal(
        Config::pre_tool_use_hook_command(),
        $decoded['hooks']['PreToolUse'][0]['hooks'][0]['command'] ?? null,
        'install_session_hook: adds the missing PreToolUse entry when SessionStart was already present'
    );
    assert_equal(
        Config::permission_request_hook_command(),
        $decoded['hooks']['PermissionRequest'][0]['hooks'][0]['command'] ?? null,
        'install_session_hook: adds the missing PermissionRequest entry too'
    );
    assert_equal(
        Config::user_prompt_submit_hook_command(),
        $decoded['hooks']['UserPromptSubmit'][0]['hooks'][0]['command'] ?? null,
        'install_session_hook: adds the missing UserPromptSubmit entry too'
    );
    assert_equal(
        Config::stop_hook_command(),
        $decoded['hooks']['Stop'][0]['hooks'][0]['command'] ?? null,
        'install_session_hook: adds the missing Stop entry too'
    );

    // --- merge safety: an existing file's unrelated hooks/settings survive untouched ---

    $preexisting = [
        'hooks' => [
            'Stop' => [['matcher' => '*', 'hooks' => [['type' => 'command', 'command' => 'notify-send done']]]],
        ],
        'theme' => 'dark',
    ];
    file_put_contents($settingsPath, json_encode($preexisting, JSON_PRETTY_PRINT));

    HookService::install_session_hook();
    $decoded = json_decode((string)file_get_contents($settingsPath), true);
    assert_equal('notify-send done', $decoded['hooks']['Stop'][0]['hooks'][0]['command'] ?? null, 'install_session_hook: preserves a pre-existing unrelated hook');
    assert_equal('dark', $decoded['theme'] ?? null, 'install_session_hook: preserves pre-existing top-level settings');
    // Regression coverage for the hooks-status-file design's own load-bearing
    // assumption: Andres's own personal Stop hook (already registered for
    // this exact event above) must survive as its own matcher-group entry,
    // with ours appended as a SECOND entry under the same event, not merged
    // into or replacing his.
    assert_equal(2, count($decoded['hooks']['Stop']), 'install_session_hook: appends our own Stop entry alongside an existing unrelated one, rather than replacing it');
    assert_equal(
        Config::stop_hook_command(),
        $decoded['hooks']['Stop'][1]['hooks'][0]['command'] ?? null,
        'install_session_hook: our Stop hook command is the second entry, coexisting with the pre-existing one'
    );
    assert_equal(
        Config::session_start_hook_command(),
        $decoded['hooks']['SessionStart'][0]['hooks'][0]['command'] ?? null,
        'install_session_hook: still adds the SessionStart entry alongside pre-existing hooks'
    );
    assert_equal(
        Config::pre_tool_use_hook_command(),
        $decoded['hooks']['PreToolUse'][0]['hooks'][0]['command'] ?? null,
        'install_session_hook: still adds the PreToolUse entry alongside pre-existing hooks'
    );

    // --- HookService::reindent_json_pretty(): PHP's 4-space JSON_PRETTY_PRINT output is halved to 2-space ---

    $rawWritten = (string)file_get_contents($settingsPath);
    assert_true(str_contains($rawWritten, "\n  \"hooks\""), 'install_session_hook: writes 2-space indent, not PHP default 4-space');

    // --- malformed existing file: refuses to overwrite, never resets to empty ---

    file_put_contents($settingsPath, '{not valid json');

    $checkMalformed = HookService::check_session_hook();
    assert_equal(false, $checkMalformed['ok'], 'check_session_hook: ok=false on a malformed settings.json');
    assert_equal(false, $checkMalformed['installed'], 'check_session_hook: installed=false on a malformed settings.json');

    $installMalformed = HookService::install_session_hook();
    assert_equal(false, $installMalformed['ok'], 'install_session_hook: refuses to touch a malformed settings.json');
    assert_equal('{not valid json', file_get_contents($settingsPath), 'install_session_hook: leaves a malformed settings.json byte-for-byte untouched');

    unlink($settingsPath);

    // --- HookService::session_start_hook_present()/HookService::pre_tool_use_hook_present(): key off the exact command string, not just hook presence ---

    assert_equal(false, HookService::session_start_hook_present(['hooks' => ['SessionStart' => [['matcher' => '*', 'hooks' => [['type' => 'command', 'command' => 'something-unrelated.sh']]]]]]), 'session_start_hook_present: false for an unrelated SessionStart hook');
    assert_equal(true, HookService::session_start_hook_present(['hooks' => ['SessionStart' => [['matcher' => 'clear', 'hooks' => [['type' => 'command', 'command' => Config::session_start_hook_command()]]]]]]), 'session_start_hook_present: true when our command is present under any matcher');
    assert_equal(false, HookService::pre_tool_use_hook_present(['hooks' => ['PreToolUse' => [['matcher' => '*', 'hooks' => [['type' => 'command', 'command' => 'something-unrelated.sh']]]]]]), 'pre_tool_use_hook_present: false for an unrelated PreToolUse hook');
    assert_equal(true, HookService::pre_tool_use_hook_present(['hooks' => ['PreToolUse' => [['matcher' => 'Bash', 'hooks' => [['type' => 'command', 'command' => Config::pre_tool_use_hook_command()]]]]]]), 'pre_tool_use_hook_present: true when our command is present under any matcher');
    assert_equal(false, HookService::permission_request_hook_present(['hooks' => ['PermissionRequest' => [['matcher' => '*', 'hooks' => [['type' => 'command', 'command' => 'something-unrelated.sh']]]]]]), 'permission_request_hook_present: false for an unrelated PermissionRequest hook');
    assert_equal(true, HookService::permission_request_hook_present(['hooks' => ['PermissionRequest' => [['matcher' => '*', 'hooks' => [['type' => 'command', 'command' => Config::permission_request_hook_command()]]]]]]), 'permission_request_hook_present: true when our command is present');
    assert_equal(false, HookService::user_prompt_submit_hook_present(['hooks' => ['UserPromptSubmit' => [['matcher' => '*', 'hooks' => [['type' => 'command', 'command' => 'something-unrelated.sh']]]]]]), 'user_prompt_submit_hook_present: false for an unrelated UserPromptSubmit hook');
    assert_equal(true, HookService::user_prompt_submit_hook_present(['hooks' => ['UserPromptSubmit' => [['matcher' => '*', 'hooks' => [['type' => 'command', 'command' => Config::user_prompt_submit_hook_command()]]]]]]), 'user_prompt_submit_hook_present: true when our command is present');
    assert_equal(false, HookService::stop_hook_present(['hooks' => ['Stop' => [['matcher' => '*', 'hooks' => [['type' => 'command', 'command' => 'notify-send done']]]]]]), 'stop_hook_present: false when only an unrelated Stop hook (e.g. Andres\'s own) is present');
    assert_equal(true, HookService::stop_hook_present(['hooks' => ['Stop' => [['matcher' => '*', 'hooks' => [['type' => 'command', 'command' => 'notify-send done']]], ['matcher' => '*', 'hooks' => [['type' => 'command', 'command' => Config::stop_hook_command()]]]]]]), 'stop_hook_present: true when our command is present alongside an unrelated one');

    // --- PromptParser::format_pending_tool_input(): full-text preview per tool shape ---

    assert_equal(
        "npm test",
        PromptParser::format_pending_tool_input('Bash', ['command' => 'npm test']),
        'format_pending_tool_input: Bash with no description is just the command'
    );
    assert_equal(
        "Run tests\n\nnpm test",
        PromptParser::format_pending_tool_input('Bash', ['command' => 'npm test', 'description' => 'Run tests']),
        'format_pending_tool_input: Bash description is prepended when present'
    );
    assert_equal(null, PromptParser::format_pending_tool_input('Bash', []), 'format_pending_tool_input: Bash with no command returns null');
    assert_equal(
        "Write /tmp/foo.txt\n\nline1\nline2",
        PromptParser::format_pending_tool_input('Write', ['file_path' => '/tmp/foo.txt', 'content' => "line1\nline2"]),
        'format_pending_tool_input: Write shows the full file content, not truncated'
    );
    assert_equal(null, PromptParser::format_pending_tool_input('Write', ['file_path' => '/tmp/foo.txt']), 'format_pending_tool_input: Write with no content returns null');
    assert_equal(
        "Edit /tmp/foo.txt\n\n--- old ---\nfoo\n\n--- new ---\nbar",
        PromptParser::format_pending_tool_input('Edit', ['file_path' => '/tmp/foo.txt', 'old_string' => 'foo', 'new_string' => 'bar']),
        'format_pending_tool_input: Edit shows old/new'
    );
    assert_equal(null, PromptParser::format_pending_tool_input('Edit', []), 'format_pending_tool_input: Edit with no file_path returns null');
    assert_true(
        str_starts_with(PromptParser::format_pending_tool_input('WebFetch', ['url' => 'https://example.com']) ?? '', "WebFetch\n\n"),
        'format_pending_tool_input: unrecognized tool falls back to a labeled JSON dump'
    );

    // --- PermissionMode::normalize_hook_permission_mode(): raw hook permission_mode -> this app's own manual/accept edits/plan/auto vocabulary ---

    assert_equal('manual', PermissionMode::normalize_hook_permission_mode('default'), 'normalize_hook_permission_mode: "default" (Claude Code\'s own enum name) maps to this app\'s "manual"');
    assert_equal('accept edits', PermissionMode::normalize_hook_permission_mode('acceptEdits'), 'normalize_hook_permission_mode: "acceptEdits" maps to "accept edits" (with a space, matching TranscriptView::MODE_OPTIONS)');
    assert_equal('plan', PermissionMode::normalize_hook_permission_mode('plan'), 'normalize_hook_permission_mode: "plan" passes through unchanged');
    assert_equal('auto', PermissionMode::normalize_hook_permission_mode('auto'), 'normalize_hook_permission_mode: "auto" passes through unchanged');
    assert_equal(null, PermissionMode::normalize_hook_permission_mode('bypassPermissions'), 'normalize_hook_permission_mode: an unrecognized enum value returns null rather than guessing');
    assert_equal(null, PermissionMode::normalize_hook_permission_mode(null), 'normalize_hook_permission_mode: null input returns null');

    // --- PromptParser::permission_suggestion_option_label()/build_options_from_permission_suggestions(): the exact option text Claude Code renders per permission_suggestion, confirmed against real captures (see the todo file's research entry) ---

    assert_equal(
        'Yes, and switch to accept edits (auto-approve file edits and common file commands) for this session (shift+tab)',
        PromptParser::permission_suggestion_option_label(['type' => 'setMode', 'mode' => 'acceptEdits']),
        'permission_suggestion_option_label: setMode:acceptEdits renders the fixed accept-edits phrasing'
    );
    assert_equal(null, PromptParser::permission_suggestion_option_label(['type' => 'setMode', 'mode' => 'plan']), 'permission_suggestion_option_label: setMode to any mode other than acceptEdits has no known phrasing (never observed live)');
    assert_equal(
        'Yes, and always allow access to /tmp from this project',
        PromptParser::permission_suggestion_option_label(['type' => 'addDirectories', 'directories' => ['/tmp']]),
        'permission_suggestion_option_label: addDirectories renders the real directory path'
    );
    assert_equal(null, PromptParser::permission_suggestion_option_label(['type' => 'addDirectories', 'directories' => []]), 'permission_suggestion_option_label: addDirectories with no directories returns null');
    assert_equal(
        "Yes, and don't ask again for: rtk curl *",
        PromptParser::permission_suggestion_option_label(['type' => 'addRules', 'rules' => [['toolName' => 'Bash', 'ruleContent' => 'rtk curl *']]]),
        'permission_suggestion_option_label: addRules renders ruleContent verbatim, confirmed live'
    );
    assert_equal(null, PromptParser::permission_suggestion_option_label(['type' => 'somethingNew']), 'permission_suggestion_option_label: an unrecognized suggestion type returns null rather than guessing');

    // Real Bash capture: addDirectories + setMode both present -> only the more specific addDirectories gets its own option, setMode does not.
    assert_equal(
        [['number' => 1, 'label' => 'Yes'], ['number' => 2, 'label' => 'Yes, and always allow access to /tmp from this project'], ['number' => 3, 'label' => 'No']],
        PromptParser::build_options_from_permission_suggestions([
            ['type' => 'addDirectories', 'directories' => ['/tmp'], 'destination' => 'session'],
            ['type' => 'setMode', 'mode' => 'acceptEdits', 'destination' => 'session'],
        ]),
        'build_options_from_permission_suggestions: addDirectories wins over setMode when both are present in one payload (real Bash capture)'
    );
    // Real Write capture: only setMode present -> it DOES get its own option this time.
    assert_equal(
        [['number' => 1, 'label' => 'Yes'], ['number' => 2, 'label' => 'Yes, and switch to accept edits (auto-approve file edits and common file commands) for this session (shift+tab)'], ['number' => 3, 'label' => 'No']],
        PromptParser::build_options_from_permission_suggestions([['type' => 'setMode', 'mode' => 'acceptEdits', 'destination' => 'session']]),
        'build_options_from_permission_suggestions: setMode gets its own option when nothing more specific is offered (real Write capture)'
    );
    assert_equal(
        [['number' => 1, 'label' => 'Yes'], ['number' => 2, 'label' => 'No']],
        PromptParser::build_options_from_permission_suggestions([]),
        'build_options_from_permission_suggestions: no suggestions at all -> just Yes/No (e.g. an AskUserQuestion payload, which carries none)'
    );
    assert_equal(
        [['number' => 1, 'label' => 'Yes'], ['number' => 2, 'label' => 'No']],
        PromptParser::build_options_from_permission_suggestions([['type' => 'somethingUnrecognized']]),
        'build_options_from_permission_suggestions: an unrecognized suggestion type with no known label falls back to plain Yes/No'
    );

    // --- PromptParser::build_prompt_from_hook_status(): the PermissionRequest-hook-fed equivalent of parse_blocking_prompt(), built with no pane content at all ---

    assert_equal(null, PromptParser::build_prompt_from_hook_status(null), 'build_prompt_from_hook_status: null blocked -> null');
    assert_equal(null, PromptParser::build_prompt_from_hook_status(['tool_name' => 'Bash']), 'build_prompt_from_hook_status: missing tool_input -> null');
    assert_equal(null, PromptParser::build_prompt_from_hook_status(['tool_input' => ['command' => 'ls']]), 'build_prompt_from_hook_status: missing tool_name -> null');

    $hookPrompt = PromptParser::build_prompt_from_hook_status([
        'tool_name' => 'Bash',
        'tool_input' => ['command' => 'rtk curl example.com', 'description' => 'Fetch example.com'],
        'permission_suggestions' => [['type' => 'addRules', 'rules' => [['toolName' => 'Bash', 'ruleContent' => 'rtk curl *']], 'behavior' => 'allow', 'destination' => 'localSettings']],
    ]);
    assert_equal('Do you want to proceed?', $hookPrompt['question'] ?? null, 'build_prompt_from_hook_status: question is Claude Code\'s own fixed phrasing for this prompt shape, confirmed live');
    assert_equal("Fetch example.com\n\nrtk curl example.com", $hookPrompt['context'] ?? null, 'build_prompt_from_hook_status: context is format_pending_tool_input()\'s full-text rendering, same as the pane-scraped path uses');
    assert_equal(
        [['number' => 1, 'label' => 'Yes'], ['number' => 2, 'label' => "Yes, and don't ask again for: rtk curl *"], ['number' => 3, 'label' => 'No']],
        $hookPrompt['options'] ?? null,
        'build_prompt_from_hook_status: options built from permission_suggestions via the lookup table'
    );
    assert_equal(false, $hookPrompt['multi_question'] ?? null, 'build_prompt_from_hook_status: never a multi-question tab set - that shape stays on the pane-scraped path');
    assert_equal(false, $hookPrompt['is_folder_trust'] ?? null, 'build_prompt_from_hook_status: never the trust dialog - that fires no hooks at all');
    assert_equal('Bash', $hookPrompt['tool_name'] ?? null, 'build_prompt_from_hook_status: exposes tool_name for downstream consumers (push body, etc.)');
    assert_equal(['command' => 'rtk curl example.com', 'description' => 'Fetch example.com'], $hookPrompt['tool_input'] ?? null, 'build_prompt_from_hook_status: exposes the full tool_input too');

    $hookPromptNoSuggestions = PromptParser::build_prompt_from_hook_status(['tool_name' => 'Write', 'tool_input' => ['file_path' => '/tmp/x', 'content' => 'y']]);
    assert_equal(
        [['number' => 1, 'label' => 'Yes'], ['number' => 2, 'label' => 'No']],
        $hookPromptNoSuggestions['options'] ?? null,
        'build_prompt_from_hook_status: a missing permission_suggestions key (not just an empty array) is treated the same as none'
    );

    // --- PromptParser::build_multi_question_key_sequence(): the exact tmux
    // action sequence for a multi-question AskUserQuestion prompt, confirmed
    // against a real live 3-question call 2026-08-22 (Color: single-select,
    // Toppings: multiSelect, Confirm: single-select-with-free-text) - see
    // the todo file's research entry for the raw captures this mirrors. ---

    $colorToppingsConfirmQuestions = [
        ['question' => 'Pick a color', 'header' => 'Color', 'multiSelect' => false, 'options' => [['label' => 'Red'], ['label' => 'Blue'], ['label' => 'Green']]],
        ['question' => 'Pick toppings', 'header' => 'Toppings', 'multiSelect' => true, 'options' => [['label' => 'Cheese'], ['label' => 'Pepperoni'], ['label' => 'Mushroom']]],
        ['question' => 'Confirm the order?', 'header' => 'Confirm', 'multiSelect' => false, 'options' => [['label' => 'Yes'], ['label' => 'No']]],
    ];

    // Real captured answers: Red (option 1), Cheese+Pepperoni (options 1+2), Yes (option 1).
    $realSequence = PromptParser::build_multi_question_key_sequence($colorToppingsConfirmQuestions, [1, [1, 2], 1]);
    assert_equal(
        [
            ['type' => 'digit', 'value' => '1'], // Color: Red - single-select digit alone selects + auto-advances
            ['type' => 'digit', 'value' => '1'], // Toppings: toggle Cheese
            ['type' => 'digit', 'value' => '2'], // Toppings: toggle Pepperoni
            ['type' => 'right'],                 // Toppings: multiSelect needs an explicit Right to advance
            ['type' => 'digit', 'value' => '1'], // Confirm: Yes - auto-advances to the Review tab
            ['type' => 'digit', 'value' => '1'], // Review tab: "1. Submit answers"
        ],
        $realSequence,
        'build_multi_question_key_sequence: matches the exact real 3-question capture (single-select, multiSelect, single-select)'
    );

    // Real captured second scenario: Pet (single-select-with-free-text "Hamster"), Confirm (single-select Yes).
    $petConfirmQuestions = [
        ['question' => 'Favorite pet?', 'header' => 'Pet', 'multiSelect' => false, 'options' => [['label' => 'Dog'], ['label' => 'Cat']]],
        ['question' => 'Confirm?', 'header' => 'Confirm', 'multiSelect' => false, 'options' => [['label' => 'Yes'], ['label' => 'No']]],
    ];
    $freetextSequence = PromptParser::build_multi_question_key_sequence($petConfirmQuestions, [['text' => 'Hamster'], 1]);
    assert_equal(
        [
            ['type' => 'digit', 'value' => '3'],       // Pet: option 3 is the synthetic free-text slot (2 real options + 1)
            ['type' => 'text', 'value' => 'Hamster'],  // typed text replaces the option's label live
            ['type' => 'enter'],                       // Enter confirms + auto-advances, same as a real option's digit
            ['type' => 'digit', 'value' => '1'],       // Confirm: Yes - auto-advances to the Review tab
            ['type' => 'digit', 'value' => '1'],       // Review tab: "1. Submit answers"
        ],
        $freetextSequence,
        'build_multi_question_key_sequence: matches the exact real free-text-within-a-multi-question capture'
    );

    // --- a LONE multiSelect question: found live 2026-09-12 (Andres: sessioneer's
    // own dashboard rendered this shape with no way to check several boxes then
    // confirm at all, unlike Claude Code's own real TUI) - it gets a tab bar just
    // like a real 2+-question call, and is mechanically identical in every respect
    // re-verified live against a real, disposable session: toggle the checked
    // options, Right advances straight to the Review tab (there's only one
    // question to review), "1. Submit answers" confirms. ---

    $loneToppingsQuestion = [
        ['question' => 'Pick your toppings', 'header' => 'Toppings', 'multiSelect' => true, 'options' => [['label' => 'Cheese'], ['label' => 'Pepperoni'], ['label' => 'Mushroom']]],
    ];
    $loneMultiSelectSequence = PromptParser::build_multi_question_key_sequence($loneToppingsQuestion, [[1, 3]]);
    assert_equal(
        [
            ['type' => 'digit', 'value' => '1'], // toggle Cheese
            ['type' => 'digit', 'value' => '3'], // toggle Mushroom
            ['type' => 'right'],                 // advance straight to the Review tab (only one question)
            ['type' => 'digit', 'value' => '1'], // Review tab: "1. Submit answers"
        ],
        $loneMultiSelectSequence,
        'build_multi_question_key_sequence: a LONE multiSelect question matches the exact real single-question capture, not rejected as "too few questions"'
    );

    // --- sad paths: malformed/mismatched $answers is rejected outright, never a partial sequence ---

    assert_equal(null, PromptParser::build_multi_question_key_sequence([$petConfirmQuestions[0]], [1]), 'build_multi_question_key_sequence: a LONE SINGLE-select question is still rejected - no tab bar exists for that specific shape, use the pane-scraped path instead');
    assert_equal(null, PromptParser::build_multi_question_key_sequence($petConfirmQuestions, [1]), 'build_multi_question_key_sequence: answers count must match questions count');
    assert_equal(null, PromptParser::build_multi_question_key_sequence($petConfirmQuestions, [1, 99]), 'build_multi_question_key_sequence: an out-of-range single-select option index is rejected');
    assert_equal(null, PromptParser::build_multi_question_key_sequence($petConfirmQuestions, [1, []]), 'build_multi_question_key_sequence: an array answer for a non-multiSelect question is rejected');
    assert_equal(null, PromptParser::build_multi_question_key_sequence($colorToppingsConfirmQuestions, [1, [], 1]), 'build_multi_question_key_sequence: an empty selection for a multiSelect question is rejected (nothing checked)');
    assert_equal(null, PromptParser::build_multi_question_key_sequence($colorToppingsConfirmQuestions, [1, ['text' => 'extra cheese'], 1]), 'build_multi_question_key_sequence: free-text is NOT supported for a multiSelect question - rejected, not silently dropped');
    assert_equal(null, PromptParser::build_multi_question_key_sequence($colorToppingsConfirmQuestions, [1, [1, 99], 1]), 'build_multi_question_key_sequence: an out-of-range multiSelect option index is rejected');
    assert_equal(null, PromptParser::build_multi_question_key_sequence($petConfirmQuestions, [['text' => '   '], 1]), 'build_multi_question_key_sequence: whitespace-only free text is rejected');
    assert_equal(null, PromptParser::build_multi_question_key_sequence($petConfirmQuestions, [1, 'Yes']), 'build_multi_question_key_sequence: a plain string answer (not int, not {text:...}) is rejected');

    // --- PromptParser::augment_prompt_with_pending_tool(): only replaces context when the pending tool matches the pane's own marker ---

    $basePrompt = [
        'question' => 'Do you want to proceed?',
        'context' => "● Bash(npm test (truncated…",
        'options' => [],
        'multi_question' => false,
        'is_folder_trust' => false,
    ];

    assert_equal(
        $basePrompt,
        PromptParser::augment_prompt_with_pending_tool($basePrompt, null),
        'augment_prompt_with_pending_tool: no pending-tool file leaves the pane-scraped prompt untouched'
    );
    assert_equal(
        $basePrompt,
        PromptParser::augment_prompt_with_pending_tool($basePrompt, ['tool_name' => 'Write', 'tool_input' => ['file_path' => '/x', 'content' => 'y']]),
        'augment_prompt_with_pending_tool: a tool-name mismatch against the pane marker is left untouched (stale/wrong pending file)'
    );
    assert_equal(
        $basePrompt,
        PromptParser::augment_prompt_with_pending_tool($basePrompt, ['tool_name' => 'Bash', 'tool_input' => null]),
        'augment_prompt_with_pending_tool: a malformed pending-tool entry (no tool_input) is left untouched'
    );

    $augmented = PromptParser::augment_prompt_with_pending_tool($basePrompt, ['tool_name' => 'Bash', 'tool_input' => ['command' => 'npm test --full-real-command-not-truncated']]);
    assert_equal('npm test --full-real-command-not-truncated', $augmented['context'], 'augment_prompt_with_pending_tool: a matching tool name replaces the truncated pane context with the full hook-sourced one');
    assert_equal('Do you want to proceed?', $augmented['question'], 'augment_prompt_with_pending_tool: only context is replaced, question/options/etc are untouched');
    assert_equal('Bash', $augmented['tool_name'] ?? null, 'augment_prompt_with_pending_tool: exposes tool_name so callers (push body) can tell a permission prompt from a real question');
    assert_equal(['command' => 'npm test --full-real-command-not-truncated'], $augmented['tool_input'] ?? null, 'augment_prompt_with_pending_tool: exposes tool_input too');

    // AskUserQuestion renders with no "●" marker at all (verified live), so
    // there's nothing to cross-check against - the pane-scraped
    // question/context (already exactly what a human sees) must be left
    // untouched rather than replaced by a raw tool_input JSON dump, but
    // tool_name/tool_input still need to be exposed so the push body can
    // tell this apart from a permission prompt.
    $questionPrompt = [
        'question' => 'Which color do you prefer?',
        'context' => "☐ Color\n\nWhich color do you prefer?",
        'options' => [],
        'multi_question' => false,
        'is_folder_trust' => false,
    ];
    $questionInput = ['questions' => [['question' => 'Which color do you prefer?', 'header' => 'Color', 'options' => [['label' => 'Red'], ['label' => 'Blue']]]]];
    $augmentedQuestion = PromptParser::augment_prompt_with_pending_tool($questionPrompt, ['tool_name' => 'AskUserQuestion', 'tool_input' => $questionInput]);
    assert_equal($questionPrompt['context'], $augmentedQuestion['context'], 'augment_prompt_with_pending_tool: AskUserQuestion context is left untouched, not replaced with a raw JSON dump');
    assert_equal('AskUserQuestion', $augmentedQuestion['tool_name'] ?? null, 'augment_prompt_with_pending_tool: AskUserQuestion still exposes tool_name');
    assert_equal($questionInput, $augmentedQuestion['tool_input'] ?? null, 'augment_prompt_with_pending_tool: AskUserQuestion still exposes tool_input');

    // --- pending-tool sidecar: read/write/delete round-trip ---

    $pendingName = 'cc-pendingtest-' . bin2hex(random_bytes(3));
    assert_equal(null, PendingToolStore::read_pending_tool($pendingName), 'read_pending_tool: null when no file exists yet');

    PendingToolStore::write_pending_tool($pendingName, ['tool_name' => 'Bash', 'tool_input' => ['command' => 'ls'], 'written_at' => 1000]);
    $read = PendingToolStore::read_pending_tool($pendingName);
    assert_equal('Bash', $read['tool_name'] ?? null, 'write_pending_tool/read_pending_tool: round-trips tool_name');
    assert_equal('ls', $read['tool_input']['command'] ?? null, 'write_pending_tool/read_pending_tool: round-trips tool_input');

    PendingToolStore::delete_pending_tool($pendingName);
    assert_equal(null, PendingToolStore::read_pending_tool($pendingName), 'delete_pending_tool: file is gone after delete');

    // --- SessionStatusStore: read/write/update(merge)/delete round-trip ---

    $statusName = 'cc-statustest-' . bin2hex(random_bytes(3));
    assert_equal(null, SessionStatusStore::read_status($statusName), 'read_status: null when no file exists yet');

    SessionStatusStore::write_status($statusName, ['status' => 'idle', 'mode' => 'manual', 'blocked' => null]);
    $statusRead = SessionStatusStore::read_status($statusName);
    assert_equal('idle', $statusRead['status'] ?? null, 'write_status/read_status: round-trips status');
    assert_equal('manual', $statusRead['mode'] ?? null, 'write_status/read_status: round-trips mode');

    // update_status() merges onto the existing file rather than overwriting
    // it wholesale - the whole point of a per-hook read-modify-write (each
    // of the 3 new hooks only ever supplies the fields its own event
    // carries), mirroring PendingToolStore::write_pending_tool()'s own
    // read-modify-write shape.
    SessionStatusStore::update_status($statusName, ['status' => 'blocked', 'blocked' => ['tool_name' => 'Bash', 'tool_input' => ['command' => 'ls'], 'permission_suggestions' => []]]);
    $statusAfterUpdate = SessionStatusStore::read_status($statusName);
    assert_equal('blocked', $statusAfterUpdate['status'] ?? null, 'update_status: overwrites the field it was given');
    assert_equal('manual', $statusAfterUpdate['mode'] ?? null, 'update_status: leaves a field it was NOT given untouched (the mode set by the earlier write_status())');
    assert_equal('Bash', $statusAfterUpdate['blocked']['tool_name'] ?? null, 'update_status: merges in a new nested field');
    assert_true(is_int($statusAfterUpdate['updated_at'] ?? null), 'update_status: stamps updated_at fresh on every call');

    // A hook that couldn't normalize permission_mode (see
    // PermissionMode::normalize_hook_permission_mode()) omits the `mode` key
    // entirely rather than passing null - update_status() must leave the
    // previously-known mode alone in that case, not clobber it with null.
    SessionStatusStore::update_status($statusName, ['status' => 'working']);
    assert_equal('manual', SessionStatusStore::read_status($statusName)['mode'] ?? null, 'update_status: omitting the mode key preserves the previously-known mode');

    SessionStatusStore::delete_status($statusName);
    assert_equal(null, SessionStatusStore::read_status($statusName), 'delete_status: file is gone after delete');

    // --- SidecarStore::prune_orphaned_sidecars(): correctly matches pending-tool files back to their session name ---

    $liveName = 'cc-prunelive-' . bin2hex(random_bytes(3));
    $deadName = 'cc-prunedead-' . bin2hex(random_bytes(3));
    SidecarStore::write_sidecar($liveName, ['workdir' => '/x', 'spawned_at' => 1]);
    PendingToolStore::write_pending_tool($liveName, ['tool_name' => 'Bash', 'tool_input' => ['command' => 'ls']]);
    SessionStatusStore::write_status($liveName, ['status' => 'idle']);
    SidecarStore::write_sidecar($deadName, ['workdir' => '/x', 'spawned_at' => 1]);
    PendingToolStore::write_pending_tool($deadName, ['tool_name' => 'Bash', 'tool_input' => ['command' => 'ls']]);
    SessionStatusStore::write_status($deadName, ['status' => 'idle']);

    SidecarStore::prune_orphaned_sidecars([$liveName]);

    assert_true(SidecarStore::read_sidecar($liveName) !== null, 'prune_orphaned_sidecars: a live session\'s sidecar row survives');
    assert_true(PendingToolStore::read_pending_tool($liveName) !== null, 'prune_orphaned_sidecars: a live session\'s pending-tool row survives');
    // Regression test, from the pre-SQLite (2026-08-24) version of this
    // method: it used to strip a hardcoded suffix list off each glob-
    // matched filename to recover the real session name (".status.json"
    // -> strip ".status" -> the real name) - forgetting a kind there
    // deleted a LIVE session's own state as a false-positive "orphan" on
    // every single list_all_sessions() poll, immediately after it was
    // written. The SQLite version deletes by explicit table name instead
    // (sidecars/session_status/pending_tools - see prune_orphaned_sidecars()'s
    // own three-table DELETE loop), which can't have this specific bug
    // class any more, but the observable behavior this test actually
    // checks - each kind of live-session state survives a prune - is still
    // exactly the right thing to keep verifying.
    assert_true(SessionStatusStore::read_status($liveName) !== null, 'prune_orphaned_sidecars: a live session\'s status row survives');
    assert_equal(null, SidecarStore::read_sidecar($deadName), 'prune_orphaned_sidecars: a dead session\'s plain sidecar is pruned');
    assert_equal(null, PendingToolStore::read_pending_tool($deadName), 'prune_orphaned_sidecars: a dead session\'s pending-tool file is pruned too');
    assert_equal(null, SessionStatusStore::read_status($deadName), 'prune_orphaned_sidecars: a dead session\'s status file is pruned too');

    // --- the actual hook script: no SESSIONEER_SESSION_NAME env -> no-op ---

    $sidecarName = 'cc-hooktest-' . bin2hex(random_bytes(3));
    $oldId = '11111111-1111-4111-8111-111111111111';
    $newId = '22222222-2222-4222-8222-222222222222';
    write_fixture_transcript($oldId);
    write_fixture_transcript($newId);
    SidecarStore::write_sidecar($sidecarName, ['workdir' => '/fixture/workdir', 'spawned_at' => 1000, 'agent_session_id' => $oldId]);

    run_session_start_hook(null, ['session_id' => $newId]);
    assert_equal($oldId, SidecarStore::read_sidecar($sidecarName)['agent_session_id'] ?? null, 'session_start.php: no-op (sidecar untouched) when SESSIONEER_SESSION_NAME is unset');

    // --- SESSIONEER_SESSION_NAME set, but no matching sidecar (already killed/never tracked) -> no-op, no crash ---

    run_session_start_hook('cc-does-not-exist', ['session_id' => $newId]);
    assert_equal(null, SidecarStore::read_sidecar('cc-does-not-exist'), 'session_start.php: no-op when SESSIONEER_SESSION_NAME has no sidecar file');

    // --- SESSIONEER_SESSION_NAME set + real sidecar + valid payload with a REAL matching transcript -> rebinds agent_session_id, keeps the rest ---

    run_session_start_hook($sidecarName, ['session_id' => $newId]);
    $rebound = SidecarStore::read_sidecar($sidecarName);
    assert_equal($newId, $rebound['agent_session_id'] ?? null, 'session_start.php: rebinds agent_session_id to the new session-id from stdin when a real transcript for it exists');
    assert_equal('/fixture/workdir', $rebound['workdir'] ?? null, 'session_start.php: preserves workdir across the rebind');
    assert_equal(1000, $rebound['spawned_at'] ?? null, 'session_start.php: preserves spawned_at across the rebind');
    assert_equal(true, $rebound['spawned_by_app'] ?? null, 'session_start.php: a SESSIONEER_SESSION_NAME session is recorded as spawned_by_app=true');

    // --- SESSIONEER_SESSION_NAME set + real sidecar + payload reports a session-id
    // with NO matching transcript anywhere -> the rebind is refused, the
    // working sidecar is left exactly as it was. Regression test for the
    // 2026-08-08 live incident: a `claude` process run manually from inside
    // a tracked pane's own Bash tool (e.g. testing `--resume` behavior)
    // inherits SESSIONEER_SESSION_NAME and fires its own genuine SessionStart with
    // its own, unrelated session_id, which the hook used to trust blindly -
    // clobbering a working sidecar with an id that never had a transcript,
    // permanently breaking "view transcript" for that pane. ---

    $phantomId = '99999999-9999-4999-8999-999999999999';
    run_session_start_hook($sidecarName, ['session_id' => $phantomId]);
    assert_equal($newId, SidecarStore::read_sidecar($sidecarName)['agent_session_id'] ?? null, 'session_start.php: a session-id with no matching transcript file anywhere is never trusted enough to rebind an existing, working sidecar');

    // --- SESSIONEER_SESSION_NAME set + real sidecar + payload reports a session-id
    // that's real (has a transcript) but is ALREADY the live id of a
    // DIFFERENT tracked tmux session -> the rebind is refused, same as the
    // phantom-id case. Regression test for the 2026-08-23 live incident: a
    // nested `claude` child process inheriting this pane's SESSIONEER_SESSION_NAME
    // reported ANOTHER pane's own real, transcript-backed session id, which
    // passed the old transcript-exists-only check and clobbered this pane's
    // sidecar onto someone else's transcript - two dashboard rows then
    // showed identical, "merged" content. ---

    $otherTrackedName = 'cc-hooktest-other-' . bin2hex(random_bytes(3));
    $otherLiveId = '44444444-4444-4444-8444-444444444444';
    write_fixture_transcript($otherLiveId);
    TmuxService::tmux_run(['new-session', '-d', '-s', $otherTrackedName, '-c', Config::www_root(), 'bash', '-c', 'stty -echo; exec cat']);
    SidecarStore::write_sidecar($otherTrackedName, ['workdir' => '/fixture/other', 'spawned_at' => 1000, 'agent_session_id' => $otherLiveId]);

    run_session_start_hook($sidecarName, ['session_id' => $otherLiveId]);
    assert_equal($newId, SidecarStore::read_sidecar($sidecarName)['agent_session_id'] ?? null, 'session_start.php: a session-id already live on a DIFFERENT tracked tmux session is never trusted enough to rebind this one\'s sidecar onto it');

    // A session re-confirming its OWN already-bound id (the ordinary,
    // frequent case - the hook fires on every rotation, not just the first
    // time) must NOT trip over its own exclusion.
    run_session_start_hook($sidecarName, ['session_id' => $newId]);
    assert_equal($newId, SidecarStore::read_sidecar($sidecarName)['agent_session_id'] ?? null, 'session_start.php: a session re-confirming its own already-bound id is not refused as a false "already live elsewhere" collision');

    TmuxService::tmux_run(['kill-session', '-t', $otherTrackedName]);
    SidecarStore::delete_sidecar($otherTrackedName);

    // --- malformed/empty stdin -> no-op, never crashes, sidecar untouched ---

    run_session_start_hook($sidecarName, null);
    assert_equal($newId, SidecarStore::read_sidecar($sidecarName)['agent_session_id'] ?? null, 'session_start.php: no-op on empty/malformed stdin payload');

    // --- adopted (non-Sessioneer) sessions: no tmux pane at all -> no-op, no sidecar ever created ---

    $adoptedName = 'my-hand-picked-tmux-session';
    SidecarStore::delete_sidecar($adoptedName);

    run_session_start_hook(null, ['session_id' => 'adopted-id-1']); // TMUX unset in the base env - no pane at all
    assert_equal(null, SidecarStore::read_sidecar($adoptedName), 'session_start.php: no TMUX env at all -> no-op, never creates a sidecar (a bare/no-pane session can never get send-keys/capture-pane support regardless)');

    // --- adopted sessions: real tmux pane, first time seen -> CREATES a
    // brand new sidecar (unlike the SESSIONEER_SESSION_NAME path, which only ever
    // rebinds an already-existing one) - keyed off the pane's own tmux
    // session name (from `tmux display-message -p '#S'`, faked here - see
    // fake_tmux_bin_dir()), not anything app-set. ---

    $fakeTmuxDir = fake_tmux_bin_dir($adoptedName);

    $adoptedId1 = '33333333-3333-4333-8333-333333333333';
    write_fixture_transcript($adoptedId1);

    run_session_start_hook(null, ['session_id' => $adoptedId1, 'cwd' => '/home/user/www/some-other-project'], [
        'TMUX' => '/tmp/fake-tmux-socket,12345,0',
        'PATH' => $fakeTmuxDir . ':' . (getenv('PATH') ?: '/usr/bin:/bin'),
    ]);
    $adopted = SidecarStore::read_sidecar($adoptedName);
    assert_equal($adoptedId1, $adopted['agent_session_id'] ?? null, 'session_start.php: an adopted session (real tmux pane, no SESSIONEER_SESSION_NAME) gets a brand new sidecar, first time seen');
    assert_equal('/home/user/www/some-other-project', $adopted['workdir'] ?? null, 'session_start.php: an adopted session\'s workdir comes from the hook payload\'s own cwd field');
    assert_equal(false, $adopted['spawned_by_app'] ?? null, 'session_start.php: an adopted session is recorded as spawned_by_app=false, distinguishing it from an app-spawned one');
    assert_true(is_int($adopted['spawned_at'] ?? null), 'session_start.php: an adopted session gets a real spawned_at timestamp on first sight');

    // --- adopted sessions: firing again for the SAME pane rebinds (like
    // the Sessioneer path), preserving the original workdir/spawned_at rather
    // than treating every subsequent /clear-triggered fire as "first
    // seen" again. ---

    $firstSpawnedAt = $adopted['spawned_at'];
    $adoptedId2 = '44444444-4444-4444-8444-444444444444';
    write_fixture_transcript($adoptedId2);
    run_session_start_hook(null, ['session_id' => $adoptedId2, 'cwd' => '/should/be/ignored'], [
        'TMUX' => '/tmp/fake-tmux-socket,12345,0',
        'PATH' => $fakeTmuxDir . ':' . (getenv('PATH') ?: '/usr/bin:/bin'),
    ]);
    $reboundAdopted = SidecarStore::read_sidecar($adoptedName);
    assert_equal($adoptedId2, $reboundAdopted['agent_session_id'] ?? null, 'session_start.php: an adopted session rotating (e.g. /clear) rebinds agent_session_id the same as a Sessioneer one would');
    assert_equal('/home/user/www/some-other-project', $reboundAdopted['workdir'] ?? null, 'session_start.php: an adopted session\'s workdir is preserved across a rebind, not overwritten from the new payload');
    assert_equal($firstSpawnedAt, $reboundAdopted['spawned_at'] ?? null, 'session_start.php: an adopted session\'s spawned_at is preserved across a rebind');

    SidecarStore::delete_sidecar($adoptedName);
    array_map('unlink', glob("{$fakeTmuxDir}/*") ?: []);
    rmdir($fakeTmuxDir);

    // --- adopted sessions: a path-traversal-shaped tmux session name is
    // refused rather than trusted unsanitized (SidecarStore keys a sidecar
    // row directly off this string). ---

    $trickyTmuxDir = fake_tmux_bin_dir('../../etc/passwd');
    run_session_start_hook(null, ['session_id' => 'adopted-id-evil'], [
        'TMUX' => '/tmp/fake-tmux-socket,12345,0',
        'PATH' => $trickyTmuxDir . ':' . (getenv('PATH') ?: '/usr/bin:/bin'),
    ]);
    assert_equal(null, SidecarStore::read_sidecar('../../etc/passwd'), 'session_start.php: a tmux session name containing "/" is refused, never trusted as a sidecar filename');
    array_map('unlink', glob("{$trickyTmuxDir}/*") ?: []);
    rmdir($trickyTmuxDir);

    // --- adopted sessions: tmux itself failing (e.g. no current session
    // for that context) -> no-op, no crash, no sidecar ---

    $failingTmuxDir = fake_tmux_bin_dir(null);
    run_session_start_hook(null, ['session_id' => 'adopted-id-fail'], [
        'TMUX' => '/tmp/fake-tmux-socket,12345,0',
        'PATH' => $failingTmuxDir . ':' . (getenv('PATH') ?: '/usr/bin:/bin'),
    ]);
    assert_equal(null, SidecarStore::read_sidecar($adoptedName), 'session_start.php: tmux display-message itself failing -> no-op, never crashes');
    array_map('unlink', glob("{$failingTmuxDir}/*") ?: []);
    rmdir($failingTmuxDir);

    // --- pre_tool_use.php: no SESSIONEER_SESSION_NAME env -> no-op ---

    $preToolSessionName = 'cc-pretooltest-' . bin2hex(random_bytes(3));

    run_pre_tool_use_hook(null, ['tool_name' => 'Bash', 'tool_input' => ['command' => 'ls']]);
    assert_equal(null, PendingToolStore::read_pending_tool($preToolSessionName), 'pre_tool_use.php: no-op (no file written) when SESSIONEER_SESSION_NAME is unset');

    // --- SESSIONEER_SESSION_NAME set + valid payload -> writes tool_name/tool_input, no sidecar required first ---

    run_pre_tool_use_hook($preToolSessionName, ['tool_name' => 'Bash', 'tool_input' => ['command' => 'echo hi'], 'tool_use_id' => 'toolu_1']);
    $written = PendingToolStore::read_pending_tool($preToolSessionName);
    assert_equal('Bash', $written['tool_name'] ?? null, 'pre_tool_use.php: records tool_name from stdin');
    assert_equal('echo hi', $written['tool_input']['command'] ?? null, 'pre_tool_use.php: records the full tool_input from stdin');

    // --- found live 2026-08-22: a session stuck showing a stale "waiting
    // on input" prompt long after it was actually resolved - permission_
    // request.php sets status=blocked, but nothing else in the hook
    // sequence ever clears it unless the SAME turn also fires
    // UserPromptSubmit or Stop. Since Claude Code never starts executing
    // tool call N+1 while tool call N's own permission prompt is still
    // genuinely unanswered, a LATER tool call's PreToolUse firing is proof
    // any earlier blocking has already been resolved - this is what
    // actually clears it now. ---
    SessionStatusStore::update_status($preToolSessionName, [
        'status' => 'blocked',
        'blocked' => ['tool_name' => 'Bash', 'tool_input' => ['command' => 'rm -rf /tmp/old'], 'permission_suggestions' => []],
    ]);
    run_pre_tool_use_hook($preToolSessionName, ['tool_name' => 'Read', 'tool_input' => ['file_path' => '/tmp/x']]);
    $afterPreToolUse = SessionStatusStore::read_status($preToolSessionName);
    assert_equal('working', $afterPreToolUse['status'] ?? null, 'pre_tool_use.php: a later tool call starting clears a stale blocked status back to working');
    // array_key_exists(), not ?? - the key is explicitly set to null (a
    // real "cleared" value), not absent, and ?? cannot tell those apart.
    assert_true(array_key_exists('blocked', $afterPreToolUse) && $afterPreToolUse['blocked'] === null, 'pre_tool_use.php: a later tool call starting clears the stale blocked prompt content too');

    // --- a later tool call overwrites the previous one (only the latest is ever kept) ---

    run_pre_tool_use_hook($preToolSessionName, ['tool_name' => 'Write', 'tool_input' => ['file_path' => '/tmp/x', 'content' => 'y']]);
    $overwritten = PendingToolStore::read_pending_tool($preToolSessionName);
    assert_equal('Write', $overwritten['tool_name'] ?? null, 'pre_tool_use.php: a later tool call overwrites the earlier pending-tool file');

    // --- found live 2026-08-23: AskUserQuestion never fires
    // PermissionRequest (it's a distinct mechanism from a permission
    // decision, per the official tools reference), so the general
    // "optimistic working, PermissionRequest corrects it to blocked right
    // after" flow above never gets its correction for this one tool -
    // sessions got stuck showing "Thinking..." forever on a real,
    // answerable question. pre_tool_use.php special-cases AskUserQuestion
    // and writes blocked directly instead of working. ---
    run_pre_tool_use_hook($preToolSessionName, ['tool_name' => 'AskUserQuestion', 'tool_input' => ['questions' => [['question' => 'Which approach?', 'header' => 'Approach', 'options' => [['label' => 'A', 'description' => 'first']], 'multiSelect' => false]]]]);
    $afterAskUserQuestion = SessionStatusStore::read_status($preToolSessionName);
    assert_equal('blocked', $afterAskUserQuestion['status'] ?? null, 'pre_tool_use.php: AskUserQuestion writes status=blocked directly, since no PermissionRequest will ever correct it');
    assert_equal('AskUserQuestion', $afterAskUserQuestion['blocked']['tool_name'] ?? null, 'pre_tool_use.php: AskUserQuestion blocked state records its own tool_name');
    assert_equal('Which approach?', $afterAskUserQuestion['blocked']['tool_input']['questions'][0]['question'] ?? null, 'pre_tool_use.php: AskUserQuestion blocked state records its own tool_input');

    // --- malformed/empty stdin, or a payload missing tool_name/tool_input -> no-op, never crashes ---

    PendingToolStore::delete_pending_tool($preToolSessionName);
    run_pre_tool_use_hook($preToolSessionName, null);
    assert_equal(null, PendingToolStore::read_pending_tool($preToolSessionName), 'pre_tool_use.php: no-op on empty/malformed stdin payload');

    run_pre_tool_use_hook($preToolSessionName, ['hook_event_name' => 'PreToolUse']);
    assert_equal(null, PendingToolStore::read_pending_tool($preToolSessionName), 'pre_tool_use.php: no-op when tool_name/tool_input are missing from the payload');

    // --- never emits stdout - a hook that prints anything (even {}) could be read as an explicit permission decision ---

    assert_equal('', run_pre_tool_use_hook($preToolSessionName, ['tool_name' => 'Bash', 'tool_input' => ['command' => 'ls']]), 'pre_tool_use.php: writes nothing to stdout, deferring the permission decision entirely to Claude Code\'s normal flow');

    // --- permission_request.php: no SESSIONEER_SESSION_NAME env -> no-op ---

    $permReqName = 'cc-permreqtest-' . bin2hex(random_bytes(3));

    run_permission_request_hook(null, ['tool_name' => 'Bash', 'tool_input' => ['command' => 'ls'], 'permission_mode' => 'default']);
    assert_equal(null, SessionStatusStore::read_status($permReqName), 'permission_request.php: no-op (no file written) when SESSIONEER_SESSION_NAME is unset');

    // --- SESSIONEER_SESSION_NAME set + valid payload -> records blocked state + normalized mode ---

    run_permission_request_hook($permReqName, [
        'tool_name' => 'Bash',
        'tool_input' => ['command' => 'rtk curl example.com'],
        'permission_suggestions' => [['type' => 'setMode', 'mode' => 'acceptEdits']],
        'permission_mode' => 'default',
    ]);
    $permReqStatus = SessionStatusStore::read_status($permReqName);
    assert_equal('blocked', $permReqStatus['status'] ?? null, 'permission_request.php: records status=blocked');
    assert_equal('Bash', $permReqStatus['blocked']['tool_name'] ?? null, 'permission_request.php: records the blocked tool_name');
    assert_equal('rtk curl example.com', $permReqStatus['blocked']['tool_input']['command'] ?? null, 'permission_request.php: records the full tool_input');
    assert_equal([['type' => 'setMode', 'mode' => 'acceptEdits']], $permReqStatus['blocked']['permission_suggestions'] ?? null, 'permission_request.php: records permission_suggestions verbatim');
    assert_equal('manual', $permReqStatus['mode'] ?? null, 'permission_request.php: normalizes permission_mode ("default" -> "manual") into the same file');

    // --- an AskUserQuestion payload (no permission_suggestions at all, confirmed live) still records fine - build_session_entry() is what excludes it, not this hook ---

    run_permission_request_hook($permReqName, [
        'tool_name' => 'AskUserQuestion',
        'tool_input' => ['questions' => [['question' => 'Pick a fruit', 'header' => 'Fruit', 'options' => [['label' => 'Apple'], ['label' => 'Banana']]]]],
        'permission_mode' => 'default',
    ]);
    $askStatus = SessionStatusStore::read_status($permReqName);
    assert_equal('AskUserQuestion', $askStatus['blocked']['tool_name'] ?? null, 'permission_request.php: records an AskUserQuestion payload too, with no permission_suggestions key needed');

    // --- an unrecognized permission_mode value (e.g. bypassPermissions) leaves the previously-recorded mode untouched, not clobbered with null ---

    run_permission_request_hook($permReqName, ['tool_name' => 'Bash', 'tool_input' => ['command' => 'ls'], 'permission_mode' => 'bypassPermissions']);
    assert_equal('manual', SessionStatusStore::read_status($permReqName)['mode'] ?? null, 'permission_request.php: an unrecognized permission_mode value never overwrites the previously-known mode');

    // --- malformed/empty stdin, or a payload missing tool_name/tool_input -> no-op, never crashes ---

    SessionStatusStore::delete_status($permReqName);
    run_permission_request_hook($permReqName, null);
    assert_equal(null, SessionStatusStore::read_status($permReqName), 'permission_request.php: no-op on empty/malformed stdin payload');

    run_permission_request_hook($permReqName, ['hook_event_name' => 'PermissionRequest']);
    assert_equal(null, SessionStatusStore::read_status($permReqName), 'permission_request.php: no-op when tool_name/tool_input are missing from the payload');

    assert_equal('', run_permission_request_hook($permReqName, ['tool_name' => 'Bash', 'tool_input' => ['command' => 'ls']]), 'permission_request.php: writes nothing to stdout - pure-observe, same convention as pre_tool_use.php');

    // --- user_prompt_submit.php: no SESSIONEER_SESSION_NAME env -> no-op ---

    $upsName = 'cc-upstest-' . bin2hex(random_bytes(3));

    run_user_prompt_submit_hook(null, ['permission_mode' => 'default']);
    assert_equal(null, SessionStatusStore::read_status($upsName), 'user_prompt_submit.php: no-op when SESSIONEER_SESSION_NAME is unset');

    // --- marks working and clears any previously-recorded blocked state ---

    SessionStatusStore::write_status($upsName, ['status' => 'blocked', 'blocked' => ['tool_name' => 'Bash', 'tool_input' => ['command' => 'ls']], 'mode' => 'manual']);
    run_user_prompt_submit_hook($upsName, ['permission_mode' => 'acceptEdits']);
    $upsStatus = SessionStatusStore::read_status($upsName);
    assert_equal('working', $upsStatus['status'] ?? null, 'user_prompt_submit.php: marks the session working');
    assert_true(array_key_exists('blocked', $upsStatus) && $upsStatus['blocked'] === null, 'user_prompt_submit.php: clears any previously-recorded blocked state');
    assert_equal('accept edits', $upsStatus['mode'] ?? null, 'user_prompt_submit.php: normalizes and records the current permission_mode');

    // --- malformed/empty stdin -> no-op, never crashes ---

    SessionStatusStore::delete_status($upsName);
    run_user_prompt_submit_hook($upsName, null);
    assert_equal(null, SessionStatusStore::read_status($upsName), 'user_prompt_submit.php: no-op on empty/malformed stdin payload');

    assert_equal('', run_user_prompt_submit_hook($upsName, ['permission_mode' => 'default']), 'user_prompt_submit.php: writes nothing to stdout');

    // --- stop.php: no SESSIONEER_SESSION_NAME env -> no-op ---

    $stopName = 'cc-stoptest-' . bin2hex(random_bytes(3));

    run_stop_hook(null, ['last_assistant_message' => 'All done.', 'permission_mode' => 'default']);
    assert_equal(null, SessionStatusStore::read_status($stopName), 'stop.php: no-op when SESSIONEER_SESSION_NAME is unset');

    // --- marks idle, clears blocked state, records last_assistant_message and mode ---

    SessionStatusStore::write_status($stopName, ['status' => 'blocked', 'blocked' => ['tool_name' => 'Bash', 'tool_input' => ['command' => 'ls']], 'mode' => 'manual']);
    run_stop_hook($stopName, ['last_assistant_message' => 'All done.', 'permission_mode' => 'default']);
    $stopStatus = SessionStatusStore::read_status($stopName);
    assert_equal('idle', $stopStatus['status'] ?? null, 'stop.php: marks the session idle');
    assert_true(array_key_exists('blocked', $stopStatus) && $stopStatus['blocked'] === null, 'stop.php: clears any previously-recorded blocked state');
    assert_equal('All done.', $stopStatus['last_message'] ?? null, 'stop.php: records last_assistant_message as last_message');
    assert_equal('manual', $stopStatus['mode'] ?? null, 'stop.php: records the normalized current mode too');

    // --- stop.php clears SessionStatusStore's `model` optimistic override
    // (the fix for the bug reported live 2026-08-30, "changing the model on
    // the session page doesn't work" - see SessionStatusStore::
    // read_status()'s own docblock for the full mechanics): once the turn
    // that used the just-picked model finishes, the transcript itself
    // already reflects it, so the override must be cleared here or it
    // would permanently shadow any LATER model change made outside this
    // app's own dropdown. ---

    SessionStatusStore::update_status($stopName, ['model' => 'haiku']);
    run_stop_hook($stopName, ['last_assistant_message' => 'All done.', 'permission_mode' => 'default']);
    $stopStatusModelCleared = SessionStatusStore::read_status($stopName);
    assert_true(
        array_key_exists('model', $stopStatusModelCleared) && $stopStatusModelCleared['model'] === null,
        'stop.php: clears the model optimistic override once the turn that used it finishes'
    );

    // --- a payload with no last_assistant_message at all (e.g. a genuinely empty response) still marks idle, just without touching last_message ---

    SessionStatusStore::write_status($stopName, ['status' => 'working', 'last_message' => 'previous message']);
    run_stop_hook($stopName, ['permission_mode' => 'default']);
    $stopStatusNoMessage = SessionStatusStore::read_status($stopName);
    assert_equal('idle', $stopStatusNoMessage['status'] ?? null, 'stop.php: still marks idle when last_assistant_message is absent');
    assert_equal('previous message', $stopStatusNoMessage['last_message'] ?? null, 'stop.php: leaves a previously-recorded last_message untouched when this fire has none');

    // --- malformed/empty stdin -> no-op, never crashes ---

    SessionStatusStore::delete_status($stopName);
    run_stop_hook($stopName, null);
    assert_equal(null, SessionStatusStore::read_status($stopName), 'stop.php: no-op on empty/malformed stdin payload');

    assert_equal('', run_stop_hook($stopName, ['last_assistant_message' => 'done', 'permission_mode' => 'default']), 'stop.php: writes nothing to stdout');
} finally {
    @unlink($settingsPath);
    @rmdir(dirname($settingsPath));
    array_map('unlink', glob("{$fixtureSidecarDir}/*") ?: []);
    @rmdir($fixtureSidecarDir);
    array_map('unlink', glob("{$fixtureHome}/.claude/projects/fixture-project/*") ?: []);
    @rmdir("{$fixtureHome}/.claude/projects/fixture-project");
    @rmdir("{$fixtureHome}/.claude/projects");
    @rmdir("{$fixtureHome}/.claude");
    @rmdir($fixtureHome);
}

test_exit();

/**
 * Runs the real host-agent/hooks/session_start.php as a subprocess, same
 * as Claude Code itself would -  becomes its SESSIONEER_SESSION_NAME
 * env var (omitted entirely when null, mirroring a plain untracked claude
 * process), $payload is JSON-encoded to its stdin (raw '' when null, to
 * exercise the empty/malformed-input path). $extraEnv merges in on top of
 * the base env - used by the tmux-adoption tests below to set TMUX (so the
 * hook believes it's running inside a pane) and override PATH to a
 * fixture directory containing a fake `tmux` executable (see
 * fake_tmux_bin_dir()) instead of the real one, so a test can never touch
 * the real tmux server.
 *
 * @param array<string, mixed>|null $payload
 * @param array<string, string> $extraEnv
 */
function run_session_start_hook(?string $sessioneerSessionName, ?array $payload, array $extraEnv = []): void
{
    $env = [
        'HOME_ROOT' => Config::home_root(),
        'SIDECAR_DIR' => Config::sidecar_dir(),
        'TMUX_SOCKET' => Config::tmux_socket(),
        'PATH' => getenv('PATH') ?: '/usr/bin:/bin',
    ];

    if ($sessioneerSessionName !== null) {
        $env['SESSIONEER_SESSION_NAME'] = $sessioneerSessionName;
    }

    $env = $extraEnv + $env;

    $descriptors = [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
    $process = proc_open(
        ['php', dirname(__DIR__) . '/host-agent/hooks/session_start.php'],
        $descriptors,
        $pipes,
        null,
        $env
    );

    if (!is_resource($process)) {
        assert_true(false, 'run_session_start_hook: failed to start subprocess');
        return;
    }

    fwrite($pipes[0], $payload !== null ? json_encode($payload) : '');
    fclose($pipes[0]);
    stream_get_contents($pipes[1]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    proc_close($process);
}

/**
 * Creates a real, minimal transcript file under the fixture HOME_ROOT's
 * .claude/projects tree so TranscriptService::find_transcript_path()
 * (used by session_start.php to refuse trusting a session-id with no real
 * transcript - see the 2026-08-08 regression test above) finds it. The
 * containing directory name is arbitrary - find_transcript_path() globs by
 * session-id filename only, never decodes the directory name.
 */
function write_fixture_transcript(string $sessionId): void
{
    $dir = Config::home_root() . '/.claude/projects/fixture-project';
    if (!is_dir($dir)) {
        mkdir($dir, 0700, true);
    }

    file_put_contents("{$dir}/{$sessionId}.jsonl", json_encode(['type' => 'user', 'sessionId' => $sessionId]) . "\n");
}

/**
 * A fake `tmux` executable (just enough to fool TmuxService::tmux_run()'s
 * `['tmux', '-S', socket, 'display-message', '-p', '#S']` invocation) in
 * its own fixture directory - $sessionNameOutput is echoed verbatim
 * regardless of the real args passed, or nothing + a non-zero exit when
 * null (simulating a real tmux failure, e.g. no current session). Putting
 * this directory FIRST on PATH is what keeps the real system tmux (and so
 * the real, possibly-live production tmux server) completely out of reach
 * for these tests.
 */
function fake_tmux_bin_dir(?string $sessionNameOutput): string
{
    $dir = sys_get_temp_dir() . '/sessioneer-test-fake-tmux-' . bin2hex(random_bytes(4));
    mkdir($dir, 0700, true);

    $script = $sessionNameOutput === null
        ? "#!/bin/bash\nexit 1\n"
        : "#!/bin/bash\necho " . escapeshellarg($sessionNameOutput) . "\n";

    file_put_contents("{$dir}/tmux", $script);
    chmod("{$dir}/tmux", 0700);

    return $dir;
}

/**
 * Same shape as run_session_start_hook(), for host-agent/hooks/pre_tool_use.php
 * - returns its stdout so callers can assert it's always empty (see
 * PendingToolStore::write_pending_tool()'s "never affects the permission decision" contract).
 *
 * @param array<string, mixed>|null $payload
 */
function run_pre_tool_use_hook(?string $sessioneerSessionName, ?array $payload): string
{
    $env = [
        'HOME_ROOT' => Config::home_root(),
        'SIDECAR_DIR' => Config::sidecar_dir(),
        'PATH' => getenv('PATH') ?: '/usr/bin:/bin',
    ];

    if ($sessioneerSessionName !== null) {
        $env['SESSIONEER_SESSION_NAME'] = $sessioneerSessionName;
    }

    $descriptors = [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
    $process = proc_open(
        ['php', dirname(__DIR__) . '/host-agent/hooks/pre_tool_use.php'],
        $descriptors,
        $pipes,
        null,
        $env
    );

    if (!is_resource($process)) {
        assert_true(false, 'run_pre_tool_use_hook: failed to start subprocess');
        return '';
    }

    fwrite($pipes[0], $payload !== null ? json_encode($payload) : '');
    fclose($pipes[0]);
    $stdout = stream_get_contents($pipes[1]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    proc_close($process);

    return (string)$stdout;
}

/**
 * Shared subprocess runner behind run_permission_request_hook()/
 * run_user_prompt_submit_hook()/run_stop_hook() below - same shape as
 * run_pre_tool_use_hook() above, just parameterized by script path instead
 * of copy-pasted three more times (all three new hooks share the exact
 * same SESSIONEER_SESSION_NAME-gated, stdin-JSON-in/stdout-string-out contract).
 *
 * @param array<string, mixed>|null $payload
 */
function run_status_hook_script(string $scriptPath, ?string $sessioneerSessionName, ?array $payload): string
{
    $env = [
        'HOME_ROOT' => Config::home_root(),
        'SIDECAR_DIR' => Config::sidecar_dir(),
        'PATH' => getenv('PATH') ?: '/usr/bin:/bin',
    ];

    if ($sessioneerSessionName !== null) {
        $env['SESSIONEER_SESSION_NAME'] = $sessioneerSessionName;
    }

    $descriptors = [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
    $process = proc_open(['php', $scriptPath], $descriptors, $pipes, null, $env);

    if (!is_resource($process)) {
        assert_true(false, "run_status_hook_script({$scriptPath}): failed to start subprocess");
        return '';
    }

    fwrite($pipes[0], $payload !== null ? json_encode($payload) : '');
    fclose($pipes[0]);
    $stdout = stream_get_contents($pipes[1]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    proc_close($process);

    return (string)$stdout;
}

/**
 * @param array<string, mixed>|null $payload
 */
function run_permission_request_hook(?string $sessioneerSessionName, ?array $payload): string
{
    return run_status_hook_script(dirname(__DIR__) . '/host-agent/hooks/permission_request.php', $sessioneerSessionName, $payload);
}

/**
 * @param array<string, mixed>|null $payload
 */
function run_user_prompt_submit_hook(?string $sessioneerSessionName, ?array $payload): string
{
    return run_status_hook_script(dirname(__DIR__) . '/host-agent/hooks/user_prompt_submit.php', $sessioneerSessionName, $payload);
}

/**
 * @param array<string, mixed>|null $payload
 */
function run_stop_hook(?string $sessioneerSessionName, ?array $payload): string
{
    return run_status_hook_script(dirname(__DIR__) . '/host-agent/hooks/stop.php', $sessioneerSessionName, $payload);
}
