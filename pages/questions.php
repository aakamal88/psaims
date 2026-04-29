<?php
require_once __DIR__ . '/../config/config.php';
requireLogin();

if (!canAdminister()) {
    die('Akses ditolak. Halaman ini hanya untuk administrator.');
}

$current_user_id = $_SESSION['user_id'] ?? null;
$msg = ''; $msg_type = '';

// ============ HANDLE POST ============
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    try {
        // ---------- ADD ----------
        if ($action === 'add') {
            $element_id      = (int)$_POST['element_id'];
            $question_number = trim($_POST['question_number'] ?? '');
            $criteria        = trim($_POST['criteria'] ?? '');
            $audit_protocol  = trim($_POST['audit_protocol'] ?? '');
            $score_criteria  = trim($_POST['score_criteria'] ?? '');

            if (!$element_id) throw new Exception('Element wajib dipilih.');
            if (empty($question_number)) throw new Exception('Question number wajib diisi.');
            if (empty($criteria)) throw new Exception('Persyaratan (criteria) wajib diisi.');

            $stmt = $pdo->prepare(
                "INSERT INTO assessment_questions
                 (element_id, question_number, criteria, audit_protocol, score_criteria, is_active)
                 VALUES (?, ?, ?, ?, ?, TRUE)
                 RETURNING id"
            );
            $stmt->execute([$element_id, $question_number, $criteria, $audit_protocol ?: null, $score_criteria ?: null]);
            $new_id = $stmt->fetchColumn();

            logActivity('CREATE', "Tambah pertanyaan #{$new_id}: Q{$question_number}");
            $msg = "✓ Pertanyaan berhasil ditambahkan (ID #{$new_id}).";
            $msg_type = 'success';
        }

        // ---------- EDIT ----------
        elseif ($action === 'edit') {
            $id              = (int)$_POST['id'];
            $element_id      = (int)$_POST['element_id'];
            $question_number = trim($_POST['question_number'] ?? '');
            $criteria        = trim($_POST['criteria'] ?? '');
            $audit_protocol  = trim($_POST['audit_protocol'] ?? '');
            $score_criteria  = trim($_POST['score_criteria'] ?? '');

            if (!$id) throw new Exception('ID tidak valid.');
            if (empty($criteria)) throw new Exception('Persyaratan wajib diisi.');

            $stmt = $pdo->prepare(
                "UPDATE assessment_questions
                 SET element_id = ?, question_number = ?, criteria = ?,
                     audit_protocol = ?, score_criteria = ?
                 WHERE id = ?"
            );
            $stmt->execute([$element_id, $question_number, $criteria, $audit_protocol ?: null, $score_criteria ?: null, $id]);

            logActivity('UPDATE', "Update pertanyaan #{$id}");
            $msg = "✓ Pertanyaan #{$id} berhasil diupdate.";
            $msg_type = 'success';
        }

        // ---------- TOGGLE ACTIVE ----------
        elseif ($action === 'toggle_active') {
            $id = (int)$_POST['id'];
            $stmt = $pdo->prepare(
                "UPDATE assessment_questions SET is_active = NOT is_active WHERE id = ?"
            );
            $stmt->execute([$id]);

            // Cek status baru
            $new_status = $pdo->prepare("SELECT is_active FROM assessment_questions WHERE id = ?");
            $new_status->execute([$id]);
            $is_active = $new_status->fetchColumn();

            logActivity('UPDATE', "Toggle active pertanyaan #{$id} → " . ($is_active ? 'AKTIF' : 'NONAKTIF'));
            $msg = $is_active ? "✓ Pertanyaan #{$id} diaktifkan." : "✓ Pertanyaan #{$id} dinonaktifkan.";
            $msg_type = $is_active ? 'success' : 'warning';
        }

        // ---------- DELETE ----------
        elseif ($action === 'delete') {
            $id = (int)$_POST['id'];

            // Cek apakah sudah ada jawaban
            $stmt = $pdo->prepare("SELECT COUNT(*) FROM assessment_results WHERE question_id = ?");
            $stmt->execute([$id]);
            $answer_count = $stmt->fetchColumn();

            if ($answer_count > 0) {
                throw new Exception("Pertanyaan ini sudah dijawab oleh {$answer_count} user. Tidak bisa dihapus permanen — gunakan tombol nonaktifkan untuk jaga integritas data historis.");
            }

            $stmt = $pdo->prepare("DELETE FROM assessment_questions WHERE id = ?");
            $stmt->execute([$id]);

            logActivity('DELETE', "Hapus permanen pertanyaan #{$id}");
            $msg = "✓ Pertanyaan #{$id} berhasil dihapus permanen.";
            $msg_type = 'success';
        }

    } catch (Exception $e) {
        $msg = '✗ Gagal: ' . $e->getMessage();
        $msg_type = 'danger';
    }
}

