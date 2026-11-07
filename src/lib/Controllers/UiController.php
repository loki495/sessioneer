<?php

declare(strict_types=1);

namespace App\Controllers;

use App\AgentClient;
use App\Services\AuthService;
use App\Views\UiView;

/**
 * The UI: a redesigned shell and dashboard served beside the classic
 * one. It reads the same host-agent data and reuses the classic JSON
 * endpoints for per-session actions (star, rename, answer_prompt, browse,
 * search, quota); only the page, its card/list fragments and a JSON twin of
 * DashboardController::handleAction() live here, because the classic action
 * handler answers with a redirect + flash that a fetch() cannot use.
 */
class UiController extends Controller
{
    /** The redesign's former page URLs (/v2, /v2/session, /v2/archived-session) now live at the plain ones. */
    public function redirectPage(): void
    {
        $path = rtrim((string)parse_url((string)($_SERVER['REQUEST_URI'] ?? ''), PHP_URL_PATH), '/');
        $plain = ['/v2' => '/', '/v2/session' => '/session', '/v2/archived-session' => '/archived-session'][$path] ?? '/';
        $query = (string)($_SERVER['QUERY_STRING'] ?? '');

        header('Location: ' . $plain . ($query !== '' ? '?' . $query : ''), true, 302);
    }

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

