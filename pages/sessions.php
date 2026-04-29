<?php
require_once __DIR__ . '/../config/config.php';
requireLogin();

if (!canViewAdmin()) {
    die('Akses ditolak.');
}

// Safety net: tolak POST request dari non-admin
blockNonAdminPost();

$msg = ''; $msg_type = '';
$user_id = $_SESSION['user_id'] ?? null;

// ============ HANDLE POST ACTIONS ============
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    try {
        // ========== CREATE SESSION ==========
        if ($action === 'create_session') {
            $name   = trim($_POST['session_name'] ?? '');
            $year   = (int)($_POST['session_year'] ?? date('Y'));
            $period = $_POST['session_period'] ?? '';
            $start  = $_POST['start_date'] ?? null;
            $end    = $_POST['end_date']   ?? null;
            $status = $_POST['status']     ?? 'draft';

            if (empty($name)) throw new Exception('Nama periode wajib diisi.');
            if (empty($period)) throw new Exception('Pilih periode (Q1/Q2/Q3/Q4/Annual).');
            if ($year < 2020 || $year > 2100) throw new Exception('Tahun tidak valid.');
            if ($start && $end && $start > $end) {
                throw new Exception('Tanggal mulai harus sebelum tanggal selesai.');
            }

            $stmt = $pdo->prepare(
                "INSERT INTO assessment_sessions
                 (session_name, session_year, session_period, start_date, end_date, status, created_by)
                 VALUES (?, ?, ?, ?, ?, ?, ?)"
            );
            $stmt->execute([$name, $year, $period, $start ?: null, $end ?: null, $status, $user_id]);
            logActivity('SESSION_CREATE', "Buat periode: {$name}");
            $msg = "Periode <strong>" . e($name) . "</strong> berhasil dibuat!";
            $msg_type = 'success';
        }

        // ========== UPDATE SESSION ==========
        elseif ($action === 'update_session') {
            $id     = (int)$_POST['session_id'];
            $name   = trim($_POST['session_name'] ?? '');
            $year   = (int)$_POST['session_year'];
            $period = $_POST['session_period'] ?? '';
            $start  = $_POST['start_date'] ?: null;
            $end    = $_POST['end_date']   ?: null;

            if (empty($name)) throw new Exception('Nama periode wajib diisi.');

            $stmt = $pdo->prepare(
                "UPDATE assessment_sessions
                 SET session_name = ?, session_year = ?, session_period = ?,
                     start_date = ?, end_date = ?
                 WHERE id = ?"
            );
            $stmt->execute([$name, $year, $period, $start, $end, $id]);
            logActivity('SESSION_UPDATE', "Update periode #{$id}");
            $msg = "Periode berhasil diupdate!";
            $msg_type = 'success';
        }

        // ========== CHANGE STATUS ==========
        elseif ($action === 'change_status') {
            $id = (int)$_POST['session_id'];
            $new_status = $_POST['new_status'] ?? '';

            if (!in_array($new_status, ['draft', 'ongoing', 'completed', 'closed'])) {
                throw new Exception('Status tidak valid.');
            }

            // Kalau aktifkan ke 'ongoing', pastikan tidak ada session ongoing lain
            if ($new_status === 'ongoing') {
                $stmt = $pdo->prepare(
                    "SELECT COUNT(*) FROM assessment_sessions
                     WHERE status = 'ongoing' AND id != ?"
                );
                $stmt->execute([$id]);
                if ($stmt->fetchColumn() > 0) {
                    throw new Exception('Hanya boleh ada 1 periode aktif (ongoing) pada satu waktu. Tutup periode aktif lainnya terlebih dulu.');
                }
            }

            $stmt = $pdo->prepare(
                "UPDATE assessment_sessions SET status = ? WHERE id = ?"
            );
            $stmt->execute([$new_status, $id]);
            logActivity('SESSION_STATUS', "Status periode #{$id} → {$new_status}");
            $msg = "Status periode berhasil diubah ke <strong>{$new_status}</strong>.";
            $msg_type = 'success';
        }

        // ========== DUPLICATE SESSION ==========
        elseif ($action === 'duplicate_session') {
            $source_id = (int)$_POST['session_id'];
            $new_name  = trim($_POST['new_name'] ?? '');
            $new_year  = (int)($_POST['new_year'] ?? date('Y'));
            $new_period= $_POST['new_period'] ?? '';
            $copy_data = !empty($_POST['copy_data']);

            if (empty($new_name)) throw new Exception('Nama periode baru wajib diisi.');
            if (empty($new_period)) throw new Exception('Pilih periode baru.');

            $pdo->beginTransaction();

            // Insert session baru
            $stmt = $pdo->prepare(
                "INSERT INTO assessment_sessions
                 (session_name, session_year, session_period, status, created_by)
                 VALUES (?, ?, ?, 'draft', ?)
                 RETURNING id"
            );
            $stmt->execute([$new_name, $new_year, $new_period, $user_id]);
            $new_id = $stmt->fetchColumn();

            // Kalau copy data, copy semua assessment_results dari source
            $copied_count = 0;
            if ($copy_data) {
                $stmt = $pdo->prepare(
                    "INSERT INTO assessment_results
                     (session_id, element_id, question_id, user_id, score,
                      evidence, gap_analysis, action_plan, target_date, responsible_person,
                      filled_as_role)
                     SELECT ?, element_id, question_id, user_id, score,
                            evidence, gap_analysis, action_plan, target_date, responsible_person,
                            filled_as_role
                     FROM assessment_results
                     WHERE session_id = ?"
                );
                $stmt->execute([$new_id, $source_id]);
                $copied_count = $stmt->rowCount();
            }

            $pdo->commit();
            logActivity('SESSION_DUPLICATE', "Duplicate session #{$source_id} → #{$new_id}");
            $msg = "Periode <strong>" . e($new_name) . "</strong> berhasil dibuat"
                 . ($copy_data ? " dengan copy {$copied_count} jawaban" : "") . ".";
            $msg_type = 'success';
        }

        // ========== DELETE SESSION ==========
        elseif ($action === 'delete_session') {
            $id = (int)$_POST['session_id'];

            // Cek ada jawaban atau tidak
            $stmt = $pdo->prepare(
                "SELECT COUNT(*) FROM assessment_results WHERE session_id = ?"
            );
            $stmt->execute([$id]);
            $answer_count = $stmt->fetchColumn();

            if ($answer_count > 0 && empty($_POST['force_delete'])) {
                throw new Exception(
                    "Periode ini punya {$answer_count} jawaban assessment. " .
                    "Kalau yakin mau hapus beserta datanya, centang 'Paksa hapus' di modal."
                );
            }

            $stmt = $pdo->prepare("DELETE FROM assessment_sessions WHERE id = ?");
            $stmt->execute([$id]);
            logActivity('SESSION_DELETE', "Delete periode #{$id}");
            $msg = "Periode berhasil dihapus" .
                   ($answer_count > 0 ? " beserta {$answer_count} jawaban." : ".");
            $msg_type = 'success';
        }

    } catch (Exception $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        $msg = 'Gagal: ' . $e->getMessage();
        $msg_type = 'danger';
    }
}

