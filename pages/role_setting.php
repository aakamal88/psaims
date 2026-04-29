<?php
require_once __DIR__ . '/../config/config.php';
requireLogin();

if (!canViewAdmin()) {
    die('Akses ditolak.');
}

// Safety net: tolak POST request dari non-admin
blockNonAdminPost();

// Daftar warna badge yang tersedia (konsisten dengan Bootstrap 4 + AdminLTE)
$available_colors = [
    'primary'   => ['label' => 'Primary (biru)',    'hex' => '#007bff'],
    'success'   => ['label' => 'Success (hijau)',   'hex' => '#28a745'],
    'danger'    => ['label' => 'Danger (merah)',    'hex' => '#dc3545'],
    'warning'   => ['label' => 'Warning (kuning)',  'hex' => '#ffc107'],
    'info'      => ['label' => 'Info (cyan)',       'hex' => '#17a2b8'],
    'secondary' => ['label' => 'Secondary (abu)',   'hex' => '#6c757d'],
    'purple'    => ['label' => 'Purple (ungu)',     'hex' => '#6f42c1'],
    'pink'      => ['label' => 'Pink (merah muda)', 'hex' => '#e83e8c'],
    'teal'      => ['label' => 'Teal (toska)',      'hex' => '#20c997'],
    'indigo'    => ['label' => 'Indigo (nila)',     'hex' => '#6610f2'],
    'orange'    => ['label' => 'Orange (oranye)',   'hex' => '#fd7e14'],
    'dark'      => ['label' => 'Dark (hitam)',      'hex' => '#343a40'],
];

