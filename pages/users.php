<?php
require_once __DIR__ . '/../config/config.php';
requireLogin();

if (!canAdminister()) {
    die('Akses ditolak. Halaman ini hanya untuk administrator.');
}

$msg = ''; $msg_type = '';
$current_user_id = $_SESSION['user_id'] ?? null;

// ============ HANDLE POST ACTIONS ============
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    try {
        // ========== CREATE USER ==========
        if ($action === 'create_user') {
            $username  = strtolower(trim($_POST['username'] ?? ''));
            $password  = $_POST['password'] ?? '';
            $full_name = trim($_POST['full_name'] ?? '');
            $email     = trim($_POST['email'] ?? '') ?: null;
            $dept      = trim($_POST['department'] ?? '') ?: null;
            $role      = $_POST['role'] ?? 'user';
            $psaims_role = $_POST['psaims_role_code'] ?? null;
            if ($psaims_role === '') $psaims_role = null;

            if (!preg_match('/^[a-z0-9_]{3,30}$/', $username)) {
                throw new Exception('Username harus 3-30 karakter: huruf kecil, angka, underscore.');
            }
            if (strlen($password) < 6) {
                throw new Exception('Password minimal 6 karakter.');
            }
            if (empty($full_name)) {
                throw new Exception('Nama lengkap wajib diisi.');
            }
            if ($email && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
                throw new Exception('Format email tidak valid.');
            }
            // Sekarang support 3 role
            if (!in_array($role, ['admin', 'assessor', 'user'])) {
                throw new Exception('Role sistem tidak valid.');
            }

            // Cek username duplicate
            $stmt = $pdo->prepare("SELECT COUNT(*) FROM users WHERE username = ?");
            $stmt->execute([$username]);
            if ($stmt->fetchColumn() > 0) {
                throw new Exception("Username '{$username}' sudah digunakan.");
            }

            // Admin dan Assessor tidak perlu psaims_role
            if (in_array($role, ['admin', 'assessor'])) $psaims_role = null;

            $hash = password_hash($password, PASSWORD_DEFAULT);
            $stmt = $pdo->prepare(
                "INSERT INTO users (username, password, full_name, email, role,
                                    department, psaims_role_code, is_active)
                 VALUES (?, ?, ?, ?, ?, ?, ?, TRUE)"
            );
            $stmt->execute([$username, $hash, $full_name, $email, $role, $dept, $psaims_role]);
            logActivity('USER_CREATE', "Buat user: {$username} (role: {$role})");
            $msg = "User <strong>{$username}</strong> berhasil dibuat sebagai <strong>" . roleLabel($role) . "</strong>!";
            $msg_type = 'success';
        }

        // ========== UPDATE USER ==========
        elseif ($action === 'update_user') {
            $id        = (int)$_POST['user_id'];
            $full_name = trim($_POST['full_name'] ?? '');
            $email     = trim($_POST['email'] ?? '') ?: null;
            $dept      = trim($_POST['department'] ?? '') ?: null;
            $role      = $_POST['role'] ?? 'user';
            $psaims_role = $_POST['psaims_role_code'] ?? null;
            if ($psaims_role === '') $psaims_role = null;

            if (empty($full_name)) throw new Exception('Nama lengkap wajib diisi.');
            if ($email && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
                throw new Exception('Format email tidak valid.');
            }
            if (!in_array($role, ['admin', 'assessor', 'user'])) {
                throw new Exception('Role sistem tidak valid.');
            }

            // Proteksi: tidak bisa demote diri sendiri dari admin
            if ($id == $current_user_id && $role !== 'admin') {
                throw new Exception('Tidak bisa ubah role sendiri. Minta admin lain untuk melakukannya.');
            }

            // Admin dan Assessor tidak perlu psaims_role
            if (in_array($role, ['admin', 'assessor'])) $psaims_role = null;

            $stmt = $pdo->prepare(
                "UPDATE users
                 SET full_name = ?, email = ?, department = ?,
                     role = ?, psaims_role_code = ?, updated_at = CURRENT_TIMESTAMP
                 WHERE id = ?"
            );
            $stmt->execute([$full_name, $email, $dept, $role, $psaims_role, $id]);
            logActivity('USER_UPDATE', "Update user #{$id} (role: {$role})");
            $msg = "User berhasil diupdate!";
            $msg_type = 'success';
        }

        // ========== RESET PASSWORD ==========
        elseif ($action === 'reset_password') {
            $id = (int)$_POST['user_id'];
            $new_password = $_POST['new_password'] ?? '';

            if (strlen($new_password) < 6) {
                throw new Exception('Password minimal 6 karakter.');
            }

            $hash = password_hash($new_password, PASSWORD_DEFAULT);
            $stmt = $pdo->prepare(
                "UPDATE users SET password = ?, updated_at = CURRENT_TIMESTAMP WHERE id = ?"
            );
            $stmt->execute([$hash, $id]);
            logActivity('USER_RESET_PW', "Reset password user #{$id}");
            $msg = "Password user berhasil direset!";
            $msg_type = 'success';
        }

        // ========== TOGGLE ACTIVE ==========
        elseif ($action === 'toggle_active') {
            $id = (int)$_POST['user_id'];

            if ($id == $current_user_id) {
                throw new Exception('Tidak bisa nonaktifkan akun sendiri.');
            }

            $stmt = $pdo->prepare(
                "UPDATE users
                 SET is_active = NOT is_active, updated_at = CURRENT_TIMESTAMP
                 WHERE id = ? RETURNING username, is_active"
            );
            $stmt->execute([$id]);
            $row = $stmt->fetch();
            $status = $row['is_active'] ? 'diaktifkan' : 'dinonaktifkan';
            logActivity('USER_TOGGLE', "User {$row['username']} {$status}");
            $msg = "User <strong>{$row['username']}</strong> berhasil {$status}.";
            $msg_type = 'success';
        }

        // ========== DELETE USER ==========
        elseif ($action === 'delete_user') {
            $id = (int)$_POST['user_id'];

            if ($id == $current_user_id) {
                throw new Exception('Tidak bisa hapus akun sendiri.');
            }

            // Cek ada data assessment atau tidak
            $stmt = $pdo->prepare(
                "SELECT COUNT(*) FROM assessment_results WHERE user_id = ?"
            );
            $stmt->execute([$id]);
            $result_count = $stmt->fetchColumn();

            if ($result_count > 0) {
                throw new Exception(
                    "User ini punya {$result_count} jawaban assessment. " .
                    "Nonaktifkan saja (jangan hapus) untuk jaga integritas data historis."
                );
            }

            $stmt = $pdo->prepare("SELECT username FROM users WHERE id = ?");
            $stmt->execute([$id]);
            $username = $stmt->fetchColumn();

            $stmt = $pdo->prepare("DELETE FROM users WHERE id = ?");
            $stmt->execute([$id]);
            logActivity('USER_DELETE', "Delete user: {$username}");
            $msg = "User <strong>{$username}</strong> berhasil dihapus.";
            $msg_type = 'success';
        }

    } catch (Exception $e) {
        $msg = 'Gagal: ' . $e->getMessage();
        $msg_type = 'danger';
    }
}

