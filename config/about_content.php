<?php
/**
 * =====================================================
 * KONTEN HALAMAN "TENTANG"
 * =====================================================
 *
 * File ini berisi semua teks yang ditampilkan di halaman About.
 * Anda bisa edit file ini KAPAN SAJA tanpa perlu tahu PHP.
 *
 * Aturan:
 * - Ganti teks di antara tanda kutip (' atau ")
 * - Jangan hapus tanda kutip atau koma
 * - Untuk baris baru di teks panjang, gunakan \n
 * - Backup file ini sebelum edit kalau perlu
 *
 * Setelah edit, simpan file ini, lalu refresh halaman About.
 * =====================================================
 */

return [

    // ============ HEADER / IDENTITAS APLIKASI ============
    'app_name'      => 'PSAIMS Self-Assessment Tool',
    'app_full_name' => 'Process Safety & Asset Integrity Management System',
    'tagline'       => 'Know the Gap, Close the Gap.',
    'description'   => 'Aplikasi web untuk melakukan self-assessment kematangan implementasi PSAIMS di lingkungan PT Pertamina Gas. Tool ini membantu mengidentifikasi gap antara kondisi saat ini dengan standar PSAIMS, memfasilitasi action plan, dan menyediakan dashboard eksekutif untuk monitoring berkelanjutan.',

    // ============ VERSI & TANGGAL RILIS ============
    'version'       => '1.0.0',
    'release_date'  => 'April 2026',
    'status'        => 'Production',

    // ============ FITUR UTAMA ============
    'features' => [
        ['fa-clipboard-list',   'Self-Assessment 18 Elemen',
         'Mengelola assessment terhadap 18 elemen PSAIMS dengan total 242 persyaratan/pertanyaan audit.'],

        ['fa-sitemap',          'RASCI Matrix Dinamis',
         'Pengaturan responsibility (Responsible, Accountable, Consult, Inform, Support) per elemen atau per pertanyaan untuk fleksibilitas penugasan.'],

        ['fa-clipboard-check',  'Workflow Verifikasi',
         'Assessor dapat memverifikasi atau mengembalikan jawaban user dengan komentar untuk perbaikan.'],

        ['fa-paperclip',        'Upload Evidence Multi-File',
         'User dapat melampirkan file pendukung (PDF, Word, Excel, gambar, video) per pertanyaan dengan auto-folder per elemen.'],

        ['fa-chart-bar',        'Dashboard & Laporan',
         'Ringkasan eksekutif, gap analysis, action plan tracker, heatmap 18 elemen, dan trend antar periode.'],

        ['fa-bell',             'Notifikasi & Email Alert',
         'Sistem inbox internal & email otomatis untuk verifikasi, return, dan reminder action plan.'],

        ['fa-user-shield',      'Multi-Role Access',
         '3-tier role: Administrator (full access), Assessor (verifikasi), User (isi assessment per RASCI).'],

        ['fa-history',          'Activity Log & Audit Trail',
         'Setiap aksi user tercatat untuk keperluan audit & compliance.'],
    ],

    // ============ TECH STACK ============
    'tech_stack' => [
        ['fab fa-php',          'PHP 8.5',          'Backend logic & rendering'],
        ['fas fa-database',     'PostgreSQL 16',    'Database utama'],
        ['fab fa-js',           'jQuery 3.x',       'Frontend interactivity'],
        ['fab fa-bootstrap',    'Bootstrap 4',      'UI framework via AdminLTE'],
        ['fas fa-server',       'IIS 10 + Windows', 'Web server'],
        ['fas fa-chart-pie',    'Chart.js',         'Visualisasi grafik'],
    ],

    // ============ CREDIT / DEVELOPER ============
    'credits' => [
        'developer'  => 'Ahmad Kamaludin',
        'role'       => 'Asset Reliability and Integrity',
        'company'    => 'PT Pertamina Gas',
        'unit'       => 'PSAIMS Tools Development',
        'email'      => '',
    ],

    // ============ INFO KONTAK / SUPPORT ============
    'contact' => [
        'support_email'   => 'psaims.support@pertagas.com',
        'documentation'   => 'Hubungi admin sistem',
        'office_hours'    => 'Senin - Jumat, 08:00 - 17:00 WIB',
    ],

    // ============ CHANGELOG / HISTORY ============
    'changelog' => [
        [
            'version' => '1.0.0',
            'date'    => 'April 2026',
            'changes' => [
                'Initial release',
                'Self-assessment 18 elemen PSAIMS dengan 242 pertanyaan',
                'RASCI matrix per-elemen & per-pertanyaan',
                'Workflow verifikasi assessor',
                'Sistem upload evidence file dengan organisasi per elemen & per user',
                'Dashboard laporan: Ringkasan, Gap Analysis, Action Plan',
                'Sistem notifikasi internal & email',
                'Activity log & audit trail',
            ],
        ],
    ],

    // ============ LISENSI / DISCLAIMER ============
    'license' => 'Aplikasi internal PT Pertamina Gas. Tidak untuk distribusi eksternal tanpa izin tertulis.',

    // ============ PESAN PENUTUP ============
    'closing_message' => 'Terima kasih telah menggunakan PSAIMS Self-Assessment Tool. Mari bersama mewujudkan zero-incident dan operational excellence.',
];