<?php
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
        $rpRes = $conn->query("SELECT COUNT(*) AS total FROM email_recovery_requests WHERE status = 'Pending'");
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

cv_alog_capture($conn, [
    [function () { return cv_alog_is_get_post('save_comment'); }, function ($conn) {
        $rid = (int)($_POST['report_id'] ?? 0);
        $n = cv_alog_user_name($conn, (int)cv_alog_scalar($conn, "SELECT user_id FROM reports WHERE id = ?", 'i', [$rid]));
        $wk = (string)cv_alog_scalar($conn, "SELECT week_start FROM reports WHERE id = ?", 'i', [$rid]);
        return ['Report Comment Saved', 'Student', $n, "Saved a comment on $n's weekly report" . ($wk !== '' ? " (week of " . date('M d, Y', strtotime($wk ?? '')) . ")" : '') . " via Reports"];
    }],
]);
// ── NEW (this adjustment): full name of the logged-in admin for the sidebar header (with the
// "Administrator" label underneath), same as every other admin page. Looked up in the `admins`
// table by $_SESSION['admin_id'] (the key admin_login.php sets), then 'user_id'; falls back to
// the session name, then to "Administrator". Read-only and fully guarded.
$adminFullName = '';
try {
    $adminLookupId = (int)($_SESSION['admin_id'] ?? $_SESSION['user_id'] ?? 0);
    if ($adminLookupId > 0) {
        $adminNameStmt = $conn->prepare("SELECT first_name, middle_name, last_name FROM admins WHERE id = ?");
        if ($adminNameStmt) {
            $adminNameStmt->bind_param("i", $adminLookupId);
            $adminNameStmt->execute();
            $adminNameRow = $adminNameStmt->get_result()->fetch_assoc();
            $adminNameStmt->close();
            if ($adminNameRow) {
                $adminFullName = preg_replace('/\s+/', ' ', trim(
                    ($adminNameRow['first_name'] ?? '') . ' ' .
                    ($adminNameRow['middle_name'] ?? '') . ' ' .
                    ($adminNameRow['last_name'] ?? '')
                ));
            }
        }
    }
} catch (\Throwable $e) { $adminFullName = ''; }
if ($adminFullName === '') $adminFullName = trim(($_SESSION['first_name'] ?? '') . ' ' . ($_SESSION['last_name'] ?? ''));
if ($adminFullName === '') $adminFullName = 'Administrator';

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

/* ── FIX (sidebar notification indicator — adopted from
   admin_student_list.php / course_offering.php): this page's "Company Requirements" link
   had no notification badge at all. It now shows the same count
   company_validation.php does. Placed BEFORE the company_id check below
   so the badge endpoint works without a company_id in the URL.
   company_validation.php's Notification Inbox lists (a) un-viewed,
   transferred MOA notifications (moa_requests.admin_viewed=0 AND
   transferred=1) PLUS (b) un-viewed requirement-upload notifications
   (company_requirement_upload_notifications.admin_viewed=0).
   This helper uses exactly that same rule, so the number shown here is
   always the same number shown on company_validation.php. Every lookup
   is guarded — a missing column/table simply counts as 0. Read-only:
   no DDL, no writes. ── */