// ============ FILTERS ============
$filter_element = isset($_GET['element']) ? (int)$_GET['element'] : 0;
$filter_status  = $_GET['status'] ?? 'all';
$search         = trim($_GET['q'] ?? '');

// ============ FETCH ELEMENTS ============
$elements = $pdo->query(
    "SELECT id, element_number, element_name, icon, color
     FROM psaims_elements
     WHERE is_active = TRUE
     ORDER BY element_number"
)->fetchAll();

// ============ FETCH QUESTIONS ============
$sql = "SELECT q.*,
               e.element_number, e.element_name, e.icon AS element_icon, e.color AS element_color,
               (SELECT COUNT(*) FROM assessment_results WHERE question_id = q.id) AS answer_count
        FROM assessment_questions q
        JOIN psaims_elements e ON e.id = q.element_id
        WHERE 1=1";
$params = [];

if ($filter_element > 0) {
    $sql .= " AND e.element_number = ?";
    $params[] = $filter_element;
}
if ($filter_status === 'active') {
    $sql .= " AND q.is_active = TRUE";
} elseif ($filter_status === 'inactive') {
    $sql .= " AND q.is_active = FALSE";
}
if ($search !== '') {
    $sql .= " AND (LOWER(q.criteria) LIKE LOWER(?)
              OR LOWER(q.audit_protocol) LIKE LOWER(?)
              OR LOWER(q.question_number) LIKE LOWER(?))";
    $params[] = "%{$search}%";
    $params[] = "%{$search}%";
    $params[] = "%{$search}%";
}

$sql .= " ORDER BY e.element_number, q.id";

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$questions = $stmt->fetchAll();

// ============ STATS ============
$stats = $pdo->query(
    "SELECT
        COUNT(*) AS total,
        COUNT(*) FILTER (WHERE is_active = TRUE) AS active,
        COUNT(*) FILTER (WHERE is_active = FALSE) AS inactive,
        COUNT(DISTINCT element_id) AS elements_with_q
     FROM assessment_questions"
)->fetch();

$page_title = 'Kelola Pertanyaan';
require_once __DIR__ . '/../includes/header.php';
require_once __DIR__ . '/../includes/sidebar.php';
?>

