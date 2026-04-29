# PSAIMS SELF ASSESSMENT TOOL

Aplikasi web untuk penilaian mandiri (*self assessment*) terhadap **18 Elemen PSAIMS**  
(*Process Safety & Asset Integrity Management System*).

**Stack:** PHP · PostgreSQL · AdminLTE 3.2.0 · IIS (Windows)

---

## Struktur Folder

```
C:\inetpub\wwwroot\PTG_PSAIMS\
├── Adminlte\                      ← Template AdminLTE 3.2.0 (sudah ada)
├── assets\
│   ├── css\custom.css
│   ├── js\custom.js
│   └── img\favicon.png            ← (tambahkan sendiri)
├── config\
│   ├── config.php                 ← konfigurasi aplikasi + session
│   └── database.php               ← koneksi PostgreSQL
├── database\
│   └── psaims_schema.sql          ← schema + data awal
├── includes\
│   ├── header.php                 ← top navbar + head
│   ├── sidebar.php                ← menu 18 elemen
│   └── footer.php                 ← JS AdminLTE
├── pages\
│   ├── assessment.php             ← halaman dinamis 18 elemen
│   └── 404.php
├── uploads\                       ← folder upload (beri write permission)
├── index.php                      ← dashboard utama
├── login.php
├── logout.php
├── web.config                     ← konfigurasi IIS
└── README.md
```

---

## Langkah Instalasi

### 1. Siapkan PostgreSQL

Buka **pgAdmin** atau *command prompt*, lalu:

```sql
CREATE DATABASE psaims_db WITH ENCODING 'UTF8';
```

Masuk ke database baru itu dan jalankan schema:

```bash
psql -U postgres -d psaims_db -f C:\inetpub\wwwroot\PTG_PSAIMS\database\psaims_schema.sql
```

Atau copy-paste isi `psaims_schema.sql` ke Query Tool pgAdmin.

### 2. Aktifkan Extension PHP untuk PostgreSQL

Edit `C:\PHP\php.ini`, pastikan baris berikut **tidak** di-comment:

```ini
extension=pdo_pgsql
extension=pgsql
```

Restart IIS: `iisreset` di Command Prompt (Administrator).

### 3. Konfigurasi Database

Edit `C:\inetpub\wwwroot\PTG_PSAIMS\config\database.php`:

```php
define('DB_HOST', 'localhost');
define('DB_PORT', '5432');
define('DB_NAME', 'psaims_db');
define('DB_USER', 'postgres');
define('DB_PASS', 'PASSWORD_POSTGRES_ANDA');   // ← ganti
```

### 4. Download AdminLTE 3.2.0

Pastikan folder `C:\inetpub\wwwroot\PTG_PSAIMS\Adminlte\` berisi struktur resmi AdminLTE:

```
Adminlte\
├── dist\css\adminlte.min.css
├── dist\js\adminlte.js
└── plugins\
    ├── fontawesome-free\
    ├── jquery\
    ├── bootstrap\
    ├── chart.js\
    ├── datatables\
    ├── datatables-bs4\
    ├── datatables-responsive\
    ├── select2\
    ├── sweetalert2\
    ├── summernote\
    ├── daterangepicker\
    ├── tempusdominus-bootstrap-4\
    ├── moment\
    ├── overlayScrollbars\
    ├── jquery-ui\
    ├── jquery-knob\
    ├── sparklines\
    ├── jqvmap\
    └── icheck-bootstrap\
