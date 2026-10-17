<?php
declare(strict_types=1);

/**
 * Pure unit tests for HostAgent\Services\TranscriptService against a
 * hand-crafted fixture JSONL (tests/fixtures/transcript_sample.jsonl) - no
 * tmux, no socket, no real ~/.claude/projects involved. See
 * test_sessions_lifecycle.php and test_agent_client_protocol.php for the
 * tmux/socket-backed suites. Also covers SessionService::title_cascade()/
 * session_title()/list_archived_sessions() at the bottom - their only real
 * dependency is TranscriptService's own transcript-path/ai-title/cwd
 * lookups, so they need no tmux either.
 */

require __DIR__ . '/lib/assert.php';
require __DIR__ . '/lib/harness.php';
require dirname(__DIR__) . '/vendor/autoload.php';

use HostAgent\Services\ArchivedSessionService;
use HostAgent\Services\Config;
use HostAgent\Services\SelectableModel;
use HostAgent\Services\SessionDetailService;
use HostAgent\Services\SessionService;
use HostAgent\Services\TranscriptService;

// Config::home_root() no longer needs stubbing here (it did back when
// home_root() lived in Sessions.php, pulling in that whole file just for
// this one function) - Config is now its own dependency-free, autoloaded
// class, so the real Config::home_root() (still env-overridable via
// HOME_ROOT, same as before) works directly.

const FIXTURE_TRANSCRIPT = __DIR__ . '/fixtures/transcript_sample.jsonl';

// --- TranscriptService::parse_transcript_line(): meta-only, malformed, and content-less
// lines are all skipped (null), everything else renders its blocks ---
assert_equal(null, TranscriptService::parse_transcript_line('{"type":"mode"}'), 'parse_transcript_line: meta-only type -> null');
assert_equal(null, TranscriptService::parse_transcript_line('not valid json{'), 'parse_transcript_line: malformed JSON -> null');
assert_equal(null, TranscriptService::parse_transcript_line('{"type":"system","message":null}'), 'parse_transcript_line: no message -> null');

// --- these four (found by scanning a real, 700+-message transcript - see
// transcript_meta_only_types()'s comment) never carry a `message` key in
// practice, but are listed explicitly for clarity; verified here with
// their real shapes (no "message" key at all, not even null) ---
assert_equal(null, TranscriptService::parse_transcript_line('{"type":"system","subtype":"stop_hook_summary","hookCount":1}'), 'parse_transcript_line: system (stop_hook_summary) -> null');
assert_equal(null, TranscriptService::parse_transcript_line('{"type":"queue-operation","operation":"enqueue","content":"do the thing"}'), 'parse_transcript_line: queue-operation -> null');
assert_equal(null, TranscriptService::parse_transcript_line('{"type":"file-history-snapshot","messageId":"x","snapshot":{}}'), 'parse_transcript_line: file-history-snapshot -> null');
assert_equal(null, TranscriptService::parse_transcript_line('{"type":"file-history-delta","messageId":"x","trackingPath":"a.php"}'), 'parse_transcript_line: file-history-delta -> null');
assert_equal(null, TranscriptService::parse_transcript_line('{"type":"user","message":{"role":"user","content":[]}}'), 'parse_transcript_line: empty content -> null');

$userLine = TranscriptService::parse_transcript_line('{"type":"user","timestamp":"2026-01-01T00:00:00Z","message":{"role":"user","content":"Fix the bug"}}');
assert_equal('user', $userLine['type'] ?? null, 'parse_transcript_line: string content - type');
assert_equal('user', $userLine['role'] ?? null, 'parse_transcript_line: string content - role');
assert_equal([['kind' => 'text', 'text' => 'Fix the bug']], $userLine['blocks'] ?? null, 'parse_transcript_line: bare string content becomes one text block');

$toolUseLine = TranscriptService::parse_transcript_line('{"type":"assistant","message":{"role":"assistant","content":[{"type":"tool_use","name":"Bash"}]}}');
assert_equal([['kind' => 'tool_use', 'text' => 'tool: Bash', 'tool_name' => 'Bash']], $toolUseLine['blocks'] ?? null, 'parse_transcript_line: tool_use with no input falls back to the bare tool name');

// --- TranscriptService::summarize_tool_use(): "tool: X - key: value, ..." - shows every
// param, not just one primary argument, joined onto a single line when
// short enough (mirrors BlockedPromptView::collapsible_summary()'s own single-line
// threshold - see format_tool_use_summary()) ---
// description (when present) is excluded from summarize_tool_use()'s own
// text entirely - it's surfaced separately (see summarize_content_block()'s
// own 'description' field and tool_use_description()'s doc comment,
// 2026-08-21) as its own always-visible rendered line, not part of the
// collapsible param-dump text at all.
assert_equal('tool: Bash - command: rm -rf /tmp/x', TranscriptService::summarize_tool_use(['name' => 'Bash', 'input' => ['command' => 'rm -rf /tmp/x', 'description' => 'Clean up']]), 'summarize_tool_use: Bash - description excluded from the text (surfaced separately), command still shown as a param');
assert_equal('tool: Bash', TranscriptService::summarize_tool_use(['name' => 'Bash', 'input' => ['description' => 'Just the description, no other params']]), 'summarize_tool_use: a description-only input has nothing left to show as text once description is excluded - just the bare tool name');
assert_equal('tool: Read - file_path: /etc/hosts', TranscriptService::summarize_tool_use(['name' => 'Read', 'input' => ['file_path' => '/etc/hosts']]), 'summarize_tool_use: Read - file_path used');
assert_equal('tool: Grep - pattern: TODO', TranscriptService::summarize_tool_use(['name' => 'Grep', 'input' => ['pattern' => 'TODO']]), 'summarize_tool_use: Grep - pattern used');
// NotebookEdit's path argument is named notebook_path, not file_path -
// input order deliberately does NOT have it first, so this only passes if
// notebook_path is itself a recognized primary key (see
// tool_use_primary_arg_keys()), not by accident of already leading the
// input array.
assert_equal(
    'tool: NotebookEdit - notebook_path: /tmp/x.ipynb, cell_id: 3',
    TranscriptService::summarize_tool_use(['name' => 'NotebookEdit', 'input' => ['cell_id' => '3', 'notebook_path' => '/tmp/x.ipynb']]),
    'summarize_tool_use: NotebookEdit - notebook_path leads the summary even though it is not the first input key'
);
assert_equal('tool: Bash', TranscriptService::summarize_tool_use(['name' => 'Bash', 'input' => []]), 'summarize_tool_use: empty input -> bare name');
assert_equal('tool: Bash', TranscriptService::summarize_tool_use(['name' => 'Bash']), 'summarize_tool_use: no input key at all -> bare name');
assert_equal(
    'tool: Weird - foo: bar',
    TranscriptService::summarize_tool_use(['name' => 'Weird', 'input' => ['foo' => 'bar']]),
    'summarize_tool_use: unrecognized shape still shows its params as key: value, not a raw JSON dump'
);

// --- TranscriptService::humanize_tool_name()/TranscriptService::summarize_tool_use(): MCP tool names
// ("mcp__server__tool") are reformatted to "server.tool" - real name taken
// from a live captured transcript entry (an MCP Playwright call). Its
// `element` field (also a real captured shape: {"element": "3. Type
// something. button", "target": "f31e74"}) is a recognized primary arg too.
// Two params pushes this past the single-line length threshold, so it's
// the multi-line "Params:" form - see format_tool_use_summary(). ---
assert_equal('playwright.browser_click', TranscriptService::humanize_tool_name('mcp__playwright__browser_click'), 'humanize_tool_name: strips the mcp__ prefix and joins server/tool with a dot');
assert_equal('Bash', TranscriptService::humanize_tool_name('Bash'), 'humanize_tool_name: a non-MCP name passes through unchanged');
assert_equal(
    "tool: playwright.browser_click\nParams:\n- element: 3. Type something. button\n- target: f31e74",
    TranscriptService::summarize_tool_use(['name' => 'mcp__playwright__browser_click', 'input' => ['element' => '3. Type something. button', 'target' => 'f31e74']]),
    'summarize_tool_use: MCP tool name humanized, element (primary arg) and target both shown'
);

// --- TranscriptService::summarize_tool_use()/summarize_ask_user_question(): AskUserQuestion's
// nested questions/options input has no scalar primary key, so it would
// otherwise fall through to an unreadable raw JSON dump - real shape taken
// from a live captured transcript entry. ---
$realAskUserQuestionInput = [
    'questions' => [[
        'header' => 'Stale tab?',
        'multiSelect' => false,
        'question' => 'Does a hard refresh on that tab fix it?',
        'options' => [
            ['label' => 'Yes, fixed after hard refresh', 'description' => 'Confirms it was just a stale page load.'],
            ['label' => 'No, still shows the tmux command after hard refresh', 'description' => "Then it's a real, still-open bug."],
        ],
    ]],
];
assert_equal(
    "tool: AskUserQuestion\nParams:\n- Does a hard refresh on that tab fix it? (Yes, fixed after hard refresh / No, still shows the tmux command after hard refresh)",
    TranscriptService::summarize_tool_use(['name' => 'AskUserQuestion', 'input' => $realAskUserQuestionInput]),
    'summarize_tool_use: AskUserQuestion shows the question and its options, not a raw JSON dump'
);
assert_equal(
    "tool: AskUserQuestion\nParams:\n- Favorite color? (Red / Blue); Favorite animal? (Cat / Dog)",
    TranscriptService::summarize_tool_use(['name' => 'AskUserQuestion', 'input' => ['questions' => [
        ['question' => 'Favorite color?', 'options' => [['label' => 'Red'], ['label' => 'Blue']]],
        ['question' => 'Favorite animal?', 'options' => [['label' => 'Cat'], ['label' => 'Dog']]],
    ]]]),
    'summarize_tool_use: AskUserQuestion with multiple questions joins them with "; "'
);
assert_equal(
    'tool: AskUserQuestion - questions: []',
    TranscriptService::summarize_tool_use(['name' => 'AskUserQuestion', 'input' => ['questions' => []]]),
    'summarize_tool_use: AskUserQuestion with an empty/unrecognized questions shape falls back to showing the raw param'
);

// --- TranscriptService::summarize_tool_use()/summarize_agent_tool_use(): a subagent launch
// (Claude Code's "Agent" tool - real name verified live 2026-08-02, not
// "Task") shows "<subagent_type>: <description>" instead of dumping
// description/prompt/subagent_type/run_in_background as separate params -
// the full prompt text especially would otherwise be noisy/unreadable. ---
assert_equal(
    'tool: Agent - general-purpose: Reply with pineapple',
    TranscriptService::summarize_tool_use(['name' => 'Agent', 'input' => ['description' => 'Reply with pineapple', 'prompt' => 'Reply with exactly the single word: pineapple. Do nothing else.', 'subagent_type' => 'general-purpose', 'run_in_background' => false]]),
    'summarize_tool_use: Agent shows subagent_type + description, not every param'
);
assert_equal(
    'tool: Agent - Reply with pineapple',
    TranscriptService::summarize_tool_use(['name' => 'Agent', 'input' => ['description' => 'Reply with pineapple']]),
    'summarize_tool_use: Agent with only description (no subagent_type) still shows something readable'
);
assert_equal(
    'tool: Agent - general-purpose:',
    TranscriptService::summarize_tool_use(['name' => 'Agent', 'input' => ['subagent_type' => 'general-purpose']]),
    'summarize_tool_use: Agent with only subagent_type (no description) still shows something readable'
);
assert_true(
    str_starts_with(TranscriptService::summarize_tool_use(['name' => 'Agent', 'input' => ['foo' => 'bar']]), 'tool: Agent - foo: bar'),
    'summarize_tool_use: Agent with neither description nor subagent_type falls back to the generic param dump'
);

// --- TranscriptService::parse_transcript_line(): a real captured Agent tool_use gets an
// agent_type field (read straight from its own input.subagent_type) so
// session.php can color/collapse it as a distinct "subagent" entry kind
// instead of a generic tool call - see entry_color_kind(). ---
$agentToolUseLine = TranscriptService::parse_transcript_line(json_encode([
    'type' => 'assistant',
    'message' => ['role' => 'assistant', 'content' => [[
        'type' => 'tool_use',
        'name' => 'Agent',
        'input' => ['description' => 'Reply with pineapple', 'prompt' => 'Reply with exactly the single word: pineapple. Do nothing else.', 'subagent_type' => 'general-purpose', 'run_in_background' => false],
    ]]],
]));
assert_equal('tool: Agent - general-purpose: Reply with pineapple', $agentToolUseLine['blocks'][0]['text'] ?? null, 'parse_transcript_line: Agent tool_use gets the clean subagent summary');
assert_equal('general-purpose', $agentToolUseLine['blocks'][0]['agent_type'] ?? null, 'parse_transcript_line: Agent tool_use block carries agent_type from its own input');

