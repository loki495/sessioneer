<?php

declare(strict_types=1);

// Temporary Shortcut preview only: no session dispatch or credentials.
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
echo json_encode([
    'type' => 'answer',
    'text' => 'Your iOS Shortcut reached Sessioneer. This is a preview response; no agent was started.',
    'session_url' => rtrim(getenv('QUICK_PROMPT_PREVIEW_BASE_URL') ?: 'https://sessioneer.example', '/') . '/',
    'session_id' => 'shortcut-preview',
], JSON_UNESCAPED_SLASHES);
