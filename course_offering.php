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
   COURSE OFFERING — lets the admin define every course the OJT program
   offers, together with its OJT hour rules:
     - Total Hour Requirement   (total OJT hours the course requires)
     - Required Hours per Day   (hours a student must spend on OJT duty
                                 in one day)
   admin_student_list.php reads this same `course_offerings` table: a
   student can only be imported / manually added when their course
   exists here (the admin is asked to skip such students or cancel).
   Styling is taken directly from admin_student_list.php.
   ════════════════════════════════════════════════════════════════════ */

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
    [function () { return cv_alog_is_post('add_course_offering'); }, function ($conn) {
        $c = cv_alog_post('course');
        return ['Course Offering Added', 'Course Offering', $c, "Added course offering $c (" . cv_alog_post('total_hours') . " total hours, " . cv_alog_post('daily_hours') . " hours/day) via Course Offering"];
    }],
    [function () { return cv_alog_is_post('edit_course_offering'); }, function ($conn) {
        $c = cv_alog_post('course');
        return ['Course Offering Updated', 'Course Offering', $c, "Updated course offering $c (" . cv_alog_post('total_hours') . " total hours, " . cv_alog_post('daily_hours') . " hours/day) via Course Offering"];
    }],
    [function () { return cv_alog_is_post('delete_course_offering'); }, function ($conn) {
        $c = (string)cv_alog_scalar($conn, "SELECT course FROM course_offerings WHERE id = ?", 'i', [(int)($_POST['course_offering_id'] ?? 0)]);
        return ['Course Offering Deleted', 'Course Offering', $c, "Deleted course offering $c via Course Offering"];
    }],
    [function () { return cv_alog_is_post('delete_selected_course_offerings'); }, function ($conn) {   // NEW (this adjustment)
        $names = [];
        foreach ((array)($_POST['course_offering_ids'] ?? []) as $raw) { $id = (int)$raw; if ($id > 0) $names[] = (string)cv_alog_scalar($conn, "SELECT course FROM course_offerings WHERE id = ?", 'i', [$id]); }
        $c = count($names);
        return ['Course Offerings Deleted', 'Course Offering', cv_alog_list($names), "Deleted " . cv_alog_plural($c, 'course offering', 'course offerings') . " (" . cv_alog_list($names) . ") via Course Offering"];
    }],
]);

$conn->query("CREATE TABLE IF NOT EXISTS course_offerings (
    id INT AUTO_INCREMENT PRIMARY KEY,
    course VARCHAR(100) NOT NULL UNIQUE,
    total_hours INT NOT NULL,
    daily_hours DECIMAL(5,2) NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
)");

/* Shared validation for the Add / Edit forms. Returns [errors, course, total, daily]. */
function course_offering_validate($conn, $courseRaw, $totalRaw, $dailyRaw, $excludeId = 0) {
    $errors = [];
    $course = preg_replace('/\s+/', ' ', trim((string)$courseRaw));
    $totalRaw = trim((string)$totalRaw);
    $dailyRaw = trim((string)$dailyRaw);

    if ($course === '') $errors[] = "Course is required.";
    elseif (mb_strlen($course) > 100) $errors[] = "Course must be 100 characters or less.";

    if ($totalRaw === '' || !ctype_digit($totalRaw) || (int)$totalRaw <= 0) {
        $errors[] = "Total Hour Requirement must be a whole number greater than 0.";
    }
    if ($dailyRaw === '' || !is_numeric($dailyRaw) || (float)$dailyRaw <= 0 || (float)$dailyRaw > 24) {
        $errors[] = "Required hours per day must be a number greater than 0 and not more than 24.";
    }
    if (empty($errors) && (float)$dailyRaw > (int)$totalRaw) {
        $errors[] = "Required hours per day cannot be greater than the Total Hour Requirement.";
    }

    if (empty($errors)) {
        $dup = $conn->prepare("SELECT id FROM course_offerings WHERE LOWER(TRIM(course)) = LOWER(?) AND id != ?");
        $dup->bind_param("si", $course, $excludeId);
        $dup->execute();
        if ($dup->get_result()->num_rows > 0) $errors[] = "The course \"" . htmlspecialchars($course ?? '') . "\" is already in the Course Offering list.";
        $dup->close();
    }

    return [$errors, $course, (int)$totalRaw, round((float)$dailyRaw, 2)];
}

// ── Add ──
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['add_course_offering'])) {
    header('Content-Type: application/json');
    [$errors, $course, $total, $daily] = course_offering_validate($conn, $_POST['course'] ?? '', $_POST['total_hours'] ?? '', $_POST['daily_hours'] ?? '');
    if (!empty($errors)) { echo json_encode(['success' => false, 'message' => implode("<br>", $errors)]); exit; }

    $stmt = $conn->prepare("INSERT INTO course_offerings (course, total_hours, daily_hours) VALUES (?, ?, ?)");
    $stmt->bind_param("sid", $course, $total, $daily);
    echo json_encode($stmt->execute()
        ? ['success' => true, 'message' => 'Course offering added successfully!']
        : ['success' => false, 'message' => 'Error adding course offering: ' . $stmt->error]);
    $stmt->close();
    exit;
}

// ── Edit ──
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['edit_course_offering'])) {
    header('Content-Type: application/json');
    $id = (int)($_POST['course_offering_id'] ?? 0);
    if ($id <= 0) { echo json_encode(['success' => false, 'message' => 'Invalid course offering.']); exit; }

    [$errors, $course, $total, $daily] = course_offering_validate($conn, $_POST['course'] ?? '', $_POST['total_hours'] ?? '', $_POST['daily_hours'] ?? '', $id);
    if (!empty($errors)) { echo json_encode(['success' => false, 'message' => implode("<br>", $errors)]); exit; }

    $stmt = $conn->prepare("UPDATE course_offerings SET course = ?, total_hours = ?, daily_hours = ? WHERE id = ?");
    $stmt->bind_param("sidi", $course, $total, $daily, $id);
    echo json_encode($stmt->execute()
        ? ['success' => true, 'message' => 'Course offering updated successfully!']
        : ['success' => false, 'message' => 'Error updating course offering: ' . $stmt->error]);
    $stmt->close();
    exit;
}

// ── Delete ──
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_course_offering'])) {
    header('Content-Type: application/json');
    $id = (int)($_POST['course_offering_id'] ?? 0);
    if ($id <= 0) { echo json_encode(['success' => false, 'message' => 'Invalid course offering.']); exit; }

    $stmt = $conn->prepare("DELETE FROM course_offerings WHERE id = ?");
    $stmt->bind_param("i", $id);
    $stmt->execute();
    $deleted = $stmt->affected_rows > 0;
    $stmt->close();
    echo json_encode($deleted
        ? ['success' => true, 'message' => 'Course offering deleted successfully!']
        : ['success' => false, 'message' => 'This course offering could not be found (it may have already been deleted).']);
    exit;
}

/* NEW (this adjustment): delete SEVERAL course offerings at once — used by the toolbar's Delete when more
   than one course is selected. Each one is deleted exactly like the single delete above. */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_selected_course_offerings'])) {
    header('Content-Type: application/json');
    $ids = array_values(array_unique(array_filter(array_map('intval', (array)($_POST['course_offering_ids'] ?? [])), function ($v) { return $v > 0; })));
    if (empty($ids)) { echo json_encode(['success' => false, 'message' => 'No course offerings were selected.']); exit; }
    $deletedCount = 0;
    $stmt = $conn->prepare("DELETE FROM course_offerings WHERE id = ?");
    foreach ($ids as $id) {
        $stmt->bind_param("i", $id);
        $stmt->execute();
        if ($stmt->affected_rows > 0) $deletedCount++;
    }
    $stmt->close();
    $notFound = count($ids) - $deletedCount;
    if ($deletedCount > 0) {
        $msg = $deletedCount . ' course offering' . ($deletedCount === 1 ? '' : 's') . ' deleted successfully!';
        if ($notFound > 0) $msg .= ' ' . $notFound . ' could not be found (already deleted).';
        echo json_encode(['success' => true, 'message' => $msg, 'deleted' => $deletedCount]);
    } else {
        echo json_encode(['success' => false, 'message' => 'The selected course offerings could not be found (they may have already been deleted).']);
    }
    exit;
}

// ── List (with how many imported students are under each course) ──
$students_import_exists = false;
try {
    $chk = $conn->query("SHOW TABLES LIKE 'students_import'");
    $students_import_exists = $chk && $chk->num_rows > 0;
} catch (\Throwable $e) {}

$offerings = [];
$listRes = $conn->query("SELECT co.*, 0 AS student_count FROM course_offerings co ORDER BY co.course ASC");
if ($listRes) { while ($r = $listRes->fetch_assoc()) $offerings[] = $r; }

/* ════════════════════════════════════════════════════════════════════
   FIX (Students column count): the count used to include only rows of
   students_import, so registered students were missed and a student
   could be counted from stale import data. It now counts every student
   exactly once, the same way admin_student_list.php builds its list:
     - REGISTERED students (users, role = 'student'): course taken from
       users.course, else student_information, else their import entry.
     - IMPORTED students without an account yet (students_import rows
       whose email isn't used by any student account).
   Courses are matched ignoring letter case and extra spaces. Missing
   tables/columns are detected first, so the page never breaks.
   ════════════════════════════════════════════════════════════════════ */
function course_offering_normalize($course) {
    $c = preg_replace('/\s+/', ' ', trim((string)$course));
    return function_exists('mb_strtolower') ? mb_strtolower($c) : strtolower($c);
}