$nonAgentToolUseLine = TranscriptService::parse_transcript_line('{"type":"assistant","message":{"role":"assistant","content":[{"type":"tool_use","name":"Bash","input":{"command":"ls"}}]}}');
assert_true(!array_key_exists('agent_type', $nonAgentToolUseLine['blocks'][0]), 'parse_transcript_line: a plain (non-Agent) tool_use never gets an agent_type field');

// --- TranscriptService::parse_transcript_line(): the Agent tool's own tool_result - real
// shape captured live 2026-08-02 against an actual subagent call. Two
// real findings baked in: (1) the second text block ("agentId: ...
// <usage>...</usage>") is pure internal bookkeeping the tool's own
// instructions say must never be shown - stripped from the rendered text
// entirely, not joined in as if it were more of the real output. (2) the
// agent_type this block gets comes from the OUTER JSONL line's
// toolUseResult.agentType field, not from anything inside the tool_result
// content itself, which has no agent-type info of its own. ---
$agentToolResultLine = TranscriptService::parse_transcript_line(json_encode([
    'type' => 'user',
    'message' => ['role' => 'user', 'content' => [[
        'type' => 'tool_result',
        'tool_use_id' => 'toolu_01C8mWxDwmtpqW39rfHT28WV',
        'content' => [
            ['type' => 'text', 'text' => 'pineapple'],
            ['type' => 'text', 'text' => "agentId: aba497819de523c24 (use SendMessage with to: 'aba497819de523c24', summary: '<5-10 word recap>' to continue this agent)\n<usage>subagent_tokens: 26059\ntool_uses: 0\nduration_ms: 2018</usage>"],
        ],
    ]]],
    'toolUseResult' => ['status' => 'completed', 'agentId' => 'aba497819de523c24', 'agentType' => 'general-purpose'],
]));
assert_equal('pineapple', $agentToolResultLine['blocks'][0]['text'] ?? null, 'parse_transcript_line: Agent tool_result text is just the real output, the internal agentId/usage metadata block is stripped');
assert_equal('general-purpose', $agentToolResultLine['blocks'][0]['agent_type'] ?? null, 'parse_transcript_line: Agent tool_result gets agent_type from the outer toolUseResult field');

$nonAgentToolResultLine = TranscriptService::parse_transcript_line('{"type":"user","message":{"role":"user","content":[{"type":"tool_result","content":[{"type":"text","text":"file1\nfile2"}]}]}}');
assert_true(!array_key_exists('agent_type', $nonAgentToolResultLine['blocks'][0]), 'parse_transcript_line: a plain (non-subagent) tool_result never gets an agent_type field');

// --- TranscriptService::is_subagent_metadata_text(): the ASYNC agent-launch
// acknowledgment tool_result - real shape captured live 2026-08-22, no
// <usage> tag anywhere (unlike the synchronous shape above), so it needs
// its own detection rather than falling through the original regex. ---
assert_true(
    TranscriptService::is_subagent_metadata_text("Async agent launched successfully. (This tool result is internal metadata \xe2\x80\x94 never quote or paste any part of it, including the agentId below, into a user-facing reply.)\nagentId: a30538165e6b11d51 (internal ID - do not mention to user. Use SendMessage with to: 'a30538165e6b11d51', summary: '<5-10 word recap>' to continue this agent.)\nThe agent is working in the background. You will be notified automatically when it completes.\noutput_file: /tmp/foo/tasks/a30538165e6b11d51.output"),
    'is_subagent_metadata_text: real async-launch acknowledgment text is recognized as internal bookkeeping'
);
assert_true(!TranscriptService::is_subagent_metadata_text('pineapple'), 'is_subagent_metadata_text: a real subagent result is never mistaken for the bookkeeping text');

// --- TranscriptService::parse_task_notification()/parse_transcript_line(): a
// backgrounded subagent's completion notifies the parent conversation as a
// plain-string "<task-notification>...</task-notification>"-wrapped "user"
// entry - Claude Code's own SendMessage/fork mechanism, NOT a tool_result
// block at all (real shape captured live 2026-08-22). Before this, a plain
// string $content with no special-casing just became a generic 'text'
// block, rendering the raw XML tags verbatim - found live while
// investigating a different report (subagent entries "render raw XML"). ---
$taskNotificationText = "<task-notification>\n<task-id>a208bf6022dfc3391</task-id>\n<tool-use-id>toolu_01WZxrK1iYbxqPteFtG8xqYw</tool-use-id>\n<output-file>/tmp/foo/tasks/a208bf6022dfc3391.output</output-file>\n<status>completed</status>\n<summary>Agent \"CDP-in-PHP feasibility research\" finished</summary>\n<note>A task-notification fires each time this agent stops with no live background children of its own.</note>\n<result>Have everything needed. All read-only research is complete.</result>\n</task-notification>";

$notification = TranscriptService::parse_task_notification($taskNotificationText);
assert_equal('completed', $notification['status'] ?? null, 'parse_task_notification: status tag extracted');
assert_equal('Agent "CDP-in-PHP feasibility research" finished', $notification['summary'] ?? null, 'parse_task_notification: summary tag extracted');
assert_equal('Have everything needed. All read-only research is complete.', $notification['result'] ?? null, 'parse_task_notification: result tag extracted');

assert_equal(null, TranscriptService::parse_task_notification('just a regular assistant reply'), 'parse_task_notification: plain text is never mistaken for a task-notification');

$taskNotificationLine = TranscriptService::parse_transcript_line(json_encode([
    'type' => 'user',
    'message' => ['role' => 'user', 'content' => $taskNotificationText],
]));
assert_equal('task_notification', $taskNotificationLine['blocks'][0]['kind'] ?? null, 'parse_transcript_line: a real task-notification string becomes a task_notification block, not a generic text block');
assert_equal('Have everything needed. All read-only research is complete.', $taskNotificationLine['blocks'][0]['text'] ?? null, 'parse_transcript_line: task_notification block text is the <result> content, not the raw XML');
assert_equal('Agent "CDP-in-PHP feasibility research" finished', $taskNotificationLine['blocks'][0]['summary'] ?? null, 'parse_transcript_line: task_notification block carries the summary tag');
assert_equal('completed', $taskNotificationLine['blocks'][0]['status'] ?? null, 'parse_transcript_line: task_notification block carries the status tag');
assert_true(!str_contains($taskNotificationLine['blocks'][0]['text'], '<task-notification>'), 'parse_transcript_line: the raw XML tags never leak into the rendered block text');

// A notification missing a <result> tag (e.g. a failure with no output)
// still parses - falls back to the summary rather than a blank block.
$noResultLine = TranscriptService::parse_transcript_line(json_encode([
    'type' => 'user',
    'message' => ['role' => 'user', 'content' => "<task-notification>\n<status>failed</status>\n<summary>Agent crashed</summary>\n</task-notification>"],
]));
assert_equal('Agent crashed', $noResultLine['blocks'][0]['text'] ?? null, 'parse_transcript_line: a task-notification with no <result> tag falls back to the summary as its text');
assert_true(!array_key_exists('result', $noResultLine['blocks'][0]), 'parse_transcript_line: task_notification block never carries a raw "result" key, only kind/text/summary/status');

// --- TranscriptService::summarize_content_block()/parse_transcript_line(): ExitPlanMode
// gets its own 'plan' block kind entirely, not the generic "tool: X -
// key: value" summary - the real plan content shown in full, like a real
// message, since Claude explicitly stops and asks for review on it. ---
$planBlock = TranscriptService::summarize_content_block(['type' => 'tool_use', 'name' => 'ExitPlanMode', 'input' => ['plan' => "# Do the thing\n\nSome details."]]);
assert_equal(['kind' => 'plan', 'text' => "# Do the thing\n\nSome details."], $planBlock, 'summarize_content_block: ExitPlanMode tool_use becomes a plan block, full text, not a param-dump summary');

$emptyPlanBlock = TranscriptService::summarize_content_block(['type' => 'tool_use', 'name' => 'ExitPlanMode', 'input' => ['plan' => '   ']]);
assert_equal('tool_use', $emptyPlanBlock['kind'], 'summarize_content_block: ExitPlanMode with a blank/whitespace-only plan falls back to the generic tool_use summary instead of an empty plan block');

$planLine = TranscriptService::parse_transcript_line(json_encode([
    'type' => 'assistant',
    'message' => ['role' => 'assistant', 'content' => [['type' => 'tool_use', 'id' => 'toolu_plan1', 'name' => 'ExitPlanMode', 'input' => ['plan' => "# Refactor the thing\n\nDetails here."]]]],
]));
assert_equal([['kind' => 'plan', 'text' => "# Refactor the thing\n\nDetails here."]], $planLine['blocks'] ?? null, 'parse_transcript_line: a real ExitPlanMode tool_use line parses to a single plan block');

// --- TranscriptService::parse_transcript_line(): an APPROVED plan is
// self-identifying via its own outer toolUseResult ({"plan": "..."} - a
// distinctive shape, verified live 2026-08-07 against real captured
// transcripts) - no id cross-reference needed. The verbose "## Approved
// Plan:\n<plan again>" boilerplate is replaced with a short, clean line -
// the real plan text is already fully visible just above as its own
// 'plan'-kind block, showing it a second time in full would be pure
// noise. ---
$approvedPlanLine = TranscriptService::parse_transcript_line(json_encode([
    'type' => 'user',
    'message' => ['role' => 'user', 'content' => [['type' => 'tool_result', 'tool_use_id' => 'toolu_plan1', 'content' => "User has approved your plan...\n\n## Approved Plan:\n# Refactor the thing"]]],
    'toolUseResult' => ['plan' => "# Refactor the thing\n\nDetails here."],
]));
assert_equal('approved', $approvedPlanLine['blocks'][0]['plan_status'] ?? null, 'parse_transcript_line: an approved plan tool_result gets plan_status=approved');
assert_equal('Plan approved - starting work', $approvedPlanLine['blocks'][0]['text'] ?? null, 'parse_transcript_line: an approved plan tool_result gets a short, clean text instead of the verbose re-dumped-plan boilerplate');

// --- TranscriptService::find_exit_plan_mode_tool_use_ids()/parse_transcript_line(): a
// REJECTED plan is NOT self-identifying - "User rejected tool use" is the
// exact same generic outer toolUseResult for ANY rejected tool (verified
// live 2026-08-07: identical string for rejected Bash/Write/Edit calls
// too), so recognizing "this rejection was specifically a plan" requires
// cross-referencing tool_use_id against a real ExitPlanMode tool_use seen
// elsewhere in the transcript - see find_exit_plan_mode_tool_use_ids(). ---
$rejectedPlanIds = TranscriptService::find_exit_plan_mode_tool_use_ids([
    json_encode(['type' => 'assistant', 'message' => ['role' => 'assistant', 'content' => [['type' => 'tool_use', 'id' => 'toolu_plan2', 'name' => 'ExitPlanMode', 'input' => ['plan' => '# A plan']]]]]),
    json_encode(['type' => 'assistant', 'message' => ['role' => 'assistant', 'content' => [['type' => 'tool_use', 'id' => 'toolu_bash1', 'name' => 'Bash', 'input' => ['command' => 'ls']]]]]),
]);
assert_equal(['toolu_plan2' => true], $rejectedPlanIds, 'find_exit_plan_mode_tool_use_ids: finds only the real ExitPlanMode tool_use id, not the unrelated Bash one');

$rejectedPlanLine = TranscriptService::parse_transcript_line(
    json_encode(['type' => 'user', 'message' => ['role' => 'user', 'content' => [['type' => 'tool_result', 'tool_use_id' => 'toolu_plan2', 'is_error' => true, 'content' => "The user doesn't want to proceed with this tool use..."]]], 'toolUseResult' => 'User rejected tool use']),
    $rejectedPlanIds
);
assert_equal('rejected', $rejectedPlanLine['blocks'][0]['plan_status'] ?? null, 'parse_transcript_line: a rejected plan tool_result (id found in the pre-scanned map) gets plan_status=rejected');
assert_equal('Plan not approved', $rejectedPlanLine['blocks'][0]['text'] ?? null, 'parse_transcript_line: a rejected plan tool_result gets a short, clean text instead of the internal "STOP what you are doing" boilerplate aimed at Claude');

// A rejected Bash call has the EXACT SAME generic outer toolUseResult
// string as a rejected plan - only the id map (built from find_exit_plan_
// mode_tool_use_ids()) tells them apart. Without a matching id, this must
// NOT be mistaken for a plan rejection.
$rejectedBashLine = TranscriptService::parse_transcript_line(
    json_encode(['type' => 'user', 'message' => ['role' => 'user', 'content' => [['type' => 'tool_result', 'tool_use_id' => 'toolu_bash1', 'is_error' => true, 'content' => "The user doesn't want to proceed with this tool use..."]]], 'toolUseResult' => 'User rejected tool use']),
    $rejectedPlanIds
);
assert_true(!array_key_exists('plan_status', $rejectedBashLine['blocks'][0]), 'parse_transcript_line: a rejected Bash call (same generic toolUseResult string, but its id is NOT in the plan id map) never gets a plan_status');

