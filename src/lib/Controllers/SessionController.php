<?php

declare(strict_types=1);

namespace App\Controllers;

use App\AgentClient;
use App\Services\AuthService;

class SessionController extends Controller
{
    /**
     * GET-only JSON endpoint backing session.php's live info/blocked-prompt
     * panel (polled while the page is visible - see session.php's inline
     * script). Read-only (no state mutated here), so no CSRF/same-origin
     * check is needed - matching GET / itself and QuotaController/
     * BrowseController, which also have none.
     *
     * start_readonly_json()'s AuthService::start_app_session() call isn't
     * for CSRF (nothing to protect on a GET) - it's so a tab left open just
     * watching (polling, never sending/answering) still touches the
     * session on every poll, keeping it alive. Without this, a long-idle-
     * but-open tab's session (and the CSRF token session.php issued at
     * load) could expire via normal PHP session GC while the page itself
     * never notices - the resulting stale-token POST comes
     * back 403 with a plain-text body ("Rejected: missing or invalid CSRF
     * token."), which a fetch().then(r => r.json()) can't parse, surfacing
     * as a bare "Unexpected response" with no indication it was actually a
     * CSRF issue. Most likely to bite an iOS "Add to Home Screen" launch:
     * no refresh gesture exists there at all, and the app can stay resumed
     * in the same JS session for hours.
     */
    public function detail(): void
    {
        $this->start_readonly_json();

        echo json_encode(AgentClient::agent_call(['action' => 'session_detail', 'session' => (string)($_GET['session'] ?? '')]));
    }

    /**
     * GET-only JSON endpoint backing the sidebar's "Plan/handoff files"
     * glance (see session.js's loadPlanFiles()) - read-only, same
     * no-CSRF-needed reasoning as detail() above.
     */
    public function planFiles(): void
    {
        $this->start_readonly_json();

        $sessionName = trim((string)($_GET['session'] ?? ''));

        echo json_encode(AgentClient::agent_call(['action' => 'list_plan_files', 'session' => $sessionName]));
    }

    /**
     * GET-only JSON endpoint backing session.php's sidebar search box -
     * searches this session's ENTIRE transcript server-side (see
     * ArchivedSessionService::session_transcript_search()'s own doc comment for
     * why this can't just filter the DOM the way the archived list's
     * title/name filter does: older messages paginated away via "Load
     * older messages" aren't in the DOM at all). Read-only, same
     * no-CSRF-needed reasoning as detail() above.
     */
    public function search(): void
    {
        $this->start_readonly_json();

        echo json_encode(AgentClient::agent_call([
            'action' => 'session_transcript_search',
            'session' => (string)($_GET['session'] ?? ''),
            'query' => trim((string)($_GET['q'] ?? '')),
            'max_matches' => 20,
        ]));
    }

    /**
     * The archived-session-view counterpart to search() above - same
     * search, keyed by agent_session_id instead of a live tmux name.
     */
    public function archivedSearch(): void
    {
        $this->start_readonly_json();

        echo json_encode(AgentClient::agent_call([
            'action' => 'archived_session_transcript_search',
            'agent_session_id' => (string)($_GET['agent_session_id'] ?? ''),
            'query' => trim((string)($_GET['q'] ?? '')),
            'max_matches' => 20,
        ]));
    }

    /**
     * POST-only JSON endpoint for session.php's message compose box.
     * Unlike a classic form POST + redirect + flash (fine for rare,
     * occasional actions), sending a message is the primary, repeated
     * interaction the compose box exists for, so a full page reload per
     * send would be poor UX. Called via fetch() instead.
     */
    public function send(): void
    {
        $this->require_post_json(); // plain-text 403 body on failure, same as every other POST handler - the JS caller treats a non-JSON response as a generic send failure

        $sessionName = trim((string)($_POST['session'] ?? ''));
        $text = (string)($_POST['message'] ?? '');
        $attachmentPaths = is_array($_POST['attachments'] ?? null) ? array_map('strval', $_POST['attachments']) : [];

        echo json_encode(AgentClient::agent_call(['action' => 'send_message', 'session' => $sessionName, 'text' => $text, 'attachment_paths' => $attachmentPaths]));
    }

    /** POST-only JSON endpoint for the rename button on session cards; an empty title clears the name. */
    public function rename(): void
    {
        $this->require_post_json();

        echo json_encode(AgentClient::agent_call([
            'action' => 'rename_session',
            'session' => trim((string)($_POST['session'] ?? '')),
            'title' => (string)($_POST['title'] ?? ''),
        ]));
    }

