<?php
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../includes/evidence.php';
requireLogin();

if (!canAdminister()) {
    die('Akses ditolak. Hanya admin.');
}

$msg = ''; $msg_type = '';

// ============ SAVE SETTINGS ============
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        $updates = [
            'evidence_base_path'            => trim($_POST['evidence_base_path'] ?? ''),
            'evidence_base_url'             => trim($_POST['evidence_base_url'] ?? ''),
            'evidence_max_size_mb'          => (int)($_POST['evidence_max_size_mb'] ?? 10),
            'evidence_allowed_ext'          => trim($_POST['evidence_allowed_ext'] ?? ''),
            'evidence_max_files_per_question' => (int)($_POST['evidence_max_files_per_question'] ?? 10),
        ];

        // Validasi
        if (empty($updates['evidence_base_path'])) {
            throw new Exception('Base path wajib diisi.');
        }
        if ($updates['evidence_max_size_mb'] < 1 || $updates['evidence_max_size_mb'] > 500) {
            throw new Exception('Max size harus antara 1-500 MB.');
        }
        if ($updates['evidence_max_files_per_question'] < 1 || $updates['evidence_max_files_per_question'] > 100) {
            throw new Exception('Max files per question harus 1-100.');
        }

        // Test base path: coba create directory
        $test_base = rtrim($updates['evidence_base_path'], '\\/');
        if (!is_dir($test_base)) {
            if (!@mkdir($test_base, 0755, true)) {
                throw new Exception("Tidak bisa buat folder: {$test_base}. Buat manual dulu atau pastikan parent dir writable.");
            }
        }
        if (!is_writable($test_base)) {
            throw new Exception("Folder {$test_base} tidak writable. Cek permission folder.");
        }

        // Save ke DB
        $pdo->beginTransaction();
        $stmt = $pdo->prepare(
            "INSERT INTO app_settings (setting_key, setting_value, updated_by, updated_at)
             VALUES (?, ?, ?, CURRENT_TIMESTAMP)
             ON CONFLICT (setting_key)
             DO UPDATE SET setting_value = EXCLUDED.setting_value,
                           updated_by = EXCLUDED.updated_by,
                           updated_at = EXCLUDED.updated_at"
        );
        foreach ($updates as $key => $value) {
            $stmt->execute([$key, (string)$value, $_SESSION['user_id']]);
        }
        $pdo->commit();

        logActivity('EVIDENCE_SETTINGS', 'Update evidence storage settings');
        $msg = '✓ Settings berhasil disimpan.';
        $msg_type = 'success';
    } catch (Exception $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        $msg = 'Gagal: ' . $e->getMessage();
        $msg_type = 'danger';
    }
}

// Get current settings (force refresh)
$settings = [];
$stmt = $pdo->query("SELECT setting_key, setting_value FROM app_settings");
while ($row = $stmt->fetch()) {
    $settings[$row['setting_key']] = $row['setting_value'];
}

// Default values
$settings['evidence_base_path']              = $settings['evidence_base_path']             ?? 'D:\\PSAIMS_Evidence';
$settings['evidence_base_url']               = $settings['evidence_base_url']              ?? '';
$settings['evidence_max_size_mb']            = $settings['evidence_max_size_mb']           ?? '10';
$settings['evidence_allowed_ext']            = $settings['evidence_allowed_ext']           ?? 'pdf,doc,docx,xls,xlsx,ppt,pptx,jpg,jpeg,png,gif,zip,txt,csv,mp4,mov';
$settings['evidence_max_files_per_question'] = $settings['evidence_max_files_per_question'] ?? '10';

// Cek status folder
$base_path = $settings['evidence_base_path'];
$folder_exists = is_dir($base_path);
$folder_writable = $folder_exists && is_writable($base_path);

