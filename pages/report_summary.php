<?php
require_once __DIR__ . '/../config/config.php';
requireLogin();

// =====================================================
// Ringkasan Assessment — Dashboard eksekutif
// =====================================================

// Filter session (default: session aktif / terbaru)
$session_id = isset($_GET['session']) ? (int)$_GET['session'] : 0;

// Ambil semua sessions untuk dropdown
$sessions = $pdo->query(
    "SELECT id, session_name, session_year, session_period, status,
            start_date, end_date
     FROM assessment_sessions
     ORDER BY session_year DESC, id DESC"
)->fetchAll();

// Auto-pick session aktif kalau belum dipilih
if (!$session_id && !empty($sessions)) {
    foreach ($sessions as $s) {
        if (in_array($s['status'], ['ongoing', 'completed'])) {
            $session_id = $s['id'];
            break;
        }
    }
    if (!$session_id) $session_id = $sessions[0]['id'];
}

$current_session = null;
foreach ($sessions as $s) {
    if ($s['id'] == $session_id) { $current_session = $s; break; }
}

// ============ QUERY: Skor per elemen ============
$element_scores = [];
$overall_stats = [
    'total_questions'  => 0,
    'answered'         => 0,
    'avg_score'        => 0,
    'score_sum'        => 0,
];

if ($session_id) {
    $stmt = $pdo->prepare(
        "SELECT
            e.id AS element_id,
            e.element_number,
            e.element_name,
            e.icon,
            e.color,
            COUNT(DISTINCT q.id) AS total_questions,
            COUNT(DISTINCT ar.question_id) AS answered,
            COALESCE(AVG(ar.score), 0)::numeric(5,2) AS avg_score,
            COALESCE(MIN(ar.score), 0) AS min_score,
            COALESCE(MAX(ar.score), 0) AS max_score,
            COUNT(ar.id) FILTER (WHERE ar.score <= 25) AS count_low,
            COUNT(ar.id) FILTER (WHERE ar.score > 25 AND ar.score <= 50) AS count_mid_low,
            COUNT(ar.id) FILTER (WHERE ar.score > 50 AND ar.score <= 75) AS count_mid_high,
            COUNT(ar.id) FILTER (WHERE ar.score > 75) AS count_high
         FROM psaims_elements e
         LEFT JOIN assessment_questions q ON q.element_id = e.id AND q.is_active = TRUE
         LEFT JOIN assessment_results ar ON ar.question_id = q.id AND ar.session_id = ?
         WHERE e.is_active = TRUE
         GROUP BY e.id, e.element_number, e.element_name, e.icon, e.color
         ORDER BY e.element_number"
    );
    $stmt->execute([$session_id]);
    $element_scores = $stmt->fetchAll();

    foreach ($element_scores as $es) {
        $overall_stats['total_questions'] += (int)$es['total_questions'];
        $overall_stats['answered']        += (int)$es['answered'];
    }

    // Hitung weighted average (berdasarkan jumlah pertanyaan terjawab)
    $stmt = $pdo->prepare(
        "SELECT COALESCE(AVG(score), 0)::numeric(5,2) AS overall_avg,
                COUNT(*) AS total_answers
         FROM assessment_results
         WHERE session_id = ?"
    );
    $stmt->execute([$session_id]);
    $row = $stmt->fetch();
    $overall_stats['avg_score'] = (float)$row['overall_avg'];
}

// Top 5 terlemah & terkuat (yang sudah ada skor)
$elements_answered = array_filter($element_scores, fn($e) => $e['answered'] > 0);
usort($elements_answered, fn($a, $b) => $a['avg_score'] <=> $b['avg_score']);
$weakest_elements = array_slice($elements_answered, 0, 5);

$elements_strongest = $elements_answered;
usort($elements_strongest, fn($a, $b) => $b['avg_score'] <=> $a['avg_score']);
$strongest_elements = array_slice($elements_strongest, 0, 5);

