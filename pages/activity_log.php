<?php
require_once __DIR__ . '/../config/config.php';
requireLogin();

if (!canAdminister()) {
    die('Akses ditolak. Halaman ini hanya untuk administrator.');
}

$current_user_id = $_SESSION['user_id'] ?? null;

// ============ CHECK TABLE ============
$has_table = false;
try {
    $has_table = (bool)$pdo->query(
        "SELECT 1 FROM information_schema.tables WHERE table_name = 'activity_log'"
    )->fetchColumn();
} catch (Exception $e) {}

// ============ FILTERS ============
$filter_user   = isset($_GET['user']) ? (int)$_GET['user'] : 0;
$filter_action = $_GET['action'] ?? 'all';
$filter_from   = $_GET['from']    ?? date('Y-m-d', strtotime('-30 days'));
$filter_to     = $_GET['to']      ?? date('Y-m-d');
$search        = trim($_GET['q']  ?? '');

// ============ EXPORT CSV ============
if (isset($_GET['export']) && $_GET['export'] === 'csv' && $has_table) {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="activity_log_' . date('Ymd_His') . '.csv"');
    echo "\xEF\xBB\xBF"; // BOM untuk Excel UTF-8
    $out = fopen('php://output', 'w');
    fputcsv($out, ['No', 'Tanggal', 'Jam', 'User', 'Username', 'Action', 'Description', 'IP Address']);

    $sql = "SELECT al.*, u.username, u.full_name
            FROM activity_log al
            LEFT JOIN users u ON u.id = al.user_id
            WHERE al.created_at::date BETWEEN ? AND ?";
    $params = [$filter_from, $filter_to];
    if ($filter_user > 0)        { $sql .= " AND al.user_id = ?";       $params[] = $filter_user; }
    if ($filter_action !== 'all'){ $sql .= " AND al.action = ?";        $params[] = $filter_action; }
    if ($search !== '')          { $sql .= " AND LOWER(al.description) LIKE LOWER(?)"; $params[] = "%{$search}%"; }
    $sql .= " ORDER BY al.created_at DESC";

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $i = 1;
    while ($row = $stmt->fetch()) {
        fputcsv($out, [
            $i++,
            date('Y-m-d', strtotime($row['created_at'])),
            date('H:i:s', strtotime($row['created_at'])),
            $row['full_name'] ?? '?',
            $row['username']  ?? '?',
            $row['action'],
            $row['description'],
            $row['ip_address'] ?? '',
        ]);
    }
    fclose($out);
    exit;
}

// ============ FETCH DATA ============
$logs  = [];
$stats = ['total' => 0, 'today' => 0, 'unique_users' => 0];

if ($has_table) {
    $sql = "SELECT al.*, u.username, u.full_name
            FROM activity_log al
            LEFT JOIN users u ON u.id = al.user_id
            WHERE al.created_at::date BETWEEN ? AND ?";
    $params = [$filter_from, $filter_to];

    if ($filter_user > 0) {
        $sql .= " AND al.user_id = ?";
        $params[] = $filter_user;
    }
    if ($filter_action !== 'all') {
        $sql .= " AND al.action = ?";
        $params[] = $filter_action;
    }
    if ($search !== '') {
        $sql .= " AND LOWER(al.description) LIKE LOWER(?)";
        $params[] = "%{$search}%";
    }
    $sql .= " ORDER BY al.created_at DESC LIMIT 500";

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $logs = $stmt->fetchAll();

    // Stats
    $stmt_stats = $pdo->prepare(
        "SELECT
            COUNT(*) AS total,
            COUNT(*) FILTER (WHERE created_at::date = CURRENT_DATE) AS today,
            COUNT(DISTINCT user_id) AS unique_users
         FROM activity_log
         WHERE created_at::date BETWEEN ? AND ?"
    );
    $stmt_stats->execute([$filter_from, $filter_to]);
    $stats = $stmt_stats->fetch();
}

// ============ DROPDOWNS ============
$users_list = [];
$action_types = [];
if ($has_table) {
    $users_list = $pdo->query(
        "SELECT u.id, u.username, u.full_name
         FROM users u
         WHERE EXISTS (SELECT 1 FROM activity_log WHERE user_id = u.id)
         ORDER BY u.full_name"
    )->fetchAll();

    $action_types = $pdo->query(
        "SELECT DISTINCT action FROM activity_log
         WHERE action IS NOT NULL ORDER BY action"
    )->fetchAll();
}