$toolUseWithCommand = TranscriptService::parse_transcript_line('{"type":"assistant","message":{"role":"assistant","content":[{"type":"tool_use","name":"Bash","input":{"command":"ls -la","description":"List files"}}]}}');
assert_equal([['kind' => 'tool_use', 'text' => 'tool: Bash - command: ls -la', 'description' => 'List files', 'tool_name' => 'Bash', 'command' => 'ls -la']], $toolUseWithCommand['blocks'] ?? null, 'parse_transcript_line: tool_use with a command shows it, plus its own separate description field (not folded into text)');

// --- summarize_content_block()'s own 'description' field: rendered as its
// own always-visible line (transcript/block.php/renderBlock() in
// session.js), never subject to the collapsible summary's first-line-only
// truncation the rest of the params are (2026-08-21) ---
$toolUseNoDescription = TranscriptService::parse_transcript_line('{"type":"assistant","message":{"role":"assistant","content":[{"type":"tool_use","name":"Read","input":{"file_path":"/etc/hosts"}}]}}');
assert_equal(false, array_key_exists('description', $toolUseNoDescription['blocks'][0] ?? []), 'parse_transcript_line: tool_use with no description input has no description key at all, not an empty string');

// Agent is excluded from the separate 'description' field - its own
// summarize_agent_tool_use() already folds description into the text
// summary itself ("<subagent_type>: <description>"), so adding it again
// here would just show it twice.
$agentToolUse = TranscriptService::parse_transcript_line('{"type":"assistant","message":{"role":"assistant","content":[{"type":"tool_use","name":"Agent","input":{"subagent_type":"general-purpose","description":"Reply with pineapple","prompt":"..."}}]}}');
assert_equal(false, array_key_exists('description', $agentToolUse['blocks'][0] ?? []), 'parse_transcript_line: Agent tool_use has no separate description field - already shown via its own bespoke "<subagent_type>: <description>" text summary');
assert_contains('Reply with pineapple', $agentToolUse['blocks'][0]['text'] ?? '', 'parse_transcript_line: Agent tool_use still shows its description, just via the text summary rather than a separate field');

$toolResultLine = TranscriptService::parse_transcript_line('{"type":"user","message":{"role":"user","content":[{"type":"tool_result","content":[{"type":"text","text":"file1\nfile2"}]}]}}');
assert_equal([['kind' => 'tool_result', 'text' => "file1\nfile2"]], $toolResultLine['blocks'] ?? null, 'parse_transcript_line: tool_result content flattened to text');

// --- images: a real captured shape (a browser-automation screenshot tool
// result) has BOTH a text block and an image block side by side in the
// same tool_result's content array - the image used to be silently
// dropped entirely (summarize_content_block()'s default case: kind kept,
// text always empty), now carried through as an extra "image" field
// alongside the text summary. ---
$fakeImageData = base64_encode('not a real png, just fixture bytes');
$toolResultWithImage = TranscriptService::parse_transcript_line(json_encode([
    'type' => 'user',
    'message' => ['role' => 'user', 'content' => [[
        'type' => 'tool_result',
        'content' => [
            ['type' => 'text', 'text' => 'Screenshot taken'],
            ['type' => 'image', 'source' => ['type' => 'base64', 'media_type' => 'image/png', 'data' => $fakeImageData]],
        ],
    ]]],
]));
assert_equal('Screenshot taken', $toolResultWithImage['blocks'][0]['text'] ?? null, 'parse_transcript_line: tool_result with an image alongside text still shows the text');
assert_equal(
    ['media_type' => 'image/png', 'data' => $fakeImageData],
    $toolResultWithImage['blocks'][0]['image'] ?? null,
    'parse_transcript_line: tool_result with an image carries it through as an extra field, not silently dropped'
);

// --- a top-level image block (not nested in a tool_result) - e.g. a
// directly-attached/pasted image. ---
$topLevelImage = TranscriptService::parse_transcript_line(json_encode([
    'type' => 'user',
    'message' => ['role' => 'user', 'content' => [
        ['type' => 'image', 'source' => ['type' => 'base64', 'media_type' => 'image/jpeg', 'data' => $fakeImageData]],
    ]],
]));
assert_equal(
    ['kind' => 'image', 'text' => '', 'image' => ['media_type' => 'image/jpeg', 'data' => $fakeImageData]],
    $topLevelImage['blocks'][0] ?? null,
    'parse_transcript_line: a top-level (not tool_result-nested) image block is carried through too'
);

// --- a pathologically large image is dropped (not embedded, not crashed
// on) rather than blowing up the page - falls back to a plain text note
// so there's still some visible sign something was there. ---
$hugeImage = TranscriptService::parse_transcript_line(json_encode([
    'type' => 'user',
    'message' => ['role' => 'user', 'content' => [
        ['type' => 'image', 'source' => ['type' => 'base64', 'media_type' => 'image/png', 'data' => str_repeat('x', 8_000_001)]],
    ]],
]));
assert_equal(null, $hugeImage['blocks'][0]['image'] ?? null, 'parse_transcript_line: an oversized image is not embedded');
assert_equal('(image could not be displayed)', $hugeImage['blocks'][0]['text'] ?? null, 'parse_transcript_line: an oversized image falls back to a plain text note instead');

$longLine = TranscriptService::parse_transcript_line(json_encode(['type' => 'assistant', 'message' => ['role' => 'assistant', 'content' => [['type' => 'text', 'text' => str_repeat('x', 52000)]]]]));
assert_true(strlen($longLine['blocks'][0]['text']) < 50100, 'parse_transcript_line: text block beyond the hard cap is truncated');
assert_true(str_ends_with($longLine['blocks'][0]['text'], '(truncated)'), 'parse_transcript_line: truncated block is marked as such');

$normalLongLine = TranscriptService::parse_transcript_line(json_encode(['type' => 'assistant', 'message' => ['role' => 'assistant', 'content' => [['type' => 'text', 'text' => str_repeat('y', 10000)]]]]));
assert_equal(10000, strlen($normalLongLine['blocks'][0]['text']), 'parse_transcript_line: a normal-sized long block (well under the hard cap) is kept in full, not truncated');

// --- TranscriptService::find_transcript_path(): only matches UUID-shaped ids, globs across
// every project dir under claude_projects_dir() (Config::home_root() . '/.claude/projects') ---
$fakeHome = sys_get_temp_dir() . '/sessioneer-test-transcript-home-' . getmypid();
$uuid = '12345678-1234-4123-8123-123456789012';
@mkdir($fakeHome . '/.claude/projects/-some-project', 0700, true);
file_put_contents($fakeHome . '/.claude/projects/-some-project/' . $uuid . '.jsonl', "{}\n");
putenv("HOME_ROOT={$fakeHome}");

assert_equal(
    $fakeHome . '/.claude/projects/-some-project/' . $uuid . '.jsonl',
    TranscriptService::find_transcript_path($uuid),
    'find_transcript_path: finds the file by globbing across project dirs'
);
assert_equal(null, TranscriptService::find_transcript_path('not-a-uuid'), 'find_transcript_path: rejects a non-UUID-shaped id before touching the filesystem');
assert_equal(null, TranscriptService::find_transcript_path('00000000-0000-4000-8000-000000000000'), 'find_transcript_path: well-formed but nonexistent UUID -> null');

// --- TranscriptService::find_first_cwd(): reads cwd from the first real
// message line, NOT the (lossy) encoded project directory name - a
// leading meta line (no cwd) is skipped for free. ---
file_put_contents($fakeHome . '/.claude/projects/-some-project/' . $uuid . '.jsonl', implode("\n", [
    '{"type":"mode","mode":"default"}',
    '{"type":"user","message":{"role":"user","content":[{"type":"text","text":"hi"}]},"cwd":"/home/user/www/some-project"}',
]) . "\n");
assert_equal('/home/user/www/some-project', TranscriptService::find_first_cwd($fakeHome . '/.claude/projects/-some-project/' . $uuid . '.jsonl'), 'find_first_cwd: skips a leading meta line, finds cwd on the first real message line');
assert_equal(null, TranscriptService::find_first_cwd('/does/not/exist.jsonl'), 'find_first_cwd: missing file -> null');

$noCwdFile = $fakeHome . '/.claude/projects/-some-project/no-cwd.jsonl';
file_put_contents($noCwdFile, str_repeat("{\"type\":\"mode\",\"mode\":\"default\"}\n", 30));
assert_equal(null, TranscriptService::find_first_cwd($noCwdFile), 'find_first_cwd: gives up after FIRST_CWD_SCAN_LINES rather than reading the whole file');
@unlink($noCwdFile);

// --- TranscriptService::find_first_timestamp(): reads the first real
// message line's own timestamp as a Unix epoch int - the take-over
// heuristic's "when did this conversation actually start" signal, since
// a bare process's OS pid is never recorded in the transcript itself. ---
assert_equal(
    null,
    TranscriptService::find_first_timestamp($fakeHome . '/.claude/projects/-some-project/' . $uuid . '.jsonl'),
    'find_first_timestamp: this fixture\'s first real line has no timestamp field -> null, not a crash'
);

$timestampFile = $fakeHome . '/.claude/projects/-some-project/has-timestamp.jsonl';
file_put_contents($timestampFile, implode("\n", [
    '{"type":"mode","mode":"default"}',
    '{"type":"user","message":{"role":"user","content":[{"type":"text","text":"hi"}]},"cwd":"/home/user/www/some-project","timestamp":"2026-08-02T04:06:15.197Z"}',
]) . "\n");
assert_equal(strtotime('2026-08-02T04:06:15.197Z'), TranscriptService::find_first_timestamp($timestampFile), 'find_first_timestamp: skips a leading meta line, finds timestamp on the first real message line');
assert_equal(null, TranscriptService::find_first_timestamp('/does/not/exist.jsonl'), 'find_first_timestamp: missing file -> null');

$noTimestampFile = $fakeHome . '/.claude/projects/-some-project/no-timestamp.jsonl';
file_put_contents($noTimestampFile, str_repeat("{\"type\":\"mode\",\"mode\":\"default\"}\n", 30));
assert_equal(null, TranscriptService::find_first_timestamp($noTimestampFile), 'find_first_timestamp: gives up after FIRST_CWD_SCAN_LINES rather than reading the whole file');
@unlink($noTimestampFile);
@unlink($timestampFile);

// --- TranscriptService::list_all_transcripts(): one entry per known
// transcript, live or dormant, across every project dir - raw ai_title
// (nullable), not a cascaded display title (that's SessionService's job). ---
$uuid2 = '22222222-2222-4222-8222-222222222222';
@mkdir($fakeHome . '/.claude/projects/-another-project', 0700, true);
file_put_contents($fakeHome . '/.claude/projects/-another-project/' . $uuid2 . '.jsonl', implode("\n", [
    '{"type":"user","message":{"role":"user","content":[{"type":"text","text":"hi"}]},"cwd":"/home/user/www/another-project"}',
    '{"type":"ai-title","aiTitle":"Fix the widget","sessionId":"' . $uuid2 . '"}',
]) . "\n");

$allTranscripts = TranscriptService::list_all_transcripts();
assert_equal(2, count($allTranscripts), 'list_all_transcripts: finds one entry per transcript file across every project dir');

$byId = [];
foreach ($allTranscripts as $t) {
    $byId[$t['agent_session_id']] = $t;
}

assert_equal('/home/user/www/some-project', $byId[$uuid]['cwd'] ?? null, 'list_all_transcripts: cwd read via find_first_cwd()');
assert_equal('hi', $byId[$uuid]['ai_title'], 'list_all_transcripts: no ai-title line -> the first prompt, not a placeholder string');
assert_equal('/home/user/www/another-project', $byId[$uuid2]['cwd'] ?? null, 'list_all_transcripts: finds the second project dir too');
assert_equal('Fix the widget', $byId[$uuid2]['ai_title'] ?? null, 'list_all_transcripts: ai_title is the raw value, not yet cascaded');
assert_true(($byId[$uuid2]['last_activity'] ?? 0) > 0, 'list_all_transcripts: last_activity is the file\'s own mtime');

// --- ArchivedSessionService::list_archived_sessions(): excludes whatever's
// currently tracked (already shown in the main list), applies the same
// title cascade as a live session, sorted most-recently-active first ---
$archived = ArchivedSessionService::list_archived_sessions([$uuid2]);
assert_equal(1, count($archived), 'list_archived_sessions: excludes the given agent_session_id, leaving just the other one');
assert_equal($uuid, $archived[0]['agent_session_id'] ?? null, 'list_archived_sessions: the non-excluded transcript is the one returned');
assert_equal('/home/user/www/some-project', $archived[0]['cwd'] ?? null, 'list_archived_sessions: cwd carried through');
assert_equal('hi', $archived[0]['title'] ?? null, 'list_archived_sessions: no ai-title -> the first prompt, via the same title_cascade() a live session uses (the workdir basename applies only when there is no prompt either; see the title_cascade tests)');

