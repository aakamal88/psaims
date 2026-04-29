<?php
// =====================================================
// SIDEBAR PSAIMS v5 — dengan Notification Dropdown
// =====================================================

require_once __DIR__ . '/notifications.php';

$role_code = $_SESSION['psaims_role_code'] ?? null;
$is_admin  = function_exists('isAdmin') && isAdmin();
$is_assessor = function_exists('isAssessor') && isAssessor();
$user_id = $_SESSION['user_id'] ?? null;

// Query elemen dengan RASCI (seperti versi sebelumnya)
if ($is_admin || $is_assessor || !$role_code) {
    $stmt = $pdo->query(
        "SELECT e.*, NULL AS my_responsibilities
         FROM psaims_elements e
         WHERE e.is_active = TRUE
         ORDER BY e.element_number"
    );
} else {
    $stmt = $pdo->prepare(
        "SELECT e.*,
                STRING_AGG(m.responsibility, ',' ORDER BY
                    CASE m.responsibility
                        WHEN 'A' THEN 1 WHEN 'R' THEN 2
                        WHEN 'S' THEN 3 WHEN 'C' THEN 4 WHEN 'I' THEN 5
                    END
                ) AS my_responsibilities
         FROM psaims_elements e
         LEFT JOIN element_role_mapping m ON m.element_id = e.id
            AND m.role_id = (SELECT id FROM psaims_roles WHERE role_code = ?)
         WHERE e.is_active = TRUE
         GROUP BY e.id
         ORDER BY e.element_number"
    );
    $stmt->execute([$role_code]);
}
$elements = $stmt->fetchAll();

$current_page    = basename($_SERVER['PHP_SELF']);
$current_element = isset($_GET['element']) ? (int)$_GET['element'] : 0;

// Badge role color
$sidebar_role_color = 'secondary';
if ($role_code !== null && $role_code !== '') {
    try {
        $stmt_rc = $pdo->prepare(
            "SELECT COALESCE(badge_color, 'secondary') AS c FROM psaims_roles WHERE role_code = ?"
        );
        $stmt_rc->execute([$role_code]);
        $sidebar_role_color = $stmt_rc->fetchColumn() ?: 'secondary';
    } catch (Exception $ex) {}
}

// Badge counters existing
$pending_verification = 0;
$pending_revision     = 0;
if (function_exists('canVerify') && canVerify()) {
    try {
        $pending_verification = (int)$pdo->query(
            "SELECT COUNT(*) FROM assessment_results WHERE verification_status = 'submitted'"
        )->fetchColumn();
    } catch (Exception $e) {}
}
if (!$is_admin && !$is_assessor && $user_id) {
    try {
        $stmt_cnt = $pdo->prepare(
            "SELECT COUNT(*) FROM assessment_results
             WHERE user_id = ? AND verification_status = 'returned'"
        );
        $stmt_cnt->execute([$user_id]);
        $pending_revision = (int)$stmt_cnt->fetchColumn();
    } catch (Exception $e) {}
}

// ============ NOTIFICATIONS ============
$unread_notif_count = 0;
$recent_notifs      = [];
$has_notif_table    = false;

try {
    $has_notif_table = (bool)$pdo->query(
        "SELECT 1 FROM information_schema.tables WHERE table_name = 'notifications'"
    )->fetchColumn();
} catch (Exception $e) {}

if ($has_notif_table && $user_id) {
    $unread_notif_count = countUnreadNotifications($user_id);
    $recent_notifs      = getNotifications($user_id, 8);
}

