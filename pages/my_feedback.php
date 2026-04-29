<?php
require_once __DIR__ . '/../config/config.php';
requireLogin();

// Semua role bisa akses (admin & assessor juga bisa lihat, tapi utamanya buat user)
$user_id = $_SESSION['user_id'] ?? null;

// ============ FILTERS ============
$filter_status = $_GET['status'] ?? 'all'; // all, returned, verified
$session_id    = isset($_GET['session']) ? (int)$_GET['session'] : 0;

// Sessions dropdown
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

// ============ QUERY FEEDBACK ============
$feedbacks = [];
$stats = ['returned' => 0, 'verified' => 0, 'submitted' => 0];

if ($session_id) {
    $sql = "SELECT
                ar.id AS result_id,
                ar.score,
                ar.verification_status,
                ar.assessor_comment,
                ar.submitted_at,
                ar.verified_at,
                q.id AS question_id,
                q.question_number,
                q.criteria,
                e.id AS element_id,
                e.element_number,
                e.element_name,
                e.icon,
                e.color,
                u_verifier.full_name AS verified_by_name,
                u_verifier.username AS verified_by_username
            FROM assessment_results ar
            JOIN assessment_questions q ON q.id = ar.question_id
            JOIN psaims_elements e ON e.id = q.element_id
            LEFT JOIN users u_verifier ON u_verifier.id = ar.verified_by
            WHERE ar.session_id = ?
              AND ar.user_id = ?
              AND ar.verification_status IN ('returned', 'verified', 'submitted')";

    $params = [$session_id, $user_id];

    if ($filter_status === 'returned') {
        $sql .= " AND ar.verification_status = 'returned'";
    } elseif ($filter_status === 'verified') {
        $sql .= " AND ar.verification_status = 'verified'";
    } elseif ($filter_status === 'submitted') {
        $sql .= " AND ar.verification_status = 'submitted'";
    }

    $sql .= " ORDER BY
              CASE ar.verification_status
                  WHEN 'returned'  THEN 1
                  WHEN 'submitted' THEN 2
                  WHEN 'verified'  THEN 3
              END,
              ar.verified_at DESC NULLS LAST,
              ar.submitted_at DESC NULLS LAST";

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $feedbacks = $stmt->fetchAll();

    // Stats
    $stmt_stats = $pdo->prepare(
        "SELECT verification_status, COUNT(*) AS cnt
         FROM assessment_results
         WHERE session_id = ? AND user_id = ?
           AND verification_status IN ('returned', 'verified', 'submitted')
         GROUP BY verification_status"
    );
    $stmt_stats->execute([$session_id, $user_id]);
    while ($row = $stmt_stats->fetch()) {
        $stats[$row['verification_status']] = (int)$row['cnt'];
    }
}

$total_feedback = $stats['returned'] + $stats['verified'] + $stats['submitted'];

// Status config
$status_config = [
    'returned'  => ['danger',  'Perlu Revisi',   'undo',          '#F8D7DA', '#721C24'],
    'submitted' => ['warning', 'Menunggu',       'hourglass-half','#FFF3CD', '#856404'],
    'verified'  => ['success', 'Disetujui',      'check-circle',  '#D4EDDA', '#155724'],
];

$page_title = 'Inbox Feedback';
require_once __DIR__ . '/../includes/header.php';
require_once __DIR__ . '/../includes/sidebar.php';
?>

