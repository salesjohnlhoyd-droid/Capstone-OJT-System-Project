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
include "mail.php"; // Assuming this contains your email functions

/* ════════════════════════════════════════════════════════════════════
   NEW (Student account auto-creation — adopted from
   admin_company_list.php): PHPMailer includes, needed to email each
   newly-created student account its login password. Guarded so they
   never clash with anything mail.php may already have loaded. Classes
   are referenced by their FULLY QUALIFIED names further below (no `use`
   import), so the existing `catch (Exception $e)` in the XLSX import
   handler still catches plain global \Exception exactly as before.
   ════════════════════════════════════════════════════════════════════ */
if (!class_exists('\\PHPMailer\\PHPMailer\\PHPMailer', false) && file_exists(__DIR__ . '/PHPMailer/src/PHPMailer.php')) {
    require_once __DIR__ . '/PHPMailer/src/PHPMailer.php';
}
if (!class_exists('\\PHPMailer\\PHPMailer\\SMTP', false) && file_exists(__DIR__ . '/PHPMailer/src/SMTP.php')) {
    require_once __DIR__ . '/PHPMailer/src/SMTP.php';
}
if (!class_exists('\\PHPMailer\\PHPMailer\\Exception', false) && file_exists(__DIR__ . '/PHPMailer/src/Exception.php')) {
    require_once __DIR__ . '/PHPMailer/src/Exception.php';
}

// Increase PHP limits for large file uploads
ini_set('upload_max_filesize', '100M');
ini_set('post_max_size', '100M');
ini_set('memory_limit', '256M');
ini_set('max_execution_time', 300);
ini_set('max_input_time', 300);

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
    [function () { return cv_alog_is_post('add_student_manual'); }, function ($conn) {
        $n = cv_alog_join_name(cv_alog_post('first_name'), cv_alog_post('middle_name'), cv_alog_post('last_name'));
        $e = cv_alog_post('email');
        return ['Student Added', 'Student', $n, "Added student $n" . ($e !== '' ? " ($e)" : '') . " via Student List"];
    }],
    [function () { return cv_alog_is_post('edit_student'); }, function ($conn) {
        $n = cv_alog_join_name(cv_alog_post('edit_first_name'), cv_alog_post('edit_middle_name'), cv_alog_post('edit_last_name'));
        if ($n === '' && preg_match('/^u?(\d+)$/', cv_alog_post('edit_student_id'), $m)) $n = cv_alog_user_name($conn, $m[1]);
        return ['Student Updated', 'Student', $n, "Updated the details of $n via Student List"];
    }],
    [function () { return cv_alog_is_post('create_student_accounts_selected'); }, function ($conn) {
        $ids = isset($_POST['import_ids']) ? (array)$_POST['import_ids'] : [];
        $c = count($ids);
        return ['Student Accounts Created', 'Student', cv_alog_plural($c, 'student', 'students'), "Created student accounts for " . cv_alog_plural($c, 'selected student', 'selected students') . " via Student List"];
    }, function ($rec, $json) { if (is_array($json) && !empty($json['message'])) $rec[3] .= ' — ' . trim(strip_tags(str_replace('<br>', ' ', (string)$json['message']))); return $rec; }],
    [function () { return ($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && isset($_FILES['import_file']) && !isset($_POST['check_import_courses']) && !isset($_POST['detect_student_import_classifications']); }, function ($conn) {
        $f = (string)($_FILES['import_file']['name'] ?? 'spreadsheet');
        return ['Students Imported', 'Student', $f, "Imported students from $f via Student List"];
    }, function ($rec, $json) { $m = trim(strip_tags(str_replace('<br>', ' ', (string)($GLOBALS['import_message'] ?? '')))); if ($m !== '') $rec[3] .= ' — ' . $m; return $rec; }],
]);

/* ════════════════════════════════════════════════════════════════════
   NEW (Campus Branch fix): `student_information` may not have ANY of the
   columns this page reads/writes campus branch from (campus_branch /
   campus / branch) — that's why an imported or manually-added student's
   Campus Branch was showing up empty: the value was there in the form
   and the import file, but student_list_insert_row() (below) only ever
   writes columns that really exist, so with none of those three columns
   present the value had nowhere to go and was silently dropped.
   Called once, right here, before anything else on the page touches
   student_information — including before student_list_table_columns()
   ever caches that table's columns — so the fix applies uniformly to
   the student list/export query, the XLSX importer, the manual
   "Add Student" form and the "Edit Student" form in this same request.
   If the table already has campus_branch, campus, OR branch, this does
   nothing and no existing column is touched. ════════════════════════════════════════════════════════════════════ */
student_list_ensure_campus_branch_column($conn);

// ── UPDATED (accurate hours): format rendered time as "X hrs Y mins" ──────────
// Uses exact seconds (not a rounded decimal) so e.g. 119h 57m is never shown as
// "120.0 hrs". Falls back to the decimal hours value when seconds are missing.
if (!function_exists('student_list_format_hours')) {
    function student_list_format_hours($seconds, $fallback_hours = 0): string
    {
        $seconds = ($seconds !== null && $seconds !== '')
            ? (int)round((float)$seconds)
            : (int)round((float)$fallback_hours * 3600);
        if ($seconds <= 0) return '0 hrs';
        $total_min = intdiv($seconds, 60);
        $h = intdiv($total_min, 60);
        $m = $total_min % 60;
        if ($h === 0) return $m . ' mins';
        return $h . ' hrs' . ($m > 0 ? ' ' . $m . ' mins' : '');
    }
}

/* ════════════════════════════════════════════════════════════════════
   UPDATED (OJT Date End <-> Est. Duty Days): "OJT Date End" used to be the
   date of the student's LAST attendance log, so it never lined up with the
   "Est. Duty Days" shown on course_offering.php. It is now computed from the
   student's course rules in Course Offering, using the SAME formula as that
   page:  Est. Duty Days = ceil(Total Hour Requirement / Required Hours per Day),
   counted on duty days only (Mon–Fri; Sat/Sun are day-off, same as the
   student dashboard).
     - Completed students  → the actual date the required hours were reached.
     - In-progress students → today (or the next duty day) + the duty days
       still needed for the remaining hours.
     - No matching Course Offering / no attendance yet → previous behaviour
       (date of the last attendance log).
   A student who renders exactly the required hours every duty day ends
   exactly on Date Start + Est. Duty Days − 1 duty days.
   ════════════════════════════════════════════════════════════════════ */
