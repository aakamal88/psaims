<?php
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../includes/mailer.php';
requireLogin();

if (!canAdminister()) {
    die('Akses ditolak. Halaman ini hanya untuk administrator.');
}

$msg = ''; $msg_type = '';
$current_user_id = $_SESSION['user_id'] ?? null;

// ============ HANDLE POST (Resend) ============
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    try {
        if ($action === 'resend') {
            $log_id = (int)$_POST['log_id'];
            $result = resendEmailFromLog($pdo, $log_id);
            logActivity('EMAIL_RESEND', "Resend email log #{$log_id}");

            $msg = $result
                ? "Email berhasil dikirim ulang! Cek email log untuk record baru."
                : "Gagal kirim ulang email. Cek SMTP config atau data terkait mungkin sudah dihapus.";
            $msg_type = $result ? 'success' : 'warning';
        }
    } catch (Exception $e) {
        $msg = 'Gagal: ' . $e->getMessage();
        $msg_type = 'danger';
    }
}

// ============ CHECK TABLE EXISTS ============
$has_email_log = false;
try {
    $stmt = $pdo->query(
        "SELECT 1 FROM information_schema.tables WHERE table_name = 'email_log'"
    );
    $has_email_log = (bool)$stmt->fetchColumn();
} catch (Exception $e) {}

// ============ FILTERS ============
$filter_status = $_GET['status'] ?? 'all';
$filter_type   = $_GET['type']   ?? 'all';
$filter_from   = $_GET['from']   ?? date('Y-m-d', strtotime('-30 days'));
$filter_to     = $_GET['to']     ?? date('Y-m-d');
$search        = trim($_GET['q'] ?? '');

// ============ EXPORT CSV ============
if (isset($_GET['export']) && $_GET['export'] === 'csv' && $has_email_log) {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="email_log_' . date('Ymd_His') . '.csv"');

    echo "\xEF\xBB\xBF"; // BOM
    $out = fopen('php://output', 'w');
    fputcsv($out, ['No', 'Tanggal', 'Jam', 'Type', 'Status', 'Subject',
                   'Penerima', 'Email', 'Elemen', 'Triggered By', 'Action', 'Error']);

    $sql = "SELECT * FROM v_email_log_summary WHERE sent_at::date BETWEEN ? AND ?";
    $params = [$filter_from, $filter_to];
    if ($filter_status !== 'all') { $sql .= " AND status = ?"; $params[] = $filter_status; }
    if ($filter_type   !== 'all') { $sql .= " AND email_type = ?"; $params[] = $filter_type; }

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $i = 1;
    while ($row = $stmt->fetch()) {
        fputcsv($out, [
            $i++,
            date('Y-m-d', strtotime($row['sent_at'])),
            date('H:i:s', strtotime($row['sent_at'])),
            $row['email_type'],
            $row['status'],
            $row['subject'],
            $row['recipient_full_name'] ?? $row['recipient_name'],
            $row['recipient_email'],
            $row['element_name'] ?? '',
            $row['triggered_by_name'] ?? '',
            $row['trigger_action'] ?? '',
            $row['error_message'] ?? '',
        ]);
    }
    fclose($out);
    exit;
}

// ============ FETCH LOGS ============
$logs = [];
$stats = ['total' => 0, 'sent' => 0, 'failed' => 0, 'skipped' => 0];

if ($has_email_log) {
    $sql = "SELECT * FROM v_email_log_summary
            WHERE sent_at::date BETWEEN ? AND ?";
    $params = [$filter_from, $filter_to];

    if ($filter_status !== 'all') {
        $sql .= " AND status = ?";
        $params[] = $filter_status;
    }
    if ($filter_type !== 'all') {
        $sql .= " AND email_type = ?";
        $params[] = $filter_type;
    }
    if ($search !== '') {
        $sql .= " AND (LOWER(recipient_email) LIKE LOWER(?)
                   OR LOWER(recipient_full_name) LIKE LOWER(?)
                   OR LOWER(subject) LIKE LOWER(?))";
        $params[] = "%{$search}%";
        $params[] = "%{$search}%";
        $params[] = "%{$search}%";
    }

    $sql .= " LIMIT 500";

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $logs = $stmt->fetchAll();

    // Stats overall (tanpa filter search)
    $stmt_stats = $pdo->prepare(
        "SELECT
            COUNT(*) AS total,
            COUNT(*) FILTER (WHERE status = 'sent')    AS sent,
            COUNT(*) FILTER (WHERE status = 'failed')  AS failed,
            COUNT(*) FILTER (WHERE status = 'skipped') AS skipped
         FROM email_log
         WHERE sent_at::date BETWEEN ? AND ?"
    );
    $stmt_stats->execute([$filter_from, $filter_to]);
    $stats = $stmt_stats->fetch();
}

