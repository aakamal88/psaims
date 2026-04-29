<?php
/**
 * AJAX endpoint untuk notifications
 * Taruh di: pages/ajax_notifications.php
 *
 * Actions:
 *   - mark_read      : Mark 1 notif as read
 *   - mark_all_read  : Mark semua notif user as read
 *   - get_unread     : Get latest notifications (untuk polling ringan, optional)
 */

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../includes/notifications.php';

header('Content-Type: application/json');

if (!isLoggedIn()) {
    echo json_encode(['ok' => false, 'error' => 'Not logged in']);
    exit;
}

$user_id = $_SESSION['user_id'];
$action = $_POST['action'] ?? $_GET['action'] ?? '';

try {
    if ($action === 'mark_read') {
        $notif_id = (int)($_POST['id'] ?? 0);
        $result = markNotificationRead($notif_id, $user_id);
        $unread = countUnreadNotifications($user_id);
        echo json_encode([
            'ok'     => $result,
            'unread' => $unread,
        ]);
    }
    elseif ($action === 'mark_all_read') {
        $count = markAllNotificationsRead($user_id);
        echo json_encode([
            'ok'    => true,
            'count' => $count,
        ]);
    }
    elseif ($action === 'get_unread') {
        $unread = countUnreadNotifications($user_id);
        $notifs = getNotifications($user_id, 10);
        echo json_encode([
            'ok'          => true,
            'unread_count' => $unread,
            'notifications' => $notifs,
        ]);
    }
    else {
        echo json_encode(['ok' => false, 'error' => 'Unknown action']);
    }
} catch (Exception $e) {
    echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
}