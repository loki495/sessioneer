<?php

declare(strict_types=1);

namespace App\Controllers;

use App\AgentClient;
use App\Services\AuthService;

class UploadController extends Controller
{
    /**
     * GET-only JSON endpoint, polled by session.php's sidebar to show the
     * current session's uploaded files (name/size/total) - same read-only
     * pattern as DashboardController::list()/QuotaController::show()/
     * BrowseController::browse().
     */
    public function list(): void
    {
        $this->start_readonly_json();

        $sessionName = trim((string)($_GET['session'] ?? ''));

        echo json_encode(AgentClient::agent_call(['action' => 'list_uploaded_files', 'session' => $sessionName]));
    }

    /**
     * POST-only JSON endpoint for session.php's compose-bar "+" upload
     * button. Reads the real uploaded file from $_FILES (multipart/form-
     * data - php.ini's upload_max_filesize/post_max_size are raised in
     * docker-compose.yml to actually allow phone-camera-sized files), then
     * relays it to the host-agent as base64 JSON, same as any other
     * action - the container has no direct filesystem access to a
     * session's real project working directory, only the host-agent does
     * (see SessionService::save_uploaded_file() in host-agent/lib/).
     */
    public function upload(): void
    {
        $this->require_post_json();

        // a real phone video upload hit
        // "Allowed memory size exhausted" under the container's default
        // 128M memory_limit. This one request briefly holds several full
        // copies of the file at once - the raw bytes from file_get_contents()
        // below, the ~1.37x-larger base64 string, then json_encode()'s own
        // output string for the whole agent_call() payload - so even a file
        // comfortably under the 64MB app-level cap (see UploadService::
        // max_upload_bytes() in host-agent/) can peak well past 128M.
        // Scoped to just this action (not the container's php.ini) since no
        // other endpoint handles a whole file in memory like this one does.
        ini_set('memory_limit', '512M');

        $sessionName = trim((string)($_POST['session'] ?? ''));
        $file = $_FILES['file'] ?? null;

        if (!is_array($file) || !isset($file['error'])) {
            echo json_encode(['ok' => false, 'message' => 'No file uploaded']);

            return;
        }

        // UPLOAD_ERR_INI_SIZE/FORM_SIZE: over php.ini's upload_max_filesize or
        // the form's own MAX_FILE_SIZE - a friendlier message than the generic
        // fallback, since this is the one upload failure a user is actually
        // likely to hit (a large phone photo/video).
        $error = (int)$file['error'];

        if ($error === UPLOAD_ERR_INI_SIZE || $error === UPLOAD_ERR_FORM_SIZE) {
            echo json_encode(['ok' => false, 'message' => 'File too large']);

            return;
        }

        if ($error !== UPLOAD_ERR_OK) {
            echo json_encode(['ok' => false, 'message' => 'Upload failed (error code ' . $error . ')']);

            return;
        }

        $tmpPath = (string)($file['tmp_name'] ?? '');
        $content = is_uploaded_file($tmpPath) ? @file_get_contents($tmpPath) : false;

        if ($content === false) {
            echo json_encode(['ok' => false, 'message' => 'Could not read the uploaded file']);

            return;
        }

        echo json_encode(AgentClient::agent_call([
            'action' => 'save_uploaded_file',
            'session' => $sessionName,
            'filename' => (string)($file['name'] ?? 'upload'),
            'content_base64' => base64_encode($content),
        ]));
    }

    /**
     * GET-only binary endpoint - the sidebar's "Uploaded files" glance
     * links straight to this now, opening the real file content in a new
     * tab rather than just naming it. Not immutable (see Controller::
     * stream_binary_result()'s own doc comment) - a re-upload can land on
     * the same (de-duplicated) filename with different content.
     */
    public function view(): void
    {
        AuthService::start_app_session();

        self::stream_binary_result(AgentClient::agent_call([
            'action' => 'read_uploaded_file',
            'session' => (string)($_GET['session'] ?? ''),
            'filename' => (string)($_GET['filename'] ?? ''),
        ]), immutable: false, inlineText: true);
    }

    /**
     * POST-only JSON endpoint for the sidebar's per-file delete (x)
     * button - same CSRF/origin-checked, fetch()-called pattern as
     * SessionController::send().
     */
    public function deleteOne(): void
    {
        $this->require_post_json();

        $sessionName = trim((string)($_POST['session'] ?? ''));
        $filename = (string)($_POST['filename'] ?? '');

        echo json_encode(AgentClient::agent_call(['action' => 'delete_uploaded_file', 'session' => $sessionName, 'filename' => $filename]));
    }

    /**
     * POST-only JSON endpoint for the sidebar's "Delete all" uploads
     * button - same pattern as deleteOne() above.
     */
    public function deleteAll(): void
    {
        $this->require_post_json();

        $sessionName = trim((string)($_POST['session'] ?? ''));

        echo json_encode(AgentClient::agent_call(['action' => 'delete_all_uploaded_files', 'session' => $sessionName]));
    }
}