// Helper RASCI
function renderRasciBadges($responsibilities) {
    if (!$responsibilities) return '';
    $map = [
        'A' => ['danger',    'Accountable'],
        'R' => ['success',   'Responsible'],
        'S' => ['info',      'Support'],
        'C' => ['warning',   'Consulted'],
        'I' => ['secondary', 'Informed'],
    ];
    $levels = array_unique(explode(',', $responsibilities));
    $html = '';
    foreach ($levels as $level) {
        if (isset($map[$level])) {
            $html .= sprintf(
                '<span class="badge badge-%s rasci-badge" title="%s">%s</span>',
                $map[$level][0], e($map[$level][1]), e($level)
            );
        }
    }
    return $html;
}
?>
<aside class="main-sidebar sidebar-dark-primary elevation-4">

    <a href="<?= BASE_URL ?>index.php" class="brand-link text-center d-block py-3">
        <img src="<?= BASE_URL ?>assets/img/pertagas_logo.png"
             alt="Pertagas Logo" class="brand-image-custom"
             style="max-height: 50px; margin-bottom: 8px;">
        <div class="brand-text font-weight-light">
            <strong>PSAIMS TOOLS</strong>
        </div>
        <div class="brand-tagline">
            <em>Know the Gap, Close the Gap.</em>
        </div>
        <div class="brand-created">
            <em>Created by Ahmad Kamaludin.</em>
        </div>
    </a>

    <div class="sidebar">

        <!-- User panel -->
        <div class="user-panel mt-3 pb-2 mb-1 d-flex">
            <div class="image">
                <i class="fas fa-user-circle fa-2x text-white"></i>
            </div>
            <div class="info">
                <a href="#" class="d-block"><?= e($user['full_name']) ?></a>
                <small class="text-muted">
                    <i class="fas fa-circle text-success" style="font-size:0.5rem;"></i>
                    <?php if ($is_admin): ?>
                        <span class="badge badge-danger">
                            <i class="fas fa-user-shield" style="font-size:9px;"></i> Administrator
                        </span>
                    <?php elseif ($is_assessor): ?>
                        <span class="badge badge-warning">
                            <i class="fas fa-clipboard-check" style="font-size:9px;"></i> Assessor
                        </span>
                    <?php elseif ($role_code): ?>
                        <span class="badge badge-<?= e($sidebar_role_color) ?>"><?= e($role_code) ?></span>
                    <?php else: ?>
                        <span class="badge badge-secondary">No Role</span>
                    <?php endif; ?>
                </small>
            </div>
        </div>

        <!-- ============ NOTIFICATION PANEL ============ -->
        <?php if ($has_notif_table): ?>
        <div class="sidebar-notif-wrap pb-2 mb-3">
            <button type="button" class="sidebar-notif-btn" id="sidebarNotifToggle"
                    title="Klik untuk lihat notifikasi">
                <span>
                    <i class="fas fa-bell"></i> Notifikasi
                    <?php if ($is_admin): ?>
                        <small class="text-warning" style="font-size:9px;">(semua user)</small>
                    <?php endif; ?>
                </span>
                <span class="sidebar-notif-badge" id="sidebarNotifBadge"
                      style="<?= $unread_notif_count > 0 ? '' : 'display:none;' ?>">
                    <?= $unread_notif_count ?>
                </span>
            </button>

            <div class="sidebar-notif-dropdown" id="sidebarNotifDropdown" style="display:none;">
                <div class="sidebar-notif-header">
                    <strong>Notifikasi</strong>
                    <?php if ($unread_notif_count > 0): ?>
                        <button type="button" class="sidebar-notif-mark-all" id="btnMarkAllRead">
                            <i class="fas fa-check-double"></i> Tandai semua dibaca
                        </button>
                    <?php endif; ?>
                </div>

                <div class="sidebar-notif-body" id="sidebarNotifBody">
                    <?php if (empty($recent_notifs)): ?>
                        <div class="sidebar-notif-empty">
                            <i class="fas fa-inbox" style="font-size:24px; opacity:0.3;"></i>
                            <p class="mb-0 mt-2" style="font-size:11px;">Tidak ada notifikasi</p>
                        </div>
                    <?php else: ?>
                        <?php foreach ($recent_notifs as $n): ?>
                            <a href="<?= e($n['link'] ?? '#') ?>"
                               class="sidebar-notif-item <?= $n['is_read'] ? '' : 'unread' ?>"
                               data-id="<?= $n['id'] ?>">
                                <div class="sidebar-notif-icon bg-<?= e($n['color'] ?? 'primary') ?>">
                                    <i class="fas <?= e($n['icon'] ?? 'fa-bell') ?>"></i>
                                </div>
                                <div class="sidebar-notif-content">
                                    <div class="sidebar-notif-title">
                                        <?= e($n['title']) ?>
                                        <?php if (!$n['is_read']): ?>
                                            <span class="sidebar-notif-new">BARU</span>
                                        <?php endif; ?>
                                    </div>
                                    <?php if (!empty($n['message'])): ?>
                                        <div class="sidebar-notif-msg">
                                            <?= e(mb_strimwidth($n['message'], 0, 80, '…')) ?>
                                        </div>
                                    <?php endif; ?>
                                    <div class="sidebar-notif-time">
                                        <?= timeAgo($n['created_at']) ?>
                                    </div>
                                </div>
                            </a>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>

                <div class="sidebar-notif-footer">
                    <a href="<?= BASE_URL ?>pages/notifications.php">
                        Lihat semua notifikasi <i class="fas fa-arrow-right"></i>
                    </a>
                </div>
            </div>
        </div>
        <?php endif; ?>

        <nav class="mt-2">
            <ul class="nav nav-pills nav-sidebar flex-column"
                data-widget="treeview" role="menu" data-accordion="false">

                <!-- DASHBOARD -->
                <li class="nav-header">UTAMA</li>
                <li class="nav-item">
                    <a href="<?= BASE_URL ?>index.php"
                       class="nav-link <?= $current_page == 'index.php' ? 'active' : '' ?>">
                        <i class="nav-icon fas fa-tachometer-alt"></i>
                        <p>Dashboard</p>
                    </a>
                </li>

                <!-- INBOX FEEDBACK — untuk user biasa -->
                <?php if (!$is_admin && !$is_assessor): ?>
                <li class="nav-item">
                    <a href="<?= BASE_URL ?>pages/my_feedback.php"
                       class="nav-link <?= $current_page == 'my_feedback.php' ? 'active' : '' ?>">
                        <i class="nav-icon fas fa-inbox"></i>
                        <p>
                            Inbox Feedback
                            <?php if ($pending_revision > 0): ?>
                                <span class="badge badge-danger right"><?= $pending_revision ?></span>
                            <?php endif; ?>
                        </p>
                    </a>
                </li>
                <?php endif; ?>

                <!-- SELF ASSESSMENT -->
                <li class="nav-header">
                    SELF ASSESSMENT
                    <?php if (!$is_admin && !$is_assessor && $role_code): ?>
                        <small class="text-warning">(<?= e($role_code) ?>)</small>
                    <?php endif; ?>
                </li>

                <li class="nav-item <?= $current_page == 'assessment.php' ? 'menu-open' : '' ?>">
                    <a href="#" class="nav-link <?= $current_page == 'assessment.php' ? 'active' : '' ?>">
                        <i class="nav-icon fas fa-clipboard-list"></i>
                        <p>
                            18 Elemen PSAIMS
                            <i class="right fas fa-angle-left"></i>
                        </p>
                    </a>
                    <ul class="nav nav-treeview">
                        <?php foreach ($elements as $el):
                            $resps      = $el['my_responsibilities'] ?? null;
                            $can_access = $is_admin || $is_assessor || $resps !== null;
                            $badges     = renderRasciBadges($resps);
                        ?>
                            <li class="nav-item">
                                <?php if ($can_access): ?>
                                    <a href="<?= BASE_URL ?>pages/assessment.php?element=<?= $el['element_number'] ?>"
                                       class="nav-link <?= ($current_page == 'assessment.php' && $current_element == $el['element_number']) ? 'active' : '' ?>"
                                       title="<?= e($el['element_name']) ?><?= $resps ? ' [' . $resps . ']' : '' ?>">
                                        <i class="<?= e($el['icon']) ?> nav-icon text-<?= e($el['color']) ?>"></i>
                                        <p>
                                            <small><?= str_pad($el['element_number'], 2, '0', STR_PAD_LEFT) ?>.</small>
                                            <?= e(mb_strlen($el['element_name']) > 26
                                                ? mb_substr($el['element_name'], 0, 26) . '…'
                                                : $el['element_name']) ?>
                                            <?= $badges ?>
                                        </p>
                                    </a>
                                <?php else: ?>
                                    <a href="#" class="nav-link nav-link-locked"
                                       title="Tidak ditugaskan ke role Anda (<?= e($role_code) ?>)"
                                       onclick="return false;">
                                        <i class="fas fa-lock nav-icon"></i>
                                        <p>
                                            <small><?= str_pad($el['element_number'], 2, '0', STR_PAD_LEFT) ?>.</small>
                                            <?= e(mb_strlen($el['element_name']) > 26
                                                ? mb_substr($el['element_name'], 0, 26) . '…'
                                                : $el['element_name']) ?>
                                        </p>
                                    </a>
                                <?php endif; ?>
                            </li>
                        <?php endforeach; ?>
                    </ul>
                </li>

                <!-- VERIFIKASI (Admin & Assessor) -->
                <?php if (function_exists('canVerify') && canVerify()): ?>
                <li class="nav-header">VERIFIKASI</li>
                <li class="nav-item">
                    <a href="<?= BASE_URL ?>pages/verification.php"
                       class="nav-link <?= $current_page == 'verification.php' ? 'active' : '' ?>">
                        <i class="nav-icon fas fa-clipboard-check text-warning"></i>
                        <p>
                            Verifikasi Assessment
                            <?php if ($pending_verification > 0): ?>
                                <span class="badge badge-warning right"><?= $pending_verification ?></span>
                            <?php endif; ?>
                        </p>
                    </a>
                </li>
                <li class="nav-item">
                    <a href="<?= BASE_URL ?>pages/evidence_list.php"
                       class="nav-link <?= $current_page == 'evidence_list.php' ? 'active' : '' ?>">
                        <i class="nav-icon fas fa-paperclip text-info"></i>
                        <p>Evidence Files</p>
                    </a>
                </li>
                <?php endif; ?>

                <!-- LAPORAN -->
                <li class="nav-header">LAPORAN</li>
                <li class="nav-item">
                    <a href="<?= BASE_URL ?>pages/report_summary.php"
                       class="nav-link <?= $current_page == 'report_summary.php' ? 'active' : '' ?>">
                        <i class="nav-icon fas fa-chart-bar"></i>
                        <p>Ringkasan Assessment</p>
                    </a>
                </li>
                <li class="nav-item">
                    <a href="<?= BASE_URL ?>pages/report_gap.php"
                       class="nav-link <?= $current_page == 'report_gap.php' ? 'active' : '' ?>">
                        <i class="nav-icon fas fa-chart-line"></i>
                        <p>Gap Analysis</p>
                    </a>
                </li>
                <li class="nav-item">
                    <a href="<?= BASE_URL ?>pages/report_action.php"
                       class="nav-link <?= $current_page == 'report_action.php' ? 'active' : '' ?>">
                        <i class="nav-icon fas fa-tasks"></i>
                        <p>Action Plan</p>
                    </a>
                </li>

                <!-- ADMINISTRASI (semua user login bisa lihat; admin only bisa edit) -->
                <?php if (function_exists('canViewAdmin') && canViewAdmin()): ?>
                <li class="nav-header">ADMINISTRASI</li>
                <li class="nav-item">
                    <a href="<?= BASE_URL ?>pages/role_setting.php"
                       class="nav-link <?= $current_page == 'role_setting.php' ? 'active' : '' ?>">
                        <i class="nav-icon fas fa-cog"></i>
                        <p>Role &amp; RASCI Setting <span class="badge badge-warning right">NEW</span></p>
                    </a>
                </li>
                <?php if (canAdminister()): ?>
                <li class="nav-item">
                    <a href="<?= BASE_URL ?>pages/users.php"
                       class="nav-link <?= $current_page == 'users.php' ? 'active' : '' ?>">
                        <i class="nav-icon fas fa-users"></i>
                        <p>Manajemen User</p>
                    </a>
                </li>
                <?php endif; ?>
                <?php if (canAdminister()): ?>
                <li class="nav-item">
                    <a href="<?= BASE_URL ?>pages/questions.php"
                       class="nav-link <?= $current_page == 'questions.php' ? 'active' : '' ?>">
                        <i class="nav-icon fas fa-question-circle"></i>
                        <p>Kelola Pertanyaan</p>
                    </a>
                </li>
                <?php endif; ?>
                <li class="nav-item">
                    <a href="<?= BASE_URL ?>pages/rasci.php"
                       class="nav-link <?= $current_page == 'rasci.php' ? 'active' : '' ?>">
                        <i class="nav-icon fas fa-sitemap"></i>
                        <p>RASCI Matrix</p>
                    </a>
                </li>
                <li class="nav-item">
                    <a href="<?= BASE_URL ?>pages/question_rasci.php"
                       class="nav-link <?= $current_page == 'question_rasci.php' ? 'active' : '' ?>">
                        <i class="nav-icon fas fa-layer-group"></i>
                        <p>RASCI Per-Pertanyaan <span class="badge badge-info right">pilot</span></p>
                    </a>
                </li>
                <li class="nav-item">
                    <a href="<?= BASE_URL ?>pages/sessions.php"
                       class="nav-link <?= $current_page == 'sessions.php' ? 'active' : '' ?>">
                        <i class="nav-icon fas fa-calendar-alt"></i>
                        <p>Periode Assessment</p>
                    </a>
                </li>
                <?php if (canAdminister()): ?>
                <li class="nav-item">
                    <a href="<?= BASE_URL ?>pages/activity_log.php"
                       class="nav-link <?= $current_page == 'activity_log.php' ? 'active' : '' ?>">
                        <i class="nav-icon fas fa-history"></i>
                        <p>Activity Log</p>
                    </a>
                </li>
                <li class="nav-item">
                    <a href="<?= BASE_URL ?>pages/email_log.php"
                       class="nav-link <?= $current_page == 'email_log.php' ? 'active' : '' ?>">
                        <i class="nav-icon fas fa-envelope-open-text"></i>
                        <p>Email Log</p>
                    </a>
                </li>
                <li class="nav-item">
                    <a href="<?= BASE_URL ?>pages/evidence_settings.php"
                       class="nav-link <?= $current_page == 'evidence_settings.php' ? 'active' : '' ?>">
                        <i class="nav-icon fas fa-folder-tree"></i>
                        <p>Evidence Settings</p>
                    </a>
                </li>
                <?php endif; ?>
                <?php endif; ?>

                <!-- SISTEM -->
                <li class="nav-header">SISTEM</li>
                <li class="nav-item">
                    <a href="<?= BASE_URL ?>pages/about.php"
                       class="nav-link <?= $current_page == 'about.php' ? 'active' : '' ?>">
                        <i class="nav-icon fas fa-info-circle"></i>
                        <p>Tentang</p>
                    </a>
                </li>
                <li class="nav-item">
                    <a href="<?= BASE_URL ?>logout.php" class="nav-link">
                        <i class="nav-icon fas fa-sign-out-alt text-danger"></i>
                        <p>Logout</p>
                    </a>
                </li>
            </ul>
        </nav>
    </div>