// Distribusi skor keseluruhan
$score_distribution = [
    'low'      => 0,
    'mid_low'  => 0,
    'mid_high' => 0,
    'high'     => 0,
];
foreach ($element_scores as $es) {
    $score_distribution['low']      += (int)$es['count_low'];
    $score_distribution['mid_low']  += (int)$es['count_mid_low'];
    $score_distribution['mid_high'] += (int)$es['count_mid_high'];
    $score_distribution['high']     += (int)$es['count_high'];
}
$total_dist = array_sum($score_distribution);

// Trend antar session (kalau ada 2+ session)
$trend_data = [];
if (count($sessions) >= 2) {
    $stmt = $pdo->query(
        "SELECT s.id, s.session_name, s.session_year, s.session_period,
                COALESCE(AVG(ar.score), 0)::numeric(5,2) AS avg_score,
                COUNT(DISTINCT ar.question_id) AS answered
         FROM assessment_sessions s
         LEFT JOIN assessment_results ar ON ar.session_id = s.id
         GROUP BY s.id, s.session_name, s.session_year, s.session_period
         HAVING COUNT(ar.id) > 0
         ORDER BY s.session_year, s.id"
    );
    $trend_data = $stmt->fetchAll();
}

// Helper: warna heatmap berdasarkan skor
function heatmapColor($score) {
    if ($score == 0)      return ['#E9ECEF', '#6C757D', 'Belum dinilai'];
    if ($score < 25)      return ['#F8D7DA', '#721C24', 'Sangat Rendah'];
    if ($score < 50)      return ['#FFF3CD', '#856404', 'Rendah'];
    if ($score < 75)      return ['#FFE5B4', '#7C5A00', 'Sedang'];
    if ($score < 90)      return ['#D4EDDA', '#155724', 'Baik'];
    return ['#C3E6CB', '#0F4021', 'Sangat Baik'];
}

$page_title = 'Ringkasan Assessment';
require_once __DIR__ . '/../includes/header.php';
require_once __DIR__ . '/../includes/sidebar.php';
?>