// ============ FILTERS ============
$filter_role    = $_GET['role']   ?? 'all';
$filter_status  = $_GET['status'] ?? 'all';
$filter_psaims  = $_GET['psaims'] ?? 'all';
$search         = trim($_GET['q'] ?? '');

// ============ FETCH USERS ============
$sql = "SELECT u.*,
               r.role_name AS psaims_role_name,
               r.badge_color,
               (SELECT COUNT(*) FROM assessment_results WHERE user_id = u.id) AS answer_count,
               (SELECT MAX(created_at) FROM assessment_results WHERE user_id = u.id) AS last_contribution,
               (SELECT COUNT(*) FROM assessment_results WHERE verified_by = u.id) AS verified_count
        FROM users u
        LEFT JOIN psaims_roles r ON r.role_code = u.psaims_role_code
        WHERE 1=1";
$params = [];

if ($filter_role !== 'all') {
    $sql .= " AND u.role = ?";
    $params[] = $filter_role;
}
if ($filter_status === 'active') {
    $sql .= " AND u.is_active = TRUE";
} elseif ($filter_status === 'inactive') {
    $sql .= " AND u.is_active = FALSE";
}
if ($filter_psaims === 'assigned') {
    $sql .= " AND u.psaims_role_code IS NOT NULL";
} elseif ($filter_psaims === 'unassigned') {
    $sql .= " AND u.psaims_role_code IS NULL AND u.role = 'user'";
} elseif ($filter_psaims !== 'all') {
    $sql .= " AND u.psaims_role_code = ?";
    $params[] = $filter_psaims;
}
if ($search !== '') {
    $sql .= " AND (LOWER(u.username) LIKE LOWER(?)
               OR LOWER(u.full_name) LIKE LOWER(?)
               OR LOWER(COALESCE(u.email, '')) LIKE LOWER(?))";
    $params[] = "%{$search}%";
    $params[] = "%{$search}%";
    $params[] = "%{$search}%";
}