```

Download resmi: <https://github.com/ColorlibHQ/AdminLTE/releases/tag/v3.2.0>

### 5. Setting IIS

1. Buka **IIS Manager**
2. Pastikan **PHP Manager** sudah terpasang dan handler `*.php` mengarah ke `C:\PHP\php-cgi.exe`
3. **Application Pool** dari `PTG_PSAIMS` set ke: **No Managed Code**, Identity: `ApplicationPoolIdentity`
4. Beri permission **Modify** ke folder `uploads` untuk user `IIS_IUSRS`:

   ```cmd
   icacls "C:\inetpub\wwwroot\PTG_PSAIMS\uploads" /grant "IIS_IUSRS:(OI)(CI)M" /T
   ```

### 6. Akses Aplikasi

Buka browser: <http://localhost/PTG_PSAIMS/>

**Login default:**

| Role     | Username   | Password   |
|----------|------------|------------|
| Admin    | `admin`    | `admin123` |
| Assessor | `assessor` | `admin123` |

> ⚠️ **Ganti password default** segera setelah login pertama!

---

## Fitur Utama

- ✅ **Dashboard interaktif** dengan ringkasan 18 elemen dalam bentuk *small box* + chart bar rata-rata skor
- ✅ **Sidebar** dengan semua 18 elemen PSAIMS beserta FontAwesome icon unik per elemen
- ✅ **Form assessment dinamis** — satu halaman `assessment.php` melayani ke-18 elemen, tinggal ganti parameter `?element=N`
- ✅ **Skoring Maturity Level 1–5** (Initial → Optimized) dengan tombol visual
- ✅ **Evidence, Gap Analysis, Action Plan, Target Date, PIC** per pertanyaan
- ✅ **Multi-user** dengan role `admin` / `assessor` / `user`
- ✅ **Activity log** untuk audit trail
- ✅ **Periode Assessment** (*session*) — per triwulan atau tahunan

---

## Membuat Periode Assessment Baru

Sementara UI admin panel belum lengkap, jalankan di pgAdmin:

```sql
INSERT INTO assessment_sessions (session_name, session_year, session_period, 
                                 start_date, end_date, status, created_by)
VALUES ('Assessment Tahun 2026', 2026, 'Annual', 
        '2026-01-01', '2026-12-31', 'ongoing', 1);
```

Setelah itu, user bisa mulai mengisi assessment di halaman masing-masing elemen.

---

## Menambahkan Pertanyaan untuk Elemen Lain

Sample pertanyaan di schema hanya untuk **Elemen 1**. Untuk menambah pertanyaan elemen lainnya:

```sql
INSERT INTO assessment_questions (element_id, question_number, question_text, criteria, weight) 
VALUES 
  (2, 1, 'Apakah kebijakan PSAIMS ditetapkan dan dikomunikasikan?', 
     'Ada dokumen kebijakan ditandatangani CEO', 1.0),
  (2, 2, 'Apakah kebijakan ditinjau secara berkala?', 
     'Ada review minimal setahun sekali', 1.0);
-- ulang untuk elemen 3 s.d. 18
```

---

## Pengembangan Lanjutan

File-file ini bisa ditambahkan sesuai kebutuhan:

- `pages/users.php` — CRUD user (admin)
- `pages/questions.php` — CRUD pertanyaan assessment
- `pages/sessions.php` — Kelola periode assessment
- `pages/report_summary.php` — Laporan ringkasan + export Excel/PDF
- `pages/report_gap.php` — Laporan gap analysis
- `pages/report_action.php` — Monitoring action plan
- `pages/profile.php` — Edit profil user
- `pages/activity_log.php` — Lihat activity log

---

## Troubleshooting

**"Koneksi database gagal"**  
→ Periksa `config/database.php`, pastikan service PostgreSQL berjalan (`services.msc`), dan extension `pdo_pgsql` aktif di `php.ini`.

**Sidebar kosong / CSS berantakan**  
→ Pastikan folder `Adminlte/` ada dan `BASE_URL` di `config.php` = `/PTG_PSAIMS/`.

**500 Internal Server Error di IIS**  
→ Cek Event Viewer, cek `php_errors.log`, pastikan `IIS_IUSRS` punya hak read untuk folder aplikasi.

**Session hilang setelah login**  
→ Pastikan folder temp PHP (biasanya `C:\Windows\Temp` atau `C:\PHP\sessions`) bisa ditulis oleh `IIS_IUSRS`.

---

© PT Pertamina Gas · PSAIMS SELF ASSESSMENT TOOL · v1.0.0
