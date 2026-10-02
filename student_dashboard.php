<?php
session_start();
include "db.php";

if (!isset($_SESSION['user_id']) || $_SESSION['role'] != "student") {
    die("Access denied.");
}

$user_id = $_SESSION['user_id'];
date_default_timezone_set("Asia/Manila");

// ================= ATTENDANCE SIDEBAR BADGE + POPUP INFO =================
$att_sidebar_badge     = false;
$attendance_badge_info = null;
$_att_today_settings   = null;
$_att_is_weekend       = false;
$_att_all_done         = false;

$_att_dow        = (int)date('w');
$_att_is_weekend = ($_att_dow === 0 || $_att_dow === 6);

$_att_ca = $conn->prepare("SELECT company_id FROM ojt_assignments WHERE student_id=? LIMIT 1");
$_att_ca->bind_param("i", $user_id);
$_att_ca->execute();
$_att_cr = $_att_ca->get_result()->fetch_assoc();
$_att_ca->close();

if ($_att_cr && !$_att_is_weekend) {
    $_att_company_id = $_att_cr['company_id'];
    $_att_date       = date("Y-m-d");
    $_att_now        = date("H:i:s");

    $_att_ss = $conn->prepare("SELECT * FROM attendance_settings WHERE company_id=? AND date=? LIMIT 1");
    $_att_ss->bind_param("is", $_att_company_id, $_att_date);
    $_att_ss->execute();
    $_att_setting = $_att_ss->get_result()->fetch_assoc();
    $_att_ss->close();

    if (!$_att_setting) {
        $_att_sf = $conn->prepare("SELECT * FROM attendance_settings WHERE company_id=? AND is_auto=1 AND date<=? ORDER BY date DESC LIMIT 1");
        $_att_sf->bind_param("is", $_att_company_id, $_att_date);
        $_att_sf->execute();
        $_att_setting = $_att_sf->get_result()->fetch_assoc();
        $_att_sf->close();
    }

    if ($_att_setting) {
        $_att_today_settings = [
            'am_time_in_start'  => $_att_setting['am_time_in_start'],
            'am_time_in_end'    => $_att_setting['am_time_in_end'],
            'am_time_out_start' => $_att_setting['am_time_out_start'],
            'am_time_out_end'   => $_att_setting['am_time_out_end'],
            'pm_time_in_start'  => $_att_setting['pm_time_in_start'],
            'pm_time_in_end'    => $_att_setting['pm_time_in_end'],
            'pm_time_out_start' => $_att_setting['pm_time_out_start'],
            'pm_time_out_end'   => $_att_setting['pm_time_out_end'],
        ];

        $_att_log_s = $conn->prepare("SELECT am_time_in, am_time_out, pm_time_in, pm_time_out FROM attendance_logs WHERE user_id=? AND date=? AND company_id=?");
        $_att_log_s->bind_param("isi", $user_id, $_att_date, $_att_company_id);
        $_att_log_s->execute();
        $_att_log = $_att_log_s->get_result()->fetch_assoc();
        $_att_log_s->close();

        $fmt12att = function($t) {
            if (!$t) return null;
            $parts = explode(':', $t);
            $h = (int)$parts[0]; $m = (int)$parts[1];
            $ampm = $h >= 12 ? 'PM' : 'AM';
            $h12  = $h % 12 ?: 12;
            return sprintf('%d:%02d %s', $h12, $m, $ampm);
        };

        $timeToSec = function($t) {
            if (!$t) return -1;
            $p = explode(':', $t);
            return (int)$p[0] * 3600 + (int)$p[1] * 60 + (isset($p[2]) ? (int)$p[2] : 0);
        };

        $_att_now_sec = $timeToSec($_att_now);

        // Check all-done flag
        $_att_all_done = $_att_log && is_array($_att_log)
            && ($_att_log['am_time_in']  !== null && $_att_log['am_time_in']  !== '' && $_att_log['am_time_in']  !== 'missed')
            && ($_att_log['am_time_out'] !== null && $_att_log['am_time_out'] !== '' && $_att_log['am_time_out'] !== 'missed')
            && ($_att_log['pm_time_in']  !== null && $_att_log['pm_time_in']  !== '' && $_att_log['pm_time_in']  !== 'missed')
            && ($_att_log['pm_time_out'] !== null && $_att_log['pm_time_out'] !== '' && $_att_log['pm_time_out'] !== 'missed');

        $_att_windows = [
            'am_time_in'  => ['label' => 'AM Duty Sign In',  'start' => $_att_setting['am_time_in_start'],  'end' => $_att_setting['am_time_in_end']],
            'am_time_out' => ['label' => 'AM Duty Sign Out', 'start' => $_att_setting['am_time_out_start'], 'end' => $_att_setting['am_time_out_end']],
            'pm_time_in'  => ['label' => 'PM Duty Sign In',  'start' => $_att_setting['pm_time_in_start'],  'end' => $_att_setting['pm_time_in_end']],
            'pm_time_out' => ['label' => 'PM Duty Sign Out', 'start' => $_att_setting['pm_time_out_start'], 'end' => $_att_setting['pm_time_out_end']],
        ];

        // Pass 1: check normal windows
        foreach ($_att_windows as $type => $winfo) {
            if (!$winfo['start'] || !$winfo['end']) continue;

            $start_sec = $timeToSec($winfo['start']);
            $end_sec   = $timeToSec($winfo['end']);

            if ($_att_now_sec >= $start_sec && $_att_now_sec <= $end_sec) {
                $val = (is_array($_att_log) && array_key_exists($type, $_att_log))
                    ? $_att_log[$type]
                    : null;

                $already_done = ($val !== null && $val !== '' && $val !== 'missed');

                if (!$already_done && !$_att_all_done) {
                    $att_sidebar_badge     = true;
                    $attendance_badge_info = [
                        'type'           => $type,
                        'label'          => $winfo['label'],
                        'start_fmt'      => $fmt12att($winfo['start']),
                        'end_fmt'        => $fmt12att($winfo['end']),
                        'start_time'     => $winfo['start'],
                        'end_time'       => $winfo['end'],
                        'is_late_window' => false,
                    ];
                    break;
                }
            }
        }

        // Pass 2: check PM Sign Out 1-hour late window (mirrors student_attendance.php)
        if (!$attendance_badge_info && !$_att_all_done) {
            $_pm_out_end = $_att_setting['pm_time_out_end'] ?? null;
            if ($_pm_out_end) {
                $_pm_out_end_sec   = $timeToSec($_pm_out_end);
                $_late_window_end  = $_pm_out_end_sec + 3600;
                if ($_att_now_sec > $_pm_out_end_sec && $_att_now_sec <= $_late_window_end) {
                    $_pm_out_val     = (is_array($_att_log) && array_key_exists('pm_time_out', $_att_log))
                        ? $_att_log['pm_time_out']
                        : null;
                    $_pm_already_done = ($_pm_out_val !== null && $_pm_out_val !== '' && $_pm_out_val !== 'missed');
                    if (!$_pm_already_done) {
                        $att_sidebar_badge        = true;
                        $_late_window_end_h       = floor($_late_window_end / 3600);
                        $_late_window_end_m       = floor(($_late_window_end % 3600) / 60);
                        $_late_window_end_str     = sprintf('%02d:%02d:00', $_late_window_end_h, $_late_window_end_m);
                        $attendance_badge_info = [
                            'type'           => 'pm_time_out_late',
                            'label'          => 'PM Sign Out Late Request',
                            'start_fmt'      => $fmt12att($_pm_out_end) . ' (missed)',
                            'end_fmt'        => $fmt12att($_late_window_end_str) . ' (deadline)',
                            'start_time'     => $_pm_out_end,
                            'end_time'       => $_late_window_end_str,
                            'is_late_window' => true,
                        ];
                    }
                }
            }
        }
    }
}

$today = date("Y-m-d");

// ── Get company ──
$stmt = $conn->prepare("SELECT company_id FROM ojt_assignments WHERE student_id=? LIMIT 1");
$stmt->bind_param("i", $user_id);
$stmt->execute();
$res = $stmt->get_result()->fetch_assoc();
if (!$res) die("No company assigned.");
$company_id = $res['company_id'];

// ── Student name (include middle_name) ──
$ns = $conn->prepare("SELECT first_name, middle_name, last_name FROM users WHERE id=? LIMIT 1");
$ns->bind_param("i", $user_id);
$ns->execute();
$srow = $ns->get_result()->fetch_assoc();

// Build full name with middle name
$_fn = $srow['first_name']  ?? '';
$_mn = $srow['middle_name'] ?? '';
$_ln = $srow['last_name']   ?? '';
$full_name    = trim($_fn . ($_mn ? ' ' . $_mn : '') . ($_ln ? ' ' . $_ln : ''));
$display_name = $full_name ? htmlspecialchars($full_name) : 'Student';
$first_name   = $srow['first_name'] ?? 'Student';

// ── OJT start ──
$ojt_stmt = $conn->prepare("SELECT MIN(date) AS ojt_start FROM attendance_settings WHERE company_id=?");
$ojt_stmt->bind_param("i", $company_id);
$ojt_stmt->execute();
$ojt_start = $ojt_stmt->get_result()->fetch_assoc()['ojt_start'] ?? $today;