    /** POST-only JSON endpoint for the star toggle on session cards. */
    public function star(): void
    {
        $this->require_post_json();

        echo json_encode(AgentClient::agent_call([
            'action' => 'star_session',
            'session' => trim((string)($_POST['session'] ?? '')),
            'starred' => ($_POST['starred'] ?? '') === '1',
        ]));
    }

    /**
     * POST-only JSON endpoint for session.php's mode toggle. Same AJAX
     * pattern as send() above - clicked often enough that a full page
     * reload per click would be poor UX.
     */
    public function setMode(): void
    {
        $this->require_post_json();

        $sessionName = trim((string)($_POST['session'] ?? ''));
        $mode = trim((string)($_POST['mode'] ?? ''));

        echo json_encode(AgentClient::agent_call(['action' => 'set_mode', 'session' => $sessionName, 'mode' => $mode]));
    }

    public function setEffort(): void
    {
        $this->require_post_json();
        if (!is_string($_POST['effort'] ?? null)) {
            echo json_encode(['ok' => false, 'message' => 'Rejected: effort must be supplied as a string']);
            return;
        }
        echo json_encode(AgentClient::agent_call([
            'action' => 'set_effort',
            'session' => trim((string)($_POST['session'] ?? '')),
            'effort' => trim($_POST['effort']),
        ]));
    }

    /**
     * POST-only JSON endpoint for session.php's "Select model" dropdown;
     * same AJAX pattern as setMode() above.
     * Session-only, never saved as the account's new default - see
     * SessionService::set_model()'s own docblock for why that distinction
     * needed driving the real interactive picker rather than just typing
     * `/model <name>` directly.
     */
    public function setModel(): void
    {
        $this->require_post_json();

        $sessionName = trim((string)($_POST['session'] ?? ''));
        $model = trim((string)($_POST['model'] ?? ''));
        $provider = trim((string)($_POST['model_provider'] ?? ''));
        $effort = trim((string)($_POST['effort'] ?? ''));

        echo json_encode(AgentClient::agent_call(['action' => 'set_model', 'session' => $sessionName, 'model' => $model, 'model_provider' => $provider, 'effort' => $effort]));
    }

    /**
     * GET-only JSON endpoint backing the OpenCode (headless) session page's
     * model dropdown - returns the serve's available models (list_models, the
     * sync's cached /config/providers). Read-only.
     */
    public function listModels(): void
    {
        $this->start_readonly_json();

        echo json_encode(AgentClient::agent_call(['action' => 'list_models', 'agent' => (string)($_GET['agent'] ?? 'opencode')]));
    }

    /**
     * GET-only JSON endpoint backing the New Session form's Claude account
     * picker - profile names live in host-agent/
     * config/agents.php, which this container has no direct filesystem
     * access to (same reason listModels() above reaches through the
     * socket rather than reading host-agent state directly) - see
     * AgentClient::agent_call()'s own docblock.
     */
    public function listClaudeProfiles(): void
    {
        $this->start_readonly_json();

        echo json_encode(AgentClient::agent_call(['action' => 'list_claude_profiles']));
    }

    /**
     * POST-only JSON endpoint for session.php's Antigravity "Select model"
     * dropdown - same AJAX pattern as
     * setModel() above, but a DIFFERENT underlying action
     * (set_antigravity_model, not set_model): unlike Claude Code's picker,
     * Antigravity's has no session-only option - this always overwrites the
     * account-wide default model for every future `agy` session. Confirmed
     * live and a deliberate decision, not an oversight - see
     * PromptInteractionService::set_antigravity_model()'s own docblock.
     */
    public function setAntigravityModel(): void
    {
        $this->require_post_json();

        $sessionName = trim((string)($_POST['session'] ?? ''));
        $model = trim((string)($_POST['model'] ?? ''));

        echo json_encode(AgentClient::agent_call(['action' => 'set_antigravity_model', 'session' => $sessionName, 'model' => $model]));
    }

    /**
     * POST-only JSON endpoint for session.php's "stop" button - sends
     * Escape to interrupt whatever Claude is currently doing. Same AJAX
     * pattern as setMode()/send().
     */
    public function escape(): void
    {
        $this->require_post_json();

        $sessionName = trim((string)($_POST['session'] ?? ''));

        echo json_encode(AgentClient::agent_call(['action' => 'send_escape', 'session' => $sessionName]));
    }