<div class="content-wrapper">
    <section class="content-header">
        <div class="container-fluid">
            <div class="row align-items-center">
                <div class="col-sm-8">
                    <h1><i class="fas fa-question-circle text-warning"></i> Kelola Pertanyaan</h1>
                    <small class="text-muted">Master data 242 persyaratan PSAIMS</small>
                </div>
                <div class="col-sm-4 text-right">
                    <?php if (canAdminister()): ?>
                    <button type="button" class="btn btn-primary" data-toggle="modal" data-target="#modalAdd">
                        <i class="fas fa-plus"></i> Tambah Pertanyaan
                    </button>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </section>

    <section class="content">
        <div class="container-fluid">

            <?php if ($msg): ?>
                <div class="alert alert-<?= $msg_type ?> alert-dismissible">
                    <button type="button" class="close" data-dismiss="alert">&times;</button>
                    <?= $msg ?>
                </div>
            <?php endif; ?>

            <!-- STATS -->
            <div class="row">
                <div class="col-md-3 col-sm-6">
                    <div class="small-box bg-info">
                        <div class="inner"><h3><?= $stats['total'] ?></h3><p>Total Pertanyaan</p></div>
                        <div class="icon"><i class="fas fa-question-circle"></i></div>
                    </div>
                </div>
                <div class="col-md-3 col-sm-6">
                    <div class="small-box bg-success">
                        <div class="inner"><h3><?= $stats['active'] ?></h3><p>Aktif</p></div>
                        <div class="icon"><i class="fas fa-check-circle"></i></div>
                    </div>
                </div>
                <div class="col-md-3 col-sm-6">
                    <div class="small-box bg-secondary">
                        <div class="inner"><h3><?= $stats['inactive'] ?></h3><p>Nonaktif</p></div>
                        <div class="icon"><i class="fas fa-pause-circle"></i></div>
                    </div>
                </div>
                <div class="col-md-3 col-sm-6">
                    <div class="small-box bg-primary">
                        <div class="inner"><h3><?= $stats['elements_with_q'] ?> / 18</h3><p>Elemen Terisi</p></div>
                        <div class="icon"><i class="fas fa-th-large"></i></div>
                    </div>
                </div>
            </div>

            <!-- FILTER -->
            <div class="card">
                <div class="card-body py-2">
                    <form method="GET" class="form-inline">
                        <div class="form-group mr-3 mb-2">
                            <label class="mr-1" style="font-size:12px;">Elemen:</label>
                            <select name="element" class="form-control form-control-sm">
                                <option value="0">Semua Elemen</option>
                                <?php foreach ($elements as $el): ?>
                                    <option value="<?= $el['element_number'] ?>"
                                            <?= $el['element_number'] == $filter_element ? 'selected' : '' ?>>
                                        E<?= str_pad($el['element_number'], 2, '0', STR_PAD_LEFT) ?> — <?= e(mb_strimwidth($el['element_name'], 0, 30, '…')) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="form-group mr-3 mb-2">
                            <label class="mr-1" style="font-size:12px;">Status:</label>
                            <select name="status" class="form-control form-control-sm">
                                <option value="all"      <?= $filter_status === 'all'      ? 'selected' : '' ?>>Semua</option>
                                <option value="active"   <?= $filter_status === 'active'   ? 'selected' : '' ?>>Aktif Saja</option>
                                <option value="inactive" <?= $filter_status === 'inactive' ? 'selected' : '' ?>>Nonaktif Saja</option>
                            </select>
                        </div>
                        <div class="form-group mr-3 mb-2">
                            <input type="text" name="q" value="<?= e($search) ?>"
                                   placeholder="Cari criteria/protokol..."
                                   class="form-control form-control-sm" style="width:240px;">
                        </div>
                        <button type="submit" class="btn btn-primary btn-sm mb-2">
                            <i class="fas fa-search"></i> Filter
                        </button>
                        <a href="<?= BASE_URL ?>pages/questions.php" class="btn btn-outline-secondary btn-sm mb-2 ml-1">Reset</a>
                    </form>
                </div>
            </div>

            <!-- QUESTIONS TABLE -->
            <div class="card">
                <div class="card-header">
                    <h5 class="card-title mb-0">
                        <i class="fas fa-list"></i> Daftar Pertanyaan
                        <span class="badge badge-secondary ml-2"><?= count($questions) ?></span>
                    </h5>
                </div>
                <div class="card-body p-0">
                    <?php if (empty($questions)): ?>
                        <div class="text-center p-4 text-muted">
                            <i class="fas fa-question-circle fa-3x mb-2" style="opacity:0.3;"></i>
                            <p>Tidak ada pertanyaan sesuai filter.</p>
                        </div>
                    <?php else: ?>
                        <div class="table-responsive">
                            <table class="table table-hover mb-0" style="font-size:12px;">
                                <thead class="bg-light">
                                    <tr>
                                        <th style="width:60px;">ID</th>
                                        <th style="width:140px;">Elemen</th>
                                        <th style="width:60px;">Q#</th>
                                        <th>Persyaratan / Audit Protocol</th>
                                        <th style="width:90px;" class="text-center">Jawaban</th>
                                        <th style="width:90px;" class="text-center">Status</th>
                                        <?php if (canAdminister()): ?>
                                        <th style="width:120px;">Aksi</th>
                                        <?php endif; ?>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($questions as $q): ?>
                                        <tr class="<?= $q['is_active'] ? '' : 'table-secondary' ?>">
                                            <td class="text-center">#<?= $q['id'] ?></td>
                                            <td>
                                                <span class="badge badge-light">
                                                    <i class="<?= e($q['element_icon']) ?> text-<?= e($q['element_color']) ?>"></i>
                                                    E<?= str_pad($q['element_number'], 2, '0', STR_PAD_LEFT) ?>
                                                </span>
                                                <br>
                                                <small><?= e(mb_strimwidth($q['element_name'], 0, 18, '…')) ?></small>
                                            </td>
                                            <td>
                                                <strong class="text-primary"><?= e($q['question_number']) ?></strong>
                                            </td>
                                            <td>
                                                <strong style="font-size:12px;"><?= e(mb_strimwidth(strip_tags($q['criteria']), 0, 120, '…')) ?></strong>
                                                <?php if ($q['audit_protocol']): ?>
                                                    <br>
                                                    <small class="text-info">
                                                        <i class="fas fa-question-circle"></i>
                                                        <?= e(mb_strimwidth(strip_tags($q['audit_protocol']), 0, 100, '…')) ?>
                                                    </small>
                                                <?php endif; ?>
                                            </td>
                                            <td class="text-center">
                                                <?php if ($q['answer_count'] > 0): ?>
                                                    <span class="badge badge-info"><?= $q['answer_count'] ?></span>
                                                <?php else: ?>
                                                    <small class="text-muted">-</small>
                                                <?php endif; ?>
                                            </td>
                                            <td class="text-center">
                                                <?php if ($q['is_active']): ?>
                                                    <span class="badge badge-success"><i class="fas fa-check"></i> Aktif</span>
                                                <?php else: ?>
                                                    <span class="badge badge-secondary"><i class="fas fa-pause"></i> Nonaktif</span>
                                                <?php endif; ?>
                                            </td>
                                            <?php if (canAdminister()): ?>
                                            <td>
                                                <button type="button" class="btn btn-xs btn-outline-info btn-edit"
                                                        data-id="<?= $q['id'] ?>"
                                                        data-element_id="<?= $q['element_id'] ?>"
                                                        data-question_number="<?= e($q['question_number']) ?>"
                                                        data-criteria="<?= e($q['criteria'] ?? '') ?>"
                                                        data-audit_protocol="<?= e($q['audit_protocol'] ?? '') ?>"
                                                        data-score_criteria="<?= e($q['score_criteria'] ?? '') ?>"
                                                        title="Edit">
                                                    <i class="fas fa-edit"></i>
                                                </button>

                                                <form method="POST" class="d-inline"
                                                      onsubmit="return confirm('<?= $q['is_active'] ? 'Nonaktifkan' : 'Aktifkan' ?> pertanyaan #<?= $q['id'] ?>?');">
                                                    <input type="hidden" name="action" value="toggle_active">
                                                    <input type="hidden" name="id" value="<?= $q['id'] ?>">
                                                    <button type="submit"
                                                            class="btn btn-xs btn-outline-<?= $q['is_active'] ? 'warning' : 'success' ?>"
                                                            title="<?= $q['is_active'] ? 'Nonaktifkan' : 'Aktifkan' ?>">
                                                        <i class="fas fa-<?= $q['is_active'] ? 'pause' : 'play' ?>"></i>
                                                    </button>
                                                </form>

                                                <?php if ($q['answer_count'] == 0): ?>
                                                    <form method="POST" class="d-inline"
                                                          onsubmit="return confirm('HAPUS PERMANEN pertanyaan #<?= $q['id'] ?>?\n\nIni tidak bisa di-undo!');">
                                                        <input type="hidden" name="action" value="delete">
                                                        <input type="hidden" name="id" value="<?= $q['id'] ?>">
                                                        <button type="submit" class="btn btn-xs btn-outline-danger" title="Hapus permanen">
                                                            <i class="fas fa-trash"></i>
                                                        </button>
                                                    </form>
                                                <?php endif; ?>
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