// ============ Handle POST Actions ============
$msg = ''; $msg_type = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    try {
        // ==================== CREATE ROLE ====================
        if ($action === 'create_role') {
            $code = strtoupper(trim($_POST['role_code'] ?? ''));
            $name = trim($_POST['role_name'] ?? '');
            $desc = trim($_POST['description'] ?? '');
            $color = $_POST['badge_color'] ?? 'secondary';

            if (!preg_match('/^[A-Z0-9_]{2,20}$/', $code)) {
                throw new Exception('Kode role harus 2-20 karakter (A-Z, 0-9, _).');
            }
            if (empty($name)) {
                throw new Exception('Nama role wajib diisi.');
            }
            if (!isset($available_colors[$color])) {
                throw new Exception('Warna tidak valid.');
            }

            $stmt = $pdo->prepare(
                "INSERT INTO psaims_roles (role_code, role_name, description, badge_color, sort_order, is_active)
                 VALUES (?, ?, ?, ?, (SELECT COALESCE(MAX(sort_order), 0) + 1 FROM psaims_roles), TRUE)"
            );
            $stmt->execute([$code, $name, $desc, $color]);
            logActivity('ROLE_CREATE', "Tambah role: {$code}");
            $msg = "Role <strong>{$code}</strong> berhasil dibuat!";
            $msg_type = 'success';
        }

        // ==================== UPDATE ROLE ====================
        elseif ($action === 'update_role') {
            $id = (int)$_POST['role_id'];
            $name = trim($_POST['role_name'] ?? '');
            $desc = trim($_POST['description'] ?? '');
            $color = $_POST['badge_color'] ?? 'secondary';

            if (empty($name)) {
                throw new Exception('Nama role wajib diisi.');
            }
            if (!isset($available_colors[$color])) {
                throw new Exception('Warna tidak valid.');
            }

            $stmt = $pdo->prepare(
                "UPDATE psaims_roles
                 SET role_name = ?, description = ?, badge_color = ?, updated_at = CURRENT_TIMESTAMP
                 WHERE id = ?"
            );
            $stmt->execute([$name, $desc, $color, $id]);
            logActivity('ROLE_UPDATE', "Update role ID {$id}");
            $msg = "Role berhasil diupdate!";
            $msg_type = 'success';
        }

        // ==================== TOGGLE ACTIVE (Soft delete) ====================
        elseif ($action === 'toggle_active') {
            $id = (int)$_POST['role_id'];
            $stmt = $pdo->prepare(
                "UPDATE psaims_roles
                 SET is_active = NOT COALESCE(is_active, TRUE), updated_at = CURRENT_TIMESTAMP
                 WHERE id = ?"
            );
            $stmt->execute([$id]);

            $stmt2 = $pdo->prepare("SELECT role_code, is_active FROM psaims_roles WHERE id = ?");
            $stmt2->execute([$id]);
            $row = $stmt2->fetch();
            $status = $row['is_active'] ? 'diaktifkan' : 'dinonaktifkan';
            logActivity('ROLE_TOGGLE', "Role {$row['role_code']} {$status}");
            $msg = "Role <strong>{$row['role_code']}</strong> berhasil {$status}.";
            $msg_type = 'success';
        }

        // ==================== UPDATE RASCI PER-ELEMEN ====================
        elseif ($action === 'update_element_rasci') {
            $element_id = (int)$_POST['element_id'];
            $mappings = $_POST['mapping'] ?? [];

            $pdo->beginTransaction();

            // Hapus mapping lama untuk elemen ini
            $stmt = $pdo->prepare("DELETE FROM element_role_mapping WHERE element_id = ?");
            $stmt->execute([$element_id]);

            // Insert mapping baru
            $stmt_ins = $pdo->prepare(
                "INSERT INTO element_role_mapping (element_id, role_id, responsibility)
                 SELECT ?, id, ? FROM psaims_roles WHERE role_code = ?"
            );

            $count_A = 0; $count_R = 0;
            foreach ($mappings as $role_code => $levels) {
                if (!is_array($levels)) continue;
                foreach ($levels as $level) {
                    if (!in_array($level, ['A', 'R', 'S', 'C', 'I'])) continue;
                    $stmt_ins->execute([$element_id, $level, $role_code]);
                    if ($level === 'A') $count_A++;
                    if ($level === 'R') $count_R++;
                }
            }

            $pdo->commit();
            logActivity('ELEMENT_RASCI_UPDATE', "Update RASCI elemen #{$element_id}");

            // Warning kalau tidak valid
            $warnings = [];
            if ($count_A !== 1) $warnings[] = "Accountable harus tepat 1 (saat ini: {$count_A})";
            if ($count_R < 1)   $warnings[] = "Responsible minimal 1 (saat ini: {$count_R})";

            if (!empty($warnings)) {
                $msg = "Mapping tersimpan, tapi ada peringatan: " . implode(', ', $warnings);
                $msg_type = 'warning';
            } else {
                $msg = "RASCI elemen berhasil diupdate!";
                $msg_type = 'success';
            }
        }

        // ==================== ASSIGN USER ROLE ====================
        elseif ($action === 'assign_user_role') {
            $user_id = (int)$_POST['user_id'];
            $role_code = $_POST['new_role_code'] ?? null;
            if ($role_code === '') $role_code = null;

            $stmt = $pdo->prepare(
                "UPDATE users SET psaims_role_code = ?, updated_at = CURRENT_TIMESTAMP WHERE id = ?"
            );
            $stmt->execute([$role_code, $user_id]);
            logActivity('USER_ROLE_ASSIGN', "User #{$user_id} → {$role_code}");
            $msg = "Role user berhasil diupdate!";
            $msg_type = 'success';
        }

    } catch (Exception $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        $msg = 'Gagal: ' . $e->getMessage();
        $msg_type = 'danger';
    }
}

// ============ Fetch Data ============
// Semua role (termasuk non-aktif)
$all_roles = $pdo->query(
    "SELECT r.*,
            COALESCE(r.is_active, TRUE) AS is_active,
            COALESCE(r.badge_color, 'secondary') AS badge_color,
            COALESCE(r.sort_order, 999) AS sort_order,
            (SELECT COUNT(*) FROM users u WHERE u.psaims_role_code = r.role_code) AS user_count,
            (SELECT COUNT(*) FROM element_role_mapping m WHERE m.role_id = r.id) AS mapping_count
     FROM psaims_roles r
     ORDER BY COALESCE(r.sort_order, 999), r.role_code"
)->fetchAll();

