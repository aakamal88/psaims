<?php
require_once __DIR__ . '/../config/config.php';
requireLogin();

// =====================================================
// Action Plan Tracker — tracker tindak lanjut
// =====================================================

$user_id = $_SESSION['user_id'] ?? null;
$is_admin    = hasRole('admin');
$is_assessor = function_exists('isAssessor') ? isAssessor() : false;

// Admin & Assessor lihat semua, User biasa lihat scope-nya saja
$is_full_view   = $is_admin || $is_assessor;
$is_scoped_view = !$is_full_view;

// Untuk user biasa, hitung scope berdasarkan RASCI mapping role-nya
$scoped_element_ids = [];
$user_role_code     = null;
$user_full_name     = $_SESSION['full_name'] ?? '';

if ($is_scoped_view && $user_id) {
    try {
        // Ambil PSAIMS role code user
        $stmt = $pdo->prepare("SELECT psaims_role_code FROM users WHERE id = ?");
        $stmt->execute([$user_id]);
        $user_role_code = $stmt->fetchColumn();

        // Ambil element_id yang user punya tanggung jawab (R/A/S)
        if ($user_role_code) {
            $stmt = $pdo->prepare(
                "SELECT DISTINCT erm.element_id
                 FROM element_role_mapping erm
                 JOIN psaims_roles pr ON pr.id = erm.role_id
                 WHERE pr.role_code = ?
                   AND erm.responsibility IN ('R', 'A', 'S')"
            );
            $stmt->execute([$user_role_code]);
            $scoped_element_ids = $stmt->fetchAll(PDO::FETCH_COLUMN);
        }
    } catch (Exception $e) {
        // Defensive: kalau struktur tidak match, biarkan kosong
        $scoped_element_ids = [];
    }
}

$msg = ''; $msg_type = '';

// ============ HANDLE POST: Update Status ============
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    try {
        if ($action === 'update_status') {
            $result_id   = (int)$_POST['result_id'];
            $new_status  = $_POST['status'] ?? 'not_started';
            $progress    = (int)($_POST['progress'] ?? 0);
            $notes       = trim($_POST['notes'] ?? '');

            if (!in_array($new_status, ['not_started', 'in_progress', 'completed', 'on_hold', 'cancelled'])) {
                throw new Exception('Status tidak valid.');
            }
            if ($progress < 0 || $progress > 100) {
                throw new Exception('Progress harus 0-100.');
            }

            // ============ OWNERSHIP CHECK untuk non-admin/assessor ============
            if ($is_scoped_view) {
                if (empty($scoped_element_ids)) {
                    throw new Exception('Anda tidak memiliki scope untuk update action plan.');
                }
                $placeholders = implode(',', array_fill(0, count($scoped_element_ids), '?'));
                $check = $pdo->prepare(
                    "SELECT 1 FROM assessment_results ar
                     JOIN assessment_questions q ON q.id = ar.question_id
                     WHERE ar.id = ? AND q.element_id IN ($placeholders)"
                );
                $check_params = array_merge([$result_id], $scoped_element_ids);
                $check->execute($check_params);
                if (!$check->fetchColumn()) {
                    throw new Exception('Anda tidak memiliki akses untuk mengupdate action plan ini. Hanya admin/assessor atau pemilik scope yang berhak.');
                }
            }

            // Auto-set progress berdasarkan status
            if ($new_status === 'not_started') $progress = 0;
            if ($new_status === 'completed')   $progress = 100;

            // Ambil data lama untuk history
            $stmt = $pdo->prepare(
                "SELECT action_status, action_progress FROM assessment_results WHERE id = ?"
            );
            $stmt->execute([$result_id]);
            $old = $stmt->fetch();

            // Update
            $completed_at = $new_status === 'completed' ? 'CURRENT_TIMESTAMP' : 'NULL';
            $stmt = $pdo->prepare(
                "UPDATE assessment_results
                 SET action_status = ?, action_progress = ?,
                     action_notes = ?, action_updated_at = CURRENT_TIMESTAMP,
                     action_updated_by = ?,
                     completed_at = " . ($new_status === 'completed' ? 'CURRENT_TIMESTAMP' : 'NULL') . "
                 WHERE id = ?"
            );
            $stmt->execute([$new_status, $progress, $notes, $user_id, $result_id]);

            // Insert history
            $stmt = $pdo->prepare(
                "INSERT INTO action_plan_history
                 (result_id, old_status, new_status, old_progress, new_progress, notes, changed_by)
                 VALUES (?, ?, ?, ?, ?, ?, ?)"
            );
            $stmt->execute([
                $result_id,
                $old['action_status'] ?? null,
                $new_status,
                $old['action_progress'] ?? 0,
                $progress,
                $notes,
                $user_id
            ]);

            logActivity('ACTION_UPDATE', "Update status action #{$result_id} → {$new_status}");
            $msg = 'Status action plan berhasil diupdate!';
            $msg_type = 'success';
        }
    } catch (Exception $e) {
        $msg = 'Gagal: ' . $e->getMessage();
        $msg_type = 'danger';
    }
}

