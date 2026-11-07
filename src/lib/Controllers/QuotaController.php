<?php

declare(strict_types=1);

namespace App\Controllers;

use App\AgentClient;

/**
 * GET-only JSON endpoint, polled asynchronously by index.php's (and
 * session.php's) sticky footer so a slow quota refresh on the host agent
 * never blocks page render. Read-only (no state mutated here), so no
 * CSRF/same-origin check is needed - matching GET / itself, which also
 * has none.
 */
class QuotaController extends Controller
{
    public function show(): void
    {
        // start_readonly_json() calls AuthService::start_app_session() first,
        // which keeps the session (and its CSRF token) alive for as long as
        // this page is open and polling - see SessionController::history()
        // for the full story on why that matters. It also sets
        // Cache-Control: no-store, overriding the private, max-age=60 that
        // start_app_session() itself sets (fine for session.php's own HTML,
        // wrong here) - a browser could otherwise serve a stale response to
        // this exact fetch() URL for up to 60s.
        $this->start_readonly_json();

        // Optional - only session.php's footer sends this, to additionally
        // overlay that one session's own context-window percentage. Unlike
        // session/week_all (account-wide, same everywhere), context is
        // genuinely per-session, so index.php's dashboard footer - which
        // has no single relevant session - simply omits it.
        $sessionName = trim((string)($_GET['session'] ?? ''));

        echo json_encode(AgentClient::agent_call(['action' => 'quota', 'session' => $sessionName]));
    }
}
