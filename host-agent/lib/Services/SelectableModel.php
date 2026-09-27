<?php

declare(strict_types=1);

namespace HostAgent\Services;

/**
 * The fixed 5-row vocabulary behind session.php's "Select model" dropdown
 * (Andres's own ask, 2026-08-24) - Default/Sonnet/Fable/Opus/Haiku, in the
 * EXACT order Claude Code's own `/model` picker renders them (verified live
 * against a real running session, same discipline PromptParser's own
 * key-sequence docblocks already use). PromptInteractionService::set_model() relies
 * on this order directly: the picker's cursor never wraps past its first
 * row (also verified live), so pressing Up enough times always lands on
 * row 1 regardless of where the cursor started, and Down (row - 1) times
 * from there reaches any target row deterministically - no need to read
 * the CURRENT model first the way set_mode()'s relative Shift+Tab cycling
 * does.
 */
class SelectableModel
{
    /**
     * Row order matches the real /model picker exactly. "Default" has no
     * raw model ID of its own (it resolves to whatever the account's
     * runtime default currently is) - family_from_raw_model() below can
     * never return it, only a caller picking it from the dropdown does.
     */
    public const PICKER_OPTIONS = [
        'default' => 'Default',
        'sonnet' => 'Sonnet',
        'fable' => 'Fable',
        'opus' => 'Opus',
        'haiku' => 'Haiku',
    ];

    /**
     * Maps a raw model ID off a transcript's own message.model field (e.g.
     * "claude-sonnet-5", or an older dated pin like
     * "claude-sonnet-4-5-20250929") to this app's own PICKER_OPTIONS key, by
     * family prefix - the exact version within a family isn't distinguished,
     * since the dropdown only ever offers a whole family at a time, same as
     * the real picker. Returns null for an unrecognized prefix rather than
     * guessing, same "don't guess" discipline as PermissionMode::
     * normalize_hook_permission_mode().
     */
    public static function family_from_raw_model(string $rawModel): ?string
    {
        foreach (['sonnet', 'opus', 'haiku', 'fable'] as $family) {
            if (str_starts_with($rawModel, "claude-{$family}")) {
                return $family;
            }
        }

        return null;
    }

    /**
     * Human-readable name for a raw Claude model id, by parsing rather than a
     * lookup table so a future model needs no edit here: "claude-opus-5-5" is
     * "Opus 5.5", "claude-sonnet-5" is "Sonnet 5", "claude-haiku-4-5-20251001"
     * is "Haiku 4.5" (a date pin and a "[1m]" context suffix are dropped).
     * Null when the id isn't a recognised Claude family, same "don't guess"
     * rule as family_from_raw_model().
     */
    public static function display_name(string $rawModel): ?string
    {
        $family = self::family_from_raw_model($rawModel);

        if ($family === null) {
            return null;
        }

        $version = self::version_parts($rawModel);

        return ucfirst($family) . ($version !== [] ? ' ' . implode('.', $version) : '');
    }

    /**
     * The version numbers following the family in a raw id
     * ("claude-opus-4-1" -> [4, 1]); an 8-digit date pin ends the run.
     *
     * @return list<int>
     */
    public static function version_parts(string $rawModel): array
    {
        $family = self::family_from_raw_model($rawModel);

        if ($family === null) {
            return [];
        }

        $rest = substr((string)preg_replace('/\[.*$/', '', $rawModel), strlen("claude-{$family}"));
        $parts = [];

        foreach (array_slice(explode('-', $rest), 1) as $token) {
            if (!preg_match('/^\d{1,2}$/', $token)) {
                break;
            }

            $parts[] = (int)$token;
        }

        return $parts;
    }

    /**
     * True when $a is a strictly newer version than $b, comparing part by part
     * ([5] vs [5, 1]: 5.1 is newer).
     *
     * @param list<int> $a
     * @param list<int> $b
     */
    public static function version_is_newer(array $a, array $b): bool
    {
        $length = max(count($a), count($b));

        for ($i = 0; $i < $length; $i++) {
            $left = $a[$i] ?? 0;
            $right = $b[$i] ?? 0;

            if ($left !== $right) {
                return $left > $right;
            }
        }

        return false;
    }
}
