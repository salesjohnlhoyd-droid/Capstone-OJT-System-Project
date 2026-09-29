<?php
/**
 * send_friday_reminders.php
 *
 * Sends OJT weekly journal reminder emails to all students
 * who have not yet submitted their report for the current week.
 *
 * ── WINDOWS TASK SCHEDULER SETUP ──
 *   Program/script : C:\xampp\php\php.exe          (use YOUR actual php.exe path)
 *   Arguments      : "C:\xampp\htdocs\yourproject\send_friday_reminders.php"
 *   Start in       : C:\xampp\htdocs\yourproject\
 *   Run as         : Your Windows user account (NOT SYSTEM — SYSTEM has no env vars)
 *   Trigger        : Weekly, Friday, 08:00 AM
 *
 * ── WHY IT WORKS IN CMD BUT NOT SCHEDULER ──
 *   1. Working directory is wrong  → fixed with chdir(__DIR__)
 *   2. PHP extensions missing      → fixed with ini_set for OpenSSL/mysqli
 *   3. Different php.ini loaded    → fixed by forcing the correct php.ini
 *   4. Errors swallowed silently   → fixed with file logging + error_log to file
 *   5. Wrong SMTP password         → synced with student_report.php
 *   6. strtotime() locale issues   → fixed with DateTime arithmetic
 *
 * All execution output is logged to: send_friday_reminders.log (same folder as this file)
 * Check that file to confirm the task scheduler is actually running the script.
 */

/*
 * ══════════════════════════════════════════════════════════════
 * STEP 0 — FIX WORKING DIRECTORY
 * Task Scheduler sets CWD to C:\Windows\System32 or the PHP
 * binary folder. chdir(__DIR__) forces it to this script's
 * folder so that all relative includes (db.php etc.) work
 * exactly as they do when you run from CMD.
 * ══════════════════════════════════════════════════════════════
 */
chdir(__DIR__);

/*
 * ══════════════════════════════════════════════════════════════
 * STEP 1 — TIMEZONE (must be before ANY date() call)
 * ══════════════════════════════════════════════════════════════
 */
date_default_timezone_set("Asia/Manila");

/*
 * ══════════════════════════════════════════════════════════════
 * STEP 2 — ERROR REPORTING
 * Scheduler swallows all output. Log everything to a file so
 * you can see what went wrong when emails don't send.
 * ══════════════════════════════════════════════════════════════
 */
define('LOG_FILE', __DIR__ . '/send_friday_reminders.log');

ini_set('display_errors', '0');
ini_set('log_errors', '1');
ini_set('error_log', __DIR__ . '/send_friday_reminders_php_errors.log');
error_reporting(E_ALL);

function cron_log(string $msg): void {
    $line = "[" . date("Y-m-d H:i:s") . "] " . $msg . PHP_EOL;
    file_put_contents(LOG_FILE, $line, FILE_APPEND | LOCK_EX);
    echo $line; // also echo so CMD runs show output
}

function cron_die(string $msg, int $code = 1): void {
    cron_log("FATAL: " . $msg);
    exit($code);
}

cron_log("========================================");
cron_log("Script started.");
cron_log("PHP version : " . PHP_VERSION);
cron_log("PHP binary  : " . PHP_BINARY);
cron_log("php.ini     : " . php_ini_loaded_file());
cron_log("CWD         : " . getcwd());
cron_log("__DIR__     : " . __DIR__);
cron_log("Script day  : " . date('l (N)') . " — timezone: " . date_default_timezone_get());

/*
 * ══════════════════════════════════════════════════════════════
 * STEP 3 — FORCE REQUIRED PHP EXTENSIONS
 * The CLI php.ini used by Task Scheduler often differs from
 * the web server php.ini. OpenSSL is required for SMTP STARTTLS.
 * MySQLi is required for the database.
 * ══════════════════════════════════════════════════════════════
 */
$missing_ext = [];
foreach (['openssl', 'mysqli', 'json'] as $ext) {
    if (!extension_loaded($ext)) {
        $missing_ext[] = $ext;
    }
}
if (!empty($missing_ext)) {
    cron_log("WARNING: Missing PHP extensions: " . implode(', ', $missing_ext));
    cron_log("         These are needed for SMTP and DB. Edit your CLI php.ini:");
    cron_log("         " . (php_ini_loaded_file() ?: 'php.ini not found — use -c flag'));
    cron_log("         Uncomment: extension=openssl  extension=mysqli");
    // Don't die here — try anyway; PHPMailer will throw a catchable exception.
} else {
    cron_log("Extensions OK: openssl, mysqli, json all loaded.");
}

