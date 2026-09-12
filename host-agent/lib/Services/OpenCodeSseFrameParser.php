<?php

declare(strict_types=1);

namespace HostAgent\Services;

/** Incremental SSE framing and OpenCode event-envelope normalization. */
final class OpenCodeSseFrameParser
{
    private string $buffer = '';
    /** @var array<int,string> */
    private array $dataLines = [];
    private int $dataBytes = 0;
    private bool $discardingFrame = false;

    public function __construct(private readonly int $maxBufferBytes = 1048576) {}

    /** @return array<int,array<string,mixed>> */
    public function push(string $chunk): array
    {
        if ($chunk === '') return [];
        $this->buffer .= $chunk;
        if (strlen($this->buffer) > $this->maxBufferBytes) {
            $this->buffer = '';
            $this->dataLines = [];
            $this->dataBytes = 0;
            $this->discardingFrame = false;
            return [];
        }
        $events = [];
        while (($newline = strpos($this->buffer, "\n")) !== false) {
            $line = rtrim(substr($this->buffer, 0, $newline), "\r");
            $this->buffer = substr($this->buffer, $newline + 1);
            if ($line === '') {
                if ($this->dataLines !== []) {
                    $decoded = json_decode(implode("\n", $this->dataLines), true);
                    if (is_array($decoded)) $events[] = $decoded;
                }
                $this->dataLines = [];
                $this->dataBytes = 0;
                $this->discardingFrame = false;
            } elseif (str_starts_with($line, 'data:')) {
                if ($this->discardingFrame) continue;
                $data = ltrim(substr($line, 5), ' ');
                $this->dataBytes += strlen($data);
                if ($this->dataBytes > $this->maxBufferBytes) {
                    // A complete-but-never-terminated event also needs a
                    // limit; otherwise many ordinary lines bypass $buffer.
                    $this->dataLines = [];
                    $this->dataBytes = 0;
                    $this->discardingFrame = true;
                } else {
                    $this->dataLines[] = $data;
                }
            }
        }
        return $events;
    }

    /**
     * Direct v1, legacy global ({directory,payload}), and v2
     * ({location,data}, question.v2.*) event envelopes.
     * @param array<string,mixed> $event
     * @return array{type:string,directory:?string,properties:array<string,mixed>}|null
     */
    public static function normalize(array $event): ?array
    {
        $directory = is_string($event['directory'] ?? null) ? $event['directory'] : null;
        if ($directory === null && is_array($event['location'] ?? null)) {
            $directory = is_string($event['location']['directory'] ?? null) ? $event['location']['directory'] : null;
        }
        $inner = is_array($event['payload'] ?? null) ? $event['payload'] : (is_array($event['data'] ?? null) ? $event['data'] : null);
        $type = is_string($event['type'] ?? null) ? $event['type'] : '';
        if ($inner !== null && is_string($inner['type'] ?? null)) $type = $inner['type'];
        $type = str_replace('question.v2.', 'question.', $type);
        if (!in_array($type, ['question.asked', 'question.replied', 'question.rejected'], true)) return null;
        $properties = $inner !== null
            ? (is_array($inner['properties'] ?? null) ? $inner['properties'] : $inner)
            : (is_array($event['properties'] ?? null) ? $event['properties'] : []);
        return ['type' => $type, 'directory' => $directory, 'properties' => $properties];
    }
}
