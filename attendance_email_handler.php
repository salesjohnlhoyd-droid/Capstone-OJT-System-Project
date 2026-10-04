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

        // Build the message (HTML + plain-text fallback). A rendering problem must never stop delivery.
        try {
            $built = buildAttendanceScheduleEmail(
                $name, $isAdmin, $company_name, $supervisor_name, $formatted_date, $schedule_scope,
                $am_in_range, $am_out_range, $pm_in_range, $pm_out_range, $skip_am, $skip_pm
            );
        } catch (Throwable $e) {
            $email_errors[] = ['email' => $to, 'student' => $name, 'reasons' => ['Could not build email content: ' . $e->getMessage()]];
            return;
        }
        $body    = $built['html'];
        $altBody = $built['text'];

        $mail = null;
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
            $mail->AltBody  = $altBody;
            $mail->send();
            $email_sent[] = $to;
        } catch (Throwable $e) {
            $reason = ($mail && $mail->ErrorInfo) ? $mail->ErrorInfo : $e->getMessage();
            $email_errors[] = ['email' => $to, 'student' => $name, 'reasons' => [$reason]];
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
/**
 * Build the schedule-update email (HTML + plain text).
 * Table-based layout with inline styles so it renders consistently in Gmail, Outlook and mobile clients.
 * Palette is limited to: navy #07145f (headings/brand), slate #4b5563 (body text), white (on navy).
 *
 * @return array ['html' => string, 'text' => string]
 */
function buildAttendanceScheduleEmail($name, $isAdmin, $company_name, $supervisor_name, $formatted_date, $schedule_scope,
                                      $am_in_range, $am_out_range, $pm_in_range, $pm_out_range, $skip_am = false, $skip_pm = false) {
    $e = function ($v) { return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); };

    // A session counts as skipped when flagged, or when both of its windows were left empty.
    $isSkipped = function ($r) { $r = trim((string)$r); return $r === '' || strcasecmp($r, 'Skipped') === 0; };
    $am_off = $skip_am || ($isSkipped($am_in_range) && $isSkipped($am_out_range));
    $pm_off = $skip_pm || ($isSkipped($pm_in_range) && $isSkipped($pm_out_range));
    $show = function ($r, $off) use ($isSkipped) { return ($off || $isSkipped($r)) ? 'Not required' : $r; };

    $am_in  = $show($am_in_range,  $am_off);  $am_out = $show($am_out_range, $am_off);
    $pm_in  = $show($pm_in_range,  $pm_off);  $pm_out = $show($pm_out_range, $pm_off);

    $name = trim((string)$name) !== '' ? trim((string)$name) : ($isAdmin ? 'Administrator' : 'Student');

    // Summary sentence adapts to which sessions are active.
    if ($am_off && $pm_off) {
        $summary = 'No duty sessions are currently scheduled. Attendance will not be recorded until a new schedule is set.';
    } elseif ($am_off) {
        $summary = 'Only the afternoon session is required. Morning attendance will not be recorded.';
    } elseif ($pm_off) {
        $summary = 'Only the morning session is required. Afternoon attendance will not be recorded.';
    } else {
        $summary = 'Both the morning and afternoon sessions are required each OJT weekday.';
    }

    if ($isAdmin) {
        $intro   = $e($company_name) . ' has updated its OJT attendance schedule. The new time windows are shown below for your records. No action is required on your part.';
        $actions = [
            'Students assigned to this company have received the same notice.',
            'Past attendance records are not affected by this change.',
            'Questions about this schedule can be directed to the supervisor, ' . $supervisor_name . '.',
        ];
        $actions_title = 'For your information';
        $badge = 'Administrator copy';
    } else {
        $intro   = 'Your supervisor at ' . $e($company_name) . ' has updated your attendance schedule. Please review the time windows below and plan your daily sign-in and sign-out accordingly.';
        $actions = [
            'Sign in and sign out only within the windows listed above. Entries made outside a window may not be accepted.',
            'Weekends are automatically marked as Day Off, so no attendance is needed on Saturday or Sunday.',
            'Missing a sign-in or sign-out may result in an Absent or Incomplete status for that session.',
            'If anything looks incorrect, please contact your supervisor, ' . $supervisor_name . ', as soon as possible.',
        ];
        $actions_title = 'What you need to do';
        $badge = 'Student notice';
    }

    $navy = '#07145f'; $slate = '#4b5563'; $line = '#e5e7eb'; $tint = '#f4f6fb';

    $row = function ($label, $in, $out) use ($e, $navy, $slate, $line) {
        $cell = function ($v) use ($e, $navy, $slate) {
            $off = ($v === 'Not required');
            return '<td style="padding:14px 16px;font-size:14px;line-height:1.4;' . ($off ? 'color:' . $slate . ';font-style:italic;' : 'color:' . $navy . ';font-weight:700;') . '">' . $e($v) . '</td>';
        };
        return '<tr><td style="padding:14px 16px;border-top:1px solid ' . $line . ';font-size:14px;font-weight:700;color:' . $navy . ';">' . $e($label) . '</td>'
             . str_replace('<td style="', '<td style="border-top:1px solid ' . $line . ';', $cell($in))
             . str_replace('<td style="', '<td style="border-top:1px solid ' . $line . ';', $cell($out)) . '</tr>';
    };

    $li = '';
    foreach ($actions as $a) {
        $li .= '<tr><td valign="top" style="padding:0 10px 10px 0;font-size:14px;color:' . $navy . ';font-weight:700;width:14px;">&bull;</td>'
             . '<td style="padding:0 0 10px;font-size:14px;line-height:1.6;color:' . $slate . ';">' . $e($a) . '</td></tr>';
    }

    $sent_at = date('F j, Y \a\t g:i A');

    $html = '<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Attendance Schedule Update</title>
</head>
<body style="margin:0;padding:0;background:' . $tint . ';font-family:\'Segoe UI\',Helvetica,Arial,sans-serif;">
<span style="display:none;max-height:0;overflow:hidden;opacity:0;">' . $e($company_name) . ' updated the attendance schedule effective ' . $e($formatted_date) . '.</span>
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="background:' . $tint . ';padding:28px 12px;">
<tr><td align="center">
  <table role="presentation" width="600" cellpadding="0" cellspacing="0" border="0" style="width:100%;max-width:600px;background:#ffffff;border:1px solid ' . $line . ';">

    <tr><td style="background:' . $navy . ';padding:28px 32px;">
      <p style="margin:0 0 6px;font-size:12px;letter-spacing:1.5px;text-transform:uppercase;color:#ffffff;">NEUST On-the-Job Training System</p>
      <h1 style="margin:0;font-size:24px;line-height:1.3;font-weight:700;color:#ffffff;">Attendance Schedule Updated</h1>
    </td></tr>

    <tr><td style="padding:28px 32px 8px;">
      <p style="margin:0 0 12px;font-size:16px;line-height:1.5;color:' . $navy . ';">Dear <strong>' . $e($name) . '</strong>,</p>
      <p style="margin:0 0 20px;font-size:14px;line-height:1.7;color:' . $slate . ';">' . $intro . '</p>
    </td></tr>

    <tr><td style="padding:0 32px 24px;">
      <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="border:1px solid ' . $line . ';">
        <tr>
          <td width="50%" style="padding:14px 16px;background:' . $tint . ';">
            <p style="margin:0;font-size:11px;letter-spacing:1px;text-transform:uppercase;color:' . $slate . ';">Effective from</p>
            <p style="margin:4px 0 0;font-size:14px;font-weight:700;color:' . $navy . ';">' . $e($formatted_date) . '</p>
          </td>
          <td width="50%" style="padding:14px 16px;background:' . $tint . ';border-left:1px solid ' . $line . ';">
            <p style="margin:0;font-size:11px;letter-spacing:1px;text-transform:uppercase;color:' . $slate . ';">Company / Supervisor</p>
            <p style="margin:4px 0 0;font-size:14px;font-weight:700;color:' . $navy . ';">' . $e($company_name) . '</p>
            <p style="margin:2px 0 0;font-size:13px;color:' . $slate . ';">' . $e($supervisor_name) . '</p>
          </td>
        </tr>
      </table>
    </td></tr>

    <tr><td style="padding:0 32px 8px;">
      <h2 style="margin:0 0 6px;font-size:16px;font-weight:700;color:' . $navy . ';">Your daily time windows</h2>
      <p style="margin:0 0 12px;font-size:14px;line-height:1.6;color:' . $slate . ';">' . $e($summary) . '</p>
      <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="border:1px solid ' . $line . ';border-collapse:collapse;">
        <tr style="background:' . $navy . ';">
          <td style="padding:10px 16px;font-size:12px;letter-spacing:1px;text-transform:uppercase;color:#ffffff;">Session</td>
          <td style="padding:10px 16px;font-size:12px;letter-spacing:1px;text-transform:uppercase;color:#ffffff;">Sign-in window</td>
          <td style="padding:10px 16px;font-size:12px;letter-spacing:1px;text-transform:uppercase;color:#ffffff;">Sign-out window</td>
        </tr>
        ' . $row('Morning (AM)', $am_in, $am_out) . '
        ' . $row('Afternoon (PM)', $pm_in, $pm_out) . '
      </table>
    </td></tr>

    <tr><td style="padding:20px 32px 8px;">
      <h2 style="margin:0 0 6px;font-size:16px;font-weight:700;color:' . $navy . ';">Schedule coverage</h2>
      <p style="margin:0;font-size:14px;line-height:1.7;color:' . $slate . ';">' . $e($schedule_scope) . '</p>
    </td></tr>

    <tr><td style="padding:20px 32px 12px;">
      <h2 style="margin:0 0 10px;font-size:16px;font-weight:700;color:' . $navy . ';">' . $e($actions_title) . '</h2>
      <table role="presentation" cellpadding="0" cellspacing="0" border="0" width="100%">' . $li . '</table>
    </td></tr>

    <tr><td style="padding:8px 32px 28px;">
      <p style="margin:0;font-size:14px;line-height:1.7;color:' . $slate . ';">Thank you,<br><strong style="color:' . $navy . ';">' . $e($supervisor_name) . '</strong><br>' . $e($company_name) . '</p>
    </td></tr>

    <tr><td style="background:' . $tint . ';border-top:1px solid ' . $line . ';padding:16px 32px;text-align:center;">
      <p style="margin:0 0 4px;font-size:12px;font-weight:700;color:' . $navy . ';">NEUST On-the-Job Training System &nbsp;|&nbsp; ' . $e($badge) . '</p>
      <p style="margin:0;font-size:12px;line-height:1.6;color:' . $slate . ';">This is an automated message sent on ' . $e($sent_at) . '. Please do not reply directly to this email.</p>
    </td></tr>

  </table>
</td></tr>
</table>
</body>
</html>';

    // Plain-text version for clients that do not render HTML.
    $text  = "ATTENDANCE SCHEDULE UPDATED\nNEUST On-the-Job Training System\n\n";
    $text .= "Dear {$name},\n\n" . html_entity_decode(strip_tags($intro), ENT_QUOTES, 'UTF-8') . "\n\n";
    $text .= "Effective from: {$formatted_date}\nCompany: {$company_name}\nSupervisor: {$supervisor_name}\n\n";
    $text .= "DAILY TIME WINDOWS\n{$summary}\n";
    $text .= "- Morning (AM):   Sign-in {$am_in} | Sign-out {$am_out}\n";
    $text .= "- Afternoon (PM): Sign-in {$pm_in} | Sign-out {$pm_out}\n\n";
    $text .= "SCHEDULE COVERAGE\n{$schedule_scope}\n\n" . strtoupper($actions_title) . "\n";
    foreach ($actions as $a) { $text .= "- {$a}\n"; }
    $text .= "\nThank you,\n{$supervisor_name}\n{$company_name}\n\nThis is an automated message. Please do not reply directly to this email.\n";

    return ['html' => $html, 'text' => $text];
}
?>