<?php

declare(strict_types=1);

namespace App\Controllers;

use App\AgentClient;
use App\Services\AuthService;
use App\Views\V2View;

/**
 * The /v2 UI: a redesigned shell and dashboard served beside the classic
 * one. It reads the same host-agent data and reuses the classic JSON
 * endpoints for per-session actions (star, rename, answer_prompt, browse,
 * search, quota); only the page, its card/list fragments and a JSON twin of
 * DashboardController::handleAction() live here, because the classic action
 * handler answers with a redirect + flash that a fetch() cannot use.
 */
class V2Controller extends Controller
{
    public function index(): void
    {
        AuthService::start_app_session();

        $listResult = AgentClient::agent_call(['action' => 'list']);
        $agentReachable = (bool)($listResult['ok'] ?? false);

        $hookResult = $agentReachable ? AgentClient::agent_call(['action' => 'check_session_hook']) : ['ok' => false];
        $healthResult = $agentReachable ? AgentClient::agent_call(['action' => 'health_check']) : ['ok' => false];
        $pushTimerResult = $agentReachable ? AgentClient::agent_call(['action' => 'get_push_timer_interval']) : ['ok' => false];

        $csrfToken = AuthService::csrf_token();
        $sessions = $agentReachable ? ($listResult['sessions'] ?? []) : [];
        $bare = $agentReachable ? ($listResult['bare'] ?? []) : [];

        echo V2View::dashboard_page([
            'agentReachable' => $agentReachable,
            'agentMessage' => (string)($listResult['message'] ?? 'Unknown error'),
            'csrfToken' => $csrfToken,
            'counts' => V2View::counts($sessions),
            'sessionsHtml' => V2View::sessions_html($sessions, $csrfToken),
            'bareHtml' => V2View::bare_html($bare, $csrfToken),
            'hooksMissing' => $agentReachable && (bool)($hookResult['ok'] ?? false) && !($hookResult['installed'] ?? false),
            'healthChecks' => (bool)($healthResult['ok'] ?? false) ? ($healthResult['checks'] ?? []) : [],
            'pushTimerSeconds' => (bool)($pushTimerResult['ok'] ?? false) ? (int)($pushTimerResult['interval_seconds'] ?? 0) : null,
            'agentOptions' => \App\Views\PageView::AGENT_OPTIONS,
        ]);
    }

    /**
     * The session page. Only the detail is rendered into the page (header,
     * controls, prompt); the transcript is fetched by v2-session.js from the
     * classic session_history.php, so there is one renderer for the first
     * page, load-earlier and live polling alike.
     */
    public function session(): void
    {
        AuthService::start_app_session();

        $sessionName = trim((string)($_GET['session'] ?? ''));

        if ($sessionName === '') {
            header('Location: /v2', true, 303);

            return;
        }

        $csrfToken = AuthService::csrf_token();
        AuthService::close_app_session();

        $detail = AgentClient::agent_call(['action' => 'session_detail', 'session' => $sessionName]);
        $jumpLine = isset($_GET['jump_line']) ? (int)$_GET['jump_line'] : 0;

        echo V2View::session_page([
            'sessionName' => $sessionName,
            'csrfToken' => $csrfToken,
            'found' => (bool)($detail['ok'] ?? false),
            'detail' => $detail,
            'jumpLine' => $jumpLine > 0 ? $jumpLine : null,
        ]);
    }

    /**
     * The classic session_history.php plus `include_thinking`, which only the
     * v2 page asks for (the classic renderers have no block kind for it).
     */
    public function history(): void
    {
        $this->start_readonly_json();

        echo json_encode(AgentClient::agent_call([
            'action' => 'session_history',
            'session' => (string)($_GET['session'] ?? ''),
            'before' => isset($_GET['before']) ? (int)$_GET['before'] : null,
            'limit' => isset($_GET['limit']) ? (int)$_GET['limit'] : 30,
            'after' => isset($_GET['after']) ? (int)$_GET['after'] : null,
            'until_user' => ($_GET['until_user'] ?? '') === '1',
            'include_thinking' => ($_GET['include_thinking'] ?? '') === '1',
        ]));
    }

    /** Polled by v2.js: the same `list` call as the classic fragment, rendered as v2 cards. */
    public function fragment(): void
    {
        $this->start_readonly_json();

        $csrfToken = AuthService::csrf_token();
        $listResult = AgentClient::agent_call(['action' => 'list']);

        if (!($listResult['ok'] ?? false)) {
            echo json_encode(['ok' => false, 'message' => (string)($listResult['message'] ?? 'Unknown error')]);

            return;
        }

        $sessions = $listResult['sessions'] ?? [];

        echo json_encode([
            'ok' => true,
            'counts' => V2View::counts($sessions),
            'sessions_html' => V2View::sessions_html($sessions, $csrfToken),
            'bare_html' => V2View::bare_html($listResult['bare'] ?? [], $csrfToken),
        ]);
    }