</aside>

<style>
/* Sidebar scroll fix */
.main-sidebar { height: 100vh !important; max-height: 100vh !important; }
.main-sidebar .sidebar {
    height: calc(100vh - 160px) !important;
    max-height: calc(100vh - 160px) !important;
    overflow-y: auto !important; overflow-x: hidden !important;
    padding-bottom: 60px !important;
}
.main-sidebar .sidebar::-webkit-scrollbar { width: 6px; }
.main-sidebar .sidebar::-webkit-scrollbar-track { background: rgba(255, 255, 255, 0.05); }
.main-sidebar .sidebar::-webkit-scrollbar-thumb { background: rgba(255, 255, 255, 0.2); border-radius: 3px; }

/* Brand */
.main-sidebar .brand-link {
    height: auto !important; padding: 1rem 0.5rem !important;
    display: block !important; text-align: center;
    border-bottom: 1px solid rgba(255,255,255,0.1);
    position: sticky; top: 0; z-index: 2; background: inherit;
}
.main-sidebar .brand-link .brand-image-custom {
    display: block; margin: 0 auto 8px;
    background: #fff; padding: 4px 8px; border-radius: 4px;
}
.main-sidebar .brand-link .brand-text {
    color: #fff; font-size: 1rem; letter-spacing: 0.5px; line-height: 1.2;
}
.main-sidebar .brand-link .brand-tagline {
    color: #fff; font-size: 0.9rem; letter-spacing: 0.3px;
    margin-top: 4px; opacity: 0.9; font-style: italic;
}