// ============ FILTERS ============
$session_id   = isset($_GET['session'])   ? (int)$_GET['session']   : 0;
$element_num  = isset($_GET['element'])   ? (int)$_GET['element']   : 0;
$status_filter= $_GET['status']  ?? 'all';
$pic_filter   = trim($_GET['pic'] ?? '');
$due_filter   = $_GET['due'] ?? 'all';   // all, overdue, this_month, upcoming, no_target

// Cek kolom action_status tersedia atau belum (migration guard)
$has_action_columns = false;
try {
    $stmt = $pdo->query(
        "SELECT 1 FROM information_schema.columns
         WHERE table_name = 'assessment_results' AND column_name = 'action_status'"
    );
    $has_action_columns = (bool)$stmt->fetchColumn();
} catch (Exception $ex) {}

// Sessions untuk dropdown
$sessions = $pdo->query(
    "SELECT id, session_name, status FROM assessment_sessions
     ORDER BY session_year DESC, id DESC"
)->fetchAll();

if (!$session_id && !empty($sessions)) {
    foreach ($sessions as $s) {
        if ($s['status'] === 'ongoing') { $session_id = $s['id']; break; }
    }
    if (!$session_id) $session_id = $sessions[0]['id'];
}

$current_session = null;
foreach ($sessions as $s) {
    if ($s['id'] == $session_id) { $current_session = $s; break; }
}

$elements = $pdo->query(
    "SELECT element_number, element_name FROM psaims_elements
     WHERE is_active = TRUE ORDER BY element_number"
)->fetchAll();

// ============ QUERY ACTION PLANS ============
$actions = [];
$stats = [
    'total'       => 0,
    'not_started' => 0,
    'in_progress' => 0,
    'completed'   => 0,
    'on_hold'     => 0,
    'cancelled'   => 0,
    'overdue'     => 0,
    'due_soon'    => 0,
];