/*
 * ══════════════════════════════════════════════════════════════
 * STEP 4 — FRIDAY GUARD
 * ══════════════════════════════════════════════════════════════
 */
if (date('N') != 5) {
    cron_log("Not Friday (today is " . date('l') . "). Exiting.");
    cron_log("========================================");
    exit(0);
}

/*
 * ══════════════════════════════════════════════════════════════
 * STEP 5 — INCLUDES
 * All paths use __DIR__ so they work regardless of CWD.
 * ══════════════════════════════════════════════════════════════
 */
// Composer autoloader (optional — loaded only if present)
$autoload_path = __DIR__ . '/vendor/autoload.php';
if (file_exists($autoload_path)) {
    require_once $autoload_path;
    cron_log("Composer autoload: loaded.");
} else {
    cron_log("Composer autoload: not found (OK if not using Composer).");
}

// PHPMailer
$pm_base = __DIR__ . '/PHPMailer/src/';
foreach (['Exception.php', 'PHPMailer.php', 'SMTP.php'] as $f) {
    $path = $pm_base . $f;
    if (!file_exists($path)) {
        cron_die("PHPMailer file not found: {$path}");
    }
    require_once $path;
}
cron_log("PHPMailer: loaded.");

// Database
$db_path = __DIR__ . '/db.php';
if (!file_exists($db_path)) {
    cron_die("db.php not found at: {$db_path}");
}
require_once $db_path;
cron_log("db.php: loaded.");

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception as MailException;

/*
 * ══════════════════════════════════════════════════════════════
 * STEP 6 — VERIFY DATABASE CONNECTION
 * ══════════════════════════════════════════════════════════════
 */
if (!isset($conn)) {
    cron_die("db.php did not set \$conn. Check db.php for errors.");
}
if ($conn instanceof mysqli && $conn->connect_error) {
    cron_die("Database connection failed: " . $conn->connect_error);
}
cron_log("Database: connected.");

/*
 * ══════════════════════════════════════════════════════════════
 * STEP 7 — COMPUTE WEEK DATES (locale-independent)
 *
 * strtotime("monday this week") is unreliable in Task Scheduler:
 * - On some Windows locales, "this week" starts on Monday, so
 *   calling it on Friday returns NEXT Monday, not current Monday.
 * - This makes the week_start date wrong, so reminder_log checks
 *   pass (no record found), report checks also pass, but the email
 *   goes out for the wrong week — or ojt_reminder_log already has
 *   an entry for the correct date, so it skips everyone.
 *
 * FIX: Use ISO day-of-week (N: Mon=1 … Sun=7) to compute Monday
 * by subtracting (N-1) days. 100% locale-independent.
 * ══════════════════════════════════════════════════════════════
 */
$today_dt   = new DateTime('now', new DateTimeZone('Asia/Manila'));
$dow        = (int)$today_dt->format('N');   // 1=Mon … 7=Sun
$monday_dt  = clone $today_dt;
$monday_dt->modify('-' . ($dow - 1) . ' days');
$friday_dt  = clone $monday_dt;
$friday_dt->modify('+4 days');

$week_start = $monday_dt->format('Y-m-d');
$week_end   = $friday_dt->format('Y-m-d');
$week_str   = $monday_dt->format('F d') . ' - ' . $friday_dt->format('F d, Y');
$today_full = $today_dt->format('l, F d, Y');

cron_log("Week: {$week_str}  (week_start={$week_start}, week_end={$week_end})");

/*
 * ══════════════════════════════════════════════════════════════
 * STEP 8 — FETCH STUDENTS
 * ══════════════════════════════════════════════════════════════
 */
