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

// ============ Handle POST Actions ============
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    try {
        // ========== UPDATE MAPPING SATU PERTANYAAN ==========
        if ($action === 'update_single') {
            $qid = (int)$_POST['question_id'];
            $mappings = $_POST['mapping'] ?? [];

            $pdo->beginTransaction();
            $stmt = $pdo->prepare("DELETE FROM question_role_mapping WHERE question_id = ?");
            $stmt->execute([$qid]);

            $stmt_ins = $pdo->prepare(
                "INSERT INTO question_role_mapping (question_id, role_id, responsibility)
                 SELECT ?, id, ? FROM psaims_roles WHERE role_code = ?"
            );
            foreach ($mappings as $role_code => $levels) {
                if (!is_array($levels)) continue;
                foreach ($levels as $level) {
                    if (!in_array($level, ['A','R','S','C','I'])) continue;
                    $stmt_ins->execute([$qid, $level, $role_code]);
                }
            }
            $pdo->commit();
            logActivity('Q_RASCI_UPDATE', "Update mapping Q#{$qid}");
            $msg = 'Mapping pertanyaan berhasil disimpan!';
            $msg_type = 'success';
        }

        // ========== BULK APPLY: Apply mapping X ke semua soal dalam elemen ==========
        elseif ($action === 'bulk_apply_to_element') {
            $qid_source  = (int)$_POST['source_question_id'];
            $element_id  = (int)$_POST['element_id'];

            $pdo->beginTransaction();

            // Backup dulu
            $stmt = $pdo->prepare("SELECT backup_element_question_mapping(?, 'before_bulk_apply', ?)");
            $stmt->execute([$element_id, $user_id]);
            $backup_id = $stmt->fetchColumn();

            // Ambil mapping source
            $stmt = $pdo->prepare(
                "SELECT role_id, responsibility FROM question_role_mapping WHERE question_id = ?"
            );
            $stmt->execute([$qid_source]);
            $source_mappings = $stmt->fetchAll();

            if (empty($source_mappings)) {
                throw new Exception('Pertanyaan source belum punya mapping. Set mapping-nya dulu.');
            }

            // Hapus semua mapping di elemen ini
            $stmt = $pdo->prepare(
                "DELETE FROM question_role_mapping
                 WHERE question_id IN (
                     SELECT id FROM assessment_questions WHERE element_id = ?
                 )"
            );
            $stmt->execute([$element_id]);

            // Apply ke semua pertanyaan
            $stmt_questions = $pdo->prepare(
                "SELECT id FROM assessment_questions WHERE element_id = ? AND is_active = TRUE"
            );
            $stmt_questions->execute([$element_id]);
            $target_qids = $stmt_questions->fetchAll(PDO::FETCH_COLUMN);

            $stmt_ins = $pdo->prepare(
                "INSERT INTO question_role_mapping (question_id, role_id, responsibility) VALUES (?, ?, ?)"
            );

            $total_inserted = 0;
            foreach ($target_qids as $target_qid) {
                foreach ($source_mappings as $m) {
                    $stmt_ins->execute([$target_qid, $m['role_id'], $m['responsibility']]);
                    $total_inserted++;
                }
            }

            // Set toggle TRUE
            $pdo->prepare("UPDATE psaims_elements SET use_question_mode = TRUE WHERE id = ?")
                ->execute([$element_id]);

            $pdo->commit();
            logActivity('Q_RASCI_BULK_APPLY', "Apply Q#{$qid_source} ke elemen #{$element_id} (" . count($target_qids) . " soal)");
            $msg = "Mapping berhasil diterapkan ke <strong>" . count($target_qids) . "</strong> pertanyaan. "
                 . ($backup_id ? "Backup batch: <code>{$backup_id}</code>" : "");
            $msg_type = 'success';
        }

        // ========== INITIALIZE ELEMENT: copy dari element_role_mapping ==========
        elseif ($action === 'init_from_element') {
            $element_id = (int)$_POST['element_id'];
            $overwrite  = !empty($_POST['overwrite']);

            $stmt = $pdo->prepare("SELECT init_questions_from_element_rasci(?, ?, ?)");
            $stmt->execute([$element_id, $user_id, $overwrite]);
            $inserted = $stmt->fetchColumn();

            logActivity('Q_RASCI_INIT', "Init elemen #{$element_id} ({$inserted} rows)");
            $msg = "Berhasil inisialisasi <strong>{$inserted}</strong> mapping dari RASCI elemen.";
            $msg_type = 'success';
        }

        // ========== INITIALIZE ALL: loop 18 elemen ==========
        elseif ($action === 'init_all_elements') {
            $pdo->beginTransaction();

            $stmt_elements = $pdo->query(
                "SELECT id, element_number FROM psaims_elements WHERE is_active = TRUE ORDER BY element_number"
            );
            $total_processed = 0;
            $total_inserted = 0;

            while ($el = $stmt_elements->fetch()) {
                $stmt = $pdo->prepare("SELECT init_questions_from_element_rasci(?, ?, FALSE)");
                $stmt->execute([$el['id'], $user_id]);
                $inserted = (int)$stmt->fetchColumn();
                if ($inserted > 0) {
                    $total_processed++;
                    $total_inserted += $inserted;
                }
            }

            $pdo->commit();
            logActivity('Q_RASCI_INIT_ALL', "Initialize all: {$total_processed} elements, {$total_inserted} rows");
            $msg = "Berhasil inisialisasi <strong>{$total_processed}</strong> elemen dengan total <strong>{$total_inserted}</strong> mapping pertanyaan!";
            $msg_type = 'success';
        }

        // ========== TOGGLE USE_QUESTION_MODE ==========
        elseif ($action === 'toggle_mode') {
            $element_id = (int)$_POST['element_id'];
            $stmt = $pdo->prepare(
                "UPDATE psaims_elements
                 SET use_question_mode = NOT COALESCE(use_question_mode, FALSE)
                 WHERE id = ? RETURNING element_number, use_question_mode"
            );
            $stmt->execute([$element_id]);
            $row = $stmt->fetch();

            $status = $row['use_question_mode'] ? 'mode pertanyaan' : 'mode elemen';
            logActivity('Q_RASCI_TOGGLE', "Elemen #{$row['element_number']} → {$status}");
            $msg = "Elemen E" . str_pad($row['element_number'], 2, '0', STR_PAD_LEFT) .
                   " sekarang pakai <strong>{$status}</strong>.";
            $msg_type = 'success';
        }

        // ========== RESTORE dari backup ==========
        elseif ($action === 'restore_backup') {
            $batch_id = $_POST['batch_id'] ?? '';
            if (!preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $batch_id)) {
                throw new Exception('Batch ID tidak valid.');
            }
            $stmt = $pdo->prepare("SELECT restore_question_mapping_from_backup(?::uuid)");
            $stmt->execute([$batch_id]);
            $restored = $stmt->fetchColumn();

            logActivity('Q_RASCI_RESTORE', "Restore batch {$batch_id} ({$restored} rows)");
            $msg = "Berhasil restore <strong>{$restored}</strong> mapping dari backup.";
            $msg_type = 'success';
        }

        // ========== RESET elemen (hapus semua mapping pertanyaan) ==========
        elseif ($action === 'reset_element') {
            $element_id = (int)$_POST['element_id'];

            // Backup dulu
            $stmt = $pdo->prepare("SELECT backup_element_question_mapping(?, 'manual', ?)");
            $stmt->execute([$element_id, $user_id]);
            $backup_id = $stmt->fetchColumn();

            // Delete
            $stmt = $pdo->prepare(
                "DELETE FROM question_role_mapping
                 WHERE question_id IN (SELECT id FROM assessment_questions WHERE element_id = ?)"
            );
            $stmt->execute([$element_id]);

            // Set toggle FALSE
            $pdo->prepare("UPDATE psaims_elements SET use_question_mode = FALSE WHERE id = ?")
                ->execute([$element_id]);

            logActivity('Q_RASCI_RESET', "Reset elemen #{$element_id}");
            $msg = "Mapping pertanyaan berhasil direset. Backup: <code>" .
                   ($backup_id ?: 'tidak ada data') . "</code>";
            $msg_type = 'success';
        }

    } catch (Exception $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        $msg = 'Gagal: ' . $e->getMessage();
        $msg_type = 'danger';
    }
}