if ($session_id && $has_action_columns) {

    // ============ SCOPE FILTER untuk user biasa ============
    // Kalau scoped tapi tidak punya scope sama sekali, skip query
    $skip_query = $is_scoped_view && empty($scoped_element_ids);

    if (!$skip_query) {
    $sql = "SELECT
                ar.id AS result_id,
                ar.score,
                ar.gap_analysis,
                ar.action_plan,
                ar.target_date,
                ar.responsible_person,
                ar.action_status,
                ar.action_progress,
                ar.action_notes,
                ar.completed_at,
                ar.action_updated_at,
                updater.full_name AS updated_by_name,
                q.id AS question_id,
                q.question_number,
                q.criteria,
                e.element_number,
                e.element_name,
                e.icon,
                e.color
            FROM assessment_results ar
            JOIN assessment_questions q ON q.id = ar.question_id
            JOIN psaims_elements e ON e.id = q.element_id
            LEFT JOIN users updater ON updater.id = ar.action_updated_by
            WHERE ar.session_id = ?
              AND ar.action_plan IS NOT NULL
              AND TRIM(ar.action_plan) != ''";

    $params = [$session_id];

    // Filter scope element untuk user biasa
    if ($is_scoped_view && !empty($scoped_element_ids)) {
        $placeholders = implode(',', array_fill(0, count($scoped_element_ids), '?'));
        $sql .= " AND e.id IN ($placeholders)";
        $params = array_merge($params, $scoped_element_ids);
    }

    if ($element_num > 0) {
        $sql .= " AND e.element_number = ?";
        $params[] = $element_num;
    }

    if ($status_filter !== 'all') {
        $sql .= " AND ar.action_status = ?";
        $params[] = $status_filter;
    }

    if ($pic_filter !== '') {
        $sql .= " AND LOWER(ar.responsible_person) LIKE LOWER(?)";
        $params[] = '%' . $pic_filter . '%';
    }

    if ($due_filter === 'overdue') {
        $sql .= " AND ar.target_date < CURRENT_DATE AND ar.action_status != 'completed'";
    } elseif ($due_filter === 'this_month') {
        $sql .= " AND ar.target_date >= CURRENT_DATE
                  AND ar.target_date <= CURRENT_DATE + INTERVAL '30 days'";
    } elseif ($due_filter === 'upcoming') {
        $sql .= " AND ar.target_date > CURRENT_DATE + INTERVAL '30 days'";
    } elseif ($due_filter === 'no_target') {
        $sql .= " AND ar.target_date IS NULL";
    }

    $sql .= " ORDER BY
              CASE ar.action_status
                  WHEN 'in_progress' THEN 1
                  WHEN 'not_started' THEN 2
                  WHEN 'on_hold' THEN 3
                  WHEN 'completed' THEN 4
                  WHEN 'cancelled' THEN 5
              END,
              CASE WHEN ar.target_date < CURRENT_DATE THEN 0 ELSE 1 END,
              ar.target_date ASC NULLS LAST,
              ar.score ASC";

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $actions = $stmt->fetchAll();

    // Stats total (respect scope user biasa)
    $stats_sql = "SELECT
            COUNT(*) AS total,
            COUNT(*) FILTER (WHERE ar.action_status = 'not_started') AS not_started,
            COUNT(*) FILTER (WHERE ar.action_status = 'in_progress') AS in_progress,
            COUNT(*) FILTER (WHERE ar.action_status = 'completed')   AS completed,
            COUNT(*) FILTER (WHERE ar.action_status = 'on_hold')     AS on_hold,
            COUNT(*) FILTER (WHERE ar.action_status = 'cancelled')   AS cancelled,
            COUNT(*) FILTER (WHERE ar.target_date < CURRENT_DATE
                               AND ar.action_status NOT IN ('completed','cancelled')) AS overdue,
            COUNT(*) FILTER (WHERE ar.target_date >= CURRENT_DATE
                               AND ar.target_date <= CURRENT_DATE + INTERVAL '30 days'
                               AND ar.action_status NOT IN ('completed','cancelled')) AS due_soon
         FROM assessment_results ar
         JOIN assessment_questions q ON q.id = ar.question_id
         WHERE ar.session_id = ?
           AND ar.action_plan IS NOT NULL
           AND TRIM(ar.action_plan) != ''";

    $stats_params = [$session_id];

    if ($is_scoped_view && !empty($scoped_element_ids)) {
        $placeholders = implode(',', array_fill(0, count($scoped_element_ids), '?'));
        $stats_sql .= " AND q.element_id IN ($placeholders)";
        $stats_params = array_merge($stats_params, $scoped_element_ids);
    }

    $stmt_stats = $pdo->prepare($stats_sql);
    $stmt_stats->execute($stats_params);
    $stats = $stmt_stats->fetch();
    } // tutup if (!$skip_query)
}

// Status config
$status_config = [
    'not_started' => ['secondary', 'Belum Dimulai', 'circle',        '#6C757D', '#E9ECEF'],
    'in_progress' => ['primary',   'In Progress',   'spinner',       '#007BFF', '#CFE2FF'],
    'completed'   => ['success',   'Selesai',       'check-circle',  '#28A745', '#D4EDDA'],
    'on_hold'     => ['warning',   'Ditunda',       'pause-circle',  '#E0A800', '#FFF3CD'],
    'cancelled'   => ['danger',    'Dibatalkan',    'times-circle',  '#DC3545', '#F8D7DA'],
];

$page_title = 'Action Plan Tracker';
require_once __DIR__ . '/../includes/header.php';
require_once __DIR__ . '/../includes/sidebar.php';
?>