// Role aktif saja (untuk dropdown)
$active_roles = array_filter($all_roles, fn($r) => $r['is_active']);

// Elemen PSAIMS
$elements = $pdo->query(
    "SELECT * FROM psaims_elements WHERE is_active = TRUE ORDER BY element_number"
)->fetchAll();

// Tab aktif (default: 'roles')
$current_tab = $_GET['tab'] ?? 'roles';

// Elemen yang dipilih untuk tab RASCI
$selected_element_id = isset($_GET['el']) ? (int)$_GET['el'] : ($elements[0]['id'] ?? 0);

// Current element RASCI mapping
$current_rasci = [];
if ($selected_element_id) {
    $stmt = $pdo->prepare(
        "SELECT r.role_code, m.responsibility
         FROM element_role_mapping m
         JOIN psaims_roles r ON r.id = m.role_id
         WHERE m.element_id = ?"
    );
    $stmt->execute([$selected_element_id]);
    while ($row = $stmt->fetch()) {
        $current_rasci[$row['role_code']][] = $row['responsibility'];
    }
}
$current_el = null;
foreach ($elements as $el) {
    if ($el['id'] == $selected_element_id) { $current_el = $el; break; }
}

// Daftar user (untuk tab Assign)
$all_users = $pdo->query(
    "SELECT u.id, u.username, u.full_name, u.email, u.role, u.is_active, u.psaims_role_code,
            r.role_name AS psaims_role_name, r.badge_color
     FROM users u
     LEFT JOIN psaims_roles r ON r.role_code = u.psaims_role_code
     ORDER BY u.role DESC, u.username"
)->fetchAll();

$page_title = 'Role &amp; RASCI Setting';
require_once __DIR__ . '/../includes/header.php';
require_once __DIR__ . '/../includes/sidebar.php';
?>

