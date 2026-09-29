<?php
/**
 * Attendance Email Handler
 * This file handles all email notifications for attendance schedule updates
 * Separated from attendance_management.php for better code organization
 */

require __DIR__ . '/phpmailer/src/Exception.php';
require __DIR__ . '/phpmailer/src/PHPMailer.php';
require __DIR__ . '/phpmailer/src/SMTP.php';

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception as MailException;

define('SMTP_HOST',      'smtp.gmail.com');
define('SMTP_PORT',      587);
define('SMTP_USER',      'salesjohnlhoyd@gmail.com');
define('SMTP_PASS',      'plmonclcxmxgvqiz');
define('SMTP_FROM',      'salesjohnlhoyd@gmail.com');
define('SMTP_FROM_NAME', 'Atate On the Job Training System');

/**
 * Send attendance schedule notification emails
 * 
 * @param mysqli $conn Database connection
 * @param int $company_id Company ID
 * @param string $date Date of the schedule
 * @param string $am_in_range AM sign-in window display text
 * @param string $am_out_range AM sign-out window display text
 * @param string $pm_in_range PM sign-in window display text
 * @param string $pm_out_range PM sign-out window display text
 * @param bool $skip_am Whether AM duty is skipped
 * @param bool $skip_pm Whether PM duty is skipped
 * @return array Returns array with 'email_sent' count, 'total_students', 'admin_notified', 'email_errors'
 */