// ============ Ambil data untuk tampilan ============
// Cek dulu apakah kolom use_question_mode sudah ada (buat defensif)
$has_toggle_column = false;
try {
    $stmt_check = $pdo->query(
        "SELECT 1 FROM information_schema.columns
         WHERE table_name = 'psaims_elements'
           AND column_name = 'use_question_mode'"
    );
    $has_toggle_column = (bool)$stmt_check->fetchColumn();
} catch (Exception $ex) {}

// Cek apakah tabel question_role_mapping sudah ada
$has_qrm_table = false;
try {
    $stmt_check = $pdo->query(
        "SELECT 1 FROM information_schema.tables
         WHERE table_name = 'question_role_mapping'"
    );
    $has_qrm_table = (bool)$stmt_check->fetchColumn();
} catch (Exception $ex) {}

// Build query secara dinamis
$use_qm_expr = $has_toggle_column
    ? "COALESCE(e.use_question_mode, FALSE)"
    : "FALSE";

$mapped_expr = $has_qrm_table
    ? "(SELECT COUNT(DISTINCT q.id) FROM assessment_questions q
        LEFT JOIN question_role_mapping m ON m.question_id = q.id
        WHERE q.element_id = e.id AND q.is_active = TRUE AND m.id IS NOT NULL)"
    : "0";

