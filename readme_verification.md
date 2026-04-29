-- =====================================================
-- PANDUAN UPDATE: Tambah workflow verifikasi di halaman existing
-- =====================================================

# 1. UPDATE sidebar.php

## A. Tambah badge role untuk assessor

Cari baris yang mirip ini di sidebar.php (bagian user panel):
```php
<?php if ($is_admin): ?>
    <span class="badge badge-danger">Administrator</span>
<?php elseif ($role_code): ?>
    ...
```

Ganti dengan:
```php
<?php if (isAdmin()): ?>
    <span class="badge badge-danger">Administrator</span>
<?php elseif (isAssessor()): ?>
    <span class="badge badge-warning">Assessor</span>
<?php elseif ($role_code): ?>
    <span class="badge badge-<?= e($sidebar_role_color) ?>"><?= e($role_code) ?></span>
<?php else: ?>
    <span class="badge badge-secondary">No Role</span>
<?php endif; ?>
```

## B. Tambah menu "Verifikasi Assessment"

Cari bagian ADMINISTRASI di sidebar. Tambahkan menu BARU di atas "Manajemen User":

```php
<?php if (canVerify()): ?>
<li class="nav-header">VERIFIKASI</li>
<li class="nav-item">
    <a href="<?= BASE_URL ?>pages/verification.php"
       class="nav-link <?= $current_page == 'verification.php' ? 'active' : '' ?>">
        <i class="nav-icon fas fa-clipboard-check text-warning"></i>
        <p>
            Verifikasi Assessment
            <?php
            // Counter jawaban menunggu
            try {
                $cnt = $pdo->query(
                    "SELECT COUNT(*) FROM assessment_results WHERE verification_status = 'submitted'"
                )->fetchColumn();
                if ($cnt > 0) echo '<span class="badge badge-warning right">' . $cnt . '</span>';
            } catch (Exception $e) {}
            ?>
        </p>
    </a>
</li>
<?php endif; ?>
```

## C. Ubah proteksi admin-only menjadi admin-strict

Cari baris yang guard menu admin (`if ($is_admin)`) untuk:
- Role & RASCI Setting
- Manajemen User
- RASCI Per-Pertanyaan
- Periode Assessment
- Kelola Pertanyaan
- Activity Log

Ganti semua `if ($is_admin)` menjadi `if (canAdminister())` supaya HANYA admin (bukan assessor) yang bisa akses.

---

# 2. UPDATE config.php

Buka `config/config.php`, cari fungsi `hasRole()` yang sudah ada.

REPLACE fungsi lama dengan fungsi baru dari file `role_helpers.php`
(copy semua fungsi di situ, atau replace yang sudah ada).

Tambahkan juga fungsi-fungsi baru:
- `isRole()`, `isAdmin()`, `isAssessor()`
- `canVerify()`, `canAdminister()`, `canViewAllReports()`
- `roleLabel()`, `roleBadgeColor()`

---

# 3. UPDATE assessment.php — Tambah tombol "Submit untuk Verifikasi"

## A. Tambah handler POST untuk submit

Di bagian handle POST (sekitar awal file), tambahkan:
```php
// Handle submit untuk verifikasi
if (isset($_POST['submit_for_review'])) {
    try {
        $stmt = $pdo->prepare(
            "UPDATE assessment_results
             SET verification_status = 'submitted',
                 submitted_at = CURRENT_TIMESTAMP
             WHERE session_id = ? AND element_id = ?
               AND user_id = ?
               AND verification_status IN ('draft', 'returned')"
        );
        $stmt->execute([$active_session['id'], $element['id'], $user['id']]);
        $count = $stmt->rowCount();

        logActivity('SUBMIT_REVIEW', "Submit E{$element_number} untuk review ({$count} jawaban)");
        $msg = "{$count} jawaban berhasil disubmit untuk verifikasi. Menunggu review assessor.";
        $msg_type = 'success';
    } catch (Exception $e) {
        $msg = 'Gagal submit: ' . $e->getMessage();
        $msg_type = 'danger';
    }
}
```

## B. Query jawaban existing — tambah field verification_status

Cari query yang ambil jawaban user (biasanya di sekitar "SELECT ... FROM assessment_results").
Tambahkan kolom `verification_status` dan `assessor_comment` di SELECT.

## C. Lock form kalau status = submitted/verified

Di dalam loop render pertanyaan, di mana form score/evidence ada:

```php
$is_locked = in_array($q_result['verification_status'] ?? 'draft', ['submitted', 'verified']);
$locked_attr = $is_locked ? 'readonly disabled' : '';
```

Lalu tambahkan class ke input dan select. Untuk "verified" bisa dipakai badge hijau, untuk "submitted" badge kuning.

## D. Banner komentar assessor kalau status = returned

```php
<?php if (($q_result['verification_status'] ?? '') === 'returned' && !empty($q_result['assessor_comment'])): ?>
    <div class="alert alert-danger py-2" style="font-size:12px;">
        <i class="fas fa-undo"></i> <strong>Assessor meminta revisi:</strong>
        <br><?= e($q_result['assessor_comment']) ?>
    </div>
<?php endif; ?>
```

## E. Tambah tombol "Submit untuk Verifikasi" di footer

Di footer halaman assessment (setelah tombol Save), tambahkan:

```php
<?php if (!$is_admin && !canVerify()): ?>
    <form method="POST" style="display:inline;"
          onsubmit="return confirm('Submit semua jawaban di elemen ini untuk diverifikasi?\nJawaban tidak bisa diedit lagi setelah disubmit.');">
        <button type="submit" name="submit_for_review" class="btn btn-warning btn-lg ml-2">
            <i class="fas fa-paper-plane"></i> Submit untuk Verifikasi
        </button>
    </form>
<?php endif; ?>
```

---

# 4. INSTALL CHECKLIST

Urutan eksekusi:
1. Backup database dulu (pg_dump)
2. Run `verification_migration.sql` di pgAdmin sebagai user `postgres`
3. Replace fungsi di `config/config.php` dengan yang dari `role_helpers.php`
4. Upload `verification.php` ke `/pages/`
5. Update `sidebar.php` sesuai instruksi di atas
6. Update `assessment.php` sesuai instruksi di atas (A-E)
7. Test dengan user:
   - Login `assessor1` / admin1234 → cek menu Verifikasi muncul
   - Login user biasa → cek tombol "Submit untuk Verifikasi" ada
   - Test flow: submit → review → verify/return → revisi

---

# 5. TEST FLOW LENGKAP

## Skenario 1: Submit → Verify
1. `user_ops` isi 5 soal E14, klik Save → status 'draft'
2. `user_ops` klik "Submit untuk Verifikasi" → status 'submitted'
3. Login `assessor1` → buka Verifikasi → badge 5 menunggu
4. Review satu per satu → klik Verify → status 'verified'
5. `user_ops` buka E14 lagi → form read-only, ada badge "Terverifikasi"

## Skenario 2: Submit → Return → Revisi
1. `user_im` isi 22 soal E10, submit
2. `assessor1` review 1 soal → klik Return dengan komentar "Evidence kurang detail"
3. `user_im` buka E10 → ada banner merah dengan komentar assessor
4. `user_im` perbaiki evidence → submit lagi → loop

## Skenario 3: Bulk Verify
1. 20 jawaban sudah di-submit di E03
2. Assessor buka Verifikasi → check-all di E03 → klik "Bulk Verify"
3. Semua 20 jawaban langsung verified sekaligus