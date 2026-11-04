<?php

declare(strict_types=1);

use App\Controllers\BrowseController;
use App\Controllers\DashboardController;
use App\Controllers\PushController;
use App\Controllers\QuotaController;
use App\Controllers\SessionController;
use App\Controllers\UploadController;
use App\Controllers\V2Controller;
use App\Http\Router;

$router = new Router();

$router->post('/api/quick-prompt', [\App\Controllers\QuickPromptController::class, 'submit']);
$router->get('/api/quick-prompt', [\App\Controllers\QuickPromptController::class, 'submit']);

// Every endpoint that's POST-only in behavior also registers a GET route
// to the same method - the method's own require_post_json() call is what
// actually produces the 405, not the router. Keeps that 405 (rather than
// public/index.php's 404 for a route with no match at all) for a GET to
// one of these paths.

$router->get('/', [V2Controller::class, 'index']);
$router->post('/', [DashboardController::class, 'handleAction']);

// The previous UI, kept as a fallback beside the redesign: its pages keep their .php URLs.
$router->get('/classic', [DashboardController::class, 'index']);

$router->get('/session', [V2Controller::class, 'session']);
$router->get('/archived-session', [V2Controller::class, 'archivedSession']);

// Old page URLs of the redesign, redirected to the plain ones. Its JSON endpoints keep the /v2 prefix.
$router->get('/v2', [V2Controller::class, 'redirectPage']);
$router->get('/v2/', [V2Controller::class, 'redirectPage']);
$router->get('/v2/session', [V2Controller::class, 'redirectPage']);
$router->get('/v2/archived-session', [V2Controller::class, 'redirectPage']);
$router->get('/v2/history', [V2Controller::class, 'history']);
$router->get('/v2/archived-history', [V2Controller::class, 'archivedHistory']);
$router->get('/v2/fragment', [V2Controller::class, 'fragment']);
$router->get('/v2/archived', [V2Controller::class, 'archived']);
$router->get('/v2/action', [V2Controller::class, 'action']);
$router->post('/v2/action', [V2Controller::class, 'action']);

$router->get('/sessions_fragment.php', [DashboardController::class, 'fragment']);
$router->get('/sessions_list.php', [DashboardController::class, 'list']);
$router->get('/sidebar_sessions.php', [DashboardController::class, 'sidebarFragment']);
$router->get('/archived_sessions_fragment.php', [DashboardController::class, 'archivedFragment']);
$router->get('/take_over_bare.php', [DashboardController::class, 'takeOverBare']);
$router->post('/take_over_bare.php', [DashboardController::class, 'takeOverBare']);
$router->get('/take_over_bare_confirm.php', [DashboardController::class, 'takeOverBareConfirm']);
$router->post('/take_over_bare_confirm.php', [DashboardController::class, 'takeOverBareConfirm']);
$router->get('/bare_process_detail.php', [DashboardController::class, 'bareProcessDetail']);
$router->get('/search_sessions.php', [DashboardController::class, 'search']);

// Reads `session` from either GET or POST with no method check at all.
$router->get('/session.php', [SessionController::class, 'show']);
$router->post('/session.php', [SessionController::class, 'show']);

$router->get('/session_detail.php', [SessionController::class, 'detail']);
$router->get('/session_plan_files.php', [SessionController::class, 'planFiles']);
$router->get('/session_plan_file.php', [SessionController::class, 'planFileContent']);
$router->get('/session_todo_file.php', [SessionController::class, 'todoFile']);
$router->get('/session_history.php', [SessionController::class, 'history']);
$router->get('/session_attachment.php', [SessionController::class, 'attachment']);
$router->get('/session_search.php', [SessionController::class, 'search']);

// Reads `agent_session_id` from either GET or POST, same as session.php's own `session` param.
$router->get('/archived_session.php', [SessionController::class, 'showArchived']);
$router->post('/archived_session.php', [SessionController::class, 'showArchived']);
$router->get('/archived_session_history_fragment.php', [SessionController::class, 'archivedHistoryFragment']);
$router->get('/archived_session_attachment.php', [SessionController::class, 'archivedAttachment']);
$router->get('/archived_session_search.php', [SessionController::class, 'archivedSearch']);

$router->get('/session_send.php', [SessionController::class, 'send']);
$router->post('/session_send.php', [SessionController::class, 'send']);
$router->post('/session_rename.php', [SessionController::class, 'rename']);
$router->post('/session_star.php', [SessionController::class, 'star']);
$router->get('/session_mode.php', [SessionController::class, 'setMode']);
$router->post('/session_mode.php', [SessionController::class, 'setMode']);
$router->get('/session_effort.php', [SessionController::class, 'setEffort']);
$router->post('/session_effort.php', [SessionController::class, 'setEffort']);
$router->get('/session_model.php', [SessionController::class, 'setModel']);
$router->post('/session_model.php', [SessionController::class, 'setModel']);
$router->get('/session_antigravity_model.php', [SessionController::class, 'setAntigravityModel']);
$router->post('/session_antigravity_model.php', [SessionController::class, 'setAntigravityModel']);
$router->get('/session_list_models.php', [SessionController::class, 'listModels']);
$router->get('/session_list_claude_profiles.php', [SessionController::class, 'listClaudeProfiles']);
$router->get('/session_escape.php', [SessionController::class, 'escape']);
$router->post('/session_escape.php', [SessionController::class, 'escape']);
$router->get('/answer_prompt.php', [SessionController::class, 'answerPrompt']);
$router->post('/answer_prompt.php', [SessionController::class, 'answerPrompt']);
$router->get('/answer_multi_question.php', [SessionController::class, 'answerMultiQuestion']);
$router->post('/answer_multi_question.php', [SessionController::class, 'answerMultiQuestion']);

$router->get('/browse.php', [BrowseController::class, 'browse']);
$router->get('/create_folder.php', [BrowseController::class, 'mkdir']);
$router->post('/create_folder.php', [BrowseController::class, 'mkdir']);

$router->get('/uploaded_files.php', [UploadController::class, 'list']);
$router->get('/uploaded_file_view.php', [UploadController::class, 'view']);
$router->get('/upload_file.php', [UploadController::class, 'upload']);
$router->post('/upload_file.php', [UploadController::class, 'upload']);
$router->get('/delete_uploaded_file.php', [UploadController::class, 'deleteOne']);
$router->post('/delete_uploaded_file.php', [UploadController::class, 'deleteOne']);
$router->get('/delete_all_uploaded_files.php', [UploadController::class, 'deleteAll']);
$router->post('/delete_all_uploaded_files.php', [UploadController::class, 'deleteAll']);

$router->get('/push_subscribe.php', [PushController::class, 'subscribe']);
$router->post('/push_subscribe.php', [PushController::class, 'subscribe']);
$router->get('/push_unsubscribe.php', [PushController::class, 'unsubscribe']);
$router->post('/push_unsubscribe.php', [PushController::class, 'unsubscribe']);

$router->get('/quota.php', [QuotaController::class, 'show']);

return $router;
