<?php
/**
 * =====================================================
 * MAILER HELPER v2 — PSAIMS Email Notifier + Audit Log
 * =====================================================
 * Taruh file ini di: includes/mailer.php
 *
 * Semua email yang dikirim (atau gagal/skipped) akan
 * otomatis tercatat di tabel email_log untuk audit trail.
 * =====================================================
 */

require_once __DIR__ . '/../config/email.php';

/**
 * Fungsi low-level: kirim email + log ke database
 *
 * @param string $to_email
 * @param string $to_name
 * @param string $subject
 * @param string $html_body
 * @param array  $context {
 *     @type int    'recipient_user_id' FK ke users
 *     @type string 'email_type' verified|returned|reminder|test|other
 *     @type int    'related_result_id' FK ke assessment_results
 *     @type int    'related_element_id' FK ke psaims_elements
 *     @type string 'trigger_action' verify|return|bulk_verify|manual_resend
 * }
 * @return bool
 */
function sendEmail($to_email, $to_name, $subject, $html_body, $context = []) {
    global $pdo;

    $default_context = [
        'recipient_user_id'  => null,
        'email_type'         => 'other',
        'related_result_id'  => null,
        'related_element_id' => null,
        'trigger_action'     => null,
    ];
    $context = array_merge($default_context, $context);

    // Snippet body untuk preview (strip HTML)
    $body_preview = mb_substr(strip_tags($html_body), 0, 500);

    // Cek apakah email valid
    if (empty($to_email) || !filter_var($to_email, FILTER_VALIDATE_EMAIL)) {
        _emailLog("INVALID EMAIL: {$to_email}");
        _insertEmailLog($pdo, [
            'recipient_email'   => $to_email,
            'recipient_name'    => $to_name,
            'subject'           => $subject,
            'body_preview'      => $body_preview,
            'status'            => 'failed',
            'error_message'     => 'Email address tidak valid',
        ] + $context);
        return false;
    }

    // Master switch: EMAIL_ENABLED
    if (!defined('EMAIL_ENABLED') || !EMAIL_ENABLED) {
        _emailLog("SKIPPED (EMAIL_ENABLED=false): {$subject} → {$to_email}");
        _insertEmailLog($pdo, [
            'recipient_email'   => $to_email,
            'recipient_name'    => $to_name,
            'subject'           => $subject,
            'body_preview'      => $body_preview,
            'status'            => 'skipped',
            'error_message'     => 'Email dinonaktifkan di config (EMAIL_ENABLED=false)',
        ] + $context);
        return false;
    }

    // Prepare email
    $from_email = EMAIL_FROM_ADDRESS;
    $from_name  = EMAIL_FROM_NAME;

    $headers = [];
    $headers[] = 'MIME-Version: 1.0';
    $headers[] = 'Content-Type: text/html; charset=UTF-8';
    $headers[] = "From: {$from_name} <{$from_email}>";
    $headers[] = 'Reply-To: ' . EMAIL_REPLY_TO;
    $headers[] = 'X-Mailer: PSAIMS-Notifier/1.0';
    $headers[] = 'X-Priority: 3';

    $header_string = implode("\r\n", $headers);
    $encoded_to = $to_name ? "{$to_name} <{$to_email}>" : $to_email;
    $encoded_subject = '=?UTF-8?B?' . base64_encode($subject) . '?=';

    // Send email
    $success = false;
    $error_msg = null;

    try {
        $success = @mail($encoded_to, $encoded_subject, $html_body, $header_string);
        if (!$success) {
            $error = error_get_last();
            $error_msg = $error['message'] ?? 'PHP mail() gagal tanpa pesan error';
        }
    } catch (Exception $e) {
        $error_msg = $e->getMessage();
    }

    if ($success) {
        _emailLog("SENT: {$subject} → {$to_email}");
    } else {
        _emailLog("FAILED: {$subject} → {$to_email} | {$error_msg}");
    }

    // Always log to database
    _insertEmailLog($pdo, [
        'recipient_email'   => $to_email,
        'recipient_name'    => $to_name,
        'subject'           => $subject,
        'body_preview'      => $body_preview,
        'status'            => $success ? 'sent' : 'failed',
        'error_message'     => $error_msg,
    ] + $context);

    return $success;
}

/**
 * Insert record ke tabel email_log
 */