<div class="content-wrapper">
    <section class="content-header">
        <div class="container-fluid">
            <div class="row align-items-center">
                <div class="col-sm-7">
                    <h1><i class="fas fa-tasks text-info"></i> Action Plan Tracker</h1>
                    <small class="text-muted">Monitoring tindak lanjut perbaikan per persyaratan</small>
                </div>
                <div class="col-sm-5 text-right">
                    <button class="btn btn-outline-secondary btn-sm" onclick="window.print()">
                        <i class="fas fa-print"></i> Print
                    </button>
                </div>
            </div>
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

            <?php if ($is_scoped_view): ?>
                <?php if (empty($scoped_element_ids)): ?>
                    <div class="alert alert-warning shadow-sm" style="border-left: 4px solid #ffc107;">
                        <h5 class="mb-1"><i class="fas fa-exclamation-triangle"></i> Anda Belum Punya Scope Action Plan</h5>
                        <p class="mb-0">
                            Role <strong><?= e($user_role_code ?? '—') ?></strong> belum memiliki tanggung jawab
                            (R/A/S) di RASCI mapping manapun. Hubungi <strong>Administrator</strong> untuk dipetakan
                            ke elemen yang sesuai.
                        </p>
                    </div>
                <?php else: ?>
                    <div class="alert alert-info shadow-sm" style="border-left: 4px solid #17a2b8;">
                        <div class="d-flex align-items-center">
                            <i class="fas fa-user-shield fa-2x mr-3 text-info"></i>
                            <div style="flex:1;">
                                <strong>Mode Scope Pribadi</strong>
                                <span class="badge badge-info ml-2"><?= e($user_role_code) ?></span>
                                <br>
                                <small>
                                    Anda melihat action plan dari <strong><?= count($scoped_element_ids) ?> elemen</strong>
                                    yang menjadi tanggung jawab role <strong><?= e($user_role_code) ?></strong>
                                    (Responsible / Accountable / Support).
                                    Anda bisa update progress untuk action plan dalam scope ini.
                                </small>
                            </div>
                        </div>
                    </div>
                <?php endif; ?>
            <?php endif; ?>

            <?php if (!$has_action_columns): ?>
                <div class="alert alert-warning">
                    <h5><i class="fas fa-exclamation-triangle"></i> Migration Belum Dijalankan</h5>
                    <p>Kolom untuk tracking action plan belum ada di database.</p>
                    <p class="mb-0">Jalankan file <code>action_plan_migration.sql</code> di pgAdmin dulu.</p>
                </div>
            <?php elseif (!$current_session): ?>
                <div class="alert alert-info">
                    <i class="fas fa-info-circle"></i> Belum ada periode assessment.
                </div>
            <?php else: ?>

                <!-- ============ PROGRESS OVERVIEW ============ -->
                <?php
                $total = (int)$stats['total'];
                $pct_done = $total > 0 ? round(100 * $stats['completed'] / $total) : 0;
                $pct_progress = $total > 0 ? round(100 * $stats['in_progress'] / $total) : 0;
                $pct_not_started = $total > 0 ? round(100 * $stats['not_started'] / $total) : 0;
                $pct_other = 100 - $pct_done - $pct_progress - $pct_not_started;
                ?>

                <div class="card">
                    <div class="card-body py-3">
                        <div class="d-flex justify-content-between align-items-center mb-2">
                            <h6 class="mb-0">
                                <i class="fas fa-chart-bar"></i> Progress Keseluruhan
                                <span class="text-muted ml-2" style="font-size:12px; font-weight:normal;">
                                    <?= $stats['completed'] ?>/<?= $total ?> selesai (<?= $pct_done ?>%)
                                </span>
                            </h6>
                            <?php if ($stats['overdue'] > 0): ?>
                                <span class="badge badge-danger" style="font-size:12px;">
                                    <i class="fas fa-fire"></i> <?= $stats['overdue'] ?> Terlambat
                                </span>
                            <?php endif; ?>
                        </div>
                        <div class="progress" style="height:24px; font-size:11px;">
                            <?php if ($pct_done > 0): ?>
                                <div class="progress-bar bg-success" style="width:<?= $pct_done ?>%" title="Selesai">
                                    Selesai <?= $pct_done ?>%
                                </div>
                            <?php endif; ?>
                            <?php if ($pct_progress > 0): ?>
                                <div class="progress-bar bg-primary" style="width:<?= $pct_progress ?>%" title="In Progress">
                                    Progress <?= $pct_progress ?>%
                                </div>
                            <?php endif; ?>
                            <?php if ($pct_not_started > 0): ?>
                                <div class="progress-bar bg-secondary" style="width:<?= $pct_not_started ?>%" title="Belum Dimulai">
                                    Belum <?= $pct_not_started ?>%
                                </div>
                            <?php endif; ?>
                            <?php if ($pct_other > 0): ?>
                                <div class="progress-bar bg-warning" style="width:<?= $pct_other ?>%" title="Lain-lain">
                                    <?= $pct_other ?>%
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>

                <!-- ============ STATS CARDS ============ -->
                <div class="row">
                    <div class="col-md-2 col-sm-4 col-6">
                        <div class="small-box" style="background:#E9ECEF;">
                            <div class="inner">
                                <h3 style="color:#495057;"><?= $stats['total'] ?></h3>
                                <p style="color:#495057;">Total</p>
                            </div>
                            <div class="icon"><i class="fas fa-list" style="color:#495057;"></i></div>
                        </div>
                    </div>
                    <div class="col-md-2 col-sm-4 col-6">
                        <div class="small-box bg-secondary">
                            <div class="inner"><h3><?= $stats['not_started'] ?></h3><p>Belum Mulai</p></div>
                            <div class="icon"><i class="far fa-circle"></i></div>
                        </div>
                    </div>
                    <div class="col-md-2 col-sm-4 col-6">
                        <div class="small-box bg-primary">
                            <div class="inner"><h3><?= $stats['in_progress'] ?></h3><p>In Progress</p></div>
                            <div class="icon"><i class="fas fa-spinner"></i></div>
                        </div>
                    </div>
                    <div class="col-md-2 col-sm-4 col-6">
                        <div class="small-box bg-success">
                            <div class="inner"><h3><?= $stats['completed'] ?></h3><p>Selesai</p></div>
                            <div class="icon"><i class="fas fa-check"></i></div>
                        </div>
                    </div>
                    <div class="col-md-2 col-sm-4 col-6">
                        <div class="small-box bg-danger">
                            <div class="inner"><h3><?= $stats['overdue'] ?></h3><p>Terlambat</p></div>
                            <div class="icon"><i class="fas fa-fire"></i></div>
                        </div>
                    </div>
                    <div class="col-md-2 col-sm-4 col-6">
                        <div class="small-box bg-warning">
                            <div class="inner"><h3><?= $stats['due_soon'] ?></h3><p>Due &lt;30 hari</p></div>
                            <div class="icon"><i class="fas fa-clock"></i></div>
                        </div>
                    </div>
                </div>

                <!-- ============ FILTER BAR ============ -->
                <div class="card">
                    <div class="card-header py-2">
                        <h6 class="card-title mb-0"><i class="fas fa-filter"></i> Filter</h6>
                    </div>
                    <div class="card-body py-2">
                        <form method="GET" class="form-inline">
                            <div class="form-group mr-3 mb-2">
                                <label class="mr-1" style="font-size:12px;">Periode:</label>
                                <select name="session" class="form-control form-control-sm">
                                    <?php foreach ($sessions as $s): ?>
                                        <option value="<?= $s['id'] ?>" <?= $s['id'] == $session_id ? 'selected' : '' ?>>
                                            <?= e($s['session_name']) ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="form-group mr-3 mb-2">
                                <label class="mr-1" style="font-size:12px;">Elemen:</label>
                                <select name="element" class="form-control form-control-sm">
                                    <option value="0">Semua</option>
                                    <?php foreach ($elements as $el): ?>
                                        <option value="<?= $el['element_number'] ?>"
                                                <?= $el['element_number'] == $element_num ? 'selected' : '' ?>>
                                            E<?= str_pad($el['element_number'], 2, '0', STR_PAD_LEFT) ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="form-group mr-3 mb-2">
                                <label class="mr-1" style="font-size:12px;">Status:</label>
                                <select name="status" class="form-control form-control-sm">
                                    <option value="all" <?= $status_filter === 'all' ? 'selected' : '' ?>>Semua Status</option>
                                    <?php foreach ($status_config as $k => $cfg): ?>
                                        <option value="<?= $k ?>" <?= $status_filter === $k ? 'selected' : '' ?>>
                                            <?= $cfg[1] ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="form-group mr-3 mb-2">
                                <label class="mr-1" style="font-size:12px;">Due:</label>
                                <select name="due" class="form-control form-control-sm">
                                    <option value="all"        <?= $due_filter === 'all'        ? 'selected' : '' ?>>Semua</option>
                                    <option value="overdue"    <?= $due_filter === 'overdue'    ? 'selected' : '' ?>>Terlambat</option>
                                    <option value="this_month" <?= $due_filter === 'this_month' ? 'selected' : '' ?>>30 hari ke depan</option>
                                    <option value="upcoming"   <?= $due_filter === 'upcoming'   ? 'selected' : '' ?>>Setelah 30 hari</option>
                                    <option value="no_target"  <?= $due_filter === 'no_target'  ? 'selected' : '' ?>>Belum set target</option>
                                </select>
                            </div>
                            <div class="form-group mr-3 mb-2">
                                <label class="mr-1" style="font-size:12px;">PIC:</label>
                                <input type="text" name="pic" value="<?= e($pic_filter) ?>"
                                       placeholder="Nama PIC"
                                       class="form-control form-control-sm" style="width:150px;">
                            </div>
                            <button type="submit" class="btn btn-primary btn-sm mb-2">
                                <i class="fas fa-search"></i> Terapkan
                            </button>
                            <a href="<?= BASE_URL ?>pages/report_action.php" class="btn btn-outline-secondary btn-sm mb-2 ml-1">
                                Reset
                            </a>
                        </form>
                    </div>
                </div>

                <!-- ============ ACTION LIST ============ -->
                <div class="card">
                    <div class="card-header">
                        <h5 class="card-title mb-0">
                            <i class="fas fa-list-check"></i> Daftar Action Plan
                            <span class="badge badge-secondary ml-2"><?= count($actions) ?></span>
                        </h5>
                    </div>
                    <div class="card-body p-0">
                        <?php if (empty($actions)): ?>
                            <div class="text-center p-4 text-muted">
                                <i class="fas fa-inbox fa-3x mb-2" style="opacity:0.3;"></i>
                                <p class="mb-0">
                                    <?php if ($stats['total'] == 0): ?>
                                        Belum ada action plan. Isi kolom "Action Plan" saat assessment.
                                    <?php else: ?>
                                        Tidak ada action yang sesuai dengan filter.
                                    <?php endif; ?>
                                </p>
                            </div>
                        <?php else: ?>
                            <div class="table-responsive">
                                <table class="table table-hover mb-0" style="font-size:12px;">
                                    <thead class="bg-light">
                                        <tr>
                                            <th style="width:40px;">#</th>
                                            <th style="width:120px;">Status</th>
                                            <th>Persyaratan &amp; Action</th>
                                            <th style="width:120px;">PIC</th>
                                            <th style="width:120px;">Target</th>
                                            <th style="width:100px;">Progress</th>
                                            <th style="width:80px;">Aksi</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($actions as $i => $a):
                                            $cfg = $status_config[$a['action_status']] ?? $status_config['not_started'];
                                            [$color, $label, $icon, $hex, $bg] = $cfg;
                                            $progress = (int)$a['action_progress'];

                                            $ref = '';
                                            if (preg_match('/\[Ref\s+([0-9.a-z]+)\]/i', $a['criteria'], $mm)) {
                                                $ref = $mm[1];
                                            }

                                            $target_days = null;
                                            $is_overdue = false;
                                            if ($a['target_date']) {
                                                $target_days = round((strtotime($a['target_date']) - time()) / 86400);
                                                $is_overdue = $target_days < 0 && $a['action_status'] !== 'completed';
                                            }
                                        ?>
                                            <tr <?= $is_overdue ? 'class="table-danger"' : '' ?>>
                                                <td class="text-center"><?= $i + 1 ?></td>
                                                <td>
                                                    <span class="badge badge-<?= $color ?>" style="font-size:11px;">
                                                        <i class="fas fa-<?= $icon ?>"></i> <?= $label ?>
                                                    </span>
                                                </td>
                                                <td>
                                                    <div style="color:#6c757d; font-size:10px;">
                                                        <i class="<?= e($a['icon']) ?> text-<?= e($a['color']) ?>"></i>
                                                        E<?= str_pad($a['element_number'], 2, '0', STR_PAD_LEFT) ?>
                                                        <?= $ref ? "· Ref {$ref}" : '' ?>
                                                        · Skor <?= $a['score'] ?>%
                                                    </div>
                                                    <div style="font-weight:500;">
                                                        <?= e(mb_strimwidth(strip_tags($a['criteria']), 0, 120, '…')) ?>
                                                    </div>
                                                    <div class="mt-1 p-2" style="background:#E7F5FF; border-left:3px solid #17a2b8; border-radius:0 3px 3px 0;">
                                                        <strong>Action:</strong>
                                                        <?= e(mb_strimwidth($a['action_plan'], 0, 180, '…')) ?>
                                                    </div>
                                                    <?php if ($a['action_notes']): ?>
                                                        <div class="mt-1 p-2" style="background:#F1F3F5; border-left:3px solid #6c757d; border-radius:0 3px 3px 0; font-size:11px;">
                                                            <strong>Catatan:</strong>
                                                            <?= e(mb_strimwidth($a['action_notes'], 0, 150, '…')) ?>
                                                        </div>
                                                    <?php endif; ?>
                                                    <?php if ($a['action_updated_at']): ?>
                                                        <div class="mt-1" style="font-size:10px; color:#6c757d;">
                                                            <i class="fas fa-clock"></i>
                                                            Update <?= date('d/m H:i', strtotime($a['action_updated_at'])) ?>
                                                            oleh <?= e($a['updated_by_name'] ?? '?') ?>
                                                        </div>
                                                    <?php endif; ?>
                                                </td>
                                                <td>
                                                    <?= $a['responsible_person'] ? e($a['responsible_person']) : '<small class="text-muted">—</small>' ?>
                                                </td>
                                                <td>
                                                    <?php if ($a['target_date']): ?>
                                                        <div><?= date('d/m/Y', strtotime($a['target_date'])) ?></div>
                                                        <?php if ($is_overdue): ?>
                                                            <small class="text-danger" style="font-size:10px;">
                                                                <i class="fas fa-fire"></i> Terlambat <?= abs($target_days) ?> hari
                                                            </small>
                                                        <?php elseif ($target_days !== null && $target_days <= 30): ?>
                                                            <small class="text-warning" style="font-size:10px;">
                                                                Dalam <?= $target_days ?> hari
                                                            </small>
                                                        <?php elseif ($a['action_status'] === 'completed'): ?>
                                                            <small class="text-success" style="font-size:10px;">
                                                                <i class="fas fa-check"></i> Selesai
                                                                <?php if ($a['completed_at']): ?>
                                                                    <?= date('d/m', strtotime($a['completed_at'])) ?>
                                                                <?php endif; ?>
                                                            </small>
                                                        <?php endif; ?>
                                                    <?php else: ?>
                                                        <small class="text-muted">Belum set</small>
                                                    <?php endif; ?>
                                                </td>
                                                <td>
                                                    <div class="progress" style="height:16px;">
                                                        <div class="progress-bar bg-<?= $color ?>"
                                                             style="width:<?= $progress ?>%; font-size:10px;">
                                                            <?= $progress ?>%
                                                        </div>
                                                    </div>
                                                </td>
                                                <td class="text-center">
                                                    <button type="button"
                                                            class="btn btn-xs btn-outline-primary btn-update-status"
                                                            data-id="<?= $a['result_id'] ?>"
                                                            data-ref="<?= e($ref ?: 'Q' . $a['question_number']) ?>"
                                                            data-status="<?= e($a['action_status']) ?>"
                                                            data-progress="<?= $progress ?>"
                                                            data-notes="<?= e($a['action_notes'] ?? '') ?>"
                                                            data-action="<?= e(mb_strimwidth($a['action_plan'], 0, 100, '…')) ?>"
                                                            title="Update status">
                                                        <i class="fas fa-edit"></i>
                                                    </button>
                                                    <a href="<?= BASE_URL ?>pages/assessment.php?element=<?= $a['element_number'] ?>#q<?= $a['question_id'] ?>"
                                                       class="btn btn-xs btn-outline-secondary" title="Detail">
                                                        <i class="fas fa-external-link-alt"></i>
                                                    </a>
                                                </td>
                                            </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>

            <?php endif; ?>

        </div>
    </section>