    /**
     * GET-only binary endpoint backing an attachment's <img src> (for an
     * image) or download link (for anything else) in the transcript - see
     * App\Views\TranscriptView's attachment rendering. Reads `line` (the
     * raw JSONL line number returned with every transcript entry) and
     * `file_uuid`, never a real host path - the host-agent re-derives the
     * path itself from the transcript file (see TranscriptService::
     * read_attachment()) rather than trusting one from the client.
     */
    public function attachment(): void
    {
        AuthService::start_app_session();

        self::stream_binary_result(AgentClient::agent_call([
            'action' => 'session_attachment',
            'session' => (string)($_GET['session'] ?? ''),
            'line' => (int)($_GET['line'] ?? 0),
            'file_uuid' => (string)($_GET['file_uuid'] ?? ''),
        ]), immutable: true);
    }

    /**
     * The archived-session-view counterpart to attachment() above - same
     * binary-endpoint contract, just backed by archived_session_attachment
     * (keyed by agent_session_id, no live tmux session/sidecar involved).
     */
    public function archivedAttachment(): void
    {
        AuthService::start_app_session();

        self::stream_binary_result(AgentClient::agent_call([
            'action' => 'archived_session_attachment',
            'agent_session_id' => (string)($_GET['agent_session_id'] ?? ''),
            'line' => (int)($_GET['line'] ?? 0),
            'file_uuid' => (string)($_GET['file_uuid'] ?? ''),
        ]), immutable: true);
    }

    /**
     * The sidebar's "Plan/handoff files" glance links straight here now
     * (see planFiles() above for the listing) - opens the real file
     * content in a new tab rather than just naming it. Not immutable
     * (unlike attachment()/archivedAttachment() above) - a plan file can
     * be edited in place at the same filename, so this must never cache
     * hard.
     */
    public function planFileContent(): void
    {
        AuthService::start_app_session();

        self::stream_binary_result(AgentClient::agent_call([
            'action' => 'read_plan_file',
            'session' => (string)($_GET['session'] ?? ''),
            'filename' => (string)($_GET['filename'] ?? ''),
        ]), immutable: false, inlineText: true);
    }

    /**
     * GET-only JSON endpoint backing the sidebar's "Todo" link - opens the session's cwd-level `todo` file in the
     * fullscreen text modal, rather than a new tab. Read-only (no state
     * mutated), same no-CSRF-needed reasoning as detail() above; workdir is
     * re-derived server-side by the host-agent, never accepted from the
     * client.
     */
    public function todoFile(): void
    {
        $this->start_readonly_json();

        echo json_encode(AgentClient::agent_call([
            'action' => 'read_todo_file',
            'session' => trim((string)($_GET['session'] ?? '')),
        ]));
    }

    /**
     * POST-only JSON endpoint, shared by index.php's dashboard rows and
     * session.php's blocked-prompt card - same AJAX pattern as send()/
     * setMode() (replacing the old classic POST+redirect+flash: answering
     * a prompt is common enough that a full page reload per answer was
     * poor UX, same reasoning as compose send).
     */
    public function answerPrompt(): void
    {
        $this->require_post_json();

        $sessionName = trim((string)($_POST['session'] ?? ''));
        $option = (int)($_POST['option'] ?? 0);
        $expectedLabel = trim((string)($_POST['expected_label'] ?? ''));
        $text = trim((string)($_POST['text'] ?? ''));

        // A free-text reply (the "Type something." option) needs the typed
        // text staged and submitted alongside the option - see
        // SessionService::answer_prompt_with_text() in host-agent/lib/.
        // Every other option just sends the bare numbered choice.
        if ($text !== '') {
            echo json_encode(AgentClient::agent_call(['action' => 'answer_prompt_with_text', 'session' => $sessionName, 'option' => $option, 'text' => $text]));
        } else {
            echo json_encode(AgentClient::agent_call(['action' => 'answer_prompt', 'session' => $sessionName, 'option' => $option, 'expected_label' => $expectedLabel]));
        }
    }

    /**
     * POST-only JSON endpoint for a multi-question AskUserQuestion prompt's
     * "answer all questions at once" form (see SessionService::
     * answer_multi_question()) - $_POST['answers'] is a JSON-encoded array,
     * one entry per question in order, since a plain flat form field can't
     * represent "some questions are a single number, others are a list of
     * numbers, others are free text" - decoded here and passed through
     * as-is; the host-agent re-validates every answer against the real
     * questions itself, this controller never trusts the shape blindly.
     */
    public function answerMultiQuestion(): void
    {
        $this->require_post_json();

        $sessionName = trim((string)($_POST['session'] ?? ''));
        $decodedAnswers = json_decode((string)($_POST['answers'] ?? ''), true);
        $answers = is_array($decodedAnswers) ? $decodedAnswers : [];

        echo json_encode(AgentClient::agent_call(['action' => 'answer_multi_question', 'session' => $sessionName, 'answers' => $answers]));
    }
}
