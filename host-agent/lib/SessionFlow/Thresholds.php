<?php

declare(strict_types=1);

namespace HostAgent\SessionFlow;

final class Thresholds
{
    /** The built-in park line, for the code that checks room without a configured Thresholds. */
    public const DEFAULT_QUOTA_PARK = 90;

    private const DEFAULTS = [
        'CTX_GO_MAX' => 150000,
        'QUOTA_GO_MAX' => 80,
        'CTX_ROTATE' => 250000,
        'QUOTA_PARK' => self::DEFAULT_QUOTA_PARK,
        'QUOTA_CARRY' => 95,
        'IDLE_MIN' => 60,
        'IDLE_FLOOR' => 100000,
    ];

    /**
     * @param array<string, int> $values
     * @param list<string> $warnings
     */
    private function __construct(private array $values, private array $warnings)
    {
    }

    public static function fromFile(string $path): self
    {
        $values = self::DEFAULTS;
        $warnings = [];
        $lines = is_file($path) ? file($path, FILE_IGNORE_NEW_LINES) : false;
        if ($lines === false) {
            return new self($values, ["thresholds file unreadable: $path; using defaults"]);
        }
        foreach ($lines as $line) {
            $line = trim($line);
            if ($line === '' || $line[0] === '#') {
                continue;
            }
            [$key, $raw] = array_map('trim', explode('=', $line, 2)) + [1 => ''];
            if (!isset(self::DEFAULTS[$key])) {
                $warnings[] = "unknown threshold key $key ignored";
            } elseif (preg_match('/^\d+$/', $raw) === 1) {
                $values[$key] = (int) $raw;
            } else {
                $warnings[] = "invalid value for $key ('$raw'); using default " . self::DEFAULTS[$key];
            }
        }

        return new self($values, $warnings);
    }

    public function get(string $key): int
    {
        return $this->values[$key] ?? throw new \InvalidArgumentException("unknown threshold $key");
    }

    /** @return list<string> */
    public function warnings(): array
    {
        return $this->warnings;
    }
}