// Status config
$status_config = [
    'sent'    => ['success',   'Terkirim',  'check-circle',    '#D4EDDA'],
    'failed'  => ['danger',    'Gagal',     'times-circle',    '#F8D7DA'],
    'skipped' => ['secondary', 'Dilewat',   'minus-circle',    '#E9ECEF'],
    'pending' => ['warning',   'Pending',   'hourglass-half',  '#FFF3CD'],
];

$type_config = [
    'verified' => ['success',   'Approval',  'check-double'],
    'returned' => ['danger',    'Revisi',    'undo'],
    'reminder' => ['info',      'Reminder',  'bell'],
    'test'     => ['secondary', 'Test',      'flask'],
    'other'    => ['light',     'Lainnya',   'envelope'],
];

$page_title = 'Email Log';
require_once __DIR__ . '/../includes/header.php';
require_once __DIR__ . '/../includes/sidebar.php';
?>

<div class="content-wrapper">
    <section class="content-header">
        <div class="container-fluid">
            <div class="row align-items-center">
                <div class="col-sm-7">
                    <h1><i class="fas fa-envelope-open-text text-primary"></i> Email Log</h1>
                    <small class="text-muted">Audit trail semua notifikasi email dari sistem</small>
                </div>
                <div class="col-sm-5 text-right">
                    <?php if ($has_email_log && !empty($logs)): ?>
                        <a href="?<?= http_build_query(array_merge($_GET, ['export' => 'csv'])) ?>"
                           class="btn btn-success btn-sm">
                            <i class="fas fa-file-csv"></i> Export CSV
                        </a>
                    <?php endif; ?>
                    <?php if (!defined('EMAIL_ENABLED') || !EMAIL_ENABLED): ?>
                        <span class="badge badge-secondary ml-2" title="EMAIL_ENABLED=false di config">
                            <i class="fas fa-power-off"></i> Email dinonaktifkan
                        </span>
                    <?php else: ?>
                        <span class="badge badge-success ml-2">
                            <i class="fas fa-check"></i> Email aktif
                        </span>
                    <?php endif; ?>
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

            <?php if (!$has_email_log): ?>
                <div class="alert alert-warning">
                    <h5><i class="fas fa-exclamation-triangle"></i> Tabel email_log belum ada</h5>
                    <p>Jalankan migration file <code>email_log_migration.sql</code> di pgAdmin dulu.</p>
                </div>
            <?php else: ?>

                <!-- ============ STATS ============ -->
                <div class="row">
                    <div class="col-md-3 col-sm-6">
                        <div class="small-box bg-info">
                            <div class="inner"><h3><?= $stats['total'] ?></h3><p>Total Email</p></div>
                            <div class="icon"><i class="fas fa-envelope"></i></div>
                        </div>
                    </div>
                    <div class="col-md-3 col-sm-6">
                        <div class="small-box bg-success">
                            <div class="inner"><h3><?= $stats['sent'] ?></h3><p>Terkirim</p></div>
                            <div class="icon"><i class="fas fa-check-circle"></i></div>
                        </div>
                    </div>
                    <div class="col-md-3 col-sm-6">
                        <div class="small-box bg-danger">
                            <div class="inner"><h3><?= $stats['failed'] ?></h3><p>Gagal</p></div>
                            <div class="icon"><i class="fas fa-times-circle"></i></div>
                        </div>
                    </div>
                    <div class="col-md-3 col-sm-6">
                        <div class="small-box bg-secondary">
                            <div class="inner"><h3><?= $stats['skipped'] ?></h3><p>Dilewat</p></div>
                            <div class="icon"><i class="fas fa-minus-circle"></i></div>
                        </div>
                    </div>
                </div>

                <!-- ============ FILTER ============ -->
                <div class="card">
                    <div class="card-body py-2">
                        <form method="GET" class="form-inline">
                            <div class="form-group mr-3 mb-2">
                                <label class="mr-1" style="font-size:12px;">Dari:</label>
                                <input type="date" name="from" value="<?= e($filter_from) ?>"
                                       class="form-control form-control-sm">
                            </div>
                            <div class="form-group mr-3 mb-2">
                                <label class="mr-1" style="font-size:12px;">Sampai:</label>
                                <input type="date" name="to" value="<?= e($filter_to) ?>"
                                       class="form-control form-control-sm">
                            </div>
                            <div class="form-group mr-3 mb-2">
                                <label class="mr-1" style="font-size:12px;">Status:</label>
                                <select name="status" class="form-control form-control-sm">
                                    <option value="all"     <?= $filter_status === 'all'     ? 'selected' : '' ?>>Semua</option>
                                    <option value="sent"    <?= $filter_status === 'sent'    ? 'selected' : '' ?>>Terkirim</option>
                                    <option value="failed"  <?= $filter_status === 'failed'  ? 'selected' : '' ?>>Gagal</option>
                                    <option value="skipped" <?= $filter_status === 'skipped' ? 'selected' : '' ?>>Dilewat</option>
                                </select>
                            </div>
                            <div class="form-group mr-3 mb-2">
                                <label class="mr-1" style="font-size:12px;">Type:</label>
                                <select name="type" class="form-control form-control-sm">
                                    <option value="all"      <?= $filter_type === 'all'      ? 'selected' : '' ?>>Semua</option>
                                    <option value="verified" <?= $filter_type === 'verified' ? 'selected' : '' ?>>Approval</option>
                                    <option value="returned" <?= $filter_type === 'returned' ? 'selected' : '' ?>>Revisi</option>
                                    <option value="reminder" <?= $filter_type === 'reminder' ? 'selected' : '' ?>>Reminder</option>
                                    <option value="test"     <?= $filter_type === 'test'     ? 'selected' : '' ?>>Test</option>
                                </select>
                            </div>
                            <div class="form-group mr-3 mb-2">
                                <label class="mr-1" style="font-size:12px;">Cari:</label>
                                <input type="text" name="q" value="<?= e($search) ?>"
                                       placeholder="Email / Nama / Subject"
                                       class="form-control form-control-sm" style="width:200px;">
                            </div>
                            <button type="submit" class="btn btn-primary btn-sm mb-2">
                                <i class="fas fa-search"></i> Filter
                            </button>
                            <a href="<?= BASE_URL ?>pages/email_log.php" class="btn btn-outline-secondary btn-sm mb-2 ml-1">Reset</a>
                        </form>
                    </div>
                </div>

                <!-- ============ LOG TABLE ============ -->
                <div class="card">
                    <div class="card-header">
                        <h5 class="card-title mb-0">
                            <i class="fas fa-list"></i> Daftar Email
                            <span class="badge badge-secondary ml-2"><?= count($logs) ?></span>
                            <?php if (count($logs) >= 500): ?>
                                <small class="text-warning ml-2">(limit 500, gunakan filter untuk narrow)</small>
                            <?php endif; ?>
                        </h5>
                    </div>
                    <div class="card-body p-0">
                        <?php if (empty($logs)): ?>
                            <div class="text-center p-4 text-muted">
                                <i class="fas fa-envelope-open fa-3x mb-2" style="opacity:0.3;"></i>
                                <p>Tidak ada email log dalam rentang tanggal/filter ini.</p>
                            </div>
                        <?php else: ?>
                            <div class="table-responsive">
                                <table class="table table-hover mb-0" style="font-size:12px;">
                                    <thead class="bg-light">
                                        <tr>
                                            <th style="width:40px;">#</th>
                                            <th style="width:140px;">Tanggal</th>
                                            <th style="width:90px;">Type</th>
                                            <th style="width:90px;">Status</th>
                                            <th>Subject / Penerima</th>
                                            <th style="width:150px;">Konteks</th>
                                            <th style="width:80px;">Aksi</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($logs as $i => $log):
                                            $s_cfg = $status_config[$log['status']] ?? $status_config['pending'];
                                            $t_cfg = $type_config[$log['email_type']] ?? $type_config['other'];
                                        ?>
                                            <tr class="<?= $log['status'] === 'failed' ? 'table-danger' : '' ?>">
                                                <td class="text-center"><?= $i + 1 ?></td>
                                                <td>
                                                    <?= date('d/m/Y', strtotime($log['sent_at'])) ?>
                                                    <br>
                                                    <small class="text-muted"><?= date('H:i:s', strtotime($log['sent_at'])) ?></small>
                                                </td>
                                                <td>
                                                    <span class="badge badge-<?= $t_cfg[0] ?>">
                                                        <i class="fas fa-<?= $t_cfg[2] ?>"></i> <?= $t_cfg[1] ?>
                                                    </span>
                                                </td>
                                                <td>
                                                    <span class="badge badge-<?= $s_cfg[0] ?>">
                                                        <i class="fas fa-<?= $s_cfg[2] ?>"></i> <?= $s_cfg[1] ?>
                                                    </span>
                                                </td>
                                                <td>
                                                    <strong><?= e(mb_strimwidth($log['subject'] ?? '', 0, 80, '…')) ?></strong>
                                                    <br>
                                                    <small>
                                                        <i class="fas fa-user" style="font-size:10px;"></i>
                                                        <?= e($log['recipient_full_name'] ?? $log['recipient_name'] ?? '?') ?>
                                                        (<?= e($log['recipient_email']) ?>)
                                                    </small>
                                                    <?php if ($log['status'] === 'failed' && $log['error_message']): ?>
                                                        <br>
                                                        <small class="text-danger">
                                                            <i class="fas fa-exclamation-triangle"></i>
                                                            <?= e(mb_strimwidth($log['error_message'], 0, 100, '…')) ?>
                                                        </small>
                                                    <?php endif; ?>
                                                </td>
                                                <td>
                                                    <?php if ($log['element_name']): ?>
                                                        <small>
                                                            E<?= str_pad($log['element_number'], 2, '0', STR_PAD_LEFT) ?>
                                                            ·
                                                            <?php if ($log['related_score']): ?>
                                                                Skor <?= $log['related_score'] ?>%
                                                            <?php endif; ?>
                                                        </small>
                                                    <?php endif; ?>
                                                    <?php if ($log['triggered_by_name']): ?>
                                                        <br>
                                                        <small class="text-muted">
                                                            oleh <?= e($log['triggered_by_name']) ?>
                                                            <?php if ($log['trigger_action']): ?>
                                                                (<?= e($log['trigger_action']) ?>)
                                                            <?php endif; ?>
                                                        </small>
                                                    <?php endif; ?>
                                                </td>
                                                <td class="text-center">
                                                    <button type="button" class="btn btn-xs btn-outline-info btn-view-detail"
                                                            data-id="<?= $log['id'] ?>"
                                                            data-sent="<?= e($log['sent_at']) ?>"
                                                            data-type="<?= e($log['email_type']) ?>"
                                                            data-status="<?= e($log['status']) ?>"
                                                            data-subject="<?= e($log['subject'] ?? '') ?>"
                                                            data-email="<?= e($log['recipient_email']) ?>"
                                                            data-name="<?= e($log['recipient_full_name'] ?? $log['recipient_name'] ?? '') ?>"
                                                            data-error="<?= e($log['error_message'] ?? '') ?>"
                                                            data-triggered="<?= e($log['triggered_by_name'] ?? '') ?>"
                                                            data-action="<?= e($log['trigger_action'] ?? '') ?>"
                                                            title="Detail">
                                                        <i class="fas fa-eye"></i>
                                                    </button>
                                                    <?php if ($log['status'] === 'failed' && $log['related_result_id']): ?>
                                                        <form method="POST" class="d-inline"
                                                              onsubmit="return confirm('Kirim ulang email ini?');">
                                                            <input type="hidden" name="action" value="resend">
                                                            <input type="hidden" name="log_id" value="<?= $log['id'] ?>">
                                                            <button type="submit" class="btn btn-xs btn-outline-warning" title="Kirim Ulang">
                                                                <i class="fas fa-redo"></i>
                                                            </button>
                                                        </form>
                                                    <?php endif; ?>
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