function sendAttendanceScheduleEmails($conn, $company_id, $date, $am_in_range, $am_out_range, $pm_in_range, $pm_out_range, $skip_am = false, $skip_pm = false) {
    
    // Fetch company info
    $co_stmt = $conn->prepare("
        SELECT u.first_name, u.last_name, u.email, ci.company
        FROM users u
        LEFT JOIN company_information ci ON ci.user_id = u.id
        WHERE u.id = ? LIMIT 1
    ");
    $co_stmt->bind_param("i", $company_id);
    $co_stmt->execute();
    $co_row = $co_stmt->get_result()->fetch_assoc();
    $supervisor_name = trim(($co_row['first_name'] ?? '') . ' ' . ($co_row['last_name'] ?? ''));
    if (!$supervisor_name) $supervisor_name = 'Supervisor';
    $company_name = !empty($co_row['company']) ? $co_row['company'] : $supervisor_name;
    $co_stmt->close();

    // Fetch students email
    $stud_stmt = $conn->prepare("
        SELECT u.email, u.first_name, u.last_name
        FROM ojt_assignments oa
        JOIN users u ON oa.student_id = u.id
        WHERE oa.company_id = ?
          AND u.email IS NOT NULL AND u.email != ''
    ");
    $stud_stmt->bind_param("i", $company_id);
    $stud_stmt->execute();
    $students_email = $stud_stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stud_stmt->close();

    // Fetch admins email
    $admin_stmt = $conn->prepare("
        SELECT email, first_name, last_name
        FROM admins
        WHERE email IS NOT NULL AND email != ''
    ");
    $admin_stmt->execute();
    $admin_emails = $admin_stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $admin_stmt->close();

    $formatted_date = date("l, F j, Y", strtotime($date));
    
    // Calculate end date limit for scope display
    $ojt_start_stmt = $conn->prepare("SELECT MIN(date) as sd FROM attendance_settings WHERE company_id=?");
    $ojt_start_stmt->bind_param("i", $company_id);
    $ojt_start_stmt->execute();
    $ojt_sd_row = $ojt_start_stmt->get_result()->fetch_assoc();
    $ojt_start = $ojt_sd_row['sd'] ?? $date;
    $ojt_start_stmt->close();
    
    $end_date_limit = date("Y-m-d", strtotime("+4 months", strtotime($ojt_start)));
    $schedule_scope = "Starting {$formatted_date} — applied to ALL remaining OJT weekdays through " . date("F j, Y", strtotime($end_date_limit)) . ".";

    $email_errors = [];
    $email_sent = [];

    $sendScheduleEmail = function($to, $name, $isAdmin = false) use (
        $company_name, $supervisor_name, $formatted_date, $schedule_scope,
        $am_in_range, $am_out_range, $pm_in_range, $pm_out_range, $skip_am, $skip_pm,
        &$email_sent, &$email_errors
    ) {
        if (!filter_var($to, FILTER_VALIDATE_EMAIL)) {
            $email_errors[] = ['email' => $to, 'student' => $name, 'reasons' => ['Invalid email address format.']];
            return;
        }

        $subject = "[{$company_name}] Attendance Schedule Updated — {$formatted_date}";

        $greeting = $isAdmin ? "Dear <strong>" . htmlspecialchars($name) . "</strong> <span style='color:#6b7280;font-weight:400;'>(Administrator)</span>," : "Dear <strong>" . htmlspecialchars($name) . "</strong>,";
        $intro = $isAdmin
            ? "<strong>" . htmlspecialchars($company_name) . "</strong> has updated the OJT attendance schedule. Here are the details for your records."
            : "<strong>" . htmlspecialchars($company_name) . "</strong> has configured your attendance schedule. Please review the time windows below and sign in/out accordingly.";
        $footer_note = $isAdmin
            ? "This is an automated notification sent to all system administrators."
            : "Weekends are automatically marked as <strong>Day Off</strong>. Please sign in and out within the allowed windows to avoid being marked <strong>Absent</strong> or <strong>Incomplete</strong>.";

        $skip_note = '';
        if ($skip_am && $skip_pm) {
            $skip_note = '<div style="background:#fef2f2;border-left:4px solid #dc2626;padding:10px 15px;margin-bottom:16px;border-radius:8px;"><strong style="color:#dc2626;">⚠️ No Duty Schedule Configured</strong><br>Both AM and PM duty times have been skipped. Please contact your supervisor for attendance instructions.</div>';
        } elseif ($skip_am) {
            $skip_note = '<div style="background:#fffbeb;border-left:4px solid #f59e0b;padding:10px 15px;margin-bottom:16px;border-radius:8px;"><strong style="color:#f59e0b;">ℹ️ AM Duty Skipped</strong><br>Morning duty has been skipped. Only PM attendance will be recorded.</div>';
        } elseif ($skip_pm) {
            $skip_note = '<div style="background:#fffbeb;border-left:4px solid #f59e0b;padding:10px 15px;margin-bottom:16px;border-radius:8px;"><strong style="color:#f59e0b;">ℹ️ PM Duty Skipped</strong><br>Afternoon duty has been skipped. Only AM attendance will be recorded.</div>';
        }

        $body = '<!DOCTYPE html>
        <html lang="en">
        <head>
        <meta charset="UTF-8">
        <meta name="viewport" content="width=device-width,initial-scale=1">
        <title>Attendance Schedule Update</title>
        </head>
        <body style="margin:0;padding:0;background:#f3f4f6;font-family:\'Segoe UI\',Arial,sans-serif;">
        <table width="100%" cellpadding="0" cellspacing="0" style="background:#f3f4f6;padding:32px 16px;">
        <tr><td align="center">
            <table width="600" cellpadding="0" cellspacing="0" style="max-width:600px;width:100%;background:#ffffff;border-radius:16px;overflow:hidden;box-shadow:0 4px 24px rgba(0,0,0,0.09);">
            <tr>
                <td style="background:linear-gradient(135deg,#07145f 0%,#1a237e 60%,#283593 100%);padding:32px 36px 24px;">
                <table width="100%" cellpadding="0" cellspacing="0">
                    <tr>
                    <tr>
                        <p style="margin:0 0 6px;font-size:11px;font-weight:700;letter-spacing:2px;text-transform:uppercase;color:#FFD700;opacity:0.9;">NEUST OJT SYSTEM</p>
                        <h1 style="margin:0 0 4px;font-size:24px;font-weight:800;color:#ffffff;line-height:1.2;">Attendance Schedule</h1>
                        <p style="margin:0;font-size:14px;color:rgba(255,255,255,0.75);">Schedule update notification</p>
                    </th>
                    <td align="right" valign="top">
                        <div style="background:rgba(255,215,0,0.15);border:1.5px solid rgba(255,215,0,0.4);border-radius:10px;padding:10px 16px;display:inline-block;text-align:center;">
                        <p style="margin:0;font-size:10px;font-weight:700;letter-spacing:1px;text-transform:uppercase;color:rgba(255,255,255,0.6);">Effective From</p>
                        <p style="margin:4px 0 0;font-size:13px;font-weight:700;color:#FFD700;">' . htmlspecialchars($formatted_date) . '</p>
                        </div>
                    </td>
                    </tr>
                </table>
                </td>
            </tr>
            <tr>
                <td style="background:#f8f7ff;border-bottom:1px solid #e8e4f9;padding:14px 36px;">
                <table width="100%" cellpadding="0" cellspacing="0">
                    <tr>
                    <td width="50%">
                        <p style="margin:0;font-size:10px;font-weight:700;text-transform:uppercase;letter-spacing:1px;color:#9ca3af;">Company</p>
                        <p style="margin:3px 0 0;font-size:14px;font-weight:700;color:#07145f;">' . htmlspecialchars($company_name) . '</p>
                    </td>
                    <td width="50%">
                        <p style="margin:0;font-size:10px;font-weight:700;text-transform:uppercase;letter-spacing:1px;color:#9ca3af;">Supervisor</p>
                        <p style="margin:3px 0 0;font-size:14px;font-weight:600;color:#374151;">' . htmlspecialchars($supervisor_name) . '</p>
                    </td>
                    </tr>
                </table>
                </td>
            </tr>
            <tr>
                <td style="padding:28px 36px 0;">
                <p style="margin:0 0 6px;font-size:15px;color:#1f2937;line-height:1.6;">' . $greeting . '</p>
                <p style="margin:0 0 24px;font-size:14px;color:#4b5563;line-height:1.7;">' . $intro . '</p>
                ' . $skip_note . '
                <table width="100%" cellpadding="0" cellspacing="0" style="margin-bottom:24px;">
                    <tr>
                    <td style="background:#ede9fe;border-left:4px solid #7c3aed;border-radius:0 10px 10px 0;padding:12px 18px;">
                        <p style="margin:0;font-size:10px;font-weight:700;text-transform:uppercase;letter-spacing:1px;color:#7c3aed;">&#128197; Schedule Coverage</p>
                        <p style="margin:6px 0 0;font-size:13px;color:#4c1d95;font-weight:600;line-height:1.6;">' . htmlspecialchars($schedule_scope) . '</p>
                    </td>
                    </tr>
                </table>
                <table width="100%" cellpadding="0" cellspacing="0" style="margin-bottom:24px;border-spacing:0;">
                    <tr>
                    <td width="48%" valign="top" style="padding-right:8px;">
                        <table width="100%" cellpadding="0" cellspacing="0" style="background:#fffbeb;border:1.5px solid #fde68a;border-radius:12px;overflow:hidden;">
                        <tr><td style="background:linear-gradient(135deg,#f59e0b,#fbbf24);padding:10px 16px;"><p style="margin:0;font-size:13px;font-weight:800;color:#ffffff;letter-spacing:0.5px;">&#9728;&#65039; MORNING (AM)</p></td> </tr>
                        <tr><td style="padding:14px 16px;">
                            <table width="100%" cellpadding="0" cellspacing="0">
                                <tr><td style="padding-bottom:10px;"><p style="margin:0;font-size:10px;font-weight:700;text-transform:uppercase;letter-spacing:0.8px;color:#92400e;">Sign-In Window</p><p style="margin:4px 0 0;font-size:16px;font-weight:800;color:#78350f;">' . htmlspecialchars($am_in_range) . '</p></td> </tr>
                                <tr><td style="border-top:1px solid #fde68a;padding-top:10px;"><p style="margin:0;font-size:10px;font-weight:700;text-transform:uppercase;letter-spacing:0.8px;color:#92400e;">Sign-Out Window</p><p style="margin:4px 0 0;font-size:16px;font-weight:800;color:#78350f;">' . htmlspecialchars($am_out_range) . '</p></td> </tr>
                            </table>
                        </td> </tr>
                        </table>
                    </td>
                    <td width="48%" valign="top" style="padding-left:8px;">
                        <table width="100%" cellpadding="0" cellspacing="0" style="background:#eff6ff;border:1.5px solid #bfdbfe;border-radius:12px;overflow:hidden;">
                        <tr><td style="background:linear-gradient(135deg,#1d4ed8,#2563eb);padding:10px 16px;"><p style="margin:0;font-size:13px;font-weight:800;color:#ffffff;letter-spacing:0.5px;">&#127751; AFTERNOON (PM)</p></td> </tr>
                        <tr><td style="padding:14px 16px;">
                            <table width="100%" cellpadding="0" cellspacing="0">
                                <tr><td style="padding-bottom:10px;"><p style="margin:0;font-size:10px;font-weight:700;text-transform:uppercase;letter-spacing:0.8px;color:#1e40af;">Sign-In Window</p><p style="margin:4px 0 0;font-size:16px;font-weight:800;color:#1e3a8a;">' . htmlspecialchars($pm_in_range) . '</p></td> </tr>
                                <tr><td style="border-top:1px solid #bfdbfe;padding-top:10px;"><p style="margin:0;font-size:10px;font-weight:700;text-transform:uppercase;letter-spacing:0.8px;color:#1e40af;">Sign-Out Window</p><p style="margin:4px 0 0;font-size:16px;font-weight:800;color:#1e3a8a;">' . htmlspecialchars($pm_out_range) . '</p></td> </tr>
                            </table>
                        </td> </tr>
                        </table>
                    </td>
                    </tr>
                </table>
                <table width="100%" cellpadding="0" cellspacing="0" style="margin-bottom:28px;">
                    <tr><td style="background:#f0fdf4;border:1.5px solid #bbf7d0;border-radius:10px;padding:14px 18px;"><p style="margin:0;font-size:13px;color:#166534;line-height:1.65;">' . $footer_note . '</p></td> </tr>
                </table>
                </td>
            </tr>
            <tr>
                <td style="background:#f8f7ff;border-top:1px solid #e8e4f9;padding:18px 36px;text-align:center;">
                <p style="margin:0 0 4px;font-size:12px;font-weight:700;color:#07145f;letter-spacing:0.5px;">NEUST On-the-Job Training System</p>
                <p style="margin:0;font-size:11px;color:#9ca3af;">This is an automated message — please do not reply directly to this email.</p>
                </td>
            </tr>
            </table>
        </td>
        </tr>
        </body>
        </html>';

        try {
            $mail = new PHPMailer(true);
            $mail->isSMTP();
            $mail->Host       = 'smtp.gmail.com';
            $mail->SMTPAuth   = true;
            $mail->Username   = 'salesjohnlhoyd@gmail.com';
            $mail->Password   = 'qwufanprpmezotly';
            $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
            $mail->Port       = 587;
            $mail->setFrom('salesjohnlhoyd@gmail.com', 'Atate On the Job Training System');
            $mail->addAddress($to, $name);
            $mail->CharSet  = 'UTF-8';
            $mail->Subject  = $subject;
            $mail->isHTML(true);
            $mail->Body     = $body;
            $mail->AltBody  = strip_tags(str_replace(['<br>', '<br/>', '<br />', '</p>', '<tr>', '</tr>'], "\n", $body));
            $mail->send();
            $email_sent[] = $to;
        } catch (MailException $e) {
            $email_errors[] = ['email' => $to, 'student' => $name, 'reasons' => [$mail->ErrorInfo]];
        }
    };

    foreach ($students_email as $student) {
        $sendScheduleEmail($student['email'], $student['first_name'] . ' ' . $student['last_name'], false);
    }

    foreach ($admin_emails as $admin) {
        $adminName = trim(($admin['first_name'] ?? '') . ' ' . ($admin['last_name'] ?? ''));
        if (!$adminName) $adminName = 'Admin';
        $sendScheduleEmail($admin['email'], $adminName, true);
    }

    return [
        'email_sent' => count($email_sent),
        'total_students' => count($students_email),
        'admin_notified' => count($admin_emails),
        'email_errors' => $email_errors
    ];
}
?>