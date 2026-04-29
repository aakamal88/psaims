<?php
require_once __DIR__ . '/../config/config.php';
requireLogin();

// Load content dari config terpisah
$content_file = __DIR__ . '/../config/about_content.php';
if (!file_exists($content_file)) {
    die('File konfigurasi about_content.php tidak ditemukan. Pastikan file ada di folder config/.');
}
$about = require $content_file;

$page_title = 'Tentang';
require_once __DIR__ . '/../includes/header.php';
require_once __DIR__ . '/../includes/sidebar.php';
?>

<style>
.about-hero {
    background: linear-gradient(135deg, #1e3c72 0%, #2a5298 100%);
    color: #fff;
    padding: 32px 28px;
    border-radius: 8px;
    margin-bottom: 20px;
    position: relative;
    overflow: hidden;
}
.about-hero::before {
    content: '';
    position: absolute;
    top: -50px; right: -50px;
    width: 200px; height: 200px;
    background: rgba(255,255,255,0.05);
    border-radius: 50%;
}
.about-hero::after {
    content: '';
    position: absolute;
    bottom: -80px; left: -50px;
    width: 250px; height: 250px;
    background: rgba(255,255,255,0.05);
    border-radius: 50%;
}
.about-hero h1 {
    font-size: 2rem;
    font-weight: 700;
    margin: 0 0 8px;
    position: relative;
    z-index: 1;
}
.about-hero .subtitle {
    font-size: 0.95rem;
    opacity: 0.9;
    margin-bottom: 16px;
    position: relative;
    z-index: 1;
}
.about-hero .tagline {
    font-style: italic;
    color: #ffc107;
    font-size: 1.1rem;
    font-weight: 500;
    margin-top: 12px;
    position: relative;
    z-index: 1;
}
.about-hero .version-badge {
    background: rgba(255,255,255,0.2);
    padding: 4px 14px;
    border-radius: 20px;
    font-size: 11px;
    display: inline-block;
    margin-right: 8px;
    border: 1px solid rgba(255,255,255,0.3);
}

.feature-card {
    background: #fff;
    border: 1px solid #e9ecef;
    border-radius: 8px;
    padding: 18px;
    height: 100%;
    transition: all 0.2s;
}
.feature-card:hover {
    box-shadow: 0 4px 12px rgba(0,0,0,0.08);
    border-color: #1e3c72;
    transform: translateY(-2px);
}
.feature-icon {
    width: 48px;
    height: 48px;
    border-radius: 10px;
    display: flex;
    align-items: center;
    justify-content: center;
    background: linear-gradient(135deg, #1e3c72 0%, #2a5298 100%);
    color: #fff;
    font-size: 20px;
    margin-bottom: 12px;
}
.feature-title {
    font-size: 14px;
    font-weight: 600;
    color: #1e3c72;
    margin-bottom: 6px;
}
.feature-desc {
    font-size: 12px;
    color: #6c757d;
    line-height: 1.5;
    margin: 0;
}

.tech-item {
    display: flex;
    align-items: center;
    gap: 12px;
    padding: 12px;
    background: #f8f9fa;
    border-radius: 6px;
    margin-bottom: 8px;
}
.tech-icon {
    width: 36px; height: 36px;
    background: #1e3c72;
    color: #fff;
    border-radius: 6px;
    display: flex; align-items: center; justify-content: center;
    font-size: 16px;
}
.tech-name { font-weight: 600; font-size: 13px; color: #212529; }
.tech-desc { font-size: 11px; color: #6c757d; }

.credit-card {
    background: linear-gradient(135deg, #f6f9fc 0%, #e9ecf3 100%);
    border-left: 4px solid #1e3c72;
    padding: 16px 20px;
    border-radius: 6px;
}
.credit-name {
    font-size: 18px;
    font-weight: 700;
    color: #1e3c72;
}
.credit-role {
    font-size: 12px;
    color: #495057;
    margin-bottom: 4px;
}
.credit-meta {
    font-size: 11px;
    color: #6c757d;
}

.changelog-item {
    border-left: 2px solid #1e3c72;
    padding: 12px 16px;
    margin-bottom: 12px;
    background: #f8f9fa;
    border-radius: 0 6px 6px 0;
}
.changelog-version {
    display: inline-block;
    background: #1e3c72;
    color: #fff;
    padding: 2px 10px;
    border-radius: 12px;
    font-size: 11px;
    font-weight: 600;
    margin-right: 8px;
}
.changelog-date {
    font-size: 11px;
    color: #6c757d;
}
.changelog-list {
    margin: 8px 0 0 0;
    padding-left: 18px;
    font-size: 12px;
    color: #495057;
}
.changelog-list li { margin-bottom: 3px; }

.section-title {
    font-size: 1.05rem;
    font-weight: 600;
    color: #1e3c72;
    margin-bottom: 14px;
    padding-bottom: 8px;
    border-bottom: 2px solid #1e3c72;
}

.contact-row {
    display: flex;
    align-items: center;
    gap: 10px;
    padding: 8px 0;
    border-bottom: 1px dashed #dee2e6;
    font-size: 13px;
}
.contact-row:last-child { border-bottom: none; }
.contact-row i {
    width: 28px;
    height: 28px;
    background: #e3eaf5;
    color: #1e3c72;
    border-radius: 50%;
    display: flex; align-items: center; justify-content: center;
    font-size: 12px;
}
</style>

<div class="content-wrapper">
    <section class="content-header">
        <div class="container-fluid">
            <h1 style="font-size: 1.4rem;"><i class="fas fa-info-circle text-primary"></i> Tentang</h1>
            <small class="text-muted">Informasi tentang aplikasi PSAIMS Self-Assessment Tool</small>
        </div>
    </section>

    <section class="content">
        <div class="container-fluid">

            <!-- ============ HERO SECTION ============ -->
            <div class="about-hero">
                <h1><i class="fas fa-shield-alt"></i> <?= e($about['app_name']) ?></h1>
                <div class="subtitle">
                    <?= e($about['app_full_name']) ?>
                </div>
                <div>
                    <span class="version-badge">
                        <i class="fas fa-code-branch"></i> v<?= e($about['version']) ?>
                    </span>
                    <span class="version-badge">
                        <i class="fas fa-calendar"></i> <?= e($about['release_date']) ?>
                    </span>
                    <span class="version-badge">
                        <i class="fas fa-circle" style="font-size:8px; color:#28a745;"></i>
                        <?= e($about['status']) ?>
                    </span>
                </div>
                <div class="tagline">
                    <i class="fas fa-quote-left" style="font-size:14px; opacity:0.6;"></i>
                    <?= e($about['tagline']) ?>
                    <i class="fas fa-quote-right" style="font-size:14px; opacity:0.6;"></i>
                </div>
            </div>

            <!-- ============ DESKRIPSI ============ -->
            <div class="card">
                <div class="card-body">
                    <h5 class="section-title"><i class="fas fa-book-open"></i> Deskripsi</h5>
                    <p style="font-size:14px; line-height:1.7; color:#495057; margin:0;">
                        <?= nl2br(e($about['description'])) ?>
                    </p>
                </div>
            </div>

            <!-- ============ FITUR UTAMA ============ -->
            <div class="card">
                <div class="card-body">
                    <h5 class="section-title"><i class="fas fa-star"></i> Fitur Utama</h5>
                    <div class="row">
                        <?php foreach ($about['features'] as $f):
                            // Support 2 format: [icon, title, desc] ATAU ['icon'=>, 'title'=>, 'description'=>]
                            $icon  = $f['icon']  ?? $f[0] ?? 'fa-circle';
                            $title = $f['title'] ?? $f[1] ?? '';
                            $desc  = $f['description'] ?? $f['desc'] ?? $f[2] ?? '';
                        ?>
                            <div class="col-md-6 col-lg-4 mb-3">
                                <div class="feature-card">
                                    <div class="feature-icon">
                                        <i class="fas <?= e($icon) ?>"></i>
                                    </div>
                                    <div class="feature-title"><?= e($title) ?></div>
                                    <p class="feature-desc"><?= e($desc) ?></p>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </div>
            </div>

            <!-- ============ TECH STACK + CREDIT ============ -->
            <div class="row">
                <div class="col-md-7">
                    <div class="card">
                        <div class="card-body">
                            <h5 class="section-title"><i class="fas fa-cogs"></i> Teknologi</h5>
                            <?php foreach ($about['tech_stack'] as $tech):
                                // Support 2 format
                                $icon = $tech['icon'] ?? $tech[0] ?? 'fas fa-code';
                                $name = $tech['name'] ?? $tech[1] ?? '';
                                $desc = $tech['version'] ?? $tech['desc'] ?? $tech['description'] ?? $tech[2] ?? '';
                            ?>
                                <div class="tech-item">
                                    <div class="tech-icon">
                                        <i class="<?= e($icon) ?>"></i>
                                    </div>
                                    <div>
                                        <div class="tech-name"><?= e($name) ?></div>
                                        <div class="tech-desc"><?= e($desc) ?></div>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                </div>

                <div class="col-md-5">
                    <div class="card">
                        <div class="card-body">
                            <h5 class="section-title"><i class="fas fa-user-cog"></i> Developer</h5>
                            <div class="credit-card mb-3">
                                <div class="credit-name">
                                    <i class="fas fa-user-circle"></i>
                                    <?= e($about['credits']['developer']) ?>
                                </div>
                                <div class="credit-role">
                                    <?= e($about['credits']['role']) ?>
                                </div>
                                <div class="credit-meta">
                                    <i class="fas fa-building"></i>
                                    <?= e($about['credits']['company']) ?>
                                    <?php if (!empty($about['credits']['unit'])): ?>
                                        · <?= e($about['credits']['unit']) ?>
                                    <?php endif; ?>
                                </div>
                                <?php if (!empty($about['credits']['email'])): ?>
                                    <div class="credit-meta mt-1">
                                        <i class="fas fa-envelope"></i>
                                        <a href="mailto:<?= e($about['credits']['email']) ?>"><?= e($about['credits']['email']) ?></a>
                                    </div>
                                <?php endif; ?>
                            </div>

                            <h5 class="section-title mt-4"><i class="fas fa-headset"></i> Kontak & Support</h5>
                            <?php if (!empty($about['contact']['support_email'])): ?>
                                <div class="contact-row">
                                    <i class="fas fa-envelope"></i>
                                    <div>
                                        <div style="color:#6c757d; font-size:11px;">Email Support</div>
                                        <a href="mailto:<?= e($about['contact']['support_email']) ?>">
                                            <?= e($about['contact']['support_email']) ?>
                                        </a>
                                    </div>
                                </div>
                            <?php endif; ?>
                            <?php if (!empty($about['contact']['documentation'])): ?>
                                <div class="contact-row">
                                    <i class="fas fa-book"></i>
                                    <div>
                                        <div style="color:#6c757d; font-size:11px;">Dokumentasi</div>
                                        <?= e($about['contact']['documentation']) ?>
                                    </div>
                                </div>
                            <?php endif; ?>
                            <?php if (!empty($about['contact']['office_hours'])): ?>
                                <div class="contact-row">
                                    <i class="fas fa-clock"></i>
                                    <div>
                                        <div style="color:#6c757d; font-size:11px;">Jam Operasional</div>
                                        <?= e($about['contact']['office_hours']) ?>
                                    </div>
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            </div>

            <!-- ============ CHANGELOG ============ -->
            <?php if (!empty($about['changelog'])): ?>
                <div class="card">
                    <div class="card-body">
                        <h5 class="section-title"><i class="fas fa-history"></i> Riwayat Versi</h5>
                        <?php foreach ($about['changelog'] as $log): ?>
                            <div class="changelog-item">
                                <span class="changelog-version">v<?= e($log['version']) ?></span>
                                <span class="changelog-date">
                                    <i class="fas fa-calendar-alt"></i> <?= e($log['date']) ?>
                                </span>
                                <ul class="changelog-list">
                                    <?php foreach ($log['changes'] as $change): ?>
                                        <li><?= e($change) ?></li>
                                    <?php endforeach; ?>
                                </ul>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </div>
            <?php endif; ?>

            <!-- ============ LISENSI ============ -->
            <?php if (!empty($about['license'])): ?>
                <div class="alert alert-secondary">
                    <strong><i class="fas fa-shield-alt"></i> Lisensi:</strong>
                    <?= e($about['license']) ?>
                </div>
            <?php endif; ?>

            <!-- ============ CLOSING MESSAGE ============ -->
            <?php if (!empty($about['closing_message'])): ?>
                <div class="text-center p-4 mb-4"
                     style="background: #f8f9fa; border-radius: 8px; border: 1px dashed #dee2e6;">
                    <i class="fas fa-heart text-danger" style="font-size:24px; margin-bottom:8px;"></i>
                    <p style="margin:0; font-size:13px; color:#495057; font-style:italic; max-width:600px; margin:0 auto;">
                        <?= e($about['closing_message']) ?>
                    </p>
                </div>
            <?php endif; ?>

        </div>
    </section>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>