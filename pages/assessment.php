<?php
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../includes/evidence.php';
requireLogin();

// Ambil data user yang sedang login
$user = currentUser();
if (!$user) {
    die('Session tidak valid. Silakan <a href="' . BASE_URL . 'login.php">login ulang</a>.');
}

$element_number = isset($_GET['element']) ? (int)$_GET['element'] : 0;

// Ambil data elemen
$stmt = $pdo->prepare("SELECT * FROM psaims_elements WHERE element_number = ? AND is_active = TRUE");
$stmt->execute([$element_number]);
$element = $stmt->fetch();

if (!$element) {
    die('Elemen tidak ditemukan. <a href="' . BASE_URL . 'index.php">Kembali</a>');
}

// ============ RBAC RASCI v2: Multi-level per user ============
$role_code        = $_SESSION['psaims_role_code'] ?? null;
$is_admin         = hasRole('admin');
$my_levels        = [];     // Array semua level yg dimiliki user: ['A','R'] atau ['S'] dll
$responsibility   = null;   // Level tertinggi yang dipakai untuk kontrol akses

if (!$is_admin && $role_code) {
    $stmt = $pdo->prepare(
        "SELECT m.responsibility
         FROM element_role_mapping m
         JOIN psaims_roles r ON r.id = m.role_id
         WHERE m.element_id = ? AND r.role_code = ?"
    );
    $stmt->execute([$element['id'], $role_code]);
    $my_levels = array_unique($stmt->fetchAll(PDO::FETCH_COLUMN));

    if (empty($my_levels)) {
        // Tidak ditugaskan sama sekali → tolak
        $page_title = 'Akses Ditolak';
        require_once __DIR__ . '/../includes/header.php';
        require_once __DIR__ . '/../includes/sidebar.php';
        echo '<div class="content-wrapper"><section class="content pt-4"><div class="container-fluid">';
        echo '<div class="alert alert-danger shadow-sm">';
        echo '<h4><i class="icon fas fa-lock"></i> Akses Ditolak</h4>';
        echo '<p>Role Anda (<strong>' . e($role_code) . '</strong>) tidak termasuk dalam RASCI ';
        echo 'matrix untuk Elemen <strong>' . $element_number .
             ' — ' . e($element['element_name']) . '</strong>.</p>';
        echo '<a href="' . BASE_URL . 'index.php" class="btn btn-secondary">';
        echo '<i class="fas fa-arrow-left"></i> Kembali</a>';
        echo '</div></div></section></div>';
        require_once __DIR__ . '/../includes/footer.php';
        exit;
    }

    // Ambil level tertinggi untuk kontrol akses (A > R > S > C > I)
    $priority = ['A' => 1, 'R' => 2, 'S' => 3, 'C' => 4, 'I' => 5];
    usort($my_levels, fn($a, $b) => $priority[$a] - $priority[$b]);
    $responsibility = $my_levels[0];
}

// ============ Matrix Hak Akses RASCI ============
// A atau R di level mana pun → isi skor penuh
// S → isi evidence/gap/action saja (tidak skor)
// C/I → read-only
$has_A             = in_array('A', $my_levels);
$has_R             = in_array('R', $my_levels);
$has_S             = in_array('S', $my_levels);
$can_fill_score    = $is_admin || $has_A || $has_R;
$can_fill_evidence = $is_admin || $has_A || $has_R || $has_S;
$can_approve       = $is_admin || $has_A;
$is_readonly       = !$can_fill_evidence;

// Ambil semua pertanyaan
$stmt = $pdo->prepare(
    "SELECT * FROM assessment_questions
     WHERE element_id = ? AND is_active = TRUE
     ORDER BY question_number"
);
$stmt->execute([$element['id']]);
$questions = $stmt->fetchAll();

// ============ RASCI Per-Question Override (dengan toggle per-elemen) ============
// Cek toggle use_question_mode di psaims_elements:
//   - Kalau TRUE → pakai question_role_mapping
//   - Kalau FALSE → fallback ke element_role_mapping (default behavior)
$question_levels = [];
$has_question_override = false;

// Cek toggle dulu
$use_question_mode = !empty($element['use_question_mode']);

if ($use_question_mode && !empty($questions)) {
    $qids = array_column($questions, 'id');
    $placeholders = implode(',', array_fill(0, count($qids), '?'));

    // Cek ada mapping pertanyaan yang valid
    $stmt = $pdo->prepare(
        "SELECT COUNT(DISTINCT question_id) AS cnt
         FROM question_role_mapping
         WHERE question_id IN ($placeholders)"
    );
    $stmt->execute($qids);
    $has_question_override = ($stmt->fetchColumn() > 0);

    if ($has_question_override && !$is_admin && $role_code) {
        $params = array_merge([$role_code], $qids);
        $stmt = $pdo->prepare(
            "SELECT m.question_id, m.responsibility
             FROM question_role_mapping m
             JOIN psaims_roles r ON r.id = m.role_id
             WHERE r.role_code = ? AND m.question_id IN ($placeholders)"
        );
        $stmt->execute($params);
        while ($row = $stmt->fetch()) {
            $question_levels[$row['question_id']][] = $row['responsibility'];
        }
    }
}

// Helper: dapat level user untuk pertanyaan tertentu
// Return ['A','R'] atau ['S'] atau ['I'] dll
function getQuestionLevels($qid, $question_levels, $my_levels, $has_override) {
    if ($has_override) {
        // Pakai override per-question (kalau ada), kalau tidak → user tidak diassign di soal itu
        return $question_levels[$qid] ?? ['I'];  // Default Informed kalau tidak ada
    }
    // Fallback ke level element-wide
    return $my_levels;
}

// Ambil sesi aktif
$stmt = $pdo->query(
    "SELECT * FROM assessment_sessions
     WHERE status = 'ongoing'
     ORDER BY created_at DESC LIMIT 1"
);
$active_session = $stmt->fetch();

// Ambil daftar role yang ditugaskan untuk elemen ini (untuk banner admin)
$element_roles = [];
$stmt = $pdo->prepare(
    "SELECT r.role_code, r.role_name, m.responsibility
     FROM element_role_mapping m
     JOIN psaims_roles r ON r.id = m.role_id
     WHERE m.element_id = ?
     ORDER BY m.responsibility, r.role_code"
);
$stmt->execute([$element['id']]);
$element_roles = $stmt->fetchAll();

