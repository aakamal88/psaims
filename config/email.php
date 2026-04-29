<?php
/**
 * =====================================================
 * EMAIL CONFIGURATION
 * =====================================================
 * Taruh file ini di: config/email.php
 *
 * CATATAN:
 * - Kalau SMTP BELUM SIAP, set EMAIL_ENABLED = false
 * - Sistem akan tetap jalan normal, email tidak dikirim
 * - Bisa diaktifkan kapan saja dengan set ke true
 * =====================================================
 */

// MASTER SWITCH — set false kalau SMTP belum ready
define('EMAIL_ENABLED', false);

// SMTP Configuration (Pertagas internal / Office 365)
define('EMAIL_SMTP_HOST',     'smtp.office365.com');   // Atau server SMTP internal Pertagas
define('EMAIL_SMTP_PORT',     587);                     // 587 (TLS) atau 465 (SSL) atau 25 (plain)
define('EMAIL_SMTP_SECURE',   'tls');                   // 'tls' atau 'ssl' atau '' (none)
define('EMAIL_SMTP_USER',     'psaims@pertagas.com');   // Email akun pengirim
define('EMAIL_SMTP_PASS',     'YOUR_PASSWORD_HERE');    // Password app, ganti!
define('EMAIL_SMTP_TIMEOUT',  10);                      // Timeout koneksi (detik)

// Sender identity
define('EMAIL_FROM_ADDRESS',  'psaims@pertagas.com');
define('EMAIL_FROM_NAME',     'PSAIMS Notification');
define('EMAIL_REPLY_TO',      'psaims@pertagas.com');

// URL untuk link di email (biasanya web server internal)
define('EMAIL_APP_URL',       'http://psaims.pertagas.local/PTG_PSAIMS/');
// Atau kalau akses via IP:
// define('EMAIL_APP_URL', 'http://10.20.30.40/PTG_PSAIMS/');

// Debug mode (nyalakan kalau mau test, simpan log di file)
define('EMAIL_DEBUG',         false);
define('EMAIL_LOG_PATH',      __DIR__ . '/../logs/email.log');