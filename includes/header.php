<?php
require_once __DIR__ . '/../config/config.php';
requireLogin();
$user = currentUser();
$page_title = $page_title ?? 'Dashboard';

// Ambil role code user untuk ditampilkan di navbar
$nav_role_code = $_SESSION['psaims_role_code'] ?? null;
$nav_is_admin  = hasRole('admin');

// Ambil warna badge dari database (dinamis)
$nav_role_color = 'secondary';
$nav_role_name  = '';
if ($nav_role_code !== null && $nav_role_code !== '') {
    try {
        $stmt_role = $pdo->prepare(
            "SELECT COALESCE(badge_color, 'secondary') AS badge_color, role_name
             FROM psaims_roles WHERE role_code = ? LIMIT 1"
        );
        $stmt_role->execute([$nav_role_code]);
        $role_row = $stmt_role->fetch();
        if ($role_row) {
            $nav_role_color = $role_row['badge_color'];
            $nav_role_name  = $role_row['role_name'];
        }
    } catch (Exception $ex) {
        // Fallback: tetap 'secondary' kalau query gagal
    }
}
?>
<!DOCTYPE html>
<html lang="id">
<head>
<meta charset="utf-8">
<meta http-equiv="X-UA-Compatible" content="IE=edge">
<title><?= e($page_title) ?> | <?= APP_NAME ?></title>

<meta name="viewport" content="width=device-width, initial-scale=1">

<!-- Favicon -->
<link rel="icon" type="image/png" href="<?= ASSETS_PATH ?>img/favicon.png">

<!-- Google Font: Source Sans Pro -->
<link rel="stylesheet"
      href="https://fonts.googleapis.com/css?family=Source+Sans+Pro:300,400,400i,700&display=fallback">

<!-- Font Awesome -->
<link rel="stylesheet" href="<?= ADMINLTE_PATH ?>plugins/fontawesome-free/css/all.min.css">

<!-- Tempusdominus Bootstrap 4 -->
<link rel="stylesheet"
      href="<?= ADMINLTE_PATH ?>plugins/tempusdominus-bootstrap-4/css/tempusdominus-bootstrap-4.min.css">

<!-- iCheck -->
<link rel="stylesheet" href="<?= ADMINLTE_PATH ?>plugins/icheck-bootstrap/icheck-bootstrap.min.css">

<!-- JQVMap -->
<link rel="stylesheet" href="<?= ADMINLTE_PATH ?>plugins/jqvmap/jqvmap.min.css">

<!-- Theme style -->
<link rel="stylesheet" href="<?= ADMINLTE_PATH ?>dist/css/adminlte.min.css">

<!-- overlayScrollbars -->
<link rel="stylesheet"
      href="<?= ADMINLTE_PATH ?>plugins/overlayScrollbars/css/OverlayScrollbars.min.css">

<!-- Daterange picker -->
<link rel="stylesheet" href="<?= ADMINLTE_PATH ?>plugins/daterangepicker/daterangepicker.css">

<!-- Summernote -->
<link rel="stylesheet"
      href="<?= ADMINLTE_PATH ?>plugins/summernote/summernote-bs4.min.css">

<!-- DataTables -->
<link rel="stylesheet"
      href="<?= ADMINLTE_PATH ?>plugins/datatables-bs4/css/dataTables.bootstrap4.min.css">
<link rel="stylesheet"
      href="<?= ADMINLTE_PATH ?>plugins/datatables-responsive/css/responsive.bootstrap4.min.css">

<!-- Select2 -->
<link rel="stylesheet" href="<?= ADMINLTE_PATH ?>plugins/select2/css/select2.min.css">

<!-- Custom CSS -->
<link rel="stylesheet" href="<?= ASSETS_PATH ?>css/custom.css">

