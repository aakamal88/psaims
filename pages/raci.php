<?php
require_once __DIR__ . '/../config/config.php';
requireLogin();

if (!hasRole('admin')) {
    die('Akses ditolak.');
}

$roles = $pdo->query("SELECT * FROM psaims_roles ORDER BY id")->fetchAll();

$elements = $pdo->query(
    "SELECT * FROM psaims_elements WHERE is_active = TRUE ORDER BY element_number"
)->fetchAll();

$mappings = $pdo->query(
    "SELECT m.*, r.role_code, e.element_number
     FROM element_role_mapping m
     JOIN psaims_roles r    ON r.id = m.role_id
     JOIN psaims_elements e ON e.id = m.element_id"
)->fetchAll();

$matrix = [];
foreach ($mappings as $m) {
    $matrix[$m['element_number']][$m['role_code']] = $m['responsibility'];
}

// Konfigurasi warna per level
$level_config = [
    'A' => ['danger',    'Accountable',  'Approver akhir, pengisi final'],
    'R' => ['success',   'Responsible',  'Pengisi utama assessment'],
    'S' => ['info',      'Support',      'Bantu data & evidence'],
    'C' => ['warning',   'Consulted',    'Review & komentar sebelum submit'],
    'I' => ['secondary', 'Informed',     'Read-only, view hasil'],
];

$page_title = 'RASCI Matrix';
require_once __DIR__ . '/../includes/header.php';
require_once __DIR__ . '/../includes/sidebar.php';
?>

<div class="content-wrapper">
    <section class="content-header">
        <div class="container-fluid">
            <h1><i class="fas fa-sitemap"></i> RASCI Matrix — PSAIMS</h1>
            <small class="text-muted">Mapping tanggung jawab tiap role untuk 18 elemen assessment</small>
        </div>
    </section>

    <section class="content">
        <div class="container-fluid">

            <!-- Legenda -->
            <div class="card card-outline card-primary">
                <div class="card-header">
                    <h5 class="card-title mb-0"><i class="fas fa-book-open"></i> Legenda RASCI</h5>
                </div>
                <div class="card-body">
                    <div class="row">
                        <?php foreach ($level_config as $code => $cfg): ?>
                            <div class="col-md">
                                <div class="p-2 border rounded">
                                    <span class="badge badge-<?= $cfg[0] ?>" style="font-size:14px;">
                                        <?= $code ?>
                                    </span>
                                    <strong><?= $cfg[1] ?></strong>
                                    <br>
                                    <small class="text-muted"><?= $cfg[2] ?></small>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </div>
            </div>

            <!-- Matrix -->
            <div class="card">
                <div class="card-header bg-primary">
                    <h3 class="card-title text-white">18 Elemen × <?= count($roles) ?> Role</h3>
                </div>
                <div class="card-body p-0">
                    <div class="table-responsive">
                        <table class="table table-bordered table-hover table-sm mb-0">
                            <thead class="bg-light text-center">
                                <tr>
                                    <th style="width:38px;">#</th>
                                    <th style="text-align:left;">Nama Elemen</th>
                                    <?php foreach ($roles as $r): ?>
                                        <th style="width:85px;" title="<?= e($r['role_name']) ?>">
                                            <?= e($r['role_code']) ?>
                                        </th>
                                    <?php endforeach; ?>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($elements as $el): ?>
                                <tr>
                                    <td class="text-center"><strong><?= $el['element_number'] ?></strong></td>
                                    <td>
                                        <i class="<?= e($el['icon']) ?> text-<?= e($el['color']) ?>"></i>
                                        <?= e($el['element_name']) ?>
                                    </td>
                                    <?php foreach ($roles as $r):
                                        $resp = $matrix[$el['element_number']][$r['role_code']] ?? null;
                                        $cfg  = $resp ? $level_config[$resp] : null;
                                    ?>
                                        <td class="text-center">
                                            <?php if ($cfg): ?>
                                                <span class="badge badge-<?= $cfg[0] ?>"
                                                      title="<?= $cfg[1] ?>: <?= $cfg[2] ?>"
                                                      style="font-size:12px; padding:4px 8px;">
                                                    <?= $resp ?>
                                                </span>
                                            <?php else: ?>
                                                <span class="text-muted">—</span>
                                            <?php endif; ?>
                                        </td>
                                    <?php endforeach; ?>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
                <div class="card-footer">
                    <h6>Keterangan Role:</h6>
                    <ul class="mb-0" style="font-size:13px;">
                        <?php foreach ($roles as $r): ?>
                            <li>
                                <strong><?= e($r['role_code']) ?></strong> —
                                <?= e($r['role_name']) ?>
                                <em class="text-muted">(<?= e($r['description']) ?>)</em>
                            </li>
                        <?php endforeach; ?>
                    </ul>
                </div>
            </div>

        </div>
    </section>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>