if (!function_exists('student_list_est_duty_days')) {
    // Same formula as course_offering.php (float-safe ceil)
    function student_list_est_duty_days($totalHours, $dailyHours): int
    {
        $total = (float)$totalHours;
        $daily = (float)$dailyHours;
        if ($total <= 0 || $daily <= 0) return 0;
        return (int)ceil(round($total / $daily, 6));
    }
}
if (!function_exists('student_list_is_duty_day')) {
    function student_list_is_duty_day(DateTime $d): bool
    {
        $dow = (int)$d->format('w');
        return $dow !== 0 && $dow !== 6; // Sat/Sun = day off
    }
}
if (!function_exists('student_list_add_duty_days')) {
    // Returns the date of the $n-th duty day, counting $fromYmd (or the next duty day) as day 1
    function student_list_add_duty_days(string $fromYmd, int $n): string
    {
        $d = new DateTime($fromYmd);
        while (!student_list_is_duty_day($d)) $d->modify('+1 day');
        for ($i = 1; $i < max(1, $n); $i++) {
            $d->modify('+1 day');
            while (!student_list_is_duty_day($d)) $d->modify('+1 day');
        }
        return $d->format('Y-m-d');
    }
}
if (!function_exists('student_list_course_rules')) {
    // [normalized course => ['total' => int, 'daily' => float]] from Course Offering
    function student_list_course_rules($conn): array
    {
        $rules = [];
        try {
            $res = $conn->query("SELECT course, total_hours, daily_hours FROM course_offerings");
            if ($res) {
                while ($r = $res->fetch_assoc()) {
                    $key = mb_strtolower(preg_replace('/\s+/', ' ', trim((string)$r['course'])));
                    $rules[$key] = ['total' => (int)$r['total_hours'], 'daily' => (float)$r['daily_hours']];
                }
            }
        } catch (\Throwable $e) {}
        return $rules;
    }
}
if (!function_exists('student_list_session_seconds_sql')) {
    // Same per-session seconds rule used by the Total Hours column
    function student_list_session_seconds_sql(string $p): string
    {
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
if (!function_exists('student_list_completion_date')) {
    // Date on which the student's running total first reached $requiredSeconds
    function student_list_completion_date($conn, $userId, int $requiredSeconds): ?string
    {
        if (empty($userId) || $requiredSeconds <= 0) return null;
        try {
            $sql = "SELECT date, SUM(" . student_list_session_seconds_sql('am') . " + " . student_list_session_seconds_sql('pm') . ") AS secs
                    FROM attendance_logs WHERE user_id = ? GROUP BY date ORDER BY date ASC";
            $st = $conn->prepare($sql);
            if (!$st) return null;
            $uid = (int)$userId;
            $st->bind_param('i', $uid);
            $st->execute();
            $res = $st->get_result();
            $running = 0;
            while ($r = $res->fetch_assoc()) {
                $running += (int)round((float)$r['secs']);
                if ($running >= $requiredSeconds) { $st->close(); return $r['date']; }
            }
            $st->close();
        } catch (\Throwable $e) {}
        return null;
    }
}
if (!function_exists('student_list_ojt_end_info')) {
    /* Returns ['date' => Y-m-d|null, 'type' => 'completed'|'estimated'|'last_log',
                'est_days' => int, 'rule' => array|null] */
    function student_list_ojt_end_info($conn, array $rules, array $r): array
    {
        $lastLog = !empty($r['ojt_date_end']) ? $r['ojt_date_end'] : null;
        $start   = !empty($r['ojt_date_start']) ? $r['ojt_date_start'] : null;
        $key     = mb_strtolower(preg_replace('/\s+/', ' ', trim((string)($r['course'] ?? ''))));
        $rule    = $rules[$key] ?? null;
        $info    = ['date' => $lastLog, 'type' => 'last_log', 'est_days' => 0, 'rule' => $rule];
        if (!$rule || !$start || $rule['total'] <= 0 || $rule['daily'] <= 0) return $info;

        $info['est_days'] = student_list_est_duty_days($rule['total'], $rule['daily']);
        $required = (int)round($rule['total'] * 3600);
        $rendered = isset($r['ojt_total_seconds']) && $r['ojt_total_seconds'] !== null
            ? (int)round((float)$r['ojt_total_seconds'])
            : (int)round((float)($r['ojt_total_hours'] ?? 0) * 3600);

        if ($rendered >= $required) {
            $done = student_list_completion_date($conn, $r['ojt_user_id'] ?? ($r['user_id'] ?? null), $required);
            $info['date'] = $done ?: $lastLog;
            $info['type'] = 'completed';
            return $info;
        }

        $remainingDays = student_list_est_duty_days(($required - $rendered) / 3600, $rule['daily']);
        $today = (new DateTime('now', new DateTimeZone('Asia/Manila')))->format('Y-m-d');
        $base  = new DateTime($today);
        if ($lastLog !== null && $lastLog >= $today) $base->modify('+1 day'); // today already logged
        $info['date'] = student_list_add_duty_days($base->format('Y-m-d'), $remainingDays);
        $info['type'] = 'estimated';
        return $info;
    }
}

// ── XLSX EXPORT ──────────────────────────────────────────────────────────────
if ((isset($_GET['export']) && $_GET['export'] === 'xlsx') || ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['export']) && $_POST['export'] === 'xlsx')) {
    /* NEW (Export result screen — adopted from admin_company_list.php):
       the Export button now downloads the file via fetch() so the page
       can tell whether the export succeeded or failed and show the
       matching full-page result screen (instead of the old "Exporting"
       popup notification). For those AJAX requests only, any exception
       or fatal error during the export is reported back as a small JSON
       error instead of a raw PHP error page. A plain (non-AJAX)
       ?export=xlsx request behaves exactly as before. The export query,
       columns and styling below are unchanged. */
    $studentExportIsAjax = isset($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest';
    $studentExportSendJsonError = function ($msg) {
        while (ob_get_level() > 0) { ob_end_clean(); }
        if (!headers_sent()) {
            http_response_code(500);
            header('Content-Type: application/json');
            header_remove('Content-Disposition');
        }
        echo json_encode(['success' => false, 'message' => $msg]);
    };
    if ($studentExportIsAjax) {
        ini_set('display_errors', '0');
        ob_start();
        set_exception_handler(function ($e) use ($studentExportSendJsonError) {
            $studentExportSendJsonError('The Excel file could not be generated: ' . $e->getMessage());
            exit;
        });
        register_shutdown_function(function () use ($studentExportSendJsonError) {
            $err = error_get_last();
            if ($err && in_array($err['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR], true)) {
                $studentExportSendJsonError('The Excel file could not be generated because of a server error. Please try again.');
            }
        });
    }

    /* ════════════════════════════════════════════════════════════════
       NEW (Export exclusion — adopted from admin_company_list.php): the
       "Export to Excel" button now asks first whether any students should
       be left OUT of the file (see exportChoiceModal /
       enterExportExcludeSelectionMode() in the script below).
       "None, Proceed With Export" sends the same plain GET ?export=xlsx
       request as before, so nothing is excluded. Choosing students
       instead submits a POST carrying exclude_student_ids[] (the checked
       rows' keys, 'u<users.id>'); only those students are filtered out —
       the column layout, styling and "ignore current filters" behavior
       are unchanged.
       ════════════════════════════════════════════════════════════════ */
    [$exportExcludeUserIds] = student_list_parse_row_keys(
        (isset($_POST['exclude_student_ids']) && is_array($_POST['exclude_student_ids'])) ? $_POST['exclude_student_ids'] : []
    );
    $exportExcludeUserIds = array_values(array_filter(array_map('intval', $exportExcludeUserIds), function ($v) { return $v > 0; }));
    $exportExcludeIn = implode(',', $exportExcludeUserIds); // ints only — safe to inline

    // Cancel the export when the table is empty — nothing is generated or
    // downloaded; the page reloads and shows an "Export Cancelled" notification.
    // UPDATED (Export exclusion): also cancelled when every student was excluded.
    $export_check_res   = $conn->query("SELECT COUNT(*) AS total FROM (" . student_list_source_sql($conn) . ") si_count"
        . ($exportExcludeIn !== '' ? " WHERE si_count.user_id NOT IN ($exportExcludeIn)" : "")); // UPDATED (cross-table list)
    $export_check_total = $export_check_res ? (int)$export_check_res->fetch_assoc()['total'] : 0;
    if ($export_check_total === 0) {
        // UPDATED (Export result screen): AJAX exports get a JSON error so
        // the page shows the "Export Failed" result screen instead.
        if ($studentExportIsAjax) {
            $studentExportSendJsonError('Exporting cancelled because the table is empty.');
            exit;
        }
        header("Location: " . strtok($_SERVER['REQUEST_URI'], '?') . "?export_empty=1");
        exit;
    }

    require 'vendor/autoload.php';

    $export_sql = "
        SELECT
            si.first_name,
            si.middle_name,
            si.last_name,
            si.course,
            si.major,
            si.section,
            si.email,
            si.campus_branch,
            ci.company        AS ojt_company,
            att.date_start    AS ojt_date_start,
            att.date_end      AS ojt_date_end,
            att.total_hours   AS ojt_total_hours,
            att.total_seconds AS ojt_total_seconds,
            u.id              AS ojt_user_id,
            si.created_at
        FROM (" . student_list_source_sql($conn) . ") si
        LEFT JOIN users u ON u.id = si.user_id
        LEFT JOIN ojt_assignments oa ON oa.student_id = u.id
        LEFT JOIN company_information ci ON ci.user_id = oa.company_id
        LEFT JOIN (
            SELECT
                user_id,
                /* UPDATED (Start = first attendance): first day with a real time-in/out */
                MIN(CASE WHEN ((am_time_in  IS NOT NULL AND am_time_in  != '' AND am_time_in  != 'missed') OR (am_time_out IS NOT NULL AND am_time_out != '' AND am_time_out != 'missed') OR (pm_time_in  IS NOT NULL AND pm_time_in  != '' AND pm_time_in  != 'missed') OR (pm_time_out IS NOT NULL AND pm_time_out != '' AND pm_time_out != 'missed')) THEN date END) AS date_start,
                MAX(date) AS date_end,
                /* UPDATED (accurate hours): exact seconds per session, datetime-aware,
                   negative spans ignored and NULL-safe so one bad value never voids a day */
                ROUND(
                    SUM(
                        GREATEST(0, COALESCE(CASE
                            WHEN am_time_in  IS NOT NULL AND am_time_in  != '' AND am_time_in  != 'missed'
                             AND am_time_out IS NOT NULL AND am_time_out != '' AND am_time_out != 'missed'
                            THEN CASE
                                WHEN am_time_in LIKE '%-%-% %' AND am_time_out LIKE '%-%-% %'
                                THEN TIMESTAMPDIFF(SECOND, am_time_in, am_time_out)
                                ELSE (TIME_TO_SEC(TIME(am_time_out)) - TIME_TO_SEC(TIME(am_time_in)))
                            END
                            ELSE 0
                        END, 0))
                        +
                        GREATEST(0, COALESCE(CASE
                            WHEN pm_time_in  IS NOT NULL AND pm_time_in  != '' AND pm_time_in  != 'missed'
                             AND pm_time_out IS NOT NULL AND pm_time_out != '' AND pm_time_out != 'missed'
                            THEN CASE
                                WHEN pm_time_in LIKE '%-%-% %' AND pm_time_out LIKE '%-%-% %'
                                THEN TIMESTAMPDIFF(SECOND, pm_time_in, pm_time_out)
                                ELSE (TIME_TO_SEC(TIME(pm_time_out)) - TIME_TO_SEC(TIME(pm_time_in)))
                            END
                            ELSE 0
                        END, 0))
                    ) / 3600
                , 2) AS total_hours,
                COALESCE(
                    SUM(
                        GREATEST(0, COALESCE(CASE
                            WHEN am_time_in  IS NOT NULL AND am_time_in  != '' AND am_time_in  != 'missed'
                             AND am_time_out IS NOT NULL AND am_time_out != '' AND am_time_out != 'missed'
                            THEN CASE
                                WHEN am_time_in LIKE '%-%-% %' AND am_time_out LIKE '%-%-% %'
                                THEN TIMESTAMPDIFF(SECOND, am_time_in, am_time_out)
                                ELSE (TIME_TO_SEC(TIME(am_time_out)) - TIME_TO_SEC(TIME(am_time_in)))
                            END
                            ELSE 0
                        END, 0))
                        +
                        GREATEST(0, COALESCE(CASE
                            WHEN pm_time_in  IS NOT NULL AND pm_time_in  != '' AND pm_time_in  != 'missed'
                             AND pm_time_out IS NOT NULL AND pm_time_out != '' AND pm_time_out != 'missed'
                            THEN CASE
                                WHEN pm_time_in LIKE '%-%-% %' AND pm_time_out LIKE '%-%-% %'
                                THEN TIMESTAMPDIFF(SECOND, pm_time_in, pm_time_out)
                                ELSE (TIME_TO_SEC(TIME(pm_time_out)) - TIME_TO_SEC(TIME(pm_time_in)))
                            END
                            ELSE 0
                        END, 0))
                    )
                , 0) AS total_seconds
            FROM attendance_logs
            GROUP BY user_id
        ) att ON att.user_id = u.id
        " . ($exportExcludeIn !== '' ? "WHERE si.user_id NOT IN ($exportExcludeIn)" : "") . " /* NEW (Export exclusion) */
        ORDER BY si.created_at DESC
    ";

    $export_result = $conn->query($export_sql);

    $wb = new \PhpOffice\PhpSpreadsheet\Spreadsheet();
    $ws = $wb->getActiveSheet();
    $ws->setTitle('Students');

    $headerFill = [
        'fillType'   => \PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID,
        'startColor' => ['rgb' => '07145F'],
    ];
    $headerFont  = ['bold' => true, 'color' => ['rgb' => 'FFD700'], 'name' => 'Arial', 'size' => 11];
    $bodyFont    = ['name' => 'Arial', 'size' => 10];
    $centerAlign = ['horizontal' => \PhpOffice\PhpSpreadsheet\Style\Alignment::HORIZONTAL_CENTER, 'vertical' => \PhpOffice\PhpSpreadsheet\Style\Alignment::VERTICAL_CENTER, 'wrapText' => false];
    $leftAlign   = ['horizontal' => \PhpOffice\PhpSpreadsheet\Style\Alignment::HORIZONTAL_LEFT,   'vertical' => \PhpOffice\PhpSpreadsheet\Style\Alignment::VERTICAL_CENTER, 'wrapText' => false, 'indent' => 1];

    $headers = [
        'A' => 'First Name',
        'B' => 'Middle Name',
        'C' => 'Last Name',
        'D' => 'Course',
        'E' => 'Major',          // NEW (Major)
        'F' => 'Section',
        'G' => 'Email',
        'H' => 'Campus Branch',
        'I' => 'Company (OJT)',
        'J' => 'OJT Date Start',
        'K' => 'OJT Date End',
        'L' => 'Total Hours',
        'M' => 'Remaining Hours', // UPDATED (matches the on-page table): Imported On column replaced with Remaining Hours
    ];
    foreach ($headers as $col => $label) {
        $cell = $col . '1';
        $ws->setCellValue($cell, $label);
        $ws->getStyle($cell)->getFont()->applyFromArray($headerFont);
        $ws->getStyle($cell)->getFill()->applyFromArray($headerFill);
        $ws->getStyle($cell)->getAlignment()->applyFromArray($centerAlign);
        $ws->getStyle($cell)->getBorders()->getBottom()->setBorderStyle(
            \PhpOffice\PhpSpreadsheet\Style\Border::BORDER_MEDIUM
        );
    }

    $ws->freezePane('A2');

    $row = 2;
    $export_course_rules = student_list_course_rules($conn); // UPDATED (OJT Date End <-> Est. Duty Days)
    while ($r = $export_result->fetch_assoc()) {
        $ws->setCellValue('A' . $row, $r['first_name']);
        $ws->setCellValue('B' . $row, $r['middle_name'] ?? '');
        $ws->setCellValue('C' . $row, $r['last_name']);
        $ws->setCellValue('D' . $row, $r['course']);
        $ws->setCellValue('E' . $row, $r['major'] ?? ''); // NEW (Major)
        $ws->setCellValue('F' . $row, $r['section'] ?? '');
        $ws->setCellValue('G' . $row, $r['email']);
        $ws->setCellValue('H' . $row, $r['campus_branch']);
        $ws->setCellValue('I' . $row, $r['ojt_company'] ?? 'Unassigned');

        $ws->setCellValue('J' . $row, $r['ojt_date_start']
            ? date('M d, Y', strtotime($r['ojt_date_start'] ?? '')) : '—');
        // UPDATED (OJT Date End <-> Est. Duty Days)
        $endInfo = student_list_ojt_end_info($conn, $export_course_rules, $r);
        $ws->setCellValue('K' . $row, $endInfo['date']
            ? date('M d, Y', strtotime($endInfo['date'] ?? '')) . ($endInfo['type'] === 'estimated' ? ' (est.)' : '') : '—');

        $hrs = floatval($r['ojt_total_hours'] ?? 0);
        // UPDATED (accurate hours): exact hours + minutes instead of a rounded decimal
        $ws->setCellValue('L' . $row, student_list_format_hours($r['ojt_total_seconds'] ?? null, $hrs));

        // UPDATED (matches the on-page table): "Imported On" replaced with
        // "Remaining Hours" here too — same calculation as the table's
        // Remaining Hours column: required hours (from the same $endInfo
        // Course Offering rule resolved just above for OJT Date End) minus
        // the hours already rendered.
        $reqRule = $endInfo['rule'] ?? null;
        if ($reqRule && (float)$reqRule['total'] > 0) {
            $requiredSecs  = (int)round((float)$reqRule['total'] * 3600);
            $renderedSecs  = isset($r['ojt_total_seconds']) ? (int)round((float)$r['ojt_total_seconds']) : (int)round($hrs * 3600);
            $remainingSecs = max(0, $requiredSecs - $renderedSecs);
            $remainingVal  = $remainingSecs <= 0 ? 'Completed' : student_list_format_hours($remainingSecs);
        } else {
            $remainingVal = '—';
        }
        $ws->setCellValue('M' . $row, $remainingVal);

        $rowFill = ($row % 2 === 0)
            ? ['fillType' => \PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID, 'startColor' => ['rgb' => 'F5F3FF']]
            : ['fillType' => \PhpOffice\PhpSpreadsheet\Style\Fill::FILL_NONE];

        foreach (array_keys($headers) as $col) {
            $ws->getStyle($col . $row)->getFont()->applyFromArray($bodyFont);
            $ws->getStyle($col . $row)->getAlignment()->applyFromArray($leftAlign);
            if ($row % 2 === 0) {
                $ws->getStyle($col . $row)->getFill()->applyFromArray($rowFill);
            }
        }

        $row++;
    }

    $colMins = [
        'A' => 16, 'B' => 16, 'C' => 16, 'D' => 18, 'E' => 24, 'F' => 16,
        'G' => 36, 'H' => 22, 'I' => 32, 'J' => 18, 'K' => 18, 'L' => 14, 'M' => 18,
    ];
    foreach (array_keys($headers) as $col) {
        $ws->getColumnDimension($col)->setAutoSize(true);
    }
    $ws->calculateColumnWidths();
    foreach ($colMins as $col => $minWidth) {
        $calculated = $ws->getColumnDimension($col)->getWidth();
        $final = max($minWidth, $calculated + 4);
        $ws->getColumnDimension($col)->setAutoSize(false);
        $ws->getColumnDimension($col)->setWidth($final);
    }

    $ws->getRowDimension(1)->setRowHeight(24);
    for ($i = 2; $i < $row; $i++) {
        $ws->getRowDimension($i)->setRowHeight(18);
    }

    $filename = 'students_export_' . date('Y-m-d_His') . '.xlsx';
    header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('Cache-Control: max-age=0');

    $writer = \PhpOffice\PhpSpreadsheet\IOFactory::createWriter($wb, 'Xlsx');
    $writer->save('php://output');
    /* NEW (Export result screen): flush the buffer opened above for AJAX
       export requests (no-op otherwise). */
    while (ob_get_level() > 0) { ob_end_flush(); }
    exit;
}
// ── END EXPORT ───────────────────────────────────────────────────────────────


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
   admin_company_list.php's aclCompanyValidationNotifCount()): the
   "Company Requirements" badge used to count moa_requests rows with
   status='Pending'. That does not match what company_validation.php
   treats as a notification: every Pending MOA request is auto-ingested
   there right away (so that count was almost always 0 / out of sync),
   and its Notification Inbox lists (a) un-viewed, transferred MOA
   notifications (moa_requests.admin_viewed=0 AND transferred=1) PLUS
   (b) un-viewed requirement-upload notifications
   (company_requirement_upload_notifications.admin_viewed=0).
   This helper uses exactly that same rule, so the number shown here is
   always the same number shown on company_validation.php and
   admin_company_list.php. Every lookup is guarded — a missing
   column/table simply counts as 0. Read-only: no DDL, no writes. ── */
if (!function_exists('student_list_company_validation_notif_count')) {
    function student_list_company_validation_notif_count($conn) {
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

/* ── NEW (sidebar notification indicator): lightweight JSON endpoint the
   sidebar polls so the "Company Requirements" badge stays in step with
   company_validation.php without a page reload (same as
   admin_company_list.php). Placed before any HTML output; read-only. ── */
if (isset($_GET['cv_sidebar_notif_count']) && $_GET['cv_sidebar_notif_count'] === '1') {
    header('Content-Type: application/json');
    echo json_encode(['count' => student_list_company_validation_notif_count($conn)]);
    exit;
}

/* UPDATED (no students_import): this page no longer creates, reads or
   writes the `students_import` staging table. Every student is saved
   straight into `users` + `student_information` (see
   attempt_create_student_account_from_data()), and the Student List is
   built only from those tables (see student_list_source_sql()). */

/* ════════════════════════════════════════════════════════════════════
   NEW (Course in users): a student's COURSE is now saved in the `users`
   table (users.course — the same column admin_company_list.php already
   writes for company accounts). The column is added only if an install
   doesn't have it yet. Student accounts created before this change that
   still have a blank users.course are filled in once from their
   student_information entry, matched by account; accounts that already
   have a course are never touched.
   UPDATED (no students_import): the old fill-in from students_import
   was removed.
   ════════════════════════════════════════════════════════════════════ */
try {
    $check_users_course_col = $conn->query("SHOW COLUMNS FROM users LIKE 'course'");
    if ($check_users_course_col && $check_users_course_col->num_rows === 0) {
        $conn->query("ALTER TABLE users ADD COLUMN course VARCHAR(100) NULL");
    }
    $check_si_course_col = $conn->query("SHOW COLUMNS FROM student_information LIKE 'course'");
    if ($check_si_course_col && $check_si_course_col->num_rows > 0) {
        $conn->query("UPDATE users u
            JOIN (SELECT user_id, MAX(course) AS course FROM student_information
                  WHERE course IS NOT NULL AND course <> '' GROUP BY user_id) sic ON sic.user_id = u.id
            SET u.course = sic.course
            WHERE u.role = 'student' AND (u.course IS NULL OR u.course = '')");
    }
} catch (\Throwable $e) {}

/* ════════════════════════════════════════════════════════════════════
   NEW (Course Offering): the list of offered courses is managed on
   course_offering.php (same table definition there). A student can
   only be imported or manually added when their course exists in this
   table; otherwise the admin is asked to skip that student or cancel.
   Matching ignores letter case and surrounding spaces.
   ════════════════════════════════════════════════════════════════════ */
$conn->query("CREATE TABLE IF NOT EXISTS course_offerings (
    id INT AUTO_INCREMENT PRIMARY KEY,
    course VARCHAR(100) NOT NULL UNIQUE,
    total_hours INT NOT NULL,
    daily_hours DECIMAL(5,2) NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
)");

function student_list_normalize_course($course) {
    return mb_strtolower(preg_replace('/\s+/', ' ', trim((string)$course)));
}

/* Returns [normalized course => course name as written in Course Offering]. */
function student_list_get_course_offerings($conn) {
    $map = [];
    $res = $conn->query("SELECT course FROM course_offerings ORDER BY course ASC");
    if ($res) {
        while ($r = $res->fetch_assoc()) $map[student_list_normalize_course($r['course'])] = $r['course'];
    }
    return $map;
}

function student_list_course_is_offered(array $offeringMap, $course) {
    return isset($offeringMap[student_list_normalize_course($course)]);
}

$course_offering_map = student_list_get_course_offerings($conn);

/* ════════════════════════════════════════════════════════════════════
   NEW (Campus Branch / Course import filter revision — adopted from the
   Company Type / Request Type import filter in admin_company_list.php):
   shared helpers used by the read-only "detect import classifications"
   endpoint, the Course Offering import pre-check, and the real XLSX
   importer below, so all three treat a row's Campus Branch / Course
   exactly the same way. Values are compared case-insensitively with
   extra spaces collapsed (the same normalization already used for
   Course Offering matching), so "Main Campus" and "main  campus" are
   treated as the same branch.
   ════════════════════════════════════════════════════════════════════ */
function student_list_normalize_campus($campus) {
    return mb_strtolower(preg_replace('/\s+/', ' ', trim((string)$campus)));
}

/* Reads the admin's picker selection (selected_campus_branches[] /
   selected_courses[]) from the POST. Returns
   [filterActive, campusSet|null, courseSet|null].
   Backward compatible: when neither field is present at all (e.g. an old
   replayed request, or a caller that never went through the picker) no
   filtering is applied and every row is considered a match — the exact
   behavior this page had before this revision. */
function student_list_import_filter_from_post() {
    $campusPost = (isset($_POST['selected_campus_branches']) && is_array($_POST['selected_campus_branches'])) ? $_POST['selected_campus_branches'] : null;
    $coursePost = (isset($_POST['selected_courses']) && is_array($_POST['selected_courses'])) ? $_POST['selected_courses'] : null;
    $campusSet = null;
    $courseSet = null;
    if ($campusPost !== null) {
        $campusSet = [];
        foreach ($campusPost as $v) $campusSet[student_list_normalize_campus($v)] = true;
    }
    if ($coursePost !== null) {
        $courseSet = [];
        foreach ($coursePost as $v) $courseSet[student_list_normalize_course($v)] = true;
    }
    return [($campusPost !== null || $coursePost !== null), $campusSet, $courseSet];
}

/* True when the row's Campus Branch AND Course are both among the
   admin's selection (a null set means "no filter on that field"). */
function student_list_import_row_matches_filter($campusSet, $courseSet, $campus, $course) {
    $campusOk = ($campusSet === null) || isset($campusSet[student_list_normalize_campus($campus)]);
    $courseOk = ($courseSet === null) || isset($courseSet[student_list_normalize_course($course)]);
    return $campusOk && $courseOk;
}

// ── ensure the moa_requests table exists so the pending-count query
// below (used for the "Company Requirements" sidebar indicator) never
// fails on a fresh install — mirrors the same guard used in
// company_validation.php / moa_request.php. No other logic touched.
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

/* UPDATED: the Registration Settings feature (registration_settings table
   setup, the settings update handler, and the values it loaded) was
   removed entirely from this page. */

/* ════════════════════════════════════════════════════════════════════
   NEW (Student account auto-creation — adopted from
   admin_company_list.php's attempt_create_company_account()):

   Saves one student as a real, loginable STUDENT account, following
   the register.php split:
     - `users`               : first_name, middle_name, last_name
                               (+ role='student', email, hashed password,
                               which every account needs in order to log in)
     - `student_information` : every other piece of student data
                               (user_id link, course, section, campus
                               branch, email — whichever of these columns
                               the table actually has).

   UPDATED (no students_import): nothing is staged in a students_import
   table anymore. An XLSX import or manual add hands the student's data
   straight to this function; rows waiting for the "Creating Student
   Accounts" popup are kept only in the admin's PHP session (see
   student_list_queue_pending_account()) and removed once processed.

   Column-safe inserts: the exact columns of `users` and
   `student_information` are read live from the database (SHOW COLUMNS)
   and only columns that really exist are written. Any NOT NULL column
   with no default that this page has no value for is given a safe
   blank value, so the insert never fails on strict-mode databases.

   Outcomes (same shape as the company page, so the popup is identical):
     'created' — account created (even if the password email failed;
                 that is reported separately in 'email_issue').
     'skipped' — missing/invalid email, email already has an account,
                 or the row no longer exists.
     'failed'  — unexpected database error; everything is rolled back.
   ════════════════════════════════════════════════════════════════════ */
function student_list_table_columns($conn, $table) {
    static $cache = [];
    if (isset($cache[$table])) return $cache[$table];
    $cols = [];
    try {
        $res = $conn->query("SHOW COLUMNS FROM `" . str_replace('`', '', $table) . "`");
        if ($res) {
            while ($c = $res->fetch_assoc()) $cols[$c['Field']] = $c;
        }
    } catch (\Throwable $e) {
        $cols = [];
    }
    $cache[$table] = $cols;
    return $cols;
}

function student_list_blank_value_for_column(array $colMeta) {
    $type = strtolower($colMeta['Type'] ?? '');
    if (preg_match('/^enum\((.*)\)$/', $type, $m)) {
        $opts = str_getcsv($m[1], ',', "'");
        return isset($opts[0]) ? $opts[0] : '';
    }
    if (preg_match('/int|decimal|float|double|bit|year|numeric|real/', $type)) return 0;
    if (strpos($type, 'datetime') === 0 || strpos($type, 'timestamp') === 0) return date('Y-m-d H:i:s');
    if (strpos($type, 'date') === 0) return date('Y-m-d');
    if (strpos($type, 'time') === 0) return date('H:i:s');
    return '';
}

/* UPDATED: guarantees users.company_validation_status accepts NULL, so a
   student account can be saved with NULL there (admin_company_list.php
   already inserts NULL into this same column for its accounts). Only the
   NULL-ability changes — the column's type and default value are kept. */
function student_list_ensure_company_validation_status_nullable($conn) {
    static $done = false;
    if ($done) return;
    $done = true;
    try {
        $res = $conn->query("SHOW COLUMNS FROM users LIKE 'company_validation_status'");
        $col = $res ? $res->fetch_assoc() : null;
        if ($col && strtoupper($col['Null']) === 'NO') {
            $defaultSql = ($col['Default'] === null) ? 'NULL' : "'" . $conn->real_escape_string($col['Default']) . "'";
            $conn->query("ALTER TABLE users MODIFY company_validation_status " . $col['Type'] . " NULL DEFAULT " . $defaultSql);
        }
    } catch (\Throwable $e) {}
}

/* NEW (Campus Branch fix): guarantees `student_information` has somewhere
   to store the Campus Branch value. On some databases this table was
   created with NONE of the column names this page looks for
   (campus_branch / campus / branch), so every campus branch value from
   an XLSX import or the "Add Student" / "Edit Student" forms was quietly
   discarded on save — the table itself had no matching column to insert
   it into, even though the surrounding code was already written to
   handle it. This adds a standard `campus_branch` VARCHAR column the
   first time none of those three are found. It is a no-op (and touches
   nothing) if the table already has campus_branch, campus, OR branch —
   an existing column is never renamed, resized or overwritten. */
function student_list_ensure_campus_branch_column($conn) {
    static $done = false;
    if ($done) return;
    $done = true;
    try {
        $res = $conn->query("SHOW COLUMNS FROM student_information");
        if (!$res) return; // table missing/unreadable — leave it alone, nothing to add to
        $existing = [];
        while ($c = $res->fetch_assoc()) $existing[strtolower($c['Field'])] = true;
        if (isset($existing['campus_branch']) || isset($existing['campus']) || isset($existing['branch'])) {
            return; // already has a place to store it — nothing to do
        }
        $conn->query("ALTER TABLE `student_information` ADD COLUMN `campus_branch` VARCHAR(150) NULL DEFAULT NULL");
    } catch (\Throwable $e) {
        error_log('[admin_student_list.php] could not add campus_branch column to student_information: ' . $e->getMessage());
    }
}

/* Runs an INSERT into $table using only the keys of $data that exist as
   real columns, auto-filling required (NOT NULL, no default) columns
   that weren't supplied. Throws \RuntimeException on failure. */
function student_list_insert_row($conn, $table, array $data) {
    $cols = student_list_table_columns($conn, $table);
    if (empty($cols)) {
        throw new \RuntimeException("the `" . $table . "` table could not be found.");
    }

    $insert = [];
    foreach ($data as $field => $value) {
        if (!isset($cols[$field])) continue;
        $extra = strtolower($cols[$field]['Extra'] ?? '');
        if (strpos($extra, 'generated') !== false && strpos($extra, 'default_generated') === false) continue;
        if ($value === null && strtoupper($cols[$field]['Null'] ?? 'YES') === 'NO') {
            $value = student_list_blank_value_for_column($cols[$field]);
        }
        $insert[$field] = $value;
    }

    foreach ($cols as $field => $meta) {
        if (array_key_exists($field, $insert)) continue;
        $extra = strtolower($meta['Extra'] ?? '');
        if (strpos($extra, 'auto_increment') !== false) continue;
        if (strpos($extra, 'generated') !== false) continue;
        if (strtoupper($meta['Null'] ?? 'YES') !== 'NO') continue;
        if ($meta['Default'] !== null) continue;
        $insert[$field] = student_list_blank_value_for_column($meta);
    }

    if (empty($insert)) {
        throw new \RuntimeException("nothing could be written to the `" . $table . "` table.");
    }

    $fieldSql = implode(', ', array_map(function ($f) { return '`' . $f . '`'; }, array_keys($insert)));
    $placeholders = implode(', ', array_fill(0, count($insert), '?'));
    $types = '';
    $values = [];
    foreach ($insert as $v) {
        $types .= is_int($v) ? 'i' : 's';
        $values[] = $v;
    }

    $stmt = $conn->prepare("INSERT INTO `" . $table . "` (" . $fieldSql . ") VALUES (" . $placeholders . ")");
    if (!$stmt) {
        throw new \RuntimeException("could not prepare the `" . $table . "` insert (" . $conn->error . ").");
    }
    $stmt->bind_param($types, ...$values);
    if (!$stmt->execute()) {
        $err = $stmt->error;
        $stmt->close();
        throw new \RuntimeException("could not save to the `" . $table . "` table (" . $err . ").");
    }
    $insertId = $conn->insert_id;
    $stmt->close();
    return $insertId;
}

/* ════════════════════════════════════════════════════════════════════
   NEW (Cross-table student list): the Student List is built from the
   tables that hold the student's real data:
     - `users` (role = 'student') for the names, email and course,
     - `student_information` for major, section and campus branch
       (and course as a fallback).
   UPDATED (no students_import): the old students_import fallback and
   the "imported entries without an account" part were removed — every
   student now has an account, so each student appears exactly once.

   Every row carries:
     row_key — 'u<users.id>'; used by the Edit / Delete selection flow.
     id      — always NULL (kept so existing code reading it still works).
     user_id — the users id.
     source  — 'registered'.
   The result is used as a derived table aliased `si`, so every existing
   filter (si.first_name, si.course, ...) keeps working unchanged.

   Text columns are converted to one charset/collation so COALESCE never
   fails with "Illegal mix of collations" when the tables were created
   with different collations. student_information's column names are
   detected live (same candidates the account creation uses).
   ════════════════════════════════════════════════════════════════════ */
function student_list_ci($expr) {
    return "(CONVERT($expr USING utf8mb4) COLLATE utf8mb4_general_ci)";
}

function student_list_source_sql($conn) {
    static $sql = null;
    if ($sql !== null) return $sql;

    $siCols  = student_list_table_columns($conn, 'student_information');
    $hasSinf = isset($siCols['user_id']);
    $pick = function (array $candidates) use ($siCols) {
        foreach ($candidates as $c) {
            if (isset($siCols[$c])) return "MAX(`" . $c . "`)";
        }
        return "NULL";
    };

    $sinfJoin = '';
    $sinfCourse = $sinfSection = $sinfCampus = 'NULL';
    $sinfMajor = 'NULL'; // NEW (Major)
    if ($hasSinf) {
        $sinfJoin = "LEFT JOIN (
                SELECT user_id,
                       " . $pick(['course', 'program']) . " AS course,
                       " . $pick(['major']) . " AS major,
                       " . $pick(['section', 'year_section', 'year_and_section']) . " AS section,
                       " . $pick(['campus_branch', 'campus', 'branch']) . " AS campus_branch
                FROM student_information
                GROUP BY user_id
            ) sinf ON sinf.user_id = u2.id";
        $sinfCourse  = 'sinf.course';
        $sinfMajor   = 'sinf.major'; // NEW (Major)
        $sinfSection = 'sinf.section';
        $sinfCampus  = 'sinf.campus_branch';
    }

    $uCols = student_list_table_columns($conn, 'users');
    $userCreated = isset($uCols['created_at']) ? 'u2.created_at' : 'NULL';
    $userCourse  = isset($uCols['course']) ? 'u2.course' : 'NULL'; // NEW (Course in users)

    $registeredSelect = "
        SELECT " . student_list_ci("CONCAT('u', u2.id)") . " AS row_key,
               NULL AS id,
               u2.id AS user_id,
               " . student_list_ci('u2.first_name') . " AS first_name,
               " . student_list_ci('u2.middle_name') . " AS middle_name,
               " . student_list_ci('u2.last_name') . " AS last_name,
               " . student_list_ci("COALESCE(NULLIF(CONVERT($userCourse USING utf8mb4), ''), CONVERT($sinfCourse USING utf8mb4))") . " AS course,
               " . student_list_ci($sinfMajor) . " AS major,
               " . student_list_ci($sinfSection) . " AS section,
               " . student_list_ci('u2.email') . " AS email,
               " . student_list_ci($sinfCampus) . " AS campus_branch,
               $userCreated AS created_at,
               " . student_list_ci("'registered'") . " AS source
        FROM users u2
        $sinfJoin
        WHERE u2.role = 'student'
    ";

    $sql = $registeredSelect;
    return $sql;
}

/* NEW (Cross-table student list): splits the Edit/Delete row keys into
   registered users ids ('u12') and students_import ids ('i34', or a plain
   number for backwards compatibility). */
function student_list_parse_row_keys(array $rawKeys) {
    $userIds = [];
    $importIds = [];
    foreach ($rawKeys as $raw) {
        $raw = trim((string)$raw);
        if (preg_match('/^u(\d+)$/', $raw, $m)) {
            if ((int)$m[1] > 0) $userIds[] = (int)$m[1];
        } elseif (preg_match('/^i?(\d+)$/', $raw, $m)) {
            if ((int)$m[1] > 0) $importIds[] = (int)$m[1];
        }
    }
    return [array_values(array_unique($userIds)), array_values(array_unique($importIds))];
}

/* NEW (Cross-table student list): permanently deletes ONE registered
   student account, mirroring admin_company_list.php's
   company_list_delete_company_account(): every record that references
   the account is removed inside one transaction (all-or-nothing), then
   the users row. UPDATED (no students_import): there is no staging
   entry to clean up anymore. */
function student_list_delete_student_account($conn, $userId, $performerName) {
    $result = ['success' => false, 'label' => 'Account #' . $userId, 'message' => ''];

    $lookup = $conn->prepare("SELECT first_name, middle_name, last_name, email FROM users WHERE id = ? AND role = 'student' LIMIT 1");
    $lookup->bind_param("i", $userId);
    $lookup->execute();
    $acc = $lookup->get_result()->fetch_assoc();
    $lookup->close();
    if (!$acc) {
        $result['message'] = 'Account #' . $userId . ': this student account could not be found (it may have already been deleted).';
        return $result;
    }

    $accEmail = (string)($acc['email'] ?? '');
    $accName  = preg_replace('/\s+/', ' ', trim(($acc['first_name'] ?? '') . ' ' . ($acc['middle_name'] ?? '') . ' ' . ($acc['last_name'] ?? '')));
    if ($accName === '') $accName = 'Unknown';
    $result['label'] = $accName;

    $targets = [
        'reports'                  => ['user_id'],
        'ojt_assignments'          => ['student_id'],
        'admin_application_approvals' => ['student_id'],
        'activity_logs'            => ['user_id'],
        'student_information'      => ['user_id'],
        'student_skills'           => ['user_id'],
        'student_experience'       => ['user_id'],
        'requirements'             => ['user_id'],
        'attendance_logs'          => ['user_id'],
        'late_requests'            => ['student_id'],
        'final_grades'             => ['student_id'],
        'ojt_applications'         => ['student_id'],
        'application_requirements' => ['student_id'],
        'ojt_reminder_log'         => ['user_id'],
        'archived_students'        => ['user_id'],
    ];

    try {
        $conn->begin_transaction();

        foreach ($targets as $table => $wantedCols) {
            $cols = student_list_table_columns($conn, $table);
            if (empty($cols)) continue;
            $conds = [];
            foreach ($wantedCols as $col) {
                if (isset($cols[$col])) $conds[] = "`$col` = " . (int)$userId;
            }
            if (empty($conds)) continue;
            if (!$conn->query("DELETE FROM `$table` WHERE " . implode(' OR ', $conds))) {
                throw new \RuntimeException("Failed to delete records from $table: " . $conn->error);
            }
        }

        if ($accEmail !== '' && !empty(student_list_table_columns($conn, 'email_recovery_requests'))) {
            $st = $conn->prepare("DELETE FROM email_recovery_requests WHERE old_email = ? OR new_email = ?");
            if ($st) { $st->bind_param("ss", $accEmail, $accEmail); $st->execute(); $st->close(); }
        }

        $del = $conn->prepare("DELETE FROM users WHERE id = ? AND role = 'student'");
        $del->bind_param("i", $userId);
        $del->execute();
        $del->close();

        $verify = $conn->prepare("SELECT COUNT(*) FROM users WHERE id = ?");
        $verify->bind_param("i", $userId);
        $verify->execute();
        $verify->bind_result($stillThere);
        $verify->fetch();
        $verify->close();
        if ((int)$stillThere > 0) throw new \RuntimeException("Account row could not be removed from users.");

        $conn->commit();
    } catch (\Throwable $e) {
        try { $conn->rollback(); } catch (\Throwable $ignored) {}
        error_log('[admin_student_list.php] delete student account failed: ' . $e->getMessage());
        $result['message'] = htmlspecialchars($accName ?? '') . ': could not fully delete this account and its records, so nothing was deleted for it. Please try again.';
        return $result;
    }

    // Best-effort activity log entry (same shape as admin_company_list.php).
    try {
        $roleLabel = 'Student';
        $logDetails = "Student account deleted for $accName via Student List";
        $logStmt = $conn->prepare("INSERT INTO activity_logs (action_type, performed_by, account_type, account_name, details, created_at)
            VALUES ('Account Deleted', ?, ?, ?, ?, NOW())");
        if ($logStmt) {
            $logStmt->bind_param("ssss", $performerName, $roleLabel, $accName, $logDetails);
            $logStmt->execute();
            $logStmt->close();
        }
    } catch (\Throwable $ignored) {}

    $result['success'] = true;
    return $result;
}

/* ════════════════════════════════════════════════════════════════════
   UPDATED (no students_import): students waiting for their account after
   an XLSX import are kept in the admin's PHP session (never in a
   database table) under a random key, until the "Creating Student
   Accounts" popup sends that key back to create_student_accounts_selected.
   Each entry is removed from the session as soon as it is processed.
   ════════════════════════════════════════════════════════════════════ */
function student_list_queue_pending_account(array $data) {
    if (!isset($_SESSION['student_list_pending_accounts']) || !is_array($_SESSION['student_list_pending_accounts'])) {
        $_SESSION['student_list_pending_accounts'] = [];
    }
    $key = 'p' . bin2hex(random_bytes(8));
    $_SESSION['student_list_pending_accounts'][$key] = [
        'first_name'    => (string)($data['first_name'] ?? ''),
        'middle_name'   => $data['middle_name'] ?? null,
        'last_name'     => (string)($data['last_name'] ?? ''),
        'course'        => (string)($data['course'] ?? ''),
        'major'         => $data['major'] ?? null,
        'section'       => $data['section'] ?? null,
        'email'         => (string)($data['email'] ?? ''),
        'campus_branch' => (string)($data['campus_branch'] ?? ''),
    ];
    return $key;
}

/* Only accepts keys made by student_list_queue_pending_account(). */
function student_list_clean_pending_key($raw) {
    $raw = trim((string)$raw);
    return preg_match('/^p[0-9a-f]{16}$/', $raw) ? $raw : '';
}

/* UPDATED (no students_import): creates the account for one queued
   (session) student, then removes it from the queue. */
function attempt_create_student_account($conn, $pendingKey) {
    $pendingKey = student_list_clean_pending_key($pendingKey);
    $impRow = ($pendingKey !== '' && isset($_SESSION['student_list_pending_accounts'][$pendingKey]))
        ? $_SESSION['student_list_pending_accounts'][$pendingKey]
        : null;
    if ($pendingKey !== '') unset($_SESSION['student_list_pending_accounts'][$pendingKey]);

    if (empty($impRow)) {
        return [
            'outcome' => 'skipped',
            'student_label' => 'Student',
            'detail' => "A student could not be found in the waiting list (it may have already been processed). Please import or add this student again if needed.",
            'email_issue' => null,
        ];
    }
    return attempt_create_student_account_from_data($conn, $impRow);
}

/* Creates the login account (users + student_information) straight from
   the student's data, and emails the password. Same logic as before —
   only the data now comes from the import / Add Student form directly
   instead of a students_import row. */
function attempt_create_student_account_from_data($conn, array $impRow) {
    $result = [
        'outcome' => 'skipped', // 'created' | 'skipped' | 'failed'
        'student_label' => 'Student',
        'detail' => null,
        'email_issue' => null,
    ];

    $fullName = preg_replace('/\s+/', ' ', trim(
        ($impRow['first_name'] ?? '') . ' ' . ($impRow['middle_name'] ?? '') . ' ' . ($impRow['last_name'] ?? '')
    ));
    $studentLabel = htmlspecialchars($fullName !== '' ? $fullName : 'Student');
    $result['student_label'] = $studentLabel;

    $emailVal = trim($impRow['email'] ?? '');
    if ($emailVal === '' || !filter_var($emailVal, FILTER_VALIDATE_EMAIL)) {
        $result['detail'] = $studentLabel . ": missing or invalid email address, so no account could be created. Edit this entry to add a valid email, then try again.";
        return $result;
    }

    $dupCheck = $conn->prepare("SELECT id FROM users WHERE email = ?");
    $dupCheck->bind_param("s", $emailVal);
    $dupCheck->execute();
    $dupCheck->store_result();
    $alreadyRegistered = $dupCheck->num_rows > 0;
    $dupCheck->close();

    if ($alreadyRegistered) {
        $result['detail'] = $studentLabel . ": this email (" . htmlspecialchars($emailVal ?? '') . ") is already associated with an existing account, so a new one was not created.";
        return $result;
    }

    $plainPassword = null;
    student_list_ensure_company_validation_status_nullable($conn); // UPDATED (runs before the transaction, since ALTER auto-commits)
    $conn->begin_transaction();
    try {
        $plainPassword  = (string) random_int(100000, 999999);
        $hashedPassword = password_hash($plainPassword, PASSWORD_DEFAULT);

        $middleN = trim((string)($impRow['middle_name'] ?? ''));
        if ($middleN === '') $middleN = null;

        // ── users: names (+ login essentials) ──
        $newUserId = (int) student_list_insert_row($conn, 'users', [
            'first_name'  => trim((string)$impRow['first_name']),
            'middle_name' => $middleN,
            'last_name'   => trim((string)$impRow['last_name']),
            'role'        => 'student',
            'email'       => $emailVal,
            'password'    => $hashedPassword,
            'course'      => trim((string)($impRow['course'] ?? '')), // NEW (Course in users)
            /* UPDATED: students are not companies, so their
               company_validation_status is explicitly saved as NULL
               instead of falling back to the column's 'pending' default. */
            'company_validation_status' => null,
        ]);
        if ($newUserId <= 0) {
            throw new \RuntimeException("could not create the login account.");
        }

        // ── student_information: every other piece of student data ──
        $campusVal  = trim((string)($impRow['campus_branch'] ?? ''));
        $courseVal  = trim((string)($impRow['course'] ?? ''));
        $sectionVal = trim((string)($impRow['section'] ?? ''));
        if ($sectionVal === '') $sectionVal = null;
        $majorVal = trim((string)($impRow['major'] ?? '')); // NEW (Major)
        if ($majorVal === '') $majorVal = null;

        student_list_insert_row($conn, 'student_information', [
            'user_id'          => $newUserId,
            // UPDATED (Course in users): course is saved in `users` above, not here.
            'section'          => $sectionVal,
            'year_section'     => $sectionVal,
            'major'            => $majorVal, // NEW (Major)
            'campus_branch'    => $campusVal,
            'campus'           => $campusVal,
            'branch'           => $campusVal,
            'email'            => $emailVal,
        ]);

        $conn->commit();
        $result['outcome'] = 'created';

    } catch (\Throwable $e) {
        $conn->rollback();
        $result['outcome'] = 'failed';
        $result['detail'] = $studentLabel . ": " . htmlspecialchars($e->getMessage());
        return $result;
    }

    /* Best-effort password email — same SMTP setup and branded design as
       admin_company_list.php. A failed send does NOT undo the account;
       it is only reported so the admin can relay the password manually. */
    try {
        if (!class_exists('\\PHPMailer\\PHPMailer\\PHPMailer')) {
            throw new \RuntimeException('PHPMailer is not available.');
        }
        $mail = new \PHPMailer\PHPMailer\PHPMailer(true);
        $mail->isSMTP();
        $mail->Host       = 'smtp.gmail.com';
        $mail->SMTPAuth   = true;
        $mail->Username   = 'salesjohnlhoyd@gmail.com';
        $mail->Password   = 'qwufanprpmezotly';
        $mail->SMTPSecure = 'tls';
        $mail->Port       = 587;
        $mail->setFrom('salesjohnlhoyd@gmail.com', 'Atate On the Job Training System');
        $mail->addAddress($emailVal);
        $mail->isHTML(true);
        $mail->Subject = 'Your Student Account Login Password - NEUST OJT Portal';
        $firstForEmail = trim((string)($impRow['first_name'] ?? ''));
        $greetingForEmail = $firstForEmail !== ''
            ? "Hello, <strong>" . htmlspecialchars($firstForEmail ?? '') . "</strong>!"
            : "Hello,";
        $accountBody = "
            <p style='margin:0 0 14px;color:#1e293b;font-size:15px;'>" . $greetingForEmail . "</p>
            <p style='margin:0 0 22px;color:#334155;font-size:14px;line-height:1.7;'>
                A student account has been created for you on the NEUST OJT Portal by the administrator.
                Use the password below together with your registered email
                (<strong>" . htmlspecialchars($emailVal ?? '') . "</strong>) to log in.
                For your security, please don't share this password with anyone.
            </p>

            <div style='background:#f8fafc;border:1px solid #e2e8f0;border-radius:12px;padding:22px;text-align:center;margin:0 0 22px;'>
                <p style='margin:0 0 8px;color:#64748b;font-size:11px;font-weight:700;letter-spacing:1.5px;text-transform:uppercase;'>Your Login Password</p>
                <p style='margin:0;color:#0038a8;font-size:32px;font-weight:800;letter-spacing:6px;'>" . htmlspecialchars($plainPassword ?? '') . "</p>
            </div>

            <p style='margin:0 0 4px;color:#64748b;font-size:13px;line-height:1.6;font-style:italic;'>
                Didn't expect this email? Please contact your school administrator right away so your account can be secured.
            </p>
        ";
        $mail->Body = student_list_build_branded_email_template(
            '#1e3a8a',
            '#eef2ff',
            '', // UPDATED: lock emoji removed from the email banner
            'Your Student Account Login Password',
            $accountBody
        );
        $mail->send();
    } catch (\Throwable $mailErr) {
        $result['email_issue'] = $studentLabel . ": account created successfully, but the password email could not be sent (SMTP error). Please relay the login details to the student directly.";
    }

    return $result;
}

/* Same branded email wrapper admin_company_list.php uses, under its own
   name so it can never collide with any other page's function. */
function student_list_build_branded_email_template($bannerColor, $bannerBg, $bannerIcon, $bannerText, $bodyHtml) {
    return "
    <div style=\"font-family:'Segoe UI',Arial,Helvetica,sans-serif;background:#eef1f8;padding:30px 10px;\">
      <div style=\"max-width:560px;margin:0 auto;background:#ffffff;border-radius:16px;overflow:hidden;box-shadow:0 6px 24px rgba(0,0,0,0.08);border:1px solid #e2e8f0;\">
        <div style=\"background:linear-gradient(135deg,#0a1454 0%,#132a8c 55%,#1a237e 100%);padding:30px 20px;text-align:center;\">
          <p style=\"margin:0 0 6px;color:#FFD700;font-size:22px;font-weight:800;letter-spacing:0.5px;\">NEUST OJT Portal</p>
          <p style=\"margin:0;color:rgba(255,255,255,0.75);font-size:13px;\">Atate Campus &mdash; On the Job Training System</p>
        </div>
        <div style=\"background:{$bannerBg};padding:16px 20px;text-align:center;border-bottom:1px solid rgba(0,0,0,0.06);\">
          <p style=\"margin:0;color:{$bannerColor};font-size:16px;font-weight:700;\">{$bannerIcon} {$bannerText}</p>
        </div>
        <div style=\"padding:32px 28px;\">
          {$bodyHtml}
        </div>
        <div style=\"background:#f1f5f9;padding:16px 20px;text-align:center;\">
          <p style=\"margin:0;color:#94a3b8;font-size:11px;line-height:1.6;\">
            This is an automated message from the NEUST OJT Validation System.<br>Please do not reply to this email.
          </p>
        </div>
      </div>
    </div>
    ";
}

/* Runs attempt_create_student_account() for a batch of ids and returns
   the created/skipped/failed breakdown used by the popup. */
function run_student_account_creation_batch($conn, array $importIds) {
    $createdCount = 0;
    $skippedCount = 0;
    $failedCount  = 0;
    $skippedDetails = [];
    $failedDetails  = [];
    $createdWithEmailIssues = [];

    foreach ($importIds as $importId) {
        $res = attempt_create_student_account($conn, $importId); // UPDATED (no students_import): session queue key
        if ($res['outcome'] === 'created') {
            $createdCount++;
            if (!empty($res['email_issue'])) $createdWithEmailIssues[] = $res['email_issue'];
        } elseif ($res['outcome'] === 'failed') {
            $failedCount++;
            if (!empty($res['detail'])) $failedDetails[] = $res['detail'];
        } else {
            $skippedCount++;
            if (!empty($res['detail'])) $skippedDetails[] = $res['detail'];
        }
    }

    return [
        'counts'  => ['created' => $createdCount, 'skipped' => $skippedCount, 'failed' => $failedCount],
        'details' => ['skipped' => $skippedDetails, 'failed' => $failedDetails, 'created_with_email_issues' => $createdWithEmailIssues],
    ];
}

/* NEW: queued students (UPDATED: session queue keys, no students_import) waiting for automatic account creation in THIS
   page load (after a successful XLSX import, or a manual add whose
   account needs follow-up via the redirect below). Consumed by the
   "Creating Student Accounts" popup's script. Empty on every other load. */
$auto_create_account_import_ids = [];

/* NEW: imported rows whose email ALREADY belongs to an existing account.
   These are never sent for creation, but are still listed as Skipped in
   the popup so the admin sees every imported row accounted for. */
$auto_create_account_pre_skipped_details = [];

/* NEW: non-JS / redirect fallback for the manual Add Student flow. */
if (isset($_GET['auto_create_student_id'])) {
    $autoCreateFromRedirect = student_list_clean_pending_key($_GET['auto_create_student_id']); // UPDATED (no students_import)
    if ($autoCreateFromRedirect !== '') {
        $auto_create_account_import_ids[] = $autoCreateFromRedirect;
    }
}

// --- Handle Manual Student Addition ---
$manual_add_message = '';
$manual_add_message_type = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['add_student_manual'])) {
    $first_name   = trim($_POST['first_name']   ?? '');
    $middle_name  = trim($_POST['middle_name']  ?? '');
    $last_name    = trim($_POST['last_name']    ?? '');
    $course       = trim($_POST['course']       ?? '');
    $section      = trim($_POST['section']      ?? ''); // UPDATED (dropdown) — now required
    $major        = trim($_POST['major']        ?? ''); // UPDATED (dropdown) — now required; "none" = deliberate "no major"
    $email        = trim($_POST['email']        ?? '');
    $campus_branch = trim($_POST['campus_branch'] ?? '');

    // If "other" campus selected, use the custom campus text input
    if ($campus_branch === 'other' && isset($_POST['custom_campus']) && !empty(trim($_POST['custom_campus']))) {
        $campus_branch = trim($_POST['custom_campus']);
    }

    // If "other" course selected, use the custom course text input
    if ($course === 'other' && isset($_POST['custom_course']) && !empty(trim($_POST['custom_course']))) {
        $course = trim($_POST['custom_course']);
    }

    /* NEW (Major/Section dropdown): same "other" → custom text pattern as
       Course/Campus above, now applied to the new Major and Section
       dropdowns. */
    if ($major === 'other' && isset($_POST['custom_major']) && !empty(trim($_POST['custom_major']))) {
        $major = trim($_POST['custom_major']);
    }
    if ($section === 'other' && isset($_POST['custom_section']) && !empty(trim($_POST['custom_section']))) {
        $section = trim($_POST['custom_section']);
    }

    $errors = [];
    if (empty($first_name))    $errors[] = "First name is required.";
    if (empty($last_name))     $errors[] = "Last name is required.";
    if (empty($course))        $errors[] = "Course is required.";
    if ($major === '')         $errors[] = "Major is required."; // NEW (Major dropdown is required; pick \"None\" if there isn't one)
    if (empty($section))       $errors[] = "Section is required."; // UPDATED (Section dropdown is now required)
    if (empty($email))         $errors[] = "Email is required.";
    if (empty($campus_branch)) $errors[] = "Campus branch is required.";

    if (!empty($email) && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $errors[] = "Invalid email format.";
    }

    // NEW (Course Offering): the course must exist in Course Offering.
    if (!empty($course) && !student_list_course_is_offered($course_offering_map, $course)) {
        $errors[] = "The course \"" . htmlspecialchars($course ?? '') . "\" is not in Course Offering, so the student was not added. Add the course on the Course Offering page first.";
    }

    if (empty($errors)) {
        if (empty($middle_name)) $middle_name = null;
        // UPDATED (Section is now required): $section is guaranteed non-empty here
        // since the "Section is required." check above already blocked an empty
        // value — no more falling back to null.
        // NEW (Major dropdown): "None" is a deliberate, explicit choice — stored as no major, same as before.
        if ($major === 'none')   $major = null;

        /* UPDATED (no students_import): duplicates are checked against
           the real accounts in `users`, and the student is saved straight
           into users + student_information (no staging row). */
        $check_stmt = $conn->prepare("SELECT id FROM users WHERE email = ?");
        $check_stmt->bind_param("s", $email);
        $check_stmt->execute();
        $check_result = $check_stmt->get_result();

        if ($check_result->num_rows > 0) {
            $manual_add_message = "Error: A student with this email already exists!";
            $manual_add_message_type = "error";
        } else {
            $newlyAddedAccountResult = attempt_create_student_account_from_data($conn, [
                'first_name'    => $first_name,
                'middle_name'   => $middle_name,
                'last_name'     => $last_name,
                'course'        => $course,
                'major'         => $major,
                'section'       => $section,
                'email'         => $email,
                'campus_branch' => $campus_branch,
            ]);

            if ($newlyAddedAccountResult['outcome'] === 'created') {
                $manual_add_message = "Student added successfully!";
                $manual_add_message_type = "success";
                $redirectUrl = $_SERVER['PHP_SELF'] . "?added=success";
                $redirectUrl .= empty($newlyAddedAccountResult['email_issue'])
                    ? "&account_created=1"
                    : "&account_created=email_failed";
                header("Location: " . $redirectUrl);
                exit;
            } else {
                // Nothing was saved, so report why instead of a success message.
                $manual_add_message = "Error adding student: " . strip_tags((string)($newlyAddedAccountResult['detail'] ?? 'the account could not be created.'));
                $manual_add_message_type = "error";
            }
        }
        $check_stmt->close();
    } else {
        $manual_add_message = implode("<br>", $errors);
        $manual_add_message_type = "error";
    }
}

// --- Handle File Upload & Import ---
$import_message = '';
$import_message_type = '';
/* NEW (Campus Branch / Course import filter revision): short note shown
   after an import when rows were left out because their Campus Branch /
   Course was not selected in the import filter picker. */
$import_filter_skip_note = '';

/* ════════════════════════════════════════════════════════════════════
   NEW (Campus Branch / Course import filter revision — mirrors
   "detect_company_import_classifications" in admin_company_list.php):
   called via fetch() the moment a file is chosen, BEFORE the Course
   Offering pre-check and BEFORE the real import. It parses the uploaded
   XLSX read-only (nothing is written to the database here) and reports
   every distinct Campus Branch and Course actually found among the
   file's data rows (same column positions as the importer below). The
   page then shows the admin a checkbox picker built from this list — see
   "studentImportFilterModal" and its script — and only the rows whose
   Campus Branch AND Course are kept checked get imported (see the
   "import filter" check inside the importer below).
   ════════════════════════════════════════════════════════════════════ */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['detect_student_import_classifications']) && isset($_FILES['import_file'])) {
    header('Content-Type: application/json');
    $file        = $_FILES['import_file'];
    $fileExt     = strtolower(pathinfo($file['name'] ?? '', PATHINFO_EXTENSION));
    $maxFileSize = 100 * 1024 * 1024;

    if ($fileExt !== 'xlsx') {
        echo json_encode(['success' => false, 'message' => 'Only XLSX files are allowed.']);
        exit;
    }
    if (($file['size'] ?? 0) > $maxFileSize) {
        echo json_encode(['success' => false, 'message' => 'File size exceeds the maximum allowed limit of 100MB.']);
        exit;
    }
    if (($file['error'] ?? 1) !== 0) {
        echo json_encode(['success' => false, 'message' => 'Error uploading file. Please try again.']);
        exit;
    }

    try {
        require_once 'vendor/autoload.php';
        $reader = \PhpOffice\PhpSpreadsheet\IOFactory::createReaderForFile($file['tmp_name']);
        $reader->setReadDataOnly(true);
        $spreadsheet = $reader->load($file['tmp_name']);
        $detectRows  = $spreadsheet->getActiveSheet()->toArray();
        $spreadsheet->disconnectWorksheets();
        unset($spreadsheet);
        array_shift($detectRows); // header row

        $foundCampuses  = []; // normalized key => ['value', 'label', 'count']
        $foundCourses   = []; // normalized key => ['value', 'label', 'count', 'offered']
        $detectRowCount = 0;

        foreach ($detectRows as $row) {
            if (empty(array_filter($row))) continue;
            $first_name = trim($row[0] ?? '');
            $last_name  = trim($row[2] ?? '');
            $course     = trim($row[3] ?? '');
            $email      = trim($row[4] ?? '');
            $campus     = trim($row[5] ?? '');
            // Would be a "Missing required data" error row in the real import — not a choice to offer.
            if (empty($first_name) || empty($last_name) || empty($course) || empty($email) || empty($campus)) continue;

            $detectRowCount++;

            $campusKey = student_list_normalize_campus($campus);
            if (!isset($foundCampuses[$campusKey])) {
                $campusLabel = preg_replace('/\s+/', ' ', $campus);
                $foundCampuses[$campusKey] = ['value' => $campusLabel, 'label' => $campusLabel, 'count' => 0];
            }
            $foundCampuses[$campusKey]['count']++;

            $courseKey = student_list_normalize_course($course);
            if (!isset($foundCourses[$courseKey])) {
                $courseLabel = preg_replace('/\s+/', ' ', $course);
                $foundCourses[$courseKey] = [
                    'value'   => $courseLabel,
                    'label'   => $courseLabel,
                    'count'   => 0,
                    'offered' => student_list_course_is_offered($course_offering_map, $course),
                ];
            }
            $foundCourses[$courseKey]['count']++;
        }

        $foundCampuses = array_values($foundCampuses);
        $foundCourses  = array_values($foundCourses);
        usort($foundCampuses, function ($a, $b) { return strnatcasecmp($a['label'], $b['label']); });
        usort($foundCourses,  function ($a, $b) { return strnatcasecmp($a['label'], $b['label']); });

        echo json_encode([
            'success'   => true,
            'row_count' => $detectRowCount,
            'campuses'  => $foundCampuses,
            'courses'   => $foundCourses,
        ]);
    } catch (\Throwable $e) {
        echo json_encode(['success' => false, 'message' => 'Error reading file: ' . $e->getMessage()]);
    }
    exit;
}

/* ════════════════════════════════════════════════════════════════════
   NEW (Course Offering): import pre-check. Called via fetch() the
   moment a file is chosen, BEFORE the real import runs. It reads the
   file exactly like the importer below (same column positions) and
   returns every student whose course is not in Course Offering, so the
   page can ask the admin to skip those students or cancel the import.
   Nothing is written to the database here.
   ════════════════════════════════════════════════════════════════════ */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['check_import_courses']) && isset($_FILES['import_file'])) {
    header('Content-Type: application/json');
    $file = $_FILES['import_file'];
    $ext  = strtolower(pathinfo($file['name'] ?? '', PATHINFO_EXTENSION));
    if ($ext !== 'xlsx' || ($file['error'] ?? 1) !== 0) {
        echo json_encode(['success' => false]);
        exit;
    }
    try {
        require_once 'vendor/autoload.php';
        $reader = \PhpOffice\PhpSpreadsheet\IOFactory::createReaderForFile($file['tmp_name']);
        $reader->setReadDataOnly(true);
        $spreadsheet = $reader->load($file['tmp_name']);
        $rows = $spreadsheet->getActiveSheet()->toArray();
        $spreadsheet->disconnectWorksheets();
        unset($spreadsheet);
        array_shift($rows); // header row

        $unknown = [];
        $unknownCourses = [];
        $validCount = 0;
        // NEW (Campus Branch / Course import filter): rows the admin left out in the picker are not checked.
        [$checkFilterActive, $checkCampusSet, $checkCourseSet] = student_list_import_filter_from_post();
        foreach ($rows as $i => $row) {
            if (empty(array_filter($row))) continue;
            $course = trim($row[3] ?? '');
            if ($course === '') continue; // reported by the importer as missing data
            if ($checkFilterActive && !student_list_import_row_matches_filter($checkCampusSet, $checkCourseSet, trim($row[5] ?? ''), $course)) continue;
            if (student_list_course_is_offered($course_offering_map, $course)) { $validCount++; continue; }
            $name = preg_replace('/\s+/', ' ', trim(trim($row[0] ?? '') . ' ' . trim($row[1] ?? '') . ' ' . trim($row[2] ?? '')));
            $unknown[] = ['row' => $i + 2, 'name' => $name !== '' ? $name : '(no name)', 'course' => $course];
            $unknownCourses[student_list_normalize_course($course)] = $course;
        }
        echo json_encode([
            'success'         => true,
            'unknown'         => $unknown,
            'unknown_courses' => array_values($unknownCourses),
            'valid_count'     => $validCount,
            'offering_count'  => count($course_offering_map),
        ]);
    } catch (\Throwable $e) {
        echo json_encode(['success' => false]);
    }
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_FILES['import_file']) && !isset($_POST['check_import_courses']) && !isset($_POST['detect_student_import_classifications'])) {
    $file        = $_FILES['import_file'];
    $fileName    = $file['name'];
    $fileTmpName = $file['tmp_name'];
    $fileError   = $file['error'];
    $fileSize    = $file['size'];

    $fileExt     = strtolower(pathinfo($fileName, PATHINFO_EXTENSION));
    $maxFileSize = 100 * 1024 * 1024;

    if ($fileExt !== 'xlsx') {
        $import_message = "Error: Only XLSX files are allowed.";
        $import_message_type = "error";
    } elseif ($fileSize > $maxFileSize) {
        $import_message = "Error: File size exceeds the maximum allowed limit of 100MB. Your file is " . round($fileSize / (1024 * 1024), 2) . "MB.";
        $import_message_type = "error";
    } elseif ($fileError !== 0) {
        $import_message = "Error uploading file. Please try again.";
        $import_message_type = "error";
    } else {
        require 'vendor/autoload.php';
        try {
            $reader = \PhpOffice\PhpSpreadsheet\IOFactory::createReaderForFile($fileTmpName);
            $reader->setReadDataOnly(true);
            $spreadsheet = $reader->load($fileTmpName);
            $worksheet   = $spreadsheet->getActiveSheet();
            $rows        = $worksheet->toArray();
            $spreadsheet->disconnectWorksheets();
            unset($spreadsheet);

            $header        = array_shift($rows);
            $importedCount = 0;
            $errorRows     = [];
            $updatedCount  = 0;
            $autoCreateAccountStudentEmails = []; // NEW: rows to auto-create accounts for after commit
            /* UPDATED (no students_import): valid rows are collected here
               (one per email — a later row with the same email replaces the
               earlier one) and only queued for account creation once the
               whole file passed validation. Nothing is written to any
               staging table. */
            $pendingImportRows = [];
            // NEW (Course Offering): admin chose "Skip" in the course pre-check popup.
            $skipUnknownCourses = isset($_POST['skip_unknown_courses']) && $_POST['skip_unknown_courses'] === '1';
            $skippedUnknownCourseDetails = [];

            /* NEW (Campus Branch / Course import filter revision): the
               admin's selection from "studentImportFilterModal" arrives as
               selected_campus_branches[] / selected_courses[]. Only rows
               whose Campus Branch AND Course are both selected are
               imported; every other row is skipped (never an error, never
               causes a rollback). No selection in the POST = no filter. */
            [$importFilterActive, $importCampusSet, $importCourseSet] = student_list_import_filter_from_post();
            $skippedImportFilterRows = [];

            // UPDATED (no students_import): no transaction needed — nothing is written while reading the file.
            $chunkSize   = 1000;
            $chunkedRows = array_chunk($rows, $chunkSize);

            foreach ($chunkedRows as $chunkIndex => $rowChunk) {
                foreach ($rowChunk as $index => $row) {
                    if (empty(array_filter($row))) continue;

                    $first_name  = trim($row[0] ?? '');
                    $middle_name = trim($row[1] ?? '');
                    $last_name   = trim($row[2] ?? '');
                    $course      = trim($row[3] ?? '');
                    $email       = trim($row[4] ?? '');
                    $campus      = trim($row[5] ?? '');
                    $section     = trim($row[6] ?? ''); // optional — existing 6-column templates still import fine
                    $major       = trim($row[7] ?? ''); // NEW (Major): optional 8th column — older templates still import fine

                    $globalRowNumber = ($chunkIndex * $chunkSize) + $index + 2;

                    if (empty($first_name) || empty($last_name) || empty($course) || empty($email) || empty($campus)) {
                        $errorRows[] = "Row " . $globalRowNumber . ": Missing required data (First Name, Last Name, Course, Email, Campus Branch are required).";
                        continue;
                    }

                    /* NEW (Campus Branch / Course import filter): skip rows
                       the admin did not select — checked before the email /
                       Course Offering checks so a row that was left out can
                       never fail or roll back the import. */
                    if ($importFilterActive && !student_list_import_row_matches_filter($importCampusSet, $importCourseSet, $campus, $course)) {
                        $skippedImportFilterRows[] = "Row " . $globalRowNumber . ": " . htmlspecialchars($campus ?? '') . " / " . htmlspecialchars($course ?? '') . " was not selected in the import filter — skipped.";
                        continue;
                    }

                    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
                        $errorRows[] = "Row " . $globalRowNumber . ": Invalid email format.";
                        continue;
                    }

                    // NEW (Course Offering): course must exist in Course Offering.
                    if (!student_list_course_is_offered($course_offering_map, $course)) {
                        if ($skipUnknownCourses) {
                            $skippedUnknownCourseDetails[] = htmlspecialchars(preg_replace('/\s+/', ' ', trim($first_name . ' ' . $middle_name . ' ' . $last_name)))
                                . ": the course \"" . htmlspecialchars($course ?? '') . "\" is not in Course Offering, so this student was not imported.";
                        } else {
                            $errorRows[] = "Row " . $globalRowNumber . ": Course \"" . $course . "\" is not in Course Offering.";
                        }
                        continue;
                    }

                    if (empty($middle_name)) $middle_name = null;
                    if (empty($section))     $section = null;
                    if ($major === '')       $major = null; // NEW (Major)

                    // UPDATED (no students_import): keep the row in memory only.
                    $pendingEmailKey = mb_strtolower($email);
                    if (isset($pendingImportRows[$pendingEmailKey])) $updatedCount++;
                    else $importedCount++;
                    $pendingImportRows[$pendingEmailKey] = [
                        'first_name'    => $first_name,
                        'middle_name'   => $middle_name,
                        'last_name'     => $last_name,
                        'course'        => $course,
                        'major'         => $major,
                        'section'       => $section,
                        'email'         => $email,
                        'campus_branch' => $campus,
                    ];
                    $autoCreateAccountStudentEmails[] = $email; // NEW
                }
            }

            if (empty($errorRows)) {
                $import_message = "Successfully imported $importedCount new records and updated $updatedCount existing records.";
                $import_message_type = "success";

                // NEW (Campus Branch / Course import filter): report how many rows were left out by the picker.
                if (!empty($skippedImportFilterRows)) {
                    $import_filter_skip_note = count($skippedImportFilterRows) . " row(s) were skipped because their Campus Branch / Course didn't match what you chose to import.";
                    $import_message .= ' ' . $import_filter_skip_note;
                }

                // NEW (Course Offering): list the skipped students in the "Creating Student Accounts" popup.
                if (!empty($skippedUnknownCourseDetails)) {
                    $auto_create_account_pre_skipped_details = array_merge($auto_create_account_pre_skipped_details, $skippedUnknownCourseDetails);
                }

                /* ════════════════════════════════════════════════════
                   NEW (Student account auto-creation): queue every valid
                   row for the "Creating Student Accounts" popup, which
                   creates each login account (users +
                   student_information) and emails the password right
                   after the page loads. Rows whose email already has an
                   account are not re-sent — they are listed directly as
                   Skipped in the same popup instead.
                   UPDATED (no students_import): rows are queued in the
                   admin's session (student_list_queue_pending_account()),
                   never in a students_import table.
                   ════════════════════════════════════════════════════ */
                if (!empty($pendingImportRows)) {
                    $existingAccountEmails = [];
                    $pendingEmailsList = array_values(array_map(function ($r) { return $r['email']; }, $pendingImportRows));
                    foreach (array_chunk($pendingEmailsList, 500) as $emailChunk) {
                        $emailPlaceholders = implode(',', array_fill(0, count($emailChunk), '?'));
                        $emailTypes = str_repeat('s', count($emailChunk));
                        $idLookupStmt = $conn->prepare("SELECT email FROM users WHERE email IN ($emailPlaceholders)");
                        $idLookupStmt->bind_param($emailTypes, ...$emailChunk);
                        $idLookupStmt->execute();
                        $idLookupRes = $idLookupStmt->get_result();
                        while ($idLookupRow = $idLookupRes->fetch_assoc()) {
                            $existingAccountEmails[mb_strtolower(trim((string)$idLookupRow['email']))] = true;
                        }
                        $idLookupStmt->close();
                    }

                    foreach ($pendingImportRows as $pendingEmailKey => $pendingRow) {
                        if (isset($existingAccountEmails[$pendingEmailKey])) {
                            $skipName = preg_replace('/\s+/', ' ', trim(
                                ($pendingRow['first_name'] ?? '') . ' ' . ($pendingRow['middle_name'] ?? '') . ' ' . ($pendingRow['last_name'] ?? '')
                            ));
                            $auto_create_account_pre_skipped_details[] = htmlspecialchars($skipName ?? '') . ": this email (" . htmlspecialchars($pendingRow['email'] ?? '') . ") already has an existing account, so a new one was not created.";
                        } else {
                            $auto_create_account_import_ids[] = student_list_queue_pending_account($pendingRow);
                        }
                    }
                }
            } else {
                // UPDATED (no students_import): nothing was written, so there is nothing to roll back.
                $import_message = "Import failed with errors:<br>" . implode("<br>", array_slice($errorRows, 0, 10));
                if (count($errorRows) > 10) $import_message .= "<br>...and " . (count($errorRows) - 10) . " more errors.";
                $import_message_type = "error";
            }
        } catch (Exception $e) {
            $import_message = "Error processing file: " . $e->getMessage();
            $import_message_type = "error";
        }
    }
}

