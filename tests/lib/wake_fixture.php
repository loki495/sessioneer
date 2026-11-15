<?php
declare(strict_types=1);

/**
 * Shared rig for the wake-up list/cancel tests: a fixture directory standing in for the `at` queue.
 * AT_BIN points at a stub script over that directory, so no real `at` job is ever read, created or
 * removed:
 *   queue.txt    what `at -l` prints
 *   jobs/N       what `at -c N` prints (a realistic preamble, then the command)
 *   removed.log  one line per `at -r N` the code under test actually ran
 *   calls.log    one line per invocation (its arguments), to prove only -l/-c/-r/-t are ever used
 *   fail-l, fail-r  flag files: make `at -l` / `at -r` exit non-zero with a two-line stderr
 *   fail-l-silent  makes `at -l` exit non-zero with an empty stderr
 *   decoy/atq, decoy/atrm  scripts first on PATH that record any call by name in atq-atrm.log
 * `at -t TIME` (what schedule() runs) creates a new job and queue row so a schedule round-trips.
 */
final class WakeRig
{
    public string $root;
    public string $dir;
    private string $tz;

    public function __construct(string $name, string $tz = 'America/New_York')
    {
        $this->root = sys_get_temp_dir() . '/sessioneer-test-' . $name . '-' . getmypid();
        $this->dir = $this->root . '/at';
        $this->tz = $tz;
        exec('rm -rf ' . escapeshellarg($this->root));
        mkdir($this->dir . '/jobs', 0700, true);
        mkdir($this->root . '/decoy', 0700, true);
        mkdir($this->root . '/cache', 0700, true);

        putenv('TZ=' . $tz);
        putenv('HOME=/home/waketest');
        putenv('SIDECAR_DIR=' . $this->root . '/sidecars');
        putenv('SESSIONS_SQLITE_FILE=' . $this->root . '/sessions.sqlite');
        putenv('CACHE_DIR=' . $this->root . '/cache');
        putenv('SESSIONEER_AGENT_SOCKET=');
        putenv('AT_BIN=' . $this->dir . '/at');

        foreach (['atq', 'atrm'] as $decoy) {
            file_put_contents($this->root . '/decoy/' . $decoy, "#!/bin/sh\necho \"$decoy \$*\" >> " . $this->root . "/atq-atrm.log\nexit 0\n");
            chmod($this->root . '/decoy/' . $decoy, 0700);
        }

        putenv('PATH=' . $this->root . '/decoy:' . getenv('PATH'));

        file_put_contents($this->dir . '/at', <<<'SH'
#!/bin/bash
d="$(cd "$(dirname "$0")" && pwd)"
printf '%s\n' "$*" >> "$d/calls.log"
case "$1" in
-l)
    if [ -f "$d/fail-l-silent" ]; then exit 1; fi
    if [ -f "$d/fail-l" ]; then printf 'at: cannot read the queue\nsecond stderr line\n' >&2; exit 1; fi
    cat "$d/queue.txt"
    ;;
-c)
    if [ -f "$d/jobs/$2" ]; then cat "$d/jobs/$2"; else echo "Cannot find jobid $2" >&2; exit 1; fi
    ;;
-r)
    if [ -f "$d/fail-r" ]; then printf 'Permission denied removing %s\nsecond stderr line\n' "$2" >&2; exit 1; fi
    if [ ! -f "$d/jobs/$2" ]; then echo "Cannot find jobid $2" >&2; exit 1; fi
    echo "$2" >> "$d/removed.log"
    rm -f "$d/jobs/$2"
    grep -v -E "^$2[[:space:]]" "$d/queue.txt" > "$d/queue.tmp"
    mv "$d/queue.tmp" "$d/queue.txt"
    ;;
-t)
    echo "$@" > "$d/at-args"
    cat > "$d/at-script"
    n=$(cat "$d/next-id" 2>/dev/null || echo 100)
    echo $((n + 1)) > "$d/next-id"
    when="$(echo "$2" | cut -c1-8) $(echo "$2" | cut -c9-10):$(echo "$2" | cut -c11-12)"
    stamp="$(date -d "$when" '+%a %b %e %H:%M:%S %Y')"
    { printf '#!/bin/sh\n# atrun uid=1000 gid=1000\numask 22\ncd /home/waketest || {\n\t echo "Execution directory inaccessible" >&2\n\t exit 1\n}\n'; cat "$d/at-script"; echo; } > "$d/jobs/$n"
    printf '%s\t%s a waketest\n' "$n" "$stamp" >> "$d/queue.txt"
    echo "job $n at $stamp" >&2
    ;;