<div class="content-wrapper">
    <section class="content-header">
        <div class="container-fluid">
            <h1><i class="fas fa-cog"></i> Role &amp; RASCI Setting</h1>
            <small class="text-muted">Kelola role organisasi dan mapping tanggung jawab</small>
        </div>
    </section>

    <section class="content">
        <div class="container-fluid">

            <?= readOnlyBanner() ?>

            <?php if ($msg): ?>
                <div class="alert alert-<?= $msg_type ?> alert-dismissible shadow-sm">
                    <button type="button" class="close" data-dismiss="alert">×</button>
                    <i class="fas fa-<?= $msg_type === 'success' ? 'check-circle' : ($msg_type === 'warning' ? 'exclamation-triangle' : 'times-circle') ?>"></i>
                    <?= $msg ?>
                </div>
            <?php endif; ?>

            <!-- ============ TABS ============ -->
            <div class="card">
                <div class="card-header p-0 border-bottom">
                    <ul class="nav nav-tabs card-header-tabs">
                        <li class="nav-item">
                            <a class="nav-link <?= $current_tab === 'roles' ? 'active' : '' ?>"
                               href="?tab=roles">
                                <i class="fas fa-user-tag"></i> Kelola Role
                            </a>
                        </li>
                        <li class="nav-item">
                            <a class="nav-link <?= $current_tab === 'rasci_element' ? 'active' : '' ?>"
                               href="?tab=rasci_element">
                                <i class="fas fa-th"></i> RASCI per-Elemen
                            </a>
                        </li>
                        <li class="nav-item">
                            <a class="nav-link <?= $current_tab === 'rasci_question' ? 'active' : '' ?>"
                               href="?tab=rasci_question">
                                <i class="fas fa-layer-group"></i> RASCI per-Pertanyaan
                                <span class="badge badge-info ml-1">pilot</span>
                            </a>
                        </li>
                        <li class="nav-item">
                            <a class="nav-link <?= $current_tab === 'users' ? 'active' : '' ?>"
                               href="?tab=users">
                                <i class="fas fa-users"></i> Assign User
                            </a>
                        </li>
                    </ul>
                </div>

                <div class="card-body">

                <!-- ================================================== -->
                <!-- TAB 1: KELOLA ROLE                                  -->
                <!-- ================================================== -->
                <?php if ($current_tab === 'roles'): ?>

                    <div class="d-flex justify-content-between align-items-center mb-3">
                        <div class="text-muted" style="font-size:13px;">
                            <?= count($active_roles) ?> role aktif · <?= count($all_roles) - count($active_roles) ?> non-aktif
                        </div>
                        <?php if (canAdminister()): ?>
                        <button class="btn btn-success btn-sm" data-toggle="modal" data-target="#modalCreateRole">
                            <i class="fas fa-plus"></i> Tambah Role Baru
                        </button>
                        <?php endif; ?>
                    </div>

                    <div class="table-responsive">
                        <table class="table table-bordered table-hover">
                            <thead class="bg-light">
                                <tr>
                                    <th style="width:40px;">#</th>
                                    <th style="width:80px;">Kode</th>
                                    <th>Nama Role</th>
                                    <th>Deskripsi</th>
                                    <th class="text-center" style="width:70px;">Warna</th>
                                    <th class="text-center" style="width:60px;">User</th>
                                    <th class="text-center" style="width:70px;">Mapping</th>
                                    <th class="text-center" style="width:70px;">Status</th>
                                    <?php if (canAdminister()): ?>
                                    <th class="text-center" style="width:120px;">Aksi</th>
                                    <?php endif; ?>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($all_roles as $i => $r): ?>
                                    <tr class="<?= !$r['is_active'] ? 'text-muted' : '' ?>">
                                        <td><?= $i + 1 ?></td>
                                        <td>
                                            <span class="badge badge-<?= e($r['badge_color']) ?>"
                                                  style="font-size:12px; padding:4px 8px;">
                                                <?= e($r['role_code']) ?>
                                            </span>
                                        </td>
                                        <td><strong><?= e($r['role_name']) ?></strong></td>
                                        <td style="font-size:12px;"><?= e($r['description']) ?></td>
                                        <td class="text-center">
                                            <span style="display:inline-block; width:18px; height:18px;
                                                         background:<?= e($available_colors[$r['badge_color']]['hex'] ?? '#6c757d') ?>;
                                                         border-radius:3px; vertical-align:middle;"></span>
                                        </td>
                                        <td class="text-center"><?= $r['user_count'] ?></td>
                                        <td class="text-center"><?= $r['mapping_count'] ?></td>
                                        <td class="text-center">
                                            <?php if ($r['is_active']): ?>
                                                <span class="badge badge-success">Aktif</span>
                                            <?php else: ?>
                                                <span class="badge badge-secondary">Non-Aktif</span>
                                            <?php endif; ?>
                                        </td>
                                        <?php if (canAdminister()): ?>
                                        <td class="text-center">
                                            <button class="btn btn-xs btn-outline-primary btn-edit-role"
                                                    data-id="<?= $r['id'] ?>"
                                                    data-code="<?= e($r['role_code']) ?>"
                                                    data-name="<?= e($r['role_name']) ?>"
                                                    data-desc="<?= e($r['description']) ?>"
                                                    data-color="<?= e($r['badge_color']) ?>">
                                                <i class="fas fa-edit"></i>
                                            </button>
                                            <form method="POST" style="display:inline;"
                                                  onsubmit="return confirm('<?= $r['is_active'] ? 'Nonaktifkan' : 'Aktifkan' ?> role <?= e($r['role_code']) ?>?');">
                                                <input type="hidden" name="action" value="toggle_active">
                                                <input type="hidden" name="role_id" value="<?= $r['id'] ?>">
                                                <button class="btn btn-xs btn-outline-<?= $r['is_active'] ? 'warning' : 'success' ?>">
                                                    <i class="fas fa-<?= $r['is_active'] ? 'ban' : 'check' ?>"></i>
                                                </button>
                                            </form>
                                        </td>
                                        <?php endif; ?>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>

                    <div class="alert alert-light mt-3" style="font-size:12px;">
                        <i class="fas fa-info-circle text-info"></i>
                        <strong>Soft delete:</strong> Role yang di-nonaktifkan tidak dihapus dari database.
                        Data historis assessment tetap aman. Role non-aktif tidak muncul di dropdown user &amp;
                        tidak bisa diberi mapping baru, tapi mapping lama tetap berlaku untuk user existing.
                    </div>

                <!-- ================================================== -->
                <!-- TAB 2: RASCI PER-ELEMEN                             -->
                <!-- ================================================== -->
                <?php elseif ($current_tab === 'rasci_element'): ?>

                    <div class="row">
                        <div class="col-md-4">
                            <h6><i class="fas fa-list"></i> Pilih Elemen</h6>
                            <div class="list-group" style="max-height:600px; overflow-y:auto; font-size:12px;">
                                <?php foreach ($elements as $el):
                                    $stmt_cnt = $pdo->prepare("SELECT COUNT(*) FROM element_role_mapping WHERE element_id = ?");
                                    $stmt_cnt->execute([$el['id']]);
                                    $mapping_cnt = $stmt_cnt->fetchColumn();
                                    $is_sel = $el['id'] == $selected_element_id;
                                ?>
                                    <a href="?tab=rasci_element&el=<?= $el['id'] ?>"
                                       class="list-group-item list-group-item-action py-2 <?= $is_sel ? 'active' : '' ?>">
                                        <i class="<?= e($el['icon']) ?> mr-1"></i>
                                        <strong>E<?= str_pad($el['element_number'], 2, '0', STR_PAD_LEFT) ?></strong>
                                        <?= e(mb_strimwidth($el['element_name'], 0, 30, '…')) ?>
                                        <?php if ($mapping_cnt > 0): ?>
                                            <span class="badge badge-<?= $is_sel ? 'light' : 'success' ?> float-right"><?= $mapping_cnt ?></span>
                                        <?php else: ?>
                                            <span class="badge badge-<?= $is_sel ? 'light' : 'secondary' ?> float-right">—</span>
                                        <?php endif; ?>
                                    </a>
                                <?php endforeach; ?>
                            </div>
                        </div>

                        <div class="col-md-8">
                            <?php if ($current_el): ?>
                                <h6>
                                    <i class="<?= e($current_el['icon']) ?> text-<?= e($current_el['color']) ?>"></i>
                                    E<?= $current_el['element_number'] ?> — <?= e($current_el['element_name']) ?>
                                </h6>
                                <p class="text-muted" style="font-size:12px;">
                                    Centang level RASCI untuk tiap role.
                                    Ideal: <strong>tepat 1 role Accountable</strong> dan <strong>minimal 1 role Responsible</strong>.
                                </p>

                                <form method="POST" id="formElementRasci">
                                    <input type="hidden" name="action" value="update_element_rasci">
                                    <input type="hidden" name="element_id" value="<?= $current_el['id'] ?>">

                                    <table class="table table-bordered table-sm" style="font-size:12px;">
                                        <thead class="bg-light">
                                            <tr>
                                                <th>Role</th>
                                                <th class="text-center" style="background:#FCEBEB;">A<br><small>Accountable</small></th>
                                                <th class="text-center" style="background:#EAF3DE;">R<br><small>Responsible</small></th>
                                                <th class="text-center" style="background:#E6F1FB;">S<br><small>Support</small></th>
                                                <th class="text-center" style="background:#FAEEDA;">C<br><small>Consulted</small></th>
                                                <th class="text-center" style="background:#F1EFE8;">I<br><small>Informed</small></th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php foreach ($active_roles as $r):
                                                $my_levels = $current_rasci[$r['role_code']] ?? [];
                                            ?>
                                                <tr>
                                                    <td>
                                                        <span class="badge badge-<?= e($r['badge_color']) ?>" style="font-size:11px;">
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
                                                                   class="rasci-checkbox"
                                                                   data-role="<?= e($r['role_code']) ?>"
                                                                   data-level="<?= $lvl ?>"
                                                                   <?= in_array($lvl, $my_levels) ? 'checked' : '' ?>
                                                                   <?= canAdminister() ? '' : 'disabled' ?>>
                                                        </td>
                                                    <?php endforeach; ?>
                                                </tr>
                                            <?php endforeach; ?>
                                        </tbody>
                                        <tfoot>
                                            <tr class="bg-light">
                                                <td><strong>Total per level:</strong></td>
                                                <td class="text-center"><span id="total-A">0</span> A</td>
                                                <td class="text-center"><span id="total-R">0</span> R</td>
                                                <td class="text-center"><span id="total-S">0</span> S</td>
                                                <td class="text-center"><span id="total-C">0</span> C</td>
                                                <td class="text-center"><span id="total-I">0</span> I</td>
                                            </tr>
                                        </tfoot>
                                    </table>

                                    <div id="rasci-warning" class="alert alert-warning py-2" style="display:none; font-size:12px;">
                                        <i class="fas fa-exclamation-triangle"></i>
                                        <span id="warning-text"></span>
                                    </div>

                                    <?php if (canAdminister()): ?>
                                    <button type="submit" class="btn btn-primary">
                                        <i class="fas fa-save"></i> Simpan Mapping
                                    </button>
                                    <a href="?tab=rasci_element" class="btn btn-secondary">Batal</a>
                                    <?php endif; ?>
                                </form>
                            <?php else: ?>
                                <div class="alert alert-info">Pilih salah satu elemen di sebelah kiri.</div>
                            <?php endif; ?>
                        </div>
                    </div>

                <!-- ================================================== -->
                <!-- TAB 3: RASCI PER-PERTANYAAN (redirect)              -->
                <!-- ================================================== -->
                <?php elseif ($current_tab === 'rasci_question'): ?>

                    <div class="alert alert-info">
                        <h5><i class="fas fa-info-circle"></i> RASCI Per-Pertanyaan</h5>
                        <p>
                            Override mapping RASCI pada pertanyaan tertentu dalam sebuah elemen
                            (misal untuk elemen heterogen seperti Integritas Aset).
                        </p>
                        <a href="<?= BASE_URL ?>pages/question_rasci.php" class="btn btn-primary">
                            <i class="fas fa-external-link-alt"></i> Buka Halaman Edit RASCI per-Pertanyaan
                        </a>
                    </div>

                <!-- ================================================== -->
                <!-- TAB 4: ASSIGN USER ROLE                             -->
                <!-- ================================================== -->
                <?php elseif ($current_tab === 'users'): ?>

                    <p class="text-muted" style="font-size:13px;">
                        Assign role PSAIMS untuk tiap user. Admin tidak perlu role PSAIMS karena otomatis punya akses penuh.
                    </p>

                    <div class="table-responsive">
                        <table class="table table-bordered table-hover table-sm">
                            <thead class="bg-light">
                                <tr>
                                    <th style="width:40px;">#</th>
                                    <th>Username</th>
                                    <th>Nama Lengkap</th>
                                    <th>Email</th>
                                    <th style="width:90px;">System Role</th>
                                    <th style="width:200px;">PSAIMS Role</th>
                                    <th class="text-center" style="width:80px;">Aksi</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($all_users as $i => $u): ?>
                                    <tr class="<?= !$u['is_active'] ? 'text-muted' : '' ?>">
                                        <td><?= $i + 1 ?></td>
                                        <td><code><?= e($u['username']) ?></code></td>
                                        <td><?= e($u['full_name']) ?></td>
                                        <td style="font-size:12px;"><?= e($u['email'] ?? '—') ?></td>
                                        <td>
                                            <?php if ($u['role'] === 'admin'): ?>
                                                <span class="badge badge-danger">Admin</span>
                                            <?php else: ?>
                                                <span class="badge badge-light">User</span>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <form method="POST" class="form-inline form-assign-role">
                                                <input type="hidden" name="action" value="assign_user_role">
                                                <input type="hidden" name="user_id" value="<?= $u['id'] ?>">
                                                <select name="new_role_code" class="form-control form-control-sm"
                                                        style="font-size:12px; width:100%;"
                                                        <?= ($u['role'] === 'admin' || !canAdminister()) ? 'disabled' : '' ?>>
                                                    <option value="">— Tidak ada —</option>
                                                    <?php foreach ($active_roles as $r): ?>
                                                        <option value="<?= e($r['role_code']) ?>"
                                                                <?= $u['psaims_role_code'] === $r['role_code'] ? 'selected' : '' ?>>
                                                            <?= e($r['role_code']) ?> — <?= e($r['role_name']) ?>
                                                        </option>
                                                    <?php endforeach; ?>
                                                </select>
                                            </form>
                                        </td>
                                        <td class="text-center">
                                            <?php if ($u['role'] !== 'admin' && canAdminister()): ?>
                                                <button class="btn btn-xs btn-primary btn-save-role"
                                                        data-user-id="<?= $u['id'] ?>">
                                                    <i class="fas fa-save"></i>
                                                </button>
                                            <?php elseif ($u['role'] === 'admin'): ?>
                                                <small class="text-muted">Admin</small>
                                            <?php else: ?>
                                                <small class="text-muted">—</small>
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

        </div>
    </section>
