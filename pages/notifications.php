<?php
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../includes/notifications.php';
requireLogin();

$msg = ''; $msg_type = '';
$current_user_id = $_SESSION['user_id'] ?? null;
$is_admin = canAdminister();

// ============ HANDLE ACTIONS ============
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    try {
        if ($action === 'mark_all_read') {
            $count = markAllNotificationsRead($current_user_id);
            $msg = "✓ <strong>{$count}</strong> notifikasi ditandai sudah dibaca.";
            $msg_type = 'success';
        }
        elseif ($action === 'delete_read' && $is_admin) {
            $days = (int)($_POST['days'] ?? 30);
            $stmt = $pdo->prepare(
                "DELETE FROM notifications
                 WHERE is_read = TRUE
                   AND read_at < CURRENT_TIMESTAMP - INTERVAL '{$days} days'"
            );
            $stmt->execute();
            $count = $stmt->rowCount();
            logActivity('NOTIF_CLEANUP', "Hapus {$count} notif sudah dibaca > {$days} hari");
            $msg = "✓ <strong>{$count}</strong> notifikasi lama dihapus.";
            $msg_type = 'success';
        }
    } catch (Exception $e) {
        $msg = 'Gagal: ' . $e->getMessage();
        $msg_type = 'danger';
    }
}

// ============ FILTERS ============
$filter_type   = $_GET['type']   ?? 'all';
$filter_status = $_GET['status'] ?? 'all';
$filter_user   = isset($_GET['user']) ? (int)$_GET['user'] : 0;
$show_all      = $is_admin && isset($_GET['view_all']) && $_GET['view_all'] === '1';

// ============ CHECK TABLE ============
$has_table = false;
try {
    $has_table = (bool)$pdo->query(
        "SELECT 1 FROM information_schema.tables WHERE table_name = 'notifications'"
    )->fetchColumn();
} catch (Exception $e) {}

// ============ QUERY ============
$notifs = [];
$stats  = ['total' => 0, 'unread' => 0, 'read' => 0];

if ($has_table) {
    $sql = "SELECT * FROM v_notifications_full WHERE 1=1";
    $params = [];

    // Admin bisa lihat semua kalau view_all=1, default cuma notif sendiri
    if ($is_admin && $show_all) {
        if ($filter_user > 0) {
            $sql .= " AND user_id = ?";
            $params[] = $filter_user;
        }
    } else {
        $sql .= " AND user_id = ?";
        $params[] = $current_user_id;
    }

    if ($filter_type !== 'all') {
        $sql .= " AND type = ?";
        $params[] = $filter_type;
    }
    if ($filter_status === 'unread') {
        $sql .= " AND is_read = FALSE";
    } elseif ($filter_status === 'read') {
        $sql .= " AND is_read = TRUE";
    }

    $sql .= " LIMIT 500";

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $notifs = $stmt->fetchAll();

    // Stats
    if ($is_admin && $show_all) {
        $stats_sql = "SELECT COUNT(*) AS total,
                             COUNT(*) FILTER (WHERE is_read = FALSE) AS unread,
                             COUNT(*) FILTER (WHERE is_read = TRUE)  AS read
                      FROM notifications";
        $stmt_stats = $pdo->query($stats_sql);
    } else {
        $stmt_stats = $pdo->prepare(
            "SELECT COUNT(*) AS total,
                    COUNT(*) FILTER (WHERE is_read = FALSE) AS unread,
                    COUNT(*) FILTER (WHERE is_read = TRUE)  AS read
             FROM notifications WHERE user_id = ?"
        );
        $stmt_stats->execute([$current_user_id]);
    }
    $stats = $stmt_stats->fetch();

    // Auto mark as read saat user buka halaman (hanya untuk view sendiri)
    if (!($is_admin && $show_all) && empty($_GET) && !empty($notifs)) {
        // Tidak auto mark, biar user lihat yang baru dulu
        // markAllNotificationsRead($current_user_id);
    }
}