<div class="content-wrapper">
    <section class="content-header">
        <div class="container-fluid">
            <div class="row align-items-center">
                <div class="col-sm-7">
                    <h1><i class="fas fa-chart-pie text-primary"></i> Ringkasan Assessment</h1>
                    <small class="text-muted">Dashboard eksekutif hasil self-assessment PSAIMS</small>
                </div>
                <div class="col-sm-5">
                    <form method="GET" class="form-inline justify-content-end">
                        <label class="mr-2" style="font-size:13px;">Periode:</label>
                        <select name="session" class="form-control form-control-sm" onchange="this.form.submit()"
                                style="min-width:220px;">
                            <?php if (empty($sessions)): ?>
                                <option>— Belum ada sesi —</option>
                            <?php endif; ?>
                            <?php foreach ($sessions as $s): ?>
                                <option value="<?= $s['id'] ?>" <?= $s['id'] == $session_id ? 'selected' : '' ?>>
                                    <?= e($s['session_name']) ?>
                                    <?php if ($s['status'] === 'ongoing'): ?>(aktif)<?php endif; ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </form>
                </div>
            </div>
        </div>
    </section>

    <section class="content">
        <div class="container-fluid">

            <?php if (!$current_session): ?>
                <div class="alert alert-info">
                    <h5><i class="fas fa-info-circle"></i> Belum ada periode assessment</h5>
                    <p class="mb-0">Silakan buat periode assessment baru di menu <strong>Periode Assessment</strong> terlebih dahulu.</p>
                </div>
            <?php else: ?>

                <!-- ============ KPI CARDS ============ -->
                <div class="row">
                    <div class="col-md-3">
                        <div class="small-box bg-info">
                            <div class="inner">
                                <h3><?= number_format($overall_stats['avg_score'], 1) ?>%</h3>
                                <p>Skor Rata-Rata</p>
                            </div>
                            <div class="icon">
                                <i class="fas fa-chart-line"></i>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-3">
                        <div class="small-box bg-success">
                            <div class="inner">
                                <h3>
                                    <?= $overall_stats['answered'] ?>
                                    <sup style="font-size:14px;">/<?= $overall_stats['total_questions'] ?></sup>
                                </h3>
                                <p>Pertanyaan Terjawab</p>
                            </div>
                            <div class="icon">
                                <i class="fas fa-check-circle"></i>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-3">
                        <?php
                        $progress_pct = $overall_stats['total_questions'] > 0
                            ? round(100 * $overall_stats['answered'] / $overall_stats['total_questions'], 1)
                            : 0;
                        ?>
                        <div class="small-box bg-warning">
                            <div class="inner">
                                <h3><?= $progress_pct ?>%</h3>
                                <p>Progress Pengisian</p>
                            </div>
                            <div class="icon">
                                <i class="fas fa-tasks"></i>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-3">
                        <div class="small-box bg-primary">
                            <div class="inner">
                                <h3><?= count($element_scores) ?></h3>
                                <p>Elemen PSAIMS</p>
                            </div>
                            <div class="icon">
                                <i class="fas fa-th-large"></i>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- ============ HEATMAP 18 ELEMEN ============ -->
                <div class="card">
                    <div class="card-header">
                        <h5 class="card-title mb-0">
                            <i class="fas fa-th"></i> Heatmap 18 Elemen PSAIMS
                        </h5>
                        <div class="card-tools">
                            <small class="text-muted">Klik elemen untuk detail</small>
                        </div>
                    </div>
                    <div class="card-body">
                        <div class="row">
                            <?php foreach ($element_scores as $es):
                                $score = (float)$es['avg_score'];
                                [$bg, $fg, $label] = heatmapColor($score);
                                $pct_answered = $es['total_questions'] > 0
                                    ? round(100 * $es['answered'] / $es['total_questions'])
                                    : 0;
                            ?>
                                <div class="col-md-2 col-sm-4 col-6 mb-2">
                                    <a href="<?= BASE_URL ?>pages/assessment.php?element=<?= $es['element_number'] ?>"
                                       class="d-block heatmap-cell"
                                       style="background: <?= $bg ?>; color: <?= $fg ?>;
                                              border-radius: 6px; padding: 12px; text-decoration: none;
                                              border: 1px solid rgba(0,0,0,0.08);">
                                        <div style="display:flex; justify-content:space-between; align-items:start;">
                                            <strong style="font-size:14px;">
                                                E<?= str_pad($es['element_number'], 2, '0', STR_PAD_LEFT) ?>
                                            </strong>
                                            <small style="font-size:10px; opacity:0.8;">
                                                <?= $pct_answered ?>% isi
                                            </small>
                                        </div>
                                        <div style="font-size:11px; margin-top:4px; min-height:28px; line-height:1.3;">
                                            <?= e(mb_strimwidth($es['element_name'], 0, 32, '…')) ?>
                                        </div>
                                        <div style="font-size:20px; font-weight:600; margin-top:6px;">
                                            <?= $es['answered'] > 0
                                                ? number_format($score, 1) . '%'
                                                : '<small style="font-size:11px; font-weight:400;">Belum dinilai</small>' ?>
                                        </div>
                                    </a>
                                </div>
                            <?php endforeach; ?>
                        </div>

                        <!-- Legend -->
                        <div class="mt-3 pt-3" style="border-top:1px solid #dee2e6; font-size:11px;">
                            <strong class="mr-2">Legend:</strong>
                            <span style="background:#E9ECEF; color:#6C757D; padding:2px 8px; border-radius:3px;">Belum</span>
                            <span style="background:#F8D7DA; color:#721C24; padding:2px 8px; border-radius:3px; margin-left:4px;">&lt;25% Sangat Rendah</span>
                            <span style="background:#FFF3CD; color:#856404; padding:2px 8px; border-radius:3px; margin-left:4px;">25-50% Rendah</span>
                            <span style="background:#FFE5B4; color:#7C5A00; padding:2px 8px; border-radius:3px; margin-left:4px;">50-75% Sedang</span>
                            <span style="background:#D4EDDA; color:#155724; padding:2px 8px; border-radius:3px; margin-left:4px;">75-90% Baik</span>
                            <span style="background:#C3E6CB; color:#0F4021; padding:2px 8px; border-radius:3px; margin-left:4px;">&gt;90% Sangat Baik</span>
                        </div>
                    </div>
                </div>

                <!-- ============ TOP 5 TERLEMAH & TERKUAT ============ -->
                <div class="row">
                    <div class="col-md-6">
                        <div class="card card-outline card-danger">
                            <div class="card-header">
                                <h5 class="card-title mb-0">
                                    <i class="fas fa-exclamation-triangle text-danger"></i>
                                    5 Elemen Terlemah
                                </h5>
                            </div>
                            <div class="card-body p-0">
                                <?php if (empty($weakest_elements)): ?>
                                    <p class="text-muted text-center p-3">Belum ada data assessment.</p>
                                <?php else: ?>
                                    <table class="table table-sm mb-0">
                                        <tbody>
                                            <?php foreach ($weakest_elements as $i => $el):
                                                $score = (float)$el['avg_score'];
                                                [$bg, $fg] = heatmapColor($score);
                                            ?>
                                                <tr>
                                                    <td style="width:30px;" class="text-center"><?= $i + 1 ?>.</td>
                                                    <td>
                                                        <strong>E<?= str_pad($el['element_number'], 2, '0', STR_PAD_LEFT) ?></strong>
                                                        <?= e($el['element_name']) ?>
                                                    </td>
                                                    <td style="width:90px;" class="text-right">
                                                        <span style="background:<?= $bg ?>; color:<?= $fg ?>;
                                                                     padding:3px 10px; border-radius:3px; font-weight:500;">
                                                            <?= number_format($score, 1) ?>%
                                                        </span>
                                                    </td>
                                                </tr>
                                            <?php endforeach; ?>
                                        </tbody>
                                    </table>
                                <?php endif; ?>
                            </div>
                            <?php if (!empty($weakest_elements)): ?>
                                <div class="card-footer text-center">
                                    <a href="<?= BASE_URL ?>pages/report_gap.php?session=<?= $session_id ?>"
                                       class="btn btn-sm btn-outline-danger">
                                        <i class="fas fa-search"></i> Lihat Gap Analysis
                                    </a>
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>

                    <div class="col-md-6">
                        <div class="card card-outline card-success">
                            <div class="card-header">
                                <h5 class="card-title mb-0">
                                    <i class="fas fa-trophy text-success"></i>
                                    5 Elemen Terkuat
                                </h5>
                            </div>
                            <div class="card-body p-0">
                                <?php if (empty($strongest_elements)): ?>
                                    <p class="text-muted text-center p-3">Belum ada data assessment.</p>
                                <?php else: ?>
                                    <table class="table table-sm mb-0">
                                        <tbody>
                                            <?php foreach ($strongest_elements as $i => $el):
                                                $score = (float)$el['avg_score'];
                                                [$bg, $fg] = heatmapColor($score);
                                            ?>
                                                <tr>
                                                    <td style="width:30px;" class="text-center"><?= $i + 1 ?>.</td>
                                                    <td>
                                                        <strong>E<?= str_pad($el['element_number'], 2, '0', STR_PAD_LEFT) ?></strong>
                                                        <?= e($el['element_name']) ?>
                                                    </td>
                                                    <td style="width:90px;" class="text-right">
                                                        <span style="background:<?= $bg ?>; color:<?= $fg ?>;
                                                                     padding:3px 10px; border-radius:3px; font-weight:500;">
                                                            <?= number_format($score, 1) ?>%
                                                        </span>
                                                    </td>
                                                </tr>
                                            <?php endforeach; ?>
                                        </tbody>
                                    </table>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- ============ DISTRIBUSI SKOR & TREND ============ -->
                <div class="row">
                    <div class="col-md-6">
                        <div class="card">
                            <div class="card-header">
                                <h5 class="card-title mb-0"><i class="fas fa-chart-pie"></i> Distribusi Skor</h5>
                            </div>
                            <div class="card-body">
                                <?php if ($total_dist == 0): ?>
                                    <p class="text-muted text-center">Belum ada data assessment.</p>
                                <?php else: ?>
                                    <div style="height:250px;">
                                        <canvas id="chartDistribution"></canvas>
                                    </div>
                                    <div class="mt-3" style="font-size:12px;">
                                        <div class="row text-center">
                                            <div class="col">
                                                <div style="color:#721C24; font-weight:500;">
                                                    <?= $score_distribution['low'] ?>
                                                </div>
                                                <small class="text-muted">≤25%</small>
                                            </div>
                                            <div class="col">
                                                <div style="color:#856404; font-weight:500;">
                                                    <?= $score_distribution['mid_low'] ?>
                                                </div>
                                                <small class="text-muted">25-50%</small>
                                            </div>
                                            <div class="col">
                                                <div style="color:#7C5A00; font-weight:500;">
                                                    <?= $score_distribution['mid_high'] ?>
                                                </div>
                                                <small class="text-muted">50-75%</small>
                                            </div>
                                            <div class="col">
                                                <div style="color:#155724; font-weight:500;">
                                                    <?= $score_distribution['high'] ?>
                                                </div>
                                                <small class="text-muted">&gt;75%</small>
                                            </div>
                                        </div>
                                    </div>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>

                    <div class="col-md-6">
                        <div class="card">
                            <div class="card-header">
                                <h5 class="card-title mb-0"><i class="fas fa-chart-line"></i> Trend Antar Periode</h5>
                            </div>
                            <div class="card-body">
                                <?php if (count($trend_data) < 2): ?>
                                    <div class="text-center text-muted p-4">
                                        <i class="fas fa-chart-line fa-3x mb-2" style="opacity:0.3;"></i>
                                        <p class="mb-0">Trend akan muncul setelah minimal 2 periode assessment selesai.</p>
                                        <small>Saat ini tersedia: <?= count($trend_data) ?> periode</small>
                                    </div>
                                <?php else: ?>
                                    <div style="height:250px;">
                                        <canvas id="chartTrend"></canvas>
                                    </div>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- ============ TABEL DETAIL PER ELEMEN ============ -->
                <div class="card">
                    <div class="card-header">
                        <h5 class="card-title mb-0"><i class="fas fa-table"></i> Detail Skor per Elemen</h5>
                        <div class="card-tools">
                            <button class="btn btn-sm btn-outline-secondary" onclick="window.print()">
                                <i class="fas fa-print"></i> Print
                            </button>
                        </div>
                    </div>
                    <div class="card-body p-0">
                        <table class="table table-hover table-sm mb-0">
                            <thead class="bg-light">
                                <tr>
                                    <th style="width:60px;">#</th>
                                    <th>Elemen</th>
                                    <th class="text-center" style="width:100px;">Progress</th>
                                    <th class="text-center" style="width:90px;">Min</th>
                                    <th class="text-center" style="width:90px;">Max</th>
                                    <th class="text-center" style="width:110px;">Rata-rata</th>
                                    <th class="text-center" style="width:80px;">Aksi</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($element_scores as $es):
                                    $score = (float)$es['avg_score'];
                                    [$bg, $fg, $label] = heatmapColor($score);
                                    $pct = $es['total_questions'] > 0
                                        ? round(100 * $es['answered'] / $es['total_questions'])
                                        : 0;
                                ?>
                                    <tr>
                                        <td class="text-center">
                                            <strong>E<?= str_pad($es['element_number'], 2, '0', STR_PAD_LEFT) ?></strong>
                                        </td>
                                        <td>
                                            <i class="<?= e($es['icon']) ?> text-<?= e($es['color']) ?>"></i>
                                            <?= e($es['element_name']) ?>
                                        </td>
                                        <td>
                                            <div class="progress" style="height:18px;">
                                                <div class="progress-bar bg-info" style="width:<?= $pct ?>%">
                                                    <?= $es['answered'] ?>/<?= $es['total_questions'] ?>
                                                </div>
                                            </div>
                                        </td>
                                        <td class="text-center">
                                            <?php if ($es['answered'] > 0): ?>
                                                <?= $es['min_score'] ?>%
                                            <?php else: ?>
                                                <small class="text-muted">—</small>
                                            <?php endif; ?>
                                        </td>
                                        <td class="text-center">
                                            <?php if ($es['answered'] > 0): ?>
                                                <?= $es['max_score'] ?>%
                                            <?php else: ?>
                                                <small class="text-muted">—</small>
                                            <?php endif; ?>
                                        </td>
                                        <td class="text-center">
                                            <?php if ($es['answered'] > 0): ?>
                                                <span style="background:<?= $bg ?>; color:<?= $fg ?>;
                                                             padding:4px 12px; border-radius:4px; font-weight:500;">
                                                    <?= number_format($score, 1) ?>%
                                                </span>
                                            <?php else: ?>
                                                <small class="text-muted">Belum dinilai</small>
                                            <?php endif; ?>
                                        </td>
                                        <td class="text-center">
                                            <a href="<?= BASE_URL ?>pages/assessment.php?element=<?= $es['element_number'] ?>"
                                               class="btn btn-xs btn-outline-primary">
                                                <i class="fas fa-eye"></i> Detail
                                            </a>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>

            <?php endif; ?>

        </div>
    </section>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>

