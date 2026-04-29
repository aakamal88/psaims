<?php
/**
 * =====================================================
 * PSAIMS SELF ASSESSMENT TOOL
 * Database Configuration - PostgreSQL
 * =====================================================
 */

// Sesuaikan dengan konfigurasi PostgreSQL Anda
define('DB_HOST', 'localhost');
define('DB_PORT', '5432');
define('DB_NAME', 'psaims_db');
define('DB_USER', 'adminpsaims');
define('DB_PASS', 'admin1234');   // GANTI dengan password PostgreSQL Anda

/**
 * Koneksi PDO ke PostgreSQL
 */
function getDBConnection() {
    try {
        $dsn = "pgsql:host=" . DB_HOST . ";port=" . DB_PORT . ";dbname=" . DB_NAME;
        $pdo = new PDO($dsn, DB_USER, DB_PASS, [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
        ]);
        return $pdo;
    } catch (PDOException $e) {
        die("Koneksi database gagal: " . $e->getMessage());
    }
}

// Buat koneksi global
$pdo = getDBConnection();