// ============ FETCH DATA ============
$sessions = $pdo->query(
    "SELECT s.*,
            (SELECT COUNT(*) FROM assessment_results WHERE session_id = s.id) AS answer_count,
            (SELECT COUNT(DISTINCT user_id) FROM assessment_results WHERE session_id = s.id) AS contributor_count,
            (SELECT COALESCE(AVG(score), 0)::numeric(5,1) FROM assessment_results WHERE session_id = s.id) AS avg_score,
            (SELECT full_name FROM users WHERE id = s.created_by) AS created_by_name
     FROM assessment_sessions s
     ORDER BY s.session_year DESC, s.id DESC"
)->fetchAll();

// Total questions untuk hitung progress
$total_questions = $pdo->query(
    "SELECT COUNT(*) FROM assessment_questions WHERE is_active = TRUE"
)->fetchColumn();

// Status config
$status_config = [
    'draft'     => ['secondary', 'Draft',       'edit',         'Belum aktif, bisa diedit'],
    'ongoing'   => ['success',   'Aktif',       'play-circle',  'Sedang berjalan, user bisa mengisi'],
    'completed' => ['primary',   'Selesai',     'check-circle', 'Pengisian selesai, belum di-close'],
    'closed'    => ['dark',      'Ditutup',     'lock',         'Terkunci, read-only'],
];