    /** Lazy, like the classic one: a full transcript-directory scan is not worth doing on every poll. */
    public function archived(): void
    {
        $this->start_readonly_json();

        $result = AgentClient::agent_call(['action' => 'list_archived']);

        if (!($result['ok'] ?? false)) {
            echo json_encode(['ok' => false, 'message' => (string)($result['message'] ?? 'Unknown error')]);

            return;
        }

        echo json_encode(['ok' => true, 'archived_html' => V2View::archived_html($result['archived'] ?? [], AuthService::csrf_token())]);
    }

    /**
     * JSON twin of DashboardController::handleAction() for the actions the v2
     * dashboard offers. Same parameters, same host-agent calls; `redirect` is
     * set where the classic one answers with a Location header.
     */
    public function action(): void
    {
        $this->require_post_json();

        $result = match ((string)($_POST['action'] ?? '')) {
            'new' => $this->create_session(),
            'resume' => $this->resume_session(),
            'switch_runtime' => $this->agent_with_redirect([
                'action' => 'switch_runtime',
                'session' => (string)($_POST['session'] ?? ''),
                'runtime' => (string)($_POST['runtime'] ?? ''),
            ]),
            'kill' => $this->agent_plain(['action' => 'kill', 'session' => (string)($_POST['session'] ?? '')]),
            'kill_bare' => $this->agent_plain(['action' => 'kill_bare', 'pid' => (int)($_POST['pid'] ?? 0)]),
            'cleanup' => $this->cleanup(),
            'install_hook' => $this->agent_plain(['action' => 'install_session_hook'], 'App hooks installed for Claude Code and Codex.'),
            'set_push_timer_interval' => $this->agent_plain(
                ['action' => 'set_push_timer_interval', 'seconds' => (int)($_POST['seconds'] ?? 0)],
                'Push-check interval set to ' . (int)($_POST['seconds'] ?? 0) . 's.'
            ),
            default => ['ok' => false, 'message' => 'Unknown action'],
        };

        echo json_encode($result);
    }

    /** @return array<string, mixed> */
    private function create_session(): array
    {
        $params = [
            'action' => 'create',
            'workdir' => trim((string)($_POST['workdir'] ?? '')),
            'enable_task_tools' => ($_POST['enable_task_tools'] ?? '') === '1',
            'starting_mode' => trim((string)($_POST['starting_mode'] ?? '')),
            'agent' => trim((string)($_POST['agent'] ?? '')),
        ];

        $model = trim((string)($_POST['model'] ?? ''));

        if ($model !== '') {
            $params['model'] = $model;
            $params['model_provider'] = trim((string)($_POST['model_provider'] ?? ''));
        }

        $profile = trim((string)($_POST['profile'] ?? ''));

        if ($profile !== '') {
            $params['profile'] = $profile;
        }

        $runtime = (string)($_POST['runtime'] ?? '');

        if (in_array($runtime, ['headless', 'tmux'], true)) {
            $params['runtime'] = $runtime;
        }

        return $this->agent_with_redirect($params, 'Created session');
    }

    /** @return array<string, mixed> */
    private function resume_session(): array
    {
        $params = [
            'action' => 'resume',
            'workdir' => trim((string)($_POST['workdir'] ?? '')),
            'agent_session_id' => trim((string)($_POST['agent_session_id'] ?? '')),
        ];

        $profile = trim((string)($_POST['profile'] ?? ''));

        if ($profile !== '') {
            $params['profile'] = $profile;
        }

        if (($_POST['runtime'] ?? '') === 'headless') {
            $params['runtime'] = 'headless';
        }

        return $this->agent_with_redirect($params);
    }

    /** @return array<string, mixed> */
    private function cleanup(): array
    {
        $result = AgentClient::agent_call(['action' => 'cleanup']);
        $killed = $result['killed'] ?? [];
        $failed = $result['failed'] ?? [];
        $message = count($killed) > 0 ? 'Killed: ' . implode(', ', $killed) : 'No sessions inactive for more than 12h';

        if (!empty($failed)) {
            $message .= ' (failed to kill: ' . implode(', ', $failed) . ')';
        }

        return ['ok' => (bool)($result['ok'] ?? false), 'message' => $message];
    }

    /**
     * @param array<string, mixed> $params
     * @return array<string, mixed>
     */
    private function agent_plain(array $params, ?string $okMessage = null): array
    {
        $result = AgentClient::agent_call($params);
        $ok = (bool)($result['ok'] ?? false);

        return ['ok' => $ok, 'message' => $ok && $okMessage !== null ? $okMessage : (string)($result['message'] ?? 'Unknown error')];
    }

    /**
     * A call that lands on a live session: the page opens it (in the v2
     * session page) instead of just showing a message.
     *
     * @param array<string, mixed> $params
     * @return array<string, mixed>
     */
    private function agent_with_redirect(array $params, ?string $okMessage = null): array
    {
        $result = AgentClient::agent_call($params);
        $ok = (bool)($result['ok'] ?? false);
        $out = ['ok' => $ok, 'message' => $ok && $okMessage !== null ? $okMessage : (string)($result['message'] ?? 'Unknown error')];

        if ($ok && is_string($result['name'] ?? null) && $result['name'] !== '') {
            $out['redirect'] = '/v2/session?session=' . urlencode($result['name']);
        }

        return $out;
    }
}
