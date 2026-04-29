<?php
$page_title = 'Dashboard';
require_once __DIR__ . '/includes/header.php';
require_once __DIR__ . '/includes/sidebar.php';

// Statistik singkat
$stmt = $pdo->query("SELECT COUNT(*) AS total FROM psaims_elements WHERE is_active = TRUE");
$total_elements = $stmt->fetch()['total'];

$stmt = $pdo->query("SELECT COUNT(*) AS total FROM assessment_questions WHERE is_active = TRUE");
$total_questions = $stmt->fetch()['total'];

$stmt = $pdo->query("SELECT COUNT(*) AS total FROM assessment_results");
$total_results = $stmt->fetch()['total'];

$stmt = $pdo->query("SELECT COUNT(*) AS total FROM assessment_sessions WHERE status = 'ongoing'");
$ongoing_sessions = $stmt->fetch()['total'];

// ============ CHART DATA ============
// Ambil data: rata-rata skor per elemen
// Label sumbu X di-force ke format E01, E02, ..., E18 supaya kompak
$stmt = $pdo->query(
    "SELECT e.element_number,
            'E' || LPAD(e.element_number::text, 2, '0') AS label,
            e.element_name,
            COALESCE(ROUND(AVG(ar.score)::numeric, 2), 0) AS avg_score,
            COUNT(ar.id) AS total_resp
     FROM psaims_elements e
     LEFT JOIN assessment_results ar ON e.id = ar.element_id
     WHERE e.is_active = TRUE
     GROUP BY e.id, e.element_number, e.element_name
     ORDER BY e.element_number"
);
$chart_data = $stmt->fetchAll();
?>

<!-- Content Wrapper -->
<div class="content-wrapper">

    <!-- Content Header -->
    <section class="content-header">
        <div class="container-fluid">
            <div class="row mb-2">
                <div class="col-sm-6">
                    <h1>
                        <i class="fas fa-tachometer-alt"></i> Dashboard PSAIMS
                    </h1>
                </div>
                <div class="col-sm-6">
                    <ol class="breadcrumb float-sm-right">
                        <li class="breadcrumb-item"><a href="#">Home</a></li>
                        <li class="breadcrumb-item active">Dashboard</li>
                    </ol>
                </div>
            </div>
        </div>
    </section>

    <!-- Main content -->
    <section class="content">
        <div class="container-fluid">

            <!-- Welcome Banner -->
            <div class="callout callout-info shadow-sm">
                <h5><i class="fas fa-hand-peace"></i> Selamat datang, <?= e($user['full_name']) ?>!</h5>
                <p class="mb-0">
                    PSAIMS (<em>Process Safety &amp; Asset Integrity Management System</em>) Self Assessment Tool
                    digunakan untuk melakukan penilaian mandiri terhadap 18 elemen keselamatan proses di perusahaan.
                </p>
            </div>

            <!-- Info boxes -->
            <div class="row">
                <div class="col-12 col-sm-6 col-md-3">
                    <div class="info-box shadow-sm">
                        <span class="info-box-icon bg-info elevation-1">
                            <i class="fas fa-th-large"></i>
                        </span>
                        <div class="info-box-content">
                            <span class="info-box-text">Total Elemen</span>
                            <span class="info-box-number"><?= $total_elements ?></span>
                        </div>
                    </div>
                </div>
                <div class="col-12 col-sm-6 col-md-3">
                    <div class="info-box shadow-sm">
                        <span class="info-box-icon bg-success elevation-1">
                            <i class="fas fa-question-circle"></i>
                        </span>
                        <div class="info-box-content">
                            <span class="info-box-text">Pertanyaan</span>
                            <span class="info-box-number"><?= $total_questions ?></span>
                        </div>
                    </div>
                </div>
                <div class="col-12 col-sm-6 col-md-3">
                    <div class="info-box shadow-sm">
                        <span class="info-box-icon bg-warning elevation-1">
                            <i class="fas fa-clipboard-check"></i>
                        </span>
                        <div class="info-box-content">
                            <span class="info-box-text">Jawaban Terisi</span>
                            <span class="info-box-number"><?= $total_results ?></span>
                        </div>
                    </div>
                </div>
                <div class="col-12 col-sm-6 col-md-3">
                    <div class="info-box shadow-sm">
                        <span class="info-box-icon bg-danger elevation-1">
                            <i class="fas fa-play-circle"></i>
                        </span>
                        <div class="info-box-content">
                            <span class="info-box-text">Periode Aktif</span>
                            <span class="info-box-number"><?= $ongoing_sessions ?></span>
                        </div>
                    </div>
                </div>
            </div>

            <!-- 18 Elemen PSAIMS sebagai Kartu -->
            <div class="row">
                <div class="col-12">
                    <div class="card card-primary card-outline">
                        <div class="card-header">
                            <h3 class="card-title">
                                <i class="fas fa-th"></i> 18 Elemen PSAIMS
                            </h3>
                        </div>
                        <div class="card-body">
                            <div class="row">
                                <?php foreach ($elements as $el):
                                    // Ambil rata-rata skor untuk elemen ini
                                    $stmt = $pdo->prepare(
                                        "SELECT COALESCE(ROUND(AVG(score)::numeric, 2), 0) AS avg_score,
                                                COUNT(*) AS total
                                         FROM assessment_results WHERE element_id = ?"
                                    );
                                    $stmt->execute([$el['id']]);
                                    $stat = $stmt->fetch();
                                    $avg   = $stat['avg_score'] ?: 0;
                                    $total = $stat['total']     ?: 0;
                                    $pct   = $avg > 0 ? min(100, ($avg / 5) * 100) : 0;
                                ?>
                                <div class="col-12 col-sm-6 col-md-4 col-lg-3 mb-3">
                                    <a href="<?= BASE_URL ?>pages/assessment.php?element=<?= $el['element_number'] ?>"
                                       class="text-decoration-none">
                                        <div class="small-box bg-<?= e($el['color']) ?> shadow"
                                             style="min-height:160px;">
                                            <div class="inner text-white">
                                                <h3 style="font-size:2rem;">
                                                    <?= str_pad($el['element_number'], 2, '0', STR_PAD_LEFT) ?>
                                                </h3>
                                                <p style="font-size:0.85rem; min-height:55px;">
                                                    <?= e($el['element_name']) ?>
                                                </p>
                                                <small>
                                                    Skor rata-rata:
                                                    <strong><?= number_format($avg, 2) ?></strong> / 5.00
                                                    (<?= $total ?> respon)
                                                </small>
                                            </div>
                                            <div class="icon">
                                                <i class="<?= e($el['icon']) ?>"></i>
                                            </div>
                                            <span class="small-box-footer">
                                                Mulai Assessment <i class="fas fa-arrow-circle-right"></i>
                                            </span>
                                        </div>
                                    </a>
                                </div>
                                <?php endforeach; ?>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Chart skor per elemen -->
            <div class="row">
                <div class="col-12">
                    <div class="card card-outline card-info">
                        <div class="card-header">
                            <h3 class="card-title">
                                <i class="fas fa-chart-bar"></i> Rata-rata Skor per Elemen
                            </h3>
                        </div>
                        <div class="card-body">
                            <?php if (empty($chart_data)): ?>
                                <div class="text-center text-muted p-4">
                                    <i class="fas fa-chart-bar fa-3x" style="opacity:0.3;"></i>
                                    <p class="mt-2 mb-0">Belum ada data assessment.</p>
                                </div>
                            <?php else: ?>
                                <div style="position: relative; height:340px;">
                                    <canvas id="chartScore"></canvas>
                                </div>
                                <small class="text-muted d-block mt-2 text-center">
                                    Total <?= count($chart_data) ?> elemen ·
                                    Skor maksimum 5.00
                                </small>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            </div>

        </div>
    </section>