$period_options = ['Q1', 'Q2', 'Q3', 'Q4', 'Annual', 'Mid-Year'];
$current_year = (int)date('Y');

$page_title = 'Periode Assessment';
require_once __DIR__ . '/../includes/header.php';
require_once __DIR__ . '/../includes/sidebar.php';
?>

<div class="content-wrapper">
    <section class="content-header">
        <div class="container-fluid">
            <div class="row align-items-center">
                <div class="col-sm-7">
                    <h1><i class="fas fa-calendar-alt text-primary"></i> Periode Assessment</h1>
                    <small class="text-muted">Kelola periode assessment PSAIMS</small>
                </div>
                <div class="col-sm-5 text-right">
                    <?php if (canAdminister()): ?>
                    <button class="btn btn-success" data-toggle="modal" data-target="#modalCreateSession">
                        <i class="fas fa-plus"></i> Buat Periode Baru
                    </button>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </section>

    <section class="content">
        <div class="container-fluid">

            <?= readOnlyBanner() ?>

            <?php if ($msg): ?>
                <div class="alert alert-<?= $msg_type ?> alert-dismissible">
                    <button type="button" class="close" data-dismiss="alert">×</button>
                    <?= $msg ?>
                </div>
            <?php endif; ?>

            <!-- ============ INFO BANNER ============ -->
            <div class="alert alert-light border" style="font-size:13px;">
                <div class="row">
                    <div class="col-md-6">
                        <strong><i class="fas fa-info-circle text-info"></i> Alur Status Periode:</strong>
                        <div class="mt-1">
                            <span class="badge badge-secondary">Draft</span>
                            <i class="fas fa-arrow-right mx-1"></i>
                            <span class="badge badge-success">Aktif</span>
                            <i class="fas fa-arrow-right mx-1"></i>
                            <span class="badge badge-primary">Selesai</span>
                            <i class="fas fa-arrow-right mx-1"></i>
                            <span class="badge badge-dark">Ditutup</span>
                        </div>
                    </div>
                    <div class="col-md-6">
                        <strong><i class="fas fa-lightbulb text-warning"></i> Tips:</strong>
                        <ul class="mb-0 mt-1" style="font-size:12px;">
                            <li>Hanya 1 periode boleh <strong>Aktif</strong> pada satu waktu</li>
                            <li>Periode yang <strong>Ditutup</strong> tidak bisa diedit lagi (read-only)</li>
                            <li>Duplikat periode untuk jadikan baseline periode baru</li>
                        </ul>
                    </div>
                </div>
            </div>

            <!-- ============ SESSIONS TABLE ============ -->
            <div class="card">
                <div class="card-header">
                    <h5 class="card-title mb-0">
                        <i class="fas fa-list"></i> Daftar Periode
                        <span class="badge badge-secondary ml-2"><?= count($sessions) ?></span>
                    </h5>
                </div>
                <div class="card-body p-0">
                    <?php if (empty($sessions)): ?>
                        <div class="text-center p-5 text-muted">
                            <i class="fas fa-calendar-plus fa-3x mb-3" style="opacity:0.3;"></i>
                            <p>Belum ada periode assessment. Klik tombol "Buat Periode Baru" untuk memulai.</p>
                        </div>
                    <?php else: ?>
                        <div class="table-responsive">
                            <table class="table table-hover mb-0" style="font-size:13px;">
                                <thead class="bg-light">
                                    <tr>
                                        <th style="width:50px;">#</th>
                                        <th>Nama Periode</th>
                                        <th style="width:80px;">Tahun</th>
                                        <th style="width:80px;">Periode</th>
                                        <th style="width:180px;">Tanggal</th>
                                        <th style="width:100px;">Status</th>
                                        <th style="width:140px;">Progress</th>
                                        <th style="width:90px;">Rata-Rata</th>
                                        <?php if (canAdminister()): ?>
                                        <th style="width:180px;">Aksi</th>
                                        <?php endif; ?>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($sessions as $i => $s):
                                        $cfg = $status_config[$s['status']] ?? $status_config['draft'];
                                        [$color, $label, $icon, $desc] = $cfg;
                                        $progress = $total_questions > 0 && $s['contributor_count'] > 0
                                            ? round(100 * $s['answer_count'] / ($total_questions * max(1, $s['contributor_count'])))
                                            : 0;
                                        $progress = min(100, $progress);
                                        $is_locked = $s['status'] === 'closed';
                                    ?>
                                        <tr <?= $s['status'] === 'ongoing' ? 'style="background:#F0FFF4;"' : '' ?>>
                                            <td class="text-center">
                                                <strong><?= $s['id'] ?></strong>
                                            </td>
                                            <td>
                                                <strong><?= e($s['session_name']) ?></strong>
                                                <?php if ($s['created_by_name']): ?>
                                                    <br>
                                                    <small class="text-muted">
                                                        Dibuat oleh <?= e($s['created_by_name']) ?>
                                                        · <?= date('d/m/Y', strtotime($s['created_at'])) ?>
                                                    </small>
                                                <?php endif; ?>
                                            </td>
                                            <td><?= $s['session_year'] ?></td>
                                            <td>
                                                <span class="badge badge-info"><?= e($s['session_period']) ?></span>
                                            </td>
                                            <td style="font-size:12px;">
                                                <?php if ($s['start_date']): ?>
                                                    <i class="fas fa-play-circle text-success"></i>
                                                    <?= date('d M Y', strtotime($s['start_date'])) ?>
                                                <?php else: ?>
                                                    <small class="text-muted">Mulai: —</small>
                                                <?php endif; ?>
                                                <br>
                                                <?php if ($s['end_date']): ?>
                                                    <i class="fas fa-stop-circle text-danger"></i>
                                                    <?= date('d M Y', strtotime($s['end_date'])) ?>
                                                <?php else: ?>
                                                    <small class="text-muted">Selesai: —</small>
                                                <?php endif; ?>
                                            </td>
                                            <td>
                                                <span class="badge badge-<?= $color ?>" title="<?= e($desc) ?>"
                                                      style="font-size:11px;">
                                                    <i class="fas fa-<?= $icon ?>"></i> <?= $label ?>
                                                </span>
                                            </td>
                                            <td>
                                                <?php if ($s['answer_count'] > 0): ?>
                                                    <div class="progress" style="height:14px;">
                                                        <div class="progress-bar bg-<?= $color ?>"
                                                             style="width:<?= $progress ?>%; font-size:10px;">
                                                            <?= $progress ?>%
                                                        </div>
                                                    </div>
                                                    <small style="font-size:10px;">
                                                        <?= $s['answer_count'] ?> jawaban ·
                                                        <?= $s['contributor_count'] ?> user
                                                    </small>
                                                <?php else: ?>
                                                    <small class="text-muted">Belum ada data</small>
                                                <?php endif; ?>
                                            </td>
                                            <td class="text-center">
                                                <?php if ($s['answer_count'] > 0): ?>
                                                    <strong style="color:#17a2b8;">
                                                        <?= number_format((float)$s['avg_score'], 1) ?>%
                                                    </strong>
                                                <?php else: ?>
                                                    <small class="text-muted">—</small>
                                                <?php endif; ?>
                                            </td>
                                            <?php if (canAdminister()): ?>
                                            <td>
                                                <div class="btn-group btn-group-sm" role="group">
                                                    <!-- Status dropdown -->
                                                    <button type="button"
                                                            class="btn btn-outline-primary dropdown-toggle btn-xs"
                                                            data-toggle="dropdown"
                                                            title="Ubah status">
                                                        <i class="fas fa-flag"></i>
                                                    </button>
                                                    <div class="dropdown-menu">
                                                        <?php foreach ($status_config as $k => $c):
                                                            if ($k === $s['status']) continue;
                                                        ?>
                                                            <form method="POST" class="m-0">
                                                                <input type="hidden" name="action" value="change_status">
                                                                <input type="hidden" name="session_id" value="<?= $s['id'] ?>">
                                                                <input type="hidden" name="new_status" value="<?= $k ?>">
                                                                <button type="submit" class="dropdown-item"
                                                                        onclick="return confirm('Ubah status ke <?= e($c[1]) ?>?');">
                                                                    <i class="fas fa-<?= $c[2] ?> text-<?= $c[0] ?>"></i>
                                                                    <?= $c[1] ?>
                                                                </button>
                                                            </form>
                                                        <?php endforeach; ?>
                                                    </div>

                                                    <?php if (!$is_locked): ?>
                                                        <button type="button"
                                                                class="btn btn-outline-secondary btn-xs btn-edit-session"
                                                                data-id="<?= $s['id'] ?>"
                                                                data-name="<?= e($s['session_name']) ?>"
                                                                data-year="<?= $s['session_year'] ?>"
                                                                data-period="<?= e($s['session_period']) ?>"
                                                                data-start="<?= e($s['start_date']) ?>"
                                                                data-end="<?= e($s['end_date']) ?>"
                                                                title="Edit">
                                                            <i class="fas fa-edit"></i>
                                                        </button>
                                                    <?php endif; ?>

                                                    <button type="button"
                                                            class="btn btn-outline-info btn-xs btn-duplicate"
                                                            data-id="<?= $s['id'] ?>"
                                                            data-name="<?= e($s['session_name']) ?>"
                                                            data-has-data="<?= $s['answer_count'] > 0 ? '1' : '0' ?>"
                                                            title="Duplicate">
                                                        <i class="fas fa-copy"></i>
                                                    </button>

                                                    <?php if (!$is_locked): ?>
                                                        <button type="button"
                                                                class="btn btn-outline-danger btn-xs btn-delete"
                                                                data-id="<?= $s['id'] ?>"
                                                                data-name="<?= e($s['session_name']) ?>"
                                                                data-answers="<?= $s['answer_count'] ?>"
                                                                title="Hapus">
                                                            <i class="fas fa-trash"></i>
                                                        </button>
                                                    <?php else: ?>
                                                        <button class="btn btn-outline-dark btn-xs" disabled title="Periode ditutup">
                                                            <i class="fas fa-lock"></i>
                                                        </button>
                                                    <?php endif; ?>
                                                </div>
                                            </td>
                                            <?php endif; ?>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    <?php endif; ?>
                </div>
            </div>

        </div>
    </section>
