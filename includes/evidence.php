<?php
/**
 * =====================================================
 * EVIDENCE FILE HELPER
 * =====================================================
 * Taruh di: includes/evidence.php
 *
 * Fungsi: upload, download, delete, slug folder, validasi
 * =====================================================
 */

/**
 * Ambil setting evidence dari DB
 */
function getEvidenceSettings() {
    global $pdo;

    static $cache = null;
    if ($cache !== null) return $cache;

    $defaults = [
        'evidence_base_path'           => 'D:\\PSAIMS_Evidence',
        'evidence_base_url'            => '',
        'evidence_max_size_mb'         => '10',
        'evidence_allowed_ext'         => 'pdf,doc,docx,xls,xlsx,ppt,pptx,jpg,jpeg,png,gif,zip,txt,csv,mp4,mov',
        'evidence_max_files_per_question' => '10',
    ];

    try {
        $stmt = $pdo->query("SELECT setting_key, setting_value FROM app_settings");
        while ($row = $stmt->fetch()) {
            $defaults[$row['setting_key']] = $row['setting_value'];
        }
    } catch (Exception $e) {
        // Tabel belum ada, pakai default
    }

    $cache = $defaults;
    return $cache;
}

/**
 * Slugify nama elemen jadi nama folder aman
 * Contoh: "Kepemimpinan & Komitmen" → "Kepemimpinan_Komitmen"
 */
function slugifyElementFolder($element_number, $element_name) {
    // Hilangkan karakter tidak aman
    $name = preg_replace('/[^a-zA-Z0-9 _-]/u', '', $element_name);
    // Space jadi underscore
    $name = preg_replace('/\s+/', '_', trim($name));
    // Max 50 char
    $name = mb_substr($name, 0, 50);

    $number = str_pad($element_number, 2, '0', STR_PAD_LEFT);
    return "{$number}_{$name}";
}

/**
 * Get full path folder untuk suatu elemen
 */
function getEvidenceFolderPath($element_number, $element_name) {
    $settings = getEvidenceSettings();
    $base     = rtrim($settings['evidence_base_path'], '\\/');
    $folder   = slugifyElementFolder($element_number, $element_name);

    return $base . DIRECTORY_SEPARATOR . $folder;
}

/**
 * Pastikan folder ada, buat kalau belum
 */
function ensureEvidenceFolderExists($element_number, $element_name) {
    $folder_path = getEvidenceFolderPath($element_number, $element_name);

    if (!is_dir($folder_path)) {
        if (!@mkdir($folder_path, 0755, true)) {
            throw new Exception("Tidak bisa buat folder: {$folder_path}. Pastikan base path ada dan writable.");
        }
    }

    if (!is_writable($folder_path)) {
        throw new Exception("Folder tidak writable: {$folder_path}");
    }

    return $folder_path;
}

/**
 * Validasi file upload
 */
function validateEvidenceFile($file) {
    $settings = getEvidenceSettings();

    // Cek error
    if ($file['error'] !== UPLOAD_ERR_OK) {
        $errors = [
            UPLOAD_ERR_INI_SIZE   => 'File terlalu besar (melebihi limit server)',
            UPLOAD_ERR_FORM_SIZE  => 'File terlalu besar',
            UPLOAD_ERR_PARTIAL    => 'Upload tidak complete',
            UPLOAD_ERR_NO_FILE    => 'Tidak ada file dipilih',
            UPLOAD_ERR_NO_TMP_DIR => 'Folder temp tidak tersedia',
            UPLOAD_ERR_CANT_WRITE => 'Gagal tulis ke disk',
            UPLOAD_ERR_EXTENSION  => 'PHP extension block upload',
        ];
        throw new Exception($errors[$file['error']] ?? 'Upload error kode ' . $file['error']);
    }

    // Cek size
    $max_bytes = (int)$settings['evidence_max_size_mb'] * 1024 * 1024;
    if ($file['size'] > $max_bytes) {
        throw new Exception("File terlalu besar. Maksimum " . $settings['evidence_max_size_mb'] . " MB.");
    }

    if ($file['size'] === 0) {
        throw new Exception("File kosong.");
    }

    // Cek extension
    $allowed = array_map('trim', explode(',', strtolower($settings['evidence_allowed_ext'])));
    $allowed = array_filter($allowed);

    $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
    if (!in_array($ext, $allowed, true)) {
        throw new Exception("Extension '.{$ext}' tidak diizinkan. Yang diizinkan: " . implode(', ', $allowed));
    }

    // Blacklist executable (safety net)
    $dangerous = ['php', 'php3', 'php4', 'php5', 'phtml', 'exe', 'bat', 'sh', 'cmd', 'com', 'js', 'vbs', 'html', 'htm'];
    if (in_array($ext, $dangerous, true)) {
        throw new Exception("Extension ini dilarang untuk keamanan.");
    }

    return $ext;
}