// Handle submit
$msg = ''; $msg_type = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $active_session) {

    // Guard: Level C/I tidak boleh save apapun
    if (!$can_fill_evidence) {
        $msg = 'Role Anda (' . ($responsibility ?? '') . ') hanya bisa melihat data, tidak bisa menyimpan.';
        $msg_type = 'danger';
    } else {
        // Ambil "Isi sebagai role" kalau admin
        $fill_as_role = null;
        if ($is_admin && !empty($_POST['fill_as_role'])) {
            $fill_as_role = $_POST['fill_as_role'];
        } elseif (!$is_admin) {
            $fill_as_role = $_SESSION['psaims_role_code'] ?? null;
        }

    try {
        $pdo->beginTransaction();
        foreach ($_POST['score'] ?? [] as $qid => $score) {
            if ($score === '' || $score === null) continue;

            $evidence    = $_POST['evidence'][$qid]     ?? '';
            $gap         = $_POST['gap'][$qid]          ?? '';
            $action      = $_POST['action'][$qid]       ?? '';
            $target      = $_POST['target'][$qid]       ?: null;
            $responsible = $_POST['responsible'][$qid]  ?? '';

            $stmt = $pdo->prepare(
                "DELETE FROM assessment_results
                 WHERE session_id = ? AND question_id = ? AND user_id = ?"
            );
            $stmt->execute([$active_session['id'], $qid, $user['id']]);

            // Konversi skor 0-1 (0, 25, 50, 75, 100%) → 1-5
            $score_int = (int) round(floatval($score) * 5);
            if ($score_int < 1) $score_int = 1;
            if ($score_int > 5) $score_int = 5;

            $stmt = $pdo->prepare(
                "INSERT INTO assessment_results
                 (session_id, element_id, question_id, user_id, score,
                  evidence, gap_analysis, action_plan, target_date, responsible_person,
                  filled_as_role)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)"
            );
            $stmt->execute([
                $active_session['id'], $element['id'], $qid, $user['id'],
                $score_int, $evidence, $gap, $action, $target, $responsible,
                $fill_as_role
            ]);
        }
        $pdo->commit();
        $log_note = $is_admin && $fill_as_role
            ? "Simpan assessment elemen #{$element_number} (atas nama role: {$fill_as_role})"
            : "Simpan assessment elemen #{$element_number}";
        logActivity('ASSESSMENT_SAVE', $log_note);
        $msg = 'Data assessment berhasil disimpan!'
             . ($is_admin && $fill_as_role ? ' (Diisi atas nama role: ' . $fill_as_role . ')' : '');
        $msg_type = 'success';
    } catch (Exception $e) {
        $pdo->rollBack();
        $msg = 'Gagal menyimpan: ' . $e->getMessage();
        $msg_type = 'danger';
    }
    }  // end of if ($can_fill_evidence)
}

// Ambil jawaban sebelumnya
$prev_answers = [];
if ($active_session) {
    $stmt = $pdo->prepare(
        "SELECT * FROM assessment_results
         WHERE session_id = ? AND element_id = ? AND user_id = ?"
    );
    $stmt->execute([$active_session['id'], $element['id'], $user['id']]);
    foreach ($stmt->fetchAll() as $r) {
        $prev_answers[$r['question_id']] = $r;
    }
}

// Helper: pecah criteria
function parseCriteria($text) {
    $ref = ''; $persyaratan = ''; $kriteria = '';
    if (preg_match('/^\[Ref\s+([\d\.a-z]+)\]\s*(.*?)(?:\n\nKriteria Penilaian:\s*(.*))?$/su', $text, $m)) {
        $ref         = trim($m[1]);
        $persyaratan = trim($m[2]);
        $kriteria    = trim($m[3] ?? '');
    } else {
        $kriteria = $text;
    }
    return [$ref, $persyaratan, $kriteria];
}

$filled = count($prev_answers);
$total  = count($questions);
$pct    = $total > 0 ? round(($filled / $total) * 100) : 0;

$page_title = 'Assessment - ' . $element['element_name'];
require_once __DIR__ . '/../includes/header.php';
require_once __DIR__ . '/../includes/sidebar.php';
?>

<style>
/* ============ TAB HORIZONTAL ============ */
.nav-tabs-horizontal {
    border-bottom: 2px solid #dee2e6;
    padding: 8px 8px 0;
    background: #f4f6f9;
    border-radius: 6px 6px 0 0;
    display: flex;
    flex-wrap: wrap;   /* otomatis wrap ke baris baru kalau banyak */
    gap: 3px;
}

.nav-tabs-horizontal .nav-link {
    border: 1px solid transparent;
    border-bottom: none;
    border-radius: 4px 4px 0 0;
    padding: 6px 14px;
    font-size: 13px;
    font-weight: 500;
    color: #495057;
    background: #fff;
    margin-bottom: -2px;
    display: inline-flex;
    align-items: center;
    gap: 6px;
    transition: all 0.15s;
    cursor: pointer;
    min-width: 55px;
    justify-content: center;
}

.nav-tabs-horizontal .nav-link:hover {
    background: #e9ecef;
    color: #212529;
    border-color: #dee2e6;
}

.nav-tabs-horizontal .nav-link.active {
    color: #fff !important;
    border-bottom: 2px solid transparent;
    box-shadow: 0 -2px 4px rgba(0,0,0,.08);
}