// Sort: admin dulu, lalu assessor, lalu user
$sql .= " ORDER BY
          CASE u.role
              WHEN 'admin' THEN 1
              WHEN 'assessor' THEN 2
              WHEN 'user' THEN 3
          END,
          u.is_active DESC, u.username";

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$users = $stmt->fetchAll();

// Stats — sekarang include assessor count
$stats = $pdo->query(
    "SELECT
        COUNT(*) AS total,
        COUNT(*) FILTER (WHERE is_active = TRUE)  AS active,
        COUNT(*) FILTER (WHERE is_active = FALSE) AS inactive,
        COUNT(*) FILTER (WHERE role = 'admin')    AS admin_count,
        COUNT(*) FILTER (WHERE role = 'assessor') AS assessor_count,
        COUNT(*) FILTER (WHERE role = 'user')     AS user_count,
        COUNT(*) FILTER (WHERE psaims_role_code IS NULL AND role = 'user') AS unassigned
     FROM users"
)->fetch();

// Roles untuk dropdown
$psaims_roles = $pdo->query(
    "SELECT role_code, role_name, COALESCE(badge_color, 'secondary') AS badge_color
     FROM psaims_roles
     WHERE COALESCE(is_active, TRUE) = TRUE
     ORDER BY COALESCE(sort_order, 999), role_code"
)->fetchAll();

// ============ HELPER: System Role Badge ============
function systemRoleBadge($role) {
    $map = [
        'admin'    => ['danger',    'user-shield',     'Administrator'],
        'assessor' => ['warning',   'clipboard-check', 'Assessor'],
        'user'     => ['light',     'user',            'User'],
    ];
    $cfg = $map[$role] ?? ['secondary', 'user', ucfirst($role)];
    return sprintf(
        '<span class="badge badge-%s" title="%s"><i class="fas fa-%s"></i> %s</span>',
        $cfg[0], e($cfg[2]), $cfg[1], $cfg[2]
    );
}

$page_title = 'Manajemen User';
require_once __DIR__ . '/../includes/header.php';
require_once __DIR__ . '/../includes/sidebar.php';
?>