</div>

<!-- ============ MODAL: Create Session ============ -->
<div class="modal fade" id="modalCreateSession" tabindex="-1">
    <div class="modal-dialog">
        <form method="POST">
            <div class="modal-content">
                <div class="modal-header bg-success">
                    <h5 class="modal-title text-white"><i class="fas fa-plus"></i> Buat Periode Baru</h5>
                    <button type="button" class="close text-white" data-dismiss="modal">×</button>
                </div>
                <div class="modal-body">
                    <input type="hidden" name="action" value="create_session">

                    <div class="form-group">
                        <label>Nama Periode <span class="text-danger">*</span></label>
                        <input type="text" name="session_name" class="form-control" required
                               placeholder="Contoh: PSAIMS Assessment Q1 2026">
                    </div>

                    <div class="row">
                        <div class="col-md-6">
                            <div class="form-group">
                                <label>Tahun <span class="text-danger">*</span></label>
                                <input type="number" name="session_year" class="form-control"
                                       value="<?= $current_year ?>" required min="2020" max="2100">
                            </div>
                        </div>
                        <div class="col-md-6">
                            <div class="form-group">
                                <label>Periode <span class="text-danger">*</span></label>
                                <select name="session_period" class="form-control" required>
                                    <option value="">— Pilih —</option>
                                    <?php foreach ($period_options as $p): ?>
                                        <option value="<?= $p ?>"><?= $p ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                        </div>
                    </div>

                    <div class="row">
                        <div class="col-md-6">
                            <div class="form-group">
                                <label>Tanggal Mulai</label>
                                <input type="date" name="start_date" class="form-control">
                            </div>
                        </div>
                        <div class="col-md-6">
                            <div class="form-group">
                                <label>Tanggal Selesai</label>
                                <input type="date" name="end_date" class="form-control">
                            </div>
                        </div>
                    </div>

                    <div class="form-group">
                        <label>Status Awal</label>
                        <select name="status" class="form-control">
                            <option value="draft">Draft — bisa diedit, belum bisa diisi user</option>
                            <option value="ongoing">Aktif — user bisa langsung mengisi</option>
                        </select>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-dismiss="modal">Batal</button>
                    <button type="submit" class="btn btn-success">
                        <i class="fas fa-plus"></i> Buat Periode
                    </button>
                </div>
            </div>
        </form>
    </div>