.main-sidebar .brand-link .brand-created {
    color: #ffc107; font-size: 0.72rem; letter-spacing: 0.3px;
    margin-top: 4px; opacity: 0.9; font-style: italic;
}

/* RASCI & Lock */
.rasci-badge { font-size: 8px !important; padding: 2px 4px; margin-left: 2px; min-width: 16px; }
.rasci-badge + .rasci-badge { margin-left: 1px; }
.nav-sidebar .nav-link-locked {
    opacity: 0.4 !important; cursor: not-allowed !important; color: #adb5bd !important;
}
.nav-sidebar .nav-link-locked .nav-icon { color: #6c757d !important; }
.user-panel .badge { font-size: 10px; padding: 2px 6px; vertical-align: middle; }
.nav-header small.text-warning { font-size: 10px; margin-left: 4px; opacity: 0.85; }

/* ============================================= */
/* NOTIFICATION PANEL STYLING                    */
/* ============================================= */
.sidebar-notif-wrap {
    padding: 0 12px;
    border-bottom: 1px solid rgba(255,255,255,0.1);
    position: relative;
}
.sidebar-notif-btn {
    width: 100%;
    display: flex;
    align-items: center;
    justify-content: space-between;
    padding: 8px 10px;
    background: rgba(255,255,255,0.05);
    border: 1px solid rgba(255,255,255,0.1);
    border-radius: 6px;
    color: #fff;
    font-size: 12px;
    cursor: pointer;
    transition: all 0.15s;
}
.sidebar-notif-btn:hover { background: rgba(255,255,255,0.12); }
.sidebar-notif-btn i { color: #ffc107; margin-right: 6px; }
.sidebar-notif-badge {
    background: #dc3545; color: #fff;
    padding: 2px 8px; border-radius: 10px;
    font-size: 10px; font-weight: 500;
    min-width: 20px; text-align: center;
}

/* Dropdown */
.sidebar-notif-dropdown {
    position: absolute;
    top: 100%; left: 12px; right: 12px;
    margin-top: 4px;
    background: #fff;
    border-radius: 6px;
    box-shadow: 0 6px 20px rgba(0,0,0,0.35);
    z-index: 1050;
    max-height: 480px;
    display: flex;
    flex-direction: column;
    color: #333;
}
.sidebar-notif-header {
    padding: 10px 12px;
    background: #f8f9fa;
    border-bottom: 1px solid #dee2e6;
    border-radius: 6px 6px 0 0;
    display: flex;
    justify-content: space-between;
    align-items: center;
    font-size: 13px;
}
.sidebar-notif-mark-all {
    background: none; border: none;
    color: #007bff; font-size: 11px; cursor: pointer;
    padding: 2px 6px;
}
.sidebar-notif-mark-all:hover { text-decoration: underline; }
.sidebar-notif-body {
    flex: 1;
    overflow-y: auto;
    max-height: 360px;
}
.sidebar-notif-body::-webkit-scrollbar { width: 6px; }
.sidebar-notif-body::-webkit-scrollbar-track { background: #f1f1f1; }
.sidebar-notif-body::-webkit-scrollbar-thumb { background: #ccc; border-radius: 3px; }

.sidebar-notif-item {
    display: flex;
    gap: 10px;
    padding: 10px 12px;
    border-bottom: 1px solid #f0f0f0;
    color: #333;
    text-decoration: none;
    transition: background 0.15s;
}
.sidebar-notif-item:hover {
    background: #f8f9fa;
    text-decoration: none;
    color: #333;
}
.sidebar-notif-item.unread {
    background: #fff8e1;
    border-left: 3px solid #ffc107;
}
.sidebar-notif-item.unread:hover { background: #fff3c4; }
.sidebar-notif-icon {
    width: 30px; height: 30px;
    border-radius: 50%;
    flex-shrink: 0;
    display: flex;
    align-items: center;
    justify-content: center;
    color: #fff;
    font-size: 12px;
}
.sidebar-notif-content { flex: 1; min-width: 0; }
.sidebar-notif-title {
    font-size: 12px;
    font-weight: 500;
    color: #333;
    line-height: 1.3;
}
.sidebar-notif-new {
    background: #dc3545; color: #fff;
    padding: 1px 5px; border-radius: 8px;
    font-size: 8px; margin-left: 4px;
    vertical-align: middle;
}
.sidebar-notif-msg {
    font-size: 11px;
    color: #666;
    margin-top: 2px;
    line-height: 1.4;
    overflow: hidden;
}
.sidebar-notif-time {
    font-size: 10px;
    color: #999;
    margin-top: 3px;
}
.sidebar-notif-empty {
    padding: 30px 12px;
    text-align: center;
    color: #999;
}
.sidebar-notif-footer {
    padding: 8px 12px;
    background: #f8f9fa;
    border-top: 1px solid #dee2e6;
    border-radius: 0 0 6px 6px;
    text-align: center;
    font-size: 11px;
}
.sidebar-notif-footer a {
    color: #007bff;
    text-decoration: none;
}
.sidebar-notif-footer a:hover { text-decoration: underline; }
</style>

<script>
jQuery(function($) {
    const $dropdown = $('#sidebarNotifDropdown');
    const $toggle   = $('#sidebarNotifToggle');
    const $badge    = $('#sidebarNotifBadge');

    // Toggle dropdown
    $toggle.on('click', function(e) {
        e.stopPropagation();
        $dropdown.toggle();
    });

    // Close dropdown kalau klik di luar
    $(document).on('click', function(e) {
        if (!$(e.target).closest('.sidebar-notif-wrap').length) {
            $dropdown.hide();
        }
    });

    // Klik notif item → mark as read, lalu ikuti link
    $('.sidebar-notif-item').on('click', function(e) {
        const $item = $(this);
        const id = $item.attr('data-id');
        const href = $item.attr('href');
        const wasUnread = $item.hasClass('unread');

        if (wasUnread && id) {
            e.preventDefault();
            $.post('<?= BASE_URL ?>pages/ajax_notifications.php', {
                action: 'mark_read', id: id
            }).done(function(res) {
                $item.removeClass('unread');
                $item.find('.sidebar-notif-new').remove();

                if (res.unread > 0) {
                    $badge.text(res.unread).show();
                } else {
                    $badge.hide();
                }

                // Redirect kalau ada link
                if (href && href !== '#') {
                    window.location.href = href;
                }
            }).fail(function() {
                if (href && href !== '#') window.location.href = href;
            });
        }
    });

    // Mark all as read
    $('#btnMarkAllRead').on('click', function(e) {
        e.preventDefault();
        e.stopPropagation();

        $.post('<?= BASE_URL ?>pages/ajax_notifications.php', {
            action: 'mark_all_read'
        }).done(function(res) {
            if (res.ok) {
                $('.sidebar-notif-item.unread').removeClass('unread');
                $('.sidebar-notif-new').remove();
                $badge.hide();
                $('#btnMarkAllRead').hide();
            }
        });
    });
});
</script>