$elements = [];
$query_error = null;
try {
    $stmt_elements = $pdo->query(
        "SELECT
            e.id AS element_id,
            e.element_number,
            e.element_name,
            {$use_qm_expr} AS use_question_mode,
            (SELECT COUNT(*) FROM assessment_questions q
             WHERE q.element_id = e.id AND q.is_active = TRUE) AS total_questions,
            {$mapped_expr} AS mapped_questions
         FROM psaims_elements e
         WHERE e.is_active = TRUE
         ORDER BY e.element_number"
    );
    // Force fetch mode associative
    $elements = $stmt_elements->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $ex) {
    $query_error = $ex->getMessage();
    $elements = [];
}

// Hitung mapping_percentage di PHP + ensure semua key ada
foreach ($elements as &$el) {
    $total  = (int)($el['total_questions']  ?? 0);
    $mapped = (int)($el['mapped_questions'] ?? 0);

    $el['total_questions']     = $total;
    $el['mapped_questions']    = $mapped;
    $el['mapping_percentage']  = $total > 0 ? (int)round(100 * $mapped / $total) : 0;
    $el['use_question_mode']   = !empty($el['use_question_mode']);
    $el['element_number']      = (int)($el['element_number']  ?? 0);
    $el['element_id']          = (int)($el['element_id']      ?? 0);
    $el['element_name']        = $el['element_name']          ?? '';
}
unset($el);

// Kalau migration belum dijalankan, kasih warning
if (!$has_toggle_column || !$has_qrm_table) {
    $migration_warning = 'Migration belum lengkap: ' .
        (!$has_toggle_column ? 'kolom use_question_mode belum ada. ' : '') .
        (!$has_qrm_table     ? 'tabel question_role_mapping belum ada. ' : '') .
        'Jalankan dulu <code>bulk_mapping_migration.sql</code> di pgAdmin.';
}

$selected_element = isset($_GET['element']) ? (int)$_GET['element'] : 10;

$current_el = null;
foreach ($elements as $el) {
    if ($el['element_number'] == $selected_element) { $current_el = $el; break; }
}

$questions = [];
if ($current_el) {
    try {
        if ($has_qrm_table) {
            $stmt = $pdo->prepare(
                "SELECT q.id, q.question_number, q.criteria,
                        STRING_AGG(DISTINCT r.role_code || ':' || m.responsibility, ',') AS mappings
                 FROM assessment_questions q
                 LEFT JOIN question_role_mapping m ON m.question_id = q.id
                 LEFT JOIN psaims_roles r ON r.id = m.role_id
                 WHERE q.element_id = ?
                   AND q.is_active = TRUE
                 GROUP BY q.id, q.question_number, q.criteria
                 ORDER BY q.question_number"
            );
        } else {
            // Tanpa JOIN ke question_role_mapping
            $stmt = $pdo->prepare(
                "SELECT q.id, q.question_number, q.criteria, NULL AS mappings
                 FROM assessment_questions q
                 WHERE q.element_id = ? AND q.is_active = TRUE
                 ORDER BY q.question_number"
            );
        }
        $stmt->execute([$current_el['element_id']]);
        $questions = $stmt->fetchAll();
    } catch (Exception $ex) {
        $questions = [];
    }
}

$roles = $pdo->query(
    "SELECT * FROM psaims_roles
     WHERE COALESCE(is_active, TRUE) = TRUE
     ORDER BY COALESCE(sort_order, 999), role_code"
)->fetchAll();

// Recent backups untuk elemen terpilih
$backups = [];
if ($current_el) {
    try {
        // Cek apakah tabel backup ada
        $stmt_check = $pdo->query(
            "SELECT 1 FROM information_schema.tables
             WHERE table_name = 'question_role_mapping_backup'"
        );
        if ($stmt_check->fetchColumn()) {
            $stmt = $pdo->prepare(
                "SELECT backup_batch_id, backup_reason, backed_up_at,
                        COUNT(*) AS row_count,
                        (SELECT username FROM users WHERE id = backed_up_by) AS user_name
                 FROM question_role_mapping_backup
                 WHERE element_id = ?
                 GROUP BY backup_batch_id, backup_reason, backed_up_at, backed_up_by
                 ORDER BY backed_up_at DESC
                 LIMIT 5"
            );
            $stmt->execute([$current_el['element_id']]);
            $backups = $stmt->fetchAll();
        }
    } catch (Exception $ex) {
        $backups = [];
    }
}