$archivedNoExclusions = ArchivedSessionService::list_archived_sessions([]);
assert_equal(2, count($archivedNoExclusions), 'list_archived_sessions: an empty exclude list returns every known transcript');
$withAiTitle = null;
foreach ($archivedNoExclusions as $a) {
    if ($a['agent_session_id'] === $uuid2) {
        $withAiTitle = $a;
    }
}
assert_equal('Fix the widget', $withAiTitle['title'] ?? null, 'list_archived_sessions: a real ai-title is used as the title when present');

touch($fakeHome . '/.claude/projects/-some-project/' . $uuid . '.jsonl', time() - 1000);
touch($fakeHome . '/.claude/projects/-another-project/' . $uuid2 . '.jsonl', time());
$sortedArchived = ArchivedSessionService::list_archived_sessions([]);
assert_equal([$uuid2, $uuid], array_column($sortedArchived, 'agent_session_id'), 'list_archived_sessions: sorted most-recently-active (mtime) first');

// --- TranscriptService::search_transcript_file(): full-text search across
// a transcript's real message content, newest match first. Line 1 is a
// deliberate false-positive trap - "pineapple" only appears inside a
// tool_use's own id (never surfaced as rendered block text), proving the
// two-stage stripos check (raw line, then parsed block text) actually
// filters it out rather than reporting a match with nothing findable to
// highlight once clicked through to. ---
$searchUuid = '33333333-3333-4333-8333-333333333333';
$searchFile = $fakeHome . '/.claude/projects/-some-project/' . $searchUuid . '.jsonl';
file_put_contents($searchFile, implode("\n", [
    json_encode(['type' => 'assistant', 'message' => ['role' => 'assistant', 'content' => [['type' => 'tool_use', 'id' => 'tool_pineapple_1', 'name' => 'Bash', 'input' => ['command' => 'ls']]]]]),
    json_encode(['type' => 'user', 'message' => ['role' => 'user', 'content' => [['type' => 'text', 'text' => 'I love pineapple pizza']]]]),
    json_encode(['type' => 'assistant', 'message' => ['role' => 'assistant', 'content' => [['type' => 'text', 'text' => 'Pineapple is a fruit, technically a berry']]]]),
    json_encode(['type' => 'user', 'timestamp' => '2026-08-11T00:00:00Z', 'message' => ['role' => 'user', 'content' => [['type' => 'text', 'text' => str_repeat('x', 100) . ' pineapple ' . str_repeat('y', 100)]]]]),
]) . "\n");

$matches = TranscriptService::search_transcript_file($searchFile, 'pineapple', 10);
assert_equal(3, count($matches), 'search_transcript_file: 3 real matches - the raw-only hit inside a tool_use id (line 1, never rendered as block text) is filtered out');
assert_equal([4, 3, 2], array_column($matches, 'line'), 'search_transcript_file: newest match first');
assert_equal('user', $matches[0]['role'] ?? null, 'search_transcript_file: role is carried through from parse_transcript_line');
assert_equal('text', $matches[0]['kind'] ?? null, 'search_transcript_file: kind is carried through from the matched block');
assert_true(str_starts_with($matches[0]['snippet'], '… '), 'search_transcript_file: a match with a long prefix before it gets a leading ellipsis');
assert_true(str_ends_with($matches[0]['snippet'], ' …'), 'search_transcript_file: a match with a long suffix after it gets a trailing ellipsis');
assert_true(str_contains($matches[0]['snippet'], 'pineapple'), 'search_transcript_file: the snippet actually contains the match');
assert_equal(strtotime('2026-08-11T00:00:00Z'), $matches[0]['timestamp'] ?? null, 'search_transcript_file: the transcript entry\'s own ISO timestamp is converted to Unix seconds, ready for relativeTimeLabel() client-side');
assert_true(array_key_exists('timestamp', $matches[1]) && $matches[1]['timestamp'] === null, 'search_transcript_file: timestamp key is present but null when the source line has no timestamp field at all');
assert_equal('Pineapple is a fruit, technically a berry', $matches[1]['snippet'] ?? null, 'search_transcript_file: a short match (fits within the context window) gets no ellipsis, whole text as-is');
assert_equal('I love pineapple pizza', $matches[2]['snippet'] ?? null, 'search_transcript_file: matching is case-insensitive relative to the query - "pineapple" (lowercase query) matches "Pineapple" (line 3) too, proven by that match being included above');

$caseInsensitiveMatches = TranscriptService::search_transcript_file($searchFile, 'PINEAPPLE', 10);
assert_equal(3, count($caseInsensitiveMatches), 'search_transcript_file: an uppercase query still matches lowercase content');

$cappedMatches = TranscriptService::search_transcript_file($searchFile, 'pineapple', 1);
assert_equal([4], array_column($cappedMatches, 'line'), 'search_transcript_file: maxMatches caps the result count, keeping the newest');

assert_equal([], TranscriptService::search_transcript_file($searchFile, '', 10), 'search_transcript_file: an empty/whitespace-only query returns no matches, not every line');
assert_equal([], TranscriptService::search_transcript_file($searchFile, '   ', 10), 'search_transcript_file: a whitespace-only query is trimmed to empty, same as above');
assert_equal([], TranscriptService::search_transcript_file('/does/not/exist.jsonl', 'pineapple', 10), 'search_transcript_file: a missing file returns an empty array, not a crash');
assert_equal([], TranscriptService::search_transcript_file($searchFile, 'mango', 10), 'search_transcript_file: a query with no matches anywhere returns an empty array');

// --- ArchivedSessionService::archived_session_transcript_search(): the archived-
// view search box's own data source - keyed straight by agent_session_id,
// same as archived_session_detail()/archived_session_history() above. ---
$archivedSearch = ArchivedSessionService::archived_session_transcript_search($searchUuid, 'pineapple', 20);
assert_true($archivedSearch['ok'] ?? false, 'archived_session_transcript_search: ok=true for a known transcript');
assert_equal(3, count($archivedSearch['matches'] ?? []), 'archived_session_transcript_search: matches passed straight through from search_transcript_file()');

$archivedSearchNoMatch = ArchivedSessionService::archived_session_transcript_search($searchUuid, 'mango', 20);
assert_true($archivedSearchNoMatch['ok'] ?? false, 'archived_session_transcript_search: ok=true even with zero matches - "no results" is not an error');
assert_equal([], $archivedSearchNoMatch['matches'] ?? null, 'archived_session_transcript_search: empty matches array for a query that hits nothing');

$archivedSearchMissing = ArchivedSessionService::archived_session_transcript_search('00000000-0000-4000-8000-000000000000', 'pineapple', 20);
assert_equal(false, $archivedSearchMissing['ok'] ?? null, 'archived_session_transcript_search: ok=false for a well-formed but unknown agent_session_id, not a crash');

// --- ArchivedSessionService::search_transcripts(): the dashboard-wide search box's
// data source - every known transcript, live or archived. Uses the same
// $fakeHome fixture tree list_all_transcripts() already reads from above,
// so this naturally covers $uuid/$uuid2/$searchUuid all at once. No live
// tmux session is tracked in this test process, so every result's own
// session_name comes back null (nothing to mark "live" against) - the
// live-vs-archived branch itself is exercised at the HTTP layer instead
// (test_ui_smoke.php's canned agent), where a tracked session is cheap to
// fake without a real tmux server. ---
$dashboardSearch = ArchivedSessionService::search_transcripts('pineapple', 30, 3);
assert_true($dashboardSearch['ok'] ?? false, 'search_transcripts: ok=true');
assert_equal(1, count($dashboardSearch['results'] ?? []), 'search_transcripts: only the transcript that actually matches is included - $uuid/$uuid2 have no "pineapple" in them');
assert_equal($searchUuid, $dashboardSearch['results'][0]['agent_session_id'] ?? null, 'search_transcripts: the matching transcript is identified by agent_session_id');
assert_true(
    array_key_exists('session_name', $dashboardSearch['results'][0]) && $dashboardSearch['results'][0]['session_name'] === null,
    'search_transcripts: session_name is null when nothing currently tracks this agent_session_id live'
);
assert_equal(3, count($dashboardSearch['results'][0]['matches'] ?? []), 'search_transcripts: per-session matches capped at max_matches_per_session (3 requested, 3 real matches exist)');

$dashboardSearchCappedPerSession = ArchivedSessionService::search_transcripts('pineapple', 30, 1);
assert_equal(1, count($dashboardSearchCappedPerSession['results'][0]['matches'] ?? []), 'search_transcripts: max_matches_per_session is respected per result too, not just the overall session count');

$dashboardSearchEmpty = ArchivedSessionService::search_transcripts('', 30, 3);
assert_equal([], $dashboardSearchEmpty['results'] ?? null, 'search_transcripts: an empty query returns no results rather than every known transcript');

$dashboardSearchNoMatch = ArchivedSessionService::search_transcripts('mango', 30, 3);
assert_equal([], $dashboardSearchNoMatch['results'] ?? null, 'search_transcripts: a query that matches nothing returns an empty results array, not an error');

// --- SessionDetailService::archived_session_detail()/archived_session_history():
// the read-only archived-session view's own data sources - keyed straight
// by agent_session_id, no sidecar/tmux-name lookup at all (a dormant
// session has neither). ---
$archivedDetail = SessionDetailService::archived_session_detail($uuid2);
assert_true($archivedDetail['ok'] ?? false, 'archived_session_detail: ok=true for a known transcript');
assert_equal($uuid2, $archivedDetail['agent_session_id'] ?? null, 'archived_session_detail: echoes the agent_session_id back');
assert_equal('/home/user/www/another-project', $archivedDetail['cwd'] ?? null, 'archived_session_detail: cwd via find_first_cwd()');
assert_equal('Fix the widget', $archivedDetail['title'] ?? null, 'archived_session_detail: title via the ai-title, same cascade as a live session');
assert_true(($archivedDetail['last_activity'] ?? null) !== null, 'archived_session_detail: last_activity is the file\'s own mtime');

$missingArchivedDetail = SessionDetailService::archived_session_detail('00000000-0000-4000-8000-000000000000');
assert_equal(false, $missingArchivedDetail['ok'] ?? null, 'archived_session_detail: ok=false for a well-formed but unknown id');

$archivedHistory = SessionDetailService::archived_session_history($uuid2, null, 10);
assert_true($archivedHistory['ok'] ?? false, 'archived_session_history: ok=true for a known transcript');
assert_equal(1, count($archivedHistory['entries'] ?? []), 'archived_session_history: the one real (non-meta) line comes back');
assert_equal('hi', $archivedHistory['entries'][0]['blocks'][0]['text'] ?? null, 'archived_session_history: it\'s the real user message');

$missingArchivedHistory = SessionDetailService::archived_session_history('00000000-0000-4000-8000-000000000000', null, 10);
assert_equal(false, $missingArchivedHistory['ok'] ?? null, 'archived_session_history: ok=false for a well-formed but unknown id');