if (!function_exists('admin_reports_company_validation_notif_count')) {
    function admin_reports_company_validation_notif_count($conn) {
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
$moa_pending_count = admin_reports_company_validation_notif_count($conn); // FIX (sidebar notification indicator)

/* ── NEW (sidebar notification indicator): lightweight JSON endpoint the
   sidebar polls so the "Company Requirements" badge stays in step with
   company_validation.php without a page reload. Placed before any HTML
   output; read-only. ── */
if (isset($_GET['cv_sidebar_notif_count']) && $_GET['cv_sidebar_notif_count'] === '1') {
    header('Content-Type: application/json');
    echo json_encode(['count' => $moa_pending_count]);
    exit;
}

$admin_id   = $_SESSION['user_id'];
$company_id = (int)($_GET['company_id'] ?? 0);
if (!$company_id) die("Invalid company.");

// ── Company info ──
$ci_stmt = $conn->prepare("
    SELECT ci.company, u.email
    FROM company_information ci
    JOIN users u ON u.id = ci.user_id
    WHERE ci.user_id = ?
");
$ci_stmt->bind_param("i", $company_id);
$ci_stmt->execute();
$ci_row = $ci_stmt->get_result()->fetch_assoc();
$company_name = $ci_row['company'] ?? 'Company';

require 'vendor/autoload.php';

/* ── OJT start date for this company (mirrors company_reports.php) ──
   NEW: used by computeWeeklyReportStats() below to power the Total /
   Submitted / Not Submitted summary cards in the Student Library. */
$ojt_start_date = '';
$stmt_ojt_start = $conn->prepare("SELECT MIN(date) as start_date FROM attendance_settings WHERE company_id = ?");
$stmt_ojt_start->bind_param("i", $company_id);
$stmt_ojt_start->execute();
$ojt_start_row = $stmt_ojt_start->get_result()->fetch_assoc();
$ojt_start_date = $ojt_start_row['start_date'] ?? '';
$stmt_ojt_start->close();

/* ============================================================
   BLOB TYPE HELPERS
   ------------------------------------------------------------
   NEW: mirrors company_reports.php — a submitted weekly report's
   `report_blob` can now be either an xlsx spreadsheet or an HTML
   document, so both need to be detected and rendered correctly on
   the admin side too (previously this file only ever assumed xlsx).
   ============================================================ */
function blobIsHTML(string $blob): bool {
    if (strlen($blob) >= 2 && substr($blob, 0, 2) === 'PK') return false;
    $head = ltrim(substr($blob, 0, 100));
    return (stripos($head, '<!doctype') === 0 || stripos($head, '<html') === 0);
}
function blobIsPDF(string $blob): bool {
    return substr($blob, 0, 4) === '%PDF';
}

/* ============================================================
   NEW: WEEKLY REPORT COMPLIANCE COUNTS (Total / Submitted / Missed)
   ------------------------------------------------------------
   Mirrors company_reports.php's computeWeeklyReportStats() exactly —
   used by the student_library=1 handler below to power the three
   summary cards now shown at the top of the Student Library
   ("Total", "Submitted", "Not Submitted").

   Definitions:
     - Total     = number of weekly reports that SHOULD have been
                   submitted so far, counting one report per week
                   from the start of OJT (the company's earliest
                   attendance_settings date, $ojt_start_date fetched
                   near the top of this file) through today's date,
                   inclusive of the current week.
     - Submitted = number of reports the student has actually
                   submitted (the same set already returned in the
                   'reports' array below, i.e. excluding rows marked
                   remark = 'Wrong Document').
     - Missed    = Total - Submitted, floored at 0 so a student who
                   has submitted extra/duplicate reports for the same
                   week never shows a negative "missed" count.
   ============================================================ */
function computeWeeklyReportStats(string $ojt_start_date, int $submitted_count): array {
    $stats = [
        'total_expected' => 0,
        'submitted'      => $submitted_count,
        'missed'         => 0,
    ];

    if (empty($ojt_start_date)) {
        return $stats;
    }

    $start_ts = strtotime($ojt_start_date ?? '');
    $today_ts = strtotime(date('Y-m-d'));
    if ($start_ts === false || $today_ts < $start_ts) {
        return $stats;
    }

    // Normalize to the Monday of the OJT start date's own week.
    $start_dow    = (int)date('N', $start_ts); // 1 (Mon) .. 7 (Sun)
    $week_start_ts = strtotime('-' . ($start_dow - 1) . ' days', $start_ts);

    $days_elapsed = (int)floor(($today_ts - $week_start_ts) / 86400);
    $total_expected = (int)floor($days_elapsed / 7) + 1;

    $stats['total_expected'] = max(0, $total_expected);
    $stats['missed']         = max(0, $stats['total_expected'] - $submitted_count);

    return $stats;
}

/* ── DOWNLOAD ──
   MODIFIED: Filename now uses Company Name + Student Last Name + Week Range,
   and now correctly serves either an .xlsx or an .html file depending on
   the stored blob's actual type (previously always assumed .xlsx).
*/
if (isset($_GET['download']) && $_GET['download'] == '1') {
    $report_id = (int)($_GET['id'] ?? 0);
    $dl = $conn->prepare("
        SELECT r.report_blob, r.week_start, u.first_name, u.last_name
        FROM reports r
        JOIN users u ON u.id = r.user_id
        WHERE r.id=? AND r.company_id=?
    ");
    $dl->bind_param("ii", $report_id, $company_id);
    $dl->execute();
    $dlrow = $dl->get_result()->fetch_assoc();
    if (!$dlrow || empty($dlrow['report_blob'])) die("File not found.");

    $student_last_name = $dlrow['last_name'] ?? 'Student';
    $clean_company_name = preg_replace('/[^A-Za-z0-9_\-]/', '_', $company_name);
    $week_start_date = strtotime($dlrow['week_start'] ?? '');
    $week_end_date = strtotime('+4 days', $week_start_date);
    $week_range = date("M d", $week_start_date) . ' – ' . date("M d, Y", $week_end_date);

    $blob    = $dlrow['report_blob'];
    $is_html = blobIsHTML($blob);
    $ext     = $is_html ? '.html' : '.xlsx';
    $filename = $clean_company_name . "_" . $student_last_name . "_" . $week_range . $ext;
    $filename = preg_replace('/[\/\\\\:*?"<>|]/', '', $filename);

    header($is_html
        ? "Content-Type: text/html; charset=utf-8"
        : "Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet");
    header("Content-Disposition: attachment; filename=\"{$filename}\"");
    header("Content-Length: " . strlen($blob));
    header("Cache-Control: max-age=0");
    echo $blob;
    exit;
}

/* ── VIEW ──
   MODIFIED: now branches on blob type — HTML reports are shown via an
   inline iframe (pointed at the new viewraw=1 endpoint below), xlsx
   reports are still rendered into an HTML table via PhpSpreadsheet,
   exactly as before. */
if (isset($_GET['view']) && $_GET['view'] == '1') {
    $report_id = (int)($_GET['id'] ?? 0);
    $dl = $conn->prepare("
        SELECT r.report_blob, r.week_start, u.first_name, u.last_name
        FROM reports r
        JOIN users u ON u.id = r.user_id
        WHERE r.id=? AND r.company_id=?
    ");
    $dl->bind_param("ii", $report_id, $company_id);
    $dl->execute();
    $dlrow = $dl->get_result()->fetch_assoc();
    if (!$dlrow || empty($dlrow['report_blob'])) {
        header('Content-Type: application/json');
        echo json_encode(['error' => 'File not found.']);
        exit;
    }

    $blob         = $dlrow['report_blob'];
    $student_name = $dlrow['first_name'] . ' ' . $dlrow['last_name'];
    $week_label   = date("M d, Y", strtotime($dlrow['week_start'] ?? ''));

    if (blobIsHTML($blob)) {
        $iframe_src = 'admin_reports.php?company_id=' . $company_id . '&viewraw=1&id=' . $report_id;
        header('Content-Type: application/json');
        echo json_encode([
            'html'    => '<iframe class="lib-report-preview-frame" src="' . htmlspecialchars($iframe_src ?? '') . '" title="Weekly Report"></iframe>',
            'week'    => $week_label,
            'student' => $student_name,
            'is_html' => true,
        ]);
        exit;
    }

    $tmpfile = tempnam(sys_get_temp_dir(), 'ojt_') . '.xlsx';
    file_put_contents($tmpfile, $blob);
    $reader = \PhpOffice\PhpSpreadsheet\IOFactory::createReader('Xlsx');
    $reader->setReadDataOnly(true);
    $spreadsheet = $reader->load($tmpfile);
    unlink($tmpfile);
    $sheet = $spreadsheet->getActiveSheet();
    $highRow = $sheet->getHighestRow();
    $highCol = $sheet->getHighestColumn();
    $html = '<table class="xl-table">';
    for ($r = 1; $r <= $highRow; $r++) {
        $html .= '<tr>';
        for ($c = 'A'; $c <= $highCol; $c++) {
            $cell = $sheet->getCell($c . $r);
            $val  = nl2br(htmlspecialchars((string)$cell->getFormattedValue()));
            $colspan = 1;
            foreach ($sheet->getMergeCells() as $merge) {
                [$tl, $br] = explode(':', $merge);
                $tlCol = preg_replace('/\d/', '', $tl); $tlRow = (int)preg_replace('/\D/', '', $tl);
                $brCol = preg_replace('/\d/', '', $br); $brRow = (int)preg_replace('/\D/', '', $br);
                if ($tlCol === $c && $tlRow === $r) {
                    $span = 0; for ($mc = $tlCol; $mc <= $brCol; $mc++) $span++;
                    $colspan = $span; break;
                }
                if ($c >= $tlCol && $c <= $brCol && $r >= $tlRow && $r <= $brRow && !($c === $tlCol && $r === $tlRow)) {
                    $val = null; break;
                }
            }
            if ($val === null) continue;
            $html .= "<td colspan=\"{$colspan}\">{$val}</td>";
        }
        $html .= '</tr>';
    }
    $html .= '</table>';
    header('Content-Type: application/json');
    echo json_encode(['html' => $html, 'week' => $week_label, 'student' => $student_name, 'is_html' => false]);
    exit;
}

/* ── VIEWRAW ──
   NEW: serves an HTML report blob raw, so it can be embedded inline
   in an iframe by the "view" JSON response above — mirrors
   company_reports.php's viewraw=1 endpoint. */
if (isset($_GET['viewraw']) && $_GET['viewraw'] == '1') {
    $report_id = (int)($_GET['id'] ?? 0);
    $dl = $conn->prepare("SELECT report_blob FROM reports WHERE id=? AND company_id=?");
    $dl->bind_param("ii", $report_id, $company_id);
    $dl->execute();
    $dlrow = $dl->get_result()->fetch_assoc();
    if (!$dlrow || empty($dlrow['report_blob'])) {
        http_response_code(404);
        echo '<p style="font-family:sans-serif;color:#ef4444;padding:20px;">Report not found.</p>';
        exit;
    }
    header('Content-Type: text/html; charset=utf-8');
    echo $dlrow['report_blob'];
    exit;
}

/* ── STUDENT LIBRARY ──
   Powers the Library overlay: returns EVERY report a given student has
   submitted to this company (across all weeks), plus whether the
   company has already submitted a Performance Evaluation for this
   student (so the "View Eval Form" button in the library toolbar knows
   whether to enable itself).
   MODIFIED: faculty_grade / company_grade are no longer selected —
   admin grading of weekly reports has been removed entirely.
   NEW: also returns a 'report_stats' payload (Total / Submitted /
   Missed) computed via computeWeeklyReportStats(), mirroring
   company_reports.php, so the Student Library can show the same
   three summary cards. */
if (isset($_GET['student_library']) && $_GET['student_library'] == '1') {
    $student_id = (int)($_GET['student_id'] ?? 0);

    $chk = $conn->prepare("SELECT student_id, eval_submitted_at FROM ojt_assignments WHERE student_id=? AND company_id=? LIMIT 1");
    $chk->bind_param("ii", $student_id, $company_id);
    $chk->execute();
    $chkrow = $chk->get_result()->fetch_assoc();
    if (!$chkrow) {
        header('Content-Type: application/json');
        echo json_encode(['error' => 'Access denied.']);
        exit;
    }
    $chk->close();

    $si = $conn->prepare("SELECT first_name, last_name, course FROM users WHERE id=? LIMIT 1");
    $si->bind_param("i", $student_id);
    $si->execute();
    $student_info = $si->get_result()->fetch_assoc();
    $si->close();

    $rq = $conn->prepare("
        SELECT
            r.id            AS report_id,
            r.week_start,
            r.submitted_at,
            r.remark,
            r.feedback,
            (r.report_blob IS NOT NULL) AS has_blob
        FROM reports r
        WHERE r.user_id = ? AND r.company_id = ?
        ORDER BY r.week_start DESC
    ");
    $rq->bind_param("ii", $student_id, $company_id);
    $rq->execute();
    $all_reports = $rq->get_result()->fetch_all(MYSQLI_ASSOC);
    $rq->close();

    /* NEW: weekly report compliance counters — Total (expected, one per
       week from OJT start to today), Submitted (count of $all_reports
       above, excluding Wrong Document rows), and Missed (Total -
       Submitted, floored at 0). Powers the three summary cards at the
       top of the Student Library. */
    $submitted_count_for_stats = count(array_filter($all_reports, function ($r) {
        return ($r['remark'] ?? '') !== 'Wrong Document';
    }));
    $report_stats = computeWeeklyReportStats($ojt_start_date, $submitted_count_for_stats);

    header('Content-Type: application/json');
    echo json_encode([
        'student'           => $student_info,
        'student_id'        => $student_id,
        'reports'           => $all_reports,
        'has_eval'          => !empty($chkrow['eval_submitted_at']),
        'eval_submitted_at' => $chkrow['eval_submitted_at'],
        'report_stats'      => $report_stats,
    ]);
    exit;
}

/* ── PRINT EVAL (READ-ONLY VIEW) ──
   NEW: lets the admin PREVIEW a student's Performance/Training-Plan
   evaluation once the company has submitted and locked it. Admin
   never creates or edits an evaluation — this endpoint only ever
   serves the already-saved eval_pdf_blob (the same "carbon copy" the
   company itself sees/prints/saves via company_reports.php), or a
   friendly placeholder message if no evaluation has been submitted
   yet. */
if (isset($_GET['print_eval']) && $_GET['print_eval'] == '1') {
    $student_id = (int)($_GET['student_id'] ?? 0);

    $chk = $conn->prepare("SELECT student_id, eval_submitted_at FROM ojt_assignments WHERE student_id=? AND company_id=? LIMIT 1");
    $chk->bind_param("ii", $student_id, $company_id);
    $chk->execute();
    $chkrow = $chk->get_result()->fetch_assoc();
    $chk->close();

    if (!$chkrow) {
        http_response_code(403);
        echo '<p style="font-family:sans-serif;color:#ef4444;padding:40px;text-align:center;">Access denied.</p>';
        exit;
    }
    if (empty($chkrow['eval_submitted_at'])) {
        http_response_code(404);
        echo '<div style="font-family:sans-serif;color:#9ca3af;padding:60px 30px;text-align:center;">'
          
           . '<div>This student has not been evaluated by the company yet.</div></div>';
        exit;
    }

    $col_check = $conn->query("SHOW COLUMNS FROM ojt_assignments LIKE 'eval_pdf_blob'");
    $has_blob_col = $col_check && $col_check->num_rows > 0;

    $blob_row = null;
    if ($has_blob_col) {
        $blob_stmt = $conn->prepare("SELECT eval_pdf_blob FROM ojt_assignments WHERE student_id=? AND company_id=? LIMIT 1");
        $blob_stmt->bind_param("ii", $student_id, $company_id);
        $blob_stmt->execute();
        $blob_row = $blob_stmt->get_result()->fetch_assoc();
        $blob_stmt->close();
    }

    if (!$blob_row || empty($blob_row['eval_pdf_blob'])) {
        echo '<div style="font-family:sans-serif;color:#9ca3af;padding:60px 30px;text-align:center;">'
          
           . '<div>The evaluation form is still being finalized. Please check back shortly.</div></div>';
        exit;
    }

    header('Content-Type: text/html; charset=utf-8');
    echo $blob_row['eval_pdf_blob'];
    exit;
}

/* ── STUDENT LIBRARY / student_library / poll / etc. continue below ── */

/* ── POLL ──
   MODIFIED: all ungraded-report queries/fields have been removed —
   admin grading of weekly reports no longer exists. */
if (isset($_GET['poll']) && $_GET['poll'] == '1') {
    $selected_p   = $_GET['week'] ?? date("Y-m-d");
    $week_start_p = date("Y-m-d", strtotime("monday this week", strtotime($selected_p ?? '')));
    $sp = $conn->prepare("
        SELECT u.id AS student_id, u.first_name, u.last_name, u.course,
               r.id AS report_id, r.report_blob IS NOT NULL AS has_blob,
               r.remark, r.feedback, r.submitted_at,
               oa.eval_submitted_at,
               CASE
                   WHEN ? > CURDATE()               THEN 'Pending'
                   WHEN r.id IS NULL                THEN 'Not Submitted'
                   WHEN r.remark = 'Wrong Document' THEN 'Not Submitted'
                   ELSE 'Submitted'
               END AS submission_status
        FROM ojt_assignments oa
        JOIN users u ON oa.student_id = u.id
        LEFT JOIN reports r ON r.user_id = u.id AND r.company_id = oa.company_id AND r.week_start = ?
        WHERE oa.company_id = ?
        ORDER BY u.first_name ASC
    ");
    $sp->bind_param("ssi", $week_start_p, $week_start_p, $company_id);
    $sp->execute();
    $rows_p = $sp->get_result()->fetch_all(MYSQLI_ASSOC);

    $wq = $conn->prepare("SELECT DISTINCT week_start FROM reports WHERE company_id=? ORDER BY week_start DESC");
    $wq->bind_param("i", $company_id);
    $wq->execute();
    $week_dates = array_column($wq->get_result()->fetch_all(MYSQLI_ASSOC), 'week_start');

    header('Content-Type: application/json');
    echo json_encode([
        'rows'       => $rows_p,
        'week_start' => $week_start_p,
        'week_dates' => $week_dates,
    ]);
    exit;
}

/* ── SAVE COMMENT ──
   NEW: replaces the removed grading form — admin can add/edit a
   comment/feedback note on a report, mirroring company_reports.php's
   save_comment handler. No grade is read or written anywhere. */
if (isset($_GET['save_comment']) && $_GET['save_comment'] == '1' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $report_id = (int)($_POST['report_id'] ?? 0);
    $feedback  = trim($_POST['feedback'] ?? '');

    $verify = $conn->prepare("SELECT id FROM reports WHERE id=? AND company_id=?");
    $verify->bind_param("ii", $report_id, $company_id);
    $verify->execute();
    $vrow = $verify->get_result()->fetch_assoc();
    if (!$vrow) {
        header('Content-Type: application/json');
        echo json_encode(['success' => false, 'message' => 'Report not found.']);
        exit;
    }

    $upd = $conn->prepare("UPDATE reports SET feedback=? WHERE id=?");
    $upd->bind_param("si", $feedback, $report_id);
    $upd->execute();
    header('Content-Type: application/json');
    echo json_encode(['success' => true, 'message' => 'Comment saved.']);
    exit;
}

/* ════════════════════════════════════════════════════════════════════
   NEW (Monthly Attendance Summary sync with attendance_management.php):
   the helper functions below are ported verbatim (same rules, same
   output) from attendance_management.php so that admin_reports.php's
   Monthly Attendance Summary — the AJAX table/chart JSON below, and the
   CSV export — computes Present/Incomplete/Absent and each student's
   OJT Start/End the exact same way the company-side page does:
     - getActiveDutyPeriods() / computeStatusForLog(): a day's status
       only checks whichever AM/PM duty periods were actually configured
       that day (attendance_settings), instead of always requiring both.
     - attm_first_attendance_map() / attm_before_start(): a student's
       OJT "Start" is their first real attendance log; any day before
       that is left blank instead of being counted as Absent.
     - admin_reports_compute_ojt_marks(): each student's OJT Start / End
       (End = date the Course Offering hour requirement was reached, an
       estimate based on remaining required hours, or their last log if
       there's no matching Course Offering) — identical formula to the
       $student_ojt_marks computation in attendance_management.php.
   ════════════════════════════════════════════════════════════════════ */
if (!function_exists('getActiveDutyPeriods')) {
    function getActiveDutyPeriods($date, $all_settings_map) {
        $setting = null;
        if (isset($all_settings_map[$date])) {
            $setting = $all_settings_map[$date];
        } else {
            $best = null;
            foreach ($all_settings_map as $d => $s) {
                if ($d <= $date) {
                    if ($best === null || $d > $best) {
                        $best = $d;
                    }
                }
            }
            if ($best !== null) $setting = $all_settings_map[$best];
        }

        if (!$setting) {
            return ['am' => true, 'pm' => true];
        }

        $amActive = !empty($setting['am_time_in_start'])
                 || !empty($setting['am_time_out_start']);
        $pmActive = !empty($setting['pm_time_in_start'])
                 || !empty($setting['pm_time_out_start']);

        if (!$amActive && !$pmActive) {
            return ['am' => true, 'pm' => true];
        }

        return ['am' => $amActive, 'pm' => $pmActive];
    }
}
if (!function_exists('computeStatusForLog')) {
    function computeStatusForLog($row, $amActive, $pmActive) {
        $isMissed = fn($v) => ($v === 'missed');
        $hasVal   = fn($v) => ($v !== null && $v !== '' && $v !== 'missed');

        $activeChecks = [];
        if ($amActive) {
            $activeChecks[] = $row['am_time_in']  ?? null;
            $activeChecks[] = $row['am_time_out'] ?? null;
        }
        if ($pmActive) {
            $activeChecks[] = $row['pm_time_in']  ?? null;
            $activeChecks[] = $row['pm_time_out'] ?? null;
        }

        $anyReal   = false;
        $allReal   = true;
        $anyMissed = false;

        foreach ($activeChecks as $v) {
            if ($hasVal($v))   $anyReal = true;
            else               $allReal = false;
            if ($isMissed($v)) $anyMissed = true;
        }

        if (!$anyReal && !$anyMissed) return 'ABSENT';
        if ($allReal && !$anyMissed)  return 'PRESENT';
        return 'INCOMPLETE';
    }
}
if (!function_exists('attm_first_attendance_map')) {
    function attm_first_attendance_map($conn, array $ids): array {
        $map = [];
        $ids = array_filter(array_map('intval', $ids));
        if (empty($ids)) return $map;
        try {
            $res = $conn->query("SELECT user_id, MIN(date) AS first_date
                                 FROM attendance_logs
                                 WHERE user_id IN (" . implode(',', $ids) . ")
                                   AND ((am_time_in  IS NOT NULL AND am_time_in  != '' AND am_time_in  != 'missed') OR (am_time_out IS NOT NULL AND am_time_out != '' AND am_time_out != 'missed') OR (pm_time_in  IS NOT NULL AND pm_time_in  != '' AND pm_time_in  != 'missed') OR (pm_time_out IS NOT NULL AND pm_time_out != '' AND pm_time_out != 'missed'))
                                 GROUP BY user_id");
            if ($res) { while ($r = $res->fetch_assoc()) $map[(int)$r['user_id']] = $r['first_date']; }
        } catch (\Throwable $e) {}
        return $map;
    }
}
if (!function_exists('attm_before_start')) {
    function attm_before_start(array $firstMap, $studentId, string $day): bool {
        $first = $firstMap[(int)$studentId] ?? null;
        return $first === null || $day < $first;
    }
}
if (!function_exists('attm_session_seconds_sql')) {
    function attm_session_seconds_sql(string $p): string {
        return "GREATEST(0, COALESCE(CASE
                    WHEN {$p}_time_in  IS NOT NULL AND {$p}_time_in  != '' AND {$p}_time_in  != 'missed'
                     AND {$p}_time_out IS NOT NULL AND {$p}_time_out != '' AND {$p}_time_out != 'missed'
                    THEN CASE
                        WHEN {$p}_time_in LIKE '%-%-% %' AND {$p}_time_out LIKE '%-%-% %'
                        THEN TIMESTAMPDIFF(SECOND, {$p}_time_in, {$p}_time_out)
                        ELSE (TIME_TO_SEC(TIME({$p}_time_out)) - TIME_TO_SEC(TIME({$p}_time_in)))
                    END
                    ELSE 0
                END, 0))";
    }
}
if (!function_exists('attm_normalize_course')) {
    function attm_normalize_course($c): string {
        $c = preg_replace('/\s+/', ' ', trim((string)$c));
        return function_exists('mb_strtolower') ? mb_strtolower($c) : strtolower($c);
    }
}
if (!function_exists('attm_add_duty_days')) {
    function attm_add_duty_days(string $fromYmd, int $n): string {
        $d = new DateTime($fromYmd);
        $isDuty = fn(DateTime $x) => !in_array((int)$x->format('w'), [0, 6], true);
        while (!$isDuty($d)) $d->modify('+1 day');
        for ($i = 1; $i < max(1, $n); $i++) {
            $d->modify('+1 day');
            while (!$isDuty($d)) $d->modify('+1 day');
        }
        return $d->format('Y-m-d');
    }
}
if (!function_exists('attm_table_columns')) {
    function attm_table_columns($conn, string $table): array {
        $cols = [];
        try {
            $r = $conn->query("SHOW COLUMNS FROM `" . str_replace('`', '', $table) . "`");
            if ($r) { while ($c = $r->fetch_assoc()) $cols[$c['Field']] = true; }
        } catch (\Throwable $e) {}
        return $cols;
    }
}
if (!function_exists('admin_reports_compute_ojt_marks')) {
    // Mirrors attendance_management.php's $student_ojt_marks computation exactly.
    // Returns [student_id => ['start'=>Y-m-d|null, 'end'=>Y-m-d|null, 'end_type'=>'completed'|'estimated'|'last_log']]
    function admin_reports_compute_ojt_marks($conn, array $students, array $first_attendance_map): array {
        $marks = [];
        try {
            $mark_ids = array_map('intval', array_keys($students));
            if (empty($mark_ids)) return $marks;
            $id_list = implode(',', $mark_ids);

            $mark_rules = [];
            try {
                $rr = $conn->query("SELECT course, total_hours, daily_hours FROM course_offerings");
                if ($rr) { while ($r = $rr->fetch_assoc()) $mark_rules[attm_normalize_course($r['course'])] = ['total' => (int)$r['total_hours'], 'daily' => (float)$r['daily_hours']]; }
            } catch (\Throwable $e) {}

            $mark_courses = [];
            $u_cols = attm_table_columns($conn, 'users');
            if (isset($u_cols['course'])) {
                $rr = $conn->query("SELECT id, course FROM users WHERE id IN ($id_list)");
                if ($rr) { while ($r = $rr->fetch_assoc()) if (trim((string)$r['course']) !== '') $mark_courses[(int)$r['id']] = $r['course']; }
            }
            $si_cols = attm_table_columns($conn, 'student_information');
            if (isset($si_cols['user_id']) && isset($si_cols['course'])) {
                $rr = $conn->query("SELECT user_id, MAX(course) AS course FROM student_information WHERE user_id IN ($id_list) GROUP BY user_id");
                if ($rr) { while ($r = $rr->fetch_assoc()) if (!isset($mark_courses[(int)$r['user_id']]) && trim((string)$r['course']) !== '') $mark_courses[(int)$r['user_id']] = $r['course']; }
            }

            $sec_sql = attm_session_seconds_sql('am') . " + " . attm_session_seconds_sql('pm');
            $rr = $conn->query("SELECT user_id, MIN(date) AS d_start, MAX(date) AS d_end, COALESCE(SUM($sec_sql), 0) AS secs
                                FROM attendance_logs WHERE user_id IN ($id_list) GROUP BY user_id");
            $mark_today = (new DateTime('now', new DateTimeZone('Asia/Manila')))->format('Y-m-d');
            if ($rr) {
                while ($r = $rr->fetch_assoc()) {
                    $uid  = (int)$r['user_id'];
                    $mark = ['start' => null, 'end' => null, 'end_type' => 'last_log'];
                    $mark['start'] = $first_attendance_map[$uid] ?? null;
                    if ($mark['start'] === null) { $marks[$uid] = $mark; continue; }
                    $mark['end'] = $r['d_end'];
                    $rule = $mark_rules[attm_normalize_course($mark_courses[$uid] ?? '')] ?? null;
                    if ($rule && $rule['total'] > 0 && $rule['daily'] > 0 && !empty($r['d_start'])) {
                        $required = (int)round($rule['total'] * 3600);
                        $rendered = (int)round((float)$r['secs']);
                        if ($rendered >= $required) {
                            $ds = $conn->prepare("SELECT date, SUM($sec_sql) AS secs FROM attendance_logs WHERE user_id = ? GROUP BY date ORDER BY date ASC");
                            if ($ds) {
                                $ds->bind_param('i', $uid);
                                $ds->execute();
                                $dres = $ds->get_result();
                                $running = 0;
                                while ($dr = $dres->fetch_assoc()) {
                                    $running += (int)round((float)$dr['secs']);
                                    if ($running >= $required) { $mark['end'] = $dr['date']; break; }
                                }
                                $ds->close();
                            }
                            $mark['end_type'] = 'completed';
                        } else {
                            $remaining_days = (int)ceil(round((($required - $rendered) / 3600) / $rule['daily'], 6));
                            $base = new DateTime($mark_today);
                            if (!empty($r['d_end']) && $r['d_end'] >= $mark_today) $base->modify('+1 day');
                            $mark['end'] = attm_add_duty_days($base->format('Y-m-d'), $remaining_days);
                            $mark['end_type'] = 'estimated';
                        }
                    }
                    $marks[$uid] = $mark;
                }
            }
        } catch (\Throwable $e) {}
        return $marks;
    }
}

/* ── ATTENDANCE SUMMARY AJAX ──
   UPDATED: now synced with attendance_management.php's Monthly
   Attendance Summary — see the helper functions immediately above for
   what changed and why. */
if (isset($_GET['attendance_summary']) && $_GET['attendance_summary'] == '1') {
    $att_month = $_GET['att_month'] ?? date('Y-m');

    $s2 = $conn->prepare("SELECT MIN(date) as sd FROM attendance_settings WHERE company_id=?");
    $s2->bind_param("i", $company_id);
    $s2->execute();
    $sd_row = $s2->get_result()->fetch_assoc();
    $att_start_date = $sd_row['sd'] ?? null;

    if (!$att_start_date) {
        header('Content-Type: application/json');
        echo json_encode(['error' => 'No attendance settings found for this company.']);
        exit;
    }

    $att_end_date_limit = date("Y-m-d", strtotime("+4 months", strtotime($att_start_date ?? '')));
    $att_ojt_start_month = date('Y-m', strtotime($att_start_date ?? ''));
    $att_month_min = $att_ojt_start_month;
    $att_month_max = date("Y-m", strtotime($att_end_date_limit ?? ''));

    if ($att_month < $att_month_min) $att_month = $att_month_min;
    if ($att_month > $att_month_max) $att_month = $att_month_max;

    if (date('Y-m', strtotime($att_start_date ?? '')) === $att_month) {
        $att_start = $att_start_date;
    } else {
        $att_start = $att_month . '-01';
    }
    $att_end = date('Y-m-t', strtotime($att_month . '-01'));
    if ($att_end > $att_end_date_limit) $att_end = $att_end_date_limit;

    // NEW: all attendance_settings rows for this company, date-keyed, so
    // getActiveDutyPeriods() can tell which duty periods (AM/PM) were
    // actually configured for each day — same source attendance_management.php uses.
    $att_settings_map = [];
    $att_settings_res = $conn->query("
        SELECT date,
               am_time_in_start, am_time_in_end, am_time_out_start, am_time_out_end,
               pm_time_in_start, pm_time_in_end, pm_time_out_start, pm_time_out_end,
               is_auto
        FROM attendance_settings
        WHERE company_id = $company_id
    ");
    if ($att_settings_res) {
        while ($sr = $att_settings_res->fetch_assoc()) {
            $att_settings_map[$sr['date']] = $sr;
        }
    }

    $stud_res = $conn->query("
        SELECT u.id, u.first_name, u.last_name
        FROM ojt_assignments oa
        JOIN users u ON oa.student_id = u.id
        WHERE oa.company_id = $company_id
        ORDER BY u.first_name ASC
    ");
    $att_students = [];
    while ($sr = $stud_res->fetch_assoc()) {
        $att_students[$sr['id']] = $sr;
    }

    // NEW: first-real-attendance map + OJT Start/End marks — identical
    // computation to attendance_management.php's Monthly Attendance Summary.
    $att_first_attendance = attm_first_attendance_map($conn, array_keys($att_students));
    $att_ojt_marks        = admin_reports_compute_ojt_marks($conn, $att_students, $att_first_attendance);

    $log_res = $conn->query("
        SELECT user_id, date, am_time_in, am_time_out, pm_time_in, pm_time_out
        FROM attendance_logs
        WHERE company_id = $company_id
          AND date BETWEEN '$att_start' AND '$att_end'
    ");
    $att_logs = [];
    while ($lr = $log_res->fetch_assoc()) {
        $att_logs[$lr['user_id']][$lr['date']] = $lr;
    }

    $att_dates = [];
    for ($d = strtotime($att_start ?? ''); $d <= strtotime($att_end ?? ''); $d = strtotime('+1 day', $d)) {
        $att_dates[] = date('Y-m-d', $d);
    }

    $table_rows = [];
    foreach ($att_students as $sid => $s) {
        $cells = [];
        $s_mark = $att_ojt_marks[$sid] ?? ['start' => null, 'end' => null, 'end_type' => 'last_log'];
        foreach ($att_dates as $d) {
            $dow = (int)date('w', strtotime($d ?? ''));
            $is_wkd = ($dow === 0 || $dow === 6);
            if ($is_wkd) {
                $cells[] = ['val' => 'O', 'class' => 'att-off', 'wknd' => true, 'mark' => null];
            } elseif ($d > date('Y-m-d')) {
                $cells[] = ['val' => '', 'class' => '', 'wknd' => false, 'mark' => null];
            } elseif (attm_before_start($att_first_attendance, $sid, $d)) {
                // UPDATED: before the student's first real attendance — blank, not Absent
                $cells[] = ['val' => '', 'class' => 'att-before-start', 'wknd' => false, 'mark' => null];
            } else {
                // UPDATED: status now respects which duty periods (AM/PM) were
                // actually configured that day, same as attendance_management.php
                $duty   = getActiveDutyPeriods($d, $att_settings_map);
                $lr     = $att_logs[$sid][$d] ?? null;
                $status = $lr ? computeStatusForLog($lr, $duty['am'], $duty['pm']) : 'ABSENT';
                $val = $status === 'PRESENT' ? 'P' : ($status === 'INCOMPLETE' ? 'I' : 'A');
                $cls = $status === 'PRESENT' ? 'att-present' : ($status === 'INCOMPLETE' ? 'att-incomplete' : 'att-absent');
                $cells[] = ['val' => $val, 'class' => $cls, 'wknd' => false, 'mark' => null];
            }
            // NEW: OJT Start / End indicator marks on the matching day —
            // same day markers shown in attendance_management.php's table.
            $last = count($cells) - 1;
            if (!empty($s_mark['start']) && $d === $s_mark['start']) {
                $cells[$last]['mark'] = 'start';
            }
            if (!empty($s_mark['end']) && $d === $s_mark['end']) {
                $is_est = ($s_mark['end_type'] === 'estimated');
                $cells[$last]['mark'] = ($cells[$last]['mark'] === 'start')
                    ? 'both' . ($is_est ? '_est' : '')
                    : ($is_est ? 'end_est' : 'end');
            }
        }
        $table_rows[] = [
            'name'      => $s['first_name'] . ' ' . $s['last_name'],
            'cells'     => $cells,
            'ojt_start' => !empty($s_mark['start']) ? date('M j, Y', strtotime($s_mark['start'] ?? '')) : '—',
            'ojt_end'   => !empty($s_mark['end'])
                ? (date('M j, Y', strtotime($s_mark['end'] ?? '')) . ($s_mark['end_type'] === 'estimated' ? ' (est.)' : ''))
                : '—',
        ];
    }

    $date_headers = [];
    foreach ($att_dates as $d) {
        $dow = (int)date('w', strtotime($d ?? ''));
        $is_wkd = ($dow === 0 || $dow === 6);
        $date_headers[] = [
            'label' => date('D d', strtotime($d ?? '')),
            'wknd'  => $is_wkd,
        ];
    }

    $today_str = date('Y-m-d');
    $all_chart_months = [];
    $cm = strtotime(date("Y-m-01", strtotime($att_start_date ?? '')));
    $cm_end = strtotime(date("Y-m-01", strtotime($att_end_date_limit ?? '')));
    while ($cm <= $cm_end) {
        $all_chart_months[] = date("Y-m", $cm);
        $cm = strtotime("+1 month", $cm);
    }

    $all_logs_res = $conn->query("
        SELECT user_id, date, am_time_in, am_time_out, pm_time_in, pm_time_out
        FROM attendance_logs
        WHERE company_id = $company_id
          AND date BETWEEN '$att_start_date' AND '$today_str'
    ");
    $all_logs_all = [];
    while ($r2 = $all_logs_res->fetch_assoc()) {
        $all_logs_all[$r2['user_id']][$r2['date']] = $r2;
    }

    $monthly_stats = [];
    foreach ($all_chart_months as $ym) {
        $ym_start = (date('Y-m', strtotime($att_start_date ?? '')) === $ym) ? $att_start_date : $ym . '-01';
        $ym_end   = date('Y-m-t', strtotime($ym . '-01'));
        if ($ym_end > $att_end_date_limit) $ym_end = $att_end_date_limit;
        if ($ym_start > $today_str) continue;
        if ($ym_end   > $today_str) $ym_end = $today_str;

        $p = 0; $inc = 0; $a = 0;
        for ($d = strtotime($ym_start ?? ''); $d <= strtotime($ym_end ?? ''); $d = strtotime('+1 day', $d)) {
            $day_str = date('Y-m-d', $d);
            $dow = (int)date('w', $d);
            if ($dow === 0 || $dow === 6) continue;
            foreach ($att_students as $sid => $s) {
                if (isset($all_logs_all[$sid][$day_str])) {
                    $lr2 = $all_logs_all[$sid][$day_str];
                    $isMissed2 = fn($v) => ($v === 'missed');
                    $hasVal2   = fn($v) => ($v !== null && $v !== '' && $v !== 'missed');

                    if (
                        ($isMissed2($lr2['am_time_in'])  || !$hasVal2($lr2['am_time_in']))  &&
                        ($isMissed2($lr2['am_time_out']) || !$hasVal2($lr2['am_time_out'])) &&
                        ($isMissed2($lr2['pm_time_in'])  || !$hasVal2($lr2['pm_time_in']))  &&
                        ($isMissed2($lr2['pm_time_out']) || !$hasVal2($lr2['pm_time_out']))
                    ) {
                        $a++;
                    } elseif (
                        $hasVal2($lr2['am_time_in'])  &&
                        $hasVal2($lr2['am_time_out']) &&
                        $hasVal2($lr2['pm_time_in'])  &&
                        $hasVal2($lr2['pm_time_out'])
                    ) {
                        $p++;
                    } elseif (
                        $hasVal2($lr2['am_time_in'])   || $hasVal2($lr2['pm_time_in'])   ||
                        $isMissed2($lr2['am_time_in'])  || $isMissed2($lr2['am_time_out']) ||
                        $isMissed2($lr2['pm_time_in'])  || $isMissed2($lr2['pm_time_out'])
                    ) {
                        $inc++;
                    } else {
                        $a++;
                    }
                } else {
                    $a++;
                }
            }
        }
        $monthly_stats[] = [
            'label'      => date('M y', strtotime($ym . '-01')),
            'ym'         => $ym,
            'present'    => $p,
            'incomplete' => $inc,
            'absent'     => $a,
        ];
    }

    header('Content-Type: application/json');
    echo json_encode([
        'success'        => true,
        'date_headers'   => $date_headers,
        'table_rows'     => $table_rows,
        'att_dates'      => $att_dates,
        'att_month'      => $att_month,
        'att_month_min'  => $att_month_min,
        'att_month_max'  => $att_month_max,
        'monthly_stats'  => $monthly_stats,
        'student_count'  => count($att_students),
    ]);
    exit;
}

/* ── ATTENDANCE CSV EXPORT ── (unchanged) */
if (isset($_GET['export_att_csv']) && $_GET['export_att_csv'] == '1') {
    $exp_month = $_GET['att_month'] ?? date('Y-m');

    $s2 = $conn->prepare("SELECT MIN(date) as sd FROM attendance_settings WHERE company_id=?");
    $s2->bind_param("i", $company_id);
    $s2->execute();
    $sd_row = $s2->get_result()->fetch_assoc();
    $exp_start_date = $sd_row['sd'] ?? date('Y-m-d');

    $exp_end_date_limit = date("Y-m-d", strtotime("+4 months", strtotime($exp_start_date ?? '')));
    $exp_month_min = date('Y-m', strtotime($exp_start_date ?? ''));
    $exp_month_max = date('Y-m', strtotime($exp_end_date_limit ?? ''));
    if ($exp_month < $exp_month_min) $exp_month = $exp_month_min;
    if ($exp_month > $exp_month_max) $exp_month = $exp_month_max;

    if (date('Y-m', strtotime($exp_start_date ?? '')) === $exp_month) {
        $exp_start = $exp_start_date;
    } else {
        $exp_start = $exp_month . '-01';
    }
    $exp_end = date('Y-m-t', strtotime($exp_month . '-01'));
    if ($exp_end > $exp_end_date_limit) $exp_end = $exp_end_date_limit;

    $stud_res = $conn->query("
        SELECT u.id, u.first_name, u.last_name
        FROM ojt_assignments oa
        JOIN users u ON oa.student_id = u.id
        WHERE oa.company_id = $company_id
        ORDER BY u.first_name ASC
    ");
    $exp_students = [];
    while ($sr = $stud_res->fetch_assoc()) {
        $exp_students[$sr['id']] = $sr;
    }

    $log_res = $conn->query("
        SELECT user_id, date, am_time_in, am_time_out, pm_time_in, pm_time_out
        FROM attendance_logs
        WHERE company_id = $company_id
          AND date BETWEEN '$exp_start' AND '$exp_end'
    ");
    $exp_logs = [];
    while ($lr = $log_res->fetch_assoc()) {
        $dow  = (int)date('w', strtotime($lr['date'] ?? ''));
        $wknd = ($dow === 0 || $dow === 6);
        $isMissed = fn($v) => ($v === 'missed');
        $hasVal   = fn($v) => ($v !== null && $v !== '' && $v !== 'missed');
        if ($wknd) {
            $st = 'OFF';
        } elseif (
            ($isMissed($lr['am_time_in'])  || !$hasVal($lr['am_time_in']))  &&
            ($isMissed($lr['am_time_out']) || !$hasVal($lr['am_time_out'])) &&
            ($isMissed($lr['pm_time_in'])  || !$hasVal($lr['pm_time_in']))  &&
            ($isMissed($lr['pm_time_out']) || !$hasVal($lr['pm_time_out']))
        ) {
            $st = 'ABSENT';
        } elseif (
            $hasVal($lr['am_time_in'])  &&
            $hasVal($lr['am_time_out']) &&
            $hasVal($lr['pm_time_in'])  &&
            $hasVal($lr['pm_time_out'])
        ) {
            $st = 'PRESENT';
        } elseif (
            $hasVal($lr['am_time_in'])   || $hasVal($lr['pm_time_in'])   ||
            $isMissed($lr['am_time_in']) || $isMissed($lr['am_time_out']) ||
            $isMissed($lr['pm_time_in']) || $isMissed($lr['pm_time_out'])
        ) {
            $st = 'INCOMPLETE';
        } else {
            $st = 'ABSENT';
        }
        $exp_logs[$lr['user_id']][$lr['date']] = $st;
    }

    $exp_dates = [];
    for ($d = strtotime($exp_start ?? ''); $d <= strtotime($exp_end ?? ''); $d = strtotime('+1 day', $d)) {
        $exp_dates[] = date('Y-m-d', $d);
    }

    $safe_company = preg_replace('/[^A-Za-z0-9_\-]/', '_', $company_name);
    $filename = 'Attendance_' . $safe_company . '_' . $exp_month . '.csv';

    header('Content-Type: text/csv; charset=UTF-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('Pragma: no-cache');
    header('Expires: 0');

    $out = fopen('php://output', 'w');
    fputs($out, "\xEF\xBB\xBF");

    fputcsv($out, [$company_name . ' — Attendance Summary — ' . date('F Y', strtotime($exp_month . '-01'))]);
    fputcsv($out, []);

    $header = ['Student Name'];
    foreach ($exp_dates as $d) {
        $dow = (int)date('w', strtotime($d ?? ''));
        $header[] = date('D d', strtotime($d ?? '')) . ($dow === 0 || $dow === 6 ? ' (Off)' : '');
    }
    $header[] = 'Present';
    $header[] = 'Incomplete';
    $header[] = 'Absent';
    fputcsv($out, $header);

    foreach ($exp_students as $sid => $stu) {
        $row = [$stu['first_name'] . ' ' . $stu['last_name']];
        $p = 0; $inc = 0; $a = 0;
        foreach ($exp_dates as $d) {
            $dow = (int)date('w', strtotime($d ?? ''));
            if ($dow === 0 || $dow === 6) {
                $row[] = 'OFF';
            } elseif ($d > date('Y-m-d')) {
                $row[] = '';
            } else {
                $val = $exp_logs[$sid][$d] ?? 'ABSENT';
                $row[] = $val;
                if ($val === 'PRESENT')        $p++;
                elseif ($val === 'INCOMPLETE') $inc++;
                elseif ($val === 'ABSENT')     $a++;
            }
        }
        $row[] = $p;
        $row[] = $inc;
        $row[] = $a;
        fputcsv($out, $row);
    }

    fclose($out);
    exit;
}

/* ── WEEK SETUP ── (unchanged) */
$weeks_stmt = $conn->prepare("SELECT DISTINCT week_start FROM reports WHERE company_id=? ORDER BY week_start DESC");
$weeks_stmt->bind_param("i", $company_id);
$weeks_stmt->execute();
$all_weeks_res   = $weeks_stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$submitted_weeks = array_column($all_weeks_res, 'week_start');

$current_week_start = date("Y-m-d", strtotime("monday this week"));
if (!in_array($current_week_start, $submitted_weeks)) array_unshift($submitted_weeks, $current_week_start);
usort($submitted_weeks, fn($a, $b) => strtotime($b ?? '') - strtotime($a ?? ''));

$selected_date = $_GET['week'] ?? $current_week_start;
$week_start    = date("Y-m-d", strtotime("monday this week", strtotime($selected_date ?? '')));
$week_end      = date("Y-m-d", strtotime("friday this week", strtotime($selected_date ?? '')));

if (!in_array($week_start, $submitted_weeks)) {
    $submitted_weeks[] = $week_start;
    usort($submitted_weeks, fn($a, $b) => strtotime($b ?? '') - strtotime($a ?? ''));
}

/* ── MAIN QUERY ──
   MODIFIED: faculty_grade / company_grade removed from SELECT (admin
   grading of weekly reports has been removed entirely). oa.eval_submitted_at
   is now selected so each student's card can show an "Evaluated" pill,
   matching company_reports.php.
   NEW: a per-student TOTAL report count (across all weeks, mirroring
   company_reports.php's total_reports) is now also fetched via a
   correlated subquery, purely to power the restyled card's "X reports"
   pill — the week-specific submission_status logic below is completely
   unchanged. */
$stmt = $conn->prepare("
    SELECT u.id AS student_id, u.first_name, u.last_name, u.course,
           r.id AS report_id, r.report_blob IS NOT NULL AS has_blob,
           r.remark, r.feedback, r.submitted_at,
           oa.eval_submitted_at,
           (SELECT COUNT(*) FROM reports r2
              WHERE r2.user_id = u.id AND r2.company_id = oa.company_id
                AND (r2.remark IS NULL OR r2.remark != 'Wrong Document')) AS total_reports,
           (SELECT MAX(r3.submitted_at) FROM reports r3
              WHERE r3.user_id = u.id AND r3.company_id = oa.company_id) AS last_submitted_at,
           CASE
               WHEN ? > CURDATE()               THEN 'Pending'
               WHEN r.id IS NULL                THEN 'Not Submitted'
               WHEN r.remark = 'Wrong Document' THEN 'Not Submitted'
               ELSE 'Submitted'
           END AS submission_status
    FROM ojt_assignments oa
    JOIN users u ON oa.student_id = u.id
    LEFT JOIN reports r ON r.user_id = u.id AND r.company_id = oa.company_id AND r.week_start = ?
    WHERE oa.company_id = ?
    ORDER BY u.first_name ASC
");
$stmt->bind_param("ssi", $week_start, $week_start, $company_id);
$stmt->execute();
$rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);

$total     = count($rows);
$submitted = count(array_filter($rows, fn($r) => $r['submission_status'] == 'Submitted'));
$not_sub   = count(array_filter($rows, fn($r) => $r['submission_status'] == 'Not Submitted'));
$pending   = count(array_filter($rows, fn($r) => $r['submission_status'] == 'Pending'));

// Check if attendance data exists for this company
$att_check = $conn->prepare("SELECT COUNT(*) as cnt FROM attendance_settings WHERE company_id=?");
$att_check->bind_param("i", $company_id);
$att_check->execute();
$att_exists = (int)($att_check->get_result()->fetch_assoc()['cnt'] ?? 0) > 0;

function weekLabel(string $ws): string {
    $mon = strtotime($ws ?? '');
    $fri = strtotime('+4 days', $mon);
    $isCurrent = $ws === date("Y-m-d", strtotime("monday this week"));
    $label = date("M d", $mon) . " – " . date("M d, Y", $fri);
    return $isCurrent ? $label . "  ✦ Current" : $label;
}

/* ============================================================
   NEW: LAST-SUBMITTED STATUS COLOR HELPER
   ------------------------------------------------------------
   Determines whether a student's "Last submitted" label should be
   shown in green (up to date — they have submitted their report for
   the currently selected/active week) or red (behind — the currently
   selected/active week has already started/ended and they have NOT
   submitted a valid report for it). If the selected week hasn't
   happened yet ('Pending'), the label stays neutral since there is
   nothing to be "behind" on yet. This only drives the color of the
   existing "Last submitted:" text — it does not touch the
   submission_status value itself or any other card logic/design.
   ============================================================ */
function lastSubmittedColorClass(string $submission_status): string {
    if ($submission_status === 'Submitted') return 'lastsub-green';
    if ($submission_status === 'Not Submitted') return 'lastsub-red';
    return 'lastsub-neutral'; // Pending (future week)
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>Admin Reports — <?= htmlspecialchars($company_name ?? '') ?></title>
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
<script src="https://cdnjs.cloudflare.com/ajax/libs/Chart.js/4.4.1/chart.umd.js"></script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/jspdf/2.5.1/jspdf.umd.min.js"></script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/html2canvas/1.4.1/html2canvas.min.js"></script>
<style>
/* ══════════════════════════════════════════════════════════════════
   DESIGN ADJUSTMENT: "Field ops grid" (Option 4 from
   company_list_style_previews.html) applied across this whole page —
   page header, view switch, week picker, student cards, attendance
   panel/table/chart, Student Library, comment drawer, evaluation
   viewer, progress overlays and toasts. Sharp corners, #C3CADA grid
   borders, navy #1B2A4A primary, uppercase tracked labels, no colored
   left bars, status shown as bold colored text.
   The sidebar/navbar keep the shared NEUST admin look so navigation is
   identical on every admin page. Every original selector is still
   here — only visual values changed; no logic was touched.
   .xl-table colors are intentionally unchanged because the same
   colors are used by the Print / Save-as-PDF document output.
   ══════════════════════════════════════════════════════════════════ */
:root {
    --neust-maroon:#07145fe5; --neust-gold:#FFD700;
    --fo-navy:#1B2A4A; --fo-navy-2:#2A3D63; --fo-line:#C3CADA; --fo-line-soft:#DDE2EC;
    --fo-bg:#EEF1F6; --fo-head:#F4F6FA; --fo-hover:#EAF0FA; --fo-muted:#5A6272; --fo-faint:#8A93A8;
    --fo-green:#2C5A2C; --fo-green-bar:#4A7A3A; --fo-gold:#A0850A; --fo-gold-ink:#7A6508; --fo-red:#A02A2A;
}
*{ box-sizing:border-box; margin:0; padding:0; }
body{ font-family:'Segoe UI',sans-serif; background:var(--fo-bg); color:var(--fo-navy); display:flex; min-height:100vh; overflow-x:hidden; }

/* ── SIDEBAR (unchanged shared admin look) ── */
.sidebar{ width:260px; background:var(--neust-maroon); height:100vh; position:fixed; display:flex; flex-direction:column; transition:0.3s; z-index:1000; }
.sidebar.collapsed{ width:80px; }
.sidebar-header{ padding:20px; display:flex; align-items:center; justify-content:space-between; border-bottom:1px solid rgba(255,255,255,0.1); }
.sidebar-header h2{ color:var(--neust-gold); margin:0; font-size:20px; white-space:nowrap; overflow:hidden; }
.sidebar.collapsed h2{ opacity:0; width:0; }
/* ── NEW (this adjustment): admin full name + "Administrator" label in the sidebar header —
   same look as the other admin pages (e.g. admin_final_grades.php). ── */
.sidebar-header-titles{ overflow:hidden; transition:0.3s; min-width:0; }
.sidebar-header-titles h2{ font-size:18px; font-weight:bold; text-overflow:ellipsis; transition:0.3s; }
.sidebar-role-label{ display:block; color:rgba(255,255,255,0.55); font-size:11px; font-weight:700; text-transform:uppercase; letter-spacing:0.8px; margin-top:3px; white-space:nowrap; overflow:hidden; text-overflow:ellipsis; transition:0.3s; }
.sidebar.collapsed .sidebar-header-titles{ opacity:0; width:0; }
.sidebar-links{ flex:1; padding:10px 0; }
.sidebar a{ padding:15px 25px; color:#cbd5e0; text-decoration:none; font-size:14px; display:flex; align-items:center; position:relative; }
.sidebar a i{ width:30px; font-size:18px; margin-right:15px; }
.sidebar.collapsed .link-text{ display:none; }
.sidebar a.active{ background:#1a237e; color:white; border-left:4px solid var(--neust-gold); }
.sidebar a:hover:not(.active){ background:rgba(255,255,255,0.07); }
.logout-link{ margin-top:auto; padding:20px; border-top:1px solid rgba(255,255,255,0.1); }
.logout-link a{ border:1px solid var(--neust-gold); color:var(--neust-gold); border-radius:6px; justify-content:center; padding:10px; text-decoration:none; display:flex; }
.sidebar-badge-ungraded{ background:#d97706; color:white; border-radius:50%; width:18px; height:18px; font-size:10px; font-weight:700; display:inline-flex; align-items:center; justify-content:center; position:absolute; right:18px; top:50%; transform:translateY(-50%); }
@keyframes dot-pulse{ 0%,100%{box-shadow:0 0 0 2px #fff8e1;} 50%{box-shadow:0 0 0 4px rgba(217,119,6,0.25);} }

/* ── LAYOUT ── */
.main-content{ margin-left:260px; width:calc(100% - 260px); min-width:0; transition:0.3s; display:flex; flex-direction:column; min-height:100vh; }
.sidebar.collapsed ~ .main-content{ margin-left:80px; width:calc(100% - 80px); }
.navbar{ background:var(--neust-maroon); padding:10px 30px; display:flex; align-items:center; color:white; height:60px; flex-shrink:0; gap:12px; }

/* ── PAGE HEADER — Field ops grid toolbar strip ── */
.page-header{ background:#fff; color:var(--fo-navy); padding:14px 28px; display:flex; align-items:center; justify-content:space-between; position:sticky; top:0; z-index:100; border-bottom:1px solid var(--fo-line); box-shadow:0 2px 8px rgba(27,42,74,0.08); flex-wrap:wrap; gap:10px; }
.page-header h2{ font-size:0.92rem; font-weight:700; text-transform:uppercase; letter-spacing:0.5px; color:var(--fo-navy); }
.page-header-sub{ font-size:0.78rem; color:var(--fo-muted); margin-top:4px; }
.page-header-sub:empty{ display:none; }
.page-header-right{ display:flex; align-items:center; gap:8px; flex-wrap:wrap; }
.back-btn{ display:inline-flex; align-items:center; gap:6px; background:#fff; border:1px solid var(--fo-line); color:var(--fo-navy); border-radius:0; padding:8px 14px; font-size:12px; font-weight:600; text-transform:uppercase; letter-spacing:0.4px; text-decoration:none; transition:background 0.15s, color 0.15s, border-color 0.15s; }
.back-btn:hover{ background:var(--fo-navy); border-color:var(--fo-navy); color:#fff; }

/* ── VIEW TOGGLE SWITCH — joined grid cells like Option 4's MOA strip ── */
.view-toggle-wrap{ display:flex; align-items:stretch; gap:0; background:#fff; border:1px solid var(--fo-line); border-radius:0; padding:0; }
.view-toggle-btn{ display:inline-flex; align-items:center; gap:6px; border:none; background:#fff; color:var(--fo-navy); border-radius:0; padding:8px 16px; font-size:12px; font-weight:600; text-transform:uppercase; letter-spacing:0.4px; cursor:pointer; transition:background 0.15s, color 0.15s; font-family:inherit; white-space:nowrap; }
.view-toggle-btn + .view-toggle-btn{ border-left:1px solid var(--fo-line); }
.view-toggle-btn.active{ background:var(--fo-navy); color:#fff; box-shadow:none; }
.view-toggle-btn:hover:not(.active){ color:var(--fo-navy); background:var(--fo-hover); }

/* ── REPORTS VIEW ──
   RESTYLED (this revision): the student cards below are now rebuilt
   to match company_reports.php's "Field ops grid" card design —
   sharp corners, bordered blocks (colored left borders removed), and
   icon-only Open Library / View Training Plan buttons with hover
   tooltips (instead of full text pill buttons). The underlying
   per-week data (submission_status, eval badge, week dropdown,
   search, silent polling) is completely unchanged; only the
   presentation of that same data has been restyled. */
.controls{ max-width:860px; width:100%; margin:16px auto 16px; padding:0 16px; display:flex; align-items:center; gap:12px; flex-wrap:wrap; }
.controls input[type="text"]{ flex:1; min-width:200px; padding:9px 14px; border:1px solid #C3CADA; border-radius:0; font-size:0.88rem; background:white; font-family:inherit; color:#1B2A4A; }
.controls input:focus{ outline:none; border-color:#1B2A4A; }
.controls input::placeholder{ color:#8A93A8; }
.total-count-simple{ display:inline-flex; align-items:center; gap:7px; background:white; border:1px solid #C3CADA; border-radius:0; padding:9px 16px; font-size:11px; font-weight:700; color:#1B2A4A; text-transform:uppercase; letter-spacing:0.4px; white-space:nowrap; flex-shrink:0; }
.total-count-simple i{ color:var(--fo-muted) !important; }
.week-dropdown-wrap{ display:flex; align-items:center; gap:8px; }
.week-dropdown-wrap label{ font-size:11px; color:var(--fo-muted); white-space:nowrap; font-weight:700; text-transform:uppercase; letter-spacing:0.4px; }
.week-select-btn{ display:flex; align-items:center; gap:8px; background:white; border:1px solid var(--fo-line); border-radius:0; padding:8px 14px; cursor:pointer; font-size:0.85rem; color:var(--fo-navy); font-family:inherit; transition:border-color 0.15s; min-width:260px; position:relative; }
.week-select-btn:hover,.week-select-btn.open{ border-color:var(--fo-navy); }
.week-select-btn .ws-label{ flex:1; text-align:left; }
.week-select-btn .ws-arrow{ font-size:0.65rem; color:var(--fo-faint); transition:transform 0.2s; }
.week-select-btn.open .ws-arrow{ transform:rotate(180deg); }
.week-dropdown-menu{ display:none; position:absolute; top:calc(100% - 1px); left:0; background:white; border:1px solid var(--fo-navy); border-radius:0; box-shadow:0 8px 24px rgba(27,42,74,0.16); z-index:200; min-width:280px; overflow:hidden; max-height:320px; overflow-y:auto; }
.week-dropdown-menu.open{ display:block; }
.week-option{ display:flex; align-items:center; justify-content:space-between; padding:10px 14px; cursor:pointer; font-size:0.85rem; border-bottom:1px solid var(--fo-line-soft); transition:background 0.15s; gap:10px; }
.week-option:last-child{ border-bottom:none; }
.week-option:hover{ background:var(--fo-hover); }
.week-option.active{ background:var(--fo-hover); }
.week-option .wo-date{ font-weight:600; color:var(--fo-navy); }
.wo-badge{ font-size:0.64rem; font-weight:700; padding:2px 7px; border-radius:0; white-space:nowrap; text-transform:uppercase; letter-spacing:0.4px; border:1px solid currentColor; background:#fff; }
.wo-badge.current{ background:#fff; color:var(--fo-navy); }
.wo-badge.has-data{ background:#fff; color:var(--fo-green); }
.week-dropdown-pos{ position:relative; }

/* Student cards — restyled to match company_reports.php's "Field ops
   grid" .student-card layout (sharp corners, no colored left
   border, icon-only action buttons with hover tooltips), while
   still showing the same underlying data as before. */
.cards{ max-width:860px; width:100%; margin:0 auto 60px; padding:0 16px; display:flex; flex-direction:column; gap:10px; }
/* ── NEW (this adjustment): shown in place of the cards list when this
   company has no registered OJT student at all (no rows in
   ojt_assignments for this company_id), instead of just leaving a blank
   area under the search bar. Styled to match .att-empty's existing look. */
.no-students-empty{ max-width:860px; width:100%; margin:0 auto 60px; padding:60px 20px; text-align:center; color:var(--fo-muted); font-size:0.85rem; background:white; border:1px solid var(--fo-line); border-radius:0; box-shadow:none; }
.no-students-empty i{ display:block; font-size:2rem; margin-bottom:12px; color:var(--fo-faint,#8A93A8); }
.card{ background:#fff; border:1px solid #C3CADA; border-radius:0; display:flex; align-items:center; justify-content:space-between; padding:14px 20px; cursor:pointer; transition:background 0.15s, border-color 0.15s, box-shadow 0.15s, transform 0.15s; gap:14px; flex-wrap:wrap; }
.card:hover{ background:#EAF0FA; box-shadow:0 5px 16px rgba(27,42,74,0.26); border-color:#8CA2C9; transform:translateY(-1px); }
/* Colored left status bars removed — submission status is still shown
   by the colored "Last submitted" text (.sc-last) on each card. */
.sc-info{ flex:1; min-width:0; }
.student-info .sname, .sc-name{ font-weight:600; font-size:0.93rem; color:#1B2A4A; }
.student-info .scourse, .sc-course{ font-size:0.68rem; color:#5A6272; margin-top:4px; text-transform:uppercase; letter-spacing:0.4px; font-weight:600; }
.sc-last{ font-size:0.72rem; color:#5A6272; margin-top:3px; font-weight:600; }
.card.submitted .sc-last{ color:#2C5A2C; }
.card.not-submitted .sc-last{ color:#8A2F28; }
.card.pending .sc-last{ color:#2C4E77; }
/* ══ NEW: explicit Last-Submitted status colors ══
   Applied directly on the .sc-last element (in addition to the
   existing card-level descendant rules above) so the "Last
   submitted:" text is unambiguously GREEN when the student is up to
   date with the currently selected/active week, and RED when they
   are behind on it. Pending (future) weeks stay neutral gray since
   there's nothing to be behind on yet. These rules are additive and
   do not remove or alter any existing card styling. */
.sc-last.lastsub-green{ color:#0f7a3d !important; }
.sc-last.lastsub-red{ color:#c0392b !important; }
.sc-last.lastsub-neutral{ color:#5A6272; }
.card-badges, .sc-badges{ display:flex; align-items:center; gap:8px; flex-wrap:wrap; justify-content:flex-end; flex-shrink:0; }
/* Option 4 shows statuses as bold colored text, not filled pills */
.eval-badge{ font-size:0.7rem; font-weight:700; padding:0; border-radius:0; background:none; color:var(--fo-navy); text-transform:uppercase; letter-spacing:0.4px; }
.status-badge{ font-size:0.7rem; font-weight:700; padding:0; border-radius:0; background:none; text-transform:uppercase; letter-spacing:0.4px; }
.status-badge.submitted{ background:none; color:var(--fo-green); }
.status-badge.not-submitted{ background:none; color:var(--fo-red); }
.status-badge.pending{ background:none; color:var(--fo-gold); }
.sc-pill{ font-size:0.68rem; font-weight:700; padding:3px 8px; border-radius:0; white-space:nowrap; text-transform:uppercase; letter-spacing:0.4px; border:1px solid var(--fo-line); background:#fff; }
.sc-pill.total{ background:#fff; color:var(--fo-navy); }
.sc-pill.none{ background:#fff; color:var(--fo-faint); }
/* Icon-only action buttons with hover tooltips (data-tooltip),
   matching company_reports.php's .icon-btn treatment exactly. */
.icon-btn {
    position: relative;
    display: inline-flex; align-items: center; justify-content: center;
    width: 34px; height: 34px;
    background: #fff; border: 1px solid #C3CADA; border-radius: 0;
    color: #1B2A4A; font-size: 13px; cursor: pointer; flex-shrink: 0;
    transition: background 0.15s, border-color 0.15s, color 0.15s;
}
.icon-btn:hover{ background:#1B2A4A; border-color:#1B2A4A; color:#fff; }
.icon-btn:focus-visible{ outline:2px solid var(--fo-gold); outline-offset:2px; }
.icon-btn.eval-done{ color:#2C5A2C; border-color:#4A7A3A; }
.icon-btn.eval-done:hover{ background:#2C5A2C; border-color:#2C5A2C; color:#fff; }
.icon-btn[data-tooltip]::after {
    content: attr(data-tooltip);
    position: absolute; bottom: calc(100% + 7px); left: 50%; transform: translateX(-50%);
    background: #1B2A4A; color: #fff; font-size: 10.5px; font-weight: 600;
    text-transform: uppercase; letter-spacing: 0.3px; padding: 5px 9px;
    white-space: nowrap; opacity: 0; pointer-events: none; transition: opacity 0.15s; z-index: 30;
}
.icon-btn[data-tooltip]::before {
    content: ''; position: absolute; bottom: 100%; left: 50%; transform: translateX(-50%);
    border: 5px solid transparent; border-top-color: #1B2A4A; margin-bottom: -3px;
    opacity: 0; pointer-events: none; transition: opacity 0.15s; z-index: 30;
}
.icon-btn[data-tooltip]:hover::after,
.icon-btn[data-tooltip]:hover::before { opacity: 1; }
.sc-open-btn{ display:inline-flex; align-items:center; gap:5px; background:#fff; color:var(--fo-navy); border:1px solid var(--fo-line); border-radius:0; padding:6px 12px; font-size:0.72rem; font-weight:700; text-transform:uppercase; letter-spacing:0.4px; white-space:nowrap; flex-shrink:0; cursor:pointer; font-family:inherit; }
.card:hover .sc-open-btn{ background:var(--fo-navy); border-color:var(--fo-navy); color:#fff; }
.sc-viewplan-btn{ display:inline-flex; align-items:center; gap:5px; background:#fff; color:var(--fo-green); border:1px solid var(--fo-green-bar); border-radius:0; padding:6px 12px; font-size:0.72rem; font-weight:700; text-transform:uppercase; letter-spacing:0.4px; white-space:nowrap; flex-shrink:0; cursor:pointer; font-family:inherit; transition:background 0.15s, color 0.15s; }
.sc-viewplan-btn:hover{ background:var(--fo-green); color:#fff; }
.sc-arrow{ font-size:0.7rem; color:#8A93A8; }
.sc-eval-strip{ width:100%; margin-top:10px; padding-top:10px; border-top:1px solid var(--fo-line-soft); display:flex; align-items:center; gap:8px; flex-wrap:wrap; }
.sc-eval-label{ font-size:0.68rem; font-weight:700; color:var(--fo-green); text-transform:uppercase; letter-spacing:0.4px; }
.sc-eval-date{ font-size:0.67rem; color:var(--fo-muted); margin-left:auto; font-weight:600; }

/* ── ATTENDANCE SUMMARY VIEW ──
   FIX (this revision): the panel header's month-nav toolbar
   (Prev / month label / Next / month picker / Export CSV) was being
   cut off the right edge of the viewport whenever the sidebar was
   expanded (260px), because the toolbar itself never wrapped its
   children and its buttons had non-shrinking, nowrap content — it
   simply overflowed past the visible content area instead of
   reflowing, unlike the collapsed-sidebar (80px) state where there
   happened to be enough room. Giving the toolbar its own flex-wrap
   (plus min-width:0 / flex-shrink on its children) lets it reflow
   onto additional lines and stay fully visible and consistent
   regardless of whether the sidebar is expanded or collapsed — the
   underlying attendance data, table, chart, and all other logic are
   completely unchanged. */
#attSummaryPanel{ display:none; max-width:1100px; width:100%; margin:0 auto 60px; padding:0 16px; min-width:0; }
#attSummaryPanel.visible{ display:block; }
.att-panel-header{ display:flex; align-items:center; justify-content:space-between; margin:20px 0 14px; flex-wrap:wrap; gap:12px; row-gap:12px; background:#fff; border:1px solid var(--fo-line); padding:12px 16px; }
.att-panel-header h3{ font-size:0.8rem; font-weight:700; color:var(--fo-navy); display:flex; align-items:center; gap:8px; flex:1 1 auto; min-width:200px; text-transform:uppercase; letter-spacing:0.5px; }
.att-month-nav{ display:flex; align-items:center; gap:6px; flex-wrap:wrap; row-gap:8px; justify-content:flex-end; flex:0 1 auto; max-width:100%; }
.att-month-nav button{ background:white; border:1px solid var(--fo-line); border-radius:0; padding:7px 12px; font-size:11.5px; font-weight:700; color:var(--fo-navy); cursor:pointer; transition:background 0.15s, color 0.15s, border-color 0.15s; font-family:inherit; flex-shrink:0; text-transform:uppercase; letter-spacing:0.4px; }
.att-month-nav button:hover:not(:disabled){ border-color:var(--fo-navy); color:#fff; background:var(--fo-navy); }
.att-month-nav button:disabled{ opacity:0.4; cursor:not-allowed; }
.att-month-label{ font-size:0.8rem; font-weight:700; color:var(--fo-navy); min-width:110px; text-align:center; flex-shrink:0; text-transform:uppercase; letter-spacing:0.4px; }
.att-month-input{ padding:6px 10px; border:1px solid var(--fo-line); border-radius:0; font-size:0.8rem; font-family:inherit; color:var(--fo-navy); background:white; cursor:pointer; flex-shrink:0; }
.att-month-input:focus{ outline:none; border-color:var(--fo-navy); }
.att-export-btn{ display:inline-flex; align-items:center; gap:6px; background:var(--fo-navy); color:#fff; border:1px solid var(--fo-navy); border-radius:0; padding:7px 14px; font-size:11.5px; font-weight:700; text-transform:uppercase; letter-spacing:0.4px; cursor:pointer; text-decoration:none; transition:background 0.15s; white-space:nowrap; flex-shrink:0; }
.att-export-btn:hover{ background:var(--fo-navy-2); border-color:var(--fo-navy-2); }
.att-legend{ display:flex; gap:0; flex-wrap:wrap; margin-bottom:14px; background:#fff; border:1px solid var(--fo-line); }
.att-legend-item{ display:flex; align-items:center; gap:6px; font-size:0.7rem; font-weight:700; color:var(--fo-navy); padding:8px 12px; border-right:1px solid var(--fo-line); text-transform:uppercase; letter-spacing:0.3px; }
.att-legend-item:last-child{ border-right:none; }
.att-legend-dot{ width:10px; height:10px; border-radius:0; flex-shrink:0; }
.att-table-wrap{ overflow-x:auto; border-radius:0; border:1px solid var(--fo-line); box-shadow:none; max-width:100%; }
.att-table{ width:100%; border-collapse:collapse; background:white; font-size:0.78rem; min-width:600px; }
.att-table th{ background:var(--fo-head); color:var(--fo-navy); font-weight:700; padding:8px 6px; text-align:center; border-bottom:1px solid var(--fo-line); white-space:nowrap; font-size:0.68rem; text-transform:uppercase; letter-spacing:0.3px; }
.att-table th.att-name-col{ text-align:left; padding-left:14px; min-width:130px; }
.att-table td{ padding:7px 4px; text-align:center; border-bottom:1px solid var(--fo-line-soft); font-weight:700; font-size:0.72rem; }
.att-table td.att-name-td{ text-align:left; padding-left:14px; color:var(--fo-navy); font-weight:600; font-size:0.8rem; white-space:nowrap; }
.att-table th.wknd-col{ background:#E6EAF2; color:var(--fo-muted); }
.att-table td.att-off{ background:#EEF1F6; color:var(--fo-muted); font-style:italic; }
.att-table td.att-present{ color:var(--fo-green); background:#EEF4EA; }
.att-table td.att-absent{ color:var(--fo-red); background:#F8ECEB; }
.att-table td.att-incomplete{ color:var(--fo-gold-ink); background:#FBF6E3; }
.att-table td.att-before-start{ background:#FAFBFD; color:#C3CADA; } /* NEW: before first attendance — matches attendance_management.php */
.att-table td .att-mark{ display:block; font-size:9px; line-height:1; margin:0 auto 2px; font-style:normal; }
.att-mark-start{ color:#2C5A2C; }
.att-mark-end{ color:#A02A2A; }
.att-mark-est{ opacity:.55; }
.att-table td.att-has-mark{ box-shadow:inset 0 0 0 1px rgba(27,42,74,.28); }
.att-table tbody tr:hover td{ filter:brightness(0.97); }
.att-table tbody tr:last-child td{ border-bottom:none; }
.att-loading{ display:flex; align-items:center; justify-content:center; gap:12px; padding:60px; color:var(--fo-muted); font-size:0.78rem; font-weight:700; text-transform:uppercase; letter-spacing:0.5px; background:#fff; border:1px solid var(--fo-line); }
.att-spinner{ width:22px; height:22px; border:3px solid #A3AFC7; border-top-color:var(--fo-navy); border-radius:50%; animation:spin 0.7s linear infinite; }
.att-empty{ text-align:center; padding:60px 20px; color:var(--fo-muted); font-size:0.85rem; background:white; border-radius:0; border:1px solid var(--fo-line); box-shadow:none; }
@keyframes spin{ to{ transform:rotate(360deg); } }
.att-chart-box{ background:white; border-radius:0; border:1px solid var(--fo-line); box-shadow:none; padding:18px 20px; margin-top:18px; max-width:100%; }
.att-chart-box h3{ font-size:0.8rem; font-weight:700; color:var(--fo-navy); margin-bottom:4px; text-transform:uppercase; letter-spacing:0.5px; }
.att-chart-subtitle{ font-size:0.76rem; color:var(--fo-muted); margin-bottom:14px; }
.att-chart-legend{ display:flex; gap:18px; margin-bottom:12px; flex-wrap:wrap; }
.att-chart-legend-item{ display:flex; align-items:center; gap:6px; font-size:0.7rem; color:var(--fo-navy); font-weight:700; text-transform:uppercase; letter-spacing:0.3px; }
.att-chart-legend-dot{ width:12px; height:12px; border-radius:0; flex-shrink:0; }
.att-chart-canvas-wrap{ position:relative; height:260px; width:100%; }
.att-chart-nav{ display:flex; align-items:center; justify-content:space-between; margin-bottom:14px; gap:0; flex-wrap:wrap; border:1px solid var(--fo-line); }
.att-chart-nav-title{ font-size:0.75rem; font-weight:700; color:var(--fo-navy); flex:1; text-align:center; min-width:120px; text-transform:uppercase; letter-spacing:0.4px; }
.att-chart-nav-btn{ background:#fff; border:none; border-radius:0; padding:8px 14px; font-size:11.5px; font-weight:700; cursor:pointer; color:var(--fo-navy); transition:background 0.15s, color 0.15s; font-family:inherit; flex-shrink:0; text-transform:uppercase; letter-spacing:0.4px; }
.att-chart-nav-btn:first-child{ border-right:1px solid var(--fo-line); }
.att-chart-nav-btn:last-child{ border-left:1px solid var(--fo-line); }
.att-chart-nav-btn:hover:not(:disabled){ background:var(--fo-navy); color:#fff; }
.att-chart-nav-btn:disabled{ opacity:0.35; cursor:not-allowed; }

/* ── SHARED ── */
.spinner{ width:18px; height:18px; border:3px solid #A3AFC7; border-top-color:var(--fo-navy); border-radius:50%; animation:spin 0.75s linear infinite; flex-shrink:0; }
.refresh-indicator{ position:fixed; bottom:14px; right:16px; background:var(--fo-navy); color:#E3E8F1; border:1px solid #55668C; border-radius:0; padding:6px 12px; font-size:0.66rem; font-weight:700; text-transform:uppercase; letter-spacing:0.4px; display:flex; align-items:center; gap:6px; opacity:0; transition:opacity 0.4s; pointer-events:none; z-index:500; }
.refresh-indicator.show{ opacity:1; }
.refresh-dot{ width:7px; height:7px; border-radius:50%; background:#8FD18F; animation:pulse-dot 1s ease-in-out infinite; }
@keyframes pulse-dot{ 0%,100%{opacity:1;} 50%{opacity:0.3;} }
.xl-table{ width:100%; border-collapse:collapse; font-size:0.82rem; }
.xl-table td{ border:1px solid #e5e7eb; padding:8px 10px; vertical-align:top; line-height:1.5; }
.xl-table tr:first-child td{ background:#1a56db; color:white; font-weight:700; font-size:1rem; text-align:center; }
.xl-table tr:nth-child(2) td{ background:#0e9f6e; color:white; font-size:0.82rem; text-align:center; font-style:italic; }
.xl-table tr:nth-child(3) td{ padding:3px; background:#f9fafb; }
.xl-table tr:nth-child(4) td{ background:#374151; color:white; font-weight:700; text-align:center; }
.xl-table tr:nth-last-child(1) td{ background:#f3f4f6; color:#6b7280; font-size:0.75rem; text-align:center; font-style:italic; }
.xl-table tr:nth-child(n+5):nth-last-child(n+2) td:first-child{ background:#eff6ff; color:#1a56db; font-weight:700; text-align:center; white-space:pre-line; }
.toast{ position:fixed; bottom:24px; left:50%; transform:translateX(-50%); background:var(--fo-navy); color:#E3E8F1; border:1px solid #55668C; padding:11px 20px; border-radius:0; font-size:0.8rem; font-weight:600; box-shadow:0 8px 24px rgba(27,42,74,0.30); z-index:2000; opacity:0; transition:opacity 0.3s; pointer-events:none; }
.toast.show{ opacity:1; }
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
/* ── FIX (sidebar notification indicator): Company Requirements badge
   (same look as on admin_monitoring_dashboard.php / course_offering.php) ── */
.sidebar-badge-moa {
    background: #ef4444; color: white; border-radius: 50%;
    width: 18px; height: 18px; font-size: 10px; font-weight: 700;
    display: inline-flex; align-items: center; justify-content: center;
    position: absolute; right: 18px; top: 50%; transform: translateY(-50%);
    animation: badge-pulse-moa-sidebar 2s ease-in-out infinite;
}
@keyframes badge-pulse-moa-sidebar {
    0%,100% { box-shadow: 0 0 0 0 rgba(239,68,68,0.55); }
    50%      { box-shadow: 0 0 0 6px rgba(239,68,68,0); }
}

/* ══ STUDENT LIBRARY — FULL-SCREEN REPORT VIEW ══ */
.lib-overlay{ display:none; position:fixed; inset:0; background:rgba(27,42,74,0.78); z-index:1200; overflow:hidden; flex-direction:column; }
.lib-overlay.open{ display:flex; }
.lib-doc-toolbar{ background:var(--fo-navy); padding:0.55rem 1.5rem; display:flex; align-items:center; justify-content:space-between; flex-shrink:0; box-shadow:0 2px 10px rgba(0,0,0,0.25); border-bottom:1px solid #55668C; gap:1rem; flex-wrap:wrap; }
.lib-doc-toolbar-left{ display:flex; align-items:center; gap:10px; min-width:0; }
.lib-doc-toolbar-title{ font-size:0.8rem; font-weight:700; color:#fff; white-space:nowrap; overflow:hidden; text-overflow:ellipsis; text-transform:uppercase; letter-spacing:0.5px; }
.lib-doc-toolbar-course{ font-size:0.66rem; color:rgba(255,255,255,0.6); white-space:nowrap; text-transform:uppercase; letter-spacing:0.4px; font-weight:600; }
.lib-doc-toolbar-right{ display:flex; align-items:center; gap:6px; flex-shrink:0; flex-wrap:wrap; }
.eval-tbtn{ display:inline-flex; align-items:center; gap:0.4rem; padding:7px 14px; border-radius:0; font-size:11.5px; font-weight:700; text-transform:uppercase; letter-spacing:0.4px; cursor:pointer; border:1px solid transparent; font-family:inherit; transition:background 0.15s, color 0.15s, border-color 0.15s; white-space:nowrap; }
.eval-tbtn-primary{ background:#fff; color:var(--fo-navy); border-color:#fff; }
.eval-tbtn-primary:hover:not(:disabled){ background:var(--fo-hover); border-color:var(--fo-hover); }
.eval-tbtn-primary:disabled{ background:rgba(255,255,255,0.35); border-color:transparent; color:var(--fo-navy); cursor:not-allowed; opacity:0.6; }
.eval-tbtn-close{ background:transparent; color:rgba(255,255,255,0.88); border:1px solid rgba(255,255,255,0.35); }
.eval-tbtn-close:hover{ background:rgba(255,255,255,0.12); color:#fff; }
.lib-tbtn-comment{ background:transparent; color:rgba(255,255,255,0.9); border:1px solid rgba(255,255,255,0.35); }
.lib-tbtn-comment:hover:not(:disabled){ background:rgba(255,255,255,0.12); color:#fff; }
.lib-tbtn-comment:disabled{ opacity:0.5; cursor:not-allowed; }
.lib-tbtn-comment.has-comment{ background:rgba(201,151,30,0.22); border-color:#C9971E; color:#F6DC8E; }
.lib-eval-btn{ display:inline-flex; align-items:center; gap:6px; background:transparent; border:1px solid rgba(255,255,255,0.35); color:white; border-radius:0; padding:7px 14px; font-size:11.5px; font-weight:700; text-transform:uppercase; letter-spacing:0.4px; cursor:pointer; transition:background 0.15s; white-space:nowrap; font-family:inherit; }
.lib-eval-btn:hover:not(:disabled){ background:rgba(255,255,255,0.12); }
.lib-eval-btn:disabled{ opacity:0.5; cursor:not-allowed; }
.lib-eval-btn.eval-done{ background:rgba(74,122,58,0.28); border-color:#8FD18F; color:#CDEBC4; }

.lib-summary-bar{ background:var(--fo-bg); border-bottom:1px solid var(--fo-line); padding:10px 20px; display:flex; gap:28px; flex-shrink:0; flex-wrap:wrap; }
.lib-stat{ display:flex; flex-direction:column; gap:1px; }
.lib-stat-val{ font-size:1.05rem; font-weight:800; color:var(--fo-navy); line-height:1; }
.lib-stat-lbl{ font-size:0.62rem; color:var(--fo-muted); text-transform:uppercase; letter-spacing:0.06em; }

/* ══ Weekly report compliance cards (Total / Submitted / Not
      Submitted) shown in the Student Library's summary strip —
      restyled to the Field ops grid: joined square cells (no
      colored left bar). ══ */
.lib-stats-row{ display:flex; gap:0; flex-wrap:wrap; flex:1; border:1px solid var(--fo-line); background:#fff; }
.lib-stat-card{
    flex: 1 1 150px;
    min-width: 140px;
    display: flex;
    align-items: center;
    gap: 12px;
    background: #fff;
    border: none;
    border-right: 1px solid var(--fo-line);
    border-radius: 0;
    padding: 10px 14px;
    box-shadow: none;
}
.lib-stat-card:last-child{ border-right:none; }
.lib-stat-icon{
    width: 34px; height: 34px;
    border-radius: 0;
    display: flex; align-items: center; justify-content: center;
    font-size: 13px; flex-shrink: 0;
    border: 1px solid var(--fo-line);
}
.lib-stat-icon.total{ background:#fff; color:var(--fo-navy); }
.lib-stat-icon.submitted{ background:#fff; color:var(--fo-green); border-color:var(--fo-green-bar); }
.lib-stat-icon.missed{ background:#fff; color:var(--fo-red); border-color:#D9A9A5; }
.lib-stat-text{ display:flex; flex-direction:column; min-width:0; }
.lib-stat-num{ font-size:1.3rem; font-weight:800; line-height:1.1; color:var(--fo-navy); }
.lib-stat-num.submitted-num{ color:var(--fo-green); }
.lib-stat-num.missed-num{ color:var(--fo-red); }
.lib-stat-lbl2{
    font-size: 0.62rem; font-weight: 700; color: var(--fo-muted);
    text-transform: uppercase; letter-spacing: 0.5px; margin-top: 2px;
}

.lib-doc-body{ flex:1; min-height:0; overflow:hidden; display:flex; }
.lib-split-body{ flex:1; display:grid; grid-template-columns:260px 1fr; min-height:0; overflow:hidden; }
.lib-list-pane{ background:white; border-right:1px solid var(--fo-line); overflow-y:auto; display:flex; flex-direction:column; }
.lib-list-empty{ padding:40px 16px; text-align:center; color:var(--fo-muted); font-size:0.78rem; }
.lib-loading{ display:flex; align-items:center; justify-content:center; gap:10px; padding:40px; color:var(--fo-muted); font-size:0.72rem; font-weight:700; text-transform:uppercase; letter-spacing:0.5px; }
.lib-list-item{ padding:10px 12px; border-bottom:1px solid var(--fo-line-soft); cursor:pointer; transition:background 0.12s; display:flex; flex-direction:column; gap:5px; }
.lib-list-item:hover{ background:var(--fo-hover); }
.lib-list-item.active{ background:var(--fo-hover); }
.lib-list-week{ font-size:0.78rem; font-weight:700; color:var(--fo-navy); }
.lib-list-sub{ font-size:0.66rem; color:var(--fo-muted); text-transform:uppercase; letter-spacing:0.3px; font-weight:600; }

.lib-detail-pane{ overflow-y:auto; padding:0; display:flex; flex-direction:column; background:var(--fo-bg); }
.lib-detail-empty{ flex:1; display:flex; flex-direction:column; align-items:center; justify-content:center; color:var(--fo-muted); font-size:0.75rem; font-weight:700; text-transform:uppercase; letter-spacing:0.5px; gap:10px; padding:40px; }
.lib-detail-empty i{ font-size:2rem; color:var(--fo-line); }
.lib-detail-body{ padding:16px 20px; display:flex; flex-direction:column; gap:14px; flex:1; min-height:0; }
.lib-preview-section{ background:white; border-radius:0; border:1px solid var(--fo-line); padding:14px 16px; flex:1; display:flex; flex-direction:column; min-height:0; }
.lib-preview-title{ font-size:0.7rem; font-weight:700; text-transform:uppercase; letter-spacing:0.5px; color:var(--fo-navy); margin-bottom:10px; flex-shrink:0; padding-bottom:8px; border-bottom:1px solid var(--fo-line-soft); }
.lib-report-preview{ border:1px solid var(--fo-line); border-radius:0; overflow:hidden; background:#FAFBFD; flex:1; display:flex; min-height:0; }
.lib-report-preview-frame{ width:100%; height:100%; min-height:68vh; border:none; display:block; background:#fff; }
.lib-report-preview-loading{ display:flex; align-items:center; justify-content:center; gap:10px; width:100%; min-height:360px; color:var(--fo-muted); font-size:0.72rem; font-weight:700; text-transform:uppercase; letter-spacing:0.5px; }
.lib-report-preview-empty{ display:flex; flex-direction:column; align-items:center; justify-content:center; gap:8px; width:100%; min-height:200px; color:var(--fo-muted); font-size:0.82rem; padding:30px; text-align:center; }
.lib-report-preview-empty i{ font-size:1.8rem; color:var(--fo-line); }
.lib-report-preview-table{ padding:14px; overflow-x:auto; overflow-y:auto; width:100%; max-height:68vh; }

/* ── Collapsible comment drawer (replaces the old grading drawer) ── */
.lib-drawer-backdrop{ display:none; position:fixed; inset:0; background:rgba(27,42,74,0.30); z-index:1240; }
.lib-drawer-backdrop.show{ display:block; }
.lib-drawer{ position:fixed; top:0; right:-400px; width:380px; max-width:92vw; height:100vh; background:var(--fo-bg); border-left:1px solid var(--fo-line); box-shadow:-6px 0 28px rgba(27,42,74,0.22); z-index:1250; transition:right 0.25s ease; display:flex; flex-direction:column; }
.lib-drawer.open{ right:0; }
.lib-drawer-header{ background:var(--fo-navy); color:white; padding:13px 18px; display:flex; align-items:center; justify-content:space-between; font-weight:700; font-size:12px; text-transform:uppercase; letter-spacing:0.6px; flex-shrink:0; }
.lib-drawer-header button{ background:transparent; border:1px solid rgba(255,255,255,0.35); color:white; width:28px; height:28px; border-radius:0; font-size:0.9rem; cursor:pointer; display:flex; align-items:center; justify-content:center; transition:background 0.15s; }
.lib-drawer-header button:hover{ background:rgba(255,255,255,0.14); }
.lib-drawer-body{ padding:16px 18px; overflow-y:auto; flex:1; }
.lib-drawer-empty{ color:var(--fo-muted); font-size:0.78rem; text-align:center; padding:40px 10px; }
.lib-drawer-section{ background:white; border-radius:0; border:1px solid var(--fo-line); padding:14px 16px; }
.lib-drawer-title-row{ display:flex; align-items:center; justify-content:space-between; gap:8px; margin-bottom:10px; padding-bottom:8px; border-bottom:1px solid var(--fo-line-soft); }
.lib-drawer-title{ font-size:0.7rem; font-weight:700; text-transform:uppercase; letter-spacing:0.5px; color:var(--fo-navy); margin-bottom:0; }
.lib-comment-display{ font-size:0.82rem; color:var(--fo-navy); background:var(--fo-head); border:1px solid var(--fo-line-soft); border-radius:0; padding:9px 11px; line-height:1.55; min-height:36px; }
.lib-comment-display.empty{ color:var(--fo-faint); font-style:normal; font-size:0.72rem; font-weight:700; text-transform:uppercase; letter-spacing:0.4px; }
.lib-comment-ta{ width:100%; padding:9px 11px; border:1px solid var(--fo-line); border-radius:0; font-size:0.82rem; font-family:inherit; color:var(--fo-navy); background:#fff; resize:vertical; min-height:100px; transition:border-color 0.15s; }
.lib-comment-ta:focus{ outline:none; border-color:var(--fo-navy); background:white; }
.lib-form-btns{ display:flex; gap:6px; align-items:center; flex-wrap:wrap; margin-top:8px; }
.lib-btn-save{ background:var(--fo-navy); color:white; border:1px solid var(--fo-navy); border-radius:0; padding:7px 14px; font-size:11.5px; font-weight:700; text-transform:uppercase; letter-spacing:0.4px; cursor:pointer; transition:background 0.15s; font-family:inherit; }
.lib-btn-save:hover:not(:disabled){ background:var(--fo-navy-2); }
.lib-btn-save:disabled{ background:#E6EAF2; border-color:var(--fo-line); color:var(--fo-faint); cursor:not-allowed; }
.lib-btn-comment-edit{ background:#fff; color:var(--fo-green); border:1px solid var(--fo-green-bar); border-radius:0; padding:7px 12px; font-size:11.5px; font-weight:700; text-transform:uppercase; letter-spacing:0.4px; cursor:pointer; font-family:inherit; transition:background 0.15s, color 0.15s; }
.lib-btn-comment-edit:hover{ background:var(--fo-green); color:#fff; }
.lib-btn-comment-cancel{ background:#fff; color:var(--fo-navy); border:1px solid var(--fo-line); border-radius:0; padding:7px 12px; font-size:11.5px; font-weight:700; text-transform:uppercase; letter-spacing:0.4px; cursor:pointer; font-family:inherit; }
.lib-btn-comment-cancel:hover{ background:var(--fo-hover); }
.save-feedback{ font-size:0.68rem; padding:4px 9px; border-radius:0; display:none; font-weight:700; text-transform:uppercase; letter-spacing:0.3px; }
.save-feedback.success{ background:#fff; border:1px solid var(--fo-green-bar); color:var(--fo-green); display:inline-block; }
.save-feedback.error{ background:#fff; border:1px solid var(--fo-red); color:var(--fo-red); display:inline-block; }

/* ── EVALUATION VIEW MODAL (read-only for admin) ── */
.eval-overlay{ display:none; position:fixed; inset:0; background:rgba(27,42,74,0.78); z-index:1400; overflow-y:auto; padding:0; }
.eval-overlay.open{ display:block; }
.eval-doc-toolbar{ background:var(--fo-navy); padding:0.55rem 1.5rem; display:flex; align-items:center; justify-content:space-between; position:sticky; top:0; z-index:200; box-shadow:0 2px 10px rgba(0,0,0,0.25); border-bottom:1px solid #55668C; gap:1rem; flex-wrap:wrap; }
.eval-doc-toolbar-left{ display:flex; align-items:center; gap:10px; min-width:0; }
.eval-doc-toolbar-title{ font-size:0.78rem; font-weight:700; color:rgba(255,255,255,0.9); white-space:nowrap; overflow:hidden; text-overflow:ellipsis; text-transform:uppercase; letter-spacing:0.5px; }
.eval-doc-toolbar-right{ display:flex; align-items:center; gap:6px; flex-shrink:0; }
.eval-doc-canvas{ background:#d8dde8; padding:24px 16px 40px; min-height:calc(100vh - 46px); display:flex; flex-direction:column; align-items:center; }
.eval-blob-frame{ width:100%; max-width:900px; border:1px solid var(--fo-line); display:block; min-height:900px; background:#fff; border-radius:0; box-shadow:0 4px 24px rgba(27,42,74,0.2); }
.eval-empty-state{ display:flex; flex-direction:column; align-items:center; justify-content:center; min-height:300px; color:rgba(255,255,255,0.85); gap:12px; font-family:'Segoe UI',sans-serif; text-transform:uppercase; letter-spacing:0.5px; font-size:0.8rem; font-weight:700; }
.eval-empty-state i{ font-size:2.2rem; }

/* ── PDF GENERATION PROGRESS OVERLAYS ── */
.pdf-gen-overlay{ display:none; position:fixed; inset:0; background:rgba(27,42,74,0.82); z-index:9000; align-items:center; justify-content:center; flex-direction:column; gap:18px; }
.pdf-gen-overlay.show{ display:flex; }
.pdf-gen-box{ background:white; border-radius:0; border:1px solid var(--fo-line); padding:28px 36px; text-align:center; box-shadow:0 8px 40px rgba(0,0,0,0.30); max-width:340px; width:90%; }
.pdf-gen-icon{ font-size:2.2rem; margin-bottom:12px; }
.pdf-gen-title{ font-size:0.82rem; font-weight:700; color:var(--fo-navy); margin-bottom:6px; text-transform:uppercase; letter-spacing:0.6px; }
.pdf-gen-msg{ font-size:0.82rem; color:var(--fo-muted); line-height:1.55; }
.pdf-gen-spinner{ width:36px; height:36px; border:4px solid #A3AFC7; border-top-color:var(--fo-navy); border-radius:50%; animation:spin 0.8s linear infinite; margin:14px auto 0; }
#printGenOverlay .pdf-gen-spinner{ border-top-color:var(--fo-green-bar); }

@media (max-width: 840px) {
    .lib-split-body{ grid-template-columns:1fr; }
    .lib-list-pane{ max-height:200px; border-right:none; border-bottom:1px solid var(--fo-line); }
    .lib-drawer{ width:100%; max-width:100%; right:-100%; }
    .lib-doc-toolbar-right{ gap:6px; }
    .controls{ flex-direction:column; align-items:stretch; }
    .total-count-simple{ justify-content:center; text-align:center; border-right:1px solid #C3CADA; border-bottom:none; }
    .lib-stats-row{ flex-direction:column; }
    .lib-stat-card{ border-right:none; border-bottom:1px solid var(--fo-line); }
    .lib-stat-card:last-child{ border-bottom:none; }
    .att-panel-header{ flex-direction:column; align-items:stretch; }
    .att-month-nav{ justify-content:flex-start; }
}
@media (prefers-reduced-motion: reduce) {
    .card, .icon-btn, .back-btn, .view-toggle-btn, .lib-drawer { transition:none; }
    .card:hover{ transform:none; }
}

/* ── FIX: keep the Attendance panel's month-nav toolbar visible and
   consistently laid out whenever the sidebar is expanded (narrows the
   main content area) — instead of overflowing off-screen, its
   buttons now wrap onto their own row while the panel itself stays
   correctly contained within the available width. Purely a layout
   fix; no attendance data/behavior is touched. */
.sidebar:not(.collapsed) ~ .main-content #attSummaryPanel{ max-width:100%; }
.sidebar:not(.collapsed) ~ .main-content .att-panel-header{ flex-wrap:wrap; }
</style>
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
<!-- NEW (this adjustment): loading page — same styles as admin_monitoring_dashboard.php / administrator.php -->
<style>
    #globalLoadingOverlay { position: fixed; inset: 0; z-index: 20000; display: flex; align-items: center; justify-content: center; background: rgba(238, 241, 246, 0.92); opacity: 1; visibility: visible; transition: opacity 0.35s ease, visibility 0.35s ease; }
    #globalLoadingOverlay.hidden { opacity: 0; visibility: hidden; pointer-events: none; }
    .global-loading-box { display: flex; flex-direction: column; align-items: center; gap: 16px; animation: globalLoadingPop 0.35s ease; }
    .global-loading-spinner { width: 54px; height: 54px; border-radius: 50%; border: 5px solid #A3AFC7; border-top-color: #1B2A4A; animation: globalLoadingSpin 0.85s linear infinite; }
    .global-loading-text { font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif; font-size: 13px; font-weight: 700; color: #1B2A4A; text-transform: uppercase; letter-spacing: 0.6px; display: flex; align-items: center; gap: 8px; }
    .global-loading-dots span { animation: globalLoadingDots 1.2s infinite; opacity: 0; }
    .global-loading-dots span:nth-child(2) { animation-delay: 0.2s; }
    .global-loading-dots span:nth-child(3) { animation-delay: 0.4s; }
    @keyframes globalLoadingSpin { to { transform: rotate(360deg); } }
    @keyframes globalLoadingPop { from { transform: scale(0.9); opacity: 0; } to { transform: scale(1); opacity: 1; } }
    @keyframes globalLoadingDots { 0%, 20% { opacity: 0; } 50% { opacity: 1; } 100% { opacity: 0; } }
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
<!-- NEW (this adjustment): export result screen ("Export Successful" / "Export Failed") — the same one
     admin_student_list.php / admin_company_list.php show after an export -->
<style>
        #globalResultOverlay {
            position: fixed;
            inset: 0;
            z-index: 20001;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 20px;
            box-sizing: border-box;
            background: rgba(238, 241, 246, 0.92);
            opacity: 1;
            visibility: visible;
            transition: opacity 0.35s ease, visibility 0.35s ease;
        }
        #globalResultOverlay.hidden {
            opacity: 0;
            visibility: hidden;
            pointer-events: none;
        }
        .global-result-box {
            display: flex;
            flex-direction: column;
            align-items: center;
            gap: 14px;
            max-width: 460px;
            width: 100%;
            text-align: center;
            animation: globalLoadingPop 0.35s ease;
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
        }
        .global-result-icon {
            width: 64px;
            height: 64px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 28px;
            color: #fff;
        }
        #globalResultOverlay.is-success .global-result-icon { background: var(--grid-green, #2C5A2C); }
        #globalResultOverlay.is-error .global-result-icon { background: var(--grid-red, #A02A2A); }
        .global-result-title {
            font-size: 14px;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.6px;
            margin: 0;
        }
        #globalResultOverlay.is-success .global-result-title { color: var(--grid-green, #2C5A2C); }
        #globalResultOverlay.is-error .global-result-title { color: var(--grid-red, #A02A2A); }
        .global-result-message {
            font-size: 13px;
            line-height: 1.5;
            color: var(--grid-navy, #1B2A4A);
            margin: 0;
            white-space: pre-line;
            max-height: 40vh;
            overflow-y: auto;
            word-break: break-word;
        }
        .global-result-ok {
            padding: 10px 28px;
            border-radius: 0;
            font-weight: 600;
            cursor: pointer;
            border: 1px solid var(--grid-navy, #1B2A4A);
            background: var(--grid-navy, #1B2A4A);
            color: #fff;
            text-transform: uppercase;
            letter-spacing: 0.4px;
            font-size: 12px;
            transition: opacity 0.2s;
        }
        .global-result-ok:hover { opacity: 0.88; }
</style>
<!-- NEW (this adjustment): button tooltips can now hold a short description, so they may wrap onto a second line -->
<style>
    #cvBtnTip { white-space: normal; max-width: 300px; text-align: center; }
</style>
</head>
<body>
<!-- NEW (this adjustment): loading page — same markup as the other admin pages -->
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
       • this page had no loading page at all — it now has the same one the
         other admin pages use (same markup, same look).
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
    var OWN_LOAD_HIDE  = false;    // does this page already hide the loading page itself once loaded?
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

<!-- PDF GENERATION PROGRESS OVERLAY (Save as PDF, both reports & eval) -->
<div class="pdf-gen-overlay" id="pdfGenOverlay">
    <div class="pdf-gen-box">
        <div class="pdf-gen-icon"><i class="fas fa-file-pdf" style="color:#1B2A4A;"></i></div>
        <div class="pdf-gen-title">Generating PDF...</div>
        <div class="pdf-gen-msg" id="pdfGenMsg">Rendering document, please wait.</div>
        <div class="pdf-gen-spinner"></div>
    </div>
</div>
<!-- PRINT PREPARATION PROGRESS OVERLAY -->
<div class="pdf-gen-overlay" id="printGenOverlay">
    <div class="pdf-gen-box">
        <div class="pdf-gen-icon"><i class="fas fa-print" style="color:#4A7A3A;"></i></div>
        <div class="pdf-gen-title">Preparing to Print...</div>
        <div class="pdf-gen-msg" id="printGenMsg">Rendering document for printing, please wait.</div>
        <div class="pdf-gen-spinner"></div>
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
        <!-- UPDATED (this adjustment): admin full name + "Administrator" label, same markup as the other admin pages -->
        <div class="sidebar-header-titles">
            <h2 id="sidebarTitle"><?= htmlspecialchars($adminFullName ?? '') ?></h2>
            <span class="sidebar-role-label">Administrator</span>
        </div>
        <button id="toggleBtn" style="background:none;border:none;color:white;cursor:pointer;font-size:20px;"><i class="fas fa-bars"></i></button>
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
        <a href="company_validation.php" style="position:relative;">
            <i class="fas fa-building"></i><span class="link-text">Company Requirements</span>
            <?php if ($moa_pending_count > 0): ?>
                <span class="sidebar-badge-moa" id="sidebarMoaBadge"><?= $moa_pending_count ?></span>
            <?php else: ?>
                <span class="sidebar-badge-moa" id="sidebarMoaBadge" style="display:none"><?= $moa_pending_count ?></span>
            <?php endif; ?>
        </a>
        <a href="monitoring.php" style="position:relative;"><i class="fas fa-users-cog"></i><span class="link-text">Manage Accounts</span><!-- NEW (this adjustment): Email Recovery Requests indicator — same badge look as the application-request badge --><span class="sidebar-badge-app sidebar-badge-recovery" id="sidebarRecoveryBadge"<?= $recovery_pending_count > 0 ? '' : ' style="display:none"' ?>><?= (int)$recovery_pending_count ?></span></a>
        <a href="admin_monitoring_dashboard.php" class="active">
            <i class="fas fa-chart-line"></i>
            <span class="link-text">Monitoring Dashboard</span>
            <!-- NOTE: Admin grading of weekly reports has been removed entirely,
                 so the previous "ungraded reports" counter/query that fed this
                 sidebar badge has been removed. -->
        </a>
        <a href="admin_final_grades.php"><i class="fas fa-graduation-cap"></i><span class="link-text">Final Grades</span></a>
        <a href="system_setting.php" ><i class="fas fa-gear"></i><span class="link-text">System Setting</span></a>
    </div>
    <div class="logout-link">
        <a href="admin_login.php?logout=1"><i class="fas fa-sign-out-alt"></i><span class="link-text">Logout</span></a>
    </div>
</div>

<!-- MAIN -->
<div class="main-content">

    <!-- PAGE HEADER -->
    <div class="page-header">
        <div>
            <h2><?= htmlspecialchars($company_name ?? '') ?> — Student Reports</h2>
            <div class="page-header-sub" id="headerSub"></div>
        </div>
        <div class="page-header-right">
            <?php if ($att_exists): ?>
            <div class="view-toggle-wrap" title="Switch between Reports and Attendance views">
                <button class="view-toggle-btn active" id="btnReportsView" onclick="switchView('reports')"> Reports</button>
                <button class="view-toggle-btn" id="btnAttView" onclick="switchView('attendance')"> Attendance</button>
            </div>
            <?php endif; ?>
            <a href="admin_monitoring_dashboard.php" class="back-btn">← Back to Dashboard</a>
        </div>
    </div>

    <!-- REPORTS PANEL -->
    <div id="reportsPanelWrap">

        <!-- Search bar + simple total count (replaces the previous 3-card stats
             row entirely — a single lightweight count now sits beside the
             search input). The id="statTotal" element is still kept in sync
             live by silentPoll()/applyPollUpdates() exactly as before, just
             rendered as plain text instead of a stat card. -->
        <div class="controls">
            <span class="total-count-simple"><i class="fas fa-users" style="color:#6b7280;"></i>&nbsp;Total: <span id="statTotal"><?= $total ?></span></span>
            <input type="text" id="searchInput" placeholder="Search student...">
        </div>

        <!-- Student cards: restyled to match company_reports.php's "Field ops
             grid" .student-card layout (sharp corners, color-coded left
             border, icon-only action buttons with hover tooltips), while
             still showing the same underlying data as before.
             NEW: the "Last submitted:" text (.sc-last) now also gets an
             explicit lastsub-green / lastsub-red / lastsub-neutral class
             based on this student's submission_status for the currently
             selected week — green when up to date, red when behind,
             neutral for weeks that haven't happened yet. -->
        <?php if ($total === 0): ?>
        <!-- NEW (this adjustment): the company has no rows in ojt_assignments
             at all (no student has ever been registered/deployed to it), so
             show an explicit message instead of leaving a blank area under
             the search bar. -->
        <div class="no-students-empty" id="noStudentsEmptyState">
            <i class="fas fa-user-slash"></i>
            <span>This company has no registered OJT student yet.</span>
        </div>
        <?php else: ?>
        <div class="cards" id="cardsContainer">
        <?php foreach ($rows as $row):
            $sid        = $row['student_id'];
            $status     = $row['submission_status'];
            $s_class    = strtolower(str_replace(' ', '-', $status));
            $evalDone   = !empty($row['eval_submitted_at']);
            $student_label = htmlspecialchars($row['first_name'] . " " . $row['last_name']);
            $course_label  = htmlspecialchars($row['course'] ?? '');
            $total_reports_for_student = (int)($row['total_reports'] ?? 0);
            $last_submitted_label = $row['last_submitted_at']
                ? 'Last submitted: ' . date("M d, Y", strtotime($row['last_submitted_at'] ?? ''))
                : 'No reports yet';
            $last_submitted_color_class = $row['last_submitted_at'] ? lastSubmittedColorClass($status) : 'lastsub-neutral';
            $evalIconClass = 'icon-btn' . ($evalDone ? ' eval-done' : '');
            $evalIcon      = $evalDone ? 'fa-star' : 'fa-clipboard-list';
            $evalTooltip   = $evalDone ? 'View Training Plan' : 'Training Plan Not Yet Submitted';
        ?>
        <div class="card searchable <?= $s_class ?>" id="card-<?= $sid ?>"
             onclick="openLibrary(<?= $sid ?>, '<?= $student_label ?>', '<?= htmlspecialchars(addslashes($row['course'] ?? '')) ?>')">
            <div class="sc-info" style="width:100%;">
                <div style="display:flex;align-items:center;gap:14px;flex-wrap:wrap;" id="cardtop-<?= $sid ?>">
                    <div style="flex:1;min-width:0;">
                        <div class="sc-name"><?= $student_label ?></div>
                        <div class="sc-course"><?= $course_label ?></div>
                        <div class="sc-last <?= $last_submitted_color_class ?>" id="sclast-<?= $sid ?>"><?= htmlspecialchars($last_submitted_label ?? '') ?></div>
                    </div>
                    <div class="sc-badges" id="badges-<?= $sid ?>">
                        <button type="button" class="<?= $evalIconClass ?>" id="viewplan-<?= $sid ?>"
                                data-tooltip="<?= $evalTooltip ?>"
                                style="<?= $evalDone ? '' : 'display:none' ?>"
                                onclick="event.stopPropagation(); openLibrary(<?= $sid ?>, '<?= $student_label ?>', '<?= htmlspecialchars(addslashes($row['course'] ?? '')) ?>', true)">
                            <i class="fas <?= $evalIcon ?>"></i>
                        </button>
                        <button type="button" class="icon-btn" data-tooltip="Open Library"
                                onclick="event.stopPropagation(); openLibrary(<?= $sid ?>, '<?= $student_label ?>', '<?= htmlspecialchars(addslashes($row['course'] ?? '')) ?>')">
                            <i class="fas fa-book-open"></i>
                        </button>
                        <span class="sc-arrow">›</span>
                    </div>
                </div>
                <?php if ($evalDone): ?>
                <div class="sc-eval-strip" id="eval-strip-<?= $sid ?>">
                    <span class="sc-eval-label">Performance Evaluation Submitted</span>
                    <span class="sc-eval-date">Submitted <?= date("M d, Y", strtotime($row['eval_submitted_at'] ?? '')) ?></span>
                </div>
                <?php else: ?>
                <div class="sc-eval-strip" id="eval-strip-<?= $sid ?>" style="display:none;"></div>
                <?php endif; ?>
            </div>
        </div>
        <?php endforeach; ?>
        </div>
        <?php endif; ?>
    </div><!-- end reportsPanelWrap -->

    <!-- ATTENDANCE SUMMARY PANEL -->
    <div id="attSummaryPanel">
        <div class="att-panel-header">
            <h3> Monthly Attendance Summary — <?= htmlspecialchars($company_name ?? '') ?></h3>
            <div class="att-month-nav">
                <button id="attPrevBtn" onclick="changeAttMonth(-1)">&#8592; Prev</button>
                <span class="att-month-label" id="attMonthLabel">—</span>
                <button id="attNextBtn" onclick="changeAttMonth(1)">&#8594; Next</button>
                <input type="month" id="attMonthInput" class="att-month-input" onchange="goToAttMonth(this.value)" title="Jump to month">
                <a id="attExportBtn" href="#" onclick="return doAttExport()" class="att-export-btn"><i class="fas fa-file-csv"></i> Export CSV</a>
            </div>
        </div>
        <div class="att-legend">
            <span class="att-legend-item"><span class="att-legend-dot" style="background:#EEF4EA;border:1px solid #4A7A3A;"></span><span style="color:#2C5A2C;">P — Present</span></span>
            <span class="att-legend-item"><span class="att-legend-dot" style="background:#FBF6E3;border:1px solid #A0850A;"></span><span style="color:#7A6508;">I — Incomplete</span></span>
            <span class="att-legend-item"><span class="att-legend-dot" style="background:#F8ECEB;border:1px solid #A02A2A;"></span><span style="color:#A02A2A;">A — Absent</span></span>
            <span class="att-legend-item"><span class="att-legend-dot" style="background:#EEF1F6;border:1px solid #8A93A8;"></span><span style="color:#5A6272;">O — Day Off</span></span>
            <!-- NEW: synced with attendance_management.php's Monthly Attendance Summary legend -->
            <span class="att-legend-item"><i class="fas fa-play-circle att-mark-start" style="font-size:10px;"></i><span style="color:#2C5A2C;">OJT Start</span></span>
            <span class="att-legend-item"><i class="fas fa-stop-circle att-mark-end" style="font-size:10px;"></i><span style="color:#A02A2A;">OJT End</span></span>
            <span class="att-legend-item"><span class="att-legend-dot" style="background:#FAFBFD;border:1px solid #C3CADA;"></span><span style="color:#5A6272;">Blank — Before first attendance</span></span>
        </div>
        <div id="attTableWrap">
            <div class="att-loading"><div class="att-spinner"></div> Loading attendance data…</div>
        </div>
        <div class="att-chart-box" id="attChartBox" style="display:none;">
            <div class="att-chart-nav">
                <button class="att-chart-nav-btn" id="attChartPrevBtn" onclick="changeChartPage(-1)" disabled>&#8592; Prev</button>
                <div class="att-chart-nav-title" id="attChartNavTitle"> Attendance Overview</div>
                <button class="att-chart-nav-btn" id="attChartNextBtn" onclick="changeChartPage(1)">Next &#8594;</button>
            </div>
            <h3>Monthly Attendance Overview</h3>
            <p class="att-chart-subtitle">Monthly totals — Present, Incomplete, Absent (weekdays only, up to today)</p>
            <div class="att-chart-legend">
                <span class="att-chart-legend-item"><span class="att-chart-legend-dot" style="background:#4A7A3A;"></span>Present</span>
                <span class="att-chart-legend-item"><span class="att-chart-legend-dot" style="background:#A0850A;"></span>Incomplete</span>
                <span class="att-chart-legend-item"><span class="att-chart-legend-dot" style="background:#A02A2A;"></span>Absent</span>
            </div>
            <div class="att-chart-canvas-wrap"><canvas id="attBarChart"></canvas></div>
        </div>
    </div><!-- end attSummaryPanel -->

</div><!-- end main-content -->

<!-- STUDENT REPORT LIBRARY — FULL-SCREEN VIEW -->
<div class="lib-overlay" id="libOverlay">
    <div class="lib-doc-toolbar">
        <div class="lib-doc-toolbar-left">
            <i class="fas fa-book-open" style="color:rgba(255,255,255,0.7);"></i>
            <span class="lib-doc-toolbar-title" id="libStudentName">Student Name</span>
            <span class="lib-doc-toolbar-course" id="libStudentCourse"></span>
        </div>
        <div class="lib-doc-toolbar-right">
            <button class="eval-tbtn eval-tbtn-primary" id="libPrintBtn" onclick="libPrintReport()" disabled><i class="fas fa-print"></i> Print</button>
            <button class="eval-tbtn eval-tbtn-primary" id="libSavePDFBtn" onclick="libSaveReportPDF()" disabled><i class="fas fa-file-pdf"></i> Save as PDF</button>
            <button class="eval-tbtn lib-tbtn-comment" id="libCommentToggleBtn" onclick="toggleCommentDrawer()" disabled><i class="fas fa-comment-alt"></i> Comment</button>
            <button class="eval-tbtn eval-tbtn-close" onclick="closeLibrary()">&#x2715; Close</button>
        </div>
    </div>

    <!-- Summary bar — weekly report compliance cards: Total (expected,
         one report per week from OJT start to today), Submitted
         (actual submitted count), and Not Submitted (Missed) — mirrors
         company_reports.php's Student Library summary strip. -->
    <div class="lib-summary-bar">
        <div class="lib-stats-row">
            <div class="lib-stat-card">
                <div class="lib-stat-icon total"><i class="fas fa-calendar-week"></i></div>
                <div class="lib-stat-text">
                    <div class="lib-stat-num" id="libStatTotalVal">-</div>
                    <div class="lib-stat-lbl2">Total</div>
                </div>
            </div>
            <div class="lib-stat-card">
                <div class="lib-stat-icon submitted"><i class="fas fa-check"></i></div>
                <div class="lib-stat-text">
                    <div class="lib-stat-num submitted-num" id="libStatSubmittedVal">-</div>
                    <div class="lib-stat-lbl2">Submitted</div>
                </div>
            </div>
            <div class="lib-stat-card">
                <div class="lib-stat-icon missed"><i class="fas fa-xmark"></i></div>
                <div class="lib-stat-text">
                    <div class="lib-stat-num missed-num" id="libStatMissedVal">-</div>
                    <div class="lib-stat-lbl2">Not Submitted</div>
                </div>
            </div>
        </div>
    </div>

    <div class="lib-doc-body">
        <div class="lib-split-body" id="libSplitBody">
            <div class="lib-list-pane" id="libListPane">
                <div class="lib-loading"><div class="spinner"></div> Loading...</div>
            </div>
            <div class="lib-detail-pane" id="libDetailPane">
                <div class="lib-detail-empty" id="libDetailEmpty">
                    <i class="fas fa-hand-point-left"></i>
                    <span>Select a report from the list</span>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- COMMENT DRAWER (replaces the removed grading drawer) -->
<div class="lib-drawer-backdrop" id="libCommentDrawerBackdrop" onclick="toggleCommentDrawer(false)"></div>
<div class="lib-drawer" id="libCommentDrawer">
    <div class="lib-drawer-header">
        <span><i class="fas fa-comment-alt"></i> Report Comment</span>
        <button onclick="toggleCommentDrawer(false)"><i class="fas fa-times"></i></button>
    </div>
    <div class="lib-drawer-body" id="libCommentDrawerBody">
        <div class="lib-drawer-empty">Select a report to view or add a comment.</div>
    </div>
</div>

<!-- EVALUATION VIEW MODAL (read-only) -->
<div id="evalOverlay" class="eval-overlay">
    <div class="eval-doc-toolbar">
        <div class="eval-doc-toolbar-left">
            <i class="fas fa-clipboard-list" style="color:rgba(255,255,255,0.7);"></i>
            <span class="eval-doc-toolbar-title" id="evalToolbarTitle">OJT/Internship Training Plan</span>
        </div>
        <div class="eval-doc-toolbar-right">
            <button class="eval-tbtn eval-tbtn-primary" id="evalPrintBtn" onclick="evalPrintDoc()" style="display:none;"><i class="fas fa-print"></i> Print</button>
            <button class="eval-tbtn eval-tbtn-primary" id="evalSavePDFBtn" onclick="evalSavePDF()" style="display:none;"><i class="fas fa-file-pdf"></i> Save as PDF</button>
            <button class="eval-tbtn eval-tbtn-close" onclick="closeEvalModal()">&#x2715; Close</button>
        </div>
    </div>
    <div class="eval-doc-canvas" id="evalDocCanvas"></div>
</div>

<!-- TOAST -->
<div class="toast" id="toast"></div>
<div class="refresh-indicator" id="refreshIndicator"><span class="refresh-dot"></span> Syncing...</div>

<iframe id="evalPdfHiddenFrame" style="position:fixed;left:-9999px;top:-9999px;width:900px;height:1200px;border:none;visibility:hidden;" tabindex="-1"></iframe>
<iframe id="evalPrintHiddenFrame" style="position:fixed;left:-9999px;top:-9999px;width:900px;height:1200px;border:none;visibility:hidden;" tabindex="-1"></iframe>

<script>
const COMPANY_ID   = <?= $company_id ?>;
const CURRENT_WEEK = <?= json_encode($current_week_start) ?>;
let _activeWeek     = <?= json_encode($week_start) ?>;
let _pollBusy       = false;
let _lastRowsJson   = '';
let _currentView    = 'reports';

/* ── LIBRARY STATE ── */
let _currentStudentId     = null;
let _currentStudentName   = '';
let _currentStudentCourse = '';
let _allReports            = [];
let _activeReportId        = null;
let _currentHasEval         = false;
/* NEW: weekly report compliance counters returned by the
   student_library=1 endpoint (see computeWeeklyReportStats() on the
   server) — { total_expected, submitted, missed } — used to populate
   the Total / Submitted / Not Submitted cards in the library summary
   bar via renderLibrarySummary(). */
let _currentReportStats     = null;

/* ═══ VIEW SWITCHING ═══ */
function switchView(view) {
    _currentView = view;
    const isReports = (view === 'reports');
    document.getElementById('reportsPanelWrap').style.display = isReports ? '' : 'none';
    document.getElementById('attSummaryPanel').classList.toggle('visible', !isReports);
    document.getElementById('btnReportsView').classList.toggle('active', isReports);
    document.getElementById('btnAttView').classList.toggle('active', !isReports);
    const headerSub = document.getElementById('headerSub');
    if (isReports) {
        headerSub.textContent = '';
    } else {
        headerSub.textContent = 'Monthly attendance records for all assigned students';
        if (!_attLoaded) loadAttendanceSummary(_attCurrentMonth);
    }
    localStorage.setItem('adminReportsView_<?= $company_id ?>', view);
}
(function() {
    const saved = localStorage.getItem('adminReportsView_<?= $company_id ?>');
    if (saved === 'attendance') setTimeout(() => switchView('attendance'), 50);
})();

/* ═══ ATTENDANCE SUMMARY ═══ */
let _attLoaded = false, _attCurrentMonth = '', _attMonthMin = '', _attMonthMax = '';
let _attAllStats = [], _attChartPage = 0;
const ATT_CHART_PAGE_SIZE = 6;
let _attBarChart = null;

function loadAttendanceSummary(month) {
    _attLoaded = false;
    const wrap = document.getElementById('attTableWrap');
    wrap.innerHTML = '<div class="att-loading"><div class="att-spinner"></div> Loading attendance data…</div>';
    document.getElementById('attChartBox').style.display = 'none';

    fetch('admin_reports.php?company_id=' + COMPANY_ID + '&attendance_summary=1&att_month=' + encodeURIComponent(month))
        .then(r => r.json())
        .then(data => {
            if (data.error) { wrap.innerHTML = '<div class="att-empty">' + escH(data.error) + '</div>'; return; }
            _attLoaded = true;
            _attCurrentMonth = data.att_month;
            _attMonthMin = data.att_month_min;
            _attMonthMax = data.att_month_max;
            _attAllStats = data.monthly_stats || [];
            _attChartPage = Math.max(0, Math.ceil(_attAllStats.length / ATT_CHART_PAGE_SIZE) - 1);
            document.getElementById('attMonthLabel').textContent = formatMonthLabel(_attCurrentMonth);
            document.getElementById('attMonthInput').value = _attCurrentMonth;
            document.getElementById('attPrevBtn').disabled = (_attCurrentMonth <= _attMonthMin);
            document.getElementById('attNextBtn').disabled = (_attCurrentMonth >= _attMonthMax);
            renderAttTable(data);
            renderAttChart();
        })
        .catch(() => { wrap.innerHTML = '<div class="att-empty">Failed to load attendance data.</div>'; });
}
function formatMonthLabel(ym) {
    if (!ym) return '—';
    const [y, m] = ym.split('-');
    const months = ['Jan','Feb','Mar','Apr','May','Jun','Jul','Aug','Sep','Oct','Nov','Dec'];
    return (months[parseInt(m,10)-1] || m) + ' ' + y;
}
function renderAttTable(data) {
    const wrap = document.getElementById('attTableWrap');
    if (!data.table_rows || data.table_rows.length === 0) {
        wrap.innerHTML = '<div class="att-empty">No students assigned to this company yet.</div>';
        return;
    }
    let html = '<div class="att-table-wrap"><table class="att-table"><thead><tr>';
    html += '<th class="att-name-col">Student</th>';
    data.date_headers.forEach(dh => { html += `<th class="${dh.wknd ? 'wknd-col' : ''}">${escH(dh.label)}</th>`; });
    html += '</tr></thead><tbody>';
    data.table_rows.forEach(row => {
        // NEW: OJT Start / End tooltip on the name cell — same as attendance_management.php's Monthly Attendance Summary
        const nameTip = 'OJT Start: ' + (row.ojt_start || '—') + ' · OJT End: ' + (row.ojt_end || '—');
        html += `<tr><td class="att-name-td" title="${escH(nameTip)}">${escH(row.name)}</td>`;
        row.cells.forEach(cell => {
            // NEW: render the OJT Start / End indicator icons on the matching day,
            // same markers shown in attendance_management.php's table.
            let markHtml = '';
            if (cell.mark === 'start') {
                markHtml = '<i class="fas fa-play-circle att-mark att-mark-start" title="OJT Start"></i>';
            } else if (cell.mark === 'end') {
                markHtml = '<i class="fas fa-stop-circle att-mark att-mark-end" title="OJT End"></i>';
            } else if (cell.mark === 'end_est') {
                markHtml = '<i class="fas fa-stop-circle att-mark att-mark-end att-mark-est" title="OJT End (estimated)"></i>';
            } else if (cell.mark === 'both') {
                markHtml = '<i class="fas fa-play-circle att-mark att-mark-start" title="OJT Start"></i><i class="fas fa-stop-circle att-mark att-mark-end" title="OJT End"></i>';
            } else if (cell.mark === 'both_est') {
                markHtml = '<i class="fas fa-play-circle att-mark att-mark-start" title="OJT Start"></i><i class="fas fa-stop-circle att-mark att-mark-end att-mark-est" title="OJT End (estimated)"></i>';
            }
            const cellCls = (cell.class || '') + (markHtml ? ' att-has-mark' : '');
            html += `<td class="${escH(cellCls.trim())}">${markHtml}${escH(cell.val)}</td>`;
        });
        html += '</tr>';
    });
    html += '</tbody></table></div>';
    wrap.innerHTML = html;
}
function renderAttChart() {
    if (!_attAllStats.length) return;
    const box = document.getElementById('attChartBox');
    box.style.display = 'block';
    const totalPages = Math.max(1, Math.ceil(_attAllStats.length / ATT_CHART_PAGE_SIZE));
    if (_attChartPage >= totalPages) _attChartPage = totalPages - 1;
    if (_attChartPage < 0) _attChartPage = 0;
    const slice = _attAllStats.slice(_attChartPage * ATT_CHART_PAGE_SIZE, (_attChartPage + 1) * ATT_CHART_PAGE_SIZE);
    const labels = slice.map(s => s.label);
    const present = slice.map(s => s.present);
    const incomplete = slice.map(s => s.incomplete);
    const absent = slice.map(s => s.absent);
    const startLabel = slice[0]?.label || '—';
    const endLabel = slice[slice.length - 1]?.label || '—';
    const navTitle = slice.length > 1 ? startLabel + ' – ' + endLabel : startLabel;
    document.getElementById('attChartNavTitle').textContent = ' ' + navTitle + (totalPages > 1 ? '  (' + (_attChartPage+1) + '/' + totalPages + ')' : '');
    document.getElementById('attChartPrevBtn').disabled = (_attChartPage <= 0);
    document.getElementById('attChartNextBtn').disabled = (_attChartPage >= totalPages - 1);
    const canvas = document.getElementById('attBarChart');
    if (_attBarChart) { _attBarChart.destroy(); _attBarChart = null; }
    _attBarChart = new Chart(canvas, {
        type: 'bar',
        data: { labels: labels, datasets: [
            { label: 'Present', data: present, backgroundColor: '#4A7A3A', borderRadius: 0, borderSkipped: false },
            { label: 'Incomplete', data: incomplete, backgroundColor: '#A0850A', borderRadius: 0, borderSkipped: false },
            { label: 'Absent', data: absent, backgroundColor: '#A02A2A', borderRadius: 0, borderSkipped: false }
        ]},
        options: {
            responsive: true, maintainAspectRatio: false,
            plugins: { legend: { display: false }, tooltip: { callbacks: { label: item => ' ' + item.dataset.label + ': ' + item.parsed.y } } },
            scales: {
                x: { title: { display: true, text: 'Month', font: { size: 12 }, color: '#5A6272' }, ticks: { font: { size: 12, weight: '600' }, color: '#1B2A4A' }, grid: { display: false } },
                y: { beginAtZero: true, ticks: { stepSize: 1, precision: 0, font: { size: 11 }, color: '#5A6272' }, grid: { color: '#DDE2EC' }, border: { color: '#C3CADA' }, title: { display: true, text: 'Total Records', font: { size: 12 }, color: '#5A6272' } }
            }
        }
    });
}
function changeAttMonth(delta) {
    if (!_attCurrentMonth) return;
    const [y, m] = _attCurrentMonth.split('-').map(Number);
    const next = new Date(y, m - 1 + delta, 1);
    const ym = next.getFullYear() + '-' + String(next.getMonth() + 1).padStart(2, '0');
    if (ym < _attMonthMin || ym > _attMonthMax) return;
    loadAttendanceSummary(ym);
}
function goToAttMonth(ym) { if (ym) loadAttendanceSummary(ym); }
function doAttExport() {
    if (!_attCurrentMonth) { showToast("Load attendance data first."); return false; }
    /* UPDATED (this adjustment): the same export loading flow as admin_student_list.php /
       admin_company_list.php. The button shows "Preparing...", the loading page shows
       "Preparing export", and the CSV is fetched in the background so the page knows whether
       the export really worked; then the file downloads and the "Export Successful" (or
       "Export Failed") result screen is shown. The request itself is exactly the same as before. */
    var btn = document.getElementById('attExportBtn');
    if (btn && btn.getAttribute('data-exporting') === '1') return false;          // already exporting
    var origHtml = btn ? btn.innerHTML : '';
    if (btn) { btn.setAttribute('data-exporting', '1'); btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Preparing...'; btn.style.pointerEvents = 'none'; }
    var ov = document.getElementById('globalLoadingOverlay'), lbl = document.getElementById('globalLoadingLabel');
    if (lbl) lbl.textContent = 'Preparing export';
    if (ov) ov.classList.remove('hidden');
    function finishExportFeedback() {
        if (btn) { btn.innerHTML = origHtml; btn.style.pointerEvents = ''; btn.removeAttribute('data-exporting'); }
        if (ov) ov.classList.add('hidden');
        if (lbl) lbl.textContent = 'Loading';
    }
    var url = "admin_reports.php?company_id=" + COMPANY_ID + "&export_att_csv=1&att_month=" + encodeURIComponent(_attCurrentMonth);
    fetch(url, { method: 'GET', headers: { 'X-Requested-With': 'XMLHttpRequest' }, credentials: 'same-origin', cache: 'no-store' })
        .then(function (response) {
            var contentType = (response.headers.get('Content-Type') || '').toLowerCase();
            if (response.ok && contentType.indexOf('text/csv') !== -1) {
                var disposition = response.headers.get('Content-Disposition') || '';
                var nameMatch = disposition.match(/filename\*?=(?:UTF-8'')?"?([^";]+)"?/i);
                var fileName = nameMatch ? decodeURIComponent(nameMatch[1]) : 'attendance_export.csv';
                return response.blob().then(function (blob) {
                    if (!blob || blob.size === 0) throw new Error('The server returned an empty file.');
                    return { blob: blob, fileName: fileName };
                });
            }
            return response.text().then(function (text) {
                var msg = '';
                try { var data = JSON.parse(text); if (data && data.message) msg = data.message; } catch (e) {}
                if (!msg) {
                    msg = response.ok
                        ? 'The server did not return a CSV file. Your session may have expired — please refresh the page and try again.'
                        : 'The server responded with an error (' + response.status + '). Please try again.';
                }
                throw new Error(msg);
            });
        })
        .then(function (file) {
            var blobUrl = URL.createObjectURL(file.blob);
            var link = document.createElement('a');
            link.href = blobUrl; link.download = file.fileName; link.style.display = 'none';
            document.body.appendChild(link); link.click();
            setTimeout(function () { URL.revokeObjectURL(blobUrl); link.remove(); }, 1000);
            finishExportFeedback();
            showGlobalResult('success', 'Export Successful', 'Your CSV file "' + file.fileName + '" has been downloaded.', 3000);
        })
        .catch(function (err) {
            finishExportFeedback();
            var detail = (err && err.message && err.message !== 'Failed to fetch')
                ? err.message
                : 'Could not reach the server. Please check your connection and try again.';
            showGlobalResult('error', 'Export Failed', detail, 0);
        });
    return false;
}
function changeChartPage(delta) { _attChartPage += delta; renderAttChart(); }
(function() {
    const now = new Date();
    _attCurrentMonth = now.getFullYear() + '-' + String(now.getMonth() + 1).padStart(2, '0');
})();

/* ═══ SIDEBAR ═══ */
document.getElementById('toggleBtn').addEventListener('click', () => {
    const sb = document.getElementById('sidebar');
    sb.classList.toggle('collapsed');
    const mc = document.querySelector('.main-content');
    mc.style.marginLeft = sb.classList.contains('collapsed') ? '80px' : '260px';
    mc.style.width = sb.classList.contains('collapsed') ? 'calc(100% - 80px)' : 'calc(100% - 260px)';
});

/* ═══ SEARCH FILTER ═══ */
function applyFilters() {
    const q = document.getElementById('searchInput').value.toLowerCase();
    document.querySelectorAll('.searchable').forEach(card => {
        card.style.display = card.innerText.toLowerCase().includes(q) ? '' : 'none';
    });
}
document.getElementById('searchInput').addEventListener('keyup', applyFilters);

/* ═══ POLL ═══ */
function silentPoll() {
    if (_pollBusy) return;
    _pollBusy = true;
    const ind = document.getElementById('refreshIndicator');
    ind.classList.add('show');
    fetch('admin_reports.php?company_id=' + COMPANY_ID + '&poll=1&week=' + encodeURIComponent(_activeWeek))
        .then(r => r.json())
        .then(data => {
            const rowsJson = JSON.stringify(data.rows);
            if (rowsJson !== _lastRowsJson) { _lastRowsJson = rowsJson; applyPollUpdates(data.rows); }
        })
        .catch(() => {})
        .finally(() => { _pollBusy = false; setTimeout(() => ind.classList.remove('show'), 800); });
}
/* NEW: mirrors the server-side lastSubmittedColorClass() helper so the
   live poll updates below can keep the "Last submitted:" text color
   in sync (green = up to date with the active week, red = behind,
   neutral = week hasn't happened yet) without a page reload. */
function lastSubmittedColorClassJS(status) {
    if (status === 'Submitted') return 'lastsub-green';
    if (status === 'Not Submitted') return 'lastsub-red';
    return 'lastsub-neutral';
}
function applyPollUpdates(rows) {
    let total = 0;
    rows.forEach(row => {
        total++;
        const sid = row.student_id;
        const status = row.submission_status;
        const sclass = status.toLowerCase().replace(/ /g,'-');
        const card = document.getElementById('card-'+sid);
        if (card) { card.classList.remove('submitted','not-submitted','pending'); card.classList.add(sclass); }
        const viewplan = document.getElementById('viewplan-'+sid);
        if (viewplan) {
            viewplan.style.display = row.eval_submitted_at ? '' : 'none';
            viewplan.classList.toggle('eval-done', !!row.eval_submitted_at);
        }
        const stripEl = document.getElementById('eval-strip-'+sid);
        if (stripEl) {
            if (row.eval_submitted_at) {
                stripEl.style.display = '';
                const subDate = new Date(row.eval_submitted_at.replace(' ','T')).toLocaleDateString('en-US',{month:'short',day:'2-digit',year:'numeric'});
                stripEl.innerHTML = '<span class="sc-eval-label">Performance Evaluation Submitted</span><span class="sc-eval-date">Submitted ' + subDate + '</span>';
            } else {
                stripEl.style.display = 'none';
                stripEl.innerHTML = '';
            }
        }
        // NEW: keep the "Last submitted:" text color in sync with the
        // latest polled submission_status for this student's active week.
        const lastEl = document.getElementById('sclast-'+sid);
        if (lastEl) {
            lastEl.classList.remove('lastsub-green','lastsub-red','lastsub-neutral');
            lastEl.classList.add(lastSubmittedColorClassJS(status));
        }
    });
    setText('statTotal', total);
    applyFilters();
}

/* ═══ STUDENT REPORT LIBRARY ═══
   NEW: openLibrary() now accepts an optional 4th argument, autoOpenEval —
   when true (used by the card-level "View Training Plan" button), the
   library data is fetched as usual and, once loaded, if the student
   actually has a submitted evaluation, the read-only Training Plan
   modal is opened automatically on top of the library. This does not
   change the normal click-card / "Open Library" behavior at all. */
function openLibrary(studentId, studentName, course, autoOpenEval) {
    _currentStudentId     = studentId;
    _currentStudentName   = studentName;
    _currentStudentCourse = course || '';
    _allReports            = [];
    _activeReportId         = null;
    _currentHasEval         = false;
    _currentReportStats     = null;

    document.getElementById('libStudentName').textContent   = studentName;
    document.getElementById('libStudentCourse').textContent = course || '';
    resetLibSummaryStats();

    document.getElementById('libListPane').innerHTML   = '<div class="lib-loading"><div class="spinner"></div> Loading...</div>';
    document.getElementById('libDetailPane').innerHTML = '<div class="lib-detail-empty"><i class="fas fa-hand-point-left"></i><span>Select a report from the list</span></div>';

    resetLibReportToolbar();

    toggleCommentDrawer(false);
    document.getElementById('libCommentDrawerBody').innerHTML = '<div class="lib-drawer-empty">Select a report to view or add a comment.</div>';

    document.body.style.overflow = 'hidden';
    document.getElementById('libOverlay').classList.add('open');

    fetch('admin_reports.php?company_id=' + COMPANY_ID + '&student_library=1&student_id=' + studentId)
        .then(r => r.json())
        .then(data => {
            if (data.error) {
                document.getElementById('libListPane').innerHTML = '<div class="lib-list-empty">' + escH(data.error) + '</div>';
                return;
            }
            _allReports    = data.reports || [];
            _currentHasEval = !!data.has_eval;
            _currentReportStats = data.report_stats || null;

            renderLibrarySummary(_allReports, _currentReportStats);
            renderListPane(_allReports);

            if (_allReports.length > 0) selectReport(_allReports[0].report_id);

            if (autoOpenEval && _currentHasEval) {
                openEvalModal();
            }
        })
        .catch(() => {
            document.getElementById('libListPane').innerHTML = '<div class="lib-list-empty">Network error. Please try again.</div>';
        });
}
function resetLibReportToolbar() {
    const printBtn = document.getElementById('libPrintBtn');
    const pdfBtn   = document.getElementById('libSavePDFBtn');
    const commentBtn = document.getElementById('libCommentToggleBtn');
    if (printBtn) printBtn.disabled = true;
    if (pdfBtn) pdfBtn.disabled = true;
    if (commentBtn) { commentBtn.disabled = true; commentBtn.classList.remove('has-comment'); }
}
/* NEW: resets the Total / Submitted / Not Submitted summary cards back
   to their loading placeholder ('-') — used whenever the library is
   (re)opened for a (new) student, before the student_library=1 fetch
   resolves. Mirrors company_reports.php's resetLibSummaryStats(). */
function resetLibSummaryStats() {
    const totalEl     = document.getElementById('libStatTotalVal');
    const submittedEl = document.getElementById('libStatSubmittedVal');
    const missedEl     = document.getElementById('libStatMissedVal');
    if (totalEl)     totalEl.textContent     = '-';
    if (submittedEl) submittedEl.textContent = '-';
    if (missedEl)     missedEl.textContent   = '-';
}
function closeLibrary() {
    document.getElementById('libOverlay').classList.remove('open');
    toggleCommentDrawer(false);
    document.body.style.overflow = '';
}
/* Populates the Total / Submitted / Not Submitted summary cards.
   `reports` is the student's report list (used as a fallback so the
   Submitted count is never wrong even if `stats` wasn't returned);
   `stats` is the { total_expected, submitted, missed } payload from
   computeWeeklyReportStats() on the server. Total = one report
   expected per week from the start of OJT through today; Missed =
   Total - Submitted, floored at 0. Mirrors company_reports.php's
   renderLibrarySummary() (excluding Wrong Document rows from the
   Submitted count, same as the server-side calculation). */
function renderLibrarySummary(reports, stats) {
    const submittedCount = (reports || []).filter(r => r.remark !== 'Wrong Document').length;
    const totalExpected  = (stats && typeof stats.total_expected === 'number')
        ? stats.total_expected
        : submittedCount;
    const missedCount    = (stats && typeof stats.missed === 'number')
        ? stats.missed
        : Math.max(0, totalExpected - submittedCount);

    const totalEl     = document.getElementById('libStatTotalVal');
    const submittedEl = document.getElementById('libStatSubmittedVal');
    const missedEl     = document.getElementById('libStatMissedVal');
    if (totalEl)     totalEl.textContent     = totalExpected;
    if (submittedEl) submittedEl.textContent = submittedCount;
    if (missedEl)     missedEl.textContent   = missedCount;
}
function renderListPane(reports) {
    const pane = document.getElementById('libListPane');
    if (!reports || reports.length === 0) {
        pane.innerHTML = '<div class="lib-list-empty">No reports submitted yet.</div>';
        return;
    }
    let html = '';
    reports.forEach(rep => {
        const monTs  = new Date(rep.week_start + 'T00:00:00');
        const friTs  = new Date(rep.week_start + 'T00:00:00');
        friTs.setDate(friTs.getDate() + 4);
        const fmtDs  = d => d.toLocaleDateString('en-US', { month: 'short', day: '2-digit' });
        const fmtD   = d => d.toLocaleDateString('en-US', { month: 'short', day: '2-digit', year: 'numeric' });
        const weekRange = fmtDs(monTs) + ' - ' + fmtD(friTs);
        const subLabel = rep.submitted_at
            ? 'Submitted ' + new Date(rep.submitted_at.replace(' ','T')).toLocaleDateString('en-US',{month:'short',day:'2-digit'})
            : 'Not submitted';
        const isWrong = rep.remark === 'Wrong Document';
        const badge = isWrong ? '<span class="wo-badge" style="background:#fff;color:#A02A2A;">Wrong Doc</span>' : '';
        html += `<div class="lib-list-item" id="list-item-${rep.report_id}" onclick="selectReport(${rep.report_id})">
            <div class="lib-list-week">${weekRange}</div>
            <div class="lib-list-sub" style="display:flex;align-items:center;justify-content:space-between;gap:6px;">
                <span>${subLabel}</span>${badge}
            </div>
        </div>`;
    });
    pane.innerHTML = html;
    if (_activeReportId) {
        const activeItem = document.getElementById('list-item-' + _activeReportId);
        if (activeItem) activeItem.classList.add('active');
    }
}
function selectReport(reportId) {
    _activeReportId = reportId;
    document.querySelectorAll('.lib-list-item').forEach(el => el.classList.remove('active'));
    const listItem = document.getElementById('list-item-' + reportId);
    if (listItem) listItem.classList.add('active');

    const rep = _allReports.find(r => r.report_id == reportId);
    if (!rep) return;
    const hasBlob = !!rep.has_blob;

    const noFileNote = !hasBlob ? `<div style="font-size:0.7rem;color:#5A6272;padding:2px 0;font-weight:700;text-transform:uppercase;letter-spacing:0.4px;">No file attached to this report.</div>` : '';
    const wrongDocNote = rep.remark === 'Wrong Document' ? `<div style="background:#fff;border:1px solid #E4C3C0;border-radius:0;padding:8px 12px;font-size:0.8rem;font-weight:600;color:#A02A2A;margin-bottom:4px;">Marked as Wrong Document.</div>` : '';
    const previewHtml = hasBlob
        ? `<div class="lib-report-preview" id="report-preview-${rep.report_id}"><div class="lib-report-preview-loading"><div class="spinner"></div> Loading preview...</div></div>`
        : `<div class="lib-report-preview"><div class="lib-report-preview-empty"><i class="fas fa-file-circle-xmark"></i><span>No report file to preview.</span></div></div>`;

    document.getElementById('libDetailPane').innerHTML = `
        <div class="lib-detail-body">
            ${noFileNote}${wrongDocNote}
            <div class="lib-preview-section">
                <div class="lib-preview-title"><i class="fas fa-file-lines" style="color:#1B2A4A;margin-right:4px;"></i> Report Preview</div>
                ${previewHtml}
            </div>
        </div>`;

    if (hasBlob) loadReportPreview(rep.report_id);

    const printBtn = document.getElementById('libPrintBtn');
    const pdfBtn   = document.getElementById('libSavePDFBtn');
    if (printBtn) printBtn.disabled = !hasBlob;
    if (pdfBtn)   pdfBtn.disabled   = !hasBlob;
    const commentBtn = document.getElementById('libCommentToggleBtn');
    if (commentBtn) { commentBtn.disabled = false; commentBtn.classList.toggle('has-comment', !!(rep.feedback && rep.feedback.trim() !== '')); }
    renderCommentDrawer(rep);
}
function loadReportPreview(reportId) {
    const container = document.getElementById('report-preview-' + reportId);
    if (!container) return;
    fetch('admin_reports.php?company_id=' + COMPANY_ID + '&view=1&id=' + reportId)
        .then(r => r.json())
        .then(data => {
            if (!document.body.contains(container)) return;
            if (data.error) {
                container.innerHTML = '<div class="lib-report-preview-empty"><i class="fas fa-triangle-exclamation"></i><span>' + escH(data.error) + '</span></div>';
            } else if (data.is_html) {
                container.innerHTML = data.html;
            } else {
                container.innerHTML = '<div class="lib-report-preview-table">' + data.html + '</div>';
            }
        })
        .catch(() => {
            if (document.body.contains(container)) {
                container.innerHTML = '<div class="lib-report-preview-empty"><i class="fas fa-triangle-exclamation"></i><span>Failed to load preview.</span></div>';
            }
        });
}

/* ═══ COMMENT DRAWER ═══ */
function renderCommentDrawer(rep) {
    const body = document.getElementById('libCommentDrawerBody');
    if (!body || !rep) return;
    const feedbackVal = escH(rep.feedback || '');
    const commentDisplay = rep.feedback
        ? `<div class="lib-comment-display">${escH(rep.feedback).replace(/\n/g,'<br>')}</div>`
        : `<div class="lib-comment-display empty">No comment yet.</div>`;
    body.innerHTML = `
        <div class="lib-drawer-section" id="comment-section-${rep.report_id}">
            <div class="lib-drawer-title-row">
                <div class="lib-drawer-title"><i class="fas fa-comment-alt" style="color:#1B2A4A;margin-right:4px;"></i> Comment</div>
            </div>
            <div id="comment-display-${rep.report_id}">${commentDisplay}</div>
            <textarea class="lib-comment-ta" id="comment-ta-${rep.report_id}" placeholder="Write a comment or feedback..." style="display:none;">${feedbackVal}</textarea>
            <div class="lib-form-btns" id="comment-btns-${rep.report_id}">
                <button class="lib-btn-comment-edit" id="comment-edit-btn-${rep.report_id}" onclick="enableCommentEdit(${rep.report_id})"><i class="fas fa-pen"></i> Edit Comment</button>
                <button class="lib-btn-save" id="comment-save-btn-${rep.report_id}" style="display:none;" onclick="saveCommentForReport(${rep.report_id})"><i class="fas fa-save"></i> Save Comment</button>
                <button class="lib-btn-comment-cancel" id="comment-cancel-btn-${rep.report_id}" style="display:none;" onclick="cancelCommentEdit(${rep.report_id})">Cancel</button>
            </div>
        </div>`;
}
function toggleCommentDrawer(forceState) {
    const drawer = document.getElementById('libCommentDrawer');
    const backdrop = document.getElementById('libCommentDrawerBackdrop');
    if (!drawer) return;
    const shouldOpen = (typeof forceState === 'boolean') ? forceState : !drawer.classList.contains('open');
    drawer.classList.toggle('open', shouldOpen);
    if (backdrop) backdrop.classList.toggle('show', shouldOpen);
}
function enableCommentEdit(reportId) {
    document.getElementById('comment-display-' + reportId).style.display = 'none';
    const ta = document.getElementById('comment-ta-' + reportId);
    ta.style.display = ''; ta.focus();
    document.getElementById('comment-edit-btn-' + reportId).style.display = 'none';
    document.getElementById('comment-save-btn-' + reportId).style.display = '';
    document.getElementById('comment-cancel-btn-' + reportId).style.display = '';
}
function cancelCommentEdit(reportId) {
    const rep = _allReports.find(r => r.report_id == reportId);
    document.getElementById('comment-ta-' + reportId).value = rep ? (rep.feedback || '') : '';
    document.getElementById('comment-ta-' + reportId).style.display = 'none';
    document.getElementById('comment-display-' + reportId).style.display = '';
    document.getElementById('comment-edit-btn-' + reportId).style.display = '';
    document.getElementById('comment-save-btn-' + reportId).style.display = 'none';
    document.getElementById('comment-cancel-btn-' + reportId).style.display = 'none';
}
function saveCommentForReport(reportId) {
    const ta = document.getElementById('comment-ta-' + reportId);
    const saveBtn = document.getElementById('comment-save-btn-' + reportId);
    if (!ta) return;
    const comment = ta.value.trim();
    if (saveBtn) { saveBtn.disabled = true; saveBtn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Saving...'; }
    const fd = new FormData();
    fd.append('report_id', reportId);
    fd.append('feedback', comment);
    fetch('admin_reports.php?company_id=' + COMPANY_ID + '&save_comment=1', { method: 'POST', body: fd })
        .then(r => r.json())
        .then(data => {
            if (data.success) {
                showToast(data.message);
                const rep = _allReports.find(r => r.report_id == reportId);
                if (rep) rep.feedback = comment;
                const display = document.getElementById('comment-display-' + reportId);
                if (display) {
                    if (comment) { display.className = 'lib-comment-display'; display.innerHTML = escH(comment).replace(/\n/g,'<br>'); }
                    else { display.className = 'lib-comment-display empty'; display.textContent = 'No comment yet.'; }
                    display.style.display = '';
                }
                if (ta) ta.style.display = 'none';
                document.getElementById('comment-edit-btn-' + reportId).style.display = '';
                if (saveBtn) { saveBtn.style.display = 'none'; saveBtn.disabled = false; saveBtn.innerHTML = '<i class="fas fa-save"></i> Save Comment'; }
                document.getElementById('comment-cancel-btn-' + reportId).style.display = 'none';
                if (_activeReportId == reportId) {
                    const commentBtn = document.getElementById('libCommentToggleBtn');
                    if (commentBtn) commentBtn.classList.toggle('has-comment', !!comment);
                }
            } else {
                showToast(data.message || 'Save failed.');
                if (saveBtn) { saveBtn.disabled = false; saveBtn.innerHTML = '<i class="fas fa-save"></i> Save Comment'; }
            }
        })
        .catch(() => {
            showToast('Network error.');
            if (saveBtn) { saveBtn.disabled = false; saveBtn.innerHTML = '<i class="fas fa-save"></i> Save Comment'; }
        });
}

/* ═══ LIBRARY TOOLBAR: PRINT & SAVE-AS-PDF (active report) ═══ */
function libPrintReport() {
    if (!_activeReportId) return;
    const rep = _allReports.find(r => r.report_id == _activeReportId);
    if (!rep || !rep.has_blob) { showToast('No file to print.'); return; }
    const previewContainer = document.getElementById('report-preview-' + _activeReportId);
    if (!previewContainer) { showToast('Report preview not ready yet.'); return; }

    const iframe = previewContainer.querySelector('iframe');
    if (iframe) {
        const doPrint = function() {
            try { iframe.contentWindow.focus(); iframe.contentWindow.print(); }
            catch (e) {
                const url = 'admin_reports.php?company_id=' + COMPANY_ID + '&viewraw=1&id=' + _activeReportId;
                const w = window.open(url, '_blank');
                if (w) { w.onload = function() { w.focus(); w.print(); }; }
                else { alert('Pop-up blocked. Please allow pop-ups and try again.'); }
            }
        };
        if (iframe.contentDocument && iframe.contentDocument.readyState === 'complete') doPrint();
        else iframe.onload = doPrint;
    } else {
        const tableEl = previewContainer.querySelector('.lib-report-preview-table');
        const contentHtml = tableEl ? tableEl.innerHTML : previewContainer.innerHTML;
        const w = window.open('', '_blank');
        if (!w) { alert('Pop-up blocked. Please allow pop-ups and try again.'); return; }
        w.document.write('<html><head><title>Report</title><style>'
            + 'body{font-family:"Segoe UI",sans-serif;margin:20px;}'
            + '.xl-table{width:100%;border-collapse:collapse;font-size:0.82rem;}'
            + '.xl-table td{border:1px solid #e5e7eb;padding:8px 10px;vertical-align:top;line-height:1.5;}'
            + '.xl-table tr:first-child td{background:#1a56db;color:#fff;font-weight:700;font-size:1rem;text-align:center;}'
            + '.xl-table tr:nth-child(2) td{background:#0e9f6e;color:#fff;font-size:0.82rem;text-align:center;font-style:italic;}'
            + '.xl-table tr:nth-child(4) td{background:#374151;color:#fff;font-weight:700;text-align:center;}'
            + '</style></head><body>' + contentHtml + '</body></html>');
        w.document.close();
        w.onload = function() { w.focus(); w.print(); };
    }
}
function libSaveReportPDF() {
    if (!_activeReportId) return;
    const rep = _allReports.find(r => r.report_id == _activeReportId);
    if (!rep || !rep.has_blob) { showToast('No file to save.'); return; }
    const saveBtn = document.getElementById('libSavePDFBtn');
    if (saveBtn) { saveBtn.disabled = true; saveBtn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Generating...'; }
    const overlay = document.getElementById('pdfGenOverlay');
    const msgEl = document.getElementById('pdfGenMsg');
    if (overlay) overlay.classList.add('show');
    if (msgEl) msgEl.textContent = 'Loading report...';
    function resetBtn() {
        if (saveBtn) { saveBtn.disabled = false; saveBtn.innerHTML = '<i class="fas fa-file-pdf"></i> Save as PDF'; }
        if (overlay) overlay.classList.remove('show');
    }
    const previewContainer = document.getElementById('report-preview-' + _activeReportId);
    if (!previewContainer) { showToast('Report preview not ready yet.'); resetBtn(); return; }

    function buildAndSavePdf(canvas) {
        try {
            const jsPDF = window.jspdf ? window.jspdf.jsPDF : window.jsPDF;
            if (!jsPDF) throw new Error('jsPDF library not loaded.');
            const PAGE_W_MM = 210, PAGE_H_MM = 297, MIN_SLICE_PX = 4;
            const pxToMm = PAGE_W_MM / canvas.width;
            const pageHeightPx = PAGE_H_MM / pxToMm;
            const totalPages = Math.ceil(canvas.height / pageHeightPx);
            let pdf = null, pagesAdded = 0;
            for (let page = 0; page < totalPages; page++) {
                const srcY = Math.round(page * pageHeightPx);
                const srcH = Math.min(Math.round(pageHeightPx), canvas.height - srcY);
                if (srcH < MIN_SLICE_PX) continue;
                const sliceHeightMm = srcH * pxToMm;
                const isLastPage = (page === totalPages - 1) || (srcH < pageHeightPx - MIN_SLICE_PX);
                const thisPageH = isLastPage ? sliceHeightMm : PAGE_H_MM;
                if (pdf === null) pdf = new jsPDF({ orientation: 'portrait', unit: 'mm', format: [PAGE_W_MM, thisPageH], compress: true });
                else pdf.addPage([PAGE_W_MM, thisPageH]);
                const sliceCanvas = document.createElement('canvas');
                sliceCanvas.width = canvas.width; sliceCanvas.height = srcH;
                const ctx = sliceCanvas.getContext('2d');
                ctx.fillStyle = '#ffffff'; ctx.fillRect(0, 0, sliceCanvas.width, sliceCanvas.height);
                ctx.drawImage(canvas, 0, srcY, canvas.width, srcH, 0, 0, canvas.width, srcH);
                pdf.addImage(sliceCanvas.toDataURL('image/png', 1.0), 'PNG', 0, 0, PAGE_W_MM, sliceHeightMm, '', 'FAST');
                pagesAdded++;
            }
            if (!pdf || pagesAdded === 0) throw new Error('No valid pages were generated.');
            const rep2 = _allReports.find(r => r.report_id == _activeReportId);
            const weekPart = rep2 ? rep2.week_start : '';
            const safeName = (_currentStudentName || 'Student').replace(/[\/\\:*?"<>|]/g, '').trim();
            pdf.save(safeName + '_Report_' + (weekPart || '') + '.pdf');
        } catch (err) { showToast('PDF build error: ' + err.message); }
        resetBtn();
    }
    function doCapture(el) {
        if (msgEl) msgEl.textContent = 'Rendering document...';
        html2canvas(el, { scale: 2, useCORS: true, allowTaint: true, backgroundColor: '#ffffff', logging: false })
            .then(buildAndSavePdf)
            .catch(function(err) { showToast('Render error: ' + err.message); resetBtn(); });
    }
    const iframe = previewContainer.querySelector('iframe');
    if (iframe) {
        const capture = function() {
            try {
                const doc = iframe.contentDocument || iframe.contentWindow.document;
                if (!doc || !doc.body) throw new Error('Could not access report content.');
                doCapture(doc.body);
            } catch (err) { showToast('Could not read report content: ' + err.message); resetBtn(); }
        };
        if (iframe.contentDocument && iframe.contentDocument.readyState === 'complete') capture();
        else iframe.onload = capture;
    } else {
        const tableEl = previewContainer.querySelector('.lib-report-preview-table') || previewContainer;
        doCapture(tableEl);
    }
}

/* ═══ EVALUATION VIEW MODAL (read-only) ═══ */
function tpWaitForIframeReady(iframeDoc, callback, attemptsLeft) {
    attemptsLeft = (typeof attemptsLeft === 'number') ? attemptsLeft : 50;
    var outer = iframeDoc ? iframeDoc.querySelector('.doc-outer') : null;
    if (!outer || outer.classList.contains('tp-ready') || attemptsLeft <= 0) { callback(); return; }
    setTimeout(function() { tpWaitForIframeReady(iframeDoc, callback, attemptsLeft - 1); }, 100);
}
function printUrlInPage(url) {
    var frame = document.getElementById('evalPrintHiddenFrame');
    if (!frame) { var w = window.open(url, '_blank'); if (w) { w.onload = function() { w.focus(); w.print(); }; } return; }
    frame.onload = function() {
        try {
            var fdoc = frame.contentDocument || frame.contentWindow.document;
            tpWaitForIframeReady(fdoc, function() {
                try { frame.contentWindow.focus(); frame.contentWindow.print(); } catch (e) {}
            });
        } catch (e) {}
    };
    frame.src = url;
}
function openEvalModal() {
    if (!_currentStudentId) return;
    document.getElementById('evalToolbarTitle').textContent = (_currentStudentName || 'Student') + ' - OJT/Internship Training Plan';
    var canvas = document.getElementById('evalDocCanvas');
    var printBtn = document.getElementById('evalPrintBtn');
    var pdfBtn   = document.getElementById('evalSavePDFBtn');

    if (_currentHasEval) {
        printBtn.style.display = '';
        pdfBtn.style.display   = '';
        canvas.innerHTML = '<iframe class="eval-blob-frame" id="evalBlobFrame" src="admin_reports.php?company_id=' + COMPANY_ID + '&print_eval=1&student_id=' + _currentStudentId + '" title="Training Plan"></iframe>';
    } else {
        printBtn.style.display = 'none';
        pdfBtn.style.display   = 'none';
        canvas.innerHTML = '<div class="eval-empty-state"><i class="fas fa-clipboard-list"></i><span>This student has not been evaluated by the company yet.</span></div>';
    }
    document.getElementById('evalOverlay').scrollTop = 0;
    document.getElementById('evalOverlay').classList.add('open');
    document.body.style.overflow = 'hidden';
}
function closeEvalModal() {
    document.getElementById('evalOverlay').classList.remove('open');
    /* The Training Plan modal is only ever reached via the card's
       "View Training Plan" shortcut, which opens the Student Report
       Library in the background first and stacks this modal on top.
       Closing it should return straight to the dashboard, not reveal
       the library underneath — so close the library too. */
    closeLibrary();
}
function evalPrintDoc() {
    if (!_currentStudentId) return;
    var printBtn = document.getElementById('evalPrintBtn');
    function resetBtn() { if (printBtn) { printBtn.disabled = false; printBtn.innerHTML = '<i class="fas fa-print"></i> Print'; } }
    if (printBtn) { printBtn.disabled = true; printBtn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Preparing...'; }
    var iframe = document.getElementById('evalBlobFrame');
    var goRender = function() {
        try {
            var iframeDoc = iframe.contentDocument || iframe.contentWindow.document;
            if (!iframeDoc || !iframeDoc.body) throw new Error('Could not access preview document.');
            tpWaitForIframeReady(iframeDoc, function() { evalRasterizeForPrint(iframeDoc, resetBtn); });
        } catch (e) {
            printUrlInPage('admin_reports.php?company_id=' + COMPANY_ID + '&print_eval=1&student_id=' + _currentStudentId);
            resetBtn();
        }
    };
    if (iframe) {
        if (iframe.contentDocument && iframe.contentDocument.readyState === 'complete') goRender();
        else iframe.onload = function() { goRender(); };
    } else {
        printUrlInPage('admin_reports.php?company_id=' + COMPANY_ID + '&print_eval=1&student_id=' + _currentStudentId);
        resetBtn();
    }
}
function evalRasterizeForPrint(iframeDoc, resetBtn) {
    var overlay = document.getElementById('printGenOverlay');
    var msgEl   = document.getElementById('printGenMsg');
    if (overlay) overlay.classList.add('show');
    if (msgEl) msgEl.textContent = 'Rendering document...';

    var paperEls = iframeDoc.querySelectorAll('.doc-paper');
    paperEls = (paperEls && paperEls.length) ? Array.prototype.slice.call(paperEls) : [iframeDoc.body];

    var totalSheets = paperEls.length;
    var images = [];
    function renderSheet(idx) {
        if (idx >= totalSheets) {
            openPrintImagesWindow(images);
            if (overlay) overlay.classList.remove('show');
            if (typeof resetBtn === 'function') resetBtn();
            return;
        }
        if (msgEl) msgEl.textContent = 'Rendering page ' + (idx + 1) + ' of ' + totalSheets + '...';
        var paperEl = paperEls[idx];
        var pr = paperEl.getBoundingClientRect();
        var pw = Math.round(pr.width)  || 794;
        var ph = Math.round(pr.height) || 1123;
        html2canvas(paperEl, {
            scale: 2, useCORS: true, allowTaint: true, backgroundColor: '#ffffff', logging: false,
            width: pw, height: ph, scrollX: 0, scrollY: 0
        }).then(function(canvas) {
            images.push(canvas.toDataURL('image/png', 1.0));
            renderSheet(idx + 1);
        }).catch(function(err) {
            showToast('Render error: ' + err.message);
            if (overlay) overlay.classList.remove('show');
            if (typeof resetBtn === 'function') resetBtn();
        });
    }
    renderSheet(0);
}
function openPrintImagesWindow(images) {
    if (!images || images.length === 0) { alert('Nothing to print — no pages were rendered.'); return; }
    var frame = document.getElementById('evalPrintHiddenFrame');
    if (!frame) { alert('Print preparation failed — the print frame is unavailable.'); return; }
    var pagesHtml = images.map(function(src) { return '<div class="print-page"><img src="' + src + '" alt="Training Plan page"></div>'; }).join('');
    var html = '<!DOCTYPE html><html><head><meta charset="utf-8"><title>Print Training Plan</title><style>'
        + '@page { size: 210mm 297mm; margin: 0; } * { box-sizing: border-box; }'
        + 'html, body { margin: 0 !important; padding: 0 !important; background: #fff; width: 210mm; }'
        + '.print-page { width: 210mm; height: 297mm; margin: 0; padding: 0; page-break-after: always; break-after: page; overflow: hidden; display: block; }'
        + '.print-page:last-child { page-break-after: auto; break-after: auto; }'
        + '.print-page img { display: block; width: 210mm; height: 297mm; margin: 0; padding: 0; border: 0; }'
        + '</style></head><body>' + pagesHtml + '</body></html>';
    frame.onload = function() {
        try { frame.contentWindow.focus(); setTimeout(function() { try { frame.contentWindow.print(); } catch (e) {} }, 150); } catch (e) {}
    };
    var fdoc = frame.contentDocument || frame.contentWindow.document;
    fdoc.open(); fdoc.write(html); fdoc.close();
}
function evalSavePDF() {
    if (!_currentStudentId) return;
    var savePDFBtn = document.getElementById('evalSavePDFBtn');
    if (savePDFBtn) { savePDFBtn.disabled = true; savePDFBtn.textContent = 'Generating...'; }
    var overlay = document.getElementById('pdfGenOverlay');
    var msgEl   = document.getElementById('pdfGenMsg');
    if (overlay) overlay.classList.add('show');
    if (msgEl)   msgEl.textContent = 'Loading evaluation form...';

    var hiddenFrame = document.getElementById('evalPdfHiddenFrame');
    hiddenFrame.onload = null; hiddenFrame.onerror = null;
    function resetBtn() {
        if (savePDFBtn) { savePDFBtn.disabled = false; savePDFBtn.textContent = 'Save as PDF'; }
        if (overlay) overlay.classList.remove('show');
        hiddenFrame.style.width = '900px'; hiddenFrame.style.height = '1200px';
    }
    hiddenFrame.onerror = function() { showToast('Failed to load evaluation form.'); resetBtn(); };
    hiddenFrame.onload = function() {
        try {
            var iframeDoc = hiddenFrame.contentDocument || hiddenFrame.contentWindow.document;
            if (!iframeDoc || !iframeDoc.body) throw new Error('Could not access iframe document.');
            if (msgEl) msgEl.textContent = 'Rendering document...';
            tpWaitForIframeReady(iframeDoc, function() {
                var paperEls = iframeDoc.querySelectorAll('.doc-paper');
                paperEls = (paperEls && paperEls.length) ? Array.prototype.slice.call(paperEls) : [iframeDoc.body];
                var totalSheets = paperEls.length;
                if (totalSheets === 0) { showToast('PDF build error: no pages were generated.'); resetBtn(); return; }

                var rect0 = paperEls[0].getBoundingClientRect();
                var sheetW = Math.round(rect0.width) || 794;
                var sheetH = Math.round(rect0.height) || 1123;
                hiddenFrame.style.width  = Math.max(920, sheetW + 40) + 'px';
                hiddenFrame.style.height = Math.max(sheetH + 60, 1200) + 'px';

                var jsPDF = window.jspdf ? window.jspdf.jsPDF : window.jsPDF;
                if (!jsPDF) { showToast('PDF build error: jsPDF library not loaded.'); resetBtn(); return; }

                var PAGE_W_MM = 210, PAGE_H_MM = 297;
                var pdf = null;
                function renderSheet(idx) {
                    if (idx >= totalSheets) {
                        if (!pdf) { showToast('PDF build error: no pages were generated.'); resetBtn(); return; }
                        var safeName = (_currentStudentName || 'Student').replace(/[\/\\:*?"<>|]/g, '').trim();
                        pdf.save(safeName + '_TrainingPlan.pdf');
                        resetBtn();
                        return;
                    }
                    if (msgEl) msgEl.textContent = 'Rendering page ' + (idx + 1) + ' of ' + totalSheets + '...';
                    var paperEl = paperEls[idx];
                    var pr = paperEl.getBoundingClientRect();
                    var pw = Math.round(pr.width)  || sheetW;
                    var ph = Math.round(pr.height) || sheetH;
                    html2canvas(paperEl, {
                        scale: 2, useCORS: true, allowTaint: true, backgroundColor: '#ffffff', logging: false,
                        width: pw, height: ph, scrollX: 0, scrollY: 0,
                        windowWidth: Math.max(920, sheetW + 40), windowHeight: Math.max(sheetH + 60, 1200)
                    }).then(function(canvas) {
                        var imgData = canvas.toDataURL('image/png', 1.0);
                        if (pdf === null) pdf = new jsPDF({ orientation: 'portrait', unit: 'mm', format: [PAGE_W_MM, PAGE_H_MM], compress: true });
                        else pdf.addPage([PAGE_W_MM, PAGE_H_MM]);
                        pdf.addImage(imgData, 'PNG', 0, 0, PAGE_W_MM, PAGE_H_MM, '', 'FAST');
                        renderSheet(idx + 1);
                    }).catch(function(canvasErr) { showToast('Render error: ' + canvasErr.message); resetBtn(); });
                }
                renderSheet(0);
            });
        } catch (e) { showToast('Error: ' + e.message); resetBtn(); }
    };
    hiddenFrame.src = 'admin_reports.php?company_id=' + COMPANY_ID + '&print_eval=1&student_id=' + _currentStudentId;
}

/* ── UTILITIES ── */
function setText(id, val) { const el=document.getElementById(id); if(el) el.textContent=val; }
function showToast(msg) { const t=document.getElementById('toast'); t.textContent=msg; t.classList.add('show'); setTimeout(()=>t.classList.remove('show'),2500); }
function escH(str) { return String(str||'').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;'); }

setTimeout(() => { silentPoll(); setInterval(silentPoll, 30000); }, 5000);
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
// ── FIX (sidebar notification indicator): Company Requirements badge live poll.
// Uses this page's own endpoint, which counts notifications exactly the way
// company_validation.php does (see admin_reports_company_validation_notif_count()).
(function() {
    function pollMoaBadge() {
        fetch('admin_reports.php?cv_sidebar_notif_count=1', { credentials: 'same-origin' })
            .then(r => r.json())
            .then(data => {
                const badge = document.getElementById('sidebarMoaBadge');
                if (!badge) return;
                const count = parseInt(data.count, 10) || 0;
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
<!-- NEW (this adjustment): export result screen — same as admin_student_list.php / admin_company_list.php -->
<div id="globalResultOverlay" class="hidden" role="alertdialog" aria-live="assertive" aria-labelledby="globalResultTitle" aria-describedby="globalResultMessage">
    <div class="global-result-box">
        <div class="global-result-icon"><i id="globalResultIcon" class="fas fa-check"></i></div>
        <p class="global-result-title" id="globalResultTitle"></p>
        <p class="global-result-message" id="globalResultMessage"></p>
        <button type="button" class="global-result-ok" id="globalResultOkBtn">OK</button>
    </div>
</div>
<script>
/* NEW (this adjustment): result screen helpers — same behaviour as admin_student_list.php:
   success closes itself after a few seconds, failure stays until OK (or Esc). */
var globalResultHideTimer = null;
function hideGlobalResult() {
    if (globalResultHideTimer) { clearTimeout(globalResultHideTimer); globalResultHideTimer = null; }
    var ov = document.getElementById('globalResultOverlay'); if (ov) ov.classList.add('hidden');
}
function showGlobalResult(type, title, message, autoHideMs) {
    var ov = document.getElementById('globalResultOverlay');
    if (!ov) { alert((title ? title + '\n\n' : '') + (message || '')); return; }
    var isSuccess = type === 'success';
    if (globalResultHideTimer) { clearTimeout(globalResultHideTimer); globalResultHideTimer = null; }
    ov.classList.toggle('is-success', isSuccess);
    ov.classList.toggle('is-error', !isSuccess);
    var icon = document.getElementById('globalResultIcon'); if (icon) icon.className = isSuccess ? 'fas fa-check' : 'fas fa-times';
    document.getElementById('globalResultTitle').textContent = title || '';
    document.getElementById('globalResultMessage').textContent = message || '';
    ov.classList.remove('hidden');
    var ok = document.getElementById('globalResultOkBtn'); if (ok) { try { ok.focus(); } catch (e) {} }
    if (autoHideMs && autoHideMs > 0) globalResultHideTimer = setTimeout(hideGlobalResult, autoHideMs);
}
document.getElementById('globalResultOkBtn').addEventListener('click', hideGlobalResult);
document.addEventListener('keydown', function (e) {
    var ov = document.getElementById('globalResultOverlay');
    if (e.key === 'Escape' && ov && !ov.classList.contains('hidden')) hideGlobalResult();
});
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
        if (r.kind === 'schedule') {   // NEW (this adjustment): schedule changed by the company supervisor → the new Application SIT needs validation
            div.innerHTML = '<i class="fas fa-calendar-days"></i><span><strong>' + esc(r.full_name || 'A student') + '</strong>\u2019s schedule was changed by the company supervisor \u2014 the new Application SIT needs validation.</span>';
        } else if (r.kind === 'placement') {   // NEW (this adjustment): preferred placement replaced → the new Application SIT needs validation
            div.innerHTML = '<i class="fas fa-right-left"></i><span><strong>' + esc(r.full_name || 'A student') + '</strong> replaced the preferred placement \u2014 the new Application SIT needs validation.</span>';
        } else {
            div.innerHTML = '<i class="fas fa-file-arrow-up"></i><span><strong>' + esc(r.full_name || 'A student') + '</strong> submitted ' + esc(what) + ' \u2014 check the Application Requests inbox.</span>';
        }
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