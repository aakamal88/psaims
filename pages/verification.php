<?php
require_once __DIR__ . '/../config/config.php';
requireLogin();

// Hanya admin & assessor yang bisa akses
if (!canVerify()) {
    die('Akses ditolak. Halaman ini hanya untuk Assessor dan Administrator.');
}

$msg = ''; $msg_type = '';
$user_id = $_SESSION['user_id'] ?? null;

// ============ HANDLE POST ACTIONS ============
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    try {
        if ($action === 'verify' || $action === 'return') {
            $result_id = (int)$_POST['result_id'];
            $comment   = trim($_POST['comment'] ?? '');

            if ($action === 'return' && empty($comment)) {
                throw new Exception('Komentar wajib diisi saat me-return jawaban.');
            }

            // Ambil status lama untuk history
            $stmt = $pdo->prepare(
                "SELECT verification_status FROM assessment_results WHERE id = ?"
            );
            $stmt->execute([$result_id]);
            $old = $stmt->fetch();

            if (!$old) throw new Exception('Jawaban tidak ditemukan.');

            $new_status = $action === 'verify' ? 'verified' : 'returned';

            // Update
            $stmt = $pdo->prepare(
                "UPDATE assessment_results
                 SET verification_status = ?,
                     verified_by = ?,
                     verified_at = CURRENT_TIMESTAMP,
                     assessor_comment = ?
                 WHERE id = ?"
            );
            $stmt->execute([$new_status, $user_id, $comment ?: null, $result_id]);

            // History
            $stmt = $pdo->prepare(
                "INSERT INTO verification_history
                 (result_id, old_status, new_status, action_by, comment)
                 VALUES (?, ?, ?, ?, ?)"
            );
            $stmt->execute([$result_id, $old['verification_status'], $new_status, $user_id, $comment ?: null]);

            logActivity('VERIFY', "Jawaban #{$result_id} → {$new_status}");
            $msg = $action === 'verify'
                ? '✅ Jawaban berhasil <strong>diverifikasi</strong>.'
                : '↩️ Jawaban <strong>dikembalikan</strong> ke user untuk revisi.';
            $msg_type = 'success';
        }

        elseif ($action === 'bulk_verify') {
            $ids = $_POST['result_ids'] ?? [];
            if (!is_array($ids) || empty($ids)) {
                throw new Exception('Pilih minimal 1 jawaban untuk verifikasi bulk.');
            }

            $pdo->beginTransaction();
            $count = 0;
            foreach ($ids as $id) {
                $id = (int)$id;

                $stmt = $pdo->prepare(
                    "SELECT verification_status FROM assessment_results
                     WHERE id = ? AND verification_status = 'submitted'"
                );
                $stmt->execute([$id]);
                if (!$stmt->fetch()) continue;

                $stmt = $pdo->prepare(
                    "UPDATE assessment_results
                     SET verification_status = 'verified',
                         verified_by = ?,
                         verified_at = CURRENT_TIMESTAMP
                     WHERE id = ?"
                );
                $stmt->execute([$user_id, $id]);

                $stmt = $pdo->prepare(
                    "INSERT INTO verification_history
                     (result_id, old_status, new_status, action_by, comment)
                     VALUES (?, 'submitted', 'verified', ?, 'Bulk verify')"
                );
                $stmt->execute([$id, $user_id]);
                $count++;
            }
            $pdo->commit();
            logActivity('BULK_VERIFY', "Bulk verify {$count} jawaban");
            $msg = "✅ <strong>{$count}</strong> jawaban berhasil diverifikasi sekaligus.";
            $msg_type = 'success';
        }

    } catch (Exception $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        $msg = 'Gagal: ' . $e->getMessage();
        $msg_type = 'danger';
    }
}

// ============ FILTERS ============
$session_id  = isset($_GET['session']) ? (int)$_GET['session'] : 0;
$element_num = isset($_GET['element']) ? (int)$_GET['element'] : 0;
$status      = $_GET['status'] ?? 'submitted'; // default: queue yang pending
$filler      = trim($_GET['filler'] ?? '');

// Sessions
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

$elements = $pdo->query(
    "SELECT element_number, element_name FROM psaims_elements
     WHERE is_active = TRUE ORDER BY element_number"
)->fetchAll();