</div>

<!-- ============ MODAL: Update Status ============ -->
<div class="modal fade" id="modalUpdateStatus" tabindex="-1">
    <div class="modal-dialog">
        <form method="POST">
            <div class="modal-content">
                <div class="modal-header bg-primary">
                    <h5 class="modal-title text-white">
                        <i class="fas fa-edit"></i> Update Status Action Plan
                        <span id="modal-ref-label" class="ml-2"></span>
                    </h5>
                    <button type="button" class="close text-white" data-dismiss="modal">×</button>
                </div>
                <div class="modal-body">
                    <input type="hidden" name="action" value="update_status">
                    <input type="hidden" name="result_id" id="modal-result-id">

                    <div class="alert alert-light" id="modal-action-preview" style="font-size:12px;"></div>

                    <div class="form-group">
                        <label>Status <span class="text-danger">*</span></label>
                        <select name="status" id="modal-status" class="form-control">
                            <?php foreach ($status_config as $k => $cfg): ?>
                                <option value="<?= $k ?>">
                                    <?= $cfg[1] ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="form-group">
                        <label>Progress (%)</label>
                        <div class="d-flex align-items-center">
                            <input type="range" name="progress" id="modal-progress"
                                   class="custom-range flex-grow-1 mr-2"
                                   min="0" max="100" step="5">
                            <input type="number" id="modal-progress-num"
                                   class="form-control" style="width:80px;"
                                   min="0" max="100">
                            <span class="ml-1">%</span>
                        </div>
                        <small class="text-muted">Auto set ke 0% kalau "Belum Mulai", 100% kalau "Selesai"</small>
                    </div>

                    <div class="form-group">
                        <label>Catatan Update</label>
                        <textarea name="notes" id="modal-notes" class="form-control" rows="3"
                                  placeholder="Catatan progress, kendala, next step..."></textarea>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-dismiss="modal">Batal</button>
                    <button type="submit" class="btn btn-primary">
                        <i class="fas fa-save"></i> Simpan Update
                    </button>
                </div>
            </div>
        </form>
    </div>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>

