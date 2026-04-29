<?php
/**
 * =====================================================
 * PSAIMS SELF ASSESSMENT TOOL
 * Application Configuration
 * =====================================================
 * Updated: include role hierarchy (admin > assessor > user)
 */

// Timezone
date_default_timezone_set('Asia/Jakarta');

// Konfigurasi Aplikasi
define('APP_NAME',    'PSAIMS SELF ASSESSMENT TOOL');
define('APP_VERSION', '1.0.0');
define('APP_AUTHOR',  'PT Pertamina Gas');
define('BASE_URL',    '/PTG_PSAIMS/');

// Path Konstanta
define('ROOT_PATH',     dirname(__DIR__));
define('ADMINLTE_PATH', BASE_URL . 'Adminlte/');
define('ASSETS_PATH',   BASE_URL . 'assets/');
define('UPLOAD_PATH',   ROOT_PATH . '/uploads/');

// Start session kalau belum ada
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Load database
require_once __DIR__ . '/database.php';

// =====================================================
// AUTHENTICATION HELPERS
// =====================================================

/**
 * Cek apakah user sudah login
 */
function isLoggedIn() {
    return isset($_SESSION['user_id']) && !empty($_SESSION['user_id']);
}

/**
 * Redirect kalau belum login
 */
function requireLogin() {
    if (!isLoggedIn()) {
        header('Location: ' . BASE_URL . 'login.php');
        exit;
    }
}

/**
 * Ambil info user yang login
 */
function currentUser() {
    if (!isLoggedIn()) return null;
    return [
        'id'        => $_SESSION['user_id']   ?? null,
        'username'  => $_SESSION['username']  ?? '',
        'full_name' => $_SESSION['full_name'] ?? '',
        'role'      => $_SESSION['role']      ?? 'user',
    ];
}

// =====================================================
// ROLE & PERMISSION HELPERS
// Hierarchy: admin (3) > assessor (2) > user (1)
// =====================================================

/**
 * Cek apakah user punya role tertentu ATAU role dengan level lebih tinggi.
 *
 * hasRole('user')     -> semua yang login (admin, assessor, user)
 * hasRole('assessor') -> admin dan assessor saja
 * hasRole('admin')    -> hanya admin
 */
function hasRole($role) {
    $current = $_SESSION['role'] ?? 'user';

    $hierarchy = [
        'admin'    => 3,
        'assessor' => 2,
        'user'     => 1,
    ];

    $current_level  = $hierarchy[$current] ?? 0;
    $required_level = $hierarchy[$role]    ?? 0;

    return $current_level >= $required_level;
}

/**
 * Cek EXACT role (tanpa hierarchy).
 * Pakai ini kalau butuh handle logic khusus per-role.
 */
function isRole($role) {
    return ($_SESSION['role'] ?? null) === $role;
}

/**
 * Shortcut: user adalah admin?
 */
function isAdmin() {
    return isRole('admin');
}

/**
 * Shortcut: user adalah assessor?
 */
function isAssessor() {
    return isRole('assessor');
}

/**
 * Bisa verifikasi jawaban? (Admin dan Assessor)
 */
function canVerify() {
    return hasRole('assessor');
}

/**
 * Bisa akses menu admin (setting, role, user, session)? (Admin only)
 */
function canAdminister() {
    return isAdmin();
}

/**
 * Bisa LIHAT menu administrasi yang public-readable?
 * Admin: full access (edit + view)
 * Assessor + User: read-only access ke halaman tertentu
 *
 * Halaman yang bisa di-view oleh non-admin:
 * - Role & RASCI Setting, Kelola Pertanyaan, RASCI Matrix,
 *   RASCI Per-Pertanyaan, Periode Assessment, Activity Log
 *
 * Halaman yang TETAP admin-only (cek via canAdminister):
 * - Manajemen User, Email Log, Evidence Settings
 */
function canViewAdmin() {
    return isLoggedIn();  // semua user yang login boleh view
}

/**
 * Render banner peringatan mode read-only.
 * Pakai di awal section content halaman administrasi.
 */
function readOnlyBanner() {
    if (canAdminister()) return '';  // admin tidak perlu banner

    return '<div class="alert alert-warning shadow-sm" style="border-left: 4px solid #ffc107;">'
         . '<div class="d-flex align-items-center">'
         . '<i class="fas fa-eye fa-2x mr-3 text-warning"></i>'
         . '<div>'
         . '<h5 class="mb-1"><strong>Mode Lihat Saja</strong></h5>'
         . '<p class="mb-0">'
         . 'Anda login sebagai <strong>' . e(roleLabel()) . '</strong>. '
         . 'Halaman ini hanya dapat dilihat. '
         . 'Untuk melakukan perubahan, hubungi <strong>Administrator</strong>.'
         . '</p>'
         . '</div>'
         . '</div>'
         . '</div>';
}

/**
 * Safety net: block POST request dari non-admin di halaman administrasi.
 * Pakai di awal halaman, sebelum handle POST.
 */
function blockNonAdminPost() {
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && !canAdminister()) {
        http_response_code(403);
        die('Akses ditolak. Hanya administrator yang dapat melakukan perubahan.');
    }
}

/**
 * Bisa lihat laporan lengkap? (Admin dan Assessor)
 */
function canViewAllReports() {
    return hasRole('assessor');
}

/**
 * Label human-readable untuk role
 */
function roleLabel($role = null) {
    $role = $role ?? ($_SESSION['role'] ?? 'user');
    return match($role) {
        'admin'    => 'Administrator',
        'assessor' => 'Assessor',
        'user'     => 'User',
        default    => ucfirst($role),
    };
}

/**
 * Badge color untuk role di UI
 */
function roleBadgeColor($role = null) {
    $role = $role ?? ($_SESSION['role'] ?? 'user');
    return match($role) {
        'admin'    => 'danger',
        'assessor' => 'warning',
        'user'     => 'light',
        default    => 'secondary',
    };
}

// =====================================================
// UTILITY HELPERS
// =====================================================

/**
 * Escape output untuk mencegah XSS
 */
function e($str) {
    return htmlspecialchars($str ?? '', ENT_QUOTES, 'UTF-8');
}

/**
 * Log aktivitas user ke activity_log
 */
function logActivity($action, $description = '') {
    global $pdo;
    if (!isLoggedIn()) return;
    try {
        $stmt = $pdo->prepare(
            "INSERT INTO activity_log (user_id, action, description, ip_address)
             VALUES (?, ?, ?, ?)"
        );
        $stmt->execute([
            $_SESSION['user_id'],
            $action,
            $description,
            $_SERVER['REMOTE_ADDR'] ?? ''
        ]);
    } catch (Exception $ex) { /* silent fail */ }
}