<!-- ============ MODAL: ADD ============ -->
<div class="modal fade" id="modalAdd" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <form method="POST">
                <div class="modal-header bg-primary">
                    <h5 class="modal-title text-white"><i class="fas fa-plus"></i> Tambah Pertanyaan Baru</h5>
                    <button type="button" class="close text-white" data-dismiss="modal">&times;</button>
                </div>
                <div class="modal-body">
                    <input type="hidden" name="action" value="add">

                    <div class="row">
                        <div class="col-md-8">
                            <div class="form-group">
                                <label>Elemen <span class="text-danger">*</span></label>
                                <select name="element_id" class="form-control" required>
                                    <option value="">— Pilih elemen —</option>
                                    <?php foreach ($elements as $el): ?>
                                        <option value="<?= $el['id'] ?>">
                                            E<?= str_pad($el['element_number'], 2, '0', STR_PAD_LEFT) ?> — <?= e($el['element_name']) ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                        </div>
                        <div class="col-md-4">
                            <div class="form-group">
                                <label>Question Number <span class="text-danger">*</span></label>
                                <input type="text" name="question_number" class="form-control" placeholder="contoh: 1.1, 1.2a" required>
                            </div>
                        </div>
                    </div>

                    <div class="form-group">
                        <label>Persyaratan / Criteria <span class="text-danger">*</span></label>
                        <textarea name="criteria" class="form-control" rows="3" required
                                  placeholder="contoh: 1. Menyusun dan menetapkan kebijakan PSAIMS..."></textarea>
                        <small class="text-muted">Wajib diisi. Bisa pakai marker [Ref X.Y] di awal kalau perlu.</small>
                    </div>

                    <div class="form-group">
                        <label>Audit Protocol (Pertanyaan Audit)</label>
                        <textarea name="audit_protocol" class="form-control" rows="2"
                                  placeholder="contoh: Apakah Perusahaan telah menetapkan...?"></textarea>
                    </div>

                    <div class="form-group">
                        <label>Score Criteria (Kriteria Penilaian)</label>
                        <textarea name="score_criteria" class="form-control" rows="3"
                                  placeholder="contoh: (25%) Tersedia kebijakan&#10;(50%) Sudah diimplementasi&#10;(100%) Sudah audit"></textarea>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-dismiss="modal">Batal</button>
                    <button type="submit" class="btn btn-primary"><i class="fas fa-save"></i> Simpan</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- ============ MODAL: EDIT ============ -->
