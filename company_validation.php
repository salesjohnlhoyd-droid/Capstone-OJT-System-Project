<?php

// ── CLEAN-UP (project audit): ONE definition of the `archived_companies` table. It used to be written out 2 times in
//    this file (1 different version(s)). CREATE TABLE IF NOT EXISTS only acts once, so whichever copy ran
//    first decided the columns; every former copy now calls this complete definition instead. ──
function cv_ensure_archived_companies_table($conn) {
    $conn->query("CREATE TABLE IF NOT EXISTS archived_companies (id INT AUTO_INCREMENT PRIMARY KEY,batch_label VARCHAR(200),user_id INT,company VARCHAR(200),company_address VARCHAR(300),telephone VARCHAR(100),contact_name VARCHAR(200),position VARCHAR(100),company_type VARCHAR(50),validation_status VARCHAR(50),archived_at DATETIME,archived_by VARCHAR(200))");
}


// ── CLEAN-UP (project audit): ONE definition of the `moa_requests` table. It used to be written out 6 times in
//    this file (3 different version(s)). CREATE TABLE IF NOT EXISTS only acts once, so whichever copy ran
//    first decided the columns; every former copy now calls this complete definition instead. ──
function cv_ensure_moa_requests_table($conn) {
    $conn->query("CREATE TABLE IF NOT EXISTS moa_requests (id INT AUTO_INCREMENT PRIMARY KEY, user_id INT, request_type VARCHAR(20) NOT NULL, company_name VARCHAR(200), company_profile TEXT, company_address VARCHAR(300), position VARCHAR(150), contact_first_name VARCHAR(100), contact_middle_name VARCHAR(100), contact_last_name VARCHAR(100), status VARCHAR(20) DEFAULT 'Pending', moa_workflow_status VARCHAR(30) DEFAULT 'pending', submitted_at DATETIME DEFAULT CURRENT_TIMESTAMP, moa_pdf LONGBLOB NULL, moa_pdf_filename VARCHAR(255) NULL)");
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
include "db.php";
include "mail.php";

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
    [function () { return cv_alog_is_post('ajax_save_requirement'); }, function ($conn) {
        $n = cv_alog_company_name($conn, (int)($_POST['user_id'] ?? 0)); $w = cv_alog_status_word($_POST['status'] ?? ''); $r = cv_alog_post('remark');
        return ['Requirement ' . $w, 'Company', $n, cv_alog_post('requirement_type') . " marked as " . strtolower($w) . " for $n" . ($r !== '' ? " — remark: $r" : '') . " via Company Requirements"];
    }],
    [function () { return cv_alog_is_post('ajax_update_moa_req_workflow'); }, function ($conn) {
        $n = cv_alog_company_name($conn, (int)($_POST['user_id'] ?? 0)); $st = ucwords(str_replace('_', ' ', cv_alog_post('stage')));
        $when = trim(cv_alog_post('schedule_date') . ' ' . cv_alog_post('schedule_time'));
        return ['MOA Stage Updated', 'Company', $n, "Moved the MOA of $n to \"$st\"" . ($when !== '' ? " (schedule: $when)" : '') . " via Company Requirements"];
    }],
    [function () { return cv_alog_is_post('ajax_moa_drawer_action'); }, function ($conn) {
        $n = (string)cv_alog_scalar($conn, "SELECT company_name FROM moa_requests WHERE id = ?", 'i', [(int)($_POST['moa_id'] ?? 0)]);
        $ok = cv_alog_post('action') === 'accept';
        return [$ok ? 'MOA Request Accepted' : 'MOA Request Rejected', 'Company', $n, ($ok ? 'Accepted' : 'Rejected') . " the MOA request of $n via Company Requirements"];
    }],
    [function () { return cv_alog_is_post('ajax_moa_reject_send'); }, function ($conn) {
        $n = (string)cv_alog_scalar($conn, "SELECT company_name FROM moa_requests WHERE id = ?", 'i', [(int)($_POST['moa_id'] ?? 0)]);
        $flags = isset($_POST['flags']) ? implode(', ', array_map('strval', (array)$_POST['flags'])) : '';
        return ['MOA Request Rejected', 'Company', $n, "Rejected the MOA request of $n" . ($flags !== '' ? " (flagged: $flags)" : '') . " and notified the company via Company Requirements"];
    }],
    [function () { return cv_alog_is_post('ajax_moa_table_flag_revision'); }, function ($conn) {
        $n = cv_alog_company_name($conn, (int)($_POST['user_id'] ?? 0));
        $flags = isset($_POST['flags']) ? implode(', ', array_map('strval', (array)$_POST['flags'])) : '';
        return ['MOA Revision Flagged', 'Company', $n, "Flagged the MOA of $n for revision" . ($flags !== '' ? " ($flags)" : '') . " via Company Requirements"];
    }],
    [function () { return cv_alog_is_post('ajax_moa_mark_done'); }, function ($conn) {
        $n = cv_alog_company_name($conn, (int)($_POST['user_id'] ?? 0));
        return ['MOA Marked Done', 'Company', $n, "Marked the MOA of $n as done via Company Requirements"];
    }],
    [function () { return cv_alog_is_post('ajax_accept_proposed_schedule'); }, function ($conn) {
        $n = cv_alog_company_name($conn, (int)($_POST['user_id'] ?? 0));
        return ['Schedule Accepted', 'Company', $n, "Accepted the signing schedule proposed by $n via Company Requirements"];
    }],
    [function () { return cv_alog_is_post('ajax_undo') || cv_alog_is_post('ajax_moa_undo'); }, function ($conn) {
        return ['Action Undone', 'Company Requirements', '—', "Undid the last action via Company Requirements"];
    }],
    [function () { return cv_alog_is_post('ajax_archive_company_batch'); }, function ($conn) {
        $b = cv_alog_post('batch_label');
        return ['Batch Archived', 'Company Batch', $b, "Archived company batch $b via Company Requirements"];
    }],
    [function () { return cv_alog_is_post('ajax_unarchive_company_batch'); }, function ($conn) {
        $b = cv_alog_post('batch_label');
        return ['Batch Restored', 'Company Batch', $b, "Restored company batch $b from the archive via Company Requirements"];
    }],
]);

// ── MOA Debug Logger ──
function moaDebugLog($step, $data = []) {
    $entry = json_encode([
        'ts'   => date('Y-m-d H:i:s'),
        'step' => $step,
        'data' => $data,
    ]);
    @file_put_contents(__DIR__ . '/moa_debug.log', $entry . "\n", FILE_APPEND | LOCK_EX);
}

// ════════════════════════════════════════════════════════════════════
//  NEW (this adjustment) — LIVE REQUIREMENT-UPLOAD PROBE.
//  The page polls this every few seconds so a requirement a company just
//  uploaded on CompanyForm.php shows up here WITHOUT a manual refresh.
//  A company (re)submitting a requirement always writes NEW
//  company_requirements rows (CompanyForm.php deletes the old set and
//  inserts each file as its own row, so the ids keep growing), which
//  makes "any compliance row with an id above the last one this page has
//  seen" a reliable, stateless upload signal — the client sends the
//  highest id it has seen (after_id) and gets back the company user_ids
//  that have newer rows, plus the new highest id. Deliberately:
//    • read-only and DDL-free (only cheap SELECTs), and placed above the
//      heavy per-request setup below, so polling it costs almost nothing;
//    • it releases the session lock straight away, so it never queues
//      behind (or blocks) another request from the same admin;
//    • MOA documents ('moa_document' / 'moa') are excluded — they have
//      their own notification flow and live refresh.
//  Turning an upload into an inbox notification happens in
//  detectAndNotifyRequirementUploads() further down.
// ════════════════════════════════════════════════════════════════════
if (isset($_POST['ajax_poll_requirement_uploads'])) {
    session_write_close();
    header('Content-Type: application/json');
    $probeAfter = (isset($_POST['after_id']) && $_POST['after_id'] !== '') ? max(0, (int)$_POST['after_id']) : null;
    $probeMax   = 0;
    $probeUids  = [];
    try {
        $probeRes = $conn->query("SELECT COALESCE(MAX(id),0) AS mx FROM company_requirements WHERE requirement_type NOT IN ('moa_document','moa')");
        if ($probeRes && ($probeRow = $probeRes->fetch_assoc())) $probeMax = (int)$probeRow['mx'];
        if ($probeAfter !== null && $probeMax > $probeAfter) {
            $probeStmt = $conn->prepare("SELECT DISTINCT user_id FROM company_requirements WHERE id > ? AND requirement_type NOT IN ('moa_document','moa') AND user_id IS NOT NULL ORDER BY user_id LIMIT 200");
            if ($probeStmt) {
                $probeStmt->bind_param("i", $probeAfter);
                $probeStmt->execute();
                $probeUidRes = $probeStmt->get_result();
                while ($probeUidRow = $probeUidRes->fetch_assoc()) $probeUids[] = (int)$probeUidRow['user_id'];
                $probeStmt->close();
            }
        }
    } catch (\Throwable $probeErr) {
        echo json_encode(['success' => false]);
        exit;
    }
    echo json_encode(['success' => true, 'max_id' => $probeMax, 'uids' => $probeUids]);
    exit;
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

// Ungraded faculty_grade count
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
$pageTitle = "Company Validation";

// ── NEW: full name shown at the top of the sidebar is now built from
// first_name + middle_name + last_name, looked up directly from the
// `admin` table (not `users`) using the logged-in admin's id, since the
// admin's identity record lives there. Session values are kept only as a
// fallback if the DB lookup comes back empty, and a generic label is used
// as a last resort. The role label underneath remains "Administrator".
// (Mirrors the same logic used in admin_student_list.php.) ──
$adminFullName = '';
$adminNameStmt = $conn->prepare("SELECT first_name, middle_name, last_name FROM admins WHERE id = ?");
if ($adminNameStmt) {
    $adminNameStmt->bind_param("i", $_SESSION['user_id']);
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

// ── NEW (this adjustment): no more manual "Accept" step. Every MOA
// request that comes in from moa_request.php lands here as a
// moa_requests row with status='Pending'; instead of waiting for an
// admin to click "Accept" in the drawer, it is transferred straight into
// the "New MOA — Requirements & MOA Workflow" table automatically, the
// very first time this file runs after it was submitted (whether that's
// a normal page load or one of the background AJAX polls), via
// autoIngestPendingMoaRequests() below. This call is placed here,
// unconditionally, before any of the AJAX action branches further down,
// so every single request to this file (full page loads AND every
// ajax_fetch_moa_requests poll) keeps the "New" table and the MOA
// Requests notification inbox fully up to date in near real-time — not
// just on a full page reload. The function is defined further down the
// file (alongside the other MOA helpers); PHP hoists top-level function
// declarations, so calling it here is safe.
autoIngestPendingMoaRequests($conn);

// ── NEW (this adjustment): companies whose MOA was created WITHOUT ever
// going through moa_requests — a "Request New MOA" account auto-generated
// at registration by company_register.php, or a manually-added/imported
// "New" request-type company created by admin_company_list.php (whose
// MOA gets created later via CompanyForm.php's own self-service "Create
// MOA" action) — never triggered the notification above at all, since it
// only ever watches the moa_requests table. detectAndNotifyDirectMoaEntries()
// closes that gap by watching company_requirements directly for a
// still-untouched MOA document that has no moa_requests row behind it at
// all, and surfacing exactly one notification for it the same way — see
// that function's own docblock (defined further down; hoisted the same
// way as autoIngestPendingMoaRequests() above) for the full detection
// logic and why it can't just re-run this migration-style check forever.
detectAndNotifyDirectMoaEntries($conn);

// ── NEW (this adjustment): a company complying with a flagged MOA
// revision, agreeing to a proposed signing schedule, or declining one and
// proposing an alternative — all happen on CompanyForm.php, which marks
// the affected company_requirements row with moa_pending_admin_notice
// (one of 'revision_complied' / 'schedule_agreed' / 'schedule_declined')
// at the moment each event occurs. detectAndNotifyComplianceEvents()
// picks those markers up here, on every request, and surfaces exactly
// one notification for each into the same MOA Requests inbox the "new
// request" notifications already use — see that function's own docblock
// (defined further down; hoisted the same way as the two calls above)
// for the full detection logic.
detectAndNotifyComplianceEvents($conn);

$companyReqLabels = [
    "company_profile"          => "Company Profile",
    "vision_mission"           => "Vision and Mission",
    "mayors_permit"            => "Mayor's Permit",
    "sec_registration"         => "SEC Registration",
    "dti_registration"         => "DTI Certificate",
    "cda_registration"         => "CDA Certificate",
    "bir_clearance"            => "BIR Clearance",
    "ohs_plan"                 => "OHS Plan",
    "training_supervisor_cv"   => "Training Supervisor CV",
    "authority_moa"            => "Authority to Sign MOA",
    "authority_moa_public"     => "Authority to Sign MOA (Public)",
    "training_supervisor_pds"  => "Training Supervisor PDS",
    "legislative_charter"      => "Legislative Charter",
    "moa_document"             => "MOA Document",
    // ── NEW (this adjustment): label for the additive "Existing"-only MOA
    // upload item CompanyForm.php appends to a company's compliance
    // checklist (key "moa_existing_upload") for any company whose MOA
    // request type is "Existing" — see the isCompanyRequestTypeExisting()
    // helper and its two call sites below for where this is required.
    // Kept byte-for-byte identical to the label used in CompanyForm.php's
    // $moa_existing_reqs array so the label reads the same on both sides.
    "moa_existing_upload"       => "MOA Document (Existing Partnership)",
];

// ── NEW (this adjustment): SINGLE SOURCE OF TRUTH for the classification-
// based compliance checklists, kept byte-for-byte identical (same keys,
// same order) to $private_reqs / $public_reqs in CompanyForm.php. Every
// place on this page that previously hardcoded its own
// Private-vs-Public requirement-key list (recomputeCompanyValidationStatus(),
// the "Existing" table loop, and the "New" table loop) now reads from these
// two arrays instead, so the admin's validation checklist, overall
// verification logic, and the company-facing checklist in CompanyForm.php
// can never drift out of sync again. This fixes a prior mismatch where the
// Private-company list here only had 6 items (missing dti_registration,
// cda_registration, ohs_plan, and training_supervisor_cv) while
// CompanyForm.php always required and displayed the full 10.
$private_compliance_reqs = [
    "company_profile",
    "vision_mission",
    "mayors_permit",
    "sec_registration",
    "dti_registration",
    "cda_registration",
    "bir_clearance",
    "ohs_plan",
    "training_supervisor_cv",
    "authority_moa",
];
$public_compliance_reqs = [
    "authority_moa_public",
    "training_supervisor_pds",
    "legislative_charter",
];

// ── NEW (this adjustment): REQUIREMENT-UPLOAD NOTIFICATIONS. A company
// uploading (or re-uploading) compliance documents on CompanyForm.php now
// surfaces in the same notification inbox as the MOA events above, as a
// "Requirement Uploaded" entry. They live in their OWN table
// (company_requirement_upload_notifications) rather than moa_requests on
// purpose: several existing flows treat "this company's latest moa_requests
// row" as its real MOA request (Flag for Revision, and the "no moa_requests
// row yet" test in detectAndNotifyDirectMoaEntries()), so a lightweight
// upload row there would shadow the real one. To show up in the SAME inbox
// list anyway, their ids are reported to the page as
// CV_REQ_NOTIF_ID_OFFSET + row id (moa_requests ids are nowhere near that
// large), which lets the inbox treat every id as an opaque number and lets
// ajax_moa_mark_notification_viewed tell which table to update.
// This is called here — after $companyReqLabels above, which it uses for
// the document names — on every request, exactly like the other detectors.
if (!defined('CV_REQ_NOTIF_ID_OFFSET')) define('CV_REQ_NOTIF_ID_OFFSET', 1000000000);
detectAndNotifyRequirementUploads($conn);

// Highest compliance-requirement row id at the moment this page is built.
// The page's live-upload poller starts from it, so anything uploaded after
// this point is picked up as new (see the ajax_poll_requirement_uploads
// probe near the top of this file and cvPollRequirementUploads() below).
$cvReqBaselineMaxId = 0;
$cvReqBaselineRes = $conn->query("SELECT COALESCE(MAX(id),0) AS mx FROM company_requirements WHERE requirement_type NOT IN ('moa_document','moa')");
if ($cvReqBaselineRes && ($cvReqBaselineRow = $cvReqBaselineRes->fetch_assoc())) $cvReqBaselineMaxId = (int)$cvReqBaselineRow['mx'];

// ── NEW (this adjustment): human-readable labels for the flaggable MOA-section fields.
// This was previously referenced via $GLOBALS['moaRejectFlagLabels'] inside
// the ajax_moa_reject_send handler below, but the array itself was never
// actually defined anywhere — that combined with an invalid `use ($GLOBALS)`
// closure capture (PHP forbids capturing superglobals in a `use()` clause)
// was the direct cause of the "Cannot use auto-global as lexical variable"
// fatal error. Defining it here (alongside the other label maps) fixes both
// the missing-data problem and gives the closure below something valid to
// read via $GLOBALS without capturing it.
// ── UPDATED (adjustment): "moa_document" was added so the admin can also
// flag the uploaded MOA file itself (not just the text fields) as the thing
// that needs correction when rejecting an "already have MOA" submission.
$moaRejectFlagLabels = [
    "company_name"          => "Company Name",
    "company_profile"       => "Company Profile",
    "company_address"       => "Company Address",
    "position"              => "Position",
    "contact_first_name"    => "Contact First Name",
    "contact_middle_name"   => "Contact Middle Name",
    "contact_last_name"     => "Contact Last Name",
    "telephone"             => "Telephone",
    "moa_document"          => "Uploaded MOA Document"
];

$remarks = ["Blurry Image", "Wrong Document", "Incomplete Document", "Unreadable File", "Incorrect Format", "Expired Document", "Fake or Invalid"];

// Ensure moa_document_workflow column exists
$conn->query("ALTER TABLE company_requirements ADD COLUMN IF NOT EXISTS moa_workflow_stage VARCHAR(30) DEFAULT 'pending'");
$conn->query("ALTER TABLE company_requirements ADD COLUMN IF NOT EXISTS moa_admin_comment TEXT NULL");
$conn->query("ALTER TABLE company_requirements ADD COLUMN IF NOT EXISTS moa_schedule_datetime DATETIME NULL");
// -- NEW (this adjustment): the company representative must now agree to
// (or decline) whatever signing schedule the admin sets -- see the
// "SIGNING SCHEDULE CONFIRMATION" block further down for the full flow.
// -- UPDATED (this adjustment): added 'confirmed_by_admin' -- the admin
// accepting the company's own counter-proposal is a distinct event from
// the company clicking "I Agree" themselves, so it gets its own status
// value and accurately-credited wording on both sides instead of both
// being labeled identically as "company confirmed".
// moa_schedule_status: NULL/'pending_confirmation' (set the moment the
// admin (re)schedules) | 'confirmed' (company agreed) |
// 'confirmed_by_admin' (admin accepted the company's proposed
// alternative) | 'declined' (company proposed a different date/time
// instead -- see the two columns after it).
$conn->query("ALTER TABLE company_requirements ADD COLUMN IF NOT EXISTS moa_schedule_status VARCHAR(20) NULL");
$conn->query("ALTER TABLE company_requirements ADD COLUMN IF NOT EXISTS moa_schedule_decline_reason TEXT NULL");
$conn->query("ALTER TABLE company_requirements ADD COLUMN IF NOT EXISTS moa_proposed_datetime DATETIME NULL");
// ── NEW (this adjustment): column that stores the ORIGINAL moa_requests
// "request_type" ('new' or 'existing') on the company_requirements row
// itself, so it is automatically persisted the moment an MOA is accepted
// — see transferMoaToRequirements() below for where this gets written.
$conn->query("ALTER TABLE company_requirements ADD COLUMN IF NOT EXISTS request_type VARCHAR(20) NULL");
// ── NEW (this adjustment): columns that back the merged "Pending for
// Review" MOA stage. moa_needs_revision / moa_flagged_fields /
// moa_revision_comment let the admin flag the in-table MOA row (while it
// sits in the merged Pending-for-Review stage) as needing correction —
// without leaving that stage — instead of the old separate inbox
// Accept/Reject step. See flagMoaRowForRevision()/ajax_moa_table_flag_revision
// below for where these are written, and renderCompanyValidationRow()
// for where the "Needs Revision" badge is displayed from them.
$conn->query("ALTER TABLE company_requirements ADD COLUMN IF NOT EXISTS moa_needs_revision TINYINT(1) NOT NULL DEFAULT 0");
$conn->query("ALTER TABLE company_requirements ADD COLUMN IF NOT EXISTS moa_flagged_fields TEXT NULL");
$conn->query("ALTER TABLE company_requirements ADD COLUMN IF NOT EXISTS moa_revision_comment TEXT NULL");

/* ================= HELPER: RESOLVE A COMPANY'S MOA REQUEST TYPE ================= */
// NEW (this adjustment) — determines whether a company belongs in the
// "Existing" or "New" MOA request-type table (see the two-table split
// further down in the page). Resolution order:
//   1) users.request_type — set directly by company_register.php's
//      registration flow, which always writes the fixed value "New"
//      (that flow only ever submits a "Request New MOA" application, and
//      never creates a moa_requests row at all).
//   2) company_requirements.request_type on this company's MOA row
//      (requirement_type 'moa_document' or 'moa') — set by
//      transferMoaToRequirements() below when an admin accepts a
//      moa_request.php submission; values are 'new'/'existing'.
//   3) Default: 'existing' — matches companies that predate the
//      MOA-specific workflow entirely (plain legacy records with no
//      request_type set anywhere), so they land in the simpler,
//      compliance-checklist-only "Existing" table rather than a "New"
//      table that would expect an MOA workflow they never started.
function resolveCompanyRequestType($conn, $user_id) {
    if (!$user_id) return 'existing';

    $uq = $conn->prepare("SELECT request_type FROM users WHERE id=?");
    $uq->bind_param("i", $user_id);
    $uq->execute();
    $urow = $uq->get_result()->fetch_assoc();
    $uq->close();
    if (!empty($urow['request_type'])) {
        return (strtolower($urow['request_type']) === 'new') ? 'new' : 'existing';
    }

    $rq = $conn->prepare("SELECT request_type FROM company_requirements WHERE user_id=? AND requirement_type IN ('moa_document','moa') ORDER BY id DESC LIMIT 1");
    $rq->bind_param("i", $user_id);
    $rq->execute();
    $rrow = $rq->get_result()->fetch_assoc();
    $rq->close();
    if (!empty($rrow['request_type'])) {
        return (strtolower($rrow['request_type']) === 'new') ? 'new' : 'existing';
    }

    return 'existing';
}

/* ================= HELPER: RESOLVE A COMPANY'S CLASSIFICATION (Private/Public) =================
   NEW (this adjustment) — fixes company_type showing empty (and, worse,
   silently defaulting to "Private" wherever that empty value was compared
   against "public") for a company added through admin_company_list.php's
   manual "Add Company" form or XLSX import. Root cause: that flow's
   attempt_create_company_account() deliberately stores company_type on
   the `users` row it creates, NOT on `company_information` — see that
   file's own "Company Type storage revision" comments. Every place in
   THIS file that read company_type only ever queried
   company_information.company_type, which is simply never populated for
   these companies. CompanyForm.php already has this exact same fallback
   (reading users.company_type fresh, since it can change after the
   company_information row was first created); this helper mirrors it for
   the single-company lookups in this file (recomputeCompanyValidationStatus
   below). The list queries further down (the main company table, the
   export, and the archive handlers) get the same fix directly in their
   SQL via COALESCE(ci.company_type, u.company_type) instead, for the same
   reason CompanyForm.php prefers the fresher users.company_type when it's
   set. Falls back to 'private' when neither is set, matching this file's
   existing default elsewhere. ================================ */
function resolveCompanyType($conn, $user_id) {
    if (!$user_id) return 'private';
    $q = $conn->prepare("
        SELECT COALESCE(NULLIF(ci.company_type, ''), NULLIF(u.company_type, '')) AS company_type
        FROM users u
        LEFT JOIN company_information ci ON ci.user_id = u.id
        WHERE u.id = ?
    ");
    if (!$q) return 'private';
    $q->bind_param("i", $user_id);
    $q->execute();
    $row = $q->get_result()->fetch_assoc();
    $q->close();
    $ct = strtolower(trim((string)($row['company_type'] ?? '')));
    return ($ct === 'public') ? 'public' : 'private';
}

/* ================= HELPER: STRICTLY DETECT AN ACTUAL "EXISTING" REQUEST TYPE =================
   NEW (this adjustment) — resolveCompanyRequestType() above DEFAULTS an
   unknown/legacy company (no request_type recorded anywhere) to
   'existing', purely so it lands in the simpler compliance-checklist-only
   "Existing" table below rather than the "New" MOA-workflow table it
   never actually started. That default is a display/bucketing choice
   only — it must NEVER be treated as if the company genuinely chose
   "Existing", because CompanyForm.php only shows/collects its additive
   "MOA Document (Existing Partnership)" upload item
   (requirement_type 'moa_existing_upload') when an ACTUAL "Existing"
   value is on record; a company defaulted into this bucket with no real
   request_type was never asked to upload that document at all, so
   requiring it here would create a permanently-unfulfillable requirement.

   This helper mirrors CompanyForm.php's own tolerant resolution exactly
   (users.request_type first, falling back to the company_requirements
   MOA row's request_type, matched case-insensitively via a substring
   check so "Existing", "existing ", "Existing Company", etc. all match)
   but — critically — returns false rather than defaulting to true when
   nothing is found. Used by recomputeCompanyValidationStatus() and the
   "Existing" table's row loop below to decide, per company, whether the
   moa_existing_upload item should actually be required/shown.
   ================================ */
function isCompanyRequestTypeExisting($conn, $user_id) {
    if (!$user_id) return false;

    $uq = $conn->prepare("SELECT request_type FROM users WHERE id=?");
    $uq->bind_param("i", $user_id);
    $uq->execute();
    $urow = $uq->get_result()->fetch_assoc();
    $uq->close();
    $rawUserType = trim((string)($urow['request_type'] ?? ''));
    if ($rawUserType !== '') {
        return (stripos($rawUserType, 'existing') !== false);
    }

    $rq = $conn->prepare("SELECT request_type FROM company_requirements WHERE user_id=? AND requirement_type IN ('moa_document','moa') ORDER BY id DESC LIMIT 1");
    $rq->bind_param("i", $user_id);
    $rq->execute();
    $rrow = $rq->get_result()->fetch_assoc();
    $rq->close();
    $rawReqType = trim((string)($rrow['request_type'] ?? ''));
    if ($rawReqType !== '') {
        return (stripos($rawReqType, 'existing') !== false);
    }

    return false;
}

/* ================= HELPER: RESOLVE THE MOA DOCUMENT ROW'S ACTUAL KEY =================
   NEW (this adjustment) — fixes the MOA in-table workflow showing
   "Awaiting MOA" for a company whose MOA document was actually already
   submitted. Root cause: company_register.php auto-generates and saves
   the MOA at registration time under requirement_type = 'moa' (see
   regSaveGeneratedMoaToRequirements() there), NOT 'moa_document'.
   CompanyForm.php already treats these two keys as the same document
   everywhere it reads them (`requirement_type IN ('moa_document','moa')`,
   picking whichever row was written most recently), but this page's MOA
   in-table workflow only ever matched 'moa_document' exactly — so a
   registration-generated 'moa' row, holding a real submitted file, was
   completely invisible here. This helper mirrors CompanyForm.php's own
   resolution exactly and is now the single source of truth every MOA
   read/write in this file goes through, so both keys are always found.
   Returns 'moa_document' when no row exists yet under either key (the
   correct default for a brand-new INSERT). ================================ */
function resolveMoaRequirementType($conn, $user_id) {
    if (!$user_id) return 'moa_document';
    $q = $conn->prepare("SELECT requirement_type FROM company_requirements WHERE user_id=? AND requirement_type IN ('moa_document','moa') ORDER BY id DESC LIMIT 1");
    if (!$q) return 'moa_document';
    $q->bind_param("i", $user_id);
    $q->execute();
    $row = $q->get_result()->fetch_assoc();
    $q->close();
    return $row['requirement_type'] ?? 'moa_document';
}

/* ================= HELPER: RECOMPUTE & STORE COMPANY VALIDATION STATUS ================= */
function recomputeCompanyValidationStatus($conn, $user_id) {
    if (!$user_id) return 'Pending';

    // FIX (this adjustment) — overall validation now depends on which
    // request_type bucket this company falls into, mirroring the
    // two-table split further down in the page:
    //   - "Existing" companies are judged purely on their
    //     classification-based compliance checklist (company_profile,
    //     vision & mission, etc.) — exactly the same list and logic this
    //     page's older version always used, since these companies never
    //     go through the in-table MOA workflow at all.
    //   - "New" companies are judged on that SAME compliance checklist
    //     PLUS the MOA document requirement, since the "New" table now
    //     also surfaces and lets the admin verify those other
    //     requirements alongside the MOA workflow (previously only the
    //     MOA requirement counted here).
    //
    // ── UPDATED (this adjustment): reads the canonical
    // $private_compliance_reqs / $public_compliance_reqs lists (defined
    // once, near the top of this file) instead of a locally hardcoded
    // array, so this function always requires the exact same 10
    // private-classification items / 3 public-classification items that
    // CompanyForm.php collects and displays to the company.
    global $private_compliance_reqs, $public_compliance_reqs;

    $requestType = resolveCompanyRequestType($conn, $user_id);

    // ── UPDATED (this adjustment): resolveCompanyType() falls back to
    // users.company_type when company_information.company_type is empty
    // (the case for a manually-added/imported company) — see that
    // helper's docblock above for the full explanation.
    $isPublic = (resolveCompanyType($conn, $user_id) === 'public');
    $complianceList = $isPublic ? $public_compliance_reqs : $private_compliance_reqs;

    $clist = ($requestType === 'new')
        ? array_merge(["moa_document"], $complianceList)
        : $complianceList;

    // ── NEW (this adjustment): a company whose MOA request type is
    // ACTUALLY "Existing" (checked strictly — see isCompanyRequestTypeExisting()
    // docblock above for why this must never rely on resolveCompanyRequestType()'s
    // default-to-'existing' fallback) must also have the additive
    // "MOA Document (Existing Partnership)" item (requirement_type
    // 'moa_existing_upload') — the same item CompanyForm.php appends to
    // that company's own compliance checklist — verified before the
    // overall status can become "Verified". This is purely additive: it
    // only ever ADDS one more entry onto $clist for companies that
    // genuinely have "Existing" on record; every other company's
    // required-item list is completely unaffected.
    if (isCompanyRequestTypeExisting($conn, $user_id)) {
        $clist[] = 'moa_existing_upload';
    }

    $allVerified = true;
    foreach ($clist as $rt) {
        // ── UPDATED (this adjustment): the MOA document may be stored
        // under the legacy 'moa' key (company_register.php) instead of
        // 'moa_document' — see resolveMoaRequirementType() above.
        $lookupType = ($rt === 'moa_document') ? resolveMoaRequirementType($conn, $user_id) : $rt;
        // ── UPDATED (this adjustment): a requirement can now hold SEVERAL
        // entries — one company_requirements row per uploaded file, all
        // sharing the same requirement_type (CompanyForm.php's multi-file
        // upload). This used to read only the FIRST row (fetch_assoc()), so
        // a requirement with 2+ files was judged purely on its first file.
        // It is now Verified only when it has at least one submitted file
        // AND every submitted file (entry) is Verified. A row with no file
        // (e.g. cleared by a Denied action) is not a submitted entry. For a
        // requirement with a single row this behaves exactly as before.
        $q = $conn->prepare("SELECT status, (file_name IS NULL OR file_name = '') AS no_file FROM company_requirements WHERE user_id=? AND requirement_type=?");
        $q->bind_param("is", $user_id, $lookupType);
        $q->execute();
        $qRes = $q->get_result();
        $entryCount = 0;
        $entriesAllVerified = true;
        while ($row = $qRes->fetch_assoc()) {
            if (!empty($row['no_file'])) continue;
            $entryCount++;
            if (($row['status'] ?? '') !== 'Verified') $entriesAllVerified = false;
        }
        $q->close();
        if ($entryCount === 0 || !$entriesAllVerified) {
            $allVerified = false;
            break;
        }
    }

    $newStatus = $allVerified ? 'Verified' : 'Pending';
    $conn->query("ALTER TABLE users ADD COLUMN IF NOT EXISTS company_validation_status VARCHAR(20) DEFAULT 'Pending'");
    $upd = $conn->prepare("UPDATE users SET company_validation_status=? WHERE id=?");
    $upd->bind_param("si", $newStatus, $user_id);
    $upd->execute();
    $upd->close();
    return $newStatus;
}

/* ================= HELPER: MAX LENGTH OF A REJECTION REMARK =================
   NEW (this adjustment) — the remark an admin gives when REJECTING a requirement
   used to be picked from a short fixed list (a dropdown); it is now free text
   typed into an input. company_requirements.remark may be a short VARCHAR
   (sized for that old list), so rather than silently cutting a longer remark
   off — or altering a table that holds every company's uploaded documents —
   this reads the column's real limit once and both the input (maxlength) and
   the server-side check use it. A TEXT column (or anything unrecognised)
   allows up to 500 characters, which is this form's own limit. */
function cvRemarkMaxLen($conn) {
    static $max = null;
    if ($max !== null) return $max;
    $max = 500;
    try {
        $r = $conn->query("SHOW COLUMNS FROM company_requirements LIKE 'remark'");
        $row = $r ? $r->fetch_assoc() : null;
        if ($row) {
            $type = strtolower((string)($row['Type'] ?? ''));
            if (preg_match('/^(?:var)?char\((\d+)\)/', $type, $m)) $max = min($max, max(1, (int)$m[1]));
            elseif (strpos($type, 'tinytext') === 0)               $max = min($max, 255);
        }
    } catch (\Throwable $e) { /* keep the default */ }
    return $max;
}

/* ================= HELPER: RENDER ONE COMPANY ROW (Existing/New tables) ================= */
// NEW (this adjustment) — the full per-row markup (summary strip, MOA
// in-table workflow, regular requirement checklist items, contact info
// side panel) is UNCHANGED from before; it has simply been extracted into
// this function so both the "Existing" and "New" request-type tables
// further down the page can render a row via the exact same code path,
// passing in only the $clist of requirement keys that section cares
// about. This avoids maintaining two near-duplicate copies of this
// (fairly large) block.
//
// UPDATED (this adjustment) — the EXPANDED details-pane inside each row has
// been redesigned as "Tabs + Document Gallery" (Requirements / MOA Workflow /
// Contact Details). Only the layout changed — see the block comment at the
// top of the pane markup below for exactly what moved and what was kept.
function renderCompanyValidationRow($conn, $company, $clist, $companyReqLabels, $remarks) {
    // ── NEW (this adjustment): needed to resolve the human-readable
    // labels for a row's flagged "Needs Revision" section(s) below.
    global $moaRejectFlagLabels;
    $uid     = $company['user_id'];
    $overall = $company['company_validation_status'] ?? 'Pending';
    $dot     = ($overall === 'Verified') ? "#2C5A2C" : "#8C6C00";

    // ════════════════════════════════════════════════════════════════════
    //  NEW (this adjustment) — the "New MOA — Requirements & MOA Workflow"
    //  table's row-summary status now shows the MOA's CURRENT in-table
    //  workflow progress ("Pending for Review" / "MOA Approved" /
    //  "Scheduling" / "MOA Revision Required") instead of the plain binary
    //  Pending/Verified every row used to show — the same stage this
    //  company's own MOA card (further down, once the row is expanded)
    //  already displays, just surfaced here too so the admin can see
    //  where a company actually stands without expanding every row.
    //
    //  $clist only ever contains 'moa_document' for a row in the "New"
    //  table (see the two foreach loops further down that build
    //  $clistExisting vs $clistNew) — an "Existing" table row never has
    //  it, so this never changes that table's summary at all. Once the
    //  company is fully Verified (every requirement including the MOA
    //  itself), the summary still simply says "Verified" — the same
    //  terminal state as before; there is no "further" progress to show
    //  past that point.
    // ════════════════════════════════════════════════════════════════════
    $summaryLabel = $overall;
    $summaryColor = $dot;
    $isNewMoaRow  = in_array('moa_document', $clist, true);
    if ($isNewMoaRow && $overall !== 'Verified') {
        $moaProgressType = resolveMoaRequirementType($conn, $uid);
        $mpq = $conn->prepare("SELECT moa_workflow_stage, moa_needs_revision, status FROM company_requirements WHERE user_id=? AND requirement_type=?");
        if ($mpq) {
            $mpq->bind_param("is", $uid, $moaProgressType);
            $mpq->execute();
            $mpRow = $mpq->get_result()->fetch_assoc();
            $mpq->close();

            // ── NEW (this adjustment): once the MOA document ITSELF is
            // Verified — it's possible for that to happen while the
            // overall company is still "Pending" because some other,
            // unrelated compliance item hasn't been verified yet — the
            // MOA has no more "progress" left to show, so no stale stage
            // label is shown.
            // ── UPDATED (this adjustment): this column is the MOA's own
            // status, so a Verified MOA now says "Verified" here (see the
            // elseif below) instead of falling through to the company's
            // overall "Pending". The requirements' own status has its own
            // "Requirement Status" column now.
            if ($mpRow && ($mpRow['status'] ?? '') !== 'Verified') {
                if (!empty($mpRow['moa_needs_revision'])) {
                    $summaryLabel = 'MOA Revision Required';
                    $summaryColor = '#A02A2A';
                } else {
                    // Legacy 'reviewing' rows (written before the
                    // Pending/Reviewing stage merge) are normalized to
                    // 'pending' here too, matching every other place in
                    // this file that reads moa_workflow_stage.
                    $stageNow = $mpRow['moa_workflow_stage'] ?? 'pending';
                    if ($stageNow === 'reviewing') $stageNow = 'pending';
                    $progressMap = [
                        'pending'   => ['Pending for Review',  '#8C6C00'],
                        'approved'  => ['MOA Approved',        '#2C5A2C'],
                        'scheduled' => ['Scheduling',          '#1B2A4A'],   // UPDATED (this adjustment): was "Scheduled for Signing"
                    ];
                    $picked = $progressMap[$stageNow] ?? $progressMap['pending'];
                    $summaryLabel = $picked[0];
                    $summaryColor = $picked[1];
                }
            } elseif ($mpRow) {
                // the MOA document is Verified (see the note above)
                $summaryLabel = 'Verified';
                $summaryColor = '#2C5A2C';
            }
            // If no MOA row exists at all yet (a brand-new "New" company
            // still awaiting its very first MOA submission), $mpRow is
            // simply empty and $summaryLabel/$summaryColor fall back to
            // $overall/$dot above — i.e. "Pending", exactly as before.
        }
    }

    // ════════════════════════════════════════════════════════════════════
    //  NEW (this adjustment) — Validation Status column (now "Requirement Status"), "Existing" table
    //  rows: instead of the plain "● Pending" text the cell now shows a
    //  rounded (ring) percent-verified indicator — the straight progress
    //  bar that used to sit at the top of the Requirements tab, moved here
    //  — next to a two-line breakdown, e.g.
    //        (55%)   6 of 11 verified
    //                1 pending · 4 awaiting
    //  Only rows WITHOUT an MOA-workflow stage (the "Existing" table) get
    //  it — a "New" row's status cell is its MOA progress label instead,
    //  and its Requirements tab keeps its own progress bar.
    //  UPDATED (this adjustment): the column is now called "Requirement
    //  Status", and a "New" row gets the very same ring + breakdown in a
    //  second, separate "Requirement Status" cell (next to its MOA Status),
    //  worked out below from the compliance requirements only (the MOA
    //  document is not counted — it has its own column).
    //
    //  Counted exactly the way the Requirements tab counts each card:
    //  verified = status 'Verified'; else no file (or Denied, which clears
    //  the file) = awaiting the company; else = pending admin review.
    //
    //  The ring and text are drawn by CSS from data attributes (see
    //  .overall-status-cell[data-vpct] below) — the cell's real text is
    //  still "● Pending"/"● Verified" (just not painted), so the status
    //  filter and every live-update function that reads/writes the cell's
    //  textContent keep working untouched. cvRefreshReqSummary() keeps the
    //  attributes in step live.
    // ════════════════════════════════════════════════════════════════════
    $vsHasRing  = false;
    $vsPct      = 0;
    $vsInfoText = '';
    $vsAllVerified = false;   // NEW (this adjustment): every compliance requirement verified (a "New" row's Requirement Status cell text)
    if (true) {   // UPDATED (this adjustment): was `if (!$isNewMoaRow)` — the figures are now needed for "New" rows too
        $vsKeys  = array_values(array_filter($clist, function ($k) { return $k !== 'moa_document'; }));
        $vsTotal = count($vsKeys);
        if ($vsTotal > 0) {
            $vsRowsByType = [];
            // ── UPDATED (this adjustment): this used to keep only the FIRST
            // row seen per requirement_type. A requirement with several
            // entries (one row per uploaded file) is now folded into ONE
            // aggregate: Verified only when it has at least one submitted
            // file and every submitted file is Verified; "awaiting" when it
            // has no submitted file at all; otherwise pending. The
            // $vsRowsByType shape ('status' / 'no_file') is unchanged, so the
            // counting loop below is untouched.
            $vsAgg = [];
            $vsq = $conn->prepare("SELECT requirement_type, status, (file_name IS NULL OR file_name = '') AS no_file FROM company_requirements WHERE user_id=?");
            if ($vsq) {
                $vsq->bind_param("i", $uid);
                $vsq->execute();
                $vsRes = $vsq->get_result();
                while ($vsr = $vsRes->fetch_assoc()) {
                    $vsT = $vsr['requirement_type'];
                    if (!array_key_exists($vsT, $vsAgg)) {
                        $vsAgg[$vsT] = ['entries' => 0, 'notVerified' => 0];
                    }
                    if (empty($vsr['no_file'])) {
                        $vsAgg[$vsT]['entries']++;
                        if (($vsr['status'] ?? '') !== 'Verified') $vsAgg[$vsT]['notVerified']++;
                    }
                }
                $vsq->close();
            }
            foreach ($vsAgg as $vsT => $vsAggRow) {
                $vsRowsByType[$vsT] = [
                    'status'  => ($vsAggRow['entries'] > 0 && $vsAggRow['notVerified'] === 0) ? 'Verified' : 'Pending',
                    'no_file' => ($vsAggRow['entries'] === 0) ? 1 : 0,
                ];
            }
            $vsV = 0; $vsP = 0; $vsA = 0;
            foreach ($vsKeys as $vsKey) {
                $vsr = $vsRowsByType[$vsKey] ?? null;
                if ($vsr && ($vsr['status'] ?? '') === 'Verified') $vsV++;
                elseif (!$vsr || !empty($vsr['no_file']))          $vsA++;
                else                                               $vsP++;
            }
            $vsPct = (int)round(($vsV / $vsTotal) * 100);
            $vsAllVerified = ($vsV === $vsTotal);
            $vsLine1 = ($vsV === $vsTotal) ? ('All ' . $vsTotal . ' verified') : ($vsV . ' of ' . $vsTotal . ' verified');
            $vsRest  = [];
            if ($vsP > 0) $vsRest[] = $vsP . ' pending';
            if ($vsA > 0) $vsRest[] = $vsA . ' awaiting';
            $vsInfoText = $vsLine1 . (count($vsRest) ? "\n" . implode(' · ', $vsRest) : '');
            $vsHasRing  = true;
        }
    }
?>
            <div class="company-row" data-uid="<?= $uid ?>">
                <input type="checkbox" id="co_<?= $uid ?>" class="toggle-input" style="display:none;">
                <?php $vsOnStatusCell = ($vsHasRing && !$isNewMoaRow); /* NEW (this adjustment): the ring lives on the status cell only for an "Existing" row */ ?>
                <label for="co_<?= $uid ?>" class="row-summary<?= $isNewMoaRow ? ' cv-five-col' : '' ?>">
                    <span><?= htmlspecialchars($company['company'] ?? '') ?></span>
                    <span style="color:#3E4963;font-size:12px;"><?= htmlspecialchars($company['company_address'] ?? '') ?></span>
                    <span style="font-size:12px;color:#3E4963;text-align:center;"><?= ucfirst($company['company_type'] ?? '') ?></span>
                    <span class="overall-status-cell" style="color:<?= $summaryColor ?>;font-weight:700;<?= $vsOnStatusCell ? '--vs-pct:' . $vsPct . ';' : '' ?>"<?= $vsOnStatusCell ? ' data-vpct="' . $vsPct . '" data-vinfo="' . htmlspecialchars($vsInfoText ?? '') . '"' : '' ?>>● <?= htmlspecialchars($summaryLabel ?? '') ?></span>
                    <?php if ($isNewMoaRow): /* NEW (this adjustment): "Requirement Status" cell of a "New" row — same ring + breakdown as the "Existing" table's; its
                        real text ("● Verified" = every compliance requirement verified, else "● Pending") stays in the DOM, unpainted, like the Existing one. */ ?>
                    <span class="overall-status-cell" data-reqcell="1" style="color:<?= $vsAllVerified ? '#2C5A2C' : '#8C6C00' ?>;font-weight:700;<?= $vsHasRing ? '--vs-pct:' . $vsPct . ';' : '' ?>"<?= $vsHasRing ? ' data-vpct="' . $vsPct . '" data-vinfo="' . htmlspecialchars($vsInfoText ?? '') . '"' : '' ?>>● <?= $vsAllVerified ? 'Verified' : 'Pending' ?></span>
                    <?php endif; ?>
                </label>


                <div class="details-pane">
                    <?php
                    // ════════════════════════════════════════════════════════════════════
                    //  NEW (this adjustment) — EXTENDED-PANEL REDESIGN: "TABS + DOCUMENT
                    //  GALLERY". What the admin sees when a company row is expanded used
                    //  to be one long vertical stack of identical requirement cards (the
                    //  MOA card first, then every compliance item) with a separate
                    //  "Contact Details" card beside it. It is now three tabs:
                    //
                    //    1. Requirements    — every compliance item as a document card in
                    //                         a responsive gallery (large preview, status
                    //                         ribbon, and the same status / remark / Save
                    //                         form underneath), plus a live summary line
                    //                         and progress bar.
                    //    2. MOA Workflow    — ("New" table rows only) the unchanged in-table
                    //                         MOA card: stepper, email comment, signing
                    //                         schedule + confirmation, action buttons.
                    //    3. Company Details — ONE card: the contact fields (Representative
                    //                         shows the FULL name incl. middle name, matching
                    //                         the MOA request inbox) with the company's
                    //                         Company Profile / Brief Description beneath.
                    //
                    //  LAYOUT ONLY. Nothing about HOW requirements are looked up, saved,
                    //  verified, denied, undone or workflow-advanced has changed: every
                    //  class name, id, data-attribute, form field name and inline-handler
                    //  the existing JavaScript relies on is preserved exactly (.req-item,
                    //  .moa-req-item, .moa-req-inner, .moa-req-workflow-section,
                    //  #moaTblStepper_/#moaComment_/#moaWfActions_/… ids, .verified-badge,
                    //  .req-lock-ui, .req-awaiting-ui, .ajax-req-form, .req-save-feedback,
                    //  .info-side p, and the `flex:1` info container the live-update code
                    //  appends into). The only additions are the tab bar, the summary
                    //  strip, and a data-state attribute on each gallery card.
                    // ════════════════════════════════════════════════════════════════════
                    $cvHasMoa  = in_array('moa_document', $clist, true);
                    $cvRegKeys = array_values(array_filter($clist, function ($k) { return $k !== 'moa_document'; }));

                    // Same query the per-item loop always used, just hoisted so the tab
                    // counts / chips can be computed BEFORE the markup is emitted.
                    $cvSelectSql = "SELECT file_name,status,remark,moa_workflow_stage,moa_admin_comment,moa_schedule_datetime,moa_needs_revision,moa_flagged_fields,moa_revision_comment,moa_complied_detail,moa_schedule_status,moa_schedule_decline_reason,moa_proposed_datetime FROM company_requirements WHERE user_id=? AND requirement_type=?";
                    $cvFetch = function ($key) use ($conn, $uid, $cvSelectSql) {
                        $st = $conn->prepare($cvSelectSql);
                        $st->bind_param("is", $uid, $key);
                        $st->execute();
                        $row = $st->get_result()->fetch_assoc();
                        $st->close();
                        return $row;
                    };

                    // ── NEW (this adjustment): MULTIPLE-FILE REQUIREMENTS. When a company uploads
                    // several files for one requirement (CompanyForm.php's multi-file input), each
                    // file is saved as its OWN company_requirements row, all sharing the same
                    // requirement_type. $cvFetch() above only ever returned the first of them, so
                    // the gallery could never show the rest. This returns EVERY entry (one per
                    // file, oldest first) with its id, status, remark and whether it is a PDF.
                    // Only the first 2 KB of each file is read (enough to identify the type), so
                    // the full documents are never loaded into PHP just to draw a card — they are
                    // streamed on demand by the stream_req_blob endpoint (now file_id-aware).
                    // Rows with no file (e.g. cleared by a Denied action) are not entries.
                    $cvFinfo = new finfo(FILEINFO_MIME_TYPE);
                    $cvFetchEntries = function ($key) use ($conn, $uid, $cvFinfo) {
                        $entries = [];
                        $st = $conn->prepare("SELECT id, status, remark, LEFT(file_name, 2048) AS head FROM company_requirements WHERE user_id=? AND requirement_type=? AND file_name IS NOT NULL AND file_name <> '' ORDER BY id ASC");
                        if (!$st) return $entries;
                        $st->bind_param("is", $uid, $key);
                        $st->execute();
                        $rsE = $st->get_result();
                        while ($er = $rsE->fetch_assoc()) {
                            $mimeE = (string) $cvFinfo->buffer((string) $er['head']);
                            $entries[] = [
                                'id'     => (int) $er['id'],
                                'status' => $er['status'] ?? 'Pending',
                                'remark' => $er['remark'] ?? '',
                                // same rule the MOA card / CompanyForm.php use to treat a file as a PDF
                                'isPdf'  => (strpos($mimeE, 'pdf') !== false || strpos($mimeE, 'octet') !== false),
                            ];
                        }
                        $st->close();
                        return $entries;
                    };

                    // ── NEW (this adjustment): REJECTED requirements. When the admin rejects a requirement, its
                    // file(s) are removed and every row is left with status 'Rejected' (older rows say 'Denied' —
                    // still treated as rejected) plus the remark. Such a requirement has no file entries, so on its
                    // own it looked like a plain "awaiting the company" card; this returns the remark when the
                    // requirement is in that rejected state (or null when it is not) so the card can say so.
                    $cvFetchRejection = function ($key) use ($conn, $uid) {
                        $st = $conn->prepare("SELECT remark FROM company_requirements WHERE user_id=? AND requirement_type=? AND status IN ('Rejected','Denied') ORDER BY id ASC LIMIT 1");
                        if (!$st) return null;
                        $st->bind_param("is", $uid, $key);
                        $st->execute();
                        $rj = $st->get_result()->fetch_assoc();
                        $st->close();
                        return $rj ? (string)($rj['remark'] ?? '') : null;
                    };

                    // ── MOA document (New-table rows only). Logic below is the same as the
                    // old per-item block: resolve the stored key ('moa_document' or legacy
                    // 'moa'), normalize the legacy 'reviewing' stage to 'pending', and read
                    // the flagged-for-revision fields.
                    $moaRes = null; $moaHasNoFile = true; $moaIsVerified = false;
                    $moaStage = 'pending'; $moaNeedsRevision = false; $moaFlaggedFieldsList = [];
                    $moaTabChip = ['awaiting', 'Awaiting'];
                    if ($cvHasMoa) {
                        $moaRes           = $cvFetch(resolveMoaRequirementType($conn, $uid));
                        $moaIsVerified    = (!empty($moaRes['status']) && $moaRes['status'] === 'Verified');
                        $moaHasNoFile     = (empty($moaRes) || empty($moaRes['file_name']));
                        $moaStage         = $moaRes['moa_workflow_stage'] ?? 'pending';
                        if ($moaStage === 'reviewing') $moaStage = 'pending';
                        $moaNeedsRevision = !empty($moaRes['moa_needs_revision']);
                        if ($moaNeedsRevision && !empty($moaRes['moa_flagged_fields'])) {
                            $decodedFlags = json_decode($moaRes['moa_flagged_fields'], true);
                            if (is_array($decodedFlags)) $moaFlaggedFieldsList = $decodedFlags;
                        }
                        // Short status chip shown on the "MOA Workflow" tab itself.
                        if ($moaIsVerified)                 $moaTabChip = ['verified',  ''];   // UPDATED (this adjustment): no "Verified" chip on the tab any more — the MOA Status column, the verified bar in the panel and the stepper already say so
                        elseif ($moaHasNoFile)              $moaTabChip = ['awaiting',  'Awaiting'];
                        elseif ($moaNeedsRevision)          $moaTabChip = ['revision',  ''];   // UPDATED (this adjustment): no "Needs revision" chip on the tab any more — the status column and the flagged-for-revision note already say so
                        // UPDATED (this adjustment): the tab no longer carries a chip for the workflow STAGES either —
                        // the Pending-for-Review and Approved chips are gone, exactly like "Scheduled" already was:
                        // the stepper in the MOA panel shows the stage, so the panels now all look the same. What the tab
                        // still flags is the state the stepper does NOT cover: Awaiting (the "Needs revision" chip is gone as
                        // well — see the line above — and so is the "Verified" chip: the MOA Status column already says it).
                        elseif ($moaStage === 'approved')   $moaTabChip = ['approved',  ''];
                        elseif ($moaStage === 'scheduled')  $moaTabChip = ['scheduled', ''];   // no chip at this stage — the stepper already says it
                        else                                $moaTabChip = ['review',    ''];
                    }

                    // ── Regular compliance items -> gallery cards. State precedence matches
                    // the original card exactly: Verified (locked) wins, else no file =
                    // awaiting the company, else it has a file the admin can act on.
                    $cvCards = [];
                    $cvN = ['verified' => 0, 'pending' => 0, 'awaiting' => 0];
                    foreach ($cvRegKeys as $cvKey) {
                        // ── UPDATED (this adjustment): the card is now built from EVERY entry saved
                        // under this requirement (see $cvFetchEntries above) instead of only the first
                        // row. Aggregate status: Verified only when every entry is Verified; a Denied
                        // entry wins next; otherwise Pending. 'res' keeps the same 'status' / 'remark'
                        // keys the Status / Remark form below already reads (remark = the first Denied
                        // entry's remark, else the first entry's).
                        $cvEntries   = $cvFetchEntries($cvKey);
                        $cvNoFile    = empty($cvEntries);
                        $cvAggStatus = 'Pending';
                        $cvAggRemark = '';
                        if (!$cvNoFile) {
                            $cvAllVerified = true; $cvAnyDenied = false;
                            foreach ($cvEntries as $cvEn) {
                                if ($cvEn['status'] !== 'Verified') $cvAllVerified = false;
                                if (in_array($cvEn['status'], ['Rejected', 'Denied'], true)) {
                                    $cvAnyDenied = true;
                                    if ($cvAggRemark === '' && $cvEn['remark'] !== '') $cvAggRemark = $cvEn['remark'];
                                }
                            }
                            $cvAggStatus = $cvAllVerified ? 'Verified' : ($cvAnyDenied ? 'Rejected' : 'Pending');
                            if ($cvAggRemark === '') $cvAggRemark = $cvEntries[0]['remark'];
                        }
                        $cvRow      = ['status' => $cvAggStatus, 'remark' => $cvAggRemark];
                        $cvVerified = (!$cvNoFile && $cvAggStatus === 'Verified');
                        $cvState    = $cvVerified ? 'verified' : ($cvNoFile ? 'awaiting' : 'pending');
                        $cvN[$cvState]++;
                        // NEW (this adjustment): a requirement with no file that was REJECTED. Its data-state stays
                        // 'awaiting' (it IS awaiting the company's new upload, so every count / ring that counts
                        // awaiting items is unchanged); 'rejected' + 'rejectRemark' only drive how the card looks.
                        $cvRejectRemark = $cvNoFile ? $cvFetchRejection($cvKey) : null;
                        $cvCards[]  = ['rt' => $cvKey, 'res' => $cvRow, 'verified' => $cvVerified, 'noFile' => $cvNoFile, 'state' => $cvState, 'entries' => $cvEntries, 'rejected' => ($cvRejectRemark !== null), 'rejectRemark' => (string)$cvRejectRemark];
                    }
                    $cvTotal = count($cvCards);
                    $cvPct   = $cvTotal > 0 ? (int)round(($cvN['verified'] / $cvTotal) * 100) : 0;
                    ?>
                    <div class="cv-panel" data-uid="<?= $uid ?>">

                        <!-- ═══ TAB BAR ═══ -->
                        <div class="cv-tabs" role="tablist" aria-label="Company details sections">
                            <button type="button" class="cv-tab active" data-tab="req" role="tab" aria-selected="true" onclick="cvSelectTab(this)">
                                Requirements <span class="cv-tab-count"><?= $cvN['verified'] ?> / <?= $cvTotal ?></span>
                            </button>
                            <?php if ($cvHasMoa): ?>
                            <button type="button" class="cv-tab" data-tab="moa" role="tab" aria-selected="false" onclick="cvSelectTab(this)">
                                MOA Workflow <span class="cv-tab-chip cv-moa-tab-chip <?= $moaTabChip[0] ?>"<?= $moaTabChip[1] === '' ? ' style="display:none;"' : '' ?>><?= $moaTabChip[1] ?></span>
                            </button>
                            <?php endif; ?>
                            <button type="button" class="cv-tab" data-tab="contact" role="tab" aria-selected="false" onclick="cvSelectTab(this)">Company Details</button>
                        </div>

                        <!-- ═══ TAB 1: REQUIREMENTS — document gallery ═══ -->
                        <div class="cv-tabpanel active" data-tabpanel="req" role="tabpanel">
                            <div class="cv-req-summary">
                                <span class="cv-req-summary-text"><b class="cv-n-total"><?= $cvTotal ?></b> requirements &middot; <b class="cv-n-verified"><?= $cvN['verified'] ?></b> verified &middot; <b class="cv-n-pending"><?= $cvN['pending'] ?></b> pending &middot; <b class="cv-n-awaiting"><?= $cvN['awaiting'] ?></b> awaiting the company</span>
                                <?php if ($cvHasMoa): /* NEW (this adjustment): "Existing" rows show this progress as the rounded ring in the Requirement Status column (UPDATED: "New" rows have that column too now) instead; the bar stays here. */ ?>
                                <div class="cv-progress">
                                    <div class="cv-progress-bar"><div class="cv-progress-fill" style="width:<?= $cvPct ?>%;"></div></div>
                                    <span class="cv-progress-pct"><?= $cvPct ?>% verified</span>
                                </div>
                                <?php endif; ?>
                            </div>
                            <div class="cv-gallery">
                                <?php foreach ($cvCards as $card):
                                    $rt            = $card['rt'];
                                    $res           = $card['res'];
                                    $isReqVerified = $card['verified'];
                                    $showLock      = $isReqVerified;
                                    $hasNoFileReg  = $card['noFile'];
                                ?>
                                <div class="req-item cv-req-card" data-uid="<?= $uid ?>" data-req="<?= $rt ?>" data-state="<?= $card['state'] ?>" data-req-sig="<?= htmlspecialchars(implode(',', array_column($card['entries'], 'id')), ENT_QUOTES) ?>" data-rejected="<?= !empty($card['rejected']) ? '1' : '0' ?>">
                                    <!-- Preview area: the submitted document (click to enlarge), with its status pill in the top-right corner. -->
                                    <?php
                                    // ── NEW (this adjustment): MULTIPLE-FILE DISPLAY + PDF PREVIEW, ported from
                                    // CompanyForm.php's requirement cards. Every entry (file) saved under this
                                    // requirement is shown, not just the first:
                                    //   • 1 image  -> the thumbnail, streamed by URL (no more base64-embedding
                                    //                 the whole file into the page — and a PDF no longer shows
                                    //                 up as a broken image);
                                    //   • 1 PDF    -> a PDF tile;
                                    //   • 2+ files -> ONE overlaying "stacked card" with a count badge.
                                    // Clicking any of them opens the preview modal (#cvReqDocPreviewModal), which
                                    // renders a PDF in an iframe / an image in the viewer and pages through every
                                    // file with prev / next. The trigger elements carry data-* attributes read by
                                    // the delegated click handler in the script below (so it also works for rows
                                    // inserted live by liveInsertOrUpdateCompanyRow()).
                                    $cvEnts      = $card['entries'];
                                    $cvEntCount  = count($cvEnts);
                                    $cvReqLabel  = $companyReqLabels[$rt] ?? $rt;
                                    $cvEntMeta   = [];
                                    foreach ($cvEnts as $cvEnX) { $cvEntMeta[] = ['id' => $cvEnX['id'], 'isPdf' => $cvEnX['isPdf']]; }
                                    $cvStreamBase = htmlspecialchars('company_validation.php?stream_req_blob=' . (int) $uid . '&req_type=' . urlencode($rt), ENT_QUOTES);
                                    $cvTrigAttrs  = 'data-uid="' . (int) $uid . '" data-req-key="' . htmlspecialchars($rt ?? '', ENT_QUOTES) . '" data-req-files="' . htmlspecialchars(json_encode($cvEntMeta), ENT_QUOTES) . '" data-req-label="' . htmlspecialchars($cvReqLabel ?? '', ENT_QUOTES) . '"';
                                    ?>
                                    <div class="cv-card-preview">
                                        <?php if ($cvEntCount === 1): ?>
                                            <?php if ($cvEnts[0]['isPdf']): ?>
                                                <div class="cv-pdf-tile cv-preview-trigger" <?= $cvTrigAttrs ?> title="Preview <?= htmlspecialchars($cvReqLabel ?? '') ?>"><i class="fas fa-file-pdf"></i><span>PDF document</span></div>
                                            <?php else: ?>
                                                <!-- If this file can no longer be streamed back, swap the broken image for the same neutral tile used when there is no file (self-contained onerror, same technique as CompanyForm.php). -->
                                                <img class="cv-thumb-img cv-preview-trigger" <?= $cvTrigAttrs ?> src="<?= $cvStreamBase ?>&amp;file_id=<?= (int) $cvEnts[0]['id'] ?>" alt="" title="Click to preview"
                                                    onerror="this.onerror=null;var d=document.createElement('div');d.className='cv-no-file';d.setAttribute('aria-hidden','true');var ic=document.createElement('i');ic.className='fas fa-file-circle-xmark';var sp=document.createElement('span');sp.textContent='Preview unavailable';d.appendChild(ic);d.appendChild(sp);if(this.parentNode){this.parentNode.replaceChild(d,this);}">
                                            <?php endif; ?>
                                        <?php elseif ($cvEntCount > 1): ?>
                                            <div class="cv-file-stack-wrap cv-preview-trigger" <?= $cvTrigAttrs ?> title="Preview all <?= (int) $cvEntCount ?> files for <?= htmlspecialchars($cvReqLabel ?? '') ?>">
                                                <div class="cv-file-stack">
                                                    <?php
                                                    $cvLayerCount = min(3, $cvEntCount);
                                                    for ($cvLi = $cvLayerCount - 1; $cvLi >= 0; $cvLi--):
                                                        $cvLayerMeta = $cvEnts[$cvLi];
                                                    ?>
                                                    <?php if ($cvLayerMeta['isPdf']): ?>
                                                        <div class="cv-stack-layer cv-stack-layer-pdf layer-<?= $cvLi + 1 ?>"><i class="fas fa-file-pdf"></i></div>
                                                    <?php else: ?>
                                                        <div class="cv-stack-layer layer-<?= $cvLi + 1 ?>"><img src="<?= $cvStreamBase ?>&amp;file_id=<?= (int) $cvLayerMeta['id'] ?>" alt="" onerror="this.onerror=null;var p=this.parentNode;if(p){p.classList.add('cv-stack-layer-missing');var i=document.createElement('i');i.className='fas fa-file-circle-xmark';p.replaceChild(i,this);}"></div>
                                                    <?php endif; ?>
                                                    <?php endfor; ?>
                                                    <span class="cv-stack-count-badge"><?= (int) $cvEntCount ?></span>
                                                </div>
                                                <div class="cv-file-stack-label"><?= (int) $cvEntCount ?> files</div>
                                            </div>
                                        <?php else: ?>
                                            <div class="cv-no-file"><i class="fas fa-hourglass-half"></i><span>No file yet</span></div>
                                        <?php endif; ?>
                                        <!-- UPDATED (this adjustment): the status pill (Verified / Pending / Awaiting) now sits in the TOP-RIGHT corner of the file preview instead of beside the requirement name below. Same three spans, same classes: .verified-badge is still toggled by updateReqItemUI(), and the pending / awaiting pills still follow data-state via CSS. -->
                                        <div class="cv-card-ribbon">
                                            <span class="verified-badge cv-rb cv-rb-verified"<?= $isReqVerified ? '' : ' style="display:none;"' ?>><i class="fas fa-check"></i> Verified</span>
                                            <span class="cv-rb cv-rb-pending">Pending</span>
                                            <span class="cv-rb cv-rb-awaiting">Awaiting</span>
                                            <span class="cv-rb cv-rb-rejected"><i class="fas fa-ban"></i> Rejected</span>
                                        </div>
                                        <!-- NEW (this adjustment): shown INSTEAD of the file preview once the requirement is rejected (data-rejected="1",
                                             set by the server for a rejected requirement and by updateReqItemUI() the moment the admin rejects one) — the
                                             previously uploaded file is no longer displayed. Hidden for every other card. -->
                                        <div class="cv-rej-placeholder"><i class="fas fa-file-circle-xmark"></i><span>Rejected<br>Awaiting re-upload</span></div>
                                    </div>

                                    <div class="cv-card-body" style="flex:1;">
                                        <div class="cv-card-label req-label-row">
                                            <?= $companyReqLabels[$rt] ?>
                                            <span class="req-save-feedback"></span>
                                        </div>
                                        <!-- NEW (this adjustment): the rejection remark sits directly BELOW the requirement name (shown only while data-rejected="1"). -->
                                        <div class="cv-card-remark"><i class="fas fa-comment-dots"></i><span><b>Remark:</b> <span class="cv-card-remark-text"><?= htmlspecialchars(($card['rejectRemark'] ?? '') !== '' ? $card['rejectRemark'] : '—') ?></span></span></div>
                                        <?php /* NEW (this adjustment): the "Already verified — no further changes allowed" and "Awaiting company submission" labels are gone — the status icon beside the name says it. Only a card with a file that is not yet verified has a form. */ ?>
                                        <?php if(!$showLock && !$hasNoFileReg): ?>
                                            <form class="update-form ajax-req-form" data-uid="<?= $uid ?>" data-req="<?= $rt ?>"<?= $cvEntCount > 1 ? ' title="This status applies to all ' . (int) $cvEntCount . ' files of this requirement"' : '' ?>>
                                                <input type="hidden" name="user_id" value="<?= $uid ?>">
                                                <input type="hidden" name="requirement_type" value="<?= $rt ?>">
                                                <select name="status" onchange="toggleRemark(this,'rem_<?= $uid.$rt ?>')">
                                                    <option value="Pending" <?= ($res['status']=="Pending")?"selected":"" ?>>Pending</option>
                                                    <option value="Verified" <?= ($res['status']=="Verified")?"selected":"" ?>>Verified</option>
                                                    <option value="Rejected" <?= (in_array($res['status'], ['Rejected','Denied'], true))?"selected":"" ?>>Rejected</option>
                                                </select>
                                                <!-- UPDATED (this adjustment): the rejection remark is free text (it used to be a dropdown of preset reasons) and is now a
                                                     multi-line, SCROLLABLE box: the text wraps inside it and a vertical scrollbar appears when it runs past three
                                                     lines, so a long remark can be read and edited in place instead of scrolling sideways along one line.
                                                     It only appears while "Rejected" is selected, and is required then. -->
                                                <textarea name="remark" id="rem_<?= $uid.$rt ?>" rows="3" maxlength="<?= (int) cvRemarkMaxLen($conn) ?>" autocomplete="off" placeholder="Remark (reason for rejection)" style="<?= in_array($res['status'], ['Rejected','Denied'], true) ? '' : 'display:none' ?>"><?= in_array($res['status'], ['Rejected','Denied'], true) ? htmlspecialchars($res['remark'] ?? '') : '' ?></textarea>
                                                <button type="submit">Save</button>
                                            </form>
                                        <?php endif; ?>
                                    </div>
                                </div>
                                <?php endforeach; ?>
                            </div>
                        </div>
                        <?php if ($cvHasMoa): ?>
                        <!-- ═══ TAB 2: MOA WORKFLOW — the unchanged in-table MOA card ═══ -->
                        <div class="cv-tabpanel cv-moa-panel" data-tabpanel="moa" role="tabpanel">
                            <div class="req-item moa-req-item<?= (!$moaHasNoFile && !$moaIsVerified && $moaStage==='scheduled' && !empty($moaRes['moa_schedule_datetime'])) ? ' moa-sched-top-right' : '' ?>" data-uid="<?= $uid ?>" data-req="moa_document">
                                <!-- ═══ MOA DOCUMENT — full in-table workflow ═══ -->
                                <div class="moa-req-inner<?= $moaIsVerified ? ' moa-verified-row' : '' ?>">
                                    <!-- Thumbnail -->
                                    <?php if (!$moaHasNoFile): ?>
                                        <?php
                                        $finfo_c=new finfo(FILEINFO_MIME_TYPE);
                                        $mime_c=$finfo_c->buffer($moaRes['file_name']);
                                        $isPdf=(strpos($mime_c,'pdf')!==false||strpos($mime_c,'octet')!==false);
                                        $reqBlobUrl="company_validation.php?stream_req_blob={$uid}&req_type=moa_document";
                                        ?>
                                        <?php if($isPdf): ?>
                                            <div class="moa-thumb-wrap" onclick="openReqBlobModal(<?= $uid ?>,'MOA Document')" title="Preview MOA Document"><i class="fas fa-file-pdf"></i></div>
                                        <?php else: ?>
                                            <img class="moa-thumb-img" src="<?= $reqBlobUrl ?>" onclick="openReqBlobModalImg('<?= $reqBlobUrl ?>','MOA Document')" title="Preview">
                                        <?php endif; ?>
                                    <?php else: ?>
                                        <div style="width:50px;height:50px;background:#E4EAF4;display:flex;align-items:center;justify-content:center;font-size:18px;border-radius:0;color:#66718D;flex-shrink:0;" title="No MOA document yet"><i class="fas fa-file-signature"></i></div>
                                    <?php endif; ?>

                                    <div style="flex:1;min-width:0;">
                                        <div style="font-weight:700;font-size:13px;color:#1B2A4A;margin-bottom:4px;display:flex;align-items:center;gap:8px;">
                                            MOA Document
                                            <span class="req-save-feedback moa-save-fb"></span>
                                        </div>
                                        <?php if(!$moaHasNoFile): ?>
                                        <!-- UPDATED (this adjustment): the small "eye" Preview MOA Document button that used to sit beside the
                                             title is gone. The document thumbnail on the left already opens the viewer when clicked, so a short
                                             hint under the title says so instead. -->
                                        <div class="moa-doc-click-hint">(Click the <?= !empty($isPdf) ? 'PDF' : 'image' ?> to view the MOA)</div>
                                        <?php endif; ?>
                                        <?php if($moaIsVerified): ?>
                                            <!-- UPDATED (this adjustment): the "MOA Document Verified — Signing Scheduled" bar no longer sits under the title inside this column —
                                                 it is drawn right after this block (below), so it lines up with the MOA Document block on one horizontal line. -->
                                        <?php elseif($moaHasNoFile): ?>
                                            <div class="req-awaiting-ui"><i class="fas fa-file-signature"></i> Awaiting MOA — this fills in automatically as soon as the company submits an MOA request</div>
                                        <?php else: ?>
                                            <!-- UPDATED (this adjustment): the stage chip that used to sit under the "MOA Document" title
                                                 ("Pending for Review" / "Approved / Signing Schedule Pending") is gone for EVERY stage, so all the
                                                 MOA panels now look alike — the "Scheduled" panel already had none, and the stepper below is what
                                                 shows the stage. The red "Needs Revision" chip that could still appear here is gone too: it only
                                                 repeated what the "MOA Revision Required" status and the "Flagged for revision — waiting on the
                                                 company" note (which lists the flagged section(s)) already say. -->
                                        <?php endif; ?>
                                    </div>
                                    <?php if($moaIsVerified): ?>
                                        <!-- NEW (this adjustment): the verified bar, on the same row as the thumbnail + MOA Document title (see .moa-verified-row). -->
                                        <div class="verified-lock">
                                            <i class="fas fa-check-circle"></i> MOA Document Verified — Signing Scheduled ✓
                                            <?php if(!empty($moaRes['moa_schedule_datetime'])): ?>
                                                <strong style="margin-left:4px;">(<?= date('M d, Y g:i A', strtotime($moaRes['moa_schedule_datetime'] ?? '')) ?>)</strong>
                                            <?php endif; ?>
                                        </div>
                                    <?php endif; ?>

                                </div>

                                <?php if($moaNeedsRevision && !$moaHasNoFile && !$moaIsVerified): ?>
                                <!-- ── NEW (this adjustment): shows exactly which section(s) were
                                     flagged, plus the admin's note, right on the row while it stays
                                     in the merged "Pending for Review" stage — this is what "the MOA
                                     of the user is now being flagged for the section that need
                                     revision" refers to. Clears automatically once the company
                                     resubmits (autoIngestPendingMoaRequests() resets these columns). -->
                                <div class="moa-needs-revision-note" style="margin:0 14px 10px;padding:10px 12px;background:#F2D5D1;border:1px solid #D49A94;border-radius:0;font-size:12px;color:#A02A2A;">
                                    <div style="font-weight:700;margin-bottom:4px;"><i class="fas fa-flag"></i> Flagged for revision — waiting on the company</div>
                                    <?php if(!empty($moaFlaggedFieldsList)): ?>
                                        <div>Section(s): <strong><?= htmlspecialchars(implode(', ', array_map(function($k) use ($moaRejectFlagLabels){ return $moaRejectFlagLabels[$k] ?? $k; }, $moaFlaggedFieldsList))) ?></strong></div>
                                    <?php endif; ?>
                                    <?php if(!empty($moaRes['moa_revision_comment'])): ?>
                                        <div style="margin-top:4px;color:#7A1F1F;">"<?= htmlspecialchars($moaRes['moa_revision_comment'] ?? '') ?>"</div>
                                    <?php endif; ?>
                                </div>
                                <?php endif; ?>

                                <?php
                                // ── NEW (this adjustment): once the company has complied with a "Flag for Revision", say WHAT
                                // it updated right on the MOA card (the same detail the Review MOA preview shows), for as long
                                // as the MOA is waiting for the admin's review. Cleared as soon as the admin acts on the MOA.
                                $moaComplied = null;
                                if (!empty($moaRes['moa_complied_detail']) && !$moaNeedsRevision && !$moaHasNoFile && !$moaIsVerified && $moaStage === 'pending') {
                                    $mcd = json_decode($moaRes['moa_complied_detail'], true);
                                    if (is_array($mcd) && !empty($mcd['fields'])) $moaComplied = $mcd;
                                }
                                ?>
                                <?php if(!empty($moaComplied)): ?>
                                <div class="moa-complied-note" style="margin:0 14px 10px;padding:10px 12px;background:#D9E8D2;border:1px solid #9DC08F;border-radius:0;font-size:12px;color:#1F421F;">
                                    <div style="font-weight:700;margin-bottom:4px;"><i class="fas fa-check-double"></i> Revision complied — the company updated the flagged section(s)</div>
                                    <?php
                                    // ── NEW (layout adjustment): the updated sections are laid out in the SAME rows as the company
                                    // form and the Review MOA preview (Contact name(s) / Position / Telephone, then Company Name /
                                    // Company Address, then Company Profile) instead of one stacked list. Only the sections the
                                    // company actually updated are shown; up to 3 columns per row (4 fields split 2 + 2 so there is
                                    // never a lone orphan cell). A section outside that layout (e.g. "moa_document") keeps the old
                                    // stacked look in a final single-column row. Same label / value / "—" fallback as before.
                                    $mcnRows = [
                                        ['contact_first_name', 'contact_middle_name', 'contact_last_name', 'position', 'telephone'],
                                        ['company_name', 'company_address'],
                                        ['company_profile'],
                                    ];
                                    $mcnGroups = [];
                                    $mcnLaidOut = [];
                                    foreach ($mcnRows as $mcnKeys) {
                                        $mcnItems = [];
                                        foreach ($mcnKeys as $mcnKey) {
                                            $mcnLaidOut[$mcnKey] = true;
                                            foreach ($moaComplied['fields'] as $mcnF) {
                                                if (is_array($mcnF) && (string)($mcnF['key'] ?? '') === $mcnKey) $mcnItems[] = $mcnF;
                                            }
                                        }
                                        if (!$mcnItems) continue;
                                        $mcnCount = count($mcnItems);
                                        $mcnGroups[] = ['cols' => ($mcnCount <= 3 ? $mcnCount : ($mcnCount === 4 ? 2 : 3)), 'items' => $mcnItems];
                                    }
                                    $mcnExtras = [];
                                    foreach ($moaComplied['fields'] as $mcnF) {
                                        if (is_array($mcnF) && !isset($mcnLaidOut[(string)($mcnF['key'] ?? '')])) $mcnExtras[] = $mcnF;
                                    }
                                    if ($mcnExtras) $mcnGroups[] = ['cols' => 1, 'items' => $mcnExtras];
                                    ?>
                                    <div class="mcp-rows">
                                    <?php foreach($mcnGroups as $mcnGroup): ?>
                                        <div class="mcp-grid cols-<?= (int)$mcnGroup['cols'] ?>">
                                            <?php foreach($mcnGroup['items'] as $mcf): ?>
                                            <div class="mcp-item">
                                                <div class="mcp-label"><?= htmlspecialchars($mcf['label'] ?? ($mcf['key'] ?? '')) ?></div>
                                                <div class="mcp-value"><?= htmlspecialchars(($mcf['value'] ?? '') !== '' ? $mcf['value'] : '—') ?></div>
                                            </div>
                                            <?php endforeach; ?>
                                        </div>
                                    <?php endforeach; ?>
                                    </div>
                                    <div style="margin-top:6px;color:#2C5A2C;">Open <strong>Review MOA</strong> to check these against the regenerated document.</div>
                                </div>
                                <?php endif; ?>

                                <?php if(!$moaHasNoFile&&!$moaIsVerified): ?>
                                <!-- Workflow stepper + comment + schedule + action buttons -->
                                <div class="moa-req-workflow-section">
                                    <!-- Stepper — 3 steps (this adjustment merges Pending + Reviewing
                                         into one "Pending for Review" step) -->
                                    <div class="moa-tbl-stepper" id="moaTblStepper_<?= $uid ?>">
                                        <?php
                                        // ── UPDATED (this adjustment): the old 4-step stepper
                                        // (Pending, Reviewing, Approved, Sched. Signing) is now 3 steps —
                                        // "Pending for Review" covers what used to be two separate steps.
                                        $tblSteps=[
                                            ['key'=>'pending',   'label'=>'Pending for Review', 'icon'=>'1'],
                                            ['key'=>'approved',  'label'=>'Approved',            'icon'=>'2'],
                                            // UPDATED (this adjustment): step 3 shows its number ("3") like steps 1 and 2 — it used to
                                            // show a check mark from the very start, which read as already complete. Once the MOA is in this
                                            // stage it becomes a rotating hourglass (schedule not finalized) and then a check mark (finalized) — see below.
                                            ['key'=>'scheduled', 'label'=>'Scheduling',         'icon'=>'3'],   // UPDATED (this adjustment): was "Sched. Signing"
                                        ];
                                        $currentIdx=array_search($moaStage,array_column($tblSteps,'key'));
                                        foreach($tblSteps as $si=>$s):
                                            $cls='';
                                            if($si<$currentIdx) $cls='done';
                                            elseif($si===$currentIdx) $cls='active';
                                            $dotContent=($cls==='done')?'<i class="fas fa-check" style="font-size:10px;"></i>':htmlspecialchars($s['icon'] ?? '');
                                            // NEW (this adjustment): once the MOA is in the "Scheduling" stage, its dot no longer shows the
                                            // a check mark straight away — while the signing schedule is NOT finalized yet (still awaiting the company's
                                            // answer, or the company proposed a different date that hasn't been accepted) it shows a rotating
                                            // hourglass instead, and only becomes a check mark once the schedule is finalized (confirmed by the company,
                                            // or accepted by the admin). Same rule as the Done button's lock (see moaScheduleIsFinal() in the JS).
                                            $dotTitle = '';
                                            if ($s['key'] === 'scheduled' && $cls === 'active') {
                                                $stepSchedFinal = in_array((($moaRes['moa_schedule_status'] ?? '') ?: 'pending_confirmation'), ['confirmed', 'confirmed_by_admin'], true);
                                                if (!$stepSchedFinal) {
                                                    $dotContent = '<i class="fas fa-hourglass-half moa-dot-hourglass"></i>';
                                                    $dotTitle   = ' title="Waiting for the signing schedule to be finalized"';
                                                } else {
                                                    // UPDATED (this adjustment): the step's own icon is now "3", so the finalized state has to draw its a check mark itself.
                                                    $dotContent = htmlspecialchars($s['icon'] ?? '');   // UPDATED (this adjustment — no emoji): the finalized dot shows its own number
                                                }
                                            }
                                        ?>
                                        <div class="moa-tbl-step <?= $cls ?>">
                                            <div class="moa-tbl-dot"<?= $dotTitle ?>><?= $dotContent ?></div>
                                            <div class="moa-tbl-label"><?= htmlspecialchars($s['label'] ?? '') ?></div>
                                        </div>
                                        <?php endforeach; ?>
                                    </div>

                                    <!-- Admin comment — attached to the automatic email sent on every stage update -->
                                    <div class="moa-comment-section" id="moaCommentSection_<?= $uid ?>" style="margin-bottom:8px;">
                                        <span class="moa-comment-label"><i class="fas fa-comment-dots"></i> Comment to include in email (optional)</span>
                                        <textarea class="moa-comment-input" id="moaComment_<?= $uid ?>" rows="2" placeholder="e.g. Please prepare 2 signed copies of the MOA on the day of signing." style="width:100%;box-sizing:border-box;padding:8px 10px;border:1px solid #A3AFC7;border-radius:0;font-size:12px;font-family:inherit;resize:vertical;"><?= htmlspecialchars($moaRes['moa_admin_comment'] ?? '') ?></textarea>
                                    </div>

                                    <!-- ── UPDATED (this adjustment): the old inline quick-pick chip
                                         panels (shown for both "Set Signing Schedule" and
                                         "Re-Schedule") are removed entirely — both actions now open
                                         the shared #moaScheduleModal 2-step (Date → Time) popup
                                         instead (see openMoaCustomScheduleModal() near the bottom of this
                                         file), which also enforces that the date can never be set in
                                         the past. -->

                                    <?php if($moaStage==='scheduled'&&!empty($moaRes['moa_schedule_datetime'])): ?>
                                    <!-- ═══════════════════════════════════════════════════════
                                         NEW (this adjustment) — SIGNING SCHEDULE CONFIRMATION.
                                         The company representative must now explicitly agree to
                                         (or decline) the schedule the admin just set — see
                                         CompanyForm.php's own confirmation panel for that side of
                                         the flow. This block shows exactly where that stands:
                                         still awaiting a response, confirmed, or declined with a
                                         reason and an alternative the company proposed instead
                                         (with a one-click way to adopt it). ═══════════════════════════════════════════════════════ -->
                                    <?php
                                    $schedStatus = $moaRes['moa_schedule_status'] ?? 'pending_confirmation';
                                    ?>
                                    <div class="moa-sched-confirm-block" id="moaScheduleConfirmBlock_<?= $uid ?>">
                                        <!-- ── NEW (this adjustment): the four states below now use the same lavender "signing
                                             schedule" panel design as CompanyForm.php's schedule confirmation panel
                                             (.moa-sched-panel — icon header, struck-through original date, reason, proposed
                                             schedule, hint), worded from the admin's side. Same logic/states/actions as before;
                                             the schedule itself is shown inside the panel (the old "Signing currently scheduled for …" line is gone). -->
                                        <div class="moa-sched-panel">
                                        <?php if ($schedStatus === 'confirmed'): ?>
                                            <div class="moa-sched-panel-header confirmed">
                                                Company representative confirmed this signing schedule
                                            </div>
                                            <p class="moa-sched-panel-datetime"><?= date('l, F j, Y \a\t g:i A', strtotime($moaRes['moa_schedule_datetime'] ?? '')) ?></p>
                                        <?php elseif ($schedStatus === 'confirmed_by_admin'): ?>
                                            <!-- ── NEW (this adjustment): distinct from the company clicking
                                                 "I Agree" above — this is the admin having accepted the
                                                 company's own counter-proposal via "Accept Proposed
                                                 Schedule". Crediting it to the company here would be
                                                 inaccurate, since they never agreed to anything; the admin
                                                 is the one who acted. -->
                                            <div class="moa-sched-panel-header confirmed">
                                                You accepted the company's proposed schedule
                                            </div>
                                            <p class="moa-sched-panel-datetime"><?= date('l, F j, Y \a\t g:i A', strtotime($moaRes['moa_schedule_datetime'] ?? '')) ?></p>
                                        <?php elseif ($schedStatus === 'declined'): ?>
                                            <div class="moa-sched-panel-header declined">
                                                <span class="moa-sched-panel-title">Company proposed a different schedule</span>
                                            </div>
                                            <!-- NEW (this adjustment): the original (struck-through) schedule sits BELOW the header sentence -->
                                            <p class="moa-sched-panel-datetime moa-sched-strike">Originally proposed: <?= date('M d, Y g:i A', strtotime($moaRes['moa_schedule_datetime'] ?? '')) ?></p>
                                            <?php if (!empty($moaRes['moa_schedule_decline_reason'])): ?>
                                            <p class="moa-sched-panel-note">Company's reason: "<?= htmlspecialchars($moaRes['moa_schedule_decline_reason'] ?? '') ?>"</p>
                                            <?php endif; ?>
                                            <?php if (!empty($moaRes['moa_proposed_datetime'])): ?>
                                            <p class="moa-sched-panel-note">Company's proposed schedule: <strong><?= date('M d, Y g:i A', strtotime($moaRes['moa_proposed_datetime'] ?? '')) ?></strong></p>
                                            <?php endif; ?>
                                            <p class="moa-sched-panel-hint">Waiting for you to review the company's proposed schedule.</p>
                                        <?php else: ?>
                                            <div class="moa-sched-panel-header pending">
                                                <i class="fas fa-calendar-check"></i> Signing schedule proposed to the company
                                            </div>
                                            <p class="moa-sched-panel-datetime"><?= date('l, F j, Y \a\t g:i A', strtotime($moaRes['moa_schedule_datetime'] ?? '')) ?></p>
                                            <p class="moa-sched-panel-hint">Awaiting company confirmation.</p>
                                        <?php endif; ?>
                                        </div>
                                    </div>
                                    <?php endif; ?>

                                    <!-- Action buttons — ── UPDATED (this adjustment): the merged
                                         "Pending for Review" stage now shows a single "Review MOA"
                                         button that opens the review/flag preview (the same modal
                                         used for flagging revision); both "Approve MOA" and
                                         "Send & Request Revision" now live inside that preview
                                         instead of as separate direct buttons on the row. -->
                                    <div class="moa-wf-actions" id="moaWfActions_<?= $uid ?>">
                                        <?php if($moaStage==='pending'): ?>
                                            <button class="moa-wf-btn review" onclick="openMoaTableFlagModal(<?= $uid ?>)"><i class="fas fa-search"></i> Review MOA</button>
                                        <?php elseif($moaStage==='approved'): ?>
                                            <button class="moa-wf-btn schedule" onclick="openMoaCustomScheduleModal(<?= $uid ?>)"><i class="fas fa-calendar-check"></i> Set Signing Schedule</button>
                                        <?php elseif($moaStage==='scheduled'): ?>
                                            <?php
                                            // ── NEW (this adjustment): "Done" stays locked until the signing schedule is FINALIZED
                                            // (company confirmed it, or the admin accepted the company's proposed one); and the
                                            // "Accept Proposed Schedule" button sits in this same row, to the right of Re-Schedule.
                                            $schedStatusNow = $moaRes['moa_schedule_status'] ?? 'pending_confirmation';
                                            $schedFinal     = in_array($schedStatusNow, ['confirmed', 'confirmed_by_admin'], true);
                                            ?>
                                            <button class="moa-wf-btn done-btn<?= $schedFinal ? '' : ' moa-done-locked' ?>" onclick="markMoaDone(<?= $uid ?>,this)"<?= $schedFinal ? '' : ' aria-disabled="true" title="Available once the signing schedule is finalized: confirmed by the company, or accepted by you."' ?>><i class="fas fa-check-double"></i> Done</button>
                                            <button class="moa-wf-btn resched-btn" onclick="openMoaCustomScheduleModal(<?= $uid ?>)"><i class="fas fa-redo"></i> Re-Schedule</button>
                                            <?php if ($schedStatusNow === 'declined' && !empty($moaRes['moa_proposed_datetime']) && !empty($moaRes['moa_schedule_datetime'])): ?>
                                            <button type="button" class="moa-wf-btn schedule moa-accept-proposed-btn" onclick="acceptProposedSchedule(<?= $uid ?>)"><i class="fas fa-check"></i> Accept Proposed Schedule</button>
                                            <?php endif; ?>
                                        <?php endif; ?>
                                    </div>
                                </div>
                                <?php endif; ?>
                            </div>
                        </div>
                        <?php endif; ?>
                        <!-- ═══ TAB 3: COMPANY DETAILS — .info-side p is read by openMoaTableFlagModal() ═══ -->
                        <div class="cv-tabpanel" data-tabpanel="contact" role="tabpanel">
                            <?php
                            // NEW (this adjustment): the Representative used to be first + last name
                            // only, which dropped the middle name — so it didn't match the full
                            // contact name the MOA request inbox shows. Built exactly the way
                            // CompanyForm.php builds it ("first middle last", whitespace collapsed).
                            $cvRepFullName = trim(preg_replace('/\s+/', ' ',
                                ($company['contact_first_name'] ?? '') . ' ' .
                                ($company['contact_middle_initial'] ?? '') . ' ' .
                                ($company['contact_last_name'] ?? '')));
                            // NEW (this adjustment): the free-text Company Profile / Brief Description
                            // (NOT the "Company Profile" document upload in the Requirements tab).
                            $cvProfileText = trim((string)($company['company_profile'] ?? ''));
                            ?>
                            <?php /* UPDATED (this adjustment): what used to be two separate cards (Contact Details +
                                     Company Profile) is now ONE card. The grid carries the .info-side class, so
                                     '.info-side p' still resolves to the Representative <p> first — that is what
                                     openMoaTableFlagModal() reads. Keep the "Representative:" label text as-is
                                     (that function strips exactly that string). */ ?>
                            <div class="cv-details-card">
                                <h4>Company Details</h4>
                                <div class="info-side cv-details-grid">
                                    <p><strong><i class="fas fa-user"></i> Representative:</strong> <?= htmlspecialchars($cvRepFullName ?? '') ?></p>
                                    <p><strong><i class="fas fa-briefcase"></i> Position:</strong> <?= htmlspecialchars($company['position'] ?? '') ?></p>
                                    <p><strong><i class="fas fa-phone"></i> Phone:</strong> <?= htmlspecialchars($company['telephone'] ?? '') ?></p>
                                    <p><strong><i class="fas fa-building"></i> Type:</strong> <?= ucfirst($company['company_type'] ?? '') ?></p>
                                    <div class="cv-detail-wide">
                                        <span class="cv-detail-label"><i class="fas fa-align-left"></i> Company Profile / Brief Description</span>
                                        <?php if ($cvProfileText !== ''): ?>
                                        <div class="cv-profile-text"><?= htmlspecialchars($cvProfileText ?? '') ?></div>
                                        <?php else: ?>
                                        <div class="cv-profile-text is-empty">No company profile / brief description has been provided.</div>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            </div>
                        </div>

                    </div>
                </div>
            </div>
<?php
}


/* ================= HELPER: SAVE MOA BLOB TO COMPANY FOLDER ================= */
function saveMoaBlobToFolder($conn, $moa_user_id, $moa_pdf_blob, $moa_pdf_filename) {
    global $companyReqLabels;
    moaDebugLog('saveMoaBlobToFolder:start', ['user_id' => $moa_user_id, 'filename' => $moa_pdf_filename, 'blob_length' => strlen($moa_pdf_blob)]);
    if (empty($moa_pdf_blob)) { moaDebugLog('saveMoaBlobToFolder:skip_no_blob'); return false; }

    $cq = $conn->prepare("SELECT company FROM company_information WHERE user_id=?");
    $cq->bind_param("i", $moa_user_id); $cq->execute();
    $cr = $cq->get_result()->fetch_assoc(); $cq->close();

    $companyName = preg_replace("/[^a-zA-Z0-9]/", "_", $cr['company'] ?? "Company_" . $moa_user_id);
    $uploadPath  = "Company Folder/" . $companyName;
    if (!file_exists($uploadPath)) { $mkResult = mkdir($uploadPath, 0777, true); moaDebugLog('saveMoaBlobToFolder:mkdir', ['result' => $mkResult]); }

    $ext = 'pdf';
    if (!empty($moa_pdf_filename)) {
        $storedExt = strtolower(pathinfo($moa_pdf_filename, PATHINFO_EXTENSION));
        if (in_array($storedExt, ['pdf','png','jpg','jpeg','gif','webp'])) $ext = $storedExt;
    } else {
        $finfo = new finfo(FILEINFO_MIME_TYPE); $mime = $finfo->buffer($moa_pdf_blob);
        $mimeMap = ['application/pdf'=>'pdf','image/png'=>'png','image/jpeg'=>'jpg','image/gif'=>'gif','image/webp'=>'webp'];
        $ext = $mimeMap[$mime] ?? 'pdf';
    }

    $safeLabel = preg_replace("/[^a-zA-Z0-9]/", "_", $companyReqLabels['moa_document'] ?? 'MOA_Document');
    $destFile  = $uploadPath . "/" . $safeLabel . "_verified_" . time() . "." . $ext;
    $written   = file_put_contents($destFile, $moa_pdf_blob);
    moaDebugLog('saveMoaBlobToFolder:write', ['dest' => $destFile, 'written' => $written]);
    return $written !== false;
}

/* ================= HELPER: ENSURE company_information COLUMNS ================= */
function ensureCompanyInfoColumns($conn) {
    $cols = [
        "ALTER TABLE company_information ADD COLUMN IF NOT EXISTS company VARCHAR(200) DEFAULT ''",
        "ALTER TABLE company_information ADD COLUMN IF NOT EXISTS company_address VARCHAR(300) DEFAULT ''",
        "ALTER TABLE company_information ADD COLUMN IF NOT EXISTS position VARCHAR(150) DEFAULT ''",
        "ALTER TABLE company_information ADD COLUMN IF NOT EXISTS contact_first_name VARCHAR(100) DEFAULT ''",
        "ALTER TABLE company_information ADD COLUMN IF NOT EXISTS contact_middle_initial VARCHAR(100) DEFAULT ''",
        "ALTER TABLE company_information ADD COLUMN IF NOT EXISTS contact_last_name VARCHAR(100) DEFAULT ''",
        // ── NEW (this adjustment): guarded here too (mirrors
        // company_register.php's own guard for this column) since
        // blankFlaggedCompanyInfoFields()/regenerateAndReturnMoaToCompany()
        // below now read/write it.
        "ALTER TABLE company_information ADD COLUMN IF NOT EXISTS company_profile TEXT NULL AFTER company_address",
    ];
    foreach ($cols as $sql) { $conn->query($sql); }
}

/* ════════════════════════════════════════════════════════════════════════
   NEW (this adjustment) — "DELETE THE FLAGGED INPUT, THEN RECREATE & RETURN
   THE MOA" for companies whose MOA is data-generated (auto-built from their
   own company_information, either at registration by company_register.php
   or via moa_request.php's "Request New MOA" path) rather than an uploaded
   scan of an already-signed document ("Already Have MOA" / 'existing').

   When the admin flags section(s) on such a company's MOA for revision:
     1. blankFlaggedCompanyInfoFields() clears (sets NULL) the underlying
        company_information column(s) for exactly the field(s) the admin
        flagged — the actual DB deletion requested.
     2. regenerateAndReturnMoaToCompany() immediately rebuilds the MOA PDF
        from the (now partially blank) company_information data and saves
        it back into company_requirements as the company's current, still-
        Pending MOA document — "returning" it to them. The blanked
        field(s) render as the same bracketed placeholder text
        regBuildMOAStaticHTMLForDompdf() already shows for any empty value
        (e.g. "[COMPANY PROFILE / BRIEF DESCRIPTION]"), so the missing
        section is visibly obvious in the document itself.

   Both steps are skipped for a company whose MOA request type is
   genuinely "existing" (an uploaded scan, not data-generated) — see
   isMoaSubmissionDataGenerated() below — since blanking fields and
   overwriting their real uploaded document with an auto-generated one
   would destroy real, legitimate data instead of helping.

   regBuildMOAStaticHTMLForDompdf() and regGenerateMoaPdfBytes() immediately
   below are copied verbatim from company_register.php (same file, same
   global namespace, so no adjustment was needed) so the regenerated
   document looks identical to the one that page itself generates/previews
   — this page and that one now share the exact same MOA-building logic
   instead of drifting apart with a second, different implementation.
   ════════════════════════════════════════════════════════════════════════ */

/**
 * Build a static, Dompdf-safe HTML version of a "Request New MOA"
 * document. This mirrors buildMOAStaticHTMLForDompdf() in moa_request.php
 * exactly (same letterhead, section structure, numbered lists, signing
 * block, witness block, acknowledgment, and repeating footer) so a MOA
 * generated/previewed at registration looks identical to one generated
 * later through the normal moa_request.php flow.
 *
 * (Copied verbatim from company_register.php's regBuildMOAStaticHTMLForDompdf()
 * — see the block comment above for why.)
 */
function regBuildMOAStaticHTMLForDompdf(array $s): string
{
    $esc = fn($v) => htmlspecialchars((string)($v ?? ''), ENT_QUOTES | ENT_HTML5, 'UTF-8');
    $dv  = fn($v, $fallback = '—') =>
        (trim((string)$v) !== '' && $v !== '___' && $v !== '202___') ? (string)$v : $fallback;

    $moa_number   = $dv($s['moa_number']             ?? '', '___');
    $moa_year     = $dv($s['moa_year']               ?? '', date('Y'));
    $company      = $dv($s['company_name']            ?? '', '[COMPANY NAME]');
    $company_desc = $dv($s['company_description']     ?? '', '[COMPANY PROFILE / BRIEF DESCRIPTION]');
    $company_addr = $dv($s['company_address']         ?? '', '[COMPANY ADDRESS]');
    $rep_name     = $dv($s['representative_name']     ?? '', '[NAME OF REPRESENTATIVE]');
    $rep_pos      = $dv($s['representative_position'] ?? '', 'Manager/Head/Director');
    $sign_date    = $dv($s['signing_date']            ?? '', '_____________________');
    $sign_place   = $dv($s['signing_place']            ?? '', '_____________________');
    $notary_city  = $dv($s['notary_city']              ?? '', '_____________________________');
    $company_id   = $dv($s['company_id']               ?? '', '________________');

    $eCompany    = $esc($company);
    $eCompDesc   = $esc($company_desc);
    $eCompAddr   = $esc($company_addr);
    $eRepName    = $esc($rep_name);
    $eRepPos     = $esc($rep_pos);
    $eSignDate   = $esc($sign_date);
    $eSignPlace  = $esc($sign_place);
    $eNotaryCity = $esc($notary_city);
    $eCompanyId  = $esc($company_id);
    $eMoaNo      = 'MOA No. ' . $esc($moa_number) . ', s.' . $esc($moa_year);
    $eYear       = $esc($moa_year);

    $FILL = 'font-family:serif;font-weight:700;color:#0d2545;'
          . 'border-bottom:1px solid #0d2545;padding:0 2pt;';

    $LH = '
<table width="100%" cellpadding="0" cellspacing="0" border="0"
       style="background:#0d2545;border-bottom:2pt solid #b8860b;">
  <tr>
    <td width="50pt" style="padding:6pt 6pt 6pt 14pt;vertical-align:middle;">
      <img src="logo.webp" alt="NEUST" width="36" height="36"
           style="border-radius:18pt;display:block;
                  border:1.5pt solid rgba(255,255,255,0.25);">
    </td>
    <td style="padding:5pt 14pt 5pt 6pt;vertical-align:middle;">
      <div style="font-size:5.5pt;letter-spacing:0.18em;text-transform:uppercase;
                  color:#aac4f0;margin-bottom:1.5pt;font-family:monospace;">
        Republic of the Philippines
      </div>
      <div style="font-size:10.5pt;font-weight:700;text-transform:uppercase;
                  color:#ffffff;font-family:sans-serif;line-height:1.2;
                  letter-spacing:0.01em;">
        Nueva Ecija University of Science and Technology
      </div>
      <div style="font-size:6.5pt;color:#aac4f0;margin-top:1pt;
                  font-style:italic;font-family:sans-serif;">
        Cabanatuan City, Nueva Ecija
      </div>
    </td>
  </tr>
</table>
<table width="100%" cellpadding="0" cellspacing="0" border="0"
       style="background:#f5f6fa;border-bottom:1pt solid #d0d5e8;">
  <tr>
    <td style="padding:5pt 14pt 4pt;text-align:center;">
      <div style="font-size:5.5pt;letter-spacing:0.14em;text-transform:uppercase;
                  color:#5a6a8a;margin-bottom:1.5pt;font-family:monospace;">
        On-the-Job Training and Career Development Center
      </div>
      <div style="font-size:12pt;font-weight:700;text-transform:uppercase;
                  color:#0d2545;letter-spacing:0.03em;font-family:sans-serif;">
        Memorandum of Agreement
      </div>
      <div style="font-size:7.5pt;font-weight:600;color:#1a4a8a;
                  margin-top:2pt;font-family:monospace;">
        ' . $eMoaNo . '
      </div>
      <div style="font-size:5.5pt;color:#888;margin-top:2pt;font-family:monospace;">
        Form No.: NEUST-OJT-F005 &nbsp;&middot;&nbsp; Effectivity: 01.08.2025
      </div>
    </td>
  </tr>
</table>';

    $ST = fn($t) =>
        '<p style="font-family:sans-serif;font-size:9.5pt;font-weight:700;'
        . 'text-align:center;text-decoration:underline;text-transform:uppercase;'
        . 'letter-spacing:0.04em;margin:10pt 0 5pt;color:#0d2545;">'
        . $t . '</p>';

    $SS = fn($t) =>
        '<p style="font-weight:700;margin:7pt 0 4pt;font-size:9.5pt;font-family:serif;">'
        . $t . '</p>';

    $LI = fn($n, $t) =>
        '<tr>'
        . '<td width="16pt" valign="top"'
        . '    style="font-size:9.5pt;font-family:serif;font-weight:700;'
        .          'color:#0d2545;padding-bottom:3pt;padding-right:4pt;'
        .          'white-space:nowrap;">'
        . $n . '.</td>'
        . '<td valign="top"'
        . '    style="font-size:9.5pt;font-family:serif;line-height:1.65;'
        .          'text-align:justify;padding-bottom:3pt;">'
        . $t . '</td>'
        . '</tr>';

    $BODY_STYLE = 'font-family:serif;font-size:9.5pt;line-height:1.65;'
                . 'color:#1a2035;text-align:justify;'
                . 'padding:10pt 22pt 22pt 22pt;';

    $buildPage = function(string $bodyHTML, string $lh, bool $isLast = false) use ($BODY_STYLE): string {
        $breakStyle = $isLast ? '' : 'page-break-after:always;';
        return '
<div style="' . $breakStyle . 'background:#ffffff;">
  ' . $lh . '
  <div style="' . $BODY_STYLE . '">
    ' . $bodyHTML . '
  </div>
</div>';
    };

    $page1Body = '
<p style="font-weight:700;margin-bottom:8pt;font-size:9.5pt;font-family:serif;">
  KNOWN ALL MEN BY THESE PRESENTS:
</p>
<p style="margin-bottom:6pt;font-size:9.5pt;font-family:serif;">
  This <strong>Memorandum of Agreement</strong> made and entered by and between:
</p>
<p style="margin:6pt 0 6pt 18pt;text-indent:-18pt;padding-left:18pt;
          font-size:9.5pt;font-family:serif;">
  <span style="' . $FILL . '">' . $eCompany . '</span>,
  an entity duly licensed and registered establishment under the laws of the Philippines,
  <span style="' . $FILL . '">' . $eCompDesc . '</span>
  with principal office address at
  <span style="' . $FILL . '">' . $eCompAddr . '</span>
  herein represented by its ' . $eRepPos . '
  <span style="' . $FILL . '">' . $eRepName . '</span>
  hereinafter referred to as the <strong><em>"TRAINING INSTITUTION"</em></strong>;
</p>
<p style="text-align:center;font-weight:700;margin:5pt 0;font-size:9.5pt;
          font-family:serif;">
  &ndash;AND&ndash;
</p>
<p style="margin:6pt 0 6pt 18pt;text-indent:-18pt;padding-left:18pt;
          font-size:9.5pt;font-family:serif;">
  <strong>NUEVA ECIJA UNIVERSITY OF SCIENCE AND TECHNOLOGY (NEUST)</strong>,
  a chartered state university in accordance with R.A. 8612, with office address at
  General Tinio Street, Cabanatuan City, Nueva Ecija 3100, represented by its
  University President, <strong>DR. RHODORA R. JUGO</strong>,
  hereinafter referred to as the <strong><em>"UNIVERSITY"</em></strong>
</p>
<p style="text-align:center;font-weight:700;font-style:italic;
          margin:8pt 0 4pt;font-size:9.5pt;font-family:serif;">
  &ndash;WITNESSETH: That&ndash;
</p>
<p style="margin-bottom:5pt;font-size:9.5pt;font-family:serif;">
  &nbsp;&nbsp;&nbsp;&nbsp;The UNIVERSITY has requested the TRAINING INSTITUTION to
  accommodate its students in the different field of discipline as TRAINEES under the
  On&ndash;the&ndash;Job Training (OJT) Program as required in the Board-approved
  curriculum they are enrolled in; and
</p>
<p style="margin-bottom:5pt;font-size:9.5pt;font-family:serif;">
  &nbsp;&nbsp;&nbsp;&nbsp;The TRAINING INSTITUTION has agreed to accommodate the TRAINEES
  for their On&ndash;the&ndash;Job Training (OJT), subject to the terms and conditions
  specified hereunder.
</p>
<p style="margin-bottom:8pt;font-size:9.5pt;font-family:serif;">
  &nbsp;&nbsp;&nbsp;&nbsp;NOW THEREFORE, for and in consideration of the foregoing premises
  and the mutual covenants set forth herein, the parties agree as follows:
</p>
' . $ST('Term') . '
<p style="margin-bottom:6pt;font-size:9.5pt;font-family:serif;">
  &nbsp;&nbsp;&nbsp;&nbsp;This Memorandum of Agreement shall take effect upon signing of
  both parties and shall continue to remain in full force and effect unless sooner revised
  or terminated by either party giving notice to the other at least six (6) months prior
  to the intended date of revision or termination. Such notice of termination will not
  interfere with the cooperative program currently underway. Such programs will be allowed
  to continue until their conclusion.
</p>';

    $page2Body = $ST('Duties and Obligations')
    . $SS('A. The UNIVERSITY')
    . '<table width="100%" cellpadding="0" cellspacing="0" border="0"
             style="margin-bottom:6pt;">'
    . $LI('1', 'To formulate local school practicum policies and guidelines on selection, placement, monitoring and assessment of the <strong>TRAINEES</strong>;')
    . $LI('2', 'To pre&ndash;qualify the <strong>TRAINEES</strong> in accordance with the school off campus training policies and requirements as specified in CMO No. 25, Series of 2015 and the requirements from the <strong>TRAINING INSTITUTION</strong>;')
    . $LI('3', 'To set the criteria on the selection of a Faculty Practicum who is academically qualified and will be responsible as Faculty SIPP Coordinator per program for all the aspects of the student internship programs including program implementation, monitoring and evaluation;')
    . $LI('4', 'To monitor, jointly with the <strong>TRAINING INSTITUTION</strong> and evaluate the performance of the TRAINEES based on the prescribed CMO No. 25, Series of 2015;')
    . $LI('6', 'To conduct general orientation for the <strong>TRAINEES</strong> and their parents/guardians;')
    . $LI('7', 'To conduct initial and regular visit of the <strong>TRAINING INSTITUTION</strong> premises to ensure the safety of the <strong>TRAINEES</strong>;')
    . $LI('8', 'To subject the student <strong>TRAINEE</strong> to institutional disciplinary policies for any violations of the guidelines set forth under CMO No. 23 series of 2009 after due investigations conducted in connection thereto; and')
    . $LI('9', 'To issue final grade to the student trainee based on the <strong>TRAINEE\'S</strong> performance evaluation upon completion of requirements on prescribed period and the concomitant Certificate of Appreciation of the completion of training of the student with the <strong>TRAINING INSTITUTION.</strong>')
    . '</table>'
    . $SS('B. The TRAINING INSTITUTION')
    . '<table width="100%" cellpadding="0" cellspacing="0" border="0"
             style="margin-bottom:4pt;">'
    . $LI('1',  'To facilitate the processing of the On-the-Job Training-related documents of the student trainees/interns in coordination with the <strong>UNIVERSITY;</strong>')
    . $LI('2',  'To provide Supervised Applied Learning Experiences for the student trainees in accordance with agreed Training Manual/Plan and schedule of activities;')
    . $LI('3',  'To assign a competent Training Supervisor responsible for the implementation of the relevant phases of the Training Plan;')
    . $LI('4',  'To provide safe and conducive working environment/venue for the Trainees which is free from any hazard and will bolster their confidence and develop their skills during the training;')
    . $LI('5',  'To allow the University through its duly authorized representative to visit the site where the training program will be held and regularly visit and monitor the same upon prior notice to the concerned office of the <strong>TRAINING INSTITUTION</strong> conducting the training;')
    . $LI('6',  'To comply with the specific provisions of the Labor Code of the Philippines and other pertinent laws in accepting Trainees under the OJT Program and afford the Trainees their respective rights under the said laws;')
    . $LI('7',  'To immediately inform the University through its authorized representative of any incident during the training program which would expose the Trainees of any harm or injury or violation of their rights;')
    . $LI('8',  'To immediately act on the complaints of the Trainees concerning the improper demeanor of their Trainor/s or any complaint concerning violation/s of their rights;')
    . $LI('9',  'To comply with the existing rules and regulations involving health protocols implemented by the University, National Government, IATF, Department of Health, CHED, Local Government Unit in the area and provide sufficient health facilities in favor of the TRAINEE. Any violation of such rules and regulations which compromise the safety of the TRAINEE shall be a ground for the termination of the On&ndash;the&ndash;job Training (OJT) Program;')
    . $LI('10', 'To conduct post training review and evaluation of the program and the performance of Trainees together with the <strong>UNIVERSITY</strong>; and')
    . $LI('11', 'To issue a <strong>Certificate of Completion</strong> of the <strong>TRAINEE</strong> one (1) week after the completion of the training.')
    . '</table>';

    $page3Body = $ST('Adjustment/s')
    . '<p style="margin-bottom:6pt;font-size:9.5pt;font-family:serif;">
  &nbsp;&nbsp;&nbsp;&nbsp;Both the <strong>TRAINING INSTITUTION</strong> and the
  <strong>UNIVERSITY</strong> can make the necessary amendments or changes on their
  duties and obligation to suit the prevailing condition and community quarantine
  in the area/s of trainee\'s assignment.
</p>'
    . $ST('Obligation of the Trainee')
    . '<p style="margin-bottom:5pt;font-size:9.5pt;font-family:serif;">
  &nbsp;&nbsp;&nbsp;&nbsp;The <strong>TRAINEE</strong> shall be liable to any damages he
  may cause through his fault or negligence such as breakage of
  <strong>TRAINING INSTITUTION</strong> properties after due notice and hearing in
  accordance with <strong>TRAINING INSTITUTION</strong> rules. The University shall
  ensure that the liable trainee shall fulfill its obligation. Otherwise, the University
  shall cover the unsettled liability of the trainee.
</p>
<p style="margin-bottom:5pt;font-size:9.5pt;font-family:serif;">
  &nbsp;&nbsp;&nbsp;&nbsp;The TRAINEES shall complete the Training Program within the
  Required OJT Hours.
</p>
<p style="margin-bottom:6pt;font-size:9.5pt;font-family:serif;">
  &nbsp;&nbsp;&nbsp;&nbsp;The <strong>TRAINEE</strong> shall strictly comply with the
  existing rules and regulations involving health protocols implemented by the
  <strong>TRAINING INSTITUTION</strong>, the University, National Government, IATF,
  Department of Health, CHED, Local Government Unit in the area. The trainee shall
  immediately report to the Company and the University any instance of violation or
  non-compliance with such rules and regulations. Any violation of such rules and
  regulations on the part of the Trainee shall be a ground for the termination of
  his/her On&ndash;the&ndash;job Training Program.
</p>'
    . $ST('Schedule')
    . '<p style="margin-bottom:5pt;font-size:9.5pt;font-family:serif;">
  &nbsp;&nbsp;&nbsp;&nbsp;The TRAINEE shall be observing the following training schedule:
</p>
<p style="margin-bottom:5pt;font-size:9.5pt;font-family:serif;">
  &nbsp;&nbsp;&nbsp;&nbsp;Mondays to Fridays Time: 8:00&ndash;5:00pm, without prejudice to
  a flexible and suitable schedule to be agreed upon by the
  <strong>TRAINING INSTITUTION</strong> and the <strong>UNIVERSITY</strong> which may
  include Saturdays and Sundays;
</p>
<p style="margin-bottom:5pt;font-size:9.5pt;font-family:serif;">
  &nbsp;&nbsp;&nbsp;&nbsp;The training hours shall not exceed eight (8) hours per day.
</p>
<p style="margin-bottom:6pt;font-size:9.5pt;font-family:serif;">
  &nbsp;&nbsp;&nbsp;&nbsp;The <strong>TRAINING INSTITUTION</strong> shall not require the
  TRAINEE to render overtime work or to report for training on legal (regular) or special
  holidays.
</p>'
    . $ST('Monitoring and Evaluation')
    . '<p style="margin-bottom:6pt;font-size:9.5pt;font-family:serif;">
  &nbsp;&nbsp;&nbsp;&nbsp;During the conduct of the Training Program, the faculty SIPP
  Coordinator and/or Director of the On&ndash;the&ndash;Job Training (OJT) and Career
  Development Centre of the UNIVERSITY shall monitor and evaluate the Trainees and will
  utilize standard procedures, instruments and methodologies such as observations, monthly
  reports, and interviews or conferences with the students.
</p>'
    . $ST('Pre-Termination')
    . '<p style="margin-bottom:8pt;font-size:9.5pt;font-family:serif;">
  &nbsp;&nbsp;&nbsp;&nbsp;Either of the parties upon written notice may pre-terminate the
  foregoing agreement in case of violation of either party of any provision of the
  foregoing Agreement. In case the violation is on the part of the
  <strong>TRAINING INSTITUTION</strong>, corresponding Certification shall be issued in
  favor of the Trainees despite the termination in accordance with the extent of the
  training undergone by them.
</p>';

    $page4Body =
    '<p style="margin-bottom:8pt;font-size:9.5pt;font-family:serif;">
  &nbsp;&nbsp;&nbsp;&nbsp;<strong>IN WITNESS WHEREOF,</strong> the parties have carefully
  read, fully understood and voluntarily agree, to the terms and conditions of this
  agreement, and have caused this agreement to be signed by their duty authorized
  representatives this
  <span style="' . $FILL . '">' . $eSignDate . '</span>
  hereat
  <span style="' . $FILL . '">' . $eSignPlace . '</span>.
</p>'
    . '<table width="100%" cellpadding="0" cellspacing="0" border="0"
             style="margin-top:10pt;">
  <tr>
    <td width="50%" valign="top" align="center" style="padding:0 6pt 0 0;">
      <p style="font-size:8.5pt;font-weight:700;text-transform:uppercase;
                letter-spacing:0.04em;color:#0d2545;font-family:sans-serif;
                margin-bottom:20pt;line-height:1.4;text-align:center;">
        For the:<br>Training Institution
      </p>
      <div style="border-bottom:1.5px solid #1a2035;width:90%;
                  margin:0 auto 3pt;"></div>
      <p style="font-weight:700;font-size:9.5pt;color:#0d2545;text-align:center;
                text-decoration:underline;font-family:serif;margin:0 0 2pt;">
        ' . $eRepName . '
      </p>
      <p style="font-size:8.5pt;color:#5a6a8a;font-family:sans-serif;
                text-align:center;margin:0 0 6pt;">
        ' . $eRepPos . '
      </p>
      <div style="border-bottom:1.5px solid #1a2035;width:70%;
                  margin:0 auto 3pt;"></div>
      <p style="font-size:8.5pt;color:#5a6a8a;font-family:sans-serif;
                text-align:center;margin:0;">
        ' . $eCompany . '
      </p>
    </td>
    <td width="50%" valign="top" align="center" style="padding:0 0 0 6pt;">
      <p style="font-size:8.5pt;font-weight:700;text-transform:uppercase;
                letter-spacing:0.04em;color:#0d2545;font-family:sans-serif;
                margin-bottom:20pt;line-height:1.4;text-align:center;">
        For the:<br>University
      </p>
      <div style="border-bottom:1.5px solid #1a2035;width:90%;
                  margin:0 auto 3pt;"></div>
      <p style="font-weight:700;font-size:9.5pt;color:#0d2545;text-align:center;
                text-decoration:underline;font-family:serif;margin:0 0 2pt;">
        RHODORA R. JUGO, EdD
      </p>
      <p style="font-size:8.5pt;color:#5a6a8a;font-family:sans-serif;
                text-align:center;margin:0;">
        University President<br>NEUST
      </p>
    </td>
  </tr>
</table>'
    . '<div style="margin-top:10pt;text-align:center;">
  <p style="font-size:8.5pt;font-weight:600;font-family:sans-serif;
            margin-bottom:8pt;">
    Signed in the presence of:
  </p>
  <p style="font-weight:700;font-size:9.5pt;text-decoration:underline;
            color:#0d2545;font-family:serif;margin:0 0 2pt;text-align:center;">
    RANDY M. BA&Ntilde;EZ, J.D.
  </p>
  <p style="font-size:8.5pt;color:#5a6a8a;font-family:sans-serif;
            text-align:center;margin:0;line-height:1.5;">
    Director, On-the-Job Training and Career Development Centre<br>
    Nueva Ecija University of Science and Technology
  </p>
</div>'
    . '
<p style="font-family:sans-serif;font-size:10pt;font-weight:700;
          text-align:center;text-decoration:underline;
          margin:16pt 0 8pt;letter-spacing:0.04em;color:#0d2545;">
  ACKNOWLEDGMENT
</p>
<p style="font-size:9pt;margin-bottom:2pt;font-family:serif;">
  REPUBLIC OF THE PHILIPPINES)
</p>
<p style="font-size:9pt;margin-bottom:2pt;font-family:serif;">
  <span style="' . $FILL . '">' . $eNotaryCity . '</span>
  &nbsp;&nbsp;&nbsp;&nbsp;) S.S.
</p>
<p style="margin:3pt 0 6pt 0;font-family:serif;font-size:9pt;">
  x----------------------------x
</p>
<p style="margin-bottom:5pt;font-size:9.5pt;font-family:serif;">
  <strong>BEFORE ME,</strong> a notary public duly authorized in the city named above,
  personally appeared:
</p>
<table width="100%" cellpadding="2" cellspacing="0" border="0"
       style="margin-bottom:6pt;font-size:9.5pt;font-family:serif;">
  <tr>
    <td width="55%" style="padding:2pt 4pt;">
      <span style="' . $FILL . '">' . $eRepName . '</span>
    </td>
    <td style="padding:2pt 4pt;">
      - ID No. <span style="' . $FILL . '">' . $eCompanyId . '</span>
    </td>
  </tr>
  <tr>
    <td style="padding:2pt 4pt;">
      <strong>RHODORA R. JUGO, EdD</strong>
    </td>
    <td style="padding:2pt 4pt;">
      - NEUST ID No. __________
    </td>
  </tr>
</table>
<p style="margin-bottom:5pt;font-size:9.5pt;font-family:serif;">
  Who are personally known to me, through their competent evidence of identity as
  above-stated, to be the same persons described in the foregoing instrument
  consisting of five (4) pages including the page where this acknowledgement is
  written, who acknowledgment before me that their respective signatures on the
  instrument were voluntarily affixed by them for the purpose stated therein, and
  who declared to me that they have executed the instrument as their free and
  voluntary act and deed.
</p>
<p style="margin-bottom:10pt;font-size:9.5pt;font-family:serif;">
  <strong>WITNESS MY HAND AND SEAL</strong> this
  <span style="' . $FILL . '">' . $eSignDate . '</span>,
  hereat
  <span style="' . $FILL . '">' . $eSignPlace . '</span>.
</p>
<div style="font-size:9pt;font-family:serif;margin-top:8pt;line-height:1.9;">
  Doc. No._____<br>
  Page No._____<br>
  Book No.______<br>
  Series of ' . $eYear . '
</div>';

    $page1 = $buildPage($page1Body, $LH, false);
    $page2 = $buildPage($page2Body, $LH, false);
    $page3 = $buildPage($page3Body, $LH, false);
    $page4 = $buildPage($page4Body, $LH, true);

    return '<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>MOA &mdash; ' . $eCompany . '</title>
<style>
  @page { size:A4 portrait; margin:0; }
  * { box-sizing:border-box; margin:0; padding:0; }
  body { font-family:serif; background:#ffffff; color:#1a2035; }
  p { margin:0; padding:0; }
  strong { font-weight:700; }
  em { font-style:italic; }
  tr { page-break-inside:avoid; }
  #pdf-footer {
    position: fixed; bottom: 0; left: 0; right: 0;
    background: #f0f2f8; border-top: 1pt solid #0d2545;
    padding: 2.5pt 14pt; font-family: monospace; font-size: 5.5pt;
    color: #888; letter-spacing: 0.07em;
  }
  #pdf-footer table { width: 100%; border-collapse: collapse; }
</style>
</head>
<body>
<div id="pdf-footer">
  <table cellpadding="0" cellspacing="0" border="0">
    <tr><td>NEUST-OJT-F005</td><td align="right">Rev. 02 (01.08.2025)</td></tr>
  </table>
</div>
' . $page1 . '
' . $page2 . '
' . $page3 . '
' . $page4 . '
</body>
</html>';
}

/**
 * Render regBuildMOAStaticHTMLForDompdf()'s HTML to PDF bytes via Dompdf,
 * probing a few common autoload locations first (mirrors
 * company_register.php's own resilient autoload search exactly). Returns
 * null (non-fatally) if Dompdf isn't available or rendering fails.
 *
 * (Copied verbatim from company_register.php's regGenerateMoaPdfBytes().)
 */
function regGenerateMoaPdfBytes(string $staticHtml): ?string
{
    $autoloaders = [
        __DIR__ . '/vendor/autoload.php',
        dirname(__DIR__) . '/vendor/autoload.php',
        __DIR__ . '/dompdf/autoload.inc.php',
        __DIR__ . '/libs/dompdf/autoload.inc.php',
    ];

    try {
        foreach ($autoloaders as $al) {
            if (file_exists($al)) {
                require_once $al;
                if (class_exists('Dompdf\Dompdf')) break;
            }
        }
    } catch (\Throwable $e) {
        error_log('[REG MOA PDF] Failed while loading an autoloader: ' . $e->getMessage());
    }

    if (!class_exists('Dompdf\Dompdf')) {
        error_log('[REG MOA PDF] Dompdf class not found.');
        return null;
    }

    try {
        $options = new \Dompdf\Options();
        $options->set('isRemoteEnabled', true);
        $options->set('isHtml5ParserEnabled', true);
        $options->set('defaultFont', 'serif');
        $options->set('isFontSubsettingEnabled', true);
        $options->set('chroot', __DIR__);

        $dompdf = new \Dompdf\Dompdf($options);
        $dompdf->loadHtml($staticHtml, 'UTF-8');
        $dompdf->setPaper('A4', 'portrait');
        $dompdf->render();

        $bytes = $dompdf->output();
        if (is_string($bytes) && strlen($bytes) > 500) {
            return $bytes;
        }
        return null;
    } catch (\Throwable $e) {
        error_log('[REG MOA PDF] Generation failed: ' . $e->getMessage()
            . ' in ' . $e->getFile() . ':' . $e->getLine());
        return null;
    }
}

/* ================= HELPER: IS THIS COMPANY'S MOA DATA-GENERATED OR AN UPLOADED SCAN? =================
   NEW (this adjustment) — the "blank the flagged field(s) + regenerate the
   MOA" behavior below must NEVER run for a company whose MOA is a real
   uploaded scan of an already-signed document ("Already Have MOA" /
   request_type 'existing' on moa_request.php's own submission form) —
   overwriting that with an auto-generated templated PDF would destroy
   real, legitimate data. It's safe to regenerate when the MOA is
   data-generated: either auto-built at registration by
   company_register.php (no company_requirements.request_type recorded at
   all — that flow never sets it), or built through moa_request.php's own
   "Request New MOA" path (request_type 'new'). Only an explicit
   'existing' value blocks it. ================================ */
function isMoaSubmissionDataGenerated($conn, $user_id) {
    if (!$user_id) return false;
    $moaType = resolveMoaRequirementType($conn, $user_id);
    $q = $conn->prepare("SELECT request_type FROM company_requirements WHERE user_id=? AND requirement_type=?");
    if (!$q) return true;
    $q->bind_param("is", $user_id, $moaType);
    $q->execute();
    $row = $q->get_result()->fetch_assoc();
    $q->close();
    $rt = strtolower(trim((string)($row['request_type'] ?? '')));
    return ($rt !== 'existing');
}

/* ================= HELPER: DELETE THE FLAGGED FIELD(S) FROM company_information =================
   NEW (this adjustment) — the actual "user input for that selected flag
   area is deleted (in the DB)" step. Only clears columns for flag keys
   that actually correspond to a company_information column; a key with
   no mapping here (e.g. "moa_document", which flags the uploaded FILE
   itself, not a text column) is safely skipped. ================================ */
function blankFlaggedCompanyInfoFields($conn, $user_id, array $flaggedFields) {
    if (!$user_id || empty($flaggedFields)) return;

    // NOTE: the flag key "contact_middle_name" (matching moa_requests'
    // column name) maps to company_information's differently-named
    // "contact_middle_initial" column.
    $fieldColumnMap = [
        'company_name'        => 'company',
        'company_profile'     => 'company_profile',
        'company_address'     => 'company_address',
        'position'            => 'position',
        'contact_first_name'  => 'contact_first_name',
        'contact_middle_name' => 'contact_middle_initial',
        'contact_last_name'   => 'contact_last_name',
        'telephone'           => 'telephone',
    ];

    ensureCompanyInfoColumns($conn);

    foreach ($flaggedFields as $flagKey) {
        $col = $fieldColumnMap[$flagKey] ?? null;
        if (!$col) continue;
        $stmt = $conn->prepare("UPDATE company_information SET `$col`=NULL WHERE user_id=?");
        if ($stmt) {
            $stmt->bind_param("i", $user_id);
            $stmt->execute();
            $stmt->close();
            moaDebugLog('blankFlaggedCompanyInfoFields:cleared', ['user_id'=>$user_id,'flag'=>$flagKey,'column'=>$col]);
        }
    }
}

/* ================= HELPER: SAVE A REGENERATED MOA BLOB =================
   NEW (this adjustment) — saves freshly-regenerated PDF bytes into
   company_requirements as the company's current Pending MOA document,
   without touching moa_workflow_stage, moa_needs_revision,
   moa_flagged_fields, or moa_revision_comment (unlike
   transferMoaToRequirements(), which intentionally resets those — this
   save happens WHILE the row is already flagged as needing revision, and
   must leave that flag in place). ================================ */
function saveRegeneratedMoaBlob($conn, $user_id, $pdfBytes) {
    if (empty($pdfBytes) || !$user_id) return false;

    $moaType = resolveMoaRequirementType($conn, $user_id);
    $chk = $conn->prepare("SELECT id FROM company_requirements WHERE user_id=? AND requirement_type=?");
    if (!$chk) return false;
    $chk->bind_param("is", $user_id, $moaType); $chk->execute();
    $exists = $chk->get_result()->fetch_assoc(); $chk->close();

    if ($exists) {
        $stmt = $conn->prepare("UPDATE company_requirements SET requirement_type='moa_document', file_name=?, status='Pending' WHERE user_id=? AND requirement_type=?");
        if (!$stmt) return false;
        $null_blob = null;
        $stmt->bind_param("bis", $null_blob, $user_id, $moaType);
        $stmt->send_long_data(0, $pdfBytes);
        $ok = $stmt->execute();
        $stmt->close();
    } else {
        $stmt = $conn->prepare("INSERT INTO company_requirements (user_id, requirement_type, file_name, status, moa_workflow_stage) VALUES (?, 'moa_document', ?, 'Pending', 'pending')");
        if (!$stmt) return false;
        $null_blob = null;
        $stmt->bind_param("ib", $user_id, $null_blob);
        $stmt->send_long_data(1, $pdfBytes);
        $ok = $stmt->execute();
        $stmt->close();
    }
    return (bool)$ok;
}

/* ================= HELPER: RECREATE & RETURN THE MOA TO THE COMPANY =================
   NEW (this adjustment) — rebuilds the MOA PDF from this company's
   CURRENT company_information data (i.e. after blankFlaggedCompanyInfoFields()
   has already cleared the flagged field(s)) and saves it back as their
   working MOA document. Any blanked field renders as
   regBuildMOAStaticHTMLForDompdf()'s own bracketed placeholder text (e.g.
   "[COMPANY PROFILE / BRIEF DESCRIPTION]"), so the missing section is
   visibly obvious in the document itself once it's "returned" to the
   company. Non-fatal on failure (e.g. Dompdf unavailable) — mirrors
   company_register.php's own STEP 2d error handling: logged, but never
   blocks the rest of the flag-for-revision flow. ================================ */
function regenerateAndReturnMoaToCompany($conn, $user_id) {
    if (!$user_id) return false;

    ensureCompanyInfoColumns($conn);

    $ciq = $conn->prepare("SELECT company, company_profile, company_address, position, contact_first_name, contact_middle_initial, contact_last_name FROM company_information WHERE user_id=?");
    if (!$ciq) { moaDebugLog('regenerateAndReturnMoaToCompany:prepare_fail', ['user_id'=>$user_id,'error'=>$conn->error]); return false; }
    $ciq->bind_param("i", $user_id); $ciq->execute();
    $ci = $ciq->get_result()->fetch_assoc(); $ciq->close();
    if (!$ci) { moaDebugLog('regenerateAndReturnMoaToCompany:no_company_information', ['user_id'=>$user_id]); return false; }

    $repFullName = preg_replace('/\s+/', ' ', trim(
        ($ci['contact_first_name'] ?? '') . ' ' . ($ci['contact_middle_initial'] ?? '') . ' ' . ($ci['contact_last_name'] ?? '')
    ));

    try {
        $staticHtml = regBuildMOAStaticHTMLForDompdf([
            'moa_number'              => '',
            'moa_year'                => date('Y'),
            'company_name'            => $ci['company'] ?? '',
            'company_description'     => $ci['company_profile'] ?? '',
            'company_address'         => $ci['company_address'] ?? '',
            'representative_name'     => $repFullName,
            'representative_position' => $ci['position'] ?? '',
            'signing_date'            => '',
            'signing_place'           => '',
            'notary_city'             => '',
            'company_id'              => '',
        ]);

        $pdfBytes = regGenerateMoaPdfBytes($staticHtml);
        if ($pdfBytes === null || strlen($pdfBytes) === 0) {
            moaDebugLog('regenerateAndReturnMoaToCompany:pdf_generation_failed', ['user_id'=>$user_id]);
            return false;
        }

        $ok = saveRegeneratedMoaBlob($conn, $user_id, $pdfBytes);
        moaDebugLog('regenerateAndReturnMoaToCompany:saved', ['user_id'=>$user_id,'ok'=>$ok,'bytes'=>strlen($pdfBytes)]);
        return $ok;
    } catch (\Throwable $e) {
        moaDebugLog('regenerateAndReturnMoaToCompany:exception', ['user_id'=>$user_id,'message'=>$e->getMessage()]);
        return false;
    }
}


/* ================= HELPER: BUILD RE-CREATED MOA REQUEST ROW (FOR REVISION) =================
   NOTE: this helper is kept intact for reference/back-compat, but as of the
   adjustment below it is no longer called by ajax_moa_reject_send. The
   full "Reject & Send" flow now updates the existing moa_requests row
   in place (status='Rejected' + flagged_fields/rejection_notes) instead
   of deleting the row and re-creating a fresh "Pending" one with the
   flagged field(s) blanked out — see the ajax_moa_reject_send handler
   below for the reasoning. This function is left defined, unused, in
   case anything else in the codebase still references it.
   ================================ */
function buildRecreatedMoaRequestRow($conn, $moaRow, $flaggedFields) {
    $recreated = [
        'request_type'        => $moaRow['request_type'] ?? 'new',
        'company_name'        => $moaRow['company_name'] ?? '',
        'company_profile'     => $moaRow['company_profile'] ?? '',
        'company_address'     => $moaRow['company_address'] ?? '',
        'telephone'           => $moaRow['telephone'] ?? '',
        'position'            => $moaRow['position'] ?? '',
        'contact_first_name'  => $moaRow['contact_first_name'] ?? '',
        'contact_middle_name' => $moaRow['contact_middle_name'] ?? '',
        'contact_last_name'   => $moaRow['contact_last_name'] ?? '',
    ];

    if (is_array($flaggedFields)) {
        foreach ($flaggedFields as $flaggedKey) {
            if (array_key_exists($flaggedKey, $recreated)) {
                $recreated[$flaggedKey] = '';
            }
        }
    }

    moaDebugLog('buildRecreatedMoaRequestRow', ['flagged' => $flaggedFields, 'result' => $recreated]);
    return $recreated;
}

/* ================= HELPER: COPY MOA BLOB → company_requirements =================
   ── UPDATED (this adjustment): now accepts an extra $request_type argument
   (the original 'new' / 'existing' value from the moa_requests row) and
   persists it into the company_requirements row's new `request_type`
   column — for both the "row already exists" (UPDATE) and "row doesn't
   exist yet" (INSERT) branches — so it is automatically saved the moment
   the admin accepts the MOA, with no extra admin action required. Every
   other column/behavior in this function (file transfer, workflow-stage
   reset, folder copy, moa_requests row cleanup, overall status recompute)
   is unchanged.
   ── UPDATED (this adjustment): a new $deleteSourceRow parameter (default
   TRUE, i.e. the exact original behavior — nothing changes for any
   existing caller that doesn't pass it) controls whether the source
   moa_requests row is deleted after the transfer. The new automatic
   ingest path (autoIngestPendingMoaRequests(), see below) passes FALSE
   so the moa_requests row survives the transfer: it is still needed
   afterwards (a) as the data source for the "Flag for Revision" action
   now available on the merged "Pending for Review" stage in the New MOA
   table, and (b) as the single row moa_request.php's own reject/revise
   cycle already knows how to read — so that entire company-facing flow
   keeps working unmodified. This function also now resets the new
   moa_needs_revision / moa_flagged_fields / moa_revision_comment columns
   every time a transfer happens, so a fresh/resubmitted MOA never still
   shows a stale "Needs Revision" badge from a previous cycle.
   ================================ */
function transferMoaToRequirements($conn, $moa_user_id, $moa_pdf_blob, $moa_pdf_filename, $moa_id, $request_type = null, $deleteSourceRow = true) {
    if (empty($moa_pdf_blob) || !$moa_user_id) {
        moaDebugLog('transferMoaToRequirements:skip', ['reason'=>'no blob or no user_id','user_id'=>$moa_user_id]);
        return false;
    }
    moaDebugLog('transferMoaToRequirements:start', ['user_id'=>$moa_user_id,'moa_id'=>$moa_id,'blob_length'=>strlen($moa_pdf_blob),'filename'=>$moa_pdf_filename,'request_type'=>$request_type]);

    // ── UPDATED (this adjustment): also detect an existing legacy 'moa'
    // row (written by company_register.php's auto-generated MOA at
    // registration time — see resolveMoaRequirementType()) so accepting/
    // ingesting a moa_requests submission UPDATEs that same row (and
    // normalizes it to the canonical 'moa_document' key) instead of
    // leaving it behind as an orphaned, invisible duplicate.
    $existingMoaType = resolveMoaRequirementType($conn, $moa_user_id);
    $crCheck = $conn->prepare("SELECT id FROM company_requirements WHERE user_id=? AND requirement_type=?");
    if (!$crCheck) { moaDebugLog('transferMoaToRequirements:prepare_check_fail', ['error'=>$conn->error]); return false; }
    $crCheck->bind_param("is", $moa_user_id, $existingMoaType); $crCheck->execute();
    $crExists = $crCheck->get_result()->fetch_assoc(); $crCheck->close();

    if ($crExists) {
        // Reset to Pending with stage=pending (the merged "Pending for
        // Review" stage) so the in-table workflow restarts, clearing any
        // previous revision flag. Also (re)store the request_type so it
        // stays in sync with whichever MOA request was most recently
        // ingested for this company. requirement_type is normalized to
        // 'moa_document' here regardless of which key the row was found
        // under, so it's always canonical going forward.
        $crStmt = $conn->prepare("UPDATE company_requirements SET requirement_type='moa_document', file_name=?, status='Pending', remark=NULL, moa_workflow_stage='pending', moa_admin_comment=NULL, moa_schedule_datetime=NULL, request_type=?, moa_needs_revision=0, moa_flagged_fields=NULL, moa_revision_comment=NULL WHERE user_id=? AND requirement_type=?");
        if (!$crStmt) { moaDebugLog('transferMoaToRequirements:prepare_update_fail', ['error'=>$conn->error]); return false; }
        $null_blob = null;
        $crStmt->bind_param("bsis", $null_blob, $request_type, $moa_user_id, $existingMoaType);
        $crStmt->send_long_data(0, $moa_pdf_blob);
        $ok = $crStmt->execute();
        moaDebugLog('transferMoaToRequirements:update', ['ok'=>$ok,'error'=>$crStmt->error?:null,'request_type'=>$request_type,'found_under'=>$existingMoaType]);
        $crStmt->close();
    } else {
        $crStmt = $conn->prepare("INSERT INTO company_requirements (user_id, requirement_type, file_name, status, moa_workflow_stage, request_type) VALUES (?, 'moa_document', ?, 'Pending', 'pending', ?)");
        if (!$crStmt) { moaDebugLog('transferMoaToRequirements:prepare_insert_fail', ['error'=>$conn->error]); return false; }
        $null_blob = null;
        $crStmt->bind_param("ibs", $moa_user_id, $null_blob, $request_type);
        $crStmt->send_long_data(1, $moa_pdf_blob);
        $ok = $crStmt->execute();
        moaDebugLog('transferMoaToRequirements:insert', ['ok'=>$ok,'error'=>$crStmt->error?:null,'request_type'=>$request_type]);
        $crStmt->close();
    }

    // NEW (this adjustment): a freshly (re)submitted MOA never carries a stale "revision complied" detail.
    try {
        $conn->query("ALTER TABLE company_requirements ADD COLUMN IF NOT EXISTS moa_complied_detail TEXT NULL");
        $conn->query("UPDATE company_requirements SET moa_complied_detail=NULL WHERE user_id=" . (int)$moa_user_id . " AND requirement_type IN ('moa_document','moa')");
    } catch (\Throwable $e) { /* non-critical */ }

    saveMoaBlobToFolder($conn, $moa_user_id, $moa_pdf_blob, $moa_pdf_filename);

    if ($deleteSourceRow) {
        // Delete moa_requests row (original accept-flow behavior — kept
        // exactly as-is for any caller that still wants it, e.g. the
        // legacy ajax_moa_drawer_action accept endpoint below).
        $delStmt = $conn->prepare("DELETE FROM moa_requests WHERE id=?");
        if ($delStmt) { $delStmt->bind_param("i", $moa_id); $delOk = $delStmt->execute(); moaDebugLog('transferMoaToRequirements:delete_moa_row', ['moa_id'=>$moa_id,'ok'=>$delOk]); $delStmt->close(); }
    }

    recomputeCompanyValidationStatus($conn, $moa_user_id);
    return true;
}

/* ================= HELPER: UPSERT company_information FROM A MOA ROW =================
   NEW (this adjustment) — pure extraction of the company_information
   upsert block that used to live inline inside the ajax_moa_drawer_action
   "accept" branch. Behavior is byte-for-byte identical; it is only pulled
   out into its own function so BOTH the legacy accept endpoint and the
   new autoIngestPendingMoaRequests() (below) share the exact same logic
   instead of two copies that could drift apart.
   ================================ */
function upsertCompanyInformationFromMoaRow($conn, $moa_user_id, $moa_company_name, $moa_contact_first, $moa_contact_middle, $moa_contact_last, $moa_company_address, $moa_position, $moa_telephone) {
    ensureCompanyInfoColumns($conn);

    $ciCheck = $conn->prepare("SELECT user_id FROM company_information WHERE user_id=?");
    $ciCheck->bind_param("i", $moa_user_id); $ciCheck->execute();
    $ciExists = $ciCheck->get_result()->fetch_assoc(); $ciCheck->close();

    if ($ciExists) {
        $ciUpd = $conn->prepare("UPDATE company_information SET company=?, contact_first_name=?, contact_middle_initial=?, contact_last_name=?, telephone=? WHERE user_id=?");
        if ($ciUpd) { $ciUpd->bind_param("sssssi",$moa_company_name,$moa_contact_first,$moa_contact_middle,$moa_contact_last,$moa_telephone,$moa_user_id); $ciUpd->execute(); $ciUpd->close(); }
    } else {
        $ciIns = $conn->prepare("INSERT INTO company_information (user_id,company,contact_first_name,contact_middle_initial,contact_last_name,company_address,position,telephone) VALUES (?,?,?,?,?,?,?,?)");
        if ($ciIns) { $ciIns->bind_param("isssssss",$moa_user_id,$moa_company_name,$moa_contact_first,$moa_contact_middle,$moa_contact_last,$moa_company_address,$moa_position,$moa_telephone); $ok=$ciIns->execute(); $ciIns->close(); if(!$ok){$eid=intval($moa_user_id);$eco=$conn->real_escape_string($moa_company_name);$conn->query("INSERT IGNORE INTO company_information (user_id,company) VALUES ($eid,'$eco')");} }
    }
}

/* ================= HELPER: AUTO-INGEST NEW MOA REQUESTS (NO MORE "ACCEPT" STEP) =================
   NEW (this adjustment) — replaces the old requirement that an admin
   manually click "Accept" on every MOA request in the drawer before it
   would show up in the "New MOA — Requirements & MOA Workflow" table.
   Every moa_requests row sitting at status='Pending' that hasn't been
   ingested yet (transferred=0) is picked up here — the very first time
   this file runs after it was submitted/resubmitted — and is:
     1. Used to upsert this company's company_information record (same
        fields the old manual "Accept" action used to sync).
     2. Copied into company_requirements via transferMoaToRequirements()
        with $deleteSourceRow=false, so it lands directly in the "New"
        table on the merged "Pending for Review" stage, and the
        moa_requests row itself is kept intact (needed by the "Flag for
        Revision" action and by moa_request.php's own revise flow).
     3. Marked transferred=1 so it is never re-processed/re-reset by a
        later poll, and admin_viewed=0 so it shows up as a fresh
        notification in the MOA Requests inbox — see the updated
        ajax_fetch_moa_requests handler and the drawer's
        "View Request" flow further down.
   This function is fully self-contained (guards every column/table it
   touches) because it is now called very early in the request lifecycle
   — before the rest of the file's own schema guards have necessarily run
   yet — from both normal page loads and every AJAX call this file
   handles (see the call near the top of the file).
   ================================ */
function autoIngestPendingMoaRequests($conn) {
    cv_ensure_moa_requests_table($conn);   // CLEAN-UP (audit): shared definition, see cv_ensure_moa_requests_table()
    $conn->query("ALTER TABLE moa_requests ADD COLUMN IF NOT EXISTS moa_workflow_status VARCHAR(30) DEFAULT 'pending'");
    $conn->query("ALTER TABLE moa_requests ADD COLUMN IF NOT EXISTS telephone VARCHAR(30) NULL AFTER contact_last_name");
    $conn->query("ALTER TABLE moa_requests ADD COLUMN IF NOT EXISTS revision_flags TEXT NULL");
    $conn->query("ALTER TABLE moa_requests ADD COLUMN IF NOT EXISTS revision_comment TEXT NULL");
    $conn->query("ALTER TABLE moa_requests ADD COLUMN IF NOT EXISTS is_revision TINYINT(1) NOT NULL DEFAULT 0");
    $conn->query("ALTER TABLE moa_requests ADD COLUMN IF NOT EXISTS flagged_fields TEXT NULL");
    $conn->query("ALTER TABLE moa_requests ADD COLUMN IF NOT EXISTS rejection_notes TEXT NULL");
    $conn->query("ALTER TABLE moa_requests ADD COLUMN IF NOT EXISTS transferred TINYINT(1) NOT NULL DEFAULT 0");
    $conn->query("ALTER TABLE moa_requests ADD COLUMN IF NOT EXISTS admin_viewed TINYINT(1) NOT NULL DEFAULT 0");
    // ── NEW (this adjustment): notif_type distinguishes what KIND of
    // notification a moa_requests row represents in the MOA Requests
    // inbox — 'new_request' (the default, for every row this function and
    // the real moa_request.php submission flow already produce),
    // 'revision_complied', 'schedule_agreed', or 'schedule_declined' (the
    // three new event types — see detectAndNotifyComplianceEvents()
    // below). Powers the inbox's new type filter dropdown.
    $conn->query("ALTER TABLE moa_requests ADD COLUMN IF NOT EXISTS notif_type VARCHAR(30) NOT NULL DEFAULT 'new_request'");
    $conn->query("ALTER TABLE company_requirements ADD COLUMN IF NOT EXISTS moa_workflow_stage VARCHAR(30) DEFAULT 'pending'");
    $conn->query("ALTER TABLE company_requirements ADD COLUMN IF NOT EXISTS moa_admin_comment TEXT NULL");
    $conn->query("ALTER TABLE company_requirements ADD COLUMN IF NOT EXISTS moa_schedule_datetime DATETIME NULL");
    $conn->query("ALTER TABLE company_requirements ADD COLUMN IF NOT EXISTS moa_schedule_status VARCHAR(20) NULL");
    $conn->query("ALTER TABLE company_requirements ADD COLUMN IF NOT EXISTS moa_schedule_decline_reason TEXT NULL");
    $conn->query("ALTER TABLE company_requirements ADD COLUMN IF NOT EXISTS moa_proposed_datetime DATETIME NULL");
    $conn->query("ALTER TABLE company_requirements ADD COLUMN IF NOT EXISTS request_type VARCHAR(20) NULL");
    $conn->query("ALTER TABLE company_requirements ADD COLUMN IF NOT EXISTS moa_needs_revision TINYINT(1) NOT NULL DEFAULT 0");
    $conn->query("ALTER TABLE company_requirements ADD COLUMN IF NOT EXISTS moa_flagged_fields TEXT NULL");
    $conn->query("ALTER TABLE company_requirements ADD COLUMN IF NOT EXISTS moa_revision_comment TEXT NULL");

    $res = $conn->query("SELECT id, user_id, company_name, company_address, telephone, position, contact_first_name, contact_middle_name, contact_last_name, request_type, moa_pdf, moa_pdf_filename FROM moa_requests WHERE status='Pending' AND transferred=0 AND moa_pdf IS NOT NULL AND LENGTH(moa_pdf)>0 AND user_id IS NOT NULL");
    if (!$res) { moaDebugLog('autoIngestPendingMoaRequests:query_fail', ['error'=>$conn->error]); return; }

    while ($moaRow = $res->fetch_assoc()) {
        $moa_id      = (int)$moaRow['id'];
        $moa_user_id = (int)($moaRow['user_id'] ?? 0);

        if ($moa_user_id > 0) {
            upsertCompanyInformationFromMoaRow(
                $conn, $moa_user_id,
                $moaRow['company_name'] ?? '',
                $moaRow['contact_first_name'] ?? '',
                $moaRow['contact_middle_name'] ?? '',
                $moaRow['contact_last_name'] ?? '',
                $moaRow['company_address'] ?? '',
                $moaRow['position'] ?? '',
                $moaRow['telephone'] ?? ''
            );

            transferMoaToRequirements(
                $conn, $moa_user_id, $moaRow['moa_pdf'], $moaRow['moa_pdf_filename'] ?? '',
                $moa_id, $moaRow['request_type'] ?? null, false
            );

            // Same courtesy email the old manual "Accept" action used to
            // send, letting the company know their MOA is now in the
            // requirements table workflow.
            sendMoaStageEmail($conn, $moa_user_id, 'pending', '', null);
        }

        // Mark ingested + surface it as a fresh "new entry" notification.
        $upd = $conn->prepare("UPDATE moa_requests SET transferred=1, admin_viewed=0 WHERE id=?");
        if ($upd) { $upd->bind_param("i", $moa_id); $upd->execute(); $upd->close(); }

        moaDebugLog('autoIngestPendingMoaRequests:ingested', ['moa_id'=>$moa_id,'user_id'=>$moa_user_id]);
    }
}

/* ================= HELPER: NOTIFY ADMIN OF MOA ENTRIES CREATED WITHOUT moa_requests =================
   NEW (this adjustment) — the MOA Requests notification inbox is entirely
   driven by the moa_requests table (see autoIngestPendingMoaRequests()
   above and the ajax_fetch_moa_notifications/ajax_moa_mark_notification_viewed
   handlers further down). Two real, common paths put a brand-new MOA
   document straight into company_requirements WITHOUT ever creating a
   moa_requests row at all, so the admin was never notified for either:
     1. company_register.php's own registration flow, which auto-
        generates and saves a "Request New MOA" company's MOA directly
        (requirement_type='moa') the moment the account is created.
     2. admin_company_list.php's manual "Add Company" form / XLSX import,
        for a "New" request-type company — that flow creates the account
        with NO MOA at all, and the company later creates it themselves
        through CompanyForm.php's own self-service "Create MOA" action
        (also saved straight into company_requirements, no moa_requests
        row involved).

   This function closes that gap without touching either of those other
   files: it watches company_requirements directly for an MOA document
   that (a) has no moa_requests row at all for that company — the
   signature of both paths above — and (b) is still sitting exactly where
   it started (status='Pending', moa_workflow_stage='pending', i.e. the
   admin hasn't touched it yet), so a company whose MOA was already
   reviewed long before this fix existed is never retroactively flagged
   as "new". The moa_admin_notified column (guarded/added here) makes
   sure each such row is only ever processed once.

   Once found, a lightweight moa_requests row is inserted for it —
   transferred=1, admin_viewed=0, populated from that company's own
   company_information — which is all ajax_fetch_moa_notifications needs
   to surface it in the inbox exactly like any other new request, with a
   working "View Request" action; it also then becomes available as the
   data source for ajax_moa_table_flag_revision's "Flag for Revision"
   action (previously that action had to fall back to
   company_information alone for these companies — see its own docblock —
   this makes that fallback path unnecessary for anything ingested from
   here on, while leaving it in place for safety). ================================ */
function detectAndNotifyDirectMoaEntries($conn) {
    $conn->query("ALTER TABLE company_requirements ADD COLUMN IF NOT EXISTS moa_admin_notified TINYINT(1) NOT NULL DEFAULT 0");
    cv_ensure_moa_requests_table($conn);   // CLEAN-UP (audit): shared definition, see cv_ensure_moa_requests_table()
    $conn->query("ALTER TABLE moa_requests ADD COLUMN IF NOT EXISTS telephone VARCHAR(30) NULL AFTER contact_last_name");
    $conn->query("ALTER TABLE moa_requests ADD COLUMN IF NOT EXISTS transferred TINYINT(1) NOT NULL DEFAULT 0");
    $conn->query("ALTER TABLE moa_requests ADD COLUMN IF NOT EXISTS admin_viewed TINYINT(1) NOT NULL DEFAULT 0");
    $conn->query("ALTER TABLE moa_requests ADD COLUMN IF NOT EXISTS notif_type VARCHAR(30) NOT NULL DEFAULT 'new_request'");

    $res = $conn->query("
        SELECT cr.id, cr.user_id
        FROM company_requirements cr
        WHERE cr.requirement_type IN ('moa_document','moa')
          AND cr.file_name IS NOT NULL AND LENGTH(cr.file_name) > 0
          AND cr.status = 'Pending'
          AND (cr.moa_workflow_stage IS NULL OR cr.moa_workflow_stage = 'pending')
          AND (cr.moa_admin_notified IS NULL OR cr.moa_admin_notified = 0)
          AND NOT EXISTS (SELECT 1 FROM moa_requests mr WHERE mr.user_id = cr.user_id)
    ");
    if (!$res) { moaDebugLog('detectAndNotifyDirectMoaEntries:query_fail', ['error'=>$conn->error]); return; }

    while ($row = $res->fetch_assoc()) {
        $uid = (int)($row['user_id'] ?? 0);
        $crId = (int)$row['id'];
        if ($uid <= 0) continue;

        // Source this company's text fields from company_information —
        // the exact same fields a real moa_requests row would carry —
        // so the notification card and any later "Flag for Revision"
        // action have real data to show/work with.
        $ciq = $conn->prepare("SELECT company, company_address, telephone, position, contact_first_name, contact_middle_initial, contact_last_name FROM company_information WHERE user_id=?");
        $ci = [];
        if ($ciq) {
            $ciq->bind_param("i", $uid); $ciq->execute();
            $ci = $ciq->get_result()->fetch_assoc() ?: [];
            $ciq->close();
        }

        $requestType = resolveCompanyRequestType($conn, $uid);

        $ins = $conn->prepare("INSERT INTO moa_requests
            (user_id, request_type, company_name, company_address, telephone, position, contact_first_name, contact_middle_name, contact_last_name, status, moa_workflow_status, transferred, admin_viewed, notif_type, submitted_at)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 'Pending', 'pending', 1, 0, 'new_request', NOW())");
        if ($ins) {
            $companyName = $ci['company'] ?? '';
            $companyAddr = $ci['company_address'] ?? '';
            $telephone   = $ci['telephone'] ?? '';
            $position    = $ci['position'] ?? '';
            $firstN      = $ci['contact_first_name'] ?? '';
            $middleN     = $ci['contact_middle_initial'] ?? '';
            $lastN       = $ci['contact_last_name'] ?? '';
            $ins->bind_param(
                "issssssss",
                $uid, $requestType, $companyName, $companyAddr, $telephone,
                $position, $firstN, $middleN, $lastN
            );
            $ins->execute();
            $ins->close();
        } else {
            moaDebugLog('detectAndNotifyDirectMoaEntries:insert_prepare_fail', ['user_id'=>$uid,'error'=>$conn->error]);
        }

        $upd = $conn->prepare("UPDATE company_requirements SET moa_admin_notified=1 WHERE id=?");
        if ($upd) { $upd->bind_param("i", $crId); $upd->execute(); $upd->close(); }

        moaDebugLog('detectAndNotifyDirectMoaEntries:notified', ['user_id'=>$uid,'company_requirements_id'=>$crId,'request_type'=>$requestType]);
    }
}

/* ================= HELPER: NOTIFY ADMIN OF COMPANY COMPLIANCE EVENTS =================
   NEW (this adjustment) — three more things a company can do entirely on
   CompanyForm.php that the admin should be told about, the exact same
   way a brand-new MOA request already is:
     1. Complying with a flagged MOA revision (fixing the flagged
        section(s) and resubmitting — see submit_moa_revision there).
     2. Agreeing to a signing schedule the admin proposed (see
        submit_moa_schedule_response, action=agree).
     3. Declining a proposed schedule and suggesting an alternative
        instead (same handler, action=decline).

   None of those three ever create or touch a moa_requests row — they
   only update company_requirements directly. CompanyForm.php marks the
   affected row with moa_pending_admin_notice (one of
   'revision_complied' / 'schedule_agreed' / 'schedule_declined') the
   instant each event happens; this function watches for that marker on
   every request (the same "poll company_requirements for a marker, react,
   clear it" pattern detectAndNotifyDirectMoaEntries() above already
   uses) and turns it into a real notification the exact same way: a
   lightweight moa_requests row (transferred=1, admin_viewed=0), tagged
   with the matching notif_type so the inbox can show/filter it
   distinctly from a plain "new request" — see
   ajax_fetch_moa_notifications, buildMoaNotificationCard(), and the new
   type filter dropdown further down/in the drawer markup. ================================ */
function detectAndNotifyComplianceEvents($conn) {
    $conn->query("ALTER TABLE company_requirements ADD COLUMN IF NOT EXISTS moa_pending_admin_notice VARCHAR(30) NULL");
    // ── NEW (this adjustment): WHAT the company complied with. CompanyForm.php erases moa_flagged_fields /
    // moa_revision_comment in the very same step that raises the 'revision_complied' notice, so by the time this
    // function sees it the list of flagged sections is already gone. The flag handler therefore also keeps a
    // copy (moa_last_flagged_fields / moa_last_revision_comment) that nothing else clears; on 'revision_complied'
    // this function turns it into moa_complied_detail (sections + the company's NEW values) on the MOA row — shown
    // on the MOA card and in the Review MOA preview — and into notif_detail on the notification itself.
    $conn->query("ALTER TABLE company_requirements ADD COLUMN IF NOT EXISTS moa_last_flagged_fields TEXT NULL");
    $conn->query("ALTER TABLE company_requirements ADD COLUMN IF NOT EXISTS moa_last_revision_comment TEXT NULL");
    $conn->query("ALTER TABLE company_requirements ADD COLUMN IF NOT EXISTS moa_complied_detail TEXT NULL");
    $conn->query("ALTER TABLE moa_requests ADD COLUMN IF NOT EXISTS notif_detail TEXT NULL");
    cv_ensure_moa_requests_table($conn);   // CLEAN-UP (audit): shared definition, see cv_ensure_moa_requests_table()
    $conn->query("ALTER TABLE moa_requests ADD COLUMN IF NOT EXISTS telephone VARCHAR(30) NULL AFTER contact_last_name");
    $conn->query("ALTER TABLE moa_requests ADD COLUMN IF NOT EXISTS transferred TINYINT(1) NOT NULL DEFAULT 0");
    $conn->query("ALTER TABLE moa_requests ADD COLUMN IF NOT EXISTS admin_viewed TINYINT(1) NOT NULL DEFAULT 0");
    $conn->query("ALTER TABLE moa_requests ADD COLUMN IF NOT EXISTS notif_type VARCHAR(30) NOT NULL DEFAULT 'new_request'");

    $res = $conn->query("
        SELECT id, user_id, moa_pending_admin_notice
        FROM company_requirements
        WHERE requirement_type IN ('moa_document','moa')
          AND moa_pending_admin_notice IS NOT NULL
          AND moa_pending_admin_notice <> ''
    ");
    if (!$res) { moaDebugLog('detectAndNotifyComplianceEvents:query_fail', ['error'=>$conn->error]); return; }

    $allowedEvents = ['revision_complied', 'schedule_agreed', 'schedule_declined'];

    while ($row = $res->fetch_assoc()) {
        $uid      = (int)($row['user_id'] ?? 0);
        $crId     = (int)$row['id'];
        $eventType = $row['moa_pending_admin_notice'] ?? '';
        if ($uid <= 0 || !in_array($eventType, $allowedEvents, true)) {
            // Unknown/stale value — clear it so it doesn't loop forever,
            // but don't fabricate a notification for it.
            $clr = $conn->prepare("UPDATE company_requirements SET moa_pending_admin_notice=NULL WHERE id=?");
            if ($clr) { $clr->bind_param("i", $crId); $clr->execute(); $clr->close(); }
            continue;
        }

        // Source this company's text fields from company_information —
        // same as every other notification-creating function in this
        // file — so the notification card and any later "Flag for
        // Revision" action have real data to show/work with.
        $ciq = $conn->prepare("SELECT company, company_address, telephone, position, contact_first_name, contact_middle_initial, contact_last_name FROM company_information WHERE user_id=?");
        $ci = [];
        if ($ciq) {
            $ciq->bind_param("i", $uid); $ciq->execute();
            $ci = $ciq->get_result()->fetch_assoc() ?: [];
            $ciq->close();
        }

        $requestType = resolveCompanyRequestType($conn, $uid);

        // NEW (this adjustment): for a completed revision, record which sections the company updated (see above).
        $notifDetailJson = null;
        if ($eventType === 'revision_complied') {
            $complianceDetail = cvBuildComplianceDetail($conn, $uid, $crId);
            if ($complianceDetail !== null) $notifDetailJson = json_encode($complianceDetail);
        }

        $ins = $conn->prepare("INSERT INTO moa_requests
            (user_id, request_type, company_name, company_address, telephone, position, contact_first_name, contact_middle_name, contact_last_name, status, moa_workflow_status, transferred, admin_viewed, notif_type, notif_detail, submitted_at)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 'Pending', 'pending', 1, 0, ?, ?, NOW())");
        if ($ins) {
            $companyName = $ci['company'] ?? '';
            $companyAddr = $ci['company_address'] ?? '';
            $telephone   = $ci['telephone'] ?? '';
            $position    = $ci['position'] ?? '';
            $firstN      = $ci['contact_first_name'] ?? '';
            $middleN     = $ci['contact_middle_initial'] ?? '';
            $lastN       = $ci['contact_last_name'] ?? '';
            $ins->bind_param(
                "issssssssss",
                $uid, $requestType, $companyName, $companyAddr, $telephone,
                $position, $firstN, $middleN, $lastN, $eventType, $notifDetailJson
            );
            $ins->execute();
            $ins->close();
        } else {
            moaDebugLog('detectAndNotifyComplianceEvents:insert_prepare_fail', ['user_id'=>$uid,'error'=>$conn->error]);
        }

        // NEW (this adjustment): keep the same detail on the MOA row itself, so the MOA card and the Review MOA
        // preview can point at exactly what was updated for as long as the admin has not acted on it yet.
        if ($notifDetailJson !== null) {
            $cdStmt = $conn->prepare("UPDATE company_requirements SET moa_complied_detail=? WHERE id=?");
            if ($cdStmt) { $cdStmt->bind_param("si", $notifDetailJson, $crId); $cdStmt->execute(); $cdStmt->close(); }
        }

        $upd = $conn->prepare("UPDATE company_requirements SET moa_pending_admin_notice=NULL WHERE id=?");
        if ($upd) { $upd->bind_param("i", $crId); $upd->execute(); $upd->close(); }

        moaDebugLog('detectAndNotifyComplianceEvents:notified', ['user_id'=>$uid,'company_requirements_id'=>$crId,'event_type'=>$eventType]);
    }
}

/* ================= HELPER: DETECT + NOTIFY COMPANY REQUIREMENT UPLOADS =================
   NEW (this adjustment) — a company uploading (or re-uploading) compliance
   documents on CompanyForm.php never touched any notification before: the
   admin only saw it after a manual refresh, with nothing in the inbox to say
   it had happened. This closes that gap the same way the other detectors
   above do — it watches company_requirements directly and runs on every
   request — but WITHOUT needing CompanyForm.php to change:

   CompanyForm.php saves a submission by deleting the requirement's old rows
   and inserting each file as a NEW row, so ids only ever grow. A single
   high-water mark (company_requirement_upload_watch.last_req_id) is therefore
   enough to know exactly which rows are new. Each run:
     1. seeds the mark to the CURRENT highest id the first time it ever runs,
        so nothing that was already on record is retroactively announced;
     2. claims the id range (last, current-max] with an atomic compare-and-swap
        on that row, so two requests (or two admins) running at once can never
        announce the same upload twice;
     3. groups the new compliance rows (MOA documents excluded — they have
        their own flow) by company and requirement, and records one
        notification per company: a JSON list of {key, label, files}.
   A company that already has a still-unviewed notification created/updated
   within the last 20 seconds gets its list MERGED into it instead of a second
   entry — one submission of many files can be seen half-written by a poll and
   would otherwise announce itself twice (the rest of it is always picked up by
   the very next check, at most ~15s later, so 20s is enough to cover that).
   Any later, separate upload creates a fresh notification — and therefore its
   own popup.
   Only live (non-archived) company accounts are announced. ================================ */
function cvEnsureReqUploadTables($conn) {
    static $done = false;
    if ($done) return;
    $done = true;
    $conn->query("CREATE TABLE IF NOT EXISTS company_requirement_upload_watch (id TINYINT NOT NULL PRIMARY KEY, last_req_id INT NOT NULL DEFAULT 0)");
    $conn->query("CREATE TABLE IF NOT EXISTS company_requirement_upload_notifications (id INT AUTO_INCREMENT PRIMARY KEY, user_id INT NOT NULL, detail TEXT NULL, created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP, updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP, admin_viewed TINYINT(1) NOT NULL DEFAULT 0, KEY idx_req_notif_viewed (admin_viewed), KEY idx_req_notif_user (user_id))");
}

function cvCountRequirementFiles($conn, $userId, $requirementType) {
    $q = $conn->prepare("SELECT COUNT(*) AS n FROM company_requirements WHERE user_id=? AND requirement_type=? AND file_name IS NOT NULL AND LENGTH(file_name) > 0");
    if (!$q) return 0;
    $q->bind_param("is", $userId, $requirementType);
    $q->execute();
    $row = $q->get_result()->fetch_assoc();
    $q->close();
    return (int)($row['n'] ?? 0);
}

function detectAndNotifyRequirementUploads($conn) {
    global $companyReqLabels;
    try {
        cvEnsureReqUploadTables($conn);
        $conn->query("ALTER TABLE users ADD COLUMN IF NOT EXISTS co_is_archived TINYINT(1) NOT NULL DEFAULT 0");

        // 1) First run ever: start from what is already on record.
        $conn->query("INSERT IGNORE INTO company_requirement_upload_watch (id, last_req_id) SELECT 1, COALESCE(MAX(id),0) FROM company_requirements");

        $wRes = $conn->query("SELECT last_req_id FROM company_requirement_upload_watch WHERE id=1");
        $wRow = $wRes ? $wRes->fetch_assoc() : null;
        if (!$wRow) return;
        $last = (int)$wRow['last_req_id'];

        $mRes = $conn->query("SELECT COALESCE(MAX(id),0) AS mx FROM company_requirements");
        $mRow = $mRes ? $mRes->fetch_assoc() : null;
        $curMax = (int)($mRow['mx'] ?? 0);
        if ($curMax === $last) return;

        // 2) Claim (last, curMax] atomically — only the request whose UPDATE
        //    actually changes the row goes on to announce it.
        $claim = $conn->prepare("UPDATE company_requirement_upload_watch SET last_req_id=? WHERE id=1 AND last_req_id=?");
        if (!$claim) return;
        $claim->bind_param("ii", $curMax, $last);
        $claim->execute();
        $won = ($claim->affected_rows === 1);
        $claim->close();
        if (!$won) return;
        // The table shrank / was restored from a backup: the mark has just been
        // pulled back down to match; there is nothing new to announce.
        if ($curMax < $last) return;

        // 3) New compliance rows in that range, grouped by company + requirement.
        $lo = $last; $hi = $curMax;
        $res = $conn->query("SELECT user_id, requirement_type FROM company_requirements WHERE id > $lo AND id <= $hi AND requirement_type NOT IN ('moa_document','moa') AND user_id IS NOT NULL AND file_name IS NOT NULL AND LENGTH(file_name) > 0 GROUP BY user_id, requirement_type");
        if (!$res) return;
        $byUser = [];
        while ($r = $res->fetch_assoc()) {
            $byUser[(int)$r['user_id']][] = (string)$r['requirement_type'];
        }

        foreach ($byUser as $uid => $types) {
            $uq = $conn->prepare("SELECT role, co_is_archived FROM users WHERE id=?");
            if (!$uq) continue;
            $uq->bind_param("i", $uid); $uq->execute();
            $u = $uq->get_result()->fetch_assoc(); $uq->close();
            if (!$u || ($u['role'] ?? '') !== 'company' || !empty($u['co_is_archived'])) continue;

            $detailByKey = [];
            foreach ($types as $tKey) {
                $files = cvCountRequirementFiles($conn, $uid, $tKey);
                if ($files < 1) continue;
                $detailByKey[$tKey] = ['key' => $tKey, 'label' => ($companyReqLabels[$tKey] ?? $tKey), 'files' => $files];
            }
            if (empty($detailByKey)) continue;

            // Merge into a still-unviewed notification from the last 20 seconds, if any (see the docblock above).
            $mq = $conn->prepare("SELECT id, detail FROM company_requirement_upload_notifications WHERE user_id=? AND admin_viewed=0 AND updated_at >= (NOW() - INTERVAL 20 SECOND) ORDER BY id DESC LIMIT 1");
            $existing = null;
            if ($mq) {
                $mq->bind_param("i", $uid); $mq->execute();
                $existing = $mq->get_result()->fetch_assoc();
                $mq->close();
            }

            if ($existing) {
                $prev = json_decode($existing['detail'] ?? '', true);
                if (is_array($prev)) {
                    foreach ($prev as $p) {
                        if (!empty($p['key']) && !isset($detailByKey[$p['key']])) {
                            $files = cvCountRequirementFiles($conn, $uid, $p['key']);
                            if ($files > 0) $detailByKey[$p['key']] = ['key' => $p['key'], 'label' => ($p['label'] ?? $p['key']), 'files' => $files];
                        }
                    }
                }
                $json = json_encode(array_values($detailByKey));
                $upd = $conn->prepare("UPDATE company_requirement_upload_notifications SET detail=?, updated_at=NOW() WHERE id=?");
                if ($upd) { $eid = (int)$existing['id']; $upd->bind_param("si", $json, $eid); $upd->execute(); $upd->close(); }
            } else {
                $json = json_encode(array_values($detailByKey));
                $ins = $conn->prepare("INSERT INTO company_requirement_upload_notifications (user_id, detail, admin_viewed) VALUES (?, ?, 0)");
                if ($ins) { $ins->bind_param("is", $uid, $json); $ins->execute(); $ins->close(); }
            }
            moaDebugLog('detectAndNotifyRequirementUploads:notified', ['user_id' => $uid, 'requirements' => array_keys($detailByKey), 'merged' => (bool)$existing]);
        }
    } catch (\Throwable $e) {
        moaDebugLog('detectAndNotifyRequirementUploads:error', ['error' => $e->getMessage()]);
    }
}

// How many requirement-upload notifications the admin hasn't acknowledged yet
// (added onto the inbox badge / pending counts wherever those are computed).
function cvReqUploadUnviewedCount($conn) {
    try {
        $r = $conn->query("SELECT COUNT(*) AS total FROM company_requirement_upload_notifications WHERE admin_viewed=0");
        $row = $r ? $r->fetch_assoc() : null;
        return (int)($row['total'] ?? 0);
    } catch (\Throwable $e) { return 0; }
}

function cvMarkReqUploadNotificationViewed($conn, $notifId) {
    $notifId = (int)$notifId;
    if ($notifId <= 0) return false;
    try {
        $s = $conn->prepare("UPDATE company_requirement_upload_notifications SET admin_viewed=1 WHERE id=?");
        if (!$s) return false;
        $s->bind_param("i", $notifId);
        $ok = $s->execute();
        $s->close();
        return (bool)$ok;
    } catch (\Throwable $e) { return false; }
}

// The un-viewed upload notifications, shaped exactly like the MOA notification
// rows ajax_fetch_moa_notifications already returns (plus a `detail` list), so
// the inbox can render them alongside those. `_ts` is only for merge-sorting.
function cvFetchReqUploadNotifications($conn) {
    $out = [];
    try {
        $res = $conn->query("SELECT n.id, n.user_id, n.detail, n.updated_at, ci.company, ci.company_address, ci.position, ci.contact_first_name, ci.contact_middle_initial, ci.contact_last_name, u.first_name AS u_first, u.last_name AS u_last FROM company_requirement_upload_notifications n LEFT JOIN company_information ci ON ci.user_id = n.user_id LEFT JOIN users u ON u.id = n.user_id WHERE n.admin_viewed=0 ORDER BY n.updated_at DESC, n.id DESC");
        if (!$res) return $out;
        while ($r = $res->fetch_assoc()) {
            $detail = json_decode($r['detail'] ?? '', true);
            if (!is_array($detail)) $detail = [];
            $name = trim((string)($r['company'] ?? ''));
            if ($name === '') $name = trim(($r['u_first'] ?? '') . ' ' . ($r['u_last'] ?? ''));
            $ts = $r['updated_at'] ? strtotime($r['updated_at'] ?? '') : 0;
            $out[] = [
                'id'              => CV_REQ_NOTIF_ID_OFFSET + (int)$r['id'],
                'user_id'         => (int)$r['user_id'],
                'request_type'    => '',
                'company_name'    => $name,
                'company_address' => $r['company_address'] ?? '',
                'position'        => $r['position'] ?? '',
                'contact_name'    => trim(preg_replace('/\s+/', ' ', ($r['contact_first_name'] ?? '') . ' ' . ($r['contact_middle_initial'] ?? '') . ' ' . ($r['contact_last_name'] ?? ''))),
                'submitted_at'    => $ts ? date('M d, Y g:i A', $ts) : '',
                'notif_type'      => 'requirement_uploaded',
                'detail'          => $detail,
                '_ts'             => $ts,
            ];
        }
    } catch (\Throwable $e) { return []; }
    return $out;
}

/* ================= HELPERS: REVISION-COMPLIANCE DETAIL + NOTIFICATIONS THAT CLEAR THEMSELVES =================
   NEW (this adjustment) —
   (1) cvBuildComplianceDetail(): when a company complies with a "Flag for Revision", the sections it fixed were
       recorded by the flag handler (moa_last_flagged_fields — nothing on the company's side erases that copy). This
       pairs each one with the company's NEW value (read from company_information, which is where CompanyForm.php
       saves it), so the notification, the MOA card and the Review MOA preview can say exactly what to look at. An
       MOA flagged before this existed has no such copy; for those the flags kept on the company's moa_requests row
       by the same flag handler are used instead.
   (2) cvOnAdminMoaAction(): acting on a company's MOA (approving it, setting / accepting a signing schedule, flagging
       it again, marking it done) settles every notification about that MOA, so they leave the Notification Inbox by
       themselves — and the "complied" detail, which only matters until the admin has acted on it, is cleared.
   (3) cvResolveRequirementUploadNotification() / cvRestoreRequirementUploadNotifications(): the same for a requirement
       the admin verifies or rejects — it is taken off its "Requirement Uploaded" notification, which disappears once
       nothing is left on it. What was taken off is kept in the Undo snapshot, so Undo puts it back (admin_viewed = 2
       marks "settled by an admin action", as opposed to 1 = the admin clicked View, and a restore only ever touches 0/2). */
function cvMoaFlagLabel($key) {
    static $labels = [
        'company_name' => 'Company Name', 'company_profile' => 'Company Profile', 'company_address' => 'Company Address',
        'position' => 'Position', 'contact_first_name' => 'Contact First Name', 'contact_middle_name' => 'Contact Middle Name',
        'contact_last_name' => 'Contact Last Name', 'telephone' => 'Telephone', 'moa_document' => 'Uploaded MOA Document',
    ];
    return $labels[$key] ?? $key;
}

function cvBuildComplianceDetail($conn, $uid, $crId) {
    $flags = null; $comment = '';
    try {
        $q = $conn->prepare("SELECT moa_last_flagged_fields, moa_last_revision_comment FROM company_requirements WHERE id=?");
        if ($q) {
            $q->bind_param("i", $crId); $q->execute();
            $r = $q->get_result()->fetch_assoc(); $q->close();
            $flags   = json_decode((string)($r['moa_last_flagged_fields'] ?? ''), true);
            $comment = (string)($r['moa_last_revision_comment'] ?? '');
        }
        if (!is_array($flags) || empty($flags)) {
            $q = $conn->prepare("SELECT flagged_fields, rejection_notes FROM moa_requests WHERE user_id=? AND flagged_fields IS NOT NULL AND flagged_fields NOT IN ('', '[]', 'null') ORDER BY id DESC LIMIT 1");
            if ($q) {
                $q->bind_param("i", $uid); $q->execute();
                $r = $q->get_result()->fetch_assoc(); $q->close();
                $flags   = json_decode((string)($r['flagged_fields'] ?? ''), true);
                $comment = (string)($r['rejection_notes'] ?? '');
            }
        }
        if (!is_array($flags) || empty($flags)) return null;

        $ci = [];
        $cq = $conn->prepare("SELECT * FROM company_information WHERE user_id=?");
        if ($cq) { $cq->bind_param("i", $uid); $cq->execute(); $ci = $cq->get_result()->fetch_assoc() ?: []; $cq->close(); }
        $columnFor = ['company_name' => 'company', 'company_profile' => 'company_profile', 'company_address' => 'company_address',
                      'position' => 'position', 'contact_first_name' => 'contact_first_name', 'contact_middle_name' => 'contact_middle_initial',
                      'contact_last_name' => 'contact_last_name', 'telephone' => 'telephone'];
        $fields = [];
        foreach ($flags as $k) {
            if (!is_string($k) || $k === '') continue;
            $fields[] = ['key' => $k, 'label' => cvMoaFlagLabel($k), 'value' => isset($columnFor[$k]) ? trim((string)($ci[$columnFor[$k]] ?? '')) : ''];
        }
        if (empty($fields)) return null;
        return ['fields' => $fields, 'comment' => $comment, 'complied_at' => date('Y-m-d H:i:s')];
    } catch (\Throwable $e) { return null; }
}

// notif_detail JSON -> the list of sections / the admin's original message (for the inbox card)
function cvNotifDetailFields($json) {
    $d = $json ? json_decode($json, true) : null;
    return (is_array($d) && isset($d['fields']) && is_array($d['fields'])) ? $d['fields'] : [];
}
function cvNotifDetailComment($json) {
    $d = $json ? json_decode($json, true) : null;
    return is_array($d) ? (string)($d['comment'] ?? '') : '';
}

function cvOnAdminMoaAction($conn, $uid) {
    $uid = (int)$uid;
    if ($uid <= 0) return;
    try {
        $s = $conn->prepare("UPDATE moa_requests SET admin_viewed=1 WHERE user_id=? AND admin_viewed=0 AND transferred=1");
        if ($s) { $s->bind_param("i", $uid); $s->execute(); $s->close(); }
        $c = $conn->prepare("UPDATE company_requirements SET moa_complied_detail=NULL WHERE user_id=? AND requirement_type IN ('moa_document','moa')");
        if ($c) { $c->bind_param("i", $uid); $c->execute(); $c->close(); }
    } catch (\Throwable $e) { /* never let clean-up break the admin's own action */ }
}

/* ═══════════════════════════════════════════════════════════════════════════════════════════════════════
   NEW (this adjustment) — UNDO FOR MOA PROCESSES (the MOA counterpart of the requirements' undo toast)
   ───────────────────────────────────────────────────────────────────────────────────────────────────────
   Every MOA process the admin runs — Approve, Set / Re-Schedule, Accept Proposed Schedule, Done, Send & Request
   Revision — can now be undone for 5 minutes, exactly like verifying / rejecting a requirement. How it works:
     • Just before the process writes anything, cvMoaUndoCapture() takes a snapshot of the rows it can touch (the MOA row(s)
       in company_requirements — the document itself is only fingerprinted, its bytes are kept in a temp file only when the
       process replaces it — plus, for a revision request, the company's information row, and the company's moa_requests
       rows). When it finishes, a second snapshot ("after") is taken and both go into $_SESSION['moa_undo_stack'][token].
     • The automatic email to the company is NOT sent straight away any more: it waits in $_SESSION['moa_pending_emails']
       until the toast is dismissed / runs out (ajax_moa_commit), so an undone action never emails the company. A page
       load also sends any that are past their window (ajax_moa_flush_emails).
     • ajax_moa_undo puts back ONLY the columns the process actually changed — and only if they still hold what the process
       left there. If the company has responded in the meantime (confirmed the schedule, updated the flagged sections…)
       the undo is refused instead of overwriting their answer.
   It is opt-in per request (moa_undoable=1, which the page's own buttons send): a request without it behaves exactly as it
   always did — email sent immediately, nothing to undo.
   ═══════════════════════════════════════════════════════════════════════════════════════════════════════ */
function cvMoaUndoSame($a, $b) {
    if ($a === null || $b === null) return $a === $b;
    return (string)$a === (string)$b;
}
function cvMoaUndoChangedCols(array $before, array $after) {
    $cols = [];
    foreach ($before as $c => $v) {
        if (!array_key_exists($c, $after) || !cvMoaUndoSame($v, $after[$c])) $cols[] = $c;
    }
    return $cols;
}
function cvMoaUndoCapture($conn, $uid, $withCompanyInfo = false) {
    $uid  = (int)$uid;
    $snap = ['cr' => [], 'ci' => null, 'mr' => []];
    try {
        $q = $conn->prepare("SELECT * FROM company_requirements WHERE user_id=? AND requirement_type IN ('moa_document','moa') ORDER BY id ASC");
        if ($q) {
            $q->bind_param("i", $uid); $q->execute();
            $res = $q->get_result();
            while ($r = $res->fetch_assoc()) {
                $blob = $r['file_name'] ?? null;   // the MOA document itself: fingerprinted, not copied
                $r['file_name'] = ($blob === null || $blob === '') ? $blob : ('blob:' . strlen($blob) . ':' . md5($blob));
                $snap['cr'][(int)$r['id']] = $r;
            }
            $q->close();
        }
    } catch (\Throwable $e) { /* keep whatever was read */ }
    if ($withCompanyInfo) {
        try {
            $q = $conn->prepare("SELECT * FROM company_information WHERE user_id=?");
            if ($q) {
                $q->bind_param("i", $uid); $q->execute();
                $snap['ci'] = $q->get_result()->fetch_assoc() ?: null;
                $q->close();
            }
        } catch (\Throwable $e) { /* optional */ }
    }
    try {
        $q = $conn->prepare("SELECT * FROM moa_requests WHERE user_id=?");
        if ($q) {
            $q->bind_param("i", $uid); $q->execute();
            $res = $q->get_result();
            while ($r = $res->fetch_assoc()) {
                unset($r['moa_pdf']);   // the request's own document is never changed by these processes
                $snap['mr'][(int)$r['id']] = $r;
            }
            $q->close();
        }
    } catch (\Throwable $e) { /* optional — this table may not exist yet */ }
    return $snap;
}
// Keeps the bytes of each MOA document row (only needed when a process is about to REPLACE the document — a revision request).
function cvMoaUndoStashBlobs($conn, array $crRows) {
    $files = [];
    foreach ($crRows as $id => $row) {
        if (!is_string($row['file_name'] ?? null) || $row['file_name'] === '') continue;
        try {
            $q = $conn->prepare("SELECT file_name FROM company_requirements WHERE id=?");
            if (!$q) continue;
            $rid = (int)$id;
            $q->bind_param("i", $rid); $q->execute();
            $b = $q->get_result()->fetch_assoc(); $q->close();
            if (empty($b['file_name'])) continue;
            $path = tempnam(sys_get_temp_dir(), 'cvmoa_');
            if ($path === false) continue;
            if (file_put_contents($path, $b['file_name']) === false) { @unlink($path); continue; }
            $files[(int)$id] = $path;
        } catch (\Throwable $e) { /* that row just won't be restorable */ }
    }
    return $files;
}
function cvMoaUndoCompanyName($conn, $uid) {
    try {
        $q = $conn->prepare("SELECT company FROM company_information WHERE user_id=?");
        if ($q) {
            $uid = (int)$uid;
            $q->bind_param("i", $uid); $q->execute();
            $r = $q->get_result()->fetch_assoc(); $q->close();
            if (!empty($r['company'])) return (string)$r['company'];
        }
    } catch (\Throwable $e) { /* fall through */ }
    return 'this company';
}
function cvMoaUndoQueueEmail($token, array $payload) {
    if (!isset($_SESSION['moa_pending_emails']) || !is_array($_SESSION['moa_pending_emails'])) $_SESSION['moa_pending_emails'] = [];
    $_SESSION['moa_pending_emails'][$token] = $payload + ['ts' => time()];
}
// Second half of every undoable process: takes the "after" snapshot, files it under the token, and returns the fields the
// page needs to show the toast.
function cvMoaUndoFinish($conn, $uid, array $ctx, $action, array $extra = []) {
    $before = $ctx['before'];
    $after  = cvMoaUndoCapture($conn, $uid, !empty($before['ci']));
    $prevStage = 'pending';
    foreach ($before['cr'] as $row) { $prevStage = (string)($row['moa_workflow_stage'] ?? 'pending'); break; }
    if ($action === 'schedule' && $prevStage === 'scheduled') $action = 'reschedule';
    $texts = [
        'approve'         => 'MOA approved',
        'schedule'        => 'Signing schedule set',
        'reschedule'      => 'Signing schedule changed',
        'accept_schedule' => 'Proposed schedule accepted',
        'done'            => 'MOA marked as done',
        'flag'            => 'Revision requested',
    ];
    $company = !empty($extra['company_name']) ? (string)$extra['company_name'] : cvMoaUndoCompanyName($conn, $uid);
    $label   = ($texts[$action] ?? 'MOA updated') . ' — ' . $company;
    if (!isset($_SESSION['moa_undo_stack']) || !is_array($_SESSION['moa_undo_stack'])) $_SESSION['moa_undo_stack'] = [];
    $_SESSION['moa_undo_stack'][$ctx['token']] = [
        'user_id'    => (int)$uid,
        'action'     => $action,
        'label'      => $label,
        'ts'         => time(),
        'before'     => $before,
        'after'      => $after,
        'blob_files' => $ctx['blob_files'] ?? [],
        'done_file'  => $extra['done_file'] ?? null,
    ];
    return ['undo_token' => $ctx['token'], 'undo_label' => $label, 'undo_action' => $action];
}
// Sends the email that was held back for a token (the undo window is over, or the admin dismissed the toast).
function cvMoaUndoSendPending($conn, $token) {
    $p = $_SESSION['moa_pending_emails'][$token] ?? null;
    if (!is_array($p)) return false;
    unset($_SESSION['moa_pending_emails'][$token]);
    $ok = false;
    try {
        if (($p['kind'] ?? '') === 'stage') {
            $ok = sendMoaStageEmail($conn, (int)$p['user_id'], (string)$p['stage'], (string)($p['comment'] ?? ''), $p['schedule'] ?? null);
        } elseif (($p['kind'] ?? '') === 'flag') {
            $blob = (!empty($p['pdf_path']) && is_file($p['pdf_path'])) ? file_get_contents($p['pdf_path']) : null;
            $dbg  = null;
            $ok = sendMoaRejectionEmailWithAttachment((string)$p['to_email'], (string)$p['to_name'], (string)$p['company_name'], (string)$p['comment'], $blob, (string)($p['pdf_filename'] ?? ''), (array)($p['flagged_labels'] ?? []), $dbg);
            moaDebugLog('cvMoaUndoSendPending:flag_mail', ['to'=>$p['to_email'] ?? '', 'sent'=>$ok, 'debug'=>$dbg]);
        }
    } catch (\Throwable $e) {
        moaDebugLog('cvMoaUndoSendPending:exception', ['message'=>$e->getMessage()]);
    }
    if (!empty($p['pdf_path'])) @unlink($p['pdf_path']);
    return $ok;
}
// Forgets a token: its snapshot, its held-back email and any temp files.
function cvMoaUndoDrop($token) {
    $snap = $_SESSION['moa_undo_stack'][$token] ?? null;
    if (is_array($snap) && !empty($snap['blob_files']) && is_array($snap['blob_files'])) {
        foreach ($snap['blob_files'] as $f) { if (is_string($f) && $f !== '') @unlink($f); }
    }
    $p = $_SESSION['moa_pending_emails'][$token] ?? null;
    if (is_array($p) && !empty($p['pdf_path'])) @unlink($p['pdf_path']);
    unset($_SESSION['moa_undo_stack'][$token], $_SESSION['moa_pending_emails'][$token]);
}
function cvMoaUndoUpdate($conn, $table, $keyCol, $keyVal, array $row, array $cols) {
    if (empty($cols)) return true;
    $sets = []; $types = ''; $vals = [];
    foreach ($cols as $c) {
        $sets[]  = '`' . str_replace('`', '``', $c) . '`=?';
        $types  .= 's';
        $vals[]  = $row[$c] ?? null;
    }
    $types .= 'i';
    $vals[] = (int)$keyVal;
    $st = $conn->prepare("UPDATE `" . $table . "` SET " . implode(', ', $sets) . " WHERE `" . $keyCol . "`=?");
    if (!$st) return false;
    $st->bind_param($types, ...$vals);
    $ok = $st->execute();
    $st->close();
    return (bool)$ok;
}
// Puts back what a process changed. Returns ['ok'=>bool, 'message'=>string, 'notif_ids'=>[…], 'overall'=>string].
function cvMoaUndoRestore($conn, array $snap) {
    $uid    = (int)($snap['user_id'] ?? 0);
    $before = $snap['before'] ?? ['cr'=>[], 'ci'=>null, 'mr'=>[]];
    $after  = $snap['after']  ?? ['cr'=>[], 'ci'=>null, 'mr'=>[]];
    $fail   = function ($msg) { return ['ok'=>false, 'message'=>$msg, 'notif_ids'=>[], 'overall'=>'']; };
    $conflict = 'This can no longer be undone — the company has already responded, or the record was changed since.';
    if (!$uid) return $fail('Undo token expired or invalid.');
    $now = cvMoaUndoCapture($conn, $uid, !empty($before['ci']));

    // 1) refuse if anything the process changed no longer holds what the process left there
    foreach ($before['cr'] as $id => $rowB) {
        $rowA = $after['cr'][$id] ?? null; $rowN = $now['cr'][$id] ?? null;
        if ($rowA === null || $rowN === null) return $fail($conflict);
        foreach (cvMoaUndoChangedCols($rowB, $rowA) as $c) {
            if (!cvMoaUndoSame($rowN[$c] ?? null, $rowA[$c] ?? null)) return $fail($conflict);
        }
        if (in_array('file_name', cvMoaUndoChangedCols($rowB, $rowA), true)) {
            $bp = $snap['blob_files'][$id] ?? null;
            if (!is_string($bp) || !is_file($bp)) return $fail('The original MOA document is no longer available, so this can\'t be undone.');
        }
    }
    if (!empty($before['ci'])) {
        $rowA = $after['ci'] ?? null; $rowN = $now['ci'] ?? null;
        if ($rowA === null || $rowN === null) return $fail($conflict);
        foreach (cvMoaUndoChangedCols($before['ci'], $rowA) as $c) {
            if (!cvMoaUndoSame($rowN[$c] ?? null, $rowA[$c] ?? null)) return $fail($conflict);
        }
    }

    // 2) put the MOA row(s) back
    foreach ($before['cr'] as $id => $rowB) {
        $changed = cvMoaUndoChangedCols($rowB, $after['cr'][$id]);
        if (empty($changed)) continue;
        $cols = [];
        foreach ($changed as $c) { if ($c !== 'file_name' && $c !== 'id') $cols[] = $c; }
        cvMoaUndoUpdate($conn, 'company_requirements', 'id', $id, $rowB, $cols);
        if (in_array('file_name', $changed, true)) {
            $bytes = file_get_contents($snap['blob_files'][$id]);
            $st = $conn->prepare("UPDATE company_requirements SET file_name=? WHERE id=?");
            if ($st) {
                $nullBlob = null; $rid = (int)$id;
                $st->bind_param("bi", $nullBlob, $rid);
                $st->send_long_data(0, $bytes);
                $st->execute(); $st->close();
            }
        }
    }
    // 3) the company's information (a revision request blanks the flagged fields)
    if (!empty($before['ci'])) {
        $cols = [];
        foreach (cvMoaUndoChangedCols($before['ci'], $after['ci']) as $c) { if ($c !== 'id' && $c !== 'user_id') $cols[] = $c; }
        cvMoaUndoUpdate($conn, 'company_information', 'user_id', $uid, $before['ci'], $cols);
    }
    // 4) the company's request rows (notification "seen" flags…) — only where they still hold what the process left
    $notif = [];
    foreach ($before['mr'] as $id => $rowB) {
        $rowA = $after['mr'][$id] ?? null; $rowN = $now['mr'][$id] ?? null;
        if ($rowA === null || $rowN === null) continue;
        $cols = [];
        foreach (cvMoaUndoChangedCols($rowB, $rowA) as $c) {
            if ($c !== 'id' && cvMoaUndoSame($rowN[$c] ?? null, $rowA[$c] ?? null)) $cols[] = $c;
        }
        if (!empty($cols)) {
            cvMoaUndoUpdate($conn, 'moa_requests', 'id', $id, $rowB, $cols);
            if (in_array('admin_viewed', $cols, true)) $notif[] = (int)$id;
        }
    }
    // 5) "Done" also saved a verified copy of the MOA on disk — take it back
    if (!empty($snap['done_file']) && is_string($snap['done_file']) && is_file($snap['done_file'])) @unlink($snap['done_file']);

    $overall = recomputeCompanyValidationStatus($conn, $uid);
    return ['ok'=>true, 'message'=>'Action undone successfully.', 'notif_ids'=>$notif, 'overall'=>$overall];
}

function cvResolveRequirementUploadNotification($conn, $uid, $reqKey) {
    $restore = [];
    try {
        $q = $conn->prepare("SELECT id, detail FROM company_requirement_upload_notifications WHERE user_id=? AND admin_viewed=0");
        if (!$q) return $restore;
        $uid = (int)$uid;
        $q->bind_param("i", $uid); $q->execute();
        $res = $q->get_result();
        $rows = [];
        while ($r = $res->fetch_assoc()) $rows[] = $r;
        $q->close();
        foreach ($rows as $r) {
            $detail = json_decode((string)($r['detail'] ?? ''), true);
            if (!is_array($detail)) continue;
            $keep = array_values(array_filter($detail, function ($d) use ($reqKey) { return ($d['key'] ?? '') !== $reqKey; }));
            if (count($keep) === count($detail)) continue;      // this notification doesn't list that requirement
            $nid = (int)$r['id'];
            $restore[] = ['id' => $nid, 'detail' => (string)$r['detail']];
            if (empty($keep)) {
                $u = $conn->prepare("UPDATE company_requirement_upload_notifications SET admin_viewed=2 WHERE id=?");
                if ($u) { $u->bind_param("i", $nid); $u->execute(); $u->close(); }
            } else {
                $json = json_encode($keep);
                $u = $conn->prepare("UPDATE company_requirement_upload_notifications SET detail=? WHERE id=?");
                if ($u) { $u->bind_param("si", $json, $nid); $u->execute(); $u->close(); }
            }
        }
    } catch (\Throwable $e) { return []; }
    return $restore;
}

function cvRestoreRequirementUploadNotifications($conn, $snapshots) {
    $ids = [];
    if (!is_array($snapshots)) return $ids;
    try {
        foreach ($snapshots as $s) {
            $nid = (int)($s['id'] ?? 0);
            if ($nid <= 0) continue;
            $detail = (string)($s['detail'] ?? '');
            $u = $conn->prepare("UPDATE company_requirement_upload_notifications SET detail=?, admin_viewed=0 WHERE id=? AND admin_viewed IN (0,2)");
            if (!$u) continue;
            $u->bind_param("si", $detail, $nid); $u->execute();
            if ($u->affected_rows > 0) $ids[] = CV_REQ_NOTIF_ID_OFFSET + $nid;
            $u->close();
        }
    } catch (\Throwable $e) { /* ignore */ }
    return $ids;
}

/* ================= HELPER: SEND MOA WORKFLOW STAGE EMAIL ================= */
function sendMoaStageEmail($conn, $user_id, $stage, $comment = '', $scheduleDateTime = null) {
    if (!$user_id) return false;

    $uq = $conn->prepare("SELECT first_name, last_name, email FROM users WHERE id=?");
    if (!$uq) { moaDebugLog('sendMoaStageEmail:prepare_fail', ['error'=>$conn->error]); return false; }
    $uq->bind_param("i", $user_id); $uq->execute();
    $u = $uq->get_result()->fetch_assoc(); $uq->close();

    if (empty($u) || empty($u['email'])) {
        moaDebugLog('sendMoaStageEmail:no_user_email', ['user_id'=>$user_id]);
        return false;
    }

    $cq = $conn->prepare("SELECT company FROM company_information WHERE user_id=?");
    $cq->bind_param("i", $user_id); $cq->execute();
    $c = $cq->get_result()->fetch_assoc(); $cq->close();
    $companyName = $c['company'] ?? 'Your Company';

    $fullName = trim(($u['first_name'] ?? '') . ' ' . ($u['last_name'] ?? ''));
    if ($fullName === '') $fullName = 'Company Representative';

    $ok = sendMoaWorkflowEmail($u['email'], $fullName, $companyName, $stage, $comment, $scheduleDateTime);
    moaDebugLog('sendMoaStageEmail:result', ['user_id'=>$user_id,'stage'=>$stage,'has_comment'=>!empty($comment),'schedule'=>$scheduleDateTime,'ok'=>$ok]);
    return $ok;
}

/* ================= HELPER: SEND MOA REJECTION EMAIL WITH PDF ATTACHMENT =================
   Self-contained (does not depend on mail.php internals) so it cannot disrupt any existing
   email flows. Sends a plain multipart/mixed email with the admin's message plus the original
   MOA file (if any) attached, using PHP's built-in mail(). ================================ */
/* ================= HELPER: SEND MOA REJECTION EMAIL WITH PDF ATTACHMENT =================
   ── UPDATED (fix): this now sends via PHPMailer over the same Gmail SMTP
   account already used everywhere else in this system (see mail.php), instead
   of PHP's built-in mail() function. mail() relies on a local sendmail/SMTP
   relay on port 25 being configured on the server, which most hosts (and this
   one) do not have — that mismatch was the direct cause of:
       "mail(): Failed to connect to mailserver at 'localhost' port 25..."
   Switching to PHPMailer + SMTP (STARTTLS, port 587, Gmail) fixes delivery
   because it talks straight to Gmail's servers over an authenticated SMTP
   connection, exactly like sendStatusEmail() / sendMoaWorkflowEmail() do.
   The function name, parameters, return value (bool), $debugInfo out-param,
   the email wording/body, the flagged-section list, and the debug logging
   are all preserved unchanged — only the transport mechanism changed. ================================ */
function sendMoaRejectionEmailWithAttachment($toEmail, $toName, $companyName, $adminComment, $pdfBlob, $pdfFilename, $flaggedLabels = [], &$debugInfo = null) {
    $debugInfo = [];

    if (empty($toEmail)) {
        $debugInfo['error'] = 'No recipient email address was provided.';
        moaDebugLog('sendMoaRejectionEmailWithAttachment:no_email', []);
        return false;
    }

    // ── DEBUG: validate the email format up front. An invalid format is a very common,
    // silent cause of delivery failures with no obvious error on some hosts.
    if (!filter_var($toEmail, FILTER_VALIDATE_EMAIL)) {
        $debugInfo['error'] = 'The recipient email address "' . $toEmail . '" is not a valid email format.';
        moaDebugLog('sendMoaRejectionEmailWithAttachment:invalid_email_format', ['to' => $toEmail]);
        return false;
    }

    $subject = "Update on Your MOA Request - " . $companyName;

    $bodyText  = "Dear " . $toName . ",\n\n";
    $bodyText .= "Thank you for submitting a Memorandum of Agreement (MOA) request on behalf of " . $companyName . ". ";
    $bodyText .= "After careful review, we are unable to move forward with this request at this time.\n\n";
    $bodyText .= "Message from the reviewing administrator:\n";
    $bodyText .= $adminComment . "\n\n";
    // ── NEW (fix): list the flagged section(s) in the email body when provided, so the
    // company knows exactly which part(s) of their submission need correction. This uses
    // the $flaggedLabels array now properly passed in from the caller.
    if (!empty($flaggedLabels)) {
        $bodyText .= "Section(s) that need correction:\n";
        foreach ($flaggedLabels as $flagLabel) {
            $bodyText .= "- " . $flagLabel . "\n";
        }
        $bodyText .= "\n";
    }
    $bodyText .= "We sincerely appreciate your interest in partnering with us. We encourage you to review the notes above and submit a new request whenever you're ready — we'd be glad to work with you. ";
    $bodyText .= "If you have any questions or need clarification, please feel free to reach out; we're happy to help.\n\n";
    $bodyText .= "For your reference, a copy of the MOA document you submitted is attached to this email.\n\n";
    $bodyText .= "Warm regards,\n";
    $bodyText .= "OJT Monitoring and Supervision Analytics System\n";

    // ── DEBUG: log the mail transport configuration + payload size BEFORE attempting to
    // send, same as before, so a failure is still fully traceable in moa_debug.log.
    moaDebugLog('sendMoaRejectionEmailWithAttachment:pre_send', [
        'to'                => $toEmail,
        'subject'           => $subject,
        'body_bytes'        => strlen($bodyText),
        'has_attachment'    => !empty($pdfBlob),
        'attachment_bytes'  => !empty($pdfBlob) ? strlen($pdfBlob) : 0,
        'flagged_labels'    => $flaggedLabels,
        'transport'         => 'PHPMailer SMTP (smtp.gmail.com:587 STARTTLS)',
    ]);

    // Fully-qualified class names are used here (instead of a `use` import)
    // because this file has no namespace declaration and `use` statements
    // must appear before any other top-level code — this file already starts
    // with session_start(), so a `use` here would be a fatal error.
    $mail = new \PHPMailer\PHPMailer\PHPMailer(true);

    try {
        $mail->isSMTP();
        $mail->Host       = 'smtp.gmail.com';
        $mail->SMTPAuth   = true;
        $mail->Username   = 'salesjohnlhoyd@gmail.com';
        $mail->Password   = 'qwufanprpmezotly';
        $mail->SMTPSecure = \PHPMailer\PHPMailer\PHPMailer::ENCRYPTION_STARTTLS;
        $mail->Port       = 587;

        $mail->setFrom('example@gmail.com', 'Atate Campus On The Job Training System');
        $mail->addAddress($toEmail, $toName);

        $mail->isHTML(false);
        $mail->Subject = $subject;
        $mail->Body    = $bodyText;
        $mail->AltBody = $bodyText;

        if (!empty($pdfBlob)) {
            $finfo = new finfo(FILEINFO_MIME_TYPE);
            $mime  = $finfo->buffer($pdfBlob) ?: 'application/octet-stream';
            $attachName = !empty($pdfFilename) ? $pdfFilename : ('MOA_Document_' . time() . '.pdf');
            // addStringAttachment lets us attach the in-memory blob directly
            // (it was previously pulled straight from the DB) without ever
            // writing it to a temp file on disk.
            $mail->addStringAttachment($pdfBlob, $attachName, 'base64', $mime);
        }

        $mail->send();

        moaDebugLog('sendMoaRejectionEmailWithAttachment:result', [
            'to'             => $toEmail,
            'has_attachment' => !empty($pdfBlob),
            'sent'           => true,
        ]);

        return true;

    } catch (\PHPMailer\PHPMailer\Exception $e) {
        $debugInfo['error'] = 'PHPMailer reported: ' . ($mail->ErrorInfo ?: $e->getMessage());

        moaDebugLog('sendMoaRejectionEmailWithAttachment:result', [
            'to'             => $toEmail,
            'has_attachment' => !empty($pdfBlob),
            'sent'           => false,
            'error'          => $mail->ErrorInfo ?: $e->getMessage(),
        ]);

        return false;
    }
}

/* ================= AJAX: UNGRADED COUNT ================= */
if (isset($_GET['ungraded_count']) && $_GET['ungraded_count'] == '1') {
    $stmt_ug = $conn->prepare("SELECT COUNT(*) as total FROM reports r JOIN ojt_assignments oa ON oa.student_id=r.user_id AND oa.company_id=r.company_id WHERE r.week_start<=CURDATE() AND (r.remark IS NULL OR r.remark!='Wrong Document') AND r.faculty_grade IS NULL");
    $stmt_ug->execute(); $res_ug = $stmt_ug->get_result()->fetch_assoc(); $stmt_ug->close();
    header('Content-Type: application/json');
    echo json_encode(['ungraded_count' => (int)($res_ug['total'] ?? 0)]);
    exit;
}

/* ================= AJAX: STREAM MOA BLOB (from moa_requests) ================= */
if (isset($_GET['stream_moa_blob'])) {
    $moa_id = intval($_GET['stream_moa_blob']);
    if (!$moa_id) { http_response_code(400); exit; }
    $stmt = $conn->prepare("SELECT moa_pdf, moa_pdf_filename FROM moa_requests WHERE id=?");
    $stmt->bind_param("i", $moa_id); $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc(); $stmt->close();
    if (empty($row) || empty($row['moa_pdf'])) { http_response_code(404); echo "No file found."; exit; }
    $blob = $row['moa_pdf']; $filename = $row['moa_pdf_filename'] ?? 'moa_document';
    $finfo = new finfo(FILEINFO_MIME_TYPE); $mime = $finfo->buffer($blob);
    if (!$mime || $mime==='application/octet-stream') {
        $ext = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
        $mimeMap = ['pdf'=>'application/pdf','png'=>'image/png','jpg'=>'image/jpeg','jpeg'=>'image/jpeg','gif'=>'image/gif','webp'=>'image/webp'];
        $mime = $mimeMap[$ext] ?? 'application/octet-stream';
    }
    header('Content-Type: ' . $mime);
    header('Content-Disposition: inline; filename="' . addslashes($filename) . '"');
    header('Content-Length: ' . strlen($blob));
    header('Cache-Control: private, max-age=300');
    echo $blob; exit;
}

/* ================= AJAX: STREAM REQUIREMENT BLOB (moa_document after acceptance) ================= */
if (isset($_GET['stream_req_blob'])) {
    $req_user_id = intval($_GET['stream_req_blob']);
    $req_type    = $_GET['req_type'] ?? 'moa_document';
    if (!$req_user_id) { http_response_code(400); exit; }
    // ── UPDATED (this adjustment): resolve the MOA document's actual
    // stored key ('moa_document' or legacy 'moa') so previewing/streaming
    // a registration-generated MOA works instead of 404ing.
    $lookupType = ($req_type === 'moa_document') ? resolveMoaRequirementType($conn, $req_user_id) : $req_type;
    // ── NEW (this adjustment): optional file_id — streams ONE specific entry when a requirement
    // holds several files (one company_requirements row each). The id is always cross-checked
    // against BOTH user_id and requirement_type, so it can only ever return a file that really
    // belongs to that company's requirement. Without file_id the query is exactly what it was
    // before (the MOA document preview keeps working untouched).
    $req_file_id = isset($_GET['file_id']) ? intval($_GET['file_id']) : 0;
    if ($req_file_id > 0) {
        $stmt = $conn->prepare("SELECT file_name FROM company_requirements WHERE id=? AND user_id=? AND requirement_type=?");
        $stmt->bind_param("iis", $req_file_id, $req_user_id, $lookupType); $stmt->execute();
    } else {
        $stmt = $conn->prepare("SELECT file_name FROM company_requirements WHERE user_id=? AND requirement_type=?");
        $stmt->bind_param("is", $req_user_id, $lookupType); $stmt->execute();
    }
    $row = $stmt->get_result()->fetch_assoc(); $stmt->close();
    if (empty($row) || empty($row['file_name'])) { http_response_code(404); echo "No file found."; exit; }
    $blob = $row['file_name']; $finfo = new finfo(FILEINFO_MIME_TYPE); $mime = $finfo->buffer($blob);
    if (!$mime || $mime==='application/octet-stream') $mime = 'application/pdf';
    header('Content-Type: ' . $mime);
    if ($req_file_id > 0) {
        header('Content-Disposition: inline; filename="' . preg_replace('/[^a-zA-Z0-9_-]/', '_', $req_type) . '_' . $req_user_id . '_' . $req_file_id . '"');
    } else {
        header('Content-Disposition: inline; filename="moa_document_' . $req_user_id . '"');
    }
    header('Content-Length: ' . strlen($blob));
    header('Cache-Control: private, max-age=300');
    echo $blob; exit;
}

/* ================= AJAX: FETCH MOA REQUESTS ================= */
if (isset($_POST['ajax_fetch_moa_requests'])) {
    header('Content-Type: application/json');
    cv_ensure_moa_requests_table($conn);   // CLEAN-UP (audit): shared definition, see cv_ensure_moa_requests_table()
    $conn->query("ALTER TABLE moa_requests ADD COLUMN IF NOT EXISTS moa_workflow_status VARCHAR(30) DEFAULT 'pending'");
    $conn->query("ALTER TABLE moa_requests ADD COLUMN IF NOT EXISTS revision_flags TEXT NULL");
    $conn->query("ALTER TABLE moa_requests ADD COLUMN IF NOT EXISTS revision_comment TEXT NULL");
    $conn->query("ALTER TABLE moa_requests ADD COLUMN IF NOT EXISTS is_revision TINYINT(1) NOT NULL DEFAULT 0");
    $rows = [];
    // ── UPDATED (adjustment): also select telephone + company_profile so we can
    // determine, per row, whether every previously-flagged field has actually been
    // re-filled in by the company yet. Nothing else about this query changed.
    $res = $conn->query("SELECT mr.id, mr.user_id, mr.request_type, mr.company_name, mr.company_profile, mr.company_address, mr.telephone, mr.position, mr.contact_first_name, mr.contact_middle_name, mr.contact_last_name, mr.status, mr.moa_workflow_status, mr.submitted_at, mr.moa_pdf_filename, mr.revision_flags, mr.revision_comment, mr.is_revision, (mr.moa_pdf IS NOT NULL AND LENGTH(mr.moa_pdf)>0) AS has_pdf FROM moa_requests mr ORDER BY FIELD(mr.status,'Pending','Approved','Rejected'), mr.submitted_at DESC");
    if ($res) {
        while ($r = $res->fetch_assoc()) {
            $flagsDecoded = [];
            if (!empty($r['revision_flags'])) {
                $decoded = json_decode($r['revision_flags'], true);
                if (is_array($decoded)) $flagsDecoded = $decoded;
            }

            // ── NEW (adjustment): a revision row is "awaiting resubmission" — and
            // therefore should have its Accept/Reject buttons hidden — for as long
            // as ANY of the fields the admin flagged are still blank (they were
            // cleared to '' when the revision row was re-created). The moment the
            // company fills every flagged field back in and re-submits, all of
            // them become non-empty and this flips to false, which automatically
            // brings the Accept/Reject buttons back on the frontend.
            //
            // NOTE: as of the "Reject & Send" adjustment, the full reject flow no
            // longer blanks the flagged field(s) out (it updates the row in place
            // and marks it status='Rejected' instead of recreating it as
            // 'Pending'), so this specific "blank field" condition will typically
            // no longer trigger for NEW rejections. It is left fully intact so any
            // older/legacy rows created before this update (still sitting as a
            // 'Pending' revision row with blanked fields) keep behaving exactly
            // as they did before.
            //
            // ── NOTE (adjustment): "moa_document" is intentionally excluded from
            // this blank-field check — it flags the uploaded FILE, not a text
            // column on this row, so there is no text value to ever compare as
            // "blank". Whether the uploaded document itself is still outstanding
            // is instead surfaced on the frontend via the Rejected-status +
            // flagged-moa_document combination (see the View PDF lock logic in
            // openMoaPreview()).
            $awaitingResubmission = false;
            if (!empty($r['is_revision']) && !empty($flagsDecoded)) {
                foreach ($flagsDecoded as $flagKey) {
                    if ($flagKey === 'moa_document') continue;
                    $flagVal = $r[$flagKey] ?? '';
                    if (trim((string)$flagVal) === '') {
                        $awaitingResubmission = true;
                        break;
                    }
                }
            }

            $rows[] = [
                'id'                  => (int)$r['id'],
                'user_id'             => (int)$r['user_id'],
                'request_type'        => $r['request_type'],
                'company_name'        => $r['company_name'],
                'company_address'     => $r['company_address'],
                'position'            => $r['position'],
                'contact_name'        => trim($r['contact_first_name'].' '.$r['contact_middle_name'].' '.$r['contact_last_name']),
                'contact_first_name'  => $r['contact_first_name'],
                'contact_middle_name' => $r['contact_middle_name'],
                'contact_last_name'   => $r['contact_last_name'],
                'status'              => $r['status'],
                'moa_workflow_status' => $r['moa_workflow_status'] ?? 'pending',
                'submitted_at'        => $r['submitted_at'] ? date('M d, Y g:i A', strtotime($r['submitted_at'] ?? '')) : '',
                'has_pdf'             => (bool)$r['has_pdf'],
                'pdf_filename'        => $r['moa_pdf_filename'] ?? '',
                'revision_flags'      => $flagsDecoded,
                'revision_comment'    => $r['revision_comment'] ?? '',
                'is_revision'         => (bool)($r['is_revision'] ?? 0),
                'awaiting_resubmission' => $awaitingResubmission,
            ];
        }
    }
    $pending_count = count(array_filter($rows, fn($r) => $r['status'] === 'Pending'));
    echo json_encode(['success'=>true,'rows'=>$rows,'pending_count'=>$pending_count]);
    exit;
}

/* ================= AJAX: FETCH MOA "NEW ENTRY" NOTIFICATIONS =================
   NEW (this adjustment) — powers the MOA Requests inbox now that it acts
   as a simple notification list instead of an Accept/Reject queue. Every
   moa_requests row that has already been auto-ingested (transferred=1,
   see autoIngestPendingMoaRequests()) but not yet acknowledged by the
   admin (admin_viewed=0) is returned here. Clicking "View Request" on the
   frontend calls ajax_moa_mark_notification_viewed (below) to flip
   admin_viewed to 1, at which point it stops being returned by this
   endpoint and disappears from the inbox — exactly the requested
   behavior. This is a separate, additive endpoint; the original
   ajax_fetch_moa_requests handler above is left completely untouched so
   any other existing logic that still reads it keeps working as-is.
   ================================ */
if (isset($_POST['ajax_fetch_moa_notifications'])) {
    header('Content-Type: application/json');
    $conn->query("ALTER TABLE moa_requests ADD COLUMN IF NOT EXISTS transferred TINYINT(1) NOT NULL DEFAULT 0");
    $conn->query("ALTER TABLE moa_requests ADD COLUMN IF NOT EXISTS admin_viewed TINYINT(1) NOT NULL DEFAULT 0");
    // ── NEW (this adjustment): notif_type — see detectAndNotifyComplianceEvents()
    // above for what creates 'revision_complied' / 'schedule_agreed' /
    // 'schedule_declined' rows; every other existing row defaults to
    // 'new_request'. Returned here so the drawer can label/filter by it.
    $conn->query("ALTER TABLE moa_requests ADD COLUMN IF NOT EXISTS notif_type VARCHAR(30) NOT NULL DEFAULT 'new_request'");

    $rows = [];
    $res = $conn->query("SELECT mr.id, mr.user_id, mr.request_type, mr.company_name, mr.company_address, mr.position, mr.contact_first_name, mr.contact_middle_name, mr.contact_last_name, mr.submitted_at, mr.notif_type, mr.notif_detail FROM moa_requests mr WHERE mr.admin_viewed=0 AND mr.transferred=1 ORDER BY mr.submitted_at DESC");
    if ($res) {
        while ($r = $res->fetch_assoc()) {
            $rows[] = [
                'id'              => (int)$r['id'],
                'user_id'         => (int)$r['user_id'],
                'request_type'    => $r['request_type'],
                'company_name'    => $r['company_name'],
                'company_address' => $r['company_address'],
                'position'        => $r['position'],
                'contact_name'    => trim($r['contact_first_name'].' '.$r['contact_middle_name'].' '.$r['contact_last_name']),
                'submitted_at'    => $r['submitted_at'] ? date('M d, Y g:i A', strtotime($r['submitted_at'] ?? '')) : '',
                'notif_type'      => $r['notif_type'] ?: 'new_request',
                // NEW (this adjustment): for "Revision Complied" — the sections the company updated (+ the admin's original message)
                'detail'          => cvNotifDetailFields($r['notif_detail'] ?? null),
                'comment'         => cvNotifDetailComment($r['notif_detail'] ?? null),
            ];
        }
    }
    // ── NEW (this adjustment): requirement-upload notifications (see
    // detectAndNotifyRequirementUploads()) live in their own table, so they are
    // merged into the list here, newest first, in among the MOA notifications.
    // Both lists are already newest-first, so this is a plain merge; on equal
    // timestamps the existing MOA rows keep their place ahead of the new ones.
    $uploadRows = cvFetchReqUploadNotifications($conn);
    if (!empty($uploadRows)) {
        $merged = []; $mi = 0; $ui = 0;
        $mCount = count($rows); $uCount = count($uploadRows);
        while ($mi < $mCount || $ui < $uCount) {
            if ($ui >= $uCount) { $merged[] = $rows[$mi++]; continue; }
            if ($mi >= $mCount) { $u = $uploadRows[$ui++]; unset($u['_ts']); $merged[] = $u; continue; }
            $mTs = strtotime($rows[$mi]['submitted_at'] ?? '') ?: 0;
            if ($uploadRows[$ui]['_ts'] > $mTs) { $u = $uploadRows[$ui++]; unset($u['_ts']); $merged[] = $u; }
            else { $merged[] = $rows[$mi++]; }
        }
        $rows = $merged;
    }
    echo json_encode(['success'=>true,'rows'=>$rows,'pending_count'=>count($rows)]);
    exit;
}

/* ================= AJAX: MARK MOA NOTIFICATION AS VIEWED =================
   NEW (this adjustment) — called the moment the admin clicks "View
   Request" on a notification card. Flips admin_viewed to 1 so it no
   longer shows up in ajax_fetch_moa_notifications above; the inbox count
   / badges update accordingly. This never touches moa_requests.status or
   any other field the company-facing moa_request.php reads, so nothing
   about the revise/resubmit cycle is affected.
   ================================ */
if (isset($_POST['ajax_moa_mark_notification_viewed'])) {
    header('Content-Type: application/json');
    $conn->query("ALTER TABLE moa_requests ADD COLUMN IF NOT EXISTS admin_viewed TINYINT(1) NOT NULL DEFAULT 0");
    $moa_id = intval($_POST['moa_id'] ?? 0);
    if (!$moa_id) { echo json_encode(['success'=>false,'message'=>'Invalid request.']); exit; }
    // ── UPDATED (this adjustment): an id at/above CV_REQ_NOTIF_ID_OFFSET is a
    // requirement-upload notification (see detectAndNotifyRequirementUploads()) —
    // it lives in its own table, so mark it there. Every other id is a
    // moa_requests row and is handled exactly as before.
    if ($moa_id >= CV_REQ_NOTIF_ID_OFFSET) {
        $ok = cvMarkReqUploadNotificationViewed($conn, $moa_id - CV_REQ_NOTIF_ID_OFFSET);
    } else {
        $stmt = $conn->prepare("UPDATE moa_requests SET admin_viewed=1 WHERE id=?");
        $stmt->bind_param("i", $moa_id); $ok = $stmt->execute(); $stmt->close();
    }
    // FIX (this adjustment): same rule as the inbox list (admin_viewed=0 AND transferred=1) — see the page-load badge count
    // further down. Without it, clicking View on a real notification could bring a "ghost" un-transferred row's count back.
    $pc = $conn->query("SELECT COUNT(*) as total FROM moa_requests WHERE admin_viewed=0 AND transferred=1");
    $pcRow = $pc ? $pc->fetch_assoc() : ['total'=>0];
    // the badge count includes the un-viewed requirement-upload notifications too
    echo json_encode(['success'=>(bool)$ok,'pending_count'=>(int)($pcRow['total']??0) + cvReqUploadUnviewedCount($conn)]);
    exit;
}

/* ================= AJAX: GET A COMPANY'S "REVISION COMPLIED" DETAIL =================
   NEW (this adjustment) — read by the Review MOA preview when it opens, so it can show which section(s) the company
   updated (with their new values) and mark them in the section list. Only returned while it still applies: the MOA is
   in "Pending for Review", not flagged again, not verified, and the admin has not acted on it yet (acting clears it —
   see cvOnAdminMoaAction()). ================================ */
if (isset($_POST['ajax_get_moa_compliance'])) {
    header('Content-Type: application/json');
    $cUid = intval($_POST['user_id'] ?? 0);
    $cDetail = null;
    if ($cUid > 0) {
        try {
            $cType = resolveMoaRequirementType($conn, $cUid);
            $cq = $conn->prepare("SELECT moa_complied_detail, moa_needs_revision, moa_workflow_stage, status FROM company_requirements WHERE user_id=? AND requirement_type=?");
            if ($cq) {
                $cq->bind_param("is", $cUid, $cType); $cq->execute();
                $cr = $cq->get_result()->fetch_assoc(); $cq->close();
                $cStage = $cr['moa_workflow_stage'] ?? 'pending';
                if ($cStage === 'reviewing') $cStage = 'pending';
                if ($cr && !empty($cr['moa_complied_detail']) && empty($cr['moa_needs_revision']) && $cStage === 'pending' && ($cr['status'] ?? '') !== 'Verified') {
                    $cd = json_decode($cr['moa_complied_detail'], true);
                    if (is_array($cd) && !empty($cd['fields'])) $cDetail = $cd;
                }
            }
        } catch (\Throwable $e) { $cDetail = null; }
    }
    // ── NEW (this adjustment): which section checkboxes the Review MOA preview must TEMPORARILY DISABLE. A section is locked while
    // (a) it is flagged and the company hasn't complied yet (moa_needs_revision + moa_flagged_fields — the flag is still
    // outstanding), or (b) it is empty (blank in company_information — flagging a section clears its value, so a flagged,
    // not-yet-complied section is empty until the company fills it in). "awaiting" wins over "empty". Same flag key → column map
    // as blankFlaggedCompanyInfoFields() / cvBuildComplianceDetail(). It lifts by itself once the company complies.
    $lockedFields = [];
    $flagsOutstanding = false;   // NEW (this adjustment): true while the MOA is flagged and the company hasn't complied yet — Approve MOA is locked meanwhile
    if ($cUid > 0) {
        try {
            $lockColumnFor = ['company_name' => 'company', 'company_profile' => 'company_profile', 'company_address' => 'company_address',
                              'position' => 'position', 'contact_first_name' => 'contact_first_name', 'contact_middle_name' => 'contact_middle_initial',
                              'contact_last_name' => 'contact_last_name', 'telephone' => 'telephone'];
            $lockType = resolveMoaRequirementType($conn, $cUid);
            $lfq = $conn->prepare("SELECT moa_needs_revision, moa_flagged_fields FROM company_requirements WHERE user_id=? AND requirement_type=?");
            if ($lfq) {
                $lfq->bind_param("is", $cUid, $lockType); $lfq->execute();
                $lfRow = $lfq->get_result()->fetch_assoc(); $lfq->close();
                if ($lfRow && !empty($lfRow['moa_needs_revision'])) $flagsOutstanding = true;
                if ($lfRow && !empty($lfRow['moa_needs_revision']) && !empty($lfRow['moa_flagged_fields'])) {
                    $lfList = json_decode($lfRow['moa_flagged_fields'], true);
                    if (is_array($lfList)) {
                        foreach ($lfList as $lfKey) {
                            if (is_string($lfKey) && isset($lockColumnFor[$lfKey])) $lockedFields[$lfKey] = 'awaiting';
                        }
                    }
                }
            }
            $lcq = $conn->prepare("SELECT * FROM company_information WHERE user_id=?");
            if ($lcq) {
                $lcq->bind_param("i", $cUid); $lcq->execute();
                $lcRow = $lcq->get_result()->fetch_assoc() ?: []; $lcq->close();
                if ($lcRow) {
                    foreach ($lockColumnFor as $lfKey => $lfCol) {
                        if (!isset($lockedFields[$lfKey]) && trim((string)($lcRow[$lfCol] ?? '')) === '') $lockedFields[$lfKey] = 'empty';
                    }
                }
            }
        } catch (\Throwable $e) { $lockedFields = []; }
    }
    echo json_encode(['success' => true, 'detail' => $cDetail, 'locked_fields' => (object)$lockedFields, 'flags_outstanding' => $flagsOutstanding]);
    exit;
}

/* ================= AJAX: FETCH ONE COMPANY'S FULLY-RENDERED ROW (LIVE TABLE UPDATES) =================
   NEW (this adjustment) — lets the page pull a company row into the
   "Existing"/"New" table live, without a manual reload, the moment a new
   MOA request notification is detected by the background poller (see
   pollMoaNotifications() and liveInsertOrUpdateCompanyRow() further
   down). Reuses renderCompanyValidationRow() — the exact same function
   the initial page load uses — via output buffering, so the row that
   gets inserted live is byte-for-byte identical to what a full reload
   would have produced: same classification-based checklist, same MOA
   workflow UI, same everything. This intentionally duplicates the small
   bit of "which compliance list applies to this company" logic that
   already lives inline in the two foreach loops that render the page's
   two tables (below), since that logic is only a few lines and pulling
   it out into a shared helper is out of scope for this addition — every
   other part of both loops is untouched. ================================ */
if (isset($_POST['ajax_fetch_company_row'])) {
    header('Content-Type: application/json');
    global $companyReqLabels, $remarks, $private_compliance_reqs, $public_compliance_reqs;

    $uid = intval($_POST['user_id'] ?? 0);
    if (!$uid) { echo json_encode(['success'=>false,'message'=>'Invalid user_id.']); exit; }

    // NEW (this adjustment): keep this live-refresh query in step with the main
    // table query — it must return company_profile too (see the note there).
    $conn->query("ALTER TABLE company_information ADD COLUMN IF NOT EXISTS company_profile TEXT NULL AFTER company_address");
    $cq = $conn->prepare("
        SELECT ci.user_id, ci.company, ci.company_address, ci.company_profile, ci.telephone,
               ci.contact_first_name, ci.contact_middle_initial, ci.contact_last_name,
               ci.position, COALESCE(NULLIF(ci.company_type, ''), NULLIF(u.company_type, '')) AS company_type,
               u.company_validation_status
        FROM company_information ci
        INNER JOIN users u ON ci.user_id = u.id
        WHERE u.role = 'company' AND u.co_is_archived = 0 AND ci.user_id = ?
    ");
    $cq->bind_param("i", $uid);
    $cq->execute();
    $company = $cq->get_result()->fetch_assoc();
    $cq->close();

    if (!$company) { echo json_encode(['success'=>false,'message'=>'Company not found.']); exit; }

    $requestTypeRow = resolveCompanyRequestType($conn, $uid);
    $bucket = ($requestTypeRow === 'new') ? 'new' : 'existing';

    $isPublicRow      = (strtolower($company['company_type'] ?? '') === 'public');
    $complianceListRow = $isPublicRow ? $public_compliance_reqs : $private_compliance_reqs;

    if ($bucket === 'new') {
        // Mirrors the "New" table's foreach exactly (see below).
        $clistRow = array_merge(["moa_document"], $complianceListRow);
    } else {
        // Mirrors the "Existing" table's foreach exactly (see below).
        $clistRow = $complianceListRow;
        if (isCompanyRequestTypeExisting($conn, $uid)) {
            $clistRow[] = 'moa_existing_upload';
        }
    }

    ob_start();
    renderCompanyValidationRow($conn, $company, $clistRow, $companyReqLabels, $remarks);
    $rowHtml = ob_get_clean();

    echo json_encode(['success' => true, 'html' => $rowHtml, 'bucket' => $bucket, 'user_id' => $uid]);
    exit;
}

/* ================= AJAX: GET MOA CONTACT EMAIL (for the Reject preview modal) =================
   ── UPDATED (this adjustment): now also accepts a `user_id` in place of
   `moa_id`, since the new "Flag for Revision" action on the New MOA
   table's merged "Pending for Review" stage is keyed by user_id (a
   company), not a moa_requests row id. If moa_id is supplied it behaves
   exactly as before (legacy inbox reject modal); otherwise it resolves
   the email straight from the user_id. ================================ */
if (isset($_POST['ajax_get_moa_contact_email'])) {
    header('Content-Type: application/json');
    $moa_id  = intval($_POST['moa_id'] ?? 0);
    $user_id = intval($_POST['user_id'] ?? 0);
    $email = '';
    $telephone = '';   // NEW (this adjustment)
    $resolvedUserId = 0;
    if ($moa_id) {
        $stmt = $conn->prepare("SELECT user_id FROM moa_requests WHERE id=?");
        $stmt->bind_param("i", $moa_id); $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc(); $stmt->close();
        if ($row && !empty($row['user_id'])) $resolvedUserId = (int)$row['user_id'];
    } elseif ($user_id) {
        $resolvedUserId = $user_id;
    }
    if ($resolvedUserId) {
        $uq = $conn->prepare("SELECT email FROM users WHERE id=?");
        $uq->bind_param("i", $resolvedUserId); $uq->execute();
        $u = $uq->get_result()->fetch_assoc(); $uq->close();
        $email = $u['email'] ?? '';
    }
    // ── NEW (this adjustment): the contact's telephone, for the company details box in the same modal. In the legacy inbox flow
    // (moa_id) the request's own number is used — the contact name shown beside it comes from that same request; otherwise, or when
    // that is blank, the number on file for the company (company_information — what the Company Details tab shows). Guarded so
    // it can never break the email lookup above.
    try {
        if ($moa_id) {
            $telQ = $conn->prepare("SELECT telephone FROM moa_requests WHERE id=?");
            if ($telQ) {
                $telQ->bind_param("i", $moa_id); $telQ->execute();
                $telRow = $telQ->get_result()->fetch_assoc(); $telQ->close();
                $telephone = trim((string)($telRow['telephone'] ?? ''));
            }
        }
        if ($telephone === '' && $resolvedUserId) {
            $telQ = $conn->prepare("SELECT telephone FROM company_information WHERE user_id=?");
            if ($telQ) {
                $telQ->bind_param("i", $resolvedUserId); $telQ->execute();
                $telRow = $telQ->get_result()->fetch_assoc(); $telQ->close();
                $telephone = trim((string)($telRow['telephone'] ?? ''));
            }
        }
    } catch (\Throwable $e) { $telephone = ''; }
    // ── NEW (this adjustment): the Company Profile / Brief Description is no longer a flaggable section in the Review MOA
    // preview — it is shown in the company details box instead (below Email), so the same lookup returns it. Read from
    // company_information (what the Company Details tab shows). Guarded so it can never break the email/telephone lookup.
    $companyProfile = '';
    try {
        if ($resolvedUserId) {
            $cpQ = $conn->prepare("SELECT company_profile FROM company_information WHERE user_id=?");
            if ($cpQ) {
                $cpQ->bind_param("i", $resolvedUserId); $cpQ->execute();
                $cpRow = $cpQ->get_result()->fetch_assoc(); $cpQ->close();
                $companyProfile = trim((string)($cpRow['company_profile'] ?? ''));
            }
        }
    } catch (\Throwable $e) { $companyProfile = ''; }
    echo json_encode(['email' => $email, 'telephone' => $telephone, 'company_profile' => $companyProfile]);
    exit;
}

/* ================= AJAX: ACCEPT OR REJECT MOA (simplified drawer actions) =================
   ── NOTE (this adjustment): the MOA Requests drawer no longer has
   Accept/Reject buttons — new MOA requests are now transferred into the
   "New MOA" table automatically by autoIngestPendingMoaRequests() (see
   near the top of this file) and the drawer only shows a "View Request"
   notification (see ajax_moa_mark_notification_viewed and the updated
   ajax_fetch_moa_requests handler below). This endpoint is left fully
   intact, unused by the current UI, purely for backward compatibility —
   removing working code that isn't the source of the requested change
   would be an unrelated disruption. ================================ */
if (isset($_POST['ajax_moa_drawer_action'])) {
    header('Content-Type: application/json');
    ob_start();

    $moa_id = intval($_POST['moa_id'] ?? 0);
    $action  = trim($_POST['action'] ?? ''); // 'accept' or 'reject'

    if (!$moa_id || !in_array($action, ['accept', 'reject'])) {
        ob_end_clean();
        echo json_encode(['success'=>false,'message'=>'Invalid request.']);
        exit;
    }

    moaDebugLog('ajax_moa_drawer_action:start', ['moa_id'=>$moa_id,'action'=>$action]);

    // Guard: ensure the telephone column exists on moa_requests before selecting it below
    // (mirrors the guard-column pattern used elsewhere in this file).
    $conn->query("ALTER TABLE moa_requests ADD COLUMN IF NOT EXISTS telephone VARCHAR(30) NULL AFTER contact_last_name");

    // Fetch MOA row
    $stmtMoa = $conn->prepare("SELECT id, user_id, company_name, company_profile, company_address, telephone, contact_first_name, contact_middle_name, contact_last_name, position, request_type, moa_pdf, moa_pdf_filename FROM moa_requests WHERE id=?");
    if (!$stmtMoa) { ob_end_clean(); echo json_encode(['success'=>false,'message'=>'DB prepare failed.']); exit; }
    $stmtMoa->bind_param("i", $moa_id); $stmtMoa->execute();
    $moaRow = $stmtMoa->get_result()->fetch_assoc(); $stmtMoa->close();

    if (!$moaRow) { ob_end_clean(); echo json_encode(['success'=>false,'message'=>'MOA request not found.']); exit; }

    if ($action === 'reject') {
        $conn->query("ALTER TABLE moa_requests ADD COLUMN IF NOT EXISTS moa_workflow_status VARCHAR(30) DEFAULT 'pending'");
        $stmt = $conn->prepare("UPDATE moa_requests SET status='Rejected', moa_workflow_status='rejected' WHERE id=?");
        $stmt->bind_param("i", $moa_id); $stmt->execute(); $stmt->close();
        $pc = $conn->query("SELECT COUNT(*) as total FROM moa_requests WHERE status='Pending'");
        $pcRow = $pc ? $pc->fetch_assoc() : ['total'=>0];
        ob_end_clean();
        echo json_encode(['success'=>true,'action'=>'rejected','pending_count'=>(int)($pcRow['total']??0)]);
        exit;
    }

    // ACCEPT — transfer blob to company_requirements with stage=pending
    $moa_user_id         = intval($moaRow['user_id'] ?? 0);
    $moa_company_name    = $moaRow['company_name'] ?? '';
    $moa_company_address = $moaRow['company_address'] ?? '';
    $moa_telephone       = $moaRow['telephone'] ?? '';
    $moa_position        = $moaRow['position'] ?? '';
    $moa_contact_first   = $moaRow['contact_first_name'] ?? '';
    $moa_contact_middle  = $moaRow['contact_middle_name'] ?? '';
    $moa_contact_last    = $moaRow['contact_last_name'] ?? '';
    $moa_pdf_blob        = $moaRow['moa_pdf'];
    $moa_pdf_filename    = $moaRow['moa_pdf_filename'] ?? '';
    // ── NEW (this adjustment): the original request type ('new' or
    // 'existing') from moa_requests, forwarded into transferMoaToRequirements()
    // below so it gets automatically saved on the company_requirements row.
    $moa_request_type    = $moaRow['request_type'] ?? null;

    if ($moa_user_id > 0) {
        ensureCompanyInfoColumns($conn);

        $ciCheck = $conn->prepare("SELECT user_id FROM company_information WHERE user_id=?");
        $ciCheck->bind_param("i", $moa_user_id); $ciCheck->execute();
        $ciExists = $ciCheck->get_result()->fetch_assoc(); $ciCheck->close();

        if ($ciExists) {
            $ciUpd = $conn->prepare("UPDATE company_information SET company=?, contact_first_name=?, contact_middle_initial=?, contact_last_name=?, telephone=? WHERE user_id=?");
            if ($ciUpd) { $ciUpd->bind_param("sssssi",$moa_company_name,$moa_contact_first,$moa_contact_middle,$moa_contact_last,$moa_telephone,$moa_user_id); $ciUpd->execute(); $ciUpd->close(); }
        } else {
            $ciIns = $conn->prepare("INSERT INTO company_information (user_id,company,contact_first_name,contact_middle_initial,contact_last_name,company_address,position,telephone) VALUES (?,?,?,?,?,?,?,?)");
            if ($ciIns) { $ciIns->bind_param("isssssss",$moa_user_id,$moa_company_name,$moa_contact_first,$moa_contact_middle,$moa_contact_last,$moa_company_address,$moa_position,$moa_telephone); $ok=$ciIns->execute(); $ciIns->close(); if(!$ok){$eid=intval($moa_user_id);$eco=$conn->real_escape_string($moa_company_name);$conn->query("INSERT IGNORE INTO company_information (user_id,company) VALUES ($eid,'$eco')");} }
        }

        transferMoaToRequirements($conn, $moa_user_id, $moa_pdf_blob, $moa_pdf_filename, $moa_id, $moa_request_type);
        // Notify the company that their MOA was accepted and is now Pending review
        // in the requirements table workflow.
        sendMoaStageEmail($conn, $moa_user_id, 'pending', '', null);
    }

    $pc = $conn->query("SELECT COUNT(*) as total FROM moa_requests WHERE status='Pending'");
    $pcRow = $pc ? $pc->fetch_assoc() : ['total'=>0];
    ob_end_clean();
    echo json_encode([
        'success'       => true,
        'action'        => 'accepted',
        'user_id'       => $moa_user_id,
        'pending_count' => (int)($pcRow['total']??0),
        'message'       => 'MOA accepted. The document is now in the requirements table. Use the in-table workflow to review and verify it.'
    ]);
    exit;
}

/* ================= AJAX: REJECT MOA WITH EMAIL + PDF ATTACHMENT, THEN MARK AS REJECTED FOR REVISION =================
   ── ADJUSTMENT: this handler previously deleted the original moa_requests
   row and INSERTed a brand-new row with status='Pending', is_revision=1,
   and the flagged field(s) blanked out — the assumption being that the
   company would re-type just those field(s) somewhere else. That never
   surfaces as "Rejected" anywhere, so the company-facing page
   (moa_request.php) — which detects a rejection purely by reading this
   row's `status` column — could never tell the request had been rejected;
   it just looked like an ordinary pending submission forever.

   The row is now updated IN PLACE instead:
     - status              = 'Rejected'
     - moa_workflow_status = 'rejected'
     - flagged_fields      = JSON array of the flagged field key(s) — this
                              is the exact column name moa_request.php reads
                              (see moaResolveFlaggedFields() /
                              moaDecodeFlaggedFields() there) to know which
                              field(s) it should let the company edit.
     - rejection_notes     = the admin's message — this is the exact column
                              name moa_request.php reads and shows to the
                              company as "Administrator's Notes".
     - revision_flags / revision_comment / is_revision are still written,
       unchanged, purely so every existing admin-side display here (the
       "Needs Revision" type chip on the drawer card, the flagged-section
       list + admin note shown in the MOA Details modal, etc.) keeps
       working exactly as it did before.
   Nothing is cleared/blanked anymore — moa_request.php's own revision form
   already pre-fills every field with its current saved value and only
   lets the company edit the flagged one(s), so blanking here was not only
   unnecessary but is what used to make status detection impossible (a
   blank field is not the same signal as "this request was rejected").
   No row is deleted and no new row is inserted, so the same moa_id keeps
   being the single row of record for this company's request throughout
   its whole reject → revise → resubmit → (re-review) lifecycle. Once the
   company resubmits via moa_request.php, that page's own confirm handler
   sets status back to 'Pending' on this same row, and it reappears in the
   admin Pending queue exactly as before.

   ── FURTHER ADJUSTMENT: the admin can now also flag "moa_document" — the
   uploaded MOA file itself — as the thing that needs correction (in
   addition to the existing text-field flags). This is included in
   $allowedFlagKeys below like any other flag, gets saved into the same
   flagged_fields / revision_flags JSON, and is what the frontend's
   openMoaPreview() checks to lock the "View PDF" button until the company
   uploads a new document and resubmits.
   ================================ */
if (isset($_POST['ajax_moa_reject_send'])) {
    header('Content-Type: application/json');
    ob_start();

    $moa_id  = intval($_POST['moa_id'] ?? 0);
    $comment = trim($_POST['comment'] ?? '');
    $rawFlags = $_POST['flags'] ?? [];
    if (!is_array($rawFlags)) $rawFlags = [];

    // Only these MOA-section fields can be flagged as erroneous by the admin.
    // ── UPDATED (adjustment): "moa_document" added so the uploaded file itself
    // can be flagged as the thing that needs correction, not just the text fields.
    $allowedFlagKeys = ['company_name','company_profile','company_address','position','contact_first_name','contact_middle_name','contact_last_name','telephone','moa_document'];
    $flaggedFields = array_values(array_intersect($allowedFlagKeys, $rawFlags));

    if (!$moa_id || $comment === '') {
        ob_end_clean();
        moaDebugLog('ajax_moa_reject_send:validation_fail', ['moa_id'=>$moa_id,'comment_empty'=>($comment==='')]);
        echo json_encode(['success'=>false,'message'=>'Please write a message before sending.']);
        exit;
    }
    if (empty($flaggedFields)) {
        ob_end_clean();
        moaDebugLog('ajax_moa_reject_send:no_flags', ['moa_id'=>$moa_id]);
        echo json_encode(['success'=>false,'message'=>'Please flag at least one section that needs correction before rejecting.']);
        exit;
    }

    moaDebugLog('ajax_moa_reject_send:start', ['moa_id'=>$moa_id,'comment_length'=>strlen($comment),'flags'=>$flaggedFields]);

    try {
        // Guard columns used by this flow (mirrors the guard-column pattern used elsewhere).
        $conn->query("ALTER TABLE moa_requests ADD COLUMN IF NOT EXISTS telephone VARCHAR(30) NULL AFTER contact_last_name");
        $conn->query("ALTER TABLE moa_requests ADD COLUMN IF NOT EXISTS revision_flags TEXT NULL");
        $conn->query("ALTER TABLE moa_requests ADD COLUMN IF NOT EXISTS revision_comment TEXT NULL");
        $conn->query("ALTER TABLE moa_requests ADD COLUMN IF NOT EXISTS is_revision TINYINT(1) NOT NULL DEFAULT 0");
        // ── ADJUSTMENT: guard the two columns moa_request.php reads to
        // detect a rejection and the field(s) that need correction.
        $conn->query("ALTER TABLE moa_requests ADD COLUMN IF NOT EXISTS flagged_fields TEXT NULL");
        $conn->query("ALTER TABLE moa_requests ADD COLUMN IF NOT EXISTS rejection_notes TEXT NULL");

        $stmtMoa = $conn->prepare("SELECT id, user_id, request_type, company_name, company_profile, company_address, telephone, position, contact_first_name, contact_middle_name, contact_last_name, moa_pdf, moa_pdf_filename FROM moa_requests WHERE id=?");
        if (!$stmtMoa) {
            moaDebugLog('ajax_moa_reject_send:prepare_fail', ['moa_id'=>$moa_id,'error'=>$conn->error]);
            ob_end_clean();
            echo json_encode(['success'=>false,'message'=>'DB prepare failed.','debug'=>$conn->error]);
            exit;
        }
        $stmtMoa->bind_param("i", $moa_id); $stmtMoa->execute();
        $moaRow = $stmtMoa->get_result()->fetch_assoc(); $stmtMoa->close();

        if (!$moaRow) {
            moaDebugLog('ajax_moa_reject_send:not_found', ['moa_id'=>$moa_id]);
            ob_end_clean();
            echo json_encode(['success'=>false,'message'=>'MOA request not found. It may have already been processed.']);
            exit;
        }

        $moa_user_id = intval($moaRow['user_id'] ?? 0);
        $companyName = $moaRow['company_name'] ?? 'Your Company';
        $pdfBlob     = $moaRow['moa_pdf'] ?? null;
        $pdfFilename = $moaRow['moa_pdf_filename'] ?? '';

        moaDebugLog('ajax_moa_reject_send:moa_row_fetched', [
            'moa_id'    => $moa_id,
            'user_id'   => $moa_user_id,
            'company'   => $companyName,
            'has_pdf'   => !empty($pdfBlob),
            'pdf_bytes' => !empty($pdfBlob) ? strlen($pdfBlob) : 0,
        ]);

        $toEmail = '';
        $toName  = 'Company Representative';
        if ($moa_user_id > 0) {
            $uq = $conn->prepare("SELECT first_name, last_name, email FROM users WHERE id=?");
            if (!$uq) {
                moaDebugLog('ajax_moa_reject_send:user_prepare_fail', ['moa_id'=>$moa_id,'error'=>$conn->error]);
            } else {
                $uq->bind_param("i", $moa_user_id); $uq->execute();
                $u = $uq->get_result()->fetch_assoc(); $uq->close();
                if ($u) {
                    $toEmail = $u['email'] ?? '';
                    $fn = trim(($u['first_name'] ?? '') . ' ' . ($u['last_name'] ?? ''));
                    if ($fn !== '') $toName = $fn;
                } else {
                    moaDebugLog('ajax_moa_reject_send:user_not_found', ['moa_id'=>$moa_id,'user_id'=>$moa_user_id]);
                }
            }
        } else {
            moaDebugLog('ajax_moa_reject_send:no_user_id_on_moa_row', ['moa_id'=>$moa_id]);
        }

        if (empty($toEmail)) {
            ob_end_clean();
            moaDebugLog('ajax_moa_reject_send:no_email', ['moa_id'=>$moa_id,'user_id'=>$moa_user_id]);
            echo json_encode(['success'=>false,'message'=>'No email address is on file for this company account. Cannot send the rejection email.']);
            exit;
        }

        // Human-readable labels for the flagged fields, used in both the email and the
        // debug log so it is always clear to the company (and to us) what needs fixing.
        // ── FIX: `$GLOBALS` is a PHP superglobal (auto-global) and PHP does not allow
        // superglobals to be captured in a closure's `use (...)` clause — doing so throws
        // "Fatal error: Cannot use auto-global as lexical variable". Superglobals are
        // already implicitly available inside any function/closure body without importing
        // them, so the fix is simply to drop the `use ($GLOBALS)` and reference $GLOBALS
        // directly inside the closure.
        $flaggedLabels = array_map(function($k) {
            return $GLOBALS['moaRejectFlagLabels'][$k] ?? $k;
        }, $flaggedFields);

        // ── DEBUG: sendMoaRejectionEmailWithAttachment now fills $mailDebug with the exact
        // reason for a failure (invalid email format, PHP mail() warning text, or a generic
        // "no mail transport configured" hint) — see moa_debug.log for the full trace, plus
        // it is echoed back in the JSON 'debug' field and appended to the on-screen message.
        $mailDebug = null;
        $sent = sendMoaRejectionEmailWithAttachment($toEmail, $toName, $companyName, $comment, $pdfBlob, $pdfFilename, $flaggedLabels, $mailDebug);
        moaDebugLog('ajax_moa_reject_send:mail_result', ['moa_id'=>$moa_id,'to'=>$toEmail,'sent'=>$sent,'debug'=>$mailDebug]);

        if (!$sent) {
            ob_end_clean();
            $friendly = 'Failed to send the rejection email. Please try again.';
            if (!empty($mailDebug['error'])) {
                $friendly .= ' (' . $mailDebug['error'] . ')';
            }
            echo json_encode(['success'=>false,'message'=>$friendly,'debug'=>$mailDebug]);
            exit;
        }

        // ══════════════════════════════════════════════════════════════
        // Email confirmed sent — mark the SAME row as Rejected in place
        // (see the big comment above the ajax_moa_reject_send handler for
        // the full reasoning). This is what makes the rejection detectable
        // by moa_request.php.
        // ══════════════════════════════════════════════════════════════
        $flaggedFieldsJson = json_encode($flaggedFields);

        $updStmt = $conn->prepare("UPDATE moa_requests
            SET status='Rejected',
                moa_workflow_status='rejected',
                flagged_fields=?,
                rejection_notes=?,
                revision_flags=?,
                revision_comment=?,
                is_revision=1
            WHERE id=?");
        if (!$updStmt) {
            moaDebugLog('ajax_moa_reject_send:mark_rejected_prepare_fail', ['moa_id'=>$moa_id,'error'=>$conn->error]);
            ob_end_clean();
            echo json_encode(['success'=>false,'message'=>'Rejection email was sent, but the request could not be marked as Rejected.','debug'=>$conn->error]);
            exit;
        }
        $updStmt->bind_param("ssssi", $flaggedFieldsJson, $comment, $flaggedFieldsJson, $comment, $moa_id);
        $markRejectedOk = $updStmt->execute();
        moaDebugLog('ajax_moa_reject_send:marked_rejected', ['moa_id'=>$moa_id,'ok'=>$markRejectedOk,'error'=>$updStmt->error?:null,'flags'=>$flaggedFields]);
        $updStmt->close();

        $pc = $conn->query("SELECT COUNT(*) as total FROM moa_requests WHERE status='Pending'");
        $pcRow = $pc ? $pc->fetch_assoc() : ['total'=>0];

        ob_end_clean();
        echo json_encode([
            'success'        => true,
            'pending_count'  => (int)($pcRow['total']??0),
            'new_moa_id'     => $moa_id,
            'flagged_fields' => $flaggedFields,
            'message'        => 'Rejection email sent. The request has been marked as Rejected — the company will now see a "Needs Revision" status and can revise the flagged section(s) and resubmit.'
        ]);
        exit;
    } catch (Throwable $ex) {
        // ── DEBUG: catch-all so an unexpected fatal (e.g. finfo error, DB disconnect mid-
        // request, or a bind_param mismatch) still returns valid JSON instead of silently
        // breaking the frontend fetch() call (which previously would have shown a
        // generic/blank failure with no clue why). The full exception message + stack
        // trace is written to moa_debug.log for pinpointing the exact cause.
        moaDebugLog('ajax_moa_reject_send:exception', ['moa_id'=>$moa_id,'message'=>$ex->getMessage(),'trace'=>$ex->getTraceAsString()]);
        ob_end_clean();
        echo json_encode(['success'=>false,'message'=>'Unexpected error while sending: '.$ex->getMessage()]);
        exit;
    }
}

/* ================= AJAX: FLAG A "PENDING FOR REVIEW" MOA ROW FOR REVISION =================
   NEW (this adjustment) — the counterpart to ajax_moa_reject_send above,
   but triggered from the merged "Pending for Review" stage of the New
   MOA table's in-table workflow (keyed by user_id, i.e. the company)
   instead of from the old inbox (keyed by a moa_requests row id). This is
   how "the MOA of the user is now being flagged for the section that
   needs revision" is implemented: the admin never leaves the New MOA
   table — no separate Accept/Reject inbox step exists anymore — they
   just flag the section(s) that are wrong right there on the row.
   Reuses the exact same email template/attachment logic
   (sendMoaRejectionEmailWithAttachment) as the legacy flow. Once the
   email is confirmed sent:
     - The company's moa_requests row (kept alive by
       autoIngestPendingMoaRequests()'s $deleteSourceRow=false, so all its
       original text fields/PDF are still there to source from) is
       updated in place exactly like ajax_moa_reject_send does — status=
       'Rejected', flagged_fields/rejection_notes set — so
       moa_request.php's existing revise/resubmit flow keeps working
       completely unmodified. `transferred` is also reset to 0, so the
       moment the company resubmits (moa_request.php flips status back to
       'Pending'), autoIngestPendingMoaRequests() automatically re-ingests
       it into the New table again — still with no manual "Accept" step.
     - The company_requirements MOA row itself stays on
       moa_workflow_stage='pending' ("Pending for Review") — it is NOT
       kicked out of the table — but moa_needs_revision/moa_flagged_fields/
       moa_revision_comment are set so the row visibly shows a
       "Needs Revision" badge with the flagged section(s) and the admin's
       note until the company resubmits (at which point
       transferMoaToRequirements() clears those columns again).
   ================================ */
if (isset($_POST['ajax_moa_table_flag_revision'])) {
    header('Content-Type: application/json');
    ob_start();

    $user_id  = intval($_POST['user_id'] ?? 0);
    $comment  = trim($_POST['comment'] ?? '');
    $rawFlags = $_POST['flags'] ?? [];
    if (!is_array($rawFlags)) $rawFlags = [];

    $allowedFlagKeys = ['company_name','company_profile','company_address','position','contact_first_name','contact_middle_name','contact_last_name','telephone','moa_document'];
    $flaggedFields = array_values(array_intersect($allowedFlagKeys, $rawFlags));

    if (!$user_id || $comment === '') {
        ob_end_clean();
        echo json_encode(['success'=>false,'message'=>'Please write a message before sending.']);
        exit;
    }
    if (empty($flaggedFields)) {
        ob_end_clean();
        echo json_encode(['success'=>false,'message'=>'Please flag at least one section that needs correction before sending.']);
        exit;
    }

    moaDebugLog('ajax_moa_table_flag_revision:start', ['user_id'=>$user_id,'comment_length'=>strlen($comment),'flags'=>$flaggedFields]);

    try {
        $conn->query("ALTER TABLE moa_requests ADD COLUMN IF NOT EXISTS telephone VARCHAR(30) NULL AFTER contact_last_name");
        $conn->query("ALTER TABLE moa_requests ADD COLUMN IF NOT EXISTS revision_flags TEXT NULL");
        $conn->query("ALTER TABLE moa_requests ADD COLUMN IF NOT EXISTS revision_comment TEXT NULL");
        $conn->query("ALTER TABLE moa_requests ADD COLUMN IF NOT EXISTS is_revision TINYINT(1) NOT NULL DEFAULT 0");
        $conn->query("ALTER TABLE moa_requests ADD COLUMN IF NOT EXISTS flagged_fields TEXT NULL");
        $conn->query("ALTER TABLE moa_requests ADD COLUMN IF NOT EXISTS rejection_notes TEXT NULL");
        $conn->query("ALTER TABLE moa_requests ADD COLUMN IF NOT EXISTS transferred TINYINT(1) NOT NULL DEFAULT 0");
        $conn->query("ALTER TABLE company_requirements ADD COLUMN IF NOT EXISTS moa_needs_revision TINYINT(1) NOT NULL DEFAULT 0");
        $conn->query("ALTER TABLE company_requirements ADD COLUMN IF NOT EXISTS moa_flagged_fields TEXT NULL");
        $conn->query("ALTER TABLE company_requirements ADD COLUMN IF NOT EXISTS moa_revision_comment TEXT NULL");

        // ══════════════════════════════════════════════════════════════
        // FIX (this adjustment): RE-FLAGGING WHILE AN EARLIER FLAG IS STILL
        // OUTSTANDING (e.g. the admin reviews the MOA again and flags more
        // sections while the first revision's undo toast is still active).
        // The Review MOA preview locks every section that is already
        // flagged and awaiting the company ("awaiting" — see
        // ajax_get_moa_compliance), so the second submission only ever
        // carries the NEW section(s). Previously that new list simply
        // OVERWROTE moa_flagged_fields / moa_requests.flagged_fields, so the
        // earlier flagged section(s) dropped out of the flag list: their
        // values stayed deleted but the company was no longer asked to fix
        // them, and the flag was left half-applied. The earlier section(s)
        // that are still awaiting compliance are now carried forward and
        // merged with the new selection, so every flagged section stays
        // flagged, is (re)cleared in company_information, is regenerated
        // into the MOA document, and is listed in the email and the row's
        // "Needs Revision" note. Once the company complies (the flag is
        // cleared on their side) nothing is carried forward any more.
        // ══════════════════════════════════════════════════════════════
        $newlySelectedFlags  = $flaggedFields;
        $outstandingFlags    = [];
        $crMoaTypeOutstanding = resolveMoaRequirementType($conn, $user_id);
        $ofq = $conn->prepare("SELECT moa_needs_revision, moa_flagged_fields FROM company_requirements WHERE user_id=? AND requirement_type=?");
        if ($ofq) {
            $ofq->bind_param("is", $user_id, $crMoaTypeOutstanding); $ofq->execute();
            $ofRow = $ofq->get_result()->fetch_assoc(); $ofq->close();
            if ($ofRow && !empty($ofRow['moa_needs_revision']) && !empty($ofRow['moa_flagged_fields'])) {
                $ofList = json_decode((string)$ofRow['moa_flagged_fields'], true);
                if (is_array($ofList)) {
                    $outstandingFlags = array_values(array_intersect($allowedFlagKeys, array_filter($ofList, 'is_string')));
                }
            }
        }
        if (!empty($outstandingFlags)) {
            // keep the checklist's own order, no duplicates
            $flaggedFields = array_values(array_intersect($allowedFlagKeys, array_unique(array_merge($outstandingFlags, $newlySelectedFlags))));
            moaDebugLog('ajax_moa_table_flag_revision:merged_outstanding_flags', ['user_id'=>$user_id,'outstanding'=>$outstandingFlags,'new'=>$newlySelectedFlags,'merged'=>$flaggedFields]);
        }

        // The moa_requests row for this company is kept alive by the
        // auto-ingest step, so we can still read the original submitted
        // text fields + PDF from it (most recent row for this user_id).
        // ── UPDATED (this adjustment): a company whose MOA was instead
        // auto-generated at registration by company_register.php (see
        // resolveMoaRequirementType()'s docblock — it's saved under
        // requirement_type='moa') never had a moa_requests row created
        // for it at all, since that registration flow doesn't use that
        // table. This lookup is now OPTIONAL rather than required — if no
        // row is found, $moaRow simply stays null and every piece of
        // data below falls back to company_information/users instead, so
        // sending the flag-for-revision email no longer hard-fails with
        // "No original MOA request record found" for these companies.
        $stmtMoa = $conn->prepare("SELECT id, user_id, request_type, company_name, company_profile, company_address, telephone, position, contact_first_name, contact_middle_name, contact_last_name, moa_pdf, moa_pdf_filename FROM moa_requests WHERE user_id=? ORDER BY id DESC LIMIT 1");
        if (!$stmtMoa) {
            moaDebugLog('ajax_moa_table_flag_revision:prepare_fail', ['user_id'=>$user_id,'error'=>$conn->error]);
            ob_end_clean();
            echo json_encode(['success'=>false,'message'=>'DB prepare failed.','debug'=>$conn->error]);
            exit;
        }
        $stmtMoa->bind_param("i", $user_id); $stmtMoa->execute();
        $moaRow = $stmtMoa->get_result()->fetch_assoc(); $stmtMoa->close();

        moaDebugLog('ajax_moa_table_flag_revision:moa_row_lookup', ['user_id'=>$user_id,'found'=>(bool)$moaRow]);

        $moa_id      = $moaRow ? (int)$moaRow['id'] : 0;
        $companyName = $moaRow['company_name'] ?? null;

        // ── NEW (this adjustment): when there's no moa_requests row,
        // source the company name from company_information instead (this
        // is always populated for a registered company, regardless of
        // which MOA path they came through).
        if (empty($companyName)) {
            $ciq = $conn->prepare("SELECT company FROM company_information WHERE user_id=?");
            if ($ciq) {
                $ciq->bind_param("i", $user_id); $ciq->execute();
                $cir = $ciq->get_result()->fetch_assoc(); $ciq->close();
                $companyName = $cir['company'] ?? 'Your Company';
            } else {
                $companyName = 'Your Company';
            }
        }

        // The attachment sent to the company should be whatever file is
        // CURRENTLY on record in the requirements table (in case it was
        // updated since the original submission); fall back to the
        // original moa_requests blob if, for any reason, it isn't there.
        $pdfBlob     = null;
        $pdfFilename = $moaRow['moa_pdf_filename'] ?? '';
        // ── UPDATED (this adjustment): resolve the actual stored key
        // ('moa_document' or legacy 'moa') so this still finds the file
        // for a registration-generated MOA.
        $crMoaType = resolveMoaRequirementType($conn, $user_id);
        $crq = $conn->prepare("SELECT file_name FROM company_requirements WHERE user_id=? AND requirement_type=?");
        $crq->bind_param("is", $user_id, $crMoaType); $crq->execute();
        $crr = $crq->get_result()->fetch_assoc(); $crq->close();
        if (!empty($crr['file_name'])) { $pdfBlob = $crr['file_name']; }
        elseif (!empty($moaRow['moa_pdf'])) { $pdfBlob = $moaRow['moa_pdf']; }

        $toEmail = '';
        $toName  = 'Company Representative';
        $uq = $conn->prepare("SELECT first_name, last_name, email FROM users WHERE id=?");
        if ($uq) {
            $uq->bind_param("i", $user_id); $uq->execute();
            $u = $uq->get_result()->fetch_assoc(); $uq->close();
            if ($u) {
                $toEmail = $u['email'] ?? '';
                $fn = trim(($u['first_name'] ?? '') . ' ' . ($u['last_name'] ?? ''));
                if ($fn !== '') $toName = $fn;
            }
        }

        if (empty($toEmail)) {
            ob_end_clean();
            moaDebugLog('ajax_moa_table_flag_revision:no_email', ['user_id'=>$user_id]);
            echo json_encode(['success'=>false,'message'=>'No email address is on file for this company account. Cannot send the revision email.']);
            exit;
        }

        $flaggedLabels = array_map(function($k) {
            return $GLOBALS['moaRejectFlagLabels'][$k] ?? $k;
        }, $flaggedFields);

        $mailDebug = null;
        // ── NEW (this adjustment): undoable? Then snapshot everything this is about to change (the MOA row + its document,
        // the company's information, the request rows) and HOLD BACK the email — it is sent when the undo window closes,
        // with the same MOA document as attachment (kept in a temp file meanwhile). See "UNDO FOR MOA PROCESSES".
        $mUndo = null;
        if (!empty($_POST['moa_undoable'])) {
            $mUndo = ['token' => bin2hex(random_bytes(12))];
            $mUndo['before']     = cvMoaUndoCapture($conn, $user_id, true);
            $mUndo['blob_files'] = cvMoaUndoStashBlobs($conn, $mUndo['before']['cr']);
            $mailPdfPath = null;
            if ($pdfBlob !== null && $pdfBlob !== '') {
                $mailPdfPath = tempnam(sys_get_temp_dir(), 'cvmoa_');
                if ($mailPdfPath !== false && file_put_contents($mailPdfPath, $pdfBlob) === false) { @unlink($mailPdfPath); $mailPdfPath = null; }
                if ($mailPdfPath === false) $mailPdfPath = null;
            }
            cvMoaUndoQueueEmail($mUndo['token'], ['kind'=>'flag','to_email'=>$toEmail,'to_name'=>$toName,'company_name'=>$companyName,'comment'=>$comment,'pdf_path'=>$mailPdfPath,'pdf_filename'=>$pdfFilename,'flagged_labels'=>$flaggedLabels,'user_id'=>$user_id]);
            $sent = true;
        } else {
            $sent = sendMoaRejectionEmailWithAttachment($toEmail, $toName, $companyName, $comment, $pdfBlob, $pdfFilename, $flaggedLabels, $mailDebug);
        }
        moaDebugLog('ajax_moa_table_flag_revision:mail_result', ['user_id'=>$user_id,'to'=>$toEmail,'sent'=>$sent,'debug'=>$mailDebug]);

        if (!$sent) {
            ob_end_clean();
            $friendly = 'Failed to send the revision email. Please try again.';
            if (!empty($mailDebug['error'])) $friendly .= ' (' . $mailDebug['error'] . ')';
            echo json_encode(['success'=>false,'message'=>$friendly,'debug'=>$mailDebug]);
            exit;
        }

        $flaggedFieldsJson = json_encode($flaggedFields);

        // ── UPDATED (this adjustment): only update the moa_requests row
        // in place when one actually exists (see the lookup above) — a
        // company whose MOA came from company_register.php's
        // auto-generate path never had one, and there is nothing there
        // to safely update. moa_request.php's own resubmission detection
        // for THAT company therefore doesn't apply; the flag is still
        // fully recorded below on company_requirements (moa_needs_revision
        // etc.), which is what actually drives the "Needs Revision" badge
        // shown in this admin table, and the company still receives the
        // email with the flagged section(s) and message either way.
        if ($moaRow) {
            $updStmt = $conn->prepare("UPDATE moa_requests
                SET status='Rejected',
                    moa_workflow_status='rejected',
                    flagged_fields=?,
                    rejection_notes=?,
                    revision_flags=?,
                    revision_comment=?,
                    is_revision=1,
                    transferred=0
                WHERE id=?");
            if ($updStmt) {
                $updStmt->bind_param("ssssi", $flaggedFieldsJson, $comment, $flaggedFieldsJson, $comment, $moa_id);
                $updStmt->execute();
                $updStmt->close();
            }
        } else {
            moaDebugLog('ajax_moa_table_flag_revision:no_moa_requests_row_to_update', ['user_id'=>$user_id]);
        }

        // ══════════════════════════════════════════════════════════════
        // NEW (this adjustment): delete the flagged field(s)' current
        // value from the DB, then immediately recreate the MOA document
        // from the (now partially blank) data and return it to the
        // company as their current Pending MOA — see the big comment
        // block above regBuildMOAStaticHTMLForDompdf() for the full
        // reasoning. Skipped entirely for a company whose MOA is a real
        // uploaded scan ("Already Have MOA") rather than data-generated,
        // since there is nothing to safely regenerate for those.
        // ══════════════════════════════════════════════════════════════
        $moaWasRegenerated = false;
        if (isMoaSubmissionDataGenerated($conn, $user_id)) {
            blankFlaggedCompanyInfoFields($conn, $user_id, $flaggedFields);
            $moaWasRegenerated = regenerateAndReturnMoaToCompany($conn, $user_id);
        } else {
            moaDebugLog('ajax_moa_table_flag_revision:skip_regenerate_uploaded_scan', ['user_id'=>$user_id]);
        }

        // Keep the row visible in the "Pending for Review" stage, just
        // annotated as needing revision, so the admin still sees it in
        // context in the New MOA table instead of it disappearing.
        // Normalizes to the canonical 'moa_document' key at the same time.
        // ── FIX (this adjustment): re-resolve the requirement_type here
        // rather than reusing the earlier $crMoaType — if
        // regenerateAndReturnMoaToCompany() just ran, it (via
        // saveRegeneratedMoaBlob()) may have already normalized a legacy
        // 'moa' row to 'moa_document', which would make the old,
        // now-stale $crMoaType value match nothing below and silently
        // fail to set moa_needs_revision.
        $crMoaTypeNow = resolveMoaRequirementType($conn, $user_id);
        // UPDATED (this adjustment): also keeps a copy of WHAT was flagged (moa_last_*) that nothing on the company's
        // side erases — CompanyForm.php clears moa_flagged_fields the moment the company complies, and this copy is what
        // lets the "Revision Complied" notification / Review MOA preview say which sections they fixed. A fresh flag also
        // supersedes any earlier "complied" detail. (See cvBuildComplianceDetail().)
        $crUpd = $conn->prepare("UPDATE company_requirements SET requirement_type='moa_document', moa_needs_revision=1, moa_flagged_fields=?, moa_revision_comment=?, moa_last_flagged_fields=?, moa_last_revision_comment=?, moa_complied_detail=NULL WHERE user_id=? AND requirement_type=?");
        if ($crUpd) {
            $crUpd->bind_param("ssssis", $flaggedFieldsJson, $comment, $flaggedFieldsJson, $comment, $user_id, $crMoaTypeNow);
            $crUpd->execute();
            $crUpd->close();
        }
        cvOnAdminMoaAction($conn, $user_id);   // NEW (this adjustment): the admin acted on this MOA — its notifications clear

        $overall = recomputeCompanyValidationStatus($conn, $user_id);

        // ── UPDATED (this adjustment): wording now reflects whether this
        // company has a moa_requests-based resubmission flow to
        // auto-detect (see the guarded UPDATE above), and whether the MOA
        // document itself was just regenerated with the flagged field(s)
        // blanked out.
        $successMessage = $moaRow
            ? 'Revision email sent. The company will see a "Needs Revision" note and can revise the flagged section(s) and resubmit — it will reappear here automatically once they do.'
            : 'Revision email sent and this row is now marked "Needs Revision." This company\'s MOA was auto-generated at registration (no separate MOA request to auto-detect a resubmission from), so once they\'ve corrected the flagged section(s) and the document is updated, please clear this manually or advance the row as usual.';
        if ($moaWasRegenerated) {
            $successMessage .= ' The flagged field(s) have been cleared and the MOA document has been regenerated and returned to the company showing the missing section(s).';
        }

        if ($mUndo) $successMessage = str_replace('Revision email sent', 'Revision recorded', $successMessage);   // NEW (this adjustment): the email follows once the undo window closes
        $undoOut = $mUndo ? cvMoaUndoFinish($conn, $user_id, $mUndo, 'flag', ['company_name' => $companyName]) : [];
        ob_end_clean();
        echo json_encode([
            'success'        => true,
            'user_id'        => $user_id,
            'flagged_fields' => $flaggedFields,
            'overall_status' => $overall,
            'message'        => $successMessage
        ] + $undoOut);
        exit;
    } catch (Throwable $ex) {
        moaDebugLog('ajax_moa_table_flag_revision:exception', ['user_id'=>$user_id,'message'=>$ex->getMessage(),'trace'=>$ex->getTraceAsString()]);
        ob_end_clean();
        echo json_encode(['success'=>false,'message'=>'Unexpected error while sending: '.$ex->getMessage()]);
        exit;
    }
}

/* ================= AJAX: UPDATE MOA WORKFLOW STAGE IN REQUIREMENTS TABLE ================= */
if (isset($_POST['ajax_update_moa_req_workflow'])) {
    header('Content-Type: application/json');

    $user_id   = intval($_POST['user_id'] ?? 0);
    $new_stage = trim($_POST['stage'] ?? '');
    $comment   = trim($_POST['comment'] ?? '');
    $schedule_date = trim($_POST['schedule_date'] ?? '');
    $schedule_time = trim($_POST['schedule_time'] ?? '');
    // ── UPDATED (this adjustment): "pending" and "reviewing" are now a
    // single merged stage ("Pending for Review"), so there is no longer a
    // separate transition into "reviewing" — the workflow goes straight
    // from "pending" to "approved". "reviewing" is intentionally kept in
    // the allowed list purely so any OLD row that still has that legacy
    // value stored can still be advanced forward without a dead end; no
    // new row is ever written with stage='reviewing' going forward (see
    // the stage-label/stepper merge further down).
    $allowed   = ['pending','reviewing','approved','scheduled'];

    if (!$user_id || !in_array($new_stage, $allowed)) {
        echo json_encode(['success'=>false,'message'=>'Invalid parameters.']);
        exit;
    }

    // The final phase (Schedule for Signing) requires a date and time to be set by the admin.
    $scheduleDateTime = null;
    if ($new_stage === 'scheduled') {
        if (empty($schedule_date) || empty($schedule_time)) {
            echo json_encode(['success'=>false,'message'=>'Please set a signing date and time before scheduling.']);
            exit;
        }
        $scheduleDateTime = $schedule_date . ' ' . $schedule_time . ':00';
        if (!strtotime($scheduleDateTime ?? '')) {
            echo json_encode(['success'=>false,'message'=>'Invalid signing date/time format.']);
            exit;
        }
    }

    moaDebugLog('ajax_update_moa_req_workflow:start', ['user_id'=>$user_id,'stage'=>$new_stage,'comment'=>$comment,'schedule'=>$scheduleDateTime]);

    // ── UPDATED (this adjustment): resolve the MOA document's actual
    // stored key ('moa_document' or legacy 'moa') so advancing the
    // workflow works for a registration-generated MOA too, and normalize
    // it to 'moa_document' going forward.
    $moaType = resolveMoaRequirementType($conn, $user_id);

    // Check file exists
    $chk = $conn->prepare("SELECT id, file_name, status FROM company_requirements WHERE user_id=? AND requirement_type=?");
    $chk->bind_param("is", $user_id, $moaType); $chk->execute();
    $chkRow = $chk->get_result()->fetch_assoc(); $chk->close();

    if (!$chkRow || empty($chkRow['file_name'])) {
        echo json_encode(['success'=>false,'message'=>'No MOA document found in requirements for this company.']);
        exit;
    }
    if ($chkRow['status'] === 'Verified') {
        echo json_encode(['success'=>false,'guard'=>'already_verified','message'=>'MOA Document is already Verified.']);
        exit;
    }

    // ── NEW (this adjustment): a MOA that still has flagged section(s) waiting on the company can't be approved — the
    // matching "Approve MOA" button in the Review MOA preview is locked, and this enforces the same rule so it can't be
    // bypassed by calling the endpoint directly. (If the flag can't be read, nothing is blocked.)
    if ($new_stage === 'approved') {
        try {
            $fgQ = $conn->prepare("SELECT moa_needs_revision FROM company_requirements WHERE user_id=? AND requirement_type=?");
            if ($fgQ) {
                $fgQ->bind_param("is", $user_id, $moaType); $fgQ->execute();
                $fgRow = $fgQ->get_result()->fetch_assoc(); $fgQ->close();
                if ($fgRow && !empty($fgRow['moa_needs_revision'])) {
                    echo json_encode(['success'=>false,'guard'=>'flags_outstanding','message'=>'This MOA still has flagged section(s) waiting on the company. It can be approved once they have updated them.']);
                    exit;
                }
            }
        } catch (\Throwable $e) { /* can't read the flag — don't block */ }
    }

    // ── NEW (this adjustment): undoable? Snapshot what this is about to change BEFORE anything is written (see the
    // "UNDO FOR MOA PROCESSES" note above cvMoaUndoCapture()). Only when the page asked for it (moa_undoable).
    $mUndo = null;
    if (!empty($_POST['moa_undoable'])) {
        $mUndo = ['token' => bin2hex(random_bytes(12)), 'before' => cvMoaUndoCapture($conn, $user_id)];
    }

    // If reaching 'scheduled' → just record the schedule info and keep status Pending.
    // Verification (and the physical copy save) now only happens when the admin
    // explicitly clicks "Done", so this stage — along with its Done / Re-Schedule
    // buttons — persists correctly across page reloads instead of collapsing into
    // the "Verified" lock state prematurely.
    //
    // ── NEW (this adjustment): every time the admin sets or resets the
    // signing schedule, it goes back to moa_schedule_status=
    // 'pending_confirmation' — the company representative must explicitly
    // agree to it (or decline and propose an alternative) on CompanyForm.php
    // before it's considered final. Any earlier decline reason/proposed
    // date from a previous round is cleared here too, since it no longer
    // applies to this new schedule.
    if ($new_stage === 'scheduled') {
        $conn->query("ALTER TABLE company_requirements ADD COLUMN IF NOT EXISTS moa_schedule_status VARCHAR(20) NULL");
        $conn->query("ALTER TABLE company_requirements ADD COLUMN IF NOT EXISTS moa_schedule_decline_reason TEXT NULL");
        $conn->query("ALTER TABLE company_requirements ADD COLUMN IF NOT EXISTS moa_proposed_datetime DATETIME NULL");

        $updStmt = $conn->prepare("UPDATE company_requirements SET requirement_type='moa_document', moa_workflow_stage='scheduled', moa_admin_comment=?, moa_schedule_datetime=?, moa_schedule_status='pending_confirmation', moa_schedule_decline_reason=NULL, moa_proposed_datetime=NULL WHERE user_id=? AND requirement_type=?");
        $updStmt->bind_param("ssis", $comment, $scheduleDateTime, $user_id, $moaType); $updStmt->execute(); $updStmt->close();
        cvOnAdminMoaAction($conn, $user_id);   // NEW (this adjustment): the admin acted on this MOA — its notifications clear

        $overall = recomputeCompanyValidationStatus($conn, $user_id);
        moaDebugLog('ajax_update_moa_req_workflow:scheduled_set', ['user_id'=>$user_id,'overall'=>$overall,'schedule'=>$scheduleDateTime]);

        // Send the automatic email update including the comment and the signing schedule.
        // UPDATED (this adjustment): when undoable the email waits until the undo window closes (see cvMoaUndoQueueEmail()).
        if ($mUndo) cvMoaUndoQueueEmail($mUndo['token'], ['kind'=>'stage','user_id'=>$user_id,'stage'=>'scheduled','comment'=>$comment,'schedule'=>$scheduleDateTime]);
        else        sendMoaStageEmail($conn, $user_id, 'scheduled', $comment, $scheduleDateTime);
        $undoOut = $mUndo ? cvMoaUndoFinish($conn, $user_id, $mUndo, 'schedule') : [];

        echo json_encode(['success'=>true,'stage'=>'scheduled','status'=>'Pending','overall_status'=>$overall,'auto_verified'=>false,'schedule_datetime'=>$scheduleDateTime,'schedule_status'=>'pending_confirmation'] + $undoOut);
        exit;
    }

    // Otherwise just advance stage, keep status=Pending
    $updStmt = $conn->prepare("UPDATE company_requirements SET requirement_type='moa_document', moa_workflow_stage=?, moa_admin_comment=? WHERE user_id=? AND requirement_type=?");
    $updStmt->bind_param("ssis", $new_stage, $comment, $user_id, $moaType); $updStmt->execute(); $updStmt->close();
    cvOnAdminMoaAction($conn, $user_id);   // NEW (this adjustment): the admin acted on this MOA — its notifications clear

    $overall = recomputeCompanyValidationStatus($conn, $user_id);

    // Send the automatic email update including the comment for this stage.
    // UPDATED (this adjustment): when undoable the email waits until the undo window closes (see cvMoaUndoQueueEmail()).
    if ($mUndo) cvMoaUndoQueueEmail($mUndo['token'], ['kind'=>'stage','user_id'=>$user_id,'stage'=>$new_stage,'comment'=>$comment,'schedule'=>null]);
    else        sendMoaStageEmail($conn, $user_id, $new_stage, $comment, null);
    $undoOut = $mUndo ? cvMoaUndoFinish($conn, $user_id, $mUndo, ($new_stage === 'approved' ? 'approve' : 'stage')) : [];

    echo json_encode(['success'=>true,'stage'=>$new_stage,'status'=>'Pending','overall_status'=>$overall,'auto_verified'=>false] + $undoOut);
    exit;
}

/* ================= AJAX: ADMIN ACCEPTS THE COMPANY'S PROPOSED SCHEDULE =================
   NEW (this adjustment) — the counterpart, on the admin side, to a
   company representative declining a proposed signing schedule on
   CompanyForm.php and proposing a different date/time instead (see the
   "SIGNING SCHEDULE CONFIRMATION" flow there). Lets the admin adopt that
   proposed date/time as the new official schedule in one click, without
   having to re-open the Set Signing Schedule modal and retype it —
   directly finalizes it as 'confirmed' (the company already told us
   they're available then), instead of going back to
   'pending_confirmation' and asking them to re-confirm their own
   suggestion. ================================ */
if (isset($_POST['ajax_accept_proposed_schedule'])) {
    header('Content-Type: application/json');
    $conn->query("ALTER TABLE company_requirements ADD COLUMN IF NOT EXISTS moa_schedule_status VARCHAR(20) NULL");
    $conn->query("ALTER TABLE company_requirements ADD COLUMN IF NOT EXISTS moa_schedule_decline_reason TEXT NULL");
    $conn->query("ALTER TABLE company_requirements ADD COLUMN IF NOT EXISTS moa_proposed_datetime DATETIME NULL");

    $user_id = intval($_POST['user_id'] ?? 0);
    if (!$user_id) { echo json_encode(['success'=>false,'message'=>'Invalid request.']); exit; }

    $moaType = resolveMoaRequirementType($conn, $user_id);
    $chk = $conn->prepare("SELECT moa_proposed_datetime FROM company_requirements WHERE user_id=? AND requirement_type=?");
    $chk->bind_param("is", $user_id, $moaType); $chk->execute();
    $row = $chk->get_result()->fetch_assoc(); $chk->close();

    if (empty($row['moa_proposed_datetime'])) {
        echo json_encode(['success'=>false,'message'=>'No proposed schedule found for this company.']);
        exit;
    }

    $newDateTime = $row['moa_proposed_datetime'];
    // ── FIX (this adjustment): this used to set moa_schedule_status
    // exactly the same as when the COMPANY themselves clicks "I Agree" —
    // 'confirmed' — so both the admin's own row and the company's own
    // CompanyForm.php view ended up saying "Company representative
    // confirmed this schedule" / "You confirmed this signing schedule"
    // even though the company never agreed to anything here; it was the
    // ADMIN who accepted the company's own counter-proposal. Now uses a
    // distinct 'confirmed_by_admin' status so both sides show accurate,
    // differently-worded confirmations crediting the right party — see
    // the matching display logic in renderCompanyValidationRow() and
    // updateMoaScheduleConfirmBlock() below, and CompanyForm.php's own
    // schedule panel.
    // NEW (this adjustment): undoable? snapshot before anything is written (see "UNDO FOR MOA PROCESSES").
    $mUndo = !empty($_POST['moa_undoable']) ? ['token' => bin2hex(random_bytes(12)), 'before' => cvMoaUndoCapture($conn, $user_id)] : null;
    $upd = $conn->prepare("UPDATE company_requirements SET moa_schedule_datetime=?, moa_schedule_status='confirmed_by_admin', moa_schedule_decline_reason=NULL, moa_proposed_datetime=NULL WHERE user_id=? AND requirement_type=?");
    $upd->bind_param("sis", $newDateTime, $user_id, $moaType); $upd->execute(); $upd->close();
    cvOnAdminMoaAction($conn, $user_id);   // NEW (this adjustment): the admin acted on this MOA — its notifications clear

    // UPDATED (this adjustment): when undoable the email waits until the undo window closes (see cvMoaUndoQueueEmail()).
    if ($mUndo) cvMoaUndoQueueEmail($mUndo['token'], ['kind'=>'stage','user_id'=>$user_id,'stage'=>'scheduled','comment'=>'Your proposed signing schedule has been accepted and is now confirmed.','schedule'=>$newDateTime]);
    else        sendMoaStageEmail($conn, $user_id, 'scheduled', 'Your proposed signing schedule has been accepted and is now confirmed.', $newDateTime);
    $undoOut = $mUndo ? cvMoaUndoFinish($conn, $user_id, $mUndo, 'accept_schedule') : [];

    echo json_encode(['success'=>true,'schedule_datetime'=>$newDateTime] + $undoOut);
    exit;
}

/* ================= AJAX: MARK MOA DONE (FINAL VERIFICATION) ================= */
if (isset($_POST['ajax_moa_mark_done'])) {
    header('Content-Type: application/json');

    $user_id = intval($_POST['user_id'] ?? 0);
    if (!$user_id) {
        echo json_encode(['success'=>false,'message'=>'Invalid parameters.']);
        exit;
    }

    moaDebugLog('ajax_moa_mark_done:start', ['user_id'=>$user_id]);

    // ── UPDATED (this adjustment): resolve the MOA document's actual
    // stored key ('moa_document' or legacy 'moa').
    $moaType = resolveMoaRequirementType($conn, $user_id);

    $chk = $conn->prepare("SELECT id, file_name, status, moa_workflow_stage, moa_schedule_datetime, moa_schedule_status FROM company_requirements WHERE user_id=? AND requirement_type=?");
    $chk->bind_param("is", $user_id, $moaType); $chk->execute();
    $chkRow = $chk->get_result()->fetch_assoc(); $chk->close();

    if (!$chkRow || empty($chkRow['file_name'])) {
        echo json_encode(['success'=>false,'message'=>'No MOA document found in requirements for this company.']);
        exit;
    }
    if ($chkRow['status'] === 'Verified') {
        echo json_encode(['success'=>false,'guard'=>'already_verified','message'=>'MOA Document is already Verified.']);
        exit;
    }
    if (($chkRow['moa_workflow_stage'] ?? '') !== 'scheduled') {
        echo json_encode(['success'=>false,'message'=>'Please set a signing schedule before marking this as done.']);
        exit;
    }
    // ── NEW (this adjustment): the schedule must also be FINALIZED before the MOA can be marked as done — i.e. the
    // company representative confirmed it ('confirmed'), or the admin accepted the company's own proposed
    // schedule ('confirmed_by_admin'). NULL / 'pending_confirmation' (still awaiting the company) and 'declined'
    // (the company proposed a different date that hasn't been accepted yet) both block it. Mirrors the locked
    // "Done" button in the UI (see .moa-done-locked) so this can't be bypassed by calling the endpoint directly.
    if (!in_array(($chkRow['moa_schedule_status'] ?? '') ?: 'pending_confirmation', ['confirmed', 'confirmed_by_admin'], true)) {
        echo json_encode(['success'=>false,'guard'=>'schedule_not_final','message'=>"The signing schedule isn't finalized yet. The company must confirm it (or you must accept the schedule they proposed) before this MOA can be marked as done."]);
        exit;
    }

    // This is the ONLY place status becomes 'Verified' for the MOA document —
    // finalizing only happens when the admin explicitly clicks "Done".
    // Also normalizes requirement_type to 'moa_document' at this point.
    // NEW (this adjustment): undoable? snapshot before anything is written (see "UNDO FOR MOA PROCESSES").
    $mUndo = !empty($_POST['moa_undoable']) ? ['token' => bin2hex(random_bytes(12)), 'before' => cvMoaUndoCapture($conn, $user_id)] : null;
    $mDoneFile = null;
    $updStmt = $conn->prepare("UPDATE company_requirements SET requirement_type='moa_document', status='Verified', remark=NULL WHERE user_id=? AND requirement_type=?");
    $updStmt->bind_param("is", $user_id, $moaType); $updStmt->execute(); $updStmt->close();
    cvOnAdminMoaAction($conn, $user_id);   // NEW (this adjustment): the admin acted on this MOA — its notifications clear

    // Save the physical verified copy now that the MOA has been fully finalized.
    $sq = $conn->prepare("SELECT file_name FROM company_requirements WHERE user_id=? AND requirement_type='moa_document'");
    $sq->bind_param("i",$user_id); $sq->execute(); $res=$sq->get_result()->fetch_assoc(); $sq->close();
    if (!empty($res['file_name'])) {
        $cq = $conn->prepare("SELECT company FROM company_information WHERE user_id=?");
        $cq->bind_param("i",$user_id); $cq->execute(); $cr=$cq->get_result()->fetch_assoc(); $cq->close();
        $companyName = preg_replace("/[^a-zA-Z0-9]/","_",$cr['company']??"Company_".$user_id);
        $uploadPath  = "Company Folder/".$companyName;
        if (!file_exists($uploadPath)) mkdir($uploadPath,0777,true);
        $finfo=new finfo(FILEINFO_MIME_TYPE); $mime=$finfo->buffer($res['file_name']);
        $ext=($mime==='application/pdf')?"pdf":(strpos($mime,"png")!==false?"png":"jpg");
        $mDoneFile = $uploadPath."/MOA_Document_verified_".time().".".$ext;   // NEW (this adjustment): remembered so Undo can take the copy back
        file_put_contents($mDoneFile,$res['file_name']);
    }

    $overall = recomputeCompanyValidationStatus($conn, $user_id);
    moaDebugLog('ajax_moa_mark_done:verified', ['user_id'=>$user_id,'overall'=>$overall]);
    $undoOut = $mUndo ? cvMoaUndoFinish($conn, $user_id, $mUndo, 'done', ['done_file' => $mDoneFile]) : [];

    echo json_encode(['success'=>true,'status'=>'Verified','overall_status'=>$overall,'schedule_datetime'=>$chkRow['moa_schedule_datetime']] + $undoOut);
    exit;
}

/* ================= AJAX: MOA DEBUG LOG ================= */
if (isset($_GET['ajax_moa_debug_log'])) {
    header('Content-Type: application/json');
    $logFile = __DIR__ . '/moa_debug.log';
    if (isset($_GET['clear']) && $_GET['clear']=='1') { @file_put_contents($logFile,''); echo json_encode(['lines'=>[],'message'=>'Log cleared.']); exit; }
    if (!file_exists($logFile)) { echo json_encode(['lines'=>[],'message'=>'No log file found yet.']); exit; }
    $raw=file($logFile,FILE_IGNORE_NEW_LINES|FILE_SKIP_EMPTY_LINES); $lines=array_slice(array_reverse($raw),0,100);
    $parsed=[]; foreach($lines as $line){$obj=json_decode($line,true);$parsed[]=$obj?:['raw'=>$line];}
    echo json_encode(['lines'=>$parsed]); exit;
}

/* ================= AJAX: MOA PENDING COUNT ================= */
if (isset($_GET['moa_pending_count']) && $_GET['moa_pending_count']=='1') {
    header('Content-Type: application/json');
    cv_ensure_moa_requests_table($conn);   // CLEAN-UP (audit): shared definition, see cv_ensure_moa_requests_table()
    $pc=$conn->query("SELECT COUNT(*) as total FROM moa_requests WHERE status='Pending'");
    $pcRow=$pc?$pc->fetch_assoc():['total'=>0];
    echo json_encode(['count'=>(int)($pcRow['total']??0)]); exit;
}

/* ================= AJAX: IMMEDIATE SAVE REQUIREMENT (non-MOA) ================= */
if (isset($_POST['ajax_save_requirement'])) {
    header('Content-Type: application/json');

    $user_id = intval($_POST['user_id'] ?? 0);
    $type    = trim($_POST['requirement_type'] ?? '');
    $status  = trim($_POST['status'] ?? '');
    // UPDATED (this adjustment): the status is now called "Rejected". "Denied" (an older page still open in a browser,
    // for instance) is accepted as the same thing, and the remark is free text entered by the admin.
    if ($status === 'Denied') $status = 'Rejected';
    $remark  = ($status === 'Rejected') ? trim((string)($_POST['remark'] ?? '')) : null;
    // NEW (this adjustment): the remark box is multi-line, but a remark is one line of text everywhere it is shown (the card,
    // CompanyForm.php, the email) — so any line breaks / runs of spaces are folded into single spaces before it is validated or stored.
    if ($remark !== null) { $remarkOneLine = preg_replace('/\s+/u', ' ', $remark); if ($remarkOneLine !== null) $remark = trim($remarkOneLine); }

    if (!$user_id || !$type || !$status) { echo json_encode(['success'=>false,'message'=>'Missing required fields.']); exit; }
    if ($status === 'Rejected' && $remark === '') { echo json_encode(['success'=>false,'message'=>'Please enter a remark for the Rejected status.']); exit; }
    if ($status === 'Rejected') {
        $remarkLen = function_exists('mb_strlen') ? mb_strlen($remark, 'UTF-8') : strlen($remark);
        if ($remarkLen > cvRemarkMaxLen($conn)) { echo json_encode(['success'=>false,'message'=>'The remark is too long (maximum '.cvRemarkMaxLen($conn).' characters).']); exit; }
    }

    // ── UPDATED (this adjustment): a requirement can hold SEVERAL entries (one row per uploaded
    // file, all under the same requirement_type). This used to read only the FIRST row, so the
    // "already verified" / "no submission" guards, the undo snapshot and the archived copy on
    // disk all reflected just the first file. Every entry is now read (no file contents loaded
    // here) and summarised: $fileEntryCount = entries that really have a file,
    // $allEntriesVerified = every one of those is already Verified, $prevRows = each row's own
    // prior state (used to restore them exactly on Undo). The status UPDATE below has always
    // matched by (user_id, requirement_type), so it applies to every entry, not just the first.
    $checkQ = $conn->prepare("SELECT id, status, remark, (file_name IS NULL OR file_name = '') AS no_file FROM company_requirements WHERE user_id=? AND requirement_type=? ORDER BY id ASC");
    $checkQ->bind_param("is", $user_id, $type); $checkQ->execute();
    $checkRes = $checkQ->get_result();
    $prevRows = []; $fileEntryCount = 0; $verifiedEntryCount = 0; $anyDeniedEntry = false; $firstDeniedRemark = null;
    while ($cr0 = $checkRes->fetch_assoc()) {
        $prevRows[] = ['id'=>(int)$cr0['id'], 'status'=>($cr0['status'] ?? 'Pending'), 'remark'=>($cr0['remark'] ?? null)];
        if (empty($cr0['no_file'])) { $fileEntryCount++; if (($cr0['status'] ?? '') === 'Verified') $verifiedEntryCount++; }
        if (in_array(($cr0['status'] ?? ''), ['Rejected','Denied'], true)) { $anyDeniedEntry = true; if ($firstDeniedRemark === null && !empty($cr0['remark'])) $firstDeniedRemark = $cr0['remark']; }
    }
    $checkQ->close();
    $allEntriesVerified = ($fileEntryCount > 0 && $verifiedEntryCount === $fileEntryCount);

    if ($allEntriesVerified && $status==='Verified') { echo json_encode(['success'=>false,'guard'=>'already_verified','message'=>($companyReqLabels[$type]??$type).' is already verified.']); exit; }
    if ($fileEntryCount === 0 && in_array($status,['Verified','Rejected'])) { echo json_encode(['success'=>false,'guard'=>'no_submission','message'=>'No submission exists for '.($companyReqLabels[$type]??$type).'.']); exit; }

    $prevStatus = empty($prevRows) ? 'Pending' : ($allEntriesVerified ? 'Verified' : ($anyDeniedEntry ? 'Rejected' : 'Pending'));
    $prevRemark = $firstDeniedRemark ?? ($prevRows[0]['remark'] ?? null);

    if ($status!=='Rejected') {
        $stmt=$conn->prepare("UPDATE company_requirements SET status=?, remark=NULL WHERE user_id=? AND requirement_type=?");
        $stmt->bind_param("sis",$status,$user_id,$type); $stmt->execute(); $stmt->close();
    }

    if ($status==='Verified') {
        // ── UPDATED (this adjustment): archive EVERY verified entry into the company folder, not just
        // the first. A requirement with a single file is saved under exactly the same name as before
        // (<Label>_verified_<time>.<ext>); when there are several, each gets an _<n> suffix so none
        // overwrites another.
        $fidList = [];
        $fq=$conn->prepare("SELECT id FROM company_requirements WHERE user_id=? AND requirement_type=? AND file_name IS NOT NULL AND file_name <> '' ORDER BY id ASC");
        $fq->bind_param("is",$user_id,$type); $fq->execute(); $fRes=$fq->get_result(); while($fr=$fRes->fetch_assoc()){$fidList[]=(int)$fr['id'];} $fq->close();
        if (!empty($fidList)) {
            $cq=$conn->prepare("SELECT company FROM company_information WHERE user_id=?"); $cq->bind_param("i",$user_id); $cq->execute(); $cr=$cq->get_result()->fetch_assoc(); $cq->close();
            $companyName=preg_replace("/[^a-zA-Z0-9]/","_",$cr['company']??"Company_".$user_id);
            $uploadPath="Company Folder/".$companyName; if(!file_exists($uploadPath))mkdir($uploadPath,0777,true);
            $safeLabel=preg_replace("/[^a-zA-Z0-9]/","_",$companyReqLabels[$type]??$type);
            $fidTotal=count($fidList); $fidN=0;
            foreach ($fidList as $fid) {
                $bq=$conn->prepare("SELECT file_name FROM company_requirements WHERE id=?"); $bq->bind_param("i",$fid); $bq->execute(); $res=$bq->get_result()->fetch_assoc(); $bq->close();
                if (empty($res['file_name'])) continue;
                $fidN++;
                $finfo=new finfo(FILEINFO_MIME_TYPE); $mime=$finfo->buffer($res['file_name']); $ext=($mime==='application/pdf')?"pdf":(strpos($mime,"png")!==false?"png":"jpg");
                $fileSuffix=($fidTotal>1)?('_'.$fidN):'';
                file_put_contents($uploadPath."/".$safeLabel."_verified_".time().$fileSuffix.".".$ext,$res['file_name']);
            }
        }
    }

    $newOverallStatus = recomputeCompanyValidationStatus($conn, $user_id);

    // NEW (this adjustment): the admin has now acted on this requirement (verified or rejected it), so it comes off its
    // "Requirement Uploaded" notification — which disappears from the inbox once nothing is left on it. What was taken
    // off goes into the Undo snapshot below, so Undo brings it back.
    $notifRestore = in_array($status, ['Verified', 'Rejected'], true) ? cvResolveRequirementUploadNotification($conn, $user_id, $type) : [];

    $token=bin2hex(random_bytes(12));
    if(!isset($_SESSION['undo_stack']))$_SESSION['undo_stack']=[];
    $_SESSION['undo_stack'][$token]=['type'=>'company_req','user_id'=>$user_id,'requirement_type'=>$type,'prev_status'=>$prevStatus,'prev_remark'=>$prevRemark,'new_status'=>$status,'new_remark'=>$remark,'prev_rows'=>$prevRows,'notif_restore'=>$notifRestore,'ts'=>time()];

    $userQuery=$conn->prepare("SELECT first_name,last_name,email FROM users WHERE id=?"); $userQuery->bind_param("i",$user_id); $userQuery->execute();
    $userData=$userQuery->get_result()->fetch_assoc(); $userQuery->close();
    if($userData){if(!isset($_SESSION['pending_emails']))$_SESSION['pending_emails']=[];$_SESSION['pending_emails'][$token]=['email'=>$userData['email'],'name'=>$userData['first_name'].' '.$userData['last_name'],'label'=>$companyReqLabels[$type]??$type,'status'=>($status==='Rejected'?'Denied':$status),'remark'=>$remark,'ts'=>time()];}

    echo json_encode(['success'=>true,'undo_token'=>$token,'new_status'=>$status,'new_remark'=>$remark,'overall_status'=>$newOverallStatus,'label'=>$companyReqLabels[$type]??$type,'is_denied'=>($status==='Rejected')]);
    exit;
}

/* ================= AJAX: COMMIT DEFERRED DENIED ================= */
if (isset($_POST['ajax_commit_denied'])) {
    header('Content-Type: application/json');
    $token=trim($_POST['undo_token']??'');
    if(empty($token)||empty($_SESSION['undo_stack'][$token])){echo json_encode(['success'=>false,'message'=>'Token expired or invalid.']);exit;}
    $snap=$_SESSION['undo_stack'][$token]; $uid=intval($snap['user_id']??0); $type=$snap['requirement_type']??''; $rm=$snap['new_remark']??null;
    if($uid&&$type){$stmt=$conn->prepare("UPDATE company_requirements SET file_name=NULL, status='Rejected', remark=? WHERE user_id=? AND requirement_type=?");$stmt->bind_param("sis",$rm,$uid,$type);$stmt->execute();$stmt->close();recomputeCompanyValidationStatus($conn,$uid);}
    if(!empty($_SESSION['pending_emails'][$token])){$p=$_SESSION['pending_emails'][$token];sendStatusEmail($p['email'],$p['name'],$p['label'],$p['status'],$p['remark']);unset($_SESSION['pending_emails'][$token]);}
    unset($_SESSION['undo_stack'][$token]);
    echo json_encode(['success'=>true]); exit;
}

/* ================= AJAX: UNDO HANDLER ================= */
if (isset($_POST['ajax_undo'])) {
    header('Content-Type: application/json');
    $token=trim($_POST['undo_token']??'');
    if(empty($token)||empty($_SESSION['undo_stack'][$token])){echo json_encode(['success'=>false,'message'=>'Undo token expired or invalid.']);exit;}
    $snap=$_SESSION['undo_stack'][$token];
    if((time()-$snap['ts'])>=300){unset($_SESSION['undo_stack'][$token]);echo json_encode(['success'=>false,'message'=>'Undo window has expired (5 minutes).']);exit;}
    $user_id=$snap['user_id'];$type=$snap['requirement_type'];$prevStatus=$snap['prev_status'];$prevRemark=$snap['prev_remark'];$newStatus=$snap['new_status']??'';$isDenied=in_array($newStatus,['Denied','Rejected'],true);
    if(!$isDenied){
        // ── UPDATED (this adjustment): every entry of a multi-file requirement is put back to its OWN
        // prior state (snapshotted by ajax_save_requirement as prev_rows). A snapshot without
        // prev_rows (taken before this change) falls back to the original whole-requirement restore.
        $prevRowsSnap=$snap['prev_rows']??null;
        if(is_array($prevRowsSnap)&&!empty($prevRowsSnap)){
            $rstmt=$conn->prepare("UPDATE company_requirements SET status=?,remark=? WHERE id=? AND user_id=? AND requirement_type=?");
            if($rstmt){
                foreach($prevRowsSnap as $pr){
                    $rId=(int)($pr['id']??0); if(!$rId)continue;
                    $rSt=$pr['status']??'Pending'; if($rSt!=='Rejected'&&$rSt!=='Denied'&&$rSt!=='Verified')$rSt='Pending';
                    $rRm=($rSt==='Rejected'||$rSt==='Denied')?($pr['remark']??null):null;
                    $rstmt->bind_param("ssiis",$rSt,$rRm,$rId,$user_id,$type);
                    $rstmt->execute();
                }
                $rstmt->close();
            }
        } else {
            if($prevStatus==='Denied'||$prevStatus==='Rejected'){$stmt=$conn->prepare("UPDATE company_requirements SET status=?,remark=? WHERE user_id=? AND requirement_type=?");$stmt->bind_param("ssis",$prevStatus,$prevRemark,$user_id,$type);}
            elseif($prevStatus==='Verified'){$stmt=$conn->prepare("UPDATE company_requirements SET status='Verified',remark=NULL WHERE user_id=? AND requirement_type=?");$stmt->bind_param("is",$user_id,$type);}
            else{$stmt=$conn->prepare("UPDATE company_requirements SET status='Pending',remark=NULL WHERE user_id=? AND requirement_type=?");$stmt->bind_param("is",$user_id,$type);}
            $stmt->execute();$stmt->close();
        }
    }
    $newOverallStatus=recomputeCompanyValidationStatus($conn,$user_id);
    $notifRestoredIds=cvRestoreRequirementUploadNotifications($conn,$snap['notif_restore']??[]);   // NEW (this adjustment): Undo also brings the upload notification back
    if(isset($_SESSION['pending_emails'][$token]))unset($_SESSION['pending_emails'][$token]);
    unset($_SESSION['undo_stack'][$token]);
    echo json_encode(['success'=>true,'message'=>'Action undone successfully.','new_status'=>$prevStatus,'new_remark'=>$prevRemark,'overall_status'=>$newOverallStatus,'user_id'=>$user_id,'requirement_type'=>$type,'notif_restored_ids'=>$notifRestoredIds]);
    exit;
}

/* ================= AJAX: CONFIRM SEND EMAIL ================= */
if (isset($_POST['ajax_confirm_send'])) {
    header('Content-Type: application/json');
    $token=trim($_POST['undo_token']??'');
    if(!empty($token)&&!empty($_SESSION['pending_emails'][$token])){$p=$_SESSION['pending_emails'][$token];if((time()-$p['ts'])<600)sendStatusEmail($p['email'],$p['name'],$p['label'],$p['status'],$p['remark']);unset($_SESSION['pending_emails'][$token]);}
    if(!empty($token)&&isset($_SESSION['undo_stack'][$token]))unset($_SESSION['undo_stack'][$token]);
    echo json_encode(['success'=>true]); exit;
}

/* ================= AJAX: FLUSH EXPIRED PENDING EMAILS ================= */
if (isset($_POST['ajax_flush_emails'])) {
    header('Content-Type: application/json');
    if(!empty($_SESSION['pending_emails'])){foreach($_SESSION['pending_emails'] as $tk=>$p){if((time()-$p['ts'])>=300&&(time()-$p['ts'])<600){sendStatusEmail($p['email'],$p['name'],$p['label'],$p['status'],$p['remark']);unset($_SESSION['pending_emails'][$tk]);}elseif((time()-$p['ts'])>=600)unset($_SESSION['pending_emails'][$tk]);}}
    echo json_encode(['success'=>true]); exit;
}

/* ================= AJAX: UNDO / COMMIT / FLUSH FOR MOA PROCESSES =================
   NEW (this adjustment) — the MOA counterparts of ajax_undo / ajax_confirm_send / ajax_flush_emails (which are left exactly
   as they were, for requirements). See "UNDO FOR MOA PROCESSES" above cvMoaUndoCapture().
     • ajax_moa_undo   — puts back what the process changed (5-minute window), drops the held-back email. If it can't (window
                         over, or the company has since responded) the email is released as normal and the reason is returned.
     • ajax_moa_commit — the toast was dismissed / ran out: send the held-back email, forget the snapshot.
     • ajax_moa_flush_emails — on page load: anything past its window (e.g. the tab was closed) is sent / forgotten.
   ================================================================================================ */
if (isset($_POST['ajax_moa_undo'])) {
    header('Content-Type: application/json');
    $mToken = trim($_POST['undo_token'] ?? '');
    $mSnap  = ($mToken !== '') ? ($_SESSION['moa_undo_stack'][$mToken] ?? null) : null;
    if (!is_array($mSnap)) { echo json_encode(['success'=>false,'message'=>'Undo token expired or invalid.']); exit; }
    if ((time() - (int)($mSnap['ts'] ?? 0)) >= 300) {
        cvMoaUndoSendPending($conn, $mToken); cvMoaUndoDrop($mToken);
        echo json_encode(['success'=>false,'message'=>'Undo window has expired (5 minutes).']); exit;
    }
    $mRes = cvMoaUndoRestore($conn, $mSnap);
    if (empty($mRes['ok'])) {
        cvMoaUndoSendPending($conn, $mToken); cvMoaUndoDrop($mToken);   // it stands — so the company is told, as usual
        echo json_encode(['success'=>false,'message'=>$mRes['message'] ?? 'Could not undo.']); exit;
    }
    cvMoaUndoDrop($mToken);   // undone — the held-back email is never sent
    echo json_encode(['success'=>true,'message'=>'Action undone successfully.','user_id'=>(int)$mSnap['user_id'],'action'=>$mSnap['action'] ?? '','overall_status'=>$mRes['overall'],'notif_restored_ids'=>$mRes['notif_ids']]);
    exit;
}
if (isset($_POST['ajax_moa_commit'])) {
    header('Content-Type: application/json');
    $mToken = trim($_POST['undo_token'] ?? '');
    if ($mToken !== '') { cvMoaUndoSendPending($conn, $mToken); cvMoaUndoDrop($mToken); }
    echo json_encode(['success'=>true]); exit;
}
if (isset($_POST['ajax_moa_flush_emails'])) {
    header('Content-Type: application/json');
    $mTokens = array_unique(array_merge(array_keys($_SESSION['moa_undo_stack'] ?? []), array_keys($_SESSION['moa_pending_emails'] ?? [])));
    foreach ($mTokens as $mTk) {
        $mTs = (int)($_SESSION['moa_undo_stack'][$mTk]['ts'] ?? ($_SESSION['moa_pending_emails'][$mTk]['ts'] ?? 0));
        if ($mTs > 0 && (time() - $mTs) >= 300) { cvMoaUndoSendPending($conn, $mTk); cvMoaUndoDrop($mTk); }
    }
    echo json_encode(['success'=>true]); exit;
}

/* ================= AJAX: EXPORT COMPANY BATCH ================= */
if (isset($_POST['ajax_export_company_batch'])) {
    header('Content-Type: application/json');
    $conn->query("ALTER TABLE users ADD COLUMN IF NOT EXISTS co_is_archived TINYINT(1) NOT NULL DEFAULT 0");
    $conn->query("UPDATE users SET co_is_archived=0 WHERE co_is_archived IS NULL");
    $res=$conn->query("SELECT ci.user_id,ci.company,ci.company_address,ci.telephone,ci.contact_first_name,ci.contact_last_name,ci.position,COALESCE(NULLIF(ci.company_type,''),NULLIF(u.company_type,'')) AS company_type,u.company_validation_status FROM company_information ci INNER JOIN users u ON ci.user_id=u.id WHERE u.role='company' AND u.co_is_archived=0 ORDER BY ci.company ASC");
    $rows=[];
    while($r=$res->fetch_assoc()){$rows[]=['Company'=>$r['company'],'Address'=>$r['company_address'],'Telephone'=>$r['telephone'],'Representative'=>trim($r['contact_first_name'].' '.$r['contact_last_name']),'Position'=>$r['position'],'Type'=>ucfirst($r['company_type'] ?? ''),'Validation Status'=>$r['company_validation_status']??'Pending'];}
    echo json_encode(['success'=>true,'data'=>$rows,'count'=>count($rows)]); exit;
}

/* ================= AJAX: ARCHIVE COMPANY BATCH ================= */
if (isset($_POST['ajax_archive_company_batch'])) {
    header('Content-Type: application/json');
    $batchLabel=trim($_POST['batch_label']??('Company Batch '.date('Y')));$archivedAt=date('Y-m-d H:i:s');$archivedBy=trim(($_SESSION['first_name']??'').' '.($_SESSION['last_name']??''));if(empty(trim($archivedBy)))$archivedBy='Admin';
    $conn->query("ALTER TABLE users ADD COLUMN IF NOT EXISTS co_is_archived TINYINT(1) NOT NULL DEFAULT 0");$conn->query("UPDATE users SET co_is_archived=0 WHERE co_is_archived IS NULL");
    cv_ensure_archived_companies_table($conn);   // CLEAN-UP (audit): shared definition, see cv_ensure_archived_companies_table()
    $res=$conn->query("SELECT ci.user_id,ci.company,ci.company_address,ci.telephone,ci.contact_first_name,ci.contact_last_name,ci.position,COALESCE(NULLIF(ci.company_type,''),NULLIF(u.company_type,'')) AS company_type,u.company_validation_status FROM company_information ci INNER JOIN users u ON ci.user_id=u.id WHERE u.role='company' AND u.co_is_archived=0");
    if(!$res){echo json_encode(['success'=>false,'message'=>'Query failed: '.$conn->error]);exit;}
    $count=0;$companyIds=[];
    while($r=$res->fetch_assoc()){$uid=intval($r['user_id']);$bl=$conn->real_escape_string($batchLabel);$co=$conn->real_escape_string($r['company']??'');$addr=$conn->real_escape_string($r['company_address']??'');$tel=$conn->real_escape_string($r['telephone']??'');$contact=$conn->real_escape_string(trim(($r['contact_first_name']??'').' '.($r['contact_last_name']??'')));$pos=$conn->real_escape_string($r['position']??'');$ctype=$conn->real_escape_string($r['company_type']??'');$vs=$conn->real_escape_string($r['company_validation_status']??'Pending');$aa=$conn->real_escape_string($archivedAt);$ab=$conn->real_escape_string($archivedBy);$conn->query("INSERT INTO archived_companies (batch_label,user_id,company,company_address,telephone,contact_name,position,company_type,validation_status,archived_at,archived_by) VALUES ('$bl',$uid,'$co','$addr','$tel','$contact','$pos','$ctype','$vs','$aa','$ab')");$companyIds[]=$uid;$count++;}
    if($count===0){echo json_encode(['success'=>false,'message'=>'No active companies found to archive.']);exit;}
    $ids=implode(',',$companyIds);$conn->query("UPDATE users SET co_is_archived=1 WHERE id IN ($ids) AND role='company'");
    echo json_encode(['success'=>true,'archived'=>$count,'batch'=>$batchLabel]); exit;
}

/* ================= AJAX: UNARCHIVE COMPANY BATCH ================= */
if (isset($_POST['ajax_unarchive_company_batch'])) {
    header('Content-Type: application/json');
    $batchLabel=trim($_POST['batch_label']??'');
    if(empty($batchLabel)){echo json_encode(['success'=>false,'message'=>'No batch specified.']);exit;}
    $conn->query("ALTER TABLE users ADD COLUMN IF NOT EXISTS co_is_archived TINYINT(1) NOT NULL DEFAULT 0");$conn->query("UPDATE users SET co_is_archived=0 WHERE co_is_archived IS NULL");
    $stmtB=$conn->prepare("SELECT user_id,batch_label FROM archived_companies WHERE TRIM(batch_label)=TRIM(?)");$stmtB->bind_param("s",$batchLabel);$stmtB->execute();
    $batchRes=$stmtB->get_result();$userIds=[];$realLabel=$batchLabel;
    while($r=$batchRes->fetch_assoc()){$userIds[]=intval($r['user_id']);$realLabel=$r['batch_label'];}$stmtB->close();
    if(empty($userIds)){$allBatches=[];$allRes=$conn->query("SELECT DISTINCT batch_label FROM archived_companies");if($allRes){while($ab=$allRes->fetch_assoc())$allBatches[]=$ab['batch_label'];}echo json_encode(['success'=>false,'message'=>'No archived records found for this batch.','stored_batches'=>$allBatches]);exit;}
    $ids=implode(',',$userIds);$activeCheck=$conn->query("SELECT COUNT(*) as cnt FROM users WHERE role='company' AND co_is_archived=0 AND id NOT IN ($ids)");$activeRow=$activeCheck->fetch_assoc();
    if(intval($activeRow['cnt'])>0){echo json_encode(['success'=>false,'blocked'=>true,'message'=>'There are currently active companies in the dashboard. Please archive the current batch first before unarchiving a previous batch.']);exit;}
    $conn->query("UPDATE users SET co_is_archived=0 WHERE id IN ($ids)");
    $stmtD=$conn->prepare("DELETE FROM archived_companies WHERE TRIM(batch_label)=TRIM(?)");$stmtD->bind_param("s",$realLabel);$stmtD->execute();$stmtD->close();
    echo json_encode(['success'=>true,'restored'=>count($userIds),'batch'=>$realLabel]); exit;
}

/* ================= AJAX: FETCH ARCHIVED COMPANIES ================= */
if (isset($_POST['ajax_fetch_company_archive'])) {
    header('Content-Type: application/json');
    cv_ensure_archived_companies_table($conn);   // CLEAN-UP (audit): shared definition, see cv_ensure_archived_companies_table()
    $rows=[];$batches=[];
    $res=$conn->query("SELECT * FROM archived_companies ORDER BY archived_at DESC,batch_label ASC,company ASC");
    if($res){while($r=$res->fetch_assoc()){$rows[]=['batch'=>$r['batch_label'],'company'=>$r['company'],'address'=>$r['company_address'],'contact'=>$r['contact_name'],'type'=>ucfirst($r['company_type'] ?? ''),'validation'=>$r['validation_status'],'archived'=>date('M d, Y',strtotime($r['archived_at'] ?? ''))];if(!in_array($r['batch_label'],$batches))$batches[]=$r['batch_label'];}}
    echo json_encode(['success'=>true,'rows'=>$rows,'batches'=>$batches]); exit;
}

// Ensure tables/columns exist
$conn->query("ALTER TABLE users ADD COLUMN IF NOT EXISTS company_validation_status VARCHAR(20) DEFAULT 'Pending'");
$conn->query("ALTER TABLE users ADD COLUMN IF NOT EXISTS co_is_archived TINYINT(1) NOT NULL DEFAULT 0");
$conn->query("UPDATE users SET co_is_archived=0 WHERE co_is_archived IS NULL");
cv_ensure_moa_requests_table($conn);   // CLEAN-UP (audit): shared definition, see cv_ensure_moa_requests_table()
$conn->query("ALTER TABLE moa_requests ADD COLUMN IF NOT EXISTS moa_workflow_status VARCHAR(30) DEFAULT 'pending'");
$conn->query("ALTER TABLE moa_requests ADD COLUMN IF NOT EXISTS telephone VARCHAR(30) NULL AFTER contact_last_name");
$conn->query("ALTER TABLE moa_requests ADD COLUMN IF NOT EXISTS revision_flags TEXT NULL");
$conn->query("ALTER TABLE moa_requests ADD COLUMN IF NOT EXISTS revision_comment TEXT NULL");
$conn->query("ALTER TABLE moa_requests ADD COLUMN IF NOT EXISTS is_revision TINYINT(1) NOT NULL DEFAULT 0");
// ── ADJUSTMENT: flagged_fields / rejection_notes are the columns
// moa_request.php (the company-facing page) reads to detect a rejection
// and to know which field(s) need correction. They mirror the exact same
// column names/types moa_request.php itself guards on its own page loads,
// so whichever page happens to load first creates them safely and both
// pages always agree on the same columns.
$conn->query("ALTER TABLE moa_requests ADD COLUMN IF NOT EXISTS flagged_fields TEXT NULL");
$conn->query("ALTER TABLE moa_requests ADD COLUMN IF NOT EXISTS rejection_notes TEXT NULL");
// ── NEW (this adjustment): "transferred" marks that this moa_requests
// row's file has already been auto-ingested into company_requirements
// (see autoIngestPendingMoaRequests()), so it is never re-copied/re-reset
// on every page load or poll. "admin_viewed" is what powers the MOA
// Requests inbox now acting as a simple notification list — it starts at
// 0 the moment a row is (re)transferred and flips to 1 the instant the
// admin clicks "View Request", at which point it disappears from the
// inbox. See autoIngestPendingMoaRequests(), the updated
// ajax_fetch_moa_requests handler, and ajax_moa_mark_notification_viewed
// below.
$conn->query("ALTER TABLE moa_requests ADD COLUMN IF NOT EXISTS transferred TINYINT(1) NOT NULL DEFAULT 0");
$conn->query("ALTER TABLE moa_requests ADD COLUMN IF NOT EXISTS admin_viewed TINYINT(1) NOT NULL DEFAULT 0");
$conn->query("ALTER TABLE company_requirements ADD COLUMN IF NOT EXISTS moa_workflow_stage VARCHAR(30) DEFAULT 'pending'");
// ── NEW (this adjustment): guard the same request_type column here too,
// so it exists no matter which code path runs first on a fresh install.
$conn->query("ALTER TABLE company_requirements ADD COLUMN IF NOT EXISTS request_type VARCHAR(20) NULL");
$conn->query("ALTER TABLE company_requirements ADD COLUMN IF NOT EXISTS moa_needs_revision TINYINT(1) NOT NULL DEFAULT 0");
$conn->query("ALTER TABLE company_requirements ADD COLUMN IF NOT EXISTS moa_flagged_fields TEXT NULL");
$conn->query("ALTER TABLE company_requirements ADD COLUMN IF NOT EXISTS moa_revision_comment TEXT NULL");

// ── UPDATED (this adjustment): the MOA badge now counts un-viewed
// "new entry" notifications (admin_viewed=0) instead of un-accepted
// moa_requests rows, since acceptance no longer exists as a step.
// FIX (this adjustment) — "ghost notification": the badge painted on page load counted EVERY un-viewed moa_requests row,
// but the Notification Inbox (ajax_fetch_moa_notifications) — and the first background check that later replaces this
// number — only lists rows that are ALSO transferred=1. A row that is un-viewed but not transferred is not a
// notification at all: e.g. a MOA that was flagged for revision and is waiting on the company (the flag handler sets
// transferred=0 until they resubmit), or one that could not be ingested. Those were counted here, showed up as a red
// "1" on the bell and the sidebar, and vanished a few seconds later when the first check corrected the number. The count
// now uses exactly the rule the inbox list uses, so what is painted on load is what the inbox really holds.
$moa_pending_res   = $conn->query("SELECT COUNT(*) as total FROM moa_requests WHERE admin_viewed=0 AND transferred=1");
$moa_pending_count = (int)(($moa_pending_res ? $moa_pending_res->fetch_assoc()['total'] : 0));
// NEW (this adjustment): the badge also counts the un-viewed requirement-upload notifications.
$moa_pending_count += cvReqUploadUnviewedCount($conn);

// ── UPDATED (this adjustment): company_type now falls back to
// users.company_type when company_information.company_type is empty —
// see resolveCompanyType()'s docblock above for the full explanation
// (a manually-added/imported company's classification lives on `users`,
// not `company_information`, so this used to render empty for them).
//
// ── NEW (this adjustment): a company that has been fully Verified
// (every requirement, including the MOA, is done) no longer shows up in
// this validation queue at all — it "graduates" into
// admin_company_list.php's own company roster instead (see that file's
// own matching filter, added the other way around: it now ONLY shows
// registered companies that ARE Verified). This page stays focused on
// companies that still need attention; once there's nothing left to
// validate for a company, there's nothing left for this page to do with
// them. markMoaDone() and the regular per-item save handlers below
// remove a row from the table live, with no reload needed, the instant
// this filter would exclude it (see removeCompanyRowIfVerified() in the
// script further down).
// ── NEW (this adjustment): the expanded panel now also shows the company's
// "Company Profile / Brief Description" (company_information.company_profile —
// the free-text description entered at registration and shown on
// CompanyForm.php). ensureCompanyInfoColumns() is not run on an ordinary page
// load, so the column is guaranteed here first (same statement it uses) —
// otherwise selecting it on a database that predates the column would fail
// this whole query.
$conn->query("ALTER TABLE company_information ADD COLUMN IF NOT EXISTS company_profile TEXT NULL AFTER company_address");
$companies = $conn->query("
    SELECT ci.user_id, ci.company, ci.company_address, ci.company_profile, ci.telephone,
           ci.contact_first_name, ci.contact_middle_initial, ci.contact_last_name,
           ci.position, COALESCE(NULLIF(ci.company_type, ''), NULLIF(u.company_type, '')) AS company_type,
           u.company_validation_status
    FROM company_information ci
    INNER JOIN users u ON ci.user_id = u.id
    WHERE u.role = 'company' AND u.co_is_archived = 0
      AND (u.company_validation_status IS NULL OR u.company_validation_status <> 'Verified')
    ORDER BY ci.company ASC
");

$totalCompanies = $companies->num_rows;
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title><?= $pageTitle ?></title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        :root {
            /* ══ OPTION 4 — "FIELD OPS GRID" palette (from company_list_style_previews.html). The variable NAMES are unchanged so every
               existing rule keeps working: --neust-maroon is now the grid navy, --neust-gold the light slate accent used on the navy surfaces. ══ */
            --neust-maroon: #1B2A4A;
            --neust-gold: #C3CADA;
            --bg: #E4EAF4;
            --text: #1B2A4A;
            --white: #ffffff;
            --sidebar-active: #2B3F66;
            /* the NAV HEADER + SIDE MENU keep their ORIGINAL colours (they were changed back to the original design) */
            --nav-maroon: #07145fe5;
            --nav-gold: #FFD700;
            --nav-active: #1a237e;
            --grid-border: #A3AFC7;
            --grid-ink-2: #3E4963;
            --grid-ok: #2C5A2C;
            --grid-bad: #A02A2A;
        }
        body { font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif; background: var(--bg); margin: 0; display: flex; color: var(--text); min-height: 100vh; }
        .sidebar { width: 260px; background: var(--nav-maroon); height: 100vh; position: fixed; display: flex; flex-direction: column; transition: all 0.3s ease; z-index: 1000; box-shadow: 4px 0 10px rgba(0,0,0,0.1); }
        .sidebar.collapsed { width: 80px; }
        .sidebar-header { padding: 20px; display: flex; align-items: center; justify-content: space-between; border-bottom: 1px solid rgba(255,255,255,0.1); }
        .sidebar-header-titles { overflow: hidden; transition: 0.3s; min-width: 0; }
        .sidebar-header h2 { color: var(--nav-gold); margin: 0; font-size: 18px; font-weight: bold; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; transition: 0.3s; }
        .sidebar-role-label { display: block; color: rgba(255,255,255,0.55); font-size: 11px; font-weight: 700; text-transform: uppercase; letter-spacing: 0.8px; margin-top: 3px; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; transition: 0.3s; }
        .sidebar.collapsed .sidebar-header-titles { opacity: 0; width: 0; }
        .sidebar-links { flex: 1; display: flex; flex-direction: column; padding: 10px 0; }
        .sidebar a { padding: 15px 25px; color: #cbd5e0; text-decoration: none; font-size: 14px; display: flex; align-items: center; transition: 0.2s; white-space: nowrap; position: relative; }
        .sidebar a i { width: 30px; font-size: 18px; margin-right: 15px; text-align: center; }
        .sidebar.collapsed a i { margin-right: 0; }
        .sidebar.collapsed .link-text { display: none; }
        .sidebar a:hover { color: white; background: rgba(255,255,255,0.05); }
        .sidebar a.active { background: var(--nav-active); color: white; border-left: 4px solid var(--nav-gold); }
        .sidebar .logout-link { margin-top: auto; padding: 20px; border-top: 1px solid rgba(255,255,255,0.1); }
        .sidebar .logout-link a { border: 1px solid var(--nav-gold); color: var(--nav-gold); border-radius: 6px; justify-content: center; padding: 10px; }
        .toggle-btn { background: transparent; border: none; color: white; cursor: pointer; font-size: 20px; outline: none; }
        .sidebar-badge-ungraded { background: #d97706; color: white; border-radius: 50%; width: 18px; height: 18px; font-size: 10px; font-weight: 700; display: inline-flex; align-items: center; justify-content: center; position: absolute; right: 18px; top: 50%; transform: translateY(-50%); animation: badge-pulse-ungraded 2s ease-in-out infinite; }
        @keyframes badge-pulse-ungraded { 0%,100%{box-shadow:0 0 0 0 rgba(217,119,6,0.55);} 50%{box-shadow:0 0 0 6px rgba(217,119,6,0);} }
        .main-content { margin-left: 260px; width: calc(100% - 260px); transition: 0.3s; min-height: 100vh; }
        .sidebar.collapsed + .main-content { margin-left: 80px; width: calc(100% - 80px); }
        .navbar { background: var(--nav-maroon); padding: 10px 30px; display: flex; justify-content: space-between; align-items: center; color: white; height: 60px; }
        .logo-section { display: flex; align-items: center; gap: 12px; }
        .university-logo { height: 40px; }
        .moa-request-btn { display: inline-flex; align-items: center; gap: 8px; background: rgba(255,255,255,0.12); color: white; border: 1.5px solid rgba(255,215,0,0.5); padding: 9px 18px; border-radius: 10px; font-size: 13px; font-weight: 700; cursor: pointer; transition: background 0.2s,border-color 0.2s; position: relative; white-space: nowrap; }
        .moa-request-btn:hover { background: rgba(255,215,0,0.15); border-color: var(--nav-gold); }
        .moa-request-btn .moa-nav-badge { background: #ef4444; color: white; border-radius: 50%; width: 18px; height: 18px; font-size: 10px; font-weight: 700; display: inline-flex; align-items: center; justify-content: center; animation: badge-pulse-moa 2s ease-in-out infinite; }
        @keyframes badge-pulse-moa { 0%,100%{box-shadow:0 0 0 0 rgba(239,68,68,0.6);} 50%{box-shadow:0 0 0 5px rgba(239,68,68,0);} }
        /* NEW (this adjustment): the "MOA Requests" inbox button is now a round bell-notification icon; the unread count badge sits on its top-right corner */
        .moa-request-btn.moa-bell-btn { width: 42px; height: 42px; padding: 0; gap: 0; justify-content: center; border-radius: 50%; font-size: 18px; }
        .moa-request-btn.moa-bell-btn .moa-nav-badge { position: absolute; top: -5px; right: -5px; width: auto; min-width: 18px; height: 18px; padding: 0 4px; box-sizing: border-box; border-radius: 9px; }
        .debug-log-btn { display: inline-flex; align-items: center; gap: 6px; background: rgba(255,255,255,0.08); color: rgba(255,215,0,0.85); border: 1.5px solid rgba(255,215,0,0.25); padding: 7px 14px; border-radius: 8px; font-size: 12px; font-weight: 700; cursor: pointer; transition: background 0.2s; white-space: nowrap; }
        .debug-log-btn:hover { background: rgba(255,215,0,0.1); border-color: var(--nav-gold); }
        .container { padding: 30px; max-width: 1200px; margin: 0 auto; }
        .filter-nav { display: flex; gap: 10px; margin-bottom: 25px; background: white; padding: 15px; border-radius:0; box-shadow: 0 2px 10px rgba(0,0,0,0.05); flex-wrap: wrap; align-items: center; }
        .search-bar, .filter-item { padding: 10px; border: 1px solid #A3AFC7; border-radius:0; flex: 1; min-width: 120px; }
        .archive-btn { display: inline-flex; align-items: center; gap: 8px; background: #1B2A4A; color: white; border: none; padding: 10px 18px; border-radius:0; font-size: 13px; font-weight: 700; cursor: pointer; transition: opacity 0.2s; white-space: nowrap; }
        .archive-btn:hover { opacity: 0.85; }
        /* NEW (this adjustment): each table's own search bar — same look as the shared search bar it replaces. */
        .table-search-bar { display:flex; align-items:center; gap:10px; flex-wrap:wrap; margin:0 0 14px; background:white; padding:12px 15px; border-radius:0; box-shadow:0 2px 10px rgba(0,0,0,0.05); }
        /* NEW (this adjustment): the filter dropdowns next to a table's search box keep their own width instead of stretching (the shared
           .filter-item rule makes them flex:1); the search box takes the remaining space. */
        .table-search-bar .search-bar { flex:1 1 220px; }
        .table-search-bar .filter-item { flex:0 0 auto; min-width:150px; background:#fff; }
        /* NEW (this adjustment): with the search box gone from the top bar, the status filter would otherwise stretch across the
           whole bar (it is flex:1 by the shared rule above) — keep it a normal width and keep Archive Batch on the right. */
        .filter-nav .filter-item { flex:0 1 240px; }
        .filter-nav .archive-btn { margin-left:auto; }
        .archive-btn:disabled { opacity: 0.4; cursor: not-allowed; }
        .company-table-header { display: grid; grid-template-columns: 1.5fr 1.5fr 100px 230px; padding: 10px 25px; background: var(--neust-maroon); border-radius:0; font-size: 12px; font-weight: 700; color: var(--neust-gold); letter-spacing: 0.5px; text-transform: uppercase; }
        .company-table-header span:nth-child(3), .company-table-header span:nth-child(4) { text-align: center; }
        /* NEW (this adjustment) — section headers for the two request-type
           tables (Existing / New) further down the page. */
        .request-type-section-title { display:flex; align-items:center; gap:10px; font-size:16px; font-weight:700; color:var(--neust-maroon); margin:0 0 14px; padding-bottom:8px; border-bottom:2px solid #A3AFC7; }
        .request-type-count-badge { background:#E4EAF4; color:#3E4963; font-size:12px; font-weight:700; padding:2px 10px; border-radius:0; }
        .company-row { background: white; border-bottom: 1px solid #A3AFC7; }
        .company-row:last-of-type { border-radius:0; border-bottom: none; }
        .company-list-wrapper { border-radius:0; box-shadow: 0 2px 10px rgba(0,0,0,0.06); margin-bottom: 24px; }
        .row-summary { padding: 18px 25px; display: grid; grid-template-columns: 1.5fr 1.5fr 100px 230px; cursor: pointer; align-items: center; font-size: 14px; }
        .row-summary span:first-child { font-weight: 600; color: #1B2A4A; }
        .row-summary span:nth-child(3), .row-summary span:nth-child(4) { text-align: center; justify-self: center; }
        /* NEW (this adjustment): the "New MOA" table has a fifth column, "Requirement Status" (same width as the Existing table's status column,
           and the same percent ring). Only rows / headers carrying .cv-five-col are affected; the Existing table keeps its four columns. */
        .company-table-header.cv-five-col, .row-summary.cv-five-col { grid-template-columns: 1.5fr 1.5fr 100px 230px 230px; }
        .company-table-header.cv-five-col span:nth-child(5) { text-align: center; }
        .row-summary.cv-five-col span:nth-child(5) { text-align: center; justify-self: center; }
        .row-summary:hover { background: #E4EAF4; }
        /* NEW (this adjustment): Requirement Status cell (was "Validation Status"; "Existing" rows, and now "New" rows' 2nd status cell) = rounded percent-verified ring + two-line breakdown
           (see the $vsHasRing block in renderCompanyValidationRow()). The cell's real text ("● Pending" / "● Verified") stays in the DOM,
           unpainted (font-size:0), because the status filter and the live-update JS read/write it. --vs-pct (0-100) drives the ring. */
        .row-summary { --vs-hole: #ffffff; }
        .row-summary:hover { --vs-hole: #E4EAF4; }
        .overall-status-cell[data-vpct] { display: flex; align-items: center; gap: 8px; margin: -10px 0; font-size: 0; text-align: left; }   /* margin -10px 0: the ring sits in the row's own padding, so the row is no taller than a New MOA row */
        .overall-status-cell[data-vpct]::before { content: attr(data-vpct) "%"; flex-shrink: 0; box-sizing: border-box; width: 36px; height: 36px; border-radius: 50%; display: flex; align-items: center; justify-content: center; font-size: 9.5px; line-height: 1; font-weight: 800; color: #1B2A4A; background: radial-gradient(closest-side, var(--vs-hole, #ffffff) 76%, transparent 78%), conic-gradient(#2C5A2C calc(var(--vs-pct, 0) * 1%), #C9D3E6 0); }
        .overall-status-cell[data-vpct]::after { content: attr(data-vinfo); white-space: pre-line; font-size: 11px; font-weight: 600; line-height: 1.4; color: #3E4963; text-align: left; }
        .details-pane { display: none; padding: 25px; border-top: 1px solid #A3AFC7; background: #E4EAF4; }
        .toggle-input:checked ~ .details-pane { display: block; }
        .detail-grid { display: grid; grid-template-columns: 1fr 320px; gap: 30px; }
        h4 { margin-top: 0; color: var(--neust-maroon); border-bottom: 2px solid var(--neust-gold); padding-bottom: 8px; font-size: 16px; }
        .req-item { display: flex; gap: 15px; background: white; padding: 12px; border-radius:0; margin-bottom: 10px; border: 1px solid #A3AFC7; align-items: center; transition: background 0.3s ease; }
        .req-item.denied-pending { background: #F2D5D1; border-color: #A02A2A; animation: deniedPulse 1s ease-in-out; }
        /* MOA Document special styling */
        .req-item.moa-req-item { border: 2px solid #A3AFC7; background: #E4EAF4; flex-direction: column; align-items: stretch; padding: 0; overflow: hidden; }
        .moa-req-inner { display: flex; gap: 15px; align-items: flex-start; padding: 14px 14px 10px; }
        .moa-req-workflow-section { padding: 0 14px 14px; }
        @keyframes deniedPulse { 0%{background:#F2D5D1;border-color:#A02A2A;} 100%{background:#F2D5D1;border-color:#A02A2A;} }

        /* MOA in-table stepper */
        .moa-tbl-stepper { display: flex; align-items: center; gap: 0; background: #E4EAF4; border: 1px solid #A3AFC7; border-radius:0; padding: 10px 14px; overflow-x: auto; margin-bottom: 10px; }
        .moa-tbl-step { display: flex; flex-direction: column; align-items: center; gap: 4px; flex: 1; position: relative; min-width: 60px; }
        .moa-tbl-step:not(:last-child)::after { content:''; position:absolute; right:-50%; top:13px; width:100%; height:2px; background:#A3AFC7; z-index:0; }
        .moa-tbl-step.done:not(:last-child)::after, .moa-tbl-step.active:not(:last-child)::after { background:#A3AFC7; }
        .moa-tbl-dot { width:28px; height:28px; border-radius:0; border:2px solid #A3AFC7; background:white; display:flex; align-items:center; justify-content:center; font-size:11px; color:#66718D; font-weight:700; z-index:1; position:relative; transition:all 0.2s; }
        .moa-tbl-step.done   .moa-tbl-dot { border-color:#4A7A3A; background:#4A7A3A; color:white; }
        .moa-tbl-step.active .moa-tbl-dot { border-color:var(--neust-maroon); background:var(--neust-maroon); color:var(--neust-gold); box-shadow:0 0 0 3px rgba(27,42,74,0.15); }
        .moa-tbl-step.verified-step .moa-tbl-dot { border-color:#4A7A3A; background:#2C5A2C; color:white; }
        .moa-tbl-label { font-size:9px; font-weight:700; text-transform:uppercase; letter-spacing:0.3px; color:#66718D; text-align:center; line-height:1.2; }
        .moa-tbl-step.done   .moa-tbl-label { color:#2C5A2C; }
        .moa-tbl-step.active .moa-tbl-label { color:var(--neust-maroon); }
        .moa-tbl-step.verified-step .moa-tbl-label { color:#2C5A2C; }
        /* NEW (this adjustment): the rotating hourglass shown in the "Scheduling" dot while the signing schedule isn't finalized yet. */
        .moa-dot-hourglass { display:inline-block; font-size:12px; line-height:1; animation:moaHourglassSpin 2s ease-in-out infinite; }
        @keyframes moaHourglassSpin { from { transform:rotate(0deg); } to { transform:rotate(360deg); } }
        /* workflow action buttons inside requirements table */
        .moa-wf-actions { display:flex; gap:8px; align-items:center; flex-wrap:wrap; }
        .moa-wf-btn { display:inline-flex; align-items:center; gap:5px; padding:7px 14px; border-radius:0; font-size:12px; font-weight:700; cursor:pointer; border:none; transition:opacity 0.15s,transform 0.1s; font-family:'Segoe UI',Tahoma,Geneva,Verdana,sans-serif; }
        .moa-wf-btn:hover { opacity:0.85; transform:translateY(-1px); }
        .moa-wf-btn:disabled { opacity:0.4; cursor:not-allowed; transform:none; }
        .moa-wf-btn.review  { background:#1B2A4A; color:white; }
        .moa-wf-btn.approve { background:#2C5A2C; color:white; }
        .moa-wf-btn.schedule{ background:#1B2A4A; color:white; }
        .moa-wf-btn.view-doc{ background:#1B2A4A; color:white; }
        .moa-wf-btn.done-btn{ background:#2C5A2C; color:white; }
        .moa-wf-btn.resched-btn{ background:#1B2A4A; color:white; }
        /* NEW (this adjustment): "Done" while the signing schedule isn't finalized yet — looks disabled, explains itself on click (markMoaDone()). aria-disabled + class instead of the disabled attribute so the blanket enable/disable the other MOA actions do can't unlock it. */
        .moa-wf-btn.moa-done-locked, .moa-wf-btn.moa-done-locked:hover { opacity:0.4; cursor:not-allowed; transform:none; }
        /* MOA comment + schedule inputs inside in-table workflow */
        .moa-comment-section { }
        .moa-comment-input { transition: border-color 0.15s; }
        .moa-comment-input:focus { outline:none; border-color:#1B2A4A; }
        .moa-comment-label { font-size:10px; font-weight:700; text-transform:uppercase; letter-spacing:0.3px; color:#1B2A4A; margin-bottom:4px; display:block; }
        /* ── UPDATED (this adjustment): the old quick-pick chips panel
           (.moa-schedule-panel / .moa-quick-chips / .moa-quick-chip /
           .moa-custom-datetime-row and their variants), along with
           .moa-schedule-section and .moa-schedule-label, are retired —
           "Set Signing Schedule" / "Re-Schedule" now both open the
           #moaCustomScheduleOverlay 2-step calendar modal instead (see
           its own .mcs-* styles further down). The old "Signing currently
           scheduled for…" line (.moa-schedule-confirm-line) is removed too —
           the schedule is now shown inside the signing-schedule panel. */

        .moa-thumb-wrap { width:50px; height:50px; border-radius:0; background:#E4EAF4; display:flex; align-items:center; justify-content:center; cursor:pointer; border:1px solid #A3AFC7; transition:background 0.15s; flex-shrink:0; }
        .moa-thumb-wrap:hover { background:#A3AFC7; }
        .moa-thumb-wrap i { font-size:22px; color:#1B2A4A; }
        .moa-thumb-img { width:50px; height:50px; border-radius:0; object-fit:cover; cursor:pointer; }
        /* NEW (this adjustment): hint under the "MOA Document" title, replacing the old eye/preview button. */
        .moa-doc-click-hint { font-size:12px; font-weight:400; color:#3E4963; line-height:1.4; margin:0 0 6px; }
        .req-item img { width:50px; height:50px; border-radius:0; object-fit:cover; }
        .update-form select, .update-form button { padding:6px 10px; border-radius:0; border:1px solid #A3AFC7; font-size:12px; }
        .update-form button { background:var(--neust-maroon); color:white; border:none; cursor:pointer; }
        .update-form button:disabled { opacity:0.5; cursor:not-allowed; }
        .empty-state { text-align:center; padding:80px 20px; color:#66718D; }
        .empty-state i { font-size:56px; margin-bottom:16px; display:block; color:#66718D; }
        .empty-state h3 { margin:0 0 8px; font-size:20px; color:#3E4963; }
        .empty-state p { margin:0; font-size:14px; }
        #imagePreviewModal { display:none; position:fixed; top:0; left:0; width:100%; height:100%; background:rgba(0,0,0,0.9); justify-content:center; align-items:center; z-index:9999; }
        .verified-lock { display:flex; align-items:center; gap:8px; background:#E4EAF4; border:1px solid #A3AFC7; color:#1B2A4A; padding:7px 12px; border-radius:0; font-size:12px; font-weight:600; margin-top:6px; }
        .verified-lock i { font-size:13px; }
        /* NEW (this adjustment) — VERIFIED MOA CARD: the "MOA Document Verified — Signing Scheduled" bar now sits on the SAME horizontal
           line as the MOA Document block (thumbnail + title + hint) instead of underneath it: the three are vertically centred on one row,
           the title block takes only the width it needs and the bar fills the rest. If the row ever gets too narrow the bar wraps onto its
           own line below (the previous look) instead of squeezing the text. (The !important only beats that block's inline flex:1.) */
        .moa-req-inner.moa-verified-row { align-items:center; flex-wrap:wrap; }
        .moa-req-inner.moa-verified-row > div[style*="flex:1"] { flex:0 0 auto !important; }
        .moa-req-inner.moa-verified-row > .verified-lock { flex:1 1 320px; min-width:0; margin-top:0; }
        .req-awaiting-ui { display:flex; align-items:center; gap:8px; background:#E4EAF4; border:1px solid #A3AFC7; color:#3E4963; padding:7px 12px; border-radius:0; font-size:12px; font-weight:600; margin-top:6px; }
        .req-save-feedback { display:inline-block; font-size:11px; font-weight:600; margin-left:6px; opacity:0; transition:opacity 0.3s; }
        .req-save-feedback.show { opacity:1; }
        .req-save-feedback.success { color:#2C5A2C; }
        .req-save-feedback.error   { color:#A02A2A; }
        /* Archive / Undo / MOA Drawer / Modals */
        .arch-modal-overlay { display:none; position:fixed; inset:0; background:rgba(0,0,0,0.55); z-index:9997; justify-content:center; align-items:center; backdrop-filter:blur(4px); }
        .arch-modal-box { background:white; border-radius:0; padding:36px 32px; width:480px; max-width:92%; box-shadow:0 20px 60px rgba(0,0,0,0.25); animation:popIn 0.3s cubic-bezier(0.34,1.56,0.64,1); }
        @keyframes popIn { from{transform:scale(0.85);opacity:0;}to{transform:scale(1);opacity:1;} }
        .arch-modal-icon { font-size:50px; text-align:center; display:block; margin-bottom:14px; }
        .arch-modal-title { font-size:20px; font-weight:700; color:var(--neust-maroon); text-align:center; margin:0 0 8px; }
        .arch-modal-msg { color:#3E4963; font-size:13px; text-align:center; margin:0 0 22px; line-height:1.6; }
        .arch-modal-label { display:block; font-size:12px; font-weight:700; color:#3E4963; text-transform:uppercase; letter-spacing:0.5px; margin-bottom:6px; }
        .arch-modal-input { width:100%; padding:11px 14px; border:1px solid #A3AFC7; border-radius:0; font-size:14px; box-sizing:border-box; margin-bottom:20px; }
        .arch-modal-input:focus { outline:none; border-color:var(--neust-maroon); }
        .arch-modal-actions { display:flex; gap:10px; }
        .arch-modal-cancel { flex:1; padding:12px; background:#E4EAF4; color:#3E4963; border:none; border-radius:0; font-weight:700; font-size:14px; cursor:pointer; }
        .arch-modal-cancel:hover { opacity:0.75; }
        .arch-modal-export { flex:1; padding:12px; background:#2C5A2C; color:white; border:none; border-radius:0; font-weight:700; font-size:14px; cursor:pointer; display:flex; align-items:center; justify-content:center; gap:6px; transition:opacity 0.2s; }
        .arch-modal-export:hover { opacity:0.85; }
        .arch-modal-confirm { flex:1; padding:12px; background:#1B2A4A; color:white; border:none; border-radius:0; font-weight:700; font-size:14px; cursor:pointer; display:flex; align-items:center; justify-content:center; gap:6px; transition:opacity 0.2s; }
        .arch-modal-confirm:hover { opacity:0.85; }
        .arch-modal-confirm:disabled, .arch-modal-export:disabled { opacity:0.4; cursor:not-allowed; }
        .arch-progress { text-align:center; padding:16px 0 0; font-size:13px; color:#1B2A4A; font-weight:600; display:none; }
        #coArchiveFab { position:fixed; bottom:36px; right:36px; z-index:1100; display:flex; flex-direction:column; align-items:center; gap:4px; }
        #coArchiveFabBtn { width:58px; height:58px; border-radius:0; background:#1B2A4A; color:white; border:none; font-size:22px; cursor:pointer; box-shadow:0 8px 24px rgba(27,42,74,0.4); transition:transform 0.2s,box-shadow 0.2s; display:flex; align-items:center; justify-content:center; }
        #coArchiveFabBtn:hover { transform:translateY(-3px); box-shadow:0 12px 30px rgba(27,42,74,0.5); }
        #coArchiveFabLabel { background:#1B2A4A; color:white; font-size:11px; font-weight:700; padding:4px 10px; border-radius:0; white-space:nowrap; opacity:0.85; }
        #coArchiveViewerOverlay { display:none; position:fixed; inset:0; background:rgba(0,0,0,0.55); z-index:9996; justify-content:center; align-items:center; backdrop-filter:blur(4px); }
        #coArchiveViewerBox { background:white; border-radius:0; width:900px; max-width:95vw; max-height:88vh; display:flex; flex-direction:column; box-shadow:0 24px 70px rgba(0,0,0,0.25); animation:popIn 0.3s cubic-bezier(0.34,1.56,0.64,1); }
        #coArchiveViewerHeader { padding:22px 28px; border-bottom:1px solid #A3AFC7; display:flex; align-items:center; justify-content:space-between; flex-shrink:0; }
        #coArchiveViewerHeader h3 { margin:0; color:var(--neust-maroon); font-size:17px; display:flex; align-items:center; gap:10px; }
        #coArchiveViewerClose { background:none; border:none; font-size:22px; color:#66718D; cursor:pointer; }
        #coArchiveViewerClose:hover { color:#3E4963; }
        #coArchiveViewerControls { padding:14px 28px; border-bottom:1px solid #A3AFC7; display:flex; gap:10px; align-items:center; flex-shrink:0; flex-wrap:wrap; }
        #coArchiveBatchFilter { padding:8px 12px; border:1px solid #A3AFC7; border-radius:0; font-size:13px; outline:none; min-width:220px; }
        #coArchiveSearchInput { padding:8px 12px; border:1px solid #A3AFC7; border-radius:0; font-size:13px; outline:none; flex:1; min-width:160px; }
        #coArchiveExportBtn { padding:8px 16px; background:#2C5A2C; color:white; border:none; border-radius:0; font-size:13px; font-weight:700; cursor:pointer; display:flex; align-items:center; gap:6px; white-space:nowrap; transition:opacity 0.2s; }
        #coArchiveExportBtn:hover { opacity:0.85; }
        #coArchiveUnarchiveBtn { padding:8px 16px; background:#1B2A4A; color:white; border:none; border-radius:0; font-size:13px; font-weight:700; cursor:pointer; display:flex; align-items:center; gap:6px; white-space:nowrap; transition:opacity 0.2s; }
        #coArchiveUnarchiveBtn:hover { opacity:0.85; }
        #coArchiveUnarchiveBtn:disabled { opacity:0.4; cursor:not-allowed; }
        #coArchiveViewerBody { overflow-y:auto; flex:1; padding:0 28px 24px; }
        #coArchiveViewerBody table { width:100%; border-collapse:collapse; font-size:13px; }
        #coArchiveViewerBody th { text-align:left; padding:12px 10px; color:#3E4963; font-size:0.75rem; text-transform:uppercase; font-weight:700; border-bottom:2px solid #A3AFC7; position:sticky; top:0; background:white; z-index:1; }
        #coArchiveViewerBody td { padding:12px 10px; border-bottom:1px solid #A3AFC7; color:#1B2A4A; }
        #coArchiveViewerBody tr:last-child td { border-bottom:none; }
        #coArchiveViewerBody tr:hover td { background:#E4EAF4; }
        .av-badge { display:inline-block; padding:2px 8px; border-radius:0; font-size:11px; font-weight:700; }
        .av-badge.verified { background:#D9E8D2; color:#2C5A2C; }
        .av-badge.pending  { background:#F3E7B5; color:#7A5A0B; }
        #coArchiveEmpty { text-align:center; padding:50px 20px; color:#66718D; font-size:14px; }
        #coBlockNotifOverlay, #coUnarchiveConfirmOverlay { display:none; position:fixed; inset:0; background:rgba(0,0,0,0.55); z-index:10002; justify-content:center; align-items:center; backdrop-filter:blur(4px); }
        .co-notif-box { background:white; border-radius:0; padding:36px 32px; width:440px; max-width:92%; text-align:center; box-shadow:0 20px 60px rgba(0,0,0,0.25); animation:popIn 0.3s cubic-bezier(0.34,1.56,0.64,1); }
        .co-notif-box .nb-icon { font-size:48px; margin-bottom:14px; display:block; }
        .co-notif-box .nb-title { font-size:18px; font-weight:700; margin:0 0 10px; }
        .co-notif-box .nb-msg { color:#3E4963; font-size:13px; margin:0 0 24px; line-height:1.6; }
        .nb-ok { background:var(--neust-maroon); color:var(--neust-gold); border:none; padding:12px 36px; border-radius:0; font-weight:700; font-size:14px; cursor:pointer; }
        .nb-actions { display:flex; gap:10px; justify-content:center; }
        .nb-cancel { padding:11px 28px; background:#E4EAF4; color:#3E4963; border:none; border-radius:0; font-weight:700; font-size:13px; cursor:pointer; }
        .nb-go { padding:11px 28px; background:#1B2A4A; color:white; border:none; border-radius:0; font-weight:700; font-size:13px; cursor:pointer; display:flex; align-items:center; gap:6px; }
        #undoToast { position:fixed; top:30px; left:50%; transform:translateX(-50%) translateY(-140px); background:#1B2A4A; color:white; padding:16px 22px; border-radius:0; box-shadow:0 10px 40px rgba(0,0,0,0.3); display:flex; align-items:center; gap:16px; font-size:14px; z-index:9999; min-width:360px; max-width:520px; transition:transform 0.4s cubic-bezier(0.34,1.56,0.64,1),opacity 0.3s; opacity:0; }
        /* UPDATED (this adjustment): the undo panel now sits at the TOP of the page (it used to be at the bottom) and slides down into view from above; the .show state below is unchanged. */
        #undoToast.show { transform:translateX(-50%) translateY(0); opacity:1; }
        #undoToast .toast-label { flex:1; line-height:1.4; }
        #undoToast .toast-label strong { display:block; font-size:13px; color:#66718D; font-weight:500; }
        #undoToast .toast-label span { font-size:14px; font-weight:600; }
        #undoToast .undo-btn { background:var(--neust-gold); color:#1B2A4A; border:none; padding:8px 18px; border-radius:0; font-weight:700; font-size:13px; cursor:pointer; white-space:nowrap; flex-shrink:0; }
        #undoToast .undo-btn:disabled { opacity:0.4; cursor:not-allowed; }
        #undoToast .dismiss-btn { background:none; border:none; color:#3E4963; cursor:pointer; font-size:18px; padding:0 4px; flex-shrink:0; }
        #undoToast .dismiss-btn:hover { color:white; }
        .countdown-ring { position:relative; width:36px; height:36px; flex-shrink:0; }
        .countdown-ring svg { transform:rotate(-90deg); }
        .countdown-ring circle { fill:none; stroke:#3A4A6B; stroke-width:3; }
        .countdown-ring .progress { stroke:var(--neust-gold); stroke-dasharray:88; stroke-dashoffset:0; transition:stroke-dashoffset 1s linear; stroke-linecap:round; }
        .countdown-ring .num { position:absolute; inset:0; display:flex; align-items:center; justify-content:center; font-size:11px; font-weight:700; color:var(--neust-gold); }
        .sidebar-badge-app { background:#dc2626; color:white; border-radius:50%; width:18px; height:18px; font-size:10px; font-weight:700; display:inline-flex; align-items:center; justify-content:center; position:absolute; right:18px; top:50%; transform:translateY(-50%); animation:badge-pulse-app-sidebar 2s ease-in-out infinite; }
        @keyframes badge-pulse-app-sidebar { 0%,100%{box-shadow:0 0 0 0 rgba(220,38,38,0.55);} 50%{box-shadow:0 0 0 6px rgba(220,38,38,0);} }
        .sidebar-badge-moa { background:#ef4444; color:white; border-radius:50%; width:18px; height:18px; font-size:10px; font-weight:700; display:inline-flex; align-items:center; justify-content:center; position:absolute; right:18px; top:50%; transform:translateY(-50%); animation:badge-pulse-moa-sidebar 2s ease-in-out infinite; }
        @keyframes badge-pulse-moa-sidebar { 0%,100%{box-shadow:0 0 0 0 rgba(239,68,68,0.55);} 50%{box-shadow:0 0 0 6px rgba(239,68,68,0);} }
        .pg-btn { padding:6px 11px; border:1px solid #A3AFC7; border-radius:0; background:white; color:#3E4963; font-size:13px; cursor:pointer; min-width:34px; transition:background 0.15s,color 0.15s; font-family:'Segoe UI',Tahoma,Geneva,Verdana,sans-serif; }
        .pg-btn:hover:not(:disabled) { background:#E4EAF4; border-color:#A3AFC7; }
        .pg-btn:disabled { opacity:0.4; cursor:not-allowed; }
        .pg-btn.pg-active { background:var(--neust-maroon); color:var(--neust-gold); border-color:var(--neust-maroon); font-weight:700; }
        .pg-ellipsis { font-size:13px; color:#66718D; padding:0 4px; }
        /* MOA DRAWER */
        #moaDrawerOverlay { display:none; position:fixed; inset:0; background:rgba(0,0,0,0.45); z-index:10003; backdrop-filter:blur(3px); }
        #moaDrawer { position:fixed; top:0; right:0; width:660px; max-width:96vw; height:100vh; background:white; z-index:10004; display:flex; flex-direction:column; box-shadow:-8px 0 40px rgba(0,0,0,0.18); transform:translateX(100%); transition:transform 0.35s cubic-bezier(0.4,0,0.2,1); }
        #moaDrawer.open { transform:translateX(0); }
        #moaDrawerOverlay.open { display:block; }
        .moa-drawer-header { padding:20px 24px; background:var(--neust-maroon); color:white; display:flex; align-items:center; justify-content:space-between; flex-shrink:0; }
        .moa-drawer-header h3 { margin:0; font-size:16px; font-weight:700; display:flex; align-items:center; gap:10px; }
        .moa-drawer-header h3 .moa-drawer-count { background:var(--neust-gold); color:#1B2A4A; border-radius:0; padding:2px 8px; font-size:11px; font-weight:800; }
        .moa-drawer-close { background:none; border:none; color:rgba(255,255,255,0.7); font-size:22px; cursor:pointer; }
        .moa-drawer-close:hover { color:white; }
        .moa-drawer-controls { padding:14px 20px; border-bottom:1px solid #A3AFC7; display:flex; gap:10px; align-items:center; flex-shrink:0; flex-wrap:wrap; }
        .moa-drawer-search { flex:1; padding:9px 14px; border:1px solid #A3AFC7; border-radius:0; font-size:13px; outline:none; min-width:180px; }
        .moa-drawer-search:focus { border-color:var(--neust-maroon); }
        .moa-drawer-filter { padding:9px 12px; border:1px solid #A3AFC7; border-radius:0; font-size:13px; outline:none; background:white; color:#3E4963; }
        .moa-drawer-body { flex:1; overflow-y:auto; }
        .moa-drawer-empty { text-align:center; padding:60px 20px; color:#66718D; }
        .moa-drawer-empty i { font-size:44px; margin-bottom:12px; display:block; color:#66718D; }
        .moa-drawer-empty p { margin:0; font-size:14px; }
        .moa-drawer-loading { text-align:center; padding:60px 20px; color:#66718D; font-size:14px; }
        /* MOA Card */
        .moa-card { border-bottom:1px solid #A3AFC7; padding:18px 20px; transition:background 0.15s; }
        .moa-card:hover { background:#E4EAF4; }
        .moa-card:last-child { border-bottom:none; }
        .moa-card-top { display:flex; align-items:flex-start; justify-content:space-between; gap:12px; margin-bottom:10px; }
        .moa-card-info { flex:1; min-width:0; }
        .moa-card-company { font-size:15px; font-weight:700; color:#1B2A4A; margin-bottom:3px; white-space:nowrap; overflow:hidden; text-overflow:ellipsis; }
        .moa-card-meta { font-size:12px; color:#3E4963; display:flex; flex-wrap:wrap; gap:10px; margin-bottom:4px; }
        .moa-card-meta span { display:flex; align-items:center; gap:4px; }
        .moa-card-type-chip { font-size:10px; font-weight:700; background:#E4EAF4; color:#1B2A4A; padding:2px 8px; border-radius:0; text-transform:uppercase; }
        .moa-card-type-chip.existing { background:#E4EAF4; color:#1B2A4A; }
        .moa-card-type-chip.revision { background:#F2D5D1; color:#A02A2A; }
        /* ── NEW (this adjustment): per-event-type badge on a notification
           card — distinguishes a brand-new MOA request from the three
           compliance events (revision complied / schedule agreed /
           schedule declined+proposed) a company can trigger on
           CompanyForm.php. See MOA_NOTIF_TYPE_META in the script below for
           the label/icon each maps to. */
        .moa-notif-type-badge { display:inline-flex; align-items:center; gap:5px; font-size:10.5px; font-weight:700; padding:3px 9px; border-radius:0; margin:2px 0 6px; width:fit-content; }
        .moa-notif-type-badge.notif-new       { background:#E4EAF4; color:#1B2A4A; }
        .moa-notif-type-badge.notif-revision  { background:#E4EAF4; color:#1B2A4A; }
        .moa-notif-type-badge.notif-agreed    { background:#D9E8D2; color:#2C5A2C; }
        .moa-notif-type-badge.notif-declined  { background:#F3E7B5; color:#7A5A0B; }
        .moa-notif-type-badge.notif-uploaded  { background:#E4EAF4; color:#1B2A4A; }   /* NEW (this adjustment): "Requirement Uploaded" */
        /* NEW (this adjustment): the list of documents a "Requirement Uploaded" notification is about — wraps instead of truncating like the address line */
        .moa-card-address.moa-notif-upload-detail { white-space:normal; overflow:visible; text-overflow:clip; color:#1B2A4A; font-weight:600; line-height:1.4; margin-top:3px; }
        .moa-status-badge { display:inline-flex; align-items:center; gap:4px; padding:4px 10px; border-radius:0; font-size:11px; font-weight:700; white-space:nowrap; }
        .moa-status-badge.Pending  { background:#F3E7B5; color:#7A5A0B; border:1px solid #D4BC66; }
        .moa-status-badge.Accepted { background:#D9E8D2; color:#2C5A2C; border:1px solid #9DC08F; }
        .moa-status-badge.Rejected { background:#F2D5D1; color:#A02A2A; border:1px solid #D49A94; }
        .moa-status-badge.Revised  { background:#E4EAF4; color:#1B2A4A; border:1px solid #A3AFC7; }
        .moa-card-address { font-size:12px; color:#66718D; white-space:nowrap; overflow:hidden; text-overflow:ellipsis; }
        .moa-card-flags { font-size:11px; color:#8C6C00; background:#F3E7B5; border:1px solid #D4BC66; border-radius:0; padding:6px 10px; margin-top:8px; display:flex; align-items:flex-start; gap:6px; line-height:1.5; }
        .moa-card-flags i { margin-top:2px; flex-shrink:0; }
        /* Drawer action buttons */
        .moa-card-actions { display:flex; gap:8px; align-items:center; flex-wrap:wrap; margin-top:10px; }
        .moa-action-btn { display:inline-flex; align-items:center; gap:5px; padding:7px 14px; border-radius:0; font-size:12px; font-weight:700; cursor:pointer; border:none; transition:opacity 0.15s,transform 0.1s; font-family:'Segoe UI',Tahoma,Geneva,Verdana,sans-serif; text-decoration:none; }
        .moa-action-btn:hover { opacity:0.85; transform:translateY(-1px); }
        .moa-action-btn:disabled { opacity:0.4; cursor:not-allowed; transform:none; }
        .moa-action-btn.view-btn    { background:#1B2A4A; color:white; }
        .moa-action-btn.details-btn { background:#3E4963; color:white; }
        .moa-action-btn.pdf-btn     { background:#1B2A4A; color:white; }
        .moa-action-btn.accept-btn  { background:#2C5A2C; color:white; }
        .moa-action-btn.reject-btn  { background:#A02A2A; color:white; }
        .moa-btn-spinner { width:11px; height:11px; border:2px solid rgba(255,255,255,0.4); border-top-color:white; border-radius:50%; animation:spin 0.6s linear infinite; display:none; }
        @keyframes spin { to{transform:rotate(360deg);} }
        /* MOA Blob Preview Modal */
        #moaBlobModal { display:none; position:fixed; inset:0; background:rgba(0,0,0,0.88); z-index:10006; flex-direction:column; align-items:center; justify-content:flex-start; }
        #moaBlobModal.open { display:flex; }
        #moaBlobModalBar { width:100%; background:#1B2A4A; padding:12px 24px; display:flex; align-items:center; justify-content:space-between; flex-shrink:0; gap:16px; box-shadow:0 2px 12px rgba(0,0,0,0.4); }
        #moaBlobModalTitle { color:white; font-size:14px; font-weight:700; display:flex; align-items:center; gap:10px; overflow:hidden; white-space:nowrap; text-overflow:ellipsis; flex:1; }
        #moaBlobModalTitle i { color:var(--neust-gold); flex-shrink:0; }
        #moaBlobModalTitle .blob-company-name { color:var(--neust-gold); }
        .blob-modal-bar-actions { display:flex; gap:10px; flex-shrink:0; }
        .blob-modal-bar-btn { display:inline-flex; align-items:center; gap:6px; padding:8px 16px; border-radius:0; font-size:12px; font-weight:700; cursor:pointer; border:none; transition:opacity 0.15s; font-family:'Segoe UI',Tahoma,Geneva,Verdana,sans-serif; text-decoration:none; }
        .blob-modal-bar-btn:hover { opacity:0.85; }
        .blob-modal-bar-btn.download { background:#1B2A4A; color:white; }
        .blob-modal-bar-btn.close-btn { background:#A02A2A; color:white; }
        #moaBlobModalContent { flex:1; width:100%; display:flex; align-items:center; justify-content:center; overflow:hidden; position:relative; }
        #moaBlobModalLoader { position:absolute; inset:0; display:flex; flex-direction:column; align-items:center; justify-content:center; color:rgba(255,255,255,0.7); font-size:14px; gap:16px; background:rgba(0,0,0,0.4); z-index:1; }
        #moaBlobModalLoader i { font-size:36px; color:var(--neust-gold); }
        #moaBlobPdfFrame { display:none; width:100%; height:100%; border:none; background:white; }
        #moaBlobImgWrap { display:none; width:100%; height:100%; overflow:auto; align-items:center; justify-content:center; }
        #moaBlobImg { max-width:95%; max-height:95%; border-radius:0; box-shadow:0 8px 40px rgba(0,0,0,0.6); object-fit:contain; display:block; margin:auto; }
        #moaBlobNoFile { display:none; flex-direction:column; align-items:center; justify-content:center; color:rgba(255,255,255,0.5); font-size:15px; gap:16px; height:100%; }
        #moaBlobNoFile i { font-size:52px; color:rgba(255,255,255,0.2); }
        /* MOA Details modal */
        #moaPreviewModal { display:none; position:fixed; inset:0; background:rgba(0,0,0,0.65); z-index:10005; justify-content:center; align-items:center; backdrop-filter:blur(4px); }
        #moaPreviewModal.open { display:flex; }
        #moaPreviewBox { background:white; border-radius:0; width:600px; max-width:94vw; max-height:90vh; display:flex; flex-direction:column; box-shadow:0 24px 70px rgba(0,0,0,0.3); animation:popIn 0.3s cubic-bezier(0.34,1.56,0.64,1); overflow:hidden; }
        #moaPreviewHeader { padding:18px 24px; border-bottom:1px solid #A3AFC7; display:flex; align-items:center; justify-content:space-between; flex-shrink:0; background:var(--neust-maroon); color:white; }
        #moaPreviewHeader h3 { margin:0; font-size:16px; font-weight:700; display:flex; align-items:center; gap:10px; }
        #moaPreviewHeader h3 i { color:var(--neust-gold); }
        #moaPreviewClose { background:none; border:none; color:rgba(255,255,255,0.7); font-size:24px; cursor:pointer; }
        #moaPreviewClose:hover { color:white; }
        #moaPreviewBody { padding:24px 28px; overflow-y:auto; flex:1; }
        .preview-field { display:flex; padding:10px 0; border-bottom:1px solid #A3AFC7; }
        .preview-field:last-child { border-bottom:none; }
        .preview-label { width:130px; flex-shrink:0; font-weight:600; color:#3E4963; font-size:13px; }
        .preview-value { flex:1; color:#1B2A4A; font-size:13px; word-break:break-word; }
        .preview-value.flagged { color:#A02A2A; font-weight:700; }
        .moa-preview-actions { display:flex; gap:10px; justify-content:flex-end; padding-top:16px; border-top:1px solid #A3AFC7; margin-top:8px; }
        /* Req Blob Modal */
        #reqBlobModal { display:none; position:fixed; inset:0; background:rgba(0,0,0,0.88); z-index:10007; flex-direction:column; align-items:center; justify-content:flex-start; }
        /* ─────────────────────────────────────────────────────────────────────
           FIXED: highlight animation for the MOA row that was just accepted.
           The previous version used an OUTWARD-expanding box-shadow (spread up
           to 10px beyond the card's own border), which visually bled past the
           card's edges and could paint over neighboring elements — e.g. the
           "Company Requirements" heading above it or the Contact Details panel
           beside it — since box-shadow ignores normal document flow/spacing.

           Fix: the glow now uses an INSET-only box-shadow. Inset shadows are
           always painted INSIDE the element's own border box, so the highlight
           is fully contained and can never overlap/cover anything outside the
           MOA card, no matter how the surrounding layout is sized. The card
           keeps its own visible pulse via border-color + inset glow instead.
           ───────────────────────────────────────────────────────────────── */
        .req-item.moa-req-item.moa-just-accepted {
            border-color: #4A7A3A;
            box-shadow: inset 0 0 0 2px rgba(74,122,58,0.35);
            animation: moaAcceptedGlow 1.4s ease-in-out 3;
            position: relative;
            z-index: 1;
        }
        @keyframes moaAcceptedGlow {
            0%,100% { box-shadow: inset 0 0 0 2px rgba(74,122,58,0.35); }
            50%     { box-shadow: inset 0 0 0 4px rgba(74,122,58,0.65); }
        }
        .moa-just-accepted-banner { display:flex; align-items:center; gap:8px; background:#D9E8D2; color:#2C5A2C; border:1px solid #9DC08F; padding:8px 12px; border-radius:0; font-size:12px; font-weight:700; margin:0 14px 10px; animation: fadeInBanner 0.4s ease-in; }
        @keyframes fadeInBanner { from{opacity:0;transform:translateY(-4px);} to{opacity:1;transform:translateY(0);} }

        /* -- NEW (this adjustment): row-level highlight for a company row that
           was just inserted (or refreshed) live by liveInsertOrUpdateCompanyRow()
           -- a distinct, whole-row treatment from .moa-just-accepted above,
           since that one only ever targets the inner MOA card, not the row
           itself, and this needs to be visible even while the row is still
           collapsed (the accordion is intentionally left closed so a
           background arrival never disrupts whatever the admin is doing
           elsewhere on the page). -- */
        .company-row.row-just-arrived {
            animation: rowJustArrivedGlow 2.2s ease-in-out;
        }
        @keyframes rowJustArrivedGlow {
            0%   { background: #E4EAF4; }
            60%  { background: #E4EAF4; }
            100% { background: transparent; }
        }
        /* ── NEW: "Don't show again" checkbox row inside the guard/notification modal ── */
        .guard-modal-dontshow { display:none; align-items:center; justify-content:center; gap:8px; font-size:12px; color:#3E4963; margin:-8px 0 20px; cursor:pointer; user-select:none; }
        .guard-modal-dontshow input[type="checkbox"] { width:14px; height:14px; cursor:pointer; accent-color: var(--neust-maroon); }

        /* ══ NEW: Digitalized / formalized "Custom Signing Schedule" wizard modal ══ */
        #moaCustomScheduleOverlay { display:none; position:fixed; inset:0; background:rgba(0,0,0,0.55); z-index:10060; justify-content:center; align-items:center; backdrop-filter:blur(4px); }
        #moaCustomScheduleOverlay.open { display:flex; }
        #moaCustomScheduleBox { background:white; border-radius:0; width:420px; max-width:92%; box-shadow:0 20px 60px rgba(0,0,0,0.25); overflow:hidden; animation:popIn 0.3s cubic-bezier(0.34,1.56,0.64,1); }
        .mcs-header { background:var(--neust-maroon); color:white; padding:18px 24px; display:flex; align-items:center; justify-content:space-between; }
        .mcs-header h3 { margin:0; font-size:15px; font-weight:700; display:flex; align-items:center; gap:10px; }
        .mcs-header h3 i { color:var(--neust-gold); }
        .mcs-close-btn { background:none; border:none; color:rgba(255,255,255,0.7); font-size:20px; cursor:pointer; }
        .mcs-close-btn:hover { color:white; }
        .mcs-steps-row { display:flex; align-items:center; justify-content:center; gap:10px; padding:18px 24px 0; }
        .mcs-step-dot { width:26px; height:26px; border-radius:0; background:#A3AFC7; color:#66718D; display:flex; align-items:center; justify-content:center; font-size:12px; font-weight:700; transition:all 0.2s; flex-shrink:0; }
        .mcs-step-dot.active { background:#1B2A4A; color:white; box-shadow:0 0 0 3px rgba(27,42,74,0.18); }
        .mcs-step-dot.done { background:#2C5A2C; color:white; }
        .mcs-step-line { width:44px; height:2px; background:#A3AFC7; transition:background 0.2s; }
        .mcs-labels-row { display:flex; justify-content:center; gap:58px; padding:6px 24px 0; font-size:11px; font-weight:700; color:#66718D; letter-spacing:0.3px; text-transform:uppercase; }
        .mcs-body { padding:16px 24px 4px; }
        .mcs-field-label { font-size:11px; font-weight:700; text-transform:uppercase; letter-spacing:0.4px; color:#1B2A4A; display:flex; align-items:center; gap:6px; margin-bottom:6px; }
        .mcs-input { width:100%; box-sizing:border-box; padding:11px 14px; border:1px solid #A3AFC7; border-radius:0; font-size:14px; font-family:'Segoe UI',Tahoma,Geneva,Verdana,sans-serif; }
        .mcs-input:focus { outline:none; border-color:#1B2A4A; }
        .mcs-hint { font-size:11.5px; color:#66718D; margin:8px 0 0; line-height:1.5; }

        /* ══════════════════════════════════════════════════════════════
           NEW (this adjustment) — inline calendar-grid display for the
           modal's Date step, replacing the plain text/native date input.
           ══════════════════════════════════════════════════════════════ */
        /* ── UPDATED (this adjustment): significantly more compact sizing —
           the calendar was making the whole modal stretch too tall. Fixed
           (smaller) cell heights instead of aspect-ratio:1/1 (which scaled
           each cell to the full column width, ~50-55px square), tighter
           gaps/padding, and slightly smaller type throughout. */
        .mcs-calendar { border:1px solid #A3AFC7; border-radius:0; padding:8px 10px 10px; background:#E4EAF4; }
        .mcs-calendar-header { display:flex; align-items:center; justify-content:space-between; margin-bottom:6px; }
        .mcs-calendar-month-label { font-size:12.5px; font-weight:800; color:#1B2A4A; }
        .mcs-calendar-nav { width:22px; height:22px; border-radius:0; border:none; background:#E4EAF4; color:#1B2A4A; font-size:10px; cursor:pointer; display:flex; align-items:center; justify-content:center; transition:background 0.15s; }
        .mcs-calendar-nav:hover:not(:disabled) { background:#A3AFC7; }
        .mcs-calendar-nav:disabled { opacity:0.35; cursor:not-allowed; }
        .mcs-calendar-weekdays { display:grid; grid-template-columns:repeat(7,1fr); text-align:center; font-size:9.5px; font-weight:700; color:#66718D; text-transform:uppercase; letter-spacing:0.2px; margin-bottom:2px; }
        .mcs-calendar-days { display:grid; grid-template-columns:repeat(7,1fr); gap:1px; }
        .mcs-cal-day { display:flex; align-items:center; justify-content:center; height:28px; border-radius:0; font-size:11.5px; font-weight:600; color:#1B2A4A; cursor:pointer; transition:background 0.15s, color 0.15s; user-select:none; }
        .mcs-cal-day.empty { cursor:default; }
        .mcs-cal-day:not(.empty):not(.disabled):hover { background:#E4EAF4; color:#1B2A4A; }
        .mcs-cal-day.today { border:1.5px solid #1B2A4A; color:#1B2A4A; font-weight:800; }
        .mcs-cal-day.selected { background:#1B2A4A; color:#ffffff; font-weight:800; }
        .mcs-cal-day.selected.today { border-color:#ffffff; }
        .mcs-cal-day.disabled { color:#66718D; cursor:not-allowed; }

        /* ══════════════════════════════════════════════════════════════
           NEW (this adjustment) — digital "roll picker" for the Time step,
           replacing reliance on the browser's own native time-picker
           dropdown. Same visual language (purple accents, rounded cells)
           as the calendar grid above.
           ── UPDATED (this adjustment) — rebalanced/realigned: the Hour and
           Minute columns were showing the browser's own default (Windows-
           style, up/down-arrow) scrollbar, which doesn't match the AM/PM
           column at all and made the whole row look lopsided/misaligned.
           Native scrollbars are now hidden entirely on every column, every
           item is a fixed height (so rows line up perfectly across all
           three columns), spacer rows are added above/below the real
           values in the Hour/Minute lists so even the very first ("01")
           or very last ("59") item can still be scrolled to sit exactly in
           the center like every other item, and a highlighted "selection
           band" now sits behind all three columns marking exactly where
           the active value lines up — the classic, balanced roll-picker
           look. ══════════════════════════════════════════════════════════════ */
        .mcs-time-roll { position:relative; display:flex; align-items:stretch; gap:0; border:1px solid #A3AFC7; border-radius:0; padding:8px 4px; background:#E4EAF4; height:140px; overflow:hidden; }
        .mcs-time-roll-highlight { position:absolute; left:0; right:0; top:50%; height:34px; transform:translateY(-50%); border-top:1px solid #A3AFC7; border-bottom:1px solid #A3AFC7; pointer-events:none; z-index:0; }
        .mcs-time-roll-col { position:relative; z-index:1; flex:1; overflow-y:auto; scroll-snap-type:y mandatory; display:flex; flex-direction:column; align-items:stretch; scrollbar-width:none; -ms-overflow-style:none; }
        .mcs-time-roll-col::-webkit-scrollbar { display:none; width:0; height:0; }
        /* ── FIX (this adjustment): the AM/PM column used to be a special
             non-scrolling case, vertically centered via flexbox
             (justify-content:center) instead of the spacer + scroll-to-
             center trick Hour/Minute use — so its selected item didn't
             reliably land inside the same highlight band the other two
             columns line up against, which is exactly the misalignment
             reported. It's now built and centered the EXACT same way as
             Hour/Minute (a real 2-item scrollable list with top/bottom
             spacers, selected via scrollIntoView({block:'center'})) — one
             consistent mechanism across all three columns instead of two
             different ones, so every column's selected value always sits
             in precisely the same place. */
        .mcs-time-roll-col.mcs-time-roll-meridiem { flex:0 0 56px; margin-left:2px; }
        .mcs-time-roll-sep { position:relative; z-index:1; display:flex; align-items:center; justify-content:center; flex:0 0 14px; font-size:18px; font-weight:800; color:#1B2A4A; }
        .mcs-time-roll-spacer { flex:0 0 auto; }
        /* ── UPDATED (this adjustment): redesigned to match the requested
           reference — plain greyed-out text for every value above/below
           the selection, bold dark text for the selected one, framed by
           the two divider lines above (.mcs-time-roll-highlight); no more
           filled/rounded highlight background or hover tint. */
        .mcs-time-roll-item { flex:0 0 34px; display:flex; align-items:center; justify-content:center; text-align:center; font-size:15px; font-weight:400; color:#66718D; cursor:pointer; scroll-snap-align:center; transition:color 0.15s, font-weight 0.15s; user-select:none; }
        .mcs-time-roll-item:hover { color:#3E4963; }
        .mcs-time-roll-item.selected { color:#1B2A4A; font-weight:700; }
        /* AM/PM now uses the exact same plain text styling as Hour/Minute
           (no more separate solid "pill" background for the selected
           value) — matches the reference screenshot, where the selected
           row (across all three columns) is just bold dark text framed by
           the two divider lines, nothing else. */
        .mcs-time-roll-meridiem .mcs-time-roll-item { font-size:14px; }
        .mcs-summary-line { margin-top:10px; background:#E4EAF4; border-radius:0; padding:8px 12px; font-size:12px; color:#1B2A4A; font-weight:600; align-items:center; gap:8px; display:none; }
        .mcs-summary-line.show { display:flex; }
        .mcs-actions { display:flex; gap:10px; padding:18px 24px 24px; }
        .mcs-btn { flex:1; padding:12px; border:none; border-radius:0; font-weight:700; font-size:13px; cursor:pointer; display:flex; align-items:center; justify-content:center; gap:6px; transition:opacity 0.15s; }
        .mcs-btn:hover { opacity:0.88; }
        .mcs-btn.mcs-cancel { background:#E4EAF4; color:#3E4963; }
        .mcs-btn.mcs-back { background:#A3AFC7; color:#3E4963; display:none; }
        .mcs-btn.mcs-next { background:#1B2A4A; color:white; }

        /* ══════════════════════════════════════════════════════════════
           NEW (this adjustment) — SIGNING SCHEDULE CONFIRMATION block,
           shown on the MOA row once a schedule has been set, reflecting
           whether the company representative has agreed, declined (with
           a reason and an alternative they propose instead), or hasn't
           responded yet — see CompanyForm.php for their side of this.
           ══════════════════════════════════════════════════════════════ */
        .moa-sched-confirm-block { margin: 4px 0 10px; }
        .moa-sched-confirm-note { display:flex; align-items:flex-start; gap:10px; padding:10px 14px; border-radius:0; font-size:12.5px; font-weight:600; line-height:1.5; }
        .moa-sched-confirm-note i { font-size:14px; margin-top:1px; flex-shrink:0; }
        .moa-sched-confirm-note.pending { background:#F3E7B5; color:#7A5A0B; }
        .moa-sched-confirm-note.confirmed { background:#D9E8D2; color:#2C5A2C; }
        .moa-sched-confirm-note.declined { background:#F2D5D1; color:#A02A2A; }
        .moa-sched-confirm-note.declined p { margin:4px 0 0; }
        .moa-sched-confirm-note.declined strong { display:block; }

        /* ── NEW (this adjustment): the same "signing schedule" panel design CompanyForm.php uses for the company's own schedule
           confirmation (lavender card, icon header, struck-through original date, reason / proposed schedule, hint), now used for the
           MOA Workflow's #moaScheduleConfirmBlock_* (server-rendered and updateMoaScheduleConfirmBlock()). ── */
        .moa-sched-panel { background:#E4EAF4; border:1.5px solid #A3AFC7; border-radius:0; padding:16px 18px; margin:0; }
        .moa-sched-panel-header { font-size:14px; font-weight:800; display:flex; align-items:center; gap:8px; margin-bottom:8px; }
        .moa-sched-panel-header.pending   { color:#7A5A0B; }
        .moa-sched-panel-header.confirmed { color:#2C5A2C; }
        .moa-sched-panel-header.declined  { color:#A02A2A; }
        .moa-sched-panel-datetime { font-size:15px; font-weight:700; color:#1B2A4A; margin:0 0 10px; }
        .moa-sched-panel-datetime.moa-sched-strike { font-size:13px; font-weight:600; color:#7A1F1F; text-decoration:line-through; opacity:0.75; }
        .moa-sched-panel-note { font-size:13px; color:#1B2A4A; margin:0 0 8px; line-height:1.5; }
        .moa-sched-panel-hint { font-size:12.5px; color:#1B2A4A; margin:0 0 12px; line-height:1.5; }
        .moa-sched-panel-actions { display:flex; gap:10px; flex-wrap:wrap; }
        /* NEW (this adjustment): declined state — header sentence (smaller, no icon) with the struck-through original schedule on the
           line right below it (sized to match the sentence). */
        .moa-sched-panel-title { display:inline-flex; align-items:center; min-width:0; font-size:12px; }
        .moa-sched-panel-header.declined + .moa-sched-strike { font-size:12px; }
        .moa-sched-panel > :last-child { margin-bottom:0; }

        /* ══ NEW: MOA Reject Modal — preview (left) + comment/send (right) ══ */
        #moaRejectModal { display:none; position:fixed; inset:0; background:rgba(0,0,0,0.88); z-index:10008; flex-direction:column; align-items:center; justify-content:flex-start; }
        #moaRejectModal.open { display:flex; }
        #moaRejectModalBar { width:100%; background:#1B2A4A; padding:12px 24px; display:flex; align-items:center; justify-content:space-between; flex-shrink:0; gap:16px; box-shadow:0 2px 12px rgba(0,0,0,0.4); }
        #moaRejectModalTitle { color:white; font-size:14px; font-weight:700; display:flex; align-items:center; gap:10px; overflow:hidden; white-space:nowrap; text-overflow:ellipsis; flex:1; }
        #moaRejectModalTitle i { color:#E88A8A; flex-shrink:0; }
        #moaRejectModalTitle .reject-company-name { color:var(--neust-gold); }
        #moaRejectModalBody { flex:1; width:100%; display:flex; overflow:hidden; background:#0F1A33; }
        #moaRejectPreviewPane { flex:1.3; position:relative; display:flex; align-items:center; justify-content:center; overflow:hidden; background:#0F1A33; border-right:1px solid rgba(255,255,255,0.08); }
        #moaRejectModalLoader { position:absolute; inset:0; display:flex; flex-direction:column; align-items:center; justify-content:center; color:rgba(255,255,255,0.7); font-size:14px; gap:16px; background:rgba(0,0,0,0.4); z-index:1; }
        #moaRejectModalLoader i { font-size:36px; color:var(--neust-gold); }
        #moaRejectPdfFrame { display:none; width:100%; height:100%; border:none; background:white; }
        #moaRejectImgWrap { display:none; width:100%; height:100%; overflow:auto; align-items:center; justify-content:center; }
        #moaRejectImg { max-width:95%; max-height:95%; border-radius:0; box-shadow:0 8px 40px rgba(0,0,0,0.6); object-fit:contain; display:block; margin:auto; }
        #moaRejectNoFile { display:none; flex-direction:column; align-items:center; justify-content:center; color:rgba(255,255,255,0.5); font-size:15px; gap:16px; height:100%; }
        #moaRejectNoFile i { font-size:52px; color:rgba(255,255,255,0.2); }
        #moaRejectCommentPane { flex:1; background:white; padding:22px 24px; display:flex; flex-direction:column; overflow-y:auto; min-width:340px; }
        /* UPDATED (this adjustment): the company details box at the top of the Review MOA / Reject MOA modal no longer uses the pink/red
           fill + dark-red text. It now uses the same blue-gray fill, border and navy text as the Company Details tab cards. */
        .moa-reject-info { background:#E4EAF4; border:1px solid #A3AFC7; border-radius:0; padding:12px 14px; margin-bottom:14px; }
        .moa-reject-info p { margin:4px 0; font-size:12.5px; color:#1B2A4A; display:flex; align-items:center; gap:8px; }
        .moa-reject-info p i { width:14px; text-align:center; color:var(--neust-maroon); }
        /* NEW (this adjustment): Company Profile row in the Review MOA company details box (below Email). The text can be a
           long paragraph, so the icon sits at the top, the text wraps (keeping the company's own line breaks) and a very long
           profile scrolls inside the box instead of stretching the side panel. */
        .moa-reject-info p.moa-reject-info-profile { align-items:flex-start; }
        .moa-reject-info p.moa-reject-info-profile i,
        .moa-reject-info p.moa-reject-info-profile .moa-reject-info-label { margin-top:2px; }
        #moaRejectInfoProfile { white-space:pre-line; max-height:120px; overflow-y:auto; line-height:1.45; }
        /* NEW (this adjustment): labels for every row of the company details box — a fixed-width label column so all the
           values line up, and values that wrap instead of overflowing the side panel. */
        .moa-reject-info-label { flex:0 0 112px; font-size:10.5px; font-weight:700; color:#5A6A8A; text-transform:uppercase; letter-spacing:.03em; }
        .moa-reject-info-value { flex:1; min-width:0; overflow-wrap:anywhere; }
        @media (max-width: 480px) { .moa-reject-info-label { flex-basis:90px; } }
        .moa-reject-comment-label { font-size:11px; font-weight:700; text-transform:uppercase; letter-spacing:0.4px; color:#A02A2A; margin-bottom:8px; display:flex; align-items:center; gap:6px; }
        #moaRejectComment { min-height:110px; width:100%; box-sizing:border-box; padding:12px 14px; border:1px solid #A3AFC7; border-radius:0; font-size:13px; font-family:inherit; resize:vertical; line-height:1.5; }
        #moaRejectComment:focus { outline:none; border-color:#A02A2A; }
        .moa-reject-hint { font-size:11.5px; color:#66718D; margin:10px 0 0; line-height:1.5; display:flex; gap:6px; align-items:flex-start; }
        .moa-reject-hint i { margin-top:2px; flex-shrink:0; }
        .moa-reject-actions { display:flex; gap:10px; margin-top:16px; }
        .moa-reject-actions .moa-action-btn { flex:1; justify-content:center; padding:11px 14px; font-size:13px; }
        /* ══ NEW: Flag-the-erroneous-section checklist inside the Reject modal ══ */
        .moa-reject-flags-label { font-size:11px; font-weight:700; text-transform:uppercase; letter-spacing:0.4px; color:#A02A2A; margin-bottom:8px; display:flex; align-items:center; gap:6px; }
        .moa-reject-flags-grid { display:grid; grid-template-columns:1fr 1fr; gap:6px 10px; margin-bottom:16px; }
        .moa-reject-flag-item { display:flex; align-items:center; gap:7px; background:#E4EAF4; border:1px solid #A3AFC7; border-radius:0; padding:8px 10px; cursor:pointer; transition:background 0.15s,border-color 0.15s; font-size:12px; color:#1B2A4A; user-select:none; }
        .moa-reject-flag-item:hover { background:#F2D5D1; border-color:#D49A94; }
        .moa-reject-flag-item input[type="checkbox"] { width:14px; height:14px; cursor:pointer; accent-color:#A02A2A; flex-shrink:0; }
        .moa-reject-flag-item.checked { background:#F2D5D1; border-color:#A02A2A; color:#A02A2A; font-weight:700; }
        .moa-reject-flags-hint { font-size:11.5px; color:#66718D; margin:-10px 0 16px; line-height:1.5; }
        /* ══ NEW (adjustment): "locked" View PDF button styling — shown when the
           uploaded MOA document itself was flagged on a Rejected request, so the
           admin (and anyone re-checking the Details modal) can see at a glance
           that the file on record is stale and awaiting a company reupload. ══ */
        .moa-action-btn.view-btn.locked { background:#66718D; cursor:not-allowed; opacity:0.65; }
        .moa-action-btn.view-btn.locked:hover { opacity:0.65; transform:none; }
        .moa-doc-locked-note { font-size:11px; color:#A02A2A; font-weight:700; display:inline-flex; align-items:center; gap:4px; }

        /* ══ NEW (this adjustment): live "company complied" detection UI ══
           A small toast used to notify the admin, without any page refresh,
           that a company has resubmitted their revised MOA request after
           being flagged for correction. Also a highlight used on the MOA
           drawer card itself when it flips from Rejected -> Revised in
           place, so the change is visually obvious in the open drawer. */
        .moa-compliance-toast { position:fixed; left:50%; transform:translateX(-50%); background:#1B2A4A; color:white; padding:14px 20px; border-radius:0; box-shadow:0 10px 30px rgba(0,0,0,0.35); display:flex; align-items:center; gap:12px; font-size:13px; z-index:10020; max-width:440px; opacity:0; transition:opacity 0.35s,transform 0.35s; }
        .moa-compliance-toast.show { opacity:1; }
        .moa-compliance-toast i { color:#2C5A2C; font-size:18px; flex-shrink:0; }
        .moa-compliance-toast strong { color:var(--neust-gold); }
        .moa-card.moa-just-revised { animation: moaRevisedGlow 1.4s ease-in-out 3; border-radius:0; }
        @keyframes moaRevisedGlow {
            0%,100% { box-shadow: inset 0 0 0 2px rgba(27,42,74,0.0); background:transparent; }
            50%     { box-shadow: inset 0 0 0 2px rgba(27,42,74,0.35); background:#E4EAF4; }
        }

        /* ══════════════════════════════════════════════════════════
           NEW (this adjustment) — global loading overlay, ported
           faithfully from admin_company_list.php's own
           #globalLoadingOverlay (identical markup/CSS/JS) so this page
           gets the same full-page "processing" popup during page load
           and while an in-page action (Set Signing Schedule / Re-
           Schedule, Approve MOA, Flag for Revision, Mark Done, Accept
           Proposed Schedule) is being processed — the admin now sees a
           clear indicator explaining the delay, instead of the button
           just sitting there with no feedback. Visible by default
           (covers the very first paint) and fades out automatically once
           the page finishes loading, or once every in-flight action that
           asked for it has completed — see showGlobalLoading()/
           hideGlobalLoading() further down.
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
        /* NEW (loader sync fix — same as administrator.php): when the page is reloading / leaving, the
           overlay must appear on the very next paint — no 0.35s fade-in, because the browser may stop
           painting this page before a fade would finish. Added by the script only while leaving, and
           removed again when the overlay hides. */
        #globalLoadingOverlay.gl-instant { transition: none; }
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
            border: 5px solid var(--grid-border, #A3AFC7);
            border-top-color: var(--neust-maroon, #1B2A4A);
            animation: globalLoadingSpin 0.85s linear infinite;
        }
        .global-loading-text {
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            font-size: 13px;
            font-weight: 700;
            color: var(--neust-maroon, #1B2A4A);
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

        /* ══════════════════════════════════════════════════════════════════
           NEW (this adjustment) — EXTENDED PANEL: "Tabs + Document Gallery".
           Additive block: it is placed LAST so it can restyle the existing
           .req-item / .update-form / .verified-lock / .req-awaiting-ui
           elements when they sit inside the gallery (.cv-gallery) WITHOUT
           touching the original rules above (the MOA card, stepper, buttons
           and schedule modal keep their original styling untouched).
           ══════════════════════════════════════════════════════════════════ */
        .cv-panel { display:flex; flex-direction:column; gap:16px; }

        /* Tab bar */
        .cv-tabs { display:flex; align-items:flex-end; flex-wrap:wrap; gap:6px; border-bottom:1px solid #A3AFC7; }
        .cv-tab { height:46px; padding:0 20px; border:none; border-bottom:3px solid transparent; background:transparent; color:#3E4963; font-size:14px; font-weight:600; font-family:inherit; display:inline-flex; align-items:center; gap:8px; cursor:pointer; margin-bottom:-1px; border-radius:0; transition:background 0.15s, color 0.15s, border-color 0.15s; }
        .cv-tab:hover { background:#E4EAF4; }
        .cv-tab:focus-visible { outline:2px solid var(--neust-maroon); outline-offset:-2px; }
        .cv-tab.active { color:var(--neust-maroon); border-bottom-color:var(--neust-gold); font-weight:700; }
        .cv-tab[data-tab="moa"] { color:#1B2A4A; }
        .cv-tab-count, .cv-tab-chip { font-size:11px; font-weight:700; padding:2px 8px; border-radius:0; background:#C9D3E6; color:#1B2A4A; white-space:nowrap; }
        .cv-tab-chip.review    { background:#F3E7B5; color:#7A5A0B; }
        .cv-tab-chip.revision  { background:#F2D5D1; color:#A02A2A; }
        .cv-tab-chip.approved  { background:#D9E8D2; color:#2C5A2C; }
        .cv-tab-chip.scheduled { background:#E4EAF4; color:#1B2A4A; }
        .cv-tab-chip.verified  { background:#D9E8D2; color:#2C5A2C; }
        .cv-tab-chip.awaiting  { background:#E4EAF4; color:#3E4963; }
        .cv-tabpanel { display:none; }
        .cv-tabpanel.active { display:block; }

        /* Requirements summary line + progress */
        .cv-req-summary { display:flex; align-items:center; justify-content:space-between; flex-wrap:wrap; gap:10px 16px; margin-bottom:14px; }
        .cv-req-summary-text { font-size:13px; color:#3E4963; }
        .cv-req-summary-text b { color:#1B2A4A; }
        .cv-progress { display:flex; align-items:center; gap:10px; }
        .cv-progress-bar { width:120px; height:8px; border-radius:0; background:#C9D3E6; overflow:hidden; }
        .cv-progress-fill { height:8px; background:#2C5A2C; transition:width 0.3s ease; }
        .cv-progress-pct { font-size:12px; color:#3E4963; white-space:nowrap; }

        /* Gallery — responsive: 5 across on a full-width panel, fewer on narrow screens */
        .cv-gallery { display:grid; grid-template-columns:repeat(auto-fill, minmax(190px, 1fr)); gap:14px; grid-auto-rows:minmax(270px, auto); }   /* NEW (this adjustment): 270px = a card that still shows its Status/Save form, so every card keeps that same size */
        .cv-gallery .req-item { flex-direction:column; align-items:stretch; gap:0; padding:0; margin-bottom:0; border:1px solid #A3AFC7; border-radius:0; overflow:hidden; background:#ffffff; }
        .cv-gallery .req-item.denied-pending { background:#F2D5D1; border-color:#A02A2A; animation:deniedPulse 1s ease-in-out; }
        .cv-card-preview { position:relative; flex:1 0 132px; min-height:132px; background:#E4EAF4; display:flex; align-items:center; justify-content:center; }
        .cv-gallery .req-item img.cv-thumb-img { position:absolute; top:0; left:0; width:100%; height:100%; border-radius:0; object-fit:cover; object-position:top center; display:block; }
        .cv-no-file { position:absolute; top:14px; right:14px; bottom:14px; left:14px; border:1px dashed #A3AFC7; border-radius:0; display:flex; flex-direction:column; align-items:center; justify-content:center; gap:6px; color:#3E4963; font-size:12px; font-weight:600; }
        .cv-no-file i { font-size:20px; }
        .cv-card-ribbon { position:absolute; top:10px; right:10px; z-index:6; display:flex; gap:6px; pointer-events:none; }   /* UPDATED (this adjustment): pinned to the top-right corner of the file preview (.cv-card-preview is position:relative); pointer-events:none so it never blocks a click on the file underneath */
        .cv-rb { display:inline-flex; align-items:center; gap:4px; font-size:11px; font-weight:700; padding:3px 9px; border-radius:0; white-space:nowrap; box-shadow:0 1px 2px rgba(0,0,0,0.12); }
        .cv-rb-verified { background:#D9E8D2; color:#2C5A2C; }
        .cv-rb-pending  { background:#F3E7B5; color:#7A5A0B; }
        .cv-rb-awaiting { background:#E4EAF4; color:#3E4963; }
        /* exactly one status ribbon is visible, driven by data-state (kept in sync by cvSyncReqCard()) */
        .cv-gallery .req-item[data-state="verified"] .cv-rb-pending,
        .cv-gallery .req-item[data-state="verified"] .cv-rb-awaiting,
        .cv-gallery .req-item[data-state="pending"]  .cv-rb-awaiting,
        .cv-gallery .req-item[data-state="awaiting"] .cv-rb-pending { display:none; }
        .cv-card-body { padding:12px; display:flex; flex-direction:column; gap:8px; }
        /* NEW (this adjustment): CONSISTENT CARD SIZE. Every card is the same height as one that still has its Status/Save form
           (a "not verified yet" card — see .cv-gallery grid-auto-rows above). The body keeps its natural height (the inline flex:1 is
           overridden on purpose — the JS hook that looks for it is unaffected) and the preview absorbs the rest. A card with no form
           (Verified / Awaiting) therefore gets a much larger preview so the document can be seen properly, plus tighter padding and the
           status icon kept beside the requirement name (the name wraps instead of dropping the icon to a second line). */
        .cv-gallery .cv-card-body { flex:0 0 auto !important; }
        .cv-gallery .req-item[data-state="verified"] .cv-card-body,
        .cv-gallery .req-item[data-state="awaiting"] .cv-card-body { box-sizing:border-box; min-height:54px; padding:8px 10px; justify-content:center; }
        .cv-gallery .req-item[data-state="verified"] .cv-card-label,
        .cv-gallery .req-item[data-state="awaiting"] .cv-card-label { flex-wrap:nowrap; }
        .cv-gallery .req-item[data-state="verified"] .cv-card-label .req-save-feedback:not(.show),
        .cv-gallery .req-item[data-state="awaiting"] .cv-card-label .req-save-feedback:not(.show) { display:none; }
        .cv-card-label { font-size:13.5px; font-weight:700; color:#1B2A4A; display:flex; align-items:center; flex-wrap:wrap; gap:4px; }
        .cv-card-body .verified-lock,
        .cv-card-body .req-awaiting-ui { margin-top:0; align-items:flex-start; line-height:1.4; }
        .cv-card-body .verified-lock i,
        .cv-card-body .req-awaiting-ui i { margin-top:2px; }
        .cv-gallery .update-form { display:flex; flex-direction:column; gap:8px; }
        .cv-gallery .update-form select,
        .cv-gallery .update-form button { width:100%; height:38px; box-sizing:border-box; padding:0 10px; border-radius:0; font-size:13px; font-family:inherit; }
        .cv-gallery .update-form select { border:1px solid #A3AFC7; background:#ffffff; }
        .cv-gallery .update-form button { background:var(--neust-maroon); color:#ffffff; border:none; font-weight:600; cursor:pointer; }
        .cv-gallery .update-form button:disabled { opacity:0.5; cursor:not-allowed; }

        /* MOA Workflow tab — same card, laid out in two columns (stepper/schedule/actions | email comment) */
        .cv-moa-panel .moa-req-workflow-section { display:grid; grid-template-columns:minmax(0, 1fr) 360px; column-gap:20px; align-items:start; }
        .cv-moa-panel .moa-req-workflow-section > * { grid-column:1; min-width:0; }
        .cv-moa-panel .moa-req-workflow-section > .moa-comment-section { grid-column:2; grid-row:1; }
        /* NEW (this adjustment): TOP-RIGHT PLACEMENT of the signing-schedule panel. While the panel is showing, it moves to the top-right
           of the WHOLE MOA card — beside the "MOA Document" header row, the stepper and the email-comment box — so it uses the empty space
           that used to sit to the right of the header. The comment box sits under the stepper on the left and the three action buttons sit
           directly BELOW the schedule panel, in the right-hand column. How: the card itself becomes a two-column grid and the workflow section is display:contents, so its children
           (stepper / comment / panel / buttons) join that grid. It is switched on by the .moa-sched-top-right class on the card: set by
           the server whenever the panel is rendered, added live by updateMoaScheduleConfirmBlock(), and removed again by
           showMoaVerifiedLock() once "Done" hides the workflow section. Without a panel (Pending / Approved stages) nothing changes:
           stepper | comment, as before. */
        .cv-moa-panel .req-item.moa-req-item.moa-sched-top-right { display:grid; grid-template-columns:minmax(0, 1fr) 436px; column-gap:20px; row-gap:0; align-items:start; padding-bottom:6px; }
        .cv-moa-panel .req-item.moa-req-item.moa-sched-top-right > .moa-req-inner { grid-column:1; grid-row:1; }
        .cv-moa-panel .req-item.moa-req-item.moa-sched-top-right > .moa-needs-revision-note { grid-column:1; }
        .cv-moa-panel .req-item.moa-req-item.moa-sched-top-right > .moa-req-workflow-section { display:contents; }
        .cv-moa-panel .req-item.moa-req-item.moa-sched-top-right > .moa-req-workflow-section > .moa-tbl-stepper { grid-column:1; grid-row:auto; margin-top:15px; margin-left:14px; }
        .cv-moa-panel .req-item.moa-req-item.moa-sched-top-right > .moa-req-workflow-section > .moa-comment-section { grid-column:1; grid-row:auto; margin-left:14px; }
        .cv-moa-panel .req-item.moa-req-item.moa-sched-top-right > .moa-req-workflow-section > .moa-sched-confirm-block { grid-column:2; grid-row:1 / span 2; align-self:end; display:flex; flex-direction:column; margin:0 14px 0 0; }   /* NEW (this adjustment): smaller background — natural height, vertically centred (was stretched to the full height) */
        .cv-moa-panel .req-item.moa-req-item.moa-sched-top-right > .moa-req-workflow-section > .moa-sched-confirm-block > .moa-sched-panel { flex:0 1 auto; display:flex; flex-direction:column; justify-content:center; align-items:center; text-align:center; }   /* NEW (this adjustment): contents centred in the panel */
        .cv-moa-panel .req-item.moa-req-item.moa-sched-top-right > .moa-req-workflow-section > .moa-wf-actions { grid-column:2; grid-row:3; align-self:start; justify-content:center; margin:10px 14px 0 0; }   /* NEW (this adjustment): the three buttons sit below the schedule panel */
        @media (max-width: 1000px) {
            .cv-moa-panel .moa-req-workflow-section { grid-template-columns:minmax(0, 1fr); }
            .cv-moa-panel .moa-req-workflow-section > .moa-comment-section { grid-column:1; grid-row:auto; }
            /* single column: back to the card's normal stacked layout (header, stepper, comment, schedule panel, actions) */
            .cv-moa-panel .req-item.moa-req-item.moa-sched-top-right { display:flex; gap:15px; align-items:stretch; padding-bottom:0; }
            .cv-moa-panel .req-item.moa-req-item.moa-sched-top-right > .moa-req-workflow-section { display:grid; }
            .cv-moa-panel .req-item.moa-req-item.moa-sched-top-right > .moa-req-workflow-section > .moa-tbl-stepper { grid-column:1; grid-row:auto; margin-top:0; margin-left:0; }
            .cv-moa-panel .req-item.moa-req-item.moa-sched-top-right > .moa-req-workflow-section > .moa-comment-section { margin-left:0; }
            .cv-moa-panel .req-item.moa-req-item.moa-sched-top-right > .moa-req-workflow-section > .moa-sched-confirm-block { grid-column:1; grid-row:auto; display:block; margin:4px 0 10px; }
            .cv-moa-panel .req-item.moa-req-item.moa-sched-top-right > .moa-req-workflow-section > .moa-wf-actions { grid-column:1; grid-row:auto; align-self:auto; justify-content:flex-start; margin:0; }
            .cv-moa-panel .req-item.moa-req-item.moa-sched-top-right > .moa-req-workflow-section > .moa-sched-confirm-block > .moa-sched-panel { display:block; text-align:left; }
        }

        /* Company Details tab — ONE card: the four contact fields on a single row, then the
           Company Profile / Brief Description as a full-width field in the same card.
           NOTE: the <p> fields set margin:0 explicitly — browsers give <p> a default top/bottom
           margin, which used to open a large gap between rows of fields. The layout responds to
           the width of the CARD (container query), not the window, so it stays tidy whether the
           sidebar is open or collapsed: 4 across -> 2x2 -> stacked. */
        .cv-details-card { background:#ffffff; padding:20px; border-radius:0; border:1px solid #A3AFC7; container-type:inline-size; }
        .cv-details-card h4 { margin-bottom:16px; }
        .cv-details-grid { display:grid; grid-template-columns:minmax(0, 1.5fr) minmax(0, 1.2fr) minmax(0, 1fr) minmax(0, 0.8fr); gap:12px; }
        .cv-details-grid > p,
        .cv-details-grid > .cv-detail-wide { margin:0; min-width:0; padding:12px 14px; background:#E4EAF4; border:1px solid #A3AFC7; border-radius:0; display:flex; flex-direction:column; gap:6px; overflow-wrap:anywhere; }
        .cv-details-grid > p { font-size:14px; font-weight:600; color:#1B2A4A; }
        .cv-details-grid > p > strong,
        .cv-detail-label { display:flex; align-items:center; gap:6px; font-size:11px; font-weight:700; letter-spacing:0.5px; text-transform:uppercase; color:#3E4963; }
        .cv-details-grid i { width:14px; text-align:center; font-size:12px; color:var(--neust-maroon); }
        .cv-detail-wide { grid-column:1 / -1; }
        .cv-profile-text { font-size:14px; font-weight:400; line-height:1.65; color:#1B2A4A; white-space:pre-line; overflow-wrap:anywhere; max-height:280px; overflow-y:auto; }
        .cv-profile-text.is-empty { color:#3E4963; font-style:italic; }
        @container (max-width: 760px) { .cv-details-grid { grid-template-columns:repeat(2, minmax(0, 1fr)); } }
        @container (max-width: 420px) { .cv-details-grid { grid-template-columns:minmax(0, 1fr); } }

        /* ══════════════════════════════════════════════════════════════════
           NEW (this adjustment) — MOA request inbox: notification card re-layout.
           The "NEW MOA / HAS MOA" chip that used to sit top-right is gone; the
           notification-type badge (New Request / Revision Complied / Schedule
           Agreed / Schedule Proposed) now takes that spot instead, and the
           "View Request" button sits on the right, vertically centred against
           the contact / position / time line and the address line beside it.
           Scoped to .moa-notif-card so the older status-card renderer
           (renderMoaDrawer) keeps its own layout untouched.
           ══════════════════════════════════════════════════════════════════ */
        .moa-notif-card .moa-card-top { align-items:center; margin-bottom:8px; }
        .moa-notif-card .moa-card-top .moa-card-company { margin-bottom:0; }
        .moa-notif-card .moa-card-top .moa-notif-type-badge { margin:0; flex-shrink:0; white-space:nowrap; }
        .moa-notif-card .moa-card-detail-row { display:flex; align-items:center; justify-content:space-between; gap:14px; }
        .moa-notif-card .moa-card-detail-row .moa-card-actions { margin-top:0; flex-shrink:0; flex-wrap:nowrap; }
        .moa-notif-card .moa-card-detail-row .moa-action-btn { white-space:nowrap; }

        /* ══════════════════════════════════════════════════════════════════
           NEW (this adjustment) — REQUIREMENTS GALLERY: MULTIPLE-FILE DISPLAY
           + PDF PREVIEW, ported from CompanyForm.php's requirement cards
           (same stacked-card look, same dark full-bleed viewer modal, same
           prev / next paging). Additive: every rule is scoped to new class
           names, so no existing card, ribbon, form or modal is affected.
           ══════════════════════════════════════════════════════════════════ */
        .cv-preview-trigger { cursor:pointer; }
        .cv-gallery .req-item img.cv-thumb-img.cv-preview-trigger { transition:filter 0.15s; }
        .cv-gallery .req-item img.cv-thumb-img.cv-preview-trigger:hover { filter:brightness(0.93); }

        /* A single PDF — a tile that fills the preview area, in the same red used by the stacked PDF layers */
        .cv-pdf-tile { position:absolute; top:14px; right:14px; bottom:14px; left:14px; box-sizing:border-box; border:1px solid #D49A94; border-radius:0; background:#F2D5D1; display:flex; flex-direction:column; align-items:center; justify-content:center; gap:6px; color:#A02A2A; font-size:12px; font-weight:700; transition:transform 0.15s, box-shadow 0.15s, background 0.15s; }
        .cv-pdf-tile i { font-size:46px; color:#A02A2A; }
        .cv-pdf-tile:hover { background:#F2D5D1; transform:translateY(-2px); box-shadow:0 6px 16px rgba(27,42,74,0.12); }

        /* 2+ files — ONE overlaying "stacked card" (max 3 layers shown) with a count badge */
        .cv-file-stack-wrap { flex-shrink:0; text-align:center; }
        .cv-file-stack { position:relative; width:112px; height:104px; margin:0 auto 4px; }
        .cv-file-stack .cv-stack-layer { position:absolute; top:8px; left:12px; width:88px; height:88px; box-sizing:border-box; border-radius:0; border:2px solid #A3AFC7; background-color:#ffffff; box-shadow:0 2px 5px rgba(0,0,0,0.12); overflow:hidden; transition:transform 0.15s; }
        .cv-file-stack .cv-stack-layer img { width:100%; height:100%; object-fit:cover; object-position:top center; display:block; }
        .cv-file-stack .cv-stack-layer.layer-1 { transform:rotate(0deg) translate(0, 0); z-index:3; }
        .cv-file-stack .cv-stack-layer.layer-2 { transform:rotate(7deg) translate(5px, 3px); z-index:2; }
        .cv-file-stack .cv-stack-layer.layer-3 { transform:rotate(-9deg) translate(-5px, 4px); z-index:1; }
        .cv-file-stack-wrap:hover .cv-stack-layer.layer-1 { transform:rotate(0deg) translate(0, -2px); }
        .cv-file-stack-wrap:hover .cv-stack-layer.layer-2 { transform:rotate(9deg) translate(6px, 0px); }
        .cv-file-stack-wrap:hover .cv-stack-layer.layer-3 { transform:rotate(-11deg) translate(-6px, 1px); }
        .cv-stack-layer.cv-stack-layer-pdf { display:flex; align-items:center; justify-content:center; background-color:#F2D5D1; border-color:#D49A94; }
        .cv-stack-layer.cv-stack-layer-pdf i { font-size:36px; color:#A02A2A; }
        /* a stacked file that can no longer be loaded reads as an explicit "unavailable" tile, not a blank one */
        .cv-stack-layer.cv-stack-layer-missing { background-color:#E4EAF4; border-style:dashed; border-color:#A3AFC7; display:flex; align-items:center; justify-content:center; }
        .cv-stack-layer.cv-stack-layer-missing i { font-size:28px; color:#66718D; }
        .cv-file-stack .cv-stack-count-badge { position:absolute; bottom:2px; right:2px; z-index:4; background:var(--neust-maroon); color:var(--neust-gold); font-size:11px; font-weight:700; border-radius:0; min-width:22px; height:22px; display:flex; align-items:center; justify-content:center; padding:0 6px; border:2px solid #ffffff; box-sizing:border-box; }
        .cv-file-stack-label { font-size:11px; color:#3E4963; font-weight:700; }

        /* Requirement document preview modal — the same full-bleed dark viewer CompanyForm.php uses */
        .cv-doc-modal { display:none; position:fixed; inset:0; box-sizing:border-box; background:#0F1A33; z-index:10008; flex-direction:column; overflow:hidden; }
        .cv-doc-modal-bar { width:100%; box-sizing:border-box; background:#1B2A4A; padding:14px 20px; display:flex; align-items:center; justify-content:space-between; gap:16px; flex-shrink:0; box-shadow:0 2px 12px rgba(0,0,0,0.4); }
        .cv-doc-modal-title { color:#ffffff; font-size:14px; font-weight:700; display:flex; align-items:center; gap:10px; overflow:hidden; white-space:nowrap; min-width:0; }
        .cv-doc-modal-icon { color:var(--neust-gold); font-size:16px; flex-shrink:0; }
        .cv-doc-modal-name { color:var(--neust-gold); overflow:hidden; text-overflow:ellipsis; white-space:nowrap; }
        .cv-doc-modal-counter { color:#A3AFC7; font-size:12px; font-weight:600; flex-shrink:0; }
        .cv-doc-modal-close { background:rgba(255,255,255,0.15); border:1px solid rgba(255,255,255,0.25); color:#ffffff; font-size:20px; font-weight:400; width:34px; height:34px; border-radius:0; cursor:pointer; line-height:1; flex-shrink:0; display:flex; align-items:center; justify-content:center; transition:background 0.15s; }
        .cv-doc-modal-close:hover { background:rgba(255,255,255,0.28); }
        .cv-doc-modal-viewer { position:relative; flex:1; min-height:0; background:#ffffff; display:flex; align-items:stretch; justify-content:stretch; overflow:hidden; }
        .cv-doc-modal-viewer iframe { width:100%; height:100%; border:none; display:block; background:#ffffff; }
        .cv-doc-modal-viewer.image-mode { background:#000000; align-items:center; justify-content:center; padding:26px; }
        .cv-doc-modal-image { max-width:100%; max-height:100%; border-radius:0; box-shadow:0 8px 40px rgba(0,0,0,0.6); display:block; margin:auto; }
        .cv-doc-modal-unavailable { color:#C3CADA; font-size:14px; font-weight:600; display:flex; flex-direction:column; align-items:center; gap:10px; margin:auto; }
        .cv-doc-modal-unavailable i { font-size:40px; color:#3E4963; }
        .cv-doc-nav-btn { position:absolute; top:50%; transform:translateY(-50%); background:rgba(27,42,74,0.55); color:#ffffff; border:none; width:40px; height:40px; border-radius:0; font-size:15px; cursor:pointer; display:flex; align-items:center; justify-content:center; transition:background 0.15s; z-index:5; }
        .cv-doc-nav-btn:hover { background:rgba(27,42,74,0.82); }
        .cv-doc-nav-prev { left:16px; }
        .cv-doc-nav-next { right:16px; }

        /* ══════════════════════════════════════════════════════════════════
           NEW (this adjustment) — LIVE REQUIREMENT UPLOADS. When a company
           uploads a requirement, the affected card(s) are swapped in place
           (see cvRefreshRequirementsLive()) and flagged for a few seconds so
           the admin can see exactly what just arrived. Additive: new class
           names only.
           ══════════════════════════════════════════════════════════════════ */
        .cv-req-card.cv-card-just-uploaded { border-color:#1B2A4A; animation:cvCardUploadedGlow 1.6s ease-in-out 3; }
        @keyframes cvCardUploadedGlow {
            0%, 100% { box-shadow:0 0 0 0 rgba(27,42,74,0); }
            50%      { box-shadow:0 0 0 4px rgba(27,42,74,0.35); }
        }
        .cv-new-upload-tag { position:absolute; top:10px; left:10px; z-index:6; display:inline-flex; align-items:center; gap:5px; background:#1B2A4A; color:#ffffff; font-size:11px; font-weight:700; padding:3px 9px; border-radius:0; box-shadow:0 1px 2px rgba(0,0,0,0.18); pointer-events:none; white-space:nowrap; }
        .cv-uploaded-banner { display:flex; align-items:center; gap:8px; margin:0 0 12px; padding:9px 14px; background:#E4EAF4; border:1px solid #A3AFC7; border-radius:0; color:#1B2A4A; font-size:13px; font-weight:600; }

        /* ══════════════════════════════════════════════════════════════════
           UPDATED (this adjustment) — NOTIFICATION TOASTS, AT THE TOP, IN THE ORIGINAL DESIGN.
           A new notification of ANY type (New Request, Revision Complied, Schedule
           Agreed, Schedule Proposed, Requirement Uploaded) pops up at the TOP of the
           page as the original toast: the same dark rounded bar with a green icon and
           one line of text as .moa-compliance-toast (the values below are that rule's,
           unchanged) — the label / "View" button / × design that briefly replaced it is
           gone. It only differs in WHERE it sits: `top` is set by cvLayoutTopToasts(),
           so it stacks downward and always sits BELOW the undo panel while that is on
           screen. Its own class (not .moa-compliance-toast) so the toasts that stay at
           the bottom (e.g. "company completed") keep their position and stacking.
           pointer-events:none: it is a message, not a control, so it never blocks the
           page underneath it. ══════════════════════════════════════════════════════════════════ */
        .cv-top-toast { position:fixed; top:30px; left:50%; transform:translateX(-50%); background:#1B2A4A; color:white; padding:14px 20px; border-radius:0; box-shadow:0 10px 30px rgba(0,0,0,0.35); display:flex; align-items:center; gap:12px; font-size:13px; z-index:10020; max-width:440px; opacity:0; transition:opacity 0.35s, top 0.3s ease; pointer-events:none; }
        .cv-top-toast.show { opacity:1; }
        .cv-top-toast i { color:#2C5A2C; font-size:18px; flex-shrink:0; }
        .cv-top-toast strong { color:var(--neust-gold); }

        /* ══════════════════════════════════════════════════════════════════
           NEW (this adjustment) — REJECTED REQUIREMENTS (was "Denied").
           A rejected card (data-rejected="1") shows a "Rejected" pill instead
           of Pending / Awaiting, replaces the previously uploaded file's
           preview with a "Rejected — awaiting re-upload" tile, and shows the
           admin's remark directly below the requirement name. Everything is
           keyed off that one attribute, so it is set server-side for a card
           that is already rejected and toggled by updateReqItemUI() the moment
           the admin rejects (or undoes it) — no reload. The remark is a text
           INPUT now (it used to be a dropdown); styled like the status select.
           ══════════════════════════════════════════════════════════════════ */
        /* UPDATED (this adjustment): the remark box wraps its text and SCROLLS vertically (about three lines are visible at a time)
           instead of being a one-line field the text runs off the side of. Fixed height + no resize handle, so the card's layout
           doesn't jump around; the scrollbar is thin and only appears when the text is long enough to need it. */
        .cv-gallery .update-form textarea[name="remark"] { width:100%; height:66px; box-sizing:border-box; padding:8px 10px; border:1px solid #A3AFC7; border-radius:0; background:#ffffff; font-size:13px; line-height:1.4; font-family:inherit; resize:none; overflow-x:hidden; overflow-y:auto; overflow-wrap:anywhere; scrollbar-width:thin; scrollbar-color:#A3AFC7 transparent; }
        .cv-gallery .update-form textarea[name="remark"]:focus { outline:none; border-color:#A02A2A; box-shadow:0 0 0 3px rgba(160,42,42,0.12); }
        .cv-rb-rejected { display:none; background:#F2D5D1; color:#A02A2A; }
        .cv-gallery .req-item[data-rejected="1"] .cv-rb-rejected { display:inline-flex; }
        .cv-gallery .req-item[data-rejected="1"] .cv-rb-pending,
        .cv-gallery .req-item[data-rejected="1"] .cv-rb-awaiting,
        .cv-gallery .req-item[data-rejected="1"] .cv-rb-verified { display:none; }
        .cv-rej-placeholder { display:none; position:absolute; top:14px; right:14px; bottom:14px; left:14px; box-sizing:border-box; border:1px dashed #D49A94; border-radius:0; background:#F2D5D1; flex-direction:column; align-items:center; justify-content:center; gap:8px; color:#A02A2A; font-size:12px; font-weight:600; text-align:center; line-height:1.4; }
        .cv-rej-placeholder i { font-size:24px; }
        .cv-req-card[data-rejected="1"] .cv-rej-placeholder { display:flex; }
        .cv-req-card[data-rejected="1"] .cv-card-preview > :not(.cv-card-ribbon):not(.cv-rej-placeholder):not(.cv-new-upload-tag) { display:none !important; }
        .cv-card-remark { display:none; align-items:flex-start; gap:7px; padding:7px 10px; background:#F2D5D1; border:1px solid #D49A94; border-radius:0; color:#A02A2A; font-size:12px; line-height:1.4; overflow-wrap:anywhere; }
        .cv-card-remark i { margin-top:2px; flex-shrink:0; }
        /* NEW (this adjustment): a long remark scrolls inside its box (about four lines are visible) instead of stretching the card. */
        .cv-card-remark > span { flex:1; min-width:0; max-height:5.6em; overflow-y:auto; padding-right:4px; scrollbar-width:thin; scrollbar-color:#D49A94 transparent; }
        .cv-card-remark b { font-weight:700; }
        .cv-req-card[data-rejected="1"] .cv-card-remark { display:flex; }

        /* ══════════════════════════════════════════════════════════════════
           NEW (this adjustment) — REVISION COMPLIED detail: on the inbox card,
           and in the Review MOA preview (a panel listing what the company
           updated + a "Complied" tag on the matching section checkboxes).
           ══════════════════════════════════════════════════════════════════ */
        .moa-card-address.moa-notif-upload-detail.complied { color:#2C5A2C; }
        #moaRejectCompliancePanel { display:none; margin:0 0 16px; padding:12px 14px; background:#D9E8D2; border:1px solid #9DC08F; border-radius:0; font-size:13px; color:#1F421F; }
        #moaRejectCompliancePanel .mcp-title { display:flex; align-items:center; gap:7px; font-weight:700; margin-bottom:4px; }
        #moaRejectCompliancePanel .mcp-hint { font-size:12px; color:#2C5A2C; margin-bottom:6px; }
        #moaRejectCompliancePanel .mcp-item { margin-top:6px; padding:7px 10px; background:#ffffff; border:1px solid #9DC08F; border-radius:0; }
        #moaRejectCompliancePanel .mcp-label { font-weight:700; font-size:12px; color:#2C5A2C; }
        #moaRejectCompliancePanel .mcp-value { margin-top:2px; max-height:72px; overflow-y:auto; overflow-wrap:anywhere; color:#0F1A33; font-size:13px; line-height:1.4; }
        #moaRejectCompliancePanel .mcp-note { margin-top:8px; font-size:12px; color:#3E4963; overflow-wrap:anywhere; }
        /* ── NEW (layout adjustment) — the "Revision complied" items follow the SAME row structure as the company form
           (Contact name(s) / Position / Telephone, then Company Name / Address, then Company Profile full-width) instead
           of one long stacked list. Each .mcp-item keeps its own look; only how they are arranged changes. The number
           of columns per row is set by the cols-N class from cvComplianceRowsHtml(). The pane's width varies with the
           screen, so the panel is a size container: a 3-column row drops to 2 columns when narrow, and every row to a
           single column when very narrow (browsers without container queries just keep the columns). ── */
        #moaRejectCompliancePanel { container-type:inline-size; }
        #moaRejectCompliancePanel .mcp-grid { display:grid; grid-template-columns:minmax(0,1fr); gap:6px 10px; margin-top:6px; }
        #moaRejectCompliancePanel .mcp-grid.cols-2 { grid-template-columns:repeat(2,minmax(0,1fr)); }
        #moaRejectCompliancePanel .mcp-grid.cols-3 { grid-template-columns:repeat(3,minmax(0,1fr)); }
        #moaRejectCompliancePanel .mcp-grid .mcp-item { margin-top:0; }
        @container (max-width: 520px) {
            #moaRejectCompliancePanel .mcp-grid.cols-3 { grid-template-columns:repeat(2,minmax(0,1fr)); }
        }
        @container (max-width: 340px) {
            #moaRejectCompliancePanel .mcp-grid.cols-2,
            #moaRejectCompliancePanel .mcp-grid.cols-3 { grid-template-columns:minmax(0,1fr); }
        }
        /* ── NEW (layout adjustment) — the compact "Revision complied" note on the MOA Workflow tab (.moa-complied-note) uses the
           same rows + item look as the Review MOA panel above. .mcp-rows is the size container (not the note itself, so the
           note's own box and its parent layout are left exactly as they were). Same breakpoints: 3 columns -> 2 when narrow,
           everything -> 1 when very narrow. ── */
        .moa-complied-note .mcp-rows { container-type:inline-size; }
        .moa-complied-note .mcp-grid { display:grid; grid-template-columns:minmax(0,1fr); gap:6px 10px; margin-top:6px; }
        .moa-complied-note .mcp-grid:first-child { margin-top:2px; }
        .moa-complied-note .mcp-grid.cols-2 { grid-template-columns:repeat(2,minmax(0,1fr)); }
        .moa-complied-note .mcp-grid.cols-3 { grid-template-columns:repeat(3,minmax(0,1fr)); }
        .moa-complied-note .mcp-item { padding:7px 10px; background:#ffffff; border:1px solid #9DC08F; border-radius:0; }
        .moa-complied-note .mcp-label { font-weight:700; font-size:12px; color:#2C5A2C; }
        .moa-complied-note .mcp-value { margin-top:2px; max-height:72px; overflow-y:auto; overflow-wrap:anywhere; color:#0F1A33; font-size:13px; line-height:1.4; }
        @container (max-width: 520px) {
            .moa-complied-note .mcp-grid.cols-3 { grid-template-columns:repeat(2,minmax(0,1fr)); }
        }
        @container (max-width: 340px) {
            .moa-complied-note .mcp-grid.cols-2,
            .moa-complied-note .mcp-grid.cols-3 { grid-template-columns:minmax(0,1fr); }
        }

        /* ── NEW (layout adjustment) — the "Flag the section(s) that need correction" checklist in the Review MOA modal follows
           the same rows too. It becomes a 6-column grid so a 3-per-row, 2-per-row and full-width row all line up on the same
           column lines; each item just says how many columns it spans (flag-c3 = a third, flag-c2 = a half, flag-c1 = full
           width). flag-start makes Company Name always begin a new row, so the groups never run into each other when the
           columns collapse. The checklist is the size container: 3 per row -> 2 per row when narrow, all full-width when
           very narrow (browsers without container queries keep the 3-column rows). ── */
        #moaRejectFlagsGrid { grid-template-columns:repeat(6,minmax(0,1fr)); container-type:inline-size; }
        #moaRejectFlagsGrid .flag-c3 { grid-column:span 2; }
        #moaRejectFlagsGrid .flag-c2 { grid-column:span 3; }
        #moaRejectFlagsGrid .flag-c2.flag-start { grid-column:1 / span 3; }
        #moaRejectFlagsGrid .flag-c1 { grid-column:1 / -1; }
        @container (max-width: 520px) {
            #moaRejectFlagsGrid .flag-c3 { grid-column:span 3; }
        }
        @container (max-width: 340px) {
            #moaRejectFlagsGrid .flag-c3,
            #moaRejectFlagsGrid .flag-c2,
            #moaRejectFlagsGrid .flag-c2.flag-start { grid-column:1 / -1; }
        }

        /* ── NEW (this adjustment) — Review MOA "load page". The same spinner + "LOADING…" look as the site's page loader
           (#globalLoadingOverlay, whose .global-loading-* classes it reuses), but scoped to the Review MOA modal's body so it
           covers the MOA preview AND the side panel while the MOA is still loading, and leaves the modal's top bar (and its
           Close button) usable. Opaque, so half-loaded content never shows through. Hidden by cvMoaLoadFinish(). ── */
        #moaRejectModalBody { position:relative; }
        #moaRejectLoadingPage { position:absolute; inset:0; z-index:10; display:flex; align-items:center; justify-content:center; background:#EEF1F6; opacity:1; visibility:visible; transition:opacity 0.35s ease, visibility 0.35s ease; }
        #moaRejectLoadingPage.hidden { opacity:0; visibility:hidden; pointer-events:none; }

        /* ── NEW (this adjustment) — a section checkbox that is TEMPORARILY DISABLED in the Review MOA checklist (the section is
           still awaiting the company's compliance, or is empty). Looks unavailable, doesn't react to hover, and explains why in
           its tooltip. Lifted automatically (cvResetMoaCompliance()) as soon as the section can be flagged again. ── */
        .moa-reject-flag-item.locked { opacity:0.55; cursor:not-allowed; }
        .moa-reject-flag-item.locked:hover { background:#E4EAF4; border-color:#A3AFC7; }
        .moa-reject-flag-item.locked input[type="checkbox"] { cursor:not-allowed; }

        /* ── NEW (this adjustment) — "Approve MOA" in the Review MOA preview while the MOA still has flagged section(s) waiting on
           the company: looks unavailable (same treatment as the locked Done button) and explains itself when clicked. ── */
        .moa-action-btn.moa-approve-locked, .moa-action-btn.moa-approve-locked:hover { opacity:0.4; cursor:not-allowed; transform:none; }

        /* ── NEW (this adjustment) — the undo toast now also appears for MOA processes. While it belongs to one it sits above
           the modals (the Review MOA preview, the confirmation popups), so "Undo" can always be reached. ── */
        #undoToast.moa-kind { z-index:10080; }
        .moa-reject-flag-item.complied { background:#D9E8D2; border-color:#4A7A3A; }
        .moa-reject-flag-item .complied-tag { margin-left:auto; font-size:10px; font-weight:700; background:#2C5A2C; color:#ffffff; padding:1px 7px; border-radius:0; white-space:nowrap; }
        /* ══════════════════════════════════════════════════════════════════════════════════════════
           NEW (this adjustment) — OPTION 4: "FIELD OPS GRID" (company_list_style_previews.html).
           DESIGN ONLY. The palette tokens in :root above, the colour / square-corner pass over every
           rule in this stylesheet and over the inline styles, plus the rules below (which sit LAST so
           they win): navy + slate grid lines, square corners, small UPPERCASE buttons, plain coloured
           status text, and the
           toasts / validation popup restyled to match. No markup hook, id, class, data-attribute,
           function or behaviour that the JavaScript depends on has been changed.
           ══════════════════════════════════════════════════════════════════════════════════════════ */

        /* — filter bars — */
        .filter-nav, .table-search-bar { background:#ffffff; border:1px solid var(--grid-border); border-radius:0; box-shadow:none; }
        .search-bar, .filter-item { background:#ffffff; border:1px solid var(--grid-border); border-radius:0; font-size:12px; color:var(--neust-maroon); }
        .search-bar:focus, .filter-item:focus { outline:none; border-color:var(--neust-maroon); }

        /* — section titles — */
        .request-type-section-title { font-size:12px; font-weight:600; letter-spacing:0.6px; text-transform:uppercase; border-bottom:1px solid var(--grid-border); }
        .request-type-count-badge { background:#ffffff; border:1px solid var(--grid-border); color:var(--neust-maroon); font-size:11px; font-weight:600; padding:1px 9px; letter-spacing:0; }
        h4 { font-size:12px; font-weight:600; letter-spacing:0.6px; text-transform:uppercase; border-bottom:1px solid var(--grid-border); }

        /* — the two company tables: square grid, 1px slate rules, a status-coloured bar on the left of every row — */
        .company-table-header { font-size:11px; font-weight:600; letter-spacing:0.6px; }
        .company-list-wrapper { background:#ffffff; border:1px solid var(--grid-border); box-shadow:none; }
        .company-row { background:#ffffff; }
        .row-summary { font-size:13px; }
        .row-summary span:first-child { font-weight:500; }
        @keyframes rowJustArrivedGlow {
            0%, 60% { background:#D6DEEE; }
            100%    { background:transparent; }
        }

        /* — buttons: small, square, UPPERCASE with tracking; primary = solid navy, secondary = white with a slate border, danger = white with red text — */
        .archive-btn, .moa-wf-btn, .moa-action-btn, .arch-modal-cancel, .arch-modal-export, .arch-modal-confirm,
        .nb-ok, .nb-cancel, .nb-go, .mcs-btn, .blob-modal-bar-btn, #coArchiveExportBtn, #coArchiveUnarchiveBtn,
        .update-form button, .cv-gallery .update-form button {
            border-radius:0; text-transform:uppercase; letter-spacing:0.4px; font-size:12px;
        }
        .moa-action-btn.details-btn, .arch-modal-cancel, .nb-cancel, .mcs-btn.mcs-cancel, .mcs-btn.mcs-back {
            background:#ffffff; color:var(--neust-maroon); border:1px solid var(--grid-border);
        }
        .moa-action-btn.reject-btn { background:#ffffff; color:var(--grid-bad); border:1px solid var(--grid-border); }
        .moa-action-btn.reject-btn:hover, .moa-action-btn.details-btn:hover { background:var(--bg); opacity:1; }
        .nb-ok { color:#ffffff; }

        /* — requirement cards: 1px slate border — */
        .cv-tab { font-size:12px; font-weight:600; letter-spacing:0.4px; text-transform:uppercase; }
        .cv-tab.active { border-bottom-color:var(--neust-maroon); }
        .cv-tab-count, .cv-tab-chip { text-transform:none; letter-spacing:0; }

        /* — MOA card / stepper — */
        .req-item.moa-req-item { border:1px solid var(--grid-border); background:#ffffff; }
        .moa-tbl-step.done:not(:last-child)::after, .moa-tbl-step.active:not(:last-child)::after { background:#66718D; }

        /* — SIGNING-SCHEDULE PANEL (adjustment 1 + option 4). The rounded check icon in front of the confirmed sentence is gone (see
             the panel markup), so that sentence is now plain text: display:block makes it follow the panel's own text-align, exactly like the
             date line under it — centred in the top-right layout, left-aligned in the narrow single-column layout. — */
        .moa-sched-panel { background:var(--bg); border:1px solid var(--grid-border); }
        .moa-sched-panel-header.confirmed { display:block; line-height:1.4; text-wrap:balance; }
        .moa-sched-panel-hint { color:var(--grid-ink-2); }

        /* — modals / popups — */
        .arch-modal-box, .co-notif-box, #coArchiveViewerBox, #moaPreviewBox, #moaCustomScheduleBox { border:1px solid var(--grid-border); border-top:4px solid var(--neust-maroon); box-shadow:0 12px 32px rgba(27,42,74,0.25); }
        .arch-modal-title, .co-notif-box .nb-title { text-transform:uppercase; letter-spacing:0.6px; font-size:15px; }
        #coArchiveFabBtn { box-shadow:0 6px 16px rgba(27,42,74,0.35); }
        #coArchiveFabLabel { text-transform:uppercase; letter-spacing:0.4px; font-size:10px; }

        /* — TOASTS. (1) the requirement-validation toast ("Status updated … Undo"); (2) the notification toasts that pop up at the top
             (.cv-top-toast) and the "company completed / resubmitted" ones (.moa-compliance-toast). Navy bar, square, plain slate frame, small tracked label — the
             same grammar as the rest of the page. The denied state still works: the script sets an inline
             border-color on #undoToast, which recolours the whole frame red. Positions / animations / sizes are untouched. — */
        #undoToast { background:var(--neust-maroon); color:#ffffff; border:1px solid #55668C; border-radius:0; box-shadow:0 8px 24px rgba(27,42,74,0.30); }
        #undoToast .toast-label strong { font-size:10.5px; font-weight:600; letter-spacing:0.6px; text-transform:uppercase; color:var(--grid-border); }
        #undoToast .toast-label span { font-size:13px; font-weight:600; color:#ffffff; }
        #undoToast .undo-btn { background:#ffffff; color:var(--neust-maroon); border-radius:0; font-size:12px; letter-spacing:0.4px; text-transform:uppercase; }
        #undoToast .dismiss-btn { color:#A3AFC7; }
        #undoToast .dismiss-btn:hover { color:#ffffff; }
        .cv-top-toast, .moa-compliance-toast { background:var(--neust-maroon); color:#E3E8F1; border:1px solid #55668C; border-radius:0; box-shadow:0 8px 24px rgba(27,42,74,0.30); font-size:12.5px; }
        .cv-top-toast i, .moa-compliance-toast i { color:#8FD18F; }
        .cv-top-toast strong, .moa-compliance-toast strong { color:#ffffff; font-weight:700; }
        /* ══════════════════════════════════════════════════════════════════════════════════════════
           NEW (this adjustment) — "LESS PALE": a contrast pass over the Option 4 design. DESIGN ONLY.
           The palette above was deepened (darker slate borders and secondary text, a deeper page
           background so the white panels stand out, stronger amber) and the rules below add weight:
           solid section titles, bolder company names, a subtle lift under the white panels, and the
           status in the tables drawn as a small tinted tag (it follows whatever colour the status
           cell has, so every existing live update keeps working). No layout, markup or behaviour
           changes; the top bar and side menu are untouched.
           ══════════════════════════════════════════════════════════════════════════════════════════ */
        .company-table-header { color:#ffffff; }
        .filter-nav, .table-search-bar, .company-list-wrapper, .req-item.moa-req-item, .cv-gallery .req-item { box-shadow:0 1px 3px rgba(27,42,74,0.16); }
        .request-type-section-title { font-size:13px; font-weight:700; border-bottom:2px solid var(--neust-maroon); }
        .request-type-count-badge { background:var(--neust-maroon); border-color:var(--neust-maroon); color:#ffffff; }
        .search-bar, .filter-item { font-size:13px; }
        .search-bar::placeholder { color:#66718D; opacity:1; }
        .row-summary { font-size:13.5px; }
        .row-summary span:first-child { font-weight:600; }
        .overall-status-cell:not([data-vpct]) { display:inline-block; padding:4px 12px; font-size:12px; background:color-mix(in srgb, currentColor 12%, #ffffff); border:1px solid color-mix(in srgb, currentColor 40%, #ffffff); }
        .cv-tab.active { background:#ffffff; }
        .cv-tab-count { background:var(--neust-maroon); color:#ffffff; }
        .moa-tbl-dot { border-color:#8593B0; color:#3E4963; }
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
        will-change: transform;   /* FIX: the ring keeps turning on the compositor while this large page is busy loading (no pause) — same as administrator.php */
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

    /* NEW (loader sync fix): 'gl-instant' (no fade-in) is only for the moment of leaving; once the overlay is hidden
       again (navigation cancelled / a download) it is dropped, so later showings fade in as before — same as administrator.php. */
    document.addEventListener('DOMContentLoaded', function () {
        try {
            var ovw = overlay();
            if (!ovw || !window.MutationObserver) return;
            new MutationObserver(function () {
                if (ovw.classList.contains('hidden') && ovw.classList.contains('gl-instant')) ovw.classList.remove('gl-instant');
            }).observe(ovw, { attributes: true, attributeFilter: ['class'] });
        } catch (e) { /* never affects the page */ }
    });
    /* NEW (loader sync fix — same behaviour as administrator.php's "navigating" flag): once this page is on its way
       to another page (link click, form submit, reload), nothing may hide the loading page again until the next page
       has taken over. Before this, this page's own load handler / 4-second safety timer (and the "minimum time"
       logic) could hide it in the middle of a navigation started in the first seconds after opening the page, so the
       loading page vanished for a moment and then came back with the next page ("pauses, then continues"). A hide
       that happens while navigating is undone before the browser paints it. The flag releases itself after 7.5 s
       (navigation cancelled / a download), a moment before the existing 8 s release below. */
    var navActive = false, navTimer = null, navObs = null;
    function navAttach() {
        if (navObs || !window.MutationObserver) return;
        var ovn = overlay(); if (!ovn) return;
        navObs = new MutationObserver(function () {
            if (navActive && ovn.classList.contains('hidden')) { ovn.classList.add('gl-instant'); ovn.classList.remove('hidden'); }
        });
        navObs.observe(ovn, { attributes: true, attributeFilter: ['class'] });
    }
    function navStart() { try { navActive = true; navAttach(); clearTimeout(navTimer); navTimer = setTimeout(navEnd, 7500); } catch (e) {} }
    function navEnd() { navActive = false; clearTimeout(navTimer); }
    window.cvNavGate = { start: navStart, end: navEnd, active: function () { return navActive; } };
    window.addEventListener('pageshow', function (e) { if (e.persisted) navEnd(); });

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
        navStart();                                                                 // NEW (loader sync fix): leaving — keep the loading page up
        var ov = overlay(); if (!ov || !ov.classList.contains('hidden')) return;
        var l = label(); if (l) l.textContent = 'Loading';
        ov.classList.add('gl-instant');   // NEW (loader sync fix): appears on the very next paint, like administrator.php
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
     NEW (this adjustment): global loading/processing popup — same
     markup as admin_company_list.php's #globalLoadingOverlay. Visible by
     default (so it covers the page while assets are still loading), then
     hidden by JS once the window finishes loading. Also reused (shown/
     hidden) around the schedule-setting/Approve/Flag/Done/Accept-Proposed
     actions further down, so the admin always sees a clear "processing"
     indicator that disappears automatically once the action completes.
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
        if (window.cvNavGate) window.cvNavGate.start();   // NEW (loader sync fix): nothing may hide the loading page while leaving
        navWasHidden = ov.classList.contains('hidden');
        if (navWasHidden) {
            if (label) { navPrevLabel = label.textContent; label.textContent = 'Loading'; }
            ov.classList.add('gl-instant');   // NEW (loader sync fix): no fade-in while leaving
            setHidden(false);
        }
        clearTimeout(navTimer);
        navTimer = setTimeout(navReset, NAV_STUCK_MS);
    }
    function navReset() {
        clearTimeout(navTimer);
        if (!navShown) return;
        navShown = false;
        if (window.cvNavGate) window.cvNavGate.end();
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

<!-- GUARD MODAL (also used for the "MOA Accepted" success notification)
     ── FIX (this adjustment): z-index raised from 10050 to 10070 — it was
     sitting BEHIND #moaCustomScheduleOverlay (z-index:10060), so the
     "Date Required" warning (and any other guard message triggered while
     the Set Signing Schedule modal is open) rendered invisibly behind it
     instead of on top. This modal is meant to always be the topmost
     layer on the page regardless of what else is open, so its z-index is
     now higher than every other stacking context in this file. -->
<div id="guardModal" style="display:none;position:fixed;top:0;left:0;width:100%;height:100%;background:rgba(0,0,0,0.5);z-index:10070;justify-content:center;align-items:center;">
    <div id="guardModalBox" style="background:#fff;border:1px solid #A3AFC7;border-top:4px solid #1B2A4A;border-radius:0;padding:32px 32px 28px;width:420px;box-shadow:0 12px 32px rgba(27,42,74,0.25);text-align:center;">
        <div id="guardModalIcon" style="font-size:48px;margin-bottom:16px;"></div>
        <h3 id="guardModalTitle" style="margin:0 0 10px;font-size:15px;text-transform:uppercase;letter-spacing:0.6px;color:#1B2A4A;"></h3>
        <p id="guardModalMsg" style="color:#3E4963;font-size:13px;margin:0 0 24px;line-height:1.6;"></p>
        <label id="guardModalDontShowWrap" class="guard-modal-dontshow">
            <input type="checkbox" id="guardModalDontShowCheckbox">
            Don't show this notification again today
        </label>
        <button onclick="closeGuardModal()" style="background:var(--neust-maroon);color:#fff;border:none;padding:10px 28px;border-radius:0;font-weight:700;font-size:12px;text-transform:uppercase;letter-spacing:0.4px;cursor:pointer;">OK, Got it</button>
    </div>
</div>

<!-- ARCHIVE MODAL -->
<div class="arch-modal-overlay" id="coArchiveModal">
    <div class="arch-modal-box">
        <p class="arch-modal-title">Archive Company Batch</p>
        <p class="arch-modal-msg">This will archive <strong id="coArchiveCount">0</strong> company records and clear the dashboard for a new batch. This action <strong>cannot be undone</strong>.</p>
        <label class="arch-modal-label" for="coBatchLabelInput">Batch Name / Label</label>
        <input type="text" class="arch-modal-input" id="coBatchLabelInput" placeholder="e.g. Company Batch 2025 — 1st Semester">
        <div class="arch-modal-actions">
            <button class="arch-modal-cancel" id="coArchCancelBtn">Cancel</button>
            <button class="arch-modal-export" id="coArchExportBtn"><i class="fas fa-file-excel"></i> Export Excel</button>
            <button class="arch-modal-confirm" id="coArchConfirmBtn"><i class="fas fa-box-archive"></i> Archive Batch</button>
        </div>
        <div class="arch-progress" id="coArchProgress">Archiving batch, please wait…</div>
    </div>
</div>

<div id="coArchiveFab">
    <button id="coArchiveFabBtn" onclick="openCoArchiveViewer()" title="View Archived Company Batches"><i class="fas fa-box-archive"></i></button>
    <span id="coArchiveFabLabel">View Archive</span>
</div>

<div id="coArchiveViewerOverlay">
    <div id="coArchiveViewerBox">
        <div id="coArchiveViewerHeader">
            <h3><i class="fas fa-box-archive" style="color:#1B2A4A;"></i> Archived Company Batches</h3>
            <button id="coArchiveViewerClose" onclick="closeCoArchiveViewer()">×</button>
        </div>
        <div id="coArchiveViewerControls">
            <select id="coArchiveBatchFilter" onchange="filterCoArchive()"><option value="">All Batches</option></select>
            <input type="text" id="coArchiveSearchInput" placeholder="Search…" oninput="filterCoArchive()">
            <button id="coArchiveExportBtn" onclick="exportFilteredCoArchive()"><i class="fas fa-file-excel"></i> Export Filtered</button>
            <button id="coArchiveUnarchiveBtn" disabled><i class="fas fa-box-open"></i> Unarchive Batch</button>
        </div>
        <div id="coArchiveViewerBody">
            <div id="coArchiveEmpty" style="display:none;"><i class="fas fa-box-open" style="font-size:40px;margin-bottom:12px;display:block;color:#66718D;"></i>No archived company records found.</div>
            <table id="coArchiveTable" style="display:none;"><thead><tr><th>Batch</th><th>Company</th><th>Type</th><th>Representative</th><th>Validation</th><th>Archived</th><th style="text-align:center;">Action</th></tr></thead><tbody id="coArchiveTableBody"></tbody></table>
        </div>
    </div>
</div>

<div id="coBlockNotifOverlay"><div class="co-notif-box"><p class="nb-title" style="color:#8C6C00;">Cannot Unarchive Yet</p><p class="nb-msg" id="coBlockNotifMsg">There are currently active companies in the dashboard.</p><button class="nb-ok" onclick="document.getElementById('coBlockNotifOverlay').style.display='none'">OK, Got it</button></div></div>
<div id="coUnarchiveConfirmOverlay"><div class="co-notif-box"><p class="nb-title" style="color:#1B2A4A;">Unarchive This Batch?</p><p class="nb-msg" id="coUnarchiveConfirmMsg">This will restore all companies from this batch.</p><div class="nb-actions"><button class="nb-cancel" onclick="document.getElementById('coUnarchiveConfirmOverlay').style.display='none'">Cancel</button><button class="nb-go" id="coUnarchiveConfirmGo"><i class="fas fa-box-open"></i> Yes, Unarchive</button></div></div></div>

<!-- MOA BLOB PREVIEW -->
<div id="moaBlobModal">
    <div id="moaBlobModalBar">
        <div id="moaBlobModalTitle"><i class="fas fa-file-signature"></i><span>MOA Document — </span><span class="blob-company-name" id="moaBlobCompanyName"></span></div>
        <div class="blob-modal-bar-actions">
            <a id="moaBlobDownloadBtn" href="#" class="blob-modal-bar-btn download" download target="_blank"><i class="fas fa-download"></i> Download</a>
            <button class="blob-modal-bar-btn close-btn" onclick="closeMoaBlobModal()"><i class="fas fa-times"></i> Close</button>
        </div>
    </div>
    <div id="moaBlobModalContent">
        <div id="moaBlobModalLoader"><i class="fas fa-spinner fa-spin"></i>Loading document…</div>
        <iframe id="moaBlobPdfFrame" title="MOA PDF Preview"></iframe>
        <div id="moaBlobImgWrap"><img id="moaBlobImg" alt="MOA Document"></div>
        <div id="moaBlobNoFile"><i class="fas fa-file-slash"></i>No document uploaded for this MOA request.</div>
    </div>
</div>

<!-- MOA DETAILS MODAL -->
<div id="moaPreviewModal">
    <div id="moaPreviewBox">
        <div id="moaPreviewHeader"><h3><i class="fas fa-file-signature"></i> MOA Request Details</h3><button id="moaPreviewClose" onclick="closeMoaPreview()">×</button></div>
        <div id="moaPreviewBody"><div id="moaPreviewContent"></div><div class="moa-preview-actions" id="moaPreviewActions"></div></div>
    </div>
</div>

<!-- REQ BLOB MODAL -->
<div id="reqBlobModal">
    <div style="width:100%;background:#1B2A4A;padding:12px 24px;display:flex;align-items:center;justify-content:space-between;gap:16px;box-shadow:0 2px 12px rgba(0,0,0,0.4);flex-shrink:0;">
        <div style="color:white;font-size:14px;font-weight:700;display:flex;align-items:center;gap:10px;flex:1;overflow:hidden;white-space:nowrap;text-overflow:ellipsis;">
            <i class="fas fa-file-signature" style="color:var(--neust-gold);flex-shrink:0;"></i>
            <span id="reqBlobModalTitle">MOA Document</span>
        </div>
        <div style="display:flex;gap:10px;flex-shrink:0;">
            <a id="reqBlobDownloadBtn" href="#" class="blob-modal-bar-btn download" target="_blank" style="display:inline-flex;align-items:center;gap:6px;padding:8px 16px;border-radius:0;font-size:12px;font-weight:700;background:#1B2A4A;color:white;text-decoration:none;"><i class="fas fa-download"></i> Download</a>
            <button class="blob-modal-bar-btn close-btn" onclick="closeReqBlobModal()" style="display:inline-flex;align-items:center;gap:6px;padding:8px 16px;border-radius:0;font-size:12px;font-weight:700;background:#A02A2A;color:white;border:none;cursor:pointer;"><i class="fas fa-times"></i> Close</button>
        </div>
    </div>
    <div style="flex:1;width:100%;display:flex;align-items:center;justify-content:center;overflow:hidden;position:relative;">
        <div id="reqBlobLoader" style="position:absolute;inset:0;display:flex;flex-direction:column;align-items:center;justify-content:center;color:rgba(255,255,255,0.7);font-size:14px;gap:16px;background:rgba(0,0,0,0.4);z-index:1;"><i class="fas fa-spinner fa-spin" style="font-size:36px;color:var(--neust-gold);"></i>Loading document…</div>
        <iframe id="reqBlobPdfFrame" title="MOA Document Preview" style="display:none;width:100%;height:100%;border:none;background:white;"></iframe>
    </div>
</div>

<!-- ══ SET SIGNING SCHEDULE MODAL — calendar-grid Date step, digital
     roll-picker Time step ══
     Two-step flow: Step 1 = pick the Date (a calendar grid — never a past
     date), Step 2 = pick the Time (a digital roll picker — Hour / Minute /
     AM-PM columns, no typing). Cancel is always available; Back returns
     from Time → Date; the final step's primary button becomes "Confirm
     Schedule", which submits the chosen date/time directly (see
     mcsNextStep()/advanceMoaReqStage() below) — this modal is now the
     sole way "Set Signing Schedule" and "Re-Schedule" work; the old
     inline quick-pick chips and custom date/time row are gone entirely,
     not just replaced for one option. -->
<div id="moaCustomScheduleOverlay">
    <div id="moaCustomScheduleBox">
        <div class="mcs-header">
            <h3><i class="fas fa-calendar-alt"></i> Set Signing Schedule</h3>
            <button class="mcs-close-btn" onclick="closeMoaCustomScheduleModal()">×</button>
        </div>

        <div class="mcs-steps-row">
            <div class="mcs-step-dot" id="mcsStepDot1">1</div>
            <div class="mcs-step-line" id="mcsStepLine"></div>
            <div class="mcs-step-dot" id="mcsStepDot2">2</div>
        </div>
        <div class="mcs-labels-row">
            <span id="mcsStepLabel1">Date</span>
            <span id="mcsStepLabel2">Time</span>
        </div>

        <div class="mcs-body">
            <!-- Step 1: Date -->
            <div id="mcsStepDate">
                <div class="mcs-field-label"><i class="fas fa-calendar-day"></i> Select Signing Date</div>
                <!-- ── UPDATED (this adjustment): the plain date input is
                     replaced with an inline calendar-grid display — clicking
                     a day cell selects it directly instead of typing/opening
                     the browser's own native date picker. The original
                     <input type="date"> is kept, just hidden, as the single
                     source of truth for the selected value: everything else
                     in this file (min-date guard, mcsUpdateSummary(),
                     mcsNextStep()'s validation/read, the change listener)
                     still reads/writes it exactly as before, completely
                     unchanged — mcsSelectCalendarDay() below just sets its
                     value and fires a change event, same as a native picker
                     would. -->
                <div class="mcs-calendar" id="mcsCalendar">
                    <div class="mcs-calendar-header">
                        <button type="button" class="mcs-calendar-nav" id="mcsCalPrevBtn" onclick="mcsCalendarChangeMonth(-1)" aria-label="Previous month"><i class="fas fa-chevron-left"></i></button>
                        <span class="mcs-calendar-month-label" id="mcsCalMonthLabel"></span>
                        <button type="button" class="mcs-calendar-nav" id="mcsCalNextBtn" onclick="mcsCalendarChangeMonth(1)" aria-label="Next month"><i class="fas fa-chevron-right"></i></button>
                    </div>
                    <div class="mcs-calendar-weekdays">
                        <span>Su</span><span>Mo</span><span>Tu</span><span>We</span><span>Th</span><span>Fr</span><span>Sa</span>
                    </div>
                    <div class="mcs-calendar-days" id="mcsCalendarDays"></div>
                </div>
                <input type="date" id="mcsDateInput" class="mcs-input" style="display:none;">
                <div class="mcs-summary-line" id="mcsDateSummaryLine"><i class="fas fa-check-circle"></i><span id="mcsDateSummaryText"></span></div>
                <p class="mcs-hint">Choose the official date the company representative should come in to sign the MOA.</p>
            </div>
            <!-- Step 2: Time -->
            <div id="mcsStepTime" style="display:none;">
                <div class="mcs-field-label"><i class="fas fa-clock"></i> Select Signing Time</div>
                <!-- ── UPDATED (this adjustment): the reliance on the
                     browser's own native time-picker dropdown (via
                     showPicker(), from an earlier revision) is replaced
                     with a fully custom, digital "roll picker" built right
                     into the modal — three scrollable columns (Hour /
                     Minute / AM-PM), matching the calendar grid's design
                     language from the Date step. Clicking (or scrolling to
                     and clicking) an item selects it; typing is not
                     possible at all now, on any browser, since there's no
                     native input in view any more. The original
                     <input type="time"> is kept, just hidden, as the single
                     source of truth for the actual value — exactly the
                     same pattern the calendar grid already uses for the
                     Date step — so mcsUpdateSummary(), mcsNextStep()'s
                     validation/read, and the change listener all keep
                     working completely unchanged. -->
                <div class="mcs-time-roll" id="mcsTimeRoll">
                    <div class="mcs-time-roll-highlight" aria-hidden="true"></div>
                    <div class="mcs-time-roll-col" id="mcsTimeRollHour"></div>
                    <div class="mcs-time-roll-sep">:</div>
                    <div class="mcs-time-roll-col" id="mcsTimeRollMinute"></div>
                    <div class="mcs-time-roll-col mcs-time-roll-meridiem" id="mcsTimeRollMeridiem"></div>
                </div>
                <input type="time" id="mcsTimeInput" class="mcs-input" style="display:none;">
                <div class="mcs-summary-line" id="mcsSummaryLine"><i class="fas fa-check-circle"></i><span id="mcsSummaryText"></span></div>
            </div>
        </div>

        <div class="mcs-actions">
            <button type="button" class="mcs-btn mcs-cancel" id="mcsCancelBtn" onclick="closeMoaCustomScheduleModal()">Cancel</button>
            <button type="button" class="mcs-btn mcs-back" id="mcsBackBtn" onclick="mcsGoToStep(1)"><i class="fas fa-arrow-left"></i> Back</button>
            <button type="button" class="mcs-btn mcs-next" id="mcsNextBtn" onclick="mcsNextStep()">Next <i class="fas fa-arrow-right"></i></button>
        </div>
    </div>
</div>

<!-- ══ MOA REVIEW / FLAG MODAL — Preview (left) + Flags/Comment/Send (right) ══
     ── UPDATED (this adjustment): also opened via the "Review MOA" button
     on a company's row in the New MOA table (merged "Pending for Review"
     stage) — see openMoaTableFlagModal(). In that context this modal now
     shows an additional "Approve MOA" action alongside the flag/reject
     one, so the admin can review the document and then either approve it
     or flag it for revision without leaving this single preview. It is
     also still opened via the "Reject" button on a pending MOA card, or
     the "Reject" button inside the MOA Details modal (legacy inbox flow,
     unchanged). Shows the same PDF/image preview used by "View MOA", a
     checklist of which section(s) of the submission are erroneous, and a
     message box. Sending the message emails the company (with the MOA
     file attached and the flagged sections listed) and, once that email
     is confirmed sent, marks the request as Rejected for revision so the
     company can edit the flagged field(s) and resubmit (via
     moa_request.php's own "Revise" flow). -->
<div id="moaRejectModal">
    <div id="moaRejectModalBar">
        <div id="moaRejectModalTitle"><i class="fas fa-times-circle" id="moaRejectModalTitleIcon"></i><span id="moaRejectModalTitlePrefix">Reject MOA — </span><span class="reject-company-name" id="moaRejectCompanyName"></span></div>
        <button class="blob-modal-bar-btn close-btn" onclick="closeMoaRejectModal()"><i class="fas fa-times"></i> Close</button>
    </div>
    <div id="moaRejectModalBody">
        <!-- NEW (this adjustment): the Review MOA "load page" — shown while the MOA is still loading (the document, the contact
             details and the revision details), then hidden by cvMoaLoadFinish(). Same look as the site's page loader. -->
        <div id="moaRejectLoadingPage" class="hidden" role="status" aria-live="polite">
            <div class="global-loading-box">
                <div class="global-loading-spinner"></div>
                <div class="global-loading-text">
                    <span id="moaRejectLoadingLabel">Loading MOA</span>
                    <span class="global-loading-dots"><span>.</span><span>.</span><span>.</span></span>
                </div>
            </div>
        </div>
        <div id="moaRejectPreviewPane">
            <div id="moaRejectModalLoader"><i class="fas fa-spinner fa-spin"></i>Loading document…</div>
            <iframe id="moaRejectPdfFrame" title="MOA PDF Preview"></iframe>
            <div id="moaRejectImgWrap"><img id="moaRejectImg" alt="MOA Document"></div>
            <div id="moaRejectNoFile"><i class="fas fa-file-slash"></i>No document uploaded for this MOA request.</div>
        </div>
        <div id="moaRejectCommentPane">
            <div class="moa-reject-info">
                <!-- UPDATED (this adjustment): every row now carries a label (same element IDs, so all the JS that fills them is unchanged) -->
                <p><i class="fas fa-building"></i> <span class="moa-reject-info-label">Company</span> <strong id="moaRejectInfoCompany" class="moa-reject-info-value"></strong></p>
                <p><i class="fas fa-user"></i> <span class="moa-reject-info-label">Representative</span> <span id="moaRejectInfoContact" class="moa-reject-info-value"></span></p>
                <p><i class="fas fa-phone"></i> <span class="moa-reject-info-label">Telephone</span> <span id="moaRejectInfoTelephone" class="moa-reject-info-value">—</span></p>   <!-- NEW (this adjustment): the contact's telephone -->
                <p><i class="fas fa-envelope"></i> <span class="moa-reject-info-label">Email</span> <span id="moaRejectInfoEmail" class="moa-reject-info-value">—</span></p>
                <!-- NEW (this adjustment): Company Profile / Brief Description — moved here from the flag checklist below -->
                <p class="moa-reject-info-profile"><i class="fas fa-align-left"></i> <span class="moa-reject-info-label">Company Profile</span> <span id="moaRejectInfoProfile" class="moa-reject-info-value">—</span></p>
            </div>

            <!-- ── NEW (this adjustment): filled in by cvShowMoaCompliance() when this Review MOA preview is opened for a
                 company that has just complied with a "Flag for Revision" — lists the section(s) it updated, with their new
                 values, so the admin can go straight to them in the document on the left. Empty / hidden otherwise. -->
            <div id="moaRejectCompliancePanel"></div>

            <!-- ── NEW (this adjustment): only shown when this modal was opened
                 as the "Review MOA" preview (table mode) — approves the MOA
                 directly from here, no flags/comment required. -->
            <button class="moa-action-btn accept-btn" id="moaReviewApproveBtn" style="display:none;width:100%;justify-content:center;margin-bottom:16px;" onclick="approveMoaFromReviewModal()">
                <span class="moa-btn-spinner" id="moaReviewApproveSpinner"></span><i class="fas fa-check-circle"></i> Approve MOA
            </button>

            <label class="moa-reject-flags-label"><i class="fas fa-flag"></i> Flag the section(s) that need correction</label>
            <p class="moa-reject-flags-hint">Only the checked field(s) below are what the company will be able to edit when they resubmit — everything else stays as-is.</p>
            <!-- UPDATED (layout adjustment): same checkboxes (same values, same JS hooks), re-ordered into the SAME rows as the
                 company form and the "Revision complied" box above — Contact name(s) / Position / Telephone, then Company Name /
                 Company Address. The flag-c* classes only set how many columns each item spans (see the
                 #moaRejectFlagsGrid rules in the stylesheet).
                 UPDATED (this adjustment): "Company Profile" is no longer a flag area — it is not printed on the MOA any more,
                 so it is shown in the company details box above (below Email) instead. -->
            <div class="moa-reject-flags-grid" id="moaRejectFlagsGrid">
                <label class="moa-reject-flag-item flag-c3"><input type="checkbox" value="contact_first_name"> Contact First Name</label>
                <label class="moa-reject-flag-item flag-c3"><input type="checkbox" value="contact_middle_name"> Contact Middle Name</label>
                <label class="moa-reject-flag-item flag-c3"><input type="checkbox" value="contact_last_name"> Contact Last Name</label>
                <label class="moa-reject-flag-item flag-c3"><input type="checkbox" value="position"> Position</label>
                <label class="moa-reject-flag-item flag-c3"><input type="checkbox" value="telephone"> Telephone</label>
                <label class="moa-reject-flag-item flag-c2 flag-start"><input type="checkbox" value="company_name"> Company Name</label>
                <label class="moa-reject-flag-item flag-c2"><input type="checkbox" value="company_address"> Company Address</label>
            </div>

            <label class="moa-reject-comment-label"><i class="fas fa-comment-dots"></i> Message to Company (sent via email)</label>
            <textarea id="moaRejectComment" rows="5" placeholder="e.g. Thank you for your interest in partnering with us. A few details in your submission need correction before we can proceed — please see the flagged section(s) above and resubmit. We look forward to working with you."></textarea>
            <p class="moa-reject-hint"><i class="fas fa-info-circle"></i> The MOA document will be attached to this email. Once the email is sent successfully, the request will be marked as Rejected so the company can revise the flagged section(s) and resubmit.</p>
            <div class="moa-reject-actions">
                <button class="moa-action-btn details-btn" onclick="closeMoaRejectModal()"><i class="fas fa-times"></i> Cancel</button>
                <button class="moa-action-btn reject-btn" id="moaRejectSendBtn" onclick="sendMoaRejectionEmail()"><span class="moa-btn-spinner" id="moaRejectSpinner"></span><i class="fas fa-paper-plane"></i> Send &amp; Request Revision</button>
            </div>
        </div>
    </div>
</div>

<!-- UNDO TOAST -->
<div id="undoToast">
    <div class="countdown-ring">
        <svg width="36" height="36" viewBox="0 0 36 36"><circle cx="18" cy="18" r="14"/><circle class="progress" id="undoRingProgress" cx="18" cy="18" r="14"/></svg>
        <div class="num" id="undoCountNum">5:00</div>
    </div>
    <div class="toast-label"><strong id="undoToastHeading">Status updated</strong><span id="undoToastLabel">—</span></div>
    <button class="undo-btn" id="undoBtnMain" onclick="triggerUndo()">Undo</button>
    <button class="dismiss-btn" onclick="dismissUndo()" title="Dismiss">×</button>
</div>

<!-- MOA REQUESTS DRAWER
     ── UPDATED (this adjustment): no more Accept/Reject queue — every new
     MOA request is already auto-inserted into the "New MOA" table (see
     autoIngestPendingMoaRequests()), so this drawer now acts purely as a
     notification list. Each card is just "here's an update from X" with a
     single "View Request" button that jumps straight to that company's
     row in the table; once clicked, it's marked viewed and disappears
     from here (see ajax_moa_mark_notification_viewed /
     ajax_fetch_moa_notifications). The old Pending/Accepted/Rejected
     status filter dropdown was removed earlier (there's no such status
     here any more, only "seen vs. not yet seen") — a new filter dropdown
     is added back now, filtering by notification TYPE instead (new
     request / revision complied / schedule agreed / schedule proposed —
     see detectAndNotifyComplianceEvents()). -->
<div id="moaDrawerOverlay" onclick="closeMoaDrawer()"></div>
<div id="moaDrawer">
    <div class="moa-drawer-header">
        <h3><i class="fas fa-file-signature"></i> Notification Inbox <span class="moa-drawer-count" id="moaDrawerPendingCount">0 New</span></h3>
        <button class="moa-drawer-close" onclick="closeMoaDrawer()">×</button>
    </div>
    <div class="moa-drawer-controls">
        <input type="text" class="moa-drawer-search" id="moaDrawerSearch" placeholder="Search by company name…" oninput="filterMoaNotifications()">
        <!-- ── NEW (this adjustment): filters the inbox by notification
             type — "New Request" (a fresh/resubmitted MOA), "Revision
             Complied" (the company fixed a flagged section), "Schedule
             Agreed" (they confirmed a proposed signing date/time), or
             "Schedule Proposed" (they declined and suggested a different
             one instead). -->
        <select class="moa-drawer-filter" id="moaDrawerTypeFilter" onchange="filterMoaNotifications()">
            <option value="">All Types</option>
            <option value="new_request">New Request</option>
            <option value="revision_complied">Revision Complied</option>
            <option value="schedule_agreed">Schedule Agreed</option>
            <option value="schedule_declined">Schedule Proposed</option>
            <option value="requirement_uploaded">Requirement Uploaded</option>
        </select>
    </div>
    <div class="moa-drawer-body" id="moaDrawerBody">
        <div class="moa-drawer-loading"><i class="fas fa-spinner fa-spin" style="font-size:24px;margin-bottom:10px;display:block;color:#66718D;"></i>Loading notifications…</div>
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
            <i class="fas fa-user-check"></i><span class="link-text">Student Requirements</span>
            <?php if($app_request_count>0): ?><span class="sidebar-badge-app" id="sidebarAppBadge"><?= $app_request_count ?></span><?php else: ?><span class="sidebar-badge-app" id="sidebarAppBadge" style="display:none"><?= $app_request_count ?></span><?php endif; ?>
        </a>
        <!-- ══ MOA badge now lives on "Company Requirements" instead of its own sidebar entry ══ -->
        <a href="company_validation.php" class="active" style="position:relative;">
            <i class="fas fa-building"></i><span class="link-text">Company Requirements</span>
            <?php if($moa_pending_count>0): ?><span class="sidebar-badge-moa" id="sidebarMoaBadge"><?= $moa_pending_count ?></span><?php else: ?><span class="sidebar-badge-moa" id="sidebarMoaBadge" style="display:none"><?= $moa_pending_count ?></span><?php endif; ?>
        </a>
        <a href="monitoring.php" style="position:relative;"><i class="fas fa-users-cog"></i><span class="link-text">Manage Accounts</span><!-- NEW (this adjustment): Email Recovery Requests indicator — same badge look as the application-request badge --><span class="sidebar-badge-app sidebar-badge-recovery" id="sidebarRecoveryBadge"<?= $recovery_pending_count > 0 ? '' : ' style="display:none"' ?>><?= (int)$recovery_pending_count ?></span></a>
        <a href="admin_monitoring_dashboard.php">
            <i class="fas fa-chart-line"></i><span class="link-text">Monitoring Dashboard</span>
        </a>
        <a href="admin_final_grades.php"><i class="fas fa-graduation-cap"></i><span class="link-text">Final Grades</span></a>
        <a href="system_setting.php" ><i class="fas fa-gear"></i><span class="link-text">System Setting</span></a>
    </div>
    <div class="logout-link"><a href="admin_login.php?logout=1"><i class="fas fa-sign-out-alt"></i><span class="link-text">Logout</span></a></div>
</div>

<div class="main-content">
    <nav class="navbar">
        <div class="logo-section">
            <img src="logo.webp" class="university-logo">
            <div>
                <div style="font-weight:bold;font-size:16px;">NEUST Atate Campus</div>
                <div style="font-size:11px;color:var(--nav-gold);">Web-Based Smart OJT Monitoring and Supervision Analytics System</div>
            </div>
        </div>
        <div style="display:flex;align-items:center;gap:10px;">
            <button class="moa-request-btn moa-bell-btn" onclick="openMoaDrawer()" id="moaNavBtn" title="Notification Inbox" aria-label="Notification Inbox">
                <i class="fas fa-bell"></i>
                <?php if($moa_pending_count>0): ?><span class="moa-nav-badge" id="moaNavBadge"><?= $moa_pending_count ?></span><?php else: ?><span class="moa-nav-badge" id="moaNavBadge" style="display:none;"><?= $moa_pending_count ?></span><?php endif; ?>
            </button>
        </div>
    </nav>

    <div class="container">
        <div class="filter-nav">
            <!-- UPDATED (this adjustment): the single search box that used to sit here (and searched BOTH tables) is gone — each
                 table now has its OWN search bar, right under its title (see #searchInputExisting / #searchInputNew below). The
                 status filter and Archive Batch stay here, and the status filter still applies to both tables. -->
            <select class="filter-item" id="statusFilter" onchange="filterAll()">
                <option value="All">All Status</option>
                <option value="Verified">Verified</option>
                <option value="Pending">Pending</option>
                <option value="Denied">Denied</option>
            </select>
            <button class="archive-btn" onclick="openCoArchiveModal()" <?php if($totalCompanies===0):?>disabled<?php endif;?>>
                <i class="fas fa-box-archive"></i> Archive Batch
            </button>
        </div>

        <?php
        // ════════════════════════════════════════════════════════════════════
        // NEW (this adjustment) — TWO-TABLE SPLIT BY REQUEST TYPE
        // ────────────────────────────────────────────────────────────────────
        // Every active company is bucketed into "Existing" or "New" using
        // resolveCompanyRequestType() (see its docblock above the function for
        // the exact resolution order). Each bucket gets its own independent
        // table:
        //   - "Existing" — the SAME simpler, compliance-checklist-only table
        //     design this page always used before the in-table MOA workflow was
        //     introduced (no MOA section at all), reusing the classification-
        //     based requirement list (private vs public) unchanged.
        //   - "New" — the CURRENT table design (full MOA in-table workflow:
        //     stepper, scheduling, comments, etc.) PLUS that SAME compliance
        //     checklist now also surfaced and verifiable here, so admins
        //     aren't limited to only reviewing the MOA document for these
        //     companies.
        // Nothing about HOW a single row renders has changed — both tables
        // call the exact same renderCompanyValidationRow() helper defined
        // above; only the $clist passed in (and therefore which requirements
        // show) differs.
        //
        // FIX (this adjustment): both branches below now build $clistExisting
        // / $clistNew from the canonical $private_compliance_reqs /
        // $public_compliance_reqs arrays (defined once near the top of this
        // file) instead of locally hardcoded lists, so the checklist rendered
        // here — and the requirements actually required for "Verified" status
        // — always matches, item for item, what CompanyForm.php collects and
        // displays to the company (10 items for Private, 3 for Public).
        //
        // NEW (this further adjustment): a company in the "Existing" table
        // whose MOA request type is ACTUALLY "Existing" (checked strictly via
        // isCompanyRequestTypeExisting() — see its docblock for why the
        // default-to-'existing' bucketing fallback above must never be used
        // for this) also gets the additive "moa_existing_upload" item
        // appended to its own $clistExisting, mirroring exactly what
        // CompanyForm.php appends to that same company's compliance
        // checklist. This keeps the admin's per-row checklist, and therefore
        // recomputeCompanyValidationStatus()'s "must be Verified" logic,
        // fully in sync with what the company was actually asked to upload.
        // ════════════════════════════════════════════════════════════════════
        $existingCompanies = [];
        $newCompanies      = [];
        $companies->data_seek(0);
        while ($company = $companies->fetch_assoc()) {
            $company['_request_type'] = resolveCompanyRequestType($conn, $company['user_id']);
            if ($company['_request_type'] === 'new') {
                $newCompanies[] = $company;
            } else {
                $existingCompanies[] = $company;
            }
        }
        $existingCount = count($existingCompanies);
        $newCount      = count($newCompanies);
        ?>

        <?php if($totalCompanies === 0): ?>
        <div class="empty-state">
            <i class="fas fa-building"></i>
            <h3>No Companies Enrolled</h3>
            <p>The dashboard is clear. New companies will appear here once they register for the next batch.</p>
        </div>
        <?php else: ?>

        <!-- ═══════════════════════════════════════════════════════════════
             "EXISTING" MOA REQUEST TYPE — compliance-checklist-only table
             (matches this page's older, pre-MOA-workflow design exactly)
             ═══════════════════════════════════════════════════════════════ -->
        <h3 class="request-type-section-title">
            <i class="fas fa-folder-open"></i> Existing MOA &mdash; Compliance Requirements
            <span class="request-type-count-badge" id="existingCountBadge"><?= $existingCount ?></span>
        </h3>
        <?php if ($existingCount === 0): ?>
        <div class="empty-state" style="padding:30px;">
            <i class="fas fa-folder-open"></i>
            <h3>No Companies Here</h3>
            <p>Companies with an existing/already-signed MOA will appear in this table.</p>
        </div>
        <?php else: ?>
        <!-- NEW (this adjustment): this table's own search bar — filters only the "Existing MOA" companies (see filterAll()) -->
        <div class="table-search-bar">
            <input type="text" id="searchInputExisting" class="search-bar" placeholder="Search company name..." aria-label="Search Existing MOA companies" autocomplete="off" oninput="filterAll('existing')">
            <!-- NEW (this adjustment): company type filter for THIS table (matches the "Type" column — see filterAll()) -->
            <select class="filter-item" id="typeFilterExisting" aria-label="Filter Existing MOA companies by company type" onchange="filterAll('existing')">
                <option value="">All Types</option>
                <option value="private">Private</option>
                <option value="public">Public</option>
            </select>
        </div>
        <div class="company-list-wrapper" id="existingCompanyWrapper">
            <div class="company-table-header">
                <span>Company Name</span><span>Address</span><span>Type</span><span>Requirement Status</span>
            </div>
            <?php foreach ($existingCompanies as $company):
                $isPublicExisting = (strtolower($company['company_type'] ?? '') === 'public');
                $clistExisting = $isPublicExisting ? $public_compliance_reqs : $private_compliance_reqs;
                // NEW (this adjustment): append the additive "MOA Document
                // (Existing Partnership)" item ONLY for companies whose MOA
                // request type is actually recorded as "Existing" — see the
                // isCompanyRequestTypeExisting() docblock above for why this
                // must never rely on the table-bucketing default instead.
                if (isCompanyRequestTypeExisting($conn, $company['user_id'])) {
                    $clistExisting[] = 'moa_existing_upload';
                }
                renderCompanyValidationRow($conn, $company, $clistExisting, $companyReqLabels, $remarks);
            endforeach; ?>
        </div>
        <div style="display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:12px;margin-top:16px;padding:14px 18px;background:#fff;border:1px solid #A3AFC7;border-radius:0;box-shadow:none;">
            <span id="paginationInfoExisting" style="font-size:13px;color:#3E4963;"></span>
            <div id="paginationContainerExisting" style="display:flex;align-items:center;gap:5px;flex-wrap:wrap;"></div>
        </div>
        <?php endif; ?>

        <!-- ═══════════════════════════════════════════════════════════════
             "NEW" MOA REQUEST TYPE — MOA workflow + compliance requirements
             (this page's current design, now also verifying the other
             requirements alongside the MOA document)
             ═══════════════════════════════════════════════════════════════ -->
        <h3 class="request-type-section-title" style="margin-top:32px;">
            <i class="fas fa-file-signature"></i> New MOA &mdash; Requirements &amp; MOA Workflow
            <span class="request-type-count-badge" id="newCountBadge"><?= $newCount ?></span>
        </h3>
        <?php if ($newCount === 0): ?>
        <div class="empty-state" style="padding:30px;">
            <i class="fas fa-file-signature"></i>
            <h3>No Companies Here</h3>
            <p>Companies requesting a brand-new MOA will appear in this table.</p>
        </div>
        <?php else: ?>
        <!-- NEW (this adjustment): this table's own search bar — filters only the "New MOA" companies (see filterAll()) -->
        <div class="table-search-bar">
            <input type="text" id="searchInputNew" class="search-bar" placeholder="Search company name..." aria-label="Search New MOA companies" autocomplete="off" oninput="filterAll('new')">
            <!-- NEW (this adjustment): company type + MOA status filters for THIS table. The MOA status options are the exact labels the
                 "MOA Status" column shows — see filterAll(). (UPDATED (this adjustment): the plain "Pending" option was removed from this list;
                 such rows are still listed under "All MOA Status".) -->
            <select class="filter-item" id="typeFilterNew" aria-label="Filter New MOA companies by company type" onchange="filterAll('new')">
                <option value="">All Types</option>
                <option value="private">Private</option>
                <option value="public">Public</option>
            </select>
            <select class="filter-item" id="moaStatusFilterNew" aria-label="Filter New MOA companies by MOA status" onchange="filterAll('new')">
                <option value="">All MOA Status</option>
                <option value="Pending for Review">Pending for Review</option>
                <option value="MOA Revision Required">MOA Revision Required</option>
                <option value="MOA Approved">MOA Approved</option>
                <option value="Scheduling">Scheduling</option>
            </select>
        </div>
        <div class="company-list-wrapper" id="newCompanyWrapper">
            <div class="company-table-header cv-five-col">
                <span>Company Name</span><span>Address</span><span>Type</span><span>MOA Status</span><span>Requirement Status</span>
            </div>
            <?php foreach ($newCompanies as $company):
                $isPublicNew = (strtolower($company['company_type'] ?? '') === 'public');
                $complianceListNew = $isPublicNew ? $public_compliance_reqs : $private_compliance_reqs;
                $clistNew = array_merge(["moa_document"], $complianceListNew);
                renderCompanyValidationRow($conn, $company, $clistNew, $companyReqLabels, $remarks);
            endforeach; ?>
        </div>
        <div style="display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:12px;margin-top:16px;padding:14px 18px;background:#fff;border:1px solid #A3AFC7;border-radius:0;box-shadow:none;">
            <span id="paginationInfoNew" style="font-size:13px;color:#3E4963;"></span>
            <div id="paginationContainerNew" style="display:flex;align-items:center;gap:5px;flex-wrap:wrap;"></div>
        </div>
        <?php endif; ?>

        <?php endif; ?>
    </div>
</div>

<div id="imagePreviewModal">
    <span onclick="this.parentElement.style.display='none'" style="position:absolute;top:20px;right:40px;font-size:40px;color:white;cursor:pointer;">&times;</span>
    <img id="previewImage" style="max-width:90%;max-height:90%;">
</div>

<!-- ── NEW (this adjustment): REQUIREMENT DOCUMENT PREVIEW MODAL. Ported from CompanyForm.php's
     #reqDocPreviewModal: the same full-bleed dark viewer, opened from a Requirements-gallery card.
     A PDF renders in an iframe, an image in the image viewer, and when the requirement holds more
     than one file the prev / next buttons (or the arrow keys) page through every one of them, with a
     "2 / 3" counter beside the title. Driven by openCvReqDocPreview() in the script below. -->
<div id="cvReqDocPreviewModal" class="cv-doc-modal">
    <div class="cv-doc-modal-bar">
        <div class="cv-doc-modal-title">
            <i class="fas fa-file-alt cv-doc-modal-icon" id="cvReqDocPreviewIcon"></i>
            <span class="cv-doc-modal-name" id="cvReqDocPreviewName">Document</span>
            <span class="cv-doc-modal-counter" id="cvReqDocPreviewCounter"></span>
        </div>
        <button type="button" onclick="closeCvReqDocPreview()" class="cv-doc-modal-close" title="Close">&times;</button>
    </div>
    <div class="cv-doc-modal-viewer" id="cvReqDocPreviewViewerWrap"></div>
</div>

<script>
const SELF = window.location.pathname;

/* ══════════════════════════════════════════════════════════
   NEW (this adjustment): global loading/processing overlay controls —
   ported faithfully from admin_company_list.php's own
   showGlobalLoading()/hideGlobalLoading() (same usage-counter pattern,
   so overlapping calls can never hide it prematurely).
   showGlobalLoading(label) reveals the popup with an optional custom
   label ("Setting Schedule…", "Approving…", "Sending…", etc.).
   hideGlobalLoading() fades it out once every in-flight operation that
   asked for it has actually finished.
   ══════════════════════════════════════════════════════════ */
let globalLoadingActiveCount = 0;
const globalLoadingOverlay = document.getElementById('globalLoadingOverlay');
const globalLoadingLabel = document.getElementById('globalLoadingLabel');

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

/* The overlay is visible by default (see CSS) so it covers the very
   first paint while page assets are still loading. As soon as the
   window has fully finished loading, it fades away on its own. */
window.addEventListener('load', function() {
    globalLoadingActiveCount = 0;
    if (globalLoadingOverlay) globalLoadingOverlay.classList.add('hidden');
});
/* Safety net: if for any reason the 'load' event is delayed (slow
   third-party assets like the Font Awesome CDN), don't leave the admin
   staring at the popup forever — hide it after a short ceiling too. */
setTimeout(function() {
    globalLoadingActiveCount = 0;
    if (globalLoadingOverlay) globalLoadingOverlay.classList.add('hidden');
}, 4000);

// ── Sidebar
document.getElementById('toggleBtn').addEventListener('click',()=>document.getElementById('sidebar').classList.toggle('collapsed'));
function openPreview(src){document.getElementById("previewImage").src=src;document.getElementById("imagePreviewModal").style.display="flex";}
// UPDATED (this adjustment): shows the remark INPUT while "Rejected" is selected (and puts the cursor in it).
function toggleRemark(sel,id){const el=document.getElementById(id);if(!el)return;const show=(sel.value==="Rejected"||sel.value==="Denied");el.style.display=show?"inline-block":"none";if(show)el.focus();}
// NEW (this adjustment): the remark box is multi-line now, but Enter still SAVES — exactly what it did when the remark was a
// one-line input. Shift+Enter adds a line break; any line breaks are folded into single spaces when the remark is sent.
document.addEventListener('keydown',function(e){
    if(e.key!=='Enter'||e.shiftKey||e.isComposing) return;
    const ta=(e.target&&e.target.matches&&e.target.matches('.ajax-req-form textarea[name="remark"]'))?e.target:null;
    if(!ta) return;
    e.preventDefault();
    const f=ta.closest('form');
    if(f.requestSubmit) f.requestSubmit(); else { const b=f.querySelector('button[type="submit"]'); if(b) b.click(); }
});

// ── Guard Modal
// ── NEW: "Don't show again" support ──────────────────────────────────────
// showGuardModal() now optionally accepts a 4th argument: an options object.
// Passing { suppressKey: 'some_unique_key' } reveals a "Don't show this
// notification again today" checkbox. If the admin checks it before closing
// the modal, a timestamp (now + 24 hours) is stored in localStorage under
// that key. Callers can check isGuardModalSuppressed('some_unique_key')
// before invoking showGuardModal to skip the popup while still performing
// any follow-up action (e.g. reloading the page). This does not change the
// behavior of any existing showGuardModal(...) call that only passes the
// original 3 arguments (icon, title, msg) — the checkbox simply stays
// hidden for those, exactly like before.
function isGuardModalSuppressed(key){
    if (!key) return false;
    try {
        const expiry = localStorage.getItem(key);
        if (!expiry) return false;
        if (Date.now() < parseInt(expiry, 10)) return true;
        localStorage.removeItem(key);
        return false;
    } catch(e) { return false; }
}
function showGuardModal(icon,title,msg,options){
    options = options || {};
    document.getElementById('guardModalIcon').textContent=icon;
    document.getElementById('guardModalIcon').style.display = icon ? '' : 'none';   // NEW (this adjustment): no emoji/icon -> no empty slot above the title
    document.getElementById('guardModalTitle').textContent=title;
    document.getElementById('guardModalMsg').innerHTML=msg;

    const dontShowWrap = document.getElementById('guardModalDontShowWrap');
    const dontShowCheckbox = document.getElementById('guardModalDontShowCheckbox');
    if (options.suppressKey) {
        window._guardModalSuppressKey = options.suppressKey;
        if (dontShowWrap) dontShowWrap.style.display = 'flex';
        if (dontShowCheckbox) dontShowCheckbox.checked = false;
    } else {
        window._guardModalSuppressKey = null;
        if (dontShowWrap) dontShowWrap.style.display = 'none';
    }

    document.getElementById('guardModal').style.display='flex';
}
function closeGuardModal(){
    // Persist the "don't show again" preference (only relevant when the
    // checkbox row was made visible by showGuardModal's suppressKey option).
    const dontShowWrap = document.getElementById('guardModalDontShowWrap');
    const dontShowCheckbox = document.getElementById('guardModalDontShowCheckbox');
    if (dontShowWrap && dontShowWrap.style.display !== 'none' && dontShowCheckbox && dontShowCheckbox.checked && window._guardModalSuppressKey) {
        try {
            const expiry = Date.now() + (24 * 60 * 60 * 1000); // suppress for 1 day
            localStorage.setItem(window._guardModalSuppressKey, String(expiry));
        } catch(e) {}
    }
    window._guardModalSuppressKey = null;
    if (dontShowWrap) dontShowWrap.style.display = 'none';
    document.getElementById('guardModal').style.display='none';
}

// ── Req Blob Modal (MOA doc stored in company_requirements)
function openReqBlobModal(userId,label){
    const modal=document.getElementById('reqBlobModal'),loader=document.getElementById('reqBlobLoader'),frame=document.getElementById('reqBlobPdfFrame');
    document.getElementById('reqBlobModalTitle').textContent=label||'MOA Document';
    const url=SELF+'?stream_req_blob='+userId+'&req_type=moa_document';
    document.getElementById('reqBlobDownloadBtn').href=url;
    frame.style.display='none';loader.style.display='flex';modal.style.display='flex';
    frame.onload=function(){loader.style.display='none';frame.style.display='block';};
    frame.onerror=function(){loader.style.display='none';};
    frame.src=url;
    setTimeout(()=>{if(loader.style.display!=='none'){loader.style.display='none';frame.style.display='block';}},6000);
}
function openReqBlobModalImg(url,label){document.getElementById('previewImage').src=url;document.getElementById('imagePreviewModal').style.display='flex';}
function closeReqBlobModal(){const modal=document.getElementById('reqBlobModal'),frame=document.getElementById('reqBlobPdfFrame');modal.style.display='none';frame.src='';}

// ════════════════════════════════════════════════════════════════════════
//  NEW (this adjustment) — REQUIREMENT DOCUMENT PREVIEW (MULTIPLE FILES + PDF)
//  Ported from CompanyForm.php's openReqDocPreview()/renderReqDocPreview().
//  A Requirements-gallery card carries the requirement's entries as
//  data-req-files (a JSON array of {id, isPdf}); clicking it opens the dark
//  viewer, where a PDF is drawn in an iframe and an image in the image
//  viewer, and — when there is more than one file — prev / next (or the
//  left / right arrow keys) page through every entry. Each entry is streamed
//  one at a time by stream_req_blob&file_id=…. The click handler is
//  delegated on `document`, so it also covers rows inserted live by
//  liveInsertOrUpdateCompanyRow().
// ════════════════════════════════════════════════════════════════════════
var cvPrevUid=null,cvPrevKey=null,cvPrevFiles=[],cvPrevIndex=0,cvPrevLabel='',cvPrevBodyOverflow='';
function cvReqFileUrl(uid,reqKey,fileId){
    return SELF+'?stream_req_blob='+encodeURIComponent(uid)+'&req_type='+encodeURIComponent(reqKey)+'&file_id='+encodeURIComponent(fileId);
}
function openCvReqDocPreview(uid,reqKey,filesMeta,label){
    cvPrevUid=uid;cvPrevKey=reqKey;cvPrevFiles=filesMeta||[];cvPrevIndex=0;cvPrevLabel=label||'Document';
    if(!cvPrevFiles.length)return;
    renderCvReqDocPreview();
    const modal=document.getElementById('cvReqDocPreviewModal');
    modal.style.display='flex';
    cvPrevBodyOverflow=document.body.style.overflow;
    document.body.style.overflow='hidden';
}
function cvStepReqDocPreview(delta){
    if(cvPrevFiles.length<2)return;
    cvPrevIndex=(cvPrevIndex+delta+cvPrevFiles.length)%cvPrevFiles.length;
    renderCvReqDocPreview();
}
function renderCvReqDocPreview(){
    const meta=cvPrevFiles[cvPrevIndex]; if(!meta)return;
    const viewerWrap=document.getElementById('cvReqDocPreviewViewerWrap');
    const nameLabel=document.getElementById('cvReqDocPreviewName');
    const iconEl=document.getElementById('cvReqDocPreviewIcon');
    const counterEl=document.getElementById('cvReqDocPreviewCounter');
    if(nameLabel)nameLabel.textContent=cvPrevLabel;
    viewerWrap.innerHTML='';
    viewerWrap.classList.remove('image-mode');
    const src=cvReqFileUrl(cvPrevUid,cvPrevKey,meta.id);
    if(meta.isPdf){
        if(iconEl)iconEl.className='fas fa-file-pdf cv-doc-modal-icon';
        const iframe=document.createElement('iframe');
        iframe.title=cvPrevLabel;
        iframe.src=src;
        viewerWrap.appendChild(iframe);
    } else {
        if(iconEl)iconEl.className='fas fa-image cv-doc-modal-icon';
        viewerWrap.classList.add('image-mode');
        const img=document.createElement('img');
        img.className='cv-doc-modal-image';
        img.alt=cvPrevLabel;
        // a file that can no longer be streamed back (e.g. removed after a denial) gets a clear message instead of a broken image
        img.onerror=function(){
            img.onerror=null;
            if(img.parentNode)img.parentNode.removeChild(img);
            const msg=document.createElement('div');
            msg.className='cv-doc-modal-unavailable';
            msg.innerHTML='<i class="fas fa-file-circle-xmark"></i><span>Preview unavailable</span>';
            viewerWrap.appendChild(msg);
        };
        img.src=src;
        viewerWrap.appendChild(img);
    }
    if(cvPrevFiles.length>1){
        const prevBtn=document.createElement('button');
        prevBtn.type='button';prevBtn.className='cv-doc-nav-btn cv-doc-nav-prev';prevBtn.title='Previous file';
        prevBtn.innerHTML='<i class="fas fa-chevron-left"></i>';
        prevBtn.addEventListener('click',function(e){e.stopPropagation();cvStepReqDocPreview(-1);});
        const nextBtn=document.createElement('button');
        nextBtn.type='button';nextBtn.className='cv-doc-nav-btn cv-doc-nav-next';nextBtn.title='Next file';
        nextBtn.innerHTML='<i class="fas fa-chevron-right"></i>';
        nextBtn.addEventListener('click',function(e){e.stopPropagation();cvStepReqDocPreview(1);});
        viewerWrap.appendChild(prevBtn);
        viewerWrap.appendChild(nextBtn);
    }
    if(counterEl)counterEl.textContent=cvPrevFiles.length>1?((cvPrevIndex+1)+' / '+cvPrevFiles.length):'';
}
function closeCvReqDocPreview(){
    const modal=document.getElementById('cvReqDocPreviewModal');
    const viewerWrap=document.getElementById('cvReqDocPreviewViewerWrap');
    if(!modal)return;
    modal.style.display='none';
    viewerWrap.innerHTML='';
    document.body.style.overflow=cvPrevBodyOverflow;
    cvPrevFiles=[];
}
document.addEventListener('click',function(e){
    const trig=e.target.closest?e.target.closest('.cv-preview-trigger'):null;
    if(trig){
        let filesMeta=[];
        try{filesMeta=JSON.parse(trig.getAttribute('data-req-files')||'[]');}catch(err){filesMeta=[];}
        openCvReqDocPreview(trig.getAttribute('data-uid'),trig.getAttribute('data-req-key'),filesMeta,trig.getAttribute('data-req-label')||'Document');
        return;
    }
    // clicking the dark backdrop (outside the bar / viewer) closes it, like CompanyForm.php
    if(e.target===document.getElementById('cvReqDocPreviewModal'))closeCvReqDocPreview();
});
document.addEventListener('keydown',function(e){
    const modal=document.getElementById('cvReqDocPreviewModal');
    if(!modal||modal.style.display!=='flex')return;
    if(e.key==='Escape')closeCvReqDocPreview();
    else if(e.key==='ArrowLeft')cvStepReqDocPreview(-1);
    else if(e.key==='ArrowRight')cvStepReqDocPreview(1);
});

// ── Pagination
const ROWS_PER_PAGE=10;
// ════════════════════════════════════════════════════════════════════════
//  NEW (this adjustment) — ACCORDION BEHAVIOR: only one company row's
//  details-pane can be expanded at a time, across BOTH tables. Expanding
//  is normally pure CSS (a hidden checkbox + a `:checked ~ .details-pane`
//  sibling selector — see .toggle-input/.details-pane rules above), which
//  has no built-in concept of "close every other one" on its own. This
//  listens for any .toggle-input becoming checked and simply unchecks
//  every other one, letting the existing CSS rule collapse them.
//  Delegated on `document` (rather than binding to each checkbox
//  individually) so it automatically covers rows added later by
//  liveInsertOrUpdateCompanyRow() too, with nothing extra to wire up
//  there. ════════════════════════════════════════════════════════════════════════
document.addEventListener('change', function(e){
    const target = e.target;
    if (!target.classList || !target.classList.contains('toggle-input')) return;
    if (!target.checked) return;
    document.querySelectorAll('.toggle-input').forEach(cb => {
        if (cb !== target) cb.checked = false;
    });
});

// NEW (this adjustment) — two independent pagers, one per request-type
// table ("existing" / "new"), since the page now shows two separate
// company lists side by side instead of one combined list. Each pager
// tracks its own current page and filtered-row set, scoped to its own
// wrapper element, so paging through one table never affects the other.
const _pagers={existing:{currentPage:1,filteredRows:[]},new:{currentPage:1,filteredRows:[]}};
function _pagerWrapperId(which){return which==='existing'?'existingCompanyWrapper':'newCompanyWrapper';}
// UPDATED (this adjustment): each table now has its OWN search bar (#searchInputExisting / #searchInputNew). filterAll() with no
// argument re-filters both tables (each by its own search text + the shared status filter) exactly as before; filterAll('existing') /
// filterAll('new') — what typing in a table's search bar calls — re-filters only that table, so searching one never resets the
// other's page.
function filterAll(only){
    if(only!=='existing'&&only!=='new')only=null;
    const stat=document.getElementById('statusFilter').value.toLowerCase();
    ['existing','new'].forEach(which=>{
        if(only&&only!==which)return;
        const searchEl=document.getElementById(which==='existing'?'searchInputExisting':'searchInputNew');
        const search=(searchEl?searchEl.value:'').toLowerCase();
        // NEW (this adjustment): this table's company type filter (both tables) and MOA status filter (New table only). Both read what the
        // row DISPLAYS — the "Type" badge (3rd cell) and the "MOA Status" text (the status cell, minus its "●") — so rows inserted or
        // updated live are matched exactly as they look, with nothing extra to keep in step.
        const typeSel=document.getElementById(which==='existing'?'typeFilterExisting':'typeFilterNew');
        const typeWanted=(typeSel?typeSel.value:'').toLowerCase();
        const moaSel=(which==='new')?document.getElementById('moaStatusFilterNew'):null;
        const moaWanted=(moaSel?moaSel.value:'').toLowerCase();
        const wrapper=document.getElementById(_pagerWrapperId(which));
        if(!wrapper)return;
        const allRows=Array.from(wrapper.querySelectorAll('.company-row'));
        _pagers[which].filteredRows=allRows.filter(row=>{const txt=row.querySelector('.row-summary').textContent.toLowerCase(),sTxt=row.querySelector('.row-summary span:last-child').textContent.toLowerCase();
            if(typeWanted){const typeCell=row.querySelector('.row-summary > span:nth-child(3)');if(((typeCell?typeCell.textContent:'').trim().toLowerCase())!==typeWanted)return false;}
            if(moaWanted){const st=row.querySelector('.overall-status-cell');if(((st?st.textContent:'').replace('●','').trim().toLowerCase())!==moaWanted)return false;}
            return txt.includes(search)&&(stat==='all'||sTxt.includes(stat));});
        _pagers[which].currentPage=1;
        renderPage(which);
    });
}
// NEW (this adjustment): puts the per-table company type / MOA status filters back to "All" — used by the two "go to this company's
// row" helpers so an active filter can never leave the row they are taking the admin to hidden.
function cvResetTableFilters(){
    ['typeFilterExisting','typeFilterNew','moaStatusFilterNew'].forEach(id=>{const el=document.getElementById(id);if(el)el.value='';});
}
function renderPage(which){
    const wrapper=document.getElementById(_pagerWrapperId(which));
    if(!wrapper)return;
    const pager=_pagers[which];
    const allRows=Array.from(wrapper.querySelectorAll('.company-row'));const start=(pager.currentPage-1)*ROWS_PER_PAGE,end=start+ROWS_PER_PAGE;
    allRows.forEach(r=>r.style.display='none');pager.filteredRows.forEach((r,i)=>{r.style.display=(i>=start&&i<end)?'':'none';});
    renderPagination(which,pager.filteredRows.length,pager.currentPage);
}
function renderPagination(which,total,current){
    const containerId=which==='existing'?'paginationContainerExisting':'paginationContainerNew';
    const infoId=which==='existing'?'paginationInfoExisting':'paginationInfoNew';
    const container=document.getElementById(containerId);if(!container)return;
    const totalPages=Math.ceil(total/ROWS_PER_PAGE),info=document.getElementById(infoId);
    if(totalPages<=1){container.innerHTML='';if(info)info.textContent=total===0?'No companies found':'Showing 1–'+total+' of '+total;return;}
    if(info){const from=(current-1)*ROWS_PER_PAGE+1,to=Math.min(current*ROWS_PER_PAGE,total);info.textContent='Showing '+from+'–'+to+' of '+total+' companies';}
    let html='<button onclick="goToPage('+(current-1)+',\''+which+'\')" '+(current===1?'disabled':'')+' class="pg-btn">← Prev</button>';
    const maxVisible=5;let startPage=Math.max(1,current-Math.floor(maxVisible/2)),endPage=Math.min(totalPages,startPage+maxVisible-1);
    if(endPage-startPage+1<maxVisible)startPage=Math.max(1,endPage-maxVisible+1);
    if(startPage>1){html+='<button onclick="goToPage(1,\''+which+'\')" class="pg-btn">1</button>';if(startPage>2)html+='<span class="pg-ellipsis">…</span>';}
    for(let p=startPage;p<=endPage;p++)html+='<button onclick="goToPage('+p+',\''+which+'\')" class="pg-btn'+(p===current?' pg-active':'')+'" >'+p+'</button>';
    if(endPage<totalPages){if(endPage<totalPages-1)html+='<span class="pg-ellipsis">…</span>';html+='<button onclick="goToPage('+totalPages+',\''+which+'\')" class="pg-btn">'+totalPages+'</button>';}
    html+='<button onclick="goToPage('+(current+1)+',\''+which+'\')" '+(current===totalPages?'disabled':'')+' class="pg-btn">Next →</button>';
    container.innerHTML=html;
}
function goToPage(page,which){const pager=_pagers[which];const totalPages=Math.ceil(pager.filteredRows.length/ROWS_PER_PAGE);if(page<1||page>totalPages)return;pager.currentPage=page;renderPage(which);document.getElementById(_pagerWrapperId(which))?.scrollIntoView({behavior:'smooth',block:'start'});}

// ════════════════════════════════════════════════════════
//  MOA DOCUMENT IN-TABLE WORKFLOW
// ════════════════════════════════════════════════════════

// ── UPDATED (this adjustment): "pending" and legacy "reviewing" are now
// a single merged "Pending for Review" stage. normalizeMoaStage() maps
// any legacy 'reviewing' value read back from the server to 'pending' so
// every client-side helper below only ever has to reason about the
// merged 3-stage model, mirroring the same normalization done server-side.
function normalizeMoaStage(stage){ return stage === 'reviewing' ? 'pending' : stage; }

const MOA_STAGE_LABELS = {
    pending:   'Pending for Review',
    approved:  'Approved',
    scheduled: 'Scheduling'   // UPDATED (this adjustment): was "Scheduled for Signing"
};
const MOA_STAGE_COLORS = {
    pending:   {bg:'#7A5A0B',c:'#7A5A0B'},
    approved:  {bg:'#2C5A2C',c:'#2C5A2C'},
    scheduled: {bg:'#A3AFC7',c:'#1B2A4A'},
};
const MOA_STAGES = ['pending','approved','scheduled'];

// ── UPDATED (this adjustment): 3-step stepper — "Pending for Review"
// covers what used to be two separate steps (Pending + Reviewing).
const MOA_STEP_DEFS = [
    {key:'pending',   label:'Pending for Review', icon:'1'},
    {key:'approved',  label:'Approved',            icon:'2'},
    {key:'scheduled', label:'Scheduling',          icon:'3'},   // UPDATED (this adjustment): "3" until scheduled — the a check mark is drawn by cvSyncSchedStepIcon() once the schedule is finalized
];

// ── UPDATED (this adjustment): the old quick-pick chip system (offset-day
// presets, per-row hidden date/time inputs, chip click/change delegated
// listeners) is removed entirely — "Set Signing Schedule" / "Re-Schedule"
// now both open the single shared #moaCustomScheduleOverlay 2-step
// wizard below, whose own "Confirm Schedule" step submits directly.
function formatScheduleReadable(dateStr,timeStr){
    if(!dateStr||!timeStr) return '';
    const d=new Date(dateStr+'T'+timeStr+':00');
    if(isNaN(d.getTime())) return '';
    return d.toLocaleString('en-US',{month:'short',day:'numeric',year:'numeric',hour:'numeric',minute:'2-digit'});
}

// ════════════════════════════════════════════════════════
//  "SET SIGNING SCHEDULE" WIZARD — Step 1: Date  →  Step 2: Time  →  Confirm.
//  ── UPDATED (this adjustment):
//    - The date step now sets min="today" (see openMoaCustomScheduleModal()),
//      so a past date can never be selected in the calendar picker at all.
//    - The time step now also offers a row of quick time-of-day chips
//      (9:00 AM / 10:00 AM / 1:00 PM / 2:00 PM) alongside the native time
//      picker, for a faster/"enhanced" pick, in addition to a fully custom
//      time.
//    - Confirming now submits straight to the server itself (via
//      advanceMoaReqStage(), reused below), instead of writing into a
//      separate per-row hidden date/time pair that a different button had
//      to submit afterward — there is no longer a separate inline panel
//      or "Confirm New Schedule" button at all.
//  Cancel closes at any time without changing anything. Back returns from
//  Time to Date.
// ════════════════════════════════════════════════════════
let _mcsCurrentUserId = null;
let _mcsStep = 1;
// ── NEW (this adjustment): which month/year the inline calendar grid is
// currently showing (0-indexed month, matching JS Date's convention).
let _mcsCalViewYear = null;
let _mcsCalViewMonth = null;

function openMoaCustomScheduleModal(userId){
    _mcsCurrentUserId = userId;
    const dateInput = document.getElementById('mcsDateInput');
    const timeInput = document.getElementById('mcsTimeInput');
    // The date can never be set in the past — enforced right in the
    // browser's own calendar picker, not just validated after the fact.
    const today = new Date();
    const minDate = today.getFullYear() + '-' + String(today.getMonth()+1).padStart(2,'0') + '-' + String(today.getDate()).padStart(2,'0');
    if (dateInput) { dateInput.min = minDate; dateInput.value = ''; }
    if (timeInput) timeInput.value = '';
    // ── NEW (this adjustment): reset the calendar grid to today's month
    // and re-render it fresh every time the modal opens.
    _mcsCalViewYear = today.getFullYear();
    _mcsCalViewMonth = today.getMonth();
    // ── FIX (this adjustment): the picker columns weren't centering at
    // all (every item started stacked at the very top instead of the
    // first item sitting in the highlight band) — the modal was still
    // display:none at the moment mcsBuildTimeRoll() measured
    // hourCol.clientHeight to compute the top/bottom spacer height, so
    // that measurement always came back 0 (a hidden element has no
    // layout size yet), which zeroed out the spacers entirely. Making the
    // overlay visible FIRST, then building/measuring the roll picker
    // columns after, means clientHeight reflects their real rendered
    // height by the time the spacer math runs.
    document.getElementById('moaCustomScheduleOverlay').classList.add('open');
    mcsRenderCalendar();
    // ── NEW (this adjustment): reset and rebuild the digital time roll
    // picker fresh every time the modal opens too, so no stale selection
    // carries over from a previous schedule action.
    mcsBuildTimeRoll();
    mcsGoToStep(1);
}
function closeMoaCustomScheduleModal(){
    document.getElementById('moaCustomScheduleOverlay').classList.remove('open');
    _mcsCurrentUserId = null;
}
function mcsGoToStep(step){
    _mcsStep = step;
    const dot1=document.getElementById('mcsStepDot1'), dot2=document.getElementById('mcsStepDot2'), line=document.getElementById('mcsStepLine');
    const stepDate=document.getElementById('mcsStepDate'), stepTime=document.getElementById('mcsStepTime');
    const backBtn=document.getElementById('mcsBackBtn'), nextBtn=document.getElementById('mcsNextBtn');
    if(step===1){
        stepDate.style.display='block'; stepTime.style.display='none';
        dot1.className='mcs-step-dot active'; dot2.className='mcs-step-dot'; line.style.background='#A3AFC7';
        backBtn.style.display='none';
        nextBtn.innerHTML='Next <i class="fas fa-arrow-right"></i>';
        nextBtn.disabled = false;
    } else {
        stepDate.style.display='none'; stepTime.style.display='block';
        dot1.className='mcs-step-dot done'; dot2.className='mcs-step-dot active'; line.style.background='#A3AFC7';
        backBtn.style.display='flex';
        nextBtn.innerHTML='<i class="fas fa-check"></i> Confirm Schedule';
        nextBtn.disabled = false;
        // ── FIX (this adjustment): the roll picker's spacer heights are
        // now (re)measured right here — the instant stepTime.style.display
        // is actually switched to 'block' above — instead of once, back
        // when the modal first opened while this whole step was still
        // display:none (which always measured a height of 0 and broke the
        // centering entirely). See mcsResizeTimeRollSpacers()'s own
        // docblock above mcsBuildTimeRoll() for the full explanation.
        mcsResizeTimeRollSpacers();
        mcsUpdateSummary();
    }
}

// ════════════════════════════════════════════════════════
//  NEW (this adjustment) — INLINE CALENDAR GRID for the Date step,
//  replacing the plain date input's own text-entry UI. The hidden
//  #mcsDateInput (a native <input type="date">) stays the single source
//  of truth for the actual selected value — mcsSelectCalendarDay() below
//  just sets its .value and fires a 'change' event, so every other piece
//  of existing logic that reads it (mcsUpdateSummary(), mcsNextStep()'s
//  validation, the .min guard set in openMoaCustomScheduleModal()) keeps
//  working completely unchanged.
// ════════════════════════════════════════════════════════
function mcsRenderCalendar(){
    const monthNames = ['January','February','March','April','May','June','July','August','September','October','November','December'];
    const label = document.getElementById('mcsCalMonthLabel');
    if (label) label.textContent = monthNames[_mcsCalViewMonth] + ' ' + _mcsCalViewYear;

    const today = new Date(); today.setHours(0,0,0,0);
    const todayY = today.getFullYear(), todayM = today.getMonth(), todayD = today.getDate();

    const dateInput = document.getElementById('mcsDateInput');
    const selectedVal = dateInput ? dateInput.value : '';
    let selY = null, selM = null, selD = null;
    if (selectedVal) {
        const parts = selectedVal.split('-');
        selY = parseInt(parts[0], 10); selM = parseInt(parts[1], 10) - 1; selD = parseInt(parts[2], 10);
    }

    const firstWeekday = new Date(_mcsCalViewYear, _mcsCalViewMonth, 1).getDay();
    const daysInMonth = new Date(_mcsCalViewYear, _mcsCalViewMonth + 1, 0).getDate();

    const daysWrap = document.getElementById('mcsCalendarDays');
    if (!daysWrap) return;

    let html = '';
    for (let i = 0; i < firstWeekday; i++) {
        html += '<span class="mcs-cal-day empty"></span>';
    }
    for (let d = 1; d <= daysInMonth; d++) {
        const cellDate = new Date(_mcsCalViewYear, _mcsCalViewMonth, d); cellDate.setHours(0,0,0,0);
        const isPast = cellDate < today;
        const isToday = (_mcsCalViewYear === todayY && _mcsCalViewMonth === todayM && d === todayD);
        const isSelected = (_mcsCalViewYear === selY && _mcsCalViewMonth === selM && d === selD);
        let cls = 'mcs-cal-day';
        if (isToday) cls += ' today';
        if (isSelected) cls += ' selected';
        if (isPast) {
            cls += ' disabled';
            html += `<span class="${cls}">${d}</span>`;
        } else {
            html += `<span class="${cls}" onclick="mcsSelectCalendarDay(${_mcsCalViewYear},${_mcsCalViewMonth},${d})">${d}</span>`;
        }
    }
    daysWrap.innerHTML = html;

    // The date can never be in the past — disable navigating any further
    // back once the calendar is already showing the current month,
    // matching the same rule the hidden native input's min attribute
    // already enforces.
    const prevBtn = document.getElementById('mcsCalPrevBtn');
    if (prevBtn) prevBtn.disabled = (_mcsCalViewYear === todayY && _mcsCalViewMonth === todayM);
}
function mcsCalendarChangeMonth(delta){
    _mcsCalViewMonth += delta;
    if (_mcsCalViewMonth < 0) { _mcsCalViewMonth = 11; _mcsCalViewYear--; }
    if (_mcsCalViewMonth > 11) { _mcsCalViewMonth = 0; _mcsCalViewYear++; }
    mcsRenderCalendar();
}
function mcsSelectCalendarDay(y, m, d){
    const dateInput = document.getElementById('mcsDateInput');
    if (!dateInput) return;
    const mm = String(m + 1).padStart(2, '0');
    const dd = String(d).padStart(2, '0');
    dateInput.value = y + '-' + mm + '-' + dd;
    dateInput.dispatchEvent(new Event('change'));
    mcsRenderCalendar();

    const summaryLine = document.getElementById('mcsDateSummaryLine'), summaryText = document.getElementById('mcsDateSummaryText');
    if (summaryLine && summaryText) {
        const readable = new Date(y, m, d).toLocaleDateString('en-US', {weekday:'long', month:'long', day:'numeric', year:'numeric'});
        summaryText.textContent = 'Selected: ' + readable;
        summaryLine.classList.add('show');
    }
}

// ════════════════════════════════════════════════════════
//  NEW (this adjustment) — DIGITAL "ROLL PICKER" for the Time step,
//  replacing reliance on the browser's own native time-picker dropdown.
//  Three scrollable columns (Hour 1–12 / Minute 00–59 / AM-PM); clicking
//  an item selects it. The hidden #mcsTimeInput (a native
//  <input type="time">) stays the single source of truth for the actual
//  24-hour value — mcsSelectTimeRoll() below just computes it from the
//  three picked parts and fires a 'change' event, exactly the same
//  pattern the calendar grid already uses for the Date step, so every
//  other existing piece of logic (mcsUpdateSummary(), mcsNextStep()'s
//  validation/read, the change listener) keeps working unchanged.
// ════════════════════════════════════════════════════════
const MCS_TIME_STATE = { hour: null, minute: null, meridiem: null };

// ── FIX (this adjustment): the previous fix (making the outer
// #moaCustomScheduleOverlay visible before measuring) wasn't the whole
// story — the roll picker columns live inside #mcsStepTime, which has
// its own separate `display:none` in the HTML and only becomes visible
// when the admin clicks through to step 2 (see mcsGoToStep() below).
// mcsBuildTimeRoll() was still measuring clientHeight while THAT
// ancestor was hidden (it runs once, when the modal first opens, while
// step 1/Date is still showing) — so it still always measured 0 and the
// spacers still came out empty. Measuring is now split out into its own
// function, mcsResizeTimeRollSpacers(), and called from mcsGoToStep()
// itself at the exact moment #mcsStepTime is switched to display:block —
// the only moment the columns actually have a real rendered height.
function mcsBuildTimeRoll(){
    MCS_TIME_STATE.hour = null;
    MCS_TIME_STATE.minute = null;
    MCS_TIME_STATE.meridiem = null;

    const hourCol = document.getElementById('mcsTimeRollHour');
    const minuteCol = document.getElementById('mcsTimeRollMinute');
    const meridiemCol = document.getElementById('mcsTimeRollMeridiem');
    if (!hourCol || !minuteCol || !meridiemCol) return;

    // ── NEW (this adjustment): a blank spacer row above and below the
    // real values, sized to exactly half the column's visible height
    // (minus one item), so even the very first ("01"/"00") or very last
    // ("12"/"59") value has room to scroll all the way up to the center
    // highlight band — matching every other item instead of getting stuck
    // unable to reach it. Actual sizing happens in
    // mcsResizeTimeRollSpacers() (see its own docblock above), since it
    // needs these columns to be visible first.
    let hourHtml = '<div class="mcs-time-roll-spacer" id="mcsHourSpacerTop"></div>';
    for (let h = 1; h <= 12; h++) {
        const hh = String(h).padStart(2, '0');
        hourHtml += `<div class="mcs-time-roll-item" data-val="${hh}" onclick="mcsSelectTimeRoll('hour','${hh}')">${hh}</div>`;
    }
    hourHtml += '<div class="mcs-time-roll-spacer" id="mcsHourSpacerBottom"></div>';
    hourCol.innerHTML = hourHtml;

    let minuteHtml = '<div class="mcs-time-roll-spacer" id="mcsMinuteSpacerTop"></div>';
    for (let m = 0; m <= 59; m++) {
        const mm = String(m).padStart(2, '0');
        minuteHtml += `<div class="mcs-time-roll-item" data-val="${mm}" onclick="mcsSelectTimeRoll('minute','${mm}')">${mm}</div>`;
    }
    minuteHtml += '<div class="mcs-time-roll-spacer" id="mcsMinuteSpacerBottom"></div>';
    minuteCol.innerHTML = minuteHtml;

    // ── FIX (this adjustment): AM/PM is now built as a real 2-item
    // scrollable list with the exact same top/bottom spacers as Hour/
    // Minute above, instead of a flex-centered static pair — that older
    // approach centered the PAIR as a whole within the column, not each
    // individual item against the highlight band, so the selected one
    // didn't reliably land inside it (the reported misalignment). This
    // makes all three columns behave identically.
    let meridiemHtml = '<div class="mcs-time-roll-spacer" id="mcsMeridiemSpacerTop"></div>';
    meridiemHtml += '<div class="mcs-time-roll-item" data-val="AM" onclick="mcsSelectTimeRoll(\'meridiem\',\'AM\')">AM</div>';
    meridiemHtml += '<div class="mcs-time-roll-item" data-val="PM" onclick="mcsSelectTimeRoll(\'meridiem\',\'PM\')">PM</div>';
    meridiemHtml += '<div class="mcs-time-roll-spacer" id="mcsMeridiemSpacerBottom"></div>';
    meridiemCol.innerHTML = meridiemHtml;

    // The columns are still hidden at this point (#mcsStepTime hasn't
    // been shown yet), so don't measure/size anything here — just reset
    // scroll position for when it does become visible.
    hourCol.scrollTop = 0;
    minuteCol.scrollTop = 0;
    meridiemCol.scrollTop = 0;
}

// ── NEW (this adjustment): does the actual spacer-height measurement —
// split out of mcsBuildTimeRoll() so it can be called at the moment the
// Time step's columns are genuinely visible (from mcsGoToStep(), right
// after #mcsStepTime's display is switched to 'block'), instead of while
// they're still hidden behind step 1/Date.
function mcsResizeTimeRollSpacers(){
    const hourCol = document.getElementById('mcsTimeRollHour');
    if (!hourCol) return;

    // Must match .mcs-time-roll-item's flex-basis in the CSS above.
    const ITEM_HEIGHT = 34;
    const spacerHeight = Math.max(0, (hourCol.clientHeight - ITEM_HEIGHT) / 2);
    ['mcsHourSpacerTop','mcsHourSpacerBottom','mcsMinuteSpacerTop','mcsMinuteSpacerBottom','mcsMeridiemSpacerTop','mcsMeridiemSpacerBottom'].forEach(id => {
        const el = document.getElementById(id);
        if (el) el.style.height = spacerHeight + 'px';
    });

    // Re-center whichever value (if any) is already selected — e.g. the
    // admin went Time → Back → Date → Next again without changing their
    // time pick; otherwise this just keeps every column sitting at its
    // freshly-sized top (scrollTop 0 already lines the first real item up
    // with the highlight band, thanks to the spacer above it).
    ['hour','minute','meridiem'].forEach(part => {
        if (MCS_TIME_STATE[part]) mcsSelectTimeRoll(part, MCS_TIME_STATE[part]);
    });
}

function mcsSelectTimeRoll(part, val){
    MCS_TIME_STATE[part] = val;

    const colId = part === 'hour' ? 'mcsTimeRollHour' : (part === 'minute' ? 'mcsTimeRollMinute' : 'mcsTimeRollMeridiem');
    const col = document.getElementById(colId);
    if (col) {
        col.querySelectorAll('.mcs-time-roll-item').forEach(el => el.classList.remove('selected'));
        const chosen = col.querySelector(`.mcs-time-roll-item[data-val="${val}"]`);
        if (chosen) {
            chosen.classList.add('selected');
            // ── FIX (this adjustment): scrollIntoView({block:'center'})
            // was not reliably centering every column — most visibly AM/PM,
            // which (with only 2 real rows) has a much shorter scrollable
            // range than Hour/Minute, and appeared to land a row off from
            // where Hour/Minute centered. scrollIntoView leaves the exact
            // centering math up to the browser, and that can behave
            // slightly differently across columns of very different
            // content length, especially combined with scroll-snap.
            // Computing and setting the exact scrollTop directly instead
            // is fully deterministic — the same math for every column,
            // every time, regardless of how many rows it has.
            const targetScrollTop = chosen.offsetTop - (col.clientHeight - chosen.offsetHeight) / 2;
            col.scrollTo({ top: targetScrollTop, behavior: 'smooth' });
        }
    }

    if (MCS_TIME_STATE.hour && MCS_TIME_STATE.minute && MCS_TIME_STATE.meridiem) {
        let h24 = parseInt(MCS_TIME_STATE.hour, 10);
        if (MCS_TIME_STATE.meridiem === 'AM') { if (h24 === 12) h24 = 0; }
        else { if (h24 !== 12) h24 += 12; }
        const timeInput = document.getElementById('mcsTimeInput');
        if (timeInput) {
            timeInput.value = String(h24).padStart(2, '0') + ':' + MCS_TIME_STATE.minute;
            timeInput.dispatchEvent(new Event('change'));
        }
    }
}

function mcsUpdateSummary(){
    const dateVal=document.getElementById('mcsDateInput').value, timeVal=document.getElementById('mcsTimeInput').value;
    const summaryLine=document.getElementById('mcsSummaryLine'), summaryText=document.getElementById('mcsSummaryText');
    const readable=formatScheduleReadable(dateVal,timeVal);
    if(readable){summaryText.textContent='Signing will be set for '+readable;summaryLine.classList.add('show');}
    else summaryLine.classList.remove('show');
}
// (The old quick time-of-day chip click handler was removed along with
// the chips themselves in an earlier revision; the Time step now uses
// the custom digital roll picker built above instead.)
function mcsNextStep(){
    if(_mcsStep===1){
        const dateVal=document.getElementById('mcsDateInput').value;
        if(!dateVal){ showGuardModal('','Date Required','Please select a signing date before continuing.'); return; }
        mcsGoToStep(2);
    } else {
        const dateVal=document.getElementById('mcsDateInput').value, timeVal=document.getElementById('mcsTimeInput').value;
        // ── FIX (this adjustment): this message used to say "pick a quick
        // option or set a custom signing time" — leftover wording from the
        // old chip-based picker that was replaced by the digital roll
        // picker above. It now accurately describes what's actually on
        // screen, so the admin isn't left confused about UI that no
        // longer exists (this fires simply when the admin hasn't finished
        // picking all three of hour/minute/AM-PM yet).
        if(!timeVal){ showGuardModal('','Time Required','Please select an hour, minute, and AM/PM before confirming.'); return; }
        // ── UPDATED (this adjustment): confirming now submits directly —
        // reuses advanceMoaReqStage() (below), passing this modal's own
        // date/time instead of reading a separate per-row hidden input pair.
        advanceMoaReqStage(_mcsCurrentUserId, 'scheduled', document.getElementById('mcsNextBtn'), dateVal, timeVal);
    }
}
document.getElementById('mcsDateInput')?.addEventListener('change', mcsUpdateSummary);
document.getElementById('mcsTimeInput')?.addEventListener('change', mcsUpdateSummary);
document.getElementById('moaCustomScheduleOverlay')?.addEventListener('click', function(e){ if(e.target===this) closeMoaCustomScheduleModal(); });

// ── UPDATED (this adjustment): now also accepts explicit
// scheduleDateOverride/scheduleTimeOverride, sourced from the
// #moaCustomScheduleOverlay modal's own Date/Time step inputs (used only
// when newStage === 'scheduled', i.e. every remaining call to this
// function) instead of a separate per-row hidden date/time input pair —
// there is no longer any other UI on this page that sets a signing
// schedule. The comment box is still read from the row itself (the modal
// intentionally doesn't duplicate it), since it's optional and stays
// visible on the row throughout.
function advanceMoaReqStage(userId, newStage, btn, scheduleDateOverride, scheduleTimeOverride) {
    const reqItem = document.querySelector(`.req-item.moa-req-item[data-uid="${userId}"]`);
    if (!reqItem) return;

    // Comment is attached to the automatic email update sent for every stage change.
    const commentInput = document.getElementById('moaComment_' + userId);
    const comment = commentInput ? commentInput.value.trim() : '';

    // The "Set Signing Schedule" / "Re-Schedule" actions require a date & time, also sent in the email.
    let scheduleDate = scheduleDateOverride || '', scheduleTime = scheduleTimeOverride || '';
    if (newStage === 'scheduled') {
        if (!scheduleDate || !scheduleTime) {
            showGuardModal('','Schedule Required','Please pick a signing <strong>date</strong> and <strong>time</strong>. This will be included in the email sent to the company.');
            return;
        }
    }

    const allBtns = reqItem.querySelectorAll('button, .moa-wf-btn');
    allBtns.forEach(b => b.disabled = true);
    if (btn) btn.disabled = true;
    // ── NEW (this adjustment): visible "processing" feedback for the
    // round-trip to the server — this is the action the delay was most
    // noticeable on (setting/confirming a signing schedule), since the
    // admin previously had no indication anything was happening between
    // clicking "Confirm Schedule" and the modal closing.
    showGlobalLoading(newStage === 'scheduled' ? 'Setting Schedule' : 'Updating');

    const fd = new FormData();
    fd.append('ajax_update_moa_req_workflow','1');
    fd.append('user_id', userId);
    fd.append('stage', newStage);
    fd.append('comment', comment);
    fd.append('moa_undoable','1');   // NEW (this adjustment): the server keeps what's needed to undo this and holds the email back
    if (newStage === 'scheduled') {
        fd.append('schedule_date', scheduleDate);
        fd.append('schedule_time', scheduleTime);
    }

    fetch(SELF, {method:'POST', body:fd})
        .then(r => r.json())
        .then(data => {
            hideGlobalLoading();
            allBtns.forEach(b => b.disabled = false);
            if (btn) btn.disabled = false;
            if (!data.success) {
                if (data.guard === 'already_verified') {
                    showGuardModal('','Already Verified','MOA Document is already marked as Verified.');
                } else {
                    showGuardModal('','Error', data.message || 'Failed to update MOA workflow.');
                }
                return;
            }
            // ── NEW (this adjustment): this is now always the modal's own
            // "Confirm Schedule" step succeeding — close it.
            if (newStage === 'scheduled') closeMoaCustomScheduleModal();
            // Update stepper
            updateMoaTblStepper(userId, data.stage);
            // Update chip
            updateMoaStageChip(userId, data.stage);
            // Update action buttons (also refreshes comment/schedule fields)
            updateMoaWfActionBtns(userId, data.stage, data.schedule_datetime, data.schedule_status);
            // ── NEW (this adjustment): keep the row-summary's granular MOA
            // progress label in sync with the stage that was just saved,
            // without needing a page reload.
            updateRowSummaryMoaProgress(userId, data.stage, false);
            // ── NEW (this adjustment): every (re)schedule resets the
            // company's confirmation back to "awaiting" — reflect that
            // live too, matching what the server just set.
            if (newStage === 'scheduled') {
                updateMoaScheduleConfirmBlock(userId, data.schedule_status || 'pending_confirmation', null, null, data.schedule_datetime);
            }
            // NEW (this adjustment): the undo toast, like a requirement's
            if (data.undo_token) startMoaUndoToast(data.undo_token, data.undo_label, userId, data.undo_action);
        })
        .catch(err => {
            hideGlobalLoading();
            allBtns.forEach(b => b.disabled = false);
            if (btn) btn.disabled = false;
            showGuardModal('','Network Error', err.message || 'Request failed.');
        });
}

// ── "Done" — finalizes verification once signing has been scheduled ──
// ── NEW (this adjustment): "Done" is locked until the signing schedule is finalized — the company representative
// confirmed it ('confirmed'), or the admin accepted the company's proposed schedule ('confirmed_by_admin'). Kept as a
// class + aria-disabled (not the disabled attribute) so the blanket "disable every button / re-enable every button"
// the other MOA actions do can't accidentally unlock it. The server enforces the same rule (ajax_moa_mark_done).
const MOA_DONE_LOCK_TITLE = 'Available once the signing schedule is finalized: confirmed by the company, or accepted by you.';
function moaScheduleIsFinal(status) { return status === 'confirmed' || status === 'confirmed_by_admin'; }
function setMoaDoneLock(btn, locked) {
    if (!btn) return;
    btn.classList.toggle('moa-done-locked', !!locked);
    if (locked) { btn.setAttribute('aria-disabled', 'true'); btn.title = MOA_DONE_LOCK_TITLE; }
    else        { btn.removeAttribute('aria-disabled');      btn.removeAttribute('title'); }
}

function markMoaDone(userId, btn) {
    const reqItem = document.querySelector(`.req-item.moa-req-item[data-uid="${userId}"]`);
    if (!reqItem) return;
    if (btn && btn.classList.contains('moa-done-locked')) {
        showGuardModal('','Signing Schedule Not Finalized','This MOA can only be marked as <strong>Done</strong> once the signing schedule is finalized — the company must <strong>confirm</strong> it, or you must <strong>accept</strong> the schedule they proposed.');
        return;
    }
    const allBtns = reqItem.querySelectorAll('button, .moa-wf-btn');
    allBtns.forEach(b => b.disabled = true);
    showGlobalLoading('Finalizing');

    const fd = new FormData();
    fd.append('ajax_moa_mark_done','1');
    fd.append('user_id', userId);
    fd.append('moa_undoable','1');   // NEW (this adjustment): the server keeps what's needed to undo this

    fetch(SELF, {method:'POST', body:fd})
        .then(r => r.json())
        .then(data => {
            hideGlobalLoading();
            if (!data.success) {
                allBtns.forEach(b => b.disabled = false);
                if (data.guard === 'already_verified') {
                    showGuardModal('','Already Verified','MOA Document is already marked as Verified.');
                } else {
                    showGuardModal('','Error', data.message || 'Failed to mark as done.');
                }
                return;
            }
            // ── UPDATED (this adjustment): the MOA document itself just
            // became Verified here, so it has no more "progress" left to
            // show in the row-summary — unlike updateOverallStatusUI's
            // usual guard (which deliberately leaves a New-table row's
            // granular MOA progress label untouched for an ordinary,
            // non-MOA item save), this specific action must always update
            // that label. It used to fall back to the company's overall
            // status (so a verified MOA still read "Pending" while other
            // requirements were open); the MOA Status column now says
            // "Verified" as soon as the MOA is, matching what a page reload
            // renders server-side. Updated directly here rather than
            // through updateOverallStatusUI so that guard isn't bypassed
            // for every other caller.
            const rowEl = document.querySelector(`.company-row[data-uid="${userId}"]`);
            const summaryCell = rowEl ? rowEl.querySelector('.overall-status-cell') : null;
            if (summaryCell) {
                summaryCell.style.color = '#2C5A2C';
                summaryCell.textContent = '● Verified';
            }
            showMoaVerifiedLock(userId, data.schedule_datetime);
            // ── NEW (this adjustment): "Done" is very often the exact
            // action that pushes a company's overall status over the line
            // into Verified (the MOA was the last thing left) — graduate
            // the row out of the queue the same way updateOverallStatusUI()
            // does for an ordinary item save.
            removeCompanyRowIfVerified(userId, data.overall_status);
            // NEW (this adjustment): the undo toast, like a requirement's
            if (data.undo_token) startMoaUndoToast(data.undo_token, data.undo_label, userId, data.undo_action);
        })
        .catch(err => {
            hideGlobalLoading();
            allBtns.forEach(b => b.disabled = false);
            showGuardModal('','Network Error', err.message || 'Request failed.');
        });
}

function updateMoaTblStepper(userId, currentStage) {
    const stepper = document.getElementById('moaTblStepper_' + userId);
    if (!stepper) return;
    const steps = stepper.querySelectorAll('.moa-tbl-step');
    const currentIdx = MOA_STAGES.indexOf(currentStage);
    steps.forEach((step, i) => {
        step.classList.remove('done','active','verified-step');
        const dot = step.querySelector('.moa-tbl-dot');
        dot.removeAttribute('title');   // NEW (this adjustment): the hourglass tooltip (see cvSyncSchedStepIcon()) only applies while it is shown
        const stepDef = MOA_STEP_DEFS[i];
        if (i < currentIdx) {
            step.classList.add('done');
            dot.innerHTML = '<i class="fas fa-check" style="font-size:10px;"></i>';
        } else if (i === currentIdx) {
            step.classList.add('active');
            dot.textContent = stepDef ? stepDef.icon : (i + 1);
        } else {
            dot.textContent = stepDef ? stepDef.icon : (i + 1);
        }
    });
    // NEW (this adjustment): in the "Scheduling" stage the dot is a rotating hourglass until the schedule is finalized
    if (currentStage === 'scheduled') cvSyncSchedStepIcon(userId, null);
}

// NEW (this adjustment): the "Scheduling" step's dot while the MOA is in that stage — a rotating hourglass while the signing
// schedule is NOT finalized (awaiting the company, or the company proposed a different date), the a check mark once it is (confirmed by
// the company, or accepted by the admin: moaScheduleIsFinal(), the same rule as the Done button's lock). Called with the
// schedule's status when it is known (updateMoaScheduleConfirmBlock()); with null it works the status out from the schedule
// panel that is on screen. The server draws the same thing on page load (see the stepper in renderCompanyValidationRow()).
function cvSyncSchedStepIcon(userId, scheduleStatus) {
    const stepper = document.getElementById('moaTblStepper_' + userId);
    if (!stepper) return;
    const steps = stepper.querySelectorAll('.moa-tbl-step');
    const step = steps[steps.length - 1];
    if (!step || !step.classList.contains('active')) return;   // only while the MOA is in the Scheduling stage
    const dot = step.querySelector('.moa-tbl-dot');
    if (!dot) return;
    let isFinal;
    if (scheduleStatus == null) {
        const panel = document.getElementById('moaScheduleConfirmBlock_' + userId);
        isFinal = !!(panel && panel.querySelector('.moa-sched-panel-header.confirmed'));
    } else {
        isFinal = moaScheduleIsFinal(scheduleStatus);
    }
    if (isFinal) {
        dot.textContent = '3';   // UPDATED (this adjustment — no emoji): the finalized dot shows its own number
        dot.removeAttribute('title');
    } else {
        dot.innerHTML = '<i class="fas fa-hourglass-half moa-dot-hourglass"></i>';
        dot.title = 'Waiting for the signing schedule to be finalized';
    }
}

function updateMoaStageChip(userId, stage) {
    const reqItem = document.querySelector(`.req-item.moa-req-item[data-uid="${userId}"]`);
    if (!reqItem) return;
    cvSetMoaTabChip(userId, stage);   // NEW: keep the "MOA Workflow" tab chip in step
    // UPDATED (this adjustment): there is no stage chip under the MOA Document title any more (see the MOA card markup), so
    // there is nothing else to update here. This deliberately no longer touches ".moa-stage-chip" — the card no longer has one at
    // all (the red "Needs Revision" chip that used to be the last one was removed as redundant).
}

// ── UPDATED (this adjustment): the old inline quick-chip schedule panel
// (built as an HTML string and inserted/removed on every stage change) is
// gone entirely — "Set Signing Schedule" / "Re-Schedule" both now open
// the shared #moaCustomScheduleOverlay modal directly (see
// openMoaCustomScheduleModal() further down), which submits straight to
// the server itself instead of writing into a per-row hidden input pair
// first. buildMoaSchedulePanelHtml() is retired along with it.

// ── NEW (this adjustment): live counterpart to the server-rendered
// "SIGNING SCHEDULE CONFIRMATION" block (see renderCompanyValidationRow()
// PHP-side) — creates the block if this is the row's first time entering
// the "scheduled" stage, or updates it in place otherwise, so the admin
// sees the confirmation status change with no reload needed.
// NEW (this adjustment): date/time formatting for the panel (same shapes as the PHP-rendered panel:
// 'M d, Y g:i A' for the short form, 'l, F j, Y \a\t g:i A' for the long form).
function formatMoaSchedShort(s) {
    const d = new Date(String(s).replace(' ', 'T'));
    if (isNaN(d.getTime())) return String(s);
    return d.toLocaleDateString('en-US', {month:'short'}) + ' ' + String(d.getDate()).padStart(2, '0') + ', ' + d.getFullYear() + ' ' + d.toLocaleTimeString('en-US', {hour:'numeric', minute:'2-digit'});
}
function formatMoaSchedLong(s) {
    const d = new Date(String(s).replace(' ', 'T'));
    if (isNaN(d.getTime())) return String(s);
    return d.toLocaleString('en-US', {weekday:'long', year:'numeric', month:'long', day:'numeric', hour:'numeric', minute:'2-digit'});
}
function updateMoaScheduleConfirmBlock(userId, scheduleStatus, declineReason, proposedDateTimeReadable, scheduleDateTime) {
    const wfSection = document.querySelector(`.req-item.moa-req-item[data-uid="${userId}"] .moa-req-workflow-section`);
    if (!wfSection) return;
    // NEW (this adjustment): the schedule panel now lives top-right of the whole MOA card (see .moa-sched-top-right in the CSS)
    const cardForPanel = wfSection.closest('.req-item.moa-req-item');
    if (cardForPanel) cardForPanel.classList.add('moa-sched-top-right');
    let block = document.getElementById('moaScheduleConfirmBlock_' + userId);
    if (!block) {
        block = document.createElement('div');
        block.className = 'moa-sched-confirm-block';
        block.id = 'moaScheduleConfirmBlock_' + userId;
        const actionsDiv = document.getElementById('moaWfActions_' + userId);
        if (actionsDiv) wfSection.insertBefore(block, actionsDiv);
        else wfSection.appendChild(block);
    }

    let html = '';
    // the scheduled date/time, shown inside the panel (the old "Signing currently scheduled for…" line is gone)
    const schedDtHtml = scheduleDateTime ? `<p class="moa-sched-panel-datetime">${escapeHtml(formatMoaSchedLong(scheduleDateTime))}</p>` : '';
    // NEW (this adjustment): kept in sync with the PHP-rendered version in renderCompanyValidationRow() — same
    // .moa-sched-panel design as CompanyForm.php's schedule confirmation panel.
    if (scheduleStatus === 'confirmed') {
        html = `<div class="moa-sched-panel-header confirmed">Company representative confirmed this signing schedule</div>${schedDtHtml}`;
    } else if (scheduleStatus === 'confirmed_by_admin') {
        // ── NEW (this adjustment): kept in sync with the PHP-rendered
        // version in renderCompanyValidationRow() — this status means the
        // ADMIN accepted the company's own counter-proposal, not the
        // company agreeing to anything, so it gets distinct wording.
        html = `<div class="moa-sched-panel-header confirmed">You accepted the company's proposed schedule</div>${schedDtHtml}`;
    } else if (scheduleStatus === 'declined') {
        // "Originally proposed" = the schedule the admin set (shown below the header sentence)
        const origHtml = scheduleDateTime ? `<p class="moa-sched-panel-datetime moa-sched-strike">Originally proposed: ${escapeHtml(formatMoaSchedShort(scheduleDateTime))}</p>` : '';
        const reasonHtml = declineReason ? `<p class="moa-sched-panel-note">Company's reason: "${escapeHtml(declineReason)}"</p>` : '';
        const proposedHtml = proposedDateTimeReadable ? `<p class="moa-sched-panel-note">Company's proposed schedule: <strong>${escapeHtml(proposedDateTimeReadable)}</strong></p>` : '';
        html = `<div class="moa-sched-panel-header declined"><span class="moa-sched-panel-title">Company proposed a different schedule</span></div>${origHtml}${reasonHtml}${proposedHtml}<p class="moa-sched-panel-hint">Waiting for you to review the company's proposed schedule.</p>`;
    } else {
        html = `<div class="moa-sched-panel-header pending"><i class="fas fa-calendar-check"></i> Signing schedule proposed to the company</div>${schedDtHtml}<p class="moa-sched-panel-hint">Awaiting company confirmation.</p>`;
    }
    block.innerHTML = `<div class="moa-sched-panel">${html}</div>`;
    cvSyncSchedStepIcon(userId, scheduleStatus);   // NEW (this adjustment): hourglass until the schedule is finalized, a check mark once it is

    // NEW (this adjustment): keep the action row in step with the confirmation status — "Done" is locked until the
    // schedule is finalized, and "Accept Proposed Schedule" sits in the same row as Done / Re-Schedule.
    const wfActionsRow = document.getElementById('moaWfActions_' + userId);
    if (wfActionsRow) {
        setMoaDoneLock(wfActionsRow.querySelector('.done-btn'), !moaScheduleIsFinal(scheduleStatus));
        let acceptBtn = wfActionsRow.querySelector('.moa-accept-proposed-btn');
        if (scheduleStatus === 'declined' && proposedDateTimeReadable) {
            if (!acceptBtn) {
                acceptBtn = document.createElement('button');
                acceptBtn.type = 'button';
                acceptBtn.className = 'moa-wf-btn schedule moa-accept-proposed-btn';
                acceptBtn.setAttribute('onclick', `acceptProposedSchedule(${userId})`);
                acceptBtn.innerHTML = '<i class="fas fa-check"></i> Accept Proposed Schedule';
                wfActionsRow.appendChild(acceptBtn);   // to the right of Re-Schedule
            }
        } else if (acceptBtn) {
            acceptBtn.remove();
        }
    }
}

function updateMoaWfActionBtns(userId, stage, scheduleDateTime, scheduleStatus) {
    const actionsDiv = document.getElementById('moaWfActions_' + userId);
    if (!actionsDiv) return;

    let html = '';
    // ── UPDATED (this adjustment): the merged "Pending for Review" stage
    // now shows a single "Review MOA" button that opens the review/flag
    // preview modal; "Approve MOA" and "Send & Request Revision" both
    // live inside that modal now instead of as separate direct buttons.
    // "Set Signing Schedule" / "Re-Schedule" now both open the same
    // shared 2-step calendar modal directly, instead of toggling an
    // inline quick-chip panel.
    if (stage === 'pending') {
        html = `<button class="moa-wf-btn review" onclick="openMoaTableFlagModal(${userId})"><i class="fas fa-search"></i> Review MOA</button>`;
    } else if (stage === 'approved') {
        html = `<button class="moa-wf-btn schedule" onclick="openMoaCustomScheduleModal(${userId})"><i class="fas fa-calendar-check"></i> Set Signing Schedule</button>`;
    } else if (stage === 'scheduled') {
        // NEW (this adjustment): a fresh (re)schedule is always "awaiting company confirmation", so Done starts locked.
        const doneLocked = !moaScheduleIsFinal(scheduleStatus || 'pending_confirmation');
        html = `<button class="moa-wf-btn done-btn${doneLocked ? ' moa-done-locked' : ''}" onclick="markMoaDone(${userId},this)"${doneLocked ? ` aria-disabled="true" title="${MOA_DONE_LOCK_TITLE}"` : ''}><i class="fas fa-check-double"></i> Done</button>
                <button class="moa-wf-btn resched-btn" onclick="openMoaCustomScheduleModal(${userId})"><i class="fas fa-redo"></i> Re-Schedule</button>`;
    }
    actionsDiv.innerHTML = html;

    // Clear the comment box after each successful send so old comments aren't resent by mistake
    const commentInput = document.getElementById('moaComment_' + userId);
    if (commentInput) commentInput.value = '';
}

function showMoaVerifiedLock(userId, scheduleDateTime) {
    const reqItem = document.querySelector(`.req-item.moa-req-item[data-uid="${userId}"]`);
    if (!reqItem) return;
    cvSetMoaTabChip(userId, 'verified');   // NEW: keep the "MOA Workflow" tab chip in step
    reqItem.classList.remove('moa-sched-top-right');   // NEW (this adjustment): the workflow section is hidden below, so the card returns to its normal layout
    // Update stepper to show final check
    const stepper = document.getElementById('moaTblStepper_' + userId);
    if (stepper) {
        const steps = stepper.querySelectorAll('.moa-tbl-step');
        steps.forEach((step, i) => {
            step.classList.remove('active');
            step.classList.add(i < steps.length - 1 ? 'done' : 'verified-step');
            const dot = step.querySelector('.moa-tbl-dot');
            dot.innerHTML = '<i class="fas fa-check" style="font-size:10px;"></i>';
        });
    }
    // Hide workflow section
    const wfSection = reqItem.querySelector('.moa-req-workflow-section');
    if (wfSection) wfSection.style.display = 'none';
    // Replace status chip with lock message
    const inner = reqItem.querySelector('.moa-req-inner');
    if (!inner) return;
    const chip = inner.querySelector('.moa-stage-chip');
    if (chip) {
        chip.innerHTML = '';
        chip.style.cssText = '';
    }
    // Insert verified lock
    const infoDiv = inner.querySelector('div[style*="flex:1"]');
    if (infoDiv) {
        infoDiv.querySelectorAll('.req-awaiting-ui,.verified-lock').forEach(el=>el.remove());
        inner.querySelectorAll(':scope > .verified-lock').forEach(el=>el.remove());   // NEW (this adjustment): the bar now lives beside the info block, not inside it
        let scheduleLabel = '';
        if (scheduleDateTime) {
            const d = new Date(scheduleDateTime.replace(' ', 'T'));
            if (!isNaN(d.getTime())) {
                scheduleLabel = ' <strong style="margin-left:4px;">(' + d.toLocaleString('en-US', {month:'short',day:'numeric',year:'numeric',hour:'numeric',minute:'2-digit'}) + ')</strong>';
            }
        }
        const lockDiv = document.createElement('div');
        lockDiv.className = 'verified-lock';
        lockDiv.innerHTML = '<i class="fas fa-check-circle"></i> MOA Document Verified — Signing Scheduled ✓' + scheduleLabel;
        // UPDATED (this adjustment): the bar goes on the same row as the thumbnail + MOA Document title (same markup as the PHP render), not under the title.
        inner.classList.add('moa-verified-row');
        inner.appendChild(lockDiv);
    }
    // Remove view-doc eye button
    const eyeBtn = inner.querySelector('.view-doc');
    if (eyeBtn) eyeBtn.style.display = 'none';
}

// ── AJAX Requirement Form Submit (non-MOA)
document.addEventListener('submit', function(e) {
    const form = e.target.closest('.ajax-req-form'); if (!form) return;
    e.preventDefault();
    const uid=form.dataset.uid,req=form.dataset.req,status=form.querySelector('[name="status"]').value,remark=(form.querySelector('[name="remark"]')?.value||'').replace(/\s+/g,' ').trim();
    // NEW (this adjustment): rejecting needs a remark — say so right away instead of round-tripping to the server.
    if(status==='Rejected'&&remark===''){const ri=form.querySelector('[name="remark"]');if(ri){ri.style.display='inline-block';ri.focus();}showGuardModal('','Remark required','Please enter a remark explaining why this requirement is being rejected.');return;}
    const saveBtn=form.querySelector('button[type="submit"]');saveBtn.disabled=true;saveBtn.textContent='…';
    const fd=new FormData();fd.append('ajax_save_requirement','1');fd.append('user_id',uid);fd.append('requirement_type',req);fd.append('status',status);
    if(status==='Rejected')fd.append('remark',remark);
    fetch(SELF,{method:'POST',body:fd}).then(r=>r.json()).then(data=>{
        saveBtn.disabled=false;saveBtn.textContent='Save';
        if(!data.success){if(data.guard==='already_verified')showGuardModal('','Already Verified','The requirement <strong>'+data.label+'</strong> is already marked as <strong>Verified</strong>.');else if(data.guard==='no_submission')showGuardModal('','No Submission Yet','No submission for <strong>'+data.label+'</strong> yet.');else showGuardModal('','Error',data.message||'Something went wrong.');return;}
        updateReqItemUI(uid,req,data.new_status,data.new_remark);updateOverallStatusUI(uid,data.overall_status);
        startUndoToast(data.undo_token,data.label,uid,req,data.new_status,data.new_remark,data.is_denied);
    }).catch(err=>{saveBtn.disabled=false;saveBtn.textContent='Save';showGuardModal('','Network Error','Could not save.<br><small>'+err.message+'</small>');});
});

// ════════════════════════════════════════════════════════════════════════
//  NEW (this adjustment) — EXTENDED PANEL "Tabs + Document Gallery" helpers.
//  Pure UI plumbing for the new tab bar / summary strip; none of it touches
//  any save, verify, deny, undo or MOA-workflow logic.
// ════════════════════════════════════════════════════════════════════════
// Activates one tab of a row's panel. `scope` may be the .cv-panel itself or
// any ancestor (e.g. the .company-row). Returns false if that tab doesn't
// exist for this row (e.g. 'moa' on an "Existing"-table row).
function cvActivateTab(scope, key){
    if(!scope) return false;
    const panel=(scope.classList&&scope.classList.contains('cv-panel'))?scope:scope.querySelector('.cv-panel');
    if(!panel) return false;
    const tab=panel.querySelector('.cv-tab[data-tab="'+key+'"]');
    if(!tab) return false;
    panel.querySelectorAll('.cv-tab').forEach(t=>{const on=(t===tab);t.classList.toggle('active',on);t.setAttribute('aria-selected',on?'true':'false');});
    panel.querySelectorAll('.cv-tabpanel').forEach(p=>{p.classList.toggle('active',p.getAttribute('data-tabpanel')===key);});
    return true;
}
function cvSelectTab(btn){
    const panel=btn.closest('.cv-panel');
    if(panel) cvActivateTab(panel, btn.getAttribute('data-tab'));
}
// Recounts the gallery cards of one company row and refreshes the summary
// line, progress bar and the "3 / 10" count on the Requirements tab.
function cvRefreshReqSummary(uid){
    const row=document.querySelector(`.company-row[data-uid="${uid}"]`); if(!row) return;
    const cards=row.querySelectorAll('.cv-gallery .req-item[data-state]');
    let v=0,p=0,a=0;
    cards.forEach(c=>{const s=c.getAttribute('data-state');if(s==='verified')v++;else if(s==='awaiting')a++;else p++;});
    const total=cards.length,pct=total?Math.round(v/total*100):0;
    const set=(sel,txt)=>{const el=row.querySelector(sel);if(el)el.textContent=txt;};
    set('.cv-n-total',total);set('.cv-n-verified',v);set('.cv-n-pending',p);set('.cv-n-awaiting',a);
    set('.cv-progress-pct',pct+'% verified');set('.cv-tab-count',v+' / '+total);
    const fill=row.querySelector('.cv-progress-fill'); if(fill) fill.style.width=pct+'%';
    // NEW (this adjustment): keep the Requirement Status column's percent ring + "X of Y verified / N pending · N awaiting"
    // breakdown in step (only rows that carry data-vpct — i.e. the Existing table's, and the New table's Requirement Status cell — have them).
    const vsCell=row.querySelector('.overall-status-cell[data-vpct]');
    if(vsCell&&total>0){
        const l1=(v===total)?('All '+total+' verified'):(v+' of '+total+' verified');
        const rest=[]; if(p>0) rest.push(p+' pending'); if(a>0) rest.push(a+' awaiting');
        vsCell.setAttribute('data-vpct',pct);
        vsCell.style.setProperty('--vs-pct',pct);
        vsCell.setAttribute('data-vinfo',rest.length?(l1+'\n'+rest.join(' · ')):l1);
        // NEW (this adjustment): a "New" row's Requirement Status cell (data-reqcell) also carries its own real text — "Verified" once
        // every requirement is — which the status filter reads. (An "Existing" row's cell text is the overall status; it is left alone.)
        if(vsCell.hasAttribute('data-reqcell')){vsCell.style.color=(v===total)?'#2C5A2C':'#8C6C00';vsCell.textContent='● '+((v===total)?'Verified':'Pending');}
    }
}
// Keeps a gallery card's data-state (which drives its status ribbon) in step
// with what updateReqItemUI() just did to the card. A committed "Denied"
// clears the file server-side, so it reads as "awaiting the company" here —
// the same way updateReqItemUI() already presents it.
function cvSyncReqCard(reqItem, newStatus, uid){
    // UPDATED (this adjustment): a rejected requirement is "awaiting the company" (its file is removed and it needs a
    // new upload), so it keeps the 'awaiting' state — the counts / ring are unchanged. "Rejected" is its own label,
    // driven by data-rejected (see cvSetRejectedDisplay() below).
    const state=(newStatus==='Verified')?'verified':((newStatus==='Rejected'||newStatus==='Denied')?'awaiting':'pending');
    reqItem.setAttribute('data-state',state);
    cvRefreshReqSummary(uid);
}
// NEW (this adjustment): puts a gallery card into (or takes it out of) its REJECTED look: the "Rejected" pill replaces the
// Pending / Awaiting pill, the previously uploaded file's preview is replaced by the "Rejected — awaiting re-upload"
// tile, and the remark is shown below the requirement name. All of it is pure CSS keyed off data-rejected, so undoing a
// rejection just flips the attribute back and the original preview is exactly as it was.
function cvSetRejectedDisplay(reqItem, on, remark){
    if(!reqItem||!reqItem.classList.contains('cv-req-card')) return;
    reqItem.setAttribute('data-rejected', on?'1':'0');
    if(on){
        const t=reqItem.querySelector('.cv-card-remark-text');
        const r=(remark==null)?'':String(remark).trim();
        if(t) t.textContent=r!==''?r:'—';
    }
}
// Mirrors the MOA card's stage onto the small chip on the "MOA Workflow" tab.
// UPDATED (this adjustment): the pending and approved stages show no chip on the tab any more (scheduled never did) —
// the stepper in the MOA panel shows the stage. UPDATED (this adjustment): the "Verified" chip is gone too, so this live updater no longer
// puts any chip on the tab (see the PHP side for the Awaiting chip, which the server renders; the "Needs revision" chip no longer exists).
const CV_MOA_TAB_CHIP_MAP={pending:['review',''],approved:['approved',''],scheduled:['scheduled',''],verified:['verified','']};
function cvSetMoaTabChip(uid, stage){
    const row=document.querySelector(`.company-row[data-uid="${uid}"]`); if(!row) return;
    const chip=row.querySelector('.cv-moa-tab-chip'); if(!chip) return;
    const m=CV_MOA_TAB_CHIP_MAP[normalizeMoaStage(stage)]||CV_MOA_TAB_CHIP_MAP.pending;
    chip.className='cv-tab-chip cv-moa-tab-chip '+m[0];
    chip.textContent=m[1];
    chip.style.display=m[1]?'':'none';   // the pending / approved / scheduled stages show no chip on the tab
}

function updateReqItemUI(uid,req,newStatus,newRemark){
    const reqItem=document.querySelector(`.req-item[data-uid="${uid}"][data-req="${req}"]`);if(!reqItem)return;
    const isRejected=(newStatus==='Rejected'||newStatus==='Denied');   // NEW (this adjustment): "Denied" is now called "Rejected" (old value still understood)
    const isGalleryCard=reqItem.classList.contains('cv-req-card');   // gallery cards no longer carry the "Already verified" / "Awaiting company submission" labels
    const verifiedBadge=reqItem.querySelector('.verified-badge'),lockUi=reqItem.querySelector('.req-lock-ui'),awaitingUi=reqItem.querySelector('.req-awaiting-ui'),form=reqItem.querySelector('.ajax-req-form'),feedback=reqItem.querySelector('.req-save-feedback');
    if(isRejected){reqItem.classList.add('denied-pending');setTimeout(()=>{reqItem.classList.remove('denied-pending');},1000);}
    if(newStatus==='Verified'){if(verifiedBadge)verifiedBadge.style.display='';if(form)form.style.display='none';if(awaitingUi)awaitingUi.style.display='none';if(!lockUi){if(!isGalleryCard){const d=document.createElement('div');d.className='verified-lock req-lock-ui';d.innerHTML='<i class="fas fa-check-circle"></i> Already verified — no further changes allowed';reqItem.querySelector('div[style*="flex:1"]').appendChild(d);}}else lockUi.style.display='flex';}
    else if(isRejected){if(verifiedBadge)verifiedBadge.style.display='none';if(lockUi)lockUi.style.display='none';if(form)form.style.display='none';if(!awaitingUi){if(!isGalleryCard){const d=document.createElement('div');d.className='req-awaiting-ui';d.innerHTML='<i class="fas fa-hourglass-half"></i> Awaiting company submission';reqItem.querySelector('div[style*="flex:1"]').appendChild(d);}}else awaitingUi.style.display='flex';}
    else{if(verifiedBadge)verifiedBadge.style.display='none';if(lockUi)lockUi.style.display='none';if(awaitingUi)awaitingUi.style.display='none';if(form){form.style.display='';const ss=form.querySelector('[name="status"]');if(ss)ss.value=newStatus;const rs=form.querySelector('[name="remark"]');if(rs){rs.style.display=isRejected?'inline-block':'none';if(newRemark)rs.value=newRemark;}}}
    cvSetRejectedDisplay(reqItem,isRejected,newRemark);   // NEW (this adjustment): Rejected pill + cleared preview + remark under the name
    cvSyncReqCard(reqItem,newStatus,uid);
    if(feedback){feedback.textContent='✓ Saved';feedback.className='req-save-feedback success show';setTimeout(()=>{feedback.className='req-save-feedback';},2000);}
}
// ════════════════════════════════════════════════════════════════════════
//  NEW (this adjustment) — a company whose overall status just became
//  Verified (every requirement, including the MOA, is done) "graduates"
//  out of this validation queue entirely and moves into
//  admin_company_list.php's own company roster instead (see that file's
//  matching filter — it now shows ONLY Verified registered companies, the
//  mirror image of the main $companies query here excluding them). This
//  removes the row from the table live, with a short fade/collapse
//  animation, decrements the section's count badge, re-runs filterAll()
//  so pagination immediately accounts for one fewer row, and shows a
//  toast confirming what happened — no reload needed anywhere in this
//  flow. Safe to call unconditionally (does nothing unless overallStatus
//  is actually 'Verified', or the row isn't currently on the page).
// ════════════════════════════════════════════════════════════════════════
function removeCompanyRowIfVerified(uid, overallStatus){
    if (overallStatus !== 'Verified') return;
    const rowEl = document.querySelector(`.company-row[data-uid="${uid}"]`);
    if (!rowEl) return;

    const companyName = rowEl.querySelector('.row-summary span')?.textContent || 'This company';
    const inExisting = !!document.getElementById('existingCompanyWrapper')?.contains(rowEl);
    const inNew = !!document.getElementById('newCompanyWrapper')?.contains(rowEl);

    rowEl.style.transition = 'opacity 0.4s ease, max-height 0.4s ease, margin 0.4s ease, padding 0.4s ease';
    rowEl.style.maxHeight = rowEl.offsetHeight + 'px';
    rowEl.style.overflow = 'hidden';
    requestAnimationFrame(() => {
        rowEl.style.opacity = '0';
        rowEl.style.maxHeight = '0px';
        rowEl.style.marginTop = '0px';
        rowEl.style.marginBottom = '0px';
        rowEl.style.paddingTop = '0px';
        rowEl.style.paddingBottom = '0px';
    });

    setTimeout(() => {
        rowEl.remove();
        const badgeId = inExisting ? 'existingCountBadge' : (inNew ? 'newCountBadge' : null);
        if (badgeId) {
            const badge = document.getElementById(badgeId);
            if (badge) badge.textContent = Math.max(0, (parseInt(badge.textContent, 10) || 0) - 1);
        }
        // Re-scan rows so pagination/search immediately account for the
        // row that just disappeared — same call liveInsertOrUpdateCompanyRow()
        // already uses after inserting a row.
        filterAll();
    }, 420);

    showCompanyVerifiedToast(companyName);
}

// ── NEW (this adjustment): confirms a company's graduation out of this
// queue. UPDATED (this adjustment): now shown at the TOP of the page instead of the bottom — the same .cv-top-toast class
// and cvLayoutTopToasts() stacking every other notification toast on this page already uses (see the big comment above
// .cv-top-toast's own CSS rule for the full story of that move), so it appears in the same place, stacks the same way
// below the undo panel and any other toast already showing, and looks identical (that CSS rule already styles both
// classes the same way) — only WHERE it appears has changed. The message, icon, and 6-second display time are unchanged.
function showCompanyVerifiedToast(companyName){
    const div = document.createElement('div');
    div.className = 'cv-top-toast';
    div.setAttribute('role', 'status');
    div.innerHTML = '<i class="fas fa-trophy"></i><span><strong>'+escapeHtml(companyName)+'</strong> has completed all requirements and moved to the Company List.</span>';
    document.body.appendChild(div);
    cvLayoutTopToasts();
    requestAnimationFrame(()=>{ div.classList.add('show'); });
    setTimeout(()=>{
        div.classList.remove('show');
        setTimeout(()=>{ div.remove(); cvLayoutTopToasts(); }, 400);
    }, 6000);
}

function updateOverallStatusUI(uid,overallStatus){
    const row=document.querySelector(`.company-row[data-uid="${uid}"]`);
    // ── NEW (this adjustment): the row is missing here when it already "graduated" out of the queue — removeCompanyRowIfVerified()
    // took it out the moment its last requirement was verified (see below). If overallStatus is no longer Verified, that verification
    // has just been UNDONE, so the company belongs back in the queue: pull it in live (liveInsertOrUpdateCompanyRow(), the same fetch
    // the MOA-undo path already uses for this) instead of leaving the admin to reload the page to see it return. If overallStatus is
    // still Verified there's nothing to do — the row is correctly gone already.
    if(!row){ if(overallStatus!=='Verified') liveInsertOrUpdateCompanyRow(uid); return; }
    const cell=row.querySelector('.overall-status-cell');if(!cell)return;

    // ── UPDATED (this adjustment): a "New" MOA-workflow row's summary now
    // shows granular MOA progress (Pending for Review / MOA Approved /
    // Scheduling / MOA Revision Required) instead of the plain binary status — see
    // renderCompanyValidationRow() server-side. Saving an ORDINARY
    // (non-MOA) requirement item for such a row must never clobber that
    // label back to plain "Pending" here — only the terminal "Verified"
    // state (everything, including the MOA, is done) should ever
    // overwrite it through this path. A dedicated MOA action
    // (Approve/Schedule/Done) keeps this cell in sync through its own
    // path instead — see updateRowSummaryMoaProgress() below.
    const isNewMoaRow = !!row.querySelector('.moa-req-item');
    if (isNewMoaRow && overallStatus !== 'Verified') return;

    cell.style.color=(overallStatus==='Verified')?'#2C5A2C':'#8C6C00';
    cell.textContent='● '+overallStatus;

    // ── NEW (this adjustment): once a company's overall status becomes
    // Verified via an ordinary requirement save (or an undo that restores
    // it to Verified), they "graduate" out of this validation queue —
    // see removeCompanyRowIfVerified()'s own docblock below for the full
    // explanation, and the main $companies query's matching filter.
    removeCompanyRowIfVerified(uid, overallStatus);
}

// ── NEW (this adjustment): keeps a "New" MOA-workflow row's summary cell
// (see renderCompanyValidationRow()'s server-side granular-progress logic)
// in sync the instant an MOA-specific action (Approve / Schedule / Flag
// for Revision) succeeds, without needing a page reload. Mirrors the
// exact same stage → label/color mapping used server-side.
const MOA_ROW_SUMMARY_PROGRESS = {
    pending:   ['Pending for Review',  '#8C6C00'],
    approved:  ['MOA Approved',        '#2C5A2C'],
    scheduled: ['Scheduling',          '#1B2A4A'],   // UPDATED (this adjustment): was "Scheduled for Signing"
};
function updateRowSummaryMoaProgress(uid, stage, needsRevision){
    const row=document.querySelector(`.company-row[data-uid="${uid}"]`);if(!row)return;
    const cell=row.querySelector('.overall-status-cell');if(!cell)return;

    if (needsRevision) {
        cell.style.color = '#A02A2A';
        cell.textContent = '● MOA Revision Required';
        return;
    }
    const stageKey = (stage === 'reviewing') ? 'pending' : (stage || 'pending');
    const picked = MOA_ROW_SUMMARY_PROGRESS[stageKey] || MOA_ROW_SUMMARY_PROGRESS.pending;
    cell.style.color = picked[1];
    cell.textContent = '● ' + picked[0];
}

// ── Undo Toast
const UNDO_DURATION=300;let undoToken=null,undoCountdown=0,undoTimer=null,_undoUid=null,_undoReq=null,_undoIsDenied=false;
const ring=document.getElementById('undoRingProgress'),ringCircumference=88;
function startUndoToast(token,label,uid,req,newStatus,newRemark,isDenied){
    if(undoToken&&undoToken!==token)_finalizeCurrentUndo();
    undoToken=token;undoCountdown=UNDO_DURATION;_undoUid=uid;_undoReq=req;_undoIsDenied=!!isDenied;
    document.getElementById('undoToastLabel').textContent=label;
    document.getElementById('undoBtnMain').disabled=false;document.getElementById('undoBtnMain').textContent='Undo';
    const ringEl=document.getElementById('undoRingProgress'),numEl=document.getElementById('undoCountNum'),toast=document.getElementById('undoToast');
    if(isDenied){ringEl.style.stroke='#ef4444';numEl.style.color='#ef4444';toast.style.borderColor='#ef4444';}else{ringEl.style.stroke='var(--neust-gold)';numEl.style.color='var(--neust-gold)';toast.style.borderColor='';}
    toast.classList.add('show');clearInterval(undoTimer);updateRing();
    undoTimer=setInterval(()=>{undoCountdown--;updateRing();if(undoCountdown<=0)dismissUndo();},1000);
}
function updateRing(){ring.style.strokeDashoffset=ringCircumference*(1-undoCountdown/UNDO_DURATION);const m=Math.floor(undoCountdown/60),s=undoCountdown%60;document.getElementById('undoCountNum').textContent=m+':'+String(s).padStart(2,'0');}
function dismissUndo(){clearInterval(undoTimer);const toast=document.getElementById('undoToast');toast.classList.remove('show');toast.style.borderColor='';document.getElementById('undoRingProgress').style.stroke='var(--neust-gold)';document.getElementById('undoCountNum').style.color='var(--neust-gold)';if(undoToken)_finalizeCurrentUndo();}
function _finalizeCurrentUndo(){const token=undoToken,isDenied=_undoIsDenied,kind=_undoKind;undoToken=null;_undoUid=null;_undoReq=null;_undoIsDenied=false;cvUndoResetMoaLook();if(!token)return;const fd=new FormData();if(kind==='moa')fd.append('ajax_moa_commit','1');else if(isDenied)fd.append('ajax_commit_denied','1');else fd.append('ajax_confirm_send','1');fd.append('undo_token',token);fetch(SELF,{method:'POST',body:fd}).catch(()=>{});}
function triggerUndo(){if(!undoToken)return;const btn=document.getElementById('undoBtnMain');btn.disabled=true;btn.textContent='…';const token=undoToken,kind=_undoKind,moaUid=_undoMoaUid;const fd=new FormData();fd.append(kind==='moa'?'ajax_moa_undo':'ajax_undo','1');fd.append('undo_token',token);fetch(SELF,{method:'POST',body:fd}).then(r=>r.json()).then(data=>{clearInterval(undoTimer);const toast=document.getElementById('undoToast');toast.classList.remove('show');toast.style.borderColor='';document.getElementById('undoRingProgress').style.stroke='var(--neust-gold)';document.getElementById('undoCountNum').style.color='var(--neust-gold)';undoToken=null;_undoUid=null;_undoReq=null;_undoIsDenied=false;cvUndoResetMoaLook();if(data.success){if(kind==='moa')cvAfterMoaUndo(data,moaUid);else{updateReqItemUI(data.user_id,data.requirement_type,data.new_status,data.new_remark);updateOverallStatusUI(data.user_id,data.overall_status);}}else showGuardModal('','Undo Failed',data.message||'Could not undo.');}).catch(()=>{btn.disabled=false;btn.textContent='Undo';showGuardModal('','Network Error','Could not connect.');});}

// ── NEW (this adjustment): the SAME undo toast, now for MOA processes too. Approve MOA, Set / Re-Schedule, Accept Proposed
// Schedule, Done and Send & Request Revision each start it (startMoaUndoToast) with the token the server returned; it
// behaves exactly like the requirements' toast — 5:00 countdown, "Undo", dismiss — but talks to the MOA endpoints
// (ajax_moa_undo / ajax_moa_commit) instead. Only ONE toast is ever active: starting a new one (of either kind) first
// commits the previous, which is when its held-back email goes out. A revision request shows the red variant, like a
// rejected requirement does.
let _undoKind='req',_undoMoaUid=null;
function cvUndoResetMoaLook(){
    _undoKind='req'; _undoMoaUid=null;
    const h=document.getElementById('undoToastHeading'); if(h)h.textContent='Status updated';
    const t=document.getElementById('undoToast'); if(t)t.classList.remove('moa-kind');
}
function startMoaUndoToast(token,label,uid,action){
    if(!token)return;
    if(undoToken&&undoToken!==token)_finalizeCurrentUndo();
    _undoKind='moa'; _undoMoaUid=uid;
    const h=document.getElementById('undoToastHeading'); if(h)h.textContent='MOA updated';
    const t=document.getElementById('undoToast'); if(t)t.classList.add('moa-kind');
    startUndoToast(token,label,uid,'moa_'+(action||'update'),null,null,action==='flag');
}
// The server put the MOA back — redraw that company's row from the server so every part of it (stepper, buttons, notes,
// status, and the row itself if "Done" had graduated it out of the queue) shows the restored state.
function cvAfterMoaUndo(data,uid){ liveInsertOrUpdateCompanyRow((data&&data.user_id)||uid); }
// If the page is closed / left while a MOA toast is still counting down, the undo window is over — release the held-back email.
window.addEventListener('pagehide',function(e){
    if(e&&e.persisted)return;
    if(undoToken&&_undoKind==='moa'&&navigator.sendBeacon){
        try{ const fd=new FormData(); fd.append('ajax_moa_commit','1'); fd.append('undo_token',undoToken); navigator.sendBeacon(SELF,fd); }catch(err){}
    }
});
// On load, anything held back whose window has passed (e.g. the tab was closed) is sent / forgotten.
document.addEventListener('DOMContentLoaded',()=>{const fd=new FormData();fd.append('ajax_moa_flush_emails','1');fetch(SELF,{method:'POST',body:fd}).catch(()=>{});});

document.addEventListener('DOMContentLoaded',()=>{const fd=new FormData();fd.append('ajax_flush_emails','1');fetch(SELF,{method:'POST',body:fd}).catch(()=>{});filterAll();focusMoaRowIfNeeded();});

// ════════════════════════════════════════════════════════
//  AUTO-FOCUS THE MOA ROW RIGHT AFTER ACCEPTANCE
//  When an admin accepts an MOA request from the drawer, we remember which
//  company (user_id) it belongs to, reload the page so the requirements
//  table is rebuilt with the fresh MOA document, then automatically expand
//  that company's accordion row and scroll to it, with a brief highlight so
//  the newly-appeared MOA workflow UI is immediately visible — no manual
//  searching required. This does not alter any existing behavior/logic.
// ════════════════════════════════════════════════════════
// ── UPDATED (this adjustment): the row-focusing logic is now factored
// into focusMoaRowByUid(uid, ...), a reusable function callable directly
// (used by the new "View Request" notification button) as well as via
// the original sessionStorage handoff (focusMoaRowIfNeeded(), still used
// after a full page reload — see viewMoaNotification()'s fallback and the
// legacy accept flow above).
function focusMoaRowIfNeeded(){
    let uid=null;
    try{ uid = sessionStorage.getItem('moa_focus_uid'); }catch(e){}
    if(!uid) return;
    try{ sessionStorage.removeItem('moa_focus_uid'); }catch(e){}
    focusMoaRowByUid(uid);
}

function focusMoaRowByUid(uid, bannerText){
    const row=document.querySelector(`.company-row[data-uid="${uid}"]`);
    if(!row) return false;

    // Make sure the row isn't hidden by an active search/filter or pagination.
    // UPDATED (this adjustment): each table has its own search box now — clear both, so the row can't stay hidden by either.
    const searchInput=document.getElementById('searchInputExisting'),searchInputNew=document.getElementById('searchInputNew'),statusFilter=document.getElementById('statusFilter');
    if(searchInput) searchInput.value='';
    if(searchInputNew) searchInputNew.value='';
    if(statusFilter) statusFilter.value='All';
    cvResetTableFilters();   // NEW (this adjustment): the per-table company type / MOA status filters too
    filterAll();
    ['existing','new'].forEach(which=>{
        const idx=_pagers[which].filteredRows.indexOf(row);
        if(idx>=0){
            const page=Math.floor(idx/ROWS_PER_PAGE)+1;
            goToPage(page,which);
        }
    });

    // Auto-expand its accordion so the MOA workflow UI is visible
    // immediately. Setting .checked programmatically does not fire a
    // native 'change' event, so the accordion listener above would never
    // see this — close every other panel here directly instead, keeping
    // the "only one panel open at a time" rule consistent for a
    // programmatic open (View Request / Accept) too, not just a manual
    // click.
    const toggle=row.querySelector('.toggle-input');
    if(toggle){
        document.querySelectorAll('.toggle-input').forEach(cb => { if (cb !== toggle) cb.checked = false; });
        toggle.checked=true;
    }

    // Add a temporary highlight banner + glow on the MOA item.
    const moaItem=row.querySelector('.moa-req-item');
    // NEW (this adjustment): the MOA card now lives on its own tab of the
    // expanded panel — switch to it so the workflow is actually visible.
    if(moaItem) cvActivateTab(row,'moa');
    if(moaItem){
        moaItem.classList.add('moa-just-accepted');
        if(!moaItem.querySelector('.moa-just-accepted-banner')){
            const banner=document.createElement('div');
            banner.className='moa-just-accepted-banner';
            banner.innerHTML='<i class="fas fa-check-circle"></i> '+(bannerText||'MOA request — now showing in this table.');
            moaItem.insertBefore(banner, moaItem.firstChild);
            setTimeout(()=>{ banner.remove(); }, 6000);
        }
        setTimeout(()=>{ moaItem.classList.remove('moa-just-accepted'); }, 5000);
    }

    setTimeout(()=>{ row.scrollIntoView({behavior:'smooth', block:'center'}); }, 150);
    return true;
}

// ══ ARCHIVE MODAL
const totalCompanies=<?= $totalCompanies ?>;
function openCoArchiveModal(){if(totalCompanies===0){showGuardModal('','Nothing to Archive','There are no active companies to archive.');return;}document.getElementById('coArchiveCount').textContent=totalCompanies;const now=new Date(),sem=(now.getMonth()+1>=6&&now.getMonth()+1<=11)?'1st Semester':'2nd Semester';document.getElementById('coBatchLabelInput').value=`Company Batch ${now.getFullYear()} — ${sem}`;document.getElementById('coArchiveModal').style.display='flex';document.getElementById('coArchProgress').style.display='none';document.getElementById('coArchConfirmBtn').disabled=false;document.getElementById('coArchExportBtn').disabled=false;}
document.getElementById('coArchCancelBtn').addEventListener('click',()=>{document.getElementById('coArchiveModal').style.display='none';});
document.getElementById('coArchExportBtn').addEventListener('click',()=>{const btn=document.getElementById('coArchExportBtn');btn.disabled=true;btn.innerHTML='<i class="fas fa-spinner fa-spin"></i> Exporting…';const fd=new FormData();fd.append('ajax_export_company_batch','1');fetch(SELF,{method:'POST',body:fd}).then(r=>r.json()).then(data=>{if(!data.success||!data.data.length){alert('No data to export.');btn.disabled=false;btn.innerHTML='<i class="fas fa-file-excel"></i> Export Excel';return;}const label=document.getElementById('coBatchLabelInput').value.trim()||'Company_Batch',cols=Object.keys(data.data[0]);let html='<table><thead><tr>'+cols.map(c=>`<th>${c}</th>`).join('')+'</tr></thead><tbody>';data.data.forEach(row=>{html+='<tr>'+cols.map(c=>`<td>${row[c]}</td>`).join('')+'</tr>';});html+='</tbody></table>';const blob=new Blob(['\ufeff',html],{type:'application/vnd.ms-excel'}),url=URL.createObjectURL(blob),a=document.createElement('a');a.href=url;a.download=label.replace(/[^a-zA-Z0-9_\-]/g,'_')+'.xls';a.click();URL.revokeObjectURL(url);btn.disabled=false;btn.innerHTML='<i class="fas fa-file-excel"></i> Export Excel';}).catch(()=>{alert('Export failed.');btn.disabled=false;btn.innerHTML='<i class="fas fa-file-excel"></i> Export Excel';});});
document.getElementById('coArchConfirmBtn').addEventListener('click',()=>{const label=document.getElementById('coBatchLabelInput').value.trim();if(!label){document.getElementById('coBatchLabelInput').focus();return;}document.getElementById('coArchConfirmBtn').disabled=true;document.getElementById('coArchExportBtn').disabled=true;document.getElementById('coArchCancelBtn').disabled=true;document.getElementById('coArchProgress').style.display='block';const fd=new FormData();fd.append('ajax_archive_company_batch','1');fd.append('batch_label',label);fetch(SELF,{method:'POST',body:fd}).then(r=>r.text()).then(raw=>{let data;try{data=JSON.parse(raw);}catch(e){alert('Server error:\n'+raw.substring(0,300));document.getElementById('coArchConfirmBtn').disabled=false;document.getElementById('coArchExportBtn').disabled=false;document.getElementById('coArchCancelBtn').disabled=false;document.getElementById('coArchProgress').style.display='none';return;}if(data.success){document.getElementById('coArchiveModal').style.display='none';showGuardModal('','Batch Archived!',`<strong>${data.archived}</strong> company records from <strong>${data.batch}</strong> have been archived successfully.`);document.querySelector('#guardModal button').addEventListener('click',()=>{window.location.reload();},{once:true});}else{alert('Archive failed: '+(data.message||'Unknown error'));document.getElementById('coArchConfirmBtn').disabled=false;document.getElementById('coArchExportBtn').disabled=false;document.getElementById('coArchCancelBtn').disabled=false;document.getElementById('coArchProgress').style.display='none';}}).catch(err=>{alert('Network error: '+err.message);document.getElementById('coArchConfirmBtn').disabled=false;document.getElementById('coArchExportBtn').disabled=false;document.getElementById('coArchCancelBtn').disabled=false;document.getElementById('coArchProgress').style.display='none';});});

// ── Archive Viewer
let _allCoArchiveRows=[],_pendingCoUnarchiveBatch='';
function openCoArchiveViewer(){document.getElementById('coArchiveViewerOverlay').style.display='flex';loadCoArchiveData();}
function closeCoArchiveViewer(){document.getElementById('coArchiveViewerOverlay').style.display='none';}
document.getElementById('coArchiveViewerOverlay').addEventListener('click',function(e){if(e.target===this)closeCoArchiveViewer();});
function loadCoArchiveData(){const body=document.getElementById('coArchiveTableBody'),empty=document.getElementById('coArchiveEmpty'),table=document.getElementById('coArchiveTable');body.innerHTML='<tr><td colspan="7" style="text-align:center;padding:30px;color:#66718D;">Loading…</td></tr>';table.style.display='table';empty.style.display='none';const fd=new FormData();fd.append('ajax_fetch_company_archive','1');fetch(SELF,{method:'POST',body:fd}).then(r=>r.json()).then(data=>{_allCoArchiveRows=data.rows||[];const batchSel=document.getElementById('coArchiveBatchFilter'),cur=batchSel.value;batchSel.innerHTML='<option value="">All Batches</option>';(data.batches||[]).forEach(b=>{const opt=document.createElement('option');opt.value=b;opt.textContent=b;if(b===cur)opt.selected=true;batchSel.appendChild(opt);});renderCoArchiveTable(_allCoArchiveRows);}).catch(()=>{document.getElementById('coArchiveTableBody').innerHTML='<tr><td colspan="7" style="text-align:center;color:#A02A2A;padding:20px;">Failed to load archive.</td></tr>';});}
function renderCoArchiveTable(rows){const body=document.getElementById('coArchiveTableBody'),empty=document.getElementById('coArchiveEmpty'),table=document.getElementById('coArchiveTable');if(!rows.length){table.style.display='none';empty.style.display='block';return;}table.style.display='table';empty.style.display='none';const shownBatches=new Set(),batchMap={};const html=rows.map((r,i)=>{const valBadge=r.validation==='Verified'?'<span class="av-badge verified">Verified</span>':'<span class="av-badge pending">Pending</span>';let actionCell='<td></td>';if(!shownBatches.has(r.batch)){shownBatches.add(r.batch);batchMap[i]=r.batch;actionCell=`<td style="text-align:center;"><button class="co-unarchive-btn" data-rowindex="${i}" style="background:#1B2A4A;color:white;border:none;padding:5px 12px;border-radius:0;font-size:11px;font-weight:700;cursor:pointer;display:inline-flex;align-items:center;gap:5px;white-space:nowrap;"><i class="fas fa-box-open"></i> Unarchive</button></td>`;}return `<tr><td style="font-weight:600;color:#1B2A4A;white-space:nowrap;">${r.batch}</td><td>${r.company}</td><td style="color:#3E4963;">${r.type}</td><td>${r.contact||'—'}</td><td>${valBadge}</td><td style="color:#66718D;white-space:nowrap;">${r.archived}</td>${actionCell}</tr>`;}).join('');body.innerHTML=html;Object.keys(batchMap).forEach(idx=>{const btn=body.querySelector(`.co-unarchive-btn[data-rowindex="${idx}"]`);if(btn)btn.dataset.batch=batchMap[idx];});}
function filterCoArchive(){const batch=document.getElementById('coArchiveBatchFilter').value.toLowerCase(),search=document.getElementById('coArchiveSearchInput').value.toLowerCase();renderCoArchiveTable(_allCoArchiveRows.filter(r=>(!batch||r.batch.toLowerCase()===batch)&&(!search||r.company.toLowerCase().includes(search)||r.type.toLowerCase().includes(search))));}
function exportFilteredCoArchive(){const batch=document.getElementById('coArchiveBatchFilter').value.toLowerCase(),search=document.getElementById('coArchiveSearchInput').value.toLowerCase();const filtered=_allCoArchiveRows.filter(r=>(!batch||r.batch.toLowerCase()===batch)&&(!search||r.company.toLowerCase().includes(search)||r.type.toLowerCase().includes(search)));if(!filtered.length){alert('No records to export.');return;}const cols=['batch','company','type','contact','validation','archived'],heads=['Batch','Company','Type','Representative','Validation Status','Archived On'];let html='<table><thead><tr>'+heads.map(h=>`<th>${h}</th>`).join('')+'</tr></thead><tbody>';filtered.forEach(r=>{html+='<tr>'+cols.map(c=>`<td>${r[c]||''}</td>`).join('')+'</tr>';});html+='</tbody></table>';const batchName=document.getElementById('coArchiveBatchFilter').value||'All_Batches';const blob=new Blob(['\ufeff',html],{type:'application/vnd.ms-excel'}),url=URL.createObjectURL(blob),a=document.createElement('a');a.href=url;a.download='Archive_'+batchName.replace(/[^a-zA-Z0-9_\-]/g,'_')+'.xls';a.click();URL.revokeObjectURL(url);}
document.getElementById('coArchiveBatchFilter').addEventListener('change',function(){const btn=document.getElementById('coArchiveUnarchiveBtn');btn.disabled=!this.value;});
document.getElementById('coArchiveViewerBody').addEventListener('click',function(e){const rowBtn=e.target.closest('.co-unarchive-btn');if(!rowBtn)return;_pendingCoUnarchiveBatch=rowBtn.dataset.batch;showCoUnarchiveConfirm(_pendingCoUnarchiveBatch);});
document.getElementById('coArchiveUnarchiveBtn').addEventListener('click',function(){const sel=document.getElementById('coArchiveBatchFilter').value;if(!sel)return;_pendingCoUnarchiveBatch=sel;showCoUnarchiveConfirm(_pendingCoUnarchiveBatch);});
function showCoUnarchiveConfirm(batchName){document.getElementById('coUnarchiveConfirmMsg').textContent=`This will restore all companies from "${batchName}" back to the active dashboard.`;document.getElementById('coUnarchiveConfirmOverlay').style.display='flex';}
document.getElementById('coUnarchiveConfirmGo').addEventListener('click',function(){const confirmBtn=this,batchToRestore=_pendingCoUnarchiveBatch;if(!batchToRestore){alert('No batch selected.');return;}confirmBtn.disabled=true;confirmBtn.innerHTML='<i class="fas fa-spinner fa-spin"></i> Restoring…';const fd=new FormData();fd.append('ajax_unarchive_company_batch','1');fd.append('batch_label',batchToRestore);fetch(SELF,{method:'POST',body:fd}).then(r=>r.text()).then(raw=>{confirmBtn.disabled=false;confirmBtn.innerHTML='<i class="fas fa-box-open"></i> Yes, Unarchive';document.getElementById('coUnarchiveConfirmOverlay').style.display='none';let data;try{data=JSON.parse(raw);}catch(e){alert('Server error:\n'+raw.substring(0,300));return;}if(data.blocked){document.getElementById('coBlockNotifMsg').textContent=data.message;document.getElementById('coBlockNotifOverlay').style.display='flex';return;}if(data.success){_pendingCoUnarchiveBatch='';closeCoArchiveViewer();showGuardModal('','Batch Restored!',`<strong>${data.restored}</strong> companies from <strong>${data.batch}</strong> have been restored.`);document.querySelector('#guardModal button').addEventListener('click',()=>{window.location.reload();},{once:true});}else alert('Unarchive failed: '+(data.message||'Unknown error'));}).catch(err=>{confirmBtn.disabled=false;confirmBtn.innerHTML='<i class="fas fa-box-open"></i> Yes, Unarchive';document.getElementById('coUnarchiveConfirmOverlay').style.display='none';alert('Network error: '+err.message);});});

// ════════════════════════════════════════════════════════
//  MOA REQUESTS DRAWER — Simplified: Details, Accept, Reject (View/Download moved to Details modal)
// ════════════════════════════════════════════════════════
let _allMoaRows = [];

// ══ NEW (this adjustment): live compliance-detection state ══
// Snapshot of { moa_id: status } captured on every successful fetch of
// ajax_fetch_moa_requests (whether from the initial page-load snapshot,
// the manual drawer open, or the background poller below). Comparing the
// previous snapshot against a freshly-fetched one lets us detect the exact
// moment a company complies with a rejection (status flips from
// 'Rejected' -> 'Pending' with is_revision=1 and no more outstanding
// blanked fields) WITHOUT the admin needing to refresh the page at all.
let _lastKnownMoaStatuses = {};
// Guards against overlapping poll requests stepping on each other.
let _moaPollInFlight = false;

// Unique key used to remember the "don't show again" preference for the
// "MOA Accepted!" success popup only. Stored in localStorage with a 1-day
// expiry (see showGuardModal/closeGuardModal above).
const MOA_ACCEPT_SUPPRESS_KEY = 'moa_accept_notif_suppress_until';

// Human-readable labels for the flaggable MOA-section fields (mirrors the
// PHP-side $moaRejectFlagLabels map so the Reject modal / Details modal /
// drawer card can show consistent text without another round-trip).
// ── UPDATED (adjustment): "moa_document" added so the uploaded file itself
// can be flagged/labelled consistently across the admin UI.
const MOA_REJECT_FLAG_LABELS = {
    company_name:        'Company Name',
    company_profile:     'Company Profile',
    company_address:     'Company Address',
    position:             'Position',
    contact_first_name:  'Contact First Name',
    contact_middle_name: 'Contact Middle Name',
    contact_last_name:   'Contact Last Name',
    telephone:            'Telephone',
    moa_document:         'Uploaded MOA Document'
};

// ════════════════════════════════════════════════════════
//  NEW (adjustment): SINGLE SOURCE OF TRUTH FOR THE MOA "STATUS" DISPLAY
//  Previously, the same "Needs Revision" wording could appear twice on the
//  same card — once in the status badge and again in the request-type
//  chip (which was being overridden by the revision state instead of
//  always showing the actual request type). This helper centralizes the
//  status wording/class so it is computed once and shown in exactly one
//  place (the status badge). The request-type chip now ALWAYS reflects
//  the real request_type ("New MOA" / "Has MOA") and never changes just
//  because a request was rejected — only the status badge changes.
//
//  Status rules:
//    - Approved                              → "Accepted — In Requirements Table"
//    - Rejected (company has NOT complied     → "Needs Revision" (not complied yet)
//      yet, i.e. still sitting as status
//      'Rejected' until they resubmit)
//    - Pending + legacy awaiting_resubmission  → "Needs Revision" (legacy rows that
//      (older rows only — see PHP comments      still have blanked flagged fields;
//      in ajax_fetch_moa_requests)               company hasn't complied yet either)
//    - Pending + is_revision + NOT awaiting     → "Revised — Pending Review" (company
//      resubmission (i.e. company has            DID comply: they fixed the flagged
//      resubmitted after a rejection)             section(s) and resubmitted)
//    - Pending, not a revision at all          → "Pending Review" (a fresh request)
// ════════════════════════════════════════════════════════
function getMoaStatusInfo(r) {
    if (r.status === 'Approved') {
        return { cls: 'Accepted', text: 'Accepted — In Requirements Table' };
    }
    if (r.status === 'Rejected') {
        // Company has not complied/revised yet — the row stays Rejected until
        // they resubmit via moa_request.php (which flips it back to Pending).
        return { cls: 'Rejected', text: 'Needs Revision' };
    }
    // status === 'Pending' from here on
    if (r.awaiting_resubmission) {
        // Legacy rows only (see PHP-side comments): flagged field(s) are still
        // blank, meaning the company has not complied yet.
        return { cls: 'Rejected', text: 'Needs Revision' };
    }
    if (r.is_revision) {
        // Company complied: they revised the flagged section(s) and resubmitted,
        // and the request is back in the queue awaiting admin review.
        return { cls: 'Revised', text: 'Revised — Pending Review' };
    }
    return { cls: 'Pending', text: 'Pending Review' };
}

// ══ NEW (this adjustment): does a row currently count as "company has
// complied with the flagged correction(s) and it's ready for admin
// review"? Used both by the poller (to know when to fire a toast /
// highlight) and to build a consistent snapshot for comparison. ══
function isMoaRowCompliant(r) {
    return r.status === 'Pending' && !!r.is_revision && !r.awaiting_resubmission;
}

// ── UPDATED (this adjustment): the drawer opens straight into the new
// notification list (loadMoaNotifications) instead of the old
// Accept/Reject queue (loadMoaRequests, kept below unused/for reference).
function openMoaDrawer(){document.getElementById('moaDrawerOverlay').classList.add('open');document.getElementById('moaDrawer').classList.add('open');loadMoaNotifications();}
function closeMoaDrawer(){document.getElementById('moaDrawerOverlay').classList.remove('open');document.getElementById('moaDrawer').classList.remove('open');}

// ════════════════════════════════════════════════════════
//  NEW (this adjustment): MOA REQUESTS INBOX AS A PLAIN NOTIFICATION LIST
//  ────────────────────────────────────────────────────────
//  There is no more Accept/Reject step here — every new MOA request is
//  already auto-inserted into the "New MOA" table by
//  autoIngestPendingMoaRequests() the moment it's seen server-side. This
//  drawer now only shows the requests the admin hasn't acknowledged yet
//  (admin_viewed=0 — see ajax_fetch_moa_notifications), each with a
//  single "View Request" button. Clicking it marks the notification
//  viewed (it then disappears from this list) and jumps straight to that
//  company's row in the New MOA table, expanding + scrolling + briefly
//  highlighting it — reusing the same focus/scroll/expand behavior
//  focusMoaRowIfNeeded() already used after the old "Accept" action.
// ════════════════════════════════════════════════════════
let _allMoaNotifications = [];
let _lastKnownMoaNotificationIds = new Set();
let _moaNotifPollInFlight = false;

function loadMoaNotifications(){
    const body=document.getElementById('moaDrawerBody');
    body.innerHTML='<div class="moa-drawer-loading"><i class="fas fa-spinner fa-spin" style="font-size:24px;margin-bottom:10px;display:block;color:#66718D;"></i>Loading notifications…</div>';
    const fd=new FormData();fd.append('ajax_fetch_moa_notifications','1');
    fetch(SELF,{method:'POST',body:fd}).then(r=>r.json()).then(data=>{
        _allMoaNotifications=data.rows||[];
        _lastKnownMoaNotificationIds = new Set(_allMoaNotifications.map(r=>r.id));
        updateMoaBadge(data.pending_count||0);
        filterMoaNotifications();
    }).catch(()=>{document.getElementById('moaDrawerBody').innerHTML='<div class="moa-drawer-empty"><i class="fas fa-exclamation-circle"></i><p>Failed to load notifications.</p></div>';});
}

// ── UPDATED (this adjustment): also filters by notification type when a
// specific one is selected in the new #moaDrawerTypeFilter dropdown.
function filterMoaNotifications(){
    const search=(document.getElementById('moaDrawerSearch')?.value||'').toLowerCase();
    const typeFilter=document.getElementById('moaDrawerTypeFilter')?.value||'';
    const filtered=_allMoaNotifications.filter(r=>{
        const matchesSearch = !search||(r.company_name||'').toLowerCase().includes(search)||(r.contact_name||'').toLowerCase().includes(search);
        const matchesType = !typeFilter||(r.notif_type||'new_request')===typeFilter;
        return matchesSearch && matchesType;
    });
    renderMoaNotifications(filtered);
}

function renderMoaNotifications(rows){
    const body=document.getElementById('moaDrawerBody');
    // ── UPDATED (this adjustment): the drawer header count always reflects
    // the TOTAL un-viewed notifications (matching the badge elsewhere),
    // not just however many are left after a type/search filter narrows
    // the visible list — otherwise picking a filter would misleadingly
    // shrink the header count too.
    document.getElementById('moaDrawerPendingCount').textContent=_allMoaNotifications.length+' New';
    if(!rows.length){
        const hasActiveFilter = !!(document.getElementById('moaDrawerSearch')?.value || document.getElementById('moaDrawerTypeFilter')?.value);
        body.innerHTML = hasActiveFilter
            ? '<div class="moa-drawer-empty"><i class="fas fa-filter"></i><p>No notifications match this filter.</p></div>'
            : '<div class="moa-drawer-empty"><i class="fas fa-check-circle"></i><p>No new notifications. You\'re all caught up.</p></div>';
        return;
    }
    body.innerHTML=rows.map(r=>buildMoaNotificationCard(r)).join('');
}

// ── NEW (this adjustment): label/icon/color per notification type —
// mirrors the same four event types detectAndNotifyComplianceEvents()
// (and autoIngestPendingMoaRequests()/detectAndNotifyDirectMoaEntries()
// for the default 'new_request' case) can produce.
const MOA_NOTIF_TYPE_META = {
    new_request:        { label: 'New Request',       icon: 'fa-file-signature',  cls: 'notif-new' },
    revision_complied:  { label: 'Revision Complied',  icon: 'fa-check-double',    cls: 'notif-revision' },
    schedule_agreed:    { label: 'Schedule Agreed',    icon: 'fa-calendar-check',  cls: 'notif-agreed' },
    schedule_declined:  { label: 'Schedule Proposed',  icon: 'fa-calendar-day',    cls: 'notif-declined' },
    // NEW (this adjustment): a company uploaded / re-uploaded compliance document(s) — see detectAndNotifyRequirementUploads()
    requirement_uploaded: { label: 'Requirement Uploaded', icon: 'fa-file-arrow-up', cls: 'notif-uploaded' },
};
// NEW (this adjustment): each notification type now has its OWN action button (they all used to say "View Request"):
// what the admin is about to do — review the MOA, review the compliance, check the schedule, or open the requirements.
const MOA_NOTIF_ACTION_META = {
    new_request:          { label: 'Review MOA',         icon: 'fa-file-signature' },
    revision_complied:    { label: 'Review Compliance',  icon: 'fa-check-double' },
    schedule_agreed:      { label: 'View Schedule',      icon: 'fa-calendar-check' },
    schedule_declined:    { label: 'Review Schedule',    icon: 'fa-calendar-day' },
    requirement_uploaded: { label: 'View Requirements',  icon: 'fa-file-arrow-up' },
};

// ── UPDATED (this adjustment): the old "NEW MOA / HAS MOA" chip (top-right) is removed
// entirely and the notification-type badge now sits in its place; the "View Request"
// button moved to the right side, level with the contact/position/time + address lines.
// The card id, the button id (used by viewMoaNotification()) and its onclick are unchanged.
// NEW (this adjustment): the person on an inbox card — the position now follows the name in parentheses, e.g. "Editha Santos Sales (COE)",
// instead of getting a briefcase icon and a cell of its own. With no position on record it is just the name (no empty "()"), and with
// no name the same "—" fallback the card always used. Used by every notification type's card (and by the older list card).
function cvNotifPersonText(name, position){
    const n = String(name == null ? '' : name).trim() || '\u2014';
    const p = String(position == null ? '' : position).trim();
    return escapeHtml(p ? (n + ' (' + p + ')') : n);
}

function buildMoaNotificationCard(r){
    const notifMeta = MOA_NOTIF_TYPE_META[r.notif_type || 'new_request'] || MOA_NOTIF_TYPE_META.new_request;
    // NEW (this adjustment): a "Requirement Uploaded" card also lists WHICH documents came in (and how many files each).
    const isUploadNotif = (r.notif_type === 'requirement_uploaded');
    const uploadDetailHtml = (isUploadNotif && Array.isArray(r.detail) && r.detail.length)
        ? '<div class="moa-card-address moa-notif-upload-detail"><i class="fas fa-file-arrow-up" style="font-size:10px;"></i> '
            + r.detail.map(d=>escapeHtml(d.label||d.key||'')+((d.files>1)?' ('+d.files+' files)':'')).join(', ') + '</div>'
        : '';
    // NEW (this adjustment): a "Revision Complied" card also names the section(s) the company updated (their new values on hover).
    const isComplied = (r.notif_type === 'revision_complied');
    const compliedDetailHtml = (isComplied && Array.isArray(r.detail) && r.detail.length)
        ? '<div class="moa-card-address moa-notif-upload-detail complied" title="'+escapeHtml(r.detail.map(d=>(d.label||d.key||'')+': '+(d.value||'—')).join('\n'))+'"><i class="fas fa-check-double" style="font-size:10px;"></i> Complied: '
            + r.detail.map(d=>escapeHtml(d.label||d.key||'')).join(', ') + '</div>'
        : '';
    const actionMeta = MOA_NOTIF_ACTION_META[r.notif_type || 'new_request'] || MOA_NOTIF_ACTION_META.new_request;
    return `
        <div class="moa-card moa-notif-card" id="moaNotifCard_${r.id}">
            <div class="moa-card-top">
                <div class="moa-card-info">
                    <div class="moa-card-company">${escapeHtml(r.company_name||'—')}</div>
                </div>
                <div class="moa-notif-type-badge ${notifMeta.cls}"><i class="fas ${notifMeta.icon}"></i> ${notifMeta.label}</div>
            </div>
            <div class="moa-card-detail-row">
                <div class="moa-card-info">
                    <div class="moa-card-meta">
                        <span><i class="fas fa-user" style="font-size:10px;"></i> ${cvNotifPersonText(r.contact_name, r.position)}</span>
                        <span><i class="fas fa-clock" style="font-size:10px;"></i> ${escapeHtml(r.submitted_at||'—')}</span>
                    </div>
                    <div class="moa-card-address"><i class="fas fa-map-marker-alt" style="font-size:10px;"></i> ${escapeHtml(r.company_address||'—')}</div>
                    ${uploadDetailHtml}${compliedDetailHtml}
                </div>
                <div class="moa-card-actions">
                    <button class="moa-action-btn accept-btn" id="moaNotifViewBtn_${r.id}" onclick="viewMoaNotification(${r.id}, ${r.user_id})">
                        <i class="fas ${actionMeta.icon}"></i> ${actionMeta.label}
                    </button>
                </div>
            </div>
        </div>`;
}

// ── NEW (this adjustment): "View Request" — marks the notification
// viewed (so it disappears from this inbox) and takes the admin straight
// to that company's row in the New MOA table. If the row is already in
// the current page's DOM (e.g. it was ingested moments ago by a
// background poll), it's focused immediately with no reload needed;
// otherwise (a fresh full-ingest not yet reflected in this page's
// server-rendered HTML) it falls back to the same
// sessionStorage + reload pattern the old "Accept" action used.
function viewMoaNotification(notifId, userId){
    // NEW (this adjustment): remembered BEFORE the notification is removed from the list below, so a
    // "Requirement Uploaded" notification can take the admin to the Requirements tab (and highlight
    // the documents it names) instead of the MOA tab.
    const notifRec = _allMoaNotifications.find(r=>r.id===notifId);
    const isReqUpload = !!(notifRec && notifRec.notif_type === 'requirement_uploaded');
    const btn = document.getElementById('moaNotifViewBtn_'+notifId);
    if (btn) { btn.disabled = true; btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Opening…'; }

    const fd = new FormData();
    fd.append('ajax_moa_mark_notification_viewed','1');
    fd.append('moa_id', notifId);
    fetch(SELF,{method:'POST',body:fd}).then(r=>r.json()).then(data=>{
        _allMoaNotifications = _allMoaNotifications.filter(r=>r.id!==notifId);
        _lastKnownMoaNotificationIds.delete(notifId);
        updateMoaBadge(data.pending_count!=null?data.pending_count:_allMoaNotifications.length);
        filterMoaNotifications();
    }).catch(()=>{});

    closeMoaDrawer();
    // If the row is already rendered on this page, focus it directly —
    // no reload needed. Otherwise (the company was ingested by a
    // background poll since this page was last loaded, so the
    // server-rendered "New" table HTML doesn't have it yet), fall back to
    // the sessionStorage handoff + reload, same as the old accept flow.
    // UPDATED (this adjustment): the banner says what actually happened (it used to say "New MOA request" for every type),
    // and for a NEW MOA / REVISION COMPLIED notification the admin is taken to the company's panel and the Review MOA
    // preview then opens by itself (see cvOpenReviewMoaAfterFocus()) — for a revision, with what was complied with shown.
    const notifType = notifRec ? (notifRec.notif_type || 'new_request') : 'new_request';
    const opensMoa = (notifType === 'new_request' || notifType === 'revision_complied');
    const focusBanner = {
        revision_complied: 'Revision complied — the updated MOA is ready for your review.',
        schedule_declined: 'The company proposed a different signing schedule.',
        schedule_agreed:   'The company agreed to the signing schedule.',
    }[notifType] || 'New MOA request — now showing in this table.';
    const focused = isReqUpload
        ? focusRequirementsRowByUid(userId, (notifRec.detail||[]).map(d=>d.key))
        : focusMoaRowByUid(userId, focusBanner);
    if (focused && opensMoa) cvOpenReviewMoaAfterFocus(userId);
    if (!focused) {
        try{
            sessionStorage.setItem(isReqUpload ? 'cv_focus_req_uid' : 'moa_focus_uid', userId);
            if (opensMoa) sessionStorage.setItem('cv_open_moa_uid', userId);
        }catch(e){}
        window.location.reload();
    }
}

function updateMoaBadge(count){
    const badge=document.getElementById('moaNavBadge');
    if(badge){badge.textContent=count;badge.style.display=count>0?'inline-flex':'none';}
    const sideBadge=document.getElementById('sidebarMoaBadge');
    if(sideBadge){sideBadge.textContent=count;sideBadge.style.display=count>0?'inline-flex':'none';}
    const dc=document.getElementById('moaDrawerPendingCount');
    if(dc)dc.textContent=count+' New';
}

// ── NEW (this adjustment): background poller for the notification
// inbox — replaces the old status-diffing pollMoaRequestsData() (still
// defined below, unused, for reference) with a much simpler "did a new
// notification id show up since the last check" comparison, since there
// is no more per-row status lifecycle to track here. Fires the same
// toast + badge update the old poller used to, whenever a brand-new
// notification appears (a fresh submission OR a company resubmitting
// after being flagged for revision).
//
// ── UPDATED (this adjustment): every freshly-arrived notification now
// also triggers liveInsertOrUpdateCompanyRow() — the admin sees the
// company's row appear (or refresh, if it was already on the page)
// directly in the Existing/New table, with no manual reload needed to
// find it.
function pollMoaNotifications(){
    if (_moaNotifPollInFlight) return;
    _moaNotifPollInFlight = true;
    const fd = new FormData();
    fd.append('ajax_fetch_moa_notifications','1');
    fetch(SELF,{method:'POST',body:fd}).then(r=>r.json()).then(data=>{
        _moaNotifPollInFlight = false;
        if (!data.success) return;
        const newRows = data.rows || [];
        const newIds = new Set(newRows.map(r=>r.id));

        const freshlyArrived = newRows.filter(r => !_lastKnownMoaNotificationIds.has(r.id));
        _allMoaNotifications = newRows;
        _lastKnownMoaNotificationIds = newIds;
        updateMoaBadge(data.pending_count||0);

        if (document.getElementById('moaDrawer').classList.contains('open')) {
            filterMoaNotifications();
        }

        freshlyArrived.forEach(r => {
            showMoaNewRequestToast(r.company_name || 'A company', r.notif_type, r);   // UPDATED (this adjustment): row passed for the click-through
            // NEW (this adjustment): a requirement upload only changes that company's requirement
            // cards, so it refreshes just those (see cvRefreshRequirementsLive()) instead of
            // replacing the whole row and losing whatever the admin has open in it.
            if (r.notif_type === 'requirement_uploaded') cvRefreshRequirementsLive(r.user_id);
            else liveInsertOrUpdateCompanyRow(r.user_id);
        });
    }).catch(()=>{ _moaNotifPollInFlight = false; });
}

// ── NEW (this adjustment): admin-side counterpart to a company declining
// a proposed schedule and suggesting an alternative on CompanyForm.php —
// adopts that proposed date/time as the new official (and already
// confirmed) schedule in one click. See the ajax_accept_proposed_schedule
// handler near the top of this file.
function acceptProposedSchedule(userId){
    showGlobalLoading('Accepting');
    const fd = new FormData();
    fd.append('ajax_accept_proposed_schedule','1');
    fd.append('user_id', userId);
    fd.append('moa_undoable','1');   // NEW (this adjustment): the server keeps what's needed to undo this and holds the email back
    fetch(SELF, {method:'POST', body:fd})
        .then(r => r.json())
        .then(data => {
            hideGlobalLoading();
            if (!data.success) {
                showGuardModal('','Error', data.message || 'Failed to accept the proposed schedule.');
                return;
            }
            liveInsertOrUpdateCompanyRow(userId);
            showGuardModal('','Schedule Confirmed','The proposed signing schedule has been accepted and is now confirmed.');
            // NEW (this adjustment): the undo toast, like a requirement's
            if (data.undo_token) startMoaUndoToast(data.undo_token, data.undo_label, userId, data.undo_action);
        })
        .catch(err => { hideGlobalLoading(); showGuardModal('','Network Error', err.message || 'Request failed.'); });
}

// ── NEW (this adjustment): pulls one company's row, fully rendered
// server-side (see the ajax_fetch_company_row handler near the top of
// this file — it reuses renderCompanyValidationRow() directly, so the
// HTML is identical to what a full page reload would produce), and
// drops it straight into the correct table — no manual reload needed to
// see a brand-new MOA request. If that company's row is already on the
// page (e.g. this is a resubmission notification, not a first-time
// arrival), it's replaced in place instead of duplicated. Briefly
// highlights the row either way, reusing the same glow/scroll treatment
// focusMoaRowByUid() already uses elsewhere on this page.
function liveInsertOrUpdateCompanyRow(userId){
    if (!userId) return;
    const fd = new FormData();
    fd.append('ajax_fetch_company_row','1');
    fd.append('user_id', userId);
    fetch(SELF,{method:'POST',body:fd}).then(r=>r.json()).then(data=>{
        if (!data.success || !data.html) return;

        const wrapperId = data.bucket === 'existing' ? 'existingCompanyWrapper' : 'newCompanyWrapper';
        const wrapper = document.getElementById(wrapperId);
        if (!wrapper) {
            // The table is currently showing its empty-state placeholder
            // (zero companies in this bucket), so there is no wrapper to
            // insert into yet. A full reload is the simplest safe way to
            // pick up the new empty-state → populated-table structure —
            // this only happens the very first time a bucket goes from
            // zero to one company.
            try{ sessionStorage.setItem('moa_focus_uid', userId); }catch(e){}
            window.location.reload();
            return;
        }

        const temp = document.createElement('div');
        temp.innerHTML = data.html.trim();
        const newRowEl = temp.firstElementChild;
        if (!newRowEl) return;

        const existingRowEl = wrapper.querySelector(`.company-row[data-uid="${userId}"]`);
        if (existingRowEl) {
            // ── NEW (this adjustment): preserve whether the row was
            // expanded before the refresh — e.g. sendMoaTableFlagRevision()
            // now calls this function from inside that very row's
            // expanded MOA card, and snapping it closed right after a
            // successful action would be a jarring, unexpected side
            // effect of what should just be an in-place content refresh.
            const oldToggle = existingRowEl.querySelector('.toggle-input');
            const wasExpanded = !!(oldToggle && oldToggle.checked);
            // NEW (this adjustment): also remember which panel tab was showing so the
            // in-place refresh doesn't bounce the admin back to the first tab.
            const oldActiveTabKey = existingRowEl.querySelector('.cv-tab.active')?.getAttribute('data-tab') || null;
            existingRowEl.replaceWith(newRowEl);
            if (wasExpanded) {
                const newToggle = newRowEl.querySelector('.toggle-input');
                if (newToggle) newToggle.checked = true;
            }
            if (oldActiveTabKey) cvActivateTab(newRowEl, oldActiveTabKey);
        } else {
            wrapper.insertBefore(newRowEl, wrapper.querySelector('.company-table-header')?.nextSibling || wrapper.firstChild);
            // Genuinely new row (not a replace-in-place) — keep the
            // section's count badge in sync too, so the admin doesn't see
            // a stale "3" next to a table that visibly now has 4 rows.
            const badgeId = data.bucket === 'existing' ? 'existingCountBadge' : 'newCountBadge';
            const badge = document.getElementById(badgeId);
            if (badge) badge.textContent = (parseInt(badge.textContent, 10) || 0) + 1;
        }

        // Re-scan rows so pagination/search immediately account for the
        // row that was just inserted or replaced.
        filterAll();

        newRowEl.classList.add('row-just-arrived');
        setTimeout(()=>{ newRowEl.classList.remove('row-just-arrived'); }, 2500);
    }).catch(()=>{});
}

// ── NEW (this adjustment): generic "new MOA request" toast — used by
// pollMoaNotifications() for both a brand-new submission and a company
// resubmitting after being flagged for revision (the wording works for
// either case, unlike the old, resubmission-specific
// showMoaComplianceToast() below, which is left as-is/unused).
// ── UPDATED (this adjustment): now accepts a notifType so the toast text
// matches whichever of the four events actually happened, instead of
// always saying "has a new MOA request" — reuses MOA_NOTIF_TYPE_META's
// icon for visual consistency with the drawer card's own badge.
function showMoaNewRequestToast(companyName, notifType, row){   // UPDATED (this adjustment): optional row = the notification (for the click-through)
    const meta = MOA_NOTIF_TYPE_META[notifType || 'new_request'] || MOA_NOTIF_TYPE_META.new_request;
    const messages = {
        new_request:       'has a new MOA request',
        revision_complied: 'complied with the flagged MOA revision',
        schedule_agreed:   'agreed to the proposed signing schedule',
        schedule_declined: 'proposed a different signing schedule',
        requirement_uploaded: 'uploaded new requirement document(s)',
    };
    const messageText = messages[notifType || 'new_request'] || messages.new_request;

    // UPDATED (this adjustment): the toast for EVERY notification type now appears at the TOP of the page, in the original toast
    // design (see cvShowTopToast() below). It used to be at the bottom, except "requirement uploaded", which had its own top popup.
    cvShowTopToast(companyName, messageText, meta.icon);
    // NEW (this adjustment): the popup just shown (last element added to <body>) opens this notification when clicked
    if (row && window.cvTagToast && document.body.lastElementChild && document.body.lastElementChild.classList.contains('cv-top-toast'))
        window.cvTagToast(document.body.lastElementChild, 'notif:' + row.id + ':' + (row.user_id || 0) + ':' + (row.notif_type || notifType || 'new_request'));
}

// ════════════════════════════════════════════════════════════════════════
//  NEW (this adjustment) — NOTIFICATIONS THAT CLEAR THEMSELVES
//  When the admin acts on the thing a notification is about — verifies / rejects a requirement, or approves /
//  schedules / flags / finishes a company's MOA — the server settles the matching notification(s) (see
//  cvResolveRequirementUploadNotification() / cvOnAdminMoaAction()). This makes the Notification Inbox and its badge
//  follow immediately instead of waiting for the next 15-second check:
//    • a small wrapper around fetch() notices when one of those admin actions succeeds and calls
//      cvRefreshNotificationInbox() — one place, so none of the existing action handlers had to change;
//    • Undo can bring an upload notification back; the server reports its id (notif_restored_ids) and it is put back
//      quietly (it was already announced once, so no second toast).
//  Anything that is genuinely NEW is deliberately left to the regular poll, so it still gets its toast.
// ════════════════════════════════════════════════════════════════════════
function cvRefreshNotificationInbox(restoredIds){
    const fd = new FormData();
    fd.append('ajax_fetch_moa_notifications','1');
    fetch(SELF,{method:'POST',body:fd}).then(r=>r.json()).then(data=>{
        if (!data || !data.success) return;
        const rows = data.rows || [];
        const restored = new Set(restoredIds || []);
        const shown = rows.filter(r => _lastKnownMoaNotificationIds.has(r.id) || restored.has(r.id));
        _allMoaNotifications = shown;
        _lastKnownMoaNotificationIds = new Set(shown.map(r=>r.id));
        updateMoaBadge(data.pending_count||0);
        filterMoaNotifications();
    }).catch(()=>{});
}
(function(){
    if (!window.fetch || window._cvFetchWrapped) return;
    window._cvFetchWrapped = true;
    const origFetch = window.fetch.bind(window);
    const MOA_ACTIONS = ['ajax_update_moa_req_workflow','ajax_moa_table_flag_revision','ajax_accept_proposed_schedule','ajax_moa_mark_done'];
    const SETTLING_ACTIONS = ['ajax_save_requirement','ajax_undo','ajax_moa_undo'].concat(MOA_ACTIONS);   // UPDATED (this adjustment): + a MOA undo, so the inbox gets its notifications back
    window.fetch = function(input, init){
        const p = origFetch(input, init);
        try {
            const body = init && init.body;
            if (body && typeof body.has === 'function' && SETTLING_ACTIONS.some(a => body.has(a))) {
                p.then(r => r.clone().json()).then(d => {
                    if (!d || !d.success) return;
                    cvRefreshNotificationInbox(d.notif_restored_ids);
                    // acting on the MOA also settles its "Revision complied" note on the MOA card
                    if (MOA_ACTIONS.some(a => body.has(a))) {
                        const uid = body.get('user_id');
                        if (uid) document.querySelectorAll('.company-row[data-uid="'+uid+'"] .moa-complied-note').forEach(n => n.remove());
                    }
                }).catch(()=>{});
            }
        } catch(e) {}
        return p;
    };
})();

// ════════════════════════════════════════════════════════════════════════
//  NEW (this adjustment) — "REVISION COMPLIED": WHAT WAS COMPLIED WITH, IN THE REVIEW MOA PREVIEW
//  cvShowMoaCompliance() runs when the Review MOA preview opens. If the company has just complied with a "Flag for
//  Revision", it fills #moaRejectCompliancePanel with the section(s) it updated and their new values, and tags the
//  matching section checkboxes "Complied" — so the admin can see straight away where to look in the document.
// ════════════════════════════════════════════════════════════════════════
// NEW (this adjustment): Approve MOA is unavailable while the MOA is flagged and the company hasn't updated the flagged
// section(s) yet. The server tells the Review MOA preview (flags_outstanding, see ajax_get_moa_compliance) and enforces the
// same rule itself (ajax_update_moa_req_workflow, guard "flags_outstanding"). Kept as a class + aria-disabled — not the
// disabled attribute — so the button can still explain itself when clicked (same approach as the locked Done button).
const MOA_APPROVE_LOCK_MSG = 'This MOA still has <strong>flagged section(s)</strong> waiting on the company, so it can&rsquo;t be approved yet. Once the company has updated them, <strong>Approve MOA</strong> becomes available again.';
const MOA_APPROVE_LOCK_TITLE = 'Unavailable while flagged section(s) are waiting on the company';
function cvApplyApproveLock(locked){
    const btn = document.getElementById('moaReviewApproveBtn');
    if (!btn) return;
    btn.classList.toggle('moa-approve-locked', !!locked);
    if (locked) { btn.setAttribute('aria-disabled', 'true'); btn.title = MOA_APPROVE_LOCK_TITLE; }
    else        { btn.removeAttribute('aria-disabled');      btn.removeAttribute('title'); }
}
function cvResetMoaCompliance(){
    cvApplyApproveLock(false);   // NEW (this adjustment): the lock only ever applies to the MOA currently being reviewed
    const p = document.getElementById('moaRejectCompliancePanel');
    if (p) { p.style.display = 'none'; p.innerHTML = ''; }
    document.querySelectorAll('#moaRejectFlagsGrid .moa-reject-flag-item.complied').forEach(el=>{
        el.classList.remove('complied');
        const tg = el.querySelector('.complied-tag'); if (tg) tg.remove();
    });
    // NEW (this adjustment): lift every temporary lock (see cvApplyFlagLocks()) so the checklist is back to normal
    document.querySelectorAll('#moaRejectFlagsGrid .moa-reject-flag-item.locked').forEach(el=>{
        el.classList.remove('locked');
        el.removeAttribute('title');
        const cb = el.querySelector('input[type="checkbox"]'); if (cb) cb.disabled = false;
    });
}
// NEW (this adjustment): TEMPORARILY DISABLES the checkbox of every section that can't be flagged right now — the section is
// still awaiting the company's compliance (it is flagged and the company hasn't updated it yet), or it is empty. `locked` is the
// server's { flagKey: 'awaiting' | 'empty' } map (see ajax_get_moa_compliance). A disabled box is also unticked, so
// getSelectedMoaRejectFlags() can never pick it up. It is lifted by cvResetMoaCompliance() and on the next open of the modal.
function cvApplyFlagLocks(locked){
    const grid = document.getElementById('moaRejectFlagsGrid');
    if (!grid || !locked || typeof locked !== 'object') return;
    const REASONS = {
        awaiting: 'Temporarily unavailable — this section is already flagged and the company has not updated it yet. It can be flagged again once they comply.',
        empty:    'Temporarily unavailable — this section is empty, so there is nothing to flag until it is filled in.'
    };
    Object.keys(locked).forEach(key=>{
        const cb = grid.querySelector('input[type="checkbox"][value="'+String(key).replace(/[^\w-]/g,'')+'"]');
        if (!cb) return;
        const item = cb.closest('.moa-reject-flag-item');
        cb.checked = false;
        cb.disabled = true;
        if (item) {
            item.classList.remove('checked');
            item.classList.add('locked');
            item.title = REASONS[locked[key]] || REASONS.awaiting;
        }
    });
}
// NEW (layout adjustment): one "Revision complied" item — exactly the markup this panel always used for a section.
function cvComplianceItemHtml(f){
    return '<div class="mcp-item"><div class="mcp-label">'+escapeHtml(f.label||f.key||'')+'</div>'
         + '<div class="mcp-value">'+(f.value?escapeHtml(f.value):(f.key==='moa_document'?'A new MOA document was uploaded.':'—'))+'</div></div>';
}
// NEW (layout adjustment): lay the complied sections out in the same rows as the company form — Contact name(s) /
// Position / Telephone, then Company Name / Company Address, then Company Profile — showing only the sections the
// company actually updated. Up to 3 columns per row (4 fields split 2 + 2, so there is never a lone orphan cell). A
// section that isn't part of that layout (e.g. "moa_document") keeps the old stacked look in a final single-column row.
function cvComplianceRowsHtml(fields){
    const ROWS = [
        ['contact_first_name','contact_middle_name','contact_last_name','position','telephone'],
        ['company_name','company_address'],
        ['company_profile']
    ];
    const laidOut = {};
    let html = '';
    ROWS.forEach(keys=>{
        const items = [];
        keys.forEach(k=>{ laidOut[k] = true; fields.forEach(f=>{ if (f && f.key === k) items.push(f); }); });
        if (!items.length) return;
        const n = items.length;
        const cols = n <= 3 ? n : (n === 4 ? 2 : 3);
        html += '<div class="mcp-grid cols-'+cols+'">'+items.map(cvComplianceItemHtml).join('')+'</div>';
    });
    const extras = fields.filter(f=>!(f && laidOut[f.key]));
    if (extras.length) html += '<div class="mcp-grid cols-1">'+extras.map(cvComplianceItemHtml).join('')+'</div>';
    return html;
}
function cvShowMoaCompliance(userId){
    cvResetMoaCompliance();
    const loadTok = _moaLoadToken;   // NEW (this adjustment): which Review MOA load this lookup belongs to
    const fd = new FormData();
    fd.append('ajax_get_moa_compliance','1');
    fd.append('user_id', userId);
    fetch(SELF,{method:'POST',body:fd}).then(r=>r.json()).then(data=>{
        // the preview may have been closed / opened for someone else while this was loading
        if (_moaRejectMode !== 'table' || String(_moaRejectCurrentUserId) !== String(userId)) return;
        cvApplyFlagLocks(data && data.locked_fields);   // NEW (this adjustment): sections awaiting the company / empty can't be flagged for now
        cvApplyApproveLock(!!(data && data.flags_outstanding));   // NEW (this adjustment): …and the MOA can't be approved while a flag is still outstanding
        const d = data && data.detail;
        if (!d || !Array.isArray(d.fields) || !d.fields.length) return;
        const panel = document.getElementById('moaRejectCompliancePanel'); if (!panel) return;
        panel.innerHTML =
            '<div class="mcp-title"><i class="fas fa-check-double"></i> Revision complied</div>'
          + '<div class="mcp-hint">The company updated the section(s) below. They are tagged “Complied” in the list underneath — check them against the document on the left.</div>'
          + cvComplianceRowsHtml(d.fields)   // UPDATED (layout adjustment): rows, not one stacked list — see cvComplianceRowsHtml()
          + (d.comment ? '<div class="mcp-note"><strong>Your message to the company:</strong> '+escapeHtml(d.comment)+'</div>' : '');
        panel.style.display = 'block';
        d.fields.forEach(f=>{
            const cb = document.querySelector('#moaRejectFlagsGrid input[type="checkbox"][value="'+String(f.key||'').replace(/[^\w-]/g,'')+'"]');
            const item = cb ? cb.closest('.moa-reject-flag-item') : null;
            if (item && !item.querySelector('.complied-tag')) {
                item.classList.add('complied');
                const tag = document.createElement('span'); tag.className = 'complied-tag'; tag.textContent = 'Complied';
                item.appendChild(tag);
            }
        });
    }).catch(()=>{}).then(()=>{ cvMoaLoadDone(loadTok, 'compliance'); });   // NEW (this adjustment): loaded (or failed) — either way it's done
}

// NEW (this adjustment): after a New MOA / Revision Complied notification has taken the admin to the company's panel,
// open the Review MOA preview by itself — but only when there actually is something to review (the "Review MOA" button
// is on the row, i.e. the MOA is still in "Pending for Review"); otherwise the admin simply lands on the MOA tab.
function cvOpenReviewMoaAfterFocus(userId){
    setTimeout(()=>{
        const btn = document.querySelector('.company-row[data-uid="'+userId+'"] .moa-wf-btn.review');
        if (btn) openMoaTableFlagModal(userId);
    }, 700);
}
// The reload fallback in viewMoaNotification() (the company's row wasn't in this page's HTML yet).
document.addEventListener('DOMContentLoaded', ()=>{
    let uid = null;
    try{ uid = sessionStorage.getItem('cv_open_moa_uid'); }catch(e){}
    if (!uid) return;
    try{ sessionStorage.removeItem('cv_open_moa_uid'); }catch(e){}
    setTimeout(()=>{ cvOpenReviewMoaAfterFocus(uid); }, 400);
});

// ════════════════════════════════════════════════════════════════════════
//  UPDATED (this adjustment) — NOTIFICATION TOASTS AT THE TOP, IN THE ORIGINAL DESIGN
//  Every notification type's toast (see showMoaNewRequestToast()) is shown here: at the TOP of the page, as the original
//  one-line toast — icon, "Company" in gold, what happened, "— check the Notification Inbox." — fading in and out, gone by
//  itself after 7 seconds. Several can be on screen at once (newest below the older ones), and all of them sit BELOW the
//  undo panel whenever it is showing: cvLayoutTopToasts() recomputes every toast's `top`, and a MutationObserver on the undo
//  panel's class list re-runs it the moment the panel appears or disappears, so the two never overlap — without touching
//  any of the undo panel's own code.
// ════════════════════════════════════════════════════════════════════════
function cvLayoutTopToasts(){
    const undo = document.getElementById('undoToast');
    let top = 30;   // same offset the undo panel uses
    if (undo && undo.classList.contains('show')) top += undo.offsetHeight + 12;
    document.querySelectorAll('.cv-top-toast').forEach(el=>{
        el.style.top = top + 'px';
        top += el.offsetHeight + 12;
    });
}
(function(){
    const undo = document.getElementById('undoToast');
    if (undo && window.MutationObserver) new MutationObserver(cvLayoutTopToasts).observe(undo, {attributes:true, attributeFilter:['class']});
})();
function cvShowTopToast(companyName, messageText, iconClass){
    const div = document.createElement('div');
    div.className = 'cv-top-toast';
    div.setAttribute('role', 'status');
    // the original toast's content: icon + "<strong>Company</strong> what happened — check the Notification Inbox."
    div.innerHTML = '<i class="fas '+escapeHtml(iconClass || 'fa-file-arrow-up')+'"></i><span><strong>'+escapeHtml(companyName)+'</strong> '+escapeHtml(messageText)+' \u2014 check the Notification Inbox.</span>';
    document.body.appendChild(div);
    cvLayoutTopToasts();
    // next frame, so the browser has painted the hidden state and the fade-in actually animates
    requestAnimationFrame(()=>{ div.classList.add('show'); });
    setTimeout(()=>{
        div.classList.remove('show');
        setTimeout(()=>{ div.remove(); cvLayoutTopToasts(); }, 400);
    }, 7000);
}

// ════════════════════════════════════════════════════════════════════════
//  NEW (this adjustment) — LIVE REQUIREMENT UPLOADS
//  When a company uploads (or re-uploads) a requirement on CompanyForm.php,
//  this page now notices by itself — no manual refresh:
//    1. cvPollRequirementUploads() asks the lightweight
//       ajax_poll_requirement_uploads probe every 5s which companies have
//       newly uploaded requirement rows since the last check (and pauses
//       while the tab is hidden, catching up the moment it is shown again);
//    2. for each such company, cvRefreshRequirementsLive() re-reads that
//       company's row and swaps in ONLY the requirement cards whose files
//       changed. Cards that did not change are left exactly as they are
//       (so an unsaved Status / Remark choice is not wiped), and so are the
//       row's expanded state, active tab and the MOA tab's typed comment.
//       The summary counts / progress / percent ring are recounted, and the
//       new cards are flagged "New upload" for a few seconds;
//    3. it then asks for an immediate notification check, so the matching
//       "Requirement Uploaded" inbox entry + toast + badge appear within
//       seconds rather than on the next 15s tick.
// ════════════════════════════════════════════════════════════════════════
const _cvLiveInFlight = {}, _cvLiveQueued = {};
function cvRefreshRequirementsLive(uid){
    uid = String(uid || ''); if (!uid) return;
    if (_cvLiveInFlight[uid]) { _cvLiveQueued[uid] = true; return; }   // one refresh per company at a time; re-run once if another was asked for meanwhile
    _cvLiveInFlight[uid] = true;
    const fd = new FormData();
    fd.append('ajax_fetch_company_row','1');
    fd.append('user_id', uid);
    fetch(SELF,{method:'POST',body:fd}).then(r=>r.json()).then(data=>{ cvApplyLiveRequirementRow(uid, data); })
        .catch(()=>{})
        .then(()=>{
            _cvLiveInFlight[uid] = false;
            if (_cvLiveQueued[uid]) { _cvLiveQueued[uid] = false; cvRefreshRequirementsLive(uid); }
        });
}
function cvApplyLiveRequirementRow(uid, data){
    if (!data || !data.success || !data.html) return;
    const temp = document.createElement('div');
    temp.innerHTML = data.html.trim();
    const newRow = temp.firstElementChild; if (!newRow) return;
    const row = document.querySelector(`.company-row[data-uid="${uid}"]`);

    // Not on the page yet (e.g. a company that just registered): insert it — unless it is already
    // fully Verified, in which case it belongs to the Company List, not this queue.
    if (!row) {
        // UPDATED (this adjustment): a "New" row's MOA Status cell can say "Verified" (the MOA is done) while the company still has open
        // requirements, so "fully verified" now means EVERY status cell of the row says Verified (its MOA Status and its Requirement
        // Status; an "Existing" row has just the one, so it is checked exactly as before).
        const stCells = Array.from(newRow.querySelectorAll('.overall-status-cell'));
        if (stCells.length && stCells.every(c => c.textContent.trim() === '● Verified')) return;
        liveInsertOrUpdateCompanyRow(uid);
        return;
    }

    const oldGallery = row.querySelector('.cv-gallery'), newGallery = newRow.querySelector('.cv-gallery');
    if (!oldGallery || !newGallery) { liveInsertOrUpdateCompanyRow(uid); return; }
    const oldCards = Array.from(oldGallery.querySelectorAll('.cv-req-card'));
    const newCards = Array.from(newGallery.querySelectorAll('.cv-req-card'));
    // A different set of requirements (e.g. the company's classification changed) is not a simple
    // upload — fall back to replacing the whole row the way every other live update does.
    const sameShape = oldCards.length === newCards.length && oldCards.every((c,i)=>c.getAttribute('data-req') === newCards[i].getAttribute('data-req'));
    if (!sameShape) { liveInsertOrUpdateCompanyRow(uid); return; }

    const changed = [];
    newCards.forEach((nc,i)=>{
        const oc = oldCards[i];
        if ((oc.getAttribute('data-req-sig') || '') !== (nc.getAttribute('data-req-sig') || '')) { oc.replaceWith(nc); changed.push(nc); }
    });
    if (!changed.length) return;

    cvRefreshReqSummary(uid);
    changed.forEach(card=>{
        card.classList.add('cv-card-just-uploaded');
        const pv = card.querySelector('.cv-card-preview');
        if (pv && !pv.querySelector('.cv-new-upload-tag')) {
            const tag = document.createElement('span');
            tag.className = 'cv-new-upload-tag';
            tag.innerHTML = '<i class="fas fa-file-arrow-up"></i> New upload';
            pv.appendChild(tag);
            setTimeout(()=>{ tag.remove(); }, 8000);
        }
        setTimeout(()=>{ card.classList.remove('cv-card-just-uploaded'); }, 5000);
    });
    // visible even while the row is still collapsed (same glow the MOA live updates use)
    row.classList.add('row-just-arrived');
    setTimeout(()=>{ row.classList.remove('row-just-arrived'); }, 2500);
}

let cvReqWatchAfterId = <?= (int)$cvReqBaselineMaxId ?>;
let cvReqWatchBusy = false;
function cvPollRequirementUploads(){
    if (cvReqWatchBusy || document.hidden) return;
    cvReqWatchBusy = true;
    const fd = new FormData();
    fd.append('ajax_poll_requirement_uploads','1');
    if (cvReqWatchAfterId !== null) fd.append('after_id', cvReqWatchAfterId);
    fetch(SELF,{method:'POST',body:fd}).then(r=>r.json()).then(data=>{
        cvReqWatchBusy = false;
        if (!data || !data.success) return;
        const uids = data.uids || [];
        cvReqWatchAfterId = data.max_id;
        uids.forEach(u=>cvRefreshRequirementsLive(u));
        if (uids.length && window._moaNotifBaselineReady) pollMoaNotifications();
    }).catch(()=>{ cvReqWatchBusy = false; });
}
setTimeout(()=>{ cvPollRequirementUploads(); setInterval(cvPollRequirementUploads, 5000); }, 3000);
document.addEventListener('visibilitychange', ()=>{ if (!document.hidden) cvPollRequirementUploads(); });

// ── NEW (this adjustment): "View Requirements" on a Requirement Uploaded notification — the
// requirements counterpart of focusMoaRowByUid(): finds the company's row, clears any
// search/filter/pagination hiding it, expands it, opens the Requirements tab, and highlights the
// cards the notification names (plus a short banner). Returns false if the row isn't on the page.
function focusRequirementsRowByUid(uid, reqKeys){
    const row = document.querySelector(`.company-row[data-uid="${uid}"]`);
    if (!row) return false;

    // UPDATED (this adjustment): each table has its own search box now — clear both, so the row can't stay hidden by either.
    const searchInput = document.getElementById('searchInputExisting'), searchInputNew = document.getElementById('searchInputNew'), statusFilter = document.getElementById('statusFilter');
    if (searchInput) searchInput.value = '';
    if (searchInputNew) searchInputNew.value = '';
    if (statusFilter) statusFilter.value = 'All';
    cvResetTableFilters();   // NEW (this adjustment): the per-table company type / MOA status filters too
    filterAll();
    ['existing','new'].forEach(which=>{
        const idx = _pagers[which].filteredRows.indexOf(row);
        if (idx >= 0) goToPage(Math.floor(idx / ROWS_PER_PAGE) + 1, which);
    });

    const toggle = row.querySelector('.toggle-input');
    if (toggle) {
        document.querySelectorAll('.toggle-input').forEach(cb => { if (cb !== toggle) cb.checked = false; });
        toggle.checked = true;
    }
    cvActivateTab(row, 'req');

    (reqKeys || []).forEach(k=>{
        const safe = String(k).replace(/[^\w-]/g, '');
        const card = safe ? row.querySelector(`.cv-req-card[data-req="${safe}"]`) : null;
        if (!card) return;
        card.classList.add('cv-card-just-uploaded');
        setTimeout(()=>{ card.classList.remove('cv-card-just-uploaded'); }, 5000);
    });
    const panel = row.querySelector('.cv-tabpanel[data-tabpanel="req"]');
    if (panel && !panel.querySelector('.cv-uploaded-banner')) {
        const banner = document.createElement('div');
        banner.className = 'cv-uploaded-banner';
        banner.innerHTML = '<i class="fas fa-file-arrow-up"></i> New requirement upload — the uploaded document(s) are highlighted below.';
        panel.insertBefore(banner, panel.firstChild);
        setTimeout(()=>{ banner.remove(); }, 6000);
    }
    setTimeout(()=>{ row.scrollIntoView({behavior:'smooth', block:'center'}); }, 150);
    return true;
}
// After the reload fallback in viewMoaNotification() (the company's row wasn't in this page's HTML yet).
document.addEventListener('DOMContentLoaded', ()=>{
    let uid = null;
    try{ uid = sessionStorage.getItem('cv_focus_req_uid'); }catch(e){}
    if (!uid) return;
    try{ sessionStorage.removeItem('cv_focus_req_uid'); }catch(e){}
    focusRequirementsRowByUid(uid, []);
});

// ══ UPDATED (this adjustment): loadMoaRequests() now also refreshes the
// `_lastKnownMoaStatuses` snapshot on every call, so opening the drawer
// manually never causes a false "just complied" toast to fire moments
// later from the background poller comparing against stale data. ══
function loadMoaRequests(){
    const body=document.getElementById('moaDrawerBody');
    body.innerHTML='<div class="moa-drawer-loading"><i class="fas fa-spinner fa-spin" style="font-size:24px;margin-bottom:10px;display:block;color:#66718D;"></i>Loading notifications…</div>';
    const fd=new FormData();fd.append('ajax_fetch_moa_requests','1');
    fetch(SELF,{method:'POST',body:fd}).then(r=>r.json()).then(data=>{
        _allMoaRows=data.rows||[];
        _lastKnownMoaStatuses = snapshotMoaStatuses(_allMoaRows);
        updateMoaBadge(data.pending_count||0);
        filterMoaDrawer();
    }).catch(()=>{document.getElementById('moaDrawerBody').innerHTML='<div class="moa-drawer-empty"><i class="fas fa-exclamation-circle"></i><p>Failed to load notifications.</p></div>';});
}

function snapshotMoaStatuses(rows){
    const snap={};
    (rows||[]).forEach(r=>{ snap[r.id]=r.status; });
    return snap;
}

function filterMoaDrawer(){
    const search=(document.getElementById('moaDrawerSearch')?.value||'').toLowerCase();
    const sfVal=document.getElementById('moaDrawerFilter')?.value||'';
    const filtered=_allMoaRows.filter(r=>{
        const matchSearch=!search||(r.company_name||'').toLowerCase().includes(search)||(r.contact_name||'').toLowerCase().includes(search);
        let matchStatus=true;
        if(sfVal==='Pending') matchStatus=r.status==='Pending';
        else if(sfVal==='Accepted') matchStatus=r.status==='Approved';
        else if(sfVal==='Rejected') matchStatus=r.status==='Rejected';
        return matchSearch&&matchStatus;
    });
    renderMoaDrawer(filtered);
}

// ══ UPDATED (this adjustment): renderMoaDrawer() accepts an optional Set
// of MOA ids that should render with a one-time "just complied" highlight
// (blue glow) so the admin's eye is drawn straight to the row(s) that
// flipped without them needing to hunt for it. ══
function renderMoaDrawer(rows, justRevisedIds){
    const body=document.getElementById('moaDrawerBody');
    const pendingCount=rows.filter(r=>r.status==='Pending').length;
    document.getElementById('moaDrawerPendingCount').textContent=pendingCount+' Pending';
    if(!rows.length){body.innerHTML='<div class="moa-drawer-empty"><i class="fas fa-file-signature"></i><p>No notifications found.</p></div>';return;}
    body.innerHTML=rows.map(r=>buildMoaCard(r, !!(justRevisedIds && justRevisedIds.has(r.id)))).join('');
}

// ── UPDATED (adjustment): Accept/Reject buttons on a MOA card are now
// conditional on `r.awaiting_resubmission`. This flag is true for a
// LEGACY revision row (one that still has its flagged field(s) blank —
// i.e. rows created by the old recreate-on-reject behavior, before this
// update) for as long as those fields are still blank. New rejections
// created by the current "Reject & Send" flow never set this flag, since
// they no longer blank fields — see the isNeedsRevision branch in
// buildMoaCard() below for how those are handled instead.
//
// ── FURTHER UPDATED (this adjustment):
//   1) The request-type chip (typeLabel/typeClass) now ALWAYS reflects the
//      actual request_type ("Has MOA" vs "New MOA") and is never swapped
//      out for revision wording — a rejection only ever changes the
//      STATUS, never the request type, per the requested behavior.
//   2) The single status badge is now produced by getMoaStatusInfo(r) so
//      there is exactly one "Needs Revision" (not complied yet) / "Revised"
//      (company complied) label per card instead of two redundant ones.
//   3) buildMoaCard() now accepts a `justRevised` flag; when true, the
//      card element gets the `moa-just-revised` class so a brief highlight
//      animation plays automatically once inserted into the DOM — no page
//      refresh required for the admin to notice the compliant resubmission.
function buildMoaCard(r, justRevised) {
    const status = r.status;
    const isPending  = status === 'Pending';
    const isAccepted = status === 'Approved';
    const isRejected = status === 'Rejected';

    // Request type chip — always the real request type, unaffected by rejection/revision.
    const typeLabel = (r.request_type === 'existing') ? 'Has MOA' : 'New MOA';
    const typeClass = (r.request_type === 'existing') ? 'existing' : '';

    const awaitingResubmission = isPending && !!r.awaiting_resubmission;

    // Single status display — computed once, shown once.
    const statusInfo = getMoaStatusInfo(r);
    const badgeHtml = `<span class="moa-status-badge ${statusInfo.cls}">${statusInfo.text}</span>`;

    // ── ADJUSTMENT: a freshly-rejected request (status === 'Rejected',
    // marked in place by the current "Reject & Send" flow) always shows
    // the flagged section(s) as "Needs correction" — it can never be
    // "resubmitted, ready for review" while its status is still Rejected,
    // since the company hasn't acted on it yet (moa_request.php flips the
    // status back to 'Pending' only once they resubmit). The older
    // awaitingResubmission-based logic (for any legacy Pending revision
    // rows still sitting in the table from before this update) is kept
    // completely intact underneath.
    const isNeedsRevision = isRejected;
    const flagsHtml = (r.is_revision && Array.isArray(r.revision_flags) && r.revision_flags.length)
        ? (isNeedsRevision
            ? `<div class="moa-card-flags"><i class="fas fa-flag"></i><span>Needs correction: ${r.revision_flags.map(f=>escapeHtml(MOA_REJECT_FLAG_LABELS[f]||f)).join(', ')}</span></div>`
            : (awaitingResubmission
                ? `<div class="moa-card-flags"><i class="fas fa-flag"></i><span>Needs correction: ${r.revision_flags.map(f=>escapeHtml(MOA_REJECT_FLAG_LABELS[f]||f)).join(', ')}</span></div>`
                : `<div class="moa-card-flags" style="color:#2C5A2C;background:#D9E8D2;border-color:#9DC08F;"><i class="fas fa-check-circle" style="color:#2C5A2C;"></i><span>Revised section(s): ${r.revision_flags.map(f=>escapeHtml(MOA_REJECT_FLAG_LABELS[f]||f)).join(', ')} — ready for review</span></div>`))
        : '';

    // ── "View PDF" and "Download" buttons removed from the inbox card.
    //    They are still available inside the Details modal (openMoaPreview). ──
    const detailsBtn = `<button class="moa-action-btn details-btn" onclick="openMoaPreview(${r.id})"><i class="fas fa-info-circle"></i> Details</button>`;

    let actionBtns = '';
    if (isPending) {
        if (awaitingResubmission) {
            // ── Legacy: Accept/Reject temporarily hidden while an OLD-style
            // revision row still has blank flagged field(s) waiting on the
            // company. New rejections never reach this branch since they
            // stay status='Rejected' (see isRejected below) until the
            // company actually resubmits via moa_request.php.
            actionBtns = `<span style="font-size:11px;color:#7A5A0B;font-weight:700;padding:7px 12px;background:#F3E7B5;border:1px solid #D4BC66;border-radius:0;display:inline-flex;align-items:center;gap:5px;"><i class="fas fa-hourglass-half"></i> Waiting for company to resubmit flagged section(s)</span>`;
        } else {
            actionBtns = `
                <button class="moa-action-btn accept-btn" onclick="moaDrawerAction(${r.id},'accept',this)">
                    <span class="moa-btn-spinner"></span><i class="fas fa-check-circle"></i> Accept
                </button>
                <button class="moa-action-btn reject-btn" onclick="openMoaRejectModal(${r.id})">
                    <i class="fas fa-times-circle"></i> Reject
                </button>`;
        }
    } else if (isAccepted) {
        actionBtns = `<span style="font-size:11px;color:#2C5A2C;font-weight:700;padding:7px 12px;background:#D9E8D2;border-radius:0;display:inline-flex;align-items:center;gap:5px;"><i class="fas fa-check-circle"></i> Accepted — check requirements table to review workflow</span>`;
    } else {
        // ── ADJUSTMENT: status is now genuinely 'Rejected' on the row
        // (rather than being recreated back to 'Pending'), so this simply
        // reflects that state — moa_request.php's own status view is what
        // now shows the company a "Needs Revision" badge and a "Revise"
        // button; once they resubmit, this same row flips back to
        // 'Pending' and reappears above with Accept/Reject buttons again.
        actionBtns = `<span style="font-size:11px;color:#A02A2A;font-weight:700;padding:7px 12px;background:#F2D5D1;border-radius:0;display:inline-flex;align-items:center;gap:5px;"><i class="fas fa-times-circle"></i> Needs Revision — waiting on company</span>`;
    }

    return `
        <div class="moa-card${justRevised ? ' moa-just-revised' : ''}" id="moaCard_${r.id}">
            <div class="moa-card-top">
                <div class="moa-card-info">
                    <div class="moa-card-company">${escapeHtml(r.company_name||'—')}</div>
                    <div class="moa-card-meta">
                        <span><i class="fas fa-user" style="font-size:10px;"></i> ${cvNotifPersonText(r.contact_name, r.position)}</span>
                        <span><i class="fas fa-clock" style="font-size:10px;"></i> ${escapeHtml(r.submitted_at||'—')}</span>
                    </div>
                    <div class="moa-card-address"><i class="fas fa-map-marker-alt" style="font-size:10px;"></i> ${escapeHtml(r.company_address||'—')}</div>
                    ${flagsHtml}
                </div>
                <div style="display:flex;flex-direction:column;align-items:flex-end;gap:6px;flex-shrink:0;">
                    ${badgeHtml}
                    <span class="moa-card-type-chip ${typeClass}">${typeLabel}</span>
                </div>
            </div>
            <div class="moa-card-actions">
                ${detailsBtn}
                ${actionBtns}
            </div>
        </div>`;
}

function moaDrawerAction(moaId, action, btn) {
    const card=document.getElementById('moaCard_'+moaId);
    const allBtns=card?card.querySelectorAll('button, a.moa-action-btn'):[];
    allBtns.forEach(b=>{if(b.tagName!=='A')b.disabled=true;});
    const spinner=btn?btn.querySelector('.moa-btn-spinner'):null;
    if(spinner)spinner.style.display='inline-block';

    const fd=new FormData();
    fd.append('ajax_moa_drawer_action','1');
    fd.append('moa_id',moaId);
    fd.append('action',action);

    fetch(SELF,{method:'POST',body:fd})
        .then(r=>{const ct=r.headers.get('content-type')||'';if(!ct.includes('application/json'))return r.text().then(t=>{throw new Error('Server returned non-JSON:\n'+t.substring(0,300));});return r.json();})
        .then(data=>{
            if(!data.success){allBtns.forEach(b=>{if(b.tagName!=='A')b.disabled=false;});if(spinner)spinner.style.display='none';showGuardModal('','Error',data.message||'Failed.');return;}
            if(action==='accept'){
                const row=_allMoaRows.find(r=>r.id===moaId);
                if(row)row.status='Approved';
                _lastKnownMoaStatuses[moaId]='Approved';
                updateMoaBadge(data.pending_count||0);
                filterMoaDrawer();

                // Remember which company's MOA row to auto-highlight, then reload so
                // the requirements table (rendered server-side) picks up the freshly
                // transferred MOA document and its in-table workflow UI "just appears".
                if (data.user_id) {
                    try{ sessionStorage.setItem('moa_focus_uid', data.user_id); }catch(e){}
                }

                // Honor the admin's "don't show again" preference. If the
                // success popup is currently suppressed (checked within the last
                // 24 hours), skip showing it and go straight to the page reload
                // that reveals the newly-accepted MOA row.
                if (isGuardModalSuppressed(MOA_ACCEPT_SUPPRESS_KEY)) {
                    window.location.reload();
                    return;
                }

                showGuardModal('','MOA Accepted!',
                    `The MOA document from <strong>${escapeHtml(card?.querySelector('.moa-card-company')?.textContent||'')}</strong> has been accepted and placed in their <strong>Requirements Table</strong> under "MOA Document".<br><br>
                    The page will refresh and automatically open that company's row below so you can start the in-table workflow (<strong>Pending → Reviewing → Approved → Set Signing Schedule → Done</strong>) right away.`,
                    { suppressKey: MOA_ACCEPT_SUPPRESS_KEY }
                );
                document.querySelector('#guardModal button').addEventListener('click',()=>{
                    window.location.reload();
                },{once:true});
            } else {
                const row=_allMoaRows.find(r=>r.id===moaId);
                if(row)row.status='Rejected';
                _lastKnownMoaStatuses[moaId]='Rejected';
                updateMoaBadge(data.pending_count||0);
                filterMoaDrawer();
            }
        })
        .catch(err=>{allBtns.forEach(b=>{if(b.tagName!=='A')b.disabled=false;});if(spinner)spinner.style.display='none';showGuardModal('','Request Failed',err.message||'Network error.');});
}

// (updateMoaBadge is now defined once, above, alongside the new
// notification-inbox functions — this legacy duplicate was removed so
// there is a single source of truth for the badge wording ("New" instead
// of "Pending", since there is no more per-row status here.)

// ════════════════════════════════════════════════════════
//  NEW (this adjustment): LIVE "COMPANY COMPLIED" DETECTION
//  ────────────────────────────────────────────────────────
//  Periodically re-fetches the MOA requests list in the background and
//  compares the freshly-fetched statuses against the last known snapshot.
//  Whenever a row that was previously 'Rejected' becomes compliant (i.e.
//  it flips to 'Pending' with is_revision=1 and no more outstanding
//  blanked flagged fields — see isMoaRowCompliant()), that means the
//  company has just filled in the flagged area(s) and resubmitted via
//  moa_request.php. The admin UI reacts immediately, without any manual
//  refresh:
//    1. The MOA nav/sidebar badge counts update.
//    2. If the MOA drawer is currently open, its card list re-renders in
//       place — the compliant card automatically regains its Accept/Reject
//       buttons and shows the "Revised — Pending Review" status, with a
//       brief highlight glow so it's easy to spot.
//    3. A small toast notification appears (regardless of whether the
//       drawer is open) so the admin knows a company just complied, even
//       if they're working elsewhere on the page.
// ════════════════════════════════════════════════════════
function pollMoaRequestsData(){
    if (_moaPollInFlight) return; // avoid overlapping requests
    _moaPollInFlight = true;
    const fd = new FormData();
    fd.append('ajax_fetch_moa_requests','1');
    fetch(SELF, {method:'POST', body:fd})
        .then(r=>r.json())
        .then(data=>{
            _moaPollInFlight = false;
            if(!data.success) return;
            const newRows = data.rows || [];

            // Detect any row that just transitioned from Rejected -> compliant
            // (Pending, is_revision, no more outstanding blanked flags).
            const justCompliedIds = new Set();
            newRows.forEach(nr=>{
                const prevStatus = _lastKnownMoaStatuses[nr.id];
                if (prevStatus === 'Rejected' && isMoaRowCompliant(nr)) {
                    justCompliedIds.add(nr.id);
                }
            });

            // Refresh the working dataset + snapshot for the next comparison.
            _allMoaRows = newRows;
            _lastKnownMoaStatuses = snapshotMoaStatuses(newRows);
            updateMoaBadge(data.pending_count || 0);

            if (justCompliedIds.size === 0) return;

            // If the drawer is currently open, re-render its visible cards so
            // the compliant request's Accept/Reject buttons and "Revised —
            // Pending Review" status appear immediately, highlighted.
            if (document.getElementById('moaDrawer').classList.contains('open')) {
                const search=(document.getElementById('moaDrawerSearch')?.value||'').toLowerCase();
                const sfVal=document.getElementById('moaDrawerFilter')?.value||'';
                const filtered=_allMoaRows.filter(r=>{
                    const matchSearch=!search||(r.company_name||'').toLowerCase().includes(search)||(r.contact_name||'').toLowerCase().includes(search);
                    let matchStatus=true;
                    if(sfVal==='Pending') matchStatus=r.status==='Pending';
                    else if(sfVal==='Accepted') matchStatus=r.status==='Approved';
                    else if(sfVal==='Rejected') matchStatus=r.status==='Rejected';
                    return matchSearch&&matchStatus;
                });
                renderMoaDrawer(filtered, justCompliedIds);
            }

            // Notify the admin with a small, non-blocking toast for each
            // company that just resubmitted — works whether or not the
            // drawer is open, so the admin never has to guess or refresh.
            newRows.forEach(nr=>{
                if (justCompliedIds.has(nr.id)) {
                    showMoaComplianceToast(nr.company_name || 'A company');
                }
            });
        })
        .catch(()=>{ _moaPollInFlight = false; });
}

// Stacks multiple compliance toasts vertically instead of overlapping.
function showMoaComplianceToast(companyName){
    const existing = document.querySelectorAll('.moa-compliance-toast').length;
    const div = document.createElement('div');
    div.className = 'moa-compliance-toast';
    div.style.bottom = (24 + existing * 64) + 'px';
    div.innerHTML = '<i class="fas fa-check-circle"></i><span><strong>'+escapeHtml(companyName)+'</strong> resubmitted their revised MOA request — it\'s ready for your review.</span>';
    document.body.appendChild(div);
    // Force a reflow so the transition to .show actually animates in.
    requestAnimationFrame(()=>{ div.classList.add('show'); });
    setTimeout(()=>{
        div.classList.remove('show');
        setTimeout(()=>{ div.remove(); }, 400);
    }, 7000);
}

// ── MOA Blob Preview (moa_requests table) — still used by the Details modal's "Preview" button
function openMoaBlobModal(moaId, companyNameOverride) {
    const row=_allMoaRows.find(r=>r.id===moaId);
    const companyName=companyNameOverride||(row?row.company_name:'');
    if(row&&!row.has_pdf){showGuardModal('','No Document','No MOA document has been uploaded for <strong>'+escapeHtml(companyName)+'</strong> yet.');return;}
    // ── NEW (adjustment): if the uploaded document itself was flagged on a
    // Rejected request, the file on record is stale and awaiting a company
    // reupload — block opening the preview and explain why instead.
    if (row && row.status === 'Rejected' && Array.isArray(row.revision_flags) && row.revision_flags.includes('moa_document')) {
        showGuardModal('','Document Locked','The uploaded MOA document for <strong>'+escapeHtml(companyName)+'</strong> was flagged during rejection and is locked from viewing until the company uploads a new file and resubmits.');
        return;
    }
    const modal=document.getElementById('moaBlobModal'),loader=document.getElementById('moaBlobModalLoader');
    const pdfFrame=document.getElementById('moaBlobPdfFrame'),imgWrap=document.getElementById('moaBlobImgWrap');
    const img=document.getElementById('moaBlobImg'),noFile=document.getElementById('moaBlobNoFile');
    const dlBtn=document.getElementById('moaBlobDownloadBtn'),cName=document.getElementById('moaBlobCompanyName');
    pdfFrame.style.display='none';imgWrap.style.display='none';noFile.style.display='none';
    loader.style.opacity='1';loader.style.display='flex';pdfFrame.src='';img.src='';
    cName.textContent=companyName;
    const blobUrl=SELF+'?stream_moa_blob='+moaId;
    dlBtn.href=blobUrl;dlBtn.download=(row?row.pdf_filename:'')||('moa_'+moaId+'.pdf');
    modal.classList.add('open');
    const filename=((row?row.pdf_filename:'')||'').toLowerCase(),isImg=/\.(png|jpg|jpeg|gif|webp)$/i.test(filename);
    if(isImg){img.onload=function(){loader.style.display='none';imgWrap.style.display='flex';};img.onerror=function(){loader.style.display='none';noFile.style.display='flex';};img.src=blobUrl;}
    else{pdfFrame.onload=function(){loader.style.display='none';pdfFrame.style.display='block';};pdfFrame.onerror=function(){loader.style.display='none';noFile.style.display='flex';};pdfFrame.src=blobUrl;setTimeout(()=>{if(loader.style.display!=='none'){loader.style.display='none';pdfFrame.style.display='block';}},6000);}
}
function closeMoaBlobModal(){const modal=document.getElementById('moaBlobModal'),pdfFrame=document.getElementById('moaBlobPdfFrame'),img=document.getElementById('moaBlobImg');modal.classList.remove('open');pdfFrame.src='';img.src='';}

// ── MOA Details Modal (View PDF + Download live here now)
// ── UPDATED (adjustment): "Request Type" now always reflects the actual
//    request_type ("New MOA" / "Has MOA"), unaffected by rejection/revision;
//    the "Status" field now uses the single-source getMoaStatusInfo() helper
//    so it shows exactly one Needs-Revision/Revised label instead of the
//    old dual "Needs Revision" (type chip) + status text combination.
function openMoaPreview(moaId){
    const row=_allMoaRows.find(r=>r.id===moaId);if(!row){showGuardModal('','Not Found','MOA request not found.');return;}
    const flaggedSet = new Set(Array.isArray(row.revision_flags) ? row.revision_flags : []);
    const flagCls = (key) => flaggedSet.has(key) ? ' flagged' : '';
    // ── NEW (adjustment): the uploaded MOA document itself is treated as
    // "locked" whenever it was flagged on a request that is currently
    // Rejected — meaning the company has not yet uploaded a replacement
    // file and resubmitted. Once they resubmit, moa_request.php flips the
    // row's status back to 'Pending' (this same admin list simply reflects
    // that), which naturally clears this lock again without any extra
    // state needing to be tracked here.
    const moaDocFlagged = row.status === 'Rejected' && flaggedSet.has('moa_document');

    // Request type chip — always the real request type.
    const typeLabel = (row.request_type === 'existing') ? 'Has MOA' : 'New MOA';
    const typeClass = (row.request_type === 'existing') ? 'existing' : '';

    // Single status display, computed once.
    const statusInfo = getMoaStatusInfo(row);

    document.getElementById('moaPreviewContent').innerHTML=`
        <div class="preview-field"><span class="preview-label">Company Name</span><span class="preview-value${flagCls('company_name')}">${escapeHtml(row.company_name||'—')}</span></div>
        <div class="preview-field"><span class="preview-label">Address</span><span class="preview-value${flagCls('company_address')}">${escapeHtml(row.company_address||'—')}</span></div>
        <div class="preview-field"><span class="preview-label">Contact Person</span><span class="preview-value${flagCls('contact_first_name')||flagCls('contact_middle_name')||flagCls('contact_last_name')}">${escapeHtml(row.contact_name||'—')}</span></div>
        <div class="preview-field"><span class="preview-label">Position</span><span class="preview-value${flagCls('position')}">${escapeHtml(row.position||'—')}</span></div>
        <div class="preview-field"><span class="preview-label">Request Type</span><span class="preview-value"><span class="moa-card-type-chip ${typeClass}">${typeLabel}</span></span></div>
        <div class="preview-field"><span class="preview-label">Status</span><span class="preview-value">${statusInfo.text}</span></div>
        <div class="preview-field"><span class="preview-label">Submitted</span><span class="preview-value">${escapeHtml(row.submitted_at||'—')}</span></div>
        ${flaggedSet.size ? `<div class="preview-field"><span class="preview-label">Flagged Section(s)</span><span class="preview-value flagged">${Array.from(flaggedSet).map(f=>escapeHtml(MOA_REJECT_FLAG_LABELS[f]||f)).join(', ')}</span></div>` : ''}
        ${row.revision_comment ? `<div class="preview-field"><span class="preview-label">Admin Note</span><span class="preview-value">${escapeHtml(row.revision_comment)}</span></div>` : ''}
        ${row.has_pdf?`<div class="preview-field"><span class="preview-label">MOA Document</span><span class="preview-value" style="display:flex;gap:8px;align-items:center;flex-wrap:wrap;">
            ${moaDocFlagged
                ? `<button class="moa-action-btn view-btn locked" disabled title="This document was flagged during rejection. It stays locked until the company uploads a new MOA document and resubmits." style="font-size:12px;padding:6px 14px;"><i class="fas fa-eye-slash"></i> View PDF (Locked)</button>`
                : `<button class="moa-action-btn view-btn" onclick="closeMoaPreview();openMoaBlobModal(${row.id});" style="font-size:12px;padding:6px 14px;"><i class="fas fa-eye"></i> View PDF</button>`
            }
            <a href="moa_request.php?download_moa=${row.id}" class="moa-action-btn pdf-btn" target="_blank" style="font-size:12px;padding:6px 14px;"><i class="fas fa-file-pdf"></i> Download</a>
            ${moaDocFlagged ? `<span class="moa-doc-locked-note"><i class="fas fa-lock"></i> Awaiting company reupload</span>` : ''}
        </span></div>`:'<div class="preview-field"><span class="preview-label">MOA Document</span><span class="preview-value" style="color:#66718D;">No PDF uploaded</span></div>'}
    `;
    const actions=document.getElementById('moaPreviewActions');
    const closeBtn=`<button class="moa-action-btn details-btn" onclick="closeMoaPreview()"><i class="fas fa-times"></i> Close</button>`;
    if(row.status==='Pending'){
        if (row.awaiting_resubmission) {
            // ── Legacy fallback for old-style revision rows — kept intact.
            actions.innerHTML=`<span style="font-size:12px;color:#7A5A0B;font-weight:700;padding:8px 14px;background:#F3E7B5;border:1px solid #D4BC66;border-radius:0;display:inline-flex;align-items:center;gap:6px;"><i class="fas fa-hourglass-half"></i> Waiting for company to resubmit flagged section(s)</span>${closeBtn}`;
        } else {
            actions.innerHTML=`<button class="moa-action-btn accept-btn" onclick="closeMoaPreview();moaDrawerAction(${row.id},'accept',null);"><i class="fas fa-check-circle"></i> Accept</button><button class="moa-action-btn reject-btn" onclick="closeMoaPreview();openMoaRejectModal(${row.id});"><i class="fas fa-times-circle"></i> Reject</button>${closeBtn}`;
        }
    } else {
        actions.innerHTML=closeBtn;
    }
    document.getElementById('moaPreviewModal').classList.add('open');
}
function closeMoaPreview(){document.getElementById('moaPreviewModal').classList.remove('open');}
document.getElementById('moaPreviewModal').addEventListener('click',function(e){if(e.target===this)closeMoaPreview();});

// ════════════════════════════════════════════════════════
//  MOA REJECT MODAL — Preview (left) + Flags/Comment/Send (right)
//  Opens the same PDF/image preview used by "View MOA", a checklist of
//  which section(s) of the submission are erroneous, and a message box.
//  Sending emails the company (with the MOA file attached and the flagged
//  sections listed) and, only once that email is confirmed sent, marks
//  the request as Rejected — the company then sees a "Needs Revision"
//  status on moa_request.php and can edit the flagged field(s) directly
//  there and resubmit.
// ════════════════════════════════════════════════════════
let _moaRejectCurrentId = null;
// ── NEW (this adjustment): the reject/flag modal above is now shared by
// two triggers — the legacy inbox reject flow (mode 'inbox', keyed by a
// moa_requests id) and the new "Flag for Revision" button on the merged
// "Pending for Review" stage in the New MOA table (mode 'table', keyed by
// a company's user_id). sendMoaRejectionEmail() below branches on
// _moaRejectMode to call the right endpoint.
let _moaRejectMode = 'inbox';
let _moaRejectCurrentUserId = null;

// ── NEW (this adjustment): the Review MOA "load page". Opening the modal starts a load (cvMoaLoadBegin) that lists what has
// to finish first — the document itself ('doc'), the contact details ('info') and, for the table's Review MOA, the revision
// details ('compliance'). Each piece reports back (cvMoaLoadDone) when it has loaded, failed or timed out, and the page hides
// once none are left, so the admin only ever sees the MOA when it is really ready. A token ties every report to the load that
// asked for it, so a late answer from an earlier company can't hide (or keep showing) the page for the current one, and a
// failsafe hides it after 12 s at the very most, so it can never get stuck.
let _moaLoadToken = 0, _moaLoadPending = null, _moaLoadFailsafe = null;
function cvMoaLoadBegin(keys){
    _moaLoadToken++;
    _moaLoadPending = {};
    (keys || []).forEach(k => { _moaLoadPending[k] = true; });
    const el = document.getElementById('moaRejectLoadingPage');
    if (el) el.classList.remove('hidden');
    clearTimeout(_moaLoadFailsafe);
    const tok = _moaLoadToken;
    _moaLoadFailsafe = setTimeout(() => { if (tok === _moaLoadToken) cvMoaLoadFinish(); }, 12000);
    return tok;
}
function cvMoaLoadDone(tok, key){
    if (tok !== _moaLoadToken || !_moaLoadPending) return;
    delete _moaLoadPending[key];
    if (Object.keys(_moaLoadPending).length === 0) cvMoaLoadFinish();
}
function cvMoaLoadFinish(){
    clearTimeout(_moaLoadFailsafe);
    _moaLoadPending = null;
    const el = document.getElementById('moaRejectLoadingPage');
    if (el) el.classList.add('hidden');
}
function cvMoaLoadCancel(){ _moaLoadToken++; cvMoaLoadFinish(); }   // the modal was closed — nothing left to wait for

function openMoaRejectModal(moaId) {
    const row = _allMoaRows.find(r => r.id === moaId);
    if (!row) { showGuardModal('','Not Found','MOA request not found.'); return; }
    _moaRejectMode = 'inbox';
    _moaRejectCurrentId = moaId;
    _moaRejectCurrentUserId = null;
    const loadTok = cvMoaLoadBegin(['doc', 'info']);   // NEW (this adjustment): show the Review MOA load page until the MOA has loaded

    // Legacy inbox flow keeps its original "Reject MOA — " title/icon,
    // and never shows the in-modal "Approve MOA" button (that flow's own
    // separate Accept/Reject drawer buttons are unaffected).
    document.getElementById('moaRejectModalTitleIcon').className = 'fas fa-times-circle';
    document.getElementById('moaRejectModalTitlePrefix').textContent = 'Reject MOA — ';
    const approveBtn = document.getElementById('moaReviewApproveBtn');
    if (approveBtn) approveBtn.style.display = 'none';

    document.getElementById('moaRejectCompanyName').textContent = row.company_name || '';
    document.getElementById('moaRejectInfoCompany').textContent = row.company_name || '—';
    document.getElementById('moaRejectInfoContact').textContent = row.contact_name || '—';
    document.getElementById('moaRejectInfoTelephone').textContent = 'Loading telephone…';   // NEW (this adjustment)
    document.getElementById('moaRejectInfoEmail').textContent = 'Loading email…';
    { const cpEl = document.getElementById('moaRejectInfoProfile'); if (cpEl) cpEl.textContent = 'Loading company profile…'; }   // NEW (this adjustment)
    document.getElementById('moaRejectComment').value = '';
    resetMoaRejectSendBtn();

    // Reset the flag checklist for a fresh selection each time the modal opens.
    const flagsGrid = document.getElementById('moaRejectFlagsGrid');
    if (flagsGrid) {
        flagsGrid.querySelectorAll('input[type="checkbox"]').forEach(cb => {
            cb.checked = false;
            cb.closest('.moa-reject-flag-item')?.classList.remove('checked');
        });
    }

    // Preview pane — same streaming endpoint used by "View MOA" (openMoaBlobModal)
    const loader = document.getElementById('moaRejectModalLoader');
    const pdfFrame = document.getElementById('moaRejectPdfFrame');
    const imgWrap = document.getElementById('moaRejectImgWrap');
    const img = document.getElementById('moaRejectImg');
    const noFile = document.getElementById('moaRejectNoFile');
    pdfFrame.style.display = 'none'; imgWrap.style.display = 'none'; noFile.style.display = 'none';
    pdfFrame.src = ''; img.src = '';

    if (!row.has_pdf) {
        loader.style.display = 'none';
        noFile.style.display = 'flex';
        cvMoaLoadDone(loadTok, 'doc');
    } else {
        loader.style.display = 'flex';
        const blobUrl = SELF + '?stream_moa_blob=' + moaId;
        const filename = (row.pdf_filename || '').toLowerCase();
        const isImg = /\.(png|jpg|jpeg|gif|webp)$/i.test(filename);
        if (isImg) {
            img.onload = function(){ loader.style.display='none'; imgWrap.style.display='flex'; cvMoaLoadDone(loadTok, 'doc'); };
            img.onerror = function(){ loader.style.display='none'; noFile.style.display='flex'; cvMoaLoadDone(loadTok, 'doc'); };
            img.src = blobUrl;
        } else {
            pdfFrame.onload = function(){ loader.style.display='none'; pdfFrame.style.display='block'; if (pdfFrame.getAttribute('src')) cvMoaLoadDone(loadTok, 'doc'); };
            pdfFrame.onerror = function(){ loader.style.display='none'; noFile.style.display='flex'; cvMoaLoadDone(loadTok, 'doc'); };
            pdfFrame.src = blobUrl;
            setTimeout(()=>{ if(loader.style.display!=='none'){ loader.style.display='none'; pdfFrame.style.display='block'; cvMoaLoadDone(loadTok, 'doc'); } }, 6000);
        }
    }

    fetchMoaContactEmail(moaId);

    document.getElementById('moaRejectModal').classList.add('open');
}

// ── NEW (this adjustment): opens the SAME reject/flag modal, but for the
// "Flag for Revision" button on a company's row in the New MOA table
// (merged "Pending for Review" stage), keyed by user_id instead of a
// moa_requests id. Company/contact info and the PDF preview are sourced
// straight from that row's own DOM/endpoints (company_requirements'
// stream_req_blob) rather than from _allMoaRows, since this company's
// moa_requests row may already be showing as "Rejected"/mid-revision by
// the time the admin flags it again.
function openMoaTableFlagModal(userId) {
    _moaRejectMode = 'table';
    _moaRejectCurrentUserId = userId;
    _moaRejectCurrentId = null;
    const loadTok = cvMoaLoadBegin(['doc', 'info', 'compliance']);   // NEW (this adjustment): show the Review MOA load page until the MOA has loaded

    // ── UPDATED (this adjustment): this is now the "Review MOA" preview —
    // no "Reject MOA — " label, a neutral review icon, and the in-modal
    // "Approve MOA" button is shown so the admin can approve straight
    // from here instead of needing a separate direct button on the row.
    document.getElementById('moaRejectModalTitleIcon').className = 'fas fa-file-signature';
    document.getElementById('moaRejectModalTitlePrefix').textContent = '';
    const approveBtn = document.getElementById('moaReviewApproveBtn');
    if (approveBtn) { approveBtn.style.display = 'flex'; approveBtn.disabled = false; approveBtn.querySelector('.moa-btn-spinner').style.display = 'none'; }

    const row = document.querySelector(`.company-row[data-uid="${userId}"]`);
    const companyName = row?.querySelector('.row-summary span')?.textContent?.trim() || '';
    const contactP = row?.querySelector('.info-side p');
    const contactName = contactP ? contactP.textContent.replace('Representative:','').trim() : '';

    document.getElementById('moaRejectCompanyName').textContent = companyName;
    document.getElementById('moaRejectInfoCompany').textContent = companyName || '—';
    document.getElementById('moaRejectInfoContact').textContent = contactName || '—';
    document.getElementById('moaRejectInfoTelephone').textContent = 'Loading telephone…';   // NEW (this adjustment)
    document.getElementById('moaRejectInfoEmail').textContent = 'Loading email…';
    { const cpEl = document.getElementById('moaRejectInfoProfile'); if (cpEl) cpEl.textContent = 'Loading company profile…'; }   // NEW (this adjustment)
    document.getElementById('moaRejectComment').value = '';
    resetMoaRejectSendBtn();

    const flagsGrid = document.getElementById('moaRejectFlagsGrid');
    if (flagsGrid) {
        flagsGrid.querySelectorAll('input[type="checkbox"]').forEach(cb => {
            cb.checked = false;
            cb.closest('.moa-reject-flag-item')?.classList.remove('checked');
        });
    }

    // Preview pane — reuse the already-transferred file in
    // company_requirements via the existing stream_req_blob endpoint.
    const loader = document.getElementById('moaRejectModalLoader');
    const pdfFrame = document.getElementById('moaRejectPdfFrame');
    const imgWrap = document.getElementById('moaRejectImgWrap');
    const img = document.getElementById('moaRejectImg');
    const noFile = document.getElementById('moaRejectNoFile');
    pdfFrame.style.display = 'none'; imgWrap.style.display = 'none'; noFile.style.display = 'none';
    pdfFrame.src = ''; img.src = '';

    loader.style.display = 'flex';
    const blobUrl = SELF + '?stream_req_blob=' + userId + '&req_type=moa_document';
    pdfFrame.onload = function(){ loader.style.display='none'; pdfFrame.style.display='block'; if (pdfFrame.getAttribute('src')) cvMoaLoadDone(loadTok, 'doc'); };
    pdfFrame.onerror = function(){ loader.style.display='none'; noFile.style.display='flex'; cvMoaLoadDone(loadTok, 'doc'); };
    pdfFrame.src = blobUrl;
    setTimeout(()=>{ if(loader.style.display!=='none'){ loader.style.display='none'; pdfFrame.style.display='block'; cvMoaLoadDone(loadTok, 'doc'); } }, 6000);

    fetchMoaContactEmail(null, userId);

    document.getElementById('moaRejectModal').classList.add('open');
    cvShowMoaCompliance(userId);   // NEW (this adjustment): if the company just complied with a flag, show what it updated
}

// ── NEW (this adjustment): approves the MOA directly from inside the
// "Review MOA" preview modal — the counterpart to "Send & Request
// Revision" in that same modal, so the admin can decide either way after
// actually reviewing the document, without a separate direct "Approve"
// button on the row. Mirrors advanceMoaReqStage(userId,'approved',...)'s
// success handling exactly, then closes the modal.
function approveMoaFromReviewModal() {
    if (!_moaRejectCurrentUserId) return;
    const userId = _moaRejectCurrentUserId;
    const btn = document.getElementById('moaReviewApproveBtn');
    // NEW (this adjustment): can't be approved while flagged section(s) are still waiting on the company (see cvApplyApproveLock()).
    if (btn && btn.classList.contains('moa-approve-locked')) { showGuardModal('','Flagged Sections Pending', MOA_APPROVE_LOCK_MSG); return; }
    const spinner = document.getElementById('moaReviewApproveSpinner');
    if (btn) btn.disabled = true;
    if (spinner) spinner.style.display = 'inline-block';
    showGlobalLoading('Approving');

    const commentInput = document.getElementById('moaComment_' + userId);
    const comment = commentInput ? commentInput.value.trim() : '';

    const fd = new FormData();
    fd.append('ajax_update_moa_req_workflow','1');
    fd.append('user_id', userId);
    fd.append('stage', 'approved');
    fd.append('comment', comment);
    fd.append('moa_undoable','1');   // NEW (this adjustment): the server keeps what's needed to undo this and holds the email back

    fetch(SELF, {method:'POST', body:fd})
        .then(r => r.json())
        .then(data => {
            hideGlobalLoading();
            if (!data.success) {
                if (btn) btn.disabled = false;
                if (spinner) spinner.style.display = 'none';
                if (data.guard === 'already_verified') {
                    showGuardModal('','Already Verified','MOA Document is already marked as Verified.');
                } else if (data.guard === 'flags_outstanding') {
                    cvApplyApproveLock(true);   // NEW (this adjustment): the server says a flag is still outstanding
                    showGuardModal('','Flagged Sections Pending', MOA_APPROVE_LOCK_MSG);
                } else {
                    showGuardModal('','Error', data.message || 'Failed to approve MOA.');
                }
                return;
            }
            updateMoaTblStepper(userId, data.stage);
            updateMoaStageChip(userId, data.stage);
            updateMoaWfActionBtns(userId, data.stage, data.schedule_datetime);
            // ── FIX (this adjustment): this is the actual "Approve MOA"
            // handler now that it lives inside the Review modal — it was
            // missing the row-summary live update entirely, so the
            // granular MOA progress label (see renderCompanyValidationRow())
            // kept showing the old stage until a manual reload. Mirrors
            // the same call advanceMoaReqStage() already makes for the
            // Set Signing Schedule / Re-Schedule actions.
            updateRowSummaryMoaProgress(userId, data.stage, false);
            closeMoaRejectModal();
            // NEW (this adjustment): the undo toast, like a requirement's
            if (data.undo_token) startMoaUndoToast(data.undo_token, data.undo_label, userId, data.undo_action);
        })
        .catch(err => {
            hideGlobalLoading();
            if (btn) btn.disabled = false;
            if (spinner) spinner.style.display = 'none';
            showGuardModal('','Network Error', err.message || 'Request failed.');
        });
}

// Toggle the visual "checked" state of a flag item when its checkbox changes.
document.getElementById('moaRejectFlagsGrid')?.addEventListener('change', function(e){
    const cb = e.target.closest('input[type="checkbox"]');
    if (!cb) return;
    cb.closest('.moa-reject-flag-item')?.classList.toggle('checked', cb.checked);
});

function getSelectedMoaRejectFlags() {
    const flagsGrid = document.getElementById('moaRejectFlagsGrid');
    if (!flagsGrid) return [];
    return Array.from(flagsGrid.querySelectorAll('input[type="checkbox"]:checked')).map(cb => cb.value);
}

function fetchMoaContactEmail(moaId, userId) {
    const loadTok = _moaLoadToken;   // NEW (this adjustment): which Review MOA load this lookup belongs to
    const fd = new FormData();
    fd.append('ajax_get_moa_contact_email','1');
    if (moaId) fd.append('moa_id', moaId);
    if (userId) fd.append('user_id', userId);
    fetch(SELF, {method:'POST', body:fd})
        .then(r => r.json())
        .then(data => {
            document.getElementById('moaRejectInfoEmail').textContent = data.email || '—';
            // NEW (this adjustment): the same lookup now also returns the contact's telephone
            const telEl = document.getElementById('moaRejectInfoTelephone');
            if (telEl) telEl.textContent = data.telephone || '—';
            // NEW (this adjustment): Company Profile / Brief Description, shown below Email
            const cpEl = document.getElementById('moaRejectInfoProfile');
            if (cpEl) cpEl.textContent = data.company_profile || 'No company profile / brief description has been provided.';
        })
        .catch(() => {
            document.getElementById('moaRejectInfoEmail').textContent = '—';
            const telEl = document.getElementById('moaRejectInfoTelephone');
            if (telEl) telEl.textContent = '—';
            const cpEl = document.getElementById('moaRejectInfoProfile');   // NEW (this adjustment)
            if (cpEl) cpEl.textContent = '—';
        })
        .then(() => { cvMoaLoadDone(loadTok, 'info'); });   // NEW (this adjustment): loaded (or failed) — either way it's done
}

function closeMoaRejectModal() {
    cvMoaLoadCancel();   // NEW (this adjustment): the modal is closing — drop the load page and ignore any late answers
    cvResetMoaCompliance();   // NEW (this adjustment)
    document.getElementById('moaRejectModal').classList.remove('open');
    document.getElementById('moaRejectPdfFrame').src = '';
    document.getElementById('moaRejectImg').src = '';
    _moaRejectCurrentId = null;
    _moaRejectCurrentUserId = null;
    _moaRejectMode = 'inbox';
}
document.getElementById('moaRejectModal')?.addEventListener('click', function(e){ if(e.target===this) closeMoaRejectModal(); });

function resetMoaRejectSendBtn() {
    const btn = document.getElementById('moaRejectSendBtn');
    const spinner = document.getElementById('moaRejectSpinner');
    if (btn) btn.disabled = false;
    if (spinner) spinner.style.display = 'none';
}

function sendMoaRejectionEmail() {
    // ── UPDATED (this adjustment): branches on _moaRejectMode. 'table' is
    // the new "Flag for Revision" action triggered from a company's row in
    // the New MOA table (merged "Pending for Review" stage); 'inbox' is
    // the original, legacy moa_id-based flow (kept working unchanged).
    if (_moaRejectMode === 'table') { sendMoaTableFlagRevision(); return; }

    if (!_moaRejectCurrentId) return;
    const comment = document.getElementById('moaRejectComment').value.trim();
    if (!comment) { showGuardModal('','Message Required','Please write a message to include in the rejection email.'); return; }

    const flags = getSelectedMoaRejectFlags();
    if (!flags.length) { showGuardModal('','Flag a Section','Please flag at least one section that needs correction before rejecting.'); return; }

    const moaId = _moaRejectCurrentId;
    const btn = document.getElementById('moaRejectSendBtn');
    const spinner = document.getElementById('moaRejectSpinner');
    btn.disabled = true; spinner.style.display = 'inline-block';

    const fd = new FormData();
    fd.append('ajax_moa_reject_send','1');
    fd.append('moa_id', moaId);
    fd.append('comment', comment);
    flags.forEach(f => fd.append('flags[]', f));

    fetch(SELF, {method:'POST', body:fd})
        .then(r=>{const ct=r.headers.get('content-type')||''; if(!ct.includes('application/json')) return r.text().then(t=>{throw new Error('Server returned non-JSON:\n'+t.substring(0,300));}); return r.json();})
        .then(data => {
            if (!data.success) {
                btn.disabled = false; spinner.style.display = 'none';
                showGuardModal('','Could Not Send', data.message || 'Failed to send the rejection email.');
                return;
            }
            closeMoaRejectModal();
            // The row is now marked Rejected in place — refresh the whole
            // list from the server so its status/badge/flags reflect that.
            loadMoaRequests();
            const flaggedLabels = flags.map(f => MOA_REJECT_FLAG_LABELS[f] || f).join(', ');
            showGuardModal('','Rejection Sent',
                `The rejection email — including the MOA document, your message, and the flagged section(s) (<strong>${escapeHtml(flaggedLabels)}</strong>) — has been sent successfully. The request has been marked as <strong>Rejected</strong>; the company will now see a "Needs Revision" status on their end and can edit just those section(s) and resubmit.`
            );
        })
        .catch(err => {
            btn.disabled = false; spinner.style.display = 'none';
            showGuardModal('','Network Error', err.message || 'Request failed.');
        });
}

// ── NEW (this adjustment): the "table" counterpart to the function
// above — flags a company's MOA row (while it's in the merged "Pending
// for Review" stage) for revision, without ever leaving the New MOA
// table / involving a separate accept/reject inbox step.
function sendMoaTableFlagRevision() {
    if (!_moaRejectCurrentUserId) return;
    const comment = document.getElementById('moaRejectComment').value.trim();
    if (!comment) { showGuardModal('','Message Required','Please write a message to include in the email.'); return; }

    const flags = getSelectedMoaRejectFlags();
    if (!flags.length) { showGuardModal('','Flag a Section','Please flag at least one section that needs correction before sending.'); return; }

    const userId = _moaRejectCurrentUserId;
    const btn = document.getElementById('moaRejectSendBtn');
    const spinner = document.getElementById('moaRejectSpinner');
    btn.disabled = true; spinner.style.display = 'inline-block';
    showGlobalLoading('Sending Email');

    const fd = new FormData();
    fd.append('ajax_moa_table_flag_revision','1');
    fd.append('user_id', userId);
    fd.append('comment', comment);
    fd.append('moa_undoable','1');   // NEW (this adjustment): the server keeps what's needed to undo this and holds the email back
    flags.forEach(f => fd.append('flags[]', f));

    fetch(SELF, {method:'POST', body:fd})
        .then(r=>{const ct=r.headers.get('content-type')||''; if(!ct.includes('application/json')) return r.text().then(t=>{throw new Error('Server returned non-JSON:\n'+t.substring(0,300));}); return r.json();})
        .then(data => {
            hideGlobalLoading();
            if (!data.success) {
                btn.disabled = false; spinner.style.display = 'none';
                showGuardModal('','Could Not Send', data.message || 'Failed to send the revision email.');
                return;
            }
            closeMoaRejectModal();
            // FIX (this adjustment): the server now carries forward any section(s) still awaiting the company from an earlier
            // flag and returns the full merged list in data.flagged_fields — show that, falling back to this selection.
            const recordedFlags = (Array.isArray(data.flagged_fields) && data.flagged_fields.length) ? data.flagged_fields : flags;
            const flaggedLabels = recordedFlags.map(f => MOA_REJECT_FLAG_LABELS[f] || f).join(', ');
            // ── UPDATED (this adjustment): the server now returns a
            // context-aware message (registration-only companies with no
            // moa_requests row to auto-detect a resubmission from get a
            // different note) — use it instead of a fixed string.
            // UPDATED (this adjustment): the email is held back until the undo window closes, so it isn't "sent" yet.
            const baseMsg = data.undo_token
                ? `The revision request — including the MOA document, your message, and the flagged section(s) (<strong>${escapeHtml(flaggedLabels)}</strong>) — has been recorded. The email goes to the company once the undo window closes (up to 5 minutes, or as soon as you dismiss the notice at the top) — use <strong>Undo</strong> there if you change your mind.`
                : `The email — including the MOA document, your message, and the flagged section(s) (<strong>${escapeHtml(flaggedLabels)}</strong>) — has been sent successfully.`;
            showGuardModal('','Revision Requested',
                data.message ? (baseMsg + ' ' + data.message) : (baseMsg + ' This row now shows a <strong>Needs Revision</strong> note; it will update automatically once the company resubmits.')
            );
            // ── FIX (this adjustment): this used to force a full page
            // reload just to show the "Needs Revision" badge/note and the
            // updated row-summary label. liveInsertOrUpdateCompanyRow()
            // (built for the live-notification feature) re-fetches this
            // exact row fully rendered server-side and swaps it in place —
            // reusing it here refreshes the needs-revision note, the
            // row-summary progress label, and everything else about the
            // row in one shot, with no reload needed.
            liveInsertOrUpdateCompanyRow(userId);
            // NEW (this adjustment): the undo toast, like a requirement's (the red variant, like a rejected requirement)
            if (data.undo_token) startMoaUndoToast(data.undo_token, data.undo_label, userId, data.undo_action);
        })
        .catch(err => {
            hideGlobalLoading();
            btn.disabled = false; spinner.style.display = 'none';
            showGuardModal('','Network Error', err.message || 'Request failed.');
        });
}

function escapeHtml(str){if(!str)return'';return String(str).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');}

// ── ESC key
document.addEventListener('keydown',function(e){
    if(e.key==='Escape'){
        if(document.getElementById('reqBlobModal').style.display==='flex')closeReqBlobModal();
        if(document.getElementById('moaBlobModal').classList.contains('open'))closeMoaBlobModal();
        if(document.getElementById('moaPreviewModal').classList.contains('open'))closeMoaPreview();
        if(document.getElementById('moaCustomScheduleOverlay').classList.contains('open'))closeMoaCustomScheduleModal();
        if(document.getElementById('moaRejectModal').classList.contains('open'))closeMoaRejectModal();
    }
});

// ── Polls
(function(){function pollAppBadge(){fetch('administrator.php?app_request_count=1').then(r=>r.json()).then(data=>{const badge=document.getElementById('sidebarAppBadge');if(!badge)return;const count=data.count||0;badge.textContent=count;badge.style.display=count>0?'inline-flex':'none';}).catch(()=>{});}setTimeout(()=>{pollAppBadge();setInterval(pollAppBadge,30000);},6000);})();
// ══ UPDATED (this adjustment): the old lightweight "?moa_pending_count=1"
// poller (and, later, the status-diffing pollMoaRequestsData()) has been
// replaced with pollMoaNotifications() — no more per-row status to
// diff, just "is this notification id new since the last check". A
// baseline snapshot is established first (initMoaNotificationSnapshot) so
// the very first poll tick never mistakes pre-existing un-viewed
// notifications for a brand-new arrival and fires a spurious toast.
(function(){
    function initMoaNotificationSnapshot(){
        const fd = new FormData();
        fd.append('ajax_fetch_moa_notifications','1');
        return fetch(SELF, {method:'POST', body:fd})
            .then(r=>r.json())
            .then(data=>{
                if(!data.success) return;
                _allMoaNotifications = data.rows || [];
                _lastKnownMoaNotificationIds = new Set(_allMoaNotifications.map(r=>r.id));
                updateMoaBadge(data.pending_count||0);
            })
            .catch(()=>{});
    }
    setTimeout(()=>{
        initMoaNotificationSnapshot().then(()=>{
            // NEW (this adjustment): the live-upload probe only asks for an immediate notification check
            // once this baseline exists (see cvPollRequirementUploads()), so pre-existing notifications
            // can never be mistaken for brand-new arrivals.
            window._moaNotifBaselineReady = true;
            // UPDATED (this adjustment): checked every 5s (was 15s) so the popup / indicator follow right away
            setInterval(pollMoaNotifications, 5000);
            // NEW (this adjustment): also re-check the moment the window regains focus or the page is restored with the Back button
            window.addEventListener('focus', function(){ pollMoaNotifications(); });
            window.addEventListener('pageshow', function(e){ if (e.persisted) pollMoaNotifications(); });
        });
    }, 0);   // UPDATED (this adjustment): baseline taken right away (was 8s), so new notifications pop up without a delay
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