// --- SessionDetailService::archived_session_history()/archived_session_detail():
// non-Claude backends must resolve real cwd (and, for OpenCode, title) instead
// of silently coming back null/wrong when fed a non-file "path". ---
try {
    $archivedHistoryRoot = sys_get_temp_dir() . '/sessioneer-test-archived-history-' . bin2hex(random_bytes(4));
    @mkdir($archivedHistoryRoot, 0700, true);

    $antigravityId = 'aaaaaaaa-bbbb-4ccc-8ddd-eeeeffffffff';
    @mkdir($archivedHistoryRoot . '/.gemini/antigravity-cli/brain/' . $antigravityId . '/.system_generated/logs', 0700, true);
    file_put_contents($archivedHistoryRoot . '/.gemini/antigravity-cli/brain/' . $antigravityId . '/.system_generated/logs/transcript_full.jsonl', implode("\n", [
        json_encode(['step_index' => 0, 'source' => 'USER_EXPLICIT', 'type' => 'USER_INPUT', 'status' => 'DONE', 'created_at' => '2026-08-30T12:00:00Z', 'content' => "<USER_REQUEST>\nAntigravity cwd fixture\n</USER_REQUEST>"]),
        json_encode(['step_index' => 1, 'source' => 'MODEL', 'type' => 'PLANNER_RESPONSE', 'status' => 'DONE', 'created_at' => '2026-08-30T12:00:01Z', 'content' => 'ok']),
    ]) . "\n");
    putenv("HOME_ROOT={$archivedHistoryRoot}");
    $antigravityHistory = SessionDetailService::archived_session_history($antigravityId, null, 10);
    assert_true($antigravityHistory['ok'] ?? false, 'archived_session_history: Antigravity ok=true');
    // Antigravity transcripts never embed a cwd field at all (see
    // AntigravityTranscriptService.php's own "don't embed a cwd field" comment) -
    // null here is correct, by-design behavior, not a bug this task fixes.
    assert_equal(null, $antigravityHistory['cwd'] ?? null, 'archived_session_history: Antigravity cwd is null - transcripts never carry one, by design');
    assert_equal('Antigravity cwd fixture', $antigravityHistory['entries'][0]['blocks'][0]['text'] ?? null, 'archived_session_history: Antigravity transcript entries still page normally');

    $opencodeDbPath = $archivedHistoryRoot . '/opencode.db';
    putenv("OPENCODE_DB_PATH={$opencodeDbPath}");
    $pdo = new PDO('sqlite:' . $opencodeDbPath);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->exec('CREATE TABLE session (id TEXT PRIMARY KEY, slug TEXT NOT NULL, directory TEXT NOT NULL, title TEXT NOT NULL, project_id TEXT NOT NULL, agent TEXT, model TEXT, version TEXT NOT NULL, time_created INTEGER NOT NULL, time_updated INTEGER NOT NULL, tokens_input INTEGER DEFAULT 0, tokens_output INTEGER DEFAULT 0, tokens_reasoning INTEGER DEFAULT 0, tokens_cache_read INTEGER DEFAULT 0, tokens_cache_write INTEGER DEFAULT 0, cost REAL DEFAULT 0)');
    $pdo->exec('CREATE TABLE message (id TEXT PRIMARY KEY, session_id TEXT NOT NULL, time_created INTEGER NOT NULL, time_updated INTEGER NOT NULL, data TEXT NOT NULL)');
    $pdo->exec('CREATE TABLE part (id TEXT PRIMARY KEY, message_id TEXT NOT NULL, session_id TEXT NOT NULL, time_created INTEGER NOT NULL, time_updated INTEGER NOT NULL, data TEXT NOT NULL)');
    $opencodeId = 'ses_historyfixture1';
    $opencodeCwd = '/home/user/www/opencode-history-fixture';
    $stmt = $pdo->prepare('INSERT INTO session (id, slug, directory, title, project_id, agent, model, version, time_created, time_updated) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)');
    $stmt->execute([$opencodeId, 'history-fixture', $opencodeCwd, 'OpenCode history fixture', 'proj-history', 'build', 'opencode/test', '1.18.21', 1000, 2000]);
    $stmt = $pdo->prepare('INSERT INTO message (id, session_id, time_created, time_updated, data) VALUES (?, ?, ?, ?, ?)');
    $stmt->execute(['msg-history-1', $opencodeId, 1001, 1001, json_encode(['role' => 'user', 'time' => ['created' => 1001000]])]);
    $stmt = $pdo->prepare('INSERT INTO part (id, message_id, session_id, time_created, time_updated, data) VALUES (?, ?, ?, ?, ?, ?)');
    $stmt->execute(['part-history-1', 'msg-history-1', $opencodeId, 1001, 1001, json_encode(['type' => 'text', 'text' => 'OpenCode cwd fixture'])]);

    $opencodeDetail = SessionDetailService::archived_session_detail($opencodeId);
    assert_true($opencodeDetail['ok'] ?? false, 'archived_session_detail: OpenCode ok=true');
    assert_equal($opencodeCwd, $opencodeDetail['cwd'] ?? null, 'archived_session_detail: OpenCode cwd comes from the session row');
    assert_equal('OpenCode history fixture', $opencodeDetail['title'] ?? null, 'archived_session_detail: OpenCode title comes from the session row, not the cwd basename');

    $opencodeHistory = SessionDetailService::archived_session_history($opencodeId, null, 10);
    assert_true($opencodeHistory['ok'] ?? false, 'archived_session_history: OpenCode ok=true');
    assert_equal($opencodeCwd, $opencodeHistory['cwd'] ?? null, 'archived_session_history: OpenCode cwd comes from the session row');
    assert_equal('OpenCode cwd fixture', $opencodeHistory['entries'][0]['blocks'][0]['text'] ?? null, 'archived_session_history: OpenCode transcript entries still page normally');

    $codexBridgeSocket = $archivedHistoryRoot . '/codex-bridge.sock';
    $codexBridgeScript = $archivedHistoryRoot . '/codex-bridge.php';
    $codexCwd = '/home/user/www/codex-history-fixture';
    $codexId = 'aaaaaaaa-1111-4111-8111-aaaaaaaaaaaa';
    $codexHome = $archivedHistoryRoot . '/codex-home';
    @mkdir($codexHome . '/.codex/archived_sessions', 0700, true);
    file_put_contents($codexHome . '/.codex/archived_sessions/rollout-2026-08-30T12-00-00-' . $codexId . '.jsonl', json_encode(['type' => 'session_meta', 'payload' => ['session_id' => $codexId, 'timestamp' => '2026-08-30T12:00:00Z', 'cwd' => $codexCwd]]) . "\n");
    file_put_contents($codexBridgeScript, <<<'PHP'
<?php
declare(strict_types=1);

$line = fgets(STDIN);
$request = is_string($line) ? json_decode($line, true) : null;
$threadId = is_array($request) && is_string($request['params']['threadId'] ?? null) ? $request['params']['threadId'] : 'codex-history-fixture';
$response = [
    'ok' => true,
    'result' => [
        'thread' => [
            'id' => $threadId,
            'cwd' => '/home/user/www/codex-history-fixture',
            'turns' => [[
                'startedAt' => 1725033600,
                'items' => [
                    ['type' => 'userMessage', 'content' => [['type' => 'text', 'text' => 'Codex cwd fixture']]],
                ],
            ]],
        ],
    ],
];

echo json_encode($response, JSON_UNESCAPED_SLASHES) . PHP_EOL;
PHP);
    $codexBridgeHarness = start_harness(['php', $codexBridgeScript], $codexBridgeSocket);
    $originalCodexBridgeSocket = getenv('CODEX_BRIDGE_SOCKET');
    putenv("CODEX_BRIDGE_SOCKET={$codexBridgeSocket}");
    putenv("HOME_ROOT={$codexHome}");
    $codexHistory = SessionDetailService::archived_session_history($codexId, null, 10);
    assert_true($codexHistory['ok'] ?? false, 'archived_session_history: Codex ok=true');
    assert_equal($codexCwd, $codexHistory['cwd'] ?? null, 'archived_session_history: Codex cwd comes from thread metadata');
    assert_equal('Codex cwd fixture', $codexHistory['entries'][0]['blocks'][0]['text'] ?? null, 'archived_session_history: Codex transcript entries still page normally');
} finally {
    if (isset($codexBridgeHarness)) {
        stop_harness($codexBridgeHarness, $codexBridgeSocket);
    }
    if (isset($codexBridgeScript)) {
        @unlink($codexBridgeScript);
    }
    if (isset($opencodeDbPath)) {
        @unlink($opencodeDbPath);
    }
    if (isset($archivedHistoryRoot)) {
        @unlink($archivedHistoryRoot . '/.gemini/antigravity-cli/brain/' . ($antigravityId ?? '') . '/.system_generated/logs/transcript_full.jsonl');
        @rmdir($archivedHistoryRoot . '/.gemini/antigravity-cli/brain/' . ($antigravityId ?? '') . '/.system_generated/logs');
        @rmdir($archivedHistoryRoot . '/.gemini/antigravity-cli/brain/' . ($antigravityId ?? '') . '/.system_generated');
        @rmdir($archivedHistoryRoot . '/.gemini/antigravity-cli/brain/' . ($antigravityId ?? ''));
        @rmdir($archivedHistoryRoot . '/.gemini/antigravity-cli/brain');
        @rmdir($archivedHistoryRoot . '/.gemini/antigravity-cli');
        @rmdir($archivedHistoryRoot . '/.gemini');
        @unlink($archivedHistoryRoot . '/codex-home/.codex/archived_sessions/rollout-2026-08-30T12-00-00-' . ($codexId ?? '') . '.jsonl');
        @rmdir($archivedHistoryRoot . '/codex-home/.codex/archived_sessions');
        @rmdir($archivedHistoryRoot . '/codex-home/.codex');
        @rmdir($archivedHistoryRoot . '/codex-home');
        @rmdir($archivedHistoryRoot);
    }
    if (isset($originalCodexBridgeSocket) && is_string($originalCodexBridgeSocket) && $originalCodexBridgeSocket !== '') {
        putenv("CODEX_BRIDGE_SOCKET={$originalCodexBridgeSocket}");
    } else {
        putenv('CODEX_BRIDGE_SOCKET');
    }
    putenv("OPENCODE_DB_PATH");
    putenv("HOME_ROOT={$fakeHome}");
}

@unlink($fakeHome . '/.claude/projects/-another-project/' . $uuid2 . '.jsonl');
@rmdir($fakeHome . '/.claude/projects/-another-project');

@unlink($fakeHome . '/.claude/projects/-some-project/' . $uuid . '.jsonl');
@rmdir($fakeHome . '/.claude/projects/-some-project');
@rmdir($fakeHome . '/.claude/projects');
@rmdir($fakeHome . '/.claude');
@rmdir($fakeHome);
putenv('HOME_ROOT');

// --- TranscriptService::parse_transcript_line(): a message that's only a thinking block
// (the common shape - Claude Code writes thinking as its own separate
// JSONL line) is treated the same as a meta-only line, not an empty
// bubble with a role header and nothing in it. ---
assert_equal(null, TranscriptService::parse_transcript_line('{"type":"assistant","message":{"role":"assistant","content":[{"type":"thinking","thinking":"hmm"}]}}'), 'parse_transcript_line: thinking-only message -> null, not an empty entry');

$mixedLine = TranscriptService::parse_transcript_line('{"type":"assistant","message":{"role":"assistant","content":[{"type":"thinking","thinking":"hmm"},{"type":"text","text":"Done."}]}}');
assert_equal([['kind' => 'text', 'text' => 'Done.']], $mixedLine['blocks'] ?? null, 'parse_transcript_line: thinking alongside real content is dropped, the rest is kept');

// --- TranscriptService::read_transcript_page(): pagination over the real fixture file ---
// 10 raw lines; renderable ones are at raw line numbers 2,3,4,5,8
// (mode/permission-mode/malformed/null-message are meta/invalid, and line
// 7's thinking-only message is dropped per the above) - see the fixture
// file for the full content.
$page1 = TranscriptService::read_transcript_page(FIXTURE_TRANSCRIPT, null, 2);
assert_true($page1['ok'] ?? false, 'read_transcript_page: page1 ok=true');
assert_equal(2, count($page1['entries'] ?? []), 'read_transcript_page: page1 has 2 entries');
assert_equal(['tool_result', 'text'], array_map(fn($e) => $e['blocks'][0]['kind'], $page1['entries']), 'read_transcript_page: page1 is lines 5 and 8 - the thinking-only line 7 is skipped for free, not counted against the page');
assert_equal(5, $page1['next_before'] ?? null, 'read_transcript_page: page1 next_before points just before line 5');
assert_true($page1['has_more'] ?? false, 'read_transcript_page: page1 has_more=true');

$page2 = TranscriptService::read_transcript_page(FIXTURE_TRANSCRIPT, $page1['next_before'], 2);
assert_equal(['text', 'tool_use'], array_map(fn($e) => $e['blocks'][0]['kind'], $page2['entries']), 'read_transcript_page: page2 is lines 3-4 (text, tool_use)');
assert_equal(3, $page2['next_before'] ?? null, 'read_transcript_page: page2 next_before points just before line 3');
assert_true($page2['has_more'] ?? false, 'read_transcript_page: page2 has_more=true');

// Line 1 is meta-only ("mode") - the walk consumes it for free while
// filling this last page, landing on has_more=false directly rather than
// leaving a dangling page with nothing in it.
$page3 = TranscriptService::read_transcript_page(FIXTURE_TRANSCRIPT, $page2['next_before'], 2);
assert_equal(['text'], array_map(fn($e) => $e['blocks'][0]['kind'], $page3['entries']), 'read_transcript_page: page3 is just line 2 (the original user message)');
assert_equal('Fix the bug', $page3['entries'][0]['blocks'][0]['text'] ?? null, 'read_transcript_page: page3 entry is the original user message');
assert_equal(null, $page3['next_before'], 'read_transcript_page: page3 next_before is null (reached the start of the file)');
assert_equal(false, $page3['has_more'] ?? null, 'read_transcript_page: page3 has_more=false');

$missing = TranscriptService::read_transcript_page('/does/not/exist.jsonl', null, 10);
assert_equal(false, $missing['ok'] ?? null, 'read_transcript_page: missing file -> ok=false');

