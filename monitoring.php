<?php

// ── CLEAN-UP (project audit): ONE definition of the `email_recovery_requests` table. It used to be written out 4 times in
//    this file (1 different version(s)). CREATE TABLE IF NOT EXISTS only acts once, so whichever copy ran
//    first decided the columns; every former copy now calls this complete definition instead. ──
function cv_ensure_email_recovery_requests_table($conn) {
    $conn->query("CREATE TABLE IF NOT EXISTS email_recovery_requests ( id INT AUTO_INCREMENT PRIMARY KEY, account_type VARCHAR(20), first_name VARCHAR(100), middle_name VARCHAR(100), last_name VARCHAR(100), old_email VARCHAR(200), new_email VARCHAR(200), reason TEXT, extra_info VARCHAR(300), selfie_blob MEDIUMBLOB, status VARCHAR(20) DEFAULT 'Pending', submitted_at DATETIME DEFAULT CURRENT_TIMESTAMP )");
}

session_start();
// ── NEW (this adjustment): LOGOUT + BACK BUTTON — never let the browser keep a copy of this page.
// After the admin logs out, pressing the browser's Back arrow used to show this page again from the
// browser's memory. With these headers the browser always asks the server again, and the server
// (the session check below) sends a logged-out visitor to admin_login.php to sign in again.
// Only applied to the page itself (a top-level page load), not to files / images / AJAX it serves.
if (($_SERVER['HTTP_SEC_FETCH_DEST'] ?? 'document') === 'document' && !headers_sent()) {
    header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
    header('Pragma: no-cache');
    header('Expires: Sat, 01 Jan 2000 00:00:00 GMT');
}

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

require 'PHPMailer/src/PHPMailer.php';
require 'PHPMailer/src/SMTP.php';
require 'PHPMailer/src/Exception.php';

include "db.php";
if (
    (!isset($_SESSION['admin_id']) && !isset($_SESSION['user_id'])) ||
    ($_SESSION['role'] ?? '') !== "admin"
) {
    header("Location: admin_login.php");
    exit;
}

// ============================================================================
// NEW (this adjustment): NO NOTIFICATIONS LEFT BEHIND BY DELETED ACCOUNTS
// ----------------------------------------------------------------------------
// When a student or company account is deleted (Student List, Company List or
// Manage Accounts), its notifications must disappear from the inboxes of
// administrator.php / company_validation.php and from every side-menu
// indicator. The company delete paths now remove them directly; this sweep also
// clears any that were already left behind (or come from any other path). It
// removes ONLY notification rows whose account no longer exists in `users`:
//   • company_requirement_upload_notifications  (Company Requirements inbox + badge)
//   • admin_application_approvals              (Application Requests inbox + badge)
//   • moa_requests                             (MOA notifications — rows tied to an account)
//   • email_recovery_requests, Pending only    (Manage Accounts indicator)
// Archiving never deletes an account, so archived students / companies are never
// touched. Runs at most once every 15 seconds per admin; fully guarded.
// ============================================================================
if (!function_exists('cv_sweep_orphan_notifications')) {
    function cv_sweep_orphan_notifications($conn) {
        $has = function ($t) use ($conn) {
            try { $r = $conn->query("SHOW TABLES LIKE '" . $conn->real_escape_string($t) . "'"); return $r && $r->num_rows > 0; }
            catch (\Throwable $e) { return false; }
        };
        $run = function ($sql) use ($conn) { try { $conn->query($sql); } catch (\Throwable $e) { /* never affects the page */ } };
        if (!$has('users')) return;
        if ($has('company_requirement_upload_notifications'))
            $run("DELETE n FROM company_requirement_upload_notifications n LEFT JOIN users u ON u.id = n.user_id WHERE u.id IS NULL");
        if ($has('admin_application_approvals'))
            $run("DELETE a FROM admin_application_approvals a LEFT JOIN users s ON s.id = a.student_id LEFT JOIN users c ON c.id = a.company_id WHERE s.id IS NULL OR c.id IS NULL");
        if ($has('moa_requests'))
            $run("DELETE m FROM moa_requests m LEFT JOIN users u ON u.id = m.user_id WHERE m.user_id > 0 AND u.id IS NULL");
        if ($has('email_recovery_requests'))
            $run("DELETE r FROM email_recovery_requests r WHERE r.status = 'Pending' AND NOT EXISTS (SELECT 1 FROM users u WHERE u.email = r.old_email OR u.email = r.new_email)");
    }
}
try {
    $cvSweepNow = time();
    if (!isset($_SESSION['cv_orphan_sweep_at']) || $cvSweepNow - (int)$_SESSION['cv_orphan_sweep_at'] >= 15) {
        $_SESSION['cv_orphan_sweep_at'] = $cvSweepNow;
        cv_sweep_orphan_notifications($conn);
    }
} catch (\Throwable $e) { /* never affects the page */ }

// ── NEW (this adjustment): EMAIL RECOVERY REQUESTS side-menu indicator ─────────
// Number of Pending rows in email_recovery_requests (the requests handled in
// Manage Accounts > Email Recovery Requests on monitoring.php). Shown as a badge
// on the "Manage Accounts" link, the same way the Student Requirements link
// shows the application-request count. Read-only and fully guarded — if the
// table does not exist yet it is simply 0.
$recovery_pending_count = 0;
try {
    $rpTbl = $conn->query("SHOW TABLES LIKE 'email_recovery_requests'");
    if ($rpTbl && $rpTbl->num_rows > 0) {
        // FIX (fresh requests landing in History): a new request is Pending whether its
        // status is saved as 'Pending', 'pending', blank or NULL.
        $rpRes = $conn->query("SELECT COUNT(*) AS total FROM email_recovery_requests WHERE (status IS NULL OR TRIM(status) = '' OR LOWER(TRIM(status)) = 'pending')");
        if ($rpRes) $recovery_pending_count = (int)($rpRes->fetch_assoc()['total'] ?? 0);
    }
} catch (\Throwable $e) { $recovery_pending_count = 0; }

// ============================================================================
// NEW (this adjustment): ACTIVITY LOG — this page's actions are recorded in
// the Live Activity Log on monitoring.php (the same `activity_logs` table and
// the same columns monitoring.php already writes: action_type, performed_by,
// account_type, account_name, details, created_at).
// ----------------------------------------------------------------------------
// • Only actions that CHANGE something are recorded (see the rules list for
//   this page below); look-ups, polling, previews and exports never are.
// • The name of the student / company / item is read BEFORE the action runs
//   (so it is still available after a delete).
// • The entry is written only if the action actually SUCCEEDED: a JSON reply
//   with success:false, an error message / "…_message_type = error", an
//   error redirect or an HTTP error status means nothing is logged.
// • Account deletions on the Student / Company List were already logged by
//   those pages themselves and are not logged a second time.
// • Fully guarded: a logging problem can never break or change the action.
// ============================================================================
if (!function_exists('cv_alog_capture')) {
    function cv_alog_post($k) { return trim((string)($_POST[$k] ?? '')); }
    function cv_alog_is_post($k) { return ($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && isset($_POST[$k]); }
    function cv_alog_is_get_post($getKey) { return ($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && isset($_GET[$getKey]) && (string)$_GET[$getKey] === '1'; }
    function cv_alog_join_name($f, $m, $l) { return preg_replace('/\s+/', ' ', trim(trim((string)$f) . ' ' . trim((string)$m) . ' ' . trim((string)$l))); }
    function cv_alog_scalar($conn, $sql, $types = '', $params = []) {
        try {
            $st = $conn->prepare($sql);
            if (!$st) return null;
            if ($types !== '') $st->bind_param($types, ...$params);
            $st->execute();
            $res = $st->get_result();
            $row = $res ? $res->fetch_row() : null;
            $st->close();
            return $row ? $row[0] : null;
        } catch (\Throwable $e) { return null; }
    }
    function cv_alog_user_name($conn, $id) {
        $id = (int)$id; if ($id <= 0) return '';
        try {
            $st = $conn->prepare("SELECT first_name, middle_name, last_name FROM users WHERE id = ?");
            if (!$st) return '';
            $st->bind_param('i', $id); $st->execute();
            $r = $st->get_result()->fetch_assoc(); $st->close();
            return $r ? cv_alog_join_name($r['first_name'] ?? '', $r['middle_name'] ?? '', $r['last_name'] ?? '') : '';
        } catch (\Throwable $e) { return ''; }
    }
    function cv_alog_company_name($conn, $userId) {
        $n = (string)cv_alog_scalar($conn, "SELECT company FROM company_information WHERE user_id = ? LIMIT 1", 'i', [(int)$userId]);
        return $n !== '' ? $n : cv_alog_user_name($conn, $userId);
    }
    function cv_alog_list($names, $max = 3) {
        $names = array_values(array_filter(array_map('strval', $names), 'strlen'));
        if (!$names) return '';
        $shown = array_slice($names, 0, $max);
        $more = count($names) - count($shown);
        return implode(', ', $shown) . ($more > 0 ? " and $more more" : '');
    }
    function cv_alog_plural($n, $one, $many) { return $n . ' ' . ($n == 1 ? $one : $many); }
    function cv_alog_status_word($status) {
        $s = strtolower(trim((string)$status));
        $map = ['verified' => 'Verified', 'approved' => 'Approved', 'rejected' => 'Rejected', 'pending' => 'Set to Pending', 'complied' => 'Complied'];
        return $map[$s] ?? ($s !== '' ? ucwords($s ?? '') : 'Updated');
    }
    function cv_alog_performer($conn) {
        $n = trim((string)($GLOBALS['adminFullName'] ?? ''));
        if ($n !== '' && strcasecmp($n, 'Administrator') !== 0) return $n;
        $n = trim(($_SESSION['first_name'] ?? '') . ' ' . ($_SESSION['last_name'] ?? ''));
        $aid = (int)($_SESSION['admin_id'] ?? $_SESSION['user_id'] ?? 0);
        if ($aid > 0) {
            try {
                $st = $conn->prepare("SELECT first_name, middle_name, last_name FROM admins WHERE id = ?");
                if ($st) {
                    $st->bind_param('i', $aid); $st->execute();
                    $r = $st->get_result()->fetch_assoc(); $st->close();
                    if ($r) { $full = cv_alog_join_name($r['first_name'] ?? '', $r['middle_name'] ?? '', $r['last_name'] ?? ''); if ($full !== '') return $full; }
                }
            } catch (\Throwable $e) {}
        }
        return $n !== '' ? $n : 'Admin';
    }
    // Decide whether the finished request succeeded, from what it sent back.
    function cv_alog_succeeded($content, &$json) {
        $json = null;
        $code = function_exists('http_response_code') ? (int)http_response_code() : 200;
        if ($code >= 400) return false;
        foreach (headers_list() as $h) {
            if (stripos($h, 'Location:') === 0 && preg_match('/[?&](error|err|fail|failed|invalid)[^&]*=/i', $h)) return false;
        }
        $body = ltrim((string)$content, "\xEF\xBB\xBF \t\r\n");
        if ($body !== '' && ($body[0] === '{' || $body[0] === '[')) {
            $j = json_decode($body, true);
            if (is_array($j)) {
                $json = $j;
                if (array_key_exists('success', $j)) return (bool)$j['success'];
                if (array_key_exists('ok', $j)) return (bool)$j['ok'];
                if (!empty($j['error'])) return false;
                return true;
            }
        }
        if ($body !== '' && strlen($body) < 200 && preg_match('/^(invalid|error|fail|unauthori|forbidden|not allowed)/i', $body)) return false;
        foreach ($GLOBALS as $k => $v) {
            if (is_string($k) && substr($k, -13) === '_message_type' && $v === 'error') return false;
        }
        if (!empty($GLOBALS['error']) && is_string($GLOBALS['error'])) return false;
        return true;
    }
    function cv_alog_cut($s, $n) { $s = (string)$s; return function_exists('mb_substr') ? mb_substr($s, 0, $n) : substr($s, 0, $n); }
    function cv_alog_write($conn, $rec, $performer) {
        $st = $conn->prepare("INSERT INTO activity_logs (action_type, performed_by, account_type, account_name, details, created_at) VALUES (?, ?, ?, ?, ?, NOW())");
        if (!$st) return;
        $type = cv_alog_cut($rec[0], 100); $acct = cv_alog_cut($rec[1], 100);
        $name = cv_alog_cut($rec[2], 255); $det  = cv_alog_cut($rec[3], 1000); $performer = cv_alog_cut($performer, 255);
        $st->bind_param('sssss', $type, $performer, $acct, $name, $det);
        $st->execute(); $st->close();
    }
    // $rules: list of [matcher(): bool, builder($conn): ?[action_type, account_type, account_name, details], optional after($rec, $json): ?array]
    function cv_alog_capture($conn, $rules) {
        try {
            foreach ($rules as $rule) {
                if (!call_user_func($rule[0])) continue;
                $rec = call_user_func($rule[1], $conn);
                if (!is_array($rec)) return;
                $after = $rule[2] ?? null;
                ob_start();
                $level = ob_get_level();
                register_shutdown_function(function () use ($rec, $after, $level) {
                    try {
                        $content = null;
                        if (ob_get_level() >= $level) {
                            while (ob_get_level() > $level) ob_end_flush();   // same output, same order — just collected into this buffer first
                            $content = ob_get_contents();
                        }
                        $json = null;
                        if (!cv_alog_succeeded((string)$content, $json)) return;
                        if ($after) { $rec = call_user_func($after, $rec, $json); if (!is_array($rec)) return; }
                        $c = $GLOBALS['conn'] ?? null; $alive = false;
                        try { $alive = ($c instanceof \mysqli) && $c->query('SELECT 1'); } catch (\Throwable $e) { $alive = false; }
                        if (!$alive) { $c = (function () { $conn = null; include __DIR__ . '/db.php'; return $conn; })(); }
                        if (!($c instanceof \mysqli)) return;
                        cv_alog_write($c, $rec, cv_alog_performer($c));
                    } catch (\Throwable $e) { /* logging must never affect the page */ }
                });
                return;
            }
        } catch (\Throwable $e) { /* logging must never affect the page */ }
    }
}

// (monitoring.php already logs its own account actions itself; only the one it didn't log is added here)
cv_alog_capture($conn, [
    [function () { return cv_alog_is_post('ajax_clear_recovery_history'); }, function ($conn) {
        return ['Recovery History Cleared', 'Email Recovery', '—', "Cleared the email recovery request history via Manage Accounts"];
    }],
]);
// ── OPTIONAL: local environment variables (SMTP credentials, etc.) ──
// This file is intentionally NOT part of version control (see
// .gitignore) and will not exist on a fresh checkout or on a
// different machine/host. That's fine — sendSystemEmail() already
// falls back to safe defaults via getenv(...) ?: 'fallback' if these
// variables were never set, so loading this conditionally here can
// never break the app. This just lets local development (e.g. XAMPP)
// set SMTP_USERNAME / SMTP_PASSWORD without hardcoding real
// credentials directly into this file.
$__envLocalFile = __DIR__ . '/config/env.local.php';
if (file_exists($__envLocalFile)) {
    require_once $__envLocalFile;
}
unset($__envLocalFile);

// ============================================
// NEW: Admin school-info extra columns (lazy migration)
// ------------------------------------------------------------
// The "Create Admin Account" form now also collects School (campus
// branch), School Address, Subject, Contact No., Required No. of
// hours, and College — used later by company_reports.php to
// auto-fill the OJT/Internship Training Plan's School information
// section instead of leaving it blank for the company rep to type.
// These columns are added lazily (same pattern already used
// elsewhere in this codebase, e.g. ensureEvalRatingColumns() in
// company_reports.php) so no separate manual migration is needed.
//
// ── UPDATED: also lazily adds an `is_active` column so that multiple
// admin accounts can now coexist, each independently activated or
// deactivated (a deactivated admin cannot log in — see login.php).
// Defaults to 1 (active) so existing admin rows are unaffected. ──
// ============================================
function ensureAdminExtraColumns(mysqli $conn): void {
    static $checked = false;
    if ($checked) return;
    $checked = true;

    $needed_columns = [
        'school'         => "VARCHAR(255) NULL",
        'school_address' => "VARCHAR(300) NULL",
        'subject'        => "VARCHAR(150) NULL",
        'contact_no'     => "VARCHAR(50)  NULL",
        'required_hours' => "VARCHAR(50)  NULL",
        'college'        => "VARCHAR(150) NULL",
        'is_active'      => "TINYINT(1) NOT NULL DEFAULT 1",
    ];
    foreach ($needed_columns as $col_name => $col_def) {
        $col_check = $conn->query("SHOW COLUMNS FROM admins LIKE '{$col_name}'");
        if ($col_check && $col_check->num_rows === 0) {
            $conn->query("ALTER TABLE admins ADD COLUMN `{$col_name}` {$col_def}");
        }
    }
}
ensureAdminExtraColumns($conn);

// ============================================
// NEW (BUG FIX): Defensive lazy migration — widen `password` columns
// ------------------------------------------------------------
// Reported issue: a brand-new Admin account created from this page
// could not log in on login.php ("Invalid Password") even though the
// temporary password shown/emailed was correct.
//
// Two things were found and fixed for this:
//   1. login.php was not trim()-ing the submitted password (only the
//      email was trimmed), so an invisible leading/trailing space or
//      newline picked up when copy-pasting the password out of the
//      HTML "Account Created" email could make password_verify()
//      fail. That is fixed in login.php.
//   2. As a second, purely defensive safety net (in case an older
//      deployment's `password` column was originally sized for short
//      plaintext values), this widens `password` to VARCHAR(255) on
//      every account table if it isn't already wide enough to safely
//      hold a full password_hash() bcrypt string (60 characters). A
//      too-narrow column would silently truncate the hash on INSERT
//      (unless strict SQL mode is enabled), so the hash actually
//      saved to the DB would never match the one password_verify()
//      re-derives from the correct plaintext password.
// This is a MODIFY (not an ADD), so it's safe to run repeatedly and
// does not touch any existing data — only the column's maximum
// length — and it mirrors the same lazy/defensive ALTER TABLE pattern
// already used throughout this file (ensureAdminExtraColumns,
// ensureStatusColumn, ensureCompanyNameColumn, etc.).
// ============================================
function ensurePasswordColumnWidth(mysqli $conn): void {
    static $checked = false;
    if ($checked) return;
    $checked = true;

    foreach (['admins', 'users', 'faculty'] as $tbl) {
        $tblCheck = $conn->query("SHOW TABLES LIKE '{$tbl}'");
        if (!$tblCheck || $tblCheck->num_rows === 0) continue;

        $colCheck = $conn->query("SHOW COLUMNS FROM `{$tbl}` LIKE 'password'");
        if (!$colCheck || $colCheck->num_rows === 0) continue;
        $colInfo = $colCheck->fetch_assoc();

        // Only widen if the current type isn't already big enough —
        // avoids running an unnecessary ALTER on every single request.
        if (stripos($colInfo['Type'], 'varchar(255)') !== false) continue;

        // Suppressed (@) and best-effort: if this particular deployment's
        // MySQL user lacks ALTER privileges, or the column has some
        // constraint that blocks a plain MODIFY, this silently no-ops
        // instead of breaking the page — it's a defensive improvement,
        // not something the rest of the app depends on to function.
        @$conn->query("ALTER TABLE `{$tbl}` MODIFY `password` VARCHAR(255) NOT NULL");
    }
}
ensurePasswordColumnWidth($conn);

// ============================================
// NEW: Lazy migration — is_active status column for Faculty & Users
// ------------------------------------------------------------
// Mirrors the same pattern as ensureAdminExtraColumns() above so that
// Faculty, Company, and Student accounts can now also be independently
// Activated/Deactivated from this page (Status column), the same way
// Admin accounts already could. Defaults to 1 (active) so existing rows
// are unaffected.
// NOTE: actual login-blocking enforcement for Faculty/Company/Student
// (checking is_active at sign-in) needs to be wired up wherever those
// roles log in (e.g. login.php), the same way it already is for Admins —
// that enforcement lives outside this file and is unchanged here.
// ============================================
function ensureStatusColumn(mysqli $conn, string $table): void {
    static $checked = [];
    if (!empty($checked[$table])) return;
    $checked[$table] = true;

    $col_check = $conn->query("SHOW COLUMNS FROM `{$table}` LIKE 'is_active'");
    if ($col_check && $col_check->num_rows === 0) {
        $conn->query("ALTER TABLE `{$table}` ADD COLUMN `is_active` TINYINT(1) NOT NULL DEFAULT 1");
    }
}
ensureStatusColumn($conn, 'faculty');
ensureStatusColumn($conn, 'users');

// ============================================
// NEW: Lazy migration — company_name column on `users`
// ------------------------------------------------------------
// The Account Management "Company" table used to display the company's
// Type (Public/Private). It now displays the Company Name instead, so
// this lazily adds a `company_name` column the same defensive way as
// the columns above, in case a given deployment doesn't have it yet.
//
// ── UPDATED (adjustment): the Company table's "Company Name" column
// was showing blank for every row. This lazily-added `company_name`
// column on `users` is never actually populated anywhere in the
// codebase — the real company name lives in `company_information.company`
// (the same column company_validation.php reads via
// `company_information ci INNER JOIN users u ON ci.user_id = u.id`).
// The fetch query further below (see the `$companies = ...` query near
// "FETCH ACCOUNTS & RENDER") now LEFT JOINs company_information and
// reads `ci.company` (falling back to `users.company_name` if somehow
// present), so this column is kept purely for backward-compatibility /
// safety and no longer relied on as the sole source of the name.
// ============================================
function ensureCompanyNameColumn(mysqli $conn): void {
    static $checked = false;
    if ($checked) return;
    $checked = true;

    $col_check = $conn->query("SHOW COLUMNS FROM users LIKE 'company_name'");
    if ($col_check && $col_check->num_rows === 0) {
        $conn->query("ALTER TABLE users ADD COLUMN `company_name` VARCHAR(255) NULL");
    }
}
ensureCompanyNameColumn($conn);

// ── NEW: Campus branch list (from student_import) for the "School"
// dropdown on the Create Admin Account form. Deployments without a
// student_import table / campus_branch column simply get an empty
// list, so the dropdown still safely falls back to the "Other"
// free-text option. ──
$campus_branches = [];
$cb_res = @$conn->query("SELECT DISTINCT campus_branch FROM students_import WHERE campus_branch IS NOT NULL AND campus_branch <> '' ORDER BY campus_branch ASC");
if ($cb_res) {
    while ($cb_row = $cb_res->fetch_assoc()) {
        $cb_val = trim((string)($cb_row['campus_branch'] ?? ''));
        if ($cb_val !== '') $campus_branches[] = $cb_val;
    }
}

// ── Pending application requests count for sidebar badge ──
$conn->query("CREATE TABLE IF NOT EXISTS admin_application_approvals (
    id INT AUTO_INCREMENT PRIMARY KEY,
    student_id INT NOT NULL,
    company_id INT NOT NULL,
    phase VARCHAR(20) NOT NULL DEFAULT 'pending',
    skill1 VARCHAR(200), skill2 VARCHAR(200), skill3 VARCHAR(200),
    exp1 TEXT, exp2 TEXT,
    submitted_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY unique_application (student_id, company_id)
)");
$app_request_count_res = $conn->query("SELECT COUNT(*) as total FROM admin_application_approvals aaa_c INNER JOIN users aaa_u ON aaa_u.id = aaa_c.student_id");
$app_request_count = (int)(($app_request_count_res ? $app_request_count_res->fetch_assoc()['total'] : 0));

// ============================================================================
// NEW (this adjustment): STUDENT REQUIREMENT SUBMISSIONS — side-menu indicator.
// A student's new / re-uploaded requirement is recorded as a notification by
// administrator.php (cv_sru_detect()) and counts toward the Student Validation
// indicator next to the application requests — the same count administrator.php
// shows. Same helpers as administrator.php (created once per session, so every
// admin page can count them). Fully guarded: if anything fails the count simply
// stays the application requests only.
// ============================================================================
if (!function_exists('cv_sru_ensure')) {
    function cv_sru_ensure($conn) {
        if (!empty($_SESSION['cv_sru_ready'])) return true;
        try {
            $conn->query("CREATE TABLE IF NOT EXISTS student_requirement_upload_notifications (
                id INT AUTO_INCREMENT PRIMARY KEY, user_id INT NOT NULL, detail TEXT NULL,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP, updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                admin_viewed TINYINT(1) NOT NULL DEFAULT 0, KEY idx_sru_viewed (admin_viewed), KEY idx_sru_user (user_id))");
            $conn->query("CREATE TABLE IF NOT EXISTS student_requirement_upload_watch (
                user_id INT NOT NULL, requirement_type VARCHAR(100) NOT NULL, file_len BIGINT NOT NULL DEFAULT 0,
                PRIMARY KEY (user_id, requirement_type))");
            $conn->query("CREATE TABLE IF NOT EXISTS student_requirement_upload_meta (id TINYINT NOT NULL PRIMARY KEY, initialized TINYINT(1) NOT NULL DEFAULT 0)");
            $_SESSION['cv_sru_ready'] = 1;
            return true;
        } catch (\Throwable $e) { return false; }
    }
    // unviewed submissions of active (not archived) students
    function cv_sru_count($conn) {
        try {
            if (!cv_sru_ensure($conn)) return 0;
            $r = $conn->query("SELECT COUNT(*) AS total FROM student_requirement_upload_notifications n
                               INNER JOIN users u ON u.id = n.user_id WHERE n.admin_viewed = 0 AND COALESCE(u.is_archived, 0) = 0");
            $row = $r ? $r->fetch_assoc() : null;
            return (int)($row['total'] ?? 0);
        } catch (\Throwable $e) { return 0; }
    }
}
try { $app_request_count += cv_sru_count($conn); } catch (\Throwable $e) { /* indicator keeps the application-request count */ }

// ── NEW: Pending MOA request count for sidebar badge (Company Requirements) ──
// Mirrors the same moa_requests table/query used in company_validation.php
// and admin_student_list.php, so the indicator is visible from this page too,
// not just when the admin is actually on company_validation.php.
$conn->query("CREATE TABLE IF NOT EXISTS moa_requests (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT,
    request_type VARCHAR(20) NOT NULL,
    company_name VARCHAR(200),
    company_profile TEXT,
    company_address VARCHAR(300),
    position VARCHAR(150),
    contact_first_name VARCHAR(100),
    contact_middle_name VARCHAR(100),
    contact_last_name VARCHAR(100),
    status VARCHAR(20) DEFAULT 'Pending',
    submitted_at DATETIME DEFAULT CURRENT_TIMESTAMP
)");
/* ── FIX (sidebar notification indicator — adopted from
   admin_student_list.php / course_offering.php): the "Company
   Requirements" badge used to count moa_requests rows with
   status='Pending'. That does not match what company_validation.php
   treats as a notification: every Pending MOA request is auto-ingested
   there right away (so that count was almost always 0 / out of sync),
   and its Notification Inbox lists (a) un-viewed, transferred MOA
   notifications (moa_requests.admin_viewed=0 AND transferred=1) PLUS
   (b) un-viewed requirement-upload notifications
   (company_requirement_upload_notifications.admin_viewed=0).
   This helper uses exactly that same rule, so the number shown here is
   always the same number shown on company_validation.php. Every lookup
   is guarded — a missing column/table simply counts as 0. Read-only:
   no DDL, no writes. ── */
if (!function_exists('monitoring_company_validation_notif_count')) {
    function monitoring_company_validation_notif_count($conn) {
        $total = 0;
        // (a) MOA notifications — same rule as company_validation.php's inbox
        try {
            $hasViewed = false; $hasTransferred = false;
            $colRes = $conn->query("SHOW COLUMNS FROM moa_requests");
            if ($colRes) {
                while ($colRow = $colRes->fetch_assoc()) {
                    if ($colRow['Field'] === 'admin_viewed') $hasViewed = true;
                    if ($colRow['Field'] === 'transferred')  $hasTransferred = true;
                }
            }
            if ($hasViewed && $hasTransferred) {
                $r = $conn->query("SELECT COUNT(*) AS total FROM moa_requests WHERE admin_viewed=0 AND transferred=1");
                $row = $r ? $r->fetch_assoc() : null;
                $total += (int)($row['total'] ?? 0);
            }
        } catch (\Throwable $e) { /* table not created yet — count as 0 */ }
        // (b) requirement-upload notifications
        try {
            $tblRes = $conn->query("SHOW TABLES LIKE 'company_requirement_upload_notifications'");
            if ($tblRes && $tblRes->num_rows > 0) {
                $r = $conn->query("SELECT COUNT(*) AS total FROM company_requirement_upload_notifications WHERE admin_viewed=0");
                $row = $r ? $r->fetch_assoc() : null;
                $total += (int)($row['total'] ?? 0);
            }
        } catch (\Throwable $e) { /* table not created yet — count as 0 */ }
        return $total;
    }
}
$moa_pending_count = monitoring_company_validation_notif_count($conn); // FIX (sidebar notification indicator)

// ── NEW: Live MOA pending-count endpoint so the sidebar badge can poll
// without a full page reload (mirrors the ungraded_count endpoint below). ──
if (isset($_GET['moa_pending_count']) && $_GET['moa_pending_count'] == '1') {
    header('Content-Type: application/json');
    echo json_encode(['count' => $moa_pending_count]);
    exit;
}

// ── NEW (this adjustment): live EMAIL RECOVERY REQUEST list for the popup + side-menu badge ──
// Read-only. Returns the Pending email recovery requests (id, name, account type) so every
// admin page can pop up "<Name> submitted a new email recovery request" the moment one
// arrives — same approach as administrator.php?app_request_list=1 for application requests.
if (isset($_GET['recovery_request_list']) && $_GET['recovery_request_list'] == '1') {
    header('Content-Type: application/json');
    $list = [];
    try {
        $rlTbl = $conn->query("SHOW TABLES LIKE 'email_recovery_requests'");
        if ($rlTbl && $rlTbl->num_rows > 0) {
            $rl = $conn->query("SELECT id, first_name, middle_name, last_name, account_type
                                FROM email_recovery_requests
                                WHERE (status IS NULL OR TRIM(status) = '' OR LOWER(TRIM(status)) = 'pending')
                                ORDER BY submitted_at ASC, id ASC");
            if ($rl) {
                while ($r = $rl->fetch_assoc()) {
                    $list[] = [
                        'id'           => (int)$r['id'],
                        'full_name'    => trim(($r['first_name'] ?? '') . ' ' . (!empty($r['middle_name']) ? $r['middle_name'] . ' ' : '') . ($r['last_name'] ?? '')),
                        'account_type' => ucfirst(strtolower((string)($r['account_type'] ?? ''))),
                    ];
                }
            }
        }
    } catch (\Throwable $e) { $list = []; }
    echo json_encode(['count' => count($list), 'rows' => $list]);
    exit;
}


// ============================================
// SHARED, HARDENED MAIL-SENDING HELPER
// ------------------------------------------------------------
// BUG FIX (email not received after successful admin creation):
// Both the Phase-1 OTP email and the new-admin credentials email used
// to be built as two separate, slightly different PHPMailer blocks.
// If PHPMailer threw anything that was NOT an instance of
// PHPMailer\PHPMailer\Exception specifically (for example a generic
// \Exception/\Error/\Throwable coming from the underlying socket/TLS
// layer, or from SMTP() itself), the old `catch (Exception $e)` block
// — which only catches the aliased PHPMailer\PHPMailer\Exception —
// would NOT catch it. That turns into an uncaught fatal error mid-
// request, which happens AFTER the admin row/activity log were
// already written, so PHP stops before reaching the final
// `echo json_encode([...'success' => true...])` call.
//
// ── SECURITY FIX (this pass — GUARANTEED single-recipient delivery):
// ------------------------------------------------------------
// Every call site (OTP email, new-admin credentials email, and the
// email-recovery Accept/Reject notifications) goes through this ONE
// helper, and this helper now GUARANTEES — not just attempts — that
// an email can only ever reach the one intended recipient:
//
//   1. $toEmail is strictly validated as a single, well-formed email
//      address via filter_var(FILTER_VALIDATE_EMAIL). Anything blank,
//      malformed, or containing extra addresses (e.g. a stray comma-
//      separated string) is rejected before PHPMailer is even touched.
//   2. Right before adding the recipient, the helper explicitly clears
//      any To/CC/BCC/Reply-To state on the PHPMailer instance (belt-
//      and-braces, since a fresh instance is already created per call)
//      and adds exactly ONE address — the specific person the email is
//      for.
//   3. NEW: after addAddress(), the helper VERIFIES — by reading back
//      PHPMailer's own recipient list via getToAddresses() — that
//      exactly one recipient is registered and that its address
//      matches $toEmail exactly (case-insensitively). If that check
//      ever fails for any reason, the function does NOT call send() at
//      all; it logs the discrepancy and returns a failure instead. This
//      turns "we only ever add one address" from an assumption into a
//      verified precondition of actually sending the email, so it is
//      structurally impossible for this function to silently deliver a
//      copy of an OTP, a temporary password, or a recovery notification
//      to anyone other than the intended owner of $toEmail.
//   4. Catches \Throwable (not just PHPMailer's Exception), so ANY
//      failure — SMTP, TLS, DNS, or otherwise — is safely captured
//      instead of ever crashing the request.
//   5. Falls back to PHP's built-in mail() function if the SMTP send
//      fails for any reason, so a transient SMTP/TLS/network hiccup on
//      the host doesn't mean the new admin never receives their
//      credentials at all — this fallback also only ever targets the
//      single, pre-validated $toEmail address, with no CC/BCC headers.
//   6. Logs the real failure reason server-side via error_log() for
//      diagnosis, without ever exposing internals to the client beyond
//      the existing temp-password fallback message.
//
// ── SECURITY FIX (this pass — the actual source of the reported leak):
// ------------------------------------------------------------
// Everything above already guarantees the *To:* field can only ever
// contain the one intended recipient. That was never the problem.
//
// The real leak is that this function used to sign in to SMTP with a
// username/password hardcoded in plain text directly in this source
// file:
//     $smtpUser = 'salesjohnlhoyd@gmail.com';
//     $smtpPass = 'plmonclcxmxgvqiz';
// Every email this system sends — every OTP, every new-admin temporary
// password, every recovery notification — goes out FROM that one
// Gmail account, and Gmail keeps its own copy of every message it
// sends in that account's "Sent Mail" folder. Anyone who can read this
// source file (anyone with hosting/FTP/git/file-manager access to the
// codebase, or simply anyone who has ever seen this file) already has
// the exact credentials needed to log into https://mail.google.com and
// read that Sent Mail folder — which contains every OTP and every
// temporary password this system has ever generated, for every admin,
// regardless of who it was actually addressed to. That is what "the
// OTP can still be viewed by another email" almost certainly refers
// to: not a bug in who the message gets addressed to (that part is
// solid), but the sending mailbox itself — and therefore its entire
// send history — being exposed by hardcoded credentials sitting in
// source.
//
// FIX:
//   - Credentials are now read from environment variables
//     (SMTP_USERNAME / SMTP_PASSWORD) first. The previous literal
//     values are kept ONLY as a fallback so nothing breaks immediately
//     on a host that hasn't set those env vars yet — but this Gmail
//     App Password has already been exposed in this file and should be
//     revoked/rotated (generate a fresh Gmail App Password, delete the
//     old one) and the new value set via the host's environment
//     variable / .env mechanism (outside the web root, outside version
//     control) rather than left in this file.
//   - $toName (the recipient's display name) is now stripped of CR/LF
//     before being handed to PHPMailer, as defense-in-depth against
//     header-injection via the display-name field.
//
// Returns [bool $sent, string $error].
// ============================================
// ============================================
// NEW (this adjustment): SHARED AUTOMATED-EMAIL DESIGN
// ------------------------------------------------------------
// Every automated email this page sends now uses the same layout as the
// NEUST OJT Portal's "Needs Attention" requirement email:
//   • navy header — gold "NEUST OJT Portal" + "Atate Campus — On the Job
//     Training System"
//   • a tinted status band — small uppercase label + one-line headline
//   • "Hi <name>," greeting and short paragraphs
//   • a highlighted box (label + value) for the key information
//   • a light lavender detail box (label + value)
//   • optional navy "Log In to OJT Portal" button (gold text)
//   • grey "automated message" footer
// Table-based markup with inline styles so it renders the same in Gmail,
// Outlook and phone mail apps. Only the look changed — every email keeps
// its recipient, subject and information.
// ============================================
function mon_portal_url(string $page): string {
    $https  = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || (($_SERVER['SERVER_PORT'] ?? '') == 443);
    $host   = $_SERVER['HTTP_HOST'] ?? 'localhost';
    $dir    = rtrim(str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? '/')), '/');
    return ($https ? 'https' : 'http') . '://' . $host . $dir . '/' . ltrim($page, '/');
}

// NEW (this adjustment): optional "label — value" rows under the lavender
// detail box's main value (used by the recovery Approved / Rejected emails
// to summarise the request). Emails that don't pass detail_rows are unchanged.
function mon_email_detail_rows(array $rows, string $font, callable $e): string {
    if (!$rows) return '';
    $html = "<table role='presentation' width='100%' cellpadding='0' cellspacing='0' style='margin-top:10px;border-top:1px solid #ddd6fe;'>";
    foreach ($rows as $r) {
        $html .= "<tr>"
               . "<td style='padding:7px 10px 0 0;{$font}font-size:12px;color:#6b7280;white-space:nowrap;vertical-align:top;'>" . $e($r[0]) . "</td>"
               . "<td style='padding:7px 0 0;{$font}font-size:13px;font-weight:700;color:#07145f;text-align:right;word-break:break-word;'>" . $e($r[1]) . "</td>"
               . "</tr>";
    }
    return $html . "</table>";
}

function mon_email_template(array $o): string {
    $themes = [
        'attention' => ['#fffbeb', '#b45309', '#fff7ed', '#fed7aa', '#9a3412'],
        'success'   => ['#f0fdf4', '#15803d', '#f0fdf4', '#bbf7d0', '#166534'],
        'info'      => ['#eff6ff', '#1d4ed8', '#eff6ff', '#bfdbfe', '#1e3a8a'],
        'danger'    => ['#fef2f2', '#b91c1c', '#fef2f2', '#fecaca', '#991b1b'],
    ];
    [$bandBg, $bandLabel, $boxBg, $boxBorder, $boxText] = $themes[$o['theme'] ?? 'info'] ?? $themes['info'];
    $e = function ($s) { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); };
    // No quotes inside the font list — the styles sit inside single-quoted style='' attributes.
    $font = "font-family:Arial,Helvetica,sans-serif;";

    $paras = '';
    foreach (($o['paragraphs'] ?? []) as $p) {
        $paras .= "<p style='margin:0 0 14px;{$font}font-size:14px;line-height:1.6;color:#374151;'>{$p}</p>";
    }
    $after = '';
    foreach (($o['after'] ?? []) as $p) {
        $after .= "<p style='margin:0 0 14px;{$font}font-size:14px;line-height:1.6;color:#374151;'>{$p}</p>";
    }

    $highlight = '';
    if (!empty($o['highlight_rows'])) {
        $rows = '';
        foreach ($o['highlight_rows'] as $r) {
            $big = !empty($r['big']) ? 'font-size:24px;letter-spacing:6px;' : 'font-size:15px;';
            $rows .= "<div style='margin:6px 0 0;'>"
                   . (isset($r['label']) && $r['label'] !== '' ? "<span style='{$font}font-size:12px;color:{$boxText};opacity:.8;'>" . $e($r['label']) . ":</span> " : '')
                   . "<span style='{$font}{$big}font-weight:700;color:{$boxText};'>" . $e($r['value']) . "</span></div>";
        }
        $highlight = "<table role='presentation' width='100%' cellpadding='0' cellspacing='0' style='margin:4px 0 18px;'><tr>"
                   . "<td style='background:{$boxBg};border:1px solid {$boxBorder};border-radius:8px;padding:14px 16px;'>"
                   . "<div style='{$font}font-size:11px;font-weight:700;letter-spacing:1px;text-transform:uppercase;color:{$bandLabel};'>" . $e($o['highlight_label'] ?? '') . "</div>"
                   . $rows . "</td></tr></table>";
    }

    $detail = '';
    if (!empty($o['detail_label'])) {
        $detail = "<table role='presentation' width='100%' cellpadding='0' cellspacing='0' style='margin:4px 0 22px;'><tr>"
                . "<td style='background:#f5f3ff;border:1px solid #ddd6fe;border-radius:8px;padding:14px 16px;'>"
                . "<div style='{$font}font-size:11px;font-weight:700;letter-spacing:1px;text-transform:uppercase;color:#6b7280;'>" . $e($o['detail_label']) . "</div>"
                . "<div style='{$font}font-size:15px;font-weight:700;color:#07145f;margin-top:6px;'>" . $e($o['detail_value'] ?? '') . "</div>"
                . mon_email_detail_rows($o['detail_rows'] ?? [], $font, $e)
                . "</td></tr></table>";
    }

    $button = '';
    if (!empty($o['button_url'])) {
        $button = "<table role='presentation' cellpadding='0' cellspacing='0' align='center' style='margin:4px auto 6px;'><tr>"
                . "<td style='background:#07145f;border-radius:8px;'>"
                . "<a href='" . $e($o['button_url']) . "' style='display:inline-block;padding:12px 26px;{$font}font-size:14px;font-weight:700;color:#FFD700;text-decoration:none;'>"
                . $e($o['button_text'] ?? 'Log In to OJT Portal') . "</a></td></tr></table>";
    }

    // NEW (this adjustment): 'layout' => 'simple' — one continuous message (header,
    // greeting, text, the key value shown inline, optional button, footer) with no
    // status band or separate boxes. Used by the OTP and new-admin emails.
    if (($o['layout'] ?? '') === 'simple') {
        $key = '';
        if (!empty($o['code'])) {
            $key = "<div style='text-align:center;margin:20px 0 22px;'>"
                 . "<span style='{$font}font-size:30px;font-weight:700;letter-spacing:10px;color:#07145f;'>" . $e($o['code']) . "</span></div>";
        } elseif (!empty($o['lines'])) {
            $rows = [];
            foreach ($o['lines'] as $ln) {
                $rows[] = "<span style='color:#6b7280;'>" . $e($ln[0]) . ":</span> <strong style='color:#07145f;'>" . $e($ln[1]) . "</strong>";
            }
            $key = "<p style='margin:0 0 16px;{$font}font-size:14px;line-height:1.9;color:#374151;'>" . implode('<br>', $rows) . "</p>";
        }
        return "<!DOCTYPE html><html><body style='margin:0;padding:0;background:#f3f4f6;'>"
             . "<table role='presentation' width='100%' cellpadding='0' cellspacing='0' style='background:#f3f4f6;padding:24px 12px;'><tr><td align='center'>"
             . "<table role='presentation' width='100%' cellpadding='0' cellspacing='0' style='max-width:480px;background:#ffffff;border:1px solid #e5e7eb;border-radius:12px;overflow:hidden;'>"
             . "<tr><td align='center' style='background:#07145f;padding:22px 20px;'>"
             . "<div style='{$font}font-size:20px;font-weight:700;color:#FFD700;'>NEUST OJT Portal</div>"
             . "<div style='{$font}font-size:11px;color:#c7d2fe;margin-top:6px;'>Atate Campus &mdash; On the Job Training System</div>"
             . "</td></tr>"
             . "<tr><td style='padding:26px 26px 20px;'>"
             . "<p style='margin:0 0 14px;{$font}font-size:14px;color:#374151;'>Hi <strong>" . $e($o['name'] ?? '') . "</strong>,</p>"
             . $paras . $key . $after . $button
             . "</td></tr>"
             . "<tr><td align='center' style='background:#f9fafb;border-top:1px solid #e5e7eb;padding:14px 20px;'>"
             . "<div style='{$font}font-size:11px;color:#9ca3af;line-height:1.5;'>This is an automated message from the NEUST OJT Portal. Please do not reply to this email.</div>"
             . "</td></tr>"
             . "</table></td></tr></table></body></html>";
    }

    return "<!DOCTYPE html><html><body style='margin:0;padding:0;background:#f3f4f6;'>"
         . "<table role='presentation' width='100%' cellpadding='0' cellspacing='0' style='background:#f3f4f6;padding:24px 12px;'><tr><td align='center'>"
         . "<table role='presentation' width='100%' cellpadding='0' cellspacing='0' style='max-width:480px;background:#ffffff;border:1px solid #e5e7eb;border-radius:12px;overflow:hidden;'>"
         // header
         . "<tr><td align='center' style='background:#07145f;padding:22px 20px;'>"
         . "<div style='{$font}font-size:20px;font-weight:700;color:#FFD700;'>NEUST OJT Portal</div>"
         . "<div style='{$font}font-size:11px;color:#c7d2fe;margin-top:6px;'>Atate Campus &mdash; On the Job Training System</div>"
         . "</td></tr>"
         // status band
         . "<tr><td align='center' style='background:{$bandBg};padding:18px 20px;border-bottom:1px solid #e5e7eb;'>"
         . "<div style='{$font}font-size:11px;font-weight:700;letter-spacing:1.5px;text-transform:uppercase;color:{$bandLabel};'>" . $e($o['band_label'] ?? '') . "</div>"
         . "<div style='{$font}font-size:15px;font-weight:700;color:#111827;margin-top:6px;'>" . $e($o['band_headline'] ?? '') . "</div>"
         . "</td></tr>"
         // body
         . "<tr><td style='padding:24px 24px 18px;'>"
         . "<p style='margin:0 0 14px;{$font}font-size:14px;color:#374151;'>Hi <strong>" . $e($o['name'] ?? '') . "</strong>,</p>"
         . $paras . $highlight . $after . $detail . $button
         . "</td></tr>"
         // footer
         . "<tr><td align='center' style='background:#f9fafb;border-top:1px solid #e5e7eb;padding:14px 20px;'>"
         . "<div style='{$font}font-size:11px;color:#9ca3af;line-height:1.5;'>This is an automated message from the NEUST OJT Portal. Please do not reply to this email.</div>"
         . "</td></tr>"
         . "</table></td></tr></table></body></html>";
}

function sendSystemEmail(string $toEmail, string $subject, string $htmlBody, string $toName = ''): array {
    // ── UPDATED: the old hardcoded Gmail fallback has been removed
    // entirely now that this deployment sends through Brevo instead of a
    // personal Gmail inbox. Credentials must come from the environment
    // (SMTP_USERNAME / SMTP_PASSWORD — set via config/env.local.php for
    // local XAMPP development, or your host's real env-var mechanism in
    // production). If they're missing, this refuses to send rather than
    // silently falling back to a credential that shouldn't exist in
    // source anymore — that's a deliberate change from before. ──
    $smtpUser = getenv('SMTP_USERNAME') ?: '';
    $smtpPass = getenv('SMTP_PASSWORD') ?: '';
    $lastError = '';

    if ($smtpUser === '' || $smtpPass === '') {
        $err = 'Refused to send: SMTP_USERNAME / SMTP_PASSWORD are not set in the environment.';
        error_log('[monitoring.php] sendSystemEmail configuration missing: ' . $err);
        return [false, $err];
    }

    // ── Defense-in-depth: strip CR/LF from the display name so it can
    // never be abused to inject extra headers via addAddress()'s $name
    // parameter. This does not change what shows up as the recipient's
    // name in normal use — only removes characters that should never be
    // in a name to begin with. ──
    $toName = str_replace(["\r", "\n"], '', $toName);

    // ── SECURITY FIX: strict single-address validation up front. This
    // is the first line of defense — a blank, malformed, or accidentally
    // multi-address string (e.g. "a@x.com,b@y.com") is rejected here and
    // never even reaches PHPMailer, so it can never result in more than
    // one recipient. ──
    $toEmail = trim($toEmail);
    if ($toEmail === '' || strpbrk($toEmail, ",;\r\n") !== false || !filter_var($toEmail, FILTER_VALIDATE_EMAIL)) {
        $err = 'Refused to send: "' . $toEmail . '" is not a single valid email address.';
        error_log('[monitoring.php] sendSystemEmail validation failed: ' . $err);
        return [false, $err];
    }

    // ── UPDATED: switched sending provider from a personal Gmail inbox to
    // Brevo's transactional SMTP relay. Host/port are read from env vars
    // (SMTP_HOST / SMTP_PORT) so this can point anywhere without another
    // code change, but default to Brevo's relay since that's what this
    // deployment is now using. $smtpUser/$smtpPass above are already the
    // Brevo SMTP login + generated SMTP key, sourced from env vars (see
    // config/env.local.php for local development). ──
    $smtpHost = getenv('SMTP_HOST') ?: 'smtp-relay.brevo.com';
    $smtpPort = (int)(getenv('SMTP_PORT') ?: 587);

    $mail = new PHPMailer(true);
    try {
        $mail->isSMTP();
        $mail->Host         = $smtpHost;
        $mail->SMTPAuth     = true;
        $mail->Username     = $smtpUser;
        $mail->Password     = $smtpPass;
        $mail->SMTPSecure   = 'tls';
        $mail->Port         = $smtpPort;
        $mail->SMTPKeepAlive = false;
        $mail->Timeout      = 15;
        // Relax strict TLS/SSL peer verification — on several local/shared
        // hosting PHP setups the bundled CA certificate list is missing or
        // outdated, which makes the TLS handshake to smtp.gmail.com throw a
        // certificate-verification error before the email is ever sent.
        $mail->SMTPOptions = [
            'ssl' => [
                'verify_peer'       => false,
                'verify_peer_name'  => false,
                'allow_self_signed' => true,
            ],
        ];
        $mail->CharSet = 'UTF-8';
        $mail->setFrom($smtpUser, 'Atate On the Job Training System');

        // ── Recipient-isolation guarantee (privacy fix) ────────────────
        // A fresh PHPMailer instance is already created per call, but we
        // defensively wipe any To/CC/BCC/Reply-To state right before
        // adding the single intended recipient.
        // -----------------------------------------------------------------
        $mail->clearAddresses();
        $mail->clearAllRecipients();
        $mail->clearCCs();
        $mail->clearBCCs();
        $mail->clearReplyTos();
        $mail->addAddress($toEmail, $toName);

        // ── SECURITY FIX: verified precondition before send() ──────────
        // Read PHPMailer's own recipient list back and confirm it holds
        // exactly one address, and that it is exactly the address we
        // intended to send to. If this ever doesn't hold — for any
        // reason — refuse to send rather than risk delivering to the
        // wrong (or an extra) recipient.
        // -----------------------------------------------------------------
        $toAddresses = $mail->getToAddresses();
        $recipientOk = (
            count($toAddresses) === 1 &&
            isset($toAddresses[0][0]) &&
            strcasecmp($toAddresses[0][0], $toEmail) === 0 &&
            empty($mail->getCcAddresses()) &&
            empty($mail->getBccAddresses())
        );
        if (!$recipientOk) {
            $err = 'Refused to send: recipient verification failed (expected exactly one recipient matching ' . $toEmail . ').';
            error_log('[monitoring.php] sendSystemEmail recipient verification failed for ' . $toEmail . ': ' . $err);
            return [false, $err];
        }

        $mail->isHTML(true);
        $mail->Subject = $subject;
        $mail->Body    = $htmlBody;
        $mail->send();

        // ── OPTIONAL DEFENSE-IN-DEPTH: best-effort purge of the copy this
        // send just left in the sending mailbox's own "Sent Mail" folder.
        // ------------------------------------------------------------
        // This does NOT fix credential exposure by itself — anyone who
        // still has the mailbox password can still log in at any time.
        // It exists only to shrink the window: if this env flag is turned
        // on, the app reaches back into the same mailbox over IMAP right
        // after sending and deletes the message it just sent, so an OTP
        // or temporary password doesn't sit readable in Sent Mail
        // indefinitely. Entirely opt-in (SMTP_AUTO_DELETE_SENT=1) and
        // entirely best-effort: it requires the `imap` PHP extension and
        // IMAP access enabled on the mailbox, and any failure here is
        // swallowed so it can never break or delay the actual email send
        // that the rest of this function already completed successfully.
        if (getenv('SMTP_AUTO_DELETE_SENT') === '1') {
            sendSystemEmail_purgeFromSentFolder($smtpUser, $smtpPass, $subject, $toEmail);
        }

        return [true, ''];
    } catch (\Throwable $e) {
        $lastError = $mail->ErrorInfo ?: $e->getMessage();
        error_log('[monitoring.php] SMTP send to ' . $toEmail . ' failed: ' . $lastError);
    }

    // ── Fallback: if SMTP failed for any reason, try the server's local
    // mail transport (PHP mail()) so a transient SMTP/TLS/network issue on
    // this particular host doesn't mean the recipient never gets anything.
    // This is best-effort — some hosts have mail() disabled entirely — so
    // its own failure is also caught and logged rather than allowed to
    // throw/crash the request. Note this call also only ever targets the
    // single, pre-validated $toEmail address passed in — no CC/BCC headers
    // are added here either, preserving the same single-recipient
    // guarantee. ──
    try {
        $headers  = "MIME-Version: 1.0\r\n";
        $headers .= "Content-Type: text/html; charset=UTF-8\r\n";
        $headers .= "From: Atate On the Job Training System <{$smtpUser}>\r\n";
        $fallbackSent = @mail($toEmail, $subject, $htmlBody, $headers);
        if ($fallbackSent) {
            return [true, ''];
        }
        $lastError = $lastError !== ''
            ? $lastError . ' (fallback mail() also failed)'
            : 'mail() failed';
    } catch (\Throwable $e2) {
        error_log('[monitoring.php] Fallback mail() to ' . $toEmail . ' failed: ' . $e2->getMessage());
        $lastError = $lastError !== '' ? $lastError : $e2->getMessage();
    }

    return [false, $lastError];
}

// ============================================
// OPTIONAL: best-effort purge of a just-sent message from the sending
// mailbox's own "Sent Mail" folder via IMAP.
// ------------------------------------------------------------
// Called only from inside sendSystemEmail(), and only when the
// SMTP_AUTO_DELETE_SENT=1 environment variable is set. This is purely
// a residual-exposure reducer (see the block comment at its call site
// above) — it is NOT a substitute for rotating the mailbox password,
// restricting who has it, and ideally moving off a shared personal
// inbox entirely for transactional email, which are the actual fixes
// for "someone else can read what this account sent."
//
// Deliberately conservative:
//   - No-ops entirely if the `imap` PHP extension isn't loaded, so this
//     never causes a fatal error on hosts that don't have it compiled in.
//   - Every IMAP call is wrapped so a failure here is only ever logged,
//     never thrown back up to the caller — sendSystemEmail() has already
//     completed the real send by the time this runs, and this cleanup
//     step must never be able to make that appear to fail.
//   - Searches Sent Mail for a message with a matching Subject sent
//     "TODAY" (IMAP SINCE search) to the specific $toEmail, and only
//     acts on that match, rather than blindly deleting the most recent
//     message in the folder.
// ============================================
function sendSystemEmail_purgeFromSentFolder(string $smtpUser, string $smtpPass, string $subject, string $toEmail): void {
    if (!extension_loaded('imap')) {
        return; // Extension not available on this host — silently skip.
    }
    try {
        $mailbox = '{imap.gmail.com:993/imap/ssl}[Gmail]/Sent Mail';
        $conn = @imap_open($mailbox, $smtpUser, $smtpPass, 0, 1);
        if ($conn === false) {
            error_log('[monitoring.php] sendSystemEmail_purgeFromSentFolder: could not open IMAP mailbox for cleanup.');
            return;
        }

        // Escape double-quotes in the subject for the IMAP search string.
        $safeSubject = str_replace('"', '', $subject);
        $criteria    = 'SINCE "' . date('d-M-Y') . '" TO "' . str_replace('"', '', $toEmail) . '" SUBJECT "' . $safeSubject . '"';
        $matches     = @imap_search($conn, $criteria, SE_UID);

        if (is_array($matches) && count($matches) > 0) {
            foreach ($matches as $uid) {
                @imap_delete($conn, $uid, FT_UID);
            }
            @imap_expunge($conn);
        }

        @imap_close($conn);
    } catch (\Throwable $e) {
        error_log('[monitoring.php] sendSystemEmail_purgeFromSentFolder failed: ' . $e->getMessage());
    }
}

// ============================================
// AJAX: ADMIN CREDENTIAL CHECK
// ============================================
if (isset($_POST['ajax_check_admin_credentials'])) {
    $email    = trim($_POST['admin_email'] ?? '');
    $password = trim($_POST['admin_password'] ?? '');

    $stmt = $conn->prepare("SELECT id, password, first_name, last_name, email FROM admins WHERE email = ?");
    $stmt->bind_param("s", $email);
    $stmt->execute();
    $stmt->store_result();

    if ($stmt->num_rows === 0) {
        echo json_encode(['success' => false, 'message' => 'Admin not found.']);
        exit;
    }

    $stmt->bind_result($id, $hashedPw, $fname, $lname, $adminEmail);
    $stmt->fetch();

    if (!password_verify($password, $hashedPw)) {
        echo json_encode(['success' => false, 'message' => 'Incorrect password.']);
        exit;
    }

    $otp = rand(100000, 999999);
    $_SESSION['admin_create_otp']            = $otp;
    $_SESSION['admin_create_otp_time']       = time();
    $_SESSION['admin_create_verified_id']    = $id;
    $_SESSION['admin_create_verifier_email'] = $adminEmail;

    // ── Goes through the shared sendSystemEmail() helper (see definition
    // above) so it benefits from the same \Throwable-safe error handling,
    // local mail() fallback, and verified single-recipient isolation
    // guarantee as every other email this page sends. The OTP can only
    // ever reach the one admin email address that requested it. ──
    [$otpSent, $otpError] = sendSystemEmail(
        $adminEmail,
        'OTP — Admin Account Creation',
        // UPDATED (this adjustment): simple one-section email — same OTP and 5-minute expiry
        mon_email_template([
            'layout'     => 'simple',
            'name'       => $fname,
            'paragraphs' => ['Use this one-time password (OTP) to confirm the admin account creation you started:'],
            'code'       => (string)$otp,
            'after'      => ['This code expires in <strong>5 minutes</strong>. If you did not start this, you can ignore this email &mdash; no account will be created.'],
        ]),
        $fname
    );

    if ($otpSent) {
        echo json_encode(['success' => true, 'message' => 'OTP sent to your email.']);
    } else {
        echo json_encode(['success' => false, 'message' => 'Credentials valid but OTP email failed: ' . $otpError]);
    }
    exit;
}

// ============================================
// AJAX: VERIFY OTP
// ============================================
if (isset($_POST['ajax_verify_admin_otp'])) {
    $inputOtp = trim($_POST['otp'] ?? '');

    if (
        empty($_SESSION['admin_create_otp']) ||
        empty($_SESSION['admin_create_otp_time']) ||
        empty($_SESSION['admin_create_verified_id'])
    ) {
        echo json_encode(['success' => false, 'message' => 'Session expired. Please restart.']);
        exit;
    }

    if ((time() - $_SESSION['admin_create_otp_time']) > 300) {
        unset($_SESSION['admin_create_otp'], $_SESSION['admin_create_otp_time']);
        echo json_encode(['success' => false, 'message' => 'OTP expired. Please restart.']);
        exit;
    }

    if ((string)$inputOtp !== (string)$_SESSION['admin_create_otp']) {
        echo json_encode(['success' => false, 'message' => 'Incorrect OTP.']);
        exit;
    }

    $_SESSION['admin_create_authenticated'] = true;
    unset($_SESSION['admin_create_otp'], $_SESSION['admin_create_otp_time']);
    echo json_encode(['success' => true, 'message' => 'OTP verified.']);
    exit;
}

// ============================================
// SAFE LIVE REFRESH
// ── FIX: $history query is now INSIDE the refresh block so the result
//    set is always freshly queried, not a stale/consumed pointer from
//    the page-load query above.
// ── NOTE: this endpoint is kept (harmless, still reachable) but is no
//    longer polled automatically by the client — see the removed
//    setInterval() in the script below. The Live Activity Log is now
//    updated directly in the DOM the instant an action completes
//    (create/delete/activate/deactivate/accept/reject), using the
//    `log` object each AJAX endpoint already returns.
// ============================================
if (isset($_GET['refresh'])) {
    $history = $conn->query("
        SELECT *,
            COALESCE(performed_by,'System') as performed_by_safe,
            COALESCE(account_type,'N/A') as account_type_safe,
            COALESCE(account_name,'N/A') as account_name_safe
        FROM activity_logs
        ORDER BY created_at DESC
    ");
    ?>
    <div id="historyContainer">
        <?php if ($history && $history->num_rows > 0): ?>
            <?php while ($row = $history->fetch_assoc()):
                $badgeClass = "badge-" . strtoupper(str_replace(' ', '-', $row['action_type']));
            ?>
                <div class="log-entry">
                    <div class="log-entry-inner">
                        <div class="log-entry-left">
                            <span class="badge <?= $badgeClass ?>">
                                <?= htmlspecialchars($row['action_type'] ?? '') ?>
                            </span>
                            <strong>
                                <?= htmlspecialchars($row['account_type'] ?? '') ?>:
                                <?= htmlspecialchars($row['account_name'] ?? '') ?>
                            </strong>
                            <div>Performed by: <?= htmlspecialchars($row['performed_by'] ?? 'System') ?></div>
                            <div>Details: <?= htmlspecialchars($row['details'] ?? '') ?></div>
                        </div>
                        <div class="log-entry-right">
                            <?= date('M d, Y h:i A', strtotime($row['created_at'] ?? '')) ?>
                        </div>
                    </div>
                </div>
            <?php endwhile; ?>
        <?php else: ?>
            <p style="text-align:center; color:#666; padding:20px;">No activity yet.</p>
        <?php endif; ?>
    </div>
    <?php
    exit;
}

// ── NEW (this adjustment): LIVE ACTIVITY LOG — clear the log (Clear Log button + confirmation) ──
// Deletes every entry, then records who cleared it (so there is always a trace), and returns
// that new entry so the page can show it right away. JSON only.
if (isset($_POST['ajax_clear_activity_log'])) {
    header('Content-Type: application/json');
    try {
        $alogCnt = $conn->query("SELECT COUNT(*) AS c FROM activity_logs");
        $alogCleared = $alogCnt ? (int)($alogCnt->fetch_assoc()['c'] ?? 0) : 0;
        if (!$conn->query("DELETE FROM activity_logs")) throw new \RuntimeException('delete failed');
        $alogPerformer = cv_alog_performer($conn);
        $alogDetails = "Cleared the activity log (" . cv_alog_plural($alogCleared, 'entry', 'entries') . " removed) via Manage Accounts";
        cv_alog_write($conn, ['Activity Log Cleared', 'Activity Log', '—', $alogDetails], $alogPerformer);
        $alogNewId = (int)$conn->insert_id;
        $alogAt = (string)cv_alog_scalar($conn, "SELECT NOW()");
        echo json_encode([
            'success' => true, 'cleared' => $alogCleared, 'last_id' => $alogNewId,
            'message' => 'The activity log has been cleared (' . cv_alog_plural($alogCleared, 'entry', 'entries') . ' removed).',
            'log' => ['action_type' => 'Activity Log Cleared', 'performed_by' => $alogPerformer, 'account_type' => 'Activity Log',
                      'account_name' => '—', 'details' => $alogDetails, 'created_at' => $alogAt],
        ]);
    } catch (\Throwable $e) {
        echo json_encode(['success' => false, 'message' => 'The activity log could not be cleared. Please try again.']);
    }
    exit;
}

// ── NEW (this adjustment): LIVE ACTIVITY LOG — entries newer than a given id (read-only) ──
// Lets the Live Activity Log add actions done on the other admin pages (or by another admin)
// as they happen, one entry at a time — no full redraw, so no blinking.
if (isset($_GET['activity_log_since'])) {
    header('Content-Type: application/json');
    $alogSince = (int)$_GET['activity_log_since'];
    $alogRows = [];
    try {
        $alogRes = $conn->query("SELECT id, action_type, performed_by, account_type, account_name, details, created_at
                                 FROM activity_logs WHERE id > $alogSince ORDER BY id ASC LIMIT 100");
        if (!$alogRes) throw new \RuntimeException('query failed');
        while ($r = $alogRes->fetch_assoc()) {
            $alogRows[] = ['id' => (int)$r['id'], 'action_type' => (string)$r['action_type'], 'performed_by' => (string)($r['performed_by'] ?? 'System'),
                           'account_type' => (string)($r['account_type'] ?? ''), 'account_name' => (string)($r['account_name'] ?? ''),
                           'details' => (string)($r['details'] ?? ''), 'created_at' => (string)$r['created_at']];
        }
        echo json_encode(['success' => true, 'rows' => $alogRows]);
    } catch (\Throwable $e) {
        echo json_encode(['success' => false, 'rows' => []]);
    }
    exit;
}

// ── Lightweight ungraded count endpoint for sidebar badge polling ──
if (isset($_GET['ungraded_count']) && $_GET['ungraded_count'] == '1') {
    $stmt_ug = $conn->prepare("
        SELECT COUNT(*) as total
        FROM reports r
        JOIN ojt_assignments oa ON oa.student_id = r.user_id AND oa.company_id = r.company_id
        WHERE r.week_start <= CURDATE()
          AND (r.remark IS NULL OR r.remark != 'Wrong Document')
          AND r.faculty_grade IS NULL
    ");
    $stmt_ug->execute();
    $res_ug = $stmt_ug->get_result()->fetch_assoc();
    $stmt_ug->close();
    header('Content-Type: application/json');
    echo json_encode(['ungraded_count' => (int)($res_ug['total'] ?? 0)]);
    exit;
}

// ============================================
// NEW — AJAX: TOGGLE ADMIN ACTIVE / DEACTIVATED STATUS
// ------------------------------------------------------------
// Since multiple admin accounts can now exist side by side (the old
// "delete the previous admin when a new one is created" behavior has
// been removed — see the CREATE ADMIN ACCOUNT section below), each
// admin row now gets an Activate/Deactivate toggle instead of the
// previous static "—" placeholder. A deactivated admin's is_active
// flag is checked by login.php (or wherever admin login is handled)
// to block sign-in; this endpoint only flips the flag and logs it.
// Returns JSON so the button can update instantly without reloading
// the page.
// ============================================
if (isset($_POST['ajax_toggle_admin_status'])) {
    header('Content-Type: application/json');
    $admin_id = intval($_POST['admin_id'] ?? 0);

    if ($admin_id <= 0) {
        echo json_encode(['success' => false, 'message' => 'Invalid admin account.']);
        exit;
    }

    $stmt = $conn->prepare("SELECT is_active, first_name, middle_name, last_name FROM admins WHERE id = ?");
    $stmt->bind_param("i", $admin_id);
    $stmt->execute();
    $res = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$res) {
        echo json_encode(['success' => false, 'message' => 'Admin account not found.']);
        exit;
    }

    $new_status = ((int)$res['is_active'] === 1) ? 0 : 1;

    $upd = $conn->prepare("UPDATE admins SET is_active = ? WHERE id = ?");
    $upd->bind_param("ii", $new_status, $admin_id);
    $upd->execute();
    $upd->close();

    $full_name      = trim($res['first_name'] . ' ' . ($res['middle_name'] ? $res['middle_name'] . ' ' : '') . $res['last_name']);
    $performer_name = trim(($_SESSION['first_name'] ?? '') . ' ' . ($_SESSION['last_name'] ?? ''));
    if ($performer_name === '') $performer_name = 'Admin';

    $action_label = $new_status ? 'Account Activated' : 'Account Deactivated';
    $details      = $new_status
        ? "Admin account activated for $full_name"
        : "Admin account deactivated for $full_name";

    $log_stmt = $conn->prepare(
        "INSERT INTO activity_logs (action_type, performed_by, account_type, account_name, details, created_at)
         VALUES (?, ?, 'Admin', ?, ?, NOW())"
    );
    $log_stmt->bind_param("ssss", $action_label, $performer_name, $full_name, $details);
    $log_stmt->execute();
    $log_stmt->close();

    echo json_encode([
        'success'   => true,
        'is_active' => $new_status,
        'message'   => $new_status
            ? "$full_name has been activated and can now log in."
            : "$full_name has been deactivated and can no longer log in.",
        'log' => [
            'action_type'  => $action_label,
            'account_type' => 'Admin',
            'account_name' => $full_name,
            'performed_by' => $performer_name,
            'details'      => $details,
            'created_at'   => date('Y-m-d H:i:s'),
        ],
    ]);
    exit;
}

// ============================================
// NEW — AJAX: TOGGLE ACCOUNT ACTIVE/DEACTIVATED STATUS (ALL ROLES)
// ------------------------------------------------------------
// Generalizes the admin-only toggle above so Faculty, Company, and
// Student accounts can also be Activated/Deactivated from their own
// Status column, the same way Admin accounts already could. The
// original ajax_toggle_admin_status endpoint above is untouched and
// still works exactly as before; this is a new, separate endpoint used
// by the updated Status column buttons for every role (including admin).
// Also returns a `log` object so the Live Activity Log can be updated
// directly in the UI the instant the action completes, instead of
// waiting on the old polling refresh.
// ============================================
if (isset($_POST['ajax_toggle_account_status'])) {
    header('Content-Type: application/json');
    $target_id = intval($_POST['target_id'] ?? 0);
    $role      = strtolower(trim($_POST['role'] ?? ''));

    switch ($role) {
        case 'admin':   $table = 'admins';  $account_type_label = 'Admin';   break;
        case 'faculty': $table = 'faculty'; $account_type_label = 'Faculty'; break;
        case 'company': $table = 'users';   $account_type_label = 'Company'; break;
        case 'student': $table = 'users';   $account_type_label = 'Student'; break;
        default:        $table = '';        $account_type_label = '';       break;
    }

    if (!$table || $target_id <= 0) {
        echo json_encode(['success' => false, 'message' => 'Invalid account.']);
        exit;
    }

    ensureStatusColumn($conn, $table);

    $stmt = $conn->prepare("SELECT is_active, first_name, middle_name, last_name FROM `$table` WHERE id = ?");
    $stmt->bind_param("i", $target_id);
    $stmt->execute();
    $res = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$res) {
        echo json_encode(['success' => false, 'message' => 'Account not found.']);
        exit;
    }

    $new_status = ((int)$res['is_active'] === 1) ? 0 : 1;

    $upd = $conn->prepare("UPDATE `$table` SET is_active = ? WHERE id = ?");
    $upd->bind_param("ii", $new_status, $target_id);
    $upd->execute();
    $upd->close();

    $full_name      = trim($res['first_name'] . ' ' . ($res['middle_name'] ? $res['middle_name'] . ' ' : '') . $res['last_name']);
    $performer_name = trim(($_SESSION['first_name'] ?? '') . ' ' . ($_SESSION['last_name'] ?? ''));
    if ($performer_name === '') $performer_name = 'Admin';

    $action_label = $new_status ? 'Account Activated' : 'Account Deactivated';
    $details      = $new_status
        ? "$account_type_label account activated for $full_name"
        : "$account_type_label account deactivated for $full_name";

    $log_stmt = $conn->prepare(
        "INSERT INTO activity_logs (action_type, performed_by, account_type, account_name, details, created_at)
         VALUES (?, ?, ?, ?, ?, NOW())"
    );
    $log_stmt->bind_param("sssss", $action_label, $performer_name, $account_type_label, $full_name, $details);
    $log_stmt->execute();
    $log_stmt->close();

    echo json_encode([
        'success'   => true,
        'is_active' => $new_status,
        'message'   => $new_status
            ? "$full_name has been activated and can now log in."
            : "$full_name has been deactivated and can no longer log in.",
        'log' => [
            'action_type'  => $action_label,
            'account_type' => $account_type_label,
            'account_name' => $full_name,
            'performed_by' => $performer_name,
            'details'      => $details,
            'created_at'   => date('Y-m-d H:i:s'),
        ],
    ]);
    exit;
}

// ============================================
// DETERMINE PAGE TITLE
// ============================================
$pageTitle = "Create Account";
if (basename($_SERVER['PHP_SELF']) == 'administrator.php')              $pageTitle = "Student Requirements";
if (basename($_SERVER['PHP_SELF']) == 'company_validation.php')         $pageTitle = "Company Validation";
if (basename($_SERVER['PHP_SELF']) == 'admin_monitoring_dashboard.php') $pageTitle = "Company Monitoring Dashboard";

// ============================================
// LIVE MONITORING HISTORY (page-load query)
// ============================================
$history = $conn->query("
    SELECT *,
        COALESCE(performed_by,'System') as performed_by_safe,
        COALESCE(account_type,'N/A') as account_type_safe,
        COALESCE(account_name,'N/A') as account_name_safe
    FROM activity_logs
    ORDER BY created_at DESC
");

$error   = "";
$success = "";

// ============================================
// NEW: Cascade-delete an account's related records across the DB
// ------------------------------------------------------------
// When a Student, Company, Faculty, or Admin account is deleted below,
// this also removes that account's associated rows from every other
// table in the system that references it, so deleting an account no
// longer leaves orphaned data scattered across the DB.
//
// ── UPDATED: the original version only cleaned up 4 tables (reports,
// ojt_assignments, admin_application_approvals, moa_requests) plus
// email_recovery_requests, which is why data from deleted accounts
// was still being left behind. It now covers EVERY table in the
// ojt_db schema that stores data tied to a student/company account:
//   - Student data : student_information, student_skills,
//       student_experience, requirements, attendance_logs,
//       late_requests, final_grades, ojt_applications,
//       application_requirements, ojt_reminder_log, archived_students
//   - Company data : company_information, company_profile,
//       company_requirements, company_messages, attendance_settings,
//       archived_companies
//   - Shared       : reports, ojt_assignments, admin_application_approvals,
//       moa_requests, activity_logs
//   - Any email_recovery_requests tied to the account's email
// Users' ids are unique across students and companies (both live in the
// `users` table), so each table is matched on every column that can hold
// the account's id (e.g. student_id OR company_id).
//
// Faculty / Admin accounts live in separate tables whose ids can collide
// with `users`.id, so for those roles ONLY the email-based cleanup runs —
// their ids are never matched against the student/company tables.
//
// Tables/columns are still checked for existence first (same defensive
// pattern as ensureAdminExtraColumns above), so this stays safe even if
// a particular deployment's schema differs slightly.
//
// The function is meant to be run inside a transaction (see the AJAX
// handler below). After deleting it re-checks every target and throws if
// any row belonging to the account is still there, which makes the
// handler roll everything back instead of leaving a half-deleted account.
// ============================================
function cascadeDeleteAccountRecords(mysqli $conn, int $id, string $email, string $role): void {
    if ($id <= 0) return;

    // table => columns that may hold this account's users.id
    $targets = [];
    if ($role === 'student' || $role === 'company') {
        // shared / activity
        $targets['reports']                     = ['user_id', 'company_id'];
        $targets['ojt_assignments']             = ['student_id', 'company_id'];
        $targets['admin_application_approvals'] = ['student_id', 'company_id'];
        $targets['moa_requests']                = ['user_id'];
        $targets['activity_logs']               = ['user_id'];
        // student side
        $targets['student_information']         = ['user_id'];
        $targets['student_skills']              = ['user_id'];
        $targets['student_experience']          = ['user_id'];
        $targets['requirements']                = ['user_id'];
        $targets['attendance_logs']             = ['user_id', 'company_id'];
        $targets['late_requests']               = ['student_id', 'company_id'];
        $targets['final_grades']                = ['student_id', 'company_id'];
        $targets['ojt_applications']            = ['student_id', 'company_id'];
        $targets['application_requirements']    = ['student_id', 'company_id'];
        $targets['ojt_reminder_log']            = ['user_id'];
        $targets['archived_students']           = ['user_id'];
        // company side
        $targets['company_information']         = ['user_id'];
        $targets['company_profile']             = ['user_id'];
        $targets['company_requirements']        = ['user_id'];
        $targets['company_requirement_upload_notifications'] = ['user_id'];   // NEW (this adjustment): Company Requirements inbox / side-menu notifications
        $targets['company_messages']            = ['company_id'];
        $targets['attendance_settings']         = ['company_id'];
        $targets['archived_companies']          = ['user_id'];
    }

    // Resolve which of the target tables/columns really exist in this DB.
    $existingCols = function (string $table) use ($conn): array {
        $cols = [];
        $stmt = $conn->prepare("SELECT COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?");
        if ($stmt) {
            $stmt->bind_param("s", $table);
            $stmt->execute();
            $stmt->bind_result($colName);
            while ($stmt->fetch()) $cols[] = $colName;
            $stmt->close();
        }
        return $cols;
    };

    $resolved = []; // table => [WHERE clause, ...]
    foreach ($targets as $table => $wantedCols) {
        $have  = $existingCols($table);
        if (empty($have)) continue; // table doesn't exist here
        $conds = [];
        foreach ($wantedCols as $col) {
            if (in_array($col, $have, true)) $conds[] = "`{$col}` = {$id}";
        }
        if (!empty($conds)) $resolved[$table] = implode(' OR ', $conds);
    }

    // Company chat messages are also keyed by the company's email address
    // (sender_email / receiver_email), so catch any that are tied to it.
    $messageEmailCleanup = ($role === 'company' && $email !== '' && isset($resolved['company_messages']));

    // ── Delete pass ──
    foreach ($resolved as $table => $where) {
        if (!$conn->query("DELETE FROM `{$table}` WHERE {$where}")) {
            throw new RuntimeException("Failed to delete records from {$table}: " . $conn->error);
        }
    }
    if ($messageEmailCleanup) {
        $stmt = $conn->prepare("DELETE FROM company_messages WHERE sender_email = ? OR receiver_email = ?");
        if (!$stmt) throw new RuntimeException("Failed to prepare company_messages cleanup: " . $conn->error);
        $stmt->bind_param("ss", $email, $email);
        $stmt->execute();
        $stmt->close();
    }

    // Any pending/past email-recovery requests tied to this account's email
    // (applies to every role, exactly as before).
    $recoveryExists = false;
    if ($email !== '') {
        $recoveryExists = !empty($existingCols('email_recovery_requests'));
        if ($recoveryExists) {
            $stmt = $conn->prepare("DELETE FROM email_recovery_requests WHERE old_email = ? OR new_email = ?");
            if (!$stmt) throw new RuntimeException("Failed to prepare email_recovery_requests cleanup: " . $conn->error);
            $stmt->bind_param("ss", $email, $email);
            $stmt->execute();
            $stmt->close();
        }
    }

    // ── Verification pass: make sure nothing belonging to the account is left ──
    $leftover = [];
    foreach ($resolved as $table => $where) {
        $chk = $conn->query("SELECT COUNT(*) AS c FROM `{$table}` WHERE {$where}");
        if ($chk) {
            $row = $chk->fetch_assoc();
            if ((int)($row['c'] ?? 0) > 0) $leftover[] = $table;
        }
    }
    if ($messageEmailCleanup) {
        $stmt = $conn->prepare("SELECT COUNT(*) FROM company_messages WHERE sender_email = ? OR receiver_email = ?");
        if ($stmt) {
            $stmt->bind_param("ss", $email, $email);
            $stmt->execute();
            $stmt->bind_result($cnt);
            $stmt->fetch();
            $stmt->close();
            if ((int)$cnt > 0) $leftover[] = 'company_messages (by email)';
        }
    }
    if ($recoveryExists) {
        $stmt = $conn->prepare("SELECT COUNT(*) FROM email_recovery_requests WHERE old_email = ? OR new_email = ?");
        if ($stmt) {
            $stmt->bind_param("ss", $email, $email);
            $stmt->execute();
            $stmt->bind_result($cnt2);
            $stmt->fetch();
            $stmt->close();
            if ((int)$cnt2 > 0) $leftover[] = 'email_recovery_requests';
        }
    }
    if (!empty($leftover)) {
        throw new RuntimeException("Records still remain in: " . implode(', ', $leftover));
    }
}

// ============================================
// NEW — AJAX: DELETE ACCOUNT
// ------------------------------------------------------------
// UPDATED: this used to be a plain $_GET['delete_id']/$_GET['role']
// link that navigated the browser to monitoring.php?success=deleted
// (a full page reload). It's now a POST-based AJAX endpoint that
// returns JSON so the row can be removed from the table instantly on
// the client without reloading the page. All the underlying logic
// (lookup full name, resolve table by role, delete row, write the
// activity log entry) is unchanged from the original.
//
// ── UPDATED: now also calls cascadeDeleteAccountRecords() before
// removing the account row itself, so related records in every other
// table that references the account (see the function above for the
// full list) are cleaned up too. ──
//
// ── UPDATED (this adjustment): the cascade + the account-row delete now
// run inside ONE database transaction and the cascade verifies that
// nothing is left behind. If anything fails, everything is rolled back
// and an error is returned — an account can no longer end up half
// deleted with data remaining in the DB. ──
// ============================================
if (isset($_POST['ajax_delete_account'])) {
    header('Content-Type: application/json');
    $del_id = intval($_POST['delete_id'] ?? 0);
    $role   = strtolower(trim($_POST['role'] ?? ''));

    switch ($role) {
        case 'admin':   $table = 'admins'; break;
        case 'faculty': $table = 'faculty'; break;
        case 'company':
        case 'student': $table = 'users'; break;
        default:        $table = ''; break;
    }

    if (!$table || $del_id <= 0) {
        echo json_encode(['success' => false, 'message' => 'Invalid request.']);
        exit;
    }

    $res = $conn->prepare("SELECT first_name, middle_name, last_name, email FROM $table WHERE id = ?");
    $res->bind_param("i", $del_id);
    $res->execute();
    $res->store_result();

    if ($res->num_rows > 0) {
        $res->bind_result($fn, $mn, $ln, $acc_email);
        $res->fetch();
        $acc_fullname = trim($fn . ' ' . ($mn ? $mn . ' ' : '') . $ln);
    } else {
        $acc_fullname = "Unknown";
        $acc_email    = '';
    }
    $res->close();

    // ── FIX: use a fallback of 'Admin' when session name is blank ──
    $performer_fn   = trim($_SESSION['first_name'] ?? '');
    $performer_ln   = trim($_SESSION['last_name']  ?? '');
    $performer_name = trim($performer_fn . ' ' . $performer_ln);
    if ($performer_name === '') $performer_name = 'Admin';

    $role_label = ucfirst($role ?? '');
    $details    = "$role_label account deleted for $acc_fullname";

    // ── NEW: capture the account's details BEFORE anything is deleted, so
    // the "Account Deleted" popup can show exactly which account was
    // removed. Best-effort only — a failure here never blocks the delete. ──
    $acct_extra_label = '';
    $acct_extra_value = '';
    try {
        $ex_stmt = null;
        if ($role === 'faculty') {
            $acct_extra_label = 'Department';
            $ex_stmt = $conn->prepare("SELECT department FROM faculty WHERE id = ?");
        } elseif ($role === 'student') {
            $acct_extra_label = 'Course';
            $ex_stmt = $conn->prepare("SELECT course FROM users WHERE id = ?");
        } elseif ($role === 'company') {
            $acct_extra_label = 'Company Name';
            $ex_stmt = $conn->prepare("SELECT COALESCE(NULLIF(ci.company, ''), u.company_name) FROM users u LEFT JOIN company_information ci ON ci.user_id = u.id WHERE u.id = ?");
        }
        if ($ex_stmt) {
            $ex_stmt->bind_param("i", $del_id);
            $ex_stmt->execute();
            $ex_stmt->bind_result($ex_val);
            if ($ex_stmt->fetch() && $ex_val !== null && trim((string)$ex_val) !== '') {
                $acct_extra_value = trim((string)$ex_val);
            }
            $ex_stmt->close();
        }
    } catch (Throwable $ignored) {}

    $account_details = [
        ['label' => 'Account Type', 'value' => $role_label],
        ['label' => 'Full Name',    'value' => $acc_fullname],
        ['label' => 'Email',        'value' => ((string)($acc_email ?? '')) !== '' ? (string)$acc_email : 'N/A'],
    ];
    if ($acct_extra_value !== '') {
        $account_details[] = ['label' => $acct_extra_label, 'value' => $acct_extra_value];
    }
    $account_details[] = ['label' => 'Account ID', 'value' => '#' . $del_id];
    $account_details[] = ['label' => 'Deleted By', 'value' => $performer_name];
    $account_details[] = ['label' => 'Deleted On', 'value' => date('M d, Y h:i A')];

    // ── NEW: remove this account's related records from every other
    // table that references it before deleting the account row itself.
    // UPDATED: all of it runs in a single transaction so it's all-or-nothing. ──
    try {
        $conn->begin_transaction();

        cascadeDeleteAccountRecords($conn, $del_id, (string)($acc_email ?? ''), $role);

        $del_stmt = $conn->prepare("DELETE FROM $table WHERE id = ?");
        $del_stmt->bind_param("i", $del_id);
        $del_stmt->execute();
        $del_stmt->close();

        // Confirm the account row itself is really gone.
        $verify_stmt = $conn->prepare("SELECT COUNT(*) FROM $table WHERE id = ?");
        $verify_stmt->bind_param("i", $del_id);
        $verify_stmt->execute();
        $verify_stmt->bind_result($still_there);
        $verify_stmt->fetch();
        $verify_stmt->close();
        if ((int)$still_there > 0) {
            throw new RuntimeException("Account row could not be removed from {$table}.");
        }

        $conn->commit();
    } catch (Throwable $e) {
        try { $conn->rollback(); } catch (Throwable $ignored) {}
        error_log('[monitoring.php] ajax_delete_account failed: ' . $e->getMessage());
        echo json_encode([
            'success' => false,
            'message' => 'Could not fully delete this account and its records, so nothing was deleted. Please try again.',
        ]);
        exit;
    }

    $log_stmt = $conn->prepare(
        "INSERT INTO activity_logs (action_type, performed_by, account_type, account_name, details, created_at)
         VALUES ('Account Deleted', ?, ?, ?, ?, NOW())"
    );
    $log_stmt->bind_param("ssss", $performer_name, $role_label, $acc_fullname, $details);
    $log_stmt->execute();
    $log_stmt->close();

    echo json_encode([
        'success' => true,
        'message' => "$role_label account deleted successfully!",
        'account_details' => $account_details,
        'log' => [
            'action_type'  => 'Account Deleted',
            'account_type' => $role_label,
            'account_name' => $acc_fullname,
            'performed_by' => $performer_name,
            'details'      => $details,
            'created_at'   => date('Y-m-d H:i:s'),
        ],
    ]);
    exit;
}

// ============================================
// CREATE ACCOUNT — FACULTY & COMPANY
// ------------------------------------------------------------
// REMOVED: Company (and Faculty/Student) account creation has been
// removed entirely per the updated requirements — the "Create
// Account" button now only ever creates Admin accounts, via the
// OTP-verified flow below. The account MANAGEMENT/listing/deletion
// of existing Company accounts (renderTable($companies,'company'))
// further down this file is untouched.
// ============================================

// ============================================
// NEW — AJAX: CREATE ADMIN ACCOUNT — AFTER OTP AUTH
// ------------------------------------------------------------
// UPDATED (multiple changes):
//   1. Converted from a normal form POST (which reloaded the whole
//      page and relied on PHP-set $success/$error to trigger a popup
//      on the next load) into a POST-based AJAX endpoint returning
//      JSON, so the modal can close and the new admin row can be
//      appended to the table instantly, with no page reload.
//   2. REMOVED the old "delete the previous admin after creating a
//      new one" behavior. Admins used to be a single replaceable
//      account; now multiple admin accounts can coexist side by side,
//      each independently Activated/Deactivated (see the
//      ajax_toggle_admin_status endpoint above), so the old admin is
//      no longer deleted when a new one is created.
//   3. (BUG FIX) If the confirmation email fails to send, the
//      temporary password is now included directly in the JSON
//      response message. Previously the response only said "Account
//      created, but email failed." with no way to actually retrieve
//      the password — meaning the brand-new admin account could be
//      created successfully yet be permanently unreachable if the
//      email never arrived. The creating admin can now read the
//      password straight off the success popup and relay it securely.
//   4. (BUG FIX — email delivery) This block now sends the new admin's
//      credentials email through the shared sendSystemEmail() helper
//      defined near the top of this file, instead of its own separate
//      PHPMailer block. That helper (a) uses the exact same verified
//      SMTP configuration as every other email this page sends, (b)
//      catches \Throwable instead of only PHPMailer's own Exception
//      class so a TLS/socket-level failure can never silently abort
//      the request before the JSON response is sent, (c) falls back
//      to the server's local mail() transport if the SMTP send fails
//      for any reason, and (d) guarantees — via the verified
//      single-recipient check inside sendSystemEmail() — that the
//      email can only ever be delivered to the one new admin's
//      address.
// All other validation, duplicate-email checking, and activity
// logging are otherwise unchanged from the original.
// ============================================
if (isset($_POST['ajax_create_admin_account'])) {
    header('Content-Type: application/json');

    if (empty($_SESSION['admin_create_authenticated'])) {
        echo json_encode(['success' => false, 'message' => 'Unauthorized. Please complete credential and OTP verification first.']);
        exit;
    }

    $first  = trim($_POST['new_admin_first_name'] ?? '');
    $middle = trim($_POST['new_admin_middle_name'] ?? '');
    $last   = trim($_POST['new_admin_last_name'] ?? '');
    $email  = trim($_POST['new_admin_email'] ?? '');

    // ── School information fields (Create Admin Account form) ──
    // "School" is either a value chosen from the campus_branch
    // dropdown (populated from student_import) or, when the admin
    // picks "Other (type below)", the free-text value the user typed.
    $school_select  = trim($_POST['new_admin_school'] ?? '');
    $school_other   = trim($_POST['new_admin_school_other'] ?? '');
    $school_final   = ($school_select === '__other__') ? $school_other : $school_select;
    $school_address = trim($_POST['new_admin_school_address'] ?? '');
    $subject        = trim($_POST['new_admin_subject'] ?? '');
    $contact_no     = preg_replace('/\D+/', '', trim($_POST['new_admin_contact_no'] ?? '')); // UPDATED: numbers only
    $required_hours = trim($_POST['new_admin_required_hours'] ?? ''); // field removed from the form — stays blank
    // UPDATED (this adjustment): first letter of each word capitalised (same as the form does while typing)
    $mon_cap = function ($s) {
        return preg_replace_callback('/(^|[\s\-\'])(\p{Ll})/u', function ($m) {
            return $m[1] . (function_exists('mb_strtoupper') ? mb_strtoupper($m[2], 'UTF-8') : strtoupper($m[2]));
        }, (string)$s);
    };
    foreach (['first', 'middle', 'last'] as $capVar) {
        if (isset($$capVar)) $$capVar = $mon_cap($$capVar);
    }
    // NOTE: "College" has been removed from the Create Admin Account form
    // entirely per the latest adjustment — it is no longer collected here
    // and no longer inserted below. The `college` column on `admins` is
    // left in place (ensureAdminExtraColumns) purely for backward
    // compatibility with any pre-existing data / other pages, but new
    // admin rows created from this form simply leave it NULL.

    if (empty($first) || empty($last) || empty($email)) {
        echo json_encode(['success' => false, 'message' => 'Please fill in all required fields.']);
        exit;
    }

    $tempPassword   = (string) rand(100000, 999999);
    $hashedPassword = password_hash($tempPassword, PASSWORD_DEFAULT);
    $full_name      = trim($first . ' ' . ($middle ? $middle . ' ' : '') . $last);
    $performer_name = trim(($_SESSION['first_name'] ?? '') . ' ' . ($_SESSION['last_name'] ?? ''));
    if (empty($performer_name)) $performer_name = 'System';

    $check = $conn->prepare("SELECT id FROM admins WHERE email=?");
    $check->bind_param("s", $email);
    $check->execute();
    $check->store_result();

    if ($check->num_rows > 0) {
        $check->close();
        echo json_encode(['success' => false, 'message' => 'Admin email already exists.']);
        exit;
    }
    $check->close();

    ensureAdminExtraColumns($conn);
    $stmt = $conn->prepare("INSERT INTO admins (first_name,middle_name,last_name,email,password,school,school_address,subject,contact_no,required_hours,is_active) VALUES (?,?,?,?,?,?,?,?,?,?,1)");
    $stmt->bind_param("ssssssssss", $first, $middle, $last, $email, $hashedPassword, $school_final, $school_address, $subject, $contact_no, $required_hours);

    if (!$stmt->execute()) {
        $stmt->close();
        unset($_SESSION['admin_create_authenticated'], $_SESSION['admin_create_verified_id'], $_SESSION['admin_create_verifier_email']);
        echo json_encode(['success' => false, 'message' => 'Database error.']);
        exit;
    }

    $new_admin_id = $stmt->insert_id;
    $stmt->close();

    $log_details = "Admin account created for $full_name";
    $log_stmt    = $conn->prepare(
        "INSERT INTO activity_logs (action_type, performed_by, account_type, account_name, details, created_at)
         VALUES ('Account Created', ?, 'Admin', ?, ?, NOW())"
    );
    $log_stmt->bind_param("sss", $performer_name, $full_name, $log_details);
    $log_stmt->execute();
    $log_stmt->close();

    // NOTE: the previous admin is intentionally NOT deleted here anymore —
    // multiple admin accounts are now supported side by side.

    // ── Send via the shared, \Throwable-safe, verified-single-recipient
    // sendSystemEmail() helper (SMTP + local mail() fallback + guaranteed
    // recipient isolation) instead of a standalone PHPMailer block, so a
    // failure here can never silently prevent the JSON response from being
    // sent, a transient SMTP hiccup doesn't mean the new admin never gets
    // their credentials at all, and the credentials are structurally
    // guaranteed to only ever reach this one new admin's inbox. ──
    [$emailSent, $mailError] = sendSystemEmail(
        $email,
        'Your New Admin Account Credentials',
        // UPDATED (this adjustment): simple one-section email — same account info + password
        mon_email_template([
            'layout'      => 'simple',
            'name'        => $first,
            'paragraphs'  => ['An <strong>Administrator</strong> account has been created for you on the NEUST OJT Portal. You can sign in with these details:'],
            'lines'       => [
                ['Email',              $email],
                ['Password',           $tempPassword], // UPDATED: not a temporary password
            ],
            'after'       => ['Please keep your password private and do not share it with anyone.'],
            'button_url'  => mon_portal_url('admin_login.php'),
            'button_text' => 'Log In to OJT Portal',
        ]),
        $first
    );

    unset($_SESSION['admin_create_authenticated'], $_SESSION['admin_create_verified_id'], $_SESSION['admin_create_verifier_email']);

    // ── BUG FIX: if the email failed to send, surface the temp password
    // directly in the response instead of leaving the new admin with no
    // way to ever learn it. (See the block comment above this endpoint.) ──
    $responseMessage = $emailSent
        // UPDATED (this adjustment): the email carries the login details (it is not a
        // "confirmation" email), so the popup now says that simply and correctly.
        ? 'The admin account was created. The login details were sent to ' . $email . '.'
        : "The admin account was created, but the login details could not be emailed. Password: {$tempPassword} — please give it to the new admin securely.";

    echo json_encode([
        'success' => true,
        'message' => $responseMessage,
        'admin'   => [
            'id'        => (int)$new_admin_id,
            'full_name' => $full_name,
            'email'     => $email,
            'is_active' => 1,
        ],
        'log' => [
            'action_type'  => 'Account Created',
            'account_type' => 'Admin',
            'account_name' => $full_name,
            'performed_by' => $performer_name,
            'details'      => $log_details,
            'created_at'   => date('Y-m-d H:i:s'),
        ],
    ]);
    exit;
}

// ============================================
// NEW (Recovery request history): "history_cleared" flag
// ------------------------------------------------------------
// Accepted / Rejected requests now live in the drawer's History tab.
// "Clear History" only HIDES them from that tab (history_cleared = 1) —
// the rows themselves are kept, so the one-request-per-student rule on
// login.php and every accept/reject record stay intact.
// ============================================
function mon_ensure_recovery_history_column($conn) {
    static $done = false;
    if ($done) return;
    $done = true;
    try {
        $col = $conn->query("SHOW COLUMNS FROM email_recovery_requests LIKE 'history_cleared'");
        if ($col && $col->num_rows === 0) {
            $conn->query("ALTER TABLE email_recovery_requests ADD COLUMN history_cleared TINYINT(1) NOT NULL DEFAULT 0");
        }
    } catch (\Throwable $e) { /* column stays missing — history simply can't be cleared */ }
}

// ============================================
// NEW: CLEAR EMAIL RECOVERY REQUEST HISTORY (AJAX)
// ============================================
if (isset($_POST['ajax_clear_recovery_history'])) {
    header('Content-Type: application/json');
    cv_ensure_email_recovery_requests_table($conn);   // CLEAN-UP (audit): shared definition, see cv_ensure_email_recovery_requests_table()
    mon_ensure_recovery_history_column($conn);
    $ok = false; $cleared = 0;
    try {
        $ok = (bool)$conn->query("UPDATE email_recovery_requests
                                  SET history_cleared = 1
                                  WHERE NOT (status IS NULL OR TRIM(status) = '' OR LOWER(TRIM(status)) = 'pending') AND history_cleared = 0");
        $cleared = $ok ? (int)$conn->affected_rows : 0;
    } catch (\Throwable $e) { $ok = false; }
    echo json_encode([
        'success' => $ok,
        'cleared' => $cleared,
        'message' => $ok ? ($cleared . ' request' . ($cleared === 1 ? '' : 's') . ' cleared from the history.')
                         : 'The history could not be cleared. Please try again.',
    ]);
    exit;
}

// ============================================
// ACCEPT EMAIL RECOVERY REQUEST
// ============================================
if (isset($_POST['ajax_accept_recovery'])) {
    header('Content-Type: application/json');
    $req_id = intval($_POST['req_id'] ?? 0);
    if ($req_id <= 0) { echo json_encode(['success'=>false,'message'=>'Invalid request.']); exit; }

    cv_ensure_email_recovery_requests_table($conn);   // CLEAN-UP (audit): shared definition, see cv_ensure_email_recovery_requests_table()

    $qr = $conn->prepare("SELECT * FROM email_recovery_requests WHERE id=? AND (status IS NULL OR TRIM(status) = '' OR LOWER(TRIM(status)) = 'pending')");
    $qr->bind_param("i", $req_id); $qr->execute();
    $req = $qr->get_result()->fetch_assoc(); $qr->close();

    if (!$req) { echo json_encode(['success'=>false,'message'=>'Request not found or already processed.']); exit; }

    $role_cond  = ($req['account_type'] === 'company') ? "AND role='company'" : "AND role='student'";
    $old_email  = $req['old_email'];
    $new_email  = $req['new_email'];
    $newPass    = (string) rand(100000, 999999);
    $hashedPass = password_hash($newPass, PASSWORD_DEFAULT);

    $upd = $conn->prepare("UPDATE users SET email=?, password=? WHERE email=? $role_cond");
    $upd->bind_param("sss", $new_email, $hashedPass, $old_email);
    $upd->execute();
    $affected = $upd->affected_rows;
    $upd->close();

    // NEW (this adjustment): counts how many account rows were actually changed,
    // so the request is only marked Accepted — and "Account Updated" is only
    // logged — when the requested email change was really carried out.
    $accountsUpdated = max(0, (int)$affected);

    if ($affected === 0) {
        $upd2 = $conn->prepare("UPDATE admins SET email=?, password=? WHERE email=?");
        $upd2->bind_param("sss", $new_email, $hashedPass, $old_email);
        $upd2->execute();
        $accountsUpdated += max(0, (int)$upd2->affected_rows);
        if ($upd2->affected_rows === 0) {
            $upd3 = $conn->prepare("UPDATE faculty SET email=?, password=? WHERE email=?");
            $upd3->bind_param("sss", $new_email, $hashedPass, $old_email);
            $upd3->execute();
            $accountsUpdated += max(0, (int)$upd3->affected_rows);
            $upd3->close();
        }
        $upd2->close();
    }

    // NEW (this adjustment): no account had the old email, so nothing was changed —
    // the request stays Pending, nothing is logged and no new password is emailed.
    if ($accountsUpdated === 0) {
        echo json_encode([
            'success' => false,
            'message' => "No account with the email $old_email was found, so nothing was changed. The request is still pending.",
        ]);
        exit;
    }

    $upd4 = $conn->prepare("UPDATE email_recovery_requests SET status='Accepted' WHERE id=?");
    $upd4->bind_param("i", $req_id); $upd4->execute(); $upd4->close();

    $performer_name = trim(($_SESSION['first_name'] ?? '') . ' ' . ($_SESSION['last_name'] ?? ''));
    if (empty(trim($performer_name))) $performer_name = 'Admin';
    $fullName    = trim($req['first_name'] . ' ' . ($req['middle_name'] ? $req['middle_name'].' ' : '') . $req['last_name']);
    $acc_type    = ucfirst($req['account_type'] ?? '');
    $log_details = "Email recovery request accepted — email changed from $old_email to $new_email and a new password was issued";

    $log_stmt = $conn->prepare(
        "INSERT INTO activity_logs (action_type, performed_by, account_type, account_name, details, created_at)
         VALUES ('Account Updated', ?, ?, ?, ?, NOW())"
    );
    $log_stmt->bind_param("ssss", $performer_name, $acc_type, $fullName, $log_details);
    $log_stmt->execute();
    $log_stmt->close();

    // ── This notification goes through the shared sendSystemEmail()
    // helper (see its definition near the top of this file), so it gets
    // the exact same \Throwable-safe error handling, local mail()
    // fallback, error_log() diagnostics, and verified single-recipient
    // guarantee as every other automated email on this page — this email
    // can only ever reach $new_email. ──
    // UPDATED (this adjustment): clearer, more informative wording — says when the
    // request was sent, exactly what changed, and what to do next.
    $reqSubmittedOn = !empty($req['submitted_at']) ? date('F j, Y \\a\\t g:i A', strtotime($req['submitted_at'] ?? '')) : '';
    $acceptEmailBody = mon_email_template([
        'theme'           => 'success',
        'band_label'      => 'Request Approved',
        'band_headline'   => 'Your account email has been updated.',
        'name'            => $fullName,
        'paragraphs'      => [
            'We reviewed your email recovery request' . ($reqSubmittedOn !== '' ? ' from <strong>' . htmlspecialchars($reqSubmittedOn ?? '') . '</strong>' : '')
            . ' and approved it. Your account now signs in with the new email address below, and a new temporary password has been set for you.',
        ],
        'highlight_label' => 'Your New Login Details',
        'highlight_rows'  => [
            ['label' => 'Email',              'value' => $new_email],
            ['label' => 'Temporary Password', 'value' => $newPass],
        ],
        'after'           => [
            '<strong>Next step:</strong> log in with these details and change your temporary password right away.',
            'Your previous email (<strong>' . htmlspecialchars($old_email ?? '') . '</strong>) can no longer be used to sign in. If you did not ask for this change, contact the administrator immediately.',
        ],
        'detail_label'    => 'Request',
        'detail_value'    => 'Email Recovery',
        'detail_rows'     => array_values(array_filter([
            $reqSubmittedOn !== '' ? ['Submitted', $reqSubmittedOn] : null,
            ['Approved', date('F j, Y \\a\\t g:i A')],
            ['Previous Email', $old_email],
            ['New Email', $new_email],
        ])),
        'button_url'      => mon_portal_url(($req['account_type'] ?? '') === 'company' ? 'company_login.php' : 'login.php'),
        'button_text'     => 'Log In to OJT Portal',
    ]);
    [$recoveryEmailSent, $recoveryMailError] = sendSystemEmail(
        $new_email,
        'Email Recovery Approved - NEUST OJT Portal',
        $acceptEmailBody,
        $fullName
    );

    // ── BUG FIX: if the notification email fails to send, surface the
    // new password directly in the response (mirrors the same safety net
    // already used for the new-admin-credentials email above), so the
    // account is never left with a changed password that nobody can
    // retrieve. ──
    $acceptResponseMessage = $recoveryEmailSent
        ? 'Request accepted. Email and password updated. A confirmation email has been sent to ' . $new_email . '.'
        : "Request accepted. Email and password updated, but the confirmation email failed to send. New password: {$newPass} — please share this with the user securely, since it was not emailed automatically.";

    echo json_encode([
        'success' => true,
        'message' => $acceptResponseMessage,
        'log' => [
            'action_type'  => 'Account Updated',
            'account_type' => $acc_type,
            'account_name' => $fullName,
            'performed_by' => $performer_name,
            'details'      => $log_details,
            'created_at'   => date('Y-m-d H:i:s'),
        ],
    ]);
    exit;
}

// ============================================
// REJECT EMAIL RECOVERY REQUEST
// ============================================
if (isset($_POST['ajax_reject_recovery'])) {
    header('Content-Type: application/json');
    $req_id        = intval($_POST['req_id']      ?? 0);
    $reject_reason = trim($_POST['reject_reason'] ?? '');

    if ($req_id <= 0) { echo json_encode(['success'=>false,'message'=>'Invalid request.']); exit; }

    cv_ensure_email_recovery_requests_table($conn);   // CLEAN-UP (audit): shared definition, see cv_ensure_email_recovery_requests_table()

    $qr = $conn->prepare("SELECT * FROM email_recovery_requests WHERE id=? AND (status IS NULL OR TRIM(status) = '' OR LOWER(TRIM(status)) = 'pending')");
    $qr->bind_param("i", $req_id); $qr->execute();
    $req = $qr->get_result()->fetch_assoc(); $qr->close();

    if (!$req) { echo json_encode(['success'=>false,'message'=>'Request not found or already processed.']); exit; }

    $upd = $conn->prepare("UPDATE email_recovery_requests SET status='Rejected' WHERE id=?");
    $upd->bind_param("i", $req_id); $upd->execute(); $upd->close();

    $performer_name = trim(($_SESSION['first_name'] ?? '') . ' ' . ($_SESSION['last_name'] ?? ''));
    if (empty(trim($performer_name))) $performer_name = 'Admin';
    $fullName    = trim($req['first_name'] . ' ' . ($req['middle_name'] ? $req['middle_name'].' ' : '') . $req['last_name']);
    $acc_type    = ucfirst($req['account_type'] ?? '');
    $old_email   = $req['old_email'];
    // UPDATED (this adjustment): a rejected request changes no account, so it is no
    // longer logged as "Account Updated" — it gets its own "Request Rejected" entry.
    $log_details = "Email recovery request rejected for $old_email" . ($reject_reason !== '' ? " — Reason: $reject_reason" : '');

    $log_stmt = $conn->prepare(
        "INSERT INTO activity_logs (action_type, performed_by, account_type, account_name, details, created_at)
         VALUES ('Request Rejected', ?, ?, ?, ?, NOW())"
    );
    $log_stmt->bind_param("ssss", $performer_name, $acc_type, $fullName, $log_details);
    $log_stmt->execute();
    $log_stmt->close();

    // ── Same fix as the Accept endpoint above — this notification goes
    // through the shared sendSystemEmail() helper, so a failed send is
    // logged and retried via the local mail() fallback, and this email is
    // guaranteed (verified single-recipient check) to only ever reach
    // $req['new_email']. ──
    // UPDATED (this adjustment): clearer, more informative wording — explains that
    // nothing on the account changed, why it was rejected, and how to try again.
    $reqSubmittedOn = !empty($req['submitted_at']) ? date('F j, Y \\a\\t g:i A', strtotime($req['submitted_at'] ?? '')) : '';
    $recordWord     = ($req['account_type'] ?? '') === 'company' ? 'company account' : 'student record';
    $rejectTemplate = [
        'theme'           => 'danger',
        'band_label'      => 'Request Rejected',
        'band_headline'   => 'We could not approve your email recovery request.',
        'name'            => $fullName,
        'paragraphs'      => [
            'We reviewed your email recovery request' . ($reqSubmittedOn !== '' ? ' from <strong>' . htmlspecialchars($reqSubmittedOn ?? '') . '</strong>' : '')
            . ' and could not approve it. <strong>No changes were made to your account</strong> — it still uses its current email address and password.',
        ],
        'after'           => [
            '<strong>What you can do:</strong> make sure every detail matches your ' . $recordWord . ' exactly and that your live selfie is clear, then submit a new request from the login page.',
            'If you need help, contact your OJT coordinator or the administrator.',
        ],
        'detail_label'    => 'Request',
        'detail_value'    => 'Email Recovery',
        'detail_rows'     => array_values(array_filter([
            $reqSubmittedOn !== '' ? ['Submitted', $reqSubmittedOn] : null,
            ['Reviewed', date('F j, Y \\a\\t g:i A')],
            ['Registered Email', (string)$old_email],
            ['Requested New Email', (string)($req['new_email'] ?? '')],
        ])),
    ];
    // UPDATED (this adjustment): the "Reason for Rejection" section is only included
    // when the admin actually typed a reason — otherwise it is left out entirely.
    if (trim((string)$reject_reason) !== '') {
        $rejectTemplate['highlight_label'] = 'Reason for Rejection';
        $rejectTemplate['highlight_rows']  = [['value' => $reject_reason]];
    }
    $rejectEmailBody = mon_email_template($rejectTemplate);
    [$rejectEmailSent, $rejectMailError] = sendSystemEmail(
        $req['new_email'],
        'Email Recovery Request Rejected - NEUST OJT Portal',
        $rejectEmailBody,
        $fullName
    );

    $rejectResponseMessage = $rejectEmailSent
        ? 'Request rejected and notification sent.'
        : 'Request rejected, but the notification email failed to send. The requester was not notified automatically.';

    echo json_encode([
        'success' => true,
        'message' => $rejectResponseMessage,
        'log' => [
            'action_type'  => 'Request Rejected',
            'account_type' => $acc_type,
            'account_name' => $fullName,
            'performed_by' => $performer_name,
            'details'      => $log_details,
            'created_at'   => date('Y-m-d H:i:s'),
        ],
    ]);
    exit;
}

// ============================================
// FETCH ACCOUNTS & RENDER (LOGIC UNTOUCHED, except admins now also
// fetch is_active so the Activate/Deactivate button can reflect
// each admin's current status)
// ============================================
$admins    = $conn->query("SELECT id, first_name, middle_name, last_name, email, is_active FROM admins");
$faculty   = $conn->query("SELECT id, first_name, middle_name, last_name, email, department, is_active FROM faculty");

// ── UPDATED (this adjustment): the Company table's "Company Name" column
// was showing blank because `users.company_name` is never actually
// populated anywhere in this codebase. The real company name is stored
// in `company_information.company` (the same table/column
// company_validation.php reads from). This now LEFT JOINs
// company_information and prefers `ci.company`, falling back to the
// (usually empty) `users.company_name` only if company_information has
// no row / no value for that user yet — so nothing breaks for a company
// account that hasn't filled out their company_information profile yet.
// Every other column, the WHERE clause, and all downstream rendering
// logic (renderTable()) are completely unchanged. ──
// ── UPDATED (this adjustment): the Company table no longer shows the Email
// column, and a "Company Type" column now sits between Company Name and
// Status. The type is read from whichever company-type column this
// deployment has (company_information first, then users); if none exists
// the column simply shows "—". Only the SELECTed columns changed — the
// JOIN, WHERE clause and the row id / is_active used by the Status and
// Delete buttons are the same as before. ──
function mon_company_type_expr(mysqli $conn): string {
    /* FIX (this adjustment — some Company Types came back empty): this used
       to read ONLY the first company-type column it found on
       company_information. admin_company_list.php stores / shows a
       registered company's type on users.company_type (company_information
       .company_type is only an older fallback), so most companies read as
       empty here. It now reads the same place admin_company_list.php does —
       users.company_type first — then falls back to
       company_information.company_type (and any other type column this
       deployment may have), trims it, and writes "public" / "PRIVATE"
       etc. as "Public" / "Private" so every company shows its type and the
       Company Type filter lists each type once. */
    $cols = function (string $table) use ($conn): array {
        $out = [];
        try {
            $res = $conn->query("SHOW COLUMNS FROM `" . $table . "`");
            if ($res) while ($c = $res->fetch_assoc()) $out[strtolower($c['Field'])] = $c['Field'];
        } catch (\Throwable $e) { $out = []; }
        return $out;
    };
    $parts = [];
    $u = $cols('users');
    foreach (['company_type', 'type_of_company', 'company_classification'] as $c) {
        if (isset($u[$c])) $parts[] = "NULLIF(TRIM(CONVERT(u.`" . $u[$c] . "` USING utf8mb4) COLLATE utf8mb4_general_ci), '')";
    }
    $ci = $cols('company_information');
    foreach (['company_type', 'type_of_company', 'company_classification', 'classification',
              'ownership_type', 'ownership', 'business_type', 'company_category', 'type'] as $c) {
        if (isset($ci[$c])) $parts[] = "NULLIF(TRIM(CONVERT(ci.`" . $ci[$c] . "` USING utf8mb4) COLLATE utf8mb4_general_ci), '')";
    }
    if (!$parts) return "NULL";
    $raw = count($parts) === 1 ? $parts[0] : "COALESCE(" . implode(', ', $parts) . ")";
    return "(CASE WHEN LOWER($raw) = 'public' THEN 'Public'
                  WHEN LOWER($raw) = 'private' THEN 'Private'
                  ELSE $raw END)";
}
$companyTypeExpr = mon_company_type_expr($conn);

$companies = $conn->query("
    SELECT u.id, u.first_name, u.middle_name, u.last_name,
           COALESCE(NULLIF(ci.company, ''), u.company_name) AS company_name,
           COALESCE($companyTypeExpr, '') AS company_type,
           u.is_active
    FROM users u
    LEFT JOIN company_information ci ON ci.user_id = u.id
    WHERE u.role = 'company'
");

$students  = $conn->query("SELECT id, first_name, middle_name, last_name, email, course, is_active FROM users WHERE role='student'");

// ── CHANGE 1 & 2: Removed Export Excel button and Edit link from renderTable.
//    All other logic, filtering, column rendering, delete link, and table structure are untouched.
// ── UPDATED: admin rows now render an Activate/Deactivate toggle button
//    (driven by is_active) instead of the old static "—" placeholder, and
//    the Delete action for non-admin roles is now a button wired up for
//    AJAX deletion (see confirmDelete()/proceedDelete() in the script
//    below) instead of a navigating <a href> link.
// ── UPDATED: both the admin status-toggle button and the delete button
//    now render a `title` attribute (native tooltip) explaining exactly
//    what the click will do, and the admin toggle button also gets an
//    icon so it reads more clearly at a glance (see CSS for the more
//    noticeable button styling). ──
// ── ADJUSTMENT (this update): the Status button's visible label/icon now
//    reflects the account's CURRENT state ("Active" / "Deactivated")
//    instead of the action clicking it would perform ("Activate" /
//    "Deactivate"). The native `title` tooltip still explains the action
//    that will be taken on click, so nothing about what happens when the
//    button is pressed has changed — only the label text/icon shown on
//    the button itself. The Delete button is now also rendered as a pill
//    button that matches the same visual design language as the
//    Status button (specifically the red "Deactivated" styling), instead
//    of a plain text link. ──
// ── ADJUSTMENT (design-refresh pass): each row's first cell now also
//    carries a thin colored left-border accent reflecting the account's
//    CURRENT status (green while Active, red while Deactivated), matching
//    the "Field ops grid" reference layout. This is purely a decorative
//    style attribute on the first <td> — no column, data, or behavior was
//    added/removed/reordered. ──
function renderTable($result, $role) {
    if ($result->num_rows > 0) {
        echo "<div class='table-section' id='section-$role'>";
        echo "<div style='display:flex; justify-content:space-between; align-items:center; margin-bottom:20px;'>";
        echo "<h3>".ucfirst($role ?? '')." Accounts</h3>";
        echo "<div style='display:flex; gap:10px; align-items:center;'>";
            // Export button removed for all roles
            // ── FIX (search-bar autofill): the "Filter Name..." input below now
            // uses a readonly-until-focus trick (readonly on load, removed on
            // the field's own focus event) plus a stack of anti-autofill
            // attributes (autocomplete="off", autocorrect/autocapitalize off,
            // spellcheck off, data-lpignore, data-form-type="other"). Browsers
            // will not autofill a field that is readonly at page-load time, so
            // this reliably stops values like a saved email address from
            // silently appearing in the box on refresh — the field still opens
            // up for normal typing the instant the user clicks/tabs into it,
            // and filterTable()/oninput behavior is completely unchanged.
            if($role != 'admin') echo "<input type='text' id='filter-name-$role' name='filter-name-$role' placeholder='Filter Name...' autocomplete='off' autocorrect='off' autocapitalize='off' spellcheck='false' data-lpignore='true' data-form-type='other' value='' readonly onfocus='this.removeAttribute(\"readonly\")' oninput='filterTable(\"$role\", 0, this.value)' class='filter-input'>";
            if($role == 'faculty') echo "<select id='filter-dept-$role' name='filter-dept-$role' autocomplete='off' onchange='filterTable(\"$role\", 2, this.value)' class='filter-input'><option value=''>All Departments</option><option value='Bachelor of Science in Information Technology'>BS Information Technology</option><option value='Bachelor of Science in Business Administration major in Entrepreneurship'>BS Entrepreneurship</option><option value='Bachelor of Science in Business Administration'>BS Business Administration</option></select>";
            // ── UPDATED: the "Company Type" (Public/Private) dropdown filter was
            // removed — the Company table now shows Company Name instead of
            // Company Type, so a fixed Public/Private dropdown no longer applies.
            // The existing "Filter Name..." text input above already covers
            // free-text filtering for the company table.
            // ── NEW (this adjustment): Company Type dropdown filter for the Company
            // table — options are the company types actually present in the
            // table (column index 2 = Company Type, exact match like the other
            // dropdown filters). Accounts with no type show "—" / "Not Set".
            if ($role == 'company') {
                $companyTypeOptions = [];
                $hasNoType = false;
                $result->data_seek(0);
                while ($typeRow = $result->fetch_assoc()) {
                    $t = trim((string)($typeRow['company_type'] ?? ''));
                    if ($t === '') { $hasNoType = true; continue; }
                    $companyTypeOptions[strtolower($t)] = $t;
                }
                $result->data_seek(0);
                natcasesort($companyTypeOptions);
                echo "<select id='filter-type-$role' name='filter-type-$role' autocomplete='off' onchange='filterTable(\"$role\", 2, this.value)' class='filter-input'><option value=''>All Company Types</option>";
                foreach ($companyTypeOptions as $t) echo "<option value='" . htmlspecialchars($t ?? '', ENT_QUOTES) . "'>" . htmlspecialchars($t ?? '') . "</option>";
                if ($hasNoType) echo "<option value='—'>Not Set</option>";
                echo "</select>";
            }
            if($role == 'student') echo "<select id='filter-course-$role' name='filter-course-$role' autocomplete='off' onchange='filterTable(\"$role\", 2, this.value)' class='filter-input'><option value=''>All Courses</option><option value='Bachelor of Science in Information Technology'>BS Information Technology</option><option value='Bachelor of Science in Business Administration major in Entrepreneurship'>BS Business Administration major in Entrepreneurship</option><option value='Bachelor of Science in Business Administration'>BS Business Administration</option></select>";
        echo "</div></div>";

        echo "<table id='table-$role'><thead><tr>";
        $firstRow = $result->fetch_assoc();
        $columns  = array_keys($firstRow);
        $displayColumns = [];
        foreach($columns as $col) {
            if ($col === 'id' || $col === 'is_active') continue;
            if (in_array($col, ['first_name','middle_name','last_name'])) {
                if (!in_array('Full Name',$displayColumns)) $displayColumns[] = 'Full Name';
            } else $displayColumns[] = $col;
        }
        foreach($displayColumns as $col) echo "<th>".ucwords(str_replace('_',' ',$col))."</th>";
        // ── UPDATED: a dedicated "Status" column (Activate/Deactivate) now sits
        // beside "Actions" for every role's table, including Admin — previously
        // the admin table only had a single "Actions" column that doubled as
        // the status toggle, and Faculty/Company/Student had no status control
        // at all. ──
        echo "<th>Status</th><th>Actions</th></tr></thead><tbody>";
        $result->data_seek(0);
        while ($row = $result->fetch_assoc()) {
            // ── Compute status ahead of the row so the first cell's accent
            // border can reflect it (design-refresh only; value/usage below
            // is identical to before). ──
            $isActive  = isset($row['is_active']) ? (int)$row['is_active'] : 1;
            $rowAccent = $isActive ? '#2C5A2C' : '#A02A2A';

            echo "<tr>";
            $firstCell = true;
            foreach($displayColumns as $col) {
                // UPDATED (this adjustment): the coloured left-side accent on the first
                // cell was removed entirely — no inline style is added any more.
                $cellStyle = '';
                if ($col === 'Full Name') {
                    $fullName = $row['first_name'] . ($row['middle_name'] ? " ".$row['middle_name'] : "") . " " . $row['last_name'];
                    echo "<td$cellStyle>".htmlspecialchars($fullName ?? '')."</td>";
                } elseif ($col === 'company_type') {
                    // NEW (this adjustment): blank company type shows "—"
                    $typeVal = trim((string)($row[$col] ?? ''));
                    echo "<td$cellStyle>".htmlspecialchars($typeVal !== '' ? $typeVal : '—')."</td>";
                } else { echo "<td$cellStyle>".htmlspecialchars($row[$col] ?? '')."</td>"; }
                $firstCell = false;
            }

            // ── Status column: shows the account's CURRENT status
            // ("Active" / "Deactivated") for every role (admin, faculty,
            // company, student) the same way. Clicking it still toggles
            // the status — the tooltip (title attribute) explains the
            // action that will happen on click.
            // ── UPDATED (adjustment): default/initial state of every account
            // is Active (is_active defaults to 1 in the DB for every table),
            // so the button correctly starts in the "Active" state — this is
            // unchanged from before, just confirmed here since it's part of
            // this adjustment's scope. ──
            $btnClass  = $isActive ? 'status-btn-active' : 'status-btn-inactive';
            $btnLabel  = $isActive ? 'Active' : 'Deactivated';
            $btnIcon   = $isActive ? 'user-check' : 'user-slash';
            $roleLabelForTitle = ($role === 'admin') ? 'admin' : 'account';
            $btnTitle  = $isActive
                ? "Deactivate this $roleLabelForTitle — they will immediately lose the ability to log in."
                : "Activate this $roleLabelForTitle — they will be able to log in again.";
            echo "<td><button type='button' class='status-toggle-btn $btnClass' data-active='$isActive' title='".htmlspecialchars($btnTitle ?? '')."' onclick=\"toggleAccountStatus('$role', {$row['id']}, this)\"><i class='fas fa-$btnIcon'></i> $btnLabel</button></td>";

            // ── UPDATED (adjustment): the Actions column now renders a working
            // Delete button for EVERY role, including Admin — previously Admin
            // rows showed a static "—" placeholder here. The delete flow itself
            // (confirmDelete() -> AJAX ajax_delete_account endpoint) already
            // fully supported the 'admin' role/table, it just wasn't exposed in
            // this column yet, so no backend changes were needed, only this
            // rendering change.
            // ── UPDATED (this adjustment): the Delete button is now a pill
            // button using the same visual design as the Status button (the
            // "delete-btn-pill" class mirrors the red "Deactivated" status
            // styling), instead of a plain text link. ──
            $deleteBtn = "<button type='button' class='delete-btn-pill' data-id='{$row['id']}' data-role='$role' title='Permanently delete this account and all of its related records.' onclick='confirmDelete(this)'><i class='fas fa-trash'></i> Delete</button>";
            echo "<td>$deleteBtn</td>";
            echo "</tr>";
        }
        echo "</tbody></table></div>";
    }
}

// ── Ensure email_recovery_requests table exists & fetch requests ──────────────
cv_ensure_email_recovery_requests_table($conn);   // CLEAN-UP (audit): shared definition, see cv_ensure_email_recovery_requests_table()
$recovery_requests = $conn->query("SELECT * FROM email_recovery_requests ORDER BY submitted_at DESC");

$pending_count = 0;
$req_rows = [];
if ($recovery_requests && $recovery_requests->num_rows > 0) {
    while ($req = $recovery_requests->fetch_assoc()) {
        $req_rows[] = $req;
        if ($req['status'] === 'Pending') $pending_count++;
    }
}

// ── FIX (this adjustment — fresh requests went straight to History): the list
// used an exact  status === 'Pending'  check, so a brand-new request whose
// status was saved as 'pending', 'Pending ' (extra space), blank or NULL
// (e.g. by a table created with a different default) was treated as
// processed and dropped into History. Status is now compared after trimming
// and ignoring letter case, and blank/NULL counts as Pending — the same rule
// is used by the SQL checks (badge count, live list, accept, reject, clear).
function mon_req_status_key($status) {
    $s = strtolower(trim((string)$status));
    return $s === '' ? 'pending' : $s;
}
$pending_count = 0;
foreach ($req_rows as $req) {
    if (mon_req_status_key($req['status'] ?? '') === 'pending') $pending_count++;
}

// ── NEW (Recovery request history): Pending requests stay in the main list;
// Accepted / Rejected ones go to the History tab (unless cleared). ──
mon_ensure_recovery_history_column($conn);
$req_pending_rows = [];
$req_history_rows = [];
foreach ($req_rows as $req) {
    if (mon_req_status_key($req['status'] ?? '') === 'pending') {
        $req_pending_rows[] = $req;
    } elseif (empty($req['history_cleared'])) {
        $req_history_rows[] = $req;
    }
}

// Renders one request card — the exact same card markup as before, shared by
// the Pending list and the History list.
function mon_render_req_card($req) {
    $fullName   = trim($req['first_name'] . ' ' . ($req['middle_name'] ? $req['middle_name'].' ' : '') . $req['last_name']);
    $isAccepted = (mon_req_status_key($req['status'] ?? '') === 'accepted');
    $isRejected = (mon_req_status_key($req['status'] ?? '') === 'rejected');
    $cardCls    = $isAccepted ? 'req-card accepted' : ($isRejected ? 'req-card rejected' : 'req-card');

    // UPDATED (this adjustment): the "extra info" string (e.g. "Course: … | Major: … |
    // Section: … | Campus: …") is split into labelled fields instead of one run-on line.
    $fields = [];
    foreach (explode('|', (string)($req['extra_info'] ?? '')) as $part) {
        $part = trim($part);
        if ($part === '') continue;
        if (preg_match('/^([^:]{1,40}):\s*(.*)$/u', $part, $m)) {
            $fields[] = [trim($m[1]), trim($m[2])];
        } else {
            $fields[] = [($req['account_type'] === 'company') ? 'Details' : 'Company', $part];
        }
    }
    ?>
            <div class="<?= $cardCls ?>" id="reqCard<?= $req['id'] ?>">
                <div class="req-card-head">
                    <?php if (!empty($req['selfie_blob'])): ?>
                        <img class="req-selfie" src="data:image/jpeg;base64,<?= base64_encode($req['selfie_blob']) ?>" alt="Selfie">
                    <?php else: ?>
                        <div class="req-selfie-placeholder"><i class="fas fa-user"></i></div>
                    <?php endif; ?>
                    <div class="req-head-info">
                        <div class="req-name-row">
                            <div class="req-name"><?= htmlspecialchars($fullName ?? '') ?></div>
                            <!-- UPDATED (this adjustment): Accept / Reject are icon buttons in the
                                 top-right corner, level with the name; in History the Accepted /
                                 Rejected status sits in the same spot. Same ids / onclick handlers. -->
                            <div class="req-head-actions">
                                <?php if ($isAccepted): ?>
                                    <div class="req-accepted-tag" title="Accepted"><i class="fas fa-check-circle"></i> Accepted</div>
                                <?php elseif ($isRejected): ?>
                                    <div class="req-rejected-tag" title="Rejected"><i class="fas fa-times-circle"></i> Rejected</div>
                                <?php else: ?>
                                    <div class="req-btn-row">
                                        <button class="req-accept-btn req-icon-btn" id="acceptBtn<?= $req['id'] ?>"
                                            title="Accept request" aria-label="Accept request"
                                            onclick="acceptRecovery(<?= $req['id'] ?>)">
                                            <i class="fas fa-check"></i>
                                        </button>
                                        <button class="req-reject-btn req-icon-btn" id="rejectBtn<?= $req['id'] ?>"
                                            title="Reject request" aria-label="Reject request"
                                            onclick="openRejectModal(<?= $req['id'] ?>)">
                                            <i class="fas fa-times"></i>
                                        </button>
                                    </div>
                                <?php endif; ?>
                            </div>
                        </div>
                        <div class="req-head-meta">
                            <span class="req-type-badge <?= $req['account_type'] ?>"><?= ucfirst($req['account_type'] ?? '') ?></span>
                            <span class="req-timestamp"><i class="far fa-clock"></i> <?= date('M d, Y h:i A', strtotime($req['submitted_at'] ?? '')) ?></span>
                        </div>
                    </div>
                </div>

                <?php
                // Two-column grid: long values take the full row; a half-width
                // field left alone on its row is widened so the grid stays even.
                // UPDATED (this adjustment): Old / New Email now come after Campus.
                $items = [];
                foreach ($fields as $f) {
                    $items[] = [$f[0], $f[1], in_array(strtolower($f[0]), ['course', 'company', 'details'], true)];
                }
                $col = 0;
                foreach ($items as $k => $it) {
                    if ($it[2]) {
                        if ($col === 1) $items[$k - 1][2] = true;
                        $col = 0;
                    } else {
                        $col = 1 - $col;
                    }
                }
                if ($col === 1) $items[count($items) - 1][2] = true;
                // Old / New Email share their own row, below Campus
                $items[] = ['Old Email', (string)$req['old_email'], false];
                $items[] = ['New Email', (string)$req['new_email'], false];
                ?>
                <div class="req-fields">
                    <?php foreach ($items as $it): ?>
                    <div class="req-field<?= $it[2] ? ' wide' : '' ?>">
                        <span class="req-field-label"><?= htmlspecialchars($it[0] ?? '') ?></span>
                        <span class="req-field-value"><?= htmlspecialchars($it[1] !== '' ? $it[1] : '—') ?></span>
                    </div>
                    <?php endforeach; ?>
                    <div class="req-field wide">
                        <span class="req-field-label">Reason</span>
                        <span class="req-field-value req-reason-box"><?= htmlspecialchars($req['reason'] ?? '') ?></span>
                    </div>
                </div>

            </div>
    <?php
}

// ── NEW (live inbox): ready-made Pending request cards for the drawer ──
// Placed right AFTER mon_render_req_card() is defined (so it never depends on
// function hoisting) and made robust: any stray PHP notice / earlier output is
// discarded and the JSON is always valid (bad UTF-8 is substituted), so the
// live inbox can always read it. Only requests that are STILL Pending are
// returned — a request only goes to History once the admin accepts/rejects it.
if (isset($_GET['recovery_request_cards']) && $_GET['recovery_request_cards'] == '1') {
    @ini_set('display_errors', '0');
    while (ob_get_level() > 0) { @ob_end_clean(); }
    header('Content-Type: application/json');
    $ids = array_values(array_unique(array_filter(array_map('intval', explode(',', (string)($_GET['ids'] ?? ''))), function ($v) { return $v > 0; })));
    $cards = [];
    if ($ids) {
        try {
            $ids = array_slice($ids, 0, 50);
            $in  = implode(',', $ids); // integers only
            $rc  = $conn->query("SELECT * FROM email_recovery_requests
                                 WHERE id IN ($in)
                                   AND (status IS NULL OR TRIM(status) = '' OR LOWER(TRIM(status)) = 'pending')
                                 ORDER BY submitted_at DESC, id DESC");
            if ($rc) {
                while ($req = $rc->fetch_assoc()) {
                    ob_start();
                    mon_render_req_card($req);
                    $cards[] = ['id' => (int)$req['id'], 'html' => ob_get_clean()];
                }
            }
        } catch (\Throwable $e) {
            while (ob_get_level() > 0) { @ob_end_clean(); }
            $cards = [];
        }
    }
    $jsonFlags = (defined('JSON_INVALID_UTF8_SUBSTITUTE') ? JSON_INVALID_UTF8_SUBSTITUTE : 0)
               | (defined('JSON_PARTIAL_OUTPUT_ON_ERROR') ? JSON_PARTIAL_OUTPUT_ON_ERROR : 0);
    $json = json_encode(['cards' => $cards], $jsonFlags);
    echo ($json !== false) ? $json : '{"cards":[]}';
    exit;
}

// ── Fetch total ungraded faculty_grade count for sidebar badge ──
$stmt_all_ug = $conn->prepare("
    SELECT COUNT(*) as total
    FROM reports r
    JOIN ojt_assignments oa ON oa.student_id = r.user_id AND oa.company_id = r.company_id
    WHERE r.week_start <= CURDATE()
      AND (r.remark IS NULL OR r.remark != 'Wrong Document')
      AND r.faculty_grade IS NULL
");
$stmt_all_ug->execute();
$res_all_ug = $stmt_all_ug->get_result()->fetch_assoc();
$all_ungraded_count = (int)($res_all_ug['total'] ?? 0);
$stmt_all_ug->close();

// ── NEW: Admin full name for sidebar header (first_name + middle_name +
// last_name looked up from the `admins` table using the logged-in admin's
// id). Session values are kept only as a fallback if the DB lookup comes
// back empty, and a generic label is used as a last resort — this mirrors
// the same pattern already used in admin_student_list.php. ──
$adminFullName = '';
// ── UPDATED: also selects email + school-information columns (school,
// school_address, subject, contact_no, required_hours) alongside the
// name fields already fetched here, so the same single lookup can now
// populate the new "My Profile" panel (reached from the FAB menu) in
// addition to the sidebar header name. College is intentionally NOT
// selected — it has been removed from the Create Admin Account form and
// is no longer surfaced anywhere on this page. ──
$adminProfile = [
    'email'          => '',
    'school'         => '',
    'school_address' => '',
    'subject'        => '',
    'contact_no'     => '',
    'required_hours' => '',
];
$adminNameStmt = $conn->prepare("SELECT first_name, middle_name, last_name, email, school, school_address, subject, contact_no, required_hours FROM admins WHERE id = ?");
if ($adminNameStmt) {
    $admin_session_id = $_SESSION['user_id'] ?? 0;
    $adminNameStmt->bind_param("i", $admin_session_id);
    $adminNameStmt->execute();
    $adminNameRow = $adminNameStmt->get_result()->fetch_assoc();
    $adminNameStmt->close();
    if ($adminNameRow) {
        $adminFullName = trim(
            ($adminNameRow['first_name'] ?? '') . ' ' .
            ($adminNameRow['middle_name'] ?? '') . ' ' .
            ($adminNameRow['last_name'] ?? '')
        );
        // collapse any double spaces left behind when middle_name is empty
        $adminFullName = preg_replace('/\s+/', ' ', $adminFullName);

        $adminProfile['email']          = (string)($adminNameRow['email'] ?? '');
        $adminProfile['school']         = (string)($adminNameRow['school'] ?? '');
        $adminProfile['school_address'] = (string)($adminNameRow['school_address'] ?? '');
        $adminProfile['subject']        = (string)($adminNameRow['subject'] ?? '');
        $adminProfile['contact_no']     = (string)($adminNameRow['contact_no'] ?? '');
        $adminProfile['required_hours'] = (string)($adminNameRow['required_hours'] ?? '');
    }
}
// ── FIX (this adjustment): admin full name in the sidebar header. admin_login.php stores the
// logged-in admin's id in $_SESSION['admin_id'] (and removes 'user_id'), so the lookup above
// often found nobody and the header fell back to the first name only. If it came back empty,
// look the admin up again by $_SESSION['admin_id'] (then 'user_id') — the same rule
// administrator.php / system_setting.php use. Read-only and fully guarded; nothing else on
// the page uses these variables.
if ($adminFullName === '') {
    try {
        $adminNameFixId = (int)($_SESSION['admin_id'] ?? $_SESSION['user_id'] ?? 0);
        if ($adminNameFixId > 0) {
            $adminNameFixStmt = $conn->prepare("SELECT first_name, middle_name, last_name FROM admins WHERE id = ?");
            if ($adminNameFixStmt) {
                $adminNameFixStmt->bind_param("i", $adminNameFixId);
                $adminNameFixStmt->execute();
                $adminNameFixRow = $adminNameFixStmt->get_result()->fetch_assoc();
                $adminNameFixStmt->close();
                if ($adminNameFixRow) {
                    $adminFullName = preg_replace('/\s+/', ' ', trim(
                        ($adminNameFixRow['first_name'] ?? '') . ' ' .
                        ($adminNameFixRow['middle_name'] ?? '') . ' ' .
                        ($adminNameFixRow['last_name'] ?? '')
                    ));
                }
            }
        }
    } catch (\Throwable $e) { /* keep the existing fallbacks below */ }
}
if ($adminFullName === '') {
    $adminFullName = trim(($_SESSION['first_name'] ?? '') . ' ' . ($_SESSION['last_name'] ?? ''));
}
if ($adminFullName === '') $adminFullName = 'Administrator';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>NEUST | Account Management</title>
    <link href="https://fonts.googleapis.com/css2?family=Outfit:wght@300;400;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        :root {
            --neust-maroon: #07145fe5;
            --neust-gold: #FFD700;
            --bg: #fcfaf7;
            --text: #2d1b1b;
            --white: #ffffff;
            --sidebar-active: #1a237e;

            /* ══════════════════════════════════════════════════════════
               NEW: "Field ops grid" design tokens (design preview #4)
               ------------------------------------------------------------
               Scoped as CSS variables so the main-content redesign below
               (page header, table cards, live activity log, filter inputs,
               status/delete buttons) reads from one consistent palette.
               The sidebar and its own header block intentionally keep
               using --neust-maroon / --sidebar-active above, untouched.
            ══════════════════════════════════════════════════════════ */
            --d4-navy: #1B2A4A;
            --d4-navy-soft: #5A6272;
            --d4-bg: #EEF1F6;
            --d4-border: #C3CADA;
            --d4-green: #2C5A2C;
            --d4-amber: #A0850A;
            --d4-red: #A02A2A;
        }

        body { font-family: 'Outfit', sans-serif; background: var(--bg); margin: 0; display: flex; color: var(--text); min-height: 100vh; overflow-x: hidden; }

        .sidebar { width: 260px; background: var(--neust-maroon); height: 100vh; position: fixed; display: flex; flex-direction: column; transition: all 0.3s ease; z-index: 1000; box-shadow: 4px 0 10px rgba(0,0,0,0.1); }
        .sidebar.collapsed { width: 80px; }
        .sidebar-header { padding: 20px; display: flex; align-items: center; justify-content: space-between; border-bottom: 1px solid rgba(255,255,255,0.1); }
        .sidebar-header-titles { overflow: hidden; transition: 0.3s; min-width: 0; }
        .sidebar-header h2 { color: var(--neust-gold); margin: 0; font-size: 20px; font-weight: bold; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; transition: 0.3s; }
        .sidebar-role-label { display: block; color: rgba(255,255,255,0.55); font-size: 11px; font-weight: 700; text-transform: uppercase; letter-spacing: 0.8px; margin-top: 3px; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; transition: 0.3s; }
        .sidebar.collapsed .sidebar-header-titles { opacity: 0; width: 0; }
        .sidebar-links { flex: 1; display: flex; flex-direction: column; padding: 10px 0; }
        .sidebar a { padding: 15px 25px; color: #cbd5e0; text-decoration: none; font-size: 14px; display: flex; align-items: center; transition: 0.2s; white-space: nowrap; position: relative; }
        .sidebar a i { width: 30px; font-size: 18px; margin-right: 15px; text-align: center; }
        .sidebar.collapsed a { justify-content: center; padding: 15px 0; }
        .sidebar.collapsed a i { margin-right: 0; }
        .sidebar.collapsed .link-text { display: none; }
        .sidebar a:hover { color: white; background: rgba(255,255,255,0.05); }
        .sidebar a.active { background: var(--sidebar-active); color: white; border-left: 4px solid var(--neust-gold); }
        .sidebar .logout-link { margin-top: auto; padding: 20px; border-top: 1px solid rgba(255,255,255,0.1); }
        .sidebar .logout-link a { border: 1px solid var(--neust-gold); color: var(--neust-gold); border-radius: 6px; justify-content: center; padding: 10px; }
        .sidebar.collapsed .logout-link a { border: none; }
        .toggle-btn { background: transparent; border: none; color: white; cursor: pointer; font-size: 20px; outline: none; }

        .sidebar-badge-ungraded {
            background: #d97706;
            color: white;
            border-radius: 50%;
            width: 18px; height: 18px;
            font-size: 10px; font-weight: 700;
            display: inline-flex;
            align-items: center; justify-content: center;
            position: absolute;
            right: 18px; top: 50%;
            transform: translateY(-50%);
            animation: badge-pulse-ungraded 2s ease-in-out infinite;
        }
        @keyframes badge-pulse-ungraded {
            0%,100% { box-shadow: 0 0 0 0 rgba(217,119,6,0.55); }
            50%      { box-shadow: 0 0 0 6px rgba(217,119,6,0); }
        }

        /* ── NEW: pending-MOA indicator on the "Company Requirements" sidebar
           link — mirrors the identical badge/animation already used in
           company_validation.php and admin_student_list.php so the visual
           language matches exactly. ── */
        .sidebar-badge-moa {
            background: #ef4444;
            color: white;
            border-radius: 50%;
            width: 18px; height: 18px;
            font-size: 10px; font-weight: 700;
            display: inline-flex;
            align-items: center; justify-content: center;
            position: absolute;
            right: 18px; top: 50%;
            transform: translateY(-50%);
            animation: badge-pulse-moa-sidebar 2s ease-in-out infinite;
        }
        @keyframes badge-pulse-moa-sidebar {
            0%,100% { box-shadow: 0 0 0 0 rgba(239,68,68,0.55); }
            50%      { box-shadow: 0 0 0 6px rgba(239,68,68,0); }
        }

        .main-content { margin-left: 260px; width: calc(100% - 260px); padding: 50px; transition: 0.3s; min-height: 100vh; }
        .sidebar.collapsed + .main-content { margin-left: 80px; width: calc(100% - 80px); }

        /* ══════════════════════════════════════════════════════════════
           REDESIGN (per company_list_style_previews.html — Style #4,
           "Field ops grid"): page header, table cards, live activity log,
           filter inputs, and the Status/Delete pill buttons below now use
           the navy / slate-blue / sharp-edged look of that reference
           style. Sidebar + sidebar header are intentionally NOT touched.
           No IDs, classes, onclick handlers, or markup structure were
           removed — this section only restyles existing selectors.
        ══════════════════════════════════════════════════════════════ */
        .filter-input {
            padding: 8px 12px;
            border: 1px solid var(--d4-border);
            border-radius: 4px;
            font-size: 0.82rem;
            outline: none;
            background: #fff;
            color: var(--d4-navy);
            font-family: 'Outfit', sans-serif;
        }
        .filter-input:focus { border-color: var(--d4-navy); }
        .export-btn { background: #2e7d32; color: white; border: none; padding: 8px 15px; border-radius: 8px; cursor: pointer; font-weight: 600; font-size: 0.85rem; }

        .page-header { margin-bottom: 40px; }
        .page-header h1 {
            font-size: 2.1rem;
            margin: 0;
            color: var(--d4-navy);
            font-weight: 700;
            letter-spacing: 0.2px;
        }

        .table-section {
            background: var(--d4-bg);
            border-radius: 12px;
            padding: 26px 28px;
            margin-bottom: 30px;
            border: 1px solid var(--d4-border);
        }
        .table-section h3 {
            margin: 0;
            font-size: 12px;
            font-weight: 700;
            color: var(--d4-navy);
            text-transform: uppercase;
            letter-spacing: 0.6px;
        }

        table { width: 100%; border-collapse: collapse; background: #fff; border-radius: 8px; overflow: hidden; }
        th {
            text-align: left;
            padding: 12px 14px;
            color: var(--d4-navy);
            font-size: 0.72rem;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            font-weight: 700;
            border-bottom: 1px solid var(--d4-border);
            background: #fff;
        }
        td { padding: 14px; border-bottom: 1px solid #EDEFF4; font-size: 0.92rem; color: var(--d4-navy); }
        tbody tr:last-child td { border-bottom: none; }

        .edit-link { color: #2196F3; text-decoration: none; font-weight: 600; margin-right: 15px; }
        .delete-link { color: #f44336; text-decoration: none; font-weight: 600; background: none; border: none; cursor: pointer; font-family: inherit; font-size: inherit; padding: 0; }

        /* ══════════════════════════════════════════════════════════════
           UPDATED: the old standalone "+" Create Admin button (bottom-right)
           and the old standalone circular Email Recovery button (top-right)
           have been merged into a single expandable "speed dial" FAB menu,
           bottom-right, with three actions: My Profile, Email Recovery
           Requests, and Create Admin Account. See #fabMenu markup below.
        ══════════════════════════════════════════════════════════════ */
        #fabMenu {
            position: fixed; bottom: 40px; right: 40px; z-index: 1100;
            display: flex; flex-direction: column; align-items: flex-end; gap: 14px;
        }
        .fab-main-btn {
            width: 60px; height: 60px;
            background: var(--neust-maroon); color: var(--neust-gold);
            border: none; border-radius: 50%; font-size: 26px; cursor: pointer;
            box-shadow: 0 10px 20px rgba(128,0,0,0.2); transition: transform 0.25s ease, box-shadow 0.25s ease;
            display: flex; align-items: center; justify-content: center;
            position: relative; flex-shrink: 0;
        }
        .fab-main-btn:hover { box-shadow: 0 14px 28px rgba(128,0,0,0.3); }
        .fab-main-btn i { transition: transform 0.25s ease; pointer-events: none; }
        #fabMenu.open .fab-main-btn i { transform: rotate(45deg); }
        .fab-main-badge {
            position: absolute; top: -4px; right: -4px;
            background: #dc2626; color: white; border-radius: 50%;
            width: 20px; height: 20px; font-size: 11px; font-weight: 700;
            display: none; align-items: center; justify-content: center;
            border: 2px solid white; pointer-events: none;
        }
        .fab-options {
            display: flex; flex-direction: column; align-items: flex-end; gap: 12px;
            opacity: 0; pointer-events: none; transform: translateY(10px) scale(0.96);
            transition: opacity 0.22s ease, transform 0.22s ease;
        }
        #fabMenu.open .fab-options { opacity: 1; pointer-events: auto; transform: translateY(0) scale(1); }
        .fab-option {
            display: flex; align-items: center; gap: 10px;
            background: white; border: 1px solid #eee; border-radius: 50px;
            padding: 8px 18px 8px 8px; cursor: pointer;
            box-shadow: 0 6px 18px rgba(0,0,0,0.14);
            font-family: 'Outfit', sans-serif; font-weight: 600; font-size: 0.85rem;
            color: var(--neust-maroon); transition: transform 0.18s, box-shadow 0.18s;
            white-space: nowrap;
        }
        .fab-option:hover { transform: translateY(-2px) scale(1.02); box-shadow: 0 10px 24px rgba(0,0,0,0.2); }
        .fab-option-icon {
            width: 34px; height: 34px; border-radius: 50%;
            background: var(--neust-maroon); color: var(--neust-gold);
            display: flex; align-items: center; justify-content: center;
            font-size: 14px; position: relative; flex-shrink: 0;
        }
        .fab-option-badge {
            position: absolute; top: -5px; right: -5px;
            background: #dc2626; color: white; border-radius: 50%;
            width: 17px; height: 17px; font-size: 10px; font-weight: 700;
            display: none; align-items: center; justify-content: center;
            border: 2px solid white;
        }
        .fab-option-label { padding-right: 4px; }

        /* ══════════════════════════════════════════════════════════════
           FIX (Delete button sometimes not clickable until scrolled):
           #fabMenu is a fixed-position flex box that always reserves room
           for the three option pills, even while they are invisible
           (opacity 0). The pills themselves had pointer-events:none, but
           the #fabMenu container box did NOT — so an invisible rectangle
           in the bottom-right corner intercepted clicks aimed at
           whatever sat underneath it, which is exactly where the Delete
           button of the Actions column lands. The container (and the
           options wrapper) is now click-through, and only the things
           that are actually visible and meant to be clicked — the main
           "+" button and the option pills while the menu is open — take
           pointer events. Look, animation and open/close behavior of the
           FAB are unchanged.
        ══════════════════════════════════════════════════════════════ */
        #fabMenu { pointer-events: none; }
        #fabMenu .fab-main-btn { pointer-events: auto; }
        #fabMenu .fab-options,
        #fabMenu.open .fab-options { pointer-events: none; }
        #fabMenu.open .fab-option { pointer-events: auto; }

        /* ── NEW: My Profile modal rows ── */
        .profile-row { display: flex; justify-content: space-between; align-items: flex-start; gap: 14px; padding: 12px 0; border-bottom: 1px solid #f1f1f1; }
        .profile-row:last-of-type { border-bottom: none; }
        .profile-label { font-size: 0.72rem; font-weight: 700; color: #a18a8a; text-transform: uppercase; letter-spacing: 0.4px; flex-shrink: 0; padding-top: 2px; }
        .profile-value { font-size: 0.92rem; color: var(--text); text-align: right; word-break: break-word; font-weight: 600; }

        .modal { display: none; position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: rgba(45,27,27,0.8); z-index: 2000; justify-content: center; align-items: center; backdrop-filter: blur(5px); }
        .role-option { flex: 1; border: 1px solid #eee; padding: 15px; border-radius: 12px; text-align: center; cursor: pointer; background: #fff; transition: 0.3s; font-weight: 600; }
        .role-option:has(input:checked) { border-color: var(--neust-maroon); background: #fff8f8; color: var(--neust-maroon); }
        .role-option input { display: none; }
        .admin-phase { display: none; }
        .admin-phase.active { display: block; }
        .phase-indicator { display: flex; align-items: center; justify-content: center; gap: 10px; margin-bottom: 20px; }
        .phase-dot { width: 10px; height: 10px; border-radius: 50%; background: #ddd; transition: all 0.3s; }
        .phase-dot.done    { background: #4CAF50; }
        .phase-dot.current { background: var(--neust-maroon); transform: scale(1.35); }
        .phase-label { text-align: center; font-size: 0.76rem; color: #999; margin-bottom: 16px; letter-spacing: 0.5px; text-transform: uppercase; }
        .admin-msg { padding: 10px 14px; border-radius: 10px; font-size: 0.88rem; margin-bottom: 14px; display: none; }
        .admin-msg.error   { background: #fef2f2; color: #dc2626; border: 1px solid #fecaca; display: block; }
        .admin-msg.success { background: #f0fdf4; color: #16a34a; border: 1px solid #bbf7d0; display: block; }
        .spinner { display: none; width: 18px; height: 18px; border: 3px solid rgba(255,255,255,0.3); border-top-color: #fff; border-radius: 50%; animation: spin 0.8s linear infinite; margin: 0 auto; }
        @keyframes spin { to { transform: rotate(360deg); } }

        /* ── Status button — restyled to match the "Field ops grid" outline
           button language (sharp corners, uppercase, letter-spacing, thin
           colored border) instead of the previous rounded pill. Same
           classes/attributes are still toggled by JS (status-btn-active /
           status-btn-inactive / data-active), so behavior is unchanged. ── */
        .status-toggle-btn {
            border: 1px solid transparent; border-radius: 4px; padding: 9px 16px;
            font-size: 0.74rem; font-weight: 700; cursor: pointer;
            text-transform: uppercase; letter-spacing: 0.5px;
            transition: 0.2s;
            display: inline-flex; align-items: center; gap: 7px;
            font-family: 'Outfit', sans-serif;
        }
        .status-toggle-btn:hover { opacity: 0.85; transform: translateY(-1px); }
        .status-toggle-btn:disabled { opacity: 0.5; cursor: not-allowed; transform: none; }
        .status-toggle-btn.status-btn-active   { background: #fff; color: var(--d4-green); border-color: var(--d4-green); }
        .status-toggle-btn.status-btn-inactive { background: #fff; color: var(--d4-red);   border-color: var(--d4-red); }

        /* ── Delete pill button — restyled to match the same outline
           language as the Status button, still clearly destructive via
           the red border/text treatment. ── */
        .delete-btn-pill {
            border: 1px solid var(--d4-red); border-radius: 4px; padding: 9px 16px;
            font-size: 0.74rem; font-weight: 700; cursor: pointer;
            text-transform: uppercase; letter-spacing: 0.5px;
            transition: 0.2s;
            display: inline-flex; align-items: center; gap: 7px;
            background: #fff; color: var(--d4-red);
            font-family: 'Outfit', sans-serif;
        }
        .delete-btn-pill:hover { opacity: 0.85; transform: translateY(-1px); }
        .delete-btn-pill:disabled { opacity: 0.5; cursor: not-allowed; transform: none; }

        /* ── LIVE HISTORY — restyled to the same navy/bordered card look. ── */
        .history-wrapper { background: var(--d4-bg); border-radius: 12px; padding: 22px 28px; margin-bottom: 30px; border: 1px solid var(--d4-border); }
        .history-wrapper::before { content: 'Live Activity Log'; display: block; font-size: 0.72rem; font-weight: 700; color: var(--d4-navy); text-transform: uppercase; letter-spacing: 0.6px; margin-bottom: 16px; padding-bottom: 12px; border-bottom: 1px solid var(--d4-border); }
        #historyContainer { max-height: 260px; overflow-y: auto; scrollbar-width: thin; scrollbar-color: var(--d4-border) transparent; }
        #historyContainer::-webkit-scrollbar { width: 4px; }
        #historyContainer::-webkit-scrollbar-thumb { background: var(--d4-border); border-radius: 4px; }
        .log-entry { border-bottom: 1px solid #E2E6EF; padding: 12px 0; animation: fadeIn 0.3s ease; }
        .log-entry:last-child { border-bottom: none; }
        @keyframes fadeIn { from { opacity:0; transform:translateY(-4px); } to { opacity:1; transform:translateY(0); } }
        .log-entry-inner { display: flex; justify-content: space-between; align-items: flex-start; gap: 16px; }
        .log-entry-left { display: flex; flex-direction: column; gap: 3px; flex: 1; }
        .log-entry-left strong { font-size: 0.88rem; color: var(--d4-navy); }
        .log-entry-left div { font-size: 0.8rem; color: var(--d4-navy-soft); }
        .log-entry-right { font-size: 0.75rem; color: #8B93A8; white-space: nowrap; padding-top: 2px; flex-shrink: 0; }
        .badge { display: inline-flex; align-items: center; padding: 3px 9px; border-radius: 4px; font-size: 0.7rem; font-weight: 700; text-transform: uppercase; letter-spacing: 0.4px; width: fit-content; margin-bottom: 2px; }
        .badge-ACCOUNT-CREATED  { background: #E4EEDF; color: var(--d4-green); }
        .badge-ACCOUNT-DELETED  { background: #F3E1DD; color: var(--d4-red); }
        .badge-ACCOUNT-UPDATED  { background: #DDE6F3; color: var(--d4-navy); }
        .badge-ACCOUNT-ACTIVATED   { background: #E4EEDF; color: var(--d4-green); }
        .badge-ACCOUNT-DEACTIVATED { background: #F4EBD3; color: var(--d4-amber); }
        .badge-LOGIN            { background: #E4EEDF; color: var(--d4-green); }
        .badge-LOGOUT           { background: #F4EBD3; color: var(--d4-amber); }
        .badge:not([class*="badge-ACCOUNT"]):not(.badge-LOGIN):not(.badge-LOGOUT) { background: #EEF1F6; color: var(--d4-navy-soft); }

        /* ══════════════════════════════════════════════════════════════
           UPDATED: the Email Recovery Requests trigger is now one of the
           three options inside the merged #fabMenu speed dial (bottom-right)
           instead of its own standalone top-right button. Its badge id
           (#recoveryFabBadge) is preserved so the existing updateFabBadge()
           JS logic keeps working unchanged — see .fab-option-badge above
           for its new styling, and the #fabMenu markup below for placement.
           Clicking it still calls the exact same openRecoveryDrawer()
           function as before.
        ══════════════════════════════════════════════════════════════ */

        /* ── RECOVERY DRAWER ── */
        #recoveryDrawerOverlay {
            display: none; position: fixed; inset: 0;
            background: rgba(0,0,0,0.45); z-index: 3000;
            justify-content: flex-end; align-items: stretch;
        }
        #recoveryDrawer {
            background: white; width: 480px; max-width: 96vw;
            display: flex; flex-direction: column;
            box-shadow: -10px 0 40px rgba(0,0,0,0.18);
            animation: slideInRight 0.3s cubic-bezier(0.25,0.46,0.45,0.94);
        }
        @keyframes slideInRight { from{transform:translateX(100%);} to{transform:translateX(0);} }
        #recoveryDrawerHeader {
            padding: 20px 24px; border-bottom: 1px solid rgba(255,255,255,0.15);
            display: flex; align-items: center; justify-content: space-between; flex-shrink: 0;
            background: var(--neust-maroon);
        }
        #recoveryDrawerHeader h3 { margin: 0; color: var(--neust-gold); font-size: 15px; display: flex; align-items: center; gap: 10px; }
        #recoveryDrawerClose { background: none; border: none; font-size: 22px; color: rgba(255,255,255,0.7); cursor: pointer; line-height: 1; padding: 0; }
        #recoveryDrawerClose:hover { color: white; }
        #recoveryDrawerBody { overflow-y: auto; flex: 1; padding: 20px 24px; }

        /* ── REQUEST CARDS ── */
        .req-card { border: 1px solid #e2e8f0; border-radius: 14px; padding: 16px 18px; margin-bottom: 14px; background: #fafafa; display: flex; gap: 14px; align-items: flex-start; }
        .req-card.accepted { border-color: #bbf7d0; background: #f0fdf4; }
        .req-card.rejected { border-color: #fecaca; background: #fff8f8; }
        .req-selfie { width: 64px; height: 64px; border-radius: 10px; object-fit: cover; border: 2px solid #e2e8f0; flex-shrink: 0; }
        .req-selfie-placeholder { width: 64px; height: 64px; border-radius: 10px; background: #e2e8f0; display: flex; align-items: center; justify-content: center; font-size: 20px; flex-shrink: 0; }
        .req-body { flex: 1; min-width: 0; }
        .req-body .req-name { font-weight: 700; font-size: 0.92rem; color: #1e293b; margin-bottom: 4px; }
        .req-body .req-detail { font-size: 0.79rem; color: #64748b; margin-bottom: 2px; word-break: break-all; }
        .req-body .req-reason-box { font-size: 0.79rem; color: #475569; margin-top: 8px; background: #f1f5f9; padding: 7px 11px; border-radius: 8px; }
        .req-type-badge { display: inline-block; padding: 2px 10px; border-radius: 20px; font-size: 0.7rem; font-weight: 700; text-transform: uppercase; margin-bottom: 5px; }
        .req-type-badge.student { background: #dbeafe; color: #1d4ed8; }
        .req-type-badge.company { background: #fef9c3; color: #854d0e; }
        .req-btn-row { display: flex; gap: 8px; margin-top: 12px; }
        .req-accept-btn { padding: 8px 16px; background: #16a34a; color: white; border: none; border-radius: 10px; font-weight: 700; font-size: 0.78rem; cursor: pointer; transition: 0.2s; display: inline-flex; align-items: center; gap: 5px; }
        .req-accept-btn:hover { opacity: 0.85; }
        .req-accept-btn:disabled { opacity: 0.4; cursor: not-allowed; }
        .req-reject-btn { padding: 8px 16px; background: #dc2626; color: white; border: none; border-radius: 10px; font-weight: 700; font-size: 0.78rem; cursor: pointer; transition: 0.2s; display: inline-flex; align-items: center; gap: 5px; }
        .req-reject-btn:hover { opacity: 0.85; }
        .req-reject-btn:disabled { opacity: 0.4; cursor: not-allowed; }
        .req-accepted-tag { display: inline-flex; align-items: center; gap: 5px; background: #dcfce7; color: #166534; padding: 6px 14px; border-radius: 10px; font-size: 0.78rem; font-weight: 700; margin-top: 10px; }
        .req-rejected-tag { display: inline-flex; align-items: center; gap: 5px; background: #fef2f2; color: #dc2626; padding: 6px 14px; border-radius: 10px; font-size: 0.78rem; font-weight: 700; margin-top: 10px; }
        .req-empty { text-align: center; color: #a0aec0; padding: 40px 0; font-size: 0.9rem; }
        .req-timestamp { font-size: 0.72rem; color: #a0aec0; margin-top: 4px; }

        /* ── REJECT REASON MODAL ── */
        #rejectReasonOverlay {
            display: none; position: fixed; inset: 0;
            background: rgba(0,0,0,0.55); z-index: 4000;
            justify-content: center; align-items: center;
        }
        #rejectReasonBox {
            background: white; border-radius: 18px; padding: 32px 28px;
            width: 400px; max-width: 92%;
            box-shadow: 0 20px 60px rgba(0,0,0,0.25);
            animation: popIn 0.3s cubic-bezier(0.34,1.56,0.64,1);
        }
        @keyframes popIn { from{transform:scale(0.85);opacity:0;} to{transform:scale(1);opacity:1;} }
        #rejectReasonBox h4 { margin: 0 0 8px; color: #dc2626; font-size: 16px; }
        #rejectReasonBox p { margin: 0 0 14px; font-size: 13px; color: #64748b; }
        #rejectReasonInput { width: 100%; padding: 12px 14px; border-radius: 10px; border: 1.5px solid #e2e8f0; font-size: 13px; resize: vertical; min-height: 80px; box-sizing: border-box; font-family: inherit; background: #f8fafc; margin-bottom: 16px; }
        #rejectReasonInput:focus { outline: none; border-color: #dc2626; }
        .rr-actions { display: flex; gap: 10px; justify-content: flex-end; }
        .rr-cancel { padding: 10px 22px; background: #f1f5f9; color: #475569; border: none; border-radius: 10px; font-weight: 700; font-size: 13px; cursor: pointer; }
        .rr-confirm { padding: 10px 22px; background: #dc2626; color: white; border: none; border-radius: 10px; font-weight: 700; font-size: 13px; cursor: pointer; display: inline-flex; align-items: center; gap: 6px; transition: 0.2s; }
        .rr-confirm:hover { opacity: 0.85; }
        .rr-confirm:disabled { opacity: 0.4; cursor: not-allowed; }

        /* ══════════════════════════════════════════════════════════════
           ENHANCED NOTIFICATION POPUP (replaces old errorModal / verifiedModal)
        ══════════════════════════════════════════════════════════════ */
        #notifOverlay {
            display: none;
            position: fixed; inset: 0;
            background: rgba(7, 20, 95, 0.45);
            z-index: 9000;
            justify-content: center;
            align-items: center;
            backdrop-filter: blur(6px);
            animation: notifFadeIn 0.22s ease;
        }
        @keyframes notifFadeIn { from{opacity:0;} to{opacity:1;} }

        #notifBox {
            background: #ffffff;
            border-radius: 24px;
            width: 380px;
            max-width: 93vw;
            box-shadow: 0 32px 80px rgba(7,20,95,0.22), 0 2px 8px rgba(0,0,0,0.06);
            overflow: hidden;
            animation: notifSlideUp 0.32s cubic-bezier(0.34,1.56,0.64,1);
            position: relative;
        }
        @keyframes notifSlideUp { from{transform:translateY(40px) scale(0.93);opacity:0;} to{transform:translateY(0) scale(1);opacity:1;} }

        #notifAccentBar {
            height: 6px;
            width: 100%;
        }
        #notifAccentBar.success { background: linear-gradient(90deg, #07145f, #3b82f6); }
        #notifAccentBar.error   { background: linear-gradient(90deg, #dc2626, #f97316); }
        #notifAccentBar.warning { background: linear-gradient(90deg, #d97706, #fbbf24); }
        #notifAccentBar.delete  { background: linear-gradient(90deg, #dc2626, #9f1239); }

        #notifBody {
            padding: 32px 32px 28px;
            text-align: center;
        }

        #notifIconRing {
            width: 76px; height: 76px;
            border-radius: 50%;
            display: flex; align-items: center; justify-content: center;
            margin: 0 auto 20px;
            font-size: 34px;
            position: relative;
        }
        #notifIconRing.success { background: #eff6ff; box-shadow: 0 0 0 10px #dbeafe55; }
        #notifIconRing.error   { background: #fef2f2; box-shadow: 0 0 0 10px #fecaca44; }
        #notifIconRing.warning { background: #fffbeb; box-shadow: 0 0 0 10px #fde68a44; }
        #notifIconRing.delete  { background: #fff1f2; box-shadow: 0 0 0 10px #fecdd344; }

        #notifTitle {
            font-size: 1.18rem;
            font-weight: 800;
            margin: 0 0 8px;
            letter-spacing: -0.01em;
        }
        #notifTitle.success { color: #07145f; }
        #notifTitle.error   { color: #dc2626; }
        #notifTitle.warning { color: #d97706; }
        #notifTitle.delete  { color: #9f1239; }

        #notifMsg {
            font-size: 0.875rem;
            color: #64748b;
            line-height: 1.65;
            margin: 0 0 28px;
        }

        #notifBtn {
            width: 100%;
            padding: 14px;
            border: none;
            border-radius: 14px;
            font-size: 0.95rem;
            font-weight: 700;
            cursor: pointer;
            letter-spacing: 0.02em;
            transition: transform 0.15s, opacity 0.15s, box-shadow 0.15s;
        }
        #notifBtn:hover  { transform: translateY(-1px); opacity: 0.92; box-shadow: 0 6px 20px rgba(0,0,0,0.14); }
        #notifBtn:active { transform: translateY(0); }
        #notifBtn.success { background: linear-gradient(135deg, #07145f, #1a237e); color: #FFD700; }
        #notifBtn.error   { background: linear-gradient(135deg, #dc2626, #b91c1c); color: white; }
        #notifBtn.warning { background: linear-gradient(135deg, #d97706, #b45309); color: white; }
        #notifBtn.delete  { background: linear-gradient(135deg, #dc2626, #9f1239); color: white; }

        /* ── DELETE CONFIRM DIALOG ── */
        #deleteConfirmOverlay {
            display: none; position: fixed; inset: 0;
            background: rgba(7,20,95,0.45); z-index: 9100;
            justify-content: center; align-items: center;
            backdrop-filter: blur(6px);
            animation: notifFadeIn 0.22s ease;
        }
        #deleteConfirmBox {
            background: #fff; border-radius: 24px;
            width: 380px; max-width: 93vw;
            overflow: hidden;
            box-shadow: 0 32px 80px rgba(7,20,95,0.22);
            animation: notifSlideUp 0.32s cubic-bezier(0.34,1.56,0.64,1);
        }
        #deleteConfirmBox .dc-bar { height: 6px; background: linear-gradient(90deg,#dc2626,#9f1239); }
        #deleteConfirmBox .dc-body { padding: 32px 32px 28px; text-align: center; }
        #deleteConfirmBox .dc-icon { width: 76px; height: 76px; border-radius: 50%; background: #fff1f2; box-shadow: 0 0 0 10px #fecdd344; display: flex; align-items: center; justify-content: center; font-size: 34px; margin: 0 auto 20px; }
        #deleteConfirmBox .dc-title { font-size: 1.18rem; font-weight: 800; color: #9f1239; margin: 0 0 8px; }
        #deleteConfirmBox .dc-msg { font-size: 0.875rem; color: #64748b; line-height: 1.65; margin: 0 0 28px; }
        #deleteConfirmBox .dc-actions { display: flex; gap: 12px; }
        #deleteConfirmBox .dc-cancel { flex: 1; padding: 14px; background: #f1f5f9; color: #475569; border: none; border-radius: 14px; font-weight: 700; font-size: 0.9rem; cursor: pointer; transition: 0.2s; }
        #deleteConfirmBox .dc-cancel:hover { background: #e2e8f0; }
        #deleteConfirmBox .dc-confirm { flex: 1; padding: 14px; background: linear-gradient(135deg,#dc2626,#9f1239); color: white; border: none; border-radius: 14px; font-weight: 700; font-size: 0.9rem; cursor: pointer; transition: 0.2s; }
        #deleteConfirmBox .dc-confirm:hover { opacity: 0.88; }

        /* ══════════════════════════════════════════════════════════════
           NEW: STATUS TOGGLE (ACTIVATE / DEACTIVATE) CONFIRMATION DIALOG
           ------------------------------------------------------------
           Same visual language as #deleteConfirmOverlay/#deleteConfirmBox
           above (own "sc-" class namespace so nothing about the delete
           dialog's styling or behavior is touched), used to replace the
           plain browser confirm() popups that used to appear when
           activating/deactivating an account. Color/icon flips between a
           "deactivate" (red) and "activate" (green) treatment depending
           on which action is being confirmed.
        ══════════════════════════════════════════════════════════════ */
        #statusConfirmOverlay {
            display: none; position: fixed; inset: 0;
            background: rgba(7,20,95,0.45); z-index: 9150;
            justify-content: center; align-items: center;
            backdrop-filter: blur(6px);
            animation: notifFadeIn 0.22s ease;
        }
        #statusConfirmBox {
            background: #fff; border-radius: 24px;
            width: 380px; max-width: 93vw;
            overflow: hidden;
            box-shadow: 0 32px 80px rgba(7,20,95,0.22);
            animation: notifSlideUp 0.32s cubic-bezier(0.34,1.56,0.64,1);
        }
        #statusConfirmBox .sc-bar { height: 6px; }
        #statusConfirmBox .sc-bar.deactivate { background: linear-gradient(90deg,#dc2626,#f97316); }
        #statusConfirmBox .sc-bar.activate   { background: linear-gradient(90deg,#16a34a,#22c55e); }
        #statusConfirmBox .sc-body { padding: 32px 32px 28px; text-align: center; }
        #statusConfirmBox .sc-icon { width: 76px; height: 76px; border-radius: 50%; display: flex; align-items: center; justify-content: center; font-size: 34px; margin: 0 auto 20px; }
        #statusConfirmBox .sc-icon.deactivate { background: #fff1f2; box-shadow: 0 0 0 10px #fecdd344; }
        #statusConfirmBox .sc-icon.activate   { background: #f0fdf4; box-shadow: 0 0 0 10px #bbf7d044; }
        #statusConfirmBox .sc-title { font-size: 1.18rem; font-weight: 800; margin: 0 0 8px; }
        #statusConfirmBox .sc-title.deactivate { color: #9f1239; }
        #statusConfirmBox .sc-title.activate   { color: #166534; }
        #statusConfirmBox .sc-msg { font-size: 0.875rem; color: #64748b; line-height: 1.65; margin: 0 0 28px; }
        #statusConfirmBox .sc-actions { display: flex; gap: 12px; }
        #statusConfirmBox .sc-cancel { flex: 1; padding: 14px; background: #f1f5f9; color: #475569; border: none; border-radius: 14px; font-weight: 700; font-size: 0.9rem; cursor: pointer; transition: 0.2s; }
        #statusConfirmBox .sc-cancel:hover { background: #e2e8f0; }
        #statusConfirmBox .sc-confirm { flex: 1; padding: 14px; border: none; border-radius: 14px; color: white; font-weight: 700; font-size: 0.9rem; cursor: pointer; transition: 0.2s; }
        #statusConfirmBox .sc-confirm.deactivate { background: linear-gradient(135deg,#dc2626,#9f1239); }
        #statusConfirmBox .sc-confirm.activate   { background: linear-gradient(135deg,#16a34a,#15803d); }
        #statusConfirmBox .sc-confirm:hover { opacity: 0.88; }
        #statusConfirmBox .sc-confirm:disabled { opacity: 0.5; cursor: not-allowed; }

        /* ── Sidebar application request badge ── */
        .sidebar-badge-app {
            background: #dc2626;
            color: white;
            border-radius: 50%;
            width: 18px; height: 18px;
            font-size: 10px; font-weight: 700;
            display: inline-flex;
            align-items: center; justify-content: center;
            position: absolute;
            right: 18px; top: 50%;
            transform: translateY(-50%);
            animation: badge-pulse-app-sidebar 2s ease-in-out infinite;
        }
        @keyframes badge-pulse-app-sidebar {
            0%,100% { box-shadow: 0 0 0 0 rgba(220,38,38,0.55); }
            50%      { box-shadow: 0 0 0 6px rgba(220,38,38,0); }
        }

        /* ══════════════════════════════════════════════════════════════
           NEW: MY PROFILE MODAL — displays the logged-in admin's own
           account details ($adminProfile, already fetched in PHP above
           but never previously rendered anywhere on the page). Uses the
           same .modal overlay language as the Create Admin modal, and
           the .profile-row/.profile-label/.profile-value classes that
           were already defined above.
        ══════════════════════════════════════════════════════════════ */
        #profileModalBox {
            background: white; padding: 36px 34px; border-radius: 25px;
            width: 440px; max-width: 92vw;
            box-shadow: 0 20px 60px rgba(0,0,0,0.2);
            max-height: 88vh; overflow-y: auto;
        }
        .profile-avatar {
            width: 72px; height: 72px; border-radius: 50%;
            background: var(--neust-maroon); color: var(--neust-gold);
            display: flex; align-items: center; justify-content: center;
            font-size: 28px; font-weight: 700; margin: 0 auto 14px;
        }
        /* ══════════════════════════════════════════════════════════
           NEW: Global loading overlay — same full-page popup used in
           admin_company_list.php (spinner + short animated message).
           Shown ONLY while the page is first loading its assets, or
           while an in-page action is being processed (delete, activate/
           deactivate, create admin, credential/OTP check, recovery
           accept/reject). It fades out automatically once loading/
           processing finishes. Purely additive — no existing markup,
           handler, or business logic was altered; it only wraps the
           existing async operations with a show/hide call. Colors read
           from this page's own --d4-* tokens (same values the reference
           page's --grid-* fallbacks use).
           ══════════════════════════════════════════════════════════ */
        #globalLoadingOverlay {
            position: fixed;
            inset: 0;
            z-index: 20000;
            display: flex;
            align-items: center;
            justify-content: center;
            background: rgba(238, 241, 246, 0.92);
            opacity: 1;
            visibility: visible;
            transition: opacity 0.35s ease, visibility 0.35s ease;
        }
        #globalLoadingOverlay.hidden {
            opacity: 0;
            visibility: hidden;
            pointer-events: none;
        }
        .global-loading-box {
            display: flex;
            flex-direction: column;
            align-items: center;
            gap: 16px;
            animation: globalLoadingPop 0.35s ease;
        }
        .global-loading-spinner {
            width: 54px;
            height: 54px;
            border-radius: 50%;
            border: 5px solid var(--d4-border, #C3CADA);
            border-top-color: var(--d4-navy, #1B2A4A);
            animation: globalLoadingSpin 0.85s linear infinite;
        }
        .global-loading-text {
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            font-size: 13px;
            font-weight: 700;
            color: var(--d4-navy, #1B2A4A);
            text-transform: uppercase;
            letter-spacing: 0.6px;
            display: flex;
            align-items: center;
            gap: 8px;
        }
        .global-loading-dots span {
            animation: globalLoadingDots 1.2s infinite;
            opacity: 0;
        }
        .global-loading-dots span:nth-child(2) { animation-delay: 0.2s; }
        .global-loading-dots span:nth-child(3) { animation-delay: 0.4s; }
        @keyframes globalLoadingSpin { to { transform: rotate(360deg); } }
        @keyframes globalLoadingPop { from { transform: scale(0.9); opacity: 0; } to { transform: scale(1); opacity: 1; } }
        @keyframes globalLoadingDots { 0%, 20% { opacity: 0; } 50% { opacity: 1; } 100% { opacity: 0; } }

        /* ══════════════════════════════════════════════════════════
           NEW: Account-details block used inside the deletion popups
           (the "Delete Account" confirmation dialog and the "Account
           Deleted" notification). Hidden by default, so every other
           notification / dialog on this page looks exactly as before.
           ══════════════════════════════════════════════════════════ */
        .acct-details {
            display: none;
            text-align: left;
            background: #f8fafc;
            border: 1px solid #e2e8f0;
            border-radius: 14px;
            padding: 4px 16px;
            margin: 0 0 24px;
            max-height: 240px;
            overflow-y: auto;
        }
        .acct-details.show { display: block; }
        .acct-details-row {
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
            gap: 14px;
            padding: 9px 0;
            border-bottom: 1px solid #e2e8f0;
            font-size: 0.8rem;
            line-height: 1.4;
        }
        .acct-details-row:last-child { border-bottom: none; }
        .acct-details-label { color: #64748b; font-weight: 600; flex-shrink: 0; }
        .acct-details-value { color: #1e293b; font-weight: 700; text-align: right; overflow-wrap: anywhere; }
        #notifBody.has-details #notifMsg { margin-bottom: 16px; }
        #deleteConfirmBox .dc-body.has-details .dc-msg { margin-bottom: 16px; }
    </style>
    <noscript><style>#globalLoadingOverlay { display: none; }</style></noscript>
<!-- ══════════════════════════════════════════════════════════════════════
     NEW (this adjustment) — COMPANY REQUIREMENTS NOTIFICATION POPUP (styles)
     Same popup as company_validation.php's notification toast (.cv-top-toast,
     final "navy bar" look): square navy bar, slate frame, green icon, the
     company name in bold white, shown at the TOP of the page and fading out by
     itself. pointer-events:none — it is a message, not a control.
     ══════════════════════════════════════════════════════════════════════ -->
<style>
    .cv-top-toast { position: fixed; top: 30px; left: 50%; transform: translateX(-50%); background: #1B2A4A; color: #E3E8F1; border: 1px solid #55668C; border-radius: 0; padding: 14px 20px; box-shadow: 0 8px 24px rgba(27,42,74,0.30); display: flex; align-items: center; gap: 12px; font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif; font-size: 12.5px; line-height: 1.45; z-index: 10020; max-width: 440px; opacity: 0; transition: opacity 0.35s, top 0.3s ease; pointer-events: none; }
    .cv-top-toast.show { opacity: 1; }
    .cv-top-toast i { color: #8FD18F; font-size: 18px; flex-shrink: 0; }
    .cv-top-toast strong { color: #ffffff; font-weight: 700; }
</style>
<!-- ══════════════════════════════════════════════════════════════════════
     UPDATED (this adjustment) — SMOOTH SIDE MENU OPEN / CLOSE + PAGE ADJUSTMENT
     ------------------------------------------------------------------------
     Why the menu list looked jerky before: on closing, every link was
     switched at once to "centered, no padding" while the menu was still
     260px wide, so the icons jumped to the middle and then drifted left as
     the menu shrank; the names vanished / reappeared instantly; and the
     links' padding animated on a different timer (0.2s) from the menu (0.3s).
     Now (on screens wider than 768px — phones keep their slide-in menu as is):
       • The icons NEVER move. In the 80px rail a 30px icon is centred at
         exactly 25px from the left — the same 25px the open menu uses — so the
         links keep one layout in both states and only the width changes.
       • The link names fade + slide in (lightly staggered, top to bottom) once
         the menu has opened far enough, and fade + slide out quickly the
         moment it starts closing — no popping, no text spilling over the page.
       • The admin name / "Administrator" label fade on the same timing.
       • The Logout button stays visible and morphs smoothly (see the
         Logout rules below) — it no longer fades out / blinks while moving.
       • The menu and the page next to it move together on one shared timing
         and ease-in-out curve (0.35s), so the page glides with the menu.
       • Reduced-motion system setting: near-instant, no animation.
     Only the animation changes: open / closed sizes, colours, icons, badges,
     the Logout button's look and every toggle button stay exactly as they were.
     A page that opens with the menu already closed is never animated.
     ══════════════════════════════════════════════════════════════════════ -->
<style>
    #sidebar.sidebar { transition: width 0.35s cubic-bezier(0.4, 0, 0.2, 1), transform 0.35s cubic-bezier(0.4, 0, 0.2, 1), box-shadow 0.35s ease; will-change: width; }
    #sidebar.sidebar a { white-space: nowrap; }
    html body .main-content { transition: margin-left 0.35s cubic-bezier(0.4, 0, 0.2, 1), width 0.35s cubic-bezier(0.4, 0, 0.2, 1); }
    /* only while the menu is moving (added / removed by the script at the end of the page) */
    #sidebar.sidebar.cv-sb-moving { overflow: hidden; }
    @media (min-width: 769px) {
        /* the links keep ONE layout in both states, so the icons stay perfectly still */
        #sidebar.sidebar .sidebar-links a { transition: background-color 0.2s ease, color 0.2s ease; }
        #sidebar.sidebar.collapsed .sidebar-links a { justify-content: flex-start; padding-left: 25px; padding-right: 25px; }
        #sidebar.sidebar.collapsed .sidebar-links a i { margin-right: 15px; }
        /* FIX (this adjustment) — icon "shaking" while the menu opens / closes:
           the icon box is a flex item, and flex items may shrink. In the narrow rail the
           (hidden) link name still takes room, so each icon's 30px box was squeezed down to
           the glyph's own width and the glyph re-centred inside it — a different amount per
           icon, changing on every frame of the width animation. Locking the box at 30px
           keeps every icon on the exact same spot in both states and during the move. */
        #sidebar.sidebar .sidebar-links a i { flex: 0 0 30px; width: 30px; min-width: 30px; }
        #sidebar.sidebar .sidebar-links a .link-text { flex: 0 0 auto; }
        /* link names: fade + slide instead of popping in / out */
        #sidebar.sidebar .sidebar-links a .link-text { display: inline-block; opacity: 1; visibility: visible; transform: translateX(0);
            transition: opacity 0.24s ease, transform 0.3s cubic-bezier(0.4, 0, 0.2, 1), visibility 0s linear 0s; }
        #sidebar.sidebar.collapsed .sidebar-links a .link-text { display: inline-block; opacity: 0; visibility: hidden; transform: translateX(-8px);
            transition: opacity 0.12s ease, transform 0.18s ease, visibility 0s linear 0.12s; transition-delay: 0s, 0s, 0.12s; }
        #sidebar.sidebar:not(.collapsed) .sidebar-links a:nth-child(1) .link-text { transition-delay: 0.10s; }
        #sidebar.sidebar:not(.collapsed) .sidebar-links a:nth-child(2) .link-text { transition-delay: 0.12s; }
        #sidebar.sidebar:not(.collapsed) .sidebar-links a:nth-child(3) .link-text { transition-delay: 0.14s; }
        #sidebar.sidebar:not(.collapsed) .sidebar-links a:nth-child(4) .link-text { transition-delay: 0.16s; }
        #sidebar.sidebar:not(.collapsed) .sidebar-links a:nth-child(5) .link-text { transition-delay: 0.18s; }
        #sidebar.sidebar:not(.collapsed) .sidebar-links a:nth-child(6) .link-text { transition-delay: 0.20s; }
        #sidebar.sidebar:not(.collapsed) .sidebar-links a:nth-child(7) .link-text { transition-delay: 0.22s; }
        #sidebar.sidebar:not(.collapsed) .sidebar-links a:nth-child(8) .link-text { transition-delay: 0.24s; }
        #sidebar.sidebar:not(.collapsed) .sidebar-links a:nth-child(9) .link-text { transition-delay: 0.26s; }
        #sidebar.sidebar:not(.collapsed) .sidebar-links a:nth-child(10) .link-text { transition-delay: 0.28s; }
        /* admin name + role label */
        #sidebar.sidebar .sidebar-header-titles { transition: opacity 0.24s ease 0.14s, width 0.35s cubic-bezier(0.4, 0, 0.2, 1); }
        #sidebar.sidebar.collapsed .sidebar-header-titles { transition: opacity 0.12s ease 0s, width 0.35s cubic-bezier(0.4, 0, 0.2, 1); }
        /* UPDATED (this adjustment) — Logout button: no more blinking. It used to fade out completely while the
           menu moved and pop back afterwards (invisible for about half of every open / close), and its label was
           switched off / on in one step (display:none), so the icon jumped. Now it stays visible the whole time and
           simply morphs: the "Logout" label narrows + fades, the icon glides to the centre, the border fades —
           ending in exactly the same open / closed look as before. */
        #sidebar.sidebar .logout-link a { transition: border-color 0.3s ease, background-color 0.2s ease, color 0.2s ease; }
        #sidebar.sidebar .logout-link a i { flex: 0 0 30px; width: 30px; min-width: 30px; transition: margin-right 0.35s cubic-bezier(0.4, 0, 0.2, 1); }
        #sidebar.sidebar.collapsed .logout-link a i { margin-right: 0; }
        #sidebar.sidebar .logout-link a .link-text { display: inline-block; max-width: 90px; opacity: 1; overflow: hidden; white-space: nowrap; vertical-align: middle;
            transition: max-width 0.35s cubic-bezier(0.4, 0, 0.2, 1), opacity 0.2s ease 0.15s; }
        #sidebar.sidebar.collapsed .logout-link a .link-text { display: inline-block; max-width: 0; opacity: 0;
            transition: max-width 0.35s cubic-bezier(0.4, 0, 0.2, 1), opacity 0.1s ease 0s; }
        /* this page hides the Logout border when the menu is closed — do it with a transparent border (same look) so the
           button keeps its height instead of shrinking 2px in one step */
        #sidebar.sidebar.collapsed .logout-link a { border-style: solid; border-width: 1px; border-color: transparent; }
    }
    @media (prefers-reduced-motion: reduce) {
        #sidebar.sidebar, #sidebar.sidebar .sidebar-header-titles, html body .main-content,
        #sidebar.sidebar .sidebar-links a .link-text, #sidebar.sidebar .logout-link a { transition-duration: 0.01s !important; transition-delay: 0s !important; }
    }
</style>
<!-- NEW (this adjustment): LOGOUT CONFIRMATION POPUP — styles (same square navy look as the
     page's other confirmation dialogs, e.g. system_setting.php's). Used by the script at the end of the page. -->
<style>
    .cv-logout-overlay { position: fixed; inset: 0; z-index: 10050; display: flex; align-items: center; justify-content: center; padding: 20px;
        background: rgba(27, 42, 74, 0.45); opacity: 0; visibility: hidden; transition: opacity 0.2s ease, visibility 0.2s ease; }
    .cv-logout-overlay.show { opacity: 1; visibility: visible; }
    .cv-logout-box { background: #ffffff; width: 420px; max-width: 100%; border-top: 3px solid #1B2A4A; box-shadow: 0 20px 60px rgba(0, 0, 0, 0.25);
        padding: 26px 24px 22px; font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif; transform: translateY(8px); transition: transform 0.2s ease; }
    .cv-logout-overlay.show .cv-logout-box { transform: translateY(0); }
    .cv-logout-box h3 { margin: 0 0 10px; font-size: 16px; color: #1B2A4A; display: flex; align-items: center; gap: 10px; }
    .cv-logout-box h3 i { color: #1B2A4A; }
    .cv-logout-box p { margin: 0 0 22px; font-size: 13.5px; color: #4A5568; line-height: 1.6; }
    .cv-logout-actions { display: flex; justify-content: flex-end; gap: 8px; }
    .cv-logout-btn { display: inline-flex; align-items: center; gap: 8px; border: 1px solid #1B2A4A; cursor: pointer; font-family: inherit; font-size: 12px;
        font-weight: 600; letter-spacing: 0.4px; text-transform: uppercase; padding: 11px 18px; border-radius: 0; background: #1B2A4A; color: #ffffff; transition: opacity 0.2s ease; }
    .cv-logout-btn:hover { opacity: 0.88; }
    .cv-logout-btn:focus-visible { outline: 2px solid #F7C600; outline-offset: 2px; }
    .cv-logout-btn.ghost { background: #ffffff; color: #1B2A4A; border-color: #D5DBE6; }
    @media (prefers-reduced-motion: reduce) { .cv-logout-overlay, .cv-logout-box { transition: none; } }
</style>
<!-- ══════════════════════════════════════════════════════════════════════
     NEW (this adjustment) — ADMIN MONITORING DASHBOARD DESIGN
     ------------------------------------------------------------------------
     Applies admin_monitoring_dashboard.php's "Field ops grid" look to this
     page: #EEF1F6 page background, Segoe UI, the navy top navbar, a centred
     1200px container, square white boxes with thin #C3CADA grid lines, light
     #F4F6FA header bars, small bold uppercase headings, square navy primary /
     white bordered secondary buttons, and square navy-framed popups.
     Style only — placed last so it wins over the older rules; no id, class,
     handler or logic was changed. The sidebar keeps its shared look.
     ══════════════════════════════════════════════════════════════════════ -->
<style>
    :root {
        --fo-navy: #1B2A4A;
        --fo-line: #C3CADA;
        --fo-line-soft: #DDE2EC;
        --fo-muted: #5A6272;
        --fo-head: #F4F6FA;
        --fo-hover: #EAF0FA;
        --fo-green: #2C5A2C;
        --fo-green-bar: #4A7A3A;
        --fo-gold: #A0850A;
        --fo-red: #A02A2A;
        --bg: #EEF1F6;
        --text: #1B2A4A;
    }
    body { font-family: 'Segoe UI', sans-serif; background: var(--bg); color: var(--text); }
    .sidebar-header h2 { font-size: 18px; }

    /* ── page frame: navbar + container ── */
    .main-content { padding: 0; box-sizing: border-box; }
    .navbar { background: var(--neust-maroon); padding: 10px 30px; display: flex; justify-content: space-between; align-items: center; color: white; height: 60px; box-sizing: border-box; }
    .logo-section { display: flex; align-items: center; gap: 12px; }
    .university-logo { height: 40px; }
    .main-content > .container { padding: 30px; max-width: 1200px; margin: 0 auto; box-sizing: border-box; }

    /* ── Live Activity Log: square grid box with a header bar ── */
    .history-wrapper { background: #fff; border-radius: 0; padding: 0; margin-bottom: 16px; border: 1px solid var(--fo-line); }
    .history-wrapper::before { content: 'Live Activity Log'; padding: 14px 20px; margin: 0; background: var(--fo-head); border-bottom: 1px solid var(--fo-line); font-size: 14px; font-weight: 600; color: var(--fo-navy); text-transform: uppercase; letter-spacing: 0.4px; }
    #historyContainer { padding: 0 20px; }
    #historyContainer::-webkit-scrollbar-thumb { border-radius: 0; }
    .log-entry { border-bottom: 1px solid var(--fo-line-soft); }
    .log-entry-left strong { font-size: 13px; color: var(--fo-navy); font-weight: 600; }
    .log-entry-left div { font-size: 12.5px; color: var(--fo-muted); }
    .log-entry-right { font-size: 11px; color: #8A93A8; font-weight: 600; text-transform: uppercase; letter-spacing: 0.3px; }
    .badge { border-radius: 0; font-size: 10.5px; }
    /* NEW (this adjustment): "Request Rejected" log entries get their own red badge */
    .history-wrapper .log-entry .badge.badge-REQUEST-REJECTED { background: #F3E1DD; color: var(--fo-red); }

    /* ── account tables: square box, header bar, grid table ── */
    .table-section { background: #fff; border-radius: 0; padding: 0; margin-bottom: 16px; border: 1px solid var(--fo-line); overflow-x: auto; transition: box-shadow 0.15s; }
    .table-section:hover { box-shadow: 0 4px 14px rgba(27,42,74,0.14); }
    .table-section > div:first-child { margin-bottom: 0 !important; padding: 12px 20px; background: var(--fo-head); border-bottom: 1px solid var(--fo-line); flex-wrap: wrap; gap: 10px; }
    .table-section h3 { font-size: 14px; font-weight: 600; letter-spacing: 0.4px; color: var(--fo-navy); }
    .table-section table { border-radius: 0; font-size: 13px; }
    .table-section th { background: var(--fo-head); padding: 10px 12px; color: var(--fo-navy); border-bottom: 1px solid var(--fo-line); font-size: 11px; }
    .table-section td { padding: 10px 12px; border-bottom: 1px solid var(--fo-line-soft); color: var(--fo-muted); font-size: 13px; }
    .table-section td:first-child { color: var(--fo-navy); font-weight: 600; }
    .table-section tbody tr:hover td { background: var(--fo-hover); }
    .filter-input { border-radius: 0; font-size: 13px; padding: 8px 10px; border-color: var(--fo-line); color: var(--fo-navy); font-family: inherit; }
    /* UPDATED (this adjustment): search bar sizing — the "Filter Name..." box is
       wider with a search icon, and every filter control shares one height. */
    .table-section .filter-input { height: 36px; box-sizing: border-box; }
    .table-section select.filter-input { min-width: 180px; cursor: pointer; }
    .table-section input.filter-input {
        width: 280px; max-width: 100%; padding-left: 32px;
        background: #fff url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='14' height='14' viewBox='0 0 24 24' fill='none' stroke='%235A6272' stroke-width='2.4' stroke-linecap='round' stroke-linejoin='round'%3E%3Ccircle cx='11' cy='11' r='7'/%3E%3Cline x1='21' y1='21' x2='16.65' y2='16.65'/%3E%3C/svg%3E") no-repeat 11px center;
    }
    .table-section input.filter-input::placeholder { color: #8A93A8; }
    @media (max-width: 760px) { .table-section input.filter-input { width: 100%; } }
    .filter-input:focus { border-color: var(--fo-navy); }

    /* ── Status / Delete buttons: square outline buttons ── */
    .status-toggle-btn, .delete-btn-pill { border-radius: 0; padding: 7px 12px; font-size: 11.5px; font-weight: 600; letter-spacing: 0.4px; font-family: inherit; transition: background 0.15s, color 0.15s, border-color 0.15s; }
    .status-toggle-btn:hover, .delete-btn-pill:hover { opacity: 1; transform: none; }
    .status-toggle-btn.status-btn-active:hover   { background: var(--fo-green); color: #fff; }
    .status-toggle-btn.status-btn-inactive:hover { background: var(--fo-red);   color: #fff; }
    .delete-btn-pill:hover { background: var(--fo-red); color: #fff; }
    .status-toggle-btn:disabled:hover, .delete-btn-pill:disabled:hover { background: #fff; }
    .status-toggle-btn.status-btn-active:disabled:hover { color: var(--fo-green); }
    .status-toggle-btn.status-btn-inactive:disabled:hover, .delete-btn-pill:disabled:hover { color: var(--fo-red); }
    .status-toggle-btn:focus-visible, .delete-btn-pill:focus-visible { outline: 2px solid var(--fo-gold); outline-offset: 2px; }

    /* ── Quick-actions FAB: square navy ── */
    .fab-main-btn { border-radius: 0; background: var(--fo-navy); color: #fff; font-size: 22px; width: 56px; height: 56px; box-shadow: 0 6px 18px rgba(27,42,74,0.30); }
    .fab-main-btn:hover { background: #2A3D63; box-shadow: 0 8px 22px rgba(27,42,74,0.36); }
    .fab-main-badge, .fab-option-badge { border-radius: 0; }
    .fab-option { border-radius: 0; border: 1px solid var(--fo-line); font-family: inherit; font-size: 12px; font-weight: 600; text-transform: uppercase; letter-spacing: 0.4px; color: var(--fo-navy); box-shadow: 0 6px 18px rgba(27,42,74,0.16); padding: 6px 16px 6px 6px; }
    .fab-option:hover { transform: none; background: var(--fo-hover); box-shadow: 0 8px 22px rgba(27,42,74,0.22); }
    .fab-option-icon { border-radius: 0; background: var(--fo-navy); color: #fff; width: 32px; height: 32px; font-size: 13px; }

    /* ── Create Admin + My Profile modals (inline-styled) → square white boxes ── */
    .modal { background: rgba(27,42,74,0.55); backdrop-filter: none; }
    #modal > div, #profileModalBox { border-radius: 0 !important; border: 1px solid var(--fo-line); box-shadow: 0 12px 30px rgba(27,42,74,0.30) !important; font-family: 'Segoe UI', sans-serif; }
    #modal > div > h2, #profileModalBox h2 { color: var(--fo-navy) !important; font-size: 15px !important; text-transform: uppercase; letter-spacing: 0.6px; padding-bottom: 10px; border-bottom: 1px solid var(--fo-line); }
    #modal input, #modal select { border-radius: 0 !important; border: 1px solid var(--fo-line) !important; background: #fff !important; color: var(--fo-navy); font-family: inherit; font-size: 13.5px; padding: 10px 12px !important; }
    #modal input:focus, #modal select:focus { outline: none; border-color: var(--fo-navy) !important; }
    #modal #adminOtpInput { font-size: 1.1rem; }
    /* NEW (this adjustment): six-box OTP input (same kind of input as login.php) */
    #otpBoxesAdmin { display: flex; justify-content: center; gap: 9px; margin: 0 0 20px; }
    #modal #otpBoxesAdmin .otp-box {
        width: 46px !important; height: 54px; padding: 0 !important; text-align: center;
        font-size: 22px; font-weight: 700; font-family: inherit; color: var(--fo-navy);
        border: 1px solid var(--fo-line) !important; border-radius: 0 !important; background: #fff !important;
        box-sizing: border-box; outline: none; caret-color: var(--fo-navy);
        transition: border-color 0.15s, box-shadow 0.15s;
    }
    #modal #otpBoxesAdmin .otp-box:focus { border-color: var(--fo-navy) !important; box-shadow: 0 0 0 3px rgba(27,42,74,0.12); }
    #modal #otpBoxesAdmin .otp-box.otp-box-filled { border-color: var(--fo-navy) !important; }
    @media (max-width: 420px) { #modal #otpBoxesAdmin .otp-box { width: 38px !important; height: 46px; font-size: 18px; } #otpBoxesAdmin { gap: 6px; } }
    #btnCheckCreds, #btnVerifyOtp, #createAdminForm button[type="submit"], #profileModalBox > button {
        background: var(--fo-navy) !important; color: #fff !important; border: 1px solid var(--fo-navy) !important; border-radius: 0 !important;
        padding: 12px !important; font-size: 12px !important; font-weight: 700 !important; text-transform: uppercase; letter-spacing: 0.4px; font-family: inherit; transition: background 0.15s;
    }
    #btnCheckCreds:hover, #btnVerifyOtp:hover, #createAdminForm button[type="submit"]:hover, #profileModalBox > button:hover { background: #2A3D63 !important; }
    #modal button[onclick="closeModal()"], #modal button[onclick="goBackToPhase1()"] {
        background: #fff !important; color: var(--fo-navy) !important; border: 1px solid var(--fo-line) !important; border-radius: 0 !important;
        padding: 11px !important; font-size: 12px; text-transform: uppercase; letter-spacing: 0.4px; font-family: inherit;
    }
    #modal button[onclick="closeModal()"]:hover, #modal button[onclick="goBackToPhase1()"]:hover { background: var(--fo-hover) !important; }
    .phase-dot { border-radius: 0; }
    .phase-dot.current { background: var(--fo-navy); }
    .phase-dot.done { background: var(--fo-green-bar); }
    .phase-label { color: var(--fo-muted); font-size: 11px; font-weight: 700; }
    .admin-msg { border-radius: 0; font-size: 12.5px; font-weight: 600; }
    .admin-msg.error   { background: #fff; color: var(--fo-red); border-color: #E4C3C0; }
    .admin-msg.success { background: #fff; color: var(--fo-green); border-color: #C4D8C0; }
    .profile-avatar { border-radius: 0; background: var(--fo-navy); color: #fff; }
    .profile-row { border-bottom-color: var(--fo-line-soft); }
    .profile-label { color: var(--fo-muted); }
    .profile-value { color: var(--fo-navy); }

    /* ── Notification / Delete / Status confirmation popups → square navy-framed boxes ── */
    #notifOverlay, #deleteConfirmOverlay, #statusConfirmOverlay { background: rgba(27,42,74,0.45); backdrop-filter: none; }
    #notifBox, #deleteConfirmBox, #statusConfirmBox { border-radius: 0; border-top: 3px solid var(--fo-navy); box-shadow: 0 20px 60px rgba(0,0,0,0.25); font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif; }
    #deleteConfirmBox { border-top-color: var(--fo-red); }
    #notifBody, #deleteConfirmBox .dc-body, #statusConfirmBox .sc-body { padding: 26px 24px 22px; }
    #notifTitle, #statusConfirmBox .sc-title { font-size: 16px; font-weight: 700; text-transform: uppercase; letter-spacing: 0.5px; }
    #notifTitle.success { color: var(--fo-navy); }
    #notifTitle.error, #notifTitle.delete, #statusConfirmBox .sc-title.deactivate { color: var(--fo-red); }
    #notifTitle.warning { color: var(--fo-gold); }
    #statusConfirmBox .sc-title.activate { color: var(--fo-green); }
    #notifMsg, #deleteConfirmBox .dc-msg, #statusConfirmBox .sc-msg { font-size: 13.5px; color: #4A5568; line-height: 1.6; }
    #notifBtn, #deleteConfirmBox .dc-cancel, #deleteConfirmBox .dc-confirm, #statusConfirmBox .sc-cancel, #statusConfirmBox .sc-confirm {
        border-radius: 0; padding: 11px 18px; font-size: 12px; font-weight: 600; text-transform: uppercase; letter-spacing: 0.4px; font-family: inherit; box-shadow: none;
    }
    #notifBtn:hover { transform: none; box-shadow: none; opacity: 0.88; }
    #notifBtn.success { background: var(--fo-navy); color: #fff; }
    #notifBtn.error, #notifBtn.delete { background: var(--fo-red); color: #fff; }
    #notifBtn.warning { background: var(--fo-gold); color: #fff; }
    #deleteConfirmBox .dc-cancel, #statusConfirmBox .sc-cancel { background: #fff; color: var(--fo-navy); border: 1px solid #D5DBE6; }
    #deleteConfirmBox .dc-cancel:hover, #statusConfirmBox .sc-cancel:hover { background: var(--fo-hover); }
    #deleteConfirmBox .dc-confirm, #statusConfirmBox .sc-confirm.deactivate { background: var(--fo-red); border: 1px solid var(--fo-red); }
    #statusConfirmBox .sc-confirm.activate { background: var(--fo-green); border: 1px solid var(--fo-green); }
    #statusConfirmBox .sc-bar { height: 3px; }
    #statusConfirmBox .sc-bar.deactivate { background: var(--fo-red); }
    #statusConfirmBox .sc-bar.activate { background: var(--fo-green); }
    #statusConfirmBox:has(.sc-bar.activate), #statusConfirmBox:has(.sc-bar.deactivate) { border-top: none; }
    .acct-details { border-radius: 0; background: var(--fo-head); border-color: var(--fo-line); }
    .acct-details-row { border-bottom-color: var(--fo-line-soft); }
    .acct-details-label { color: var(--fo-muted); }
    .acct-details-value { color: var(--fo-navy); }

    /* ── Email Recovery Requests drawer ── */
    #recoveryDrawerOverlay { background: rgba(27,42,74,0.55); }
    #recoveryDrawer { font-family: 'Segoe UI', sans-serif; box-shadow: -10px 0 30px rgba(27,42,74,0.30); }
    #recoveryDrawerHeader { background: var(--fo-navy); padding: 14px 20px; }
    #recoveryDrawerHeader h3 { color: #fff; font-size: 12px; text-transform: uppercase; letter-spacing: 0.6px; }
    #recoveryDrawerHeader h3 span { border-radius: 0 !important; }
    #recoveryDrawerBody { background: var(--fo-head); padding: 16px 20px; }
    /* UPDATED (this adjustment): request card layout — header (photo, name,
       type, submitted time), a tidy 2-column grid of labelled fields, a
       labelled reason, and a footer with the buttons / result tag.
       No coloured left-side accent anywhere. */
    .req-card { display: block; border-radius: 0; border: 1px solid var(--fo-line); background: #fff; margin-bottom: 10px; padding: 0; }
    .req-card.accepted, .req-card.rejected { border-color: var(--fo-line); background: #fff; }
    .req-card-head { display: flex; align-items: center; gap: 12px; padding: 12px 14px; border-bottom: 1px solid var(--fo-line-soft); }
    .req-selfie, .req-selfie-placeholder { width: 52px; height: 52px; border-radius: 0; border: 1px solid var(--fo-line); flex-shrink: 0; }
    .req-selfie { object-fit: cover; }
    .req-selfie-placeholder { background: var(--fo-head); color: #8A93A8; font-size: 20px; display: flex; align-items: center; justify-content: center; }
    .req-head-info { min-width: 0; flex: 1; }
    .req-card .req-name { color: var(--fo-navy); font-size: 14px; font-weight: 700; margin: 0 0 5px; text-transform: capitalize; overflow-wrap: anywhere; }
    .req-head-meta { display: flex; align-items: center; flex-wrap: wrap; gap: 8px; }
    .req-head-meta .req-type-badge { margin: 0; }
    .req-head-meta .req-timestamp { margin: 0; display: inline-flex; align-items: center; gap: 5px; }
    .req-fields { display: grid; grid-template-columns: 1fr 1fr; gap: 1px; background: var(--fo-line-soft); border-bottom: 1px solid var(--fo-line-soft); }
    .req-field { padding: 9px 14px; background: #fff; min-width: 0; }
    .req-field.wide { grid-column: 1 / -1; }
    .req-field-label { display: block; font-size: 10.5px; font-weight: 700; color: var(--fo-muted); text-transform: uppercase; letter-spacing: 0.4px; margin-bottom: 3px; }
    .req-field-value { display: block; font-size: 13px; color: var(--fo-navy); font-weight: 600; overflow-wrap: break-word; word-break: normal; line-height: 1.4; }
    .req-card .req-reason-box { margin: 0; padding: 0; background: none; border: none; border-radius: 0; font-weight: 400; color: var(--fo-navy); font-size: 13px; white-space: pre-line; }
    /* UPDATED (this adjustment): actions / status in the top-right corner, level with the name */
    .req-card-head { align-items: flex-start; }
    .req-name-row { display: flex; align-items: center; justify-content: space-between; gap: 10px; margin-bottom: 5px; }
    .req-name-row .req-name { margin: 0; flex: 1 1 140px; min-width: 0; overflow-wrap: break-word; }
    .req-name-row { flex-wrap: wrap; }
    .req-head-actions { flex-shrink: 0; display: flex; align-items: center; }
    .req-head-actions .req-btn-row { margin: 0; gap: 6px; }
    .req-icon-btn { width: 32px; height: 32px; padding: 0 !important; display: inline-flex; align-items: center; justify-content: center; font-size: 13px !important; }
    .req-head-actions .req-accepted-tag, .req-head-actions .req-rejected-tag { margin: 0; padding: 5px 9px; font-size: 10.5px; white-space: nowrap; }

    /* UPDATED (this adjustment): compact request cards (Pending + History) —
       smaller photo, name, labels, values, spacing and icon buttons so each
       request takes far less room. Layout and behaviour are unchanged. */
    #recoveryDrawerBody { padding: 12px 14px; }
    #recoveryDrawer .req-card { margin-bottom: 8px; }
    #recoveryDrawer .req-card-head { gap: 10px; padding: 9px 12px; }
    #recoveryDrawer .req-selfie, #recoveryDrawer .req-selfie-placeholder { width: 40px; height: 40px; }
    #recoveryDrawer .req-selfie-placeholder { font-size: 15px; }
    #recoveryDrawer .req-card .req-name { font-size: 13px; margin-bottom: 3px; }
    #recoveryDrawer .req-name-row { margin-bottom: 3px; gap: 8px; }
    #recoveryDrawer .req-head-meta { gap: 6px; }
    #recoveryDrawer .req-type-badge { font-size: 9.5px; padding: 1px 7px; }
    #recoveryDrawer .req-timestamp { font-size: 10.5px; }
    #recoveryDrawer .req-field { padding: 6px 12px; }
    #recoveryDrawer .req-field-label { font-size: 9.5px; margin-bottom: 1px; letter-spacing: 0.3px; }
    #recoveryDrawer .req-field-value { font-size: 12px; line-height: 1.35; }
    #recoveryDrawer .req-card .req-reason-box { font-size: 12px; }
    #recoveryDrawer .req-icon-btn { width: 26px; height: 26px; font-size: 11px !important; }
    #recoveryDrawer .req-head-actions .req-btn-row { gap: 5px; }
    #recoveryDrawer .req-head-actions .req-accepted-tag,
    #recoveryDrawer .req-head-actions .req-rejected-tag { padding: 3px 7px; font-size: 9.5px; }
    #recoveryDrawer .req-tab { padding: 10px 8px; font-size: 11px; }
    #recoveryDrawer .req-history-bar { padding: 7px 10px; margin-bottom: 8px; }
    #recoveryDrawer .req-clear-btn { padding: 5px 10px; font-size: 10.5px; }
    #recoveryDrawer .req-empty { padding: 26px 14px; font-size: 12.5px; }
    .req-type-badge { border-radius: 0; font-size: 10.5px; letter-spacing: 0.4px; }
    .req-type-badge.student { background: #DDE6F3; color: var(--fo-navy); }
    .req-type-badge.company { background: #F4EBD3; color: var(--fo-gold); }
    .req-accept-btn, .req-reject-btn { border-radius: 0; padding: 7px 14px; font-size: 11.5px; font-weight: 600; text-transform: uppercase; letter-spacing: 0.4px; font-family: inherit; }
    .req-accept-btn { background: #fff; color: var(--fo-green); border: 1px solid var(--fo-green-bar); }
    .req-accept-btn:hover:not(:disabled) { background: var(--fo-green); color: #fff; opacity: 1; }
    .req-reject-btn { background: #fff; color: var(--fo-red); border: 1px solid var(--fo-red); }
    .req-reject-btn:hover:not(:disabled) { background: var(--fo-red); color: #fff; opacity: 1; }
    .req-accepted-tag, .req-rejected-tag { border-radius: 0; font-size: 11px; text-transform: uppercase; letter-spacing: 0.4px; padding: 5px 10px; }
    .req-accepted-tag { background: #E4EEDF; color: var(--fo-green); }
    .req-rejected-tag { background: #F3E1DD; color: var(--fo-red); }
    .req-timestamp { color: #8A93A8; font-size: 11px; }
    .req-empty { color: var(--fo-muted); background: #fff; border: 1px solid var(--fo-line); font-size: 13px; padding: 36px 16px; }
    .req-empty i { color: var(--fo-line) !important; }

    /* NEW (Recovery request history): Pending / History tabs + Clear History bar */
    .req-tabs { display: flex; background: #fff; border-bottom: 1px solid var(--fo-line); flex-shrink: 0; }
    .req-tab { flex: 1; display: inline-flex; align-items: center; justify-content: center; gap: 8px; padding: 12px 10px; background: #fff; border: none; border-bottom: 3px solid transparent;
        color: var(--fo-muted); font-family: inherit; font-size: 11.5px; font-weight: 700; text-transform: uppercase; letter-spacing: 0.5px; cursor: pointer; transition: background 0.15s, color 0.15s, border-color 0.15s; }
    .req-tab:hover { background: var(--fo-hover); color: var(--fo-navy); }
    .req-tab.active { color: var(--fo-navy); border-bottom-color: var(--fo-navy); background: var(--fo-head); }
    .req-tab-count { min-width: 20px; height: 20px; padding: 0 6px; box-sizing: border-box; display: inline-flex; align-items: center; justify-content: center;
        background: var(--fo-head); border: 1px solid var(--fo-line); color: var(--fo-navy); font-size: 10.5px; font-weight: 700; }
    .req-tab.active .req-tab-count { background: var(--fo-navy); border-color: var(--fo-navy); color: #fff; }
    .req-history-bar { display: flex; justify-content: space-between; align-items: center; gap: 10px; flex-wrap: wrap; margin-bottom: 10px; padding: 10px 12px; background: #fff; border: 1px solid var(--fo-line); }
    .req-history-note { font-size: 11px; font-weight: 700; color: var(--fo-muted); text-transform: uppercase; letter-spacing: 0.4px; }
    .req-clear-btn { display: inline-flex; align-items: center; gap: 6px; background: #fff; color: var(--fo-red); border: 1px solid var(--fo-red); border-radius: 0; padding: 7px 12px;
        font-family: inherit; font-size: 11.5px; font-weight: 600; text-transform: uppercase; letter-spacing: 0.4px; cursor: pointer; transition: background 0.15s, color 0.15s; }
    .req-clear-btn:hover:not(:disabled) { background: var(--fo-red); color: #fff; }
    .req-clear-btn:disabled { opacity: 0.45; cursor: not-allowed; }
    #clearHistoryConfirmOverlay .cv-logout-box { border-top-color: var(--fo-red); }
    #clearHistoryConfirmOverlay .cv-logout-box h3 i { color: var(--fo-red); }
    #clearHistoryConfirmBtn { background: var(--fo-red); border-color: var(--fo-red); }

    /* ── Reject reason modal ── */
    #rejectReasonOverlay { background: rgba(27,42,74,0.55); }
    #rejectReasonBox { border-radius: 0; border-top: 3px solid var(--fo-red); padding: 26px 24px 22px; box-shadow: 0 20px 60px rgba(0,0,0,0.25); font-family: 'Segoe UI', sans-serif; animation: globalLoadingPop 0.25s ease; }
    #rejectReasonBox h4 { color: var(--fo-red); font-size: 16px; text-transform: uppercase; letter-spacing: 0.5px; }
    #rejectReasonBox p { color: #4A5568; font-size: 13.5px; }
    #rejectReasonInput { border-radius: 0; border: 1px solid var(--fo-line); background: #fff; color: var(--fo-navy); font-size: 13px; }
    #rejectReasonInput:focus { border-color: var(--fo-navy); }
    .rr-cancel, .rr-confirm { border-radius: 0; padding: 11px 18px; font-size: 12px; font-weight: 600; text-transform: uppercase; letter-spacing: 0.4px; font-family: inherit; }
    .rr-cancel { background: #fff; color: var(--fo-navy); border: 1px solid #D5DBE6; }
    .rr-cancel:hover { background: var(--fo-hover); }
    .rr-confirm { background: var(--fo-red); border: 1px solid var(--fo-red); }

    /* NEW (this adjustment): Accept confirmation popup — same design as the
       Reject Recovery Request popup, with a green accent. */
    #acceptConfirmOverlay { display: none; position: fixed; inset: 0; background: rgba(27,42,74,0.55); z-index: 4000; justify-content: center; align-items: center; }
    #acceptConfirmBox { background: #fff; width: 400px; max-width: 92%; border-radius: 0; border-top: 3px solid var(--fo-green); padding: 26px 24px 22px; box-shadow: 0 20px 60px rgba(0,0,0,0.25); font-family: 'Segoe UI', sans-serif; animation: globalLoadingPop 0.25s ease; box-sizing: border-box; }
    #acceptConfirmBox h4 { margin: 0 0 8px; color: var(--fo-green); font-size: 16px; text-transform: uppercase; letter-spacing: 0.5px; }
    #acceptConfirmBox p { margin: 0 0 14px; font-size: 13.5px; color: #4A5568; line-height: 1.6; }
    #acceptConfirmBox .ac-summary { background: var(--fo-head); border: 1px solid var(--fo-line); padding: 4px 12px; margin-bottom: 16px; }
    #acceptConfirmBox .ac-row { display: flex; justify-content: space-between; gap: 12px; padding: 7px 0; border-bottom: 1px solid var(--fo-line-soft); font-size: 12.5px; }
    #acceptConfirmBox .ac-row:last-child { border-bottom: none; }
    #acceptConfirmBox .ac-row span { color: var(--fo-muted); white-space: nowrap; }
    #acceptConfirmBox .ac-row strong { color: var(--fo-navy); text-align: right; overflow-wrap: anywhere; }
    .ac-confirm { padding: 11px 18px; background: var(--fo-green); color: #fff; border: 1px solid var(--fo-green); border-radius: 0; font-weight: 600; font-size: 12px; text-transform: uppercase; letter-spacing: 0.4px; font-family: inherit; cursor: pointer; display: inline-flex; align-items: center; gap: 6px; transition: opacity 0.2s; }
    .ac-confirm:hover { opacity: 0.88; }
    .ac-confirm:disabled { opacity: 0.4; cursor: not-allowed; }

    @media (max-width: 760px) {
        .main-content > .container { padding: 18px; }
    }
    @media (prefers-reduced-motion: reduce) {
        .table-section, .status-toggle-btn, .delete-btn-pill, .req-tab, .req-clear-btn { transition: none; }
    }
</style>
<!-- NEW (this adjustment): BUTTON TOOLTIPS — same design as the "Apply Students" tooltip on
     admin_monitoring_dashboard.php (#amdFloatTip): square navy box, small bold uppercase white text,
     soft shadow, arrow pointing at the button. Used by the script at the end of the page. -->
<style>
    /* UPDATED (this adjustment): drawn above every popup / dialog (their backdrops go up to 10050) so a tooltip for a
       button INSIDE a popup (e.g. the Export popup's close button) is no longer hidden behind the popup */
    #cvBtnTip { position: fixed; z-index: 20010; background: #1B2A4A; color: #fff; font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif; font-size: 10.5px; font-weight: 600; line-height: 1.3; text-transform: uppercase; letter-spacing: 0.3px; padding: 6px 10px; border-radius: 0; white-space: nowrap; box-shadow: 0 4px 14px rgba(27,42,74,0.30); pointer-events: none; opacity: 0; transform: translateY(4px); transition: opacity .15s, transform .15s; }
    #cvBtnTip.show { opacity: 1; transform: translateY(0); }
    #cvBtnTip::after { content: ''; position: absolute; top: 100%; left: var(--arrow-x, 50%); transform: translateX(-50%); border: 6px solid transparent; border-top-color: #1B2A4A; }
    #cvBtnTip.below { transform: translateY(-4px); }
    #cvBtnTip.below.show { transform: translateY(0); }
    #cvBtnTip.below::after { top: auto; bottom: 100%; border-top-color: transparent; border-bottom-color: #1B2A4A; }
    @media (prefers-reduced-motion: reduce) { #cvBtnTip { transition: none; } }
</style>
<!-- NEW (this adjustment): Live Activity Log tools (search / action filter / Clear Log) + new action badges -->
<style>
    .alog-toolbar { display: flex; align-items: stretch; gap: 10px; padding: 12px 20px; border-bottom: 1px solid var(--fo-line, #C3CADA); background: #fff; flex-wrap: wrap; }
    .alog-search { flex: 1; min-width: 220px; display: flex; align-items: center; gap: 8px; border: 1px solid var(--fo-line, #C3CADA); background: #fff; padding: 0 12px; }
    .alog-search i { color: #8A93A8; font-size: 12px; }
    .alog-search input { flex: 1; border: none; outline: none; padding: 9px 0; font-size: 13px; font-family: inherit; color: var(--fo-navy, #1B2A4A); background: transparent; }
    .alog-search:focus-within { border-color: var(--fo-navy, #1B2A4A); }
    #alogAction { min-width: 190px; border: 1px solid var(--fo-line, #C3CADA); border-radius: 0; padding: 8px 12px; font-size: 13px; font-family: inherit; color: var(--fo-navy, #1B2A4A); background: #fff; cursor: pointer; }
    #alogAction:focus { outline: none; border-color: var(--fo-navy, #1B2A4A); }
    .alog-clear-btn { display: inline-flex; align-items: center; gap: 8px; border: 1px solid var(--fo-red, #A02A2A); background: #fff; color: var(--fo-red, #A02A2A); border-radius: 0;
        padding: 8px 16px; font-size: 12px; font-weight: 600; text-transform: uppercase; letter-spacing: 0.4px; font-family: inherit; cursor: pointer; transition: background 0.15s, color 0.15s; }
    .alog-clear-btn:hover { background: var(--fo-red, #A02A2A); color: #fff; }
    .alog-clear-btn:disabled { opacity: 0.55; cursor: default; }
    .alog-no-match { text-align: center; color: #666; padding: 20px; margin: 0; }
    .cv-logout-btn.cv-alog-danger { background: var(--fo-red, #A02A2A); border-color: var(--fo-red, #A02A2A); }
    /* colours for the new action types logged from the other admin pages (existing badges keep their own colours) */
    .history-wrapper .log-entry .badge:not([class*="badge-ACCOUNT"]):not(.badge-LOGIN):not(.badge-LOGOUT):not(.badge-REQUEST-REJECTED)[class*="-ADDED"],
    .history-wrapper .log-entry .badge:not([class*="badge-ACCOUNT"]):not(.badge-LOGIN):not(.badge-LOGOUT):not(.badge-REQUEST-REJECTED)[class*="-CREATED"],
    .history-wrapper .log-entry .badge:not([class*="badge-ACCOUNT"]):not(.badge-LOGIN):not(.badge-LOGOUT):not(.badge-REQUEST-REJECTED)[class*="-IMPORTED"],
    .history-wrapper .log-entry .badge:not([class*="badge-ACCOUNT"]):not(.badge-LOGIN):not(.badge-LOGOUT):not(.badge-REQUEST-REJECTED)[class*="-VERIFIED"],
    .history-wrapper .log-entry .badge:not([class*="badge-ACCOUNT"]):not(.badge-LOGIN):not(.badge-LOGOUT):not(.badge-REQUEST-REJECTED)[class*="-APPROVED"],
    .history-wrapper .log-entry .badge:not([class*="badge-ACCOUNT"]):not(.badge-LOGIN):not(.badge-LOGOUT):not(.badge-REQUEST-REJECTED)[class*="-ACCEPTED"],
    .history-wrapper .log-entry .badge:not([class*="badge-ACCOUNT"]):not(.badge-LOGIN):not(.badge-LOGOUT):not(.badge-REQUEST-REJECTED)[class*="-PUBLISHED"]:not([class*="UNPUBLISHED"]),
    .history-wrapper .log-entry .badge:not([class*="badge-ACCOUNT"]):not(.badge-LOGIN):not(.badge-LOGOUT):not(.badge-REQUEST-REJECTED)[class*="-RESTORED"],
    .history-wrapper .log-entry .badge:not([class*="badge-ACCOUNT"]):not(.badge-LOGIN):not(.badge-LOGOUT):not(.badge-REQUEST-REJECTED)[class*="-APPLIED"],
    .history-wrapper .log-entry .badge:not([class*="badge-ACCOUNT"]):not(.badge-LOGIN):not(.badge-LOGOUT):not(.badge-REQUEST-REJECTED)[class*="-DONE"] { background: #E4EEDF; color: #2C5A2C; }
    .history-wrapper .log-entry .badge:not([class*="badge-ACCOUNT"]):not(.badge-LOGIN):not(.badge-LOGOUT):not(.badge-REQUEST-REJECTED)[class*="-DELETED"],
    .history-wrapper .log-entry .badge:not([class*="badge-ACCOUNT"]):not(.badge-LOGIN):not(.badge-LOGOUT):not(.badge-REQUEST-REJECTED)[class*="-REJECTED"],
    .history-wrapper .log-entry .badge:not([class*="badge-ACCOUNT"]):not(.badge-LOGIN):not(.badge-LOGOUT):not(.badge-REQUEST-REJECTED)[class*="-DENIED"],
    .history-wrapper .log-entry .badge:not([class*="badge-ACCOUNT"]):not(.badge-LOGIN):not(.badge-LOGOUT):not(.badge-REQUEST-REJECTED)[class*="-CLEARED"] { background: #F3E1DD; color: var(--fo-red, #A02A2A); }
    .history-wrapper .log-entry .badge:not([class*="badge-ACCOUNT"]):not(.badge-LOGIN):not(.badge-LOGOUT):not(.badge-REQUEST-REJECTED)[class*="-UPDATED"],
    .history-wrapper .log-entry .badge:not([class*="badge-ACCOUNT"]):not(.badge-LOGIN):not(.badge-LOGOUT):not(.badge-REQUEST-REJECTED)[class*="-UNDONE"],
    .history-wrapper .log-entry .badge:not([class*="badge-ACCOUNT"]):not(.badge-LOGIN):not(.badge-LOGOUT):not(.badge-REQUEST-REJECTED)[class*="-ARCHIVED"],
    .history-wrapper .log-entry .badge:not([class*="badge-ACCOUNT"]):not(.badge-LOGIN):not(.badge-LOGOUT):not(.badge-REQUEST-REJECTED)[class*="-FLAGGED"],
    .history-wrapper .log-entry .badge:not([class*="badge-ACCOUNT"]):not(.badge-LOGIN):not(.badge-LOGOUT):not(.badge-REQUEST-REJECTED)[class*="UNPUBLISHED"],
    .history-wrapper .log-entry .badge:not([class*="badge-ACCOUNT"]):not(.badge-LOGIN):not(.badge-LOGOUT):not(.badge-REQUEST-REJECTED)[class*="-PENDING"] { background: #F4EBD3; color: #7A6508; }
    @media (max-width: 700px) { #alogAction, .alog-clear-btn { flex: 1; } }
</style>
<!-- NEW (this adjustment): CLICKABLE NOTIFICATION POPUPS — styles (used by the script at the end of the page) -->
<style>
    .cv-top-toast[data-cv-go] { pointer-events: auto; cursor: pointer; transition: opacity 0.35s, top 0.3s ease, background-color 0.15s ease; }
    .cv-top-toast[data-cv-go]:hover { background: #24375E; }
    .cv-top-toast[data-cv-go]:focus-visible { outline: 2px solid #F7C600; outline-offset: 2px; }
    .cv-top-toast .cv-toast-go { flex-shrink: 0; margin-left: 6px; color: #F7C600; font-size: 10.5px; font-weight: 700; text-transform: uppercase; letter-spacing: 0.4px; white-space: nowrap; }
    .cv-top-toast .cv-toast-go i { color: inherit; font-size: 9px; margin-left: 3px; }
    /* the item a popup led to is briefly highlighted when it is shown */
    .cv-go-highlight { outline: 2px solid #F7C600 !important; outline-offset: 2px; animation: cvGoFlash 2.6s ease; }
    @keyframes cvGoFlash { 0%, 55% { box-shadow: 0 0 0 5px rgba(247, 198, 0, 0.35); } 100% { box-shadow: 0 0 0 0 rgba(247, 198, 0, 0); } }
</style>
<!-- ══════════════════════════════════════════════════════════════════════
     NEW (this adjustment) — LOADING PAGE: SHOWN DIRECTLY, EVERY TIME
     ------------------------------------------------------------------------
     The loading page sometimes did not appear, because:
       • while the page was still loading, anything above this page's loading
         overlay in the HTML could be painted before the overlay existed;
       • many of the page's actions (saves, deletes, status changes …) sent
         their request without showing it — only some actions called
         showGlobalLoading();
       • reloads / redirects started by the page's own script (after an
         action) did not show it on every page.
     This block (in <head>, so it runs before anything else on the page):
       1. FIRST PAINT — the page opens under a loading screen with the same
          look as this page's loading overlay (spinner + "LOADING"), until the
          real #globalLoadingOverlay is in place; then that one simply takes
          over (it is already visible at that moment), so there is no gap.
       2. ACTIONS — any request that changes something (POST …) and starts
          right after a click / key press / form change shows the loading page
          straight away ("Processing"), for at least 350 ms so it never
          flickers, and hides it when the request is done. Background checks
          (notification polls, look-ups, previews, the dashboard chat, the
          commits that run after an undo window) never show it, and a request
          the page already covers with its own loading page is left to it.
       3. LEAVING BY SCRIPT — a reload / redirect started by the page shows the
          loading page too (released after a few seconds if it was really a
          file download, which never leaves the page).
     Self-contained: the page's own loading logic, labels and design are
     unchanged; fetch / XMLHttpRequest keep working exactly as before.
     ══════════════════════════════════════════════════════════════════════ -->
<style>
    /* the first-paint ring: exactly where the page's own spinner is; its size and look come from the shared ring rule below,
       and it carries on from the previous page's loading page (--cv-ring-delay). CLEAN-UP (audit): two rules merged into one. */
    html.cv-booting::before { content: ''; position: fixed; left: 50%; top: 50%; margin: -47.5px 0 0 -32px; z-index: 20002;
        animation: cvRingSpin 1s steps(12, end) infinite; animation-delay: var(--cv-ring-delay, 0s); }
    html.cv-booting::after { content: 'LOADING'; position: fixed; inset: 0; z-index: 20001; display: flex; align-items: center; justify-content: center;
        padding: 80px 20.7px 0 0; box-sizing: border-box; background: rgba(238, 241, 246, 0.92); color: #1B2A4A;   /* UPDATED (this adjustment): label exactly where the page's own label is */
        font: 700 13px 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif; letter-spacing: 0.6px; }
    /* NEW (this adjustment): ENHANCED LOADING RING — instead of one solid arc sweeping round, 12 rounded segments
       in the site's navy that fade from dark to light around the circle and tick round (like a classic activity
       indicator). Same 64 px footprint and position as before, so nothing else moves. Used by the first-paint
       cover AND by this page's own loading page, so both always look identical. */
    html.cv-booting::before,
    #globalLoadingOverlay .global-loading-spinner {
        width: 64px; height: 64px; border: 0; border-radius: 50%; box-sizing: border-box;
        background: conic-gradient(from 0deg, rgba(27,42,74,0.12) 0deg, rgba(27,42,74,0.35) 120deg, rgba(27,42,74,0.7) 240deg, #1B2A4A 330deg, #1B2A4A 360deg);
        -webkit-mask: radial-gradient(farthest-side, transparent calc(100% - 9px), #000 calc(100% - 8px)),
                      repeating-conic-gradient(from 5deg, #000 0deg 20deg, transparent 20deg 30deg);
        -webkit-mask-composite: source-in;
                mask: radial-gradient(farthest-side, transparent calc(100% - 9px), #000 calc(100% - 8px)),
                      repeating-conic-gradient(from 5deg, #000 0deg 20deg, transparent 20deg 30deg);
                mask-composite: intersect;
    }
    #globalLoadingOverlay .global-loading-spinner { animation: cvRingSpin 1s steps(12, end) infinite; }
    @keyframes cvRingSpin { to { transform: rotate(360deg); } }
    /* NEW (this adjustment): the animated dots after "LOADING", like the page's own loading page, so nothing changes
       when the page's loading page takes over */
    /* UPDATED (this adjustment): the three dots fade one after another exactly like the page's own dots
       (same 1.2 s cycle, 0.2 s apart), so they simply carry on when the page's loading page takes over */
    html.cv-booting body::before { content: '.'; position: fixed; left: calc(50% + 29.75px); top: calc(50% + 32.5px); z-index: 20003;
        font: 700 13px/15px 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif; letter-spacing: 0.6px; color: rgba(27,42,74,0);
        animation: cvBootDots 1.2s linear infinite; animation-delay: var(--cv-dots-delay, 0s); pointer-events: none; }
    @keyframes cvBootDots {
        0.0% { color: rgba(27,42,74,0.0); text-shadow: 4.23px 0 rgba(27,42,74,0.075), 8.47px 0 rgba(27,42,74,0.424); }
        2.5% { color: rgba(27,42,74,0.0); text-shadow: 4.23px 0 rgba(27,42,74,0.052), 8.47px 0 rgba(27,42,74,0.342); }
        5.0% { color: rgba(27,42,74,0.0); text-shadow: 4.23px 0 rgba(27,42,74,0.034), 8.47px 0 rgba(27,42,74,0.273); }
        7.5% { color: rgba(27,42,74,0.0); text-shadow: 4.23px 0 rgba(27,42,74,0.02), 8.47px 0 rgba(27,42,74,0.215); }
        10.0% { color: rgba(27,42,74,0.0); text-shadow: 4.23px 0 rgba(27,42,74,0.01), 8.47px 0 rgba(27,42,74,0.166); }
        12.5% { color: rgba(27,42,74,0.0); text-shadow: 4.23px 0 rgba(27,42,74,0.004), 8.47px 0 rgba(27,42,74,0.126); }
        15.0% { color: rgba(27,42,74,0.0); text-shadow: 4.23px 0 rgba(27,42,74,0.001), 8.47px 0 rgba(27,42,74,0.094); }
        17.5% { color: rgba(27,42,74,0.0); text-shadow: 4.23px 0 rgba(27,42,74,0.0), 8.47px 0 rgba(27,42,74,0.067); }
        20.0% { color: rgba(27,42,74,0.0); text-shadow: 4.23px 0 rgba(27,42,74,0.0), 8.47px 0 rgba(27,42,74,0.046); }
        22.5% { color: rgba(27,42,74,0.071); text-shadow: 4.23px 0 rgba(27,42,74,0.0), 8.47px 0 rgba(27,42,74,0.029); }
        25.0% { color: rgba(27,42,74,0.221); text-shadow: 4.23px 0 rgba(27,42,74,0.0), 8.47px 0 rgba(27,42,74,0.017); }
        27.5% { color: rgba(27,42,74,0.409); text-shadow: 4.23px 0 rgba(27,42,74,0.0), 8.47px 0 rgba(27,42,74,0.008); }
        30.0% { color: rgba(27,42,74,0.576); text-shadow: 4.23px 0 rgba(27,42,74,0.0), 8.47px 0 rgba(27,42,74,0.002); }
        32.5% { color: rgba(27,42,74,0.706); text-shadow: 4.23px 0 rgba(27,42,74,0.0), 8.47px 0 rgba(27,42,74,0.0); }
        35.0% { color: rgba(27,42,74,0.802); text-shadow: 4.23px 0 rgba(27,42,74,0.0), 8.47px 0 rgba(27,42,74,0.0); }
        37.5% { color: rgba(27,42,74,0.874); text-shadow: 4.23px 0 rgba(27,42,74,0.015), 8.47px 0 rgba(27,42,74,0.0); }
        40.0% { color: rgba(27,42,74,0.925); text-shadow: 4.23px 0 rgba(27,42,74,0.113), 8.47px 0 rgba(27,42,74,0.0); }
        42.5% { color: rgba(27,42,74,0.96); text-shadow: 4.23px 0 rgba(27,42,74,0.283), 8.47px 0 rgba(27,42,74,0.0); }
        45.0% { color: rgba(27,42,74,0.983); text-shadow: 4.23px 0 rgba(27,42,74,0.468), 8.47px 0 rgba(27,42,74,0.0); }
        47.5% { color: rgba(27,42,74,0.996); text-shadow: 4.23px 0 rgba(27,42,74,0.623), 8.47px 0 rgba(27,42,74,0.0); }
        50.0% { color: rgba(27,42,74,1.0); text-shadow: 4.23px 0 rgba(27,42,74,0.741), 8.47px 0 rgba(27,42,74,0.0); }
        52.5% { color: rgba(27,42,74,0.967); text-shadow: 4.23px 0 rgba(27,42,74,0.829), 8.47px 0 rgba(27,42,74,0.0); }
        55.0% { color: rgba(27,42,74,0.905); text-shadow: 4.23px 0 rgba(27,42,74,0.893), 8.47px 0 rgba(27,42,74,0.038); }
        57.5% { color: rgba(27,42,74,0.815); text-shadow: 4.23px 0 rgba(27,42,74,0.938), 8.47px 0 rgba(27,42,74,0.163); }
        60.0% { color: rgba(27,42,74,0.705); text-shadow: 4.23px 0 rgba(27,42,74,0.969), 8.47px 0 rgba(27,42,74,0.346); }
        62.5% { color: rgba(27,42,74,0.591); text-shadow: 4.23px 0 rgba(27,42,74,0.989), 8.47px 0 rgba(27,42,74,0.524); }
        65.0% { color: rgba(27,42,74,0.487); text-shadow: 4.23px 0 rgba(27,42,74,0.998), 8.47px 0 rgba(27,42,74,0.666); }
        67.5% { color: rgba(27,42,74,0.395); text-shadow: 4.23px 0 rgba(27,42,74,0.992), 8.47px 0 rgba(27,42,74,0.773); }
        70.0% { color: rgba(27,42,74,0.317); text-shadow: 4.23px 0 rgba(27,42,74,0.95), 8.47px 0 rgba(27,42,74,0.852); }
        72.5% { color: rgba(27,42,74,0.252); text-shadow: 4.23px 0 rgba(27,42,74,0.878), 8.47px 0 rgba(27,42,74,0.91); }
        75.0% { color: rgba(27,42,74,0.198); text-shadow: 4.23px 0 rgba(27,42,74,0.779), 8.47px 0 rgba(27,42,74,0.95); }
        77.5% { color: rgba(27,42,74,0.152); text-shadow: 4.23px 0 rgba(27,42,74,0.667), 8.47px 0 rgba(27,42,74,0.977); }
        80.0% { color: rgba(27,42,74,0.115); text-shadow: 4.23px 0 rgba(27,42,74,0.555), 8.47px 0 rgba(27,42,74,0.993); }
        82.5% { color: rgba(27,42,74,0.084); text-shadow: 4.23px 0 rgba(27,42,74,0.455), 8.47px 0 rgba(27,42,74,1.0); }
        85.0% { color: rgba(27,42,74,0.059); text-shadow: 4.23px 0 rgba(27,42,74,0.368), 8.47px 0 rgba(27,42,74,0.981); }
        87.5% { color: rgba(27,42,74,0.04); text-shadow: 4.23px 0 rgba(27,42,74,0.294), 8.47px 0 rgba(27,42,74,0.929); }
        90.0% { color: rgba(27,42,74,0.024); text-shadow: 4.23px 0 rgba(27,42,74,0.233), 8.47px 0 rgba(27,42,74,0.848); }
        92.5% { color: rgba(27,42,74,0.013); text-shadow: 4.23px 0 rgba(27,42,74,0.182), 8.47px 0 rgba(27,42,74,0.743); }
        95.0% { color: rgba(27,42,74,0.006); text-shadow: 4.23px 0 rgba(27,42,74,0.139), 8.47px 0 rgba(27,42,74,0.629); }
        97.5% { color: rgba(27,42,74,0.001); text-shadow: 4.23px 0 rgba(27,42,74,0.104), 8.47px 0 rgba(27,42,74,0.52); }
        100.0% { color: rgba(27,42,74,0.0); text-shadow: 4.23px 0 rgba(27,42,74,0.075), 8.47px 0 rgba(27,42,74,0.424); }
    }
</style>
<script>
(function () {
    'use strict';
    if (window._cvBusyReady) return;
    window._cvBusyReady = true;
    var root = document.documentElement;

    // ── 1) first paint: covered until this page's own loading overlay exists ──
    root.classList.add('cv-booting');
    function releaseBoot() { root.classList.remove('cv-booting'); }
    /* NEW (this adjustment): the loading animation no longer starts over midway. When the page's own loading page
       takes over from this cover, its spinner continues from the same angle, its dots continue in the same rhythm,
       and its pop-in is not replayed (it is already on screen). Only for this one hand-over, and only if the cover
       was actually painted; any later showing of the loading page (e.g. "Saving") animates exactly as before. */
    var cvBootStart = (window.performance && performance.now) ? performance.now() : Date.now();
    var cvCoverPainted = false;
    /* NEW (this adjustment): ONE loading page from the side-menu click / refresh until the new page is ready.
       The page being left saves the moment its loading page appeared (see "pagehide" below); this page picks it
       up and simply carries on from there — same ring position, same dots, no second pop-in, nothing drawn twice.
       Used once, only if recent (15 s); a first visit (nothing saved) behaves as before. */
    var CV_LOADER_KEY = 'cvLoaderEpoch', cvCarriedOver = false;
    try {
        var cvEpoch = parseInt(sessionStorage.getItem(CV_LOADER_KEY) || '', 10);
        sessionStorage.removeItem(CV_LOADER_KEY);
        var cvSince = cvEpoch ? Date.now() - cvEpoch : -1;
        if (cvSince >= 0 && cvSince < 15000) {
            cvCarriedOver = true; cvCoverPainted = true;
            cvBootStart = cvBootStart - cvSince;
            root.style.setProperty('--cv-ring-delay', (-((cvSince / 1000) % 1)).toFixed(3) + 's');
            root.style.setProperty('--cv-dots-delay', (-((cvSince / 1000) % 1.2)).toFixed(3) + 's');
        }
    } catch (e) {}
    function cvLoaderStartedAt() { return Date.now() - (((window.performance && performance.now) ? performance.now() : Date.now()) - cvBootStart); }
    if (window.requestAnimationFrame) requestAnimationFrame(function () { requestAnimationFrame(function () { cvCoverPainted = root.classList.contains('cv-booting'); }); });
    function continueCoverAnimation(ov) {
        try {
            if (!cvCoverPainted || !ov || ov.classList.contains('hidden')) return;
            var now = (window.performance && performance.now) ? performance.now() : Date.now();
            var elapsed = (now - cvBootStart) / 1000;
            var spinner = ov.querySelector('.global-loading-spinner');
            if (spinner) spinner.style.animationDelay = (-(elapsed % 1)).toFixed(3) + 's';   // UPDATED (this adjustment): the ring's 1 s turn
            var dots = ov.querySelectorAll('.global-loading-dots span');
            for (var i = 0; i < dots.length; i++) dots[i].style.animationDelay = (-((elapsed - i * 0.2) % 1.2 + 1.2) % 1.2).toFixed(3) + 's';
            var box = ov.querySelector('.global-loading-box');
            if (box) {
                box.style.animation = 'none';   // no second pop-in
                var restore = new MutationObserver(function () {   // later showings get their pop-in back, as before
                    if (ov.classList.contains('hidden')) {
                        restore.disconnect();
                        setTimeout(function () { box.style.animation = ''; if (spinner) spinner.style.animationDelay = ''; for (var j = 0; j < dots.length; j++) dots[j].style.animationDelay = ''; }, 400);
                    }
                });
                restore.observe(ov, { attributes: true, attributeFilter: ['class'] });
            }
        } catch (e) { /* never affects the page */ }
    }
    // UPDATED (this adjustment): the cover gives way the instant the page's loading page is in the page (before the
    // next frame is drawn), so the two are never drawn at the same time
    var cvHandedOver = false;
    function cvHandOver(ovEl) {
        if (cvHandedOver) return;
        cvHandedOver = true;
        continueCoverAnimation(ovEl);
        releaseBoot();
    }
    if (window.MutationObserver) {
        var cvOvWatch = new MutationObserver(function () {
            var o = document.getElementById('globalLoadingOverlay');
            if (o) { cvOvWatch.disconnect(); cvHandOver(o); }
        });
        cvOvWatch.observe(root, { childList: true, subtree: true });
        document.addEventListener('DOMContentLoaded', function () { cvOvWatch.disconnect(); });
    }
    (function waitForOverlay() {
        var ovEl = document.getElementById('globalLoadingOverlay');
        if (ovEl) { cvHandOver(ovEl); return; }
        if (document.readyState !== 'loading') { releaseBoot(); return; }   // page without an overlay: never keep it covered
        setTimeout(waitForOverlay, 16);
    })();
    document.addEventListener('DOMContentLoaded', function () { setTimeout(releaseBoot, 0); });
    window.addEventListener('pageshow', function (e) { if (e.persisted) releaseBoot(); });

    /* NEW (this adjustment): remember when this page's loading page appeared, and hand that moment to the next
       page when this one is left (side-menu link, refresh, redirect) while it is showing — so the next page
       carries on the same loading page instead of starting a second one. Downloads never leave the page, so
       they never hand anything over. */
    var cvShownSince = null;
    function cvWatchOverlay() {
        var ov = document.getElementById('globalLoadingOverlay');
        if (!ov) return;
        var mark = function () {
            var shown = !ov.classList.contains('hidden');
            if (shown && cvShownSince === null) cvShownSince = Date.now();
            if (!shown) cvShownSince = null;
        };
        if (!ov.classList.contains('hidden')) cvShownSince = cvLoaderStartedAt();   // the page's first loading page
        new MutationObserver(mark).observe(ov, { attributes: true, attributeFilter: ['class'] });
    }
    if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', cvWatchOverlay); else cvWatchOverlay();
    window.addEventListener('pagehide', function () {
        try {
            var ov = document.getElementById('globalLoadingOverlay');
            if (ov && !ov.classList.contains('hidden') && !ov.classList.contains('success-state')) {
                sessionStorage.setItem(CV_LOADER_KEY, String(cvShownSince !== null ? cvShownSince : Date.now()));
            }
        } catch (e) {}
    });

    // ── shared: this page's loading overlay ──
    var LABEL = 'Processing', MIN_MS = 350, SAFETY_MS = 30000;
    var pending = 0, shownAt = 0, weShowed = false, hideTimer = null, safetyTimer = null;
    function overlay() { return document.getElementById('globalLoadingOverlay'); }
    function label() { return document.getElementById('globalLoadingLabel'); }
    function showBusy() {
        var ov = overlay(); if (!ov) return;
        clearTimeout(hideTimer);
        if (!weShowed) {
            if (!ov.classList.contains('hidden')) return;          // the page is already showing it — leave it to the page
            weShowed = true; shownAt = Date.now();
            var l = label(); if (l) l.textContent = LABEL;
            ov.classList.remove('hidden');
        }
        clearTimeout(safetyTimer);
        safetyTimer = setTimeout(function () { pending = 0; hideBusy(true); }, SAFETY_MS);
    }
    function hideBusy(force) {
        if (!weShowed) return;
        if (!force && pending > 0) return;
        var wait = Math.max(0, MIN_MS - (Date.now() - shownAt));
        clearTimeout(hideTimer);
        hideTimer = setTimeout(function () {
            if (pending > 0 && !force) return;
            weShowed = false; clearTimeout(safetyTimer);
            var ov = overlay(), l = label();
            if (l && l.textContent !== LABEL) return;                // the page took the loading page over meanwhile — it hides it itself
            if (ov) ov.classList.add('hidden');
            if (l) l.textContent = 'Loading';
        }, wait);
    }

    // ── 2) which requests count as "doing something" ──
    var lastGesture = 0;
    ['pointerdown', 'click', 'submit', 'change'].forEach(function (t) { document.addEventListener(t, function () { lastGesture = Date.now(); }, true); });
    document.addEventListener('keydown', function (e) { if (e.key === 'Enter' || e.key === ' ') lastGesture = Date.now(); }, true);
    var QUIET = /^(ajax_fetch|ajax_poll|ajax_get|ajax_check|check_|detect_|amd_search|amd_course|amd_signatories|load_messages|poll|ajax_endorsement_preview|ajax_endorsement_defaults|ajax_moa_mark_notification_viewed|ajax_flush_emails|ajax_moa_flush_emails|ajax_commit_denied|ajax_confirm_send|ajax_moa_commit|cv_sidebar|sidebar_counts|backup_list)/;
    function keysOf(body) {
        var keys = [];
        try {
            if (!body) return keys;
            if (typeof FormData !== 'undefined' && body instanceof FormData) { body.forEach(function (v, k) { keys.push(k); }); return keys; }
            if (typeof URLSearchParams !== 'undefined' && body instanceof URLSearchParams) { body.forEach(function (v, k) { keys.push(k); }); return keys; }
            if (typeof body === 'string' && body.indexOf('=') !== -1 && body.charAt(0) !== '{') { body.split('&').forEach(function (p) { keys.push(decodeURIComponent(p.split('=')[0] || '')); }); return keys; }
        } catch (e) {}
        return keys;
    }
    function isAction(method, body) {
        if (String(method || 'GET').toUpperCase() === 'GET' || String(method).toUpperCase() === 'HEAD') return false;
        if (Date.now() - lastGesture > 1500) return false;                          // not started by the admin → background
        var keys = keysOf(body);
        if (keys.some(function (k) { return QUIET.test(k); })) return false;
        if (keys.indexOf('mode') !== -1 && keys.indexOf('company_id') !== -1 && keys.indexOf('amd_apply_students') === -1) return false;   // dashboard chat
        return true;
    }
    function track(promiseLike) {
        pending++; showBusy();
        var done = function () { pending = Math.max(0, pending - 1); hideBusy(false); };
        return promiseLike.then(function (r) { done(); return r; }, function (e) { done(); throw e; });
    }

    // fetch
    if (window.fetch) {
        var origFetch = window.fetch;
        window.fetch = function (input, init) {
            var p = origFetch.apply(this, arguments);
            try {
                var method = (init && init.method) || (input && typeof input === 'object' && input.method) || 'GET';
                if (isAction(method, init && init.body)) return track(p);
            } catch (e) {}
            return p;
        };
    }
    // XMLHttpRequest
    if (window.XMLHttpRequest) {
        var XP = window.XMLHttpRequest.prototype, origOpen = XP.open, origSend = XP.send;
        XP.open = function (method) { this._cvMethod = method; return origOpen.apply(this, arguments); };
        XP.send = function (body) {
            try {
                if (isAction(this._cvMethod, body)) {
                    var xhr = this, finished = false;
                    pending++; showBusy();
                    var end = function () { if (finished) return; finished = true; pending = Math.max(0, pending - 1); hideBusy(false); };
                    xhr.addEventListener('loadend', end);
                }
            } catch (e) {}
            return origSend.apply(this, arguments);
        };
    }

    // ── 3) leaving by script (reload / redirect after an action) ──
    var lastFileClick = 0;
    var FILE_RE = /[?&][^=&]*(export|download|print|stream|pdf|preview|blob|file|csv)[^=&]*=|\.(pdf|xlsx?|csv|docx?|zip)(\?|$)/i;
    document.addEventListener('click', function (e) {
        var a = e.target && e.target.closest ? e.target.closest('a[href], button, input[type="submit"]') : null;
        if (!a) return;
        var href = a.getAttribute && (a.getAttribute('href') || a.getAttribute('formaction') || '');
        var form = a.form || (a.closest && a.closest('form'));
        var txt = (a.textContent || a.value || '').toLowerCase();
        if (a.hasAttribute && a.hasAttribute('download') || (href && FILE_RE.test(href)) || /export|download|print/.test(txt) ||
            (form && form.querySelector && form.querySelector('[name*="export"],[name*="download"]'))) lastFileClick = Date.now();
    }, true);
    window.addEventListener('beforeunload', function () {
        if (Date.now() - lastFileClick < 2000) return;                             // most likely a file download
        var ov = overlay(); if (!ov || !ov.classList.contains('hidden')) return;
        var l = label(); if (l) l.textContent = 'Loading';
        ov.classList.remove('hidden');
        setTimeout(function () { if (!document.hidden) { ov.classList.add('hidden'); } }, 8000);   // still here → it was a download
    });
})();
</script>
<!-- NEW (this adjustment): button tooltips can now hold a short description, so they may wrap onto a second line -->
<style>
    #cvBtnTip { white-space: normal; max-width: 300px; text-align: center; }
</style>
</head>
<body>

<!-- ══════════════════════════════════════════════════════════
     NEW: Global loading/processing popup (same as admin_company_list.php).
     Visible by default so it covers the page while assets are still
     loading, then hidden by JS once the window finishes loading. Also
     reused (shown/hidden) around the AJAX actions further down so the
     admin always sees a clear "processing" indicator that disappears
     automatically the moment the action completes.
     ══════════════════════════════════════════════════════════ -->
<div id="globalLoadingOverlay">
    <div class="global-loading-box">
        <div class="global-loading-spinner"></div>
        <div class="global-loading-text">
            <span id="globalLoadingLabel">Loading</span>
            <span class="global-loading-dots"><span>.</span><span>.</span><span>.</span></span>
        </div>
    </div>
</div>
<!-- ══════════════════════════════════════════════════════════════════════
     NEW (this adjustment) — LOADING PAGE: ALWAYS SHOWS WHEN A PAGE LOADS
     ------------------------------------------------------------------------
     The loading page sometimes did not appear at all:
       • on a fast load the page hid it the instant it finished loading —
         before the browser had even painted it, so it was never seen;
       • leaving this page did not show it at all, so while the next page was
         still being prepared there was nothing on screen to say it was loading.
     This guard (placed right after the loading page, so it runs as early as
     possible) makes it dependable without replacing any existing code:
       1. It is visible from the start of every page load, and stays up for
          at least a short moment (450 ms) so it is always actually seen. If
          the page's own code hides it sooner, the hide is simply held back
          until that moment has passed (unless the page shows it again).
       2. Leaving the page with a same-tab link or a normal form submit shows it
          straight away. File downloads, exports, previews, new-tab links and
          AJAX forms are skipped, with a 10 s safety in case a page never changes.
       3. Back / Forward (page restored from the browser cache) clears anything
          left over from leaving, so it is never stuck on screen.
     Uses the page's existing #globalLoadingOverlay / #globalLoadingLabel,
     so the design is unchanged. Everything it does is limited to the first
     moments of the page load and to leaving the page.
     ══════════════════════════════════════════════════════════════════════ -->
<script>
(function () {
    'use strict';
    var ov = document.getElementById('globalLoadingOverlay');
    if (!ov || window._cvPageLoaderGuard) return;
    window._cvPageLoaderGuard = true;

    var MIN_VISIBLE_MS = 450;         // shortest time the loading page is shown on a page load
    var OWN_LOAD_HIDE  = true;    // does this page already hide the loading page itself once loaded?
    var NAV_ON_CLICK   = true;    // show it when leaving the page via a link / form (if the page doesn't already)
    var NAV_STUCK_MS   = 10000;       // safety: if the page is still here after this, put things back
    var label = document.getElementById('globalLoadingLabel');
    var start = Date.now();
    var initialPhase = true, wantHide = false;

    function setHidden(h) { if (h) ov.classList.add('hidden'); else ov.classList.remove('hidden'); if (mo) mo.takeRecords(); }

    // 1) visible from the very start of the page load
    var mo = null;
    if (ov.classList.contains('hidden')) ov.classList.remove('hidden');
    if (window.MutationObserver) {
        mo = new MutationObserver(function () {
            if (!initialPhase) return;
            if (ov.classList.contains('hidden')) {
                if (Date.now() - start < MIN_VISIBLE_MS) { wantHide = true; setHidden(false); }
            } else {
                wantHide = false;   // the page showed it again (e.g. an action) — respect that
            }
        });
        mo.observe(ov, { attributes: true, attributeFilter: ['class'] });
    }
    function endInitialPhase() {
        if (!initialPhase) return;
        initialPhase = false;
        if (mo) mo.takeRecords();
        if (wantHide || !OWN_LOAD_HIDE) setHidden(true);
        if (mo) { mo.disconnect(); mo = null; }
    }
    function afterLoad() { setTimeout(endInitialPhase, Math.max(0, MIN_VISIBLE_MS - (Date.now() - start))); }
    if (document.readyState === 'complete') afterLoad(); else window.addEventListener('load', afterLoad);
    if (!OWN_LOAD_HIDE) setTimeout(endInitialPhase, 15000);   // safety if 'load' is held up by a slow asset

    // 2) leaving the page: show it the moment a same-tab link is clicked or a form is submitted
    var navShown = false, navWasHidden = false, navPrevLabel = null, navTimer = null;
    function navShow() {
        if (navShown) return;
        navShown = true;
        navWasHidden = ov.classList.contains('hidden');
        if (navWasHidden) {
            if (label) { navPrevLabel = label.textContent; label.textContent = 'Loading'; }
            setHidden(false);
        }
        clearTimeout(navTimer);
        navTimer = setTimeout(navReset, NAV_STUCK_MS);
    }
    function navReset() {
        clearTimeout(navTimer);
        if (!navShown) return;
        navShown = false;
        if (navWasHidden) {
            setHidden(true);
            if (label && navPrevLabel !== null) label.textContent = navPrevLabel;
        }
    }
    // file / export / preview URLs download or open a file instead of leaving the page — skip them
    var FILE_RE  = /\.(pdf|xlsx?|csv|docx?|pptx?|zip|png|jpe?g|gif|webp|txt)$/i;
    var PARAM_RE = /[?&][^=&]*(export|download|print|stream|pdf|preview|blob|file)[^=&]*=/i;
    function isPageUrl(u) {
        if (u.origin !== window.location.origin || !/^https?:$/.test(u.protocol)) return false;
        if (FILE_RE.test(u.pathname) || PARAM_RE.test(u.search)) return false;
        return true;
    }
    if (NAV_ON_CLICK) {
        document.addEventListener('click', function (e) {
            if (e.button !== 0 || e.metaKey || e.ctrlKey || e.shiftKey || e.altKey) return;
            var a = e.target && e.target.closest ? e.target.closest('a[href]') : null;
            if (!a || a.hasAttribute('download')) return;
            var raw = (a.getAttribute('href') || '').trim();
            if (!raw || raw.charAt(0) === '#' || /^(javascript|mailto|tel|blob|data):/i.test(raw)) return;
            var t = (a.getAttribute('target') || '').toLowerCase();
            if (t && t !== '_self') return;
            var u; try { u = new URL(a.href, window.location.href); } catch (x) { return; }
            if (!isPageUrl(u)) return;
            if (u.pathname === window.location.pathname && u.search === window.location.search && u.hash) return;   // same-page anchor
            // decided after every other click handler has run, so links the page handles itself are left alone
            setTimeout(function () { if (!e.defaultPrevented) navShow(); }, 0);
        });
        document.addEventListener('submit', function (e) {
            var f = e.target;
            if (!f || f.tagName !== 'FORM') return;
            var t = (f.getAttribute('target') || '').toLowerCase();
            if (t && t !== '_self') return;
            var u; try { u = new URL(f.getAttribute('action') || window.location.href, window.location.href); } catch (x) { return; }
            if (!isPageUrl(u)) return;
            setTimeout(function () { if (!e.defaultPrevented) navShow(); }, 0);   // AJAX forms prevent the submit — left alone
        });
    }

    // 3) Back / Forward cache: the page did not reload, so clear what leaving it left behind
    window.addEventListener('pageshow', function (e) {
        if (!e.persisted) return;
        navReset();
        if (initialPhase) endInitialPhase();
    });
})();
</script>

<!-- ══════════════════════════════════════════════════════════════
     ENHANCED NOTIFICATION POPUP (replaces old errorModal / verifiedModal)
══════════════════════════════════════════════════════════════ -->
<div id="notifOverlay">
    <div id="notifBox">
        <!-- UPDATED: the coloured top line was removed from this notification popup. -->
        <div id="notifBody">
            <!-- UPDATED: the emoji/icon ring was removed from this notification popup. -->
            <p id="notifTitle"></p>
            <p id="notifMsg"></p>
            <!-- NEW: optional account-details block (used by the deletion notification) -->
            <div id="notifDetails" class="acct-details"></div>
            <button id="notifBtn" onclick="closeNotif()">OK</button>
        </div>
    </div>
</div>

<!-- ── DELETE CONFIRM DIALOG ── -->
<div id="deleteConfirmOverlay">
    <div id="deleteConfirmBox">
        <!-- UPDATED: the red top bar, the trash icon and the "Delete Account"
             heading were removed from this confirmation dialog. -->
        <div class="dc-body">
            <p class="dc-msg">Are you sure you want to permanently delete this account? This action cannot be undone.</p>
            <!-- NEW: details of the account that is about to be deleted -->
            <div id="dcDetails" class="acct-details"></div>
            <div class="dc-actions">
                <button class="dc-cancel" onclick="cancelDelete()">Cancel</button>
                <button class="dc-confirm" id="dcConfirmBtn" onclick="proceedDelete()">Yes, Delete</button>
            </div>
        </div>
    </div>
</div>

<!-- ══════════════════════════════════════════════════════════════
     NEW: STATUS TOGGLE (ACTIVATE / DEACTIVATE) CONFIRMATION DIALOG
     Shown before either activating or deactivating any account
     (Admin, Faculty, Company, or Student), replacing the previous
     plain browser confirm() popup. "Continue" proceeds with the
     toggle; "Cancel" closes the dialog and does nothing.
══════════════════════════════════════════════════════════════ -->
<div id="statusConfirmOverlay">
    <div id="statusConfirmBox">
        <div class="sc-bar" id="scBar"></div>
        <div class="sc-body">
            <!-- UPDATED: the emoji/icon was removed from this confirmation popup. -->
            <p class="sc-title" id="scTitle"></p>
            <p class="sc-msg" id="scMsg"></p>
            <div class="sc-actions">
                <button class="sc-cancel" onclick="cancelStatusToggle()">Cancel</button>
                <button class="sc-confirm" id="scConfirmBtn" onclick="proceedStatusToggle()">Continue</button>
            </div>
        </div>
    </div>
</div>

<!-- ══════════════════════════════════════════════════════════════
     FIX: the speed-dial FAB menu markup was missing entirely — the
     CSS for #fabMenu / .fab-main-btn / .fab-options / .fab-option
     already existed above, but no matching HTML was ever added to
     the page, which is why no FAB / FAB options were showing up.
     This restores the bottom-right expandable FAB with its three
     options: My Profile, Email Recovery Requests, Create Admin
     Account. The old standalone top-right recovery button and the
     old standalone "+" create button have been folded into it (see
     toggleFabMenu()/openProfileModal()/openRecoveryDrawer()/openModal()
     in the script below). The #recoveryFabBadge id is preserved so
     the existing updateFabBadge() logic keeps working unchanged.
══════════════════════════════════════════════════════════════ -->
<div id="fabMenu">
    <div class="fab-options" id="fabOptions">
        <div class="fab-option" onclick="openProfileModal()">
            <span class="fab-option-icon"><i class="fas fa-id-badge"></i></span>
            <span class="fab-option-label">My Profile</span>
        </div>
        <div class="fab-option" onclick="openRecoveryDrawer()">
            <span class="fab-option-icon">
                <i class="fas fa-envelope"></i>
                <span id="recoveryFabBadge" class="fab-option-badge"><?= $pending_count > 0 ? $pending_count : '' ?></span>
            </span>
            <span class="fab-option-label">Email Recovery Requests</span>
        </div>
        <div class="fab-option" onclick="openModal()">
            <span class="fab-option-icon"><i class="fas fa-user-plus"></i></span>
            <span class="fab-option-label">Create Admin Account</span>
        </div>
    </div>
    <button type="button" class="fab-main-btn" id="fabMainBtn" onclick="toggleFabMenu()" title="Quick Actions">
        <i class="fas fa-plus"></i>
        <span id="fabMainBadge" class="fab-main-badge"><?= $pending_count > 0 ? $pending_count : '' ?></span>
    </button>
</div>
<?php if ($pending_count > 0): ?>
<script>
document.getElementById('recoveryFabBadge').style.display = 'flex';
document.getElementById('fabMainBadge').style.display = 'flex';
</script>
<?php endif; ?>

<!-- ══════════════════════════════════════════════════════════════
     NEW: MY PROFILE MODAL — shows the logged-in admin's own account
     info ($adminProfile, populated in PHP above). Reached from the
     FAB menu's "My Profile" option.
══════════════════════════════════════════════════════════════ -->
<div id="profileModal" class="modal">
    <div id="profileModalBox">
        <div class="profile-avatar"><?= htmlspecialchars(strtoupper(substr($adminFullName, 0, 1))) ?></div>
        <h2 style="text-align:center; color:var(--neust-maroon); margin:0 0 4px; font-weight:700;"><?= htmlspecialchars($adminFullName ?? '') ?></h2>
        <p style="text-align:center; color:#a18a8a; font-size:0.78rem; text-transform:uppercase; letter-spacing:0.6px; font-weight:700; margin:0 0 20px;">Administrator</p>

        <div class="profile-row">
            <span class="profile-label">Email</span>
            <span class="profile-value"><?= htmlspecialchars($adminProfile['email'] !== '' ? $adminProfile['email'] : '—') ?></span>
        </div>
        <div class="profile-row">
            <span class="profile-label">School</span>
            <span class="profile-value"><?= htmlspecialchars($adminProfile['school'] !== '' ? $adminProfile['school'] : '—') ?></span>
        </div>
        <div class="profile-row">
            <span class="profile-label">School Address</span>
            <span class="profile-value"><?= htmlspecialchars($adminProfile['school_address'] !== '' ? $adminProfile['school_address'] : '—') ?></span>
        </div>
        <div class="profile-row">
            <span class="profile-label">Subject</span>
            <span class="profile-value"><?= htmlspecialchars($adminProfile['subject'] !== '' ? $adminProfile['subject'] : '—') ?></span>
        </div>
        <div class="profile-row">
            <span class="profile-label">Required Hours</span>
            <span class="profile-value"><?= htmlspecialchars($adminProfile['required_hours'] !== '' ? $adminProfile['required_hours'] : '—') ?></span>
        </div>
        <div class="profile-row">
            <span class="profile-label">Contact No.</span>
            <span class="profile-value"><?= htmlspecialchars($adminProfile['contact_no'] !== '' ? $adminProfile['contact_no'] : '—') ?></span>
        </div>

        <button type="button" onclick="closeProfileModal()" style="width:100%;background:var(--neust-maroon);color:var(--neust-gold);padding:16px;border:none;border-radius:15px;cursor:pointer;font-weight:700;font-size:0.95rem;margin-top:24px;">Close</button>
    </div>
</div>

<!-- ── RECOVERY DRAWER ── -->
<div id="recoveryDrawerOverlay" onclick="if(event.target===this)closeRecoveryDrawer()">
    <div id="recoveryDrawer">
        <div id="recoveryDrawerHeader">
            <h3>
                <i class="fas fa-envelope"></i>
                Email Recovery Requests
                <!-- UPDATED (this adjustment): always rendered (hidden at 0) so the live inbox can keep its count current -->
                <span id="recoveryHeaderPending" style="background:#dc2626;color:white;font-size:0.7rem;padding:2px 9px;border-radius:20px;font-weight:700;<?= $pending_count > 0 ? '' : 'display:none;' ?>">
                    <span id="recoveryHeaderPendingNum"><?= $pending_count ?></span> Pending
                </span>
            </h3>
            <button id="recoveryDrawerClose" onclick="closeRecoveryDrawer()">&#x2715;</button>
        </div>
        <!-- NEW (Recovery request history): Pending / History tabs. Pending keeps
             the Accept / Reject flow; Accepted and Rejected requests move to
             History, which can be cleared with the "Clear History" button. -->
        <div class="req-tabs" role="tablist">
            <button type="button" class="req-tab active" id="reqTabPending" role="tab" onclick="switchRecoveryTab('pending')">
                <i class="fas fa-hourglass-half"></i> Pending
                <span class="req-tab-count" id="reqPendingCount"><?= count($req_pending_rows) ?></span>
            </button>
            <button type="button" class="req-tab" id="reqTabHistory" role="tab" onclick="switchRecoveryTab('history')">
                <i class="fas fa-clock-rotate-left"></i> History
                <span class="req-tab-count" id="reqHistoryCount"><?= count($req_history_rows) ?></span>
            </button>
        </div>
        <div id="recoveryDrawerBody">
            <!-- PENDING -->
            <div class="req-list" id="reqPendingList">
                <div class="req-empty" id="reqPendingEmpty"<?= empty($req_pending_rows) ? '' : ' style="display:none"' ?>>
                    <i class="fas fa-inbox" style="font-size:36px;display:block;margin-bottom:12px;color:#cbd5e0;"></i>
                    No pending email recovery requests.
                </div>
                <div id="reqPendingItems">
                    <?php foreach ($req_pending_rows as $req) mon_render_req_card($req); ?>
                </div>
            </div>
            <!-- HISTORY -->
            <div class="req-list" id="reqHistoryList" style="display:none">
                <div class="req-history-bar">
                    <span class="req-history-note">Accepted and rejected requests</span>
                    <button type="button" class="req-clear-btn" id="clearHistoryBtn" onclick="openClearHistoryConfirm()"<?= empty($req_history_rows) ? ' disabled' : '' ?>>
                        <i class="fas fa-trash-can"></i> Clear History
                    </button>
                </div>
                <div class="req-empty" id="reqHistoryEmpty"<?= empty($req_history_rows) ? '' : ' style="display:none"' ?>>
                    <i class="fas fa-clock-rotate-left" style="font-size:36px;display:block;margin-bottom:12px;color:#cbd5e0;"></i>
                    No request history.
                </div>
                <div id="reqHistoryItems">
                    <?php foreach ($req_history_rows as $req) mon_render_req_card($req); ?>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- NEW (Recovery request history): Clear History confirmation — same square
     navy dialog look as the page's logout confirmation (.cv-logout-*). -->
<div class="cv-logout-overlay" id="clearHistoryConfirmOverlay" onclick="if(event.target===this)closeClearHistoryConfirm()">
    <div class="cv-logout-box" role="dialog" aria-modal="true" aria-labelledby="clearHistoryTitle">
        <h3 id="clearHistoryTitle"><i class="fas fa-trash-can"></i> Clear Request History</h3>
        <p>Remove all accepted and rejected email recovery requests from the History list? Pending requests are not affected.</p>
        <div class="cv-logout-actions">
            <button type="button" class="cv-logout-btn ghost" onclick="closeClearHistoryConfirm()">Cancel</button>
            <button type="button" class="cv-logout-btn" id="clearHistoryConfirmBtn" onclick="clearRecoveryHistory()"><i class="fas fa-trash-can"></i> Clear History</button>
        </div>
    </div>
</div>

<!-- ── NEW (this adjustment): ACCEPT CONFIRMATION POPUP — replaces the browser's
     confirm() box; same design as the Reject Recovery Request popup below. ── -->
<div id="acceptConfirmOverlay" onclick="if(event.target===this)closeAcceptModal()">
    <div id="acceptConfirmBox" role="dialog" aria-modal="true" aria-labelledby="acceptConfirmTitle">
        <h4 id="acceptConfirmTitle"><i class="fas fa-check-circle"></i> Accept Recovery Request</h4>
        <p>The old email will be replaced with the new one and a new password will be sent to the new email address.</p>
        <div class="ac-summary">
            <div class="ac-row"><span>Name</span><strong id="acceptSumName">&mdash;</strong></div>
            <div class="ac-row"><span>Old Email</span><strong id="acceptSumOld">&mdash;</strong></div>
            <div class="ac-row"><span>New Email</span><strong id="acceptSumNew">&mdash;</strong></div>
        </div>
        <div class="rr-actions">
            <button class="rr-cancel" onclick="closeAcceptModal()">Cancel</button>
            <button class="ac-confirm" id="acceptConfirmBtn" onclick="confirmAccept()">
                <i class="fas fa-check"></i> Confirm Accept
            </button>
        </div>
    </div>
</div>

<!-- ── REJECT REASON MODAL ── -->
<div id="rejectReasonOverlay">
    <div id="rejectReasonBox">
        <h4><i class="fas fa-times-circle"></i> Reject Recovery Request</h4>
        <p>Optionally provide a reason. It will be included in the rejection email sent to the user.</p>
        <textarea id="rejectReasonInput" placeholder="Reason for rejection (optional)..."></textarea>
        <div class="rr-actions">
            <button class="rr-cancel" onclick="closeRejectModal()">Cancel</button>
            <button class="rr-confirm" id="rejectConfirmBtn" onclick="confirmReject()">
                <i class="fas fa-times"></i> Confirm Reject
            </button>
        </div>
    </div>
</div>

<!-- SIDEBAR -->
<div id="sidebar" class="sidebar">
    <!-- ══════════════════════════════════════════════════════════════
         NEW (this adjustment): SIDEBAR STATE SYNC (same as login.php /
         company_login.php / admin_login.php)
         ------------------------------------------------------------
         Keeps the sidebar collapsed / expanded exactly as the admin left
         it on the previous page, using the same shared localStorage key
         "neustSidebarCollapsed" the login pages use.
         • Restore — runs right here, as the sidebar is being built and
           before the page is first painted, so the page opens directly in
           the saved state with no flash of the other state. It adds this
           page's own .collapsed class, so this page's own collapsed styles
           (and its toggle button) take it from there unchanged.
         • Save — a watcher on the sidebar's class list stores the new
           state whenever the existing toggle button changes it, so none of
           the existing toggle code had to change.
         • Phones (768px and narrower) are left alone: there some pages use
           .collapsed to OPEN the slide-in menu, so a saved desktop
           preference is never applied or overwritten on a small screen.
    ══════════════════════════════════════════════════════════════ -->
    <script>
    (function () {
        var sb = document.getElementById('sidebar');
        if (!sb) return;
        var KEY = 'neustSidebarCollapsed';
        function isDesktop() { return !(window.matchMedia && window.matchMedia('(max-width: 768px)').matches); }
        try {
            if (isDesktop() && localStorage.getItem(KEY) === '1') sb.classList.add('collapsed');
        } catch (e) { /* localStorage unavailable — falls back to the default expanded state */ }
        if (window.MutationObserver) {
            new MutationObserver(function () {
                if (!isDesktop()) return;
                try { localStorage.setItem(KEY, sb.classList.contains('collapsed') ? '1' : '0'); } catch (e) { /* state just won't persist */ }
            }).observe(sb, { attributes: true, attributeFilter: ['class'] });
        }
    })();
    </script>
    <div class="sidebar-header">
        <div class="sidebar-header-titles">
            <h2 id="sidebarTitle"><?= htmlspecialchars($adminFullName ?? '') ?></h2>
            <span class="sidebar-role-label">Administrator</span>
        </div>
        <button id="toggleBtn" class="toggle-btn"><i class="fas fa-bars"></i></button>
    </div>
    <div class="sidebar-links">
        <a href="admin_student_list.php"><i class="fas fa-users"></i><span class="link-text">Student List</span></a>
        <a href="admin_company_list.php"><i class="fas fa-building"></i><span class="link-text">Company List</span></a>
        <a href="course_offering.php"><i class="fas fa-book"></i><span class="link-text">Course Offering</span></a>
        <a href="administrator.php" style="position:relative;">
            <i class="fas fa-user-check"></i>
            <span class="link-text">Student Requirements</span>
            <?php if ($app_request_count > 0): ?>
                <span class="sidebar-badge-app" id="sidebarAppBadge"><?= $app_request_count ?></span>
            <?php else: ?>
                <span class="sidebar-badge-app" id="sidebarAppBadge" style="display:none"><?= $app_request_count ?></span>
            <?php endif; ?>
        </a>
        <!-- ── NEW: pending-MOA badge added to the Company Requirements link
             so it's visible from this page too, not just company_validation.php
             or admin_student_list.php. ── -->
        <a href="company_validation.php" style="position:relative;">
            <i class="fas fa-building"></i><span class="link-text">Company Requirements</span>
            <?php if ($moa_pending_count > 0): ?>
                <span class="sidebar-badge-moa" id="sidebarMoaBadge"><?= $moa_pending_count ?></span>
            <?php else: ?>
                <span class="sidebar-badge-moa" id="sidebarMoaBadge" style="display:none"><?= $moa_pending_count ?></span>
            <?php endif; ?>
        </a>
        <a href="monitoring.php" class="active" style="position:relative;"><i class="fas fa-users-cog"></i><span class="link-text">Manage Accounts</span><!-- NEW (this adjustment): Email Recovery Requests indicator — same badge look as the application-request badge --><span class="sidebar-badge-app sidebar-badge-recovery" id="sidebarRecoveryBadge"<?= $recovery_pending_count > 0 ? '' : ' style="display:none"' ?>><?= (int)$recovery_pending_count ?></span></a>
        <a href="admin_monitoring_dashboard.php">
            <i class="fas fa-chart-line"></i>
            <span class="link-text">Monitoring Dashboard</span>
        </a>
        <a href="admin_final_grades.php"><i class="fas fa-graduation-cap"></i><span class="link-text">Final Grades</span></a>
        <a href="system_setting.php" ><i class="fas fa-gear"></i><span class="link-text">System Setting</span></a>
    </div>
    <div class="logout-link">
        <a href="admin_login.php?logout=1"><i class="fas fa-sign-out-alt"></i><span class="link-text">Logout</span></a>
    </div>
</div>

<!-- MAIN CONTENT -->
<div class="main-content">
    <!-- NEW (dashboard design): same top navbar as admin_monitoring_dashboard.php -->
    <nav class="navbar">
        <div class="logo-section">
            <img src="logo.webp" class="university-logo" alt="NEUST Logo">
            <div>
                <div style="font-weight:bold; font-size:16px;">NEUST Atate Campus</div>
                <div style="font-size:11px; color:var(--neust-gold);">Web-Based Smart OJT Monitoring and Supervision Analytics System</div>
            </div>
        </div>
    </nav>

    <div class="container">
    <div class="history-wrapper">
        <!-- NEW (this adjustment): Live Activity Log tools — search, action filter, Clear Log -->
        <?php
            $alogLastId = null;
            try { $alogMax = $conn->query("SELECT MAX(id) AS m FROM activity_logs"); if ($alogMax) $alogLastId = (int)($alogMax->fetch_assoc()['m'] ?? 0); }
            catch (\Throwable $e) { $alogLastId = null; }
        ?>
        <div class="alog-toolbar" id="alogToolbar" data-last-id="<?= $alogLastId === null ? '' : (int)$alogLastId ?>">
            <div class="alog-search">
                <i class="fas fa-search"></i>
                <input type="text" id="alogSearch" placeholder="Search activity (name, action, details...)" aria-label="Search the activity log" autocomplete="off">
            </div>
            <select id="alogAction" aria-label="Filter by action">
                <option value="">All Actions</option>
            </select>
            <button type="button" class="alog-clear-btn" id="alogClearBtn" data-cv-tip="Clear the activity log"><i class="fas fa-trash-alt"></i> Clear Log</button>
        </div>
        <p class="alog-no-match" id="alogNoMatch" style="display:none;">No activity matches your search.</p>
        <div id="historyContainer">
            <?php if ($history && $history->num_rows > 0): ?>
                <?php while ($row = $history->fetch_assoc()): ?>
                    <?php $badgeClass = "badge-" . strtoupper(str_replace(' ', '-', $row['action_type'])); ?>
                    <div class="log-entry">
                        <div class="log-entry-inner">
                            <div class="log-entry-left">
                                <span class="badge <?= $badgeClass ?>"><?= htmlspecialchars($row['action_type'] ?? '') ?></span>
                                <strong><?= htmlspecialchars($row['account_type'] ?? '') ?>: <?= htmlspecialchars($row['account_name'] ?? '') ?></strong>
                                <div>Performed by: <?= htmlspecialchars($row['performed_by'] ?? 'System') ?></div>
                                <div>Details: <?= htmlspecialchars($row['details'] ?? '') ?></div>
                            </div>
                            <div class="log-entry-right"><?= date('M d, Y h:i A', strtotime($row['created_at'] ?? '')) ?></div>
                        </div>
                    </div>
                <?php endwhile; ?>
            <?php else: ?>
                <p style="text-align:center; color:#666; padding:20px;">No activity yet.</p>
            <?php endif; ?>
        </div>
    </div>

    <div id="tablesContainer">
        <?php
            renderTable($admins,    'admin');
            renderTable($faculty,   'faculty');
            renderTable($companies, 'company');
            renderTable($students,  'student');
        ?>
    </div>
    </div><!-- /.container (dashboard design) -->
</div>

<!-- MAIN MODAL — Company/Faculty account creation removed entirely.
     The modal now only ever runs the OTP-verified Admin creation flow. -->
<div id="modal" class="modal">
    <div style="background:white; padding:40px; border-radius:25px; width:500px; box-shadow:0 20px 60px rgba(0,0,0,0.2); max-height:90vh; overflow-y:auto;">
        <h2 style="text-align:center; color:var(--neust-maroon); margin-top:0; font-weight:700;">Create Admin Account</h2>

        <!-- ── Company account creation removed entirely; Create Account
             now only ever creates Admin accounts, so the role selector
             has been removed and the modal opens straight into the
             Admin creation flow. ── -->
        <div id="adminFlow" style="display:block;">
            <div class="phase-indicator">
                <div class="phase-dot current" id="dot1"></div>
                <div class="phase-dot"         id="dot2"></div>
                <div class="phase-dot"         id="dot3"></div>
            </div>
            <div id="adminMsg" class="admin-msg"></div>
            <div id="adminPhase1" class="admin-phase active">
                <p class="phase-label">Phase 1 of 3 — Verify Your Credentials</p>
                <input type="email" id="adminCheckEmail" placeholder="Your Admin Email" style="width:100%;padding:15px;margin-bottom:10px;border:1px solid #eee;border-radius:12px;background:#fafafa;box-sizing:border-box;">
                <input type="password" id="adminCheckPassword" placeholder="Your Admin Password" style="width:100%;padding:15px;margin-bottom:20px;border:1px solid #eee;border-radius:12px;background:#fafafa;box-sizing:border-box;">
                <button type="button" id="btnCheckCreds" onclick="checkAdminCredentials()" style="width:100%;background:var(--neust-maroon);color:var(--neust-gold);padding:18px;border:none;border-radius:15px;cursor:pointer;font-weight:700;font-size:1rem;">
                    <span id="btnCheckCredsLabel">Verify Credentials</span>
                    <div class="spinner" id="spinCreds"></div>
                </button>
            </div>
            <div id="adminPhase2" class="admin-phase">
                <p class="phase-label">Phase 2 of 3 — Enter OTP Sent to Your Email</p>
                <!-- UPDATED (this adjustment): six single-digit OTP boxes, same kind of input as
                     login.php (auto-advance, Backspace goes back, paste fills all boxes). The
                     hidden #adminOtpInput keeps the 6-digit value for verifyAdminOtp(). -->
                <div class="otp-box-group" id="otpBoxesAdmin">
                    <input type="text" inputmode="numeric" pattern="[0-9]*" maxlength="1" class="otp-box" autocomplete="one-time-code" aria-label="OTP digit 1">
                    <input type="text" inputmode="numeric" pattern="[0-9]*" maxlength="1" class="otp-box" aria-label="OTP digit 2">
                    <input type="text" inputmode="numeric" pattern="[0-9]*" maxlength="1" class="otp-box" aria-label="OTP digit 3">
                    <input type="text" inputmode="numeric" pattern="[0-9]*" maxlength="1" class="otp-box" aria-label="OTP digit 4">
                    <input type="text" inputmode="numeric" pattern="[0-9]*" maxlength="1" class="otp-box" aria-label="OTP digit 5">
                    <input type="text" inputmode="numeric" pattern="[0-9]*" maxlength="1" class="otp-box" aria-label="OTP digit 6">
                </div>
                <input type="hidden" id="adminOtpInput" value="">
                <button type="button" id="btnVerifyOtp" onclick="verifyAdminOtp()" style="width:100%;background:var(--neust-maroon);color:var(--neust-gold);padding:18px;border:none;border-radius:15px;cursor:pointer;font-weight:700;font-size:1rem;">
                    <span id="btnVerifyOtpLabel">Verify OTP</span>
                    <div class="spinner" id="spinOtp"></div>
                </button>
                <button type="button" onclick="goBackToPhase1()" style="width:100%;background:none;border:none;color:#aaa;margin-top:12px;cursor:pointer;font-weight:600;">&#x2190; Back</button>
            </div>
            <div id="adminPhase3" class="admin-phase">
                <p class="phase-label">Phase 3 of 3 — New Admin Account Details</p>
                <!-- ── UPDATED: submission is now intercepted by JS (submitCreateAdmin)
                     and sent via fetch/AJAX, so creating the account no longer
                     reloads the page. The new admin row is appended to the Admin
                     Accounts table directly from the JSON response. ── -->
                <form id="createAdminForm" onsubmit="return submitCreateAdmin(event)">
                    <input type="text" name="new_admin_first_name" placeholder="First Name" style="width:100%;padding:15px;margin-bottom:10px;border:1px solid #eee;border-radius:12px;background:#fafafa;box-sizing:border-box;" required>
                    <input type="text" name="new_admin_middle_name" placeholder="Middle Name (optional)" style="width:100%;padding:15px;margin-bottom:10px;border:1px solid #eee;border-radius:12px;background:#fafafa;box-sizing:border-box;">
                    <input type="text" name="new_admin_last_name" placeholder="Last Name" style="width:100%;padding:15px;margin-bottom:10px;border:1px solid #eee;border-radius:12px;background:#fafafa;box-sizing:border-box;" required>
                    <input type="email" name="new_admin_email" placeholder="New Admin Email Address" style="width:100%;padding:15px;margin-bottom:20px;border:1px solid #eee;border-radius:12px;background:#fafafa;box-sizing:border-box;" required>

                    <!-- ── NEW: School information fields. These are used
                         later by company_reports.php to auto-fill the
                         OJT/Internship Training Plan's "School information"
                         section for the company rep, instead of it being
                         left blank to type from scratch. ── -->
                    <div style="font-size:0.72rem;font-weight:700;color:#a18a8a;text-transform:uppercase;letter-spacing:0.5px;margin:6px 0 10px;">School Information</div>
                    <select name="new_admin_school" id="newAdminSchool" onchange="toggleNewAdminSchoolOther()" style="width:100%;padding:15px;margin-bottom:10px;border:1px solid #eee;border-radius:12px;background:#fafafa;box-sizing:border-box;">
                        <option value="">Select School / Campus Branch</option>
                        <?php foreach ($campus_branches as $cb): ?>
                            <option value="<?= htmlspecialchars($cb ?? '') ?>"><?= htmlspecialchars($cb ?? '') ?></option>
                        <?php endforeach; ?>
                        <option value="__other__">Other (type below)</option>
                    </select>
                    <input type="text" name="new_admin_school_other" id="newAdminSchoolOther" placeholder="Type School / Campus Branch Name" style="width:100%;padding:15px;margin-bottom:10px;border:1px solid #eee;border-radius:12px;background:#fafafa;box-sizing:border-box;display:none;">
                    <input type="text" name="new_admin_school_address" placeholder="School Address" style="width:100%;padding:15px;margin-bottom:10px;border:1px solid #eee;border-radius:12px;background:#fafafa;box-sizing:border-box;">
                    <input type="text" name="new_admin_subject" placeholder="Subject" style="width:100%;padding:15px;margin-bottom:10px;border:1px solid #eee;border-radius:12px;background:#fafafa;box-sizing:border-box;">
                    <!-- UPDATED (this adjustment): "Required No. of Hours" field removed. Contact No.
                         accepts numbers only — any letter / symbol typed or pasted is ignored. -->
                    <input type="text" name="new_admin_contact_no" id="newAdminContactNo" placeholder="Contact No." inputmode="numeric" pattern="[0-9]*" autocomplete="tel" style="width:100%;padding:15px;margin-bottom:20px;border:1px solid #eee;border-radius:12px;background:#fafafa;box-sizing:border-box;">

                    <button type="submit" style="width:100%;background:var(--neust-maroon);color:var(--neust-gold);padding:18px;border:none;border-radius:15px;cursor:pointer;font-weight:700;font-size:1rem;position:relative;">
                        <span id="createAdminBtnLabel">Confirm &amp; Create Admin</span>
                        <div class="spinner" id="spinCreateAdmin" style="position:absolute;top:50%;left:50%;margin-top:-9px;margin-left:-9px;"></div>
                    </button>
                </form>
            </div>
        </div>

        <button type="button" onclick="closeModal()" style="width:100%;background:none;border:none;color:#aaa;margin-top:15px;cursor:pointer;font-weight:600;">Cancel</button>
    </div>
</div>

<script>
// ── SIDEBAR ──────────────────────────────────────────────────────────────────
const sb = document.getElementById('sidebar');
const toggleBtn = document.getElementById('toggleBtn');
toggleBtn.addEventListener('click', function() { sb.classList.toggle('collapsed'); });

// ══════════════════════════════════════════════════════════════════════════════
// NEW: GLOBAL LOADING / PROCESSING OVERLAY CONTROLS (same as admin_company_list.php)
// showGlobalLoading(label) reveals the popup with an optional custom label;
// hideGlobalLoading() fades it out. A small usage counter makes sure the
// overlay only hides once every in-flight operation that asked for it has
// actually finished, so overlapping calls can never hide it prematurely.
// ══════════════════════════════════════════════════════════════════════════════
let globalLoadingActiveCount = 0;
let globalLoadingInitialPhase = true;
const globalLoadingOverlay = document.getElementById('globalLoadingOverlay');
const globalLoadingLabel   = document.getElementById('globalLoadingLabel');

function showGlobalLoading(label) {
    globalLoadingActiveCount++;
    if (globalLoadingLabel) globalLoadingLabel.textContent = label || 'Loading';
    if (globalLoadingOverlay) globalLoadingOverlay.classList.remove('hidden');
}
function hideGlobalLoading() {
    globalLoadingActiveCount = Math.max(0, globalLoadingActiveCount - 1);
    if (globalLoadingActiveCount === 0 && globalLoadingOverlay) {
        globalLoadingOverlay.classList.add('hidden');
    }
}
// The overlay is visible by default (see CSS) so it covers the very first
// paint. Once the window has fully loaded it fades away on its own.
function finishInitialGlobalLoading() {
    if (!globalLoadingInitialPhase) return;
    globalLoadingInitialPhase = false;
    globalLoadingActiveCount = 0;
    if (globalLoadingOverlay) globalLoadingOverlay.classList.add('hidden');
}
window.addEventListener('load', finishInitialGlobalLoading);
// Safety net: if 'load' is delayed (slow CDN assets such as Font Awesome or
// Google Fonts), don't leave the admin staring at the popup forever. This
// only ever applies to the initial page load — it can never cut short an
// action that started later.
setTimeout(finishInitialGlobalLoading, 4000);

// ══════════════════════════════════════════════════════════════════════════════
// UPDATED (this adjustment): FILTER INPUT RESET ON LOAD — made more robust.
// ------------------------------------------------------------------------------
// Fixes the Student/Faculty/Company table's "Filter Name..." (and the
// dept/course dropdown) restoring whatever text/selection was typed
// before the browser was refreshed. Some browsers re-populate form
// fields from history/bfcache — or, on a plain manual reload, restore
// previously-typed values into plain <input> elements AFTER
// DOMContentLoaded (and sometimes even after the 'load' event) has
// already fired — so a single reset call right at DOMContentLoaded is
// not always enough to win that race.
//
// This now: (1) runs immediately as soon as the script executes,
// (2) re-runs on 'DOMContentLoaded', (3) re-runs on 'load',
// (4) re-runs on 'pageshow' (covers back/forward-cache restores), and
// (5) re-runs a few more times shortly after load via setTimeout, to
// reliably win the race against the browser's own late value-restore
// on a manual page reload — combined with the readonly-until-focus
// trick and autocomplete="off" already set on each field (see
// renderTable() in PHP), this guarantees the search bar (and
// department/course dropdown filters) always start blank on a fresh
// page load.
// ══════════════════════════════════════════════════════════════════════════════
function resetAllFilterInputs() {
    document.querySelectorAll('.filter-input').forEach(function (el) {
        if (el.value === '') return;
        el.value = '';
        el.dispatchEvent(new Event('input'));
        el.dispatchEvent(new Event('change'));
    });
}
// Run right away (covers normal script execution order on first paint)
resetAllFilterInputs();
// Covers the normal DOM-ready case
document.addEventListener('DOMContentLoaded', resetAllFilterInputs);
// Covers browsers that restore field values only after full page load
window.addEventListener('load', resetAllFilterInputs);
// Covers back/forward-cache (bfcache) restores
window.addEventListener('pageshow', resetAllFilterInputs);
// Extra safety net: some browsers restore typed values into plain
// <input> fields a short moment AFTER load/DOMContentLoaded on a manual
// reload — re-clearing a few more times shortly after guarantees the
// fields end up empty regardless of exactly when that restore happens.
setTimeout(resetAllFilterInputs, 50);
setTimeout(resetAllFilterInputs, 300);
setTimeout(resetAllFilterInputs, 800);

// ── FILTER TABLE (UNTOUCHED) ─────────────────────────────────────────────────
function filterTable(role, colIndex, query) {
    const table = document.getElementById('table-' + role);
    if (!table) return;
    const rows   = table.getElementsByTagName('tr');
    const filter = query.toLowerCase();
    // colIndex 0 = name (free-text search, use includes)
    // colIndex 2 = dept/type/course (dropdown, use exact match)
    const useExact = colIndex !== 0;
    for (let i = 1; i < rows.length; i++) {
        const cell = rows[i].getElementsByTagName('td')[colIndex];
        if (cell) {
            const text = (cell.textContent || cell.innerText).toLowerCase().trim();
            const match = filter === ''
                ? true
                : useExact ? text === filter : text.includes(filter);
            rows[i].style.display = match ? '' : 'none';
        }
    }
}

// ── EXCEL EXPORT (UNTOUCHED — function kept for any other callers) ────────────
function exportToExcel(tableID, filename) {
    let table = document.getElementById(tableID);
    let clone = table.cloneNode(true);
    for (let row of clone.rows) { row.deleteCell(-1); }
    let html  = clone.outerHTML;
    let blob  = new Blob(['\ufeff', html], { type: 'application/vnd.ms-excel' });
    let url   = URL.createObjectURL(blob);
    let a     = document.createElement("a");
    a.href = url; a.download = filename + ".xls"; a.click();
}

// ══════════════════════════════════════════════════════════════════════════════
// UPDATED: LIVE ACTIVITY LOG — no more auto-polling / blinking refresh.
// ------------------------------------------------------------------------------
// The Live Activity Log used to re-fetch and redraw itself from the server
// every 5 seconds (setInterval + ?refresh=1), which caused a visible
// blink/flash even when nothing had changed. That polling has been
// removed entirely. Instead, the log now updates directly in the DOM the
// instant an action actually completes — every AJAX endpoint on this page
// (create admin, delete account, activate/deactivate, accept/reject
// recovery) already returns a `log` object in its JSON response, and each
// success handler below calls prependLogEntry(data.log) to insert that
// single new entry at the top of the list. No other part of the page is
// touched, and no request is made unless the admin actually performs an
// action.
// ══════════════════════════════════════════════════════════════════════════════
function formatLogDate(dtStr) {
    if (!dtStr) return '';
    const iso = String(dtStr).replace(' ', 'T');
    const d = new Date(iso);
    if (isNaN(d.getTime())) return dtStr;
    const months = ['Jan','Feb','Mar','Apr','May','Jun','Jul','Aug','Sep','Oct','Nov','Dec'];
    let h = d.getHours();
    const ampm = h >= 12 ? 'PM' : 'AM';
    h = h % 12; if (h === 0) h = 12;
    const mins = String(d.getMinutes()).padStart(2, '0');
    const day  = String(d.getDate()).padStart(2, '0');
    const hh   = String(h).padStart(2, '0');
    return months[d.getMonth()] + ' ' + day + ', ' + d.getFullYear() + ' ' + hh + ':' + mins + ' ' + ampm;
}

function prependLogEntry(log) {
    if (!log) return;
    const container = document.getElementById('historyContainer');
    if (!container) return;

    // Remove the "No activity yet." placeholder if it's the only thing there.
    const emptyMsg = container.querySelector('p');
    if (emptyMsg) emptyMsg.remove();

    const badgeClass = 'badge-' + String(log.action_type || '').toUpperCase().replace(/ /g, '-');
    const entry = document.createElement('div');
    entry.className = 'log-entry';
    entry.innerHTML =
        '<div class="log-entry-inner">' +
            '<div class="log-entry-left">' +
                '<span class="badge ' + badgeClass + '">' + escapeHtml(log.action_type) + '</span>' +
                '<strong>' + escapeHtml(log.account_type) + ': ' + escapeHtml(log.account_name) + '</strong>' +
                '<div>Performed by: ' + escapeHtml(log.performed_by) + '</div>' +
                '<div>Details: ' + escapeHtml(log.details) + '</div>' +
            '</div>' +
            '<div class="log-entry-right">' + formatLogDate(log.created_at) + '</div>' +
        '</div>';
    container.insertBefore(entry, container.firstChild);
}

// ══════════════════════════════════════════════════════════════════════════════
// ENHANCED NOTIFICATION POPUP
// ══════════════════════════════════════════════════════════════════════════════
var _notifRedirect = '';

// ── NEW: renders an array of {label, value} pairs into a .acct-details block.
// Returns true when something was shown, false when the block is hidden. ──
function renderAccountDetails(el, details) {
    if (!el) return false;
    el.innerHTML = '';
    if (!Array.isArray(details) || details.length === 0) {
        el.classList.remove('show');
        return false;
    }
    details.forEach(function (d) {
        if (!d || d.value == null || String(d.value).trim() === '') return;
        const row = document.createElement('div');
        row.className = 'acct-details-row';
        const l = document.createElement('span');
        l.className = 'acct-details-label';
        l.textContent = d.label;
        const v = document.createElement('span');
        v.className = 'acct-details-value';
        v.textContent = d.value;
        row.appendChild(l);
        row.appendChild(v);
        el.appendChild(row);
    });
    const has = el.children.length > 0;
    el.classList.toggle('show', has);
    return has;
}

// UPDATED: optional 5th argument `details` (array of {label, value}). When
// omitted — as in every other call on this page — the popup is unchanged.
function showNotif(type, title, msg, redirect, details) {
    redirect = redirect || '';
    _notifRedirect = redirect;

    document.getElementById('notifTitle').className     = type;
    document.getElementById('notifTitle').textContent   = title;
    document.getElementById('notifMsg').textContent     = msg;
    document.getElementById('notifBtn').className       = type;

    const hasDetails = renderAccountDetails(document.getElementById('notifDetails'), details);
    document.getElementById('notifBody').classList.toggle('has-details', hasDetails);

    document.getElementById('notifOverlay').style.display = 'flex';
}

function closeNotif() {
    document.getElementById('notifOverlay').style.display = 'none';
    if (_notifRedirect) window.location.href = _notifRedirect;
}

// ══════════════════════════════════════════════════════════════════════════════
// DELETE CONFIRM DIALOG — UPDATED: now fires an AJAX request and removes
// the row from the table directly on success, instead of navigating to
// ?delete_id=...&role=... (which caused a full page reload).
// ══════════════════════════════════════════════════════════════════════════════
var _deleteId = null, _deleteRole = null, _deleteRowRef = null, _deleteDetails = null;

// ── NEW: reads the account's details straight from the table row (headers
// supply the labels, so it works for every role's column layout). The
// trailing Status/Actions columns are skipped. ──
function collectDeleteDetails(btn, role) {
    const details = [];
    const tr = btn.closest('tr');
    const table = btn.closest('table');
    if (!tr || !table) return details;
    const heads = table.querySelectorAll('thead th');
    const cells = tr.children;
    const roleLabel = role ? role.charAt(0).toUpperCase() + role.slice(1) : '';
    if (roleLabel) details.push({ label: 'Account Type', value: roleLabel });
    for (let i = 0; i < heads.length - 2 && i < cells.length; i++) {
        details.push({ label: heads[i].textContent.trim(), value: cells[i].textContent.trim() });
    }
    return details;
}

function confirmDelete(btn) {
    _deleteId    = btn.getAttribute('data-id');
    _deleteRole  = btn.getAttribute('data-role');
    _deleteRowRef = btn.closest('tr');
    _deleteDetails = collectDeleteDetails(btn, _deleteRole);
    const dcHas = renderAccountDetails(document.getElementById('dcDetails'), _deleteDetails);
    document.querySelector('#deleteConfirmBox .dc-body').classList.toggle('has-details', dcHas);
    document.getElementById('deleteConfirmOverlay').style.display = 'flex';
}
function cancelDelete() {
    document.getElementById('deleteConfirmOverlay').style.display = 'none';
    _deleteId = null; _deleteRole = null; _deleteRowRef = null; _deleteDetails = null;
}
function proceedDelete() {
    if (!_deleteId || !_deleteRole) { cancelDelete(); return; }

    const confirmBtn = document.getElementById('dcConfirmBtn');
    confirmBtn.disabled = true;
    confirmBtn.textContent = 'Deleting...';
    showGlobalLoading('Deleting account');

    const fd = new FormData();
    fd.append('ajax_delete_account', '1');
    fd.append('delete_id', _deleteId);
    fd.append('role', _deleteRole);

    fetch(window.location.pathname, { method: 'POST', body: fd })
        .then(r => r.json())
        .then(data => {
            hideGlobalLoading();
            document.getElementById('deleteConfirmOverlay').style.display = 'none';
            confirmBtn.disabled = false;
            confirmBtn.textContent = 'Yes, Delete';

            if (data.success) {
                if (_deleteRowRef) _deleteRowRef.remove();
                prependLogEntry(data.log);
                showNotif('delete', 'Account Deleted', data.message || 'Account deleted successfully!');
            } else {
                showNotif('error', 'Delete Failed', data.message || 'Unknown error.');
            }
            _deleteId = null; _deleteRole = null; _deleteRowRef = null; _deleteDetails = null;
        })
        .catch(err => {
            hideGlobalLoading();
            document.getElementById('deleteConfirmOverlay').style.display = 'none';
            confirmBtn.disabled = false;
            confirmBtn.textContent = 'Yes, Delete';
            showNotif('error', 'Network Error', err.message);
        });
}

// ══════════════════════════════════════════════════════════════════════════════
// NEW: STATUS TOGGLE (ACTIVATE / DEACTIVATE) CONFIRMATION MODAL
// ------------------------------------------------------------------------------
// Replaces the previous browser confirm() popups used by both the
// admin-only toggle (toggleAdminStatus, still used when a brand-new admin
// row is appended live via addAdminRowToTable()) and the generalized
// all-roles toggle (toggleAccountStatus, used by every Status button
// rendered server-side in renderTable() — Admin, Faculty, Company, and
// Student). Clicking a Status button now opens this custom popup with
// "Continue" / "Cancel" buttons instead of the native browser dialog:
//   - "Cancel"   closes the popup and does nothing (the toggle is NOT sent).
//   - "Continue" closes the popup and runs the exact same AJAX toggle logic
//                that used to run immediately after confirm() returned true.
// No other behavior of either toggle flow (endpoint used, button state
// updates, activity-log refresh, error handling) has been changed.
// ══════════════════════════════════════════════════════════════════════════════
var _statusToggleCtx = null; // { mode: 'admin' | 'generic', role, id, btn }

function openStatusConfirmModal(willDeactivate, ctx) {
    _statusToggleCtx = ctx;

    const cls        = willDeactivate ? 'deactivate' : 'activate';
    const bar        = document.getElementById('scBar');
    const title      = document.getElementById('scTitle');
    const msg        = document.getElementById('scMsg');
    const confirmBtn = document.getElementById('scConfirmBtn');

    bar.className   = 'sc-bar ' + cls;
    title.className = 'sc-title ' + cls;
    title.textContent = willDeactivate ? 'Deactivate Account' : 'Activate Account';
    msg.textContent = willDeactivate
        ? 'Are you sure you want to deactivate this account? They will immediately lose the ability to log in.'
        : 'Are you sure you want to activate this account? They will be able to log in again.';
    confirmBtn.className   = 'sc-confirm ' + cls;
    confirmBtn.textContent = 'Continue';
    confirmBtn.disabled    = false;

    document.getElementById('statusConfirmOverlay').style.display = 'flex';
}

function cancelStatusToggle() {
    document.getElementById('statusConfirmOverlay').style.display = 'none';
    _statusToggleCtx = null;
}

function proceedStatusToggle() {
    if (!_statusToggleCtx) { cancelStatusToggle(); return; }
    const ctx = _statusToggleCtx;
    document.getElementById('statusConfirmOverlay').style.display = 'none';
    _statusToggleCtx = null;

    if (ctx.mode === 'admin') {
        executeToggleAdminStatus(ctx.id, ctx.btn);
    } else {
        executeToggleAccountStatus(ctx.role, ctx.id, ctx.btn);
    }
}

// ── Admin-only toggle entry point (used by rows added live via
// addAdminRowToTable() right after a new admin is created) — now just
// opens the confirmation popup instead of running the AJAX call directly. ──
function toggleAdminStatus(id, btn) {
    const currentlyActive = btn.getAttribute('data-active') === '1';
    openStatusConfirmModal(currentlyActive, { mode: 'admin', id: id, btn: btn });
}

// The actual admin-only AJAX toggle logic — unchanged from before except
// that it no longer contains its own confirm() call (that check now
// happens up-front via the modal in toggleAdminStatus()/proceedStatusToggle()),
// and the button's visible label/icon now reflects the CURRENT status
// ("Active" / "Deactivated") instead of the next action to take.
function executeToggleAdminStatus(id, btn) {
    btn.disabled = true;
    const originalHTML = btn.innerHTML;
    btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> ...';
    showGlobalLoading('Updating account status');

    const fd = new FormData();
    fd.append('ajax_toggle_admin_status', '1');
    fd.append('admin_id', id);

    fetch(window.location.pathname, { method: 'POST', body: fd })
        .then(r => r.json())
        .then(data => {
            hideGlobalLoading();
            btn.disabled = false;
            if (data.success) {
                const nowActive = String(data.is_active) === '1';
                btn.setAttribute('data-active', nowActive ? '1' : '0');
                btn.innerHTML = '<i class="fas fa-' + (nowActive ? 'user-check' : 'user-slash') + '"></i> ' + (nowActive ? 'Active' : 'Deactivated');
                btn.title = nowActive
                    ? 'Deactivate this admin — they will immediately lose the ability to log in.'
                    : 'Activate this admin — they will be able to log in again.';
                btn.classList.remove('status-btn-active', 'status-btn-inactive');
                btn.classList.add(nowActive ? 'status-btn-active' : 'status-btn-inactive');
                // ── UPDATED (adjustment): notification title now spells out the
                // before → after transition ("Active → Deactivated" / "Deactivated
                // → Active") instead of just the end state, so it's immediately
                // clear to the admin what just changed, at a glance. ──
                showNotif(nowActive ? 'success' : 'warning',
                    nowActive ? 'Deactivated → Active' : 'Active → Deactivated',
                    data.message);
                prependLogEntry(data.log);
            } else {
                btn.innerHTML = originalHTML;
                showNotif('error', 'Action Failed', data.message || 'Unknown error.');
            }
        })
        .catch(err => {
            hideGlobalLoading();
            btn.disabled = false;
            btn.innerHTML = originalHTML;
            showNotif('error', 'Network Error', err.message);
        });
}

// ══════════════════════════════════════════════════════════════════════════════
// NEW: GENERALIZED STATUS TOGGLE (ALL ROLES) — Admin, Faculty, Company,
// Student. Every Status button rendered server-side in renderTable() calls
// toggleAccountStatus('$role', {$row['id']}, this); this function now opens
// the confirmation popup first, and only runs the AJAX call (against the
// existing ajax_toggle_account_status endpoint) once "Continue" is pressed.
// The button's visible label/icon reflects the CURRENT status ("Active" /
// "Deactivated") instead of the next action to take.
// ══════════════════════════════════════════════════════════════════════════════
function toggleAccountStatus(role, id, btn) {
    const currentlyActive = btn.getAttribute('data-active') === '1';
    openStatusConfirmModal(currentlyActive, { mode: 'generic', role: role, id: id, btn: btn });
}

function executeToggleAccountStatus(role, id, btn) {
    btn.disabled = true;
    const originalHTML = btn.innerHTML;
    btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> ...';
    showGlobalLoading('Updating account status');

    const fd = new FormData();
    fd.append('ajax_toggle_account_status', '1');
    fd.append('target_id', id);
    fd.append('role', role);

    fetch(window.location.pathname, { method: 'POST', body: fd })
        .then(r => r.json())
        .then(data => {
            hideGlobalLoading();
            btn.disabled = false;
            if (data.success) {
                const nowActive = String(data.is_active) === '1';
                btn.setAttribute('data-active', nowActive ? '1' : '0');
                btn.innerHTML = '<i class="fas fa-' + (nowActive ? 'user-check' : 'user-slash') + '"></i> ' + (nowActive ? 'Active' : 'Deactivated');
                const roleLabelForTitle = (role === 'admin') ? 'admin' : 'account';
                btn.title = nowActive
                    ? 'Deactivate this ' + roleLabelForTitle + ' — they will immediately lose the ability to log in.'
                    : 'Activate this ' + roleLabelForTitle + ' — they will be able to log in again.';
                btn.classList.remove('status-btn-active', 'status-btn-inactive');
                btn.classList.add(nowActive ? 'status-btn-active' : 'status-btn-inactive');
                // ── UPDATED (adjustment): same clearer before → after transition
                // wording as the admin-only toggle above, applied here for
                // Faculty/Company/Student (and Admin rows going through this
                // generic endpoint) — e.g. "Active → Deactivated" instead of
                // just "Account Deactivated", so the change is unambiguous. ──
                showNotif(nowActive ? 'success' : 'warning',
                    nowActive ? 'Deactivated → Active' : 'Active → Deactivated',
                    data.message);
                prependLogEntry(data.log);
            } else {
                btn.innerHTML = originalHTML;
                showNotif('error', 'Action Failed', data.message || 'Unknown error.');
            }
        })
        .catch(err => {
            hideGlobalLoading();
            btn.disabled = false;
            btn.innerHTML = originalHTML;
            showNotif('error', 'Network Error', err.message);
        });
}

// ── Trigger popup from PHP state (kept for any other page states that
// still redirect here with ?success=..., e.g. links from other pages) ──
document.addEventListener('DOMContentLoaded', function () {
    <?php if (isset($_GET['success'])): ?>
        <?php
        $msg = $_GET['success'];
        if ($msg === 'created')      $notifMsg = 'Account created successfully!';
        elseif ($msg === 'deleted')  $notifMsg = 'Account deleted successfully!';
        else                         $notifMsg = htmlspecialchars($msg ?? '');
        ?>
        <?php if ($msg === 'deleted'): ?>
            showNotif('delete', 'Account Deleted', <?= json_encode($notifMsg) ?>);
        <?php else: ?>
            showNotif('success', 'Done!', <?= json_encode($notifMsg) ?>);
        <?php endif; ?>
    <?php endif; ?>
});

// ══════════════════════════════════════════════════════════════════════════════
// NEW: SPEED-DIAL FAB MENU — toggles the bottom-right FAB open/closed and
// wires up its three options (My Profile / Email Recovery Requests /
// Create Admin Account). This is what was missing before: the CSS for
// #fabMenu/.fab-main-btn/.fab-options/.fab-option already existed, but the
// HTML markup (and this toggle function) were never added, so nothing
// ever rendered. Clicking outside the open menu, or clicking the main
// button again, closes it.
// ══════════════════════════════════════════════════════════════════════════════
function toggleFabMenu() {
    document.getElementById('fabMenu').classList.toggle('open');
}
document.addEventListener('click', function (e) {
    const fab = document.getElementById('fabMenu');
    if (fab && fab.classList.contains('open') && !fab.contains(e.target)) {
        fab.classList.remove('open');
    }
});
function closeFabMenu() {
    document.getElementById('fabMenu').classList.remove('open');
}

// ── MY PROFILE MODAL ─────────────────────────────────────────────────────────
function openProfileModal() {
    closeFabMenu();
    document.getElementById('profileModal').style.display = 'flex';
}
function closeProfileModal() {
    document.getElementById('profileModal').style.display = 'none';
}

// ── MODAL OPEN / CLOSE ───────────────────────────────────────────────────────
function openModal() { closeFabMenu(); document.getElementById('modal').style.display = 'flex'; resetModalState(); }
function closeModal() { document.getElementById('modal').style.display = 'none'; resetModalState(); }
function resetModalState() {
    /* Company account creation has been removed entirely — the modal
       now always opens straight into the (only remaining) Admin
       creation flow, so there is no role selector / non-admin form
       to reset anymore. */
    document.getElementById('adminFlow').style.display = 'block';
    setAdminPhase(1); clearAdminMsg();
    document.getElementById('adminCheckEmail').value    = '';
    document.getElementById('adminCheckPassword').value = '';
    document.getElementById('adminOtpInput').value      = '';
    clearAdminOtpBoxes(); // NEW (this adjustment)
    const createForm = document.getElementById('createAdminForm');
    if (createForm) createForm.reset();
    const schoolSel   = document.getElementById('newAdminSchool');
    const schoolOther = document.getElementById('newAdminSchoolOther');
    if (schoolSel)   schoolSel.value = '';
    if (schoolOther) { schoolOther.value = ''; schoolOther.style.display = 'none'; }
}

// ── NEW: toggles the free-text "Other" school input on/off depending on
// whether "Other (type below)" is selected in the School dropdown. ──
function toggleNewAdminSchoolOther() {
    const sel   = document.getElementById('newAdminSchool');
    const other = document.getElementById('newAdminSchoolOther');
    if (!sel || !other) return;
    other.style.display = (sel.value === '__other__') ? 'block' : 'none';
}

function setAdminPhase(phase) {
    [1, 2, 3].forEach(p => {
        document.getElementById('adminPhase' + p).classList.remove('active');
        const dot = document.getElementById('dot' + p);
        dot.classList.remove('current', 'done');
        if (p < phase)   dot.classList.add('done');
        if (p === phase) dot.classList.add('current');
    });
    document.getElementById('adminPhase' + phase).classList.add('active');
}
function goBackToPhase1() { clearAdminMsg(); setAdminPhase(1); }

// ══ NEW (this adjustment): admin OTP boxes — same behaviour as login.php ══
function syncAdminOtpHidden() {
    var boxes = document.querySelectorAll('#otpBoxesAdmin .otp-box');
    var hidden = document.getElementById('adminOtpInput');
    if (hidden) hidden.value = Array.prototype.map.call(boxes, function (b) { return b.value; }).join('');
}
function clearAdminOtpBoxes() {
    document.querySelectorAll('#otpBoxesAdmin .otp-box').forEach(function (b) { b.value = ''; b.classList.remove('otp-box-filled'); });
    syncAdminOtpHidden();
}
(function () {
    var group = document.getElementById('otpBoxesAdmin');
    if (!group) return;
    var boxes = Array.prototype.slice.call(group.querySelectorAll('.otp-box'));
    boxes.forEach(function (box, idx) {
        box.addEventListener('input', function () {
            box.value = box.value.replace(/[^0-9]/g, '').slice(-1);
            box.classList.toggle('otp-box-filled', !!box.value);
            if (box.value && idx < boxes.length - 1) boxes[idx + 1].focus();
            syncAdminOtpHidden();
        });
        box.addEventListener('keydown', function (e) {
            if (e.key === 'Backspace' && !box.value && idx > 0) boxes[idx - 1].focus();
            if (e.key === 'Enter') { e.preventDefault(); verifyAdminOtp(); }
        });
        box.addEventListener('paste', function (e) {
            e.preventDefault();
            var text = (e.clipboardData || window.clipboardData).getData('text').replace(/[^0-9]/g, '');
            if (!text) return;
            for (var i = 0; i < boxes.length; i++) {
                boxes[i].value = text[i] || '';
                boxes[i].classList.toggle('otp-box-filled', !!boxes[i].value);
            }
            syncAdminOtpHidden();
            var next = boxes.findIndex(function (b) { return !b.value; });
            (next === -1 ? boxes[boxes.length - 1] : boxes[next]).focus();
        });
    });
    // focus the first box whenever phase 2 opens
    var phase2 = document.getElementById('adminPhase2');
    if (phase2 && window.MutationObserver) {
        new MutationObserver(function () {
            if (phase2.classList.contains('active')) setTimeout(function () { boxes[0].focus(); }, 50);
        }).observe(phase2, { attributes: true, attributeFilter: ['class'] });
    }
})();

// ══ NEW (this adjustment): Create Admin form input helpers ══
// Contact No.: digits only (letters / symbols typed or pasted are dropped).
// Text fields: first letter of each word becomes a capital letter as you type.
(function () {
    var contact = document.getElementById('newAdminContactNo');
    if (contact) {
        contact.addEventListener('input', function () {
            var cleaned = contact.value.replace(/\D+/g, '');
            if (cleaned !== contact.value) {
                var pos = contact.selectionStart - (contact.value.length - cleaned.length);
                contact.value = cleaned;
                try { contact.setSelectionRange(Math.max(0, pos), Math.max(0, pos)); } catch (e) {}
            }
        });
    }
    var form = document.getElementById('createAdminForm');
    if (!form) return;
    var capFields = form.querySelectorAll('input[name="new_admin_first_name"], input[name="new_admin_middle_name"], input[name="new_admin_last_name"], input[name="new_admin_school_other"], input[name="new_admin_school_address"], input[name="new_admin_subject"]');
    Array.prototype.forEach.call(capFields, function (inp) {
        inp.setAttribute('autocapitalize', 'words');
        inp.addEventListener('input', function () {
            var capped = inp.value.replace(/(^|[\s\-'])(\S)/g, function (m, pre, ch) { return pre + ch.toUpperCase(); });
            if (capped !== inp.value) {
                var s = inp.selectionStart, en = inp.selectionEnd;
                inp.value = capped;
                try { inp.setSelectionRange(s, en); } catch (e) {}
            }
        });
    });
})();
function showAdminMsg(msg, type) { const el = document.getElementById('adminMsg'); el.className = 'admin-msg ' + type; el.textContent = msg; }
function clearAdminMsg() { const el = document.getElementById('adminMsg'); el.className = 'admin-msg'; el.textContent = ''; }
function setBtnLoading(btnId, spinId, labelId, loading) {
    document.getElementById(btnId).disabled        = loading;
    document.getElementById(spinId).style.display  = loading ? 'block'  : 'none';
    document.getElementById(labelId).style.display = loading ? 'none'   : 'inline';
}
function checkAdminCredentials() {
    const email    = document.getElementById('adminCheckEmail').value.trim();
    const password = document.getElementById('adminCheckPassword').value.trim();
    if (!email || !password) { showAdminMsg('Please enter your email and password.', 'error'); return; }
    clearAdminMsg();
    setBtnLoading('btnCheckCreds', 'spinCreds', 'btnCheckCredsLabel', true);
    showGlobalLoading('Verifying credentials');
    const fd = new FormData();
    fd.append('ajax_check_admin_credentials', '1');
    fd.append('admin_email',    email);
    fd.append('admin_password', password);
    fetch(window.location.pathname, { method: 'POST', body: fd })
        .then(r => r.json())
        .then(data => {
            hideGlobalLoading();
            setBtnLoading('btnCheckCreds', 'spinCreds', 'btnCheckCredsLabel', false);
            if (data.success) { showAdminMsg('Credentials verified. OTP sent.', 'success'); setTimeout(() => { clearAdminMsg(); setAdminPhase(2); }, 1400); }
            else { showAdminMsg(data.message, 'error'); }
        })
        .catch(() => { hideGlobalLoading(); setBtnLoading('btnCheckCreds', 'spinCreds', 'btnCheckCredsLabel', false); showAdminMsg('Network error.', 'error'); });
}
function verifyAdminOtp() {
    const otp = document.getElementById('adminOtpInput').value.trim();
    if (otp.length !== 6 || isNaN(otp)) { showAdminMsg('Please enter the 6-digit OTP.', 'error'); return; }
    clearAdminMsg();
    setBtnLoading('btnVerifyOtp', 'spinOtp', 'btnVerifyOtpLabel', true);
    showGlobalLoading('Verifying OTP');
    const fd = new FormData();
    fd.append('ajax_verify_admin_otp', '1');
    fd.append('otp', otp);
    fetch(window.location.pathname, { method: 'POST', body: fd })
        .then(r => r.json())
        .then(data => {
            hideGlobalLoading();
            setBtnLoading('btnVerifyOtp', 'spinOtp', 'btnVerifyOtpLabel', false);
            if (data.success) { showAdminMsg('OTP verified.', 'success'); setTimeout(() => { clearAdminMsg(); setAdminPhase(3); }, 1400); }
            else { showAdminMsg(data.message, 'error'); }
        })
        .catch(() => { hideGlobalLoading(); setBtnLoading('btnVerifyOtp', 'spinOtp', 'btnVerifyOtpLabel', false); showAdminMsg('Network error.', 'error'); });
}

// ══════════════════════════════════════════════════════════════════════════════
// NEW: CREATE ADMIN ACCOUNT (Phase 3) — now submitted via AJAX. On success
// the modal closes, a notification pops up, and the new admin row is
// appended to the Admin Accounts table directly — no page reload.
// ══════════════════════════════════════════════════════════════════════════════
function submitCreateAdmin(e) {
    e.preventDefault();
    const form = document.getElementById('createAdminForm');
    const fd = new FormData(form);
    fd.append('ajax_create_admin_account', '1');

    const submitBtn = form.querySelector('button[type="submit"]');
    submitBtn.disabled = true;
    document.getElementById('spinCreateAdmin').style.display = 'block';
    document.getElementById('createAdminBtnLabel').style.display = 'none';
    showGlobalLoading('Creating admin account');

    fetch(window.location.pathname, { method: 'POST', body: fd })
        .then(r => r.json())
        .then(data => {
            hideGlobalLoading();
            submitBtn.disabled = false;
            document.getElementById('spinCreateAdmin').style.display = 'none';
            document.getElementById('createAdminBtnLabel').style.display = 'inline';

            if (data.success) {
                addAdminRowToTable(data.admin);
                prependLogEntry(data.log);
                closeModal();
                showNotif('success', 'Account Created!', data.message || 'Account created successfully!');
            } else {
                showAdminMsg(data.message || 'Something went wrong.', 'error');
            }
        })
        .catch(err => {
            hideGlobalLoading();
            submitBtn.disabled = false;
            document.getElementById('spinCreateAdmin').style.display = 'none';
            document.getElementById('createAdminBtnLabel').style.display = 'inline';
            showAdminMsg('Network error: ' + err.message, 'error');
        });

    return false;
}

function escapeHtml(str) {
    const div = document.createElement('div');
    div.textContent = str == null ? '' : String(str);
    return div.innerHTML;
}

// ══════════════════════════════════════════════════════════════════════════════
// NEW: ensures the "Admin Accounts" table section exists in the DOM,
// building it on the fly (matching the same markup renderTable() emits
// server-side) if this is the very first admin ever created and the
// section wasn't rendered on page load. This is what previously fell
// back to a full window.location.reload(). — now everything happens via
// direct DOM insertion, so no page reload is ever needed here.
// ══════════════════════════════════════════════════════════════════════════════
function ensureAdminTableSection() {
    let table = document.getElementById('table-admin');
    if (table) return table;

    const container = document.getElementById('tablesContainer');
    const section = document.createElement('div');
    section.className = 'table-section';
    section.id = 'section-admin';
    section.innerHTML =
        '<div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:20px;">' +
            '<h3>Admin Accounts</h3>' +
        '</div>' +
        '<table id="table-admin">' +
            '<thead><tr><th>Full Name</th><th>Email</th><th>Status</th><th>Actions</th></tr></thead>' +
            '<tbody></tbody>' +
        '</table>';

    // Admin section is always rendered first server-side, so keep it first here too.
    container.insertBefore(section, container.firstChild);
    return document.getElementById('table-admin');
}

// ── UPDATED (adjustment): now renders 4 cells (Full Name, Email, Status,
// Actions) instead of 3, so a freshly-created admin row matches the exact
// same layout renderTable() emits server-side for every other admin row —
// including a working Delete button in the Actions cell (previously this
// live-appended row had no delete control at all). The Status button label
// reflects the CURRENT state ("Active") and the Delete button uses the same
// pill design ("delete-btn-pill") as every other role's Delete button. ──
function addAdminRowToTable(admin) {
    const table = ensureAdminTableSection();
    const tbody = table.querySelector('tbody');
    const tr = document.createElement('tr');
    tr.innerHTML =
        '<td>' + escapeHtml(admin.full_name) + '</td>' +
        '<td>' + escapeHtml(admin.email) + '</td>' +
        '<td><button type="button" class="status-toggle-btn status-btn-active" data-active="1" ' +
        'title="Deactivate this admin — they will immediately lose the ability to log in." ' +
        'onclick="toggleAdminStatus(' + admin.id + ', this)"><i class="fas fa-user-check"></i> Active</button></td>' +
        '<td><button type="button" class="delete-btn-pill" data-id="' + admin.id + '" data-role="admin" ' +
        'title="Permanently delete this account and all of its related records." ' +
        'onclick="confirmDelete(this)"><i class="fas fa-trash"></i> Delete</button></td>';
    tbody.appendChild(tr);
}

// ── RECOVERY DRAWER ──────────────────────────────────────────────────────────
function openRecoveryDrawer()  { closeFabMenu(); document.getElementById('recoveryDrawerOverlay').style.display = 'flex'; }
function closeRecoveryDrawer() { document.getElementById('recoveryDrawerOverlay').style.display = 'none'; }

// ── ACCEPT ───────────────────────────────────────────────────────────────────
// UPDATED (this adjustment): the browser confirm() box is replaced by the
// Accept confirmation popup (#acceptConfirmOverlay, same design as the Reject
// popup). "Confirm Accept" runs exactly the same accept request as before.
var _pendingAcceptId = null;
function acceptRecovery(reqId) {
    if (!document.getElementById('acceptBtn' + reqId)) return;
    _pendingAcceptId = reqId;
    var card = document.getElementById('reqCard' + reqId);
    var name = card ? card.querySelector('.req-name') : null;
    var vals = {};
    if (card) {
        card.querySelectorAll('.req-field').forEach(function (f) {
            var l = f.querySelector('.req-field-label'), v = f.querySelector('.req-field-value');
            if (l && v) vals[l.textContent.trim().toLowerCase()] = v.textContent.trim();
        });
    }
    document.getElementById('acceptSumName').textContent = name ? name.textContent.trim() : '\u2014';
    document.getElementById('acceptSumOld').textContent  = vals['old email'] || '\u2014';
    document.getElementById('acceptSumNew').textContent  = vals['new email'] || '\u2014';
    var cBtn = document.getElementById('acceptConfirmBtn');
    cBtn.disabled = false;
    cBtn.innerHTML = '<i class="fas fa-check"></i> Confirm Accept';
    document.getElementById('acceptConfirmOverlay').style.display = 'flex';
}
function closeAcceptModal() {
    document.getElementById('acceptConfirmOverlay').style.display = 'none';
    _pendingAcceptId = null;
}
function confirmAccept() {
    if (!_pendingAcceptId) return;
    var reqId = _pendingAcceptId;
    closeAcceptModal();
    proceedAcceptRecovery(reqId);
}
document.addEventListener('keydown', function (e) {
    var ov = document.getElementById('acceptConfirmOverlay');
    if (e.key === 'Escape' && ov && ov.style.display === 'flex') closeAcceptModal();
});

function proceedAcceptRecovery(reqId) {
    var btn  = document.getElementById('acceptBtn' + reqId);
    var rBtn = document.getElementById('rejectBtn' + reqId);
    if (!btn) return;
    btn.disabled = true; if (rBtn) rBtn.disabled = true;
    btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i>'; // UPDATED: icon button
    showGlobalLoading('Processing request');
    var fd = new FormData();
    fd.append('ajax_accept_recovery', '1');
    fd.append('req_id', reqId);
    fetch(window.location.pathname, { method: 'POST', body: fd })
        .then(r => r.text())
        .then(raw => {
            hideGlobalLoading();
            var data;
            try { data = JSON.parse(raw); } catch(e) {
                showNotif('error', 'Accept Failed', 'Server error: ' + raw.substring(0,200));
                btn.disabled = false; if (rBtn) rBtn.disabled = false;
                btn.innerHTML = '<i class="fas fa-check"></i>'; return;
            }
            if (data.success) {
                var card = document.getElementById('reqCard' + reqId);
                var row  = card.querySelector('.req-btn-row');
                if (row) row.outerHTML = '<div class="req-accepted-tag"><i class="fas fa-check-circle"></i> Accepted</div>';
                card.classList.remove('rejected'); card.classList.add('accepted');
                moveReqCardToHistory(card); // NEW (Recovery request history)
                updateFabBadge(-1);
                prependLogEntry(data.log);
            } else {
                showNotif('error', 'Accept Failed', data.message || 'Unknown error');
                btn.disabled = false; if (rBtn) rBtn.disabled = false;
                btn.innerHTML = '<i class="fas fa-check"></i>';
            }
        })
        .catch(err => {
            hideGlobalLoading();
            btn.disabled = false; if (rBtn) rBtn.disabled = false;
            btn.innerHTML = '<i class="fas fa-check"></i>';
            showNotif('error', 'Accept Failed', 'Network error: ' + err.message);
        });
}

// ── REJECT ───────────────────────────────────────────────────────────────────
var _pendingRejectId = null;

function openRejectModal(reqId) {
    _pendingRejectId = reqId;
    document.getElementById('rejectReasonInput').value = '';
    var btn = document.getElementById('rejectConfirmBtn');
    btn.disabled = false;
    btn.innerHTML = '<i class="fas fa-times"></i> Confirm Reject';
    document.getElementById('rejectReasonOverlay').style.display = 'flex';
}
function closeRejectModal() {
    document.getElementById('rejectReasonOverlay').style.display = 'none';
    _pendingRejectId = null;
}
function confirmReject() {
    if (!_pendingRejectId) return;
    var reqId  = _pendingRejectId;
    var reason = document.getElementById('rejectReasonInput').value.trim();
    var btn    = document.getElementById('rejectConfirmBtn');
    var rBtn   = document.getElementById('rejectBtn'   + reqId);
    var aBtn   = document.getElementById('acceptBtn'   + reqId);
    btn.disabled = true;
    btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Rejecting...';
    showGlobalLoading('Rejecting request');
    var fd = new FormData();
    fd.append('ajax_reject_recovery', '1');
    fd.append('req_id', reqId);
    fd.append('reject_reason', reason);
    fetch(window.location.pathname, { method: 'POST', body: fd })
        .then(r => r.text())
        .then(raw => {
            hideGlobalLoading();
            var data;
            try { data = JSON.parse(raw); } catch(e) {
                showNotif('error', 'Reject Failed', 'Server error: ' + raw.substring(0,200));
                btn.disabled = false;
                btn.innerHTML = '<i class="fas fa-times"></i> Confirm Reject'; return;
            }
            closeRejectModal();
            if (data.success) {
                var card = document.getElementById('reqCard' + reqId);
                var row  = card.querySelector('.req-btn-row');
                if (row) row.outerHTML = '<div class="req-rejected-tag"><i class="fas fa-times-circle"></i> Rejected</div>';
                card.classList.remove('accepted'); card.classList.add('rejected');
                moveReqCardToHistory(card); // NEW (Recovery request history)
                updateFabBadge(-1);
                prependLogEntry(data.log);
            } else {
                showNotif('error', 'Reject Failed', data.message || 'Unknown error');
            }
        })
        .catch(err => {
            hideGlobalLoading();
            btn.disabled = false;
            btn.innerHTML = '<i class="fas fa-times"></i> Confirm Reject';
            showNotif('error', 'Reject Failed', 'Network error: ' + err.message);
        });
}

// ── NEW (Recovery request history): Pending / History tabs ─────────────────
function switchRecoveryTab(tab) {
    var isHistory = tab === 'history';
    var pList = document.getElementById('reqPendingList');
    var hList = document.getElementById('reqHistoryList');
    if (pList) pList.style.display = isHistory ? 'none' : '';
    if (hList) hList.style.display = isHistory ? '' : 'none';
    var pTab = document.getElementById('reqTabPending');
    var hTab = document.getElementById('reqTabHistory');
    if (pTab) pTab.classList.toggle('active', !isHistory);
    if (hTab) hTab.classList.toggle('active', isHistory);
}
function refreshRecoveryLists() {
    var pItems = document.getElementById('reqPendingItems');
    var hItems = document.getElementById('reqHistoryItems');
    var pCount = pItems ? pItems.querySelectorAll('.req-card').length : 0;
    var hCount = hItems ? hItems.querySelectorAll('.req-card').length : 0;
    var el;
    if ((el = document.getElementById('reqPendingCount'))) el.textContent = pCount;
    if ((el = document.getElementById('reqHistoryCount'))) el.textContent = hCount;
    if ((el = document.getElementById('reqPendingEmpty'))) el.style.display = pCount ? 'none' : '';
    if ((el = document.getElementById('reqHistoryEmpty'))) el.style.display = hCount ? 'none' : '';
    if ((el = document.getElementById('clearHistoryBtn'))) el.disabled = hCount === 0;
    if ((el = document.getElementById('recoveryHeaderPendingNum'))) el.textContent = pCount;
    if ((el = document.getElementById('recoveryHeaderPending'))) el.style.display = pCount > 0 ? '' : 'none';
}
// Accepted / Rejected card → top of the History list
function moveReqCardToHistory(card) {
    var hItems = document.getElementById('reqHistoryItems');
    if (!card || !hItems) return;
    hItems.insertBefore(card, hItems.firstChild);
    refreshRecoveryLists();
}
function openClearHistoryConfirm() {
    var btn = document.getElementById('clearHistoryBtn');
    if (btn && btn.disabled) return;
    var ov = document.getElementById('clearHistoryConfirmOverlay');
    if (ov) ov.classList.add('show');
}
function closeClearHistoryConfirm() {
    var ov = document.getElementById('clearHistoryConfirmOverlay');
    if (ov) ov.classList.remove('show');
}
function clearRecoveryHistory() {
    var cBtn = document.getElementById('clearHistoryConfirmBtn');
    if (cBtn) cBtn.disabled = true;
    closeClearHistoryConfirm();
    showGlobalLoading('Clearing history');
    var fd = new FormData();
    fd.append('ajax_clear_recovery_history', '1');
    fetch(window.location.pathname, { method: 'POST', body: fd })
        .then(function (r) { return r.text(); })
        .then(function (raw) {
            hideGlobalLoading();
            if (cBtn) cBtn.disabled = false;
            var data;
            try { data = JSON.parse(raw); } catch (e) {
                showNotif('error', 'Clear History Failed', 'Server error: ' + raw.substring(0, 200));
                return;
            }
            if (data.success) {
                var hItems = document.getElementById('reqHistoryItems');
                if (hItems) hItems.innerHTML = '';
                refreshRecoveryLists();
                showNotif('success', 'History Cleared', data.message || 'The request history was cleared.');
            } else {
                showNotif('error', 'Clear History Failed', data.message || 'The history could not be cleared.');
            }
        })
        .catch(function (err) {
            hideGlobalLoading();
            if (cBtn) cBtn.disabled = false;
            showNotif('error', 'Clear History Failed', 'Network error: ' + err.message);
        });
}

// ── NEW (this adjustment): LIVE INBOX — fresh requests appear in Pending ──────
// Called by the live request poll (every few seconds) with the current list of
// Pending requests. Any Pending request that isn't on the page yet is fetched
// as a ready-made card and added to the TOP of the Pending list — no reload
// needed. Cards already on the page are never moved: a card only goes to
// History when the admin accepts or rejects it (moveReqCardToHistory()).
var _reqCardsLoading = false;
function _reqInsertPendingCards(cardEls) {
    var list = document.getElementById('reqPendingItems');
    if (!list) return;
    // cards arrive newest first; insert oldest first so the newest ends up on top
    cardEls.slice().reverse().forEach(function (card) {
        if (!card || !card.id || document.getElementById(card.id)) return;   // already on the page
        list.insertBefore(card, list.firstChild);
    });
    refreshRecoveryLists();
}
// Fallback: read the missing cards from a fresh copy of this page (the same
// cards the page draws on load) if the card endpoint can't be read for any reason.
function _reqLoadCardsFromPage(missing) {
    return fetch(window.location.pathname, { credentials: 'same-origin', cache: 'no-store' })
        .then(function (r) { return r.text(); })
        .then(function (html) {
            var doc = new DOMParser().parseFromString(html, 'text/html');
            var found = [];
            missing.forEach(function (id) {
                var c = doc.querySelector('#reqPendingItems #reqCard' + id);
                if (c) found.push(document.importNode(c, true));
            });
            _reqInsertPendingCards(found);
        });
}
function cvSyncRecoveryCards(rows) {
    if (!Array.isArray(rows)) return;
    var num = document.getElementById('recoveryHeaderPendingNum');
    var pill = document.getElementById('recoveryHeaderPending');
    if (num) num.textContent = rows.length;
    if (pill) pill.style.display = rows.length > 0 ? '' : 'none';

    var pendingIds = rows.map(function (r) { return parseInt(r.id, 10); }).filter(function (id) { return id > 0; });

    // A request that is still Pending on the server must never sit in History:
    // if its (unprocessed) card is there, move it back to the top of Pending.
    var pList = document.getElementById('reqPendingItems');
    var hList = document.getElementById('reqHistoryItems');
    var moved = false;
    if (pList && hList) {
        pendingIds.forEach(function (id) {
            var c = document.getElementById('reqCard' + id);
            if (c && hList.contains(c) && !c.classList.contains('accepted') && !c.classList.contains('rejected')) {
                pList.insertBefore(c, pList.firstChild);
                moved = true;
            }
        });
    }
    if (moved) refreshRecoveryLists();

    var missing = pendingIds.filter(function (id) { return !document.getElementById('reqCard' + id); });
    if (!missing.length || _reqCardsLoading) return;
    _reqCardsLoading = true;
    fetch(window.location.pathname + '?recovery_request_cards=1&ids=' + encodeURIComponent(missing.join(',')), { credentials: 'same-origin', cache: 'no-store' })
        .then(function (r) { return r.text(); })
        .then(function (raw) {
            var data = null;
            try { data = JSON.parse(raw); } catch (e) { data = null; }
            var cards = (data && Array.isArray(data.cards)) ? data.cards : [];
            var els = [];
            cards.forEach(function (c) {
                var wrap = document.createElement('div');
                wrap.innerHTML = String(c.html || '').trim();
                if (wrap.firstElementChild) els.push(wrap.firstElementChild);
            });
            _reqInsertPendingCards(els);
            var still = missing.filter(function (id) { return !document.getElementById('reqCard' + id); });
            if (still.length) return _reqLoadCardsFromPage(still);   // fallback
        })
        .catch(function () { return _reqLoadCardsFromPage(missing).catch(function () {}); })
        .then(function () { _reqCardsLoading = false; }, function () { _reqCardsLoading = false; });
}
window.cvSyncRecoveryCards = cvSyncRecoveryCards;

// ── FAB BADGE ────────────────────────────────────────────────────────────────
// UPDATED: now also syncs the FAB main-button badge (#fabMainBadge) alongside
// the existing #recoveryFabBadge, since Email Recovery is now one of the FAB
// menu's options rather than a standalone button. Both badges always show
// the same pending-recovery-request count.
var _fabCount = <?= $pending_count ?>;
function updateFabBadge(delta) {
    _fabCount += delta;
    var badge = document.getElementById('recoveryFabBadge');
    var mainBadge = document.getElementById('fabMainBadge');
    if (_fabCount > 0) {
        badge.textContent = _fabCount;
        badge.style.display = 'flex';
        if (mainBadge) { mainBadge.textContent = _fabCount; mainBadge.style.display = 'flex'; }
    } else {
        badge.style.display = 'none';
        if (mainBadge) mainBadge.style.display = 'none';
    }
}

// ── App request badge live poll ──
(function() {
    function pollAppBadge() {
        fetch('administrator.php?app_request_count=1')
            .then(r => r.json())
            .then(data => {
                const badge = document.getElementById('sidebarAppBadge');
                if (!badge) return;
                const count = data.count || 0;
                badge.textContent = count;
                badge.style.display = count > 0 ? 'inline-flex' : 'none';
            })
            .catch(() => {});
    }
    setTimeout(() => { pollAppBadge(); setInterval(pollAppBadge, 30000); }, 6000);
})();

// ── NEW: MOA pending badge live poll ──
// Mirrors the same moa_pending_count AJAX endpoint used in
// admin_student_list.php and company_validation.php, so the badge here
// stays in sync with admin actions taken on those pages (accepting or
// rejecting a MOA request) without requiring a full page reload.
(function() {
    function pollMoaBadge() {
        // FIX (sidebar notification indicator): this endpoint now returns the same
        // notification count company_validation.php shows (see monitoring_company_validation_notif_count()).
        fetch('monitoring.php?moa_pending_count=1', { credentials: 'same-origin' })
            .then(r => r.json())
            .then(data => {
                const badge = document.getElementById('sidebarMoaBadge');
                if (!badge) return;
                const count = parseInt(data.count, 10) || 0; // FIX (sidebar notification indicator)
                badge.textContent = count;
                badge.style.display = count > 0 ? 'inline-flex' : 'none';
            })
            .catch(() => {});
    }
    setTimeout(() => { pollMoaBadge(); setInterval(pollMoaBadge, 30000); }, 7000);
})();
</script>
<!-- ══════════════════════════════════════════════════════════════════════
     NEW (this adjustment) — COMPANY REQUIREMENTS NOTIFICATION POPUP
     ------------------------------------------------------------------------
     This page already had the "Company Requirements" side-menu indicator;
     now it also shows the same POPUP company_validation.php shows whenever a
     new item lands in its Notification Inbox — for every notification type:
       • New Request           — "has a new MOA request"
       • Revision Complied     — "complied with the flagged MOA revision"
       • Schedule Agreed       — "agreed to the proposed signing schedule"
       • Schedule Proposed     — "proposed a different signing schedule"
       • Requirement Uploaded  — "uploaded new requirement document(s)"
     The list comes straight from company_validation.php's own
     ajax_fetch_moa_notifications endpoint (read-only, nothing is marked as
     viewed), so the popup text, icons and rules are exactly the same as there.
     Only notifications that appear AFTER the page loaded pop up (a baseline is
     taken first, like company_validation.php does). The ids already seen are
     kept in sessionStorage for a short while, so moving from one admin page to
     another neither repeats a popup nor misses one that arrived in between.
     The check pauses while the tab is hidden and catches up when it is shown.
     Self-contained: no existing function, poller or style is changed.
     ══════════════════════════════════════════════════════════════════════ -->
<script>
(function () {
    'use strict';
    if (window._cvCompanyNotifPopupReady) return;
    window._cvCompanyNotifPopupReady = true;

    var CV_NOTIF_ENDPOINT      = 'company_validation.php';
    var CV_NOTIF_POLL_MS       = 5000;    // UPDATED (this adjustment): checked every 5s (was 15s) so the popup / indicator follow right away
    var CV_NOTIF_FIRST_MS      = 0;       // UPDATED (this adjustment): first check right away (was 1.5s)
    var CV_NOTIF_TOAST_MS      = 7000;    // same lifetime as company_validation.php's popup
    var CV_NOTIF_STORE_KEY     = 'cvCompanyNotifKnownIds';
    var CV_NOTIF_STORE_FRESH   = 45000;
    var CV_NOTIF_BADGE_DISPLAY = 'inline-flex';   // same value this page's own badge poller uses

    // Same icons / wording as company_validation.php (MOA_NOTIF_TYPE_META + showMoaNewRequestToast()).
    var CV_NOTIF_ICONS = {
        new_request:          'fa-file-signature',
        revision_complied:    'fa-check-double',
        schedule_agreed:      'fa-calendar-check',
        schedule_declined:    'fa-calendar-day',
        requirement_uploaded: 'fa-file-arrow-up'
    };
    var CV_NOTIF_MESSAGES = {
        new_request:          'has a new MOA request',
        revision_complied:    'complied with the flagged MOA revision',
        schedule_agreed:      'agreed to the proposed signing schedule',
        schedule_declined:    'proposed a different signing schedule',
        requirement_uploaded: 'uploaded new requirement document(s)'
    };

    var knownIds = null;      // Set of notification ids already seen; null until the baseline exists
    var inFlight = false;

    function esc(s) {
        return String(s == null ? '' : s).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;').replace(/'/g, '&#39;');
    }

    function readStore() {
        try {
            var raw = sessionStorage.getItem(CV_NOTIF_STORE_KEY);
            if (!raw) return null;
            var o = JSON.parse(raw);
            if (!o || !Array.isArray(o.ids) || (Date.now() - (o.ts || 0)) > CV_NOTIF_STORE_FRESH) return null;
            return new Set(o.ids.map(Number));
        } catch (e) { return null; }
    }
    function writeStore() {
        if (!knownIds) return;
        try { sessionStorage.setItem(CV_NOTIF_STORE_KEY, JSON.stringify({ ids: Array.from(knownIds), ts: Date.now() })); } catch (e) {}
    }

    // Stacks every .cv-top-toast on the page (newest below), below the undo toast while it is showing.
    // Uses this page's own cvLayoutTopToasts() when it has one, so both kinds of popup share one stack.
    function layoutToasts() {
        if (typeof window.cvLayoutTopToasts === 'function') { window.cvLayoutTopToasts(); return; }
        var top = 30;
        var undo = document.getElementById('undoToast');
        if (undo && undo.classList.contains('show')) top = Math.max(top, undo.getBoundingClientRect().bottom + 12);
        document.querySelectorAll('.cv-top-toast').forEach(function (el) {
            el.style.top = top + 'px';
            top += el.offsetHeight + 12;
        });
    }
    (function () {
        if (typeof window.cvLayoutTopToasts === 'function') return;   // that page already re-lays out on undo changes
        var undo = document.getElementById('undoToast');
        if (undo && window.MutationObserver) new MutationObserver(layoutToasts).observe(undo, { attributes: true, attributeFilter: ['class'] });
    })();

    // The popup itself — same markup as company_validation.php's cvShowTopToast().
    function showNotifPopup(companyName, notifType, r) {   // UPDATED (this adjustment): r = the notification row (for the click-through)
        var type = CV_NOTIF_MESSAGES[notifType] ? notifType : 'new_request';
        var div = document.createElement('div');
        div.className = 'cv-top-toast';
        div.setAttribute('role', 'status');
        div.innerHTML = '<i class="fas ' + esc(CV_NOTIF_ICONS[type]) + '"></i><span><strong>' + esc(companyName || 'A company') + '</strong> ' + esc(CV_NOTIF_MESSAGES[type]) + ' \u2014 check the Notification Inbox.</span>';
        document.body.appendChild(div);
        if (r && window.cvTagToast) window.cvTagToast(div, 'notif:' + r.id + ':' + (r.user_id || 0) + ':' + (r.notif_type || 'new_request'));   // NEW (this adjustment): clickable
        layoutToasts();
        requestAnimationFrame(function () { div.classList.add('show'); });
        setTimeout(function () {
            div.classList.remove('show');
            setTimeout(function () { div.remove(); layoutToasts(); }, 400);
        }, CV_NOTIF_TOAST_MS);
    }

    function setMoaBadge(count) {
        var badge = document.getElementById('sidebarMoaBadge');
        if (!badge) return;
        count = parseInt(count, 10) || 0;
        badge.textContent = count;
        badge.style.display = count > 0 ? CV_NOTIF_BADGE_DISPLAY : 'none';
    }

    function pollCompanyNotifications() {
        if (inFlight || document.hidden) return;
        inFlight = true;
        var fd = new FormData();
        fd.append('ajax_fetch_moa_notifications', '1');
        fetch(CV_NOTIF_ENDPOINT, { method: 'POST', body: fd, credentials: 'same-origin', cache: 'no-store' })
            .then(function (r) { return r.json(); })
            .then(function (data) {
                inFlight = false;
                if (!data || !data.success) return;
                var rows = data.rows || [];
                var ids = new Set(rows.map(function (r) { return Number(r.id); }));
                if (knownIds === null) {
                    // First check on this page: use the ids another admin page saw moments ago, if any;
                    // otherwise everything already waiting is the baseline and nothing pops up.
                    var stored = readStore();
                    // UPDATED (this adjustment): the indicator is brought up to date on every check, not only when something new arrives
                    if (!stored) { knownIds = ids; writeStore(); setMoaBadge(data.pending_count != null ? data.pending_count : rows.length); return; }
                    knownIds = stored;
                }
                var fresh = rows.filter(function (r) { return !knownIds.has(Number(r.id)); });
                knownIds = ids;
                writeStore();
                // UPDATED (this adjustment): always refresh the indicator, so it also goes down / hides when a notification is cleared
                setMoaBadge(data.pending_count != null ? data.pending_count : rows.length);
                if (!fresh.length) return;
                fresh.forEach(function (r) { showNotifPopup(r.company_name, r.notif_type, r); });   // UPDATED (this adjustment): row passed for the click-through
            })
            .catch(function () { inFlight = false; });
    }

    setTimeout(function () {
        pollCompanyNotifications();
        setInterval(pollCompanyNotifications, CV_NOTIF_POLL_MS);
    }, CV_NOTIF_FIRST_MS);
    document.addEventListener('visibilitychange', function () {
        if (!document.hidden && knownIds !== null) pollCompanyNotifications();
    });
    // NEW (this adjustment): also re-check the moment the window regains focus or the page is restored with the Back button
    window.addEventListener('focus', function () { if (knownIds !== null) pollCompanyNotifications(); });
    window.addEventListener('pageshow', function (e) { if (e.persisted) pollCompanyNotifications(); });
    window.addEventListener('pagehide', writeStore);
})();
</script>
<!-- ══════════════════════════════════════════════════════════════════════
     NEW (this adjustment) — APPLICATION REQUEST POPUP NOTIFICATION
     ------------------------------------------------------------------------
     This page already had the "Student Requirements" side-menu indicator;
     now it also shows the same POPUP administrator.php shows whenever a new
     student application request arrives:
       "<Student> submitted a new application request for <Company>
        — check the Application Requests inbox."
     The list comes straight from administrator.php's own read-only
     ?app_request_list=1 endpoint (id, student name, company), so the popup
     text and rules are exactly the same as there. Only requests that appear
     AFTER the page loaded pop up (the requests already waiting are the
     baseline). The ids already seen are kept in sessionStorage for a short
     while, so moving from one admin page to another neither repeats a popup
     nor misses one that arrived in between. The check pauses while the tab is
     hidden and catches up when it is shown. It shares the same top-of-page
     stack (.cv-top-toast) as the Company Requirements popup, so the two never
     overlap. Self-contained: no existing function, poller or style is changed.
     ══════════════════════════════════════════════════════════════════════ -->
<script>
(function () {
    'use strict';
    if (window._cvAppRequestPopupReady) return;
    window._cvAppRequestPopupReady = true;

    var APP_REQ_ENDPOINT     = 'administrator.php?app_request_list=1';
    var APP_REQ_POLL_MS      = 3000;    // UPDATED (this adjustment): checked every 3s (was 15s) so the popup / indicator follow right away
    var APP_REQ_FIRST_MS     = 0;       // UPDATED (this adjustment): first check right away (was 2s)
    var APP_REQ_TOAST_MS     = 7000;    // same lifetime as administrator.php's popup
    var APP_REQ_STORE_KEY    = 'cvAppRequestKnownIds';
    var APP_REQ_STORE_FRESH  = 45000;

    var knownIds = null;      // Set of request ids (strings) already seen; null until the baseline exists
    var inFlight = false;

    function esc(s) {
        return String(s == null ? '' : s).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;').replace(/'/g, '&#39;');
    }

    function readStore() {
        try {
            var raw = sessionStorage.getItem(APP_REQ_STORE_KEY);
            if (!raw) return null;
            var o = JSON.parse(raw);
            if (!o || !Array.isArray(o.ids) || (Date.now() - (o.ts || 0)) > APP_REQ_STORE_FRESH) return null;
            return new Set(o.ids.map(String));
        } catch (e) { return null; }
    }
    function writeStore() {
        if (!knownIds) return;
        try { sessionStorage.setItem(APP_REQ_STORE_KEY, JSON.stringify({ ids: Array.from(knownIds), ts: Date.now() })); } catch (e) {}
    }

    // Stacks every .cv-top-toast on the page (newest below), below the undo toast while it is showing.
    // Uses this page's own cvLayoutTopToasts() when it has one, so every kind of popup shares one stack.
    function layoutToasts() {
        if (typeof window.cvLayoutTopToasts === 'function') { window.cvLayoutTopToasts(); return; }
        var top = 30;
        var undo = document.getElementById('undoToast');
        if (undo && undo.classList.contains('show')) top = Math.max(top, undo.getBoundingClientRect().bottom + 12);
        document.querySelectorAll('.cv-top-toast').forEach(function (el) {
            el.style.top = top + 'px';
            top += el.offsetHeight + 12;
        });
    }

    // The popup itself — same markup, icon and wording as administrator.php's notifyNewAppRequests() / cvShowTopToast().
    function showAppRequestPopup(app) {
        var messageText = 'submitted a new application request' + (app.company_name ? ' for ' + app.company_name : '');
        var div = document.createElement('div');
        div.className = 'cv-top-toast';
        div.setAttribute('role', 'status');
        div.innerHTML = '<i class="fas fa-envelope-open-text"></i><span><strong>' + esc(app.full_name || 'A student') + '</strong> ' + esc(messageText) + ' \u2014 check the Application Requests inbox.</span>';
        document.body.appendChild(div);
        if (window.cvTagToast && app && app.id != null) window.cvTagToast(div, 'app:' + app.id);   // NEW (this adjustment): clickable
        layoutToasts();
        requestAnimationFrame(function () { div.classList.add('show'); });
        setTimeout(function () {
            div.classList.remove('show');
            setTimeout(function () { div.remove(); layoutToasts(); }, 400);
        }, APP_REQ_TOAST_MS);
    }

    function setAppBadge(count) {
        var badge = document.getElementById('sidebarAppBadge');
        if (!badge) return;
        count = parseInt(count, 10) || 0;
        badge.textContent = count;
        badge.style.display = count > 0 ? 'inline-flex' : 'none';   // same value this page's own badge poller uses
    }

    function pollAppRequests() {
        if (inFlight || document.hidden) return;
        inFlight = true;
        fetch(APP_REQ_ENDPOINT, { credentials: 'same-origin', cache: 'no-store' })
            .then(function (r) { return r.json(); })
            .then(function (data) {
                inFlight = false;
                if (!data || !Array.isArray(data.rows)) return;
                var rows = data.rows;
                var ids = new Set(rows.map(function (r) { return String(r.id); }));
                if (knownIds === null) {
                    // First check on this page: use the ids another admin page saw moments ago, if any;
                    // otherwise every request already waiting is the baseline and nothing pops up.
                    var stored = readStore();
                    // UPDATED (this adjustment): the indicator is brought up to date on every check, not only when something new arrives
                    if (!stored) { knownIds = ids; writeStore(); setAppBadge(data.count != null ? data.count : rows.length); return; }
                    knownIds = stored;
                }
                var fresh = rows.filter(function (r) { return !knownIds.has(String(r.id)); });
                knownIds = ids;
                writeStore();
                // UPDATED (this adjustment): always refresh the indicator, so it goes down / hides the moment a student cancels a request
                setAppBadge(data.count != null ? data.count : rows.length);
                if (!fresh.length) return;
                fresh.forEach(showAppRequestPopup);
            })
            .catch(function () { inFlight = false; });
    }

    setTimeout(function () {
        pollAppRequests();
        setInterval(pollAppRequests, APP_REQ_POLL_MS);
    }, APP_REQ_FIRST_MS);
    document.addEventListener('visibilitychange', function () {
        if (!document.hidden && knownIds !== null) pollAppRequests();
    });
    // NEW (this adjustment): also re-check the moment the window regains focus or the page is restored with the Back button
    window.addEventListener('focus', function () { if (knownIds !== null) pollAppRequests(); });
    window.addEventListener('pageshow', function (e) { if (e.persisted) pollAppRequests(); });
    window.addEventListener('pagehide', writeStore);
})();
</script>
<!-- ══════════════════════════════════════════════════════════════════════
     NEW (this adjustment) — SIDE MENU TOOLTIPS WHILE THE MENU IS CLOSED
     ------------------------------------------------------------------------
     When the side menu is collapsed to its icon rail, the link names are
     hidden, so hovering (or keyboard-focusing) an icon now shows its name in
     a tooltip — the same design as the "Apply Students" tooltip on
     admin_monitoring_dashboard.php (#amdFloatTip): a square navy box, small
     bold uppercase white text, soft shadow and an arrow. It sits to the RIGHT
     of the icon, arrow pointing at it, so it never covers the other icons.
     If the link has a notification badge showing, its count is added, e.g.
     "Company Requirements (4)".
     It only appears while the link's own label is actually hidden, so it never
     shows with the menu open (or on phones, where the open menu shows the
     labels). ONE floating element, pointer-events:none, so it never blocks a
     click; it hides on click, scroll, and whenever the menu is opened/closed.
     Self-contained: no existing sidebar markup, style, or script is changed.
     ══════════════════════════════════════════════════════════════════════ -->
<style>
    #cvSideTip { position: fixed; z-index: 3000; background: #1B2A4A; color: #fff; font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif; font-size: 10.5px; font-weight: 600; line-height: 1.3; text-transform: uppercase; letter-spacing: 0.3px; padding: 6px 10px; border-radius: 0; white-space: nowrap; box-shadow: 0 4px 14px rgba(27,42,74,0.30); pointer-events: none; opacity: 0; transform: translateX(-4px); transition: opacity .15s, transform .15s; }
    #cvSideTip.show { opacity: 1; transform: translateX(0); }
    #cvSideTip::after { content: ''; position: absolute; right: 100%; top: 50%; transform: translateY(-50%); border: 6px solid transparent; border-right-color: #1B2A4A; }
</style>
<script>
(function () {
    'use strict';
    var sb = document.getElementById('sidebar');
    if (!sb || document.getElementById('cvSideTip')) return;

    var tip = document.createElement('div');
    tip.id = 'cvSideTip';
    tip.setAttribute('role', 'tooltip');
    document.body.appendChild(tip);
    var current = null;

    // the sidebar link under the pointer / focus, or null
    function linkFrom(el) {
        var a = el && el.closest ? el.closest('a') : null;
        return (a && sb.contains(a)) ? a : null;
    }
    // only while the link's own label is hidden (menu closed)
    function labelOf(a) {
        var lt = a.querySelector('.link-text');
        // UPDATED (this adjustment): the closed menu now hides a name by fading it (visibility:hidden) instead of display:none
        if (!lt) return '';
        var ltStyle = window.getComputedStyle(lt);
        if (ltStyle.display !== 'none' && ltStyle.visibility !== 'hidden') return '';
        var text = (lt.textContent || '').replace(/\s+/g, ' ').trim();
        var badge = a.querySelector('[class*="sidebar-badge"]');
        if (badge && window.getComputedStyle(badge).display !== 'none') {
            var n = (badge.textContent || '').trim();
            if (n && n !== '0') text += ' (' + n + ')';
        }
        return text;
    }
    function show(a) {
        var text = labelOf(a);
        if (!text) { hide(); return; }
        current = a;
        tip.textContent = text;
        var r = a.getBoundingClientRect();
        tip.style.left = '0px'; tip.style.top = '0px';
        var h = tip.offsetHeight;
        tip.style.left = (r.right + 10) + 'px';
        tip.style.top = Math.min(Math.max(8, r.top + r.height / 2 - h / 2), window.innerHeight - h - 8) + 'px';
        tip.classList.add('show');
    }
    function hide() { current = null; tip.classList.remove('show'); }

    document.addEventListener('mouseover', function (e) { var a = linkFrom(e.target); if (a) show(a); });
    document.addEventListener('mouseout', function (e) { var a = linkFrom(e.target); if (a && !a.contains(e.relatedTarget)) hide(); });
    document.addEventListener('focusin', function (e) { var a = linkFrom(e.target); if (a) show(a); else hide(); });
    document.addEventListener('focusout', hide);
    document.addEventListener('click', hide, true);
    window.addEventListener('scroll', hide, true);
    window.addEventListener('resize', hide);
    // opening / closing the menu (existing toggle button) hides it right away
    if (window.MutationObserver) new MutationObserver(hide).observe(sb, { attributes: true, attributeFilter: ['class'] });
})();
</script>
<!-- NEW (this adjustment) — SMOOTHER SIDE MENU OPEN / CLOSE (see the matching styles in <head>).
     Watches the side menu's existing .collapsed class (the toggle button's own code is unchanged) and,
     only while it is moving, marks it .cv-sb-moving (clips its contents) and, when opening,
     .cv-sb-opening (fades the link names in). Both are removed as soon as the move is over. -->
<script>
(function () {
    'use strict';
    var sb = document.getElementById('sidebar');
    if (!sb || !window.MutationObserver) return;
    var wasCollapsed = sb.classList.contains('collapsed');
    var timer = null;
    new MutationObserver(function () {
        var isCollapsed = sb.classList.contains('collapsed');
        if (isCollapsed === wasCollapsed) return;       // some other class changed — nothing to animate
        wasCollapsed = isCollapsed;
        clearTimeout(timer);
        sb.classList.remove('cv-sb-opening');
        void sb.offsetWidth;                            // restart the label fade if toggled quickly
        sb.classList.add('cv-sb-moving');
        if (!isCollapsed) sb.classList.add('cv-sb-opening');
        timer = setTimeout(function () { sb.classList.remove('cv-sb-moving', 'cv-sb-opening'); }, 420);
    }).observe(sb, { attributes: true, attributeFilter: ['class'] });
})();
</script>
<!-- NEW (this adjustment): LOGOUT + BACK BUTTON. If the browser shows this page from its back / forward
     memory (for example after the admin has logged out and pressed Back), reload it from the server instead,
     so a logged-out visitor is sent to admin_login.php and has to sign in again. The loading page covers the
     old view while it reloads. -->
<script>
window.addEventListener('pageshow', function (e) {
    if (!e.persisted) return;
    var ov = document.getElementById('globalLoadingOverlay');
    if (ov) ov.classList.remove('hidden');
    window.location.reload();
});
</script>
<!-- ══════════════════════════════════════════════════════════════════════
     NEW (this adjustment) — EMAIL RECOVERY REQUEST POPUP + SIDE-MENU INDICATOR
     ------------------------------------------------------------------------
     Same popup style as administrator.php's application-request notification
     (the navy .cv-top-toast bar at the top of the page):
       "<Name> submitted a new email recovery request (Student account)
        — check Email Recovery Requests in Manage Accounts."
     and the same kind of live side-menu badge, on the "Manage Accounts" link.
     The list comes from monitoring.php?recovery_request_list=1 (read-only:
     Pending requests only — nothing is accepted, rejected or changed).
     • Only requests that arrive AFTER the page loaded pop up (the ones already
       waiting are the baseline); ids already seen are kept briefly in
       sessionStorage so moving between admin pages neither repeats a popup
       nor misses one that arrived in between.
     • The badge is refreshed on every check, so it also goes down / hides as
       soon as a request is accepted or rejected.
     • On monitoring.php the existing Email Recovery badges on the floating
       button (#recoveryFabBadge / #fabMainBadge) are kept in step too, through
       the page's own updateFabBadge().
     • Checked right away, then every 5 s; pauses while the tab is hidden and
       catches up when it is shown again or the window regains focus.
     Self-contained: no existing function, poller or style is changed.
     ══════════════════════════════════════════════════════════════════════ -->
<script>
(function () {
    'use strict';
    if (window._cvRecoveryPopupReady) return;
    window._cvRecoveryPopupReady = true;

    var RC_ENDPOINT    = 'monitoring.php?recovery_request_list=1';
    var RC_POLL_MS     = 5000;
    var RC_TOAST_MS    = 7000;    // same lifetime as the application-request popup
    var RC_STORE_KEY   = 'cvRecoveryKnownIds';
    var RC_STORE_FRESH = 45000;

    var knownIds = null, inFlight = false;

    function esc(s) {
        return String(s == null ? '' : s).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;').replace(/'/g, '&#39;');
    }
    function readStore() {
        try {
            var o = JSON.parse(sessionStorage.getItem(RC_STORE_KEY) || 'null');
            if (!o || !Array.isArray(o.ids) || (Date.now() - (o.ts || 0)) > RC_STORE_FRESH) return null;
            return new Set(o.ids.map(String));
        } catch (e) { return null; }
    }
    function writeStore() {
        if (!knownIds) return;
        try { sessionStorage.setItem(RC_STORE_KEY, JSON.stringify({ ids: Array.from(knownIds), ts: Date.now() })); } catch (e) {}
    }

    // shares one top-of-page stack with every other .cv-top-toast popup
    function layoutToasts() {
        if (typeof window.cvLayoutTopToasts === 'function') { window.cvLayoutTopToasts(); return; }
        var top = 30;
        var undo = document.getElementById('undoToast');
        if (undo && undo.classList.contains('show')) top = Math.max(top, undo.getBoundingClientRect().bottom + 12);
        document.querySelectorAll('.cv-top-toast').forEach(function (el) { el.style.top = top + 'px'; top += el.offsetHeight + 12; });
    }

    // the popup — same markup / style as the application-request popup
    function showRecoveryPopup(r) {
        var msg = 'submitted a new email recovery request' + (r.account_type ? ' (' + r.account_type + ' account)' : '');
        var div = document.createElement('div');
        div.className = 'cv-top-toast';
        div.setAttribute('role', 'status');
        div.innerHTML = '<i class="fas fa-envelope"></i><span><strong>' + esc(r.full_name || 'Someone') + '</strong> ' + esc(msg) + ' \u2014 check Email Recovery Requests in Manage Accounts.</span>';
        document.body.appendChild(div);
        if (window.cvTagToast && r && r.id != null) window.cvTagToast(div, 'recovery:' + r.id);   // NEW (this adjustment): clickable
        layoutToasts();
        requestAnimationFrame(function () { div.classList.add('show'); });
        setTimeout(function () {
            div.classList.remove('show');
            setTimeout(function () { div.remove(); layoutToasts(); }, 400);
        }, RC_TOAST_MS);
    }

    function setIndicators(count) {
        count = parseInt(count, 10) || 0;
        var badge = document.getElementById('sidebarRecoveryBadge');
        if (badge) { badge.textContent = count; badge.style.display = count > 0 ? '' : 'none'; }
        // monitoring.php only: keep its floating-button recovery badges in step (its own function)
        if (typeof window.updateFabBadge === 'function' && typeof window._fabCount === 'number' && window._fabCount !== count) {
            window.updateFabBadge(count - window._fabCount);
        }
    }

    function poll() {
        if (inFlight || document.hidden) return;
        inFlight = true;
        fetch(RC_ENDPOINT, { credentials: 'same-origin', cache: 'no-store' })
            .then(function (r) { return r.json(); })
            .then(function (data) {
                inFlight = false;
                if (!data || !Array.isArray(data.rows)) return;
                var rows = data.rows, ids = new Set(rows.map(function (r) { return String(r.id); }));
                setIndicators(data.count != null ? data.count : rows.length);
                // NEW (this adjustment): monitoring.php only — put fresh requests into the drawer's Pending list
                if (typeof window.cvSyncRecoveryCards === 'function') window.cvSyncRecoveryCards(rows);
                if (knownIds === null) {
                    var stored = readStore();
                    if (!stored) { knownIds = ids; writeStore(); return; }   // baseline: nothing pops up
                    knownIds = stored;
                }
                var fresh = rows.filter(function (r) { return !knownIds.has(String(r.id)); });
                knownIds = ids;
                writeStore();
                fresh.forEach(showRecoveryPopup);
            })
            .catch(function () { inFlight = false; });
    }

    poll();
    setInterval(poll, RC_POLL_MS);
    document.addEventListener('visibilitychange', function () { if (!document.hidden && knownIds !== null) poll(); });
    window.addEventListener('focus', function () { if (knownIds !== null) poll(); });
    window.addEventListener('pageshow', function (e) { if (e.persisted) poll(); });
    window.addEventListener('pagehide', writeStore);
})();
</script>
<!-- ══════════════════════════════════════════════════════════════════════
     NEW (this adjustment) — LOGOUT CONFIRMATION POPUP + "LOGGING OUT" LOADING PAGE
     ------------------------------------------------------------------------
     Clicking the sidebar "Logout" button no longer logs out straight away:
       • A confirmation popup ("Log Out") asks "Are you sure you want to Log out?
         You need to login again to access your Account." with Cancel / Log out.
         Cancel, the Esc key or a click outside the box closes it and nothing
         happens (the admin stays signed in, on the same page).
       • "Log out" shows this page's own loading page with the label
         "Logging out" and then goes to admin_login.php?logout=1 (unchanged).
     Ctrl / Cmd / middle-click on Logout are caught too, so a background tab
     can never log the admin out without confirming.
     The click is caught before any other click handler (capture phase), so the
     page's usual "Loading" overlay for link clicks does not appear behind the
     popup; while leaving, the label is kept as "Logging out".
     Self-contained: the Logout link, its href and every other behaviour are
     unchanged (without JavaScript the link still logs out as before).
     ══════════════════════════════════════════════════════════════════════ -->
<script>
(function () {
    'use strict';
    if (window._cvLogoutConfirmReady) return;
    window._cvLogoutConfirmReady = true;

    var LOGOUT_SELECTOR = '.logout-link a[href*="logout=1"]';
    var pendingHref = null, loggingOut = false, lastFocus = null, stuckTimer = null;

    var overlay = document.createElement('div');
    overlay.className = 'cv-logout-overlay';
    overlay.id = 'cvLogoutConfirm';
    overlay.setAttribute('role', 'dialog');
    overlay.setAttribute('aria-modal', 'true');
    overlay.setAttribute('aria-labelledby', 'cvLogoutTitle');
    overlay.setAttribute('aria-describedby', 'cvLogoutMsg');
    overlay.innerHTML =
        '<div class="cv-logout-box">' +
            '<h3 id="cvLogoutTitle"><i class="fas fa-sign-out-alt"></i> Log Out</h3>' +
            '<p id="cvLogoutMsg">Are you sure you want to Log out? You need to login again to access your Account.</p>' +
            '<div class="cv-logout-actions">' +
                '<button type="button" class="cv-logout-btn ghost" data-cv-logout="cancel">Cancel</button>' +
                '<button type="button" class="cv-logout-btn" data-cv-logout="ok"><i class="fas fa-sign-out-alt"></i> Log out</button>' +
            '</div>' +
        '</div>';
    document.body.appendChild(overlay);
    var btnCancel = overlay.querySelector('[data-cv-logout="cancel"]');
    var btnOk     = overlay.querySelector('[data-cv-logout="ok"]');

    function isOpen() { return overlay.classList.contains('show'); }
    function openConfirm(href) {
        pendingHref = href;
        lastFocus = document.activeElement;
        overlay.classList.add('show');
        setTimeout(function () { btnCancel.focus(); }, 30);
    }
    function closeConfirm() {
        overlay.classList.remove('show');
        pendingHref = null;
        if (lastFocus && lastFocus.focus) { try { lastFocus.focus(); } catch (e) {} }
    }

    // this page's own loading page, labelled "Logging out"
    function showLoggingOut() {
        var ov = document.getElementById('globalLoadingOverlay');
        var label = document.getElementById('globalLoadingLabel');
        if (label) label.textContent = 'Logging out';
        if (ov) ov.classList.remove('hidden');
    }
    function hideLoggingOut() {
        var ov = document.getElementById('globalLoadingOverlay');
        var label = document.getElementById('globalLoadingLabel');
        if (ov) ov.classList.add('hidden');
        if (label) label.textContent = 'Loading';
    }

    function confirmLogout() {
        if (!pendingHref) return;
        var href = pendingHref;
        overlay.classList.remove('show');
        pendingHref = null;
        loggingOut = true;
        showLoggingOut();
        // safety: if the browser never leaves (e.g. the server cannot be reached), give the page back
        clearTimeout(stuckTimer);
        stuckTimer = setTimeout(function () { if (loggingOut) { loggingOut = false; hideLoggingOut(); } }, 15000);
        setTimeout(function () { window.location.href = href; }, 60);   // lets "Logging out" paint first
    }

    // Catch the Logout click before any other click handler (capture phase)
    function intercept(e) {
        var a = e.target && e.target.closest ? e.target.closest(LOGOUT_SELECTOR) : null;
        if (!a) return;
        if (e.type === 'auxclick' && e.button !== 1) return;
        e.preventDefault();
        if (loggingOut) return;
        openConfirm(a.href);
    }
    document.addEventListener('click', intercept, true);
    document.addEventListener('auxclick', intercept, true);

    btnCancel.addEventListener('click', closeConfirm);
    btnOk.addEventListener('click', confirmLogout);
    overlay.addEventListener('click', function (e) { if (e.target === overlay) closeConfirm(); });
    document.addEventListener('keydown', function (e) {
        if (!isOpen()) return;
        if (e.key === 'Escape') { e.preventDefault(); closeConfirm(); }
        else if (e.key === 'Tab') {        // keep keyboard focus inside the popup
            var first = btnCancel, last = btnOk;
            if (e.shiftKey && document.activeElement === first) { e.preventDefault(); last.focus(); }
            else if (!e.shiftKey && document.activeElement === last) { e.preventDefault(); first.focus(); }
        }
    });

    // While leaving, keep the label "Logging out" (some pages reset it to "Loading" on unload)
    window.addEventListener('beforeunload', function () { if (loggingOut) showLoggingOut(); });
    // Back / Forward restore of this page: start clean
    window.addEventListener('pageshow', function (e) {
        if (!e.persisted) return;
        loggingOut = false; clearTimeout(stuckTimer);
        overlay.classList.remove('show'); pendingHref = null;
    });
})();
</script>
<!-- ══════════════════════════════════════════════════════════════════════
     NEW (this adjustment) — LIVE ACTIVITY LOG TOOLS
     • Search box: shows only entries whose text matches (name, action, performer, details, date).
     • Action dropdown: "All Actions" or one action type; the list is built from the entries shown
       and grows by itself as new kinds of actions appear.
     • Clear Log: asks for confirmation first (Cancel / Clear Log), then removes every entry on the
       server; one "Activity Log Cleared" entry records who cleared it.
     • Live: actions done on the other admin pages (or by another admin) are added at the top every
       few seconds, one entry at a time (no redraw, no blinking). Entries this page already added
       itself are never duplicated.
     • FIX (this adjustment — duplicate entries): this page's own actions show their entry at once
       with PHP's clock (date()), while the saved row gets MySQL's clock (NOW()); on XAMPP the two
       are usually in different time zones, so the live check did not recognise the entry and added
       the saved row a second time. Entries are now matched by what they say (action, account,
       details) — never by time — and each saved row is linked to exactly ONE entry: an entry this
       page added itself is linked to its saved row (and its time corrected to the saved time,
       the same one a reload shows) instead of being added again. A genuinely repeated action
       still gets its own entry.
     Uses this page's own prependLogEntry(), formatLogDate(), escapeHtml() and showNotif() unchanged.
     ══════════════════════════════════════════════════════════════════════ -->
<script>
(function () {
    'use strict';
    var container = document.getElementById('historyContainer');
    var toolbar   = document.getElementById('alogToolbar');
    if (!container || !toolbar || window._cvAlogToolsReady) return;
    window._cvAlogToolsReady = true;

    var search = document.getElementById('alogSearch'), actionSel = document.getElementById('alogAction');
    var clearBtn = document.getElementById('alogClearBtn'), noMatch = document.getElementById('alogNoMatch');
    var lastIdAttr = toolbar.getAttribute('data-last-id');
    var lastId = lastIdAttr === '' ? null : parseInt(lastIdAttr, 10);

    function entries() { return Array.prototype.slice.call(container.querySelectorAll('.log-entry')); }
    function actionOf(entry) { var b = entry.querySelector('.badge'); return b ? b.textContent.replace(/\s+/g, ' ').trim() : ''; }

    // ── action dropdown: rebuilt from the entries present, keeping the current choice ──
    function refreshActions() {
        var current = actionSel.value, seen = {}, list = [];
        entries().forEach(function (e) { var a = actionOf(e); if (a && !seen[a.toLowerCase()]) { seen[a.toLowerCase()] = 1; list.push(a); } });
        list.sort(function (a, b) { return a.localeCompare(b); });
        if (current && !seen[current.toLowerCase()]) list.push(current);   // keep a chosen filter even if its entries are gone
        var html = '<option value="">All Actions</option>';
        list.forEach(function (a) { html += '<option value="' + escapeHtml(a) + '">' + escapeHtml(a) + '</option>'; });
        actionSel.innerHTML = html;
        actionSel.value = current;
    }

    // ── search + action filter ──
    function applyFilters() {
        var q = search.value.replace(/\s+/g, ' ').trim().toLowerCase(), act = actionSel.value.toLowerCase(), shown = 0, all = entries();
        all.forEach(function (e) {
            var ok = (!act || actionOf(e).toLowerCase() === act) && (!q || e.textContent.replace(/\s+/g, ' ').toLowerCase().indexOf(q) !== -1);
            e.style.display = ok ? '' : 'none';
            if (ok) shown++;
        });
        noMatch.style.display = (all.length && !shown) ? '' : 'none';
    }
    search.addEventListener('input', applyFilters);
    actionSel.addEventListener('change', applyFilters);

    // any entry added / removed (by this page's own actions, the live check or Clear Log) → refresh the tools
    var busy = false;
    new MutationObserver(function () { if (busy) return; busy = true; refreshActions(); applyFilters(); busy = false; })
        .observe(container, { childList: true });
    refreshActions(); applyFilters();

    // FIX (duplicates): entries rendered with the page are already saved rows — mark them as known
    entries().forEach(function (e) { e.setAttribute('data-alog-id', 'loaded'); });

    // ── Clear Log with confirmation (same popup look as the Logout confirmation) ──
    var ov = document.createElement('div');
    ov.className = 'cv-logout-overlay';
    ov.setAttribute('role', 'dialog'); ov.setAttribute('aria-modal', 'true'); ov.setAttribute('aria-labelledby', 'alogClearTitle');
    ov.innerHTML = '<div class="cv-logout-box">' +
        '<h3 id="alogClearTitle"><i class="fas fa-trash-alt"></i> Clear Activity Log</h3>' +
        '<p id="alogClearMsg">Are you sure you want to clear the activity log? All entries will be permanently removed. This cannot be undone.</p>' +
        '<div class="cv-logout-actions">' +
            '<button type="button" class="cv-logout-btn ghost" data-alog="cancel">Cancel</button>' +
            '<button type="button" class="cv-logout-btn cv-alog-danger" data-alog="ok"><i class="fas fa-trash-alt"></i> Clear Log</button>' +
        '</div></div>';
    document.body.appendChild(ov);
    var okBtn = ov.querySelector('[data-alog="ok"]'), cancelBtn = ov.querySelector('[data-alog="cancel"]'), lastFocus = null;
    function openConfirm() {
        var n = entries().length;
        document.getElementById('alogClearMsg').textContent = 'Are you sure you want to clear the activity log? ' +
            (n ? 'All ' + n + ' ' + (n === 1 ? 'entry' : 'entries') + ' will be permanently removed.' : 'All entries will be permanently removed.') + ' This cannot be undone.';
        lastFocus = document.activeElement; ov.classList.add('show'); setTimeout(function () { cancelBtn.focus(); }, 30);
    }
    function closeConfirm() { ov.classList.remove('show'); if (lastFocus && lastFocus.focus) { try { lastFocus.focus(); } catch (e) {} } }
    clearBtn.addEventListener('click', openConfirm);
    cancelBtn.addEventListener('click', closeConfirm);
    ov.addEventListener('click', function (e) { if (e.target === ov) closeConfirm(); });
    document.addEventListener('keydown', function (e) {
        if (!ov.classList.contains('show')) return;
        if (e.key === 'Escape') { e.preventDefault(); closeConfirm(); }
        else if (e.key === 'Tab') {
            if (e.shiftKey && document.activeElement === cancelBtn) { e.preventDefault(); okBtn.focus(); }
            else if (!e.shiftKey && document.activeElement === okBtn) { e.preventDefault(); cancelBtn.focus(); }
        }
    });
    okBtn.addEventListener('click', function () {
        closeConfirm();
        clearBtn.disabled = true;
        var fd = new FormData(); fd.append('ajax_clear_activity_log', '1');
        fetch('monitoring.php', { method: 'POST', body: fd, credentials: 'same-origin' })
            .then(function (r) { return r.json(); })
            .then(function (d) {
                clearBtn.disabled = false;
                if (!d || !d.success) { if (typeof showNotif === 'function') showNotif('error', 'Could Not Clear', (d && d.message) || 'The activity log could not be cleared. Please try again.'); return; }
                container.innerHTML = '';
                if (d.last_id) lastId = d.last_id;
                if (d.log) {
                    prependLogEntry(d.log);
                    var first = container.querySelector('.log-entry');   // FIX (duplicates): linked to its saved row at once
                    if (first) first.setAttribute('data-alog-id', String(d.last_id || 'cleared'));
                }
                if (typeof showNotif === 'function') showNotif('success', 'Activity Log Cleared', d.message || 'The activity log has been cleared.');
            })
            .catch(function () { clearBtn.disabled = false; if (typeof showNotif === 'function') showNotif('error', 'Could Not Clear', 'The activity log could not be cleared. Please check your connection and try again.'); });
    });

    // ── live: add entries logged elsewhere, without redrawing and without duplicates ──
    // FIX (duplicates): matched by what the entry says, never by its time (see the header comment)
    function norm(t) { return String(t == null ? '' : t).replace(/\s+/g, ' ').trim().toLowerCase(); }
    function textOf(el) { return el ? el.textContent : ''; }
    function domKey(e) {
        var left = e.querySelector('.log-entry-left'), divs = left ? left.querySelectorAll(':scope > div') : [];
        return [norm(textOf(e.querySelector('.badge'))), norm(textOf(e.querySelector('strong'))), divs.length ? norm(textOf(divs[divs.length - 1])) : ''].join('|');
    }
    function rowKey(r) {
        return [norm(r.action_type), norm(String(r.account_type || '') + ': ' + String(r.account_name || '')), norm('Details: ' + String(r.details || ''))].join('|');
    }
    var inFlight = false;
    function poll() {
        if (lastId === null || inFlight || document.hidden) return;
        inFlight = true;
        fetch('monitoring.php?activity_log_since=' + encodeURIComponent(lastId), { credentials: 'same-origin', cache: 'no-store' })
            .then(function (r) { return r.json(); })
            .then(function (d) {
                inFlight = false;
                if (!d || !d.success) { if (d && d.success === false) lastId = null; return; }   // no id column → live check off
                (d.rows || []).forEach(function (r) {
                    if (r.id > lastId) lastId = r.id;
                    if (container.querySelector('.log-entry[data-alog-id="' + r.id + '"]')) return;   // already shown
                    var key = rowKey(r);
                    // an entry this page added itself (not linked to a saved row yet) that says the same thing → link it
                    var own = entries().filter(function (e) { return !e.hasAttribute('data-alog-id') && domKey(e) === key; });
                    if (own.length) {
                        var match = own[own.length - 1];                          // the oldest unlinked one first
                        match.setAttribute('data-alog-id', String(r.id));
                        var right = match.querySelector('.log-entry-right');
                        if (right && r.created_at) right.textContent = formatLogDate(r.created_at);   // the saved time (as after a reload)
                        return;
                    }
                    prependLogEntry(r);
                    var first = container.querySelector('.log-entry');
                    if (first) first.setAttribute('data-alog-id', String(r.id));
                });
            })
            .catch(function () { inFlight = false; });
    }
    if (lastId !== null) {
        setInterval(poll, 5000);
        document.addEventListener('visibilitychange', function () { if (!document.hidden) poll(); });
    }
})();
</script>
<!-- ══════════════════════════════════════════════════════════════════════
     NEW (this adjustment) — BUTTON TOOLTIPS (every button on this page)
     Same design as the "Apply Students" tooltip on admin_monitoring_dashboard.php, shown ABOVE the
     button (or below it when there is no room above) with its arrow pointing at the button.
     Text: data-cv-tip → aria-label → title → the button's own text → for icon-only buttons, a
     name taken from its icon (trash → Delete, pen → Edit, × → Close, ...).
     • Buttons that already have their own tooltip (the dashboard's "Apply Students" .amd-tip and
       admin_reports.php's [data-tooltip] icon buttons) are left exactly as they are.
     • A button's own title="" is kept, but the browser's plain grey title bubble is held back while
       this tooltip shows, so two tooltips never appear together.
     • ONE floating element, pointer-events:none (never blocks a click); hides on click, scroll,
       resize and Esc. Keyboard users get it when they Tab to a button.
     Self-contained: no existing button, handler or style is changed.
     ══════════════════════════════════════════════════════════════════════ -->
<script>
(function () {
    'use strict';
    if (window._cvBtnTipReady) return;
    window._cvBtnTipReady = true;

    var SEL = 'button, input[type="button"], input[type="submit"], input[type="reset"], [role="button"], a[class*="btn"], a[class*="button"]';
    var ICONS = [   // more specific names first (e.g. "book-open" before "pen")
        ['book-open', 'Open'], ['eye-slash', 'Hide'], ['trash', 'Delete'], ['pen', 'Edit'], ['pencil', 'Edit'], ['edit', 'Edit'],
        ['xmark', 'Close'], ['fa-times', 'Close'], ['fa-close', 'Close'], ['fa-eye', 'View'], ['file-export', 'Export'], ['file-import', 'Import'],
        ['download', 'Download'], ['upload', 'Upload'], ['print', 'Print'], ['user-plus', 'Add'], ['fa-plus', 'Add'], ['magnifying-glass', 'Search'],
        ['search', 'Search'], ['filter', 'Filter'], ['sync', 'Refresh'], ['rotate', 'Refresh'], ['redo', 'Refresh'], ['undo', 'Undo'],
        ['box-archive', 'Archive'], ['archive', 'Archive'], ['paper-plane', 'Send'], ['fa-check', 'Confirm'], ['arrow-left', 'Back'],
        ['chevron-left', 'Previous'], ['chevron-right', 'Next'], ['angle-left', 'Previous'], ['angle-right', 'Next'], ['copy', 'Copy'],
        ['comment', 'Comment'], ['calendar', 'Pick a date'], ['bell', 'Notifications'], ['sign-out', 'Logout'], ['right-from-bracket', 'Logout'], ['bars', 'Menu']
    ];

    var tip = document.createElement('div');
    tip.id = 'cvBtnTip';
    tip.setAttribute('role', 'tooltip');
    document.body.appendChild(tip);
    var current = null;

    function eligible(el) {
        var b = el && el.closest ? el.closest(SEL) : null;
        if (!b || b === tip) return null;
        if (b.closest('.amd-tip') || b.closest('[data-tooltip]')) return null;   // already has its own tooltip
        return b;
    }
    function iconName(b) {
        var i = b.querySelector('i[class*="fa-"], svg[class*="fa-"]');
        var cls = i ? (i.getAttribute('class') || '') : '';
        for (var k = 0; k < ICONS.length; k++) { if (cls.indexOf(ICONS[k][0]) !== -1) return ICONS[k][1]; }
        return '';
    }
    /* ─────────────────────────────────────────────────────────────────────
       UPDATED (this adjustment): tooltips now say, in a few simple words,
       WHAT the button does — instead of repeating the button's own name.
       Order: a description written for that exact button → the button's own
       title / label when it says more than its name → the description for
       that kind of button (this page first, then the general list) → the
       old behaviour as a last resort. Counters are understood, e.g.
       "Delete (2)" / "Export (Excluding 3)".
       ───────────────────────────────────────────────────────────────────── */
    var CV_TIP_GENERAL = {
        'cancel': 'Close this without saving',
        'close': 'Close this window',
        'dismiss': 'Hide this message',
        'ok': 'Close this message',
        'ok, got it': 'Close this message',
        'done': 'Close this summary',
        'save': 'Save your changes',
        'save changes': 'Save your changes',
        'save all': 'Save every course you edited',
        'yes, delete': 'Delete for good — this cannot be undone',
        'delete': 'Remove this item',
        'remove': 'Remove this item',
        'confirm': 'Yes, go ahead',
        'continue': 'Go on to the next step',
        'back': 'Go back to the previous step',
        'next': 'Go to the next page',
        'prev': 'Go to the previous page',
        'previous': 'Go to the previous page',
        'next page': 'Go to the next page',
        'previous page': 'Go to the previous page',
        'next month': 'Show the next month',
        'previous month': 'Show the previous month',
        'export excel': 'Download this list as an Excel file',
        'export to excel': 'Download this list as an Excel file',
        'export filtered': 'Download only the rows that match your filters',
        'export': 'Download this list as a file',
        'export (excluding n)': 'Download the list without the entries you ticked',
        'none, proceed with export': 'Export everyone in the list',
        'archive batch': 'Move a finished batch to the archive',
        'unarchive batch': 'Bring an archived batch back',
        'unarchive': 'Bring this batch back to the active list',
        'yes, unarchive': 'Bring the batch back to the active list',
        'view archived batches': 'See the batches you archived',
        'view archived company batches': 'See the company batches you archived',
        'undo': 'Reverse your last action',
        'refresh': 'Load the latest list',
        'print': 'Print this page',
        'save as pdf': 'Download this as a PDF file',
        'select all': 'Tick every item in this list',
        'clear': 'Untick every item in this list',
        'import selected': 'Import only the groups you ticked',
        'cancel import': 'Stop — nothing from the file is added',
        'skip these students': 'Import the rest and leave these students out',
        'no, skip these students': 'Import the rest and leave these students out',
        'no, skip this student': 'Leave this student out',
        'skip this student': 'Leave this student out',
        'yes, add course': 'Add this course to Course Offering first',
        'add course offering': 'Save this course to Course Offering',
        'edit': 'Choose one entry, then change it',
        'edit selected': 'Open the entry you picked for editing',
        'edit (n)': 'Edit the entries you picked',
        'delete (n)': 'Delete the entries you picked',
        'view': 'Open the full details',
        'full view': 'Open the full application',
        'details': 'Show the full details',
        'company details': "Show the company's details",
        'view requirements': "See this company's requirements",
        'view pdf': 'Open the document',
        'view pdf (locked)': 'Open the flagged document (read only)',
        'send': 'Send your message',
        'open chat': 'Chat with this company',
        'preview letter': 'See the letter before sending it',
        'apply & send endorsement letter': 'Assign the students and email the letter',
        'approve moa': "Approve this company's MOA",
        'reject': 'Reject it and say what to fix',
        'accept': 'Accept this request',
        'send & request revision': 'Ask the company to fix the flagged items',
        'set signing schedule': 'Pick the MOA signing date and time',
        're-schedule': 'Change the MOA signing date',
        'accept proposed schedule': "Agree to the company's proposed date",
        'review moa': 'Check the MOA the company sent',
        'moa workflow': "Track each company's MOA progress",
        'notification inbox': 'See new company notifications',
        'requirements': "See the companies' requirements",
        'requirements /': "See the companies' requirements",
        'allow': "Approve this student's application",
        'allow application': "Approve this student's application",
        'deny': "Decline this student's application",
        'deny application': "Decline this student's application",
        'approve & send letter': 'Approve and email the endorsement letter',
        'application requests': 'See new student application requests',
        'update id': "Save the student's new ID number",
        'add student': 'Add a student',
        'remove student': 'Take this student off the list',
        'confirm & create admin': 'Create the new admin account',
        'verify credentials': 'Check the details before creating the account',
        'verify otp': 'Confirm the code sent by email',
        'clear history': 'Remove all finished recovery requests',
        'clear log': 'Remove every activity log entry',
        'confirm accept': 'Approve this email change',
        'confirm reject': 'Decline this email change',
        'accept request': 'Approve this email change request',
        'reject request': 'Decline this email change request',
        'history': 'Show requests already handled',
        'pending': 'Show requests waiting for you',
        'quick actions': 'Open shortcuts for common tasks',
        'attendance': 'Show the attendance records',
        'reports': 'Show the weekly reports',
        'comment': 'Leave feedback on this report',
        'edit comment': 'Change your feedback',
        'save comment': 'Save your feedback',
        'upload': 'Let the student see this grade',
        'unupload': 'Hide this grade from the student',
        'upload selected': 'Show the ticked grades to students',
        'unupload selected': 'Hide the ticked grades from students',
        'backup now': 'Make a copy of the database now',
        'add company manually': 'Open the form to add one company',
        'add company': 'Save this new company',
        'import companies': 'Add many companies from an Excel file',
        'import students': 'Add many students from an Excel file',
        'log out': 'Sign out of your account',
        'logout': 'Sign out of your account',
        'notifications': 'See new notifications',
        'search': 'Search the list',
        'filter': 'Narrow down the list',
        'menu': 'Open the menu'
    };
    var CV_TIP_PAGE = {
        'admin_student_list.php': {
            'delete': 'Choose students to remove', 'yes': 'Choose students to leave out of the export', 'done': 'Close this summary'
        },
        'admin_company_list.php': {
            'delete': 'Choose companies to remove', 'yes': 'Choose companies to leave out of the export', 'done': 'Close this summary'
        },
        'course_offering.php': {
            'delete': 'Choose courses to remove', 'next course': 'Go to the next course', 'previous course': 'Go to the previous course'
        },
        'company_validation.php': { 'done': 'Mark this MOA as completed', 'back': 'Go back to the list' },
        'admin_final_grades.php': { 'export': 'Download the grades as a file' },
        'system_setting.php': { 'delete': 'Delete this backup', 'refresh': 'Load the latest backup list' },
        'monitoring.php': { 'delete': 'Delete this account for good' }
    };
    var CV_TIP_EXACT = [   // [CSS selector, description] — for buttons whose words mean different things on the same page
        ['#toggleBtn', null],
        ['#addStudentBtn', 'Open the form to add one student'],
        ['#addStudentForm button[type="submit"]', 'Save this new student'],
        ['#addCourseOfferingBtn', 'Open the form to add a course'],
        ['#importAddCourseSubmitBtn', 'Save this course, then continue'],
        ['#courseOfferingSubmitBtn', 'Save this course'],
        ['#addCompanyBtn', 'Open the form to add one company'],
        ['#alogClearBtn', 'Remove every activity log entry'],
        ['#studentImportFilterCloseBtn', 'Stop — nothing from the file is added'],
        ['#importClassificationCloseBtn', 'Stop — nothing from the file is added'],
        ['#exportChoiceCloseBtn', 'Cancel the export'],
        ['#globalResultOkBtn', 'Close this message']
    ];
    var CV_TIP_PAGE_NAME = (window.location.pathname.split('/').pop() || '').toLowerCase();
    function cvTipKey(t) {
        return String(t || '').replace(/\s+/g, ' ').trim().toLowerCase()
            .replace(/^[\u2190\u2192\u2039\u203a\u00ab\u00bb\u00d7\u2715<>\s]+|[\u2190\u2192\u2039\u203a\u00ab\u00bb\u00d7\u2715<>\s]+$/g, '')
            .replace(/\d+/g, 'n');
    }
    function cvTipDescribe(key) {
        var page = CV_TIP_PAGE[CV_TIP_PAGE_NAME] || {};
        // try the name as it is, then without a trailing counter ("Pending 3", "Requirements 2 / 5")
        var keys = [key, String(key).replace(/(\s+n(\s*\/\s*n)?)+$/, '').replace(/\s*\/\s*$/, '').trim()];
        for (var k = 0; k < keys.length; k++) {
            if (Object.prototype.hasOwnProperty.call(page, keys[k])) return page[keys[k]];
            if (Object.prototype.hasOwnProperty.call(CV_TIP_GENERAL, keys[k])) return CV_TIP_GENERAL[keys[k]];
        }
        return '';
    }
    function labelOf(b) {
        if (b.id === 'toggleBtn') {
            var sb = document.getElementById('sidebar');
            return sb && sb.classList.contains('collapsed') ? 'Show the full menu' : 'Make the menu smaller';
        }
        for (var i = 0; i < CV_TIP_EXACT.length; i++) {
            try { if (CV_TIP_EXACT[i][1] && b.matches(CV_TIP_EXACT[i][0])) return CV_TIP_EXACT[i][1]; } catch (x) {}
        }
        var visible = (b.tagName === 'INPUT' ? (b.value || '') : (b.textContent || '')).replace(/\s+/g, ' ').trim();
        if (/^[\u00D7\u2715xX]$/.test(visible)) visible = 'Close';
        var own = (b.getAttribute('data-cv-tip') || b.getAttribute('aria-label') || b.getAttribute('data-cv-title') || b.getAttribute('title') || '').replace(/\s+/g, ' ').trim();
        // the button's own title / label, when it says more than the button's name (more than one word)
        if (own && own.indexOf(' ') !== -1 && cvTipKey(own) !== cvTipKey(visible) && own.length <= 160 && !cvTipDescribe(cvTipKey(own))) return own;
        var d = cvTipDescribe(cvTipKey(visible)) || cvTipDescribe(cvTipKey(own));
        if (!d && (!visible || /^[^A-Za-z0-9]+$/.test(visible))) d = cvTipDescribe(cvTipKey(iconName(b)));
        if (d) return d;
        // last resort — the old behaviour
        var t = own || visible;
        if (!t || /^[^A-Za-z0-9]+$/.test(t)) t = iconName(b);
        return t.length > 60 ? '' : t;     // long text (e.g. whole cards acting as buttons) gets no tooltip
    }
    function holdTitle(b) { var t = b.getAttribute('title'); if (t) { b.setAttribute('data-cv-title', t); b.removeAttribute('title'); } }
    function releaseTitle(b) { var t = b && b.getAttribute('data-cv-title'); if (t !== null && t !== undefined && b) { if (!b.hasAttribute('title')) b.setAttribute('title', t); b.removeAttribute('data-cv-title'); } }

    function show(b) {
        var text = labelOf(b);
        if (!text) { hide(); return; }
        if (current && current !== b) releaseTitle(current);
        current = b; holdTitle(b);
        tip.textContent = text;
        var r = b.getBoundingClientRect();
        if (!r.width && !r.height) { hide(); return; }
        tip.style.left = '0px'; tip.style.top = '0px';
        var w = tip.offsetWidth, h = tip.offsetHeight;
        var left = Math.min(Math.max(8, r.left + r.width / 2 - w / 2), window.innerWidth - w - 8);
        var below = r.top - h - 10 < 8;
        tip.classList.toggle('below', below);
        tip.style.left = left + 'px';
        tip.style.top = (below ? r.bottom + 10 : r.top - h - 10) + 'px';
        tip.style.setProperty('--arrow-x', (r.left + r.width / 2 - left) + 'px');   // arrow stays on the button even when nudged sideways
        tip.classList.add('show');
    }
    function hide() { tip.classList.remove('show'); if (current) releaseTitle(current); current = null; }

    document.addEventListener('mouseover', function (e) { var b = eligible(e.target); if (b) { if (b !== current) show(b); } });
    // after a scroll (which hides the tooltip) bring it back as soon as the pointer moves over the button again
    document.addEventListener('mousemove', function (e) { if (tip.classList.contains('show')) return; var b = eligible(e.target); if (b) show(b); }, { passive: true });
    document.addEventListener('mouseout', function (e) { var b = eligible(e.target); if (b && b === current && !b.contains(e.relatedTarget)) hide(); });
    document.addEventListener('focusin', function (e) {
        var b = eligible(e.target);
        var kb = false; try { kb = e.target.matches(':focus-visible'); } catch (x) { kb = false; }
        if (b && kb) show(b); else if (!b) hide();
    });
    document.addEventListener('focusout', function () { hide(); });
    document.addEventListener('click', hide, true);
    document.addEventListener('keydown', function (e) { if (e.key === 'Escape') hide(); });
    window.addEventListener('scroll', hide, true);
    window.addEventListener('resize', hide);
})();
</script>
<!-- ══════════════════════════════════════════════════════════════════════
     NEW (this adjustment) — CLICKABLE NOTIFICATION POPUPS
     ------------------------------------------------------------------------
     Every notification popup (the navy bar at the top of the page) can now be
     clicked — or reached with Tab and opened with Enter — and takes the admin
     straight to what it is about:
       • Company Requirements notification (new MOA request, revision complied,
         schedule agreed / proposed, requirement uploaded)
           → company_validation.php, running that page's own "View" action for
             the notification (the company's row / MOA review, or the
             Requirements tab for an upload) — exactly like its inbox button.
       • Application request → administrator.php: the Application Requests
         inbox opens and then that student's request (Full View).
       • Email recovery request → monitoring.php: the Email Recovery Requests
         drawer opens on Pending and scrolls to / highlights that request.
     On the page that owns the subject it happens right there; from any other
     page it goes to that page (with the loading page) and happens after load.
     The one-time link parameter is removed from the address bar first, so a
     refresh never repeats it. If the subject was already handled meanwhile,
     the inbox / drawer is simply opened. A small "View ›" marks the popups.
     Self-contained: no existing popup text, timing, poller or style changes.
     ══════════════════════════════════════════════════════════════════════ -->
<script>
(function () {
    'use strict';
    if (window._cvToastGoReady) return;
    window._cvToastGoReady = true;

    // ── marks a popup as clickable; spec = "notif:<id>:<companyUserId>:<type>" | "app:<id>" | "recovery:<id>"
    window.cvTagToast = function (el, spec) {
        if (!el || !spec || el.hasAttribute('data-cv-go')) return;
        el.setAttribute('data-cv-go', spec);
        el.setAttribute('role', 'link');
        el.setAttribute('tabindex', '0');
        el.setAttribute('aria-label', (el.textContent || '').replace(/\s+/g, ' ').trim() + ' — open');
        var hint = document.createElement('span');
        hint.className = 'cv-toast-go';
        hint.setAttribute('aria-hidden', 'true');
        hint.innerHTML = 'View <i class="fas fa-chevron-right"></i>';
        el.appendChild(hint);
    };

    function g(name) { try { return window[name] || (0, eval)('typeof ' + name + ' !== "undefined" ? ' + name + ' : undefined'); } catch (e) { return undefined; } }
    function waitFor(test, ms) {
        return new Promise(function (resolve) {
            var t0 = Date.now();
            (function tick() {
                var v = null; try { v = test(); } catch (e) { v = null; }
                if (v) return resolve(v);
                if (Date.now() - t0 > ms) return resolve(null);
                setTimeout(tick, 150);
            })();
        });
    }
    function highlight(el) {
        if (!el) return;
        try { el.scrollIntoView({ behavior: 'smooth', block: 'center' }); } catch (e) { el.scrollIntoView(); }
        el.classList.remove('cv-go-highlight'); void el.offsetWidth; el.classList.add('cv-go-highlight');
        setTimeout(function () { el.classList.remove('cv-go-highlight'); }, 2800);
    }
    function goTo(url) {
        var ov = document.getElementById('globalLoadingOverlay'), label = document.getElementById('globalLoadingLabel');
        if (label) label.textContent = 'Loading';
        if (ov) ov.classList.remove('hidden');
        window.location.href = url;
    }

    // ── Company Requirements notification → company_validation.php's own "View" action ──
    function openNotif(id, uid, type) {
        var view = g('viewMoaNotification');
        if (typeof view !== 'function') {
            goTo('company_validation.php?open_notif=' + encodeURIComponent(id) + '&uid=' + encodeURIComponent(uid) + '&type=' + encodeURIComponent(type || 'new_request'));
            return;
        }
        var numId = parseInt(id, 10), numUid = parseInt(uid, 10);
        var list = function () { var l = g('_allMoaNotifications'); return Array.isArray(l) ? l : null; };
        var has = function () { var l = list(); return l && l.some(function (r) { return r.id === numId; }); };
        var run = function () {
            // already handled / not in the list any more: still go to the company, as the right kind of notification
            var l = list();
            if (l && !has()) l.push({ id: numId, user_id: numUid, notif_type: type || 'new_request', detail: [] });
            view(numId, numUid);
        };
        if (has()) { run(); return; }
        var load = g('loadMoaNotifications');
        if (typeof load === 'function') { try { load(); } catch (e) {} }
        waitFor(has, 4000).then(run);
    }

    // ── Application request → administrator.php: inbox, then that request's Full View ──
    function openApp(id) {
        var openInbox = g('openAppInbox'), fullView = g('openAppFullView');
        if (typeof openInbox !== 'function' || typeof fullView !== 'function') {
            goTo('administrator.php?open_app_request=' + encodeURIComponent(id));
            return;
        }
        var ov = document.getElementById('appRequestOverlay');
        if (!ov || ov.style.display !== 'flex') openInbox();
        waitFor(function () { var a = g('_fvAllApps'); return Array.isArray(a) && a.some(function (x) { return String(x.id) === String(id); }); }, 6000)
            .then(function (found) {
                if (found) { fullView(id); return; }
                // no longer pending (approved / denied / withdrawn meanwhile): the inbox stays open
            });
    }

    // ── Email recovery request → monitoring.php: drawer on Pending, that request highlighted ──
    function openRecovery(id) {
        var openDrawer = g('openRecoveryDrawer');
        if (typeof openDrawer !== 'function') { goTo('monitoring.php?open_recovery=' + encodeURIComponent(id)); return; }
        openDrawer();
        var sw = g('switchRecoveryTab'); if (typeof sw === 'function') sw('pending');
        var card = function () { return document.getElementById('reqCard' + id); };
        if (!card()) {
            var sync = g('cvSyncRecoveryCards');
            if (typeof sync === 'function') {
                fetch('monitoring.php?recovery_request_list=1', { credentials: 'same-origin', cache: 'no-store' })
                    .then(function (r) { return r.json(); }).then(function (d) { if (d && Array.isArray(d.rows)) sync(d.rows); }).catch(function () {});
            }
        }
        waitFor(card, 6000).then(function (c) {
            if (!c) return;
            var h = document.getElementById('reqHistoryItems');
            if (h && h.contains(c) && typeof sw === 'function') sw('history');   // already accepted / rejected
            setTimeout(function () { highlight(c); }, 250);
        });
    }

    // NEW (this adjustment): new requirement submission → administrator.php opens that student's requirements
    function openStudentUpload(id, uid) {
        var view = g('cvViewStudentUpload');
        if (typeof view === 'function') { view(parseInt(id, 10), parseInt(uid, 10)); return; }
        goTo('administrator.php?open_student_upload=' + encodeURIComponent(id) + '&uid=' + encodeURIComponent(uid));
    }

    function go(spec) {
        var p = String(spec || '').split(':');
        if (p[0] === 'studentupload') { openStudentUpload(p[1], p[2]); return; }
        if (p[0] === 'notif') openNotif(p[1], p[2], p[3]);
        else if (p[0] === 'app') openApp(p[1]);
        else if (p[0] === 'recovery') openRecovery(p[1]);
    }

    function activate(toast) {
        var spec = toast.getAttribute('data-cv-go');
        toast.classList.remove('show');
        setTimeout(function () { if (toast.parentNode) toast.parentNode.removeChild(toast); var lay = g('cvLayoutTopToasts'); if (typeof lay === 'function') lay(); }, 350);
        go(spec);
    }
    document.addEventListener('click', function (e) {
        var t = e.target && e.target.closest ? e.target.closest('.cv-top-toast[data-cv-go]') : null;
        if (t) { e.preventDefault(); activate(t); }
    });
    document.addEventListener('keydown', function (e) {
        if (e.key !== 'Enter' && e.key !== ' ') return;
        var t = e.target && e.target.closest ? e.target.closest('.cv-top-toast[data-cv-go]') : null;
        if (t) { e.preventDefault(); activate(t); }
    });

    // ── arriving from a popup on another page: do it here once the page has loaded ──
    var params = new URLSearchParams(window.location.search), spec = null;
    if (params.get('open_notif')) spec = 'notif:' + params.get('open_notif') + ':' + (params.get('uid') || 0) + ':' + (params.get('type') || 'new_request');
    else if (params.get('open_app_request')) spec = 'app:' + params.get('open_app_request');
    else if (params.get('open_recovery')) spec = 'recovery:' + params.get('open_recovery');
    else if (params.get('open_student_upload')) spec = 'studentupload:' + params.get('open_student_upload') + ':' + (params.get('uid') || 0);   // NEW (this adjustment)
    if (spec) {
        ['open_notif', 'uid', 'type', 'open_app_request', 'open_recovery', 'open_student_upload'].forEach(function (k) { params.delete(k); });
        if (window.history.replaceState) {
            var q = params.toString();
            window.history.replaceState({}, document.title, window.location.pathname + (q ? '?' + q : '') + window.location.hash);
        }
        var start = function () { setTimeout(function () { go(spec); }, 600); };
        if (document.readyState === 'complete') start(); else window.addEventListener('load', start);
    }
})();
</script>
<!-- ══════════════════════════════════════════════════════════════════════
     NEW (this adjustment) — NEW REQUIREMENT SUBMISSION POPUP + INDICATOR
     Same design and behaviour as the other popups on this page (and as
     company_validation.php's "uploaded new requirement document(s)" popup):
     checked right away and then every 4 s; submissions that were already
     waiting when the page opened do not pop up; each one pops up once (a
     further file merged into it pops up again, listing everything); clicking
     it opens that student's requirements (administrator.php). The Student Validation indicator is
     kept in step with the Inbox total (application requests + submissions).
     Ported from administrator.php: the submissions list, the detection and the
     indicator count all come from administrator.php?student_upload_list=1, so
     every admin page shows the same popups once, whichever page is open.
     ══════════════════════════════════════════════════════════════════════ -->
<script>
(function () {
    'use strict';
    if (window._cvStudentUploadPopupReady) return;
    window._cvStudentUploadPopupReady = true;
    var SRU_ENDPOINT    = 'administrator.php?student_upload_list=1';
    var SRU_POLL_MS     = 4000;
    var SRU_TOAST_MS    = 7000;
    var SRU_STORE_KEY   = 'cvStudentUploadKnown';
    var SRU_STORE_FRESH = 45000;
    var known = null, inFlight = false;
    function esc(s) { return String(s == null ? '' : s).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;').replace(/'/g, '&#39;'); }
    function sigOf(r) { return String(r.id) + '@' + String(r.sig || ''); }
    function readStore() {
        try {
            var o = JSON.parse(sessionStorage.getItem(SRU_STORE_KEY) || 'null');
            if (!o || !Array.isArray(o.ids) || (Date.now() - (o.ts || 0)) > SRU_STORE_FRESH) return null;
            return new Set(o.ids.map(String));
        } catch (e) { return null; }
    }
    function writeStore() { if (!known) return; try { sessionStorage.setItem(SRU_STORE_KEY, JSON.stringify({ ids: Array.from(known), ts: Date.now() })); } catch (e) {} }
    function layoutToasts() {
        if (typeof window.cvLayoutTopToasts === 'function') { window.cvLayoutTopToasts(); return; }
        var top = 30, undo = document.getElementById('undoToast');
        if (undo && undo.classList.contains('show')) top = Math.max(top, undo.getBoundingClientRect().bottom + 12);
        document.querySelectorAll('.cv-top-toast').forEach(function (el) { el.style.top = top + 'px'; top += el.offsetHeight + 12; });
    }
    function showPopup(r) {
        var labels = (r.items || []).map(function (i) { return i.label || i.key || ''; }).filter(Boolean);
        var what = labels.length === 1 ? 'a new requirement: ' + labels[0] : (labels.length + ' new requirements: ' + labels.join(', '));
        var div = document.createElement('div');
        div.className = 'cv-top-toast';
        div.setAttribute('role', 'status');
        div.innerHTML = '<i class="fas fa-file-arrow-up"></i><span><strong>' + esc(r.full_name || 'A student') + '</strong> submitted ' + esc(what) + ' \u2014 check the Application Requests inbox.</span>';
        document.body.appendChild(div);
        if (window.cvTagToast) window.cvTagToast(div, 'studentupload:' + r.id + ':' + r.user_id);   // clickable
        layoutToasts();
        requestAnimationFrame(function () { div.classList.add('show'); });
        setTimeout(function () { div.classList.remove('show'); setTimeout(function () { div.remove(); layoutToasts(); }, 400); }, SRU_TOAST_MS);
    }
    function setBadge(count) {
        var badge = document.getElementById('sidebarAppBadge');
        if (!badge) return;
        count = parseInt(count, 10) || 0;
        badge.textContent = count;
        badge.style.display = count > 0 ? 'inline-flex' : 'none';
    }
    function poll() {
        if (inFlight || document.hidden) return;
        inFlight = true;
        fetch(SRU_ENDPOINT, { credentials: 'same-origin', cache: 'no-store' })
            .then(function (r) { return r.json(); })
            .then(function (d) {
                inFlight = false;
                if (!d || !d.success || !Array.isArray(d.rows)) return;
                var now = new Set(d.rows.map(sigOf));
                setBadge(d.count);
                if (typeof window.cvRenderStudentUploads === 'function') window.cvRenderStudentUploads(d.rows);   // administrator.php's Inbox, if open
                if (known === null) { known = readStore() || now; if (known === now) { writeStore(); return; } }
                var fresh = d.rows.filter(function (r) { return !known.has(sigOf(r)); });
                known = now; writeStore();
                fresh.forEach(showPopup);
            })
            .catch(function () { inFlight = false; });
    }
    setTimeout(function () { poll(); setInterval(poll, SRU_POLL_MS); }, 0);
    document.addEventListener('visibilitychange', function () { if (!document.hidden && known !== null) poll(); });
    window.addEventListener('focus', function () { if (known !== null) poll(); });
    window.addEventListener('pageshow', function (e) { if (e.persisted) poll(); });
    window.addEventListener('pagehide', writeStore);
})();
</script>
</body>
</html>