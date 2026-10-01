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

/* ════════════════════════════════════════════════════════════════════
   SYSTEM SETTING — Database Backup
   - "Backup Now" creates a full .sql copy of the database (structure +
     data of every table, plus views) and saves it in the folder
     "Database Backup" (created next to this file if it doesn't exist).
   - "Backup History" lists every backup: who made it, when, size,
     tables/rows, and whether the file is still in the folder. Each
     backup can be downloaded or deleted.
   - The side menu carries the same notification indicators as
     administrator.php (Student Requirements / Company Requirements /
     Monitoring Dashboard), using the same counting rules, kept live
     by a background poll.
   Pure PHP (mysqli) — no mysqldump / exec() needed, so it works on
   XAMPP / shared hosting the same way.
   ════════════════════════════════════════════════════════════════════ */

// Same admin session guard as administrator.php
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
    [function () { return cv_alog_is_post('create_backup'); }, function ($conn) {
        return ['Backup Created', 'System', 'Database backup', "Created a manual database backup via System Setting"];
    }, function ($rec, $json) {
        $f = (string)($GLOBALS['fileName'] ?? '');
        if ($f === '' && is_array($json) && preg_match('/Backup created:\s*(.+)$/', (string)($json['message'] ?? ''), $m)) $f = trim($m[1]);
        if ($f !== '') { $rec[2] = $f; $rec[3] = "Created a manual database backup ($f) via System Setting"; }
        return $rec;
    }],
    [function () { return cv_alog_is_post('delete_backup'); }, function ($conn) {
        $f = (string)cv_alog_scalar($conn, "SELECT file_name FROM system_backups WHERE id = ?", 'i', [(int)($_POST['id'] ?? 0)]);
        return ['Backup Deleted', 'System', $f !== '' ? $f : 'Database backup', "Deleted the database backup " . ($f !== '' ? $f : '') . " via System Setting"];
    }],
]);

date_default_timezone_set('Asia/Manila');

define('SS_BACKUP_DIR_NAME', 'Database Backup');
define('SS_BACKUP_DIR', __DIR__ . DIRECTORY_SEPARATOR . SS_BACKUP_DIR_NAME);

if (empty($_SESSION['ss_csrf'])) {
    $_SESSION['ss_csrf'] = bin2hex(random_bytes(16));
}
$ssCsrf = $_SESSION['ss_csrf'];

/* ── Backup history table ── */
try {
    $conn->query("CREATE TABLE IF NOT EXISTS system_backups (
        id INT AUTO_INCREMENT PRIMARY KEY,
        file_name VARCHAR(255) NOT NULL UNIQUE,
        file_size BIGINT NOT NULL DEFAULT 0,
        table_count INT NOT NULL DEFAULT 0,
        row_count BIGINT NOT NULL DEFAULT 0,
        created_by VARCHAR(150) DEFAULT NULL,
        source VARCHAR(20) NOT NULL DEFAULT 'manual',
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP
    )");
} catch (\Throwable $e) {}

/* ── Admin name (same lookup as administrator.php) ── */
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
            $adminFullName = preg_replace('/\s+/', ' ', trim(
                ($adminNameRow['first_name'] ?? '') . ' ' .
                ($adminNameRow['middle_name'] ?? '') . ' ' .
                ($adminNameRow['last_name'] ?? '')
            ));
        }
    }
} catch (\Throwable $e) {}
if ($adminFullName === '') $adminFullName = trim(($_SESSION['first_name'] ?? '') . ' ' . ($_SESSION['last_name'] ?? ''));
if ($adminFullName === '') $adminFullName = 'Administrator';

