<?php
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../includes/evidence.php';
requireLogin();

// Hanya admin & assessor yang bisa lihat semua
if (!canVerify()) {
    die('Akses ditolak. Halaman ini hanya untuk Administrator dan Assessor.');
}

$msg = ''; $msg_type = '';
$current_user_id = $_SESSION['user_id'] ?? null;

// ============ HANDLE ACTIONS ============
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        if (($_POST['action'] ?? '') === 'delete_file') {
            $file_id = (int)$_POST['file_id'];
            deleteEvidenceFile($file_id, $current_user_id);
            $msg = '✓ File berhasil dihapus.';
            $msg_type = 'success';
        }
    } catch (Exception $e) {
        $msg = 'Gagal: ' . $e->getMessage();
        $msg_type = 'danger';
    }
}

// ============ FILTERS ============
$filter_session = isset($_GET['session']) ? (int)$_GET['session'] : 0;
$filter_element = isset($_GET['element']) ? (int)$_GET['element'] : 0;
$filter_user    = isset($_GET['user'])    ? (int)$_GET['user']    : 0;
$filter_ext     = $_GET['ext'] ?? 'all';
$search         = trim($_GET['q'] ?? '');

// Sessions dropdown
$sessions = $pdo->query(
    "SELECT id, session_name, status FROM assessment_sessions
     ORDER BY session_year DESC, id DESC"
)->fetchAll();

if (!$filter_session && !empty($sessions)) {
    foreach ($sessions as $s) {
        if ($s['status'] === 'ongoing') { $filter_session = $s['id']; break; }
    }
    if (!$filter_session) $filter_session = $sessions[0]['id'];
}

// Elements dropdown
$elements = $pdo->query(
    "SELECT element_number, element_name FROM psaims_elements
     WHERE is_active = TRUE ORDER BY element_number"
)->fetchAll();

// Users dropdown
$users_list = $pdo->query(
    "SELECT id, username, full_name FROM users
     WHERE is_active = TRUE ORDER BY username"
)->fetchAll();

// Extensions unique
$exts = $pdo->query(
    "SELECT DISTINCT file_extension FROM evidence_files
     WHERE is_deleted = FALSE ORDER BY file_extension"
)->fetchAll();

// ============ QUERY ============
$sql = "SELECT * FROM v_evidence_files WHERE 1=1";
$params = [];

if ($filter_session > 0) {
    $sql .= " AND session_id = ?";
    $params[] = $filter_session;
}
if ($filter_element > 0) {
    $sql .= " AND element_number = ?";
    $params[] = $filter_element;
}
if ($filter_user > 0) {
    $sql .= " AND uploaded_by = ?";
    $params[] = $filter_user;
}
if ($filter_ext !== 'all') {
    $sql .= " AND file_extension = ?";
    $params[] = $filter_ext;
}
if ($search !== '') {
    $sql .= " AND LOWER(original_name) LIKE LOWER(?)";
    $params[] = "%{$search}%";
}

$sql .= " LIMIT 500";

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$files = $stmt->fetchAll();

// ============ STATS ============
$stats_sql = "SELECT
    COUNT(*) AS total_files,
    COALESCE(SUM(file_size), 0) AS total_size,
    COUNT(DISTINCT uploaded_by) AS total_uploaders,
    COUNT(DISTINCT question_id) AS total_questions
  FROM evidence_files WHERE is_deleted = FALSE";
$params_stats = [];
if ($filter_session > 0) {
    $stats_sql .= " AND session_id = ?";
    $params_stats[] = $filter_session;
}
$stmt_stats = $pdo->prepare($stats_sql);
$stmt_stats->execute($params_stats);
$stats = $stmt_stats->fetch();

$page_title = 'Evidence Files';
require_once __DIR__ . '/../includes/header.php';
require_once __DIR__ . '/../includes/sidebar.php';
?>