// ============ ACTION CONFIG ============
$action_config = [
    'LOGIN'              => ['success',   'Login',           'sign-in-alt'],
    'LOGOUT'             => ['secondary', 'Logout',          'sign-out-alt'],
    'LOGIN_FAILED'       => ['danger',    'Login Gagal',     'times-circle'],
    'CREATE'             => ['primary',   'Tambah',          'plus'],
    'UPDATE'             => ['info',      'Update',          'edit'],
    'DELETE'             => ['danger',    'Hapus',           'trash'],
    'ASSESSMENT_SAVE'    => ['primary',   'Save Assessment', 'save'],
    'ASSESSMENT_SUBMIT'  => ['warning',   'Submit',          'paper-plane'],
    'VERIFY'             => ['success',   'Verify',          'check-circle'],
    'RETURN'             => ['danger',    'Return',          'undo'],
    'BULK_VERIFY'        => ['success',   'Bulk Verify',     'check-double'],
    'EVIDENCE_UPLOAD'    => ['info',      'Upload Evidence', 'paperclip'],
    'EVIDENCE_DELETE'    => ['danger',    'Delete Evidence', 'times'],
    'EVIDENCE_DOWNLOAD'  => ['secondary', 'Download',        'download'],
    'EVIDENCE_SETTINGS'  => ['warning',   'Settings',        'cog'],
    'NOTIF_CLEANUP'      => ['secondary', 'Cleanup Notif',   'broom'],
    'EMAIL_RESEND'       => ['warning',   'Resend Email',    'envelope'],
    'ROLE_CHANGE'        => ['warning',   'Role Change',     'user-tag'],
    'PASSWORD_RESET'     => ['warning',   'Reset Password',  'key'],
];
$default_cfg = ['light', 'Other', 'circle'];

$page_title = 'Activity Log';
require_once __DIR__ . '/../includes/header.php';
require_once __DIR__ . '/../includes/sidebar.php';
?>

<div class="content-wrapper">
    <section class="content-header">
        <div class="container-fluid">
            <div class="row align-items-center">
                <div class="col-sm-8">
                    <h1><i class="fas fa-history text-primary"></i> Activity Log</h1>
                    <small class="text-muted">Audit trail semua aktivitas pengguna di sistem</small>
                </div>
                <div class="col-sm-4 text-right">
                    <?php if ($has_table && !empty($logs)): ?>
                        <a href="?<?= http_build_query(array_merge($_GET, ['export' => 'csv'])) ?>"
                           class="btn btn-success btn-sm">
                            <i class="fas fa-file-csv"></i> Export CSV
                        </a>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </section>

    <section class="content">
        <div class="container-fluid">

            <?php if (!$has_table): ?>
                <div class="alert alert-warning">
                    <h5><i class="fas fa-exclamation-triangle"></i> Tabel <code>activity_log</code> belum ada</h5>
                    <p>Tabel ini biasanya sudah dibuat di schema awal. Jalankan SQL berikut di pgAdmin:</p>
                    <pre class="mt-2 p-2 bg-light" style="font-size:11px;">CREATE TABLE IF NOT EXISTS activity_log (
    id SERIAL PRIMARY KEY,
    user_id INTEGER REFERENCES users(id) ON DELETE SET NULL,
    action VARCHAR(100) NOT NULL,
    description TEXT,
    ip_address VARCHAR(45),
    user_agent TEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);