// ============ QUERY ============
$items = [];
$stats = ['submitted' => 0, 'verified' => 0, 'returned' => 0, 'draft' => 0];

if ($session_id) {
    $sql = "SELECT * FROM v_verification_queue WHERE session_id = ?";
    $params = [$session_id];

    if ($element_num > 0) {
        $sql .= " AND element_number = ?";
        $params[] = $element_num;
    }
    if ($status !== 'all') {
        $sql .= " AND verification_status = ?";
        $params[] = $status;
    }
    if ($filler !== '') {
        $sql .= " AND (LOWER(filled_by_username) LIKE LOWER(?)
                   OR LOWER(filled_by_name) LIKE LOWER(?))";
        $params[] = "%{$filler}%";
        $params[] = "%{$filler}%";
    }

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $items = $stmt->fetchAll();

    // Stats
    $stmt = $pdo->prepare(
        "SELECT verification_status, COUNT(*) AS cnt
         FROM assessment_results
         WHERE session_id = ?
         GROUP BY verification_status"
    );
    $stmt->execute([$session_id]);
    while ($row = $stmt->fetch()) {
        $stats[$row['verification_status']] = (int)$row['cnt'];
    }
}

// Status config
$status_config = [
    'draft'     => ['secondary', 'Draft',       'edit',         'Masih bisa diedit user'],
    'submitted' => ['warning',   'Menunggu',    'hourglass',    'Menunggu verifikasi'],
    'verified'  => ['success',   'Terverifikasi','check-double','Sudah di-approve'],
    'returned'  => ['danger',    'Dikembalikan','undo',         'Butuh revisi user'],
];

$page_title = 'Verifikasi Assessment';
require_once __DIR__ . '/../includes/header.php';
require_once __DIR__ . '/../includes/sidebar.php';
?>