</div>

<!-- ============ MODAL: Create Role ============ -->
<div class="modal fade" id="modalCreateRole" tabindex="-1">
    <div class="modal-dialog">
        <form method="POST">
            <div class="modal-content">
                <div class="modal-header bg-success">
                    <h5 class="modal-title text-white"><i class="fas fa-plus"></i> Tambah Role Baru</h5>
                    <button type="button" class="close text-white" data-dismiss="modal">×</button>
                </div>
                <div class="modal-body">
                    <input type="hidden" name="action" value="create_role">

                    <div class="form-group">
                        <label>Kode Role <span class="text-danger">*</span></label>
                        <input type="text" name="role_code" class="form-control text-uppercase"
                               required maxlength="20" pattern="[A-Za-z0-9_]{2,20}"
                               placeholder="Contoh: PROD, MARKETING">
                        <small class="text-muted">2-20 karakter, huruf/angka/underscore saja. Otomatis kapital.</small>
                    </div>

                    <div class="form-group">
                        <label>Nama Role <span class="text-danger">*</span></label>
                        <input type="text" name="role_name" class="form-control" required maxlength="100"
                               placeholder="Contoh: Production Engineering">
                    </div>

                    <div class="form-group">
                        <label>Deskripsi</label>
                        <textarea name="description" class="form-control" rows="2"
                                  placeholder="Scope &amp; tanggung jawab role ini"></textarea>
                    </div>

                    <div class="form-group">
                        <label>Warna Badge</label>
                        <div class="row">
                            <?php foreach ($available_colors as $code => $cfg): ?>
                                <div class="col-md-4 col-6 mb-2">
                                    <label style="cursor:pointer; font-weight:normal;">
                                        <input type="radio" name="badge_color" value="<?= $code ?>"
                                               <?= $code === 'secondary' ? 'checked' : '' ?>>
                                        <span style="display:inline-block; width:14px; height:14px;
                                                     background:<?= $cfg['hex'] ?>;
                                                     border-radius:3px; vertical-align:middle;"></span>
                                        <small><?= e($cfg['label']) ?></small>
                                    </label>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-dismiss="modal">Batal</button>
                    <button type="submit" class="btn btn-success">
                        <i class="fas fa-plus"></i> Tambah Role
                    </button>
                </div>
            </div>
        </form>
    </div>