<div class="content-wrapper">
    <section class="content-header">
        <div class="container-fluid">
            <div class="row align-items-center">
                <div class="col-sm-7">
                    <h1><i class="fas fa-users text-primary"></i> Manajemen User</h1>
                    <small class="text-muted">Kelola akun user PSAIMS (Admin / Assessor / User)</small>
                </div>
                <div class="col-sm-5 text-right">
                    <button class="btn btn-success" data-toggle="modal" data-target="#modalCreateUser">
                        <i class="fas fa-user-plus"></i> Tambah User
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

            <!-- ============ STATS (5 cards) ============ -->
            <div class="row">
                <div class="col-md col-sm-6">
                    <div class="small-box bg-info">
                        <div class="inner"><h3><?= $stats['total'] ?></h3><p>Total User</p></div>
                        <div class="icon"><i class="fas fa-users"></i></div>
                    </div>
                </div>
                <div class="col-md col-sm-6">
                    <div class="small-box bg-success">
                        <div class="inner"><h3><?= $stats['active'] ?></h3><p>Aktif</p></div>
                        <div class="icon"><i class="fas fa-user-check"></i></div>
                    </div>
                </div>
                <div class="col-md col-sm-6">
                    <div class="small-box bg-danger">
                        <div class="inner"><h3><?= $stats['admin_count'] ?></h3><p>Administrator</p></div>
                        <div class="icon"><i class="fas fa-user-shield"></i></div>
                    </div>
                </div>
                <div class="col-md col-sm-6">
                    <div class="small-box bg-warning">
                        <div class="inner"><h3><?= $stats['assessor_count'] ?></h3><p>Assessor</p></div>
                        <div class="icon"><i class="fas fa-clipboard-check"></i></div>
                    </div>
                </div>
                <div class="col-md col-sm-6">
                    <div class="small-box" style="background:#E9ECEF;">
                        <div class="inner">
                            <h3 style="color:#495057;"><?= $stats['unassigned'] ?></h3>
                            <p style="color:#495057;">Belum Ada Role PSAIMS</p>
                        </div>
                        <div class="icon"><i class="fas fa-user-times" style="color:#495057;"></i></div>
                    </div>
                </div>
            </div>

            <!-- ============ FILTER ============ -->
            <div class="card">
                <div class="card-header py-2">
                    <h6 class="card-title mb-0"><i class="fas fa-filter"></i> Filter</h6>
                </div>
                <div class="card-body py-2">
                    <form method="GET" class="form-inline">
                        <div class="form-group mr-3 mb-2">
                            <label class="mr-1" style="font-size:12px;">Cari:</label>
                            <input type="text" name="q" value="<?= e($search) ?>"
                                   placeholder="Username / Nama / Email"
                                   class="form-control form-control-sm" style="width:220px;">
                        </div>
                        <div class="form-group mr-3 mb-2">
                            <label class="mr-1" style="font-size:12px;">System Role:</label>
                            <select name="role" class="form-control form-control-sm">
                                <option value="all"      <?= $filter_role === 'all'      ? 'selected' : '' ?>>Semua</option>
                                <option value="admin"    <?= $filter_role === 'admin'    ? 'selected' : '' ?>>Administrator</option>
                                <option value="assessor" <?= $filter_role === 'assessor' ? 'selected' : '' ?>>Assessor</option>
                                <option value="user"     <?= $filter_role === 'user'     ? 'selected' : '' ?>>User</option>
                            </select>
                        </div>
                        <div class="form-group mr-3 mb-2">
                            <label class="mr-1" style="font-size:12px;">PSAIMS Role:</label>
                            <select name="psaims" class="form-control form-control-sm">
                                <option value="all"        <?= $filter_psaims === 'all'        ? 'selected' : '' ?>>Semua</option>
                                <option value="assigned"   <?= $filter_psaims === 'assigned'   ? 'selected' : '' ?>>Sudah Diassign</option>
                                <option value="unassigned" <?= $filter_psaims === 'unassigned' ? 'selected' : '' ?>>Belum Diassign</option>
                                <?php foreach ($psaims_roles as $r): ?>
                                    <option value="<?= e($r['role_code']) ?>"
                                            <?= $filter_psaims === $r['role_code'] ? 'selected' : '' ?>>
                                        <?= e($r['role_code']) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="form-group mr-3 mb-2">
                            <label class="mr-1" style="font-size:12px;">Status:</label>
                            <select name="status" class="form-control form-control-sm">
                                <option value="all"      <?= $filter_status === 'all'      ? 'selected' : '' ?>>Semua</option>
                                <option value="active"   <?= $filter_status === 'active'   ? 'selected' : '' ?>>Aktif</option>
                                <option value="inactive" <?= $filter_status === 'inactive' ? 'selected' : '' ?>>Non-Aktif</option>
                            </select>
                        </div>
                        <button type="submit" class="btn btn-primary btn-sm mb-2">
                            <i class="fas fa-search"></i> Cari
                        </button>
                        <a href="<?= BASE_URL ?>pages/users.php" class="btn btn-outline-secondary btn-sm mb-2 ml-1">
                            Reset
                        </a>
                    </form>
                </div>
            </div>

            <!-- ============ USER TABLE ============ -->
            <div class="card">
                <div class="card-header">
                    <h5 class="card-title mb-0">
                        <i class="fas fa-list"></i> Daftar User
                        <span class="badge badge-secondary ml-2"><?= count($users) ?></span>
                    </h5>
                </div>
                <div class="card-body p-0">
                    <?php if (empty($users)): ?>
                        <p class="text-muted text-center p-4">Tidak ada user yang sesuai filter.</p>
                    <?php else: ?>
                        <div class="table-responsive">
                            <table class="table table-hover mb-0" style="font-size:13px;">
                                <thead class="bg-light">
                                    <tr>
                                        <th style="width:40px;">#</th>
                                        <th style="width:150px;">Username</th>
                                        <th>Nama &amp; Info</th>
                                        <th style="width:150px;">System Role</th>
                                        <th style="width:150px;">PSAIMS Role</th>
                                        <th style="width:100px;">Kontribusi</th>
                                        <th style="width:90px;">Status</th>
                                        <th style="width:160px;">Aksi</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($users as $i => $u):
                                        $is_self = $u['id'] == $current_user_id;
                                    ?>
                                        <tr class="<?= !$u['is_active'] ? 'text-muted' : '' ?>">
                                            <td class="text-center"><?= $i + 1 ?></td>
                                            <td>
                                                <code><?= e($u['username']) ?></code>
                                                <?php if ($is_self): ?>
                                                    <span class="badge badge-info ml-1" style="font-size:9px;">ANDA</span>
                                                <?php endif; ?>
                                            </td>
                                            <td>
                                                <strong><?= e($u['full_name']) ?></strong>
                                                <?php if ($u['email']): ?>
                                                    <br>
                                                    <small class="text-muted">
                                                        <i class="fas fa-envelope" style="font-size:10px;"></i>
                                                        <?= e($u['email']) ?>
                                                    </small>
                                                <?php endif; ?>
                                                <?php if ($u['department']): ?>
                                                    <br>
                                                    <small class="text-muted">
                                                        <i class="fas fa-building" style="font-size:10px;"></i>
                                                        <?= e($u['department']) ?>
                                                    </small>
                                                <?php endif; ?>
                                            </td>
                                            <td>
                                                <?= systemRoleBadge($u['role']) ?>
                                            </td>
                                            <td>
                                                <?php if ($u['role'] === 'admin'): ?>
                                                    <small class="text-muted">
                                                        <i class="fas fa-check-circle"></i> Akses penuh
                                                    </small>
                                                <?php elseif ($u['role'] === 'assessor'): ?>
                                                    <small class="text-muted">
                                                        <i class="fas fa-eye"></i> Verifikator
                                                    </small>
                                                <?php elseif ($u['psaims_role_code']): ?>
                                                    <span class="badge badge-<?= e($u['badge_color']) ?>"
                                                          title="<?= e($u['psaims_role_name']) ?>">
                                                        <?= e($u['psaims_role_code']) ?>
                                                    </span>
                                                    <br>
                                                    <small class="text-muted" style="font-size:10px;">
                                                        <?= e(mb_strimwidth($u['psaims_role_name'], 0, 20, '…')) ?>
                                                    </small>
                                                <?php else: ?>
                                                    <small class="text-warning">
                                                        <i class="fas fa-exclamation-triangle"></i> Belum diassign
                                                    </small>
                                                <?php endif; ?>
                                            </td>
                                            <td>
                                                <?php if ($u['role'] === 'assessor' && $u['verified_count'] > 0): ?>
                                                    <strong><?= $u['verified_count'] ?></strong>
                                                    <small class="text-muted">diverifikasi</small>
                                                <?php elseif ($u['answer_count'] > 0): ?>
                                                    <strong><?= $u['answer_count'] ?></strong>
                                                    <small class="text-muted">jawaban</small>
                                                    <?php if ($u['last_contribution']): ?>
                                                        <br>
                                                        <small class="text-muted" style="font-size:10px;">
                                                            <?= date('d/m/Y', strtotime($u['last_contribution'])) ?>
                                                        </small>
                                                    <?php endif; ?>
                                                <?php else: ?>
                                                    <small class="text-muted">—</small>
                                                <?php endif; ?>
                                            </td>
                                            <td>
                                                <?php if ($u['is_active']): ?>
                                                    <span class="badge badge-success">Aktif</span>
                                                <?php else: ?>
                                                    <span class="badge badge-secondary">Non-Aktif</span>
                                                <?php endif; ?>
                                            </td>
                                            <td>
                                                <div class="btn-group btn-group-sm" role="group">
                                                    <button type="button"
                                                            class="btn btn-outline-primary btn-xs btn-edit-user"
                                                            data-id="<?= $u['id'] ?>"
                                                            data-username="<?= e($u['username']) ?>"
                                                            data-name="<?= e($u['full_name']) ?>"
                                                            data-email="<?= e($u['email'] ?? '') ?>"
                                                            data-dept="<?= e($u['department'] ?? '') ?>"
                                                            data-role="<?= e($u['role']) ?>"
                                                            data-psaims="<?= e($u['psaims_role_code'] ?? '') ?>"
                                                            title="Edit">
                                                        <i class="fas fa-edit"></i>
                                                    </button>
                                                    <button type="button"
                                                            class="btn btn-outline-warning btn-xs btn-reset-pw"
                                                            data-id="<?= $u['id'] ?>"
                                                            data-username="<?= e($u['username']) ?>"
                                                            title="Reset Password">
                                                        <i class="fas fa-key"></i>
                                                    </button>
                                                    <?php if (!$is_self): ?>
                                                        <form method="POST" class="d-inline"
                                                              onsubmit="return confirm('<?= $u['is_active'] ? 'Non-aktifkan' : 'Aktifkan' ?> user <?= e($u['username']) ?>?');">
                                                            <input type="hidden" name="action" value="toggle_active">
                                                            <input type="hidden" name="user_id" value="<?= $u['id'] ?>">
                                                            <button type="submit"
                                                                    class="btn btn-outline-<?= $u['is_active'] ? 'secondary' : 'success' ?> btn-xs"
                                                                    title="<?= $u['is_active'] ? 'Non-aktifkan' : 'Aktifkan' ?>">
                                                                <i class="fas fa-<?= $u['is_active'] ? 'ban' : 'check' ?>"></i>
                                                            </button>
                                                        </form>
                                                        <?php if ($u['answer_count'] == 0 && $u['verified_count'] == 0): ?>
                                                            <form method="POST" class="d-inline"
                                                                  onsubmit="return confirm('PERMANEN: Hapus user <?= e($u['username']) ?>?\n\nAksi ini tidak bisa di-undo.');">
                                                                <input type="hidden" name="action" value="delete_user">
                                                                <input type="hidden" name="user_id" value="<?= $u['id'] ?>">
                                                                <button type="submit" class="btn btn-outline-danger btn-xs"
                                                                        title="Hapus">
                                                                    <i class="fas fa-trash"></i>
                                                                </button>
                                                            </form>
                                                        <?php endif; ?>
                                                    <?php endif; ?>
                                                </div>
                                            </td>
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