<style>
/* Header navbar custom */
.main-header.navbar {
    border-bottom: 2px solid #1e3c72;
    box-shadow: 0 2px 6px rgba(0,0,0,.06);
}
.navbar-page-title {
    font-size: 0.95rem;
    font-weight: 500;
    color: #1e3c72;
    margin: 0;
    padding: 0 6px;
    border-left: 2px solid #e9ecef;
}
.user-role-badge {
    font-size: 10px;
    padding: 3px 8px;
    margin-left: 6px;
    font-weight: 600;
    letter-spacing: 0.3px;
    vertical-align: middle;
}
.badge-purple { background: #6f42c1; color: #fff; }
.badge-pink   { background: #e83e8c; color: #fff; }
.badge-teal   { background: #20c997; color: #fff; }
.badge-indigo { background: #6610f2; color: #fff; }
.badge-orange { background: #fd7e14; color: #fff; }

/* Navbar user greeting */
.navbar-user-info {
    line-height: 1.2;
    text-align: right;
    padding-right: 4px;
}
.navbar-user-info .u-name {
    font-weight: 500;
    font-size: 13px;
    color: #1e3c72;
}
.navbar-user-info .u-meta {
    font-size: 10px;
    color: #6c757d;
}
</style>
</head>
<body class="hold-transition sidebar-mini layout-fixed">
<div class="wrapper">

<!-- Preloader -->
<div class="preloader flex-column justify-content-center align-items-center">
    <img class="animation__shake"
         src="<?= ADMINLTE_PATH ?>dist/img/AdminLTELogo.png"
         alt="PSAIMS Logo" height="60" width="60">
</div>

<!-- Navbar -->
<nav class="main-header navbar navbar-expand navbar-white navbar-light">
    <!-- Left navbar -->
    <ul class="navbar-nav">
        <li class="nav-item">
            <a class="nav-link" data-widget="pushmenu" href="#" role="button" title="Toggle sidebar">
                <i class="fas fa-bars"></i>
            </a>
        </li>
        <li class="nav-item d-none d-sm-inline-block">
            <a href="<?= BASE_URL ?>index.php" class="nav-link">
                <i class="fas fa-home"></i> Home
            </a>
        </li>
        <li class="nav-item d-none d-md-inline-block">
            <span class="navbar-page-title">
                <i class="fas fa-chevron-right mr-1" style="font-size:10px; opacity:.5;"></i>
                <?= e($page_title) ?>
            </span>
        </li>
    </ul>

    <!-- Right navbar -->
    <ul class="navbar-nav ml-auto">

        <!-- Info session aktif -->
        <li class="nav-item d-none d-lg-flex align-items-center mr-2">
            <?php
            try {
                $stmt_ses = $pdo->query(
                    "SELECT session_name FROM assessment_sessions
                     WHERE status = 'ongoing'
                     ORDER BY created_at DESC LIMIT 1"
                );
                $active_ses = $stmt_ses->fetch();
            } catch (Exception $ex) { $active_ses = null; }
            ?>
            <?php if ($active_ses): ?>
                <span class="badge badge-success" title="Periode assessment aktif"
                      style="font-size:10px; padding:4px 8px;">
                    <i class="fas fa-circle" style="font-size:6px; animation: pulse 1.5s infinite;"></i>
                    <?= e(mb_strimwidth($active_ses['session_name'], 0, 22, '…')) ?>
                </span>
            <?php else: ?>
                <span class="badge badge-secondary" style="font-size:10px; padding:4px 8px;">
                    <i class="fas fa-pause-circle"></i> Tidak ada periode aktif
                </span>
            <?php endif; ?>
        </li>

        <!-- User Dropdown -->
        <li class="nav-item dropdown">
            <a class="nav-link d-flex align-items-center" data-toggle="dropdown" href="#">
                <div class="navbar-user-info d-none d-md-block">
                    <div class="u-name">
                        <?= e($user['full_name']) ?>
                        <?php if ($nav_is_admin): ?>
                            <span class="badge badge-danger user-role-badge">ADMIN</span>
                        <?php elseif ($nav_role_code): ?>
                            <span class="badge badge-<?= e($nav_role_color) ?> user-role-badge"
                                  title="Role PSAIMS Anda">
                                <?= e($nav_role_code) ?>
                            </span>
                        <?php endif; ?>
                    </div>
                    <div class="u-meta"><?= e($user['email'] ?? '') ?></div>
                </div>
                <i class="fas fa-user-circle fa-lg ml-2" style="color:#1e3c72;"></i>
            </a>
            <div class="dropdown-menu dropdown-menu-right" style="min-width:240px;">
                <div class="dropdown-item-text">
                    <strong><?= e($user['full_name']) ?></strong>
                    <br>
                    <small class="text-muted"><?= e($user['email'] ?? '—') ?></small>
                    <br>
                    <?php if ($nav_is_admin): ?>
                        <span class="badge badge-danger mt-1">Administrator</span>
                        <small class="text-success d-block mt-1">
                            <i class="fas fa-check-circle"></i> Full access semua elemen
                        </small>
                    <?php elseif ($nav_role_code): ?>
                        <span class="badge badge-<?= e($nav_role_color) ?> mt-1">
                            Role: <?= e($nav_role_code) ?>
                        </span>
                    <?php endif; ?>
                </div>
                <div class="dropdown-divider"></div>
                <a href="<?= BASE_URL ?>pages/profile.php" class="dropdown-item">
                    <i class="fas fa-user mr-2"></i> Profil Saya
                </a>
                <?php if ($nav_is_admin): ?>
                    <a href="<?= BASE_URL ?>pages/rasci.php" class="dropdown-item">
                        <i class="fas fa-sitemap mr-2"></i> RASCI Matrix
                    </a>
                <?php endif; ?>
                <div class="dropdown-divider"></div>
                <a href="<?= BASE_URL ?>logout.php" class="dropdown-item text-danger">
                    <i class="fas fa-sign-out-alt mr-2"></i> Logout
                </a>
            </div>
        </li>
        <li class="nav-item">
            <a class="nav-link" data-widget="fullscreen" href="#" role="button" title="Fullscreen">
                <i class="fas fa-expand-arrows-alt"></i>
            </a>
        </li>
    </ul>
</nav>