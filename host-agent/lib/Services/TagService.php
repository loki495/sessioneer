<?php

declare(strict_types=1);

namespace HostAgent\Services;

class TagService
{
    public const MAX_TAGS = 8;
    public const MAX_LENGTH = 32;

    /** @return list<string> */
    public static function parse(mixed $input, bool $split = false): array
    {
        if (is_string($input) && $split) $input = explode(',', $input);
        if (!is_array($input) || !array_is_list($input)) throw new \InvalidArgumentException('Tags must be a list');
        $result = [];
        foreach ($input as $value) {
            if (!is_string($value)) throw new \InvalidArgumentException('Tags must be strings');
            if ($split && trim($value) === '') continue;
            $tag = trim((string)preg_replace('/\s+/', ' ', str_replace(['-', '_'], ' ', strtolower($value))));
            if ($tag === 'none' || strlen($tag) > self::MAX_LENGTH || !preg_match('/^[a-z0-9]+( [a-z0-9]+)*$/D', $tag)) {
                throw new \InvalidArgumentException('invalid tag: ' . $value);
            }
            if (!in_array($tag, $result, true)) $result[] = $tag;
        }
        return $result;
    }

    /**
     * @param list<string> $current
     * @param list<string> $tags
     * @return list<string>
     */
    public static function apply(array $current, string $mode, array $tags): array
    {
        if ($mode === 'set') $result = $tags;
        elseif ($mode === 'add') $result = array_values(array_unique(array_merge($current, $tags)));
        elseif ($mode === 'remove') $result = array_values(array_diff($current, $tags));
        else throw new \InvalidArgumentException('Unknown tag mode');
        if (count($result) > self::MAX_TAGS) throw new \InvalidArgumentException('A session may have at most ' . self::MAX_TAGS . ' tags');
        return $result;
    }
}