// Estimasi total size
$total_size = 0;
$total_files = 0;
try {
    $stmt = $pdo->query("SELECT COALESCE(SUM(file_size), 0) AS size, COUNT(*) AS cnt FROM evidence_files WHERE is_deleted = FALSE");
    $row = $stmt->fetch();
    $total_size = $row['size'];
    $total_files = $row['cnt'];
} catch (Exception $e) {}

$page_title = 'Evidence Storage Settings';
require_once __DIR__ . '/../includes/header.php';
require_once __DIR__ . '/../includes/sidebar.php';
?>

<div class="content-wrapper">
    <section class="content-header">
        <div class="container-fluid">
            <h1><i class="fas fa-cog text-primary"></i> Evidence Storage Settings</h1>
            <small class="text-muted">Konfigurasi lokasi dan aturan upload file evidence</small>
        </div>
    </section>

    <section class="content">
        <div class="container-fluid">

            <?php if ($msg): ?>
                <div class="alert alert-<?= $msg_type ?> alert-dismissible">
                    <button type="button" class="close" data-dismiss="alert">×</button>
                    <?= $msg ?>
                </div>
            <?php endif; ?>

            <div class="row">
                <div class="col-md-8">
                    <form method="POST">
                        <div class="card">
                            <div class="card-header">
                                <h5 class="card-title mb-0"><i class="fas fa-folder"></i> Storage Location</h5>
                            </div>
                            <div class="card-body">
                                <div class="form-group">
                                    <label>Base Directory Path <span class="text-danger">*</span></label>
                                    <input type="text" name="evidence_base_path" class="form-control"
                                           value="<?= e($settings['evidence_base_path']) ?>"
                                           placeholder="D:\PSAIMS_Evidence atau /var/www/evidence" required>
                                    <small class="text-muted">
                                        <i class="fas fa-info-circle"></i>
                                        Path absolute tempat file akan disimpan. Gunakan <code>\\</code> untuk Windows atau <code>/</code> untuk Linux.
                                        <br>
                                        Contoh Windows: <code>D:\PSAIMS_Evidence</code> atau <code>C:\inetpub\wwwroot\PTG_PSAIMS\uploads\evidence</code>
                                    </small>
                                </div>

                                <div class="form-group">
                                    <label>Base URL (opsional)</label>
                                    <input type="text" name="evidence_base_url" class="form-control"
                                           value="<?= e($settings['evidence_base_url']) ?>"
                                           placeholder="(kosongkan untuk pakai endpoint PHP download — lebih aman)">
                                    <small class="text-muted">
                                        Kosongkan kalau pakai <code>download_evidence.php</code>. Isi kalau folder evidence bisa diakses langsung via web (misal kalau di dalam web root).
                                    </small>
                                </div>
                            </div>
                        </div>

                        <div class="card">
                            <div class="card-header">
                                <h5 class="card-title mb-0"><i class="fas fa-shield-alt"></i> Upload Rules</h5>
                            </div>
                            <div class="card-body">
                                <div class="row">
                                    <div class="col-md-6">
                                        <div class="form-group">
                                            <label>Max Size per File (MB)</label>
                                            <input type="number" name="evidence_max_size_mb" class="form-control"
                                                   value="<?= e($settings['evidence_max_size_mb']) ?>" min="1" max="500" required>
                                            <small class="text-muted">Juga cek <code>upload_max_filesize</code> di <code>php.ini</code> (harus sama atau lebih besar)</small>
                                        </div>
                                    </div>
                                    <div class="col-md-6">
                                        <div class="form-group">
                                            <label>Max File per Pertanyaan</label>
                                            <input type="number" name="evidence_max_files_per_question" class="form-control"
                                                   value="<?= e($settings['evidence_max_files_per_question']) ?>" min="1" max="100" required>
                                            <small class="text-muted">Per user per pertanyaan per session</small>
                                        </div>
                                    </div>
                                </div>

                                <div class="form-group">
                                    <label>Allowed Extensions</label>
                                    <input type="text" name="evidence_allowed_ext" class="form-control"
                                           value="<?= e($settings['evidence_allowed_ext']) ?>"
                                           placeholder="pdf,doc,docx,xls,xlsx,jpg,png,...">
                                    <small class="text-muted">
                                        Pisahkan dengan koma, tanpa titik. Contoh: <code>pdf,docx,jpg,png,mp4</code>
                                        <br>
                                        <strong class="text-danger">⚠️ Extension executable (exe, bat, php, dll) otomatis diblokir.</strong>
                                    </small>
                                </div>
                            </div>
                        </div>

                        <div class="card">
                            <div class="card-body text-right">
                                <button type="submit" class="btn btn-primary">
                                    <i class="fas fa-save"></i> Simpan Settings
                                </button>
                            </div>
                        </div>
                    </form>
                </div>

                <div class="col-md-4">
                    <div class="card">
                        <div class="card-header bg-info">
                            <h5 class="card-title mb-0 text-white"><i class="fas fa-info-circle"></i> Status</h5>
                        </div>
                        <div class="card-body">
                            <p><strong>Base Directory:</strong></p>
                            <code style="font-size:11px;"><?= e($base_path) ?></code>
                            <hr>

                            <p style="font-size:13px; margin-bottom:8px;">
                                <strong>Folder ada?</strong>
                                <?php if ($folder_exists): ?>
                                    <span class="badge badge-success float-right"><i class="fas fa-check"></i> Ya</span>
                                <?php else: ?>
                                    <span class="badge badge-warning float-right"><i class="fas fa-times"></i> Belum</span>
                                <?php endif; ?>
                            </p>

                            <p style="font-size:13px; margin-bottom:8px;">
                                <strong>Writable?</strong>
                                <?php if ($folder_writable): ?>
                                    <span class="badge badge-success float-right"><i class="fas fa-check"></i> Ya</span>
                                <?php else: ?>
                                    <span class="badge badge-danger float-right"><i class="fas fa-times"></i> Tidak</span>
                                <?php endif; ?>
                            </p>

                            <hr>

                            <p style="font-size:13px; margin-bottom:8px;">
                                <strong>Total file:</strong>
                                <span class="float-right"><?= $total_files ?> file</span>
                            </p>
                            <p style="font-size:13px; margin-bottom:0;">
                                <strong>Total size:</strong>
                                <span class="float-right"><?= formatFileSize($total_size) ?></span>
                            </p>
                        </div>
                    </div>

                    <div class="card">
                        <div class="card-header bg-warning">
                            <h5 class="card-title mb-0"><i class="fas fa-exclamation-triangle"></i> Penting</h5>
                        </div>
                        <div class="card-body" style="font-size:12px;">
                            <ul class="pl-3">
                                <li><strong>Jangan pindah base path</strong> kalau sudah ada file! File existing akan hilang.</li>
                                <li>Kalau perlu ganti path, <strong>pindahkan semua folder elemen</strong> dulu ke path baru.</li>
                                <li>Backup folder base path secara berkala.</li>
                                <li>Cek <code>upload_max_filesize</code> dan <code>post_max_size</code> di <code>php.ini</code> juga.</li>
                            </ul>
                        </div>
                    </div>

                    <div class="card">
                        <div class="card-header bg-light">
                            <h5 class="card-title mb-0"><i class="fas fa-folder-tree"></i> Struktur Folder</h5>
                        </div>
                        <div class="card-body" style="font-size:11px; font-family:monospace;">
                            <?= e(basename($base_path)) ?>/<br>
                            &nbsp;&nbsp;├─ 01_Kepemimpinan/<br>
                            &nbsp;&nbsp;├─ 02_Kebijakan/<br>
                            &nbsp;&nbsp;├─ 03_Regulasi/<br>
                            &nbsp;&nbsp;├─ ...<br>
                            &nbsp;&nbsp;├─ 10_Integritas_Aset/<br>
                            &nbsp;&nbsp;├─ ...<br>
                            &nbsp;&nbsp;└─ 18_Tinjauan/
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </section>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>