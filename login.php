<?php
require_once __DIR__ . '/config/config.php';

if (isLoggedIn()) {
    header('Location: ' . BASE_URL . 'index.php');
    exit;
}

$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = trim($_POST['username'] ?? '');
    $password = $_POST['password'] ?? '';

    if (empty($username) || empty($password)) {
        $error = 'Username dan password harus diisi';
    } else {
        try {
            $stmt = $pdo->prepare(
                "SELECT * FROM users WHERE username = ? AND is_active = TRUE"
            );
            $stmt->execute([$username]);
            $usr = $stmt->fetch();

            if ($usr && password_verify($password, $usr['password'])) {
                // Store ke session
                $_SESSION['user_id']          = $usr['id'];
                $_SESSION['username']         = $usr['username'];
                $_SESSION['full_name']        = $usr['full_name'];
                $_SESSION['role']             = $usr['role'];
                $_SESSION['email']            = $usr['email'];
                // Role PSAIMS untuk RBAC (null = admin/tidak dibatasi)
                $_SESSION['psaims_role_code'] = $usr['psaims_role_code'] ?? null;

                logActivity('LOGIN', 'User berhasil login');
                header('Location: ' . BASE_URL . 'index.php');
                exit;
            } else {
                $error = 'Username atau password salah';
            }
        } catch (Exception $ex) {
            $error = 'Terjadi kesalahan: ' . $ex->getMessage();
        }
    }
}
?>
<!DOCTYPE html>
<html lang="id">
<head>
<meta charset="utf-8">
<meta http-equiv="X-UA-Compatible" content="IE=edge">
<title>Login | <?= APP_NAME ?></title>
<meta name="viewport" content="width=device-width, initial-scale=1">
<link rel="stylesheet" href="<?= ADMINLTE_PATH ?>plugins/fontawesome-free/css/all.min.css">
<link rel="stylesheet" href="<?= ADMINLTE_PATH ?>plugins/icheck-bootstrap/icheck-bootstrap.min.css">
<link rel="stylesheet" href="<?= ADMINLTE_PATH ?>dist/css/adminlte.min.css">
<style>
body.login-page { background: linear-gradient(135deg,#1e3c72 0%,#2a5298 100%); min-height:100vh; }
.login-logo a   { color:#fff; font-weight:700; text-shadow:2px 2px 4px rgba(0,0,0,.3); }
.login-box      { width:420px; }
.card           { border-radius:12px; box-shadow:0 10px 30px rgba(0,0,0,.3); border:none; }
.card-header-psaims {
    background: linear-gradient(135deg,#f39c12 0%,#e67e22 100%);
    color:#fff; text-align:center; padding:25px; border-radius:12px 12px 0 0;
}
.card-header-psaims i { font-size:3rem; margin-bottom:10px; }
.btn-psaims {
    background:linear-gradient(135deg,#1e3c72 0%,#2a5298 100%);
    color:#fff; border:none; font-weight:600;
}
.btn-psaims:hover { opacity:.9; color:#fff; }
</style>
</head>
<body class="hold-transition login-page">

<div class="login-box">
    <div class="login-logo">
        <a href="#"><b>PSAIMS</b> Self Assessment Tool</a>
    </div>

    <div class="card">
        <div class="card-header-psaims">
            <i class="fas fa-shield-alt"></i>
            <h4 class="mb-0">Selamat Datang</h4>
            <small>Silakan login untuk melanjutkan</small>
        </div>
        <div class="card-body login-card-body" style="border-radius: 0 0 12px 12px;">

            <?php if ($error): ?>
                <div class="alert alert-danger alert-dismissible">
                    <i class="icon fas fa-ban"></i> <?= e($error) ?>
                </div>
            <?php endif; ?>

            <form action="login.php" method="post">
                <div class="input-group mb-3">
                    <input type="text" name="username" class="form-control"
                           placeholder="Username" required autofocus
                           value="<?= e($_POST['username'] ?? '') ?>">
                    <div class="input-group-append">
                        <div class="input-group-text"><span class="fas fa-user"></span></div>
                    </div>
                </div>
                <div class="input-group mb-3">
                    <input type="password" name="password" class="form-control"
                           placeholder="Password" required>
                    <div class="input-group-append">
                        <div class="input-group-text"><span class="fas fa-lock"></span></div>
                    </div>
                </div>
                <div class="row">
                    <div class="col-8">
                        <div class="icheck-primary">
                            <input type="checkbox" id="remember">
                            <label for="remember">Ingat saya</label>
                        </div>
                    </div>
                    <div class="col-4">
                        <button type="submit" class="btn btn-psaims btn-block">
                            <i class="fas fa-sign-in-alt"></i> Login
                        </button>
                    </div>
                </div>
            </form>

            <hr>
            <p class="mb-0 text-center text-muted" style="font-size: 11px;">
                <strong>User demo tersedia:</strong><br>
                admin / adminpsaims (semua akses)<br>
                user_hc, user_qm, user_hse, user_ops,<br>
                user_asset, user_tech, user_pse<br>
                <em>Password semua: admin1234</em>
            </p>
        </div>
    </div>
</div>

<script src="<?= ADMINLTE_PATH ?>plugins/jquery/jquery.min.js"></script>
<script src="<?= ADMINLTE_PATH ?>plugins/bootstrap/js/bootstrap.bundle.min.js"></script>
<script src="<?= ADMINLTE_PATH ?>dist/js/adminlte.min.js"></script>
</body>
</html>