<!-- ============ MODAL: Detail ============ -->
<div class="modal fade" id="modalDetail" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header bg-info">
                <h5 class="modal-title text-white"><i class="fas fa-envelope"></i> Detail Email Log</h5>
                <button type="button" class="close text-white" data-dismiss="modal">×</button>
            </div>
            <div class="modal-body">
                <table class="table table-sm">
                    <tr><th style="width:140px;">ID Log</th><td id="dt-id"></td></tr>
                    <tr><th>Dikirim Pada</th><td id="dt-sent"></td></tr>
                    <tr><th>Type</th><td id="dt-type"></td></tr>
                    <tr><th>Status</th><td id="dt-status"></td></tr>
                    <tr><th>Subject</th><td id="dt-subject"></td></tr>
                    <tr><th>Penerima</th><td id="dt-recipient"></td></tr>
                    <tr><th>Triggered By</th><td id="dt-triggered"></td></tr>
                    <tr><th>Action</th><td id="dt-action"></td></tr>
                    <tr id="dt-error-row" style="display:none;"><th class="text-danger">Error</th><td id="dt-error" class="text-danger"></td></tr>
                </table>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-dismiss="modal">Tutup</button>
            </div>
        </div>
    </div>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>

<script>
jQuery(function($) {
    $('#modalDetail').appendTo('body');

    $(document).on('click', '.btn-view-detail', function() {
        const $btn = $(this);
        $('#dt-id').text('#' + $btn.attr('data-id'));
        $('#dt-sent').text($btn.attr('data-sent'));
        $('#dt-type').text($btn.attr('data-type'));
        $('#dt-status').text($btn.attr('data-status'));
        $('#dt-subject').text($btn.attr('data-subject'));
        $('#dt-recipient').text($btn.attr('data-name') + ' <' + $btn.attr('data-email') + '>');
        $('#dt-triggered').text($btn.attr('data-triggered') || '-');
        $('#dt-action').text($btn.attr('data-action') || '-');

        const err = $btn.attr('data-error');
        if (err) {
            $('#dt-error').text(err);
            $('#dt-error-row').show();
        } else {
            $('#dt-error-row').hide();
        }

        $('#modalDetail').modal('show');
    });
});
</script>