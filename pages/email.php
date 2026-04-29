<?php
/**
 * =====================================================
 * MAILER HELPER — PSAIMS Email Notifier
 * =====================================================
 * Taruh file ini di: includes/mailer.php
 *
 * Cara pakai:
 *   require_once 'includes/mailer.php';
 *   sendFeedbackEmail($user_id, 'returned', $result_data);
 *
 * TIDAK butuh library eksternal (PHPMailer dll).
 * Pakai PHP built-in mail() function.
 * Kalau butuh SMTP authentication, uncomment bagian SMTP manual.
 * =====================================================
 */

require_once __DIR__ . '/../config/email.php';

/**
 * Fungsi low-level: kirim email ke satu penerima
 * Return: true kalau berhasil, false kalau gagal
 */
function sendEmail($to_email, $to_name, $subject, $html_body) {
    // Master switch
    if (!defined('EMAIL_ENABLED') || !EMAIL_ENABLED) {
        _emailLog("SKIPPED (EMAIL_ENABLED=false): {$subject} → {$to_email}");
        return false;
    }

    if (empty($to_email) || !filter_var($to_email, FILTER_VALIDATE_EMAIL)) {
        _emailLog("INVALID EMAIL: {$to_email}");
        return false;
    }

    $from_email = EMAIL_FROM_ADDRESS;
    $from_name  = EMAIL_FROM_NAME;

    // Headers untuk email HTML
    $headers = [];
    $headers[] = 'MIME-Version: 1.0';
    $headers[] = 'Content-Type: text/html; charset=UTF-8';
    $headers[] = "From: {$from_name} <{$from_email}>";
    $headers[] = 'Reply-To: ' . EMAIL_REPLY_TO;
    $headers[] = 'X-Mailer: PSAIMS-Notifier/1.0';
    $headers[] = 'X-Priority: 3';

    $header_string = implode("\r\n", $headers);
    $encoded_to = $to_name
        ? "{$to_name} <{$to_email}>"
        : $to_email;

    // Encode subject untuk support karakter UTF-8
    $encoded_subject = '=?UTF-8?B?' . base64_encode($subject) . '?=';

    try {
        $result = @mail($encoded_to, $encoded_subject, $html_body, $header_string);

        if ($result) {
            _emailLog("SENT: {$subject} → {$to_email}");
            return true;
        } else {
            $error = error_get_last();
            _emailLog("FAILED: {$subject} → {$to_email} | " . ($error['message'] ?? 'unknown'));
            return false;
        }
    } catch (Exception $e) {
        _emailLog("EXCEPTION: {$subject} → {$to_email} | " . $e->getMessage());
        return false;
    }
}

/**
 * Template: Notifikasi jawaban di-Return (minta revisi)
 */
function sendReturnedEmail($user_row, $result_data) {
    $url = EMAIL_APP_URL . 'pages/my_feedback.php';
    $app_name = defined('APP_NAME') ? APP_NAME : 'PSAIMS';

    $subject = "[PSAIMS] Jawaban Anda perlu direvisi — " . ($result_data['element_name'] ?? 'Assessment');

    $html = _emailTemplate([
        'title'      => '🔄 Jawaban Perlu Direvisi',
        'color'      => '#dc3545',
        'greeting'   => "Halo <strong>{$user_row['full_name']}</strong>,",
        'intro'      => "Jawaban Anda untuk <strong>" . htmlspecialchars($result_data['element_name']) . "</strong> (" .
                        htmlspecialchars($result_data['question_ref']) . ") dikembalikan oleh assessor untuk direvisi.",
        'details'    => [
            'Elemen'        => $result_data['element_name'],
            'Pertanyaan'    => $result_data['question_ref'],
            'Skor Anda'     => $result_data['score'] . '%',
            'Direview oleh' => $result_data['verified_by_name'],
            'Pada'          => date('d F Y, H:i', strtotime($result_data['verified_at'])),
        ],
        'comment_label' => 'Komentar Assessor',
        'comment'    => $result_data['assessor_comment'] ?? '(tidak ada komentar)',
        'cta_label'  => 'Lihat &amp; Revisi Jawaban',
        'cta_url'    => $url,
        'footer'     => 'Silakan login ke aplikasi untuk melihat detail dan melakukan revisi.',
    ]);

    return sendEmail($user_row['email'], $user_row['full_name'], $subject, $html);
}

/**
 * Template: Notifikasi jawaban di-Verify (approved)
 */
