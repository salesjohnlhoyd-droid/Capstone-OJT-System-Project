<?php

// ── CLEAN-UP (project audit): ONE definition of the `archived_students` table. It used to be written out 2 times in
//    this file (2 different version(s)). CREATE TABLE IF NOT EXISTS only acts once, so whichever copy ran
//    first decided the columns; every former copy now calls this complete definition instead. ──
function cv_ensure_archived_students_table($conn) {
    $conn->query("CREATE TABLE IF NOT EXISTS archived_students ( id INT AUTO_INCREMENT PRIMARY KEY, batch_label VARCHAR(200), user_id INT, first_name VARCHAR(100), middle_name VARCHAR(100), last_name VARCHAR(100), course VARCHAR(200), deploy_status VARCHAR(50), validation_status VARCHAR(50), photo_status VARCHAR(50), company VARCHAR(200), supervisor VARCHAR(200), archived_at DATETIME, archived_by VARCHAR(200) )");
}


// ── CLEAN-UP (project audit): ONE definition of the `admin_application_approvals` table. It used to be written out 2 times in
//    this file (1 different version(s)). CREATE TABLE IF NOT EXISTS only acts once, so whichever copy ran
//    first decided the columns; every former copy now calls this complete definition instead. ──
function cv_ensure_admin_application_approvals_table($conn) {
    $conn->query("CREATE TABLE IF NOT EXISTS admin_application_approvals ( id INT AUTO_INCREMENT PRIMARY KEY, student_id INT NOT NULL, company_id INT NOT NULL, phase VARCHAR(20) NOT NULL DEFAULT 'pending', skill1 TEXT, skill2 TEXT, skill3 TEXT, exp1 TEXT, exp2 TEXT, submitted_at DATETIME DEFAULT CURRENT_TIMESTAMP, UNIQUE KEY unique_application (student_id, company_id) )");
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
// ============================================================================
// ADJUSTMENT: VERIFY-TOAST GATE (writer side) — a held application waits for the undo toast
// ----------------------------------------------------------------------------
// A "Verified" save is written at once while the Undo toast stays up (up to 5 minutes). A student whose
// application is ON HOLD (placement replaced) must not be applied while that toast is still active, so every
// Verified save is recorded in verify_toast_gate:
//   • cv_vt_reserve() — BEFORE the Verified status is written (a temporary token), so the student's page can
//                       never see "all Verified" in the gap between the write and the undo token existing;
//   • cv_vt_mark()    — right after the undo snapshot: the entry now carries the toast's undo token;
//   • cv_vt_clear()   — the toast ended (ajax_confirm_send: countdown finished / dismissed / replaced by the
//                       next toast) or the action was undone (ajax_undo).
// company_list.php reads this table before it releases a held application. Times are PHP epoch seconds, and an
// entry also expires by itself after the 5-minute undo window, so a closed tab never leaves a student on hold
// forever. Every function swallows (and logs) its errors: the save itself is never affected.
// ============================================================================
if (!defined('CV_VT_WINDOW_SECONDS')) define('CV_VT_WINDOW_SECONDS', 305); // undo window (300 s) + a short grace
function cv_vt_ensure($conn) {
    static $done = false;
    if ($done) return true;
    try {
        $conn->query("CREATE TABLE IF NOT EXISTS verify_toast_gate ( undo_token VARCHAR(64) NOT NULL PRIMARY KEY, student_id INT NOT NULL, created_ts BIGINT NOT NULL, KEY idx_vtg_student (student_id) )");
        $done = true;
    } catch (\Throwable $e) { error_log('verify_toast_gate ensure: ' . $e->getMessage()); return false; }
    // NEW (this adjustment): which requirement the toast is for ('application_sit', '__photo', …). add_ojt_student.php reads it so
    // the company's Accept / Reject buttons wait for the Application SIT toast specifically. Optional: without the
    // column everything behaves exactly as before.
    try { $conn->query("ALTER TABLE verify_toast_gate ADD COLUMN IF NOT EXISTS requirement_type VARCHAR(50) NULL"); } catch (\Throwable $e) { error_log('verify_toast_gate type column: ' . $e->getMessage()); }
    return $done;
}
function cv_vt_has_type($conn) {
    static $has = null;
    if ($has !== null) return $has;
    try { $r = $conn->query("SHOW COLUMNS FROM verify_toast_gate LIKE 'requirement_type'"); $has = ($r && $r->num_rows > 0); } catch (\Throwable $e) { $has = false; }
    return $has;
}
function cv_vt_table_exists($conn, $table) {
    $r = $conn->query("SHOW TABLES LIKE '" . $conn->real_escape_string($table) . "'");
    return ($r && $r->num_rows > 0);
}
function cv_vt_shared_cols($conn, $from, $to) {   // the columns both tables have (so a later schema change cannot break the move)
    $cols = [];
    foreach ([$from, $to] as $i => $t) {
        $c = [];
        $r = $conn->query("SHOW COLUMNS FROM `" . $t . "`");
        if ($r) while ($x = $r->fetch_assoc()) $c[] = $x['Field'];
        $cols[$i] = $c;
    }
    $shared = array_values(array_intersect($cols[0], $cols[1]));
    return (in_array('student_id', $shared, true) && in_array('company_id', $shared, true)) ? '`' . implode('`,`', $shared) . '`' : '';
}
// moves a student's row(s) between the hold table and its stash; the source row is only deleted once the copy is confirmed
function cv_vt_move_rows($conn, $from, $to, $student_id) {
    $sid = (int)$student_id;
    if ($sid <= 0 || !cv_vt_table_exists($conn, $from) || !cv_vt_table_exists($conn, $to)) return false;
    $r = $conn->query("SELECT 1 FROM `" . $from . "` WHERE student_id = " . $sid . " LIMIT 1");
    if (!$r || $r->num_rows === 0) return true;   // nothing to move
    $cols = cv_vt_shared_cols($conn, $from, $to);
    if ($cols === '') return false;
    $conn->query("INSERT IGNORE INTO `" . $to . "` (" . $cols . ") SELECT " . $cols . " FROM `" . $from . "` WHERE student_id = " . $sid);
    $chk = $conn->query("SELECT 1 FROM `" . $to . "` WHERE student_id = " . $sid . " LIMIT 1");
    if (!$chk || $chk->num_rows === 0) return false;
    $conn->query("DELETE FROM `" . $from . "` WHERE student_id = " . $sid);
    return true;
}
function cv_vt_live_count($conn, $student_id, $exclude = '') {   // live toasts of this student (fails open: 0)
    try {
        if (!cv_vt_ensure($conn)) return 0;
        $since = time() - (int)CV_VT_WINDOW_SECONDS; $sid = (int)$student_id; $ex = (string)$exclude;
        $st = $conn->prepare("SELECT COUNT(*) FROM verify_toast_gate WHERE student_id = ? AND created_ts >= ? AND undo_token <> ?");
        $st->bind_param('iis', $sid, $since, $ex);
        $st->execute(); $n = (int)($st->get_result()->fetch_row()[0] ?? 0); $st->close();
        return $n;
    } catch (\Throwable $e) { return 0; }
}
// puts the student's held application back once NO toast of theirs is live any more (ended / undone / expired)
function cv_vt_restore_if_idle($conn, $student_id, $exclude = '') {
    try {
        if (!cv_vt_table_exists($conn, 'verify_toast_hold_stash')) return;
        if (cv_vt_live_count($conn, $student_id, $exclude) > 0) return;
        cv_vt_move_rows($conn, 'verify_toast_hold_stash', 'placement_hold_applications', $student_id);
    } catch (\Throwable $e) { error_log('verify_toast_gate restore: ' . $e->getMessage()); }
}
function cv_vt_stash_hold($conn, $student_id) {   // takes the student's held application out of sight while the toast is active
    try {
        if (!cv_vt_table_exists($conn, 'placement_hold_applications')) return;
        $r = $conn->query("SELECT 1 FROM placement_hold_applications WHERE student_id = " . (int)$student_id . " LIMIT 1");
        if (!$r || $r->num_rows === 0) return;   // not on hold: nothing to do
        $conn->query("CREATE TABLE IF NOT EXISTS verify_toast_hold_stash LIKE placement_hold_applications");
        cv_vt_move_rows($conn, 'placement_hold_applications', 'verify_toast_hold_stash', $student_id);
    } catch (\Throwable $e) { error_log('verify_toast_gate stash: ' . $e->getMessage()); }
}
function cv_vt_restore_expired($conn) {   // toasts that were never ended (tab closed): after the undo window the hold is back
    try {
        if (!cv_vt_table_exists($conn, 'verify_toast_hold_stash')) return;
        $r = $conn->query("SELECT DISTINCT student_id FROM verify_toast_hold_stash");
        $ids = []; if ($r) while ($x = $r->fetch_assoc()) $ids[] = (int)$x['student_id'];
        foreach ($ids as $sid) cv_vt_restore_if_idle($conn, $sid);
    } catch (\Throwable $e) { error_log('verify_toast_gate restore_expired: ' . $e->getMessage()); }
}
function cv_vt_mark($conn, $student_id, $token, $type = '') {
    try {
        $student_id = (int)$student_id; $token = (string)$token;
        if ($student_id <= 0 || $token === '' || !cv_vt_ensure($conn)) return false;
        $old = time() - (int)CV_VT_WINDOW_SECONDS;
        $conn->query("DELETE FROM verify_toast_gate WHERE created_ts < " . (int)$old);
        $now = time();
        if ($type !== '' && cv_vt_has_type($conn)) {
            $st = $conn->prepare("REPLACE INTO verify_toast_gate (undo_token, student_id, created_ts, requirement_type) VALUES (?, ?, ?, ?)");
            $st->bind_param('siis', $token, $student_id, $now, $type);
        } else {
            $st = $conn->prepare("REPLACE INTO verify_toast_gate (undo_token, student_id, created_ts) VALUES (?, ?, ?)");
            $st->bind_param('sii', $token, $student_id, $now);
        }
        $st->execute(); $st->close();
        return true;
    } catch (\Throwable $e) { error_log('verify_toast_gate mark: ' . $e->getMessage()); return false; }
}
function cv_vt_reserve($conn, $student_id, $type = '') {   // returns the temporary token (or '' when it could not be recorded)
    try {
        $tmp = 'tmp_' . bin2hex(random_bytes(8));
        if (!cv_vt_mark($conn, $student_id, $tmp, $type)) return '';
        cv_vt_stash_hold($conn, $student_id);   // a held application cannot be released by anything while the toast is active
        return $tmp;
    } catch (\Throwable $e) { return ''; }
}
function cv_vt_clear($conn, $token) {
    try {
        $token = (string)$token;
        if ($token === '' || !cv_vt_ensure($conn)) return;
        $sq = $conn->prepare("SELECT student_id FROM verify_toast_gate WHERE undo_token = ?");
        $sq->bind_param('s', $token); $sq->execute();
        $sr = $sq->get_result()->fetch_assoc(); $sq->close();
        if ($sr) cv_vt_restore_if_idle($conn, (int)$sr['student_id'], $token);   // toast over / undone: the held application is back (and, if still Verified, is released by the student's page)
        $st = $conn->prepare("DELETE FROM verify_toast_gate WHERE undo_token = ?");
        $st->bind_param('s', $token);
        $st->execute(); $st->close();
    } catch (\Throwable $e) { error_log('verify_toast_gate clear: ' . $e->getMessage()); }
}
include "mail.php";

// ============================================
// FIX: admin session guard was checking $_SESSION['user_id'], but
// admin_login.php never sets that key — it deliberately uses its own
// session key, $_SESSION['admin_id'], so an admin session never
// collides with (or gets overwritten by) a student/company session
// sharing the same browser. Because of that mismatch, every
// successful admin login was redirected here by admin_login.php,
// immediately failed this guard (no 'user_id' set), and got bounced
// right back out to login.php — the STUDENT portal's login page —
// instead of staying on this admin dashboard.
//
// This now accepts either session shape: the admin-specific
// 'admin_id' (set by admin_login.php) OR the legacy 'user_id' (kept
// for backward compatibility, in case anything else in the system
// still sets it for an admin role). The redirect target on failure
// is also corrected to admin_login.php — the actual login page for
// this portal — instead of the student login.php.
// ============================================
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
        // NEW (this adjustment): student requirement submissions of deleted students
        if ($has('student_requirement_upload_notifications'))
            $run("DELETE n FROM student_requirement_upload_notifications n LEFT JOIN users u ON u.id = n.user_id WHERE u.id IS NULL");
        if ($has('student_requirement_upload_watch'))
            $run("DELETE w FROM student_requirement_upload_watch w LEFT JOIN users u ON u.id = w.user_id WHERE u.id IS NULL");
    }
}
try {
    $cvSweepNow = time();
    if (!isset($_SESSION['cv_orphan_sweep_at']) || $cvSweepNow - (int)$_SESSION['cv_orphan_sweep_at'] >= 15) {
        $_SESSION['cv_orphan_sweep_at'] = $cvSweepNow;
        cv_sweep_orphan_notifications($conn);
    }
} catch (\Throwable $e) { /* never affects the page */ }

// ============================================================================
// NEW (this adjustment): STUDENT REQUIREMENT SUBMISSIONS — shared helpers.
// A student's new / re-uploaded requirement (or profile photo) is recorded as a
// notification (administrator.php detects it — see cv_sru_detect() there) and it
// counts toward the Student Validation side-menu indicator on every page, next to
// the application requests, exactly like company_validation.php's "Requirement
// Uploaded" notifications count toward the Company Requirements indicator.
// These tables are created here (once per session) so every page can count them.
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
try { cv_sru_ensure($conn); } catch (\Throwable $e) {}

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
    [function () { return cv_alog_is_post('ajax_update_requirement') || cv_alog_is_post('update_requirement'); }, function ($conn) {
        $n = cv_alog_user_name($conn, (int)($_POST['user_id'] ?? 0)); $w = cv_alog_status_word($_POST['status'] ?? ''); $r = cv_alog_post('remark');
        return ['Requirement ' . $w, 'Student', $n, cv_alog_post('requirement_type') . " marked as " . strtolower($w) . " for $n" . ($r !== '' ? " — remark: $r" : '') . " via Student Requirements"];
    }],
    [function () { return cv_alog_is_post('ajax_update_photo') || cv_alog_is_post('update_photo'); }, function ($conn) {
        $n = cv_alog_user_name($conn, (int)($_POST['user_id'] ?? 0)); $w = cv_alog_status_word($_POST['photo_status'] ?? ''); $r = cv_alog_post('photo_remark');
        return ['Photo ' . $w, 'Student', $n, "Photo marked as " . strtolower($w) . " for $n" . ($r !== '' ? " — remark: $r" : '') . " via Student Requirements"];
    }],
    [function () { return cv_alog_is_post('ajax_undo'); }, function ($conn) {
        return ['Action Undone', 'Student Requirements', '—', "Undid the last action via Student Requirements"];
    }],
    [function () { return cv_alog_is_post('ajax_archive_batch'); }, function ($conn) {
        $b = cv_alog_post('batch_label');
        return ['Batch Archived', 'Student Batch', $b, "Archived student batch $b via Student Requirements"];
    }],
    [function () { return cv_alog_is_post('ajax_unarchive_batch'); }, function ($conn) {
        $b = cv_alog_post('batch_label');
        return ['Batch Restored', 'Student Batch', $b, "Restored student batch $b from the archive via Student Requirements"];
    }],
    [function () { return cv_alog_is_post('ajax_approve_app_request') || cv_alog_is_post('ajax_deny_app_request'); }, function ($conn) {
        $aid = (int)($_POST['approval_id'] ?? 0); $sid = (int)cv_alog_scalar($conn, "SELECT student_id FROM admin_application_approvals WHERE id = ?", 'i', [$aid]);
        $cid = (int)cv_alog_scalar($conn, "SELECT company_id FROM admin_application_approvals WHERE id = ?", 'i', [$aid]);
        $n = cv_alog_user_name($conn, $sid); $co = cv_alog_company_name($conn, $cid); $ok = isset($_POST['ajax_approve_app_request']);
        return [$ok ? 'Application Approved' : 'Application Denied', 'Student', $n, ($ok ? 'Approved' : 'Denied') . " $n's application" . ($co !== '' ? " to $co" : '') . " via Student Requirements"];
    }],
    [function () { return cv_alog_is_post('ajax_endorsement_signatory_delete'); }, function ($conn) {
        return ['Signatory Deleted', 'Endorsement Letter', '—', "Deleted a saved endorsement-letter signatory via Student Requirements"];
    }],
]);


// Pending application requests count for navbar badge
cv_ensure_admin_application_approvals_table($conn);   // CLEAN-UP (audit): shared definition, see cv_ensure_admin_application_approvals_table()
/* ── FIX: widen skill1/skill2/skill3 on any table that already existed
   before this change. CREATE TABLE IF NOT EXISTS above only applies to
   brand-new tables — it does nothing to a table that was already created
   with the old VARCHAR(200) columns. A single skill entry from the
   student's Digital Resume (e.g. the "Time Management" bullet) can
   already be longer than 200 characters on its own, so MySQL was
   silently truncating skill1/skill2, and truncating skill3 so badly
   that the delimiter used to pack extra skill entries together got cut
   off too — corrupting the split done in ajax_fetch_app_requests below.
   exp1/exp2 were already TEXT, which is why experience entries were
   unaffected. MODIFY COLUMN is safe to run every load: once the columns
   are already TEXT, it's a harmless no-op. */
$conn->query("ALTER TABLE admin_application_approvals MODIFY COLUMN skill1 TEXT");
$conn->query("ALTER TABLE admin_application_approvals MODIFY COLUMN skill2 TEXT");
$conn->query("ALTER TABLE admin_application_approvals MODIFY COLUMN skill3 TEXT");
cv_ph_detect($conn);   // NEW (this adjustment): the indicator below already includes students who replaced their preferred placement
cv_sc_reconcile($conn); cv_sc_detect($conn);   // NEW (this adjustment): …and students whose schedule was changed by their supervisor
$app_request_count_res = $conn->query("SELECT (SELECT COUNT(*) FROM admin_application_approvals aaa_c INNER JOIN users aaa_u ON aaa_u.id = aaa_c.student_id) + (SELECT COUNT(*) FROM student_requirement_upload_notifications srun INNER JOIN users srun_u ON srun_u.id = srun.user_id WHERE srun.admin_viewed = 0 AND COALESCE(srun_u.is_archived, 0) = 0) as total");
$app_request_count = (int)(($app_request_count_res ? $app_request_count_res->fetch_assoc()['total'] : 0));
// Ungraded faculty_grade count for sidebar badge
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

/* ================= NEW (this adjustment): COMPANY REQUIREMENTS SIDE-MENU INDICATOR =================
   The "Company Requirements" link in the side menu had no notification
   indicator on this page, so the admin could not see from here that
   company_validation.php had new items waiting in its Notification Inbox.
   adminCompanyNotificationCount() counts EXACTLY what company_validation.php's
   own sidebar badge (#sidebarMoaBadge) counts on page load:
     • un-viewed MOA notifications that were ingested (admin_viewed=0 AND
       transferred=1 — the same rule as its inbox list, so no "ghost" count), plus
     • un-viewed requirement-upload notifications
       (company_requirement_upload_notifications.admin_viewed=0).
   It is read-only (never creates/alters tables here — company_validation.php
   owns that schema) and every query is guarded, so a fresh install where
   those tables/columns don't exist yet simply returns 0 instead of erroring. */
function adminCompanyNotificationCount($conn) {
    $total = 0;
    try {
        $r = $conn->query("SELECT COUNT(*) AS total FROM moa_requests WHERE admin_viewed=0 AND transferred=1");
        if ($r) { $row = $r->fetch_assoc(); $total += (int)($row['total'] ?? 0); }
    } catch (\Throwable $e) { /* table/columns not created yet */ }
    try {
        $r = $conn->query("SELECT COUNT(*) AS total FROM company_requirement_upload_notifications WHERE admin_viewed=0");
        if ($r) { $row = $r->fetch_assoc(); $total += (int)($row['total'] ?? 0); }
    } catch (\Throwable $e) { /* table not created yet */ }
    return $total;
}
$moa_pending_count = adminCompanyNotificationCount($conn);

/* ================= NEW (this adjustment): BASELINE FOR THE APPLICATION REQUEST POPUP =================
   The ids of the application requests already pending when the page loads.
   The popup notification only fires for requests that arrive AFTER this
   (i.e. genuinely newly detected ones), so reloading the page never
   re-announces requests the admin has already seen in the badge/inbox. */
$app_request_known_ids = [];
$app_ids_res = $conn->query("SELECT id FROM admin_application_approvals");
if ($app_ids_res) {
    while ($air = $app_ids_res->fetch_assoc()) $app_request_known_ids[] = (int)$air['id'];
}

$pageTitle = "Students Validation";

// ── NEW (this adjustment — design from company_validation.php): the side-menu header now shows the
// logged-in admin's full name with an "Administrator" role label underneath, exactly like
// company_validation.php. Same lookup (the `admins` table), except the id also accepts
// $_SESSION['admin_id'] — the key admin_login.php actually sets (see the session guard at the top).
// Fully guarded: if the lookup fails for any reason the header falls back to the session name,
// then to "Administrator", so the page can never break on it.
$adminFullName = '';
try {
    $adminLookupId = (int)($_SESSION['admin_id'] ?? $_SESSION['user_id'] ?? 0);
    $adminNameStmt = $conn->prepare("SELECT first_name, middle_name, last_name FROM admins WHERE id = ?");
    if ($adminNameStmt) {
        $adminNameStmt->bind_param("i", $adminLookupId);
        $adminNameStmt->execute();
        $adminNameRow = $adminNameStmt->get_result()->fetch_assoc();
        $adminNameStmt->close();
        if ($adminNameRow) {
            $adminFullName = trim(
                ($adminNameRow['first_name'] ?? '') . ' ' .
                ($adminNameRow['middle_name'] ?? '') . ' ' .
                ($adminNameRow['last_name'] ?? '')
            );
            $adminFullName = preg_replace('/\s+/', ' ', $adminFullName);
        }
    }
} catch (\Throwable $e) { $adminFullName = ''; }
if ($adminFullName === '') {
    $adminFullName = trim(($_SESSION['first_name'] ?? '') . ' ' . ($_SESSION['last_name'] ?? ''));
}
if ($adminFullName === '') $adminFullName = 'Administrator';

$reqLabels = [
    "cert_registration"=>"Certification of Registration",
    "certificate_pdos"=>"Certificate of participation(PDOS)",
    "ojt_sheet"=>"On-the-job Training Program and information sheet",
    "application_sit"=>"Application for supervised Industrial Training",
    "waiver_form"=>"Waiver and permission form",
    "student_contract"=>"Student/University contract",
    "psych_result"=>"Psych Test Result",
    "medical_result"=>"Medical result"
];

// ============================================================================
// NEW (this adjustment): DETECT NEW STUDENT REQUIREMENT SUBMISSIONS
// ----------------------------------------------------------------------------
// Same idea as company_validation.php's detectAndNotifyRequirementUploads(), for
// students. A student's requirement lives in ONE `requirements` row (and the ID
// photo in student_information), so a (re-)upload replaces the file rather than
// adding a row — a new submission is therefore spotted by the stored file's size
// changing to a non-empty file. The last size seen per student + requirement is
// kept in student_requirement_upload_watch, and each change is claimed with a
// conditional UPDATE / INSERT IGNORE, so it is announced exactly ONCE even with
// several admins / tabs open. The very first run only remembers what is already
// there (nothing old is announced). Files from the same student within 20 seconds
// go into one notification. An item the admin has already reviewed (Verified /
// Denied) or that no longer has a file drops out of its notification by itself,
// and a notification with nothing left is removed. Runs at most every 2 seconds
// per admin; any error is swallowed so it can never affect the page.
// ============================================================================
function cv_sru_label($key, $labels) {
    if ($key === '__photo') return 'Profile Photo (ID)';
    return (string)($labels[$key] ?? $key);
}
function cv_sru_detect($conn, $labels) {
    try {
        if (!cv_sru_ensure($conn)) return;
        // UPDATED (this adjustment): one check every 2 seconds for ALL admins / tabs together (claimed in the
        // database), instead of per admin session — and it no longer touches the session at all.
        $conn->query("CREATE TABLE IF NOT EXISTS student_requirement_upload_meta (id TINYINT NOT NULL PRIMARY KEY, initialized TINYINT(1) NOT NULL DEFAULT 0)");
        $conn->query("ALTER TABLE student_requirement_upload_meta ADD COLUMN IF NOT EXISTS last_run DATETIME NULL");
        $conn->query("INSERT IGNORE INTO student_requirement_upload_meta (id, initialized) VALUES (1, 0)");
        $conn->query("UPDATE student_requirement_upload_meta SET last_run = NOW() WHERE id = 1 AND (last_run IS NULL OR last_run <= NOW() - INTERVAL 2 SECOND)");
        if ($conn->affected_rows !== 1) return;

        // 1) what is on file right now (active students only)
        $cur = [];
        $r = $conn->query("SELECT r.user_id, r.requirement_type, COALESCE(LENGTH(r.file_name), 0) AS len
                           FROM requirements r INNER JOIN users u ON u.id = r.user_id
                           WHERE u.role = 'student' AND COALESCE(u.is_archived, 0) = 0");
        if ($r) while ($x = $r->fetch_assoc()) $cur[(int)$x['user_id'] . '|' . $x['requirement_type']] = (int)$x['len'];
        $r = $conn->query("SELECT si.user_id, COALESCE(LENGTH(si.student_photo), 0) AS len
                           FROM student_information si INNER JOIN users u ON u.id = si.user_id
                           WHERE u.role = 'student' AND COALESCE(u.is_archived, 0) = 0");
        if ($r) while ($x = $r->fetch_assoc()) $cur[(int)$x['user_id'] . '|__photo'] = (int)$x['len'];

        // 2) what was seen before
        $seen = [];
        $r = $conn->query("SELECT user_id, requirement_type, file_len FROM student_requirement_upload_watch");
        // NEW (this adjustment): '__placement' rows in this table belong to cv_ph_detect() (placement-replaced
        // notifications), not to a file size — they are never compared against what is on file.
        if ($r) while ($x = $r->fetch_assoc()) { if ($x['requirement_type'] === '__placement' || $x['requirement_type'] === '__schedule') continue; $seen[(int)$x['user_id'] . '|' . $x['requirement_type']] = (int)$x['file_len']; }

        // 3) the first run ever only remembers
        $conn->query("INSERT IGNORE INTO student_requirement_upload_meta (id, initialized) VALUES (1, 0)");
        $conn->query("UPDATE student_requirement_upload_meta SET initialized = 1 WHERE id = 1 AND initialized = 0");
        $firstRun = ($conn->affected_rows === 1);

        // 4) claim each change once
        $ins = $conn->prepare("INSERT IGNORE INTO student_requirement_upload_watch (user_id, requirement_type, file_len) VALUES (?, ?, ?)");
        $upd = $conn->prepare("UPDATE student_requirement_upload_watch SET file_len = ? WHERE user_id = ? AND requirement_type = ? AND file_len = ?");
        $new = [];
        foreach ($cur as $k => $len) {
            $parts = explode('|', $k, 2); $uid = (int)$parts[0]; $type = $parts[1];
            if (!array_key_exists($k, $seen)) {
                $ins->bind_param('isi', $uid, $type, $len); $ins->execute(); $won = ($ins->affected_rows === 1);
            } elseif ($seen[$k] !== $len) {
                $old = $seen[$k];
                $upd->bind_param('iisi', $len, $uid, $type, $old); $upd->execute(); $won = ($upd->affected_rows === 1);
            } else continue;
            if ($won && !$firstRun && $len > 0) $new[$uid][$type] = true;
        }
        $ins->close(); $upd->close();

        // NEW (this adjustment): a requirement row that was DELETED (placement replaced → Application SIT removed)
        // goes back to "nothing on file", so the student's next upload is announced even if the file has the
        // same size as the deleted one. Only active students are touched.
        $gone = [];
        foreach ($seen as $k => $len) {
            if ($len > 0 && !array_key_exists($k, $cur)) $gone[$k] = true;
        }
        if ($gone) {
            $active = [];
            $ar = $conn->query("SELECT id FROM users WHERE role = 'student' AND COALESCE(is_archived, 0) = 0");
            if ($ar) while ($x = $ar->fetch_assoc()) $active[(int)$x['id']] = true;
            $rst = $conn->prepare("UPDATE student_requirement_upload_watch SET file_len = 0 WHERE user_id = ? AND requirement_type = ?");
            foreach ($gone as $k => $_) {
                $parts = explode('|', $k, 2); $uid = (int)$parts[0]; $type = $parts[1];
                if (!isset($active[$uid])) continue;
                $rst->bind_param('is', $uid, $type); $rst->execute();
            }
            $rst->close();
        }

        // 5) announce (merge into a still-unviewed notification from the last 20 seconds)
        foreach ($new as $uid => $types) {
            $detail = [];
            foreach (array_keys($types) as $t) $detail[$t] = ['key' => $t, 'label' => cv_sru_label($t, $labels)];
            $mq = $conn->prepare("SELECT id, detail FROM student_requirement_upload_notifications
                                  WHERE user_id = ? AND admin_viewed = 0 AND updated_at >= (NOW() - INTERVAL 20 SECOND)
                                    AND detail NOT LIKE '%\"__placement\"%' AND detail NOT LIKE '%\"__schedule\"%' ORDER BY id DESC LIMIT 1");   // a placement-replaced notification is never merged into
            $mq->bind_param('i', $uid); $mq->execute(); $existing = $mq->get_result()->fetch_assoc(); $mq->close();
            if ($existing) {
                $prev = json_decode($existing['detail'] ?? '', true);
                if (is_array($prev)) foreach ($prev as $p) if (!empty($p['key']) && !isset($detail[$p['key']])) $detail[$p['key']] = $p;
                $json = json_encode(array_values($detail)); $eid = (int)$existing['id'];
                $u2 = $conn->prepare("UPDATE student_requirement_upload_notifications SET detail = ?, updated_at = NOW() WHERE id = ?");
                $u2->bind_param('si', $json, $eid); $u2->execute(); $u2->close();
            } else {
                $json = json_encode(array_values($detail));
                $i2 = $conn->prepare("INSERT INTO student_requirement_upload_notifications (user_id, detail, admin_viewed) VALUES (?, ?, 0)");
                $i2->bind_param('is', $uid, $json); $i2->execute(); $i2->close();
            }
        }

        // 6) drop items the admin has already reviewed (or whose file is gone)
        $r = $conn->query("SELECT id, user_id, detail FROM student_requirement_upload_notifications WHERE admin_viewed = 0");
        $rows = []; if ($r) while ($x = $r->fetch_assoc()) $rows[] = $x;
        foreach ($rows as $n) {
            $items = json_decode($n['detail'] ?? '', true); if (!is_array($items)) $items = [];
            $keep = []; $uid = (int)$n['user_id'];
            foreach ($items as $it) {
                $key = (string)($it['key'] ?? ''); if ($key === '') continue;
                if ($key === '__placement' || $key === '__schedule') { $keep[] = $it; continue; }   // cleared by cv_ph_detect() once the new Application SIT is reviewed
                if ($key === '__photo') {
                    $q = $conn->prepare("SELECT photo_status AS st, COALESCE(LENGTH(student_photo), 0) AS len FROM student_information WHERE user_id = ?");
                    $q->bind_param('i', $uid);
                } else {
                    $q = $conn->prepare("SELECT status AS st, COALESCE(LENGTH(file_name), 0) AS len FROM requirements WHERE user_id = ? AND requirement_type = ?");
                    $q->bind_param('is', $uid, $key);
                }
                $q->execute(); $st = $q->get_result()->fetch_assoc(); $q->close();
                $reviewed = !$st || (int)$st['len'] === 0 || in_array((string)($st['st'] ?? ''), ['Verified', 'Denied'], true);
                if (!$reviewed) $keep[] = $it;
            }
            $nid = (int)$n['id'];
            if (!$keep) {
                $d = $conn->prepare("DELETE FROM student_requirement_upload_notifications WHERE id = ?"); $d->bind_param('i', $nid); $d->execute(); $d->close();
            } elseif (count($keep) !== count($items)) {
                $json = json_encode(array_values($keep));
                $u3 = $conn->prepare("UPDATE student_requirement_upload_notifications SET detail = ? WHERE id = ?"); $u3->bind_param('si', $json, $nid); $u3->execute(); $u3->close();
            }
        }
    } catch (\Throwable $e) { /* never affects the page */ }
}
// the unviewed submissions, newest first (for the popup and the inbox)
function cv_sru_list($conn) {
    $out = [];
    try {
        if (!cv_sru_ensure($conn)) return $out;
        $r = $conn->query("SELECT n.id, n.user_id, n.detail, n.created_at, n.updated_at, u.first_name, u.middle_name, u.last_name, u.email
                           FROM student_requirement_upload_notifications n INNER JOIN users u ON u.id = n.user_id
                           WHERE n.admin_viewed = 0 AND COALESCE(u.is_archived, 0) = 0
                           ORDER BY n.updated_at DESC, n.id DESC");
        if ($r) while ($x = $r->fetch_assoc()) {
            $items = json_decode($x['detail'] ?? '', true);
            $out[] = [
                'id'        => (int)$x['id'],
                'user_id'   => (int)$x['user_id'],
                'full_name' => trim(preg_replace('/\s+/', ' ', ($x['first_name'] ?? '') . ' ' . ($x['middle_name'] ?? '') . ' ' . ($x['last_name'] ?? ''))),
                'email'     => (string)($x['email'] ?? ''),
                'items'     => is_array($items) ? array_values($items) : [],
                'kind'      => (is_array($items) && in_array('__schedule', array_column($items, 'key'), true)) ? 'schedule' : ((is_array($items) && in_array('__placement', array_column($items, 'key'), true)) ? 'placement' : 'upload'),   // NEW (this adjustment): placement replaced → new Application SIT needs validation
                'sig'       => (string)$x['updated_at'],
                'when'      => $x['updated_at'] ? date('M d, Y h:i A', strtotime($x['updated_at'])) : '',
            ];
        }
    } catch (\Throwable $e) {}
    return $out;
}
function cv_inbox_total($conn) {   // application requests + unviewed submissions (the Student Validation indicator)
    try {
        $r = $conn->query("SELECT COUNT(*) AS total FROM admin_application_approvals aaa_c INNER JOIN users aaa_u ON aaa_u.id = aaa_c.student_id");
        $row = $r ? $r->fetch_assoc() : null;
        return (int)($row['total'] ?? 0) + cv_sru_count($conn);
    } catch (\Throwable $e) { return cv_sru_count($conn); }
}
// ============================================================================
// NEW (this adjustment): PREFERRED PLACEMENT REPLACED → NEW APPLICATION SIT NEEDS VALIDATION
// ----------------------------------------------------------------------------
// When a student replaces the Preference for Placement on company_list.php, the
// Application SIT requirement is deleted and the application is put on hold
// (placement_hold_applications) until the NEW Application SIT is verified. The
// student's overall validation_status was left at 'Verified', so this page (which
// only lists students that are not verified) never showed them again.
// cv_ph_reconcile() puts every such student back to 'Pending' — the same value
// recomputeValidationStatus() stores while a requirement is not verified — so the
// student is listed again here, with Application SIT waiting for the new upload.
// It only touches students that are on hold AND whose Application SIT is not
// verified, so every other student is left exactly as it was. When the new
// Application SIT is verified the normal save recomputes the status as before.
// Returns the ids of the students currently on hold (for the live check). Any
// error (e.g. the hold table does not exist yet) is swallowed.
// ============================================================================
function cv_ph_reconcile($conn) {
    $held = [];
    try {
        $t = $conn->query("SHOW TABLES LIKE 'placement_hold_applications'");
        if (!$t || $t->num_rows === 0) return $held;
        $conn->query("UPDATE users u
                      INNER JOIN placement_hold_applications h ON h.student_id = u.id
                      SET u.validation_status = 'Pending'
                      WHERE u.role = 'student' AND COALESCE(u.is_archived, 0) = 0 AND u.validation_status = 'Verified'
                        AND NOT EXISTS (SELECT 1 FROM requirements r WHERE r.user_id = u.id AND r.requirement_type = 'application_sit'
                                        AND r.status = 'Verified' AND r.file_name IS NOT NULL AND r.file_name <> '')");
        $r = $conn->query("SELECT DISTINCT h.student_id FROM placement_hold_applications h
                           INNER JOIN users u ON u.id = h.student_id
                           WHERE u.role = 'student' AND COALESCE(u.is_archived, 0) = 0");
        if ($r) while ($x = $r->fetch_assoc()) $held[] = (int)$x['student_id'];
    } catch (\Throwable $e) { /* never affects the page */ }
    return $held;
}
// ============================================================================
// NEW (this adjustment): PLACEMENT REPLACED → A NOTIFICATION, LIKE ANY OTHER
// ----------------------------------------------------------------------------
// "<student> replaced the preferred placement — the new Application SIT needs
// validation" used to be a one-off toast shown only on this page while it was
// open, so it left nothing behind: no side-menu indicator and no popup on the
// other admin pages. cv_ph_detect() turns it into a real notification in the
// SAME table / pipeline as the student requirement submissions
// (student_requirement_upload_notifications, one item with key '__placement'),
// so it is counted in the Student Requirements side-menu indicator on every
// admin page, listed in this page's Inbox, and announced by the popup that
// every admin page already polls (?student_upload_list=1) — all without any
// change to how those work.
//   • A student is "pending" while on hold in placement_hold_applications, active,
//     and the new Application SIT has not yet been reviewed (Verified / Denied).
//   • Each hold is announced ONCE: the claim is an INSERT IGNORE into
//     student_requirement_upload_watch (requirement_type '__placement'), so
//     several admins / tabs polling together never create duplicates, and
//     reloading or re-opening a page never re-announces it.
//   • When a student stops being pending (new SIT reviewed, hold lifted, student
//     archived/deleted) the claim is released and an un-viewed notification is
//     removed, so a LATER replacement is announced again.
// Any error (e.g. the hold table does not exist yet) is swallowed.
// ============================================================================
function cv_ph_detect($conn) {
    try {
        if (!cv_sru_ensure($conn)) return;
        $t = $conn->query("SHOW TABLES LIKE 'placement_hold_applications'");
        if (!$t || $t->num_rows === 0) return;

        $pending = [];
        $r = $conn->query("SELECT DISTINCT h.student_id FROM placement_hold_applications h
                           INNER JOIN users u ON u.id = h.student_id
                           WHERE u.role = 'student' AND COALESCE(u.is_archived, 0) = 0
                             AND NOT EXISTS (SELECT 1 FROM requirements r WHERE r.user_id = u.id AND r.requirement_type = 'application_sit'
                                             AND r.file_name IS NOT NULL AND r.file_name <> '' AND r.status IN ('Verified', 'Denied'))");
        if (!$r) return;   // query problem: change nothing rather than wrongly releasing claims
        while ($x = $r->fetch_assoc()) $pending[(int)$x['student_id']] = true;

        $claimed = [];
        $r = $conn->query("SELECT user_id FROM student_requirement_upload_watch WHERE requirement_type = '__placement'");
        if (!$r) return;
        while ($x = $r->fetch_assoc()) $claimed[(int)$x['user_id']] = true;

        // announce each newly pending student once
        $ins = $conn->prepare("INSERT IGNORE INTO student_requirement_upload_watch (user_id, requirement_type, file_len) VALUES (?, '__placement', 1)");
        $add = $conn->prepare("INSERT INTO student_requirement_upload_notifications (user_id, detail, admin_viewed) VALUES (?, ?, 0)");
        $json = json_encode([['key' => '__placement', 'label' => 'Preferred placement replaced — new Application SIT needs validation']]);
        foreach (array_keys($pending) as $uid) {
            if (isset($claimed[$uid])) continue;
            $ins->bind_param('i', $uid); $ins->execute();
            if ($ins->affected_rows !== 1) continue;   // another admin / tab claimed it first
            $add->bind_param('is', $uid, $json); $add->execute();
        }
        $ins->close(); $add->close();

        // release students that are no longer pending
        $rel = $conn->prepare("DELETE FROM student_requirement_upload_watch WHERE user_id = ? AND requirement_type = '__placement'");
        $del = $conn->prepare("DELETE FROM student_requirement_upload_notifications WHERE user_id = ? AND admin_viewed = 0 AND detail LIKE '%\"__placement\"%'");
        foreach (array_keys($claimed) as $uid) {
            if (isset($pending[$uid])) continue;
            $rel->bind_param('i', $uid); $rel->execute();
            $del->bind_param('i', $uid); $del->execute();
        }
        $rel->close(); $del->close();
    } catch (\Throwable $e) { /* never affects the page */ }
}
// ============================================================================
// NEW (this adjustment): SCHEDULE CHANGED BY THE COMPANY SUPERVISOR → CONTRACT NEEDS VALIDATION AGAIN
// ----------------------------------------------------------------------------
// On add_ojt_student.php a supervisor can set a new Day / Evening Schedule for an applicant. That removes the
// student's Application SIT (record + stored files) and writes a row to student_schedule_changes.
// This works exactly like the "preferred placement replaced" flow above:
//   • cv_sc_open() — the students whose LATEST schedule change is still open: active, and the new Application SIT
//     one has not been reviewed (Verified / Denied) yet.
//   • cv_sc_reconcile() — puts such a student back to 'Pending' (the value stored while a requirement is not verified),
//     so they are listed on this page again, with the contract waiting for the new upload. Returns their ids.
//   • cv_sc_detect() — announces each change ONCE as a notification in the SAME table / pipeline as the other student
//     submissions (item key '__schedule'): counted in the side-menu indicator, listed in the Inbox and announced by the
//     popup. A newer change for the same student replaces the earlier (still un-viewed) notification; when the contract
//     has been reviewed the claim is released and an un-viewed notification is removed.
// Any error (e.g. the history table does not exist yet) is swallowed.
// ============================================================================
function cv_sc_open($conn) {
    $open = [];
    try {
        $t = $conn->query("SHOW TABLES LIKE 'student_schedule_changes'");
        if (!$t || $t->num_rows === 0) return $open;
        $r = $conn->query("SELECT c.id, c.student_id, c.old_day_sched, c.old_evening_sched, c.new_day_sched, c.new_evening_sched, c.reason, c.created_at
                           FROM student_schedule_changes c
                           INNER JOIN (SELECT student_id, MAX(id) AS mid FROM student_schedule_changes GROUP BY student_id) m ON m.mid = c.id
                           INNER JOIN users u ON u.id = c.student_id
                           WHERE u.role = 'student' AND COALESCE(u.is_archived, 0) = 0
                             AND NOT EXISTS (SELECT 1 FROM requirements r WHERE r.user_id = c.student_id AND r.requirement_type = 'application_sit'
                                             AND r.file_name IS NOT NULL AND r.file_name <> '' AND r.status IN ('Verified', 'Denied'))");
        if ($r) while ($x = $r->fetch_assoc()) $open[(int)$x['student_id']] = $x;
    } catch (\Throwable $e) { /* never affects the page */ }
    return $open;
}
function cv_sc_reconcile($conn) {
    $ids = [];
    try {
        $open = cv_sc_open($conn);
        if (!$open) return $ids;
        $ids = array_keys($open);
        $in = implode(',', array_map('intval', $ids));
        $conn->query("UPDATE users SET validation_status = 'Pending'
                      WHERE id IN ($in) AND role = 'student' AND COALESCE(is_archived, 0) = 0 AND validation_status = 'Verified'");
    } catch (\Throwable $e) { /* never affects the page */ }
    return $ids;
}
function cv_sc_label($row) {
    $lab = function ($v) {
        $v = trim((string)$v);
        if ($v === '') return 'Not set';
        if (strcasecmp($v, 'None') === 0) return 'None';
        $names = ['M' => 'Mon', 'T' => 'Tue', 'W' => 'Wed', 'Th' => 'Thu', 'F' => 'Fri'];
        $out = []; $rest = $v;
        while ($rest !== '') {
            if (stripos($rest, 'Th') === 0) { $out[] = 'Thu'; $rest = substr($rest, 2); continue; }
            $c = strtoupper($rest[0]);
            if (!isset($names[$c])) return $v;   // free text from before the day picker existed
            $out[] = $names[$c]; $rest = substr($rest, 1);
        }
        return implode(', ', $out);
    };
    $reason = trim(preg_replace('/\s+/', ' ', (string)($row['reason'] ?? '')));
    if (function_exists('mb_strlen') && mb_strlen($reason) > 140) $reason = mb_substr($reason, 0, 137) . '...';
    return 'Schedule changed by the company supervisor (Day: ' . $lab($row['new_day_sched'] ?? '') . ' | Evening: ' . $lab($row['new_evening_sched'] ?? '')
         . ') — the Application SIT needs to be validated again' . ($reason !== '' ? '. Reason: ' . $reason : '');
}
function cv_sc_detect($conn) {
    try {
        if (!cv_sru_ensure($conn)) return;
        $open = cv_sc_open($conn);
        $t = $conn->query("SHOW TABLES LIKE 'student_schedule_changes'");
        if (!$t || $t->num_rows === 0) return;

        $claimed = [];
        $r = $conn->query("SELECT user_id, file_len FROM student_requirement_upload_watch WHERE requirement_type = '__schedule'");
        if (!$r) return;   // query problem: change nothing rather than wrongly releasing claims
        while ($x = $r->fetch_assoc()) $claimed[(int)$x['user_id']] = (int)$x['file_len'];

        $ins = $conn->prepare("INSERT IGNORE INTO student_requirement_upload_watch (user_id, requirement_type, file_len) VALUES (?, '__schedule', ?)");
        $upd = $conn->prepare("UPDATE student_requirement_upload_watch SET file_len = ? WHERE user_id = ? AND requirement_type = '__schedule' AND file_len = ?");
        $drop = $conn->prepare("DELETE FROM student_requirement_upload_notifications WHERE user_id = ? AND admin_viewed = 0 AND detail LIKE '%\"__schedule\"%'");
        $add = $conn->prepare("INSERT INTO student_requirement_upload_notifications (user_id, detail, admin_viewed) VALUES (?, ?, 0)");
        foreach ($open as $uid => $row) {
            $cid = (int)$row['id'];
            if (!isset($claimed[$uid])) {
                $ins->bind_param('ii', $uid, $cid); $ins->execute();
                if ($ins->affected_rows !== 1) continue;            // another admin / tab claimed it first
            } elseif ($claimed[$uid] !== $cid) {                    // a newer change for the same student
                $old = $claimed[$uid];
                $upd->bind_param('iii', $cid, $uid, $old); $upd->execute();
                if ($upd->affected_rows !== 1) continue;
                $drop->bind_param('i', $uid); $drop->execute();     // the earlier, still un-viewed announcement is replaced
            } else continue;
            $json = json_encode([['key' => '__schedule', 'label' => cv_sc_label($row)]]);
            $add->bind_param('is', $uid, $json); $add->execute();
        }
        $ins->close(); $upd->close(); $drop->close(); $add->close();

        // release students whose contract has been reviewed (or who are no longer around)
        $rel = $conn->prepare("DELETE FROM student_requirement_upload_watch WHERE user_id = ? AND requirement_type = '__schedule'");
        $del = $conn->prepare("DELETE FROM student_requirement_upload_notifications WHERE user_id = ? AND admin_viewed = 0 AND detail LIKE '%\"__schedule\"%'");
        foreach (array_keys($claimed) as $uid) {
            if (isset($open[$uid])) continue;
            $rel->bind_param('i', $uid); $rel->execute();
            $del->bind_param('i', $uid); $del->execute();
        }
        $rel->close(); $del->close();
    } catch (\Throwable $e) { /* never affects the page */ }
}
// UPDATED (this adjustment): detection no longer runs on every request (it used to run here, on page load and
// on every save) — only in the submissions check below and when the Inbox is opened.

/* NEW (this adjustment): submissions list — polled by every page's popup, and read by the Inbox */
if (isset($_GET['student_upload_list']) && $_GET['student_upload_list'] == '1') {
    header('Content-Type: application/json');
    session_write_close();   // FIX (this adjustment): read-only check — never keep this admin's other requests waiting
    cv_ph_reconcile($conn);   // NEW (this adjustment): placement replaced → student needs validation again
    cv_ph_detect($conn);      // NEW (this adjustment): …and that is announced as a notification
    cv_sc_reconcile($conn);   // NEW (this adjustment): schedule changed by the supervisor → student needs validation again
    cv_sc_detect($conn);      // NEW (this adjustment): …and that is announced as a notification
    cv_sru_detect($conn, $reqLabels);
    echo json_encode(['success' => true, 'rows' => cv_sru_list($conn), 'count' => cv_inbox_total($conn)]);
    exit;
}
/* NEW (this adjustment): "View Requirements" — the submission leaves the Inbox (and the indicator) */
if (isset($_POST['ajax_student_upload_viewed'])) {
    header('Content-Type: application/json');
    try {
        cv_sru_ensure($conn);
        $nid = (int)($_POST['id'] ?? 0);
        $q = $conn->prepare("SELECT user_id, detail FROM student_requirement_upload_notifications WHERE id = ?");
        $q->bind_param('i', $nid); $q->execute(); $row = $q->get_result()->fetch_assoc(); $q->close();
        if ($row) {
            $u = $conn->prepare("UPDATE student_requirement_upload_notifications SET admin_viewed = 1 WHERE id = ?");
            $u->bind_param('i', $nid); $u->execute(); $u->close();
        }
        $items = $row ? json_decode($row['detail'] ?? '', true) : [];
        echo json_encode(['success' => true, 'user_id' => $row ? (int)$row['user_id'] : (int)($_POST['user_id'] ?? 0),
                          'keys' => array_values(array_map(function ($i) { return (string)($i['key'] ?? ''); }, is_array($items) ? $items : [])),
                          'count' => cv_inbox_total($conn)]);
    } catch (\Throwable $e) {
        echo json_encode(['success' => false, 'message' => 'The submission could not be opened. Please try again.']);
    }
    exit;
}

$remarks = [
    "Blurry Image",
    "Wrong Document",
    "Incomplete Document",
    "Unreadable File",
    "Incorrect Format",
    "Expired Document",
    "Fake or Invalid"
];

/* ================= HELPERS: FREE-TEXT REJECTION REMARK =================
   NEW (this adjustment) — the remark an admin gives when REJECTING ("Denied") a student requirement or profile photo
   used to be picked from the fixed list above (a dropdown); it is now free text typed into a box, the same as the
   rejection remark in company_validation.php. requirements.remark / student_information.photo_remark can be a short
   VARCHAR, so rather than silently cutting a longer remark off (or altering tables that hold every student's uploaded
   documents) the real column limit is read once and both the box (maxlength) and the server-side check use it.
   A TEXT column (or anything unrecognised) allows up to 500 characters, this form's own limit. */
function svRemarkMaxLen($conn, $table = 'requirements', $column = 'remark') {
    static $cache = [];
    $key = $table . '.' . $column;
    if (isset($cache[$key])) return $cache[$key];
    $max = 500;
    try {
        $r = $conn->query("SHOW COLUMNS FROM `" . str_replace('`', '', $table) . "` LIKE '" . $conn->real_escape_string($column) . "'");
        $row = $r ? $r->fetch_assoc() : null;
        if ($row) {
            $type = strtolower((string)($row['Type'] ?? ''));
            if (preg_match('/^(?:var)?char\((\d+)\)/', $type, $m)) $max = min($max, max(1, (int)$m[1]));
            elseif (strpos($type, 'tinytext') === 0)               $max = min($max, 255);
        }
    } catch (\Throwable $e) { /* keep the default */ }
    return $cache[$key] = $max;
}
// The remark is one line of text everywhere it is shown (card, email, activity log): line breaks / repeated spaces fold into single spaces.
function svNormalizeRemark($remark) {
    $remark = trim((string)$remark);
    $one = preg_replace('/\s+/u', ' ', $remark);
    return trim($one !== null ? $one : $remark);
}
function svRemarkLength($remark) {
    return function_exists('mb_strlen') ? mb_strlen($remark, 'UTF-8') : strlen($remark);
}

/* ================= HELPER: CHECK DEPLOY STATUS ================= */
function getStudentGuardStatus($conn, $user_id) {
    $q = $conn->prepare("SELECT deploy_status FROM users WHERE id = ?");
    $q->bind_param("i", $user_id);
    $q->execute();
    $row = $q->get_result()->fetch_assoc();
    $q->close();
    return $row['deploy_status'] ?? 'Waiting';
}

/* ================= HELPER: RECOMPUTE & STORE VALIDATION STATUS ================= */
function recomputeValidationStatus($conn, $user_id) {
    $q = $conn->prepare("SELECT photo_status, student_photo FROM student_information WHERE user_id = ?");
    $q->bind_param("i", $user_id);
    $q->execute();
    $photoRow = $q->get_result()->fetch_assoc();
    $q->close();

    $photoVerified = (
        !empty($photoRow['photo_status']) &&
        $photoRow['photo_status'] === 'Verified' &&
        !empty($photoRow['student_photo'])
    );

    $q2 = $conn->prepare("SELECT status, file_name FROM requirements WHERE user_id = ?");
    $q2->bind_param("i", $user_id);
    $q2->execute();
    $reqResult = $q2->get_result();
    $q2->close();

    $allVerified = $photoVerified;
    if ($allVerified) {
        while ($row = $reqResult->fetch_assoc()) {
            if ($row['status'] !== 'Verified' || empty($row['file_name'])) {
                $allVerified = false;
                break;
            }
        }
    }

    $newStatus = $allVerified ? 'Verified' : 'Pending';
    $conn->query("ALTER TABLE users ADD COLUMN IF NOT EXISTS validation_status VARCHAR(20) DEFAULT 'Pending'");
    $upd = $conn->prepare("UPDATE users SET validation_status = ? WHERE id = ?");
    $upd->bind_param("si", $newStatus, $user_id);
    $upd->execute();
    $upd->close();
    return $newStatus;
}

/* ================= HELPER: SAVE UNDO SNAPSHOT TO SESSION ================= */
function saveUndoSnapshot($type, $payload, $label) {
    $token = bin2hex(random_bytes(12));
    if (!isset($_SESSION['undo_stack'])) $_SESSION['undo_stack'] = [];
    $_SESSION['undo_stack'] = array_filter($_SESSION['undo_stack'], fn($u) => (time() - $u['ts']) < 300);
    $_SESSION['undo_stack'][$token] = [
        'type'  => $type,
        'data'  => $payload,
        'label' => $label,
        'ts'    => time()
    ];
    return $token;
}

/* ================= AJAX: IMMEDIATE PHOTO UPDATE ================= */
if (isset($_POST['ajax_update_photo'])) {
    header('Content-Type: application/json');
    $user_id = intval($_POST['user_id']);
    $status  = $_POST['photo_status'];
    $remark  = ($status == "Denied") ? svNormalizeRemark($_POST['photo_remark'] ?? '') : null;   // UPDATED (this adjustment): free-text remark

    // Guard: deployed
    $deployStatus = getStudentGuardStatus($conn, $user_id);
    if ($deployStatus === 'Deployed') {
        echo json_encode(['success'=>false,'guard'=>'deployed']);
        exit;
    }

    // Guard: no submission
    $photoBlob = $conn->prepare("SELECT student_photo FROM student_information WHERE user_id = ?");
    $photoBlob->bind_param("i", $user_id);
    $photoBlob->execute();
    $photoBlobRow = $photoBlob->get_result()->fetch_assoc();
    $photoBlob->close();
    if (empty($photoBlobRow['student_photo']) && in_array($status, ['Verified','Denied'])) {
        echo json_encode(['success'=>false,'guard'=>'no_submission','field'=>'Profile Photo']);
        exit;
    }

    // Guard: denied needs remark
    if ($status == "Denied" && $remark === '') {
        echo json_encode(['success'=>false,'guard'=>'no_remark']);
        exit;
    }
    // NEW (this adjustment): the remark must fit the column it is stored in (never silently cut off)
    if ($status == "Denied" && svRemarkLength($remark) > svRemarkMaxLen($conn, 'student_information', 'photo_remark')) {
        echo json_encode(['success'=>false,'message'=>'The remark is too long (maximum ' . svRemarkMaxLen($conn, 'student_information', 'photo_remark') . ' characters).']);
        exit;
    }

    // Read previous status for undo
    $checkPrev = $conn->prepare("SELECT photo_status, photo_remark FROM student_information WHERE user_id = ?");
    $checkPrev->bind_param("i", $user_id);
    $checkPrev->execute();
    $prevData = $checkPrev->get_result()->fetch_assoc();
    $checkPrev->close();

    // Guard: already verified (no live undo)
    $hasLiveUndo = false;
    if (!empty($_SESSION['undo_stack'])) {
        foreach ($_SESSION['undo_stack'] as $tk => $snap) {
            if ($snap['type']==='photo' && $snap['data']['user_id']===$user_id && (time()-$snap['ts'])<300) {
                $hasLiveUndo = true; break;
            }
        }
    }
    if (!$hasLiveUndo && !empty($prevData['photo_status']) && $prevData['photo_status'] === 'Verified' && $status === 'Verified') {
        echo json_encode(['success'=>false,'guard'=>'already_verified','field'=>'Profile Photo']);
        exit;
    }

    $vtTmp = ($status === 'Verified') ? cv_vt_reserve($conn, $user_id, '__photo') : '';   // ADJUSTMENT: hold the student's held application BEFORE Verified is written
    // ── DB WRITE: only for non-Denied; Denied is deferred until toast expires ──
    if ($status !== 'Denied') {
        if ($status === 'Verified') {
            $stmt = $conn->prepare("UPDATE student_information SET photo_status = ?, photo_remark = NULL WHERE user_id = ?");
            $stmt->bind_param("si", $status, $user_id);
            $stmt->execute(); $stmt->close();

            // Save verified file
            $uq = $conn->prepare("SELECT first_name, middle_name, last_name FROM users WHERE id=?");
            $uq->bind_param("i", $user_id); $uq->execute();
            $ud = $uq->get_result()->fetch_assoc(); $uq->close();
            $safeFirst  = preg_replace("/[^a-zA-Z0-9]/","_",$ud['first_name']);
            $safeMiddle = preg_replace("/[^a-zA-Z0-9]/","_",$ud['middle_name']);
            $safeLast   = preg_replace("/[^a-zA-Z0-9]/","_",$ud['last_name']);
            $folder = !empty($safeMiddle) ? $safeFirst."_".$safeMiddle."_".$safeLast : $safeFirst."_".$safeLast;
            if (!file_exists("uploads")) mkdir("uploads",0777,true);
            $path = "uploads/".$folder;
            if (!file_exists($path)) mkdir($path,0777,true);
            $pq = $conn->prepare("SELECT student_photo FROM student_information WHERE user_id=?");
            $pq->bind_param("i",$user_id); $pq->execute();
            $pd = $pq->get_result()->fetch_assoc(); $pq->close();
            if (!empty($pd['student_photo'])) file_put_contents($path."/2x2_verified_".time().".jpg",$pd['student_photo']);
        } else {
            $stmt = $conn->prepare("UPDATE student_information SET photo_status = ?, photo_remark = NULL WHERE user_id = ?");
            $stmt->bind_param("si", $status, $user_id);
            $stmt->execute(); $stmt->close();
        }
    }
    // For Denied: DB write is deferred — committed by ajax_commit_denied on toast expire/dismiss

    $newOverall = recomputeValidationStatus($conn, $user_id);

    // Save undo snapshot
    $undoToken = saveUndoSnapshot('photo', [
        'user_id'      => $user_id,
        'prev_status'  => $prevData['photo_status'] ?? 'Pending',
        'prev_remark'  => $prevData['photo_remark'] ?? null,
        'new_status'   => $status,
        'new_remark'   => $remark,
    ], 'Profile Photo');
    if ($status === 'Verified') { cv_vt_mark($conn, $user_id, $undoToken, '__photo'); cv_vt_clear($conn, $vtTmp); }   // ADJUSTMENT: the entry now carries the toast's undo token

    // Store deferred email
    if (!isset($_SESSION['pending_emails'])) $_SESSION['pending_emails'] = [];
    $userQuery = $conn->prepare("SELECT first_name, last_name, email FROM users WHERE id = ?");
    $userQuery->bind_param("i", $user_id);
    $userQuery->execute();
    $userData = $userQuery->get_result()->fetch_assoc();
    $userQuery->close();
    $_SESSION['pending_emails'][$undoToken] = [
        'email'  => $userData['email'],
        'name'   => $userData['first_name'] . ' ' . $userData['last_name'],
        'label'  => 'Profile Photo',
        'status' => $status,
        'remark' => $remark,
        'ts'     => time(),
    ];

    echo json_encode([
        'success'       => true,
        'undo_token'    => $undoToken,
        'undo_label'    => 'Profile Photo',
        'new_status'    => $status,
        'new_remark'    => $remark,
        'overall_status'=> $newOverall,
        'user_id'       => $user_id,
        'is_denied'     => ($status === 'Denied'),
    ]);
    exit;
}

/* ================= HELPER: SAVE EVERY FILE OF A VERIFIED REQUIREMENT =============
   NEW (this adjustment) — MULTIPLE-FILE REQUIREMENTS. A requirement the student uploaded
   several files for is stored as several `requirements` rows (one file each) under the same
   user_id + requirement_type. Verifying used to read only the FIRST row, so only one file was
   written to the student's uploads/<folder>. This copies ALL of them, oldest first.
   - Files are read one at a time (by row id), so many large documents are never in memory together.
   - A single-file requirement keeps its original name:  <Label>_verified_<time>.<ext>
     Several files get a position number:               <Label>_<n>_verified_<time>.<ext>
   - Extension follows the real content (pdf / png / gif / webp), jpg otherwise (the old default).
   - A name that already exists is never overwritten.
   - Never throws: a file that cannot be written is logged and skipped so the verification itself,
     the undo, and the e-mail are never broken. Returns the number of files written.
   (Self-contained: the other svBlob* helpers are defined further down the file, after this runs.)
================================================================= */
if (!function_exists('svSaveVerifiedRequirementFiles')) {
    function svSaveVerifiedRequirementFiles($conn, $user_id, $type, $dir, $safeLabel) {
        $saved = 0;
        try {
            $ids = [];
            $st = $conn->prepare("SELECT id FROM requirements WHERE user_id = ? AND requirement_type = ? AND file_name IS NOT NULL AND file_name <> '' ORDER BY id ASC");
            if (!$st) return 0;
            $st->bind_param("is", $user_id, $type);
            $st->execute();
            $rs = $st->get_result();
            while ($r = $rs->fetch_assoc()) { $ids[] = (int) $r['id']; }
            $st->close();
            if (!$ids) return 0;
            if (!is_dir($dir) || !is_writable($dir)) {
                error_log("administrator.php: cannot save verified files, folder not writable: " . $dir);
                return 0;
            }

            $multi = count($ids) > 1;
            $stamp = time();
            $n = 0;
            foreach ($ids as $rid) {
                $n++;
                try {
                    $fq = $conn->prepare("SELECT file_name FROM requirements WHERE id = ? AND user_id = ? AND requirement_type = ?");
                    if (!$fq) continue;
                    $fq->bind_param("iis", $rid, $user_id, $type);
                    $fq->execute();
                    $row = $fq->get_result()->fetch_assoc();
                    $fq->close();
                    $blob = $row['file_name'] ?? '';
                    if (!is_string($blob) || $blob === '') continue;

                    $head = substr($blob, 0, 16);
                    $ext  = 'jpg';
                    if (strpos(substr($blob, 0, 2048), '%PDF-') !== false)                        $ext = 'pdf';
                    elseif (substr($head, 0, 8) === "\x89PNG\r\n\x1a\n")                           $ext = 'png';
                    elseif (substr($head, 0, 3) === 'GIF')                                         $ext = 'gif';
                    elseif (substr($head, 0, 4) === 'RIFF' && substr($head, 8, 4) === 'WEBP')      $ext = 'webp';

                    $base = $multi ? ($safeLabel . "_" . $n . "_verified_" . $stamp) : ($safeLabel . "_verified_" . $stamp);
                    $file = $dir . "/" . $base . "." . $ext;
                    $dup  = 1;
                    while (file_exists($file)) { $file = $dir . "/" . $base . "_" . $dup . "." . $ext; $dup++; }

                    if (file_put_contents($file, $blob) !== false) { $saved++; }
                    else { error_log("administrator.php: could not write verified file " . $file); }
                } catch (\Throwable $e) {
                    error_log("administrator.php: verified file save failed (row " . $rid . "): " . $e->getMessage());
                }
            }
        } catch (\Throwable $e) {
            error_log("administrator.php: verified requirement files save failed: " . $e->getMessage());
        }
        return $saved;
    }
}

/* ================= AJAX: IMMEDIATE REQUIREMENT UPDATE ================= */
if (isset($_POST['ajax_update_requirement'])) {
    header('Content-Type: application/json');
    $user_id = intval($_POST['user_id']);
    $type    = $_POST['requirement_type'];
    $status  = $_POST['status'];
    $remark  = ($status == "Denied") ? svNormalizeRemark($_POST['remark'] ?? '') : null;   // UPDATED (this adjustment): free-text remark

    global $reqLabels;

    // Guard: deployed
    $deployStatus = getStudentGuardStatus($conn, $user_id);
    if ($deployStatus === 'Deployed') {
        echo json_encode(['success'=>false,'guard'=>'deployed']);
        exit;
    }

    // Guard: no submission
    $blobCheck = $conn->prepare("SELECT file_name FROM requirements WHERE user_id = ? AND requirement_type = ?");
    $blobCheck->bind_param("is", $user_id, $type);
    $blobCheck->execute();
    $blobRow = $blobCheck->get_result()->fetch_assoc();
    $blobCheck->close();
    if ((empty($blobRow) || empty($blobRow['file_name'])) && in_array($status, ['Verified','Denied'])) {
        echo json_encode(['success'=>false,'guard'=>'no_submission','field'=>$reqLabels[$type] ?? $type]);
        exit;
    }

    // Guard: denied needs remark
    if ($status == "Denied" && $remark === '') {
        echo json_encode(['success'=>false,'guard'=>'no_remark']);
        exit;
    }
    // NEW (this adjustment): the remark must fit the column it is stored in (never silently cut off)
    if ($status == "Denied" && svRemarkLength($remark) > svRemarkMaxLen($conn, 'requirements', 'remark')) {
        echo json_encode(['success'=>false,'message'=>'The remark is too long (maximum ' . svRemarkMaxLen($conn, 'requirements', 'remark') . ' characters).']);
        exit;
    }

    // Read previous status for undo
    $checkPrev = $conn->prepare("SELECT status, remark FROM requirements WHERE user_id = ? AND requirement_type = ?");
    $checkPrev->bind_param("is", $user_id, $type);
    $checkPrev->execute();
    $prevData = $checkPrev->get_result()->fetch_assoc();
    $checkPrev->close();

    // Guard: already verified (no live undo)
    $hasLiveUndo = false;
    if (!empty($_SESSION['undo_stack'])) {
        foreach ($_SESSION['undo_stack'] as $tk => $snap) {
            if ($snap['type']==='requirement' && $snap['data']['user_id']===$user_id && $snap['data']['requirement_type']===$type && (time()-$snap['ts'])<300) {
                $hasLiveUndo = true; break;
            }
        }
    }
    if (!$hasLiveUndo && !empty($prevData['status']) && $prevData['status'] === 'Verified' && $status === 'Verified') {
        echo json_encode(['success'=>false,'guard'=>'already_verified','field'=>$reqLabels[$type] ?? $type]);
        exit;
    }

    $vtTmp = ($status === 'Verified') ? cv_vt_reserve($conn, $user_id, $type) : '';   // ADJUSTMENT: hold the student's held application BEFORE Verified is written
    // ── DB WRITE: only for non-Denied; Denied is deferred until toast expires ──
    if ($status !== 'Denied') {
        if ($status === 'Verified') {
            $stmt = $conn->prepare("UPDATE requirements SET status = ?, remark = NULL WHERE user_id = ? AND requirement_type = ?");
            $stmt->bind_param("sis", $status, $user_id, $type);
            $stmt->execute(); $stmt->close();

            // Save verified file
            $uq = $conn->prepare("SELECT first_name, middle_name, last_name FROM users WHERE id=?");
            $uq->bind_param("i", $user_id); $uq->execute();
            $ud = $uq->get_result()->fetch_assoc(); $uq->close();
            $safeFirst  = preg_replace("/[^a-zA-Z0-9]/","_",$ud['first_name']);
            $safeMiddle = preg_replace("/[^a-zA-Z0-9]/","_",$ud['middle_name']);
            $safeLast   = preg_replace("/[^a-zA-Z0-9]/","_",$ud['last_name']);
            $folder = !empty($safeMiddle) ? $safeFirst."_".$safeMiddle."_".$safeLast : $safeFirst."_".$safeLast;
            if (!file_exists("uploads")) mkdir("uploads",0777,true);
            $path = "uploads/".$folder;
            if (!file_exists($path)) mkdir($path,0777,true);
            // UPDATED (this adjustment): save EVERY file of the requirement (it used to save only the first row's file)
            $safeLabel = preg_replace("/[^a-zA-Z0-9]/","_",$reqLabels[$type] ?? $type);
            svSaveVerifiedRequirementFiles($conn, $user_id, $type, $path, $safeLabel);
        } else {
            $stmt = $conn->prepare("UPDATE requirements SET status = ?, remark = NULL WHERE user_id = ? AND requirement_type = ?");
            $stmt->bind_param("sis", $status, $user_id, $type);
            $stmt->execute(); $stmt->close();
        }
    }
    // For Denied: DB write is deferred — committed by ajax_commit_denied on toast expire/dismiss

    $newOverall = recomputeValidationStatus($conn, $user_id);

    // Save undo snapshot
    $undoLabel = $reqLabels[$type] ?? $type;
    $undoToken = saveUndoSnapshot('requirement', [
        'user_id'          => $user_id,
        'requirement_type' => $type,
        'prev_status'      => $prevData['status'] ?? 'Pending',
        'prev_remark'      => $prevData['remark'] ?? null,
        'new_status'       => $status,
        'new_remark'       => $remark,
    ], $undoLabel);
    if ($status === 'Verified') { cv_vt_mark($conn, $user_id, $undoToken, $type); cv_vt_clear($conn, $vtTmp); }   // ADJUSTMENT: the entry now carries the toast's undo token

    // Store deferred email
    if (!isset($_SESSION['pending_emails'])) $_SESSION['pending_emails'] = [];
    $userQuery = $conn->prepare("SELECT first_name, last_name, email FROM users WHERE id = ?");
    $userQuery->bind_param("i", $user_id);
    $userQuery->execute();
    $userData = $userQuery->get_result()->fetch_assoc();
    $userQuery->close();
    $_SESSION['pending_emails'][$undoToken] = [
        'email'  => $userData['email'],
        'name'   => $userData['first_name'] . ' ' . $userData['last_name'],
        'label'  => $reqLabels[$type],
        'status' => $status,
        'remark' => $remark,
        'ts'     => time(),
    ];

    echo json_encode([
        'success'          => true,
        'undo_token'       => $undoToken,
        'undo_label'       => $undoLabel,
        'new_status'       => $status,
        'new_remark'       => $remark,
        'overall_status'   => $newOverall,
        'user_id'          => $user_id,
        'requirement_type' => $type,
        'is_denied'        => ($status === 'Denied'),
    ]);
    exit;
}

/* ================= AJAX: COMMIT DEFERRED DENIED (called on toast expire/dismiss for Denied actions) ================= */
if (isset($_POST['ajax_commit_denied'])) {
    header('Content-Type: application/json');
    $token = trim($_POST['undo_token'] ?? '');

    if (empty($token) || empty($_SESSION['undo_stack'][$token])) {
        echo json_encode(['success' => false, 'message' => 'Token expired or invalid.']);
        exit;
    }

    $snap = $_SESSION['undo_stack'][$token];
    $d    = $snap['data'];

    if ($snap['type'] === 'requirement') {
        $remark = $d['new_remark'] ?? null;
        $stmt   = $conn->prepare("UPDATE requirements SET file_name = NULL, status = 'Denied', remark = ? WHERE user_id = ? AND requirement_type = ?");
        $stmt->bind_param("sis", $remark, $d['user_id'], $d['requirement_type']);
        $stmt->execute(); $stmt->close();
        recomputeValidationStatus($conn, $d['user_id']);
    } elseif ($snap['type'] === 'photo') {
        $remark = $d['new_remark'] ?? null;
        $stmt   = $conn->prepare("UPDATE student_information SET student_photo = NULL, photo_status = 'Denied', photo_remark = ? WHERE user_id = ?");
        $stmt->bind_param("si", $remark, $d['user_id']);
        $stmt->execute(); $stmt->close();
        recomputeValidationStatus($conn, $d['user_id']);
    }

    /* FIX (this adjustment): the email is taken out of the session and the session lock is released BEFORE the
       mail server is contacted. PHP lets only one request per session run at a time, so while an email was being
       sent (seconds — or until the mail server timed out) every other request from this admin waited, e.g. the
       next Deny's save, which left the "Processing" loading page on screen. Same emails, same conditions. */
    $mailToSend = !empty($_SESSION['pending_emails'][$token]) ? $_SESSION['pending_emails'][$token] : null;
    unset($_SESSION['pending_emails'][$token]);
    unset($_SESSION['undo_stack'][$token]);
    session_write_close();
    if ($mailToSend) {
        sendStatusEmail($mailToSend['email'], $mailToSend['name'], $mailToSend['label'], $mailToSend['status'], $mailToSend['remark']);
    }
    echo json_encode(['success' => true]);
    exit;
}

/* ================= AJAX: UNDO HANDLER (reverses already-committed write, or cancels deferred Denied) ================= */
if (isset($_POST['ajax_undo'])) {
    header('Content-Type: application/json');
    $token = trim($_POST['undo_token'] ?? '');
    if (empty($token) || empty($_SESSION['undo_stack'][$token])) {
        echo json_encode(['success'=>false,'message'=>'Undo token expired or invalid.']);
        exit;
    }
    $snap = $_SESSION['undo_stack'][$token];
    // 10 s grace: the browser's countdown starts when the response arrives, a moment after 'ts' was stamped here
    if ((time() - $snap['ts']) >= 310) {
        unset($_SESSION['undo_stack'][$token]);
        echo json_encode(['success'=>false,'message'=>'Undo window has expired (5 minutes).']);
        exit;
    }

    $d = $snap['data'];
    $isDenied = ($d['new_status'] === 'Denied');

    if ($snap['type'] === 'photo') {
        $prevStatus = $d['prev_status'] ?? 'Pending';
        $prevRemark = $d['prev_remark'] ?? null;

        if (!$isDenied) {
            if ($prevStatus === 'Denied') {
                $stmt = $conn->prepare("UPDATE student_information SET photo_status = 'Denied', photo_remark = ? WHERE user_id = ?");
                $stmt->bind_param("si", $prevRemark, $d['user_id']);
            } else {
                $stmt = $conn->prepare("UPDATE student_information SET photo_status = ?, photo_remark = NULL WHERE user_id = ?");
                $stmt->bind_param("si", $prevStatus, $d['user_id']);
            }
            $stmt->execute(); $stmt->close();
        }

        $newOverall = recomputeValidationStatus($conn, $d['user_id']);
        unset($_SESSION['undo_stack'][$token]);
        if (isset($_SESSION['pending_emails'][$token])) unset($_SESSION['pending_emails'][$token]);
        cv_vt_clear($conn, $token);   // ADJUSTMENT: undone — nothing left to wait for

        echo json_encode([
            'success'        => true,
            'message'        => 'Action undone successfully.',
            'type'           => 'photo',
            'user_id'        => $d['user_id'],
            'prev_status'    => $prevStatus,
            'prev_remark'    => $prevRemark,
            'overall_status' => $newOverall,
        ]);
    } elseif ($snap['type'] === 'requirement') {
        $prevStatus = $d['prev_status'] ?? 'Pending';
        $prevRemark = $d['prev_remark'] ?? null;
        $reqType    = $d['requirement_type'];

        if (!$isDenied) {
            if ($prevStatus === 'Denied') {
                $stmt = $conn->prepare("UPDATE requirements SET status = 'Denied', remark = ? WHERE user_id = ? AND requirement_type = ?");
                $stmt->bind_param("sis", $prevRemark, $d['user_id'], $reqType);
            } else {
                $stmt = $conn->prepare("UPDATE requirements SET status = ?, remark = NULL WHERE user_id = ? AND requirement_type = ?");
                $stmt->bind_param("sis", $prevStatus, $d['user_id'], $reqType);
            }
            $stmt->execute(); $stmt->close();
        }

        $newOverall = recomputeValidationStatus($conn, $d['user_id']);
        unset($_SESSION['undo_stack'][$token]);
        if (isset($_SESSION['pending_emails'][$token])) unset($_SESSION['pending_emails'][$token]);
        cv_vt_clear($conn, $token);   // ADJUSTMENT: undone — nothing left to wait for

        echo json_encode([
            'success'          => true,
            'message'          => 'Action undone successfully.',
            'type'             => 'requirement',
            'user_id'          => $d['user_id'],
            'requirement_type' => $reqType,
            'prev_status'      => $prevStatus,
            'prev_remark'      => $prevRemark,
            'overall_status'   => $newOverall,
        ]);
    } else {
        echo json_encode(['success'=>false,'message'=>'Unknown undo type.']);
    }
    exit;
}

/* ================= AJAX: SEND EMAIL (called on toast dismiss / countdown end for non-Denied) ================= */
if (isset($_POST['ajax_confirm_send'])) {
    header('Content-Type: application/json');
    $token = trim($_POST['undo_token'] ?? '');
    /* FIX (this adjustment): the email is taken out of the session and the session lock is released BEFORE the
       mail server is contacted. PHP lets only one request per session run at a time, so while an email was being
       sent (seconds — or until the mail server timed out) every other request from this admin waited, e.g. the
       next Deny's save, which left the "Processing" loading page on screen. Same emails, same conditions. */
    cv_vt_clear($conn, $token);   // ADJUSTMENT: the toast is over — a held application may now be released
    $mailToSend = null;
    if (!empty($token) && !empty($_SESSION['pending_emails'][$token])) {
        $p = $_SESSION['pending_emails'][$token];
        if ((time() - $p['ts']) < 600) $mailToSend = $p;
        unset($_SESSION['pending_emails'][$token]);
    }
    if (!empty($token) && isset($_SESSION['undo_stack'][$token])) {
        unset($_SESSION['undo_stack'][$token]);
    }
    session_write_close();
    if ($mailToSend) {
        sendStatusEmail($mailToSend['email'], $mailToSend['name'], $mailToSend['label'], $mailToSend['status'], $mailToSend['remark']);
    }
    echo json_encode(['success'=>true]);
    exit;
}

/* ================= AJAX: FLUSH EXPIRED PENDING EMAILS ================= */
if (isset($_POST['ajax_flush_emails'])) {
    header('Content-Type: application/json');
    cv_vt_restore_expired($conn);   // ADJUSTMENT: held applications whose Verified toast was never ended (tab closed) come back after the undo window
    /* FIX (this adjustment): the email is taken out of the session and the session lock is released BEFORE the
       mail server is contacted. PHP lets only one request per session run at a time, so while an email was being
       sent (seconds — or until the mail server timed out) every other request from this admin waited, e.g. the
       next Deny's save, which left the "Processing" loading page on screen. Same emails, same conditions. */
    $mailsToSend = [];
    if (!empty($_SESSION['pending_emails'])) {
        foreach ($_SESSION['pending_emails'] as $tk => $p) {
            if ((time() - $p['ts']) >= 300 && (time() - $p['ts']) < 600) {
                $mailsToSend[] = $p;
                unset($_SESSION['pending_emails'][$tk]);
            } elseif ((time() - $p['ts']) >= 600) {
                unset($_SESSION['pending_emails'][$tk]);
            }
        }
    }
    session_write_close();
    foreach ($mailsToSend as $p) {
        sendStatusEmail($p['email'], $p['name'], $p['label'], $p['status'], $p['remark']);
    }
    echo json_encode(['success'=>true]);
    exit;
}

/* ================= AJAX: EXPORT BATCH DATA AS JSON (for client-side Excel) ================= */
if (isset($_POST['ajax_export_batch'])) {
    header('Content-Type: application/json');

    $students = $conn->query("
        SELECT u.id, u.first_name, u.middle_name, u.last_name, u.course,
               u.deploy_status, u.validation_status,
               si.photo_status,
               ci.company, ci.contact_first_name, ci.contact_middle_initial, ci.contact_last_name
        FROM users u
        LEFT JOIN student_information si ON u.id = si.user_id
        LEFT JOIN ojt_assignments oa ON u.id = oa.student_id
        LEFT JOIN company_information ci ON oa.company_id = ci.user_id
        WHERE u.role = 'student' AND u.is_archived = 0
        ORDER BY u.last_name ASC
    ");

    $rows = [];
    while ($s = $students->fetch_assoc()) {
        $rows[] = [
            'Full Name'         => trim($s['first_name'] . ' ' . ($s['middle_name'] ? $s['middle_name'] . ' ' : '') . $s['last_name']),
            'Course'            => $s['course'] ?? '',
            'Deploy Status'     => $s['deploy_status'] ?? 'Waiting',
            'Validation Status' => $s['validation_status'] ?? 'Pending',
            'Photo Status'      => $s['photo_status'] ?? 'Pending',
            'Company'           => $s['company'] ?? 'Unassigned',
            'Supervisor'        => trim(($s['contact_first_name'] ?? '') . ' ' . ($s['contact_middle_initial'] ?? '') . ' ' . ($s['contact_last_name'] ?? '')),
        ];
    }
    echo json_encode(['success'=>true, 'data'=>$rows, 'count'=>count($rows)]);
    exit;
}

/* ================= AJAX: ARCHIVE BATCH ================= */
if (isset($_POST['ajax_archive_batch'])) {
    header('Content-Type: application/json');

    $batchLabel = trim($_POST['batch_label'] ?? ('OJT Batch ' . date('Y')));
    $archivedAt = date('Y-m-d H:i:s');
    $archivedBy = trim(($_SESSION['first_name'] ?? '') . ' ' . ($_SESSION['last_name'] ?? ''));
    if (empty(trim($archivedBy))) $archivedBy = 'Admin';

    $conn->query("ALTER TABLE users ADD COLUMN IF NOT EXISTS is_archived TINYINT(1) NOT NULL DEFAULT 0");
    $conn->query("UPDATE users SET is_archived = 0 WHERE is_archived IS NULL");

    cv_ensure_archived_students_table($conn);   // CLEAN-UP (audit): shared definition, see cv_ensure_archived_students_table()

    $students = $conn->query("
        SELECT u.id, u.first_name, u.middle_name, u.last_name, u.course,
               u.deploy_status, u.validation_status,
               si.photo_status,
               ci.company, ci.contact_first_name, ci.contact_middle_initial, ci.contact_last_name
        FROM users u
        LEFT JOIN student_information si ON u.id = si.user_id
        LEFT JOIN ojt_assignments oa ON u.id = oa.student_id
        LEFT JOIN company_information ci ON oa.company_id = ci.user_id
        WHERE u.role = 'student' AND u.is_archived = 0
    ");

    if (!$students) {
        echo json_encode(['success'=>false,'message'=>'Query failed: '.$conn->error]);
        exit;
    }

    $count      = 0;
    $studentIds = [];

    while ($s = $students->fetch_assoc()) {
        $uid        = intval($s['id']);
        $fName      = $s['first_name']    ?? '';
        $mName      = $s['middle_name']   ?? '';
        $lName      = $s['last_name']     ?? '';
        $course     = $s['course']        ?? '';
        $deploy     = $s['deploy_status'] ?? 'Waiting';
        $valStat    = $s['validation_status'] ?? 'Pending';
        $photoSt    = $s['photo_status']  ?? 'Pending';
        $company    = $s['company']       ?? '';
        $mi = trim($s['contact_middle_initial'] ?? '');
        $supervisor = trim(($s['contact_first_name'] ?? '') . ' ' . ($mi ? $mi . ' ' : '') . ($s['contact_last_name'] ?? ''));

        $bl  = $conn->real_escape_string($batchLabel);
        $fn  = $conn->real_escape_string($fName);
        $mn  = $conn->real_escape_string($mName);
        $ln  = $conn->real_escape_string($lName);
        $cr  = $conn->real_escape_string($course);
        $dep = $conn->real_escape_string($deploy);
        $vs  = $conn->real_escape_string($valStat);
        $ps  = $conn->real_escape_string($photoSt);
        $co  = $conn->real_escape_string($company);
        $sv  = $conn->real_escape_string($supervisor);
        $aa  = $conn->real_escape_string($archivedAt);
        $ab  = $conn->real_escape_string($archivedBy);

        $conn->query("INSERT INTO archived_students
            (batch_label, user_id, first_name, middle_name, last_name, course,
             deploy_status, validation_status, photo_status, company, supervisor, archived_at, archived_by)
            VALUES ('$bl', $uid, '$fn', '$mn', '$ln', '$cr',
                    '$dep', '$vs', '$ps', '$co', '$sv', '$aa', '$ab')");

        $studentIds[] = $uid;
        $count++;
    }

    if ($count === 0) {
        echo json_encode(['success'=>false,'message'=>'No active students found to archive.']);
        exit;
    }

    $ids = implode(',', $studentIds);
    $conn->query("UPDATE users SET is_archived = 1 WHERE id IN ($ids) AND role = 'student'");

    echo json_encode(['success'=>true, 'archived'=>$count, 'batch'=>$batchLabel]);
    exit;
}

/* ================= AJAX: UNARCHIVE A BATCH ================= */
if (isset($_POST['ajax_unarchive_batch'])) {
    header('Content-Type: application/json');

    $batchLabel = trim($_POST['batch_label'] ?? '');
    if (empty($batchLabel)) {
        echo json_encode(['success'=>false,'message'=>'No batch specified.']);
        exit;
    }

    $conn->query("ALTER TABLE users ADD COLUMN IF NOT EXISTS is_archived TINYINT(1) NOT NULL DEFAULT 0");
    $conn->query("UPDATE users SET is_archived = 0 WHERE is_archived IS NULL");

    $stmtB = $conn->prepare("SELECT user_id, batch_label FROM archived_students WHERE TRIM(batch_label) = TRIM(?)");
    $stmtB->bind_param("s", $batchLabel);
    $stmtB->execute();
    $batchRes  = $stmtB->get_result();
    $userIds   = [];
    $realLabel = $batchLabel;
    while ($r = $batchRes->fetch_assoc()) {
        $userIds[]  = intval($r['user_id']);
        $realLabel  = $r['batch_label'];
    }
    $stmtB->close();

    if (empty($userIds)) {
        $allBatches = [];
        $allRes = $conn->query("SELECT DISTINCT batch_label FROM archived_students");
        if ($allRes) { while ($ab = $allRes->fetch_assoc()) $allBatches[] = $ab['batch_label']; }
        echo json_encode([
            'success'        => false,
            'message'        => 'No archived records found for this batch.',
            'received_label' => $batchLabel,
            'stored_batches' => $allBatches
        ]);
        exit;
    }

    $ids = implode(',', $userIds);

    $activeCheck = $conn->query("SELECT COUNT(*) as cnt FROM users WHERE role='student' AND is_archived = 0 AND id NOT IN ($ids)");
    $activeRow   = $activeCheck->fetch_assoc();
    if (intval($activeRow['cnt']) > 0) {
        echo json_encode([
            'success' => false,
            'blocked' => true,
            'active'  => intval($activeRow['cnt']),
            'message' => 'There are currently active students in the dashboard. Please archive the current batch first before unarchiving a previous batch.'
        ]);
        exit;
    }

    $updateRes = $conn->query("UPDATE users SET is_archived = 0 WHERE id IN ($ids)");
    $updatedRows = $conn->affected_rows;

    $stmtD = $conn->prepare("DELETE FROM archived_students WHERE TRIM(batch_label) = TRIM(?)");
    $stmtD->bind_param("s", $realLabel);
    $stmtD->execute();
    $deletedRows = $stmtD->affected_rows;
    $stmtD->close();

    echo json_encode([
        'success'      => true,
        'restored'     => count($userIds),
        'batch'        => $realLabel,
        'updated_rows' => $updatedRows,
        'deleted_rows' => $deletedRows
    ]);
    exit;
}

/* ================= AJAX: FETCH ARCHIVED RECORDS ================= */
if (isset($_POST['ajax_fetch_archive'])) {
    header('Content-Type: application/json');

    cv_ensure_archived_students_table($conn);   // CLEAN-UP (audit): shared definition, see cv_ensure_archived_students_table()

    $rows = [];
    $batches = [];
    $res = $conn->query("SELECT * FROM archived_students ORDER BY archived_at DESC, batch_label ASC, last_name ASC");
    if ($res) {
        while ($r = $res->fetch_assoc()) {
            $fullName = trim($r['first_name'] . ' ' . ($r['middle_name'] ? $r['middle_name'] . ' ' : '') . $r['last_name']);
            $rows[] = [
                'batch'      => $r['batch_label'],
                'name'       => $fullName,
                'course'     => $r['course'],
                'validation' => $r['validation_status'],
                'deploy'     => $r['deploy_status'],
                'company'    => $r['company'],
                'supervisor' => $r['supervisor'],
                'archived'   => date('M d, Y', strtotime($r['archived_at'] ?? '')),
                'by'         => $r['archived_by'],
            ];
            if (!in_array($r['batch_label'], $batches)) $batches[] = $r['batch_label'];
        }
    }
    echo json_encode(['success'=>true, 'rows'=>$rows, 'batches'=>$batches]);
    exit;
}
/* ================= AJAX: UNGRADED COUNT ================= */
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

/* ================= AJAX: LIVE SUBMISSION CHECK ================================
   Polled periodically by the client to detect when a student uploads a
   requirement or profile photo, so the review UI can activate itself
   in place — without the admin needing to refresh/reload the page.
   Only lightweight booleans/status text are returned (file length, not
   the file itself) to keep this cheap enough to poll frequently.
================================================================= */
if (isset($_POST['ajax_check_submissions'])) {
    header('Content-Type: application/json');

    $heldIds = cv_ph_reconcile($conn);   // NEW (this adjustment): placement replaced → student needs validation again
    $scheduleIds = cv_sc_reconcile($conn);   // NEW (this adjustment): schedule changed by the supervisor → student needs validation again
    $state = [];

    $uRes = $conn->query("
        SELECT u.id, u.deploy_status,
               LENGTH(si.student_photo) AS photo_len,
               si.photo_status, si.photo_remark
        FROM users u
        LEFT JOIN student_information si ON si.user_id = u.id
        WHERE u.role = 'student' AND u.is_archived = 0
    ");
    if ($uRes) {
        while ($u = $uRes->fetch_assoc()) {
            $state[$u['id']] = [
                'deployed' => ($u['deploy_status'] === 'Deployed'),
                'photo' => [
                    'has_file' => !empty($u['photo_len']),
                    'status'   => $u['photo_status'] ?? 'Pending',
                    'remark'   => $u['photo_remark'] ?? null,
                ],
                'reqs' => [],
            ];
        }
    }

    $rRes = $conn->query("SELECT user_id, requirement_type, LENGTH(file_name) AS file_len, status, remark FROM requirements");
    if ($rRes) {
        while ($r = $rRes->fetch_assoc()) {
            $uid = $r['user_id'];
            if (!isset($state[$uid])) continue;
            $state[$uid]['reqs'][$r['requirement_type']] = [
                'has_file' => !empty($r['file_len']),
                'status'   => $r['status'],
                'remark'   => $r['remark'],
            ];
        }
    }

    // NEW (this adjustment): the students that are not verified (name for the popup) and the ones on hold after a
    // placement replacement, so the page can bring a missing / outdated student row back without a reload.
    $needs = [];
    try {
        $nRes = $conn->query("SELECT id, first_name, middle_name, last_name FROM users
                              WHERE role = 'student' AND COALESCE(is_archived, 0) = 0 AND COALESCE(validation_status, 'Pending') <> 'Verified'");
        if ($nRes) while ($n = $nRes->fetch_assoc()) {
            $needs[] = ['id' => (int)$n['id'],
                        'name' => trim(preg_replace('/\s+/', ' ', ($n['first_name'] ?? '') . ' ' . ($n['middle_name'] ?? '') . ' ' . ($n['last_name'] ?? '')))];
        }
    } catch (\Throwable $e) {}

    echo json_encode(['success' => true, 'state' => $state, 'needs_validation' => $needs, 'held' => $heldIds, 'schedule_held' => $scheduleIds]);
    exit;
}

/* ================= HELPER: IS THIS STORED FILE A PDF? =========================
   NEW (this adjustment) — PDF DISPLAY + PREVIEW, ported from company_validation.php.
   Students may upload a JPG, PNG or a PDF (submit_requirements.php), all stored in the
   same BLOB column, so the Requirements gallery has to tell them apart: an image is
   drawn as a thumbnail exactly as before, a PDF as a PDF tile that opens the preview
   modal. Only the first 2 KB is inspected. Safe on empty / non-string values.
================================================================= */
if (!function_exists('svBlobIsPdf')) {
    function svBlobIsPdf($blob) {
        if (!is_string($blob) || $blob === '') return false;
        $head = substr($blob, 0, 2048);
        if (strpos($head, '%PDF-') !== false) return true;
        try {
            if (class_exists('finfo')) {
                $fi = new finfo(FILEINFO_MIME_TYPE);
                return strpos((string) $fi->buffer($head), 'pdf') !== false;
            }
        } catch (\Throwable $e) { /* fall through: not a PDF */ }
        return false;
    }
}

/* ================= HELPER: EVERY STORED FILE OF ONE REQUIREMENT ===================
   NEW (this adjustment) — MULTIPLE-FILE (stacked card) DISPLAY, ported from
   company_validation.php's $cvFetchEntries(): returns one {id, isPdf} per stored file
   (one `requirements` row each, oldest first) under a requirement_type. Only the first
   2 KB of each file is read (enough to tell a PDF from an image), so the documents are
   never loaded just to draw a card; they are streamed on demand by stream_student_file
   (&file_id=…). Rows with no file are not entries. Fully guarded: any failure returns
   an empty list, i.e. the card simply keeps its normal single-file display.
================================================================= */
if (!function_exists('svFetchFileEntries')) {
    function svFetchFileEntries($conn, $uid, $type) {
        $entries = [];
        try {
            $st = $conn->prepare("SELECT id, LEFT(file_name, 2048) AS head FROM requirements WHERE user_id = ? AND requirement_type = ? AND file_name IS NOT NULL AND file_name <> '' ORDER BY id ASC");
            if (!$st) return $entries;
            $st->bind_param("is", $uid, $type);
            $st->execute();
            $rs = $st->get_result();
            while ($er = $rs->fetch_assoc()) {
                $entries[] = ['id' => (int) $er['id'], 'isPdf' => svBlobIsPdf((string) $er['head'])];
            }
            $st->close();
        } catch (\Throwable $e) { return []; }
        return $entries;
    }
}

/* ================= STREAM A STUDENT'S SUBMITTED FILE (inline) ====================
   NEW (this adjustment) — the same idea as company_validation.php's stream_req_blob:
   serves ONE stored file (a requirement, or the profile photo with type=photo) with
   its real content type so the browser can show a PDF in an <iframe> and an image in
   the preview viewer, instead of base64-embedding whole documents in the page.
   Only reachable with the admin session guard at the top of this file. Prepared
   statements only; the content type is whitelisted and sent with nosniff, so a
   stored file can never be served as HTML / script.
================================================================= */
if (isset($_GET['stream_student_file'])) {
    $sf_uid  = intval($_GET['stream_student_file']);
    $sf_type = trim((string) ($_GET['type'] ?? ''));
    if ($sf_uid <= 0 || $sf_type === '' || strlen($sf_type) > 100) { http_response_code(400); echo "Bad request."; exit; }

    // optional file_id: streams ONE specific entry when a requirement holds several files (one `requirements`
    // row each). It is always cross-checked against BOTH user_id and requirement_type, so it can only ever
    // return a file that really belongs to that student's requirement. Without it the query is the original one.
    $sf_fid  = ($sf_type !== 'photo') ? intval($_GET['file_id'] ?? 0) : 0;
    $sf_blob = null;
    if ($sf_type === 'photo') {
        $sf_st = $conn->prepare("SELECT student_photo AS blob_data FROM student_information WHERE user_id = ?");
    } elseif ($sf_fid > 0) {
        $sf_st = $conn->prepare("SELECT file_name AS blob_data FROM requirements WHERE id = ? AND user_id = ? AND requirement_type = ?");
    } else {
        $sf_st = $conn->prepare("SELECT file_name AS blob_data FROM requirements WHERE user_id = ? AND requirement_type = ?");
    }
    if (!$sf_st) { http_response_code(500); echo "Could not read the file."; exit; }
    if ($sf_type === 'photo')  { $sf_st->bind_param("i", $sf_uid); }
    elseif ($sf_fid > 0)       { $sf_st->bind_param("iis", $sf_fid, $sf_uid, $sf_type); }
    else                       { $sf_st->bind_param("is", $sf_uid, $sf_type); }
    $sf_st->execute();
    $sf_row = $sf_st->get_result()->fetch_assoc();
    $sf_st->close();
    $sf_blob = $sf_row['blob_data'] ?? null;
    if (!is_string($sf_blob) || $sf_blob === '') { http_response_code(404); echo "No file found."; exit; }

    $sf_mime = '';
    try { $sf_fi = new finfo(FILEINFO_MIME_TYPE); $sf_mime = (string) $sf_fi->buffer($sf_blob); } catch (\Throwable $e) { $sf_mime = ''; }
    if ($sf_mime === 'application/pdf' || svBlobIsPdf($sf_blob)) { $sf_mime = 'application/pdf'; }
    elseif (!in_array($sf_mime, ['image/jpeg', 'image/png', 'image/gif', 'image/webp'], true)) {
        http_response_code(415); echo "Unsupported file type."; exit;
    }
    $sf_ext = ['application/pdf' => 'pdf', 'image/jpeg' => 'jpg', 'image/png' => 'png', 'image/gif' => 'gif', 'image/webp' => 'webp'][$sf_mime];

    session_write_close();   // a large file must not hold the session lock while it streams
    while (ob_get_level() > 0) { @ob_end_clean(); }
    header('Content-Type: ' . $sf_mime);
    header('X-Content-Type-Options: nosniff');
    header('Content-Disposition: inline; filename="' . preg_replace('/[^a-zA-Z0-9_-]/', '_', $sf_type) . '_' . $sf_uid . ($sf_fid > 0 ? '_' . $sf_fid : '') . '.' . $sf_ext . '"');
    header('Content-Length: ' . strlen($sf_blob));
    header('Cache-Control: private, no-cache, must-revalidate');   // a re-upload under the same URL must never show the old file
    echo $sf_blob; exit;
}

/* ================= AJAX: FETCH A SINGLE SUBMISSION FILE ========================
   Used right after a live submission is detected, to refresh just that one
   thumbnail/profile photo image without reloading the whole page.
================================================================= */
if (isset($_POST['ajax_get_file'])) {
    header('Content-Type: application/json');
    $user_id = intval($_POST['user_id']);
    $type    = trim($_POST['type'] ?? '');

    if ($type === 'photo') {
        $q = $conn->prepare("SELECT student_photo FROM student_information WHERE user_id = ?");
        $q->bind_param("i", $user_id);
        $q->execute();
        $row = $q->get_result()->fetch_assoc();
        $q->close();
        $b64 = (!empty($row['student_photo'])) ? base64_encode($row['student_photo']) : null;
        $isPdfFile = svBlobIsPdf($row['student_photo'] ?? null);   // NEW (this adjustment)
    } else {
        $q = $conn->prepare("SELECT file_name FROM requirements WHERE user_id = ? AND requirement_type = ?");
        $q->bind_param("is", $user_id, $type);
        $q->execute();
        $row = $q->get_result()->fetch_assoc();
        $q->close();
        $b64 = (!empty($row['file_name'])) ? base64_encode($row['file_name']) : null;
        $isPdfFile = svBlobIsPdf($row['file_name'] ?? null);   // NEW (this adjustment)
        $filesMeta = svFetchFileEntries($conn, $user_id, $type);   // NEW (this adjustment): every file of this requirement (stacked-card display)
    }

    echo json_encode(['success' => true, 'file' => $b64, 'isPdf' => !empty($isPdfFile), 'files' => $filesMeta ?? []]);
    exit;
}

/* ================= LEGACY FORM HANDLERS (kept for non-JS fallback) ================= */
if(isset($_POST['update_photo'])){
    $user_id = intval($_POST['user_id']);
    $status  = $_POST['photo_status'];
    $remark  = ($status == "Denied") ? svNormalizeRemark($_POST['photo_remark'] ?? '') : NULL;   // UPDATED (this adjustment): free-text remark

    $deployStatus = getStudentGuardStatus($conn, $user_id);
    if($deployStatus === 'Deployed') {
        header("Location: administrator.php?guard_error=deployed&user_id=" . $user_id . "&open_user=" . $user_id);
        exit;
    }

    $checkVerified = $conn->prepare("SELECT photo_status, photo_remark FROM student_information WHERE user_id = ?");
    $checkVerified->bind_param("i", $user_id);
    $checkVerified->execute();
    $currentData = $checkVerified->get_result()->fetch_assoc();
    $checkVerified->close();

    $photoBlob = $conn->prepare("SELECT student_photo FROM student_information WHERE user_id = ?");
    $photoBlob->bind_param("i", $user_id);
    $photoBlob->execute();
    $photoBlobRow = $photoBlob->get_result()->fetch_assoc();
    $photoBlob->close();
    if (empty($photoBlobRow['student_photo']) && in_array($status, ['Verified','Denied'])) {
        header("Location: administrator.php?guard_error=no_submission&field=" . urlencode("Profile Photo") . "&user_id=" . $user_id . "&open_user=" . $user_id);
        exit;
    }

    if($status == "Denied" && $remark === ''){
        echo "<script>alert('Please enter a remark if Denied'); window.history.back();</script>";
        exit;
    }
    if($status == "Denied" && svRemarkLength($remark) > svRemarkMaxLen($conn, 'student_information', 'photo_remark')){
        echo "<script>alert('The remark is too long (maximum " . svRemarkMaxLen($conn, 'student_information', 'photo_remark') . " characters).'); window.history.back();</script>";
        exit;
    }

    if ($status === 'Denied') {
        $stmt = $conn->prepare("UPDATE student_information SET student_photo = NULL, photo_status = 'Denied', photo_remark = ? WHERE user_id = ?");
        $stmt->bind_param("si", $remark, $user_id);
    } else {
        $stmt = $conn->prepare("UPDATE student_information SET photo_status = ?, photo_remark = NULL WHERE user_id = ?");
        $stmt->bind_param("si", $status, $user_id);
    }
    $stmt->execute(); $stmt->close();
    recomputeValidationStatus($conn, $user_id);

    header("Location: administrator.php?msg=photo_updated&open_user=" . $user_id);
    exit;
}

if(isset($_POST['update_requirement'])){
    $user_id = intval($_POST['user_id']);
    $type    = $_POST['requirement_type'];
    $status  = $_POST['status'];
    $remark  = ($status == "Denied") ? svNormalizeRemark($_POST['remark'] ?? '') : NULL;   // UPDATED (this adjustment): free-text remark

    $deployStatus = getStudentGuardStatus($conn, $user_id);
    if($deployStatus === 'Deployed') {
        header("Location: administrator.php?guard_error=deployed&user_id=" . $user_id . "&open_user=" . $user_id);
        exit;
    }

    $blobCheck = $conn->prepare("SELECT file_name FROM requirements WHERE user_id = ? AND requirement_type = ?");
    $blobCheck->bind_param("is", $user_id, $type);
    $blobCheck->execute();
    $blobRow = $blobCheck->get_result()->fetch_assoc();
    $blobCheck->close();
    if ((empty($blobRow) || empty($blobRow['file_name'])) && in_array($status, ['Verified','Denied'])) {
        $noSubLabel = urlencode($reqLabels[$type] ?? $type);
        header("Location: administrator.php?guard_error=no_submission&field=" . $noSubLabel . "&user_id=" . $user_id . "&open_user=" . $user_id);
        exit;
    }

    if($status == "Denied" && $remark === ''){
        echo "<script>alert('Please enter a remark if Denied'); window.history.back();</script>";
        exit;
    }
    if($status == "Denied" && svRemarkLength($remark) > svRemarkMaxLen($conn, 'requirements', 'remark')){
        echo "<script>alert('The remark is too long (maximum " . svRemarkMaxLen($conn, 'requirements', 'remark') . " characters).'); window.history.back();</script>";
        exit;
    }

    if ($status === 'Denied') {
        $stmt = $conn->prepare("UPDATE requirements SET file_name = NULL, status = 'Denied', remark = ? WHERE user_id = ? AND requirement_type = ?");
        $stmt->bind_param("sis", $remark, $user_id, $type);
    } else {
        $stmt = $conn->prepare("UPDATE requirements SET status = ?, remark = NULL WHERE user_id = ? AND requirement_type = ?");
        $stmt->bind_param("sis", $status, $user_id, $type);
    }
    $stmt->execute(); $stmt->close();
    recomputeValidationStatus($conn, $user_id);

    header("Location: administrator.php?msg=requirement_updated&open_user=" . $user_id);
    exit;
}
/* ================= ENSURE ADMIN APPROVALS TABLE ================= */
cv_ensure_admin_application_approvals_table($conn);   // CLEAN-UP (audit): shared definition, see cv_ensure_admin_application_approvals_table()

/* ================= NEW (endorsement flow): ENDORSEMENT LETTERS TABLE + BUILDER =================
   Application flow (this adjustment):
     1) Student applies (company_list.php)          → admin_application_approvals (unchanged)
     2) Admin Allows here                            → ojt_applications (unchanged) AND an
                                                      Endorsement Letter (ENDORSEMENT_form_builder.php)
                                                      is issued to the student (endorsement_letters)
     3) Student opens the letter in their Inbox      → prints / saves it as PDF, gets it signed,
                                                      uploads it back (company_list.php)
     4) Company validates the upload                  → Verified  = student registered (add_ojt_student.php)
                                                      → Rejected  = remarks sent back, student re-uploads
   The same CREATE TABLE IF NOT EXISTS lives in all three pages, so whichever page runs first
   creates it. */
require_once __DIR__ . '/ENDORSEMENT_form_builder.php';

if (!function_exists('ensureEndorsementLettersTable')) {
    function ensureEndorsementLettersTable($conn) {
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
        } catch (\Throwable $e) { /* never break the page over this */ }
    }
}
ensureEndorsementLettersTable($conn);

/* Keys the admin may set on a letter — anything else posted is ignored. */
$ENDORSEMENT_ALLOWED_KEYS = [
    'department_name', 'letter_date', 'recipient_name', 'recipient_position', 'company_name',
    'recipient_address', 'salutation_name', 'program', 'required_hours', 'start_date',
    'end_date', 'students', 'adviser_name', 'dean_name', 'dean_title', 'director_name',
];

/* Default letter values for one application (company contact, student, course, today). */
/* ================= UPDATED (endorsement training section ← course_offering.php) =================
   The Training part of the letter now comes from the student's course in the
   `course_offerings` table (managed on course_offering.php):
     • Program / Course       → course_offerings.course
     • Total Hour Requirement → course_offerings.total_hours   (letter: "for a period of N hours")
     • Required Hours per Day → course_offerings.daily_hours    (used for the end date)
     • Start                  → month chosen by the admin (dropdown), year = current year
     • End                    → Start + Est. Duty Days, where
                                Est. Duty Days = ceil(total_hours / daily_hours), Mon–Fri only
                                (the exact rule course_offering.php / admin_student_list.php use)
   The OJT Adviser is always the logged-in administrator. */
function endoFormatHours($value) {
    $f = (float)$value;
    return ($f == floor($f)) ? number_format($f, 0, '.', '') : rtrim(rtrim(number_format($f, 2, '.', ''), '0'), '.');
}

function endoStudentCourse($conn, $student_id) {
    try {
        $q = $conn->prepare("SELECT course FROM users WHERE id = ?");
        $q->bind_param("i", $student_id);
        $q->execute();
        $r = $q->get_result()->fetch_assoc();
        $q->close();
        return trim((string)($r['course'] ?? ''));
    } catch (\Throwable $e) { return ''; }
}

function endoFindCourseOffering($conn, $course) {
    if (trim((string)$course) === '') return null;
    try {
        $q = $conn->prepare("SELECT course, total_hours, daily_hours FROM course_offerings WHERE LOWER(TRIM(course)) = LOWER(TRIM(?)) LIMIT 1");
        $q->bind_param("s", $course);
        $q->execute();
        $r = $q->get_result()->fetch_assoc();
        $q->close();
        return $r ?: null;
    } catch (\Throwable $e) { return null; } // table not created yet
}

/* Same float-safe rule as course_offering_est_duty_days() in course_offering.php. */
function endoEstDutyDays($totalHours, $dailyHours) {
    $total = (float)$totalHours;
    $daily = (float)$dailyHours;
    if ($total <= 0 || $daily <= 0) return 0;
    return (int)ceil(round($total / $daily, 6));
}

/* Start = first Mon–Fri day of the chosen month; End = the last of $dutyDays Mon–Fri duty days. */
function endoTrainingSchedule($month, $year, $dutyDays) {
    $month = max(1, min(12, (int)$month));
    $d = new DateTime(sprintf('%04d-%02d-01', (int)$year, $month));
    while ((int)$d->format('N') >= 6) $d->modify('+1 day');
    $start = clone $d;
    $count = 1;
    while ($count < max(1, (int)$dutyDays)) {
        $d->modify('+1 day');
        if ((int)$d->format('N') <= 5) $count++;
    }
    return [
        'start_iso'   => $start->format('Y-m-d'),
        'end_iso'     => $d->format('Y-m-d'),
        // UPDATED: abbreviated month names in the letter (e.g. "Aug 2026").
        'start_label' => $start->format('M Y'),
        'end_label'   => $d->format('M Y'),
    ];
}

/* Month number from a "Month Year" label (e.g. "August 2026"); null when none. */
function endoMonthFromLabel($label) {
    $label = strtolower(trim((string)$label));
    for ($m = 1; $m <= 12; $m++) {
        // UPDATED: match on the 3-letter abbreviation, so both "Aug 2026" and "August 2026" parse.
        $abbr = strtolower(date('M', mktime(0, 0, 0, $m, 1)));
        if ($label !== '' && strpos($label, $abbr) === 0) return $m;
    }
    return null;
}

/* Training meta for the composer + the values the letter should carry. */
function endoCourseTraining($conn, $course, $month = null) {
    $year  = (int)date('Y');
    $month = $month ?: (int)date('n');
    $co    = endoFindCourseOffering($conn, $course);
    $out = [
        'found' => (bool)$co, 'student_course' => $course, 'course' => $co ? $co['course'] : $course,
        'total_hours' => $co ? endoFormatHours($co['total_hours']) : '',
        'daily_hours' => $co ? endoFormatHours($co['daily_hours']) : '',
        'est_days' => $co ? endoEstDutyDays($co['total_hours'], $co['daily_hours']) : 0,
        'year' => $year, 'month' => $month,
        'start_label' => date('M', mktime(0, 0, 0, $month, 1)) . ' ' . $year, // UPDATED: abbreviated month
        'end_label' => '', 'start_iso' => '', 'end_iso' => '',
    ];
    if ($co && $out['est_days'] > 0) {
        $s = endoTrainingSchedule($month, $year, $out['est_days']);
        $out = array_merge($out, $s);
    }
    return $out;
}

/* ================= UPDATED (atomic names): First / Middle / Last parts =================
   The composer now edits every person name as separate First / Middle / Last
   inputs. These are the parts behind the default names (company contact →
   recipient & salutation, the student, the administrator → OJT Adviser).
   The letter itself still receives the combined "First Middle Last" string. */
function endoNamePart($first, $middle, $last) {
    return ['first' => trim((string)$first), 'middle' => trim((string)$middle), 'last' => trim((string)$last)];
}

function endoNameParts($conn, $student_id, $company_id, $adminRow, $adminFullName) {
    $p = [
        'recipient_name'  => endoNamePart('', '', ''),
        'salutation_name' => endoNamePart('', '', ''),
        'students'        => [],
        'adviser_name'    => endoNamePart('', '', ''),
    ];
    try {
        $q = $conn->prepare("SELECT first_name, middle_name, last_name FROM users WHERE id = ?");
        $q->bind_param("i", $student_id);
        $q->execute();
        $u = $q->get_result()->fetch_assoc();
        $q->close();
        if ($u) $p['students'][] = endoNamePart($u['first_name'] ?? '', $u['middle_name'] ?? '', $u['last_name'] ?? '');
    } catch (\Throwable $e) {}
    try {
        $q = $conn->prepare("SELECT * FROM company_information WHERE user_id = ?");
        $q->bind_param("i", $company_id);
        $q->execute();
        $c = $q->get_result()->fetch_assoc();
        $q->close();
        if ($c) {
            // Same middle-initial rule as buildEndorsementDefaults(), so the parts join to the same name.
            $mi = trim((string)($c['contact_middle_initial'] ?? ''));
            if ($mi !== '' && strlen($mi) <= 2 && substr($mi, -1) !== '.') $mi .= '.';
            $p['recipient_name']  = endoNamePart($c['contact_first_name'] ?? '', $mi, $c['contact_last_name'] ?? '');
            $p['salutation_name'] = $p['recipient_name'];
        }
    } catch (\Throwable $e) {}
    if (is_array($adminRow) && trim(($adminRow['first_name'] ?? '') . ($adminRow['last_name'] ?? '')) !== '') {
        $p['adviser_name'] = endoNamePart($adminRow['first_name'] ?? '', $adminRow['middle_name'] ?? '', $adminRow['last_name'] ?? '');
    } else {
        // Fallback name (session / "Administrator"): first token = first, last token = last.
        $t = preg_split('/\s+/', trim((string)$adminFullName), -1, PREG_SPLIT_NO_EMPTY);
        if (count($t) === 1)    $p['adviser_name'] = endoNamePart($t[0], '', '');
        elseif (count($t) > 1)  $p['adviser_name'] = endoNamePart($t[0], implode(' ', array_slice($t, 1, -1)), end($t));
    }
    return $p;
}

/* ================= UPDATED (department auto-fill ← AccomForm.php) =================
   The letter's "Department / College" (printed in the header above
   "ENDORSEMENT LETTER") comes from the College the student entered on the
   Requirements page (AccomForm.php → student_information.college).
   AccomForm.php owns that column (ensureCollegeColumn()), so it is only READ
   here — and only if it exists, so an older database never errors. */
function endoStudentCollege($conn, $student_id) {
    try {
        $chk = $conn->query("SHOW COLUMNS FROM student_information LIKE 'college'");
        if (!$chk || $chk->num_rows === 0) return '';
        $q = $conn->prepare("SELECT college FROM student_information WHERE user_id = ?");
        $q->bind_param("i", $student_id);
        $q->execute();
        $r = $q->get_result()->fetch_assoc();
        $q->close();
        return trim((string)($r['college'] ?? ''));
    } catch (\Throwable $e) { return ''; }
}

function buildEndorsementDefaults($conn, $student_id, $company_id, $adviserName = '') {
    $d = [
        'department_name' => '', 'letter_date' => date('F j, Y'), 'recipient_name' => '',
        'recipient_position' => '', 'company_name' => '', 'recipient_address' => '',
        'salutation_name' => '', 'program' => '', 'required_hours' => '', 'start_date' => '',
        'end_date' => '', 'students' => '', 'adviser_name' => '', 'dean_name' => '',
        'dean_title' => 'Dean', 'director_name' => '',
    ];
    // UPDATED: OJT Adviser = the administrator issuing the letter.
    $d['adviser_name'] = trim((string)$adviserName);
    // UPDATED: Department / College = the College the student entered on AccomForm.php.
    $d['department_name'] = endoStudentCollege($conn, $student_id);
    try {
        $q = $conn->prepare("SELECT first_name, middle_name, last_name, course FROM users WHERE id = ?");
        $q->bind_param("i", $student_id);
        $q->execute();
        $u = $q->get_result()->fetch_assoc();
        $q->close();
        if ($u) {
            $d['students'] = trim(($u['first_name'] ?? '') . ' ' . (!empty($u['middle_name']) ? $u['middle_name'] . ' ' : '') . ($u['last_name'] ?? ''));
            $d['program']  = $u['course'] ?? '';
        }
    } catch (\Throwable $e) {}
    try {
        // SELECT * so a missing optional column (e.g. company_address) can never error out.
        $q = $conn->prepare("SELECT * FROM company_information WHERE user_id = ?");
        $q->bind_param("i", $company_id);
        $q->execute();
        $c = $q->get_result()->fetch_assoc();
        $q->close();
        if ($c) {
            $mi = trim((string)($c['contact_middle_initial'] ?? ''));
            if ($mi !== '' && strlen($mi) <= 2 && substr($mi, -1) !== '.') $mi .= '.';
            $name = trim(($c['contact_first_name'] ?? '') . ' ' . ($mi !== '' ? $mi . ' ' : '') . ($c['contact_last_name'] ?? ''));
            $d['recipient_name']     = preg_replace('/\s+/', ' ', $name);
            $d['salutation_name']    = $d['recipient_name'];
            $d['recipient_position'] = $c['position'] ?? '';
            $d['company_name']       = $c['company'] ?? '';
            $d['recipient_address']  = $c['company_address'] ?? ($c['address'] ?? '');
        }
    } catch (\Throwable $e) {}
    // UPDATED: Training section from course_offerings (start = current month of the current year).
    $t = endoCourseTraining($conn, $d['program']);
    $d['program']    = $t['course'] !== '' ? $t['course'] : $d['program'];
    $d['start_date'] = $t['start_label'];
    if ($t['found']) {
        $d['required_hours'] = $t['total_hours'];
        $d['end_date']       = $t['end_label'];
    }
    return $d;
}

/* ================= UPDATED (saved signatories): Dean & OJT-CDC Director name lists =================
   The Dean and OJT-CDC Director names used on endorsement letters are saved here, so the
   composer can offer them as a dropdown next time instead of the admin retyping them.
   A name typed via "Other" is added (or its last-used time refreshed) whenever a letter
   is sent. role = 'dean' | 'director'. */
function ensureEndorsementSignatoriesTable($conn) {
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
    } catch (\Throwable $e) { /* never break the page over this */ }
}
ensureEndorsementSignatoriesTable($conn);

function endoFetchSignatories($conn) {
    $out = ['dean' => [], 'director' => []];
    try {
        $res = $conn->query("SELECT id, role, first_name, middle_name, last_name, full_name, title
                             FROM endorsement_signatories ORDER BY last_used_at DESC, id DESC");
        if ($res) {
            while ($r = $res->fetch_assoc()) {
                if (!isset($out[$r['role']])) continue;
                $out[$r['role']][] = [
                    'id' => (int)$r['id'], 'full_name' => $r['full_name'], 'title' => $r['title'] ?? '',
                    'first' => $r['first_name'], 'middle' => $r['middle_name'], 'last' => $r['last_name'],
                ];
            }
        }
    } catch (\Throwable $e) {}
    return $out;
}

/* Adds a name to the list, or refreshes it (parts, title, last used) if it already exists. */
function endoSaveSignatory($conn, $role, $fullName, $parts, $title = null) {
    $fullName = preg_replace('/\s+/', ' ', trim((string)$fullName));
    if ($fullName === '' || !in_array($role, ['dean', 'director'], true)) return;
    $p = is_array($parts) ? $parts : [];
    $first  = trim((string)($p['first']  ?? ''));
    $middle = trim((string)($p['middle'] ?? ''));
    $last   = trim((string)($p['last']   ?? ''));
    if ($first === '' && $last === '') { // no parts sent → first word / middle words / last word
        $t = preg_split('/\s+/', $fullName, -1, PREG_SPLIT_NO_EMPTY);
        $first = $t[0] ?? '';
        $last  = count($t) > 1 ? end($t) : '';
        $middle = count($t) > 2 ? implode(' ', array_slice($t, 1, -1)) : '';
    }
    $title = ($title === null || trim((string)$title) === '') ? null : trim((string)$title);
    try {
        $st = $conn->prepare("INSERT INTO endorsement_signatories (role, first_name, middle_name, last_name, full_name, title, last_used_at)
                              VALUES (?, ?, ?, ?, ?, ?, NOW())
                              ON DUPLICATE KEY UPDATE first_name = VALUES(first_name), middle_name = VALUES(middle_name),
                                  last_name = VALUES(last_name), title = COALESCE(VALUES(title), title), last_used_at = NOW()");
        $st->bind_param("ssssss", $role, $first, $middle, $last, $fullName, $title);
        $st->execute();
        $st->close();
    } catch (\Throwable $e) { error_log('endoSaveSignatory failed: ' . $e->getMessage()); }
}

/* ================= UPDATED (saved signatories): AJAX — remove a saved name ================= */
if (isset($_POST['ajax_endorsement_signatory_delete'])) {
    header('Content-Type: application/json');
    $sid = intval($_POST['signatory_id'] ?? 0);
    $ok = false;
    try {
        $st = $conn->prepare("DELETE FROM endorsement_signatories WHERE id = ?");
        $st->bind_param("i", $sid);
        $st->execute();
        $ok = $st->affected_rows > 0;
        $st->close();
    } catch (\Throwable $e) {}
    echo json_encode(['success' => $ok, 'signatories' => endoFetchSignatories($conn)]);
    exit;
}

/* ================= NEW (endorsement flow): AJAX — LETTER DEFAULTS FOR THE COMPOSER ================= */
if (isset($_POST['ajax_endorsement_defaults'])) {
    header('Content-Type: application/json');
    $approval_id = intval($_POST['approval_id'] ?? 0);
    $q = $conn->prepare("SELECT student_id, company_id FROM admin_application_approvals WHERE id = ?");
    $q->bind_param("i", $approval_id);
    $q->execute();
    $row = $q->get_result()->fetch_assoc();
    $q->close();
    if (!$row) {
        echo json_encode(['success' => false, 'message' => 'Application not found. It may have been withdrawn.']);
        exit;
    }
    echo json_encode([
        'success'  => true,
        'defaults' => buildEndorsementDefaults($conn, (int)$row['student_id'], (int)$row['company_id'], $adminFullName),
        // UPDATED: course-offering data that drives the Training section in the composer.
        'training' => endoCourseTraining($conn, endoStudentCourse($conn, (int)$row['student_id'])),
        // UPDATED (atomic names): First / Middle / Last parts for the composer's name fields.
        'name_parts' => endoNameParts($conn, (int)$row['student_id'], (int)$row['company_id'], $adminNameRow ?? null, $adminFullName),
        // UPDATED (saved signatories): Dean / OJT-CDC Director dropdown lists, most recently used first.
        'signatories' => endoFetchSignatories($conn),
    ]);
    exit;
}

/* ================= NEW (endorsement flow): AJAX — LIVE LETTER PREVIEW (returns full HTML) ================= */
if (isset($_POST['ajax_endorsement_preview'])) {
    header('Content-Type: text/html; charset=UTF-8');
    $in = json_decode($_POST['letter'] ?? '{}', true);
    if (!is_array($in)) $in = [];
    $clean = [];
    foreach ($ENDORSEMENT_ALLOWED_KEYS as $k) $clean[$k] = trim((string)($in[$k] ?? ''));
    echo buildEndorsementFormHTML($clean);
    exit;
}

/* ================= AJAX: FETCH PENDING APPLICATION REQUESTS ================= */
if (isset($_POST['ajax_fetch_app_requests'])) {
    cv_ph_detect($conn);   // NEW (this adjustment): placement replaced → notification
    cv_sc_reconcile($conn); cv_sc_detect($conn);   // NEW (this adjustment): schedule changed by the supervisor → notification
    cv_sru_detect($conn, $reqLabels);   // NEW (this adjustment): new student submissions
    header('Content-Type: application/json');
    $rows = [];
    $res = $conn->query("
        SELECT aaa.id, aaa.student_id, aaa.company_id, aaa.skill1, aaa.skill2, aaa.skill3, aaa.exp1, aaa.exp2, aaa.submitted_at,
               u.first_name, u.middle_name, u.last_name, u.email, u.course,
               si.student_photo, si.photo_status,
               ci.company AS company_name
        FROM admin_application_approvals aaa
        INNER JOIN users u ON u.id = aaa.student_id
        LEFT JOIN student_information si ON si.user_id = u.id
        LEFT JOIN company_information ci ON ci.user_id = aaa.company_id
        ORDER BY aaa.submitted_at DESC
    ");
    if ($res) {
        while ($r = $res->fetch_assoc()) {
            $reqLabelsLocal = [
                "cert_registration"  => "Certification of Registration",
                "certificate_pdos"   => "Certificate of participation(PDOS)",
                "ojt_sheet"          => "OJT Program and Information Sheet",
                "application_sit"    => "Application for Supervised Industrial Training",
                "waiver_form"        => "Waiver and Permission Form",
                "student_contract"   => "Student/University Contract",
                "psych_result"       => "Psych Test Result",
                "medical_result"     => "Medical Result"
            ];

            $sid        = intval($r['student_id']);
            $company_id = intval($r['company_id']);
            $app_id     = intval($r['id']);

            $snapMap = [];
            $snapRes = $conn->query("
                SELECT requirement_type, file_name, status, remark
                FROM application_requirements
                WHERE application_id = $app_id
                  AND student_id     = $sid
                  AND company_id     = $company_id
            ");
            if ($snapRes) {
                while ($sr = $snapRes->fetch_assoc()) {
                    $snapMap[$sr['requirement_type']] = [
                        'status' => $sr['status'],
                        'remark' => $sr['remark'],
                        'file'   => !empty($sr['file_name']) ? base64_encode($sr['file_name']) : null,
                    ];
                }
            }

            $liveMap = [];
            $liveRes = $conn->query("
                SELECT requirement_type, file_name, status
                FROM requirements
                WHERE user_id = $sid
            ");
            if ($liveRes) {
                while ($lr = $liveRes->fetch_assoc()) {
                    $liveMap[$lr['requirement_type']] = [
                        'status' => $lr['status'],
                        'file'   => !empty($lr['file_name']) ? base64_encode($lr['file_name']) : null,
                    ];
                }
            }

            $reqDocs = [];
            foreach ($reqLabelsLocal as $type => $label) {
                if (isset($snapMap[$type])) {
                    $reqDocs[] = [
                        'type'   => $type,
                        'label'  => $label,
                        'status' => $snapMap[$type]['status'] ?? 'Pending',
                        'file'   => $snapMap[$type]['file'] ?? null,
                        'source' => 'snapshot',
                    ];
                } else {
                    $reqDocs[] = [
                        'type'   => $type,
                        'label'  => $label,
                        'status' => $liveMap[$type]['status'] ?? 'Pending',
                        'file'   => $liveMap[$type]['file'] ?? null,
                        'source' => 'live',
                    ];
                }
            }

            $cntStmt = $conn->prepare("SELECT COUNT(*) AS cnt FROM ojt_assignments WHERE company_id = ?");
            $cntStmt->bind_param("i", $company_id);
            $cntStmt->execute();
            $cntResult = $cntStmt->get_result()->fetch_assoc();
            $cntStmt->close();
            $ojt_count = (int)($cntResult['cnt'] ?? 0);

            /* ── FIX: reconstruct the FULL skill/experience entry lists ──
               company_list.php's Digital Resume lets a student save more
               than 2 skills and more than 1 experience entry. When the
               student applies, it packs any entries beyond skill1/skill2
               into skill3 (joined with a Unit Separator, \x1F) and any
               entries beyond exp1 into exp2 (joined with a Record
               Separator, \x1E) — see company_list.php's $legacy_skill3 /
               $legacy_exp2 construction — before saving them into
               admin_application_approvals.

               Previously this endpoint only forwarded raw skill1/skill2/
               skill3/exp1/exp2 to the browser, and the Full View modal
               treated skill3/exp2 as a single entry each — so a 4th+
               skill or 3rd+ experience entry never showed up (or got
               silently merged into "Skill 3" / "Experience 2"). Splitting
               those packed fields back apart here and sending the full
               ordered lists (`skills` / `experiences`) lets the admin's
               Full View render every entry the student actually saved —
               matching what the student sees in their own Digital Resume. */
            $SKILL_ENTRY_DELIM = "\x1F"; // Unit Separator — matches company_list.php
            $EXP_ENTRY_DELIM   = "\x1E"; // Record Separator — matches company_list.php

            $skillsFull = [];
            if (!empty($r['skill1'])) $skillsFull[] = $r['skill1'];
            if (!empty($r['skill2'])) $skillsFull[] = $r['skill2'];
            if (!empty($r['skill3'])) {
                foreach (explode($SKILL_ENTRY_DELIM, $r['skill3']) as $extraSkill) {
                    if (trim($extraSkill) !== '') $skillsFull[] = $extraSkill;
                }
            }

            $expFull = [];
            if (!empty($r['exp1'])) $expFull[] = $r['exp1'];
            if (!empty($r['exp2'])) {
                foreach (explode($EXP_ENTRY_DELIM, $r['exp2']) as $extraExp) {
                    if (trim($extraExp) !== '') $expFull[] = $extraExp;
                }
            }

            $rows[] = [
                /* ── FIX: cast id/student_id/company_id to int explicitly ──
                   $app_id/$sid/$company_id above are already (int)-cast
                   local variables; reuse them here (instead of the raw
                   $r['id']/$r['student_id']/$r['company_id']) so every
                   response from this endpoint always serializes these as
                   JSON numbers, never numeric strings. The client-side
                   live-diff/undo-guard logic normalizes ids to strings
                   before comparing anyway (see administrator.php's
                   markRecentlyHandled()/diffAndUpdateAppInbox()), but
                   keeping the server output consistently typed removes
                   one more variable from that class of bug. */
                'id'           => $app_id,
                'student_id'   => $sid,
                'company_id'   => $company_id,
                'full_name'    => trim($r['first_name'] . ' ' . ($r['middle_name'] ? $r['middle_name'] . ' ' : '') . $r['last_name']),
                'email'        => $r['email'],
                'course'       => $r['course'] ?? '',
                'company_name' => $r['company_name'] ?? 'Unknown Company',
                'skill1'       => $r['skill1'] ?? '',
                'skill2'       => $r['skill2'] ?? '',
                'skill3'       => $r['skill3'] ?? '',
                'exp1'         => $r['exp1'] ?? '',
                'exp2'         => $r['exp2'] ?? '',
                'skills'       => $skillsFull,
                'experiences'  => $expFull,
                'photo'        => $r['student_photo'] ? base64_encode($r['student_photo']) : null,
                'photo_status' => $r['photo_status'] ?? 'Pending',
                'submitted_at' => $r['submitted_at'],
                'req_docs'     => $reqDocs,
                'ojt_count'    => $ojt_count,
            ];
        }
    }
    echo json_encode(['success' => true, 'rows' => $rows, 'count' => count($rows) + cv_sru_count($conn)]);   // UPDATED (this adjustment): the Inbox also holds new submissions
    exit;
}

/* ================= AJAX: APPROVE APPLICATION REQUEST ================= */
if (isset($_POST['ajax_approve_app_request'])) {
    header('Content-Type: application/json');
    $approval_id = intval($_POST['approval_id']);

    $q = $conn->prepare("SELECT * FROM admin_application_approvals WHERE id = ?");
    $q->bind_param("i", $approval_id);
    $q->execute();
    $app = $q->get_result()->fetch_assoc();
    $q->close();

    if (!$app) {
        echo json_encode(['success' => false, 'message' => 'Application not found.']);
        exit;
    }

    /* FIX (application not reaching the company): look at the phase of any existing row too.
       Previously ANY leftover row for this student + company (e.g. 'accepted', 'rejected' or
       NULL from an earlier attempt) made the approval skip the copy, so the company's table
       — which only lists phase = 'pending' — never showed the application, even though the
       endorsement letter was issued and the student could upload it. A pending row is
       preferred if one exists; otherwise the newest leftover row is reused below. */
    $chk = $conn->prepare("SELECT id, phase FROM ojt_applications WHERE student_id = ? AND company_id = ?
                           ORDER BY (COALESCE(phase, '') = 'pending') DESC, id DESC LIMIT 1");
    $chk->bind_param("ii", $app['student_id'], $app['company_id']);
    $chk->execute();
    $existingAppRow = $chk->get_result()->fetch_assoc();
    $alreadyExists = (bool)$existingAppRow;
    $chk->close();
    $existingIsPending = $existingAppRow && strtolower(trim((string)$existingAppRow['phase'])) === 'pending';

    // FIX: remember any database error from copying the application to the company, so a
    // failed copy is reported to the admin instead of silently "succeeding" (see the check below).
    $copyError = '';
    $copyErrno = 0;

    if (!$alreadyExists) {
        /* ── FIX: same truncation issue as admin_application_approvals —
           ojt_applications may still have skill1/skill2/skill3 defined as
           VARCHAR(200) from before this fix. Widen them defensively right
           before copying the (now-complete) skill/experience text into
           this table, so an approved application doesn't lose data again
           downstream. Wrapped in try/catch since this table isn't created
           by this file — if it doesn't exist yet, or is already TEXT,
           this is a harmless no-op. */
        try {
            $conn->query("ALTER TABLE ojt_applications MODIFY COLUMN skill1 TEXT");
            $conn->query("ALTER TABLE ojt_applications MODIFY COLUMN skill2 TEXT");
            $conn->query("ALTER TABLE ojt_applications MODIFY COLUMN skill3 TEXT");
        } catch (\Throwable $e) {
            // Table/columns not in the expected shape yet — safe to ignore here.
        }

        // FIX: same INSERT as before, but its outcome is now captured (works whether
        // mysqli error reporting is switched on — exceptions — or off — return values).
        try {
            $ins = $conn->prepare("INSERT INTO ojt_applications (student_id, company_id, phase, skill1, skill2, skill3, exp1, exp2) VALUES (?, ?, 'pending', ?, ?, ?, ?, ?)");
            if ($ins) {
                $ins->bind_param("iisssss", $app['student_id'], $app['company_id'], $app['skill1'], $app['skill2'], $app['skill3'], $app['exp1'], $app['exp2']);
                if (!$ins->execute()) { $copyError = $ins->error; $copyErrno = (int)$ins->errno; }
                $ins->close();
            } else {
                $copyError = $conn->error; $copyErrno = (int)$conn->errno;
            }
        } catch (\Throwable $e) {
            $copyError = $e->getMessage(); $copyErrno = (int)$e->getCode();
        }

        /* FIX: the database refused a duplicate (errno 1062) — typically a "one application
           per student" unique key while the student still has a FINISHED (non-pending) row
           for another company. Reuse that dead row for this application. A live pending
           application to another company is never touched (the check below reports it). */
        if ($copyErrno === 1062) {
            try {
                $old = $conn->prepare("SELECT id FROM ojt_applications WHERE student_id = ? AND COALESCE(phase, '') <> 'pending' ORDER BY id DESC LIMIT 1");
                $old->bind_param("i", $app['student_id']);
                $old->execute();
                $oldRow = $old->get_result()->fetch_assoc();
                $old->close();
                if ($oldRow) {
                    $oldId = (int)$oldRow['id'];
                    $re = $conn->prepare("UPDATE ojt_applications SET company_id = ?, phase = 'pending', skill1 = ?, skill2 = ?, skill3 = ?, exp1 = ?, exp2 = ? WHERE id = ?");
                    $re->bind_param("isssssi", $app['company_id'], $app['skill1'], $app['skill2'], $app['skill3'], $app['exp1'], $app['exp2'], $oldId);
                    if ($re->execute()) { $copyError = ''; $copyErrno = 0; } else { $copyError = $re->error; }
                    $re->close();
                    try { $conn->query("UPDATE ojt_applications SET created_at = NOW() WHERE id = " . $oldId); } catch (\Throwable $e) {}
                }
            } catch (\Throwable $e) {
                $copyError = $e->getMessage();
            }
        }
    } elseif (!$existingIsPending) {
        /* FIX: a leftover, non-pending row exists → reuse it as this new pending application
           (fresh skills / experience), so it appears in the company's applicants table. */
        try {
            $conn->query("ALTER TABLE ojt_applications MODIFY COLUMN skill1 TEXT");
            $conn->query("ALTER TABLE ojt_applications MODIFY COLUMN skill2 TEXT");
            $conn->query("ALTER TABLE ojt_applications MODIFY COLUMN skill3 TEXT");
        } catch (\Throwable $e) {}
        $existingAppId = (int)$existingAppRow['id'];
        try {
            $upd = $conn->prepare("UPDATE ojt_applications SET phase = 'pending', skill1 = ?, skill2 = ?, skill3 = ?, exp1 = ?, exp2 = ? WHERE id = ?");
            if ($upd) {
                $upd->bind_param("sssssi", $app['skill1'], $app['skill2'], $app['skill3'], $app['exp1'], $app['exp2'], $existingAppId);
                if (!$upd->execute()) $copyError = $upd->error;
                $upd->close();
            } else {
                $copyError = $conn->error;
            }
        } catch (\Throwable $e) {
            $copyError = $e->getMessage();
        }
        // Show it as a new application ("Applied …") — only if the table has created_at.
        try { $conn->query("UPDATE ojt_applications SET created_at = NOW() WHERE id = " . $existingAppId); } catch (\Throwable $e) {}
    }

    /* FIX (application not reaching the company): VERIFY the company can now see it.
       The company's applicants table lists ojt_applications rows with phase = 'pending'
       for its company_id. If no such row exists, stop HERE — before the letter is issued
       and before the request leaves the admin's queue — and report the real reason, so
       the admin sees the failure (with Try Again) instead of a false "approved". */
    $verifyRow = null;
    try {
        $vq = $conn->prepare("SELECT id FROM ojt_applications WHERE student_id = ? AND company_id = ? AND phase = 'pending' LIMIT 1");
        if ($vq) {
            $vq->bind_param("ii", $app['student_id'], $app['company_id']);
            $vq->execute();
            $verifyRow = $vq->get_result()->fetch_assoc();
            $vq->close();
        } elseif ($copyError === '') {
            $copyError = $conn->error;
        }
    } catch (\Throwable $e) {
        if ($copyError === '') $copyError = $e->getMessage();
    }
    if (!$verifyRow) {
        error_log('Approve: application ' . $approval_id . ' was not copied to ojt_applications: ' . $copyError);
        echo json_encode([
            'success' => false,
            'message' => 'The application could not be passed to the company, so nothing was sent to the student '
                       . 'and the request is still in your queue. Database error: '
                       . ($copyError !== '' ? $copyError : 'no pending application row was created in ojt_applications.'),
        ]);
        exit;
    }

    /* ── NEW (endorsement flow): issue the Endorsement Letter to the student ──
       The letter values come from the admin's composer (posted as JSON in
       `endorsement`); anything missing falls back to buildEndorsementDefaults()
       so an approval can never go out without a letter. Re-issuing (e.g. the
       student re-applied to the same company) resets the upload/validation. */
    $endoIn = json_decode($_POST['endorsement'] ?? '{}', true);
    if (!is_array($endoIn)) $endoIn = [];
    $endoData = buildEndorsementDefaults($conn, (int)$app['student_id'], (int)$app['company_id'], $adminFullName);
    $endoAutoFilled = $endoData; // UPDATED: auto-filled names are locked in the composer
    foreach ($ENDORSEMENT_ALLOWED_KEYS as $k) {
        if (array_key_exists($k, $endoIn)) $endoData[$k] = trim((string)$endoIn[$k]);
    }
    /* UPDATED: names the system auto-fills (company contact → recipient & salutation, the
       student) are locked in the composer, so keep the auto-filled value whenever one exists.
       If a name could not be auto-filled, the admin typed it (First / Middle / Last) — use that. */
    foreach (['recipient_name', 'salutation_name', 'students'] as $k) {
        if (trim((string)($endoAutoFilled[$k] ?? '')) !== '') $endoData[$k] = $endoAutoFilled[$k];
    }
    // UPDATED: the Addressee (Company) section is locked too — keep the auto-filled company details.
    foreach (['recipient_position', 'company_name', 'recipient_address'] as $k) {
        if (trim((string)($endoAutoFilled[$k] ?? '')) !== '') $endoData[$k] = $endoAutoFilled[$k];
    }
    // UPDATED: Department / College comes from the student's AccomForm.php College — locked when set.
    if (trim((string)($endoAutoFilled['department_name'] ?? '')) !== '') $endoData['department_name'] = $endoAutoFilled['department_name'];
    // UPDATED (saved signatories): save / refresh the Dean and OJT-CDC Director for next time.
    $endoSigParts = (isset($endoIn['_signatory_parts']) && is_array($endoIn['_signatory_parts'])) ? $endoIn['_signatory_parts'] : [];
    endoSaveSignatory($conn, 'dean', $endoData['dean_name'] ?? '', $endoSigParts['dean_name'] ?? null, $endoData['dean_title'] ?? null);
    endoSaveSignatory($conn, 'director', $endoData['director_name'] ?? '', $endoSigParts['director_name'] ?? null);
    /* UPDATED: enforce the course-offering Training section and the admin as OJT Adviser
       server-side, so the stored letter always matches course_offering.php regardless of
       what the browser sent. Only the start MONTH is taken from the composer; the year is
       always the current year and the end is recomputed from Est. Duty Days. */
    $endoData['adviser_name'] = $adminFullName;
    $endoTraining = endoCourseTraining(
        $conn,
        endoStudentCourse($conn, (int)$app['student_id']),
        endoMonthFromLabel($endoData['start_date'] ?? '')
    );
    $endoData['start_date'] = $endoTraining['start_label'];
    if ($endoTraining['found']) {
        $endoData['program']        = $endoTraining['course'];
        $endoData['required_hours'] = $endoTraining['total_hours'];
        $endoData['end_date']       = $endoTraining['end_label'];
    }
    $endoJson = json_encode($endoData, JSON_UNESCAPED_UNICODE);
    $endoSent = false;
    try {
        $endoIns = $conn->prepare("
            INSERT INTO endorsement_letters
                (student_id, company_id, letter_data, sent_at, student_viewed,
                 uploaded_file, uploaded_mime, uploaded_name, uploaded_at,
                 validation_status, validation_remark, validated_at)
            VALUES (?, ?, ?, NOW(), 0, NULL, NULL, NULL, NULL, 'Awaiting Upload', NULL, NULL)
            ON DUPLICATE KEY UPDATE
                letter_data = VALUES(letter_data), sent_at = NOW(), student_viewed = 0,
                uploaded_file = NULL, uploaded_mime = NULL, uploaded_name = NULL, uploaded_at = NULL,
                validation_status = 'Awaiting Upload', validation_remark = NULL, validated_at = NULL
        ");
        $endoIns->bind_param("iis", $app['student_id'], $app['company_id'], $endoJson);
        $endoSent = $endoIns->execute();
        $endoIns->close();
    } catch (\Throwable $e) {
        error_log('Endorsement letter insert failed: ' . $e->getMessage());
    }

    $del = $conn->prepare("DELETE FROM admin_application_approvals WHERE id = ?");
    $del->bind_param("i", $approval_id);
    $del->execute();
    $del->close();

    $remaining = $conn->query("SELECT (SELECT COUNT(*) FROM admin_application_approvals aaa_c INNER JOIN users aaa_u ON aaa_u.id = aaa_c.student_id) + (SELECT COUNT(*) FROM student_requirement_upload_notifications srun INNER JOIN users srun_u ON srun_u.id = srun.user_id WHERE srun.admin_viewed = 0 AND COALESCE(srun_u.is_archived, 0) = 0) as total")->fetch_assoc()['total'] ?? 0;

    $emailQ = $conn->prepare("SELECT u.first_name, u.last_name, u.email, ci.company FROM users u LEFT JOIN company_information ci ON ci.user_id = ? WHERE u.id = ?");
    $emailQ->bind_param("ii", $app['company_id'], $app['student_id']);
    $emailQ->execute();
    $emailData = $emailQ->get_result()->fetch_assoc();
    $emailQ->close();
    if (!empty($emailData['email'])) {
        $studentName = trim($emailData['first_name'] . ' ' . $emailData['last_name']);
        $companyName = $emailData['company'] ?? 'the company';
        sendApplicationResultEmail($emailData['email'], $studentName, $companyName, 'approved');
    }

    echo json_encode(['success' => true, 'remaining' => (int)$remaining, 'endorsement_sent' => (bool)$endoSent]);
    exit;
}

/* ================= AJAX: DENY APPLICATION REQUEST ================= */
if (isset($_POST['ajax_deny_app_request'])) {
    header('Content-Type: application/json');
    $approval_id = intval($_POST['approval_id']);

    $preQ = $conn->prepare("SELECT aaa.student_id, aaa.company_id, u.first_name, u.last_name, u.email, ci.company FROM admin_application_approvals aaa JOIN users u ON u.id = aaa.student_id LEFT JOIN company_information ci ON ci.user_id = aaa.company_id WHERE aaa.id = ?");
    $preQ->bind_param("i", $approval_id);
    $preQ->execute();
    $preData = $preQ->get_result()->fetch_assoc();
    $preQ->close();

    $del = $conn->prepare("DELETE FROM admin_application_approvals WHERE id = ?");
    $del->bind_param("i", $approval_id);
    $del->execute();
    $del->close();

    $remaining = $conn->query("SELECT (SELECT COUNT(*) FROM admin_application_approvals aaa_c INNER JOIN users aaa_u ON aaa_u.id = aaa_c.student_id) + (SELECT COUNT(*) FROM student_requirement_upload_notifications srun INNER JOIN users srun_u ON srun_u.id = srun.user_id WHERE srun.admin_viewed = 0 AND COALESCE(srun_u.is_archived, 0) = 0) as total")->fetch_assoc()['total'] ?? 0;

    if (!empty($preData['email'])) {
        $studentName = trim($preData['first_name'] . ' ' . $preData['last_name']);
        $companyName = $preData['company'] ?? 'the company';
        sendApplicationResultEmail($preData['email'], $studentName, $companyName, 'denied');
    }

    echo json_encode(['success' => true, 'remaining' => (int)$remaining]);
    exit;
}

/* ================= AJAX: GET PENDING APP REQUEST COUNT ================= */
if (isset($_GET['app_request_count']) && $_GET['app_request_count'] == '1') {
    header('Content-Type: application/json');
    $total = $conn->query("SELECT (SELECT COUNT(*) FROM admin_application_approvals aaa_c INNER JOIN users aaa_u ON aaa_u.id = aaa_c.student_id) + (SELECT COUNT(*) FROM student_requirement_upload_notifications srun INNER JOIN users srun_u ON srun_u.id = srun.user_id WHERE srun.admin_viewed = 0 AND COALESCE(srun_u.is_archived, 0) = 0) as total")->fetch_assoc()['total'] ?? 0;
    echo json_encode(['count' => (int)$total]);
    exit;
}

/* ================= NEW (this adjustment): AJAX: LIGHTWEIGHT APP REQUEST LIST (for the popup) =================
   Same count as ?app_request_count=1 above (that endpoint is kept exactly as
   it was — company_validation.php still polls it for its own sidebar badge),
   plus just enough per-request info (id, student name, company) for the
   popup notification to say WHO applied WHERE. No photos / documents are
   sent, so this stays cheap enough for the background poll. */
if (isset($_GET['app_request_list']) && $_GET['app_request_list'] == '1') {
    header('Content-Type: application/json');
    $list = [];
    $lr = $conn->query("
        SELECT aaa.id, u.first_name, u.middle_name, u.last_name, ci.company AS company_name
        FROM admin_application_approvals aaa
        INNER JOIN users u ON u.id = aaa.student_id   /* FIX (audit): only requests whose student still exists — same as the inbox */
        LEFT JOIN company_information ci ON ci.user_id = aaa.company_id
        ORDER BY aaa.submitted_at ASC, aaa.id ASC
    ");
    if ($lr) {
        while ($l = $lr->fetch_assoc()) {
            $list[] = [
                'id'           => (int)$l['id'],
                'full_name'    => trim(($l['first_name'] ?? '') . ' ' . (!empty($l['middle_name']) ? $l['middle_name'] . ' ' : '') . ($l['last_name'] ?? '')),
                'company_name' => $l['company_name'] ?? 'Unknown Company',
            ];
        }
    }
    $total = $conn->query("SELECT (SELECT COUNT(*) FROM admin_application_approvals aaa_c INNER JOIN users aaa_u ON aaa_u.id = aaa_c.student_id) + (SELECT COUNT(*) FROM student_requirement_upload_notifications srun INNER JOIN users srun_u ON srun_u.id = srun.user_id WHERE srun.admin_viewed = 0 AND COALESCE(srun_u.is_archived, 0) = 0) as total")->fetch_assoc()['total'] ?? 0;
    echo json_encode(['count' => (int)$total, 'rows' => $list]);
    exit;
}

/* ================= NEW (this adjustment): AJAX: COMPANY REQUIREMENTS SIDE-MENU COUNT =================
   Keeps the "Company Requirements" side-menu indicator live on this page.
   Uses adminCompanyNotificationCount() (see top of file) — the same rule
   company_validation.php's own #sidebarMoaBadge uses — rather than
   company_validation.php?moa_pending_count=1, which counts
   moa_requests.status='Pending' and therefore does not match what the
   Company Requirements page's Notification Inbox actually holds. */
if (isset($_GET['moa_notif_count']) && $_GET['moa_notif_count'] == '1') {
    header('Content-Type: application/json');
    echo json_encode(['count' => adminCompanyNotificationCount($conn)]);
    exit;
}
$conn->query("ALTER TABLE users ADD COLUMN IF NOT EXISTS validation_status VARCHAR(20) DEFAULT 'Pending'");
$conn->query("ALTER TABLE users ADD COLUMN IF NOT EXISTS is_archived TINYINT(1) NOT NULL DEFAULT 0");
$conn->query("UPDATE users SET is_archived = 0 WHERE is_archived IS NULL");
cv_ph_reconcile($conn);   // NEW (this adjustment): students who replaced their preferred placement are validated again
cv_sc_reconcile($conn);   // NEW (this adjustment): …and students whose schedule was changed by their supervisor

$students = $conn->query("
    SELECT u.id, u.first_name, u.middle_name, u.last_name, u.course, u.deploy_status, u.validation_status,
           si.student_photo, si.photo_status, si.photo_remark,
           ci.company, ci.telephone, ci.contact_first_name, ci.contact_middle_initial, ci.contact_last_name
    FROM users u
    LEFT JOIN student_information si ON u.id = si.user_id
    LEFT JOIN ojt_assignments oa ON u.id = oa.student_id
    LEFT JOIN company_information ci ON oa.company_id = ci.user_id
    WHERE u.role = 'student' AND u.is_archived = 0
    ORDER BY u.last_name ASC
");

$totalStudents = $students->num_rows;

// ── Separate students into two groups for the split-table display ──
$pendingStudents  = [];
$verifiedStudents = [];
$students->data_seek(0);
while ($s = $students->fetch_assoc()) {
    if (($s['validation_status'] ?? 'Pending') === 'Verified') {
        $verifiedStudents[] = $s;
    } else {
        $pendingStudents[]  = $s;
    }
}
$students->data_seek(0);

/* ================= NEW (this adjustment): COURSE FILTER SYNCED WITH COURSE OFFERING =================
   The "All Courses" filter used to be a hard-coded list (BSIT / BSE /
   BSBA), so any course added, renamed or deleted in course_offering.php
   never showed up here. The options are now read from the same
   course_offerings table that course_offering.php manages, in the same
   order it lists them (course ASC), so the two pages always match.
   If the table does not exist yet (course_offering.php never opened),
   the original three courses are used as a fallback so the filter
   keeps working exactly as before. */
$courseFilterOptions = [];
$courseOfferingsLoaded = false;
try {
    $coFilterRes = $conn->query("SELECT course FROM course_offerings ORDER BY course ASC");
    if ($coFilterRes) {
        $courseOfferingsLoaded = true;
        $coSeen = [];
        while ($coRow = $coFilterRes->fetch_assoc()) {
            $coName = preg_replace('/\s+/', ' ', trim((string)($coRow['course'] ?? '')));
            if ($coName === '') continue;
            $coKey = function_exists('mb_strtolower') ? mb_strtolower($coName) : strtolower($coName);
            if (isset($coSeen[$coKey])) continue;
            $coSeen[$coKey] = true;
            $courseFilterOptions[] = $coName;
        }
    }
} catch (\Throwable $e) {
    $courseOfferingsLoaded = false;
}
if (!$courseOfferingsLoaded) {
    $courseFilterOptions = [
        'Bachelor of Science in Information Technology',
        'Bachelor of Science in Business Administration major in Entrepreneurship',
        'Bachelor of Science in Business Administration',
    ];
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title><?= $pageTitle ?></title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        :root {
            --neust-maroon: #07145fe5;
            --neust-gold: #FFD700;
            --bg: #fcfaf7;
            --text: #2d1b1b;
            --white: #ffffff;
            --sidebar-active: #1a237e;
        }

        body { font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif; background: var(--bg); margin: 0; display: flex; color: var(--text); min-height: 100vh; }

        .sidebar { width: 260px; background: var(--neust-maroon); height: 100vh; position: fixed; display: flex; flex-direction: column; transition: all 0.3s ease; z-index: 1000; box-shadow: 4px 0 10px rgba(0,0,0,0.1); }
        .sidebar.collapsed { width: 80px; }
        .sidebar-header { padding: 20px; display: flex; align-items: center; justify-content: space-between; border-bottom: 1px solid rgba(255,255,255,0.1); }
        .sidebar-header h2 { color: var(--neust-gold); margin: 0; font-size: 20px; font-weight: bold; white-space: nowrap; overflow: hidden; transition: 0.3s; }
        .sidebar.collapsed .sidebar-header h2 { opacity: 0; width: 0; }
        .sidebar-links { flex: 1; display: flex; flex-direction: column; padding: 10px 0; }
        .sidebar a { padding: 15px 25px; color: #cbd5e0; text-decoration: none; font-size: 14px; display: flex; align-items: center; transition: 0.2s; white-space: nowrap; }
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

        /* ── Ungraded sidebar badge ── */
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

        .main-content { margin-left: 260px; width: calc(100% - 260px); transition: 0.3s; min-height: 100vh; }
        .sidebar.collapsed + .main-content { margin-left: 80px; width: calc(100% - 80px); }
        .navbar { background: var(--neust-maroon); padding: 10px 30px; display: flex; justify-content: space-between; align-items: center; color: white; height: 60px; }
        .logo-section { display: flex; align-items: center; gap: 12px; }
        .university-logo { height: 40px; }

        .container { padding: 30px; max-width: 1200px; margin: 0 auto; }
        .filter-nav { display: flex; gap: 10px; margin-bottom: 25px; background: white; padding: 15px; border-radius: 8px; box-shadow: 0 2px 10px rgba(0,0,0,0.05); }
        .search-bar, .filter-item { padding: 10px; border: 1px solid #ddd; border-radius: 4px; outline: none; }
        .search-bar { flex: 1; }

        /* ── ARCHIVE BUTTON ── */
        .archive-btn {
            display: inline-flex; align-items: center; gap: 8px;
            background: #7c3aed; color: white; border: none;
            padding: 10px 18px; border-radius: 8px; font-size: 13px;
            font-weight: 700; cursor: pointer; transition: opacity 0.2s;
            white-space: nowrap;
        }
        .archive-btn:hover { opacity: 0.85; }
        .archive-btn:disabled { opacity: 0.4; cursor: not-allowed; }

        /* ── SECTION HEADER (Pending / Verified tables) ── */
        .section-header {
            display: flex; align-items: center; gap: 12px;
            margin: 28px 0 0;
            padding: 14px 20px;
            border-radius: 10px 10px 0 0;
        }
        .section-header.pending-header {
            background: linear-gradient(90deg, #fffbeb 0%, #fef3c7 100%);
            border: 1.5px solid #fde68a;
            border-bottom: none;
        }
        .section-header.verified-header {
            background: linear-gradient(90deg, #f0fdf4 0%, #dcfce7 100%);
            border: 1.5px solid #bbf7d0;
            border-bottom: none;
        }
        .section-header .sh-icon {
            font-size: 20px; flex-shrink: 0;
        }
        .section-header .sh-title {
            font-size: 15px; font-weight: 700; flex: 1;
        }
        .section-header.pending-header  .sh-title { color: #92400e; }
        .section-header.verified-header .sh-title { color: #166534; }
        .section-header .sh-count {
            font-size: 12px; font-weight: 700;
            padding: 3px 12px; border-radius: 20px;
        }
        .section-header.pending-header  .sh-count { background: #fde68a; color: #92400e; }
        .section-header.verified-header .sh-count { background: #bbf7d0; color: #166534; }

       /* ── STUDENT TABLE HEADER ── */
        .student-table-header {
            display: grid;
            grid-template-columns: 1.5fr 1.5fr 230px; /* UPDATED (this adjustment): Internship Status column removed; Requirement Status widened for the ring */
            padding: 10px 25px;
            background: var(--neust-maroon);
            margin-bottom: 0;
            font-size: 12px;
            font-weight: 700;
            color: var(--neust-gold);
            letter-spacing: 0.5px;
            text-transform: uppercase;
        }
        .student-table-header.pending-table-header {
            border-left: 1.5px solid #fde68a;
            border-right: 1.5px solid #fde68a;
        }
        .student-table-header.verified-table-header {
            border-left: 1.5px solid #bbf7d0;
            border-right: 1.5px solid #bbf7d0;
        }
        .student-table-header span:nth-child(3),
        .student-table-header span:nth-child(4) {
            text-align: center;
        }

        .student-row { background: white; border-radius: 0; margin-bottom: 0; box-shadow: none; border: none; border-bottom: 1px solid #eee; }
        .student-row:last-of-type { border-bottom: none; }

        /* ── PENDING TABLE WRAPPER ── */
        .student-list-wrapper {
            border-radius: 0 0 8px 8px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.06);
            overflow: hidden;
            margin-bottom: 8px;
        }
        .student-list-wrapper.pending-wrapper {
            border: 1.5px solid #fde68a;
            border-top: none;
        }
        .student-list-wrapper.verified-wrapper {
            border: 1.5px solid #bbf7d0;
            border-top: none;
        }

        .row-summary { padding: 18px 25px; display: grid; grid-template-columns: 1.5fr 1.5fr 230px; cursor: pointer; align-items: center; font-size: 14px; }
        .row-summary span:first-child { font-weight: 600; color: #1a202c; }
        .row-summary span:nth-child(3),
        .row-summary span:nth-child(4) { text-align: center; }
        .row-summary:hover { background: #f8f7ff; }
        .details-pane { display: none; padding: 25px; border-top: 1px solid #f0f0f0; background: #fafafa; }
        .toggle-input:checked ~ .details-pane { display: block; }
        .detail-grid { display: grid; grid-template-columns: 1fr 300px; gap: 30px; }
        h4 { margin-top: 0; color: var(--neust-maroon); border-bottom: 2px solid var(--neust-gold); padding-bottom: 8px; font-size: 16px; }
        .req-item { display: flex; gap: 15px; background: white; padding: 12px; border-radius: 6px; margin-bottom: 10px; border: 1px solid #eef0f2; align-items: center; }
        .req-item img { width: 50px; height: 50px; border-radius: 4px; object-fit: cover; border: 1px solid #ddd; }
        .update-form select, .update-form button { padding: 6px 10px; border-radius: 4px; border: 1px solid #ccc; font-size: 13px; }
        .update-form button { background: var(--neust-maroon); color: white; border: none; cursor: pointer; font-weight: 500; }
        .update-form button.saving { opacity: 0.6; cursor: not-allowed; }
        .update-form button.saved  { background: #16a34a; }
        .profile-card { text-align: center; background: white; padding: 20px; border-radius: 8px; border: 1px solid #eee; }
        .profile-img-large { width: 120px; height: 120px; border-radius: 8px; object-fit: cover; margin-bottom: 15px; border: 3px solid var(--neust-gold); }

        /* ── EMPTY SECTION STATE ── */
        .section-empty {
            text-align: center; padding: 32px 20px; color: #a0aec0;
            background: white;
        }
        .section-empty i { font-size: 32px; margin-bottom: 10px; display: block; }
        .section-empty p { margin: 0; font-size: 13px; }

        /* ── EMPTY STATE (no students at all) ── */
        .empty-state {
            text-align: center; padding: 80px 20px; color: #a0aec0;
        }
        .empty-state i { font-size: 56px; margin-bottom: 16px; display: block; color: #cbd5e0; }
        .empty-state h3 { margin: 0 0 8px; font-size: 20px; color: #718096; }
        .empty-state p { margin: 0; font-size: 14px; }

        /* ── GUARD STYLES ── */
        .guard-banner { display: flex; align-items: center; gap: 14px; padding: 14px 20px; border-radius: 10px; margin-bottom: 20px; font-size: 14px; font-weight: 500; animation: slideDown 0.3s ease; }
        .guard-banner.warning { background: #fffbeb; border: 1px solid #fde68a; color: #b45309; }
        .guard-banner i { font-size: 18px; flex-shrink: 0; }
        .deployed-lock { display: flex; align-items: center; gap: 8px; background: #f0fdf4; border: 1px solid #bbf7d0; color: #166534; padding: 8px 12px; border-radius: 6px; font-size: 12px; font-weight: 600; margin-top: 6px; }
        .deployed-lock i { font-size: 13px; }
        .verified-lock { display: flex; align-items: center; gap: 8px; background: #f0f9ff; border: 1px solid #bae6fd; color: #0369a1; padding: 7px 12px; border-radius: 6px; font-size: 12px; font-weight: 600; margin-top: 6px; }
        .verified-lock i { font-size: 13px; }
        @keyframes slideDown { from { opacity:0; transform:translateY(-8px); } to { opacity:1; transform:translateY(0); } }

        /* ── ARCHIVE MODAL ── */
        .arch-modal-overlay { display:none; position:fixed; inset:0; background:rgba(0,0,0,0.55); z-index:9997; justify-content:center; align-items:center; backdrop-filter:blur(4px); }
        .arch-modal-box { background:white; border-radius:18px; padding:36px 32px; width:480px; max-width:92%; box-shadow:0 20px 60px rgba(0,0,0,0.25); animation: popIn 0.3s cubic-bezier(0.34,1.56,0.64,1); }
        @keyframes popIn { from{transform:scale(0.85);opacity:0;} to{transform:scale(1);opacity:1;} }
        .arch-modal-icon { font-size:50px; text-align:center; display:block; margin-bottom:14px; }
        .arch-modal-title { font-size:20px; font-weight:700; color:var(--neust-maroon); text-align:center; margin:0 0 8px; }
        .arch-modal-msg { color:#718096; font-size:13px; text-align:center; margin:0 0 22px; line-height:1.6; }
        .arch-modal-label { display:block; font-size:12px; font-weight:700; color:#4a5568; text-transform:uppercase; letter-spacing:0.5px; margin-bottom:6px; }
        .arch-modal-input { width:100%; padding:11px 14px; border:1px solid #e2e8f0; border-radius:8px; font-size:14px; box-sizing:border-box; margin-bottom:20px; }
        .arch-modal-input:focus { outline:none; border-color:var(--neust-maroon); }
        .arch-modal-actions { display:flex; gap:10px; }
        .arch-modal-cancel { flex:1; padding:12px; background:#f1f5f9; color:#475569; border:none; border-radius:10px; font-weight:700; font-size:14px; cursor:pointer; transition:opacity 0.2s; }
        .arch-modal-cancel:hover { opacity:0.75; }
        .arch-modal-export { flex:1; padding:12px; background:#2e7d32; color:white; border:none; border-radius:10px; font-weight:700; font-size:14px; cursor:pointer; transition:opacity 0.2s; display:flex; align-items:center; justify-content:center; gap:6px; }
        .arch-modal-export:hover { opacity:0.85; }
        .arch-modal-confirm { flex:1; padding:12px; background:#7c3aed; color:white; border:none; border-radius:10px; font-weight:700; font-size:14px; cursor:pointer; transition:opacity 0.2s; display:flex; align-items:center; justify-content:center; gap:6px; }
        .arch-modal-confirm:hover { opacity:0.85; }
        .arch-modal-confirm:disabled, .arch-modal-export:disabled { opacity:0.4; cursor:not-allowed; }
        .arch-progress { text-align:center; padding:16px 0 0; font-size:13px; color:#7c3aed; font-weight:600; display:none; }

        /* ── UNDO TOAST — pinned to the TOP ── */
        #undoToast {
            position: fixed;
            top: 80px;
            left: 50%;
            transform: translateX(-50%) translateY(-120px);
            background: #1e293b; color: white; padding: 16px 22px;
            border-radius: 14px; box-shadow: 0 10px 40px rgba(0,0,0,0.3);
            display: flex; align-items: center; gap: 16px;
            font-size: 14px; z-index: 9999; min-width: 360px; max-width: 520px;
            transition: transform 0.4s cubic-bezier(0.34,1.56,0.64,1), opacity 0.3s;
            opacity: 0;
        }
        #undoToast.show { transform: translateX(-50%) translateY(0); opacity: 1; }
        #undoToast.denied-pending { border: 1px solid #ef4444; }
        #undoToast .toast-label { flex: 1; line-height: 1.4; }
        #undoToast .toast-label strong { display: block; font-size: 13px; color: #94a3b8; font-weight: 500; }
        #undoToast .toast-label strong.denied-mode { color: #fca5a5; }
        #undoToast .toast-label span { font-size: 14px; font-weight: 600; }
        #undoToast .undo-btn { background: var(--neust-gold); color: #1e293b; border: none; padding: 8px 18px; border-radius: 8px; font-weight: 700; font-size: 13px; cursor: pointer; white-space: nowrap; flex-shrink: 0; transition: opacity 0.2s; }
        #undoToast .undo-btn:hover { opacity: 0.85; }
        #undoToast .undo-btn:disabled { opacity: 0.4; cursor: not-allowed; }
        #undoToast .dismiss-btn { background: none; border: none; color: #64748b; cursor: pointer; font-size: 18px; padding: 0 4px; flex-shrink: 0; }
        #undoToast .dismiss-btn:hover { color: white; }
        .countdown-ring { position: relative; width: 36px; height: 36px; flex-shrink: 0; }
        .countdown-ring svg { transform: rotate(-90deg); }
        .countdown-ring circle { fill: none; stroke: #334155; stroke-width: 3; }
        .countdown-ring .progress { stroke: var(--neust-gold); stroke-dasharray: 88; stroke-dashoffset: 0; transition: stroke-dashoffset 1s linear; stroke-linecap: round; }
        .countdown-ring .progress.denied-ring { stroke: #ef4444; }
        .countdown-ring .num { position: absolute; inset: 0; display: flex; align-items: center; justify-content: center; font-size: 11px; font-weight: 700; color: var(--neust-gold); }
        .countdown-ring .num.denied-num { color: #ef4444; }

        /* ── PAGINATION CONTROLS ── */
        .pagination-controls {
            display: flex;
            justify-content: center;
            align-items: center;
            gap: 8px;
            margin-top: 14px;
            margin-bottom: 4px;
            flex-wrap: wrap;
            min-height: 40px;
        }
        .pg-btn {
            padding: 7px 16px;
            border: 1px solid #ddd;
            background: white;
            border-radius: 7px;
            font-size: 13px;
            font-weight: 600;
            cursor: pointer;
            color: #07145f;
            transition: all 0.15s;
            user-select: none;
        }
        .pg-btn:hover:not(:disabled) {
            background: #f0f0ff;
            border-color: #07145f;
        }
        .pg-btn:disabled {
            opacity: 0.35;
            cursor: not-allowed;
        }
        .pg-number-btn {
            padding: 6px 12px;
            border-radius: 7px;
            font-size: 13px;
            cursor: pointer;
            border: 1px solid #ddd;
            background: white;
            color: #374151;
            font-weight: 500;
            transition: all 0.15s;
            user-select: none;
        }
        .pg-number-btn:hover {
            background: #f0f0ff;
            border-color: #07145f;
        }
        .pg-number-btn.active {
            background: var(--neust-maroon);
            color: var(--neust-gold);
            border-color: var(--neust-maroon);
            font-weight: 700;
            cursor: default;
        }
        .pg-ellipsis {
            font-size: 13px;
            color: #a0aec0;
            padding: 0 4px;
            user-select: none;
        }
        .pg-info {
            margin-left: 6px;
            font-size: 12px;
            color: #718096;
            white-space: nowrap;
        }

        /* ── ARCHIVE VIEWER FAB ── */
        #archiveFab {
            position: fixed; bottom: 36px; right: 36px; z-index: 1100;
            display: flex; flex-direction: column; align-items: center; gap: 4px;
        }
        #archiveFabBtn {
            width: 58px; height: 58px; border-radius: 50%;
            background: #7c3aed; color: white; border: none;
            font-size: 22px; cursor: pointer;
            box-shadow: 0 8px 24px rgba(124,58,237,0.4);
            transition: transform 0.2s, box-shadow 0.2s;
            display: flex; align-items: center; justify-content: center;
        }
        #archiveFabBtn:hover { transform: translateY(-3px); box-shadow: 0 12px 30px rgba(124,58,237,0.5); }
        #archiveFabLabel {
            background: #1e293b; color: white; font-size: 11px; font-weight: 700;
            padding: 4px 10px; border-radius: 20px; white-space: nowrap;
            opacity: 0.85; letter-spacing: 0.3px;
        }

        /* ── ARCHIVE VIEWER MODAL ── */
        #archiveViewerOverlay {
            display: none; position: fixed; inset: 0;
            background: rgba(0,0,0,0.55); z-index: 9996;
            justify-content: center; align-items: center;
            backdrop-filter: blur(4px);
        }
        #archiveViewerBox {
            background: white; border-radius: 18px;
            width: 900px; max-width: 95vw; max-height: 88vh;
            display: flex; flex-direction: column;
            box-shadow: 0 24px 70px rgba(0,0,0,0.25);
            animation: popIn 0.3s cubic-bezier(0.34,1.56,0.64,1);
        }
        #archiveViewerHeader {
            padding: 22px 28px; border-bottom: 1px solid #f2e9e9;
            display: flex; align-items: center; justify-content: space-between;
            flex-shrink: 0;
        }
        #archiveViewerHeader h3 { margin: 0; color: var(--neust-maroon); font-size: 17px; display: flex; align-items: center; gap: 10px; }
        #archiveViewerClose { background: none; border: none; font-size: 22px; color: #a0aec0; cursor: pointer; line-height: 1; }
        #archiveViewerClose:hover { color: #475569; }

        #archiveViewerControls {
            padding: 14px 28px; border-bottom: 1px solid #f9f2f2;
            display: flex; gap: 10px; align-items: center; flex-shrink: 0; flex-wrap: wrap;
        }
        #archiveBatchFilter {
            padding: 8px 12px; border: 1px solid #ddd; border-radius: 8px;
            font-size: 13px; outline: none; min-width: 220px;
        }
        #archiveSearchInput {
            padding: 8px 12px; border: 1px solid #ddd; border-radius: 8px;
            font-size: 13px; outline: none; flex: 1; min-width: 160px;
        }
        #archiveExportFilteredBtn {
            padding: 8px 16px; background: #2e7d32; color: white; border: none;
            border-radius: 8px; font-size: 13px; font-weight: 700; cursor: pointer;
            display: flex; align-items: center; gap: 6px; white-space: nowrap;
            transition: opacity 0.2s;
        }
        #archiveExportFilteredBtn:hover { opacity: 0.85; }

        #archiveViewerBody { overflow-y: auto; flex: 1; padding: 0 28px 24px; }
        #archiveViewerBody table { width: 100%; border-collapse: collapse; font-size: 13px; }
        #archiveViewerBody th {
            text-align: left; padding: 12px 10px; color: #a18a8a;
            font-size: 0.75rem; text-transform: uppercase; font-weight: 700;
            border-bottom: 2px solid #f2e9e9; position: sticky; top: 0;
            background: white; z-index: 1;
        }
        #archiveViewerBody td { padding: 12px 10px; border-bottom: 1px solid #f9f2f2; color: #2d3748; }
        #archiveViewerBody tr:last-child td { border-bottom: none; }
        #archiveViewerBody tr:hover td { background: #fafaf8; }

        .av-badge {
            display: inline-block; padding: 2px 8px; border-radius: 12px;
            font-size: 11px; font-weight: 700;
        }
        .av-badge.verified { background: #dcfce7; color: #166534; }
        .av-badge.pending  { background: #fef9c3; color: #854d0e; }
        .av-badge.deployed { background: #dbeafe; color: #1d4ed8; }
        .av-badge.waiting  { background: #f3f4f6; color: #374151; }

        #archiveEmpty { text-align: center; padding: 50px 20px; color: #a0aec0; font-size: 14px; }

        /* ── UNARCHIVE BUTTON ── */
        #archiveUnarchiveBtn {
            padding: 8px 16px; background: #0369a1; color: white; border: none;
            border-radius: 8px; font-size: 13px; font-weight: 700; cursor: pointer;
            display: flex; align-items: center; gap: 6px; white-space: nowrap;
            transition: opacity 0.2s;
        }
        #archiveUnarchiveBtn:hover { opacity: 0.85; }
        #archiveUnarchiveBtn:disabled { opacity: 0.4; cursor: not-allowed; }

        /* ── BLOCK NOTIFICATION MODAL ── */
        #blockNotifOverlay {
            display: none; position: fixed; inset: 0;
            background: rgba(0,0,0,0.55); z-index: 10002;
            justify-content: center; align-items: center;
            backdrop-filter: blur(4px);
        }
        #blockNotifBox {
            background: white; border-radius: 16px; padding: 36px 32px;
            width: 420px; max-width: 92%; text-align: center;
            box-shadow: 0 20px 60px rgba(0,0,0,0.25);
            animation: popIn 0.3s cubic-bezier(0.34,1.56,0.64,1);
        }
        #blockNotifBox .bn-icon { font-size: 48px; margin-bottom: 14px; display: block; }
        #blockNotifBox .bn-title { font-size: 18px; font-weight: 700; color: #b45309; margin: 0 0 10px; }
        #blockNotifBox .bn-msg { color: #718096; font-size: 13px; margin: 0 0 24px; line-height: 1.6; }
        #blockNotifBox .bn-ok {
            background: var(--neust-maroon); color: var(--neust-gold);
            border: none; padding: 12px 36px; border-radius: 10px;
            font-weight: 700; font-size: 14px; cursor: pointer;
            transition: opacity 0.2s;
        }
        #blockNotifBox .bn-ok:hover { opacity: 0.88; }

        /* ── UNARCHIVE CONFIRM MODAL ── */
        #unarchiveConfirmOverlay {
            display: none; position: fixed; inset: 0;
            background: rgba(0,0,0,0.55); z-index: 10002;
            justify-content: center; align-items: center;
            backdrop-filter: blur(4px);
        }
        #unarchiveConfirmBox {
            background: white; border-radius: 16px; padding: 36px 32px;
            width: 440px; max-width: 92%; text-align: center;
            box-shadow: 0 20px 60px rgba(0,0,0,0.25);
            animation: popIn 0.3s cubic-bezier(0.34,1.56,0.64,1);
        }
        #unarchiveConfirmBox .uc-icon { font-size: 48px; margin-bottom: 14px; display: block; }
        #unarchiveConfirmBox .uc-title { font-size: 18px; font-weight: 700; color: #0369a1; margin: 0 0 10px; }
        #unarchiveConfirmBox .uc-msg { color: #718096; font-size: 13px; margin: 0 0 24px; line-height: 1.6; }
        #unarchiveConfirmBox .uc-actions { display: flex; gap: 10px; justify-content: center; }
        #unarchiveConfirmBox .uc-cancel { padding: 11px 28px; background: #f1f5f9; color: #475569; border: none; border-radius: 10px; font-weight: 700; font-size: 13px; cursor: pointer; transition: opacity 0.2s; }
        #unarchiveConfirmBox .uc-cancel:hover { opacity: 0.75; }
        #unarchiveConfirmBox .uc-go { padding: 11px 28px; background: #0369a1; color: white; border: none; border-radius: 10px; font-weight: 700; font-size: 13px; cursor: pointer; transition: opacity 0.2s; display: flex; align-items: center; gap: 6px; }
        #unarchiveConfirmBox .uc-go:hover { opacity: 0.85; }

        /* ── IN-PLACE STATUS FLASH ── */
        @keyframes statusFlash {
            0%   { background: #fef9c3; }
            100% { background: transparent; }
        }
        .req-item.just-updated { animation: statusFlash 1.2s ease-out; }

        /* ── APPLICATION REQUEST INBOX ── */
        #appInboxBtn {
            position: relative;
            width: 38px; height: 38px;
            border-radius: 50%;
            background: rgba(255,255,255,0.13);
            border: 1.5px solid rgba(255,255,255,0.25);
            color: white;
            font-size: 16px;
            cursor: pointer;
            display: flex; align-items: center; justify-content: center;
            transition: background 0.2s;
            flex-shrink: 0;
        }
        #appInboxBtn:hover { background: rgba(255,255,255,0.22); }
        #appInboxBadge {
            position: absolute; top: -5px; right: -5px;
            background: #dc2626; color: white;
            border-radius: 50%; width: 18px; height: 18px;
            font-size: 10px; font-weight: 700;
            display: none; align-items: center; justify-content: center;
            border: 2px solid var(--neust-maroon);
            animation: badge-pulse-app 2s ease-in-out infinite;
        }
        @keyframes badge-pulse-app {
            0%,100% { box-shadow: 0 0 0 0 rgba(220,38,38,0.5); }
            50%      { box-shadow: 0 0 0 5px rgba(220,38,38,0); }
        }
        #appRequestOverlay {
            display: none; position: fixed; inset: 0;
            background: rgba(0,0,0,0.5); z-index: 9990;
            justify-content: flex-end; align-items: stretch;
        }
        #appRequestDrawer {
            background: white; width: 500px; max-width: 95vw;
            display: flex; flex-direction: column;
            box-shadow: -8px 0 32px rgba(0,0,0,0.18);
            animation: appDrawerSlideIn 0.3s ease;
        }
        @keyframes appDrawerSlideIn { from{transform:translateX(100%);} to{transform:translateX(0);} }
        #appRequestHeader {
            padding: 16px 20px;
            background: var(--neust-maroon);
            display: flex; align-items: center; justify-content: space-between;
            flex-shrink: 0;
        }
        #appRequestHeader h3 {
            margin: 0; color: var(--neust-gold);
            font-size: 14px; display: flex; align-items: center; gap: 10px;
        }
        #appRequestClose {
            background: none; border: none; color: rgba(255,255,255,0.65);
            font-size: 22px; cursor: pointer; padding: 0; line-height: 1;
        }
        #appRequestClose:hover { color: white; }
        #appRequestBody { overflow-y: auto; flex: 1; padding: 16px 18px; }

        /* ══════════════════════════════════════════════
           APPLICATION SUMMARY CARD (drawer)
           ------------------------------------------------------------
           UPDATED LAYOUT: the card now shows a plain vertical stack —
           Student Name, then Course, then Company Name, then the
           count of students currently registered at that company —
           followed by a single horizontal row of Allow / Deny /
           Full View buttons. The previous photo + inline "company
           chip" header row has been replaced by this simpler,
           label-stacked summary per the requested format. ar-card,
           ar-card-top, ar-photo(-placeholder), ar-info, ar-meta,
           ar-company, ar-skills and ar-actions rules below are left
           in place unused/backward-compatible so nothing else that
           may reference them elsewhere breaks; only the JS markup
           generated for each card (buildArCard) changed to use the
           new ar-summary-* classes + ar-fullview-btn added here.
           ══════════════════════════════════════════════ */
        .ar-card {
            border: 1px solid #e2e8f0; border-radius: 12px;
            padding: 15px; margin-bottom: 13px; background: #fafafa;
            transition: opacity 0.3s;
        }
        .ar-card.removing { opacity: 0; pointer-events: none; }
        .ar-card-top { display: flex; gap: 12px; align-items: center; }
        .ar-photo {
            width: 52px; height: 52px; border-radius: 50%;
            object-fit: cover; border: 2px solid #e2e8f0; flex-shrink: 0;
        }
        .ar-photo-placeholder {
            width: 52px; height: 52px; border-radius: 50%;
            background: #dbeafe; display: flex; align-items: center;
            justify-content: center; font-size: 15px; font-weight: 700;
            color: #1d4ed8; flex-shrink: 0;
        }
        .ar-info { flex: 1; min-width: 0; }
        .ar-name { font-weight: 700; font-size: 14px; color: #1e293b; margin-bottom: 2px; }
        .ar-meta { font-size: 12px; color: #64748b; margin-bottom: 2px; }
        .ar-company {
            display: inline-flex; align-items: center; gap: 5px;
            margin-top: 5px; font-size: 11px; font-weight: 700;
            background: #f3e8ff; color: #7c3aed;
            padding: 3px 9px; border-radius: 12px;
        }
        .ar-skills {
            margin-top: 9px; background: #f1f5f9; border-radius: 7px;
            padding: 8px 11px; font-size: 12px; color: #475569;
        }
        .ar-actions { display: flex; gap: 8px; margin-top: 11px; }
        .ar-allow-btn {
            background: #16a34a; color: white; border: none;
            padding: 7px 18px; border-radius: 7px;
            font-size: 12px; font-weight: 700; cursor: pointer;
            display: flex; align-items: center; gap: 5px;
            transition: opacity 0.2s;
        }
        .ar-allow-btn:hover { opacity: 0.85; }
        .ar-allow-btn:disabled { opacity: 0.4; cursor: not-allowed; }
        .ar-deny-btn {
            background: #fff1f1; color: #dc2626;
            border: 1px solid #fecaca;
            padding: 7px 18px; border-radius: 7px;
            font-size: 12px; font-weight: 700; cursor: pointer;
            display: flex; align-items: center; gap: 5px;
            transition: all 0.2s;
        }
        .ar-deny-btn:hover { background: #dc2626; color: white; }
        .ar-deny-btn:disabled { opacity: 0.4; cursor: not-allowed; }
        .ar-empty {
            text-align: center; color: #a0aec0;
            padding: 50px 20px; font-size: 13px;
        }
        .ar-empty i { font-size: 40px; display: block; margin-bottom: 14px; color: #cbd5e0; }

        /* ── LIVE APPLICATION REQUEST DETECTION — new/removed feedback ──
           .ar-card-new: applied to a card the instant it is detected by
           the live drawer poll (fresh submission), giving it a brief
           slide-in + soft highlight flash so the admin visibly notices
           it appeared while the drawer was already open — instead of it
           silently showing up with no indication anything changed.
           .ar-live-notice: a small transient banner inserted at the top
           of the drawer body whenever the live poll detects a change
           (new submission or student withdrawal), so the update is
           obvious even if the admin isn't looking directly at the list
           when it happens. Auto-dismisses after a few seconds. */
        @keyframes arCardNewIn {
            0%   { opacity: 0; transform: translateY(-8px); }
            100% { opacity: 1; transform: translateY(0); }
        }
        @keyframes arCardNewFlash {
            0%   { background: #ecfdf5; border-color: #6ee7b7; }
            70%  { background: #ecfdf5; border-color: #6ee7b7; }
            100% { background: #fafafa; border-color: #e2e8f0; }
        }
        .ar-card.ar-card-new {
            animation: arCardNewIn 0.35s ease, arCardNewFlash 2.4s ease-out;
        }
        .ar-live-notice {
            display: flex; align-items: center; gap: 8px;
            font-family: 'Segoe UI', sans-serif; font-size: 12px; font-weight: 700;
            padding: 9px 13px; border-radius: 9px; margin-bottom: 10px;
            transition: opacity 0.3s;
        }
        .ar-live-notice-added   { background: #eff6ff; color: #1d4ed8; border: 1px solid #bfdbfe; }
        .ar-live-notice-removed { background: #fff7ed; color: #9a3412; border: 1px solid #fed7aa; }

        /* New summary-stack classes (Name / Course / Company / Count) */
        .ar-summary-name {
            font-family: 'Segoe UI', sans-serif;
            font-weight: 700; font-size: 14px; color: #1e293b;
            margin-bottom: 3px;
        }
        .ar-summary-course {
            font-family: 'Segoe UI', sans-serif;
            font-size: 12px; color: #64748b;
            margin-bottom: 7px;
        }
        .ar-summary-company {
            display: flex; align-items: center; gap: 6px;
            font-family: 'Segoe UI', sans-serif;
            font-size: 12px; font-weight: 700; color: #7c3aed;
            margin-bottom: 4px;
        }
        .ar-summary-count {
            display: flex; align-items: center; gap: 6px;
            font-family: 'Segoe UI', sans-serif;
            font-size: 11.5px; color: #64748b;
            margin-bottom: 2px;
        }
        /* Full View button — third button in the same horizontal row as Allow/Deny */
        .ar-fullview-btn {
            background: #f8f7ff; color: var(--neust-maroon);
            border: 1px solid #c7d2fe;
            padding: 7px 14px; border-radius: 7px;
            font-size: 12px; font-weight: 700; cursor: pointer;
            display: flex; align-items: center; gap: 5px;
            transition: all 0.2s; white-space: nowrap;
        }
        .ar-fullview-btn:hover { background: #ece9ff; }
        .ar-fullview-btn:disabled { opacity: 0.4; cursor: not-allowed; }

        /* ══════════════════════════════════════════════
           FULL VIEW APPLICATION MODAL — LETTERHEAD/DOCUMENT STYLE
           ------------------------------------------------------------
           UPDATED: the overlay/toolbar/canvas shell now matches the
           full-bleed, sticky-toolbar "document viewer" pattern used by
           company_reports.php's evaluation preview (.eval-overlay /
           .eval-doc-toolbar / .eval-doc-canvas / .doc-paper) — a
           dark full-screen backdrop, a navy toolbar pinned to the top
           of the scrollable viewport, and a padded gray canvas that
           centers the white letterhead "paper" below it — instead of
           the previous rounded, centered floating-panel look. Only the
           SHELL (overlay/toolbar/canvas) styling changed; the
           letterhead/title-band/form-body/footer-band/doc-table/
           signature-bar content styling and structure below this
           block is untouched.

           FIX — Allow/Deny bar overlapping the document:
           This file never defines a global `box-sizing: border-box`
           reset (student_profile.php does, via its `*` rule, which is
           why the same kind of letterhead/toolbar layout doesn't have
           this problem there). Several elements below use a fixed
           `width: 794px` together with their own `padding`
           (`.fv-doc-paper`, `.fv-submit-bar`, `#appFullViewBox`'s
           children). Under the browser default `content-box` sizing,
           padding is added ON TOP of that 794px, so the Allow/Deny
           action bar rendered wider than the document paper above it
           and visually overlapped/overflowed past its edges. Scoping
           `box-sizing: border-box` to just this modal (below) fixes
           the width math without touching box-sizing anywhere else on
           the page, so no other existing layout is affected.
           ══════════════════════════════════════════════ */
        :root {
            --navy: #07145f;
            --gold: #c8a800;
            --rule: #c8cfe8;
        }
        #appFullViewOverlay,
        #appFullViewOverlay *,
        #appFullViewOverlay *::before,
        #appFullViewOverlay *::after {
            box-sizing: border-box;
        }
        #appFullViewOverlay {
            display: none; position: fixed; inset: 0;
            background: rgba(0,0,0,0.72); z-index: 10010;
            overflow-y: auto; padding: 0;
        }
        #appFullViewOverlay.open { display: block; }
        .fv-doc-toolbar {
            background: var(--navy); padding: 0.55rem 1.5rem;
            display: flex; align-items: center; justify-content: space-between;
            gap: 12px; flex-wrap: wrap;
            position: sticky; top: 0; z-index: 200;
            box-shadow: 0 2px 10px rgba(0,0,0,0.35);
        }
        .fv-doc-toolbar-left { display: flex; align-items: center; gap: 10px; min-width: 0; }
        .fv-doc-toolbar-title { font-family: 'Segoe UI', sans-serif; font-size: 0.85rem; font-weight: 700; color: #fff; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
        .fv-doc-toolbar-right { display: flex; align-items: center; gap: 8px; flex-shrink: 0; }
        .fv-tbtn-close { background: rgba(255,255,255,0.14); color: rgba(255,255,255,0.9); border: 1px solid rgba(255,255,255,0.28); padding: 7px 16px; border-radius: 6px; font-size: 12.5px; font-weight: 600; cursor: pointer; transition: background 0.15s; }
        .fv-tbtn-close:hover { background: rgba(255,255,255,0.26); color: #fff; }

        .fv-doc-canvas { background: #d8dde8; padding: 24px 16px 40px; min-height: calc(100vh - 54px); display: flex; flex-direction: column; align-items: center; }
        #appFullViewBox {
            background: transparent; width: 794px; max-width: 100%;
            margin: 0 auto; display: flex; flex-direction: column;
        }
        .fv-doc-paper {
            background: #fff; border: 1px solid #b0b8cc; box-shadow: 0 4px 32px rgba(0,0,0,.22);
            width: 794px; max-width: 100%;
            font-family: "Times New Roman","Crimson Pro",Times,serif; color: #1a1a1a;
            display: flex; flex-direction: column; box-sizing: border-box;
            /* ── PAGINATION: fixed A4-equivalent height so multi-page
               application documents (see .fv-pages-wrap below) lay out
               exactly like company_list.php's dr-paper pages — each
               .fv-doc-paper instance built by the JS pagination engine
               is now one fixed-size page instead of one page that grows
               forever with content. overflow:hidden means any content
               that doesn't fit a page's measured budget clips inside
               that page instead of visually colliding with the next
               page or the footer band. */
            height: 1123px;
            overflow: hidden;
        }
        .fv-letterhead { background: var(--navy); padding: 12px 24px; display: flex; align-items: center; gap: 14px; border-bottom: 3px solid var(--gold); flex-shrink: 0; }
        .fv-lh-seal { width: 54px; height: 54px; border-radius: 50%; display: flex; align-items: center; justify-content: center; flex-shrink: 0; overflow: hidden; border: 2px solid rgba(255,255,255,.25); }
        .fv-lh-seal img { width: 100%; height: 100%; object-fit: cover; border-radius: 50%; display: block; }
        .fv-lh-text { color: #fff; flex: 1; min-width: 0; }
        .fv-lh-line1 { font-size: 8.5px; letter-spacing: .18em; text-transform: uppercase; color: #aac4f0; margin-bottom: 2px; font-family: 'Courier New', monospace; }
        .fv-lh-line2 { font-size: 15px; font-weight: 700; line-height: 1.25; text-transform: uppercase; letter-spacing: .01em; }
        .fv-lh-line3 { font-size: 9.5px; color: #dbe6fb; margin-top: 2px; }
        .fv-lh-line4 { font-size: 9px; color: #aac4f0; margin-top: 1px; }
        .fv-title-band { background: #f4f5fb; border-bottom: 1.5px solid var(--rule); padding: 8px 24px 7px; text-align: center; flex-shrink: 0; }
        .fv-title-band h1 { font-family: 'Segoe UI', sans-serif; font-size: 16px; font-weight: 700; color: var(--navy); letter-spacing: .035em; text-transform: uppercase; }
        .fv-form-meta { margin-top: 3px; font-family: 'Courier New', monospace; font-size: 7.5px; color: #999; }
        /* min-height:0 + overflow:hidden: prevents this flex-item body from
           refusing to shrink below its content and pushing the footer band
           off the fixed-height page — same fix company_list.php applies to
           its own .dr-form-body for the identical reason. */
        .fv-form-body { padding: 16px 28px 20px; flex: 1; min-height: 0; overflow: hidden; }

        /* ══════════════════════════════════════════════
           PAGINATION ENGINE SUPPORT — mirrors company_list.php's
           .dr-pages-wrap / .dr-header-clone / .dr-footer-clone /
           .dr-raw-source / .dr-doc-page-body / .dr-doc-page-fallback.
           These are new, additive rules; nothing above this block
           was removed or renamed. ══════════════════════════════════════════════ */
        .fv-pages-wrap {
            display: flex;
            flex-direction: column;
            align-items: center;
            gap: 22px;
            width: 794px;
            max-width: 100%;
            margin: 0 auto;
        }
        /* Hidden measurement scaffold — never rendered visibly. The JS
           pagination engine (fvRenderPages(), in the <script> block)
           clones these into an off-screen sandbox purely to measure
           real, font-aware heights before building the visible pages. */
        .fv-header-clone,
        .fv-footer-clone,
        .fv-raw-source {
            display: none !important;
            position: fixed !important;
            top: 0 !important; left: -9999px !important;
            width: 0 !important; height: 0 !important;
            overflow: hidden !important;
            pointer-events: none !important;
        }
        /* Submitted Documents dedicated page — always the single, last
           page. Vertically centers the title+table as one block, same
           as company_list.php's Submitted Documents treatment; falls
           back to plain top-aligned flow if the content is ever tall
           enough that centering it could risk clipping. */
        .fv-doc-page-body {
            display: flex;
            flex-direction: column;
            justify-content: center;
        }
        .fv-doc-page-fallback {
            display: flex;
            flex-direction: column;
            justify-content: flex-start;
        }
        .fv-doc-page-inner { width: 100%; }
        .fv-form-body { padding: 18px 28px 20px; flex: 1; min-height: 0; overflow: hidden; }
        .fv-section-title { font-size: 13px; font-weight: 700; color: #1a1a1a; margin: 16px 0 8px; border-bottom: 1px solid #e5e7eb; padding-bottom: 4px; }
        .fv-section-title:first-child { margin-top: 0; }
        .fv-footer-band { background: #f0f2f8; border-top: 1.5px solid var(--navy); padding: 5px 24px; display: flex; justify-content: space-between; font-family: 'Courier New', monospace; font-size: 7.5px; color: #888; letter-spacing: .07em; flex-shrink: 0; }

        /* Applicant header strip inside the paper (photo + name + meta) */
        .fv-applicant-strip { display: flex; align-items: center; gap: 16px; margin-bottom: 4px; }
        .fv-avatar2 { width: 66px; height: 66px; border-radius: 50%; background: #dbeafe; display: flex; align-items: center; justify-content: center; font-size: 19px; font-weight: 700; color: #1d4ed8; overflow: hidden; flex-shrink: 0; border: 2.5px solid var(--gold); cursor: pointer; }
        .fv-avatar2 img { width: 100%; height: 100%; object-fit: cover; }
        .fv-applicant-name { font-family: 'Segoe UI', sans-serif; font-size: 17px; font-weight: 700; color: var(--navy); }
        .fv-applicant-meta { font-family: 'Segoe UI', sans-serif; font-size: 11.5px; color: #5a6272; margin-top: 2px; }
        .fv-applicant-badges { display: flex; flex-wrap: wrap; gap: 6px; margin-top: 7px; }
        .fv-badge-pill { display: inline-flex; align-items: center; gap: 5px; font-family: 'Segoe UI', sans-serif; font-size: 11px; font-weight: 700; padding: 3px 10px; border-radius: 20px; }
        .fv-badge-company { background: #f3e8ff; color: #7c3aed; }
        .fv-badge-date { background: #eef1fb; color: #374151; }
        .fv-badge-ojtcount { background: #ede9fe; color: #5b21b6; }
        .fv-badge-photo { border-radius: 10px; font-size: 10px; }

        /* ══════════════════════════════════════════════
           RESUME-MIRROR APPLICANT INFO
           ------------------------------------------------------------
           Mirrors student_profile.php's Digital Resume header
           (`.resume-header-row` / `.resume-header-info`), which lists
           Full Name / Email / Course as stacked "<b>Label:</b> value"
           lines next to the applicant's photo, instead of the single
           combined "Course · Email" line previously shown here.
           ══════════════════════════════════════════════ */
        .fv-resume-mirror-info { margin-bottom: 2px; }
        .fv-resume-mirror-info p {
            font-family: 'Segoe UI', sans-serif;
            font-size: 12.5px; color: #4b5563;
            margin: 0 0 3px; line-height: 1.5;
        }
        .fv-resume-mirror-info p b {
            color: #1a1a2e; font-weight: 700; margin-right: 4px;
        }

        /* Two-column form-grid, reused for Skills/Experience & Documents */
        .fv-form-grid { display: grid; grid-template-columns: 1fr; row-gap: 10px; }
        .fv-field-label { font-family: 'Segoe UI', sans-serif; font-size: 10.5px; font-weight: 700; color: #6b7280; text-transform: uppercase; letter-spacing: 0.04em; margin-bottom: 4px; }
        .fv-skill-chip-row { display: flex; flex-wrap: wrap; gap: 7px; }
        .fv-skill-chip { font-family: 'Segoe UI', sans-serif; background: #f3e8ff; color: #5b21b6; font-size: 12px; padding: 5px 13px; border-radius: 20px; border: 1px solid #ddd6fe; }
        .fv-exp-row { display: flex; align-items: flex-start; gap: 10px; padding: 9px 12px; background: #f8f7ff; border-radius: 8px; border: 1px solid #eee; font-family: 'Segoe UI', sans-serif; font-size: 12.5px; color: #374151; line-height: 1.5; }
        .fv-exp-dot { width: 6px; height: 6px; border-radius: 50%; background: #7c3aed; flex-shrink: 0; margin-top: 6px; }
        .fv-empty-note { font-family: 'Segoe UI', sans-serif; font-size: 12px; color: #a0aec0; font-style: italic; }

        /* ══════════════════════════════════════════════
           SKILL / EXPERIENCE ENTRY BOXES — mirrors the student's
           Digital Resume form (student_profile.php: the locked,
           numbered "Skill 1", "Skill 2"... / "Experience 1"...
           textarea fields under `.field-wrap .autogrow-textarea
           .field-locked`). Each entry here is rendered the same way:
           a small uppercase "Skill N" / "Experience N" label above a
           soft-bordered, tinted box holding that entry's full text
           (line breaks preserved), stacked vertically — replacing the
           previous pill-chip / dotted-row treatment.
           ══════════════════════════════════════════════ */
        .fv-entries-col { display: flex; flex-direction: column; gap: 10px; }
        .fv-entry-item { margin-bottom: 0; }
        .fv-entry-box {
            font-family: 'Segoe UI', sans-serif;
            font-size: 12.5px;
            color: #374151;
            line-height: 1.6;
            background: #f4f5fb;
            border: 1.5px solid var(--rule);
            border-radius: 10px;
            padding: 10px 14px;
            white-space: pre-wrap;
            word-break: break-word;
        }

        /* Documents table, styled like tp-comp-table */
        .fv-doc-table { width: 100%; border-collapse: collapse; border: 1px solid #000; font-size: 12px; }
        .fv-doc-table td { border: 1px solid #000; padding: 6px 10px; vertical-align: middle; font-family: 'Segoe UI', sans-serif; }
        .fv-doc-table .fv-doc-th td { font-weight: 700; background: #f4f5fb; text-align: center; }
        .fv-doc-thumb { width: 34px; height: 34px; border-radius: 6px; overflow: hidden; flex-shrink: 0; cursor: pointer; border: 1px solid #ddd; display: inline-flex; }
        .fv-doc-thumb img { width: 100%; height: 100%; object-fit: cover; display: block; }
        .fv-doc-nothumb { width: 34px; height: 34px; border-radius: 6px; background: #f3f4f6; display: inline-flex; align-items: center; justify-content: center; }
        .fv-doc-row-name { display: flex; align-items: center; gap: 10px; }
        .fv-status-chip { font-size: 10px; font-weight: 700; padding: 3px 9px; border-radius: 8px; white-space: nowrap; display: inline-block; }
        .fv-status-chip.verified { background: #dcfce7; color: #166534; }
        .fv-status-chip.denied   { background: #fee2e2; color: #991b1b; }
        .fv-status-chip.pending  { background: #fef9c3; color: #854d0e; }

        /* Signature-style action bar (matches eval-submit-bar look) */
        .fv-submit-bar {
            background: #f4f5fb; border-top: 1.5px solid var(--navy);
            padding: 14px 24px; display: flex; align-items: center;
            justify-content: space-between; gap: 10px; flex-shrink: 0;
            width: 794px; max-width: 100%; margin: 0 auto;
            border-radius: 0 0 12px 12px; box-shadow: 0 4px 20px rgba(0,0,0,.12);
        }
        .fv-submit-bar-note { font-family: 'Courier New', monospace; font-size: 7.5px; color: #9ca3af; }
        .fv-submit-bar-actions { display: flex; gap: 10px; flex-wrap: wrap; justify-content: flex-end; }
        .fv-allow-btn2 {
            background: #16a34a; color: white; border: none; padding: 10px 22px;
            border-radius: 8px; font-family: 'Segoe UI', sans-serif; font-size: 13px;
            font-weight: 700; cursor: pointer; display: flex; align-items: center;
            gap: 7px; transition: opacity 0.2s;
        }
        .fv-allow-btn2:hover:not(:disabled) { opacity: 0.88; }
        .fv-allow-btn2:disabled { opacity: 0.5; cursor: not-allowed; }
        .fv-deny-btn2 {
            background: #fff1f1; color: #dc2626; border: 1px solid #fecaca; padding: 10px 22px;
            border-radius: 8px; font-family: 'Segoe UI', sans-serif; font-size: 13px;
            font-weight: 700; cursor: pointer; display: flex; align-items: center;
            gap: 7px; transition: all 0.2s;
        }
        .fv-deny-btn2:hover:not(:disabled) { background: #dc2626; color: #fff; }
        .fv-deny-btn2:disabled { opacity: 0.5; cursor: not-allowed; }

        @media (max-width: 840px) {
            #appFullViewBox, .fv-doc-paper, .fv-submit-bar, .fv-pages-wrap { width: 100%; }
        }

        /* ══════════════════════════════════════════════════════════════════
           NEW (this adjustment) — COMPANY REQUIREMENTS SIDE-MENU INDICATOR
           Same badge as company_validation.php's #sidebarMoaBadge (.sidebar-badge-moa),
           so the "Company Requirements" link shows the same count on both pages.
           ══════════════════════════════════════════════════════════════════ */
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

        /* ══════════════════════════════════════════════════════════════════
           NEW (this adjustment) — APPLICATION REQUEST POPUP NOTIFICATION
           Ported from company_validation.php's notification popup (.cv-top-toast,
           including its final "navy bar" styling): a square navy bar with a slate
           frame, green icon and the student's name in bold white, shown at the TOP
           of the page, fading in/out by itself after 7 seconds. Several stack
           downward (newest below) and they always sit BELOW this page's undo toast
           while it is showing (see cvLayoutTopToasts()). pointer-events:none — it is
           a message, not a control, so it never blocks the page underneath.
           ══════════════════════════════════════════════════════════════════ */
        .cv-top-toast {
            position: fixed; top: 30px; left: 50%; transform: translateX(-50%);
            background: #1B2A4A; color: #E3E8F1;
            border: 1px solid #55668C; border-radius: 0;
            padding: 14px 20px;
            box-shadow: 0 8px 24px rgba(27,42,74,0.30);
            display: flex; align-items: center; gap: 12px;
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            font-size: 12.5px; line-height: 1.45;
            z-index: 10020; max-width: 440px;
            opacity: 0; transition: opacity 0.35s, top 0.3s ease;
            pointer-events: none;
        }
        .cv-top-toast.show { opacity: 1; }
        .cv-top-toast i { color: #8FD18F; font-size: 18px; flex-shrink: 0; }
        .cv-top-toast strong { color: #ffffff; font-weight: 700; }
        /* ══════════════════════════════════════════════════════════════════════════════════════════
           NEW (this adjustment) — DESIGN FROM company_validation.php: OPTION 4 "FIELD OPS GRID"
           + its "LESS PALE" contrast pass. DESIGN ONLY — same palette and grammar as that page:
           navy #1B2A4A + slate grid lines, #E4EAF4 page background, square corners, small UPPERCASE
           tracked buttons and section titles, statuses drawn as small tinted tags, modals with a
           navy top rule, the same undo toast / popups / drawer look. Like company_validation.php,
           the NAV HEADER and SIDE MENU keep their ORIGINAL colours (--nav-* below).
           No id, class, data-attribute, function or behaviour the JavaScript depends on has been
           changed — these rules only sit LAST so they win (a few use !important because they have
           to beat an inline style=""). The Full View application "paper" (letterhead document)
           keeps its official document look; only the viewer shell around it follows the grid.
           ══════════════════════════════════════════════════════════════════════════════════════════ */
        :root {
            --grid-navy: #1B2A4A;
            --grid-slate: #C3CADA;
            --grid-bg: #E4EAF4;
            --grid-border: #A3AFC7;
            --grid-ink-2: #3E4963;
            --grid-ink-3: #66718D;
            --grid-ok: #2C5A2C;
            --grid-bad: #A02A2A;
            --grid-warn-bg: #F3E7B5;
            --grid-warn: #7A5A0B;
            --nav-maroon: #07145fe5;
            --nav-gold: #FFD700;
            --nav-active: #1a237e;
        }
        body { background: var(--grid-bg); color: var(--grid-navy); }

        /* — side menu header: admin name + role label (company_validation.php) — */
        .sidebar-header-titles { overflow: hidden; transition: 0.3s; min-width: 0; }
        .sidebar-header h2 { font-size: 18px; text-overflow: ellipsis; }
        .sidebar-role-label { display: block; color: rgba(255,255,255,0.55); font-size: 11px; font-weight: 700; text-transform: uppercase; letter-spacing: 0.8px; margin-top: 3px; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; transition: 0.3s; }
        .sidebar.collapsed .sidebar-header-titles { opacity: 0; width: 0; }
        .sidebar a { position: relative; }

        /* — navbar inbox button: same round button as company_validation.php's bell — */
        #appInboxBtn { width: 42px; height: 42px; font-size: 18px; background: rgba(255,255,255,0.12); border: 1.5px solid rgba(255,215,0,0.5); transition: background 0.2s, border-color 0.2s; }
        #appInboxBtn:hover { background: rgba(255,215,0,0.15); border-color: var(--nav-gold); }
        #appInboxBadge { background: #ef4444; border: none; width: auto; min-width: 18px; padding: 0 4px; box-sizing: border-box; border-radius: 9px; }

        /* — filter bar — */
        .filter-nav { background: #ffffff; border: 1px solid var(--grid-border); border-radius: 0; box-shadow: 0 1px 3px rgba(27,42,74,0.16); flex-wrap: wrap; align-items: center; }
        .search-bar, .filter-item { background: #ffffff; border: 1px solid var(--grid-border); border-radius: 0; font-size: 13px; color: var(--grid-navy); }
        .search-bar::placeholder { color: var(--grid-ink-3); opacity: 1; }
        .search-bar:focus, .filter-item:focus { outline: none; border-color: var(--grid-navy); }
        .filter-nav .archive-btn { margin-left: auto; }
        /* NEW (course filter sync): full course names come from Course Offering, so cap the width */
        #courseFilter { max-width: 340px; text-overflow: ellipsis; }
        .archive-btn { background: var(--grid-navy); border-radius: 0; text-transform: uppercase; letter-spacing: 0.4px; font-size: 12px; }

        /* — section titles (Pending Validation / Verified Students) = .request-type-section-title — */
        .section-header,
        .section-header.pending-header,
        .section-header.verified-header { background: none; border: none; border-bottom: 2px solid var(--grid-navy); border-radius: 0; padding: 0 0 8px; margin: 28px 0 14px; gap: 10px; }
        .section-header .sh-icon { display: none; }
        .section-header .sh-title,
        .section-header.pending-header .sh-title,
        .section-header.verified-header .sh-title { flex: 0 1 auto; color: var(--grid-navy); font-size: 13px; font-weight: 700; letter-spacing: 0.6px; text-transform: uppercase; }
        .section-header .sh-count,
        .section-header.pending-header .sh-count,
        .section-header.verified-header .sh-count { background: var(--grid-navy); color: #ffffff; border: 1px solid var(--grid-navy); border-radius: 0; font-size: 11px; font-weight: 600; padding: 1px 9px; }

        /* — student tables: square grid, 1px slate rules — */
        .student-table-header,
        .student-table-header.pending-table-header,
        .student-table-header.verified-table-header { background: var(--grid-navy); color: #ffffff; border: 1px solid var(--grid-navy); font-size: 11px; font-weight: 600; letter-spacing: 0.6px; }
        .student-list-wrapper,
        .student-list-wrapper.pending-wrapper,
        .student-list-wrapper.verified-wrapper { background: #ffffff; border: 1px solid var(--grid-border); border-top: none; border-radius: 0; box-shadow: 0 1px 3px rgba(27,42,74,0.16); }
        .student-row { background: #ffffff; border-bottom: 1px solid var(--grid-border); }
        .row-summary { font-size: 13.5px; --row-hover: var(--grid-bg); }
        .row-summary:hover { background: var(--grid-bg); }
        .row-summary span:first-child { font-weight: 600; color: var(--grid-navy); }
        .row-summary span:nth-child(2) { color: var(--grid-ink-2) !important; }
        .row-summary span:nth-child(3), .row-summary span:nth-child(4) { justify-self: center; }
        /* status as a small tinted tag — follows whatever colour the cell has, so the live updates keep working */
        .overall-status-dot { display: inline-block; padding: 4px 12px; font-size: 12px; font-weight: 700 !important; background: color-mix(in srgb, currentColor 12%, #ffffff); border: 1px solid color-mix(in srgb, currentColor 40%, #ffffff); }
        .row-summary span:nth-child(4)[style] { background: #ffffff !important; border: 1px solid var(--grid-border); border-radius: 0 !important; color: var(--grid-navy); font-weight: 600; padding: 3px 10px !important; }
        /* NEW (this adjustment): Requirement Status cell (was "Validation Status") = rounded percent-verified ring + two-line
           breakdown, same as company_validation.php. The cell's real text ("● Pending" / "● Verified") stays in the DOM,
           unpainted (font-size:0), because the status filter and the live-update JS read/write it. --vs-pct (0-100) drives the ring. */
        .row-summary { --vs-hole: #ffffff; }
        .row-summary:hover { --vs-hole: var(--grid-bg); }
        .overall-status-dot[data-vpct] { display: flex; align-items: center; gap: 8px; margin: -10px 0; padding: 0; font-size: 0; text-align: left; background: transparent; border: none; }
        .overall-status-dot[data-vpct]::before { content: attr(data-vpct) "%"; flex-shrink: 0; box-sizing: border-box; width: 36px; height: 36px; border-radius: 50%; display: flex; align-items: center; justify-content: center; font-size: 9.5px; line-height: 1; font-weight: 800; color: #1B2A4A; background: radial-gradient(closest-side, var(--vs-hole, #ffffff) 76%, transparent 78%), conic-gradient(#2C5A2C calc(var(--vs-pct, 0) * 1%), #C9D3E6 0); }
        .overall-status-dot[data-vpct]::after { content: attr(data-vinfo); white-space: pre-line; font-size: 11px; font-weight: 600; line-height: 1.4; color: #3E4963; text-align: left; }
        .section-empty { color: var(--grid-ink-3); }
        .section-empty i { color: var(--grid-border) !important; }
        .empty-state { color: var(--grid-ink-3); }
        .empty-state i { color: var(--grid-ink-3); }
        .empty-state h3 { color: var(--grid-ink-2); }

        /* — expanded details — */
        .details-pane { background: var(--grid-bg); border-top: 1px solid var(--grid-border); }
        h4 { color: var(--grid-navy); font-size: 12px; font-weight: 600; letter-spacing: 0.6px; text-transform: uppercase; border-bottom: 1px solid var(--grid-border); }
        .req-item { border-radius: 0; border: 1px solid var(--grid-border); transition: background 0.3s ease; }
        .req-item img { border-radius: 0; border: 1px solid var(--grid-border); }
        .req-item > div[style*="background:#eee"] { background: var(--grid-bg) !important; color: var(--grid-ink-3) !important; border: 1px solid var(--grid-border); }
        @keyframes statusFlash { 0% { background: #D6DEEE; } 100% { background: #ffffff; } }
        .update-form select, .update-form button { border-radius: 0; border: 1px solid var(--grid-border); font-size: 12px; }
        .update-form select { background: #ffffff; color: var(--grid-navy); }
        .update-form button { background: var(--grid-navy); color: #ffffff; border: none; text-transform: uppercase; letter-spacing: 0.4px; font-weight: 700; }
        .update-form button.saved { background: var(--grid-ok); }
        .verified-badge { background: #D9E8D2 !important; color: var(--grid-ok) !important; border-radius: 0 !important; }
        .awaiting-submission-block { background: var(--grid-bg) !important; border: 1px solid var(--grid-border) !important; color: var(--grid-ink-2) !important; border-radius: 0 !important; }
        .req-item span[style*="#dbeafe"], [id^="photo-ctrl-"] span[style*="#dbeafe"] { background: var(--grid-navy) !important; color: #ffffff !important; border-radius: 0 !important; }
        .verified-lock { background: var(--grid-bg); border: 1px solid var(--grid-border); color: var(--grid-navy); border-radius: 0; }
        .deployed-lock { background: #D9E8D2; border: 1px solid #9DC08F; color: var(--grid-ok); border-radius: 0; }
        .guard-banner { border-radius: 0; }
        .guard-banner.warning { background: var(--grid-warn-bg); border: 1px solid #D4BC66; color: var(--grid-warn); }
        .profile-card { border-radius: 0; border: 1px solid var(--grid-border); box-shadow: 0 1px 3px rgba(27,42,74,0.16); }
        .profile-img-large { border-radius: 0; border: 2px solid var(--grid-navy); }
        .profile-card > div[style*="background:#eee"] { background: var(--grid-bg) !important; border: 1px solid var(--grid-border); border-radius: 0 !important; }
        [id^="photo-ctrl-"] > div[style*="#dcfce7"] { background: #D9E8D2 !important; color: var(--grid-ok) !important; border: 1px solid #9DC08F; border-radius: 0 !important; }
        .info-side > div[style*="margin-top:20px"] { border: 1px solid var(--grid-border) !important; border-radius: 0 !important; box-shadow: 0 1px 3px rgba(27,42,74,0.16); color: var(--grid-navy); }

        /* — pagination — */
        .pg-btn, .pg-number-btn { border: 1px solid var(--grid-border); border-radius: 0; background: #ffffff; color: var(--grid-ink-2); }
        .pg-btn:hover:not(:disabled), .pg-number-btn:hover { background: var(--grid-bg); border-color: var(--grid-border); }
        .pg-number-btn.active { background: var(--grid-navy); color: var(--grid-slate); border-color: var(--grid-navy); }
        .pg-ellipsis { color: var(--grid-ink-3); }
        .pg-info { color: var(--grid-ink-2); }

        /* — undo toast: navy bar, square, slate frame, small tracked label (position/animation untouched) — */
        #undoToast { background: var(--grid-navy); color: #ffffff; border: 1px solid #55668C; border-radius: 0; box-shadow: 0 8px 24px rgba(27,42,74,0.30); }
        #undoToast.denied-pending { border: 1px solid #ef4444; }
        #undoToast .toast-label strong { font-size: 10.5px; font-weight: 600; letter-spacing: 0.6px; text-transform: uppercase; color: var(--grid-border); }
        #undoToast .toast-label strong.denied-mode { color: #fca5a5; }
        #undoToast .toast-label span { font-size: 13px; font-weight: 600; color: #ffffff; }
        #undoToast .undo-btn { background: #ffffff; color: var(--grid-navy); border-radius: 0; font-size: 12px; letter-spacing: 0.4px; text-transform: uppercase; }
        #undoToast .dismiss-btn { color: var(--grid-border); }
        #undoToast .dismiss-btn:hover { color: #ffffff; }
        .countdown-ring circle { stroke: #3A4A6B; }
        .countdown-ring .progress { stroke: var(--grid-slate); }
        .countdown-ring .num { color: var(--grid-slate); }
        .countdown-ring .progress.denied-ring { stroke: #ef4444; }
        .countdown-ring .num.denied-num { color: #ef4444; }

        /* — modals / popups: square, slate frame, navy top rule, UPPERCASE titles — */
        .arch-modal-box, #archiveViewerBox, #blockNotifBox, #unarchiveConfirmBox { border-radius: 0; border: 1px solid var(--grid-border); border-top: 4px solid var(--grid-navy); box-shadow: 0 12px 32px rgba(27,42,74,0.25); }
        .arch-modal-title, #blockNotifBox .bn-title, #unarchiveConfirmBox .uc-title { color: var(--grid-navy); text-transform: uppercase; letter-spacing: 0.6px; font-size: 15px; }
        .arch-modal-msg, #blockNotifBox .bn-msg, #unarchiveConfirmBox .uc-msg { color: var(--grid-ink-2); }
        .arch-modal-label { color: var(--grid-ink-2); }
        .arch-modal-input { border: 1px solid var(--grid-border); border-radius: 0; }
        .arch-modal-input:focus { border-color: var(--grid-navy); }
        .arch-modal-cancel, .arch-modal-export, .arch-modal-confirm,
        #blockNotifBox .bn-ok, #unarchiveConfirmBox .uc-cancel, #unarchiveConfirmBox .uc-go { border-radius: 0; text-transform: uppercase; letter-spacing: 0.4px; font-size: 12px; }
        .arch-modal-cancel, #unarchiveConfirmBox .uc-cancel { background: #ffffff; color: var(--grid-navy); border: 1px solid var(--grid-border); }
        .arch-modal-export { background: var(--grid-ok); }
        .arch-modal-confirm, #unarchiveConfirmBox .uc-go { background: var(--grid-navy); color: #ffffff; }
        #blockNotifBox .bn-ok { background: var(--grid-navy); color: #ffffff; }
        .arch-progress { color: var(--grid-navy); }
        #guardModal > div { border-radius: 0 !important; border: 1px solid var(--grid-border); border-top: 4px solid var(--grid-navy); box-shadow: 0 12px 32px rgba(27,42,74,0.25) !important; }
        #guardModal #guardModalTitle { color: var(--grid-navy); text-transform: uppercase; letter-spacing: 0.6px; font-size: 15px !important; }
        #guardModal #guardModalMsg { color: var(--grid-ink-2) !important; }
        #guardModal button { background: var(--grid-navy) !important; color: #ffffff !important; border-radius: 0 !important; text-transform: uppercase; letter-spacing: 0.4px; font-size: 12px !important; }

        /* — archive FAB + archive viewer — */
        #archiveFabBtn { border-radius: 0; background: var(--grid-navy); box-shadow: 0 6px 16px rgba(27,42,74,0.35); }
        #archiveFabBtn:hover { box-shadow: 0 12px 30px rgba(27,42,74,0.5); }
        #archiveFabLabel { background: var(--grid-navy); border-radius: 0; text-transform: uppercase; letter-spacing: 0.4px; font-size: 10px; }
        #archiveViewerHeader, #archiveViewerControls { border-bottom: 1px solid var(--grid-border); }
        #archiveViewerHeader h3 { color: var(--grid-navy); }
        #archiveViewerHeader h3 i { color: var(--grid-navy) !important; }
        #archiveViewerClose { color: var(--grid-ink-3); }
        #archiveViewerClose:hover { color: var(--grid-ink-2); }
        #archiveBatchFilter, #archiveSearchInput { border: 1px solid var(--grid-border); border-radius: 0; }
        #archiveExportFilteredBtn { background: var(--grid-ok); border-radius: 0; text-transform: uppercase; letter-spacing: 0.4px; font-size: 12px; }
        #archiveUnarchiveBtn { background: var(--grid-navy) !important; border-radius: 0 !important; text-transform: uppercase; letter-spacing: 0.4px; font-size: 12px !important; }
        #archiveViewerBody th { color: var(--grid-ink-2); border-bottom: 2px solid var(--grid-border); }
        #archiveViewerBody td { color: var(--grid-navy); border-bottom: 1px solid var(--grid-border); }
        #archiveViewerBody tr:hover td { background: var(--grid-bg); }
        #archiveViewerBody td[style*="#7c3aed"] { color: var(--grid-navy) !important; }
        #archiveViewerBody td[style*="#718096"], #archiveViewerBody td[style*="#a0aec0"] { color: var(--grid-ink-2) !important; }
        #archiveEmpty { color: var(--grid-ink-3); }
        #archiveEmpty i { color: var(--grid-border) !important; }
        .av-badge { border-radius: 0; }
        .av-badge.verified { background: #D9E8D2; color: var(--grid-ok); }
        .av-badge.pending  { background: var(--grid-warn-bg); color: var(--grid-warn); }
        .av-badge.deployed { background: var(--grid-bg); color: var(--grid-navy); border: 1px solid var(--grid-border); }
        .av-badge.waiting  { background: #ffffff; color: var(--grid-ink-2); border: 1px solid var(--grid-border); }
        .av-unarchive-btn { background: var(--grid-navy) !important; border-radius: 0 !important; text-transform: uppercase; letter-spacing: 0.4px; }

        /* — application request drawer = company_validation.php's notification drawer — */
        #appRequestDrawer { box-shadow: -8px 0 40px rgba(0,0,0,0.18); }
        #appRequestHeader { background: var(--grid-navy); padding: 20px 24px; }
        #appRequestHeader h3 { color: #ffffff; font-size: 16px; font-weight: 700; }
        #appRequestNewBadge { background: var(--grid-slate) !important; color: var(--grid-navy) !important; border-radius: 0 !important; font-size: 11px !important; font-weight: 800 !important; padding: 2px 8px !important; }
        #appRequestClose { color: rgba(255,255,255,0.7); }
        #appRequestBody { padding: 0; }
        .ar-card { border: none; border-bottom: 1px solid var(--grid-border); border-radius: 0; margin-bottom: 0; background: #ffffff; padding: 18px 20px; transition: opacity 0.3s, background 0.15s; }
        .ar-card:hover { background: var(--grid-bg); }
        @keyframes arCardNewFlash {
            0%   { background: #D6DEEE; }
            70%  { background: #D6DEEE; }
            100% { background: #ffffff; }
        }
        .ar-summary-name { color: var(--grid-navy); }
        .ar-summary-course { color: var(--grid-ink-2); }
        .ar-summary-company { color: var(--grid-navy); }
        .ar-summary-count { color: var(--grid-ink-3); }
        .ar-allow-btn, .ar-deny-btn, .ar-fullview-btn { border-radius: 0; text-transform: uppercase; letter-spacing: 0.4px; font-size: 12px; }
        .ar-allow-btn { background: var(--grid-ok); }
        .ar-deny-btn { background: #ffffff; color: var(--grid-bad); border: 1px solid var(--grid-border); }
        .ar-deny-btn:hover { background: var(--grid-bg); color: var(--grid-bad); }
        .ar-fullview-btn { background: #ffffff; color: var(--grid-navy); border: 1px solid var(--grid-border); }
        .ar-fullview-btn:hover { background: var(--grid-bg); }
        .ar-live-notice { border-radius: 0; margin: 12px 16px 0; }
        .ar-live-notice-added   { background: var(--grid-bg); color: var(--grid-navy); border: 1px solid var(--grid-border); }
        .ar-live-notice-removed { background: var(--grid-warn-bg); color: var(--grid-warn); border: 1px solid #D4BC66; }
        .ar-empty { color: var(--grid-ink-3); padding: 60px 20px; font-size: 14px; }
        .ar-empty i { color: var(--grid-ink-3); font-size: 44px; }
        #appRequestBody > div[style*="#a0aec0"] { color: var(--grid-ink-3) !important; }

        /* — Full View viewer shell (the letterhead "paper" itself keeps its document design) — */
        .fv-doc-toolbar { background: var(--grid-navy); }
        .fv-tbtn-close { border-radius: 0; text-transform: uppercase; letter-spacing: 0.4px; font-size: 12px; }
        .fv-doc-canvas { background: var(--grid-bg); }
        .fv-submit-bar { border-radius: 0; border: 1px solid var(--grid-border); border-top: 2px solid var(--grid-navy); background: #ffffff; }
        .fv-allow-btn2, .fv-deny-btn2 { border-radius: 0; text-transform: uppercase; letter-spacing: 0.4px; font-size: 12px; }
        .fv-allow-btn2 { background: var(--grid-ok); }
        .fv-deny-btn2 { background: #ffffff; color: var(--grid-bad); border: 1px solid var(--grid-border); }
        .fv-deny-btn2:hover:not(:disabled) { background: var(--grid-bg); color: var(--grid-bad); }
        /* ══════════════════════════════════════════════════════════
           NEW (this adjustment) — PAGE LOAD overlay, same as
           company_validation.php's #globalLoadingOverlay: visible by
           default (covers the very first paint) and fades out once the
           page has finished loading.
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
        /* NEW (loader sync fix): when the page is reloading / leaving, the
           overlay must appear on the very next paint — no 0.35s fade-in,
           because the browser may stop painting this page before a fade
           would finish, which made the loader look like it never activated. */
        #globalLoadingOverlay.gl-instant { transition: none; }
        .global-loading-box { display: flex; flex-direction: column; align-items: center; gap: 16px; animation: globalLoadingPop 0.35s ease; }
        .global-loading-spinner { width: 54px; height: 54px; border-radius: 50%; border: 5px solid #A3AFC7; border-top-color: #1B2A4A; animation: globalLoadingSpin 0.85s linear infinite; }
        .global-loading-text { font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif; font-size: 13px; font-weight: 700; color: #1B2A4A; text-transform: uppercase; letter-spacing: 0.6px; display: flex; align-items: center; gap: 8px; }
        .global-loading-dots span { animation: globalLoadingDots 1.2s infinite; opacity: 0; }
        .global-loading-dots span:nth-child(2) { animation-delay: 0.2s; }
        .global-loading-dots span:nth-child(3) { animation-delay: 0.4s; }
        @keyframes globalLoadingSpin { to { transform: rotate(360deg); } }
        @keyframes globalLoadingPop { from { transform: scale(0.9); opacity: 0; } to { transform: scale(1); opacity: 1; } }
        @keyframes globalLoadingDots { 0%, 20% { opacity: 0; } 50% { opacity: 1; } 100% { opacity: 0; } }

        /* ══════════════════════════════════════════════════════════════════
           NEW (this adjustment) — EXTENDED PANEL: company_validation.php's
           "Tabs + Document Gallery" requirement layout, ported rule for rule
           (Field Ops Grid colours). Placed last so it restyles the existing
           .req-item / .update-form / .profile-card elements inside the gallery.
           ══════════════════════════════════════════════════════════════════ */
        .cv-panel { display: flex; flex-direction: column; gap: 16px; }
        .cv-tabs { display: flex; align-items: flex-end; flex-wrap: wrap; gap: 6px; border-bottom: 1px solid #A3AFC7; }
        .cv-tab { height: 46px; padding: 0 20px; border: none; border-bottom: 3px solid transparent; background: transparent; color: #3E4963; font-size: 12px; font-weight: 600; letter-spacing: 0.4px; text-transform: uppercase; font-family: inherit; display: inline-flex; align-items: center; gap: 8px; cursor: default; margin-bottom: -1px; border-radius: 0; }
        .cv-tab.active { color: #1B2A4A; border-bottom-color: #1B2A4A; font-weight: 700; background: #ffffff; }
        .cv-tab-count { font-size: 11px; font-weight: 700; padding: 2px 8px; border-radius: 0; background: #1B2A4A; color: #ffffff; white-space: nowrap; text-transform: none; letter-spacing: 0; }
        .cv-tabpanel { display: none; }
        .cv-tabpanel.active { display: block; }

        .cv-req-summary { display: flex; align-items: center; justify-content: space-between; flex-wrap: wrap; gap: 10px 16px; margin-bottom: 14px; }
        .cv-req-summary-text { font-size: 13px; color: #3E4963; }
        .cv-req-summary-text b { color: #1B2A4A; }
        .cv-progress { display: flex; align-items: center; gap: 10px; }
        .cv-progress-bar { width: 120px; height: 8px; border-radius: 0; background: #C9D3E6; overflow: hidden; }
        .cv-progress-fill { height: 8px; background: #2C5A2C; transition: width 0.3s ease; }
        .cv-progress-pct { font-size: 12px; color: #3E4963; white-space: nowrap; }

        .cv-gallery { display: grid; grid-template-columns: repeat(auto-fill, minmax(190px, 1fr)); gap: 14px; grid-auto-rows: minmax(270px, auto); }
        .cv-gallery .req-item { display: flex; flex-direction: column; align-items: stretch; gap: 0; padding: 0; margin-bottom: 0; border: 1px solid #A3AFC7; border-radius: 0; overflow: hidden; background: #ffffff; box-shadow: 0 1px 3px rgba(27,42,74,0.16); text-align: left; }
        .cv-card-preview { position: relative; flex: 1 0 132px; min-height: 132px; background: #E4EAF4; display: flex; align-items: center; justify-content: center; }
        .cv-gallery .cv-card-preview img,
        .cv-gallery .req-item img.cv-thumb-img,
        .cv-gallery .cv-card-preview img.profile-img-large { position: absolute; top: 0; left: 0; width: 100%; height: 100%; margin: 0; border: none; border-radius: 0; object-fit: cover; object-position: top center; display: block; cursor: pointer; }
        .cv-no-file { position: absolute; top: 14px; right: 14px; bottom: 14px; left: 14px; border: 1px dashed #A3AFC7; border-radius: 0; display: flex; flex-direction: column; align-items: center; justify-content: center; gap: 6px; color: #3E4963; font-size: 12px; font-weight: 600; }
        .cv-no-file i { font-size: 20px; }

        /* ══════════════════════════════════════════════════════════════════
           NEW (this adjustment) — PDF DISPLAY + PREVIEW, ported from
           company_validation.php: a submitted PDF shows as a PDF tile in the
           Requirements gallery and opens a full-bleed dark viewer (PDF in an
           iframe, image in the image viewer). Additive: every rule is scoped
           to new class names, so no existing card, ribbon, form or modal is
           affected.
           ══════════════════════════════════════════════════════════════════ */
        .cv-preview-trigger { cursor:pointer; }
        .cv-gallery .req-item img.cv-thumb-img.cv-preview-trigger { transition:filter 0.15s; }
        .cv-gallery .req-item img.cv-thumb-img.cv-preview-trigger:hover { filter:brightness(0.93); }

        /* A single PDF — a tile that fills the preview area, in the same red used by the stacked PDF layers */
        .cv-pdf-tile { position:absolute; top:14px; right:14px; bottom:14px; left:14px; box-sizing:border-box; border:1px solid #D49A94; border-radius:0; background:#F2D5D1; display:flex; flex-direction:column; align-items:center; justify-content:center; gap:6px; color:#A02A2A; font-size:12px; font-weight:700; transition:transform 0.15s, box-shadow 0.15s, background 0.15s; }
        .cv-pdf-tile i { font-size:46px; color:#A02A2A; }
        .cv-pdf-tile:hover { background:#F2D5D1; transform:translateY(-2px); box-shadow:0 6px 16px rgba(27,42,74,0.12); }

        /* Requirement document preview modal — the same full-bleed dark viewer CompanyForm.php uses */
        .cv-doc-modal { display:none; position:fixed; inset:0; box-sizing:border-box; background:#0F1A33; z-index:10030; flex-direction:column; overflow:hidden; }
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

        .fv-doc-thumb.fv-doc-thumb-pdf { align-items:center; justify-content:center; background:#F2D5D1; border-color:#D49A94; }
        .fv-doc-thumb.fv-doc-thumb-pdf i { font-size:16px; color:#A02A2A; }
        .cv-card-ribbon { position: absolute; top: 10px; right: 10px; z-index: 6; display: flex; gap: 6px; pointer-events: none; }
        .cv-rb { display: inline-flex; align-items: center; gap: 4px; font-size: 11px; font-weight: 700; padding: 3px 9px; border-radius: 0; white-space: nowrap; box-shadow: 0 1px 2px rgba(0,0,0,0.12); }
        .cv-rb-verified { background: #D9E8D2; color: #2C5A2C; }
        .cv-rb-pending  { background: #F3E7B5; color: #7A5A0B; }
        .cv-rb-awaiting { background: #E4EAF4; color: #3E4963; }
        .cv-rb-rejected { display: none; background: #F2D5D1; color: #A02A2A; }
        /* exactly one status pill is visible, driven by data-state / data-rejected */
        .cv-gallery .req-item:not([data-state="verified"]) .cv-rb-verified,
        .cv-gallery .req-item[data-state="verified"] .cv-rb-pending,
        .cv-gallery .req-item[data-state="verified"] .cv-rb-awaiting,
        .cv-gallery .req-item[data-state="pending"]  .cv-rb-awaiting,
        .cv-gallery .req-item[data-state="awaiting"] .cv-rb-pending { display: none; }
        .cv-gallery .req-item[data-rejected="1"] .cv-rb-rejected { display: inline-flex; }
        .cv-gallery .req-item[data-rejected="1"] .cv-rb-pending,
        .cv-gallery .req-item[data-rejected="1"] .cv-rb-awaiting,
        .cv-gallery .req-item[data-rejected="1"] .cv-rb-verified { display: none; }
        .cv-rej-placeholder { display: none; position: absolute; top: 14px; right: 14px; bottom: 14px; left: 14px; box-sizing: border-box; border: 1px dashed #D49A94; border-radius: 0; background: #F2D5D1; flex-direction: column; align-items: center; justify-content: center; gap: 8px; color: #A02A2A; font-size: 12px; font-weight: 600; text-align: center; line-height: 1.4; }
        .cv-rej-placeholder i { font-size: 24px; }
        .cv-req-card[data-rejected="1"] .cv-rej-placeholder { display: flex; }
        .cv-req-card[data-rejected="1"] .cv-card-preview > :not(.cv-card-ribbon):not(.cv-rej-placeholder) { display: none !important; }

        .cv-card-body { padding: 12px; display: flex; flex-direction: column; gap: 8px; flex: 0 0 auto; }
        .cv-gallery .req-item[data-state="verified"] .cv-card-body,
        .cv-gallery .req-item[data-state="awaiting"] .cv-card-body { box-sizing: border-box; min-height: 54px; padding: 8px 10px; justify-content: center; }
        /* the requirement's own content block (#req-content-*) joins the card body's column, so the
           remark can sit right under the name while the JS keeps re-rendering that block as before */
        .cv-card-content { display: contents; }
        .cv-card-content > * { order: 2; }
        .cv-card-content > div[style*="font-weight:600"] { order: 0; }
        .cv-card-remark { order: 1; }
        .cv-card-label,
        .cv-card-content > div[style*="font-weight:600"] { font-size: 13.5px !important; font-weight: 700 !important; color: #1B2A4A; display: flex; align-items: center; flex-wrap: wrap; gap: 4px; margin-bottom: 0 !important; }
        .cv-card-remark { display: none; align-items: flex-start; gap: 7px; padding: 7px 10px; background: #F2D5D1; border: 1px solid #D49A94; border-radius: 0; color: #A02A2A; font-size: 12px; line-height: 1.4; overflow-wrap: anywhere; }
        .cv-card-remark i { margin-top: 2px; flex-shrink: 0; }
        .cv-card-remark > span { flex: 1; min-width: 0; max-height: 5.6em; overflow-y: auto; padding-right: 4px; }
        .cv-card-remark b { font-weight: 700; }
        .cv-req-card[data-rejected="1"] .cv-card-remark { display: flex; }
        /* the status pill on the preview says Verified / Awaiting — the old in-body labels are kept (the script
           looks for them) but not shown, exactly like company_validation.php's cards */
        .cv-card-body .verified-badge,
        .cv-gallery .verified-lock,
        .cv-gallery .awaiting-submission-block,
        .cv-gallery [id^="photo-ctrl-"] > div[style*="#dcfce7"] { display: none !important; }
        .cv-gallery .deployed-lock { margin-top: 0 !important; justify-content: flex-start !important; align-items: flex-start; line-height: 1.4; }
        .cv-gallery [id^="photo-ctrl-"] > div[style*="text-align:center"] { text-align: left !important; margin-bottom: 0 !important; }
        .cv-gallery .update-form { display: flex; flex-direction: column; gap: 8px; }
        .cv-gallery .update-form select,
        .cv-gallery .update-form button { width: 100%; height: 38px; box-sizing: border-box; padding: 0 10px; margin-top: 0 !important; border-radius: 0; font-size: 13px; font-family: inherit; }
        .cv-gallery .update-form select { border: 1px solid #A3AFC7; background: #ffffff; color: #1B2A4A; }
        .cv-gallery .update-form button { background: #1B2A4A; color: #ffffff; border: none; font-weight: 600; cursor: pointer; }
        .cv-gallery .update-form button.saved { background: #2C5A2C; }
        /* NEW (this adjustment): the free-text rejection remark box (was a dropdown) — same look as the Status select, wraps long text */
        .cv-gallery .update-form textarea[name="remark"],
        .cv-gallery .update-form textarea[name="photo_remark"] { width: 100%; height: 66px; box-sizing: border-box; padding: 8px 10px; border: 1px solid #A3AFC7; border-radius: 0; background: #ffffff; color: #1B2A4A; font-size: 13px; line-height: 1.4; font-family: inherit; resize: none; overflow-x: hidden; overflow-y: auto; overflow-wrap: anywhere; scrollbar-width: thin; scrollbar-color: #A3AFC7 transparent; }
        .cv-gallery .update-form textarea[name="remark"]:focus,
        .cv-gallery .update-form textarea[name="photo_remark"]:focus { outline: none; border-color: #A02A2A; box-shadow: 0 0 0 3px rgba(160,42,42,0.12); }
        .cv-gallery .update-form button:disabled { opacity: 0.5; cursor: not-allowed; }
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
        will-change: transform;   /* FIX: the ring keeps turning on the compositor while this large page is busy loading (no pause) */
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
    var CV_PHASE_KEY = 'cvLoaderPhase', cvRingAt0 = null, cvDotsAt0 = null, cvPhaseReadAt = 0;   // NEW (loader sync fix)
    try {
        var cvEpoch = parseInt(sessionStorage.getItem(CV_LOADER_KEY) || '', 10);
        sessionStorage.removeItem(CV_LOADER_KEY);
        var cvSince = cvEpoch ? Date.now() - cvEpoch : -1;
        if (cvSince >= 0 && cvSince < 15000) {
            cvCarriedOver = true; cvCoverPainted = true;
            cvBootStart = cvBootStart - cvSince;
            root.style.setProperty('--cv-ring-delay', (-((cvSince / 1000) % 1)).toFixed(3) + 's');
            root.style.setProperty('--cv-dots-delay', (-((cvSince / 1000) % 1.2)).toFixed(3) + 's');
            /* NEW (loader sync fix): the previous page also handed over where its ring and dots REALLY were in their
               turn (read from the running animations — see "pagehide" below). The old way assumed the ring started
               turning the moment the loading page was shown, which is only true for the first loading page after a
               page opens; a loading page shown later (a link click) had its ring at any angle, so the next page
               continued from the wrong one — a visible jump. With the real positions it continues exactly. */
            try {
                var cvPh = JSON.parse(sessionStorage.getItem(CV_PHASE_KEY) || 'null');
                if (cvPh && typeof cvPh.ring === 'number' && typeof cvPh.dots === 'number' && Date.now() - cvPh.t >= 0 && Date.now() - cvPh.t < 15000) {
                    var cvGap = Date.now() - cvPh.t;
                    cvRingAt0 = (cvPh.ring + cvGap) / 1000; cvDotsAt0 = (cvPh.dots + cvGap) / 1000;
                    cvPhaseReadAt = (window.performance && performance.now) ? performance.now() : Date.now();
                    root.style.setProperty('--cv-ring-delay', (-(cvRingAt0 % 1)).toFixed(3) + 's');
                    root.style.setProperty('--cv-dots-delay', (-(cvDotsAt0 % 1.2)).toFixed(3) + 's');
                }
            } catch (e) {}
        }
        sessionStorage.removeItem(CV_PHASE_KEY);
    } catch (e) {}
    function cvLoaderStartedAt() { return Date.now() - (((window.performance && performance.now) ? performance.now() : Date.now()) - cvBootStart); }
    if (window.requestAnimationFrame) requestAnimationFrame(function () { requestAnimationFrame(function () { cvCoverPainted = root.classList.contains('cv-booting'); }); });
    function continueCoverAnimation(ov) {
        try {
            if (!cvCoverPainted || !ov || ov.classList.contains('hidden')) return;
            var now = (window.performance && performance.now) ? performance.now() : Date.now();
            var elapsed = (now - cvBootStart) / 1000;
            var elapsedRing = (cvRingAt0 !== null) ? cvRingAt0 + (now - cvPhaseReadAt) / 1000 : elapsed;   // NEW (loader sync fix): the real position, when handed over
            var elapsedDots = (cvDotsAt0 !== null) ? cvDotsAt0 + (now - cvPhaseReadAt) / 1000 : elapsed;
            var spinner = ov.querySelector('.global-loading-spinner');
            if (spinner) spinner.style.animationDelay = (-(elapsedRing % 1)).toFixed(3) + 's';   // UPDATED (this adjustment): the ring's 1 s turn
            var dots = ov.querySelectorAll('.global-loading-dots span');
            for (var i = 0; i < dots.length; i++) dots[i].style.animationDelay = (-((elapsedDots - i * 0.2) % 1.2 + 1.2) % 1.2).toFixed(3) + 's';
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
    // NEW (loader sync fix): milliseconds into the current turn of an element's running CSS animation (null if unknown)
    function cvAnimPhase(el, period) {
        try {
            if (!el || !el.getAnimations) return null;
            var list = el.getAnimations();
            for (var i = 0; i < list.length; i++) {
                var a = list[i], ct = a.currentTime;
                if (typeof ct !== 'number' || !a.effect || !a.effect.getComputedTiming) continue;
                var delay = a.effect.getComputedTiming().delay || 0;
                return (((ct - delay) % period) + period) % period;
            }
        } catch (e) {}
        return null;
    }
    window.addEventListener('pagehide', function () {
        try {
            var ov = document.getElementById('globalLoadingOverlay');
            if (ov && !ov.classList.contains('hidden') && !ov.classList.contains('success-state')) {
                sessionStorage.setItem(CV_LOADER_KEY, String(cvShownSince !== null ? cvShownSince : Date.now()));
                // NEW (loader sync fix): where the ring and the dots really are in their turn right now
                var ringMs = cvAnimPhase(ov.querySelector('.global-loading-spinner'), 1000);
                var dotsMs = cvAnimPhase(ov.querySelector('.global-loading-dots span'), 1200);
                if (ringMs !== null && dotsMs !== null) sessionStorage.setItem(CV_PHASE_KEY, JSON.stringify({ t: Date.now(), ring: ringMs, dots: dotsMs }));
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
<!-- NEW (this adjustment): the "New upload" tag — same design as company_validation.php (.cv-new-upload-tag) -->
<style>
    .cv-new-upload-tag { position:absolute; top:10px; left:10px; z-index:6; display:inline-flex; align-items:center; gap:5px; background:#1B2A4A; color:#ffffff; font-size:11px; font-weight:700; padding:3px 9px; border-radius:0; box-shadow:0 1px 2px rgba(0,0,0,0.18); pointer-events:none; white-space:nowrap; }
</style>
<!-- NEW (this adjustment): Inbox — new requirement submission cards, same design as company_validation.php's notification cards -->
<style>
    #studentUploadInbox { flex-shrink: 0; max-height: 45vh; overflow-y: auto; border-bottom: 1px solid #A3AFC7; background: #fff; }
    .sru-section-title { display: flex; align-items: center; gap: 8px; padding: 10px 20px; background: #E4EAF4; color: #1B2A4A;
        font-size: 11px; font-weight: 800; text-transform: uppercase; letter-spacing: 0.5px; flex-shrink: 0; }
    .sru-section-title .sru-count { margin-left: auto; background: #1B2A4A; color: #fff; padding: 1px 8px; font-size: 10.5px; }
    #studentUploadInbox .moa-card { border-bottom:1px solid #A3AFC7; padding:18px 20px; transition:background 0.15s; }
    #studentUploadInbox .moa-card:hover { background:#E4EAF4; }
    #studentUploadInbox .moa-card:last-child { border-bottom:none; }
    #studentUploadInbox .moa-card-top { display:flex; align-items:center; justify-content:space-between; gap:12px; margin-bottom:8px; }
    #studentUploadInbox .moa-card-info { flex:1; min-width:0; }
    #studentUploadInbox .moa-card-company { font-size:15px; font-weight:700; color:#1B2A4A; margin-bottom:0; white-space:nowrap; overflow:hidden; text-overflow:ellipsis; }
    #studentUploadInbox .moa-card-meta { font-size:12px; color:#3E4963; display:flex; flex-wrap:wrap; gap:10px; margin-bottom:4px; }
    #studentUploadInbox .moa-card-meta span { display:flex; align-items:center; gap:4px; }
    #studentUploadInbox .moa-notif-type-badge { display:inline-flex; align-items:center; gap:5px; font-size:10.5px; font-weight:700; padding:3px 9px; border-radius:0; margin:0; width:fit-content; flex-shrink:0; white-space:nowrap; }
    #studentUploadInbox .moa-notif-type-badge.notif-uploaded { background:#E4EAF4; color:#1B2A4A; }
    #studentUploadInbox .moa-notif-type-badge.notif-placement { background:#FFF4CC; color:#7A5A00; }
    #studentUploadInbox .moa-notif-type-badge.notif-schedule { background:#E6F0FF; color:#12408A; }   /* NEW (this adjustment): schedule changed by the supervisor */   /* NEW (this adjustment): placement replaced */
    #studentUploadInbox .moa-card-detail-row { display:flex; align-items:center; justify-content:space-between; gap:14px; }
    #studentUploadInbox .moa-card-address { font-size:12px; color:#66718D; }
    #studentUploadInbox .moa-card-address.moa-notif-upload-detail { white-space:normal; color:#1B2A4A; font-weight:600; line-height:1.4; margin-top:3px; }
    #studentUploadInbox .moa-card-actions { display:flex; gap:8px; align-items:center; flex-wrap:wrap; }
    #studentUploadInbox .moa-action-btn { display:inline-flex; align-items:center; gap:5px; padding:7px 14px; border-radius:0; font-size:12px; font-weight:700; cursor:pointer; border:none; transition:opacity 0.15s,transform 0.1s; font-family:'Segoe UI',Tahoma,Geneva,Verdana,sans-serif; white-space:nowrap; }
    #studentUploadInbox .moa-action-btn:hover { opacity:0.85; transform:translateY(-1px); }
    #studentUploadInbox .moa-action-btn:disabled { opacity:0.4; cursor:not-allowed; transform:none; }
    #studentUploadInbox .moa-action-btn.accept-btn { background:#2C5A2C; color:white; }
</style>
</head>
<body>

<!-- ══════════════════════════════════════════════════════════
     NEW (this adjustment): page-load / processing popup — same
     markup as company_validation.php's #globalLoadingOverlay. Visible by
     default (so it covers the page while it is still loading), then
     hidden by JS once the window finishes loading.
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
       • (this page already shows it when leaving, so that part is unchanged.)
     This guard (placed right after the loading page, so it runs as early as
     possible) makes it dependable without replacing any existing code:
       1. It is visible from the start of every page load, and stays up for
          at least a short moment (450 ms) so it is always actually seen. If
          the page's own code hides it sooner, the hide is simply held back
          until that moment has passed (unless the page shows it again).
       2. Leaving the page is already handled by this page's own code, so the
          guard leaves that alone.
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
    var NAV_ON_CLICK   = false;    // show it when leaving the page via a link / form (if the page doesn't already)
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

<!-- ── GUARD MODAL ── -->
<div id="guardModal" style="display:none; position:fixed; top:0; left:0; width:100%; height:100%; background:rgba(0,0,0,0.5); z-index:9998; justify-content:center; align-items:center;">
    <div style="background:white; border-radius:16px; padding:36px 32px; width:420px; box-shadow:0 20px 60px rgba(0,0,0,0.2); text-align:center;">
        <div id="guardModalIcon" style="font-size:48px; margin-bottom:16px;"></div>
        <h3 id="guardModalTitle" style="margin:0 0 10px; font-size:18px;"></h3>
        <p id="guardModalMsg" style="color:#666; font-size:14px; margin:0 0 24px; line-height:1.6;"></p>
        <button onclick="closeGuardModal()" style="background:var(--neust-maroon); color:var(--neust-gold); border:none; padding:12px 32px; border-radius:10px; font-weight:700; font-size:14px; cursor:pointer;">OK, Got it</button>
    </div>
</div>

<!-- ── ARCHIVE MODAL ── -->
<div class="arch-modal-overlay" id="archiveModal">
    <div class="arch-modal-box">
        <span class="arch-modal-icon"></span>
        <p class="arch-modal-title">Archive OJT Batch</p>
        <p class="arch-modal-msg">This will archive <strong id="archStudentCount">0</strong> student records and clear the dashboard for a new OJT batch. An Excel export will be generated before archiving. This action <strong>cannot be undone</strong>.</p>
        <label class="arch-modal-label" for="batchLabelInput">Batch Name / Label</label>
        <input type="text" class="arch-modal-input" id="batchLabelInput" placeholder="e.g. OJT Batch 2025 — 1st Semester">
        <div class="arch-modal-actions">
            <button class="arch-modal-cancel" id="archCancelBtn">Cancel</button>
            <button class="arch-modal-export" id="archExportBtn"><i class="fas fa-file-excel"></i> Export Excel</button>
            <button class="arch-modal-confirm" id="archConfirmBtn"><i class="fas fa-box-archive"></i> Archive Batch</button>
        </div>
        <div class="arch-progress" id="archProgress"> Archiving batch, please wait…</div>
    </div>
</div>

<!-- ── UNDO TOAST (top) ── -->
<div id="undoToast">
    <div class="countdown-ring">
        <svg width="36" height="36" viewBox="0 0 36 36">
            <circle cx="18" cy="18" r="14"/>
            <circle class="progress" id="undoRingProgress" cx="18" cy="18" r="14"/>
        </svg>
        <div class="num" id="undoCountNum">5:00</div>
    </div>
    <div class="toast-label">
        <strong id="undoToastStatus">Status updated</strong>
        <span id="undoToastLabel">—</span>
    </div>
    <button class="undo-btn" id="undoBtnMain" onclick="triggerUndo()">Undo</button>
    <button class="dismiss-btn" onclick="dismissUndo(true)" title="Dismiss">×</button>
</div>

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
        <!-- UPDATED (this adjustment — design from company_validation.php): admin name + role label, same markup/id as there -->
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
        <a href="administrator.php" class="active" style="position:relative;">
            <i class="fas fa-user-check"></i>
            <span class="link-text">Student Requirements</span>
            <?php if ($app_request_count > 0): ?>
                <span class="sidebar-badge-app" id="sidebarAppBadge"><?= $app_request_count ?></span>
            <?php else: ?>
                <span class="sidebar-badge-app" id="sidebarAppBadge" style="display:none"><?= $app_request_count ?></span>
            <?php endif; ?>
        </a>
        <!-- UPDATED (this adjustment): "Company Requirements" now carries the same notification indicator as on company_validation.php -->
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
                <div style="font-size:11px; color:var(--nav-gold);">Web-Based Smart OJT Monitoring and Supervision Analytics System</div>
            </div>
        </div>
        <div style="margin-left:auto; display:flex; align-items:center;">
            <button id="appInboxBtn" onclick="openAppInbox()" title="Application Requests">
                <i class="fas fa-envelope-open-text"></i>
                <span id="appInboxBadge"><?php if ($app_request_count > 0): ?>
                    <script>document.getElementById('appInboxBadge').style.display='flex';</script>
                    <?php endif; ?>
                </span>
            </button>
        </div>
    </nav>

    <div class="container">
        <!-- ── APPLICATION REQUEST INBOX DRAWER ── -->
        <div id="appRequestOverlay" onclick="if(event.target===this)closeAppInbox()">
            <div id="appRequestDrawer">
                <div id="appRequestHeader">
                    <h3>
                        <i class="fas fa-envelope-open-text"></i>
                        Inbox<!-- UPDATED (this adjustment): application requests + new requirement submissions -->
                        <span id="appRequestNewBadge" style="background:#dc2626;color:white;font-size:0.7rem;padding:2px 9px;border-radius:20px;font-weight:700;display:none;"></span>
                    </h3>
                    <button id="appRequestClose" onclick="closeAppInbox()">&#x2715;</button>
                </div>
                <!-- NEW (this adjustment): new requirement submissions (filled by cvRenderStudentUploads) -->
                <div id="studentUploadInbox" style="display:none;"></div>
                <div id="appRequestSectionTitle" class="sru-section-title" style="display:none;"><i class="fas fa-envelope-open-text"></i> Application Requests</div>
                <div id="appRequestBody">
                    <div class="ar-empty" id="arEmpty" style="display:none;">
                        <i class="fas fa-inbox"></i>
                        No pending application requests.
                    </div>
                </div>
            </div>
        </div>

        <div class="filter-nav">
            <input type="text" id="searchInput" placeholder="Search by student name..." class="search-bar" onkeyup="filterAll()">
            <select class="filter-item" id="courseFilter" onchange="filterAll()">
                <option value="All">All Courses</option>
                <?php /* UPDATED: options now come from course_offerings (see $courseFilterOptions above) */ ?>
                <?php foreach ($courseFilterOptions as $coOpt): ?>
                <option value="<?= htmlspecialchars($coOpt ?? '') ?>" title="<?= htmlspecialchars($coOpt ?? '') ?>"><?= htmlspecialchars($coOpt ?? '') ?></option>
                <?php endforeach; ?>
            </select>
            <?php /* REMOVED (this adjustment): the "All Status" filter (#statusFilter) was taken out of the filter bar. */ ?>
            <button class="archive-btn" onclick="openArchiveModal()" id="archiveBatchBtn"
                <?php if($totalStudents === 0): ?>disabled title="No active students to archive"<?php endif; ?>>
                <i class="fas fa-box-archive"></i> Archive Batch
            </button>
        </div>

        <?php if($totalStudents === 0): ?>
        <div class="empty-state">
            <i class="fas fa-users-slash"></i>
            <h3>No Students Enrolled</h3>
            <p>The dashboard is clear. New students will appear here once they register for the next OJT batch.</p>
        </div>
        <?php else: ?>

        <!-- ════════════════════════════════════════════
             SECTION 1 — PENDING STUDENTS
        ═════════════════════════════════════════════ -->
        <div class="section-header pending-header" id="pendingSectionHeader">
            <span class="sh-icon"></span>
            <span class="sh-title">Pending Validation</span>
            <span class="sh-count" id="pendingCountBadge"><?= count($pendingStudents) ?></span>
        </div>

        <div class="student-table-header pending-table-header">
            <span>Student Name</span>
            <span>Course</span>
            <span>Requirement Status</span>
        </div>

        <div class="student-list-wrapper pending-wrapper" id="pendingListWrapper">
            <?php if (empty($pendingStudents)): ?>
            <div class="section-empty">
                <i class="fas fa-check-circle" style="color:#bbf7d0;"></i>
                <p>All students have been verified!</p>
            </div>
            <?php else: ?>
            <?php foreach($pendingStudents as $student):
                $user_id = $student['id'];
                $middleName = trim($student['middle_name'] ?? '');
                $fullName = trim($student['first_name'] . ($middleName ? ' ' . $middleName : '') . ' ' . $student['last_name']);
                $isDeployed = ($student['deploy_status'] === 'Deployed');
                $overallStatus = $student['validation_status'] ?? 'Pending';
                $dot = "#8C6C00";

                    /* ══════════════════════════════════════════════════════════════════════
                       UPDATED (this adjustment) — REQUIREMENT LAYOUT FROM company_validation.php
                       The old two-column "Document Verification" list + Profile Photo / Placement
                       Info side column is replaced by company_validation.php's requirement layout:
                       a tab bar, a summary line with a progress bar, and a gallery of document
                       cards (preview on top with the status pill in its top-right corner, the
                       requirement name, the rejection remark, and the Status / Save form below).
                       The Placement Info section is removed entirely. The profile photo is now the
                       first card of the gallery.
                       Every hook the existing JavaScript uses is kept exactly as it was:
                       #req-item-<uid>-<type>, #req-content-<uid>-<type> (with the same label div,
                       locks, placeholders and .ajax-req-form inside), #photo-ctrl-<uid>,
                       .profile-card, .profile-img-large, .awaiting-submission-block, .verified-lock,
                       .deployed-lock, rem_<uid><type>, photo_rem_<uid>. The status pill and the
                       counts follow each card's data-state, kept in sync by svSyncReqCard() /
                       svSyncPhotoCard() in the script below.
                       ══════════════════════════════════════════════════════════════════════ */
                    $svCards = [];
                    $svN     = ['verified' => 0, 'pending' => 0, 'awaiting' => 0];
                    foreach($reqLabels as $type => $label) {
                        $stmt = $conn->prepare("SELECT file_name, status, remark FROM requirements WHERE user_id = ? AND requirement_type = ?");
                        $stmt->bind_param("is",$user_id,$type);
                        $stmt->execute();
                        $res = $stmt->get_result()->fetch_assoc();
                        $stmt->close();
                        $isReqVerified = ($res && $res['status'] === 'Verified');
                        $hasNoFile     = (empty($res) || empty($res['file_name']));
                        $svState       = $isReqVerified ? 'verified' : ($hasNoFile ? 'awaiting' : 'pending');
                        $svRejected    = ($hasNoFile && $res && $res['status'] === 'Denied');
                        $svN[$svState]++;
                        $svCards[] = ['type' => $type, 'label' => $label, 'res' => $res, 'verified' => $isReqVerified, 'noFile' => $hasNoFile, 'state' => $svState, 'rejected' => $svRejected];
                    }
                    $isPhotoVerified = ($student['photo_status'] === 'Verified');
                    $hasNoPhoto      = empty($student['student_photo']);
                    $showPhotoLock   = $isPhotoVerified;
                    $svPhotoState    = $isPhotoVerified ? 'verified' : ($hasNoPhoto ? 'awaiting' : 'pending');
                    $svPhotoRejected = ($hasNoPhoto && ($student['photo_status'] ?? '') === 'Denied');
                    $svN[$svPhotoState]++;
                    $svTotal = count($svCards) + 1;
                    $svPct   = $svTotal > 0 ? (int)round(($svN['verified'] / $svTotal) * 100) : 0;

                /* NEW (this adjustment) — REQUIREMENT STATUS column (same as
                   company_validation.php): instead of the plain "● Pending" tag
                   the cell shows a rounded (ring) percent-verified indicator next
                   to a two-line breakdown, e.g.
                         (33%)   3 of 9 verified
                                 2 pending · 4 awaiting
                   Counted from the very same cards as the extended panel
                   ($svN / $svTotal above). The cell's real text ("● Pending" /
                   "● Verified") stays in the DOM, unpainted, so the status filter
                   and updateOverallStatusUI() keep working untouched;
                   svRecountPanel() keeps the ring + breakdown in step live. */
                $vsLine1 = ($svN['verified'] === $svTotal) ? ('All ' . $svTotal . ' verified') : ($svN['verified'] . ' of ' . $svTotal . ' verified');
                $vsRest  = [];
                if ($svN['pending']  > 0) $vsRest[] = $svN['pending']  . ' pending';
                if ($svN['awaiting'] > 0) $vsRest[] = $svN['awaiting'] . ' awaiting';
                $vsInfoText = $vsLine1 . (count($vsRest) ? "\n" . implode(' · ', $vsRest) : '');
            ?>
            <div class="student-row" id="student-row-<?= $user_id ?>" data-group="pending">
                <input type="checkbox" id="user_<?= $user_id ?>" class="toggle-input" style="display:none;">
                <label for="user_<?= $user_id ?>" class="row-summary" id="row-summary-<?= $user_id ?>">
                    <span><?= htmlspecialchars($fullName ?? '') ?></span>
                    <span style="color:#718096; font-size:13px;"><?= htmlspecialchars($student['course'] ?? '') ?></span>
                    <span class="overall-status-dot" style="color:<?= $dot ?>; font-weight:bold; --vs-pct:<?= $svPct ?>;" data-vpct="<?= $svPct ?>" data-vinfo="<?= htmlspecialchars($vsInfoText ?? '') ?>">● <?= $overallStatus ?></span>
                </label>

                <div class="details-pane">
                    <?php if($isDeployed): ?>
                        <div class="guard-banner warning" style="margin-bottom:20px;">
                            <i class="fas fa-lock"></i>
                            <span>This student is currently <strong>Deployed</strong>. All requirement statuses are locked and cannot be changed until the student is no longer deployed.</span>
                        </div>
                    <?php endif; ?>

                    <div class="cv-panel sv-panel" data-uid="<?= $user_id ?>">

                        <!-- UPDATED (this adjustment): the redundant "Requirements X / Y" tab bar
                             that sat on top of the extended panel was removed — the summary line
                             below already shows the same counts. -->

                        <!-- ═══ REQUIREMENTS — document gallery ═══ -->
                        <div class="cv-tabpanel active" data-tabpanel="req" role="tabpanel">
                            <div class="cv-req-summary">
                                <span class="cv-req-summary-text"><b class="cv-n-total"><?= $svTotal ?></b> requirements &middot; <b class="cv-n-verified"><?= $svN['verified'] ?></b> verified &middot; <b class="cv-n-pending"><?= $svN['pending'] ?></b> pending &middot; <b class="cv-n-awaiting"><?= $svN['awaiting'] ?></b> awaiting the student</span>
                                <div class="cv-progress">
                                    <div class="cv-progress-bar"><div class="cv-progress-fill" style="width:<?= $svPct ?>%;"></div></div>
                                    <span class="cv-progress-pct"><?= $svPct ?>% verified</span>
                                </div>
                            </div>
                            <div class="cv-gallery">

                                <!-- Profile photo card (was the "Profile Photo" side card) -->
                                <div class="req-item cv-req-card profile-card sv-photo-card" id="photo-card-<?= $user_id ?>" data-state="<?= $svPhotoState ?>" data-rejected="<?= $svPhotoRejected ? '1' : '0' ?>">
                                    <div class="cv-card-preview">
                                        <?php if($student['student_photo'] && svBlobIsPdf($student['student_photo'])): ?>
                                            <!-- NEW (this adjustment): a PDF is shown as a PDF tile that opens the document preview modal (#cvReqDocPreviewModal), like company_validation.php -->
                                            <div class="cv-pdf-tile cv-preview-trigger" data-uid="<?= (int) $user_id ?>" data-req-key="photo" data-req-label="Profile Photo (ID)" title="Preview Profile Photo (ID)"><i class="fas fa-file-pdf"></i><span>PDF document</span></div>
                                        <?php elseif($student['student_photo']): ?>
                                            <img src="data:image/jpeg;base64,<?= base64_encode($student['student_photo']) ?>" class="profile-img-large cv-thumb-img" onclick="openPreview(this.src)" style="cursor:pointer;" alt="" title="Click to preview">
                                        <?php else: ?>
                                            <div class="cv-no-file"><i class="fas fa-hourglass-half"></i><span>No file yet</span></div>
                                        <?php endif; ?>
                                        <div class="cv-card-ribbon">
                                            <span class="cv-rb cv-rb-verified"><i class="fas fa-check"></i> Verified</span>
                                            <span class="cv-rb cv-rb-pending">Pending</span>
                                            <span class="cv-rb cv-rb-awaiting">Awaiting</span>
                                            <span class="cv-rb cv-rb-rejected"><i class="fas fa-ban"></i> Rejected</span>
                                        </div>
                                        <div class="cv-rej-placeholder"><i class="fas fa-file-circle-xmark"></i><span>Rejected<br>Awaiting re-submission</span></div>
                                    </div>
                                    <div class="cv-card-body">
                                        <div class="cv-card-label">Profile Photo (ID)</div>
                                        <div class="cv-card-remark"><i class="fas fa-comment-dots"></i><span><b>Remark:</b> <span class="cv-card-remark-text"><?= htmlspecialchars(($student['photo_remark'] ?? '') !== '' ? $student['photo_remark'] : '—') ?></span></span></div>
                                        <div id="photo-ctrl-<?= $user_id ?>">
                                        <?php if($isDeployed): ?>
                                            <div class="deployed-lock" style="justify-content:center; margin-top:10px;"><i class="fas fa-lock"></i> Locked — student is deployed</div>
                                        <?php elseif($showPhotoLock): ?>
                                            <div style="background:#dcfce7; color:#166534; font-size:12px; padding:6px 12px; border-radius:8px; margin-top:8px; font-weight:600;">
                                                <i class="fas fa-check-circle"></i> Photo already verified
                                            </div>
                                        <?php elseif($hasNoPhoto): ?>
                                            <div class="awaiting-submission-block" style="display:flex; align-items:center; justify-content:center; gap:8px; background:#fff7ed; border:1px solid #fed7aa; color:#9a3412; padding:8px 12px; border-radius:8px; margin-top:8px; font-size:12px; font-weight:600;">
                                                <i class="fas fa-hourglass-half"></i> Awaiting student submission
                                            </div>
                                        <?php else: ?>
                                            <form class="update-form ajax-photo-form"
                                                  data-user-id="<?= $user_id ?>"
                                                  style="justify-content:center;">
                                                <input type="hidden" name="user_id" value="<?= $user_id ?>">
                                                <select name="photo_status" onchange="toggleRemark(this,'photo_rem_<?= $user_id ?>')">
                                                    <option value="Pending" <?= ($student['photo_status']=="Pending")?"selected":"" ?>>Pending</option>
                                                    <option value="Verified" <?= ($student['photo_status']=="Verified")?"selected":"" ?>>Verified</option>
                                                    <option value="Denied" <?= ($student['photo_status']=="Denied")?"selected":"" ?>>Denied</option>
                                                </select>
                                                <!-- UPDATED (this adjustment): the rejection remark is free text (it used to be a dropdown of preset reasons) -->
                                                <textarea name="photo_remark" id="photo_rem_<?= $user_id ?>" rows="3" maxlength="<?= (int) svRemarkMaxLen($conn, 'student_information', 'photo_remark') ?>" autocomplete="off" placeholder="Remark (reason for rejection)" style="<?= ($student['photo_status']=="Denied")?'':'display:none' ?>"><?= ($student['photo_status']=="Denied") ? htmlspecialchars($student['photo_remark'] ?? '') : '' ?></textarea>
                                                <button type="submit" style="display:block; width:100%; margin-top:10px;">Update ID</button>
                                            </form>
                                        <?php endif; ?>
                                        </div>
                                    </div>
                                </div>

                                <?php foreach($svCards as $svCard):
                                    $type          = $svCard['type'];
                                    $label         = $svCard['label'];
                                    $res           = $svCard['res'];
                                    $isReqVerified = $svCard['verified'];
                                    $hasNoFile     = $svCard['noFile'];
                                    $showLock      = $isReqVerified;
                                    // NEW (this adjustment): every file stored under this requirement (2+ => the stacked card below)
                                    $svEnts        = svFetchFileEntries($conn, $user_id, $type);
                                    $svEntCount    = count($svEnts);
                                    $svStreamBase  = htmlspecialchars('?stream_student_file=' . (int) $user_id . '&type=' . urlencode($type), ENT_QUOTES);
                                    $svReqLabel    = strip_tags($label ?? '');
                                ?>
                                <div class="req-item cv-req-card" id="req-item-<?= $user_id ?>-<?= $type ?>" data-state="<?= $svCard['state'] ?>" data-rejected="<?= $svCard['rejected'] ? '1' : '0' ?>">
                                    <div class="cv-card-preview">
                                        <?php if($svEntCount > 1): ?>
                                            <!-- NEW (this adjustment): MULTIPLE FILES — ONE overlaying "stacked card" (max 3 layers shown) with a count badge,
                                                 ported from company_validation.php. Clicking it opens the preview modal, which pages through every file. -->
                                            <div class="cv-file-stack-wrap cv-preview-trigger" data-uid="<?= (int) $user_id ?>" data-req-key="<?= htmlspecialchars($type ?? '', ENT_QUOTES) ?>" data-req-label="<?= htmlspecialchars($svReqLabel, ENT_QUOTES) ?>" data-req-files="<?= htmlspecialchars(json_encode($svEnts), ENT_QUOTES) ?>" title="Preview all <?= (int) $svEntCount ?> files for <?= htmlspecialchars($svReqLabel, ENT_QUOTES) ?>">
                                                <div class="cv-file-stack">
                                                    <?php
                                                    $svLayerCount = min(3, $svEntCount);
                                                    for ($svLi = $svLayerCount - 1; $svLi >= 0; $svLi--):
                                                        $svLayerMeta = $svEnts[$svLi];
                                                    ?>
                                                    <?php if ($svLayerMeta['isPdf']): ?>
                                                        <div class="cv-stack-layer cv-stack-layer-pdf layer-<?= $svLi + 1 ?>"><i class="fas fa-file-pdf"></i></div>
                                                    <?php else: ?>
                                                        <div class="cv-stack-layer layer-<?= $svLi + 1 ?>"><img src="<?= $svStreamBase ?>&amp;file_id=<?= (int) $svLayerMeta['id'] ?>" alt="" onerror="this.onerror=null;var p=this.parentNode;if(p){p.classList.add('cv-stack-layer-missing');var i=document.createElement('i');i.className='fas fa-file-circle-xmark';p.replaceChild(i,this);}"></div>
                                                    <?php endif; ?>
                                                    <?php endfor; ?>
                                                    <span class="cv-stack-count-badge"><?= (int) $svEntCount ?></span>
                                                </div>
                                                <div class="cv-file-stack-label"><?= (int) $svEntCount ?> files</div>
                                            </div>
                                        <?php elseif($res && $res['file_name'] && svBlobIsPdf($res['file_name'])): ?>
                                            <!-- NEW (this adjustment): a PDF is shown as a PDF tile that opens the document preview modal (#cvReqDocPreviewModal), like company_validation.php -->
                                            <div class="cv-pdf-tile cv-preview-trigger" data-uid="<?= (int) $user_id ?>" data-req-key="<?= htmlspecialchars($type ?? '', ENT_QUOTES) ?>" data-req-label="<?= htmlspecialchars(strip_tags($label ?? ''), ENT_QUOTES) ?>" title="Preview <?= htmlspecialchars(strip_tags($label ?? ''), ENT_QUOTES) ?>"><i class="fas fa-file-pdf"></i><span>PDF document</span></div>
                                        <?php elseif($res && $res['file_name']): ?>
                                            <img src="data:image/jpeg;base64,<?= base64_encode($res['file_name']) ?>" class="cv-thumb-img" onclick="openPreview(this.src)" style="cursor:pointer;" alt="" title="Click to preview">
                                        <?php else: ?>
                                            <div class="cv-no-file"><i class="fas fa-hourglass-half"></i><span>No file yet</span></div>
                                        <?php endif; ?>
                                        <div class="cv-card-ribbon">
                                            <span class="cv-rb cv-rb-verified"><i class="fas fa-check"></i> Verified</span>
                                            <span class="cv-rb cv-rb-pending">Pending</span>
                                            <span class="cv-rb cv-rb-awaiting">Awaiting</span>
                                            <span class="cv-rb cv-rb-rejected"><i class="fas fa-ban"></i> Rejected</span>
                                        </div>
                                        <div class="cv-rej-placeholder"><i class="fas fa-file-circle-xmark"></i><span>Rejected<br>Awaiting re-submission</span></div>
                                    </div>
                                    <div class="cv-card-body">
                                        <div class="cv-card-remark"><i class="fas fa-comment-dots"></i><span><b>Remark:</b> <span class="cv-card-remark-text"><?= htmlspecialchars(($res['remark'] ?? '') !== '' ? $res['remark'] : '—') ?></span></span></div>
                                        <div class="cv-card-content" id="req-content-<?= $user_id ?>-<?= $type ?>">
                                            <div style="font-weight:600; font-size:13px; margin-bottom:4px;">
                                                <?= $label ?>
                                                <?php if($isReqVerified): ?>
                                                    <span class="verified-badge" style="background:#dcfce7; color:#166534; font-size:10px; padding:2px 7px; border-radius:8px; margin-left:6px; font-weight:700;">✓ VERIFIED</span>
                                                <?php endif; ?>
                                            </div>
                                            <?php if($isDeployed): ?>
                                                <div class="deployed-lock"><i class="fas fa-lock"></i> Locked — student is deployed</div>
                                            <?php elseif($showLock): ?>
                                                <div class="verified-lock" id="req-lock-<?= $user_id ?>-<?= $type ?>"><i class="fas fa-check-circle"></i> Already verified — no further changes allowed</div>
                                            <?php elseif($hasNoFile): ?>
                                                <div class="awaiting-submission-block" style="display:flex; align-items:center; gap:8px; background:#fff7ed; border:1px solid #fed7aa; color:#9a3412; padding:7px 12px; border-radius:6px; font-size:12px; font-weight:600; margin-top:6px;">
                                                    <i class="fas fa-hourglass-half" style="font-size:13px;"></i>
                                                    Awaiting student submission
                                                </div>
                                            <?php else: ?>
                                                <form class="update-form ajax-req-form"
                                                      data-user-id="<?= $user_id ?>"
                                                      data-req-type="<?= $type ?>"
                                                      data-label="<?= htmlspecialchars($label ?? '', ENT_QUOTES) ?>">
                                                    <input type="hidden" name="user_id" value="<?= $user_id ?>">
                                                    <input type="hidden" name="requirement_type" value="<?= $type ?>">
                                                    <select name="status" onchange="toggleRemark(this,'rem_<?= $user_id.$type ?>')">
                                                        <option value="Pending" <?= ($res && $res['status']=="Pending")?"selected":"" ?>>Pending</option>
                                                        <option value="Verified" <?= ($res && $res['status']=="Verified")?"selected":"" ?>>Verified</option>
                                                        <option value="Denied" <?= ($res && $res['status']=="Denied")?"selected":"" ?>>Denied</option>
                                                    </select>
                                                    <!-- UPDATED (this adjustment): the rejection remark is free text (it used to be a dropdown of preset reasons) -->
                                                    <textarea name="remark" id="rem_<?= $user_id.$type ?>" rows="3" maxlength="<?= (int) svRemarkMaxLen($conn, 'requirements', 'remark') ?>" autocomplete="off" placeholder="Remark (reason for rejection)" style="<?= ($res && $res['status']=="Denied")?'':'display:none' ?>"><?= ($res && $res['status']=="Denied") ? htmlspecialchars($res['remark'] ?? '') : '' ?></textarea>
                                                    <button type="submit">Save</button>
                                                </form>
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                </div>
                                <?php endforeach; ?>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
            <?php endforeach; ?>
            <?php endif; ?>
        </div><!-- end pending wrapper -->

        <!-- ── Pending pagination controls ── -->
        <div class="pagination-controls" id="pendingPaginationControls">
            <button class="pg-btn" id="pgPrevBtnPending" onclick="changePage('pending', currentPagePending - 1)">← Prev</button>
            <span id="pgPageNumbersPending" style="display:flex; gap:5px; align-items:center;"></span>
            <button class="pg-btn" id="pgNextBtnPending" onclick="changePage('pending', currentPagePending + 1)">Next →</button>
            <span class="pg-info" id="pgInfoPending"></span>
        </div>

        <!-- ════════════════════════════════════════════
             UPDATED (this adjustment): the "Verified Students" table is gone —
             same as company_validation.php, a student whose requirements are all
             verified simply leaves this page. On page load only students that are
             not yet verified are listed; a student verified while the page is open
             disappears from the table on the spot (graduateStudentRow() in the
             script below) and comes back if that action is undone.
        ═════════════════════════════════════════════ -->

        <?php endif; // end if totalStudents > 0 ?>
    </div><!-- end .container -->
</div><!-- end .main-content -->

<div id="imagePreviewModal" style="display:none;position:fixed;top:0;left:0;width:100%;height:100%;background:rgba(0,0,0,0.9);justify-content:center;align-items:center;z-index:10020;">
    <span onclick="this.parentElement.style.display='none'" style="position:absolute;top:20px;right:40px;font-size:40px;color:white;cursor:pointer;">&times;</span>
    <img id="previewImage" style="max-width:90%; max-height:90%; border-radius:4px;">
</div>

<!-- ── NEW (this adjustment): DOCUMENT PREVIEW MODAL. Ported from company_validation.php's #cvReqDocPreviewModal:
     the same full-bleed dark viewer. A PDF renders in an iframe, an image in the image viewer; when more than one
     file is queued (Full View's submitted documents) prev / next (or the arrow keys) page through them. Opened from
     a PDF tile in the Requirements gallery (.cv-preview-trigger) or a PDF icon in Full View. Driven by
     cvOpenDocPreview() in the script below. -->
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

<!-- ── ARCHIVE VIEWER FAB ── -->
<div id="archiveFab">
    <button id="archiveFabBtn" onclick="openArchiveViewer()" title="View Archived Batches">
        <i class="fas fa-box-archive"></i>
    </button>
    <span id="archiveFabLabel">View Archive</span>
</div>

<!-- ── ARCHIVE VIEWER MODAL ── -->
<div id="archiveViewerOverlay">
    <div id="archiveViewerBox">
        <div id="archiveViewerHeader">
            <h3><i class="fas fa-box-archive" style="color:#7c3aed;"></i> Archived OJT Batches</h3>
            <button id="archiveViewerClose" onclick="closeArchiveViewer()">×</button>
        </div>
        <div id="archiveViewerControls">
            <select id="archiveBatchFilter" onchange="filterArchive()">
                <option value="">All Batches</option>
            </select>
            <input type="text" id="archiveSearchInput" placeholder="Search by name or course…" oninput="filterArchive()">
            <button id="archiveExportFilteredBtn" onclick="exportFilteredArchive()">
                <i class="fas fa-file-excel"></i> Export Filtered
            </button>
            <button id="archiveUnarchiveBtn" disabled title="Select a specific batch to unarchive"
                style="padding:8px 16px;background:#0369a1;color:white;border:none;border-radius:8px;font-size:13px;font-weight:700;cursor:pointer;display:flex;align-items:center;gap:6px;white-space:nowrap;transition:opacity 0.2s;">
                <i class="fas fa-box-open"></i> Unarchive Batch
            </button>
        </div>
        <div id="archiveViewerBody">
            <div id="archiveEmpty" style="display:none;">
                <i class="fas fa-box-open" style="font-size:40px; margin-bottom:12px; display:block; color:#cbd5e0;"></i>
                No archived records found.
            </div>
            <table id="archiveTable" style="display:none;">
                <thead>
                    <tr>
                        <th>Batch</th>
                        <th>Full Name</th>
                        <th>Course</th>
                        <th>Validation</th>
                        <th>Deploy</th>
                        <th>Company</th>
                        <th>Supervisor</th>
                        <th>Archived</th>
                        <th style="text-align:center;">Action</th>
                    </tr>
                </thead>
                <tbody id="archiveTableBody"></tbody>
            </table>
        </div>
    </div>
</div>

<!-- ── BLOCK NOTIFICATION MODAL ── -->
<div id="blockNotifOverlay">
    <div id="blockNotifBox">
        <p class="bn-title">Cannot Unarchive Yet</p>
        <p class="bn-msg" id="blockNotifMsg">There are currently active students in the dashboard. Please archive the current batch first before unarchiving a previous batch.</p>
        <button class="bn-ok" onclick="document.getElementById('blockNotifOverlay').style.display='none'">OK, Got it</button>
    </div>
</div>

<!-- ── UNARCHIVE CONFIRM MODAL ── -->
<div id="unarchiveConfirmOverlay">
    <div id="unarchiveConfirmBox">
        <p class="uc-title">Unarchive This Batch?</p>
        <p class="uc-msg" id="unarchiveConfirmMsg">This will restore all students from this batch back to the active dashboard. They will be visible and manageable again.</p>
        <div class="uc-actions">
            <button class="uc-cancel" onclick="document.getElementById('unarchiveConfirmOverlay').style.display='none'">Cancel</button>
            <button class="uc-go" id="unarchiveConfirmGo"><i class="fas fa-box-open"></i> Yes, Unarchive</button>
        </div>
    </div>
</div>

<!-- ── FULL VIEW APPLICATION MODAL — LETTERHEAD/DOCUMENT STYLE, PAGINATED ──
     UPDATED STRUCTURE: the document is no longer a single ever-growing
     .fv-doc-paper. It now uses the same dynamic A4 pagination approach
     as company_list.php's Digital Resume preview:
       - #fvHeaderClone / #fvFooterClone — hidden letterhead/footer
         templates the JS pagination engine clones onto every page it
         builds (mirrors company_list.php's #drHeaderClone/#drFooterClone).
       - #fvPagesWrap — empty container the JS engine fills with however
         many fixed-height .fv-doc-paper pages the applicant's Skills/
         Experience content actually needs, plus one always-last,
         dedicated "Submitted Documents" page.
       - #fvRawSource — hidden container holding the applicant strip,
         Skills, Experience and Submitted Documents markup that
         openAppFullView() populates via JS exactly as before; this is
         what the pagination engine measures and clones from (mirrors
         company_list.php's #drRaw_<id>).
     The Allow/Deny signature bar (.fv-submit-bar) is unchanged and still
     sits below the paginated pages, inside #appFullViewBox. -->
<div id="appFullViewOverlay">
    <div class="fv-doc-toolbar">
        <div class="fv-doc-toolbar-left">
            <i class="fas fa-file-user" style="color:rgba(255,255,255,0.7);"></i>
            <span class="fv-doc-toolbar-title" id="fvToolbarTitle">Application Review — Full View</span>
        </div>
        <div class="fv-doc-toolbar-right">
            <button class="fv-tbtn-close" onclick="closeAppFullView()"><i class="fas fa-times"></i> Close</button>
        </div>
    </div>
    <div class="fv-doc-canvas">
        <div id="appFullViewBox">

            <!-- Hidden header/footer templates cloned onto every generated page -->
            <div class="fv-header-clone" id="fvHeaderClone">
                <div class="fv-letterhead">
                    <div class="fv-lh-seal"><img src="logo.webp" alt="NEUST Seal"></div>
                    <div class="fv-lh-text">
                        <div class="fv-lh-line1">Republic of the Philippines</div>
                        <div class="fv-lh-line2">Nueva Ecija University of Science and Technology</div>
                        <div class="fv-lh-line3">On&ndash;the&ndash;Job Training and Career Development Center</div>
                        <div class="fv-lh-line4">Atate Campus</div>
                    </div>
                </div>
                <div class="fv-title-band">
                    <h1>OJT Application Review</h1>
                    <div class="fv-form-meta">Student Application Request &mdash; Full View</div>
                </div>
            </div>
            <div class="fv-footer-clone" id="fvFooterClone">
                <div class="fv-footer-band">
                    <span>NEUST&ndash;OJT&ndash;APPREV</span>
                    <span>Application Review</span>
                </div>
            </div>

            <!-- JS-built paginated pages land here -->
            <div class="fv-pages-wrap" id="fvPagesWrap"></div>

            <!-- Hidden raw source — populated by openAppFullView(), measured/cloned by fvRenderPages() -->
            <div class="fv-raw-source" id="fvRawSource">

                <div class="fv-applicant-strip">
                    <div class="fv-avatar2" id="fv-photo-thumb" onclick="if(window._fvCurrentApp && window._fvCurrentApp.photo) openPreview('data:image/jpeg;base64,'+window._fvCurrentApp.photo);"></div>
                    <div style="flex:1; min-width:0;">
                        <div class="fv-resume-mirror-info">
                            <p><b>Full Name:</b> <span id="fv-name-val">&nbsp;</span></p>
                            <p><b>Email:</b> <span id="fv-email-val">&nbsp;</span></p>
                            <p><b>Course:</b> <span id="fv-course-val">&nbsp;</span></p>
                        </div>
                        <div class="fv-applicant-badges">
                            <span class="fv-badge-pill fv-badge-company" id="fv-company"></span>
                            <span class="fv-badge-pill fv-badge-date" id="fv-date-pill"></span>
                            <span class="fv-badge-pill fv-badge-ojtcount" id="fv-ojt-count-pill" style="display:none;"></span>
                            <span class="fv-status-chip fv-badge-photo" id="fv-photo-status"></span>
                        </div>
                    </div>
                </div>

                <div class="fv-section-title fv-skills-title">Skills</div>
                <div class="fv-entries-col fv-skills-col" id="fv-skills"></div>

                <div class="fv-section-title fv-exp-title">Experience</div>
                <div class="fv-entries-col fv-exp-col" id="fv-exp"></div>

                <div class="fv-section-title fv-doc-title">Submitted Documents (click a thumbnail to view)</div>
                <table class="fv-doc-table">
                    <tbody id="fv-docs-grid">
                        <tr class="fv-doc-th"><td>Document</td><td style="width:110px; text-align:center;">Status</td></tr>
                    </tbody>
                </table>

            </div><!-- end #fvRawSource -->

            <div id="fv-date" style="display:none;"></div>

            <div class="fv-submit-bar">
                <div class="fv-submit-bar-note">Review the application above, then Allow or Deny.</div>
                <div class="fv-submit-bar-actions">
                    <button id="fv-allow-btn" class="fv-allow-btn2" onclick="fvHandleAction('allow')">
                        <i class="fas fa-check"></i> Allow Application
                    </button>
                    <button id="fv-deny-btn" class="fv-deny-btn2" onclick="fvHandleAction('deny')">
                        <i class="fas fa-times"></i> Deny Application
                    </button>
                </div>
            </div>
        </div>
    </div>
</div>

<script>
    /* ══════════════════════════════════════════════════════════
       NEW (this adjustment): page-load / processing overlay controls —
       ported from company_validation.php's showGlobalLoading()/
       hideGlobalLoading() (same usage-counter pattern, so overlapping calls
       can never hide it prematurely). The overlay is visible by default
       (see CSS) and fades out once the page has finished loading, with a
       4-second safety net in case a slow external asset delays 'load'.
       ══════════════════════════════════════════════════════════ */
    /* ══════════════════════════════════════════════════════════
       UPDATED (loader sync fix): the overlay sometimes did not show
       when the page was reloaded. Causes that are fixed below:
         1. On reload the browser keeps showing the OLD page while
            the server builds the new one (this file runs many queries
            before any HTML is sent), and nothing turned the overlay on
            during that wait. It now turns on the moment a reload or
            navigation starts (beforeunload, link clicks, and
            the Archive / Restore Batch reloads).
         2. A very fast (cached) load hid the overlay before it was
            ever painted, so it looked like it never activated. The
            first-load overlay now stays up for a short minimum time.
         3. The 'load' handler and the 4-second safety net forced the
            counter to 0, which could also hide an overlay opened by
            showGlobalLoading() for a different task. The first page
            load is now its own "token", released exactly once.
         4. Coming back with Back/Forward (bfcache) could show a stale
            overlay. pageshow now re-syncs it.
       showGlobalLoading()/hideGlobalLoading() keep the same names,
       arguments and counter behaviour as before.
       ══════════════════════════════════════════════════════════ */
    var globalLoadingActiveCount = 1;          // 1 = the initial page-load token (overlay is visible by default)
    var globalLoadingOverlay = document.getElementById('globalLoadingOverlay');
    var globalLoadingLabel   = document.getElementById('globalLoadingLabel');
    var GLOBAL_LOADING_MIN_MS      = 350;      // minimum time the first-load overlay stays visible
    var GLOBAL_LOADING_SAFETY_MS   = 4000;     // existing safety net for slow external assets
    var GLOBAL_LOADING_NAV_STUCK_MS = 15000;   // hide again if a started navigation never actually left the page
    var globalLoadingStartedAt     = (window.performance && performance.now) ? performance.now() : 0;
    var globalLoadingInitialDone   = false;
    var globalLoadingNavigating    = false;
    var globalLoadingNavTimer      = null;

    function globalLoadingPaint() {
        if (!globalLoadingOverlay) return;
        if (globalLoadingActiveCount > 0 || globalLoadingNavigating) {
            globalLoadingOverlay.classList.remove('hidden');
        } else {
            globalLoadingOverlay.classList.remove('gl-instant');
            globalLoadingOverlay.classList.add('hidden');
        }
    }

    function showGlobalLoading(label) {
        globalLoadingActiveCount++;
        if (globalLoadingLabel) globalLoadingLabel.textContent = label || 'Loading';
        globalLoadingPaint();
    }

    function hideGlobalLoading() {
        globalLoadingActiveCount = Math.max(0, globalLoadingActiveCount - 1);
        globalLoadingPaint();
    }

    // Releases the initial page-load token exactly once (after the minimum visible time).
    function finishInitialGlobalLoading() {
        if (globalLoadingInitialDone) return;
        var now = (window.performance && performance.now) ? performance.now() : GLOBAL_LOADING_MIN_MS;
        var wait = Math.max(0, GLOBAL_LOADING_MIN_MS - (now - globalLoadingStartedAt));
        globalLoadingInitialDone = true;
        setTimeout(function () {
            globalLoadingActiveCount = Math.max(0, globalLoadingActiveCount - 1);
            if (globalLoadingLabel && globalLoadingActiveCount === 0) globalLoadingLabel.textContent = 'Loading';
            globalLoadingPaint();
        }, wait);
    }

    // Turns the overlay on instantly when this page is being reloaded / left.
    function startNavigationGlobalLoading(label) {
        globalLoadingNavigating = true;
        if (globalLoadingLabel) globalLoadingLabel.textContent = label || 'Loading';
        if (globalLoadingOverlay) globalLoadingOverlay.classList.add('gl-instant');
        globalLoadingPaint();
        clearTimeout(globalLoadingNavTimer);
        // Safety: if the page is still here long after (navigation was
        // cancelled, or the "link" was really a file download), release it.
        globalLoadingNavTimer = setTimeout(stopNavigationGlobalLoading, GLOBAL_LOADING_NAV_STUCK_MS);
    }

    function stopNavigationGlobalLoading() {
        clearTimeout(globalLoadingNavTimer);
        globalLoadingNavigating = false;
        if (globalLoadingLabel && globalLoadingActiveCount === 0) globalLoadingLabel.textContent = 'Loading';
        globalLoadingPaint();
    }

    // First load: hide when the page has fully loaded (or right away if it already has).
    if (document.readyState === 'complete') {
        finishInitialGlobalLoading();
    } else {
        window.addEventListener('load', finishInitialGlobalLoading);
    }
    setTimeout(finishInitialGlobalLoading, GLOBAL_LOADING_SAFETY_MS);

    // Reload (F5 / Ctrl+R / reload button), link navigation, form submit.
    window.addEventListener('beforeunload', function () {
        startNavigationGlobalLoading('Loading');
    });

    // Same-tab links: show the overlay on click, before beforeunload even fires.
    document.addEventListener('click', function (e) {
        if (e.defaultPrevented || e.button !== 0 || e.metaKey || e.ctrlKey || e.shiftKey || e.altKey) return;
        var a = e.target && e.target.closest ? e.target.closest('a[href]') : null;
        if (!a) return;
        var href = a.getAttribute('href') || '';
        if (!href || href.charAt(0) === '#' || /^(javascript|mailto|tel|blob|data):/i.test(href)) return;
        if (a.hasAttribute('download')) return;
        if (a.target && a.target.toLowerCase() !== '_self') return;
        if (a.origin && a.origin !== window.location.origin) return;
        startNavigationGlobalLoading('Loading');
    });

    // Back/Forward cache restore: the page did not reload, so re-sync the overlay.
    window.addEventListener('pageshow', function (e) {
        if (e.persisted) {
            globalLoadingInitialDone = true;
            globalLoadingActiveCount = 0;
            stopNavigationGlobalLoading();
        }
    });

    // ── EXISTING UTILITIES ────────────────────────────────────────────────
    function openPreview(src){ document.getElementById("previewImage").src = src; document.getElementById("imagePreviewModal").style.display="flex"; }

    // ════════════════════════════════════════════════════════════════════════
    //  NEW (this adjustment) — DOCUMENT PREVIEW (PDF + IMAGE), ported from
    //  company_validation.php's openCvReqDocPreview()/renderCvReqDocPreview().
    //  A PDF tile in the Requirements gallery carries data-uid / data-req-key /
    //  data-req-label; clicking it opens the dark viewer, which streams that one
    //  file by ?stream_student_file=…&type=… (a PDF in an iframe, an image in the
    //  image viewer). Full View passes its own list of PDFs (base64) instead,
    //  and prev / next (or the left / right arrow keys) page through the list.
    //  The click handler is delegated on `document`, so it also covers tiles
    //  inserted live by refreshThumb().
    // ════════════════════════════════════════════════════════════════════════
    var cvPrevItems = [], cvPrevIndex = 0, cvPrevBodyOverflow = '', cvPrevBlobUrl = null;
    function cvRevokePrevBlob() { if (cvPrevBlobUrl) { try { URL.revokeObjectURL(cvPrevBlobUrl); } catch (e) {} cvPrevBlobUrl = null; } }
    function cvOpenDocPreview(items, index) {
        if (!items || !items.length) return;
        cvPrevItems = items;
        cvPrevIndex = (index >= 0 && index < items.length) ? index : 0;
        renderCvDocPreview();
        document.getElementById('cvReqDocPreviewModal').style.display = 'flex';
        if (!cvPrevBodyOverflow) cvPrevBodyOverflow = document.body.style.overflow || ' ';
        document.body.style.overflow = 'hidden';
    }
    function cvStepDocPreview(delta) {
        if (cvPrevItems.length < 2) return;
        cvPrevIndex = (cvPrevIndex + delta + cvPrevItems.length) % cvPrevItems.length;
        renderCvDocPreview();
    }
    function cvDocItemSrc(item) {
        if (item.src) return item.src;
        if (item.b64) {   // a file held in the page as base64 (Full View) -> a temporary blob URL
            try {
                var bin = atob(item.b64), len = bin.length, bytes = new Uint8Array(len);
                for (var i = 0; i < len; i++) bytes[i] = bin.charCodeAt(i);
                cvPrevBlobUrl = URL.createObjectURL(new Blob([bytes], { type: item.isPdf ? 'application/pdf' : 'image/jpeg' }));
                return cvPrevBlobUrl;
            } catch (e) { return ''; }
        }
        return '';
    }
    function renderCvDocPreview() {
        var item = cvPrevItems[cvPrevIndex]; if (!item) return;
        var viewerWrap = document.getElementById('cvReqDocPreviewViewerWrap');
        var nameLabel  = document.getElementById('cvReqDocPreviewName');
        var iconEl     = document.getElementById('cvReqDocPreviewIcon');
        var counterEl  = document.getElementById('cvReqDocPreviewCounter');
        var label = item.label || 'Document';
        cvRevokePrevBlob();
        if (nameLabel) nameLabel.textContent = label;
        viewerWrap.innerHTML = '';
        viewerWrap.classList.remove('image-mode');
        var src = cvDocItemSrc(item);

        function unavailable() {
            viewerWrap.classList.remove('image-mode');
            viewerWrap.innerHTML = '';
            var msg = document.createElement('div');
            msg.className = 'cv-doc-modal-unavailable';
            msg.innerHTML = '<i class="fas fa-file-circle-xmark"></i><span>Preview unavailable</span>';
            viewerWrap.appendChild(msg);
        }
        if (!src) {
            if (iconEl) iconEl.className = 'fas fa-file-circle-xmark cv-doc-modal-icon';
            unavailable();
        } else if (item.isPdf) {
            if (iconEl) iconEl.className = 'fas fa-file-pdf cv-doc-modal-icon';
            var iframe = document.createElement('iframe');
            iframe.title = label;
            iframe.src = src;
            viewerWrap.appendChild(iframe);
        } else {
            if (iconEl) iconEl.className = 'fas fa-image cv-doc-modal-icon';
            viewerWrap.classList.add('image-mode');
            var img = document.createElement('img');
            img.className = 'cv-doc-modal-image';
            img.alt = label;
            // a file that can no longer be streamed back (e.g. removed after a denial) gets a clear message instead of a broken image
            img.onerror = function () { img.onerror = null; unavailable(); };
            img.src = src;
            viewerWrap.appendChild(img);
        }
        if (cvPrevItems.length > 1) {
            var prevBtn = document.createElement('button');
            prevBtn.type = 'button'; prevBtn.className = 'cv-doc-nav-btn cv-doc-nav-prev'; prevBtn.title = 'Previous file';
            prevBtn.innerHTML = '<i class="fas fa-chevron-left"></i>';
            prevBtn.addEventListener('click', function (e) { e.stopPropagation(); cvStepDocPreview(-1); });
            var nextBtn = document.createElement('button');
            nextBtn.type = 'button'; nextBtn.className = 'cv-doc-nav-btn cv-doc-nav-next'; nextBtn.title = 'Next file';
            nextBtn.innerHTML = '<i class="fas fa-chevron-right"></i>';
            nextBtn.addEventListener('click', function (e) { e.stopPropagation(); cvStepDocPreview(1); });
            viewerWrap.appendChild(prevBtn);
            viewerWrap.appendChild(nextBtn);
        }
        if (counterEl) counterEl.textContent = cvPrevItems.length > 1 ? ((cvPrevIndex + 1) + ' / ' + cvPrevItems.length) : '';
    }
    function closeCvReqDocPreview() {
        var modal = document.getElementById('cvReqDocPreviewModal');
        var viewerWrap = document.getElementById('cvReqDocPreviewViewerWrap');
        if (!modal) return;
        modal.style.display = 'none';
        if (viewerWrap) viewerWrap.innerHTML = '';
        cvRevokePrevBlob();
        document.body.style.overflow = (cvPrevBodyOverflow === ' ') ? '' : cvPrevBodyOverflow;
        cvPrevBodyOverflow = '';
        cvPrevItems = [];
    }
    document.addEventListener('click', function (e) {
        var trig = e.target.closest ? e.target.closest('.cv-preview-trigger') : null;
        if (trig) {
            var uid = trig.getAttribute('data-uid'), key = trig.getAttribute('data-req-key');
            if (!uid || !key) return;
            // NEW (this adjustment): a stacked card lists its files in data-req-files ({id, isPdf} each) — page through all of them
            var filesMeta = [];
            try { filesMeta = JSON.parse(trig.getAttribute('data-req-files') || '[]'); } catch (err) { filesMeta = []; }
            if (filesMeta && filesMeta.length) {
                var base = window.location.pathname + '?stream_student_file=' + encodeURIComponent(uid) + '&type=' + encodeURIComponent(key) + '&file_id=';
                var lblMulti = trig.getAttribute('data-req-label') || 'Document';
                cvOpenDocPreview(filesMeta.map(function (m) { return { src: base + encodeURIComponent(m.id) + '&_=' + Date.now(), isPdf: !!m.isPdf, label: lblMulti }; }), 0);
                return;
            }
            cvOpenDocPreview([{
                src: window.location.pathname + '?stream_student_file=' + encodeURIComponent(uid) + '&type=' + encodeURIComponent(key) + '&_=' + Date.now(),
                isPdf: true,
                label: trig.getAttribute('data-req-label') || 'Document'
            }], 0);
            return;
        }
        // clicking the dark backdrop (outside the bar / viewer) closes it
        if (e.target === document.getElementById('cvReqDocPreviewModal')) closeCvReqDocPreview();
    });
    document.addEventListener('keydown', function (e) {
        var modal = document.getElementById('cvReqDocPreviewModal');
        if (!modal || modal.style.display !== 'flex') return;
        if (e.key === 'Escape') closeCvReqDocPreview();
        else if (e.key === 'ArrowLeft') cvStepDocPreview(-1);
        else if (e.key === 'ArrowRight') cvStepDocPreview(1);
    });
    // UPDATED (this adjustment): shows the remark BOX while "Denied" is selected (and puts the cursor in it).
    function toggleRemark(sel,id){
        var el = document.getElementById(id);
        if (!el) return;
        var show = (sel.value === "Denied");
        el.style.display = show ? "inline-block" : "none";
        if (show && el.focus) el.focus();
    }
    // NEW (this adjustment): column limits for the free-text rejection remark (read from the database on the server).
    var SV_REMARK_MAX_REQ   = <?= (int) svRemarkMaxLen($conn, 'requirements', 'remark') ?>;
    var SV_REMARK_MAX_PHOTO = <?= (int) svRemarkMaxLen($conn, 'student_information', 'photo_remark') ?>;
    function svEscHtml(s) {
        return String(s == null ? '' : s).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;');
    }
    // NEW (this adjustment): the remark box is multi-line, but Enter still SAVES (as it did when the remark was a
    // one-line control). Shift+Enter adds a line break; line breaks are folded into single spaces when the remark is sent.
    document.addEventListener('keydown', function (e) {
        if (e.key !== 'Enter' || e.shiftKey || e.isComposing) return;
        var ta = (e.target && e.target.matches && e.target.matches('.ajax-req-form textarea[name="remark"], .ajax-photo-form textarea[name="photo_remark"]')) ? e.target : null;
        if (!ta) return;
        e.preventDefault();
        var f = ta.closest('form');
        if (!f) return;
        if (f.requestSubmit) f.requestSubmit(); else { var b = f.querySelector('button[type="submit"]'); if (b) b.click(); }
    });

    // ══════════════════════════════════════════════════════════════════════
    // PAGINATION ENGINE  — 10 rows per page, independent for each table
    // ══════════════════════════════════════════════════════════════════════
    const ROWS_PER_PAGE = 10;
    let currentPagePending  = 1;
    let currentPageVerified = 1;
    let _filteredPending    = [];
    let _filteredVerified   = [];

    // Collect all rows on page load and run first render
    document.addEventListener('DOMContentLoaded', function () {
        _filteredPending  = Array.from(document.querySelectorAll('.student-row[data-group="pending"]'));
        _filteredVerified = Array.from(document.querySelectorAll('.student-row[data-group="verified"]'));
        renderPage('pending');
        renderPage('verified');
    });

    // Called by search / filter controls
    function filterAll() {
        const search = document.getElementById('searchInput').value.toLowerCase();
        const course = document.getElementById('courseFilter').value;
        // REMOVED (this adjustment): status filter no longer exists, so rows are filtered by search + course only.
        // UPDATED: courses are compared ignoring letter case and extra
        // spaces — the same rule course_offering.php uses — so a student
        // whose saved course differs only in spacing/case still matches.
        const normCourse = function (c) { return String(c || '').replace(/\s+/g, ' ').trim().toLowerCase(); };
        const courseKey  = normCourse(course);

        _filteredPending  = [];
        _filteredVerified = [];

        // Hide every row first
        document.querySelectorAll('.student-row').forEach(function (row) {
            row.style.display = 'none';
            var toggle = row.querySelector('.toggle-input');
            if (toggle) toggle.checked = false;
        });

        document.querySelectorAll('.student-row').forEach(function (row) {
            var group      = row.dataset.group;
            var rowText    = row.querySelector('.row-summary').textContent.toLowerCase();
            var courseEl   = row.querySelector('.row-summary span:nth-child(2)');
            var rowCourse  = courseEl  ? courseEl.textContent  : '';

            var matchSearch = rowText.includes(search);
            var matchCourse = (course === 'All') || (normCourse(rowCourse) === courseKey);

            if (matchSearch && matchCourse) {
                if (group === 'pending')  _filteredPending.push(row);
                if (group === 'verified') _filteredVerified.push(row);
            }
        });

        currentPagePending  = 1;
        currentPageVerified = 1;
        renderPage('pending');
        renderPage('verified');

        document.getElementById('pendingCountBadge').textContent  = _filteredPending.length;
        // UPDATED (this adjustment): the Verified table was removed, so its badge may not exist
        var verifiedBadgeEl = document.getElementById('verifiedCountBadge');
        if (verifiedBadgeEl) verifiedBadgeEl.textContent = _filteredVerified.length;
    }

    // Show only the rows for the current page in a given group
    function renderPage(group) {
        var rows        = (group === 'pending') ? _filteredPending  : _filteredVerified;
        var currentPage = (group === 'pending') ? currentPagePending : currentPageVerified;
        var totalPages  = Math.max(1, Math.ceil(rows.length / ROWS_PER_PAGE));

        // Clamp page
        currentPage = Math.min(Math.max(currentPage, 1), totalPages);
        if (group === 'pending')  currentPagePending  = currentPage;
        else                      currentPageVerified = currentPage;

        var start = (currentPage - 1) * ROWS_PER_PAGE;
        var end   = start + ROWS_PER_PAGE;

        rows.forEach(function (row, i) {
            if (i >= start && i < end) {
                row.style.display = '';
            } else {
                row.style.display = 'none';
                var toggle = row.querySelector('.toggle-input');
                if (toggle) toggle.checked = false;
            }
        });

        renderPaginationUI(group, totalPages, currentPage, rows.length);
    }

    // Change to a specific page number
    function changePage(group, page) {
        var rows       = (group === 'pending') ? _filteredPending  : _filteredVerified;
        var totalPages = Math.max(1, Math.ceil(rows.length / ROWS_PER_PAGE));
        if (page < 1 || page > totalPages) return;
        if (group === 'pending')  currentPagePending  = page;
        else                      currentPageVerified = page;
        renderPage(group);
        // Scroll to the top of the respective section header
        var headerId = (group === 'pending') ? 'pendingSectionHeader' : 'verifiedSectionHeader';
        var headerEl = document.getElementById(headerId);
        if (headerEl) headerEl.scrollIntoView({ behavior: 'smooth', block: 'start' });
    }

    // Build the pagination UI (prev/next/numbers/info) for one group
    function renderPaginationUI(group, totalPages, currentPage, totalCount) {
        var suffix     = (group === 'pending') ? 'Pending' : 'Verified';
        var ctrlEl     = document.getElementById(group + 'PaginationControls');
        var prevBtn    = document.getElementById('pgPrevBtn'     + suffix);
        var nextBtn    = document.getElementById('pgNextBtn'     + suffix);
        var numbersEl  = document.getElementById('pgPageNumbers' + suffix);
        var infoEl     = document.getElementById('pgInfo'        + suffix);

        if (!ctrlEl) return;

        // Hide the whole control bar when everything fits on one page
        ctrlEl.style.display = (totalPages <= 1 && totalCount <= ROWS_PER_PAGE) ? 'none' : 'flex';

        // Prev / Next button states
        prevBtn.disabled      = (currentPage === 1);
        nextBtn.disabled      = (currentPage === totalPages);

        // Page number buttons
        var pages = [];
        if (totalPages <= 7) {
            for (var i = 1; i <= totalPages; i++) pages.push(i);
        } else {
            pages.push(1);
            if (currentPage > 3) pages.push('…');
            var lo = Math.max(2, currentPage - 1);
            var hi = Math.min(totalPages - 1, currentPage + 1);
            for (var j = lo; j <= hi; j++) pages.push(j);
            if (currentPage < totalPages - 2) pages.push('…');
            pages.push(totalPages);
        }

        numbersEl.innerHTML = '';
        pages.forEach(function (p) {
            if (p === '…') {
                var dot = document.createElement('span');
                dot.textContent = '…';
                dot.className   = 'pg-ellipsis';
                numbersEl.appendChild(dot);
            } else {
                var btn = document.createElement('button');
                btn.textContent = p;
                btn.className   = 'pg-number-btn' + (p === currentPage ? ' active' : '');
                if (p !== currentPage) {
                    (function (pg) {
                        btn.addEventListener('click', function () { changePage(group, pg); });
                    })(p);
                }
                numbersEl.appendChild(btn);
            }
        });

        // Info text  "Showing 1–10 of 25 students"
        var start = (totalCount === 0) ? 0 : (currentPage - 1) * ROWS_PER_PAGE + 1;
        var end   = Math.min(currentPage * ROWS_PER_PAGE, totalCount);
        infoEl.textContent = totalCount > 0
            ? 'Showing ' + start + '–' + end + ' of ' + totalCount + ' students'
            : 'No students found';
    }

    // ── SIDEBAR TOGGLE ────────────────────────────────────────────────────
    var sb = document.getElementById('sidebar');
    document.getElementById('toggleBtn').addEventListener('click', function () {
        sb.classList.toggle('collapsed');
    });

    // ── GUARD MODAL ───────────────────────────────────────────────────────
    function closeGuardModal() {
        document.getElementById('guardModal').style.display = 'none';
        if (window.history.replaceState) window.history.replaceState({}, document.title, window.location.pathname);
    }

    function showGuardModal(icon, title, msg) {
        document.getElementById('guardModalIcon').textContent = icon;
        document.getElementById('guardModalIcon').style.display = icon ? '' : 'none';   // UPDATED (this adjustment — no emoji): no icon -> no empty slot above the title
        document.getElementById('guardModalTitle').textContent = title;
        document.getElementById('guardModalMsg').innerHTML = msg;
        document.getElementById('guardModal').style.display = 'flex';
    }

    <?php
    $guardError = $_GET['guard_error'] ?? '';
    $guardField = htmlspecialchars($_GET['field'] ?? '', ENT_QUOTES);
    if ($guardError === 'already_verified'): ?>
        document.addEventListener('DOMContentLoaded', function () {
            showGuardModal('','Already Verified','The requirement <strong><?= addslashes($guardField) ?></strong> is already marked as <strong>Verified</strong>.<br>Its status cannot be changed again.');
        });
    <?php elseif ($guardError === 'deployed'): ?>
        document.addEventListener('DOMContentLoaded', function () {
            showGuardModal('','Student is Deployed','This student is currently <strong>Deployed</strong>.<br>Requirement statuses cannot be changed while a student is on active deployment.');
        });
    <?php elseif ($guardError === 'no_submission'): ?>
        document.addEventListener('DOMContentLoaded', function () {
            showGuardModal('','No Submission Yet','There\'s no existing student requirement for <strong><?= addslashes($guardField) ?></strong>.<br>Please wait until the student submits this requirement.');
        });
    <?php endif; ?>

    // ── UNDO TOAST ────────────────────────────────────────────────────────
    const UNDO_DURATION = 300;
    let undoToken      = null;
    let undoCountdown  = 0;
    let undoTimer      = null;
    /* FIX (countdown drifting while the tab is in the background): the toast used to count by subtracting 1 on
       every setInterval tick. Browsers throttle (or freeze) timers in hidden tabs, so the countdown slowed or
       stopped while the admin was on another site, then still showed time left after the real 5 minutes had
       passed — and Undo answered "expired". The countdown is now measured against a fixed wall-clock deadline
       and re-read on every tick and whenever the tab becomes visible again, so it is always accurate. */
    let undoDeadline   = 0;   // Date.now() value at which the undo window closes
    let undoIsDenied   = false;
    const ring         = document.getElementById('undoRingProgress');
    const ringCircumference = 88;

    /* FIX (Denied card flashing back to "New submission"): a Denied save is only written to the database when the
       Undo toast ends (so Undo can cancel it). Until then the database still holds the student's file as Pending,
       and the live submission poll (pollNewSubmissions) took that for a brand-new upload — it swapped the card's
       "Rejected / awaiting re-submission" view back to the review form + "New submission" for a moment. Cards with
       a Denied action still waiting on its toast are tracked here (key "userId|type", photo = "photo") and the poll
       leaves them alone; the key is released only once the commit / undo request has finished. */
    var _deniedPendingKeys = {};
    function svDeniedKey(ctx) {
        if (!ctx) return null;
        return ctx.userId + '|' + (ctx.type === 'photo' ? 'photo' : ctx.reqType);
    }
    function svHoldDenied(ctx) { var k = svDeniedKey(ctx); if (k) _deniedPendingKeys[k] = (_deniedPendingKeys[k] || 0) + 1; return k; }
    function svReleaseDenied(k) {
        if (!k || !_deniedPendingKeys[k]) return;
        if (--_deniedPendingKeys[k] <= 0) delete _deniedPendingKeys[k];
    }
    function svIsDeniedPending(userId, type) { return !!_deniedPendingKeys[userId + '|' + type]; }

    let undoKey       = null;   // hold key of the toast currently showing a Denied action
    let undoContext   = null;
    let _fvAllApps    = [];
    let _fvCurrentApp = null;

    function startUndoToast(token, label, context, isDenied) {
        if (undoToken && undoToken !== token) {
            if (undoIsDenied) { commitDenied(undoToken, undoKey); }
            else              { sendEmailForToken(undoToken); }
        }

        undoToken     = token;
        undoContext   = context || null;
        undoIsDenied  = !!isDenied;
        undoKey       = isDenied ? svHoldDenied(undoContext) : null;
        undoCountdown = UNDO_DURATION;
        undoDeadline  = Date.now() + UNDO_DURATION * 1000;

        document.getElementById('undoToastLabel').textContent = label;
        document.getElementById('undoBtnMain').disabled = false;

        var statusEl = document.getElementById('undoToastStatus');
        var ringEl   = document.getElementById('undoRingProgress');
        var numEl    = document.getElementById('undoCountNum');
        var toast    = document.getElementById('undoToast');

        if (isDenied) {
            statusEl.textContent = ' Denied pending — undo to cancel';
            statusEl.classList.add('denied-mode');
            ringEl.classList.add('denied-ring');
            numEl.classList.add('denied-num');
            toast.classList.add('denied-pending');
        } else {
            statusEl.textContent = 'Status updated';
            statusEl.classList.remove('denied-mode');
            ringEl.classList.remove('denied-ring');
            numEl.classList.remove('denied-num');
            toast.classList.remove('denied-pending');
        }

        toast.classList.add('show');
        clearInterval(undoTimer);
        updateRing();
        undoTimer = setInterval(undoTick, 250);
    }

    // Recompute the time left from the deadline (never by counting ticks); close + commit when it has run out.
    function undoTick() {
        if (!undoToken) return;
        var left = Math.max(0, Math.ceil((undoDeadline - Date.now()) / 1000));
        if (left > UNDO_DURATION) left = UNDO_DURATION;   // clock moved backwards — never show more than the window
        if (left !== undoCountdown) { undoCountdown = left; updateRing(); }
        if (left <= 0) { dismissUndo(true); }
    }
    // Returning to the page (tab switch, window focus, back/forward cache) re-syncs immediately instead of
    // waiting for a throttled timer.
    document.addEventListener('visibilitychange', function () { if (!document.hidden) undoTick(); });
    window.addEventListener('focus', undoTick);
    window.addEventListener('pageshow', undoTick);

    function updateRing() {
        var fraction = undoCountdown / UNDO_DURATION;
        ring.style.strokeDashoffset = ringCircumference * (1 - fraction);
        var m = Math.floor(undoCountdown / 60);
        var s = undoCountdown % 60;
        document.getElementById('undoCountNum').textContent = m + ':' + String(s).padStart(2,'0');
    }

    function sendEmailForToken(token) {
        if (!token) return;
        var fd = new FormData();
        fd.append('ajax_confirm_send', '1');
        fd.append('undo_token', token);
        fetch(window.location.pathname, { method: 'POST', body: fd }).catch(function(){});
    }

    function commitDenied(token, heldKey) {
        if (!token) { svReleaseDenied(heldKey); return; }
        var fd = new FormData();
        fd.append('ajax_commit_denied', '1');
        fd.append('undo_token', token);
        fetch(window.location.pathname, { method: 'POST', body: fd })
            .catch(function(){})
            .then(function () { setTimeout(function () { svReleaseDenied(heldKey); }, 3000); });   // short grace for a poll already in flight   // the database now says Denied — safe for the poll to read it
    }

    function dismissUndo(commit) {
        clearInterval(undoTimer);
        document.getElementById('undoToast').classList.remove('show');
        document.getElementById('undoToast').classList.remove('denied-pending');
        document.getElementById('undoRingProgress').classList.remove('denied-ring');
        document.getElementById('undoCountNum').classList.remove('denied-num');
        document.getElementById('undoToastStatus').classList.remove('denied-mode');

        if (commit && undoToken) {
            if (undoIsDenied) { commitDenied(undoToken, undoKey); }
            else              { sendEmailForToken(undoToken); }
        } else if (undoIsDenied) {
            svReleaseDenied(undoKey);   // cancelled (Undo) — nothing to commit
        }
        undoKey      = null;
        undoToken    = null;
        undoContext  = null;
        undoIsDenied = false;
    }

    function triggerUndo() {
        undoTick();   // window may have closed while the tab was in the background
        if (!undoToken) return;
        var btn = document.getElementById('undoBtnMain');
        btn.disabled = true;
        btn.textContent = '…';

        var tokenToUndo  = undoToken;
        var isDeniedUndo = undoIsDenied;

        var fd = new FormData();
        fd.append('ajax_undo', '1');
        fd.append('undo_token', tokenToUndo);
        fetch(window.location.pathname, { method: 'POST', body: fd })
            .then(function (r) { return r.json(); })
            .then(function (data) {
                if (data.success) {
                    dismissUndo(false);
                    // NEW (this adjustment): the undone action had verified the student and removed
                    // their row — put it back first so the previous status can be applied to it.
                    if (data.overall_status !== 'Verified') restoreGraduatedRow(data.user_id);
                    if (data.type === 'requirement') {
                        applyReqStatusUI(data.user_id, data.requirement_type, data.prev_status, data.prev_remark);
                        updateOverallStatusUI(data.user_id, data.overall_status);
                    } else if (data.type === 'photo') {
                        applyPhotoStatusUI(data.user_id, data.prev_status, data.prev_remark);
                        updateOverallStatusUI(data.user_id, data.overall_status);
                    }
                } else {
                    dismissUndo(false);
                    alert('Undo failed: ' + data.message);
                    btn.disabled = false;
                    btn.textContent = 'Undo';
                }
            })
            .catch(function () {
                btn.disabled = false;
                btn.textContent = 'Undo';
                alert('Network error during undo.');
            });
    }

    // Flush expired pending emails on page load
    document.addEventListener('DOMContentLoaded', function () {
        var fd = new FormData();
        fd.append('ajax_flush_emails', '1');
        fetch(window.location.pathname, { method: 'POST', body: fd }).catch(function(){});
    });

    // ── IN-PLACE UI UPDATE HELPERS ────────────────────────────────────────

    /* NEW (this adjustment): a requirement's title, read back from its card WITHOUT any badge text. The card's
       title line used to also hold the "NEW SUBMISSION" badge, so reading it back (after a save, or when the next
       file arrived) copied "NEW SUBMISSION" into the title — again on every new upload ("… NEW SUBMISSION NEW
       SUBMISSION"). This also cleans titles that already picked it up. */
    function svCleanReqLabel(text) {
        return String(text || '').replace(/✓\s*VERIFIED/g, '').replace(/\bNEW\s+SUBMISSION\b/gi, '').replace(/\s+/g, ' ').trim();
    }
    /* NEW (this adjustment): the "New upload" tag — the same one company_validation.php shows — on the card's
       preview for a few seconds, instead of a "NEW SUBMISSION" badge inside the title. */
    function svFlagNewUpload(cardEl) {
        var pv = cardEl ? cardEl.querySelector('.cv-card-preview') : null;
        if (!pv) return;
        var old = pv.querySelector('.cv-new-upload-tag');   // already flagged: show it again for the full time
        if (old && old.parentNode) old.parentNode.removeChild(old);
        var tag = document.createElement('span');
        tag.className = 'cv-new-upload-tag';
        tag.innerHTML = '<i class="fas fa-file-arrow-up"></i> New upload';
        pv.appendChild(tag);
        setTimeout(function () { if (tag.parentNode) tag.parentNode.removeChild(tag); }, 8000);
    }

    function applyReqStatusUI(userId, reqType, newStatus, newRemark) {
        var contentEl = document.getElementById('req-content-' + userId + '-' + reqType);
        var itemEl    = document.getElementById('req-item-' + userId + '-' + reqType);
        if (!contentEl) return;

        var labelDiv  = contentEl.querySelector('div[style*="font-weight:600"]');
        var labelText = labelDiv ? svCleanReqLabel(labelDiv.textContent) : reqType;   // UPDATED (this adjustment): badge text never becomes part of the title

        if (newStatus === 'Verified') {
            contentEl.innerHTML = `
                <div style="font-weight:600; font-size:13px; margin-bottom:4px;">
                    ${labelText}
                    <span class="verified-badge" style="background:#dcfce7; color:#166534; font-size:10px; padding:2px 7px; border-radius:8px; margin-left:6px; font-weight:700;">✓ VERIFIED</span>
                </div>
                <div class="verified-lock" id="req-lock-${userId}-${reqType}"><i class="fas fa-check-circle"></i> Already verified — no further changes allowed</div>
            `;
        } else if (newStatus === 'Denied') {
            contentEl.innerHTML = `
                <div style="font-weight:600; font-size:13px; margin-bottom:4px;">${labelText}</div>
                <div class="awaiting-submission-block" style="display:flex; align-items:center; gap:8px; background:#fff7ed; border:1px solid #fed7aa; color:#9a3412; padding:7px 12px; border-radius:6px; font-size:12px; font-weight:600; margin-top:6px;">
                    <i class="fas fa-hourglass-half" style="font-size:13px;"></i>
                    Awaiting student submission
                </div>
            `;
        } else {
            contentEl.innerHTML = `
                <div style="font-weight:600; font-size:13px; margin-bottom:4px;">${labelText}</div>
                <form class="update-form ajax-req-form"
                      data-user-id="${userId}"
                      data-req-type="${reqType}"
                      data-label="${labelText}">
                    <input type="hidden" name="user_id" value="${userId}">
                    <input type="hidden" name="requirement_type" value="${reqType}">
                    <select name="status" onchange="toggleRemark(this,'rem_${userId}${reqType}')">
                        <option value="Pending" selected>Pending</option>
                        <option value="Verified">Verified</option>
                        <option value="Denied">Denied</option>
                    </select>
                    <textarea name="remark" id="rem_${userId}${reqType}" rows="3" maxlength="${SV_REMARK_MAX_REQ}" autocomplete="off" placeholder="Remark (reason for rejection)" style="display:none"></textarea>
                    <button type="submit">Save</button>
                </form>
            `;
            attachReqFormListener(contentEl.querySelector('.ajax-req-form'));
        }

        if (itemEl) {
            itemEl.classList.remove('just-updated');
            void itemEl.offsetWidth;
            itemEl.classList.add('just-updated');
            setTimeout(function () { itemEl.classList.remove('just-updated'); }, 1400);
        }

        svSyncReqCard(userId, reqType, newStatus, newRemark);   // NEW (this adjustment): card status pill / counts
    }

    function applyPhotoStatusUI(userId, newStatus, newRemark) {
        var ctrlEl = document.getElementById('photo-ctrl-' + userId);
        if (!ctrlEl) return;

        if (newStatus === 'Verified') {
            ctrlEl.innerHTML = `
                <div style="background:#dcfce7; color:#166534; font-size:12px; padding:6px 12px; border-radius:8px; margin-top:8px; font-weight:600;">
                    <i class="fas fa-check-circle"></i> Photo already verified
                </div>
            `;
        } else if (newStatus === 'Denied') {
            ctrlEl.innerHTML = `
                <div class="awaiting-submission-block" style="display:flex; align-items:center; justify-content:center; gap:8px; background:#fff7ed; border:1px solid #fed7aa; color:#9a3412; padding:8px 12px; border-radius:8px; margin-top:8px; font-size:12px; font-weight:600;">
                    <i class="fas fa-hourglass-half"></i> Awaiting student submission
                </div>
            `;
        } else {
            ctrlEl.innerHTML = `
                <form class="update-form ajax-photo-form"
                      data-user-id="${userId}"
                      style="justify-content:center;">
                    <input type="hidden" name="user_id" value="${userId}">
                    <select name="photo_status" onchange="toggleRemark(this,'photo_rem_${userId}')">
                        <option value="Pending" selected>Pending</option>
                        <option value="Verified">Verified</option>
                        <option value="Denied">Denied</option>
                    </select>
                    <textarea name="photo_remark" id="photo_rem_${userId}" rows="3" maxlength="${SV_REMARK_MAX_PHOTO}" autocomplete="off" placeholder="Remark (reason for rejection)" style="display:none"></textarea>
                    <button type="submit" style="display:block; width:100%; margin-top:10px;">Update ID</button>
                </form>
            `;
            attachPhotoFormListener(ctrlEl.querySelector('.ajax-photo-form'));
        }

        svSyncPhotoCard(userId, newStatus, newRemark);   // NEW (this adjustment): card status pill / counts
    }

    function updateOverallStatusUI(userId, overallStatus) {
        // UPDATED (this adjustment): the row may currently be out of the page (it was verified and
        // removed) — look it up there too, so its status text is always kept right.
        var row = document.getElementById('student-row-' + userId) ||
                  (_graduatedRows[userId] ? _graduatedRows[userId].row : null);
        if (!row) return;
        var dotEl = row.querySelector('#row-summary-' + userId + ' .overall-status-dot');
        if (!dotEl) return;
        var color = (overallStatus === 'Verified') ? '#2C5A2C' : '#8C6C00';
        dotEl.style.color = color;
        dotEl.textContent = '● ' + overallStatus;

        // UPDATED (this adjustment): there is no Verified table any more (same as
        // company_validation.php). A student who becomes Verified leaves the page;
        // if that is undone, restoreGraduatedRow() brings the row back.
        if (overallStatus === 'Verified' && row.dataset.group === 'pending' && document.body.contains(row)) {
            graduateStudentRow(userId);
        } else if (overallStatus !== 'Verified' && _graduatedRows[userId]) {
            restoreGraduatedRow(userId);
        }
    }

    /* ══════════════════════════════════════════════════════════════════════
       NEW (this adjustment) — VERIFIED STUDENTS LEAVE THE PAGE
       Ported from company_validation.php's removeCompanyRowIfVerified() /
       showCompanyVerifiedToast(): the row fades and collapses, is taken out of
       the table and its pagination, the Pending count drops, and a popup at the
       top confirms it. The row element is kept in memory (_graduatedRows) for
       as long as the page is open, so Undo can put it back exactly as it was.
       ══════════════════════════════════════════════════════════════════════ */
    var _graduatedRows = {};

    function graduateStudentRow(userId) {
        var row = document.getElementById('student-row-' + userId);
        if (!row || _graduatedRows[userId]) return;
        var nameEl = row.querySelector('.row-summary span');
        var studentName = nameEl ? nameEl.textContent.trim() : 'This student';

        _graduatedRows[userId] = { row: row, next: row.nextElementSibling };

        row.style.transition = 'opacity 0.4s ease, max-height 0.4s ease, margin 0.4s ease, padding 0.4s ease';
        row.style.maxHeight  = row.offsetHeight + 'px';
        row.style.overflow   = 'hidden';
        requestAnimationFrame(function () {
            row.style.opacity       = '0';
            row.style.maxHeight     = '0px';
            row.style.marginTop     = '0px';
            row.style.marginBottom  = '0px';
            row.style.paddingTop    = '0px';
            row.style.paddingBottom = '0px';
        });

        setTimeout(function () {
            if (!_graduatedRows[userId]) return;   // undone during the animation
            row.remove();
            _filteredPending = _filteredPending.filter(function (r) { return r !== row; });
            renderPage('pending');
            document.getElementById('pendingCountBadge').textContent = _filteredPending.length;
            svEnsurePendingEmptyState();
        }, 420);

        showStudentVerifiedToast(studentName);
    }

    function restoreGraduatedRow(userId) {
        var entry = _graduatedRows[userId];
        if (!entry) return;
        delete _graduatedRows[userId];
        var row = entry.row;
        var wrapper = document.getElementById('pendingListWrapper');
        if (!wrapper) return;

        // undo the collapse styles
        ['transition','maxHeight','overflow','opacity','marginTop','marginBottom','paddingTop','paddingBottom'].forEach(function (k) { row.style[k] = ''; });

        var emptyEl = wrapper.querySelector('.section-empty');
        if (emptyEl) emptyEl.remove();

        if (!document.body.contains(row)) {
            if (entry.next && entry.next.parentNode === wrapper) wrapper.insertBefore(row, entry.next);
            else wrapper.appendChild(row);
        }
        row.dataset.group = 'pending';
        if (_filteredPending.indexOf(row) === -1) _filteredPending.push(row);
        _filteredPending.sort(function (a, b) {
            return (a.compareDocumentPosition(b) & Node.DOCUMENT_POSITION_FOLLOWING) ? -1 : 1;
        });
        renderPage('pending');
        document.getElementById('pendingCountBadge').textContent = _filteredPending.length;
    }

    function svEnsurePendingEmptyState() {
        var wrapper = document.getElementById('pendingListWrapper');
        if (!wrapper || wrapper.querySelector('.student-row') || wrapper.querySelector('.section-empty')) return;
        var e = document.createElement('div');
        e.className = 'section-empty';
        e.innerHTML = '<i class="fas fa-check-circle" style="color:#bbf7d0;"></i><p>All students have been verified!</p>';
        wrapper.appendChild(e);
    }

    function showStudentVerifiedToast(studentName) {
        var div = document.createElement('div');
        div.className = 'cv-top-toast';
        div.setAttribute('role', 'status');
        div.innerHTML = '<i class="fas fa-user-plus"></i><span><strong>' + escHtml(studentName) + '</strong> has completed all requirements and has been verified.</span>';
        document.body.appendChild(div);
        cvLayoutTopToasts();
        requestAnimationFrame(function () { div.classList.add('show'); });
        setTimeout(function () {
            div.classList.remove('show');
            setTimeout(function () { div.remove(); cvLayoutTopToasts(); }, 400);
        }, 6000);
    }

    /* ══════════════════════════════════════════════════════════════════════
       NEW (this adjustment) — REQUIREMENT CARDS (company_validation.php layout)
       Keeps each card's data-state / data-rejected (which drive the status pill
       and the "Rejected" placeholder), the rejection remark, and the panel's
       summary counts / progress bar in step with every status change.
       ══════════════════════════════════════════════════════════════════════ */
    function svSetCardState(cardEl, state, rejected, remark) {
        if (!cardEl) return;
        cardEl.setAttribute('data-state', state);
        cardEl.setAttribute('data-rejected', rejected ? '1' : '0');
        var remarkEl = cardEl.querySelector('.cv-card-remark-text');
        if (remarkEl && rejected) remarkEl.textContent = remark ? remark : '—';
        svRecountPanel(cardEl.closest('.student-row'));
    }

    function svSyncReqCard(userId, reqType, status, remark) {
        var item = document.getElementById('req-item-' + userId + '-' + reqType);
        if (!item) return;
        var hasFile = !!item.querySelector('.cv-card-preview img, .cv-card-preview .cv-pdf-tile, .cv-card-preview .cv-file-stack-wrap');
        var state = (status === 'Verified') ? 'verified' : ((status === 'Denied') ? 'awaiting' : (hasFile ? 'pending' : 'awaiting'));
        svSetCardState(item, state, status === 'Denied', remark);
    }

    function svSyncPhotoCard(userId, status, remark) {
        var item = document.getElementById('photo-card-' + userId);
        if (!item) return;
        var hasFile = !!item.querySelector('.cv-card-preview img, .cv-card-preview .cv-pdf-tile, .cv-card-preview .cv-file-stack-wrap');
        var state = (status === 'Verified') ? 'verified' : ((status === 'Denied') ? 'awaiting' : (hasFile ? 'pending' : 'awaiting'));
        svSetCardState(item, state, status === 'Denied', remark);
    }

    function svRecountPanel(row) {
        if (!row) return;
        var panel = row.querySelector('.sv-panel');
        if (!panel) return;
        var n = { verified: 0, pending: 0, awaiting: 0 };
        var cards = panel.querySelectorAll('.cv-req-card');
        cards.forEach(function (c) {
            var st = c.getAttribute('data-state');
            if (n[st] !== undefined) n[st]++;
        });
        var total = cards.length;
        var pct = total > 0 ? Math.round((n.verified / total) * 100) : 0;
        var set = function (sel, v) { var el = panel.querySelector(sel); if (el) el.textContent = v; };
        set('.cv-n-total', total);
        set('.cv-n-verified', n.verified);
        set('.cv-n-pending', n.pending);
        set('.cv-n-awaiting', n.awaiting);
        set('.cv-tab-count', n.verified + ' / ' + total);
        set('.cv-progress-pct', pct + '% verified');
        var fill = panel.querySelector('.cv-progress-fill');
        if (fill) fill.style.width = pct + '%';
        // NEW (this adjustment): keep the Requirement Status column's percent ring +
        // "X of Y verified / N pending · N awaiting" breakdown in step (same as company_validation.php).
        var vsCell = row.querySelector('.row-summary .overall-status-dot[data-vpct]');
        if (vsCell && total > 0) {
            var l1 = (n.verified === total) ? ('All ' + total + ' verified') : (n.verified + ' of ' + total + ' verified');
            var rest = [];
            if (n.pending > 0)  rest.push(n.pending + ' pending');
            if (n.awaiting > 0) rest.push(n.awaiting + ' awaiting');
            vsCell.setAttribute('data-vpct', pct);
            vsCell.style.setProperty('--vs-pct', pct);
            vsCell.setAttribute('data-vinfo', rest.length ? (l1 + '\n' + rest.join(' · ')) : l1);
        }
    }

    // ── AJAX FORM SUBMISSION ──────────────────────────────────────────────

    function attachReqFormListener(form) {
        if (!form) return;
        form.addEventListener('submit', function (e) {
            e.preventDefault();
            var userId  = this.dataset.userId;
            var reqType = this.dataset.reqType;
            var label   = this.dataset.label;
            var status  = this.querySelector('[name="status"]').value;
            var remarkEl = this.querySelector('[name="remark"]');
            var remark  = remarkEl ? remarkEl.value.replace(/\s+/g, ' ').trim() : '';   // UPDATED (this adjustment): free text, one line
            var saveBtn = this.querySelector('button[type="submit"]');

            // NEW (this adjustment): rejecting needs a remark — say so right away instead of a round trip to the server.
            if (status === 'Denied' && remark === '') {
                if (remarkEl) { remarkEl.style.display = 'inline-block'; remarkEl.focus(); }
                alert('Please enter a remark explaining why this requirement is being rejected.');
                return;
            }

            saveBtn.disabled = true;
            saveBtn.classList.add('saving');
            saveBtn.textContent = '…';

            var fd = new FormData();
            fd.append('ajax_update_requirement', '1');
            fd.append('user_id', userId);
            fd.append('requirement_type', reqType);
            fd.append('status', status);
            fd.append('remark', remark);

            fetch(window.location.pathname, { method: 'POST', body: fd })
                .then(function (r) { return r.json(); })
                .then(function (data) {
                    if (data.success) {
                        applyReqStatusUI(data.user_id, data.requirement_type, data.new_status, data.new_remark);
                        updateOverallStatusUI(data.user_id, data.overall_status);
                        startUndoToast(data.undo_token, data.undo_label, {
                            type:    'requirement',
                            userId:  data.user_id,
                            reqType: data.requirement_type,
                        }, data.is_denied);
                    } else {
                        saveBtn.disabled = false;
                        saveBtn.classList.remove('saving');
                        saveBtn.textContent = 'Save';

                        if (data.guard === 'deployed') {
                            showGuardModal('','Student is Deployed','This student is currently <strong>Deployed</strong>.<br>Requirement statuses cannot be changed while a student is on active deployment.');
                        } else if (data.guard === 'already_verified') {
                            showGuardModal('','Already Verified','The requirement <strong>' + (data.field||'') + '</strong> is already marked as <strong>Verified</strong>.<br>Its status cannot be changed again.');
                        } else if (data.guard === 'no_submission') {
                            showGuardModal('','No Submission Yet','There\'s no existing student requirement for <strong>' + (data.field||'') + '</strong>.<br>Please wait until the student submits this requirement.');
                        } else if (data.guard === 'no_remark') {
                            alert('Please enter a remark when setting status to Denied.');
                        } else {
                            alert('Failed to save: ' + (data.message || 'Unknown error'));
                        }
                    }
                })
                .catch(function (err) {
                    saveBtn.disabled = false;
                    saveBtn.classList.remove('saving');
                    saveBtn.textContent = 'Save';
                    alert('Network error: ' + err.message);
                });
        });
    }

    function attachPhotoFormListener(form) {
        if (!form) return;
        form.addEventListener('submit', function (e) {
            e.preventDefault();
            var userId  = this.dataset.userId;
            var status  = this.querySelector('[name="photo_status"]').value;
            var remarkEl = this.querySelector('[name="photo_remark"]');
            var remark  = remarkEl ? remarkEl.value.replace(/\s+/g, ' ').trim() : '';   // UPDATED (this adjustment): free text, one line
            var saveBtn = this.querySelector('button[type="submit"]');

            // NEW (this adjustment): rejecting needs a remark — say so right away instead of a round trip to the server.
            if (status === 'Denied' && remark === '') {
                if (remarkEl) { remarkEl.style.display = 'inline-block'; remarkEl.focus(); }
                alert('Please enter a remark explaining why the photo is being rejected.');
                return;
            }

            saveBtn.disabled = true;
            saveBtn.classList.add('saving');
            saveBtn.textContent = '…';

            var fd = new FormData();
            fd.append('ajax_update_photo', '1');
            fd.append('user_id', userId);
            fd.append('photo_status', status);
            fd.append('photo_remark', remark);

            fetch(window.location.pathname, { method: 'POST', body: fd })
                .then(function (r) { return r.json(); })
                .then(function (data) {
                    if (data.success) {
                        applyPhotoStatusUI(data.user_id, data.new_status, data.new_remark);
                        updateOverallStatusUI(data.user_id, data.overall_status);
                        startUndoToast(data.undo_token, data.undo_label, {
                            type:   'photo',
                            userId: data.user_id,
                        }, data.is_denied);
                    } else {
                        saveBtn.disabled = false;
                        saveBtn.classList.remove('saving');
                        saveBtn.textContent = 'Update ID';

                        if (data.guard === 'deployed') {
                            showGuardModal('','Student is Deployed','This student is currently <strong>Deployed</strong>.<br>Requirement statuses cannot be changed while a student is on active deployment.');
                        } else if (data.guard === 'already_verified') {
                            showGuardModal('','Already Verified','The profile photo is already <strong>Verified</strong>.<br>Its status cannot be changed again.');
                        } else if (data.guard === 'no_submission') {
                            showGuardModal('','No Submission Yet','There\'s no profile photo submitted yet.<br>Please wait until the student submits their photo.');
                        } else if (data.guard === 'no_remark') {
                            alert('Please enter a remark when setting status to Denied.');
                        } else {
                            alert('Failed to save: ' + (data.message || 'Unknown error'));
                        }
                    }
                })
                .catch(function (err) {
                    saveBtn.disabled = false;
                    saveBtn.classList.remove('saving');
                    saveBtn.textContent = 'Update ID';
                    alert('Network error: ' + err.message);
                });
        });
    }

    // Attach listeners to all forms on page load
    document.addEventListener('DOMContentLoaded', function () {
        document.querySelectorAll('.ajax-req-form').forEach(attachReqFormListener);
        document.querySelectorAll('.ajax-photo-form').forEach(attachPhotoFormListener);
    });

    // ══════════════════════════════════════════════════════════════════════
    // LIVE SUBMISSION DETECTION
    // Polls the server periodically. When a student uploads a requirement
    // or a profile photo, the corresponding "Awaiting student submission"
    // placeholder is swapped for the live review form automatically — the
    // admin never has to refresh or reload the page to see it appear.
    // ══════════════════════════════════════════════════════════════════════
    const SUBMISSION_POLL_INTERVAL = 12000; // 12 seconds

    function pollNewSubmissions() {
        var fd = new FormData();
        fd.append('ajax_check_submissions', '1');
        fetch(window.location.pathname, { method: 'POST', body: fd })
            .then(function (r) { return r.json(); })
            .then(function (data) {
                if (!data.success || !data.state) return;
                svSyncPlacementReplaced(data);   // NEW (this adjustment): placement replaced → bring the student back
                Object.keys(data.state).forEach(function (uid) {
                    var s = data.state[uid];

                    // Profile photo
                    liveActivatePhotoItem(uid, s.photo, s.deployed);

                    // Requirements
                    Object.keys(s.reqs || {}).forEach(function (type) {
                        liveActivateReqItem(uid, type, s.reqs[type], s.deployed);
                    });
                });
            })
            .catch(function () {});
    }

    /* ══════════════════════════════════════════════════════════════════════
       NEW (this adjustment) — PREFERRED PLACEMENT REPLACED → STUDENT COMES BACK FOR VALIDATION
       When a student replaces the Preference for Placement on company_list.php, the Application SIT is
       deleted and has to be validated again. The server (cv_ph_reconcile) puts such a student back to
       "Pending"; the live check above then reports who is not verified (data.needs_validation) and who is on
       hold (data.held). Here the page reacts without a reload:
         • a student who is missing from the list (verified earlier) gets a fresh row inserted;
         • a student whose open row still shows the OLD Application SIT as received / verified gets that row
           refreshed so the card shows "Awaiting student submission" for the new one.
       The fresh row is taken from this same page (same markup, same PHP), so nothing is duplicated.
       ══════════════════════════════════════════════════════════════════════ */
    var _svRowBusy = {};          // uid → true while a row is being fetched
    var _svRowFailedAt = {};      // uid → time of the last failed fetch (retry after a pause, not every poll)

    function svFetchFreshRow(uid) {
        return fetch(window.location.pathname, { credentials: 'same-origin', cache: 'no-store' })
            .then(function (r) { if (!r.ok) throw new Error('http'); return r.text(); })
            .then(function (html) {
                var doc = new DOMParser().parseFromString(html, 'text/html');
                var fresh = doc.getElementById('student-row-' + uid);
                if (!fresh) return null;
                // remember where it sits in the server's order: the next row that is already on this page
                var next = fresh.nextElementSibling;
                while (next && !(next.classList && next.classList.contains('student-row') && document.getElementById(next.id))) next = next.nextElementSibling;
                return { row: document.importNode(fresh, true), nextId: next ? next.id : null };
            });
    }

    function svRowMatchesFilters(row) {
        var si = document.getElementById('searchInput'), cf = document.getElementById('courseFilter');
        var search = si ? si.value.toLowerCase() : '';
        var course = cf ? cf.value : 'All';
        var norm = function (c) { return String(c || '').replace(/\s+/g, ' ').trim().toLowerCase(); };
        var sum = row.querySelector('.row-summary');
        var courseEl = row.querySelector('.row-summary span:nth-child(2)');
        return (!sum || sum.textContent.toLowerCase().indexOf(search) !== -1) &&
               (course === 'All' || norm(courseEl ? courseEl.textContent : '') === norm(course));
    }

    // Put a freshly fetched row on the page (inserting it, or swapping it for the outdated one).
    function svPlaceFreshRow(uid, fetched) {
        var wrapper = document.getElementById('pendingListWrapper');
        if (!wrapper || !fetched) return null;
        var row = fetched.row;
        var old = document.getElementById('student-row-' + uid);
        var wasOpen = false;

        if (old) {
            var oldToggle = old.querySelector('.toggle-input');
            wasOpen = !!(oldToggle && oldToggle.checked);
            old.replaceWith(row);
            var oi = _filteredPending.indexOf(old);
            if (oi !== -1) _filteredPending[oi] = row;
        } else {
            var emptyEl = wrapper.querySelector('.section-empty');
            if (emptyEl) emptyEl.remove();
            var nextEl = fetched.nextId ? document.getElementById(fetched.nextId) : null;
            if (nextEl && nextEl.parentNode === wrapper) wrapper.insertBefore(row, nextEl); else wrapper.appendChild(row);
            if (svRowMatchesFilters(row) && _filteredPending.indexOf(row) === -1) _filteredPending.push(row);
        }
        delete _graduatedRows[uid];   // any in-memory copy of this row is outdated now
        row.dataset.group = 'pending';
        if (wasOpen) { var nt = row.querySelector('.toggle-input'); if (nt) nt.checked = true; }
        row.querySelectorAll('.ajax-req-form').forEach(attachReqFormListener);
        row.querySelectorAll('.ajax-photo-form').forEach(attachPhotoFormListener);

        _filteredPending.sort(function (a, b) {
            return (a.compareDocumentPosition(b) & Node.DOCUMENT_POSITION_FOLLOWING) ? -1 : 1;
        });
        renderPage('pending');
        var badge = document.getElementById('pendingCountBadge');
        if (badge) badge.textContent = _filteredPending.length;
        return row;
    }

    // Resolves with the student's row (fetching it when it is not on the page); null if it cannot be shown.
    function svEnsureStudentRow(uid, forceRefresh) {
        var existing = document.getElementById('student-row-' + uid);
        if (existing && !forceRefresh) return Promise.resolve(existing);
        if (_svRowBusy[uid]) return Promise.resolve(existing || null);
        _svRowBusy[uid] = true;
        return svFetchFreshRow(uid)
            .then(function (fetched) {
                if (!fetched) return existing || null;
                return svPlaceFreshRow(uid, fetched) || existing || null;
            })
            .catch(function () { _svRowFailedAt[uid] = Date.now(); return existing || null; })
            .then(function (res) { delete _svRowBusy[uid]; return res; });
    }

    var _svAnnouncedHold = {};
    function svSyncPlacementReplaced(data) {
        var needs = Array.isArray(data.needs_validation) ? data.needs_validation : [];
        var held  = {};
        (Array.isArray(data.held) ? data.held : []).forEach(function (id) { held[String(id)] = true; });
        var schedHeld = {};   // NEW (this adjustment): schedule changed by the supervisor → the Application SIT needs the new upload
        (Array.isArray(data.schedule_held) ? data.schedule_held : []).forEach(function (id) { schedHeld[String(id)] = true; });

        needs.forEach(function (n) {
            var uid = String(n.id);
            if (_svRowBusy[uid]) return;
            if (_svRowFailedAt[uid] && Date.now() - _svRowFailedAt[uid] < 30000) return;
            var row = document.getElementById('student-row-' + uid);
            var st  = data.state ? data.state[uid] : null;
            var isSched = !!schedHeld[uid] && !held[uid];
            var chkType = 'application_sit';   // a placement replacement AND a schedule change both need a new Application SIT
            var sit = st && st.reqs ? st.reqs[chkType] : null;
            var hasSit = !!(sit && sit.has_file);

            if (!row) {
                // not on the page (verified earlier): bring the student back — only a student on hold is a placement
                // replacement, any other not-verified student not shown here is left to the normal page load.
                if (!held[uid] && !schedHeld[uid]) return;
                svEnsureStudentRow(uid).then(function (r) { if (r) _svAnnouncedHold[uid] = true; });   // UPDATED (this adjustment): announced by the notification popup (cv_ph_detect), not here
                return;
            }
            if ((!held[uid] && !schedHeld[uid]) || hasSit) return;
            // row is on the page: refresh it only while it still shows the OLD requirement (Application SIT / contract) as received / verified
            var card = document.getElementById('req-item-' + uid + '-' + chkType);
            var cardState = card ? card.getAttribute('data-state') : null;
            if (card && cardState && cardState !== 'awaiting' && !svIsDeniedPending(uid, chkType)) {
                _svRowFailedAt[uid] = Date.now();   // at most one refresh per 30 seconds for the same student
                svEnsureStudentRow(uid, true).then(function (r) { if (r) _svAnnouncedHold[uid] = true; });
            }
        });
        // forget students no longer on hold, so a later replacement is announced again
        Object.keys(_svAnnouncedHold).forEach(function (uid) { if (!held[uid] && !schedHeld[uid]) delete _svAnnouncedHold[uid]; });
    }

    // Swap a requirement's "Awaiting student submission" placeholder for the
    // live review form the instant a file becomes available. It only acts
    // on items that are still showing that placeholder, so it never touches
    // a row the admin is already reviewing, has verified, or has denied.
    function liveActivateReqItem(userId, type, curReq, isDeployed) {
        if (!curReq || !curReq.has_file) return;
        if (svIsDeniedPending(userId, type)) return;   // Denied, waiting for its Undo toast to end — not a new upload
        var contentEl = document.getElementById('req-content-' + userId + '-' + type);
        if (!contentEl) return;
        var placeholder = contentEl.querySelector('.awaiting-submission-block');
        if (!placeholder) return; // not currently in the "awaiting" state — leave it alone

        var itemEl    = document.getElementById('req-item-' + userId + '-' + type);
        var labelDiv  = contentEl.querySelector('div[style*="font-weight:600"]');
        var labelText = labelDiv ? svCleanReqLabel(labelDiv.textContent) : type;   // UPDATED (this adjustment)

        if (isDeployed) {
            contentEl.innerHTML =
                '<div style="font-weight:600; font-size:13px; margin-bottom:4px;">' + labelText + '</div>' +
                '<div class="deployed-lock"><i class="fas fa-lock"></i> Locked — student is deployed</div>';
        } else {
            var statusVal = curReq.status || 'Pending';
            var remarkVal = (statusVal === 'Denied') ? svEscHtml(curReq.remark || '') : '';

            contentEl.innerHTML =
                '<div style="font-weight:600; font-size:13px; margin-bottom:4px;">' + labelText + '</div>' +   // UPDATED (this adjustment): the title only — the "New upload" tag goes on the preview
                '<form class="update-form ajax-req-form" data-user-id="' + userId + '" data-req-type="' + type + '" data-label="' + labelText + '">' +
                    '<input type="hidden" name="user_id" value="' + userId + '">' +
                    '<input type="hidden" name="requirement_type" value="' + type + '">' +
                    '<select name="status" onchange="toggleRemark(this,\'rem_' + userId + type + '\')">' +
                        '<option value="Pending"' + (statusVal === 'Pending' ? ' selected' : '') + '>Pending</option>' +
                        '<option value="Verified"' + (statusVal === 'Verified' ? ' selected' : '') + '>Verified</option>' +
                        '<option value="Denied"' + (statusVal === 'Denied' ? ' selected' : '') + '>Denied</option>' +
                    '</select>' +
                    '<textarea name="remark" id="rem_' + userId + type + '" rows="3" maxlength="' + SV_REMARK_MAX_REQ + '" autocomplete="off" placeholder="Remark (reason for rejection)" style="' + (statusVal === 'Denied' ? '' : 'display:none') + '">' + remarkVal + '</textarea>' +
                    '<button type="submit">Save</button>' +
                '</form>';
            attachReqFormListener(contentEl.querySelector('.ajax-req-form'));
        }

        if (itemEl) {
            itemEl.classList.remove('just-updated');
            void itemEl.offsetWidth;
            itemEl.classList.add('just-updated');
            setTimeout(function () { itemEl.classList.remove('just-updated'); }, 1400);
        }

        svSetCardState(itemEl, 'pending', false, '');   // NEW (this adjustment): a new file is in — card shows "Pending"
        if (!isDeployed) svFlagNewUpload(itemEl);        // NEW (this adjustment): same "New upload" tag as company_validation.php
        refreshThumb(userId, type);
    }

    // Same idea, for the profile photo control.
    function liveActivatePhotoItem(userId, curPhoto, isDeployed) {
        if (!curPhoto || !curPhoto.has_file) return;
        if (svIsDeniedPending(userId, 'photo')) return;   // Denied, waiting for its Undo toast to end — not a new upload
        var ctrlEl = document.getElementById('photo-ctrl-' + userId);
        if (!ctrlEl) return;
        var placeholder = ctrlEl.querySelector('.awaiting-submission-block');
        if (!placeholder) return;

        if (isDeployed) {
            ctrlEl.innerHTML = '<div class="deployed-lock" style="justify-content:center; margin-top:10px;"><i class="fas fa-lock"></i> Locked — student is deployed</div>';
        } else {
            var statusVal = curPhoto.status || 'Pending';
            var remarkVal = (statusVal === 'Denied') ? svEscHtml(curPhoto.remark || '') : '';

            ctrlEl.innerHTML =   // UPDATED (this adjustment): no "NEW SUBMISSION" line — the "New upload" tag goes on the photo card's preview
                '<form class="update-form ajax-photo-form" data-user-id="' + userId + '" style="justify-content:center;">' +
                    '<input type="hidden" name="user_id" value="' + userId + '">' +
                    '<select name="photo_status" onchange="toggleRemark(this,\'photo_rem_' + userId + '\')">' +
                        '<option value="Pending"' + (statusVal === 'Pending' ? ' selected' : '') + '>Pending</option>' +
                        '<option value="Verified"' + (statusVal === 'Verified' ? ' selected' : '') + '>Verified</option>' +
                        '<option value="Denied"' + (statusVal === 'Denied' ? ' selected' : '') + '>Denied</option>' +
                    '</select>' +
                    '<textarea name="photo_remark" id="photo_rem_' + userId + '" rows="3" maxlength="' + SV_REMARK_MAX_PHOTO + '" autocomplete="off" placeholder="Remark (reason for rejection)" style="' + (statusVal === 'Denied' ? '' : 'display:none') + '">' + remarkVal + '</textarea>' +
                    '<button type="submit" style="display:block; width:100%; margin-top:10px;">Update ID</button>' +
                '</form>';
            attachPhotoFormListener(ctrlEl.querySelector('.ajax-photo-form'));
            svFlagNewUpload(ctrlEl.closest('.profile-card') || (document.getElementById('student-row-' + userId) || document).querySelector('.profile-card'));   // NEW (this adjustment)
        }

        svSetCardState(document.getElementById('photo-card-' + userId), 'pending', false, '');   // NEW (this adjustment)
        refreshThumb(userId, 'photo');
    }

    // Pull the newly-uploaded image itself so the thumbnail / profile photo
    // refreshes in place too, instead of leaving a stale "N/A" placeholder.
    function refreshThumb(userId, type) {
        var fd = new FormData();
        fd.append('ajax_get_file', '1');
        fd.append('user_id', userId);
        fd.append('type', type);
        fetch(window.location.pathname, { method: 'POST', body: fd })
            .then(function (r) { return r.json(); })
            .then(function (data) {
                if (!data.success || !data.file) return;
                var src = 'data:image/jpeg;base64,' + data.file;

                // NEW (this adjustment): the requirement now holds 2+ files — show (or refresh) the stacked card
                var stackHost = (type !== 'photo') ? document.getElementById('req-item-' + userId + '-' + type) : null;
                var stackPv   = stackHost ? stackHost.querySelector('.cv-card-preview') : null;
                var curStack  = stackPv ? stackPv.querySelector('.cv-file-stack-wrap') : null;
                if (stackPv && data.files && data.files.length > 1) {
                    var wrap = document.createElement('div');
                    wrap.className = 'cv-file-stack-wrap cv-preview-trigger';
                    wrap.setAttribute('data-uid', userId);
                    wrap.setAttribute('data-req-key', type);
                    var sLbl = String((stackHost.querySelector('.cv-card-content > div') || {}).textContent || '').replace(/✓\s*VERIFIED/g, '').replace(/\bNEW\s+SUBMISSION\b/gi, '').replace(/\s+/g, ' ').trim() || 'Document';
                    wrap.setAttribute('data-req-label', sLbl);
                    wrap.setAttribute('data-req-files', JSON.stringify(data.files));
                    wrap.title = 'Preview all ' + data.files.length + ' files for ' + sLbl;
                    var stackBase = window.location.pathname + '?stream_student_file=' + encodeURIComponent(userId) + '&type=' + encodeURIComponent(type) + '&file_id=';
                    var layers = '';
                    for (var li = Math.min(3, data.files.length) - 1; li >= 0; li--) {
                        var fm = data.files[li];
                        layers += fm.isPdf
                            ? '<div class="cv-stack-layer cv-stack-layer-pdf layer-' + (li + 1) + '"><i class="fas fa-file-pdf"></i></div>'
                            : '<div class="cv-stack-layer layer-' + (li + 1) + '"><img src="' + stackBase + encodeURIComponent(fm.id) + '&_=' + Date.now() + '" alt=""></div>';
                    }
                    wrap.innerHTML = '<div class="cv-file-stack">' + layers + '<span class="cv-stack-count-badge">' + data.files.length + '</span></div><div class="cv-file-stack-label">' + data.files.length + ' files</div>';
                    var curMain = stackPv.querySelector('img.cv-thumb-img, .cv-pdf-tile, .cv-no-file, .cv-file-stack-wrap');
                    if (curMain) curMain.replaceWith(wrap); else stackPv.insertBefore(wrap, stackPv.firstChild);
                    return;
                }
                // back to a single file: drop the stacked card so the normal single-file refresh below can take over
                if (curStack) {
                    var blank = document.createElement('div');
                    blank.className = 'cv-no-file';
                    curStack.replaceWith(blank);
                }

                // NEW (this adjustment): a PDF was submitted — show the PDF tile that opens the document preview modal
                if (data.isPdf) {
                    var pdfHost = (type === 'photo')
                        ? ((document.getElementById('student-row-' + userId) || document).querySelector('.profile-card'))
                        : document.getElementById('req-item-' + userId + '-' + type);
                    var pdfPv = pdfHost ? pdfHost.querySelector('.cv-card-preview') : null;
                    if (!pdfPv) return;
                    var pdfOld = pdfPv.querySelector('img, .cv-pdf-tile, .cv-no-file');
                    var tile = document.createElement('div');
                    tile.className = 'cv-pdf-tile cv-preview-trigger';
                    tile.setAttribute('data-uid', userId);
                    tile.setAttribute('data-req-key', type);
                    var lbl = (type === 'photo') ? 'Profile Photo (ID)' : String((pdfHost.querySelector('.cv-card-content > div') || {}).textContent || '').replace(/✓\s*VERIFIED/g, '').replace(/\bNEW\s+SUBMISSION\b/gi, '').replace(/\s+/g, ' ').trim();
                    lbl = lbl || 'Document';
                    tile.setAttribute('data-req-label', lbl);
                    tile.title = 'Preview ' + lbl;
                    tile.innerHTML = '<i class="fas fa-file-pdf"></i><span>PDF document</span>';
                    if (pdfOld) pdfOld.replaceWith(tile); else pdfPv.insertBefore(tile, pdfPv.firstChild);
                    return;
                }

                if (type === 'photo') {
                    var row = document.getElementById('student-row-' + userId);
                    if (!row) return;
                    var imgEl = row.querySelector('.profile-img-large');
                    if (imgEl) {
                        imgEl.src = src;
                    } else {
                        var card = row.querySelector('.profile-card');
                        // UPDATED (this adjustment): the empty photo slot is now the card's "No file yet" tile
                        var ph = card ? (card.querySelector('.cv-card-preview .cv-no-file, .cv-card-preview .cv-pdf-tile') || card.querySelector('div[style*="background:#eee"]')) : null;
                        if (ph) {
                            var newImg = document.createElement('img');
                            newImg.src = src;
                            newImg.className = 'profile-img-large cv-thumb-img';
                            newImg.style.cursor = 'pointer';
                            newImg.onclick = function () { openPreview(src); };
                            ph.replaceWith(newImg);
                        }
                    }
                } else {
                    var itemEl = document.getElementById('req-item-' + userId + '-' + type);
                    if (!itemEl) return;
                    var imgEl2 = itemEl.querySelector('img');
                    if (imgEl2) {
                        imgEl2.src = src;
                    } else {
                        // UPDATED (this adjustment): the empty slot is now the card's "No file yet" tile
                        var ph2 = itemEl.querySelector('.cv-card-preview .cv-no-file, .cv-card-preview .cv-pdf-tile') || itemEl.querySelector('div[style*="background:#eee"]');
                        if (ph2) {
                            var newImg2 = document.createElement('img');
                            newImg2.src = src;
                            newImg2.className = 'cv-thumb-img';
                            newImg2.style.cursor = 'pointer';
                            newImg2.onclick = function () { openPreview(src); };
                            ph2.replaceWith(newImg2);
                        }
                    }
                }
            })
            .catch(function () {});
    }

    setTimeout(function () { pollNewSubmissions(); setInterval(pollNewSubmissions, SUBMISSION_POLL_INTERVAL); }, 6000);

    // ── AUTO-OPEN STUDENT DETAILS AFTER REDIRECT ──────────────────────────
    (function restoreOpenStudent() {
        var params = new URLSearchParams(window.location.search);
        var openId = params.get('open_user');
        if (!openId) return;

        var checkbox = document.getElementById('user_' + openId);
        if (!checkbox) return;

        checkbox.checked = true;

        requestAnimationFrame(function () {
            requestAnimationFrame(function () {
                var label = document.querySelector('label[for="user_' + openId + '"]');
                if (label) {
                    label.scrollIntoView({ behavior: 'smooth', block: 'start' });
                }
            });
        });

        if (window.history.replaceState) {
            params.delete('open_user');
            var newQuery = params.toString();
            var newUrl   = window.location.pathname + (newQuery ? '?' + newQuery : '');
            window.history.replaceState({}, document.title, newUrl);
        }
    })();

    // ── ARCHIVE MODAL ─────────────────────────────────────────────────────
    var totalStudents = <?= $totalStudents ?>;

    function openArchiveModal() {
        if (totalStudents === 0) {
            showGuardModal('','Nothing to Archive','There are no active students in the dashboard to archive. Register new students first.');
            return;
        }
        document.getElementById('archStudentCount').textContent = totalStudents;
        var now = new Date();
        var year = now.getFullYear();
        var month = now.getMonth() + 1;
        var sem = (month >= 6 && month <= 11) ? '1st Semester' : '2nd Semester';
        document.getElementById('batchLabelInput').value = 'OJT Batch ' + year + ' — ' + sem;
        document.getElementById('archiveModal').style.display = 'flex';
        document.getElementById('archProgress').style.display = 'none';
        document.getElementById('archConfirmBtn').disabled = false;
        document.getElementById('archExportBtn').disabled = false;
    }

    document.getElementById('archCancelBtn').addEventListener('click', function () {
        document.getElementById('archiveModal').style.display = 'none';
    });

    document.getElementById('archExportBtn').addEventListener('click', function () {
        var btn = document.getElementById('archExportBtn');
        btn.disabled = true;
        btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Exporting…';

        var fd = new FormData();
        fd.append('ajax_export_batch', '1');
        fetch(window.location.pathname, { method: 'POST', body: fd })
            .then(function (r) { return r.json(); })
            .then(function (data) {
                if (!data.success || !data.data.length) {
                    alert('No student data to export.');
                    btn.disabled = false;
                    btn.innerHTML = '<i class="fas fa-file-excel"></i> Export Excel';
                    return;
                }

                var label = document.getElementById('batchLabelInput').value.trim() || 'OJT_Batch';
                var safeLabel = label.replace(/[^a-zA-Z0-9_\-]/g, '_');

                var cols = Object.keys(data.data[0]);
                var html = '<table><thead><tr>' + cols.map(function (c) { return '<th>' + c + '</th>'; }).join('') + '</tr></thead><tbody>';
                data.data.forEach(function (row) {
                    html += '<tr>' + cols.map(function (c) { return '<td>' + row[c] + '</td>'; }).join('') + '</tr>';
                });
                html += '</tbody></table>';

                var blob = new Blob(['\ufeff', html], { type: 'application/vnd.ms-excel' });
                var url  = URL.createObjectURL(blob);
                var a    = document.createElement('a');
                a.href = url; a.download = safeLabel + '.xls'; a.click();
                URL.revokeObjectURL(url);

                btn.disabled = false;
                btn.innerHTML = '<i class="fas fa-file-excel"></i> Export Excel';
            })
            .catch(function () {
                alert('Export failed. Please try again.');
                btn.disabled = false;
                btn.innerHTML = '<i class="fas fa-file-excel"></i> Export Excel';
            });
    });

    document.getElementById('archConfirmBtn').addEventListener('click', function () {
        var label = document.getElementById('batchLabelInput').value.trim();
        if (!label) {
            document.getElementById('batchLabelInput').focus();
            return;
        }

        document.getElementById('archConfirmBtn').disabled = true;
        document.getElementById('archExportBtn').disabled  = true;
        document.getElementById('archCancelBtn').disabled  = true;
        document.getElementById('archProgress').style.display = 'block';

        var fd = new FormData();
        fd.append('ajax_archive_batch', '1');
        fd.append('batch_label', label);
        fetch(window.location.pathname, { method: 'POST', body: fd })
            .then(function (r) { return r.text(); })
            .then(function (raw) {
                var data;
                try { data = JSON.parse(raw); }
                catch(e) {
                    alert('Archive server error:\n' + raw.substring(0, 400));
                    document.getElementById('archConfirmBtn').disabled = false;
                    document.getElementById('archExportBtn').disabled  = false;
                    document.getElementById('archCancelBtn').disabled  = false;
                    document.getElementById('archProgress').style.display = 'none';
                    return;
                }
                if (data.success) {
                    document.getElementById('archiveModal').style.display = 'none';
                    showGuardModal('','Batch Archived!','<strong>' + data.archived + '</strong> student records from <strong>' + data.batch + '</strong> have been archived successfully. The dashboard has been cleared for the next OJT batch.');
                    document.querySelector('#guardModal button').addEventListener('click', function () {
                        startNavigationGlobalLoading('Loading'); // UPDATED (loader sync fix): show the loader before reloading
                        window.location.reload();
                    }, { once: true });
                } else {
                    alert('Archive failed: ' + (data.message || 'Unknown error'));
                    document.getElementById('archConfirmBtn').disabled = false;
                    document.getElementById('archExportBtn').disabled  = false;
                    document.getElementById('archCancelBtn').disabled  = false;
                    document.getElementById('archProgress').style.display = 'none';
                }
            })
            .catch(function (err) {
                alert('Network error during archive: ' + err.message);
                document.getElementById('archConfirmBtn').disabled = false;
                document.getElementById('archExportBtn').disabled  = false;
                document.getElementById('archCancelBtn').disabled  = false;
                document.getElementById('archProgress').style.display = 'none';
            });
    });

    // ── ARCHIVE VIEWER ────────────────────────────────────────────────────
    var _allArchiveRows = [];

    function openArchiveViewer() {
        document.getElementById('archiveViewerOverlay').style.display = 'flex';
        loadArchiveData();
    }

    function closeArchiveViewer() {
        document.getElementById('archiveViewerOverlay').style.display = 'none';
    }

    document.getElementById('archiveViewerOverlay').addEventListener('click', function (e) {
        if (e.target === this) closeArchiveViewer();
    });

    function loadArchiveData() {
        var body  = document.getElementById('archiveTableBody');
        var empty = document.getElementById('archiveEmpty');
        var table = document.getElementById('archiveTable');
        body.innerHTML = '<tr><td colspan="8" style="text-align:center;padding:30px;color:#a0aec0;">Loading…</td></tr>';
        table.style.display = 'table';
        empty.style.display = 'none';

        var fd = new FormData();
        fd.append('ajax_fetch_archive', '1');
        fetch(window.location.pathname, { method: 'POST', body: fd })
            .then(function (r) { return r.json(); })
            .then(function (data) {
                _allArchiveRows = data.rows || [];

                var batchSel   = document.getElementById('archiveBatchFilter');
                var currentVal = batchSel.value;
                batchSel.innerHTML = '<option value="">All Batches</option>';
                (data.batches || []).forEach(function (b) {
                    var opt = document.createElement('option');
                    opt.value = b; opt.textContent = b;
                    if (b === currentVal) opt.selected = true;
                    batchSel.appendChild(opt);
                });

                renderArchiveTable(_allArchiveRows);
            })
            .catch(function () {
                document.getElementById('archiveTableBody').innerHTML =
                    '<tr><td colspan="8" style="text-align:center;color:#e53e3e;padding:20px;">Failed to load archive.</td></tr>';
            });
    }

    function renderArchiveTable(rows) {
        var body  = document.getElementById('archiveTableBody');
        var empty = document.getElementById('archiveEmpty');
        var table = document.getElementById('archiveTable');

        if (!rows.length) {
            table.style.display = 'none';
            empty.style.display = 'block';
            return;
        }

        table.style.display = 'table';
        empty.style.display = 'none';

        var shownBatches = new Set();
        var batchMap = {};

        var html = rows.map(function (r, i) {
            var valBadge    = r.validation === 'Verified'
                ? '<span class="av-badge verified">Verified</span>'
                : '<span class="av-badge pending">Pending</span>';
            var deployBadge = r.deploy === 'Deployed'
                ? '<span class="av-badge deployed">Deployed</span>'
                : '<span class="av-badge waiting">' + (r.deploy || 'Waiting') + '</span>';

            var actionCell = '<td></td>';
            if (!shownBatches.has(r.batch)) {
                shownBatches.add(r.batch);
                batchMap[i] = r.batch;
                actionCell = '<td style="text-align:center;"><button class="av-unarchive-btn" data-rowindex="' + i + '" style="background:#0369a1;color:white;border:none;padding:5px 12px;border-radius:6px;font-size:11px;font-weight:700;cursor:pointer;display:inline-flex;align-items:center;gap:5px;white-space:nowrap;"><i class="fas fa-box-open"></i> Unarchive</button></td>';
            }

            return '<tr>' +
                '<td style="font-weight:600;color:#7c3aed;white-space:nowrap;">' + r.batch + '</td>' +
                '<td>' + r.name + '</td>' +
                '<td style="color:#718096;">' + r.course + '</td>' +
                '<td>' + valBadge + '</td>' +
                '<td>' + deployBadge + '</td>' +
                '<td>' + (r.company || '—') + '</td>' +
                '<td>' + (r.supervisor || '—') + '</td>' +
                '<td style="color:#a0aec0;white-space:nowrap;">' + r.archived + '</td>' +
                actionCell +
                '</tr>';
        }).join('');

        body.innerHTML = html;

        Object.keys(batchMap).forEach(function (idx) {
            var btn = body.querySelector('.av-unarchive-btn[data-rowindex="' + idx + '"]');
            if (btn) btn.dataset.batch = batchMap[idx];
        });
    }

    function filterArchive() {
        var batch   = document.getElementById('archiveBatchFilter').value.toLowerCase();
        var search  = document.getElementById('archiveSearchInput').value.toLowerCase();
        var filtered = _allArchiveRows.filter(function (r) {
            var matchBatch  = !batch  || r.batch.toLowerCase() === batch;
            var matchSearch = !search || r.name.toLowerCase().includes(search) || r.course.toLowerCase().includes(search);
            return matchBatch && matchSearch;
        });
        renderArchiveTable(filtered);
    }

    // ── UNARCHIVE LOGIC ───────────────────────────────────────────────────
    var _pendingUnarchiveBatch = '';

    document.getElementById('archiveViewerBody').addEventListener('click', function (e) {
        var rowBtn = e.target.closest('.av-unarchive-btn');
        if (!rowBtn) return;
        _pendingUnarchiveBatch = rowBtn.dataset.batch;
        showUnarchiveConfirm(_pendingUnarchiveBatch);
    });

    document.getElementById('archiveUnarchiveBtn').addEventListener('click', function () {
        var sel = document.getElementById('archiveBatchFilter').value;
        if (!sel) return;
        _pendingUnarchiveBatch = sel;
        showUnarchiveConfirm(_pendingUnarchiveBatch);
    });

    document.getElementById('archiveBatchFilter').addEventListener('change', function () {
        var btn = document.getElementById('archiveUnarchiveBtn');
        btn.disabled = !this.value;
        btn.title = this.value ? 'Unarchive "' + this.value + '"' : 'Select a batch first';
    });

    function showUnarchiveConfirm(batchName) {
        document.getElementById('unarchiveConfirmMsg').textContent =
            'This will restore all students from "' + batchName + '" back to the active dashboard.';
        document.getElementById('unarchiveConfirmOverlay').style.display = 'flex';
    }

    document.getElementById('unarchiveConfirmGo').addEventListener('click', function () {
        var confirmBtn     = this;
        var batchToRestore = _pendingUnarchiveBatch;

        if (!batchToRestore) {
            alert('No batch selected. Please click the Unarchive button on a batch row.');
            return;
        }

        confirmBtn.disabled = true;
        confirmBtn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Restoring…';

        var fd = new FormData();
        fd.append('ajax_unarchive_batch', '1');
        fd.append('batch_label', batchToRestore);

        fetch(window.location.pathname, { method: 'POST', body: fd })
            .then(function (r) { return r.text(); })
            .then(function (raw) {
                confirmBtn.disabled = false;
                confirmBtn.innerHTML = '<i class="fas fa-box-open"></i> Yes, Unarchive';
                document.getElementById('unarchiveConfirmOverlay').style.display = 'none';

                var data;
                try { data = JSON.parse(raw); }
                catch(e) {
                    alert('Unexpected server response:\n' + raw.substring(0, 400));
                    return;
                }

                if (data.blocked) {
                    document.getElementById('blockNotifMsg').textContent = data.message;
                    document.getElementById('blockNotifOverlay').style.display = 'flex';
                    return;
                }

                if (data.success) {
                    _pendingUnarchiveBatch = '';
                    closeArchiveViewer();
                    showGuardModal('','Batch Restored!',
                        '<strong>' + data.restored + '</strong> students from <strong>' + data.batch + '</strong> have been restored to the active dashboard.');
                    document.querySelector('#guardModal button').addEventListener('click', function () {
                        startNavigationGlobalLoading('Loading'); // UPDATED (loader sync fix): show the loader before reloading
                        window.location.reload();
                    }, { once: true });
                } else {
                    alert('Unarchive failed: ' + (data.message || 'Unknown error'));
                }
            })
            .catch(function (err) {
                confirmBtn.disabled = false;
                confirmBtn.innerHTML = '<i class="fas fa-box-open"></i> Yes, Unarchive';
                document.getElementById('unarchiveConfirmOverlay').style.display = 'none';
                alert('Network error: ' + err.message);
            });
    });

    function exportFilteredArchive() {
        var batch   = document.getElementById('archiveBatchFilter').value.toLowerCase();
        var search  = document.getElementById('archiveSearchInput').value.toLowerCase();
        var filtered = _allArchiveRows.filter(function (r) {
            var matchBatch  = !batch  || r.batch.toLowerCase() === batch;
            var matchSearch = !search || r.name.toLowerCase().includes(search) || r.course.toLowerCase().includes(search);
            return matchBatch && matchSearch;
        });

        if (!filtered.length) { alert('No records to export.'); return; }

        var cols  = ['batch','name','course','validation','deploy','company','supervisor','archived'];
        var heads = ['Batch','Full Name','Course','Validation Status','Deploy Status','Company','Supervisor','Archived On'];
        var html = '<table><thead><tr>' + heads.map(function (h) { return '<th>' + h + '</th>'; }).join('') + '</tr></thead><tbody>';
        filtered.forEach(function (r) {
            html += '<tr>' + cols.map(function (c) { return '<td>' + (r[c] || '') + '</td>'; }).join('') + '</tr>';
        });
        html += '</tbody></table>';

        var batchName = document.getElementById('archiveBatchFilter').value || 'All_Batches';
        var safeLabel = batchName.replace(/[^a-zA-Z0-9_\-]/g, '_');
        var blob = new Blob(['\ufeff', html], { type: 'application/vnd.ms-excel' });
        var url  = URL.createObjectURL(blob);
        var a    = document.createElement('a');
        a.href = url; a.download = 'Archive_' + safeLabel + '.xls'; a.click();
        URL.revokeObjectURL(url);
    }

    // ── APPLICATION REQUEST INBOX ─────────────────────────────────────────
    //
    // ══════════════════════════════════════════════════════════════
    // LIVE POLLING TIMING — application request detection
    // ------------------------------------------------------------
    // FIX: the badge/count poll previously started only after an
    // 8-second delay and then repeated every 30 seconds, so a new or
    // withdrawn application request could sit undetected for up to
    // half a minute even with the inbox closed. APP_REQUEST_POLL_INTERVAL
    // / APP_REQUEST_INITIAL_DELAY tighten that background cadence.
    //
    // Separately, while the Application Request Inbox drawer is open,
    // APP_DRAWER_LIVE_POLL_INTERVAL drives a faster, content-aware poll
    // (pollAppDrawerLive → diffAndUpdateAppInbox) that fetches the full
    // request list (not just the count) and diffs it against what's
    // currently rendered, so both a brand-new submission AND a
    // cancelled/withdrawn request are reflected in the open drawer's
    // card list immediately — not just in the badge number.
    // ══════════════════════════════════════════════════════════════
    const APP_REQUEST_POLL_INTERVAL       = 8000;  // background badge sync (was 30000)
    const APP_REQUEST_INITIAL_DELAY       = 3000;  // was 8000
    const APP_DRAWER_LIVE_POLL_INTERVAL   = 4000;  // fast poll only while drawer is open

    let _appDrawerLiveTimer = null;

    // ══════════════════════════════════════════════════════════════
    // SELF-ACTION TRACKING — fixes the duplicate
    // "1 application request withdrawn" / "No pending application
    // requests" bug.
    // ------------------------------------------------------------
    // handleAppRequest()/fvHandleAction() already remove a card and
    // update _fvAllApps themselves the instant THIS admin approves or
    // denies a request. The background live poll (pollAppDrawerLive →
    // diffAndUpdateAppInbox), running every few seconds while the
    // drawer is open, then also notices that same id is gone from the
    // server and treats it as a fresh "removal" — mislabeling the
    // admin's own action as a student "withdrawal" and re-running the
    // same card-removal / empty-state logic a second time, which is
    // what produced the duplicated notice + empty message.
    //
    // _recentlyHandledAppIds remembers ids this client just
    // approved/denied itself so the live diff can skip them — no
    // duplicate removal, no incorrect "withdrawn" notice. Entries
    // expire on their own after a few seconds (well past one live
    // poll cycle) so the Set never grows unbounded.
    //
    // ── RACE-CONDITION FIX (this is the actual bug fix requested) ──
    // Previously, an id was only added to _recentlyHandledAppIds
    // *inside* the success callback of the Allow/Deny fetch — i.e.
    // AFTER the network round trip for that action finished. But the
    // faster background drawer poll (pollAppDrawerLive, every 4s) can
    // have its own "fetch_app_requests" request in flight at the same
    // moment. If that poll's request reaches the server (and the
    // approve/deny has already been committed there) but its response
    // comes back to the browser BEFORE the admin's own Allow/Deny
    // fetch response does, diffAndUpdateAppInbox() would see the
    // application missing from the server list while
    // _recentlyHandledAppIds was still empty for that id — and wrongly
    // conclude the student withdrew it, showing the incorrect
    // "1 application request withdrawn" notice on top of the correct
    // approval/denial outcome.
    //
    // Calling markRecentlyHandled(approvalId) immediately when the
    // admin clicks Allow/Deny — BEFORE the fetch is even sent, not
    // after it resolves — closes that race window completely: any
    // concurrent poll response arriving in between will already see
    // the id flagged as self-handled and skip it.
    //
    // ── ADDITIONAL FIX (this is what was still slipping through) ──
    // The race-condition guard above only works if a marked id can
    // actually be *found* again later. It couldn't, reliably: the
    // inline card buttons render `app.id` as a bare, unquoted literal
    // inside their onclick="" attribute (see buildArCard below), so
    // whatever gets passed into handleAppRequest()/fvHandleAction() is
    // always parsed back as a plain JS Number. But `_fvAllApps`/
    // `newRows` — the arrays these ids get diffed against — carry
    // `id` exactly as the server returned it in JSON, which some
    // mysqli configurations hand back as a numeric *string* rather
    // than a native number. Set.has() and strict !==/=== never treat
    // "5" and 5 as equal, so a genuinely self-handled id could still
    // fail to match here and get flagged as a "withdrawn" request —
    // which is exactly the bug: it showed up specifically after
    // Allow (never really "fixed" by the guard above), because
    // approving does more DB work (extra ALTER TABLE checks, the
    // ojt_applications insert, and sendApplicationResultEmail()) than
    // denying, giving the background poll a much better chance to
    // land its response before the admin's own Allow request
    // finishes and clears the flag.
    //
    // markRecentlyHandled() and every id comparison in
    // diffAndUpdateAppInbox() (and the two `_fvAllApps` filters below)
    // now normalize ids to strings before comparing, so this can't
    // happen regardless of what type the id arrives as.
    // ══════════════════════════════════════════════════════════════
    let _recentlyHandledAppIds = new Set();

    function markRecentlyHandled(id) {
        // Normalize to a string — see the "ADDITIONAL FIX" note above.
        var key = String(id);
        _recentlyHandledAppIds.add(key);
        setTimeout(function () { _recentlyHandledAppIds.delete(key); }, 10000);
    }

    // Idempotent empty-state helper — safe to call from multiple places
    // (manual approve/deny, and the live diff poll) without ever
    // inserting the "No pending application requests." message twice.
    function ensureAppEmptyState(body) {
        if (!body) return;
        if (body.querySelector('.ar-card')) return;
        if (body.querySelector('.ar-empty')) return;
        var e = document.createElement('div');
        e.className = 'ar-empty';
        e.innerHTML = '<i class="fas fa-inbox"></i>No pending application requests.';
        body.appendChild(e);
        var newBadge = document.getElementById('appRequestNewBadge');
        if (newBadge) newBadge.style.display = 'none';
    }

    function openAppInbox() {
        document.getElementById('appRequestOverlay').style.display = 'flex';
        cvLoadStudentUploads();   // NEW (this adjustment): new requirement submissions
        loadAppRequests();
        startAppDrawerLivePoll();
    }
    function closeAppInbox() {
        document.getElementById('appRequestOverlay').style.display = 'none';
        stopAppDrawerLivePoll();
    }

    function startAppDrawerLivePoll() {
        stopAppDrawerLivePoll();
        _appDrawerLiveTimer = setInterval(pollAppDrawerLive, APP_DRAWER_LIVE_POLL_INTERVAL);
    }

    function stopAppDrawerLivePoll() {
        if (_appDrawerLiveTimer) {
            clearInterval(_appDrawerLiveTimer);
            _appDrawerLiveTimer = null;
        }
    }

    // Fast poll used only while the drawer is open. Fetches the full
    // request list (not just the count) so newly-arrived and
    // withdrawn/cancelled requests can both be detected and reflected
    // live in the open drawer, instead of requiring a manual close/reopen.
    function pollAppDrawerLive() {
        cvLoadStudentUploads();   // NEW (this adjustment): keep the submissions live too while the Inbox is open
        var fd = new FormData();
        fd.append('ajax_fetch_app_requests', '1');
        fetch(window.location.pathname, { method: 'POST', body: fd })
            .then(function (r) { return r.json(); })
            .then(function (data) {
                if (!data.success) return;
                updateAppBadge(data.count);
                notifyNewAppRequests(data.rows || []);   // NEW (this adjustment): popup for newly detected requests
                diffAndUpdateAppInbox(data.rows || []);
                _lastKnownAppCount = data.count;
            })
            .catch(function () {});
    }

    function loadAppRequests() {
        var body = document.getElementById('appRequestBody');
        body.innerHTML = '<div style="text-align:center;padding:30px;color:#a0aec0;font-size:13px;">Loading…</div>';

        var fd = new FormData();
        fd.append('ajax_fetch_app_requests', '1');
        fetch(window.location.pathname, { method: 'POST', body: fd })
            .then(function (r) { return r.json(); })
            .then(function (data) {
                updateAppBadge(data.count);
                notifyNewAppRequests(data.rows || []);   // NEW (this adjustment): popup for newly detected requests
                _fvAllApps = data.rows || [];
                _lastKnownAppCount = data.count;

                if (!_fvAllApps.length) {
                    body.innerHTML = '';
                    var emptyEl = document.createElement('div');
                    emptyEl.className = 'ar-empty';
                    emptyEl.innerHTML = '<i class="fas fa-inbox"></i>No pending application requests.';
                    body.appendChild(emptyEl);
                    return;
                }
                body.innerHTML = '';
                var newBadge = document.getElementById('appRequestNewBadge');
                if (newBadge) {
                    newBadge.textContent = data.count + ' New';
                    newBadge.style.display = 'inline';
                }
                _fvAllApps.forEach(function (app) {
                    body.appendChild(buildArCard(app));
                });
            })
            .catch(function () {
                body.innerHTML = '<div style="text-align:center;padding:20px;color:#e53e3e;font-size:13px;">Failed to load requests.</div>';
            });
    }

    // ══════════════════════════════════════════════════════════════
    // LIVE DIFF — activates the UI directly for both new submissions
    // AND cancellations/withdrawals while the drawer is already open.
    // ------------------------------------------------------------
    // Compares the freshly-fetched request list against the list
    // currently backing the drawer (_fvAllApps), and applies only the
    // delta to the DOM:
    //   - newly-appeared ids  → card built + inserted at the top with
    //     the ar-card-new highlight animation
    //   - ids no longer present (approved elsewhere, denied elsewhere,
    //     or withdrawn/cancelled by the student) → card fades out and
    //     is removed; if that exact application happens to be open in
    //     the Full View modal, the modal is closed automatically
    //
    // Ids that THIS client just approved/denied itself (tracked in
    // _recentlyHandledAppIds — see above, and now marked BEFORE the
    // fetch is even sent) are excluded from this "genuine removal"
    // pass entirely: handleAppRequest()/fvHandleAction() already
    // removed their card and updated _fvAllApps, so re-processing them
    // here would just repeat the same fade-out on an already-gone
    // card, mislabel the admin's own approval/denial as a "withdrawn"
    // request, and risk inserting a second empty-state message.
    // ensureAppEmptyState() is idempotent on top of that, so even a
    // race between the two code paths can never show the empty
    // message twice.
    //
    // FIX: every id compared below — oldIds, newIds, and the values
    // fed into _recentlyHandledAppIds.has() — is normalized with
    // String() first. See the "ADDITIONAL FIX" note above
    // markRecentlyHandled() for why a raw type mismatch was letting a
    // self-handled Allow slip past this guard and get mislabeled as a
    // withdrawal.
    // ══════════════════════════════════════════════════════════════
    function diffAndUpdateAppInbox(newRows) {
        var body = document.getElementById('appRequestBody');
        if (!body) return;

        var oldIds = _fvAllApps.map(function (a) { return String(a.id); });
        var newIds = newRows.map(function (a) { return String(a.id); });

        var addedApps  = newRows.filter(function (a) { return oldIds.indexOf(String(a.id)) === -1; });
        var removedIds = oldIds.filter(function (id) { return newIds.indexOf(id) === -1; });

        // Exclude ids this client already handled locally (own Allow/Deny
        // click) — those are not genuine external changes. Both sides are
        // strings at this point, so this comparison is now reliable
        // regardless of whether the server or the onclick literal handed
        // back a number or a numeric string.
        var genuineRemovedIds = removedIds.filter(function (id) {
            return !_recentlyHandledAppIds.has(id);
        });

        if (!addedApps.length && !genuineRemovedIds.length) {
            // No structural change — keep the backing data fresh (e.g. an
            // applicant's document/photo status may have changed) without
            // touching the rendered cards.
            _fvAllApps = newRows;
            return;
        }

        // ── Removed (approved/denied elsewhere, or withdrawn/cancelled) ──
        genuineRemovedIds.forEach(function (id) {
            var card = document.getElementById('arCard' + id);
            if (card) {
                card.classList.add('removing');
                setTimeout(function () {
                    card.remove();
                    ensureAppEmptyState(document.getElementById('appRequestBody'));
                }, 300);
            }
            // If the admin currently has this exact application open in
            // Full View, close it since it's no longer a pending request.
            if (_fvCurrentApp && String(_fvCurrentApp.id) === id) {
                closeAppFullView();
            }
        });

        // ── Added (new submissions) — insert at the top, newest-first ──
        addedApps.forEach(function (app) {
            var stale = body.querySelector('.ar-empty');
            if (stale) stale.remove();
            var card = buildArCard(app, true);
            body.insertBefore(card, body.firstChild);
        });

        showAppLiveNotice(addedApps.length, genuineRemovedIds.length);

        _fvAllApps = newRows;

        var newBadge = document.getElementById('appRequestNewBadge');
        if (newBadge) {
            if (newRows.length > 0) {
                newBadge.textContent = newRows.length + ' New';
                newBadge.style.display = 'inline';
            } else {
                newBadge.style.display = 'none';
            }
        }

        // If the list ends up empty (after any removal animation finishes),
        // show the empty state — idempotent, so this can never duplicate it.
        setTimeout(function () { ensureAppEmptyState(body); }, 320);
    }

    // Transient banner announcing what the live poll just detected. Only
    // called with genuine (non-self-triggered) counts, so it never labels
    // the admin's own approval/denial as a "withdrawal".
    function showAppLiveNotice(addedCount, removedCount) {
        var body = document.getElementById('appRequestBody');
        if (!body) return;

        var existing = body.querySelector('.ar-live-notice');
        if (existing) existing.remove();

        var parts = [];
        if (addedCount > 0) {
            parts.push({
                cls:  'ar-live-notice-added',
                icon: 'fa-inbox',
                text: addedCount + ' new application request' + (addedCount !== 1 ? 's' : '') + ' received'
            });
        }
        if (removedCount > 0) {
            parts.push({
                cls:  'ar-live-notice-removed',
                icon: 'fa-rotate-left',
                text: removedCount + ' application request' + (removedCount !== 1 ? 's' : '') + ' withdrawn'
            });
        }

        parts.forEach(function (p) {
            var notice = document.createElement('div');
            notice.className = 'ar-live-notice ' + p.cls;
            notice.innerHTML = '<i class="fas ' + p.icon + '"></i> ' + p.text;
            body.insertBefore(notice, body.firstChild);
            setTimeout(function () {
                notice.style.opacity = '0';
                setTimeout(function () { notice.remove(); }, 300);
            }, 4000);
        });
    }

    /* ══════════════════════════════════════════════════════════════
       SUMMARIZED APPLICATION REQUEST CARD
       ------------------------------------------------------------
       UPDATED LAYOUT: Student Name → Course → Company Name →
       count of students currently registered at that company,
       stacked vertically, followed by a single horizontal row of
       Allow / Deny / Full View buttons (ar-actions is already a
       flex row, so all three sit side by side).

       Full applicant detail (photo, skills, experience, submitted
       documents, etc.) is still shown in the Full View document
       modal via openAppFullView(), which reads from the same `app`
       object fetched by loadAppRequests() above.

       isNew (optional): when true, tags the card with ar-card-new so
       it plays the slide-in + highlight-flash animation — used by
       diffAndUpdateAppInbox() when a fresh submission is detected
       while the drawer is already open.
       ══════════════════════════════════════════════════════════════ */
    function buildArCard(app, isNew) {
        var card = document.createElement('div');
        card.className = 'ar-card' + (isNew ? ' ar-card-new' : '');
        card.id = 'arCard' + app.id;

        var ojtCount     = (app.ojt_count !== undefined && app.ojt_count !== null) ? app.ojt_count : 0;
        var ojtCountText = ojtCount > 0
            ? ojtCount + ' student' + (ojtCount !== 1 ? 's' : '') + ' currently registered'
            : 'No students registered yet';

        card.innerHTML =
            '<div class="ar-summary-name">' + escHtml(app.full_name) + '</div>' +
            '<div class="ar-summary-course">' + escHtml(app.course || '—') + '</div>' +
            '<div class="ar-summary-company"><i class="fas fa-building" style="font-size:10px;"></i> ' + escHtml(app.company_name) + '</div>' +
            '<div class="ar-summary-count"><i class="fas fa-users" style="font-size:10px;"></i> ' + escHtml(ojtCountText) + '</div>' +
            '<div class="ar-actions">' +
                '<button class="ar-allow-btn" onclick="handleAppRequest(' + app.id + ', \'allow\', this)"><i class="fas fa-check"></i> Allow</button>' +
                '<button class="ar-deny-btn"  onclick="handleAppRequest(' + app.id + ', \'deny\', this)"><i class="fas fa-times"></i> Deny</button>' +
                '<button class="ar-fullview-btn" onclick="openAppFullView(' + app.id + ')"><i class="fas fa-expand-alt"></i> Full View</button>' +
            '</div>';
        return card;
    }

    // ══════════════════════════════════════════════════════════════
    // DIGITAL RESUME / DOCUMENTS — DYNAMIC A4 PAGINATION ENGINE
    // (Full View application document)
    // ------------------------------------------------------------
    // Ported from company_list.php's own resume pagination engine
    // (drMeasureContentHeight / drPartitionResume / drRenderPages,
    // etc.) so an applicant with a lot of Skills/Experience entries
    // spans however many real A4-sized pages that content needs,
    // instead of one endlessly-tall document. The "Submitted
    // Documents" table is always rendered as its own dedicated,
    // vertically-centered final page — same behavior as
    // company_list.php's OJT Requirements page.
    //
    // Unlike company_list.php (where the raw content is baked in by
    // PHP per company and cached per-company the first time an
    // accordion opens), the Full View modal's raw content is
    // populated dynamically by openAppFullView() for whichever
    // applicant was clicked, so fvRenderPages() rebuilds the pages
    // fresh every time the modal opens rather than caching them.
    // ══════════════════════════════════════════════════════════════
    const FV_PAGE_W = 794;
    const FV_PAGE_H = 1123;
    const FV_BODY_TOP_BOTTOM_PAD = 18 + 20; // matches .fv-form-body's top+bottom padding
    const FV_SAFETY_BUFFER = 32;            // extra headroom so nothing ever grazes the footer

    function fvMeasureContentHeight(el) {
        // Measures a clone of `el` at the same content width the real
        // .fv-form-body gives it (794px page minus 28px left/right padding).
        // overflow:hidden gives the sandbox its own block-formatting
        // context so the cloned block's top/bottom margins are measured
        // in full instead of collapsing into the sandbox's own edges.
        var sandbox = document.createElement('div');
        sandbox.style.cssText = 'position:fixed;top:0;left:-9999px;visibility:hidden;width:738px;overflow:hidden;';
        sandbox.appendChild(el.cloneNode(true));
        document.body.appendChild(sandbox);
        var h = sandbox.scrollHeight;
        document.body.removeChild(sandbox);
        return h;
    }

    function fvMeasureFullWidthHeight(el) {
        var sandbox = document.createElement('div');
        sandbox.style.cssText = 'position:fixed;top:0;left:-9999px;visibility:hidden;width:' + FV_PAGE_W + 'px;overflow:hidden;';
        sandbox.appendChild(el.cloneNode(true));
        document.body.appendChild(sandbox);
        var h = sandbox.scrollHeight;
        document.body.removeChild(sandbox);
        return h;
    }

    function fvAppendSectionBlocks(rawRoot, titleSelector, colSelector, blocks) {
        // Glues a section title together with whichever comes right after
        // it (its first entry, or its "No skills/experience listed" empty
        // note) into a single unit the paginator can never split across a
        // page break, so the title is never stranded alone at the bottom
        // of a page. Any further entries after that still flow normally,
        // each free to land on whichever page has room.
        var title = rawRoot.querySelector(titleSelector);
        if (!title) return;

        var col = rawRoot.querySelector(colSelector);
        var children = col ? Array.prototype.slice.call(col.children) : [];

        if (children.length > 0) {
            blocks.push({ type: 'glued', els: [title, children[0]] });
            for (var i = 1; i < children.length; i++) {
                blocks.push({ type: 'atomic', el: children[i] });
            }
        } else {
            blocks.push({ type: 'atomic', el: title });
        }
    }

    function fvBuildResumeBlocks(rawRoot) {
        // Only the applicant-strip + Skills + Experience portion — the
        // Submitted Documents section is handled entirely separately by
        // fvBuildDocPageBody() below, as its own dedicated page.
        var blocks = [];

        var applicantStrip = rawRoot.querySelector('.fv-applicant-strip');
        if (applicantStrip) blocks.push({ type: 'atomic', el: applicantStrip });

        fvAppendSectionBlocks(rawRoot, '.fv-skills-title', '.fv-skills-col', blocks);
        fvAppendSectionBlocks(rawRoot, '.fv-exp-title', '.fv-exp-col', blocks);

        return blocks;
    }

    function fvPartitionResume(rawRoot, usableH) {
        var blocks  = fvBuildResumeBlocks(rawRoot);
        var pages   = [[]];
        var pageIdx = 0;
        var curH    = 0;

        function breakPageIfNeeded(h) {
            if (curH + h > usableH && pages[pageIdx].length > 0) {
                pageIdx++;
                pages[pageIdx] = [];
                curH = 0;
            }
        }

        blocks.forEach(function(block) {
            if (block.type === 'atomic') {
                var h = fvMeasureContentHeight(block.el);
                breakPageIfNeeded(h);
                pages[pageIdx].push(block.el.cloneNode(true));
                curH += h;
                return;
            }

            if (block.type === 'glued') {
                var combinedWrap = document.createElement('div');
                block.els.forEach(function(e) { combinedWrap.appendChild(e.cloneNode(true)); });
                var gh = fvMeasureContentHeight(combinedWrap);
                breakPageIfNeeded(gh);
                block.els.forEach(function(e) { pages[pageIdx].push(e.cloneNode(true)); });
                curH += gh;
            }
        });

        pages = pages.filter(function(p) { return p.length > 0; });
        if (pages.length === 0) pages = [[]];
        return pages;
    }

    function fvBuildDocPageBody(rawRoot) {
        // The Submitted Documents title + table, cloned as one
        // self-contained unit for the dedicated final page. Never split
        // across pages — there are always exactly 8 fixed requirement
        // rows, so this comfortably fits a single A4 page.
        var wrap = document.createElement('div');
        wrap.className = 'fv-doc-page-inner';

        var docTitle = rawRoot.querySelector('.fv-doc-title');
        if (docTitle) wrap.appendChild(docTitle.cloneNode(true));

        var docTable = rawRoot.querySelector('.fv-doc-table');
        if (docTable) wrap.appendChild(docTable.cloneNode(true));

        return wrap;
    }

    function fvRenderPages() {
        var wrap = document.getElementById('fvPagesWrap');
        var raw  = document.getElementById('fvRawSource');
        if (!wrap || !raw) return;

        var headerClone = document.getElementById('fvHeaderClone');
        var footerClone = document.getElementById('fvFooterClone');

        /* Wait for web fonts to finish loading before measuring anything —
           measuring too early (while text still renders in a fallback
           font) under/over-estimates block heights, which can let content
           run past the fixed 1123px page height and clip against
           .fv-doc-paper's overflow:hidden. Mirrors company_list.php's
           document.fonts.ready gate in drRenderPages(). */
        document.fonts.ready.then(function() {
            var HDR_H    = fvMeasureFullWidthHeight(headerClone) || 120;
            var FTR_H    = fvMeasureFullWidthHeight(footerClone) || 24;
            var USABLE_H = FV_PAGE_H - HDR_H - FTR_H - FV_BODY_TOP_BOTTOM_PAD - FV_SAFETY_BUFFER;

            // Applicant strip / Skills / Experience pages — grows or
            // shrinks with entry count.
            var resumePages = fvPartitionResume(raw, USABLE_H);

            // Submitted Documents — always exactly one dedicated page,
            // vertically centered, appended after every resume page.
            var docBody       = fvBuildDocPageBody(raw);
            var docBodyHeight = fvMeasureContentHeight(docBody);
            var docFits       = docBodyHeight <= USABLE_H;

            var totalPages = resumePages.length + 1;

            wrap.innerHTML = '';

            function buildPaper(pageNum, isDocPage, content) {
                var paper = document.createElement('div');
                paper.className = 'fv-doc-paper';

                var hdr = document.createElement('div');
                hdr.innerHTML = headerClone.innerHTML;
                var metaEl = hdr.querySelector('.fv-form-meta');
                if (metaEl) metaEl.textContent = 'Student Application Request \u2014 Full View \u2014 Page ' + pageNum + ' of ' + totalPages;
                paper.appendChild(hdr);

                var body = document.createElement('div');
                body.className = 'fv-form-body' + (isDocPage ? (docFits ? ' fv-doc-page-body' : ' fv-doc-page-fallback') : '');
                if (isDocPage) {
                    body.appendChild(content);
                } else {
                    content.forEach(function(node) { body.appendChild(node); });
                }
                paper.appendChild(body);

                var ftr = document.createElement('div');
                ftr.innerHTML = footerClone.innerHTML;
                paper.appendChild(ftr);

                wrap.appendChild(paper);
            }

            resumePages.forEach(function(nodes, i) {
                buildPaper(i + 1, false, nodes);
            });
            buildPaper(totalPages, true, docBody);
        });
    }

    // ══════════════════════════════════════════════════════════════
    // FULL VIEW APPLICATION MODAL — LETTERHEAD/DOCUMENT STYLE
    // Mirrors the full-bleed, sticky-toolbar document viewer used by
    // company_reports.php's evaluation preview (.eval-overlay /
    // .eval-doc-toolbar / .eval-doc-canvas / .doc-paper), adapted
    // here as fv-* classes so nothing in this file's existing CSS/JS
    // is touched or renamed. Only the open/close calls below were
    // updated (no more forced inline "flex" display) to match the
    // simple class-toggled show/hide used by company_reports.php's
    // eval-overlay; the data population logic is unchanged except for
    // the applicant-info + skills/experience mirroring described in
    // the CSS/HTML comments above, and now finishes by calling
    // fvRenderPages() to lay the populated raw source out across
    // however many paginated A4 pages it needs.
    //
    // FIX — missing skill/experience entries in the admin's Full View:
    // On the student side (company_list.php), the Digital Resume can
    // hold more than 2 skills and more than 1 experience entry. When
    // applying, any entries beyond skill1/skill2 are packed into
    // skill3 (joined with a delimiter), and any entries beyond exp1
    // are packed into exp2 (joined with a different delimiter) — see
    // that file's $legacy_skill3 / $legacy_exp2 construction. The
    // admin's ajax_fetch_app_requests handler now splits those packed
    // fields back into full `skills` / `experiences` arrays (see the
    // PHP block above), so this modal renders every entry the student
    // actually saved instead of only "Skill 1/2/3" and "Experience
    // 1/2" with extra entries silently swallowed into one box.
    // ══════════════════════════════════════════════════════════════
    function openAppFullView(approvalId) {
        var app = _fvAllApps.find(function (a) { return a.id == approvalId; });
        if (!app) return;
        _fvCurrentApp = app;
        window._fvCurrentApp = app;

        var initials = app.full_name.split(' ').map(function(n){return n[0];}).join('').substring(0,2).toUpperCase();
        var avatarEl = document.getElementById('fv-photo-thumb');
        if (app.photo) {
            avatarEl.innerHTML = '<img src="data:image/jpeg;base64,' + app.photo + '" alt="Photo">';
        } else {
            avatarEl.innerHTML = '';
            avatarEl.textContent = initials;
        }

        document.getElementById('fvToolbarTitle').textContent = app.full_name + ' — Application Review';

        // Mirrors student_profile.php's Digital Resume header: stacked
        // "Full Name:" / "Email:" / "Course:" lines beside the photo.
        document.getElementById('fv-name-val').textContent   = app.full_name;
        document.getElementById('fv-email-val').textContent  = app.email;
        document.getElementById('fv-course-val').textContent = app.course;

        document.getElementById('fv-company').innerHTML = '<i class="fas fa-building" style="font-size:10px;"></i> ' + escHtml(app.company_name);

        var submitted = app.submitted_at ? new Date(app.submitted_at).toLocaleDateString('en-PH',{month:'short',day:'numeric',year:'numeric'}) : '—';
        document.getElementById('fv-date').textContent = submitted;
        var datePill = document.getElementById('fv-date-pill');
        if (datePill) datePill.innerHTML = '<i class="fas fa-calendar-alt" style="font-size:10px;"></i> Applied ' + submitted;

        var ojtPill = document.getElementById('fv-ojt-count-pill');
        if (ojtPill) {
            if (app.ojt_count !== undefined && app.ojt_count > 0) {
                ojtPill.innerHTML = '<i class="fas fa-users" style="font-size:10px;"></i> ' + app.ojt_count + ' student' + (app.ojt_count !== 1 ? 's' : '') + ' enrolled at this company';
                ojtPill.style.display = 'inline-flex';
            } else {
                ojtPill.style.display = 'none';
            }
        }

        // Skills — rendered as numbered, locked-field-style boxes
        // ("Skill 1", "Skill 2", ...), mirroring the resume form's
        // .field-wrap / .autogrow-textarea.field-locked entries.
        // FIX: use the full reconstructed `app.skills` list (server-side
        // explode of skill3's packed extra entries — see PHP above)
        // instead of just [skill1, skill2, skill3], so every skill entry
        // the student saved in their Digital Resume shows up here, not
        // just the first two plus one combined-looking third entry.
        var skillsArr = (app.skills && app.skills.length)
            ? app.skills.filter(function(s){return s && s.trim();})
            : [app.skill1, app.skill2, app.skill3].filter(function(s){return s && s.trim();});
        document.getElementById('fv-skills').innerHTML = skillsArr.length
            ? skillsArr.map(function(s, idx){
                return '<div class="fv-entry-item">' +
                           '<div class="fv-field-label">Skill ' + (idx + 1) + '</div>' +
                           '<div class="fv-entry-box">' + escHtml(s) + '</div>' +
                       '</div>';
              }).join('')
            : '<span class="fv-empty-note">No skills listed</span>';

        // Experience — same numbered, locked-field-style boxes,
        // mirroring the resume form's "Experience 1", "Experience 2"...
        // FIX: same reconstruction, using the full `app.experiences`
        // list (server-side explode of exp2's packed extra entries)
        // instead of just [exp1, exp2].
        var expsArr = (app.experiences && app.experiences.length)
            ? app.experiences.filter(function(e){return e && e.trim();})
            : [app.exp1, app.exp2].filter(function(e){return e && e.trim();});
        document.getElementById('fv-exp').innerHTML = expsArr.length
            ? expsArr.map(function(e, idx){
                return '<div class="fv-entry-item">' +
                           '<div class="fv-field-label">Experience ' + (idx + 1) + '</div>' +
                           '<div class="fv-entry-box">' + escHtml(e) + '</div>' +
                       '</div>';
              }).join('')
            : '<span class="fv-empty-note">No experience listed</span>';

        var photoStatusEl = document.getElementById('fv-photo-status');
        fvSetStatusPill(photoStatusEl, app.photo_status || 'Pending');

        var grid = document.getElementById('fv-docs-grid');
        grid.innerHTML = '<tr class="fv-doc-th"><td>Document</td><td style="width:110px; text-align:center;">Status</td></tr>';
        var fvPreviewDocs = window._fvPreviewDocs = [];   // NEW (this adjustment): this application's PDFs, paged by the preview modal
        (app.req_docs || []).forEach(function (doc) {
            var statusClass = (doc.status === 'Verified') ? 'verified' : (doc.status === 'Denied') ? 'denied' : 'pending';
            var statusText  = (doc.status === 'Verified') ? '✓ Verified' : (doc.status === 'Denied') ? '✗ Denied' : 'Pending';
            // UPDATED (this adjustment): a PDF gets a PDF icon that opens the document preview modal (an image keeps the thumbnail + enlarge)
            var thumbHtml;
            if (doc.file && /^JVBERi/.test(doc.file)) {   // base64 of "%PDF"
                var pdfIdx = fvPreviewDocs.push({ b64: doc.file, isPdf: true, label: doc.label || 'Document' }) - 1;
                thumbHtml = '<span class="fv-doc-thumb fv-doc-thumb-pdf" onclick="cvOpenDocPreview(window._fvPreviewDocs,' + pdfIdx + ')" title="Preview PDF"><i class="fas fa-file-pdf"></i></span>';
            } else if (doc.file) {
                thumbHtml = '<span class="fv-doc-thumb" onclick="openPreview(\'data:image/jpeg;base64,' + doc.file + '\')"><img src="data:image/jpeg;base64,' + doc.file + '"></span>';
            } else {
                thumbHtml = '<span class="fv-doc-nothumb"><i class="fas fa-file" style="font-size:12px;color:#d1d5db;"></i></span>';
            }

            var row = document.createElement('tr');
            row.innerHTML =
                '<td><div class="fv-doc-row-name">' + thumbHtml + '<span>' + escHtml(doc.label) + '</span></div></td>' +
                '<td style="text-align:center;"><span class="fv-status-chip ' + statusClass + '">' + statusText + '</span></td>';
            grid.appendChild(row);
        });

        var allowBtn = document.getElementById('fv-allow-btn');
        var denyBtn  = document.getElementById('fv-deny-btn');
        allowBtn.disabled = false; allowBtn.innerHTML = '<i class="fas fa-check"></i> Allow Application';
        denyBtn.disabled  = false; denyBtn.innerHTML  = '<i class="fas fa-times"></i> Deny Application';

        document.getElementById('appFullViewOverlay').classList.add('open');

        // Build/rebuild the paginated A4 pages from the raw source just populated above.
        fvRenderPages();
    }

    function closeAppFullView() {
        document.getElementById('appFullViewOverlay').classList.remove('open');
        _fvCurrentApp = null;
        window._fvCurrentApp = null;

        // Clear built pages so the next open always renders fresh (no stale flash
        // of a previous applicant's pages before fvRenderPages() finishes).
        var wrap = document.getElementById('fvPagesWrap');
        if (wrap) wrap.innerHTML = '';
    }

    function fvSetStatusPill(el, status) {
        if (!el) return;
        var cls = (status === 'Verified') ? 'verified' : (status === 'Denied') ? 'denied' : 'pending';
        var text = (status === 'Verified') ? '✓ Verified' : (status === 'Denied') ? '✗ Denied' : status;
        el.className = 'fv-status-chip fv-badge-photo ' + cls;
        el.textContent = text;
    }

    function fvHandleAction(action, endorsementData) {
        if (!_fvCurrentApp) return;
        var approvalId = _fvCurrentApp.id;

        // ── NEW (endorsement flow): "Allow" first opens the Endorsement Letter
        // composer. The composer calls back into this same function with the
        // finished letter, which then runs the original approve request below.
        if (action === 'allow' && endorsementData === undefined) {
            openEndorsementComposer(approvalId, function (letter) { fvHandleAction('allow', letter); });
            return;
        }

        // ── RACE-CONDITION FIX ──
        // Mark this id as "recently handled by this client" IMMEDIATELY —
        // before the approve/deny fetch is even sent — so that any
        // concurrent drawer live-poll response arriving in between can
        // never mistake this admin's own action for a student withdrawal.
        // See the extended explanation above markRecentlyHandled().
        markRecentlyHandled(approvalId);

        var allowBtn   = document.getElementById('fv-allow-btn');
        var denyBtn    = document.getElementById('fv-deny-btn');
        allowBtn.disabled = true;
        denyBtn.disabled  = true;
        if (action === 'allow') { allowBtn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Processing…'; }
        else                    { denyBtn.innerHTML  = '<i class="fas fa-spinner fa-spin"></i> Processing…'; }

        // UPDATED: loading → success / failed status screen for "Approve & Send Letter".
        var endoLetterFlow = (action === 'allow' && !!endorsementData);
        if (endoLetterFlow) endoStatusLoading(approvalId);

        var fd = new FormData();
        fd.append(action === 'allow' ? 'ajax_approve_app_request' : 'ajax_deny_app_request', '1');
        fd.append('approval_id', approvalId);
        if (action === 'allow' && endorsementData) fd.append('endorsement', JSON.stringify(endorsementData));

        fetch(window.location.pathname, { method: 'POST', body: fd })
            .then(function (r) { return r.json(); })
            .then(function (data) {
                if (data.success) {
                    if (endoLetterFlow) endoStatusDone(data);                        // UPDATED
                    else if (action === 'allow') endoShowSentToast(data.endorsement_sent);
                    closeAppFullView();
                    var card = document.getElementById('arCard' + approvalId);
                    if (card) {
                        card.classList.add('removing');
                        setTimeout(function () {
                            card.remove();
                            ensureAppEmptyState(document.getElementById('appRequestBody'));
                        }, 300);
                    }
                    // FIX: compare with String() on both sides so this
                    // removal from _fvAllApps actually happens even when
                    // the id types don't natively match (see the
                    // "ADDITIONAL FIX" note above markRecentlyHandled()).
                    _fvAllApps = _fvAllApps.filter(function (a) { return String(a.id) !== String(approvalId); });
                    updateAppBadge(data.remaining);
                    _lastKnownAppCount = data.remaining;
                } else {
                    allowBtn.disabled = false;
                    denyBtn.disabled  = false;
                    allowBtn.innerHTML = '<i class="fas fa-check"></i> Allow Application';
                    denyBtn.innerHTML  = '<i class="fas fa-times"></i> Deny Application';
                    if (endoLetterFlow) endoStatusFailed(data.message || 'Unknown error', function () { fvHandleAction('allow', endorsementData); }); // UPDATED
                    else alert('Action failed: ' + (data.message || 'Unknown error'));
                }
            })
            .catch(function () {
                allowBtn.disabled = false;
                denyBtn.disabled  = false;
                allowBtn.innerHTML = '<i class="fas fa-check"></i> Allow Application';
                denyBtn.innerHTML  = '<i class="fas fa-times"></i> Deny Application';
                if (endoLetterFlow) endoStatusFailed('Network error — the server could not be reached.', function () { fvHandleAction('allow', endorsementData); }); // UPDATED
                else alert('Network error. Please try again.');
            });
    }

    document.getElementById('appFullViewOverlay').addEventListener('click', function (e) {
        if (e.target === this) closeAppFullView();
    });

    function handleAppRequest(approvalId, action, btnEl, endorsementData) {
        // ── NEW (endorsement flow): same composer step as fvHandleAction(). ──
        if (action === 'allow' && endorsementData === undefined) {
            openEndorsementComposer(approvalId, function (letter) { handleAppRequest(approvalId, 'allow', btnEl, letter); });
            return;
        }

        // ── RACE-CONDITION FIX ──
        // Same reasoning as fvHandleAction(): mark this id as handled by
        // this client BEFORE sending the approve/deny request, so the
        // background drawer live-poll can never race ahead of this action
        // and mislabel it as a withdrawn/cancelled application.
        markRecentlyHandled(approvalId);

        var card = document.getElementById('arCard' + approvalId);
        var btns = card ? card.querySelectorAll('button') : [];
        btns.forEach(function (b) { b.disabled = true; });
        if (btnEl) { btnEl.textContent = '…'; }

        // UPDATED: loading → success / failed status screen for "Approve & Send Letter".
        var endoLetterFlow = (action === 'allow' && !!endorsementData);
        if (endoLetterFlow) endoStatusLoading(approvalId);

        var fd = new FormData();
        fd.append(action === 'allow' ? 'ajax_approve_app_request' : 'ajax_deny_app_request', '1');
        fd.append('approval_id', approvalId);
        if (action === 'allow' && endorsementData) fd.append('endorsement', JSON.stringify(endorsementData));

        fetch(window.location.pathname, { method: 'POST', body: fd })
            .then(function (r) { return r.json(); })
            .then(function (data) {
                if (data.success) {
                    if (endoLetterFlow) endoStatusDone(data);                        // UPDATED
                    else if (action === 'allow') endoShowSentToast(data.endorsement_sent);
                    if (card) {
                        card.classList.add('removing');
                        setTimeout(function () {
                            card.remove();
                            ensureAppEmptyState(document.getElementById('appRequestBody'));
                        }, 300);
                    }
                    // FIX: same String() normalization as fvHandleAction()
                    // above, for the same reason.
                    _fvAllApps = _fvAllApps.filter(function (a) { return String(a.id) !== String(approvalId); });
                    updateAppBadge(data.remaining);
                    _lastKnownAppCount = data.remaining;
                } else {
                    btns.forEach(function (b) { b.disabled = false; });
                    if (btnEl) { btnEl.textContent = action === 'allow' ? 'Allow' : 'Deny'; }
                    if (endoLetterFlow) endoStatusFailed(data.message || 'Unknown error', function () { handleAppRequest(approvalId, 'allow', btnEl, endorsementData); }); // UPDATED
                    else alert('Action failed: ' + (data.message || 'Unknown error'));
                }
            })
            .catch(function () {
                btns.forEach(function (b) { b.disabled = false; });
                if (endoLetterFlow) endoStatusFailed('Network error — the server could not be reached.', function () { handleAppRequest(approvalId, 'allow', btnEl, endorsementData); }); // UPDATED
                else alert('Network error. Please try again.');
            });
    }

    function updateAppBadge(count) {
        var badge    = document.getElementById('appInboxBadge');
        var newBadge = document.getElementById('appRequestNewBadge');
        if (badge) {
            badge.textContent = count > 0 ? count : '';
            badge.style.display = count > 0 ? 'flex' : 'none';
        }
        if (newBadge) {
            if (count > 0) { newBadge.textContent = count + ' New'; newBadge.style.display = 'inline'; }
            else           { newBadge.style.display = 'none'; }
        }
        var sidebarBadge = document.getElementById('sidebarAppBadge');
        if (sidebarBadge) {
            sidebarBadge.textContent = count > 0 ? count : '';
            sidebarBadge.style.display = count > 0 ? 'inline-flex' : 'none';
        }
    }


    /* ══════════════════════════════════════════════════════════════════
       NEW (this adjustment): INBOX — NEW REQUIREMENT SUBMISSIONS
       The drawer shows new student submissions (above the application
       requests) as cards like company_validation.php's "Requirement
       Uploaded" notifications. "View Requirements" marks it viewed (it leaves
       the Inbox and the indicator), closes the drawer and opens that student's
       row — on the right page, clearing a search / course filter that would
       hide it — with the "New upload" tag on each submitted card.
       ══════════════════════════════════════════════════════════════════ */
    var _sruRows = [];
    function cvSruEsc(v) { return escHtml(v == null ? '' : String(v)); }
    function cvSruCard(r) {
        var items = (r.items || []).map(function (i) { return cvSruEsc(i.label || i.key || ''); }).join(', ');
        var isPlace = (r.kind === 'placement');   // NEW (this adjustment)
        var isSched = (r.kind === 'schedule');    // NEW (this adjustment): schedule changed by the supervisor
        return '<div class="moa-card moa-notif-card" id="sruCard_' + r.id + '">' +
                 '<div class="moa-card-top"><div class="moa-card-info"><div class="moa-card-company">' + cvSruEsc(r.full_name || 'Student') + '</div></div>' +
                   (isSched ? '<div class="moa-notif-type-badge notif-schedule"><i class="fas fa-calendar-days"></i> Schedule Changed</div></div>'
                    : isPlace ? '<div class="moa-notif-type-badge notif-placement"><i class="fas fa-right-left"></i> Placement Replaced</div></div>'
                            : '<div class="moa-notif-type-badge notif-uploaded"><i class="fas fa-file-arrow-up"></i> Requirement Uploaded</div></div>') +
                 '<div class="moa-card-detail-row"><div class="moa-card-info">' +
                   '<div class="moa-card-meta"><span><i class="fas fa-envelope" style="font-size:10px;"></i> ' + cvSruEsc(r.email || '—') + '</span>' +
                     '<span><i class="fas fa-clock" style="font-size:10px;"></i> ' + cvSruEsc(r.when || '—') + '</span></div>' +
                   '<div class="moa-card-address moa-notif-upload-detail"><i class="fas ' + (isSched ? 'fa-calendar-days' : isPlace ? 'fa-right-left' : 'fa-file-arrow-up') + '" style="font-size:10px;"></i> ' + items + '</div>' +
                 '</div><div class="moa-card-actions">' +
                   '<button type="button" class="moa-action-btn accept-btn" id="sruViewBtn_' + r.id + '" onclick="cvViewStudentUpload(' + r.id + ', ' + r.user_id + ')">' +
                     '<i class="fas ' + (isSched ? 'fa-calendar-days' : isPlace ? 'fa-right-left' : 'fa-file-arrow-up') + '"></i> View Requirements</button>' +
                 '</div></div></div>';
    }
    function cvRenderStudentUploads(rows) {
        _sruRows = Array.isArray(rows) ? rows : [];
        var box = document.getElementById('studentUploadInbox'), title = document.getElementById('appRequestSectionTitle');
        if (!box) return;
        if (!_sruRows.length) { box.style.display = 'none'; box.innerHTML = ''; if (title) title.style.display = 'none'; return; }
        box.innerHTML = '<div class="sru-section-title"><i class="fas fa-file-arrow-up"></i> New Requirement Submissions <span class="sru-count">' + _sruRows.length + '</span></div>' +
                        _sruRows.map(cvSruCard).join('');
        box.style.display = '';
        if (title) title.style.display = '';
    }
    window.cvRenderStudentUploads = cvRenderStudentUploads;
    function cvLoadStudentUploads() {
        fetch(window.location.pathname + '?student_upload_list=1', { credentials: 'same-origin', cache: 'no-store' })
            .then(function (r) { return r.json(); })
            .then(function (d) { if (!d || !d.success) return; updateAppBadge(d.count || 0); cvRenderStudentUploads(d.rows || []); })
            .catch(function () {});
    }
    function cvFocusStudentSubmission(uid, keys) {
        var row = document.getElementById('student-row-' + uid);
        if (!row) {
            // NEW (this adjustment): the student may have been verified earlier and is back for a new Application SIT
            // (placement replaced) — fetch their row first; only when it still is not there is the student gone.
            if (typeof svEnsureStudentRow === 'function') {
                svEnsureStudentRow(uid).then(function (r) {
                    if (r) cvFocusStudentSubmission(uid, keys);
                    else showGuardModal('', 'Student Not Found', 'This student is no longer in the list — they may have been archived or deleted.');
                });
                return;
            }
            showGuardModal('', 'Student Not Found', 'This student is no longer in the list — they may have been archived or deleted.');
            return;
        }
        var group = row.dataset.group === 'verified' ? 'verified' : 'pending';
        var list = group === 'verified' ? _filteredVerified : _filteredPending;
        if (list.indexOf(row) === -1) {   // hidden by the search / course filter → clear them
            var si = document.getElementById('searchInput'), cf = document.getElementById('courseFilter');
            if (si) si.value = '';
            if (cf) cf.value = 'All';
            filterAll();
            list = group === 'verified' ? _filteredVerified : _filteredPending;
        }
        var idx = list.indexOf(row);
        if (idx >= 0) {
            var page = Math.floor(idx / ROWS_PER_PAGE) + 1;
            if (group === 'verified') currentPageVerified = page; else currentPagePending = page;
            renderPage(group);
        }
        var toggle = row.querySelector('.toggle-input');
        if (toggle) toggle.checked = true;
        setTimeout(function () {
            try { row.scrollIntoView({ behavior: 'smooth', block: 'start' }); } catch (e) { row.scrollIntoView(); }
            (keys || []).forEach(function (k) {
                var isPlacement = (k === '__placement' || k === '__schedule');
                if (k === '__placement') k = 'application_sit';   // NEW (this adjustment): the card that needs the new submission (no "New upload" tag — nothing was uploaded yet)
                else if (k === '__schedule') k = 'application_sit';   // NEW (this adjustment): schedule changed → the new Application SIT
                var card = (k === '__photo') ? row.querySelector('.profile-card') : document.getElementById('req-item-' + uid + '-' + k);
                if (!card) return;
                if (!isPlacement) svFlagNewUpload(card);
                card.classList.remove('just-updated'); void card.offsetWidth; card.classList.add('just-updated');
                setTimeout(function () { card.classList.remove('just-updated'); }, 1400);
            });
        }, 80);
    }
    function cvViewStudentUpload(id, uid) {
        var btn = document.getElementById('sruViewBtn_' + id);
        if (btn) btn.disabled = true;
        var fd = new FormData();
        fd.append('ajax_student_upload_viewed', '1'); fd.append('id', id); fd.append('user_id', uid);
        fetch(window.location.pathname, { method: 'POST', body: fd, credentials: 'same-origin' })
            .then(function (r) { return r.json(); })
            .then(function (d) {
                if (!d || !d.success) {
                    if (btn) btn.disabled = false;
                    showGuardModal('', 'Could Not Open', (d && d.message) || 'The submission could not be opened. Please try again.');
                    return;
                }
                updateAppBadge(d.count || 0);
                cvRenderStudentUploads(_sruRows.filter(function (x) { return x.id !== id; }));
                var ov = document.getElementById('appRequestOverlay');
                if (ov && ov.style.display === 'flex') closeAppInbox();
                cvFocusStudentSubmission(d.user_id || uid, d.keys || []);
            })
            .catch(function () {
                if (btn) btn.disabled = false;
                showGuardModal('', 'Could Not Open', 'Something went wrong while opening the submission. Please check your connection and try again.');
            });
    }
    window.cvViewStudentUpload = cvViewStudentUpload;
    function escHtml(str) {
        if (!str) return '';
        return String(str).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
    }

    // ── LIVE APPLICATION REQUEST DETECTION (background badge sync) ──────
    // Tracks the last known pending-application-request count. This
    // background poll keeps the badge (navbar + sidebar) in sync even
    // while the drawer is CLOSED. While the drawer is OPEN, the faster
    // pollAppDrawerLive()/diffAndUpdateAppInbox() pair above handles both
    // the badge and the live card list (additions AND removals), so this
    // background poll simply keeps _lastKnownAppCount current and no
    // longer needs to trigger a full drawer reload itself.
    let _lastKnownAppCount = <?= (int)$app_request_count ?>;

    // UPDATED (this adjustment): the background poll now reads
    // ?app_request_list=1 — the same count as ?app_request_count=1 (which is
    // kept, company_validation.php uses it) plus each request's id / student /
    // company — so a newly detected request also raises the popup
    // notification (notifyNewAppRequests()) even while the drawer is closed.
    // The badge update and _lastKnownAppCount handling are unchanged.
    setTimeout(function () {
        setInterval(function () {
            fetch(window.location.pathname + '?app_request_list=1')
                .then(function (r) { return r.json(); })
                .then(function (data) {
                    var newCount = data.count || 0;
                    updateAppBadge(newCount);
                    notifyNewAppRequests(data.rows || []);
                    _lastKnownAppCount = newCount;
                })
                .catch(function(){});
        }, APP_REQUEST_POLL_INTERVAL);
    }, APP_REQUEST_INITIAL_DELAY);

    // ════════════════════════════════════════════════════════════════════════
    //  NEW (this adjustment) — APPLICATION REQUEST POPUP NOTIFICATION
    //  Same design and behaviour as company_validation.php's notification popup
    //  (cvShowTopToast() / cvLayoutTopToasts()): shown at the TOP of the page,
    //  icon + "<strong>Student</strong> what happened — check the inbox.",
    //  gone by itself after 7 seconds, stacking below this page's undo toast.
    //
    //  _seenAppRequestIds starts with every request that was already pending
    //  when the page loaded, so only requests detected AFTER that pop up. All
    //  three places that fetch the request list (background poll, drawer open,
    //  drawer live poll) go through notifyNewAppRequests(), and each id is
    //  announced only once no matter which of them sees it first.
    // ════════════════════════════════════════════════════════════════════════
    var _seenAppRequestIds = new Set(<?= json_encode(array_map('strval', $app_request_known_ids)) ?>);

    function notifyNewAppRequests(rows) {
        if (!rows || !rows.length) return;
        rows.forEach(function (app) {
            var key = String(app.id);
            if (_seenAppRequestIds.has(key)) return;
            _seenAppRequestIds.add(key);
            cvShowTopToast(
                app.full_name || 'A student',
                'submitted a new application request' + (app.company_name ? ' for ' + app.company_name : ''),
                'fa-envelope-open-text'
            );
            // NEW (this adjustment): the popup just shown (last element added to <body>) opens this request when clicked
            if (window.cvTagToast && document.body.lastElementChild && document.body.lastElementChild.classList.contains('cv-top-toast')) window.cvTagToast(document.body.lastElementChild, 'app:' + app.id);
        });
    }

    function cvLayoutTopToasts() {
        var undo = document.getElementById('undoToast');
        var top = 30;
        // below the undo toast while it shows — measured from where it actually sits on this page (top:80px)
        if (undo && undo.classList.contains('show')) top = Math.max(top, undo.offsetTop + undo.offsetHeight + 12);
        document.querySelectorAll('.cv-top-toast').forEach(function (el) {
            el.style.top = top + 'px';
            top += el.offsetHeight + 12;
        });
    }
    (function () {
        var undo = document.getElementById('undoToast');
        if (undo && window.MutationObserver) new MutationObserver(cvLayoutTopToasts).observe(undo, { attributes: true, attributeFilter: ['class'] });
    })();

    function cvShowTopToast(name, messageText, iconClass) {
        var div = document.createElement('div');
        div.className = 'cv-top-toast';
        div.setAttribute('role', 'status');
        div.innerHTML = '<i class="fas ' + escHtml(iconClass || 'fa-envelope-open-text') + '"></i><span><strong>' + escHtml(name) + '</strong> ' + escHtml(messageText) + ' \u2014 check the Application Requests inbox.</span>';
        document.body.appendChild(div);
        cvLayoutTopToasts();
        // next frame, so the hidden state is painted first and the fade-in animates
        requestAnimationFrame(function () { div.classList.add('show'); });
        setTimeout(function () {
            div.classList.remove('show');
            setTimeout(function () { div.remove(); cvLayoutTopToasts(); }, 400);
        }, 7000);
    }

    // ════════════════════════════════════════════════════════════════════════
    //  NEW (this adjustment) — COMPANY REQUIREMENTS SIDE-MENU INDICATOR (live)
    //  Keeps #sidebarMoaBadge in step with company_validation.php's
    //  Notification Inbox without a page reload — same 15-second cadence
    //  company_validation.php uses for its own notification check.
    // ════════════════════════════════════════════════════════════════════════
    function pollCompanyReqBadge() {
        fetch(window.location.pathname + '?moa_notif_count=1')
            .then(function (r) { return r.json(); })
            .then(function (data) {
                var badge = document.getElementById('sidebarMoaBadge');
                if (!badge) return;
                var count = data.count || 0;
                badge.textContent = count;
                badge.style.display = count > 0 ? 'inline-flex' : 'none';
            })
            .catch(function () {});
    }
    setTimeout(function () { pollCompanyReqBadge(); setInterval(pollCompanyReqBadge, 15000); }, 4000);
</script>
<!-- ══════════════════════════════════════════════════════════════════════
     NEW (endorsement flow): ENDORSEMENT LETTER COMPOSER
     ------------------------------------------------------------------------
     Opened by "Allow" (summary card or Full View). Prefilled from the
     application (student, course, company contact/address, today's date);
     the admin completes the rest while a live preview renders the real
     ENDORSEMENT_form_builder.php output. "Approve & Send Letter" runs the
     original approve request with the letter attached, which issues it to
     the student's Inbox on company_list.php.
     ══════════════════════════════════════════════════════════════════════ -->
<style>
    #endoComposerOverlay { display:none; position:fixed; inset:0; z-index:10050; background:rgba(0,0,0,0.72); flex-direction:column; }
    #endoComposerOverlay.open { display:flex; }
    #endoComposerOverlay * { box-sizing:border-box; }
    .endo-c-toolbar { background:#07145f; padding:0.55rem 1.5rem; display:flex; align-items:center; justify-content:space-between; gap:12px; flex-wrap:wrap; box-shadow:0 2px 10px rgba(0,0,0,0.35); flex-shrink:0; }
    .endo-c-title { font-family:'Segoe UI',sans-serif; font-size:0.9rem; font-weight:700; color:#fff; display:flex; align-items:center; gap:10px; min-width:0; }
    .endo-c-title i { color:#FFD700; }
    .endo-c-title span { white-space:nowrap; overflow:hidden; text-overflow:ellipsis; }
    .endo-c-actions { display:flex; gap:8px; flex-shrink:0; }
    .endo-c-cancel { background:rgba(255,255,255,0.14); color:rgba(255,255,255,0.92); border:1px solid rgba(255,255,255,0.28); padding:7px 16px; border-radius:6px; font-size:12.5px; font-weight:600; cursor:pointer; }
    .endo-c-cancel:hover { background:rgba(255,255,255,0.26); color:#fff; }
    .endo-c-send { background:#16a34a; color:#fff; border:none; padding:8px 18px; border-radius:6px; font-size:12.5px; font-weight:700; cursor:pointer; display:inline-flex; align-items:center; gap:7px; }
    .endo-c-send:hover:not(:disabled) { opacity:0.9; }
    .endo-c-send:disabled { opacity:0.55; cursor:not-allowed; }
    /* UPDATED: letter form on the RIGHT, live preview on the left (CSS order only — markup unchanged). */
    .endo-c-body { flex:1; min-height:0; display:grid; grid-template-columns:1fr 380px; }
    .endo-c-form { background:#fff; overflow-y:auto; padding:18px 20px 30px; border-left:1px solid #e2e8f0; font-family:'Segoe UI',sans-serif; order:2; }
    .endo-c-intro { font-size:12px; color:#475569; background:#eff6ff; border:1px solid #bfdbfe; border-radius:8px; padding:9px 12px; margin-bottom:14px; line-height:1.5; }
    .endo-c-group { font-size:10.5px; font-weight:700; color:#07145f; text-transform:uppercase; letter-spacing:.06em; margin:16px 0 8px; padding-bottom:4px; border-bottom:2px solid #FFD700; }
    .endo-c-field { margin-bottom:10px; }
    .endo-c-field label { display:block; font-size:11.5px; font-weight:700; color:#475569; margin-bottom:4px; }
    .endo-c-field label .req { color:#dc2626; margin-left:2px; }
    .endo-c-field input, .endo-c-field textarea { width:100%; padding:8px 10px; border:1px solid #cbd5e1; border-radius:7px; font-size:13px; font-family:inherit; color:#1e293b; background:#fff; }
    .endo-c-field textarea { resize:vertical; min-height:56px; }
    .endo-c-field input:focus, .endo-c-field textarea:focus { outline:none; border-color:#1a4a8a; box-shadow:0 0 0 3px rgba(26,74,138,0.12); }
    .endo-c-field.invalid input, .endo-c-field.invalid textarea { border-color:#dc2626; background:#fff7f7; }
    .endo-c-row { display:grid; grid-template-columns:1fr 1fr; gap:10px; }
    .endo-c-hint { font-size:10.5px; color:#94a3b8; margin-top:3px; }
    .endo-c-error { display:none; font-size:12px; font-weight:600; color:#991b1b; background:#fef2f2; border:1px solid #fecaca; border-radius:8px; padding:9px 12px; margin-top:12px; }
    .endo-c-preview { background:#d8dde8; overflow:auto; padding:18px 12px 30px; position:relative; order:1; }
    .endo-c-preview-label { font-family:'Courier New',monospace; font-size:10px; letter-spacing:.12em; color:#5a6a8a; text-transform:uppercase; text-align:center; margin-bottom:10px; }
    .endo-c-scale-box { margin:0 auto; position:relative; }
    #endoPreviewFrame { border:none; width:834px; transform-origin:top left; position:absolute; top:0; left:0; background:transparent; }
    /* UPDATED: loading screen while the letter is being prepared */
    .endo-c-body { position:relative; }
    /* UPDATED (consistent loading design): the preview's "Updating" tag in the same palette,
       with a mini version of .global-loading-spinner. Declared after .endo-c-loading's
       original rule below, via higher specificity, so only its look changes. */
    .endo-c-preview .endo-c-loading { background:rgba(238, 241, 246, 0.97); color:#1B2A4A; border:1px solid #A3AFC7; text-transform:uppercase; letter-spacing:0.6px; font-family:'Segoe UI', Tahoma, Geneva, Verdana, sans-serif; box-shadow:0 2px 8px rgba(27,42,74,0.12); align-items:center; gap:7px; }
    .endo-c-preview .endo-c-loading[style*="block"] { display:inline-flex !important; }
    .endo-c-mini-spin { width:12px; height:12px; border-radius:50%; border:2px solid #A3AFC7; border-top-color:#1B2A4A; animation:globalLoadingSpin 0.85s linear infinite; display:inline-block; }
    .endo-c-preview.is-updating .endo-c-scale-box { opacity:.55; transition:opacity .2s; }
    .endo-c-scale-box { transition:opacity .2s; }
    .endo-c-loading { position:absolute; top:44px; left:50%; transform:translateX(-50%); background:#07145f; color:#fff; font-size:11px; font-weight:700; padding:5px 12px; border-radius:20px; display:none; z-index:2; }
    /* UPDATED (atomic names): First / Middle / Last inputs */
    .endo-c-name-row { display:grid; grid-template-columns:1fr 1fr 1fr; gap:6px; }
    .endo-c-name-row > div { min-width:0; }
    .endo-c-name-cap { display:block; font-size:9.5px; font-weight:700; color:#94a3b8; text-transform:uppercase; letter-spacing:.05em; margin-top:2px; padding-left:2px; }
    .endo-c-field.invalid .endo-c-name-row input.part-missing { border-color:#dc2626; background:#fff7f7; }
    .endo-c-field.invalid .endo-c-name-row input:not(.part-missing) { border-color:#cbd5e1; background:#fff; }
    .endo-c-field.invalid .endo-c-name-row input[readonly]:not(.part-missing) { background:#f1f5f9; }
    .endo-c-student-row { display:flex; align-items:flex-start; gap:6px; margin-bottom:6px; }
    .endo-c-student-row .endo-c-name-row { flex:1; min-width:0; }
    .endo-c-stu-no { font-size:11px; font-weight:700; color:#64748b; width:16px; padding-top:9px; flex-shrink:0; text-align:right; }
    .endo-c-rm { background:#fff1f1; color:#dc2626; border:1px solid #fecaca; width:30px; height:34px; border-radius:7px; cursor:pointer; flex-shrink:0; font-size:12px; }
    .endo-c-rm:hover { background:#dc2626; color:#fff; }
    .endo-c-add { background:#f8f7ff; color:#07145f; border:1px dashed #a5b4fc; border-radius:7px; padding:6px 12px; font-size:12px; font-weight:700; cursor:pointer; font-family:inherit; }
    .endo-c-add:hover { background:#ece9ff; }
    /* UPDATED: auto-filled names = one locked full-name box; names you type = First / Middle / Last */
    .endo-c-name-full { width:100%; padding:8px 10px; border:1px solid #cbd5e1; border-radius:7px; font-size:13px; font-family:inherit; }
    .endo-c-name.is-locked .endo-c-name-row,
    .endo-c-name.is-locked .endo-c-atomic-only { display:none; }
    .endo-c-name:not(.is-locked) .endo-c-name-full,
    .endo-c-name:not(.is-locked) .endo-c-locked-only { display:none; }
    #endoStudentLocked .endo-c-name-full + .endo-c-name-full { margin-top:6px; }
    .endo-c-field.invalid .endo-c-name-full { border-color:#dc2626; background:#fff7f7; }
    /* UPDATED: locked Addressee (Company) fields */
    .endo-c-field[data-autofill-plain]:not(.is-locked) .endo-c-locked-only { display:none; }
    .endo-c-field[data-autofill-plain].is-locked .endo-c-unlocked-only { display:none; }
    .endo-c-field textarea[readonly] { background:#f1f5f9; color:#334155; cursor:default; resize:none; }
    .endo-c-field textarea[readonly]:focus { border-color:#cbd5e1; box-shadow:none; }
    /* UPDATED (saved signatories): dropdown + "Other" → First / Middle / Last */
    .endo-c-sig-pick { display:flex; gap:6px; }
    .endo-c-sig-select { flex:1; min-width:0; padding:8px 10px; border:1px solid #cbd5e1; border-radius:7px; font-size:13px; font-family:inherit; color:#1e293b; background:#fff; cursor:pointer; }
    .endo-c-sig-select:focus { outline:none; border-color:#1a4a8a; box-shadow:0 0 0 3px rgba(26,74,138,0.12); }
    .endo-c-field.invalid .endo-c-sig-select { border-color:#dc2626; background:#fff7f7; }
    .endo-c-sig-del { background:#fff; color:#94a3b8; border:1px solid #e2e8f0; width:34px; border-radius:7px; cursor:pointer; flex-shrink:0; font-size:12px; }
    .endo-c-sig-del:hover { background:#fff1f1; color:#dc2626; border-color:#fecaca; }
    .endo-c-field[data-signatory]:not(.sig-saved) .endo-c-sig-del { display:none; }
    .endo-c-field[data-signatory] .endo-c-name-row { margin-top:6px; }
    .endo-c-field[data-signatory]:not(.sig-other) .endo-c-name-row,
    .endo-c-field[data-signatory]:not(.sig-other) .endo-c-sig-other-only { display:none; }

    /* UPDATED: course-offering driven Training section */
    .endo-c-field input[readonly] { background:#f1f5f9; color:#334155; cursor:default; }
    .endo-c-field input[readonly]:focus { border-color:#cbd5e1; box-shadow:none; }
    .endo-c-lock { font-size:9px; color:#94a3b8; margin-left:3px; }
    .endo-c-month-row { display:grid; grid-template-columns:1fr 74px; gap:6px; }
    .endo-c-month-row select { width:100%; padding:8px 10px; border:1px solid #cbd5e1; border-radius:7px; font-size:13px; font-family:inherit; color:#1e293b; background:#fff; cursor:pointer; }
    .endo-c-month-row select:focus { outline:none; border-color:#1a4a8a; box-shadow:0 0 0 3px rgba(26,74,138,0.12); }
    .endo-c-field.invalid select { border-color:#dc2626; background:#fff7f7; }
    .endo-c-month-row input { text-align:center; }
    .endo-c-course-note { display:none; font-size:11.5px; line-height:1.5; border-radius:8px; padding:8px 11px; margin-bottom:10px; }
    .endo-c-course-note.ok   { display:block; background:#f0fdf4; border:1px solid #bbf7d0; color:#166534; }
    .endo-c-course-note.warn { display:block; background:#fffbeb; border:1px solid #fde68a; color:#92400e; }
    .endo-c-course-note a { color:inherit; font-weight:700; }
    .endo-c-schedule { font-size:11px; color:#475569; background:#f8fafc; border:1px dashed #cbd5e1; border-radius:7px; padding:7px 10px; margin:-2px 0 6px; display:none; }
    #endoSentToast { position:fixed; bottom:30px; left:50%; transform:translateX(-50%) translateY(90px); background:#1e293b; color:#fff; padding:13px 22px; border-radius:12px; font-family:'Segoe UI',sans-serif; font-size:13.5px; font-weight:600; display:flex; align-items:center; gap:10px; z-index:10060; opacity:0; transition:transform .35s cubic-bezier(0.34,1.56,0.64,1), opacity .3s; border-left:4px solid #16a34a; box-shadow:0 8px 30px rgba(0,0,0,0.25); }
    #endoSentToast.show { transform:translateX(-50%) translateY(0); opacity:1; }
    @media (max-width: 900px) {
        .endo-c-body { grid-template-columns:1fr; grid-template-rows:auto 1fr; overflow-y:auto; }
        .endo-c-form { border-left:none; border-bottom:1px solid #e2e8f0; overflow:visible; order:1; }
        .endo-c-preview { min-height:520px; order:2; }
    }
</style>

<div id="endoComposerOverlay" aria-modal="true" role="dialog">
    <div class="endo-c-toolbar">
        <div class="endo-c-title"><i class="fas fa-envelope-open-text"></i> <span id="endoComposerTitle">Endorsement Letter</span></div>
        <div class="endo-c-actions">
            <button type="button" class="endo-c-cancel" onclick="closeEndorsementComposer()"><i class="fas fa-times"></i> Cancel</button>
            <button type="button" class="endo-c-send" id="endoComposerSend" onclick="submitEndorsementComposer()"><i class="fas fa-paper-plane"></i> Approve &amp; Send Letter</button>
        </div>
    </div>
    <div class="endo-c-body">
        <div class="endo-c-form" id="endoComposerForm">
            <div class="endo-c-intro">
                <i class="fas fa-info-circle"></i> Approving this application issues this <strong>Endorsement Letter</strong> to the student's Inbox. The student prints it, has it signed, and uploads it for the company to validate.
            </div>

            <div class="endo-c-group">Letter</div>
            <!-- UPDATED: auto-filled from the student's College on AccomForm.php (locked when set). -->
            <div class="endo-c-field" data-key="department_name" data-autofill-plain="1"><label>Department / College<span class="req">*</span> <i class="fas fa-lock endo-c-lock endo-c-locked-only" title="From the student’s Requirements form (College)"></i></label><input type="text" placeholder="e.g. College of Information and Communications Technology">
                <div class="endo-c-hint endo-c-locked-only">From the College the student entered on their Requirements form.</div>
                <div class="endo-c-hint endo-c-unlocked-only">The student hasn’t entered their College on the Requirements form yet — type it here.</div>
            </div>
            <div class="endo-c-field" data-key="letter_date"><label>Letter Date<span class="req">*</span></label><input type="text" placeholder="e.g. July 15, 2026"></div>

            <div class="endo-c-group">Addressee (Company)</div>
            <!-- UPDATED (atomic names): every person name is First / Middle / Last. -->
            <div class="endo-c-field endo-c-name" data-key="recipient_name" data-name-field="1" data-autofill="1"><label>Recipient Name<span class="req">*</span> <i class="fas fa-lock endo-c-lock endo-c-locked-only" title="Filled in automatically"></i></label>
                <input type="hidden">
                <input type="text" class="endo-c-name-full" readonly tabindex="-1">
                <div class="endo-c-name-row">
                    <div><input type="text" data-part="first" placeholder="First name"><span class="endo-c-name-cap">First</span></div>
                    <div><input type="text" data-part="middle" placeholder="Middle name"><span class="endo-c-name-cap">Middle</span></div>
                    <div><input type="text" data-part="last" placeholder="Last name"><span class="endo-c-name-cap">Last</span></div>
                </div>
            </div>
            <div class="endo-c-field" data-key="recipient_position" data-autofill-plain="1"><label>Position <i class="fas fa-lock endo-c-lock endo-c-locked-only" title="Filled in automatically"></i></label><input type="text" placeholder="e.g. HR Manager"></div>
            <div class="endo-c-field endo-c-name" data-key="salutation_name" data-name-field="1" data-autofill="1"><label>Salutation Name <i class="fas fa-lock endo-c-lock endo-c-locked-only" title="Filled in automatically"></i></label>
                <input type="hidden">
                <input type="text" class="endo-c-name-full" readonly tabindex="-1">
                <div class="endo-c-name-row">
                    <div><input type="text" data-part="first" placeholder="First name"><span class="endo-c-name-cap">First</span></div>
                    <div><input type="text" data-part="middle" placeholder="Middle name"><span class="endo-c-name-cap">Middle</span></div>
                    <div><input type="text" data-part="last" placeholder="Last name"><span class="endo-c-name-cap">Last</span></div>
                </div>
                <div class="endo-c-hint endo-c-atomic-only">Used after “Dear”. Leave blank to use the recipient’s name.</div>
            </div>
            <div class="endo-c-field" data-key="company_name" data-autofill-plain="1"><label>Company Name<span class="req">*</span> <i class="fas fa-lock endo-c-lock endo-c-locked-only" title="Filled in automatically"></i></label><input type="text"></div>
            <div class="endo-c-field" data-key="recipient_address" data-autofill-plain="1"><label>Company Address<span class="req">*</span> <i class="fas fa-lock endo-c-lock endo-c-locked-only" title="Filled in automatically"></i></label><textarea rows="2"></textarea></div>

            <div class="endo-c-group">Training</div>
            <!-- UPDATED: Training section is driven by course_offering.php (course, Total Hour
                 Requirement, Required Hours per Day, Est. Duty Days). Only the start month is chosen. -->
            <div class="endo-c-course-note" id="endoCourseNote"></div>
            <div class="endo-c-field" data-key="program"><label>Program / Course<span class="req">*</span> <i class="fas fa-lock endo-c-lock" title="From Course Offering"></i></label><input type="text" readonly></div>
            <div class="endo-c-row">
                <div class="endo-c-field" data-key="required_hours"><label>Total Hour Requirement<span class="req">*</span> <i class="fas fa-lock endo-c-lock" title="From Course Offering"></i></label><input type="text" readonly placeholder="e.g. 486"></div>
                <div class="endo-c-field endo-c-info"><label>Required Hours per Day <i class="fas fa-lock endo-c-lock" title="From Course Offering"></i></label><input type="text" id="endoDailyHours" readonly></div>
            </div>
            <div class="endo-c-row">
                <div class="endo-c-field" data-key="start_date"><label>Start<span class="req">*</span></label>
                    <input type="hidden">
                    <div class="endo-c-month-row">
                        <select id="endoStartMonth" aria-label="Start month"></select>
                        <input type="text" id="endoStartYear" readonly aria-label="Start year (current year)" title="Current year">
                    </div>
                </div>
                <div class="endo-c-field" data-key="end_date"><label>End<span class="req">*</span> <i class="fas fa-lock endo-c-lock" title="Start + Est. Duty Days"></i></label><input type="text" readonly placeholder="e.g. Nov 2026"></div>
            </div>
            <div class="endo-c-schedule" id="endoScheduleHint"></div>
            <div class="endo-c-field endo-c-name" data-key="students" data-students="1" data-autofill="1"><label>Student/s<span class="req">*</span> <i class="fas fa-lock endo-c-lock endo-c-locked-only" title="Filled in automatically"></i></label>
                <input type="hidden">
                <!-- UPDATED: auto-filled students → locked full names; otherwise First / Middle / Last rows -->
                <div id="endoStudentLocked" class="endo-c-locked-only"></div>
                <div id="endoStudentRows" class="endo-c-atomic-only"></div>
                <button type="button" class="endo-c-add endo-c-atomic-only" onclick="endoAddStudentRow(null, true)"><i class="fas fa-plus"></i> Add student</button>
            </div>

            <div class="endo-c-group">Signatories</div>
            <div class="endo-c-field endo-c-name" data-key="adviser_name" data-name-field="1" data-autofill="1"><label>OJT Adviser<span class="req">*</span> <i class="fas fa-lock endo-c-lock" title="The logged-in administrator"></i></label>
                <input type="hidden">
                <input type="text" class="endo-c-name-full" readonly tabindex="-1">
                <div class="endo-c-name-row">
                    <div><input type="text" data-part="first" readonly placeholder="First name"><span class="endo-c-name-cap">First</span></div>
                    <div><input type="text" data-part="middle" readonly placeholder="Middle name"><span class="endo-c-name-cap">Middle</span></div>
                    <div><input type="text" data-part="last" readonly placeholder="Last name"><span class="endo-c-name-cap">Last</span></div>
                </div>
                <div class="endo-c-hint">You, as the issuing administrator.</div>
            </div>
            <!-- UPDATED (saved signatories): pick a saved name, or "Other" to type a new one (First / Middle / Last). -->
            <div class="endo-c-field endo-c-name" data-key="dean_name" data-name-field="1" data-signatory="dean"><label>Dean / Director<span class="req">*</span></label>
                <input type="hidden">
                <div class="endo-c-sig-pick">
                    <select class="endo-c-sig-select" aria-label="Dean / Director"></select>
                    <button type="button" class="endo-c-sig-del" title="Remove this saved name from the list" onclick="endoDeleteSignatory(this)"><i class="fas fa-trash-alt"></i></button>
                </div>
                <div class="endo-c-name-row">
                    <div><input type="text" data-part="first" placeholder="First name"><span class="endo-c-name-cap">First</span></div>
                    <div><input type="text" data-part="middle" placeholder="Middle name"><span class="endo-c-name-cap">Middle</span></div>
                    <div><input type="text" data-part="last" placeholder="Last name"><span class="endo-c-name-cap">Last</span></div>
                </div>
                <div class="endo-c-hint endo-c-sig-other-only">New names are saved to this list when you send the letter.</div>
            </div>
            <div class="endo-c-field" data-key="dean_title"><label>Dean Title</label><input type="text" placeholder="Dean"></div>
            <div class="endo-c-field endo-c-name" data-key="director_name" data-name-field="1" data-signatory="director"><label>OJT-CDC Director (Noted by)</label>
                <input type="hidden">
                <div class="endo-c-sig-pick">
                    <select class="endo-c-sig-select" aria-label="OJT-CDC Director"></select>
                    <button type="button" class="endo-c-sig-del" title="Remove this saved name from the list" onclick="endoDeleteSignatory(this)"><i class="fas fa-trash-alt"></i></button>
                </div>
                <div class="endo-c-name-row">
                    <div><input type="text" data-part="first" placeholder="First name"><span class="endo-c-name-cap">First</span></div>
                    <div><input type="text" data-part="middle" placeholder="Middle name"><span class="endo-c-name-cap">Middle</span></div>
                    <div><input type="text" data-part="last" placeholder="Last name"><span class="endo-c-name-cap">Last</span></div>
                </div>
                <div class="endo-c-hint endo-c-sig-other-only">New names are saved to this list when you send the letter. Leave blank to use RANDY M. BAÑEZ, J.D.</div>
            </div>

            <div class="endo-c-error" id="endoComposerError"></div>
        </div>
        <div class="endo-c-preview" id="endoPreviewPane">
            <div class="endo-c-preview-label">Live Preview</div>
            <div class="endo-c-loading" id="endoPreviewLoading"><span class="endo-c-mini-spin"></span> Updating<span class="global-loading-dots"><span>.</span><span>.</span><span>.</span></span></div>
            <div class="endo-c-scale-box" id="endoPreviewScaleBox">
                <iframe id="endoPreviewFrame" title="Endorsement letter preview"></iframe>
            </div>
        </div>
    </div>
</div>

<div id="endoSentToast"><i class="fas fa-envelope-circle-check" style="color:#4ade80;"></i> <span id="endoSentToastMsg"></span></div>

<!-- ══════════════════════════════════════════════════════════════════════
     UPDATED: APPROVE & SEND LETTER — STATUS SCREEN
     Loading while the approval runs, then Success / Partial / Failed.
     ══════════════════════════════════════════════════════════════════════ -->
<style>
    #endoStatusOverlay { display:none; position:fixed; inset:0; z-index:10070; background:rgba(7,20,95,0.55); backdrop-filter:blur(3px); align-items:center; justify-content:center; padding:20px; font-family:'Segoe UI',sans-serif; }
    #endoStatusOverlay.open { display:flex; }
    .endo-st-box { background:#fff; width:440px; max-width:100%; border-radius:16px; box-shadow:0 24px 70px rgba(0,0,0,0.35); overflow:hidden; text-align:center; animation:endoStPop .28s cubic-bezier(0.34,1.56,0.64,1); position:relative; }
    @keyframes endoStPop { from { transform:scale(0.9); opacity:0; } to { transform:scale(1); opacity:1; } }
    .endo-st-band { height:5px; background:#07145f; }
    .endo-st-box[data-state="success"] .endo-st-band { background:#16a34a; }
    .endo-st-box[data-state="partial"] .endo-st-band { background:#d97706; }
    .endo-st-box[data-state="failed"]  .endo-st-band { background:#dc2626; }
    .endo-st-body { padding:30px 30px 24px; }
    .endo-st-icon { width:74px; height:74px; margin:0 auto 16px; border-radius:50%; display:flex; align-items:center; justify-content:center; font-size:32px; }
    .endo-st-box[data-state="loading"] .endo-st-icon { background:#eef2ff; }
    .endo-st-box[data-state="success"] .endo-st-icon { background:#dcfce7; color:#16a34a; }
    .endo-st-box[data-state="partial"] .endo-st-icon { background:#fef3c7; color:#d97706; }
    .endo-st-box[data-state="failed"]  .endo-st-icon { background:#fee2e2; color:#dc2626; }
    .endo-st-spinner { width:46px; height:46px; border-radius:50%; border:4px solid #c7d2fe; border-top-color:#07145f; animation:endoStSpin .8s linear infinite; }
    @keyframes endoStSpin { to { transform:rotate(360deg); } }
    .endo-st-title { font-size:18px; font-weight:800; color:#0f172a; margin:0 0 6px; }
    .endo-st-who { display:inline-flex; align-items:center; gap:7px; flex-wrap:wrap; justify-content:center; font-size:12.5px; font-weight:700; color:#07145f; background:#f1f5f9; border-radius:20px; padding:5px 12px; margin:4px 0 12px; }
    .endo-st-who i { color:#94a3b8; font-size:10px; }
    .endo-st-msg { font-size:13px; color:#475569; line-height:1.55; margin:0; }
    .endo-st-reason { margin-top:12px; background:#fef2f2; border:1px solid #fecaca; color:#7f1d1d; border-radius:9px; padding:9px 12px; font-size:12.5px; text-align:left; word-break:break-word; }
    .endo-st-reason b { display:block; font-size:10.5px; text-transform:uppercase; letter-spacing:.05em; color:#991b1b; margin-bottom:2px; }
    .endo-st-actions { display:flex; gap:10px; justify-content:center; margin-top:22px; }
    .endo-st-btn { border:none; border-radius:9px; padding:10px 22px; font-size:13px; font-weight:700; cursor:pointer; font-family:inherit; display:inline-flex; align-items:center; gap:7px; }
    .endo-st-btn.primary { background:#07145f; color:#fff; }
    .endo-st-btn.primary:hover { background:#1a237e; }
    .endo-st-btn.retry { background:#dc2626; color:#fff; }
    .endo-st-btn.retry:hover { opacity:.9; }
    .endo-st-btn.ghost { background:#f1f5f9; color:#475569; }
    .endo-st-btn.ghost:hover { background:#e2e8f0; }
    .endo-st-dots span { animation:endoStDot 1.2s infinite both; }
    .endo-st-dots span:nth-child(2) { animation-delay:.2s; }
    .endo-st-dots span:nth-child(3) { animation-delay:.4s; }
    @keyframes endoStDot { 0%,80%,100% { opacity:0; } 40% { opacity:1; } }
    .endo-st-timer { position:absolute; left:0; bottom:0; height:3px; background:#16a34a; width:0; }
    .endo-st-timer.run { width:100%; transition:width linear; }
</style>
<div id="endoStatusOverlay" role="alertdialog" aria-modal="true" aria-live="assertive">
    <div class="endo-st-box" id="endoStatusBox" data-state="loading">
        <div class="endo-st-band"></div>
        <div class="endo-st-body">
            <div class="endo-st-icon" id="endoStatusIcon"></div>
            <h3 class="endo-st-title" id="endoStatusTitle"></h3>
            <div class="endo-st-who" id="endoStatusWho"></div>
            <p class="endo-st-msg" id="endoStatusMsg"></p>
            <div class="endo-st-reason" id="endoStatusReason" style="display:none;"></div>
            <div class="endo-st-actions" id="endoStatusActions"></div>
        </div>
        <div class="endo-st-timer" id="endoStatusTimer"></div>
    </div>
</div>
<script>
    /* ══════════════════════════════════════════════════════════════════
       UPDATED: APPROVE & SEND LETTER — STATUS SCREEN (logic)
       endoStatusLoading(id)       → spinner while the approve request runs
       endoStatusDone(data)        → Success (or Partial if the letter wasn't saved)
       endoStatusFailed(msg, fn)   → Failed with the reason + Try Again (same letter)
       ══════════════════════════════════════════════════════════════════ */
    var ENDO_STATUS_MIN_MS   = 900;   // loading stays up at least this long, so it never flashes
    var ENDO_STATUS_AUTO_MS  = 5000;  // success closes itself after this long
    var _endoStatusStart = 0, _endoStatusBusy = false, _endoStatusAutoTimer = null, _endoStatusWho = null;

    function endoStatusApp(approvalId) {
        var app = (typeof _fvAllApps !== 'undefined' && _fvAllApps)
            ? _fvAllApps.find(function (a) { return String(a.id) === String(approvalId); }) : null;
        if (app) return { name: app.full_name || '', company: app.company_name || '' };
        // UPDATED: fall back to the letter just composed (student + company), so the
        // notification can always name the student even if the requests list isn't loaded.
        try {
            var stu = endoInput(endoFieldByKey('students')).value.split(/\r?\n/)[0].trim();
            var co  = endoInput(endoFieldByKey('company_name')).value.trim();
            if (stu) return { name: stu, company: co };
        } catch (e) {}
        return null;
    }

    function endoStatusRender(state, title, msg, opts) {
        opts = opts || {};
        var box = document.getElementById('endoStatusBox');
        box.dataset.state = state;
        document.getElementById('endoStatusIcon').innerHTML = {
            loading: '<div class="endo-st-spinner"></div>',
            success: '<i class="fas fa-circle-check"></i>',
            partial: '<i class="fas fa-triangle-exclamation"></i>',
            failed:  '<i class="fas fa-circle-xmark"></i>'
        }[state];
        document.getElementById('endoStatusTitle').innerHTML = title;
        var who = document.getElementById('endoStatusWho');
        if (_endoStatusWho && _endoStatusWho.name) {
            who.innerHTML = '<span>' + escHtml(_endoStatusWho.name) + '</span>' +
                (_endoStatusWho.company ? '<i class="fas fa-arrow-right"></i><span>' + escHtml(_endoStatusWho.company) + '</span>' : '');
            who.style.display = 'inline-flex';
        } else { who.style.display = 'none'; }
        document.getElementById('endoStatusMsg').innerHTML = msg;
        var reason = document.getElementById('endoStatusReason');
        if (opts.reason) { reason.innerHTML = '<b>Reason</b>' + escHtml(opts.reason); reason.style.display = 'block'; }
        else { reason.style.display = 'none'; }

        var actions = document.getElementById('endoStatusActions');
        actions.innerHTML = '';
        (opts.buttons || []).forEach(function (b) {
            var el = document.createElement('button');
            el.type = 'button'; el.className = 'endo-st-btn ' + b.cls; el.innerHTML = b.html;
            el.addEventListener('click', b.fn);
            actions.appendChild(el);
        });
        actions.style.display = (opts.buttons && opts.buttons.length) ? 'flex' : 'none';

        var timer = document.getElementById('endoStatusTimer');
        clearTimeout(_endoStatusAutoTimer);
        timer.classList.remove('run'); timer.style.transitionDuration = '0ms'; timer.style.width = '0';
        if (opts.autoClose) {
            void timer.offsetWidth;
            timer.style.transitionDuration = ENDO_STATUS_AUTO_MS + 'ms';
            timer.classList.add('run'); timer.style.width = '100%';
            _endoStatusAutoTimer = setTimeout(endoStatusClose, ENDO_STATUS_AUTO_MS);
        }
        document.getElementById('endoStatusOverlay').classList.add('open');
        var focusBtn = actions.querySelector('button'); if (focusBtn) focusBtn.focus();
    }

    // Wait out the minimum loading time before showing the result.
    function endoStatusAfterMin(fn) {
        var left = Math.max(0, ENDO_STATUS_MIN_MS - (Date.now() - _endoStatusStart));
        setTimeout(function () { endoGlobalRelease('status'); fn(); }, left); // UPDATED: end the page loader, then show the result
    }

    function endoStatusLoading(approvalId) {
        _endoStatusWho   = endoStatusApp(approvalId);
        _endoStatusStart = Date.now();
        _endoStatusBusy  = true;
        // UPDATED (same loading page as the rest of the page): full-screen "LOADING" while sending.
        document.getElementById('endoStatusOverlay').classList.remove('open');
        endoGlobalHold('status', ENDO_LOADER_LABELS.sending); // UPDATED: detailed label
    }

    function endoStatusDone(data) {
        endoStatusAfterMin(function () {
            _endoStatusBusy = false;
            if (data && data.endorsement_sent) {
                // UPDATED: success is shown as the SAME popup notification used for new student
                // application requests (.cv-top-toast), instead of a centred card.
                endoShowApprovedToast(_endoStatusWho);
            } else {
                endoStatusRender('partial', 'Approved, but the letter was not saved',
                    'The application was approved and moved to the company, but the endorsement letter could not be saved. Please check the server error log.',
                    { buttons: [{ cls: 'primary', html: 'Close', fn: endoStatusClose }] });
            }
        });
    }

    /* UPDATED: "approved & letter sent" popup — identical to the student-application popup
       notification (cvShowTopToast / showStudentVerifiedToast): the same .cv-top-toast navy bar
       at the top of the page, green icon + student name in bold, stacking via
       cvLayoutTopToasts(), fading out by itself after 7 seconds. */
    function endoShowApprovedToast(who) {
        var name = who && who.name ? who.name : '';
        var company = who && who.company ? who.company : '';
        var div = document.createElement('div');
        div.className = 'cv-top-toast';
        div.setAttribute('role', 'status');
        div.innerHTML = '<i class="fas fa-envelope-circle-check"></i><span>' +
            (name
                ? '<strong>' + escHtml(name) + '</strong> was approved' + (company ? ' for ' + escHtml(company) : '') +
                  ' \u2014 the endorsement letter is now in their Inbox.'
                : 'Application approved \u2014 the endorsement letter is now in the student\u2019s Inbox.') +
            '</span>';
        document.body.appendChild(div);
        cvLayoutTopToasts();
        requestAnimationFrame(function () { div.classList.add('show'); });
        setTimeout(function () {
            div.classList.remove('show');
            setTimeout(function () { div.remove(); cvLayoutTopToasts(); }, 400);
        }, 7000);
    }

    function endoStatusFailed(message, retryFn) {
        endoStatusAfterMin(function () {
            _endoStatusBusy = false;
            endoStatusRender('failed', 'Could not approve the application',
                'Nothing was sent to the student. You can try again with the same letter, or close this and review the application.',
                { reason: message || 'Unknown error', buttons: [
                    { cls: 'ghost', html: 'Close', fn: endoStatusClose },
                    { cls: 'retry', html: '<i class="fas fa-rotate-right"></i> Try Again', fn: function () { endoStatusClose(); if (typeof retryFn === 'function') retryFn(); } }
                ] });
        });
    }

    function endoStatusClose() {
        if (_endoStatusBusy) return; // never while the request is still running
        clearTimeout(_endoStatusAutoTimer);
        document.getElementById('endoStatusOverlay').classList.remove('open');
    }

    document.addEventListener('keydown', function (e) {
        if (!document.getElementById('endoStatusOverlay').classList.contains('open')) return;
        if (e.key === 'Escape') { e.stopPropagation(); endoStatusClose(); }
    }, true);
    window.addEventListener('beforeunload', function (e) {
        if (_endoStatusBusy) { e.preventDefault(); e.returnValue = ''; }
    });
</script>

<script>
    /* ══════════════════════════════════════════════════════════════════
       NEW (endorsement flow): ENDORSEMENT LETTER COMPOSER — logic
       ══════════════════════════════════════════════════════════════════ */
    var ENDO_REQUIRED = ['department_name','letter_date','recipient_name','company_name','recipient_address',
                         'program','required_hours','start_date','end_date','students','adviser_name','dean_name'];
    // Values that are usually the same across letters — remembered on this browser to save typing.
    // UPDATED: Training values (course-offering driven) and the adviser (the admin) are no longer remembered.
    // UPDATED (saved signatories): Dean / OJT-CDC Director names now come from the saved list in the database.
    var ENDO_REMEMBER = ['department_name','dean_title'];
    var ENDO_LS_KEY   = 'admin_endorsement_letter_last_values';
    var _endoOnConfirm = null;
    var _endoPreviewTimer = null;
    var _endoPreviewSeq = 0;

    function endoFields() { return document.querySelectorAll('#endoComposerForm .endo-c-field[data-key]'); }
    function endoInput(field) { return field.querySelector('input, textarea'); }

    /* ══════════════════════════════════════════════════════════════════
       UPDATED (atomic names): First / Middle / Last name inputs
       Each name field keeps a hidden input (the one endoInput() returns)
       holding the combined "First Middle Last" value, so collect / preview /
       validation / sending keep working exactly as before.
       ══════════════════════════════════════════════════════════════════ */
    var ENDO_NAME_KEYS = ['first', 'middle', 'last'];

    function endoJoinName(p) {
        return ENDO_NAME_KEYS.map(function (k) { return String((p && p[k]) || '').trim(); }).filter(Boolean).join(' ');
    }
    // Fallback only (server-provided parts are preferred): first word / middle words / last word.
    function endoSplitName(str) {
        var t = String(str || '').trim().split(/\s+/).filter(Boolean);
        if (!t.length)      return { first: '', middle: '', last: '' };
        if (t.length === 1) return { first: t[0], middle: '', last: '' };
        return { first: t[0], middle: t.slice(1, -1).join(' '), last: t[t.length - 1] };
    }
    function endoGetParts(row) {
        var p = {};
        ENDO_NAME_KEYS.forEach(function (k) { var i = row.querySelector('input[data-part="' + k + '"]'); p[k] = i ? i.value.trim() : ''; });
        return p;
    }
    function endoSetParts(row, p) {
        ENDO_NAME_KEYS.forEach(function (k) { var i = row.querySelector('input[data-part="' + k + '"]'); if (i) i.value = (p && p[k]) || ''; });
    }

    function endoStudentRowsEl() { return document.getElementById('endoStudentRows'); }
    function endoRenumberStudents() {
        var rows = endoStudentRowsEl().querySelectorAll('.endo-c-student-row');
        rows.forEach(function (r, i) {
            r.querySelector('.endo-c-stu-no').textContent = (i + 1) + '.';
            r.querySelector('.endo-c-rm').style.visibility = rows.length > 1 ? 'visible' : 'hidden';
        });
    }
    function endoAddStudentRow(parts, focus) {
        var row = document.createElement('div');
        row.className = 'endo-c-student-row';
        row.innerHTML =
            '<span class="endo-c-stu-no"></span>' +
            '<div class="endo-c-name-row">' +
                '<div><input type="text" data-part="first" placeholder="First name"><span class="endo-c-name-cap">First</span></div>' +
                '<div><input type="text" data-part="middle" placeholder="Middle name"><span class="endo-c-name-cap">Middle</span></div>' +
                '<div><input type="text" data-part="last" placeholder="Last name"><span class="endo-c-name-cap">Last</span></div>' +
            '</div>' +
            '<button type="button" class="endo-c-rm" title="Remove student" onclick="endoRemoveStudentRow(this)"><i class="fas fa-times"></i></button>';
        endoSetParts(row, parts);
        endoStudentRowsEl().appendChild(row);
        endoRenumberStudents();
        if (focus) row.querySelector('input[data-part="first"]').focus();
        return row;
    }
    function endoRemoveStudentRow(btn) {
        var rows = endoStudentRowsEl().querySelectorAll('.endo-c-student-row');
        if (rows.length <= 1) return;
        btn.closest('.endo-c-student-row').remove();
        endoRenumberStudents();
        endoRefreshPreview(false);
    }
    function endoSetStudents(list) {
        endoStudentRowsEl().innerHTML = '';
        (list && list.length ? list : [{}]).forEach(function (p) { endoAddStudentRow(p, false); });
    }

    /* UPDATED: auto-filled names are shown as ONE locked full-name box (class is-locked);
       names the admin types (Dean, OJT-CDC Director — or an auto-fill that came back empty)
       stay First / Middle / Last. */
    function endoIsLocked(f) { return !!(f && f.classList.contains('is-locked')); }

    function endoSetNameMode(f, locked, fullName) {
        if (!f) return;
        f.classList.toggle('is-locked', !!locked);
        if (f.dataset.students) {
            var box = document.getElementById('endoStudentLocked');
            box.innerHTML = '';
            if (locked) {
                String(fullName || '').split(/\r?\n/).map(function (s) { return s.trim(); }).filter(Boolean).forEach(function (n) {
                    var i = document.createElement('input');
                    i.type = 'text'; i.className = 'endo-c-name-full'; i.readOnly = true; i.tabIndex = -1; i.value = n;
                    box.appendChild(i);
                });
            }
        } else {
            var full = f.querySelector('.endo-c-name-full');
            if (full) full.value = locked ? String(fullName || '') : '';
        }
    }

    // Writes the combined names into each name field's hidden input.
    function endoSyncNames() {
        document.querySelectorAll('#endoComposerForm .endo-c-field[data-name-field]').forEach(function (f) {
            if (endoIsLocked(f)) { endoInput(f).value = f.querySelector('.endo-c-name-full').value.trim(); return; } // UPDATED
            if (f.dataset.signatory && !f.classList.contains('sig-other')) { endoInput(f).value = endoSigSelectedName(f); return; } // UPDATED (saved signatories)
            endoInput(f).value = endoJoinName(endoGetParts(f.querySelector('.endo-c-name-row')));
        });
        var sf = endoFieldByKey('students');
        if (sf) {
            if (endoIsLocked(sf)) { // UPDATED: locked student names
                endoInput(sf).value = Array.prototype.map.call(
                    document.querySelectorAll('#endoStudentLocked .endo-c-name-full'),
                    function (i) { return i.value.trim(); }
                ).filter(Boolean).join('\n');
                return;
            }
            endoInput(sf).value = Array.prototype.map.call(
                endoStudentRowsEl().querySelectorAll('.endo-c-student-row'),
                function (r) { return endoJoinName(endoGetParts(r)); }
            ).filter(Boolean).join('\n');
        }
    }

    // Required name = First AND Last; a student row that's partly filled must also have both.
    function endoNameFieldInvalid(f) {
        if (endoIsLocked(f)) return !endoInput(f).value.trim(); // UPDATED: locked = just needs a value
        if (f.dataset.signatory && !f.classList.contains('sig-other')) return !endoInput(f).value.trim(); // UPDATED (saved signatories)
        var rows = f.dataset.students
            ? Array.prototype.slice.call(endoStudentRowsEl().querySelectorAll('.endo-c-student-row'))
            : [f.querySelector('.endo-c-name-row')];
        var bad = false, anyComplete = false;
        f.querySelectorAll('input[data-part]').forEach(function (i) { i.classList.remove('part-missing'); });
        rows.forEach(function (r) {
            var p = endoGetParts(r);
            var touched = !!(p.first || p.middle || p.last);
            if (p.first && p.last) { anyComplete = true; return; }
            if (touched || rows.length === 1) {
                bad = true;
                if (!p.first) r.querySelector('input[data-part="first"]').classList.add('part-missing');
                if (!p.last)  r.querySelector('input[data-part="last"]').classList.add('part-missing');
            }
        });
        return bad || !anyComplete;
    }

    function endoCollect() {
        endoSyncNames(); // UPDATED (atomic names)
        var out = {};
        endoFields().forEach(function (f) {
            var v = endoInput(f).value;
            if (f.dataset.key === 'students') v = v.split(/\r?\n/).map(function (s) { return s.trim(); }).filter(Boolean).join('\n');
            out[f.dataset.key] = v.trim();
        });
        return out;
    }

    // UPDATED (atomic names): optional `parts` = { key: {first,middle,last}, students: [{...}] }.
    // UPDATED: optional `locks` = { key: true } → that auto-filled name is shown locked as a full name.
    function endoFill(values, parts, locks) {
        endoFields().forEach(function (f) {
            var v = values[f.dataset.key];
            endoInput(f).value = (v === undefined || v === null) ? '' : String(v);
            f.classList.remove('invalid');
            f.querySelectorAll('input[data-part]').forEach(function (i) { i.classList.remove('part-missing'); });
            if (f.dataset.autofillPlain) {
                // UPDATED: Addressee (Company) details are auto-filled → locked (editable only if empty).
                var lockPlain = !!(locks && locks[f.dataset.key]) && endoInput(f).value.trim() !== '';
                f.classList.toggle('is-locked', lockPlain);
                endoInput(f).readOnly = lockPlain;
            }
            if ((f.dataset.nameField || f.dataset.students) && f.dataset.autofill) {
                // Auto-filled + has a value → locked full name. Empty auto-fill → typed First / Middle / Last.
                endoSetNameMode(f, !!(locks && locks[f.dataset.key]) && endoInput(f).value.trim() !== '', endoInput(f).value);
            }
            if (f.dataset.nameField) {
                var p = (parts && parts[f.dataset.key]) || endoSplitName(endoInput(f).value);
                endoSetParts(f.querySelector('.endo-c-name-row'), p);
            } else if (f.dataset.students) {
                var list = (parts && parts.students && parts.students.length)
                    ? parts.students
                    : endoInput(f).value.split(/\r?\n/).map(function (s) { return s.trim(); }).filter(Boolean).map(endoSplitName);
                endoSetStudents(list);
            }
        });
        endoSyncNames();
    }

    function openEndorsementComposer(approvalId, onConfirm) {
        _endoOnConfirm = onConfirm;
        var app = (typeof _fvAllApps !== 'undefined' && _fvAllApps) ? _fvAllApps.find(function (a) { return String(a.id) === String(approvalId); }) : null;
        document.getElementById('endoComposerTitle').textContent =
            'Endorsement Letter' + (app ? ' — ' + app.full_name + (app.company_name ? ' → ' + app.company_name : '') : '');
        var errEl = document.getElementById('endoComposerError');
        errEl.style.display = 'none';
        var sendBtn = document.getElementById('endoComposerSend');
        sendBtn.disabled = true;
        sendBtn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Loading…';

        // Instant prefill from what the page already knows, refined by the server defaults below.
        endoFill({ students: app ? app.full_name : '', program: app ? (app.course || '') : '', company_name: app ? (app.company_name || '') : '' },
                 null, { students: true }); // UPDATED: student name is auto-filled → locked
        document.getElementById('endoComposerOverlay').classList.add('open');
        document.body.style.overflow = 'hidden';
        endoLoaderShow(); // UPDATED: loading screen until the letter is ready

        var fd = new FormData();
        fd.append('ajax_endorsement_defaults', '1');
        fd.append('approval_id', approvalId);
        fetch(window.location.pathname, { method: 'POST', body: fd })
            .then(function (r) { return r.json(); })
            .then(function (res) {
                if (!res.success) {
                    closeEndorsementComposer();
                    alert(res.message || 'Unable to prepare the endorsement letter.');
                    return;
                }
                endoLoaderStep('details', 'done'); // UPDATED
                var values = res.defaults || {};
                var remembered = {};
                try { remembered = JSON.parse(localStorage.getItem(ENDO_LS_KEY) || '{}') || {}; } catch (e) {}
                // UPDATED (atomic names): server parts, plus remembered Dean / Director parts.
                var parts = Object.assign({}, res.name_parts || {});
                var rememberedParts = remembered._parts || {};
                ENDO_REMEMBER.forEach(function (k) {
                    if (remembered[k] && !values[k]) {
                        values[k] = remembered[k];
                        if (rememberedParts[k]) parts[k] = rememberedParts[k];
                    }
                    if (k === 'dean_title' && remembered[k]) values[k] = remembered[k];
                });
                // UPDATED: names the system filled in are locked (full name); the rest stay First / Middle / Last.
                endoFill(values, parts, {
                    recipient_name: true, salutation_name: true, students: true, adviser_name: true,
                    recipient_position: true, company_name: true, recipient_address: true, // UPDATED: Addressee locked
                    department_name: true // UPDATED: from the student's College (AccomForm.php)
                });
                endoApplySignatories(res.signatories || {}); // UPDATED (saved signatories)
                endoApplyTraining(res.training || null, values.start_date); // UPDATED
                sendBtn.disabled = false;
                sendBtn.innerHTML = '<i class="fas fa-paper-plane"></i> Approve &amp; Send Letter';
                endoRefreshPreview(true);
            })
            .catch(function () {
                endoLoaderStep('details', 'warn'); // UPDATED: details unavailable — continue with manual entry
                endoApplyTraining(null, ''); // UPDATED: fall back to manual entry
                endoApplySignatories({}); // UPDATED (saved signatories): no list → type the names
                // UPDATED: defaults could not be loaded → let the admin type the names (adviser stays locked).
                ['recipient_name', 'salutation_name'].forEach(function (k) { endoSetNameMode(endoFieldByKey(k), false, ''); });
                sendBtn.disabled = false;
                sendBtn.innerHTML = '<i class="fas fa-paper-plane"></i> Approve &amp; Send Letter';
                endoRefreshPreview(true);
            });
    }

    /* ══════════════════════════════════════════════════════════════════
       UPDATED: TRAINING SECTION ← course_offering.php
       Start = month dropdown + current year. End = Start + Est. Duty Days
       (ceil(Total Hour Requirement / Required Hours per Day), Mon–Fri only),
       mirroring endoTrainingSchedule() on the server, which re-applies the
       same rule when the letter is saved.
       ══════════════════════════════════════════════════════════════════ */
    var ENDO_MONTHS = ['January','February','March','April','May','June','July','August','September','October','November','December'];
    // UPDATED: abbreviated month names for the dropdown and the letter (e.g. "Aug 2026").
    var ENDO_MONTHS_SHORT = ENDO_MONTHS.map(function (m) { return m.substring(0, 3); });
    var _endoTraining = null;

    function endoFieldByKey(k) { return document.querySelector('#endoComposerForm .endo-c-field[data-key="' + k + '"]'); }

    (function endoBuildMonthOptions() {
        var sel = document.getElementById('endoStartMonth');
        if (!sel || sel.options.length) return;
        ENDO_MONTHS.forEach(function (m, i) {
            var o = document.createElement('option');
            o.value = String(i + 1); o.textContent = ENDO_MONTHS_SHORT[i]; o.title = m;
            sel.appendChild(o);
        });
    })();

    function endoSchedule(month, year, dutyDays) {
        var d = new Date(year, month - 1, 1);
        while (d.getDay() === 0 || d.getDay() === 6) d.setDate(d.getDate() + 1);
        var start = new Date(d.getTime());
        var count = 1;
        while (count < Math.max(1, dutyDays)) {
            d.setDate(d.getDate() + 1);
            if (d.getDay() !== 0 && d.getDay() !== 6) count++;
        }
        return { start: start, end: d };
    }

    function endoFmtDate(d) {
        return ENDO_MONTHS[d.getMonth()].substring(0, 3) + ' ' + d.getDate() + ', ' + d.getFullYear();
    }

    function endoRecomputeSchedule() {
        var t     = _endoTraining || {};
        var year  = parseInt(t.year, 10) || new Date().getFullYear();
        var month = parseInt(document.getElementById('endoStartMonth').value, 10) || (new Date().getMonth() + 1);
        endoInput(endoFieldByKey('start_date')).value = ENDO_MONTHS_SHORT[month - 1] + ' ' + year;
        var endInput = endoInput(endoFieldByKey('end_date'));
        var hint = document.getElementById('endoScheduleHint');
        if (t.found && t.est_days > 0) {
            var s = endoSchedule(month, year, t.est_days);
            endInput.value = ENDO_MONTHS_SHORT[s.end.getMonth()] + ' ' + s.end.getFullYear();
            hint.innerHTML = '<i class="fas fa-calendar-check"></i> ' + t.est_days + ' duty days (Mon–Fri) &middot; ' +
                             endoFmtDate(s.start) + ' &rarr; ' + endoFmtDate(s.end);
            hint.style.display = 'block';
        } else {
            hint.style.display = 'none';
        }
    }

    function endoApplyTraining(training, startLabel) {
        _endoTraining = training || { found: false, year: new Date().getFullYear(), month: new Date().getMonth() + 1 };
        var t = _endoTraining;
        document.getElementById('endoStartYear').value = t.year;

        // Month from the defaults ("August 2026") → dropdown; otherwise the server's month.
        var month = parseInt(t.month, 10) || (new Date().getMonth() + 1);
        var lbl = String(startLabel || '').toLowerCase();
        ENDO_MONTHS_SHORT.forEach(function (m, i) { if (lbl.indexOf(m.toLowerCase()) === 0) month = i + 1; }); // UPDATED: "Aug…" or "August…"
        document.getElementById('endoStartMonth').value = String(month);

        var daily = document.getElementById('endoDailyHours');
        daily.value = t.found ? (t.daily_hours + ' hrs / day') : '';
        daily.placeholder = t.found ? '' : 'Not set';

        // From Course Offering → locked. Course not offered → hours / end entered manually.
        ['program', 'required_hours', 'end_date'].forEach(function (k) {
            var inp = endoInput(endoFieldByKey(k));
            if (k === 'program') { inp.readOnly = true; return; }
            inp.readOnly = !!t.found;
        });
        if (t.found) {
            endoInput(endoFieldByKey('program')).value        = t.course;
            endoInput(endoFieldByKey('required_hours')).value = t.total_hours;
        }

        var note = document.getElementById('endoCourseNote');
        if (t.found) {
            note.className = 'endo-c-course-note ok';
            note.innerHTML = '<i class="fas fa-circle-check"></i> Based on <strong>' + escHtml(t.course) + '</strong> in Course Offering: ' +
                             escHtml(t.total_hours) + ' hrs total &middot; ' + escHtml(t.daily_hours) + ' hrs/day &middot; ' + t.est_days + ' est. duty days.';
        } else {
            note.className = 'endo-c-course-note warn';
            note.innerHTML = '<i class="fas fa-triangle-exclamation"></i> ' +
                (t.student_course ? 'The course <strong>' + escHtml(t.student_course) + '</strong> is not' : 'This student\u2019s course is not') +
                ' in <a href="course_offering.php" target="_blank" rel="noopener">Course Offering</a>. Enter the Total Hour Requirement and End manually, or add the course there.';
        }
        endoRecomputeSchedule();
    }

    document.getElementById('endoStartMonth').addEventListener('change', function () {
        endoRecomputeSchedule();
        endoRefreshPreview(false);
    });

    /* ══════════════════════════════════════════════════════════════════
       UPDATED (saved signatories): Dean / OJT-CDC Director dropdowns
       Options = names saved from earlier letters (most recent first) +
       "Other (type a new name)", which shows the First / Middle / Last boxes.
       The Director list also has the builder's default (RANDY M. BAÑEZ, J.D.).
       ══════════════════════════════════════════════════════════════════ */
    var ENDO_SIG_OTHER   = '__other__';
    var ENDO_SIG_DEFAULT = '__default__';
    var _endoSigs = { dean: [], director: [] };

    function endoSigRecord(f) {
        var sel = f && f.querySelector('.endo-c-sig-select');
        if (!sel) return null;
        var list = _endoSigs[f.dataset.signatory] || [];
        return list.find(function (s) { return String(s.id) === sel.value; }) || null;
    }
    function endoSigSelectedName(f) {
        var rec = endoSigRecord(f);
        return rec ? rec.full_name : '';
    }
    function endoSigSelectedParts(f) {
        if (!f) return null;
        if (f.classList.contains('sig-other')) {
            var p = endoGetParts(f.querySelector('.endo-c-name-row'));
            return (p.first || p.last) ? p : null;
        }
        var rec = endoSigRecord(f);
        return rec ? { first: rec.first, middle: rec.middle, last: rec.last } : null;
    }

    function endoSigModeFromSelect(f) {
        var sel = f.querySelector('.endo-c-sig-select');
        var other = sel.value === ENDO_SIG_OTHER;
        f.classList.toggle('sig-other', other);
        f.classList.toggle('sig-saved', !other && sel.value !== ENDO_SIG_DEFAULT && sel.value !== '');
        f.classList.remove('invalid');
        f.querySelectorAll('input[data-part]').forEach(function (i) { i.classList.remove('part-missing'); });
        // A saved Dean brings back the title it was saved with.
        var rec = endoSigRecord(f);
        if (f.dataset.signatory === 'dean' && rec && rec.title) endoInput(endoFieldByKey('dean_title')).value = rec.title;
        endoSyncNames();
    }

    function endoBuildSigSelect(role, selectValue) {
        var f = document.querySelector('#endoComposerForm .endo-c-field[data-signatory="' + role + '"]');
        if (!f) return;
        var sel = f.querySelector('.endo-c-sig-select');
        var list = _endoSigs[role] || [];
        sel.innerHTML = '';
        if (list.length) {
            var grp = document.createElement('optgroup');
            grp.label = 'Saved names';
            list.forEach(function (s) {
                var o = document.createElement('option');
                o.value = String(s.id); o.textContent = s.full_name;
                grp.appendChild(o);
            });
            sel.appendChild(grp);
        }
        if (role === 'director') {
            var d = document.createElement('option');
            d.value = ENDO_SIG_DEFAULT; d.textContent = 'Default — RANDY M. BAÑEZ, J.D.';
            sel.appendChild(d);
        }
        var oth = document.createElement('option');
        oth.value = ENDO_SIG_OTHER; oth.textContent = 'Other (type a new name)…';
        sel.appendChild(oth);

        var values = Array.prototype.map.call(sel.options, function (o) { return o.value; });
        sel.value = (selectValue && values.indexOf(selectValue) !== -1)
            ? selectValue
            : (list.length ? String(list[0].id) : (role === 'director' ? ENDO_SIG_DEFAULT : ENDO_SIG_OTHER));
        endoSigModeFromSelect(f);
    }

    function endoApplySignatories(sigs) {
        _endoSigs = { dean: (sigs && sigs.dean) || [], director: (sigs && sigs.director) || [] };
        endoBuildSigSelect('dean');
        endoBuildSigSelect('director');
    }

    document.querySelectorAll('#endoComposerForm .endo-c-sig-select').forEach(function (sel) {
        sel.addEventListener('change', function () {
            var f = sel.closest('.endo-c-field');
            endoSigModeFromSelect(f);
            if (f.classList.contains('sig-other')) {
                var first = f.querySelector('input[data-part="first"]');
                if (first) first.focus();
            }
            endoRefreshPreview(false);
        });
    });

    function endoDeleteSignatory(btn) {
        var f = btn.closest('.endo-c-field');
        var rec = endoSigRecord(f);
        if (!rec) return;
        if (!confirm('Remove "' + rec.full_name + '" from the saved list?\n\nLetters already sent are not affected.')) return;
        btn.disabled = true;
        var fd = new FormData();
        fd.append('ajax_endorsement_signatory_delete', '1');
        fd.append('signatory_id', rec.id);
        fetch(window.location.pathname, { method: 'POST', body: fd })
            .then(function (r) { return r.json(); })
            .then(function (res) {
                btn.disabled = false;
                if (res && res.signatories) {
                    var keepDean = endoFieldByKey('dean_name').querySelector('.endo-c-sig-select').value;
                    var keepDir  = endoFieldByKey('director_name').querySelector('.endo-c-sig-select').value;
                    _endoSigs = { dean: res.signatories.dean || [], director: res.signatories.director || [] };
                    endoBuildSigSelect('dean', keepDean);
                    endoBuildSigSelect('director', keepDir);
                    endoRefreshPreview(false);
                }
            })
            .catch(function () { btn.disabled = false; alert('Network error. Please try again.'); });
    }

    /* ══════════════════════════════════════════════════════════════════
       UPDATED: LETTER LOADING SCREEN
       Shown over the composer from the moment it opens until the letter
       preview has actually rendered its A4 pages. Steps tick off as they
       really finish (details request → rendered preview), with a minimum
       display time so it never flashes and a safety timeout so it can
       never get stuck.
       ══════════════════════════════════════════════════════════════════ */
    var ENDO_LOADER_MIN_MS = 600, ENDO_LOADER_MAX_MS = 15000;
    var _endoLoaderStart = 0, _endoLoaderSafety = null, _endoLoaderToken = 0;

    // UPDATED (same loading page as the rest of the page): the letter uses the page's own
    // full-screen #globalLoadingOverlay ("LOADING") via showGlobalLoading()/hideGlobalLoading().
    // That loader is counter-based, so each letter loader holds at most ONE count and always
    // releases it — it can never get stuck or hide another part of the page's loading.
    var ENDO_GLOBAL_LABEL = 'Loading';
    // UPDATED (detailed labels): the page loader shows what the letter is actually doing.
    var ENDO_LOADER_LABELS = {
        details: 'Loading student & company details',
        preview: 'Rendering letter preview',
        sending: 'Approving & sending letter'
    };
    var _endoGlobalHeld = { letter: false, status: false };
    function endoGlobalSetLabel(label) {
        var el = document.getElementById('globalLoadingLabel');
        if (el) el.textContent = label || ENDO_GLOBAL_LABEL;
    }
    function endoGlobalHold(key, label) {
        if (_endoGlobalHeld[key]) { endoGlobalSetLabel(label); return; }
        _endoGlobalHeld[key] = true;
        showGlobalLoading(label || ENDO_GLOBAL_LABEL);
    }
    function endoGlobalRelease(key) {
        if (!_endoGlobalHeld[key]) return;
        _endoGlobalHeld[key] = false;
        hideGlobalLoading();
    }

    var _endoLoaderState = {};

    function endoLoaderShow() {
        _endoLoaderState = {};
        endoGlobalHold('letter', ENDO_LOADER_LABELS.details);
        endoLoaderStep('details', 'active');
        _endoLoaderToken++;
        _endoLoaderStart = Date.now();
        clearTimeout(_endoLoaderSafety);
        _endoLoaderSafety = setTimeout(function () { endoLoaderHide(true); }, ENDO_LOADER_MAX_MS);
    }
    // Progress is still tracked (details → preview) to know when the letter is ready.
    function endoLoaderStep(step, state) {
        _endoLoaderState[step] = state;
        if (state === 'active' && _endoGlobalHeld.letter) endoGlobalSetLabel(ENDO_LOADER_LABELS[step]); // UPDATED
        if (step === 'details' && (state === 'done' || state === 'warn')) endoLoaderStep('preview', 'active');
    }
    function endoLoaderHide(now) {
        if (!_endoGlobalHeld.letter) return;
        clearTimeout(_endoLoaderSafety);
        var wait = now ? 0 : Math.max(0, ENDO_LOADER_MIN_MS - (Date.now() - _endoLoaderStart));
        var token = _endoLoaderToken; // a newer opening must not be hidden by an older timer
        setTimeout(function () { if (token === _endoLoaderToken) endoGlobalRelease('letter'); }, wait);
    }
    // Calls done() once the builder inside the preview iframe has drawn its pages
    // (it paginates only after its web fonts load); gives up quietly after ~6s.
    function endoWhenLetterRendered(frame, done) {
        var tries = 0;
        (function check() {
            var ok = false;
            try { ok = !!frame.contentDocument.querySelector('#rendering-preview-root .doc-paper'); } catch (e) { ok = true; }
            if (ok || ++tries > 60) { setTimeout(done, 60); return; }
            setTimeout(check, 100);
        })();
    }

    function closeEndorsementComposer() {
        endoLoaderHide(true); // UPDATED
        document.getElementById('endoComposerOverlay').classList.remove('open');
        // Keep the page scroll locked if the Full View document is still open underneath.
        var fv = document.getElementById('appFullViewOverlay');
        if (!(fv && fv.classList.contains('open'))) document.body.style.overflow = '';
        _endoOnConfirm = null;
    }

    function submitEndorsementComposer() {
        var data = endoCollect();
        var missing = [];
        endoFields().forEach(function (f) {
            var required = ENDO_REQUIRED.indexOf(f.dataset.key) !== -1;
            // UPDATED (atomic names): required names need First and Last; optional names only if partly filled.
            var bad = (f.dataset.nameField || f.dataset.students)
                ? ((required || data[f.dataset.key]) && endoNameFieldInvalid(f))
                : (required && !data[f.dataset.key]);
            f.classList.toggle('invalid', !!bad);
            if (bad) missing.push(f.querySelector('label').childNodes[0].textContent.trim());
        });
        var errEl = document.getElementById('endoComposerError');
        if (missing.length) {
            errEl.innerHTML = '<i class="fas fa-exclamation-circle"></i> Please complete: ' + missing.map(escHtml).join(', ') + '.';
            errEl.style.display = 'block';
            var firstBad = document.querySelector('#endoComposerForm .endo-c-field.invalid');
            if (firstBad) {
                firstBad.scrollIntoView({ behavior: 'smooth', block: 'center' });
                var focusEl = firstBad.querySelector('input.part-missing') || endoInput(firstBad); // UPDATED (atomic names)
                focusEl.focus();
            }
            return;
        }
        errEl.style.display = 'none';
        try {
            var keep = {};
            ENDO_REMEMBER.forEach(function (k) { if (data[k]) keep[k] = data[k]; });
            localStorage.setItem(ENDO_LS_KEY, JSON.stringify(keep));
        } catch (e) {}
        // UPDATED (saved signatories): send the First / Middle / Last behind the Dean / Director so the
        // server can save (or refresh) them in the dropdown list for the next letter.
        data._signatory_parts = {};
        ['dean_name', 'director_name'].forEach(function (k) {
            var p = endoSigSelectedParts(endoFieldByKey(k));
            if (p) data._signatory_parts[k] = p;
        });
        var cb = _endoOnConfirm;
        closeEndorsementComposer();
        if (typeof cb === 'function') cb(data);
    }

    function endoLayoutPreview() {
        var pane  = document.getElementById('endoPreviewPane');
        var box   = document.getElementById('endoPreviewScaleBox');
        var frame = document.getElementById('endoPreviewFrame');
        if (!pane || !box || !frame) return;
        var avail = pane.clientWidth - 24;
        var scale = Math.min(1, avail / 834);
        var h = 1200;
        try {
            var doc = frame.contentDocument;
            if (doc && doc.documentElement) h = Math.max(doc.documentElement.scrollHeight, doc.body ? doc.body.scrollHeight : 0, 600);
        } catch (e) {}
        frame.style.height = h + 'px';
        frame.style.transform = 'scale(' + scale + ')';
        box.style.width  = (834 * scale) + 'px';
        box.style.height = (h * scale) + 'px';
    }

    function endoRefreshPreview(immediate) {
        clearTimeout(_endoPreviewTimer);
        _endoPreviewTimer = setTimeout(function () {
            var seq = ++_endoPreviewSeq;
            document.getElementById('endoPreviewLoading').style.display = 'block';
            document.getElementById('endoPreviewPane').classList.add('is-updating'); // UPDATED
            var fd = new FormData();
            fd.append('ajax_endorsement_preview', '1');
            fd.append('letter', JSON.stringify(endoCollect()));
            fetch(window.location.pathname, { method: 'POST', body: fd })
                .then(function (r) { return r.text(); })
                .then(function (html) {
                    if (seq !== _endoPreviewSeq) return; // a newer preview is on its way
                    var frame = document.getElementById('endoPreviewFrame');
                    frame.onload = function () {
                        // The builder paginates after its web fonts load — re-measure a few times.
                        [120, 400, 900, 1600].forEach(function (t) { setTimeout(endoLayoutPreview, t); });
                        document.getElementById('endoPreviewLoading').style.display = 'none';
                        endoWhenLetterRendered(frame, function () {                     // UPDATED
                            document.getElementById('endoPreviewPane').classList.remove('is-updating');
                            endoLayoutPreview();
                            endoLoaderStep('preview', 'done');
                            endoLoaderHide();
                        });
                    };
                    frame.srcdoc = html;
                })
                .catch(function () {
                    document.getElementById('endoPreviewLoading').style.display = 'none';
                    document.getElementById('endoPreviewPane').classList.remove('is-updating'); // UPDATED
                    endoLoaderStep('preview', 'warn');
                    endoLoaderHide();
                });
        }, immediate ? 0 : 450);
    }

    document.getElementById('endoComposerForm').addEventListener('input', function (e) {
        var f = e.target.closest('.endo-c-field');
        if (f) f.classList.remove('invalid');
        endoRefreshPreview(false);
    });
    window.addEventListener('resize', function () {
        if (document.getElementById('endoComposerOverlay').classList.contains('open')) endoLayoutPreview();
    });
    document.addEventListener('keydown', function (e) {
        if (e.key === 'Escape' && document.getElementById('endoComposerOverlay').classList.contains('open')) {
            e.stopPropagation();
            closeEndorsementComposer();
        }
    }, true);

    var _endoToastTimer = null;
    function endoShowSentToast(sent) {
        var t = document.getElementById('endoSentToast');
        document.getElementById('endoSentToastMsg').textContent = sent
            ? 'Application approved — endorsement letter sent to the student\u2019s inbox.'
            : 'Application approved. (The endorsement letter could not be saved — please check the server log.)';
        t.style.borderLeftColor = sent ? '#16a34a' : '#d97706';
        t.classList.add('show');
        clearTimeout(_endoToastTimer);
        _endoToastTimer = setTimeout(function () { t.classList.remove('show'); }, 4500);
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
     it opens that student's requirements. The Student Validation indicator is
     kept in step with the Inbox total (application requests + submissions).
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
        if (r.kind === 'schedule') {   // NEW (this adjustment): schedule changed by the supervisor → the new Application SIT needs validation
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
    /* FIX (indicators firing one after another): the Student Validation side-menu badge, the popup and the navbar
       Inbox indicator are all driven by the same inbox total, but this 4 s poll used to move ONLY the side-menu
       badge (the popup and the Inbox indicator for a released application waited for the page's separate 8 s
       application-request poll — a few seconds later). Now, the moment this poll sees the total change, it also
       reads the application-request list and applies everything in ONE synchronous block: side-menu badge,
       navbar Inbox indicator ("N New" included) and every popup (new submissions here, new application requests
       through the page's own notifyNewAppRequests()) — so the three appear on the same frame. If that extra read
       fails, the badges still update exactly as before and the page's own poll announces the request later. */
    function shownCount() {
        var b = document.getElementById('sidebarAppBadge');
        if (!b || b.style.display === 'none') return 0;
        return parseInt(b.textContent, 10) || 0;
    }
    function applyAll(d, appData) {
        if (typeof window.updateAppBadge === 'function') window.updateAppBadge(parseInt(d.count, 10) || 0);   // side menu + navbar Inbox indicator together
        else setBadge(d.count);
        if (appData && Array.isArray(appData.rows) && typeof window.notifyNewAppRequests === 'function') window.notifyNewAppRequests(appData.rows);   // application-request popup
        if (typeof window.cvRenderStudentUploads === 'function') window.cvRenderStudentUploads(d.rows);   // administrator.php's Inbox, if open
        var now = new Set(d.rows.map(sigOf));
        if (known === null) { known = readStore() || now; if (known === now) { writeStore(); return; } }
        var fresh = d.rows.filter(function (r) { return !known.has(sigOf(r)); });
        known = now; writeStore();
        fresh.forEach(showPopup);
    }
    function poll() {
        if (inFlight || document.hidden) return;
        inFlight = true;
        fetch(SRU_ENDPOINT, { credentials: 'same-origin', cache: 'no-store' })
            .then(function (r) { return r.json(); })
            .then(function (d) {
                if (!d || !d.success || !Array.isArray(d.rows)) { inFlight = false; return; }
                var changed = (parseInt(d.count, 10) || 0) !== shownCount();
                if (!changed || typeof window.notifyNewAppRequests !== 'function') { inFlight = false; applyAll(d, null); return; }
                // total changed: read the application requests too, then apply everything at once
                return fetch(window.location.pathname + '?app_request_list=1', { credentials: 'same-origin', cache: 'no-store' })
                    .then(function (r) { return r.json(); })
                    .catch(function () { return null; })
                    .then(function (a) { inFlight = false; applyAll(d, a); });
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