// --- TranscriptService::read_transcript_page($untilRealUserMessage=true):
// backs session.php's "Load until last message" button (Andres's own ask
// 2026-08-24) - stops the moment it includes a REAL user message
// (role="user" AND not a bare tool_result) rather than after $limit
// entries, which is ignored entirely in this mode. The fixture's only two
// role="user" entries are line 5 (a tool_result Claude Code fed back in,
// not something a human typed) and line 2 ("Fix the bug", the actual
// human-typed message) - a real regression here would most likely stop
// too early, at line 5's tool_result, so $limit=1 is deliberately far
// smaller than the 5 real entries actually between the end of the file and
// line 2, proving $limit truly is ignored, not just larger than needed.
$untilUser = TranscriptService::read_transcript_page(FIXTURE_TRANSCRIPT, null, 1, true);
assert_true($untilUser['ok'] ?? false, 'read_transcript_page(untilRealUserMessage): ok=true');
assert_equal(
    ['text', 'text', 'tool_use', 'tool_result', 'text'],
    array_map(fn($e) => $e['blocks'][0]['kind'], $untilUser['entries'] ?? []),
    'read_transcript_page(untilRealUserMessage): walks all the way back past line 5\'s tool_result (which looks like a user entry but isn\'t one) to line 2, the real user message - despite limit=1'
);
assert_equal('user', $untilUser['entries'][0]['role'] ?? null, 'read_transcript_page(untilRealUserMessage): the oldest (first) entry in the page is role=user');
assert_equal('Fix the bug', $untilUser['entries'][0]['blocks'][0]['text'] ?? null, 'read_transcript_page(untilRealUserMessage): the oldest entry in the page is the real user message itself');
assert_equal(2, $untilUser['next_before'] ?? null, 'read_transcript_page(untilRealUserMessage): next_before points just before the found user message\'s own line');
assert_true($untilUser['has_more'] ?? false, 'read_transcript_page(untilRealUserMessage): has_more=true (line 1, the meta-only "mode" line, hasn\'t actually been scanned yet - a real page still exists behind it, even if empty)');

// A follow-up page picking up exactly where the untilRealUserMessage page
// left off (next_before=2) has nothing left to find but the meta-only
// "mode" line - genuinely empty, not an error, and correctly reports
// has_more=false since the walk actually reached the start of the file
// this time.
$afterUntilUser = TranscriptService::read_transcript_page(FIXTURE_TRANSCRIPT, $untilUser['next_before'], 30);
assert_equal([], $afterUntilUser['entries'] ?? null, 'read_transcript_page: the page right after an untilRealUserMessage page is empty (only the meta-only "mode" line remains)');
assert_equal(null, $afterUntilUser['next_before'], 'read_transcript_page: that empty page correctly reports next_before=null');
assert_equal(false, $afterUntilUser['has_more'] ?? null, 'read_transcript_page: that empty page correctly reports has_more=false');

// Sad path: no real user message anywhere before the start of the file -
// the walk must still terminate cleanly at index 0 rather than looping
// forever or erroring, reporting has_more=false same as an ordinary page
// that happens to reach the start of the file.
$noUserFixture = sys_get_temp_dir() . '/sessioneer-test-no-user-message-' . getmypid() . '.jsonl';
file_put_contents($noUserFixture, implode("\n", [
    '{"type":"assistant","message":{"role":"assistant","content":[{"type":"tool_use","name":"Bash","input":{"command":"ls"}}]}}',
    '{"type":"user","message":{"role":"user","content":[{"type":"tool_result","tool_use_id":"x","content":[{"type":"text","text":"file1"}]}]}}',
    '{"type":"assistant","message":{"role":"assistant","content":[{"type":"text","text":"Done."}]}}',
]) . "\n");

$noUserPage = TranscriptService::read_transcript_page($noUserFixture, null, 1, true);
assert_true($noUserPage['ok'] ?? false, 'read_transcript_page(untilRealUserMessage, no real user message in the whole file): ok=true, not an error');
assert_equal(3, count($noUserPage['entries'] ?? []), 'read_transcript_page(untilRealUserMessage, no real user message in the whole file): every entry in the file is returned');
assert_equal(false, $noUserPage['has_more'] ?? null, 'read_transcript_page(untilRealUserMessage, no real user message in the whole file): has_more=false - the walk reached the real start of the file, not the safety cap');

@unlink($noUserFixture);

// --- TranscriptService::read_transcript_page_since(): the regular-poll
// counterpart - reads FORWARD from just after a given line, oldest-first
// (no reversal needed, unlike read_transcript_page()'s backward walk) -
// renderable lines in the fixture are 2, 3, 4, 5, 8 (see the file itself:
// 1/6 are meta, 7 is thinking-only, 9 is malformed, 10 has no message). ---
$since2 = TranscriptService::read_transcript_page_since(FIXTURE_TRANSCRIPT, 2, 10);
assert_true($since2['ok'] ?? false, 'read_transcript_page_since: ok=true');
assert_equal([3, 4, 5, 8], array_column($since2['entries'], 'line'), 'read_transcript_page_since: after line 2, every renderable line that follows it comes back in ascending order');

$since5 = TranscriptService::read_transcript_page_since(FIXTURE_TRANSCRIPT, 5, 10);
assert_equal([8], array_column($since5['entries'], 'line'), 'read_transcript_page_since: after line 5, only line 8 remains');

$since8 = TranscriptService::read_transcript_page_since(FIXTURE_TRANSCRIPT, 8, 10);
assert_equal([], $since8['entries'], 'read_transcript_page_since: after the last renderable line, nothing comes back');

$since0Capped = TranscriptService::read_transcript_page_since(FIXTURE_TRANSCRIPT, 0, 2);
assert_equal([2, 3], array_column($since0Capped['entries'], 'line'), 'read_transcript_page_since: $limit caps how many entries come back even when more exist further in the file');

$sinceMissing = TranscriptService::read_transcript_page_since('/does/not/exist.jsonl', 0, 10);
assert_equal(false, $sinceMissing['ok'] ?? null, 'read_transcript_page_since: missing file -> ok=false');

// --- TranscriptService::transcript_attachments_from_tool_use_result()/
// parse_transcript_line(): a SendUserFile tool_result's real file
// metadata (path, size, isImage, media_type, file_uuid) lives on the
// outer JSONL line's toolUseResult.attachments field, not in the content
// blocks themselves (verified live 2026-08-04 against a real captured
// SendUserFile call) - same "outer field, threaded onto the tool_result
// block" pattern as agent_type above, just a different field. The real
// host path is deliberately dropped from the rendered shape (never sent
// to the browser) in favor of just a filename derived from it. ---
assert_equal([], TranscriptService::transcript_attachments_from_tool_use_result(null), 'transcript_attachments_from_tool_use_result: not an array -> []');
assert_equal([], TranscriptService::transcript_attachments_from_tool_use_result(['status' => 'completed']), 'transcript_attachments_from_tool_use_result: no attachments field -> []');
assert_equal(
    [['file_uuid' => 'abc-123', 'filename' => 'footer-before.png', 'size' => 63182, 'isImage' => true, 'media_type' => 'image/png']],
    TranscriptService::transcript_attachments_from_tool_use_result([
        'attachments' => [['path' => '/tmp/claude-1000/scratchpad/footer-before.png', 'size' => 63182, 'isImage' => true, 'media_type' => 'image/png', 'pathValidated' => true, 'file_uuid' => 'abc-123']],
    ]),
    'transcript_attachments_from_tool_use_result: real path collapsed to its basename, other fields passed through'
);
assert_equal(
    [],
    TranscriptService::transcript_attachments_from_tool_use_result(['attachments' => [['size' => 10, 'isImage' => false]]]),
    'transcript_attachments_from_tool_use_result: an entry missing path/file_uuid is skipped, not half-rendered'
);

$sendUserFileLine = TranscriptService::parse_transcript_line(json_encode([
    'type' => 'user',
    'message' => ['role' => 'user', 'content' => [[
        'type' => 'tool_result',
        'content' => 'Sent 1 file(s) to the user.',
    ]]],
    'toolUseResult' => [
        'attachments' => [['path' => '/tmp/x/report.pdf', 'size' => 4096, 'isImage' => false, 'media_type' => 'application/pdf', 'pathValidated' => true, 'file_uuid' => 'file-uuid-1']],
    ],
]));
assert_equal(
    [['file_uuid' => 'file-uuid-1', 'filename' => 'report.pdf', 'size' => 4096, 'isImage' => false, 'media_type' => 'application/pdf']],
    $sendUserFileLine['blocks'][0]['attachments'] ?? null,
    'parse_transcript_line: SendUserFile tool_result block carries attachments from the outer toolUseResult field'
);

$nonAttachmentToolResultLine = TranscriptService::parse_transcript_line('{"type":"user","message":{"role":"user","content":[{"type":"tool_result","content":"ok"}]}}');
assert_true(!array_key_exists('attachments', $nonAttachmentToolResultLine['blocks'][0]), 'parse_transcript_line: a plain tool_result never gets an attachments field');

// --- TranscriptService::read_attachment(): re-reads a real transcript
// line by number and returns a specific attachment's real file bytes as
// base64 - the browser only ever supplies (session, line, file_uuid),
// never a real path, so this re-derives the path itself from the
// transcript rather than trusting one from the caller. ---
$attachmentFixtureDir = sys_get_temp_dir() . '/sessioneer-test-attachment-' . getmypid();
@mkdir($attachmentFixtureDir, 0700, true);
$attachmentFile = $attachmentFixtureDir . '/hello.txt';
file_put_contents($attachmentFile, 'hello attachment bytes');
$attachmentTranscript = $attachmentFixtureDir . '/transcript.jsonl';
file_put_contents($attachmentTranscript, json_encode([
    'type' => 'user',
    'message' => ['role' => 'user', 'content' => [['type' => 'tool_result', 'content' => 'Sent 1 file(s) to the user.']]],
    'toolUseResult' => ['attachments' => [['path' => $attachmentFile, 'size' => 22, 'isImage' => false, 'media_type' => 'text/plain', 'pathValidated' => true, 'file_uuid' => 'the-uuid']]],
]) . "\n");

$readOk = TranscriptService::read_attachment($attachmentTranscript, 1, 'the-uuid');
assert_true($readOk['ok'] ?? false, 'read_attachment: real line + matching file_uuid -> ok=true');
assert_equal(base64_encode('hello attachment bytes'), $readOk['data'] ?? null, 'read_attachment: returns the real file bytes as base64');
assert_equal('text/plain', $readOk['media_type'] ?? null, 'read_attachment: returns the media_type from the transcript');
assert_equal('hello.txt', $readOk['filename'] ?? null, 'read_attachment: filename derived from the real path\'s basename');
assert_equal(22, $readOk['size'] ?? null, 'read_attachment: size reflects the actual bytes read');

$wrongLine = TranscriptService::read_attachment($attachmentTranscript, 99, 'the-uuid');
assert_equal(false, $wrongLine['ok'] ?? null, 'read_attachment: out-of-range line -> ok=false');

$wrongUuid = TranscriptService::read_attachment($attachmentTranscript, 1, 'not-the-uuid');
assert_equal(false, $wrongUuid['ok'] ?? null, 'read_attachment: file_uuid not found on that line -> ok=false');

@unlink($attachmentFile);
$missingFile = TranscriptService::read_attachment($attachmentTranscript, 1, 'the-uuid');
assert_equal(false, $missingFile['ok'] ?? null, 'read_attachment: attachment path found in transcript but no longer exists on disk -> ok=false');

@unlink($attachmentTranscript);
@rmdir($attachmentFixtureDir);

// --- TranscriptService::find_latest_ai_title(): the primary session-title
// source for the unify-claude-sessions plan (works for a dormant session
// exactly as well as a live one, unlike a live-pane-title scrape) - keeps
// the LATEST ai-title line, since a long conversation can get more than
// one as Claude Code refines it. ---
$aiTitleFixture = sys_get_temp_dir() . '/sessioneer-test-ai-title-' . getmypid() . '.jsonl';
file_put_contents($aiTitleFixture, implode("\n", [
    '{"type":"user","message":{"role":"user","content":[{"type":"text","text":"hi"}]}}',
    '{"type":"ai-title","aiTitle":"First title","sessionId":"abc"}',
    '{"type":"assistant","message":{"role":"assistant","content":[{"type":"text","text":"ok"}]}}',
    '{"type":"ai-title","aiTitle":"Second, more accurate title","sessionId":"abc"}',
]) . "\n");

assert_equal('Second, more accurate title', TranscriptService::find_latest_ai_title($aiTitleFixture), 'find_latest_ai_title: keeps the LATEST ai-title line, not the first');
assert_equal(null, TranscriptService::find_latest_ai_title('/does/not/exist.jsonl'), 'find_latest_ai_title: missing file -> null');

file_put_contents($aiTitleFixture, "{\"type\":\"ai-title\",\"aiTitle\":\"\",\"sessionId\":\"abc\"}\n{\"type\":\"user\",\"message\":{\"role\":\"user\",\"content\":[{\"type\":\"text\",\"text\":\"hi\"}]}}\n");
assert_equal(null, TranscriptService::find_latest_ai_title($aiTitleFixture), 'find_latest_ai_title: a blank aiTitle is not treated as a real title');

// --- find_latest_ai_title() only reads the file's TAIL
// (AI_TITLE_TAIL_SCAN_BYTES), not the whole thing - real transcripts can
// be tens of MB, and this runs on every dashboard poll. Padding a title
// beyond that window with filler proves it's genuinely ignored, not just
// coincidentally not the "latest" by scan order. ---
$padding = str_repeat('{"type":"user","message":{"role":"user","content":[{"type":"text","text":"filler"}]}}' . "\n", 5000);
file_put_contents($aiTitleFixture, $padding);
$outOfWindowTitle = '{"type":"ai-title","aiTitle":"Title before the padding","sessionId":"abc"}' . "\n";
file_put_contents($aiTitleFixture, $outOfWindowTitle, FILE_APPEND);
file_put_contents($aiTitleFixture, $padding, FILE_APPEND);
assert_true(
    filesize($aiTitleFixture) > TranscriptService::AI_TITLE_TAIL_SCAN_BYTES,
    'find_latest_ai_title tail-window test setup: fixture is actually larger than the tail scan window'
);
assert_equal(null, TranscriptService::find_latest_ai_title($aiTitleFixture), 'find_latest_ai_title: an ai-title outside the tail scan window is not found');

