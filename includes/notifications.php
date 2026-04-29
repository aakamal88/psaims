<?php
/**
 * =====================================================
 * NOTIFICATIONS HELPER — Inbox Internal
 * =====================================================
 * Taruh di: includes/notifications.php
 *
 * Cara pakai:
 *   require_once 'includes/notifications.php';
 *   notify($user_id, 'returned', 'Jawaban perlu direvisi', '...', $link);
 * =====================================================
 */

/**
 * Kirim notifikasi internal ke user.
 *
 * @param int    $user_id        Penerima
 * @param string $type           verified|returned|submit_success|info|warning|system|reminder
 * @param string $title          Judul singkat
 * @param string $message        Body pesan
 * @param string $link           URL untuk action (optional)
 * @param array  $context        related_result_id, related_element_id (optional)
 * @return int|false             ID notifikasi baru atau false kalau gagal
 */
function notify($user_id, $type, $title, $message = '', $link = null, $context = []) {
    global $pdo;

    if (!$user_id || empty($type) || empty($title)) return false;

    // Mapping default icon & color per type
    $type_defaults = [
        'verified'        => ['fa-check-circle', 'success'],
        'returned'        => ['fa-undo', 'danger'],
        'submit_success'  => ['fa-paper-plane', 'info'],
        'info'            => ['fa-info-circle', 'info'],
        'warning'         => ['fa-exclamation-triangle', 'warning'],
        'system'          => ['fa-cog', 'secondary'],
        'reminder'        => ['fa-bell', 'warning'],
    ];
    [$default_icon, $default_color] = $type_defaults[$type] ?? ['fa-bell', 'primary'];

    $icon  = $context['icon']  ?? $default_icon;
    $color = $context['color'] ?? $default_color;

    try {
        $stmt = $pdo->prepare(
            "INSERT INTO notifications
             (user_id, type, icon, color, title, message, link,
              related_result_id, related_element_id, created_by)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
             RETURNING id"
        );
        $stmt->execute([
            $user_id,
            $type,
            $icon,
            $color,
            $title,
            $message ?: null,
            $link ?: null,
            $context['related_result_id']  ?? null,
            $context['related_element_id'] ?? null,
            $_SESSION['user_id'] ?? null,
        ]);
        return $stmt->fetchColumn();
    } catch (Exception $e) {
        error_log("Notify failed: " . $e->getMessage());
        return false;
    }
}

/**
 * Ambil notifikasi untuk user tertentu
 */
function getNotifications($user_id, $limit = 10, $unread_only = false) {
    global $pdo;

    if (!$user_id) return [];

    $sql = "SELECT * FROM notifications WHERE user_id = ?";
    $params = [$user_id];

    if ($unread_only) {
        $sql .= " AND is_read = FALSE";
    }

    $sql .= " ORDER BY created_at DESC LIMIT ?";
    $params[] = $limit;

    try {
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll();
    } catch (Exception $e) {
        return [];
    }
}

/**
 * Hitung jumlah notifikasi belum dibaca
 */
function countUnreadNotifications($user_id) {
    global $pdo;

    if (!$user_id) return 0;

    try {
        $stmt = $pdo->prepare(
            "SELECT COUNT(*) FROM notifications WHERE user_id = ? AND is_read = FALSE"
        );
        $stmt->execute([$user_id]);
        return (int)$stmt->fetchColumn();
    } catch (Exception $e) {
        return 0;
    }
}

/**
 * Mark notification as read
 */
function markNotificationRead($notif_id, $user_id) {
    global $pdo;

    try {
        $stmt = $pdo->prepare(
            "UPDATE notifications
             SET is_read = TRUE, read_at = CURRENT_TIMESTAMP
             WHERE id = ? AND user_id = ? AND is_read = FALSE"
        );
        $stmt->execute([$notif_id, $user_id]);
        return $stmt->rowCount() > 0;
    } catch (Exception $e) {
        return false;
    }
}

/**
 * Mark all as read untuk user
 */
function markAllNotificationsRead($user_id) {
    global $pdo;

    try {
        $stmt = $pdo->prepare(
            "UPDATE notifications
             SET is_read = TRUE, read_at = CURRENT_TIMESTAMP
             WHERE user_id = ? AND is_read = FALSE"
        );
        $stmt->execute([$user_id]);
        return $stmt->rowCount();
    } catch (Exception $e) {
        return 0;
    }
}

/**
 * Shortcut: Notify saat jawaban di-verify
 */
function notifyVerified($user_id, $element_name, $question_ref, $score, $verifier_name, $comment, $context = []) {
    $link = (defined('BASE_URL') ? BASE_URL : '/') . 'pages/my_feedback.php?status=verified';
    $title = "Jawaban disetujui: {$element_name}";
    $msg = "Jawaban Anda untuk {$question_ref} (skor {$score}%) telah disetujui oleh {$verifier_name}.";
    if (!empty($comment)) {
        $msg .= " Komentar: \"{$comment}\"";
    }
    return notify($user_id, 'verified', $title, $msg, $link, $context);
}

/**
 * Shortcut: Notify saat jawaban di-return
 */
function notifyReturned($user_id, $element_name, $question_ref, $score, $verifier_name, $comment, $context = []) {
    $link = (defined('BASE_URL') ? BASE_URL : '/') . 'pages/my_feedback.php?status=returned';
    $title = "Jawaban perlu direvisi: {$element_name}";
    $msg = "Jawaban Anda untuk {$question_ref} (skor {$score}%) dikembalikan oleh {$verifier_name}.";
    if (!empty($comment)) {
        $msg .= " Komentar: \"{$comment}\"";
    }
    return notify($user_id, 'returned', $title, $msg, $link, $context);
}

/**
 * Shortcut: Notify user bahwa submit berhasil
 */
function notifySubmitSuccess($user_id, $element_name, $count) {
    $link = (defined('BASE_URL') ? BASE_URL : '/') . 'pages/my_feedback.php?status=submitted';
    $title = "Submit berhasil: {$element_name}";
    $msg = "{$count} jawaban telah disubmit untuk diverifikasi oleh assessor.";
    return notify($user_id, 'submit_success', $title, $msg, $link);
}

/**
 * Format timestamp relative (misal: "10 menit yang lalu")
 */
function timeAgo($datetime) {
    $time = is_numeric($datetime) ? $datetime : strtotime($datetime);
    $diff = time() - $time;

    if ($diff < 60) return 'Baru saja';
    if ($diff < 3600) return floor($diff / 60) . ' menit yang lalu';
    if ($diff < 86400) return floor($diff / 3600) . ' jam yang lalu';
    if ($diff < 604800) return floor($diff / 86400) . ' hari yang lalu';
    if ($diff < 2592000) return floor($diff / 604800) . ' minggu yang lalu';

    return date('d M Y', $time);
}