/* ════════════════════════════════════════════════════════════════════
   SIDEBAR INDICATORS — same rules as administrator.php
   ════════════════════════════════════════════════════════════════════ */

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
function ss_app_request_count($conn) {
    $total = 0;
    try {
        $r = $conn->query("SELECT COUNT(*) AS total FROM admin_application_approvals aaa_c INNER JOIN users aaa_u ON aaa_u.id = aaa_c.student_id");
        if ($r) $total = (int)($r->fetch_assoc()['total'] ?? 0);
    } catch (\Throwable $e) {}
    // NEW (this adjustment): + unviewed student requirement submissions — the same count administrator.php shows
    try { $total += cv_sru_count($conn); } catch (\Throwable $e) {}
    return $total;
}
function ss_company_notification_count($conn) {
    $total = 0;
    try {
        $r = $conn->query("SELECT COUNT(*) AS total FROM moa_requests WHERE admin_viewed=0 AND transferred=1");
        if ($r) { $row = $r->fetch_assoc(); $total += (int)($row['total'] ?? 0); }
    } catch (\Throwable $e) {}
    try {
        $r = $conn->query("SELECT COUNT(*) AS total FROM company_requirement_upload_notifications WHERE admin_viewed=0");
        if ($r) { $row = $r->fetch_assoc(); $total += (int)($row['total'] ?? 0); }
    } catch (\Throwable $e) {}
    return $total;
}
function ss_ungraded_count($conn) {
    try {
        $r = $conn->query("
            SELECT COUNT(*) as total
            FROM reports r
            JOIN ojt_assignments oa ON oa.student_id = r.user_id AND oa.company_id = r.company_id
            WHERE r.week_start <= CURDATE()
              AND (r.remark IS NULL OR r.remark != 'Wrong Document')
              AND r.faculty_grade IS NULL
        ");
        if ($r) return (int)($r->fetch_assoc()['total'] ?? 0);
    } catch (\Throwable $e) {}
    return 0;
}

if (isset($_GET['sidebar_counts']) && $_GET['sidebar_counts'] === '1') {
    header('Content-Type: application/json');
    echo json_encode([
        'app'      => ss_app_request_count($conn),
        'moa'      => ss_company_notification_count($conn),
        'ungraded' => ss_ungraded_count($conn),
    ]);
    exit;
}

/* ════════════════════════════════════════════════════════════════════
   BACKUP HELPERS
   ════════════════════════════════════════════════════════════════════ */
function ss_ensure_backup_dir() {
    if (!is_dir(SS_BACKUP_DIR)) {
        if (!@mkdir(SS_BACKUP_DIR, 0755, true) && !is_dir(SS_BACKUP_DIR)) return false;
    }
    // Block direct web access to the backup files (Apache 2.2 + 2.4)
    $ht = SS_BACKUP_DIR . DIRECTORY_SEPARATOR . '.htaccess';
    if (!file_exists($ht)) {
        @file_put_contents($ht, "<IfModule mod_authz_core.c>\n    Require all denied\n</IfModule>\n<IfModule !mod_authz_core.c>\n    Order allow,deny\n    Deny from all\n</IfModule>\n");
    }
    $idx = SS_BACKUP_DIR . DIRECTORY_SEPARATOR . 'index.html';
    if (!file_exists($idx)) @file_put_contents($idx, '');
    return is_writable(SS_BACKUP_DIR);
}

function ss_safe_file_name($name) {
    $name = basename((string)$name);
    return preg_match('/^[A-Za-z0-9._-]+\.sql$/', $name) ? $name : '';
}

function ss_db_name($conn) {
    try {
        $r = $conn->query("SELECT DATABASE() AS db");
        if ($r) return (string)($r->fetch_assoc()['db'] ?? '');
    } catch (\Throwable $e) {}
    return '';
}

/* Writes a full SQL dump of the current database to $path.
   Returns ['tables' => n, 'rows' => n]. Throws on failure. */
function ss_dump_database($conn, $path) {
    @set_time_limit(0);
    $dbName = ss_db_name($conn);
    $fh = @fopen($path, 'wb');
    if (!$fh) throw new \RuntimeException('Cannot write the backup file. Check the folder permissions of "' . SS_BACKUP_DIR_NAME . '".');
    $GLOBALS['ss_dump_fh'] = $fh; // so a failed backup can close the file before deleting it

    $w = function ($s) use ($fh) {
        if (fwrite($fh, $s) === false) throw new \RuntimeException('Writing the backup file failed (disk full?).');
    };

    $conn->set_charset('utf8mb4');
    // Read TIMESTAMP columns in UTC (the dump restores with time_zone '+00:00'),
    // exactly like mysqldump does, so no times shift after a restore.
    $prevTz = null;
    try {
        $tzr = $conn->query("SELECT @@session.time_zone AS tz");
        if ($tzr) $prevTz = $tzr->fetch_assoc()['tz'] ?? null;
        $conn->query("SET time_zone = '+00:00'");
    } catch (\Throwable $e) {}
    $w("-- ------------------------------------------------------------\n");
    $w("-- Database backup\n");
    $w("-- Database : " . $dbName . "\n");
    $w("-- Created  : " . date('Y-m-d H:i:s') . " (Asia/Manila)\n");
    $w("-- Generator: system_setting.php\n");
    $w("-- ------------------------------------------------------------\n\n");
    $w("SET NAMES utf8mb4;\nSET FOREIGN_KEY_CHECKS = 0;\nSET SQL_MODE = 'NO_AUTO_VALUE_ON_ZERO';\nSET time_zone = '+00:00';\n\n");

    $tables = [];
    $views  = [];
    $res = $conn->query("SHOW FULL TABLES");
    while ($row = $res->fetch_row()) {
        if (strtoupper($row[1] ?? '') === 'VIEW') $views[] = $row[0];
        else $tables[] = $row[0];
    }
    $res->free();

    $totalRows = 0;
    foreach ($tables as $table) {
        $qt = '`' . str_replace('`', '``', $table) . '`';
        $cr = $conn->query("SHOW CREATE TABLE $qt")->fetch_row();
        $w("-- ------------------------------------------------------------\n-- Table: $table\n-- ------------------------------------------------------------\n");
        $w("DROP TABLE IF EXISTS $qt;\n" . $cr[1] . ";\n\n");

        $data = $conn->query("SELECT * FROM $qt", MYSQLI_USE_RESULT);
        if (!$data) continue;
        $fields = $data->fetch_fields();
        $isBinary = [];
        $cols = [];
        foreach ($fields as $i => $f) {
            $cols[] = '`' . str_replace('`', '``', $f->name) . '`';
            // charsetnr 63 = binary (BLOB / BINARY / VARBINARY) → write as hex
            $isBinary[$i] = ((int)$f->charsetnr === 63) && in_array((int)$f->type, [
                MYSQLI_TYPE_TINY_BLOB, MYSQLI_TYPE_MEDIUM_BLOB, MYSQLI_TYPE_LONG_BLOB, MYSQLI_TYPE_BLOB,
                MYSQLI_TYPE_STRING, MYSQLI_TYPE_VAR_STRING
            ], true);
        }
        $insertHead = "INSERT INTO $qt (" . implode(', ', $cols) . ") VALUES\n";
        $batch = [];
        while ($r = $data->fetch_row()) {
            $vals = [];
            foreach ($r as $i => $v) {
                if ($v === null)               $vals[] = 'NULL';
                elseif (!empty($isBinary[$i])) $vals[] = ($v === '' ? "''" : '0x' . bin2hex($v));
                else                           $vals[] = "'" . $conn->real_escape_string($v) . "'";
            }
            $batch[] = '(' . implode(', ', $vals) . ')';
            $totalRows++;
            if (count($batch) >= 100) { $w($insertHead . implode(",\n", $batch) . ";\n"); $batch = []; }
        }
        if ($batch) $w($insertHead . implode(",\n", $batch) . ";\n");
        $data->free();
        $w("\n");
    }

    foreach ($views as $view) {
        $qv = '`' . str_replace('`', '``', $view) . '`';
        try {
            $cv = $conn->query("SHOW CREATE VIEW $qv")->fetch_row();
            // strip DEFINER so the view restores on any MySQL account
            $sql = preg_replace('/\sDEFINER=`[^`]*`@`[^`]*`/', '', $cv[1]);
            $w("-- View: $view\nDROP VIEW IF EXISTS $qv;\n" . $sql . ";\n\n");
        } catch (\Throwable $e) {}
    }

    $w("SET FOREIGN_KEY_CHECKS = 1;\n-- End of backup\n");
    fclose($fh);
    if ($prevTz !== null) {
        try { $conn->query("SET time_zone = '" . $conn->real_escape_string($prevTz) . "'"); } catch (\Throwable $e) {}
    }
    return ['tables' => count($tables), 'rows' => $totalRows];
}

/* History rows + sync with what is really in the folder:
   - a file in the folder that has no history row is registered
     (source = 'folder'), so copies placed there by hand show up too;
   - a history row whose file is gone is shown as "File Missing". */
function ss_backup_list($conn) {
    $files = [];
    if (is_dir(SS_BACKUP_DIR)) {
        foreach (glob(SS_BACKUP_DIR . DIRECTORY_SEPARATOR . '*.sql') ?: [] as $f) {
            $n = ss_safe_file_name(basename($f));
            if ($n !== '') $files[$n] = $f;
        }
    }
    $known = [];
    try {
        $r = $conn->query("SELECT file_name FROM system_backups");
        while ($r && ($row = $r->fetch_assoc())) $known[$row['file_name']] = true;
    } catch (\Throwable $e) {}
    foreach ($files as $n => $f) {
        if (isset($known[$n])) continue;
        try {
            $st = $conn->prepare("INSERT IGNORE INTO system_backups (file_name, file_size, created_by, source, created_at) VALUES (?, ?, 'Unknown', 'folder', ?)");
            $size = (int)@filesize($f);
            $when = date('Y-m-d H:i:s', (int)@filemtime($f));
            $st->bind_param("sis", $n, $size, $when);
            $st->execute();
            $st->close();
        } catch (\Throwable $e) {}
    }

    $list = [];
    try {
        $r = $conn->query("SELECT * FROM system_backups ORDER BY created_at DESC, id DESC");
        while ($r && ($row = $r->fetch_assoc())) {
            $exists = isset($files[$row['file_name']]);
            $list[] = [
                'id'          => (int)$row['id'],
                'file_name'   => $row['file_name'],
                'file_size'   => $exists ? (int)@filesize($files[$row['file_name']]) : (int)$row['file_size'],
                'table_count' => (int)$row['table_count'],
                'row_count'   => (int)$row['row_count'],
                'created_by'  => $row['created_by'] ?: 'Unknown',
                'source'      => $row['source'],
                'created_at'  => $row['created_at'],
                'created_fmt' => $row['created_at'] ? date('M d, Y · h:i A', strtotime($row['created_at'] ?? '')) : '—',
                'exists'      => $exists,
            ];
        }
    } catch (\Throwable $e) {}
    return $list;
}

function ss_json_fail($msg) {
    echo json_encode(['success' => false, 'message' => $msg]);
    exit;
}

/* ── AJAX: create backup ── */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['create_backup'])) {
    header('Content-Type: application/json');
    if (!hash_equals($ssCsrf, (string)($_POST['csrf'] ?? ''))) ss_json_fail('Your session expired. Please reload the page and try again.');
    if (!ss_ensure_backup_dir()) ss_json_fail('The folder "' . SS_BACKUP_DIR_NAME . '" could not be created or is not writable.');

    $dbName = preg_replace('/[^A-Za-z0-9_-]/', '_', ss_db_name($conn)) ?: 'database';
    $fileName = 'backup_' . $dbName . '_' . date('Y-m-d_H-i-s') . '.sql';
    if (file_exists(SS_BACKUP_DIR . DIRECTORY_SEPARATOR . $fileName)) {
        $fileName = 'backup_' . $dbName . '_' . date('Y-m-d_H-i-s') . '_' . substr(bin2hex(random_bytes(3)), 0, 4) . '.sql';
    }
    $final = SS_BACKUP_DIR . DIRECTORY_SEPARATOR . $fileName;
    $tmp   = $final . '.part';
    try {
        $stats = ss_dump_database($conn, $tmp);
        if (!@rename($tmp, $final)) throw new \RuntimeException('Could not finalize the backup file.');
        $size = (int)filesize($final);
        $createdAt = date('Y-m-d H:i:s'); // same clock as the file name (Asia/Manila)
        $st = $conn->prepare("INSERT INTO system_backups (file_name, file_size, table_count, row_count, created_by, source, created_at) VALUES (?, ?, ?, ?, ?, 'manual', ?)");
        $st->bind_param("siiiss", $fileName, $size, $stats['tables'], $stats['rows'], $adminFullName, $createdAt);
        $st->execute();
        $st->close();
    } catch (\Throwable $e) {
        if (!empty($GLOBALS['ss_dump_fh']) && is_resource($GLOBALS['ss_dump_fh'])) @fclose($GLOBALS['ss_dump_fh']);
        if (is_file($tmp)) @unlink($tmp);
        ss_json_fail('Backup failed: ' . $e->getMessage());
    }
    echo json_encode([
        'success' => true,
        'message' => 'Backup created: ' . $fileName,
        'list'    => ss_backup_list($conn),
    ]);
    exit;
}

