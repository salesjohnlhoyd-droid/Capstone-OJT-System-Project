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
$app_request_count_res = $conn->query("SELECT COUNT(*) as total FROM admin_application_approvals");
$app_request_count = (int)(($app_request_count_res ? $app_request_count_res->fetch_assoc()['total'] : 0));

// Determine page title for sidebar
$pageTitle = "Monitoring Dashboard";

// ================= SESSION CHECK (UNTOUCHED) =================
if (
    (!isset($_SESSION['admin_id']) && !isset($_SESSION['user_id'])) ||
    ($_SESSION['role'] ?? '') !== "admin"
) {
    header("Location: admin_login.php");
    exit;
}

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
        return $map[$s] ?? ($s !== '' ? ucwords($s) : 'Updated');
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
    [function () { return cv_alog_is_post('amd_apply_students'); }, function ($conn) {
        $co = cv_alog_company_name($conn, (int)($_POST['company_id'] ?? 0));
        $ids = json_decode((string)($_POST['student_ids'] ?? '[]'), true); $ids = is_array($ids) ? $ids : [];
        $names = []; foreach ($ids as $sid) { if ((int)$sid > 0) $names[] = cv_alog_user_name($conn, (int)$sid); }
        $c = count($ids);
        return ['Students Applied', 'Company', $co, "Applied " . cv_alog_plural($c, 'student', 'students') . (cv_alog_list($names) !== '' ? ' (' . cv_alog_list($names) . ')' : '') . " to $co via Monitoring Dashboard"];
    }],
    [function () { return cv_alog_is_post('mode') && in_array($_POST['mode'], ['send', 'edit', 'delete'], true) && !isset($_POST['amd_apply_students']) && !isset($_POST['amd_letter_preview']); }, function ($conn) {
        $co = cv_alog_company_name($conn, (int)($_POST['company_id'] ?? 0)); $m = (string)$_POST['mode'];
        $t = ['send' => 'Message Sent', 'edit' => 'Message Edited', 'delete' => 'Message Deleted'][$m];
        $d = ['send' => "Sent a message to $co", 'edit' => "Edited a message sent to $co", 'delete' => "Deleted a message sent to $co"][$m];
        return [$t, 'Company', $co, "$d via Monitoring Dashboard"];
    }],
]);

// ── FIX: admin_login.php's OTP-verify step and the "remember me"
// auto-login only ever set $_SESSION['admin_id'] (never
// $_SESSION['user_id']). This page was unconditionally reading
// $_SESSION['user_id'], which was undefined/null after a normal admin
// login, causing the admins lookup below to return 0 rows, which in
// turn ran session_destroy() and bounced the admin straight back to
// admin_login.php. Reading admin_id first (falling back to user_id for
// any older/alternate session-population path) fixes this without
// touching the guard clause above or anything else on the page. ──
$admin_id = $_SESSION['admin_id'] ?? $_SESSION['user_id'];

$stmt_admin = $conn->prepare("SELECT * FROM admins WHERE id = ? LIMIT 1");
$stmt_admin->bind_param("i", $admin_id);
$stmt_admin->execute();
$result_admin = $stmt_admin->get_result();
if ($result_admin->num_rows === 0) {
    session_destroy();
    header("Location: admin_login.php");
    exit;
}
$admin = $result_admin->fetch_assoc();
$admin_email = $admin['email'] ?? ''; 
$stmt_admin->close();

// ── NEW: full name shown at the top of the sidebar, built from
// first_name + middle_name + last_name of the logged-in admin (same
// pattern used in admin_student_list.php). $admin was already fetched
// above via "SELECT * FROM admins ...", so we reuse it directly instead
// of running a second lookup. Falls back to session values, then to a
// generic label, if nothing is available. ──
$adminFullName = trim(
    ($admin['first_name'] ?? '') . ' ' .
    ($admin['middle_name'] ?? '') . ' ' .
    ($admin['last_name'] ?? '')
);
$adminFullName = preg_replace('/\s+/', ' ', $adminFullName);
if ($adminFullName === '') {
    $adminFullName = trim(($_SESSION['first_name'] ?? '') . ' ' . ($_SESSION['last_name'] ?? ''));
}
if ($adminFullName === '') $adminFullName = 'Administrator';

// Ensure archive columns exist and fix NULLs so filters work correctly
$conn->query("ALTER TABLE users ADD COLUMN IF NOT EXISTS co_is_archived TINYINT(1) NOT NULL DEFAULT 0");
$conn->query("ALTER TABLE users ADD COLUMN IF NOT EXISTS is_archived TINYINT(1) NOT NULL DEFAULT 0");
$conn->query("UPDATE users SET co_is_archived = 0 WHERE co_is_archived IS NULL");
$conn->query("UPDATE users SET is_archived = 0 WHERE is_archived IS NULL");

// ── NEW: ensure the moa_requests table exists so the pending-count query
// below (used for the "Company Requirements" sidebar indicator) never
// fails on a fresh install — mirrors the same guard used in
// company_validation.php / admin_student_list.php. No other logic touched. ──
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
   admin_student_list.php / course_offering.php): the badge used to count moa_requests rows
   with status='Pending', but company_validation.php auto-ingests every
   Pending MOA request right away, so that count was almost always 0 /
   out of sync with what that page actually shows.
   company_validation.php's Notification Inbox lists (a) un-viewed,
   transferred MOA notifications (moa_requests.admin_viewed=0 AND
   transferred=1) PLUS (b) un-viewed requirement-upload notifications
   (company_requirement_upload_notifications.admin_viewed=0).
   This helper uses exactly that same rule, so the number shown here is
   always the same number shown on company_validation.php. Every lookup
   is guarded — a missing column/table simply counts as 0. Read-only:
   no DDL, no writes. ── */
