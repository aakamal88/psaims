# INBOX INTERNAL NOTIFICATIONS — Panduan Install

## 📦 File yang Ada

| File | Lokasi Akhir | Fungsi |
|---|---|---|
| `notifications_migration.sql` | Jalankan di pgAdmin | Buat tabel `notifications` + view |
| `notifications.php` (helper) | `includes/notifications.php` | Helper functions: notify(), countUnread(), dll |
| `sidebar.php` | `includes/sidebar.php` | **TIMPA** — dengan dropdown notifikasi |
| `ajax_notifications.php` | `pages/ajax_notifications.php` | Endpoint AJAX mark as read |
| `notifications_page.php` | `pages/notifications.php` | **Rename saat copy** — full inbox page |

## 🚀 Langkah Install

### 1. Database
Di pgAdmin sebagai `postgres`, jalankan:
```
notifications_migration.sql
```

### 2. Copy Helper & Sidebar
- `notifications.php` → `C:\inetpub\wwwroot\PTG_PSAIMS\includes\notifications.php`
- `sidebar.php` → **TIMPA** file yang ada di `includes\sidebar.php`

### 3. Copy Halaman
- `ajax_notifications.php` → `C:\inetpub\wwwroot\PTG_PSAIMS\pages\ajax_notifications.php`
- `notifications_page.php` → **rename jadi** `notifications.php` → taruh di `pages\notifications.php`

## 🔌 Integrasi dengan verification.php

Buka `pages/verification.php`, di atas file tambahkan:

```php
require_once __DIR__ . '/../includes/notifications.php';
```

Lalu cari handler `if ($action === 'verify' || $action === 'return')`.
Setelah bagian insert history, sebelum `$msg = ...`, tambahkan:

```php
// Kirim notifikasi internal ke user yang mengisi jawaban
try {
    $stmt_notif = $pdo->prepare(
        "SELECT ar.user_id, ar.score, ar.assessor_comment,
                q.criteria, e.id AS element_id, e.element_name,
                verifier.full_name AS verifier_name
         FROM assessment_results ar
         JOIN assessment_questions q ON q.id = ar.question_id
         JOIN psaims_elements e ON e.id = q.element_id
         LEFT JOIN users verifier ON verifier.id = ar.verified_by
         WHERE ar.id = ?"
    );
    $stmt_notif->execute([$result_id]);
    $nd = $stmt_notif->fetch();

    if ($nd && $nd['user_id']) {
        $ref = '';
        if (preg_match('/\[Ref\s+([0-9.a-z]+)\]/i', $nd['criteria'], $m)) {
            $ref = "Ref {$m[1]}";
        } else {
            $ref = 'Pertanyaan';
        }

        $context = [
            'related_result_id'  => $result_id,
            'related_element_id' => $nd['element_id'],
        ];

        if ($action === 'verify') {
            notifyVerified($nd['user_id'], $nd['element_name'], $ref,
                          $nd['score'], $nd['verifier_name'] ?? 'Assessor',
                          $nd['assessor_comment'], $context);
        } else {
            notifyReturned($nd['user_id'], $nd['element_name'], $ref,
                          $nd['score'], $nd['verifier_name'] ?? 'Assessor',
                          $nd['assessor_comment'], $context);
        }
    }
} catch (Exception $notif_ex) {
    error_log("Notify failed: " . $notif_ex->getMessage());
}
```

Untuk handler `bulk_verify`, tambahkan di dalam loop setelah insert history (sama pattern).

## 🔌 Integrasi dengan assessment.php (optional)

Kalau mau notif user saat submit berhasil, di handler POST `submit_for_review`:

```php
require_once __DIR__ . '/../includes/notifications.php';

// Setelah UPDATE sukses
notifySubmitSuccess($user['id'], $element['element_name'], $count);
```

## 🧪 Test Flow

**1. Buat notif test manual:**
Di pgAdmin:
```sql
INSERT INTO notifications (user_id, type, icon, color, title, message, link, created_by)
VALUES
  (2, 'returned', 'fa-undo', 'danger',
   'Test: Jawaban perlu direvisi',
   'Ini notifikasi test untuk cek tampilan dropdown sidebar.',
   '/PTG_PSAIMS/pages/my_feedback.php', 1);
```

Ganti `user_id=2` dengan ID user yang login.

**2. Login sebagai user tersebut:**
- Dropdown "Notifikasi" di bawah user panel sidebar akan muncul dengan badge merah "1"
- Klik toggle → dropdown slide down
- Klik item → auto mark as read + redirect ke link

**3. Test admin view all:**
- Login sebagai admin
- Buka "Notifikasi" di halaman penuh (dari footer dropdown: "Lihat semua notifikasi")
- Klik tombol "Lihat Semua User" → admin bisa lihat notif untuk semua user
- Filter by user, type, status
- Cleanup old notifications (tombol warning di bawah)

## 🎯 Fitur Utama

✅ **Dropdown sidebar** — 8 notifikasi terbaru, toggle klik
✅ **Badge counter** — angka merah di tombol kalau ada unread
✅ **Auto mark as read** — saat klik notifikasi
✅ **Mark all as read** — button di header dropdown
✅ **Full inbox page** — `pages/notifications.php`
✅ **Admin global view** — admin lihat semua notif untuk semua user
✅ **Filter lengkap** — by user, type, status
✅ **Cleanup tool** — admin bisa hapus notif lama
✅ **Auto time display** — "10 menit yang lalu"
✅ **Icon + color per type** — visual distinction

## ⚠️ Catatan

1. **Tidak ada polling real-time** — user perlu refresh untuk dapat update terbaru (sesuai design kita)
2. **Retention policy** — admin bisa cleanup manual notif > 30 hari via halaman notifications
3. **Link** — kalau notifikasi ada link, klik item akan auto-redirect setelah mark as read

## 🔄 Sinkronisasi dengan Email

Kalau email juga aktif (EMAIL_ENABLED=true):
- Email dan notif internal akan **sama-sama dikirim**
- Kalau email gagal, notif internal tetap sampai (ini benefit utama!)
- Admin bisa lihat di Email Log siapa yang emailnya gagal, lalu cross-check di Notifications mereka tetap terima notif internal

Sekarang sistem feedback sudah sangat robust dengan dual-channel delivery. 🎯