// UPDATED (Start = first attendance): the student's own start date is the first
// day they actually recorded attendance (a real time-in/out — "missed"-only rows
// don't count). Days before it are "before start", never absent / missed.
// No attendance yet → start is today, so no past day is marked absent.
$first_att_stmt = $conn->prepare("SELECT MIN(date) AS first_date FROM attendance_logs
                                  WHERE user_id = ? AND ((am_time_in  IS NOT NULL AND am_time_in  != '' AND am_time_in  != 'missed') OR (am_time_out IS NOT NULL AND am_time_out != '' AND am_time_out != 'missed') OR (pm_time_in  IS NOT NULL AND pm_time_in  != '' AND pm_time_in  != 'missed') OR (pm_time_out IS NOT NULL AND pm_time_out != '' AND pm_time_out != 'missed'))");
if ($first_att_stmt) {
    $first_att_stmt->bind_param("i", $user_id);
    $first_att_stmt->execute();
    $first_att_date = $first_att_stmt->get_result()->fetch_assoc()['first_date'] ?? null;
    $first_att_stmt->close();
    $ojt_start = $first_att_date ?: $today;
}

// ── Month selector ──
$month     = $_GET['month'] ?? date("Y-m");
if (!preg_match('/^\d{4}-\d{2}$/', $month)) $month = date("Y-m");
$month_start = $month . "-01";
$month_end   = date("Y-m-t", strtotime($month_start));

// ── Load ALL attendance settings for this company into a date-keyed map
//    (same approach as attendance_management.php so status computation matches) ──
$all_settings_map = [];
$res_all_settings = $conn->query("
    SELECT date,
           am_time_in_start, am_time_in_end, am_time_out_start, am_time_out_end,
           pm_time_in_start, pm_time_in_end, pm_time_out_start, pm_time_out_end,
           is_auto
    FROM attendance_settings
    WHERE company_id = " . (int)$company_id
);
if ($res_all_settings) {
    while ($sr = $res_all_settings->fetch_assoc()) {
        $all_settings_map[$sr['date']] = $sr;
    }
}

/**
 * Determine which duty columns are "active" for a given date.
 * Falls back to the most recent setting on or before the date if no exact match.
 * Returns ['am' => bool, 'pm' => bool]
 * Ported from attendance_management.php — ensures student dashboard status
 * matches what supervisors see in the attendance management page.
 */
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

/**
 * Compute attendance status for a log row, respecting:
 *   1. Which duty periods are active (AM and/or PM)
 *   2. Late-request approvals (approved 'missed' entries count as real values)
 *
 * Mirrors computeStatusForLog() from attendance_management.php but also
 * integrates the late-request approval logic from the original dashboard.
 *
 * Returns: 'present' | 'incomplete' | 'absent'
 */
function computeStudentStatus($log, $amActive, $pmActive, $dayLR) {
    $hasVal   = fn($v) => ($v !== null && $v !== '' && $v !== 'missed');
    $isMissed = fn($v) => ($v === 'missed');

    // Build the list of columns we actually care about
    $activeChecks = [];
    if ($amActive) {
        $activeChecks[] = 'am_time_in';
        $activeChecks[] = 'am_time_out';
    }
    if ($pmActive) {
        $activeChecks[] = 'pm_time_in';
        $activeChecks[] = 'pm_time_out';
    }

    $anyReal   = false;
    $allReal   = true;
    $anyMissed = false;

    foreach ($activeChecks as $col) {
        $v  = $log[$col] ?? null;
        $lr = $dayLR[$col] ?? null;

        // An approved late-request on a 'missed' entry counts as a real value
        $effectivelyReal = $hasVal($v) || ($isMissed($v) && $lr === 'approved');

        if ($effectivelyReal) {
            $anyReal = true;
        } else {
            $allReal = false;
        }

        if ($isMissed($v) && $lr !== 'approved') {
            $anyMissed = true;
        }
    }

    if (!$anyReal && !$anyMissed) return 'absent';
    if ($allReal && !$anyMissed)  return 'present';
    return 'incomplete';
}

// ── Fetch logs for selected month ──
$log_stmt = $conn->prepare("
    SELECT date, am_time_in, am_time_out, pm_time_in, pm_time_out
    FROM attendance_logs
    WHERE user_id=? AND company_id=? AND date BETWEEN ? AND ?
    ORDER BY date ASC
");
$log_stmt->bind_param("iiss", $user_id, $company_id, $month_start, $month_end);
$log_stmt->execute();
$logs = $log_stmt->get_result()->fetch_all(MYSQLI_ASSOC);

// ── Fetch late requests for the selected month ──
// request_type ('late' / 'overtime') exists once the Late Request / Overtime feature has been used;
// older databases simply report every request as a regular late request.
$lr_has_kind = false;
try {
    $lr_kc = $conn->query("SHOW COLUMNS FROM late_requests LIKE 'request_type'");
    $lr_has_kind = ($lr_kc && $lr_kc->num_rows > 0);
} catch (\Throwable $e) {}
$lr_kind_sel = $lr_has_kind ? 'request_type' : "'late' AS request_type";
$lr_stmt = $conn->prepare("
    SELECT date, type, status, {$lr_kind_sel}
    FROM late_requests
    WHERE student_id=? AND company_id=? AND date BETWEEN ? AND ?
");
$lr_stmt->bind_param("iiss", $user_id, $company_id, $month_start, $month_end);
$lr_stmt->execute();
$lr_rows = $lr_stmt->get_result()->fetch_all(MYSQLI_ASSOC);

$late_req_map = [];
$late_kind_map = []; // [date][slot] => 'late' | 'overtime'
foreach ($lr_rows as $lr) {
    $late_req_map[$lr['date']][$lr['type']] = $lr['status'];
    $late_kind_map[$lr['date']][$lr['type']] = (($lr['request_type'] ?? 'late') === 'overtime') ? 'overtime' : 'late';
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

$sched_map = attsch_load($conn, [(int)$user_id]); // NEW (student schedule)
$total_ns  = 0;                                          // NEW (student schedule): past days the student was not scheduled on

// ── Build daily data ──
$log_map = [];
foreach ($logs as $row) {
    $log_map[$row['date']] = $row;
}

$hasVal = fn($v) => ($v !== null && $v !== '' && $v !== 'missed');

$days_data   = [];
$total_p     = 0; $total_i = 0; $total_a = 0; $total_off = 0;
$chart_dates = []; $chart_hours = []; $chart_labels = [];
$chart_seconds = []; // UPDATED (accurate hours): exact seconds per charted day

$d = strtotime($month_start);
while ($d <= strtotime($month_end)) {
    $ds  = date("Y-m-d", $d);
    $dow = (int)date('w', $d);
    $wknd = ($dow===0 || $dow===6);
    $row  = $log_map[$ds] ?? null;
    $hours = 0;
    $day_seconds = 0; // UPDATED (accurate hours)

    if ($ds < $ojt_start) {
        $status = 'before_start';
    } elseif ($wknd) {
        $status = 'dayoff'; $total_off++;
    } elseif (!attsch_has_real_entry($row) && !attsch_is_scheduled($sched_map, $user_id, $ds)) {
        // NEW (student schedule): not a duty day for this student and nothing recorded → never absent / missed / incomplete
        $status = 'noschedule'; if ($ds <= $today) $total_ns++;
    } elseif ($ds > $today) {
        $status = 'future';
    } elseif (!$row) {
        $status = 'absent'; $total_a++;
    } else {
        // ── Hours calculation (uses whatever columns have real values) ──
        $amIn  = $row['am_time_in'];
        $amOut = $row['am_time_out'];
        $pmIn  = $row['pm_time_in'];
        $pmOut = $row['pm_time_out'];

        // UPDATED (accurate hours): work in exact seconds, never let a
        // reversed/invalid pair subtract time, and use the full date+time when
        // both values carry a date (handles sessions that cross midnight).
        $calc_seconds = function($in, $out) use ($hasVal) {
            if (!$hasVal($in) || !$hasVal($out)) return 0;
            $in  = trim((string)$in);
            $out = trim((string)$out);
            $isDateTime = fn($t) => strpos($t,' ')!==false && strpos($t,'-')!==false;
            if ($isDateTime($in) && $isDateTime($out)) {
                $tIn  = strtotime($in);
                $tOut = strtotime($out);
            } else {
                $strip = fn($t) => strpos($t,' ')!==false ? explode(' ',$t)[1] : $t;
                $tIn  = strtotime('1970-01-01 ' . $strip($in));
                $tOut = strtotime('1970-01-01 ' . $strip($out));
            }
            if ($tIn === false || $tOut === false) return 0;
            return max(0, $tOut - $tIn);
        };
        $calc_hours = function($in, $out) use ($calc_seconds) {
            return $calc_seconds($in, $out) / 3600;
        };
        $am_s = $calc_seconds($amIn, $amOut);
        $pm_s = $calc_seconds($pmIn, $pmOut);
        $day_seconds = $am_s + $pm_s;
        $am_h = $am_s / 3600;
        $pm_h = $pm_s / 3600;
        $hours = round($am_h + $pm_h, 2);

        // ── Determine which duty periods are active for this date ──
        $duty = getActiveDutyPeriods($ds, $all_settings_map);
        $duty = attsch_limit_duty($duty, attsch_periods($sched_map, $user_id, $ds), $row); // NEW (student schedule): Day = AM duty, Evening = PM duty

        // ── Compute status using the same logic as attendance_management.php ──
        $dayLR  = $late_req_map[$ds] ?? [];
        $result = computeStudentStatus($row, $duty['am'], $duty['pm'], $dayLR);

        if ($result === 'present') {
            $status = 'present'; $total_p++;
        } elseif ($result === 'incomplete') {
            $status = 'incomplete'; $total_i++;
        } else {
            $status = 'absent'; $total_a++;
        }
    }

    $days_data[$ds] = ['status'=>$status,'hours'=>$hours,'dow'=>$dow,'seconds'=>$day_seconds];

    if (!$wknd && $ds >= $ojt_start && $ds <= $today && $status !== 'before_start') {
        $chart_dates[]  = $ds;
        $chart_hours[]  = $hours;
        $chart_seconds[] = $day_seconds;
        $chart_labels[] = date("M j", $d);
    }

    $d = strtotime("+1 day", $d);
}

// UPDATED (accurate hours): totals come from exact seconds, not from the
// per-day values that were already rounded to 2 decimals.
$total_duty_seconds = array_sum($chart_seconds);
$total_duty_hours   = $total_duty_seconds / 3600;
$avg_duty_seconds   = count($chart_seconds) > 0 ? (int)round($total_duty_seconds / count($chart_seconds)) : 0;
$avg_hours_per_day  = count($chart_hours) > 0 ? round($total_duty_hours / count($chart_hours), 1) : 0;
$required_per_day   = 8;

/* ════════════════════════════════════════════════════════════════════
   UPDATED (Required Hours per Day from Course Offering): the daily
   requirement is no longer a fixed 8 hours. It is the "Required Hours per
   Day" set for the student's course on course_offering.php (same
   `course_offerings` table, course matched ignoring letter case and
   extra spaces). Falls back to 8 hours when the course has no offering.
   ════════════════════════════════════════════════════════════════════ */
$required_course = '';
try {
    $req_norm = function($c) {
        $c = preg_replace('/\s+/', ' ', trim((string)$c));
        return function_exists('mb_strtolower') ? mb_strtolower($c) : strtolower($c);
    };
    $req_cols = function($table) use ($conn) {
        $cols = [];
        try {
            $r = $conn->query("SHOW COLUMNS FROM `" . str_replace('`', '', $table) . "`");
            if ($r) { while ($c = $r->fetch_assoc()) $cols[$c['Field']] = true; }
        } catch (\Throwable $e) {}
        return $cols;
    };

    // Student's course: users.course → student_information.course → students_import.course (by email)
    $u_cols = $req_cols('users');
    if (isset($u_cols['course'])) {
        $cs = $conn->prepare("SELECT course" . (isset($u_cols['email']) ? ", email" : "") . " FROM users WHERE id = ? LIMIT 1");
        $cs->bind_param("i", $user_id);
        $cs->execute();
        $cr = $cs->get_result()->fetch_assoc();
        $cs->close();
        $required_course = trim((string)($cr['course'] ?? ''));
    }
    if ($required_course === '') {
        $si_cols = $req_cols('student_information');
        if (isset($si_cols['user_id']) && isset($si_cols['course'])) {
            $cs = $conn->prepare("SELECT MAX(course) AS course FROM student_information WHERE user_id = ?");
            $cs->bind_param("i", $user_id);
            $cs->execute();
            $required_course = trim((string)($cs->get_result()->fetch_assoc()['course'] ?? ''));
            $cs->close();
        }
    }
    if ($required_course === '' && isset($u_cols['email'])) {
        $imp_cols = $req_cols('students_import');
        if (isset($imp_cols['email']) && isset($imp_cols['course'])) {
            $cs = $conn->prepare("SELECT si.course FROM students_import si JOIN users u ON u.email = si.email WHERE u.id = ? LIMIT 1");
            $cs->bind_param("i", $user_id);
            $cs->execute();
            $required_course = trim((string)($cs->get_result()->fetch_assoc()['course'] ?? ''));
            $cs->close();
        }
    }

    if ($required_course !== '') {
        $co_res = $conn->query("SELECT course, daily_hours FROM course_offerings");
        if ($co_res) {
            $want = $req_norm($required_course);
            while ($co = $co_res->fetch_assoc()) {
                if ($req_norm($co['course']) === $want && (float)$co['daily_hours'] > 0) {
                    $required_per_day = (float)$co['daily_hours'];
                    break;
                }
            }
        }
    }
} catch (\Throwable $e) { /* keep the 8-hour default */ }

// Display form, same style as course_offering.php (e.g. "8", "7.5")
$required_per_day_label = ((float)$required_per_day == floor((float)$required_per_day))
    ? number_format((float)$required_per_day, 0)
    : rtrim(rtrim(number_format((float)$required_per_day, 2), '0'), '.');
$required_per_day_seconds = (int)round((float)$required_per_day * 3600);
$days_met_req       = count(array_filter($chart_seconds, fn($s) => $s >= $required_per_day_seconds));

// ── Month navigation ──
$prev_month = date("Y-m", strtotime("-1 month", strtotime($month_start)));
$next_month = date("Y-m", strtotime("+1 month", strtotime($month_start)));
$ojt_month  = substr($ojt_start, 0, 7);
$can_prev   = ($prev_month >= $ojt_month);
$can_next   = ($next_month <= date("Y-m"));

// UPDATED (accurate hours): format seconds as "Xh Ym" (minutes are not rounded up,
// so the display never shows more time than was actually rendered)
function fmtDuration($seconds) {
    $seconds = (int)$seconds;
    if ($seconds <= 0) return '0h';
    $total_min = intdiv($seconds, 60);
    $h = intdiv($total_min, 60);
    $m = $total_min % 60;
    if ($h === 0) return $m . 'm';
    return $h . 'h' . ($m > 0 ? ' ' . $m . 'm' : '');
}

function fmt12s($t) {
    if (!$t || $t === 'missed') return ($t === 'missed') ? 'MISSED' : '—';
    if (strpos($t,' ')!==false) $t=explode(' ',$t)[1];
    $p=explode(':',$t); $h=(int)$p[0]; $m=(int)$p[1];
    return sprintf('%d:%02d %s',$h%12?:12,$m,$h>=12?'PM':'AM');
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Dashboard — <?= $display_name ?></title>
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
<link href="https://fonts.googleapis.com/css2?family=Sora:wght@400;500;600;700;800&family=JetBrains+Mono:wght@400;500&family=DM+Sans:wght@300;400;500;600&family=DM+Mono:wght@400;500&display=swap" rel="stylesheet">
<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js"></script>
<style>
/* ══ Reset ══ */
*, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }

/* ══ VARIABLES ══ */
:root {
    /* Sidebar */
    --neust-maroon: #07145fe5;
    --neust-gold:   #FFD700;
    --neust-active: #1a237e;
    /* Dashboard dark theme */
    --bg:       #0f1117;
    --surface:  #1a1d27;
    --surface2: #22263a;
    --border:   #2a2e45;
    --accent:   #6c63ff;
    --accent2:  #ff6584;
    --green:    #43e97b;
    --orange:   #f9a826;
    --red:      #ff5c5c;
    --purple:   #a78bfa;
    --text:     #e8eaf0;
    --muted:    #7b7f9e;
    --mono:     'JetBrains Mono', monospace;
}
:root.light-mode {
    --bg:       #f8fafc;
    --surface:  #ffffff;
    --surface2: #f1f5f9;
    --border:   #e2e8f0;
    --accent:   #4f46e5;
    --accent2:  #ec4899;
    --green:    #10b981;
    --orange:   #f59e0b;
    --red:      #ef4444;
    --purple:   #8b5cf6;
    --text:     #1e293b;
    --muted:    #64748b;
}

body {
    font-family: 'Sora', sans-serif;
    background: var(--bg);
    color: var(--text);
    min-height: 100vh;
    line-height: 1.5;
}
a { color: inherit; text-decoration: none; }

/* ══ SIDEBAR ══ */
.sidebar {
    width: 260px;
    background: var(--neust-maroon);
    height: 100vh;
    position: fixed;
    top: 0; left: 0;
    display: flex;
    flex-direction: column;
    transition: width 0.3s ease;
    z-index: 1000;
    box-shadow: 4px 0 10px rgba(0,0,0,0.2);
}
.sidebar.collapsed { width: 80px; }

/* ── SIDEBAR HEADER: student name + OJT Trainee label ── */
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
    font-family: 'DM Sans', sans-serif;
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

.sidebar-links { flex: 1; display: flex; flex-direction: column; padding: 10px 0; overflow: hidden; }

.sidebar a {
    padding: 15px 25px;
    color: #cbd5e0;
    text-decoration: none;
    font-size: 14px;
    display: flex;
    align-items: center;
    transition: background 0.2s, color 0.2s;
    white-space: nowrap;
    position: relative;
    font-family: 'DM Sans', sans-serif;
}
.sidebar a i { width: 30px; font-size: 18px; margin-right: 15px; text-align: center; flex-shrink: 0; }
.sidebar.collapsed .link-text { display: none; }
.sidebar.collapsed a i { margin-right: 0; }
.sidebar a:hover:not(.active) { background: rgba(255,255,255,0.07); color: white; }
.sidebar a.active { background: var(--neust-active); color: white; border-left: 4px solid var(--neust-gold); }

.sidebar-badge-att {
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
    animation: badge-pulse-att 2s ease-in-out infinite;
}
@keyframes badge-pulse-att {
    0%, 100% { box-shadow: 0 0 0 0 rgba(217,119,6,0.55); }
    50%       { box-shadow: 0 0 0 6px rgba(217,119,6,0); }
}

.sidebar-badge-journal {
    background: #f59e0b;
    color: #1c1917;
    border-radius: 50%;
    min-width: 18px; height: 18px;
    font-size: 10px; font-weight: 800;
    display: inline-flex;
    align-items: center; justify-content: center;
    position: absolute;
    right: 18px; top: 50%;
    transform: translateY(-50%);
    padding: 0 3px;
    animation: badge-pulse-journal 2.4s ease-in-out infinite;
}
@keyframes badge-pulse-journal {
    0%, 100% { box-shadow: 0 0 0 0 rgba(245,158,11,0.5); }
    50%       { box-shadow: 0 0 0 5px rgba(245,158,11,0); }
}

.logout-link { margin-top: auto; padding: 20px; border-top: 1px solid rgba(255,255,255,0.1); }
.logout-link a {
    border: 1px solid var(--neust-gold); color: var(--neust-gold); border-radius: 6px;
    justify-content: center; padding: 10px; display: flex; align-items: center;
    text-decoration: none; font-size: 14px; transition: background 0.2s;
    font-family: 'DM Sans', sans-serif;
}
.logout-link a:hover { background: rgba(255,215,0,0.08); }

.toggle-btn { background: transparent; border: none; color: white; cursor: pointer; font-size: 20px; outline: none; flex-shrink: 0; }

/* ══ MAIN CONTENT WRAPPER ══ */
.main-content {
    margin-left: 260px;
    width: calc(100% - 260px);
    transition: margin-left 0.3s, width 0.3s;
    min-height: 100vh;
}

/* ══ NAVBAR ══ */
.navbar {
    background: var(--neust-maroon);
    padding: 10px 30px;
    display: flex;
    align-items: center;
    color: white;
    height: 60px;
    box-shadow: 0 2px 8px rgba(0,0,0,0.3);
    justify-content: space-between;
    position: relative;
    z-index: 99;
}
.navbar-left { display: flex; align-items: center; gap: 0; }
.navbar img   { height: 40px; margin-right: 14px; }

/* ══ ENHANCED THEME TOGGLE BUTTON ══ */
.theme-toggle-wrap {
    display: flex;
    align-items: center;
    gap: 10px;
    flex-shrink: 0;
}
.theme-switch {
    position: relative;
    width: 72px;
    height: 34px;
    cursor: pointer;
    flex-shrink: 0;
}
.theme-switch input { opacity: 0; width: 0; height: 0; position: absolute; }
.theme-switch-track {
    position: absolute;
    inset: 0;
    border-radius: 34px;
    background: linear-gradient(135deg, #1e293b, #0f172a);
    border: 1px solid rgba(255,255,255,0.15);
    transition: background 0.4s ease;
    overflow: hidden;
    box-shadow: inset 0 2px 4px rgba(0,0,0,0.4), 0 1px 0 rgba(255,255,255,0.08);
}
.theme-switch-track::before {
    content: '✦ ✦';
    position: absolute;
    right: 8px; top: 50%;
    transform: translateY(-50%);
    font-size: 7px;
    color: rgba(255,255,255,0.45);
    letter-spacing: 2px;
    transition: opacity 0.3s;
}
.theme-switch-track::after {
    content: '';
    position: absolute;
    inset: 0;
    background: linear-gradient(135deg, #fbbf24, #f59e0b);
    opacity: 0;
    transition: opacity 0.4s ease;
    border-radius: inherit;
}
.theme-switch-thumb {
    position: absolute;
    top: 3px; left: 3px;
    width: 28px; height: 28px;
    border-radius: 50%;
    background: linear-gradient(145deg, #c7d2fe, #818cf8);
    box-shadow: 0 2px 6px rgba(0,0,0,0.4), inset 0 1px 0 rgba(255,255,255,0.3);
    transition: transform 0.4s cubic-bezier(0.34, 1.56, 0.64, 1), background 0.4s ease, box-shadow 0.3s;
    display: flex; align-items: center; justify-content: center;
    z-index: 1;
    font-size: 13px; line-height: 1;
}
.theme-label {
    font-size: 11px; font-weight: 700;
    color: rgba(255,255,255,0.75);
    font-family: 'Sora', sans-serif;
    letter-spacing: 0.04em;
    text-transform: uppercase;
    white-space: nowrap;
    transition: color 0.3s;
    user-select: none;
    min-width: 36px;
}
:root.light-mode .theme-switch-track {
    background: linear-gradient(135deg, #bae6fd, #7dd3fc);
    border-color: rgba(255,255,255,0.5);
    box-shadow: inset 0 2px 4px rgba(0,0,0,0.1), 0 1px 0 rgba(255,255,255,0.5);
}
:root.light-mode .theme-switch-track::before { opacity: 0; }
:root.light-mode .theme-switch-track::after  { opacity: 1; }
:root.light-mode .theme-switch-thumb {
    transform: translateX(38px);
    background: linear-gradient(145deg, #fef3c7, #fde68a);
    box-shadow: 0 2px 8px rgba(245,158,11,0.5), inset 0 1px 0 rgba(255,255,255,0.6);
}
:root.light-mode .theme-label { color: rgba(255,255,255,0.9); }
.theme-switch:hover .theme-switch-thumb {
    box-shadow: 0 2px 6px rgba(0,0,0,0.4), 0 0 0 3px rgba(255,255,255,0.12), inset 0 1px 0 rgba(255,255,255,0.3);
}
:root.light-mode .theme-switch:hover .theme-switch-thumb {
    box-shadow: 0 2px 8px rgba(245,158,11,0.5), 0 0 0 3px rgba(251,191,36,0.2), inset 0 1px 0 rgba(255,255,255,0.6);
}

/* ══════════════════════════════════════════════════════════════
   ATTENDANCE NOTIFICATION BAR — v3
══════════════════════════════════════════════════════════════ */
#att-notif-bar {
    position: fixed;
    top: 60px;
    left: 50%;
    transform: translateX(-50%) translateY(-120%);
    visibility: hidden;
    opacity: 0;
    width: calc(100% - 300px);
    max-width: 820px;
    background: #07145f;
    border-radius: 0 0 12px 12px;
    border: 1px solid rgba(255,255,255,.12);
    border-top: none;
    padding: 10px 16px;
    display: flex;
    align-items: center;
    gap: 12px;
    transition: transform .4s cubic-bezier(.34,1.2,.64,1),
                opacity .3s ease,
                visibility 0s linear .4s;
    z-index: 2000;
    pointer-events: none;
    overflow: hidden;
}
#att-notif-bar.anb-visible {
    transform: translateX(-50%) translateY(0);
    visibility: visible;
    opacity: 1;
    transition: transform .4s cubic-bezier(.34,1.2,.64,1),
                opacity .3s ease,
                visibility 0s linear 0s;
    pointer-events: auto;
}
#att-notif-bar.sidebar-collapsed { width: calc(100% - 120px); }

.anb-icon {
    width: 34px; height: 34px;
    border-radius: 8px;
    background: #FAEEDA;
    display: flex; align-items: center; justify-content: center;
    flex-shrink: 0;
}
.anb-icon i { font-size: 16px; color: #854F0B; }

.anb-pulse {
    width: 8px; height: 8px;
    border-radius: 50%;
    background: #EF9F27;
    flex-shrink: 0;
    animation: anb-blink 1.4s ease-in-out infinite;
}
@keyframes anb-blink { 0%,100%{opacity:1} 50%{opacity:.2} }

.anb-content {
    flex: 1;
    min-width: 0;
    display: flex;
    align-items: center;
    gap: 14px;
    flex-wrap: nowrap;
    overflow: hidden;
}

.anb-text-group {
    display: flex;
    flex-direction: column;
    min-width: 0;
}

.anb-label {
    font-size: 12px;
    font-weight: 700;
    color: #FAEEDA;
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
}
.anb-window {
    font-size: 11px;
    color: rgba(250,238,218,.65);
    margin-top: 1px;
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
}

.anb-divider {
    width: 1px; height: 26px;
    background: rgba(255,255,255,.18);
    flex-shrink: 0;
}

.anb-countdown {
    font-size: 11px;
    color: #FAC775;
    white-space: nowrap;
    background: rgba(250,199,117,.14);
    border-radius: 99px;
    padding: 3px 11px;
    border: 1px solid rgba(250,199,117,.28);
    font-family: 'DM Mono', monospace;
    font-variant-numeric: tabular-nums;
    flex-shrink: 0;
    min-width: 100px;
    text-align: center;
}

.anb-btn {
    background: #EF9F27;
    color: #412402;
    border: none;
    border-radius: 7px;
    padding: 7px 15px;
    font-size: 11px;
    font-weight: 700;
    font-family: inherit;
    white-space: nowrap;
    flex-shrink: 0;
    transition: background .15s;
    cursor: pointer;
}
.anb-btn:hover { background: #FAC775; }

.anb-close {
    background: rgba(255,255,255,.12);
    border: none;
    color: rgba(250,238,218,.75);
    width: 26px; height: 26px;
    border-radius: 50%;
    font-size: 13px;
    display: flex; align-items: center; justify-content: center;
    flex-shrink: 0;
    transition: background .15s;
    cursor: pointer;
}
.anb-close:hover { background: rgba(255,255,255,.24); color: #FAEEDA; }

.anb-progress {
    position: absolute;
    bottom: 0; left: 0;
    height: 2px;
    background: #EF9F27;
    border-radius: 0 0 0 12px;
    pointer-events: none;
}

/* ══ ALL ORIGINAL DASHBOARD STYLES (unchanged) ══ */
.shell {
    max-width: 900px;
    margin: 0 auto;
    padding: 24px 16px 60px;
    transition: padding-top .4s cubic-bezier(.34,1.2,.64,1);
}
.shell.anb-open {
    padding-top: 78px;
}

.top-nav {
    display: flex;
    align-items: center;
    justify-content: space-between;
    margin-bottom: 28px;
    gap: 12px;
}
.nav-greeting h1 {
    font-size: 22px;
    font-weight: 800;
    letter-spacing: -.3px;
    line-height: 1.2;
}
.nav-greeting p {
    font-size: 12px;
    color: var(--muted);
    margin-top: 3px;
    font-family: var(--mono);
}
.nav-att-link {
    background: var(--accent);
    color: #fff;
    border-radius: 12px;
    padding: 10px 18px;
    font-size: 13px;
    font-weight: 700;
    display: flex;
    align-items: center;
    gap: 7px;
    transition: opacity .2s, transform .15s;
    white-space: nowrap;
}
.nav-att-link:hover { opacity: .85; transform: translateY(-1px); }

.stat-row {
    display: grid;
    grid-template-columns: repeat(4, 1fr);
    gap: 10px;
    margin-bottom: 18px;
}
@media (max-width: 600px) { .stat-row { grid-template-columns: repeat(2, 1fr); } }

.stat-card {
    background: var(--surface);
    border: 1px solid var(--border);
    border-radius: 16px;
    padding: 16px 14px;
    position: relative;
    overflow: hidden;
    transition: border-color .2s;
}
.stat-card:hover { border-color: var(--accent); }
.stat-card::before {
    content: '';
    position: absolute;
    top: 0; left: 0; right: 0;
    height: 3px;
}
.stat-card.present::before  { background: var(--green); }
.stat-card.incomplete::before { background: var(--orange); }
.stat-card.absent::before   { background: var(--red); }
.stat-card.dayoff::before   { background: var(--purple); }

.stat-icon { font-size: 20px; margin-bottom: 8px; display: block; }
.stat-num {
    font-size: 30px;
    font-weight: 800;
    line-height: 1;
    font-family: var(--mono);
    margin-bottom: 4px;
}
.stat-num.present    { color: var(--green); }
.stat-num.incomplete { color: var(--orange); }
.stat-num.absent     { color: var(--red); }
.stat-num.dayoff     { color: var(--purple); }
.stat-label { font-size: 11px; color: var(--muted); font-weight: 600; text-transform: uppercase; letter-spacing: .06em; }

.hours-row {
    display: grid;
    grid-template-columns: repeat(3, 1fr);
    gap: 10px;
    margin-bottom: 18px;
}
@media (max-width: 500px) { .hours-row { grid-template-columns: 1fr 1fr; } }

.hour-card {
    background: var(--surface);
    border: 1px solid var(--border);
    border-radius: 14px;
    padding: 14px;
    text-align: center;
}
.hour-card .hc-num {
    font-size: 24px;
    font-weight: 800;
    font-family: var(--mono);
    color: var(--accent);
    line-height: 1;
}
.hour-card .hc-label { font-size: 10px; color: var(--muted); font-weight: 600; text-transform: uppercase; letter-spacing: .06em; margin-top: 5px; }
.hour-card .hc-sub   { font-size: 11px; color: var(--muted); margin-top: 2px; }

.chart-card {
    background: var(--surface);
    border: 1px solid var(--border);
    border-radius: 20px;
    padding: 20px;
    margin-bottom: 18px;
}
.chart-card-header {
    display: flex;
    align-items: flex-start;
    justify-content: space-between;
    margin-bottom: 18px;
    gap: 12px;
    flex-wrap: wrap;
}
.chart-card-title { font-size: 14px; font-weight: 700; color: var(--text); }
.chart-card-sub { font-size: 12px; color: var(--muted); margin-top: 3px; }
.req-badge {
    background: rgba(108,99,255,.15);
    color: var(--accent);
    border: 1px solid rgba(108,99,255,.3);
    border-radius: 8px;
    padding: 5px 10px;
    font-size: 11px;
    font-weight: 700;
    font-family: var(--mono);
    white-space: nowrap;
}
.chart-wrap { position: relative; height: 220px; }

.chart-legend { display: flex; gap: 16px; margin-top: 14px; flex-wrap: wrap; }
.legend-dot { width: 10px; height: 10px; border-radius: 50%; flex-shrink: 0; display: inline-block; }
.legend-item { display: flex; align-items: center; gap: 6px; font-size: 11px; color: var(--muted); }

.cal-card {
    background: var(--surface);
    border: 1px solid var(--border);
    border-radius: 20px;
    overflow: hidden;
    margin-bottom: 18px;
}
.cal-header {
    display: flex;
    align-items: center;
    justify-content: space-between;
    padding: 16px 20px;
    border-bottom: 1px solid var(--border);
}
.cal-header h3 { font-size: 15px; font-weight: 700; }
.cal-nav { display: flex; align-items: center; gap: 10px; }
.cal-nav-btn {
    background: var(--surface2);
    border: 1px solid var(--border);
    color: var(--text);
    width: 30px; height: 30px;
    border-radius: 8px;
    cursor: pointer;
    font-size: 14px;
    display: flex; align-items: center; justify-content: center;
    transition: background .2s;
}
.cal-nav-btn:hover:not(:disabled) { background: var(--border); }
.cal-nav-btn:disabled { opacity: .3; cursor: default; }
.cal-month-label { font-size: 13px; font-weight: 700; font-family: var(--mono); min-width: 120px; text-align: center; }

.cal-grid { padding: 14px; display: grid; grid-template-columns: repeat(7, 1fr); gap: 5px; }
.cal-day-hdr {
    text-align: center;
    font-size: 10px;
    font-weight: 700;
    color: var(--muted);
    text-transform: uppercase;
    letter-spacing: .05em;
    padding-bottom: 6px;
}
.cal-day-hdr:first-child, .cal-day-hdr:last-child { color: var(--purple); }

.cal-day {
    aspect-ratio: 1;
    border-radius: 10px;
    display: flex;
    flex-direction: column;
    align-items: center;
    justify-content: center;
    font-size: 11px;
    font-weight: 700;
    cursor: default;
    transition: transform .15s;
    position: relative;
}
.cal-day.empty { background: transparent; }
.cal-day.future { background: var(--surface2); color: var(--border); }
.cal-day.before-start { background: transparent; }
.cal-day.dayoff { background: rgba(167,139,250,.12); color: var(--purple); border: 1px solid rgba(167,139,250,.2); }
.cal-day.nosched { background: rgba(96,165,250,.12); color: #60a5fa; border: 1px solid rgba(96,165,250,.28); } /* NEW (student schedule): not scheduled that day */
.cal-legend { display: flex; gap: 14px; flex-wrap: wrap; margin-top: 12px; padding: 0 16px 14px; font-size: 11px; color: var(--muted); }
.cal-legend-item { display: flex; align-items: center; gap: 6px; }
.cal-legend-dot { width: 12px; height: 12px; border-radius: 3px; flex-shrink: 0; display: inline-block; background: rgba(96,165,250,.12); border: 1px solid rgba(96,165,250,.28); }
.cal-day.present    { background: rgba(67,233,123,.12); color: var(--green);  border: 1px solid rgba(67,233,123,.25); }
.cal-day.incomplete { background: rgba(249,168,38,.12); color: var(--orange); border: 1px solid rgba(249,168,38,.25); }
.cal-day.absent     { background: rgba(255,92,92,.12);  color: var(--red);    border: 1px solid rgba(255,92,92,.25); }
.cal-day.pending    { background: rgba(246,173,85,.12); color: #f6ad55;       border: 1px solid rgba(246,173,85,.3); }
.cal-day.today-ring { box-shadow: 0 0 0 2px var(--accent); }

.cal-day:not(.empty):not(.future):not(.before-start):hover { transform: scale(1.08); z-index: 1; }
.cal-day .day-num { font-size: 12px; line-height: 1; }
.cal-day .day-badge { font-size: 7px; font-weight: 800; line-height: 1; margin-top: 2px; opacity: .8; }
.cal-day .day-hrs { font-size: 7px; font-family: var(--mono); color: inherit; opacity: .75; margin-top: 1px; }

[data-tip] { position: relative; cursor: default; }
[data-tip]:hover::after {
    content: attr(data-tip);
    position: absolute;
    bottom: calc(100% + 6px);
    left: 50%;
    transform: translateX(-50%);
    background: #1e2235;
    color: var(--text);
    border: 1px solid var(--border);
    border-radius: 8px;
    padding: 6px 10px;
    font-size: 11px;
    font-family: var(--mono);
    white-space: pre;
    z-index: 99;
    min-width: 140px;
    text-align: left;
    line-height: 1.6;
    pointer-events: none;
    box-shadow: 0 6px 20px rgba(0,0,0,.4);
}

.log-card {
    background: var(--surface);
    border: 1px solid var(--border);
    border-radius: 20px;
    overflow: hidden;
    margin-bottom: 18px;
}
.log-card-header { padding: 16px 20px; border-bottom: 1px solid var(--border); font-size: 14px; font-weight: 700; }
.log-table { width: 100%; border-collapse: collapse; font-size: 12px; }
.log-table th {
    background: var(--surface2);
    color: var(--muted);
    font-size: 10px;
    font-weight: 700;
    text-transform: uppercase;
    letter-spacing: .06em;
    padding: 8px 14px;
    text-align: left;
}
.log-table td { padding: 10px 14px; border-bottom: 1px solid var(--border); color: var(--text); }
.log-table tr:last-child td { border-bottom: none; }
.log-table tr:hover td { background: var(--surface2); }
.status-pill { display: inline-block; padding: 3px 9px; border-radius: 20px; font-size: 10px; font-weight: 700; }
.status-pill.present    { background: rgba(67,233,123,.15);  color: var(--green); }
.status-pill.incomplete { background: rgba(249,168,38,.15);  color: var(--orange); }
.status-pill.absent     { background: rgba(255,92,92,.15);   color: var(--red); }
.status-pill.dayoff     { background: rgba(167,139,250,.15); color: var(--purple); }
.status-pill.pending    { background: rgba(246,173,85,.15);  color: #f6ad55; }
.time-val { font-family: var(--mono); font-size: 11px; color: var(--accent); }
.time-val.empty   { color: var(--border); font-style: italic; }
.time-val.missed  { color: var(--red); font-weight: 700; }
.time-val.pending { color: var(--orange); font-weight: 700; }
.ot-tag { display: inline-block; margin-left: 4px; padding: 1px 6px; border-radius: 20px; font-size: 9px; font-weight: 700; letter-spacing: .3px; background: rgba(167,139,250,.15); color: var(--purple); vertical-align: middle; }
.hours-val { font-family: var(--mono); font-size: 12px; font-weight: 700; }
.hours-val.met  { color: var(--green); }
.hours-val.low  { color: var(--orange); }
.hours-val.zero { color: var(--muted); }

.req-bar-wrap { margin-bottom: 18px; }
.req-bar-label { display: flex; justify-content: space-between; font-size: 11px; color: var(--muted); margin-bottom: 6px; }
.req-bar-label strong { color: var(--text); }
.req-bar-track { background: var(--surface2); border-radius: 99px; height: 6px; overflow: hidden; }
.req-bar-fill { height: 100%; border-radius: 99px; background: linear-gradient(90deg, var(--accent), var(--green)); transition: width .8s cubic-bezier(.4,0,.2,1); }

.empty-state { padding: 40px; text-align: center; color: var(--muted); font-size: 13px; }
.empty-state .es-icon { font-size: 32px; display: block; margin-bottom: 10px; }
</style>
</head>
<body>

<!-- ══ SIDEBAR ══ -->
<div id="sidebar" class="sidebar">
    <div class="sidebar-header">
        <div class="sidebar-user-info">
            <span class="sidebar-user-name"><?php echo htmlspecialchars($full_name); ?></span>
            <span class="sidebar-user-role">OJT Trainee</span>
        </div>
        <button id="toggleBtn" class="toggle-btn"><i class="fas fa-bars"></i></button>
    </div>
    <div class="sidebar-links">
        <a href="student_profile.php">
            <i class="fas fa-user-circle"></i>
            <span class="link-text">My Profile</span>
        </a>
        <a href="company_list.php">
            <i class="fas fa-building"></i>
            <span class="link-text">Company List</span>
        </a>
        <a href="AccomForm.php">
            <i class="fas fa-file-contract"></i>
            <span class="link-text">Requirements</span>
        </a>
        <a href="student_attendance.php">
            <i class="fas fa-calendar-check"></i>
            <span class="link-text">Attendance</span>
            <?php if (!empty($att_sidebar_badge)): ?>
                <span class="sidebar-badge-att">!</span>
            <?php endif; ?>
        </a>
        <a href="student_report.php">
            <i class="fas fa-chart-bar"></i>
            <span class="link-text">Reports</span>
            <span class="sidebar-badge-journal" id="journalEmptyBadge" style="display:none;"></span>
        </a>
        <a href="student_dashboard.php" class="active">
            <i class="fas fa-tachometer-alt"></i>
            <span class="link-text">Dashboard</span>
        </a>
    </div>
    <div class="logout-link">
        <a href="login.php">
            <i class="fas fa-sign-out-alt"></i>
            <span class="link-text" style="margin-left:10px;">Logout</span>
        </a>
    </div>
</div>

<!-- ══ ATTENDANCE NOTIFICATION BAR — v3 (matches student_profile.php) ══ -->
<div id="att-notif-bar">
    <div class="anb-icon"><i class="fas fa-clock"></i></div>
    <span class="anb-pulse"></span>
    <div class="anb-content">
        <div class="anb-text-group">
            <div id="anb-label" class="anb-label">Attendance Window Open</div>
            <div id="anb-window" class="anb-window">—</div>
        </div>
        <div class="anb-divider"></div>
        <span id="anb-countdown" class="anb-countdown">Calculating...</span>
    </div>
    <button class="anb-btn" id="anb-action-btn" onclick="window.location.href='student_attendance.php'">Sign now</button>
    <button class="anb-close" id="anb-close-btn" type="button" aria-label="Dismiss notification">&#x2715;</button>
    <div id="anb-progress" class="anb-progress" style="width:100%;"></div>
</div>

<!-- ══ MAIN CONTENT ══ -->
<div class="main-content" id="mainContent">

    <!-- Navbar -->
    <nav class="navbar">
        <div class="navbar-left">
            <img src="logo.webp" style="height:40px;margin-right:15px;" alt="NEUST Logo">
            <div>
                <div style="font-weight:bold; font-size:16px;">NEUST Atate Campus</div>
                <div style="font-size:11px; color:var(--neust-gold);">Web-Based Smart OJT Monitoring and Supervision Analytics System</div>
            </div>
        </div>

        <!-- ══ ENHANCED THEME TOGGLE ══ -->
        <div class="theme-toggle-wrap">
            <span class="theme-label" id="themeLabelText">Dark</span>
            <label class="theme-switch" title="Toggle dark/light mode" aria-label="Toggle theme">
                <input type="checkbox" id="theme-toggle-checkbox">
                <div class="theme-switch-track"></div>
                <div class="theme-switch-thumb" id="themeThumb">🌙</div>
            </label>
        </div>
    </nav>

    <!-- ══ DASHBOARD HTML ══ -->
    <div class="shell" id="dashShell">

        <div class="top-nav">
            <div class="nav-greeting">
                <h1><?= htmlspecialchars($first_name) ?>'s Dashboard</h1>
                <p><?= date("l, F j, Y") ?></p>
            </div>
            <a href="student_attendance.php" class="nav-att-link"> Take Attendance</a>
        </div>

        <form method="GET" id="month-form" style="display:none;">
            <input type="hidden" name="month" id="month-input" value="<?= $month ?>">
        </form>

        <div class="stat-row">
            <div class="stat-card present">
                <span class="stat-icon"></span>
                <div class="stat-num present"><?= $total_p ?></div>
                <div class="stat-label">Present</div>
            </div>
            <div class="stat-card incomplete">
                <span class="stat-icon"></span>
                <div class="stat-num incomplete"><?= $total_i ?></div>
                <div class="stat-label">Incomplete</div>
            </div>
            <div class="stat-card absent">
                <span class="stat-icon"></span>
                <div class="stat-num absent"><?= $total_a ?></div>
                <div class="stat-label">Absent</div>
            </div>
            <div class="stat-card dayoff">
                <span class="stat-icon"></span>
                <div class="stat-num dayoff"><?= $total_off ?></div>
                <div class="stat-label">Day Off</div>
            </div>
        </div>

        <div class="hours-row">
            <div class="hour-card">
                <div class="hc-num"><?= fmtDuration($total_duty_seconds) ?></div>
                <div class="hc-label">Total Hours</div>
                <div class="hc-sub">this month</div>
            </div>
            <div class="hour-card">
                <div class="hc-num"><?= fmtDuration($avg_duty_seconds) ?></div>
                <div class="hc-label">Avg hrs/day</div>
                <div class="hc-sub">required: <?= $required_per_day_label ?>h</div>
            </div>
            <div class="hour-card">
                <div class="hc-num"><?= $days_met_req ?></div>
                <div class="hc-label">Days Full</div>
                <div class="hc-sub">≥<?= $required_per_day_label ?>h met</div>
            </div>
        </div>

        <?php
        $required_month_hours = ($total_p + $total_i + $total_a) * $required_per_day;
        $pct = $required_month_hours > 0 ? min(100, round(($total_duty_hours / $required_month_hours) * 100)) : 0;
        ?>
        <div class="req-bar-wrap">
            <div class="req-bar-label">
                <span>Monthly duty hours progress</span>
                <strong><?= fmtDuration($total_duty_seconds) ?> / <?= ((float)$required_month_hours == floor((float)$required_month_hours)) ? number_format((float)$required_month_hours, 0, '.', '') : rtrim(rtrim(number_format((float)$required_month_hours, 2, '.', ''), '0'), '.') ?>h required</strong>
            </div>
            <div class="req-bar-track">
                <div class="req-bar-fill" style="width:<?= $pct ?>%"></div>
            </div>
        </div>

        <div class="chart-card">
            <div class="chart-card-header">
                <div>
                    <div class="chart-card-title"> Daily Duty Hours</div>
                    <div class="chart-card-sub">Hours spent per day vs. the <?= $required_per_day_label ?>-hour requirement<?= $required_course !== '' ? ' (' . htmlspecialchars($required_course) . ')' : '' ?></div>
                </div>
                <div class="req-badge">⏱ <?= $required_per_day_label ?>h / day required</div>
            </div>

            <?php if (empty($chart_hours)): ?>
            <div class="empty-state">
                <span class="es-icon"></span>
                No attendance data yet for this month.
            </div>
            <?php else: ?>
            <div class="chart-wrap">
                <canvas id="hoursChart"></canvas>
            </div>
            <div class="chart-legend">
                <div class="legend-item"><span class="legend-dot" style="background:var(--accent)"></span> Duty hours</div>
                <div class="legend-item"><span class="legend-dot" style="background:var(--accent2);border-radius:2px;width:20px;height:3px;"></span> <?= $required_per_day_label ?>h requirement</div>
            </div>
            <?php endif; ?>
        </div>

        <div class="cal-card">
            <div class="cal-header">
                <h3> <?= date("F Y", strtotime($month_start)) ?></h3>
                <div class="cal-nav">
                    <?php if ($can_prev): ?>
                    <button class="cal-nav-btn" onclick="navMonth('<?= $prev_month ?>')">&#8249;</button>
                    <?php else: ?>
                    <button class="cal-nav-btn" disabled>&#8249;</button>
                    <?php endif; ?>
                    <span class="cal-month-label"><?= date("M Y", strtotime($month_start)) ?></span>
                    <?php if ($can_next): ?>
                    <button class="cal-nav-btn" onclick="navMonth('<?= $next_month ?>')">&#8250;</button>
                    <?php else: ?>
                    <button class="cal-nav-btn" disabled>&#8250;</button>
                    <?php endif; ?>
                </div>
            </div>
            <div class="cal-grid">
                <?php
                $day_hdrs = ['Sun','Mon','Tue','Wed','Thu','Fri','Sat'];
                foreach ($day_hdrs as $dh) echo "<div class=\"cal-day-hdr\">{$dh}</div>";

                $first_dow = (int)date('w', strtotime($month_start));
                for ($e = 0; $e < $first_dow; $e++) echo "<div class=\"cal-day empty\"></div>";

                foreach ($days_data as $ds => $dd) {
                    $num  = (int)date("j", strtotime($ds));
                    $isToday = ($ds === $today);
                    $ring = $isToday ? ' today-ring' : '';
                    $st   = $dd['status'];
                    $hrs  = $dd['hours'];

                    if ($st === 'before_start') {
                        echo "<div class=\"cal-day before-start\"></div>";
                        continue;
                    }
                    if ($st === 'future') {
                        echo "<div class=\"cal-day future\"><span class=\"day-num\">{$num}</span></div>";
                        continue;
                    }

                    $cls_map = [
                        'present'    => 'present',
                        'incomplete' => 'incomplete',
                        'absent'     => 'absent',
                        'dayoff'     => 'dayoff',
                        'noschedule' => 'nosched',
                    ];
                    $cls   = $cls_map[$st] ?? 'future';
                    $badge = strtoupper(substr($st, 0, 1));
                    if ($st === 'dayoff') $badge = 'OFF';
                    if ($st === 'noschedule') $badge = 'N/S';

                    $log = $log_map[$ds] ?? null;
                    $dayLR = $late_req_map[$ds] ?? [];

                    if ($log && $st !== 'noschedule') {
                        $dayKind = $late_kind_map[$ds] ?? [];
                        $tipSlot = function($col, $label) use ($log, $dayLR, $dayKind) {
                            $v  = $log[$col] ?? null;
                            $lr = $dayLR[$col] ?? null;
                            $otTag = (($dayKind[$col] ?? 'late') === 'overtime' && in_array($lr, ['pending','approved'], true)) ? ' [Overtime]' : '';
                            if ($v === 'missed') {
                                $tag = $lr === 'pending' ? ' (⏳)' : ($lr === 'approved' ? ' (✓)' : '');
                                return "{$label}: MISSED{$tag}{$otTag}";
                            }
                            return "{$label}: " . fmt12s($v) . $otTag;
                        };
                        $tip = $tipSlot('am_time_in',  'AM In ')
                             . "\n" . $tipSlot('am_time_out', 'AM Out')
                             . "\n" . $tipSlot('pm_time_in',  'PM In ')
                             . "\n" . $tipSlot('pm_time_out', 'PM Out')
                             . (($dd['seconds'] ?? 0) > 0 ? "\nHours: " . fmtDuration($dd['seconds']) : '');
                    } elseif ($st === 'dayoff') {
                        $tip = "Weekend — Day Off";
                    } elseif ($st === 'noschedule') {
                        $tip = date("D, M j", strtotime($ds)) . "\nNot scheduled — no attendance required";
                    } else {
                        $tip = date("D, M j", strtotime($ds));
                    }

                    // NEW (student schedule): say so when only one duty (Day = AM, Evening = PM) is scheduled that day
                    $per_t = attsch_periods($sched_map, $user_id, $ds);
                    if ($st !== 'dayoff' && $st !== 'noschedule' && ($per_t['am'] xor $per_t['pm'])) $tip .= "\nScheduled: " . ($per_t['am'] ? 'Day (AM duty) only' : 'Evening (PM duty) only');

                    $hasPending = !empty(array_filter($dayLR, fn($s) => $s === 'pending'));
                    if ($hasPending && $st === 'absent') {
                        $cls   = 'pending';
                        $badge = '⏳';
                    } elseif ($hasPending) {
                        $badge .= '⏳';
                    }

                    // Same exact-seconds duration as the tooltip and Detailed Log (e.g. "3h 9m"), not a rounded decimal
                    $cell_secs = (int)($dd['seconds'] ?? 0);
                    $hrs_label = ($cell_secs > 0) ? fmtDuration($cell_secs) : (($hrs > 0) ? "{$hrs}h" : '');
                    echo "<div class=\"cal-day {$cls}{$ring}\" data-tip=\"{$tip}\">
                        <span class=\"day-num\">{$num}</span>
                        <span class=\"day-badge\">{$badge}</span>
                        ".($hrs_label ? "<span class=\"day-hrs\">{$hrs_label}</span>" : '')."
                    </div>";
                }
                ?>
            </div>
            <?php if (in_array('noschedule', array_column($days_data, 'status'), true)): ?>
            <div class="cal-legend"><span class="cal-legend-item"><span class="cal-legend-dot"></span>N/S &mdash; Not scheduled (no attendance required)</span></div>
            <?php endif; ?>
        </div>

        <div class="log-card">
            <div class="log-card-header"> Detailed Log — <?= date("F Y", strtotime($month_start)) ?></div>
            <?php
            $table_rows = array_filter($days_data, fn($d) => !in_array($d['status'],['before_start','future','dayoff','noschedule']));
            if (empty($table_rows)):
            ?>
            <div class="empty-state"><span class="es-icon"></span>No records for this month.</div>
            <?php else: ?>
            <table class="log-table">
                <thead>
                    <tr>
                        <th>Date</th>
                        <th>AM In</th>
                        <th>AM Out</th>
                        <th>PM In</th>
                        <th>PM Out</th>
                        <th>Hours</th>
                        <th>Status</th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($table_rows as $ds => $dd):
                    $log   = $log_map[$ds] ?? null;
                    $dayLR = $late_req_map[$ds] ?? [];
                    $dayKind = $late_kind_map[$ds] ?? [];
                    $h     = $dd['hours'];
                    $st    = $dd['status'];
                    $hs    = (int)($dd['seconds'] ?? round($h * 3600)); // UPDATED (accurate hours)
                    $hrs_cls = $hs >= $required_per_day_seconds ? 'met' : ($hs > 0 ? 'low' : 'zero'); // UPDATED: course requirement

                    $hasPendingLR = !empty(array_filter($dayLR, fn($s) => $s === 'pending'));
                    if ($st === 'absent' && $hasPendingLR) {
                        $st_cls = 'pending';
                        $st_label = 'Pending';
                    } else {
                        $st_cls   = $st === 'present' ? 'present' : ($st === 'incomplete' ? 'incomplete' : 'absent');
                        $st_label = ucfirst($st);
                    }
                ?>
                <tr>
                    <td style="font-family:var(--mono);font-size:11px;"><?= date("D, M j", strtotime($ds)) ?></td>
                    <?php
                    $cols = ['am_time_in','am_time_out','pm_time_in','pm_time_out'];
                    foreach ($cols as $col):
                        $v   = $log[$col] ?? null;
                        $lrs = $dayLR[$col] ?? null;
                        if ($v === 'missed') {
                            if ($lrs === 'pending') {
                                $cls  = 'pending';
                                $disp = 'MISSED ⏳';
                            } elseif ($lrs === 'approved') {
                                $cls  = '';
                                $disp = 'APPROVED';
                            } else {
                                $cls  = 'missed';
                                $disp = 'MISSED';
                            }
                        } elseif ($v) {
                            $cls  = '';
                            $disp = fmt12s($v);
                        } else {
                            $cls  = 'empty';
                            $disp = '—';
                        }
                    ?>
                    <td><span class="time-val<?= $cls ? " {$cls}" : '' ?>"><?= htmlspecialchars($disp) ?></span><?php if (($dayKind[$col] ?? 'late') === 'overtime' && in_array($lrs, ['pending','approved'], true)): ?> <span class="ot-tag" title="Overtime request (<?= htmlspecialchars($lrs) ?>)">OT</span><?php endif; ?></td>
                    <?php endforeach; ?>
                    <td><span class="hours-val <?= $hrs_cls ?>"><?= $hs > 0 ? fmtDuration($hs) : '—' ?></span></td>
                    <td><span class="status-pill <?= $st_cls ?>"><?= $st_label ?></span></td>
                </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
            <?php endif; ?>
        </div>

    </div><!-- end .shell -->

</div><!-- end .main-content -->

<?php if (!empty($chart_hours)): ?>
<script>
const chartLabels = <?= json_encode($chart_labels) ?>;
const chartHours  = <?= json_encode($chart_hours) ?>;
const REQ         = <?= json_encode((float)$required_per_day) ?>; // UPDATED: Required Hours per Day from Course Offering
const REQ_LABEL   = <?= json_encode($required_per_day_label) ?>;
const chartMet    = <?= json_encode(array_map(fn($s) => $s >= $required_per_day_seconds, $chart_seconds)) ?>; // exact-seconds check

const ctx = document.getElementById('hoursChart').getContext('2d');

const gradient = ctx.createLinearGradient(0, 0, 0, 220);
gradient.addColorStop(0, 'rgba(108,99,255,0.35)');
gradient.addColorStop(1, 'rgba(108,99,255,0.0)');

new Chart(ctx, {
    type: 'line',
    data: {
        labels: chartLabels,
        datasets: [
            {
                label: 'Duty hours',
                data: chartHours,
                borderColor: '#6c63ff',
                backgroundColor: gradient,
                borderWidth: 2.5,
                pointBackgroundColor: chartHours.map((h, i) => chartMet[i] ? '#43e97b' : h > 0 ? '#f9a826' : '#ff5c5c'),
                pointBorderColor: '#1a1d27',
                pointBorderWidth: 2,
                pointRadius: 5,
                pointHoverRadius: 7,
                fill: true,
                tension: 0.38,
            },
            {
                label: REQ_LABEL + 'h requirement',
                data: Array(chartLabels.length).fill(REQ),
                borderColor: '#ff6584',
                borderWidth: 1.5,
                borderDash: [5, 4],
                pointRadius: 0,
                fill: false,
                tension: 0,
            },
        ],
    },
    options: {
        responsive: true,
        maintainAspectRatio: false,
        interaction: { mode: 'index', intersect: false },
        plugins: {
            legend: { display: false },
            tooltip: {
                backgroundColor: '#1e2235',
                titleColor: '#7b7f9e',
                bodyColor: '#e8eaf0',
                borderColor: '#2a2e45',
                borderWidth: 1,
                padding: 10,
                callbacks: {
                    label: ctx => {
                        if (ctx.datasetIndex === 1) return `  Required: ${REQ_LABEL}h`;
                        const h = ctx.raw;
                        const diff = (h - REQ).toFixed(1);
                        const sign = diff >= 0 ? '+' : '';
                        return [`  Hours: ${h}h`, `  vs req: ${sign}${diff}h`];
                    },
                },
            },
        },
        scales: {
            x: {
                grid: { color: 'rgba(42,46,69,.6)' },
                ticks: { color: '#7b7f9e', font: { family: 'JetBrains Mono', size: 10 } },
            },
            y: {
                min: 0,
                max: Math.max(REQ + 2, ...chartHours) + 1,
                grid: { color: 'rgba(42,46,69,.6)' },
                ticks: {
                    color: '#7b7f9e',
                    font: { family: 'JetBrains Mono', size: 10 },
                    callback: v => v + 'h',
                    stepSize: 2,
                },
            },
        },
    },
});
</script>
<?php endif; ?>

<script>
/* ── SIDEBAR TOGGLE ── */
const sidebar   = document.getElementById('sidebar');
const toggleBtn = document.getElementById('toggleBtn');
toggleBtn.addEventListener('click', () => {
    sidebar.classList.toggle('collapsed');
    const mc = document.getElementById('mainContent');
    if (sidebar.classList.contains('collapsed')) {
        mc.style.marginLeft = '80px';
        mc.style.width = 'calc(100% - 80px)';
    } else {
        mc.style.marginLeft = '260px';
        mc.style.width = 'calc(100% - 260px)';
    }
    /* Keep ANB width in sync with sidebar state */
    document.getElementById('att-notif-bar')
            .classList.toggle('sidebar-collapsed', sidebar.classList.contains('collapsed'));
});

/* ── MONTH NAV ── */
function navMonth(m) {
    document.getElementById('month-input').value = m;
    document.getElementById('month-form').submit();
}

/* ── Journal empty-section badge (reads localStorage written by student_report.php) ── */
(function() {
    const JOURNAL_BADGE_LS_KEY = <?= json_encode('ojt_journal_empty_count_' . $user_id) ?>;
    const badge = document.getElementById('journalEmptyBadge');
    if (!badge) return;
    function refreshJournalBadge() {
        let count = 0;
        try { const raw = localStorage.getItem(JOURNAL_BADGE_LS_KEY); count = raw !== null ? parseInt(raw, 10) || 0 : 0; } catch(e) {}
        if (count > 0) { badge.textContent = count; badge.style.display = 'inline-flex'; }
        else { badge.style.display = 'none'; badge.textContent = ''; }
    }
    refreshJournalBadge();
    setInterval(refreshJournalBadge, 10000);
    window.addEventListener('storage', e => { if (e.key === JOURNAL_BADGE_LS_KEY) refreshJournalBadge(); });
})();

/* ══ ENHANCED THEME TOGGLE ══ */
const themeCheckbox  = document.getElementById('theme-toggle-checkbox');
const themeThumb     = document.getElementById('themeThumb');
const themeLabelText = document.getElementById('themeLabelText');
const THEME_KEY      = 'ojt_theme_preference';

function applyTheme(isLight) {
    if (isLight) {
        document.documentElement.classList.add('light-mode');
        themeCheckbox.checked      = true;
        themeThumb.textContent     = '☀️';
        themeLabelText.textContent = 'Light';
        localStorage.setItem(THEME_KEY, 'light');
    } else {
        document.documentElement.classList.remove('light-mode');
        themeCheckbox.checked      = false;
        themeThumb.textContent     = '🌙';
        themeLabelText.textContent = 'Dark';
        localStorage.setItem(THEME_KEY, 'dark');
    }
}
const savedTheme  = localStorage.getItem(THEME_KEY);
const prefersDark = window.matchMedia('(prefers-color-scheme: dark)').matches;
applyTheme(savedTheme === 'light' || (!savedTheme && !prefersDark));
themeCheckbox.addEventListener('change', () => applyTheme(themeCheckbox.checked));

/* ══════════════════════════════════════════════════════════════
   ATTENDANCE NOTIFICATION BAR — v3
   Ported from student_profile.php (full parity incl. PM late window).
══════════════════════════════════════════════════════════════ */

const ANB_BADGE_INFO     = <?= json_encode($attendance_badge_info) ?>;
const ANB_TODAY_SETTINGS = <?= json_encode($_att_today_settings) ?>;
const ANB_IS_WEEKEND     = <?= $_att_is_weekend ? 'true' : 'false' ?>;
const ANB_IS_ALL_DONE    = <?= json_encode((bool)$_att_all_done) ?>;
const ANB_ORDER          = ['am_time_in','am_time_out','pm_time_in','pm_time_out'];

/* ── Shell reference for push/pull ── */
const _dashShell = document.getElementById('dashShell');

/**
 * Central ANB state — single source of truth.
 */
const _anb = {
    shownWindows:     new Set(),
    dismissedWindows: new Set(),
    tickInterval:     null,
    autoHideTimer:    null,
    rafId:            null,
    showStartTs:      0,
    autoHideDuration: 10000,
    currentType:      '',
};

if (ANB_BADGE_INFO) {
    _anb.shownWindows.add(ANB_BADGE_INFO.type);
}

/* ── Helpers ── */
function _anbTimeToSec(t) {
    if (!t) return -1;
    const p = t.split(':');
    return parseInt(p[0],10)*3600 + parseInt(p[1],10)*60 + (p[2] ? parseInt(p[2],10) : 0);
}
function _anbNowSec() {
    const n = new Date();
    return n.getHours()*3600 + n.getMinutes()*60 + n.getSeconds();
}
function _anbFmt12(t) {
    if (!t) return '—';
    const p = t.split(':'); let h = parseInt(p[0],10), m = parseInt(p[1],10);
    const ap = h >= 12 ? 'PM' : 'AM'; h = h%12||12;
    return h + ':' + String(m).padStart(2,'0') + ' ' + ap;
}

/* ── Push shell down when bar is visible, restore when hidden ── */
function _anbSetShellPush(open) {
    if (_dashShell) _dashShell.classList.toggle('anb-open', open);
}

/**
 * _anbHide(type) — single dismissal gate.
 */
function _anbHide(type) {
    if (type) _anb.dismissedWindows.add(type);
    _anb.currentType = '';

    if (_anb.tickInterval  !== null) { clearInterval(_anb.tickInterval);  _anb.tickInterval  = null; }
    if (_anb.autoHideTimer !== null) { clearTimeout(_anb.autoHideTimer);  _anb.autoHideTimer = null; }
    if (_anb.rafId         !== null) { cancelAnimationFrame(_anb.rafId);  _anb.rafId         = null; }

    document.getElementById('att-notif-bar').classList.remove('anb-visible');

    /* Restore shell to original padding when bar closes */
    _anbSetShellPush(false);

    const prog = document.getElementById('anb-progress');
    if (prog) {
        prog.style.transition = 'none';
        prog.style.width = '0%';
        requestAnimationFrame(() => { prog.style.transition = ''; });
    }
}

/**
 * _anbShow(info) — shows the bar for a given attendance window.
 */
function _anbShow(info) {
    if (!info) return;
    const dow = new Date().getDay();
    if (ANB_IS_WEEKEND || dow === 0 || dow === 6 || ANB_IS_ALL_DONE) return;
    if (_anb.dismissedWindows.has(info.type)) return;

    _anb.currentType = info.type;
    _anb.showStartTs = performance.now();

    document.getElementById('anb-label').textContent  = info.label + ' is open';
    document.getElementById('anb-window').textContent = 'Window: ' + info.start_fmt + ' \u2013 ' + info.end_fmt;
    document.getElementById('anb-countdown').textContent = 'Calculating...';

    /* Action button label differs for late-window entries */
    const anbActionBtn = document.getElementById('anb-action-btn');
    if (info.is_late_window) {
        anbActionBtn.textContent = 'Request now';
    } else {
        anbActionBtn.textContent = 'Sign now';
    }
    /* Always redirect to student_attendance.php from dashboard */
    anbActionBtn.onclick = function() { window.location.href = 'student_attendance.php'; };

    document.getElementById('att-notif-bar')
            .classList.toggle('sidebar-collapsed', sidebar.classList.contains('collapsed'));

    if (_anb.tickInterval  !== null) { clearInterval(_anb.tickInterval);  _anb.tickInterval  = null; }
    if (_anb.autoHideTimer !== null) { clearTimeout(_anb.autoHideTimer);  _anb.autoHideTimer = null; }
    if (_anb.rafId         !== null) { cancelAnimationFrame(_anb.rafId);  _anb.rafId         = null; }

    /* ── RAF-driven progress bar ── */
    const prog     = document.getElementById('anb-progress');
    const duration = _anb.autoHideDuration;
    const startTs  = _anb.showStartTs;

    if (prog) {
        prog.style.transition = 'none';
        prog.style.width = '100%';
        /* Force reflow so the transition reset takes effect */
        void prog.offsetWidth;
    }

    /* Push shell down so bar doesn't overlap the top of the dashboard content */
    _anbSetShellPush(true);

    function rafTick(now) {
        const elapsed = now - startTs;
        const pct = Math.max(0, 100 - (elapsed / duration) * 100);
        if (prog) prog.style.width = pct + '%';
        if (pct > 0) {
            _anb.rafId = requestAnimationFrame(rafTick);
        } else {
            _anb.rafId = null;
            _anbHide(_anb.currentType);
        }
    }
    _anb.rafId = requestAnimationFrame(rafTick);

    const endSec = _anbTimeToSec(info.end_time);
    function tick() {
        const rem = endSec - _anbNowSec();
        if (rem <= 0) { _anbHide(_anb.currentType); return; }
        const m = Math.floor(rem / 60), s = rem % 60;
        document.getElementById('anb-countdown').textContent =
            '\u23F3 ' + m + 'm ' + String(s).padStart(2,'0') + 's left';
    }
    tick();
    _anb.tickInterval = setInterval(tick, 1000);

    const capturedType = info.type;
    _anb.autoHideTimer = setTimeout(function() {
        _anb.autoHideTimer = null;
        _anbHide(capturedType);
    }, duration);

    document.getElementById('att-notif-bar').classList.add('anb-visible');
}

/* ── Close button: reads live _anb.currentType at click time ── */
document.getElementById('anb-close-btn').addEventListener('click', function(e) {
    e.stopPropagation();
    _anbHide(_anb.currentType);
});

/**
 * _anbWatch() — polls every 30s for newly-opened windows.
 * Full parity with student_profile.php including PM late window.
 */
let _anbPmLateShown = false;

function _anbWatch() {
    const dow = new Date().getDay();
    if (ANB_IS_WEEKEND || dow === 0 || dow === 6 || !ANB_TODAY_SETTINGS || ANB_IS_ALL_DONE) return;

    const ns = _anbNowSec();
    const DEFS = {
        am_time_in:  { label: 'AM Duty Sign In',  startKey: 'am_time_in_start',  endKey: 'am_time_in_end'  },
        am_time_out: { label: 'AM Duty Sign Out', startKey: 'am_time_out_start', endKey: 'am_time_out_end' },
        pm_time_in:  { label: 'PM Duty Sign In',  startKey: 'pm_time_in_start',  endKey: 'pm_time_in_end'  },
        pm_time_out: { label: 'PM Duty Sign Out', startKey: 'pm_time_out_start', endKey: 'pm_time_out_end' },
    };

    /* Pass 1 — normal windows */
    for (const type of ANB_ORDER) {
        const def      = DEFS[type];
        const startStr = ANB_TODAY_SETTINGS[def.startKey];
        const endStr   = ANB_TODAY_SETTINGS[def.endKey];
        if (!startStr || !endStr) continue;
        const s = _anbTimeToSec(startStr), e = _anbTimeToSec(endStr);
        if (ns < s || ns > e)                    continue;
        if (_anb.dismissedWindows.has(type))     continue;
        if (_anb.shownWindows.has(type))         continue;

        _anb.shownWindows.add(type);
        _anbShow({
            type,
            label:           def.label,
            start_fmt:       _anbFmt12(startStr),
            end_fmt:         _anbFmt12(endStr),
            start_time:      startStr,
            end_time:        endStr,
            is_late_window:  false,
        });
        return;
    }

    /* Pass 2 — PM Sign Out 1-hour late window (mirrors student_profile.php) */
    if (_anbPmLateShown) return;
    const pmOutEndStr = ANB_TODAY_SETTINGS['pm_time_out_end'];
    if (!pmOutEndStr) return;
    const pmOutEndSec   = _anbTimeToSec(pmOutEndStr);
    const lateWindowEnd = pmOutEndSec + 3600;
    if (ns <= pmOutEndSec || ns > lateWindowEnd) return;

    if (ANB_IS_ALL_DONE) return;
    if (_anb.dismissedWindows.has('pm_time_out_late')) return;

    const lateWindowEndH   = Math.floor(lateWindowEnd / 3600);
    const lateWindowEndM   = Math.floor((lateWindowEnd % 3600) / 60);
    const lateWindowEndStr = String(lateWindowEndH).padStart(2,'0') + ':' + String(lateWindowEndM).padStart(2,'0') + ':00';

    _anbPmLateShown = true;
    _anb.shownWindows.add('pm_time_out_late');
    _anbShow({
        type:           'pm_time_out_late',
        label:          'PM Sign Out Late Request',
        start_fmt:      _anbFmt12(pmOutEndStr) + ' (missed)',
        end_fmt:        _anbFmt12(lateWindowEndStr) + ' (deadline)',
        start_time:     pmOutEndStr,
        end_time:       lateWindowEndStr,
        is_late_window: true,
    });
}

/* ── Initial page-load trigger ── */
(function() {
    const dow = new Date().getDay();
    if (ANB_BADGE_INFO && !ANB_IS_WEEKEND && dow !== 0 && dow !== 6 && !ANB_IS_ALL_DONE) {
        setTimeout(function() { _anbShow(ANB_BADGE_INFO); }, 800);
    }
})();

/* ── Periodic watch for newly-opened windows ── */
setInterval(_anbWatch, 30000);
</script>
</body>
</html>