</div>

<!-- ============ MODAL: Edit Session ============ -->
<div class="modal fade" id="modalEditSession" tabindex="-1">
    <div class="modal-dialog">
        <form method="POST">
            <div class="modal-content">
                <div class="modal-header bg-primary">
                    <h5 class="modal-title text-white"><i class="fas fa-edit"></i> Edit Periode</h5>
                    <button type="button" class="close text-white" data-dismiss="modal">×</button>
                </div>
                <div class="modal-body">
                    <input type="hidden" name="action" value="update_session">
                    <input type="hidden" name="session_id" id="edit-id">

                    <div class="form-group">
                        <label>Nama Periode <span class="text-danger">*</span></label>
                        <input type="text" name="session_name" id="edit-name" class="form-control" required>
                    </div>

                    <div class="row">
                        <div class="col-md-6">
                            <div class="form-group">
                                <label>Tahun</label>
                                <input type="number" name="session_year" id="edit-year" class="form-control">
                            </div>
                        </div>
                        <div class="col-md-6">
                            <div class="form-group">
                                <label>Periode</label>
                                <select name="session_period" id="edit-period" class="form-control">
                                    <?php foreach ($period_options as $p): ?>
                                        <option value="<?= $p ?>"><?= $p ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                        </div>
                    </div>

                    <div class="row">
                        <div class="col-md-6">
                            <div class="form-group">
                                <label>Tanggal Mulai</label>
                                <input type="date" name="start_date" id="edit-start" class="form-control">
                            </div>
                        </div>
                        <div class="col-md-6">
                            <div class="form-group">
                                <label>Tanggal Selesai</label>
                                <input type="date" name="end_date" id="edit-end" class="form-control">
                            </div>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-dismiss="modal">Batal</button>
                    <button type="submit" class="btn btn-primary">
                        <i class="fas fa-save"></i> Simpan Perubahan
                    </button>
                </div>
            </div>
        </form>
    </div>
