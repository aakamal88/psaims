# EVIDENCE FILE UPLOAD SYSTEM — Install Guide

## 🎯 Yang Akan Terbentuk

✅ Upload multi-file evidence di setiap pertanyaan assessment
✅ Auto-create folder per elemen: `01_Kepemimpinan/`, `07_HAZOP/`, dll
✅ Preview/download file via secure PHP endpoint
✅ Halaman Evidence Files di bawah menu Verifikasi (untuk admin/assessor)
✅ Halaman Settings untuk admin atur base path, max size, extensions
✅ Soft delete + audit trail

## 📦 File yang Disiapkan (9 file)

| File | Lokasi Install | Fungsi |
|---|---|---|
| `evidence_migration.sql` | Run di pgAdmin | Buat tabel `evidence_files` + `app_settings` |
| `evidence.php` | `includes/evidence.php` | Helper functions (upload, delete, validate) |
| `ajax_evidence.php` | `pages/ajax_evidence.php` | AJAX endpoint upload/delete |
| `download_evidence.php` | `pages/download_evidence.php` | Secure download endpoint |
| `evidence_list.php` | `pages/evidence_list.php` | Halaman list semua file (admin/assessor) |
| `evidence_settings.php` | `pages/evidence_settings.php` | Setting path, size, ext (admin) |
| `SNIPPET_assessment.md` | Panduan | Snippet kode untuk ditambah ke assessment.php |
| `PATCH_sidebar.md` | Panduan | Patch kode untuk sidebar.php |
| `README.md` | Baca file ini | Panduan keseluruhan |

## 🚀 Urutan Install

### 1. Database
Login ke pgAdmin sebagai `postgres`, jalankan `evidence_migration.sql`.

Verifikasi:
```sql
SELECT * FROM app_settings;
-- Harus ada 5 row default settings
```

### 2. Copy File PHP

```
C:\inetpub\wwwroot\PTG_PSAIMS\
├── includes\
│   └── evidence.php              ← COPY
└── pages\
    ├── ajax_evidence.php         ← COPY
    ├── download_evidence.php     ← COPY
    ├── evidence_list.php         ← COPY
    └── evidence_settings.php     ← COPY
```

### 3. Buat Base Directory

Default base path: `D:\PSAIMS_Evidence`

Kalau drive D tidak ada, ganti di halaman Settings nanti atau di DB:
```sql
UPDATE app_settings
SET setting_value = 'C:\inetpub\wwwroot\PTG_PSAIMS\uploads\evidence'
WHERE setting_key = 'evidence_base_path';
```

Buat folder manual via File Explorer atau di Command Prompt:
```cmd
mkdir D:\PSAIMS_Evidence
```

**PENTING:** Pastikan IIS user (biasanya `IUSR` atau `IIS APPPOOL\DefaultAppPool`) punya write access ke folder ini.

Cara quick set permission (via PowerShell admin):
```powershell
icacls "D:\PSAIMS_Evidence" /grant "IIS_IUSRS:(OI)(CI)M" /T
```

### 4. Update php.ini (kalau perlu file > 2MB default)

Di `C:\Program Files\PHP\php.ini`:
```ini
upload_max_filesize = 20M
post_max_size = 25M
max_file_uploads = 20
```

Restart IIS setelah edit.

### 5. Update sidebar.php

Ikuti panduan di `PATCH_sidebar.md` — tambah 1-2 menu item di sidebar.

### 6. Integrasi ke assessment.php

Ikuti `SNIPPET_assessment.md` — tambah 3 snippet ke halaman assessment.

**ATAU** upload `pages/assessment.php` versi Anda ke saya, saya generate versi pre-merged.

## 🧪 Test Flow

### Test 1: Admin setting
1. Login sebagai admin
2. Buka `pages/evidence_settings.php`
3. Cek sidebar panel kanan — status folder harus "Ada" dan "Writable"
4. Kalau belum writable, fix permission dulu
5. Save settings (boleh tanpa ubah apa-apa)