function parseMappings($str) {
    $result = [];
    if (!$str) return $result;
    foreach (explode(',', $str) as $pair) {
        if (strpos($pair, ':') === false) continue;
        [$role, $lvl] = explode(':', $pair);
        $result[$role][] = $lvl;
    }
    return $result;
}

$level_config = [
    'A' => ['danger',    'Accountable'],
    'R' => ['success',   'Responsible'],
    'S' => ['info',      'Support'],
    'C' => ['warning',   'Consulted'],
    'I' => ['secondary', 'Informed'],
];

$page_title = 'RASCI Per-Pertanyaan';
require_once __DIR__ . '/../includes/header.php';
require_once __DIR__ . '/../includes/sidebar.php';
?>

<div class="content-wrapper">
    <section class="content-header">
        <div class="container-fluid">
            <h1><i class="fas fa-layer-group"></i> RASCI Per-Pertanyaan</h1>
            <small class="text-muted">Mapping role untuk tiap pertanyaan individual</small>
        </div>
    </section>

    <section class="content">
        <div class="container-fluid">

            <?= readOnlyBanner() ?>

            <?php if ($msg): ?>
                <div class="alert alert-<?= $msg_type ?> alert-dismissible shadow-sm">
                    <button type="button" class="close" data-dismiss="alert">×</button>
                    <?= $msg ?>
                </div>
            <?php endif; ?>

            <?php if (!empty($migration_warning)): ?>
                <div class="alert alert-warning shadow-sm">
                    <h5><i class="fas fa-exclamation-triangle"></i> Database Migration Belum Lengkap</h5>
                    <p class="mb-2"><?= $migration_warning ?></p>
                    <p class="mb-0" style="font-size:12px;">
                        <strong>Lokasi file:</strong> <code>/mnt/user-data/outputs/bulk_mapping/bulk_mapping_migration.sql</code><br>
                        <strong>Cara jalankan:</strong> Buka pgAdmin → Query Tool → paste isi SQL → F5 (Execute).
                    </p>
                </div>
            <?php endif; ?>

            <?php if (!empty($query_error)): ?>
                <div class="alert alert-danger shadow-sm">
                    <h5><i class="fas fa-bug"></i> Database Query Error</h5>
                    <p class="mb-0" style="font-size:12px;">
                        <code><?= e($query_error) ?></code>
                    </p>
                </div>
            <?php endif; ?>

            <!-- =========== ACTION BAR =========== -->
            <div class="card card-outline card-warning">
                <div class="card-header">
                    <h5 class="card-title mb-0"><i class="fas fa-magic"></i> Bulk Actions</h5>
                </div>
                <div class="card-body">
                    <div class="row">
                        <div class="col-md-6">
                            <h6><i class="fas fa-rocket text-primary"></i> Inisialisasi Global</h6>
                            <p class="text-muted" style="font-size:12px;">
                                Copy semua RASCI level-elemen (18 elemen) menjadi mapping per-pertanyaan.
                                Backup otomatis akan dibuat untuk elemen yang sudah ada mapping-nya.
                            </p>
                            <form method="POST"
                                  onsubmit="return confirm('Ini akan generate mapping untuk SEMUA 242 pertanyaan di 18 elemen.\n\nMapping yang sudah ada akan di-backup otomatis.\n\nLanjutkan?');">
                                <input type="hidden" name="action" value="init_all_elements">
                                <button class="btn btn-primary btn-block">
                                    <i class="fas fa-rocket"></i> Initialize All 18 Elements
                                </button>
                            </form>
                        </div>
                        <div class="col-md-6">
                            <h6><i class="fas fa-info-circle text-info"></i> Cara Kerja</h6>
                            <ul style="font-size:12px; margin-bottom:0;">
                                <li><strong>Mode Pertanyaan</strong> aktif → sistem pakai <code>question_role_mapping</code></li>
                                <li><strong>Mode Elemen</strong> aktif → sistem pakai <code>element_role_mapping</code> (default)</li>
                                <li>Toggle bisa diubah per elemen kapan saja tanpa hapus data</li>
                                <li>Backup otomatis saat init/bulk apply/reset → bisa restore kapan saja</li>
                            </ul>
                        </div>
                    </div>
                </div>
            </div>

            <!-- =========== PILIH ELEMEN =========== -->
            <div class="card">
                <div class="card-header">
                    <h5 class="card-title mb-0"><i class="fas fa-list"></i> Status Mapping per Elemen</h5>
                </div>
                <div class="card-body">
                    <div class="row">
                        <?php foreach ($elements as $el):
                            // Defensive: fallback ke 0 kalau key tidak ada
                            $el_num     = $el['element_number']     ?? 0;
                            $el_total   = (int)($el['total_questions']   ?? 0);
                            $el_mapped  = (int)($el['mapped_questions']  ?? 0);
                            $el_pct     = (int)($el['mapping_percentage'] ?? 0);
                            $el_use_qm  = !empty($el['use_question_mode']);

                            $is_sel = $el_num == $selected_element;
                            $full   = $el_pct >= 100;
                            $partial= $el_pct > 0 && $el_pct < 100;
                            $none   = $el_pct == 0;

                            if ($is_sel) $btn_class = 'primary';
                            elseif ($full && $el_use_qm)  $btn_class = 'outline-success';
                            elseif ($full && !$el_use_qm) $btn_class = 'outline-info';
                            elseif ($partial) $btn_class = 'outline-warning';
                            else $btn_class = 'outline-secondary';
                        ?>
                            <div class="col-md-2 col-sm-4 col-6 mb-2">
                                <a href="?element=<?= $el_num ?>"
                                   class="btn btn-block btn-<?= $btn_class ?> btn-sm py-2 text-left"
                                   style="font-size:11px;">
                                    <div style="display:flex; justify-content:space-between; align-items:center;">
                                        <strong>E<?= str_pad($el_num, 2, '0', STR_PAD_LEFT) ?></strong>
                                        <?php if ($el_use_qm): ?>
                                            <span class="badge badge-success" title="Mode Pertanyaan Aktif">Q</span>
                                        <?php else: ?>
                                            <span class="badge badge-light" title="Mode Elemen">E</span>
                                        <?php endif; ?>
                                    </div>
                                    <div>
                                        <?= $el_mapped ?>/<?= $el_total ?>
                                        (<?= $el_pct ?>%)
                                    </div>
                                </a>
                            </div>
                        <?php endforeach; ?>
                    </div>
                    <div class="mt-2" style="font-size:11px;">
                        <span class="badge badge-outline-info">E</span> = Mode Elemen (default)
                        <span class="badge badge-outline-success ml-2">Q</span> = Mode Pertanyaan aktif
                    </div>
                </div>
            </div>

            <!-- =========== DETAIL ELEMEN =========== -->
            <?php if ($current_el): ?>
            <div class="card">
                <div class="card-header bg-primary">
                    <h3 class="card-title text-white">
                        E<?= $current_el['element_number'] ?> — <?= e($current_el['element_name']) ?>
                    </h3>
                    <div class="card-tools">
                        <span class="badge badge-light">
                            <?= $current_el['mapped_questions'] ?>/<?= $current_el['total_questions'] ?> pertanyaan
                        </span>
                    </div>
                </div>
                <div class="card-body">

                    <?php if (canAdminister()): ?>
                    <!-- Toggle mode + actions -->
                    <div class="row mb-3">
                        <div class="col-md-4">
                            <div class="p-2 border rounded">
                                <strong style="font-size:12px;">Mode Aktif</strong>
                                <form method="POST" class="d-inline">
                                    <input type="hidden" name="action" value="toggle_mode">
                                    <input type="hidden" name="element_id" value="<?= $current_el['element_id'] ?>">
                                    <div class="form-check mt-1">
                                        <label class="switch">
                                            <input type="checkbox"
                                                   <?= $current_el['use_question_mode'] ? 'checked' : '' ?>
                                                   onchange="this.form.submit()">
                                            <span class="slider round"></span>
                                        </label>
                                        <span class="ml-2" style="font-size:12px;">
                                            <?php if ($current_el['use_question_mode']): ?>
                                                <strong class="text-success">Mode Pertanyaan</strong>
                                            <?php else: ?>
                                                <strong class="text-info">Mode Elemen</strong>
                                            <?php endif; ?>
                                        </span>
                                    </div>
                                </form>
                            </div>
                        </div>
                        <div class="col-md-4">
                            <div class="p-2 border rounded">
                                <strong style="font-size:12px;">Inisialisasi dari Elemen</strong>
                                <form method="POST" class="mt-1"
                                      onsubmit="return confirm('Copy RASCI elemen ke semua pertanyaan di E<?= $current_el['element_number'] ?>?\n\nMapping lama akan di-backup otomatis.');">
                                    <input type="hidden" name="action" value="init_from_element">
                                    <input type="hidden" name="element_id" value="<?= $current_el['element_id'] ?>">
                                    <input type="hidden" name="overwrite" value="1">
                                    <button class="btn btn-info btn-sm btn-block">
                                        <i class="fas fa-copy"></i> Copy dari RASCI Elemen
                                    </button>
                                </form>
                            </div>
                        </div>
                        <div class="col-md-4">
                            <div class="p-2 border rounded">
                                <strong style="font-size:12px;">Reset Mapping</strong>
                                <form method="POST" class="mt-1"
                                      onsubmit="return confirm('PERINGATAN: Semua mapping pertanyaan di E<?= $current_el['element_number'] ?> akan dihapus.\n\nBackup akan dibuat otomatis dan bisa direstore.\n\nLanjutkan?');">
                                    <input type="hidden" name="action" value="reset_element">
                                    <input type="hidden" name="element_id" value="<?= $current_el['element_id'] ?>">
                                    <button class="btn btn-outline-danger btn-sm btn-block">
                                        <i class="fas fa-undo"></i> Reset Mapping
                                    </button>
                                </form>
                            </div>
                        </div>
                    </div>
                    <?php else: ?>
                    <!-- Info mode untuk non-admin -->
                    <div class="alert alert-light mb-3" style="border-left: 3px solid <?= $current_el['use_question_mode'] ? '#28a745' : '#17a2b8' ?>;">
                        <small>
                            <strong>Mode Aktif:</strong>
                            <?= $current_el['use_question_mode'] ? '<span class="text-success">Mode Pertanyaan</span> (RASCI per-pertanyaan)' : '<span class="text-info">Mode Elemen</span> (RASCI per-elemen)' ?>
                        </small>
                    </div>
                    <?php endif; ?>

                    <!-- Tabel pertanyaan -->
                    <div class="table-responsive">
                        <table class="table table-bordered table-hover table-sm" style="font-size:12px;">
                            <thead class="bg-light">
                                <tr>
                                    <th style="width:36px;">#</th>
                                    <th style="width:50px;">Ref</th>
                                    <th>Persyaratan</th>
                                    <?php foreach ($roles as $r): ?>
                                        <th style="width:70px; text-align:center;">
                                            <?= e($r['role_code']) ?>
                                        </th>
                                    <?php endforeach; ?>
                                    <?php if (canAdminister()): ?>
                                    <th style="width:100px;">Aksi</th>
                                    <?php endif; ?>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if (empty($questions)): ?>
                                    <tr>
                                        <td colspan="<?= 4 + count($roles) ?>" class="text-center text-muted py-3">
                                            Tidak ada pertanyaan di elemen ini
                                        </td>
                                    </tr>
                                <?php endif; ?>
                                <?php foreach ($questions as $q):
                                    $mappings = parseMappings($q['mappings']);
                                    $preview = mb_substr(strip_tags($q['criteria']), 0, 100);
                                    $ref_display = '';
                                    if (preg_match('/\[Ref\s+([0-9.a-z]+)\]/i', $q['criteria'], $mm)) {
                                        $ref_display = $mm[1];
                                    }
                                    $has_mapping = !empty($mappings);
                                ?>
                                    <tr class="<?= !$has_mapping ? 'table-warning' : '' ?>">
                                        <td class="text-center"><?= $q['question_number'] ?></td>
                                        <td><strong><?= e($ref_display ?: '—') ?></strong></td>
                                        <td><?= e($preview) ?>…</td>
                                        <?php foreach ($roles as $r):
                                            $levels = $mappings[$r['role_code']] ?? [];
                                        ?>
                                            <td class="text-center">
                                                <?php foreach ($levels as $lvl):
                                                    if (!isset($level_config[$lvl])) continue;
                                                    [$color, $label] = $level_config[$lvl];
                                                ?>
                                                    <span class="badge badge-<?= $color ?>" title="<?= $label ?>">
                                                        <?= $lvl ?>
                                                    </span>
                                                <?php endforeach; ?>
                                                <?php if (empty($levels)): ?>
                                                    <small class="text-muted">—</small>
                                                <?php endif; ?>
                                            </td>
                                        <?php endforeach; ?>
                                        <?php if (canAdminister()): ?>
                                        <td class="text-center">
                                            <button type="button"
                                                    class="btn btn-xs btn-outline-primary btn-edit-q"
                                                    data-qid="<?= $q['id'] ?>"
                                                    data-eid="<?= $current_el['element_id'] ?>"
                                                    data-mappings-b64="<?= base64_encode(json_encode($mappings)) ?>"
                                                    data-ref="<?= e($ref_display ?: 'Q' . $q['question_number']) ?>"
                                                    data-preview="<?= e($preview) ?>"
                                                    title="Edit mapping">
                                                <i class="fas fa-edit"></i>
                                            </button>
                                            <?php if ($has_mapping): ?>
                                                <button type="button"
                                                        class="btn btn-xs btn-outline-warning btn-apply-all"
                                                        data-qid="<?= $q['id'] ?>"
                                                        data-eid="<?= $current_el['element_id'] ?>"
                                                        data-ref="<?= e($ref_display) ?>"
                                                        title="Apply mapping ini ke semua soal dalam elemen">
                                                    <i class="fas fa-share-alt"></i>
                                                </button>
                                            <?php endif; ?>
                                        </td>
                                        <?php endif; ?>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>

                    <!-- Backup history -->
                    <?php if (!empty($backups)): ?>
                        <div class="mt-3">
                            <h6><i class="fas fa-history"></i> Backup Recent (5 terakhir)</h6>
                            <table class="table table-sm table-bordered" style="font-size:11px;">
                                <thead class="bg-light">
                                    <tr>
                                        <th>Waktu</th>
                                        <th>Alasan</th>
                                        <th>Rows</th>
                                        <th>By</th>
                                        <?php if (canAdminister()): ?>
                                        <th style="width:90px;">Action</th>
                                        <?php endif; ?>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($backups as $b): ?>
                                        <tr>
                                            <td><?= date('d/m/Y H:i:s', strtotime($b['backed_up_at'])) ?></td>
                                            <td><code><?= e($b['backup_reason']) ?></code></td>
                                            <td><?= $b['row_count'] ?></td>
                                            <td><?= e($b['user_name'] ?? '—') ?></td>
                                            <?php if (canAdminister()): ?>
                                            <td class="text-center">
                                                <form method="POST" class="d-inline"
                                                      onsubmit="return confirm('Restore dari backup ini?\n\nMapping current akan dihapus dan diganti dengan snapshot.');">
                                                    <input type="hidden" name="action" value="restore_backup">
                                                    <input type="hidden" name="batch_id" value="<?= e($b['backup_batch_id']) ?>">
                                                    <button class="btn btn-xs btn-outline-success">
                                                        <i class="fas fa-undo-alt"></i> Restore
                                                    </button>
                                                </form>
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
            <?php endif; ?>

        </div>
    </section>