function sendVerifiedEmail($user_row, $result_data) {
    $url = EMAIL_APP_URL . 'pages/my_feedback.php';

    $subject = "[PSAIMS] Jawaban Anda disetujui — " . ($result_data['element_name'] ?? 'Assessment');

    $comment_section = '';
    if (!empty($result_data['assessor_comment'])) {
        $comment_section = $result_data['assessor_comment'];
    }

    $html = _emailTemplate([
        'title'      => '✓ Jawaban Disetujui',
        'color'      => '#28a745',
        'greeting'   => "Halo <strong>{$user_row['full_name']}</strong>,",
        'intro'      => "Jawaban Anda untuk <strong>" . htmlspecialchars($result_data['element_name']) . "</strong> (" .
                        htmlspecialchars($result_data['question_ref']) . ") telah disetujui oleh assessor.",
        'details'    => [
            'Elemen'        => $result_data['element_name'],
            'Pertanyaan'    => $result_data['question_ref'],
            'Skor Final'    => $result_data['score'] . '%',
            'Diverifikasi oleh' => $result_data['verified_by_name'],
            'Pada'          => date('d F Y, H:i', strtotime($result_data['verified_at'])),
        ],
        'comment_label' => $comment_section ? 'Komentar Assessor' : null,
        'comment'    => $comment_section,
        'cta_label'  => 'Lihat Detail',
        'cta_url'    => $url,
        'footer'     => 'Jawaban ini sekarang terkunci dan menjadi data final. Terima kasih atas kontribusi Anda.',
    ]);

    return sendEmail($user_row['email'], $user_row['full_name'], $subject, $html);
}

/**
 * Template HTML email dengan styling inline (max compatibility)
 */
function _emailTemplate($data) {
    $color = $data['color'] ?? '#007bff';
    $details_html = '';
    foreach ($data['details'] as $label => $value) {
        $details_html .= sprintf(
            '<tr><td style="padding:6px 12px 6px 0; color:#666; font-size:13px; vertical-align:top; white-space:nowrap;">%s</td>' .
            '<td style="padding:6px 0; color:#333; font-size:13px;"><strong>%s</strong></td></tr>',
            htmlspecialchars($label),
            htmlspecialchars($value)
        );
    }

    $comment_html = '';
    if (!empty($data['comment']) && !empty($data['comment_label'])) {
        $comment_html = sprintf(
            '<div style="margin:20px 0; padding:16px; background:#fffbea; border-left:4px solid #ffc107; border-radius:4px;">' .
            '<strong style="display:block; margin-bottom:8px; color:#856404;">💬 %s:</strong>' .
            '<div style="color:#333; font-size:14px; line-height:1.6; white-space:pre-wrap;">%s</div>' .
            '</div>',
            htmlspecialchars($data['comment_label']),
            htmlspecialchars($data['comment'])
        );
    }

    return <<<HTML
<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>{$data['title']}</title>
</head>
<body style="margin:0; padding:0; background:#f5f5f5; font-family: Arial, Helvetica, sans-serif;">
<table width="100%" cellpadding="0" cellspacing="0" style="background:#f5f5f5; padding:20px 0;">
    <tr>
        <td align="center">
            <table width="600" cellpadding="0" cellspacing="0" style="background:#ffffff; border-radius:8px; overflow:hidden; box-shadow:0 2px 4px rgba(0,0,0,0.05);">
                <!-- Header -->
                <tr>
                    <td style="background:{$color}; padding:20px 24px; color:#fff;">
                        <h1 style="margin:0; font-size:20px; font-weight:500;">{$data['title']}</h1>
                        <small style="opacity:0.85;">PSAIMS Self-Assessment Tool</small>
                    </td>
                </tr>

                <!-- Body -->
                <tr>
                    <td style="padding:24px;">
                        <p style="margin:0 0 16px; font-size:14px; color:#333;">{$data['greeting']}</p>
                        <p style="margin:0 0 20px; font-size:14px; color:#333; line-height:1.6;">{$data['intro']}</p>

                        <table width="100%" cellpadding="0" cellspacing="0" style="margin:16px 0; border-top:1px solid #eee; border-bottom:1px solid #eee; padding:8px 0;">
                            {$details_html}
                        </table>

                        {$comment_html}

                        <div style="text-align:center; margin:24px 0;">
                            <a href="{$data['cta_url']}"
                               style="display:inline-block; padding:12px 32px; background:{$color}; color:#fff; text-decoration:none; border-radius:4px; font-weight:500; font-size:14px;">
                                {$data['cta_label']}
                            </a>
                        </div>

                        <p style="margin:16px 0 0; font-size:12px; color:#888; line-height:1.5;">{$data['footer']}</p>
                    </td>
                </tr>

                <!-- Footer -->
                <tr>
                    <td style="background:#f9f9f9; padding:16px 24px; border-top:1px solid #eee; text-align:center;">
                        <small style="color:#999; font-size:11px;">
                            Email otomatis dari sistem PSAIMS PT Pertamina Gas<br>
                            Mohon tidak reply email ini langsung.
                        </small>
                    </td>
                </tr>
            </table>
        </td>
    </tr>
</table>
</body>
</html>
HTML;
}

/**
 * Simpan log email ke file (untuk debug)
 */
function _emailLog($message) {
    if (!defined('EMAIL_DEBUG') || !EMAIL_DEBUG) return;
    if (!defined('EMAIL_LOG_PATH')) return;

    $log_dir = dirname(EMAIL_LOG_PATH);
    if (!is_dir($log_dir)) {
        @mkdir($log_dir, 0755, true);
    }

    $line = '[' . date('Y-m-d H:i:s') . '] ' . $message . PHP_EOL;
    @file_put_contents(EMAIL_LOG_PATH, $line, FILE_APPEND | LOCK_EX);
}