/* ════════════════════════════════════════════════════════════════════
   NEW: Toolbar "Delete" action — replaces the old "Delete All Records"
   (truncate) button entirely. Unlike truncate, this only ever removes
   the specific rows the admin has checked in the table via the
   checkbox-driven selection flow, mirroring the equivalent handler in
   admin_company_list.php. Always responds with JSON since it is only
   ever triggered via fetch().
   ════════════════════════════════════════════════════════════════════ */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_selected_students'])) {
    header('Content-Type: application/json');

    /* UPDATED (Cross-table student list): each selected row key is a
       student account ('u<id>'), deleted with all its records (see
       student_list_delete_student_account()).
       UPDATED (no students_import): there are no imported entries to
       delete from students_import anymore. */
    [$selected_user_ids, $selected_ids] = student_list_parse_row_keys(
        (isset($_POST['student_ids']) && is_array($_POST['student_ids'])) ? $_POST['student_ids'] : []
    );

    if (empty($selected_ids) && empty($selected_user_ids)) {
        echo json_encode(['success' => false, 'message' => 'No entries were selected for deletion.']);
        exit;
    }

    $deletedCount = 0;
    $deleteErrors = [];

    if (!empty($selected_user_ids)) {
        $performerName = trim(($_SESSION['first_name'] ?? '') . ' ' . ($_SESSION['last_name'] ?? ''));
        if ($performerName === '') $performerName = 'Administrator';
        foreach ($selected_user_ids as $delUserId) {
            $delRes = student_list_delete_student_account($conn, $delUserId, $performerName);
            if ($delRes['success']) $deletedCount++;
            else $deleteErrors[] = $delRes['message'];
        }
    }

    if ($deletedCount > 0) {
        $msg = "Successfully deleted " . $deletedCount . " selected student" . ($deletedCount === 1 ? "" : "s") . ".";
        if (!empty($deleteErrors)) $msg .= " Some could not be deleted: " . strip_tags(implode(' ', $deleteErrors));
        echo json_encode(['success' => true, 'message' => $msg]);
    } else {
        echo json_encode([
            'success' => false,
            'message' => !empty($deleteErrors)
                ? strip_tags(implode(' ', $deleteErrors))
                : "The selected entries could not be found (they may have already been removed).",
        ]);
    }
    exit;
}

/* ════════════════════════════════════════════════════════════════════
   NEW: Toolbar "Edit" action — lets an admin correct the details of a
   single imported/manually-added student (first/middle/last name,
   course, email, campus branch), selected via the same single-select
   checkbox flow used by admin_company_list.php's "Edit" button. Always
   responds with JSON since it is only ever triggered via fetch().
   ════════════════════════════════════════════════════════════════════ */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['edit_student'])) {
    header('Content-Type: application/json');

    /* UPDATED (Cross-table student list): the row key ('u<id>') tells
       which student account is being edited.
       UPDATED (no students_import): only accounts can be edited now —
       the old students_import entry editing was removed. */
    [$edit_user_ids, $edit_import_ids] = student_list_parse_row_keys([$_POST['edit_student_id'] ?? '']);

    if (!empty($edit_user_ids)) {
        $edit_user_id = $edit_user_ids[0];

        $accStmt = $conn->prepare("SELECT id, email FROM users WHERE id = ? AND role = 'student'");
        $accStmt->bind_param("i", $edit_user_id);
        $accStmt->execute();
        $accRow = $accStmt->get_result()->fetch_assoc();
        $accStmt->close();
        if (!$accRow) {
            echo json_encode(['success' => false, 'message' => 'This student could not be found (it may have already been deleted).']);
            exit;
        }
        $oldEmail = (string)$accRow['email'];

        $first_name    = trim($_POST['edit_first_name'] ?? '');
        $middle_name   = trim($_POST['edit_middle_name'] ?? '');
        $last_name     = trim($_POST['edit_last_name'] ?? '');
        $course        = trim($_POST['edit_course'] ?? '');
        $section       = trim($_POST['edit_section'] ?? ''); // UPDATED (dropdown) — now required
        $major         = trim($_POST['edit_major'] ?? ''); // UPDATED (dropdown) — now required; "none" = deliberate "no major"
        $email         = trim($_POST['edit_email'] ?? '');
        $campus_branch = trim($_POST['edit_campus_branch'] ?? '');
        if ($campus_branch === 'other' && isset($_POST['edit_custom_campus']) && trim($_POST['edit_custom_campus']) !== '') {
            $campus_branch = trim($_POST['edit_custom_campus']);
        }
        if ($course === 'other' && isset($_POST['edit_custom_course']) && trim($_POST['edit_custom_course']) !== '') {
            $course = trim($_POST['edit_custom_course']);
        }
        /* NEW (Major/Section dropdown): same "other" → custom text pattern as
           Course/Campus above, now applied to the Major and Section dropdowns. */
        if ($major === 'other' && isset($_POST['edit_custom_major']) && trim($_POST['edit_custom_major']) !== '') {
            $major = trim($_POST['edit_custom_major']);
        }
        if ($section === 'other' && isset($_POST['edit_custom_section']) && trim($_POST['edit_custom_section']) !== '') {
            $section = trim($_POST['edit_custom_section']);
        }

        $errors = [];
        if (empty($first_name))    $errors[] = "First name is required.";
        if (empty($last_name))     $errors[] = "Last name is required.";
        if (empty($course))        $errors[] = "Course is required.";
        if ($major === '')         $errors[] = "Major is required."; // NEW (Major dropdown is required; pick \"None\" if there isn't one)
        if (empty($section))       $errors[] = "Section is required."; // UPDATED (Section dropdown is now required)
        if (empty($email)) {
            $errors[] = "Email is required.";
        } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $errors[] = "Invalid email format.";
        }
        if (empty($campus_branch)) $errors[] = "Campus branch is required.";

        if (empty($errors) && strcasecmp($email, $oldEmail) !== 0) {
            $dupU = $conn->prepare("SELECT id FROM users WHERE email = ? AND id != ?");
            $dupU->bind_param("si", $email, $edit_user_id);
            $dupU->execute();
            if ($dupU->get_result()->num_rows > 0) $errors[] = "Another account already uses this email.";
            $dupU->close();
        }

        if (!empty($errors)) {
            echo json_encode(['success' => false, 'message' => implode("<br>", $errors)]);
            exit;
        }

        $middle_name = ($middle_name === '') ? null : $middle_name;
        // UPDATED (Section is now required): $section is guaranteed non-empty here
        // since the "Section is required." check above already blocked an empty
        // value — no more falling back to null.
        // NEW (Major dropdown): "None" is a deliberate, explicit choice — stored as no major, same as before.
        $major       = ($major === 'none') ? null : $major;

        $conn->begin_transaction();
        try {
            // users: names + email
            $uCols = student_list_table_columns($conn, 'users');
            $middleForUsers = ($middle_name === null && isset($uCols['middle_name']) && strtoupper($uCols['middle_name']['Null']) === 'NO') ? '' : $middle_name;
            // UPDATED (Course in users): course is saved in `users` too.
            $upU = $conn->prepare("UPDATE users SET first_name = ?, middle_name = ?, last_name = ?, email = ?, course = ? WHERE id = ? AND role = 'student'");
            $upU->bind_param("sssssi", $first_name, $middleForUsers, $last_name, $email, $course, $edit_user_id);
            if (!$upU->execute()) throw new \RuntimeException($upU->error);
            $upU->close();

            // student_information: other data (only columns that exist)
            $siCols = student_list_table_columns($conn, 'student_information');
            if (isset($siCols['user_id'])) {
                $siData = [
                    // UPDATED (Course in users): course is no longer written here.
                    'section' => $section, 'year_section' => $section,
                    'major' => $major, // NEW (Major)
                    'campus_branch' => $campus_branch, 'campus' => $campus_branch, 'branch' => $campus_branch,
                    'email' => $email,
                ];
                $haveSi = $conn->prepare("SELECT COUNT(*) FROM student_information WHERE user_id = ?");
                $haveSi->bind_param("i", $edit_user_id);
                $haveSi->execute();
                $haveSi->bind_result($siCount);
                $haveSi->fetch();
                $haveSi->close();

                if ((int)$siCount > 0) {
                    $sets = []; $vals = []; $types = '';
                    foreach ($siData as $col => $val) {
                        if (!isset($siCols[$col])) continue;
                        if ($val === null && strtoupper($siCols[$col]['Null']) === 'NO') $val = '';
                        $sets[] = "`$col` = ?"; $vals[] = $val; $types .= 's';
                    }
                    if (!empty($sets)) {
                        $vals[] = $edit_user_id; $types .= 'i';
                        $upS = $conn->prepare("UPDATE student_information SET " . implode(', ', $sets) . " WHERE user_id = ?");
                        $upS->bind_param($types, ...$vals);
                        if (!$upS->execute()) throw new \RuntimeException($upS->error);
                        $upS->close();
                    }
                } else {
                    student_list_insert_row($conn, 'student_information', ['user_id' => $edit_user_id] + $siData);
                }
            }

            $conn->commit();
            echo json_encode(['success' => true, 'message' => 'Student updated successfully!']);
        } catch (\Throwable $e) {
            try { $conn->rollback(); } catch (\Throwable $ignored) {}
            echo json_encode(['success' => false, 'message' => 'Error updating student: ' . htmlspecialchars($e->getMessage())]);
        }
        exit;
    }

    // UPDATED (no students_import): anything that isn't a student account can't be edited.
    echo json_encode(['success' => false, 'message' => 'Invalid student reference.']);
    exit;
}

/* ════════════════════════════════════════════════════════════════════
   NEW (Student account auto-creation): endpoint called by the
   "Creating Student Accounts" popup (runAutoAccountCreation() below)
   with the queued student keys (UPDATED: session queue, no
   students_import). Always responds with JSON: a
   created / skipped / failed breakdown, as counts and per-student
   details — same contract as admin_company_list.php's
   create_accounts_selected endpoint.
   ════════════════════════════════════════════════════════════════════ */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['create_student_accounts_selected'])) {
    header('Content-Type: application/json');
    @set_time_limit(0);

    $selected_import_ids = [];
    if (isset($_POST['import_ids']) && is_array($_POST['import_ids'])) {
        foreach ($_POST['import_ids'] as $rawId) {
            $pendingKey = student_list_clean_pending_key($rawId); // UPDATED (no students_import)
            if ($pendingKey !== '') $selected_import_ids[] = $pendingKey;
        }
    }
    $selected_import_ids = array_values(array_unique($selected_import_ids));

    if (empty($selected_import_ids)) {
        echo json_encode([
            'success' => false,
            'message' => 'No entries were selected for account creation.',
            'counts'  => ['created' => 0, 'skipped' => 0, 'failed' => 0],
            'details' => ['skipped' => [], 'failed' => [], 'created_with_email_issues' => []],
        ]);
        exit;
    }

    $batch = run_student_account_creation_batch($conn, $selected_import_ids);
    echo json_encode([
        'success' => $batch['counts']['created'] > 0,
        'message' => 'Account creation finished.',
        'counts'  => $batch['counts'],
        'details' => $batch['details'],
    ]);
    exit;
}

// --- Get filter values from URL ---
$search_term   = isset($_GET['search'])        ? trim($_GET['search'])        : '';
$campus_filter = isset($_GET['campus_branch']) ? trim($_GET['campus_branch']) : '';
$course_filter = isset($_GET['course'])        ? trim($_GET['course'])        : '';

// --- Build WHERE clause ---
$where_clauses = [];
$params        = [];
$param_types   = '';

if (!empty($search_term)) {
    $where_clauses[] = "(si.first_name LIKE ? OR si.last_name LIKE ? OR si.email LIKE ?)";
    $search_param    = "%$search_term%";
    $params[]        = $search_param;
    $params[]        = $search_param;
    $params[]        = $search_param;
    $param_types    .= 'sss';
}
if (!empty($campus_filter)) {
    $where_clauses[] = "si.campus_branch = ?";
    $params[]        = $campus_filter;
    $param_types    .= 's';
}
if (!empty($course_filter)) {
    $where_clauses[] = "si.course = ?";
    $params[]        = $course_filter;
    $param_types    .= 's';
}
$where_sql = !empty($where_clauses) ? "WHERE " . implode(" AND ", $where_clauses) : '';

// --- Distinct campus branches and courses for filter dropdowns ---
$campus_result = $conn->query("SELECT DISTINCT campus_branch FROM (" . student_list_source_sql($conn) . ") si_c WHERE campus_branch IS NOT NULL AND campus_branch != '' ORDER BY campus_branch"); // UPDATED (cross-table list)
$campuses = [];
while ($row = $campus_result->fetch_assoc()) { $campuses[] = $row['campus_branch']; }

$course_result = $conn->query("SELECT DISTINCT course FROM (" . student_list_source_sql($conn) . ") si_c WHERE course IS NOT NULL AND course != '' ORDER BY course"); // UPDATED (cross-table list)
$courses = [];
while ($row = $course_result->fetch_assoc()) { $courses[] = $row['course']; }

// --- Pagination ---
$records_per_page = 10;
$page = isset($_GET['page']) ? (int)$_GET['page'] : 1;
if ($page < 1) $page = 1;

$base_join = "
    FROM (" . student_list_source_sql($conn) . ") si
    LEFT JOIN users u ON u.id = si.user_id
    LEFT JOIN ojt_assignments oa ON oa.student_id = u.id
    LEFT JOIN company_information ci ON ci.user_id = oa.company_id
    LEFT JOIN (
        SELECT
            user_id,
            /* UPDATED (Start = first attendance): first day with a real time-in/out */
            MIN(CASE WHEN ((am_time_in  IS NOT NULL AND am_time_in  != '' AND am_time_in  != 'missed') OR (am_time_out IS NOT NULL AND am_time_out != '' AND am_time_out != 'missed') OR (pm_time_in  IS NOT NULL AND pm_time_in  != '' AND pm_time_in  != 'missed') OR (pm_time_out IS NOT NULL AND pm_time_out != '' AND pm_time_out != 'missed')) THEN date END) AS date_start,
            MAX(date) AS date_end,
            /* UPDATED (accurate hours): exact seconds per session, datetime-aware,
               negative spans ignored and NULL-safe so one bad value never voids a day */
            ROUND(
                SUM(
                    GREATEST(0, COALESCE(CASE
                        WHEN am_time_in  IS NOT NULL AND am_time_in  != '' AND am_time_in  != 'missed'
                         AND am_time_out IS NOT NULL AND am_time_out != '' AND am_time_out != 'missed'
                        THEN CASE
                            WHEN am_time_in LIKE '%-%-% %' AND am_time_out LIKE '%-%-% %'
                            THEN TIMESTAMPDIFF(SECOND, am_time_in, am_time_out)
                            ELSE (TIME_TO_SEC(TIME(am_time_out)) - TIME_TO_SEC(TIME(am_time_in)))
                        END
                        ELSE 0
                    END, 0))
                    +
                    GREATEST(0, COALESCE(CASE
                        WHEN pm_time_in  IS NOT NULL AND pm_time_in  != '' AND pm_time_in  != 'missed'
                         AND pm_time_out IS NOT NULL AND pm_time_out != '' AND pm_time_out != 'missed'
                        THEN CASE
                            WHEN pm_time_in LIKE '%-%-% %' AND pm_time_out LIKE '%-%-% %'
                            THEN TIMESTAMPDIFF(SECOND, pm_time_in, pm_time_out)
                            ELSE (TIME_TO_SEC(TIME(pm_time_out)) - TIME_TO_SEC(TIME(pm_time_in)))
                        END
                        ELSE 0
                    END, 0))
                ) / 3600
            , 2) AS total_hours,
            COALESCE(
                SUM(
                    GREATEST(0, COALESCE(CASE
                        WHEN am_time_in  IS NOT NULL AND am_time_in  != '' AND am_time_in  != 'missed'
                         AND am_time_out IS NOT NULL AND am_time_out != '' AND am_time_out != 'missed'
                        THEN CASE
                            WHEN am_time_in LIKE '%-%-% %' AND am_time_out LIKE '%-%-% %'
                            THEN TIMESTAMPDIFF(SECOND, am_time_in, am_time_out)
                            ELSE (TIME_TO_SEC(TIME(am_time_out)) - TIME_TO_SEC(TIME(am_time_in)))
                        END
                        ELSE 0
                    END, 0))
                    +
                    GREATEST(0, COALESCE(CASE
                        WHEN pm_time_in  IS NOT NULL AND pm_time_in  != '' AND pm_time_in  != 'missed'
                         AND pm_time_out IS NOT NULL AND pm_time_out != '' AND pm_time_out != 'missed'
                        THEN CASE
                            WHEN pm_time_in LIKE '%-%-% %' AND pm_time_out LIKE '%-%-% %'
                            THEN TIMESTAMPDIFF(SECOND, pm_time_in, pm_time_out)
                            ELSE (TIME_TO_SEC(TIME(pm_time_out)) - TIME_TO_SEC(TIME(pm_time_in)))
                        END
                        ELSE 0
                    END, 0))
                )
            , 0) AS total_seconds
        FROM attendance_logs
        GROUP BY user_id
    ) att ON att.user_id = u.id
";

$count_sql  = "SELECT COUNT(*) as total " . $base_join . " " . $where_sql;
$count_stmt = $conn->prepare($count_sql);
if (!empty($params)) { $count_stmt->bind_param($param_types, ...$params); }
$count_stmt->execute();
$total_students = $count_stmt->get_result()->fetch_assoc()['total'];
$total_pages    = ceil($total_students / $records_per_page);

// Total rows in the table the export reads from (export ignores filters) —
// used by the Export button to cancel when there is nothing to export.
$export_total_res  = $conn->query("SELECT COUNT(*) AS total FROM (" . student_list_source_sql($conn) . ") si_count"); // UPDATED (cross-table list)
$export_total_rows = $export_total_res ? (int)$export_total_res->fetch_assoc()['total'] : 0;

if ($page > $total_pages && $total_pages > 0) $page = $total_pages;
$offset = ($page - 1) * $records_per_page;

$sql = "
    SELECT si.*, ci.company AS ojt_company,
           att.date_start AS ojt_date_start, att.date_end AS ojt_date_end, att.total_hours AS ojt_total_hours,
           att.total_seconds AS ojt_total_seconds,
           u.id AS ojt_user_id
    " . $base_join . " " . $where_sql . "
    ORDER BY si.created_at DESC
    LIMIT ? OFFSET ?
";
$stmt = $conn->prepare($sql);
$limit_offset_params = array_merge($params, [$records_per_page, $offset]);
$limit_offset_types  = $param_types . 'ii';
if (!empty($limit_offset_params)) { $stmt->bind_param($limit_offset_types, ...$limit_offset_params); }
$stmt->execute();
$students_result = $stmt->get_result();
$list_course_rules = student_list_course_rules($conn); // UPDATED (OJT Date End <-> Est. Duty Days)

