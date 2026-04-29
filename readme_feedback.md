# Integrasi Email Notification ke verification.php

## Step 1 — Tambah require di atas file `pages/verification.php`

Cari baris:
```php
require_once __DIR__ . '/../config/config.php';
requireLogin();
```

Ubah menjadi:
```php
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../includes/mailer.php';  // TAMBAH INI
requireLogin();
```

## Step 2 — Modifikasi handler POST untuk verify/return

Cari bagian handler POST (sekitar baris 20-60 di verification.php):

```php
if ($action === 'verify' || $action === 'return') {
    // ... kode yang ada ...
```

Setelah `logActivity('VERIFY', ...);` dan sebelum set `$msg`, tambahkan blok ini:

```php
// ========== SEND EMAIL NOTIFICATION ==========
try {
    // Ambil data detail hasil verifikasi untuk email
    $stmt_email = $pdo->prepare(
        "SELECT
            ar.score,
            ar.verification_status,
            ar.assessor_comment,
            ar.verified_at,
            q.criteria,
            e.element_name,
            u.full_name,
            u.email AS user_email,
            verifier.full_name AS verified_by_name
         FROM assessment_results ar
         JOIN assessment_questions q ON q.id = ar.question_id
         JOIN psaims_elements e ON e.id = q.element_id
         JOIN users u ON u.id = ar.user_id
         LEFT JOIN users verifier ON verifier.id = ar.verified_by
         WHERE ar.id = ?"
    );
    $stmt_email->execute([$result_id]);
    $email_data = $stmt_email->fetch();

    if ($email_data && !empty($email_data['user_email'])) {
        $ref = '';
        if (preg_match('/\[Ref\s+([0-9.a-z]+)\]/i', $email_data['criteria'], $m)) {
            $ref = $m[1];
        }

        $user_row = [
            'email'     => $email_data['user_email'],
            'full_name' => $email_data['full_name'],
        ];
        $result_payload = [
            'element_name'      => $email_data['element_name'],
            'question_ref'      => $ref ? "Ref {$ref}" : 'Pertanyaan',
            'score'             => $email_data['score'],
            'verified_by_name'  => $email_data['verified_by_name'] ?? 'Assessor',
            'verified_at'       => $email_data['verified_at'],
            'assessor_comment'  => $email_data['assessor_comment'],
        ];

        if ($action === 'verify') {
            sendVerifiedEmail($user_row, $result_payload);
        } else {
            sendReturnedEmail($user_row, $result_payload);
        }
    }
} catch (Exception $email_ex) {
    // Email gagal, tapi tidak block proses verifikasi
    error_log("Email notification failed: " . $email_ex->getMessage());
}
```

## Step 3 — Juga di handler bulk_verify

Cari bagian `if ($action === 'bulk_verify')`. Di loop foreach setelah insert history, tambahkan block email yang sama (dengan penyesuaian: status='verified' dan no comment).

## Cara Aktifkan Email

1. Edit file `config/email.php`
2. Set `EMAIL_ENABLED` ke `true`
3. Sesuaikan SMTP host, port, user, password sesuai infrastruktur Pertagas
4. Test kirim email dengan script `test_email.php` (lihat bawah)

## Script test email

Buat file `test_email.php` di root folder:

```php
<?php
require_once __DIR__ . '/config/config.php';
require_once __DIR__ . '/includes/mailer.php';

$test_user = [
    'email'     => 'admin@pertagas.com',  // Ganti dengan email Anda
    'full_name' => 'Test User',
];
$test_result = [
    'element_name'      => 'E01 Kepemimpinan',
    'question_ref'      => 'Ref 1.1',
    'score'             => 75,
    'verified_by_name'  => 'Assessor Demo',
    'verified_at'       => date('Y-m-d H:i:s'),
    'assessor_comment'  => 'Evidence kurang detail, mohon lengkapi dokumen pendukung.',
];

$result = sendReturnedEmail($test_user, $test_result);
echo $result ? "Email sent OK!" : "Email FAILED (cek config/email.php dan log)";
```

Akses via browser: `http://localhost/PTG_PSAIMS/test_email.php`
Hapus file ini setelah test selesai!

## Catatan Penting

- Kalau pakai IIS di Windows: PHP's `mail()` butuh SMTP config di `php.ini`
  ```ini
  SMTP = smtp.office365.com
  smtp_port = 587
  sendmail_from = psaims@pertagas.com
  ```
- Atau install `PHPMailer` via Composer untuk SMTP authentication yang lebih reliable
- File log email tersimpan di `logs/email.log` kalau `EMAIL_DEBUG = true`