<!-- ============ MODAL: Create User ============ -->
<div class="modal fade" id="modalCreateUser" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <form method="POST">
            <div class="modal-content">
                <div class="modal-header bg-success">
                    <h5 class="modal-title text-white"><i class="fas fa-user-plus"></i> Tambah User Baru</h5>
                    <button type="button" class="close text-white" data-dismiss="modal">×</button>
                </div>
                <div class="modal-body">
                    <input type="hidden" name="action" value="create_user">

                    <div class="row">
                        <div class="col-md-6">
                            <div class="form-group">
                                <label>Username <span class="text-danger">*</span></label>
                                <input type="text" name="username" class="form-control" required
                                       pattern="[a-z0-9_]{3,30}"
                                       placeholder="contoh: budi_santoso"
                                       style="text-transform:lowercase;">
                                <small class="text-muted">3-30 karakter: huruf kecil, angka, underscore</small>
                            </div>
                        </div>
                        <div class="col-md-6">
                            <div class="form-group">
                                <label>Password <span class="text-danger">*</span></label>
                                <div class="input-group">
                                    <input type="text" name="password" class="form-control" required
                                           minlength="6" placeholder="Min. 6 karakter" id="create-pw">
                                    <div class="input-group-append">
                                        <button type="button" class="btn btn-outline-secondary btn-generate-pw"
                                                data-target="#create-pw" title="Generate random password">
                                            <i class="fas fa-magic"></i>
                                        </button>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <div class="form-group">
                        <label>Nama Lengkap <span class="text-danger">*</span></label>
                        <input type="text" name="full_name" class="form-control" required
                               placeholder="Nama lengkap user">
                    </div>

                    <div class="row">
                        <div class="col-md-6">
                            <div class="form-group">
                                <label>Email</label>
                                <input type="email" name="email" class="form-control"
                                       placeholder="user@pertagas.com">
                            </div>
                        </div>
                        <div class="col-md-6">
                            <div class="form-group">
                                <label>Departemen</label>
                                <input type="text" name="department" class="form-control"
                                       placeholder="Contoh: Operations">
                            </div>
                        </div>
                    </div>

                    <hr>

                    <div class="row">
                        <div class="col-md-6">
                            <div class="form-group">
                                <label>System Role <span class="text-danger">*</span></label>
                                <select name="role" class="form-control" id="create-role">
                                    <option value="user">User (Responsible — isi assessment)</option>
                                    <option value="assessor">Assessor (Verifier — review jawaban)</option>
                                    <option value="admin">Administrator (Full akses sistem)</option>
                                </select>
                                <small class="text-muted">
                                    <i class="fas fa-info-circle"></i>
                                    User isi → Assessor verifikasi → Admin kelola
                                </small>
                            </div>
                        </div>
                        <div class="col-md-6">
                            <div class="form-group" id="create-psaims-wrap">
                                <label>PSAIMS Role</label>
                                <select name="psaims_role_code" class="form-control">
                                    <option value="">— Tidak diassign —</option>
                                    <?php foreach ($psaims_roles as $r): ?>
                                        <option value="<?= e($r['role_code']) ?>">
                                            <?= e($r['role_code']) ?> — <?= e($r['role_name']) ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                                <small class="text-muted">Hanya untuk role User (Responsible)</small>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-dismiss="modal">Batal</button>
                    <button type="submit" class="btn btn-success">
                        <i class="fas fa-user-plus"></i> Buat User
                    </button>
                </div>
            </div>
        </form>
    </div>