function _insertEmailLog($pdo, $data) {
    try {
        $stmt = $pdo->prepare(
            "INSERT INTO email_log
             (recipient_email, recipient_name, recipient_user_id,
              email_type, subject, body_preview,
              related_result_id, related_element_id,
              status, error_message,
              triggered_by, trigger_action, ip_address)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)"
        );
        $stmt->execute([
            $data['recipient_email']    ?? null,
            $data['recipient_name']     ?? null,
            $data['recipient_user_id']  ?? null,
            $data['email_type']         ?? 'other',
            $data['subject']            ?? null,
            $data['body_preview']       ?? null,
            $data['related_result_id']  ?? null,
            $data['related_element_id'] ?? null,
            $data['status']             ?? 'pending',
            $data['error_message']      ?? null,
            $_SESSION['user_id']        ?? null,
            $data['trigger_action']     ?? null,
            $_SERVER['REMOTE_ADDR']     ?? null,
        ]);
    } catch (Exception $e) {
        // Kalau gagal insert log, masih tetap log ke file (fallback)
        _emailLog("LOG DB FAILED: " . $e->getMessage());
    }
}

/**
 * Template: Notifikasi jawaban di-Return
 */
function sendReturnedEmail($user_row, $result_data, $extra_context = []) {
    $url = EMAIL_APP_URL . 'pages/my_feedback.php';

    $subject = "[PSAIMS] Jawaban Anda perlu direvisi — " . ($result_data['element_name'] ?? 'Assessment');

    $html = _emailTemplate([
        'title'      => 'Jawaban Perlu Direvisi',
        'color'      => '#dc3545',
        'greeting'   => "Halo <strong>" . htmlspecialchars($user_row['full_name']) . "</strong>,",
        'intro'      => "Jawaban Anda untuk <strong>" . htmlspecialchars($result_data['element_name']) . "</strong> (" .
                        htmlspecialchars($result_data['question_ref']) . ") dikembalikan oleh assessor untuk direvisi.",
        'details'    => [
            'Elemen'            => $result_data['element_name'],
            'Pertanyaan'        => $result_data['question_ref'],
            'Skor Anda'         => $result_data['score'] . '%',
            'Direview oleh'     => $result_data['verified_by_name'],
            'Pada'              => date('d F Y, H:i', strtotime($result_data['verified_at'])),
        ],
        'comment_label' => 'Komentar Assessor',
        'comment'    => $result_data['assessor_comment'] ?? '(tidak ada komentar)',
        'cta_label'  => 'Lihat &amp; Revisi Jawaban',
        'cta_url'    => $url,
        'footer'     => 'Silakan login ke aplikasi untuk melihat detail dan melakukan revisi.',
    ]);

    $context = array_merge([
        'recipient_user_id'  => $user_row['id'] ?? null,
        'email_type'         => 'returned',
        'related_result_id'  => $result_data['result_id'] ?? null,
        'trigger_action'     => 'return',
    ], $extra_context);

    return sendEmail($user_row['email'], $user_row['full_name'], $subject, $html, $context);
}

/**
 * Template: Notifikasi jawaban di-Verify
 */
function sendVerifiedEmail($user_row, $result_data, $extra_context = []) {
    $url = EMAIL_APP_URL . 'pages/my_feedback.php';

    $subject = "[PSAIMS] Jawaban Anda disetujui — " . ($result_data['element_name'] ?? 'Assessment');

    $html = _emailTemplate([
        'title'      => 'Jawaban Disetujui',
        'color'      => '#28a745',
        'greeting'   => "Halo <strong>" . htmlspecialchars($user_row['full_name']) . "</strong>,",
        'intro'      => "Jawaban Anda untuk <strong>" . htmlspecialchars($result_data['element_name']) . "</strong> (" .
                        htmlspecialchars($result_data['question_ref']) . ") telah disetujui oleh assessor.",
        'details'    => [
            'Elemen'            => $result_data['element_name'],
            'Pertanyaan'        => $result_data['question_ref'],
            'Skor Final'        => $result_data['score'] . '%',
            'Diverifikasi oleh' => $result_data['verified_by_name'],
            'Pada'              => date('d F Y, H:i', strtotime($result_data['verified_at'])),
        ],
        'comment_label' => !empty($result_data['assessor_comment']) ? 'Komentar Assessor' : null,
        'comment'    => $result_data['assessor_comment'] ?? '',
        'cta_label'  => 'Lihat Detail',
        'cta_url'    => $url,
        'footer'     => 'Jawaban ini sekarang terkunci dan menjadi data final. Terima kasih atas kontribusi Anda.',
    ]);

    $context = array_merge([
        'recipient_user_id'  => $user_row['id'] ?? null,
        'email_type'         => 'verified',
        'related_result_id'  => $result_data['result_id'] ?? null,
        'trigger_action'     => 'verify',
    ], $extra_context);

    return sendEmail($user_row['email'], $user_row['full_name'], $subject, $html, $context);
}

/**
 * Resend email berdasarkan email_log ID (untuk fitur manual resend)
 */