</div>

<!-- ============ MODAL: Duplicate Session ============ -->
<div class="modal fade" id="modalDuplicate" tabindex="-1">
    <div class="modal-dialog">
        <form method="POST">
            <div class="modal-content">
                <div class="modal-header bg-info">
                    <h5 class="modal-title text-white"><i class="fas fa-copy"></i> Duplikat Periode</h5>
                    <button type="button" class="close text-white" data-dismiss="modal">×</button>
                </div>
                <div class="modal-body">
                    <input type="hidden" name="action" value="duplicate_session">
                    <input type="hidden" name="session_id" id="dup-id">

                    <div class="alert alert-info" style="font-size:12px;">
                        Duplikat dari: <strong id="dup-source-name"></strong>
                    </div>

                    <div class="form-group">
                        <label>Nama Periode Baru <span class="text-danger">*</span></label>
                        <input type="text" name="new_name" id="dup-name" class="form-control" required
                               placeholder="Contoh: PSAIMS Assessment Q2 2026">
                    </div>

                    <div class="row">
                        <div class="col-md-6">
                            <div class="form-group">
                                <label>Tahun</label>
                                <input type="number" name="new_year" class="form-control"
                                       value="<?= $current_year ?>">
                            </div>
                        </div>
                        <div class="col-md-6">
                            <div class="form-group">
                                <label>Periode</label>
                                <select name="new_period" class="form-control" required>
                                    <option value="">— Pilih —</option>
                                    <?php foreach ($period_options as $p): ?>
                                        <option value="<?= $p ?>"><?= $p ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                        </div>
                    </div>

                    <div class="form-group" id="dup-data-option" style="display:none;">
                        <div class="custom-control custom-switch">
                            <input type="checkbox" name="copy_data" id="dup-copy-data"
                                   class="custom-control-input" value="1">
                            <label class="custom-control-label" for="dup-copy-data">
                                <strong>Copy data jawaban sebagai baseline</strong>
                            </label>
                        </div>
                        <small class="text-muted">
                            Berguna untuk continuous assessment — jawaban lama jadi starting point, tinggal update yang berubah.
                        </small>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-dismiss="modal">Batal</button>
                    <button type="submit" class="btn btn-info">
                        <i class="fas fa-copy"></i> Duplikat
                    </button>
                </div>
            </div>
        </form>
    </div>
