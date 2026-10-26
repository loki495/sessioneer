#!/usr/bin/env php
<?php

declare(strict_types=1);

// This hook is observability-only. It must never influence a Codex turn,
// approval, or tool call, even when Sessioneer's database is unavailable.
ini_set('display_errors', '0');
ob_start();

try {
    require __DIR__ . '/../../lib/Sessions.php';

    $payload = json_decode((string)stream_get_contents(STDIN), true);

    if (is_array($payload)) {
        $sessionId = is_string($payload['session_id'] ?? null) ? trim($payload['session_id']) : '';
        $event = is_string($payload['hook_event_name'] ?? null) ? $payload['hook_event_name'] : '';

        if ($sessionId !== '' && strlen($sessionId) <= 200) {
            $fields = null;

            if ($event === 'UserPromptSubmit') {
                $fields = ['status' => 'working', 'blocked' => null, 'last_turn_error' => null];
            } elseif ($event === 'SessionStart') {
                $fields = ($payload['source'] ?? null) === 'compact'
                    ? ['status' => 'working', 'blocked' => null]
                    : ['status' => 'idle', 'blocked' => null];
            } elseif (in_array($event, ['Stop', 'Interrupt', 'SessionEnd'], true)) {
                $fields = ['status' => 'idle', 'blocked' => null];

                if ($event === 'Stop' && is_string($payload['last_assistant_message'] ?? null)) {
                    $fields['last_message'] = $payload['last_assistant_message'];
                }
            } elseif ($event === 'PermissionRequest') {
                $toolName = is_string($payload['tool_name'] ?? null) ? $payload['tool_name'] : 'tool';
                $toolInput = is_array($payload['tool_input'] ?? null) ? $payload['tool_input'] : [];
                $description = is_string($toolInput['description'] ?? null) ? trim($toolInput['description']) : '';
                $command = is_string($toolInput['command'] ?? null) ? trim($toolInput['command']) : '';
                $context = $description !== '' ? $description : ($command !== '' ? $command : $toolName);
                $fields = ['status' => 'blocked', 'blocked' => [
                    'tool_name' => 'external_input',
                    'question' => 'Approval required in Codex Remote.',
                    'context' => $context,
                    'options' => [],
                    'multi_question' => false,
                    'is_folder_trust' => false,
                    'tool_input' => $toolInput,
                    'external' => true,
                ]];
            } elseif ($event === 'PreToolUse' && ($payload['tool_name'] ?? null) === 'request_user_input') {
                $toolInput = is_array($payload['tool_input'] ?? null) ? $payload['tool_input'] : [];
                $questions = is_array($toolInput['questions'] ?? null) ? $toolInput['questions'] : [];
                $questionText = [];

                foreach ($questions as $question) {
                    if (is_array($question) && is_string($question['question'] ?? null) && trim($question['question']) !== '') {
                        $questionText[] = trim($question['question']);
                    }
                }

                $fields = ['status' => 'blocked', 'blocked' => [
                    'tool_name' => 'external_input',
                    'question' => 'Input required in Codex Remote.',
                    'context' => implode("\n", $questionText),
                    'options' => [],
                    'multi_question' => false,
                    'is_folder_trust' => false,
                    'tool_input' => $toolInput,
                    'external' => true,
                ]];
            } elseif ($event === 'PostToolUse') {
                $fields = ['status' => 'working', 'blocked' => null];
            }

            // The bridge records the real, answerable prompt; this hook's notice is only
            // for a request the bridge cannot see, so it must never replace one.
            if (is_array($fields) && ($fields['blocked']['external'] ?? false) === true) {
                $current = \HostAgent\Stores\SessionStatusStore::read_status($sessionId);
                if (is_array($current['blocked'] ?? null) && ($current['blocked']['external'] ?? false) !== true) {
                    $fields = null;
                }
            }

            if (is_array($fields)) {
                \HostAgent\Stores\SessionStatusStore::update_status($sessionId, $fields);
            }
        }
    }
} catch (\Throwable) {
    // Status tracking is best-effort and must never block Codex.
}

while (ob_get_level() > 0) {
    ob_end_clean();
}

fwrite(STDOUT, "{}\n");
exit(0);