</div>
<!-- /.content-wrapper -->

<?php require_once __DIR__ . '/includes/footer.php'; ?>

<script>
jQuery(function($) {
    var canvas = document.getElementById('chartScore');
    if (!canvas) return;

    var labels = <?= json_encode(array_map(fn($r) => $r['label'], $chart_data)) ?>;
    var data   = <?= json_encode(array_map(fn($r) => floatval($r['avg_score']), $chart_data)) ?>;
    var resp   = <?= json_encode(array_map(fn($r) => (int)$r['total_resp'], $chart_data)) ?>;
    var names  = <?= json_encode(array_map(fn($r) => $r['element_name'], $chart_data)) ?>;

    if (typeof Chart === 'undefined') {
        canvas.parentElement.innerHTML = '<div class="alert alert-warning">Chart.js library tidak loaded.</div>';
        return;
    }

    // Color per bar berdasarkan skor (red = low, green = high)
    var bgColors = data.map(function(s) {
        if (s >= 4) return 'rgba(40, 167, 69, 0.75)';   // green
        if (s >= 3) return 'rgba(23, 162, 184, 0.75)';  // info
        if (s >= 2) return 'rgba(255, 193, 7, 0.75)';   // warning
        if (s >  0) return 'rgba(220, 53, 69, 0.75)';   // danger
        return 'rgba(108, 117, 125, 0.4)';              // gray for 0
    });
    var borderColors = bgColors.map(function(c) { return c.replace('0.75', '1').replace('0.4', '1'); });

    new Chart(canvas.getContext('2d'), {
        type: 'bar',
        data: {
            labels: labels,
            datasets: [{
                label: 'Rata-rata Skor',
                data: data,
                backgroundColor: bgColors,
                borderColor:     borderColors,
                borderWidth: 1
            }]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            legend: { display: false },
            tooltips: {
                callbacks: {
                    title: function(items) {
                        var i = items[0].index;
                        return labels[i] + ' — ' + names[i];
                    },
                    label: function(item) {
                        var i = item.index;
                        return 'Skor: ' + item.yLabel.toFixed(2) + ' / 5.00 (' + resp[i] + ' respon)';
                    }
                }
            },
            scales: {
                yAxes: [{
                    ticks: { beginAtZero: true, max: 5, stepSize: 1 },
                    scaleLabel: { display: true, labelString: 'Skor (0 - 5)' }
                }],
                xAxes: [{
                    ticks: { autoSkip: false, maxRotation: 0, minRotation: 0 }
                }]
            }
        }
    });
});
</script>