<?php
/**
 * send_interview_notifications.php
 * ─────────────────────────────────
 * Run via cron job / Task Scheduler every minute:
 *   Windows XAMPP Task Scheduler:
 *     php C:\xampp\htdocs\your_project\send_interview_notifications.php
 *
 * Sends TWO types of emails per company:
 *   1. Early reminder  → 1 hour before interview_date  (uses interview_early_reminder_sent flag)
 *   2. On-time reminder → within ±1 minute of interview_date (uses interview_reminder_sent flag)
 *
 * Both emails go to the company supervisor and include:
 *   - A summary table of ALL students scheduled today
 *   - Individual detail blocks for each student whose time triggered this send
 */

/* ── Reliability: prevent timeout on large datasets ──────────────────────── */
set_time_limit(300);
ini_set('memory_limit', '128M');

/* ── Reliability: global exception handler so cron always sees clean output ─ */
set_exception_handler(function (Throwable $e) {
    $msg = "[" . date('Y-m-d H:i:s') . "] UNCAUGHT EXCEPTION: " . $e->getMessage()
         . " in " . $e->getFile() . " on line " . $e->getLine() . "\n";
    error_log($msg);
    logRun($msg);
    echo $msg;
    exit(1);
});

set_error_handler(function ($severity, $message, $file, $line) {
    if (!(error_reporting() & $severity)) return false;
    throw new ErrorException($message, 0, $severity, $file, $line);
});

/* ── Reliability: persistent log so you can confirm every cron execution ─── */
define('LOG_FILE', __DIR__ . '/interview_notification.log');
function logRun(string $message): void {
    $entry = "[" . date('Y-m-d H:i:s') . "] " . trim($message) . "\n";
    file_put_contents(LOG_FILE, $entry, FILE_APPEND | LOCK_EX);
    echo $entry;
}