### Test 2: User upload
1. Login sebagai user biasa
2. Buka halaman Assessment, pilih elemen
3. Di pertanyaan pertama, klik **Upload File**
4. Pilih PDF atau gambar
5. Progress bar harus muncul, lalu file muncul di list
6. Cek di disk: `D:\PSAIMS_Evidence\01_Kepemimpinan\` — file harus ada di situ

### Test 3: Download & delete
1. Klik nama file di list → preview/download
2. Klik icon X merah → konfirmasi delete → file hilang
3. Cek di disk: file sudah tidak ada
4. Cek di DB: `SELECT * FROM evidence_files WHERE is_deleted = TRUE;` — harus ada row dengan `deleted_at` terisi

### Test 4: Admin view
1. Login sebagai admin atau assessor
2. Sidebar → **Verifikasi → Evidence Files**
3. Tabel menampilkan semua file dari semua user
4. Filter by periode, elemen, user, extension
5. Download file (bisa buka file user lain)

## 🔐 Security Features

✅ **Extension whitelist** — default 16 extensions aman
✅ **Executable blocklist** — exe, bat, php, sh, dll auto-block meski masuk whitelist
✅ **Path traversal prevention** — di download endpoint, validasi path harus dalam base dir
✅ **Authorization** — user hanya bisa download file sendiri, admin/assessor bisa semua
✅ **Soft delete** — file masih di DB untuk audit, bisa di-restore kalau perlu
✅ **Max size limit** — default 10MB, configurable
✅ **Max count per question** — default 10 file, configurable

## 📁 Struktur Folder Evidence

Contoh hasil di disk setelah beberapa user upload:

```
D:\PSAIMS_Evidence\
├── 01_Kepemimpinan\
│   ├── s1_q3_20260420143022_a8f3c2_laporan_komitmen.pdf
│   └── s1_q5_20260420144511_b9e4d1_screenshot_rapat.png
├── 07_HAZOP\
│   ├── s1_q48_20260420150822_c7a2e9_hazop_study_rev3.xlsx
│   └── s1_q52_20260420151533_d8b3f0_minutes_meeting.docx
└── 10_Integritas_Aset\
    ├── s1_q85_20260420160122_e9c4g1_inspection_report.pdf
    └── s1_q88_20260420161244_f0d5h2_asset_register.xlsx
```

Format stored filename: `s{session_id}_q{question_id}_{timestamp}_{random}_{clean_original_name}.{ext}`

Ini memastikan:
- Tidak ada collision kalau 2 user upload file dengan nama sama
- Bisa trace session & question dari nama file
- Nama asli user tetap tersimpan di DB

## 💡 Tips Operasional

**Backup strategy:**
Setting scheduled task untuk copy folder evidence ke backup server:
```cmd
robocopy D:\PSAIMS_Evidence \\backupserver\evidence_backup /MIR /R:2 /W:10
```

**Cleanup soft-deleted files:**
Kalau mau bersihkan file yang sudah di-soft-delete (> 90 hari):
```sql
-- Lihat dulu
SELECT COUNT(*), SUM(file_size)
FROM evidence_files
WHERE is_deleted = TRUE AND deleted_at < NOW() - INTERVAL '90 days';

-- Hapus permanen (hati-hati!)
-- Script PHP untuk hapus fisik + DELETE dari DB bisa dibuat nanti kalau perlu
```

**Monitoring disk usage:**
Di halaman Settings, sudah ada info:
- Total files
- Total size

Admin bisa cek berkala supaya tidak kehabisan disk.

## ⏭️ Fitur yang Bisa Ditambahkan Nanti (opsional)

- **Zip download per elemen** — download semua evidence E10 sekaligus sebagai ZIP
- **Image thumbnail preview** — untuk JPG/PNG generate thumbnail
- **PDF preview inline** — embed PDF viewer di halaman assessment
- **Bulk upload via drag-and-drop zone**
- **Version history** — track perubahan file (replace vs add new)
- **Restore soft-deleted files** — undelete button untuk admin

Silakan test. Kalau ada error atau butuh integrasi assessment.php, bilang saja. 🎯