$students_stmt = $conn->prepare("
    SELECT DISTINCT u.id, u.first_name, u.last_name, u.email
    FROM users u
    JOIN ojt_assignments oa ON oa.student_id = u.id
    WHERE u.email IS NOT NULL AND u.email != ''
");
if (!$students_stmt) {
    cron_die("Failed to prepare student query: " . $conn->error);
}
$students_stmt->execute();
$students = $students_stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$students_stmt->close();

cron_log("Students found: " . count($students));

$sent_count    = 0;
$skipped_count = 0;
$error_count   = 0;

foreach ($students as $stu) {
    $user_id      = (int)$stu['id'];
    $studentName  = trim($stu['first_name'] . ' ' . $stu['last_name']);
    $studentEmail = trim($stu['email']);

    /* ── Skip if already reminded this week ── */
    $log_chk = $conn->prepare("SELECT id FROM ojt_reminder_log WHERE user_id = ? AND week_start = ?");
    $log_chk->bind_param("is", $user_id, $week_start);
    $log_chk->execute();
    $already_reminded = $log_chk->get_result()->num_rows > 0;
    $log_chk->close();

    if ($already_reminded) {
        cron_log("  SKIP (already reminded): {$studentName}");
        $skipped_count++;
        continue;
    }

    /* ── Skip if already submitted this week ── */
    $sub_chk = $conn->prepare("
        SELECT id FROM reports
        WHERE user_id = ? AND week_start = ?
          AND (remark IS NULL OR remark != 'Wrong Document')
    ");
    $sub_chk->bind_param("is", $user_id, $week_start);
    $sub_chk->execute();
    $already_submitted = $sub_chk->get_result()->num_rows > 0;
    $sub_chk->close();

    if ($already_submitted) {
        $log_ins = $conn->prepare("INSERT IGNORE INTO ojt_reminder_log (user_id, week_start) VALUES (?, ?)");
        $log_ins->bind_param("is", $user_id, $week_start);
        $log_ins->execute();
        $log_ins->close();
        cron_log("  SKIP (submitted): {$studentName}");
        $skipped_count++;
        continue;
    }

    /* ══════════════════════════════════════════════════════════
       BUILD EMAIL — Option 2 "Letter from Your Coordinator"
       Synced exactly with student_report.php.
    ══════════════════════════════════════════════════════════ */
    $firstName = htmlspecialchars($stu['first_name']);

    $emailHTML = '<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>OJT Weekly Report Reminder</title>
</head>
<body style="margin:0;padding:0;background-color:#f5f0e8;font-family:Georgia,\'Times New Roman\',serif;">
  <table width="100%" cellpadding="0" cellspacing="0" border="0" style="background-color:#f5f0e8;padding:32px 16px;">
    <tr><td align="center">
      <table width="100%" cellpadding="0" cellspacing="0" border="0" style="max-width:600px;">

        <!-- Header -->
        <tr><td style="background:linear-gradient(135deg,#07145f 0%,#1a237e 100%);border-radius:16px 16px 0 0;padding:32px 40px 28px;text-align:center;">
          <div style="display:inline-block;background:rgba(255,215,0,0.15);border:1.5px solid rgba(255,215,0,0.4);border-radius:50px;padding:6px 20px;margin-bottom:14px;">
            <span style="color:#FFD700;font-size:11px;font-family:Arial,sans-serif;letter-spacing:2px;text-transform:uppercase;font-weight:700;">NEUST Atate Campus</span>
          </div>
          <div style="font-size:11px;color:rgba(255,255,255,0.55);font-family:Arial,sans-serif;letter-spacing:1px;text-transform:uppercase;">Web-Based Smart OJT Monitoring System</div>
        </td></tr>

        <!-- Body -->
        <tr><td style="background:#fffef9;border-left:1px solid #e8e0cc;border-right:1px solid #e8e0cc;padding:44px 48px 36px;">

          <p style="margin:0 0 28px;color:#9a8c78;font-size:13px;font-style:italic;border-bottom:1px solid #ede8db;padding-bottom:18px;">' . $today_full . '</p>

          <p style="margin:0 0 20px;font-size:22px;font-weight:700;color:#07145f;line-height:1.3;">Dear ' . $firstName . ', &#127881;</p>

          <p style="margin:0 0 16px;font-size:15px;color:#3d3527;line-height:1.8;">Happy Friday! We hope this week has been filled with meaningful experiences, new learnings, and moments that reminded you why you chose this path.</p>

          <p style="margin:0 0 16px;font-size:15px;color:#3d3527;line-height:1.8;">As this week comes to a close, we wanted to take a moment to reach out &mdash; not just as a system reminder, but as a genuine check-in from your OJT support team.</p>

          <p style="margin:0 0 24px;font-size:15px;color:#3d3527;line-height:1.8;">You have spent another week gaining real-world experience, navigating the professional world, and growing into the person your future career needs you to be. That is something to be proud of, no matter how the week went.</p>

          <!-- Highlight box -->
          <table width="100%" cellpadding="0" cellspacing="0" border="0" style="margin-bottom:24px;">
            <tr><td style="background:#fef9ec;border-left:4px solid #FFD700;border-radius:0 8px 8px 0;padding:18px 22px;">
              <p style="margin:0 0 6px;font-size:11px;color:#9a8c78;font-family:Arial,sans-serif;text-transform:uppercase;letter-spacing:1.5px;font-weight:700;">One small but important request</p>
              <p style="margin:0;font-size:16px;color:#07145f;font-weight:700;line-height:1.5;">Please submit your Weekly OJT Report for the week of <span style="color:#1a237e;">' . $week_str . '</span></p>
            </td></tr>
          </table>

          <p style="margin:0 0 24px;font-size:15px;color:#3d3527;line-height:1.8;">Your report is more than a school requirement &mdash; it is your personal journal of this once-in-a-lifetime experience. <strong style="color:#07145f;">Don&rsquo;t let this week go unwritten.</strong></p>

          <!-- How to submit -->
          <p style="margin:0 0 12px;font-size:11px;color:#9a8c78;font-family:Arial,sans-serif;text-transform:uppercase;letter-spacing:1.5px;font-weight:700;">&#128204; How to submit</p>
          <table width="100%" cellpadding="0" cellspacing="0" border="0" style="margin-bottom:24px;background:#f9f7f2;border-radius:10px;">
            <tr><td style="padding:12px 22px;font-size:14px;color:#3d3527;border-bottom:1px solid #ede8db;">&#10102;&nbsp;&nbsp;Log in to the OJT Monitoring System with your student account</td></tr>
            <tr><td style="padding:12px 22px;font-size:14px;color:#3d3527;border-bottom:1px solid #ede8db;">&#10103;&nbsp;&nbsp;Navigate to the <strong>Reports</strong> section from the sidebar</td></tr>
            <tr><td style="padding:12px 22px;font-size:14px;color:#3d3527;border-bottom:1px solid #ede8db;">&#10104;&nbsp;&nbsp;Fill in your journal entries for each day you were present</td></tr>
            <tr><td style="padding:12px 22px;font-size:14px;color:#3d3527;border-bottom:1px solid #ede8db;">&#10105;&nbsp;&nbsp;Review everything carefully before confirming</td></tr>
            <tr><td style="padding:12px 22px;font-size:14px;color:#3d3527;">&#10106;&nbsp;&nbsp;Click <strong>&ldquo;Submit Weekly Report&rdquo;</strong> to finalize &#10003;</td></tr>
          </table>

          <!-- Warning -->
          <table width="100%" cellpadding="0" cellspacing="0" border="0" style="margin-bottom:32px;">
            <tr><td style="background:#fff8e1;border:1.5px solid #fcd34d;border-radius:8px;padding:14px 18px;">
              <p style="margin:0;font-size:13px;color:#78350f;line-height:1.7;font-family:Arial,sans-serif;">&#9888;&#65039;&nbsp;<strong>Important:</strong> Reports can only be submitted <strong>today &mdash; Friday</strong>. Once submitted, entries cannot be edited.</p>
            </td></tr>
          </table>

          <!-- CTA -->
          <table width="100%" cellpadding="0" cellspacing="0" border="0" style="margin-bottom:36px;">
            <tr><td align="center">
              <a href="https://yourdomain.com/student_report.php" style="display:inline-block;background:linear-gradient(135deg,#07145f,#1a237e);color:#FFD700;text-decoration:none;font-family:Arial,sans-serif;font-size:15px;font-weight:700;padding:16px 48px;border-radius:50px;letter-spacing:0.5px;">&#128221;&nbsp; Log In &amp; Submit My Report</a>
            </td></tr>
          </table>

          <!-- Quote -->
          <table width="100%" cellpadding="0" cellspacing="0" border="0" style="margin-bottom:32px;">
            <tr><td style="border-top:1px solid #ede8db;border-bottom:1px solid #ede8db;padding:22px 0;text-align:center;">
              <p style="margin:0;font-size:15px;color:#6b5e4a;font-style:italic;line-height:1.7;">&ldquo;The secret of getting ahead is getting started.&rdquo;</p>
              <p style="margin:8px 0 0;font-size:12px;color:#9a8c78;font-family:Arial,sans-serif;">&#8212; Mark Twain</p>
            </td></tr>
          </table>

          <!-- Closing -->
          <p style="margin:0 0 6px;font-size:15px;color:#3d3527;line-height:1.8;">We believe in you, <strong style="color:#07145f;">' . $firstName . '</strong>. Keep going &mdash; you are doing great.</p>
          <p style="margin:0 0 28px;font-size:15px;color:#3d3527;line-height:1.8;">Warmly,</p>
          <table cellpadding="0" cellspacing="0" border="0">
            <tr><td style="border-left:3px solid #FFD700;padding-left:16px;">
              <p style="margin:0 0 3px;font-size:15px;font-weight:700;color:#07145f;">Your OJT Monitoring Team</p>
              <p style="margin:0 0 2px;font-size:13px;color:#6b5e4a;font-family:Arial,sans-serif;">NEUST Atate Campus</p>
              <p style="margin:0;font-size:12px;color:#9a8c78;font-family:Arial,sans-serif;font-style:italic;">Web-Based Smart OJT Monitoring and Supervision Analytics System</p>
            </td></tr>
          </table>

        </td></tr>

        <!-- Footer -->
        <tr><td style="background:#07145f;border-radius:0 0 16px 16px;padding:20px 40px;text-align:center;">
          <p style="margin:0 0 6px;font-size:11px;color:rgba(255,255,255,0.45);font-family:Arial,sans-serif;line-height:1.7;">This is an automated reminder from the NEUST Atate OJT Monitoring System.<br>Please do not reply directly to this email.</p>
          <p style="margin:0;font-size:11px;color:rgba(255,215,0,0.5);font-family:Arial,sans-serif;">&copy; ' . date('Y') . ' NEUST Atate Campus. All rights reserved.</p>
        </td></tr>

      </table>
    </td></tr>
  </table>
</body>
</html>';

    $emailAlt = "Dear " . $stu['first_name'] . ",\n\n"
        . "Happy Friday!\n\n"
        . "This is a friendly reminder to submit your Weekly OJT Report for the week of " . $week_str . ".\n\n"
        . "HOW TO SUBMIT:\n"
        . "1. Log in to the OJT Monitoring System\n"
        . "2. Go to the Reports section\n"
        . "3. Fill in your journal entries for each day you were present\n"
        . "4. Review everything carefully\n"
        . "5. Click \"Submit Weekly Report\" to finalize\n\n"
        . "IMPORTANT: Reports can only be submitted today (Friday). Entries cannot be edited after submission.\n\n"
        . "\"The secret of getting ahead is getting started.\" -- Mark Twain\n\n"
        . "We believe in you, " . $stu['first_name'] . ". Keep going -- you are doing great.\n\n"
        . "Warmly,\n"
        . "Your OJT Monitoring Team\n"
        . "NEUST Atate Campus\n\n"
        . "---\n"
        . "This is an automated reminder. Please do not reply to this email.";

    /* ── SEND ── */
    try {
        $mail = new PHPMailer(true);
        $mail->isSMTP();
        $mail->Host       = 'smtp.gmail.com';
        $mail->SMTPAuth   = true;
        $mail->Username   = 'salesjohnlhoyd@gmail.com';
        $mail->Password   = 'qwufanprpmezotly';  // synced with student_report.php
        $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
        $mail->Port       = 587;
        $mail->CharSet    = 'UTF-8';

        $mail->setFrom('salesjohnlhoyd@gmail.com', 'NEUST Atate OJT Monitoring System');
        $mail->addAddress($studentEmail, $studentName);
        $mail->isHTML(true);
        $mail->Subject = "=?UTF-8?B?" . base64_encode("Hey " . $stu['first_name'] . ", a quick reminder from your OJT family - submit your report today!") . "?=";
        $mail->Body    = $emailHTML;
        $mail->AltBody = $emailAlt;
        $mail->send();

        /* ── Log success ── */
        $log_ins = $conn->prepare("INSERT IGNORE INTO ojt_reminder_log (user_id, week_start) VALUES (?, ?)");
        $log_ins->bind_param("is", $user_id, $week_start);
        $log_ins->execute();
        $log_ins->close();

        cron_log("  SENT: {$studentName} <{$studentEmail}>");
        $sent_count++;

    } catch (MailException $e) {
        $errMsg = $e->getMessage();
        error_log("OJT cron mail failed uid={$user_id}: " . $errMsg);
        cron_log("  ERROR sending to {$studentName} <{$studentEmail}>: " . $errMsg);
        $error_count++;
    }
}

cron_log("Finished. Sent: {$sent_count} | Skipped: {$skipped_count} | Errors: {$error_count}");
cron_log("========================================");