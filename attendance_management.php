<?php
session_start();
include "db.php";

if (!isset($_SESSION['user_id'])) {
    die("Unauthorized access.");
}

$company_id = $_SESSION['user_id'];

// ================= FETCH INBOX COUNT =================
$inbox_count = 0;
$stmt_inbox = $conn->prepare("SELECT COUNT(*) as total FROM ojt_applications WHERE company_id=? AND phase='pending'");
$stmt_inbox->bind_param("i", $company_id);
$stmt_inbox->execute();
$res_inbox = $stmt_inbox->get_result()->fetch_assoc();
$inbox_count = $res_inbox['total'] ?? 0;
$stmt_inbox->close();

// ================= FETCH UNGRADED COUNT =================
$ungraded_count = 0;
$stmt_ungraded = $conn->prepare("
    SELECT COUNT(*) as total
    FROM reports r
    JOIN ojt_assignments oa ON oa.student_id = r.user_id AND oa.company_id = r.company_id
    WHERE r.company_id = ?
      AND r.week_start <= CURDATE()
      AND (r.remark IS NULL OR r.remark != 'Wrong Document')
      AND r.company_grade IS NULL
");
$stmt_ungraded->bind_param("i", $company_id);
$stmt_ungraded->execute();
$res_ungraded = $stmt_ungraded->get_result()->fetch_assoc();
$ungraded_count = $res_ungraded['total'] ?? 0;
$stmt_ungraded->close();

// ================= FETCH PENDING LATE REQUESTS COUNT (for sidebar badge) =================
$pending_lr_count = 0;
$stmt_plr = $conn->prepare("SELECT COUNT(*) as total FROM late_requests WHERE company_id=? AND status='pending'");
$stmt_plr->bind_param("i", $company_id);
$stmt_plr->execute();
$res_plr = $stmt_plr->get_result()->fetch_assoc();
$pending_lr_count = $res_plr['total'] ?? 0;
$stmt_plr->close();

// ================= FETCH SUPERVISOR NAME =================
$supervisor_name_display = 'Supervisor';
$stmt_sv = $conn->prepare("SELECT first_name, middle_name, last_name FROM users WHERE id=? LIMIT 1");
$stmt_sv->bind_param("i", $company_id);
$stmt_sv->execute();
$sv_row = $stmt_sv->get_result()->fetch_assoc();
$stmt_sv->close();
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
   UPDATED (Live updates instead of auto page reload): a small fingerprint
   of everything this page displays (attendance logs, late requests,
   schedules, assigned students, course rules and today's date). The page
   polls it and, only when it changes, pulls the fresh page in the
   background and swaps the affected panels in place — no page reload.
   ════════════════════════════════════════════════════════════════════ */
if (!function_exists('attm_live_signature')) {
    function attm_live_signature($conn, $company_id): string {
        $cid   = (int)$company_id;
        $parts = [date('Y-m-d')];
        $queries = [
            "SELECT COUNT(*) AS c, COALESCE(SUM(CRC32(CONCAT_WS('|', user_id, date, IFNULL(am_time_in,''), IFNULL(am_time_out,''), IFNULL(pm_time_in,''), IFNULL(pm_time_out,'')))), 0) AS s
             FROM attendance_logs WHERE company_id = $cid",
            "SELECT COUNT(*) AS c, COALESCE(SUM(CRC32(CONCAT_WS('|', id, IFNULL(status,''), date, IFNULL(type,'')))), 0) AS s
             FROM late_requests WHERE company_id = $cid",
            "SELECT COUNT(*) AS c, COALESCE(SUM(CRC32(CONCAT_WS('|', date, IFNULL(am_time_in_start,''), IFNULL(am_time_in_end,''), IFNULL(am_time_out_start,''), IFNULL(am_time_out_end,''), IFNULL(pm_time_in_start,''), IFNULL(pm_time_in_end,''), IFNULL(pm_time_out_start,''), IFNULL(pm_time_out_end,'')))), 0) AS s
             FROM attendance_settings WHERE company_id = $cid",
            "SELECT COUNT(*) AS c, COALESCE(SUM(student_id), 0) AS s FROM ojt_assignments WHERE company_id = $cid",
            "SELECT COUNT(*) AS c, COALESCE(SUM(CRC32(CONCAT_WS('|', course, total_hours, daily_hours))), 0) AS s FROM course_offerings",
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

    $log_res = $conn->query("
        SELECT user_id, date, am_time_in, am_time_out, pm_time_in, pm_time_out
        FROM attendance_logs
        WHERE company_id = $company_id
          AND date BETWEEN '$exp_start' AND '$exp_end'
    ");
    $exp_logs = [];
    while ($lr = $log_res->fetch_assoc()) {
        $dow = (int)date('w', strtotime($lr['date']));
        $wknd = ($dow === 0 || $dow === 6);
        if ($wknd) {
            $st = 'DAY OFF';
        } else {
            $duty = getActiveDutyPeriods($lr['date'], $all_settings_map);
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

function timeToMins($time) {
    if (!$time) return -1;
    $parts = explode(':', $time);
    return (int)$parts[0] * 60 + (int)$parts[1];
}

// late_requests.request_type: 'late' (original behaviour) or 'overtime'. The column is added
// automatically the first time it is needed so existing databases keep working.
if (!function_exists('ensureLateRequestTypeColumn')) {
    function ensureLateRequestTypeColumn($conn) {
        static $ok = null;
        if ($ok !== null) return $ok;
        $exists = function() use ($conn) {
            $r = $conn->query("SHOW COLUMNS FROM late_requests LIKE 'request_type'");
            return $r && $r->num_rows > 0;
        };
        try {
            if ($exists()) return $ok = true;
            $conn->query("ALTER TABLE late_requests ADD COLUMN request_type ENUM('late','overtime') NOT NULL DEFAULT 'late' AFTER type");
        } catch (\Throwable $e) {
            // another request may have added it first - fall through to the re-check
        }
        try { return $ok = $exists(); } catch (\Throwable $e) { return $ok = false; }
    }
}

// ─────────────────────────────────────────────────────────────────────────────
// AJAX HANDLERS
// ─────────────────────────────────────────────────────────────────────────────

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_SERVER['HTTP_X_REQUESTED_WITH'])) {

    $action = $_POST['action'] ?? '';

    if ($action === 'approve_late_request') {
        header('Content-Type: application/json');

        $req_id = (int)($_POST['req_id'] ?? 0);
        if (!$req_id) { echo json_encode(['success'=>false,'message'=>'Invalid request.']); exit; }

        $stmt = $conn->prepare("SELECT * FROM late_requests WHERE id=? AND company_id=? AND status='pending'");
        $stmt->bind_param("ii", $req_id, $company_id);
        $stmt->execute();
        $lr = $stmt->get_result()->fetch_assoc();

        if (!$lr) { echo json_encode(['success'=>false,'message'=>'Request not found or already processed.']); exit; }

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
        $requestType   = (($lr['request_type'] ?? 'late') === 'overtime') ? 'overtime' : 'late';

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

        /* ── Late request vs Overtime (sign-out entries only) ──
           Duty time everywhere in the system is (sign out − sign in), so the time recorded for the
           sign out is what decides the hours credited:
             • Overtime     → counted from the duty Sign In up to the moment the request was submitted.
             • Late request → counts only that duty (AM Sign In → AM Sign Out): the Sign Out is credited
                              at the scheduled sign-out time instead of the (later) submission time. */
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

            if ($requestType === 'overtime') {
                if ($inTs === null) {
                    echo json_encode(['success'=>false,'message'=>'Cannot approve overtime: the student has no recorded ' . strtoupper($period) . ' Sign In to count it from. Reject it or ask the student to resubmit as a late request.']);
                    exit;
                }
                if ($createdTs !== false && $createdTs < $inTs) $approved_time = date('Y-m-d H:i:s', $inTs);
            } else {
                $schedOut = $settingRow[$period . '_time_out_start'] ?? null;
                $schedTs  = $schedOut ? $toTs($schedOut) : null;
                if ($schedTs !== null) {
                    if ($createdTs !== false && $schedTs > $createdTs) $schedTs = $createdTs;   // never later than the submission
                    if ($inTs !== null && $schedTs < $inTs)            $schedTs = $inTs;         // never before the sign in (0 min)
                    $approved_time = date('Y-m-d H:i:s', $schedTs);
                }
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

        $approvedLabel = ($requestType === 'overtime') ? 'overtime' : 'time';
        echo json_encode([
            'success'       => true,
            'message'       => "Approved. {$sName} {$approvedLabel} and photo updated recorded.",
            'request_type'  => $requestType,
            'recorded_time' => $approved_time,
            'duty_info'     => $dutyInfo,
            'req_id'        => $req_id,
            'pending_count' => $newPendingCount,
        ]);
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

        if (!$skip_am && (!$am_time_in_start || !$am_time_in_end || !$am_time_out_start || !$am_time_out_end)) {
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
        $dutyMins = function($start, $outStart) use ($toMins) {
            $s = $toMins($start); $e = $toMins($outStart);
            if ($s === null || $e === null) return 0;
            return max(0, $e - $s);
        };
        $MAX_DUTY_MINS   = 8 * 60;
        $am_duty_mins    = $skip_am ? 0 : $dutyMins($am_time_in_start, $am_time_out_start);
        $pm_duty_mins    = $skip_pm ? 0 : $dutyMins($pm_time_in_start, $pm_time_out_start);
        $total_duty_mins = $am_duty_mins + $pm_duty_mins;
        if ($total_duty_mins > $MAX_DUTY_MINS) {
            $th = intdiv($total_duty_mins, 60); $tm = $total_duty_mins % 60;
            ob_end_clean();
            echo json_encode([
                'success' => false,
                'message' => 'The schedule totals ' . $th . 'h' . ($tm ? ' ' . $tm . 'm' : '') . '. The maximum allowed duty time is 8 hours per day. Please adjust the time windows.',
            ]);
            exit;
        }

        $ojt_start_stmt = $conn->prepare("SELECT MIN(date) as sd FROM attendance_settings WHERE company_id=?");
        $ojt_start_stmt->bind_param("i", $company_id);
        $ojt_start_stmt->execute();
        $ojt_sd_row = $ojt_start_stmt->get_result()->fetch_assoc();
        $ojt_start_for_limit = $ojt_sd_row['sd'] ?? $date;
        $end_date_limit_for_save = date("Y-m-d", strtotime("+4 months", strtotime($ojt_start_for_limit)));

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
        $schedule_scope = "Starting {$formatted_date} — applied to today and ALL remaining OJT weekdays through " . date("F j, Y", strtotime($end_date_limit_for_save)) . ". Past days are not affected.";

        $am_in_range  = $am_time_in_start  ? fmt12($am_time_in_start)  . ' – ' . fmt12($am_time_in_end)  : 'Skipped';
        $am_out_range = $am_time_out_start ? fmt12($am_time_out_start) . ' – ' . fmt12($am_time_out_end) : 'Skipped';
        $pm_in_range  = $pm_time_in_start  ? fmt12($pm_time_in_start)  . ' – ' . fmt12($pm_time_in_end)  : 'Skipped';
        $pm_out_range = $pm_time_out_start ? fmt12($pm_time_out_start) . ' – ' . fmt12($pm_time_out_end) : 'Skipped';

        require_once __DIR__ . '/attendance_email_handler.php';
        $emailResult = sendAttendanceScheduleEmails(
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

    $lrTypeSel = ensureLateRequestTypeColumn($conn) ? 'lr.request_type' : "'late' AS request_type";
    $stmt = $conn->prepare("
        SELECT
            lr.id,
            lr.student_id,
            lr.date,
            lr.type,
            {$lrTypeSel},
            lr.reason,
            lr.status,
            lr.created_at,
            lr.photo IS NOT NULL AS has_photo,
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
        LIMIT 80
    ");
    $stmt->bind_param("i", $company_id);
    $stmt->execute();
    $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);

    foreach ($rows as &$row) {
        $row['request_type'] = (($row['request_type'] ?? 'late') === 'overtime') ? 'overtime' : 'late';
        $row['has_photo'] = (bool)$row['has_photo'];
        $row['photo_url'] = "late_request_photo.php?id={$row['id']}&t=" . time();
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

$end_date_limit = date("Y-m-d", strtotime("+4 months", strtotime($start_date)));

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
$ojt_limit_ts = strtotime($end_date_limit);
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
    $ts_started_sql = !empty($ts_started) ? implode(',', $ts_started) : '0';
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
        $total      = count($ts_all_ids); // UPDATED: header still shows every assigned student
        $today_stats = [
            'present'    => $present,
            'absent'     => $absent,
            'incomplete' => $incomplete,
            'day_off'    => 0,
            'total'      => $total,
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

$month = $_GET['month'] ?? date("Y-m", strtotime($start_date));
$ojt_start_month = date("Y-m", strtotime($start_date));
$month_min = $ojt_start_month;
$month_max = date("Y-m", strtotime($end_date_limit));
if ($month < $month_min) $month = $month_min;
if ($month > $month_max) $month = $month_max;

$start = max($start_date, $month . "-01");
if (date("Y-m", strtotime($start_date)) === $month) {
    $start = $start_date;
} else {
    $start = $month . "-01";
}
$end   = date("Y-m-t", strtotime($month . "-01"));
if ($end > $end_date_limit) $end = $end_date_limit;

$students = [];
$res = $conn->query("
    SELECT u.id, u.first_name, u.middle_name, u.last_name
    FROM ojt_assignments oa
    JOIN users u ON oa.student_id = u.id
    WHERE oa.company_id = $company_id
");
while ($row = $res->fetch_assoc()) { $students[$row['id']] = $row; }
$student_first_attendance = attm_first_attendance_map($conn, array_keys($students)); // UPDATED (Start = first attendance)

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
$res = $conn->query("
    SELECT user_id, date, am_time_in, am_time_out, pm_time_in, pm_time_out
    FROM attendance_logs
    WHERE company_id = $company_id AND date BETWEEN '$start' AND '$end'
");
while ($row = $res->fetch_assoc()) {
    $dow  = (int)date('w', strtotime($row['date']));
    $wknd = ($dow === 0 || $dow === 6);
    if ($wknd) {
        $status = "DAY OFF";
    } else {
        $duty   = getActiveDutyPeriods($row['date'], $all_settings_map);
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
$cm = strtotime(date("Y-m-01", strtotime($start_date)));
$cm_end = strtotime(date("Y-m-01", strtotime($end_date_limit)));
while ($cm <= $cm_end) {
    $all_chart_months[] = date("Y-m", $cm);
    $cm = strtotime("+1 month", $cm);
}

$today_str = date("Y-m-d");

$all_logs_res = $conn->query("
    SELECT user_id, date, am_time_in, am_time_out, pm_time_in, pm_time_out
    FROM attendance_logs
    WHERE company_id = $company_id
      AND date BETWEEN '$start_date' AND '$today_str'
");
$all_logs = [];
while ($r = $all_logs_res->fetch_assoc()) {
    $all_logs[$r['user_id']][$r['date']] = $r;
}

// ── Monthly chart stats ───────────────────────────────────────────────────────
// Also updated to use getActiveDutyPeriods() + computeStatusForLog()
$monthly_stats = [];

foreach ($all_chart_months as $ym) {
    $ym_start = (date('Y-m', strtotime($start_date)) === $ym) ? $start_date : $ym . '-01';
    $ym_end   = date('Y-m-t', strtotime($ym . '-01'));
    if ($ym_end > $end_date_limit) $ym_end = $end_date_limit;

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
            if (isset($all_logs[$sid][$day_str])) {
                $lr2    = $all_logs[$sid][$day_str];
                $status = computeStatusForLog($lr2, $duty['am'], $duty['pm']);
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
<html>
<head>
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
.sidebar-badge-ungraded { background: #d97706; color: white; border-radius: 50%; width: 18px; height: 18px; font-size: 10px; font-weight: 700; display: inline-flex; align-items: center; justify-content: center; position: absolute; right: 18px; top: 50%; transform: translateY(-50%); }

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
.sm-table td.sm-before-start { background:#f8fafc; color:#cbd5e1; } /* UPDATED: before first attendance */
.sm-table td.sm-has-mark { box-shadow:inset 0 0 0 1px rgba(21,101,192,.25); }
.sm-table tbody tr:hover td { filter:brightness(0.97); }
.sm-table tbody tr:last-child td { border-bottom:none; }
.sm-table-empty { text-align:center; padding:30px; color:#aaa; font-size:12px; }

#toastContainer { position:fixed; bottom:20px; right:20px; z-index:9999; display:flex; flex-direction:column; gap:8px; }
.toast { background:#323232; color:#fff; padding:12px 20px; border-radius:8px; font-size:13px; max-width:320px;
    opacity:0; transform:translateY(10px); transition:opacity .3s,transform .3s; box-shadow:0 3px 12px rgba(0,0,0,.2); }
.toast.show    { opacity:1; transform:translateY(0); }
.toast.success { background:#2e7d32; }
.toast.error   { background:#c62828; }
.toast.warning { background:#e65100; }
.toast.info    { background:#1565c0; }

td.day-off   { background:#ede7f6 !important; color:#512da8; font-weight:700; text-align:center; }
td.present   { color:#2e7d32; font-weight:700; text-align:center; }
td.absent    { color:#c62828; font-weight:700; text-align:center; }
td.incomplete{ color:#e65100; font-weight:700; text-align:center; }
td.pending   { color:#888;    font-weight:600; text-align:center; }
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
.as-footer { padding:0 26px 22px; display:flex; justify-content:flex-end; gap:10px; }

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
.hp-btn-continue { padding:11px 22px; background:#e65100; color:#fff; border:none; border-radius:9px; font-size:14px; font-weight:700; cursor:pointer; transition:background .2s; }
.hp-btn-continue:hover { background:#bf360c; }

#continueAnywayOverlay { display:none; position:fixed; inset:0; background:rgba(0,0,0,.55); z-index:21000; align-items:center; justify-content:center; backdrop-filter:blur(4px); }
#continueAnywayOverlay.open { display:flex; }
#continueAnywayBox { background:#fff; border-radius:18px; width:480px; max-width:95vw; box-shadow:0 28px 70px rgba(0,0,0,.28); overflow:hidden; animation:wiz-in .3s cubic-bezier(.34,1.56,.64,1); }
.ca-header { background:linear-gradient(135deg,#e65100,#f57c00); color:#fff; padding:18px 24px 14px; display:flex; align-items:center; gap:12px; }
.ca-header-icon { font-size:28px; line-height:1; flex-shrink:0; }
.ca-header-text-title { font-size:16px; font-weight:700; }
.ca-header-text-sub { font-size:12px; opacity:.85; margin-top:2px; }
.ca-body { padding:20px 24px; }
.ca-summary-label { font-size:11px; font-weight:700; color:#e65100; text-transform:uppercase; letter-spacing:.6px; margin-bottom:8px; }
.ca-summary-box { background:#fff8e1; border:1px solid #ffe082; border-radius:10px; padding:14px 16px; margin-bottom:14px; }
.ca-summary-grid { display:grid; grid-template-columns:1fr 1fr; gap:8px; }
.ca-sg-card { background:#fff; border-radius:7px; padding:8px 10px; }
.ca-sg-card .ca-sg-lbl { font-size:10px; font-weight:700; color:#888; text-transform:uppercase; }
.ca-sg-card .ca-sg-val { font-size:13px; font-weight:600; color:#222; margin-top:2px; }
.ca-total-row { margin-top:10px; background:#fef2f2; border-radius:7px; padding:8px 12px; display:flex; justify-content:space-between; align-items:center; }
.ca-total-row .ca-total-lbl { font-size:12px; color:#888; }
.ca-total-row .ca-total-val { font-size:14px; font-weight:700; }
.ca-warning-box { background:#fef2f2; border:1px solid #fecaca; border-radius:8px; padding:10px 13px; font-size:12px; color:#c62828; font-weight:600; display:flex; align-items:flex-start; gap:6px; line-height:1.5; }
.ca-footer { padding:0 24px 20px; display:flex; gap:10px; justify-content:flex-end; }
.ca-btn-cancel { padding:10px 22px; border-radius:9px; background:#f5f5f5; color:#555; border:none; font-size:14px; font-weight:600; cursor:pointer; transition:background .2s; }
.ca-btn-cancel:hover { background:#e8e8e8; color:#333; }
.ca-btn-confirm { padding:10px 22px; border-radius:9px; background:#e65100; color:#fff; border:none; font-size:14px; font-weight:700; cursor:pointer; transition:background .2s; }
.ca-btn-confirm:hover { background:#bf360c; }
.ca-btn-confirm.loading { opacity:.6; pointer-events:none; }
.ca-btn-confirm.loading::after { content:''; display:inline-block; width:11px; height:11px; border:2px solid rgba(255,255,255,.4); border-top-color:#fff; border-radius:50%; animation:spin .7s linear infinite; margin-left:8px; vertical-align:middle; }

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
.req-kind-badge { display:inline-block; padding:3px 10px; border-radius:20px; font-size:11px; font-weight:700; color:#fff; }
.req-kind-badge.late { background:#5A6272; }
.req-kind-badge.overtime { background:#6a1b9a; }
.req-kind-note { margin-top:8px; font-size:12px; color:#5A6272; line-height:1.5; }
.req-kind-note strong { color:#1B2A4A; }
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

#emailSendingOverlay {
    display: none;
    position: fixed;
    inset: 0;
    background: rgba(0,0,0,0.65);
    z-index: 50000;
    align-items: center;
    justify-content: center;
    backdrop-filter: blur(6px);
}
#emailSendingOverlay.open {
    display: flex;
}
#emailSendingBox {
    background: #fff;
    border-radius: 20px;
    width: 360px;
    max-width: 92vw;
    box-shadow: 0 28px 70px rgba(0,0,0,0.30);
    overflow: hidden;
    animation: wiz-in .3s cubic-bezier(.34,1.56,.64,1);
    text-align: center;
}
.esb-header {
    background: linear-gradient(135deg, #1565c0, #1976d2);
    padding: 28px 24px 22px;
}
.esb-spinner-wrap {
    width: 64px;
    height: 64px;
    border-radius: 50%;
    background: rgba(255,255,255,0.15);
    border: 2px solid rgba(255,255,255,0.3);
    display: flex;
    align-items: center;
    justify-content: center;
    margin: 0 auto 16px;
}
.esb-spinner {
    width: 36px;
    height: 36px;
    border: 4px solid rgba(255,255,255,0.3);
    border-top-color: #fff;
    border-radius: 50%;
    animation: spin 0.8s linear infinite;
}
.esb-header h3 {
    margin: 0 0 6px;
    font-size: 18px;
    font-weight: 800;
    color: #fff;
    line-height: 1.2;
}
.esb-header p {
    margin: 0;
    font-size: 13px;
    color: rgba(255,255,255,0.75);
    line-height: 1.5;
}
.esb-body {
    padding: 20px 24px 24px;
}
.esb-steps {
    display: flex;
    flex-direction: column;
    gap: 10px;
    text-align: left;
}
.esb-step {
    display: flex;
    align-items: center;
    gap: 10px;
    font-size: 13px;
    color: #555;
    padding: 8px 12px;
    border-radius: 8px;
    background: #f8fafc;
    border: 1px solid #e9ecef;
    transition: all 0.3s;
}
.esb-step.active {
    background: #e8f5e9;
    border-color: #a5d6a7;
    color: #1b5e20;
    font-weight: 600;
}
.esb-step.done {
    background: #f0fdf4;
    border-color: #bbf7d0;
    color: #15803d;
}
.esb-step-icon {
    width: 22px;
    height: 22px;
    border-radius: 50%;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 11px;
    flex-shrink: 0;
    background: #e0e0e0;
    color: #888;
    transition: all 0.3s;
}
.esb-step.active .esb-step-icon {
    background: #1976d2;
    color: #fff;
    animation: esb-pulse 1.2s ease-in-out infinite;
}
.esb-step.done .esb-step-icon {
    background: #16a34a;
    color: #fff;
}
@keyframes esb-pulse {
    0%,100% { box-shadow: 0 0 0 0 rgba(25,118,210,0.5); }
    50%      { box-shadow: 0 0 0 5px rgba(25,118,210,0); }
}
.esb-note {
    margin-top: 16px;
    font-size: 11px;
    color: #9ca3af;
    line-height: 1.5;
    text-align: center;
}
</style>
</head>
<body>

<div id="sidebar" class="sidebar">
    <div class="sidebar-header">
        <div class="sidebar-user-info">
            <span class="sidebar-user-name"><?= htmlspecialchars($supervisor_name_display) ?></span>
            <span class="sidebar-user-role">Supervisor</span>
        </div>
        <button id="toggleBtn" class="toggle-btn"><i class="fas fa-bars"></i></button>
    </div>
    <div class="sidebar-links">
        <a href="Profile.php">
            <i class="fas fa-user-circle"></i>
            <span class="link-text">My Profile</span>
        </a>
        <a href="add_ojt_student.php">
            <i class="fas fa-user-graduate"></i>
            <span class="link-text">OJT Student List</span>
            <?php if ($inbox_count > 0): ?>
                <span class="sidebar-badge"><?= $inbox_count ?></span>
            <?php endif; ?>
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
            <?php if ($ungraded_count > 0): ?>
                <span class="sidebar-badge-ungraded"><?= $ungraded_count ?></span>
            <?php endif; ?>
        </a>
    </div>
    <div class="logout-link">
        <a href="login.php">
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
                <h3><i class="fas fa-chart-bar" style="color:#1565c0;font-size:13px;"></i> Attendance Overview</h3>
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
                    <span class="chart-legend-item"><span class="chart-legend-dot" style="background:#2e7d32;"></span>Present</span>
                    <span class="chart-legend-item"><span class="chart-legend-dot" style="background:#e65100;"></span>Incomplete</span>
                    <span class="chart-legend-item"><span class="chart-legend-dot" style="background:#c62828;"></span>Absent</span>
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
                                { label: 'Present', data: present, backgroundColor: '#2e7d32', borderRadius: 6, borderSkipped: false },
                                { label: 'Incomplete', data: incomplete, backgroundColor: '#e65100', borderRadius: 6, borderSkipped: false },
                                { label: 'Absent', data: absent, backgroundColor: '#c62828', borderRadius: 6, borderSkipped: false }
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
                <h3><i class="fas fa-table" style="color:#1565c0;font-size:13px;"></i> Monthly Attendance Summary</h3>
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
                        $sm_month_params['date'] = $date;
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
                            <?php if ($sm_next_month <= $month_max): ?>
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
                        <a class="sm-action-btn export" href="export_attendance_xlsx.php?month=<?= htmlspecialchars($month) ?>" target="_blank">
                            <i class="fas fa-file-excel"></i> Export XLSX
                        </a>
                    </div>
                </div>

                <div class="sm-legend">
                    <span class="sm-legend-item"><span class="sm-legend-dot" style="background:#d1fae5;border:1px solid #6ee7b7;"></span><span style="color:#03543f;">P — Present</span></span>
                    <span class="sm-legend-item"><span class="sm-legend-dot" style="background:#fffbeb;border:1px solid #fcd34d;"></span><span style="color:#92400e;">I — Incomplete</span></span>
                    <span class="sm-legend-item"><span class="sm-legend-dot" style="background:#fef2f2;border:1px solid #fca5a5;"></span><span style="color:#9b1c1c;">A — Absent</span></span>
                    <span class="sm-legend-item"><span class="sm-legend-dot" style="background:#f3f0ff;border:1px solid #c4b5fd;"></span><span style="color:#7c3aed;">O — Day Off</span></span>
                    <span class="sm-legend-item"><i class="fas fa-play-circle sm-mark-start" style="font-size:10px;"></i><span style="color:#2C5A2C;">OJT Start</span></span>
                    <span class="sm-legend-item"><i class="fas fa-stop-circle sm-mark-end" style="font-size:10px;"></i><span style="color:#A02A2A;">OJT End</span></span>
                    <span class="sm-legend-item"><i class="fas fa-stop-circle sm-mark-end sm-mark-est" style="font-size:10px;"></i><span style="color:#A02A2A;">OJT End (est.)</span></span>
                    <span class="sm-legend-item"><span class="sm-legend-dot" style="background:#f8fafc;border:1px solid #e2e8f0;"></span><span style="color:#64748b;">Blank — Before first attendance</span></span>
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
                                    $val = ''; $cls = '';
                                } elseif (attm_before_start($student_first_attendance, $id, $d)) {
                                    $val = ''; $cls = 'sm-before-start'; // UPDATED: before first attendance — not absent / missed
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
                            ?>
                            <td class="<?= $td_cls ?>"><?= $mark_html ?><?= $val ?></td>
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
                <h3><i class="fas fa-bolt" style="color:#f59e0b;font-size:13px;"></i> Quick Actions</h3>
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
                <h3><i class="fas fa-calendar-check" style="color:#1565c0;font-size:13px;"></i> Today's Stats</h3>
            </div>
            <div class="panel-card-body" style="padding:14px 16px;">
                <div style="font-size:11px; color:#9ca3af; margin-bottom:10px; font-weight:600;">
                    <?= date("l, F j") ?> &middot; <?= $today_stats['total'] ?> student<?= $today_stats['total'] !== 1 ? 's' : '' ?>
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
                <div style="margin-top:10px; background:#fef2f2; border:1px solid #fecaca; border-radius:9px; padding:9px 12px; font-size:12px; color:#c62828; font-weight:600; display:flex; align-items:center; gap:7px; cursor:pointer;" onclick="openLateInbox()">
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

<div id="toastContainer"></div>

<!-- ══ ATTENDANCE LOG MODAL ══ -->
<div id="attLogModal">
    <div style="background:#fff; border-radius:18px; width:1100px; max-width:100%; box-shadow:0 28px 70px rgba(0,0,0,.25); animation:sm-in .3s cubic-bezier(.34,1.56,.64,1); margin:auto; overflow:hidden;">
        <div style="background:linear-gradient(135deg,#1565c0,#1976d2); color:#fff; padding:18px 24px; display:flex; align-items:center; justify-content:space-between;">
            <h3 style="margin:0; font-size:18px; font-weight:700; display:flex; align-items:center; gap:8px;">
                <i class="fas fa-clipboard-list"></i> Attendance Log
                <span id="attLogTitle" style="font-weight:400; font-size:16px; opacity:0.9;"></span>
            </h3>
            <button onclick="closeAttLogModal()" style="background:rgba(255,255,255,.15); border:none; color:#fff; border-radius:8px; padding:6px 12px; font-size:13px; font-weight:600; cursor:pointer;">
                <i class="fas fa-times"></i> Close
            </button>
        </div>
        <div style="padding:20px 24px;">
            <div style="display:flex; align-items:center; gap:12px; flex-wrap:wrap; margin-bottom:16px;">
                <input type="text" id="searchInput" placeholder="Search student...">
                <div style="display:flex; align-items:center; gap:8px; background:#f5f5f5; padding:8px 12px; border-radius:8px;">
                    <label style="font-size:13px; font-weight:600; color:#555; white-space:nowrap;">Month:</label>
                    <select id="modalMonthFilter" onchange="onModalMonthChange()"
                            style="padding:6px 10px; border:1px solid #ddd; border-radius:6px; font-size:13px;">
                        <?php
                        for ($m = strtotime($ojt_start_month); $m <= strtotime($month_max); $m = strtotime('+1 month', $m)) {
                            $month_val = date('Y-m', $m);
                            $month_name = date('F Y', $m);
                            echo "<option value='$month_val'>$month_name</option>";
                        }
                        ?>
                    </select>
                </div>
                <div style="display:flex; align-items:center; gap:6px; background:#f5f5f5; padding:6px 10px; border-radius:8px;">
                    <button id="modalPrevDay" onclick="modalShiftDay(-1)"
                        style="background:#1565c0; color:#fff; border:none; border-radius:6px; padding:5px 11px; font-size:13px; font-weight:700; cursor:pointer; transition:background .15s;"
                        onmouseover="this.style.background='#0d47a1'" onmouseout="this.style.background='#1565c0'">&#8592;</button>
                    <select id="modalDayFilter" onchange="modalGoToDay()"
                            style="width:72px; padding:6px 8px; border:1px solid #ddd; border-radius:6px; font-size:13px;"></select>
                    <button id="modalNextDay" onclick="modalShiftDay(1)"
                        style="background:#1565c0; color:#fff; border:none; border-radius:6px; padding:5px 11px; font-size:13px; font-weight:700; cursor:pointer; transition:background .15s;"
                        onmouseover="this.style.background='#0d47a1'" onmouseout="this.style.background='#1565c0'">&#8594;</button>
                </div>
            </div>

            <div id="attLogSpinner" style="display:none; text-align:center; padding:40px; color:#888; font-size:14px;">
                <div style="display:inline-block; width:28px; height:28px; border:3px solid #e0e0e0; border-top-color:#1565c0; border-radius:50%; animation:spin .7s linear infinite; vertical-align:middle; margin-right:10px;"></div>
                Loading attendance…
            </div>

            <div class="table-box" id="attLogTableBox">
                <h2 id="attLogDateHeading" style="margin:0 0 12px; font-size:16px; color:#333;"></h2>
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
            <button id="openWizardBtn" onclick="openWizardFromSettings()" style="display:inline-flex;align-items:center;gap:8px;padding:10px 20px;background:#1976d2;color:#fff;border:none;border-radius:9px;font-size:14px;font-weight:600;cursor:pointer;transition:background .2s;margin-bottom:4px;">
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
            <p style="font-size:13px;color:#888;margin:0;">Navigate to today's date to configure the attendance schedule.</p>
            <?php endif; ?>
        </div>
        <div class="as-footer">
            <button class="sm-action-btn print" onclick="closeAttSettings()">Close</button>
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
            <input type="checkbox" id="skipAmCheckbox" onchange="toggleAmFields()">
            <span><i class="fas fa-ban" style="color:#e65100;"></i> Skip AM Duty (No morning attendance required)</span>
          </label>
        </div>
        <div id="amFieldsContainer">
          <div class="field-row">
            <div class="field-group">
              <label>Sign-In Opens</label>
              <input type="time" id="w_am_ti_s" onchange="validateAmInField(this)">
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
              <input type="time" id="w_am_to_s" onchange="clearAmError()">
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
            <input type="checkbox" id="skipPmCheckbox" onchange="togglePmFields()">
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

    <!-- STEP 3: Confirm & Save -->
    <div class="wiz-step" id="wizStep3" style="display:none">
      <div class="wiz-header">
        <div class="wiz-step-label">Step 3 of 3</div>
        <h2>Confirm &amp; Save</h2>
        <p>Review the schedule below. It will be applied to <strong>today and all future OJT weekdays</strong>. Past days will <strong>not</strong> be affected. Students &amp; admins will be notified by email.</p>
      </div>
      <div class="wiz-body">
        <div id="bothSkippedWarning" style="display:none;" class="skip-warning">
          <i class="fas fa-exclamation-triangle"></i> You are trying to skip both AM and PM duty times. At least one duty period must be configured.
        </div>
        <div class="sg-scope scope-all" id="sumScope">
            <i class="fas fa-calendar-alt"></i>
            Applies to today + <?= $remaining_ojt_weekdays ?> remaining OJT weekday(s) through <?= date("F j, Y", strtotime($end_date_limit)) ?>. Past days are unchanged.
        </div>
        <div class="summary-grid">
          <div class="sg-card"><div class="sg-label">AM Sign-In</div><div class="sg-value" id="sum_am_in">—</div></div>
          <div class="sg-card"><div class="sg-label">AM Sign-Out</div><div class="sg-value" id="sum_am_out">—</div></div>
          <div class="sg-card"><div class="sg-label">PM Sign-In</div><div class="sg-value" id="sum_pm_in">—</div></div>
          <div class="sg-card"><div class="sg-label">PM Sign-Out</div><div class="sg-value" id="sum_pm_out">—</div></div>
        </div>
        <div style="background:#e8f5e9;border-radius:8px;padding:10px 14px;font-size:12px;color:#2e7d32;font-weight:600;margin-bottom:6px;">
            <i class="fas fa-clock"></i> Total duty hours: <span id="sum_total_hrs" style="font-size:14px;">—</span>
        </div>
        <div style="background:#fff8e1;border-radius:8px;padding:8px 14px;font-size:11px;color:#e65100;margin-bottom:10px;">
            <i class="fas fa-info-circle"></i> Duty time is measured from <strong>Sign-In Opens → Sign-Out Opens</strong>. Sign-Out Closes is a grace window and is not counted.
        </div>
        <p style="font-size:12px;color:#888;margin:0;line-height:1.5">
            <i class="fas fa-envelope"></i> All registered students <strong>and system admins</strong> will be notified by email.
        </p>
      </div>
      <div class="wiz-footer">
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

<!-- ══ EMAIL SENDING LOADING OVERLAY ══ -->
<div id="emailSendingOverlay">
    <div id="emailSendingBox">
        <div class="esb-header">
            <div class="esb-spinner-wrap">
                <div class="esb-spinner"></div>
            </div>
            <h3>Saving &amp; Notifying…</h3>
            <p>Please wait while we save your schedule and send email notifications to all students and admins.</p>
        </div>
        <div class="esb-body">
            <div class="esb-steps">
                <div class="esb-step" id="esb-step-1">
                    <div class="esb-step-icon" id="esb-icon-1"><i class="fas fa-save"></i></div>
                    <span>Saving attendance settings…</span>
                </div>
                <div class="esb-step" id="esb-step-2">
                    <div class="esb-step-icon" id="esb-icon-2"><i class="fas fa-user-graduate"></i></div>
                    <span>Notifying students by email…</span>
                </div>
                <div class="esb-step" id="esb-step-3">
                    <div class="esb-step-icon" id="esb-icon-3"><i class="fas fa-user-shield"></i></div>
                    <span>Notifying administrators…</span>
                </div>
            </div>
            <p class="esb-note">This may take a few moments depending on the number of recipients. Do not close this page.</p>
        </div>
    </div>
</div>

<!-- ══ LATE REQUEST INBOX MODAL ══ -->
<div id="lateInboxOverlay">
    <div id="lateInboxBox">
        <div class="li-header">
            <h3><i class="fas fa-inbox"></i> Late Attendance Requests</h3>
            <button class="li-close-btn" onclick="closeLateInbox()"><i class="fas fa-times"></i> Close</button>
        </div>
        <div class="li-tabs">
            <button class="li-tab active" onclick="switchTab('pending')">Pending</button>
            <button class="li-tab" onclick="switchTab('approved')">Approved</button>
            <button class="li-tab" onclick="switchTab('rejected')">Rejected</button>
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

<!-- ══ CONTINUE ANYWAY CONFIRMATION OVERLAY ══ -->
<div id="continueAnywayOverlay">
    <div id="continueAnywayBox">
        <div class="ca-header">
            <span class="ca-header-icon"><i class="fas fa-exclamation-triangle" style="font-size:26px;"></i></span>
            <div>
                <div class="ca-header-text-title">Save Non-Standard Schedule?</div>
                <div class="ca-header-text-sub">This schedule does not total 8 hours — are you sure?</div>
            </div>
        </div>
        <div class="ca-body">
            <div class="ca-summary-label">Schedule Summary</div>
            <div class="ca-summary-box">
                <div class="ca-summary-grid">
                    <div class="ca-sg-card"><div class="ca-sg-lbl">AM Sign-In</div><div class="ca-sg-val" id="ca_am_in">—</div></div>
                    <div class="ca-sg-card"><div class="ca-sg-lbl">AM Sign-Out</div><div class="ca-sg-val" id="ca_am_out">—</div></div>
                    <div class="ca-sg-card"><div class="ca-sg-lbl">PM Sign-In</div><div class="ca-sg-val" id="ca_pm_in">—</div></div>
                    <div class="ca-sg-card"><div class="ca-sg-lbl">PM Sign-Out</div><div class="ca-sg-val" id="ca_pm_out">—</div></div>
                </div>
                <div class="ca-total-row"><span class="ca-total-lbl">Total duty time</span><span class="ca-total-val" id="ca_total_hrs">—</span></div>
            </div>
            <div class="ca-warning-box"><i class="fas fa-exclamation-circle"></i> Saving will apply this schedule to today and all future OJT weekdays. Past days will not be changed.</div>
        </div>
        <div class="ca-footer">
            <button class="ca-btn-cancel" onclick="closeContinueAnyway()">No, Adjust Times</button>
            <button class="ca-btn-confirm" id="caConfirmBtn" onclick="confirmContinueAnyway()">Yes, Save Anyway</button>
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

/* ── ATT LOG MODAL ── */
function openAttLogModal() {
    const today = new Date();
    const todayStr = today.toISOString().split('T')[0];
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
function showToast(message, type="info") {
    let t = document.createElement("div"); t.className="toast " + type; t.innerText=message;
    document.getElementById("toastContainer").appendChild(t);
    setTimeout(()=>t.classList.add("show"),100);
    setTimeout(()=>{ t.classList.remove("show"); setTimeout(()=>t.remove(),300); },4500);
}

/* ── LIVE UPDATES (UPDATED: replaces the old 45-second automatic page reload) ──
   Every few seconds the page asks the server for a tiny fingerprint of its
   data. Only when the fingerprint changes (new attendance, late request,
   schedule, student…) it loads the fresh page in the background and swaps
   the Attendance Overview, Monthly Attendance Summary, Quick Actions and
   Today's Stats panels in place. No reload, scroll position and open
   modals are kept, and the user never has to reload manually. */
let _liveSignature  = '<?= attm_live_signature($conn, $company_id) ?>';
let _liveBusy       = false;
const LIVE_POLL_MS  = 10000;

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
    if (name === 'chart' && window.Chart && typeof Chart.getChart === 'function') {
        const oldCanvas = document.getElementById('attendanceBarChart');
        const oldChart  = oldCanvas ? Chart.getChart(oldCanvas) : null;
        if (oldChart) oldChart.destroy();
    }
    const fresh = document.importNode(nxt, true);
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
    // Wizard scope line ("Applies to today + N remaining OJT weekday(s)…")
    const newScope = newDoc.getElementById('sumScope'), curScope = document.getElementById('sumScope');
    if (newScope && curScope) curScope.innerHTML = newScope.innerHTML;

    // First-attendance maps used by the Attendance Log popup (NOT STARTED rows)
    const byId   = html.match(/const _firstAttendanceById\s*=\s*(\{[\s\S]*?\});/);
    const byName = html.match(/const _firstAttendanceByName\s*=\s*(\{[\s\S]*?\});/);
    try {
        if (byId)   { const o = JSON.parse(byId[1]);   Object.keys(_firstAttendanceById).forEach(k => delete _firstAttendanceById[k]);   Object.assign(_firstAttendanceById, o); }
        if (byName) { const o = JSON.parse(byName[1]); Object.keys(_firstAttendanceByName).forEach(k => delete _firstAttendanceByName[k]); Object.assign(_firstAttendanceByName, o); }
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
    return fetch(window.location.pathname + '?action=live_signature', { headers: { 'X-Requested-With': 'XMLHttpRequest' }, cache: 'no-store' })
        .then(r => r.json())
        .then(data => {
            if (!data || !data.success) return false;
            if (!force && data.signature === _liveSignature) return false;
            return fetch(window.location.href, { cache: 'no-store', credentials: 'same-origin' })
                .then(r => r.text())
                .then(html => {
                    _liveSignature = data.signature;
                    const changed = liveApplyPage(html);
                    if (changed && !force) showToast('Attendance data updated.', 'info');
                    return changed;
                });
        })
        .catch(() => false)
        .finally(() => { _liveBusy = false; });
}
setInterval(() => { if (!document.hidden) liveRefreshNow(false); }, LIVE_POLL_MS);
document.addEventListener('visibilitychange', () => { if (!document.hidden) liveRefreshNow(false); });
window.addEventListener('focus', () => liveRefreshNow(false));

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
function copyEmailDebug() { navigator.clipboard.writeText(document.getElementById('emailDebugBody').innerText).then(()=>showToast('Copied','info')); }
function escHtml(str) { return String(str).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;'); }

/* ── EMAIL SENDING LOADING OVERLAY ── */
let _esbStepTimer = null;
function showEmailSendingOverlay() {
    [1,2,3].forEach(n => {
        document.getElementById('esb-step-'+n).classList.remove('active','done');
        document.getElementById('esb-icon-'+n).innerHTML = ['<i class="fas fa-save"></i>','<i class="fas fa-user-graduate"></i>','<i class="fas fa-user-shield"></i>'][n-1];
    });
    document.getElementById('esb-step-1').classList.add('active');
    document.getElementById('emailSendingOverlay').classList.add('open');
    _esbStepTimer = setTimeout(() => {
        document.getElementById('esb-step-1').classList.remove('active');
        document.getElementById('esb-step-1').classList.add('done');
        document.getElementById('esb-icon-1').innerHTML = '<i class="fas fa-check"></i>';
        document.getElementById('esb-step-2').classList.add('active');
    }, 800);
}
function advanceEmailSendingOverlay() {
    clearTimeout(_esbStepTimer);
    document.getElementById('esb-step-1').classList.remove('active');
    document.getElementById('esb-step-1').classList.add('done');
    document.getElementById('esb-icon-1').innerHTML = '<i class="fas fa-check"></i>';
    document.getElementById('esb-step-2').classList.remove('active');
    document.getElementById('esb-step-2').classList.add('done');
    document.getElementById('esb-icon-2').innerHTML = '<i class="fas fa-check"></i>';
    document.getElementById('esb-step-3').classList.remove('active');
    document.getElementById('esb-step-3').classList.add('done');
    document.getElementById('esb-icon-3').innerHTML = '<i class="fas fa-check"></i>';
}
function hideEmailSendingOverlay() {
    clearTimeout(_esbStepTimer);
    document.getElementById('emailSendingOverlay').classList.remove('open');
}

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
    if (total > MAX_DUTY_MINS) {
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
    if (total > MAX_DUTY_MINS) {
        const h=Math.floor(total/60), m=total%60;
        showToast('Schedule is '+h+'h'+(m?' '+m+'m':'')+' — the maximum allowed is 8 hours per day.','warning');
        return true;
    }
    return false;
}
function closeHoursPopup() { document.getElementById('hoursPopupOverlay').classList.remove('open'); }
function calcDutyMins(startId, outStartId) {
    const s=document.getElementById(startId).value, e=document.getElementById(outStartId).value;
    if (!s || !e) return 0;
    const [sh,sm]=s.split(':').map(Number), [eh,em]=e.split(':').map(Number);
    return Math.max(0,(eh*60+em)-(sh*60+sm));
}

/* ── CONTINUE ANYWAY ── */
function continueAnywayConfirm() {
    if (blockIfOverMax()) return; // UPDATED (8-hour maximum)
    const g = id => document.getElementById(id).value;
    const skipAm = document.getElementById('skipAmCheckbox').checked;
    const skipPm = document.getElementById('skipPmCheckbox').checked;
    document.getElementById('ca_am_in').textContent  = skipAm ? 'SKIPPED' : (fmt12js(g('w_am_ti_s'))+' – '+fmt12js(g('w_am_ti_e')));
    document.getElementById('ca_am_out').textContent = skipAm ? 'SKIPPED' : (fmt12js(g('w_am_to_s'))+' – '+fmt12js(g('w_am_to_e')));
    document.getElementById('ca_pm_in').textContent  = skipPm ? 'SKIPPED' : (g('w_pm_ti_s') ? fmt12js(g('w_pm_ti_s'))+' – '+fmt12js(g('w_pm_ti_e')) : '—');
    document.getElementById('ca_pm_out').textContent = skipPm ? 'SKIPPED' : (g('w_pm_to_s') ? fmt12js(g('w_pm_to_s'))+' – '+fmt12js(g('w_pm_to_e')) : '—');
    const amMins = skipAm ? 0 : calcDutyMins('w_am_ti_s','w_am_to_s');
    const pmMins = skipPm ? 0 : ((g('w_pm_ti_s')&&g('w_pm_to_s'))?calcDutyMins('w_pm_ti_s','w_pm_to_s'):0);
    const total=amMins+pmMins, h=Math.floor(total/60), m=total%60;
    const totalEl=document.getElementById('ca_total_hrs');
    totalEl.textContent=h+'h'+(m?' '+m+'m':'');
    totalEl.style.color=total===480?'#2e7d32':'#c62828';
    closeHoursPopup();
    document.getElementById('continueAnywayOverlay').classList.add('open');
}
function closeContinueAnyway() { document.getElementById('continueAnywayOverlay').classList.remove('open'); }
function confirmContinueAnyway() {
    if (blockIfOverMax()) { closeContinueAnyway(); return; } // UPDATED (8-hour maximum)
    const btn=document.getElementById('caConfirmBtn');
    btn.classList.add('loading'); btn.textContent='Saving…';
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
    closeContinueAnyway();
    btn.classList.remove('loading'); btn.textContent='Yes, Save Anyway';
    wizSave();
}
document.getElementById('continueAnywayOverlay').addEventListener('click',function(e){if(e.target===this)closeContinueAnyway();});

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
    }
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
function closeWizard(){document.getElementById('wizardOverlay').classList.remove('open');}
function showWizStep(n){
    document.querySelectorAll('.wiz-step').forEach(s=>s.style.display='none');
    document.getElementById('wizStep'+n).style.display='block';
    document.getElementById('wizardProgressBar').style.width=(n*33.33)+'%';
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
        showToast('You cannot skip both AM and PM duty times.','warning');
        return;
    }

    if(step===1){
        if (!skipAm) {
            if(!g('w_am_ti_s')||!g('w_am_ti_e')||!g('w_am_to_s')||!g('w_am_to_e')){
                showToast('Please fill all AM time fields.','warning'); return;
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
        showWizStep(2);

    } else if(step===2){
        if (!skipPm) {
            const pmAny=g('w_pm_ti_s')||g('w_pm_ti_e')||g('w_pm_to_s')||g('w_pm_to_e');
            const pmAll=g('w_pm_ti_s')&&g('w_pm_ti_e')&&g('w_pm_to_s')&&g('w_pm_to_e');
            if(pmAny&&!pmAll){showToast('Fill all PM fields or leave all blank to skip.','warning');return;}
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
        showToast('You cannot skip both AM and PM duty times.','warning');
        return;
    }

    closeWizard();
    showEmailSendingOverlay();

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

    fetch(window.location.pathname,{method:'POST',headers:{'X-Requested-With':'XMLHttpRequest'},body:fd})
    .then(r=>r.text())
    .then(rawText=>{
        btn.classList.remove('loading'); btn.innerHTML='<i class="fas fa-save"></i> Save & Notify';
        advanceEmailSendingOverlay();
        setTimeout(() => {
            hideEmailSendingOverlay();
            let data=null;
            try{data=JSON.parse(rawText);}catch(e){showToast('Unexpected server response.','error');console.error(e,rawText);return;}
            if(data.success){
                showToast(''+data.message,'success');
                if(data.total_students>0){
                    if(data.notified===data.total_students) setTimeout(()=>showToast(`${data.notified} student(s) notified.`,'info'),700);
                    else if(data.notified>0) setTimeout(()=>showToast(`${data.notified}/${data.total_students} notified — see details.`,'warning'),700);
                    else setTimeout(()=>showToast('Email delivery failed — see details.','error'),700);
                }
                if(data.admin_notified&&data.admin_notified>0) setTimeout(()=>showToast(`${data.admin_notified} admin(s) also notified.`,'info'),1200);
                if(data.email_errors&&data.email_errors.length>0) setTimeout(()=>renderEmailDebug(data.email_errors),1400);
                if(data.db_errors&&data.db_errors.length>0){setTimeout(()=>showToast(`${data.db_errors.length} DB error(s).`,'warning'),1800);console.warn('DB errors:',data.db_errors);}
                setTimeout(()=>liveRefreshNow(true),1500); // UPDATED: show the new schedule in place (no page reload)
            }else{showToast(''+(data.message||'Save failed.'),'error');}
        }, 900);
    })
    .catch(err=>{
        btn.classList.remove('loading');btn.innerHTML='<i class="fas fa-save"></i> Save & Notify';
        hideEmailSendingOverlay();
        showToast('Network error.','error');
    });
}
document.getElementById('wizardOverlay').addEventListener('click',function(e){if(e.target===this)closeWizard();});
document.getElementById('hoursPopupOverlay').addEventListener('click',function(e){if(e.target===this)closeHoursPopup();});

/* ── BUILT-IN POPUP NOTIFICATIONS ── */
function requestNotifPermission() { /* no-op */ }
function sendSystemNotification(title, body, onClick) {
    const container=document.getElementById('builtInNotifContainer');
    if(!container){showToast(title+' — '+body,'info');return;}
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
function showCustomConfirm({title,message,studentName,type,onConfirm}){
    document.getElementById('ccTitle').textContent=title;
    document.getElementById('ccMessage').textContent=message;
    const iconWrap=document.getElementById('ccIconWrap'),confirmBtn=document.getElementById('ccConfirmBtn'),studentBadge=document.getElementById('ccStudentBadge'),studentNameEl=document.getElementById('ccStudentName');
    if(studentName){studentNameEl.textContent=studentName;studentBadge.style.display='inline-flex';}else{studentBadge.style.display='none';}
    if(type==='approve'){iconWrap.innerHTML='<i class="fas fa-check" style="color:#16a34a;font-size:24px;"></i>';iconWrap.className='cc-icon-wrap approve';confirmBtn.className='cc-btn cc-btn-confirm-approve';confirmBtn.innerHTML='<i class="fas fa-check"></i> Allow Request';}
    else{iconWrap.innerHTML='<i class="fas fa-times" style="color:#dc2626;font-size:24px;"></i>';iconWrap.className='cc-icon-wrap reject';confirmBtn.className='cc-btn cc-btn-confirm-reject';confirmBtn.innerHTML='<i class="fas fa-times"></i> Reject Request';}
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
const KIND_LABELS_CO={late:'Late Request',overtime:'Overtime'};
function reqKindNote(req){
    const isOut=(req.type==='am_time_out'||req.type==='pm_time_out');
    if(!isOut) return '';
    const P=req.type==='am_time_out'?'AM':'PM';
    return req.request_type==='overtime'
        ? `<div class="req-kind-note"><strong>Overtime:</strong> ${P} duty is counted from the student's ${P} Sign In up to the time this request was submitted.</div>`
        : `<div class="req-kind-note"><strong>Late Request:</strong> only the ${P} duty is counted &mdash; ${P} Sign In to the scheduled ${P} Sign Out.</div>`;
}

function openLateInbox(){document.getElementById('lateInboxOverlay').classList.add('open');if(!liLoaded)fetchLateRequests();}
function closeLateInbox(){document.getElementById('lateInboxOverlay').classList.remove('open');}
document.getElementById('lateInboxOverlay').addEventListener('click',function(e){if(e.target===this)closeLateInbox();});

function switchTab(tab){
    liTab=tab;
    document.querySelectorAll('.li-tab').forEach((t,i)=>{t.classList.toggle('active',['pending','approved','rejected'][i]===tab);});
    renderLiBody();
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
                const kindWord=(req&&req.request_type==='overtime')?'an overtime request':'a late request';
                sendSystemNotification(req&&req.request_type==='overtime'?'New Overtime Request':'New Late Request',`${studentName} submitted ${kindWord} for ${typeLabel}.`,()=>{window.focus();openLateInbox();switchTab('pending');});
                showToast(req&&req.request_type==='overtime'?`New overtime request from ${studentName}`:`New late request from ${studentName}`,'info');
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

function renderLiBody(){
    const filtered=liRequests.filter(r=>r.status===liTab);
    const body=document.getElementById('li-body');
    if(!filtered.length){body.innerHTML=`<div class="li-empty">No ${liTab} requests.</div>`;return;}
    let html='';
    filtered.forEach(req=>{
        const kind=(req.request_type==='overtime')?'overtime':'late';
        const typeBadge=`<span class="req-type-badge ${req.type}">${TYPE_LABELS_CO[req.type]||req.type}</span><span class="req-kind-badge ${kind}">${KIND_LABELS_CO[kind]}</span>`;
        const dateLabel=new Date(req.date+'T00:00:00').toLocaleDateString('en-US',{weekday:'long',year:'numeric',month:'long',day:'numeric'});
        const submitted=new Date(req.created_at).toLocaleString('en-US',{month:'short',day:'numeric',hour:'2-digit',minute:'2-digit'});
        let statusSection='';
        if(req.status==='pending'){statusSection=`<div class="req-actions"><button class="req-btn-allow" onclick="approveRequest(${req.id}, this)">Allow</button><button class="req-btn-reject" onclick="rejectRequest(${req.id}, this)">Reject</button></div>`;}
        else if(req.status==='approved'){statusSection=`<span class="req-status-badge approved">Approved</span>`;}
        else{statusSection=`<span class="req-status-badge rejected">Rejected</span>`;}
        let photoHtml='';
        if(req.has_photo){photoHtml=`<div class="req-photo-section"><div class="rps-label">Submitted Photo</div><div class="req-photo-frame" id="photo-frame-${req.id}" onclick="openPhotoLightbox(${req.id},'${escH(req.first_name)} ${escH(req.last_name)}')" data-loaded="false"><div class="rp-loading"><div class="rp-spinner"></div><span>Loading photo…</span></div><div class="rp-expand-hint">Click to enlarge</div></div></div>`;}
        else{photoHtml=`<div class="req-photo-section"><div class="rps-label">Submitted Photo</div><div class="req-photo-no-photo">No photo submitted</div></div>`;}
        let dutyHtml='';
        if(req.status==='approved'&&(req.am_time_in||req.pm_time_in)){
            const fmt=v=>{if(!v||v==='missed')return'—';const t=v.includes(' ')?v.split(' ')[1]:v;const [h,m]=t.split(':').map(Number);const ampm=h>=12?'PM':'AM';const h12=h%12||12;return`${h12}:${String(m).padStart(2,'0')} ${ampm}`;};
            dutyHtml=`<div class="req-duty-info has-late"><div class="req-duty-stat"><strong>AM In</strong>${fmt(req.am_time_in)}</div><div class="req-duty-stat"><strong>AM Out</strong>${fmt(req.am_time_out)}</div><div class="req-duty-stat"><strong>PM In</strong>${fmt(req.pm_time_in)}</div><div class="req-duty-stat"><strong>PM Out</strong>${fmt(req.pm_time_out)}</div></div>`;
        }
        html+=`<div class="req-card" id="req-card-${req.id}"><div class="req-card-top"><div class="req-student-info"><div class="req-student-name">${escH(req.first_name)}${req.middle_name?' '+escH(req.middle_name):''} ${escH(req.last_name)}</div><div class="req-meta">${typeBadge}<span>${escH(dateLabel)}</span><span>Submitted ${escH(submitted)}</span></div>${reqKindNote(req)}</div></div>${photoHtml}<div class="req-reason-box"><div class="req-reason-label">Reason</div>${escH(req.reason)}</div>${dutyHtml}${statusSection}</div>`;
    });
    body.innerHTML=html;
    filtered.forEach(req=>{if(req.has_photo)loadPhotoIntoFrame(req.id);});
}

function loadPhotoIntoFrame(reqId){
    const frame=document.getElementById('photo-frame-'+reqId);
    if(!frame||frame.dataset.loaded==='true')return;
    frame.dataset.loaded='true';
    fetch(window.location.pathname+'?action=get_late_request_photo&req_id='+reqId,{headers:{'X-Requested-With':'XMLHttpRequest'},cache:'no-store'})
    .then(r=>r.json())
    .then(data=>{
        if(data.success&&data.photo_b64){const src='data:image/jpeg;base64,'+data.photo_b64;frame.innerHTML=`<img src="${src}" alt="Student photo"><div class="rp-expand-hint">Click to enlarge</div>`;frame.dataset.src=src;}
        else{frame.innerHTML=`<div class="rp-loading" style="color:#c62828;">Photo unavailable</div>`;}
    })
    .catch(()=>{frame.innerHTML=`<div class="rp-loading" style="color:#c62828;">Failed to load</div>`;});
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
    const isOT=!!(req&&req.request_type==='overtime');
    showCustomConfirm({title:isOT?'Allow Overtime Request?':'Allow Late Request?',message:isOT?"Overtime will be counted from the student's Sign In up to the time this request was submitted.":"The student's time and photo will be recorded.",studentName,type:'approve',onConfirm:()=>{
        btn.classList.add('loading'); btn.textContent='Processing…';
        const fd=new FormData(); fd.append('action','approve_late_request'); fd.append('req_id',reqId);
        fetch(window.location.pathname,{method:'POST',headers:{'X-Requested-With':'XMLHttpRequest'},body:fd})
        .then(r=>r.json())
        .then(data=>{
            if(data.success){
                showToast(''+data.message,'success');
                const idx=liRequests.findIndex(r=>r.id===reqId);
                if(idx!==-1)liRequests[idx].status='approved';
                const pending=liRequests.filter(r=>r.status==='pending').length;
                updateBadges(pending);
                if(data.pending_count!==undefined)updateBadges(data.pending_count);
                renderLiBody();
            }else{btn.classList.remove('loading');btn.textContent='Allow';showToast(''+(data.message||'Failed.'),'error');}
        })
        .catch(()=>{btn.classList.remove('loading');btn.textContent='Allow';showToast('Network error.','error');});
    }});
}

function rejectRequest(reqId,btn){
    const req=liRequests.find(r=>r.id===reqId);
    const studentName=req?(req.first_name+' '+req.last_name):null;
    showCustomConfirm({title:'Reject This Request?',message:"The student's "+(req&&req.request_type==='overtime'?'overtime':'late')+" request will be rejected. This cannot be undone.",studentName,type:'reject',onConfirm:()=>{
        const fd=new FormData(); fd.append('action','reject_late_request'); fd.append('req_id',reqId);
        fetch(window.location.pathname,{method:'POST',headers:{'X-Requested-With':'XMLHttpRequest'},body:fd})
        .then(r=>r.json())
        .then(data=>{
            if(data.success){
                showToast('Request rejected.','warning');
                const idx=liRequests.findIndex(r=>r.id===reqId);
                if(idx!==-1)liRequests[idx].status='rejected';
                const pending=liRequests.filter(r=>r.status==='pending').length;
                updateBadges(pending);
                if(data.pending_count!==undefined)updateBadges(data.pending_count);
                renderLiBody();
            }else{showToast(''+(data.message||'Failed.'),'error');}
        })
        .catch(()=>showToast('Network error.','error'));
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
        if(!data.success){showToast('Failed to load attendance.','error');return;}
        renderAttLogTable(data);
    })
    .catch(()=>{
        document.getElementById('attLogSpinner').style.display='none';
        document.getElementById('attLogTableBox').style.opacity='1';
        showToast('Network error loading attendance.','error');
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
        const rowNum=start+i+1,cls=(r.status==='NOT STARTED'?'pending':(statusClass[r.status]||''));
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

// Poll every 60 seconds for new late requests
setInterval(()=>{ fetchLateRequests(true); },60000);
</script>
</body>
</html>