/**
 * Generate stored name yang unik
 */
function generateStoredName($session_id, $question_id, $original_name, $ext) {
    $base = pathinfo($original_name, PATHINFO_FILENAME);
    $base = preg_replace('/[^a-zA-Z0-9_-]/u', '_', $base);
    $base = mb_substr($base, 0, 40);

    $timestamp = date('YmdHis');
    $random = substr(md5(uniqid('', true)), 0, 6);

    return "s{$session_id}_q{$question_id}_{$timestamp}_{$random}_{$base}.{$ext}";
}

/**
 * Upload satu file evidence
 * @return array Info file yang ter-upload (dari DB)
 */
function uploadEvidenceFile($file, $context) {
    global $pdo;

    // Required context: session_id, element_id, element_number, element_name, question_id
    $session_id     = $context['session_id']     ?? null;
    $element_id     = $context['element_id']     ?? null;
    $element_number = $context['element_number'] ?? null;
    $element_name   = $context['element_name']   ?? null;
    $question_id    = $context['question_id']    ?? null;
    $result_id      = $context['result_id']      ?? null;
    $description    = $context['description']    ?? null;
    $uploaded_by    = $_SESSION['user_id']       ?? null;

    if (!$session_id || !$element_id || !$question_id) {
        throw new Exception("Context tidak lengkap (session_id, element_id, question_id harus ada).");
    }

    // Validasi
    $ext = validateEvidenceFile($file);

    // Cek jumlah file existing (limit per question per user)
    $settings = getEvidenceSettings();
    $max_files = (int)$settings['evidence_max_files_per_question'];

    $stmt = $pdo->prepare(
        "SELECT COUNT(*) FROM evidence_files
         WHERE session_id = ? AND question_id = ? AND uploaded_by = ? AND is_deleted = FALSE"
    );
    $stmt->execute([$session_id, $question_id, $uploaded_by]);
    $existing_count = $stmt->fetchColumn();

    if ($existing_count >= $max_files) {
        throw new Exception("Sudah mencapai batas maksimum {$max_files} file per pertanyaan.");
    }

    // Buat folder
    $folder_path = ensureEvidenceFolderExists($element_number, $element_name);

    // Generate stored name
    $stored_name = generateStoredName($session_id, $question_id, $file['name'], $ext);
    $full_path = $folder_path . DIRECTORY_SEPARATOR . $stored_name;

    // Relative path untuk simpan di DB (dari base path)
    $folder_slug = slugifyElementFolder($element_number, $element_name);
    $relative_path = $folder_slug . '/' . $stored_name;

    // Move file
    if (!@move_uploaded_file($file['tmp_name'], $full_path)) {
        throw new Exception("Gagal simpan file ke disk: {$full_path}");
    }

    // Insert DB
    try {
        $stmt = $pdo->prepare(
            "INSERT INTO evidence_files
             (result_id, session_id, element_id, question_id,
              original_name, stored_name, relative_path,
              file_size, mime_type, file_extension,
              description, uploaded_by)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
             RETURNING *"
        );
        $stmt->execute([
            $result_id,
            $session_id,
            $element_id,
            $question_id,
            $file['name'],
            $stored_name,
            $relative_path,
            $file['size'],
            $file['type'] ?? null,
            $ext,
            $description,
            $uploaded_by,
        ]);
        return $stmt->fetch();
    } catch (Exception $e) {
        // Rollback: hapus file kalau DB gagal
        @unlink($full_path);
        throw $e;
    }
}