/* ── AJAX: delete backup ── */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_backup'])) {
    header('Content-Type: application/json');
    if (!hash_equals($ssCsrf, (string)($_POST['csrf'] ?? ''))) ss_json_fail('Your session expired. Please reload the page and try again.');
    $id = (int)($_POST['id'] ?? 0);
    $st = $conn->prepare("SELECT file_name FROM system_backups WHERE id = ?");
    $st->bind_param("i", $id);
    $st->execute();
    $row = $st->get_result()->fetch_assoc();
    $st->close();
    if (!$row) ss_json_fail('This backup could not be found (it may have already been deleted).');
    $name = ss_safe_file_name($row['file_name']);
    if ($name !== '') {
        $path = SS_BACKUP_DIR . DIRECTORY_SEPARATOR . $name;
        if (is_file($path) && !@unlink($path)) ss_json_fail('The backup file could not be deleted from the folder.');
    }
    $st = $conn->prepare("DELETE FROM system_backups WHERE id = ?");
    $st->bind_param("i", $id);
    $st->execute();
    $st->close();
    echo json_encode(['success' => true, 'message' => 'Backup deleted.', 'list' => ss_backup_list($conn)]);
    exit;
}

/* ── AJAX: refresh list ── */
if (isset($_GET['backup_list']) && $_GET['backup_list'] === '1') {
    header('Content-Type: application/json');
    echo json_encode(['list' => ss_backup_list($conn)]);
    exit;
}

/* ── Download (admin only — the folder itself is blocked from the web) ── */
if (isset($_GET['download'])) {
    $id = (int)$_GET['download'];
    $st = $conn->prepare("SELECT file_name FROM system_backups WHERE id = ?");
    $st->bind_param("i", $id);
    $st->execute();
    $row = $st->get_result()->fetch_assoc();
    $st->close();
    $name = $row ? ss_safe_file_name($row['file_name']) : '';
    $path = $name !== '' ? SS_BACKUP_DIR . DIRECTORY_SEPARATOR . $name : '';
    if ($path === '' || !is_file($path)) {
        http_response_code(404);
        echo 'Backup file not found.';
        exit;
    }
    while (ob_get_level()) ob_end_clean();
    header('Content-Type: application/sql');
    header('Content-Disposition: attachment; filename="' . $name . '"');
    header('Content-Length: ' . filesize($path));
    header('X-Content-Type-Options: nosniff');
    readfile($path);
    exit;
}

