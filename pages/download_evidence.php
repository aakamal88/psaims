<?php
/**
 * Download endpoint untuk evidence file
 * Taruh di: pages/download_evidence.php
 *
 * Usage: download_evidence.php?id=123
 *
 * Security:
 * - Hanya user yang login
 * - Owner bisa download file sendiri
 * - Admin/Assessor bisa download semua
 * - Prevent path traversal
 */

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../includes/evidence.php';

requireLogin();

$file_id = (int)($_GET['id'] ?? 0);

if (!$file_id) {
    http_response_code(400);
    die('Invalid file ID');
}

try {
    // Get file record
    $stmt = $pdo->prepare(
        "SELECT ef.*, e.element_name, e.element_number
         FROM evidence_files ef
         LEFT JOIN psaims_elements e ON e.id = ef.element_id
         WHERE ef.id = ? AND ef.is_deleted = FALSE"
    );
    $stmt->execute([$file_id]);
    $file = $stmt->fetch();

    if (!$file) {
        http_response_code(404);
        die('File tidak ditemukan atau sudah dihapus.');
    }

    // Authorization check
    $is_owner    = $file['uploaded_by'] == $_SESSION['user_id'];
    $is_reviewer = function_exists('canVerify') && canVerify();

    if (!$is_owner && !$is_reviewer) {
        http_response_code(403);
        die('Tidak berhak download file ini.');
    }

    // Build full path
    $full_path = getEvidenceFullPath($file['relative_path']);

    // Prevent path traversal
    $settings = getEvidenceSettings();
    $real_base = realpath($settings['evidence_base_path']);
    $real_file = realpath($full_path);

    if (!$real_base || !$real_file || strpos($real_file, $real_base) !== 0) {
        http_response_code(403);
        die('Invalid file path.');
    }

    if (!file_exists($full_path)) {
        http_response_code(404);
        die('File tidak ada di disk. Mungkin sudah dipindah atau dihapus manual.');
    }

    // Log download
    try {
        logActivity('EVIDENCE_DOWNLOAD', "Download: {$file['original_name']}");
    } catch (Exception $e) {}

    // Serve file
    $mime = $file['mime_type'] ?: 'application/octet-stream';

    // Decide: inline (preview) atau download attachment
    $preview_types = ['pdf', 'jpg', 'jpeg', 'png', 'gif', 'txt'];
    $disposition = in_array(strtolower($file['file_extension']), $preview_types) && !isset($_GET['dl'])
        ? 'inline'
        : 'attachment';

    // Clean output buffer
    if (ob_get_length()) ob_end_clean();

    header('Content-Type: ' . $mime);
    header('Content-Length: ' . filesize($full_path));
    header('Content-Disposition: ' . $disposition . '; filename="' . basename($file['original_name']) . '"');
    header('Cache-Control: private, no-cache, must-revalidate');
    header('Pragma: no-cache');
    header('X-Content-Type-Options: nosniff');

    readfile($full_path);
    exit;

} catch (Exception $e) {
    http_response_code(500);
    die('Error: ' . htmlspecialchars($e->getMessage()));
}