/* Dot status */
.tab-dot {
    width: 8px; height: 8px; border-radius: 50%;
    background: #adb5bd;
    flex-shrink: 0;
}
.tab-dot.filled { background: #28a745; }
.nav-link.active .tab-dot { background: #fff; }

/* Tab level badge (per-question override mode) */
.tab-level-badge {
    display: inline-block;
    font-size: 8px;
    font-weight: 600;
    padding: 1px 4px;
    border-radius: 2px;
    color: #fff;
    line-height: 1.3;
    margin-left: 3px;
    min-width: 14px;
    text-align: center;
}
.nav-link.tab-readonly {
    background: #f8f9fa !important;
}
.nav-link.tab-readonly .tab-dot { background: #d1d3d4; }

/* Content area */
.tab-content-horizontal {
    background: #fff;
    border: 1px solid #dee2e6;
    border-top: none;
    border-radius: 0 0 6px 6px;
    padding: 20px;
}

/* Progress summary */
.progress-summary {
    background: #fff; border: 1px solid #dee2e6;
    border-radius: 6px; padding: 12px 16px; margin-bottom: 14px;
}

/* Warna aktif per elemen - set via inline style */

/* ============ EVIDENCE FILE UPLOAD ============ */
.evidence-upload-section {
    padding: 10px 12px;
    background: #f8fafc;
    border: 1px dashed #cbd5e1;
    border-radius: 6px;
    margin-top: 8px;
}
.evidence-files-list {
    margin-top: 4px;
    margin-bottom: 6px;
    min-height: 4px;
}
.evidence-file-item {
    display: inline-flex;
    align-items: center;
    gap: 8px;
    padding: 5px 10px;
    background: #fff;
    border: 1px solid #dee2e6;
    border-radius: 4px;
    font-size: 12px;
    margin: 2px 4px 2px 0;
    transition: all 0.15s;
}
.evidence-file-item:hover {
    background: #f1f5f9;
    border-color: #94a3b8;
    box-shadow: 0 1px 3px rgba(0,0,0,0.08);
}
.evidence-file-item i.fas {
    font-size: 14px;
}
.evidence-file-link {
    color: #334155;
    text-decoration: none;
    font-weight: 500;
    max-width: 280px;
    overflow: hidden;
    text-overflow: ellipsis;
    white-space: nowrap;
}
.evidence-file-link:hover {
    color: #2563eb;
    text-decoration: underline;
}
.evidence-file-size {
    color: #94a3b8;
    font-size: 11px;
    font-weight: normal;
}
.btn-remove-evidence {
    background: none;
    border: none;
    color: #dc2626;
    cursor: pointer;
    padding: 0 4px;
    font-size: 13px;
    line-height: 1;
    border-radius: 3px;
    transition: all 0.15s;
}
.btn-remove-evidence:hover {
    background: #fee2e2;
    color: #991b1b;
}
.evidence-upload-wrap {
    display: flex;
    align-items: center;
    flex-wrap: wrap;
    gap: 6px;
}
.evidence-upload-wrap .btn {
    font-size: 12px;
    padding: 4px 12px;
}
.evidence-progress {
    max-width: 500px;
}
.evidence-progress .progress {
    border-radius: 3px;
}
.evidence-progress .progress-bar {
    transition: width 0.2s ease;
    font-weight: 500;
    padding-left: 8px;
    text-align: left;
    color: #fff;
}
.evidence-progress .progress-text {
    font-size: 10px;
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
}
</style>

<div class="content-wrapper">

    <section class="content-header">
        <div class="container-fluid">
            <div class="row mb-2">
                <div class="col-sm-8">
                    <h1 style="font-size: 1.6rem;">
                        <i class="<?= e($element['icon']) ?> text-<?= e($element['color']) ?>"></i>
                        Elemen <?= $element['element_number'] ?>: <?= e($element['element_name']) ?>
                    </h1>
                    <small class="text-muted">
                        Kode: <?= e($element['element_code']) ?>
                        <?php
                        $rasci_config = [
                            'A' => ['danger',    'Accountable',    'gavel'],
                            'R' => ['success',   'Responsible',    'edit'],
                            'S' => ['info',      'Support',        'hands-helping'],
                            'C' => ['warning',   'Consulted',      'comments'],
                            'I' => ['secondary', 'Informed',       'eye'],
                        ];
                        if (!empty($my_levels)):
                            // Tampilkan semua level user
                            foreach ($my_levels as $lvl):
                                if (isset($rasci_config[$lvl])):
                                    [$color, $label, $icon] = $rasci_config[$lvl];
                        ?>
                            <span class="badge badge-<?= $color ?> ml-1" title="<?= e($label) ?>">
                                <i class="fas fa-<?= $icon ?>"></i>
                                <?= $lvl ?> — <?= $label ?>
                            </span>
                        <?php
                                endif;
                            endforeach;
                        elseif ($is_admin): ?>
                            <span class="badge badge-danger ml-1">
                                <i class="fas fa-user-shield"></i> Administrator
                            </span>
                        <?php endif; ?>
                    </small>
                </div>
                <div class="col-sm-4">
                    <ol class="breadcrumb float-sm-right">
                        <li class="breadcrumb-item"><a href="<?= BASE_URL ?>index.php">Home</a></li>
                        <li class="breadcrumb-item">Assessment</li>
                        <li class="breadcrumb-item active">E<?= $element['element_number'] ?></li>
                    </ol>
                </div>
            </div>
        </div>
    </section>

    <section class="content">
        <div class="container-fluid">

            <?php if ($msg): ?>
                <div class="alert alert-<?= $msg_type ?> alert-dismissible">
                    <button type="button" class="close" data-dismiss="alert">&times;</button>
                    <i class="icon fas fa-<?= $msg_type == 'success' ? 'check' : 'ban' ?>"></i>
                    <?= e($msg) ?>
                </div>
            <?php endif; ?>

            <?php if (!$active_session): ?>
                <div class="alert alert-warning shadow-sm" style="border-left: 4px solid #ffc107;">
                    <h5 class="mb-1">
                        <i class="fas fa-exclamation-triangle"></i> Tidak Ada Sesi Assessment Aktif
                    </h5>
                    <p class="mb-2">
                        Saat ini tidak ada periode assessment dengan status <strong>ongoing</strong> di sistem.
                        Anda tidak bisa menyimpan jawaban maupun upload file evidence sampai sesi diaktifkan.
                    </p>
                    <hr class="my-2">
                    <p class="mb-1"><strong><i class="fas fa-wrench"></i> Cara aktifkan sesi:</strong></p>
                    <ol class="mb-0" style="font-size:13px;">
                        <?php if (canAdminister()): ?>
                            <li>
                                Buka menu <a href="<?= BASE_URL ?>pages/sessions.php" class="alert-link">
                                <i class="fas fa-calendar-alt"></i> Periode Assessment</a>
                            </li>
                            <li>Buat sesi baru atau pilih sesi yang ada, lalu set status menjadi <strong>ongoing</strong></li>
                            <li>Kembali ke halaman ini dan refresh</li>
                        <?php else: ?>
                            <li>Hubungi <strong>administrator</strong> untuk mengaktifkan sesi assessment</li>
                            <li>Admin perlu membuka menu <em>Periode Assessment</em> dan mengatur status sesi</li>
                        <?php endif; ?>
                    </ol>
                </div>
            <?php endif; ?>

            <!-- Progress summary -->
            <div class="progress-summary">
                <div class="row align-items-center">
                    <div class="col-md-6">
                        <strong><i class="fas fa-clipboard-check"></i> Progress Assessment:</strong>
                        <span class="badge badge-<?= e($element['color']) ?>">
                            <?= $filled ?> / <?= $total ?> pertanyaan diisi
                        </span>
                        <?php if ($active_session): ?>
                            <span class="ml-2 text-muted">
                                <small><i class="fas fa-calendar-alt"></i> <?= e($active_session['session_name']) ?></small>
                            </span>
                        <?php endif; ?>
                    </div>
                    <div class="col-md-6">
                        <div class="progress" style="height: 22px;">
                            <div class="progress-bar bg-<?= e($element['color']) ?> progress-bar-striped"
                                 role="progressbar" style="width: <?= $pct ?>%;">
                                <?= $pct ?>%
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Banner khusus sesuai level RASCI user -->
            <?php if (!$is_admin && !empty($my_levels)):
                $has_multi = count($my_levels) > 1;
                if ($has_A && $has_R): ?>
                    <div class="alert alert-danger shadow-sm" style="border-left: 4px solid #dc3545;">
                        <h6 class="mb-1"><i class="fas fa-gavel"></i> Mode Accountable + Responsible (A/R)</h6>
                        <small>
                            Anda memegang <strong>kedua peran sekaligus</strong>: mengeksekusi (Responsible) sekaligus
                            menjadi <em>approver</em> akhir (Accountable). Anda bisa mengisi semua field dan memberi persetujuan.
                        </small>
                    </div>
                <?php elseif ($has_A): ?>
                    <div class="alert alert-danger shadow-sm" style="border-left: 4px solid #dc3545;">
                        <h6 class="mb-1"><i class="fas fa-gavel"></i> Mode Accountable (Approver)</h6>
                        <small>
                            Anda <strong>pemegang akuntabilitas akhir</strong> untuk elemen ini.
                            Selain bisa mengisi form, Anda memberi persetujuan atas hasil assessment.
                        </small>
                    </div>
                <?php elseif ($has_R): ?>
                    <div class="alert alert-success shadow-sm" style="border-left: 4px solid #28a745;">
                        <h6 class="mb-1"><i class="fas fa-edit"></i> Mode Responsible (Pengisi Utama)</h6>
                        <small>
                            Anda adalah <strong>pengisi utama</strong> assessment elemen ini.
                            Isi skor, evidence, gap, dan action plan; approval akan diberikan oleh role Accountable.
                        </small>
                    </div>
                <?php elseif ($has_S): ?>
                    <div class="alert alert-info shadow-sm" style="border-left: 4px solid #17a2b8;">
                        <h6 class="mb-1"><i class="fas fa-hands-helping"></i> Mode Support</h6>
                        <small>
                            Anda berperan <strong>memberi dukungan data &amp; evidence</strong> kepada role Responsible.
                            Anda bisa mengisi Evidence, Gap, dan Action Plan, namun <strong>tidak dapat mengubah skor</strong>.
                        </small>
                    </div>
                <?php elseif (in_array('C', $my_levels)): ?>
                    <div class="alert alert-warning shadow-sm" style="border-left: 4px solid #ffc107;">
                        <h6 class="mb-1"><i class="fas fa-comments"></i> Mode Consulted (Review)</h6>
                        <small>
                            Anda <strong>dikonsultasi untuk review</strong> sebelum Responsible menyimpan final.
                            Halaman ini read-only — komentar disampaikan langsung kepada R/A.
                        </small>
                    </div>
                <?php elseif (in_array('I', $my_levels)): ?>
                    <div class="alert alert-secondary shadow-sm" style="border-left: 4px solid #6c757d;">
                        <h6 class="mb-1"><i class="fas fa-eye"></i> Mode Informed</h6>
                        <small>
                            Anda sekadar <strong>diinformasi</strong> — halaman ini read-only untuk Anda.
                        </small>
                    </div>
                <?php endif;
            endif; ?>

            <?php if ($has_question_override): ?>
                <div class="alert alert-primary shadow-sm" style="border-left: 4px solid #007bff;">
                    <h6 class="mb-1"><i class="fas fa-layer-group"></i> Mode RASCI Per-Pertanyaan</h6>
                    <small>
                        Elemen ini menggunakan <strong>RASCI per-pertanyaan</strong> — tiap soal bisa punya
                        role penanggung jawab yang berbeda. Cek badge di setiap tab untuk melihat level Anda
                        di pertanyaan tersebut. Tab yang berwarna pucat menunjukkan pertanyaan read-only untuk Anda.
                    </small>
                </div>
            <?php endif; ?>

            <?php if (!$active_session): ?>
                <div class="alert alert-warning">
                    <h5><i class="icon fas fa-exclamation-triangle"></i> Tidak ada periode assessment aktif!</h5>
                </div>
            <?php endif; ?>

            <?php if ($is_admin && !empty($element_roles)): ?>
                <!-- Banner khusus Administrator -->
                <div class="alert alert-admin shadow-sm" style="background: linear-gradient(135deg, #fff5e6 0%, #ffe4b5 100%); border-left: 4px solid #e67e22;">
                    <div class="row align-items-center">
                        <div class="col-md-8">
                            <h6 class="mb-1">
                                <i class="fas fa-user-shield text-danger"></i>
                                <strong>Mode Administrator</strong>
                                <span class="badge badge-danger ml-2">FULL ACCESS</span>
                            </h6>
                            <small class="text-muted">
                                Anda login sebagai admin — dapat mengisi assessment untuk semua 18 elemen.
                                Role yang seharusnya bertanggung jawab untuk elemen ini:
                            </small>
                            <div class="mt-1">
                                <?php foreach ($element_roles as $er):
                                    $badge_class = $er['responsibility'] === 'R' ? 'badge-success' : 'badge-info';
                                ?>
                                    <span class="badge <?= $badge_class ?>"
                                          title="<?= e($er['role_name']) ?>"
                                          style="font-size: 11px; padding: 4px 8px; margin-right: 3px;">
                                        <?= e($er['role_code']) ?>
                                        (<?= e($er['responsibility']) ?>)
                                    </span>
                                <?php endforeach; ?>
                            </div>
                        </div>
                        <div class="col-md-4 text-md-right mt-2 mt-md-0">
                            <small class="text-muted d-block mb-1">Isi atas nama role:</small>
                            <select class="form-control form-control-sm"
                                    id="fill_as_role"
                                    name="fill_as_role"
                                    style="max-width: 220px; margin-left: auto;"
                                    form="formAssessment">
                                <option value="">— Sebagai Admin —</option>
                                <?php foreach ($element_roles as $er): ?>
                                    <option value="<?= e($er['role_code']) ?>"
                                            <?= ($_POST['fill_as_role'] ?? '') === $er['role_code'] ? 'selected' : '' ?>>
                                        <?= e($er['role_code']) ?> — <?= e($er['role_name']) ?> (<?= $er['responsibility'] ?>)
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>
                </div>
            <?php endif; ?>

            <?php if (empty($questions)): ?>
                <div class="card">
                    <div class="card-body text-center py-5 text-muted">
                        <i class="fas fa-inbox fa-3x mb-3"></i>
                        <p>Belum ada pertanyaan untuk elemen ini.</p>
                    </div>
                </div>
            <?php else: ?>

                <form method="POST" action="" id="formAssessment">

                    <!-- ============ TAB HORIZONTAL ============ -->
                    <ul class="nav nav-tabs-horizontal" id="questionTab" role="tablist">
                        <?php foreach ($questions as $idx => $q):
                            [$ref, ,] = parseCriteria($q['criteria']);
                            $is_filled = isset($prev_answers[$q['id']]);
                            $active_class = ($idx === 0) ? 'active' : '';

                            // Level user di pertanyaan ini
                            $q_levels = getQuestionLevels($q['id'], $question_levels, $my_levels, $has_question_override);
                            $q_has_A = in_array('A', $q_levels);
                            $q_has_R = in_array('R', $q_levels);
                            $q_has_S = in_array('S', $q_levels);
                            $q_readonly = !$is_admin && !$q_has_A && !$q_has_R && !$q_has_S;
                            $q_primary_level = $q_levels[0] ?? 'I';

                            // Warna dot sesuai level
                            $dot_color_map = [
                                'A' => '#dc3545', 'R' => '#28a745',
                                'S' => '#17a2b8', 'C' => '#ffc107', 'I' => '#adb5bd'
                            ];
                            $level_dot_color = $dot_color_map[$q_primary_level] ?? '#adb5bd';
                        ?>
                        <li class="nav-item" role="presentation">
                            <a class="nav-link <?= $active_class ?> <?= $q_readonly ? 'tab-readonly' : '' ?>"
                               id="tab-<?= $q['id'] ?>"
                               data-toggle="tab"
                               href="#panel-<?= $q['id'] ?>"
                               role="tab"
                               data-color="<?= e($element['color']) ?>"
                               data-level="<?= e($q_primary_level) ?>"
                               data-readonly="<?= $q_readonly ? '1' : '0' ?>"
                               title="Pertanyaan <?= $q['question_number'] ?> — Level Anda: <?= implode('/', $q_levels) ?>">
                                <span class="tab-dot <?= $is_filled ? 'filled' : '' ?>"
                                      id="dot-<?= $q['id'] ?>"></span>
                                <span><?= $ref ?: ('Q' . $q['question_number']) ?></span>
                                <?php if ($has_question_override && !$is_admin): ?>
                                    <span class="tab-level-badge"
                                          style="background:<?= $level_dot_color ?>;">
                                        <?= implode('/', $q_levels) ?>
                                    </span>
                                <?php endif; ?>
                            </a>
                        </li>
                        <?php endforeach; ?>
                    </ul>

                    <!-- ============ ISI FORM ============ -->
                    <div class="tab-content-horizontal">
                        <div class="tab-content" id="questionTabContent">
                            <?php foreach ($questions as $idx => $q):
                                $prev = $prev_answers[$q['id']] ?? null;
                                [$ref, $persyaratan, $kriteria] = parseCriteria($q['criteria']);
                                $prev_score_frac = $prev ? ($prev['score'] / 5) : null;
                                $active_class = ($idx === 0) ? 'active show' : '';

                                // Level user di pertanyaan ini
                                $q_levels = getQuestionLevels($q['id'], $question_levels, $my_levels, $has_question_override);
                                $q_has_A = in_array('A', $q_levels);
                                $q_has_R = in_array('R', $q_levels);
                                $q_has_S = in_array('S', $q_levels);
                                $q_can_fill_score    = $is_admin || $q_has_A || $q_has_R;
                                $q_can_fill_evidence = $is_admin || $q_has_A || $q_has_R || $q_has_S;
                            ?>
                            <div class="tab-pane fade <?= $active_class ?>"
                                 id="panel-<?= $q['id'] ?>" role="tabpanel"
                                 data-q-level="<?= implode(',', $q_levels) ?>"
                                 data-q-can-score="<?= $q_can_fill_score ? '1' : '0' ?>"
                                 data-q-can-evidence="<?= $q_can_fill_evidence ? '1' : '0' ?>">

                                <?php if ($has_question_override && !$is_admin): ?>
                                    <?php
                                    $q_badge_config = [
                                        'A' => ['danger',    'Accountable — Approver',       'gavel'],
                                        'R' => ['success',   'Responsible — Pengisi utama',  'edit'],
                                        'S' => ['info',      'Support — Isi evidence/gap',   'hands-helping'],
                                        'C' => ['warning',   'Consulted — Review',           'comments'],
                                        'I' => ['secondary', 'Informed — Read-only',         'eye'],
                                    ];
                                    $q_primary = $q_levels[0] ?? 'I';
                                    [$q_color, $q_label, $q_icon] = $q_badge_config[$q_primary];
                                    ?>
                                    <div class="alert alert-<?= $q_color ?> py-2 mb-2" style="font-size:12px; border-left:3px solid;">
                                        <i class="fas fa-<?= $q_icon ?>"></i>
                                        <strong>Pertanyaan ini:</strong> Anda level
                                        <strong><?= implode('/', $q_levels) ?></strong> — <?= $q_label ?>.
                                        <?php if (!$q_can_fill_evidence): ?>
                                            <small class="text-muted ml-2">(Form tidak bisa diisi untuk pertanyaan ini.)</small>
                                        <?php elseif (!$q_can_fill_score): ?>
                                            <small class="text-muted ml-2">(Anda hanya bisa mengisi evidence/gap/action plan, tidak skor.)</small>
                                        <?php endif; ?>
                                    </div>
                                <?php endif; ?>

                                <!-- Header panel -->
                                <div class="d-flex justify-content-between align-items-center mb-3 pb-2"
                                     style="border-bottom:1px solid #e9ecef;">
                                    <h5 class="mb-0">
                                        <span class="badge badge-<?= e($element['color']) ?> mr-2"
                                              style="font-size:14px; padding:5px 10px;">
                                            <?= $ref ?: ('Q' . $q['question_number']) ?>
                                        </span>
                                        <small class="text-muted">Pertanyaan <?= $q['question_number'] ?> dari <?= $total ?></small>
                                    </h5>
                                    <div>
                                        <button type="button" class="btn btn-sm btn-outline-secondary btn-prev-q" title="Sebelumnya (Ctrl+←)">
                                            <i class="fas fa-chevron-left"></i> Prev
                                        </button>
                                        <button type="button" class="btn btn-sm btn-outline-secondary btn-next-q" title="Berikutnya (Ctrl+→)">
                                            Next <i class="fas fa-chevron-right"></i>
                                        </button>
                                    </div>
                                </div>

                                <!-- Persyaratan -->
                                <?php if ($persyaratan): ?>
                                <div class="mb-3">
                                    <label class="text-primary font-weight-bold mb-1">
                                        <i class="fas fa-bookmark"></i> Persyaratan:
                                    </label>
                                    <div class="p-2 bg-light rounded" style="white-space: pre-line;">
                                        <?= e($persyaratan) ?>
                                    </div>
                                </div>
                                <?php endif; ?>

                                <!-- Audit Protocol -->
                                <div class="mb-3">
                                    <label class="text-info font-weight-bold mb-1">
                                        <i class="fas fa-question-circle"></i> Audit Protocol:
                                    </label>
                                    <div class="p-2 border-left border-info pl-3" style="white-space: pre-line;">
                                        <strong><?= e($q['question_text']) ?></strong>
                                    </div>
                                </div>

                                <!-- Kriteria -->
                                <?php if ($kriteria): ?>
                                <div class="mb-3">
                                    <label class="text-success font-weight-bold mb-1">
                                        <i class="fas fa-check-circle"></i> Kriteria Penilaian:
                                    </label>
                                    <div class="p-2 bg-light rounded border-left border-success"
                                         style="white-space: pre-line;">
                                        <?= e($kriteria) ?>
                                    </div>
                                </div>
                                <?php endif; ?>

                                <!-- Nilai Pemenuhan -->
                                <div class="form-group">
                                    <label class="font-weight-bold">
                                        <i class="fas fa-star text-warning"></i> Nilai Pemenuhan:
                                    </label>
                                    <div class="btn-group btn-group-toggle d-flex flex-wrap score-group"
                                         data-toggle="buttons" data-qid="<?= $q['id'] ?>">
                                        <?php
                                        $score_options = [
                                            ['val' => '0',    'pct' => '0%',   'label' => 'Tidak Ada'],
                                            ['val' => '0.25', 'pct' => '25%',  'label' => 'Kecil'],
                                            ['val' => '0.5',  'pct' => '50%',  'label' => 'Separuh'],
                                            ['val' => '0.75', 'pct' => '75%',  'label' => 'Besar'],
                                            ['val' => '1',    'pct' => '100%', 'label' => 'Penuh'],
                                        ];
                                        foreach ($score_options as $opt):
                                            $checked = ($prev_score_frac !== null &&
                                                       abs($prev_score_frac - floatval($opt['val'])) < 0.05)
                                                       ? 'active' : '';
                                        ?>
                                        <label class="btn btn-outline-<?= e($element['color']) ?> <?= $checked ?> flex-fill">
                                            <input type="radio"
                                                   name="score[<?= $q['id'] ?>]"
                                                   value="<?= $opt['val'] ?>"
                                                   <?= $checked ? 'checked' : '' ?>>
                                            <strong><?= $opt['pct'] ?></strong><br>
                                            <small><?= $opt['label'] ?></small>
                                        </label>
                                        <?php endforeach; ?>
                                    </div>
                                </div>

                                <!-- Kondisi & Gap -->
                                <div class="row">
                                    <div class="col-md-6">
                                        <div class="form-group">
                                            <label><i class="fas fa-camera text-warning"></i>
                                                Kondisi Saat Ini / Evidence:</label>
                                            <textarea name="evidence[<?= $q['id'] ?>]"
                                                      class="form-control" rows="3"
                                                      placeholder="Deskripsikan kondisi saat ini..."><?= e($prev['evidence'] ?? '') ?></textarea>
                                        </div>
                                    </div>
                                    <div class="col-md-6">
                                        <div class="form-group">
                                            <label><i class="fas fa-exclamation-triangle text-danger"></i>
                                                Gap Analysis:</label>
                                            <textarea name="gap[<?= $q['id'] ?>]"
                                                      class="form-control" rows="3"
                                                      placeholder="Gap yang teridentifikasi..."><?= e($prev['gap_analysis'] ?? '') ?></textarea>
                                        </div>
                                    </div>
                                </div>

                                <!-- ============ EVIDENCE FILE UPLOAD ============ -->
                                <div class="form-group mt-2 evidence-upload-section"
                                     data-question-id="<?= $q['id'] ?>"
                                     data-element-id="<?= $element['id'] ?>"
                                     data-element-number="<?= $element['element_number'] ?>"
                                     data-element-name="<?= e($element['element_name']) ?>"
                                     data-session-id="<?= $active_session['id'] ?? 0 ?>">
                                    <label style="font-size:12px;">
                                        <i class="fas fa-paperclip text-secondary"></i>
                                        Lampiran File Evidence
                                        <small class="text-muted ml-1">(optional, multi-file OK)</small>
                                    </label>

                                    <!-- File list existing -->
                                    <div class="evidence-files-list">
                                        <?php
                                        $evidence_error = null;
                                        $existing_files = [];
                                        if ($active_session && !empty($q['id'])) {
                                            try {
                                                $existing_files = getEvidenceFiles($active_session['id'], $q['id'], $user['id']);
                                            } catch (Exception $ex) {
                                                $evidence_error = $ex->getMessage();
                                            }
                                        }

                                        foreach ($existing_files as $ef):
                                            [$ef_icon, $ef_color] = getFileIcon($ef['file_extension']);
                                        ?>
                                            <div class="evidence-file-item" data-file-id="<?= $ef['id'] ?>">
                                                <i class="fas <?= $ef_icon ?> text-<?= $ef_color ?>"></i>
                                                <a href="<?= BASE_URL ?>pages/download_evidence.php?id=<?= $ef['id'] ?>"
                                                   target="_blank" class="evidence-file-link"
                                                   title="<?= e($ef['original_name']) ?>">
                                                    <?= e(mb_strimwidth($ef['original_name'], 0, 40, '…')) ?>
                                                </a>
                                                <span class="evidence-file-size"><?= formatFileSize($ef['file_size']) ?></span>
                                                <?php if ($q_can_fill_evidence): ?>
                                                    <button type="button" class="btn-remove-evidence" title="Hapus">
                                                        <i class="fas fa-times"></i>
                                                    </button>
                                                <?php endif; ?>
                                            </div>
                                        <?php endforeach; ?>
                                    </div>

                                    <?php if ($evidence_error): ?>
                                        <div class="alert alert-warning py-2 px-3 mb-0 mt-1" style="font-size:12px;">
                                            <i class="fas fa-exclamation-triangle"></i>
                                            <strong>Evidence system error:</strong> <?= e($evidence_error) ?>
                                            <?php if (canAdminister()): ?>
                                                <br><small>Admin: pastikan <code>evidence_migration.sql</code> sudah dijalankan dan file <code>includes/evidence.php</code> sudah ada.</small>
                                            <?php endif; ?>
                                        </div>
                                    <?php elseif (!$active_session): ?>
                                        <div class="alert alert-warning py-2 px-3 mb-0 mt-1" style="font-size:12px;">
                                            <i class="fas fa-exclamation-triangle"></i>
                                            <strong>Upload dinonaktifkan</strong> — Tidak ada periode assessment yang berstatus <code>ongoing</code>.
                                            <?php if (canAdminister()): ?>
                                                <a href="<?= BASE_URL ?>pages/sessions.php" class="ml-2">
                                                    <i class="fas fa-arrow-right"></i> Buat/aktifkan periode
                                                </a>
                                            <?php else: ?>
                                                Hubungi admin untuk membuka periode assessment.
                                            <?php endif; ?>
                                        </div>
                                    <?php elseif (!$q_can_fill_evidence): ?>
                                        <div class="alert alert-info py-2 px-3 mb-0 mt-1" style="font-size:12px;">
                                            <i class="fas fa-eye"></i>
                                            <strong>Mode lihat saja</strong> — Role Anda
                                            <?php if (!empty($q_levels)): ?>
                                                (<?= e($role_code ?? '?') ?>/<?= implode(',', $q_levels) ?>)
                                            <?php endif; ?>
                                            tidak punya hak upload evidence untuk pertanyaan ini.
                                            File yang sudah di-upload user lain tetap bisa dilihat & di-download.
                                        </div>
                                    <?php else: ?>
                                        <!-- Upload trigger -->
                                        <div class="evidence-upload-wrap mt-2">
                                            <label class="btn btn-primary mb-0" style="cursor:pointer;">
                                                <i class="fas fa-cloud-upload-alt"></i> Pilih File untuk Upload
                                                <input type="file" class="evidence-file-input" multiple
                                                       style="display:none;"
                                                       accept=".pdf,.doc,.docx,.xls,.xlsx,.ppt,.pptx,.jpg,.jpeg,.png,.gif,.zip,.txt,.csv,.mp4,.mov">
                                            </label>
                                            <small class="text-muted ml-2">
                                                <i class="fas fa-info-circle"></i>
                                                Max 10MB/file · Multi-file OK · PDF/Word/Excel/Image/Video
                                            </small>
                                        </div>

                                        <!-- Progress bar -->
                                        <div class="evidence-progress mt-2" style="display:none;">
                                            <div class="progress" style="height:20px;">
                                                <div class="progress-bar progress-bar-striped progress-bar-animated bg-info"
                                                     style="width:0%; font-size:11px;">
                                                    <span class="progress-text">0%</span>
                                                </div>
                                            </div>
                                        </div>
                                    <?php endif; ?>
                                </div>

                                <!-- Rekomendasi -->
                                <div class="form-group">
                                    <label><i class="fas fa-lightbulb text-info"></i>
                                        Rekomendasi / Action Plan:</label>
                                    <textarea name="action[<?= $q['id'] ?>]"
                                              class="form-control" rows="2"
                                              placeholder="Rencana perbaikan..."><?= e($prev['action_plan'] ?? '') ?></textarea>
                                </div>

                                <div class="row">
                                    <div class="col-md-6">
                                        <div class="form-group mb-0">
                                            <label><i class="fas fa-calendar text-primary"></i>
                                                Target Penyelesaian:</label>
                                            <input type="date" name="target[<?= $q['id'] ?>]"
                                                   class="form-control"
                                                   value="<?= e($prev['target_date'] ?? '') ?>">
                                        </div>
                                    </div>
                                    <div class="col-md-6">
                                        <div class="form-group mb-0">
                                            <label><i class="fas fa-user-tag text-success"></i>
                                                Penanggung Jawab:</label>
                                            <input type="text" name="responsible[<?= $q['id'] ?>]"
                                                   class="form-control" placeholder="Nama PIC..."
                                                   value="<?= e($prev['responsible_person'] ?? '') ?>">
                                        </div>
                                    </div>
                                </div>

                            </div>
                            <?php endforeach; ?>
                        </div>
                    </div>

                    <!-- Footer aksi -->
                    <div class="card mt-3" style="position: sticky; bottom: 0; z-index: 10;">
                        <div class="card-body py-2 text-right">
                            <a href="<?= BASE_URL ?>index.php" class="btn btn-secondary">
                                <i class="fas fa-arrow-left"></i> Kembali
                            </a>
                            <?php if ($active_session): ?>
                                <button type="submit" class="btn btn-<?= e($element['color']) ?> btn-lg">
                                    <i class="fas fa-save"></i> Simpan Semua
                                </button>
                            <?php endif; ?>
                        </div>
                    </div>
                </form>
            <?php endif; ?>

        </div>
    </section>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>

<script>
$(function() {
    // Warna background tab aktif sesuai warna elemen
    const colorMap = {
        primary: '#007bff', info: '#17a2b8', warning: '#ffc107',
        success: '#28a745', danger: '#dc3545'
    };

    function applyActiveTabColor() {
        $('.nav-tabs-horizontal .nav-link').each(function() {
            const color = colorMap[$(this).data('color')] || '#007bff';
            if ($(this).hasClass('active')) {
                $(this).css({
                    'background-color': color,
                    'border-color': color,
                    'color': '#fff'
                });
            } else {
                $(this).css({
                    'background-color': '#fff',
                    'border-color': 'transparent',
                    'color': '#495057'
                });
            }
        });
    }
    applyActiveTabColor();

    // Tombol Prev / Next
    $('.btn-next-q').on('click', function() {
        const current = $('.nav-tabs-horizontal .nav-link.active').parent();
        const next = current.next('.nav-item').find('.nav-link');
        if (next.length) next.tab('show');
    });
    $('.btn-prev-q').on('click', function() {
        const current = $('.nav-tabs-horizontal .nav-link.active').parent();
        const prev = current.prev('.nav-item').find('.nav-link');
        if (prev.length) prev.tab('show');
    });

    // Update warna tab aktif + scroll kalau perlu setelah pindah tab
    $('a[data-toggle="tab"]').on('shown.bs.tab', function (e) {
        applyActiveTabColor();
        // Pastikan tab aktif terlihat
        const activeTab = $(e.target);
        const container = $('.nav-tabs-horizontal');
        container.animate({
            scrollLeft: activeTab.position().left + container.scrollLeft() - container.width()/2 + activeTab.width()/2
        }, 150);
    });

    // Saat user pilih skor, ubah dot jadi hijau (tanda sudah diisi)
    $('.score-group input[type=radio]').on('change', function() {
        const qid = $(this).closest('.score-group').data('qid');
        $('#dot-' + qid).addClass('filled');
    });

    // ============ RASCI: Kontrol hak akses form ============
    const canFillScore    = <?= $can_fill_score    ? 'true' : 'false' ?>;
    const canFillEvidence = <?= $can_fill_evidence ? 'true' : 'false' ?>;
    const hasQuestionOverride = <?= $has_question_override ? 'true' : 'false' ?>;
    const isAdmin         = <?= $is_admin ? 'true' : 'false' ?>;

    if (hasQuestionOverride && !isAdmin) {
        // Per-question mode: cek data-attribute tiap panel
        $('.tab-pane').each(function() {
            const $panel = $(this);
            const canScore    = $panel.data('q-can-score') === 1 || $panel.data('q-can-score') === '1';
            const canEvidence = $panel.data('q-can-evidence') === 1 || $panel.data('q-can-evidence') === '1';

            if (!canEvidence) {
                $panel.find(':input').prop('disabled', true);
                $panel.find('textarea, input[type=text], input[type=date]')
                    .css('background-color', '#f1f3f5');
                $panel.find('.score-group label.btn').css({
                    'opacity': '0.4', 'cursor': 'not-allowed'
                });
            } else if (!canScore) {
                $panel.find('.score-group input[type=radio]').prop('disabled', true);
                $panel.find('.score-group label.btn').css({
                    'opacity': '0.5', 'cursor': 'not-allowed'
                });
            }
        });

        // Hide main submit button kalau tidak ada satupun panel yang bisa di-edit
        const anyEditable = $('.tab-pane').filter(function() {
            return $(this).data('q-can-evidence') === 1 || $(this).data('q-can-evidence') === '1';
        }).length > 0;
        if (!anyEditable) {
            $('#formAssessment button[type=submit]').hide();
        }

        // Style tab read-only
        $('.tab-readonly').css('opacity', '0.6');
    }
    // Mode lama (per-elemen): apply sekali ke seluruh form
    else {
        if (!canFillEvidence) {
            $('#formAssessment :input').prop('disabled', true);
            $('#formAssessment button[type=submit]').hide();
            $('#formAssessment textarea, #formAssessment input[type=text], #formAssessment input[type=date]')
                .css('background-color', '#f1f3f5');
        }
        else if (!canFillScore) {
            $('.score-group input[type=radio]').prop('disabled', true);
            $('.score-group label.btn').css({
                'opacity': '0.5', 'cursor': 'not-allowed'
            });
            $('.score-group').after(
                '<small class="text-warning d-block mt-1">' +
                '<i class="fas fa-info-circle"></i> ' +
                'Skor hanya bisa diubah oleh Responsible/Accountable. ' +
                'Anda dapat mengisi Evidence, Gap, dan Action Plan.' +
                '</small>'
            );
        }
    }

    // ============ EVIDENCE FILE UPLOAD HANDLERS ============
    const BASE = '<?= BASE_URL ?>';

    // File icon mapping (harus sinkron dengan PHP getFileIcon)
    const iconMap = {
        pdf:  ['fa-file-pdf',        'danger'],
        doc:  ['fa-file-word',       'primary'],
        docx: ['fa-file-word',       'primary'],
        xls:  ['fa-file-excel',      'success'],
        xlsx: ['fa-file-excel',      'success'],
        ppt:  ['fa-file-powerpoint', 'warning'],
        pptx: ['fa-file-powerpoint', 'warning'],
        jpg:  ['fa-file-image',      'info'],
        jpeg: ['fa-file-image',      'info'],
        png:  ['fa-file-image',      'info'],
        gif:  ['fa-file-image',      'info'],
        zip:  ['fa-file-archive',    'secondary'],
        rar:  ['fa-file-archive',    'secondary'],
        txt:  ['fa-file-alt',        'secondary'],
        csv:  ['fa-file-csv',        'success'],
        mp4:  ['fa-file-video',      'danger'],
        mov:  ['fa-file-video',      'danger'],
    };

    function escapeHtml(text) {
        const map = {'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'};
        return String(text).replace(/[&<>"']/g, m => map[m]);
    }

    function truncate(text, len) {
        return text.length > len ? text.substring(0, len) + '…' : text;
    }

    function appendFileItem($listWrap, file) {
        const [icon, color] = iconMap[file.file_extension] || ['fa-file','secondary'];
        const shortName = truncate(file.original_name, 40);

        const html = `
            <div class="evidence-file-item" data-file-id="${file.id}">
                <i class="fas ${icon} text-${color}"></i>
                <a href="${BASE}pages/download_evidence.php?id=${file.id}"
                   target="_blank" class="evidence-file-link"
                   title="${escapeHtml(file.original_name)}">
                    ${escapeHtml(shortName)}
                </a>
                <span class="evidence-file-size">${file.size_formatted}</span>
                <button type="button" class="btn-remove-evidence" title="Hapus">
                    <i class="fas fa-times"></i>
                </button>
            </div>`;
        $listWrap.append(html);
    }

    function uploadFilesSequential(files, index, ctx, $progress, $progressBar, $progressText, $listWrap) {
        if (index >= files.length) {
            setTimeout(() => $progress.fadeOut(), 500);
            return;
        }

        const file = files[index];
        const total = files.length;

        $progress.show();
        $progressBar.css('width', '0%').removeClass('bg-danger bg-success').addClass('bg-info');
        $progressText.text(`Upload ${index + 1}/${total}: ${file.name}`);

        const fd = new FormData();
        fd.append('action', 'upload');
        fd.append('file', file);
        $.each(ctx, function(key, val) { fd.append(key, val); });

        $.ajax({
            url: BASE + 'pages/ajax_evidence.php',
            type: 'POST',
            data: fd,
            processData: false,
            contentType: false,
            dataType: 'json',
            xhr: function() {
                const xhr = new window.XMLHttpRequest();
                xhr.upload.addEventListener('progress', function(evt) {
                    if (evt.lengthComputable) {
                        const pct = Math.round((evt.loaded / evt.total) * 100);
                        $progressBar.css('width', pct + '%');
                        $progressText.text(`${index + 1}/${total}: ${pct}%`);
                    }
                }, false);
                return xhr;
            },
            success: function(res) {
                if (res.ok) {
                    appendFileItem($listWrap, res.file);
                    $progressBar.css('width', '100%').removeClass('bg-info').addClass('bg-success');
                    $progressText.text(`✓ ${index + 1}/${total} OK`);
                    setTimeout(() => uploadFilesSequential(files, index + 1, ctx, $progress, $progressBar, $progressText, $listWrap), 400);
                } else {
                    $progressBar.removeClass('bg-info').addClass('bg-danger');
                    $progressText.text('Gagal: ' + res.error);
                    alert('Upload gagal (' + file.name + '):\n\n' + res.error);
                    setTimeout(() => uploadFilesSequential(files, index + 1, ctx, $progress, $progressBar, $progressText, $listWrap), 1500);
                }
            },
            error: function(xhr) {
                $progressBar.removeClass('bg-info').addClass('bg-danger');
                $progressText.text('Error jaringan');
                alert('Error upload: ' + (xhr.responseText || xhr.statusText || 'Unknown'));
                setTimeout(() => uploadFilesSequential(files, index + 1, ctx, $progress, $progressBar, $progressText, $listWrap), 1500);
            }
        });
    }

    // Handle file input change → upload
    $(document).on('change', '.evidence-file-input', function() {
        const files = this.files;
        if (!files.length) return;

        const $wrap    = $(this).closest('.evidence-upload-wrap');
        const $section = $(this).closest('.evidence-upload-section');
        const $listWrap = $section.find('.evidence-files-list');
        const $progress = $section.find('.evidence-progress');
        const $progressBar = $progress.find('.progress-bar');
        const $progressText = $progress.find('.progress-text');

        const ctx = {
            session_id:     $section.data('session-id'),
            question_id:    $section.data('question-id'),
            element_id:     $section.data('element-id'),
            element_number: $section.data('element-number'),
            element_name:   $section.data('element-name'),
        };

        if (!ctx.session_id) {
            alert('Tidak ada sesi aktif. Upload dibatalkan.');
            $(this).val('');
            return;
        }

        uploadFilesSequential(files, 0, ctx, $progress, $progressBar, $progressText, $listWrap);
        $(this).val('');  // Reset supaya bisa upload file sama lagi
    });

    // Handle delete file
    $(document).on('click', '.btn-remove-evidence', function(e) {
        e.preventDefault();
        e.stopPropagation();
        const $item = $(this).closest('.evidence-file-item');
        const fileId = $item.data('file-id');

        if (!confirm('Hapus file ini? Tindakan tidak bisa di-undo.')) return;

        $.post(BASE + 'pages/ajax_evidence.php', {
            action: 'delete', file_id: fileId
        }, null, 'json').done(function(res) {
            if (res.ok) {
                $item.fadeOut(200, function() { $(this).remove(); });
            } else {
                alert('Gagal hapus: ' + (res.error || 'Unknown error'));
            }
        }).fail(function(xhr) {
            alert('Error jaringan: ' + (xhr.responseText || 'gagal hubungi server'));
        });
    });

    // Keyboard shortcut
    $(document).on('keydown', function(e) {
        if (!e.ctrlKey) return;
        if (e.key === 'ArrowRight') { $('.btn-next-q:visible').first().click(); e.preventDefault(); }
        if (e.key === 'ArrowLeft')  { $('.btn-prev-q:visible').first().click(); e.preventDefault(); }
    });
});
</script>