CREATE INDEX idx_activity_user ON activity_log(user_id);
CREATE INDEX idx_activity_created ON activity_log(created_at DESC);
CREATE INDEX idx_activity_action ON activity_log(action);
GRANT ALL PRIVILEGES ON activity_log TO adminpsaims;
GRANT ALL PRIVILEGES ON SEQUENCE activity_log_id_seq TO adminpsaims;</pre>
                </div>
            <?php else: ?>

                <!-- ============ STATS ============ -->
                <div class="row">
                    <div class="col-md-4 col-sm-6">
                        <div class="small-box bg-info">
                            <div class="inner"><h3><?= number_format($stats['total']) ?></h3><p>Total Aktivitas</p></div>
                            <div class="icon"><i class="fas fa-list-alt"></i></div>
                        </div>
                    </div>
                    <div class="col-md-4 col-sm-6">
                        <div class="small-box bg-success">
                            <div class="inner"><h3><?= number_format($stats['today']) ?></h3><p>Hari Ini</p></div>
                            <div class="icon"><i class="fas fa-clock"></i></div>
                        </div>
                    </div>
                    <div class="col-md-4 col-sm-6">
                        <div class="small-box bg-warning">
                            <div class="inner"><h3><?= number_format($stats['unique_users']) ?></h3><p>User Aktif</p></div>
                            <div class="icon"><i class="fas fa-users"></i></div>
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
                                <label class="mr-1" style="font-size:12px;">User:</label>
                                <select name="user" class="form-control form-control-sm">
                                    <option value="0">Semua</option>
                                    <?php foreach ($users_list as $u): ?>
                                        <option value="<?= $u['id'] ?>"
                                                <?= $u['id'] == $filter_user ? 'selected' : '' ?>>
                                            <?= e($u['full_name']) ?> (<?= e($u['username']) ?>)
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="form-group mr-3 mb-2">
                                <label class="mr-1" style="font-size:12px;">Action:</label>
                                <select name="action" class="form-control form-control-sm">
                                    <option value="all">Semua</option>
                                    <?php foreach ($action_types as $a):
                                        $cfg = $action_config[$a['action']] ?? $default_cfg;
                                    ?>
                                        <option value="<?= e($a['action']) ?>"
                                                <?= $filter_action === $a['action'] ? 'selected' : '' ?>>
                                            <?= e($cfg[1]) ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="form-group mr-3 mb-2">
                                <input type="text" name="q" value="<?= e($search) ?>"
                                       placeholder="Cari description..."
                                       class="form-control form-control-sm" style="width:200px;">
                            </div>
                            <button type="submit" class="btn btn-primary btn-sm mb-2">
                                <i class="fas fa-search"></i> Filter
                            </button>
                            <a href="<?= BASE_URL ?>pages/activity_log.php"
                               class="btn btn-outline-secondary btn-sm mb-2 ml-1">Reset</a>
                        </form>
                    </div>
                </div>

                <!-- ============ TABLE ============ -->
                <div class="card">
                    <div class="card-header">
                        <h5 class="card-title mb-0">
                            <i class="fas fa-list"></i> Daftar Aktivitas
                            <span class="badge badge-secondary ml-2"><?= count($logs) ?></span>
                            <?php if (count($logs) >= 500): ?>
                                <small class="text-warning ml-2">(limit 500, gunakan filter untuk narrow)</small>
                            <?php endif; ?>
                        </h5>
                    </div>
                    <div class="card-body p-0">
                        <?php if (empty($logs)): ?>
                            <div class="text-center p-4 text-muted">
                                <i class="fas fa-history fa-3x mb-2" style="opacity:0.3;"></i>
                                <p>Tidak ada aktivitas dalam rentang tanggal/filter ini.</p>
                            </div>
                        <?php else: ?>
                            <div class="table-responsive">
                                <table class="table table-hover mb-0" style="font-size:12px;">
                                    <thead class="bg-light">
                                        <tr>
                                            <th style="width:40px;">#</th>
                                            <th style="width:130px;">Waktu</th>
                                            <th style="width:200px;">User</th>
                                            <th style="width:140px;">Action</th>
                                            <th>Description</th>
                                            <th style="width:120px;">IP Address</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($logs as $i => $log):
                                            $cfg = $action_config[$log['action']] ?? $default_cfg;
                                        ?>
                                            <tr>
                                                <td class="text-center"><?= $i + 1 ?></td>
                                                <td>
                                                    <?= date('d/m/Y', strtotime($log['created_at'])) ?>
                                                    <br>
                                                    <small class="text-muted"><?= date('H:i:s', strtotime($log['created_at'])) ?></small>
                                                </td>
                                                <td>
                                                    <?php if ($log['user_id']): ?>
                                                        <strong><?= e($log['full_name'] ?? '?') ?></strong>
                                                        <br>
                                                        <small class="text-muted"><?= e($log['username'] ?? '?') ?></small>
                                                    <?php else: ?>
                                                        <span class="text-muted"><i>System</i></span>
                                                    <?php endif; ?>
                                                </td>
                                                <td>
                                                    <span class="badge badge-<?= $cfg[0] ?>">
                                                        <i class="fas fa-<?= $cfg[2] ?>"></i> <?= $cfg[1] ?>
                                                    </span>
                                                    <br>
                                                    <small class="text-muted"><?= e($log['action']) ?></small>
                                                </td>
                                                <td>
                                                    <?php if ($log['description']): ?>
                                                        <?= e($log['description']) ?>
                                                    <?php else: ?>
                                                        <span class="text-muted">-</span>
                                                    <?php endif; ?>
                                                </td>
                                                <td>
                                                    <small class="text-muted">
                                                        <?= e($log['ip_address'] ?? '-') ?>
                                                    </small>
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

<?php require_once __DIR__ . '/../includes/footer.php'; ?>