<?php
require_once __DIR__ . '/../config/config.php';
requireLogin();

if (!canViewAdmin()) {
    die('Akses ditolak.');
}

$roles = $pdo->query("SELECT * FROM psaims_roles ORDER BY id")->fetchAll();

$elements = $pdo->query(
    "SELECT * FROM psaims_elements WHERE is_active = TRUE ORDER BY element_number"
)->fetchAll();

// Fetch mapping dengan aggregate multi-level
$mappings = $pdo->query(
    "SELECT e.element_number,
            r.role_code,
            STRING_AGG(m.responsibility, '/' ORDER BY
                CASE m.responsibility
                    WHEN 'A' THEN 1 WHEN 'R' THEN 2
                    WHEN 'S' THEN 3 WHEN 'C' THEN 4 WHEN 'I' THEN 5
                END
            ) AS levels
     FROM element_role_mapping m
     JOIN psaims_roles r    ON r.id = m.role_id
     JOIN psaims_elements e ON e.id = m.element_id
     GROUP BY e.element_number, r.role_code"
)->fetchAll();

// Susun jadi [elem_num][role_code] = 'A/R' atau 'R' atau 'S' dll
$matrix = [];
foreach ($mappings as $m) {
    $matrix[$m['element_number']][$m['role_code']] = $m['levels'];
}

$level_config = [
    'A' => ['danger',    'Accountable',  'Approver akhir'],
    'R' => ['success',   'Responsible',  'Pengisi utama'],
    'S' => ['info',      'Support',      'Bantu data & evidence'],
    'C' => ['warning',   'Consulted',    'Review & komentar'],
    'I' => ['secondary', 'Informed',     'Read-only / view only'],
];

// Helper: render level badge (handle multi-level A/R)
function renderLevel($levels, $level_config) {
    if (!$levels) return '<span class="text-muted">—</span>';
    $parts = explode('/', $levels);
    $html = '';
    foreach ($parts as $lvl) {
        if (isset($level_config[$lvl])) {
            $html .= '<span class="badge badge-' . $level_config[$lvl][0] . '" ' .
                     'style="font-size:11px; padding:3px 6px; margin:1px;" ' .
                     'title="' . $level_config[$lvl][1] . '">' . $lvl . '</span>';
        }
    }
    return $html;
}

$page_title = 'RASCI Matrix';
require_once __DIR__ . '/../includes/header.php';
require_once __DIR__ . '/../includes/sidebar.php';
?>

<div class="content-wrapper">
    <section class="content-header">
        <div class="container-fluid">
            <h1><i class="fas fa-sitemap"></i> RASCI Matrix — PSAIMS</h1>
            <small class="text-muted">
                Mapping tanggung jawab 5 role untuk 18 elemen assessment
            </small>
        </div>
    </section>

    <section class="content">
        <div class="container-fluid">

            <?= readOnlyBanner() ?>

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
                    <div class="mt-2">
                        <small class="text-info">
                            <i class="fas fa-info-circle"></i>
                            Sel yang bertanda <strong>A/R</strong> = role tersebut memegang dua peran sekaligus
                            (Accountable dan Responsible).
                        </small>
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
                                        <th style="width:90px;" title="<?= e($r['role_name']) ?>">
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
                                        $levels = $matrix[$el['element_number']][$r['role_code']] ?? null;
                                    ?>
                                        <td class="text-center">
                                            <?= renderLevel($levels, $level_config) ?>
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
                                <br>
                                <em class="text-muted"><?= e($r['description']) ?></em>
                            </li>
                        <?php endforeach; ?>
                    </ul>
                </div>
            </div>

        </div>
    </section>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>