file_put_contents($aiTitleFixture, '{"type":"ai-title","aiTitle":"Title inside the window","sessionId":"abc"}' . "\n", FILE_APPEND);
assert_equal('Title inside the window', TranscriptService::find_latest_ai_title($aiTitleFixture), 'find_latest_ai_title: an ai-title within the tail scan window is still found in a file larger than the window');

// --- /rename (custom-title / agent-name) beats an ai-title; a headless session
// with neither falls back to its first real prompt, shortened. ---
file_put_contents($aiTitleFixture, implode("\n", [
    '{"type":"ai-title","aiTitle":"Generated","sessionId":"abc"}',
    '{"type":"custom-title","customTitle":"My rename","sessionId":"abc"}',
    '{"type":"ai-title","aiTitle":"Generated later","sessionId":"abc"}',
]) . "\n");
assert_equal('My rename', TranscriptService::find_latest_ai_title($aiTitleFixture), 'find_latest_ai_title: a custom-title (rename) wins over a later ai-title');

file_put_contents($aiTitleFixture, implode("\n", [
    '{"type":"queue-operation","operation":"enqueue","content":"ignored"}',
    '{"type":"user","isMeta":true,"message":{"role":"user","content":"meta caveat"}}',
    '{"type":"user","message":{"role":"user","content":"<command-name>/clear</command-name>"}}',
    '{"type":"user","message":{"role":"user","content":[{"type":"text","text":"  Fix the\n   login   bug  ' . str_repeat('x', 200) . '"}]}}',
    '{"type":"user","message":{"role":"user","content":"second prompt"}}',
]) . "\n");
$firstPrompt = TranscriptService::find_display_title($aiTitleFixture);
assert_true(str_starts_with((string)$firstPrompt, 'Fix the login bug xxx'), 'find_display_title: no ai-title -> first real prompt, whitespace collapsed, meta/wrapper lines skipped');
assert_equal(TranscriptService::TITLE_MAX_CHARS, mb_strlen((string)$firstPrompt), 'find_display_title: a long prompt is cut to TITLE_MAX_CHARS');

file_put_contents($aiTitleFixture, '{"type":"ai-title","aiTitle":"Real title","sessionId":"abc"}' . "\n" . '{"type":"user","message":{"role":"user","content":"a prompt"}}' . "\n");
assert_equal('Real title', TranscriptService::find_display_title($aiTitleFixture), 'find_display_title: an ai-title beats the first prompt');

file_put_contents($aiTitleFixture, '{"type":"assistant","message":{"role":"assistant","content":"hi"}}' . "\n" . "not json\n");
assert_equal(null, TranscriptService::find_display_title($aiTitleFixture), 'find_display_title: no title and no prompt -> null (caller falls back to the workdir)');
assert_equal(null, TranscriptService::find_display_title('/does/not/exist.jsonl'), 'find_display_title: missing file -> null');

@unlink($aiTitleFixture);

// --- TranscriptService::find_latest_model(): backs session.php's "Select
// model" dropdown - the raw model ID off the most recent assistant
// message, same "keep the latest" tail-scan shape as find_latest_ai_title()
// above. ---
$modelFixture = sys_get_temp_dir() . '/sessioneer-test-latest-model-' . getmypid() . '.jsonl';
file_put_contents($modelFixture, implode("\n", [
    '{"type":"user","message":{"role":"user","content":[{"type":"text","text":"hi"}]}}',
    '{"type":"assistant","message":{"role":"assistant","model":"claude-sonnet-5","content":[{"type":"text","text":"ok"}]}}',
    '{"type":"user","message":{"role":"user","content":[{"type":"tool_result","tool_use_id":"x","content":[{"type":"text","text":"file1"}]}]}}',
    '{"type":"assistant","message":{"role":"assistant","model":"claude-opus-5","content":[{"type":"text","text":"switched"}]}}',
]) . "\n");

assert_equal('claude-opus-5', TranscriptService::find_latest_model($modelFixture), 'find_latest_model: keeps the LATEST assistant message\'s model, not the first');
assert_equal(null, TranscriptService::find_latest_model('/does/not/exist.jsonl'), 'find_latest_model: missing file -> null');

file_put_contents($modelFixture, '{"type":"user","message":{"role":"user","content":[{"type":"text","text":"hi"}]}}' . "\n");
assert_equal(null, TranscriptService::find_latest_model($modelFixture), 'find_latest_model: no assistant message yet this session -> null');

@unlink($modelFixture);

// --- SelectableModel::family_from_raw_model(): maps a raw transcript model
// ID to this app's own PICKER_OPTIONS key by family prefix - the exact
// version within a family isn't distinguished, matching the real /model
// picker's own granularity. ---
assert_equal('sonnet', SelectableModel::family_from_raw_model('claude-sonnet-5'), 'family_from_raw_model: claude-sonnet-5 -> sonnet');
assert_equal('sonnet', SelectableModel::family_from_raw_model('claude-sonnet-4-5-20250929'), 'family_from_raw_model: an older dated pin within the family still resolves');
assert_equal('opus', SelectableModel::family_from_raw_model('claude-opus-5'), 'family_from_raw_model: claude-opus-5 -> opus');
assert_equal('haiku', SelectableModel::family_from_raw_model('claude-haiku-4-5'), 'family_from_raw_model: claude-haiku-4-5 -> haiku');
assert_equal('fable', SelectableModel::family_from_raw_model('claude-fable-5'), 'family_from_raw_model: claude-fable-5 -> fable');
assert_equal(null, SelectableModel::family_from_raw_model('some-other-provider-model'), 'family_from_raw_model: an unrecognized prefix -> null, not a guess');
assert_equal(null, SelectableModel::family_from_raw_model('default'), 'family_from_raw_model: "default" itself is never returned - it has no raw model ID of its own to match against');

// --- TranscriptService::find_latest_todo_list(): the sidebar's live task
// checklist source - a TodoWrite tool call rewrites the FULL list every
// time (never a diff), so only the latest one matters, same "keep the
// latest" shape as ai-title above. Unlike ai-title though, this one falls
// back to a full-file scan when the tail window misses - a todo list can
// legitimately go untouched for a long stretch of a long conversation
// while still being current, unlike a title (rewritten near-continuously
// in practice), so "not in the tail" must NOT mean "doesn't exist". ---
$todoFixture = sys_get_temp_dir() . '/sessioneer-test-todo-list-' . getmypid() . '.jsonl';

file_put_contents($todoFixture, implode("\n", [
    '{"type":"user","message":{"role":"user","content":[{"type":"text","text":"hi"}]}}',
    '{"type":"assistant","isSidechain":false,"message":{"role":"assistant","content":[{"type":"tool_use","id":"t1","name":"TodoWrite","input":{"todos":[{"content":"First task","activeForm":"Doing first task","status":"pending"}]}}]}}',
    '{"type":"assistant","isSidechain":false,"message":{"role":"assistant","content":[{"type":"tool_use","id":"t2","name":"TodoWrite","input":{"todos":[{"content":"First task","activeForm":"Doing first task","status":"completed"},{"content":"Second task","activeForm":"Doing second task","status":"in_progress"}]}}]}}',
]) . "\n");

assert_equal(
    [['content' => 'First task', 'activeForm' => 'Doing first task', 'status' => 'completed'], ['content' => 'Second task', 'activeForm' => 'Doing second task', 'status' => 'in_progress']],
    TranscriptService::find_latest_todo_list($todoFixture),
    'find_latest_todo_list: keeps the LATEST TodoWrite call (full list, not merged with the earlier one)'
);
assert_equal(null, TranscriptService::find_latest_todo_list('/does/not/exist.jsonl'), 'find_latest_todo_list: missing file -> null');

file_put_contents($todoFixture, '{"type":"user","message":{"role":"user","content":[{"type":"text","text":"hi"}]}}' . "\n");
assert_equal(null, TranscriptService::find_latest_todo_list($todoFixture), 'find_latest_todo_list: a session that never called TodoWrite -> null');

file_put_contents($todoFixture, implode("\n", [
    '{"type":"assistant","isSidechain":false,"message":{"role":"assistant","content":[{"type":"tool_use","id":"t1","name":"TodoWrite","input":{"todos":[{"content":"Real task","activeForm":"Doing it","status":"pending"}]}}]}}',
    '{"type":"assistant","isSidechain":true,"message":{"role":"assistant","content":[{"type":"tool_use","id":"t2","name":"TodoWrite","input":{"todos":[{"content":"Subagent own task","activeForm":"Doing it","status":"pending"}]}}]}}',
]) . "\n");
assert_equal(
    [['content' => 'Real task', 'activeForm' => 'Doing it', 'status' => 'pending']],
    TranscriptService::find_latest_todo_list($todoFixture),
    'find_latest_todo_list: a SUBAGENT\'s own TodoWrite call (isSidechain:true) is ignored - only the top-level agent\'s own list counts'
);

file_put_contents($todoFixture, '{"type":"assistant","isSidechain":false,"message":{"role":"assistant","content":[{"type":"tool_use","id":"t1","name":"TodoWrite","input":{"todos":[{"content":"Has status","status":"pending"},{"status":"pending"},"not even an object",{"content":"Missing status"}]}}]}}' . "\n");
assert_equal(
    [['content' => 'Has status', 'activeForm' => 'Has status', 'status' => 'pending']],
    TranscriptService::find_latest_todo_list($todoFixture),
    'find_latest_todo_list: a malformed todo item (missing content/status, or not even an object) is skipped rather than crashing or polluting the list; activeForm falls back to content when absent'
);

file_put_contents($todoFixture, '{"type":"assistant","isSidechain":false,"message":{"role":"assistant","content":[{"type":"tool_use","id":"t1","name":"TodoWrite","input":{"todos":[]}}]}}' . "\n");
assert_equal([], TranscriptService::find_latest_todo_list($todoFixture), 'find_latest_todo_list: an explicitly emptied list ([]) is returned as [], not confused with null ("never called TodoWrite")');

// The key behavioral difference from find_latest_ai_title(): a TodoWrite
// call OUTSIDE the tail scan window must still be found via the fallback
// full-file scan, not reported as null.
$padding = str_repeat('{"type":"user","message":{"role":"user","content":[{"type":"text","text":"filler"}]}}' . "\n", 5000);
file_put_contents($todoFixture, $padding);
file_put_contents($todoFixture, '{"type":"assistant","isSidechain":false,"message":{"role":"assistant","content":[{"type":"tool_use","id":"t1","name":"TodoWrite","input":{"todos":[{"content":"Old task, set up early","status":"in_progress"}]}}]}}' . "\n", FILE_APPEND);
file_put_contents($todoFixture, $padding, FILE_APPEND);
assert_true(
    filesize($todoFixture) > TranscriptService::TODO_LIST_TAIL_SCAN_BYTES,
    'find_latest_todo_list tail-fallback test setup: fixture is actually larger than the tail scan window'
);
assert_equal(
    [['content' => 'Old task, set up early', 'activeForm' => 'Old task, set up early', 'status' => 'in_progress']],
    TranscriptService::find_latest_todo_list($todoFixture),
    'find_latest_todo_list: a TodoWrite call OUTSIDE the tail scan window is STILL found via the full-file fallback scan - unlike find_latest_ai_title(), a todo list can go untouched for a long stretch while still being current'
);

@unlink($todoFixture);

// --- TranscriptService::find_current_task_list(): the TodoWrite REPLACEMENT
// sidebar source (TaskCreate/TaskUpdate - the Task tool family, opt-in via
// this app's own enableTaskTools checkbox). Unlike TodoWrite, this is CRUD,
// not "one call carries the full list" - each fixture line pair below is a
// tool_use call + its matching tool_result, correlated by tool_use_id, with
// the STRUCTURED toolUseResult field the real assertions are keyed off (not
// the human-readable text) - see find_current_task_list()'s own docblock for
// why: this exact field/shape was captured live 2026-08-22 against a real
// Claude Code v2.1.241 transcript by actually calling these tools in the
// session that built this feature, not guessed or taken from public docs
// (which don't document toolUseResult at all). ---

/**
 * @param array<string, mixed> $input
 */
function task_tool_use_jsonl(string $toolUseId, string $name, array $input, bool $sidechain = false): string
{
    return json_encode([
        'type' => 'assistant',
        'isSidechain' => $sidechain,
        'message' => [
            'role' => 'assistant',
            'content' => [[
                'type' => 'tool_use',
                'id' => $toolUseId,
                'name' => $name,
                'input' => $input,
            ]],
        ],
    ]);
}