/* ── Reliability: keep log file from growing indefinitely (keep last 500 lines) ── */
if (file_exists(LOG_FILE)) {
    $lines = file(LOG_FILE, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    if (count($lines) > 500) {
        file_put_contents(LOG_FILE, implode("\n", array_slice($lines, -500)) . "\n");
    }
}

logRun("Reminder check started.");

/* ── Database connection ─────────────────────────────────────────────────── */
include __DIR__ . "/db.php";

/* ── Reliability: verify DB connection is alive before proceeding ─────────── */
if (!$conn || $conn->connect_error) {
    logRun("ERROR: Database connection failed — " . ($conn->connect_error ?? 'unknown error'));
    exit(1);
}

/* ── Reliability: keep the connection alive (important for long-running loops) ── */
$conn->options(MYSQLI_OPT_CONNECT_TIMEOUT, 10);

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;
require __DIR__ . '/PHPMailer/src/PHPMailer.php';
require __DIR__ . '/PHPMailer/src/SMTP.php';
require __DIR__ . '/PHPMailer/src/Exception.php';

/* ── Ensure reminder columns exist ───────────────────────────────────────── */
$conn->query("ALTER TABLE ojt_applications ADD COLUMN IF NOT EXISTS phase VARCHAR(20) NOT NULL DEFAULT 'pending'");
$conn->query("ALTER TABLE ojt_applications ADD COLUMN IF NOT EXISTS interview_date DATETIME NULL");
$conn->query("ALTER TABLE ojt_applications ADD COLUMN IF NOT EXISTS interview_notes TEXT NULL");
$conn->query("ALTER TABLE ojt_applications ADD COLUMN IF NOT EXISTS interview_reminder_sent TINYINT(1) NOT NULL DEFAULT 0");
$conn->query("ALTER TABLE ojt_applications ADD COLUMN IF NOT EXISTS interview_early_reminder_sent TINYINT(1) NOT NULL DEFAULT 0");

/* ── Helper: send email ───────────────────────────────────────────────────── */
function sendMail($to, $subject, $body) {
    $mail = new PHPMailer(true);
    try {
        $mail->isSMTP();
        $mail->Host       = 'smtp.gmail.com';
        $mail->SMTPAuth   = true;
        $mail->Username   = 'salesjohnlhoyd@gmail.com';
        $mail->Password   = 'qwufanprpmezotly';
        $mail->SMTPSecure = 'tls';
        $mail->Port       = 587;
        $mail->setFrom('salesjohnlhoyd@gmail.com', 'NEUST OJT System');
        $mail->addAddress($to);
        $mail->isHTML(true);
        $mail->Subject = $subject;
        $mail->Body    = $body;
        $mail->send();
        return true;
    } catch (Exception $e) {
        error_log("PHPMailer error: " . $e->getMessage());
        return false;
    }
}

/* ── Helper: build and send one reminder email to a company ──────────────── */
function sendReminderEmail(
    $conn,
    $company_id,
    $company_email,
    $company_name,
    $supervisor_name,
    $supervisor_pos,
    array $triggered,
    array $allToday,
    string $reminderType
) {
    $supervisorDisplay = htmlspecialchars($supervisor_name)
        . ($supervisor_pos
            ? ' <span style="color:#64748b;font-weight:400;">(' . htmlspecialchars($supervisor_pos) . ')</span>'
            : '');

    /* ── Summary table: ALL today's interviews ── */
    $summaryRows = '';
    foreach ($allToday as $s) {
        $fn      = trim($s['first_name'] . ' ' . ($s['middle_name'] ? $s['middle_name'] . ' ' : '') . $s['last_name']);
        $timeStr = date('g:i A', strtotime($s['interview_date']));
        $summaryRows .= "
            <tr>
                <td style='padding:9px 12px;border-bottom:1px solid #e2e8f0;'>" . htmlspecialchars($fn) . "</td>
                <td style='padding:9px 12px;border-bottom:1px solid #e2e8f0;color:#64748b;font-size:12px;'>" . htmlspecialchars($s['course'] ?? '') . "</td>
                <td style='padding:9px 12px;border-bottom:1px solid #e2e8f0;font-weight:700;color:#1d4ed8;'>" . $timeStr . "</td>
                <td style='padding:9px 12px;border-bottom:1px solid #e2e8f0;color:#64748b;font-size:12px;'>" . htmlspecialchars($s['student_email']) . "</td>
            </tr>";
    }

    /* ── Detail blocks: only triggered students ── */
    $detailBlocks = '';
    foreach ($triggered as $s) {
        $fn      = trim($s['first_name'] . ' ' . ($s['middle_name'] ? $s['middle_name'] . ' ' : '') . $s['last_name']);
        $timeStr = date('F j, Y \a\t g:i A', strtotime($s['interview_date']));

        $skills = array_filter([$s['skill1'] ?? '', $s['skill2'] ?? '', $s['skill3'] ?? '']);
        $skillHtml = $skills
            ? '<ul style="margin:4px 0 0;padding-left:18px;color:#475569;font-size:12px;">'
              . implode('', array_map(fn($sk) => '<li>' . htmlspecialchars($sk) . '</li>', $skills))
              . '</ul>'
            : '<span style="color:#a0aec0;font-size:12px;">None listed</span>';

        $exps = array_filter([$s['exp1'] ?? '', $s['exp2'] ?? '']);
        $expHtml = $exps
            ? '<ul style="margin:4px 0 0;padding-left:18px;color:#475569;font-size:12px;">'
              . implode('', array_map(fn($ex) => '<li>' . htmlspecialchars($ex) . '</li>', $exps))
              . '</ul>'
            : '<span style="color:#a0aec0;font-size:12px;">None listed</span>';

        $notesHtml = !empty($s['interview_notes'])
            ? '<p style="background:#fef9c3;border:1px solid #fde68a;padding:8px 12px;border-radius:8px;font-size:12px;margin:8px 0 0;">'
              . '<strong>Notes:</strong> ' . nl2br(htmlspecialchars($s['interview_notes'])) . '</p>'
            : '';

        $detailBlocks .= "
        <div style='border:1px solid #bfdbfe;border-radius:12px;padding:18px 20px;margin-bottom:16px;background:#eff6ff;'>
            <div style='display:flex;align-items:center;gap:12px;margin-bottom:12px;'>
                <div style='flex:1;'>
                    <div style='font-weight:700;font-size:15px;color:#1e293b;'>" . htmlspecialchars($fn) . "</div>
                    <div style='font-size:12px;color:#3b82f6;margin-top:2px;'>&#x1F4E7; " . htmlspecialchars($s['student_email']) . "</div>
                    <div style='font-size:12px;color:#64748b;'>&#x1F4DA; " . htmlspecialchars($s['course'] ?? '') . "</div>
                </div>
                <div style='background:#dbeafe;color:#1d4ed8;padding:6px 14px;border-radius:20px;font-size:12px;font-weight:700;white-space:nowrap;'>
                    &#x23F0; " . $timeStr . "
                </div>
            </div>
            <table style='width:100%;border-collapse:collapse;font-size:13px;'>
                <tr>
                    <td style='width:50%;vertical-align:top;padding-right:12px;'>
                        <strong style='color:#374151;'>Skills</strong>
                        $skillHtml
                    </td>
                    <td style='width:50%;vertical-align:top;'>
                        <strong style='color:#374151;'>Work Experience</strong>
                        $expHtml
                    </td>
                </tr>
            </table>
            $notesHtml
        </div>";
    }

    $todayFormatted = date('F j, Y');
    $triggeredCount = count($triggered);
    $totalCount     = count($allToday);

    /* ── Email header copy differs by reminder type ── */
    if ($reminderType === 'early') {
        $badgeLabel  = '&#x23F3; 1-Hour Advance Reminder';
        $headerBg    = '#07145f';
        $introLine   = "This is an advance reminder that <strong>$triggeredCount</strong> interview(s) are scheduled to begin in <strong>1 hour</strong>.";
        $detailTitle = 'Applicant Details (Starting in 1 Hour)';
        $subjectLine = 'Interview Reminder (1 Hour Away) – ' . $company_name . ' (' . $todayFormatted . ')';
    } else {
        $badgeLabel  = '&#x1F4C5; Interview Day Reminder';
        $headerBg    = '#07145f';
        $introLine   = "This is an automated reminder that <strong>$triggeredCount</strong> interview(s) are starting <strong>right now</strong>.";
        $detailTitle = 'Applicant Details (Now Starting)';
        $subjectLine = 'Interview Day Reminder – ' . $company_name . ' (' . $todayFormatted . ')';
    }

    $emailBody = "
    <div style='font-family:sans-serif;max-width:600px;margin:auto;'>
        <div style='background:{$headerBg};padding:24px 28px;border-radius:12px 12px 0 0;'>
            <h2 style='color:#FFD700;margin:0;font-size:20px;'>{$badgeLabel}</h2>
            <p style='color:rgba(255,255,255,0.8);margin:6px 0 0;font-size:13px;'>$todayFormatted &nbsp;&bull;&nbsp; " . htmlspecialchars($company_name) . "</p>
        </div>
        <div style='border:1px solid #e2e8f0;border-top:none;border-radius:0 0 12px 12px;padding:24px 28px;'>
            <p style='margin:0 0 8px;'>Dear <strong>$supervisorDisplay</strong>,</p>
            <p style='margin:0 0 20px;color:#475569;font-size:13px;'>
                $introLine
                Below is today's full schedule (<strong>$totalCount</strong> total) followed by individual applicant details.
            </p>

            <h3 style='margin:0 0 10px;color:#07145f;font-size:14px;text-transform:uppercase;letter-spacing:0.5px;border-bottom:2px solid #FFD700;padding-bottom:6px;'>
                Today's Full Schedule
            </h3>
            <table style='width:100%;border-collapse:collapse;font-size:13px;margin-bottom:24px;'>
                <thead>
                    <tr style='background:#f1f5f9;'>
                        <th style='padding:9px 12px;text-align:left;color:#374151;font-size:11px;text-transform:uppercase;'>Student</th>
                        <th style='padding:9px 12px;text-align:left;color:#374151;font-size:11px;text-transform:uppercase;'>Course</th>
                        <th style='padding:9px 12px;text-align:left;color:#374151;font-size:11px;text-transform:uppercase;'>Time</th>
                        <th style='padding:9px 12px;text-align:left;color:#374151;font-size:11px;text-transform:uppercase;'>Email</th>
                    </tr>
                </thead>
                <tbody>
                    $summaryRows
                </tbody>
            </table>

            <h3 style='margin:0 0 12px;color:#07145f;font-size:14px;text-transform:uppercase;letter-spacing:0.5px;border-bottom:2px solid #FFD700;padding-bottom:6px;'>
                $detailTitle
            </h3>
            $detailBlocks

            <p style='margin:20px 0 0;color:#94a3b8;font-size:11px;'>This is an automated message from the NEUST OJT System. Please do not reply to this email.</p>
        </div>
    </div>";

    $sent = sendMail($company_email, $subjectLine, $emailBody);

    /* ── Mark the correct flag so this email never fires twice ── */
    $ids    = implode(',', array_map(fn($s) => intval($s['id']), $triggered));
    $column = $reminderType === 'early' ? 'interview_early_reminder_sent' : 'interview_reminder_sent';
    $conn->query("UPDATE ojt_applications SET $column = 1 WHERE id IN ($ids)");

    return $sent;
}

/* ══════════════════════════════════════════════════════════════════════════════
   MAIN LOOP — iterate over every company that has interview-phase applications
   ══════════════════════════════════════════════════════════════════════════════ */

$companiesResult = $conn->query("
    SELECT DISTINCT a.company_id
    FROM ojt_applications a
    WHERE a.phase = 'interview'
      AND a.interview_date IS NOT NULL
      AND DATE(a.interview_date) = CURDATE()
");

/* ── Reliability: handle query failure gracefully ── */
if ($companiesResult === false) {
    logRun("ERROR: Main companies query failed — " . $conn->error);
    exit(1);
}

$companiesProcessed = 0;
$emailsSent         = 0;

while ($compRow = $companiesResult->fetch_assoc()) {
    $cid = intval($compRow['company_id']);
    $companiesProcessed++;

    /* ── Fetch company profile ── */
    $ciRow = $conn->query("
        SELECT company, contact_first_name, contact_middle_initial, contact_last_name, position
        FROM company_information WHERE user_id=$cid
    ")->fetch_assoc();

    $ceRow = $conn->query("
        SELECT email FROM users WHERE id=$cid AND role='company'
    ")->fetch_assoc();

    if (empty($ceRow['email'])) {
        logRun("SKIP company_id=$cid — no email address found.");
        continue;
    }

    $company_email   = $ceRow['email'];
    $company_name    = $ciRow['company'] ?? 'Our Company';
    $supervisor_name = trim(
        ($ciRow['contact_first_name']     ?? '') . ' ' .
        ($ciRow['contact_middle_initial'] ?? '') . ' ' .
        ($ciRow['contact_last_name']      ?? '')
    );
    $supervisor_pos  = $ciRow['position'] ?? '';

    /* ── All students scheduled TODAY for this company (summary table) ── */
    $todayStmt = $conn->prepare("
        SELECT a.id, a.interview_date, a.interview_notes,
               a.skill1, a.skill2, a.skill3, a.exp1, a.exp2,
               u.first_name, u.middle_name, u.last_name,
               u.email AS student_email, u.course
        FROM ojt_applications a
        INNER JOIN users u ON u.id = a.student_id
        WHERE a.company_id = ?
          AND a.phase = 'interview'
          AND a.interview_date IS NOT NULL
          AND DATE(a.interview_date) = CURDATE()
        ORDER BY a.interview_date ASC
    ");
    $todayStmt->bind_param("i", $cid);
    $todayStmt->execute();
    $allToday = $todayStmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $todayStmt->close();

    if (empty($allToday)) continue;

    /* ──────────────────────────────────────────────────────────────────────
       TRIGGER 1: Early reminder — 1 hour before (±1 minute window)
    ────────────────────────────────────────────────────────────────────── */
    $earlyStmt = $conn->prepare("
        SELECT a.id, a.interview_date, a.interview_notes,
               a.skill1, a.skill2, a.skill3, a.exp1, a.exp2,
               u.first_name, u.middle_name, u.last_name,
               u.email AS student_email, u.course
        FROM ojt_applications a
        INNER JOIN users u ON u.id = a.student_id
        WHERE a.company_id = ?
          AND a.phase = 'interview'
          AND a.interview_early_reminder_sent = 0
          AND a.interview_date IS NOT NULL
          AND ABS(TIMESTAMPDIFF(SECOND, DATE_SUB(a.interview_date, INTERVAL 1 HOUR), NOW())) <= 60
    ");
    $earlyStmt->bind_param("i", $cid);
    $earlyStmt->execute();
    $earlyTriggered = $earlyStmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $earlyStmt->close();

    if (!empty($earlyTriggered)) {
        $sent = sendReminderEmail(
            $conn, $cid, $company_email,
            $company_name, $supervisor_name, $supervisor_pos,
            $earlyTriggered, $allToday, 'early'
        );
        if ($sent) {
            $emailsSent++;
            logRun("SENT early reminder → company_id=$cid ({$company_name}), triggered=" . count($earlyTriggered) . " student(s).");
        } else {
            logRun("FAILED early reminder → company_id=$cid ({$company_name}).");
        }
    }

    /* ──────────────────────────────────────────────────────────────────────
       TRIGGER 2: On-time reminder — within ±1 minute of interview_date
    ────────────────────────────────────────────────────────────────────── */
    $ontimeStmt = $conn->prepare("
        SELECT a.id, a.interview_date, a.interview_notes,
               a.skill1, a.skill2, a.skill3, a.exp1, a.exp2,
               u.first_name, u.middle_name, u.last_name,
               u.email AS student_email, u.course
        FROM ojt_applications a
        INNER JOIN users u ON u.id = a.student_id
        WHERE a.company_id = ?
          AND a.phase = 'interview'
          AND a.interview_reminder_sent = 0
          AND a.interview_date IS NOT NULL
          AND ABS(TIMESTAMPDIFF(SECOND, a.interview_date, NOW())) <= 60
    ");
    $ontimeStmt->bind_param("i", $cid);
    $ontimeStmt->execute();
    $ontimeTriggered = $ontimeStmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $ontimeStmt->close();

    if (!empty($ontimeTriggered)) {
        $sent = sendReminderEmail(
            $conn, $cid, $company_email,
            $company_name, $supervisor_name, $supervisor_pos,
            $ontimeTriggered, $allToday, 'ontime'
        );
        if ($sent) {
            $emailsSent++;
            logRun("SENT on-time reminder → company_id=$cid ({$company_name}), triggered=" . count($ontimeTriggered) . " student(s).");
        } else {
            logRun("FAILED on-time reminder → company_id=$cid ({$company_name}).");
        }
    }
}

logRun("Reminder check complete. Companies checked: $companiesProcessed | Emails sent: $emailsSent.");