if (!function_exists('dashboard_company_validation_notif_count')) {
    function dashboard_company_validation_notif_count($conn) {
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
$moa_pending_count = dashboard_company_validation_notif_count($conn); // FIX (sidebar notification indicator)

/* ── NEW (sidebar notification indicator): lightweight JSON endpoint the
   sidebar polls so the "Company Requirements" badge stays in step with
   company_validation.php without a page reload. Placed before any HTML
   output; read-only. ── */
if (isset($_GET['cv_sidebar_notif_count']) && $_GET['cv_sidebar_notif_count'] === '1') {
    header('Content-Type: application/json');
    echo json_encode(['count' => $moa_pending_count]);
    exit;
}

/* ============================================================================
   ADJUSTMENT: APPLY STUDENTS (batch) FROM THE MONITORING DASHBOARD
   ----------------------------------------------------------------------------
   The admin picks one or more students on a company panel and applies them to
   that company. Every student gets an application (ojt_applications,
   source = 'admin_batch', in_table = 0 → it lands in the company's
   application INBOX on add_ojt_student.php, not straight in its table) and
   ONE shared Endorsement Letter (ENDORSEMENT_form_builder.php) listing the
   whole batch. The students receive it in their Inbox (company_list.php);
   because they share one batch_id, one signed upload covers everyone.
   The letter is filled the same way administrator.php fills it (company
   contact, College → Department, Course Offering hours / schedule, the admin
   as OJT Adviser); the admin only chooses the start month, Dean and Director.
   ============================================================================ */
require_once __DIR__ . '/ENDORSEMENT_form_builder.php';

if (!function_exists('amdEnsureFlowSchema')) {
    function amdEnsureFlowSchema($conn) {
        // Same definitions used by administrator.php / add_ojt_student.php / company_list.php.
        try {
            $conn->query("CREATE TABLE IF NOT EXISTS endorsement_letters (
                id INT AUTO_INCREMENT PRIMARY KEY,
                student_id INT NOT NULL,
                company_id INT NOT NULL,
                letter_data LONGTEXT NULL,
                sent_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                student_viewed TINYINT(1) NOT NULL DEFAULT 0,
                uploaded_file LONGBLOB NULL,
                uploaded_mime VARCHAR(100) NULL,
                uploaded_name VARCHAR(255) NULL,
                uploaded_at DATETIME NULL,
                validation_status VARCHAR(20) NOT NULL DEFAULT 'Awaiting Upload',
                validation_remark TEXT NULL,
                validated_at DATETIME NULL,
                UNIQUE KEY unique_endorsement (student_id, company_id)
            )");
        } catch (\Throwable $e) {}
        try {
            $conn->query("CREATE TABLE IF NOT EXISTS endorsement_signatories (
                id INT AUTO_INCREMENT PRIMARY KEY,
                role VARCHAR(20) NOT NULL,
                first_name VARCHAR(100) NOT NULL DEFAULT '',
                middle_name VARCHAR(100) NOT NULL DEFAULT '',
                last_name VARCHAR(100) NOT NULL DEFAULT '',
                full_name VARCHAR(255) NOT NULL,
                title VARCHAR(150) NULL,
                last_used_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                UNIQUE KEY uniq_role_name (role, full_name)
            )");
        } catch (\Throwable $e) {}
        // batch_id: students applied together share one letter (and one upload).
        try {
            $c = $conn->query("SHOW COLUMNS FROM endorsement_letters LIKE 'batch_id'");
            if ($c && $c->num_rows === 0) $conn->query("ALTER TABLE endorsement_letters ADD COLUMN batch_id VARCHAR(40) NULL");
        } catch (\Throwable $e) {}
        // source / in_table: dashboard applications wait in the company's inbox until moved to its table.
        try {
            $cols = [];
            $r = $conn->query("SHOW COLUMNS FROM ojt_applications");
            while ($r && ($col = $r->fetch_assoc())) $cols[$col['Field']] = true;
            if ($cols && !isset($cols['source']))   $conn->query("ALTER TABLE ojt_applications ADD COLUMN source VARCHAR(20) NOT NULL DEFAULT 'student'");
            if ($cols && !isset($cols['in_table'])) $conn->query("ALTER TABLE ojt_applications ADD COLUMN in_table TINYINT(1) NOT NULL DEFAULT 1");
        } catch (\Throwable $e) {}
    }
}

function amdFullName($r) {
    return preg_replace('/\s+/', ' ', trim(($r['first_name'] ?? '') . ' ' . ($r['middle_name'] ?? '') . ' ' . ($r['last_name'] ?? '')));
}

/* Students who can still be applied: not registered anywhere, nothing pending. */
function amdEligibleWhere() {
    return "u.role = 'student' AND COALESCE(u.is_archived, 0) = 0
        AND NOT EXISTS (SELECT 1 FROM ojt_assignments oa WHERE oa.student_id = u.id)
        AND NOT EXISTS (SELECT 1 FROM admin_application_approvals ap WHERE ap.student_id = u.id)
        AND NOT EXISTS (SELECT 1 FROM ojt_applications ax WHERE ax.student_id = u.id AND ax.phase = 'pending')";
}

function amdSignatories($conn) {
    $out = ['dean' => [], 'director' => []];
    try {
        $r = $conn->query("SELECT id, role, full_name, first_name, middle_name, last_name, title FROM endorsement_signatories ORDER BY last_used_at DESC, id DESC");
        while ($r && ($row = $r->fetch_assoc())) {
            if (isset($out[$row['role']])) $out[$row['role']][] = [
                'id' => (int)$row['id'], 'full_name' => $row['full_name'], 'title' => $row['title'] ?? '',
                'first' => $row['first_name'], 'middle' => $row['middle_name'], 'last' => $row['last_name'],
            ];
        }
    } catch (\Throwable $e) {}
    return $out;
}

function amdSaveSignatory($conn, $role, $full, $parts, $title = null) {
    $full = preg_replace('/\s+/', ' ', trim((string)$full));
    if ($full === '') return;
    $f = trim((string)($parts['first'] ?? '')); $m = trim((string)($parts['middle'] ?? '')); $l = trim((string)($parts['last'] ?? ''));
    if ($f === '' && $l === '') {
        $t = preg_split('/\s+/', $full, -1, PREG_SPLIT_NO_EMPTY);
        $f = $t[0] ?? ''; $l = count($t) > 1 ? end($t) : ''; $m = count($t) > 2 ? implode(' ', array_slice($t, 1, -1)) : '';
    }
    $title = ($title === null || trim((string)$title) === '') ? null : trim((string)$title);
    try {
        $st = $conn->prepare("INSERT INTO endorsement_signatories (role, first_name, middle_name, last_name, full_name, title, last_used_at)
                              VALUES (?, ?, ?, ?, ?, ?, NOW())
                              ON DUPLICATE KEY UPDATE first_name = VALUES(first_name), middle_name = VALUES(middle_name),
                                  last_name = VALUES(last_name), title = COALESCE(VALUES(title), title), last_used_at = NOW()");
        $st->bind_param("ssssss", $role, $f, $m, $l, $full, $title);
        $st->execute();
        $st->close();
    } catch (\Throwable $e) {}
}

/* Builds the shared letter for a batch — same values administrator.php would fill in. */
function amdBuildBatchLetter($conn, $company_id, array $students, $month, $deanName, $deanTitle, $directorName, $adviserName) {
    $d = [
        'department_name' => '', 'letter_date' => date('F j, Y'), 'recipient_name' => '', 'recipient_position' => '',
        'company_name' => '', 'recipient_address' => '', 'salutation_name' => '', 'program' => '', 'required_hours' => '',
        'start_date' => '', 'end_date' => '', 'students' => '', 'adviser_name' => trim((string)$adviserName),
        'dean_name' => trim((string)$deanName), 'dean_title' => trim((string)$deanTitle) !== '' ? trim((string)$deanTitle) : 'Dean',
        'director_name' => trim((string)$directorName),
    ];
    // Addressee — the company contact (same rule as administrator.php)
    try {
        $q = $conn->prepare("SELECT * FROM company_information WHERE user_id = ?");
        $q->bind_param("i", $company_id); $q->execute();
        $c = $q->get_result()->fetch_assoc(); $q->close();
        if ($c) {
            $mi = trim((string)($c['contact_middle_initial'] ?? ''));
            if ($mi !== '' && strlen($mi) <= 2 && substr($mi, -1) !== '.') $mi .= '.';
            $name = preg_replace('/\s+/', ' ', trim(($c['contact_first_name'] ?? '') . ' ' . ($mi !== '' ? $mi . ' ' : '') . ($c['contact_last_name'] ?? '')));
            $d['recipient_name'] = $name; $d['salutation_name'] = $name;
            $d['recipient_position'] = $c['position'] ?? '';
            $d['company_name'] = $c['company'] ?? '';
            $d['recipient_address'] = $c['company_address'] ?? ($c['address'] ?? '');
        }
    } catch (\Throwable $e) {}
    // Students (one per line) and their shared course
    $d['students'] = implode("\n", array_map('amdFullName', $students));
    $course = trim((string)($students[0]['course'] ?? ''));
    $d['program'] = $course;
    // Department / College — the College the student entered on AccomForm.php (first one found)
    try {
        $chk = $conn->query("SHOW COLUMNS FROM student_information LIKE 'college'");
        if ($chk && $chk->num_rows > 0) {
            foreach ($students as $s) {
                $q = $conn->prepare("SELECT college FROM student_information WHERE user_id = ?");
                $sid = (int)$s['id']; $q->bind_param("i", $sid); $q->execute();
                $row = $q->get_result()->fetch_assoc(); $q->close();
                if ($row && trim((string)$row['college']) !== '') { $d['department_name'] = trim($row['college']); break; }
            }
        }
    } catch (\Throwable $e) {}
    // Training — Course Offering (total hours, hours/day → est. duty days, Mon–Fri)
    $est = amdCourseEstimate($conn, $course, $month);
    $d['start_date'] = $est['start_date'];
    if ($est['program'] !== '') $d['program'] = $est['program'];
    if ($est['required_hours'] !== '') $d['required_hours'] = $est['required_hours'];
    if ($est['end_date'] !== '') $d['end_date'] = $est['end_date'];
    return $d;
}

/* ── NEW: shared start-month / end-month / Est. Duty Days calculator ──
   Pulled out of amdBuildBatchLetter() so the exact same rule (course's
   total_hours / daily_hours, counted over Mon–Fri only, starting on the
   first weekday of the chosen month) can also power the live "End" and
   "Est. Day" preview fields in the Apply Students modal, via the
   amd_course_estimate endpoint below — without duplicating the logic or
   touching what amdBuildBatchLetter already produces for the letter. */
function amdCourseEstimate($conn, $course, $month) {
    $out = ['start_date' => '', 'end_date' => '', 'days' => 0, 'required_hours' => '', 'program' => ''];
    $year = (int)date('Y');
    $month = max(1, min(12, (int)$month ?: (int)date('n')));
    $start = new DateTime(sprintf('%04d-%02d-01', $year, $month));
    while ((int)$start->format('N') >= 6) $start->modify('+1 day');
    $out['start_date'] = $start->format('M Y');
    try {
        $q = $conn->prepare("SELECT course, total_hours, daily_hours FROM course_offerings WHERE LOWER(TRIM(course)) = LOWER(TRIM(?)) LIMIT 1");
        $q->bind_param("s", $course); $q->execute();
        $co = $q->get_result()->fetch_assoc(); $q->close();
        if ($co && (float)$co['daily_hours'] > 0) {
            $total = (float)$co['total_hours'];
            $out['program'] = $co['course'];
            $out['required_hours'] = ($total == floor($total)) ? (string)(int)$total : rtrim(rtrim(number_format($total, 2, '.', ''), '0'), '.');
            $days = (int)ceil(round($total / (float)$co['daily_hours'], 6));
            $out['days'] = $days;
            $end = clone $start; $n = 1;
            while ($n < max(1, $days)) { $end->modify('+1 day'); if ((int)$end->format('N') <= 5) $n++; }
            $out['end_date'] = $end->format('M Y');
        }
    } catch (\Throwable $e) {}
    return $out;
}

/* Loads + validates the chosen students (eligible, same course). Returns [students, error]. */
function amdLoadBatch($conn, $ids) {
    $ids = array_values(array_unique(array_filter(array_map('intval', (array)$ids))));
    if (!$ids) return [[], 'Please choose at least one student.'];
    $in = implode(',', $ids);
    $rows = [];
    $r = $conn->query("SELECT u.id, u.first_name, u.middle_name, u.last_name, u.course, (" . amdEligibleWhere() . ") AS eligible
                       FROM users u WHERE u.id IN ($in)");
    while ($r && ($row = $r->fetch_assoc())) $rows[(int)$row['id']] = $row;
    $students = []; $blocked = [];
    foreach ($ids as $id) {
        if (!isset($rows[$id])) continue;
        if (!(int)$rows[$id]['eligible']) $blocked[] = amdFullName($rows[$id]);
        $students[] = $rows[$id];
    }
    if (!$students) return [[], 'The selected students could not be found.'];
    if ($blocked) return [[], 'Already registered or with a pending application: ' . implode(', ', $blocked) . '.'];
    $courses = array_unique(array_map(fn($s) => strtolower(trim((string)$s['course'])), $students));
    if (count($courses) > 1) return [[], 'A batch shares one endorsement letter, so all students must be from the same course.'];
    return [$students, ''];
}

amdEnsureFlowSchema($conn);

/* ── GET: student search for the picker (photo, full name, course) ── */
if (isset($_GET['amd_search_students'])) {
    header('Content-Type: application/json');
    $q = trim((string)($_GET['q'] ?? ''));
    $out = [];
    if ($q !== '') {
        $like = '%' . $q . '%';
        $st = $conn->prepare("SELECT u.id, u.first_name, u.middle_name, u.last_name, u.email, u.course,
                                     (si.student_photo IS NOT NULL AND LENGTH(si.student_photo) > 0) AS has_photo
                              FROM users u LEFT JOIN student_information si ON si.user_id = u.id
                              WHERE " . amdEligibleWhere() . "
                                AND (CONCAT_WS(' ', u.first_name, u.middle_name, u.last_name) LIKE ?
                                     OR CONCAT(u.first_name, ' ', u.last_name) LIKE ? OR u.email LIKE ?)
                              ORDER BY u.first_name, u.last_name LIMIT 10");
        $st->bind_param("sss", $like, $like, $like);
        $st->execute();
        $res = $st->get_result();
        while ($row = $res->fetch_assoc()) {
            $out[] = ['id' => (int)$row['id'], 'name' => amdFullName($row), 'course' => $row['course'] ?? '', 'email' => $row['email'] ?? '', 'has_photo' => (bool)$row['has_photo']];
        }
        $st->close();
    }
    echo json_encode(['success' => true, 'students' => $out]);
    exit;
}

/* ── GET: a student's photo (for the picker) ── */
if (isset($_GET['amd_student_photo'])) {
    $sid = intval($_GET['amd_student_photo']);
    $st = $conn->prepare("SELECT student_photo FROM student_information WHERE user_id = ?");
    $st->bind_param("i", $sid); $st->execute();
    $row = $st->get_result()->fetch_assoc(); $st->close();
    if (!$row || empty($row['student_photo'])) { http_response_code(404); exit; }
    $mime = 'image/jpeg';
    if (function_exists('finfo_open')) { $fi = finfo_open(FILEINFO_MIME_TYPE); $m = $fi ? finfo_buffer($fi, $row['student_photo']) : ''; if ($fi) finfo_close($fi); if ($m && strpos($m, 'image/') === 0) $mime = $m; }
    header('Content-Type: ' . $mime);
    header('Cache-Control: private, max-age=600');
    echo $row['student_photo'];
    exit;
}

/* ── GET: saved Dean / Director names (same list administrator.php uses) ── */
if (isset($_GET['amd_signatories'])) {
    header('Content-Type: application/json');
    echo json_encode(['success' => true, 'signatories' => amdSignatories($conn), 'year' => (int)date('Y'), 'month' => (int)date('n')]);
    exit;
}

/* ── GET: live End Month + Est. Day preview for the Apply Students modal.
   ADJUSTMENT: the modal now shows an End Month (next to Start) and, below
   both, the course's Est. Day — this endpoint computes both, live, as the
   admin changes the Start month or adds/removes students, using the same
   amdCourseEstimate() rule the endorsement letter itself is built from. ── */
if (isset($_GET['amd_course_estimate'])) {
    header('Content-Type: application/json');
    $course = trim((string)($_GET['course'] ?? ''));
    $month = intval($_GET['month'] ?? 0);
    if ($course === '') { echo json_encode(['success' => true, 'start_date' => '', 'end_date' => '', 'days' => 0]); exit; }
    $est = amdCourseEstimate($conn, $course, $month);
    echo json_encode(['success' => true, 'start_date' => $est['start_date'], 'end_date' => $est['end_date'], 'days' => (int)$est['days']]);
    exit;
}

/* ── POST: letter preview (full HTML) ── */
if (isset($_POST['amd_letter_preview'])) {
    header('Content-Type: text/html; charset=UTF-8');
    [$students, $err] = amdLoadBatch($conn, json_decode($_POST['student_ids'] ?? '[]', true));
    if ($err !== '') { echo '<p style="font-family:sans-serif;padding:30px;color:#991b1b;">' . htmlspecialchars($err) . '</p>'; exit; }
    echo buildEndorsementFormHTML(amdBuildBatchLetter($conn, intval($_POST['company_id'] ?? 0), $students,
        intval($_POST['start_month'] ?? 0), $_POST['dean_name'] ?? '', $_POST['dean_title'] ?? '', $_POST['director_name'] ?? '', $adminFullName));
    exit;
}

/* ── POST: apply the batch + issue the shared endorsement letter ── */
if (isset($_POST['amd_apply_students'])) {
    header('Content-Type: application/json');
    $company_id = intval($_POST['company_id'] ?? 0);
    $cq = $conn->prepare("SELECT u.id, ci.company FROM users u LEFT JOIN company_information ci ON ci.user_id = u.id WHERE u.id = ? AND u.role = 'company'");
    $cq->bind_param("i", $company_id); $cq->execute();
    $co = $cq->get_result()->fetch_assoc(); $cq->close();
    if (!$co) { echo json_encode(['success' => false, 'message' => 'Company not found.']); exit; }

    [$students, $err] = amdLoadBatch($conn, json_decode($_POST['student_ids'] ?? '[]', true));
    if ($err !== '') { echo json_encode(['success' => false, 'message' => $err]); exit; }
    $deanName = trim((string)($_POST['dean_name'] ?? ''));
    if ($deanName === '') { echo json_encode(['success' => false, 'message' => 'Please choose or enter the Dean / Director.']); exit; }

    $letter = amdBuildBatchLetter($conn, $company_id, $students, intval($_POST['start_month'] ?? 0),
        $deanName, $_POST['dean_title'] ?? '', $_POST['director_name'] ?? '', $adminFullName);
    $letterJson = json_encode($letter, JSON_UNESCAPED_UNICODE);
    $batchId = 'b' . bin2hex(random_bytes(12));

    $done = []; $failed = [];
    foreach ($students as $s) {
        $sid = (int)$s['id'];
        // Resume data exactly as company_list.php sends it (student_profile.php's skills / experience).
        $skills = []; $exps = [];
        try {
            $q = $conn->prepare("SELECT entry_text FROM student_skills WHERE user_id = ? ORDER BY sort_order ASC, id ASC");
            $q->bind_param("i", $sid); $q->execute(); $rr = $q->get_result();
            while ($x = $rr->fetch_assoc()) $skills[] = $x['entry_text']; $q->close();
            $q = $conn->prepare("SELECT entry_text FROM student_experience WHERE user_id = ? ORDER BY sort_order ASC, id ASC");
            $q->bind_param("i", $sid); $q->execute(); $rr = $q->get_result();
            while ($x = $rr->fetch_assoc()) $exps[] = $x['entry_text']; $q->close();
        } catch (\Throwable $e) {}
        $s1 = $skills[0] ?? ''; $s2 = $skills[1] ?? ''; $s3 = count($skills) > 2 ? implode("\x1F", array_slice($skills, 2)) : '';
        $e1 = $exps[0] ?? '';   $e2 = count($exps) > 1 ? implode("\x1E", array_slice($exps, 1)) : '';

        $err = '';
        try {
            // Reuse a finished (non-pending) row for this pair, otherwise insert.
            $q = $conn->prepare("SELECT id FROM ojt_applications WHERE student_id = ? AND company_id = ? ORDER BY id DESC LIMIT 1");
            $q->bind_param("ii", $sid, $company_id); $q->execute();
            $old = $q->get_result()->fetch_assoc(); $q->close();
            try { foreach (['skill1','skill2','skill3'] as $col) $conn->query("ALTER TABLE ojt_applications MODIFY COLUMN $col TEXT"); } catch (\Throwable $e) {}
            if ($old) {
                $u = $conn->prepare("UPDATE ojt_applications SET phase = 'pending', skill1 = ?, skill2 = ?, skill3 = ?, exp1 = ?, exp2 = ?, source = 'admin_batch', in_table = 0 WHERE id = ?");
                $oid = (int)$old['id'];
                $u->bind_param("sssssi", $s1, $s2, $s3, $e1, $e2, $oid);
                if (!$u->execute()) $err = $u->error; $u->close();
                try { $conn->query("UPDATE ojt_applications SET created_at = NOW() WHERE id = " . $oid); } catch (\Throwable $e) {}
            } else {
                $i = $conn->prepare("INSERT INTO ojt_applications (student_id, company_id, phase, skill1, skill2, skill3, exp1, exp2, source, in_table)
                                     VALUES (?, ?, 'pending', ?, ?, ?, ?, ?, 'admin_batch', 0)");
                $i->bind_param("iisssss", $sid, $company_id, $s1, $s2, $s3, $e1, $e2);
                if (!$i->execute()) $err = $i->error; $i->close();
            }
            // The shared letter (one row per student, same batch_id / letter).
            if ($err === '') {
                $l = $conn->prepare("INSERT INTO endorsement_letters
                        (student_id, company_id, letter_data, sent_at, student_viewed, uploaded_file, uploaded_mime, uploaded_name, uploaded_at,
                         validation_status, validation_remark, validated_at, batch_id)
                    VALUES (?, ?, ?, NOW(), 0, NULL, NULL, NULL, NULL, 'Awaiting Upload', NULL, NULL, ?)
                    ON DUPLICATE KEY UPDATE letter_data = VALUES(letter_data), sent_at = NOW(), student_viewed = 0,
                        uploaded_file = NULL, uploaded_mime = NULL, uploaded_name = NULL, uploaded_at = NULL,
                        validation_status = 'Awaiting Upload', validation_remark = NULL, validated_at = NULL, batch_id = VALUES(batch_id)");
                $l->bind_param("iiss", $sid, $company_id, $letterJson, $batchId);
                if (!$l->execute()) $err = $l->error; $l->close();
            }
        } catch (\Throwable $e) { $err = $e->getMessage(); }
        if ($err === '') $done[] = amdFullName($s); else $failed[] = amdFullName($s) . ' (' . $err . ')';
    }

    // Remember the signatories for next time (same list as administrator.php).
    $dp = json_decode($_POST['dean_parts'] ?? 'null', true);
    amdSaveSignatory($conn, 'dean', $deanName, is_array($dp) ? $dp : [], $_POST['dean_title'] ?? null);
    $dir = trim((string)($_POST['director_name'] ?? ''));
    if ($dir !== '') { $rp = json_decode($_POST['director_parts'] ?? 'null', true); amdSaveSignatory($conn, 'director', $dir, is_array($rp) ? $rp : []); }

    echo json_encode([
        'success' => count($done) > 0 && !$failed,
        'applied' => $done,
        'failed'  => $failed,
        'company' => $co['company'] ?? '',
        'message' => $failed ? ('Could not apply: ' . implode('; ', $failed)) : '',
    ]);
    exit;
}

// ================= FETCH COMPANIES — exclude archived batches =================
$companies = [];
$stmt = $conn->prepare("
    SELECT u.id AS user_id, u.email AS user_email, 
           ci.company, ci.contact_first_name, ci.contact_middle_initial, ci.contact_last_name,
           cp.telephone, cp.facebook_link, cp.google_map_link
    FROM users u
    LEFT JOIN company_information ci ON ci.user_id = u.id
    LEFT JOIN company_profile cp ON cp.user_id = u.id
    WHERE u.role='company' AND u.co_is_archived = 0
");
$stmt->execute();
$result = $stmt->get_result();
while($row = $result->fetch_assoc()){
    $companies[] = $row;
}
$stmt->close();

function getGoogleMapSrc($link){
    if(empty($link)) return '';
    // If the user pasted a full iframe, extract the SRC
    if (preg_match('/<iframe.*?src=["\']([^"\']+)["\'].*?>/i', $link, $matches)) {
        return $matches[1];
    } 
    // If it's just a URL, return it (ensure it's the /embed or /maps version)
    return $link;
}

// ================= CHAT HANDLERS (UNTOUCHED) =================
if(isset($_POST['mode'])){
    $company_id = isset($_POST['company_id']) ? intval($_POST['company_id']) : 0;
    $sender = $admin_email;
    $receiver = $_POST['receiver'] ?? '';

    if($company_id <= 0){ exit('Invalid request'); }

    if($_POST['mode'] === 'send' && isset($_POST['message'])){
        $msg = trim($_POST['message']);
        if($msg !== ''){
            $stmt = $conn->prepare("INSERT INTO company_messages (company_id,sender_email,receiver_email,message) VALUES (?,?,?,?)");
            $stmt->bind_param("isss", $company_id, $sender, $receiver, $msg);
            $stmt->execute();
            $stmt->close();
        }
        exit('success');
    }

    if($_POST['mode'] === 'delete' && isset($_POST['message_id'])){
        $msg_id = intval($_POST['message_id']);
        if($msg_id > 0){
            $stmt = $conn->prepare("UPDATE company_messages SET message='Message removed' WHERE id=? AND sender_email=? AND company_id=?");
            $stmt->bind_param("isi", $msg_id, $sender, $company_id);
            $stmt->execute();
            $stmt->close();
        }
        exit('deleted');
    }

    if($_POST['mode'] === 'edit' && isset($_POST['message_id'], $_POST['new_message'])){
        $msg_id = intval($_POST['message_id']);
        $new_message = trim($_POST['new_message']);
        if($msg_id > 0 && $new_message !== ''){
            $stmt = $conn->prepare("UPDATE company_messages SET message=?, edited=1 WHERE id=? AND sender_email=? AND company_id=?");
            $stmt->bind_param("sisi", $new_message, $msg_id, $sender, $company_id);
            $stmt->execute();
            $stmt->close();
        }
        exit('edited');
    }
}

if(isset($_GET['load_messages'])){
    $company_id = isset($_GET['company_id']) ? intval($_GET['company_id']) : 0;
    if($company_id <= 0){ exit('Invalid company'); }

    $stmt = $conn->prepare("SELECT * FROM company_messages WHERE company_id=? ORDER BY created_at ASC");
    $stmt->bind_param("i", $company_id);
    $stmt->execute();
    $res = $stmt->get_result();
    while($row = $res->fetch_assoc()){
        $isSender = ($row['sender_email'] === $admin_email);
        $bgColor = $isSender ? "#EAF0FA" : "#FFFFFF";
        $align = $isSender ? "margin-left: auto;" : "margin-right: auto;";
        
        echo "<div style='max-width:80%; margin-bottom:10px; padding:10px 12px; border-radius:0; border:1px solid #C3CADA; background:$bgColor; font-size:13px; color:#1B2A4A; $align'>";
        echo "<b style='font-size:10.5px; color:#5A6272; text-transform:uppercase; letter-spacing:0.3px;'>".htmlspecialchars($row['sender_email'])."</b> ";
        if(!empty($row['edited']) && $row['edited']==1) echo "<span style='font-size:10px;color:gray'>(edited)</span>";
        echo "<div style='margin-top:4px;'>".nl2br(htmlspecialchars($row['message']))."</div>";
        echo "<div style='font-size:10px;color:#8A93A8; margin-top:6px; padding-top:5px; border-top:1px solid #DDE2EC; display:flex; justify-content:space-between; align-items:center; gap:10px;'>";
        echo "<span>".$row['created_at']."</span>";
        if($isSender && $row['message'] !== 'Message removed'){
            echo "<div><span onclick='editMessage(".$row['id'].")' style='cursor:pointer; color:#1B2A4A; margin-right:10px; font-weight:700; text-transform:uppercase; letter-spacing:0.3px;'>Edit</span>";
            echo "<span onclick='deleteMessage(".$row['id'].")' style='cursor:pointer; color:#A02A2A; font-weight:700; text-transform:uppercase; letter-spacing:0.3px;'>Delete</span></div>";
        }
        echo "</div></div>";
    }
    exit;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title><?= $pageTitle ?></title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        /* ══════════════════════════════════════════════════════════════
           DESIGN ADJUSTMENT: "Field ops grid" (Option 4 from
           company_list_style_previews.html) applied to this page.
           Sharp corners, #C3CADA grid borders, navy #1B2A4A primary,
           uppercase tracked labels, no colored left bars, status
           shown as bold colored text. Sidebar / navbar / badges keep
           the shared NEUST admin look so navigation stays identical
           across all admin pages. No selectors or class names were
           removed — only their visual values changed.
           ══════════════════════════════════════════════════════════════ */
        :root {
            --neust-maroon: #07145fe5;
            --neust-gold: #FFD700;
            --bg: #EEF1F6;
            --text: #1B2A4A;
            --white: #ffffff;
            --sidebar-active: #1a237e;

            /* Field ops grid tokens */
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
        }

        body { font-family: 'Segoe UI', sans-serif; background: var(--bg); margin: 0; display: flex; color: var(--text); min-height: 100vh; }

        /* --- UPDATED SIDEBAR (unchanged shared admin look) --- */
        .sidebar { 
            width: 260px; background: var(--neust-maroon); height: 100vh; position: fixed; 
            display: flex; flex-direction: column; transition: 0.3s; z-index: 1000;
        }
        .sidebar.collapsed { width: 80px; }
        .sidebar-header { padding: 20px; display: flex; align-items: center; justify-content: space-between; border-bottom: 1px solid rgba(255,255,255,0.1); }
        .sidebar-header-titles { overflow: hidden; transition: 0.3s; min-width: 0; }
        .sidebar-header h2 { color: var(--neust-gold); margin: 0; font-size: 18px; font-weight: bold; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; transition: 0.3s; }
        .sidebar-role-label { display: block; color: rgba(255,255,255,0.55); font-size: 11px; font-weight: 700; text-transform: uppercase; letter-spacing: 0.8px; margin-top: 3px; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; transition: 0.3s; }
        .sidebar.collapsed .sidebar-header-titles { opacity: 0; width: 0; }
        
        .sidebar-links { flex: 1; display: flex; flex-direction: column; padding: 10px 0; }
        .sidebar a { padding: 15px 25px; color: #cbd5e0; text-decoration: none; font-size: 14px; display: flex; align-items: center; transition: 0.2s; white-space: nowrap; position: relative; }
        .sidebar a i { width: 30px; font-size: 18px; margin-right: 15px; text-align: center; }
        .sidebar.collapsed a i { margin-right: 0; }
        .sidebar.collapsed .link-text { display: none; }
        
        .sidebar a:hover { color: white; background: rgba(255,255,255,0.05); }
        .sidebar a.active { background: var(--sidebar-active); color: white; border-left: 4px solid var(--neust-gold); }
        
        .sidebar .logout-link { margin-top: auto; border-top: 1px solid rgba(255,255,255,0.1); padding: 20px; }
        .sidebar .logout-link a { border: 1px solid var(--neust-gold); color: var(--neust-gold); border-radius: 6px; justify-content: center; padding: 10px; }
        .toggle-btn { background: transparent; border: none; color: white; cursor: pointer; font-size: 20px; outline: none; }

        /* ── NEW: pending-MOA indicator on the "Company Requirements" sidebar
           link — mirrors the identical badge/animation already used in
           company_validation.php / admin_student_list.php so the visual
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

        /* --- MAIN CONTENT --- */
        .main-content { margin-left: 260px; width: calc(100% - 260px); transition: 0.3s; min-height: 100vh; }
        .sidebar.collapsed + .main-content { margin-left: 80px; width: calc(100% - 80px); }

        .navbar { background: var(--neust-maroon); padding: 10px 30px; display: flex; justify-content: space-between; align-items: center; color: white; height: 60px; }
        .logo-section { display: flex; align-items: center; gap: 12px; }
        .university-logo { height: 40px; }

        .container { padding: 30px; max-width: 1200px; margin: 0 auto; }

        /* --- ACCORDION (Field ops grid rows) ---
           Each company is a square, grid-bordered row. (Colored left
           bars removed — the expanded row is marked by its tinted,
           underlined header instead.) */
        .company-row { background: #fff; border-radius: 0; margin-bottom: 10px; box-shadow: none; border: 1px solid var(--fo-line); overflow: hidden; transition: border-color 0.15s, box-shadow 0.15s; }
        .company-row:hover { box-shadow: 0 4px 14px rgba(27,42,74,0.14); }
        .company-summary { padding: 14px 20px; display: flex; justify-content: space-between; align-items: center; cursor: pointer; gap: 12px; flex-wrap: wrap; transition: background 0.15s; }
        .company-summary:hover { background: var(--fo-hover); }
        .company-summary > span { font-weight: 600; color: var(--fo-navy); font-size: 14px; text-transform: uppercase; letter-spacing: 0.4px; }
        .toggle-input:checked + .company-summary { background: var(--fo-head); border-bottom: 1px solid var(--fo-line); }

        .details-pane { display: none; padding: 20px; border-top: none; background: var(--fo-head); }
        .toggle-input:checked ~ .details-pane { display: block; }

        .info-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 0; margin-bottom: 16px; border: 1px solid var(--fo-line); }
        .info-grid > .info-card { border: none; }
        .info-grid > .info-card:first-child { border-right: 1px solid var(--fo-line); }
        .info-card { background: #fff; padding: 14px 16px; border-radius: 0; border: 1px solid var(--fo-line); font-size: 13px; color: var(--fo-navy); }
        .info-card p { margin: 8px 0; color: var(--fo-muted); }
        .info-card p strong { color: var(--fo-navy); font-weight: 600; font-size: 11px; text-transform: uppercase; letter-spacing: 0.4px; margin-right: 4px; }
        .info-card h4 { margin: 0 0 10px 0; color: var(--fo-navy); border-bottom: 1px solid var(--fo-line); padding-bottom: 8px; font-size: 12px; font-weight: 700; text-transform: uppercase; letter-spacing: 0.6px; }
        .info-card iframe { border-radius: 0 !important; border: 1px solid var(--fo-line) !important; box-sizing: border-box; }

        /* Action buttons — Option 4: navy primary, white bordered secondary */
        .fo-btn-primary { background: var(--fo-navy); color: #fff; border: 1px solid var(--fo-navy); padding: 8px 14px; border-radius: 0; cursor: pointer; font-size: 12px; font-weight: 600; text-decoration: none; display: inline-flex; align-items: center; gap: 6px; text-transform: uppercase; letter-spacing: 0.4px; font-family: inherit; transition: background 0.15s, border-color 0.15s; }
        .fo-btn-primary:hover { background: #2A3D63; border-color: #2A3D63; }
        .message-btn { background: #fff; color: var(--fo-navy); border: 1px solid var(--fo-line); padding: 8px 14px; border-radius: 0; cursor: pointer; font-size: 12px; font-weight: 600; text-transform: uppercase; letter-spacing: 0.4px; font-family: inherit; transition: background 0.15s, border-color 0.15s, color 0.15s; }
        .message-btn:hover { background: var(--fo-navy); border-color: var(--fo-navy); color: #fff; }
        .fo-btn-primary:focus-visible, .message-btn:focus-visible, .amd-apply-btn:focus-visible { outline: 2px solid var(--fo-gold); outline-offset: 2px; }
        
        /* --- STUDENT TABLE --- */
        .student-table { width: 100%; border-collapse: collapse; background: #fff; border-radius: 0; overflow: hidden; font-size: 13px; border: 1px solid var(--fo-line); }
        .student-table th { background: var(--fo-head); padding: 10px 12px; text-align: left; color: var(--fo-navy); border-bottom: 1px solid var(--fo-line); font-size: 11px; font-weight: 700; text-transform: uppercase; letter-spacing: 0.5px; }
        .student-table td { padding: 10px 12px; border-bottom: 1px solid var(--fo-line-soft); color: var(--fo-muted); }
        .student-table td:nth-child(2) { color: var(--fo-navy); font-weight: 600; }
        .student-table tbody tr:last-child td { border-bottom: none; }
        .student-table tbody tr:hover td { background: var(--fo-hover); }
        .student-photo { width: 35px; height: 35px; border-radius: 0; object-fit: cover; border: 1px solid var(--fo-line); display: block; }
        .fo-photo-ph { width: 35px; height: 35px; background: var(--fo-head); border: 1px solid var(--fo-line); display: flex; align-items: center; justify-content: center; color: #8A93A8; font-size: 13px; box-sizing: border-box; }
        .fo-muted-note { color: #8A93A8; font-size: 12px; font-style: normal; text-transform: uppercase; letter-spacing: 0.4px; font-weight: 600; margin: 6px 0; }
        .fo-link { color: var(--fo-navy); text-decoration: none; font-size: 11px; font-weight: 700; text-transform: uppercase; letter-spacing: 0.4px; border-bottom: 1px solid var(--fo-line); padding-bottom: 1px; }
        .fo-link:hover { border-bottom-color: var(--fo-navy); }

        /* --- EMPTY STATE --- */
        .empty-state { text-align: center; padding: 70px 20px; color: var(--fo-muted); background: #fff; border: 1px solid var(--fo-line); }
        .empty-state i { font-size: 44px; margin-bottom: 16px; display: block; color: var(--fo-line); }
        .empty-state h3 { margin: 0 0 8px; font-size: 14px; color: var(--fo-navy); text-transform: uppercase; letter-spacing: 0.6px; }
        .empty-state p { margin: 0 auto; font-size: 13px; max-width: 460px; line-height: 1.5; }

        /* --- MODAL (chat) --- */
        .message-modal { display:none; position:fixed; top:0; left:0; width:100%; height:100%; background:rgba(27,42,74,0.55); justify-content:center; align-items:center; z-index:2000; }
        .message-box { background:#fff; width:450px; max-width:94vw; height:550px; max-height:90vh; border-radius:0; display:flex; flex-direction:column; overflow:hidden; box-shadow: 0 12px 30px rgba(27,42,74,0.30); border: 1px solid var(--fo-line); }
        .message-header { background:var(--fo-navy); color:white; padding:14px 16px; font-weight:700; display:flex; justify-content:space-between; align-items:center; font-size:12px; text-transform:uppercase; letter-spacing:0.6px; }
        .chat-messages { flex:1; overflow-y:auto; padding:18px; background:var(--fo-head); }
        .chat-input { display:flex; padding:12px; background:white; border-top:1px solid var(--fo-line); gap: 0; }
        .chat-input input { flex:1; padding:10px 12px; border:1px solid var(--fo-line); border-right:none; border-radius:0; outline:none; font-size:13px; color:var(--fo-navy); font-family:inherit; }
        .chat-input input:focus { border-color: var(--fo-navy); }
        .chat-input button { background:var(--fo-navy); color:white; border:1px solid var(--fo-navy); padding:0 18px; border-radius:0; cursor:pointer; font-weight:700; font-size:12px; text-transform:uppercase; letter-spacing:0.4px; font-family:inherit; }
        .chat-input button:hover { background:#2A3D63; }
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

        /* ══════════ ADJUSTMENT: APPLY STUDENTS (batch) ══════════ */
        .amd-apply-btn { width: 34px; height: 34px; border-radius: 0; border: 1px solid var(--fo-green-bar); background: #fff; color: var(--fo-green); cursor: pointer; font-size: 13px; display: inline-flex; align-items: center; justify-content: center; transition: background 0.15s, color 0.15s; }
        .amd-apply-btn:hover { background: var(--fo-green); border-color: var(--fo-green); color: #fff; }
        .amd-tip { position: relative; display: inline-flex; }
        /* FIX: the company panel has overflow:hidden (cuts a tooltip above the button) and a tooltip
           beside the button covered the navy "Open Chat" button and blended in. The tooltip is now
           ONE floating element on the page (#amdFloatTip), placed ABOVE the button with an arrow —
           never clipped, never covering another button. */
        #amdFloatTip { position: fixed; z-index: 3000; background: #1B2A4A; color: #fff; font-size: 10.5px; font-weight: 600; text-transform: uppercase; letter-spacing: 0.3px; padding: 6px 10px; border-radius: 0; white-space: nowrap; box-shadow: 0 4px 14px rgba(27,42,74,0.30); pointer-events: none; opacity: 0; transform: translateY(4px); transition: opacity .15s, transform .15s; }
        #amdFloatTip.show { opacity: 1; transform: translateY(0); }
        #amdFloatTip::after { content: ''; position: absolute; top: 100%; left: var(--arrow-x, 50%); transform: translateX(-50%); border: 6px solid transparent; border-top-color: #1B2A4A; }

        #applyStuModal { display: none; position: fixed; inset: 0; background: rgba(27,42,74,0.55); z-index: 2100; justify-content: center; align-items: center; padding: 20px 0; overflow-y: auto; box-sizing: border-box; }
        #applyStuModal.open { display: flex; }
        /* ── ADJUSTMENT: restyled to match admin_company_list.php's manual
           "Add Company" modal — a plain white bordered box, centered in the
           viewport (not pinned to the top), a bottom-bordered header instead
           of a solid navy bar, and a sticky footer — using this page's own
           --fo-* color tokens so the look matches the rest of this dashboard. ── */
        .apply-box { background: #fff; width: 860px; max-width: 94%; border-radius: 0; border: 1px solid var(--fo-line); box-shadow: 0 12px 30px rgba(27,42,74,0.30); max-height: calc(100vh - 40px); max-height: calc(100dvh - 40px); overflow-y: auto; box-sizing: border-box; font-size: 14px; color: var(--fo-navy); padding: 16px 24px 0 24px; animation: applyModalPop 0.25s ease; }
        .apply-head { display: flex; justify-content: space-between; align-items: center; margin-bottom: 12px; padding-bottom: 8px; border-bottom: 1px solid var(--fo-line); font-weight: 700; font-size: 15px; color: var(--fo-navy); gap: 10px; }
        .apply-head #applyCoName { font-size: 15px; font-weight: 700; }
        .apply-head button { background: none; border: none; color: var(--fo-muted); font-size: 22px; cursor: pointer; line-height: 1; }
        .apply-body { padding: 0 0 8px; }
        .apply-chips { display: flex; flex-wrap: wrap; gap: 6px; margin-bottom: 8px; }
        .apply-chip { display: inline-flex; align-items: center; gap: 7px; background: #fff; border: 1px solid var(--fo-line); border-radius: 0; padding: 3px 6px 3px 3px; font-size: 12.5px; font-weight: 600; color: var(--fo-navy); }
        .apply-chip img, .apply-chip .ph { width: 24px; height: 24px; border-radius: 0; object-fit: cover; }
        .apply-chip button { border: none; background: none; color: var(--fo-muted); cursor: pointer; font-size: 15px; line-height: 1; padding: 0 3px; }
        .apply-chip button:hover { color: var(--fo-red); }
        .apply-search-wrap { position: relative; }
        .apply-search-wrap input { width: 100%; box-sizing: border-box; padding: 10px 12px; border: 1px solid var(--fo-line); border-radius: 0; font-size: 13.5px; outline: none; color: var(--fo-navy); font-family: inherit; }
        .apply-search-wrap input::placeholder { color: #8A93A8; }
        .apply-search-wrap input:focus { border-color: var(--fo-navy); box-shadow: none; }
        .apply-results { display: none; position: absolute; left: 0; right: 0; top: calc(100% - 1px); background: #fff; border: 1px solid var(--fo-navy); border-radius: 0; box-shadow: 0 10px 24px rgba(27,42,74,0.16); max-height: 320px; overflow-y: auto; z-index: 5; }
        .apply-results.open { display: block; }
        .apply-result { display: flex; align-items: center; gap: 18px; padding: 10px 18px; cursor: pointer; border-bottom: 1px solid var(--fo-line-soft); }
        .apply-result:nth-child(odd) { background: #FAFBFD; }
        /* FIX (double indicator): only ONE row is highlighted at a time.
           Hover no longer paints its own highlight — moving the mouse over a
           row makes it the .active row (see the mousemove handler on
           #applyResults), so the mouse and the Up/Down arrow keys share the
           same single indicator and never show two rows at once. */
        .apply-result.active { background: var(--fo-hover); }
        .apply-result img, .apply-result .ph { width: 46px; height: 46px; border-radius: 0; object-fit: cover; border: 1px solid var(--fo-line); flex-shrink: 0; }
        .apply-result .nm { font-weight: 700; color: var(--fo-navy); font-size: 14px; min-width: 150px; }
        .apply-result .cs { color: var(--fo-muted); font-size: 11px; font-weight: 600; text-transform: uppercase; letter-spacing: 0.4px; }
        .apply-empty { padding: 14px 18px; color: #8A93A8; font-size: 12.5px; }
        .ph { background: var(--fo-head); color: var(--fo-navy); display: inline-flex; align-items: center; justify-content: center; font-weight: 700; font-size: 12px; }
        .apply-result .ph { font-size: 15px; }
        /* ADJUSTMENT: help/hint copy now reads like the manual Add Company
           form's .help-text (smaller, muted, tighter line-height) instead of
           the previous larger "hint" style. */
        .apply-hint, .apply-note, .help-text { font-size: 11px; color: var(--fo-muted); margin-top: 5px; line-height: 1.4; }
        .apply-section { margin: 16px 0 10px; font-size: 11px; font-weight: 700; color: var(--fo-navy); text-transform: uppercase; letter-spacing: .6px; padding: 7px 10px; border: 1px solid var(--fo-line); background: var(--fo-head); }
        /* ADJUSTMENT: 3-column form grid with span-2 / span-3 helpers —
           mirrors admin_company_list.php's .add-company-grid pattern. */
        .apply-grid { display: grid; grid-template-columns: repeat(3, 1fr); column-gap: 14px; row-gap: 0; align-items: start; }
        .apply-grid .span-2 { grid-column: span 2; }
        .apply-grid .span-3 { grid-column: 1 / -1; }
        .apply-field { margin-bottom: 12px; }
        .apply-field label { display: block; font-weight: 600; color: var(--fo-navy); margin-bottom: 6px; font-size: 12.5px; }
        .apply-field label .required { color: var(--fo-red); }
        .apply-field select, .apply-field input { width: 100%; box-sizing: border-box; padding: 8px 10px; border: 1px solid var(--fo-line); border-radius: 0; font-size: 13px; background: #fff; color: var(--fo-navy); font-family: inherit; }
        .apply-field select:focus, .apply-field input:focus { outline: none; border-color: var(--fo-navy); }
        .apply-field input[readonly] { background: var(--fo-head); color: var(--fo-muted); }
        .apply-month { display: grid; grid-template-columns: 1fr 80px; gap: 6px; }
        .apply-parts { display: grid; grid-template-columns: 1fr 1fr 1fr; gap: 6px; margin-top: 6px; }
        .apply-error { display: none; margin-top: 12px; background: #fff; border: 1px solid #E4C3C0; color: var(--fo-red); border-radius: 0; padding: 9px 12px; font-size: 12.5px; font-weight: 600; }
        /* ADJUSTMENT: footer now stays pinned to the bottom of the box while
           the form scrolls above it — same "sticky modal-actions" behavior
           as the manual Add Company modal. */
        .apply-foot { display: flex; justify-content: flex-end; gap: 12px; padding: 10px 0 14px; border-top: 1px solid var(--fo-line-soft); margin-top: 4px; position: sticky; bottom: 0; background: #fff; z-index: 2; flex-wrap: wrap; }
        .apply-foot button { border: 1px solid var(--fo-line); border-radius: 0; padding: 10px 22px; font-size: 12px; font-weight: 700; cursor: pointer; text-transform: uppercase; letter-spacing: 0.3px; font-family: inherit; transition: background 0.15s, color 0.15s, border-color 0.15s; }
        .apply-foot .ghost { background: #fff; color: var(--fo-navy); }
        .apply-foot .ghost:hover { background: var(--fo-hover); }
        .apply-foot .primary { background: var(--fo-navy); color: #fff; border-color: var(--fo-navy); }
        .apply-foot .primary:hover:not(:disabled) { background: #2A3D63; border-color: #2A3D63; }
        .apply-foot button:disabled { opacity: .55; cursor: not-allowed; }
        @keyframes applyModalPop { from { transform: scale(0.96); opacity: 0; } to { transform: scale(1); opacity: 1; } }
        @media (max-width: 900px) {
            .apply-grid { grid-template-columns: 1fr 1fr; }
        }
        @media (max-width: 640px) {
            .apply-box { padding: 14px 14px 0 14px; }
            .apply-grid { grid-template-columns: 1fr; }
            .apply-grid .span-2, .apply-grid .span-3 { grid-column: auto; }
        }

        #applyPreviewOverlay { display: none; position: fixed; inset: 0; z-index: 2200; background: rgba(27,42,74,0.78); flex-direction: column; }
        #applyPreviewOverlay.open { display: flex; }
        .apply-pv-bar { background: #1B2A4A; color: #fff; padding: 10px 18px; display: flex; justify-content: space-between; align-items: center; font-weight: 700; font-size: 12px; text-transform: uppercase; letter-spacing: 0.5px; }
        .apply-pv-bar button { background: transparent; color: #fff; border: 1px solid rgba(255,255,255,0.4); border-radius: 0; padding: 6px 14px; cursor: pointer; font-size: 11.5px; font-weight: 700; text-transform: uppercase; letter-spacing: 0.4px; font-family: inherit; }
        .apply-pv-bar button:hover { background: rgba(255,255,255,0.12); }
        #applyPreviewFrame { flex: 1; border: none; background: #d8dde8; }

        /* loading page + popup — administrator.php look (already Field ops grid) */
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
        .cv-top-toast { position: fixed; top: 30px; left: 50%; transform: translateX(-50%); background: #1B2A4A; color: #E3E8F1; border: 1px solid #55668C; border-radius: 0; padding: 14px 20px; box-shadow: 0 8px 24px rgba(27,42,74,0.30); display: flex; align-items: center; gap: 12px; font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif; font-size: 12.5px; line-height: 1.45; z-index: 10020; max-width: 440px; opacity: 0; transition: opacity 0.35s, top 0.3s ease; pointer-events: none; }
        .cv-top-toast.show { opacity: 1; }
        .cv-top-toast i { color: #8FD18F; font-size: 18px; flex-shrink: 0; }
        .cv-top-toast.is-error i { color: #f87171; }
        .cv-top-toast strong { color: #ffffff; font-weight: 700; }

        @media (max-width: 760px) {
            .info-grid { grid-template-columns: 1fr; }
            .info-grid > .info-card:first-child { border-right: none; border-bottom: 1px solid var(--fo-line); }
            .container { padding: 18px; }
        }
        @media (prefers-reduced-motion: reduce) {
            .company-row, .company-summary, .message-btn, .fo-btn-primary, .amd-apply-btn { transition: none; }
        }
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
    html.cv-booting::before { content: ''; position: fixed; left: 50%; top: 50%; width: 54px; height: 54px; margin: -44px 0 0 -32px; border-radius: 50%;
        border: 5px solid #A3AFC7; border-top-color: #1B2A4A; z-index: 20002; animation: cvBootSpin 0.85s linear infinite; }
    html.cv-booting::after { content: 'LOADING'; position: fixed; inset: 0; z-index: 20001; display: flex; align-items: center; justify-content: center;
        padding-top: 70px; box-sizing: border-box; background: rgba(238, 241, 246, 0.92); color: #1B2A4A;
        font: 700 13px 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif; letter-spacing: 0.6px; }
    @keyframes cvBootSpin { to { transform: rotate(360deg); } }
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
    (function waitForOverlay() {
        if (document.getElementById('globalLoadingOverlay')) { releaseBoot(); return; }
        if (document.readyState !== 'loading') { releaseBoot(); return; }   // page without an overlay: never keep it covered
        setTimeout(waitForOverlay, 16);
    })();
    document.addEventListener('DOMContentLoaded', function () { setTimeout(releaseBoot, 0); });
    window.addEventListener('pageshow', function (e) { if (e.persisted) releaseBoot(); });

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
</head>
<body>

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
            <h2 id="sidebarTitle"><?= htmlspecialchars($adminFullName) ?></h2>
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
             / admin_student_list.php. ── -->
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
        <a href="admin_monitoring_dashboard.php" class="active">
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

<div class="main-content">
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
        <?php if(empty($companies)): ?>
        <div class="empty-state">
            <i class="fas fa-building"></i>
            <h3>No Active Companies</h3>
            <p>There are no active company records to display. Companies will appear here once registered or unarchived.</p>
        </div>
        <?php else: ?>

        <?php foreach($companies as $company): ?>
        <div class="company-row">
            <input type="checkbox" id="company_<?= $company['user_id'] ?>" class="toggle-input" style="display:none;">
            <label for="company_<?= $company['user_id'] ?>" class="company-summary">
                <span><?= htmlspecialchars($company['company']) ?></span>
                <div style="display:flex;gap:8px;align-items:center;">
                    <a href="admin_reports.php?company_id=<?= $company['user_id'] ?>"
                    class="fo-btn-primary"
                    onclick="event.stopPropagation();">
                        Overview
                    </a>
                    <button type="button" class="message-btn"
                            onclick="openMessageBox('<?= htmlspecialchars($admin_email) ?>','<?= htmlspecialchars($company['user_email']) ?>','<?= $company['user_id'] ?>')">
                        Open Chat
                    </button>
                    <!-- ADJUSTMENT: apply students (batch) to this company -->
                    <span class="amd-tip" data-tip="Apply students">
                        <button type="button" class="amd-apply-btn" aria-label="Apply students to this company"
                                onclick="event.preventDefault(); event.stopPropagation(); openApplyStudents(<?= (int)$company['user_id'] ?>, <?= htmlspecialchars(json_encode($company['company'] ?? ''), ENT_QUOTES) ?>);">
                            <i class="fas fa-user-plus"></i>
                        </button>
                    </span>
                </div>
            </label>

            <div class="details-pane">
                <div class="info-grid">
                    <div class="info-card">
                        <h4>Contact Information</h4>
                        <p><strong>Manager:</strong> <?= htmlspecialchars(trim($company['contact_first_name'].' '.$company['contact_last_name'])) ?></p>
                        <p><strong>Email:</strong> <?= htmlspecialchars($company['user_email']) ?></p>
                        <p><strong>Tel:</strong> <?= htmlspecialchars($company['telephone'] ?? 'N/A') ?></p>
                        <?php if(!empty($company['facebook_link'])): ?>
                            <a href="<?= htmlspecialchars($company['facebook_link']) ?>" target="_blank" class="fo-link">View Facebook Page</a>
                        <?php endif; ?>
                    </div>
                    <div class="info-card">
                        <h4>Location</h4>
                        <?php $mapSrc = getGoogleMapSrc($company['google_map_link']); ?>
                        <?php if(!empty($mapSrc)): ?>
                            <iframe 
                                src="<?= htmlspecialchars($mapSrc) ?>" 
                                width="100%" 
                                height="200" 
                                style="border:0; border-radius:4px;" 
                                allowfullscreen="" 
                                loading="lazy" 
                                referrerpolicy="no-referrer-when-downgrade">
                            </iframe>
                        <?php else: ?>
                            <p class="fo-muted-note">No map location provided.</p>
                        <?php endif; ?>
                    </div>
                </div>

                <div class="info-card" style="width:100%; box-sizing:border-box;">
                    <h4>Assigned OJT Students</h4>
                    <?php
                    // Only show active (non-archived) students
                    $stmt_students = $conn->prepare("
                        SELECT u.first_name, u.last_name, u.email, si.student_photo
                        FROM ojt_assignments oa
                        JOIN users u ON u.id = oa.student_id
                        LEFT JOIN student_information si ON si.user_id = u.id
                        WHERE oa.company_id = ? AND u.is_archived = 0
                    ");
                    $stmt_students->bind_param("i", $company['user_id']);
                    $stmt_students->execute();
                    $students_result = $stmt_students->get_result();
                    ?>
                    
                    <?php if($students_result->num_rows > 0): ?>
                        <table class="student-table">
                            <thead>
                                <tr>
                                    <th>Photo</th>
                                    <th>Student Name</th>
                                    <th>Email Address</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php while($student = $students_result->fetch_assoc()): ?>
                                <tr>
                                    <td>
                                        <?php if(!empty($student['student_photo'])): ?>
                                            <img src="data:image/jpeg;base64,<?= base64_encode($student['student_photo']) ?>" class="student-photo">
                                        <?php else: ?>
                                            <div class="fo-photo-ph"><i class="fas fa-user"></i></div>
                                        <?php endif; ?>
                                    </td>
                                    <td><?= htmlspecialchars($student['first_name'].' '.$student['last_name']) ?></td>
                                    <td><?= htmlspecialchars($student['email']) ?></td>
                                </tr>
                                <?php endwhile; ?>
                            </tbody>
                        </table>
                    <?php else: ?>
                        <p class="fo-muted-note">No students currently assigned to this company.</p>
                    <?php endif; ?>
                </div>
            </div>
        </div>
        <?php endforeach; ?>
        <?php endif; ?>
    </div>
</div>

<!-- ══════════ ADJUSTMENT: APPLY STUDENTS (batch) ══════════ -->
<div id="applyStuModal" role="dialog" aria-modal="true" onclick="if (event.target === this) closeApplyStudents()">
    <div class="apply-box">
        <div class="apply-head">
            <span><i class="fas fa-user-plus"></i> Apply Students &mdash; <span id="applyCoName"></span></span>
            <button type="button" onclick="closeApplyStudents()" aria-label="Close">&times;</button>
        </div>
        <div class="apply-body">
            <div class="apply-grid">
                <div class="apply-field span-3">
                    <label>Students <span class="required">*</span></label>
                    <div class="apply-chips" id="applyChips"></div>
                    <div class="apply-search-wrap">
                        <input type="text" id="applySearch" placeholder="Search students by name or email..." autocomplete="off">
                        <div class="apply-results" id="applyResults"></div>
                    </div>
                    <div class="apply-hint">Students applied together share <b>one endorsement letter</b> and one upload &mdash; choose students from the same course.</div>
                </div>
            </div>

            <div class="apply-section">Endorsement Letter</div>
            <div class="apply-grid">
                <div class="apply-field">
                    <label>Start</label>
                    <div class="apply-month">
                        <select id="applyMonth"></select>
                        <input type="text" id="applyYear" readonly>
                    </div>
                </div>
                <div class="apply-field">
                    <label>End</label>
                    <input type="text" id="applyEndMonth" readonly placeholder="&mdash;">
                    <div class="apply-note">Follows the course's Est. Duty Days.</div>
                </div>
                <div class="apply-field">
                    <label>Est. Day</label>
                    <input type="text" id="applyEstDays" readonly placeholder="&mdash;">
                </div>
                <div class="apply-field">
                    <label>Dean Title</label>
                    <input type="text" id="applyDeanTitle" placeholder="Dean">
                </div>
                <div class="apply-field">
                    <label>Dean / Director <span class="required">*</span></label>
                    <select id="applyDean"></select>
                    <div class="apply-parts" id="applyDeanParts" style="display:none;">
                        <input type="text" data-part="first" placeholder="First name">
                        <input type="text" data-part="middle" placeholder="Middle name">
                        <input type="text" data-part="last" placeholder="Last name">
                    </div>
                </div>
                <div class="apply-field">
                    <label>OJT-CDC Director (Noted by)</label>
                    <select id="applyDirector"></select>
                    <div class="apply-parts" id="applyDirectorParts" style="display:none;">
                        <input type="text" data-part="first" placeholder="First name">
                        <input type="text" data-part="middle" placeholder="Middle name">
                        <input type="text" data-part="last" placeholder="Last name">
                    </div>
                </div>
                <div class="apply-field span-3">
                    <div class="apply-note">The rest of the letter is filled in automatically: company contact and address, the students' College, the course's hours and schedule (Course Offering), and you as OJT Adviser.</div>
                </div>
            </div>
            <div class="apply-error" id="applyError"></div>
        </div>
        <div class="apply-foot">
            <button type="button" class="ghost" onclick="previewApplyLetter()"><i class="fas fa-eye"></i> Preview Letter</button>
            <button type="button" class="primary" id="applySubmit" onclick="submitApplyStudents()"><i class="fas fa-paper-plane"></i> Apply &amp; Send Endorsement Letter</button>
        </div>
    </div>
</div>
<div id="applyPreviewOverlay">
    <div class="apply-pv-bar"><span><i class="fas fa-envelope-open-text"></i> Endorsement Letter Preview</span><button type="button" onclick="closeApplyPreview()">Close</button></div>
    <iframe id="applyPreviewFrame" title="Endorsement letter preview"></iframe>
</div>
<div id="globalLoadingOverlay" class="hidden">
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
       • on this page it started out hidden, so it never showed on a page load,
         and leaving this page did not show it either.
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

<div id="messageModal" class="message-modal">
    <div class="message-box">
        <div class="message-header">
            <span>Company Chat</span>
            <span style="cursor:pointer" onclick="closeMessageBox()">✕</span>
        </div>
        <div id="chatMessages" class="chat-messages"></div>
        <div class="chat-input">
            <input type="text" id="messageInput" placeholder="Write a message...">
            <button onclick="sendMessage()">Send</button>
        </div>
    </div>
</div>

<script>
const sidebar = document.getElementById('sidebar');
const toggleBtn = document.getElementById('toggleBtn');
toggleBtn.addEventListener('click', () => {
    sidebar.classList.toggle('collapsed');
});

let receiverEmail = "";
let companyId = "";

function openMessageBox(sender, receiver, id){
    receiverEmail = receiver;
    companyId = id;
    document.getElementById("messageModal").style.display="flex";
    loadMessages();
}

function closeMessageBox(){ document.getElementById("messageModal").style.display="none"; }

function loadMessages(){
    fetch("<?= $_SERVER['PHP_SELF'] ?>?load_messages=1&company_id="+companyId)
    .then(res=>res.text())
    .then(data=>{
        const box = document.getElementById("chatMessages");
        box.innerHTML=data;
        box.scrollTop=box.scrollHeight;
    });
}

function sendMessage(){
    let msg = document.getElementById("messageInput").value;
    if(msg.trim()=="") return;
    fetch("<?= $_SERVER['PHP_SELF'] ?>", {
        method:"POST",
        headers:{'Content-Type':'application/x-www-form-urlencoded'},
        body: "mode=send&company_id="+companyId+"&receiver="+encodeURIComponent(receiverEmail)+"&message="+encodeURIComponent(msg)
    }).then(()=>{
        document.getElementById("messageInput").value="";
        loadMessages();
    });
}

function deleteMessage(id){
    if(!confirm('Delete this message?')) return;
    fetch('<?= $_SERVER['PHP_SELF'] ?>',{
        method:"POST",
        headers:{'Content-Type':'application/x-www-form-urlencoded'},
        body:"mode=delete&company_id="+companyId+"&message_id="+id
    }).then(()=>loadMessages());
}

function editMessage(id){
    let newMsg = prompt("Edit your message:");
    if(newMsg===null) return;
    fetch("<?= $_SERVER['PHP_SELF'] ?>",{
        method:'POST',
        headers:{'Content-Type':'application/x-www-form-urlencoded'},
        body:"mode=edit&message_id="+id+"&new_message="+encodeURIComponent(newMsg)
    }).then(()=>loadMessages());
}

setInterval(()=>{
    if(document.getElementById("messageModal").style.display === "flex") loadMessages();
}, 4000);

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
// admin_student_list.php), so the badge here stays in sync with admin
// actions taken on that page (accepting/rejecting a MOA request) without
// requiring a full page reload.
(function() {
    function pollMoaBadge() {
        // FIX (sidebar notification indicator): polls this page's own endpoint, which uses the
        // same notification rule as company_validation.php (see dashboard_company_validation_notif_count()).
        fetch('admin_monitoring_dashboard.php?cv_sidebar_notif_count=1', { credentials: 'same-origin' })
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

/* ══════════════════════════════════════════════════════════════════════
   ADJUSTMENT: APPLY STUDENTS (batch) — picker, letter options, preview, apply
   ══════════════════════════════════════════════════════════════════════ */
var AMD_MONTHS = ['Jan','Feb','Mar','Apr','May','Jun','Jul','Aug','Sep','Oct','Nov','Dec'];
var _amd = { company: 0, name: '', picked: [], sigs: { dean: [], director: [] }, searchTimer: null, seq: 0 };

function amdEsc(s) { var d = document.createElement('div'); d.textContent = s == null ? '' : String(s); return d.innerHTML; }
function amdInitials(n) { return String(n || '').split(/\s+/).filter(Boolean).map(function (w) { return w[0]; }).slice(0, 2).join('').toUpperCase(); }
function amdPhoto(s, cls) {
    return s.has_photo
        ? '<img src="?amd_student_photo=' + s.id + '" alt=""' + (cls ? ' class="' + cls + '"' : '') + '>'
        : '<span class="ph' + (cls ? ' ' + cls : '') + '">' + amdEsc(amdInitials(s.name)) + '</span>';
}

function openApplyStudents(companyId, companyName) {
    _amd.company = companyId; _amd.name = companyName || ''; _amd.picked = [];
    document.getElementById('applyCoName').textContent = _amd.name;
    document.getElementById('applySearch').value = '';
    document.getElementById('applyResults').classList.remove('open');
    document.getElementById('applyError').style.display = 'none';
    amdRenderChips();
    var ms = document.getElementById('applyMonth');
    if (!ms.options.length) AMD_MONTHS.forEach(function (m, i) { var o = document.createElement('option'); o.value = i + 1; o.textContent = m; ms.appendChild(o); });
    ms.onchange = function () { amdUpdateEstimate(); };
    document.getElementById('applyEndMonth').value = '';
    document.getElementById('applyEstDays').value = '';
    fetch('?amd_signatories=1', { credentials: 'same-origin' }).then(function (r) { return r.json(); }).then(function (res) {
        _amd.sigs = res.signatories || { dean: [], director: [] };
        ms.value = String(res.month || (new Date().getMonth() + 1));
        document.getElementById('applyYear').value = res.year || new Date().getFullYear();
        amdBuildSigSelect('applyDean', _amd.sigs.dean, false);
        amdBuildSigSelect('applyDirector', _amd.sigs.director, true);
        amdUpdateEstimate();
    }).catch(function () {});
    document.getElementById('applyStuModal').classList.add('open');
    setTimeout(function () { document.getElementById('applySearch').focus(); }, 50);
}
function closeApplyStudents() { document.getElementById('applyStuModal').classList.remove('open'); }

function amdBuildSigSelect(id, list, withDefault) {
    var sel = document.getElementById(id);
    sel.innerHTML = '';
    list.forEach(function (s) { var o = document.createElement('option'); o.value = 'id:' + s.id; o.textContent = s.full_name; sel.appendChild(o); });
    if (withDefault) { var d = document.createElement('option'); d.value = 'default'; d.textContent = 'Default \u2014 RANDY M. BA\u00d1EZ, J.D.'; sel.appendChild(d); }
    var ot = document.createElement('option'); ot.value = 'other'; ot.textContent = 'Other (type a new name)...'; sel.appendChild(ot);
    sel.value = list.length ? 'id:' + list[0].id : (withDefault ? 'default' : 'other');
    sel.onchange = function () { amdSigChanged(id); };
    amdSigChanged(id);
}
function amdSigChanged(id) {
    var sel = document.getElementById(id);
    document.getElementById(id + 'Parts').style.display = sel.value === 'other' ? 'grid' : 'none';
    if (id === 'applyDean') {
        var rec = amdSigRecord('dean', sel.value);
        document.getElementById('applyDeanTitle').value = rec && rec.title ? rec.title : (document.getElementById('applyDeanTitle').value || 'Dean');
    }
}
function amdSigRecord(role, value) {
    if (String(value).indexOf('id:') !== 0) return null;
    var id = parseInt(String(value).slice(3), 10);
    return (_amd.sigs[role] || []).find(function (s) { return s.id === id; }) || null;
}
function amdSigValue(role, id) {
    var sel = document.getElementById(id);
    if (sel.value === 'default') return { name: '', parts: null };
    if (sel.value === 'other') {
        var p = {}; document.querySelectorAll('#' + id + 'Parts input').forEach(function (i) { p[i.dataset.part] = i.value.trim(); });
        return { name: [p.first, p.middle, p.last].filter(Boolean).join(' '), parts: p };
    }
    var rec = amdSigRecord(role, sel.value);
    return rec ? { name: rec.full_name, parts: { first: rec.first, middle: rec.middle, last: rec.last } } : { name: '', parts: null };
}

// ── search + multi-select ──
document.getElementById('applySearch').addEventListener('input', function () {
    var q = this.value.trim();
    clearTimeout(_amd.searchTimer);
    if (!q) { document.getElementById('applyResults').classList.remove('open'); return; }
    _amd.searchTimer = setTimeout(function () { amdSearch(q); }, 220);
});
document.getElementById('applySearch').addEventListener('keydown', function (e) {
    var box = document.getElementById('applyResults');
    var items = Array.prototype.slice.call(box.querySelectorAll('.apply-result'));
    if (!items.length) return;
    var idx = items.findIndex(function (x) { return x.classList.contains('active'); });
    if (e.key === 'ArrowDown') { e.preventDefault(); idx = Math.min(items.length - 1, idx + 1); }
    else if (e.key === 'ArrowUp') { e.preventDefault(); idx = Math.max(0, idx - 1); }
    else if (e.key === 'Enter') { e.preventDefault(); (items[idx] || items[0]).click(); return; }
    else return;
    items.forEach(function (x, i) { x.classList.toggle('active', i === idx); });
    if (items[idx]) items[idx].scrollIntoView({ block: 'nearest' });
});
/* FIX (double indicator): when the mouse moves over a search result, that
   row becomes the single .active row, replacing the arrow-key highlight.
   Arrow keys and Enter then continue from the row under the mouse.
   mousemove (not mouseover) is used so rows scrolling under a still
   mouse during arrow-key navigation don't steal the highlight. */
document.getElementById('applyResults').addEventListener('mousemove', function (e) {
    var row = e.target.closest ? e.target.closest('.apply-result') : null;
    if (!row || row.classList.contains('active')) return;
    this.querySelectorAll('.apply-result').forEach(function (x) { x.classList.toggle('active', x === row); });
});
document.addEventListener('click', function (e) {
    if (!e.target.closest || !e.target.closest('.apply-search-wrap')) document.getElementById('applyResults').classList.remove('open');
});

function amdSearch(q) {
    var seq = ++_amd.seq;
    fetch('?amd_search_students=1&q=' + encodeURIComponent(q), { credentials: 'same-origin' })
        .then(function (r) { return r.json(); })
        .then(function (res) {
            if (seq !== _amd.seq) return;
            var picked = _amd.picked.map(function (p) { return p.id; });
            var list = (res.students || []).filter(function (s) { return picked.indexOf(s.id) === -1; });
            var box = document.getElementById('applyResults');
            box.innerHTML = list.length ? list.map(function (s, i) {
                return '<div class="apply-result' + (i === 0 ? ' active' : '') + '" data-i="' + i + '">' + amdPhoto(s) +
                       '<span class="nm">' + amdEsc(s.name) + '</span><span class="cs">' + amdEsc(s.course || '\u2014') + '</span></div>';
            }).join('') : '<div class="apply-empty">No available students match \u201c' + amdEsc(q) + '\u201d.</div>';
            box.querySelectorAll('.apply-result').forEach(function (el) {
                el.addEventListener('click', function () { amdPick(list[parseInt(el.dataset.i, 10)]); });
            });
            box.classList.add('open');
        }).catch(function () {});
}

function amdPick(s) {
    var err = document.getElementById('applyError');
    if (_amd.picked.length && String(_amd.picked[0].course || '').trim().toLowerCase() !== String(s.course || '').trim().toLowerCase()) {
        // ADJUSTMENT: a batch holds ONE course — tell the admin (popup notification) to add this
        // student later, in a separate batch, once the current batch is done.
        amdToast(s.name, '(' + (s.course || 'no course') + ') cannot join this batch \u2014 it is for ' +
                 (_amd.picked[0].course || 'another course') + '. Apply them later, in a separate batch, once this batch is done.',
                 'fa-circle-exclamation', true);
        var inp0 = document.getElementById('applySearch');
        inp0.value = ''; inp0.focus();
        document.getElementById('applyResults').classList.remove('open');
        return;
    }
    err.style.display = 'none';
    _amd.picked.push(s);
    amdRenderChips();
    var inp = document.getElementById('applySearch');
    inp.value = ''; inp.focus();
    document.getElementById('applyResults').classList.remove('open');
}
function amdUnpick(id) {
    _amd.picked = _amd.picked.filter(function (p) { return p.id !== id; });
    amdRenderChips();
}
function amdRenderChips() {
    document.getElementById('applyChips').innerHTML = _amd.picked.map(function (s) {
        return '<span class="apply-chip">' + amdPhoto(s) + amdEsc(s.name) + '<button type="button" title="Remove" onclick="amdUnpick(' + s.id + ')">&times;</button></span>';
    }).join('');
    amdUpdateEstimate();
}

/* ADJUSTMENT: live-fills the modal's End Month + Est. Day fields from the
   picked students' shared course and the chosen Start month, using the same
   Est. Duty Days rule the endorsement letter itself is built from
   (amd_course_estimate → amdCourseEstimate() on the server). Runs whenever a
   student is picked/unpicked or the Start month changes; clears both fields
   when no student is picked yet (course isn't known until then). */
function amdUpdateEstimate() {
    var endEl = document.getElementById('applyEndMonth'), daysEl = document.getElementById('applyEstDays');
    if (!endEl || !daysEl) return;
    var course = _amd.picked.length ? (_amd.picked[0].course || '') : '';
    if (!course) { endEl.value = ''; daysEl.value = ''; return; }
    var month = document.getElementById('applyMonth').value;
    fetch('?amd_course_estimate=1&course=' + encodeURIComponent(course) + '&month=' + encodeURIComponent(month), { credentials: 'same-origin' })
        .then(function (r) { return r.json(); })
        .then(function (res) {
            endEl.value = res.end_date || '';
            daysEl.value = res.days ? (res.days + (res.days === 1 ? ' day' : ' days')) : '';
        })
        .catch(function () { endEl.value = ''; daysEl.value = ''; });
}

function amdPayload() {
    var fd = new FormData();
    fd.append('company_id', _amd.company);
    fd.append('student_ids', JSON.stringify(_amd.picked.map(function (p) { return p.id; })));
    fd.append('start_month', document.getElementById('applyMonth').value);
    var dean = amdSigValue('dean', 'applyDean'), dir = amdSigValue('director', 'applyDirector');
    fd.append('dean_name', dean.name);
    fd.append('dean_parts', JSON.stringify(dean.parts));
    fd.append('dean_title', document.getElementById('applyDeanTitle').value.trim());
    fd.append('director_name', dir.name);
    fd.append('director_parts', JSON.stringify(dir.parts));
    return { fd: fd, dean: dean };
}
function amdCheck(needDean) {
    var err = document.getElementById('applyError');
    var msg = '';
    if (!_amd.picked.length) msg = 'Please choose at least one student.';
    else if (needDean && !amdSigValue('dean', 'applyDean').name) msg = 'Please choose or enter the Dean / Director.';
    err.textContent = msg; err.style.display = msg ? 'block' : 'none';
    return !msg;
}

function previewApplyLetter() {
    if (!amdCheck(false)) return;
    var p = amdPayload(); p.fd.append('amd_letter_preview', '1');
    amdShowLoading('Preparing letter preview');
    fetch(window.location.pathname, { method: 'POST', body: p.fd, credentials: 'same-origin' })
        .then(function (r) { return r.text(); })
        .then(function (html) {
            document.getElementById('applyPreviewFrame').srcdoc = html;
            document.getElementById('applyPreviewOverlay').classList.add('open');
        })
        .finally(function () { amdHideLoading(); });
}
function closeApplyPreview() {
    document.getElementById('applyPreviewOverlay').classList.remove('open');
    document.getElementById('applyPreviewFrame').srcdoc = '';
}

function submitApplyStudents() {
    if (!amdCheck(true)) return;
    var p = amdPayload(); p.fd.append('amd_apply_students', '1');
    var btn = document.getElementById('applySubmit'); btn.disabled = true;
    var n = _amd.picked.length, co = _amd.name;
    amdShowLoading('Applying students & sending letter');
    fetch(window.location.pathname, { method: 'POST', body: p.fd, credentials: 'same-origin' })
        .then(function (r) { return r.json(); })
        .then(function (res) {
            btn.disabled = false;
            if (res && res.success) {
                closeApplyStudents();
                amdToast(n + ' student' + (n === 1 ? '' : 's'), 'applied to ' + (res.company || co) + ' \u2014 the shared endorsement letter was sent to their Inbox.', 'fa-envelope-circle-check');
            } else {
                var err = document.getElementById('applyError');
                err.textContent = (res && res.message) || 'Could not apply the students. Please try again.';
                err.style.display = 'block';
            }
        })
        .catch(function () {
            btn.disabled = false;
            var err = document.getElementById('applyError');
            err.textContent = 'Network error. Please try again.'; err.style.display = 'block';
        })
        .finally(function () { amdHideLoading(); });
}

// loading page + popup (administrator.php look)
var _amdLoadStart = 0;
function amdShowLoading(label) {
    document.getElementById('globalLoadingLabel').textContent = label || 'Loading';
    document.getElementById('globalLoadingOverlay').classList.remove('hidden');
    _amdLoadStart = Date.now();
}
function amdHideLoading() {
    setTimeout(function () { document.getElementById('globalLoadingOverlay').classList.add('hidden'); }, Math.max(0, 500 - (Date.now() - _amdLoadStart)));
}
function amdToast(name, text, icon, isError) {
    var div = document.createElement('div');
    div.className = 'cv-top-toast' + (isError ? ' is-error' : '');
    div.setAttribute('role', 'status');
    div.innerHTML = '<i class="fas ' + (icon || 'fa-circle-info') + '"></i><span>' + (name ? '<strong>' + amdEsc(name) + '</strong> ' : '') + amdEsc(text) + '</span>';
    document.body.appendChild(div);
    var top = 30; document.querySelectorAll('.cv-top-toast').forEach(function (el) { el.style.top = top + 'px'; top += el.offsetHeight + 12; });
    requestAnimationFrame(function () { div.classList.add('show'); });
    setTimeout(function () { div.classList.remove('show'); setTimeout(function () { div.remove(); }, 400); }, 7000);
}
// FIX: floating tooltip for the Apply Students button (see #amdFloatTip CSS).
(function () {
    var tip = document.createElement('div');
    tip.id = 'amdFloatTip';
    tip.setAttribute('role', 'tooltip');
    document.body.appendChild(tip);
    function show(el) {
        tip.textContent = el.getAttribute('data-tip') || '';
        var r = el.getBoundingClientRect();
        tip.style.left = '0px'; tip.style.top = '0px';
        var w = tip.offsetWidth, h = tip.offsetHeight;
        var left = Math.min(Math.max(8, r.left + r.width / 2 - w / 2), window.innerWidth - w - 8);
        tip.style.left = left + 'px';
        tip.style.top = Math.max(8, r.top - h - 10) + 'px';
        // keep the arrow pointing at the button even when the tooltip is nudged sideways
        tip.style.setProperty('--arrow-x', (r.left + r.width / 2 - left) + 'px');
        tip.classList.add('show');
    }
    function hide() { tip.classList.remove('show'); }
    document.addEventListener('mouseover', function (e) { var t = e.target.closest && e.target.closest('.amd-tip'); if (t) show(t); });
    document.addEventListener('mouseout', function (e) { var t = e.target.closest && e.target.closest('.amd-tip'); if (t && !t.contains(e.relatedTarget)) hide(); });
    document.addEventListener('focusin', function (e) { var t = e.target.closest && e.target.closest('.amd-tip'); if (t) show(t); });
    document.addEventListener('focusout', hide);
    document.addEventListener('click', hide, true);
    window.addEventListener('scroll', hide, true);
})();

document.addEventListener('keydown', function (e) {
    if (e.key !== 'Escape') return;
    if (document.getElementById('applyPreviewOverlay').classList.contains('open')) { closeApplyPreview(); return; }
    if (document.getElementById('applyStuModal').classList.contains('open')) closeApplyStudents();
});
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
    function labelOf(b) {
        if (b.id === 'toggleBtn') {
            var sb = document.getElementById('sidebar');
            return sb && sb.classList.contains('collapsed') ? 'Expand menu' : 'Collapse menu';
        }
        var t = b.getAttribute('data-cv-tip') || b.getAttribute('aria-label') || b.getAttribute('data-cv-title') || b.getAttribute('title') || '';
        if (!t) t = b.tagName === 'INPUT' ? (b.value || '') : (b.textContent || '');
        t = t.replace(/\s+/g, ' ').trim();
        if (/^[\u00D7\u2715xX]$/.test(t)) t = 'Close';
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

    function go(spec) {
        var p = String(spec || '').split(':');
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
    if (spec) {
        ['open_notif', 'uid', 'type', 'open_app_request', 'open_recovery'].forEach(function (k) { params.delete(k); });
        if (window.history.replaceState) {
            var q = params.toString();
            window.history.replaceState({}, document.title, window.location.pathname + (q ? '?' + q : '') + window.location.hash);
        }
        var start = function () { setTimeout(function () { go(spec); }, 600); };
        if (document.readyState === 'complete') start(); else window.addEventListener('load', start);
    }
})();
</script>
</body>
</html>