$stmt_all_ug = $conn->prepare("
    SELECT COUNT(*) as total
    FROM reports r
    JOIN ojt_assignments oa ON oa.student_id = r.user_id AND oa.company_id = r.company_id
    WHERE r.week_start <= CURDATE()
      AND (r.remark IS NULL OR r.remark != 'Wrong Document')
      AND r.faculty_grade IS NULL
");
$stmt_all_ug->execute();
$all_ungraded_count = (int)($stmt_all_ug->get_result()->fetch_assoc()['total'] ?? 0);
$stmt_all_ug->close();

// ── "Company Requirements" sidebar indicator count.
// FIX (sidebar notification indicator): now uses the same notification
// rule as company_validation.php / admin_company_list.php (see
// student_list_company_validation_notif_count() above) instead of the old
// moa_requests status='Pending' count, which was almost always out of sync. ──
$moa_pending_count = student_list_company_validation_notif_count($conn);

// ── full name shown at the top of the sidebar is built from
// first_name + middle_name + last_name, looked up directly from the
// `admin` table (not `users`) using the logged-in admin's id, since the
// admin's identity record lives there. Session values are kept only as a
// fallback if the DB lookup comes back empty, and a generic label is used
// as a last resort. The role label underneath remains "Administrator". ──
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

$pageTitle = "Student Import";

function build_url($params = []) {
    $current_params = $_GET;
    foreach ($params as $key => $value) {
        if ($value === '') unset($current_params[$key]);
        else $current_params[$key] = $value;
    }
    return '?' . http_build_query($current_params);
}

function getCampusOptions($conn) {
    $campuses = [];
    $result = $conn->query("SELECT DISTINCT campus_branch FROM (" . student_list_source_sql($conn) . ") si_c ORDER BY campus_branch"); // UPDATED (cross-table list)
    while ($row = $result->fetch_assoc()) {
        if (!empty($row['campus_branch'])) $campuses[] = $row['campus_branch'];
    }
    return $campuses;
}

// ── helper that returns distinct courses from the Student List (users + student_information) ──
function getCourseOptions($conn) {
    $list = [];
    $result = $conn->query("SELECT DISTINCT course FROM (" . student_list_source_sql($conn) . ") si_c ORDER BY course"); // UPDATED (cross-table list)
    while ($row = $result->fetch_assoc()) {
        if (!empty($row['course'])) $list[] = $row['course'];
    }
    return $list;
}

// ── NEW (Major/Section dropdown): same DISTINCT-from-Student-List pattern as
//    getCampusOptions()/getCourseOptions() above, so the Add Student dropdowns
//    offer every major/section already on file, in addition to "None" / "other".
function getMajorOptions($conn) {
    $list = [];
    $result = $conn->query("SELECT DISTINCT major FROM (" . student_list_source_sql($conn) . ") si_m WHERE major IS NOT NULL AND major != '' ORDER BY major");
    while ($row = $result->fetch_assoc()) {
        if (!empty($row['major'])) $list[] = $row['major'];
    }
    return $list;
}

function getSectionOptions($conn) {
    $list = [];
    $result = $conn->query("SELECT DISTINCT section FROM (" . student_list_source_sql($conn) . ") si_s WHERE section IS NOT NULL AND section != '' ORDER BY section");
    while ($row = $result->fetch_assoc()) {
        if (!empty($row['section'])) $list[] = $row['section'];
    }
    return $list;
}

$all_campus_options = getCampusOptions($conn);
$all_course_options = getCourseOptions($conn);   // ← used in Add-Student / Edit-Student modals
$all_major_options   = getMajorOptions($conn);   // ← used in Add-Student modal
$all_section_options = getSectionOptions($conn); // ← used in Add-Student modal

/* ════════════════════════════════════════════════════════════════════
   NEW (adopted from admin_company_list.php): the entire student table
   section (table / empty-state + pagination + pagination-info) is
   rendered once into a string via output buffering. This same string
   is used both for the normal full-page render AND for the AJAX
   partial-refresh requests fired by the search box, the Campus/Course
   filters, and pagination clicks — so there is exactly one place that
   generates this markup and the no-reload filtering behavior can never
   drift out of sync with a full page load.

   This replaces the previous approach (which fetched and parsed the
   ENTIRE HTML page client-side via DOMParser on every keystroke/filter
   change) with the same lightweight JSON-fragment approach already
   used on admin_company_list.php: if this request is the JS-driven
   search/filter/pagination call (identified by ?ajax_table=1), only
   this small fragment — not the full page markup, styles, and scripts
   — is returned, and the request exits immediately below without
   rendering the rest of the page at all.

   NEW (empty-table detection): the check below uses a loose (==)
   comparison against 0, exactly like admin_company_list.php's
   `if ($total_companies == 0)`, instead of a strict (===) comparison.
   COUNT(*) can come back from the DB driver as a numeric string
   ("0"), which a strict === 0 check would never match — silently
   skipping the "No Students Found" empty state and falling into the
   table-render branch with zero rows instead.
   ════════════════════════════════════════════════════════════════════ */
ob_start();
if ($total_students == 0) {
?>
            <div class="empty-state">
                <i class="fas fa-users"></i>
                <h3>No Students Found</h3>
                <p>Try adjusting your filters, or check back once students are imported.</p>
            </div>
<?php
} else {
?>

            <!-- ════════════════════════════════════════════════════════
                 Scrollable table wrapper — bottom scrollbar appears when
                 the viewport is narrower than min-width: 1170px
                 ════════════════════════════════════════════════════════ -->
            <div class="table-scroll-wrapper">
                <table class="student-table">
                    <thead>
                        <tr>
                            <th class="checkbox-cell"><input type="checkbox" id="selectAllCheckbox" title="Select all entries on this page"></th>
                            <th><i class="fas fa-user"            style="margin-right:5px;"></i>Full Name</th>
                            <th><i class="fas fa-graduation-cap"  style="margin-right:5px;"></i>Course</th>
                            <th><i class="fas fa-book-open"       style="margin-right:5px;"></i>Major</th>
                            <th><i class="fas fa-layer-group"     style="margin-right:5px;"></i>Section</th>
                            <th><i class="fas fa-envelope"        style="margin-right:5px;"></i>Email</th>
                            <th><i class="fas fa-university"      style="margin-right:5px;"></i>Campus Branch</th>
                            <th><i class="fas fa-building"        style="margin-right:5px;"></i>Company</th>
                            <th><i class="fas fa-calendar-check"  style="margin-right:5px;"></i>Date of Start</th>
                            <th><i class="fas fa-calendar-times"  style="margin-right:5px;"></i>Date of End</th>
                            <th><i class="fas fa-clock"           style="margin-right:5px;"></i>Total Hours</th>
                            <th><i class="fas fa-hourglass-half"  style="margin-right:5px;"></i>Remaining Hours</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php while ($row = $students_result->fetch_assoc()): ?>
                        <tr>
                            <td class="checkbox-cell">
                                <input type="checkbox" class="row-select-checkbox" value="<?= htmlspecialchars($row['row_key'] ?? '', ENT_QUOTES) ?>"
                                    data-id="<?= htmlspecialchars($row['row_key'] ?? '', ENT_QUOTES) ?>"
                                    data-source="<?= htmlspecialchars($row['source'] ?? '', ENT_QUOTES) ?>"
                                    data-first="<?= htmlspecialchars($row['first_name'] ?? '', ENT_QUOTES) ?>"
                                    data-middle="<?= htmlspecialchars($row['middle_name'] ?? '', ENT_QUOTES) ?>"
                                    data-last="<?= htmlspecialchars($row['last_name'] ?? '', ENT_QUOTES) ?>"
                                    data-course="<?= htmlspecialchars($row['course'] ?? '', ENT_QUOTES) ?>"
                                    data-section="<?= htmlspecialchars($row['section'] ?? '', ENT_QUOTES) ?>"
                                    data-major="<?= htmlspecialchars($row['major'] ?? '', ENT_QUOTES) ?>"
                                    data-email="<?= htmlspecialchars($row['email'] ?? '', ENT_QUOTES) ?>"
                                    data-campus="<?= htmlspecialchars($row['campus_branch'] ?? '', ENT_QUOTES) ?>">
                            </td>
                            <td>
                                <strong><?= htmlspecialchars(trim(
                                    $row['first_name'] .
                                    ($row['middle_name'] ? ' ' . $row['middle_name'] . ' ' : ' ') .
                                    $row['last_name']
                                )) ?></strong>
                                <?php if (($row['source'] ?? '') === 'imported'): ?>
                                    <div style="font-size:10px; color:var(--grid-muted); text-transform:uppercase; letter-spacing:0.3px; margin-top:2px;">Not Registered</div>
                                <?php endif; ?>
                            </td>
                            <td><?= htmlspecialchars($row['course'] ?? '') ?></td>
                            <td><?= !empty($row['major']) ? htmlspecialchars($row['major'] ?? '') : '<span style="color:#9aa2b1; font-style:italic;">—</span>' ?></td>
                            <td><?= !empty($row['section']) ? htmlspecialchars($row['section'] ?? '') : '<span style="color:#9aa2b1; font-style:italic;">—</span>' ?></td>
                            <td style="font-size:12px; color:#475569;"><?= htmlspecialchars($row['email'] ?? '') ?></td>
                            <td><?= htmlspecialchars($row['campus_branch'] ?? '') ?></td>
                            <td>
                                <?php if (!empty($row['ojt_company'])): ?>
                                    <span class="company-pill">
                                        <i class="fas fa-building" style="font-size:9px;"></i>
                                        <?= htmlspecialchars($row['ojt_company'] ?? '') ?>
                                    </span>
                                <?php else: ?>
                                    <span class="company-pill unassigned">
                                        <i class="fas fa-minus" style="font-size:9px;"></i> Unassigned
                                    </span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <?php if (!empty($row['ojt_date_start'])): ?>
                                    <span class="date-cell">
                                        <i class="fas fa-play-circle" style="color:#2C5A2C; font-size:10px; margin-right:3px;"></i>
                                        <?= date('M d, Y', strtotime($row['ojt_date_start'] ?? '')) ?>
                                    </span>
                                <?php else: ?>
                                    <span class="date-cell no-data">—</span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <?php
                                // UPDATED (OJT Date End <-> Est. Duty Days)
                                $endInfo = student_list_ojt_end_info($conn, $list_course_rules, $row);
                                $endTip  = '';
                                if ($endInfo['rule']) {
                                    $endTip = 'Est. Duty Days: ' . $endInfo['est_days'] . ' (Mon–Fri) · '
                                            . student_list_format_hours($endInfo['rule']['total'] * 3600) . ' required';
                                    $endTip .= $endInfo['type'] === 'completed' ? ' · Completed on this date'
                                             : ($endInfo['type'] === 'estimated' ? ' · Estimated from remaining hours' : '');
                                }
                                $row['ojt_date_end'] = $endInfo['date'];
                                ?>
                                <?php if (!empty($row['ojt_date_end'])): ?>
                                    <span class="date-cell"<?= $endTip !== '' ? ' title="' . htmlspecialchars($endTip ?? '') . '"' : '' ?>>
                                        <i class="fas fa-stop-circle" style="color:#A02A2A; font-size:10px; margin-right:3px;"></i>
                                        <?= date('M d, Y', strtotime($row['ojt_date_end'] ?? '')) ?>
                                        <?php if ($endInfo['type'] === 'estimated'): ?><em style="font-size:10px; margin-left:2px;">(est.)</em><?php endif; ?>
                                    </span>
                                <?php else: ?>
                                    <span class="date-cell no-data">—</span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <?php
                                $hrs = floatval($row['ojt_total_hours'] ?? 0);
                                // UPDATED (accurate hours): use exact seconds for the check and the label
                                $secs = isset($row['ojt_total_seconds']) ? (int)round((float)$row['ojt_total_seconds']) : (int)round($hrs * 3600);
                                if ($secs >= 60):
                                    $display_hrs = student_list_format_hours($secs, $hrs);
                                ?>
                                    <span class="hours-badge" title="<?= htmlspecialchars(number_format($secs / 3600, 2)) ?> hours">
                                        <i class="fas fa-hourglass-half" style="font-size:10px; margin-right:3px;"></i>
                                        <?= $display_hrs ?>
                                    </span>
                                <?php else: ?>
                                    <span class="hours-badge no-hours">
                                        <i class="fas fa-hourglass-start" style="font-size:10px; margin-right:3px;"></i>
                                        0 hrs
                                    </span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <?php
                                // NEW (Remaining Hours): required hours (from the same Course Offering
                                // rule already resolved above for "Date of End"/$endInfo) minus the
                                // hours already rendered ($secs, computed just above for Total Hours).
                                // Reuses $endInfo['rule'] and $secs rather than re-querying anything.
                                $reqRule = $endInfo['rule'] ?? null;
                                if ($reqRule && (float)$reqRule['total'] > 0):
                                    $requiredSecs  = (int)round((float)$reqRule['total'] * 3600);
                                    $remainingSecs = max(0, $requiredSecs - $secs);
                                    if ($remainingSecs <= 0):
                                ?>
                                    <span class="hours-badge completed" title="Required hours reached">
                                        <i class="fas fa-check-circle" style="font-size:10px; margin-right:3px;"></i>
                                        Completed
                                    </span>
                                    <?php else: ?>
                                    <span class="hours-badge remaining" title="<?= htmlspecialchars(number_format($remainingSecs / 3600, 2)) ?> hours remaining">
                                        <i class="fas fa-hourglass-half" style="font-size:10px; margin-right:3px;"></i>
                                        <?= student_list_format_hours($remainingSecs) ?>
                                    </span>
                                    <?php endif; ?>
                                <?php else: ?>
                                    <span class="hours-badge no-hours" title="No matching Course Offering to compute this from">—</span>
                                <?php endif; ?>
                            </td>
                        </tr>
                        <?php endwhile; ?>
                    </tbody>
                </table>
            </div>

            <!-- Pagination -->
            <?php if ($total_pages > 1): ?>
                <div class="pagination">
                    <?php if ($page > 1): ?>
                        <a href="<?= build_url(['page' => $page - 1]) ?>"><i class="fas fa-chevron-left"></i> Prev</a>
                    <?php else: ?>
                        <span class="disabled"><i class="fas fa-chevron-left"></i> Prev</span>
                    <?php endif; ?>
                    <?php
                    $start_page = max(1, $page - 2);
                    $end_page   = min($total_pages, $page + 2);
                    if ($start_page > 1) {
                        echo '<a href="' . build_url(['page' => 1]) . '">1</a>';
                        if ($start_page > 2) echo '<span class="disabled">...</span>';
                    }
                    for ($i = $start_page; $i <= $end_page; $i++) {
                        if ($i == $page) echo '<span class="active">' . $i . '</span>';
                        else             echo '<a href="' . build_url(['page' => $i]) . '">' . $i . '</a>';
                    }
                    if ($end_page < $total_pages) {
                        if ($end_page < $total_pages - 1) echo '<span class="disabled">...</span>';
                        echo '<a href="' . build_url(['page' => $total_pages]) . '">' . $total_pages . '</a>';
                    }
                    ?>
                    <?php if ($page < $total_pages): ?>
                        <a href="<?= build_url(['page' => $page + 1]) ?>">Next <i class="fas fa-chevron-right"></i></a>
                    <?php else: ?>
                        <span class="disabled">Next <i class="fas fa-chevron-right"></i></span>
                    <?php endif; ?>
                </div>
                <div class="pagination-info">
                    Showing <?= $offset + 1 ?> – <?= min($offset + $records_per_page, $total_students) ?> of <?= $total_students ?> students
                </div>
            <?php else: ?>
                <div class="pagination-info" style="margin-top:20px;">
                    Showing <?= $total_students ?> student<?= $total_students != 1 ? 's' : '' ?>
                </div>
            <?php endif; ?>

<?php
}
$table_section_html = ob_get_clean();

/* NEW: if this request came from the JS-driven search/filter/pagination
   (identified by ?ajax_table=1), return just the rendered fragment as
   JSON and stop — no full HTML document, no page reload on the client,
   and none of the full-page markup/CSS/JS below is even rendered. */
if (isset($_GET['ajax_table']) && $_GET['ajax_table'] === '1') {
    header('Content-Type: application/json');
    echo json_encode([
        'html'  => $table_section_html,
        'total' => (int) $total_students,
    ]);
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
        :root {
            --neust-maroon: #07145fe5;
            --neust-gold: #FFD700;
            --bg: #fcfaf7;
            --text: #2d1b1b;
            --white: #ffffff;
            --sidebar-active: #1a237e;

            /* ══════════════════════════════════════════════════════════
               "Field Ops Grid" content-area palette (design #4 from the
               style previews). Scoped to its own variables so the
               sidebar and navbar — which keep using --neust-maroon /
               --neust-gold above — are completely unaffected. Every
               content component below (import card, filter bar,
               toolbar, table, badges, modals, pagination, toast) is
               restyled using these instead.
               ══════════════════════════════════════════════════════════ */
            --grid-bg: #EEF1F6;
            --grid-navy: #1B2A4A;
            --grid-border: #C3CADA;
            --grid-border-soft: #DCE1EC;
            --grid-green: #2C5A2C;
            --grid-green-bg: #EAF3EA;
            --grid-red: #A02A2A;
            --grid-red-bg: #F7E9E9;
            --grid-amber: #A0850A;
            --grid-amber-bg: #FAF3DC;
            --grid-muted: #5A6272;
        }

        body { font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif; background: var(--bg); margin: 0; display: flex; color: var(--text); min-height: 100vh; }

        .sidebar { width: 260px; background: var(--neust-maroon); height: 100vh; position: fixed; display: flex; flex-direction: column; transition: all 0.3s ease; z-index: 1000; box-shadow: 4px 0 10px rgba(0,0,0,0.1); }
        .sidebar.collapsed { width: 80px; }
        .sidebar-header { padding: 20px; display: flex; align-items: center; justify-content: space-between; border-bottom: 1px solid rgba(255,255,255,0.1); }
        .sidebar-header-titles { overflow: hidden; transition: 0.3s; min-width: 0; }
        .sidebar-header h2 { color: var(--neust-gold); margin: 0; font-size: 18px; font-weight: bold; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; transition: 0.3s; }
        .sidebar-role-label { display: block; color: rgba(255,255,255,0.55); font-size: 11px; font-weight: 700; text-transform: uppercase; letter-spacing: 0.8px; margin-top: 3px; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; transition: 0.3s; }
        .sidebar.collapsed .sidebar-header-titles { opacity: 0; width: 0; }
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

        .sidebar-badge-ungraded {
            background: #d97706; color: white; border-radius: 50%;
            width: 18px; height: 18px; font-size: 10px; font-weight: 700;
            display: inline-flex; align-items: center; justify-content: center;
            position: absolute; right: 18px; top: 50%; transform: translateY(-50%);
            animation: badge-pulse-ungraded 2s ease-in-out infinite;
        }
        @keyframes badge-pulse-ungraded {
            0%,100% { box-shadow: 0 0 0 0 rgba(217,119,6,0.55); }
            50%      { box-shadow: 0 0 0 6px rgba(217,119,6,0); }
        }
        .sidebar-badge-app {
            background: #dc2626; color: white; border-radius: 50%;
            width: 18px; height: 18px; font-size: 10px; font-weight: 700;
            display: inline-flex; align-items: center; justify-content: center;
            position: absolute; right: 18px; top: 50%; transform: translateY(-50%);
            animation: badge-pulse-app-sidebar 2s ease-in-out infinite;
        }
        @keyframes badge-pulse-app-sidebar {
            0%,100% { box-shadow: 0 0 0 0 rgba(220,38,38,0.55); }
            50%      { box-shadow: 0 0 0 6px rgba(220,38,38,0); }
        }

        /* pending-MOA indicator on the "Company Requirements" sidebar
           link — mirrors the identical badge/animation already used in
           company_validation.php so the visual language matches exactly. */
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

        .main-content { margin-left: 260px; width: calc(100% - 260px); transition: 0.3s; min-height: 100vh; }
        .sidebar.collapsed + .main-content { margin-left: 80px; width: calc(100% - 80px); }
        .navbar { background: var(--neust-maroon); padding: 10px 30px; display: flex; justify-content: space-between; align-items: center; color: white; height: 60px; }
        .logo-section { display: flex; align-items: center; gap: 12px; }
        .university-logo { height: 40px; }


        /* ══════════════════════════════════════════════════════════
           "Field Ops Grid" content area (everything below the navbar).
           ══════════════════════════════════════════════════════════ */
        .container { padding: 30px; max-width: 1450px; margin: 0 auto; background: var(--grid-bg); }
        .container h2 { margin-top: 0; color: var(--grid-navy); text-transform: uppercase; letter-spacing: 0.6px; font-size: 20px; }

        input[type="file"] { display: none; }

        .btn-primary {
            padding: 10px 24px; border: none; border-radius: 0; font-weight: 600;
            cursor: pointer; transition: opacity 0.2s; display: inline-flex; align-items: center;
            gap: 8px; background: var(--grid-navy); color: white;
            text-transform: uppercase; letter-spacing: 0.3px; font-size: 12px;
        }
        .btn-primary:hover { opacity: 0.9; }

        .add-student-btn {
            background: var(--grid-navy); color: white; border: none; padding: 10px 18px;
            border-radius: 0; font-weight: 600; cursor: pointer; transition: opacity 0.2s;
            display: inline-flex; align-items: center; gap: 8px; font-size: 12px;
            text-transform: uppercase; letter-spacing: 0.4px;
        }
        .add-student-btn:hover { opacity: 0.9; }

        /* NEW: toolbar-level Edit / Delete / Cancel buttons — adopted
           from admin_company_list.php's selection-mode toolbar so the
           two lists share the same interaction pattern. */
        .edit-entry-btn {
            background: #fff; color: var(--grid-navy); border: 1px solid var(--grid-border); border-radius: 0;
            padding: 10px 18px; font-weight: 600; cursor: pointer; transition: opacity 0.2s;
            display: inline-flex; align-items: center; gap: 8px; font-size: 12px;
            text-transform: uppercase; letter-spacing: 0.4px; justify-content: center;
        }
        .edit-entry-btn:disabled { color: #a9b0c2; cursor: not-allowed; opacity: 0.75; }
        .edit-entry-btn:not(:disabled):hover { background: #f3f4f7; }

        .delete-entry-btn {
            background: #fff; color: var(--grid-red); border: 1px solid var(--grid-border); border-radius: 0;
            padding: 10px 18px; font-weight: 600; cursor: pointer; transition: opacity 0.2s;
            display: inline-flex; align-items: center; gap: 8px; font-size: 12px;
            text-transform: uppercase; letter-spacing: 0.4px; justify-content: center;
        }
        .delete-entry-btn:disabled { color: #c79b9b; cursor: not-allowed; opacity: 0.75; }
        .delete-entry-btn:not(:disabled):hover { background: var(--grid-red-bg); }

        .cancel-selection-btn {
            background: #fff; color: var(--grid-muted); border: 1px solid var(--grid-border); border-radius: 0;
            padding: 10px 18px; font-weight: 600; cursor: pointer; transition: opacity 0.2s;
            display: inline-flex; align-items: center; gap: 8px; font-size: 12px;
            text-transform: uppercase; letter-spacing: 0.4px; justify-content: center;
        }
        .cancel-selection-btn:hover { background: #f3f4f7; }

        .alert { padding: 12px 20px; border-radius: 0; margin-bottom: 20px; border: 1px solid; }
        .alert-success { background: var(--grid-green-bg); color: var(--grid-green); border-color: #bfe0bf; }
        .alert-error { background: var(--grid-red-bg); color: var(--grid-red); border-color: #e3bcbc; }

        .student-list-header {
            display: flex; justify-content: space-between; align-items: center;
            margin-bottom: 14px; flex-wrap: wrap; gap: 15px;
        }
        .student-list-header h3 { margin: 0; color: var(--grid-navy); font-size: 14px; text-transform: uppercase; letter-spacing: 0.4px; }
        .header-actions { display: flex; gap: 10px; align-items: center; flex-wrap: wrap; }

        /* Total Students label — styled like "Total Companies" in admin_company_list.php:
           top-left above the table, on the same horizontal line as the toolbar buttons. */
        .student-total-count { display: block; text-align: left; margin: 0; font-size: 13px; font-weight: 700; color: var(--grid-navy); text-transform: uppercase; letter-spacing: 0.4px; flex: 0 0 auto; white-space: nowrap; }
        .student-total-count i { margin-right: 6px; }
        .student-total-count strong { font-weight: 800; }
        .student-list-header { flex-wrap: nowrap; gap: 10px 16px; }
        .student-list-header .header-actions { flex: 1 1 auto; min-width: 0; margin-left: auto; justify-content: flex-end; gap: 8px; }
        .student-list-header .header-actions .add-student-btn,
        .student-list-header .header-actions .edit-entry-btn,
        .student-list-header .header-actions .delete-entry-btn,
        .student-list-header .header-actions .cancel-selection-btn,
        .student-list-header .header-actions .export-btn,
        .student-list-header .header-actions .import-btn { padding: 10px 13px; gap: 7px; }

        .export-btn {
            background: #fff; color: var(--grid-navy); border: 1px solid var(--grid-border); padding: 10px 18px;
            border-radius: 0; font-weight: 600; cursor: pointer; transition: opacity 0.2s;
            display: inline-flex; align-items: center; gap: 8px; font-size: 12px; text-decoration: none;
            text-transform: uppercase; letter-spacing: 0.4px; justify-content: center;
        }
        .export-btn:hover { background: #f3f4f7; }

        /* Import Students button — sits to the right of Export to Excel and
           matches its look (replaces the old Import card / drop zone). */
        .import-btn {
            background: #fff; color: var(--grid-navy); border: 1px solid var(--grid-border); padding: 10px 18px;
            border-radius: 0; font-weight: 600; cursor: pointer; transition: opacity 0.2s;
            display: inline-flex; align-items: center; gap: 8px; font-size: 12px;
            text-transform: uppercase; letter-spacing: 0.4px; justify-content: center;
        }
        .import-btn:hover { background: #f3f4f7; }
        .import-btn:disabled { cursor: not-allowed; opacity: 0.75; }

        .filter-bar {
            background: white; border-radius: 0; padding: 20px; margin-bottom: 25px;
            box-shadow: none; border: 1px solid var(--grid-border); display: flex; flex-wrap: wrap;
            gap: 15px; align-items: flex-end;
        }
        .filter-group { flex: 1; min-width: 180px; }
        .filter-group label {
            display: block; font-size: 11px; font-weight: 600; color: var(--grid-navy); margin-bottom: 6px;
            text-transform: uppercase; letter-spacing: 0.5px;
        }
        .filter-group input, .filter-group select {
            width: 100%; padding: 9px 12px; border: 1px solid var(--grid-border);
            border-radius: 0; font-size: 13px; transition: all 0.2s; background: white; color: var(--text);
            box-sizing: border-box; /* keeps the search box/selects inside their column so they no longer overlap the neighbouring field or the filter buttons */
        }
        .filter-group input:focus, .filter-group select:focus {
            outline: none; border-color: var(--grid-navy); box-shadow: 0 0 0 3px rgba(27,42,74,0.08);
        }
        .filter-actions { display: flex; gap: 10px; align-items: center; flex-shrink: 0; }
        .btn-filter {
            background: var(--grid-navy); color: white; border: none; padding: 10px 18px;
            border-radius: 0; font-weight: 600; cursor: pointer; transition: opacity 0.2s;
            display: inline-flex; align-items: center; gap: 8px;
            text-transform: uppercase; letter-spacing: 0.4px; font-size: 12px;
        }
        .btn-filter:hover { opacity: 0.9; }
        .btn-reset {
            background: #fff; color: var(--grid-navy); border: 1px solid var(--grid-border); padding: 10px 18px;
            border-radius: 0; font-weight: 600; cursor: pointer; transition: opacity 0.2s;
            display: inline-flex; align-items: center; gap: 8px;
            text-transform: uppercase; letter-spacing: 0.4px; font-size: 12px;
        }
        .btn-reset:hover { background: #f3f4f7; }

        /* ═══════════════════════════════════════════════════════════════
           TABLE — horizontally scrollable with a visible, styled scrollbar
           ═══════════════════════════════════════════════════════════════ */
        .table-scroll-wrapper {
            overflow-x: auto;
            -webkit-overflow-scrolling: touch;
            border-radius: 0;
            border: 1px solid var(--grid-border);
            box-shadow: none;
            scrollbar-width: thin;
            scrollbar-color: var(--grid-navy) var(--grid-border);
        }
        .table-scroll-wrapper::-webkit-scrollbar        { height: 8px; }
        .table-scroll-wrapper::-webkit-scrollbar-track  { background: var(--grid-border); }
        .table-scroll-wrapper::-webkit-scrollbar-thumb  { background: var(--grid-navy); border-radius: 0; }
        .table-scroll-wrapper::-webkit-scrollbar-thumb:hover { background: #0f1a30; }

        .student-table {
            width: 100%;
            border-collapse: collapse;
            background: white;
            min-width: 1420px; /* UPDATED (Major): +150px for the new Major column */
            table-layout: auto;
        }
        .student-table th {
            background: var(--grid-navy); color: #fff;
            padding: 12px 16px; text-align: left; font-size: 11px; font-weight: 700;
            text-transform: uppercase; letter-spacing: 0.4px; white-space: nowrap;
        }
        .student-table td {
            padding: 12px 16px; border-bottom: 1px solid var(--grid-border-soft);
            color: #2d3748; font-size: 13px; vertical-align: middle;
            white-space: nowrap;   /* keep each cell on one line — no squeezing */
        }
        .student-table tr:hover td { background: #F5F7FB; }
        .student-table tr:last-child td { border-bottom: none; }

        /* Row-selection checkbox column — hidden by default, revealed
           only once the admin clicks "Edit" or "Delete" to enter
           selection mode. JS sets the inline "display" style directly,
           which always wins over this stylesheet rule. */
        .checkbox-cell { display: none; text-align: center; }
        .student-table input[type="checkbox"] { cursor: pointer; width: 17px; height: 17px; vertical-align: middle; }

        /* Highlight a row while its checkbox is selected — red while in
           Delete mode, amber while in Edit mode, mirroring
           admin_company_list.php exactly. */
        .student-table tr.row-selected td { background: var(--grid-red-bg); }
        .student-table tr.row-selected:hover td { background: #f1d9d9; }
        .student-table tr.row-selected-edit td { background: var(--grid-amber-bg); }
        .student-table tr.row-selected-edit:hover td { background: #f5ecc7; }
        /* NEW (Export exclusion — adopted from admin_company_list.php): a row
           checked to be LEFT OUT of the export gets a light navy tint, so it
           never looks like the Delete (red) or Edit (amber) selection. */
        .student-table tr.row-selected-export td { background: #E7ECF7; }
        .student-table tr.row-selected-export:hover td { background: #d7deef; }

        /* NEW (row selection — adopted from admin_company_list.php): while a
           selection mode (Edit / Delete) is active, the whole row can be
           clicked to select it, so hint that with a pointer cursor. Links,
           buttons, selects and labels inside the row keep their own cursor. */
        .student-table.selection-mode-active tbody tr { cursor: pointer; }
        .student-table.selection-mode-active tbody tr a,
        .student-table.selection-mode-active tbody tr button,
        .student-table.selection-mode-active tbody tr select,
        .student-table.selection-mode-active tbody tr label { cursor: auto; }

        /* Per-column minimum widths for comfortable reading */
        .student-table th:nth-child(1), .student-table td:nth-child(1) { width: 44px; min-width: 44px; } /* Checkbox    */
        .student-table th:nth-child(2), .student-table td:nth-child(2) { min-width: 170px; } /* Full Name   */
        .student-table th:nth-child(3), .student-table td:nth-child(3) { min-width: 110px; } /* Course      */
        .student-table th:nth-child(4), .student-table td:nth-child(4) { min-width: 150px; } /* Major (NEW) */
        .student-table th:nth-child(5), .student-table td:nth-child(5) { min-width: 100px; } /* Section     */
        .student-table th:nth-child(6), .student-table td:nth-child(6) { min-width: 210px; } /* Email       */
        .student-table th:nth-child(7), .student-table td:nth-child(7) { min-width: 145px; } /* Campus      */
        .student-table th:nth-child(8), .student-table td:nth-child(8) { min-width: 170px; } /* Company     */
        .student-table th:nth-child(9), .student-table td:nth-child(9) { min-width: 130px; } /* Date Start  */
        .student-table th:nth-child(10), .student-table td:nth-child(10) { min-width: 130px; } /* Date End    */
        .student-table th:nth-child(11), .student-table td:nth-child(11) { min-width: 115px; } /* Total Hours */
        .student-table th:nth-child(12), .student-table td:nth-child(12) { min-width: 130px; } /* Remaining Hours (NEW) */

        /* Badges restyled flat/colored-text per design #4 — no rounded
           pill backgrounds, just a small left accent + colored text. */
        .company-pill {
            display: inline-flex; align-items: center; gap: 5px;
            color: #5b3fa0; font-size: 11px; font-weight: 700;
            text-transform: uppercase; letter-spacing: 0.3px; white-space: nowrap;
        }
        .company-pill.unassigned { color: var(--grid-muted); font-style: italic; }
        .date-cell { font-size: 12px; color: var(--grid-muted); white-space: nowrap; }
        .date-cell.no-data { color: #9aa2b1; font-style: italic; }
        .hours-badge {
            display: inline-block; color: var(--grid-navy);
            font-size: 12px; font-weight: 700; white-space: nowrap;
        }
        .hours-badge.no-hours { color: #9aa2b1; }
        /* NEW (Remaining Hours column): green when the required hours have
           been reached, amber while hours are still remaining. */
        .hours-badge.completed { color: #2C5A2C; }
        .hours-badge.remaining { color: #B8860B; }

        .empty-state { text-align: center; padding: 60px 20px; color: var(--grid-muted); background: #fff; border: 1px solid var(--grid-border); }
        .empty-state i { font-size: 48px; margin-bottom: 16px; display: block; color: var(--grid-border); }

        .pagination {
            display: flex; justify-content: center; align-items: center;
            gap: 0; margin-top: 30px; flex-wrap: wrap; border: 1px solid var(--grid-border);
            width: fit-content; margin-left: auto; margin-right: auto;
        }
        .pagination a, .pagination span {
            display: inline-flex; align-items: center; justify-content: center;
            min-width: 40px; height: 38px; padding: 0 12px; border-radius: 0;
            font-size: 13px; font-weight: 500; text-decoration: none; transition: all 0.2s;
            background: white; color: var(--grid-navy); border-right: 1px solid var(--grid-border);
        }
        .pagination a:last-child, .pagination span:last-child { border-right: none; }
        .pagination a:hover { background: var(--grid-navy); color: white; }
        .pagination .active  { background: var(--grid-navy); color: white; }
        .pagination .disabled { opacity: 0.5; cursor: not-allowed; background: #f8fafc; }
        .pagination-info { text-align: center; margin-top: 15px; font-size: 12px; color: var(--grid-muted); text-transform: uppercase; letter-spacing: 0.3px; }

        /* Auto-filter loading / fade-in — mirrors #companyTableSection in admin_company_list.php */
        #studentTableSection { transition: opacity 0.15s ease; }
        #studentTableSection.table-loading { opacity: 0.35; pointer-events: none; }
        #studentTableSection.table-fade-in { animation: studentTableFadeIn 0.35s ease; }
        @keyframes studentTableFadeIn { from { opacity: 0; transform: translateY(6px); } to { opacity: 1; transform: translateY(0); } }

        .loading-overlay {
            display: none; position: fixed; top: 0; left: 0; width: 100%; height: 100%;
            background: rgba(0,0,0,0.7); z-index: 10000; justify-content: center;
            align-items: center; flex-direction: column;
        }
        .loading-spinner {
            width: 50px; height: 50px; border: 4px solid #f3f3f3;
            border-top: 4px solid var(--neust-gold); border-radius: 50%;
            animation: spin 1s linear infinite; margin-bottom: 20px;
        }
        .loading-text { color: white; font-size: 14px; font-weight: 500; }
        @keyframes spin { 0% { transform: rotate(0deg); } 100% { transform: rotate(360deg); } }

        /* ══════════════════════════════════════════════════════════
           NEW: Global loading overlay — mirrors admin_company_list.php's
           full-page "load page" exactly. Shown by default so it covers
           the very first paint while page assets are still loading, and
           reused (shown/hidden) around in-page actions via
           showGlobalLoading()/hideGlobalLoading(). This is separate from
           the existing #loadingOverlay above (which is only used for the
           "Processing large file..." XLSX import spinner) — that overlay
           and its logic are untouched.
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
            border: 5px solid var(--grid-border, #C3CADA);
            border-top-color: var(--grid-navy, #1B2A4A);
            animation: globalLoadingSpin 0.85s linear infinite;
        }
        .global-loading-text {
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            font-size: 13px;
            font-weight: 700;
            color: var(--grid-navy, #1B2A4A);
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

        /* UPDATED (success loading page — same as course_offering.php): after a
           successful edit / delete the same full-page loader switches to a check
           icon + message of the action, instead of the popup (toast)
           notification, then the page reloads. */
        .global-loading-success { display: none; flex-direction: column; align-items: center; gap: 10px; text-align: center; max-width: 420px; padding: 0 20px; }
        #globalLoadingOverlay.success-state .global-loading-spinner,
        #globalLoadingOverlay.success-state .global-loading-text { display: none; }
        #globalLoadingOverlay.success-state .global-loading-success { display: flex; }
        .gls-check {
            width: 64px; height: 64px; border-radius: 50%;
            background: var(--grid-green, #2C5A2C); color: #fff;
            display: flex; align-items: center; justify-content: center;
            font-size: 30px; box-shadow: 0 0 0 8px var(--grid-green-bg, #EAF3EA);
            animation: glsCheckPop 0.45s cubic-bezier(.34,1.56,.64,1);
        }
        .gls-title {
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            font-size: 15px; font-weight: 700; color: var(--grid-navy, #1B2A4A);
            text-transform: uppercase; letter-spacing: 0.6px; margin-top: 6px;
        }
        .gls-message { font-size: 13px; color: var(--grid-muted, #5B6478); }
        .gls-sub { font-size: 11px; color: var(--grid-muted, #5B6478); opacity: .8; display: flex; align-items: center; gap: 6px; }
        @keyframes glsCheckPop { from { transform: scale(0.3); opacity: 0; } to { transform: scale(1); opacity: 1; } }

        /* ══════════════════════════════════════════════════════════
           NEW (Export result screen — same as admin_company_list.php):
           full-page result screen shown right after an export finishes —
           same backdrop and pop-in animation as the loading overlay above,
           but with a success (green check) or failure (red X) icon, a
           title, a short message and an OK button. Success screens close
           themselves after a few seconds; failure screens stay until the
           admin clicks OK. Sits one layer above #globalLoadingOverlay.
           ══════════════════════════════════════════════════════════ */
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

        /* Add / Edit Student Modal (shared classes) */
        .add-student-modal-overlay {
            display: none; position: fixed; top: 0; left: 0; width: 100%; height: 100%;
            background: rgba(0,0,0,0.5); z-index: 10003; justify-content: center; align-items: center;
        }
        .add-student-modal-box {
            background: white; border-radius: 0; border: 1px solid var(--grid-border); padding: 32px; width: 550px;
            max-width: 90%; max-height: 90vh; overflow-y: auto; animation: modalPop 0.3s ease;
        }
        .add-student-modal-header {
            display: flex; justify-content: space-between; align-items: center;
            margin-bottom: 24px; padding-bottom: 16px; border-bottom: 1px solid var(--grid-border);
        }
        .add-student-modal-header h3 { margin: 0; color: var(--grid-navy); font-size: 16px; text-transform: uppercase; letter-spacing: 0.4px; }
        .close-add-student-btn { background: none; border: none; font-size: 24px; cursor: pointer; color: var(--grid-muted); transition: color 0.2s; }
        .close-add-student-btn:hover { color: var(--grid-navy); }
        .form-group { margin-bottom: 20px; }
        .form-group label { display: block; font-weight: 600; color: #1e293b; margin-bottom: 8px; font-size: 13px; }
        .form-group label .required { color: var(--grid-red); }
        .form-group input, .form-group select {
            width: 100%; padding: 10px 12px; border: 1px solid var(--grid-border); border-radius: 0;
            font-size: 14px; transition: all 0.2s; box-sizing: border-box;
        }
        .form-group input:focus, .form-group select:focus {
            outline: none; border-color: var(--grid-navy); box-shadow: 0 0 0 3px rgba(27,42,74,0.08);
        }
        .form-row { display: flex; gap: 15px; }
        .form-row .form-group { flex: 1; }
        /* Campus / Course custom-entry panels — hidden by default, revealed when "other" is picked */
        .custom-campus-group { display: none; margin-top: 10px; }
        .custom-campus-group.show { display: block; }
        .custom-course-group { display: none; margin-top: 10px; }
        .custom-course-group.show { display: block; }
        .help-text { font-size: 11px; color: var(--grid-muted); margin-top: 5px; }
        .modal-actions { display: flex; gap: 12px; justify-content: flex-end; margin-top: 24px; padding-top: 16px; border-top: 1px solid var(--grid-border); }
        .btn-submit { background: var(--grid-navy); color: white; border: none; padding: 10px 24px; border-radius: 0; font-weight: 600; cursor: pointer; transition: opacity 0.2s; text-transform: uppercase; letter-spacing: 0.3px; font-size: 12px; }
        .btn-submit:hover { opacity: 0.9; }
        .btn-cancel-modal { background: #fff; color: var(--grid-navy); border: 1px solid var(--grid-border); padding: 10px 24px; border-radius: 0; font-weight: 600; cursor: pointer; transition: opacity 0.2s; text-transform: uppercase; letter-spacing: 0.3px; font-size: 12px; }
        .btn-cancel-modal:hover { background: #f3f4f7; }

        /* ── Add / Edit Student modal — compact grid layout, mirrors the Add
           Company modal in admin_company_list.php. Scoped to #addStudentModal
           AND #editStudentModal (UPDATED: Edit Student now shares the exact
           same compact grid look as Add Student), so the Settings modal and
           every other modal keep their existing look. 3 columns on desktop,
           2 on medium screens, 1 on small screens; the box always leaves a
           margin above and below, scrolls internally on short screens, and
           keeps the action buttons pinned at the bottom. ── */
        #addStudentModal, #editStudentModal { align-items: center; padding: 20px 0; box-sizing: border-box; }
        #addStudentModal .add-student-modal-box, #editStudentModal .add-student-modal-box { width: 860px; max-width: 94%; max-height: calc(100vh - 40px); max-height: calc(100dvh - 40px); padding: 16px 24px 0 24px; box-sizing: border-box; }
        #addStudentModal .add-student-modal-header, #editStudentModal .add-student-modal-header { margin-bottom: 12px; padding-bottom: 8px; }
        #addStudentModal .add-student-modal-header h3, #editStudentModal .add-student-modal-header h3 { font-size: 15px; }
        #addStudentModal .add-student-grid, #editStudentModal .add-student-grid { display: grid; grid-template-columns: repeat(3, 1fr); column-gap: 14px; row-gap: 0; align-items: start; }
        #addStudentModal .add-student-grid .span-2, #editStudentModal .add-student-grid .span-2 { grid-column: span 2; }
        #addStudentModal .add-student-grid .span-3, #editStudentModal .add-student-grid .span-3 { grid-column: 1 / -1; }
        #addStudentModal .form-group, #editStudentModal .form-group { margin-bottom: 9px; }
        #addStudentModal .form-group label, #editStudentModal .form-group label { margin-bottom: 4px; font-size: 12px; }
        #addStudentModal .form-group input, #addStudentModal .form-group select,
        #editStudentModal .form-group input, #editStudentModal .form-group select { padding: 7px 10px; font-size: 13px; }
        #addStudentModal .help-text, #editStudentModal .help-text { font-size: 10.5px; margin-top: 3px; line-height: 1.35; }
        #addStudentModal .custom-campus-group, #addStudentModal .custom-course-group,
        #editStudentModal .custom-campus-group, #editStudentModal .custom-course-group { margin-top: 6px; }
        #addStudentModal .modal-actions, #editStudentModal .modal-actions { justify-content: flex-end; position: sticky; bottom: 0; background: #fff; margin-top: 4px; padding: 10px 0 12px 0; z-index: 2; }
        @media (max-width: 900px) {
            #addStudentModal .add-student-grid, #editStudentModal .add-student-grid { grid-template-columns: 1fr 1fr; }
        }
        @media (max-width: 640px) {
            #addStudentModal .add-student-modal-box, #editStudentModal .add-student-modal-box { padding: 14px 14px 0 14px; }
            #addStudentModal .add-student-grid, #editStudentModal .add-student-grid { grid-template-columns: 1fr; }
            #addStudentModal .add-student-grid .span-2, #addStudentModal .add-student-grid .span-3,
            #editStudentModal .add-student-grid .span-2, #editStudentModal .add-student-grid .span-3 { grid-column: auto; }
        }


        /* Toast */
        .toast-notification {
            position: fixed; top: 30px; left: 50%; background: white; border-radius: 0;
            padding: 16px 20px; min-width: 320px; max-width: 440px;
            box-shadow: 0 10px 30px rgba(0,0,0,0.18); display: flex; align-items: center;
            gap: 12px; z-index: 10002;
            transform: translateX(-50%) translateY(-120px); opacity: 0;
            transition: transform 0.35s cubic-bezier(0.34, 1.56, 0.64, 1), opacity 0.35s ease;
            border: 1px solid var(--grid-border); border-top: 4px solid;
        }
        .toast-notification.show    { transform: translateX(-50%) translateY(0); opacity: 1; }
        .toast-notification.vanishing { transform: translateX(-50%) translateY(-20px); opacity: 0; transition: transform 0.5s ease, opacity 0.5s ease; }
        .toast-notification.success { border-top-color: var(--grid-green); }
        .toast-notification.error   { border-top-color: var(--grid-red); }
        .toast-notification.warning { border-top-color: var(--grid-amber); }
        .toast-icon { font-size: 22px; }
        .toast-content { flex: 1; }
        .toast-title { font-weight: 700; margin-bottom: 4px; font-size: 14px; }
        .toast-message { font-size: 13px; color: var(--grid-muted); }
        .toast-close { background: none; border: none; font-size: 18px; cursor: pointer; color: #94a3b8; padding: 0; }

        /* Delete Selected Confirmation Modal (replaces the old truncate-table modal) */
        .modal-overlay {
            display: none; position: fixed; top: 0; left: 0; width: 100%; height: 100%;
            background: rgba(0,0,0,0.5); z-index: 9999; justify-content: center; align-items: center;
        }
        .modal-box { background: white; border-radius: 0; border: 1px solid var(--grid-border); padding: 32px; width: 420px; max-width: 90%; text-align: center; animation: modalPop 0.3s ease; }
        @keyframes modalPop { from { transform: scale(0.9); opacity: 0; } to { transform: scale(1); opacity: 1; } }
        .modal-icon    { font-size: 48px; color: var(--grid-red); margin-bottom: 16px; }
        .modal-title   { font-size: 18px; font-weight: 700; color: #1e293b; margin-bottom: 8px; text-transform: uppercase; letter-spacing: 0.3px; }
        .modal-message { color: var(--grid-muted); font-size: 14px; margin-bottom: 24px; line-height: 1.6; }
        .modal-actions { display: flex; gap: 12px; justify-content: center; }
        .modal-cancel, .modal-confirm { padding: 10px 24px; border-radius: 0; font-weight: 600; cursor: pointer; border: 1px solid var(--grid-border); transition: opacity 0.2s; text-transform: uppercase; letter-spacing: 0.3px; font-size: 12px; }
        .modal-cancel  { background: #fff; color: var(--grid-navy); }
        .modal-confirm { background: var(--grid-red); color: white; border-color: var(--grid-red); }
        .modal-cancel:hover, .modal-confirm:hover { opacity: 0.85; }

        /* NEW: "Creating Student Accounts" popup (adopted from admin_company_list.php) */
        .auto-account-stat-grid { display:flex; gap:14px; justify-content:center; margin-bottom:18px; flex-wrap:wrap; }
        .auto-account-stat { text-align:center; min-width:70px; }
        .auto-account-stat-number { font-size:28px; font-weight:800; line-height:1; }
        .auto-account-stat-label { font-size:11px; text-transform:uppercase; letter-spacing:0.4px; color:var(--grid-muted); margin-top:4px; }
        .auto-account-stat-number.created { color:var(--grid-green); }
        .auto-account-stat-number.skipped { color:var(--grid-amber); }
        .auto-account-stat-number.failed { color:var(--grid-red); }
        .auto-account-detail-block { text-align:left; margin-bottom:12px; }
        .auto-account-detail-block strong { font-size:11px; text-transform:uppercase; letter-spacing:0.4px; display:block; margin-bottom:4px; }
        .auto-account-detail-block ul { margin:0; padding-left:18px; font-size:12px; color:#475569; max-height:140px; overflow-y:auto; }

        /* NEW (Campus Branch / Course import filter revision): checkbox
           groups inside the "studentImportFilterModal" picker (same look
           as the import picker in admin_company_list.php). */
        #studentImportFilterModal .modal-box { max-height: calc(100vh - 40px); overflow-y: auto; box-sizing: border-box; }
        .import-classification-group { margin-top: 18px; text-align: left; }
        .import-classification-group-head { display: flex; align-items: center; justify-content: space-between; gap: 8px; margin-bottom: 8px; }
        .import-classification-group-label { font-size: 11px; font-weight: 700; color: var(--grid-navy); text-transform: uppercase; letter-spacing: 0.4px; }
        .import-classification-toggle { background: none; border: none; padding: 0; font-size: 11px; font-weight: 600; color: var(--grid-navy); cursor: pointer; text-decoration: underline; text-transform: uppercase; letter-spacing: 0.3px; }
        .import-classification-toggle:hover { opacity: 0.75; }
        .import-classification-options { display: flex; flex-direction: column; gap: 8px; max-height: 190px; overflow-y: auto; padding-right: 2px; }
        .import-classification-option { display: flex; align-items: center; gap: 8px; padding: 8px 10px; border: 1px solid var(--grid-border); background: var(--grid-bg); font-size: 13px; color: #2d3748; cursor: pointer; }
        .import-classification-option input[type="checkbox"] { width: 15px; height: 15px; cursor: pointer; flex-shrink: 0; }
        .import-classification-option .opt-label { flex: 1; word-break: break-word; }
        .import-classification-option .opt-count { font-size: 11px; color: var(--grid-muted); white-space: nowrap; }
        .import-classification-option .opt-flag { font-size: 10px; font-weight: 700; color: var(--grid-amber); text-transform: uppercase; letter-spacing: 0.3px; white-space: nowrap; }
        .auto-account-progress-text { text-align:center; color:var(--grid-muted); font-size:13px; margin-top:4px; }

        @media (max-width: 768px) {
            .sidebar { transform: translateX(-100%); }
            .sidebar.collapsed { transform: translateX(0); width: 260px; }
            .main-content, .sidebar.collapsed + .main-content { margin-left: 0; width: 100%; }
            .student-list-header { flex-direction: column; align-items: flex-start; flex-wrap: wrap; }
            .student-list-header .header-actions { margin-left: 0; }
            .header-actions { width: 100%; justify-content: stretch; }
            .add-student-btn, .edit-entry-btn, .delete-entry-btn, .cancel-selection-btn, .export-btn, .import-btn { flex: 1; justify-content: center; }
            .filter-bar { flex-direction: column; }
            .filter-group { width: 100%; }
            .filter-actions { justify-content: stretch; }
            .btn-filter, .btn-reset { flex: 1; justify-content: center; }
            .toast-notification { left: 20px; right: 20px; min-width: auto; }
            .form-row { flex-direction: column; gap: 0; }
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
        ov.classList.add('gl-instant');   // NEW (loader sync fix): appears on the very next paint, like administrator.php
        ov.classList.remove('hidden');
        setTimeout(function () { if (!document.hidden) { ov.classList.add('hidden'); } }, 8000);   // still here → it was a download
    });
})();
</script>
<!-- NEW (this adjustment): close (×) button on the "Export To Excel" choice popup — same look as this page's other close buttons -->
<style>
    #exportChoiceModal .modal-box { position: relative; }
    .export-choice-close { position: absolute; top: 10px; right: 14px; background: none; border: none; padding: 4px; font-size: 24px; line-height: 1;
        cursor: pointer; color: var(--grid-muted, #5A6272); transition: color 0.2s; font-family: inherit; }
    .export-choice-close:hover { color: var(--grid-navy, #1B2A4A); }
    .export-choice-close:focus-visible { outline: 2px solid var(--grid-navy, #1B2A4A); outline-offset: 2px; }
</style>
<!-- NEW (this adjustment): the import's "Add Course Offering" form uses the SAME design as the manual
     Add Student form (#addStudentModal): compact header, the same field / label / hint sizes, the grid
     layout and the action buttons pinned at the bottom-right. Only the column count differs (2 columns
     for 3 fields — the Add Student form's own medium-screen layout). Scoped to #importAddCourseModal,
     so the Add / Edit Student forms and every other modal keep their existing look. -->
<style>
    #importAddCourseModal { align-items: center; padding: 20px 0; box-sizing: border-box; }
    #importAddCourseModal .add-student-modal-box { width: 640px; max-width: 94%; max-height: calc(100vh - 40px); max-height: calc(100dvh - 40px); padding: 16px 24px 0 24px; box-sizing: border-box; }
    #importAddCourseModal .add-student-modal-header { margin-bottom: 12px; padding-bottom: 8px; }
    #importAddCourseModal .add-student-modal-header h3 { font-size: 15px; }
    #importAddCourseModal .add-student-grid { display: grid; grid-template-columns: repeat(2, 1fr); column-gap: 14px; row-gap: 0; align-items: start; }
    #importAddCourseModal .add-student-grid .span-3 { grid-column: 1 / -1; }
    #importAddCourseModal .form-group { margin-bottom: 9px; }
    #importAddCourseModal .form-group label { margin-bottom: 4px; font-size: 12px; }
    #importAddCourseModal .form-group input { padding: 7px 10px; font-size: 13px; }
    #importAddCourseModal .help-text { font-size: 10.5px; margin-top: 3px; line-height: 1.35; }
    #importAddCourseModal .modal-actions { justify-content: flex-end; position: sticky; bottom: 0; background: #fff; margin-top: 4px; padding: 10px 0 12px 0; z-index: 2; }
    #importAddCourseModal .iac-note { margin: 0 0 10px; font-size: 12px; color: var(--grid-muted); line-height: 1.45; }
    /* NEW (this adjustment): manual Add Student -> the course is locked to the one the admin entered for the student */
    #importAddCourseModal .form-group input[readonly] { background: #f1f3f5; cursor: not-allowed; }
    @media (max-width: 640px) {
        #importAddCourseModal .add-student-modal-box { padding: 14px 14px 0 14px; }
        #importAddCourseModal .add-student-grid { grid-template-columns: 1fr; }
        #importAddCourseModal .add-student-grid .span-3 { grid-column: auto; }
    }
</style>
<!-- NEW (this adjustment): the "Course not in Course Offering" question opens ABOVE the Add Student form
     (that form's overlay is at 10003, the question's general popup layer was 9999, so it opened behind the form) -->
<style>
    #courseCheckModal { z-index: 10010; }
</style>
<!-- NEW (this adjustment): "Choose What To Import" — same design as the manual Add Student form: wide box that always
     fits the screen, compact header with a close (×) button, the two lists side by side, buttons pinned bottom-right. -->
<style>
    #studentImportFilterModal .modal-box { width: 860px !important; max-width: 94%; max-height: calc(100vh - 40px); max-height: calc(100dvh - 40px);
        padding: 16px 24px 0 24px; box-sizing: border-box; display: flex; flex-direction: column; overflow: hidden; text-align: left; }
    #studentImportFilterModal .import-picker-head { display: flex; justify-content: space-between; align-items: center; gap: 12px;
        border-bottom: 1px solid var(--grid-border); margin-bottom: 10px; padding-bottom: 8px; }
    #studentImportFilterModal .import-picker-head h3 { margin: 0; color: var(--grid-navy); font-size: 15px; text-transform: uppercase; letter-spacing: 0.4px; }
    #studentImportFilterModal .import-picker-close { background: none; border: none; font-size: 24px; line-height: 1; cursor: pointer; color: var(--grid-muted); transition: color 0.2s; padding: 0 2px; }
    #studentImportFilterModal .import-picker-close:hover { color: var(--grid-navy); }
    #studentImportFilterModal .modal-message { font-size: 12.5px; line-height: 1.5; margin: 0 0 4px; text-align: left; }
    #studentImportFilterModal .import-picker-grid { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); column-gap: 18px; align-items: start; min-height: 0; flex: 1 1 auto; overflow: hidden; }
    #studentImportFilterModal .import-classification-group { margin-top: 8px; min-width: 0; }
    #studentImportFilterModal .import-classification-options { gap: 6px; max-height: calc(100vh - 300px); max-height: calc(100dvh - 300px); overflow-y: auto; padding-right: 2px; }
    #studentImportFilterModal .import-classification-option { padding: 6px 10px; font-size: 12.5px; }
    #studentImportFilterModal .modal-actions { justify-content: flex-end; position: sticky; bottom: 0; background: #fff; margin-top: 10px !important;
        padding: 10px 0 12px 0; border-top: 1px solid var(--grid-border); z-index: 2; }
    @media (max-width: 760px) {
        #studentImportFilterModal .modal-box { padding: 14px 14px 0 14px; overflow-y: auto; }
        #studentImportFilterModal .import-picker-grid { grid-template-columns: 1fr; overflow: visible; }
        #studentImportFilterModal .import-classification-options { max-height: none; }
    }
</style>
<!-- NEW (this adjustment): button tooltips can now hold a short description, so they may wrap onto a second line -->
<style>
    #cvBtnTip { white-space: normal; max-width: 300px; text-align: center; }
</style>
</head>
<body>

<!-- ══════════════════════════════════════════════════════════
     NEW: Global loading/processing popup. Visible by default (so it
     covers the page while assets are still loading), then hidden by
     JS once the window finishes loading. Also reused (shown/hidden)
     around in-page actions further down via showGlobalLoading()/
     hideGlobalLoading(), mirroring admin_company_list.php exactly.
     Purely additive — does not alter the existing #loadingOverlay
     below (kept as-is for the large-file-import spinner).
     ══════════════════════════════════════════════════════════ -->
<div id="globalLoadingOverlay">
    <div class="global-loading-box">
        <div class="global-loading-spinner"></div>
        <div class="global-loading-text">
            <span id="globalLoadingLabel">Loading</span>
            <span class="global-loading-dots"><span>.</span><span>.</span><span>.</span></span>
        </div>
        <!-- UPDATED (success loading page — same as course_offering.php): check icon + notification of the action -->
        <div class="global-loading-success" id="globalLoadingSuccess" role="status" aria-live="polite">
            <div class="gls-check"><i class="fas fa-check"></i></div>
            <div class="gls-title" id="globalLoadingSuccessTitle">Success</div>
            <div class="gls-message" id="globalLoadingSuccessMsg"></div>
            <div class="gls-sub"><i class="fas fa-sync-alt fa-spin"></i> Refreshing the list...</div>
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

<!-- NEW (Export result screen — same as admin_company_list.php): full-page
     result screen shown when an export finishes (successfully or not).
     Hidden by default; controlled by showGlobalResult()/hideGlobalResult()
     in the script below. -->
<div id="globalResultOverlay" class="hidden" role="alertdialog" aria-live="assertive" aria-labelledby="globalResultTitle" aria-describedby="globalResultMessage">
    <div class="global-result-box">
        <div class="global-result-icon"><i id="globalResultIcon" class="fas fa-check"></i></div>
        <p class="global-result-title" id="globalResultTitle"></p>
        <p class="global-result-message" id="globalResultMessage"></p>
        <button type="button" class="global-result-ok" id="globalResultOkBtn">OK</button>
    </div>
</div>

<!-- UPDATED: the old "Processing large file... Please wait..." overlay was
     removed; imports now use the same #globalLoadingOverlay page loader as
     admin_company_list.php ("Importing students..."). -->

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
        <a href="admin_student_list.php" class="active"><i class="fas fa-users"></i><span class="link-text">Student List</span></a>
        <a href="admin_company_list.php"><i class="fas fa-building"></i><span class="link-text">Company List</span></a>
        <a href="course_offering.php"><i class="fas fa-book"></i><span class="link-text">Course Offering</span></a>
        <a href="administrator.php" style="position:relative;">
            <i class="fas fa-user-check"></i>
            <span class="link-text">Student Validation</span>
            <?php if ($app_request_count > 0): ?>
                <span class="sidebar-badge-app" id="sidebarAppBadge"><?= $app_request_count ?></span>
            <?php else: ?>
                <span class="sidebar-badge-app" id="sidebarAppBadge" style="display:none"><?= $app_request_count ?></span>
            <?php endif; ?>
        </a>
        <a href="company_validation.php" style="position:relative;">
            <i class="fas fa-building"></i><span class="link-text">Company Requirements</span>
            <span class="sidebar-badge-moa" id="sidebarMoaBadge"<?= $moa_pending_count > 0 ? '' : ' style="display:none"' ?>><?= $moa_pending_count ?></span>
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
                <div style="font-size:11px; color:var(--neust-gold);">Web-Based Smart OJT Monitoring and Supervision Analytics System</div>
            </div>
        </div>
    </nav>

    <div class="container">
        <?php /* UPDATED: the "Student Import" heading (icon + label) above the search bar was removed. */ ?>

        <?php /* UPDATED: the "Successfully imported ... records" display was removed; only import errors are shown here. */ ?>
        <?php if ($import_message && $import_message_type !== 'success'): ?>
            <div class="alert alert-<?= $import_message_type ?>"><?= $import_message ?></div>
        <?php endif; ?>

        <!-- Filter Bar -->
        <div class="filter-bar">
            <div class="filter-group">
                <label><i class="fas fa-search"></i> Search</label>
                <input type="text" id="searchInput" placeholder="Name or Email..." value="<?= htmlspecialchars($search_term ?? '') ?>">
            </div>
            <div class="filter-group">
                <label><i class="fas fa-building"></i> Campus Branch</label>
                <select id="campusSelect">
                    <option value="">All Campuses</option>
                    <?php foreach ($campuses as $campus): ?>
                        <option value="<?= htmlspecialchars($campus ?? '') ?>" <?= $campus_filter == $campus ? 'selected' : '' ?>>
                            <?= htmlspecialchars($campus ?? '') ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="filter-group">
                <label><i class="fas fa-graduation-cap"></i> Course</label>
                <select id="courseSelect">
                    <option value="">All Courses</option>
                    <?php foreach ($courses as $course): ?>
                        <option value="<?= htmlspecialchars($course ?? '') ?>" <?= $course_filter == $course ? 'selected' : '' ?>>
                            <?= htmlspecialchars($course ?? '') ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
        </div>

        <!-- Student List Header -->
        <div class="student-list-header">
            <div class="student-total-count" id="studentTotalCount"><i class="fas fa-users"></i> Total Students: <strong id="studentTotalCountValue"><?= (int) $total_students ?></strong></div>
            <!-- ══════════════════════════════════════════════════════════
                 NEW: toolbar — Add Student, Edit, Delete (checkbox-driven
                 selection flow adopted from admin_company_list.php), and
                 Export to Excel. The old "Delete All Records" (truncate)
                 button has been removed entirely.
                 ══════════════════════════════════════════════════════════ -->
            <div class="header-actions">
                <button id="addStudentBtn" class="add-student-btn">
                    <i class="fas fa-plus-circle"></i> Add Student
                </button>
                <button type="button" class="edit-entry-btn" id="editEntryBtn" title="Click to select one entry to edit">
                    <i class="fas fa-edit"></i> Edit
                </button>
                <button type="button" class="delete-entry-btn" id="deleteEntryBtn" title="Click to select entries to delete">
                    <i class="fas fa-trash-alt"></i> Delete
                </button>
                <button type="button" class="cancel-selection-btn" id="cancelSelectionBtn" style="display:none;">
                    <i class="fas fa-times"></i> Cancel
                </button>
                <a href="?export=xlsx" class="export-btn" id="exportBtn">
                    <i class="fas fa-file-excel"></i> Export to Excel
                </a>
                <!-- NEW (Export exclusion — adopted from admin_company_list.php):
                     shown only while the admin is choosing which students to
                     leave OUT of the export (see enterExportExcludeSelectionMode()
                     in the script below); replaces the plain Export button for
                     the duration of that selection. -->
                <button type="button" class="export-btn" id="exportConfirmBtn" style="display:none;">
                    <i class="fas fa-file-excel"></i> Export (Excluding 0)
                </button>
                <button type="button" class="import-btn" id="importBtn" title="Import students from an XLSX file (up to 100MB)">
                    <i class="fas fa-file-import"></i> Import Students
                </button>
                <!-- Hidden upload form used by the Import Students button (same field name the PHP import handler expects) -->
                <form method="POST" enctype="multipart/form-data" id="importForm" style="display:none;">
                    <input type="file" name="import_file" id="import_file" accept=".xlsx">
                </form>
            </div>
        </div>

        <!-- Auto-filter: search / dropdown / pagination changes swap only this container (no page reload). -->
        <div id="studentTableSection"><?= $table_section_html ?></div><!-- end #studentTableSection -->
    </div><!-- end .container -->
</div><!-- end .main-content -->


<!-- ═══════════════════════════════════════
     Add Student Modal
     ═══════════════════════════════════════ -->
<div id="addStudentModal" class="add-student-modal-overlay">
    <div class="add-student-modal-box">
        <div class="add-student-modal-header">
            <h3><i class="fas fa-user-plus"></i> Add New Student</h3>
            <button class="close-add-student-btn" id="closeAddStudentBtn">&times;</button>
        </div>
        <form method="POST" id="addStudentForm">
            <div class="add-student-grid">
                <div class="form-group">
                    <label>First Name <span class="required">*</span></label>
                    <input type="text" name="first_name" required placeholder="Enter first name">
                </div>
                <div class="form-group">
                    <label>Middle Name</label>
                    <input type="text" name="middle_name" placeholder="Enter middle name (optional)">
                </div>
                <div class="form-group">
                    <label>Last Name <span class="required">*</span></label>
                    <input type="text" name="last_name" required placeholder="Enter last name">
                </div>

                <!-- Course dropdown (mirrors Campus Branch pattern) -->
                <div class="form-group span-2">
                    <label>Course <span class="required">*</span></label>
                    <select name="course" id="courseSelectModal" required>
                        <option value="">Select Course</option>
                        <?php
                        /* NEW (Course Offering): the Add Student dropdown also lists every
                           course from Course Offering (plus the existing course options). */
                        $add_student_course_options = $all_course_options;
                        foreach ($course_offering_map as $offeredCourseName) {
                            $alreadyListed = false;
                            foreach ($add_student_course_options as $existingOpt) {
                                if (student_list_normalize_course($existingOpt) === student_list_normalize_course($offeredCourseName)) { $alreadyListed = true; break; }
                            }
                            if (!$alreadyListed) $add_student_course_options[] = $offeredCourseName;
                        }
                        sort($add_student_course_options, SORT_NATURAL | SORT_FLAG_CASE);
                        ?>
                        <?php if (empty($add_student_course_options)): ?>
                            <option value="other">+ Add New Course (Enter manually)</option>
                        <?php else: ?>
                            <?php foreach ($add_student_course_options as $co): ?>
                                <option value="<?= htmlspecialchars($co ?? '') ?>"><?= htmlspecialchars($co ?? '') ?></option>
                            <?php endforeach; ?>
                            <option value="other">+ Add New Course (Enter manually)</option>
                        <?php endif; ?>
                    </select>
                    <div id="customCourseGroup" class="custom-course-group">
                        <input type="text" name="custom_course" id="customCourse"
                               placeholder="Enter new course (e.g., BSIT, BSCS)">
                    </div>
                    <div class="help-text">
                        <i class="fas fa-info-circle"></i> Select an existing course or choose "+ Add New Course" to enter a new one.
                    </div>
                </div>

                <!-- UPDATED: Major dropdown (mirrors Campus Branch pattern) — required, but "None" is always an option -->
                <div class="form-group">
                    <label>Major <span class="required">*</span></label>
                    <select name="major" id="majorSelectModal" required>
                        <option value="">Select Major</option>
                        <option value="none">None</option>
                        <?php foreach ($all_major_options as $mo): ?>
                            <option value="<?= htmlspecialchars($mo ?? '') ?>"><?= htmlspecialchars($mo ?? '') ?></option>
                        <?php endforeach; ?>
                        <option value="other">+ Add New Major (Enter manually)</option>
                    </select>
                    <div id="customMajorGroup" class="custom-course-group">
                        <input type="text" name="custom_major" id="customMajor" maxlength="150"
                               placeholder="Enter new major">
                    </div>
                    <div class="help-text">
                        <i class="fas fa-info-circle"></i> Select "None" if this course/student has no major, or choose "+ Add New Major" to enter one.
                    </div>
                </div>

                <!-- UPDATED: Section dropdown (mirrors Campus Branch pattern) — now required -->
                <div class="form-group">
                    <label>Section <span class="required">*</span></label>
                    <select name="section" id="sectionSelectModal" required>
                        <option value="">Select Section</option>
                        <?php foreach ($all_section_options as $so): ?>
                            <option value="<?= htmlspecialchars($so ?? '') ?>"><?= htmlspecialchars($so ?? '') ?></option>
                        <?php endforeach; ?>
                        <option value="other">+ Add New Section (Enter manually)</option>
                    </select>
                    <div id="customSectionGroup" class="custom-course-group">
                        <input type="text" name="custom_section" id="customSection"
                               placeholder="Enter new section">
                    </div>
                </div>

                <div class="form-group span-2">
                    <label>Email <span class="required">*</span></label>
                    <input type="email" name="email" required placeholder="student@example.com">
                </div>

                <!-- Campus Branch dropdown -->
                <div class="form-group">
                    <label>Campus Branch <span class="required">*</span></label>
                    <select name="campus_branch" id="campusBranchSelect" required>
                        <option value="">Select Campus Branch</option>
                        <?php if (empty($all_campus_options)): ?>
                            <option value="other">+ Add New Campus (Enter manually)</option>
                        <?php else: ?>
                            <?php foreach ($all_campus_options as $campus_option): ?>
                                <option value="<?= htmlspecialchars($campus_option ?? '') ?>"><?= htmlspecialchars($campus_option ?? '') ?></option>
                            <?php endforeach; ?>
                            <option value="other">+ Add New Campus (Enter manually)</option>
                        <?php endif; ?>
                    </select>
                    <div id="customCampusGroup" class="custom-campus-group">
                        <input type="text" name="custom_campus" id="customCampus"
                               placeholder="Enter new campus name">
                    </div>
                    <div class="help-text">
                        <i class="fas fa-info-circle"></i> Select an existing campus or choose "+ Add New Campus" to enter a new one.
                    </div>
                </div>
            </div>

            <div class="modal-actions">
                <button type="button" class="btn-cancel-modal" id="cancelAddStudentBtn">Cancel</button>
                <button type="submit" name="add_student_manual" class="btn-submit">Add Student</button>
            </div>
        </form>
    </div>
</div>


<!-- ═══════════════════════════════════════
     NEW: Edit Student Modal — opened from the toolbar "Edit" button's
     single-select checkbox flow (mirrors admin_company_list.php's Edit
     Imported Company modal). Submits via fetch() to the edit_student
     handler above; on success the page reloads so the table reflects
     the change.
     ═══════════════════════════════════════ -->
<div id="editStudentModal" class="add-student-modal-overlay">
    <div class="add-student-modal-box">
        <div class="add-student-modal-header">
            <h3><i class="fas fa-user-edit"></i> Edit Student</h3>
            <button class="close-add-student-btn" id="closeEditStudentBtn">&times;</button>
        </div>
        <form method="POST" id="editStudentForm">
            <input type="hidden" name="edit_student_id" id="editStudentId" value="">
            <!-- UPDATED: Edit Student now uses the SAME compact 3-column grid
                 layout as the Add Student modal (add-student-grid + span-2/
                 span-3), instead of the old stacked form-row layout. Field
                 order, ids, names and required/optional-ness are unchanged
                 so nothing server-side or in the populate/validate JS below
                 needs to change — only the markup/layout moved. -->
            <div class="add-student-grid">
                <div class="form-group">
                    <label>First Name <span class="required">*</span></label>
                    <input type="text" name="edit_first_name" id="editFirstName" required placeholder="Enter first name">
                </div>
                <div class="form-group">
                    <label>Middle Name</label>
                    <input type="text" name="edit_middle_name" id="editMiddleName" placeholder="Enter middle name (optional)">
                </div>
                <div class="form-group">
                    <label>Last Name <span class="required">*</span></label>
                    <input type="text" name="edit_last_name" id="editLastName" required placeholder="Enter last name">
                </div>

                <!-- Course dropdown (mirrors Add Student modal) -->
                <div class="form-group span-2">
                    <label>Course <span class="required">*</span></label>
                    <select name="edit_course" id="editCourseSelect" required>
                        <option value="">Select Course</option>
                        <?php foreach ($all_course_options as $co): ?>
                            <option value="<?= htmlspecialchars($co ?? '') ?>"><?= htmlspecialchars($co ?? '') ?></option>
                        <?php endforeach; ?>
                        <option value="other">+ Add New Course (Enter manually)</option>
                    </select>
                    <div id="editCustomCourseGroup" class="custom-course-group">
                        <input type="text" name="edit_custom_course" id="editCustomCourse"
                               placeholder="Enter new course (e.g., BSIT, BSCS)">
                    </div>
                    <div class="help-text">
                        <i class="fas fa-info-circle"></i> Select an existing course or choose "+ Add New Course" to enter a new one.
                    </div>
                </div>

                <!-- UPDATED: Major dropdown (mirrors Add Student modal) — required, but "None" is always an option -->
                <div class="form-group">
                    <label>Major <span class="required">*</span></label>
                    <select name="edit_major" id="editMajorSelect" required>
                        <option value="">Select Major</option>
                        <option value="none">None</option>
                        <?php foreach ($all_major_options as $mo): ?>
                            <option value="<?= htmlspecialchars($mo ?? '') ?>"><?= htmlspecialchars($mo ?? '') ?></option>
                        <?php endforeach; ?>
                        <option value="other">+ Add New Major (Enter manually)</option>
                    </select>
                    <div id="editCustomMajorGroup" class="custom-course-group">
                        <input type="text" name="edit_custom_major" id="editCustomMajor" maxlength="150"
                               placeholder="Enter new major">
                    </div>
                    <div class="help-text">
                        <i class="fas fa-info-circle"></i> Select "None" if this course/student has no major, or choose "+ Add New Major" to enter one.
                    </div>
                </div>

                <!-- UPDATED: Section dropdown (mirrors Add Student modal) — now required -->
                <div class="form-group">
                    <label>Section <span class="required">*</span></label>
                    <select name="edit_section" id="editSectionSelect" required>
                        <option value="">Select Section</option>
                        <?php foreach ($all_section_options as $so): ?>
                            <option value="<?= htmlspecialchars($so ?? '') ?>"><?= htmlspecialchars($so ?? '') ?></option>
                        <?php endforeach; ?>
                        <option value="other">+ Add New Section (Enter manually)</option>
                    </select>
                    <div id="editCustomSectionGroup" class="custom-course-group">
                        <input type="text" name="edit_custom_section" id="editCustomSection"
                               placeholder="Enter new section">
                    </div>
                </div>

                <div class="form-group span-2">
                    <label>Email <span class="required">*</span></label>
                    <input type="email" name="edit_email" id="editEmail" required placeholder="student@example.com">
                </div>

                <!-- Campus Branch dropdown -->
                <div class="form-group">
                    <label>Campus Branch <span class="required">*</span></label>
                    <select name="edit_campus_branch" id="editCampusBranchSelect" required>
                        <option value="">Select Campus Branch</option>
                        <?php foreach ($all_campus_options as $campus_option): ?>
                            <option value="<?= htmlspecialchars($campus_option ?? '') ?>"><?= htmlspecialchars($campus_option ?? '') ?></option>
                        <?php endforeach; ?>
                        <option value="other">+ Add New Campus (Enter manually)</option>
                    </select>
                    <div id="editCustomCampusGroup" class="custom-campus-group">
                        <input type="text" name="edit_custom_campus" id="editCustomCampus"
                               placeholder="Enter new campus name">
                    </div>
                    <div class="help-text">
                        <i class="fas fa-info-circle"></i> Select an existing campus or choose "+ Add New Campus" to enter a new one.
                    </div>
                </div>
            </div>

            <div class="modal-actions">
                <button type="button" class="btn-cancel-modal" id="cancelEditStudentBtn">Cancel</button>
                <button type="submit" name="edit_student" class="btn-submit">Save Changes</button>
            </div>
        </form>
    </div>
</div>



<!-- ══════════════════════════════════════════════════════════
     NEW (Export exclusion — adopted from admin_company_list.php): first
     step of "Export to Excel". "None, Proceed With Export" exports every
     student exactly as before. "Yes" turns on a selection mode over the
     table (the same checkbox column used by Edit / Delete) so the admin
     can check the students to leave OUT of the file — see
     enterExportExcludeSelectionMode() in the script below.
     ══════════════════════════════════════════════════════════ -->
<div id="exportChoiceModal" class="modal-overlay">
    <div class="modal-box" style="width:440px;">
        <button type="button" class="export-choice-close" id="exportChoiceCloseBtn" aria-label="Close" data-cv-tip="Close">&times;</button><!-- NEW (this adjustment): cancels and closes this popup -->
        <div class="modal-icon" style="color:var(--grid-navy);"><i class="fas fa-file-excel"></i></div>
        <p class="modal-title">Export To Excel</p>
        <p class="modal-message" style="text-align:center;">Do you have any students you'd like to exclude from this export?</p>
        <div class="modal-actions">
            <button type="button" class="modal-cancel" id="exportChoiceNoneBtn">None, Proceed With Export</button>
            <button type="button" class="modal-confirm" id="exportChoiceYesBtn" style="background:var(--grid-navy); border-color:var(--grid-navy);">Yes</button>
        </div>
    </div>
</div>

<!-- ══════════════════════════════════════════════════════════
     NEW: Delete Selected confirmation modal — replaces the old
     truncate-table confirmation modal. Triggered by the toolbar
     "Delete" button once one or more entries are checked. Submits via
     fetch() to the delete_selected_students handler above; on success
     the page reloads so the table reflects the change.
     ══════════════════════════════════════════════════════════ -->
<div id="deleteSelectedModal" class="modal-overlay">
    <div class="modal-box">
        <div class="modal-icon"><i class="fas fa-trash-alt"></i></div>
        <p class="modal-title">Confirm Deletion</p>
        <p class="modal-message" id="deleteSelectedMessage">
            Are you sure you want to delete the selected student(s)? This action cannot be undone.
        </p>
        <div class="modal-actions">
            <button type="button" class="modal-cancel" id="deleteSelectedCancelBtn">Cancel</button>
            <button type="button" class="modal-confirm" id="deleteSelectedConfirmBtn">Yes, Delete</button>
        </div>
    </div>
</div>

<!-- ══════════════════════════════════════════════════════════
     NEW (Course Offering): shown when an import file (or a manual Add
     Student) contains a course that is not in Course Offering. The admin
     can skip those students or cancel the import.
     ══════════════════════════════════════════════════════════ -->
<div id="courseCheckModal" class="modal-overlay">
    <div class="modal-box" style="width:520px;">
        <div class="modal-icon" style="color:var(--grid-amber);"><i class="fas fa-exclamation-triangle"></i></div>
        <p class="modal-title">Course Not in Course Offering</p>
        <p class="modal-message" id="courseCheckMessage" style="margin-bottom:14px;"></p>
        <div id="courseCheckList" class="auto-account-detail-block"></div>
        <div class="modal-actions">
            <button type="button" class="modal-cancel" id="courseCheckCancelBtn">Cancel Import</button>
            <button type="button" class="modal-confirm" id="courseCheckSkipBtn" style="background:var(--grid-amber); border-color:var(--grid-amber);">Skip These Students</button>
            <!-- NEW (this adjustment): import only — add the missing course to Course Offering right here, then the import continues -->
            <button type="button" class="modal-confirm" id="courseCheckAddBtn" style="display:none; background:var(--grid-navy); border-color:var(--grid-navy);">Yes, Add Course</button>
        </div>
    </div>
</div>

<!-- ══════════════════════════════════════════════════════════
     NEW (this adjustment): ADD MISSING COURSE DURING AN IMPORT.
     Same form as "Add Course Offering" on course_offering.php (same
     fields, hints and look). It is saved through course_offering.php
     itself (add_course_offering), so the rules, the saved record and
     the activity log entry are exactly the same as adding it there.
     After it is saved the course check runs again and the import
     continues — any other missing course is asked about next.
     ══════════════════════════════════════════════════════════ -->
<div id="importAddCourseModal" class="add-student-modal-overlay">
    <div class="add-student-modal-box"><!-- UPDATED (this adjustment): same design as the manual Add Student form -->
        <div class="add-student-modal-header">
            <h3><i class="fas fa-plus-circle"></i> Add Course Offering</h3>
            <button type="button" class="close-add-student-btn" id="closeImportAddCourseBtn" aria-label="Close">&times;</button>
        </div>
        <p id="importAddCourseNote" class="iac-note"></p>
        <form id="importAddCourseForm" autocomplete="off">
            <div class="add-student-grid">
            <div class="form-group span-3">
                <label>Course <span class="required">*</span></label>
                <input type="text" name="course" id="iacCourse" required maxlength="100" placeholder="e.g., BSIT, BSCS, BSBA">
                <div class="help-text" id="iacCourseHelp"><i class="fas fa-info-circle"></i> Must match the Course written in the student import file (not case-sensitive).</div>
            </div>
            <div class="form-group">
                <label>Total Hour Requirement <span class="required">*</span></label>
                <input type="number" name="total_hours" id="iacTotalHours" required min="1" step="1" placeholder="e.g., 486">
                <div class="help-text"><i class="fas fa-info-circle"></i> Total number of OJT hours a student of this course must complete.</div>
            </div>
            <div class="form-group">
                <label>Required Hours of OJT Duty per Day <span class="required">*</span></label>
                <input type="number" name="daily_hours" id="iacDailyHours" required min="0.5" max="24" step="0.5" placeholder="e.g., 8">
                <div class="help-text"><i class="fas fa-info-circle"></i> Total hours a student must spend on OJT duty in one day.</div>
            </div>
            </div>
            <div class="modal-actions">
                <button type="button" class="btn-cancel-modal" id="cancelImportAddCourseBtn">Cancel</button>
                <button type="submit" class="btn-submit" id="importAddCourseSubmitBtn">Add Course Offering</button>
            </div>
        </form>
    </div>
</div>

<!-- ══════════════════════════════════════════════════════════
     NEW (Campus Branch / Course import filter revision — adopted from
     "importClassificationModal" in admin_company_list.php): shown right
     after a file is chosen for "Import Students", once the read-only
     detect endpoint (detect_student_import_classifications) has reported
     every distinct Campus Branch / Course found in the file. Every box
     starts checked, so clicking "Import Selected" with no changes imports
     the whole file exactly as before. Unchecking a campus or course leaves
     every student of that campus / course out of the import. Cancel
     discards the file and nothing is uploaded.
     ══════════════════════════════════════════════════════════ -->
<div id="studentImportFilterModal" class="modal-overlay">
    <div class="modal-box"><!-- UPDATED (this adjustment): same design as the manual Add Student form (see the scoped styles) -->
        <div class="import-picker-head">
            <h3><i class="fas fa-file-import"></i> Choose What To Import</h3>
            <button type="button" class="import-picker-close" id="studentImportFilterCloseBtn" aria-label="Close" data-cv-tip="Close">&times;</button>
        </div>
        <p class="modal-message" id="studentImportFilterSummary" style="margin-bottom:6px;">This file contains the campus branches and courses below. Only the students whose campus branch and course you keep checked will be imported — everything else in the file will be skipped.</p>
        <div class="import-picker-grid"><!-- NEW (this adjustment): the two lists side by side -->
        <div class="import-classification-group">
            <div class="import-classification-group-head">
                <div class="import-classification-group-label"><i class="fas fa-building"></i> Campus Branch</div>
                <div>
                    <button type="button" class="import-classification-toggle" data-filter-target="importCampusOptions" data-filter-check="1">Select All</button>
                    &nbsp;/&nbsp;
                    <button type="button" class="import-classification-toggle" data-filter-target="importCampusOptions" data-filter-check="0">Clear</button>
                </div>
            </div>
            <div id="importCampusOptions" class="import-classification-options"></div>
        </div>
        <div class="import-classification-group">
            <div class="import-classification-group-head">
                <div class="import-classification-group-label"><i class="fas fa-graduation-cap"></i> Course</div>
                <div>
                    <button type="button" class="import-classification-toggle" data-filter-target="importCourseOptions" data-filter-check="1">Select All</button>
                    &nbsp;/&nbsp;
                    <button type="button" class="import-classification-toggle" data-filter-target="importCourseOptions" data-filter-check="0">Clear</button>
                </div>
            </div>
            <div id="importCourseOptions" class="import-classification-options"></div>
        </div>
        </div>
        <div class="modal-actions" style="margin-top:22px;">
            <button type="button" class="modal-cancel" id="studentImportFilterCancelBtn">Cancel</button>
            <button type="button" class="modal-confirm" id="studentImportFilterConfirmBtn" style="background:var(--grid-navy); border-color:var(--grid-navy);">Import Selected</button>
        </div>
    </div>
</div>

<!-- Toast Notification -->
<div id="toastNotification" class="toast-notification">
    <div class="toast-icon"></div>
    <div class="toast-content">
        <div class="toast-title"></div>
        <div class="toast-message"></div>
    </div>
    <button class="toast-close">&times;</button>
</div>

<!-- ══════════════════════════════════════════════════════════
     NEW: Auto Account Creation Progress popup (adopted from
     admin_company_list.php). Opens automatically after a successful
     XLSX import, or a manual "Add Student" whose account needs
     follow-up. Starts as a spinner, then swaps to a Created / Skipped /
     Failed breakdown with per-student details.
     ══════════════════════════════════════════════════════════ -->
<div id="autoAccountProgressModal" class="modal-overlay">
    <div class="modal-box" style="width:480px;">
        <div class="modal-icon" style="color:var(--grid-navy);"><i class="fas fa-user-plus"></i></div>
        <p class="modal-title">Creating Student Accounts</p>
        <div id="autoAccountProgressBody"></div>
        <div class="modal-actions">
            <button type="button" class="modal-confirm" id="autoAccountProgressCloseBtn" style="background:var(--grid-navy); border-color:var(--grid-navy); display:none;">Done</button>
        </div>
    </div>
</div>

<script>
    /* NEW: queued student keys (UPDATED: session queue, no students_import) / pre-known skips from PHP (empty on normal loads). */
    window.autoCreateAccountImportIds = <?= json_encode(array_values($auto_create_account_import_ids)) ?>;
    window.autoCreateAccountPreSkippedDetails = <?= json_encode(array_values($auto_create_account_pre_skipped_details)) ?>;
    /* NEW (Course Offering): offered courses, used to check a manual Add Student before it is sent. */
    window.courseOfferingList = <?= json_encode(array_values($course_offering_map)) ?>;

    // ── Sidebar toggle ──
    const sb = document.getElementById('sidebar');
    const toggleBtn = document.getElementById('toggleBtn');
    if (toggleBtn) toggleBtn.addEventListener('click', () => sb.classList.toggle('collapsed'));

    /* ══════════════════════════════════════════════════════════
       NEW: Global loading/processing overlay controls — mirrors
       admin_company_list.php's showGlobalLoading()/hideGlobalLoading()
       exactly. showGlobalLoading(label) reveals the popup with an
       optional custom label. hideGlobalLoading() fades it out. A small
       usage counter (globalLoadingActiveCount) makes sure the overlay
       only hides once every in-flight operation that asked for it has
       actually finished, so overlapping calls can never hide it
       prematurely. Purely additive — does not touch the existing
       #loadingOverlay / its own show/hide logic used elsewhere on this
       page for the large-file-import spinner.
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
    /* FIX (loader sometimes not showing): the 'load' handler and the
       4-second safety net used to force the counter to 0 and hide the
       overlay unconditionally — so an action started shortly after the
       page opened (import, add, edit, delete, filter...) had its loader
       hidden while it was still running. Both now only finish the
       INITIAL page-load overlay, once, and never hide the overlay while
       another action is still in progress. */
    let initialPageLoadPending = true;
    function finishInitialPageLoad() {
        if (!initialPageLoadPending) return;
        initialPageLoadPending = false;
        if (globalLoadingActiveCount === 0 && globalLoadingOverlay) {
            globalLoadingOverlay.classList.add('hidden');
        }
    }
    window.addEventListener('load', finishInitialPageLoad);
    if (document.readyState === 'complete') finishInitialPageLoad();
    /* Safety net: if for any reason the 'load' event is delayed (slow
       third-party assets like the Font Awesome CDN), don't leave the
       admin staring at the popup forever — hide it after a short
       ceiling too. */
    setTimeout(finishInitialPageLoad, 4000);

    /* FIX (loader sometimes not showing): show the loader the moment
       this page starts navigating away (sidebar links, reloads after
       edit/delete/settings, form submits), so there is never a blank,
       unresponsive gap before the next page paints its own loader.
       The Export download doesn't leave the page, so it is skipped. */
    let skipUnloadLoadingOverlay = false;
    window.addEventListener('beforeunload', function() {
        if (skipUnloadLoadingOverlay) return;
        if (globalSuccessShown) return; // UPDATED: keep the check + message visible while the page reloads
        // UPDATED (this adjustment — no redundant loading page): a loader already on screen (e.g. "Importing students")
        // is kept as it is instead of being replaced by a second, generic "Loading" one.
        if (globalLoadingOverlay && !globalLoadingOverlay.classList.contains('hidden')) return;
        if (globalLoadingLabel) globalLoadingLabel.textContent = 'Loading';
        if (globalLoadingOverlay) globalLoadingOverlay.classList.remove('hidden');
    });
    /* When the browser restores this page from its back/forward cache,
       scripts don't re-run, so clear any loader left over from leaving. */
    window.addEventListener('pageshow', function(e) {
        if (e.persisted) {
            initialPageLoadPending = false;
            globalLoadingActiveCount = 0;
            globalSuccessShown = false; // UPDATED (success loading page)
            if (globalLoadingOverlay) globalLoadingOverlay.classList.remove('success-state');
            if (globalLoadingOverlay) globalLoadingOverlay.classList.add('hidden');
        }
    });

    /* ══════════════════════════════════════════════════════════
       FIX (previous import re-triggering after Edit / Delete): after an
       XLSX import (or any other normal form POST) this page is the
       result of that POST. The old window.location.reload() used after a
       successful Edit / Delete re-sent that same POST — so the import
       ran again. Two guards now prevent it:
         1. When this page was produced by a POST, its history entry is
            turned into a plain GET entry right away, so any reload
            (including the browser's own refresh button) no longer
            re-submits the import.
         2. Edit / Delete now refresh with a fresh GET navigation to the
            same URL (reloadStudentListAsGet) instead of reload().
       ══════════════════════════════════════════════════════════ */
    <?php if ($_SERVER['REQUEST_METHOD'] === 'POST'): ?>
    if (window.history && history.replaceState) {
        history.replaceState(null, '', window.location.pathname + window.location.search);
    }
    <?php endif; ?>
    function reloadStudentListAsGet() {
        window.location.replace(window.location.pathname + window.location.search);
    }

    /* UPDATED (success loading page — same as course_offering.php): turn
       the page loader into a check icon + action message instead of a
       popup (toast) notification, then refresh the list. */
    let globalSuccessShown = false;
    function showGlobalSuccess(title, message, reloadDelay, refreshPage) {   // UPDATED (this adjustment): refreshPage = which table page to show after it
        globalSuccessShown = true;
        const t = document.getElementById('globalLoadingSuccessTitle');
        const m = document.getElementById('globalLoadingSuccessMsg');
        const plain = document.createElement('div');
        plain.innerHTML = String(message || '').replace(/<br\s*\/?>/gi, ' ');
        if (t) t.textContent = title || 'Success';
        if (m) m.textContent = (plain.textContent || '').replace(/\s+/g, ' ').trim();
        if (globalLoadingOverlay) {
            globalLoadingOverlay.classList.add('success-state');
            globalLoadingOverlay.classList.remove('hidden');
        }
        /* UPDATED (this adjustment — same as admin_company_list.php): the list is refreshed IN PLACE behind
           this success screen instead of reloading the whole page, so no second loading page appears after
           it. The success screen stays for the same time, then releases the action's own loader ("Saving
           changes" / "Deleting entries") and fades straight into the updated list. If the in-place refresh
           is not available for some reason, the page is reloaded exactly as before. */
        if (typeof fetchStudentTable === 'function' && document.getElementById('studentTableSection')) {
            fetchStudentTable(refreshPage || parseInt(new URLSearchParams(window.location.search).get('page'), 10) || 1, true);   // quiet: covered by the success screen
            setTimeout(function () {
                globalSuccessShown = false;
                hideGlobalLoading();
                setTimeout(function () {
                    if (globalLoadingOverlay && globalLoadingOverlay.classList.contains('hidden')) globalLoadingOverlay.classList.remove('success-state');
                }, 400);
            }, reloadDelay || 1600);
            return;
        }
        setTimeout(reloadStudentListAsGet, reloadDelay || 1600);
    }

    /* ══════════════════════════════════════════════════════════
       NEW (Export result screen — same as admin_company_list.php):
       showGlobalResult(type, title, message, autoHideMs)
         - type: 'success' (green check) or 'error' (red X)
         - autoHideMs: close automatically after this many ms (0 = stay
           open until the admin clicks OK).
       hideGlobalResult() closes it. Completely separate from the loading
       overlay's usage counter above, so it never interferes with it.
       ══════════════════════════════════════════════════════════ */
    const globalResultOverlay = document.getElementById('globalResultOverlay');
    const globalResultIcon    = document.getElementById('globalResultIcon');
    const globalResultTitle   = document.getElementById('globalResultTitle');
    const globalResultMessage = document.getElementById('globalResultMessage');
    const globalResultOkBtn   = document.getElementById('globalResultOkBtn');
    let globalResultHideTimer = null;

    function hideGlobalResult() {
        if (globalResultHideTimer) { clearTimeout(globalResultHideTimer); globalResultHideTimer = null; }
        if (globalResultOverlay) globalResultOverlay.classList.add('hidden');
    }

    function showGlobalResult(type, title, message, autoHideMs) {
        if (!globalResultOverlay) {
            // Fallback: should never happen, but never let a result go unreported.
            alert((title ? title + '\n\n' : '') + (message || ''));
            return;
        }
        const isSuccess = type === 'success';
        if (globalResultHideTimer) { clearTimeout(globalResultHideTimer); globalResultHideTimer = null; }
        globalResultOverlay.classList.toggle('is-success', isSuccess);
        globalResultOverlay.classList.toggle('is-error', !isSuccess);
        if (globalResultIcon) globalResultIcon.className = isSuccess ? 'fas fa-check' : 'fas fa-times';
        if (globalResultTitle) globalResultTitle.textContent = title || '';
        if (globalResultMessage) globalResultMessage.textContent = message || '';
        globalResultOverlay.classList.remove('hidden');
        if (globalResultOkBtn) { try { globalResultOkBtn.focus(); } catch (e) {} }
        if (autoHideMs && autoHideMs > 0) {
            globalResultHideTimer = setTimeout(hideGlobalResult, autoHideMs);
        }
    }

    if (globalResultOkBtn) globalResultOkBtn.addEventListener('click', hideGlobalResult);
    document.addEventListener('keydown', function(e) {
        if (e.key === 'Escape' && globalResultOverlay && !globalResultOverlay.classList.contains('hidden')) hideGlobalResult();
    });

    // ── Toast ──
    let toastTimeout = null, toastCloseHandler = null;
    function showToast(title, message, type = 'success') {
        const toast    = document.getElementById('toastNotification');
        const iconDiv  = toast.querySelector('.toast-icon');
        const titleDiv = toast.querySelector('.toast-title');
        const msgDiv   = toast.querySelector('.toast-message');
        const closeBtn = toast.querySelector('.toast-close');
        if (toastTimeout) { clearTimeout(toastTimeout); toastTimeout = null; }
        if (toastCloseHandler) { closeBtn.removeEventListener('click', toastCloseHandler); toastCloseHandler = null; }
        if (type === 'success')    iconDiv.innerHTML = '<i class="fas fa-check-circle"       style="color:#2C5A2C;font-size:22px;"></i>';
        else if (type === 'error') iconDiv.innerHTML = '<i class="fas fa-exclamation-circle" style="color:#A02A2A;font-size:22px;"></i>';
        else                       iconDiv.innerHTML = '<i class="fas fa-exclamation-triangle" style="color:#A0850A;font-size:22px;"></i>';
        toast.className = 'toast-notification ' + type;
        titleDiv.textContent = title;
        msgDiv.textContent   = message;
        toastCloseHandler = () => { toast.classList.remove('show'); if (toastTimeout) { clearTimeout(toastTimeout); toastTimeout = null; } };
        closeBtn.addEventListener('click', toastCloseHandler);
        setTimeout(() => toast.classList.add('show'), 10);
        toastTimeout = setTimeout(() => { toast.classList.remove('show'); toastTimeout = null; }, 4000);
    }

    const fileInput2   = document.getElementById('import_file');

    // ── Course dropdown toggle (Add Student modal) ──
    const courseSelectModal = document.getElementById('courseSelectModal');
    const customCourseGroup = document.getElementById('customCourseGroup');
    const customCourseInput = document.getElementById('customCourse');
    function toggleCustomCourseInput() {
        if (!courseSelectModal || !customCourseGroup) return;
        if (courseSelectModal.value === 'other') {
            customCourseGroup.classList.add('show');
            customCourseInput.setAttribute('required', 'required');
        } else {
            customCourseGroup.classList.remove('show');
            customCourseInput.removeAttribute('required');
            customCourseInput.value = '';
        }
    }
    if (courseSelectModal) courseSelectModal.addEventListener('change', toggleCustomCourseInput);

    // ── Campus dropdown toggle (Add Student modal) ──
    const campusBranchSelect = document.getElementById('campusBranchSelect');
    const customCampusGroup  = document.getElementById('customCampusGroup');
    const customCampusInput  = document.getElementById('customCampus');
    function toggleCustomCampusInput() {
        if (!campusBranchSelect || !customCampusGroup) return;
        if (campusBranchSelect.value === 'other') {
            customCampusGroup.classList.add('show');
            customCampusInput.setAttribute('required', 'required');
        } else {
            customCampusGroup.classList.remove('show');
            customCampusInput.removeAttribute('required');
            customCampusInput.value = '';
        }
    }
    if (campusBranchSelect) campusBranchSelect.addEventListener('change', toggleCustomCampusInput);

    // ── Major dropdown toggle (Add Student modal) ──
    const majorSelectModal = document.getElementById('majorSelectModal');
    const customMajorGroup = document.getElementById('customMajorGroup');
    const customMajorInput = document.getElementById('customMajor');
    function toggleCustomMajorInput() {
        if (!majorSelectModal || !customMajorGroup) return;
        if (majorSelectModal.value === 'other') {
            customMajorGroup.classList.add('show');
            customMajorInput.setAttribute('required', 'required');
        } else {
            customMajorGroup.classList.remove('show');
            customMajorInput.removeAttribute('required');
            customMajorInput.value = '';
        }
    }
    if (majorSelectModal) majorSelectModal.addEventListener('change', toggleCustomMajorInput);

    // ── Section dropdown toggle (Add Student modal) ──
    const sectionSelectModal = document.getElementById('sectionSelectModal');
    const customSectionGroup = document.getElementById('customSectionGroup');
    const customSectionInput = document.getElementById('customSection');
    function toggleCustomSectionInput() {
        if (!sectionSelectModal || !customSectionGroup) return;
        if (sectionSelectModal.value === 'other') {
            customSectionGroup.classList.add('show');
            customSectionInput.setAttribute('required', 'required');
        } else {
            customSectionGroup.classList.remove('show');
            customSectionInput.removeAttribute('required');
            customSectionInput.value = '';
        }
    }
    if (sectionSelectModal) sectionSelectModal.addEventListener('change', toggleCustomSectionInput);

    // ── Course dropdown toggle (Edit Student modal) ──
    const editCourseSelect = document.getElementById('editCourseSelect');
    const editCustomCourseGroup = document.getElementById('editCustomCourseGroup');
    const editCustomCourseInput = document.getElementById('editCustomCourse');
    function toggleEditCustomCourseInput() {
        if (!editCourseSelect || !editCustomCourseGroup) return;
        if (editCourseSelect.value === 'other') {
            editCustomCourseGroup.classList.add('show');
            editCustomCourseInput.setAttribute('required', 'required');
        } else {
            editCustomCourseGroup.classList.remove('show');
            editCustomCourseInput.removeAttribute('required');
            editCustomCourseInput.value = '';
        }
    }
    if (editCourseSelect) editCourseSelect.addEventListener('change', toggleEditCustomCourseInput);

    // ── Campus dropdown toggle (Edit Student modal) ──
    const editCampusBranchSelect = document.getElementById('editCampusBranchSelect');
    const editCustomCampusGroup  = document.getElementById('editCustomCampusGroup');
    const editCustomCampusInput  = document.getElementById('editCustomCampus');
    function toggleEditCustomCampusInput() {
        if (!editCampusBranchSelect || !editCustomCampusGroup) return;
        if (editCampusBranchSelect.value === 'other') {
            editCustomCampusGroup.classList.add('show');
            editCustomCampusInput.setAttribute('required', 'required');
        } else {
            editCustomCampusGroup.classList.remove('show');
            editCustomCampusInput.removeAttribute('required');
            editCustomCampusInput.value = '';
        }
    }
    if (editCampusBranchSelect) editCampusBranchSelect.addEventListener('change', toggleEditCustomCampusInput);

    // ── Major dropdown toggle (Edit Student modal) ──
    const editMajorSelect = document.getElementById('editMajorSelect');
    const editCustomMajorGroup = document.getElementById('editCustomMajorGroup');
    const editCustomMajorInput = document.getElementById('editCustomMajor');
    function toggleEditCustomMajorInput() {
        if (!editMajorSelect || !editCustomMajorGroup) return;
        if (editMajorSelect.value === 'other') {
            editCustomMajorGroup.classList.add('show');
            editCustomMajorInput.setAttribute('required', 'required');
        } else {
            editCustomMajorGroup.classList.remove('show');
            editCustomMajorInput.removeAttribute('required');
            editCustomMajorInput.value = '';
        }
    }
    if (editMajorSelect) editMajorSelect.addEventListener('change', toggleEditCustomMajorInput);

    // ── Section dropdown toggle (Edit Student modal) ──
    const editSectionSelect = document.getElementById('editSectionSelect');
    const editCustomSectionGroup = document.getElementById('editCustomSectionGroup');
    const editCustomSectionInput = document.getElementById('editCustomSection');
    function toggleEditCustomSectionInput() {
        if (!editSectionSelect || !editCustomSectionGroup) return;
        if (editSectionSelect.value === 'other') {
            editCustomSectionGroup.classList.add('show');
            editCustomSectionInput.setAttribute('required', 'required');
        } else {
            editCustomSectionGroup.classList.remove('show');
            editCustomSectionInput.removeAttribute('required');
            editCustomSectionInput.value = '';
        }
    }
    if (editSectionSelect) editSectionSelect.addEventListener('change', toggleEditCustomSectionInput);

    // ── Import Students button → file picker → auto-import ──
    const importForm     = document.getElementById('importForm');
    const importBtn      = document.getElementById('importBtn');
    if (importBtn && fileInput2) {
        importBtn.addEventListener('click', function() { fileInput2.click(); });
    }
    if (fileInput2) {
        fileInput2.addEventListener('change', function() {
            if (!this.files || !this.files[0]) return;
            const file = this.files[0], ext = file.name.split('.').pop().toLowerCase(), sizeMB = (file.size/(1024*1024)).toFixed(2);
            if (ext !== 'xlsx') { showToast('Invalid File Type', 'Only XLSX files are allowed.', 'error'); this.value = ''; return; }
            if (file.size > 100*1024*1024) { showToast('File Too Large', 'Max 100MB. Your file is ' + sizeMB + 'MB.', 'error'); this.value = ''; return; }
            /* NEW (Campus Branch / Course import filter revision): first
               ask the admin which campus branches / courses from this file
               to import. The Course Offering check below runs right after
               the picker is confirmed. */
            clearStudentImportFilterInputs();
            runStudentImportFilterDetection();
        });
    }

    /* NEW (Course Offering): check the file's courses first; only
       continue to the real import when every course is offered,
       or after the admin chooses to skip the students whose
       course is not offered.
       UPDATED (Campus Branch / Course import filter revision): moved into
       its own function (logic unchanged) so it now runs after the import
       filter picker is confirmed. The FormData now also carries the
       picker's selection, so only the selected students are checked. */
    function runStudentImportCourseCheck() {
        showGlobalLoading('Checking courses');
        const checkData = new FormData(importForm);
        checkData.append('check_import_courses', '1');
        fetch(window.location.pathname, { method: 'POST', headers: { 'X-Requested-With': 'XMLHttpRequest' }, body: checkData })
            .then(r => r.json())
            .then(data => {
                hideGlobalLoading();
                if (!data || !data.success || !data.unknown || data.unknown.length === 0) {
                    proceedStudentImport(false);
                    return;
                }
                openCourseCheckForImport(data);
            })
            .catch(() => {
                // The server still enforces Course Offering during the real import.
                hideGlobalLoading();
                proceedStudentImport(false);
            });
    }

    /* ══════════════════════════════════════════════════════════
       NEW (Campus Branch / Course import filter revision — adopted from
       the Company Type / Request Type import picker in
       admin_company_list.php). Choosing a file sends it to the read-only
       detect endpoint (detect_student_import_classifications), which
       returns every Campus Branch / Course found in the file. They are
       shown as checkboxes in "studentImportFilterModal" (all checked by
       default). On "Import Selected" the selection is added to the hidden
       import form as selected_campus_branches[] / selected_courses[], and
       the existing Course Offering check → import flow continues as
       before. Cancel discards the file.
       ══════════════════════════════════════════════════════════ */
    const studentImportFilterModal      = document.getElementById('studentImportFilterModal');
    const studentImportFilterSummary    = document.getElementById('studentImportFilterSummary');
    const importCampusOptions           = document.getElementById('importCampusOptions');
    const importCourseOptions           = document.getElementById('importCourseOptions');
    const studentImportFilterCancelBtn  = document.getElementById('studentImportFilterCancelBtn');
    const studentImportFilterConfirmBtn = document.getElementById('studentImportFilterConfirmBtn');

    function clearStudentImportFilterInputs() {
        if (!importForm) return;
        importForm.querySelectorAll('.import-filter-hidden-input').forEach(function(el) { el.remove(); });
    }

    function buildStudentImportFilterCheckboxes(container, items, isCourse) {
        if (!container) return;
        container.innerHTML = '';
        (items || []).forEach(function(item) {
            const label = document.createElement('label');
            label.className = 'import-classification-option';
            const box = document.createElement('input');
            box.type = 'checkbox';
            box.checked = true;
            box.value = item.value;
            const text = document.createElement('span');
            text.className = 'opt-label';
            text.textContent = item.label;
            label.appendChild(box);
            label.appendChild(text);
            if (isCourse && item.offered === false) {
                const flag = document.createElement('span');
                flag.className = 'opt-flag';
                flag.textContent = 'Not in Course Offering';
                label.appendChild(flag);
            }
            const count = document.createElement('span');
            count.className = 'opt-count';
            const n = parseInt(item.count, 10) || 0;
            count.textContent = n + ' student' + (n === 1 ? '' : 's');
            label.appendChild(count);
            container.appendChild(label);
        });
    }

    function closeStudentImportFilterModal() {
        if (studentImportFilterModal) studentImportFilterModal.style.display = 'none';
    }

    function resetStudentImportFilterPicker() {
        closeStudentImportFilterModal();
        clearStudentImportFilterInputs();
        if (fileInput2) fileInput2.value = '';
    }

    function runStudentImportFilterDetection() {
        showGlobalLoading('Reading file');
        const detectData = new FormData(importForm);
        detectData.append('detect_student_import_classifications', '1');
        fetch(window.location.pathname, { method: 'POST', headers: { 'X-Requested-With': 'XMLHttpRequest' }, body: detectData })
            .then(r => r.json())
            .then(data => {
                hideGlobalLoading();
                if (!data || !data.success) {
                    showToast('Import Failed', (data && data.message) ? data.message : 'Could not read the selected file.', 'error');
                    resetStudentImportFilterPicker();
                    return;
                }
                if (!data.row_count) {
                    // Nothing to choose from (e.g. every row is missing required data) —
                    // continue with the normal flow so the importer reports it as before.
                    runStudentImportCourseCheck();
                    return;
                }
                buildStudentImportFilterCheckboxes(importCampusOptions, data.campuses, false);
                buildStudentImportFilterCheckboxes(importCourseOptions, data.courses, true);
                if (studentImportFilterSummary) {
                    const rowCount = data.row_count || 0;
                    studentImportFilterSummary.textContent = 'This file has ' + rowCount + ' student' + (rowCount === 1 ? '' : 's') + ' across the campus branches and courses below. Only the students whose campus branch and course you keep checked will be imported — everything else in the file will be skipped.';
                }
                if (studentImportFilterModal) studentImportFilterModal.style.display = 'flex';
            })
            .catch(() => {
                hideGlobalLoading();
                showToast('Import Failed', 'Something went wrong while reading the file. Please try again.', 'error');
                resetStudentImportFilterPicker();
            });
    }

    document.querySelectorAll('.import-classification-toggle').forEach(function(btn) {
        btn.addEventListener('click', function() {
            const target = document.getElementById(this.dataset.filterTarget);
            if (!target) return;
            const check = this.dataset.filterCheck === '1';
            target.querySelectorAll('input[type="checkbox"]').forEach(function(box) { box.checked = check; });
        });
    });

    // NEW (this adjustment): the header's × does exactly what Cancel does
    const studentImportFilterCloseBtn = document.getElementById('studentImportFilterCloseBtn');
    if (studentImportFilterCloseBtn && studentImportFilterCancelBtn) studentImportFilterCloseBtn.addEventListener('click', function() { studentImportFilterCancelBtn.click(); });
    if (studentImportFilterCancelBtn) {
        studentImportFilterCancelBtn.addEventListener('click', function() {
            resetStudentImportFilterPicker();
        });
    }

    if (studentImportFilterConfirmBtn) {
        studentImportFilterConfirmBtn.addEventListener('click', function() {
            const checkedCampuses = importCampusOptions ? Array.from(importCampusOptions.querySelectorAll('input[type="checkbox"]:checked')) : [];
            const checkedCourses  = importCourseOptions ? Array.from(importCourseOptions.querySelectorAll('input[type="checkbox"]:checked')) : [];

            if (checkedCampuses.length === 0 || checkedCourses.length === 0) {
                showToast('Nothing Selected', 'Please keep at least one Campus Branch and one Course checked, or click Cancel to discard this import.', 'error');
                return;
            }

            // Clear any hidden filter inputs left over from a previous attempt.
            clearStudentImportFilterInputs();

            checkedCampuses.forEach(function(box) {
                const hidden = document.createElement('input');
                hidden.type = 'hidden';
                hidden.name = 'selected_campus_branches[]';
                hidden.value = box.value;
                hidden.className = 'import-filter-hidden-input';
                importForm.appendChild(hidden);
            });
            checkedCourses.forEach(function(box) {
                const hidden = document.createElement('input');
                hidden.type = 'hidden';
                hidden.name = 'selected_courses[]';
                hidden.value = box.value;
                hidden.className = 'import-filter-hidden-input';
                importForm.appendChild(hidden);
            });

            closeStudentImportFilterModal();
            runStudentImportCourseCheck();
        });
    }

    function proceedStudentImport(skipUnknownCourses) {
        const oldFlag = importForm.querySelector('input[name="skip_unknown_courses"]');
        if (oldFlag) oldFlag.remove();
        if (skipUnknownCourses) {
            const flag = document.createElement('input');
            flag.type = 'hidden';
            flag.name = 'skip_unknown_courses';
            flag.value = '1';
            importForm.appendChild(flag);
        }
        showGlobalLoading('Importing students'); // UPDATED: "File Selected" toast removed; company-style page loader
        if (importBtn) {
            importBtn.disabled = true;
            importBtn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Processing...';
        }
        importForm.submit();
    }

    // ── NEW (Course Offering): "Course Not in Course Offering" popup ──
    const courseCheckModal     = document.getElementById('courseCheckModal');
    const courseCheckMessage   = document.getElementById('courseCheckMessage');
    const courseCheckList      = document.getElementById('courseCheckList');
    const courseCheckCancelBtn = document.getElementById('courseCheckCancelBtn');
    const courseCheckSkipBtn   = document.getElementById('courseCheckSkipBtn');
    let courseCheckMode = null; // 'import' | 'manual'
    const courseCheckAddBtn = document.getElementById('courseCheckAddBtn');   // NEW (this adjustment)
    let importMissingCourseData = null;                                          // NEW (this adjustment): last course-check result during an import

    function courseCheckEscape(t) {
        const d = document.createElement('div');
        d.textContent = t == null ? '' : String(t);
        return d.innerHTML;
    }

    function openCourseCheckForImport(data) {
        courseCheckMode = 'import';
        const n = data.unknown.length;
        let msg = '<strong>' + n + '</strong> student' + (n === 1 ? ' has a course' : 's have courses') + ' that ' + (n === 1 ? 'is' : 'are') + ' not in Course Offering';
        if (data.unknown_courses && data.unknown_courses.length) {
            msg += ' (' + data.unknown_courses.map(c => '<strong>' + courseCheckEscape(c) + '</strong>').join(', ') + ')';
        }
        /* UPDATED (this adjustment): the import now pauses here and asks whether to ADD the missing course.
           Yes -> the Add Course Offering form (then the import continues); No -> these students are skipped. */
        importMissingCourseData = data;
        const missingCourses = (data.unknown_courses || []).length;
        msg += '. Do you want to add the missing course' + (missingCourses === 1 ? '' : 's') + ' to Course Offering now? ' +
               'Choose <strong>No</strong> to skip ' + (n === 1 ? 'this student' : 'these students') + ' and import the rest.';
        if (!data.valid_count) msg += '<br><em>No other students in this file can be imported, so skipping will import nobody.</em>';
        courseCheckMessage.innerHTML = msg;
        courseCheckList.innerHTML = '<strong style="color:var(--grid-amber);">Students with a missing course</strong><ul>' +   // UPDATED (this adjustment): skipped only if "No" is chosen
            data.unknown.map(u => '<li>Row ' + u.row + ': ' + courseCheckEscape(u.name) + ' — ' + courseCheckEscape(u.course) + '</li>').join('') + '</ul>';
        courseCheckCancelBtn.textContent = 'Cancel Import';
        courseCheckSkipBtn.textContent = n === 1 ? 'No, Skip This Student' : 'No, Skip These Students';   // UPDATED (this adjustment)
        if (courseCheckAddBtn) courseCheckAddBtn.style.display = '';                                        // NEW (this adjustment)
        courseCheckSkipBtn.style.display = '';                                                              // NEW (this adjustment): Skip is hidden only in the manual Add Student popup
        courseCheckModal.style.display = 'flex';
    }

    let manualMissingCourse = '';   // NEW (this adjustment): course of the manual Add Student that is not in Course Offering yet
    function openCourseCheckForManual(courseName) {
        /* UPDATED (this adjustment): the manual Add Student popup is now  Cancel / Yes, Add Course.
           Yes -> the Add Course Offering form (then the student is added automatically); Cancel -> back to the Add Student form. */
        manualMissingCourse = String(courseName || '').trim();
        courseCheckMode = 'manual';
        courseCheckMessage.innerHTML = 'The course <strong>' + courseCheckEscape(courseName) + '</strong> is not in Course Offering. Do you want to add it to Course Offering now and continue adding this student?';
        courseCheckList.innerHTML = '';
        courseCheckCancelBtn.textContent = 'Cancel';
        courseCheckSkipBtn.style.display = 'none';
        if (courseCheckAddBtn) { courseCheckAddBtn.textContent = 'Yes, Add Course'; courseCheckAddBtn.style.display = ''; }
        courseCheckModal.style.display = 'flex';
    }

    function closeCourseCheckModal() {
        if (courseCheckModal) courseCheckModal.style.display = 'none';
    }

    if (courseCheckCancelBtn) {
        courseCheckCancelBtn.addEventListener('click', function() {
            closeCourseCheckModal();
            if (courseCheckMode === 'import') {
                if (fileInput2) fileInput2.value = '';
                // UPDATED: the "Import Cancelled" popup notification was removed.
            }
            // 'manual': just return to the Add Student form so the course can be changed.
            courseCheckMode = null;
        });
    }
    if (courseCheckSkipBtn) {
        courseCheckSkipBtn.addEventListener('click', function() {
            closeCourseCheckModal();
            if (courseCheckMode === 'import') {
                proceedStudentImport(true);
            } else if (courseCheckMode === 'manual') {
                closeAddStudentModal();
                // UPDATED: the "Student Skipped" popup notification was removed.
            }
            courseCheckMode = null;
        });
    }


    /* ════════════════════════════════════════════════════════
       NEW (this adjustment): ADD THE MISSING COURSE DURING AN IMPORT.
       "Yes, Add Course" opens the Add Course Offering form with the
       missing course already filled in. Saving goes through
       course_offering.php's own add_course_offering (same rules, same
       record, same activity log). Then the course check runs again:
       if another course is still missing the admin is asked again,
       otherwise the import continues with these students included.
       Cancel / × on the form goes back to the question, where the
       admin can still choose No (skip) or Cancel Import.
       ════════════════════════════════════════════════════════ */
    const importAddCourseModal  = document.getElementById('importAddCourseModal');
    const importAddCourseForm   = document.getElementById('importAddCourseForm');
    const importAddCourseNote   = document.getElementById('importAddCourseNote');
    const iacCourse             = document.getElementById('iacCourse');
    const iacSubmitBtn          = document.getElementById('importAddCourseSubmitBtn');

    /* NEW (this adjustment): manual Add Student — lock the course to the one entered for the student so that,
       once saved, the student's course is guaranteed to be in Course Offering. */
    const iacCourseHelp = document.getElementById('iacCourseHelp');
    const iacCourseHelpDefault = iacCourseHelp ? iacCourseHelp.innerHTML : '';
    function setIacCourseLocked(locked) {
        if (iacCourse) iacCourse.readOnly = !!locked;
        if (iacCourseHelp) iacCourseHelp.innerHTML = locked
            ? '<i class="fas fa-info-circle"></i> This is the course entered for the student you are adding.'
            : iacCourseHelpDefault;
    }
    function openManualAddCourseForm() {
        if (importAddCourseForm) importAddCourseForm.reset();
        setIacCourseLocked(true);
        if (iacCourse) iacCourse.value = manualMissingCourse;
        if (importAddCourseNote) {
            importAddCourseNote.innerHTML = '<i class="fas fa-pause-circle"></i> The student has not been added yet. After saving this course, the student is added automatically.';
        }
        if (importAddCourseModal) importAddCourseModal.style.display = 'flex';
        setTimeout(function() { const t = document.getElementById('iacTotalHours'); if (t) t.focus(); }, 50);
    }

    function openImportAddCourseForm() {
        const data = importMissingCourseData || {};
        const courses = data.unknown_courses || [];
        const course = courses.length ? courses[0] : '';
        const key = String(course).replace(/\s+/g, ' ').trim().toLowerCase();
        const uses = (data.unknown || []).filter(function(u) { return String(u.course).replace(/\s+/g, ' ').trim().toLowerCase() === key; }).length;
        if (importAddCourseForm) importAddCourseForm.reset();
        setIacCourseLocked(false);   // NEW (this adjustment): import keeps the course editable, exactly as before
        if (iacCourse) iacCourse.value = course;
        if (importAddCourseNote) {
            importAddCourseNote.innerHTML = '<i class="fas fa-pause-circle"></i> The import is paused. ' +
                (uses ? '<strong>' + uses + '</strong> student' + (uses === 1 ? '' : 's') + ' in your file use' + (uses === 1 ? 's' : '') + ' this course. ' : '') +
                (courses.length > 1 ? '(Missing course 1 of ' + courses.length + ' — you will be asked about the next one after saving.) ' : '') +
                'After saving, the import continues.';
        }
        if (importAddCourseModal) importAddCourseModal.style.display = 'flex';
        setTimeout(function() { const t = document.getElementById('iacTotalHours'); if (t) t.focus(); }, 50);
    }
    function closeImportAddCourseForm(backToQuestion) {
        if (importAddCourseModal) importAddCourseModal.style.display = 'none';
        if (backToQuestion && courseCheckMode === 'manual') { openCourseCheckForManual(manualMissingCourse); return; }   // NEW (this adjustment)
        if (backToQuestion && importMissingCourseData) openCourseCheckForImport(importMissingCourseData);
    }
    if (courseCheckAddBtn) {
        courseCheckAddBtn.addEventListener('click', function() {
            closeCourseCheckModal();
            if (courseCheckMode === 'manual') openManualAddCourseForm();   // NEW (this adjustment): manual Add Student
            else openImportAddCourseForm();
        });
    }
    ['closeImportAddCourseBtn', 'cancelImportAddCourseBtn'].forEach(function(id) {
        const b = document.getElementById(id);
        if (b) b.addEventListener('click', function() { closeImportAddCourseForm(true); });
    });
    /* NEW (this adjustment): after the missing course is saved, the loading page that says "Adding course
       offering" turns into the success screen ("Course Offering Added" + the course, "Continuing the
       import..."), then — on the same loading page — continues into "Checking courses", and from there to
       the next missing-course question or the import itself. One continuous loading page, no popup. */
    function showImportCourseAddedAndContinue(savedCourse) {
        const t = document.getElementById('globalLoadingSuccessTitle');
        const m = document.getElementById('globalLoadingSuccessMsg');
        const sub = globalLoadingOverlay ? globalLoadingOverlay.querySelector('.gls-sub') : null;
        const subHtml = sub ? sub.innerHTML : null;
        if (t) t.textContent = 'Course Offering Added';
        if (m) m.textContent = savedCourse ? savedCourse + ' was added to Course Offering.' : 'The course was added to Course Offering.';
        if (sub) sub.innerHTML = '<i class="fas fa-sync-alt fa-spin"></i> Continuing the import...';
        if (globalLoadingOverlay) { globalLoadingOverlay.classList.add('success-state'); globalLoadingOverlay.classList.remove('hidden'); }
        setTimeout(function () {
            if (globalLoadingOverlay) globalLoadingOverlay.classList.remove('success-state');
            if (sub && subHtml !== null) sub.innerHTML = subHtml;
            runStudentImportCourseCheck();   // shows "Checking courses" on the same loading page, then the next step
            hideGlobalLoading();             // releases "Adding course offering" — "Checking courses" keeps the page up
        }, 1600);
    }
    /* NEW (this adjustment): manual Add Student — after the missing course is saved, the same loading page shows
       "Course Offering Added" and then the Add Student form is submitted automatically with the values already
       typed in, so the student is added without the admin re-entering anything. */
    function showManualCourseAddedAndContinue(savedCourse) {
        const t = document.getElementById('globalLoadingSuccessTitle');
        const m = document.getElementById('globalLoadingSuccessMsg');
        const sub = globalLoadingOverlay ? globalLoadingOverlay.querySelector('.gls-sub') : null;
        const subHtml = sub ? sub.innerHTML : null;
        if (t) t.textContent = 'Course Offering Added';
        if (m) m.textContent = savedCourse ? savedCourse + ' was added to Course Offering.' : 'The course was added to Course Offering.';
        if (sub) sub.innerHTML = '<i class="fas fa-sync-alt fa-spin"></i> Adding the student...';
        if (globalLoadingOverlay) { globalLoadingOverlay.classList.add('success-state'); globalLoadingOverlay.classList.remove('hidden'); }
        setTimeout(function () {
            if (globalLoadingOverlay) globalLoadingOverlay.classList.remove('success-state');
            if (sub && subHtml !== null) sub.innerHTML = subHtml;
            courseCheckMode = null;
            const addBtn = addStudentForm ? addStudentForm.querySelector('button[name="add_student_manual"]') : null;
            if (addStudentForm && typeof addStudentForm.requestSubmit === 'function') {
                addStudentForm.requestSubmit(addBtn);   // runs the normal submit handler (course is now offered) and sends add_student_manual
            } else if (addStudentForm) {
                const h = document.createElement('input');
                h.type = 'hidden'; h.name = 'add_student_manual'; h.value = '1';
                addStudentForm.appendChild(h);
                showGlobalLoading('Adding student');
                addStudentForm.submit();
            }
            hideGlobalLoading();   // releases "Adding course offering" — "Adding student" keeps the page up
        }, 1600);
    }
    if (importAddCourseForm) {
        importAddCourseForm.addEventListener('submit', function(e) {
            e.preventDefault();
            const formData = new FormData(importAddCourseForm);
            formData.append('add_course_offering', '1');
            const orig = iacSubmitBtn ? iacSubmitBtn.innerHTML : '';
            if (iacSubmitBtn) { iacSubmitBtn.disabled = true; iacSubmitBtn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Saving...'; }
            showGlobalLoading('Adding course offering');
            fetch('course_offering.php', { method: 'POST', headers: { 'X-Requested-With': 'XMLHttpRequest' }, body: formData, credentials: 'same-origin' })
                .then(function(r) { return r.json(); })
                .then(function(data) {
                    if (iacSubmitBtn) { iacSubmitBtn.disabled = false; iacSubmitBtn.innerHTML = orig; }
                    if (data && data.success) {
                        const saved = iacCourse ? iacCourse.value.trim() : '';
                        if (importAddCourseModal) importAddCourseModal.style.display = 'none';
                        // UPDATED (this adjustment): no toast (it was hidden behind the next loading page) — the
                        // loading page itself shows "Course Offering Added" and flows on into "Checking courses".
                        if (courseCheckMode === 'manual') {   // NEW (this adjustment): manual Add Student -> add the student next
                            (window.courseOfferingList = window.courseOfferingList || []).push(saved);
                            showManualCourseAddedAndContinue(saved);
                        } else {
                            showImportCourseAddedAndContinue(saved);
                        }
                    } else {
                        hideGlobalLoading();
                        showToast('Error', String((data && data.message) || 'The course offering could not be added.').replace(/<br\s*\/?>/g, ' '), 'error');
                    }
                })
                .catch(function() {
                    if (iacSubmitBtn) { iacSubmitBtn.disabled = false; iacSubmitBtn.innerHTML = orig; }
                    hideGlobalLoading();
                    showToast('Error', 'Something went wrong while saving the course offering. Please try again.', 'error');
                });
        });
    }

    // ── Add Student Modal ──
    const addStudentBtn       = document.getElementById('addStudentBtn');
    const addStudentModal     = document.getElementById('addStudentModal');
    const closeAddStudentBtn  = document.getElementById('closeAddStudentBtn');
    const cancelAddStudentBtn = document.getElementById('cancelAddStudentBtn');
    const addStudentForm      = document.getElementById('addStudentForm');
    function openAddStudentModal() {
        addStudentModal.style.display = 'flex';
        if (campusBranchSelect)  { campusBranchSelect.value  = ''; toggleCustomCampusInput(); }
        if (courseSelectModal)   { courseSelectModal.value   = ''; toggleCustomCourseInput(); }
        if (majorSelectModal)    { majorSelectModal.value    = ''; toggleCustomMajorInput(); }
        if (sectionSelectModal)  { sectionSelectModal.value  = ''; toggleCustomSectionInput(); }
    }
    function closeAddStudentModal() {
        addStudentModal.style.display = 'none';
        if (addStudentForm)    addStudentForm.reset();
        if (customCampusGroup)  customCampusGroup.classList.remove('show');
        if (customCourseGroup)  customCourseGroup.classList.remove('show');
        if (customMajorGroup)   customMajorGroup.classList.remove('show');
        if (customSectionGroup) customSectionGroup.classList.remove('show');
    }
    if (addStudentBtn)       addStudentBtn.addEventListener('click', openAddStudentModal);
    if (closeAddStudentBtn)  closeAddStudentBtn.addEventListener('click', closeAddStudentModal);
    if (cancelAddStudentBtn) cancelAddStudentBtn.addEventListener('click', closeAddStudentModal);
    // UPDATED (this adjustment): clicking outside the Add Student form no longer closes it (and no longer clears what was typed) —
    // it closes only with its × or Cancel button.
    // NEW (company-style page loader): shown while the new student (and their account) is being saved.
    if (addStudentForm) addStudentForm.addEventListener('submit', function(e) {
        /* NEW (Course Offering): stop and ask the admin when the chosen
           course is not in Course Offering. */
        let chosenCourse = courseSelectModal ? courseSelectModal.value : '';
        if (chosenCourse === 'other' && customCourseInput) chosenCourse = customCourseInput.value;
        const normalize = c => String(c || '').trim().replace(/\s+/g, ' ').toLowerCase();
        const offered = (window.courseOfferingList || []).some(c => normalize(c) === normalize(chosenCourse));
        if (normalize(chosenCourse) !== '' && !offered) {
            e.preventDefault();
            /* UPDATED (this adjustment): the same "Checking courses" loading page as the import shows first,
               then the question — which now opens ABOVE the Add Student form (see #courseCheckModal style). */
            const missingCourse = String(chosenCourse).trim();
            showGlobalLoading('Checking courses');
            setTimeout(function () {
                hideGlobalLoading();
                openCourseCheckForManual(missingCourse);
            }, 700);
            return;
        }
        showGlobalLoading('Adding student');
    });


    // ── Filters (auto-applied, like admin_company_list.php — no Apply/Reset buttons) ──
    // Typing in Search (debounced), changing Campus Branch / Course, or clicking a pagination
    // link fetches the same page with the new filters and swaps only #studentTableSection,
    // so the rest of the page never reloads. Without JS, normal GET links still work.
    const searchInput         = document.getElementById('searchInput');
    const campusSelect        = document.getElementById('campusSelect');
    const courseSelect        = document.getElementById('courseSelect');
    const studentTableSection = document.getElementById('studentTableSection');
    let searchDebounceTimer;
    let studentFetchController = null;
    let studentFetchSeq = 0;

    function buildStudentQuery(page) {
        const p = new URLSearchParams();
        const search = searchInput ? searchInput.value.trim() : '';
        const campus = campusSelect ? campusSelect.value : '';
        const course = courseSelect ? courseSelect.value : '';
        if (search) p.set('search', search);
        if (campus) p.set('campus_branch', campus);
        if (course) p.set('course', course);
        p.set('page', page || 1);
        p.set('ajax_table', '1');
        return p.toString();
    }

    /* UPDATED (adopted from admin_company_list.php's fetchCompanyTable):
       requests only the small JSON table fragment (?ajax_table=1) instead
       of fetching and re-parsing the ENTIRE HTML page with DOMParser on
       every keystroke/filter change/pagination click. Behavior for the
       admin is unchanged — same debounce, same in-place swap, same
       history/rebind handling — this only changes what's requested and
       how the response is read. */
    function fetchStudentTable(page, quiet) {   // UPDATED (this adjustment): quiet = refresh the table without the loading page
        const qs = buildStudentQuery(page);
        if (!studentTableSection) { window.location.href = '?' + qs.replace(/&?ajax_table=1/, ''); return; }
        const seq = ++studentFetchSeq;
        if (studentFetchController) studentFetchController.abort();
        studentFetchController = (typeof AbortController !== 'undefined') ? new AbortController() : null;
        studentTableSection.classList.add('table-loading');
        /* NEW (adopted from admin_company_list.php's fetchCompanyTable):
           show the same full-page "loading companies"-style overlay
           while a filter/search/pagination fetch is in flight, in
           addition to the existing #studentTableSection fade/opacity
           treatment above (untouched). hideGlobalLoading() is called in
           BOTH the success and error/abort branches below so the
           overlay's usage counter always ends up balanced, even when a
           fast-typed search aborts an earlier in-flight request. */
        if (!quiet) showGlobalLoading('Loading students');
        const opts = { credentials: 'same-origin', headers: { 'X-Requested-With': 'XMLHttpRequest' } };
        if (studentFetchController) opts.signal = studentFetchController.signal;
        fetch('?' + qs, opts)
            .then(r => r.json())
            .then(data => {
                if (!quiet) hideGlobalLoading();
                if (seq !== studentFetchSeq) return;
                studentTableSection.innerHTML = data.html;
                const totalEl = document.getElementById('studentTotalCountValue');
                if (totalEl && typeof data.total !== 'undefined') totalEl.textContent = data.total;
                studentTableSection.classList.remove('table-loading');
                studentTableSection.classList.add('table-fade-in');
                setTimeout(() => studentTableSection.classList.remove('table-fade-in'), 350);
                if (typeof rebindStudentTable === 'function') rebindStudentTable();
                if (window.history && history.replaceState) {
                    const shareQs = qs.replace(/&?ajax_table=1/, '');
                    history.replaceState(null, '', window.location.pathname + '?' + shareQs);
                }
            })
            .catch(err => {
                if (!quiet) hideGlobalLoading();
                if (err && err.name === 'AbortError') return;
                if (seq === studentFetchSeq) studentTableSection.classList.remove('table-loading');
            });
    }

    if (searchInput) {
        searchInput.addEventListener('input', function() {
            clearTimeout(searchDebounceTimer);
            searchDebounceTimer = setTimeout(() => fetchStudentTable(1), 450);
        });
        searchInput.addEventListener('keypress', function(e) {
            if (e.key === 'Enter') { e.preventDefault(); clearTimeout(searchDebounceTimer); fetchStudentTable(1); }
        });
    }
    if (campusSelect) campusSelect.addEventListener('change', () => fetchStudentTable(1));
    if (courseSelect) courseSelect.addEventListener('change', () => fetchStudentTable(1));

    // Delegated so pagination links keep working after the table section is swapped.
    if (studentTableSection) {
        studentTableSection.addEventListener('click', function(e) {
            const pageLink = e.target.closest('.pagination a');
            if (pageLink) {
                e.preventDefault();
                let pg = 1;
                try { pg = parseInt(new URL(pageLink.getAttribute('href'), window.location.href).searchParams.get('page'), 10) || 1; } catch (err) {}
                fetchStudentTable(pg);
                window.scrollTo({ top: 0, behavior: 'smooth' });
            }
        });
    }

    // ── PHP → JS toasts ──
    <?php /* UPDATED: the "Import Complete" success toast has been removed entirely. */ ?>
    <?php if ($import_message && $import_message_type === 'error'): ?>
    showToast('Import Failed', '<?= addslashes(strip_tags($import_message)) ?>', 'error');
    <?php endif; ?>
    <?php /* UPDATED: the "Import Filter Applied" popup notification has been removed entirely. */ ?>
    <?php if (isset($manual_add_message) && $manual_add_message !== ''): ?>
    showToast('<?= $manual_add_message_type === 'success' ? 'Success' : 'Error' ?>', '<?= addslashes(strip_tags($manual_add_message)) ?>', '<?= $manual_add_message_type ?>');
    <?php endif; ?>
    <?php if (isset($_GET['export_empty'])): ?>
    // UPDATED (Export result screen): result screen instead of the popup notification.
    showGlobalResult('error', 'Export Cancelled', 'Exporting cancelled because the table is empty.', 0);
    if (window.history && history.replaceState) {
        const cleanParams = new URLSearchParams(window.location.search);
        cleanParams.delete('export_empty');
        const qs = cleanParams.toString();
        history.replaceState(null, '', window.location.pathname + (qs ? '?' + qs : ''));
    }
    <?php endif; ?>
    <?php if (isset($_GET['added']) && $_GET['added'] === 'success'): ?>
    <?php if (isset($_GET['account_created']) && $_GET['account_created'] === '1'): ?>
    showToast('Success', 'Student added successfully! A login account was created and the password was emailed to the student.', 'success');
    <?php elseif (isset($_GET['account_created']) && $_GET['account_created'] === 'email_failed'): ?>
    showToast('Account Created', 'Student added and account created, but the password email could not be sent. Please relay the login details to the student directly.', 'warning');
    <?php else: ?>
    showToast('Success', 'Student added successfully!', 'success');
    <?php endif; ?>
    <?php endif; ?>

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
                }).catch(() => {});
        }
        setTimeout(() => { pollAppBadge(); setInterval(pollAppBadge, 30000); }, 6000);
    })();

    /* ── FIX (sidebar notification indicator — same as admin_company_list.php):
       keeps the "Company Requirements" badge live, using the same
       notification count company_validation.php shows (see
       student_list_company_validation_notif_count() above). Hidden at 0. ── */
    (function() {
        function pollCompanyValidationBadge() {
            fetch('admin_student_list.php?cv_sidebar_notif_count=1', { credentials: 'same-origin' })
                .then(r => r.json())
                .then(data => {
                    const badge = document.getElementById('sidebarMoaBadge');
                    if (!badge) return;
                    const count = parseInt(data.count, 10) || 0;
                    badge.textContent = count;
                    badge.style.display = count > 0 ? '' : 'none';
                }).catch(() => {});
        }
        setTimeout(() => { pollCompanyValidationBadge(); setInterval(pollCompanyValidationBadge, 15000); }, 5000);
    })();

    /* ══════════════════════════════════════════════════════════
       UPDATED (Export exclusion — adopted from admin_company_list.php):
       clicking "Export to Excel" no longer downloads immediately — it
       first opens exportChoiceModal:
         - "None, Proceed With Export" → exports everything with the same
           plain ?export=xlsx request, spinner and page loader as before
           (no popup notification — a result screen is shown instead, same
           as admin_company_list.php).
         - "Yes" → turns on a checkbox selection mode over the table (see
           enterExportExcludeSelectionMode() further below). Confirming it
           with "Export (Excluding N)" runs the same download with those
           students filtered out server-side.
       The empty-table check still cancels the export before anything
       opens. triggerExportDownload() is the one place that starts the
       download either way, so the feedback is always the same.
       ══════════════════════════════════════════════════════════ */
    const exportBtnEl         = document.getElementById('exportBtn');
    const exportConfirmBtn    = document.getElementById('exportConfirmBtn');
    const exportChoiceModal   = document.getElementById('exportChoiceModal');
    const exportChoiceNoneBtn = document.getElementById('exportChoiceNoneBtn');
    const exportChoiceYesBtn  = document.getElementById('exportChoiceYesBtn');
    const exportHasData = <?= $export_total_rows > 0 ? 'true' : 'false' ?>;

    function triggerExportDownload(excludeStudentIds) {
        const btnForFeedback = (activeSelectionMode === 'export' && exportConfirmBtn) ? exportConfirmBtn : exportBtnEl;
        const origHtml = btnForFeedback ? btnForFeedback.innerHTML : '';
        if (btnForFeedback) {
            btnForFeedback.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Preparing...';
            btnForFeedback.style.pointerEvents = 'none';
        }
        // Same "Preparing export" loader as admin_company_list.php.
        showGlobalLoading('Preparing export');
        /* UPDATED (same as admin_company_list.php): the "Exporting —
           Preparing your Excel file — download will start shortly."
           popup notification is no longer shown on export.
           showToast() itself is untouched and still used elsewhere. */

        excludeStudentIds = excludeStudentIds || [];

        /* UPDATED (Export result screen — same as admin_company_list.php):
           the file is now fetched in the background instead of navigating
           to it, so the page knows whether the export actually succeeded.
           The request itself is the same as before — a plain GET
           ?export=xlsx when nothing is excluded, or a POST carrying
           export=xlsx plus exclude_student_ids[] (never a long URL) when
           something is. The loading screen stays up until the file is
           ready, then either the "Export Successful" or the "Export
           Failed" result screen is shown. The button's inline spinner is
           restored when the request finishes instead of after a fixed 3.5s. */
        let fetchUrl = '?export=xlsx';
        const fetchOptions = { method: 'GET', headers: { 'X-Requested-With': 'XMLHttpRequest' }, credentials: 'same-origin' };

        if (excludeStudentIds.length > 0) {
            const formData = new FormData();
            formData.append('export', 'xlsx');
            excludeStudentIds.forEach(function(id) { formData.append('exclude_student_ids[]', id); });
            fetchUrl = window.location.pathname + window.location.search;
            fetchOptions.method = 'POST';
            fetchOptions.body = formData;
        }

        const excludedCount = excludeStudentIds.length;

        function finishExportFeedback() {
            if (btnForFeedback) {
                btnForFeedback.innerHTML = origHtml;
                btnForFeedback.style.pointerEvents = '';
            }
            hideGlobalLoading();
        }

        fetch(fetchUrl, fetchOptions)
        .then(function(response) {
            const contentType = (response.headers.get('Content-Type') || '').toLowerCase();
            if (response.ok && contentType.indexOf('spreadsheetml') !== -1) {
                const disposition = response.headers.get('Content-Disposition') || '';
                const nameMatch = disposition.match(/filename\*?=(?:UTF-8'')?"?([^";]+)"?/i);
                const fileName = nameMatch ? decodeURIComponent(nameMatch[1]) : 'students_export.xlsx';
                return response.blob().then(function(blob) {
                    if (!blob || blob.size === 0) throw new Error('The server returned an empty file.');
                    return { blob: blob, fileName: fileName };
                });
            }
            // Not a spreadsheet: read the server's JSON error if there is one.
            return response.text().then(function(text) {
                let msg = '';
                try { const data = JSON.parse(text); if (data && data.message) msg = data.message; } catch (e) {}
                if (!msg) {
                    msg = response.ok
                        ? 'The server did not return an Excel file. Your session may have expired — please refresh the page and try again.'
                        : 'The server responded with an error (' + response.status + '). Please try again.';
                }
                throw new Error(msg);
            });
        })
        .then(function(file) {
            const blobUrl = URL.createObjectURL(file.blob);
            const link = document.createElement('a');
            link.href = blobUrl;
            link.download = file.fileName;
            link.style.display = 'none';
            document.body.appendChild(link);
            link.click();
            setTimeout(function() { URL.revokeObjectURL(blobUrl); link.remove(); }, 1000);

            finishExportFeedback();
            showGlobalResult(
                'success',
                'Export Successful',
                'Your Excel file "' + file.fileName + '" has been downloaded' +
                    (excludedCount > 0 ? ' (' + excludedCount + ' student' + (excludedCount === 1 ? '' : 's') + ' excluded).' : '.'),
                3000
            );
        })
        .catch(function(err) {
            finishExportFeedback();
            const detail = (err && err.message && err.message !== 'Failed to fetch')
                ? err.message
                : 'Could not reach the server. Please check your connection and try again.';
            showGlobalResult('error', 'Export Failed', detail, 0);
        });
    }

    if (exportBtnEl) {
        exportBtnEl.addEventListener('click', function(e) {
            e.preventDefault();
            if (!exportHasData) {
                // UPDATED (Export result screen): result screen instead of the popup notification.
                showGlobalResult('error', 'Export Cancelled', 'Exporting cancelled because the table is empty.', 0);
                return;
            }
            if (exportChoiceModal) exportChoiceModal.style.display = 'flex';
        });
    }
    if (exportChoiceNoneBtn) {
        exportChoiceNoneBtn.addEventListener('click', function() {
            if (exportChoiceModal) exportChoiceModal.style.display = 'none';
            triggerExportDownload([]);
        });
    }
    if (exportChoiceYesBtn) {
        exportChoiceYesBtn.addEventListener('click', function() {
            if (exportChoiceModal) exportChoiceModal.style.display = 'none';
            enterExportExcludeSelectionMode();
        });
    }
    if (exportChoiceModal) {
        exportChoiceModal.addEventListener('click', function(e) {
            if (e.target === exportChoiceModal) exportChoiceModal.style.display = 'none';
        });
    }
    // NEW (this adjustment): the × button cancels the export choice and closes the popup — the same as clicking outside it
    const exportChoiceCloseBtn = document.getElementById('exportChoiceCloseBtn');
    if (exportChoiceCloseBtn) {
        exportChoiceCloseBtn.addEventListener('click', function() {
            if (exportChoiceModal) exportChoiceModal.style.display = 'none';
        });
    }
    if (exportConfirmBtn) {
        exportConfirmBtn.addEventListener('click', function() {
            triggerExportDownload(getSelectedStudentIds());
            exitSelectionMode();
        });
    }

    /* ══════════════════════════════════════════════════════════
       NEW: Edit / Delete toolbar — checkbox-driven selection flow
       adopted from admin_company_list.php. "Delete" supports multiple
       selections; "Edit" only ever allows exactly one entry to be
       selected at a time. The old "Delete All Records" (truncate)
       button/modal/handler has been removed entirely and replaced by
       this selective delete flow.
       ══════════════════════════════════════════════════════════ */
    const editEntryBtn        = document.getElementById('editEntryBtn');
    const deleteEntryBtn      = document.getElementById('deleteEntryBtn');
    const cancelSelectionBtn  = document.getElementById('cancelSelectionBtn');
    let studentTableWrapper = document.querySelector('.table-scroll-wrapper');
    let selectAllCheckboxEl = document.getElementById('selectAllCheckbox');

    const editStudentModal       = document.getElementById('editStudentModal');
    const closeEditStudentBtn    = document.getElementById('closeEditStudentBtn');
    const cancelEditStudentBtn   = document.getElementById('cancelEditStudentBtn');
    const editStudentForm        = document.getElementById('editStudentForm');
    const editStudentIdInput     = document.getElementById('editStudentId');
    const editFirstNameInput     = document.getElementById('editFirstName');
    const editMiddleNameInput    = document.getElementById('editMiddleName');
    const editLastNameInput      = document.getElementById('editLastName');
    const editEmailInput         = document.getElementById('editEmail');

    const deleteSelectedModal        = document.getElementById('deleteSelectedModal');
    const deleteSelectedMessage      = document.getElementById('deleteSelectedMessage');
    const deleteSelectedCancelBtn    = document.getElementById('deleteSelectedCancelBtn');
    const deleteSelectedConfirmBtn   = document.getElementById('deleteSelectedConfirmBtn');

    // null = no selection mode active, 'delete' = multi-select for Delete, 'edit' = single-select for Edit.
    // NEW (Export exclusion): 'export' = multi-select for the students to leave OUT of the Excel export.
    let activeSelectionMode = null;

    function getAllRowCheckboxes() {
        return Array.from(document.querySelectorAll('.row-select-checkbox'));
    }
    function getSelectedStudentIds() {
        return getAllRowCheckboxes().filter(cb => cb.checked).map(cb => cb.value);
    }
    function updateRowHighlightForCheckbox(cb) {
        if (!cb) return;
        const tr = cb.closest('tr');
        if (!tr) return;
        const checked = !!cb.checked;
        if (activeSelectionMode === 'edit') {
            tr.classList.toggle('row-selected-edit', checked);
            tr.classList.remove('row-selected');
            tr.classList.remove('row-selected-export'); // NEW (Export exclusion)
        } else if (activeSelectionMode === 'export') {
            // NEW (Export exclusion): navy tint for rows left out of the export
            tr.classList.toggle('row-selected-export', checked);
            tr.classList.remove('row-selected');
            tr.classList.remove('row-selected-edit');
        } else {
            tr.classList.toggle('row-selected', checked);
            tr.classList.remove('row-selected-edit');
            tr.classList.remove('row-selected-export'); // NEW (Export exclusion)
        }
    }

    function refreshDeleteButtonUI() {
        if (!deleteEntryBtn) return;
        if (activeSelectionMode !== 'delete') {
            deleteEntryBtn.disabled = false;
            deleteEntryBtn.innerHTML = '<i class="fas fa-trash-alt"></i> Delete';
            deleteEntryBtn.title = 'Click to select entries to delete';
            return;
        }
        const count = getSelectedStudentIds().length;
        deleteEntryBtn.disabled = count === 0;
        deleteEntryBtn.innerHTML = '<i class="fas fa-trash-alt"></i> Delete' + (count > 0 ? ' (' + count + ')' : '');
        deleteEntryBtn.title = count > 0 ? 'Delete the selected entries' : 'Select one or more entries below';
    }
    function refreshEditButtonUI() {
        if (!editEntryBtn) return;
        if (activeSelectionMode !== 'edit') {
            editEntryBtn.disabled = false;
            editEntryBtn.innerHTML = '<i class="fas fa-edit"></i> Edit';
            editEntryBtn.title = 'Click to select one entry to edit';
            return;
        }
        const count = getSelectedStudentIds().length;
        editEntryBtn.disabled = count !== 1;
        editEntryBtn.innerHTML = '<i class="fas fa-edit"></i> Edit' + (count === 1 ? ' Selected' : '');
        editEntryBtn.title = count === 1 ? 'Edit the selected entry' : 'Select exactly one entry below to edit';
    }

    /* NEW (Export exclusion — adopted from admin_company_list.php): label of
       the "Export (Excluding N)" button. A count of 0 is still valid — it
       simply exports everything. */
    function refreshExportConfirmButtonUI() {
        if (!exportConfirmBtn) return;
        if (activeSelectionMode !== 'export') {
            exportConfirmBtn.innerHTML = '<i class="fas fa-file-excel"></i> Export (Excluding 0)';
            return;
        }
        const count = getSelectedStudentIds().length;
        exportConfirmBtn.innerHTML = '<i class="fas fa-file-excel"></i> Export' + (count > 0 ? ' (Excluding ' + count + ')' : ' (Excluding None)');
        exportConfirmBtn.title = count > 0 ? 'Export every student except the ' + count + ' checked below' : 'No students checked — this will export everything';
    }

    function setCheckboxColumnVisible(visible) {
        document.querySelectorAll('.checkbox-cell').forEach(cell => {
            cell.style.display = visible ? 'table-cell' : 'none';
        });
        /* NEW (row selection — adopted from admin_company_list.php): lets
           the whole row be clicked to select it (see the delegated
           row-click listener below). This class only drives the pointer
           cursor — the click-to-select itself is gated on
           activeSelectionMode in that listener too. */
        document.querySelectorAll('.student-table').forEach(table => {
            table.classList.toggle('selection-mode-active', visible);
        });
    }
    function setSelectAllCheckboxEnabled(enabled) {
        if (selectAllCheckboxEl) selectAllCheckboxEl.disabled = !enabled;
    }

    function enterDeleteSelectionMode() {
        activeSelectionMode = 'delete';
        setCheckboxColumnVisible(true);
        setSelectAllCheckboxEnabled(true);
        if (editEntryBtn) editEntryBtn.style.display = 'none';
        if (cancelSelectionBtn) cancelSelectionBtn.style.display = 'inline-flex';
        refreshDeleteButtonUI();
        refreshEditButtonUI();
        refreshExportConfirmButtonUI(); // NEW (Export exclusion)
    }
    function enterEditSelectionMode() {
        activeSelectionMode = 'edit';
        setCheckboxColumnVisible(true);
        setSelectAllCheckboxEnabled(false);
        if (deleteEntryBtn) deleteEntryBtn.style.display = 'none';
        if (cancelSelectionBtn) cancelSelectionBtn.style.display = 'inline-flex';
        refreshDeleteButtonUI();
        refreshEditButtonUI();
        refreshExportConfirmButtonUI(); // NEW (Export exclusion)
    }
    /* NEW (Export exclusion — adopted from admin_company_list.php): same
       checkbox selection as Delete (multi-select), but the checked students
       are the ones left OUT of the export. Edit / Delete / Export are hidden
       and "Export (Excluding N)" + Cancel are shown until it is confirmed
       or cancelled. */
    function enterExportExcludeSelectionMode() {
        activeSelectionMode = 'export';
        setCheckboxColumnVisible(true);
        setSelectAllCheckboxEnabled(true);
        if (deleteEntryBtn) deleteEntryBtn.style.display = 'none';
        if (editEntryBtn) editEntryBtn.style.display = 'none';
        if (exportBtnEl) exportBtnEl.style.display = 'none';
        if (exportConfirmBtn) exportConfirmBtn.style.display = 'inline-flex';
        if (cancelSelectionBtn) cancelSelectionBtn.style.display = 'inline-flex';
        refreshDeleteButtonUI();
        refreshEditButtonUI();
        refreshExportConfirmButtonUI();
    }
    function exitSelectionMode() {
        activeSelectionMode = null;
        setCheckboxColumnVisible(false);
        setSelectAllCheckboxEnabled(true);
        getAllRowCheckboxes().forEach(cb => { cb.checked = false; updateRowHighlightForCheckbox(cb); });
        if (selectAllCheckboxEl) selectAllCheckboxEl.checked = false;
        if (deleteEntryBtn) deleteEntryBtn.style.display = 'inline-flex';
        if (editEntryBtn) editEntryBtn.style.display = 'inline-flex';
        if (exportBtnEl) exportBtnEl.style.display = 'inline-flex'; // NEW (Export exclusion)
        if (exportConfirmBtn) exportConfirmBtn.style.display = 'none'; // NEW (Export exclusion)
        if (cancelSelectionBtn) cancelSelectionBtn.style.display = 'none';
        refreshDeleteButtonUI();
        refreshEditButtonUI();
        refreshExportConfirmButtonUI(); // NEW (Export exclusion)
    }

    function syncSelectAllCheckbox() {
        const rowBoxes = getAllRowCheckboxes();
        if (selectAllCheckboxEl && (activeSelectionMode === 'delete' || activeSelectionMode === 'export')) { // UPDATED (Export exclusion)
            selectAllCheckboxEl.checked = rowBoxes.length > 0 && rowBoxes.every(cb => cb.checked);
        }
        refreshDeleteButtonUI();
        refreshEditButtonUI();
        refreshExportConfirmButtonUI(); // NEW (Export exclusion)
    }
    function applySelectAllToRows() {
        if (!selectAllCheckboxEl || (activeSelectionMode !== 'delete' && activeSelectionMode !== 'export')) return; // UPDATED (Export exclusion)
        const checked = selectAllCheckboxEl.checked;
        getAllRowCheckboxes().forEach(cb => { cb.checked = checked; updateRowHighlightForCheckbox(cb); });
        refreshDeleteButtonUI();
        refreshEditButtonUI();
        refreshExportConfirmButtonUI(); // NEW (Export exclusion)
    }
    function handleRowCheckboxChange(cb) {
        if (!cb) return;
        if (activeSelectionMode === 'edit' && cb.checked) {
            getAllRowCheckboxes().forEach(other => {
                if (other !== cb && other.checked) { other.checked = false; updateRowHighlightForCheckbox(other); }
            });
        }
        updateRowHighlightForCheckbox(cb);
        syncSelectAllCheckbox();
    }

    if (selectAllCheckboxEl) selectAllCheckboxEl.addEventListener('change', applySelectAllToRows);
    getAllRowCheckboxes().forEach(cb => cb.addEventListener('change', () => handleRowCheckboxChange(cb)));
    // Delegated fallback, in case a checkbox is toggled without its direct listener attached.
    if (studentTableWrapper) {
        studentTableWrapper.addEventListener('change', function(e) {
            const target = e.target;
            if (!target) return;
            if (target.id === 'selectAllCheckbox') applySelectAllToRows();
            else if (target.classList && target.classList.contains('row-select-checkbox')) handleRowCheckboxChange(target);
        });
    }

    /* NEW (row selection — adopted from admin_company_list.php): click
       anywhere on a row to select / deselect it, instead of having to
       hit the small checkbox — only while a selection mode (Edit or
       Delete) is active, so normal browsing of the table is unaffected.
       Bound once to the stable #studentTableSection container (only its
       contents are swapped by the auto-filter), so it keeps working
       after every search / filter / pagination refresh. Clicks on the
       checkbox itself or any other control in the row (links, buttons,
       selects, labels, inputs) are left to their own handling. Toggling
       reuses handleRowCheckboxChange(), so single-select in Edit mode,
       row highlighting and the Edit / Delete button counts stay in sync. */
    if (studentTableSection) {
        studentTableSection.addEventListener('click', function(e) {
            if (!activeSelectionMode) return;
            if (e.target.closest('a, button, select, option, label, input')) return;
            const tr = e.target.closest('tr');
            if (!tr || !tr.closest('tbody')) return;
            const cb = tr.querySelector('.row-select-checkbox');
            if (!cb || cb.disabled) return;
            cb.checked = !cb.checked;
            handleRowCheckboxChange(cb);
        });
    }

    // Called after the auto-filter swaps the table: re-point to the fresh table/select-all checkbox,
    // re-attach the checkbox handling, and reset the toolbar to its default (closed) state.
    function rebindStudentTable() {
        studentTableWrapper = document.querySelector('.table-scroll-wrapper');
        selectAllCheckboxEl = document.getElementById('selectAllCheckbox');
        if (studentTableWrapper) {
            studentTableWrapper.addEventListener('change', function(e) {
                const target = e.target;
                if (!target) return;
                if (target.id === 'selectAllCheckbox') applySelectAllToRows();
                else if (target.classList && target.classList.contains('row-select-checkbox')) handleRowCheckboxChange(target);
            });
        }
        exitSelectionMode();
    }

    if (deleteEntryBtn) {
        deleteEntryBtn.addEventListener('click', function() {
            if (activeSelectionMode !== 'delete') { enterDeleteSelectionMode(); return; }
            const selectedNow = getSelectedStudentIds();
            if (selectedNow.length === 0) return;
            const count = selectedNow.length;
            if (deleteSelectedMessage) {
                deleteSelectedMessage.innerHTML = 'Are you sure you want to delete <strong>' + count + '</strong> selected student' + (count === 1 ? '' : 's') + '? This action cannot be undone.';
                // NEW (Cross-table student list): registered accounts are deleted with all their records.
                const registeredSelected = getAllRowCheckboxes().filter(cb => cb.checked && cb.getAttribute('data-source') === 'registered').length;
                if (registeredSelected > 0) {
                    deleteSelectedMessage.innerHTML += '<br><br><strong>' + registeredSelected + '</strong> of them ' + (registeredSelected === 1 ? 'has a registered account' : 'have registered accounts') + ' — the account' + (registeredSelected === 1 ? '' : 's') + ' and all related records (attendance, reports, OJT assignment, etc.) will be permanently deleted.';
                }
            }
            if (deleteSelectedModal) deleteSelectedModal.style.display = 'flex';
        });
    }

    function openEditStudentModal(cb) {
        if (!cb || !editStudentModal) return;
        if (editStudentIdInput)  editStudentIdInput.value  = cb.getAttribute('data-id') || '';
        if (editFirstNameInput)  editFirstNameInput.value  = cb.getAttribute('data-first') || '';
        if (editMiddleNameInput) editMiddleNameInput.value = cb.getAttribute('data-middle') || '';
        if (editLastNameInput)   editLastNameInput.value   = cb.getAttribute('data-last') || '';
        if (editEmailInput)      editEmailInput.value      = cb.getAttribute('data-email') || '';

        const majorVal = cb.getAttribute('data-major') || '';
        if (editMajorSelect) {
            if (majorVal === '') {
                // UPDATED (Major dropdown required): no major on file → the explicit "None" choice.
                editMajorSelect.value = 'none'; toggleEditCustomMajorInput();
            } else {
                const match = Array.from(editMajorSelect.options).find(o => o.value === majorVal);
                if (match) {
                    editMajorSelect.value = majorVal; toggleEditCustomMajorInput();
                } else {
                    editMajorSelect.value = 'other'; toggleEditCustomMajorInput();
                    if (editCustomMajorInput) editCustomMajorInput.value = majorVal;
                }
            }
        }

        const sectionVal = cb.getAttribute('data-section') || '';
        if (editSectionSelect) {
            const match = Array.from(editSectionSelect.options).find(o => o.value === sectionVal);
            if (sectionVal && match) {
                editSectionSelect.value = sectionVal; toggleEditCustomSectionInput();
            } else if (sectionVal) {
                editSectionSelect.value = 'other'; toggleEditCustomSectionInput();
                if (editCustomSectionInput) editCustomSectionInput.value = sectionVal;
            } else {
                editSectionSelect.value = ''; toggleEditCustomSectionInput();
            }
        }

        const courseVal = cb.getAttribute('data-course') || '';
        if (editCourseSelect) {
            const match = Array.from(editCourseSelect.options).find(o => o.value === courseVal);
            if (courseVal && match) {
                editCourseSelect.value = courseVal; toggleEditCustomCourseInput();
            } else if (courseVal) {
                editCourseSelect.value = 'other'; toggleEditCustomCourseInput();
                if (editCustomCourseInput) editCustomCourseInput.value = courseVal;
            } else {
                editCourseSelect.value = ''; toggleEditCustomCourseInput();
            }
        }

        const campusVal = cb.getAttribute('data-campus') || '';
        if (editCampusBranchSelect) {
            const match = Array.from(editCampusBranchSelect.options).find(o => o.value === campusVal);
            if (campusVal && match) {
                editCampusBranchSelect.value = campusVal; toggleEditCustomCampusInput();
            } else if (campusVal) {
                editCampusBranchSelect.value = 'other'; toggleEditCustomCampusInput();
                if (editCustomCampusInput) editCustomCampusInput.value = campusVal;
            } else {
                editCampusBranchSelect.value = ''; toggleEditCustomCampusInput();
            }
        }

        editStudentModal.style.display = 'flex';
    }
    function closeEditStudentModal() {
        if (editStudentModal) editStudentModal.style.display = 'none';
        if (editStudentForm)  editStudentForm.reset();
        toggleEditCustomCourseInput();
        toggleEditCustomCampusInput();
        toggleEditCustomMajorInput();
        toggleEditCustomSectionInput();
    }
    if (closeEditStudentBtn)  closeEditStudentBtn.addEventListener('click', closeEditStudentModal);
    if (cancelEditStudentBtn) cancelEditStudentBtn.addEventListener('click', closeEditStudentModal);
    // Deliberately not closed by clicking the backdrop, so an in-progress edit is never lost by an accidental outside click.

    if (editEntryBtn) {
        editEntryBtn.addEventListener('click', function() {
            if (activeSelectionMode !== 'edit') { enterEditSelectionMode(); return; }
            const selected = getSelectedStudentIds();
            if (selected.length !== 1) return;
            const checkedBox = document.querySelector('.row-select-checkbox:checked');
            if (checkedBox) {
                openEditStudentModal(checkedBox);
                exitSelectionMode();
            } else {
                showToast('Error', 'Could not find this entry\u2019s details. Please try again.', 'error');
            }
        });
    }

    if (cancelSelectionBtn) cancelSelectionBtn.addEventListener('click', exitSelectionMode);

    if (editStudentForm) {
        editStudentForm.addEventListener('submit', function(e) {
            e.preventDefault();
            const btn = editStudentForm.querySelector('.btn-submit');
            const orig = btn ? btn.innerHTML : '';
            if (btn) { btn.disabled = true; btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Saving...'; }
            const formData = new FormData(editStudentForm);
            formData.append('edit_student', '1');
            showGlobalLoading('Saving changes'); // NEW (company-style page loader)
            fetch(window.location.pathname + window.location.search, {
                method: 'POST',
                headers: { 'X-Requested-With': 'XMLHttpRequest' },
                body: formData
            })
            .then(r => r.json())
            .then(data => {
                if (btn) { btn.disabled = false; btn.innerHTML = orig; }
                if (data.success) {
                    // UPDATED: success → loading page with check icon + action message (no popup), like course_offering.php
                    closeEditStudentModal();
                    showGlobalSuccess('Changes Saved', data.message);
                } else {
                    hideGlobalLoading();
                    showToast('Error', data.message.replace(/<br\s*\/?>/g, ' '), 'error');
                }
            })
            .catch(() => {
                if (btn) { btn.disabled = false; btn.innerHTML = orig; }
                hideGlobalLoading();
                showToast('Error', 'Something went wrong while updating the student. Please try again.', 'error');
            });
        });
    }

    if (deleteSelectedCancelBtn) deleteSelectedCancelBtn.addEventListener('click', () => { if (deleteSelectedModal) deleteSelectedModal.style.display = 'none'; });
    if (deleteSelectedModal) deleteSelectedModal.addEventListener('click', e => { if (e.target === deleteSelectedModal) deleteSelectedModal.style.display = 'none'; });
    if (deleteSelectedConfirmBtn) {
        deleteSelectedConfirmBtn.addEventListener('click', function() {
            const selected = getSelectedStudentIds();
            if (selected.length === 0) { if (deleteSelectedModal) deleteSelectedModal.style.display = 'none'; return; }
            const orig = deleteSelectedConfirmBtn.innerHTML;
            deleteSelectedConfirmBtn.disabled = true;
            deleteSelectedConfirmBtn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Deleting...';
            const formData = new FormData();
            formData.append('delete_selected_students', '1');
            selected.forEach(id => formData.append('student_ids[]', id));
            if (deleteSelectedModal) deleteSelectedModal.style.display = 'none';
            showGlobalLoading('Deleting entries'); // NEW (company-style page loader)
            fetch(window.location.pathname + window.location.search, {
                method: 'POST',
                headers: { 'X-Requested-With': 'XMLHttpRequest' },
                body: formData
            })
            .then(r => r.json())
            .then(data => {
                deleteSelectedConfirmBtn.disabled = false;
                deleteSelectedConfirmBtn.innerHTML = orig;
                if (deleteSelectedModal) deleteSelectedModal.style.display = 'none';
                if (data.success) {
                    // UPDATED: success → loading page with check icon + action message (no popup), like course_offering.php
                    exitSelectionMode();
                    showGlobalSuccess('Entries Deleted', data.message, 0, 1);   // UPDATED (this adjustment): back to page 1 after a delete, like admin_company_list.php
                } else {
                    hideGlobalLoading();
                    showToast('Error', data.message, 'error');
                }
            })
            .catch(() => {
                deleteSelectedConfirmBtn.disabled = false;
                deleteSelectedConfirmBtn.innerHTML = orig;
                if (deleteSelectedModal) deleteSelectedModal.style.display = 'none';
                hideGlobalLoading();
                showToast('Error', 'Something went wrong while deleting the selected entries. Please try again.', 'error');
            });
        });
    }

    // Ensure the checkbox column and toolbar start in their default (closed) state on load.
    exitSelectionMode();

    /* ══════════════════════════════════════════════════════════
       NEW (adopted from admin_company_list.php): automatic student
       account creation popup. Fires only when PHP queued ids or
       pre-known skips in THIS page load (after an XLSX import, or a
       manual add that needs follow-up). Calls the
       create_student_accounts_selected endpoint, then shows a
       Created / Skipped / Failed breakdown.
       ══════════════════════════════════════════════════════════ */
    const autoAccountProgressModal    = document.getElementById('autoAccountProgressModal');
    const autoAccountProgressBody     = document.getElementById('autoAccountProgressBody');
    const autoAccountProgressCloseBtn = document.getElementById('autoAccountProgressCloseBtn');

    function renderAutoAccountProgressLoading(total) {
        if (!autoAccountProgressBody) return;
        autoAccountProgressBody.innerHTML =
            '<div class="global-loading-spinner" style="margin:0 auto 14px auto;"></div>' +
            '<p class="auto-account-progress-text">Creating login account' + (total === 1 ? '' : 's') + ' for ' + total + ' newly imported student' + (total === 1 ? '' : 's') + '... please wait.</p>';
        if (autoAccountProgressCloseBtn) autoAccountProgressCloseBtn.style.display = 'none';
    }

    function renderAutoAccountDetailBlock(title, colorVar, items) {
        if (!items || items.length === 0) return '';
        const listItems = items.map(function(t) { return '<li>' + t + '</li>'; }).join('');
        return '<div class="auto-account-detail-block"><strong style="color:' + colorVar + ';">' + title + '</strong><ul>' + listItems + '</ul></div>';
    }

    function renderAutoAccountProgressResult(counts, details) {
        if (!autoAccountProgressBody) return;
        counts = counts || { created: 0, skipped: 0, failed: 0 };
        details = details || {};

        let html = '<div class="auto-account-stat-grid">';
        html += '<div class="auto-account-stat"><div class="auto-account-stat-number created">' + counts.created + '</div><div class="auto-account-stat-label">Created</div></div>';
        html += '<div class="auto-account-stat"><div class="auto-account-stat-number skipped">' + counts.skipped + '</div><div class="auto-account-stat-label">Skipped</div></div>';
        html += '<div class="auto-account-stat"><div class="auto-account-stat-number failed">' + counts.failed + '</div><div class="auto-account-stat-label">Failed</div></div>';
        html += '</div>';

        html += renderAutoAccountDetailBlock('Skipped', 'var(--grid-amber)', details.skipped);
        html += renderAutoAccountDetailBlock('Failed', 'var(--grid-red)', details.failed);
        html += renderAutoAccountDetailBlock('Created, but email not sent', 'var(--grid-navy)', details.created_with_email_issues);

        if (counts.created === 0 && counts.skipped === 0 && counts.failed === 0) {
            html += '<p class="auto-account-progress-text">No entries needed to be processed.</p>';
        }

        autoAccountProgressBody.innerHTML = html;
        if (autoAccountProgressCloseBtn) autoAccountProgressCloseBtn.style.display = 'inline-flex';
    }

    function runAutoAccountCreation(importIds, preSkippedDetails) {
        importIds = importIds || [];
        preSkippedDetails = preSkippedDetails || [];

        if ((importIds.length === 0 && preSkippedDetails.length === 0) || !autoAccountProgressModal) return;

        autoAccountProgressModal.style.display = 'flex';

        if (importIds.length === 0) {
            renderAutoAccountProgressResult(
                { created: 0, skipped: preSkippedDetails.length, failed: 0 },
                { skipped: preSkippedDetails, failed: [], created_with_email_issues: [] }
            );
            return;
        }

        renderAutoAccountProgressLoading(importIds.length);

        const formData = new FormData();
        formData.append('create_student_accounts_selected', '1');
        importIds.forEach(function(id) { formData.append('import_ids[]', id); });

        fetch(window.location.pathname, {
            method: 'POST',
            headers: { 'X-Requested-With': 'XMLHttpRequest' },
            body: formData
        })
        .then(function(r) { return r.json(); })
        .then(function(data) {
            const counts = data.counts || { created: 0, skipped: 0, failed: 0 };
            const details = data.details || { skipped: [], failed: [], created_with_email_issues: [] };
            if (preSkippedDetails.length > 0) {
                counts.skipped = (counts.skipped || 0) + preSkippedDetails.length;
                details.skipped = (details.skipped || []).concat(preSkippedDetails);
            }
            renderAutoAccountProgressResult(counts, details);
            /* NEW (this adjustment): the table on screen was built BEFORE these accounts existed (the
               accounts are created here, after the page loaded), so the new students only showed up after
               a manual reload. Refresh the table in place now — same page, same search / filters — using
               the page's own fetchStudentTable() (it also updates the Total count and re-binds the rows). */
            if ((parseInt(counts.created, 10) || 0) > 0 && typeof fetchStudentTable === 'function') {
                fetchStudentTable(parseInt(new URLSearchParams(window.location.search).get('page'), 10) || 1, true);   // quiet: the accounts popup already shows progress — no second loading page
            }
        })
        .catch(function() {
            renderAutoAccountProgressResult(
                { created: 0, skipped: preSkippedDetails.length, failed: importIds.length },
                {
                    skipped: preSkippedDetails,
                    failed: ['Something went wrong while creating accounts automatically. Please try importing or adding the student(s) again.'],
                    created_with_email_issues: []
                }
            );
        });
    }

    if (autoAccountProgressCloseBtn) {
        autoAccountProgressCloseBtn.addEventListener('click', function() {
            if (autoAccountProgressModal) autoAccountProgressModal.style.display = 'none';
        });
    }

    // Drop the one-time redirect params so a manual refresh never re-triggers account creation.
    if (window.history && history.replaceState) {
        const acctParams = new URLSearchParams(window.location.search);
        if (acctParams.has('auto_create_student_id') || acctParams.has('account_created')) {
            acctParams.delete('auto_create_student_id');
            acctParams.delete('account_created');
            const acctQs = acctParams.toString();
            history.replaceState(null, '', window.location.pathname + (acctQs ? '?' + acctQs : ''));
        }
    }

    if (
        (window.autoCreateAccountImportIds && window.autoCreateAccountImportIds.length > 0) ||
        (window.autoCreateAccountPreSkippedDetails && window.autoCreateAccountPreSkippedDetails.length > 0)
    ) {
        runAutoAccountCreation(window.autoCreateAccountImportIds, window.autoCreateAccountPreSkippedDetails);
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
    var CV_NOTIF_BADGE_DISPLAY = '';   // same value this page's own badge poller uses

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
     NEW (this adjustment) — AUTOMATIC CAPITAL LETTERS in the manual adding form(s)
     As the admin types, the first letter of every word becomes a capital
     ("mark nhel" -> "Mark Nhel") — the SAME rule the Add Company form in
     admin_company_list.php already uses (capitalizeWordsOnInput). Only first
     letters are touched — the rest is kept exactly as typed (so "BSIT",
     "McArthur" stay as they are) — and the cursor never jumps. Emails and
     number fields are not included. Also works for text pasted into these fields.
     ══════════════════════════════════════════════════════════════════════ -->
<script>
(function () {
    'use strict';
    var FIELDS = ["#addStudentModal input[name=\"first_name\"]", "#addStudentModal input[name=\"middle_name\"]", "#addStudentModal input[name=\"last_name\"]", "#addStudentModal input[name=\"custom_course\"]", "#addStudentModal input[name=\"custom_major\"]", "#addStudentModal input[name=\"custom_section\"]", "#addStudentModal input[name=\"custom_campus\"]", "#iacCourse"];
    function capitalizeWords(v) {   // same as admin_company_list.php's capitalizeWordsOnInput()
        return String(v).replace(/(^|\s)([a-z])/g, function (m, boundary, letter) { return boundary + letter.toUpperCase(); });
    }
    function isTarget(el) { return el && el.tagName === 'INPUT' && FIELDS.some(function (sel) { return el.matches(sel); }); }
    FIELDS.forEach(function (sel) { document.querySelectorAll(sel).forEach(function (el) { el.setAttribute('autocapitalize', 'words'); }); });
    document.addEventListener('input', function (e) {
        var el = e.target;
        if (!isTarget(el) || e.isComposing) return;
        var fixed = capitalizeWords(el.value);
        if (fixed === el.value) return;
        var start = el.selectionStart, end = el.selectionEnd;   // same length, so the cursor stays put
        el.value = fixed;
        try { el.setSelectionRange(start, end); } catch (x) {}
    });
    document.addEventListener('compositionend', function (e) {   // phone / IME keyboards
        var el = e.target;
        if (isTarget(el)) el.dispatchEvent(new Event('input', { bubbles: true }));
    });
})();
</script>
<!-- ══════════════════════════════════════════════════════════════════════
     NEW (this adjustment) — DIGITS ONLY in the import's "Add Course Offering" form
     "Total Hour Requirement" accepts digits only; "Required Hours of OJT Duty
     per Day" accepts digits and one decimal point (the form allows half hours,
     e.g. 7.5). Letters and other symbols — including the e / + / - that number
     fields normally let through — are simply ignored, whether typed or pasted.
     ══════════════════════════════════════════════════════════════════════ -->
<script>
(function () {
    'use strict';
    var RULES = { iacTotalHours: false, iacDailyHours: true };   // id: decimal point allowed?
    Object.keys(RULES).forEach(function (id) {
        var el = document.getElementById(id);
        if (!el) return;
        var allowDot = RULES[id];
        el.setAttribute('inputmode', allowDot ? 'decimal' : 'numeric');
        el.addEventListener('keydown', function (e) {
            if (e.ctrlKey || e.metaKey || e.altKey || e.key.length !== 1) return;   // shortcuts, arrows, Backspace, Tab…
            if (/[0-9]/.test(e.key)) return;
            if (allowDot && e.key === '.' && String(el.value).indexOf('.') === -1) return;
            e.preventDefault();
        });
        el.addEventListener('beforeinput', function (e) {   // paste / drop / autofill
            if (e.data == null) return;
            var ok = allowDot ? /^[0-9]*\.?[0-9]*$/.test(e.data) : /^[0-9]*$/.test(e.data);
            if (!ok) {
                e.preventDefault();
                var clean = String(e.data).replace(allowDot ? /[^0-9.]/g : /[^0-9]/g, '');
                if (allowDot) { var p = clean.split('.'); clean = p.shift() + (p.length ? '.' + p.join('') : ''); }
                if (clean !== '' && e.inputType && e.inputType.indexOf('insertFromPaste') === 0) el.value = String(el.value) + clean;
            }
        });
    });
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