<div class="content-wrapper">
    <section class="content-header">
        <div class="container-fluid">
            <div class="row align-items-center">
                <div class="col-sm-8">
                    <h1><i class="fas fa-clipboard-check text-warning"></i> Verifikasi Assessment</h1>
                    <small class="text-muted">Inbox review jawaban user</small>
                </div>
                <div class="col-sm-4 text-right">
                    <span class="badge badge-warning" style="font-size:14px; padding:8px 14px;">
                        <i class="fas fa-inbox"></i>
                        <?= $stats['submitted'] ?> menunggu
                    </span>
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

            <!-- ============ STATS ============ -->
            <div class="row">
                <div class="col-md-3 col-sm-6">
                    <div class="small-box bg-warning">
                        <div class="inner">
                            <h3><?= $stats['submitted'] ?></h3>
                            <p>Menunggu Verifikasi</p>
                        </div>
                        <div class="icon"><i class="fas fa-hourglass-half"></i></div>
                    </div>
                </div>
                <div class="col-md-3 col-sm-6">
                    <div class="small-box bg-success">
                        <div class="inner">
                            <h3><?= $stats['verified'] ?></h3>
                            <p>Terverifikasi</p>
                        </div>
                        <div class="icon"><i class="fas fa-check-double"></i></div>
                    </div>
                </div>
                <div class="col-md-3 col-sm-6">
                    <div class="small-box bg-danger">
                        <div class="inner">
                            <h3><?= $stats['returned'] ?></h3>
                            <p>Dikembalikan</p>
                        </div>
                        <div class="icon"><i class="fas fa-undo"></i></div>
                    </div>
                </div>
                <div class="col-md-3 col-sm-6">
                    <div class="small-box" style="background:#E9ECEF;">
                        <div class="inner">
                            <h3 style="color:#495057;"><?= $stats['draft'] ?></h3>
                            <p style="color:#495057;">Draft</p>
                        </div>
                        <div class="icon"><i class="fas fa-edit" style="color:#495057;"></i></div>
                    </div>
                </div>
            </div>

            <!-- ============ FILTER ============ -->
            <div class="card">
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
                                <option value="all"       <?= $status === 'all'       ? 'selected' : '' ?>>Semua</option>
                                <option value="submitted" <?= $status === 'submitted' ? 'selected' : '' ?>>Menunggu</option>
                                <option value="verified"  <?= $status === 'verified'  ? 'selected' : '' ?>>Terverifikasi</option>
                                <option value="returned"  <?= $status === 'returned'  ? 'selected' : '' ?>>Dikembalikan</option>
                                <option value="draft"     <?= $status === 'draft'     ? 'selected' : '' ?>>Draft</option>
                            </select>
                        </div>
                        <div class="form-group mr-3 mb-2">
                            <label class="mr-1" style="font-size:12px;">Diisi oleh:</label>
                            <input type="text" name="filler" value="<?= e($filler) ?>"
                                   placeholder="Username/Nama"
                                   class="form-control form-control-sm" style="width:180px;">
                        </div>
                        <button type="submit" class="btn btn-primary btn-sm mb-2">
                            <i class="fas fa-search"></i> Terapkan
                        </button>
                        <a href="<?= BASE_URL ?>pages/verification.php" class="btn btn-outline-secondary btn-sm mb-2 ml-1">Reset</a>
                    </form>
                </div>
            </div>

            <!-- ============ RESULTS TABLE ============ -->
            <form method="POST" id="formBulk">
                <input type="hidden" name="action" value="bulk_verify">

                <div class="card">
                    <div class="card-header">
                        <h5 class="card-title mb-0">
                            <i class="fas fa-list"></i> Queue Verifikasi
                            <span class="badge badge-secondary ml-2"><?= count($items) ?></span>
                        </h5>
                        <div class="card-tools">
                            <?php if ($status === 'submitted' && !empty($items)): ?>
                                <button type="button" class="btn btn-sm btn-success" id="btn-bulk-verify"
                                        onclick="if(confirm('Verifikasi semua jawaban yang dipilih?')) $('#formBulk').submit();">
                                    <i class="fas fa-check-double"></i>
                                    Bulk Verify (<span id="selected-count">0</span>)
                                </button>
                            <?php endif; ?>
                        </div>
                    </div>

                    <div class="card-body p-0">
                        <?php if (empty($items)): ?>
                            <div class="text-center p-5 text-muted">
                                <i class="fas fa-inbox fa-3x mb-2" style="opacity:0.3;"></i>
                                <p class="mb-0">
                                    <?php if ($status === 'submitted'): ?>
                                        🎉 Tidak ada jawaban menunggu verifikasi. Inbox zero!
                                    <?php else: ?>
                                        Tidak ada data sesuai filter.
                                    <?php endif; ?>
                                </p>
                            </div>
                        <?php else: ?>
                            <table class="table table-hover mb-0" style="font-size:12px;">
                                <thead class="bg-light">
                                    <tr>
                                        <?php if ($status === 'submitted'): ?>
                                            <th style="width:40px;">
                                                <input type="checkbox" id="check-all">
                                            </th>
                                        <?php endif; ?>
                                        <th style="width:110px;">Status</th>
                                        <th style="width:70px;">Skor</th>
                                        <th>Elemen &amp; Persyaratan</th>
                                        <th style="width:160px;">Diisi oleh</th>
                                        <th style="width:130px;">Submit</th>
                                        <th style="width:100px;">Aksi</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($items as $it):
                                        $cfg = $status_config[$it['verification_status']] ?? $status_config['draft'];
                                        [$color, $label, $icon, $desc] = $cfg;
                                        $ref = '';
                                        if (preg_match('/\[Ref\s+([0-9.a-z]+)\]/i', $it['criteria'], $m)) {
                                            $ref = $m[1];
                                        }
                                    ?>
                                        <tr>
                                            <?php if ($status === 'submitted'): ?>
                                                <td class="text-center">
                                                    <input type="checkbox" name="result_ids[]"
                                                           value="<?= $it['result_id'] ?>" class="chk-item">
                                                </td>
                                            <?php endif; ?>
                                            <td>
                                                <span class="badge badge-<?= $color ?>" title="<?= e($desc) ?>">
                                                    <i class="fas fa-<?= $icon ?>"></i> <?= $label ?>
                                                </span>
                                                <?php if ($it['verified_by_name']): ?>
                                                    <br>
                                                    <small class="text-muted" style="font-size:10px;">
                                                        oleh <?= e($it['verified_by_name']) ?>
                                                    </small>
                                                <?php endif; ?>
                                            </td>
                                            <td class="text-center">
                                                <strong style="font-size:14px; color:#17a2b8;">
                                                    <?= $it['score'] ?>%
                                                </strong>
                                            </td>
                                            <td>
                                                <div style="color:#6c757d; font-size:10px;">
                                                    <i class="<?= e($it['icon']) ?> text-<?= e($it['color']) ?>"></i>
                                                    E<?= str_pad($it['element_number'], 2, '0', STR_PAD_LEFT) ?>
                                                    · <?= e(mb_strimwidth($it['element_name'], 0, 25, '…')) ?>
                                                    <?= $ref ? "· Ref {$ref}" : '' ?>
                                                </div>
                                                <div><?= e(mb_strimwidth(strip_tags($it['criteria']), 0, 150, '…')) ?></div>
                                            </td>
                                            <td>
                                                <?php if ($it['filled_by_name']): ?>
                                                    <strong style="font-size:11px;"><?= e($it['filled_by_name']) ?></strong>
                                                    <br>
                                                    <code style="font-size:10px;"><?= e($it['filled_by_username']) ?></code>
                                                    <?php if ($it['filled_as_role']): ?>
                                                        <br>
                                                        <span class="badge badge-light" style="font-size:9px;">
                                                            as <?= e($it['filled_as_role']) ?>
                                                        </span>
                                                    <?php endif; ?>
                                                <?php else: ?>
                                                    <small class="text-muted">—</small>
                                                <?php endif; ?>
                                            </td>
                                            <td style="font-size:11px;">
                                                <?php if ($it['submitted_at']): ?>
                                                    <?= date('d/m/Y', strtotime($it['submitted_at'])) ?>
                                                    <br>
                                                    <small class="text-muted"><?= date('H:i', strtotime($it['submitted_at'])) ?></small>
                                                <?php else: ?>
                                                    <small class="text-muted">Belum submit</small>
                                                <?php endif; ?>
                                            </td>
                                            <td class="text-center">
                                                <button type="button"
                                                        class="btn btn-xs btn-primary btn-review"
                                                        data-id="<?= $it['result_id'] ?>"
                                                        data-element="E<?= $it['element_number'] ?>"
                                                        data-ref="<?= e($ref ?: 'Q' . $it['question_number']) ?>"
                                                        data-criteria="<?= e(strip_tags($it['criteria'])) ?>"
                                                        data-score="<?= $it['score'] ?>"
                                                        data-evidence="<?= e($it['evidence'] ?? '') ?>"
                                                        data-gap="<?= e($it['gap_analysis'] ?? '') ?>"
                                                        data-action="<?= e($it['action_plan'] ?? '') ?>"
                                                        data-filler="<?= e($it['filled_by_name'] ?? '?') ?>"
                                                        data-status="<?= e($it['verification_status']) ?>"
                                                        data-comment="<?= e($it['assessor_comment'] ?? '') ?>">
                                                    <i class="fas fa-search-plus"></i> Review
                                                </button>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        <?php endif; ?>
                    </div>
                </div>
            </form>

        </div>
    </section>
