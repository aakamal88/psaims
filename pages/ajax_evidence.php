<?php
/**
 * AJAX endpoint untuk evidence file operations
 * Taruh di: pages/ajax_evidence.php
 *
 * Actions:
 *   - upload   : Upload file
 *   - delete   : Hapus file
 *   - list     : List file untuk question tertentu
 */

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../includes/evidence.php';

header('Content-Type: application/json');

if (!isLoggedIn()) {
    http_response_code(401);
    echo json_encode(['ok' => false, 'error' => 'Not logged in']);
    exit;
}

$user_id = $_SESSION['user_id'];
$action = $_POST['action'] ?? $_GET['action'] ?? '';

try {
    // ========== UPLOAD ==========
    if ($action === 'upload') {
        if (empty($_FILES['file'])) {
            throw new Exception('File tidak ada di request.');
        }

        $context = [
            'session_id'     => (int)($_POST['session_id']     ?? 0),
            'element_id'     => (int)($_POST['element_id']     ?? 0),
            'element_number' => (int)($_POST['element_number'] ?? 0),
            'element_name'   => $_POST['element_name']         ?? '',
            'question_id'    => (int)($_POST['question_id']    ?? 0),
            'result_id'      => isset($_POST['result_id']) && $_POST['result_id'] ? (int)$_POST['result_id'] : null,
            'description'    => trim($_POST['description'] ?? '') ?: null,
        ];

        $uploaded = uploadEvidenceFile($_FILES['file'], $context);

        logActivity('EVIDENCE_UPLOAD', "Upload file: {$uploaded['original_name']} (E{$context['element_number']}/Q{$context['question_id']})");

        echo json_encode([
            'ok'   => true,
            'file' => [
                'id'            => $uploaded['id'],
                'original_name' => $uploaded['original_name'],
                'stored_name'   => $uploaded['stored_name'],
                'file_size'     => $uploaded['file_size'],
                'size_formatted' => formatFileSize($uploaded['file_size']),
                'file_extension' => $uploaded['file_extension'],
                'uploaded_at'   => $uploaded['uploaded_at'],
            ],
        ]);
    }

    // ========== DELETE ==========
    elseif ($action === 'delete') {
        $file_id = (int)($_POST['file_id'] ?? 0);
        deleteEvidenceFile($file_id, $user_id);
        logActivity('EVIDENCE_DELETE', "Delete evidence file #{$file_id}");
        echo json_encode(['ok' => true]);
    }

    // ========== LIST ==========
    elseif ($action === 'list') {
        $session_id  = (int)($_GET['session_id'] ?? 0);
        $question_id = (int)($_GET['question_id'] ?? 0);
        $all_users   = isset($_GET['all']) && $_GET['all'] == '1';

        if (!$session_id || !$question_id) {
            throw new Exception('Session ID dan Question ID diperlukan.');
        }

        // User biasa: cuma liat file sendiri. Admin/Assessor: semua
        $filter_user = ($all_users && (isAdmin() || isAssessor())) ? null : $user_id;
        $files = getEvidenceFiles($session_id, $question_id, $filter_user);

        // Format untuk output
        $result = [];
        foreach ($files as $f) {
            $result[] = [
                'id'             => $f['id'],
                'original_name'  => $f['original_name'],
                'file_size'      => $f['file_size'],
                'size_formatted' => formatFileSize($f['file_size']),
                'file_extension' => $f['file_extension'],
                'uploaded_at'    => $f['uploaded_at'],
                'uploaded_by'    => $f['uploaded_by'],
                'can_delete'     => ($f['uploaded_by'] == $user_id || isAdmin()),
            ];
        }

        echo json_encode(['ok' => true, 'files' => $result]);
    }

    else {
        throw new Exception('Unknown action');
    }

} catch (Exception $e) {
    echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
}