function resendEmailFromLog($pdo, $log_id) {
    $stmt = $pdo->prepare(
        "SELECT el.*, u.email AS user_email, u.full_name AS user_name
         FROM email_log el
         LEFT JOIN users u ON u.id = el.recipient_user_id
         WHERE el.id = ?"
    );
    $stmt->execute([$log_id]);
    $log = $stmt->fetch();

    if (!$log) return false;

    // Rekonstruksi berdasarkan type
    if ($log['email_type'] === 'returned' || $log['email_type'] === 'verified') {
        $stmt = $pdo->prepare(
            "SELECT
                ar.score, ar.assessor_comment, ar.verified_at,
                q.criteria,
                e.element_name,
                verifier.full_name AS verified_by_name
             FROM assessment_results ar
             JOIN assessment_questions q ON q.id = ar.question_id
             JOIN psaims_elements e ON e.id = q.element_id
             LEFT JOIN users verifier ON verifier.id = ar.verified_by
             WHERE ar.id = ?"
        );
        $stmt->execute([$log['related_result_id']]);
        $data = $stmt->fetch();

        if (!$data) return false;

        $ref = '';
        if (preg_match('/\[Ref\s+([0-9.a-z]+)\]/i', $data['criteria'], $m)) {
            $ref = $m[1];
        }

        $user_row = [
            'id'        => $log['recipient_user_id'],
            'email'     => $log['user_email'] ?? $log['recipient_email'],
            'full_name' => $log['user_name'] ?? $log['recipient_name'],
        ];
        $result_payload = [
            'result_id'         => $log['related_result_id'],
            'element_name'      => $data['element_name'],
            'question_ref'      => $ref ? "Ref {$ref}" : 'Pertanyaan',
            'score'             => $data['score'],
            'verified_by_name'  => $data['verified_by_name'] ?? 'Assessor',
            'verified_at'       => $data['verified_at'],
            'assessor_comment'  => $data['assessor_comment'],
        ];

        $extra_context = ['trigger_action' => 'manual_resend'];

        if ($log['email_type'] === 'verified') {
            return sendVerifiedEmail($user_row, $result_payload, $extra_context);
        } else {
            return sendReturnedEmail($user_row, $result_payload, $extra_context);
        }
    }

    return false;
}

/**
 * Template HTML email
 */
function _emailTemplate($data) {
    $color = $data['color'] ?? '#007bff';
    $details_html = '';
    foreach ($data['details'] as $label => $value) {
        $details_html .= sprintf(
            '<tr><td style="padding:6px 12px 6px 0; color:#666; font-size:13px; vertical-align:top; white-space:nowrap;">%s</td>' .
            '<td style="padding:6px 0; color:#333; font-size:13px;"><strong>%s</strong></td></tr>',
            htmlspecialchars($label), htmlspecialchars($value)
        );
    }

    $comment_html = '';
    if (!empty($data['comment']) && !empty($data['comment_label'])) {
        $comment_html = sprintf(
            '<div style="margin:20px 0; padding:16px; background:#fffbea; border-left:4px solid #ffc107; border-radius:4px;">' .
            '<strong style="display:block; margin-bottom:8px; color:#856404;">%s:</strong>' .
            '<div style="color:#333; font-size:14px; line-height:1.6; white-space:pre-wrap;">%s</div>' .
            '</div>',
            htmlspecialchars($data['comment_label']), htmlspecialchars($data['comment'])
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
    <tr><td align="center">
        <table width="600" cellpadding="0" cellspacing="0" style="background:#ffffff; border-radius:8px; overflow:hidden; box-shadow:0 2px 4px rgba(0,0,0,0.05);">
            <tr>
                <td style="background:{$color}; padding:20px 24px; color:#fff;">
                    <h1 style="margin:0; font-size:20px; font-weight:500;">{$data['title']}</h1>
                    <small style="opacity:0.85;">PSAIMS Self-Assessment Tool</small>
                </td>
            </tr>
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
            <tr>
                <td style="background:#f9f9f9; padding:16px 24px; border-top:1px solid #eee; text-align:center;">
                    <small style="color:#999; font-size:11px;">
                        Email otomatis dari sistem PSAIMS PT Pertamina Gas<br>
                        Mohon tidak reply email ini langsung.
                    </small>
                </td>
            </tr>
        </table>
    </td></tr>
</table>
</body>
</html>
HTML;
}

/**
 * Simpan log file (fallback)
 */
function _emailLog($message) {
    if (!defined('EMAIL_DEBUG') || !EMAIL_DEBUG) return;
    if (!defined('EMAIL_LOG_PATH')) return;

    $log_dir = dirname(EMAIL_LOG_PATH);
    if (!is_dir($log_dir)) @mkdir($log_dir, 0755, true);

    $line = '[' . date('Y-m-d H:i:s') . '] ' . $message . PHP_EOL;
    @file_put_contents(EMAIL_LOG_PATH, $line, FILE_APPEND | LOCK_EX);
}