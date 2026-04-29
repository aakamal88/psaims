<?php
require_once __DIR__ . '/../config/config.php';
requireLogin();

// =====================================================
// Gap Analysis — List persyaratan dengan skor rendah
// =====================================================

// ============ FILTERS ============
$session_id   = isset($_GET['session'])  ? (int)$_GET['session']  : 0;
$element_num  = isset($_GET['element'])  ? (int)$_GET['element']  : 0;
$severity     = $_GET['severity'] ?? 'all';   // all, critical, high, medium
$max_score    = isset($_GET['max_score']) ? (int)$_GET['max_score'] : 75;
$has_gap_only = isset($_GET['has_gap']);

// Sessions untuk dropdown
$sessions = $pdo->query(
    "SELECT id, session_name, status
     FROM assessment_sessions
     ORDER BY session_year DESC, id DESC"
)->fetchAll();

// Auto-pick session
if (!$session_id && !empty($sessions)) {
    foreach ($sessions as $s) {
        if ($s['status'] === 'ongoing') { $session_id = $s['id']; break; }
    }
    if (!$session_id) $session_id = $sessions[0]['id'];
}

$current_session = null;
foreach ($sessions as $s) {
    if ($s['id'] == $session_id) { $current_session = $s; break; }
}

// Elements untuk dropdown filter
$elements = $pdo->query(
    "SELECT element_number, element_name, icon, color
     FROM psaims_elements
     WHERE is_active = TRUE
     ORDER BY element_number"
)->fetchAll();

// ============ QUERY GAP DATA ============
$gaps = [];
$stats = ['total_gap' => 0, 'critical' => 0, 'high' => 0, 'medium' => 0];

if ($session_id) {
    $sql = "SELECT
                e.element_number,
                e.element_name,
                e.icon,
                e.color,
                q.id AS question_id,
                q.question_number,
                q.criteria,
                ar.id AS result_id,
                ar.score,
                ar.evidence,
                ar.gap_analysis,
                ar.action_plan,
                ar.target_date,
                ar.responsible_person,
                ar.updated_at,
                u.full_name AS filled_by,
                u.username AS filled_username
            FROM assessment_results ar
            JOIN assessment_questions q ON q.id = ar.question_id
            JOIN psaims_elements e ON e.id = q.element_id
            LEFT JOIN users u ON u.id = ar.user_id
            WHERE ar.session_id = ?
              AND ar.score <= ?
              AND e.is_active = TRUE
              AND q.is_active = TRUE";

    $params = [$session_id, $max_score];

    if ($element_num > 0) {
        $sql .= " AND e.element_number = ?";
        $params[] = $element_num;
    }

    if ($severity === 'critical') {
        $sql .= " AND ar.score <= 25";
    } elseif ($severity === 'high') {
        $sql .= " AND ar.score > 25 AND ar.score <= 50";
    } elseif ($severity === 'medium') {
        $sql .= " AND ar.score > 50 AND ar.score <= 75";
    }

    if ($has_gap_only) {
        $sql .= " AND ar.gap_analysis IS NOT NULL AND TRIM(ar.gap_analysis) != ''";
    }

    $sql .= " ORDER BY ar.score ASC, e.element_number, q.question_number";

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $gaps = $stmt->fetchAll();

    // Hitung statistik (tanpa filter severity - total semua gap)
    $stmt_stats = $pdo->prepare(
        "SELECT
            COUNT(*) FILTER (WHERE ar.score <= 75) AS total_gap,
            COUNT(*) FILTER (WHERE ar.score <= 25) AS critical,
            COUNT(*) FILTER (WHERE ar.score > 25 AND ar.score <= 50) AS high,
            COUNT(*) FILTER (WHERE ar.score > 50 AND ar.score <= 75) AS medium
         FROM assessment_results ar
         WHERE ar.session_id = ?"
    );
    $stmt_stats->execute([$session_id]);
    $stats = $stmt_stats->fetch();
}

// Helper untuk severity badge
function severityBadge($score) {
    if ($score <= 25) return ['danger', 'Critical', '#F8D7DA', '#721C24'];
    if ($score <= 50) return ['warning', 'High', '#FFF3CD', '#856404'];
    if ($score <= 75) return ['info', 'Medium', '#D1ECF1', '#0C5460'];
    return ['success', 'Low', '#D4EDDA', '#155724'];
}

