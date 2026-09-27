<?php

declare(strict_types=1);

namespace HostAgent\Services;

use HostAgent\Stores\GlobalStateStore;

/**
 * The full names behind the model dropdown's family rows. The dropdown offers
 * a whole family at a time ("Opus"), and which version that resolves to is
 * Claude Code's decision, so there is nothing here to hardcode: every raw
 * model id Sessioneer reads off a transcript or a headless session is recorded
 * here, and each family is labelled with the newest version seen so far
 * ("Opus 5.5"). A family never seen yet keeps its plain name.
 */
class ClaudeModelCatalog
{
    private const STATE_KEY = 'claude_models_seen';

    /** Remembers $rawModel if it is newer than what is already known for its family. */
    public static function record(string $rawModel): void
    {
        $family = SelectableModel::family_from_raw_model($rawModel);

        if ($family === null) {
            return;
        }

        $seen = self::seen();
        $known = $seen[$family] ?? null;

        if ($known !== null && !SelectableModel::version_is_newer(SelectableModel::version_parts($rawModel), SelectableModel::version_parts($known))) {
            return;
        }

        $seen[$family] = $rawModel;
        GlobalStateStore::write(self::STATE_KEY, ['models' => $seen]);
    }

    /**
     * Dropdown key => label for every SelectableModel::PICKER_OPTIONS row.
     *
     * @return array<string, string>
     */
    public static function labels(): array
    {
        $seen = self::seen();
        $labels = [];

        foreach (SelectableModel::PICKER_OPTIONS as $key => $plain) {
            $labels[$key] = isset($seen[$key]) ? (SelectableModel::display_name($seen[$key]) ?? $plain) : $plain;
        }

        return $labels;
    }

    /**
     * @return array<string, string>
     */
    private static function seen(): array
    {
        $models = GlobalStateStore::read(self::STATE_KEY)['models'] ?? [];
        $seen = [];

        foreach (is_array($models) ? $models : [] as $family => $raw) {
            if (is_string($family) && is_string($raw)) {
                $seen[$family] = $raw;
            }
        }

        return $seen;
    }
}