        echo UiView::dashboard_page([
            'agentReachable' => $agentReachable,
            'agentMessage' => (string)($listResult['message'] ?? 'Unknown error'),
            'csrfToken' => $csrfToken,
            'counts' => UiView::counts($sessions),
            'sessionsHtml' => UiView::sessions_html($sessions, $csrfToken, $this->quota_reader()),
            'bareHtml' => UiView::bare_html($bare, $csrfToken),
            'hooksMissing' => $agentReachable && (bool)($hookResult['ok'] ?? false) && !($hookResult['installed'] ?? false),
            'healthChecks' => (bool)($healthResult['ok'] ?? false) ? ($healthResult['checks'] ?? []) : [],
            'pushTimerSeconds' => (bool)($pushTimerResult['ok'] ?? false) ? (int)($pushTimerResult['interval_seconds'] ?? 0) : null,
            'vapidPublicKey' => $agentReachable ? (string)(AgentClient::agent_call(['action' => 'push_public_key'])['public_key'] ?? '') : '',
            'agentOptions' => array_diff_key(\App\Views\UiView::AGENT_OPTIONS, ['antigravity' => true]),
        ]);
    }

    /**
     * The session page. Only the detail is rendered into the page (header,
     * controls, prompt); the transcript is fetched by ui-session.js from the
     * /history, so there is one renderer for the first
     * page, load-earlier and live polling alike.
     */
    public function session(): void
    {
        AuthService::start_app_session();

        $sessionName = trim((string)($_GET['session'] ?? ''));

        if ($sessionName === '') {
            header('Location: /', true, 303);

            return;
        }

        $csrfToken = AuthService::csrf_token();
        AuthService::close_app_session();

        $detail = AgentClient::agent_call(['action' => 'session_detail', 'session' => $sessionName]);
        $jumpLine = isset($_GET['jump_line']) ? (int)$_GET['jump_line'] : 0;

        echo UiView::session_page([
            'sessionName' => $sessionName,
            'csrfToken' => $csrfToken,
            'found' => (bool)($detail['ok'] ?? false),
            'detail' => $detail,
            'jumpLine' => $jumpLine > 0 ? $jumpLine : null,
            'adviseShown' => UiView::advise_visible($detail, $this->quota_reader()),
        ]);
    }

    /** One memoised `quota` call, made only if a caller needs it. */
    private function quota_reader(): \Closure
    {
        $reply = null;

        return static function () use (&$reply): array {
            return $reply ??= AgentClient::agent_call(['action' => 'quota']);
        };
    }

    /**
     * The host agent's `session_history`, plus `include_thinking` (reasoning blocks).
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

    /**
     * A dormant conversation: the same page shell as the live session page,
     * booted read only. Its transcript comes from archivedHistory().
     */
    public function archivedSession(): void
    {
        AuthService::start_app_session();

        $agentSessionId = trim((string)($_GET['agent_session_id'] ?? ''));

        if ($agentSessionId === '') {
            header('Location: /', true, 303);

            return;
        }

        $csrfToken = AuthService::csrf_token();
        AuthService::close_app_session();

        $detail = AgentClient::agent_call(['action' => 'archived_session_detail', 'agent_session_id' => $agentSessionId]);
        $jumpLine = isset($_GET['jump_line']) ? (int)$_GET['jump_line'] : 0;

        echo UiView::session_page([
            'sessionName' => $agentSessionId,
            'archived' => true,
            'csrfToken' => $csrfToken,
            'found' => (bool)($detail['ok'] ?? false),
            'detail' => $detail,
            'jumpLine' => $jumpLine > 0 ? $jumpLine : null,
        ]);
    }

    /** The classic archived_session_history call as JSON entries, the shape /history returns. */
    public function archivedHistory(): void
    {
        $this->start_readonly_json();

        echo json_encode(AgentClient::agent_call([
            'action' => 'archived_session_history',
            'agent_session_id' => (string)($_GET['agent_session_id'] ?? ''),
            'before' => isset($_GET['before']) ? (int)$_GET['before'] : null,
            'limit' => isset($_GET['limit']) ? (int)$_GET['limit'] : 30,
            'after' => isset($_GET['after']) ? (int)$_GET['after'] : null,
        ]));
    }

    /** Polled by ui.js: the same `list` call as the classic fragment, rendered as cards. */
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
            'counts' => UiView::counts($sessions),
            'sessions_html' => UiView::sessions_html($sessions, $csrfToken, $this->quota_reader()),
            'bare_html' => UiView::bare_html($listResult['bare'] ?? [], $csrfToken),
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

        echo json_encode(['ok' => true, 'archived_html' => UiView::archived_html($result['archived'] ?? [], AuthService::csrf_token())]);
    }

    /**
     * JSON twin of DashboardController::handleAction() for the actions the UI
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
            'advise' => $this->advise(false),
            'advise_act' => $this->advise(true),
            'kill' => $this->agent_plain(['action' => 'launch_kill', 'target' => (string)($_POST['session'] ?? ''), 'force' => true]),
            'take_over' => $this->take_over(),
            'take_over_confirm' => trim((string)($_POST['agent_session_id'] ?? '')) === ''
                ? ['ok' => false, 'message' => 'Pick a conversation to resume']
                : $this->agent_with_redirect([
                    'action' => 'launch_take_over',
                    'target' => (string)(int)($_POST['pid'] ?? 0),
                    'agent_session_id' => trim((string)($_POST['agent_session_id'] ?? '')),
                    'runtime' => 'headless',
                ]),
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

    /**
     * The resume-or-fresh advisor for one session, keyed by session name. `advise_act` carries the person's pick
     * (choice = resume|fresh) and, for a fresh session, answers with a redirect to the new one.
     *
     * @return array<string, mixed>
     */
    private function advise(bool $act): array
    {
        $params = ['action' => 'advise', 'target' => (string)($_POST['session'] ?? '')];
        if ($act) {
            $params += ['act' => true, 'choice' => (string)($_POST['choice'] ?? '')];
        }
        $result = AgentClient::agent_call($params);
        $ok = (bool)($result['ok'] ?? false);
        $out = ['ok' => $ok, 'message' => (string)($result['message'] ?? '')];

        if ($ok && !$act) {
            $row = $result['sessions'][0] ?? [];
            $out['status'] = (string)($row['status'] ?? '');
            $out['advice'] = $row['advice'] ?? null;
        }
        if ($ok && $act && is_string($result['session'] ?? null) && $result['session'] !== '') {
            $out['redirect'] = '/session?session=' . urlencode($result['session']);
        }

        return $out;
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

        // The UI runs everything headless; Antigravity is tmux-only, so it cannot be created here.
        if ($params['agent'] === 'antigravity') {
            return ['ok' => false, 'message' => 'Antigravity needs a terminal and is not available in this UI.'];
        }

        if ($params['agent'] === 'claude') {
            $params['runtime'] = 'headless';
        }

        $prompt = trim((string)($_POST['prompt'] ?? ''));

        if ($prompt === '') {
            return $this->agent_with_redirect($params, 'Created session');
        }

        $created = $this->agent_with_redirect($params, 'Created session');
        $name = (string)(parse_url((string)($created['redirect'] ?? ''), PHP_URL_QUERY) ?? '');
        parse_str($name, $query);

        if (!($created['ok'] ?? false) || !is_string($query['session'] ?? null)) {
            return $created;
        }

        $sent = AgentClient::agent_call(['action' => 'send_message', 'session' => $query['session'], 'text' => $prompt, 'attachment_paths' => []]);

        if (!($sent['ok'] ?? false)) {
            $created['notice'] = 'Session created, but the first message was not sent: ' . (string)($sent['message'] ?? 'unknown error');
        }

        return $created;
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

        $params['runtime'] = 'headless';

        return $this->agent_with_redirect($params);
    }

    /**
     * A bare Claude process becomes a headless session. A confident match is
     * already stopped and resumed (redirect); otherwise nothing was killed and
     * the candidates come back for the page to let a human pick.
     *
     * @return array<string, mixed>
     */
    private function take_over(): array
    {
        $result = AgentClient::agent_call(['action' => 'launch_take_over', 'target' => (string)(int)($_POST['pid'] ?? 0), 'runtime' => 'headless']);

        if (!($result['ok'] ?? false) || !($result['needs_choice'] ?? false)) {
            $out = ['ok' => (bool)($result['ok'] ?? false), 'message' => (string)($result['message'] ?? 'Taken over')];

            if (($out['ok']) && is_string($result['name'] ?? null) && $result['name'] !== '') {
                $out['redirect'] = '/session?session=' . urlencode($result['name']);
            }

            return $out;
        }

        return [
            'ok' => true,
            'needs_choice' => true,
            'pid' => (int)$result['pid'],
            'workdir' => (string)$result['workdir'],
            'candidates' => $result['candidates'] ?? [],
            'suggested_agent_session_id' => $result['suggested_agent_session_id'] ?? null,
        ];
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
     * A call that lands on a live session: the page opens it ((in the UI)
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
            $out['redirect'] = '/session?session=' . urlencode($result['name']);
        }

        return $out;
    }
}