/**
 * Get evidence files untuk suatu pertanyaan
 */
function getEvidenceFiles($session_id, $question_id, $user_id = null) {
    global $pdo;

    $sql = "SELECT * FROM evidence_files
            WHERE session_id = ? AND question_id = ? AND is_deleted = FALSE";
    $params = [$session_id, $question_id];

    if ($user_id !== null) {
        $sql .= " AND uploaded_by = ?";
        $params[] = $user_id;
    }

    $sql .= " ORDER BY uploaded_at DESC";

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    return $stmt->fetchAll();
}

/**
 * Hapus file evidence (soft delete + hapus fisik)
 */
function deleteEvidenceFile($file_id, $user_id) {
    global $pdo;

    // Get file info
    $stmt = $pdo->prepare("SELECT * FROM evidence_files WHERE id = ? AND is_deleted = FALSE");
    $stmt->execute([$file_id]);
    $file = $stmt->fetch();

    if (!$file) throw new Exception("File tidak ditemukan.");

    // Authorization: hanya owner, admin, atau assessor
    $is_owner = $file['uploaded_by'] == $user_id;
    $is_admin = function_exists('isAdmin') && isAdmin();

    if (!$is_owner && !$is_admin) {
        throw new Exception("Tidak diizinkan hapus file ini.");
    }

    // Hapus fisik
    $settings = getEvidenceSettings();
    $full_path = rtrim($settings['evidence_base_path'], '\\/') . DIRECTORY_SEPARATOR .
                 str_replace('/', DIRECTORY_SEPARATOR, $file['relative_path']);

    if (file_exists($full_path)) {
        @unlink($full_path);
    }

    // Soft delete di DB
    $stmt = $pdo->prepare(
        "UPDATE evidence_files
         SET is_deleted = TRUE, deleted_at = CURRENT_TIMESTAMP, deleted_by = ?
         WHERE id = ?"
    );
    $stmt->execute([$user_id, $file_id]);

    return true;
}

/**
 * Get full filesystem path dari evidence file
 */
function getEvidenceFullPath($relative_path) {
    $settings = getEvidenceSettings();
    $base = rtrim($settings['evidence_base_path'], '\\/');
    return $base . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relative_path);
}

/**
 * Format file size human-readable
 */
function formatFileSize($bytes) {
    if ($bytes < 1024) return $bytes . ' B';
    if ($bytes < 1048576) return round($bytes / 1024, 1) . ' KB';
    if ($bytes < 1073741824) return round($bytes / 1048576, 1) . ' MB';
    return round($bytes / 1073741824, 2) . ' GB';
}

/**
 * Icon file berdasarkan extension
 */
function getFileIcon($ext) {
    $ext = strtolower($ext);
    $map = [
        'pdf'  => ['fa-file-pdf',        'danger'],
        'doc'  => ['fa-file-word',       'primary'],
        'docx' => ['fa-file-word',       'primary'],
        'xls'  => ['fa-file-excel',      'success'],
        'xlsx' => ['fa-file-excel',      'success'],
        'ppt'  => ['fa-file-powerpoint', 'warning'],
        'pptx' => ['fa-file-powerpoint', 'warning'],
        'jpg'  => ['fa-file-image',      'info'],
        'jpeg' => ['fa-file-image',      'info'],
        'png'  => ['fa-file-image',      'info'],
        'gif'  => ['fa-file-image',      'info'],
        'zip'  => ['fa-file-archive',    'secondary'],
        'rar'  => ['fa-file-archive',    'secondary'],
        'txt'  => ['fa-file-alt',        'secondary'],
        'csv'  => ['fa-file-csv',        'success'],
        'mp4'  => ['fa-file-video',      'danger'],
        'mov'  => ['fa-file-video',      'danger'],
    ];
    return $map[$ext] ?? ['fa-file', 'secondary'];
}