// Export Excel handler
if (isset($_GET['export']) && $_GET['export'] === 'excel') {
    header('Content-Type: application/vnd.ms-excel; charset=utf-8');
    header('Content-Disposition: attachment; filename="gap_analysis_' . date('Ymd_His') . '.xls"');
    header('Cache-Control: max-age=0');
    echo "\xEF\xBB\xBF"; // BOM for UTF-8 in Excel

    echo "<table border='1'>";
    echo "<tr style='background:#1e3c72; color:white; font-weight:bold;'>";
    echo "<th>No</th><th>Elemen</th><th>Ref</th><th>Persyaratan</th>";
    echo "<th>Skor</th><th>Severity</th><th>Evidence</th><th>Gap Analysis</th>";
    echo "<th>Action Plan</th><th>Target Date</th><th>PIC</th><th>Diisi Oleh</th>";
    echo "</tr>";

    foreach ($gaps as $i => $g) {
        $ref = '';
        if (preg_match('/\[Ref\s+([0-9.a-z]+)\]/i', $g['criteria'], $m)) $ref = $m[1];
        [$sev_color, $sev_label] = severityBadge($g['score']);
        echo "<tr>";
        echo "<td>" . ($i + 1) . "</td>";
        echo "<td>E" . str_pad($g['element_number'], 2, '0', STR_PAD_LEFT) . " " . e($g['element_name']) . "</td>";
        echo "<td>" . e($ref) . "</td>";
        echo "<td>" . e(strip_tags($g['criteria'])) . "</td>";
        echo "<td>" . $g['score'] . "%</td>";
        echo "<td>" . $sev_label . "</td>";
        echo "<td>" . e($g['evidence'] ?? '') . "</td>";
        echo "<td>" . e($g['gap_analysis'] ?? '') . "</td>";
        echo "<td>" . e($g['action_plan'] ?? '') . "</td>";
        echo "<td>" . ($g['target_date'] ? date('d/m/Y', strtotime($g['target_date'])) : '') . "</td>";
        echo "<td>" . e($g['responsible_person'] ?? '') . "</td>";
        echo "<td>" . e($g['filled_by'] ?? '') . "</td>";
        echo "</tr>";
    }
    echo "</table>";
    exit;
}

$page_title = 'Gap Analysis';
require_once __DIR__ . '/../includes/header.php';
require_once __DIR__ . '/../includes/sidebar.php';
?>