</div>

<!-- ============ MODAL: Edit Role ============ -->
<div class="modal fade" id="modalEditRole" tabindex="-1">
    <div class="modal-dialog">
        <form method="POST">
            <div class="modal-content">
                <div class="modal-header bg-primary">
                    <h5 class="modal-title text-white"><i class="fas fa-edit"></i> Edit Role
                        <span id="edit-code-label" class="ml-2"></span>
                    </h5>
                    <button type="button" class="close text-white" data-dismiss="modal">×</button>
                </div>
                <div class="modal-body">
                    <input type="hidden" name="action" value="update_role">
                    <input type="hidden" name="role_id" id="edit-role-id">

                    <div class="alert alert-light" style="font-size:12px;">
                        <i class="fas fa-info-circle text-info"></i>
                        Kode role tidak bisa diubah untuk menjaga integritas referensi.
                    </div>

                    <div class="form-group">
                        <label>Nama Role <span class="text-danger">*</span></label>
                        <input type="text" name="role_name" id="edit-role-name" class="form-control" required>
                    </div>

                    <div class="form-group">
                        <label>Deskripsi</label>
                        <textarea name="description" id="edit-description" class="form-control" rows="2"></textarea>
                    </div>

                    <div class="form-group">
                        <label>Warna Badge</label>
                        <div class="row" id="edit-color-picker">
                            <?php foreach ($available_colors as $code => $cfg): ?>
                                <div class="col-md-4 col-6 mb-2">
                                    <label style="cursor:pointer; font-weight:normal;">
                                        <input type="radio" name="badge_color" value="<?= $code ?>">
                                        <span style="display:inline-block; width:14px; height:14px;
                                                     background:<?= $cfg['hex'] ?>;
                                                     border-radius:3px; vertical-align:middle;"></span>
                                        <small><?= e($cfg['label']) ?></small>
                                    </label>
                                </div>
                            <?php endforeach; ?>
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