function course_offering_columns($conn, $table) {
    $cols = [];
    try {
        $res = $conn->query("SHOW COLUMNS FROM `" . str_replace('`', '', $table) . "`");
        if ($res) { while ($c = $res->fetch_assoc()) $cols[$c['Field']] = true; }
    } catch (\Throwable $e) {}
    return $cols;
}

$course_student_counts = [];
try {
    $usersCols  = course_offering_columns($conn, 'users');
    $sinfCols   = course_offering_columns($conn, 'student_information');
    $importCols = $students_import_exists ? course_offering_columns($conn, 'students_import') : [];

    // (A) registered students
    if (!empty($usersCols)) {
        $userCourseSel = isset($usersCols['course']) ? 'u.course' : 'NULL';
        $sinfCourseCol = isset($sinfCols['course']) ? 'course' : (isset($sinfCols['program']) ? 'program' : null);
        $sinfJoin = '';
        $sinfCourseSel = 'NULL';
        if (isset($sinfCols['user_id']) && $sinfCourseCol !== null) {
            $sinfJoin = "LEFT JOIN (SELECT user_id, MAX(`$sinfCourseCol`) AS course FROM student_information GROUP BY user_id) sinf ON sinf.user_id = u.id";
            $sinfCourseSel = 'sinf.course';
        }
        $impJoin = '';
        $impCourseSel = 'NULL';
        if (isset($importCols['course']) && isset($importCols['email'])) {
            $impJoin = "LEFT JOIN students_import simp ON simp.email = u.email";
            $impCourseSel = 'simp.course';
        }
        $regRes = $conn->query("SELECT u.id, $userCourseSel AS user_course, $sinfCourseSel AS sinf_course, $impCourseSel AS imp_course
                                FROM users u $sinfJoin $impJoin
                                WHERE u.role = 'student'");
        $seenUsers = [];
        if ($regRes) {
            while ($r = $regRes->fetch_assoc()) {
                if (isset($seenUsers[$r['id']])) continue; // count each account once
                $seenUsers[$r['id']] = true;
                $c = '';
                foreach (['user_course', 'sinf_course', 'imp_course'] as $k) {
                    if (trim((string)($r[$k] ?? '')) !== '') { $c = $r[$k]; break; }
                }
                if ($c === '') continue;
                $key = course_offering_normalize($c);
                $course_student_counts[$key] = ($course_student_counts[$key] ?? 0) + 1;
            }
        }
    }

    // (B) imported students that don't have an account yet
    if (isset($importCols['course']) && isset($importCols['email'])) {
        $impSql = !empty($usersCols)
            ? "SELECT s.course FROM students_import s WHERE NOT EXISTS (SELECT 1 FROM users ux WHERE ux.email = s.email AND ux.role = 'student')"
            : "SELECT s.course FROM students_import s";
        $impRes = $conn->query($impSql);
        if ($impRes) {
            while ($r = $impRes->fetch_assoc()) {
                if (trim((string)($r['course'] ?? '')) === '') continue;
                $key = course_offering_normalize($r['course']);
                $course_student_counts[$key] = ($course_student_counts[$key] ?? 0) + 1;
            }
        }
    }
} catch (\Throwable $e) {}

foreach ($offerings as $i => $o) {
    $offerings[$i]['student_count'] = $course_student_counts[course_offering_normalize($o['course'])] ?? 0;
}

function course_offering_format_hours($value) {
    $f = (float)$value;
    return ($f == floor($f)) ? number_format($f, 0) : rtrim(rtrim(number_format($f, 2), '0'), '.');
}

/* ════════════════════════════════════════════════════════════════════
   UPDATED (Est. Duty Days): one shared rule, identical to the one
   admin_student_list.php uses to compute each student's OJT Date End:
     Est. Duty Days = ceil(Total Hour Requirement / Required Hours per Day)
   counted on duty days only (Mon–Fri; Sat/Sun are day-off). The division
   is rounded first so floating-point noise can't add a phantom extra day.
   Also returns the hours of the (possibly shorter) last duty day and the
   calendar span in weeks, so the value can be checked against a student's
   Date Start → Date End on the Student List.
   ════════════════════════════════════════════════════════════════════ */
function course_offering_est_duty_days($totalHours, $dailyHours) {
    $total = (float)$totalHours;
    $daily = (float)$dailyHours;
    $out = ['days' => 0, 'last_day_hours' => 0.0, 'weeks' => 0, 'extra_days' => 0, 'calendar_days' => 0];
    if ($total <= 0 || $daily <= 0) return $out;

    $days = (int)ceil(round($total / $daily, 6));
    $last = round($total - ($days - 1) * $daily, 2);
    if ($last <= 0) $last = $daily;

    $out['days']           = $days;
    $out['last_day_hours'] = $last;
    $out['weeks']          = intdiv($days, 5);          // full Mon–Fri weeks
    $out['extra_days']     = $days % 5;                 // remaining duty days
    // Calendar days from a Monday start to the last duty day (weekends in between included)
    $out['calendar_days']  = intdiv($days - 1, 5) * 7 + (($days - 1) % 5) + 1;
    return $out;
}

// ── Sidebar counts (same sources as admin_student_list.php), guarded so a
//    missing table never breaks this page ──
$app_request_count = 0;
try { $res = $conn->query("SELECT COUNT(*) as total FROM admin_application_approvals aaa_c INNER JOIN users aaa_u ON aaa_u.id = aaa_c.student_id"); if ($res) $app_request_count = (int)$res->fetch_assoc()['total']; } catch (\Throwable $e) {}

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
   admin_student_list.php / admin_company_list.php): the "Company
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
if (!function_exists('course_offering_company_validation_notif_count')) {
    function course_offering_company_validation_notif_count($conn) {
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
   company_validation.php without a page reload. Placed before any HTML
   output; read-only. ── */
if (isset($_GET['cv_sidebar_notif_count']) && $_GET['cv_sidebar_notif_count'] === '1') {
    header('Content-Type: application/json');
    echo json_encode(['count' => course_offering_company_validation_notif_count($conn)]);
    exit;
}

$moa_pending_count = course_offering_company_validation_notif_count($conn); // FIX (sidebar notification indicator)
$all_ungraded_count = 0;
try {
    $res = $conn->query("
        SELECT COUNT(*) as total
        FROM reports r
        JOIN ojt_assignments oa ON oa.student_id = r.user_id AND oa.company_id = r.company_id
        WHERE r.week_start <= CURDATE()
          AND (r.remark IS NULL OR r.remark != 'Wrong Document')
          AND r.faculty_grade IS NULL
    ");
    if ($res) $all_ungraded_count = (int)($res->fetch_assoc()['total'] ?? 0);
} catch (\Throwable $e) {}

$adminFullName = '';
try {
    $adminNameStmt = $conn->prepare("SELECT first_name, middle_name, last_name FROM admins WHERE id = ?");
    if ($adminNameStmt) {
        $adminNameStmt->bind_param("i", $_SESSION['user_id']);
        $adminNameStmt->execute();
        $adminNameRow = $adminNameStmt->get_result()->fetch_assoc();
        $adminNameStmt->close();
        if ($adminNameRow) {
            $adminFullName = preg_replace('/\s+/', ' ', trim(
                ($adminNameRow['first_name'] ?? '') . ' ' . ($adminNameRow['middle_name'] ?? '') . ' ' . ($adminNameRow['last_name'] ?? '')
            ));
        }
    }
} catch (\Throwable $e) {}
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
if ($adminFullName === '') $adminFullName = trim(($_SESSION['first_name'] ?? '') . ' ' . ($_SESSION['last_name'] ?? ''));
if ($adminFullName === '') $adminFullName = 'Administrator';

$pageTitle = "Course Offering";
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

        .settings-btn {
            background: rgba(255,255,255,0.15); border: none; color: white; font-size: 20px;
            width: 40px; height: 40px; border-radius: 50%; display: flex; align-items: center;
            justify-content: center; cursor: pointer; transition: all 0.3s ease;
        }
        .settings-btn:hover { background: rgba(255,255,255,0.25); transform: rotate(30deg); }

        /* ══════════════════════════════════════════════════════════
           "Field Ops Grid" content area (everything below the navbar).
           ══════════════════════════════════════════════════════════ */
        .container { padding: 30px; max-width: 1450px; margin: 0 auto; background: var(--grid-bg); }
        .container h2 { margin-top: 0; color: var(--grid-navy); text-transform: uppercase; letter-spacing: 0.6px; font-size: 20px; }
        /* FIX (full-screen background): .container only painted its own box, so on
           a short page (only a few courses) the area below the table — and the
           strips beside it on wide screens — showed the body's off-white colour.
           The page area now uses the same light background from edge to edge and
           all the way down to the bottom of the screen. */
        .main-content { background: var(--grid-bg); }

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
            min-width: 1270px;
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

        /* Per-column minimum widths for comfortable reading */
        .student-table th:nth-child(1), .student-table td:nth-child(1) { width: 44px; min-width: 44px; } /* Checkbox    */
        .student-table th:nth-child(2), .student-table td:nth-child(2) { min-width: 170px; } /* Full Name   */
        .student-table th:nth-child(3), .student-table td:nth-child(3) { min-width: 110px; } /* Course      */
        .student-table th:nth-child(4), .student-table td:nth-child(4) { min-width: 100px; } /* Section     */
        .student-table th:nth-child(5), .student-table td:nth-child(5) { min-width: 210px; } /* Email       */
        .student-table th:nth-child(6), .student-table td:nth-child(6) { min-width: 145px; } /* Campus      */
        .student-table th:nth-child(7), .student-table td:nth-child(7) { min-width: 170px; } /* Company     */
        .student-table th:nth-child(8), .student-table td:nth-child(8) { min-width: 130px; } /* Date Start  */
        .student-table th:nth-child(9), .student-table td:nth-child(9) { min-width: 130px; } /* Date End    */
        .student-table th:nth-child(10), .student-table td:nth-child(10) { min-width: 115px; } /* Total Hours */
        .student-table th:nth-child(11), .student-table td:nth-child(11) { min-width: 115px; } /* Imported On */

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

        /* UPDATED (success loading page): after a successful add / edit / delete the
           same full-page loader switches to a check icon + message of the action,
           instead of the popup (toast) notification, then the page reloads. */
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

        /* ── Add Student modal — compact grid layout, mirrors the Add Company
           modal in admin_company_list.php. Scoped to #addStudentModal ONLY,
           so the Edit Student modal, Settings modal and every other modal
           keep their existing look. 3 columns on desktop, 2 on medium
           screens, 1 on small screens; the box always leaves a margin above
           and below, scrolls internally on short screens, and keeps the
           action buttons pinned at the bottom. ── */
        #addStudentModal { align-items: center; padding: 20px 0; box-sizing: border-box; }
        #addStudentModal .add-student-modal-box { width: 860px; max-width: 94%; max-height: calc(100vh - 40px); max-height: calc(100dvh - 40px); padding: 16px 24px 0 24px; box-sizing: border-box; }
        #addStudentModal .add-student-modal-header { margin-bottom: 12px; padding-bottom: 8px; }
        #addStudentModal .add-student-modal-header h3 { font-size: 15px; }
        #addStudentModal .add-student-grid { display: grid; grid-template-columns: repeat(3, 1fr); column-gap: 14px; row-gap: 0; align-items: start; }
        #addStudentModal .add-student-grid .span-2 { grid-column: span 2; }
        #addStudentModal .add-student-grid .span-3 { grid-column: 1 / -1; }
        #addStudentModal .form-group { margin-bottom: 9px; }
        #addStudentModal .form-group label { margin-bottom: 4px; font-size: 12px; }
        #addStudentModal .form-group input, #addStudentModal .form-group select { padding: 7px 10px; font-size: 13px; }
        #addStudentModal .help-text { font-size: 10.5px; margin-top: 3px; line-height: 1.35; }
        #addStudentModal .custom-campus-group, #addStudentModal .custom-course-group { margin-top: 6px; }
        #addStudentModal .modal-actions { justify-content: flex-end; position: sticky; bottom: 0; background: #fff; margin-top: 4px; padding: 10px 0 12px 0; z-index: 2; }
        @media (max-width: 900px) {
            #addStudentModal .add-student-grid { grid-template-columns: 1fr 1fr; }
        }
        @media (max-width: 640px) {
            #addStudentModal .add-student-modal-box { padding: 14px 14px 0 14px; }
            #addStudentModal .add-student-grid { grid-template-columns: 1fr; }
            #addStudentModal .add-student-grid .span-2,
            #addStudentModal .add-student-grid .span-3 { grid-column: auto; }
        }

        /* Settings Modal */
        .settings-modal-overlay {
            display: none; position: fixed; top: 0; left: 0; width: 100%; height: 100%;
            background: rgba(0,0,0,0.5); z-index: 10001; justify-content: center; align-items: center;
        }
        .settings-modal-box { background: white; border-radius: 0; border: 1px solid var(--grid-border); padding: 32px; width: 500px; max-width: 90%; animation: modalPop 0.3s ease; }
        .settings-modal-header { display: flex; justify-content: space-between; align-items: center; margin-bottom: 24px; padding-bottom: 16px; border-bottom: 1px solid var(--grid-border); }
        .settings-modal-header h3 { margin: 0; color: var(--grid-navy); font-size: 16px; text-transform: uppercase; letter-spacing: 0.4px; }
        .close-settings-btn { background: none; border: none; font-size: 24px; cursor: pointer; color: var(--grid-muted); transition: color 0.2s; }
        .close-settings-btn:hover { color: var(--grid-navy); }
        .settings-group { margin-bottom: 28px; }
        .settings-group > label { display: flex; align-items: center; gap: 12px; font-weight: 600; color: #1e293b; margin-bottom: 12px; cursor: pointer; }
        .settings-group > label input[type="checkbox"] { width: 18px; height: 18px; cursor: pointer; }
        .settings-group .help-text { font-size: 12px; color: var(--grid-muted); margin-top: 6px; margin-left: 30px; }
        .campuses-list { max-height: 250px; overflow-y: auto; border: 1px solid var(--grid-border); border-radius: 0; padding: 12px; background: var(--grid-bg); }
        .campus-checkbox { display: flex; align-items: center; gap: 10px; padding: 8px 12px; margin: 4px 0; border-radius: 0; transition: background 0.2s; cursor: pointer; }
        .campus-checkbox:hover { background: #e2e8f0; }
        .campus-checkbox input[type="checkbox"] { width: 16px; height: 16px; cursor: pointer; }
        .campus-checkbox span { flex: 1; font-size: 14px; color: #334155; }
        .empty-campuses { text-align: center; padding: 20px; color: #94a3b8; font-size: 13px; }
        .settings-actions { display: flex; gap: 12px; justify-content: flex-end; margin-top: 24px; padding-top: 16px; border-top: 1px solid var(--grid-border); }
        .btn-save   { background: var(--grid-navy); color: white; border: none; padding: 10px 24px; border-radius: 0; font-weight: 600; cursor: pointer; transition: opacity 0.2s; text-transform: uppercase; letter-spacing: 0.3px; font-size: 12px; }
        .btn-save:hover { opacity: 0.9; }
        .btn-cancel { background: #fff; color: var(--grid-navy); border: 1px solid var(--grid-border); padding: 10px 24px; border-radius: 0; font-weight: 600; cursor: pointer; transition: opacity 0.2s; text-transform: uppercase; letter-spacing: 0.3px; font-size: 12px; }
        .btn-cancel:hover { background: #f3f4f7; }

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

        /* ══════════════════════════════════════════════════════════
           Course Offering page — only additions on top of the styles
           copied from admin_student_list.php.
           ══════════════════════════════════════════════════════════ */
        .course-offering-table { min-width: 900px; }
        .course-offering-table th:nth-child(n), .course-offering-table td:nth-child(n) { min-width: 0; width: auto; }
        .course-offering-table th:nth-child(1), .course-offering-table td:nth-child(1) { min-width: 200px; }
        .course-offering-table th:last-child, .course-offering-table td:last-child { text-align: right; width: 1%; }
        .course-name-cell { font-weight: 700; color: var(--grid-navy); }
        .hours-pill {
            display: inline-block; padding: 3px 10px; font-size: 12px; font-weight: 700;
            border: 1px solid var(--grid-border); background: var(--grid-bg, #F5F7FB); color: var(--grid-navy);
        }
        .hours-pill.daily { background: var(--grid-green-bg); color: var(--grid-green); border-color: #bfe0bf; }
        /* UPDATED (Est. Duty Days): small detail line under the day count */
        .est-days-sub { display: block; font-size: 10.5px; color: var(--grid-muted, #6b7280); margin-top: 2px; white-space: nowrap; }
        .row-action-btn {
            background: #fff; border: 1px solid var(--grid-border); padding: 6px 12px; font-size: 11px;
            font-weight: 700; text-transform: uppercase; letter-spacing: 0.3px; cursor: pointer; border-radius: 0;
            display: inline-flex; align-items: center; gap: 6px; transition: background 0.2s;
        }
        .row-action-btn.edit { color: var(--grid-navy); }
        .row-action-btn.edit:hover { background: var(--grid-amber-bg); }
        .row-action-btn.delete { color: var(--grid-red); margin-left: 6px; }
        .row-action-btn.delete:hover { background: var(--grid-red-bg); }
        #courseOfferingModal .add-student-modal-box { width: 520px; }
        .co-search-empty { display: none; }
        /* NEW (this adjustment): MULTI-EDIT — one edit form per selected course, all shown together */
        #courseMultiEditModal .add-student-modal-box { width: 720px; }
        .co-multi-intro { font-size: 12px; color: var(--grid-muted); margin: -8px 0 16px 0; }
        .co-edit-card { border: 1px solid var(--grid-border); padding: 16px 16px 4px 16px; margin-bottom: 0; background: #fff; }
        .co-edit-card.saved { background: #f4faf4; border-color: #9CC59C; }
        .co-edit-card.saving { opacity: 0.75; }
        .co-edit-card-head { display: flex; justify-content: space-between; align-items: center; margin-bottom: 14px; padding-bottom: 10px; border-bottom: 1px solid var(--grid-border); }
        .co-edit-card-head strong { color: var(--grid-navy); font-size: 13px; text-transform: uppercase; letter-spacing: 0.3px; }
        .co-edit-card-status { font-size: 11px; font-weight: 700; text-transform: uppercase; letter-spacing: 0.3px; color: var(--grid-muted); }
        .co-edit-card.saved .co-edit-card-status { color: #2C5A2C; }
        .co-edit-card.failed .co-edit-card-status { color: var(--grid-red); }
        .co-edit-card .form-row .form-group { margin-bottom: 14px; }
        .co-edit-card > .form-group { margin-bottom: 14px; }
        .co-edit-card-error { display: none; color: var(--grid-red); font-size: 12px; margin: 0 0 12px 0; }
        .co-edit-card.failed .co-edit-card-error { display: block; }
        #courseMultiSaveAllBtn:disabled { opacity: 0.55; cursor: not-allowed; }
        @media (max-width: 600px) { #courseMultiEditModal .form-row { flex-direction: column; gap: 0; } }
        /* NEW (this adjustment): MULTI-EDIT PAGINATION — one form per page */
        .co-edit-card { display: none; }
        .co-edit-card.co-page-active { display: block; }
        .co-pager { display: flex; align-items: center; justify-content: center; flex-wrap: wrap; gap: 6px; margin: 16px 0 4px 0; }
        /* NEW (this adjustment): Prev / Next arrows sit on the left and right side of the edit form */
        .co-edit-stage { display: flex; align-items: center; gap: 10px; }
        .co-edit-stage #courseMultiEditList { flex: 1; min-width: 0; }
        .co-side-arrow { flex: 0 0 auto; width: 40px; height: 40px; background: #fff; color: var(--grid-navy); border: 1px solid var(--grid-border); border-radius: 0; font-size: 16px; cursor: pointer; display: inline-flex; align-items: center; justify-content: center; transition: background 0.2s; }
        .co-side-arrow:hover:not(:disabled) { background: #f3f4f7; }
        .co-side-arrow:disabled { opacity: 0.35; cursor: not-allowed; }
        @media (max-width: 600px) { .co-edit-stage { gap: 6px; } .co-side-arrow { width: 30px; height: 36px; font-size: 14px; } }
        .co-pager button { background: #fff; color: var(--grid-navy); border: 1px solid var(--grid-border); min-width: 34px; height: 34px; padding: 0 12px; font-size: 12px; font-weight: 700; cursor: pointer; border-radius: 0; transition: background 0.2s; }
        .co-pager button:hover:not(:disabled) { background: #f3f4f7; }
        .co-pager button:disabled { opacity: 0.45; cursor: not-allowed; }
        .co-pager button.co-pg-num.current { background: var(--grid-navy); color: #fff; border-color: var(--grid-navy); }
        .co-pager button.co-pg-num.saved { border-color: #9CC59C; color: #2C5A2C; background: #f4faf4; }
        .co-pager button.co-pg-num.saved.current { background: #2C5A2C; color: #fff; border-color: #2C5A2C; }
        .co-pager button.co-pg-num.failed { border-color: var(--grid-red); color: var(--grid-red); }
        .co-pager button.co-pg-num.failed.current { background: var(--grid-red); color: #fff; }
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
<!-- NEW (this adjustment): row-selection checkbox column for the toolbar's Edit / Delete — same look as the Student List -->
<style>
    .course-offering-table th.checkbox-cell, .course-offering-table td.checkbox-cell { width: 44px; min-width: 44px; }
    /* the checkbox column is now 1st, so the Course column's minimum width moves to the 2nd column, and Date Added
       (now last) keeps its normal look — the right-aligned last slot belonged to the removed Actions column */
    .course-offering-table th:nth-child(2), .course-offering-table td:nth-child(2) { min-width: 200px; }
    .course-offering-table th:last-child, .course-offering-table td:last-child { text-align: left; width: auto; }
    .course-offering-table input[type="checkbox"] { cursor: pointer; width: 17px; height: 17px; vertical-align: middle; }
    .course-offering-table.selection-mode-active tbody tr { cursor: pointer; }
    .course-offering-table.selection-mode-active tbody tr a,
    .course-offering-table.selection-mode-active tbody tr button,
    .course-offering-table.selection-mode-active tbody tr label { cursor: auto; }
</style>
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
        <!-- UPDATED (success loading page): check icon + notification of the action -->
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
    var NAV_ON_CLICK   = true;    // show it when leaving the page via a link / form (if the page doesn't already)   // UPDATED (loader sync fix): on click, like administrator.php — not only when the browser starts unloading
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
        <a href="course_offering.php" class="active"><i class="fas fa-book"></i><span class="link-text">Course Offering</span></a>
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
        <!-- UPDATED: the "Course Offering" icon + title heading was removed. -->

        <!-- Filter Bar (search only) -->
        <div class="filter-bar">
            <div class="filter-group">
                <label><i class="fas fa-search"></i> Search</label>
                <input type="text" id="courseSearchInput" placeholder="Course name...">
            </div>
        </div>

        <!-- List Header -->
        <div class="student-list-header">
            <div class="student-total-count"><i class="fas fa-book"></i> Total Courses: <strong id="courseTotalCountValue"><?= count($offerings) ?></strong></div>
            <div class="header-actions">
                <button type="button" id="addCourseOfferingBtn" class="add-student-btn">
                    <i class="fas fa-plus-circle"></i> Add Course Offering
                </button>
                <!-- NEW (this adjustment): Edit / Delete moved up here from each row — same checkbox selection as the
                     Student List: Edit = pick one course, Delete = pick one or more and delete them together. -->
                <button type="button" class="edit-entry-btn" id="editEntryBtn" title="Click to select one course to edit">
                    <i class="fas fa-edit"></i> Edit
                </button>
                <button type="button" class="delete-entry-btn" id="deleteEntryBtn" title="Click to select courses to delete">
                    <i class="fas fa-trash-alt"></i> Delete
                </button>
                <button type="button" class="cancel-selection-btn" id="cancelSelectionBtn" style="display:none;">
                    <i class="fas fa-times"></i> Cancel
                </button>
            </div>
        </div>

        <?php if (empty($offerings)): ?>
            <div class="empty-state">
                <i class="fas fa-book"></i>
                <h3>No Course Offerings Yet</h3>
                <p>Click "Add Course Offering" to add a course with its OJT hour requirements. Students can only be imported or added for courses listed here.</p>
            </div>
        <?php else: ?>
            <div class="table-scroll-wrapper">
                <table class="student-table course-offering-table">
                    <thead>
                        <tr>
                            <th class="checkbox-cell"><input type="checkbox" id="selectAllCheckbox" title="Select all courses shown"></th><!-- NEW (this adjustment) -->
                            <th><i class="fas fa-graduation-cap" style="margin-right:5px;"></i>Course</th>
                            <th><i class="fas fa-hourglass-half" style="margin-right:5px;"></i>Total Hour Requirement</th>
                            <th><i class="fas fa-business-time"  style="margin-right:5px;"></i>Required Hours per Day</th>
                            <th title="ceil(Total Hour Requirement ÷ Required Hours per Day), Mon–Fri duty days"><i class="fas fa-calendar-day"   style="margin-right:5px;"></i>Est. Duty Days</th>
                            <th><i class="fas fa-users"          style="margin-right:5px;"></i>Students</th>
                            <th><i class="fas fa-calendar-plus"  style="margin-right:5px;"></i>Date Added</th>
                        </tr>
                    </thead>
                    <tbody id="courseOfferingTbody">
                        <?php foreach ($offerings as $o):
                            $daily = (float)$o['daily_hours'];
                            // UPDATED (Est. Duty Days): shared, float-safe rule (same as Student List Date End)
                            $est     = course_offering_est_duty_days((int)$o['total_hours'], $daily);
                            $estDays = $est['days'];
                        ?>
                        <tr class="course-offering-row" data-search="<?= htmlspecialchars(mb_strtolower($o['course'])) ?>">
                            <td class="checkbox-cell"><!-- NEW (this adjustment): row selection for the toolbar's Edit / Delete -->
                                <input type="checkbox" class="row-select-checkbox" value="<?= (int)$o['id'] ?>"
                                       data-id="<?= (int)$o['id'] ?>"
                                       data-course="<?= htmlspecialchars($o['course'] ?? '') ?>"
                                       data-total="<?= (int)$o['total_hours'] ?>"
                                       data-daily="<?= course_offering_format_hours($o['daily_hours']) ?>"
                                       data-students="<?= (int)$o['student_count'] ?>">
                            </td>
                            <td class="course-name-cell"><?= htmlspecialchars($o['course'] ?? '') ?></td>
                            <td><span class="hours-pill"><?= number_format((int)$o['total_hours']) ?> hrs</span></td>
                            <td><span class="hours-pill daily"><?= course_offering_format_hours($o['daily_hours']) ?> hrs / day</span></td>
                            <td title="<?= htmlspecialchars('≈ ' . $est['calendar_days'] . ' calendar days when starting on a Monday') ?>">
                                <?= $estDays ?> day<?= $estDays === 1 ? '' : 's' ?>
                                <?php if ($estDays > 0): ?>
                                <span class="est-days-sub">
                                    Mon–Fri · <?= $est['weeks'] > 0 ? $est['weeks'] . ' wk' . ($est['weeks'] === 1 ? '' : 's') : '' ?><?= ($est['weeks'] > 0 && $est['extra_days'] > 0) ? ' ' : '' ?><?= $est['extra_days'] > 0 ? $est['extra_days'] . ' day' . ($est['extra_days'] === 1 ? '' : 's') : '' ?><?php if ($est['last_day_hours'] < $daily): ?> · last day <?= course_offering_format_hours($est['last_day_hours']) ?> hrs<?php endif; ?>
                                </span>
                                <?php endif; ?>
                            </td>
                            <td><?= (int)$o['student_count'] ?></td>
                            <td class="date-cell"><?= $o['created_at'] ? date('M d, Y', strtotime($o['created_at'] ?? '')) : '—' ?></td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <div class="empty-state co-search-empty" id="courseSearchEmpty">
                <i class="fas fa-search"></i>
                <h3>No Matching Courses</h3>
                <p>No course offering matches your search.</p>
            </div>
        <?php endif; ?>
    </div><!-- end .container -->
</div><!-- end .main-content -->

<!-- ═══════════════════════════════════════
     Add / Edit Course Offering Modal
     ═══════════════════════════════════════ -->
<div id="courseOfferingModal" class="add-student-modal-overlay">
    <div class="add-student-modal-box">
        <div class="add-student-modal-header">
            <h3 id="courseOfferingModalTitle"><i class="fas fa-plus-circle"></i> Add Course Offering</h3>
            <button type="button" class="close-add-student-btn" id="closeCourseOfferingBtn">&times;</button>
        </div>
        <form id="courseOfferingForm" autocomplete="off">
            <input type="hidden" name="course_offering_id" id="courseOfferingId" value="">
            <div class="form-group">
                <label>Course <span class="required">*</span></label>
                <input type="text" name="course" id="coCourse" required maxlength="100" placeholder="e.g., BSIT, BSCS, BSBA">
                <div class="help-text"><i class="fas fa-info-circle"></i> Must match the Course written in the student import file (not case-sensitive).</div>
            </div>
            <div class="form-group">
                <label>Total Hour Requirement <span class="required">*</span></label>
                <input type="number" name="total_hours" id="coTotalHours" required min="1" step="1" placeholder="e.g., 486">
                <div class="help-text"><i class="fas fa-info-circle"></i> Total number of OJT hours a student of this course must complete.</div>
            </div>
            <div class="form-group">
                <label>Required Hours of OJT Duty per Day <span class="required">*</span></label>
                <input type="number" name="daily_hours" id="coDailyHours" required min="0.5" max="24" step="0.5" placeholder="e.g., 8">
                <div class="help-text"><i class="fas fa-info-circle"></i> Total hours a student must spend on OJT duty in one day.</div>
            </div>
            <div class="modal-actions">
                <button type="button" class="btn-cancel-modal" id="cancelCourseOfferingBtn">Cancel</button>
                <button type="submit" class="btn-submit" id="courseOfferingSubmitBtn">Add Course Offering</button>
            </div>
        </form>
    </div>
</div>

<!-- ═══════════════════════════════════════
     NEW (this adjustment): Edit Several Course Offerings — one form per selected course
     ═══════════════════════════════════════ -->
<div id="courseMultiEditModal" class="add-student-modal-overlay">
    <div class="add-student-modal-box">
        <div class="add-student-modal-header">
            <h3 id="courseMultiEditTitle"><i class="fas fa-edit"></i> Edit Course Offerings</h3>
            <button type="button" class="close-add-student-btn" id="closeCourseMultiEditBtn">&times;</button>
        </div>
        <p class="co-multi-intro">Each selected course has its own form, one per page. Use the arrows beside the form to move between them, then click Save All to save every course.</p>
        <div class="co-edit-stage">
            <button type="button" class="co-side-arrow" id="courseMultiPrevBtn" title="Previous course" aria-label="Previous course"><i class="fas fa-chevron-left"></i></button>
            <div id="courseMultiEditList"></div>
            <button type="button" class="co-side-arrow" id="courseMultiNextBtn" title="Next course" aria-label="Next course"><i class="fas fa-chevron-right"></i></button>
        </div>
        <div class="co-pager" id="courseMultiPager"></div>
        <div class="modal-actions">
            <button type="button" class="btn-cancel-modal" id="cancelCourseMultiEditBtn">Close</button>
            <button type="button" class="btn-submit" id="courseMultiSaveAllBtn">Save All</button>
        </div>
    </div>
</div>

<!-- Delete Confirmation Modal -->
<div id="deleteCourseModal" class="modal-overlay">
    <div class="modal-box">
        <div class="modal-icon"><i class="fas fa-trash-alt"></i></div>
        <p class="modal-title">Confirm Deletion</p>
        <p class="modal-message" id="deleteCourseMessage"></p>
        <div class="modal-actions">
            <button type="button" class="modal-cancel" id="deleteCourseCancelBtn">Cancel</button>
            <button type="button" class="modal-confirm" id="deleteCourseConfirmBtn">Yes, Delete</button>
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

<script>
    // ── Sidebar toggle ──
    const sb = document.getElementById('sidebar');
    const toggleBtn = document.getElementById('toggleBtn');
    if (toggleBtn) toggleBtn.addEventListener('click', () => sb.classList.toggle('collapsed'));

    // ── Global page loader (same behavior as admin_student_list.php) ──
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
        if (globalLoadingActiveCount === 0 && globalLoadingOverlay) globalLoadingOverlay.classList.add('hidden');
    }
    let initialPageLoadPending = true;
    function finishInitialPageLoad() {
        if (!initialPageLoadPending) return;
        initialPageLoadPending = false;
        if (globalLoadingActiveCount === 0 && globalLoadingOverlay) globalLoadingOverlay.classList.add('hidden');
    }
    window.addEventListener('load', finishInitialPageLoad);
    if (document.readyState === 'complete') finishInitialPageLoad();
    setTimeout(finishInitialPageLoad, 4000);
    // UPDATED (success loading page): turn the loader into a check icon + action message
    let globalSuccessShown = false;
    function showGlobalSuccess(title, message, reloadDelay) {
        globalSuccessShown = true;
        const t = document.getElementById('globalLoadingSuccessTitle');
        const m = document.getElementById('globalLoadingSuccessMsg');
        if (t) t.textContent = title || 'Success';
        if (m) m.textContent = message || '';
        if (globalLoadingOverlay) {
            globalLoadingOverlay.classList.add('success-state');
            globalLoadingOverlay.classList.remove('hidden');
        }
        /* UPDATED (this adjustment — same as admin_company_list.php): the list is refreshed IN PLACE behind
           this success screen instead of reloading the whole page, so no second loading page appears after
           it. The success screen stays for the same time, then releases the action's own loader and fades
           straight into the updated list. If the refresh fails, the page is reloaded exactly as before. */
        const holdUntil = Date.now() + (reloadDelay || 1600);
        return refreshCourseOfferingList()   // UPDATED (this adjustment): returns a promise so the multi-edit can wait for each success screen
            .then(function () {
                return new Promise(function (resolve) {
                    setTimeout(function () {
                        globalSuccessShown = false;
                        hideGlobalLoading();
                        setTimeout(function () {
                            if (globalLoadingOverlay && globalLoadingOverlay.classList.contains('hidden')) globalLoadingOverlay.classList.remove('success-state');
                        }, 400);
                        resolve();
                    }, Math.max(0, holdUntil - Date.now()));
                });
            })
            .catch(function () { setTimeout(() => window.location.reload(), Math.max(0, holdUntil - Date.now())); });
    }

    /* NEW (this adjustment): fetch this page again and swap in the updated course list (the Total, and the
       table / "No Course Offerings Yet" area below the header), then wire up the new rows' Edit / Delete
       buttons and re-apply the current search — the same things a full reload would give, without one. */
    function refreshCourseOfferingList() {
        return fetch(window.location.pathname + window.location.search, { credentials: 'same-origin', cache: 'no-store' })
            .then(function (r) { if (!r.ok) throw new Error('HTTP ' + r.status); return r.text(); })
            .then(function (html) {
                const doc = new DOMParser().parseFromString(html, 'text/html');
                const newHeader = doc.querySelector('.student-list-header');
                const curHeader = document.querySelector('.student-list-header');
                if (!newHeader || !curHeader || !curHeader.parentNode) throw new Error('list not found');
                let n = curHeader.nextSibling;
                while (n) { const next = n.nextSibling; n.parentNode.removeChild(n); n = next; }
                let m = newHeader.nextSibling;
                while (m) { const next = m.nextSibling; curHeader.parentNode.appendChild(document.importNode(m, true)); m = next; }
                const newTotal = doc.getElementById('courseTotalCountValue'), curTotal = document.getElementById('courseTotalCountValue');
                if (newTotal && curTotal) curTotal.textContent = newTotal.textContent;
                bindCourseOfferingEditButtons();
                bindCourseOfferingDeleteButtons();
                if (typeof coExitSelectionMode === 'function') coExitSelectionMode();   // NEW (this adjustment)
                const search = document.getElementById('courseSearchInput');
                if (search && search.value.trim() !== '') search.dispatchEvent(new Event('input'));
            });
    }
    window.addEventListener('beforeunload', function() {
        if (globalSuccessShown) return; // keep the check + message visible while the page reloads
        if (globalLoadingLabel) globalLoadingLabel.textContent = 'Loading';
        if (globalLoadingOverlay) globalLoadingOverlay.classList.remove('hidden');
    });
    window.addEventListener('pageshow', function(e) {
        if (e.persisted) {
            initialPageLoadPending = false;
            globalLoadingActiveCount = 0;
            globalSuccessShown = false;
            if (globalLoadingOverlay) globalLoadingOverlay.classList.remove('success-state');
            if (globalLoadingOverlay) globalLoadingOverlay.classList.add('hidden');
        }
    });

    // ── Toast (same as admin_student_list.php) ──
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

    // ── Search (client-side) ──
    const courseSearchInput = document.getElementById('courseSearchInput');
    if (courseSearchInput) {
        courseSearchInput.addEventListener('input', function() {
            const q = this.value.trim().toLowerCase();
            let shown = 0;
            document.querySelectorAll('.course-offering-row').forEach(function(row) {
                const match = q === '' || (row.getAttribute('data-search') || '').indexOf(q) !== -1;
                row.style.display = match ? '' : 'none';
                if (match) shown++;
            });
            const wrapper = document.querySelector('.table-scroll-wrapper');
            const emptyEl = document.getElementById('courseSearchEmpty');
            if (wrapper) wrapper.style.display = shown === 0 ? 'none' : '';
            if (emptyEl) emptyEl.style.display = shown === 0 ? 'block' : 'none';
        });
    }

    // ── Add / Edit modal ──
    const courseOfferingModal   = document.getElementById('courseOfferingModal');
    const courseOfferingForm    = document.getElementById('courseOfferingForm');
    const courseOfferingTitle   = document.getElementById('courseOfferingModalTitle');
    const courseOfferingSubmit  = document.getElementById('courseOfferingSubmitBtn');
    const courseOfferingIdInput = document.getElementById('courseOfferingId');
    const coCourse      = document.getElementById('coCourse');
    const coTotalHours  = document.getElementById('coTotalHours');
    const coDailyHours  = document.getElementById('coDailyHours');

    function openCourseOfferingModal(editData) {
        courseOfferingForm.reset();
        if (editData) {
            courseOfferingIdInput.value = editData.id;
            coCourse.value = editData.course;
            coTotalHours.value = editData.total;
            coDailyHours.value = editData.daily;
            courseOfferingTitle.innerHTML = '<i class="fas fa-edit"></i> Edit Course Offering';
            courseOfferingSubmit.textContent = 'Save Changes';
        } else {
            courseOfferingIdInput.value = '';
            courseOfferingTitle.innerHTML = '<i class="fas fa-plus-circle"></i> Add Course Offering';
            courseOfferingSubmit.textContent = 'Add Course Offering';
        }
        courseOfferingModal.style.display = 'flex';
        setTimeout(() => coCourse.focus(), 50);
    }
    function closeCourseOfferingModal() {
        courseOfferingModal.style.display = 'none';
        courseOfferingForm.reset();
    }
    document.getElementById('addCourseOfferingBtn').addEventListener('click', () => openCourseOfferingModal(null));
    document.getElementById('closeCourseOfferingBtn').addEventListener('click', closeCourseOfferingModal);
    document.getElementById('cancelCourseOfferingBtn').addEventListener('click', closeCourseOfferingModal);

    function bindCourseOfferingEditButtons() {   // UPDATED (this adjustment): wrapped so refreshed rows are wired too
    document.querySelectorAll('.row-action-btn.edit').forEach(function(btn) {
        btn.addEventListener('click', function() {
            openCourseOfferingModal({
                id: btn.getAttribute('data-id'),
                course: btn.getAttribute('data-course'),
                total: btn.getAttribute('data-total'),
                daily: btn.getAttribute('data-daily')
            });
        });
    });
    }
    bindCourseOfferingEditButtons();

    courseOfferingForm.addEventListener('submit', function(e) {
        e.preventDefault();
        const isEdit = courseOfferingIdInput.value !== '';
        const formData = new FormData(courseOfferingForm);
        formData.append(isEdit ? 'edit_course_offering' : 'add_course_offering', '1');
        const orig = courseOfferingSubmit.innerHTML;
        courseOfferingSubmit.disabled = true;
        courseOfferingSubmit.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Saving...';
        showGlobalLoading(isEdit ? 'Saving changes' : 'Adding course offering');
        fetch(window.location.pathname, { method: 'POST', headers: { 'X-Requested-With': 'XMLHttpRequest' }, body: formData })
            .then(r => r.json())
            .then(data => {
                courseOfferingSubmit.disabled = false;
                courseOfferingSubmit.innerHTML = orig;
                if (data.success) {
                    // UPDATED: success → loading page with check icon + action message (no popup)
                    const savedCourse = coCourse.value.trim();
                    closeCourseOfferingModal();
                    showGlobalSuccess(
                        isEdit ? 'Changes Saved' : 'Course Offering Added',
                        String(data.message || '').replace(/<br\s*\/?>/g, ' ') + (savedCourse ? ' (' + savedCourse + ')' : '')
                    );
                } else {
                    showToast('Error', String(data.message || '').replace(/<br\s*\/?>/g, ' '), 'error');
                    hideGlobalLoading();
                }
            })
            .catch(() => {
                courseOfferingSubmit.disabled = false;
                courseOfferingSubmit.innerHTML = orig;
                hideGlobalLoading();
                showToast('Error', 'Something went wrong while saving the course offering. Please try again.', 'error');
            });
    });

    /* ════════════════════════════════════════════════════════
       NEW (this adjustment): EDIT SEVERAL COURSES — one edit form per
       selected course, all shown at the same time. Every Save (each
       card's own button, or Save All which goes card by card) uses the
       same request as the single Edit and the same loading page:
       "Saving changes…" → check icon + message → list refreshed.
       ════════════════════════════════════════════════════════ */
    const courseMultiEditModal  = document.getElementById('courseMultiEditModal');
    const courseMultiEditList   = document.getElementById('courseMultiEditList');
    const courseMultiSaveAllBtn = document.getElementById('courseMultiSaveAllBtn');
    let courseMultiBusy = false;
    let courseMultiPage = 0;   // NEW (this adjustment): index of the form (page) currently shown

    function courseMultiCards() { return Array.from(courseMultiEditList.querySelectorAll('.co-edit-card')); }
    function courseMultiShowPage(i) {
        const cards = courseMultiCards();
        if (!cards.length) return;
        courseMultiPage = Math.max(0, Math.min(cards.length - 1, i));
        cards.forEach(function (c, idx) { c.classList.toggle('co-page-active', idx === courseMultiPage); });
        courseMultiRenderPager();
    }
    function courseMultiRenderPager() {
        const pager = document.getElementById('courseMultiPager');
        const cards = courseMultiCards();
        if (!pager) return;
        pager.innerHTML = '';
        const mk = function (html, cls, disabled, handler, title) {
            const b = document.createElement('button');
            b.type = 'button'; b.innerHTML = html; if (cls) b.className = cls; if (title) b.title = title;
            b.disabled = !!disabled; b.addEventListener('click', handler);
            pager.appendChild(b); return b;
        };
        cards.forEach(function (c, idx) {
            const cls = 'co-pg-num' + (idx === courseMultiPage ? ' current' : '') + (c.classList.contains('saved') ? ' saved' : '') + (c.classList.contains('failed') ? ' failed' : '');
            mk(String(idx + 1), cls, false, function () { courseMultiShowPage(idx); }, (c.querySelector('.co-m-course').value || '') + (c.classList.contains('saved') ? ' (saved)' : ''));
        });
        const prevB = document.getElementById('courseMultiPrevBtn'), nextB = document.getElementById('courseMultiNextBtn');
        if (prevB) prevB.disabled = courseMultiPage === 0;
        if (nextB) nextB.disabled = courseMultiPage >= cards.length - 1;
    }

    function openCourseMultiEditModal(items) {
        courseMultiBusy = false;                      // FIX: every time the form opens it starts unlocked
        courseMultiEditList.innerHTML = '';
        items.forEach(function (it, i) {
            const card = document.createElement('div');
            card.className = 'co-edit-card';
            card.setAttribute('data-id', it.id);
            card.innerHTML =
                '<div class="co-edit-card-head"><strong>Course ' + (i + 1) + ' of ' + items.length + ': ' + escapeHtmlText(it.course) + '</strong>' +
                '<span class="co-edit-card-status">Not saved</span></div>' +
                '<div class="form-group"><label>Course <span class="required">*</span></label>' +
                '<input type="text" class="co-m-course" required maxlength="100" placeholder="e.g., BSIT, BSCS, BSBA"></div>' +
                '<div class="form-row">' +
                '<div class="form-group"><label>Total Hour Requirement <span class="required">*</span></label>' +
                '<input type="number" class="co-m-total" required min="1" step="1" placeholder="e.g., 486"></div>' +
                '<div class="form-group"><label>Required Hours of OJT Duty per Day <span class="required">*</span></label>' +
                '<input type="number" class="co-m-daily" required min="0.5" max="24" step="0.5" placeholder="e.g., 8"></div>' +
                '</div>' +
                '<div class="co-edit-card-error"></div>';
            card.querySelector('.co-m-course').value = it.course;
            card.querySelector('.co-m-total').value = it.total;
            card.querySelector('.co-m-daily').value = it.daily;
            courseMultiEditList.appendChild(card);
        });
        courseMultiSaveAllBtn.disabled = false;
        courseMultiShowPage(0);   // NEW (this adjustment): one form per page — start on the first
        courseMultiEditModal.style.display = 'flex';
        const first = courseMultiEditList.querySelector('.co-edit-card.co-page-active .co-m-course');
        if (first) setTimeout(() => first.focus(), 50);
    }
    function closeCourseMultiEditModal() {
        courseMultiBusy = false;                      // FIX: release the "busy" lock, otherwise the form is frozen the next time it is opened
        courseMultiSaveAllBtn.disabled = false;       // FIX: Save All must be usable again on the next open
        courseMultiEditModal.style.display = 'none';
        courseMultiEditList.innerHTML = '';
        const pg = document.getElementById('courseMultiPager'); if (pg) pg.innerHTML = '';
        courseMultiPage = 0;
    }
    function courseMultiSetBusy(busy) {
        courseMultiBusy = busy;
        courseMultiSaveAllBtn.disabled = busy;
        courseMultiEditList.querySelectorAll('.co-m-save').forEach(function (b) {
            b.disabled = busy || !!b.closest('.co-edit-card').classList.contains('saved');
        });
    }
    function courseMultiAfterSave() {
        // every card saved → close the multi-edit form
        if (courseMultiEditList.querySelectorAll('.co-edit-card:not(.saved)').length === 0) closeCourseMultiEditModal();
        else {
            courseMultiSetBusy(false);
            const cards = courseMultiCards();   // move on to the next form that still needs saving
            let next = cards.findIndex((c, idx) => idx > courseMultiPage && !c.classList.contains('saved'));
            if (next < 0) next = cards.findIndex(c => !c.classList.contains('saved'));
            courseMultiShowPage(next < 0 ? courseMultiPage : next);
        }
    }

    // Saves ONE card. Returns a promise that resolves to true (saved) / false (failed).
    function saveCourseMultiCard(card, fromSaveAll) {
        if (courseMultiBusy && !fromSaveAll) return Promise.resolve(false);
        if (card.classList.contains('saved')) return Promise.resolve(true);
        const courseIn = card.querySelector('.co-m-course'), totalIn = card.querySelector('.co-m-total'), dailyIn = card.querySelector('.co-m-daily');
        const statusEl = card.querySelector('.co-edit-card-status'), errEl = card.querySelector('.co-edit-card-error'), saveBtn = card.querySelector('.co-m-save');   // (no per-card button any more — Save All saves the cards)
        const fail = function (msg) {
            card.classList.remove('saving'); card.classList.add('failed');
            statusEl.textContent = 'Not saved'; errEl.textContent = msg;
            if (saveBtn) { saveBtn.disabled = false; saveBtn.innerHTML = 'Save Changes'; }
            courseMultiShowPage(courseMultiCards().indexOf(card));   // NEW (this adjustment): jump to the form that has the error
            showToast('Error', msg, 'error');
        };
        if (!courseIn.value.trim() || !totalIn.value.trim() || !dailyIn.value.trim()) {
            fail('Please fill in Course, Total Hour Requirement and Required Hours per Day.');
            if (!fromSaveAll) courseMultiSetBusy(false);
            return Promise.resolve(false);
        }
        const formData = new FormData();
        formData.append('edit_course_offering', '1');
        formData.append('course_offering_id', card.getAttribute('data-id'));
        formData.append('course', courseIn.value);
        formData.append('total_hours', totalIn.value);
        formData.append('daily_hours', dailyIn.value);

        if (!fromSaveAll) courseMultiSetBusy(true);
        card.classList.remove('failed'); card.classList.add('saving');
        courseMultiShowPage(courseMultiCards().indexOf(card));   // NEW (this adjustment): Save All walks through the pages
        statusEl.textContent = 'Saving…'; errEl.textContent = '';
        if (saveBtn) { saveBtn.disabled = true; saveBtn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Saving...'; }
        const savedCourse = courseIn.value.trim();
        showGlobalLoading('Saving changes');

        return fetch(window.location.pathname, { method: 'POST', headers: { 'X-Requested-With': 'XMLHttpRequest' }, body: formData })
            .then(r => r.json())
            .then(function (data) {
                if (data.success) {
                    card.classList.remove('saving'); card.classList.add('saved');
                    statusEl.textContent = 'Saved';
                    if (saveBtn) saveBtn.innerHTML = '<i class="fas fa-check"></i> Saved';
                    [courseIn, totalIn, dailyIn].forEach(i => { i.disabled = true; });
                    courseMultiRenderPager();
                    const hold = fromSaveAll ? 1100 : 1600;
                    return showGlobalSuccess('Changes Saved', String(data.message || '').replace(/<br\s*\/?>/g, ' ') + (savedCourse ? ' (' + savedCourse + ')' : ''), hold)
                        .then(function () { if (!fromSaveAll) courseMultiAfterSave(); return true; });
                }
                hideGlobalLoading();
                fail(String(data.message || '').replace(/<br\s*\/?>/g, ' '));
                if (!fromSaveAll) courseMultiSetBusy(false);
                return false;
            })
            .catch(function () {
                hideGlobalLoading();
                fail('Something went wrong while saving the course offering. Please try again.');
                if (!fromSaveAll) courseMultiSetBusy(false);
                return false;
            });
    }

    // Save All: goes through the unsaved cards one at a time, each with its own loading → success page.
    courseMultiSaveAllBtn.addEventListener('click', function () {
        if (courseMultiBusy) return;
        const cards = Array.from(courseMultiEditList.querySelectorAll('.co-edit-card:not(.saved)'));
        if (!cards.length) return;
        courseMultiSetBusy(true);
        cards.reduce(function (chain, card) {
            return chain.then(function () { return saveCourseMultiCard(card, true); });
        }, Promise.resolve()).then(courseMultiAfterSave).catch(function () { courseMultiSetBusy(false); });   // FIX: never stay locked
    });
    document.getElementById('courseMultiPrevBtn').addEventListener('click', function () { courseMultiShowPage(courseMultiPage - 1); });
    document.getElementById('courseMultiNextBtn').addEventListener('click', function () { courseMultiShowPage(courseMultiPage + 1); });
    document.getElementById('closeCourseMultiEditBtn').addEventListener('click', function () { if (!courseMultiBusy) closeCourseMultiEditModal(); });
    document.getElementById('cancelCourseMultiEditBtn').addEventListener('click', function () { if (!courseMultiBusy) closeCourseMultiEditModal(); });

    // ── Delete ──
    const deleteCourseModal      = document.getElementById('deleteCourseModal');
    const deleteCourseMessage    = document.getElementById('deleteCourseMessage');
    const deleteCourseConfirmBtn = document.getElementById('deleteCourseConfirmBtn');
    let pendingDeleteCourseId = null;
    let pendingDeleteCourseIds = [];   // NEW (this adjustment): several courses selected with the toolbar's Delete

    function escapeHtmlText(t) {
        const d = document.createElement('div');
        d.textContent = t == null ? '' : String(t);
        return d.innerHTML;
    }

    function bindCourseOfferingDeleteButtons() {   // UPDATED (this adjustment): wrapped so refreshed rows are wired too
    document.querySelectorAll('.row-action-btn.delete').forEach(function(btn) {
        btn.addEventListener('click', function() {
            pendingDeleteCourseId = btn.getAttribute('data-id');
            const students = parseInt(btn.getAttribute('data-students') || '0', 10);
            let msg = 'Are you sure you want to delete the course offering <strong>' + escapeHtmlText(btn.getAttribute('data-course')) + '</strong>? This action cannot be undone.';
            if (students > 0) {
                msg += '<br><br>' + students + ' student' + (students === 1 ? ' uses' : 's use') + ' this course. They stay in the Student List, but new students with this course can no longer be imported or added until it is added again.';
            }
            deleteCourseMessage.innerHTML = msg;
            deleteCourseModal.style.display = 'flex';
        });
    });
    }
    bindCourseOfferingDeleteButtons();

    /* ════════════════════════════════════════════════════════
       NEW (this adjustment): toolbar EDIT / DELETE with row selection —
       the same flow as the Student List's Edit / Delete:
         • Edit   → pick one or MORE courses (its checkbox, or click the row)
                    → "Edit Selected" opens the Edit form (one course) or one
                    Edit form per course (several) — see the multi-edit below.
         • Delete → pick one or MORE courses (header checkbox = every
                    course shown by the current search) → "Delete (N)"
                    asks for confirmation; one course is deleted exactly as
                    before, several are deleted together in one request.
         • Cancel → leaves selection mode.
       After add / edit / delete the list refreshes in place and selection
       mode is reset.
       ════════════════════════════════════════════════════════ */
    const editEntryBtn       = document.getElementById('editEntryBtn');
    const deleteEntryBtn     = document.getElementById('deleteEntryBtn');
    const cancelSelectionBtn = document.getElementById('cancelSelectionBtn');
    let activeSelectionMode  = null;   // null | 'edit' | 'delete'

    function coRowCheckboxes(visibleOnly) {
        return Array.from(document.querySelectorAll('.course-offering-row .row-select-checkbox'))
            .filter(cb => !visibleOnly || cb.closest('tr').style.display !== 'none');
    }
    function coSelected() { return coRowCheckboxes(false).filter(cb => cb.checked); }
    function coHighlight(cb) {
        const tr = cb.closest('tr'); if (!tr) return;
        tr.classList.toggle('row-selected-edit', activeSelectionMode === 'edit' && cb.checked);
        tr.classList.toggle('row-selected', activeSelectionMode === 'delete' && cb.checked);
    }
    function coRefreshButtons() {
        const n = coSelected().length;
        if (editEntryBtn) {
            if (activeSelectionMode === 'edit') {
                editEntryBtn.disabled = n < 1;   // UPDATED (this adjustment): one or more courses
                editEntryBtn.innerHTML = '<i class="fas fa-edit"></i> Edit' + (n > 0 ? ' Selected (' + n + ')' : '');
                editEntryBtn.title = n > 0 ? 'Edit the selected course' + (n === 1 ? '' : 's') : 'Select one or more courses below to edit';
            } else {
                editEntryBtn.disabled = false;
                editEntryBtn.innerHTML = '<i class="fas fa-edit"></i> Edit';
                editEntryBtn.title = 'Click to select courses to edit';
            }
        }
        if (deleteEntryBtn) {
            if (activeSelectionMode === 'delete') {
                deleteEntryBtn.disabled = n === 0;
                deleteEntryBtn.innerHTML = '<i class="fas fa-trash-alt"></i> Delete' + (n > 0 ? ' (' + n + ')' : '');
                deleteEntryBtn.title = n > 0 ? 'Delete the selected courses' : 'Select one or more courses below';
            } else {
                deleteEntryBtn.disabled = false;
                deleteEntryBtn.innerHTML = '<i class="fas fa-trash-alt"></i> Delete';
                deleteEntryBtn.title = 'Click to select courses to delete';
            }
        }
        const all = document.getElementById('selectAllCheckbox');
        if (all) {
            const shown = coRowCheckboxes(true);
            all.disabled = activeSelectionMode !== 'delete' && activeSelectionMode !== 'edit';   // UPDATED (this adjustment): Edit can pick several too
            all.checked = (activeSelectionMode === 'delete' || activeSelectionMode === 'edit') && shown.length > 0 && shown.every(cb => cb.checked);
        }
    }
    function coShowCheckboxColumn(show) {
        document.querySelectorAll('.course-offering-table .checkbox-cell').forEach(c => { c.style.display = show ? 'table-cell' : 'none'; });
        document.querySelectorAll('.course-offering-table').forEach(t => t.classList.toggle('selection-mode-active', show));
    }
    function coEnterSelectionMode(mode) {
        activeSelectionMode = mode;
        coShowCheckboxColumn(true);
        if (mode === 'edit' && deleteEntryBtn) deleteEntryBtn.style.display = 'none';
        if (mode === 'delete' && editEntryBtn) editEntryBtn.style.display = 'none';
        if (cancelSelectionBtn) cancelSelectionBtn.style.display = 'inline-flex';
        coRefreshButtons();
    }
    function coExitSelectionMode() {
        activeSelectionMode = null;
        coRowCheckboxes(false).forEach(cb => { cb.checked = false; coHighlight(cb); });
        coShowCheckboxColumn(false);
        if (editEntryBtn) editEntryBtn.style.display = 'inline-flex';
        if (deleteEntryBtn) deleteEntryBtn.style.display = 'inline-flex';
        if (cancelSelectionBtn) cancelSelectionBtn.style.display = 'none';
        coRefreshButtons();
    }
    function coToggle(cb, checked) {
        cb.checked = checked;
        coHighlight(cb);
        coRefreshButtons();
    }

    if (editEntryBtn) editEntryBtn.addEventListener('click', function() {
        if (activeSelectionMode !== 'edit') { coEnterSelectionMode('edit'); return; }
        const sel = coSelected();
        if (sel.length < 1) return;
        const items = sel.map(cb => ({
            id: cb.getAttribute('data-id'),
            course: cb.getAttribute('data-course'),
            total: cb.getAttribute('data-total'),
            daily: cb.getAttribute('data-daily')
        }));
        coExitSelectionMode();
        if (items.length === 1) { openCourseOfferingModal(items[0]); return; }   // one course: same Edit form as before
        openCourseMultiEditModal(items);                                          // several: one form per course
    });
    if (deleteEntryBtn) deleteEntryBtn.addEventListener('click', function() {
        if (activeSelectionMode !== 'delete') { coEnterSelectionMode('delete'); return; }
        const sel = coSelected();
        if (sel.length === 0) return;
        pendingDeleteCourseIds = sel.map(cb => cb.getAttribute('data-id'));
        pendingDeleteCourseId  = pendingDeleteCourseIds[0];
        const students = sel.reduce((sum, cb) => sum + (parseInt(cb.getAttribute('data-students') || '0', 10) || 0), 0);
        let msg;
        if (sel.length === 1) {   // same wording as the old per-row Delete
            msg = 'Are you sure you want to delete the course offering <strong>' + escapeHtmlText(sel[0].getAttribute('data-course')) + '</strong>? This action cannot be undone.';
            if (students > 0) msg += '<br><br>' + students + ' student' + (students === 1 ? ' uses' : 's use') + ' this course. They stay in the Student List, but new students with this course can no longer be imported or added until it is added again.';
        } else {
            msg = 'Are you sure you want to delete these <strong>' + sel.length + '</strong> course offerings (' +
                  sel.map(cb => '<strong>' + escapeHtmlText(cb.getAttribute('data-course')) + '</strong>').join(', ') + ')? This action cannot be undone.';
            if (students > 0) msg += '<br><br>' + students + ' student' + (students === 1 ? ' uses' : 's use') + ' these courses. They stay in the Student List, but new students with these courses can no longer be imported or added until they are added again.';
        }
        deleteCourseMessage.innerHTML = msg;
        deleteCourseModal.style.display = 'flex';
    });
    if (cancelSelectionBtn) cancelSelectionBtn.addEventListener('click', coExitSelectionMode);

    // checkbox clicks, "select all" and whole-row clicks (delegated, so refreshed rows work too)
    document.addEventListener('change', function(e) {
        const t = e.target;
        if (!t || !activeSelectionMode) return;
        if (t.id === 'selectAllCheckbox') {
            coRowCheckboxes(true).forEach(cb => { cb.checked = t.checked; coHighlight(cb); });
            coRefreshButtons();
        } else if (t.classList && t.classList.contains('row-select-checkbox') && t.closest('.course-offering-row')) {
            coToggle(t, t.checked);
        }
    });
    document.addEventListener('click', function(e) {
        if (!activeSelectionMode) return;
        if (e.target.closest('a, button, select, option, label, input')) return;
        const tr = e.target.closest('.course-offering-row');
        if (!tr) return;
        const cb = tr.querySelector('.row-select-checkbox');
        if (cb && !cb.disabled) coToggle(cb, !cb.checked);
    });
    // a new search leaves selection mode (hidden rows are never left selected by accident)
    if (courseSearchInput) courseSearchInput.addEventListener('input', function() { if (activeSelectionMode) coRefreshButtons(); });
    coExitSelectionMode();
    document.getElementById('deleteCourseCancelBtn').addEventListener('click', () => { deleteCourseModal.style.display = 'none'; });
    deleteCourseModal.addEventListener('click', e => { if (e.target === deleteCourseModal) deleteCourseModal.style.display = 'none'; });
    deleteCourseConfirmBtn.addEventListener('click', function() {
        // NEW (this adjustment): several courses selected with the toolbar's Delete → delete them together
        if (Array.isArray(pendingDeleteCourseIds) && pendingDeleteCourseIds.length > 1) {
            const bulk = new FormData();
            bulk.append('delete_selected_course_offerings', '1');
            pendingDeleteCourseIds.forEach(function(id) { bulk.append('course_offering_ids[]', id); });
            deleteCourseModal.style.display = 'none';
            showGlobalLoading('Deleting course offerings');
            fetch(window.location.pathname, { method: 'POST', headers: { 'X-Requested-With': 'XMLHttpRequest' }, body: bulk })
                .then(r => r.json())
                .then(data => {
                    if (data.success) { coExitSelectionMode(); showGlobalSuccess('Course Offerings Deleted', data.message); }
                    else { showToast('Error', data.message, 'error'); hideGlobalLoading(); }
                })
                .catch(() => {
                    hideGlobalLoading();
                    showToast('Error', 'Something went wrong while deleting the course offerings. Please try again.', 'error');
                });
            return;
        }
        if (!pendingDeleteCourseId) return;
        const formData = new FormData();
        formData.append('delete_course_offering', '1');
        formData.append('course_offering_id', pendingDeleteCourseId);
        deleteCourseModal.style.display = 'none';
        showGlobalLoading('Deleting course offering');
        fetch(window.location.pathname, { method: 'POST', headers: { 'X-Requested-With': 'XMLHttpRequest' }, body: formData })
            .then(r => r.json())
            .then(data => {
                // UPDATED: success → loading page with check icon + action message (no popup)
                if (data.success) { if (typeof coExitSelectionMode === 'function') coExitSelectionMode(); showGlobalSuccess('Course Offering Deleted', data.message); }   // UPDATED (this adjustment): leave selection mode
                else { showToast('Error', data.message, 'error'); hideGlobalLoading(); }
            })
            .catch(() => {
                hideGlobalLoading();
                showToast('Error', 'Something went wrong while deleting the course offering. Please try again.', 'error');
            });
    });

    // ── Sidebar badge live polls (same endpoints as admin_student_list.php) ──
    (function() {
        function pollAppBadge() {
            fetch('administrator.php?app_request_count=1').then(r => r.json()).then(data => {
                const badge = document.getElementById('sidebarAppBadge');
                if (!badge) return;
                const count = data.count || 0;
                badge.textContent = count;
                badge.style.display = count > 0 ? 'inline-flex' : 'none';
            }).catch(() => {});
        }
        setTimeout(() => { pollAppBadge(); setInterval(pollAppBadge, 30000); }, 6000);
    })();
    (function() {
        function pollMoaBadge() {
            // FIX (sidebar notification indicator): polls this page's own endpoint, which uses the
            // same notification rule as company_validation.php (see course_offering_company_validation_notif_count()).
            fetch('course_offering.php?cv_sidebar_notif_count=1', { credentials: 'same-origin' }).then(r => r.json()).then(data => {
                const badge = document.getElementById('sidebarMoaBadge');
                if (!badge) return;
                const count = parseInt(data.count, 10) || 0;
                badge.textContent = count;
                badge.style.display = count > 0 ? 'inline-flex' : 'none';
            }).catch(() => {});
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
    var FIELDS = ["#coCourse"];
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