</div>

<!-- ============ MODAL: Edit User ============ -->
<div class="modal fade" id="modalEditUser" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <form method="POST">
            <div class="modal-content">
                <div class="modal-header bg-primary">
                    <h5 class="modal-title text-white">
                        <i class="fas fa-edit"></i> Edit User
                        <code class="ml-2 text-white" id="edit-username-label"></code>
                    </h5>
                    <button type="button" class="close text-white" data-dismiss="modal">×</button>
                </div>
                <div class="modal-body">
                    <input type="hidden" name="action" value="update_user">
                    <input type="hidden" name="user_id" id="edit-id">

                    <div class="alert alert-light" style="font-size:12px;">
                        <i class="fas fa-info-circle text-info"></i>
                        Username tidak bisa diubah. Untuk reset password, gunakan tombol
                        <i class="fas fa-key text-warning"></i>.
                    </div>

                    <div class="form-group">
                        <label>Nama Lengkap <span class="text-danger">*</span></label>
                        <input type="text" name="full_name" id="edit-name" class="form-control" required>
                    </div>

                    <div class="row">
                        <div class="col-md-6">
                            <div class="form-group">
                                <label>Email</label>
                                <input type="email" name="email" id="edit-email" class="form-control">
                            </div>
                        </div>
                        <div class="col-md-6">
                            <div class="form-group">
                                <label>Departemen</label>
                                <input type="text" name="department" id="edit-dept" class="form-control">
                            </div>
                        </div>
                    </div>

                    <hr>

                    <div class="row">
                        <div class="col-md-6">
                            <div class="form-group">
                                <label>System Role</label>
                                <select name="role" id="edit-role" class="form-control">
                                    <option value="user">User (Responsible)</option>
                                    <option value="assessor">Assessor (Verifier)</option>
                                    <option value="admin">Administrator</option>
                                </select>
                            </div>
                        </div>
                        <div class="col-md-6">
                            <div class="form-group" id="edit-psaims-wrap">
                                <label>PSAIMS Role</label>
                                <select name="psaims_role_code" id="edit-psaims" class="form-control">
                                    <option value="">— Tidak diassign —</option>
                                    <?php foreach ($psaims_roles as $r): ?>
                                        <option value="<?= e($r['role_code']) ?>">
                                            <?= e($r['role_code']) ?> — <?= e($r['role_name']) ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                                <small class="text-muted">Hanya untuk role User</small>
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