<div class="content-wrapper">
    <section class="content-header">
        <div class="container-fluid">
            <div class="row align-items-center">
                <div class="col-sm-7">
                    <h1><i class="fas fa-paperclip text-primary"></i> Evidence Files</h1>
                    <small class="text-muted">Daftar semua file lampiran evidence per pertanyaan</small>
                </div>
                <div class="col-sm-5 text-right">
                    <?php if (canAdminister()): ?>
                        <a href="<?= BASE_URL ?>pages/evidence_settings.php" class="btn btn-outline-secondary btn-sm">
                            <i class="fas fa-cog"></i> Settings
                        </a>
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

            <!-- STATS -->
            <div class="row">
                <div class="col-md-3 col-sm-6">
                    <div class="small-box bg-info">
                        <div class="inner"><h3><?= $stats['total_files'] ?></h3><p>Total File</p></div>
                        <div class="icon"><i class="fas fa-file"></i></div>
                    </div>
                </div>
                <div class="col-md-3 col-sm-6">
                    <div class="small-box bg-primary">
                        <div class="inner"><h3><?= formatFileSize($stats['total_size']) ?></h3><p>Total Size</p></div>
                        <div class="icon"><i class="fas fa-hdd"></i></div>
                    </div>
                </div>
                <div class="col-md-3 col-sm-6">
                    <div class="small-box bg-success">
                        <div class="inner"><h3><?= $stats['total_uploaders'] ?></h3><p>Uploaders</p></div>
                        <div class="icon"><i class="fas fa-users"></i></div>
                    </div>
                </div>
                <div class="col-md-3 col-sm-6">
                    <div class="small-box bg-warning">
                        <div class="inner"><h3><?= $stats['total_questions'] ?></h3><p>Pertanyaan</p></div>
                        <div class="icon"><i class="fas fa-question-circle"></i></div>
                    </div>
                </div>
            </div>

            <!-- FILTER -->
            <div class="card">
                <div class="card-body py-2">
                    <form method="GET" class="form-inline">
                        <div class="form-group mr-3 mb-2">
                            <label class="mr-1" style="font-size:12px;">Periode:</label>
                            <select name="session" class="form-control form-control-sm">
                                <?php foreach ($sessions as $s): ?>
                                    <option value="<?= $s['id'] ?>" <?= $s['id'] == $filter_session ? 'selected' : '' ?>>
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
                                    <option value="<?= $el['element_number'] ?>" <?= $el['element_number'] == $filter_element ? 'selected' : '' ?>>
                                        E<?= str_pad($el['element_number'], 2, '0', STR_PAD_LEFT) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="form-group mr-3 mb-2">
                            <label class="mr-1" style="font-size:12px;">Uploader:</label>
                            <select name="user" class="form-control form-control-sm">
                                <option value="0">Semua User</option>
                                <?php foreach ($users_list as $u): ?>
                                    <option value="<?= $u['id'] ?>" <?= $u['id'] == $filter_user ? 'selected' : '' ?>>
                                        <?= e($u['full_name']) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="form-group mr-3 mb-2">
                            <label class="mr-1" style="font-size:12px;">Type:</label>
                            <select name="ext" class="form-control form-control-sm">
                                <option value="all">Semua</option>
                                <?php foreach ($exts as $ex): ?>
                                    <option value="<?= e($ex['file_extension']) ?>" <?= $filter_ext === $ex['file_extension'] ? 'selected' : '' ?>>
                                        .<?= e($ex['file_extension']) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="form-group mr-3 mb-2">
                            <input type="text" name="q" value="<?= e($search) ?>"
                                   placeholder="Cari nama file..." class="form-control form-control-sm" style="width:180px;">
                        </div>
                        <button type="submit" class="btn btn-primary btn-sm mb-2">
                            <i class="fas fa-search"></i> Filter
                        </button>
                        <a href="<?= BASE_URL ?>pages/evidence_list.php" class="btn btn-outline-secondary btn-sm mb-2 ml-1">Reset</a>
                    </form>
                </div>
            </div>

            <!-- FILES TABLE -->
            <div class="card">
                <div class="card-header">
                    <h5 class="card-title mb-0">
                        <i class="fas fa-list"></i> Daftar File
                        <span class="badge badge-secondary ml-2"><?= count($files) ?></span>
                    </h5>
                </div>
                <div class="card-body p-0">
                    <?php if (empty($files)): ?>
                        <div class="text-center p-4 text-muted">
                            <i class="fas fa-folder-open fa-3x mb-2" style="opacity:0.3;"></i>
                            <p>Tidak ada file evidence sesuai filter.</p>
                        </div>
                    <?php else: ?>
                        <div class="table-responsive">
                            <table class="table table-hover mb-0" style="font-size:12px;">
                                <thead class="bg-light">
                                    <tr>
                                        <th style="width:40px;">#</th>
                                        <th style="width:40px;"></th>
                                        <th>Nama File</th>
                                        <th style="width:100px;">Elemen</th>
                                        <th style="width:80px;">Ukuran</th>
                                        <th style="width:140px;">Uploader</th>
                                        <th style="width:140px;">Tanggal</th>
                                        <th style="width:100px;">Aksi</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($files as $i => $f):
                                        [$icon, $color] = getFileIcon($f['file_extension']);
                                    ?>
                                        <tr>
                                            <td class="text-center"><?= $i + 1 ?></td>
                                            <td class="text-center">
                                                <i class="fas <?= $icon ?> text-<?= $color ?>" style="font-size:18px;"></i>
                                            </td>
                                            <td>
                                                <strong><?= e($f['original_name']) ?></strong>
                                                <br>
                                                <small class="text-muted">
                                                    .<?= e($f['file_extension']) ?>
                                                    <?php if ($f['question_number']): ?>
                                                        · Q#<?= $f['question_number'] ?>
                                                    <?php endif; ?>
                                                    <?php if ($f['score']): ?>
                                                        · Skor <?= $f['score'] ?>%
                                                    <?php endif; ?>
                                                </small>
                                                <?php if ($f['description']): ?>
                                                    <br>
                                                    <small class="text-info"><i class="fas fa-comment"></i> <?= e($f['description']) ?></small>
                                                <?php endif; ?>
                                            </td>
                                            <td>
                                                <?php if ($f['element_number']): ?>
                                                    <span class="badge badge-light">
                                                        E<?= str_pad($f['element_number'], 2, '0', STR_PAD_LEFT) ?>
                                                    </span>
                                                    <br>
                                                    <small><?= e(mb_strimwidth($f['element_name'], 0, 18, '…')) ?></small>
                                                <?php endif; ?>
                                            </td>
                                            <td><?= formatFileSize($f['file_size']) ?></td>
                                            <td>
                                                <?= e($f['uploaded_by_name'] ?? '?') ?>
                                                <br>
                                                <small class="text-muted"><?= e($f['uploaded_by_username']) ?></small>
                                            </td>
                                            <td style="font-size:11px;">
                                                <?= date('d/m/Y', strtotime($f['uploaded_at'])) ?>
                                                <br>
                                                <small class="text-muted"><?= date('H:i', strtotime($f['uploaded_at'])) ?></small>
                                            </td>
                                            <td>
                                                <a href="<?= BASE_URL ?>pages/download_evidence.php?id=<?= $f['id'] ?>"
                                                   target="_blank" class="btn btn-xs btn-outline-primary" title="Preview/Download">
                                                    <i class="fas fa-download"></i>
                                                </a>
                                                <?php if (canAdminister()): ?>
                                                    <form method="POST" class="d-inline"
                                                          onsubmit="return confirm('Hapus file <?= e($f['original_name']) ?>?\n\nFile akan dihapus dari disk dan DB.');">
                                                        <input type="hidden" name="action" value="delete_file">
                                                        <input type="hidden" name="file_id" value="<?= $f['id'] ?>">
                                                        <button type="submit" class="btn btn-xs btn-outline-danger" title="Hapus">
                                                            <i class="fas fa-trash"></i>
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

        </div>
    </section>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>