</div>

<!-- ============ MODAL: Delete Session ============ -->
<div class="modal fade" id="modalDelete" tabindex="-1">
    <div class="modal-dialog">
        <form method="POST">
            <div class="modal-content">
                <div class="modal-header bg-danger">
                    <h5 class="modal-title text-white"><i class="fas fa-trash"></i> Hapus Periode</h5>
                    <button type="button" class="close text-white" data-dismiss="modal">×</button>
                </div>
                <div class="modal-body">
                    <input type="hidden" name="action" value="delete_session">
                    <input type="hidden" name="session_id" id="del-id">

                    <p>Anda akan menghapus periode: <strong id="del-name"></strong></p>

                    <div id="del-warning" style="display:none;">
                        <div class="alert alert-warning">
                            <i class="fas fa-exclamation-triangle"></i>
                            Periode ini punya <strong id="del-count">0</strong> jawaban assessment.
                            Jawaban akan <strong>ikut terhapus</strong> secara permanen (cascade).
                        </div>
                        <div class="custom-control custom-checkbox">
                            <input type="checkbox" name="force_delete" id="del-force"
                                   class="custom-control-input" value="1" required>
                            <label class="custom-control-label text-danger" for="del-force">
                                <strong>Saya yakin mau hapus periode beserta semua datanya</strong>
                            </label>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-dismiss="modal">Batal</button>
                    <button type="submit" class="btn btn-danger">
                        <i class="fas fa-trash"></i> Hapus Permanen
                    </button>
                </div>
            </div>
        </form>
    </div>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>

<script>
jQuery(function($) {
    $('#modalCreateSession, #modalEditSession, #modalDuplicate, #modalDelete').appendTo('body');

    // Edit
    $(document).on('click', '.btn-edit-session', function() {
        const $btn = $(this);
        $('#edit-id').val($btn.attr('data-id'));
        $('#edit-name').val($btn.attr('data-name'));
        $('#edit-year').val($btn.attr('data-year'));
        $('#edit-period').val($btn.attr('data-period'));
        $('#edit-start').val($btn.attr('data-start'));
        $('#edit-end').val($btn.attr('data-end'));
        $('#modalEditSession').modal('show');
    });

    // Duplicate
    $(document).on('click', '.btn-duplicate', function() {
        const $btn = $(this);
        $('#dup-id').val($btn.attr('data-id'));
        $('#dup-source-name').text($btn.attr('data-name'));
        $('#dup-name').val($btn.attr('data-name') + ' (copy)');
        $('#dup-copy-data').prop('checked', false);
        if ($btn.attr('data-has-data') === '1') {
            $('#dup-data-option').show();
        } else {
            $('#dup-data-option').hide();
        }
        $('#modalDuplicate').modal('show');
    });

    // Delete
    $(document).on('click', '.btn-delete', function() {
        const $btn = $(this);
        const count = parseInt($btn.attr('data-answers')) || 0;
        $('#del-id').val($btn.attr('data-id'));
        $('#del-name').text($btn.attr('data-name'));
        $('#del-count').text(count);
        if (count > 0) {
            $('#del-warning').show();
            $('#del-force').prop('required', true);
        } else {
            $('#del-warning').hide();
            $('#del-force').prop('required', false);
        }
        $('#modalDelete').modal('show');
    });
});
</script>