<script>
jQuery(function($) {
    <?php if ($total_dist > 0): ?>
    // ============ Chart: Distribusi Skor (Doughnut) ============
    const ctxDist = document.getElementById('chartDistribution');
    if (ctxDist) {
        new Chart(ctxDist, {
            type: 'doughnut',
            data: {
                labels: ['Sangat Rendah (≤25%)', 'Rendah (25-50%)', 'Sedang (50-75%)', 'Baik (>75%)'],
                datasets: [{
                    data: [
                        <?= $score_distribution['low'] ?>,
                        <?= $score_distribution['mid_low'] ?>,
                        <?= $score_distribution['mid_high'] ?>,
                        <?= $score_distribution['high'] ?>
                    ],
                    backgroundColor: ['#F5C6CB', '#FFEEBA', '#FFE5B4', '#C3E6CB'],
                    borderColor: ['#F1B0B7', '#FFDF7E', '#FFD085', '#A3D9B1'],
                    borderWidth: 1
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                legend: { position: 'bottom', labels: { fontSize: 11, padding: 8 } }
            }
        });
    }
    <?php endif; ?>

    <?php if (count($trend_data) >= 2): ?>
    // ============ Chart: Trend Antar Periode (Line) ============
    const ctxTrend = document.getElementById('chartTrend');
    if (ctxTrend) {
        new Chart(ctxTrend, {
            type: 'line',
            data: {
                labels: [<?php
                    echo implode(',', array_map(
                        fn($t) => '"' . addslashes($t['session_name']) . '"', $trend_data
                    ));
                ?>],
                datasets: [{
                    label: 'Skor Rata-rata PSAIMS',
                    data: [<?php
                        echo implode(',', array_map(fn($t) => $t['avg_score'], $trend_data));
                    ?>],
                    backgroundColor: 'rgba(23, 162, 184, 0.1)',
                    borderColor: '#17a2b8',
                    borderWidth: 2,
                    pointRadius: 5,
                    pointBackgroundColor: '#17a2b8',
                    fill: true,
                    tension: 0.3
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                scales: {
                    yAxes: [{
                        ticks: { min: 0, max: 100, stepSize: 25, callback: v => v + '%' }
                    }]
                },
                legend: { display: false }
            }
        });
    }
    <?php endif; ?>

    // Hover effect untuk heatmap
    $('.heatmap-cell').hover(
        function() { $(this).css('transform', 'scale(1.03)').css('transition', 'transform 0.15s'); },
        function() { $(this).css('transform', 'scale(1)'); }
    );
});
</script>

<style>
.heatmap-cell:hover {
    box-shadow: 0 2px 8px rgba(0,0,0,0.15);
    cursor: pointer;
}
@media print {
    .main-sidebar, .main-header, .main-footer, .card-tools, .content-header form { display: none !important; }
    .content-wrapper { margin-left: 0 !important; }
    .card { page-break-inside: avoid; }
}
</style>