<script>
$(document).ready(function() {

    // ============ Edit Role ============
    $('.btn-edit-role').on('click', function() {
        $('#edit-role-id').val($(this).data('id'));
        $('#edit-code-label').text($(this).data('code'));
        $('#edit-role-name').val($(this).data('name'));
        $('#edit-description').val($(this).data('desc'));
        $('#edit-color-picker input[name=badge_color]')
            .filter('[value="' + $(this).data('color') + '"]').prop('checked', true);
        $('#modalEditRole').modal('show');
    });

    // ============ Save role button (tab 4) ============
    $('.btn-save-role').on('click', function() {
        $(this).closest('tr').find('form.form-assign-role').submit();
    });

    // ============ RASCI Element — hitung total & tampilkan warning ============
    function updateRasciTotals() {
        const totals = {A:0, R:0, S:0, C:0, I:0};
        $('.rasci-checkbox:checked').each(function() {
            totals[$(this).data('level')]++;
        });
        Object.keys(totals).forEach(lvl => {
            $('#total-' + lvl).text(totals[lvl]);
        });

        // Warning
        const warnings = [];
        if (totals.A !== 1) warnings.push('Accountable harus tepat 1 (saat ini: ' + totals.A + ')');
        if (totals.R < 1)   warnings.push('Responsible minimal 1 (saat ini: ' + totals.R + ')');

        if (warnings.length > 0) {
            $('#warning-text').text(warnings.join(' · '));
            $('#rasci-warning').slideDown(150);
        } else {
            $('#rasci-warning').slideUp(150);
        }
    }
    $('.rasci-checkbox').on('change', updateRasciTotals);
    if ($('.rasci-checkbox').length > 0) updateRasciTotals();

    // Konfirm submit kalau ada warning
    $('#formElementRasci').on('submit', function(e) {
        if ($('#rasci-warning').is(':visible')) {
            if (!confirm('Mapping tidak memenuhi aturan RASCI (A harus 1, R minimal 1).\n\nYakin tetap simpan?')) {
                e.preventDefault();
            }
        }
    });

});
</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>