</div>

<!-- ============ MODAL: Review ============ -->
<div class="modal fade" id="modalReview" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <form method="POST">
            <div class="modal-content">
                <div class="modal-header bg-primary">
                    <h5 class="modal-title text-white">
                        <i class="fas fa-clipboard-check"></i>
                        Review Jawaban
                        <span id="rv-ref" class="ml-2 badge badge-light"></span>
                    </h5>
                    <button type="button" class="close text-white" data-dismiss="modal">×</button>
                </div>
                <div class="modal-body">
                    <input type="hidden" name="result_id" id="rv-id">

                    <div class="alert alert-light mb-3" style="font-size:12px;">
                        <div class="row">
                            <div class="col-md-4">
                                <strong>Elemen:</strong> <span id="rv-element"></span>
                            </div>
                            <div class="col-md-4">
                                <strong>Diisi oleh:</strong> <span id="rv-filler"></span>
                            </div>
                            <div class="col-md-4 text-right">
                                <strong>Skor:</strong>
                                <span id="rv-score" style="font-size:16px; color:#17a2b8; font-weight:500;"></span>
                            </div>
                        </div>
                    </div>

                    <div class="form-group">
                        <label>Persyaratan:</label>
                        <div class="p-2 border rounded" style="background:#f8f9fa; font-size:12px;">
                            <span id="rv-criteria"></span>
                        </div>
                    </div>

                    <div class="row">
                        <div class="col-md-12">
                            <div class="form-group">
                                <label><i class="fas fa-paperclip text-secondary"></i> Evidence:</label>
                                <div class="p-2 border rounded" style="background:#fff; font-size:12px; min-height:60px; white-space:pre-wrap;">
                                    <span id="rv-evidence"></span>
                                </div>
                            </div>
                        </div>
                    </div>

                    <div class="row">
                        <div class="col-md-6">
                            <div class="form-group">
                                <label><i class="fas fa-exclamation-triangle text-warning"></i> Gap Analysis:</label>
                                <div class="p-2 border rounded" style="background:#FFF8E7; font-size:12px; min-height:60px; white-space:pre-wrap;">
                                    <span id="rv-gap"></span>
                                </div>
                            </div>
                        </div>
                        <div class="col-md-6">
                            <div class="form-group">
                                <label><i class="fas fa-tasks text-info"></i> Action Plan:</label>
                                <div class="p-2 border rounded" style="background:#E7F5FF; font-size:12px; min-height:60px; white-space:pre-wrap;">
                                    <span id="rv-action"></span>
                                </div>
                            </div>
                        </div>
                    </div>

                    <hr>

                    <div id="rv-previous-comment" style="display:none;" class="alert alert-info" style="font-size:12px;">
                        <strong>Komentar Sebelumnya:</strong>
                        <div id="rv-prev-comment-text"></div>
                    </div>

                    <div class="form-group">
                        <label>
                            <strong>Komentar Reviewer</strong>
                            <small class="text-muted">(wajib diisi saat Return)</small>
                        </label>
                        <textarea name="comment" id="rv-comment" class="form-control" rows="3"
                                  placeholder="Berikan feedback kenapa perlu revisi, atau komentar untuk approval..."></textarea>
                    </div>

                    <div class="alert alert-warning" style="font-size:12px;">
                        <i class="fas fa-info-circle"></i>
                        <strong>Panduan Review:</strong>
                        <ul class="mb-0 mt-1">
                            <li><strong>Verify</strong> — evidence jelas, gap &amp; action plan sesuai, skor wajar</li>
                            <li><strong>Return</strong> — ada yang kurang/salah, user perlu revisi (wajib komentar)</li>
                        </ul>
                    </div>
                </div>
                <div class="modal-footer justify-content-between">
                    <button type="button" class="btn btn-secondary" data-dismiss="modal">
                        Batal
                    </button>
                    <div>
                        <button type="submit" name="action" value="return"
                                class="btn btn-danger mr-2"
                                onclick="return $('#rv-comment').val().trim() !== '' || (alert('Komentar wajib diisi untuk Return!'), false);">
                            <i class="fas fa-undo"></i> Return (Minta Revisi)
                        </button>
                        <button type="submit" name="action" value="verify" class="btn btn-success">
                            <i class="fas fa-check-double"></i> Verify (Approve)
                        </button>
                    </div>
                </div>
            </div>
        </form>
    </div>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>