<!-- ============ MODAL: Reset Password ============ -->
<div class="modal fade" id="modalResetPw" tabindex="-1">
    <div class="modal-dialog">
        <form method="POST">
            <div class="modal-content">
                <div class="modal-header bg-warning">
                    <h5 class="modal-title"><i class="fas fa-key"></i> Reset Password</h5>
                    <button type="button" class="close" data-dismiss="modal">×</button>
                </div>
                <div class="modal-body">
                    <input type="hidden" name="action" value="reset_password">
                    <input type="hidden" name="user_id" id="reset-id">

                    <p>Reset password untuk user: <code id="reset-username"></code></p>

                    <div class="form-group">
                        <label>Password Baru <span class="text-danger">*</span></label>
                        <div class="input-group">
                            <input type="text" name="new_password" class="form-control" required
                                   minlength="6" placeholder="Min. 6 karakter" id="reset-pw">
                            <div class="input-group-append">
                                <button type="button" class="btn btn-outline-secondary btn-generate-pw"
                                        data-target="#reset-pw" title="Generate random">
                                    <i class="fas fa-magic"></i>
                                </button>
                            </div>
                        </div>
                        <small class="text-muted">
                            <i class="fas fa-info-circle"></i>
                            Sampaikan password baru ini ke user via channel yang aman.
                        </small>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-dismiss="modal">Batal</button>
                    <button type="submit" class="btn btn-warning">
                        <i class="fas fa-key"></i> Reset Password
                    </button>
                </div>
            </div>
        </form>
    </div>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>