</div>

<!-- ============ MODAL: Edit Single Mapping ============ -->
<div class="modal fade" id="modalEditMapping" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <form method="POST">
            <div class="modal-content">
                <div class="modal-header bg-primary">
                    <h5 class="modal-title text-white">
                        <i class="fas fa-edit"></i> Edit RASCI
                        <span id="modal-ref-label" class="ml-2"></span>
                    </h5>
                    <button type="button" class="close text-white" data-dismiss="modal">×</button>
                </div>
                <div class="modal-body">
                    <p class="text-muted" id="modal-preview" style="font-size:12px;"></p>

                    <div class="alert alert-info py-2">
                        <small><i class="fas fa-info-circle"></i>
                        Centang level RASCI untuk tiap role. Ideal: tepat 1 A, minimal 1 R.</small>
                    </div>

                    <input type="hidden" name="action" value="update_single">
                    <input type="hidden" name="question_id" id="modal-qid">

                    <table class="table table-bordered table-sm" style="font-size:12px;">
                        <thead class="bg-light">
                            <tr>
                                <th>Role</th>
                                <th class="text-center">A</th>
                                <th class="text-center">R</th>
                                <th class="text-center">S</th>
                                <th class="text-center">C</th>
                                <th class="text-center">I</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($roles as $r): ?>
                                <tr>
                                    <td>
                                        <span class="badge badge-<?= e($r['badge_color'] ?? 'secondary') ?>">
                                            <?= e($r['role_code']) ?>
                                        </span>
                                        <br>
                                        <small class="text-muted"><?= e($r['role_name']) ?></small>
                                    </td>
                                    <?php foreach (['A','R','S','C','I'] as $lvl): ?>
                                        <td class="text-center">
                                            <input type="checkbox"
                                                   name="mapping[<?= e($r['role_code']) ?>][]"
                                                   value="<?= $lvl ?>"
                                                   class="mapping-checkbox"
                                                   data-role="<?= e($r['role_code']) ?>"
                                                   data-level="<?= $lvl ?>">
                                        </td>
                                    <?php endforeach; ?>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-dismiss="modal">Batal</button>
                    <button type="submit" class="btn btn-primary">
                        <i class="fas fa-save"></i> Simpan
                    </button>
                </div>
            </div>
        </form>
    </div>