<div class="content-wrapper">
    <section class="content-header">
        <div class="container-fluid">
            <div class="row align-items-center">
                <div class="col-sm-7">
                    <h1><i class="fas fa-search text-warning"></i> Gap Analysis</h1>
                    <small class="text-muted">Identifikasi persyaratan dengan skor di bawah target</small>
                </div>
                <div class="col-sm-5 text-right">
                    <?php if (!empty($gaps)): ?>
                        <a href="?<?= http_build_query(array_merge($_GET, ['export' => 'excel'])) ?>"
                           class="btn btn-success btn-sm">
                            <i class="fas fa-file-excel"></i> Export Excel
                        </a>
                    <?php endif; ?>
                    <button class="btn btn-outline-secondary btn-sm" onclick="window.print()">
                        <i class="fas fa-print"></i> Print
                    </button>
                </div>
            </div>
        </div>
    </section>

    <section class="content">
        <div class="container-fluid">

            <?php if (!$current_session): ?>
                <div class="alert alert-info">
                    <h5><i class="fas fa-info-circle"></i> Belum ada periode assessment</h5>
                    <p class="mb-0">Buat periode assessment dulu untuk bisa melihat gap analysis.</p>
                </div>
            <?php else: ?>

                <!-- ============ FILTER BAR ============ -->
                <div class="card">
                    <div class="card-header py-2">
                        <h6 class="card-title mb-0"><i class="fas fa-filter"></i> Filter</h6>
                    </div>
                    <div class="card-body py-2">
                        <form method="GET" class="form-inline">
                            <div class="form-group mr-3 mb-2">
                                <label class="mr-2" style="font-size:12px;">Periode:</label>
                                <select name="session" class="form-control form-control-sm">
                                    <?php foreach ($sessions as $s): ?>
                                        <option value="<?= $s['id'] ?>" <?= $s['id'] == $session_id ? 'selected' : '' ?>>
                                            <?= e($s['session_name']) ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>

                            <div class="form-group mr-3 mb-2">
                                <label class="mr-2" style="font-size:12px;">Elemen:</label>
                                <select name="element" class="form-control form-control-sm">
                                    <option value="0">Semua elemen</option>
                                    <?php foreach ($elements as $el): ?>
                                        <option value="<?= $el['element_number'] ?>"
                                                <?= $el['element_number'] == $element_num ? 'selected' : '' ?>>
                                            E<?= str_pad($el['element_number'], 2, '0', STR_PAD_LEFT) ?> — <?= e(mb_strimwidth($el['element_name'], 0, 30, '…')) ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>

                            <div class="form-group mr-3 mb-2">
                                <label class="mr-2" style="font-size:12px;">Severity:</label>
                                <select name="severity" class="form-control form-control-sm">
                                    <option value="all"      <?= $severity === 'all'      ? 'selected' : '' ?>>Semua</option>
                                    <option value="critical" <?= $severity === 'critical' ? 'selected' : '' ?>>Critical (≤25%)</option>
                                    <option value="high"     <?= $severity === 'high'     ? 'selected' : '' ?>>High (26-50%)</option>
                                    <option value="medium"   <?= $severity === 'medium'   ? 'selected' : '' ?>>Medium (51-75%)</option>
                                </select>
                            </div>

                            <div class="form-group mr-3 mb-2">
                                <label class="mr-2" style="font-size:12px;">Max Skor:</label>
                                <input type="number" name="max_score" value="<?= $max_score ?>"
                                       min="0" max="100" step="5"
                                       class="form-control form-control-sm" style="width:70px;">
                                <small class="text-muted ml-1">%</small>
                            </div>

                            <div class="form-group mr-3 mb-2">
                                <div class="form-check">
                                    <input type="checkbox" name="has_gap" value="1" id="has_gap"
                                           class="form-check-input" <?= $has_gap_only ? 'checked' : '' ?>>
                                    <label class="form-check-label" for="has_gap" style="font-size:12px;">
                                        Hanya yang ada gap description
                                    </label>
                                </div>
                            </div>

                            <button type="submit" class="btn btn-primary btn-sm mb-2">
                                <i class="fas fa-search"></i> Terapkan
                            </button>
                            <a href="<?= BASE_URL ?>pages/report_gap.php" class="btn btn-outline-secondary btn-sm mb-2 ml-1">
                                Reset
                            </a>
                        </form>
                    </div>
                </div>

                <!-- ============ STATS CARDS ============ -->
                <div class="row">
                    <div class="col-md-3">
                        <div class="small-box" style="background:#E9ECEF;">
                            <div class="inner">
                                <h3 style="color:#495057;"><?= $stats['total_gap'] ?></h3>
                                <p style="color:#495057;">Total Gap (≤75%)</p>
                            </div>
                            <div class="icon"><i class="fas fa-exclamation-circle" style="color:#495057;"></i></div>
                        </div>
                    </div>
                    <div class="col-md-3">
                        <div class="small-box bg-danger">
                            <div class="inner">
                                <h3><?= $stats['critical'] ?></h3>
                                <p>Critical (≤25%)</p>
                            </div>
                            <div class="icon"><i class="fas fa-fire"></i></div>
                        </div>
                    </div>
                    <div class="col-md-3">
                        <div class="small-box bg-warning">
                            <div class="inner">
                                <h3><?= $stats['high'] ?></h3>
                                <p>High (26-50%)</p>
                            </div>
                            <div class="icon"><i class="fas fa-exclamation-triangle"></i></div>
                        </div>
                    </div>
                    <div class="col-md-3">
                        <div class="small-box bg-info">
                            <div class="inner">
                                <h3><?= $stats['medium'] ?></h3>
                                <p>Medium (51-75%)</p>
                            </div>
                            <div class="icon"><i class="fas fa-exclamation"></i></div>
                        </div>
                    </div>
                </div>

                <!-- ============ RESULTS ============ -->
                <div class="card">
                    <div class="card-header">
                        <h5 class="card-title mb-0">
                            <i class="fas fa-list"></i> Daftar Gap
                            <span class="badge badge-secondary ml-2"><?= count($gaps) ?> persyaratan</span>
                        </h5>
                        <div class="card-tools">
                            <div class="btn-group btn-group-sm" role="group">
                                <button type="button" class="btn btn-outline-secondary active" id="btn-view-table">
                                    <i class="fas fa-table"></i> Tabel
                                </button>
                                <button type="button" class="btn btn-outline-secondary" id="btn-view-card">
                                    <i class="fas fa-th-large"></i> Card
                                </button>
                            </div>
                        </div>
                    </div>

                    <div class="card-body p-0">
                        <?php if (empty($gaps)): ?>
                            <div class="text-center p-4">
                                <i class="fas fa-check-circle fa-3x text-success mb-2" style="opacity:0.5;"></i>
                                <p class="text-muted mb-0">
                                    <?php if ($stats['total_gap'] == 0): ?>
                                        Tidak ada gap dengan filter ini. 🎉
                                    <?php else: ?>
                                        Tidak ada gap yang sesuai dengan filter yang dipilih.
                                    <?php endif; ?>
                                </p>
                            </div>
                        <?php else: ?>

                            <!-- VIEW TABEL -->
                            <div id="view-table">
                                <div class="table-responsive">
                                    <table class="table table-hover table-sm mb-0">
                                        <thead class="bg-light">
                                            <tr>
                                                <th style="width:40px;">#</th>
                                                <th style="width:90px;">Severity</th>
                                                <th style="width:70px;">Skor</th>
                                                <th>Elemen &amp; Persyaratan</th>
                                                <th style="width:90px;">PIC</th>
                                                <th style="width:90px;">Target</th>
                                                <th style="width:70px;">Aksi</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php foreach ($gaps as $i => $g):
                                                [$sev_color, $sev_label, $sev_bg, $sev_fg] = severityBadge($g['score']);
                                                $ref = '';
                                                if (preg_match('/\[Ref\s+([0-9.a-z]+)\]/i', $g['criteria'], $mm)) {
                                                    $ref = $mm[1];
                                                }
                                                $preview = mb_substr(strip_tags($g['criteria']), 0, 150);
                                            ?>
                                                <tr>
                                                    <td class="text-center"><?= $i + 1 ?></td>
                                                    <td>
                                                        <span class="badge badge-<?= $sev_color ?>"><?= $sev_label ?></span>
                                                    </td>
                                                    <td class="text-center">
                                                        <span style="background:<?= $sev_bg ?>; color:<?= $sev_fg ?>;
                                                                     padding:3px 10px; border-radius:3px; font-weight:500;">
                                                            <?= $g['score'] ?>%
                                                        </span>
                                                    </td>
                                                    <td>
                                                        <div style="font-size:11px; color:#6c757d;">
                                                            <i class="<?= e($g['icon']) ?> text-<?= e($g['color']) ?>"></i>
                                                            <strong>E<?= str_pad($g['element_number'], 2, '0', STR_PAD_LEFT) ?></strong>
                                                            <?= e($g['element_name']) ?>
                                                            <?php if ($ref): ?>
                                                                · Ref <?= e($ref) ?>
                                                            <?php endif; ?>
                                                        </div>
                                                        <div><?= e($preview) ?>…</div>
                                                        <?php if (!empty($g['gap_analysis'])): ?>
                                                            <div class="mt-1 p-2" style="background:#FFF8E7; border-left:3px solid #ffc107; font-size:11px; border-radius:0 3px 3px 0;">
                                                                <strong>Gap:</strong> <?= e(mb_strimwidth($g['gap_analysis'], 0, 200, '…')) ?>
                                                            </div>
                                                        <?php endif; ?>
                                                        <?php if (!empty($g['action_plan'])): ?>
                                                            <div class="mt-1 p-2" style="background:#E7F5FF; border-left:3px solid #17a2b8; font-size:11px; border-radius:0 3px 3px 0;">
                                                                <strong>Action:</strong> <?= e(mb_strimwidth($g['action_plan'], 0, 200, '…')) ?>
                                                            </div>
                                                        <?php endif; ?>
                                                    </td>
                                                    <td>
                                                        <?php if ($g['responsible_person']): ?>
                                                            <small><?= e($g['responsible_person']) ?></small>
                                                        <?php else: ?>
                                                            <small class="text-muted">—</small>
                                                        <?php endif; ?>
                                                    </td>
                                                    <td>
                                                        <?php if ($g['target_date']):
                                                            $target = strtotime($g['target_date']);
                                                            $days = round(($target - time()) / 86400);
                                                            $target_color = $days < 0 ? 'danger' : ($days < 30 ? 'warning' : 'muted');
                                                        ?>
                                                            <small class="text-<?= $target_color ?>">
                                                                <?= date('d/m/Y', $target) ?>
                                                                <?php if ($days < 0): ?>
                                                                    <br><span style="font-size:10px;">Terlambat <?= abs($days) ?> hari</span>
                                                                <?php elseif ($days < 30): ?>
                                                                    <br><span style="font-size:10px;">Dalam <?= $days ?> hari</span>
                                                                <?php endif; ?>
                                                            </small>
                                                        <?php else: ?>
                                                            <small class="text-muted">—</small>
                                                        <?php endif; ?>
                                                    </td>
                                                    <td class="text-center">
                                                        <a href="<?= BASE_URL ?>pages/assessment.php?element=<?= $g['element_number'] ?>#q<?= $g['question_id'] ?>"
                                                           class="btn btn-xs btn-outline-primary" title="Lihat detail">
                                                            <i class="fas fa-external-link-alt"></i>
                                                        </a>
                                                    </td>
                                                </tr>
                                            <?php endforeach; ?>
                                        </tbody>
                                    </table>
                                </div>
                            </div>

                            <!-- VIEW CARD -->
                            <div id="view-card" style="display:none;" class="p-3">
                                <div class="row">
                                    <?php foreach ($gaps as $g):
                                        [$sev_color, $sev_label, $sev_bg, $sev_fg] = severityBadge($g['score']);
                                        $ref = '';
                                        if (preg_match('/\[Ref\s+([0-9.a-z]+)\]/i', $g['criteria'], $mm)) {
                                            $ref = $mm[1];
                                        }
                                    ?>
                                        <div class="col-md-6 mb-3">
                                            <div class="card card-outline card-<?= $sev_color ?> h-100">
                                                <div class="card-header py-2">
                                                    <div class="d-flex justify-content-between align-items-center">
                                                        <strong style="font-size:12px;">
                                                            E<?= str_pad($g['element_number'], 2, '0', STR_PAD_LEFT) ?>
                                                            <?php if ($ref): ?> · Ref <?= e($ref) ?><?php endif; ?>
                                                        </strong>
                                                        <div>
                                                            <span class="badge badge-<?= $sev_color ?>"><?= $sev_label ?></span>
                                                            <strong style="color:<?= $sev_fg ?>; font-size:16px;">
                                                                <?= $g['score'] ?>%
                                                            </strong>
                                                        </div>
                                                    </div>
                                                </div>
                                                <div class="card-body py-2" style="font-size:12px;">
                                                    <div style="color:#6c757d; margin-bottom:4px;">
                                                        <?= e($g['element_name']) ?>
                                                    </div>
                                                    <div><?= e(mb_strimwidth(strip_tags($g['criteria']), 0, 140, '…')) ?></div>

                                                    <?php if (!empty($g['gap_analysis'])): ?>
                                                        <div class="mt-2 p-2" style="background:#FFF8E7; border-left:3px solid #ffc107; border-radius:0 3px 3px 0;">
                                                            <strong>Gap:</strong> <?= e(mb_strimwidth($g['gap_analysis'], 0, 150, '…')) ?>
                                                        </div>
                                                    <?php endif; ?>
                                                </div>
                                                <div class="card-footer py-2" style="font-size:11px;">
                                                    <div class="row">
                                                        <div class="col-6">
                                                            <i class="fas fa-user"></i>
                                                            <?= $g['responsible_person'] ? e($g['responsible_person']) : '<span class="text-muted">PIC belum set</span>' ?>
                                                        </div>
                                                        <div class="col-6 text-right">
                                                            <?php if ($g['target_date']): ?>
                                                                <i class="fas fa-calendar"></i>
                                                                <?= date('d/m/Y', strtotime($g['target_date'])) ?>
                                                            <?php else: ?>
                                                                <span class="text-muted">Target belum set</span>
                                                            <?php endif; ?>
                                                        </div>
                                                    </div>
                                                </div>
                                            </div>
                                        </div>
                                    <?php endforeach; ?>
                                </div>
                            </div>

                        <?php endif; ?>
                    </div>
                </div>

            <?php endif; ?>

        </div>
    </section>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>

<script>
jQuery(function($) {
    // Toggle view table/card
    $('#btn-view-table').on('click', function() {
        $('#view-table').show();
        $('#view-card').hide();
        $(this).addClass('active');
        $('#btn-view-card').removeClass('active');
    });
    $('#btn-view-card').on('click', function() {
        $('#view-card').show();
        $('#view-table').hide();
        $(this).addClass('active');
        $('#btn-view-table').removeClass('active');
    });
});
</script>

<style>
@media print {
    .main-sidebar, .main-header, .main-footer, .card-tools,
    .content-header form, .content-header .text-right, #btn-view-card,
    .content-header .btn { display: none !important; }
    .content-wrapper { margin-left: 0 !important; }
    .card { page-break-inside: avoid; }
}
</style>