/**
 * @param array<string, mixed>|null $toolUseResult
 */
function task_tool_result_jsonl(string $toolUseId, string $text, ?array $toolUseResult): string
{
    $line = [
        'type' => 'user',
        'isSidechain' => false,
        'message' => [
            'role' => 'user',
            'content' => [[
                'tool_use_id' => $toolUseId,
                'type' => 'tool_result',
                'content' => $text,
            ]],
        ],
    ];

    if ($toolUseResult !== null) {
        $line['toolUseResult'] = $toolUseResult;
    }

    return json_encode($line);
}

$taskFixture = sys_get_temp_dir() . '/sessioneer-test-task-list-' . getmypid() . '.jsonl';

// Happy path: two creates, then a sequence of updates - final state must
// reflect every applied change, in CREATION order (not update order).
file_put_contents($taskFixture, implode("\n", [
    task_tool_use_jsonl('t1', 'TaskCreate', ['subject' => 'First task', 'description' => 'd1', 'activeForm' => 'Doing first task']),
    task_tool_result_jsonl('t1', 'Task #1 created successfully: First task', ['task' => ['id' => '1', 'subject' => 'First task']]),
    task_tool_use_jsonl('t2', 'TaskCreate', ['subject' => 'Second task', 'description' => 'd2', 'activeForm' => 'Doing second task']),
    task_tool_result_jsonl('t2', 'Task #2 created successfully: Second task', ['task' => ['id' => '2', 'subject' => 'Second task']]),
    task_tool_use_jsonl('t3', 'TaskUpdate', ['taskId' => '1', 'status' => 'in_progress']),
    task_tool_result_jsonl('t3', 'Updated task #1 status', ['success' => true, 'taskId' => '1', 'updatedFields' => ['status'], 'statusChange' => ['from' => 'pending', 'to' => 'in_progress']]),
    task_tool_use_jsonl('t4', 'TaskUpdate', ['taskId' => '1', 'status' => 'completed']),
    task_tool_result_jsonl('t4', 'Updated task #1 status', ['success' => true, 'taskId' => '1', 'updatedFields' => ['status'], 'statusChange' => ['from' => 'in_progress', 'to' => 'completed']]),
    task_tool_use_jsonl('t5', 'TaskUpdate', ['taskId' => '2', 'status' => 'in_progress']),
    task_tool_result_jsonl('t5', 'Updated task #2 status', ['success' => true, 'taskId' => '2', 'updatedFields' => ['status'], 'statusChange' => ['from' => 'pending', 'to' => 'in_progress']]),
]) . "\n");
assert_equal(
    [
        ['content' => 'First task', 'activeForm' => 'Doing first task', 'status' => 'completed'],
        ['content' => 'Second task', 'activeForm' => 'Doing second task', 'status' => 'in_progress'],
    ],
    TranscriptService::find_current_task_list($taskFixture),
    'find_current_task_list: replays TaskCreate + a sequence of TaskUpdate calls into the current state, ordered by CREATION order'
);

assert_equal(null, TranscriptService::find_current_task_list('/does/not/exist.jsonl'), 'find_current_task_list: missing file -> null');

file_put_contents($taskFixture, '{"type":"user","message":{"role":"user","content":[{"type":"text","text":"hi"}]}}' . "\n");
assert_equal(null, TranscriptService::find_current_task_list($taskFixture), 'find_current_task_list: a session that never called TaskCreate -> null');

// A TodoWrite-only transcript must ALSO read as null here (not just "no
// text") - this is the precondition SessionDetailService::session_detail()'s
// cascade relies on to fall back to find_latest_todo_list() correctly.
file_put_contents($taskFixture, '{"type":"assistant","isSidechain":false,"message":{"role":"assistant","content":[{"type":"tool_use","id":"tw1","name":"TodoWrite","input":{"todos":[{"content":"Old-style task","status":"pending"}]}}]}}' . "\n");
assert_equal(null, TranscriptService::find_current_task_list($taskFixture), 'find_current_task_list: a TodoWrite-only transcript (no Task-family calls at all) -> null, so session_detail()\'s cascade falls back to find_latest_todo_list()');

// isSidechain: a SUBAGENT's own TaskCreate (and its own successful
// tool_result, claiming id 2) must not pollute the top-level agent's list.
file_put_contents($taskFixture, implode("\n", [
    task_tool_use_jsonl('t1', 'TaskCreate', ['subject' => 'Real task', 'description' => 'd', 'activeForm' => 'Doing it']),
    task_tool_result_jsonl('t1', 'Task #1 created successfully: Real task', ['task' => ['id' => '1', 'subject' => 'Real task']]),
    task_tool_use_jsonl('t2', 'TaskCreate', ['subject' => 'Subagent own task', 'description' => 'd', 'activeForm' => 'Doing it'], true),
    task_tool_result_jsonl('t2', 'Task #2 created successfully: Subagent own task', ['task' => ['id' => '2', 'subject' => 'Subagent own task']]),
]) . "\n");
assert_equal(
    [['content' => 'Real task', 'activeForm' => 'Doing it', 'status' => 'pending']],
    TranscriptService::find_current_task_list($taskFixture),
    'find_current_task_list: a SUBAGENT\'s own TaskCreate call (isSidechain:true) is ignored - only the top-level agent\'s own list counts'
);

// Malformed/partial data: a create with no subject, and a create whose
// tool_result is missing the structured id - both must be skipped rather
// than crashing or polluting the list with a guessed entry. A THIRD,
// well-formed create in the same fixture proves the scan keeps going.
file_put_contents($taskFixture, implode("\n", [
    task_tool_use_jsonl('t1', 'TaskCreate', ['description' => 'no subject at all']),
    task_tool_result_jsonl('t1', 'Task #1 created successfully', ['task' => ['id' => '1']]),
    task_tool_use_jsonl('t2', 'TaskCreate', ['subject' => 'Result missing id', 'description' => 'd']),
    task_tool_result_jsonl('t2', 'Task #2 created successfully: Result missing id', ['task' => ['subject' => 'Result missing id']]),
    task_tool_use_jsonl('t3', 'TaskCreate', ['subject' => 'Well-formed task', 'description' => 'd']),
    task_tool_result_jsonl('t3', 'Task #3 created successfully: Well-formed task', ['task' => ['id' => '3', 'subject' => 'Well-formed task']]),
]) . "\n");
assert_equal(
    [['content' => 'Well-formed task', 'activeForm' => 'Well-formed task', 'status' => 'pending']],
    TranscriptService::find_current_task_list($taskFixture),
    'find_current_task_list: a create with no subject, and a create whose tool_result is missing the structured task id, are both skipped rather than polluting the list; a well-formed create alongside them still comes through (activeForm falls back to subject when absent)'
);

// The critical sad path this whole method exists to get right (found LIVE
// 2026-08-22, not hypothesized - see the method's own docblock): a
// TaskUpdate call can plausibly LOOK like it should apply (a well-formed
// call, a real existing task id) while the tool actually REJECTED it
// (toolUseResult.success === false). The requested change must NOT be
// applied - the task must keep its prior state, exactly as the real tool
// itself did.
file_put_contents($taskFixture, implode("\n", [
    task_tool_use_jsonl('t1', 'TaskCreate', ['subject' => 'Real task', 'description' => 'd']),
    task_tool_result_jsonl('t1', 'Task #1 created successfully: Real task', ['task' => ['id' => '1', 'subject' => 'Real task']]),
    task_tool_use_jsonl('t2', 'TaskUpdate', ['taskId' => '1', 'status' => 'completed']),
    task_tool_result_jsonl('t2', 'Task not found', ['success' => false, 'taskId' => '1', 'updatedFields' => [], 'error' => 'Task not found']),
]) . "\n");
assert_equal(
    [['content' => 'Real task', 'activeForm' => 'Real task', 'status' => 'pending']],
    TranscriptService::find_current_task_list($taskFixture),
    'find_current_task_list: a TaskUpdate whose toolUseResult reports success:false (live-verified real shape: "Task not found") does NOT apply its requested change - the task keeps its prior status, matching what the real tool actually did rather than what was merely requested'
);

// A task updated then successfully deleted must not be in the final list -
// status:"deleted" with success:true (live-verified real shape, including
// updatedFields:["deleted"]) removes the task outright, distinct from the
// failed-delete case above.
file_put_contents($taskFixture, implode("\n", [
    task_tool_use_jsonl('t1', 'TaskCreate', ['subject' => 'Throwaway task', 'description' => 'd']),
    task_tool_result_jsonl('t1', 'Task #1 created successfully: Throwaway task', ['task' => ['id' => '1', 'subject' => 'Throwaway task']]),
    task_tool_use_jsonl('t2', 'TaskUpdate', ['taskId' => '1', 'status' => 'completed']),
    task_tool_result_jsonl('t2', 'Updated task #1 status', ['success' => true, 'taskId' => '1', 'updatedFields' => ['status'], 'statusChange' => ['from' => 'pending', 'to' => 'completed']]),
    task_tool_use_jsonl('t3', 'TaskUpdate', ['taskId' => '1', 'status' => 'deleted']),
    task_tool_result_jsonl('t3', 'Updated task #1 deleted', ['success' => true, 'taskId' => '1', 'updatedFields' => ['deleted'], 'statusChange' => ['from' => 'completed', 'to' => 'deleted']]),
]) . "\n");
assert_equal(
    [],
    TranscriptService::find_current_task_list($taskFixture),
    'find_current_task_list: a task updated then successfully deleted is removed from the final list entirely - returns [] (TaskCreate WAS called), not null'
);

@unlink($taskFixture);

// --- SessionService::title_cascade(): the pure fallback logic shared by
// both session_title() (live, below) and list_archived_sessions() (no
// filesystem access, just the four already-resolved inputs) ---
assert_equal('ai title', SessionService::title_cascade('ai title', 'pane title', '/some/workdir', 'fallback'), 'title_cascade: ai-title wins over everything else');
assert_equal('pane title', SessionService::title_cascade(null, 'pane title', '/some/workdir', 'fallback'), 'title_cascade: falls back to the live pane title when there\'s no ai-title');
assert_equal('workdir', SessionService::title_cascade(null, null, '/some/path/workdir', 'fallback'), 'title_cascade: falls back to the workdir basename next');
assert_equal('fallback', SessionService::title_cascade(null, null, null, 'fallback'), 'title_cascade: falls back to the raw name as the last resort');
assert_equal('fallback', SessionService::title_cascade('', '', '', 'fallback'), 'title_cascade: empty strings are treated the same as null, not shown as blank');

// --- SessionService::session_title(): the fallback cascade behind
// build_session_entry()'s title field - ai-title first, then the live
// pane title, then the workdir basename, then the raw session name as
// the one source that's always available (a title should never come back
// blank). Uses the same HOME_ROOT-fixture pattern as find_transcript_path()
// above since this function resolves a real transcript path internally. ---
$titleFakeHome = sys_get_temp_dir() . '/sessioneer-test-session-title-home-' . getmypid();
$titleUuid = '87654321-4321-4321-8321-210987654321';
@mkdir($titleFakeHome . '/.claude/projects/-some-project', 0700, true);
file_put_contents(
    $titleFakeHome . '/.claude/projects/-some-project/' . $titleUuid . '.jsonl',
    '{"type":"ai-title","aiTitle":"Real ai-title wins","sessionId":"' . $titleUuid . '"}' . "\n"
);
putenv("HOME_ROOT={$titleFakeHome}");

assert_equal(
    'Real ai-title wins',
    SessionService::session_title($titleUuid, 'live pane title', '/some/workdir', 'cc-20260101-1200'),
    'session_title: ai-title wins even when a live pane title, workdir, and name are all also available'
);
assert_equal(
    'live pane title',
    SessionService::session_title('00000000-0000-4000-8000-000000000000', 'live pane title', '/some/workdir', 'cc-20260101-1200'),
    'session_title: falls back to the live pane title when no ai-title is found (well-formed but nonexistent session id)'
);
assert_equal(
    'workdir',
    SessionService::session_title(null, null, '/some/path/workdir', 'cc-20260101-1200'),
    'session_title: falls back to the workdir basename when there\'s no agent_session_id or live pane title'
);
assert_equal(
    'cc-20260101-1200',
    SessionService::session_title(null, null, null, 'cc-20260101-1200'),
    'session_title: falls back to the raw session name as the last resort'
);
assert_equal(
    'cc-20260101-1200',
    SessionService::session_title(null, '', '', 'cc-20260101-1200'),
    'session_title: an empty-string live pane title or workdir is treated the same as absent, not shown as a blank title'
);

@unlink($titleFakeHome . '/.claude/projects/-some-project/' . $titleUuid . '.jsonl');
@rmdir($titleFakeHome . '/.claude/projects/-some-project');
@rmdir($titleFakeHome . '/.claude/projects');
@rmdir($titleFakeHome . '/.claude');
@rmdir($titleFakeHome);
putenv('HOME_ROOT');

test_exit();