<div class="modal fade" id="modalEdit" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <form method="POST">
                <div class="modal-header bg-info">
                    <h5 class="modal-title text-white"><i class="fas fa-edit"></i> Edit Pertanyaan <span id="editId"></span></h5>
                    <button type="button" class="close text-white" data-dismiss="modal">&times;</button>
                </div>
                <div class="modal-body">
                    <input type="hidden" name="action" value="edit">
                    <input type="hidden" name="id" id="edit_id">

                    <div class="row">
                        <div class="col-md-8">
                            <div class="form-group">
                                <label>Elemen <span class="text-danger">*</span></label>
                                <select name="element_id" id="edit_element_id" class="form-control" required>
                                    <?php foreach ($elements as $el): ?>
                                        <option value="<?= $el['id'] ?>">
                                            E<?= str_pad($el['element_number'], 2, '0', STR_PAD_LEFT) ?> — <?= e($el['element_name']) ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                        </div>
                        <div class="col-md-4">
                            <div class="form-group">
                                <label>Question Number <span class="text-danger">*</span></label>
                                <input type="text" name="question_number" id="edit_question_number" class="form-control" required>
                            </div>
                        </div>
                    </div>

                    <div class="form-group">
                        <label>Persyaratan / Criteria <span class="text-danger">*</span></label>
                        <textarea name="criteria" id="edit_criteria" class="form-control" rows="3" required></textarea>
                    </div>

                    <div class="form-group">
                        <label>Audit Protocol</label>
                        <textarea name="audit_protocol" id="edit_audit_protocol" class="form-control" rows="2"></textarea>
                    </div>

                    <div class="form-group">
                        <label>Score Criteria</label>
                        <textarea name="score_criteria" id="edit_score_criteria" class="form-control" rows="3"></textarea>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-dismiss="modal">Batal</button>
                    <button type="submit" class="btn btn-info"><i class="fas fa-save"></i> Update</button>
                </div>
            </form>
        </div>
    </div>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>

<script>
jQuery(function($) {
    // Move modal ke body untuk mencegah overflow clip
    $('#modalAdd, #modalEdit').appendTo('body');

    // Handle edit button
    $(document).on('click', '.btn-edit', function() {
        const $btn = $(this);
        $('#editId').text('#' + $btn.data('id'));
        $('#edit_id').val($btn.data('id'));
        $('#edit_element_id').val($btn.data('element_id'));
        $('#edit_question_number').val($btn.data('question_number'));
        $('#edit_criteria').val($btn.data('criteria'));
        $('#edit_audit_protocol').val($btn.data('audit_protocol'));
        $('#edit_score_criteria').val($btn.data('score_criteria'));
        $('#modalEdit').modal('show');
    });
});
</script>