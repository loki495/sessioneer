<?php

declare(strict_types=1);

/**
 * Per-agent account/config-dir "profiles" - lets a session be spawned
 * under a different Claude Code account (e.g. a separate work account,
 * via its own CLAUDE_CONFIG_DIR) instead of always using this process's
 * default CLAUDE_BIN/HOME.
 *
 * Named agents.php (not claude_profiles.php) on purpose: the schema below
 * is keyed by agent id so a later pass can add 'codex'/'opencode'/
 * 'antigravity' entries without a rename - see Dibs plan #230/#236.
 *
 * config_dir: null means "use this process's own default" (today's
 * single-account behavior, e.g. $HOME/.claude for Claude Code) - so an
 * empty/untouched profile list changes nothing for an existing install.
 * bin: null means "use the agent's normal *_BIN env var" - only set this
 * per profile if a work account genuinely needs a different binary, which
 * is not the common case (a config-dir override is usually enough).
 */
return [
    'claude' => [
        'profiles' => [
            'personal' => [
                'config_dir' => null,
                'bin' => null,
            ],
            'work' => [
                'config_dir' => \HostAgent\Services\Config::home_root() . '/.claude-work',
                'bin' => null,
            ],
        ],
    ],
];