<div class="content-wrapper">
    <section class="content-header">
        <div class="container-fluid">
            <div class="row align-items-center">
                <div class="col-sm-8">
                    <h1><i class="fas fa-inbox text-primary"></i> Inbox Feedback</h1>
                    <small class="text-muted">Feedback dari Assessor untuk jawaban assessment Anda</small>
                </div>
                <div class="col-sm-4 text-right">
                    <?php if ($stats['returned'] > 0): ?>
                        <span class="badge badge-danger" style="font-size:14px; padding:8px 14px;">
                            <i class="fas fa-undo"></i>
                            <?= $stats['returned'] ?> perlu revisi
                        </span>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </section>

    <section class="content">
        <div class="container-fluid">

            <!-- ============ STATS ============ -->
            <div class="row">
                <div class="col-md-3 col-sm-6">
                    <div class="small-box bg-danger">
                        <div class="inner">
                            <h3><?= $stats['returned'] ?></h3>
                            <p>Perlu Revisi</p>
                        </div>
                        <div class="icon"><i class="fas fa-undo"></i></div>
                        <a href="?status=returned&session=<?= $session_id ?>" class="small-box-footer">
                            Lihat &amp; Revisi <i class="fas fa-arrow-circle-right"></i>
                        </a>
                    </div>
                </div>
                <div class="col-md-3 col-sm-6">
                    <div class="small-box bg-warning">
                        <div class="inner">
                            <h3><?= $stats['submitted'] ?></h3>
                            <p>Menunggu Review</p>
                        </div>
                        <div class="icon"><i class="fas fa-hourglass-half"></i></div>
                        <a href="?status=submitted&session=<?= $session_id ?>" class="small-box-footer">
                            Lihat detail <i class="fas fa-arrow-circle-right"></i>
                        </a>
                    </div>
                </div>
                <div class="col-md-3 col-sm-6">
                    <div class="small-box bg-success">
                        <div class="inner">
                            <h3><?= $stats['verified'] ?></h3>
                            <p>Sudah Disetujui</p>
                        </div>
                        <div class="icon"><i class="fas fa-check-circle"></i></div>
                        <a href="?status=verified&session=<?= $session_id ?>" class="small-box-footer">
                            Lihat <i class="fas fa-arrow-circle-right"></i>
                        </a>
                    </div>
                </div>
                <div class="col-md-3 col-sm-6">
                    <div class="small-box bg-info">
                        <div class="inner">
                            <h3><?= $total_feedback ?></h3>
                            <p>Total Feedback</p>
                        </div>
                        <div class="icon"><i class="fas fa-comments"></i></div>
                        <a href="?status=all&session=<?= $session_id ?>" class="small-box-footer">
                            Semua <i class="fas fa-arrow-circle-right"></i>
                        </a>
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
                            <label class="mr-1" style="font-size:12px;">Status:</label>
                            <select name="status" class="form-control form-control-sm">
                                <option value="all"       <?= $filter_status === 'all'       ? 'selected' : '' ?>>Semua Feedback</option>
                                <option value="returned"  <?= $filter_status === 'returned'  ? 'selected' : '' ?>>Perlu Revisi</option>
                                <option value="submitted" <?= $filter_status === 'submitted' ? 'selected' : '' ?>>Menunggu Review</option>
                                <option value="verified"  <?= $filter_status === 'verified'  ? 'selected' : '' ?>>Sudah Disetujui</option>
                            </select>
                        </div>
                        <button type="submit" class="btn btn-primary btn-sm mb-2">
                            <i class="fas fa-filter"></i> Terapkan
                        </button>
                        <a href="<?= BASE_URL ?>pages/my_feedback.php" class="btn btn-outline-secondary btn-sm mb-2 ml-1">Reset</a>
                    </form>
                </div>
            </div>

            <!-- ============ FEEDBACK LIST ============ -->
            <?php if (empty($feedbacks)): ?>
                <div class="card">
                    <div class="card-body text-center p-5">
                        <?php if ($total_feedback == 0): ?>
                            <i class="fas fa-inbox fa-3x text-muted mb-3" style="opacity:0.3;"></i>
                            <h5 class="text-muted">Belum ada feedback</h5>
                            <p class="text-muted mb-3">
                                Anda belum pernah submit jawaban untuk diverifikasi pada periode ini.
                                Setelah submit, feedback dari assessor akan muncul di sini.
                            </p>
                            <a href="<?= BASE_URL ?>pages/assessment.php?element=1" class="btn btn-primary">
                                <i class="fas fa-edit"></i> Mulai Isi Assessment
                            </a>
                        <?php else: ?>
                            <i class="fas fa-filter fa-3x text-muted mb-3" style="opacity:0.3;"></i>
                            <p class="text-muted">Tidak ada feedback sesuai filter.</p>
                        <?php endif; ?>
                    </div>
                </div>
            <?php else: ?>
                <div class="feedback-list">
                    <?php foreach ($feedbacks as $fb):
                        $cfg = $status_config[$fb['verification_status']] ?? $status_config['submitted'];
                        [$color, $label, $icon, $bg, $fg] = $cfg;
                        $ref = '';
                        if (preg_match('/\[Ref\s+([0-9.a-z]+)\]/i', $fb['criteria'], $m)) {
                            $ref = $m[1];
                        }
                        $has_comment = !empty($fb['assessor_comment']);
                    ?>
                        <div class="card feedback-card mb-3 <?= $fb['verification_status'] === 'returned' ? 'border-danger' : '' ?>">
                            <div class="card-header py-2 d-flex justify-content-between align-items-center"
                                 style="background: <?= $bg ?>;">
                                <div>
                                    <span class="badge badge-<?= $color ?>" style="font-size:11px;">
                                        <i class="fas fa-<?= $icon ?>"></i> <?= $label ?>
                                    </span>
                                    <strong style="color: <?= $fg ?>; margin-left:8px;">
                                        <i class="<?= e($fb['icon']) ?>"></i>
                                        E<?= str_pad($fb['element_number'], 2, '0', STR_PAD_LEFT) ?>
                                        · <?= e(mb_strimwidth($fb['element_name'], 0, 30, '…')) ?>
                                        <?= $ref ? "· Ref {$ref}" : '' ?>
                                    </strong>
                                </div>
                                <div style="color: <?= $fg ?>; font-size:12px;">
                                    <?php if ($fb['verified_at']): ?>
                                        <i class="fas fa-clock"></i>
                                        <?= date('d M Y, H:i', strtotime($fb['verified_at'])) ?>
                                    <?php elseif ($fb['submitted_at']): ?>
                                        <i class="fas fa-paper-plane"></i>
                                        Submit: <?= date('d M Y, H:i', strtotime($fb['submitted_at'])) ?>
                                    <?php endif; ?>
                                </div>
                            </div>

                            <div class="card-body py-3">
                                <div class="mb-3">
                                    <strong style="font-size:12px; color:#6c757d;">Persyaratan:</strong>
                                    <div style="font-size:13px; margin-top:4px;">
                                        <?= e(mb_strimwidth(strip_tags($fb['criteria']), 0, 300, '…')) ?>
                                    </div>
                                </div>

                                <div class="row" style="font-size:12px;">
                                    <div class="col-md-4">
                                        <strong class="text-muted">Skor Anda:</strong>
                                        <span style="font-size:14px; color:#17a2b8; font-weight:500;">
                                            <?= $fb['score'] ?>%
                                        </span>
                                    </div>
                                    <?php if ($fb['verified_by_name']): ?>
                                        <div class="col-md-8">
                                            <strong class="text-muted">Direview oleh:</strong>
                                            <?= e($fb['verified_by_name']) ?>
                                        </div>
                                    <?php endif; ?>
                                </div>

                                <?php if ($has_comment): ?>
                                    <div class="mt-3 p-3"
                                         style="background: <?= $fb['verification_status'] === 'returned' ? '#FFF5F5' : '#F0FFF4' ?>;
                                                border-left: 4px solid <?= $fg ?>;
                                                border-radius: 0 4px 4px 0;">
                                        <strong style="color: <?= $fg ?>; font-size:12px;">
                                            <i class="fas fa-comment-dots"></i>
                                            Komentar Assessor:
                                        </strong>
                                        <div style="margin-top:6px; font-size:13px; color:#333; white-space:pre-wrap; line-height:1.6;">
                                            <?= e($fb['assessor_comment']) ?>
                                        </div>
                                    </div>
                                <?php elseif ($fb['verification_status'] === 'verified'): ?>
                                    <div class="mt-3 text-center text-muted" style="font-size:12px;">
                                        <i class="fas fa-check-double"></i>
                                        Disetujui tanpa komentar — jawaban sudah memenuhi kriteria
                                    </div>
                                <?php endif; ?>
                            </div>

                            <div class="card-footer py-2">
                                <?php if ($fb['verification_status'] === 'returned'): ?>
                                    <a href="<?= BASE_URL ?>pages/assessment.php?element=<?= $fb['element_number'] ?>#q<?= $fb['question_id'] ?>"
                                       class="btn btn-danger btn-sm">
                                        <i class="fas fa-edit"></i> Revisi Sekarang
                                    </a>
                                <?php elseif ($fb['verification_status'] === 'verified'): ?>
                                    <a href="<?= BASE_URL ?>pages/assessment.php?element=<?= $fb['element_number'] ?>#q<?= $fb['question_id'] ?>"
                                       class="btn btn-outline-success btn-sm">
                                        <i class="fas fa-eye"></i> Lihat Detail
                                    </a>
                                <?php else: ?>
                                    <span class="text-muted" style="font-size:12px;">
                                        <i class="fas fa-hourglass-half"></i>
                                        Sedang menunggu review dari assessor
                                    </span>
                                <?php endif; ?>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>

        </div>
    </section>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>

<style>
.feedback-card {
    transition: transform 0.15s, box-shadow 0.15s;
}
.feedback-card:hover {
    transform: translateY(-1px);
    box-shadow: 0 2px 8px rgba(0,0,0,0.08);
}
.feedback-card.border-danger {
    border-width: 1px;
    border-left-width: 4px;
}
</style>