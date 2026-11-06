<?php

declare(strict_types=1);

namespace App\Controllers;

use App\AgentClient;

class DashboardController extends Controller
{
    /**
     * GET-only JSON endpoint, polled by the session page to show every
     * other session's status/prompt. Read-only.
     */
    public function list(): void
    {
        $this->start_readonly_json();

        echo json_encode(AgentClient::agent_call(['action' => 'list']));
    }

    /**
     * GET-only JSON endpoint backing the dashboard's search box - content
     * search across every known transcript (live and archived alike), not
     * just the archived list's own client-side title/name filter (see
     * ArchivedSessionService::search_transcripts()'s own doc comment for why this
     * has to be server-side). Read-only, and deliberately NOT part of any
     * regular poll - only ever fired by the search box's own debounced input handler.
     */
    public function search(): void
    {
        $this->start_readonly_json();

        $query = trim((string)($_GET['q'] ?? ''));

        echo json_encode(AgentClient::agent_call([
            'action' => 'search_transcripts',
            'query' => $query,
            'max_sessions' => 30,
            'max_matches_per_session' => 3,
        ]));
    }
}
