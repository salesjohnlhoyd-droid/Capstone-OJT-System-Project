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
    [function () { return cv_alog_is_get_post('toggle_publish'); }, function ($conn) {
        $n = cv_alog_user_name($conn, (int)($_POST['student_id'] ?? 0)); $p = (int)($_POST['publish'] ?? 0) === 1;
        return [$p ? 'Grade Published' : 'Grade Unpublished', 'Student', $n, ($p ? 'Published' : 'Unpublished') . " the final grade of $n via Final Grades"];
    }],
    [function () { return cv_alog_is_get_post('bulk_publish'); }, function ($conn) {
        $pairs = array_filter(array_map('trim', explode(',', (string)($_POST['ids'] ?? '')))); $names = [];
        foreach ($pairs as $p) { if (preg_match('/^(\d+)_(\d+)$/', $p, $m)) $names[] = cv_alog_user_name($conn, (int)$m[1]); }
        $c = count($pairs); $pub = (int)($_POST['publish'] ?? 0) === 1;
        return [$pub ? 'Grades Published' : 'Grades Unpublished', 'Student', cv_alog_list($names) ?: cv_alog_plural($c, 'student', 'students'), ($pub ? 'Published' : 'Unpublished') . " the final grades of " . cv_alog_plural($c, 'student', 'students') . (cv_alog_list($names) !== '' ? ' (' . cv_alog_list($names) . ')' : '') . " via Final Grades"];
    }],
]);

$admin_id = $_SESSION['user_id'];
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

// ── NEW: ensure the moa_requests table exists so the pending-count query
// below (used for the "Company Requirements" sidebar indicator) never
// fails on a fresh install — mirrors the same guard used in
// company_validation.php / admin_student_list.php / admin_monitoring_dashboard.php. ──
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

// ── NEW: pending MOA request count, used for the "Company Requirements"
// sidebar indicator so admins can see there's a pending MOA request
// waiting for review even while they're on this page. ──
/* ── FIX (sidebar notification indicator — adopted from
   admin_student_list.php / admin_monitoring_dashboard.php): the badge
   used to count moa_requests rows with status='Pending', but
   company_validation.php auto-ingests every Pending MOA request right
   away, so that count was almost always 0 / out of sync. That page's
   Notification Inbox lists (a) un-viewed, transferred MOA notifications
   (moa_requests.admin_viewed=0 AND transferred=1) PLUS (b) un-viewed
   requirement-upload notifications
   (company_requirement_upload_notifications.admin_viewed=0).
   This helper uses exactly that same rule, so the number shown here is
   always the same number shown on company_validation.php. Every lookup
   is guarded — a missing column/table simply counts as 0. Read-only:
   no DDL, no writes. ── */