<script>
jQuery(function($) {
    $('#modalReview').appendTo('body');

    // Check-all functionality
    $('#check-all').on('change', function() {
        $('.chk-item').prop('checked', this.checked);
        updateSelectedCount();
    });
    $(document).on('change', '.chk-item', updateSelectedCount);
    function updateSelectedCount() {
        $('#selected-count').text($('.chk-item:checked').length);
    }

    // Review button
    $(document).on('click', '.btn-review', function() {
        const $btn = $(this);
        $('#rv-id').val($btn.attr('data-id'));
        $('#rv-ref').text($btn.attr('data-ref'));
        $('#rv-element').text($btn.attr('data-element'));
        $('#rv-filler').text($btn.attr('data-filler'));
        $('#rv-score').text($btn.attr('data-score') + '%');
        $('#rv-criteria').text($btn.attr('data-criteria'));
        $('#rv-evidence').text($btn.attr('data-evidence') || '(kosong)');
        $('#rv-gap').text($btn.attr('data-gap') || '(kosong)');
        $('#rv-action').text($btn.attr('data-action') || '(kosong)');

        const prevComment = $btn.attr('data-comment');
        const status = $btn.attr('data-status');
        if (prevComment && (status === 'returned' || status === 'verified')) {
            $('#rv-prev-comment-text').text(prevComment);
            $('#rv-previous-comment').show();
        } else {
            $('#rv-previous-comment').hide();
        }

        $('#rv-comment').val('');
        $('#modalReview').modal('show');
    });
});
</script>