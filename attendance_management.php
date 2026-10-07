<?php
session_start();
// FIX (date one day short): this page used the server's own default time zone for every date() — "Today's Stats" and the other server-side
// dates were therefore a day behind the live clock in the header (the browser's clock) for part of the day. Every student page already
// works in Philippine time (and the attendance logs are dated that way), so this page now does too.
date_default_timezone_set('Asia/Manila');
include "db.php";
// Logged-out plain page visit (no query string, not an XHR) -> the company login page. Any other request keeps this
// page's own response further below (AJAX / action requests are never redirected).
if (empty($_SESSION['user_id']) && ($_SERVER['REQUEST_METHOD'] ?? '') === 'GET' && empty($_SERVER['HTTP_X_REQUESTED_WITH']) && empty($_GET) && !headers_sent()) {
    header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
    header('Location: company_login.php');
    exit;
}

if (!isset($_SESSION['user_id']) || ($_SESSION['role'] ?? '') !== "company") { // same company-only gate as the other company pages
    die("Unauthorized access.");
}

$company_id = (int)$_SESSION['user_id'];

/**
 * Fault-tolerant COUNT helper for the sidebar badges: a failed query (missing table, DB hiccup)
 * shows 0 instead of taking the whole page down.
 */
function attm_safe_count($conn, $sql, $company_id) {
    try {
        $st = $conn->prepare($sql);
        if (!$st) return 0;
        $st->bind_param("i", $company_id);
        if (!$st->execute()) { $st->close(); return 0; }
        $row = $st->get_result()->fetch_assoc();
        $st->close();
        return (int)($row['total'] ?? 0);
    } catch (Throwable $e) {
        return 0;
    }
}

// ================= FETCH INBOX COUNT =================
$inbox_count = attm_safe_count($conn, "SELECT COUNT(*) as total FROM ojt_applications WHERE company_id=? AND phase='pending'", $company_id);

// ================= FETCH UNGRADED COUNT =================
/* UPDATED (weekly report indicator): the NEW weekly reports (not opened yet). attm_safe_count() returns 0 while reports.company_viewed_at does not exist yet. */
$ungraded_count = attm_safe_count($conn, "
    SELECT COUNT(*) as total
    FROM reports r
    WHERE r.company_id = ?
      AND (r.remark IS NULL OR r.remark <> 'Wrong Document')
      AND (r.company_viewed_at IS NULL OR r.submitted_at > r.company_viewed_at)
      AND EXISTS (SELECT 1 FROM ojt_assignments oa WHERE oa.student_id = r.user_id AND oa.company_id = r.company_id)
", $company_id);

// ================= FETCH PENDING LATE REQUESTS COUNT (for sidebar badge) =================
$pending_lr_count = attm_safe_count($conn, "SELECT COUNT(*) as total FROM late_requests WHERE company_id=? AND status='pending'", $company_id);

// ================= FETCH SUPERVISOR NAME =================
$supervisor_name_display = 'Supervisor';
$sv_row = null;
try {
    $stmt_sv = $conn->prepare("SELECT first_name, middle_name, last_name FROM users WHERE id=? LIMIT 1");
    if ($stmt_sv) {
        $stmt_sv->bind_param("i", $company_id);
        if ($stmt_sv->execute()) $sv_row = $stmt_sv->get_result()->fetch_assoc();
        $stmt_sv->close();
    }
} catch (Throwable $e) { $sv_row = null; }
if ($sv_row) {
    $sv_mn = trim($sv_row['middle_name'] ?? '');
    $supervisor_name_display = trim(
        ($sv_row['first_name'] ?? '') .
        ($sv_mn ? ' ' . $sv_mn : '') .
        ' ' . ($sv_row['last_name'] ?? '')
    );
    if (!$supervisor_name_display) $supervisor_name_display = 'Supervisor';
}

// ================= FETCH ALL ATTENDANCE SETTINGS FOR THIS COMPANY =================
// We load all settings into a date-keyed map so we can check per-day
// which duty periods (AM/PM) are actually configured (non-null).
$all_settings_map = [];
$res_settings = $conn->query("
    SELECT date,
           am_time_in_start, am_time_in_end, am_time_out_start, am_time_out_end,
           pm_time_in_start, pm_time_in_end, pm_time_out_start, pm_time_out_end,
           is_auto
    FROM attendance_settings
    WHERE company_id = $company_id
");
if ($res_settings) {
    while ($sr = $res_settings->fetch_assoc()) {
        $all_settings_map[$sr['date']] = $sr;
    }
}

/**
 * Determine which duty columns are "active" for a given date.
 * Falls back to the most recent auto-setting on or before the date if no exact match.
 * Returns ['am' => bool, 'pm' => bool]
 */
function getActiveDutyPeriods($date, $all_settings_map) {
    $setting = null;
    if (isset($all_settings_map[$date])) {
        $setting = $all_settings_map[$date];
    } else {
        // Find most recent setting on or before this date
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
        // No settings at all — treat both as active (original behaviour)
        return ['am' => true, 'pm' => true];
    }

    $amActive = !empty($setting['am_time_in_start'])
             || !empty($setting['am_time_out_start']);
    $pmActive = !empty($setting['pm_time_in_start'])
             || !empty($setting['pm_time_out_start']);

    // If somehow both are null, fall back to both active
    if (!$amActive && !$pmActive) {
        return ['am' => true, 'pm' => true];
    }

    return ['am' => $amActive, 'pm' => $pmActive];
}

/**
 * Compute attendance status for a log row, respecting which duty periods are active.
 *
 * Rules:
 *  - Only considers columns for active duty periods.
 *  - PRESENT  : all active columns have a real (non-missed, non-null) value.
 *  - INCOMPLETE: at least one active column has a real value but not all do,
 *                OR any active column is 'missed'.
 *  - ABSENT   : no active column has any real value.
 */
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

function upsert_settings($conn, $company_id, $d,
    $am_ti_s, $am_ti_e, $am_to_s, $am_to_e,
    $pm_ti_s, $pm_ti_e, $pm_to_s, $pm_to_e, $is_auto) {

    $nullify = function($v) { return ($v === '' || $v === null) ? null : $v; };
    $am_ti_s = $nullify($am_ti_s);
    $am_ti_e = $nullify($am_ti_e);
    $am_to_s = $nullify($am_to_s);
    $am_to_e = $nullify($am_to_e);
    $pm_ti_s = $nullify($pm_ti_s);
    $pm_ti_e = $nullify($pm_ti_e);
    $pm_to_s = $nullify($pm_to_s);
    $pm_to_e = $nullify($pm_to_e);

    $chk = $conn->prepare("SELECT id FROM attendance_settings WHERE company_id=? AND date=?");
    $chk->bind_param("is", $company_id, $d);
    $chk->execute();
    $ex = $chk->get_result()->fetch_assoc();

    if ($ex) {
        $u = $conn->prepare("
            UPDATE attendance_settings
            SET am_time_in_start=?, am_time_in_end=?,
                am_time_out_start=?, am_time_out_end=?,
                pm_time_in_start=?, pm_time_in_end=?,
                pm_time_out_start=?, pm_time_out_end=?,
                is_auto=?
            WHERE id=?
        ");
        $u->bind_param("ssssssssii",
            $am_ti_s, $am_ti_e, $am_to_s, $am_to_e,
            $pm_ti_s, $pm_ti_e, $pm_to_s, $pm_to_e,
            $is_auto, $ex['id']);
        $u->execute();
        return ['action' => 'updated', 'id' => $ex['id'], 'date' => $d, 'error' => $u->error ?: null];
    } else {
        $i = $conn->prepare("
            INSERT INTO attendance_settings
            (company_id, date,
             am_time_in_start, am_time_in_end,
             am_time_out_start, am_time_out_end,
             pm_time_in_start, pm_time_in_end,
             pm_time_out_start, pm_time_out_end,
             is_auto)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
        ");
        $i->bind_param("isssssssssi",
            $company_id, $d,
            $am_ti_s, $am_ti_e, $am_to_s, $am_to_e,
            $pm_ti_s, $pm_ti_e, $pm_to_s, $pm_to_e,
            $is_auto);
        $i->execute();
        return ['action' => 'inserted', 'id' => $conn->insert_id, 'date' => $d, 'error' => $i->error ?: null];
    }
}

function fmt12($t) {
    if (!$t) return '—';
    [$h, $m] = array_map('intval', explode(':', $t));
    $ampm = $h >= 12 ? 'PM' : 'AM';
    $h12  = $h % 12 ?: 12;
    return sprintf('%d:%02d %s', $h12, $m, $ampm);
}

function computeDutyInfo($log, $setting) {
    if (!$log || !$setting) return null;

    $toMins = function($t) {
        if (!$t || $t === 'missed') return null;
        if (strpos($t,' ') !== false) $t = explode(' ',$t)[1];
        $p = explode(':', $t);
        return (int)$p[0]*60 + (int)$p[1];
    };

    $amInActual  = $toMins($log['am_time_in']);
    $amOutActual = $toMins($log['am_time_out']);
    $pmInActual  = $toMins($log['pm_time_in']);
    $pmOutActual = $toMins($log['pm_time_out']);

    $amInSched   = $toMins($setting['am_time_in_start']);
    $amOutSched  = $toMins($setting['am_time_out_start']);
    $pmInSched   = $toMins($setting['pm_time_in_start']);
    $pmOutSched  = $toMins($setting['pm_time_out_start']);

    $amMins = null;
    if ($amInActual !== null && $amOutSched !== null) {
        $amMins = max(0, $amOutSched - $amInActual);
    }
    $pmMins = null;
    if ($pmInActual !== null && $pmOutSched !== null) {
        $pmMins = max(0, $pmOutSched - $pmInActual);
    }

    $amLate = ($amInActual !== null && $amInSched !== null && $amInActual > $amInSched)
           || ($amOutActual !== null && $amOutSched !== null && $amOutActual > $amOutSched);
    $pmLate = ($pmInActual !== null && $pmInSched !== null && $pmInActual > $pmInSched)
           || ($pmOutActual !== null && $pmOutSched !== null && $pmOutActual > $pmOutSched);

    $total = ($amMins ?? 0) + ($pmMins ?? 0);

    $isMissed = fn($v) => ($v === 'missed');
    $hasVal   = fn($v) => ($v !== null && $v !== '' && $v !== 'missed');

    $amInDone  = $hasVal($log['am_time_in']);
    $amOutDone = $hasVal($log['am_time_out']);
    $pmInDone  = $hasVal($log['pm_time_in']);
    $pmOutDone = $hasVal($log['pm_time_out']);

    $anyMissed = $isMissed($log['am_time_in']) || $isMissed($log['am_time_out'])
              || $isMissed($log['pm_time_in']) || $isMissed($log['pm_time_out']);

    $anyActualIn = $hasVal($log['am_time_in']) || $hasVal($log['pm_time_in']);
    $anyActualOut = $hasVal($log['am_time_out']) || $hasVal($log['pm_time_out']);

    $allDone = $amInDone && $amOutDone && $pmInDone && $pmOutDone;

    if (!$anyActualIn && !$anyActualOut) {
        $status = 'ABSENT';
    } elseif ($allDone) {
        $status = ($amLate || $pmLate) ? 'LATE' : 'PRESENT';
    } else {
        $status = 'INCOMPLETE';
    }

    return [
        'am_mins'    => $amMins,
        'pm_mins'    => $pmMins,
        'total_mins' => $total,
        'am_late'    => $amLate,
        'pm_late'    => $pmLate,
        'status'     => $status,
    ];
}

/* ════════════════════════════════════════════════════════════════════
   UPDATED (Start = first attendance): a student's OJT start date is the
   first day they actually recorded attendance in the system (any real
   AM/PM time-in or time-out — a row holding only "missed" does not count).
   Days BEFORE that date are never marked Absent / Missed / Incomplete.
   A student with no attendance yet has not started, so none of their
   days are counted as absent.
   ════════════════════════════════════════════════════════════════════ */
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
    // true when $day is before the student's first attendance (or they have none yet)
    function attm_before_start(array $firstMap, $studentId, string $day): bool {
        $first = $firstMap[(int)$studentId] ?? null;
        return $first === null || $day < $first;
    }
}

/* ════════════════════════════════════════════════════════════════════
   NEW (student schedule): attendance follows each student's own training
   schedule (Day / Evening Schedule set on AccomForm.php and changed by the
   company supervisor on add_ojt_student.php; stored in student_information
   as Mon–Fri acronyms such as "MWF", "TTh" or "None").
   Day Schedule = AM duty days, Evening Schedule = PM duty days. A student with
   only a Day schedule reports (and is judged) on the AM duty only; with only an
   Evening schedule, on the PM duty only; with both, on both.
   A weekday with neither duty scheduled and no real attendance entry is never
   counted as Absent / Missed / Incomplete — it is shown as "Not scheduled".
   A duty period that holds a real entry is always judged, scheduled or not.
   Schedule changes are dated (student_schedule_changes), so past days keep the
   schedule that was in force back then. A student whose schedule is empty or
   unreadable is treated as scheduled every weekday (nothing changes for them).
   ════════════════════════════════════════════════════════════════════ */
if (!function_exists('attsch_parse_days')) {
    // "MWF" -> [1,3,5] (date('w') numbers); "None" / empty / unreadable -> []
    function attsch_parse_days($value): array {
        $rest = trim((string)$value);
        if ($rest === '' || strcasecmp($rest, 'None') === 0) return [];
        $codes = ['Th' => 4, 'M' => 1, 'T' => 2, 'W' => 3, 'F' => 5];
        $found = [];
        while ($rest !== '') {
            $hit = false;
            foreach ($codes as $code => $n) {
                if (stripos($rest, $code) === 0) { $found[$n] = true; $rest = substr($rest, strlen($code)); $hit = true; break; }
            }
            if (!$hit) return [];
        }
        $days = array_keys($found);
        sort($days);
        return $days;
    }
}
if (!function_exists('attsch_days')) {
    // Day (AM duty) / Evening (PM duty) schedule -> ['d' => AM days, 'e' => PM days]; null = no usable schedule (both duties every weekday)
    function attsch_days($day, $evening): ?array {
        $d = attsch_parse_days($day);
        $e = attsch_parse_days($evening);
        if (empty($d) && empty($e)) return null;
        return ['d' => $d, 'e' => $e];
    }
}
if (!function_exists('attsch_load')) {
    // [student_id => ['cur' => ['d'=>…,'e'=>…]|null, 'changes' => [['d' => 'Y-m-d', 'old' => same|null], ...oldest first]]]
    function attsch_load($conn, array $ids): array {
        static $cache = [];
        $ids = array_values(array_unique(array_filter(array_map('intval', $ids))));
        sort($ids);
        if (empty($ids)) return [];
        $key = implode(',', $ids);
        if (isset($cache[$key])) return $cache[$key];
        $map = [];
        foreach ($ids as $i) $map[$i] = ['cur' => null, 'changes' => []];
        try {
            $r = $conn->query("SELECT user_id, day_sched, evening_sched FROM student_information WHERE user_id IN ($key)");
            if ($r) { while ($row = $r->fetch_assoc()) $map[(int)$row['user_id']]['cur'] = attsch_days($row['day_sched'], $row['evening_sched']); }
        } catch (\Throwable $e) {}
        try {
            $t = $conn->query("SHOW TABLES LIKE 'student_schedule_changes'");
            if ($t && $t->num_rows > 0) {
                $r = $conn->query("SELECT student_id, old_day_sched, old_evening_sched, DATE(created_at) AS d
                                   FROM student_schedule_changes WHERE student_id IN ($key) ORDER BY created_at ASC, id ASC");
                if ($r) {
                    while ($row = $r->fetch_assoc()) {
                        $map[(int)$row['student_id']]['changes'][] = ['d' => $row['d'], 'old' => attsch_days($row['old_day_sched'], $row['old_evening_sched'])];
                    }
                }
            }
        } catch (\Throwable $e) {}
        return $cache[$key] = $map;
    }
}
if (!function_exists('attsch_periods')) {
    // which duty periods the student is scheduled for on $day: ['am' => bool, 'pm' => bool] (unknown student = both)
    function attsch_periods(array $map, $studentId, string $day): array {
        $s = $map[(int)$studentId] ?? null;
        if ($s === null) return ['am' => true, 'pm' => true];
        $sc = $s['cur'];
        foreach ($s['changes'] as $c) {            // a change applies from its own date onward
            if ($c['d'] > $day) { $sc = $c['old']; break; }
        }
        if ($sc === null) return ['am' => true, 'pm' => true];
        $dow = (int)date('w', strtotime($day));
        return ['am' => in_array($dow, $sc['d'], true), 'pm' => in_array($dow, $sc['e'], true)];
    }
}
if (!function_exists('attsch_is_scheduled')) {
    // true when the student is scheduled for at least one duty period on $day
    function attsch_is_scheduled(array $map, $studentId, string $day): bool {
        $p = attsch_periods($map, $studentId, $day);
        return $p['am'] || $p['pm'];
    }
}
if (!function_exists('attsch_has_real_entry')) {
    // true when the log row holds at least one real time (a "missed"-only row is not an entry)
    function attsch_has_real_entry($row): bool {
        if (!is_array($row)) return false;
        foreach (['am_time_in', 'am_time_out', 'pm_time_in', 'pm_time_out'] as $c) {
            $v = $row[$c] ?? null;
            if ($v !== null && $v !== '' && $v !== 'missed') return true;
        }
        return false;
    }
}
if (!function_exists('attsch_limit_duty')) {
    // narrows the day's active duty periods (['am' => bool, 'pm' => bool]) to the ones the student is scheduled for;
    // a period that holds a real entry stays active so recorded attendance is never ignored
    function attsch_limit_duty(array $duty, array $periods, $row): array {
        $hv = fn($v) => ($v !== null && $v !== '' && $v !== 'missed');
        $realAm = is_array($row) && ($hv($row['am_time_in'] ?? null) || $hv($row['am_time_out'] ?? null));
        $realPm = is_array($row) && ($hv($row['pm_time_in'] ?? null) || $hv($row['pm_time_out'] ?? null));
        $duty['am'] = !empty($duty['am']) && ($periods['am'] || $realAm);
        $duty['pm'] = !empty($duty['pm']) && ($periods['pm'] || $realPm);
        return $duty;
    }
}
if (!function_exists('attsch_js_map')) {
    // compact form for the page script: {id: {c: {d: AM days, e: PM days}|null, h: [[date, {d,e}|null], ...]}}
    function attsch_js_map(array $map): object {
        $o = [];
        foreach ($map as $id => $s) {
            $h = [];
            foreach ($s['changes'] as $c) $h[] = [$c['d'], $c['old']];
            $o[(string)$id] = ['c' => $s['cur'], 'h' => $h];
        }
        return (object)$o;
    }
}

/* ════════════════════════════════════════════════════════════════════
   NEW (OJT ends at the required hours): a student's OJT ends on the day their
   rendered duty hours (all logs, all companies — same total as the Student List
   and the OJT End marker) reach the "Total Required Hours" of their course on
   course_offering.php. From the next day on, nothing is required of the student:
   no Absent / Missed / Incomplete, no new sign-ins. A student without a Course
   Offering (or without a total) simply never ends. A day that holds a real entry
   is always shown as recorded.
   ════════════════════════════════════════════════════════════════════ */
if (!function_exists('ojtend_dates')) {
    // [student_id => 'Y-m-d' (the day the required hours were reached)]; students who have not reached them are left out
    function ojtend_dates($conn, array $ids): array {
        static $cache = [];
        $ids = array_values(array_unique(array_filter(array_map('intval', $ids))));
        sort($ids);
        if (empty($ids)) return [];
        $key = implode(',', $ids);
        if (isset($cache[$key])) return $cache[$key];
        $out = [];
        try {
            $norm = function($c) { $c = preg_replace('/\s+/', ' ', trim((string)$c)); return function_exists('mb_strtolower') ? mb_strtolower($c) : strtolower($c); };
            $hasCol = function($table, $col) use ($conn) {
                $r = $conn->query("SHOW COLUMNS FROM `$table` LIKE '" . $conn->real_escape_string($col) . "'");
                return $r && $r->num_rows > 0;
            };
            $rules = [];
            $r = $conn->query("SELECT course, total_hours FROM course_offerings");
            if ($r) { while ($row = $r->fetch_assoc()) { if ((int)$row['total_hours'] > 0) $rules[$norm($row['course'])] = (int)$row['total_hours']; } }
            if (empty($rules)) return $cache[$key] = [];

            $courses = [];
            if ($hasCol('users', 'course')) {
                $r = $conn->query("SELECT id, course FROM users WHERE id IN ($key)");
                if ($r) { while ($row = $r->fetch_assoc()) if (trim((string)$row['course']) !== '') $courses[(int)$row['id']] = $row['course']; }
            }
            if ($hasCol('student_information', 'course')) {
                $r = $conn->query("SELECT user_id, MAX(course) AS course FROM student_information WHERE user_id IN ($key) GROUP BY user_id");
                if ($r) { while ($row = $r->fetch_assoc()) if (!isset($courses[(int)$row['user_id']]) && trim((string)$row['course']) !== '') $courses[(int)$row['user_id']] = $row['course']; }
            }

            $need = [];
            foreach ($ids as $i) { $k = $norm($courses[$i] ?? ''); if ($k !== '' && isset($rules[$k])) $need[$i] = (int)round($rules[$k] * 3600); }
            if (empty($need)) return $cache[$key] = [];

            $sec = function($p) {
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
            };
            $needList = implode(',', array_keys($need));
            $r = $conn->query("SELECT user_id, date, SUM(" . $sec('am') . " + " . $sec('pm') . ") AS secs
                               FROM attendance_logs WHERE user_id IN ($needList) GROUP BY user_id, date ORDER BY user_id, date ASC");
            $running = [];
            if ($r) {
                while ($row = $r->fetch_assoc()) {
                    $u = (int)$row['user_id'];
                    if (isset($out[$u])) continue;
                    $running[$u] = ($running[$u] ?? 0) + (int)round((float)$row['secs']);
                    if ($running[$u] >= $need[$u]) $out[$u] = $row['date'];
                }
            }
        } catch (\Throwable $e) { $out = []; }
        return $cache[$key] = $out;
    }
}
if (!function_exists('ojtend_is_after')) {
    // true when $day is after the student's OJT end date (the day the required hours were reached)
    function ojtend_is_after(array $endMap, $studentId, string $day): bool {
        $e = $endMap[(int)$studentId] ?? null;
        return $e !== null && $day > $e;
    }
}

/* ════════════════════════════════════════════════════════════════════
   NEW (no fixed OJT window): there is no "4 months" limit any more. A student's OJT ends on the day their rendered duty hours reach the
   "Total Required Hours" of their course on course_offering.php (ojtend_dates() above); until they get there the end is ESTIMATED from the
   hours still needed and the course's daily hours (Mon–Fri duty days) — the same rule as the "OJT End (est.)" marker of the Monthly Attendance Summary.
   attm_ojt_horizon() is the latest of those end dates over the company's registered students (null when no student has a Course Offering with
   hours). It only decides how far the page DISPLAYS and how many per-day schedule rows are written: a day without a row of its own always
   uses the most recent schedule before it (here and in student_attendance.php), so a schedule never stops applying.
   ════════════════════════════════════════════════════════════════════ */
if (!function_exists('attm_ojt_horizon')) {
    function attm_ojt_horizon($conn, $company_id): ?string {
        try {
            $ids = [];
            $r = $conn->query("SELECT student_id FROM ojt_assignments WHERE company_id = " . (int)$company_id);
            if ($r) { while ($x = $r->fetch_assoc()) $ids[] = (int)$x['student_id']; }
            if (!$ids) return null;
            $list = implode(',', $ids);
            $norm = function($c) { $c = preg_replace('/\s+/', ' ', trim((string)$c)); return function_exists('mb_strtolower') ? mb_strtolower($c) : strtolower($c); };
            $hasCol = function($table, $col) use ($conn) {
                $q = $conn->query("SHOW COLUMNS FROM `$table` LIKE '" . $conn->real_escape_string($col) . "'");
                return $q && $q->num_rows > 0;
            };
            $rules = [];
            $q = $conn->query("SELECT course, total_hours, daily_hours FROM course_offerings");
            if ($q) { while ($x = $q->fetch_assoc()) { if ((float)$x['total_hours'] > 0 && (float)$x['daily_hours'] > 0) $rules[$norm($x['course'])] = ['total' => (float)$x['total_hours'], 'daily' => (float)$x['daily_hours']]; } }
            if (!$rules) return null;

            $courses = [];
            if ($hasCol('users', 'course')) {
                $q = $conn->query("SELECT id, course FROM users WHERE id IN ($list)");
                if ($q) { while ($x = $q->fetch_assoc()) if (trim((string)$x['course']) !== '') $courses[(int)$x['id']] = $x['course']; }
            }
            if ($hasCol('student_information', 'course')) {
                $q = $conn->query("SELECT user_id, MAX(course) AS course FROM student_information WHERE user_id IN ($list) GROUP BY user_id");
                if ($q) { while ($x = $q->fetch_assoc()) if (!isset($courses[(int)$x['user_id']]) && trim((string)$x['course']) !== '') $courses[(int)$x['user_id']] = $x['course']; }
            }

            // rendered seconds and the last log day of every student (all logs, all companies — the same total as the OJT End marker)
            $sec = function($p) {
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
            };
            $rend = [];
            $q = $conn->query("SELECT user_id, MAX(date) AS last_d, COALESCE(SUM(" . $sec('am') . " + " . $sec('pm') . "), 0) AS secs FROM attendance_logs WHERE user_id IN ($list) GROUP BY user_id");
            if ($q) { while ($x = $q->fetch_assoc()) $rend[(int)$x['user_id']] = ['last' => $x['last_d'], 'secs' => (int)round((float)$x['secs'])]; }

            $reached = ojtend_dates($conn, $ids);   // the day each student who got there reached the required hours
            $today = (new DateTime('now', new DateTimeZone('Asia/Manila')))->format('Y-m-d');
            $addDuty = function(string $from, int $n) {   // the $n-th duty day (Mon–Fri), $from (or the next duty day) being day 1
                $d = new DateTime($from);
                $isDuty = fn(DateTime $x) => !in_array((int)$x->format('w'), [0, 6], true);
                while (!$isDuty($d)) $d->modify('+1 day');
                for ($i = 1; $i < max(1, $n); $i++) { $d->modify('+1 day'); while (!$isDuty($d)) $d->modify('+1 day'); }
                return $d->format('Y-m-d');
            };
            $best = null;
            foreach ($ids as $sid) {
                $rule = $rules[$norm($courses[$sid] ?? '')] ?? null;
                if (!$rule) continue;                                  // no Course Offering hours: this student's OJT has no computed end
                $required = (int)round($rule['total'] * 3600);
                $rendered = $rend[$sid]['secs'] ?? 0;
                if ($rendered >= $required) {
                    $end = $reached[$sid] ?? ($rend[$sid]['last'] ?? null);
                } else {
                    $days = (int)ceil(round((($required - $rendered) / 3600) / $rule['daily'], 6));
                    $base = new DateTime($today);
                    if (!empty($rend[$sid]['last']) && $rend[$sid]['last'] >= $today) $base->modify('+1 day');
                    $end = $addDuty($base->format('Y-m-d'), $days);
                }
                if ($end && ($best === null || $end > $best)) $best = $end;
            }
            return $best;
        } catch (\Throwable $e) {
            error_log('attendance_management.php: OJT end horizon failed: ' . $e->getMessage());
            return null;
        }
    }
}
if (!function_exists('attm_scope_phrase')) {
    // "through <date> …" when the end of the company's OJT is known, otherwise "until each student's OJT ends …"
    function attm_scope_phrase(?string $horizon): string {
        if ($horizon !== null && $horizon >= date('Y-m-d')) {
            return 'through ' . date('F j, Y', strtotime($horizon)) . ' (estimated; each student\'s OJT ends once they reach the required hours of their course)';
        }
        return 'until each student\'s OJT ends (once they reach the required hours of their course)';
    }
}

/* ════════════════════════════════════════════════════════════════════
   UPDATED (Live updates instead of auto page reload): a small fingerprint
   of everything this page displays (attendance logs, late requests,
   schedules, assigned students, course rules and today's date). The page
   polls it and, only when it changes, pulls the fresh page in the
   background and swaps the affected panels in place — no page reload.
   ════════════════════════════════════════════════════════════════════ */
if (!function_exists('attm_live_signature')) {
    function attm_live_signature($conn, $company_id): string {
        $cid   = (int)$company_id;
        $parts = [date('Y-m-d H:i')]; // minute bucket: today's Present / Incomplete / Absent change as duty windows close, with no DB write
        $queries = [
            "SELECT COUNT(*) AS c, COALESCE(SUM(CRC32(CONCAT_WS('|', user_id, date, IFNULL(am_time_in,''), IFNULL(am_time_out,''), IFNULL(pm_time_in,''), IFNULL(pm_time_out,'')))), 0) AS s
             FROM attendance_logs WHERE company_id = $cid",
            "SELECT COUNT(*) AS c, COALESCE(SUM(CRC32(CONCAT_WS('|', id, IFNULL(status,''), date, IFNULL(type,'')))), 0) AS s
             FROM late_requests WHERE company_id = $cid",
            "SELECT COUNT(*) AS c, COALESCE(SUM(CRC32(CONCAT_WS('|', date, IFNULL(am_time_in_start,''), IFNULL(am_time_in_end,''), IFNULL(am_time_out_start,''), IFNULL(am_time_out_end,''), IFNULL(pm_time_in_start,''), IFNULL(pm_time_in_end,''), IFNULL(pm_time_out_start,''), IFNULL(pm_time_out_end,'')))), 0) AS s
             FROM attendance_settings WHERE company_id = $cid",
            "SELECT COUNT(*) AS c, COALESCE(SUM(student_id), 0) AS s FROM ojt_assignments WHERE company_id = $cid",
            "SELECT COUNT(*) AS c, COALESCE(SUM(CRC32(CONCAT_WS('|', user_id, IFNULL(day_sched,''), IFNULL(evening_sched,'')))), 0) AS s FROM student_information WHERE user_id IN (SELECT student_id FROM ojt_assignments WHERE company_id = $cid)", // NEW (student schedule)
            "SELECT COUNT(*) AS c, COALESCE(SUM(id), 0) AS s FROM student_schedule_changes WHERE company_id = $cid", // NEW (student schedule): absent table is ignored below
            "SELECT COUNT(*) AS c, COALESCE(SUM(CRC32(CONCAT_WS('|', course, total_hours, daily_hours))), 0) AS s FROM course_offerings",
            "SELECT COUNT(*) AS c, COALESCE(SUM(id), 0) AS s FROM ojt_applications WHERE company_id = $cid AND phase = 'pending'", // sidebar badge: OJT Student List
            "SELECT COUNT(*) AS c, COALESCE(SUM(r.id), 0) AS s FROM reports r
             WHERE r.company_id = $cid AND (r.remark IS NULL OR r.remark <> 'Wrong Document') AND (r.company_viewed_at IS NULL OR r.submitted_at > r.company_viewed_at)", // sidebar badge: Company Reports (new weekly reports)
        ];
        foreach ($queries as $q) {
            try {
                $r = $conn->query($q);
                $row = $r ? $r->fetch_assoc() : null;
                $parts[] = $row ? ($row['c'] . ':' . $row['s']) : '-';
            } catch (\Throwable $e) { $parts[] = '-'; }
        }
        return md5(implode('|', $parts));
    }
}

/* ════════════════════════════════════════════════════════════════════
   NEW (late request legitimacy check): evidence the SERVER records for every late request
   (the student's browser cannot change it) so the supervisor can judge whether it is genuine:
     late_requests.submit_ip / submit_ua / photo_hash / photo_valid, and
     attendance_device_log — the device, network and photo fingerprint of every regular sign-in and late
     request, which the request is compared against on attendance_management.php.
   Columns / table are created automatically the first time they are needed (existing databases keep working).
   ════════════════════════════════════════════════════════════════════ */
if (!function_exists('ensureLateRequestEvidence')) {
    function ensureLateRequestEvidence($conn): bool {
        static $ok = null;
        if ($ok !== null) return $ok;
        try {
            $have = [];
            $r = $conn->query("SHOW COLUMNS FROM late_requests");
            if ($r) { while ($c = $r->fetch_assoc()) $have[$c['Field']] = true; }
            $add = [
                'submit_ip'   => "VARCHAR(45) NULL",
                'submit_ua'   => "VARCHAR(255) NULL",
                'photo_hash'  => "CHAR(64) NULL",
                'photo_valid' => "TINYINT(1) NULL",
            ];
            foreach ($add as $col => $def) {
                if (!isset($have[$col])) {
                    try { $conn->query("ALTER TABLE late_requests ADD COLUMN `$col` $def"); } catch (\Throwable $e) { /* added by a parallel request */ }
                }
            }
            $conn->query("CREATE TABLE IF NOT EXISTS attendance_device_log (
                id INT AUTO_INCREMENT PRIMARY KEY,
                user_id INT NOT NULL,
                company_id INT NOT NULL,
                date DATE NOT NULL,
                slot VARCHAR(20) NOT NULL,
                kind VARCHAR(12) NOT NULL,
                ip VARCHAR(45) NULL,
                ua VARCHAR(255) NULL,
                photo_hash CHAR(64) NULL,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                KEY idx_adl_user (user_id, created_at),
                KEY idx_adl_hash (photo_hash)
            )");
            $r = $conn->query("SHOW COLUMNS FROM late_requests LIKE 'photo_hash'");
            return $ok = ($r && $r->num_rows > 0);
        } catch (\Throwable $e) { return $ok = false; }
    }
}

if (!function_exists('attm_lr_ua_key')) {
    // "Chrome / Windows" style key of a user-agent string (used to compare devices)
    function attm_lr_ua_key(?string $ua): string {
        $ua = (string)$ua;
        if ($ua === '') return '';
        $b = 'Browser';
        if (preg_match('/Edg(e|A|iOS)?\//i', $ua))        $b = 'Edge';
        elseif (preg_match('/OPR\/|Opera/i', $ua))         $b = 'Opera';
        elseif (preg_match('/Firefox|FxiOS/i', $ua))       $b = 'Firefox';
        elseif (preg_match('/Chrome|CriOS/i', $ua))        $b = 'Chrome';
        elseif (preg_match('/Safari/i', $ua))              $b = 'Safari';
        $o = 'Unknown OS';
        if (preg_match('/Android/i', $ua))                 $o = 'Android';
        elseif (preg_match('/iPhone|iPad|iPod/i', $ua))    $o = 'iOS';
        elseif (preg_match('/Windows/i', $ua))             $o = 'Windows';
        elseif (preg_match('/Mac OS X|Macintosh/i', $ua))  $o = 'Mac';
        elseif (preg_match('/Linux|X11/i', $ua))           $o = 'Linux';
        return $b . ' on ' . $o;
    }
}
if (!function_exists('attm_lr_net_key')) {
    // network of an IP address: IPv4 /24, IPv6 /48 (so a changing last number on the same network still matches)
    function attm_lr_net_key(?string $ip): string {
        $ip = (string)$ip;
        if ($ip === '') return '';
        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) { $p = explode('.', $ip); return $p[0] . '.' . $p[1] . '.' . $p[2]; }
        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6)) { $b = @inet_pton($ip); return $b === false ? '' : bin2hex(substr($b, 0, 6)); }
        return '';
    }
}
if (!function_exists('attm_lr_mask_ip')) {
    function attm_lr_mask_ip(?string $ip): string {
        $ip = (string)$ip;
        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) { $p = explode('.', $ip); return $p[0] . '.' . $p[1] . '.' . $p[2] . '.xxx'; }
        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6)) { $p = explode(':', $ip); return implode(':', array_slice($p, 0, 3)) . ':…'; }
        return '';
    }
}
if (!function_exists('attm_lr_evidence')) {
    /* Legitimacy check of ONE late request. Only evidence the server recorded or can verify is used:
         photo (readable, not re-used), device + network compared with the student's regular sign-ins,
         the rest of that day's attendance, how long after the window it was sent, how often the student asks,
         and whether the same reason was already used. Returns
         ['level' => 'ok'|'review'|'risk', 'label' => string, 'signals' => [['level' => 'ok'|'info'|'review'|'risk', 'text' => string], ...],
          'device' => 'Chrome on Windows · 203.0.113.xxx'] — never throws. */
    function attm_lr_evidence($conn, int $companyId, array $r): array {
        $sig = [];
        $add = function(string $lvl, string $txt) use (&$sig) { $sig[] = ['level' => $lvl, 'text' => $txt]; };
        $device = '';
        $detail = null;
        $set = null;
        try {
            $id   = (int)($r['id'] ?? 0);
            $sid  = (int)($r['student_id'] ?? 0);
            $date = (string)($r['date'] ?? '');
            $type = (string)($r['type'] ?? '');
            $kind = 'late'; // overtime requests were removed (old overtime rows are handled as late requests)
            $labels = ['am_time_in' => 'AM Sign In', 'am_time_out' => 'AM Sign Out', 'pm_time_in' => 'PM Sign In', 'pm_time_out' => 'PM Sign Out'];
            $slotLbl = $labels[$type] ?? $type;

            // 1) Photo
            $hasPhoto = !empty($r['has_photo']) || !empty($r['photo']);
            if (!$hasPhoto) {
                $add('review', 'No photo was attached to this request.');
            } elseif (isset($r['photo_valid']) && $r['photo_valid'] !== null && (int)$r['photo_valid'] === 0) {
                $add('risk', 'The attached photo is not a valid image.');
            } else {
                $hash = (string)($r['photo_hash'] ?? '');
                $dupMsg = null;
                if ($hash !== '') {
                    $q = $conn->prepare("SELECT lr2.student_id, lr2.date, CONCAT(u.first_name, ' ', u.last_name) AS nm FROM late_requests lr2 JOIN users u ON u.id = lr2.student_id WHERE lr2.photo_hash = ? AND lr2.id <> ? ORDER BY lr2.id ASC LIMIT 1");
                    $q->bind_param("si", $hash, $id); $q->execute();
                    $d = $q->get_result()->fetch_assoc(); $q->close();
                    if ($d) {
                        $dupMsg = ((int)$d['student_id'] === $sid)
                            ? 'This exact photo was already used in an earlier request of this student (' . date('M j, Y', strtotime($d['date'])) . ').'
                            : 'This exact photo was already submitted by another student (' . trim($d['nm']) . ', ' . date('M j, Y', strtotime($d['date'])) . ').';
                    } else {
                        $q = $conn->prepare("SELECT user_id, date FROM attendance_device_log WHERE photo_hash = ? AND NOT (kind IN ('late','overtime') AND user_id = ? AND date = ? AND slot = ?) ORDER BY id ASC LIMIT 1");
                        $q->bind_param("siss", $hash, $sid, $date, $type); $q->execute();
                        $d = $q->get_result()->fetch_assoc(); $q->close();
                        if ($d) $dupMsg = 'This exact photo was already used for another attendance entry (' . date('M j, Y', strtotime($d['date'])) . ').';
                    }
                }
                if ($dupMsg) $add('risk', $dupMsg);
                else         $add('ok', 'A readable photo is attached and has not been used before.');
            }

            // 2) Device / network compared with the student's regular sign-ins (last 60 days)
            $ip = (string)($r['submit_ip'] ?? ''); $ua = (string)($r['submit_ua'] ?? '');
            if ($ip !== '' || $ua !== '') $device = trim(attm_lr_ua_key($ua) . ($ip !== '' ? ' · ' . attm_lr_mask_ip($ip) : ''), ' ·');
            if ($ip === '' && $ua === '') {
                $add('info', 'Device and network were not recorded for this request (sent before this check existed).');
            } else {
                $q = $conn->prepare("SELECT ip, ua FROM attendance_device_log WHERE user_id = ? AND kind = 'attendance' AND created_at >= (NOW() - INTERVAL 60 DAY) ORDER BY id DESC LIMIT 40");
                $q->bind_param("i", $sid); $q->execute();
                $hist = $q->get_result()->fetch_all(MYSQLI_ASSOC); $q->close();
                if (empty($hist)) {
                    $add('info', 'No earlier sign-ins are recorded to compare this device or network with.');
                } else {
                    $uaKey = attm_lr_ua_key($ua); $netKey = attm_lr_net_key($ip);
                    $sameUa = false; $sameNet = false;
                    foreach ($hist as $h) {
                        if ($uaKey !== '' && attm_lr_ua_key($h['ua']) === $uaKey)   $sameUa = true;
                        if ($netKey !== '' && attm_lr_net_key($h['ip']) === $netKey) $sameNet = true;
                    }
                    if ($sameUa && $sameNet)      $add('ok', 'Sent from the same device and network as the student\'s regular sign-ins.');
                    elseif ($sameUa)              $add('review', 'Sent from a different network than the student\'s regular sign-ins (same device).');
                    elseif ($sameNet)             $add('review', 'Sent from a different device than the student\'s regular sign-ins (same network).');
                    else                          $add(count($hist) >= 3 ? 'risk' : 'review', 'Sent from a device and network the student has never signed in from.');
                }
            }

            // 3) The rest of that day's attendance
            $q = $conn->prepare("SELECT am_time_in, am_time_out, pm_time_in, pm_time_out FROM attendance_logs WHERE user_id = ? AND date = ? AND company_id = ? LIMIT 1");
            $q->bind_param("isi", $sid, $date, $companyId); $q->execute();
            $log = $q->get_result()->fetch_assoc(); $q->close();
            $real = 0;
            foreach (['am_time_in', 'am_time_out', 'pm_time_in', 'pm_time_out'] as $c) { $v = $log[$c] ?? null; if ($v !== null && $v !== '' && $v !== 'missed') $real++; }
            if ($real === 0) {
                $add('review', 'The student has no other attendance recorded on this day.');
            } else {
                $add('ok', 'The student has ' . $real . ' other recorded entr' . ($real === 1 ? 'y' : 'ies') . ' on this day.');
            }
            if ($kind === 'late' && in_array($type, ['am_time_out', 'pm_time_out'], true)) {
                $inV = $log[$type === 'am_time_out' ? 'am_time_in' : 'pm_time_in'] ?? null;
                if ($inV === null || $inV === '' || $inV === 'missed') $add('review', 'No ' . ($type === 'am_time_out' ? 'AM' : 'PM') . ' Sign In is recorded for this duty, so there is nothing to sign out from.');
            }

            // 4) How long after the window it was sent (information)
            $endKeys = ['am_time_in' => 'am_time_in_end', 'am_time_out' => 'am_time_out_end', 'pm_time_in' => 'pm_time_in_end', 'pm_time_out' => 'pm_time_out_end'];
            if (isset($endKeys[$type]) && $date !== '') {
                $q = $conn->prepare("SELECT * FROM attendance_settings WHERE company_id = ? AND (date = ? OR (is_auto = 1 AND date <= ?)) ORDER BY (date = ?) DESC, date DESC LIMIT 1");
                $q->bind_param("isss", $companyId, $date, $date, $date); $q->execute();
                $set = $q->get_result()->fetch_assoc(); $q->close();
                $endT = $set[$endKeys[$type]] ?? null;
                $sentTs = strtotime((string)($r['created_at'] ?? ''));
                if ($endT && $sentTs) {
                    $endTs = strtotime($date . ' ' . $endT);
                    if ($endTs) {
                        $mins = (int)round(($sentTs - $endTs) / 60);
                        if ($mins >= 0) $add('info', 'Sent ' . ($mins >= 60 ? floor($mins / 60) . ' h ' . ($mins % 60) . ' min' : $mins . ' min') . ' after the ' . $slotLbl . ' window closed.');
                    }
                }
            }

            // 4b) What allowing this late request records: the Sign Out is credited at the SCHEDULED sign-out time
            //     (never later than the moment the request was sent, never before the Sign In).
            if (in_array($type, ['am_time_out', 'pm_time_out'], true) && $set) {
                $per = ($type === 'am_time_out') ? 'am' : 'pm';
                $fmt = function($ts) { return $ts ? date('g:i A', $ts) : null; };
                $dur = function($sec) { $m = (int)round(max(0, $sec) / 60); return floor($m / 60) . 'h ' . ($m % 60) . 'm'; };
                $toTs = function($v) use ($date) {
                    if (!$v || $v === 'missed') return null;
                    $v = (strpos($v, ' ') === false) ? ($date . ' ' . $v) : $v;
                    $t = strtotime($v);
                    return $t === false ? null : $t;
                };
                $inTs    = $toTs($log[$per . '_time_in'] ?? null);
                $schedTs = $toTs($set[$per . '_time_out_start'] ?? null);
                $sentTs2 = strtotime((string)($r['created_at'] ?? '')) ?: null;
                $detail = [
                    'period' => strtoupper($per),
                    'in' => $fmt($inTs), 'sched_out' => $fmt($schedTs), 'sent' => $fmt($sentTs2),
                    'late_credit' => null,
                ];
                if ($inTs && $schedTs && $sentTs2) {
                    $lateOut = min($schedTs, $sentTs2); if ($lateOut < $inTs) $lateOut = $inTs;
                    $detail['late_credit'] = $dur($lateOut - $inTs);
                    $add('info', 'Allowing it credits ' . $detail['late_credit'] . ' (sign-in to the scheduled sign-out, ' . $detail['sched_out'] . ').');
                } elseif (!$inTs) {
                    $detail['late_credit'] = '0h 0m';
                }
            }

            // 5) How often this student asks
            $q = $conn->prepare("SELECT COUNT(*) AS n, SUM(status = 'rejected') AS rej FROM late_requests WHERE student_id = ? AND id <> ? AND created_at >= (NOW() - INTERVAL 30 DAY)");
            $q->bind_param("ii", $sid, $id); $q->execute();
            $f = $q->get_result()->fetch_assoc(); $q->close();
            $n = (int)($f['n'] ?? 0); $rej = (int)($f['rej'] ?? 0);
            if ($n >= 5)      $add('risk',   $n . ' other late requests from this student in the last 30 days.');
            elseif ($n >= 3)  $add('review', $n . ' other late requests from this student in the last 30 days.');
            else              $add('ok',     $n === 0 ? 'No other late requests from this student in the last 30 days.' : $n . ' other late request' . ($n === 1 ? '' : 's') . ' from this student in the last 30 days.');
            if ($rej >= 2)    $add('review', $rej . ' of this student\'s recent requests were rejected.');

            // 6) The reason
            $reason = trim((string)($r['reason'] ?? ''));
            $norm = preg_replace('/[^a-z0-9]+/', ' ', function_exists('mb_strtolower') ? mb_strtolower($reason) : strtolower($reason));
            $norm = trim($norm);
            if ($norm !== '') {
                if ((function_exists('mb_strlen') ? mb_strlen($reason) : strlen($reason)) < 15) $add('review', 'The reason is very short.');
                $q = $conn->prepare("SELECT student_id, reason FROM late_requests WHERE company_id = ? AND id <> ? AND (student_id = ? OR date = ?) ORDER BY id DESC LIMIT 200");
                $q->bind_param("iiis", $companyId, $id, $sid, $date); $q->execute();
                $others = $q->get_result()->fetch_all(MYSQLI_ASSOC); $q->close();
                $sameSelf = false; $sameOther = false;
                foreach ($others as $o) {
                    $on = trim(preg_replace('/[^a-z0-9]+/', ' ', function_exists('mb_strtolower') ? mb_strtolower((string)$o['reason']) : strtolower((string)$o['reason'])));
                    if ($on === $norm) { if ((int)$o['student_id'] === $sid) $sameSelf = true; else $sameOther = true; }
                }
                if ($sameOther)     $add('risk',   'The same reason was submitted by another student on this day.');
                if ($sameSelf)      $add('review', 'The student used exactly the same reason in an earlier request.');
            }
        } catch (\Throwable $e) {
            $add('info', 'Some checks could not be completed.');
        }
        $rank = ['ok' => 0, 'info' => 0, 'review' => 1, 'risk' => 2];
        $max = 0;
        foreach ($sig as $s) $max = max($max, $rank[$s['level']] ?? 0);
        $level = $max >= 2 ? 'risk' : ($max === 1 ? 'review' : 'ok');
        $label = ['ok' => 'Looks consistent', 'review' => 'Review before approving', 'risk' => 'High risk — verify with the student first'][$level];
        return ['level' => $level, 'label' => $label, 'signals' => $sig, 'device' => $device, 'detail' => $detail];
    }
}

// ─────────────────────────────────────────────────────────────────────────────
// XLSX EXPORT HANDLER
// (formerly the separate export_attendance_xlsx.php — the Export XLSX button of the
//  Monthly Attendance Summary now calls attendance_management.php?export=xlsx&month=YYYY-MM)
//
// Filename format: {CompanyName}_{MonAbbrev}{Year}.xlsx   e.g.  AcmeCorp_Jan2025.xlsx
// Uses the same student schedule (Day = AM duty, Evening = PM duty) and OJT-end rules as the page.
// ─────────────────────────────────────────────────────────────────────────────

/**
 * Minimal pure-PHP XLSX writer.
 * Supports: cell values, bold header row, column auto-width, cell fill colours.
 */
if (!function_exists('attm_build_xlsx')) {
function attm_build_xlsx(array $header, array $rows, string $sheet_title,
                    string $company_display, string $month_label): string
{
    // ── Colour map for status values ──────────────────────────────────────────
    $status_fills = [
        'PRESENT'    => 'C6EFCE',   // green tint
        'ABSENT'     => 'FFC7CE',   // red tint
        'INCOMPLETE' => 'FFEB9C',   // amber tint
        'OFF'        => 'E4DFEC',   // purple tint (Day Off)
        'OFF'        => 'E4DFEC',
    ];

    // ── Shared strings ────────────────────────────────────────────────────────
    $sst    = [];    // index => string
    $sstMap = [];    // string => index

    $si = function(string $v) use (&$sst, &$sstMap): int {
        if (!isset($sstMap[$v])) {
            $sstMap[$v] = count($sst);
            $sst[]      = $v;
        }
        return $sstMap[$v];
    };

    // ── Figure out column widths (max char length per column) ─────────────────
    $col_widths = [];
    foreach ($header as $ci => $h) {
        $col_widths[$ci] = mb_strlen((string)$h);
    }
    foreach ($rows as $row) {
        foreach ($row as $ci => $cell) {
            $len = mb_strlen((string)$cell);
            if (!isset($col_widths[$ci]) || $len > $col_widths[$ci]) {
                $col_widths[$ci] = $len;
            }
        }
    }
    // Add padding; cap at 40; Name column wider
    foreach ($col_widths as $ci => &$w) {
        $w = min(40, max(9, $w + 4));
    }
    unset($w);
    $col_widths[0] = min(40, max(20, $col_widths[0] ?? 20)); // Name col

    // ── Build sheet XML ───────────────────────────────────────────────────────
    // Style indices (defined in styles.xml below):
    //   0 = default, 1 = bold header, 2 = present, 3 = absent, 4 = incomplete, 5 = off
    $style_map = [
        'PRESENT'    => 2,
        'ABSENT'     => 3,
        'INCOMPLETE' => 4,
        'OFF'        => 5,
    ];

    $col_letter = function(int $n): string {
        $s = '';
        $n++;   // 0-indexed → 1-indexed
        while ($n > 0) {
            $n--;
            $s = chr(65 + ($n % 26)) . $s;
            $n = intdiv($n, 26);
        }
        return $s;
    };

    $xml_sheet = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>';
    $xml_sheet .= '<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">';

    // column widths
    $xml_sheet .= '<cols>';
    foreach ($col_widths as $ci => $w) {
        $c1 = $c2 = $ci + 1;
        $xml_sheet .= '<col min="'.$c1.'" max="'.$c2.'" width="'.$w.'" customWidth="1"/>';
    }
    $xml_sheet .= '</cols>';

    $xml_sheet .= '<sheetData>';

    // Title row (row 1) — merged later via mergeCell; put text in A1
    $title_text = $company_display . ' — Attendance Summary — ' . $month_label;
    $xml_sheet .= '<row r="1"><c r="A1" t="s" s="6"><v>'.$si($title_text).'</v></c></row>';

    // Header row (row 2)
    $xml_sheet .= '<row r="2">';
    foreach ($header as $ci => $h) {
        $col = $col_letter($ci);
        $xml_sheet .= '<c r="'.$col.'2" t="s" s="1"><v>'.$si((string)$h).'</v></c>';
    }
    $xml_sheet .= '</row>';

    // Data rows (start at row 3)
    $excel_row = 3;
    foreach ($rows as $row) {
        $xml_sheet .= '<row r="'.$excel_row.'">';
        foreach ($row as $ci => $cell) {
            $col   = $col_letter($ci);
            $ref   = $col . $excel_row;
            $upper = strtoupper(trim((string)$cell));
            $s_idx = isset($style_map[$upper]) ? $style_map[$upper] : 0;
            if ($cell === '') {
                $xml_sheet .= '<c r="'.$ref.'" s="'.$s_idx.'"/>';
            } else {
                $xml_sheet .= '<c r="'.$ref.'" t="s" s="'.$s_idx.'"><v>'.$si((string)$cell).'</v></c>';
            }
        }
        $xml_sheet .= '</row>';
        $excel_row++;
    }

    $xml_sheet .= '</sheetData>';

    // Merge title row across all columns
    $total_cols  = count($header);
    $last_col    = $col_letter($total_cols - 1);
    $xml_sheet .= '<mergeCells><mergeCell ref="A1:'.$last_col.'1"/></mergeCells>';

    $xml_sheet .= '</worksheet>';

    // ── Shared strings XML ────────────────────────────────────────────────────
    $xml_sst = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>';
    $xml_sst .= '<sst xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" count="'.count($sst).'" uniqueCount="'.count($sst).'">';
    foreach ($sst as $sv) {
        $xml_sst .= '<si><t xml:space="preserve">'.htmlspecialchars($sv, ENT_XML1, 'UTF-8').'</t></si>';
    }
    $xml_sst .= '</sst>';

    // ── Styles XML ────────────────────────────────────────────────────────────
    // Fill indices: 0=none,1=gray(reserved),2=present(green),3=absent(red),4=incomplete(amber),5=off(purple),6=header(dark blue),7=title(navy)
    $xml_styles = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>';
    $xml_styles .= '<styleSheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">';

    // fonts: 0=default, 1=bold, 2=bold+white(for header), 3=bold+dark(for status), 4=bold+white+larger(title)
    $xml_styles .= '<fonts count="5">';
    $xml_styles .= '<font><sz val="11"/><name val="Arial"/></font>';                                                         // 0 default
    $xml_styles .= '<font><b/><sz val="11"/><name val="Arial"/></font>';                                                     // 1 bold
    $xml_styles .= '<font><b/><sz val="11"/><color rgb="FFFFFFFF"/><name val="Arial"/></font>';                              // 2 bold white (header bg)
    $xml_styles .= '<font><b/><sz val="10"/><name val="Arial"/></font>';                                                     // 3 bold dark (status cells)
    $xml_styles .= '<font><b/><sz val="13"/><color rgb="FFFFFFFF"/><name val="Arial"/></font>';                              // 4 bold white large (title)
    $xml_styles .= '</fonts>';

    // fills: 0=none,1=gray,2=present,3=absent,4=incomplete,5=off,6=header,7=title
    $xml_styles .= '<fills count="8">';
    $xml_styles .= '<fill><patternFill patternType="none"/></fill>';
    $xml_styles .= '<fill><patternFill patternType="gray125"/></fill>';
    $xml_styles .= '<fill><patternFill patternType="solid"><fgColor rgb="FFC6EFCE"/></patternFill></fill>';   // 2 present
    $xml_styles .= '<fill><patternFill patternType="solid"><fgColor rgb="FFFFC7CE"/></patternFill></fill>';   // 3 absent
    $xml_styles .= '<fill><patternFill patternType="solid"><fgColor rgb="FFFFEB9C"/></patternFill></fill>';   // 4 incomplete
    $xml_styles .= '<fill><patternFill patternType="solid"><fgColor rgb="FFE4DFEC"/></patternFill></fill>';   // 5 off
    $xml_styles .= '<fill><patternFill patternType="solid"><fgColor rgb="FF1565C0"/></patternFill></fill>';   // 6 header dark blue
    $xml_styles .= '<fill><patternFill patternType="solid"><fgColor rgb="FF0D2B6B"/></patternFill></fill>';   // 7 title navy
    $xml_styles .= '</fills>';

    // borders
    $border_thin = '<border><left style="thin"><color rgb="FFD0D0D0"/></left><right style="thin"><color rgb="FFD0D0D0"/></right><top style="thin"><color rgb="FFD0D0D0"/></top><bottom style="thin"><color rgb="FFD0D0D0"/></bottom></border>';
    $xml_styles .= '<borders count="2">';
    $xml_styles .= '<border/>';
    $xml_styles .= $border_thin;
    $xml_styles .= '</borders>';

    // cellStyleXfs (required)
    $xml_styles .= '<cellStyleXfs count="1"><xf numFmtId="0" fontId="0" fillId="0" borderId="0"/></cellStyleXfs>';

    // cellXfs:
    // 0 = default (data)
    // 1 = header  (bold white text, dark-blue fill, thin border, centered)
    // 2 = present (bold, green fill, border, centered)
    // 3 = absent  (bold, red fill, border, centered)
    // 4 = incomplete (bold, amber fill, border, centered)
    // 5 = off     (bold, purple fill, border, centered)
    // 6 = title   (bold white, navy fill, larger, wrap, centered)
    $center  = '<alignment horizontal="center" vertical="center"/>';
    $wrap_c  = '<alignment horizontal="center" vertical="center" wrapText="1"/>';
    $xml_styles .= '<cellXfs count="7">';
    $xml_styles .= '<xf numFmtId="0" fontId="0" fillId="0" borderId="1" xfId="0"><alignment vertical="center"/></xf>'; // 0
    $xml_styles .= '<xf numFmtId="0" fontId="2" fillId="6" borderId="1" xfId="0">'.$center.'</xf>';  // 1 header
    $xml_styles .= '<xf numFmtId="0" fontId="3" fillId="2" borderId="1" xfId="0">'.$center.'</xf>';  // 2 present
    $xml_styles .= '<xf numFmtId="0" fontId="3" fillId="3" borderId="1" xfId="0">'.$center.'</xf>';  // 3 absent
    $xml_styles .= '<xf numFmtId="0" fontId="3" fillId="4" borderId="1" xfId="0">'.$center.'</xf>';  // 4 incomplete
    $xml_styles .= '<xf numFmtId="0" fontId="3" fillId="5" borderId="1" xfId="0">'.$center.'</xf>';  // 5 off
    $xml_styles .= '<xf numFmtId="0" fontId="4" fillId="7" borderId="1" xfId="0">'.$wrap_c.'</xf>'; // 6 title
    $xml_styles .= '</cellXfs>';

    $xml_styles .= '<cellStyles count="1"><cellStyle name="Normal" xfId="0" builtinId="0"/></cellStyles>';
    $xml_styles .= '</styleSheet>';

    // ── Workbook XML ──────────────────────────────────────────────────────────
    $xml_workbook = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>';
    $xml_workbook .= '<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">';
    $xml_workbook .= '<sheets><sheet name="'.htmlspecialchars($sheet_title, ENT_XML1).'" sheetId="1" r:id="rId1"/></sheets>';
    $xml_workbook .= '</workbook>';

    // ── Relationships ─────────────────────────────────────────────────────────
    $xml_wb_rels = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>';
    $xml_wb_rels .= '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">';
    $xml_wb_rels .= '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet1.xml"/>';
    $xml_wb_rels .= '<Relationship Id="rId2" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/sharedStrings" Target="sharedStrings.xml"/>';
    $xml_wb_rels .= '<Relationship Id="rId3" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/>';
    $xml_wb_rels .= '</Relationships>';

    $xml_root_rels = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>';
    $xml_root_rels .= '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">';
    $xml_root_rels .= '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/>';
    $xml_root_rels .= '</Relationships>';

    $xml_ct = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>';
    $xml_ct .= '<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">';
    $xml_ct .= '<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>';
    $xml_ct .= '<Default Extension="xml" ContentType="application/xml"/>';
    $xml_ct .= '<Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>';
    $xml_ct .= '<Override PartName="/xl/worksheets/sheet1.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>';
    $xml_ct .= '<Override PartName="/xl/sharedStrings.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sharedStrings+xml"/>';
    $xml_ct .= '<Override PartName="/xl/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml"/>';
    $xml_ct .= '</Types>';

    // ── ZIP it into XLSX ──────────────────────────────────────────────────────
    $tmp = tempnam(sys_get_temp_dir(), 'xlsx_');
    @unlink($tmp);
    $tmp .= '.xlsx';

    $zip = new ZipArchive();
    $zip->open($tmp, ZipArchive::CREATE | ZipArchive::OVERWRITE);
    $zip->addFromString('[Content_Types].xml',       $xml_ct);
    $zip->addFromString('_rels/.rels',               $xml_root_rels);
    $zip->addFromString('xl/workbook.xml',           $xml_workbook);
    $zip->addFromString('xl/_rels/workbook.xml.rels',$xml_wb_rels);
    $zip->addFromString('xl/worksheets/sheet1.xml',  $xml_sheet);
    $zip->addFromString('xl/sharedStrings.xml',      $xml_sst);
    $zip->addFromString('xl/styles.xml',             $xml_styles);
    $zip->close();

    $blob = file_get_contents($tmp);
    @unlink($tmp);
    return $blob;
}

}

if (!function_exists('attm_export_xlsx')) {
    function attm_export_xlsx($conn, $company_id) {
        if (!class_exists('ZipArchive')) {
            http_response_code(500);
            header('Content-Type: text/plain; charset=UTF-8');
            echo 'Excel export is unavailable: the PHP zip extension is not enabled on this server.';
            exit;
        }
        $company_id = (int)$company_id;

    // ── Fetch company name ────────────────────────────────────────────────────────
    $co_stmt = $conn->prepare("
        SELECT ci.company, u.first_name, u.last_name
        FROM users u
        LEFT JOIN company_information ci ON ci.user_id = u.id
        WHERE u.id = ? LIMIT 1
    ");
    $co_stmt->bind_param("i", $company_id);
    $co_stmt->execute();
    $co_row = $co_stmt->get_result()->fetch_assoc();
    $company_name_raw = !empty($co_row['company'])
        ? $co_row['company']
        : trim(($co_row['first_name'] ?? '') . ' ' . ($co_row['last_name'] ?? ''));
    if (!$company_name_raw) $company_name_raw = 'Company';
    // Sanitise for filename (remove chars that aren't word chars / spaces / hyphens)
    $company_name_safe = preg_replace('/[^\w\s\-]/', '', $company_name_raw);
    $company_name_safe = preg_replace('/\s+/', '_', trim($company_name_safe));

    // ── Month & date range ────────────────────────────────────────────────────────
    $exp_month = $_GET['month'] ?? date('Y-m');
    if (!preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', (string)$exp_month)) $exp_month = date('Y-m'); // NEW: bad / missing month falls back to the current month

    $s2 = $conn->prepare("SELECT MIN(date) as sd FROM attendance_settings WHERE company_id=?");
    $s2->bind_param("i", $company_id);
    $s2->execute();
    $sd_row = $s2->get_result()->fetch_assoc();
    $sd = $sd_row['sd'] ?? date('Y-m-d');

    if (date('Y-m', strtotime($sd)) === $exp_month) {
        $exp_start = $sd;
    } else {
        $exp_start = $exp_month . '-01';
    }
    $exp_end = date('Y-m-t', strtotime($exp_month . '-01'));

    // ── Filename: CompanyName_MonYear.xlsx ────────────────────────────────────────
    $month_abbrev = date('M', strtotime($exp_month . '-01'));   // e.g. "Jan"
    $year_4       = date('Y', strtotime($exp_month . '-01'));   // e.g. "2025"
    $filename     = $company_name_safe . '_' . $month_abbrev . $year_4 . '.xlsx';

    // ── Students ──────────────────────────────────────────────────────────────────
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


    $exp_sched = attsch_load($conn, array_keys($exp_students)); // NEW (student schedule)
    $exp_ojt_end = ojtend_dates($conn, array_keys($exp_students)); // NEW (OJT ends at the required hours)

    // ── Logs ──────────────────────────────────────────────────────────────────────
    $log_res = $conn->query("
        SELECT user_id, date, am_time_in, am_time_out, pm_time_in, pm_time_out
        FROM attendance_logs
        WHERE company_id = $company_id
          AND date BETWEEN '$exp_start' AND '$exp_end'
    ");
    $exp_logs = [];
    $exp_has_entry = []; // real times only (a "missed"-only row is not an entry)
    while ($lr = $log_res->fetch_assoc()) {
        foreach (['am_time_in','am_time_out','pm_time_in','pm_time_out'] as $c_) {
            if ($lr[$c_] !== null && $lr[$c_] !== '' && $lr[$c_] !== 'missed') { $exp_has_entry[$lr['user_id']][$lr['date']] = true; break; }
        }
        $dow   = (int)date('w', strtotime($lr['date']));
        $wknd  = ($dow === 0 || $dow === 6);
        $isMissed = fn($v) => ($v === 'missed');
        $hasVal   = fn($v) => ($v !== null && $v !== '' && $v !== 'missed');
        // NEW (student schedule): judged on the duty periods scheduled that day (Day = AM duty, Evening = PM duty);
        // a period holding a real entry always counts. Students on both duties are judged exactly as before.
        $per    = attsch_periods($exp_sched, $lr['user_id'], $lr['date']);
        $needAm = $per['am'] || $hasVal($lr['am_time_in']) || $hasVal($lr['am_time_out']);
        $needPm = $per['pm'] || $hasVal($lr['pm_time_in']) || $hasVal($lr['pm_time_out']);
        if (!$needAm && !$needPm) { $needAm = $needPm = true; }
        if ($wknd) {
            $st = 'OFF';
        } elseif ((!$needAm || ($hasVal($lr['am_time_in']) && $hasVal($lr['am_time_out'])))
               && (!$needPm || ($hasVal($lr['pm_time_in']) && $hasVal($lr['pm_time_out'])))) {
            $st = 'PRESENT';
        } elseif (($needAm && ($hasVal($lr['am_time_in']) || $isMissed($lr['am_time_in']) || $isMissed($lr['am_time_out'])))
               || ($needPm && ($hasVal($lr['pm_time_in']) || $isMissed($lr['pm_time_in']) || $isMissed($lr['pm_time_out'])))
               || ($per['am'] && $per['pm'] && ($hasVal($lr['am_time_in']) || $hasVal($lr['pm_time_in'])))) {
            $st = 'INCOMPLETE';
        } else {
            $st = 'ABSENT';
        }
        $exp_logs[$lr['user_id']][$lr['date']] = $st;
    }

    // ── Date columns ──────────────────────────────────────────────────────────────
    $exp_dates = [];
    for ($d = strtotime($exp_start); $d <= strtotime($exp_end); $d = strtotime('+1 day', $d)) {
        $exp_dates[] = date('Y-m-d', $d);
    }

    // ─────────────────────────────────────────────────────────────────────────────
    // Build the data array (header + rows)
    // ─────────────────────────────────────────────────────────────────────────────
    $today_str = date('Y-m-d');

    $header_row = ['Name'];
    foreach ($exp_dates as $d) {
        $dow = (int)date('w', strtotime($d));
        $label = date('D d', strtotime($d));
        if ($dow === 0 || $dow === 6) $label .= ' (Off)';
        $header_row[] = $label;
    }

    $data_rows = [];
    foreach ($exp_students as $sid => $stu) {
        $row = [$stu['first_name'] . ' ' . $stu['last_name']];
        foreach ($exp_dates as $d) {
            $dow = (int)date('w', strtotime($d));
            if ($dow === 0 || $dow === 6) {
                $row[] = 'OFF';
            } elseif ($d > $today_str) {
                $row[] = '';
            } elseif (empty($exp_has_entry[$sid][$d]) && ojtend_is_after($exp_ojt_end, $sid, $d)) {
                $row[] = ''; // NEW (OJT ends at the required hours): the student already completed the OJT
            } elseif (empty($exp_has_entry[$sid][$d]) && !attsch_is_scheduled($exp_sched, $sid, $d)) {
                $row[] = 'NOT SCHEDULED'; // NEW (student schedule): not a duty day for this student — never absent
            } elseif ($d === $today_str && empty($exp_has_entry[$sid][$d])) {
                $row[] = ''; // today with no attendance entry yet: blank until the day has passed
            } else {
                $row[] = $exp_logs[$sid][$d] ?? 'ABSENT';
            }
        }
        $data_rows[] = $row;
    }

    // Generate & stream
    // ─────────────────────────────────────────────────────────────────────────────
    $sheet_title     = 'Attendance ' . $month_abbrev . $year_4;
    $month_label_fmt = date('F Y', strtotime($exp_month . '-01'));

    $xlsx_blob = attm_build_xlsx(
        $header_row,
        $data_rows,
        $sheet_title,
        $company_name_raw,
        $month_label_fmt
    );

    header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('Content-Length: ' . strlen($xlsx_blob));
    header('Cache-Control: no-cache, no-store, must-revalidate');
    header('Pragma: no-cache');
    header('Expires: 0');
    echo $xlsx_blob;
    exit;

    }
}
if (isset($_GET['export']) && $_GET['export'] === 'xlsx') {
    try {
        attm_export_xlsx($conn, $company_id);
    } catch (\Throwable $e) {
        while (ob_get_level() > 0) { ob_end_clean(); }
        http_response_code(500);
        header('Content-Type: text/plain; charset=UTF-8');
        echo 'The Excel export could not be generated. Please try again.';
        exit;
    }
}

// ─────────────────────────────────────────────────────────────────────────────
// CSV EXPORT HANDLER
// ─────────────────────────────────────────────────────────────────────────────
if (isset($_GET['export']) && $_GET['export'] == '1') {
    $exp_month = $_GET['month'] ?? date('Y-m');

    $s2 = $conn->prepare("SELECT MIN(date) as sd FROM attendance_settings WHERE company_id=?");
    $s2->bind_param("i", $company_id);
    $s2->execute();
    $sd_row = $s2->get_result()->fetch_assoc();
    $sd = $sd_row['sd'] ?? date('Y-m-d');

    $ojt_start_month_exp = date('Y-m', strtotime($sd));

    if (date('Y-m', strtotime($sd)) === $exp_month) {
        $exp_start = $sd;
    } else {
        $exp_start = $exp_month . '-01';
    }
    $exp_end = date('Y-m-t', strtotime($exp_month . '-01'));

    $stud_res = $conn->query("
        SELECT u.id, u.first_name, u.middle_name, u.last_name
        FROM ojt_assignments oa
        JOIN users u ON oa.student_id = u.id
        WHERE oa.company_id = $company_id
        ORDER BY u.first_name ASC
    ");
    $exp_students = [];
    while ($sr = $stud_res->fetch_assoc()) {
        $exp_students[$sr['id']] = $sr;
    }
    $exp_first = attm_first_attendance_map($conn, array_keys($exp_students)); // UPDATED (Start = first attendance)
    $exp_sched = attsch_load($conn, array_keys($exp_students)); // NEW (student schedule)
    $exp_ojt_end = ojtend_dates($conn, array_keys($exp_students)); // NEW (OJT ends at the required hours)

    $log_res = $conn->query("
        SELECT user_id, date, am_time_in, am_time_out, pm_time_in, pm_time_out
        FROM attendance_logs
        WHERE company_id = $company_id
          AND date BETWEEN '$exp_start' AND '$exp_end'
    ");
    $exp_logs = [];
    $exp_has_entry = []; // real times only (a "missed"-only row is not an entry)
    while ($lr = $log_res->fetch_assoc()) {
        foreach (['am_time_in','am_time_out','pm_time_in','pm_time_out'] as $c_) {
            if ($lr[$c_] !== null && $lr[$c_] !== '' && $lr[$c_] !== 'missed') { $exp_has_entry[$lr['user_id']][$lr['date']] = true; break; }
        }
        $dow = (int)date('w', strtotime($lr['date']));
        $wknd = ($dow === 0 || $dow === 6);
        if ($wknd) {
            $st = 'DAY OFF';
        } else {
            $duty = getActiveDutyPeriods($lr['date'], $all_settings_map);
            $duty = attsch_limit_duty($duty, attsch_periods($exp_sched, $lr['user_id'], $lr['date']), $lr); // NEW (student schedule): Day = AM duty, Evening = PM duty
            $st   = computeStatusForLog($lr, $duty['am'], $duty['pm']);
        }
        $exp_logs[$lr['user_id']][$lr['date']] = $st;
    }

    $exp_dates = [];
    for ($d = strtotime($exp_start); $d <= strtotime($exp_end); $d = strtotime('+1 day', $d)) {
        $exp_dates[] = date('Y-m-d', $d);
    }

    $filename = 'attendance_' . $exp_month . '.csv';
    header('Content-Type: text/csv; charset=UTF-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('Pragma: no-cache');
    header('Expires: 0');

    $out = fopen('php://output', 'w');
    fputs($out, "\xEF\xBB\xBF");

    $header = ['Name'];
    foreach ($exp_dates as $d) {
        $dow = (int)date('w', strtotime($d));
        $header[] = date('D d', strtotime($d)) . ($dow===0||$dow===6?' (Off)':'');
    }
    fputcsv($out, $header);

    foreach ($exp_students as $sid => $stu) {
        $mn = trim($stu['middle_name'] ?? '');
        $row = [trim($stu['first_name'] . ($mn ? ' ' . $mn : '') . ' ' . $stu['last_name'])];
        foreach ($exp_dates as $d) {
            $dow = (int)date('w', strtotime($d));
            if ($dow === 0 || $dow === 6) {
                $row[] = 'OFF';
            } elseif ($d > date('Y-m-d')) {
                $row[] = '';
            } elseif (attm_before_start($exp_first, $sid, $d)) {
                $row[] = ''; // UPDATED: before first attendance — not absent / missed
            } elseif (empty($exp_has_entry[$sid][$d]) && ojtend_is_after($exp_ojt_end, $sid, $d)) {
                $row[] = ''; // NEW (OJT ends at the required hours): the student already completed the OJT
            } elseif (empty($exp_has_entry[$sid][$d]) && !attsch_is_scheduled($exp_sched, $sid, $d)) {
                $row[] = 'NOT SCHEDULED'; // NEW (student schedule): not a duty day for this student — never absent
            } elseif ($d === date('Y-m-d') && empty($exp_has_entry[$sid][$d])) {
                $row[] = ''; // today with no attendance entry yet: blank until the day has passed
            } else {
                $raw = $exp_logs[$sid][$d] ?? 'ABSENT';
                $row[] = $raw;
            }
        }
        fputcsv($out, $row);
    }
    fclose($out);
    exit;
}

/* ════════════════════════════════════════════════════════════════════
   Attendance schedule e-mail (self-contained: this page no longer depends on
   attendance_email_handler.php). PHPMailer is loaded only when an e-mail is sent.
   ════════════════════════════════════════════════════════════════════ */
function attm_load_phpmailer() {
    if (class_exists('\\PHPMailer\\PHPMailer\\PHPMailer', false)) return;
    require_once __DIR__ . '/phpmailer/src/Exception.php';
    require_once __DIR__ . '/phpmailer/src/PHPMailer.php';
    require_once __DIR__ . '/phpmailer/src/SMTP.php';
}

/**
 * Send attendance schedule notification emails
 * 
 * @param mysqli $conn Database connection
 * @param int $company_id Company ID
 * @param string $date Date of the schedule
 * @param string $am_in_range AM sign-in window display text
 * @param string $am_out_range AM sign-out window display text
 * @param string $pm_in_range PM sign-in window display text
 * @param string $pm_out_range PM sign-out window display text
 * @param bool $skip_am Whether AM duty is skipped
 * @param bool $skip_pm Whether PM duty is skipped
 * @return array Returns array with 'email_sent' count, 'total_students', 'admin_notified', 'email_errors'
 */
function attm_send_schedule_emails($conn, $company_id, $date, $am_in_range, $am_out_range, $pm_in_range, $pm_out_range, $skip_am = false, $skip_pm = false) {
    
    // Fetch company info
    $co_stmt = $conn->prepare("
        SELECT u.first_name, u.last_name, u.email, ci.company
        FROM users u
        LEFT JOIN company_information ci ON ci.user_id = u.id
        WHERE u.id = ? LIMIT 1
    ");
    $co_stmt->bind_param("i", $company_id);
    $co_stmt->execute();
    $co_row = $co_stmt->get_result()->fetch_assoc();
    $supervisor_name = trim(($co_row['first_name'] ?? '') . ' ' . ($co_row['last_name'] ?? ''));
    if (!$supervisor_name) $supervisor_name = 'Supervisor';
    $company_name = !empty($co_row['company']) ? $co_row['company'] : $supervisor_name;
    $co_stmt->close();

    // Fetch students email
    $stud_stmt = $conn->prepare("
        SELECT u.email, u.first_name, u.last_name
        FROM ojt_assignments oa
        JOIN users u ON oa.student_id = u.id
        WHERE oa.company_id = ?
          AND u.email IS NOT NULL AND u.email != ''
    ");
    $stud_stmt->bind_param("i", $company_id);
    $stud_stmt->execute();
    $students_email = $stud_stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stud_stmt->close();

    // Fetch admins email
    $admin_stmt = $conn->prepare("
        SELECT email, first_name, last_name
        FROM admins
        WHERE email IS NOT NULL AND email != ''
    ");
    $admin_stmt->execute();
    $admin_emails = $admin_stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $admin_stmt->close();

    $formatted_date = date("l, F j, Y", strtotime($date));
    
    // NEW (no fixed OJT window): the schedule applies until each student's OJT ends (required hours of the course reached)
    $schedule_scope = "Starting {$formatted_date} — applied to ALL remaining OJT weekdays " . attm_scope_phrase(attm_ojt_horizon($conn, $company_id)) . ".";

    $email_errors = [];
    $email_sent = [];

    $sendScheduleEmail = function($to, $name, $isAdmin = false, $facultyStudents = null) use (
        $company_name, $supervisor_name, $formatted_date, $schedule_scope,
        $am_in_range, $am_out_range, $pm_in_range, $pm_out_range, $skip_am, $skip_pm,
        &$email_sent, &$email_errors
    ) {
        // NEW: a faculty copy ($facultyStudents = the faculty's students) never adds to the student / administrator error list — its problems are only logged
        $isFacultyCopy = is_array($facultyStudents);
        if (!filter_var($to, FILTER_VALIDATE_EMAIL)) {
            if ($isFacultyCopy) { error_log('attendance_management.php: faculty schedule e-mail skipped — invalid address ' . $to); return false; }
            $email_errors[] = ['email' => $to, 'student' => $name, 'reasons' => ['Invalid email address format.']];
            return false;
        }

        $subject = "[{$company_name}] Attendance Schedule Updated — {$formatted_date}";

        // Build the message (HTML + plain-text fallback). A rendering problem must never stop delivery.
        try {
            $built = attm_build_schedule_email(
                $name, $isAdmin, $company_name, $supervisor_name, $formatted_date, $schedule_scope,
                $am_in_range, $am_out_range, $pm_in_range, $pm_out_range, $skip_am, $skip_pm, $facultyStudents
            );
        } catch (Throwable $e) {
            if ($isFacultyCopy) { error_log('attendance_management.php: faculty schedule e-mail could not be built: ' . $e->getMessage()); return false; }
            $email_errors[] = ['email' => $to, 'student' => $name, 'reasons' => ['Could not build email content: ' . $e->getMessage()]];
            return false;
        }
        $body    = $built['html'];
        $altBody = $built['text'];

        $mail = null;
        try {
            attm_load_phpmailer();
            $mail = new \PHPMailer\PHPMailer\PHPMailer(true);
            $mail->isSMTP();
            $mail->Host       = 'smtp.gmail.com';
            $mail->SMTPAuth   = true;
            $mail->Username   = 'salesjohnlhoyd@gmail.com';
            $mail->Password   = 'qwufanprpmezotly';
            $mail->SMTPSecure = \PHPMailer\PHPMailer\PHPMailer::ENCRYPTION_STARTTLS;
            $mail->Port       = 587;
            $mail->setFrom('salesjohnlhoyd@gmail.com', 'Atate On the Job Training System');
            $mail->addAddress($to, $name);
            $mail->CharSet  = 'UTF-8';
            $mail->Subject  = $subject;
            $mail->isHTML(true);
            $mail->Body     = $body;
            $mail->AltBody  = $altBody;
            $mail->send();
            if (!$isFacultyCopy) $email_sent[] = $to;
            return true;
        } catch (Throwable $e) {
            $reason = ($mail && $mail->ErrorInfo) ? $mail->ErrorInfo : $e->getMessage();
            if ($isFacultyCopy) { error_log('attendance_management.php: faculty schedule e-mail to ' . $to . ' failed: ' . $reason); return false; }
            $email_errors[] = ['email' => $to, 'student' => $name, 'reasons' => [$reason]];
            return false;
        }
    };

    foreach ($students_email as $student) {
        $sendScheduleEmail($student['email'], $student['first_name'] . ' ' . $student['last_name'], false);
    }

    foreach ($admin_emails as $admin) {
        $adminName = trim(($admin['first_name'] ?? '') . ' ' . ($admin['last_name'] ?? ''));
        if (!$adminName) $adminName = 'Admin';
        $sendScheduleEmail($admin['email'], $adminName, true);
    }

    // NEW: the faculty of the company's registered students receive the notice too — ONE e-mail per faculty, listing only that faculty's
    // students (never the same e-mail twice). Separate from everything above: whatever happens here (no faculty, no / invalid address,
    // a mail failure) only gets logged and never changes or stops the student / administrator e-mails or the saved schedule.
    $faculty_notified = 0; $faculty_total = 0;
    try {
        $reg_stmt = $conn->prepare("
            SELECT u.id, u.first_name, u.middle_name, u.last_name
            FROM ojt_assignments oa
            JOIN users u ON u.id = oa.student_id
            WHERE oa.company_id = ? AND COALESCE(u.is_archived, 0) = 0
        ");
        $reg_stmt->bind_param("i", $company_id);
        $reg_stmt->execute();
        $reg_rows = $reg_stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $reg_stmt->close();
        $reg_names = [];
        foreach ($reg_rows as $rr) {
            $reg_names[(int)$rr['id']] = trim(preg_replace('/\s+/', ' ', ($rr['first_name'] ?? '') . ' ' . ($rr['middle_name'] ?? '') . ' ' . ($rr['last_name'] ?? '')));
        }
        $facs = attm_faculty_recipients($conn, array_keys($reg_names));
        $faculty_total = count($facs);
        foreach ($facs as $fac) {
            $list = [];
            foreach ($fac['students'] as $sidv) { if (!empty($reg_names[$sidv])) $list[] = $reg_names[$sidv]; }
            if (!$list) continue;
            sort($list, SORT_NATURAL | SORT_FLAG_CASE);
            if ($sendScheduleEmail($fac['email'], $fac['name'], false, $list)) $faculty_notified++;
        }
    } catch (Throwable $e) {
        error_log('attendance_management.php: faculty schedule e-mails failed: ' . $e->getMessage());
    }

    return [
        'email_sent' => count($email_sent),
        'total_students' => count($students_email),
        'admin_notified' => count($admin_emails),
        'faculty_notified' => $faculty_notified,
        'faculty_total' => $faculty_total,
        'email_errors' => $email_errors
    ];
}
/**
 * NEW: the faculty of the given students (the recipients of the faculty copy of the schedule notice).
 * A student is linked to their faculty the way faculty_student_list.php / faculty_administrator.php do it (student_list_source_sql / fac_scope_cond): the faculty account's Course
 * (faculty.department), Section and Campus Branch equal the student's (users.course, student_information.year_section / campus_branch).
 * Returns ONE entry per faculty e-mail address — ['email', 'name', 'students' => [student ids]] — so a faculty that matches several of the
 * students gets a single e-mail listing them, and a student with several faculty reaches each of them once. A student without a faculty, a
 * deactivated faculty and a faculty without a valid e-mail address are skipped silently. Any error just returns what was found.
 */
function attm_faculty_recipients($conn, array $studentIds) {
    $out = [];
    try {
        $ids = array_values(array_unique(array_filter(array_map('intval', $studentIds))));
        if (!$ids) return $out;
        // The SAME rule faculty_student_list.php uses to decide which students are a faculty's (student_list_source_sql): the student's
        // Course / Section / Campus Branch are read from users.course and a ONE-ROW-PER-STUDENT summary of student_information
        // (the first existing column of each candidate list, MAX() over the student's rows), and the faculty account's Course (department),
        // Section and Campus Branch must equal all three — compared case-insensitively inside the database.
        $siCols = [];
        $r = $conn->query("SHOW COLUMNS FROM student_information");
        while ($r && ($c = $r->fetch_assoc())) $siCols[$c['Field']] = true;
        $pick = function (array $cands) use ($siCols) { foreach ($cands as $c) { if (isset($siCols[$c])) return "MAX(`" . $c . "`)"; } return "NULL"; };
        $uc = $conn->query("SHOW COLUMNS FROM users LIKE 'course'");
        $userCourse = ($uc && $uc->num_rows > 0) ? 'u.course' : 'NULL';
        $active = '';
        $fc = $conn->query("SHOW COLUMNS FROM faculty LIKE 'is_active'");
        if ($fc && $fc->num_rows > 0) $active = " AND COALESCE(f.is_active, 1) = 1";   // a deactivated faculty cannot use the system: no e-mail
        $in = implode(',', $ids);
        $r = $conn->query("SELECT f.id AS fid, f.first_name, f.middle_name, f.last_name, f.email, u.id AS sid
                           FROM users u
                           JOIN (SELECT user_id,
                                        " . $pick(['course', 'program']) . " AS course,
                                        " . $pick(['section', 'year_section', 'year_and_section']) . " AS section,
                                        " . $pick(['campus_branch', 'campus', 'branch']) . " AS campus_branch
                                 FROM student_information GROUP BY user_id) sinf ON sinf.user_id = u.id
                           JOIN faculty f ON LOWER(TRIM(CONVERT(f.department USING utf8mb4))) = LOWER(TRIM(CONVERT(COALESCE(NULLIF($userCourse, ''), sinf.course) USING utf8mb4)))
                                         AND LOWER(TRIM(CONVERT(f.section USING utf8mb4))) = LOWER(TRIM(CONVERT(sinf.section USING utf8mb4)))
                                         AND LOWER(TRIM(CONVERT(f.campus_branch USING utf8mb4))) = LOWER(TRIM(CONVERT(sinf.campus_branch USING utf8mb4)))
                           WHERE u.role = 'student' AND u.id IN ($in)$active
                           ORDER BY f.id, u.id");
        while ($r && ($x = $r->fetch_assoc())) {
            $em = trim((string)($x['email'] ?? ''));
            if ($em === '' || !filter_var($em, FILTER_VALIDATE_EMAIL)) continue;
            $key = strtolower($em);
            if (!isset($out[$key])) {
                $out[$key] = ['email' => $em,
                              'name' => trim(preg_replace('/\s+/', ' ', ($x['first_name'] ?? '') . ' ' . ($x['middle_name'] ?? '') . ' ' . ($x['last_name'] ?? ''))) ?: 'Faculty',
                              'students' => []];
            }
            if (!in_array((int)$x['sid'], $out[$key]['students'], true)) $out[$key]['students'][] = (int)$x['sid'];
        }
    } catch (Throwable $e) {
        error_log('attendance_management.php: faculty recipients lookup failed: ' . $e->getMessage());
    }
    return array_values($out);
}
/**
 * Build the schedule-update email (HTML + plain text).
 * Table-based layout with inline styles so it renders consistently in Gmail, Outlook and mobile clients.
 * Palette is limited to: navy #07145f (headings/brand), slate #4b5563 (body text), white (on navy).
 *
 * @return array ['html' => string, 'text' => string]
 */
function attm_build_schedule_email($name, $isAdmin, $company_name, $supervisor_name, $formatted_date, $schedule_scope,
                                      $am_in_range, $am_out_range, $pm_in_range, $pm_out_range, $skip_am = false, $skip_pm = false, $facultyStudents = null) {
    $isFaculty = is_array($facultyStudents);   // NEW: the faculty copy — $facultyStudents = the names of THAT faculty's students at this company
    $e = function ($v) { return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); };

    // A session counts as skipped when flagged, or when both of its windows were left empty.
    $isSkipped = function ($r) { $r = trim((string)$r); return $r === '' || strcasecmp($r, 'Skipped') === 0; };
    $am_off = $skip_am || ($isSkipped($am_in_range) && $isSkipped($am_out_range));
    $pm_off = $skip_pm || ($isSkipped($pm_in_range) && $isSkipped($pm_out_range));
    $show = function ($r, $off) use ($isSkipped) { return ($off || $isSkipped($r)) ? 'Not required' : $r; };

    $am_in  = $show($am_in_range,  $am_off);  $am_out = $show($am_out_range, $am_off);
    $pm_in  = $show($pm_in_range,  $pm_off);  $pm_out = $show($pm_out_range, $pm_off);

    $name = trim((string)$name) !== '' ? trim((string)$name) : ($isAdmin ? 'Administrator' : ($isFaculty ? 'Faculty' : 'Student'));

    // Summary sentence adapts to which sessions are active.
    if ($am_off && $pm_off) {
        $summary = 'No duty sessions are currently scheduled. Attendance will not be recorded until a new schedule is set.';
    } elseif ($am_off) {
        $summary = 'Only the afternoon session is required. Morning attendance will not be recorded.';
    } elseif ($pm_off) {
        $summary = 'Only the morning session is required. Afternoon attendance will not be recorded.';
    } else {
        $summary = 'Both the morning and afternoon sessions are required each OJT weekday.';
    }

    if ($isAdmin) {
        $intro   = $e($company_name) . ' has updated its OJT attendance schedule. The new time windows are shown below for your records. No action is required on your part.';
        $actions = [
            'Students assigned to this company have received the same notice.',
            'Past attendance records are not affected by this change.',
            'Questions about this schedule can be directed to the supervisor, ' . $supervisor_name . '.',
        ];
        $actions_title = 'For your information';
        $badge = 'Administrator copy';
    } elseif ($isFaculty) {
        $n = count($facultyStudents);
        $intro   = 'We would like to let you know that ' . $e($company_name) . ' has updated its OJT attendance schedule, which applies to '
                 . ($n === 1 ? 'your student ' . $e($facultyStudents[0]) : 'the ' . $n . ' students of yours listed below') . ' training there. The new daily time windows are shown below for your information.';
        $actions = [
            'No action is needed from you; this notice is only to keep you informed.',
            ($n === 1 ? 'Your student has' : 'Your students have') . ' received the same notice and will sign in and out within these windows.',
            'Past attendance records are not affected by this change.',
            'Questions about this schedule can be directed to the supervisor, ' . $supervisor_name . '.',
        ];
        $actions_title = 'For your information';
        $badge = 'Faculty copy';
    } else {
        $intro   = 'Your supervisor at ' . $e($company_name) . ' has updated your attendance schedule. Please review the time windows below and plan your daily sign-in and sign-out accordingly.';
        $actions = [
            'Sign in and sign out only within the windows listed above. Entries made outside a window may not be accepted.',
            'Weekends are automatically marked as Day Off, so no attendance is needed on Saturday or Sunday.',
            'Missing a sign-in or sign-out may result in an Absent or Incomplete status for that session.',
            'If anything looks incorrect, please contact your supervisor, ' . $supervisor_name . ', as soon as possible.',
        ];
        $actions_title = 'What you need to do';
        $badge = 'Student notice';
    }

    $navy = '#07145f'; $slate = '#4b5563'; $line = '#e5e7eb'; $tint = '#f4f6fb';

    // NEW (faculty copy): the faculty's own students at this company
    $studentsBlock = '';
    if ($isFaculty) {
        $sli = '';
        foreach ($facultyStudents as $sn) {
            $sli .= '<tr><td valign="top" style="padding:0 10px 6px 0;font-size:14px;color:' . $navy . ';font-weight:700;width:14px;">&bull;</td>'
                  . '<td style="padding:0 0 6px;font-size:14px;line-height:1.5;color:' . $slate . ';">' . $e($sn) . '</td></tr>';
        }
        $studentsBlock = '<tr><td style="padding:0 32px 20px;">'
            . '<h2 style="margin:0 0 8px;font-size:16px;font-weight:700;color:' . $navy . ';">' . (count($facultyStudents) === 1 ? 'Your student at ' : 'Your students at ') . $e($company_name) . '</h2>'
            . '<table role="presentation" cellpadding="0" cellspacing="0" border="0" width="100%">' . $sli . '</table></td></tr>';
    }

    $row = function ($label, $in, $out) use ($e, $navy, $slate, $line) {
        $cell = function ($v) use ($e, $navy, $slate) {
            $off = ($v === 'Not required');
            return '<td style="padding:14px 16px;font-size:14px;line-height:1.4;' . ($off ? 'color:' . $slate . ';font-style:italic;' : 'color:' . $navy . ';font-weight:700;') . '">' . $e($v) . '</td>';
        };
        return '<tr><td style="padding:14px 16px;border-top:1px solid ' . $line . ';font-size:14px;font-weight:700;color:' . $navy . ';">' . $e($label) . '</td>'
             . str_replace('<td style="', '<td style="border-top:1px solid ' . $line . ';', $cell($in))
             . str_replace('<td style="', '<td style="border-top:1px solid ' . $line . ';', $cell($out)) . '</tr>';
    };

    $li = '';
    foreach ($actions as $a) {
        $li .= '<tr><td valign="top" style="padding:0 10px 10px 0;font-size:14px;color:' . $navy . ';font-weight:700;width:14px;">&bull;</td>'
             . '<td style="padding:0 0 10px;font-size:14px;line-height:1.6;color:' . $slate . ';">' . $e($a) . '</td></tr>';
    }

    $sent_at = date('F j, Y \a\t g:i A');

    $html = '<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Attendance Schedule Update</title>
</head>
<body style="margin:0;padding:0;background:' . $tint . ';font-family:\'Segoe UI\',Helvetica,Arial,sans-serif;">
<span style="display:none;max-height:0;overflow:hidden;opacity:0;">' . $e($company_name) . ' updated the attendance schedule effective ' . $e($formatted_date) . '.</span>
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="background:' . $tint . ';padding:28px 12px;">
<tr><td align="center">
  <table role="presentation" width="600" cellpadding="0" cellspacing="0" border="0" style="width:100%;max-width:600px;background:#ffffff;border:1px solid ' . $line . ';">

    <tr><td style="background:' . $navy . ';padding:28px 32px;">
      <p style="margin:0 0 6px;font-size:12px;letter-spacing:1.5px;text-transform:uppercase;color:#ffffff;">NEUST On-the-Job Training System</p>
      <h1 style="margin:0;font-size:24px;line-height:1.3;font-weight:700;color:#ffffff;">Attendance Schedule Updated</h1>
    </td></tr>

    <tr><td style="padding:28px 32px 8px;">
      <p style="margin:0 0 12px;font-size:16px;line-height:1.5;color:' . $navy . ';">Dear <strong>' . $e($name) . '</strong>,</p>
      <p style="margin:0 0 20px;font-size:14px;line-height:1.7;color:' . $slate . ';">' . $intro . '</p>
    </td></tr>

    <tr><td style="padding:0 32px 24px;">
      <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="border:1px solid ' . $line . ';">
        <tr>
          <td width="50%" style="padding:14px 16px;background:' . $tint . ';">
            <p style="margin:0;font-size:11px;letter-spacing:1px;text-transform:uppercase;color:' . $slate . ';">Effective from</p>
            <p style="margin:4px 0 0;font-size:14px;font-weight:700;color:' . $navy . ';">' . $e($formatted_date) . '</p>
          </td>
          <td width="50%" style="padding:14px 16px;background:' . $tint . ';border-left:1px solid ' . $line . ';">
            <p style="margin:0;font-size:11px;letter-spacing:1px;text-transform:uppercase;color:' . $slate . ';">Company / Supervisor</p>
            <p style="margin:4px 0 0;font-size:14px;font-weight:700;color:' . $navy . ';">' . $e($company_name) . '</p>
            <p style="margin:2px 0 0;font-size:13px;color:' . $slate . ';">' . $e($supervisor_name) . '</p>
          </td>
        </tr>
      </table>
    </td></tr>

    ' . $studentsBlock . '
    <tr><td style="padding:0 32px 8px;">
      <h2 style="margin:0 0 6px;font-size:16px;font-weight:700;color:' . $navy . ';">' . ($isFaculty ? 'The daily time windows' : 'Your daily time windows') . '</h2>
      <p style="margin:0 0 12px;font-size:14px;line-height:1.6;color:' . $slate . ';">' . $e($summary) . '</p>
      <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="border:1px solid ' . $line . ';border-collapse:collapse;">
        <tr style="background:' . $navy . ';">
          <td style="padding:10px 16px;font-size:12px;letter-spacing:1px;text-transform:uppercase;color:#ffffff;">Session</td>
          <td style="padding:10px 16px;font-size:12px;letter-spacing:1px;text-transform:uppercase;color:#ffffff;">Sign-in window</td>
          <td style="padding:10px 16px;font-size:12px;letter-spacing:1px;text-transform:uppercase;color:#ffffff;">Sign-out window</td>
        </tr>
        ' . $row('Morning (AM)', $am_in, $am_out) . '
        ' . $row('Afternoon (PM)', $pm_in, $pm_out) . '
      </table>
    </td></tr>

    <tr><td style="padding:20px 32px 8px;">
      <h2 style="margin:0 0 6px;font-size:16px;font-weight:700;color:' . $navy . ';">Schedule coverage</h2>
      <p style="margin:0;font-size:14px;line-height:1.7;color:' . $slate . ';">' . $e($schedule_scope) . '</p>
    </td></tr>

    <tr><td style="padding:20px 32px 12px;">
      <h2 style="margin:0 0 10px;font-size:16px;font-weight:700;color:' . $navy . ';">' . $e($actions_title) . '</h2>
      <table role="presentation" cellpadding="0" cellspacing="0" border="0" width="100%">' . $li . '</table>
    </td></tr>

    <tr><td style="padding:8px 32px 28px;">
      <p style="margin:0;font-size:14px;line-height:1.7;color:' . $slate . ';">Thank you,<br><strong style="color:' . $navy . ';">' . $e($supervisor_name) . '</strong><br>' . $e($company_name) . '</p>
    </td></tr>

    <tr><td style="background:' . $tint . ';border-top:1px solid ' . $line . ';padding:16px 32px;text-align:center;">
      <p style="margin:0 0 4px;font-size:12px;font-weight:700;color:' . $navy . ';">NEUST On-the-Job Training System &nbsp;|&nbsp; ' . $e($badge) . '</p>
      <p style="margin:0;font-size:12px;line-height:1.6;color:' . $slate . ';">This is an automated message sent on ' . $e($sent_at) . '. Please do not reply directly to this email.</p>
    </td></tr>

  </table>
</td></tr>
</table>
</body>
</html>';

    // Plain-text version for clients that do not render HTML.
    $text  = "ATTENDANCE SCHEDULE UPDATED\nNEUST On-the-Job Training System\n\n";
    $text .= "Dear {$name},\n\n" . html_entity_decode(strip_tags($intro), ENT_QUOTES, 'UTF-8') . "\n\n";
    $text .= "Effective from: {$formatted_date}\nCompany: {$company_name}\nSupervisor: {$supervisor_name}\n\n";
    if ($isFaculty) { $text .= (count($facultyStudents) === 1 ? "YOUR STUDENT AT {$company_name}\n" : "YOUR STUDENTS AT {$company_name}\n"); foreach ($facultyStudents as $sn) { $text .= "- {$sn}\n"; } $text .= "\n"; }
    $text .= "DAILY TIME WINDOWS\n{$summary}\n";
    $text .= "- Morning (AM):   Sign-in {$am_in} | Sign-out {$am_out}\n";
    $text .= "- Afternoon (PM): Sign-in {$pm_in} | Sign-out {$pm_out}\n\n";
    $text .= "SCHEDULE COVERAGE\n{$schedule_scope}\n\n" . strtoupper($actions_title) . "\n";
    foreach ($actions as $a) { $text .= "- {$a}\n"; }
    $text .= "\nThank you,\n{$supervisor_name}\n{$company_name}\n\nThis is an automated message. Please do not reply directly to this email.\n";

    return ['html' => $html, 'text' => $text];
}

function timeToMins($time) {
    if (!$time) return -1;
    $parts = explode(':', $time);
    return (int)$parts[0] * 60 + (int)$parts[1];
}

/* ════════════════════════════════════════════════════════════════════
   NEW (skip AM / PM duty guard): a duty period can only be skipped when no OJT
   trainee of this company is scheduled for it (Day schedule = AM duty, Evening
   schedule = PM duty; a trainee without a usable schedule is scheduled for both).
   Trainees whose OJT already ended have nothing left to schedule and are ignored.
   Returns ['ok' => bool, 'error' => string, 'am' => [names], 'pm' => [names]].
   ════════════════════════════════════════════════════════════════════ */
function attm_skip_conflicts($conn, $company_id, $skip_am, $skip_pm) {
    $out = ['ok' => true, 'error' => '', 'am' => [], 'pm' => []];
    if (!$skip_am && !$skip_pm) return $out;
    try {
        $st = $conn->prepare("
            SELECT u.id, u.first_name, u.middle_name, u.last_name
            FROM ojt_assignments oa
            JOIN users u ON oa.student_id = u.id
            WHERE oa.company_id = ?
            ORDER BY u.last_name, u.first_name
        ");
        if (!$st) throw new Exception('prepare failed');
        $st->bind_param("i", $company_id);
        if (!$st->execute()) throw new Exception('execute failed');
        $rows = [];
        $res = $st->get_result();
        while ($r = $res->fetch_assoc()) $rows[(int)$r['id']] = $r;
        $st->close();
        if (empty($rows)) return $out;

        $ids    = array_keys($rows);
        $sched  = attsch_load($conn, $ids);
        $ended  = ojtend_dates($conn, $ids);
        $today  = date('Y-m-d');
        foreach ($rows as $sid => $r) {
            if (ojtend_is_after($ended, $sid, $today)) continue;
            $cur = $sched[$sid]['cur'] ?? null;
            $hasAm = $cur === null || !empty($cur['d']);
            $hasPm = $cur === null || !empty($cur['e']);
            $name = trim(($r['last_name'] ?? '') . ', ' . ($r['first_name'] ?? '') . (trim($r['middle_name'] ?? '') !== '' ? ' ' . mb_substr(trim($r['middle_name']), 0, 1) . '.' : ''));
            $dayNames = [1 => 'Mon', 2 => 'Tue', 3 => 'Wed', 4 => 'Thu', 5 => 'Fri'];
            $daysText = function($list) use ($cur, $dayNames) {
                if ($cur === null) return 'Every weekday';
                return implode(', ', array_map(fn($n) => $dayNames[$n] ?? '', $list));
            };
            if ($skip_am && $hasAm) $out['am'][] = ['name' => $name, 'days' => $daysText($cur['d'] ?? [])];
            if ($skip_pm && $hasPm) $out['pm'][] = ['name' => $name, 'days' => $daysText($cur['e'] ?? [])];
        }
    } catch (Throwable $e) {
        return ['ok' => false, 'error' => 'Could not verify the OJT trainees\' schedules. Please try again.', 'am' => [], 'pm' => []];
    }
    return $out;
}

/* ════════════════════════════════════════════════════════════════════
   NEW (missing duty time): when the company's current attendance setting has no AM (or no PM)
   duty times but an OJT trainee is scheduled for that duty — for example a trainee with AM duty
   was just registered while the setting is PM-only — the company must fill in the missing times.
   No setting at all (or neither duty set) keeps the original "both duties active" behaviour and
   is not a gap. Returns ['ok','error','missing' => ['am'?,'pm'?],'am' => [trainees],'pm' => [trainees],'setting' => row|null].
   ════════════════════════════════════════════════════════════════════ */
function attm_duty_gap($conn, $company_id) {
    $out = ['ok' => true, 'error' => '', 'missing' => [], 'am' => [], 'pm' => [], 'setting' => null];
    try {
        $today = date('Y-m-d');
        $st = $conn->prepare("SELECT date, am_time_in_start, am_time_in_end, am_time_out_start, am_time_out_end,
                                      pm_time_in_start, pm_time_in_end, pm_time_out_start, pm_time_out_end
                              FROM attendance_settings WHERE company_id = ? AND date <= ? ORDER BY date DESC LIMIT 1");
        if (!$st) throw new Exception('prepare failed');
        $st->bind_param("is", $company_id, $today);
        if (!$st->execute()) throw new Exception('execute failed');
        $row = $st->get_result()->fetch_assoc();
        $st->close();
        if (!$row) return $out;
        $hasAm = !empty($row['am_time_in_start']) || !empty($row['am_time_out_start']);
        $hasPm = !empty($row['pm_time_in_start']) || !empty($row['pm_time_out_start']);
        if ($hasAm === $hasPm) return $out;   // both set = no gap; neither set = original behaviour (both active)
        $c = attm_skip_conflicts($conn, $company_id, !$hasAm, !$hasPm);
        if (!$c['ok']) return ['ok' => false, 'error' => $c['error'], 'missing' => [], 'am' => [], 'pm' => [], 'setting' => null];
        if (!$hasAm && $c['am']) { $out['missing'][] = 'am'; $out['am'] = $c['am']; }
        if (!$hasPm && $c['pm']) { $out['missing'][] = 'pm'; $out['pm'] = $c['pm']; }
        if ($out['missing']) $out['setting'] = $row;
    } catch (Throwable $e) {
        return ['ok' => false, 'error' => 'Could not check the duty schedule.', 'missing' => [], 'am' => [], 'pm' => [], 'setting' => null];
    }
    return $out;
}

// ─────────────────────────────────────────────────────────────────────────────
// AJAX HANDLERS
// ─────────────────────────────────────────────────────────────────────────────

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_SERVER['HTTP_X_REQUESTED_WITH'])) {

    $action = $_POST['action'] ?? '';

    if ($action === 'check_skip_conflicts') {
        header('Content-Type: application/json');
        $chk = attm_skip_conflicts($conn, $company_id, ($_POST['skip_am'] ?? '') === '1', ($_POST['skip_pm'] ?? '') === '1');
        echo json_encode($chk);
        exit;
    }

    if ($action === 'approve_late_request') {
        header('Content-Type: application/json');

        $req_id = (int)($_POST['req_id'] ?? 0);
        if (!$req_id) { echo json_encode(['success'=>false,'message'=>'Invalid request.']); exit; }

        $stmt = $conn->prepare("SELECT * FROM late_requests WHERE id=? AND company_id=? AND status='pending'");
        $stmt->bind_param("ii", $req_id, $company_id);
        $stmt->execute();
        $lr = $stmt->get_result()->fetch_assoc();

        if (!$lr) { echo json_encode(['success'=>false,'message'=>'Request not found or already processed.']); exit; }

        // NEW (late request legitimacy check): a high-risk request is only approved after the supervisor explicitly confirms the warnings
        $lrEv = attm_lr_evidence($conn, (int)$company_id, $lr);
        if ($lrEv['level'] === 'risk' && empty($_POST['confirm_risk'])) {
            echo json_encode(['success'=>false,'needs_confirm'=>true,'message'=>'This request has high-risk warnings. Review them and confirm again to approve it.','evidence'=>$lrEv]);
            exit;
        }

        $student_id = $lr['student_id'];
        $lr_date    = $lr['date'];
        $type       = $lr['type'];

        $col_map = [
            'am_time_in'  => ['am_time_in',  'am_time_in_photo'],
            'am_time_out' => ['am_time_out', 'am_time_out_photo'],
            'pm_time_in'  => ['pm_time_in',  'pm_time_in_photo'],
            'pm_time_out' => ['pm_time_out', 'pm_time_out_photo'],
        ];
        [$time_col, $photo_col] = $col_map[$type];

        $approved_time = $lr_date . ' ' . date('H:i:s', strtotime($lr['created_at']));

        $setting = $conn->prepare("SELECT * FROM attendance_settings WHERE company_id=? AND date=? LIMIT 1");
        $setting->bind_param("is", $company_id, $lr_date);
        $setting->execute();
        $settingRow = $setting->get_result()->fetch_assoc();

        if (!$settingRow) {
            $fallback = $conn->prepare("SELECT * FROM attendance_settings WHERE company_id=? AND is_auto=1 AND date<=? ORDER BY date DESC LIMIT 1");
            $fallback->bind_param("is", $company_id, $lr_date);
            $fallback->execute();
            $settingRow = $fallback->get_result()->fetch_assoc();
        }

        $chk = $conn->prepare("SELECT * FROM attendance_logs WHERE user_id=? AND date=? AND company_id=?");
        $chk->bind_param("isi", $student_id, $lr_date, $company_id);
        $chk->execute();
        $existing_log = $chk->get_result()->fetch_assoc();

        /* ── Late request (sign-out entries only) ──
           Duty time everywhere in the system is (sign out − sign in), so the time recorded for the
           sign out is what decides the hours credited: only that duty (AM Sign In → AM Sign Out) is counted —
           the Sign Out is credited at the scheduled sign-out time instead of the (later) submission time. */
        if (in_array($type, ['am_time_out','pm_time_out'], true)) {
            $period = ($type === 'am_time_out') ? 'am' : 'pm';
            $toTs = function($v) use ($lr_date) {
                if (!$v || $v === 'missed') return null;
                $v = (strpos($v, ' ') === false) ? ($lr_date . ' ' . $v) : $v;
                $ts = strtotime($v);
                return $ts === false ? null : $ts;
            };
            $inTs      = $toTs($existing_log[$period . '_time_in'] ?? null);
            $createdTs = strtotime($approved_time);

            $schedOut = $settingRow[$period . '_time_out_start'] ?? null;
            $schedTs  = $schedOut ? $toTs($schedOut) : null;
            if ($schedTs !== null) {
                if ($createdTs !== false && $schedTs > $createdTs) $schedTs = $createdTs;   // never later than the submission
                if ($inTs !== null && $schedTs < $inTs)            $schedTs = $inTs;         // never before the sign in (0 min)
                $approved_time = date('Y-m-d H:i:s', $schedTs);
            }
        }

        $photoStmt = $conn->prepare("SELECT photo FROM late_requests WHERE id=?");
        $photoStmt->bind_param("i", $req_id);
        $photoStmt->execute();
        $photoRow = $photoStmt->get_result()->fetch_assoc();
        $photo_data = $photoRow['photo'] ?? null;

        if (!$existing_log) {
            $ins = $conn->prepare("INSERT INTO attendance_logs (user_id, company_id, date, {$time_col}, {$photo_col}) VALUES (?,?,?,?,?) ON DUPLICATE KEY UPDATE {$time_col}=VALUES({$time_col}), {$photo_col}=VALUES({$photo_col})");
            $null = null;
            $ins->bind_param("iissb", $student_id, $company_id, $lr_date, $approved_time, $null);
            if ($photo_data) $ins->send_long_data(4, $photo_data);
            $ins->execute();
        } else {
            $upd = $conn->prepare("UPDATE attendance_logs SET {$time_col}=?, {$photo_col}=? WHERE user_id=? AND date=? AND company_id=?");
            $null = null;
            $upd->bind_param("sbisi", $approved_time, $null, $student_id, $lr_date, $company_id);
            if ($photo_data) $upd->send_long_data(1, $photo_data);
            $upd->execute();
        }

        $upd2 = $conn->prepare("UPDATE late_requests SET status='approved', reviewed_at=NOW() WHERE id=?");
        $upd2->bind_param("i", $req_id);
        $upd2->execute();

        $logChk = $conn->prepare("SELECT am_time_in, am_time_out, pm_time_in, pm_time_out FROM attendance_logs WHERE user_id=? AND date=? AND company_id=?");
        $logChk->bind_param("isi", $student_id, $lr_date, $company_id);
        $logChk->execute();
        $updatedLog = $logChk->get_result()->fetch_assoc();

        $dutyInfo = computeDutyInfo($updatedLog, $settingRow);

        $nameStmt = $conn->prepare("SELECT first_name, middle_name, last_name FROM users WHERE id=? LIMIT 1");
        $nameStmt->bind_param("i", $student_id);
        $nameStmt->execute();
        $nameRow = $nameStmt->get_result()->fetch_assoc();
        $s_name_mn = trim($nameRow['middle_name'] ?? '');
        $sName = trim(($nameRow['first_name'] ?? '') . ($s_name_mn ? ' ' . $s_name_mn : '') . ' ' . ($nameRow['last_name'] ?? ''));

        $newPendingCount = 0;
        $npc = $conn->prepare("SELECT COUNT(*) as cnt FROM late_requests WHERE company_id=? AND status='pending'");
        $npc->bind_param("i", $company_id);
        $npc->execute();
        $newPendingCount = $npc->get_result()->fetch_assoc()['cnt'] ?? 0;

        echo json_encode([
            'success'       => true,
            'message'       => "Approved. {$sName} time and photo updated recorded.",
            'recorded_time' => $approved_time,
            'duty_info'     => $dutyInfo,
            'req_id'        => $req_id,
            'pending_count' => $newPendingCount,
        ]);
        exit;
    }

    // NEW (Request History): "Clear History" removes every APPROVED and REJECTED late request of this company. Pending requests are never
    // touched, and the attendance already credited by an approved request stays exactly as it is (it lives in attendance_logs).
    if ($action === 'clear_late_request_history') {
        header('Content-Type: application/json');
        try {
            $del = $conn->prepare("DELETE FROM late_requests WHERE company_id=? AND status IN ('approved','rejected')");
            $del->bind_param("i", $company_id);
            $del->execute();
            $deleted = max(0, (int)$del->affected_rows);
            $del->close();
            $npc = $conn->prepare("SELECT COUNT(*) as cnt FROM late_requests WHERE company_id=? AND status='pending'");
            $npc->bind_param("i", $company_id);
            $npc->execute();
            $pendingNow = (int)($npc->get_result()->fetch_assoc()['cnt'] ?? 0);
            $npc->close();
            echo json_encode(['success' => true, 'deleted' => $deleted, 'pending_count' => $pendingNow]);
        } catch (\Throwable $e) {
            error_log('attendance_management.php: clear late request history failed: ' . $e->getMessage());
            echo json_encode(['success' => false, 'message' => 'The history could not be cleared. Please try again.']);
        }
        exit;
    }

    if ($action === 'reject_late_request') {
        header('Content-Type: application/json');

        $req_id = (int)($_POST['req_id'] ?? 0);
        if (!$req_id) { echo json_encode(['success'=>false,'message'=>'Invalid request.']); exit; }

        $upd = $conn->prepare("UPDATE late_requests SET status='rejected', reviewed_at=NOW() WHERE id=? AND company_id=? AND status='pending'");
        $upd->bind_param("ii", $req_id, $company_id);
        $upd->execute();

        $newPendingCount = 0;
        $npc = $conn->prepare("SELECT COUNT(*) as cnt FROM late_requests WHERE company_id=? AND status='pending'");
        $npc->bind_param("i", $company_id);
        $npc->execute();
        $newPendingCount = $npc->get_result()->fetch_assoc()['cnt'] ?? 0;

        if ($conn->affected_rows === 0) {
            echo json_encode(['success'=>false,'message'=>'Request not found or already processed.']);
        } else {
            echo json_encode(['success'=>true,'message'=>'Request rejected.','req_id'=>$req_id,'pending_count'=>$newPendingCount]);
        }
        exit;
    }

    ob_start();
    header('Content-Type: application/json');

    try {
        $date = $_POST['date'] ?? date("Y-m-d");

        $skip_am = isset($_POST['skip_am']) && $_POST['skip_am'] === '1';
        $skip_pm = isset($_POST['skip_pm']) && $_POST['skip_pm'] === '1';

        $am_time_in_start  = $skip_am ? null : (($_POST['am_time_in_start']  ?? '') ?: null);
        $am_time_in_end    = $skip_am ? null : (($_POST['am_time_in_end']    ?? '') ?: null);
        $am_time_out_start = $skip_am ? null : (($_POST['am_time_out_start'] ?? '') ?: null);
        $am_time_out_end   = $skip_am ? null : (($_POST['am_time_out_end']   ?? '') ?: null);

        $pm_time_in_start  = $skip_pm ? null : (($_POST['pm_time_in_start']  ?? '') ?: null);
        $pm_time_in_end    = $skip_pm ? null : (($_POST['pm_time_in_end']    ?? '') ?: null);
        $pm_time_out_start = $skip_pm ? null : (($_POST['pm_time_out_start'] ?? '') ?: null);
        $pm_time_out_end   = $skip_pm ? null : (($_POST['pm_time_out_end']   ?? '') ?: null);

        $is_auto   = isset($_POST['is_auto']) ? (int)$_POST['is_auto'] : 0;
        $auto_type = $_POST['auto_type'] ?? 'all_remaining';

        if ($skip_am && $skip_pm) {
            ob_end_clean();
            echo json_encode(['success' => false, 'message' => 'At least one duty period (AM or PM) must be configured.']);
            exit;
        }

        // NEW (skip AM / PM duty guard): never skip a duty period an OJT trainee is scheduled for
        $skip_chk = attm_skip_conflicts($conn, $company_id, $skip_am, $skip_pm);
        if (!$skip_chk['ok'] || $skip_chk['am'] || $skip_chk['pm']) {
            ob_end_clean();
            echo json_encode([
                'success' => false,
                'message' => !$skip_chk['ok']
                    ? $skip_chk['error']
                    : 'Skipping the ' . ($skip_chk['am'] ? 'AM' : '') . ($skip_chk['am'] && $skip_chk['pm'] ? ' and ' : '') . ($skip_chk['pm'] ? 'PM' : '') . ' duty cannot be done because it will affect the schedule of OJT trainees assigned to your company.',
            ]);
            exit;
        }

        if (!$skip_am && (!$am_time_in_start ||!$am_time_in_end || !$am_time_out_start || !$am_time_out_end)) {
            ob_end_clean();
            echo json_encode(['success' => false, 'message' => 'Please fill all AM time fields before saving.']);
            exit;
        }

        // ── UPDATED (8-hour maximum): the schedule can never exceed 8 hours of duty.
        //    Duty time is measured exactly like the wizard: Sign-In Opens → Sign-Out Opens
        //    (Sign-Out Closes is a grace window and is not counted).
        $toMins = function($t) {
            if ($t === null || $t === '') return null;
            $p = explode(':', (string)$t);
            return ((int)($p[0] ?? 0)) * 60 + (int)($p[1] ?? 0);
        };
        $dutyMins = function($start, $outStart, $overnight = false) use ($toMins) {
            $s = $toMins($start); $e = $toMins($outStart);
            if ($s === null || $e === null) return 0;
            if ($overnight && $e < $s) $e += 24 * 60; // NEW: PM sign-out earlier than sign-in = next day
            return max(0, $e - $s);
        };
        $MAX_DUTY_MINS   = 8 * 60;
        $am_duty_mins    = $skip_am ? 0 : $dutyMins($am_time_in_start, $am_time_out_start);
        $pm_duty_mins    = $skip_pm ? 0 : $dutyMins($pm_time_in_start, $pm_time_out_start, true);
        $total_duty_mins = $am_duty_mins + $pm_duty_mins;
        // NEW (4-hour limit per duty): AM and PM may each be at most 4 hours
        $MAX_PERIOD_MINS = 4 * 60;
        $fmtDur = function($m) { return intdiv($m, 60) . 'h' . ($m % 60 ? ' ' . ($m % 60) . 'm' : ''); };
        $over_periods = [];
        if ($am_duty_mins > $MAX_PERIOD_MINS) $over_periods[] = 'AM duty is ' . $fmtDur($am_duty_mins);
        if ($pm_duty_mins > $MAX_PERIOD_MINS) $over_periods[] = 'PM duty is ' . $fmtDur($pm_duty_mins);
        if ($over_periods) {
            ob_end_clean();
            echo json_encode([
                'success' => false,
                'message' => 'The ' . implode(' and the ', $over_periods) . '. Each duty (AM and PM) can be at most 4 hours, for a maximum of 8 hours a day. Please shorten the time windows.',
            ]);
            exit;
        }
        if ($total_duty_mins > $MAX_DUTY_MINS) {
            $th = intdiv($total_duty_mins, 60); $tm = $total_duty_mins % 60;
            ob_end_clean();
            echo json_encode([
                'success' => false,
                'message' => 'The schedule totals ' . $th . 'h' . ($tm ? ' ' . $tm . 'm' : '') . '. The maximum allowed duty time is 8 hours per day. Please adjust the time windows.',
            ]);
            exit;
        }

        // NEW (no fixed OJT window): the per-day rows are written through the end of the students' OJT (their course's required hours, see
        // attm_ojt_horizon) and over every row that already exists, so no older schedule is left behind. Days after the last row simply keep
        // using it (student_attendance.php and this page both fall back to the most recent schedule), so the schedule never stops applying.
        // The only cap is a safety one on how many rows ONE save writes (about two years of weekdays).
        $ojt_horizon_for_save = attm_ojt_horizon($conn, $company_id);
        $last_row_stmt = $conn->prepare("SELECT MAX(date) AS d FROM attendance_settings WHERE company_id=?");
        $last_row_stmt->bind_param("i", $company_id);
        $last_row_stmt->execute();
        $last_row_date = $last_row_stmt->get_result()->fetch_assoc()['d'] ?? null;
        $last_row_stmt->close();
        $end_date_limit_for_save = max(array_filter([$ojt_horizon_for_save, $last_row_date, $date]));
        $save_cap = date("Y-m-d", strtotime("+2 years", strtotime($date)));
        if ($end_date_limit_for_save > $save_cap) $end_date_limit_for_save = $save_cap;

        // Always save today's date first
        $r = upsert_settings($conn, $company_id, $date,
            $am_time_in_start, $am_time_in_end, $am_time_out_start, $am_time_out_end,
            $pm_time_in_start, $pm_time_in_end, $pm_time_out_start, $pm_time_out_end,
            1);

        $dates_set = [$date];
        $db_errors = $r['error'] ? [$r['error']] : [];

        // ── KEY CHANGE: only update today and FUTURE dates; skip past dates ──
        $today_str = date("Y-m-d");
        $step = strtotime("+1 day", strtotime($date));
        $limit_ts = strtotime($end_date_limit_for_save);

        while ($step <= $limit_ts) {
            $d = date("Y-m-d", $step);
            $dow = (int)date('w', $step);

            // Skip weekends
            if ($dow !== 0 && $dow !== 6) {
                // Only update today and future dates — never touch past days
                if ($d >= $today_str) {
                    $r = upsert_settings($conn, $company_id, $d,
                        $am_time_in_start, $am_time_in_end, $am_time_out_start, $am_time_out_end,
                        $pm_time_in_start, $pm_time_in_end, $pm_time_out_start, $pm_time_out_end,
                        1);
                    if ($r['error']) $db_errors[] = "{$d}: {$r['error']}";
                    $dates_set[] = $d;
                }
            }
            $step = strtotime("+1 day", $step);
        }

        $msg = count($dates_set) > 1
            ? "Attendance settings saved for " . count($dates_set) . " day(s)."
            : "Attendance settings saved successfully.";

        $formatted_date = date("l, F j, Y", strtotime($date));
        $schedule_scope = "Starting {$formatted_date} — applied to today and ALL remaining OJT weekdays " . attm_scope_phrase($ojt_horizon_for_save) . ". Past days are not affected.";

        $am_in_range  = $am_time_in_start  ? fmt12($am_time_in_start)  . ' – ' . fmt12($am_time_in_end)  : 'Skipped';
        $am_out_range = $am_time_out_start ? fmt12($am_time_out_start) . ' – ' . fmt12($am_time_out_end) : 'Skipped';
        $pm_in_range  = $pm_time_in_start  ? fmt12($pm_time_in_start)  . ' – ' . fmt12($pm_time_in_end)  : 'Skipped';
        $pm_out_range = $pm_time_out_start ? fmt12($pm_time_out_start) . ' – ' . fmt12($pm_time_out_end) : 'Skipped';

        $emailResult = attm_send_schedule_emails(
            $conn, $company_id, $date,
            $am_in_range, $am_out_range, $pm_in_range, $pm_out_range,
            false, false
        );

        ob_end_clean();
        echo json_encode([
            'success'        => true,
            'message'        => $msg,
            'notified'       => $emailResult['email_sent'],
            'total_students' => $emailResult['total_students'],
            'admin_notified' => $emailResult['admin_notified'],
            'dates_count'    => count($dates_set),
            'db_errors'      => $db_errors,
            'email_errors'   => $emailResult['email_errors'],
        ]);

    } catch (Throwable $e) {
        ob_end_clean();
        echo json_encode(['success' => false, 'message' => 'Server error: ' . $e->getMessage()]);
    }
    exit;
}

// UPDATED (Live updates): lightweight "has anything changed?" check
if ($_SERVER['REQUEST_METHOD'] === 'GET'
    && isset($_SERVER['HTTP_X_REQUESTED_WITH'])
    && ($_GET['action'] ?? '') === 'duty_gap') {   // NEW (missing duty time): used by add_ojt_student.php
    header('Content-Type: application/json');
    header('Cache-Control: no-store');
    $g = attm_duty_gap($conn, $company_id);
    echo json_encode(['success' => $g['ok'], 'missing' => $g['missing'], 'am' => $g['am'], 'pm' => $g['pm']]);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'GET'
    && isset($_SERVER['HTTP_X_REQUESTED_WITH'])
    && ($_GET['action'] ?? '') === 'live_signature') {
    header('Content-Type: application/json');
    header('Cache-Control: no-store');
    echo json_encode(['success' => true, 'signature' => attm_live_signature($conn, $company_id)]);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'GET'
    && isset($_SERVER['HTTP_X_REQUESTED_WITH'])
    && ($_GET['action'] ?? '') === 'get_late_requests') {

    header('Content-Type: application/json');

    // NEW (late request legitimacy check): server-recorded evidence; photos sent before this check existed get their fingerprint here
    $evOk  = ensureLateRequestEvidence($conn);
    $evSel = $evOk ? ', lr.submit_ip, lr.submit_ua, lr.photo_hash, lr.photo_valid' : '';
    if ($evOk) {
        try { $conn->query("UPDATE late_requests SET photo_hash = SHA2(photo, 256) WHERE company_id = " . (int)$company_id . " AND photo IS NOT NULL AND photo_hash IS NULL LIMIT 100"); } catch (\Throwable $e) {}
    }
    $stmt = $conn->prepare("
        SELECT
            lr.id,
            lr.student_id,
            lr.date,
            lr.type,
            lr.reason,
            lr.status,
            lr.created_at,
            lr.reviewed_at,
            lr.photo IS NOT NULL AS has_photo{$evSel},
            u.first_name,
            u.middle_name,
            u.last_name,
            u.email,
            al.am_time_in,  al.am_time_out,
            al.pm_time_in,  al.pm_time_out,
            s.am_time_in_start, s.am_time_in_end,
            s.am_time_out_start,s.am_time_out_end,
            s.pm_time_in_start, s.pm_time_in_end,
            s.pm_time_out_start,s.pm_time_out_end
        FROM late_requests lr
        JOIN users u ON u.id = lr.student_id
        LEFT JOIN attendance_logs al
               ON al.user_id    = lr.student_id
              AND al.company_id = lr.company_id
              AND al.date       = lr.date
        LEFT JOIN attendance_settings s
               ON s.company_id  = lr.company_id
              AND s.date        = lr.date
        WHERE lr.company_id = ?
        ORDER BY
            lr.status = 'pending' DESC,
            lr.date DESC,
            lr.created_at DESC
        LIMIT 300
    ");
    $stmt->bind_param("i", $company_id);
    $stmt->execute();
    $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);

    foreach ($rows as &$row) {
        $row['has_photo'] = (bool)$row['has_photo'];
        $row['photo_url'] = "late_request_photo.php?id={$row['id']}&t=" . time();
        // NEW (late request legitimacy check): evidence for the requests still waiting for a decision
        $row['evidence'] = ($row['status'] === 'pending') ? attm_lr_evidence($conn, (int)$company_id, $row) : null;
        unset($row['submit_ip'], $row['submit_ua'], $row['photo_hash'], $row['photo_valid']);
    }
    unset($row);

    echo json_encode(['success' => true, 'requests' => $rows]);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'GET'
    && isset($_SERVER['HTTP_X_REQUESTED_WITH'])
    && ($_GET['action'] ?? '') === 'get_late_request_photo') {

    header('Content-Type: application/json');
    $req_id = (int)($_GET['req_id'] ?? 0);
    if (!$req_id) { echo json_encode(['success'=>false]); exit; }

    $stmt = $conn->prepare("SELECT photo FROM late_requests WHERE id=? AND company_id=?");
    $stmt->bind_param("ii", $req_id, $company_id);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();

    if ($row && !empty($row['photo'])) {
        echo json_encode(['success'=>true, 'photo_b64' => base64_encode($row['photo'])]);
    } else {
        echo json_encode(['success'=>false, 'photo_b64'=>null]);
    }
    exit;
}

// ─────────────────────────────────────────────────────────────────────────────
// PAGE DATA QUERIES
// ─────────────────────────────────────────────────────────────────────────────

$stmt = $conn->prepare("SELECT MIN(date) as start_date FROM attendance_settings WHERE company_id=?");
$stmt->bind_param("i", $company_id);
$stmt->execute();
$start_res  = $stmt->get_result()->fetch_assoc();
$start_date = $start_res['start_date'] ?? date("Y-m-d");

// NEW (no fixed OJT window): the 4-month limit is gone. The page runs as far as the students' OJT does (each student's OJT ends when they reach the
// required hours of their course — attm_ojt_horizon()), and never less than the last schedule row and the end of the current month.
$ojt_horizon = attm_ojt_horizon($conn, $company_id);
$last_setting_date = null;
try {
    $ls_stmt = $conn->prepare("SELECT MAX(date) AS d FROM attendance_settings WHERE company_id=?");
    $ls_stmt->bind_param("i", $company_id);
    $ls_stmt->execute();
    $last_setting_date = $ls_stmt->get_result()->fetch_assoc()['d'] ?? null;
    $ls_stmt->close();
} catch (\Throwable $e) {}
$end_date_limit = max(array_filter([$ojt_horizon, $last_setting_date, date("Y-m-t")]));

// NEW (display start): the Attendance Overview / Monthly Attendance Summary start at the earliest of the first attendance
// setting and the first attendance log. $start_date (first setting) still drives the schedule window above; without this,
// attendance recorded before the first (new) setting was dated would be cut off and both panels would look empty.
$display_start_date = $start_date;
try {
    $ds_stmt = $conn->prepare("SELECT MIN(date) AS d FROM attendance_logs WHERE company_id=? AND date<=?");
    $ds_today = date("Y-m-d");
    $ds_stmt->bind_param("is", $company_id, $ds_today);
    $ds_stmt->execute();
    $ds_first = $ds_stmt->get_result()->fetch_assoc()['d'] ?? null;
    $ds_stmt->close();
    if ($ds_first && $ds_first < $display_start_date) $display_start_date = $ds_first;
} catch (\Throwable $e) {}

// Display range of the Monthly Attendance Summary / Attendance Overview chart.
// Attendance that students record AFTER that end is real data and must stay visible: when the company has logs past it, the
// display range runs through today (days without an entry are Absent once they have passed).
$display_end_limit = $end_date_limit;
try {
    $dl_stmt = $conn->prepare("SELECT MAX(date) AS d FROM attendance_logs WHERE company_id=? AND date<=?");
    $dl_today = date("Y-m-d");
    $dl_stmt->bind_param("is", $company_id, $dl_today);
    $dl_stmt->execute();
    $dl_last = $dl_stmt->get_result()->fetch_assoc()['d'] ?? null;
    if ($dl_last && $dl_last > $end_date_limit) $display_end_limit = $dl_today;
} catch (\Throwable $e) {}

$date = $_GET['date'] ?? date("Y-m-d");
if ($date < $start_date)     $date = $start_date;
if ($date > $end_date_limit) $date = $end_date_limit;

$isToday = ($date == date("Y-m-d"));

$date_dow  = (int)date('w', strtotime($date));
$isWeekend = ($date_dow === 0 || $date_dow === 6);

$stmt = $conn->prepare("SELECT * FROM attendance_settings WHERE company_id=? AND date=?");
$stmt->bind_param("is", $company_id, $date);
$stmt->execute();
$current_settings = $stmt->get_result()->fetch_assoc();

$hasLogs = $conn->query("
    SELECT COUNT(*) as cnt FROM attendance_logs
    WHERE company_id = $company_id AND date = '$date'
")->fetch_assoc()['cnt'] > 0;

$remaining_weekdays = 0;
$today_ts  = strtotime(date("Y-m-d"));
$month_end = strtotime(date("Y-m-t"));
for ($ts = strtotime("+1 day", $today_ts); $ts <= $month_end; $ts = strtotime("+1 day", $ts)) {
    $dow = (int)date('w', $ts);
    if ($dow !== 0 && $dow !== 6) $remaining_weekdays++;
}

$remaining_ojt_weekdays = 0;
$ojt_limit_ts = strtotime($ojt_horizon !== null && $ojt_horizon > date('Y-m-d') ? $ojt_horizon : date('Y-m-d'));   // NEW: through the end of the OJT (not the display window)
for ($ts = strtotime("+1 day", $today_ts); $ts <= $ojt_limit_ts; $ts = strtotime("+1 day", $ts)) {
    $dow = (int)date('w', $ts);
    if ($dow !== 0 && $dow !== 6) $remaining_ojt_weekdays++;
}

$today_str = date("Y-m-d");
$today_stats = ['present' => 0, 'absent' => 0, 'incomplete' => 0, 'day_off' => 0, 'total' => 0];
$today_dow = (int)date('w');
if ($today_dow === 0 || $today_dow === 6) {
    $total_students_count = $conn->query("SELECT COUNT(*) as c FROM ojt_assignments WHERE company_id=$company_id")->fetch_assoc()['c'] ?? 0;
    $today_stats['day_off'] = $total_students_count;
    $today_stats['total']   = $total_students_count;
} else {
    // UPDATED (Start = first attendance): only students who have started (first
    // attendance on/before today) can be Present / Incomplete / Absent today.
    $ts_all_ids = [];
    $ts_ids_res = $conn->query("SELECT student_id FROM ojt_assignments WHERE company_id=$company_id");
    if ($ts_ids_res) { while ($tr = $ts_ids_res->fetch_assoc()) $ts_all_ids[] = (int)$tr['student_id']; }
    $ts_first   = attm_first_attendance_map($conn, $ts_all_ids);
    $ts_started = array_values(array_filter($ts_all_ids, fn($sid) => !attm_before_start($ts_first, $sid, $today_str)));
    // NEW (student schedule): a student who is not scheduled today and has no real entry is neither Absent nor Incomplete
    $ts_sched = attsch_load($conn, $ts_all_ids);
    $ts_real_today = [];
    $ts_rows_today = [];
    try {
        $ts_nr = $conn->query("SELECT user_id, am_time_in, am_time_out, pm_time_in, pm_time_out FROM attendance_logs WHERE company_id=$company_id AND date='$today_str'");
        if ($ts_nr) { while ($tn = $ts_nr->fetch_assoc()) { $ts_rows_today[(int)$tn['user_id']] = $tn; if (attsch_has_real_entry($tn)) $ts_real_today[(int)$tn['user_id']] = true; } }
    } catch (\Throwable $e) {}
    $ts_end = ojtend_dates($conn, $ts_all_ids); // NEW (OJT ends at the required hours)
    $ts_completed_count = 0;
    $ts_started = array_values(array_filter($ts_started, function($sid) use ($ts_end, $ts_real_today, $today_str, &$ts_completed_count) {
        if (ojtend_is_after($ts_end, $sid, $today_str) && empty($ts_real_today[(int)$sid])) { $ts_completed_count++; return false; }
        return true;
    }));
    $ts_not_sched_count = 0;
    $ts_started = array_values(array_filter($ts_started, function($sid) use ($ts_sched, $ts_real_today, $today_str, &$ts_not_sched_count) {
        if (attsch_is_scheduled($ts_sched, $sid, $today_str) || !empty($ts_real_today[(int)$sid])) return true;
        $ts_not_sched_count++;
        return false;
    }));
    // NEW (student schedule): a student scheduled for only ONE duty (Day = AM only, Evening = PM only) is judged on that duty
    // alone — counted here in PHP with the same rules as the Monthly Attendance Summary; everyone else uses the query below.
    $ts_part = ['present' => 0, 'incomplete' => 0, 'absent' => 0];
    $ts_full = [];
    foreach ($ts_started as $ts_sid) {
        $ts_per = attsch_periods($ts_sched, $ts_sid, $today_str);
        if ($ts_per['am'] && $ts_per['pm']) { $ts_full[] = $ts_sid; continue; }
        $ts_duty = attsch_limit_duty(getActiveDutyPeriods($today_str, $all_settings_map), $ts_per, $ts_rows_today[$ts_sid] ?? null);
        $ts_st   = computeStatusForLog($ts_rows_today[$ts_sid] ?? [], $ts_duty['am'], $ts_duty['pm']);
        if ($ts_st === 'PRESENT') $ts_part['present']++; elseif ($ts_st === 'INCOMPLETE') $ts_part['incomplete']++; else $ts_part['absent']++;
    }
    $ts_started_sql = !empty($ts_full) ? implode(',', $ts_full) : '0';
    $ts_res = $conn->query("
        SELECT
            SUM(CASE WHEN
                a.am_time_in IS NOT NULL AND a.am_time_in != '' AND a.am_time_in != 'missed' AND
                a.am_time_out IS NOT NULL AND a.am_time_out != '' AND a.am_time_out != 'missed' AND
                a.pm_time_in IS NOT NULL AND a.pm_time_in != '' AND a.pm_time_in != 'missed' AND
                a.pm_time_out IS NOT NULL AND a.pm_time_out != '' AND a.pm_time_out != 'missed'
                THEN 1 ELSE 0 END) AS present_count,
            SUM(CASE WHEN
                (a.am_time_in IS NOT NULL AND a.am_time_in != '' AND a.am_time_in != 'missed') OR
                (a.pm_time_in IS NOT NULL AND a.pm_time_in != '' AND a.pm_time_in != 'missed') OR
                a.am_time_in = 'missed' OR a.am_time_out = 'missed' OR
                a.pm_time_in = 'missed' OR a.pm_time_out = 'missed'
                THEN 1 ELSE 0 END) AS partial_count,
            COUNT(oa.student_id) AS total_count
        FROM ojt_assignments oa
        LEFT JOIN attendance_logs a ON a.user_id = oa.student_id AND a.company_id = oa.company_id AND a.date = '$today_str'
        WHERE oa.company_id = $company_id
          AND oa.student_id IN ($ts_started_sql)
    ");
    if ($ts_row = $ts_res->fetch_assoc()) {
        $present    = (int)($ts_row['present_count'] ?? 0);
        $partial    = (int)($ts_row['partial_count'] ?? 0);
        $incomplete = max(0, $partial - $present);
        $total      = (int)($ts_row['total_count'] ?? 0);
        $absent     = max(0, $total - $present - $incomplete);
        $present += $ts_part['present']; $incomplete += $ts_part['incomplete']; $absent += $ts_part['absent']; // NEW (student schedule)
        $total      = count($ts_all_ids); // UPDATED: header still shows every assigned student
        $today_stats = [
            'present'    => $present,
            'absent'     => $absent,
            'incomplete' => $incomplete,
            'day_off'    => 0,
            'total'      => $total,
            'not_scheduled' => $ts_not_sched_count, // NEW (student schedule)
            'completed'     => $ts_completed_count, // NEW (OJT ends at the required hours)
        ];
    }
}

// ── Attendance Log modal query (for the selected $date) ──────────────────────
// We use PHP to compute status per-student row rather than relying solely
// on the SQL CASE, so that we can respect the per-day duty-period config.
// The SQL still returns all columns; we override the status field below.
$query = "
SELECT
    u.id as student_id,
    u.first_name, u.last_name,
    a.am_time_in, a.am_time_out, a.am_time_in_photo, a.am_time_out_photo,
    a.pm_time_in, a.pm_time_out, a.pm_time_in_photo, a.pm_time_out_photo,
    CASE
        WHEN DAYOFWEEK(?) IN (1, 7)  THEN 'DAY OFF'
        WHEN ? > CURDATE()            THEN 'PENDING'
        ELSE 'COMPUTE'
    END AS status
FROM ojt_assignments oa
JOIN users u ON oa.student_id = u.id
LEFT JOIN attendance_logs a
    ON a.user_id    = oa.student_id
   AND a.company_id = oa.company_id
   AND a.date       = ?
WHERE oa.company_id = ?
ORDER BY u.first_name ASC
";
$stmt = $conn->prepare($query);
$stmt->bind_param("sssi", $date, $date, $date, $company_id);
$stmt->execute();
$result = $stmt->get_result();

$month = $_GET['month'] ?? date("Y-m");   // UPDATED: opens on the current month (kept inside the OJT's first … last month just below)
$ojt_start_month = date("Y-m", strtotime($display_start_date));
$month_min = $ojt_start_month;
$month_max = date("Y-m", strtotime($end_date_limit));
$sm_month_max = date("Y-m", strtotime($display_end_limit)); // Monthly Attendance Summary navigation limit
if ($month < $month_min) $month = $month_min;
if ($month > $sm_month_max) $month = $sm_month_max;

$start = max($display_start_date, $month . "-01");
if (date("Y-m", strtotime($display_start_date)) === $month) {
    $start = $display_start_date;
} else {
    $start = $month . "-01";
}
$end   = date("Y-m-t", strtotime($month . "-01"));
if ($end > $display_end_limit) $end = $display_end_limit;

$students = [];
$res = $conn->query("
    SELECT u.id, u.first_name, u.middle_name, u.last_name
    FROM ojt_assignments oa
    JOIN users u ON oa.student_id = u.id
    WHERE oa.company_id = $company_id
");
while ($row = $res->fetch_assoc()) { $students[$row['id']] = $row; }
$student_first_attendance = attm_first_attendance_map($conn, array_keys($students)); // UPDATED (Start = first attendance)
$student_sched = attsch_load($conn, array_keys($students)); // NEW (student schedule)
$student_ojt_end = ojtend_dates($conn, array_keys($students)); // NEW (OJT ends at the required hours)

/* ════════════════════════════════════════════════════════════════════
   UPDATED (Start / End indicators in the Monthly Attendance Summary):
   each student's OJT start and end date, computed exactly like the
   "OJT Date Start" / "OJT Date End" columns of admin_student_list.php:
     - Start = date of the student's first attendance log.
     - End   = date the student's Course Offering hour requirement was
               reached (completed), otherwise today/next duty day + the
               duty days still needed (Mon–Fri) for the remaining hours
               (estimated). Without a Course Offering: last attendance log.
   ════════════════════════════════════════════════════════════════════ */
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
    // Date of the $n-th duty day (Mon–Fri), counting $fromYmd (or the next duty day) as day 1
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

$student_ojt_marks = []; // [student_id => ['start'=>Y-m-d|null, 'end'=>Y-m-d|null, 'end_type'=>'completed'|'estimated'|'last_log']]
try {
    $mark_ids = array_map('intval', array_keys($students));
    if (!empty($mark_ids)) {
        $id_list = implode(',', $mark_ids);

        // Course Offering rules
        $mark_rules = [];
        try {
            $rr = $conn->query("SELECT course, total_hours, daily_hours FROM course_offerings");
            if ($rr) { while ($r = $rr->fetch_assoc()) $mark_rules[attm_normalize_course($r['course'])] = ['total' => (int)$r['total_hours'], 'daily' => (float)$r['daily_hours']]; }
        } catch (\Throwable $e) {}

        // Each student's course (users.course, else student_information)
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

        // First / last log and exact rendered seconds (all companies, same as the Student List)
        $sec_sql = attm_session_seconds_sql('am') . " + " . attm_session_seconds_sql('pm');
        $rr = $conn->query("SELECT user_id, MIN(date) AS d_start, MAX(date) AS d_end, COALESCE(SUM($sec_sql), 0) AS secs
                            FROM attendance_logs WHERE user_id IN ($id_list) GROUP BY user_id");
        $mark_today = (new DateTime('now', new DateTimeZone('Asia/Manila')))->format('Y-m-d');
        if ($rr) {
            while ($r = $rr->fetch_assoc()) {
                $uid   = (int)$r['user_id'];
                $mark  = ['start' => $r['d_start'], 'end' => $r['d_end'], 'end_type' => 'last_log'];
                // UPDATED (Start = first attendance): start = first REAL attendance, not a "missed"-only row
                $mark['start'] = $student_first_attendance[$uid] ?? null;
                if ($mark['start'] === null) { $mark['end'] = null; $student_ojt_marks[$uid] = $mark; continue; }
                $rule  = $mark_rules[attm_normalize_course($mark_courses[$uid] ?? '')] ?? null;
                if ($rule && $rule['total'] > 0 && $rule['daily'] > 0 && !empty($r['d_start'])) {
                    $required = (int)round($rule['total'] * 3600);
                    $rendered = (int)round((float)$r['secs']);
                    if ($rendered >= $required) {
                        // date the running total reached the requirement
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
                $student_ojt_marks[$uid] = $mark;
            }
        }
    }
} catch (\Throwable $e) {}

// ── Monthly Summary table logs ───────────────────────────────────────────────
// Now uses getActiveDutyPeriods() + computeStatusForLog() per day
$logs = [];
$logs_has_entry = []; // [student_id][date] => true when the day holds at least one REAL time (a "missed"-only row is not an entry)
$res = $conn->query("
    SELECT user_id, date, am_time_in, am_time_out, pm_time_in, pm_time_out
    FROM attendance_logs
    WHERE company_id = $company_id AND date BETWEEN '$start' AND '$end'
");
while ($row = $res->fetch_assoc()) {
    foreach (['am_time_in','am_time_out','pm_time_in','pm_time_out'] as $c_) {
        if ($row[$c_] !== null && $row[$c_] !== '' && $row[$c_] !== 'missed') { $logs_has_entry[$row['user_id']][$row['date']] = true; break; }
    }
    $dow  = (int)date('w', strtotime($row['date']));
    $wknd = ($dow === 0 || $dow === 6);
    if ($wknd) {
        $status = "DAY OFF";
    } else {
        $duty   = getActiveDutyPeriods($row['date'], $all_settings_map);
        $duty   = attsch_limit_duty($duty, attsch_periods($student_sched, $row['user_id'], $row['date']), $row); // NEW (student schedule): Day = AM duty, Evening = PM duty
        $status = computeStatusForLog($row, $duty['am'], $duty['pm']);
    }
    $logs[$row['user_id']][$row['date']] = $status;
}

$weekend_dates = [];
for ($d = strtotime($start); $d <= strtotime($end); $d = strtotime("+1 day", $d)) {
    $dow = (int)date('w', $d);
    if ($dow === 0 || $dow === 6) $weekend_dates[] = date("Y-m-d", $d);
}

$all_chart_months = [];
$cm = strtotime(date("Y-m-01", strtotime($display_start_date)));
$cm_end = strtotime(date("Y-m-01", strtotime($display_end_limit)));
while ($cm <= $cm_end) {
    $all_chart_months[] = date("Y-m", $cm);
    $cm = strtotime("+1 month", $cm);
}

$today_str = date("Y-m-d");

$all_logs_res = $conn->query("
    SELECT user_id, date, am_time_in, am_time_out, pm_time_in, pm_time_out
    FROM attendance_logs
    WHERE company_id = $company_id
      AND date BETWEEN '$display_start_date' AND '$today_str'
");
$all_logs = [];
while ($r = $all_logs_res->fetch_assoc()) {
    $all_logs[$r['user_id']][$r['date']] = $r;
}

// ── Monthly chart stats ───────────────────────────────────────────────────────
// Also updated to use getActiveDutyPeriods() + computeStatusForLog()
$monthly_stats = [];

foreach ($all_chart_months as $ym) {
    $ym_start = (date('Y-m', strtotime($display_start_date)) === $ym) ? $display_start_date : $ym . '-01';
    $ym_end   = date('Y-m-t', strtotime($ym . '-01'));
    if ($ym_end > $display_end_limit) $ym_end = $display_end_limit;

    if ($ym_start > $today_str) continue;
    if ($ym_end > $today_str) $ym_end = $today_str;

    $p = 0; $inc = 0; $a = 0;

    for ($d = strtotime($ym_start); $d <= strtotime($ym_end); $d = strtotime('+1 day', $d)) {
        $day_str = date('Y-m-d', $d);
        $dow     = (int)date('w', $d);
        if ($dow === 0 || $dow === 6) continue;

        $duty = getActiveDutyPeriods($day_str, $all_settings_map);

        foreach ($students as $sid => $s) {
            if (attm_before_start($student_first_attendance, $sid, $day_str)) continue; // UPDATED: not started yet
            // NEW (OJT ends at the required hours): after the student's OJT end date nothing is counted unless something was recorded
            if (ojtend_is_after($student_ojt_end, $sid, $day_str) && !attsch_has_real_entry($all_logs[$sid][$day_str] ?? null)) continue;
            // NEW (student schedule): not a duty day for this student and nothing recorded → not counted at all
            if (!attsch_has_real_entry($all_logs[$sid][$day_str] ?? null) && !attsch_is_scheduled($student_sched, $sid, $day_str)) continue;
            if (isset($all_logs[$sid][$day_str])) {
                $lr2    = $all_logs[$sid][$day_str];
                $duty_s = attsch_limit_duty($duty, attsch_periods($student_sched, $sid, $day_str), $lr2); // NEW (student schedule)
                $status = computeStatusForLog($lr2, $duty_s['am'], $duty_s['pm']);
                if      ($status === 'PRESENT')    $p++;
                elseif  ($status === 'INCOMPLETE')  $inc++;
                else                                $a++;
            } else {
                // No log row at all = absent
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

define('CHART_PAGE_SIZE', 6);
$chart_page = max(0, (int)($_GET['chart_page'] ?? 0));
$total_chart_pages = max(1, (int)ceil(count($monthly_stats) / CHART_PAGE_SIZE));
if ($chart_page >= $total_chart_pages) $chart_page = $total_chart_pages - 1;

$chart_slice = array_slice($monthly_stats, $chart_page * CHART_PAGE_SIZE, CHART_PAGE_SIZE);

$chart_labels_m   = array_column($chart_slice, 'label');
$chart_present_m  = array_column($chart_slice, 'present');
$chart_incomplete_m = array_column($chart_slice, 'incomplete');
$chart_absent_m   = array_column($chart_slice, 'absent');

$pending_count_stmt = $conn->prepare("SELECT COUNT(*) AS cnt FROM late_requests WHERE company_id=? AND status='pending'");
$pending_count_stmt->bind_param("i", $company_id);
$pending_count_stmt->execute();
$pending_count = $pending_count_stmt->get_result()->fetch_assoc()['cnt'] ?? 0;

function fmtTime($t) {
    if (!$t || $t === '-' || $t === 'missed') return '-';
    if (strpos($t, ' ') !== false) {
        $parts = explode(' ', $t);
        $t = $parts[1] ?? '';
    }
    $parts = explode(':', $t);
    if (count($parts) < 2) return '-';
    $h = (int)$parts[0];
    $m = (int)$parts[1];
    $ampm = $h >= 12 ? 'PM' : 'AM';
    $h12  = $h % 12 ?: 12;
    return sprintf('%d:%02d %s', $h12, $m, $ampm);
}

?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Attendance Management</title>
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
<style>
:root {
    --neust-maroon: #07145fe5;
    --neust-gold: #FFD700;
    --panel-radius: 14px;
    --panel-shadow: 0 2px 12px rgba(0,0,0,0.07);
}
body { margin: 0; display: flex; min-height: 100vh; font-family: 'Segoe UI', Tahoma, sans-serif; background: #f4f6fb; }

.sidebar { width: 260px; background: var(--neust-maroon); height: 100vh; position: fixed; display: flex; flex-direction: column; transition: 0.3s; z-index: 1000; }
.sidebar.collapsed { width: 80px; }

.sidebar-header {
    padding: 16px 20px;
    display: flex;
    align-items: center;
    justify-content: space-between;
    border-bottom: 1px solid rgba(255,255,255,0.1);
    flex-shrink: 0;
    min-height: 72px;
}
.sidebar-user-info {
    display: flex;
    flex-direction: column;
    gap: 1px;
    overflow: hidden;
    transition: opacity 0.2s, width 0.3s;
    max-width: 180px;
}
.sidebar-user-name {
    color: var(--neust-gold);
    font-size: 14px;
    font-weight: 700;
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
    line-height: 1.3;
}
.sidebar-user-role {
    color: rgba(255,255,255,0.55);
    font-size: 10px;
    font-weight: 500;
    text-transform: uppercase;
    letter-spacing: 0.08em;
    white-space: nowrap;
}
.sidebar.collapsed .sidebar-user-info { opacity: 0; width: 0; overflow: hidden; }

.sidebar-links { flex: 1; padding: 10px 0; }
.sidebar a { padding: 15px 25px; color: #cbd5e0; text-decoration: none; font-size: 14px; display: flex; align-items: center; position: relative; }
.sidebar a i { width: 30px; font-size: 18px; margin-right: 15px; text-align: center; flex-shrink: 0; }
.sidebar.collapsed .link-text { display: none; }
.sidebar.collapsed a i { margin-right: 0; }
.sidebar a.active { background: #1a237e; color: white; border-left: 4px solid var(--neust-gold); }
.sidebar a:hover:not(.active) { background: rgba(255,255,255,0.07); }
.logout-link { margin-top: auto; padding: 20px; border-top: 1px solid rgba(255,255,255,0.1); }
.logout-link a { border: 1px solid var(--neust-gold); color: var(--neust-gold); border-radius: 6px; justify-content: center; padding: 10px; text-decoration: none; display: flex; align-items: center; transition: background 0.2s; }
.logout-link a:hover { background: rgba(255,215,0,0.08); }
.sidebar-badge { background: #dc2626; color: white; border-radius: 50%; width: 18px; height: 18px; font-size: 10px; font-weight: 700; display: inline-flex; align-items: center; justify-content: center; position: absolute; right: 18px; top: 50%; transform: translateY(-50%); }
.sidebar-badge-late { background: #d97706; color: white; border-radius: 50%; width: 18px; height: 18px; font-size: 10px; font-weight: 700; display: inline-flex; align-items: center; justify-content: center; position: absolute; right: 18px; top: 50%; transform: translateY(-50%); animation: badge-pulse-late 2s ease-in-out infinite; }
@keyframes badge-pulse-late { 0%,100%{box-shadow:0 0 0 0 rgba(217,119,6,0.55);}50%{box-shadow:0 0 0 6px rgba(217,119,6,0);} }
.sidebar-badge-ungraded { background: #dc2626; color: white; border-radius: 50%; width: 18px; height: 18px; font-size: 10px; font-weight: 700; display: inline-flex; align-items: center; justify-content: center; position: absolute; right: 18px; top: 50%; transform: translateY(-50%); }

.toggle-btn { background: transparent; border: none; color: white; cursor: pointer; font-size: 20px; outline: none; flex-shrink: 0; }

.main-content { margin-left: 260px; width: calc(100% - 260px); transition: 0.3s; }
.sidebar.collapsed + .main-content { margin-left: 80px; width: calc(100% - 80px); }
.navbar { background: var(--neust-maroon); padding: 10px 30px; display: flex; align-items: center; color: white; height: 60px; flex-shrink: 0; box-shadow: 0 2px 8px rgba(0,0,0,0.15); }
.page-inner { padding: 24px 28px; }

.page-title-bar {
    display: flex;
    align-items: center;
    justify-content: flex-end;
    margin-bottom: 20px;
    flex-wrap: wrap;
    gap: 10px;
}

.datetime-widget {
    display: inline-flex;
    align-items: stretch;
    border-radius: 12px;
    overflow: hidden;
    box-shadow: 0 2px 12px rgba(7,20,95,0.18), 0 1px 3px rgba(0,0,0,0.10);
    border: 1px solid rgba(7,20,95,0.12);
    background: #fff;
}
.dtw-date-block {
    display: flex;
    align-items: center;
    gap: 10px;
    background: linear-gradient(135deg, #07145f, #1a237e);
    color: #fff;
    padding: 10px 16px;
}
.dtw-date-icon {
    width: 32px; height: 32px;
    border-radius: 8px;
    background: rgba(255,215,0,0.18);
    border: 1px solid rgba(255,215,0,0.35);
    display: flex; align-items: center; justify-content: center;
    font-size: 15px;
    flex-shrink: 0;
    color: #FFD700;
}
.dtw-date-text {
    display: flex;
    flex-direction: column;
    gap: 1px;
}
.dtw-date-label {
    font-size: 10px;
    font-weight: 700;
    text-transform: uppercase;
    letter-spacing: 0.8px;
    color: rgba(255,255,255,0.6);
    line-height: 1;
}
.dtw-date-value {
    font-size: 13px;
    font-weight: 700;
    color: #fff;
    line-height: 1.2;
    white-space: nowrap;
}
.dtw-divider {
    width: 1px;
    background: rgba(7,20,95,0.10);
    flex-shrink: 0;
}
.dtw-time-block {
    display: flex;
    align-items: center;
    gap: 10px;
    background: #fff;
    padding: 10px 16px;
}
.dtw-time-icon {
    width: 32px; height: 32px;
    border-radius: 8px;
    background: #ede9fe;
    border: 1px solid #c4b5fd;
    display: flex; align-items: center; justify-content: center;
    font-size: 14px;
    flex-shrink: 0;
    color: #7c3aed;
}
.dtw-time-text {
    display: flex;
    flex-direction: column;
    gap: 1px;
}
.dtw-time-label {
    font-size: 10px;
    font-weight: 700;
    text-transform: uppercase;
    letter-spacing: 0.8px;
    color: #9ca3af;
    line-height: 1;
}
.dtw-time-value {
    font-size: 16px;
    font-weight: 800;
    color: #1a1a2e;
    line-height: 1.1;
    font-variant-numeric: tabular-nums;
    letter-spacing: 0.5px;
    font-family: 'Segoe UI', monospace;
}
.dtw-ampm {
    font-size: 10px;
    font-weight: 700;
    color: #7c3aed;
    vertical-align: super;
    margin-left: 2px;
}
.dtw-live-dot.is-off { background: #A02A2A; animation: none; }
.dtw-live-dot {
    width: 7px; height: 7px;
    border-radius: 50%;
    background: #16a34a;
    flex-shrink: 0;
    animation: dtw-pulse 1.8s ease-in-out infinite;
}
@keyframes dtw-pulse {
    0%, 100% { opacity: 1; box-shadow: 0 0 0 0 rgba(22,163,74,0.5); }
    50%       { opacity: 0.7; box-shadow: 0 0 0 5px rgba(22,163,74,0); }
}

/* UPDATED: compact navbar version of the date / live-time widget */
.datetime-widget.dtw-in-navbar {
    margin-left: auto;
    border-radius: 10px;
    border: 1px solid rgba(255,255,255,0.22);
    box-shadow: 0 1px 6px rgba(0,0,0,0.18);
    flex-shrink: 0;
}
.dtw-in-navbar .dtw-date-block,
.dtw-in-navbar .dtw-time-block { padding: 5px 12px; gap: 8px; }
.dtw-in-navbar .dtw-date-icon,
.dtw-in-navbar .dtw-time-icon { width: 26px; height: 26px; border-radius: 7px; font-size: 12px; }
.dtw-in-navbar .dtw-date-label,
.dtw-in-navbar .dtw-time-label { font-size: 9px; letter-spacing: 0.7px; }
.dtw-in-navbar .dtw-date-value { font-size: 12px; }
.dtw-in-navbar .dtw-time-value { font-size: 14px; }
.dtw-in-navbar .dtw-ampm { font-size: 9px; }
@media (max-width: 900px) {
    .dtw-in-navbar .dtw-date-icon,
    .dtw-in-navbar .dtw-time-icon,
    .dtw-in-navbar .dtw-date-label,
    .dtw-in-navbar .dtw-time-label { display: none; }
}
@media (max-width: 640px) {
    .dtw-in-navbar .dtw-date-block,
    .dtw-in-navbar .dtw-divider { display: none; }
}

.split-layout {
    display: flex;
    gap: 20px;
    align-items: flex-start;
}
.split-left {
    flex: 3;
    min-width: 0;
    display: flex;
    flex-direction: column;
    gap: 18px;
}
.split-right {
    width: 260px;
    flex-shrink: 0;
    display: flex;
    flex-direction: column;
    gap: 14px;
    position: sticky;
    top: 20px;
}

.panel-card {
    background: #fff;
    border-radius: var(--panel-radius);
    box-shadow: var(--panel-shadow);
    border: 1px solid #e9ecef;
    overflow: hidden;
}
.panel-card-header {
    padding: 14px 18px 12px;
    border-bottom: 1px solid #f0f2f5;
    display: flex;
    align-items: center;
    justify-content: space-between;
    background: #fafbfc;
}
.panel-card-header h3 {
    margin: 0;
    font-size: 14px;
    font-weight: 700;
    color: #1a1a2e;
    display: flex;
    align-items: center;
    gap: 7px;
}
.panel-card-body {
    padding: 16px 18px;
}

.quick-action-btn {
    display: flex;
    align-items: center;
    gap: 10px;
    width: 100%;
    padding: 11px 14px;
    border-radius: 9px;
    font-size: 13px;
    font-weight: 600;
    cursor: pointer;
    border: 1px solid transparent;
    text-align: left;
    transition: all 0.18s;
    margin-bottom: 6px;
    position: relative;
}
.quick-action-btn:last-child { margin-bottom: 0; }
.quick-action-btn .qa-icon { font-size: 15px; line-height: 1; flex-shrink: 0; color: inherit; }
.quick-action-btn .qa-label { flex: 1; }
.quick-action-btn .qa-badge {
    background: #dc2626;
    color: #fff;
    border-radius: 20px;
    padding: 1px 7px;
    font-size: 10px;
    font-weight: 700;
    flex-shrink: 0;
    animation: badge-pulse-late 2s ease-in-out infinite;
}
.qa-btn-blue   { background: #E6F1FB; color: #0C447C; border-color: #bfdbfe; }
.qa-btn-blue:hover { background: #dbeafe; border-color: #93c5fd; transform: translateY(-1px); box-shadow: 0 3px 8px rgba(37,99,235,0.15); }
.qa-btn-amber  { background: #FAEEDA; color: #633806; border-color: #fde68a; }
.qa-btn-amber:hover { background: #fef3c7; border-color: #fcd34d; transform: translateY(-1px); box-shadow: 0 3px 8px rgba(217,119,6,0.15); }
.qa-btn-green  { background: #EAF3DE; color: #27500A; border-color: #bbf7d0; }
.qa-btn-green:hover { background: #dcfce7; border-color: #86efac; transform: translateY(-1px); box-shadow: 0 3px 8px rgba(21,128,61,0.15); }

.kpi-card {
    border-radius: 10px;
    padding: 11px 13px;
    border: 1px solid transparent;
    margin-bottom: 6px;
    display: flex;
    align-items: center;
    gap: 12px;
}
.kpi-card:last-child { margin-bottom: 0; }
.kpi-card .kpi-icon { font-size: 20px; line-height: 1; flex-shrink: 0; }
.kpi-card .kpi-info .kpi-label { font-size: 10px; font-weight: 700; text-transform: uppercase; letter-spacing: 0.6px; }
.kpi-card .kpi-info .kpi-value { font-size: 20px; font-weight: 800; line-height: 1.1; margin-top: 2px; }
.kpi-present    { background: #f0fdf4; border-color: #bbf7d0; }
.kpi-present    .kpi-label  { color: #166534; }
.kpi-present    .kpi-value  { color: #15803d; }
.kpi-absent     { background: #fef2f2; border-color: #fecaca; }
.kpi-absent     .kpi-label  { color: #991b1b; }
.kpi-absent     .kpi-value  { color: #dc2626; }
.kpi-incomplete { background: #fffbeb; border-color: #fde68a; }
.kpi-incomplete .kpi-label  { color: #92400e; }
.kpi-incomplete .kpi-value  { color: #d97706; }

.chart-nav { display:flex; align-items:center; justify-content:space-between; margin-bottom:10px; gap:8px; }
.chart-nav-title { font-size:13px; font-weight:700; color:#333; flex:1; text-align:center; }
.chart-nav-btn { background:#f5f5f5; border:1px solid #ddd; border-radius:7px; padding:4px 12px; font-size:12px; font-weight:600; cursor:pointer; color:#444; transition:background .18s,border-color .18s; text-decoration:none; display:inline-block; }
.chart-nav-btn:hover:not([aria-disabled="true"]) { background:#e3f2fd; border-color:#90caf9; color:#1565c0; }
.chart-nav-btn[aria-disabled="true"] { opacity:.38; cursor:not-allowed; pointer-events:none; }
.chart-legend { display:flex; gap:16px; margin-bottom:10px; flex-wrap:wrap; }
.chart-legend-item { display:flex; align-items:center; gap:5px; font-size:11px; color:#555; }
.chart-legend-dot { width:11px; height:11px; border-radius:2px; flex-shrink:0; }
.chart-canvas-wrap { position:relative; height:240px; width:100%; }
.chart-empty { text-align:center; color:#aaa; font-size:13px; padding:40px 0; }

.sm-month-nav { display:flex; align-items:center; justify-content:space-between; flex-wrap:wrap; gap:10px; margin-bottom:14px; }
.sm-month-nav-left { display:flex; align-items:center; gap:8px; }
.sm-month-nav-left label { font-size:12px; color:#555; font-weight:600; }
.sm-month-input { padding:6px 10px; border:1.5px solid #e0e0e0; border-radius:8px; font-size:12px; font-family:inherit; color:#333; background:#fff; cursor:pointer; }
.sm-month-input:focus { outline:none; border-color:#1565c0; }
.sm-month-nav-right { display:flex; align-items:center; gap:6px; }
/* UPDATED: arrow-button month switcher + 10-per-page pagination for the Monthly Attendance Summary */
.sm-month-arrows { display:flex; align-items:center; gap:6px; }
.sm-month-label { min-width:120px; text-align:center; font-size:12px; font-weight:700; color:#1e293b; padding:5px 10px; border:1.5px solid #e0e0e0; border-radius:8px; background:#fff; }
.sm-table tbody tr.sm-row-hidden { display:none; }
#smPagination { padding-top:12px; }
.sm-action-btn { display:inline-flex; align-items:center; gap:5px; border:none; border-radius:7px; padding:7px 13px; font-size:12px; font-weight:600; cursor:pointer; transition:background .2s; font-family:inherit; }
.sm-action-btn.print { background:#f5f5f5; color:#333; }
.sm-action-btn.print:hover { background:#e0e0e0; }
.sm-action-btn.export { background:#0e9f6e; color:#fff; }
.sm-action-btn.export:hover { background:#0b8a5e; }

.sm-legend { display:flex; gap:12px; flex-wrap:wrap; margin-bottom:12px; }
.sm-legend-item { display:flex; align-items:center; gap:4px; font-size:11px; font-weight:600; color:#374151; }
.sm-legend-dot { width:9px; height:9px; border-radius:2px; flex-shrink:0; }

.sm-table-wrap { overflow-x:auto; border-radius:10px; box-shadow:0 1px 4px rgba(0,0,0,.06); }
.sm-table { width:100%; border-collapse:collapse; background:#fff; font-size:11px; min-width:400px; }
.sm-table th { background:#f8fafc; color:#374151; font-weight:700; padding:7px 5px; text-align:center; border-bottom:2px solid #e5e7eb; white-space:nowrap; font-size:10px; }
.sm-table th.sm-name-col { text-align:left; padding-left:12px; min-width:120px; }
.sm-table td { padding:6px 4px; text-align:center; border-bottom:1px solid #f1f5f9; font-weight:700; font-size:10px; }
.sm-table td.sm-name-td { text-align:left; padding-left:12px; color:#1e293b; font-weight:600; font-size:11px; white-space:nowrap; }
.sm-table th.sm-wknd-col { background:#f3f0ff; color:#7c3aed; }
.sm-table td.sm-off { background:#f3f0ff; color:#7c3aed; font-style:italic; }
.sm-table td.sm-present { color:#03543f; background:#f0fdf4; }
.sm-table td.sm-absent { color:#9b1c1c; background:#fef2f2; }
.sm-table td.sm-incomplete { color:#92400e; background:#fffbeb; }
/* UPDATED: OJT start / end indicators in the Monthly Attendance Summary */
.sm-table td .sm-mark { display:block; font-size:9px; line-height:1; margin:0 auto 2px; font-style:normal; }
.sm-mark-start { color:#2C5A2C; }
.sm-mark-end   { color:#A02A2A; }
.sm-mark-est   { opacity:.55; }
.sm-table td.sm-nosched { background:#eff6ff; color:#2563eb; font-style:italic; } /* NEW (student schedule): not scheduled that day */
.sm-table td.sm-ended { background:#f1f5f9; } /* NEW (OJT ends at the required hours) */
.sm-table td.sm-before-start { background:#f8fafc; color:#cbd5e1; } /* UPDATED: before first attendance */
.sm-table td.sm-has-mark { box-shadow:inset 0 0 0 1px rgba(21,101,192,.25); }
.sm-table tbody tr:hover td { filter:brightness(0.97); }
.sm-table tbody tr:last-child td { border-bottom:none; }
.sm-table-empty { text-align:center; padding:30px; color:#aaa; font-size:12px; }


td.day-off   { background:#ede7f6 !important; color:#512da8; font-weight:700; text-align:center; }
td.present   { color:#2e7d32; font-weight:700; text-align:center; }
td.absent    { color:#c62828; font-weight:700; text-align:center; }
td.incomplete{ color:#e65100; font-weight:700; text-align:center; }
td.pending   { color:#888;    font-weight:600; text-align:center; }
td.nosched   { color:#2563eb; background:#eff6ff; font-weight:700; text-align:center; } /* NEW (student schedule) */
td.missed    { color:#e65100; font-weight:700; text-align:center; }
tr.day-off-row td { background:#ede7f6; color:#512da8; font-style:italic; }
.weekend-notice { background:#ede7f6; color:#512da8; padding:10px 16px; border-radius:8px; margin-bottom:12px; font-size:13px; font-weight:500; display:flex; align-items:center; gap:8px; }

.att-pagination { display:flex; align-items:center; justify-content:space-between; padding:12px 0 4px; flex-wrap:wrap; gap:10px; }
.att-pagination-info { font-size:12px; color:#888; font-weight:500; }
.att-pagination-btns { display:flex; align-items:center; gap:6px; }
.att-page-btn { min-width:34px; height:34px; border:1px solid #e0e0e0; border-radius:7px; background:#fff; color:#444; font-size:13px; font-weight:600; cursor:pointer; transition:background .15s,border-color .15s,color .15s; display:inline-flex; align-items:center; justify-content:center; padding:0 8px; }
.att-page-btn:hover:not(:disabled):not(.active) { background:#e3f2fd; border-color:#90caf9; color:#1565c0; }
.att-page-btn.active { background:#1565c0; border-color:#1565c0; color:#fff; cursor:default; }
.att-page-btn:disabled { opacity:.35; cursor:not-allowed; }
.att-page-ellipsis { font-size:13px; color:#aaa; padding:0 2px; }

#attSettingsOverlay { display:none; position:fixed; inset:0; background:rgba(0,0,0,.55); z-index:9000; align-items:center; justify-content:center; backdrop-filter:blur(4px); }
#attSettingsOverlay.open { display:flex; }
#attSettingsBox { background:#fff; border-radius:18px; width:520px; max-width:96vw; box-shadow:0 28px 70px rgba(0,0,0,.28); overflow:hidden; animation:as-in .3s cubic-bezier(.34,1.56,.64,1); }
@keyframes as-in { from{opacity:0;transform:scale(.88) translateY(20px);} to{opacity:1;transform:scale(1) translateY(0);} }
.as-header { background:linear-gradient(135deg,#1565c0,#1976d2); color:#fff; padding:20px 26px 16px; display:flex; align-items:center; justify-content:space-between; }
.as-header-left { display:flex; align-items:center; gap:10px; }
.as-header-icon { font-size:24px; line-height:1; }
.as-header h3 { margin:0; font-size:17px; font-weight:700; }
.as-header p  { margin:4px 0 0; font-size:12px; opacity:.85; }
.as-close-btn { background:rgba(255,255,255,.15); border:none; color:#fff; border-radius:8px; padding:6px 12px; font-size:13px; font-weight:600; cursor:pointer; transition:background .2s; }
.as-close-btn:hover { background:rgba(255,255,255,.28); }
.as-body { padding:22px 26px; }
.as-today-only-notice { background:#fff8e1; border:1px solid #ffe082; border-radius:10px; padding:12px 16px; font-size:13px; color:#e65100; font-weight:500; display:flex; align-items:center; gap:8px; margin-bottom:14px; }
.as-current-settings { background:#f5f5f5; border-radius:10px; padding:13px 16px; font-size:13px; line-height:1.7; margin-bottom:16px; border-left:4px solid #1976d2; }
.as-current-settings strong.as-title { display:block; margin-bottom:6px; font-size:13px; color:#1565c0; }

#wizardOverlay { display:none; position:fixed; inset:0; background:rgba(0,0,0,.55); z-index:10000; align-items:center; justify-content:center; backdrop-filter:blur(4px); }
#wizardOverlay.open { display:flex; }
#wizardBox { background:#fff; border-radius:18px; width:500px; max-width:96vw; box-shadow:0 28px 70px rgba(0,0,0,.28); overflow:hidden; animation:wiz-in .3s cubic-bezier(.34,1.56,.64,1); }
@keyframes wiz-in { from{opacity:0;transform:scale(.86) translateY(24px);} to{opacity:1;transform:scale(1) translateY(0);} }
#wizardProgress { height:5px; background:#e0e0e0; }
#wizardProgressBar { height:100%; background:linear-gradient(90deg,#1976d2,#42a5f5); transition:width .4s ease; }
.wiz-header { padding:22px 28px 12px; border-bottom:1px solid #f0f0f0; }
.wiz-step-label { font-size:11px; font-weight:700; letter-spacing:1.2px; color:#1976d2; text-transform:uppercase; margin-bottom:4px; }
.wiz-header h2 { margin:0; font-size:19px; font-weight:700; color:#1a1a2e; }
.wiz-header p  { margin:5px 0 0; font-size:13px; color:#666; }
.wiz-body { padding:20px 28px; }
.field-row { display:grid; grid-template-columns:1fr 1fr; gap:12px; margin-bottom:14px; }
.field-group label { display:block; font-size:11px; font-weight:700; color:#555; text-transform:uppercase; letter-spacing:.6px; margin-bottom:5px; }
.field-group input[type="time"], .field-group input[type="number"] { width:100%; padding:9px 11px; border:1.5px solid #ddd; border-radius:8px; font-size:14px; color:#222; transition:border-color .2s; box-sizing:border-box; display:block; -webkit-appearance:auto; appearance:auto; background:#fff; }
.field-group input:focus { outline:none; border-color:#1976d2; box-shadow:0 0 0 3px rgba(25,118,210,.12); }
.field-group input.input-error { border-color:#c62828 !important; background:#fff5f5; }
.field-hint { font-size:10px; color:#888; margin-top:4px; }
.field-hint.am-hint { color:#e65100; }
.field-hint.pm-hint { color:#1565c0; }
.summary-grid { display:grid; grid-template-columns:1fr 1fr; gap:8px; margin-bottom:14px; }
.sg-card { background:#f5f5f5; border-radius:8px; padding:10px 12px; }
.sg-card .sg-label { font-size:10px; font-weight:700; color:#888; text-transform:uppercase; }
.sg-card .sg-value { font-size:13px; font-weight:600; color:#222; margin-top:3px; }
.sg-scope { background:#e8f5e9; border-radius:8px; padding:10px 14px; font-size:12px; color:#2e7d32; font-weight:600; margin-bottom:14px; display:flex; align-items:center; gap:8px; }
.sg-scope.scope-all { background:#ede9fe; color:#5b21b6; }
.wiz-footer { padding:14px 28px 22px; display:flex; justify-content:space-between; align-items:center; border-top:1px solid #f0f0f0; }
.wiz-dots { display:flex; gap:6px; }
.wiz-dot { width:7px; height:7px; border-radius:50%; background:#ddd; transition:background .3s; }
.wiz-dot.active { background:#1976d2; }
.wiz-btn { padding:10px 22px; border-radius:8px; font-size:14px; font-weight:600; cursor:pointer; border:none; transition:all .18s; }
.wiz-btn-back { background:#f5f5f5; color:#555; }
.wiz-btn-back:hover { background:#e8e8e8; }
.wiz-btn-next { background:#1976d2; color:#fff; }
.wiz-btn-next:hover { background:#1565c0; }
.wiz-btn-next:disabled { opacity:.5; cursor:not-allowed; }
.wiz-btn-save { background:#2e7d32; color:#fff; }
.wiz-btn-save:hover { background:#1b5e20; }
.wiz-btn-save.loading { opacity:.6; pointer-events:none; }
.wiz-btn-save.loading::after { content:''; display:inline-block; width:11px; height:11px; border:2px solid rgba(255,255,255,.4); border-top-color:#fff; border-radius:50%; animation:spin .7s linear infinite; margin-left:8px; vertical-align:middle; }
@keyframes spin { to { transform:rotate(360deg); } }

.skip-row {
    margin: 12px 0;
    padding: 10px;
    background: #f8fafc;
    border-radius: 8px;
    border: 1px solid #e2e8f0;
}
.skip-checkbox {
    display: flex;
    align-items: center;
    gap: 10px;
    cursor: pointer;
    padding: 5px 0;
}
.skip-checkbox input[type="checkbox"] {
    width: 18px;
    height: 18px;
    cursor: pointer;
    margin: 0;
}
.skip-checkbox span {
    font-size: 13px;
    font-weight: 600;
    color: #475569;
}
.skip-checkbox:hover span {
    color: #1565c0;
}
.skip-warning {
    background: #fef2f2;
    border: 1px solid #fecaca;
    border-radius: 8px;
    padding: 8px 12px;
    margin: 8px 0;
    font-size: 12px;
    color: #dc2626;
    display: flex;
    align-items: center;
    gap: 8px;
}
.field-group.disabled-field {
    opacity: 0.5;
    pointer-events: none;
}
.field-group.disabled-field input {
    background: #f1f5f9;
}

#hoursPopupOverlay { display:none; position:fixed; inset:0; background:rgba(0,0,0,.55); z-index:20000; align-items:center; justify-content:center; backdrop-filter:blur(4px); }
#hoursPopupOverlay.open { display:flex; }
#hoursPopupBox { background:#fff; border-radius:18px; width:420px; max-width:94vw; box-shadow:0 24px 60px rgba(0,0,0,.25); animation:wiz-in .3s cubic-bezier(.34,1.56,.64,1); overflow:hidden; }
.hp-header { background:linear-gradient(135deg,#c62828,#e53935); color:#fff; padding:22px 26px 16px; }
.hp-header .hp-icon { font-size:36px; display:block; margin-bottom:8px; }
.hp-header h3 { margin:0; font-size:18px; font-weight:700; }
.hp-body { padding:20px 26px; }
.hp-body p { margin:0 0 12px; font-size:14px; color:#444; line-height:1.6; }
.hp-breakdown { background:#fef2f2; border:1px solid #fecaca; border-radius:10px; padding:12px 16px; margin-bottom:16px; }
.hp-breakdown .hpb-row { display:flex; justify-content:space-between; font-size:13px; padding:4px 0; }
.hp-breakdown .hpb-row .hpb-label { color:#666; }
.hp-breakdown .hpb-row .hpb-val   { font-weight:700; color:#c62828; }
.hp-breakdown .hpb-row.total      { border-top:1px solid #fecaca; margin-top:6px; padding-top:8px; }
.hp-breakdown .hpb-row.total .hpb-val { color:#1b5e20; }
.hp-footer { padding:0 26px 22px; display:flex; justify-content:space-between; gap:10px; }
.hp-btn-ok { padding:11px 22px; background:#f5f5f5; color:#555; border:none; border-radius:9px; font-size:14px; font-weight:700; cursor:pointer; transition:background .2s; }
.hp-btn-ok:hover { background:#e8e8e8; }
/* NEW: "cannot skip duty" popup (reuses the hp-* look) */
/* Missing duty times form — same layout as the manual Add Student form of admin_student_list.php (compact grid, flat navy look) */
#dutyGapOverlay { display:none; position:fixed; inset:0; background:rgba(27,42,74,.55); z-index:20000; align-items:center; justify-content:center; padding:20px 0; box-sizing:border-box; }
#dutyGapOverlay.open { display:flex; }
#dutyGapBox { background:#fff; border:1px solid var(--grid-border, #C3CADA); border-top:3px solid var(--grid-navy, #1B2A4A); border-radius:0; width:640px; max-width:94%; max-height:calc(100vh - 40px); overflow-y:auto; padding:16px 24px 0 24px; box-sizing:border-box; box-shadow:0 20px 60px rgba(0,0,0,.25); animation:wiz-in .3s cubic-bezier(.34,1.56,.64,1); }
#dutyGapBox .dg-head { display:flex; justify-content:space-between; align-items:center; margin-bottom:12px; padding-bottom:8px; border-bottom:1px solid var(--grid-border, #C3CADA); gap:12px; }
#dutyGapBox .dg-head h3 { margin:0; color:var(--grid-navy, #1B2A4A); font-size:15px; text-transform:uppercase; letter-spacing:.4px; }
#dutyGapBox .dg-head h3 i { margin-right:6px; }
#dutyGapBox .dg-badge { font-size:11px; font-weight:700; text-transform:uppercase; letter-spacing:.3px; color:#A0850A; background:#FAF3DC; border:1px solid #E6D69A; padding:4px 10px; white-space:nowrap; }
#dutyGapBox .dg-msg { margin:0 0 10px; font-size:13px; color:#1e293b; line-height:1.55; }
#dutyGapBox .sb-list { margin:0 0 12px; border:1px solid var(--grid-border, #C3CADA); background:var(--surface-soft, #F3F5F9); border-radius:0; max-height:130px; }
#dutyGapBox .sb-list li { border-color:var(--grid-border-soft, #DCE1EC); padding:7px 12px; }
#dutyGapBox .sb-list .sb-days { color:#A0850A; }
#dutyGapBox .dg-grid { display:grid; grid-template-columns:repeat(2, 1fr); column-gap:14px; row-gap:0; align-items:start; }
#dutyGapBox .dg-group { margin-bottom:9px; }
#dutyGapBox .dg-group label { display:block; font-weight:600; color:#1e293b; margin-bottom:4px; font-size:12px; }
#dutyGapBox .dg-group label .required { color:var(--grid-red, #A02A2A); }
#dutyGapBox .dg-group input[type="time"] { width:100%; padding:7px 10px; border:1px solid var(--grid-border, #C3CADA); border-radius:0; font-size:13px; transition:all .2s; box-sizing:border-box; background:#fff; }
#dutyGapBox .dg-group input[type="time"]:focus { outline:none; border-color:var(--grid-navy, #1B2A4A); box-shadow:0 0 0 3px rgba(27,42,74,.08); }
#dutyGapBox .dg-group input.input-error { border-color:var(--grid-red, #A02A2A) !important; background:var(--grid-red-bg, #F7E9E9); }
#dutyGapBox .help-text { font-size:10.5px; color:var(--grid-muted, #5A6272); margin-top:3px; line-height:1.35; }
#dutyGapBox .dg-error { display:none; background:var(--grid-red-bg, #F7E9E9); border:1px solid #E3BCBC; color:var(--grid-red, #A02A2A); padding:8px 12px; font-size:12px; margin:0 0 8px; border-radius:0; }
#dutyGapBox .dg-note { margin:0 0 4px; font-size:11px; color:var(--grid-muted, #5A6272); line-height:1.45; }
#dutyGapBox .dg-actions { display:flex; justify-content:flex-end; position:sticky; bottom:0; background:#fff; margin-top:4px; padding:10px 0 12px 0; border-top:1px solid var(--grid-border, #C3CADA); z-index:2; }
#dutyGapBox .dg-submit { background:var(--grid-navy, #1B2A4A); color:#fff; border:none; padding:10px 24px; border-radius:0; font-weight:600; cursor:pointer; transition:opacity .2s; text-transform:uppercase; letter-spacing:.3px; font-size:12px; }
#dutyGapBox .dg-submit:hover:not(:disabled) { opacity:.9; }
#dutyGapBox .dg-submit:disabled { opacity:.6; cursor:not-allowed; }
@media (max-width:640px) { #dutyGapBox { padding:14px 14px 0 14px; } #dutyGapBox .dg-grid { grid-template-columns:1fr; } #dutyGapBox .dg-badge { display:none; } }
#skipBlockOverlay { display:none; position:fixed; inset:0; background:rgba(0,0,0,.55); z-index:20000; align-items:center; justify-content:center; backdrop-filter:blur(4px); }
#skipBlockOverlay.open { display:flex; }
#skipBlockBox { background:#fff; border-radius:18px; width:460px; max-width:94vw; box-shadow:0 24px 60px rgba(0,0,0,.25); animation:wiz-in .3s cubic-bezier(.34,1.56,.64,1); overflow:hidden; }
#skipBlockBox .sb-header { display:flex; align-items:center; gap:16px; }
#skipBlockBox .sb-header .sb-icon { flex:0 0 auto; display:flex; align-items:center; justify-content:center; width:48px; height:48px; margin:0; font-size:26px; background:rgba(255,255,255,.16); border-radius:0; }
#skipBlockBox .sb-head-text { min-width:0; }
#skipBlockBox .hp-btn-continue { background:#1976d2; color:#fff; }
#skipBlockBox .hp-btn-continue:hover, #skipBlockBox .hp-btn-continue:focus-visible { background:#1565c0; }
#skipBlockBox .sb-sub { margin:4px 0 0; font-size:13px; opacity:.9; }
.sb-list { list-style:none; margin:0 0 14px; padding:0; max-height:200px; overflow-y:auto; border:1px solid #fecaca; border-radius:10px; background:#fef2f2; }
.sb-list li { display:flex; justify-content:space-between; align-items:center; gap:10px; padding:9px 14px; font-size:13px; border-bottom:1px solid #fecaca; }
.sb-list li:last-child { border-bottom:none; }
.sb-list .sb-name { font-weight:700; color:#333; }
.sb-list .sb-days { color:#c62828; font-size:12px; white-space:nowrap; }
.sb-more { padding:9px 14px; font-size:12px; color:#666; font-style:italic; }
.sb-tip { background:#f5f8ff; border-left:4px solid #1976d2; padding:10px 14px; font-size:12.5px; color:#444; line-height:1.55; margin:0 !important; }
.sb-retry { display:none; }
.hp-btn-continue { padding:11px 22px; background:#e65100; color:#fff; border:none; border-radius:9px; font-size:14px; font-weight:700; cursor:pointer; transition:background .2s; }
.hp-btn-continue:hover { background:#bf360c; }


#emailDebugPanel { display:none; position:fixed; bottom:0; left:0; right:0; background:#0d1117; color:#e6edf3; font-family:'Courier New',monospace; font-size:12px; z-index:99999; border-top:3px solid #d29922; max-height:50vh; flex-direction:column; }
#emailDebugPanel.open { display:flex; }
.edbg-titlebar { display:flex; align-items:center; justify-content:space-between; padding:8px 16px; background:#161b22; border-bottom:1px solid #30363d; flex-shrink:0; }
.edbg-titlebar h4 { margin:0; font-size:13px; color:#d29922; display:flex; align-items:center; gap:8px; }
.edbg-controls { display:flex; gap:8px; }
.edbg-controls button { background:#21262d; color:#e6edf3; border:1px solid #30363d; border-radius:5px; padding:3px 10px; font-size:11px; cursor:pointer; }
.edbg-controls button:hover { background:#30363d; }
.edbg-body { overflow-y:auto; padding:12px 16px; flex:1; }
.edbg-failure { background:#1a1009; border:1px solid #3d2b00; border-left:4px solid #d29922; border-radius:6px; padding:12px 14px; margin-bottom:10px; }
.edbg-failure .edbg-recipient { font-size:13px; font-weight:700; color:#f0c040; margin-bottom:6px; }
.edbg-failure .edbg-recipient span { color:#8b949e; font-weight:400; font-size:11px; margin-left:6px; }
.edbg-reason-list { list-style:none; margin:0; padding:0; }
.edbg-reason-list li { padding:3px 0; border-bottom:1px solid #21262d; color:#f85149; font-size:11px; line-height:1.5; }
.edbg-reason-list li::before { content:'\f00d'; font-family:'Font Awesome 6 Free'; font-weight:900; margin-right:6px; }
.edbg-reason-list li.ok { color:#3fb950; }
.edbg-reason-list li.ok::before { content:'\f00c'; font-family:'Font Awesome 6 Free'; font-weight:900; margin-right:6px; }
.edbg-hint { margin-top:10px; background:#0d1117; border:1px solid #30363d; border-radius:6px; padding:10px 14px; font-size:11px; color:#8b949e; line-height:1.7; }
.edbg-hint strong { color:#e6edf3; }

#lateInboxOverlay { display:none; position:fixed; inset:0; background:rgba(0,0,0,.55); z-index:8000; align-items:flex-start; justify-content:center; padding:40px 16px; backdrop-filter:blur(4px); overflow-y:auto; }
#lateInboxOverlay.open { display:flex; }
#lateInboxBox { background:#fff; border-radius:18px; width:720px; max-width:100%; box-shadow:0 28px 70px rgba(0,0,0,.28); overflow:hidden; animation:li-in .3s cubic-bezier(.34,1.56,.64,1); margin:auto; }
@keyframes li-in { from{opacity:0;transform:scale(.9) translateY(20px)} to{opacity:1;transform:scale(1) translateY(0)} }
.li-header { background:linear-gradient(135deg,#1565c0,#1976d2); color:#fff; padding:18px 24px; display:flex; align-items:center; justify-content:space-between; }
.li-header h3 { margin:0; font-size:18px; font-weight:700; display:flex; align-items:center; gap:8px; }
.li-close-btn { background:rgba(255,255,255,.15); border:none; color:#fff; border-radius:8px; padding:6px 12px; font-size:13px; font-weight:600; cursor:pointer; transition:background .2s; }
.li-close-btn:hover { background:rgba(255,255,255,.28); }
.li-tabs { display:flex; gap:0; border-bottom:2px solid #e0e0e0; background:#f5f5f5; }
.li-tab { flex:1; padding:10px; text-align:center; font-size:13px; font-weight:600; cursor:pointer; color:#888; border-bottom:3px solid transparent; transition:all .2s; margin-bottom:-2px; background:none; border-top:none; border-left:none; border-right:none; }
.li-tab.active { color:#1565c0; border-bottom-color:#1565c0; background:#fff; }
.li-body { max-height:560px; overflow-y:auto; padding:0; }
.li-loading { display:flex; align-items:center; justify-content:center; padding:60px; gap:12px; color:#aaa; }
.li-spinner { width:24px; height:24px; border:3px solid #e0e0e0; border-top-color:#1565c0; border-radius:50%; animation:spin .7s linear infinite; }
.li-empty { text-align:center; padding:60px 20px; color:#aaa; font-size:14px; }

.req-card { border-bottom:1px solid #f0f0f0; padding:18px 22px; transition:background .15s; }
.req-card:hover { background:#f9f9f9; }
.req-card-top { display:flex; align-items:flex-start; justify-content:space-between; gap:12px; margin-bottom:12px; }
.req-student-info { flex:1; min-width:0; }
.req-student-name { font-size:15px; font-weight:700; color:#1a1a2e; }
.req-meta { font-size:12px; color:#888; margin-top:2px; display:flex; align-items:center; gap:6px; flex-wrap:wrap; }
.req-type-badge { display:inline-block; padding:3px 10px; border-radius:20px; font-size:11px; font-weight:700; color:#fff; }
.req-type-badge.am_time_in  { background:#e65100; }
.req-type-badge.am_time_out { background:#c62828; }
.req-type-badge.pm_time_in  { background:#1565c0; }
.req-type-badge.pm_time_out { background:#2e7d32; }
.req-kind-note { margin-top:8px; font-size:12px; color:#5A6272; line-height:1.5; }
.req-kind-note strong { color:#1B2A4A; }
.req-evidence { border:1px solid #e0e4ef; border-radius:8px; margin-bottom:10px; overflow:hidden; font-size:12.5px; }
.req-evidence-head { display:flex; align-items:center; gap:8px; padding:8px 12px; font-weight:700; }
.req-evidence.ok .req-evidence-head { background:#ecfdf3; color:#166534; }
.req-evidence.review .req-evidence-head { background:#fffbeb; color:#92400e; }
.req-evidence.risk .req-evidence-head { background:#fef2f2; color:#991b1b; }
.req-evidence-list { list-style:none; margin:0; padding:8px 12px 10px; background:#fff; }
.req-evidence-list li { display:flex; gap:8px; padding:3px 0; color:#444; line-height:1.45; }
.req-evidence-list li i { margin-top:3px; font-size:11px; flex-shrink:0; }
.req-evidence-list li.ok i { color:#16a34a; } .req-evidence-list li.info i { color:#64748b; }
.req-evidence-list li.review i { color:#d97706; } .req-evidence-list li.risk i { color:#dc2626; }
.req-evidence-detail { display:grid; grid-template-columns:1fr 1fr; gap:1px; background:#e8ebf3; border-top:1px solid #e8ebf3; }
.req-evidence-detail div { background:#f8f9fd; padding:7px 12px; display:flex; flex-direction:column; gap:1px; }
.req-evidence-detail span { font-size:10.5px; text-transform:uppercase; letter-spacing:.3px; color:#64748b; }
.req-evidence-detail strong { font-size:12.5px; color:#1B2A4A; }
@media (max-width:520px){ .req-evidence-detail { grid-template-columns:1fr; } }
.req-evidence-device { padding:0 12px 9px; background:#fff; color:#64748b; font-size:11.5px; }
.req-reason-box { background:#f8f9ff; border:1px solid #e8eaf6; border-radius:8px; padding:10px 13px; font-size:13px; color:#444; line-height:1.55; margin-bottom:10px; }
.req-reason-label { font-size:10px; font-weight:700; color:#9fa8da; text-transform:uppercase; margin-bottom:4px; }
.req-photo-section { margin-bottom:12px; }
.req-photo-section .rps-label { font-size:10px; font-weight:700; color:#9fa8da; text-transform:uppercase; margin-bottom:6px; }
.req-photo-frame { width:100%; max-height:220px; border-radius:10px; overflow:hidden; background:#1a202c; display:flex; align-items:center; justify-content:center; border:2px solid #e0e0e0; cursor:pointer; position:relative; transition:border-color .2s; }
.req-photo-frame:hover { border-color:#1565c0; }
.req-photo-frame img { width:100%; max-height:220px; object-fit:cover; display:block; transition:transform .2s; }
.req-photo-frame:hover img { transform:scale(1.02); }
.req-photo-frame .rp-loading { color:#aaa; font-size:13px; padding:40px; display:flex; align-items:center; gap:8px; }
.req-photo-frame .rp-spinner { width:20px; height:20px; border:2.5px solid #e0e0e0; border-top-color:#1565c0; border-radius:50%; animation:spin .7s linear infinite; flex-shrink:0; }
.req-photo-frame .rp-expand-hint { position:absolute; bottom:8px; right:8px; background:rgba(0,0,0,.5); color:white; font-size:11px; padding:3px 8px; border-radius:6px; backdrop-filter:blur(4px); pointer-events:none; }
.req-photo-no-photo { background:#f5f5f5; border:1px dashed #ddd; border-radius:8px; padding:16px; text-align:center; color:#aaa; font-size:13px; }
.req-duty-info { background:#f0fff4; border:1px solid #c6f6d5; border-radius:8px; padding:8px 12px; font-size:12px; color:#276749; margin-bottom:10px; display:flex; gap:16px; flex-wrap:wrap; }
.req-duty-info.has-late { background:#fff8e1; border-color:#ffe082; color:#e65100; }
.req-duty-stat strong { display:block; font-size:10px; color:#aaa; font-weight:700; text-transform:uppercase; }
.req-actions { display:flex; gap:8px; }
.req-btn-allow { flex:1; padding:9px; border:none; border-radius:9px; background:#2e7d32; color:#fff; font-size:13px; font-weight:700; cursor:pointer; transition:all .18s; display:flex; align-items:center; justify-content:center; gap:5px; }
.req-btn-allow:hover { background:#1b5e20; }
.req-btn-allow.loading { opacity:.6; pointer-events:none; }
.req-btn-reject { flex:1; padding:9px; border:1.5px solid #e0e0e0; border-radius:9px; background:#fff; color:#888; font-size:13px; font-weight:600; cursor:pointer; transition:all .18s; }
.req-btn-reject:hover { background:#fef2f2; border-color:#f56565; color:#c62828; }
.req-status-badge { padding:4px 12px; border-radius:20px; font-size:11px; font-weight:700; display:inline-flex; align-items:center; gap:4px; }
.req-status-badge.approved { background:#c6f6d5; color:#276749; }
.req-status-badge.rejected { background:#fed7d7; color:#c62828; }

#reqPhotoLightbox { display:none; position:fixed; inset:0; background:rgba(0,0,0,.85); z-index:99999; align-items:center; justify-content:center; cursor:zoom-out; flex-direction:column; gap:12px; }
#reqPhotoLightbox.open { display:flex; }
#reqPhotoLightbox img { max-width:90vw; max-height:85vh; border-radius:12px; box-shadow:0 8px 40px rgba(0,0,0,.5); object-fit:contain; }
#reqPhotoLightbox .lb-caption { color:rgba(255,255,255,.7); font-size:12px; text-align:center; }

#notifPermissionBanner { display:none !important; }
#builtInNotifContainer { position:fixed; top:20px; right:20px; z-index:99998; display:flex; flex-direction:column; gap:10px; pointer-events:none; }
.builtin-notif { pointer-events:all; background:#fff; border-radius:14px; box-shadow:0 8px 32px rgba(0,0,0,.18),0 2px 8px rgba(0,0,0,.10); border-left:5px solid #1565c0; min-width:300px; max-width:380px; padding:0; overflow:hidden; animation:bn-slide-in .38s cubic-bezier(.34,1.56,.64,1); transform-origin:top right; }
@keyframes bn-slide-in { from{opacity:0;transform:scale(.82) translateX(30px);} to{opacity:1;transform:scale(1) translateX(0);} }
.builtin-notif.bn-hiding { animation:bn-slide-out .3s ease forwards; }
@keyframes bn-slide-out { from{opacity:1;transform:scale(1) translateX(0);max-height:200px;margin-bottom:0;} to{opacity:0;transform:scale(.9) translateX(20px);max-height:0;margin-bottom:-10px;} }
.bn-header { background:linear-gradient(135deg,#1565c0,#1976d2); padding:10px 14px 8px; display:flex; align-items:center; justify-content:space-between; }
.bn-header-left { display:flex; align-items:center; gap:8px; }
.bn-icon { font-size:20px; line-height:1; }
.bn-title { font-size:13px; font-weight:700; color:#fff; letter-spacing:.2px; }
.bn-close { background:rgba(255,255,255,.18); border:none; color:#fff; border-radius:6px; width:22px; height:22px; font-size:13px; cursor:pointer; display:flex; align-items:center; justify-content:center; transition:background .18s; flex-shrink:0; }
.bn-close:hover { background:rgba(255,255,255,.35); }
.bn-body { padding:11px 14px 13px; font-size:13px; color:#333; line-height:1.5; }
.bn-body strong { color:#1a1a2e; }
.bn-action { display:block; width:100%; background:none; border:none; border-top:1px solid #f0f0f0; padding:9px 14px; font-size:12px; font-weight:700; color:#1565c0; cursor:pointer; text-align:left; transition:background .15s; }
.bn-action:hover { background:#f0f7ff; }
.bn-progress { height:3px; background:#e3f2fd; }
.bn-progress-bar { height:100%; background:#1565c0; transition:width linear; }

#customConfirmOverlay { display:none; position:fixed; inset:0; background:rgba(0,0,0,.55); z-index:30000; align-items:center; justify-content:center; backdrop-filter:blur(4px); }
#customConfirmOverlay.open { display:flex; }
#customConfirmBox { background:#fff; border-radius:18px; width:440px; max-width:94vw; box-shadow:0 24px 60px rgba(0,0,0,.28); animation:wiz-in .3s cubic-bezier(.34,1.56,.64,1); overflow:hidden; }
.cc-header { padding:22px 26px 16px; display:flex; align-items:flex-start; gap:14px; }
.cc-icon-wrap { width:52px; height:52px; border-radius:14px; display:flex; align-items:center; justify-content:center; font-size:28px; flex-shrink:0; }
.cc-icon-wrap.approve { background:#dcfce7; }
.cc-icon-wrap.reject  { background:#fee2e2; }
.cc-text-wrap { flex:1; min-width:0; }
.cc-title { font-size:17px; font-weight:700; color:#1a1a2e; margin:0 0 6px; }
.cc-message { font-size:14px; color:#555; line-height:1.6; margin:0; }
.cc-student-badge { display:inline-flex; align-items:center; gap:6px; background:#f0f7ff; border:1px solid #bfdbfe; border-radius:8px; padding:8px 13px; margin:0 26px 16px; font-size:13px; color:#1e40af; font-weight:600; width:calc(100% - 52px); box-sizing:border-box; }
.cc-divider { height:1px; background:#f0f0f0; margin:0 0 4px; }
.cc-footer { display:flex; gap:10px; padding:14px 26px 22px; justify-content:flex-end; }
.cc-btn { padding:11px 26px; border-radius:10px; font-size:14px; font-weight:700; cursor:pointer; border:none; transition:all .18s; display:flex; align-items:center; gap:6px; }
.cc-btn-cancel { background:#f5f5f5; color:#666; }
.cc-btn-cancel:hover { background:#e8e8e8; color:#333; }
.cc-btn-confirm-approve { background:#16a34a; color:#fff; box-shadow:0 2px 10px rgba(22,163,74,.3); }
.cc-btn-confirm-approve:hover { background:#15803d; }
.cc-btn-confirm-reject { background:#dc2626; color:#fff; box-shadow:0 2px 10px rgba(220,38,38,.3); }
.cc-btn-confirm-reject:hover { background:#b91c1c; }

#attLogModal { display:none; position:fixed; inset:0; background:rgba(0,0,0,.55); z-index:8600; align-items:flex-start; justify-content:center; padding:30px 16px; backdrop-filter:blur(4px); overflow-y:auto; }
#attLogModal.open { display:flex; }
#searchInput { padding:10px 14px; border:1px solid #ddd; border-radius:8px; font-size:13px; min-width:220px; flex:1; }

.qa-badge-anim {
    animation: badge-pulse-late 2s ease-in-out infinite;
}


/* ══════════════════════════════════════════════════════════════════════
   ADJUSTMENT: "Field Ops Grid" restyle — the same design language as
   company_list.php (flat square corners, no drop shadows, navy #1B2A4A
   with gold accents, slate grid borders, green / amber / red status
   tones). This layer only changes colours, radii, shadows and spacing;
   no markup hooks, ids, classes or behaviour used by the scripts changed.
   ══════════════════════════════════════════════════════════════════════ */
:root {
    --grid-bg: #EEF1F6; --grid-navy: #1B2A4A; --grid-border: #C3CADA; --grid-border-soft: #DCE1EC;
    --grid-green: #2C5A2C; --grid-green-bg: #EAF3EA; --grid-red: #A02A2A; --grid-red-bg: #F7E9E9;
    --grid-amber: #A0850A; --grid-amber-bg: #FAF3DC; --grid-muted: #5A6272;
    --ink: #2d3748; --ink-faint: #8A93A6; --surface-soft: #F3F5F9; --blue-light: #E7ECF7;
    --panel-radius: 0; --panel-shadow: none;
}
* { box-sizing: border-box; }
body { background: var(--grid-bg); color: var(--ink); line-height: 1.6; }
button, input, select { font-family: inherit; }
button:focus-visible, a:focus-visible, input:focus-visible, select:focus-visible { outline: 2px solid #F7C600; outline-offset: 2px; }

/* sidebar / navbar — same as company_list.php */
.sidebar { transition: width 0.3s ease; box-shadow: 4px 0 10px rgba(0,0,0,0.1); top: 0; left: 0; }
.sidebar-header { padding: 20px; }
.sidebar-user-info { min-width: 0; }
.sidebar-user-name { font-size: 18px; font-weight: bold; }
.sidebar-user-role { font-size: 11px; font-weight: 700; letter-spacing: 0.8px; margin-top: 3px; overflow: hidden; text-overflow: ellipsis; }
.sidebar-links { display: flex; flex-direction: column; overflow: hidden; }
.sidebar a { transition: background 0.2s, color 0.2s; white-space: nowrap; }
.sidebar a:hover:not(.active) { color: #fff; }
.logout-link a { font-size: 14px; }
.main-content { transition: margin-left 0.3s, width 0.3s; display: flex; flex-direction: column; min-height: 100vh; min-width: 0; }
.navbar { box-shadow: none; position: relative; z-index: 99; }
.page-inner { flex: 1; padding: 30px; width: 100%; }

/* date / live-time widget — square */
.datetime-widget, .datetime-widget.dtw-in-navbar { border-radius: 0; box-shadow: none; border: 1px solid rgba(255,255,255,0.22); }
.dtw-date-block { background: var(--grid-navy); }
.dtw-date-icon, .dtw-time-icon { border-radius: 0; }
.dtw-time-icon { background: var(--blue-light); border-color: var(--grid-border); color: var(--grid-navy); }
.dtw-ampm { color: var(--grid-navy); }

/* panels */
.split-layout { gap: 24px; }
.split-left { gap: 20px; }
.panel-card { background: #fff; border: 1px solid var(--grid-border); border-radius: 0; box-shadow: none; }
.panel-card-header { background: var(--surface-soft); border-bottom: 1px solid var(--grid-border-soft); }
.panel-card-header h3 { color: var(--grid-navy); font-size: 13px; text-transform: uppercase; letter-spacing: 0.5px; }
.panel-card-header h3 i { color: var(--grid-navy) !important; }

/* quick actions */
.quick-action-btn { border-radius: 0; border: 1px solid var(--grid-border); font-family: inherit; }
.quick-action-btn:hover { transform: none !important; box-shadow: none !important; }
.quick-action-btn .qa-badge { border-radius: 0; }
.qa-btn-blue  { background: var(--blue-light); color: var(--grid-navy); border-color: var(--grid-border); }
.qa-btn-blue:hover  { background: var(--grid-navy); color: #fff; border-color: var(--grid-navy); }
.qa-btn-amber { background: var(--grid-amber-bg); color: var(--grid-amber); border-color: #E6D9A8; }
.qa-btn-amber:hover { background: var(--grid-amber); color: #fff; border-color: var(--grid-amber); }
.qa-btn-green { background: var(--grid-green-bg); color: var(--grid-green); border-color: #BBD3BB; }
.qa-btn-green:hover { background: var(--grid-green); color: #fff; border-color: var(--grid-green); }

/* today's stats */
.kpi-card { border-radius: 0; }
.kpi-card .kpi-icon i { color: inherit !important; }
.kpi-present    { background: var(--grid-green-bg); border-color: #BBD3BB; color: var(--grid-green); }
.kpi-present .kpi-label, .kpi-present .kpi-value { color: var(--grid-green); }
.kpi-absent     { background: var(--grid-red-bg); border-color: #E3BCBC; color: var(--grid-red); }
.kpi-absent .kpi-label, .kpi-absent .kpi-value { color: var(--grid-red); }
.kpi-incomplete { background: var(--grid-amber-bg); border-color: #E6D9A8; color: var(--grid-amber); }
.kpi-incomplete .kpi-label, .kpi-incomplete .kpi-value { color: var(--grid-amber); }
[data-live-section="today"] [onclick="openLateInbox()"] { background: var(--grid-red-bg) !important; border-color: #E3BCBC !important; border-radius: 0 !important; color: var(--grid-red) !important; }

/* chart + month navigation + pagination */
.chart-nav-btn, .sm-month-label, .sm-month-input, .att-page-btn { border-radius: 0; border-color: var(--grid-border); }
.chart-nav-btn { background: #fff; color: var(--grid-navy); }
.chart-nav-btn:hover:not([aria-disabled="true"]) { background: var(--blue-light); border-color: var(--grid-navy); color: var(--grid-navy); }
.sm-month-label { color: var(--grid-navy); }
.sm-month-input:focus { border-color: var(--grid-navy); }
.chart-legend-dot, .sm-legend-dot { border-radius: 0; }
.chart-nav-title, .chart-legend-item { color: var(--grid-muted); }
.att-pagination-info { color: var(--grid-muted); }
.att-page-btn:hover:not(:disabled):not(.active) { background: var(--blue-light); border-color: var(--grid-navy); color: var(--grid-navy); }
.att-page-btn.active { background: var(--grid-navy); border-color: var(--grid-navy); }
.sm-action-btn { border-radius: 0; border: 1px solid var(--grid-border); text-decoration: none; }
.sm-action-btn.print { background: #fff; color: var(--grid-navy); }
.sm-action-btn.print:hover { background: var(--blue-light); }
.sm-action-btn.export { background: var(--grid-green); border-color: var(--grid-green); color: #fff; }
.sm-action-btn.export:hover { background: #234823; }

/* monthly summary table */
.sm-table-wrap { border-radius: 0; box-shadow: none; border: 1px solid var(--grid-border-soft); }
.sm-table th { background: var(--surface-soft); color: var(--grid-navy); border-bottom: 2px solid var(--grid-border); }
.sm-table td { border-bottom: 1px solid var(--grid-border-soft); }
.sm-table td.sm-name-td { color: var(--ink); }
.sm-table td.sm-present    { color: var(--grid-green); background: var(--grid-green-bg); }
.sm-table td.sm-absent     { color: var(--grid-red);   background: var(--grid-red-bg); }
.sm-table td.sm-incomplete { color: var(--grid-amber); background: var(--grid-amber-bg); }
.sm-table td.sm-has-mark { box-shadow: inset 0 0 0 1px rgba(27,42,74,.25); }
.sm-table-empty, .chart-empty { color: var(--ink-faint); }
.weekend-notice { border-radius: 0; }

/* attendance-log table (also covers the inline styles the script writes) */
#attendanceTable { border: 1px solid var(--grid-border-soft); }
#attendanceTable th { border-bottom: 1px solid var(--grid-border-soft); color: var(--grid-navy); }
#attLogThead th { background: var(--surface-soft) !important; }
#attLogThead th[style*="#fff8e1"] { background: var(--grid-amber-bg) !important; }
#attLogThead th[style*="#e8f5e9"] { background: var(--grid-green-bg) !important; }
#attendanceTable tbody td { border-bottom: 1px solid var(--grid-border-soft); }
#attendanceTable tbody tr:hover td { background: #F8F9FC; }
#attendanceTable img { border-radius: 0 !important; border: 1px solid var(--grid-border-soft); }
td.day-off, tr.day-off-row td { background: #ede7f6; }
td.present    { color: var(--grid-green); }
td.absent     { color: var(--grid-red); }
td.incomplete, td.missed { color: var(--grid-amber); }
#searchInput { border: 1px solid var(--grid-border); border-radius: 0; }
#searchInput:focus, #modalMonthFilter:focus, #modalDayFilter:focus { outline: none; border-color: var(--grid-navy); }

/* modal shell: square, flat, navy header with a gold rule (company_list.php's popup look) */
#attSettingsOverlay, #wizardOverlay, #hoursPopupOverlay, #lateInboxOverlay,
#customConfirmOverlay, #attLogModal, #skipBlockOverlay, #dutyGapOverlay { background: rgba(27,42,74,.55); }
#attSettingsBox, #wizardBox, #hoursPopupBox, #lateInboxBox, #customConfirmBox, #skipBlockBox, #dutyGapBox,
.alm-box { border-radius: 0; box-shadow: 0 20px 60px rgba(0,0,0,.25); border-top: 3px solid var(--grid-navy); }
.alm-box { background: #fff; width: 1100px; max-width: 100%; margin: auto; overflow: hidden; animation: as-in .3s cubic-bezier(.34,1.56,.64,1); }
.as-header, .li-header, .alm-head { background: var(--grid-navy); border-bottom: 3px solid #F7C600; }
.alm-head { color: #fff; padding: 18px 24px; display: flex; align-items: center; justify-content: space-between; }
.alm-head h3 { margin: 0; font-size: 18px; font-weight: 700; display: flex; align-items: center; gap: 8px; }
.alm-body { padding: 20px 24px; }
.alm-toolbar { display: flex; align-items: center; gap: 12px; flex-wrap: wrap; margin-bottom: 16px; }
.alm-group { display: flex; align-items: center; gap: 8px; background: var(--surface-soft); padding: 8px 12px; border: 1px solid var(--grid-border-soft); }
.alm-group.tight { gap: 6px; padding: 6px 10px; }
.alm-group label { font-size: 13px; font-weight: 600; color: var(--grid-muted); white-space: nowrap; }
.alm-group select { padding: 6px 10px; border: 1px solid var(--grid-border); border-radius: 0; font-size: 13px; background: #fff; }
#modalDayFilter { width: 72px; }
.alm-nav-btn { background: var(--grid-navy); color: #fff; border: 1px solid var(--grid-navy); border-radius: 0; padding: 5px 11px; font-size: 13px; font-weight: 700; cursor: pointer; transition: opacity .15s; }
.alm-nav-btn:hover { opacity: .85; }
.alm-spinner { display: none; text-align: center; padding: 40px; color: var(--grid-muted); font-size: 14px; }
.alm-spinner i { display: inline-block; width: 28px; height: 28px; border: 3px solid var(--grid-border-soft); border-top-color: var(--grid-navy); border-radius: 50%; animation: spin .7s linear infinite; vertical-align: middle; margin-right: 10px; }
#attLogDateHeading { margin: 0 0 12px; font-size: 16px; color: var(--grid-navy); }
.as-close-btn, .li-close-btn, .alm-close-btn { background: rgba(255,255,255,.10); border: 1px solid rgba(255,255,255,.25); color: #fff; border-radius: 0; padding: 6px 12px; font-size: 12px; font-weight: 600; text-transform: uppercase; letter-spacing: .4px; cursor: pointer; transition: background .2s; }
.as-close-btn:hover, .li-close-btn:hover, .alm-close-btn:hover { background: rgba(255,255,255,.22); }
.as-today-only-notice { background: var(--grid-amber-bg); border-color: #E6D9A8; color: var(--grid-amber); border-radius: 0; }
.as-current-settings { background: var(--surface-soft); border-radius: 0; border-left: 4px solid var(--grid-navy); }
.as-current-settings strong.as-title { color: var(--grid-navy); }
.as-open-wizard { display: inline-flex; align-items: center; gap: 8px; padding: 10px 20px; background: var(--grid-navy); color: #fff; border: 1px solid var(--grid-navy); border-radius: 0; font-size: 12px; font-weight: 600; text-transform: uppercase; letter-spacing: .4px; cursor: pointer; transition: opacity .2s; margin-bottom: 4px; }
.as-open-wizard:hover { opacity: .88; }

/* wizard */
#wizardProgress { background: var(--grid-border-soft); }
#wizardProgressBar { background: var(--grid-navy); }
.wiz-header, .wiz-footer { border-color: var(--grid-border-soft); }
.wiz-step-label { color: var(--grid-amber); }
.wiz-header h2 { color: var(--grid-navy); }
.field-group label { color: var(--grid-muted); }
.field-group input[type="time"], .field-group input[type="number"] { border: 1px solid var(--grid-border); border-radius: 0; }
.field-group input:focus { border-color: var(--grid-navy); box-shadow: none; }
.field-group input.input-error { border-color: var(--grid-red) !important; background: var(--grid-red-bg); }
.field-hint.am-hint { color: var(--grid-amber); }
.field-hint.pm-hint { color: var(--grid-navy); }
.sg-card { background: var(--surface-soft); border: 1px solid var(--grid-border-soft); border-radius: 0; }
.sg-scope { background: var(--grid-green-bg); color: var(--grid-green); border-radius: 0; }
.sg-scope.scope-all { background: var(--blue-light); color: var(--grid-navy); }
.wiz-dot { background: var(--grid-border); }
.wiz-dot.active { background: var(--grid-navy); }
.wiz-btn, .hp-btn-ok, .hp-btn-continue, .cc-btn { border-radius: 0; font-size: 12px; text-transform: uppercase; letter-spacing: .4px; border: 1px solid transparent; }
.wiz-btn-back, .hp-btn-ok, .cc-btn-cancel { background: #fff; color: var(--grid-navy); border-color: var(--grid-border); }
.wiz-btn-back:hover, .hp-btn-ok:hover, .cc-btn-cancel:hover { background: var(--blue-light); color: var(--grid-navy); }
.wiz-btn-next { background: var(--grid-navy); }
.wiz-btn-next:hover { background: #24375E; }
.wiz-btn-save, .cc-btn-confirm-approve { background: var(--grid-green); box-shadow: none; }
.wiz-btn-save:hover, .cc-btn-confirm-approve:hover { background: #234823; }
.skip-row, .skip-warning, .field-group.disabled-field input { border-radius: 0; }
.skip-row { background: var(--surface-soft); border-color: var(--grid-border-soft); }
.skip-checkbox:hover span { color: var(--grid-navy); }
.skip-warning { background: var(--grid-red-bg); border-color: #E3BCBC; color: var(--grid-red); border-radius: 0; }
#amErrorMsg, #pmErrorMsg { background: var(--grid-red-bg) !important; border-color: #E3BCBC !important; color: var(--grid-red) !important; border-radius: 0 !important; }
.wiz-body > div[style*="#e8f5e9"] { background: var(--grid-green-bg) !important; color: var(--grid-green) !important; border-radius: 0 !important; }
.wiz-body > div[style*="#fff8e1"] { background: var(--grid-amber-bg) !important; color: var(--grid-amber) !important; border-radius: 0 !important; }

/* 8-hour / continue-anyway / confirm dialogs */
.hp-header { background: var(--grid-red); }
.sb-list { background: var(--grid-red-bg); border-color: #E3BCBC; border-radius: 0; }
.sb-list li { border-color: #E3BCBC; }
.sb-list .sb-days { color: var(--grid-red); }
.sb-tip { border-radius: 0; background: var(--surface-soft); border-left-color: var(--grid-navy); }
.hp-breakdown { background: var(--grid-red-bg); border-color: #E3BCBC; border-radius: 0; }
.hp-breakdown .hpb-row .hpb-val { color: var(--grid-red); }
.hp-breakdown .hpb-row.total { border-color: #E3BCBC; }
.hp-btn-continue { background: var(--grid-amber); color: #fff; }
.hp-btn-continue:hover { background: #7F6808; }
.cc-icon-wrap { border-radius: 0; }
.cc-icon-wrap.approve { background: var(--grid-green-bg); }
.cc-icon-wrap.reject { background: var(--grid-red-bg); }
.cc-title { color: var(--grid-navy); }
.cc-student-badge { background: var(--blue-light); border-color: var(--grid-border); color: var(--grid-navy); border-radius: 0; }
.cc-btn-confirm-reject { background: var(--grid-red); box-shadow: none; }
.cc-btn-confirm-reject:hover { background: #7F2020; }

/* late-request inbox */
.li-tabs { background: var(--surface-soft); border-bottom: 1px solid var(--grid-border); }
.li-tab.active { color: var(--grid-navy); border-bottom-color: #F7C600; }
.li-spinner, .req-photo-frame .rp-spinner { border-top-color: var(--grid-navy); }
.req-card:hover { background: #F8F9FC; }
.req-type-badge, .req-status-badge { border-radius: 0; }
.req-type-badge.am_time_in { background: var(--grid-amber); }
.req-type-badge.am_time_out { background: var(--grid-red); }
.req-type-badge.pm_time_in { background: var(--grid-navy); }
.req-type-badge.pm_time_out { background: var(--grid-green); }
.req-status-badge.approved { background: var(--grid-green-bg); color: var(--grid-green); }
.req-status-badge.rejected { background: var(--grid-red-bg); color: var(--grid-red); }
.req-evidence, .req-reason-box, .req-photo-frame, .req-photo-no-photo, .req-duty-info { border-radius: 0; }
.req-evidence.ok .req-evidence-head { background: var(--grid-green-bg); color: var(--grid-green); }
.req-evidence.review .req-evidence-head { background: var(--grid-amber-bg); color: var(--grid-amber); }
.req-evidence.risk .req-evidence-head { background: var(--grid-red-bg); color: var(--grid-red); }
.req-duty-info { background: var(--grid-green-bg); border-color: #BBD3BB; color: var(--grid-green); }
.req-duty-info.has-late { background: var(--grid-amber-bg); border-color: #E6D9A8; color: var(--grid-amber); }
.req-photo-frame:hover { border-color: var(--grid-navy); }
.req-photo-frame .rp-expand-hint { border-radius: 0; }
.req-btn-allow { border-radius: 0; background: var(--grid-green); font-family: inherit; text-transform: uppercase; letter-spacing: .4px; font-size: 12px; }
.req-btn-allow:hover { background: #234823; }
.req-btn-reject { border-radius: 0; border-color: var(--grid-border); font-family: inherit; text-transform: uppercase; letter-spacing: .4px; font-size: 12px; }
.req-btn-reject:hover { background: var(--grid-red-bg); border-color: var(--grid-red); color: var(--grid-red); }
#reqPhotoLightbox img, #modalImg { border-radius: 0 !important; box-shadow: none !important; }
/* Late Attendance Requests — two sections (Pending / Request History) and a compact photo thumbnail instead of the wide banner */
.li-tab-count { display:inline-block; min-width:18px; padding:0 6px; margin-left:4px; font-size:11px; font-weight:700; line-height:18px; text-align:center; background:#e5e7ee; color:var(--grid-navy); }
.li-tab.active .li-tab-count { background:var(--grid-navy); color:#fff; }
.li-history-bar { display:flex; align-items:center; justify-content:space-between; gap:12px; padding:10px 22px; background:var(--surface-soft); border-bottom:1px solid var(--grid-border); font-size:12.5px; color:#5A6272; font-weight:600; }
.li-clear-btn { display:inline-flex; align-items:center; gap:6px; padding:7px 14px; background:#fff; border:1px solid var(--grid-red); color:var(--grid-red); font-size:11.5px; font-weight:700; text-transform:uppercase; letter-spacing:.3px; cursor:pointer; border-radius:0; font-family:inherit; transition:background .15s,color .15s; }
.li-clear-btn:hover:not(:disabled) { background:var(--grid-red); color:#fff; }
.li-clear-btn:disabled { opacity:.45; cursor:not-allowed; }
.req-main { display:flex; gap:14px; align-items:flex-start; margin-bottom:12px; }
.req-main-info { flex:1; min-width:0; }
.req-main-info .req-reason-box { margin:10px 0 0; }
.req-thumb { position:relative; flex:0 0 96px; width:96px; height:96px; background:#EEF1F6; border:1px solid var(--grid-border); overflow:hidden; cursor:zoom-in; display:flex; align-items:center; justify-content:center; border-radius:0; }
.req-thumb:hover { border-color:var(--grid-navy); }
.req-thumb img { width:100%; height:100%; object-fit:cover; display:block; }
.req-thumb .rp-zoom { position:absolute; right:4px; bottom:4px; width:22px; height:22px; background:rgba(27,42,74,.78); color:#fff; font-size:11px; display:flex; align-items:center; justify-content:center; pointer-events:none; }
.req-thumb .rp-loading { color:#8A93A8; font-size:11px; padding:0; display:flex; flex-direction:column; align-items:center; gap:6px; text-align:center; }
.req-thumb .rp-spinner { width:18px; height:18px; border:2.5px solid #d6dbe8; border-top-color:var(--grid-navy); border-radius:50%; animation:spin .7s linear infinite; }
.req-thumb.is-empty { cursor:default; flex-direction:column; gap:4px; color:#8A93A8; font-size:10.5px; text-transform:uppercase; letter-spacing:.3px; font-weight:600; text-align:center; }
.req-thumb.is-empty i { font-size:22px; color:#B7BFD0; }
.req-card.is-history { padding:12px 22px; }
.req-card.is-history .req-main { margin-bottom:0; }
.req-card.is-history .req-thumb { flex-basis:60px; width:60px; height:60px; }
.req-card.is-history .req-thumb .rp-zoom { width:18px; height:18px; font-size:9px; }
.req-card.is-history .req-thumb.is-empty i { font-size:16px; }
.req-card.is-history .req-thumb.is-empty span { display:none; }
.req-history-head { display:flex; align-items:center; justify-content:space-between; gap:10px; flex-wrap:wrap; }
.req-history-reason { margin-top:6px; font-size:12.5px; color:#5A6272; line-height:1.5; overflow:hidden; display:-webkit-box; -webkit-line-clamp:2; -webkit-box-orient:vertical; }
.req-history-reason b { color:#1B2A4A; font-weight:700; }
.req-history-when { font-size:11.5px; color:#8A93A8; }
.req-card.is-history .req-duty-info { margin:8px 0 0; }
@media (max-width:520px){ .req-main { flex-direction:column; } .req-thumb { width:100%; flex-basis:auto; height:150px; } }

/* in-page notification (builtin-notif) — the navy toast used by company_list.php */
.builtin-notif { background: var(--grid-navy); border: 1px solid #55668C; border-radius: 0; box-shadow: 0 8px 24px rgba(27,42,74,.30); }
.bn-header { background: transparent; }
.bn-title { color: #fff; }
.bn-close { border-radius: 0; }
.bn-body { color: #E3E8F1; }
.bn-body strong { color: #fff; }
.bn-action { background: transparent; border-top-color: rgba(255,255,255,.18); color: #F7C600; }
.bn-action:hover { background: #24375E; }
.bn-progress { background: rgba(255,255,255,.12); }
.bn-progress-bar { background: #F7C600; }

/* narrow screens: stack the two columns so nothing is cut off */
@media (max-width: 1100px) {
    .split-layout { flex-direction: column; }
    .split-left, .split-right { width: 100%; flex: none; position: static; }
}
@media (max-width: 768px) {
    .page-inner { padding: 16px; }
    .field-row, .summary-grid { grid-template-columns: 1fr; }
}
/* ── Step 3 (Confirm & Save): same look as the manual Add New Student form in admin_student_list.php — compact uppercase title with
      an icon, labelled fields in a grid, small hints under each field and a bordered action bar. Scoped to #wizStep3 only. ── */
#wizStep3 .s3-header { padding: 16px 24px 10px; border-bottom: 1px solid var(--grid-border); margin: 0 0 0; }
#wizStep3 .s3-title-row { display: flex; justify-content: space-between; align-items: flex-start; gap: 12px; }
#wizStep3 .s3-header h2 { font-size: 15px; text-transform: uppercase; letter-spacing: .4px; display: flex; align-items: center; gap: 8px; }
#wizStep3 .s3-header h2 i { font-size: 14px; }
#wizStep3 .s3-close { background: none; border: none; font-size: 24px; line-height: 1; cursor: pointer; color: var(--grid-muted); transition: color .2s; padding: 0 2px; }
#wizStep3 .s3-close:hover { color: var(--grid-navy); }
#wizStep3 .s3-intro { margin: 0 0 10px; font-size: 11.5px; line-height: 1.45; color: var(--grid-muted); }
#wizStep3 .s3-body { padding: 12px 24px 4px; max-height: calc(100vh - 230px); max-height: calc(100dvh - 230px); min-height: 120px; overflow-y: auto; }
#wizStep3 .sg-scope { align-items: flex-start; font-size: 11.5px; line-height: 1.45; padding: 8px 12px; margin-bottom: 12px; }
#wizStep3 .sg-scope i { margin-top: 2px; }
#wizStep3 .s3-grid { display: grid; grid-template-columns: repeat(3, 1fr); column-gap: 14px; row-gap: 0; align-items: start; }
#wizStep3 .s3-grid .s3-span { grid-column: 1 / -1; }
#wizStep3 .form-group { margin-bottom: 9px; }
#wizStep3 .form-group label { display: block; font-weight: 600; color: #1e293b; margin-bottom: 4px; font-size: 12px; text-transform: none; letter-spacing: 0; }
#wizStep3 .s3-field { width: 100%; box-sizing: border-box; padding: 7px 10px; border: 1px solid var(--grid-border); border-radius: 0; font-size: 13px; font-weight: 600; color: #1e293b; background: var(--surface-soft); min-height: 34px; line-height: 1.35; overflow-wrap: anywhere; }
#wizStep3 .s3-field.s3-total { color: var(--grid-green); background: var(--grid-green-bg); }
#wizStep3 .s3-field.s3-total span { font-size: 14px; }
#wizStep3 .help-text { font-size: 10.5px; color: var(--grid-muted); margin-top: 3px; line-height: 1.35; }
#wizStep3 .s3-notify-field { display: flex; align-items: center; gap: 8px; }
#wizStep3 .s3-notify-field i { color: var(--grid-navy); font-size: 12px; }
#wizStep3 .s3-footer { margin-top: 4px; padding: 10px 24px 12px; border-top: 1px solid var(--grid-border); justify-content: flex-end; }
#wizStep3 .s3-footer .wiz-dots { display: none; }
#wizStep3 .s3-footer .wiz-btn { padding: 10px 24px; font-weight: 600; letter-spacing: .3px; }
#wizStep3 .s3-footer .wiz-btn-save { background: var(--grid-navy); }
#wizStep3 .s3-footer .wiz-btn-save:hover { background: #24375E; }
#wizardBox.s3-wide { width: 860px; max-width: 94%; }
@media (max-width: 760px) {
    #wizStep3 .s3-grid { grid-template-columns: 1fr 1fr; }
}
@media (max-width: 480px) {
    #wizStep3 .s3-grid { grid-template-columns: 1fr; }
    #wizStep3 .s3-header, #wizStep3 .s3-body, #wizStep3 .s3-footer { padding-left: 14px; padding-right: 14px; }
}</style>
</head>
<body>
<style id="cvCompanyShellCss">
/* side-menu header: full name + role (same as admin_student_list.php's header) */
#sidebar .sidebar-header { padding: 20px; min-height: 72px; }
#sidebar .sidebar-user-info { display: flex; flex-direction: column; gap: 1px; overflow: hidden; min-width: 0; max-width: 180px; transition: opacity .2s, width .3s; }
#sidebar .sidebar-user-name { color: #FFD700; font-size: 18px; font-weight: bold; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; line-height: 1.3; }
#sidebar .sidebar-user-role { color: rgba(255,255,255,.55); font-size: 11px; font-weight: 700; text-transform: uppercase; letter-spacing: .8px; margin-top: 3px; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
#sidebar.collapsed .sidebar-user-info { opacity: 0; width: 0; overflow: hidden; }

/* loading page: never fades in while a page is being left */
#globalLoadingOverlay.gl-instant { transition: none; }

/* logout confirmation popup (same square navy look as the admin pages') */
.cv-logout-overlay { position: fixed; inset: 0; z-index: 300000; display: flex; align-items: center; justify-content: center; padding: 20px;
    background: rgba(27,42,74,.45); opacity: 0; visibility: hidden; transition: opacity .2s ease, visibility .2s ease; }
.cv-logout-overlay.show { opacity: 1; visibility: visible; }
.cv-logout-box { background: #fff; width: 420px; max-width: 100%; border-top: 3px solid #1B2A4A; box-shadow: 0 20px 60px rgba(0,0,0,.25);
    padding: 26px 24px 22px; font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif; transform: translateY(8px); transition: transform .2s ease; }
.cv-logout-overlay.show .cv-logout-box { transform: translateY(0); }
.cv-logout-box h3 { margin: 0 0 10px; font-size: 16px; color: #1B2A4A; display: flex; align-items: center; gap: 10px; }
.cv-logout-box h3 i { color: #1B2A4A; }
.cv-logout-box p { margin: 0 0 22px; font-size: 13.5px; color: #4A5568; line-height: 1.6; }
.cv-logout-actions { display: flex; justify-content: flex-end; gap: 8px; }
.cv-logout-btn { display: inline-flex; align-items: center; gap: 8px; border: 1px solid #1B2A4A; cursor: pointer; font-family: inherit; font-size: 12px; font-weight: 600;
    letter-spacing: .4px; text-transform: uppercase; padding: 11px 18px; border-radius: 0; background: #1B2A4A; color: #fff; transition: opacity .2s ease; }
.cv-logout-btn:hover { opacity: .88; }
.cv-logout-btn:focus-visible { outline: 2px solid #F7C600; outline-offset: 2px; }
.cv-logout-btn.ghost { background: #fff; color: #1B2A4A; border-color: #D5DBE6; }
@media (prefers-reduced-motion: reduce) { .cv-logout-overlay, .cv-logout-box { transition: none; } }
</style><style id="cvCompanyShellOverlayCss">
#globalLoadingOverlay { position: fixed; inset: 0; z-index: 200000; display: flex; align-items: center; justify-content: center; background: rgba(238,241,246,.92); opacity: 1; visibility: visible; transition: opacity .35s ease, visibility .35s ease; }
#globalLoadingOverlay.hidden { opacity: 0; visibility: hidden; pointer-events: none; }
.global-loading-box { display: flex; flex-direction: column; align-items: center; gap: 16px; animation: cvShellPop .35s ease; }
.global-loading-spinner { width: 64px; height: 64px; border: 0; border-radius: 50%; box-sizing: border-box; background: conic-gradient(from 0deg, rgba(27,42,74,.12) 0deg, rgba(27,42,74,.35) 120deg, rgba(27,42,74,.7) 240deg, #1B2A4A 330deg, #1B2A4A 360deg);
    -webkit-mask: radial-gradient(farthest-side, transparent calc(100% - 9px), #000 calc(100% - 8px)), repeating-conic-gradient(from 5deg, #000 0deg 20deg, transparent 20deg 30deg); -webkit-mask-composite: source-in;
            mask: radial-gradient(farthest-side, transparent calc(100% - 9px), #000 calc(100% - 8px)), repeating-conic-gradient(from 5deg, #000 0deg 20deg, transparent 20deg 30deg); mask-composite: intersect;
    will-change: transform; animation: cvShellRing 1s steps(12, end) infinite; }
@keyframes cvShellRing { to { transform: rotate(360deg); } }
.global-loading-text { font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif; font-size: 13px; font-weight: 700; color: #1B2A4A; text-transform: uppercase; letter-spacing: .6px; display: flex; align-items: center; gap: 8px; }
.global-loading-dots span { animation: cvShellDots 1.2s infinite; opacity: 0; }
.global-loading-dots span:nth-child(2) { animation-delay: .2s; }
.global-loading-dots span:nth-child(3) { animation-delay: .4s; }
@keyframes cvShellPop { from { transform: scale(.9); opacity: 0; } to { transform: scale(1); opacity: 1; } }
@keyframes cvShellDots { 0%, 20% { opacity: 0; } 50% { opacity: 1; } 100% { opacity: 0; } }
@media (prefers-reduced-motion: reduce) { .global-loading-spinner { animation-duration: 2s; } #globalLoadingOverlay { transition-duration: .01s; } }
</style>
<div id="globalLoadingOverlay">
    <div class="global-loading-box">
        <div class="global-loading-spinner"></div>
        <div class="global-loading-text"><span id="globalLoadingLabel">Loading</span><span class="global-loading-dots"><span>.</span><span>.</span><span>.</span></span></div>
    </div>
</div>
<noscript><style>#globalLoadingOverlay { display: none !important; }</style></noscript><script>
(function () {
    var FULL = true;
    var ov = document.getElementById('globalLoadingOverlay');
    if (!ov) { if (FULL) window.showActionLoading = window.hideActionLoading = function () {}; return; }
    if (window._cvCompanyShellNav) return;
    window._cvCompanyShellNav = true;

    var MIN_MS = 350, SAFETY_MS = 4000, NAV_STUCK_MS = 15000, started = Date.now();
    var ACTION_MIN_MS = 350, ACTION_SAFETY_MS = 180000;      // saving + e-mailing can take a while; never stay up forever
    var done = !FULL, actions = 0, actionShownAt = 0, actionHideTimer = null, actionSafetyTimer = null;
    var navigating = false, navTimer = null;
    function labelEl() { return document.getElementById('globalLoadingLabel'); }

    /* ── 1) page load: the loading page is up from the first paint and closes once the page has loaded ── */
    function hide() {
        if (done) return; done = true;
        setTimeout(function () {
            if (actions > 0 || navigating) return;           // something else is using the loading page — it closes it itself
            ov.classList.remove('gl-instant'); ov.classList.add('hidden');
        }, Math.max(0, MIN_MS - (Date.now() - started)));
    }
    if (FULL) {
        if (document.readyState === 'complete') hide(); else window.addEventListener('load', hide);
        setTimeout(hide, SAFETY_MS);
    }

    /* ── 2) ACTION LOADING PAGE (full mode): showActionLoading('Saving') … hideActionLoading() around any action the
          user starts. Overlapping actions share one loading page, it stays up at least ACTION_MIN_MS, and a safety
          timer closes it if a request never answers. ── */
    if (FULL) {
        var finishAction = function () {
            if (actions > 0 || navigating) return;
            clearTimeout(actionSafetyTimer);
            ov.classList.remove('gl-instant'); ov.classList.add('hidden');
            var l = labelEl(); if (l) l.textContent = 'Loading';
        };
        window.showActionLoading = function (label) {
            actions++;
            clearTimeout(actionHideTimer);
            if (actions === 1) actionShownAt = Date.now();
            var l = labelEl(); if (l) l.textContent = label || 'Processing';
            ov.classList.remove('gl-instant', 'hidden');
            clearTimeout(actionSafetyTimer);
            actionSafetyTimer = setTimeout(function () { actions = 0; finishAction(); }, ACTION_SAFETY_MS);
        };
        window.hideActionLoading = function () {
            if (actions === 0) return;                       // unbalanced call: ignore
            actions--;
            if (actions > 0) return;
            clearTimeout(actionHideTimer);
            actionHideTimer = setTimeout(finishAction, Math.max(0, ACTION_MIN_MS - (Date.now() - actionShownAt)));
        };
    }

    /* ── 3) NAVIGATION LOADING PAGE: shown the moment a same-tab link (side menu, buttons) is clicked ── */
    var FILE_RE  = /\.(pdf|xlsx?|csv|docx?|pptx?|zip|png|jpe?g|gif|webp|txt)$/i;
    var PARAM_RE = /[?&][^=&]*(export|download|print|stream|pdf|preview|blob|file)[^=&]*=/i;
    function isPageUrl(u) {
        if (u.origin !== window.location.origin || !/^https?:$/.test(u.protocol)) return false;
        return !(FILE_RE.test(u.pathname) || PARAM_RE.test(u.search));
    }
    var navObserver = null;
    function navShow() {
        if (navigating) return;
        navigating = true;
        var l = labelEl(); if (l && !(window.globalLoadingNavigating)) l.textContent = 'Loading';
        ov.classList.add('gl-instant'); ov.classList.remove('hidden');
        // a page that has its own loader may close it meanwhile — keep it up while we are leaving
        if (window.MutationObserver) {
            navObserver = new MutationObserver(function () { if (navigating && ov.classList.contains('hidden')) ov.classList.remove('hidden'); });
            navObserver.observe(ov, { attributes: true, attributeFilter: ['class'] });
        }
        clearTimeout(navTimer);
        navTimer = setTimeout(navReset, NAV_STUCK_MS);       // safety: still here → it was not a real page change
    }
    function navReset() {
        clearTimeout(navTimer);
        if (!navigating) return;
        navigating = false;
        if (navObserver) { navObserver.disconnect(); navObserver = null; }
        if (actions === 0) { ov.classList.remove('gl-instant'); ov.classList.add('hidden'); var l = labelEl(); if (l) l.textContent = 'Loading'; }
    }
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
        // decided after every other click handler has run, so links the page handles itself (popups, the logout confirm) are left alone
        setTimeout(function () { if (!e.defaultPrevented) navShow(); }, 0);
    });
    // Back / Forward cache restore: the page did not reload, so clear what leaving it left behind
    window.addEventListener('pageshow', function (e) {
        if (!e.persisted) return;
        done = true; actions = 0;
        navReset();
        if (FULL) ov.classList.add('hidden');
    });
})();
</script>
<!-- Export result screen ("Export Successful" / "Export Failed") — same markup, look and behaviour as admin_reports.php -->
<style>
    #globalResultOverlay { position: fixed; inset: 0; z-index: 200001; display: flex; align-items: center; justify-content: center; padding: 20px; background: rgba(238,241,246,.92); opacity: 1; visibility: visible; transition: opacity .35s ease, visibility .35s ease; }
    #globalResultOverlay.hidden { opacity: 0; visibility: hidden; pointer-events: none; }
    .global-result-box { display: flex; flex-direction: column; align-items: center; gap: 14px; max-width: 460px; width: 100%; text-align: center; animation: globalLoadingPop .35s ease; }
    .global-result-icon { width: 64px; height: 64px; border-radius: 50%; display: flex; align-items: center; justify-content: center; font-size: 28px; color: #fff; }
    #globalResultOverlay.is-success .global-result-icon { background: var(--grid-green, #2C5A2C); }
    #globalResultOverlay.is-error .global-result-icon { background: var(--grid-red, #A02A2A); }
    .global-result-title { font-size: 14px; font-weight: 700; text-transform: uppercase; letter-spacing: .6px; margin: 0; }
    #globalResultOverlay.is-success .global-result-title { color: var(--grid-green, #2C5A2C); }
    #globalResultOverlay.is-error .global-result-title { color: var(--grid-red, #A02A2A); }
    .global-result-message { font-size: 13px; line-height: 1.5; color: var(--grid-navy, #1B2A4A); margin: 0; white-space: pre-line; max-height: 40vh; overflow-y: auto; word-break: break-word; }
    .global-result-ok { padding: 10px 28px; border-radius: 0; font-weight: 600; cursor: pointer; border: 1px solid var(--grid-navy, #1B2A4A); background: var(--grid-navy, #1B2A4A); color: #fff; text-transform: uppercase; letter-spacing: .4px; font-size: 12px; transition: opacity .2s; }
    .global-result-ok:hover { opacity: .88; }
    .sm-action-btn.export[data-exporting="1"] { opacity: .7; pointer-events: none; }
</style>
<div id="globalResultOverlay" class="hidden" role="alertdialog" aria-live="assertive" aria-labelledby="globalResultTitle" aria-describedby="globalResultMessage">
    <div class="global-result-box">
        <div class="global-result-icon"><i id="globalResultIcon" class="fas fa-check"></i></div>
        <p class="global-result-title" id="globalResultTitle"></p>
        <p class="global-result-message" id="globalResultMessage"></p>
        <button type="button" class="global-result-ok" id="globalResultOkBtn">OK</button>
    </div>
</div>
<script>
/* Result screen helpers (same as admin_reports.php): success closes itself after a few seconds, failure stays until OK / Esc. */
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

/* Export XLSX — the export loading flow of admin_reports.php: the button shows "Preparing...", the loading page shows
   "Preparing export", the file is fetched in the background so the page knows whether it really worked, then it is
   downloaded and "Export Successful" / "Export Failed" is shown. Without JavaScript the link still downloads the file
   directly (it keeps its href). Returning false cancels that default; returning true (script problem) lets the link work. */
var _attExporting = false;
var ATT_EXPORT_TIMEOUT_MS = 120000;
function doAttExport(link) {
    try {
        var href = link && link.getAttribute ? link.getAttribute('href') : '';
        if (!href || !window.fetch) return true;                 // old browser: fall back to the plain link
        if (_attExporting) return false;                         // already exporting
        _attExporting = true;
        var btnId = link.id, origHtml = link.innerHTML;
        link.setAttribute('data-exporting', '1');
        link.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Preparing...';
        if (window.showActionLoading) showActionLoading('Preparing export');
        var ctrl = (window.AbortController ? new AbortController() : null);
        var timer = ctrl ? setTimeout(function () { ctrl.abort(); }, ATT_EXPORT_TIMEOUT_MS) : null;
        var finish = function () {
            _attExporting = false;
            if (timer) clearTimeout(timer);
            // the summary panel may have been swapped by live updates meanwhile — find the button again
            var b = (btnId && document.getElementById(btnId)) || link;
            if (b) { b.innerHTML = origHtml; b.removeAttribute('data-exporting'); }
            if (window.hideActionLoading) hideActionLoading();
        };
        fetch(href, { method: 'GET', headers: { 'X-Requested-With': 'XMLHttpRequest' }, credentials: 'same-origin', cache: 'no-store', signal: ctrl ? ctrl.signal : undefined })
            .then(function (response) {
                var type = (response.headers.get('Content-Type') || '').toLowerCase();
                if (response.ok && type.indexOf('spreadsheetml') !== -1) {
                    var disp = response.headers.get('Content-Disposition') || '';
                    var m = disp.match(/filename\*?=(?:UTF-8'')?"?([^";]+)"?/i), name = 'attendance_export.xlsx';
                    if (m) { try { name = decodeURIComponent(m[1]); } catch (e) { name = m[1]; } }
                    return response.blob().then(function (blob) {
                        if (!blob || blob.size === 0) throw new Error('The server returned an empty file.');
                        return { blob: blob, fileName: name };
                    });
                }
                return response.text().then(function (text) {
                    var msg = '';
                    try { var d = JSON.parse(text); if (d && d.message) msg = d.message; } catch (e) {}
                    // this page reports export problems as short plain text (never HTML)
                    if (!msg && type.indexOf('text/plain') !== -1 && text && text.length < 300) msg = text.trim();
                    if (!msg) msg = response.ok
                        ? 'The server did not return an Excel file. Your session may have expired — please refresh the page and try again.'
                        : 'The server responded with an error (' + response.status + '). Please try again.';
                    throw new Error(msg);
                });
            })
            .then(function (file) {
                var url = URL.createObjectURL(file.blob), a = document.createElement('a');
                a.href = url; a.download = file.fileName; a.style.display = 'none';
                document.body.appendChild(a); a.click();
                setTimeout(function () { URL.revokeObjectURL(url); a.remove(); }, 1000);
                finish();
                showGlobalResult('success', 'Export Successful', 'Your Excel file "' + file.fileName + '" has been downloaded.', 3000);
            })
            .catch(function (err) {
                finish();
                var detail = (err && err.name === 'AbortError') ? 'The export took too long and was stopped. Please try again.'
                    : (err && err.message && err.message !== 'Failed to fetch') ? err.message
                    : 'Could not reach the server. Please check your connection and try again.';
                showGlobalResult('error', 'Export Failed', detail, 0);
            });
        return false;
    } catch (e) {
        _attExporting = false;
        return true; // anything unexpected: let the normal link download the file
    }
}
</script>

<div id="sidebar" class="sidebar">
<script>
(function () {
    var sb = document.getElementById('sidebar');
    if (!sb) return;
    var KEY = 'neustSidebarCollapsed';
    function isDesktop() { return !(window.matchMedia && window.matchMedia('(max-width: 768px)').matches); }
    try { if (isDesktop() && localStorage.getItem(KEY) === '1') sb.classList.add('collapsed'); } catch (e) { /* storage unavailable: default state */ }
    if (window.MutationObserver) {
        new MutationObserver(function () {
            if (!isDesktop()) return;
            try { localStorage.setItem(KEY, sb.classList.contains('collapsed') ? '1' : '0'); } catch (e) { /* state just won't persist */ }
        }).observe(sb, { attributes: true, attributeFilter: ['class'] });
    }
})();
</script>    <div class="sidebar-header">
<?php $cvSupNameSafe = htmlspecialchars(trim((string)($supervisor_name_display)) !== '' ? trim((string)($supervisor_name_display)) : 'Supervisor', ENT_QUOTES, 'UTF-8'); ?>
        <div class="sidebar-user-info" title="<?= $cvSupNameSafe ?>"><span class="sidebar-user-name" id="sidebarTitle"><?= $cvSupNameSafe ?></span><span class="sidebar-user-role">Supervisor</span></div>
        <button id="toggleBtn" class="toggle-btn"><i class="fas fa-bars"></i></button>
    </div>
    <div class="sidebar-links">
        <a href="Profile.php" style="position:relative;">
            <i class="fas fa-user-circle"></i>
            <span class="link-text">My Profile</span><!-- NEW (company chat notification): unread messages from the administrator / OJT trainees --><span class="sidebar-badge-chat" id="sidebarChatBadge" style="display:none"></span>
        </a>
        <a href="add_ojt_student.php">
            <i class="fas fa-user-graduate"></i>
            <span class="link-text">OJT Student List</span>
            <span class="sidebar-badge" id="sidebarInboxBadge"<?= $inbox_count > 0 ? '' : ' style="display:none;"' ?>><?= (int)$inbox_count ?></span>
        </a>
        <a href="CompanyForm.php">
            <i class="fas fa-file-contract"></i>
            <span class="link-text">Requirements</span>
        </a>
        <a href="attendance_management.php" class="active">
            <i class="fas fa-building"></i>
            <span class="link-text">Attendance Management</span>
            <?php if ($pending_lr_count > 0): ?>
                <span class="sidebar-badge-late" id="sidebarLateBadge"><?= $pending_lr_count ?></span>
            <?php else: ?>
                <span class="sidebar-badge-late" id="sidebarLateBadge" style="display:none;">0</span>
            <?php endif; ?>
        </a>
        <a href="company_reports.php">
            <i class="fas fa-chart-bar"></i>
            <span class="link-text">Company Reports</span>
            <span class="sidebar-badge-ungraded" id="sidebarReportBadge"<?= $ungraded_count > 0 ? '' : ' style="display:none;"' ?>><?= (int)$ungraded_count ?></span>
        </a>
    </div>
    <div class="logout-link">
        <a href="company_login.php?logout=1">
            <i class="fas fa-sign-out-alt"></i>
            <span class="link-text" style="margin-left:10px;">Logout</span>
        </a>
    </div>
</div>

<div class="main-content">
    <nav class="navbar">
        <img src="logo.webp" style="height:40px;margin-right:15px;">
        <div class="logo-section">
            <div>
                <div style="font-weight:bold; font-size:16px;">NEUST Atate Campus</div>
                <div style="font-size:11px; color:var(--neust-gold);">Web-Based Smart OJT Monitoring and Supervision Analytics System</div>
            </div>
        </div>
        <!-- UPDATED: compact date / live-time widget (same ids, same live clock script) -->
        <div class="datetime-widget dtw-in-navbar">
            <div class="dtw-date-block">
                <div class="dtw-date-icon"><i class="fas fa-calendar-day"></i></div>
                <div class="dtw-date-text">
                    <span class="dtw-date-label">Today</span>
                    <span class="dtw-date-value" id="dtwDateValue"><?= date("D, M j, Y") ?></span>
                </div>
            </div>
            <div class="dtw-divider"></div>
            <div class="dtw-time-block">
                <div class="dtw-time-icon"><i class="fas fa-clock"></i></div>
                <div class="dtw-time-text">
                    <span class="dtw-time-label">Live Time</span>
                    <span class="dtw-time-value" id="dtwTimeValue">--:-- <span class="dtw-ampm" id="dtwAmPm">--</span></span>
                </div>
                <span class="dtw-live-dot"></span>
            </div>
        </div>
    </nav>

    <div class="page-inner">

<div id="notifPermissionBanner" style="display:none!important;"></div>
<div id="builtInNotifContainer"></div>

<!-- UPDATED: the date / live-time widget now sits in the navbar (no extra row of white space here). -->

<div class="split-layout">

    <div class="split-left">

        <div class="panel-card" data-live-section="chart">
            <div class="panel-card-header">
                <?php
                $page_start_month = isset($chart_slice[0]) ? $chart_slice[0]['label'] : '—';
                $page_end_month   = isset($chart_slice[count($chart_slice)-1]) ? $chart_slice[count($chart_slice)-1]['label'] : '—';
                $nav_title = (count($chart_slice) > 1)
                    ? $page_start_month . ' – ' . $page_end_month
                    : ($page_start_month ?? '—');

                $base_url = strtok($_SERVER['REQUEST_URI'], '?');
                $existing_params = $_GET;
                unset($existing_params['chart_page']);

                function chartPageUrl($page, $base_url, $params) {
                    $p = $params;
                    $p['chart_page'] = $page;
                    return $base_url . '?' . http_build_query($p);
                }
                ?>
                <h3><i class="fas fa-chart-bar" style="font-size:13px;"></i> Attendance Overview</h3>
                <div class="chart-nav" style="margin:0; gap:6px;">
                    <?php if ($chart_page > 0): ?>
                        <a class="chart-nav-btn" href="<?= htmlspecialchars(chartPageUrl($chart_page - 1, $base_url, $existing_params)) ?>">&#8592;</a>
                    <?php else: ?>
                        <span class="chart-nav-btn" aria-disabled="true">&#8592;</span>
                    <?php endif; ?>
                    <span style="font-size:12px; color:#888; font-weight:600;"><?= htmlspecialchars($nav_title) ?><?php if ($total_chart_pages > 1): ?> <span style="color:#ccc;">(<?= $chart_page+1 ?>/<?= $total_chart_pages ?>)</span><?php endif; ?></span>
                    <?php if ($chart_page < $total_chart_pages - 1): ?>
                        <a class="chart-nav-btn" href="<?= htmlspecialchars(chartPageUrl($chart_page + 1, $base_url, $existing_params)) ?>">&#8594;</a>
                    <?php else: ?>
                        <span class="chart-nav-btn" aria-disabled="true">&#8594;</span>
                    <?php endif; ?>
                </div>
            </div>
            <div class="panel-card-body">
                <p style="font-size:11px; color:#aaa; margin:0 0 10px;">Monthly totals — Present, Incomplete, Absent (weekdays only, up to today)</p>
                <?php if (count($chart_slice) > 0): ?>
                <div class="chart-legend">
                    <span class="chart-legend-item"><span class="chart-legend-dot" style="background:#2C5A2C;"></span>Present</span>
                    <span class="chart-legend-item"><span class="chart-legend-dot" style="background:#A0850A;"></span>Incomplete</span>
                    <span class="chart-legend-item"><span class="chart-legend-dot" style="background:#A02A2A;"></span>Absent</span>
                </div>
                <div class="chart-canvas-wrap">
                    <canvas id="attendanceBarChart"></canvas>
                </div>
                <script src="https://cdnjs.cloudflare.com/ajax/libs/Chart.js/4.4.1/chart.umd.js"></script>
                <script>
                (function() {
                    const labels     = <?= json_encode($chart_labels_m) ?>;
                    const present    = <?= json_encode($chart_present_m) ?>;
                    const incomplete = <?= json_encode($chart_incomplete_m) ?>;
                    const absent     = <?= json_encode($chart_absent_m) ?>;
                    new Chart(document.getElementById('attendanceBarChart'), {
                        type: 'bar',
                        data: {
                            labels: labels,
                            datasets: [
                                { label: 'Present', data: present, backgroundColor: '#2C5A2C', borderRadius: 0, borderSkipped: false },
                                { label: 'Incomplete', data: incomplete, backgroundColor: '#A0850A', borderRadius: 0, borderSkipped: false },
                                { label: 'Absent', data: absent, backgroundColor: '#A02A2A', borderRadius: 0, borderSkipped: false }
                            ]
                        },
                        options: {
                            responsive: true,
                            maintainAspectRatio: false,
                            plugins: {
                                legend: { display: false },
                                tooltip: {
                                    callbacks: {
                                        title: function(items) { return items[0].label; },
                                        label: function(item) { return ' ' + item.dataset.label + ': ' + item.parsed.y; }
                                    }
                                }
                            },
                            scales: {
                                x: { title:{ display:true, text:'Month', font:{size:11}, color:'#888' }, ticks:{ font:{size:11,weight:'600'}, color:'#444' }, grid:{ display:false } },
                                y: { beginAtZero:true, ticks:{ stepSize:1, precision:0, font:{size:11}, color:'#555' }, grid:{ color:'rgba(0,0,0,0.06)' }, title:{ display:true, text:'Total Records', font:{size:11}, color:'#888' } }
                            }
                        }
                    });
                })();
                </script>
                <?php else: ?>
                <div class="chart-empty">No attendance records found yet.</div>
                <?php endif; ?>
            </div>
        </div>

        <div class="panel-card" data-live-section="summary">
            <div class="panel-card-header">
                <h3><i class="fas fa-table" style="font-size:13px;"></i> Monthly Attendance Summary</h3>
            </div>
            <div class="panel-card-body">
                <div class="sm-month-nav">
                    <div class="sm-month-nav-left">
                        <label>Month:</label>
                        <?php
                        // UPDATED: switch months with arrow buttons (same limits as before:
                        // from the OJT start month up to the OJT end month)
                        $sm_prev_month = date("Y-m", strtotime("-1 month", strtotime($month . "-01")));
                        $sm_next_month = date("Y-m", strtotime("+1 month", strtotime($month . "-01")));
                        $sm_month_params = $_GET;
                        unset($sm_month_params['month']);
                        // FIX: a date equal to today is not carried along any more — it would stay in the address (and in the page's live refresh)
                        // after midnight and the page would keep showing yesterday as if it were today.
                        if ($date !== date('Y-m-d')) $sm_month_params['date'] = $date; else unset($sm_month_params['date']);
                        $sm_month_base = strtok($_SERVER['REQUEST_URI'], '?');
                        $sm_month_url = function($ym) use ($sm_month_base, $sm_month_params) {
                            $p = $sm_month_params;
                            $p['month'] = $ym;
                            return $sm_month_base . '?' . http_build_query($p);
                        };
                        ?>
                        <div class="sm-month-arrows">
                            <?php if ($sm_prev_month >= $ojt_start_month): ?>
                                <a class="chart-nav-btn" href="<?= htmlspecialchars($sm_month_url($sm_prev_month)) ?>" title="Previous month (<?= date("F Y", strtotime($sm_prev_month . "-01")) ?>)">&#8592;</a>
                            <?php else: ?>
                                <span class="chart-nav-btn" aria-disabled="true">&#8592;</span>
                            <?php endif; ?>
                            <span class="sm-month-label" id="smMonthLabel"><?= date("F Y", strtotime($month . "-01")) ?></span>
                            <?php if ($sm_next_month <= $sm_month_max): ?>
                                <a class="chart-nav-btn" href="<?= htmlspecialchars($sm_month_url($sm_next_month)) ?>" title="Next month (<?= date("F Y", strtotime($sm_next_month . "-01")) ?>)">&#8594;</a>
                            <?php else: ?>
                                <span class="chart-nav-btn" aria-disabled="true">&#8594;</span>
                            <?php endif; ?>
                        </div>
                    </div>
                    <div class="sm-month-nav-right">
                        <button class="sm-action-btn print" onclick="printSummary()">
                            <i class="fas fa-print"></i> Print
                        </button>
                        <a class="sm-action-btn export" id="attExportBtn" href="attendance_management.php?export=xlsx&month=<?= htmlspecialchars($month) ?>" target="_blank" onclick="return doAttExport(this)">
                            <i class="fas fa-file-excel"></i> Export XLSX
                        </a>
                    </div>
                </div>

                <div class="sm-legend">
                    <span class="sm-legend-item"><span class="sm-legend-dot" style="background:#EAF3EA;border:1px solid #2C5A2C;"></span><span style="color:#2C5A2C;">P — Present</span></span>
                    <span class="sm-legend-item"><span class="sm-legend-dot" style="background:#FAF3DC;border:1px solid #A0850A;"></span><span style="color:#A0850A;">I — Incomplete</span></span>
                    <span class="sm-legend-item"><span class="sm-legend-dot" style="background:#F7E9E9;border:1px solid #A02A2A;"></span><span style="color:#A02A2A;">A — Absent</span></span>
                    <span class="sm-legend-item"><span class="sm-legend-dot" style="background:#f3f0ff;border:1px solid #c4b5fd;"></span><span style="color:#7c3aed;">O — Day Off</span></span>
                    <span class="sm-legend-item"><span class="sm-legend-dot" style="background:#eff6ff;border:1px solid #93c5fd;"></span><span style="color:#2563eb;">N — Not scheduled</span></span>
                    <span class="sm-legend-item"><i class="fas fa-play-circle sm-mark-start" style="font-size:10px;"></i><span style="color:#2C5A2C;">OJT Start</span></span>
                    <span class="sm-legend-item"><i class="fas fa-stop-circle sm-mark-end" style="font-size:10px;"></i><span style="color:#A02A2A;">OJT End</span></span>
                    <span class="sm-legend-item"><i class="fas fa-stop-circle sm-mark-end sm-mark-est" style="font-size:10px;"></i><span style="color:#A02A2A;">OJT End (est.)</span></span>
                    <span class="sm-legend-item"><span class="sm-legend-dot" style="background:#f8fafc;border:1px solid #e2e8f0;"></span><span style="color:#64748b;">Blank — Before first attendance / after OJT completion</span></span>
                </div>

                <div id="summaryTableWrapper">
                    <?php
                    $dates = [];
                    for ($d = strtotime($start); $d <= strtotime($end); $d = strtotime("+1 day", $d)) {
                        $dates[] = date("Y-m-d", $d);
                    }
                    if (empty($students)): ?>
                    <div class="sm-table-empty">No students assigned yet.</div>
                    <?php else: ?>
                    <div class="sm-table-wrap">
                    <table class="sm-table">
                        <thead>
                            <tr>
                                <th class="sm-name-col">Student</th>
                                <?php foreach ($dates as $d):
                                    $dow_d = (int)date('w', strtotime($d));
                                    $is_wkd = ($dow_d === 0 || $dow_d === 6);
                                    $th_cls = $is_wkd ? ' sm-wknd-col' : '';
                                ?>
                                <th class="<?= trim($th_cls) ?>" title="<?= $d ?>"><?= date("D d", strtotime($d)) ?></th>
                                <?php endforeach; ?>
                            </tr>
                        </thead>
                        <tbody>
                        <?php foreach ($students as $id => $s): ?>
                        <tr>
                            <?php $s_mn = trim($s['middle_name'] ?? '');
                            // UPDATED: this student's OJT start / end (indicator icons)
                            $s_mark  = $student_ojt_marks[(int)$id] ?? ['start' => null, 'end' => null, 'end_type' => 'last_log'];
                            $s_tip   = 'OJT Start: ' . (!empty($s_mark['start']) ? date('M j, Y', strtotime($s_mark['start'])) : '—')
                                     . ' · OJT End: ' . (!empty($s_mark['end']) ? date('M j, Y', strtotime($s_mark['end'])) . ($s_mark['end_type'] === 'estimated' ? ' (est.)' : '') : '—');
                            ?>
                            <td class="sm-name-td" title="<?= htmlspecialchars($s_tip) ?>"><?= htmlspecialchars(trim($s['first_name'] . ($s_mn ? ' ' . $s_mn : '') . ' ' . $s['last_name'])) ?></td>
                            <?php foreach ($dates as $d):
                                $dow_d  = (int)date('w', strtotime($d));
                                $is_wkd = ($dow_d === 0 || $dow_d === 6);
                                if ($is_wkd) {
                                    $val = 'O'; $cls = 'sm-off';
                                } elseif ($d > date("Y-m-d")) {
                                    // NEW (OJT ends at the required hours): nothing is shown for upcoming days after the OJT end date
                                    // NEW (student schedule): upcoming days the student is not scheduled on carry the marker too
                                    if (!attm_before_start($student_first_attendance, $id, $d) && !ojtend_is_after($student_ojt_end, $id, $d) && !attsch_is_scheduled($student_sched, $id, $d)) { $val = 'N'; $cls = 'sm-nosched'; }
                                    else { $val = ''; $cls = ''; }
                                } elseif (attm_before_start($student_first_attendance, $id, $d)) {
                                    $val = ''; $cls = 'sm-before-start'; // UPDATED: before first attendance — not absent / missed
                                } elseif (empty($logs_has_entry[$id][$d]) && ojtend_is_after($student_ojt_end, $id, $d)) {
                                    $val = ''; $cls = 'sm-before-start sm-ended'; // NEW (OJT ends at the required hours): OJT already completed
                                } elseif (empty($logs_has_entry[$id][$d]) && !attsch_is_scheduled($student_sched, $id, $d)) {
                                    $val = 'N'; $cls = 'sm-nosched'; // NEW (student schedule): not scheduled on this day → not absent
                                } elseif ($d === date("Y-m-d") && empty($logs_has_entry[$id][$d])) {
                                    $val = ''; $cls = ''; // today and no attendance entry yet: stay blank, it becomes Absent once the day has passed
                                } else {
                                    $raw = $logs[$id][$d] ?? 'ABSENT';
                                    $first = substr($raw, 0, 1);
                                    if ($first === 'P')      { $val = 'P'; $cls = 'sm-present'; }
                                    elseif ($first === 'A')  { $val = 'A'; $cls = 'sm-absent'; }
                                    elseif ($first === 'I')  { $val = 'I'; $cls = 'sm-incomplete'; }
                                    else                     { $val = 'O'; $cls = 'sm-off'; }
                                }
                            ?>
                            <?php
                                // UPDATED: start / end indicator icons
                                $mark_html = '';
                                if (!empty($s_mark['start']) && $d === $s_mark['start']) {
                                    $mark_html .= '<i class="fas fa-play-circle sm-mark sm-mark-start" title="OJT Start: ' . date('M j, Y', strtotime($d)) . '"></i>';
                                }
                                if (!empty($s_mark['end']) && $d === $s_mark['end']) {
                                    $is_est = ($s_mark['end_type'] === 'estimated');
                                    $mark_html .= '<i class="fas fa-stop-circle sm-mark sm-mark-end' . ($is_est ? ' sm-mark-est' : '') . '" title="OJT End' . ($is_est ? ' (estimated)' : '') . ': ' . date('M j, Y', strtotime($d)) . '"></i>';
                                }
                                $td_cls = trim($cls . ($mark_html !== '' ? ' sm-has-mark' : ''));
                                // NEW (student schedule): hint when the student is scheduled for only one duty that day
                                $td_title = '';
                                if (!$is_wkd && $val !== 'N') {
                                    $per_t = attsch_periods($student_sched, $id, $d);
                                    if ($per_t['am'] xor $per_t['pm']) $td_title = $per_t['am'] ? 'Scheduled: Day (AM duty) only' : 'Scheduled: Evening (PM duty) only';
                                }
                            ?>
                            <td class="<?= $td_cls ?>"<?= $td_title !== '' ? ' title="' . htmlspecialchars($td_title) . '"' : '' ?>><?= $mark_html ?><?= $val ?></td>
                            <?php endforeach; ?>
                        </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                    </div>
                    <?php endif; ?>
                </div>
                <!-- UPDATED: Monthly Attendance Summary pagination (10 students per page).
                     Kept outside #summaryTableWrapper so Print still prints every student. -->
                <div class="att-pagination" id="smPagination" style="display:none;">
                    <div class="att-pagination-info" id="smPaginationInfo"></div>
                    <div class="att-pagination-btns" id="smPaginationBtns"></div>
                </div>
                <script>
                (function(){
                    const SM_PAGE_SIZE = 10;
                    const SM_STORE_KEY = 'sm_summary_page_<?= htmlspecialchars($month, ENT_QUOTES) ?>';
                    const rows = Array.from(document.querySelectorAll('#summaryTableWrapper .sm-table tbody tr'));
                    const pag  = document.getElementById('smPagination');
                    const info = document.getElementById('smPaginationInfo');
                    const btns = document.getElementById('smPaginationBtns');
                    if (!rows.length || !pag) return;
                    const totalPages = Math.max(1, Math.ceil(rows.length / SM_PAGE_SIZE));
                    let page = 0;
                    try { page = parseInt(sessionStorage.getItem(SM_STORE_KEY) || '0', 10) || 0; } catch (e) {}
                    if (page >= totalPages) page = totalPages - 1;

                    function mkBtn(label, target, opts) {
                        const b = document.createElement('button');
                        b.type = 'button';
                        b.className = 'att-page-btn' + (opts && opts.active ? ' active' : '');
                        b.innerHTML = label;
                        if (opts && opts.title) b.title = opts.title;
                        if (opts && opts.disabled) b.disabled = true;
                        else if (!(opts && opts.active)) b.addEventListener('click', () => go(target));
                        return b;
                    }
                    function go(p) {
                        page = Math.max(0, Math.min(totalPages - 1, p));
                        try { sessionStorage.setItem(SM_STORE_KEY, String(page)); } catch (e) {}
                        render();
                    }
                    function render() {
                        const start = page * SM_PAGE_SIZE, end = Math.min(start + SM_PAGE_SIZE, rows.length);
                        rows.forEach((r, i) => r.classList.toggle('sm-row-hidden', i < start || i >= end));
                        if (totalPages <= 1) { pag.style.display = 'none'; return; }
                        pag.style.display = 'flex';
                        info.textContent = 'Showing ' + (start + 1) + '–' + end + ' of ' + rows.length + ' students';
                        btns.innerHTML = '';
                        btns.appendChild(mkBtn('<i class="fas fa-chevron-left"></i>', page - 1, { disabled: page === 0, title: 'Previous page' }));
                        let pages = [];
                        if (totalPages <= 7) { for (let i = 0; i < totalPages; i++) pages.push(i); }
                        else {
                            pages.push(0);
                            if (page > 2) pages.push('…');
                            for (let i = Math.max(1, page - 1); i <= Math.min(totalPages - 2, page + 1); i++) pages.push(i);
                            if (page < totalPages - 3) pages.push('…');
                            pages.push(totalPages - 1);
                        }
                        pages.forEach(p => {
                            if (p === '…') { const s = document.createElement('span'); s.className = 'att-page-ellipsis'; s.textContent = '…'; btns.appendChild(s); }
                            else btns.appendChild(mkBtn(String(p + 1), p, { active: p === page }));
                        });
                        btns.appendChild(mkBtn('<i class="fas fa-chevron-right"></i>', page + 1, { disabled: page === totalPages - 1, title: 'Next page' }));
                    }
                    render();
                })();
                </script>
            </div>
        </div>

    </div>

    <div class="split-right">

        <div class="panel-card" data-live-section="quick">
            <div class="panel-card-header">
                <h3><i class="fas fa-bolt" style="font-size:13px;"></i> Quick Actions</h3>
            </div>
            <div class="panel-card-body" style="padding:14px 16px;">
                <button class="quick-action-btn qa-btn-blue" onclick="openAttLogModal()">
                    <i class="fas fa-clipboard-list qa-icon"></i>
                    <span class="qa-label">Attendance Log</span>
                </button>
                <button class="quick-action-btn qa-btn-amber" onclick="openLateInbox()" id="lateInboxBtn" style="position:relative;">
                    <i class="fas fa-inbox qa-icon"></i>
                    <span class="qa-label">Late Requests</span>
                    <?php if ($pending_count > 0): ?>
                    <span class="qa-badge" id="inboxBadge"><?= $pending_count ?></span>
                    <?php else: ?>
                    <span class="qa-badge" id="inboxBadge" style="display:none;"><?= $pending_count ?></span>
                    <?php endif; ?>
                </button>
                <button class="quick-action-btn qa-btn-green" onclick="openAttSettings()">
                    <i class="fas fa-cog qa-icon"></i>
                    <span class="qa-label">Attendance Settings</span>
                </button>
            </div>
        </div>

        <div class="panel-card" data-live-section="today">
            <div class="panel-card-header">
                <h3><i class="fas fa-calendar-check" style="font-size:13px;"></i> Today's Stats</h3>
            </div>
            <div class="panel-card-body" style="padding:14px 16px;">
                <div style="font-size:11px; color:#9ca3af; margin-bottom:10px; font-weight:600;">
                    <?= date("l, F j") ?> &middot; <?= $today_stats['total'] ?> student<?= $today_stats['total'] !== 1 ? 's' : '' ?><?php if (!empty($today_stats['not_scheduled'])): ?> &middot; <?= (int)$today_stats['not_scheduled'] ?> not scheduled today<?php endif; ?><?php if (!empty($today_stats['completed'])): ?> &middot; <?= (int)$today_stats['completed'] ?> completed OJT<?php endif; ?>
                </div>
                <div class="kpi-card kpi-present">
                    <span class="kpi-icon"><i class="fas fa-check-circle" style="color:#15803d;font-size:20px;"></i></span>
                    <div class="kpi-info">
                        <div class="kpi-label">Present</div>
                        <div class="kpi-value"><?= $today_stats['present'] ?></div>
                    </div>
                </div>
                <div class="kpi-card kpi-absent">
                    <span class="kpi-icon"><i class="fas fa-times-circle" style="color:#dc2626;font-size:20px;"></i></span>
                    <div class="kpi-info">
                        <div class="kpi-label">Absent</div>
                        <div class="kpi-value"><?= $today_stats['absent'] ?></div>
                    </div>
                </div>
                <div class="kpi-card kpi-incomplete">
                    <span class="kpi-icon"><i class="fas fa-exclamation-circle" style="color:#d97706;font-size:20px;"></i></span>
                    <div class="kpi-info">
                        <div class="kpi-label">Incomplete</div>
                        <div class="kpi-value"><?= $today_stats['incomplete'] ?></div>
                    </div>
                </div>
                <?php if ($pending_count > 0): ?>
                <div style="margin-top:10px; background:#fef2f2; border:1px solid #fecaca; padding:9px 12px; font-size:12px; color:#c62828; font-weight:600; display:flex; align-items:center; gap:7px; cursor:pointer;" onclick="openLateInbox()">
                    <i class="fas fa-inbox" style="font-size:14px;"></i>
                    <span><?= $pending_count ?> pending late request<?= $pending_count !== 1 ? 's' : '' ?></span>
                </div>
                <?php endif; ?>
            </div>
        </div>

        <!-- UPDATED: the "OJT Progress" panel was removed. -->

    </div>

</div>

<div id="imageModal" class="modal" style="z-index:99990;">
    <span class="close" onclick="closeModal()">&times;</span>
    <img id="modalImg">
</div>

<!-- ══ ATTENDANCE LOG MODAL ══ -->
<div id="attLogModal">
    <div class="alm-box">
        <div class="alm-head">
            <h3>
                <i class="fas fa-clipboard-list"></i> Attendance Log
                <span id="attLogTitle" style="font-weight:400; font-size:16px; opacity:0.9;"></span>
            </h3>
            <button class="alm-close-btn" onclick="closeAttLogModal()">
                <i class="fas fa-times"></i> Close
            </button>
        </div>
        <div class="alm-body">
            <div class="alm-toolbar">
                <input type="text" id="searchInput" placeholder="Search student...">
                <div class="alm-group">
                    <label>Month:</label>
                    <select id="modalMonthFilter" onchange="onModalMonthChange()">
                        <?php
                        for ($m = strtotime($ojt_start_month); $m <= strtotime($month_max); $m = strtotime('+1 month', $m)) {
                            $month_val = date('Y-m', $m);
                            $month_name = date('F Y', $m);
                            echo "<option value='$month_val'>$month_name</option>";
                        }
                        ?>
                    </select>
                </div>
                <div class="alm-group tight">
                    <button id="modalPrevDay" class="alm-nav-btn" onclick="modalShiftDay(-1)">&#8592;</button>
                    <select id="modalDayFilter" onchange="modalGoToDay()"></select>
                    <button id="modalNextDay" class="alm-nav-btn" onclick="modalShiftDay(1)">&#8594;</button>
                </div>
            </div>

            <div id="attLogSpinner" class="alm-spinner">
                <i></i>
                Loading attendance…
            </div>

            <div class="table-box" id="attLogTableBox">
                <h2 id="attLogDateHeading"></h2>
                <div id="attLogWeekendNotice" style="display:none;" class="weekend-notice">
                    <i class="fas fa-umbrella-beach"></i>
                    <strong id="attLogWeekendDay"></strong> — Weekend. All students are <strong>Day Off</strong>.
                </div>
                <div style="overflow-x:auto;">
                    <table id="attendanceTable" style="width:100%; border-collapse:collapse; font-size:13px;">
                        <thead id="attLogThead"></thead>
                        <tbody id="attLogTbody"></tbody>
                    </table>
                </div>
                <div class="att-pagination" id="attPagination" style="display:none;">
                    <div class="att-pagination-info" id="attPaginationInfo"></div>
                    <div class="att-pagination-btns" id="attPaginationBtns"></div>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- ══ ATTENDANCE SETTINGS POPUP ══ -->
<div id="attSettingsOverlay">
    <div id="attSettingsBox">
        <div class="as-header">
            <div class="as-header-left">
                <span class="as-header-icon"><i class="fas fa-cog" style="font-size:22px;"></i></span>
                <div>
                    <h3>Attendance Settings</h3>
                    <p>Configure time windows for today's attendance</p>
                </div>
            </div>
            <button class="as-close-btn" onclick="closeAttSettings()"><i class="fas fa-times"></i> Close</button>
        </div>
        <div class="as-body">
            <?php if ($isToday): ?>
            <p style="font-size:13px;color:#555;margin:0 0 16px;">Configure the attendance time windows for today. The wizard will guide you through AM duty, PM duty, and auto-scheduling.</p>
            <button id="openWizardBtn" class="as-open-wizard" onclick="openWizardFromSettings()">
                <i class="fas fa-magic"></i> Setup Attendance Schedule
            </button>
            <?php if ($current_settings): ?>
            <div class="as-current-settings" style="margin-top:16px;">
                <strong class="as-title">Current Settings — <?= htmlspecialchars($date) ?></strong>
                <strong>AM In:</strong> <?= fmt12($current_settings['am_time_in_start'] ?? '') ?> – <?= fmt12($current_settings['am_time_in_end'] ?? '') ?>
                &nbsp;|&nbsp; <strong>AM Out:</strong> <?= fmt12($current_settings['am_time_out_start'] ?? '') ?> – <?= fmt12($current_settings['am_time_out_end'] ?? '') ?><br>
                <strong>PM In:</strong> <?= fmt12($current_settings['pm_time_in_start'] ?? '') ?> – <?= fmt12($current_settings['pm_time_in_end'] ?? '') ?>
                &nbsp;|&nbsp; <strong>PM Out:</strong> <?= fmt12($current_settings['pm_time_out_start'] ?? '') ?> – <?= fmt12($current_settings['pm_time_out_end'] ?? '') ?>
            </div>
            <?php endif; ?>
            <?php else: ?>
            <div class="as-today-only-notice"><i class="fas fa-exclamation-triangle"></i> Attendance settings can only be configured for today's date.</div>
            <p style="font-size:13px;color:#888;margin:0 0 14px;">Navigate to today's date to configure the attendance schedule.</p>
            <?php
            // NEW: one click to today (the same page without the date in the address — month and the rest are kept)
            $go_today_params = $_GET; unset($go_today_params['date']);
            $go_today_url = strtok($_SERVER['REQUEST_URI'], '?') . ($go_today_params ? '?' . http_build_query($go_today_params) : '');
            ?>
            <a class="as-open-wizard" href="<?= htmlspecialchars($go_today_url) ?>" style="text-decoration:none;"><i class="fas fa-calendar-day"></i> Go to today (<?= date('M j, Y') ?>)</a>
            <?php endif; ?>
        </div>
    </div>
</div>

<!-- ══ MISSING DUTY TIMES FORM (required) ══ -->
<div id="dutyGapOverlay" role="dialog" aria-modal="true" aria-labelledby="dgTitle">
    <div id="dutyGapBox">
        <div class="dg-head">
            <h3><i class="fas fa-calendar-plus"></i><span id="dgTitle">Duty Times Required</span></h3>
            <span class="dg-badge" id="dgSub"></span>
        </div>
        <p class="dg-msg" id="dgMessage"></p>
        <ul class="sb-list" id="dgList"></ul>
        <div class="dg-grid">
            <div class="dg-group">
                <label>Sign-In Opens <span class="required">*</span></label>
                <input type="time" id="dg_ti_s">
                <div class="help-text" id="dg_hint_in"></div>
            </div>
            <div class="dg-group">
                <label>Sign-In Closes <span class="required">*</span></label>
                <input type="time" id="dg_ti_e">
                <div class="help-text" id="dg_hint_in2"></div>
            </div>
            <div class="dg-group">
                <label>Sign-Out Opens <span class="required">*</span></label>
                <input type="time" id="dg_to_s">
                <div class="help-text"><i class="fas fa-info-circle"></i> Duty is counted up to this time (max 4 hours)</div>
            </div>
            <div class="dg-group">
                <label>Sign-Out Closes <span class="required">*</span> <small style="font-weight:400;color:#8A93A6;">(grace only)</small></label>
                <input type="time" id="dg_to_e">
                <div class="help-text"><i class="fas fa-info-circle"></i> Grace period end</div>
            </div>
        </div>
        <div class="dg-error" id="dgError"></div>
        <p class="dg-note" id="dgTip"><i class="fas fa-envelope"></i> When you save, the schedule is applied to today and all remaining OJT weekdays, and an email about the updated attendance schedule is sent automatically to the administrators and your OJT trainees.</p>
        <div class="dg-actions">
            <button class="dg-submit" id="dgSaveBtn" type="button"><i class="fas fa-save"></i> Save &amp; Notify</button>
        </div>
    </div>
</div>

<!-- ══ CANNOT SKIP AM / PM DUTY POPUP ══ -->
<div id="skipBlockOverlay" role="dialog" aria-modal="true" aria-labelledby="sbTitle">
    <div id="skipBlockBox">
        <div class="hp-header sb-header">
            <span class="hp-icon sb-icon"><i class="fas fa-user-clock" id="sbIcon"></i></span>
            <div class="sb-head-text">
                <h3 id="sbTitle">Cannot Skip Duty</h3>
                <p class="sb-sub" id="sbSub"></p>
            </div>
        </div>
        <div class="hp-body">
            <p id="sbMessage"></p>
            <ul class="sb-list" id="sbList"></ul>
            <p class="sb-tip" id="sbTip"></p>
        </div>
        <div class="hp-footer">
            <button class="hp-btn-ok sb-retry" id="sbRetryBtn" type="button">Try Again</button>
            <button class="hp-btn-continue" id="sbOkBtn" type="button" style="margin-left:auto;">Got it</button>
        </div>
    </div>
</div>

<!-- ══ 8-HOUR VALIDATION POPUP ══ -->
<div id="hoursPopupOverlay">
    <div id="hoursPopupBox">
        <div class="hp-header">
            <h3 id="hpTitle">Schedule Must Total 8 Hours</h3>
        </div>
        <div class="hp-body">
            <p id="hpMessage">The combined AM and PM duty time must equal exactly <strong>8 hours</strong> per day. Please adjust the time windows.</p>
            <div class="hp-breakdown">
                <div class="hpb-row"><span class="hpb-label">AM Duty (Sign-In Start → Sign-Out Start)</span><span class="hpb-val" id="hp_am_hrs">—</span></div>
                <div class="hpb-row"><span class="hpb-label">PM Duty (Sign-In Start → Sign-Out Start)</span><span class="hpb-val" id="hp_pm_hrs">—</span></div>
                <div class="hpb-row total"><span class="hpb-label"><strong>Total</strong></span><span class="hpb-val" id="hp_total_hrs">—</span></div>
            </div>
            <p style="font-size:12px;color:#888;margin:0;"><strong>Note:</strong> Duty hours are counted from <em>Sign-In Opens</em> to <em>Sign-Out Opens</em>. Aim for 4h AM + 4h PM, or another split totalling 8 hours.</p>
        </div>
        <div class="hp-footer">
            <button class="hp-btn-ok" onclick="closeHoursPopup()">← Adjust Times</button>
            <button class="hp-btn-continue" id="hpContinueBtn" onclick="continueAnywayConfirm()">Continue Anyway →</button>
        </div>
    </div>
</div>

<!-- ══ WIZARD MODAL ══ -->
<div id="wizardOverlay">
  <div id="wizardBox">
    <div id="wizardProgress"><div id="wizardProgressBar" style="width:33%"></div></div>

    <!-- STEP 1: AM Times -->
    <div class="wiz-step" id="wizStep1">
      <div class="wiz-header">
        <div class="wiz-step-label">Step 1 of 3</div>
        <h2>AM Duty Times</h2>
        <p>Set the morning shift sign-in and sign-out windows. Sign-In Opens/Closes must be <strong>before noon (12:00 PM)</strong>. Sign-Out can be set to any time (AM or PM pick-up).</p>
      </div>
      <div class="wiz-body">
        <div class="skip-row">
          <label class="skip-checkbox">
            <input type="checkbox" id="skipAmCheckbox" onchange="guardSkipDuty('am')">
            <span><i class="fas fa-ban" style="color:#e65100;"></i> Skip AM Duty (No morning attendance required)</span>
          </label>
        </div>
        <div id="amFieldsContainer">
          <div class="field-row">
            <div class="field-group">
              <label>Sign-In Opens</label>
              <input type="time" id="w_am_ti_s" onchange="validateAmInField(this); checkAmDutyLimit()">
              <div class="field-hint am-hint">Must be before 12:00 PM</div>
            </div>
            <div class="field-group">
              <label>Sign-In Closes</label>
              <input type="time" id="w_am_ti_e" onchange="validateAmInField(this)">
              <div class="field-hint am-hint">Must be before 12:00 PM</div>
            </div>
          </div>
          <div class="field-row">
            <div class="field-group">
              <label>Sign-Out Opens</label>
              <input type="time" id="w_am_to_s" onchange="clearAmError(); checkAmDutyLimit()">
              <div class="field-hint" style="color:#888;">Any time (AM or PM)</div>
            </div>
            <div class="field-group">
              <label>Sign-Out Closes <small style="font-weight:400;color:#aaa;">(grace only)</small></label>
              <input type="time" id="w_am_to_e" onchange="clearAmError()">
              <div class="field-hint" style="color:#888;">Any time — grace period end</div>
            </div>
          </div>
        </div>
        <div id="amErrorMsg" style="display:none;background:#fef2f2;border:1px solid #fecaca;border-radius:8px;padding:8px 12px;font-size:12px;color:#c62828;margin-top:4px;"></div>
      </div>
      <div class="wiz-footer">
        <div class="wiz-dots"><div class="wiz-dot active"></div><div class="wiz-dot"></div><div class="wiz-dot"></div></div>
        <div style="display:flex;gap:8px">
          <button class="wiz-btn wiz-btn-back" onclick="closeWizard()"><i class="fas fa-times"></i> Cancel</button>
          <button class="wiz-btn wiz-btn-next" onclick="wizNext(1)">Next <i class="fas fa-arrow-right"></i></button>
        </div>
      </div>
    </div>

    <!-- STEP 2: PM Times -->
    <div class="wiz-step" id="wizStep2" style="display:none">
      <div class="wiz-header">
        <div class="wiz-step-label">Step 2 of 3</div>
        <h2>PM Duty Times</h2>
        <p>Set the afternoon shift windows. All PM fields must be <strong>12:00 PM or later</strong>.</p>
      </div>
      <div class="wiz-body">
        <div class="skip-row">
          <label class="skip-checkbox">
            <input type="checkbox" id="skipPmCheckbox" onchange="guardSkipDuty('pm')">
            <span><i class="fas fa-ban" style="color:#1565c0;"></i> Skip PM Duty (No afternoon attendance required)</span>
          </label>
        </div>
        <div id="pmFieldsContainer">
          <div class="field-row">
            <div class="field-group"><label>Sign-In Opens</label><input type="time" id="w_pm_ti_s" min="12:00" max="23:59" onchange="validatePmField(this)"><div class="field-hint pm-hint">Must be 12:00 PM or later</div></div>
            <div class="field-group"><label>Sign-In Closes</label><input type="time" id="w_pm_ti_e" min="12:00" max="23:59" onchange="validatePmField(this)"><div class="field-hint pm-hint">Must be 12:00 PM or later</div></div>
          </div>
          <div class="field-row">
            <div class="field-group"><label>Sign-Out Opens</label><input type="time" id="w_pm_to_s" min="12:00" max="23:59" onchange="validatePmField(this)"><div class="field-hint pm-hint">Must be 12:00 PM or later</div></div>
            <div class="field-group"><label>Sign-Out Closes <small style="font-weight:400;color:#aaa;">(grace only)</small></label><input type="time" id="w_pm_to_e" min="12:00" max="23:59" onchange="validatePmField(this)"><div class="field-hint pm-hint">Must be 12:00 PM or later — grace period</div></div>
          </div>
        </div>
        <div id="pmErrorMsg" style="display:none;background:#fef2f2;border:1px solid #fecaca;border-radius:8px;padding:8px 12px;font-size:12px;color:#c62828;margin-top:4px;"></div>
      </div>
      <div class="wiz-footer">
        <div class="wiz-dots"><div class="wiz-dot"></div><div class="wiz-dot active"></div><div class="wiz-dot"></div></div>
        <div style="display:flex;gap:8px">
          <button class="wiz-btn wiz-btn-back" onclick="wizBack(2)"><i class="fas fa-arrow-left"></i> Back</button>
          <button class="wiz-btn wiz-btn-next" onclick="wizNext(2)">Next <i class="fas fa-arrow-right"></i></button>
        </div>
      </div>
    </div>

    <!-- STEP 3: Confirm & Save
         UPDATED: laid out like the manual "Add New Student" form of admin_student_list.php — compact title bar with an icon,
         labelled form fields in a grid (label above, bordered box, small hint underneath) and a bordered action bar at the bottom.
         The same element ids are kept, so the wizard script (sum_* values, sumScope, bothSkippedWarning, wizSaveBtn) is untouched. -->
    <div class="wiz-step" id="wizStep3" style="display:none">
      <div class="wiz-header s3-header">
        <div class="s3-title-row">
          <div>
            <div class="wiz-step-label">Step 3 of 3</div>
            <h2><i class="fas fa-clipboard-check"></i> Confirm &amp; Save</h2>
          </div>
          <button type="button" class="s3-close" onclick="closeWizard()" aria-label="Close" title="Close">&times;</button>
        </div>
      </div>
      <div class="wiz-body s3-body">
        <p class="s3-intro">Review the schedule below. It will be applied to <strong>today and all future OJT weekdays</strong>. Past days will <strong>not</strong> be affected. Students &amp; admins will be notified by email.</p>
        <div id="bothSkippedWarning" style="display:none;" class="skip-warning">
          <i class="fas fa-exclamation-triangle"></i> You are trying to skip both AM and PM duty times. At least one duty period must be configured.
        </div>
        <div class="sg-scope scope-all" id="sumScope">
            <i class="fas fa-calendar-alt"></i>
            <?php if ($ojt_horizon !== null && $ojt_horizon > date('Y-m-d')): ?>
            Applies to today + <?= $remaining_ojt_weekdays ?> remaining OJT weekday(s) through <?= date("F j, Y", strtotime($ojt_horizon)) ?> (estimated &mdash; each student's OJT ends once they reach the required hours of their course). Past days are unchanged.
            <?php else: ?>
            Applies to today and every following OJT weekday until each student's OJT ends (once they reach the required hours of their course). Past days are unchanged.
            <?php endif; ?>
        </div>
        <div class="s3-grid">
          <div class="form-group">
            <label>AM Sign-In</label>
            <div class="s3-field" id="sum_am_in">—</div>
            <div class="help-text"><i class="fas fa-info-circle"></i> Sign-In opens &ndash; closes</div>
          </div>
          <div class="form-group">
            <label>AM Sign-Out</label>
            <div class="s3-field" id="sum_am_out">—</div>
            <div class="help-text"><i class="fas fa-info-circle"></i> Sign-Out opens &ndash; closes (grace)</div>
          </div>
          <div class="form-group">
            <label>Total Duty Hours</label>
            <div class="s3-field s3-total"><i class="fas fa-clock"></i> <span id="sum_total_hrs">—</span></div>
            <div class="help-text"><i class="fas fa-info-circle"></i> Duty time is measured from <strong>Sign-In Opens &rarr; Sign-Out Opens</strong>. Sign-Out Closes is a grace window and is not counted.</div>
          </div>
          <div class="form-group">
            <label>PM Sign-In</label>
            <div class="s3-field" id="sum_pm_in">—</div>
            <div class="help-text"><i class="fas fa-info-circle"></i> Sign-In opens &ndash; closes</div>
          </div>
          <div class="form-group">
            <label>PM Sign-Out</label>
            <div class="s3-field" id="sum_pm_out">—</div>
            <div class="help-text"><i class="fas fa-info-circle"></i> Sign-Out opens &ndash; closes (grace)</div>
          </div>
          <div class="form-group">
            <label>Notification</label>
            <div class="s3-field s3-notify-field"><i class="fas fa-envelope"></i> Email notification</div>
            <div class="help-text"><i class="fas fa-info-circle"></i> All registered students <strong>and system admins</strong> will be notified by email.</div>
          </div>
        </div>
      </div>
      <div class="wiz-footer s3-footer">
        <div class="wiz-dots"><div class="wiz-dot"></div><div class="wiz-dot"></div><div class="wiz-dot active"></div></div>
        <div style="display:flex;gap:8px">
          <button class="wiz-btn wiz-btn-back" onclick="wizBack(3)"><i class="fas fa-arrow-left"></i> Back</button>
          <button class="wiz-btn wiz-btn-save" id="wizSaveBtn" onclick="wizSave()"><i class="fas fa-save"></i> Save &amp; Notify</button>
        </div>
      </div>
    </div>
  </div>
</div>

<!-- EMAIL DEBUG PANEL -->
<div id="emailDebugPanel">
  <div class="edbg-titlebar">
    <h4><i class="fas fa-envelope-open-text"></i> Email Delivery Failures — Diagnostics</h4>
    <div class="edbg-controls">
      <button onclick="copyEmailDebug()"><i class="fas fa-copy"></i> Copy</button>
      <button onclick="closeEmailDebug()"><i class="fas fa-times"></i> Close</button>
    </div>
  </div>
  <div class="edbg-body" id="emailDebugBody"></div>
</div>

<!-- ══ LATE REQUEST INBOX MODAL ══ -->
<div id="lateInboxOverlay">
    <div id="lateInboxBox">
        <div class="li-header">
            <h3><i class="fas fa-inbox"></i> Late Attendance Requests</h3>
            <button class="li-close-btn" onclick="closeLateInbox()"><i class="fas fa-times"></i> Close</button>
        </div>
        <div class="li-tabs">
            <button class="li-tab active" data-tab="pending" onclick="switchTab('pending')">Pending <span class="li-tab-count" id="liCountPending">0</span></button>
            <button class="li-tab" data-tab="history" onclick="switchTab('history')">Request History <span class="li-tab-count" id="liCountHistory">0</span></button>
        </div>
        <div class="li-body" id="li-body">
            <div class="li-loading"><div class="li-spinner"></div><span>Loading requests…</span></div>
        </div>
    </div>
</div>

<!-- ══ CUSTOM CONFIRM DIALOG ══ -->
<div id="customConfirmOverlay">
    <div id="customConfirmBox">
        <div class="cc-header">
            <div class="cc-icon-wrap approve" id="ccIconWrap"></div>
            <div class="cc-text-wrap">
                <div class="cc-title" id="ccTitle">Confirm Action</div>
                <p class="cc-message" id="ccMessage"></p>
            </div>
        </div>
        <div class="cc-student-badge" id="ccStudentBadge" style="display:none;"><i class="fas fa-user"></i><span id="ccStudentName"></span></div>
        <div class="cc-divider"></div>
        <div class="cc-footer">
            <button class="cc-btn cc-btn-cancel" onclick="closeCustomConfirm()">Cancel</button>
            <button class="cc-btn cc-btn-confirm-approve" id="ccConfirmBtn">Confirm</button>
        </div>
    </div>
</div>

<!-- ══ PHOTO LIGHTBOX ══ -->
<div id="reqPhotoLightbox" onclick="this.classList.remove('open')">
    <img id="reqPhotoImg" src="" alt="Student photo">
    <div class="lb-caption" id="reqPhotoCaption">Click anywhere to close</div>
</div>

<script>
/* ── SIDEBAR TOGGLE ── */
const sb = document.getElementById('sidebar');
document.getElementById('toggleBtn').addEventListener('click', () => {
    sb.classList.toggle('collapsed');
});

/* ── LIVE DATE/TIME WIDGET ── */
(function() {
    const timeEl  = document.getElementById('dtwTimeValue');
    const ampmEl  = document.getElementById('dtwAmPm');
    const dateEl  = document.getElementById('dtwDateValue');
    const days    = ['Sun','Mon','Tue','Wed','Thu','Fri','Sat'];
    const months  = ['Jan','Feb','Mar','Apr','May','Jun','Jul','Aug','Sep','Oct','Nov','Dec'];

    function tickDateTime() {
        const now  = new Date();
        const h24  = now.getHours();
        const min  = now.getMinutes();
        const sec  = now.getSeconds();
        const ampm = h24 >= 12 ? 'PM' : 'AM';
        const h12  = h24 % 12 || 12;
        const minS = String(min).padStart(2,'0');
        const secS = String(sec).padStart(2,'0');

        if (timeEl) {
            timeEl.childNodes[0].textContent = h12 + ':' + minS + ':' + secS + ' ';
        }
        if (ampmEl) ampmEl.textContent = ampm;

        if (dateEl) {
            const dayName = days[now.getDay()];
            const monName = months[now.getMonth()];
            const dayNum  = now.getDate();
            const year    = now.getFullYear();
            dateEl.textContent = dayName + ', ' + monName + ' ' + dayNum + ', ' + year;
        }
    }

    tickDateTime();
    setInterval(tickDateTime, 1000);
})();

/* ── Skip AM/PM Field Toggle Functions ── */
function toggleAmFields() {
    const skipAm = document.getElementById('skipAmCheckbox').checked;
    const container = document.getElementById('amFieldsContainer');
    const inputs = container.querySelectorAll('input');
    if (skipAm) {
        container.style.opacity = '0.5';
        inputs.forEach(input => {
            input.disabled = true;
            input.classList.remove('input-error');
        });
        clearAmError();
    } else {
        container.style.opacity = '1';
        inputs.forEach(input => { input.disabled = false; });
    }
}

function togglePmFields() {
    const skipPm = document.getElementById('skipPmCheckbox').checked;
    const container = document.getElementById('pmFieldsContainer');
    const inputs = container.querySelectorAll('input');
    if (skipPm) {
        container.style.opacity = '0.5';
        inputs.forEach(input => {
            input.disabled = true;
            input.classList.remove('input-error');
        });
        clearPmError();
    } else {
        container.style.opacity = '1';
        inputs.forEach(input => { input.disabled = false; });
    }
}

/* ── NEW (missing duty time): required form when trainees have a duty the current setting does not cover ── */
const _dutyGap = <?= json_encode(attm_duty_gap($conn, $company_id), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_PARTIAL_OUTPUT_ON_ERROR) ?>;
let _dutyGapPeriod = null;
function dgShowError(msg) { const el = document.getElementById('dgError'); el.textContent = msg; el.style.display = msg ? 'block' : 'none'; }
function openDutyGap(gap) {
    try {
        if (!gap || !gap.ok || !gap.missing || !gap.missing.length || !gap.setting) return;
        const per = gap.missing[0]; // 'am' | 'pm'
        const L = per === 'am' ? 'AM' : 'PM';
        const list = gap[per] || [];
        _dutyGapPeriod = per;
        const n = list.length;
        document.getElementById('dgTitle').textContent = L + ' Duty Times Required';
        document.getElementById('dgSub').textContent = n + ' OJT trainee' + (n > 1 ? 's' : '') + ' scheduled for ' + L + ' duty';
        document.getElementById('dgMessage').textContent = 'Your current attendance setting has no ' + L + ' duty times, but the OJT trainee' + (n > 1 ? 's' : '') + ' below ' + (n > 1 ? 'are' : 'is') + ' scheduled for ' + L + ' duty. Please set the ' + L + ' duty times to continue.';
        const ul = document.getElementById('dgList'); ul.innerHTML = '';
        list.slice(0, 50).forEach(it => {
            const li = document.createElement('li');
            const a = document.createElement('span'); a.className = 'sb-name'; a.textContent = it.name;
            const b = document.createElement('span'); b.className = 'sb-days'; b.textContent = it.days || '';
            li.appendChild(a); li.appendChild(b); ul.appendChild(li);
        });
        ul.style.display = n ? '' : 'none';
        const hint = per === 'am' ? 'Must be before 12:00 PM' : 'Must be 12:00 PM or later';
        document.getElementById('dg_hint_in').textContent = hint;
        document.getElementById('dg_hint_in2').textContent = hint;
        ['dg_ti_s','dg_ti_e','dg_to_s','dg_to_e'].forEach(id => { const el = document.getElementById(id); el.value = ''; el.classList.remove('input-error'); });
        dgShowError('');
        document.getElementById('dutyGapOverlay').classList.add('open');
    } catch (e) { console.warn('Duty gap form could not be opened.', e); }
}
function dgSave() {
    const per = _dutyGapPeriod, st = _dutyGap && _dutyGap.setting;
    if (!per || !st) return;
    const g = id => document.getElementById(id).value;
    const ids = ['dg_ti_s','dg_ti_e','dg_to_s','dg_to_e'];
    ids.forEach(id => document.getElementById(id).classList.remove('input-error'));
    if (ids.some(id => !g(id))) { ids.forEach(id => { if (!g(id)) document.getElementById(id).classList.add('input-error'); }); dgShowError('Please fill in all four ' + per.toUpperCase() + ' time fields.'); return; }
    const t = timeToMins;
    if (per === 'am' && (t(g('dg_ti_s')) >= 720 || t(g('dg_ti_e')) >= 720)) { ['dg_ti_s','dg_ti_e'].forEach(id => document.getElementById(id).classList.add('input-error')); dgShowError('AM Sign-In times must be before 12:00 PM.'); return; }
    if (per === 'pm' && ids.some(id => t(g(id)) < 720)) { ids.forEach(id => { if (t(g(id)) < 720) document.getElementById(id).classList.add('input-error'); }); dgShowError('All PM times must be 12:00 PM or later.'); return; }
    let mins = t(g('dg_to_s')) - t(g('dg_ti_s'));
    if (per === 'pm' && mins < 0) mins += 1440; // PM sign-out earlier than sign-in = next day
    if (mins <= 0) { document.getElementById('dg_to_s').classList.add('input-error'); dgShowError('Sign-Out Opens must be after Sign-In Opens.'); return; }
    if (mins > MAX_PERIOD_MINS) { document.getElementById('dg_to_s').classList.add('input-error'); dgShowError('The ' + per.toUpperCase() + ' duty is ' + Math.floor(mins / 60) + 'h' + (mins % 60 ? ' ' + (mins % 60) + 'm' : '') + ' — the maximum is 4 hours (AM 4h + PM 4h = 8 hours a day). Please shorten the Sign-In Opens → Sign-Out Opens window.'); return; }
    dgShowError('');

    const fd = new FormData();
    fd.append('date', '<?= date('Y-m-d') ?>');
    fd.append('skip_am', '0'); fd.append('skip_pm', '0');
    const cut = v => v ? String(v).substring(0, 5) : '';
    const keep = (name, v) => fd.append(name, cut(v));
    if (per === 'am') {
        fd.append('am_time_in_start', g('dg_ti_s')); fd.append('am_time_in_end', g('dg_ti_e'));
        fd.append('am_time_out_start', g('dg_to_s')); fd.append('am_time_out_end', g('dg_to_e'));
        keep('pm_time_in_start', st.pm_time_in_start); keep('pm_time_in_end', st.pm_time_in_end);
        keep('pm_time_out_start', st.pm_time_out_start); keep('pm_time_out_end', st.pm_time_out_end);
    } else {
        keep('am_time_in_start', st.am_time_in_start); keep('am_time_in_end', st.am_time_in_end);
        keep('am_time_out_start', st.am_time_out_start); keep('am_time_out_end', st.am_time_out_end);
        fd.append('pm_time_in_start', g('dg_ti_s')); fd.append('pm_time_in_end', g('dg_ti_e'));
        fd.append('pm_time_out_start', g('dg_to_s')); fd.append('pm_time_out_end', g('dg_to_e'));
    }
    fd.append('is_auto', '1'); fd.append('auto_type', 'all_remaining');

    const btn = document.getElementById('dgSaveBtn');
    btn.disabled = true;
    showActionLoading('Saving & notifying');
    let done = false;
    const fin = () => { if (done) return; done = true; btn.disabled = false; hideActionLoading(); };
    fetch(window.location.pathname, { method: 'POST', headers: { 'X-Requested-With': 'XMLHttpRequest' }, body: fd, credentials: 'same-origin', cache: 'no-store' })
    .then(r => r.text())
    .then(raw => {
        fin();
        let data = null; try { data = JSON.parse(raw); } catch (e) { data = null; }
        if (!data) { dgShowError('The server sent an unexpected reply, so it is not certain the schedule was saved. Please reload the page and check the Attendance Settings.'); return; }
        if (data.success) {
            document.getElementById('dutyGapOverlay').classList.remove('open');
            _dutyGapPeriod = null;
            if (data.email_errors && data.email_errors.length > 0) setTimeout(() => renderEmailDebug(data.email_errors), 600);
            showGlobalResult('success', 'Attendance Schedule Updated', 'The ' + per.toUpperCase() + ' duty times were saved and the administrators and OJT trainees are being notified by email.', 5000);
            liveRefreshNow(true);
        } else {
            dgShowError(data.message || 'The attendance schedule could not be saved. Please try again.');
        }
    })
    .catch(() => { fin(); dgShowError('Could not reach the server. Please check your connection and try again.'); });
}
document.getElementById('dgSaveBtn').addEventListener('click', dgSave);
openDutyGap(_dutyGap); // required: shown on every visit until the missing duty times are saved

/* ── NEW: skipping AM / PM duty is only allowed when no OJT trainee is scheduled for it ── */
let _skipBlockRetry = null;
function closeSkipBlock() { document.getElementById('skipBlockOverlay').classList.remove('open'); _skipBlockRetry = null; }
function showSkipBlock(opts) {
    // opts: {title, sub, message, items:[{name,days}], total, tip, icon, retry}
    const $ = id => document.getElementById(id);
    $('sbTitle').textContent   = opts.title;
    $('sbSub').textContent     = opts.sub || '';
    $('sbSub').style.display   = opts.sub ? '' : 'none';
    $('sbMessage').textContent = opts.message;
    $('sbIcon').className      = 'fas ' + (opts.icon || 'fa-user-clock');
    const list = $('sbList');
    list.innerHTML = '';
    const items = opts.items || [];
    items.slice(0, 50).forEach(it => {
        const li = document.createElement('li');
        const n = document.createElement('span'); n.className = 'sb-name'; n.textContent = it.name;
        const d = document.createElement('span'); d.className = 'sb-days'; d.textContent = it.days || '';
        li.appendChild(n); li.appendChild(d); list.appendChild(li);
    });
    if (items.length > 50) {
        const li = document.createElement('li'); li.className = 'sb-more';
        li.textContent = '…and ' + (items.length - 50) + ' more trainee(s)';
        list.appendChild(li);
    }
    list.style.display = items.length ? '' : 'none';
    $('sbTip').textContent = opts.tip || '';
    $('sbTip').style.display = opts.tip ? '' : 'none';
    const retry = $('sbRetryBtn');
    _skipBlockRetry = opts.retry || null;
    retry.style.display = opts.retry ? 'inline-block' : 'none';
    $('skipBlockOverlay').classList.add('open');
    try { $('sbOkBtn').focus(); } catch (e) {}
}
(function () {
    const ov = document.getElementById('skipBlockOverlay');
    document.getElementById('sbOkBtn').addEventListener('click', closeSkipBlock);
    document.getElementById('sbRetryBtn').addEventListener('click', function () { const fn = _skipBlockRetry; closeSkipBlock(); if (fn) fn(); });
    ov.addEventListener('click', function (e) { if (e.target === this) closeSkipBlock(); });
    document.addEventListener('keydown', function (e) { if (e.key === 'Escape' && ov.classList.contains('open')) closeSkipBlock(); });
})();

function guardSkipDuty(which) {
    const cb = document.getElementById(which === 'am' ? 'skipAmCheckbox' : 'skipPmCheckbox');
    const apply = () => (which === 'am' ? toggleAmFields() : togglePmFields());
    if (!cb.checked) { apply(); return; }          // un-skipping needs no check

    const label = which === 'am' ? 'AM' : 'PM';
    const period = which === 'am' ? 'morning (Day schedule)' : 'afternoon/evening (Evening schedule)';
    const fd = new FormData();
    fd.append('action', 'check_skip_conflicts');
    fd.append('skip_am', which === 'am' ? '1' : '0');
    fd.append('skip_pm', which === 'pm' ? '1' : '0');
    cb.disabled = true;
    showActionLoading('Checking OJT trainee schedules');
    const done = () => { cb.disabled = false; hideActionLoading(); };
    const fail = (title, msg, sub) => {
        cb.checked = false; apply();
        showSkipBlock({ title: title, sub: sub, message: msg, icon: 'fa-exclamation-triangle',
            tip: 'Your attendance settings were not changed. You can try again in a moment.',
            retry: () => { cb.checked = true; guardSkipDuty(which); } });
    };

    fetch(window.location.pathname, { method: 'POST', headers: { 'X-Requested-With': 'XMLHttpRequest' }, body: fd, credentials: 'same-origin', cache: 'no-store' })
    .then(r => r.text())
    .then(raw => {
        done();
        let data = null;
        try { data = JSON.parse(raw); } catch (e) { data = null; }
        if (!data || !data.ok) {
            fail('Schedule Check Failed', (data && data.error) || 'We could not verify the OJT trainees\' schedules, so the skip was cancelled to keep their schedules safe.', 'Unable to verify');
            return;
        }
        const list = (which === 'am' ? data.am : data.pm) || [];
        if (list.length) {
            cb.checked = false; apply();
            const n = list.length;
            showSkipBlock({
                title: 'Cannot Skip ' + label + ' Duty',
                sub: n + ' OJT trainee' + (n > 1 ? 's' : '') + ' scheduled for ' + label + ' duty',
                message: 'The ' + label + ' duty cannot be skipped because it would affect the schedule of the OJT trainee' + (n > 1 ? 's' : '') + ' listed below, who ' + (n > 1 ? 'are' : 'is') + ' registered to your company and required to report in the ' + period + '.',
                items: list,
                tip: 'Tip: keep the ' + label + ' duty and adjust its time windows instead. To skip it, the trainee\'s schedule must first be changed so that nobody is assigned to ' + label + ' duty.',
                icon: 'fa-user-clock'
            });
            return;
        }
        apply();
    })
    .catch(() => {
        done();
        fail('Connection Problem', 'We could not reach the server to verify the OJT trainees\' schedules, so the skip was cancelled. Please check your internet connection and try again.', 'Unable to verify');
    });
}

/* ── ATT LOG MODAL ── */
function openAttLogModal() {
    const today = new Date();
    // FIX (Attendance Log opened on yesterday): toISOString() is UTC, so between midnight and 8 AM in the Philippines it still gave the PREVIOUS day.
    // The local calendar day is what the header clock shows, so use that.
    const todayStr = today.getFullYear() + '-' + String(today.getMonth() + 1).padStart(2, '0') + '-' + String(today.getDate()).padStart(2, '0');
    let targetDate = todayStr;
    if (targetDate < _ojtStart) targetDate = _ojtStart;
    if (targetDate > _ojtEnd)   targetDate = _ojtEnd;
    _modalDate = targetDate;
    const [yr, mo] = targetDate.split('-');
    const ym = yr + '-' + mo;
    const monthSel = document.getElementById('modalMonthFilter');
    if (monthSel) {
        for (let opt of monthSel.options) {
            if (opt.value === ym) { opt.selected = true; break; }
        }
    }
    rebuildDayDropdown(ym, parseInt(targetDate.split('-')[2], 10));
    document.getElementById('attLogModal').style.display = 'flex';
    loadAttLogForDate(targetDate);
}
function closeAttLogModal() {
    document.getElementById('attLogModal').style.display = 'none';
}
document.getElementById('attLogModal').addEventListener('click', function(e) {
    if (e.target === this) closeAttLogModal();
});

/* ── ATTENDANCE SETTINGS POPUP ── */
function openAttSettings() { document.getElementById('attSettingsOverlay').classList.add('open'); }
function closeAttSettings() { document.getElementById('attSettingsOverlay').classList.remove('open'); }
document.getElementById('attSettingsOverlay').addEventListener('click', function(e) { if (e.target === this) closeAttSettings(); });
function openWizardFromSettings() { closeAttSettings(); setTimeout(openWizard, 120); }

/* ── IMAGE MODAL ── */
function openModal(src) {
    const m = document.getElementById("imageModal");
    m.style.display = "flex"; m.style.position = "fixed"; m.style.inset = "0";
    m.style.background = "rgba(0,0,0,0.92)"; m.style.zIndex = "99990";
    m.style.alignItems = "center"; m.style.justifyContent = "center";
    document.getElementById("modalImg").src = src;
    document.getElementById("modalImg").style.cssText = "max-width:90vw;max-height:88vh;border-radius:8px;object-fit:contain;box-shadow:0 8px 40px rgba(0,0,0,0.5);";
    const closeBtn = m.querySelector('.close');
    if (closeBtn) { closeBtn.style.cssText = "position:absolute;top:20px;right:40px;font-size:40px;color:white;cursor:pointer;z-index:1;"; }
}
function closeModal() { document.getElementById("imageModal").style.display = "none"; }
document.getElementById('imageModal').addEventListener('click', function(e) { if (e.target === this) closeModal(); });

/* ── SUMMARY PRINT ── */
function printSummary() {
    let content = document.getElementById("summaryTableWrapper").innerHTML.replace(/\bsm-row-hidden\b/g, ''); // UPDATED: print every student, not only the current page
    let win = window.open("","","width=900,height=700");
    // UPDATED: load Font Awesome so the OJT start / end icons also print
    win.document.write("<html><head><link rel=\"stylesheet\" href=\"https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css\"></head><body>" + content + "</body></html>");
    win.document.close();
    let _printed = false;
    const _doPrint = () => { if (_printed) return; _printed = true; win.print(); };
    win.onload = _doPrint;
    setTimeout(_doPrint, 1200);
}

/* ── TOAST ── */
/* ── LIVE UPDATES (UPDATED: replaces the old 45-second automatic page reload) ──
   Every few seconds the page asks the server for a tiny fingerprint of its
   data. Only when the fingerprint changes (new attendance, late request,
   schedule, student…) it loads the fresh page in the background and swaps
   the Attendance Overview, Monthly Attendance Summary, Quick Actions and
   Today's Stats panels in place. No reload, scroll position and open
   modals are kept, and the user never has to reload manually. */
let _liveSignature  = '<?= attm_live_signature($conn, $company_id) ?>';
let _liveBusy       = false;

function liveRunScripts(container, scriptTexts) {
    scriptTexts.forEach(code => {
        const s = document.createElement('script');
        s.textContent = code;
        container.appendChild(s);
    });
}
function liveSwapSection(name, newDoc) {
    const cur = document.querySelector('[data-live-section="' + name + '"]');
    const nxt = newDoc.querySelector('[data-live-section="' + name + '"]');
    if (!cur || !nxt) return false;
    if (cur.innerHTML === nxt.innerHTML) return false;
    // keep inline scripts aside (run them fresh after the swap); external scripts (Chart.js) are already loaded
    const inlineScripts = [];
    nxt.querySelectorAll('script').forEach(sc => {
        if (!sc.src) inlineScripts.push(sc.textContent);
        sc.remove();
    });
    let oldChart = null;
    if (name === 'chart' && window.Chart && typeof Chart.getChart === 'function') {
        const oldCanvas = document.getElementById('attendanceBarChart');
        oldChart = oldCanvas ? Chart.getChart(oldCanvas) : null;
    }
    const fresh = document.importNode(nxt, true);
    // NEW (real-time graph): keep the existing chart and animate it to the new numbers instead of rebuilding it
    // (no flicker, bars move as attendance is recorded). Falls back to rebuilding when the data cannot be read.
    if (oldChart) {
        try {
            const txt = inlineScripts.join(' ');
            const pick = k => { const i = txt.indexOf('const ' + k + ' '); if (i < 0) return null; const a = txt.indexOf('[', i), b = txt.indexOf(']', a); return (a < 0 || b < 0) ? null : JSON.parse(txt.slice(a, b + 1)); };
            const labels = pick('labels'), present = pick('present'), incomplete = pick('incomplete'), absent = pick('absent');
            const oldWrap = cur.querySelector('.chart-canvas-wrap'), newWrap = fresh.querySelector('.chart-canvas-wrap');
            if (labels && present && incomplete && absent && oldWrap && newWrap && oldChart.data.datasets.length >= 3) {
                newWrap.replaceWith(oldWrap);
                cur.replaceWith(fresh);
                oldChart.data.labels = labels;
                oldChart.data.datasets[0].data = present;
                oldChart.data.datasets[1].data = incomplete;
                oldChart.data.datasets[2].data = absent;
                oldChart.update();
                return true;
            }
        } catch (e) { /* fall through to the rebuild below */ }
    }
    if (oldChart) oldChart.destroy();
    cur.replaceWith(fresh);
    liveRunScripts(fresh, inlineScripts);
    return true;
}
function liveApplyPage(html) {
    const newDoc = new DOMParser().parseFromString(html, 'text/html');
    let changed = false;
    ['chart', 'summary', 'quick', 'today'].forEach(n => { if (liveSwapSection(n, newDoc)) changed = true; });

    // Attendance Settings popup body (current schedule) — only while it is closed
    const setOverlay = document.getElementById('attSettingsOverlay');
    const newSetBox  = newDoc.getElementById('attSettingsBox');
    const curSetBox  = document.getElementById('attSettingsBox');
    if (setOverlay && !setOverlay.classList.contains('open') && newSetBox && curSetBox && curSetBox.innerHTML !== newSetBox.innerHTML) {
        curSetBox.innerHTML = newSetBox.innerHTML;
    }
    // Sidebar badges that are not inside a swapped panel (OJT Student List / Company Reports)
    ['sidebarInboxBadge', 'sidebarReportBadge'].forEach(id => {
        const cur = document.getElementById(id), nxt = newDoc.getElementById(id);
        if (!cur || !nxt) return;
        const hide = nxt.style.display === 'none';
        if (cur.textContent !== nxt.textContent) { cur.textContent = nxt.textContent; changed = true; }
        if ((cur.style.display === 'none') !== hide) { cur.style.display = hide ? 'none' : ''; changed = true; }
    });
    // Wizard scope line ("Applies to today + N remaining OJT weekday(s)…")
    const newScope = newDoc.getElementById('sumScope'), curScope = document.getElementById('sumScope');
    if (newScope && curScope) curScope.innerHTML = newScope.innerHTML;

    // First-attendance maps used by the Attendance Log popup (NOT STARTED rows)
    const byId   = html.match(/const _firstAttendanceById\s*=\s*(\{[\s\S]*?\});/);
    const byName = html.match(/const _firstAttendanceByName\s*=\s*(\{[\s\S]*?\});/);
    const byEnd = html.match(/const _ojtEndById\s*=\s*(\{[\s\S]*?\});\s*\n/); // NEW (OJT ends at the required hours)
    const bySched = html.match(/const _schedById\s*=\s*(\{[\s\S]*?\});\s*\n/); // NEW (student schedule)
    try {
        if (byId)   { const o = JSON.parse(byId[1]);   Object.keys(_firstAttendanceById).forEach(k => delete _firstAttendanceById[k]);   Object.assign(_firstAttendanceById, o); }
        if (byName) { const o = JSON.parse(byName[1]); Object.keys(_firstAttendanceByName).forEach(k => delete _firstAttendanceByName[k]); Object.assign(_firstAttendanceByName, o); }
        if (byEnd) { const o = JSON.parse(byEnd[1]); Object.keys(_ojtEndById).forEach(k => delete _ojtEndById[k]); Object.assign(_ojtEndById, o); }
        if (bySched) { const o = JSON.parse(bySched[1]); Object.keys(_schedById).forEach(k => delete _schedById[k]); Object.assign(_schedById, o); }
    } catch (e) {}

    // Refresh open popups that show live data
    const attLogModal = document.getElementById('attLogModal');
    if (attLogModal && attLogModal.style.display === 'flex' && _modalDate) loadAttLogForDate(_modalDate);
    if (typeof fetchLateRequests === 'function') fetchLateRequests(true);
    return changed;
}
function liveRefreshNow(force) {
    if (_liveBusy) return Promise.resolve(false);
    _liveBusy = true;
    return fetch(window.location.pathname + '?action=live_signature', { headers: { 'X-Requested-With': 'XMLHttpRequest' }, cache: 'no-store', credentials: 'same-origin' })
        .then(r => {
            if (!r.ok) throw new Error('http ' + r.status);
            return r.json(); // a plain-text reply (session ended / access denied) is not JSON → counted as a failure below
        })
        .then(data => {
            if (!data || !data.success) throw new Error('bad signature reply');
            _liveFails = 0; liveSetStatus(true);
            if (!force && data.signature === _liveSignature) return false;
            return fetch(window.location.href, { cache: 'no-store', credentials: 'same-origin' })
                .then(r => { if (!r.ok) throw new Error('http ' + r.status); return r.text(); })
                .then(html => {
                    const changed = liveApplyPage(html);
                    _liveSignature = data.signature; // remembered only after the new data was applied, so a failed apply is retried
                    return changed;
                });
        })
        .catch(() => {
            _liveFails++;
            if (_liveFails >= LIVE_FAIL_LIMIT * 2) _liveStopped = true; // persistent failure (e.g. logged out): stop hammering the server
            liveSetStatus(false);
            return false;
        })
        .finally(() => { _liveBusy = false; });
}
/* Automatic live detection (no page reload): every LIVE_POLL_MS the page asks for the data fingerprint (attm_live_signature);
   only when it differs from the last one seen is the fresh page fetched in the background and the changed panels swapped in place
   (liveApplyPage). Paused while the tab is hidden, checked again at once when it becomes visible, and backed off while the server
   cannot be reached or the session has ended — the green "live" dot turns red meanwhile. */
const LIVE_POLL_MS = 3000, LIVE_POLL_MAX_MS = 60000, LIVE_FAIL_LIMIT = 5; // UPDATED (real-time graph): check every 3 s (was 10 s); failures still back off up to 60 s
let _liveFails = 0, _liveTimer = null, _liveStopped = false;
function liveSetStatus(ok) {
    const dot = document.querySelector('.dtw-live-dot');
    if (!dot) return;
    dot.classList.toggle('is-off', !ok);
    dot.title = ok ? 'Live updates on' : (_liveStopped ? 'Live updates stopped — reload the page' : 'Live updates paused — connection problem');
}
function liveSchedule() {
    clearTimeout(_liveTimer);
    if (_liveStopped) return;
    const wait = Math.min(LIVE_POLL_MAX_MS, LIVE_POLL_MS * Math.pow(2, Math.min(_liveFails, 3)));
    _liveTimer = setTimeout(liveTick, wait);
}
function liveTick() {
    if (document.hidden) { liveSchedule(); return; }
    liveRefreshNow(false).finally(liveSchedule);
}
function liveKick() { // tab shown / window focused: check right away, then carry on
    if (_liveStopped || document.hidden) return;
    clearTimeout(_liveTimer);
    liveTick();
}
document.addEventListener('visibilitychange', liveKick);
window.addEventListener('focus', liveKick);
window.addEventListener('online', () => { _liveFails = 0; liveKick(); });
liveSchedule();

/* ── EMAIL DEBUG ── */
function renderEmailDebug(emailErrors) {
    if (!emailErrors || emailErrors.length === 0) return;
    const panel = document.getElementById('emailDebugPanel');
    const body  = document.getElementById('emailDebugBody');
    let html = '';
    emailErrors.forEach(err => {
        html += `<div class="edbg-failure"><div class="edbg-recipient"><i class="fas fa-envelope" style="margin-right:6px;"></i>${escHtml(err.email)}<span>— ${escHtml(err.student)}</span></div><ul class="edbg-reason-list">`;
        err.reasons.forEach(r => { const isOk = r.startsWith('sendmail path OK') || r.startsWith('SMTP='); html += `<li class="${isOk?'ok':''}">${escHtml(r)}</li>`; });
        html += `</ul></div>`;
    });
    html += `<div class="edbg-hint"><strong>Common fixes:</strong><br>• <strong>Authentication failed:</strong> Use a Gmail App Password.<br>• <strong>Connection refused:</strong> Check SMTP_HOST / SMTP_PORT.<br>• <strong>Invalid address:</strong> Verify the email in the users table.</div>`;
    body.innerHTML = html;
    panel.classList.add('open');
}
function closeEmailDebug() { document.getElementById('emailDebugPanel').classList.remove('open'); }
function copyEmailDebug() { navigator.clipboard.writeText(document.getElementById('emailDebugBody').innerText).then(()=>{}); }
function escHtml(str) { return String(str).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;'); }

/* ── 8-HOUR POPUP ── */
function showHoursPopup(amMins, pmMins) {
    function fmtMins(m) { const h=Math.floor(m/60),min=m%60; return h+'h '+(min?min+'m':''); }
    document.getElementById('hp_am_hrs').textContent = fmtMins(amMins);
    document.getElementById('hp_pm_hrs').textContent = fmtMins(pmMins);
    const total = amMins + pmMins;
    const totalEl = document.getElementById('hp_total_hrs');
    totalEl.textContent = fmtMins(total);
    totalEl.style.color = total === 480 ? '#2e7d32' : '#c62828';
    // UPDATED (8-hour maximum): above 8 hours cannot be saved — no "Continue Anyway"
    const hpTitle = document.getElementById('hpTitle');
    const hpMsg   = document.getElementById('hpMessage');
    const hpCont  = document.getElementById('hpContinueBtn');
    if (!hpTitle.dataset.orig) { hpTitle.dataset.orig = hpTitle.innerHTML; hpMsg.dataset.orig = hpMsg.innerHTML; }
    const amOver = amMins > MAX_PERIOD_MINS, pmOver = pmMins > MAX_PERIOD_MINS;
    document.getElementById('hp_am_hrs').textContent = fmtMins(amMins) + (amOver ? ' — over the 4h limit' : '');
    document.getElementById('hp_pm_hrs').textContent = fmtMins(pmMins) + (pmOver ? ' — over the 4h limit' : '');
    if (amOver || pmOver) {
        // NEW (4-hour limit per duty): each of AM / PM may be at most 4 hours
        const which = amOver && pmOver ? 'AM and PM Duty Exceed' : (amOver ? 'AM Duty Exceeds' : 'PM Duty Exceeds');
        const over  = amOver ? amMins : pmMins;
        const lbl   = amOver && pmOver ? 'Each duty period' : (amOver ? 'The AM duty' : 'The PM duty');
        hpTitle.innerHTML = which + ' 4 Hours';
        hpMsg.innerHTML   = 'Almost there! ' + lbl + ' can be at most <strong>4 hours</strong> (AM 4h + PM 4h = <strong>8 hours</strong> a day). ' +
            (amOver && pmOver ? 'Both are currently over the limit' : 'It is currently <strong>' + fmtMins(over) + '</strong>') +
            ', so this schedule cannot be saved. Please shorten the Sign-In Opens → Sign-Out Opens window to 4 hours or less.';
        hpCont.style.display = 'none';
    } else if (total > MAX_DUTY_MINS) {
        hpTitle.innerHTML = 'Schedule Exceeds 8 Hours';
        hpMsg.innerHTML   = 'The combined AM and PM duty time is <strong>' + fmtMins(total) + '</strong>. The maximum allowed is <strong>8 hours</strong> per day, so this schedule cannot be saved. Please shorten the time windows.';
        hpCont.style.display = 'none';
    } else {
        hpTitle.innerHTML = hpTitle.dataset.orig;
        hpMsg.innerHTML   = hpMsg.dataset.orig;
        hpCont.style.display = '';
    }
    document.getElementById('hoursPopupOverlay').classList.add('open');
}
const MAX_DUTY_MINS = 480; // UPDATED (8-hour maximum)
const MAX_PERIOD_MINS = 240; // NEW (4-hour limit per duty)
function wizardPeriodMins() {
    const g = id => document.getElementById(id).value;
    const skipAm = document.getElementById('skipAmCheckbox').checked;
    const skipPm = document.getElementById('skipPmCheckbox').checked;
    return {
        am: skipAm ? 0 : calcDutyMins('w_am_ti_s','w_am_to_s'),
        pm: skipPm ? 0 : ((g('w_pm_ti_s')&&g('w_pm_to_s'))?calcDutyMins('w_pm_ti_s','w_pm_to_s'):0)
    };
}
function wizardDutyTotalMins() {
    const g = id => document.getElementById(id).value;
    const skipAm = document.getElementById('skipAmCheckbox').checked;
    const skipPm = document.getElementById('skipPmCheckbox').checked;
    const amMins = skipAm ? 0 : calcDutyMins('w_am_ti_s','w_am_to_s');
    const pmMins = skipPm ? 0 : ((g('w_pm_ti_s')&&g('w_pm_to_s'))?calcDutyMins('w_pm_ti_s','w_pm_to_s'):0);
    return amMins + pmMins;
}
function blockIfOverMax() {
    const total = wizardDutyTotalMins();
    const per = wizardPeriodMins();
    if (total > MAX_DUTY_MINS || per.am > MAX_PERIOD_MINS || per.pm > MAX_PERIOD_MINS) { // NEW (4-hour limit per duty)
        return true;
    }
    return false;
}
function closeHoursPopup() { document.getElementById('hoursPopupOverlay').classList.remove('open'); }
function calcDutyMins(startId, outStartId) {
    const s=document.getElementById(startId).value, e=document.getElementById(outStartId).value;
    if (!s || !e) return 0;
    const [sh,sm]=s.split(':').map(Number), [eh,em]=e.split(':').map(Number);
    let diff=(eh*60+em)-(sh*60+sm);
    // NEW: a PM sign-out earlier than its sign-in (e.g. 11:00 PM → 12:00 PM) means the NEXT day
    if (diff<0 && startId.indexOf('w_pm')===0) diff+=24*60;
    return Math.max(0,diff);
}

/* ── CONTINUE ANYWAY ──
   "Continue Anyway →" on the hours warning above now saves right away. That warning already asked the question, so the separate
   "Save Non-Standard Schedule?" confirmation that used to follow it was a second, identical "are you sure?" and has been removed.
   The checks are unchanged: a schedule over the 4-hour-per-duty / 8-hour-per-day maximum still cannot be saved (here and on the server). */
function continueAnywayConfirm() {
    if (blockIfOverMax()) return; // UPDATED (8-hour maximum)
    const g=id=>document.getElementById(id).value;
    const skipAm = document.getElementById('skipAmCheckbox').checked;
    const skipPm = document.getElementById('skipPmCheckbox').checked;
    document.getElementById('sum_am_in').textContent  = skipAm ? 'SKIPPED (No AM Duty)' : (fmt12js(g('w_am_ti_s'))+' – '+fmt12js(g('w_am_ti_e')));
    document.getElementById('sum_am_out').textContent = skipAm ? 'SKIPPED (No AM Duty)' : (fmt12js(g('w_am_to_s'))+' – '+fmt12js(g('w_am_to_e')));
    document.getElementById('sum_pm_in').textContent  = skipPm ? 'SKIPPED (No PM Duty)' : (g('w_pm_ti_s')?fmt12js(g('w_pm_ti_s'))+' – '+fmt12js(g('w_pm_ti_e')):'—');
    document.getElementById('sum_pm_out').textContent = skipPm ? 'SKIPPED (No PM Duty)' : (g('w_pm_to_s')?fmt12js(g('w_pm_to_s'))+' – '+fmt12js(g('w_pm_to_e')):'—');
    const amMins = skipAm ? 0 : calcDutyMins('w_am_ti_s','w_am_to_s');
    const pmMins = skipPm ? 0 : ((g('w_pm_ti_s')&&g('w_pm_to_s'))?calcDutyMins('w_pm_ti_s','w_pm_to_s'):0);
    const total=amMins+pmMins, h=Math.floor(total/60), mm=total%60;
    document.getElementById('sum_total_hrs').textContent=h+'h'+(mm?' '+mm+'m':'');
    closeHoursPopup();
    wizSave();
}

/* ── FIELD VALIDATION HELPERS ── */
function timeToMins(val) { if(!val) return -1; const [h,m]=val.split(':').map(Number); return h*60+m; }

function validateAmInField(input) {
    if (document.getElementById('skipAmCheckbox').checked) return;
    const mins = timeToMins(input.value);
    if (mins < 0) return;
    if (mins >= 12 * 60) {
        input.classList.add('input-error');
        showAmError('AM Sign-In times must be before 12:00 PM. You entered ' + fmt12js(input.value) + '.');
    } else {
        input.classList.remove('input-error');
        clearAmError();
    }
}

/* NEW (4-hour limit per duty): as soon as the AM Sign-In Opens and Sign-Out Opens are both filled, warn right away */
function checkAmDutyLimit() {
    try {
        if (document.getElementById('skipAmCheckbox').checked) return;
        const s = document.getElementById('w_am_ti_s').value, e = document.getElementById('w_am_to_s').value;
        if (!s || !e || timeToMins(s) >= 12 * 60) return; // wait until both are filled; noon error is handled by validateAmInField
        const amMins = calcDutyMins('w_am_ti_s', 'w_am_to_s');
        if (amMins > MAX_PERIOD_MINS) {
            const h = Math.floor(amMins / 60), m = amMins % 60;
            showAmError('The AM duty is ' + h + 'h' + (m ? ' ' + m + 'm' : '') + ' — the maximum is 4 hours. Please shorten the Sign-In Opens → Sign-Out Opens window.');
            document.getElementById('w_am_to_s').classList.add('input-error');
            showHoursPopup(amMins, 0);
        } else {
            document.getElementById('w_am_to_s').classList.remove('input-error');
        }
    } catch (err) { /* never block the wizard because of this helper */ }
}

function validatePmField(input) {
    if (document.getElementById('skipPmCheckbox').checked) return;
    const mins = timeToMins(input.value);
    if (mins < 0) return;
    if (mins < 12 * 60) {
        input.classList.add('input-error');
        showPmError('PM times must be 12:00 PM or later. You entered ' + fmt12js(input.value) + '.');
    } else {
        input.classList.remove('input-error');
        clearPmError();
        if (input.id === 'w_pm_ti_s' || input.id === 'w_pm_to_s') checkPmDutyLimit();
    }
}
/* NEW (4-hour limit per duty): same instant check for the PM Sign-In Opens → Sign-Out Opens window */
function checkPmDutyLimit() {
    try {
        if (document.getElementById('skipPmCheckbox').checked) return;
        const s = document.getElementById('w_pm_ti_s').value, e = document.getElementById('w_pm_to_s').value;
        if (!s || !e || timeToMins(s) < 12 * 60 || timeToMins(e) < 12 * 60) return;
        const pmMins = calcDutyMins('w_pm_ti_s', 'w_pm_to_s');
        if (pmMins > MAX_PERIOD_MINS) {
            const h = Math.floor(pmMins / 60), m = pmMins % 60;
            const next = timeToMins(e) < timeToMins(s) ? ' (the Sign-Out time is read as the next day)' : '';
            showPmError('The PM duty is ' + h + 'h' + (m ? ' ' + m + 'm' : '') + next + ' — the maximum is 4 hours. Please shorten the Sign-In Opens → Sign-Out Opens window.');
            document.getElementById('w_pm_to_s').classList.add('input-error');
            showHoursPopup(0, pmMins);
        } else {
            document.getElementById('w_pm_to_s').classList.remove('input-error');
        }
    } catch (err) { /* never block the wizard because of this helper */ }
}

function showAmError(msg){const el=document.getElementById('amErrorMsg');el.textContent=msg;el.style.display='block';}
function clearAmError(){const el=document.getElementById('amErrorMsg');if(el){el.style.display='none';el.textContent='';}}
function showPmError(msg){const el=document.getElementById('pmErrorMsg');el.textContent=msg;el.style.display='block';}
function clearPmError(){const el=document.getElementById('pmErrorMsg');el.style.display='none';el.textContent='';}

/* ── WIZARD ── */
function openWizard() {
    const skipAm = document.getElementById('skipAmCheckbox');
    const skipPm = document.getElementById('skipPmCheckbox');
    skipAm.checked = false;
    skipPm.checked = false;
    ['w_am_ti_s','w_am_ti_e','w_am_to_s','w_am_to_e','w_pm_ti_s','w_pm_ti_e','w_pm_to_s','w_pm_to_e'].forEach(id=>{
        const el=document.getElementById(id); if(el){el.value='';el.classList.remove('input-error');el.disabled=false;}
    });
    document.getElementById('amFieldsContainer').style.opacity = '1';
    document.getElementById('pmFieldsContainer').style.opacity = '1';
    clearAmError(); clearPmError();
    showWizStep(1);
    document.getElementById('wizardOverlay').classList.add('open');
}
function closeWizard(){document.getElementById('wizardOverlay').classList.remove('open'); const wb=document.getElementById('wizardBox'); if(wb) wb.classList.remove('s3-wide');}
function showWizStep(n){
    document.querySelectorAll('.wiz-step').forEach(s=>s.style.display='none');
    document.getElementById('wizStep'+n).style.display='block';
    document.getElementById('wizardProgressBar').style.width=(n*33.33)+'%';
    const wb=document.getElementById('wizardBox'); if(wb) wb.classList.toggle('s3-wide', n===3); // Step 3 uses the wide Add-Student-style form
}
function fmt12js(val){
    if(!val) return '—';
    const [h,m]=val.split(':').map(Number);
    const ampm=h>=12?'PM':'AM'; const h12=h%12||12;
    return `${h12}:${String(m).padStart(2,'0')} ${ampm}`;
}

function wizNext(step){
    const g=id=>document.getElementById(id).value;
    const skipAm = document.getElementById('skipAmCheckbox').checked;
    const skipPm = document.getElementById('skipPmCheckbox').checked;

    if (skipAm && skipPm) {
        return;
    }

    if(step===1){
        if (!skipAm) {
            if(!g('w_am_ti_s')||!g('w_am_ti_e')||!g('w_am_to_s')||!g('w_am_to_e')){
                return;
            }
            let hasError = false;
            ['w_am_ti_s','w_am_ti_e'].forEach(id=>{
                if(timeToMins(g(id)) >= 12*60){
                    document.getElementById(id).classList.add('input-error'); hasError=true;
                }
            });
            if(hasError){
                showAmError('AM Sign-In fields must be before noon (12:00 PM).');
                return;
            }
        }
        clearAmError();
        if (!skipAm) { // NEW (4-hour limit per duty): stop right at the AM step
            const amNow = calcDutyMins('w_am_ti_s','w_am_to_s');
            if (amNow > MAX_PERIOD_MINS) { showHoursPopup(amNow, 0); return; }
        }
        showWizStep(2);

    } else if(step===2){
        if (!skipPm) {
            const pmAny=g('w_pm_ti_s')||g('w_pm_ti_e')||g('w_pm_to_s')||g('w_pm_to_e');
            const pmAll=g('w_pm_ti_s')&&g('w_pm_ti_e')&&g('w_pm_to_s')&&g('w_pm_to_e');
            if(pmAny&&!pmAll){return;}
            if(pmAll){
                let hasPmError=false;
                ['w_pm_ti_s','w_pm_ti_e','w_pm_to_s','w_pm_to_e'].forEach(id=>{
                    if(timeToMins(g(id))<12*60){document.getElementById(id).classList.add('input-error');hasPmError=true;}
                });
                if(hasPmError){showPmError('All PM fields must be 12:00 PM or later.');return;}
            }
        }
        clearPmError();

        const amMins = skipAm ? 0 : calcDutyMins('w_am_ti_s','w_am_to_s');
        const pmMins = skipPm ? 0 : (g('w_pm_ti_s')&&g('w_pm_to_s') ? calcDutyMins('w_pm_ti_s','w_pm_to_s') : 0);
        const total  = amMins + pmMins;

        if (amMins > MAX_PERIOD_MINS || pmMins > MAX_PERIOD_MINS) { // NEW (4-hour limit per duty)
            showHoursPopup(amMins, pmMins);
            return;
        }

        const amHasValues = !skipAm && g('w_am_ti_s') && g('w_am_to_s');
        const pmHasValues = !skipPm && g('w_pm_ti_s') && g('w_pm_to_s');

        const shouldValidateHours = (
            (!skipAm && !skipPm && amHasValues && pmHasValues) ||
            (skipPm && !skipAm && amHasValues) ||
            (skipAm && !skipPm && pmHasValues)
        );

        if (shouldValidateHours && total !== 480) {
            showHoursPopup(amMins, pmMins);
            return;
        }

        document.getElementById('sum_am_in').textContent  = skipAm ? 'SKIPPED (No AM Duty)' : (fmt12js(g('w_am_ti_s'))+' – '+fmt12js(g('w_am_ti_e')));
        document.getElementById('sum_am_out').textContent = skipAm ? 'SKIPPED (No AM Duty)' : (fmt12js(g('w_am_to_s'))+' – '+fmt12js(g('w_am_to_e')));
        document.getElementById('sum_pm_in').textContent  = skipPm ? 'SKIPPED (No PM Duty)' : (g('w_pm_ti_s')?fmt12js(g('w_pm_ti_s'))+' – '+fmt12js(g('w_pm_ti_e')):'—');
        document.getElementById('sum_pm_out').textContent = skipPm ? 'SKIPPED (No PM Duty)' : (g('w_pm_to_s')?fmt12js(g('w_pm_to_s'))+' – '+fmt12js(g('w_pm_to_e')):'—');

        const h=Math.floor(total/60), m=total%60;
        const totalHrsEl = document.getElementById('sum_total_hrs');
        totalHrsEl.textContent = h+'h'+(m?' '+m+'m':'');

        showWizStep(3);
    }
}
function wizBack(step){showWizStep(step-1);}

function wizSave(){
    if (blockIfOverMax()) return; // UPDATED (8-hour maximum)
    const btn=document.getElementById('wizSaveBtn');
    btn.classList.add('loading'); btn.innerHTML='<i class="fas fa-spinner fa-spin"></i> Saving…';

    const skipAm = document.getElementById('skipAmCheckbox').checked;
    const skipPm = document.getElementById('skipPmCheckbox').checked;

    if (skipAm && skipPm) {
        btn.classList.remove('loading'); btn.innerHTML='<i class="fas fa-save"></i> Save & Notify';
        return;
    }

    closeWizard();
    showActionLoading('Saving & notifying');

    const g=id=>document.getElementById(id).value;
    const fd=new FormData();
    fd.append('date','<?= $date ?>');
    fd.append('skip_am', skipAm ? '1' : '0');
    fd.append('skip_pm', skipPm ? '1' : '0');
    fd.append('am_time_in_start',  skipAm ? '' : g('w_am_ti_s'));
    fd.append('am_time_in_end',    skipAm ? '' : g('w_am_ti_e'));
    fd.append('am_time_out_start', skipAm ? '' : g('w_am_to_s'));
    fd.append('am_time_out_end',   skipAm ? '' : g('w_am_to_e'));
    fd.append('pm_time_in_start',  skipPm ? '' : g('w_pm_ti_s'));
    fd.append('pm_time_in_end',    skipPm ? '' : g('w_pm_ti_e'));
    fd.append('pm_time_out_start', skipPm ? '' : g('w_pm_to_s'));
    fd.append('pm_time_out_end',   skipPm ? '' : g('w_pm_to_e'));
    fd.append('is_auto','1');
    fd.append('auto_type','all_remaining');

    let _saveDone = false;
    const finishSave = () => { if (_saveDone) return; _saveDone = true; btn.classList.remove('loading'); btn.innerHTML='<i class="fas fa-save"></i> Save & Notify'; hideActionLoading(); };
    fetch(window.location.pathname,{method:'POST',headers:{'X-Requested-With':'XMLHttpRequest'},body:fd})
    .then(r=>r.text())
    .then(rawText=>{
        finishSave();
        let data=null;
        try{data=JSON.parse(rawText);}catch(e){
            console.error(e,rawText);
            showGlobalResult('error','Save Failed','The server sent an unexpected reply, so it is not certain the schedule was saved. Please reload the page and check the Attendance Settings.',0);
            return;
        }
        if(data.success){
            if(data.email_errors&&data.email_errors.length>0) setTimeout(()=>renderEmailDebug(data.email_errors),600);
            if(data.db_errors&&data.db_errors.length>0){console.warn('DB errors:',data.db_errors);}
            liveRefreshNow(true); // show the new schedule in place (no page reload)
        }else{
            console.warn('Save failed:',data.message||'');
            showGlobalResult('error','Save Failed',data.message||'The attendance schedule could not be saved. Please try again.',0);
        }
    })
    .catch(err=>{
        finishSave();
        console.warn('Network error.',err);
        showGlobalResult('error','Save Failed','Could not reach the server. Please check your connection and try again.',0);
    });
}
document.getElementById('wizardOverlay').addEventListener('click',function(e){if(e.target===this)closeWizard();});
document.getElementById('hoursPopupOverlay').addEventListener('click',function(e){if(e.target===this)closeHoursPopup();});

/* ── BUILT-IN POPUP NOTIFICATIONS ── */
function requestNotifPermission() { /* no-op */ }
function sendSystemNotification(title, body, onClick) {
    const container=document.getElementById('builtInNotifContainer');
    if(!container){return;}
    const AUTO_CLOSE_MS=7000;
    const notif=document.createElement('div');
    notif.className='builtin-notif';
    notif.innerHTML=`<div class="bn-header"><div class="bn-header-left"><span class="bn-icon"><i class="fas fa-inbox"></i></span><span class="bn-title">${escHtml(title)}</span></div><button class="bn-close" title="Dismiss"><i class="fas fa-times"></i></button></div><div class="bn-body">${escHtml(body)}</div>${onClick?`<button class="bn-action">View Request →</button>`:''}<div class="bn-progress"><div class="bn-progress-bar" style="width:100%"></div></div>`;
    container.appendChild(notif);
    notif.querySelector('.bn-close').addEventListener('click',()=>dismissNotif(notif));
    if(onClick){notif.querySelector('.bn-action').addEventListener('click',()=>{dismissNotif(notif);onClick();});}
    const bar=notif.querySelector('.bn-progress-bar');
    bar.style.transition=`width ${AUTO_CLOSE_MS}ms linear`;
    void bar.offsetWidth;
    bar.style.width='0%';
    let autoTimer=setTimeout(()=>dismissNotif(notif),AUTO_CLOSE_MS);
    notif.addEventListener('mouseenter',()=>{clearTimeout(autoTimer);bar.style.transition='none';});
    notif.addEventListener('mouseleave',()=>{const remaining=(parseFloat(bar.style.width)/100)*AUTO_CLOSE_MS;const wait=Math.max(remaining,500);bar.style.transition=`width ${wait}ms linear`;bar.style.width='0%';autoTimer=setTimeout(()=>dismissNotif(notif),wait);});
}
function dismissNotif(notif){if(notif._dismissed)return;notif._dismissed=true;notif.classList.add('bn-hiding');setTimeout(()=>{if(notif.parentNode)notif.parentNode.removeChild(notif);},320);}

/* ── CUSTOM CONFIRM DIALOG ── */
function showCustomConfirm({title,message,studentName,type,onConfirm,confirmLabel,confirmIcon}){
    document.getElementById('ccTitle').textContent=title;
    document.getElementById('ccMessage').textContent=message;
    const iconWrap=document.getElementById('ccIconWrap'),confirmBtn=document.getElementById('ccConfirmBtn'),studentBadge=document.getElementById('ccStudentBadge'),studentNameEl=document.getElementById('ccStudentName');
    if(studentName){studentNameEl.textContent=studentName;studentBadge.style.display='inline-flex';}else{studentBadge.style.display='none';}
    if(type==='approve'){iconWrap.innerHTML='<i class="fas fa-check" style="color:#16a34a;font-size:24px;"></i>';iconWrap.className='cc-icon-wrap approve';confirmBtn.className='cc-btn cc-btn-confirm-approve';confirmBtn.innerHTML='<i class="fas fa-check"></i> Allow Request';}
    else{iconWrap.innerHTML='<i class="fas fa-times" style="color:#dc2626;font-size:24px;"></i>';iconWrap.className='cc-icon-wrap reject';confirmBtn.className='cc-btn cc-btn-confirm-reject';confirmBtn.innerHTML='<i class="fas '+(confirmIcon||'fa-times')+'"></i> '+(confirmLabel||'Reject Request');}
    const newBtn=confirmBtn.cloneNode(true);confirmBtn.parentNode.replaceChild(newBtn,confirmBtn);
    newBtn.addEventListener('click',()=>{closeCustomConfirm();onConfirm();});
    document.getElementById('customConfirmOverlay').classList.add('open');
}
function closeCustomConfirm(){document.getElementById('customConfirmOverlay').classList.remove('open');}
document.getElementById('customConfirmOverlay').addEventListener('click',function(e){if(e.target===this)closeCustomConfirm();});

/* ── LATE REQUEST INBOX ── */
let liRequests=[], liTab='pending', liLoaded=false;
let _lastKnownPendingIds=new Set(<?php
    $init_ids=$conn->query("SELECT id FROM late_requests WHERE company_id=$company_id AND status='pending' LIMIT 50");
    $id_arr=[];while($idr=$init_ids->fetch_assoc())$id_arr[]=$idr['id'];echo json_encode($id_arr);
?>);
const TYPE_LABELS_CO={am_time_in:'AM Sign In',am_time_out:'AM Sign Out',pm_time_in:'PM Sign In',pm_time_out:'PM Sign Out'};
function reqKindNote(req){
    const isOut=(req.type==='am_time_out'||req.type==='pm_time_out');
    if(!isOut) return '';
    const P=req.type==='am_time_out'?'AM':'PM';
    return `<div class="req-kind-note"><strong>Late Request:</strong> only the ${P} duty is counted &mdash; ${P} Sign In to the scheduled ${P} Sign Out.</div>`;
}

function openLateInbox(){document.getElementById('lateInboxOverlay').classList.add('open');fetchLateRequests();} // always load fresh data on open (the automatic polling is gone)
function closeLateInbox(){document.getElementById('lateInboxOverlay').classList.remove('open');}
document.getElementById('lateInboxOverlay').addEventListener('click',function(e){if(e.target===this)closeLateInbox();});

function switchTab(tab){
    if(tab==='approved'||tab==='rejected') tab='history';   // the Approved / Rejected tabs are now one Request History
    liTab=(tab==='history')?'history':'pending';
    document.querySelectorAll('.li-tab').forEach(t=>{t.classList.toggle('active',t.dataset.tab===liTab);});
    renderLiBody();
}
function updateLiTabCounts(){
    const p=liRequests.filter(r=>r.status==='pending').length, h=liRequests.length-p;
    const a=document.getElementById('liCountPending'), b=document.getElementById('liCountHistory');
    if(a)a.textContent=p; if(b)b.textContent=h;
}

function fetchLateRequests(isPolling){
    if(!isPolling) document.getElementById('li-body').innerHTML=`<div class="li-loading"><div class="li-spinner"></div><span>Loading…</span></div>`;
    fetch(window.location.pathname+'?action=get_late_requests',{headers:{'X-Requested-With':'XMLHttpRequest'},cache:'no-store'})
    .then(r=>r.json())
    .then(data=>{
        if(!data.success){if(!isPolling)document.getElementById('li-body').innerHTML=`<div class="li-empty">Failed to load.</div>`;return;}
        const newRequests=data.requests||[];
        if(isPolling){
            const newPendingIds=newRequests.filter(r=>r.status==='pending').map(r=>r.id);
            const brandNew=newPendingIds.filter(id=>!_lastKnownPendingIds.has(id));
            if(brandNew.length>0){
                const req=newRequests.find(r=>r.id===brandNew[0]);
                const studentName=req?(req.first_name+(req.middle_name?' '+req.middle_name:'')+' '+req.last_name):'A student';
                const typeLabel=req?(TYPE_LABELS_CO[req.type]||req.type):'';
                sendSystemNotification('New Late Request',`${studentName} submitted a late request for ${typeLabel}.`,()=>{window.focus();openLateInbox();switchTab('pending');});
            }
            newPendingIds.forEach(id=>_lastKnownPendingIds.add(id));
            const currentPendingSet=new Set(newPendingIds);
            _lastKnownPendingIds.forEach(id=>{if(!currentPendingSet.has(id))_lastKnownPendingIds.delete(id);});
        }
        liRequests=newRequests; liLoaded=true;
        const pending=liRequests.filter(r=>r.status==='pending').length;
        updateBadges(pending);
        if(document.getElementById('lateInboxOverlay').classList.contains('open')||!isPolling) renderLiBody();
    })
    .catch(()=>{if(!isPolling)document.getElementById('li-body').innerHTML=`<div class="li-empty">Network error.</div>`;});
}

function liPhotoThumb(req){
    // a small square thumbnail (click = full-size lightbox); the picture itself loads when the card scrolls into view
    if(req.has_photo){return `<div class="req-thumb" id="photo-frame-${req.id}" onclick="openPhotoLightbox(${req.id},'${escH(req.first_name)} ${escH(req.last_name)}')" data-loaded="false" title="Click to enlarge"><div class="rp-loading"><div class="rp-spinner"></div></div></div>`;}
    return `<div class="req-thumb is-empty" title="No photo submitted"><i class="fas fa-image"></i><span>No photo</span></div>`;
}
function liFmtTime(v){if(!v||v==='missed')return'—';const t=v.includes(' ')?v.split(' ')[1]:v;const [h,m]=t.split(':').map(Number);const ampm=h>=12?'PM':'AM';const h12=h%12||12;return`${h12}:${String(m).padStart(2,'0')} ${ampm}`;}
function liDutyHtml(req){
    if(req.status==='approved'&&(req.am_time_in||req.pm_time_in)){
        return `<div class="req-duty-info has-late"><div class="req-duty-stat"><strong>AM In</strong>${liFmtTime(req.am_time_in)}</div><div class="req-duty-stat"><strong>AM Out</strong>${liFmtTime(req.am_time_out)}</div><div class="req-duty-stat"><strong>PM In</strong>${liFmtTime(req.pm_time_in)}</div><div class="req-duty-stat"><strong>PM Out</strong>${liFmtTime(req.pm_time_out)}</div></div>`;
    }
    return '';
}
function liPendingCard(req){
    const typeBadge=`<span class="req-type-badge ${req.type}">${TYPE_LABELS_CO[req.type]||req.type}</span>`;
    const dateLabel=new Date(req.date+'T00:00:00').toLocaleDateString('en-US',{weekday:'long',year:'numeric',month:'long',day:'numeric'});
    const submitted=new Date(req.created_at).toLocaleString('en-US',{month:'short',day:'numeric',hour:'2-digit',minute:'2-digit'});
    const actions=`<div class="req-actions"><button class="req-btn-allow" onclick="approveRequest(${req.id}, this)">Allow</button><button class="req-btn-reject" onclick="rejectRequest(${req.id}, this)">Reject</button></div>`;
    let evidenceHtml='';
        if(req.evidence){const ev=req.evidence,ico={ok:'fa-circle-check',info:'fa-circle-info',review:'fa-triangle-exclamation',risk:'fa-circle-xmark'};evidenceHtml=`<div class="req-evidence ${escH(ev.level)}"><div class="req-evidence-head"><i class="fas ${ev.level==='ok'?'fa-shield-halved':(ev.level==='review'?'fa-triangle-exclamation':'fa-circle-exclamation')}"></i>Legitimacy check — ${escH(ev.label)}</div><ul class="req-evidence-list">${(ev.signals||[]).map(sg=>`<li class="${escH(sg.level)}"><i class="fas ${ico[sg.level]||'fa-circle-info'}"></i><span>${escH(sg.text)}</span></li>`).join('')}</ul>${ev.detail?`<div class="req-evidence-detail"><div><span>${ev.detail.period} Sign In recorded</span><strong>${ev.detail.in?escH(ev.detail.in):'none'}</strong></div><div><span>Scheduled ${ev.detail.period} Sign Out</span><strong>${ev.detail.sched_out?escH(ev.detail.sched_out):'—'}</strong></div><div><span>Request sent at</span><strong>${ev.detail.sent?escH(ev.detail.sent):'—'}</strong></div><div><span>Credited if allowed</span><strong>${ev.detail.late_credit?escH(ev.detail.late_credit):'—'}</strong></div></div>`:''}${ev.device?`<div class="req-evidence-device">Sent from: ${escH(ev.device)}</div>`:''}</div>`;}
    return `<div class="req-card" id="req-card-${req.id}"><div class="req-main">${liPhotoThumb(req)}<div class="req-main-info"><div class="req-student-name">${escH(req.first_name)}${req.middle_name?' '+escH(req.middle_name):''} ${escH(req.last_name)}</div><div class="req-meta">${typeBadge}<span>${escH(dateLabel)}</span><span>Submitted ${escH(submitted)}</span></div>${reqKindNote(req)}<div class="req-reason-box"><div class="req-reason-label">Reason</div>${escH(req.reason)}</div></div></div>${evidenceHtml}${actions}</div>`;
}
function liHistoryCard(req){
    const typeBadge=`<span class="req-type-badge ${req.type}">${TYPE_LABELS_CO[req.type]||req.type}</span>`;
    const dateLabel=new Date(req.date+'T00:00:00').toLocaleDateString('en-US',{month:'short',day:'numeric',year:'numeric'});
    const decided=req.reviewed_at?new Date(String(req.reviewed_at).replace(' ','T')).toLocaleString('en-US',{month:'short',day:'numeric',year:'numeric',hour:'2-digit',minute:'2-digit'}):'';
    const status=req.status==='approved'?`<span class="req-status-badge approved">Approved</span>`:`<span class="req-status-badge rejected">Rejected</span>`;
    return `<div class="req-card is-history" id="req-card-${req.id}"><div class="req-main">${liPhotoThumb(req)}<div class="req-main-info"><div class="req-history-head"><div class="req-student-name">${escH(req.first_name)}${req.middle_name?' '+escH(req.middle_name):''} ${escH(req.last_name)}</div>${status}</div><div class="req-meta">${typeBadge}<span>${escH(dateLabel)}</span>${decided?`<span class="req-history-when">${req.status==='approved'?'Approved':'Rejected'} ${escH(decided)}</span>`:''}</div><div class="req-history-reason"><b>Reason:</b> ${escH(req.reason)}</div>${liDutyHtml(req)}</div></div></div>`;
}
function liNowStamp(){const d=new Date(),p=n=>String(n).padStart(2,'0');return d.getFullYear()+'-'+p(d.getMonth()+1)+'-'+p(d.getDate())+' '+p(d.getHours())+':'+p(d.getMinutes())+':'+p(d.getSeconds());}
let _liPhotoObs=null;
function observeLiPhotos(list){
    if(_liPhotoObs){_liPhotoObs.disconnect();_liPhotoObs=null;}
    const withPhoto=list.filter(r=>r.has_photo);
    if(!withPhoto.length)return;
    if(!('IntersectionObserver' in window)){withPhoto.forEach(r=>loadPhotoIntoFrame(r.id));return;}
    _liPhotoObs=new IntersectionObserver(entries=>{entries.forEach(en=>{if(en.isIntersecting){_liPhotoObs.unobserve(en.target);loadPhotoIntoFrame(en.target.dataset.reqId);}});},{root:document.getElementById('li-body'),rootMargin:'160px'});
    withPhoto.forEach(r=>{const fr=document.getElementById('photo-frame-'+r.id);if(fr){fr.dataset.reqId=r.id;_liPhotoObs.observe(fr);}});
}
function renderLiBody(){
    updateLiTabCounts();
    const isHistory=(liTab==='history');
    let list=liRequests.filter(r=>isHistory?r.status!=='pending':r.status==='pending');
    if(isHistory) list=list.slice().sort((a,b)=>String(b.reviewed_at||b.created_at).localeCompare(String(a.reviewed_at||a.created_at)));
    const body=document.getElementById('li-body');
    let html='';
    if(isHistory){
        html+=`<div class="li-history-bar"><span>${list.length?list.length+' approved or rejected request'+(list.length===1?'':'s'):'No history yet'}</span><button type="button" class="li-clear-btn" onclick="clearLateHistory()" ${list.length?'':'disabled'}><i class="fas fa-trash-can"></i> Clear History</button></div>`;
    }
    if(!list.length){body.innerHTML=html+`<div class="li-empty">${isHistory?'No approved or rejected requests.':'No pending requests.'}</div>`;return;}
    list.forEach(req=>{html+=isHistory?liHistoryCard(req):liPendingCard(req);});
    body.innerHTML=html;
    observeLiPhotos(list);
}

function loadPhotoIntoFrame(reqId){
    const frame=document.getElementById('photo-frame-'+reqId);
    if(!frame||frame.dataset.loaded==='true')return;
    frame.dataset.loaded='true';
    fetch(window.location.pathname+'?action=get_late_request_photo&req_id='+reqId,{headers:{'X-Requested-With':'XMLHttpRequest'},cache:'no-store'})
    .then(r=>r.json())
    .then(data=>{
        if(data.success&&data.photo_b64){const src='data:image/jpeg;base64,'+data.photo_b64;frame.innerHTML=`<img src="${src}" alt="Student photo"><span class="rp-zoom"><i class="fas fa-magnifying-glass-plus"></i></span>`;frame.dataset.src=src;}
        else{frame.classList.add('is-empty');frame.style.cursor='default';frame.innerHTML=`<i class="fas fa-image"></i><span>Unavailable</span>`;}
    })
    .catch(()=>{frame.dataset.loaded='false';frame.classList.add('is-empty');frame.innerHTML=`<i class="fas fa-rotate-right"></i><span>Retry</span>`;frame.onclick=()=>{frame.classList.remove('is-empty');frame.innerHTML='<div class="rp-loading"><div class="rp-spinner"></div></div>';frame.onclick=()=>openPhotoLightbox(reqId,'');loadPhotoIntoFrame(reqId);};});
}

/* NEW (Request History): "Clear History" — deletes every approved and rejected late request (pending ones stay). */
function clearLateHistory(){
    const n=liRequests.filter(r=>r.status!=='pending').length;
    if(!n)return;
    showCustomConfirm({title:'Clear Request History?',message:`All ${n} approved and rejected late request${n===1?'':'s'} will be permanently deleted. Pending requests are not affected, and attendance already recorded stays as it is. This cannot be undone.`,type:'reject',confirmLabel:'Clear History',confirmIcon:'fa-trash-can',onConfirm:()=>{
        showActionLoading('Clearing history');
        const fd=new FormData(); fd.append('action','clear_late_request_history');
        fetch(window.location.pathname,{method:'POST',headers:{'X-Requested-With':'XMLHttpRequest'},body:fd})
        .then(r=>r.json())
        .then(data=>{
            hideActionLoading();
            if(data.success){
                liRequests=liRequests.filter(r=>r.status==='pending');
                if(data.pending_count!==undefined)updateBadges(data.pending_count);
                renderLiBody();
                showGlobalResult('success','History Cleared',(data.deleted||0)+' request'+((data.deleted||0)===1?'':'s')+' deleted.',2200);
            }else{showGlobalResult('error','History Not Cleared',data.message||'The history could not be cleared. Please try again.',0);}
        })
        .catch(()=>{hideActionLoading();showGlobalResult('error','History Not Cleared','Could not reach the server. Please check your connection and try again.',0);});
    }});
}

function openPhotoLightbox(reqId,studentName){
    const frame=document.getElementById('photo-frame-'+reqId);
    const src=frame?frame.dataset.src:null;
    if(!src)return;
    document.getElementById('reqPhotoImg').src=src;
    document.getElementById('reqPhotoCaption').textContent=studentName+' — Click anywhere to close';
    document.getElementById('reqPhotoLightbox').classList.add('open');
}

function approveRequest(reqId,btn){
    const req=liRequests.find(r=>r.id===reqId);
    const studentName=req?(req.first_name+(req.middle_name?' '+req.middle_name:'')+' '+req.last_name):null;
    const isRisk=!!(req&&req.evidence&&req.evidence.level==='risk');
    const baseMsg="The student's time and photo will be recorded.";
    showCustomConfirm({title:'Allow Late Request?',message:isRisk?"High-risk warnings were found in the legitimacy check. Allow only if you have confirmed this with the student. "+baseMsg:baseMsg,studentName,type:'approve',onConfirm:()=>{
        btn.classList.add('loading'); btn.textContent='Processing…';
        showActionLoading('Allowing request');
        const fd=new FormData(); fd.append('action','approve_late_request'); fd.append('req_id',reqId); if(isRisk) fd.append('confirm_risk','1');
        fetch(window.location.pathname,{method:'POST',headers:{'X-Requested-With':'XMLHttpRequest'},body:fd})
        .then(r=>r.json())
        .then(data=>{
            hideActionLoading();
            if(data.success){
                const idx=liRequests.findIndex(r=>r.id===reqId);
                if(idx!==-1){liRequests[idx].status='approved';liRequests[idx].reviewed_at=liNowStamp();}
                const pending=liRequests.filter(r=>r.status==='pending').length;
                updateBadges(pending);
                if(data.pending_count!==undefined)updateBadges(data.pending_count);
                renderLiBody();
            }else{btn.classList.remove('loading');btn.textContent='Allow';showGlobalResult('error','Request Not Allowed',data.message||'The late request could not be approved. Please try again.',0);}
        })
        .catch(()=>{hideActionLoading();btn.classList.remove('loading');btn.textContent='Allow';showGlobalResult('error','Request Not Allowed','Could not reach the server. Please check your connection and try again.',0);});
    }});
}

function rejectRequest(reqId,btn){
    const req=liRequests.find(r=>r.id===reqId);
    const studentName=req?(req.first_name+' '+req.last_name):null;
    showCustomConfirm({title:'Reject This Request?',message:"The student's late request will be rejected. This cannot be undone.",studentName,type:'reject',onConfirm:()=>{
        showActionLoading('Rejecting request');
        const fd=new FormData(); fd.append('action','reject_late_request'); fd.append('req_id',reqId);
        fetch(window.location.pathname,{method:'POST',headers:{'X-Requested-With':'XMLHttpRequest'},body:fd})
        .then(r=>r.json())
        .then(data=>{
            hideActionLoading();
            if(data.success){
                const idx=liRequests.findIndex(r=>r.id===reqId);
                if(idx!==-1){liRequests[idx].status='rejected';liRequests[idx].reviewed_at=liNowStamp();}
                const pending=liRequests.filter(r=>r.status==='pending').length;
                updateBadges(pending);
                if(data.pending_count!==undefined)updateBadges(data.pending_count);
                renderLiBody();
            }else{showGlobalResult('error','Request Not Rejected',data.message||'The late request could not be rejected. Please try again.',0);}
        })
        .catch(()=>{hideActionLoading();showGlobalResult('error','Request Not Rejected','Could not reach the server. Please check your connection and try again.',0);});
    }});
}

function updateBadges(count){
    const badge=document.getElementById('inboxBadge');
    if(badge){badge.textContent=count;badge.style.display=count>0?'inline-flex':'none';}
    const sbBadge=document.getElementById('sidebarLateBadge');
    if(sbBadge){sbBadge.textContent=count;sbBadge.style.display=count>0?'inline-flex':'none';}
}

function escH(str){return String(str||'').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');}

/* ══ ATTENDANCE LOG MODAL — AJAX DAY NAVIGATION ══ */
let _modalDate=null;
/* UPDATED (Start = first attendance): each student's first attendance date, used so
   the Attendance Log never shows ABSENT for a day before the student started. */
const _firstAttendanceById   = <?= json_encode((object)array_map('strval', $student_first_attendance)) ?>;
const _firstAttendanceByName = <?php
    $fa_by_name = [];
    foreach ($students as $fa_id => $fa_s) {
        $fa_date = $student_first_attendance[(int)$fa_id] ?? '';
        $fa_mn   = trim($fa_s['middle_name'] ?? '');
        foreach ([
            trim($fa_s['first_name'] . ' ' . $fa_s['last_name']),
            trim($fa_s['first_name'] . ($fa_mn ? ' ' . $fa_mn : '') . ' ' . $fa_s['last_name']),
            trim($fa_s['last_name'] . ', ' . $fa_s['first_name']),
        ] as $fa_key) {
            $fa_by_name[mb_strtolower(preg_replace('/\s+/', ' ', $fa_key))] = $fa_date;
        }
    }
    echo json_encode((object)$fa_by_name);
?>;
/* NEW (student schedule): each student's Day (AM duty) / Evening (PM duty) training days (0=Sun…6=Sat numbers, null = both
   duties every weekday) and dated schedule changes, so the Attendance Log judges a student only on the duties they are scheduled for. */
const _schedById = <?= json_encode(attsch_js_map($student_sched)) ?>;
/* NEW (OJT ends at the required hours): the day each student reached the course's required hours; later days are never ABSENT / INCOMPLETE. */
const _ojtEndById = <?= json_encode((object)array_map('strval', $student_ojt_end)) ?>;
function schedPeriods(row, dateStr){
    const both = {am:true, pm:true};
    const id = row.student_id ?? row.user_id ?? row.id;
    if (id === undefined || id === null || !Object.prototype.hasOwnProperty.call(_schedById, String(id))) return both; // unknown student: keep server status
    const s = _schedById[String(id)];
    let sc = s.c;
    for (const h of (s.h || [])) { if (h[0] > dateStr) { sc = h[1]; break; } }
    if (sc === null || sc === undefined) return both;
    const dow = new Date(dateStr + 'T00:00:00').getDay();
    return {am: (sc.d||[]).indexOf(dow) !== -1, pm: (sc.e||[]).indexOf(dow) !== -1};
}
/* Day schedule = AM duty, Evening schedule = PM duty. Returns the corrected status (or null = keep the server's):
   NOT SCHEDULED when neither duty is scheduled and nothing was recorded; otherwise the status is re-judged on the
   duty periods the student is scheduled for (a period holding a real entry always counts). */
function schedStatus(row, dateStr){
    const sp = schedPeriods(row, dateStr);
    if (sp.am && sp.pm) return null;
    const realAm = attHasReal(row.am_time_in) || attHasReal(row.am_time_out);
    const realPm = attHasReal(row.pm_time_in) || attHasReal(row.pm_time_out);
    if (!sp.am && !sp.pm && !realAm && !realPm) return 'NOT SCHEDULED';
    const cols = [];
    if (sp.am || realAm) cols.push(row.am_time_in, row.am_time_out);
    if (sp.pm || realPm) cols.push(row.pm_time_in, row.pm_time_out);
    const isMissed = v => String(v||'').trim().toLowerCase() === 'missed';
    const anyReal = cols.some(attHasReal), allReal = cols.every(attHasReal), anyMissed = cols.some(isMissed);
    if (!anyReal && !anyMissed) return 'ABSENT';
    if (allReal && !anyMissed) return 'PRESENT';
    return 'INCOMPLETE';
}
function attHasReal(v){ if (v === null || v === undefined) return false; const t = String(v).trim().toLowerCase(); return t !== '' && t !== '-' && t !== 'missed'; } // the log popup shows '-' for empty and 'MISSED' for missed
function isBeforeFirstAttendance(row, dateStr){
    const id = row.student_id ?? row.user_id ?? row.id;
    let first;
    if (id !== undefined && id !== null && Object.prototype.hasOwnProperty.call(_firstAttendanceById, String(id))) first = _firstAttendanceById[String(id)];
    else if (id !== undefined && id !== null) first = ''; // assigned student with no attendance yet
    else {
        const key = String(row.name||'').toLowerCase().replace(/\s+/g,' ').trim();
        if (!Object.prototype.hasOwnProperty.call(_firstAttendanceByName, key)) return false; // unknown student: keep server status
        first = _firstAttendanceByName[key];
    }
    return !first || dateStr < first;
}
const _ojtStart='<?= $start_date ?>';
const _ojtEnd='<?= $end_date_limit ?>';
const _monthMin='<?= $ojt_start_month ?>';
const _monthMax='<?= $month_max ?>';
const ATT_PAGE_SIZE=10;
let _attAllRows=[], _attPage=0, _attIsWeekend=false, _attData=null;

function rebuildDayDropdown(ym,selectedDay){
    const [yr,mo]=ym.split('-').map(Number);
    const daysInMonth=new Date(yr,mo,0).getDate();
    const sel=document.getElementById('modalDayFilter');
    sel.innerHTML='';
    for(let d=1;d<=daysInMonth;d++){const opt=document.createElement('option');opt.value=d;opt.textContent=String(d).padStart(2,'0');if(d===selectedDay)opt.selected=true;sel.appendChild(opt);}
    updateModalNavBtns(_modalDate);
}

function updateModalNavBtns(dateStr){
    const prev=document.getElementById('modalPrevDay'),next=document.getElementById('modalNextDay');
    const atStart=dateStr<=_ojtStart,atEnd=dateStr>=_ojtEnd;
    prev.style.opacity=atStart?'0.35':'1'; prev.style.pointerEvents=atStart?'none':'';
    next.style.opacity=atEnd?'0.35':'1';   next.style.pointerEvents=atEnd?'none':'';
}

function onModalMonthChange(){
    const ym=document.getElementById('modalMonthFilter').value;
    const [yr,mo]=ym.split('-').map(Number);
    let day=1;
    const candidate=ym+'-01';
    if(candidate<_ojtStart) day=parseInt(_ojtStart.split('-')[2],10);
    rebuildDayDropdown(ym,day);
    _modalDate=ym+'-'+String(day).padStart(2,'0');
    loadAttLogForDate(_modalDate);
}

function modalGoToDay(){
    const ym=document.getElementById('modalMonthFilter').value;
    const day=document.getElementById('modalDayFilter').value;
    _modalDate=ym+'-'+String(day).padStart(2,'0');
    updateModalNavBtns(_modalDate);
    loadAttLogForDate(_modalDate);
}

function modalShiftDay(delta){
    if(!_modalDate)return;
    const parts=_modalDate.split('-').map(Number);
    const d=new Date(parts[0],parts[1]-1,parts[2]);
    d.setDate(d.getDate()+delta);
    const yy=d.getFullYear(),mm=String(d.getMonth()+1).padStart(2,'0'),dd=String(d.getDate()).padStart(2,'0');
    let next=`${yy}-${mm}-${dd}`;
    if(next<_ojtStart)next=_ojtStart;
    if(next>_ojtEnd)next=_ojtEnd;
    if(next===_modalDate)return;
    _modalDate=next;
    const ym2=next.slice(0,7),day2=parseInt(next.slice(8),10);
    const monthSel=document.getElementById('modalMonthFilter');
    for(let opt of monthSel.options){if(opt.value===ym2){opt.selected=true;break;}}
    rebuildDayDropdown(ym2,day2);
    loadAttLogForDate(next);
}

function loadAttLogForDate(dateStr){
    document.getElementById('attLogSpinner').style.display='block';
    document.getElementById('attLogTableBox').style.opacity='0.3';
    fetch('attendance_ajax.php?date='+encodeURIComponent(dateStr),{headers:{'X-Requested-With':'XMLHttpRequest'},cache:'no-store'})
    .then(r=>r.json())
    .then(data=>{
        document.getElementById('attLogSpinner').style.display='none';
        document.getElementById('attLogTableBox').style.opacity='1';
        if(!data.success){return;}
        renderAttLogTable(data);
    })
    .catch(()=>{
        document.getElementById('attLogSpinner').style.display='none';
        document.getElementById('attLogTableBox').style.opacity='1';
    });
}

function renderAttLogTable(data){
    const {date,is_weekend,day_name,rows}=data;
    _attAllRows=rows||[]; _attIsWeekend=!!is_weekend; _attData=data; _attPage=0;
    const d=new Date(date+'T00:00:00');
    const pretty=d.toLocaleDateString('en-US',{weekday:'long',year:'numeric',month:'long',day:'numeric'});
    document.getElementById('attLogTitle').textContent='— '+d.toLocaleDateString('en-US',{month:'long',year:'numeric'})+' — Day '+d.getDate();
    document.getElementById('attLogDateHeading').textContent=pretty;
    const wkndNotice=document.getElementById('attLogWeekendNotice');
    if(is_weekend){document.getElementById('attLogWeekendDay').textContent=day_name;wkndNotice.style.display='flex';}else{wkndNotice.style.display='none';}
    const thead=document.getElementById('attLogThead');
    if(!is_weekend){
        thead.innerHTML=`<tr><th rowspan="2" style="text-align:left;padding:8px 10px;background:#f5f5f5;border-bottom:2px solid #e0e0e0;">#</th><th rowspan="2" style="text-align:left;padding:8px 10px;background:#f5f5f5;border-bottom:2px solid #e0e0e0;">Name</th><th colspan="4" style="text-align:center;background:#fff8e1;padding:6px;border-bottom:1px solid #e0e0e0;">AM Duty</th><th colspan="4" style="text-align:center;background:#e8f5e9;padding:6px;border-bottom:1px solid #e0e0e0;">PM Duty</th><th rowspan="2" style="text-align:center;padding:8px;background:#f5f5f5;border-bottom:2px solid #e0e0e0;">Status</th></tr><tr style="background:#f9f9f9;font-size:11px;color:#666;"><th style="padding:5px 6px;">Time In</th><th style="padding:5px 6px;">Photo</th><th style="padding:5px 6px;">Time Out</th><th style="padding:5px 6px;">Photo</th><th style="padding:5px 6px;">Time In</th><th style="padding:5px 6px;">Photo</th><th style="padding:5px 6px;">Time Out</th><th style="padding:5px 6px;">Photo</th></table>`;
    }else{
        thead.innerHTML=`<tr><th style="text-align:left;padding:8px 10px;background:#f5f5f5;">#</th><th style="text-align:left;padding:8px 10px;background:#f5f5f5;">Name</th><th style="text-align:center;padding:8px;background:#f5f5f5;">Status</th></tr>`;
    }
    renderAttPage();
}

function getFilteredRows(){
    const sv=document.getElementById('searchInput').value.toLowerCase().trim();
    if(!sv)return _attAllRows;
    return _attAllRows.filter(r=>r.name.toLowerCase().includes(sv));
}

function renderAttPage(){
    const filtered=getFilteredRows(),totalRows=filtered.length,totalPages=Math.max(1,Math.ceil(totalRows/ATT_PAGE_SIZE));
    if(_attPage>=totalPages)_attPage=totalPages-1;
    const start=_attPage*ATT_PAGE_SIZE,end=Math.min(start+ATT_PAGE_SIZE,totalRows),pageRows=filtered.slice(start,end);
    const tbody=document.getElementById('attLogTbody');
    const colSpan=_attIsWeekend?3:11;
    if(totalRows===0){tbody.innerHTML=`<tr><td colspan="${colSpan}" style="text-align:center;padding:30px;color:#aaa;">No students found.</td></tr>`;document.getElementById('attPagination').style.display='none';return;}
    const statusClass={'PRESENT':'present','ABSENT':'absent','INCOMPLETE':'incomplete','DAY OFF':'day-off','PENDING':'pending'};
    const mkTime=v=>{if(!v)return'-';if(v==='missed')return'<span style="color:#e65100;font-weight:700;font-size:11px;">MISSED</span>';return escH(v);};
    const mkPhoto=b64=>b64?`<img src="data:image/jpeg;base64,${b64}" style="width:46px;height:36px;object-fit:cover;border-radius:4px;cursor:pointer;" onclick="openModal(this.src)">`:'-';
    tbody.innerHTML=pageRows.map((r,i)=>{
        // UPDATED (Start = first attendance): no ABSENT / missed before the student's first attendance
        if(!_attIsWeekend && _attData && _attData.date && (r.status==='ABSENT'||r.status==='INCOMPLETE') && isBeforeFirstAttendance(r,_attData.date)){
            r = Object.assign({}, r, {status:'NOT STARTED', am_time_in:null, am_time_out:null, pm_time_in:null, pm_time_out:null});
        }
        // NEW (student schedule): not a duty day for this student and nothing recorded → NOT SCHEDULED, never ABSENT
        if(!_attIsWeekend && _attData && _attData.date && (r.status==='ABSENT'||r.status==='INCOMPLETE')){
            const eid = r.student_id ?? r.user_id ?? r.id;
            const eEnd = (eid !== undefined && eid !== null) ? _ojtEndById[String(eid)] : undefined;
            if (eEnd && _attData.date > eEnd && !['am_time_in','am_time_out','pm_time_in','pm_time_out'].some(k => attHasReal(r[k]))) {
                r = Object.assign({}, r, {status:'OJT COMPLETED', am_time_in:null, am_time_out:null, pm_time_in:null, pm_time_out:null});
            }
        }
        if(!_attIsWeekend && _attData && _attData.date && (r.status==='ABSENT'||r.status==='INCOMPLETE'||r.status==='PRESENT')){
            const ss = schedStatus(r,_attData.date);
            if (ss === 'NOT SCHEDULED') r = Object.assign({}, r, {status:'NOT SCHEDULED', am_time_in:null, am_time_out:null, pm_time_in:null, pm_time_out:null});
            else if (ss) r = Object.assign({}, r, {status:ss});
        }
        const rowNum=start+i+1,cls=(r.status==='NOT STARTED'?'pending':((r.status==='NOT SCHEDULED'||r.status==='OJT COMPLETED')?'nosched':(statusClass[r.status]||'')));
        if(_attIsWeekend){return`<tr class="day-off-row"><td style="padding:8px 6px;text-align:center;color:#aaa;font-size:11px;">${rowNum}</td><td style="padding:8px 10px;">${escH(r.name)}</td><td class="day-off" style="text-align:center;">DAY OFF</td></tr>`;}
        return`<tr><td style="padding:8px 6px;text-align:center;color:#aaa;font-size:11px;white-space:nowrap;">${rowNum}</td><td style="padding:8px 10px;white-space:nowrap;">${escH(r.name)}</td><td style="text-align:center;padding:5px;">${mkTime(r.am_time_in)}</td><td style="text-align:center;padding:5px;">${mkPhoto(r.am_time_in_photo)}</td><td style="text-align:center;padding:5px;">${mkTime(r.am_time_out)}</td><td style="text-align:center;padding:5px;">${mkPhoto(r.am_time_out_photo)}</td><td style="text-align:center;padding:5px;">${mkTime(r.pm_time_in)}</td><td style="text-align:center;padding:5px;">${mkPhoto(r.pm_time_in_photo)}</td><td style="text-align:center;padding:5px;">${mkTime(r.pm_time_out)}</td><td style="text-align:center;padding:5px;">${mkPhoto(r.pm_time_out_photo)}</td><td class="${cls}" style="text-align:center;padding:5px;font-weight:700;">${escH(r.status)}</td></tr>`;
    }).join('');
    renderAttPaginationControls(totalRows,totalPages,start,end);
}

function renderAttPaginationControls(totalRows,totalPages,start,end){
    const pag=document.getElementById('attPagination'),info=document.getElementById('attPaginationInfo'),btnsCon=document.getElementById('attPaginationBtns');
    if(totalPages<=1){pag.style.display='none';return;}
    pag.style.display='flex';
    info.textContent=`Showing ${start+1}–${end} of ${totalRows} students`;
    const cur=_attPage;
    let pages=[];
    if(totalPages<=7){pages=Array.from({length:totalPages},(_,i)=>i);}
    else{
        pages=[0];
        if(cur>2)pages.push('…');
        const rangeStart=Math.max(1,cur-1),rangeEnd=Math.min(totalPages-2,cur+1);
        for(let i=rangeStart;i<=rangeEnd;i++)pages.push(i);
        if(cur<totalPages-3)pages.push('…');
        pages.push(totalPages-1);
    }
    let html=`<button class="att-page-btn" onclick="attGoPage(${cur-1})" ${cur===0?'disabled':''}>&#8249;</button>`;
    pages.forEach(p=>{
        if(p==='…'){html+=`<span class="att-page-ellipsis">…</span>`;}
        else{html+=`<button class="att-page-btn ${p===cur?'active':''}" onclick="attGoPage(${p})">${p+1}</button>`;}
    });
    html+=`<button class="att-page-btn" onclick="attGoPage(${cur+1})" ${cur>=totalPages-1?'disabled':''}>&#8250;</button>`;
    btnsCon.innerHTML=html;
}

function attGoPage(page){
    const filtered=getFilteredRows(),totalPages=Math.max(1,Math.ceil(filtered.length/ATT_PAGE_SIZE));
    if(page<0||page>=totalPages)return;
    _attPage=page; renderAttPage();
    document.getElementById('attLogTableBox').scrollIntoView({behavior:'smooth',block:'nearest'});
}

document.getElementById('searchInput').addEventListener('keyup',function(){
    if(_attAllRows.length===0)return;
    _attPage=0; renderAttPage();
});

// (automatic 60-second late-request polling removed — the inbox loads when it is opened)
</script>
<!-- ══════════════════════════════════════════════════════════════════════
     NEW (OJT trainee group chat) — NEW-MESSAGE POPUP + SIDE-MENU INDICATOR (same as Profile.php)
     ------------------------------------------------------------------------
     When the administrator or a registered OJT trainee writes in the company's chat (the chat itself lives on
     Profile.php: "Admin" and "OJT Trainee Group Chat"), this page shows:
       • the same navy popup ("Admin sent you a new message" / "<Name> sent a message in OJT Trainee Group Chat") —
         clicking it opens that conversation on Profile.php;
       • a live red count (both chats together) on the "My Profile" side-menu link.
     The counts come from Profile.php?chat_unread=1 (admin) and Profile.php?gc_load=1&peek=1 (group) — read-only.
     Same rules as the other popups: messages already waiting when the page opens are the baseline (no popup); seen ids
     are kept briefly in sessionStorage (shared with Profile.php, so moving between pages never repeats a popup);
     checked right away, then every 5 s (paused while the tab is hidden).
     Self-contained: no existing function, poller or style is changed.
     ══════════════════════════════════════════════════════════════════════ -->
<style>
    .sidebar-badge-chat { background:#dc2626; color:#fff; font-weight:800; text-align:center; box-sizing:border-box; min-width:18px; height:18px; padding:0 3px; border-radius:50%; font-size:10px; line-height:18px; display:inline-flex; align-items:center; justify-content:center; position:absolute; right:18px; top:50%; transform:translateY(-50%); animation:ccBadgePulse 2s ease-in-out infinite; }
    .sidebar.collapsed .sidebar-badge-chat { right:14px; top:10px; transform:none; }
    @keyframes ccBadgePulse { 0%, 100% { box-shadow:0 0 0 0 rgba(220,38,38,0.55); } 50% { box-shadow:0 0 0 6px rgba(220,38,38,0); } }
    .cv-top-toast.cc-go { position:fixed; top:30px; left:50%; transform:translateX(-50%); background:#1B2A4A; color:#E3E8F1; border:1px solid #55668C; border-radius:0; padding:14px 20px; box-shadow:0 8px 24px rgba(27,42,74,0.30); display:flex; align-items:center; gap:12px; font-family:'Segoe UI', Tahoma, Geneva, Verdana, sans-serif; font-size:12.5px; line-height:1.45; z-index:10020; max-width:440px; opacity:0; transition:opacity 0.35s, top 0.3s ease, background-color 0.15s ease; pointer-events:auto; cursor:pointer; }
    .cv-top-toast.cc-go.show { opacity:1; }
    .cv-top-toast.cc-go:hover { background:#24375E; }
    .cv-top-toast.cc-go:focus-visible { outline:2px solid #F7C600; outline-offset:2px; }
    .cv-top-toast.cc-go i { color:#8FD18F; font-size:18px; flex-shrink:0; }
    .cv-top-toast.cc-go strong { color:#ffffff; font-weight:700; }
    .cv-top-toast.cc-go .cv-toast-go { flex-shrink:0; margin-left:6px; color:#F7C600; font-size:10.5px; font-weight:700; text-transform:uppercase; letter-spacing:0.4px; white-space:nowrap; }
    .cv-top-toast.cc-go .cv-toast-go i { color:inherit; font-size:9px; margin-left:3px; }
</style>
<script>
(function () {
    'use strict';
    if (window._cvCompanyChatNotifyReady) return;
    window._cvCompanyChatNotifyReady = true;
    var POLL_MS = 5000, TOAST_MS = 7000, STORE_FRESH = 45000;
    var KEYS = { admin: 'cvAdminChatKnownIds', group: 'cvGroupChatKnownIds' };
    var known = { admin: null, group: null }, counts = { admin: 0, group: 0 }, inFlight = false;

    function esc(s) { return String(s == null ? '' : s).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;').replace(/'/g, '&#39;'); }
    function readStore(key) { try { var o = JSON.parse(sessionStorage.getItem(key) || 'null'); if (o && Array.isArray(o.ids) && Date.now() - (o.ts || 0) <= STORE_FRESH) return new Set(o.ids.map(String)); } catch (e) {} return null; }
    function writeStore(conv) { if (!known[conv]) return; try { sessionStorage.setItem(KEYS[conv], JSON.stringify({ ids: Array.from(known[conv]), ts: Date.now() })); } catch (e) {} }
    function layoutToasts() {
        var top = 30, undo = document.getElementById('undoToast');
        if (undo && undo.classList && undo.classList.contains('show')) top = Math.max(top, undo.getBoundingClientRect().bottom + 12);
        document.querySelectorAll('.cv-top-toast').forEach(function (el) { el.style.top = top + 'px'; top += el.offsetHeight + 12; });
    }
    function popup(conv, who, text) {
        var go = function () { window.location.href = 'Profile.php?open_chat=' + conv; };
        var div = document.createElement('div');
        div.className = 'cv-top-toast cc-go'; div.setAttribute('role', 'status'); div.setAttribute('tabindex', '0');
        div.innerHTML = '<i class="fas fa-comment-dots"></i><span><strong>' + esc(who) + '</strong> ' + esc(text) + '</span><span class="cv-toast-go">View <i class="fas fa-chevron-right"></i></span>';
        div.addEventListener('click', go);
        div.addEventListener('keydown', function (e) { if (e.key === 'Enter' || e.key === ' ') { e.preventDefault(); go(); } });
        document.body.appendChild(div); layoutToasts();
        requestAnimationFrame(function () { div.classList.add('show'); });
        setTimeout(function () { div.classList.remove('show'); setTimeout(function () { div.remove(); layoutToasts(); }, 400); }, TOAST_MS);
    }
    function paintBadge() {
        var n = counts.admin + counts.group, b = document.getElementById('sidebarChatBadge');
        if (b) { b.textContent = n > 99 ? '99+' : n; b.style.display = n > 0 ? '' : 'none'; }
    }
    function handle(conv, rows, count) {
        var ids = new Set(rows.map(function (r) { return String(r.id); }));
        counts[conv] = parseInt(count, 10) || 0;
        if (known[conv] === null) {
            var st = readStore(KEYS[conv]);
            if (!st) { known[conv] = ids; writeStore(conv); return; }   // baseline: nothing pops up
            known[conv] = st;
        }
        var fresh = rows.filter(function (r) { return !known[conv].has(String(r.id)); });
        known[conv] = ids; writeStore(conv);
        if (!fresh.length) return;
        if (conv === 'admin') popup('admin', 'Admin', fresh.length > 1 ? 'sent you ' + fresh.length + ' new messages \u2014 open the chat.' : 'sent you a new message \u2014 open the chat.');
        else {
            var names = Array.from(new Set(fresh.map(function (r) { return r.name; })));
            if (names.length === 1) popup('group', names[0], fresh.length > 1 ? 'sent ' + fresh.length + ' messages in OJT Trainee Group Chat.' : 'sent a message in OJT Trainee Group Chat.');
            else popup('group', fresh.length + ' new messages', 'in OJT Trainee Group Chat.');
        }
    }
    function poll() {
        if (inFlight || document.hidden) return;
        inFlight = true;
        var a = fetch('Profile.php?chat_unread=1', { credentials: 'same-origin', cache: 'no-store' }).then(function (r) { return r.json(); })
            .then(function (d) { if (d && d.success && Array.isArray(d.rows)) handle('admin', d.rows, d.count); }).catch(function () {});
        var g = fetch('Profile.php?gc_load=1&peek=1', { credentials: 'same-origin', cache: 'no-store' }).then(function (r) { return r.json(); })
            .then(function (d) { if (d && d.success) handle('group', d.unread_rows || [], d.unread); }).catch(function () {});
        Promise.all([a, g]).then(function () { inFlight = false; paintBadge(); });
    }
    poll();
    setInterval(poll, POLL_MS);
    document.addEventListener('visibilitychange', function () { if (!document.hidden) poll(); });
    window.addEventListener('focus', poll);
    window.addEventListener('pageshow', function (e) { if (e.persisted) poll(); });
    window.addEventListener('pagehide', function () { writeStore('admin'); writeStore('group'); });
})();
</script>
<script>
(function () {
    'use strict';
    if (window._cvCompanyLogoutReady) return;
    window._cvCompanyLogoutReady = true;

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
        var ov = document.getElementById('globalLoadingOverlay'), label = document.getElementById('globalLoadingLabel');
        if (label) label.textContent = 'Logging out';
        if (ov) { ov.classList.add('gl-instant'); ov.classList.remove('hidden'); }
    }
    function hideLoggingOut() {
        var ov = document.getElementById('globalLoadingOverlay'), label = document.getElementById('globalLoadingLabel');
        if (ov) { ov.classList.remove('gl-instant'); ov.classList.add('hidden'); }
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

    // Back / Forward restore: reload from the server so a logged-out visitor is sent to the login page
    window.addEventListener('pageshow', function (e) {
        if (!e.persisted) return;
        loggingOut = false; clearTimeout(stuckTimer);
        overlay.classList.remove('show'); pendingHref = null;
        var ov = document.getElementById('globalLoadingOverlay');
        if (ov) ov.classList.remove('hidden');
        window.location.reload();
    });
})();
</script><!-- ══════════════════════════════════════════════════════════════════════
     NEW (application notification) — NEW-APPLICATION POPUP + SIDE-MENU INDICATOR (same on every company page)
     ------------------------------------------------------------------------
     Designed after administrator.php's company-invitation popup: the navy square .cv-top-toast bar at the TOP of the page
     (slate frame, green icon, names in bold white, "— check the … inbox"), a small "View ›" mark, gone by itself after 7 s,
     several stack downward (newest below), clickable and keyboard-operable (role="link"). When an application reaches this
     company, every company page shows one for each place it lands:
       • "Students Endorsed by the Admin" inbox  → "<Name> was endorsed by the administrator to be added as an OJT trainee —
         check the Students Endorsed by the Admin inbox."
       • "OJT Applicants — Endorsement Letter Validation" table → "<Name> submitted a new application — check the OJT
         Applicants table."
     Clicking a popup fades it out and opens THAT application on add_ojt_student.php — the inbox drawer with the card, or the
     applicants table with the row — and briefly highlights it (from another page it navigates there behind the loading
     page; the one-time link parameter is removed from the address bar so a refresh never repeats it).
     A live count on the "OJT Student List" side-menu link shows every pending application (inbox + table).
     The list comes from add_ojt_student.php?application_alerts=1 (read-only). Applications already waiting when the page opens
     are the baseline (no popup); seen ids are kept briefly in sessionStorage, shared by all company pages, so moving between
     pages neither repeats a popup nor misses one; an application that is only moved from the inbox to the table (already
     known) does not pop up again. Checked right away, then every 5 s (paused while the tab is hidden, slower while the server
     cannot be reached). Self-contained: no existing function, poller or style is changed.
     ══════════════════════════════════════════════════════════════════════ -->
<style>
    .cv-top-toast.ap-notice {
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
    .cv-top-toast.ap-notice.show { opacity: 1; }
    .cv-top-toast.ap-notice i { color: #8FD18F; font-size: 18px; flex-shrink: 0; }
    .cv-top-toast.ap-notice strong { color: #ffffff; font-weight: 700; }
    .cv-top-toast.ap-notice[data-cv-go] { pointer-events: auto; cursor: pointer; transition: opacity 0.35s, top 0.3s ease, background-color 0.15s ease; }
    .cv-top-toast.ap-notice[data-cv-go]:hover { background: #24375E; }
    .cv-top-toast.ap-notice[data-cv-go]:focus-visible { outline: 2px solid #F7C600; outline-offset: 2px; }
    .cv-top-toast.ap-notice .cv-toast-go { flex-shrink: 0; margin-left: 6px; color: #F7C600; font-size: 10.5px; font-weight: 700; text-transform: uppercase; letter-spacing: 0.4px; white-space: nowrap; }
    .cv-top-toast.ap-notice .cv-toast-go i { color: inherit; font-size: 9px; margin-left: 3px; }
    /* the item a popup led to is briefly highlighted when it is shown */
    .ap-go-highlight { outline: 2px solid #F7C600 !important; outline-offset: 2px; animation: apGoFlash 2.6s ease; }
    @keyframes apGoFlash { 0%, 55% { box-shadow: 0 0 0 5px rgba(247, 198, 0, 0.35); } 100% { box-shadow: 0 0 0 0 rgba(247, 198, 0, 0); } }
</style>
<script>
(function () {
    'use strict';
    if (window._cvAppAlertReady) return;
    window._cvAppAlertReady = true;

    var ENDPOINT = 'add_ojt_student.php?application_alerts=1';
    var POLL_MS = 5000, POLL_MAX_MS = 60000, TOAST_MS = 7000, STORE_KEY = 'cvAppKnownIds', STORE_FRESH = 45000, FAIL_STOP = 30;
    var PARAM = 'open_application';   // one-time link parameter: "<inbox|table>:<application id>"
    var known = null, inFlight = false, fails = 0, timer = null, stopped = false;
    var ON_PAGE = /add_ojt_student\.php$/i.test(window.location.pathname);

    function esc(s) { return String(s == null ? '' : s).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;').replace(/'/g, '&#39;'); }
    function readStore() {
        try {
            var o = JSON.parse(sessionStorage.getItem(STORE_KEY) || 'null');
            if (o && Array.isArray(o.ids) && Date.now() - (o.ts || 0) <= STORE_FRESH) return new Set(o.ids.map(String));
        } catch (e) {}
        return null;
    }
    function writeStore() { if (!known) return; try { sessionStorage.setItem(STORE_KEY, JSON.stringify({ ids: Array.from(known), ts: Date.now() })); } catch (e) {} }

    // shares one top-of-page stack with every other .cv-top-toast popup (chat etc.)
    function layoutToasts() {
        var top = 30, undo = document.getElementById('undoToast');
        if (undo && undo.classList && undo.classList.contains('show')) top = Math.max(top, undo.getBoundingClientRect().bottom + 12);
        document.querySelectorAll('.cv-top-toast').forEach(function (el) { el.style.top = top + 'px'; top += el.offsetHeight + 12; });
    }

    /* ── going to the application a popup is about ── */
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
        el.classList.remove('ap-go-highlight'); void el.offsetWidth; el.classList.add('ap-go-highlight');
        setTimeout(function () { el.classList.remove('ap-go-highlight'); }, 2800);
    }
    function goTo(url) {   // the loading page covers the page while it changes (same as administrator.php's popups)
        var ov = document.getElementById('globalLoadingOverlay'), label = document.getElementById('globalLoadingLabel');
        if (label) label.textContent = 'Loading';
        if (ov) { ov.classList.add('gl-instant'); ov.classList.remove('hidden'); }
        window.location.href = url;
    }
    function openHere(kind, id) {
        if (kind === 'inbox') {
            if (typeof window.openInbox === 'function') window.openInbox();
            waitFor(function () { return document.getElementById('appCard' + id); }, 6000).then(function (c) { if (c) setTimeout(function () { highlight(c); }, 250); });
            return;
        }
        // table: the row, once the table has it (it is refreshed every few seconds; ask for it right away)
        if (typeof window.refreshApplicantTable === 'function') { try { window.refreshApplicantTable(); } catch (e) {} }
        waitFor(function () { return document.getElementById('appRow' + id); }, 6000).then(function (row) {
            highlight(row || document.querySelector('.applicants-card'));
        });
    }
    function go(spec) {
        var p = String(spec || '').split(':'), kind = p[0], id = parseInt(p[1], 10) || 0;
        if (kind !== 'inbox' && kind !== 'table') return;
        if (ON_PAGE) { openHere(kind, id); return; }
        goTo('add_ojt_student.php?' + PARAM + '=' + encodeURIComponent(kind + ':' + id));
    }
    if (ON_PAGE) {   // arriving from a popup on another page: do it here once the page has loaded
        try {
            var params = new URLSearchParams(window.location.search), want = params.get(PARAM);
            if (want) {
                params.delete(PARAM);
                if (window.history && window.history.replaceState) {
                    var q = params.toString();
                    window.history.replaceState({}, document.title, window.location.pathname + (q ? '?' + q : '') + window.location.hash);
                }
                var start = function () { setTimeout(function () { go(want); }, 800); };
                if (document.readyState === 'complete') start(); else window.addEventListener('load', start);
            }
        } catch (e) {}
    }

    /* ── the popup (same markup as administrator.php's invitation popup + its "View ›" mark) ── */
    function namesHtml(rows) {
        var names = rows.map(function (r) { return esc(r.name || 'A student'); });
        if (names.length > 3) return '<strong>' + names.length + ' students</strong>';
        return names.length === 1 ? '<strong>' + names[0] + '</strong>'
            : names.slice(0, -1).map(function (n) { return '<strong>' + n + '</strong>'; }).join(', ') + ' and <strong>' + names[names.length - 1] + '</strong>';
    }
    function tagToast(el, spec) {
        el.setAttribute('data-cv-go', spec);
        el.setAttribute('role', 'link');
        el.setAttribute('tabindex', '0');
        el.setAttribute('aria-label', (el.textContent || '').replace(/\s+/g, ' ').trim() + ' — open');
        var hint = document.createElement('span');
        hint.className = 'cv-toast-go';
        hint.setAttribute('aria-hidden', 'true');
        hint.innerHTML = 'View <i class="fas fa-chevron-right"></i>';
        el.appendChild(hint);
    }
    function activate(toast) {   // fade out, then open the application
        var spec = toast.getAttribute('data-cv-go');
        toast.classList.remove('show');
        setTimeout(function () { if (toast.parentNode) toast.parentNode.removeChild(toast); layoutToasts(); }, 350);
        go(spec);
    }
    function popup(kind, rows) {
        var one = rows.length === 1, who = namesHtml(rows);
        var plural = rows.length > 1;
        var msg = kind === 'inbox'
            ? who + ' ' + (plural ? 'were' : 'was') + ' endorsed by the administrator to be added as ' + (plural ? 'OJT trainees' : 'an OJT trainee') + ' — check the Students Endorsed by the Admin inbox.'
            : who + ' submitted ' + (plural ? 'new applications' : 'a new application') + ' — check the OJT Applicants table.';
        var div = document.createElement('div');
        div.className = 'cv-top-toast ap-notice';
        div.setAttribute('role', 'status');
        div.innerHTML = '<i class="fas ' + (kind === 'inbox' ? 'fa-user-plus' : 'fa-envelope-open-text') + '"></i><span>' + msg + '</span>';
        document.body.appendChild(div);
        tagToast(div, kind + ':' + (parseInt(rows[0].id, 10) || 0));   // clickable → that application
        layoutToasts();
        requestAnimationFrame(function () { div.classList.add('show'); });
        setTimeout(function () { div.classList.remove('show'); setTimeout(function () { div.remove(); layoutToasts(); }, 400); }, TOAST_MS);
    }
    document.addEventListener('click', function (e) {
        var t = e.target && e.target.closest ? e.target.closest('.cv-top-toast.ap-notice[data-cv-go]') : null;
        if (t) { e.preventDefault(); activate(t); }
    });
    document.addEventListener('keydown', function (e) {
        if (e.key !== 'Enter' && e.key !== ' ') return;
        var t = e.target && e.target.closest ? e.target.closest('.cv-top-toast.ap-notice[data-cv-go]') : null;
        if (t) { e.preventDefault(); activate(t); }
    });

    /* ── side-menu indicator ── */
    function paintBadge(count) {
        count = parseInt(count, 10) || 0;
        var b = document.getElementById('sidebarAppBadge') || document.getElementById('sidebarInboxBadge');
        if (!b) return;   // e.g. the locked sidebar of CompanyForm.php has no "OJT Student List" link
        b.textContent = count > 99 ? '99+' : String(count);
        b.style.display = count > 0 ? 'inline-flex' : 'none';
    }

    function handle(d) {
        var rows = d.rows, ids = new Set(rows.map(function (r) { return String(r.id); }));
        paintBadge(d.count != null ? d.count : rows.length);
        if (known === null) {
            var stored = readStore();
            if (!stored) { known = ids; writeStore(); return; }      // baseline: nothing pops up
            known = stored;
        }
        var fresh = rows.filter(function (r) { return !known.has(String(r.id)); });
        known = ids; writeStore();                                  // an inbox → table move keeps its id, so it is never "new"
        var inbox = fresh.filter(function (r) { return !parseInt(r.in_table, 10); });
        var table = fresh.filter(function (r) { return parseInt(r.in_table, 10); });
        if (inbox.length) popup('inbox', inbox);
        if (table.length) popup('table', table);
    }

    /* ── polling ── */
    function schedule() {
        clearTimeout(timer);
        if (stopped) return;
        timer = setTimeout(poll, Math.min(POLL_MAX_MS, POLL_MS * Math.pow(2, Math.min(fails, 4))));
    }
    function poll() {
        if (stopped) return;
        if (inFlight || document.hidden) { schedule(); return; }
        inFlight = true;
        fetch(ENDPOINT, { credentials: 'same-origin', cache: 'no-store' })
            .then(function (r) { if (!r.ok) throw new Error('http ' + r.status); return r.json(); })
            .then(function (d) {
                if (!d || !d.success || !Array.isArray(d.rows)) throw new Error('bad reply');
                fails = 0; handle(d);
            })
            .catch(function () { fails++; if (fails >= FAIL_STOP) stopped = true; })   // e.g. the session ended: stop asking
            .then(function () { inFlight = false; schedule(); });
    }
    function kick() { if (stopped || document.hidden || (known === null && inFlight)) return; clearTimeout(timer); poll(); }

    poll();
    document.addEventListener('visibilitychange', function () { if (!document.hidden && known !== null) kick(); });
    window.addEventListener('focus', function () { if (known !== null) kick(); });
    window.addEventListener('online', function () { fails = 0; kick(); });
    window.addEventListener('pageshow', function (e) { if (e.persisted) kick(); });
    window.addEventListener('pagehide', writeStore);
})();
</script>
<!-- ══════════════════════════════════════════════════════════════════════
     NEW (weekly report notification) — NEW-REPORT POPUP + SIDE-MENU INDICATOR (same on every company page)
     ------------------------------------------------------------------------
     When a student submits a weekly report, every company page shows the same navy popup as the application notification
     ("<Name> submitted a weekly report (Oct 05 – Oct 09) — check the Company Reports."), and the "Company Reports" side-menu
     link shows how many reports have not been opened yet. On company_reports.php the same news also puts a "N new" badge on
     the student's card and a "NEW" tag on the report inside the student's library; all of them disappear as soon as the report
     is opened. Clicking the popup opens that student's library on company_reports.php and highlights the report (from another
     page it navigates there behind the loading page; the one-time link parameter is removed from the address bar).
     The list comes from company_reports.php?report_alerts=1 (read-only). Reports already waiting when the page opens are the
     baseline (no popup); seen ids are kept briefly in sessionStorage, shared by all company pages, so moving between pages
     neither repeats a popup nor misses one. Checked right away, then every 6 s (paused while the tab is hidden, slower while
     the server cannot be reached). Self-contained: no existing function, poller or style is changed.
     ══════════════════════════════════════════════════════════════════════ -->
<style>
    .cv-top-toast.rp-notice {
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
    .cv-top-toast.rp-notice.show { opacity: 1; }
    .cv-top-toast.rp-notice i { color: #8FD18F; font-size: 18px; flex-shrink: 0; }
    .cv-top-toast.rp-notice strong { color: #ffffff; font-weight: 700; }
    .cv-top-toast.rp-notice[data-cv-go] { pointer-events: auto; cursor: pointer; transition: opacity 0.35s, top 0.3s ease, background-color 0.15s ease; }
    .cv-top-toast.rp-notice[data-cv-go]:hover { background: #24375E; }
    .cv-top-toast.rp-notice[data-cv-go]:focus-visible { outline: 2px solid #F7C600; outline-offset: 2px; }
    .cv-top-toast.rp-notice .cv-toast-go { flex-shrink: 0; margin-left: 6px; color: #F7C600; font-size: 10.5px; font-weight: 700; text-transform: uppercase; letter-spacing: 0.4px; white-space: nowrap; }
    .cv-top-toast.rp-notice .cv-toast-go i { color: inherit; font-size: 9px; margin-left: 3px; }
    /* the item a popup led to is briefly highlighted when it is shown */
    .rp-go-highlight { outline: 2px solid #F7C600 !important; outline-offset: 2px; animation: rpGoFlash 2.6s ease; }
    @keyframes rpGoFlash { 0%, 55% { box-shadow: 0 0 0 5px rgba(247, 198, 0, 0.35); } 100% { box-shadow: 0 0 0 0 rgba(247, 198, 0, 0); } }
</style>
<script>
(function () {
    'use strict';
    if (window._cvReportAlertReady) return;
    window._cvReportAlertReady = true;

    var ENDPOINT = 'company_reports.php?report_alerts=1';
    var POLL_MS = 6000, POLL_MAX_MS = 60000, TOAST_MS = 7000, STORE_KEY = 'cvReportKnownIds', STORE_FRESH = 45000, FAIL_STOP = 30;
    var PARAM = 'open_report';   // one-time link parameter: "<report id>:<student id>"
    var known = null, inFlight = false, fails = 0, timer = null, stopped = false;
    var ON_PAGE = /company_reports\.php$/i.test(window.location.pathname);

    function esc(s) { return String(s == null ? '' : s).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;').replace(/'/g, '&#39;'); }
    function readStore() {
        try {
            var o = JSON.parse(sessionStorage.getItem(STORE_KEY) || 'null');
            if (o && Array.isArray(o.ids) && Date.now() - (o.ts || 0) <= STORE_FRESH) return new Set(o.ids.map(String));
        } catch (e) {}
        return null;
    }
    function writeStore() { if (!known) return; try { sessionStorage.setItem(STORE_KEY, JSON.stringify({ ids: Array.from(known), ts: Date.now() })); } catch (e) {} }

    // shares one top-of-page stack with every other .cv-top-toast popup (chat, applications, action toasts)
    function layoutToasts() {
        var top = 30, undo = document.getElementById('undoToast');
        if (undo && undo.classList && undo.classList.contains('show')) top = Math.max(top, undo.getBoundingClientRect().bottom + 12);
        document.querySelectorAll('.cv-top-toast').forEach(function (el) { el.style.top = top + 'px'; top += el.offsetHeight + 12; });
    }

    /* ── going to the report a popup is about ── */
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
        el.classList.remove('rp-go-highlight'); void el.offsetWidth; el.classList.add('rp-go-highlight');
        setTimeout(function () { el.classList.remove('rp-go-highlight'); }, 2800);
    }
    function goTo(url) {   // the loading page covers the page while it changes (same as the other popups)
        var ov = document.getElementById('globalLoadingOverlay'), label = document.getElementById('globalLoadingLabel');
        if (label) label.textContent = 'Loading';
        if (ov) { ov.classList.add('gl-instant'); ov.classList.remove('hidden'); }
        window.location.href = url;
    }
    function openHere(rid, sid) {
        var opened = false;
        if (typeof window.cvOpenReportFromAlert === 'function') { try { opened = window.cvOpenReportFromAlert(rid, sid) !== false; } catch (e) { opened = false; } }
        if (!opened) { highlight(document.getElementById('scard-' + sid)); return; }   // the student is not on this list any more
        waitFor(function () { return document.getElementById('list-item-' + rid); }, 6000).then(function (item) {
            if (item) setTimeout(function () { highlight(item); }, 250); else highlight(document.getElementById('scard-' + sid));
        });
    }
    function go(spec) {
        var p = String(spec || '').split(':'), rid = parseInt(p[1], 10) || 0, sid = parseInt(p[2], 10) || 0;
        if (p[0] !== 'report' || !rid || !sid) return;
        if (ON_PAGE) { openHere(rid, sid); return; }
        goTo('company_reports.php?' + PARAM + '=' + encodeURIComponent(rid + ':' + sid));
    }
    if (ON_PAGE) {   // arriving from a popup on another page: do it here once the page has loaded
        try {
            var params = new URLSearchParams(window.location.search), want = params.get(PARAM);
            if (want) {
                params.delete(PARAM);
                if (window.history && window.history.replaceState) {
                    var q = params.toString();
                    window.history.replaceState({}, document.title, window.location.pathname + (q ? '?' + q : '') + window.location.hash);
                }
                var wp = String(want).split(':');
                var start = function () { setTimeout(function () { go('report:' + wp[0] + ':' + wp[1]); }, 800); };
                if (document.readyState === 'complete') start(); else window.addEventListener('load', start);
            }
        } catch (e) {}
    }

    /* ── the popup (same markup as the application popup + its "View ›" mark) ── */
    function fmtWeek(ws) {
        try {
            var m = new Date(String(ws) + 'T00:00:00'), f = new Date(m.getTime()); f.setDate(f.getDate() + 4);
            if (isNaN(m.getTime())) return '';
            var o = { month: 'short', day: '2-digit' };
            return m.toLocaleDateString('en-US', o) + ' – ' + f.toLocaleDateString('en-US', o);
        } catch (e) { return ''; }
    }
    function tagToast(el, spec) {
        el.setAttribute('data-cv-go', spec);
        el.setAttribute('role', 'link');
        el.setAttribute('tabindex', '0');
        el.setAttribute('aria-label', (el.textContent || '').replace(/\s+/g, ' ').trim() + ' — open');
        var hint = document.createElement('span');
        hint.className = 'cv-toast-go';
        hint.setAttribute('aria-hidden', 'true');
        hint.innerHTML = 'View <i class="fas fa-chevron-right"></i>';
        el.appendChild(hint);
    }
    function activate(toast) {   // fade out, then open the report
        var spec = toast.getAttribute('data-cv-go');
        toast.classList.remove('show');
        setTimeout(function () { if (toast.parentNode) toast.parentNode.removeChild(toast); layoutToasts(); }, 350);
        go(spec);
    }
    function popup(rows) {
        // one popup per batch of new reports: the students in bold, what they submitted, where to look
        var students = [], seen = {};
        rows.forEach(function (r) { var k = String(r.student_id); if (!seen[k]) { seen[k] = true; students.push(r); } });
        var names = students.map(function (r) { return '<strong>' + esc(r.name || 'A student') + '</strong>'; });
        var who = students.length > 3 ? '<strong>' + students.length + ' students</strong>'
            : (names.length === 1 ? names[0] : names.slice(0, -1).join(', ') + ' and ' + names[names.length - 1]);
        var what = rows.length === 1
            ? 'a weekly report' + (fmtWeek(rows[0].week_start) ? ' (' + esc(fmtWeek(rows[0].week_start)) + ')' : '')
            : rows.length + ' new weekly reports';
        var div = document.createElement('div');
        div.className = 'cv-top-toast rp-notice';
        div.setAttribute('role', 'status');
        div.innerHTML = '<i class="fas fa-file-lines"></i><span>' + who + ' submitted ' + what + ' — check the Company Reports.</span>';
        document.body.appendChild(div);
        tagToast(div, 'report:' + (parseInt(rows[0].id, 10) || 0) + ':' + (parseInt(rows[0].student_id, 10) || 0));   // clickable → the newest report
        layoutToasts();
        requestAnimationFrame(function () { div.classList.add('show'); });
        setTimeout(function () { div.classList.remove('show'); setTimeout(function () { div.remove(); layoutToasts(); }, 400); }, TOAST_MS);
    }
    document.addEventListener('click', function (e) {
        var t = e.target && e.target.closest ? e.target.closest('.cv-top-toast.rp-notice[data-cv-go]') : null;
        if (t) { e.preventDefault(); activate(t); }
    });
    document.addEventListener('keydown', function (e) {
        if (e.key !== 'Enter' && e.key !== ' ') return;
        var t = e.target && e.target.closest ? e.target.closest('.cv-top-toast.rp-notice[data-cv-go]') : null;
        if (t) { e.preventDefault(); activate(t); }
    });

    /* ── side-menu indicator ── */
    function paintBadge(count) {
        count = parseInt(count, 10) || 0;
        var b = document.getElementById('sidebarReportBadge');
        if (!b) return;
        b.textContent = count > 99 ? '99+' : String(count);
        b.style.display = count > 0 ? 'inline-flex' : 'none';
    }

    function handle(d) {
        var rows = d.rows, ids = new Set(rows.map(function (r) { return String(r.id); }));
        var shown = d.count != null ? d.count : rows.length;
        if (typeof window.cvAdjustedReportCount === 'function') { try { shown = window.cvAdjustedReportCount(d, shown); } catch (e) {} }
        paintBadge(shown);
        if (typeof window.cvApplyReportAlerts === 'function') { try { window.cvApplyReportAlerts(d); } catch (e) {} }   // company_reports.php: student cards + library
        if (known === null) {
            var stored = readStore();
            if (!stored) { known = ids; writeStore(); return; }      // baseline: nothing pops up
            known = stored;
        }
        var fresh = rows.filter(function (r) { return !known.has(String(r.id)); });
        known = ids; writeStore();
        if (fresh.length) popup(fresh);
    }

    /* ── polling ── */
    function schedule() {
        clearTimeout(timer);
        if (stopped) return;
        timer = setTimeout(poll, Math.min(POLL_MAX_MS, POLL_MS * Math.pow(2, Math.min(fails, 4))));
    }
    function poll() {
        if (stopped) return;
        if (inFlight || document.hidden) { schedule(); return; }
        inFlight = true;
        fetch(ENDPOINT, { credentials: 'same-origin', cache: 'no-store' })
            .then(function (r) { if (!r.ok) throw new Error('http ' + r.status); return r.json(); })
            .then(function (d) {
                if (!d || !d.success || !Array.isArray(d.rows)) throw new Error('bad reply');
                fails = 0; handle(d);
            })
            .catch(function () { fails++; if (fails >= FAIL_STOP) stopped = true; })   // e.g. the session ended: stop asking
            .then(function () { inFlight = false; schedule(); });
    }
    function kick() { if (stopped || document.hidden || (known === null && inFlight)) return; clearTimeout(timer); poll(); }
    window.cvReportAlertsRefresh = kick;   // lets company_reports.php refresh the indicators right after a report is opened

    poll();
    document.addEventListener('visibilitychange', function () { if (!document.hidden && known !== null) kick(); });
    window.addEventListener('focus', function () { if (known !== null) kick(); });
    window.addEventListener('online', function () { fails = 0; kick(); });
    window.addEventListener('pageshow', function (e) { if (e.persisted) kick(); });
    window.addEventListener('pagehide', writeStore);
})();
</script>
<!-- ══════════════════════════════════════════════════════════════════════
     Evaluation-ready popup + side-menu indicator (included at the end of every company page)
     ------------------------------------------------------------------------
     When a trainee reaches the course's Total Hour Requirement the Evaluate button on company_reports.php unlocks. Every company
     page then shows the same navy popup ("<Name> completed the required hours (486 hrs) — you can now evaluate.") and a red
     count on the "Company Reports" side-menu link; both clear once the student is evaluated. Clicking the popup opens
     company_reports.php and highlights the student's Evaluate button.
     Data: company_reports.php?eval_alerts=1 (read-only). The popup appears once each time a trainee reaches the Total Hour Requirement
     (the server remembers the announcement, so it is not repeated on later page loads or other pages). The count stays until the trainee is evaluated. Checked right away, then every
     10 s (paused while the tab is hidden, slower while the server cannot be reached). Self-contained: no existing function,
     poller or style is changed; the indicator is added to the existing "Company Reports" link by script.
     ══════════════════════════════════════════════════════════════════════ -->
<style>
    .cv-top-toast.ev-notice {
        position: fixed; top: 30px; left: 50%; transform: translateX(-50%);
        background: #1B2A4A; color: #E3E8F1;
        border: 1px solid #55668C; border-radius: 0;
        padding: 14px 20px;
        box-shadow: 0 8px 24px rgba(27,42,74,0.30);
        display: flex; align-items: center; gap: 12px;
        font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
        font-size: 12.5px; line-height: 1.45;
        z-index: 10020; max-width: 440px;
        opacity: 0; transition: opacity 0.35s, top 0.3s ease, background-color 0.15s ease;
        pointer-events: auto; cursor: pointer;
    }
    .cv-top-toast.ev-notice.show { opacity: 1; }
    .cv-top-toast.ev-notice:hover { background: #24375E; }
    .cv-top-toast.ev-notice:focus-visible { outline: 2px solid #F7C600; outline-offset: 2px; }
    .cv-top-toast.ev-notice i { color: #8FD18F; font-size: 18px; flex-shrink: 0; }
    .cv-top-toast.ev-notice strong { color: #ffffff; font-weight: 700; }
    .cv-top-toast.ev-notice .cv-toast-go { flex-shrink: 0; margin-left: 6px; color: #F7C600; font-size: 10.5px; font-weight: 700; text-transform: uppercase; letter-spacing: 0.4px; white-space: nowrap; }
    .cv-top-toast.ev-notice .cv-toast-go i { color: inherit; font-size: 9px; margin-left: 3px; }
    .sidebar-badge-eval {
        background: #dc2626; color: #fff; border-radius: 50%; min-width: 18px; height: 18px; padding: 0 3px;
        font-size: 10px; font-weight: 700; display: inline-flex; align-items: center; justify-content: center;
        position: absolute; right: 44px; top: 50%; transform: translateY(-50%);
        animation: evBadgePulse 2s ease-in-out infinite;
    }
    .sidebar.collapsed .sidebar-badge-eval { right: 14px; top: auto; bottom: 4px; transform: none; }
    @keyframes evBadgePulse { 0%, 100% { box-shadow: 0 0 0 0 rgba(220,38,38,0.55); } 50% { box-shadow: 0 0 0 6px rgba(220,38,38,0); } }
    .ev-go-highlight { outline: 2px solid #F7C600 !important; outline-offset: 2px; animation: evGoFlash 2.6s ease; }
    @keyframes evGoFlash { 0%, 55% { box-shadow: 0 0 0 5px rgba(247, 198, 0, 0.35); } 100% { box-shadow: 0 0 0 0 rgba(247, 198, 0, 0); } }
</style>
<script>
(function () {
    'use strict';
    if (window._cvEvalAlertReady) return;
    window._cvEvalAlertReady = true;

    var ENDPOINT = 'company_reports.php?eval_alerts=1';
    var POLL_MS = 10000, POLL_MAX_MS = 60000, TOAST_MS = 8000, FAIL_STOP = 30;
    var PARAM = 'open_eval';   // one-time link parameter: <student id>
    var inFlight = false, fails = 0, timer = null, stopped = false;
    var ON_PAGE = /company_reports\.php$/i.test(window.location.pathname);

    function esc(s) { return String(s == null ? '' : s).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;').replace(/'/g, '&#39;'); }
    function fmtH(h) { h = parseFloat(h) || 0; return (h === Math.floor(h)) ? String(h) : String(Math.round(h * 100) / 100); }

    // shares the top-of-page stack with every other .cv-top-toast popup
    function layoutToasts() {
        var top = 30, undo = document.getElementById('undoToast');
        if (undo && undo.classList && undo.classList.contains('show')) top = Math.max(top, undo.getBoundingClientRect().bottom + 12);
        document.querySelectorAll('.cv-top-toast').forEach(function (el) { el.style.top = top + 'px'; top += el.offsetHeight + 12; });
    }

    /* ── going to the student's Evaluate button ── */
    function highlight(sid) {
        var el = document.getElementById('sc-eval-btn-' + sid), card = document.getElementById('scard-' + sid);
        if (!el && !card) return;
        try { (card || el).scrollIntoView({ behavior: 'smooth', block: 'center' }); } catch (e) { (card || el).scrollIntoView(); }
        [card, el].forEach(function (n) {
            if (!n) return;
            n.classList.remove('ev-go-highlight'); void n.offsetWidth; n.classList.add('ev-go-highlight');
            setTimeout(function () { n.classList.remove('ev-go-highlight'); }, 2800);
        });
    }
    function goTo(url) {
        var ov = document.getElementById('globalLoadingOverlay'), label = document.getElementById('globalLoadingLabel');
        if (label) label.textContent = 'Loading';
        if (ov) { ov.classList.add('gl-instant'); ov.classList.remove('hidden'); }
        window.location.href = url;
    }
    function go(sid) {
        sid = parseInt(sid, 10) || 0;
        if (!sid) return;
        if (ON_PAGE) highlight(sid); else goTo('company_reports.php?' + PARAM + '=' + encodeURIComponent(sid));
    }
    if (ON_PAGE) {   // arriving from a popup on another page
        try {
            var params = new URLSearchParams(window.location.search), want = params.get(PARAM);
            if (want) {
                params.delete(PARAM);
                if (window.history && window.history.replaceState) {
                    var q = params.toString();
                    window.history.replaceState({}, document.title, window.location.pathname + (q ? '?' + q : '') + window.location.hash);
                }
                var start = function () { setTimeout(function () { highlight(parseInt(want, 10) || 0); }, 800); };
                if (document.readyState === 'complete') start(); else window.addEventListener('load', start);
            }
        } catch (e) {}
    }

    /* ── the popup ── */
    function popup(rows) {
        var names = rows.map(function (r) { return '<strong>' + esc(r.name || 'A trainee') + '</strong>'; });
        var who = rows.length > 3 ? '<strong>' + rows.length + ' trainees</strong>'
            : (names.length === 1 ? names[0] : names.slice(0, -1).join(', ') + ' and ' + names[names.length - 1]);
        var what = rows.length === 1
            ? 'completed the required ' + esc(fmtH(rows[0].required)) + ' hours — you can now evaluate.'
            : 'completed the required hours — you can now evaluate.';
        var div = document.createElement('div');
        div.className = 'cv-top-toast ev-notice';
        div.setAttribute('role', 'link');
        div.setAttribute('tabindex', '0');
        div.setAttribute('data-ev-sid', String(parseInt(rows[0].student_id, 10) || 0));
        div.innerHTML = '<i class="fas fa-clipboard-check"></i><span>' + who + ' ' + what + '</span><span class="cv-toast-go" aria-hidden="true">Evaluate <i class="fas fa-chevron-right"></i></span>';
        document.body.appendChild(div);
        layoutToasts();
        requestAnimationFrame(function () { div.classList.add('show'); });
        setTimeout(function () { div.classList.remove('show'); setTimeout(function () { if (div.parentNode) div.parentNode.removeChild(div); layoutToasts(); }, 400); }, TOAST_MS);
    }
    function activate(toast) {
        var sid = toast.getAttribute('data-ev-sid');
        toast.classList.remove('show');
        setTimeout(function () { if (toast.parentNode) toast.parentNode.removeChild(toast); layoutToasts(); }, 350);
        go(sid);
    }
    document.addEventListener('click', function (e) {
        var t = e.target && e.target.closest ? e.target.closest('.cv-top-toast.ev-notice') : null;
        if (t) { e.preventDefault(); activate(t); }
    });
    document.addEventListener('keydown', function (e) {
        if (e.key !== 'Enter' && e.key !== ' ') return;
        var t = e.target && e.target.closest ? e.target.closest('.cv-top-toast.ev-notice') : null;
        if (t) { e.preventDefault(); activate(t); }
    });

    /* ── side-menu indicator (added to the existing "Company Reports" link) ── */
    function paintBadge(count) {
        count = parseInt(count, 10) || 0;
        var b = document.getElementById('sidebarEvalBadge');
        if (!b) {
            var link = document.querySelector('.sidebar-links a[href="company_reports.php"]');
            if (!link) return;
            if (window.getComputedStyle(link).position === 'static') link.style.position = 'relative';
            b = document.createElement('span');
            b.id = 'sidebarEvalBadge';
            b.className = 'sidebar-badge-eval';
            b.style.display = 'none';
            b.setAttribute('title', 'Trainees ready for evaluation');
            link.appendChild(b);
        }
        b.textContent = count > 99 ? '99+' : String(count);
        b.style.display = count > 0 ? 'inline-flex' : 'none';
    }

    function handle(d) {
        var rows = d.rows;
        paintBadge(d.count != null ? d.count : rows.length);
        if (typeof window.cvApplyEvalAlerts === 'function') { try { window.cvApplyEvalAlerts(d); } catch (e) {} }   // company_reports.php: Evaluate buttons
        var fresh = Array.isArray(d.fresh) ? d.fresh : [];   // trainees that reached the Total Hour Requirement since the last announcement (the server hands each one out exactly once)
        if (fresh.length) popup(fresh);
    }

    /* ── polling ── */
    function schedule() {
        clearTimeout(timer);
        if (stopped) return;
        timer = setTimeout(poll, Math.min(POLL_MAX_MS, POLL_MS * Math.pow(2, Math.min(fails, 3))));
    }
    function poll() {
        if (stopped) return;
        if (inFlight || document.hidden) { schedule(); return; }
        inFlight = true;
        fetch(ENDPOINT, { credentials: 'same-origin', cache: 'no-store' })
            .then(function (r) { if (!r.ok) throw new Error('http ' + r.status); return r.json(); })
            .then(function (d) {
                if (!d || !d.success || !Array.isArray(d.rows)) throw new Error('bad reply');
                fails = 0; handle(d);
            })
            .catch(function () { fails++; if (fails >= FAIL_STOP) stopped = true; })   // e.g. the session ended: stop asking
            .then(function () { inFlight = false; schedule(); });
    }
    function kick() { if (stopped || document.hidden || inFlight) return; clearTimeout(timer); poll(); }
    window.cvEvalAlertsRefresh = kick;

    poll();
    document.addEventListener('visibilitychange', function () { if (!document.hidden) kick(); });
    window.addEventListener('focus', kick);
    window.addEventListener('online', function () { fails = 0; kick(); });
    window.addEventListener('pageshow', function (e) { if (e.persisted) kick(); });
})();
</script>
<?php /* NEW (OJT Program Cycle): popup — see ojt_cycle_popup.php */ $ojc_popup_mode = 'company'; include __DIR__ . '/ojt_cycle_popup.php'; ?>
</body>
</html>