<script>
jQuery(function($) {
    $('#modalCreateUser, #modalEditUser, #modalResetPw').appendTo('body');

    // Username auto-lowercase
    $('input[name=username]').on('input', function() {
        this.value = this.value.toLowerCase().replace(/[^a-z0-9_]/g, '');
    });

    // Generate random password
    $(document).on('click', '.btn-generate-pw', function() {
        const chars = 'abcdefghijkmnpqrstuvwxyzABCDEFGHJKLMNPQRSTUVWXYZ23456789';
        let pw = '';
        for (let i = 0; i < 10; i++) {
            pw += chars.charAt(Math.floor(Math.random() * chars.length));
        }
        $($(this).attr('data-target')).val(pw);
    });

    // Toggle PSAIMS role based on system role
    // Admin dan Assessor: disable (tidak perlu PSAIMS role)
    // User: enable
    function toggleRoleWrap(roleValue, $wrap) {
        if (roleValue === 'admin' || roleValue === 'assessor') {
            $wrap.find('select').val('').prop('disabled', true);
            $wrap.css('opacity', 0.5);
        } else {
            $wrap.find('select').prop('disabled', false);
            $wrap.css('opacity', 1);
        }
    }
    $('#create-role').on('change', function() { toggleRoleWrap($(this).val(), $('#create-psaims-wrap')); });
    $('#edit-role').on('change', function() { toggleRoleWrap($(this).val(), $('#edit-psaims-wrap')); });

    // Edit button
    $(document).on('click', '.btn-edit-user', function() {
        const $btn = $(this);
        $('#edit-id').val($btn.attr('data-id'));
        $('#edit-username-label').text($btn.attr('data-username'));
        $('#edit-name').val($btn.attr('data-name'));
        $('#edit-email').val($btn.attr('data-email'));
        $('#edit-dept').val($btn.attr('data-dept'));
        $('#edit-role').val($btn.attr('data-role'));
        $('#edit-psaims').val($btn.attr('data-psaims'));
        toggleRoleWrap($btn.attr('data-role'), $('#edit-psaims-wrap'));
        $('#modalEditUser').modal('show');
    });

    // Reset password button
    $(document).on('click', '.btn-reset-pw', function() {
        const $btn = $(this);
        $('#reset-id').val($btn.attr('data-id'));
        $('#reset-username').text($btn.attr('data-username'));
        $('#reset-pw').val('');
        $('#modalResetPw').modal('show');
    });
});
</script>