<script>
jQuery(function($) {
    $('#modalUpdateStatus').appendTo('body');

    $(document).on('click', '.btn-update-status', function(e) {
        e.preventDefault();
        const $btn = $(this);
        $('#modal-result-id').val($btn.attr('data-id'));
        $('#modal-ref-label').text($btn.attr('data-ref'));
        $('#modal-status').val($btn.attr('data-status'));
        $('#modal-progress').val($btn.attr('data-progress'));
        $('#modal-progress-num').val($btn.attr('data-progress'));
        $('#modal-notes').val($btn.attr('data-notes'));
        $('#modal-action-preview').html('<strong>Action:</strong> ' + $btn.attr('data-action'));
        $('#modalUpdateStatus').modal('show');
    });

    // Sync range & number input
    $('#modal-progress').on('input', function() {
        $('#modal-progress-num').val($(this).val());
    });
    $('#modal-progress-num').on('input', function() {
        let v = Math.max(0, Math.min(100, parseInt($(this).val()) || 0));
        $('#modal-progress').val(v);
    });

    // Auto-set progress saat status berubah
    $('#modal-status').on('change', function() {
        const s = $(this).val();
        if (s === 'not_started') {
            $('#modal-progress').val(0);
            $('#modal-progress-num').val(0);
        } else if (s === 'completed') {
            $('#modal-progress').val(100);
            $('#modal-progress-num').val(100);
        }
    });
});
</script>

<style>
.custom-range::-webkit-slider-thumb { background: #007bff; }
@media print {
    .main-sidebar, .main-header, .main-footer, .card-tools,
    .content-header form, .content-header .text-right, .btn { display: none !important; }
    .content-wrapper { margin-left: 0 !important; }
    .card { page-break-inside: avoid; }
}
</style>