if (!function_exists('final_grades_company_validation_notif_count')) {
    function final_grades_company_validation_notif_count($conn) {
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
$moa_pending_count = final_grades_company_validation_notif_count($conn); // FIX (sidebar notification indicator)

/* ── NEW (sidebar notification indicator): lightweight JSON endpoint the
   sidebar polls so the "Company Requirements" badge stays in step with
   company_validation.php without a page reload. Placed before any HTML
   output; read-only. ── */
if (isset($_GET['cv_sidebar_notif_count']) && $_GET['cv_sidebar_notif_count'] === '1') {
    header('Content-Type: application/json');
    echo json_encode(['count' => $moa_pending_count]);
    exit;
}

// ── NEW: full name shown at the top of the sidebar, built from
// first_name + middle_name + last_name of the logged-in admin (same
// pattern used in admin_student_list.php / admin_monitoring_dashboard.php).
// Falls back to session values, then to a generic label, if nothing is
// available. ──
$adminFullName = '';
$adminNameStmt = $conn->prepare("SELECT first_name, middle_name, last_name FROM admins WHERE id = ?");
if ($adminNameStmt) {
    $adminNameStmt->bind_param("i", $admin_id);
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

/* ══════════════════════════════════════════════
   NEW SOURCE OF TRUTH FOR FINAL GRADES
   ------------------------------------------------------------
   Final grades are no longer read from a separate `final_grades`
   table (which stored a company-avg/admin-avg weighted score from
   weekly report grading — a feature that has been removed). They
   now come directly from `ojt_assignments`, which already carries
   the General/Specific/Overall Competency Ratings persisted by
   company_reports.php's save_eval=1 handler (see
   ensureEvalRatingColumns() there) once a company submits and locks
   a student's OJT/Internship Training Plan evaluation.

   `ojt_assignments` has no single auto-increment `id` column that
   this page can rely on — rows are uniquely identified by the
   (student_id, company_id) pair, same as company_reports.php uses
   throughout. So instead of a single `fg_id`, this page builds a
   composite identifier "{student_id}_{company_id}" for each row
   (used for DOM element ids/checkbox values/AJAX payloads), and the
   toggle_publish / bulk_publish endpoints below accept student_id +
   company_id instead of a single numeric id.

   `is_published` / `published_at` did not previously exist on
   ojt_assignments, so they are lazily added here the same way
   general_competency_rating / specific_competency_rating /
   overall_competency_rating and eval_pdf_blob are lazily added in
   company_reports.php.
   ============================================================ */
function ensureEvalRatingColumns(mysqli $conn): void {
    static $checked = false;
    if ($checked) return;
    $checked = true;

    $needed_columns = [
        'general_competency_rating'  => "DECIMAL(4,2) NULL",
        'specific_competency_rating' => "DECIMAL(4,2) NULL",
        'overall_competency_rating'  => "DECIMAL(4,2) NULL",
    ];
    foreach ($needed_columns as $col_name => $col_def) {
        $col_check = $conn->query("SHOW COLUMNS FROM ojt_assignments LIKE '{$col_name}'");
        if ($col_check && $col_check->num_rows === 0) {
            $conn->query("ALTER TABLE ojt_assignments ADD COLUMN `{$col_name}` {$col_def} AFTER eval_submitted_at");
        }
    }
}

function ensureFinalGradePublishColumns(mysqli $conn): void {
    static $checked = false;
    if ($checked) return;
    $checked = true;

    $needed_columns = [
        'is_published' => "TINYINT(1) NOT NULL DEFAULT 0",
        'published_at' => "DATETIME NULL",
    ];
    foreach ($needed_columns as $col_name => $col_def) {
        $col_check = $conn->query("SHOW COLUMNS FROM ojt_assignments LIKE '{$col_name}'");
        if ($col_check && $col_check->num_rows === 0) {
            $conn->query("ALTER TABLE ojt_assignments ADD COLUMN `{$col_name}` {$col_def}");
        }
    }
}

ensureEvalRatingColumns($conn);
ensureFinalGradePublishColumns($conn);

/* ══════════════════════════════════════════════
   AJAX: TOGGLE PUBLISH (upload / unupload)
   ------------------------------------------------------------
   Now targets a single ojt_assignments row identified by the
   (student_id, company_id) pair instead of a single fg_id.
══════════════════════════════════════════════ */
if (isset($_GET['toggle_publish']) && $_GET['toggle_publish'] == '1' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    header('Content-Type: application/json');
    $req_student_id = (int)($_POST['student_id']  ?? 0);
    $req_company_id = (int)($_POST['company_id']  ?? 0);
    $publish        = (int)($_POST['publish']     ?? 0); // 1 = publish, 0 = unpublish

    if (!$req_student_id || !$req_company_id) {
        echo json_encode(['success' => false, 'message' => 'Invalid record.']);
        exit;
    }

    $published_at_expr = $publish ? 'NOW()' : 'NULL';
    $stmt = $conn->prepare("
        UPDATE ojt_assignments
        SET is_published = ?, published_at = {$published_at_expr}
        WHERE student_id = ? AND company_id = ?
    ");
    $stmt->bind_param("iii", $publish, $req_student_id, $req_company_id);
    if ($stmt->execute()) {
        echo json_encode(['success' => true, 'is_published' => $publish]);
    } else {
        echo json_encode(['success' => false, 'message' => 'DB error: ' . $conn->error]);
    }
    $stmt->close();
    exit;
}

/* ══════════════════════════════════════════════
   AJAX: BULK TOGGLE PUBLISH
   ------------------------------------------------------------
   `ids` is now a comma-separated list of "{student_id}_{company_id}"
   pairs (matching the composite fg_id built for each row below),
   since ojt_assignments has no single id column to match against.
══════════════════════════════════════════════ */
if (isset($_GET['bulk_publish']) && $_GET['bulk_publish'] == '1' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    header('Content-Type: application/json');
    $ids_raw = $_POST['ids'] ?? '';
    $publish = (int)($_POST['publish'] ?? 0);

    $raw_pairs = array_filter(array_map('trim', explode(',', $ids_raw)));
    $pairs = [];
    foreach ($raw_pairs as $p) {
        if (preg_match('/^(\d+)_(\d+)$/', $p, $m)) {
            $pairs[] = [(int)$m[1], (int)$m[2]];
        }
    }

    if (empty($pairs)) {
        echo json_encode(['success' => false, 'message' => 'No records selected.']);
        exit;
    }

    $conds  = [];
    $types  = '';
    $params = [];
    foreach ($pairs as $pair) {
        $conds[]  = "(student_id = ? AND company_id = ?)";
        $types   .= "ii";
        $params[] = $pair[0];
        $params[] = $pair[1];
    }
    $where_clause       = implode(' OR ', $conds);
    $published_at_expr  = $publish ? 'NOW()' : 'NULL';

    $stmt = $conn->prepare("
        UPDATE ojt_assignments
        SET is_published = {$publish}, published_at = {$published_at_expr}
        WHERE {$where_clause}
    ");
    $stmt->bind_param($types, ...$params);
    if ($stmt->execute()) {
        echo json_encode(['success' => true, 'affected' => $stmt->affected_rows]);
    } else {
        echo json_encode(['success' => false, 'message' => 'DB error: ' . $conn->error]);
    }
    $stmt->close();
    exit;
}

/* ══════════════════════════════════════════════
   FETCH ALL FINAL GRADES (EVALUATION RATINGS) WITH STUDENT + COMPANY INFO
   ------------------------------------------------------------
   Sourced from ojt_assignments — only students whose Training Plan
   evaluation has actually been submitted/locked (eval_submitted_at
   IS NOT NULL) show up here, mirroring how only saved final_grades
   rows used to appear.
══════════════════════════════════════════════ */
$grades_stmt = $conn->prepare("
    SELECT
        oa.student_id,
        oa.company_id,
        oa.general_competency_rating,
        oa.specific_competency_rating,
        oa.overall_competency_rating,
        oa.eval_submitted_at,
        oa.is_published,
        oa.published_at,
        u.first_name             AS student_first,
        u.last_name              AS student_last,
        u.course,
        ci.company               AS company_name
    FROM ojt_assignments oa
    JOIN users u ON u.id = oa.student_id
    LEFT JOIN company_information ci ON ci.user_id = oa.company_id
    WHERE oa.eval_submitted_at IS NOT NULL
    ORDER BY ci.company ASC, u.last_name ASC, u.first_name ASC
");
$grades_stmt->execute();
$all_grades = $grades_stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$grades_stmt->close();

/* ══════════════════════════════════════════════
   AJAX: POLL FOR GRADE UPDATES (auto-detect newly submitted evaluations)
   ------------------------------------------------------------
   Lightweight polling endpoint the front-end calls on an interval so
   newly-submitted/locked company evaluations show up in the table
   automatically, with no manual page refresh needed. Returns the same
   rows used to render the page, in JSON form, keyed by the same
   composite "{student_id}_{company_id}" fg_id used everywhere else on
   this page.
══════════════════════════════════════════════ */
if (isset($_GET['poll_grades']) && $_GET['poll_grades'] == '1') {
    header('Content-Type: application/json');
    $poll_data = [];
    foreach ($all_grades as $prow) {
        $p_fg_id     = $prow['student_id'] . '_' . $prow['company_id'];
        $p_grade_val = $prow['overall_competency_rating'] !== null ? (float)$prow['overall_competency_rating'] : null;
        $poll_data[] = [
            'fg_id'             => $p_fg_id,
            'student_id'        => (int)$prow['student_id'],
            'company_id'        => (int)$prow['company_id'],
            'student_name'      => trim($prow['student_first'] . ' ' . $prow['student_last']),
            'course'            => $prow['course'] ?? '',
            'company_name'      => $prow['company_name'] ?? 'Unknown Company',
            'general_rating'    => $prow['general_competency_rating']  !== null ? number_format((float)$prow['general_competency_rating'], 2)  : null,
            'specific_rating'   => $prow['specific_competency_rating'] !== null ? number_format((float)$prow['specific_competency_rating'], 2) : null,
            'overall_rating'    => $p_grade_val !== null ? number_format($p_grade_val, 2) : null,
            'eval_submitted_at' => $prow['eval_submitted_at'],
            'is_published'      => (int)$prow['is_published'],
            'published_at'      => $prow['published_at'],
        ];
    }
    echo json_encode(['success' => true, 'grades' => $poll_data]);
    exit;
}

/* Stats */
$total_students  = count($all_grades);
$total_published = count(array_filter($all_grades, fn($r) => $r['is_published']));
$total_pending   = $total_students - $total_published;

/* ══════════════════════════════════════════════
   RATING → COLOR / LABEL HELPERS (1.0–5.0 scale, LOWER is better)
   ------------------------------------------------------------
   Replaces the old 0–100 percentage-based gradeColor()/gradeLetter()
   functions, which no longer apply now that ratings come from the
   OJT/Internship Training Plan's Competency Rating Scale (see
   RATING_SCALE in company_reports.php):
       1.0–1.25  Excellent
       1.5–2.0   Very Satisfactory
       2.25–2.75 Satisfactory
       3.0       Passed
       5.0       Failed
   Since the Overall Competency Rating is a weighted average
   (General 40% / Specific 60%), it can land anywhere between 1.0
   and 5.0, so banding is done by range rather than exact match.
══════════════════════════════════════════════ */
function ratingGradeClass(float $r): string {
    if ($r <= 2.0)  return 'grade-a'; // Excellent / Very Satisfactory
    if ($r <= 2.75) return 'grade-b'; // Satisfactory
    if ($r <= 3.0)  return 'grade-c'; // Passed
    return 'grade-d';                 // Failed
}
function ratingLabel(float $r): string {
    if ($r <= 1.25) return 'Excellent';
    if ($r <= 2.0)  return 'Very Satisfactory';
    if ($r <= 2.75) return 'Satisfactory';
    if ($r <= 3.0)  return 'Passed';
    return 'Failed';
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>Final Grades — Admin Panel</title>
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
<link href="https://fonts.googleapis.com/css2?family=DM+Sans:wght@300;400;500;600;700&family=DM+Mono:wght@400;500&display=swap" rel="stylesheet">
<script src="https://cdnjs.cloudflare.com/ajax/libs/xlsx/0.18.5/xlsx.full.min.js"></script>
<style>
/* ══════════════════════════════════════════════════════════════════
   DESIGN ADJUSTMENT: "Field ops grid" (Option 4 from
   company_list_style_previews.html) applied to this page, matching
   admin_monitoring_dashboard.php / admin_reports.php. Sharp corners,
   #C3CADA grid borders, navy #1B2A4A primary, white bordered
   secondary buttons, uppercase tracked labels, color-coded left
   bars, statuses shown as bold colored text.
   The sidebar / navbar / sidebar badges keep the shared NEUST admin
   look. Every original selector and CSS variable name is kept (JS and
   inline styles reference var(--text-muted) etc.) — only visual
   values changed; no logic was touched.
   ══════════════════════════════════════════════════════════════════ */
:root {
    --neust-maroon: #07145fe5;
    --neust-gold: #FFD700;
    --bg: #EEF1F6;
    --surface: #ffffff;
    --border: #C3CADA;
    --text-primary: #1B2A4A;
    --text-secondary: #5A6272;
    --text-muted: #8A93A8;
    --blue: #1B2A4A;
    --green: #2C5A2C;
    --amber: #A0850A;
    --red: #A02A2A;
    --purple: #1B2A4A;
    --shadow-sm: none;
    --shadow-md: 0 4px 14px rgba(27,42,74,0.14);
    --shadow-lg: 0 12px 30px rgba(27,42,74,0.30);
    --radius: 0;
    --radius-sm: 0;

    /* Field ops grid tokens */
    --fo-navy: #1B2A4A;
    --fo-navy-2: #2A3D63;
    --fo-line: #C3CADA;
    --fo-line-soft: #DDE2EC;
    --fo-head: #F4F6FA;
    --fo-hover: #EAF0FA;
    --fo-green: #2C5A2C;
    --fo-green-bar: #4A7A3A;
    --fo-gold: #A0850A;
    --fo-gold-ink: #7A6508;
    --fo-red: #A02A2A;
}
* { box-sizing: border-box; margin: 0; padding: 0; }
body {
    font-family: 'DM Sans', 'Segoe UI', sans-serif;
    background: var(--bg);
    color: var(--text-primary);
    display: flex;
    min-height: 100vh;
    line-height: 1.5;
}

/* ── SIDEBAR (unchanged shared admin look) ── */
.sidebar { width:260px; background:var(--neust-maroon); height:100vh; position:fixed; display:flex; flex-direction:column; transition:0.3s; z-index:1000; }
.sidebar.collapsed { width:80px; }
.sidebar-header { padding:20px; display:flex; align-items:center; justify-content:space-between; border-bottom:1px solid rgba(255,255,255,0.1); }
.sidebar-header-titles { overflow: hidden; transition: 0.3s; min-width: 0; }
.sidebar-header h2 { color:var(--neust-gold); margin:0; font-size:18px; font-weight:bold; white-space:nowrap; overflow:hidden; text-overflow: ellipsis; transition: 0.3s; }
.sidebar-role-label { display: block; color: rgba(255,255,255,0.55); font-size: 11px; font-weight: 700; text-transform: uppercase; letter-spacing: 0.8px; margin-top: 3px; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; transition: 0.3s; }
.sidebar.collapsed .sidebar-header-titles { opacity:0; width:0; }
.sidebar.collapsed h2 { opacity:0; width:0; }
.sidebar-links { flex:1; padding:10px 0; }
.sidebar a { padding:15px 25px; color:#cbd5e0; text-decoration:none; font-size:14px; display:flex; align-items:center; position:relative; }
.sidebar a i { width:30px; font-size:18px; margin-right:15px; }
.sidebar.collapsed .link-text { display:none; }
.sidebar a.active { background:#1a237e; color:white; border-left:4px solid var(--neust-gold); }
.sidebar a:hover:not(.active) { background:rgba(255,255,255,0.07); }
/* ── FIX (consistent sidebar size): this page's body uses 'DM Sans' with
   line-height 1.5, and the sidebar inherited both — every menu item came
   out taller (and the text wider) than on admin_student_list.php and the
   other admin pages, so the whole sidebar looked enlarged. The sidebar now
   uses the same font, line height and link rules as admin_student_list.php,
   so it is the same size on every page. Only the sidebar is affected; the
   rest of this page keeps its own font and spacing. ── */
.sidebar { font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif; line-height: normal; box-shadow: 4px 0 10px rgba(0,0,0,0.1); }
.sidebar-links { display: flex; flex-direction: column; }
.sidebar a { white-space: nowrap; transition: 0.2s; }
.sidebar a i { text-align: center; }
.sidebar.collapsed a { justify-content: center; padding: 15px 0; }
.sidebar.collapsed a i { margin-right: 0; }
.sidebar .logout-link a { padding: 10px; }
.sidebar.collapsed .logout-link a { border: none; }
.logout-link { margin-top:auto; padding:20px; border-top:1px solid rgba(255,255,255,0.1); }
.logout-link a { border:1px solid var(--neust-gold); color:var(--neust-gold); border-radius:6px; justify-content:center; padding:10px; text-decoration:none; display:flex; }

/* ── NEW: pending-MOA indicator on the "Company Requirements" sidebar
   link — mirrors the identical badge/animation already used in
   company_validation.php / admin_student_list.php / admin_monitoring_dashboard.php
   so the visual language matches exactly. ── */
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

/* ── LAYOUT ── */
.main-content { margin-left:260px; width:calc(100% - 260px); transition:0.3s; min-height:100vh; display:flex; flex-direction:column; }
.sidebar.collapsed ~ .main-content { margin-left:80px; width:calc(100% - 80px); }

/* ── TOP NAV HEADER (matches admin_monitoring_dashboard.php) ── */
.navbar { background: var(--neust-maroon); padding: 10px 30px; display: flex; justify-content: space-between; align-items: center; color: white; height: 60px; }
.logo-section { display: flex; align-items: center; gap: 12px; }
.university-logo { height: 40px; }

/* ── PAGE HEADER — Field ops grid toolbar strip ── */
.page-header {
    background: #fff;
    color: var(--fo-navy); padding: 14px 28px;
    display: flex; align-items: center; justify-content: space-between;
    position: sticky; top: 0; z-index: 100;
    border-bottom: 1px solid var(--fo-line); border-left: 3px solid var(--fo-navy);
    box-shadow: 0 2px 8px rgba(27,42,74,0.08);
    flex-wrap: wrap; gap: 10px;
}
.page-header-left h2 { font-size: 0.92rem; font-weight: 700; text-transform: uppercase; letter-spacing: 0.5px; color: var(--fo-navy); }
.page-header-left p { font-size: 0.78rem; color: var(--text-secondary); margin-top: 4px; }
.page-header-right { display: flex; align-items: center; gap: 8px; flex-wrap: wrap; }

/* ── STAT CARDS — joined grid cells with a colored left bar ── */
.content-wrap { max-width: 1200px; margin: 0 auto; padding: 26px 20px 60px; width: 100%; }
.stats-row { display: grid; grid-template-columns: repeat(auto-fit, minmax(170px, 1fr)); gap: 0; margin-bottom: 18px; border: 1px solid var(--fo-line); background: var(--fo-line); grid-gap: 1px; }
.stat-card {
    background: var(--surface); border-radius: 0;
    padding: 14px 16px; box-shadow: none;
    border: none; border-left: 3px solid var(--fo-navy);
    display: flex; align-items: center; gap: 14px;
}
.stat-card:has(.stat-icon.pub) { border-left-color: var(--fo-green-bar); }
.stat-card:has(.stat-icon.pending) { border-left-color: var(--fo-gold); }
.stat-icon {
    width: 38px; height: 38px; border-radius: 0;
    display: flex; align-items: center; justify-content: center;
    font-size: 1rem; flex-shrink: 0; border: 1px solid var(--fo-line); background: #fff;
}
.stat-icon.total    { background: #fff; color: var(--fo-navy); }
.stat-icon.pub      { background: #fff; color: var(--fo-green); border-color: var(--fo-green-bar); }
.stat-icon.pending  { background: #fff; color: var(--fo-gold-ink); border-color: var(--fo-gold); }
.stat-val { font-size: 1.5rem; font-weight: 800; line-height: 1; color: var(--fo-navy); }
.stat-label { font-size: 0.66rem; color: var(--text-secondary); font-weight: 700; text-transform: uppercase; letter-spacing: 0.5px; margin-top: 4px; }

/* ── CONTROLS BAR ── */
.controls-bar {
    display: flex; align-items: center; gap: 6px; margin-bottom: 14px; flex-wrap: wrap;
    background: #fff; border: 1px solid var(--fo-line); padding: 10px;
}
.search-wrap { position: relative; flex: 1; min-width: 200px; max-width: 300px; }
.search-wrap i { position: absolute; left: 12px; top: 50%; transform: translateY(-50%); color: var(--text-muted); font-size: 0.85rem; }
.search-input {
    width: 100%; padding: 8px 12px 8px 34px;
    border: 1px solid var(--fo-line); border-radius: 0;
    font-size: 0.86rem; font-family: inherit; background: var(--surface); color: var(--fo-navy);
    transition: border-color 0.15s;
}
.search-input::placeholder { color: var(--text-muted); }
.search-input:focus { outline: none; border-color: var(--fo-navy); }
.filter-select {
    padding: 8px 12px; border: 1px solid var(--fo-line);
    border-radius: 0; font-size: 0.78rem; font-family: inherit; font-weight: 700;
    text-transform: uppercase; letter-spacing: 0.4px; color: var(--fo-navy);
    background: var(--surface); cursor: pointer;
}
.filter-select:focus { outline: none; border-color: var(--fo-navy); }

/* Bulk action buttons — Option 4: navy primary, white bordered secondary,
   white with red text for the "remove" action */
.bulk-btn {
    display: inline-flex; align-items: center; gap: 7px;
    border: 1px solid var(--fo-line); border-radius: 0; padding: 8px 14px;
    font-size: 12px; font-weight: 700; font-family: 'DM Sans', sans-serif;
    text-transform: uppercase; letter-spacing: 0.4px;
    cursor: pointer; transition: background 0.15s, color 0.15s, border-color 0.15s; white-space: nowrap;
}
.bulk-btn:hover:not(:disabled) { opacity: 1; transform: none; }
.bulk-btn:focus-visible { outline: 2px solid var(--fo-gold); outline-offset: 2px; }
.bulk-btn:disabled { opacity: 0.4; cursor: not-allowed; }
.bulk-btn.publish   { background: var(--fo-navy); border-color: var(--fo-navy); color: white; }
.bulk-btn.publish:hover:not(:disabled) { background: var(--fo-navy-2); border-color: var(--fo-navy-2); }
.bulk-btn.unpublish { background: #fff; color: var(--fo-red); }
.bulk-btn.unpublish:hover:not(:disabled) { background: var(--fo-red); border-color: var(--fo-red); color: #fff; }
.bulk-count { margin-left: auto; font-size: 11px; color: var(--text-secondary); white-space: nowrap; font-weight: 700; text-transform: uppercase; letter-spacing: 0.4px; padding: 0 6px; }

/* Export button — now placed inline in the controls bar, right after
   the "Unupload Selected" button, restyled to match the bulk-btn family
   (Option 4: white bordered "Export"). */
.bulk-btn.export {
    background: #fff;
    color: var(--fo-navy);
    border: 1px solid var(--fo-line);
}
.bulk-btn.export:hover:not(:disabled) { background: var(--fo-navy); border-color: var(--fo-navy); color: #fff; }

/* ── UNIFIED GRADES TABLE (all students, all companies, one table) ── */
.grades-table-wrap {
    background: var(--surface);
    border: 1px solid var(--fo-line);
    border-radius: 0;
    overflow: hidden;
    box-shadow: none;
    margin-bottom: 14px;
}
.company-cell-name { font-weight: 600; font-size: 0.82rem; color: var(--text-secondary); }

/* ── GRADES TABLE ── */
.grades-table { width: 100%; border-collapse: collapse; font-size: 0.82rem; min-width: 760px; }
.grades-table thead th {
    background: var(--fo-head); color: var(--fo-navy);
    font-weight: 700; padding: 10px 14px; text-align: left;
    border-bottom: 1px solid var(--fo-line); font-size: 0.68rem;
    text-transform: uppercase; letter-spacing: 0.5px; white-space: nowrap;
}
.grades-table thead th.th-center { text-align: center; }
.grades-table thead th.th-check { width: 38px; text-align: center; }

.grades-table tbody tr { transition: background 0.12s; }
.grades-table tbody tr:hover td { background: var(--fo-hover); }
.grades-table tbody tr:not(:last-child) td { border-bottom: 1px solid var(--fo-line-soft); }

.grades-table td { padding: 11px 14px; vertical-align: middle; }
.grades-table td.td-check { text-align: center; border-left: 3px solid transparent; }

.student-name-cell .sn-name { font-weight: 700; font-size: 0.88rem; color: var(--fo-navy); }
.student-name-cell .sn-course { font-size: 0.66rem; color: var(--text-secondary); margin-top: 3px; text-transform: uppercase; letter-spacing: 0.4px; font-weight: 600; }

/* grade value badges */
.gv { font-family: 'DM Mono', monospace; font-weight: 700; font-size: 0.82rem; }
.gv.company-g { color: var(--fo-navy); }
.gv.admin-g   { color: var(--fo-navy); }

/* Weighted final grade badge — square, bordered, colored text */
.final-grade-badge {
    display: inline-flex; align-items: center; gap: 6px;
    border-radius: 0; padding: 4px 10px; font-weight: 800;
    font-family: 'DM Mono', monospace; font-size: 0.86rem;
    border: 1px solid; border-left-width: 3px; background: #fff;
}
.final-grade-badge.grade-a  { background: #fff; color: var(--fo-green); border-color: var(--fo-green-bar); }
.final-grade-badge.grade-b  { background: #fff; color: var(--fo-navy); border-color: var(--fo-navy); }
.final-grade-badge.grade-c  { background: #fff; color: var(--fo-gold-ink); border-color: var(--fo-gold); }
.final-grade-badge.grade-d  { background: #fff; color: var(--fo-red); border-color: var(--fo-red); }
.grade-letter { font-size: 0.62rem; opacity: 0.85; font-family: 'DM Sans', sans-serif; font-weight: 700; text-transform: uppercase; letter-spacing: 0.4px; }

/* Publish status — Option 4 shows status as bold colored text */
.pub-status {
    display: inline-flex; align-items: center; gap: 5px;
    border-radius: 0; padding: 0;
    font-size: 0.7rem; font-weight: 700; white-space: nowrap; border: none;
    text-transform: uppercase; letter-spacing: 0.4px; background: none;
}
.pub-status.published   { background: none; color: var(--fo-green); border-color: transparent; }
.pub-status.unpublished { background: none; color: var(--text-secondary); border-color: transparent; }

.pub-date { font-size: 0.66rem; color: var(--text-muted); font-family: 'DM Mono', monospace; display: block; margin-top: 3px; }

/* Action column */
.action-cell { text-align: center; white-space: nowrap; }

/* Upload / Unupload toggle button */
.btn-toggle-pub {
    display: inline-flex; align-items: center; gap: 6px;
    border: 1px solid var(--fo-line); border-radius: 0; padding: 7px 12px;
    font-size: 11px; font-weight: 700; font-family: 'DM Sans', sans-serif;
    text-transform: uppercase; letter-spacing: 0.4px;
    cursor: pointer; transition: background 0.15s, color 0.15s, border-color 0.15s; white-space: nowrap;
}
.btn-toggle-pub.do-publish {
    background: var(--fo-navy); border-color: var(--fo-navy);
    color: white; box-shadow: none;
}
.btn-toggle-pub.do-publish:hover:not(:disabled) { background: var(--fo-navy-2); border-color: var(--fo-navy-2); }
.btn-toggle-pub.do-unpublish {
    background: #fff; color: var(--fo-red);
    border: 1px solid var(--fo-line);
}
.btn-toggle-pub.do-unpublish:hover:not(:disabled) { background: var(--fo-red); border-color: var(--fo-red); color: #fff; }
.btn-toggle-pub:hover:not(:disabled) { opacity: 1; transform: none; }
.btn-toggle-pub:focus-visible { outline: 2px solid var(--fo-gold); outline-offset: 2px; }
.btn-toggle-pub:disabled { opacity: 0.5; cursor: not-allowed; }

/* Row highlighting when published — green left bar + faint green wash */
.grades-table tbody tr.is-published td { background: #F3F7F1; }
.grades-table tbody tr.is-published td.td-check { border-left-color: var(--fo-green-bar); }
.grades-table tbody tr.is-published:hover td { background: #E7F0E3; }

/* ── NEW: highlight flash for a row inserted live by the auto-detect
   poller, so the admin's eye is drawn to newly-appeared evaluations
   without any layout shift or disruptive animation. ── */
@keyframes newRowFadeIn {
    0%   { background: #FBF6E3; }
    100% { background: transparent; }
}
.grade-row.new-grade-highlight td { animation: newRowFadeIn 3s ease-out; }

/* Checkbox styling */
.row-checkbox { accent-color: var(--fo-navy); width: 15px; height: 15px; cursor: pointer; }
.select-all-cb { accent-color: var(--fo-navy); width: 15px; height: 15px; cursor: pointer; }

/* ── EMPTY / NO-MATCH STATE ── */
.page-empty {
    text-align: center; padding: 70px 20px;
    color: var(--text-secondary);
    background: #fff; border: 1px solid var(--fo-line); border-left: 3px solid var(--fo-line);
}
.page-empty i { font-size: 2.6rem; margin-bottom: 14px; display: block; opacity: 1; color: var(--fo-line); }
.page-empty h3 { font-size: 0.88rem; margin-bottom: 8px; color: var(--fo-navy); text-transform: uppercase; letter-spacing: 0.6px; }
.page-empty p { font-size: 0.84rem; max-width: 480px; margin: 0 auto; }
.no-match-row td { text-align: center; color: var(--text-secondary); font-style: normal; padding: 30px; font-size: 0.74rem; font-weight: 700; text-transform: uppercase; letter-spacing: 0.5px; }

/* ── PAGINATION ── */
.pagination-bar {
    display: flex; align-items: center; justify-content: space-between;
    flex-wrap: wrap; gap: 12px; margin-bottom: 40px;
}
.pagination-info { font-size: 11px; color: var(--text-secondary); font-weight: 700; text-transform: uppercase; letter-spacing: 0.4px; }
.pagination-controls { display: flex; align-items: center; gap: 4px; flex-wrap: wrap; }
.page-btn {
    min-width: 34px; height: 34px; padding: 0 10px;
    border: 1px solid var(--fo-line); background: var(--surface);
    border-radius: 0; font-size: 0.8rem; font-weight: 700;
    color: var(--fo-navy); cursor: pointer; font-family: 'DM Sans', sans-serif;
    transition: background 0.15s, color 0.15s; display: inline-flex; align-items: center; justify-content: center;
}
.page-btn:hover:not(:disabled):not(.active) { background: var(--fo-hover); color: var(--fo-navy); }
.page-btn.active { background: var(--fo-navy); border-color: var(--fo-navy); color: white; }
.page-btn:disabled { opacity: 0.4; cursor: not-allowed; }
.page-ellipsis { padding: 0 6px; color: var(--text-muted); font-size: 0.82rem; }

/* ── TOAST ── */
.toast {
    position: fixed; bottom: 22px; left: 50%; transform: translateX(-50%);
    background: var(--fo-navy); color: #E3E8F1; border: 1px solid #55668C;
    padding: 11px 22px; border-radius: 0; box-shadow: 0 8px 24px rgba(27,42,74,0.30);
    font-size: 0.82rem; font-weight: 600; z-index: 3000; opacity: 0; transition: opacity 0.3s;
    pointer-events: none; white-space: nowrap; max-width: 90vw;
}
.toast.show { opacity: 1; }

/* ── HEADER LINK BUTTON ── */
.btn-header-link {
    display: inline-flex; align-items: center; gap: 7px;
    background: #fff; border: 1px solid var(--fo-line);
    color: var(--fo-navy); border-radius: 0; padding: 8px 14px; font-size: 12px;
    font-weight: 700; text-transform: uppercase; letter-spacing: 0.4px;
    text-decoration: none; transition: background 0.15s, color 0.15s;
    font-family: 'DM Sans', sans-serif; cursor: pointer;
}
.btn-header-link:hover { background: var(--fo-navy); border-color: var(--fo-navy); color: #fff; }

/* ── EXPORT BUTTON (legacy style kept for compatibility, no longer used
     in the header now that Export lives in the controls bar) ── */
.btn-export {
    display: inline-flex; align-items: center; gap: 7px;
    background: #fff; border: 1px solid var(--fo-line);
    color: var(--fo-navy); border-radius: 0; padding: 8px 14px; font-size: 12px;
    font-weight: 700; text-transform: uppercase; letter-spacing: 0.4px;
    text-decoration: none; transition: background 0.15s, color 0.15s;
    font-family: 'DM Sans', sans-serif; cursor: pointer; white-space: nowrap;
}
.btn-export:hover { background: var(--fo-navy); border-color: var(--fo-navy); color: #fff; }

/* Overflow scrollable table wrapper */
.table-scroll { overflow-x: auto; }

@media (max-width: 768px) {
    .stats-row { grid-template-columns: 1fr 1fr; }
    .bulk-count { margin-left: 0; }
}

/* ── EXPORT MODAL ── */
.export-modal-overlay {
    position: fixed; inset: 0; background: rgba(27, 42, 74, 0.55);
    backdrop-filter: none; -webkit-backdrop-filter: none;
    z-index: 2000; display: flex; align-items: center; justify-content: center;
    opacity: 0; pointer-events: none; transition: opacity 0.2s;
}
.export-modal-overlay.open { opacity: 1; pointer-events: all; }
.export-modal {
    background: var(--surface); border-radius: 0;
    border: 1px solid var(--fo-line); border-top: 3px solid var(--fo-navy);
    box-shadow: var(--shadow-lg); padding: 24px 24px 20px;
    width: 100%; max-width: 420px; margin: 16px;
    transform: translateY(12px);
    transition: transform 0.2s ease;
}
.export-modal-overlay.open .export-modal { transform: translateY(0); }

.export-modal-icon {
    width: 40px; height: 40px; border-radius: 0;
    background: var(--fo-navy);
    display: flex; align-items: center; justify-content: center;
    color: #fff; font-size: 1.05rem; margin-bottom: 14px;
}
.export-modal h3 {
    font-size: 0.88rem; font-weight: 700; color: var(--fo-navy); margin-bottom: 4px;
    text-transform: uppercase; letter-spacing: 0.6px;
}
.export-modal p {
    font-size: 0.8rem; color: var(--text-secondary); margin-bottom: 18px;
}
.export-modal label {
    display: block; font-size: 0.68rem; font-weight: 700;
    color: var(--fo-navy); text-transform: uppercase;
    letter-spacing: 0.5px; margin-bottom: 6px;
}
.export-filename-wrap { position: relative; margin-bottom: 20px; }
.export-filename-input {
    width: 100%; padding: 10px 52px 10px 12px;
    border: 1px solid var(--fo-line); border-radius: 0;
    font-size: 0.86rem; font-family: 'DM Mono', monospace;
    color: var(--fo-navy); background: var(--fo-head);
    transition: border-color 0.15s, background 0.15s;
}
.export-filename-input:focus {
    outline: none; border-color: var(--fo-navy); background: var(--surface);
}
.export-filename-ext {
    position: absolute; right: 12px; top: 50%; transform: translateY(-50%);
    font-size: 0.72rem; font-weight: 700; color: var(--text-secondary);
    font-family: 'DM Mono', monospace; pointer-events: none;
}
.export-modal-actions { display: flex; gap: 8px; }
.btn-modal-cancel {
    flex: 1; padding: 10px; border: 1px solid var(--fo-line);
    border-radius: 0; background: var(--surface);
    color: var(--fo-navy); font-size: 12px; font-weight: 700;
    text-transform: uppercase; letter-spacing: 0.4px;
    font-family: 'DM Sans', sans-serif; cursor: pointer;
    transition: background 0.15s, color 0.15s;
}
.btn-modal-cancel:hover { background: var(--fo-hover); color: var(--fo-navy); }
.btn-modal-export {
    flex: 2; padding: 10px; border: 1px solid var(--fo-navy);
    border-radius: 0;
    background: var(--fo-navy);
    color: white; font-size: 12px; font-weight: 700;
    text-transform: uppercase; letter-spacing: 0.4px;
    font-family: 'DM Sans', sans-serif; cursor: pointer;
    display: flex; align-items: center; justify-content: center; gap: 7px;
    transition: background 0.15s;
}
.btn-modal-export:hover { background: var(--fo-navy-2); border-color: var(--fo-navy-2); }
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
@media (prefers-reduced-motion: reduce) {
    .bulk-btn, .btn-toggle-pub, .page-btn, .export-modal, .export-modal-overlay { transition: none; }
    .grade-row.new-grade-highlight td { animation: none; }
}
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

<!-- ══════ SIDEBAR ══════ -->
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
        <!-- ── NEW: pending-MOA badge added to the Company Requirements link
             so it's visible from this page too, not just company_validation.php
             / admin_student_list.php / admin_monitoring_dashboard.php. ── -->
        <a href="company_validation.php" style="position:relative;">
            <i class="fas fa-building"></i>
            <span class="link-text">Company Requirements</span>
            <?php if ($moa_pending_count > 0): ?>
                <span class="sidebar-badge-moa" id="sidebarMoaBadge"><?= $moa_pending_count ?></span>
            <?php else: ?>
                <span class="sidebar-badge-moa" id="sidebarMoaBadge" style="display:none"><?= $moa_pending_count ?></span>
            <?php endif; ?>
        </a>
        <a href="monitoring.php" style="position:relative;"><i class="fas fa-users-cog"></i><span class="link-text">Manage Accounts</span><!-- NEW (this adjustment): Email Recovery Requests indicator — same badge look as the application-request badge --><span class="sidebar-badge-app sidebar-badge-recovery" id="sidebarRecoveryBadge"<?= $recovery_pending_count > 0 ? '' : ' style="display:none"' ?>><?= (int)$recovery_pending_count ?></span></a>
        <a href="admin_monitoring_dashboard.php">
            <i class="fas fa-chart-line"></i>
            <span class="link-text">Monitoring Dashboard</span>
        </a>
        <a href="admin_final_grades.php" class="active">
            <i class="fas fa-graduation-cap"></i>
            <span class="link-text">Final Grades</span>
        </a>
        <a href="system_setting.php" ><i class="fas fa-gear"></i><span class="link-text">System Setting</span></a>
    </div>
    <div class="logout-link">
        <a href="admin_login.php?logout=1"><i class="fas fa-sign-out-alt"></i><span class="link-text">Logout</span></a>
    </div>
</div>

<!-- ══════ MAIN ══════ -->
<div class="main-content">

    <!-- ══════ TOP NAV HEADER (same as admin_monitoring_dashboard.php) ══════ -->
    <nav class="navbar">
        <div class="logo-section">
            <img src="logo.webp" class="university-logo" alt="NEUST Logo">
            <div>
                <div style="font-weight:bold; font-size:16px;">NEUST Atate Campus</div>
                <div style="font-size:11px; color:var(--neust-gold);">Web-Based Smart OJT Monitoring and Supervision Analytics System</div>
            </div>
        </div>
    </nav>

    <!-- CONTENT -->
    <div class="content-wrap">

        <!-- Stats -->
        <div class="stats-row">
            <div class="stat-card">
                <div class="stat-icon total"><i class="fas fa-users"></i></div>
                <div>
                    <div class="stat-val" id="statTotal"><?= $total_students ?></div>
                    <div class="stat-label">Total Evaluated Students</div>
                </div>
            </div>
            <div class="stat-card">
                <div class="stat-icon pub"><i class="fas fa-eye"></i></div>
                <div>
                    <div class="stat-val" id="statPub"><?= $total_published ?></div>
                    <div class="stat-label">Published to Students</div>
                </div>
            </div>
            <div class="stat-card">
                <div class="stat-icon pending"><i class="fas fa-eye-slash"></i></div>
                <div>
                    <div class="stat-val" id="statPending"><?= $total_pending ?></div>
                    <div class="stat-label">Not Yet Published</div>
                </div>
            </div>
        </div>

        <!-- Controls -->
        <div class="controls-bar">
            <div class="search-wrap">
                <i class="fas fa-search"></i>
                <input type="text" class="search-input" id="searchInput" placeholder="Search student or company…">
            </div>
            <select class="filter-select" id="filterStatus" onchange="applyFilters()">
                <option value="all">All Status</option>
                <option value="published">Published</option>
                <option value="unpublished">Not Published</option>
            </select>
            <button class="bulk-btn publish" id="btnBulkPublish" onclick="bulkToggle(1)" disabled>
                <i class="fas fa-upload"></i> Upload Selected
            </button>
            <button class="bulk-btn unpublish" id="btnBulkUnpublish" onclick="bulkToggle(0)" disabled>
                <i class="fas fa-eye-slash"></i> Unupload Selected
            </button>
            <!-- ── Export All Grades button — moved here, right after
                 "Unupload Selected", and restyled to match the bulk-btn
                 family instead of the old header-pill style. ── -->
            <button class="bulk-btn export" onclick="exportGradesCSV()" title="Export all final grades to CSV">
                <i class="fas fa-file-csv"></i> Export All Grades
            </button>
            <!-- UPDATED (this adjustment): hidden until at least one row is selected — see onCheckChange() -->
            <span class="bulk-count" id="selectionCount" style="display:none">0 selected</span>
        </div>

        <!-- Empty state -->
        <?php if (empty($all_grades)): ?>
        <div class="page-empty" id="pageEmptyState">
            <i class="fas fa-graduation-cap"></i>
            <h3>No Evaluations Submitted Yet</h3>
            <p>Ratings appear here automatically once a company submits and locks a student's OJT/Internship Training Plan evaluation.</p>
        </div>
        <?php else: ?>

        <!-- ══════════════════════════════════════════════
             UNIFIED GRADES TABLE
             ------------------------------------------------------------
             All students from every company now live in a single table
             (company name shown as its own column) instead of being
             split into a separate table per company. Pagination (10
             students per page) is handled client-side in JS below. ══════════════════════════════════════════════ -->
        <div class="grades-table-wrap" id="gradesTableWrap">
            <div class="table-scroll">
            <table class="grades-table" id="gradesTable">
                <thead>
                    <tr>
                        <th class="th-check">
                            <input type="checkbox" class="select-all-cb" id="selectAllCb" onchange="toggleSelectAll(this)">
                        </th>
                        <th>Student</th>
                        <th>Company</th>
                        <th class="th-center">General Competency</th>
                        <th class="th-center">Specific Competency</th>
                        <th class="th-center">Overall Rating</th>
                        <th class="th-center">Status</th>
                        <th class="th-center">Action</th>
                    </tr>
                </thead>
                <tbody id="gradesTableBody">
                <?php foreach ($all_grades as $row):
                    $fg_id = $row['student_id'] . '_' . $row['company_id'];
                    $gradeVal = $row['overall_competency_rating'] !== null ? (float)$row['overall_competency_rating'] : null;
                    $gclass = $gradeVal !== null ? ratingGradeClass($gradeVal) : 'grade-d';
                    $rlabel = $gradeVal !== null ? ratingLabel($gradeVal) : '—';
                    $cname  = $row['company_name'] ?? 'Unknown Company';
                ?>
                <tr class="grade-row <?= $row['is_published'] ? 'is-published' : '' ?>"
                    data-fg-id="<?= htmlspecialchars($fg_id ?? '') ?>"
                    data-student="<?= htmlspecialchars($row['student_first'] . ' ' . $row['student_last']) ?>"
                    data-company="<?= htmlspecialchars($cname ?? '') ?>"
                    data-published="<?= $row['is_published'] ?>"
                    data-course="<?= htmlspecialchars($row['course'] ?? '') ?>"
                    data-general-rating="<?= $row['general_competency_rating'] !== null ? number_format((float)$row['general_competency_rating'], 2) : '' ?>"
                    data-specific-rating="<?= $row['specific_competency_rating'] !== null ? number_format((float)$row['specific_competency_rating'], 2) : '' ?>"
                    data-overall-rating="<?= $gradeVal !== null ? number_format($gradeVal, 2) : '' ?>"
                    data-eval-submitted-at="<?= htmlspecialchars($row['eval_submitted_at'] ?? '') ?>"
                    data-published-at="<?= htmlspecialchars($row['published_at'] ?? '') ?>">
                    <td class="td-check">
                        <input type="checkbox" class="row-checkbox" value="<?= htmlspecialchars($fg_id ?? '') ?>" onchange="onCheckChange()">
                    </td>
                    <td>
                        <div class="student-name-cell">
                            <div class="sn-name"><?= htmlspecialchars($row['student_first'] . ' ' . $row['student_last']) ?></div>
                            <div class="sn-course"><?= htmlspecialchars($row['course'] ?? '—') ?></div>
                        </div>
                    </td>
                    <td>
                        <span class="company-cell-name"><i class="fas fa-building" style="opacity:0.45;margin-right:6px;font-size:0.78rem;"></i><?= htmlspecialchars($cname ?? '') ?></span>
                    </td>
                    <td class="th-center" style="text-align:center;">
                        <?php if ($row['general_competency_rating'] !== null): ?>
                            <span class="gv company-g"><?= number_format($row['general_competency_rating'], 2) ?></span>
                        <?php else: ?>
                            <span style="color:var(--text-muted);font-style:italic;font-size:0.78rem;">—</span>
                        <?php endif; ?>
                    </td>
                    <td style="text-align:center;">
                        <?php if ($row['specific_competency_rating'] !== null): ?>
                            <span class="gv admin-g"><?= number_format($row['specific_competency_rating'], 2) ?></span>
                        <?php else: ?>
                            <span style="color:var(--text-muted);font-style:italic;font-size:0.78rem;">—</span>
                        <?php endif; ?>
                    </td>
                    <td style="text-align:center;">
                        <?php if ($gradeVal !== null): ?>
                        <span class="final-grade-badge <?= $gclass ?>">
                            <?= number_format($gradeVal, 2) ?>
                            <span class="grade-letter"><?= htmlspecialchars($rlabel ?? '') ?></span>
                        </span>
                        <?php else: ?>
                            <span style="color:var(--text-muted);font-style:italic;font-size:0.78rem;">—</span>
                        <?php endif; ?>
                    </td>
                    <td style="text-align:center;">
                        <span class="pub-status <?= $row['is_published'] ? 'published' : 'unpublished' ?>"
                              id="pubstatus-<?= htmlspecialchars($fg_id ?? '') ?>">
                            <?php if ($row['is_published']): ?>
                                <i class="fas fa-eye"></i> Published
                            <?php else: ?>
                                <i class="fas fa-eye-slash"></i> Hidden
                            <?php endif; ?>
                        </span>
                        <?php if ($row['is_published'] && $row['published_at']): ?>
                        <span class="pub-date" id="pubdate-<?= htmlspecialchars($fg_id ?? '') ?>">
                            <?= date('M d, Y', strtotime($row['published_at'] ?? '')) ?>
                        </span>
                        <?php else: ?>
                        <span class="pub-date" id="pubdate-<?= htmlspecialchars($fg_id ?? '') ?>"></span>
                        <?php endif; ?>
                    </td>
                    <td class="action-cell">
                        <?php if ($row['is_published']): ?>
                        <button class="btn-toggle-pub do-unpublish"
                                id="togglebtn-<?= htmlspecialchars($fg_id ?? '') ?>"
                                onclick="togglePublish('<?= htmlspecialchars($fg_id ?? '') ?>', 0)">
                            <i class="fas fa-eye-slash"></i> Unupload
                        </button>
                        <?php else: ?>
                        <button class="btn-toggle-pub do-publish"
                                id="togglebtn-<?= htmlspecialchars($fg_id ?? '') ?>"
                                onclick="togglePublish('<?= htmlspecialchars($fg_id ?? '') ?>', 1)">
                            <i class="fas fa-upload"></i> Upload
                        </button>
                        <?php endif; ?>
                    </td>
                </tr>
                <?php endforeach; ?>
                <tr class="no-match-row" id="noMatchRow" style="display:none;">
                    <td colspan="8">No matching records found.</td>
                </tr>
                </tbody>
            </table>
            </div>
        </div>

        <!-- Pagination (10 students per page) -->
        <div class="pagination-bar" id="paginationBar">
            <div class="pagination-info" id="paginationInfo"></div>
            <div class="pagination-controls" id="paginationControls"></div>
        </div>

        <?php endif; ?>

    </div><!-- end content-wrap -->
</div><!-- end main-content -->

<div class="toast" id="toast"></div>

<!-- ══════ EXPORT MODAL ══════ -->
<div class="export-modal-overlay" id="exportModalOverlay">
    <div class="export-modal" role="dialog" aria-modal="true" aria-labelledby="exportModalTitle">
        <div class="export-modal-icon"><i class="fas fa-file-csv"></i></div>
        <h3 id="exportModalTitle">Export Final Grades</h3>
        <p>Enter a file name for your CSV export, then click <strong>Export</strong>.</p>
        <label for="exportFilenameInput">File Name</label>
        <div class="export-filename-wrap">
            <input type="text" class="export-filename-input" id="exportFilenameInput"
                   spellcheck="false" autocomplete="off" placeholder="final_grades_YYYY-MM-DD">
            <span class="export-filename-ext">.xlsx</span>
        </div>
        <div class="export-modal-actions">
            <button class="btn-modal-cancel" onclick="closeExportModal()">
                <i class="fas fa-times"></i> Cancel
            </button>
            <button class="btn-modal-export" onclick="confirmExport()">
                <i class="fas fa-download"></i> Export
            </button>
        </div>
    </div>
</div>

<script>
/* ── SIDEBAR TOGGLE ── */
document.getElementById('toggleBtn').addEventListener('click', () => {
    const sb = document.getElementById('sidebar');
    sb.classList.toggle('collapsed');
    const mc = document.querySelector('.main-content');
    mc.style.marginLeft = sb.classList.contains('collapsed') ? '80px' : '260px';
    mc.style.width      = sb.classList.contains('collapsed') ? 'calc(100% - 80px)' : 'calc(100% - 260px)';
});

/* ══════════════════════════════════════════════
   SEARCH + FILTER + PAGINATION (10 students / page)
   ------------------------------------------------------------
   All students now live in a single table (with a Company column),
   so search/status filtering are combined with client-side pagination:
   getFilteredRows() computes the full matching set, then renderTable()
   slices out just the current page's worth (PAGE_SIZE rows) to show.
══════════════════════════════════════════════ */
const PAGE_SIZE = 10;
let currentPage = 1;

const searchInputEl = document.getElementById('searchInput');
if (searchInputEl) searchInputEl.addEventListener('keyup', applyFilters);

function getFilteredRows() {
    const q      = (document.getElementById('searchInput')?.value || '').toLowerCase();
    const status = document.getElementById('filterStatus')?.value || 'all';
    return [...document.querySelectorAll('.grade-row')].filter(row => {
        const student = (row.dataset.student || '').toLowerCase();
        const company = (row.dataset.company || '').toLowerCase();
        const isPub   = row.dataset.published === '1';
        const matchQ  = student.includes(q) || company.includes(q);
        const matchSt = status === 'all'
            || (status === 'published'   &&  isPub)
            || (status === 'unpublished' && !isPub);
        return matchQ && matchSt;
    });
}

/* Called whenever the search box or status filter changes — resets back to page 1 */
function applyFilters() {
    currentPage = 1;
    renderTable();
}

/* Renders the current page: hides every row, then shows only the slice
   of filtered rows that belongs on currentPage, and rebuilds the
   pagination controls to match. */
function renderTable() {
    const tbody = document.getElementById('gradesTableBody');
    if (!tbody) return;

    const filtered   = getFilteredRows();
    const totalItems = filtered.length;
    const totalPages = Math.max(1, Math.ceil(totalItems / PAGE_SIZE));
    if (currentPage > totalPages) currentPage = totalPages;
    if (currentPage < 1) currentPage = 1;

    const start    = (currentPage - 1) * PAGE_SIZE;
    const pageSet  = new Set(filtered.slice(start, start + PAGE_SIZE));

    document.querySelectorAll('.grade-row').forEach(row => {
        const show = pageSet.has(row);
        row.style.display = show ? '' : 'none';
        if (!show) {
            const cb = row.querySelector('.row-checkbox');
            if (cb) cb.checked = false;
        }
    });

    const noMatchRow = document.getElementById('noMatchRow');
    if (noMatchRow) noMatchRow.style.display = totalItems === 0 ? '' : 'none';

    renderPaginationControls(totalItems, totalPages);
    onCheckChange();
}

function renderPaginationControls(totalItems, totalPages) {
    const bar      = document.getElementById('paginationBar');
    const infoEl   = document.getElementById('paginationInfo');
    const ctrlsEl  = document.getElementById('paginationControls');
    if (!bar || !infoEl || !ctrlsEl) return;

    if (totalItems === 0) {
        infoEl.textContent = 'No records to show';
        ctrlsEl.innerHTML = '';
        return;
    }

    const start = (currentPage - 1) * PAGE_SIZE + 1;
    const end   = Math.min(currentPage * PAGE_SIZE, totalItems);
    infoEl.textContent = `Showing ${start}–${end} of ${totalItems} student${totalItems !== 1 ? 's' : ''}`;

    let html = '';
    html += `<button class="page-btn" ${currentPage === 1 ? 'disabled' : ''} onclick="goToPage(${currentPage - 1})" title="Previous page"><i class="fas fa-chevron-left"></i></button>`;

    const pagesToShow = new Set([1, totalPages, currentPage, currentPage - 1, currentPage + 1]);
    let lastRendered = 0;
    for (let p = 1; p <= totalPages; p++) {
        if (!pagesToShow.has(p)) continue;
        if (p - lastRendered > 1) html += `<span class="page-ellipsis">…</span>`;
        html += `<button class="page-btn ${p === currentPage ? 'active' : ''}" onclick="goToPage(${p})">${p}</button>`;
        lastRendered = p;
    }

    html += `<button class="page-btn" ${currentPage === totalPages ? 'disabled' : ''} onclick="goToPage(${currentPage + 1})" title="Next page"><i class="fas fa-chevron-right"></i></button>`;
    ctrlsEl.innerHTML = html;
}

function goToPage(p) {
    currentPage = p;
    renderTable();
}

/* ── CHECKBOX SELECTION ──
   Select-all now only toggles the checkboxes for rows currently
   visible on this page (there's just one table now). */
function toggleSelectAll(masterCb) {
    document.querySelectorAll('.grade-row').forEach(row => {
        if (row.style.display !== 'none') {
            const cb = row.querySelector('.row-checkbox');
            if (cb) cb.checked = masterCb.checked;
        }
    });
    onCheckChange();
}

function onCheckChange() {
    const checked = [...document.querySelectorAll('.row-checkbox:checked')];
    const count   = checked.length;
    document.getElementById('selectionCount').textContent = count + ' selected';
    document.getElementById('selectionCount').style.display = count > 0 ? '' : 'none';   // UPDATED (this adjustment): only shown while the admin is selecting
    document.getElementById('btnBulkPublish').disabled   = count === 0;
    document.getElementById('btnBulkUnpublish').disabled = count === 0;
}

/* ── TOGGLE PUBLISH (single) ──
   fgId is a composite "{student_id}_{company_id}" string, since
   ojt_assignments rows are identified by that pair rather than a
   single numeric id. */
function togglePublish(fgId, publish) {
    const btn = document.getElementById('togglebtn-' + fgId);
    btn.disabled = true;
    btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i>';

    const parts     = String(fgId).split('_');
    const studentId = parts[0];
    const companyId = parts[1];

    const fd = new FormData();
    fd.append('student_id', studentId);
    fd.append('company_id', companyId);
    fd.append('publish', publish);

    fetch('admin_final_grades.php?toggle_publish=1', { method: 'POST', body: fd })
        .then(r => r.json())
        .then(data => {
            if (data.success) {
                updateRowUI(fgId, publish);
                updateStatCounts();
                showToast(publish
                    ? 'Evaluation uploaded — student can now see it.'
                    : 'Evaluation hidden from student.');
            } else {
                showToast(data.message || 'Failed.');
                btn.disabled = false;
                btn.innerHTML = publish
                    ? '<i class="fas fa-upload"></i> Upload'
                    : '<i class="fas fa-eye-slash"></i> Unupload';
            }
        })
        .catch(() => {
            showToast('Network error.');
            btn.disabled = false;
        });
}

/* ── BULK TOGGLE ──
   Selected checkbox values are already composite "{student_id}_{company_id}"
   strings, joined with commas — matches the parsing on the server. */
function bulkToggle(publish) {
    const checked = [...document.querySelectorAll('.row-checkbox:checked')];
    if (!checked.length) return;
    const ids = checked.map(cb => cb.value).join(',');

    document.getElementById('btnBulkPublish').disabled   = true;
    document.getElementById('btnBulkUnpublish').disabled = true;

    // Disable individual buttons for selected rows
    checked.forEach(cb => {
        const fgId = cb.value;
        const btn  = document.getElementById('togglebtn-' + fgId);
        if (btn) { btn.disabled = true; btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i>'; }
    });

    const fd = new FormData();
    fd.append('ids', ids);
    fd.append('publish', publish);

    fetch('admin_final_grades.php?bulk_publish=1', { method: 'POST', body: fd })
        .then(r => r.json())
        .then(data => {
            if (data.success) {
                checked.forEach(cb => {
                    updateRowUI(cb.value, publish);
                    cb.checked = false;
                });
                updateStatCounts();
                showToast(publish
                    ? `${data.affected} record(s) uploaded to students.`
                    : `${data.affected} record(s) hidden from students.`);
            } else {
                showToast(data.message || 'Bulk action failed.');
            }
        })
        .catch(() => showToast('Network error.'))
        .finally(() => {
            onCheckChange(); // re-evaluate button states
        });
}

/* ── UPDATE SINGLE ROW UI ── */
function updateRowUI(fgId, publish) {
    const row    = document.querySelector('[data-fg-id="' + fgId + '"]');
    const badge  = document.getElementById('pubstatus-' + fgId);
    const btn    = document.getElementById('togglebtn-' + fgId);
    const datEl  = document.getElementById('pubdate-' + fgId);

    if (!row) return;

    if (publish) {
        row.classList.add('is-published');
        row.dataset.published = '1';
        if (badge) {
            badge.className = 'pub-status published';
            badge.innerHTML = '<i class="fas fa-eye"></i> Published';
        }
        if (datEl) {
            const now = new Date();
            datEl.textContent = now.toLocaleDateString('en-US', {month:'short',day:'numeric',year:'numeric'});
        }
        if (btn) {
            btn.className = 'btn-toggle-pub do-unpublish';
            btn.innerHTML = '<i class="fas fa-eye-slash"></i> Unupload';
            btn.disabled  = false;
            btn.onclick   = () => togglePublish(fgId, 0);
        }
    } else {
        row.classList.remove('is-published');
        row.dataset.published = '0';
        if (badge) {
            badge.className = 'pub-status unpublished';
            badge.innerHTML = '<i class="fas fa-eye-slash"></i> Hidden';
        }
        if (datEl) datEl.textContent = '';
        if (btn) {
            btn.className = 'btn-toggle-pub do-publish';
            btn.innerHTML = '<i class="fas fa-upload"></i> Upload';
            btn.disabled  = false;
            btn.onclick   = () => togglePublish(fgId, 1);
        }
    }

    // A publish/unpublish can move a row in/out of the current status
    // filter (e.g. filter = "Not Published" and the row just got
    // published) — re-run filtering/pagination so the view stays accurate.
    renderTable();
}

/* ── UPDATE STAT COUNTS ── */
function updateStatCounts() {
    const total      = document.querySelectorAll('.grade-row').length;
    const published  = document.querySelectorAll('.grade-row.is-published').length;
    const pending    = total - published;
    const pubEl  = document.getElementById('statPub');
    const pendEl = document.getElementById('statPending');
    if (pubEl)  pubEl.textContent  = published;
    if (pendEl) pendEl.textContent = pending;
}

/* ── TOAST ── */
function showToast(msg) {
    const t = document.getElementById('toast');
    t.textContent = msg;
    t.classList.add('show');
    setTimeout(() => t.classList.remove('show'), 2800);
}

/* ══════════════════════════════════════════════
   EXPORT ALL GRADES TO CSV — MODAL FLOW
══════════════════════════════════════════════ */

/* Open the export modal and pre-fill default filename */
function exportGradesCSV() {
    const rows = document.querySelectorAll('.grade-row');
    if (!rows.length) {
        showToast('No grade records to export.');
        return;
    }
    const now     = new Date();
    const dateStr = now.toISOString().slice(0, 10); // YYYY-MM-DD
    const input   = document.getElementById('exportFilenameInput');
    input.value   = `final_grades_${dateStr}`;

    const overlay = document.getElementById('exportModalOverlay');
    overlay.classList.add('open');

    // Focus + select all text so user can type right away
    setTimeout(() => { input.focus(); input.select(); }, 80);
}

/* Close without exporting */
function closeExportModal() {
    document.getElementById('exportModalOverlay').classList.remove('open');
}

/* Close modal when clicking outside the card */
document.getElementById('exportModalOverlay').addEventListener('click', function(e) {
    if (e.target === this) closeExportModal();
});

/* Allow Enter key to confirm export, Escape to cancel */
document.getElementById('exportFilenameInput').addEventListener('keydown', function(e) {
    if (e.key === 'Enter')  { e.preventDefault(); confirmExport(); }
    if (e.key === 'Escape') { closeExportModal(); }
});

/* Confirm: build XLSX and download with the user-supplied name.
   NOTE: export always includes ALL grade records regardless of the
   current page or filter — pagination only affects what's shown on
   screen, never what gets exported. */
function confirmExport() {
    const rawName  = document.getElementById('exportFilenameInput').value.trim();
    const now      = new Date();
    const dateStr  = now.toISOString().slice(0, 10);
    const baseName = rawName.length ? rawName : `final_grades_${dateStr}`;
    // Strip any trailing extension the user may have typed
    const cleanName = baseName.replace(/\.(xlsx?|csv)$/i, '');
    const filename  = `${cleanName}.xlsx`;

    const rows = document.querySelectorAll('.grade-row');

    const headers = [
        'Company',
        'Student Name',
        'Course',
        'General Competency Rating',
        'Specific Competency Rating',
        'Weight General (%)',
        'Weight Specific (%)',
        'Overall Competency Rating'
    ];

    // Build data array: first row = headers
    const data = [headers];

    rows.forEach(row => {
        const company        = row.dataset.company        || '';
        const student        = row.dataset.student        || '';
        const course         = row.dataset.course          || '';
        const generalRating  = row.dataset.generalRating   || '';
        const specificRating = row.dataset.specificRating  || '';
        const overallRating  = row.dataset.overallRating   || '';

        data.push([
            company,
            student,
            course,
            generalRating  !== '' ? parseFloat(generalRating)  : '',
            specificRating !== '' ? parseFloat(specificRating) : '',
            40, // General weight is fixed at 40% (see tpUpdateTotals() in company_reports.php)
            60, // Specific weight is fixed at 60%
            overallRating  !== '' ? parseFloat(overallRating)  : ''
        ]);
    });

    // Create worksheet from data array
    const ws = XLSX.utils.aoa_to_sheet(data);

    // Auto-fit column widths based on the longest value in each column
    const colWidths = headers.map((h, colIdx) => {
        let max = h.length;
        for (let r = 1; r < data.length; r++) {
            const val = data[r][colIdx];
            const len = val !== null && val !== undefined ? String(val).length : 0;
            if (len > max) max = len;
        }
        return { wch: max + 4 }; // +4 padding so text never feels cramped
    });
    ws['!cols'] = colWidths;

    // Create workbook and append sheet
    const wb = XLSX.utils.book_new();
    XLSX.utils.book_append_sheet(wb, ws, 'Final Grades');

    // Trigger download
    XLSX.writeFile(wb, filename);

    closeExportModal();
    showToast(`Exported ${rows.length - 0} record(s) as "${filename}"`);
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
// Mirrors the same moa_pending_count AJAX endpoint used inside
// company_validation.php's own polling script (and now also in
// admin_student_list.php / admin_monitoring_dashboard.php), so the badge
// here stays in sync with admin actions taken on that page (accepting/
// rejecting a MOA request) without requiring a full page reload.
(function() {
    function pollMoaBadge() {
        // FIX (sidebar notification indicator): polls this page's own endpoint, which uses the
        // same notification rule as company_validation.php (see final_grades_company_validation_notif_count()).
        fetch('admin_final_grades.php?cv_sidebar_notif_count=1', { credentials: 'same-origin' })
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

/* ══════════════════════════════════════════════════════════════════
   AUTO-DETECT NEWLY-SUBMITTED EVALUATION RATINGS (LIVE POLL)
   ------------------------------------------------------------------
   Polls admin_final_grades.php?poll_grades=1 on an interval. That
   endpoint returns every ojt_assignments row that currently has a
   locked evaluation (eval_submitted_at IS NOT NULL) — exactly the
   same rows the page renders on load. Any fg_id ("{student_id}_
   {company_id}") returned that isn't already present in the DOM is
   a brand-new evaluation a company just submitted/locked, so it is
   appended straight into the single unified table body (creating it,
   and removing the "No Evaluations Submitted Yet" empty state, if
   this is the very first evaluation ever) — no manual page refresh
   required. All existing single/bulk publish logic above is
   untouched; this only adds rows, it never removes or alters ones
   already on screen. Pagination is recalculated afterwards so the
   new row surfaces correctly under the current search/filter/page.
══════════════════════════════════════════════════════════════════ */
(function() {
    const knownGradeIds = new Set();
    document.querySelectorAll('.grade-row').forEach(row => {
        if (row.dataset.fgId) knownGradeIds.add(row.dataset.fgId);
    });

    function escapeHtml(str) {
        if (str === null || str === undefined) return '';
        return String(str).replace(/[&<>"']/g, m => ({
            '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;'
        }[m]));
    }

    function ratingGradeClassJS(r) {
        if (r <= 2.0)  return 'grade-a';
        if (r <= 2.75) return 'grade-b';
        if (r <= 3.0)  return 'grade-c';
        return 'grade-d';
    }
    function ratingLabelJS(r) {
        if (r <= 1.25) return 'Excellent';
        if (r <= 2.0)  return 'Very Satisfactory';
        if (r <= 2.75) return 'Satisfactory';
        if (r <= 3.0)  return 'Passed';
        return 'Failed';
    }
    function formatDateShort(dateStr) {
        try {
            const d = new Date(String(dateStr).replace(' ', 'T'));
            if (isNaN(d.getTime())) return '';
            return d.toLocaleDateString('en-US', { month: 'short', day: 'numeric', year: 'numeric' });
        } catch (e) { return ''; }
    }

    function getOrCreateTableBody() {
        let tbody = document.getElementById('gradesTableBody');
        if (tbody) return tbody;

        // First-ever row: clear the empty state and build the table shell
        const emptyState = document.getElementById('pageEmptyState');
        if (emptyState) {
            const wrap = document.createElement('div');
            wrap.className = 'grades-table-wrap';
            wrap.id = 'gradesTableWrap';
            wrap.innerHTML = `
                <div class="table-scroll">
                <table class="grades-table" id="gradesTable">
                    <thead>
                        <tr>
                            <th class="th-check">
                                <input type="checkbox" class="select-all-cb" id="selectAllCb" onchange="toggleSelectAll(this)">
                            </th>
                            <th>Student</th>
                            <th>Company</th>
                            <th class="th-center">General Competency</th>
                            <th class="th-center">Specific Competency</th>
                            <th class="th-center">Overall Rating</th>
                            <th class="th-center">Status</th>
                            <th class="th-center">Action</th>
                        </tr>
                    </thead>
                    <tbody id="gradesTableBody">
                        <tr class="no-match-row" id="noMatchRow" style="display:none;"><td colspan="8">No matching records found.</td></tr>
                    </tbody>
                </table>
                </div>
            `;
            emptyState.replaceWith(wrap);

            const paginationBar = document.createElement('div');
            paginationBar.className = 'pagination-bar';
            paginationBar.id = 'paginationBar';
            paginationBar.innerHTML = `
                <div class="pagination-info" id="paginationInfo"></div>
                <div class="pagination-controls" id="paginationControls"></div>
            `;
            wrap.after(paginationBar);

            tbody = document.getElementById('gradesTableBody');
        }
        return tbody;
    }

    function buildGradeRowEl(g) {
        const gradeVal = g.overall_rating !== null && g.overall_rating !== undefined ? parseFloat(g.overall_rating) : null;
        const gclass   = gradeVal !== null ? ratingGradeClassJS(gradeVal) : 'grade-d';
        const rlabel   = gradeVal !== null ? ratingLabelJS(gradeVal) : '—';
        const isPub    = !!g.is_published;
        const cname    = g.company_name || 'Unknown Company';

        const tr = document.createElement('tr');
        tr.className = 'grade-row new-grade-highlight' + (isPub ? ' is-published' : '');
        tr.dataset.fgId = g.fg_id;
        tr.dataset.student = g.student_name || '';
        tr.dataset.company = cname;
        tr.dataset.published = isPub ? '1' : '0';
        tr.dataset.course = g.course || '';
        tr.dataset.generalRating = g.general_rating || '';
        tr.dataset.specificRating = g.specific_rating || '';
        tr.dataset.overallRating = g.overall_rating || '';
        tr.dataset.evalSubmittedAt = g.eval_submitted_at || '';
        tr.dataset.publishedAt = g.published_at || '';

        tr.innerHTML = `
            <td class="td-check">
                <input type="checkbox" class="row-checkbox" value="${escapeHtml(g.fg_id)}" onchange="onCheckChange()">
            </td>
            <td>
                <div class="student-name-cell">
                    <div class="sn-name">${escapeHtml(g.student_name)}</div>
                    <div class="sn-course">${escapeHtml(g.course || '—')}</div>
                </div>
            </td>
            <td>
                <span class="company-cell-name"><i class="fas fa-building" style="opacity:0.45;margin-right:6px;font-size:0.78rem;"></i>${escapeHtml(cname)}</span>
            </td>
            <td class="th-center" style="text-align:center;">
                ${g.general_rating !== null && g.general_rating !== undefined
                    ? `<span class="gv company-g">${escapeHtml(g.general_rating)}</span>`
                    : `<span style="color:var(--text-muted);font-style:italic;font-size:0.78rem;">—</span>`}
            </td>
            <td style="text-align:center;">
                ${g.specific_rating !== null && g.specific_rating !== undefined
                    ? `<span class="gv admin-g">${escapeHtml(g.specific_rating)}</span>`
                    : `<span style="color:var(--text-muted);font-style:italic;font-size:0.78rem;">—</span>`}
            </td>
            <td style="text-align:center;">
                ${gradeVal !== null
                    ? `<span class="final-grade-badge ${gclass}">${escapeHtml(g.overall_rating)}<span class="grade-letter">${escapeHtml(rlabel)}</span></span>`
                    : `<span style="color:var(--text-muted);font-style:italic;font-size:0.78rem;">—</span>`}
            </td>
            <td style="text-align:center;">
                <span class="pub-status ${isPub ? 'published' : 'unpublished'}" id="pubstatus-${escapeHtml(g.fg_id)}">
                    ${isPub ? '<i class="fas fa-eye"></i> Published' : '<i class="fas fa-eye-slash"></i> Hidden'}
                </span>
                <span class="pub-date" id="pubdate-${escapeHtml(g.fg_id)}">${isPub && g.published_at ? formatDateShort(g.published_at) : ''}</span>
            </td>
            <td class="action-cell">
                ${isPub
                    ? `<button class="btn-toggle-pub do-unpublish" id="togglebtn-${escapeHtml(g.fg_id)}" onclick="togglePublish('${escapeHtml(g.fg_id)}', 0)"><i class="fas fa-eye-slash"></i> Unupload</button>`
                    : `<button class="btn-toggle-pub do-publish" id="togglebtn-${escapeHtml(g.fg_id)}" onclick="togglePublish('${escapeHtml(g.fg_id)}', 1)"><i class="fas fa-upload"></i> Upload</button>`}
            </td>
        `;
        return tr;
    }

    function addNewGradeRow(g) {
        const tbody = getOrCreateTableBody();
        if (!tbody) return;
        const tr = buildGradeRowEl(g);
        const noMatchRow = document.getElementById('noMatchRow');
        if (noMatchRow) {
            tbody.insertBefore(tr, noMatchRow);
        } else {
            tbody.appendChild(tr);
        }
        setTimeout(() => tr.classList.remove('new-grade-highlight'), 3000);
    }

    function updateGlobalStatsFromPoll(allGrades) {
        const totalEl = document.getElementById('statTotal');
        if (totalEl) totalEl.textContent = allGrades.length;
    }

    function pollGradesUpdate() {
        fetch('admin_final_grades.php?poll_grades=1')
            .then(r => r.json())
            .then(data => {
                if (!data || !data.success || !Array.isArray(data.grades)) return;

                const freshRows = data.grades.filter(g => g.fg_id && !knownGradeIds.has(g.fg_id));
                if (freshRows.length === 0) {
                    // Still keep the header stats accurate even when nothing new
                    // arrived (e.g. after a manual toggle from another admin tab).
                    updateGlobalStatsFromPoll(data.grades);
                    return;
                }

                freshRows.forEach(g => {
                    addNewGradeRow(g);
                    knownGradeIds.add(g.fg_id);
                });

                updateStatCounts();
                updateGlobalStatsFromPoll(data.grades);
                renderTable(); // recompute filtering/pagination now that rows were added

                showToast(freshRows.length === 1
                    ? 'New evaluation detected — added automatically.'
                    : `${freshRows.length} new evaluations detected — added automatically.`);
            })
            .catch(() => {});
    }

    // Start polling a little after page load, then keep checking every 15s —
    // frequent enough to feel automatic without hammering the server.
    setTimeout(() => { pollGradesUpdate(); setInterval(pollGradesUpdate, 15000); }, 10000);
})();

/* ── INITIAL PAGE RENDER ── */
document.addEventListener('DOMContentLoaded', function() {
    if (document.getElementById('gradesTableBody')) {
        renderTable();
    }
});
// In case DOMContentLoaded already fired (script is at bottom of body, so it normally has),
// make sure the first page/pagination controls render immediately too.
if (document.getElementById('gradesTableBody')) {
    renderTable();
}
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