</div>

<!-- ============ MODAL: Bulk Apply ============ -->
<div class="modal fade" id="modalBulkApply" tabindex="-1">
    <div class="modal-dialog">
        <form method="POST">
            <div class="modal-content">
                <div class="modal-header bg-warning">
                    <h5 class="modal-title">
                        <i class="fas fa-share-alt"></i> Apply Mapping ke Semua Pertanyaan
                    </h5>
                    <button type="button" class="close" data-dismiss="modal">×</button>
                </div>
                <div class="modal-body">
                    <div class="alert alert-warning">
                        <strong>⚠ Perhatian:</strong> Semua pertanyaan di elemen ini akan memiliki
                        mapping RASCI yang sama dengan pertanyaan <strong id="bulk-source-ref"></strong>.
                        <br><br>
                        Mapping lama akan di-<strong>backup otomatis</strong> dan bisa direstore kapan saja.
                    </div>
                    <p style="font-size:13px;">
                        Cocok untuk elemen yang <em>homogen</em> (semua pertanyaan berkaitan dengan role yang sama).
                    </p>

                    <input type="hidden" name="action" value="bulk_apply_to_element">
                    <input type="hidden" name="source_question_id" id="bulk-source-qid">
                    <input type="hidden" name="element_id" id="bulk-element-id">
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-dismiss="modal">Batal</button>
                    <button type="submit" class="btn btn-warning">
                        <i class="fas fa-share-alt"></i> Apply ke Semua
                    </button>
                </div>
            </div>
        </form>
    </div>