/* ── Page data ── */
ss_ensure_backup_dir();
$backupList        = ss_backup_list($conn);
$dbNameDisplay     = ss_db_name($conn);
$dbTableCount      = 0;
$dbSizeBytes       = 0;
try {
    $r = $conn->query("SELECT COUNT(*) AS n, COALESCE(SUM(data_length + index_length),0) AS sz FROM information_schema.tables WHERE table_schema = DATABASE() AND table_type = 'BASE TABLE'");
    if ($r) { $row = $r->fetch_assoc(); $dbTableCount = (int)$row['n']; $dbSizeBytes = (int)$row['sz']; }
} catch (\Throwable $e) {}
$folderWritable = is_dir(SS_BACKUP_DIR) && is_writable(SS_BACKUP_DIR);

$app_request_count  = ss_app_request_count($conn);
$moa_pending_count  = ss_company_notification_count($conn);
$all_ungraded_count = ss_ungraded_count($conn);

$pageTitle = "System Setting";
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
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

            /* Field Ops Grid palette — same as administrator.php */
            --grid-navy: #1B2A4A;
            --grid-slate: #C3CADA;
            --grid-bg: #E4EAF4;
            --grid-border: #A3AFC7;
            --grid-ink-2: #3E4963;
            --grid-ink-3: #66718D;
            --grid-ok: #2C5A2C;
            --grid-ok-bg: #D9E8D2;
            --grid-bad: #A02A2A;
            --grid-bad-bg: #F2D5D1;
            --grid-warn-bg: #F3E7B5;
            --grid-warn: #7A5A0B;
            --nav-gold: #FFD700;
        }

        body { font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif; background: var(--grid-bg); margin: 0; display: flex; color: var(--grid-navy); min-height: 100vh; }

        /* ── Sidebar (identical to administrator.php) ── */
        .sidebar { width: 260px; background: var(--neust-maroon); height: 100vh; position: fixed; display: flex; flex-direction: column; transition: all 0.3s ease; z-index: 1000; box-shadow: 4px 0 10px rgba(0,0,0,0.1); }
        .sidebar.collapsed { width: 80px; }
        .sidebar-header { padding: 20px; display: flex; align-items: center; justify-content: space-between; border-bottom: 1px solid rgba(255,255,255,0.1); }
        .sidebar-header-titles { overflow: hidden; transition: 0.3s; min-width: 0; }
        .sidebar-header h2 { color: var(--neust-gold); margin: 0; font-size: 18px; font-weight: bold; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; transition: 0.3s; }
        .sidebar-role-label { display: block; color: rgba(255,255,255,0.55); font-size: 11px; font-weight: 700; text-transform: uppercase; letter-spacing: 0.8px; margin-top: 3px; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; transition: 0.3s; }
        .sidebar.collapsed .sidebar-header-titles { opacity: 0; width: 0; }
        .sidebar-links { flex: 1; display: flex; flex-direction: column; padding: 10px 0; overflow-y: auto; }
        /* FIX (this adjustment): no scrollbar on the side menu. The link list above can scroll (overflow-y:auto),
           which made the browser show a scrollbar: a horizontal one when the menu is closed (the hidden link
           names still reach past the 80px rail) and a vertical one on shorter screens. Sideways overflow is now
           cut off and the scrollbar itself is hidden — the list can still be scrolled with the mouse wheel /
           touch on a short screen, so no link ever becomes unreachable. */
        .sidebar-links { overflow-x: hidden; scrollbar-width: none; -ms-overflow-style: none; }
        .sidebar-links::-webkit-scrollbar { display: none; width: 0; height: 0; }
        .sidebar a { position: relative; padding: 15px 25px; color: #cbd5e0; text-decoration: none; font-size: 14px; display: flex; align-items: center; transition: 0.2s; white-space: nowrap; }
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

        /* ── Sidebar indicators (same as administrator.php) ── */
        .sidebar-badge-ungraded, .sidebar-badge-app, .sidebar-badge-moa {
            color: white; border-radius: 50%;
            width: 18px; height: 18px; font-size: 10px; font-weight: 700;
            display: inline-flex; align-items: center; justify-content: center;
            position: absolute; right: 18px; top: 50%; transform: translateY(-50%);
        }
        .sidebar-badge-ungraded { background: #d97706; animation: badge-pulse-ungraded 2s ease-in-out infinite; }
        .sidebar-badge-app      { background: #dc2626; animation: badge-pulse-app-sidebar 2s ease-in-out infinite; }
        .sidebar-badge-moa      { background: #ef4444; animation: badge-pulse-moa-sidebar 2s ease-in-out infinite; }
        @keyframes badge-pulse-ungraded    { 0%,100% { box-shadow: 0 0 0 0 rgba(217,119,6,0.55); } 50% { box-shadow: 0 0 0 6px rgba(217,119,6,0); } }
        @keyframes badge-pulse-app-sidebar { 0%,100% { box-shadow: 0 0 0 0 rgba(220,38,38,0.55); } 50% { box-shadow: 0 0 0 6px rgba(220,38,38,0); } }
        @keyframes badge-pulse-moa-sidebar { 0%,100% { box-shadow: 0 0 0 0 rgba(239,68,68,0.55); } 50% { box-shadow: 0 0 0 6px rgba(239,68,68,0); } }

        /* ── Layout / navbar (same as administrator.php) ── */
        .main-content { margin-left: 260px; width: calc(100% - 260px); transition: 0.3s; min-height: 100vh; }
        .sidebar.collapsed + .main-content { margin-left: 80px; width: calc(100% - 80px); }
        .navbar { background: var(--neust-maroon); padding: 10px 30px; display: flex; justify-content: space-between; align-items: center; color: white; height: 60px; }
        .logo-section { display: flex; align-items: center; gap: 12px; }
        .university-logo { height: 40px; }
        .container { padding: 30px; max-width: 1200px; margin: 0 auto; }

        /* ── Page content (Field Ops Grid) ── */
        .page-title { border-bottom: 2px solid var(--grid-navy); padding-bottom: 8px; margin: 0 0 20px; display: flex; align-items: center; gap: 10px; }
        .page-title h1 { margin: 0; font-size: 20px; font-weight: 700; color: var(--grid-navy); }
        .page-title span { color: var(--grid-ink-3); font-size: 13px; }

        .ss-card { background: #ffffff; border: 1px solid var(--grid-border); box-shadow: 0 1px 3px rgba(27,42,74,0.16); margin-bottom: 26px; }
        .ss-card-head { display: flex; align-items: center; justify-content: space-between; gap: 14px; flex-wrap: wrap; padding: 16px 20px; border-top: 3px solid var(--grid-navy); border-bottom: 1px solid var(--grid-border); }
        .ss-card-head h2 { margin: 0; font-size: 13px; font-weight: 700; letter-spacing: 0.6px; text-transform: uppercase; color: var(--grid-navy); display: flex; align-items: center; gap: 8px; }
        .ss-card-head p { margin: 4px 0 0; font-size: 13px; color: var(--grid-ink-2); }
        .ss-card-body { padding: 18px 20px; }

        .ss-stats { display: grid; grid-template-columns: repeat(auto-fit, minmax(180px, 1fr)); border-top: 1px solid var(--grid-border); border-left: 1px solid var(--grid-border); }
        .ss-stat { padding: 12px 14px; border-right: 1px solid var(--grid-border); border-bottom: 1px solid var(--grid-border); min-width: 0; }
        .ss-stat-label { font-size: 11px; color: var(--grid-ink-3); font-weight: 600; }
        .ss-stat-value { font-size: 15px; font-weight: 700; color: var(--grid-navy); margin-top: 4px; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
        .ss-stat-value.ok  { color: var(--grid-ok); }
        .ss-stat-value.bad { color: var(--grid-bad); }

        .ss-btn { display: inline-flex; align-items: center; gap: 8px; border: none; cursor: pointer; font-family: inherit; font-size: 12px; font-weight: 600; letter-spacing: 0.4px; text-transform: uppercase; padding: 11px 18px; border-radius: 0; background: var(--grid-navy); color: #ffffff; transition: opacity 0.2s; }
        .ss-btn:hover:not(:disabled) { opacity: 0.88; }
        .ss-btn:disabled { opacity: 0.5; cursor: not-allowed; }
        .ss-btn.ghost { background: #ffffff; color: var(--grid-navy); border: 1px solid var(--grid-border); }
        .ss-btn.danger { background: var(--grid-bad); }

        .ss-toolbar { display: flex; gap: 10px; flex-wrap: wrap; align-items: center; margin-bottom: 14px; }
        .ss-search { flex: 1; min-width: 200px; padding: 10px; border: 1px solid var(--grid-border); border-radius: 0; font-size: 13px; color: var(--grid-navy); outline: none; font-family: inherit; }
        .ss-search:focus { border-color: var(--grid-navy); }

        .ss-table-wrap { overflow-x: auto; border: 1px solid var(--grid-border); }
        .ss-table { width: 100%; border-collapse: collapse; font-size: 13px; min-width: 820px; }
        .ss-table th { background: var(--grid-navy); color: #ffffff; font-size: 11px; font-weight: 600; letter-spacing: 0.6px; text-transform: uppercase; text-align: left; padding: 11px 12px; white-space: nowrap; }
        .ss-table td { padding: 11px 12px; border-top: 1px solid var(--grid-slate); color: var(--grid-ink-2); vertical-align: middle; }
        .ss-table tr:hover td { background: #F4F6FA; }
        .ss-table td.file { color: var(--grid-navy); font-weight: 600; word-break: break-all; }
        .ss-table td.num { font-variant-numeric: tabular-nums; white-space: nowrap; }
        .ss-tag { display: inline-block; font-size: 11px; font-weight: 700; padding: 3px 9px; white-space: nowrap; }
        .ss-tag.ok   { background: var(--grid-ok-bg); color: var(--grid-ok); }
        .ss-tag.bad  { background: var(--grid-bad-bg); color: var(--grid-bad); }
        .ss-tag.info { background: var(--grid-bg); color: var(--grid-ink-2); border: 1px solid var(--grid-border); }
        .ss-row-btn { background: none; border: none; cursor: pointer; font-family: inherit; font-size: 12px; font-weight: 600; padding: 5px 8px; color: var(--grid-navy); text-decoration: none; display: inline-flex; align-items: center; gap: 5px; }
        .ss-row-btn:hover { background: var(--grid-bg); }
        .ss-row-btn.delete { color: var(--grid-bad); }
        .ss-row-btn.delete:hover { background: #F7E9E9; }
        .ss-row-btn.disabled { color: var(--grid-ink-3); pointer-events: none; opacity: 0.6; }
        .ss-empty { text-align: center; padding: 34px 12px; color: var(--grid-ink-3); }
        .ss-empty i { font-size: 30px; display: block; margin-bottom: 8px; }

        .ss-pager { display: flex; justify-content: space-between; align-items: center; gap: 10px; flex-wrap: wrap; margin-top: 12px; font-size: 12px; color: var(--grid-ink-2); }
        .ss-pager-btns { display: flex; gap: 6px; }
        .ss-pager-btns button { background: #ffffff; border: 1px solid var(--grid-border); color: var(--grid-navy); padding: 6px 11px; cursor: pointer; font-family: inherit; font-size: 12px; font-weight: 600; }
        .ss-pager-btns button.active { background: var(--grid-navy); color: #ffffff; border-color: var(--grid-navy); }
        .ss-pager-btns button:disabled { opacity: 0.45; cursor: not-allowed; }

        .ss-note { font-size: 12px; color: var(--grid-ink-3); margin-top: 12px; line-height: 1.6; }
        .ss-note code { background: var(--grid-bg); padding: 1px 5px; color: var(--grid-navy); }

        /* ── Confirm modal ── */
        .ss-modal { display: none; position: fixed; inset: 0; background: rgba(27,42,74,0.45); z-index: 9000; align-items: center; justify-content: center; padding: 20px; }
        .ss-modal-box { background: #ffffff; width: 430px; max-width: 100%; border-top: 3px solid var(--grid-navy); box-shadow: 0 20px 60px rgba(0,0,0,0.25); padding: 26px 24px 22px; }
        .ss-modal-box h3 { margin: 0 0 10px; font-size: 16px; color: var(--grid-navy); display: flex; align-items: center; gap: 8px; }
        .ss-modal-box p { margin: 0 0 22px; font-size: 13.5px; color: var(--grid-ink-2); line-height: 1.6; word-break: break-word; }
        .ss-modal-actions { display: flex; justify-content: flex-end; gap: 8px; }

        /* ── Toast ── */
        .ss-toast { position: fixed; top: 22px; left: 50%; transform: translateX(-50%) translateY(-20px); background: var(--grid-navy); color: #ffffff; border: 1px solid var(--grid-border); padding: 12px 18px; font-size: 13px; display: flex; align-items: center; gap: 10px; z-index: 12000; opacity: 0; pointer-events: none; transition: opacity 0.3s, transform 0.3s; max-width: 90vw; }
        .ss-toast.show { opacity: 1; transform: translateX(-50%) translateY(0); }
        .ss-toast i { color: #8FD18F; font-size: 16px; }
        .ss-toast.error i { color: #F2A29A; }

        /* ── Page load / processing overlay (same as administrator.php) ── */
        #globalLoadingOverlay { position: fixed; inset: 0; z-index: 20000; display: flex; align-items: center; justify-content: center; background: rgba(238, 241, 246, 0.92); opacity: 1; visibility: visible; transition: opacity 0.35s ease, visibility 0.35s ease; }
        #globalLoadingOverlay.hidden { opacity: 0; visibility: hidden; pointer-events: none; }
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

        @media (max-width: 768px) {
            .container { padding: 18px; }
            .ss-card-head .ss-btn { width: 100%; justify-content: center; }
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
            <span class="sidebar-badge-app" id="sidebarAppBadge"<?= $app_request_count > 0 ? '' : ' style="display:none"' ?>><?= $app_request_count ?></span>
        </a>
        <a href="company_validation.php" style="position:relative;">
            <i class="fas fa-building"></i>
            <span class="link-text">Company Requirements</span>
            <span class="sidebar-badge-moa" id="sidebarMoaBadge"<?= $moa_pending_count > 0 ? '' : ' style="display:none"' ?>><?= $moa_pending_count ?></span>
        </a>
        <a href="monitoring.php" style="position:relative;"><i class="fas fa-users-cog"></i><span class="link-text">Manage Accounts</span><!-- NEW (this adjustment): Email Recovery Requests indicator — same badge look as the application-request badge --><span class="sidebar-badge-app sidebar-badge-recovery" id="sidebarRecoveryBadge"<?= $recovery_pending_count > 0 ? '' : ' style="display:none"' ?>><?= (int)$recovery_pending_count ?></span></a>
        <a href="admin_monitoring_dashboard.php">
            <i class="fas fa-chart-line"></i>
            <span class="link-text">Monitoring Dashboard</span>
        </a>
        <a href="admin_final_grades.php"><i class="fas fa-graduation-cap"></i><span class="link-text">Final Grades</span></a>
        <a href="system_setting.php" class="active"><i class="fas fa-gear"></i><span class="link-text">System Setting</span></a>
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
    </nav>

    <div class="container">
        <div class="page-title">
            <h1>System Setting</h1>
            <span>Database backup and history</span>
        </div>

        <!-- ── DATABASE BACKUP ── -->
        <div class="ss-card">
            <div class="ss-card-head">
                <div>
                    <h2><i class="fas fa-database"></i> Database Backup</h2>
                    <p>Creates a full copy of the database (all tables and records) and saves it in the “<?= htmlspecialchars(SS_BACKUP_DIR_NAME) ?>” folder.</p>
                </div>
                <button class="ss-btn" id="backupBtn" <?= $folderWritable ? '' : 'disabled title="The backup folder is not writable"' ?>>
                    <i class="fas fa-cloud-arrow-down"></i> Backup Now
                </button>
            </div>
            <div class="ss-card-body">
                <div class="ss-stats">
                    <div class="ss-stat">
                        <div class="ss-stat-label">Database</div>
                        <div class="ss-stat-value" title="<?= htmlspecialchars($dbNameDisplay ?? '') ?>"><?= htmlspecialchars($dbNameDisplay ?: '—') ?></div>
                    </div>
                    <div class="ss-stat">
                        <div class="ss-stat-label">Tables / Size</div>
                        <div class="ss-stat-value"><?= $dbTableCount ?> · <span id="dbSize" data-bytes="<?= $dbSizeBytes ?>"></span></div>
                    </div>
                    <div class="ss-stat">
                        <div class="ss-stat-label">Last Backup</div>
                        <div class="ss-stat-value" id="lastBackupVal">—</div>
                    </div>
                    <div class="ss-stat">
                        <div class="ss-stat-label">Backup Folder</div>
                        <div class="ss-stat-value <?= $folderWritable ? 'ok' : 'bad' ?>">
                            <?= $folderWritable ? '<i class="fas fa-circle-check"></i> Ready' : '<i class="fas fa-circle-xmark"></i> Not writable' ?>
                        </div>
                    </div>
                </div>
                <div class="ss-note">
                    Saved to <code><?= htmlspecialchars(SS_BACKUP_DIR_NAME) ?>/</code> as a <code>.sql</code> file.
                    The folder is protected from direct web access; use the Download button below.
                    To restore, import the file with phpMyAdmin (Import tab) or the <code>mysql</code> command.
                    <?php if (!$folderWritable): ?>
                        <br><strong style="color:var(--grid-bad);">The folder “<?= htmlspecialchars(SS_BACKUP_DIR_NAME) ?>” could not be created or is not writable. Create it next to system_setting.php and give the web server write permission.</strong>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <!-- ── BACKUP HISTORY ── -->
        <div class="ss-card">
            <div class="ss-card-head">
                <div>
                    <h2><i class="fas fa-clock-rotate-left"></i> Backup History</h2>
                    <p><span id="historyCount">0</span> backup(s) recorded</p>
                </div>
            </div>
            <div class="ss-card-body">
                <div class="ss-toolbar">
                    <input type="text" id="historySearch" class="ss-search" placeholder="Search by file name or created by...">
                    <button class="ss-btn ghost" id="refreshBtn"><i class="fas fa-rotate"></i> Refresh</button>
                </div>
                <div class="ss-table-wrap">
                    <table class="ss-table">
                        <thead>
                            <tr>
                                <th>#</th>
                                <th>File Name</th>
                                <th>Size</th>
                                <th>Tables</th>
                                <th>Rows</th>
                                <th>Created By</th>
                                <th>Date &amp; Time</th>
                                <th>Status</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody id="historyBody"></tbody>
                    </table>
                </div>
                <div class="ss-pager">
                    <span id="pagerInfo"></span>
                    <div class="ss-pager-btns" id="pagerBtns"></div>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Confirm modal (backup / delete) -->
<div class="ss-modal" id="confirmModal">
    <div class="ss-modal-box">
        <h3 id="confirmTitle"></h3>
        <p id="confirmMsg"></p>
        <div class="ss-modal-actions">
            <button class="ss-btn ghost" id="confirmCancel">Cancel</button>
            <button class="ss-btn" id="confirmOk">Confirm</button>
        </div>
    </div>
</div>

<div class="ss-toast" id="ssToast"><i class="fas fa-circle-check"></i><span id="ssToastMsg"></span></div>

<script>
    /* ══════════════════════════════════════════════════════════
       Page-load / processing overlay — same behaviour as
       administrator.php (shows on reload/navigation, first-load
       token, minimum visible time, bfcache re-sync).
       ══════════════════════════════════════════════════════════ */
    var globalLoadingActiveCount = 1;
    var globalLoadingOverlay = document.getElementById('globalLoadingOverlay');
    var globalLoadingLabel   = document.getElementById('globalLoadingLabel');
    var GLOBAL_LOADING_MIN_MS = 350, GLOBAL_LOADING_SAFETY_MS = 4000, GLOBAL_LOADING_NAV_STUCK_MS = 15000;
    var globalLoadingStartedAt = (window.performance && performance.now) ? performance.now() : 0;
    var globalLoadingInitialDone = false, globalLoadingNavigating = false, globalLoadingNavTimer = null;

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
    function startNavigationGlobalLoading(label) {
        globalLoadingNavigating = true;
        if (globalLoadingLabel) globalLoadingLabel.textContent = label || 'Loading';
        if (globalLoadingOverlay) globalLoadingOverlay.classList.add('gl-instant');
        globalLoadingPaint();
        clearTimeout(globalLoadingNavTimer);
        globalLoadingNavTimer = setTimeout(stopNavigationGlobalLoading, GLOBAL_LOADING_NAV_STUCK_MS);
    }
    function stopNavigationGlobalLoading() {
        clearTimeout(globalLoadingNavTimer);
        globalLoadingNavigating = false;
        if (globalLoadingLabel && globalLoadingActiveCount === 0) globalLoadingLabel.textContent = 'Loading';
        globalLoadingPaint();
    }
    if (document.readyState === 'complete') finishInitialGlobalLoading();
    else window.addEventListener('load', finishInitialGlobalLoading);
    setTimeout(finishInitialGlobalLoading, GLOBAL_LOADING_SAFETY_MS);

    window.addEventListener('beforeunload', function () { startNavigationGlobalLoading('Loading'); });
    document.addEventListener('click', function (e) {
        if (e.defaultPrevented || e.button !== 0 || e.metaKey || e.ctrlKey || e.shiftKey || e.altKey) return;
        var a = e.target && e.target.closest ? e.target.closest('a[href]') : null;
        if (!a) return;
        var href = a.getAttribute('href') || '';
        if (!href || href.charAt(0) === '#' || /^(javascript|mailto|tel|blob|data):/i.test(href)) return;
        if (a.hasAttribute('download') || a.hasAttribute('data-no-loader')) return;
        if (a.target && a.target.toLowerCase() !== '_self') return;
        if (a.origin && a.origin !== window.location.origin) return;
        startNavigationGlobalLoading('Loading');
    });
    window.addEventListener('pageshow', function (e) {
        if (e.persisted) {
            globalLoadingInitialDone = true;
            globalLoadingActiveCount = 0;
            stopNavigationGlobalLoading();
        }
    });

    // ── Sidebar toggle ──
    document.getElementById('toggleBtn').addEventListener('click', function () {
        document.getElementById('sidebar').classList.toggle('collapsed');
    });

    // ── Sidebar indicators (live, same counts as administrator.php) ──
    function setSidebarBadge(id, count) {
        var b = document.getElementById(id);
        if (!b) return;
        b.textContent = count;
        b.style.display = count > 0 ? 'inline-flex' : 'none';
    }
    function pollSidebarCounts() {
        fetch(window.location.pathname + '?sidebar_counts=1', { cache: 'no-store' })
            .then(function (r) { return r.json(); })
            .then(function (d) {
                setSidebarBadge('sidebarAppBadge', d.app || 0);
                setSidebarBadge('sidebarMoaBadge', d.moa || 0);
            })
            .catch(function () {});
    }
    setTimeout(function () { pollSidebarCounts(); setInterval(pollSidebarCounts, 10000); }, 4000);

    // ── Helpers ──
    var CSRF = <?= json_encode($ssCsrf) ?>;
    function escHtml(s) {
        return String(s == null ? '' : s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
    }
    function fmtBytes(n) {
        n = Number(n) || 0;
        if (n < 1024) return n + ' B';
        var u = ['KB','MB','GB','TB'], i = -1;
        do { n /= 1024; i++; } while (n >= 1024 && i < u.length - 1);
        return n.toFixed(n < 10 ? 2 : 1) + ' ' + u[i];
    }
    document.getElementById('dbSize').textContent = fmtBytes(document.getElementById('dbSize').dataset.bytes);

    var toastTimer = null;
    function showToast(msg, isError) {
        var t = document.getElementById('ssToast');
        t.classList.toggle('error', !!isError);
        t.querySelector('i').className = isError ? 'fas fa-circle-exclamation' : 'fas fa-circle-check';
        document.getElementById('ssToastMsg').textContent = msg;
        t.classList.add('show');
        clearTimeout(toastTimer);
        toastTimer = setTimeout(function () { t.classList.remove('show'); }, 4500);
    }

    var confirmAction = null;
    function openConfirm(title, msgHtml, okLabel, danger, action) {
        document.getElementById('confirmTitle').innerHTML = title;
        document.getElementById('confirmMsg').innerHTML = msgHtml;
        var ok = document.getElementById('confirmOk');
        ok.textContent = okLabel;
        ok.classList.toggle('danger', !!danger);
        confirmAction = action;
        document.getElementById('confirmModal').style.display = 'flex';
    }
    function closeConfirm() { document.getElementById('confirmModal').style.display = 'none'; confirmAction = null; }
    document.getElementById('confirmCancel').addEventListener('click', closeConfirm);
    document.getElementById('confirmModal').addEventListener('click', function (e) { if (e.target === this) closeConfirm(); });
    document.getElementById('confirmOk').addEventListener('click', function () {
        var fn = confirmAction; closeConfirm(); if (fn) fn();
    });

    // ── Backup history ──
    var backupList = <?= json_encode($backupList) ?>;
    var PER_PAGE = 10, currentPage = 1;

    function filteredList() {
        var q = document.getElementById('historySearch').value.trim().toLowerCase();
        if (!q) return backupList;
        return backupList.filter(function (b) {
            return b.file_name.toLowerCase().indexOf(q) !== -1 || String(b.created_by).toLowerCase().indexOf(q) !== -1;
        });
    }

    function renderHistory() {
        var list = filteredList();
        var pages = Math.max(1, Math.ceil(list.length / PER_PAGE));
        if (currentPage > pages) currentPage = pages;
        var start = (currentPage - 1) * PER_PAGE;
        var slice = list.slice(start, start + PER_PAGE);
        var body = document.getElementById('historyBody');

        if (!slice.length) {
            body.innerHTML = '<tr><td colspan="9" class="ss-empty"><i class="fas fa-box-open"></i>' +
                (backupList.length ? 'No backups match your search.' : 'No backups yet. Click “Backup Now” to create the first one.') + '</td></tr>';
        } else {
            body.innerHTML = slice.map(function (b, i) {
                var status = b.exists
                    ? '<span class="ss-tag ok">Saved</span>'
                    : '<span class="ss-tag bad">File Missing</span>';
                if (b.source === 'folder') status += ' <span class="ss-tag info" title="Found in the folder, not created from this page">Imported</span>';
                var dl = b.exists
                    ? '<a class="ss-row-btn" data-no-loader href="?download=' + b.id + '"><i class="fas fa-download"></i> Download</a>'
                    : '<span class="ss-row-btn disabled"><i class="fas fa-download"></i> Download</span>';
                return '<tr>' +
                    '<td class="num">' + (start + i + 1) + '</td>' +
                    '<td class="file">' + escHtml(b.file_name) + '</td>' +
                    '<td class="num">' + fmtBytes(b.file_size) + '</td>' +
                    '<td class="num">' + (b.source === 'folder' && !b.table_count ? '—' : b.table_count) + '</td>' +
                    '<td class="num">' + (b.source === 'folder' && !b.row_count ? '—' : Number(b.row_count).toLocaleString()) + '</td>' +
                    '<td>' + escHtml(b.created_by) + '</td>' +
                    '<td class="num">' + escHtml(b.created_fmt) + '</td>' +
                    '<td>' + status + '</td>' +
                    '<td style="white-space:nowrap;">' + dl +
                        '<button class="ss-row-btn delete" data-id="' + b.id + '" data-name="' + escHtml(b.file_name) + '"><i class="fas fa-trash-alt"></i> Delete</button>' +
                    '</td>' +
                '</tr>';
            }).join('');
        }

        document.getElementById('historyCount').textContent = backupList.length;
        document.getElementById('pagerInfo').textContent = list.length
            ? 'Showing ' + (start + 1) + '–' + Math.min(start + PER_PAGE, list.length) + ' of ' + list.length
            : '';
        var btns = '';
        if (pages > 1) {
            btns += '<button data-page="' + (currentPage - 1) + '"' + (currentPage === 1 ? ' disabled' : '') + '>&lsaquo;</button>';
            for (var p = 1; p <= pages; p++) btns += '<button data-page="' + p + '" class="' + (p === currentPage ? 'active' : '') + '">' + p + '</button>';
            btns += '<button data-page="' + (currentPage + 1) + '"' + (currentPage === pages ? ' disabled' : '') + '>&rsaquo;</button>';
        }
        document.getElementById('pagerBtns').innerHTML = btns;

        var latest = backupList.filter(function (b) { return b.exists; })[0];
        document.getElementById('lastBackupVal').textContent = latest ? latest.created_fmt : 'Never';
    }

    document.getElementById('pagerBtns').addEventListener('click', function (e) {
        var b = e.target.closest('button[data-page]');
        if (!b || b.disabled) return;
        currentPage = parseInt(b.dataset.page, 10);
        renderHistory();
    });
    document.getElementById('historySearch').addEventListener('input', function () { currentPage = 1; renderHistory(); });

    function postAction(fields, loadingLabel) {
        var fd = new FormData();
        fd.append('csrf', CSRF);
        Object.keys(fields).forEach(function (k) { fd.append(k, fields[k]); });
        showGlobalLoading(loadingLabel);
        return fetch(window.location.pathname, { method: 'POST', body: fd })
            .then(function (r) { return r.text(); })
            .then(function (raw) {
                try { return JSON.parse(raw); }
                catch (e) { return { success: false, message: 'Unexpected server response: ' + raw.substring(0, 300) }; }
            })
            .catch(function (err) { return { success: false, message: 'Network error: ' + err.message }; })
            .finally(hideGlobalLoading);
    }

    // Backup Now
    document.getElementById('backupBtn').addEventListener('click', function () {
        openConfirm('<i class="fas fa-database"></i> Create Backup',
            'A full copy of the database will be saved in the <strong><?= htmlspecialchars(SS_BACKUP_DIR_NAME) ?></strong> folder. This may take a moment for a large database.',
            'Backup Now', false, function () {
                var btn = document.getElementById('backupBtn');
                btn.disabled = true;
                postAction({ create_backup: '1' }, 'Creating backup').then(function (d) {
                    btn.disabled = false;
                    if (d.success) {
                        backupList = d.list || backupList;
                        currentPage = 1;
                        renderHistory();
                        showToast(d.message);
                    } else {
                        showToast(d.message || 'Backup failed.', true);
                    }
                });
            });
    });

    // Delete
    document.getElementById('historyBody').addEventListener('click', function (e) {
        var btn = e.target.closest('.ss-row-btn.delete');
        if (!btn) return;
        var id = btn.dataset.id;
        openConfirm('<i class="fas fa-trash-alt"></i> Delete Backup',
            'Delete <strong>' + escHtml(btn.dataset.name) + '</strong>? The file will be removed from the folder and from the history. This cannot be undone.',
            'Yes, Delete', true, function () {
                postAction({ delete_backup: '1', id: id }, 'Deleting').then(function (d) {
                    if (d.success) { backupList = d.list || backupList; renderHistory(); showToast(d.message); }
                    else showToast(d.message || 'Delete failed.', true);
                });
            });
    });

    // Refresh (re-syncs with the folder)
    document.getElementById('refreshBtn').addEventListener('click', function () {
        showGlobalLoading('Refreshing');
        fetch(window.location.pathname + '?backup_list=1', { cache: 'no-store' })
            .then(function (r) { return r.json(); })
            .then(function (d) { backupList = d.list || []; renderHistory(); })
            .catch(function () { showToast('Could not refresh the history.', true); })
            .finally(hideGlobalLoading);
    });

    renderHistory();
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
        if (r.kind === 'placement') {   // NEW (this adjustment): preferred placement replaced → the new Application SIT needs validation
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