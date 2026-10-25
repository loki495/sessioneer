<?php

declare(strict_types=1);

namespace HostAgent\Launch;

use HostAgent\Services\ProcessRunner;
use HostAgent\Stores\SidecarStore;

/**
 * The peers/send parts every launcher shares: which git repository and branch
 * a directory is in, who else lives there, and the sender stamp. The using
 * class supplies list_sessions().
 */
trait PeerLookup
{
    public function peers(array $request): array
    {
        $from = is_string($request['from'] ?? null) ? trim($request['from']) : '';
        $sender = $from !== '' ? SidecarStore::read_sidecar($from) : null;
        $workdir = is_string($sender['workdir'] ?? null) ? $sender['workdir'] : (is_string($request['workdir'] ?? null) ? $request['workdir'] : '');
        $repo = $workdir !== '' ? $this->repo_of($workdir) : null;

        if ($repo === null) {
            return ['ok' => false, 'message' => 'Peers needs a session (SESSIONEER_SELF) or a workdir inside a git repository'];
        }

        $peers = [];

        foreach ($this->list_sessions($request)['sessions'] ?? [] as $s) {
            if ($s['name'] === $from || !is_string($s['workdir']) || $this->repo_of($s['workdir']) !== $repo) {
                continue;
            }

            $peers[] = ['name' => $s['name'], 'status' => $s['status'], 'workdir' => $s['workdir'], 'branch' => $this->branch_of($s['workdir']), 'title' => $s['title']];
        }

        return ['ok' => true, 'self' => $from !== '' ? $from : null, 'peers' => $peers];
    }

    /** The stamp comes from the host agent's own records of the sender, never from the message. */
    private function peer_stamp(string $from, array $sender): string
    {
        $workdir = (string)($sender['workdir'] ?? '-');

        return "[sessioneer peer: {$from} · {$workdir} · " . ($this->branch_of($workdir) ?? '-') . ']';
    }

    private function repo_of(string $dir): ?string
    {
        $r = is_dir($dir) ? ProcessRunner::run_process(['git', '-C', $dir, 'rev-parse', '--path-format=absolute', '--git-common-dir']) : null;

        return $r !== null && $r['exit'] === 0 && trim($r['stdout']) !== '' ? trim($r['stdout']) : null;
    }

    private function branch_of(string $dir): ?string
    {
        $r = is_dir($dir) ? ProcessRunner::run_process(['git', '-C', $dir, 'branch', '--show-current']) : null;

        return $r !== null && $r['exit'] === 0 && trim($r['stdout']) !== '' ? trim($r['stdout']) : null;
    }
}