</div>

<style>
.switch {
    position: relative; display: inline-block;
    width: 44px; height: 22px; vertical-align: middle;
}
.switch input { opacity: 0; width: 0; height: 0; }
.slider {
    position: absolute; cursor: pointer;
    top: 0; left: 0; right: 0; bottom: 0;
    background-color: #ccc; transition: .3s; border-radius: 22px;
}
.slider:before {
    position: absolute; content: "";
    height: 16px; width: 16px; left: 3px; bottom: 3px;
    background-color: white; transition: .3s; border-radius: 50%;
}
input:checked + .slider { background-color: #28a745; }
input:checked + .slider:before { transform: translateX(22px); }
.badge-outline-info { color: #17a2b8; border: 1px solid #17a2b8; background: transparent; padding: 2px 5px; border-radius: 3px; }
.badge-outline-success { color: #28a745; border: 1px solid #28a745; background: transparent; padding: 2px 5px; border-radius: 3px; }
</style>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>

<script>
// Script ini jalan SETELAH footer.php yang meload jQuery & Bootstrap
jQuery(function($) {

    // Pindahkan modal ke body untuk hindari masalah z-index/overflow
    // (content-wrapper AdminLTE punya position: relative yang bisa crop modal)
    $('#modalEditMapping').appendTo('body');
    $('#modalBulkApply').appendTo('body');

    // Edit single mapping
    $(document).on('click', '.btn-edit-q', function(e) {
        e.preventDefault();
        e.stopPropagation();

        const $btn = $(this);
        const b64 = $btn.attr('data-mappings-b64') || '';
        let mappings = {};
        if (b64) {
            try { mappings = JSON.parse(atob(b64)) || {}; }
            catch (err) { console.error('decode err', err); mappings = {}; }
        }

        const qid = $btn.attr('data-qid');
        const ref = $btn.attr('data-ref') || '';
        const preview = $btn.attr('data-preview') || '';

        console.log('[btn-edit-q] qid=', qid, 'ref=', ref, 'mappings=', mappings);

        $('#modal-qid').val(qid);
        $('#modal-ref-label').text(ref);
        $('#modal-preview').text(preview);

        // Reset dan set checkbox
        $('#modalEditMapping .mapping-checkbox').prop('checked', false);
        Object.keys(mappings).forEach(role => {
            const lvls = mappings[role];
            if (!Array.isArray(lvls)) return;
            lvls.forEach(lvl => {
                $('#modalEditMapping .mapping-checkbox[data-role="' + role + '"][data-level="' + lvl + '"]')
                    .prop('checked', true);
            });
        });

        $('#modalEditMapping').modal('show');
    });

    // Bulk apply
    $(document).on('click', '.btn-apply-all', function(e) {
        e.preventDefault();
        e.stopPropagation();

        const $btn = $(this);
        $('#bulk-source-qid').val($btn.attr('data-qid'));
        $('#bulk-element-id').val($btn.attr('data-eid'));
        $('#bulk-source-ref').text($btn.attr('data-ref') || '');
        $('#modalBulkApply').modal('show');
    });

    // Validasi single edit (delegated supaya tetap jalan setelah appendTo)
    $(document).on('submit', '#modalEditMapping form', function(e) {
        const aCount = $('#modalEditMapping .mapping-checkbox[data-level="A"]:checked').length;
        const rCount = $('#modalEditMapping .mapping-checkbox[data-level="R"]:checked').length;
        if (aCount !== 1) {
            if (!confirm('Accountable seharusnya tepat 1 (saat ini: ' + aCount + ').\nTetap simpan?')) {
                e.preventDefault(); return false;
            }
        }
        if (rCount < 1) {
            if (!confirm('Responsible seharusnya minimal 1 (saat ini: ' + rCount + ').\nTetap simpan?')) {
                e.preventDefault(); return false;
            }
        }
    });
});
</script>