esac
SH);
        chmod($this->dir . '/at', 0700);
        $this->reset();
    }

    /** Back to an empty queue, no jobs, no recorded calls, a fresh wake cache. */
    public function reset(): void
    {
        file_put_contents($this->dir . '/queue.txt', '');
        exec('rm -f ' . escapeshellarg($this->dir) . '/jobs/* ' . escapeshellarg($this->dir) . '/removed.log ' . escapeshellarg($this->dir) . '/calls.log ' . escapeshellarg($this->dir) . '/fail-l ' . escapeshellarg($this->dir) . '/fail-l-silent ' . escapeshellarg($this->dir) . '/fail-r ' . escapeshellarg($this->dir) . '/next-id ' . escapeshellarg($this->root) . '/atq-atrm.log');
        $this->clear_cache();
    }

    public function clear_cache(): void
    {
        exec('rm -rf ' . escapeshellarg($this->root . '/cache') . ' && mkdir -p ' . escapeshellarg($this->root . '/cache'));
    }

    /** The `at -l` row for a job: `26<TAB>Sun Nov 15 10:30:00 2026 a andres`. */
    public function row(int $job, int $at, string $letter = 'a'): string
    {
        $stamp = (new DateTimeImmutable('@' . $at))->setTimezone(new DateTimeZone($this->tz))->format('D M j H:i:s Y');
        // at pads a single-digit day to two characters with a space, like ctime.
        $stamp = (string)preg_replace('/^(\w{3} \w{3}) (\d) /', '$1  $2 ', $stamp);

        return "{$job}\t{$stamp} {$letter} waketest";
    }

    /** Adds a queue row and, unless $body is null, the job file `at -c` prints. */
    public function add(int $job, int $at, ?string $body, string $letter = 'a'): void
    {
        file_put_contents($this->dir . '/queue.txt', $this->row($job, $at, $letter) . "\n", FILE_APPEND);

        if ($body !== null) {
            file_put_contents($this->dir . '/jobs/' . $job, $body);
        }
    }

    /** What `at -c` prints around a command: the shell preamble holds the scheduler's whole environment. */
    public static function body(string $command, array $extraExports = []): string
    {
        $exports = array_merge([
            "SECRET_TOKEN=hunter2-do-not-leak; export SECRET_TOKEN",
            "HOME=/home/waketest; export HOME",
            // at escapes a variable's value with backslashes, so a line never starts with a bare `env -i '`.
            "TRICKY=env\\ -i\\ \\'HOME\\=/evil\\'\\ x; export TRICKY",
        ], $extraExports);

        return "#!/bin/sh\n# atrun uid=1000 gid=1000\n# mail waketest 0\numask 22\n" . implode("\n", $exports)
            . "\ncd /home/waketest || {\n\t echo 'Execution directory inaccessible' >&2\n\t exit 1\n}\n" . $command . "\n\n";
    }

    public function removed(): string
    {
        return is_file($this->dir . '/removed.log') ? (string)file_get_contents($this->dir . '/removed.log') : '';
    }

    /** @return list<string> every `at` invocation's arguments, oldest first */
    public function calls(): array
    {
        return is_file($this->dir . '/calls.log') ? array_values(array_filter(explode("\n", (string)file_get_contents($this->dir . '/calls.log')), static fn (string $l): bool => $l !== '')) : [];
    }

    public function count_calls(string $argsLine): int
    {
        return count(array_filter($this->calls(), static fn (string $l): bool => $l === $argsLine));
    }

    public function clear_calls(): void
    {
        @unlink($this->dir . '/calls.log');
    }

    public function cleanup(): void
    {
        exec('rm -rf ' . escapeshellarg($this->root));
    }
}

/** The epoch of a wall-clock time in a zone. */
function wake_epoch(string $local, string $tz = 'America/New_York'): int
{
    return (new DateTimeImmutable($local, new DateTimeZone($tz)))->getTimestamp();
}