// List users untuk admin filter
$users_list = [];
if ($is_admin && $show_all) {
    $users_list = $pdo->query(
        "SELECT id, username, full_name FROM users
         WHERE is_active = TRUE ORDER BY username"
    )->fetchAll();
}

$page_title = 'Notifikasi';
require_once __DIR__ . '/../includes/header.php';
require_once __DIR__ . '/../includes/sidebar.php';
?>

<div class="content-wrapper">
    <section class="content-header">
        <div class="container-fluid">
            <div class="row align-items-center">
                <div class="col-sm-7">
                    <h1>
                        <i class="fas fa-bell text-warning"></i>
                        <?= $show_all ? 'Semua Notifikasi Sistem' : 'Notifikasi Saya' ?>
                    </h1>
                    <small class="text-muted">
                        <?php if ($show_all): ?>
                            Monitoring notifikasi untuk semua user
                        <?php else: ?>
                            Riwayat pemberitahuan untuk akun Anda
                        <?php endif; ?>
                    </small>
                </div>
                <div class="col-sm-5 text-right">
                    <?php if ($is_admin): ?>
                        <?php if ($show_all): ?>
                            <a href="?" class="btn btn-sm btn-outline-primary">
                                <i class="fas fa-user"></i> Notif Saya
                            </a>
                        <?php else: ?>
                            <a href="?view_all=1" class="btn btn-sm btn-outline-danger">
                                <i class="fas fa-users"></i> Lihat Semua User
                            </a>
                        <?php endif; ?>
                    <?php endif; ?>
                    <?php if (!$show_all && $stats['unread'] > 0): ?>
                        <form method="POST" class="d-inline">
                            <input type="hidden" name="action" value="mark_all_read">
                            <button type="submit" class="btn btn-sm btn-outline-success">
                                <i class="fas fa-check-double"></i> Tandai Semua Dibaca
                            </button>
                        </form>
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

            <?php if (!$has_table): ?>
                <div class="alert alert-warning">
                    <h5><i class="fas fa-exclamation-triangle"></i> Tabel notifications belum ada</h5>
                    <p>Jalankan <code>notifications_migration.sql</code> di pgAdmin dulu.</p>
                </div>
            <?php else: ?>

                <!-- STATS -->
                <div class="row">
                    <div class="col-md-4 col-sm-12">
                        <div class="small-box bg-info">
                            <div class="inner"><h3><?= $stats['total'] ?></h3><p>Total Notifikasi</p></div>
                            <div class="icon"><i class="fas fa-bell"></i></div>
                        </div>
                    </div>
                    <div class="col-md-4 col-sm-12">
                        <div class="small-box bg-warning">
                            <div class="inner"><h3><?= $stats['unread'] ?></h3><p>Belum Dibaca</p></div>
                            <div class="icon"><i class="fas fa-envelope"></i></div>
                        </div>
                    </div>
                    <div class="col-md-4 col-sm-12">
                        <div class="small-box bg-success">
                            <div class="inner"><h3><?= $stats['read'] ?></h3><p>Sudah Dibaca</p></div>
                            <div class="icon"><i class="fas fa-envelope-open"></i></div>
                        </div>
                    </div>
                </div>

                <!-- FILTER -->
                <div class="card">
                    <div class="card-body py-2">
                        <form method="GET" class="form-inline">
                            <?php if ($show_all): ?>
                                <input type="hidden" name="view_all" value="1">
                            <?php endif; ?>

                            <?php if ($show_all && !empty($users_list)): ?>
                                <div class="form-group mr-3 mb-2">
                                    <label class="mr-1" style="font-size:12px;">User:</label>
                                    <select name="user" class="form-control form-control-sm">
                                        <option value="0">Semua User</option>
                                        <?php foreach ($users_list as $u): ?>
                                            <option value="<?= $u['id'] ?>" <?= $filter_user == $u['id'] ? 'selected' : '' ?>>
                                                <?= e($u['full_name']) ?> (<?= e($u['username']) ?>)
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                            <?php endif; ?>

                            <div class="form-group mr-3 mb-2">
                                <label class="mr-1" style="font-size:12px;">Type:</label>
                                <select name="type" class="form-control form-control-sm">
                                    <option value="all"            <?= $filter_type === 'all'            ? 'selected' : '' ?>>Semua</option>
                                    <option value="verified"       <?= $filter_type === 'verified'       ? 'selected' : '' ?>>Disetujui</option>
                                    <option value="returned"       <?= $filter_type === 'returned'       ? 'selected' : '' ?>>Revisi</option>
                                    <option value="submit_success" <?= $filter_type === 'submit_success' ? 'selected' : '' ?>>Submit</option>
                                    <option value="reminder"       <?= $filter_type === 'reminder'       ? 'selected' : '' ?>>Reminder</option>
                                    <option value="info"           <?= $filter_type === 'info'           ? 'selected' : '' ?>>Info</option>
                                    <option value="warning"        <?= $filter_type === 'warning'        ? 'selected' : '' ?>>Warning</option>
                                    <option value="system"         <?= $filter_type === 'system'         ? 'selected' : '' ?>>System</option>
                                </select>
                            </div>

                            <div class="form-group mr-3 mb-2">
                                <label class="mr-1" style="font-size:12px;">Status:</label>
                                <select name="status" class="form-control form-control-sm">
                                    <option value="all"    <?= $filter_status === 'all'    ? 'selected' : '' ?>>Semua</option>
                                    <option value="unread" <?= $filter_status === 'unread' ? 'selected' : '' ?>>Belum Dibaca</option>
                                    <option value="read"   <?= $filter_status === 'read'   ? 'selected' : '' ?>>Sudah Dibaca</option>
                                </select>
                            </div>

                            <button type="submit" class="btn btn-primary btn-sm mb-2">
                                <i class="fas fa-filter"></i> Filter
                            </button>
                            <a href="<?= BASE_URL ?>pages/notifications.php<?= $show_all ? '?view_all=1' : '' ?>"
                               class="btn btn-outline-secondary btn-sm mb-2 ml-1">Reset</a>
                        </form>
                    </div>
                </div>

                <!-- NOTIF LIST -->
                <div class="card">
                    <div class="card-header">
                        <h5 class="card-title mb-0">
                            <i class="fas fa-list"></i> Daftar Notifikasi
                            <span class="badge badge-secondary ml-2"><?= count($notifs) ?></span>
                            <?php if (count($notifs) >= 500): ?>
                                <small class="text-warning ml-2">(limit 500, gunakan filter)</small>
                            <?php endif; ?>
                        </h5>
                    </div>
                    <div class="card-body p-0">
                        <?php if (empty($notifs)): ?>
                            <div class="text-center p-4 text-muted">
                                <i class="fas fa-inbox fa-3x mb-2" style="opacity:0.3;"></i>
                                <p>Tidak ada notifikasi.</p>
                            </div>
                        <?php else: ?>
                            <div class="notif-list">
                                <?php foreach ($notifs as $n):
                                    $color = $n['color'] ?? 'primary';
                                    $icon  = $n['icon']  ?? 'fa-bell';
                                ?>
                                    <div class="notif-row <?= $n['is_read'] ? '' : 'unread' ?>">
                                        <div class="notif-row-icon bg-<?= e($color) ?>">
                                            <i class="fas <?= e($icon) ?>"></i>
                                        </div>
                                        <div class="notif-row-content">
                                            <div class="notif-row-header">
                                                <strong><?= e($n['title']) ?></strong>
                                                <?php if (!$n['is_read']): ?>
                                                    <span class="badge badge-danger ml-1" style="font-size:9px;">BARU</span>
                                                <?php endif; ?>
                                                <?php if ($show_all): ?>
                                                    <span class="badge badge-light ml-1" style="font-size:9px;" title="Penerima">
                                                        <i class="fas fa-user"></i>
                                                        <?= e($n['recipient_full_name'] ?? '?') ?>
                                                    </span>
                                                <?php endif; ?>
                                            </div>
                                            <?php if ($n['message']): ?>
                                                <div class="notif-row-msg">
                                                    <?= e($n['message']) ?>
                                                </div>
                                            <?php endif; ?>
                                            <div class="notif-row-meta">
                                                <small class="text-muted">
                                                    <i class="fas fa-clock"></i>
                                                    <?= date('d M Y, H:i', strtotime($n['created_at'])) ?>
                                                    <span style="opacity:0.6;">(<?= timeAgo($n['created_at']) ?>)</span>
                                                </small>
                                                <?php if ($n['created_by_name']): ?>
                                                    <small class="text-muted ml-2">
                                                        <i class="fas fa-user-edit"></i>
                                                        oleh <?= e($n['created_by_name']) ?>
                                                    </small>
                                                <?php endif; ?>
                                                <?php if ($n['is_read'] && $n['read_at']): ?>
                                                    <small class="text-success ml-2">
                                                        <i class="fas fa-eye"></i>
                                                        Dibaca <?= timeAgo($n['read_at']) ?>
                                                    </small>
                                                <?php endif; ?>
                                            </div>
                                        </div>
                                        <?php if (!empty($n['link'])): ?>
                                            <div class="notif-row-action">
                                                <a href="<?= e($n['link']) ?>" class="btn btn-xs btn-outline-primary">
                                                    <i class="fas fa-arrow-right"></i> Buka
                                                </a>
                                            </div>
                                        <?php endif; ?>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>

                <?php if ($is_admin && $show_all): ?>
                    <div class="card border-warning mt-3">
                        <div class="card-header bg-warning">
                            <i class="fas fa-broom"></i> Cleanup (Admin Only)
                        </div>
                        <div class="card-body py-2">
                            <form method="POST" class="form-inline"
                                  onsubmit="return confirm('Hapus notifikasi sudah dibaca yang lebih lama dari X hari?');">
                                <input type="hidden" name="action" value="delete_read">
                                <label class="mr-2" style="font-size:12px;">Hapus notif yang sudah dibaca &gt;</label>
                                <input type="number" name="days" value="30" min="1" max="365"
                                       class="form-control form-control-sm mr-2" style="width:80px;">
                                <span class="mr-2" style="font-size:12px;">hari lalu</span>
                                <button type="submit" class="btn btn-warning btn-sm">
                                    <i class="fas fa-trash"></i> Cleanup
                                </button>
                            </form>
                        </div>
                    </div>
                <?php endif; ?>

            <?php endif; ?>

        </div>
    </section>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>

<style>
.notif-list { display: flex; flex-direction: column; }
.notif-row {
    display: flex;
    gap: 14px;
    padding: 14px 16px;
    border-bottom: 1px solid #f0f0f0;
    transition: background 0.15s;
}
.notif-row:hover { background: #fafafa; }
.notif-row.unread {
    background: #fffbea;
    border-left: 4px solid #ffc107;
}
.notif-row.unread:hover { background: #fff7d6; }

.notif-row-icon {
    width: 40px; height: 40px;
    border-radius: 50%;
    flex-shrink: 0;
    display: flex;
    align-items: center;
    justify-content: center;
    color: #fff;
    font-size: 14px;
}
.notif-row-content { flex: 1; min-width: 0; }
.notif-row-header { font-size: 14px; margin-bottom: 4px; }
.notif-row-msg {
    font-size: 13px;
    color: #555;
    line-height: 1.5;
    margin-bottom: 6px;
}
.notif-row-meta { font-size: 11px; }
.notif-row-action { display: flex; align-items: center; }
</style>