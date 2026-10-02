<?php
session_start();
ini_set('post_max_size', '20M');
ini_set('upload_max_filesize', '20M');
include "db.php";

// ================= SESSION CHECK =================
if (!isset($_SESSION['user_id']) || $_SESSION['role'] != "student") {
    if (isset($_SERVER['HTTP_X_REQUESTED_WITH'])) {
        header('Content-Type: application/json');
        echo json_encode(['success' => false, 'message' => 'Access denied.']);
        exit;
    }
    die("Access denied.");
}

$user_id = $_SESSION['user_id'];
date_default_timezone_set("Asia/Manila");
$date = date("Y-m-d");
$current_time = date("H:i:s");

// ================= WEEKEND CHECK =================
$day_of_week  = (int)date('w');
$is_weekend   = ($day_of_week === 0 || $day_of_week === 6);
$weekend_name = ($day_of_week === 0) ? 'Sunday' : 'Saturday';

// ================= TOKEN SETUP =================
if (!isset($_SESSION['attendance_token'])) {
    $_SESSION['attendance_token'] = bin2hex(random_bytes(16));
}

// ================= GET COMPANY =================
$stmt = $conn->prepare("SELECT company_id FROM ojt_assignments WHERE student_id=? LIMIT 1");
$stmt->bind_param("i", $user_id);
$stmt->execute();
$res = $stmt->get_result()->fetch_assoc();

if (!$res) {
    if (isset($_SERVER['HTTP_X_REQUESTED_WITH'])) {
        header('Content-Type: application/json');
        echo json_encode(['success' => false, 'message' => 'No company assigned.']);
        exit;
    }
    die("No company assigned.");
}

$company_id = $res['company_id'];

// ================= HELPERS =================
function fmt12php($t) {
    if (!$t) return null;
    if (strpos($t, ' ') !== false) $t = explode(' ', $t)[1];
    $parts = explode(':', $t);
    $h = (int)$parts[0]; $m = (int)$parts[1];
    $ampm = $h >= 12 ? 'PM' : 'AM';
    $h12  = $h % 12 ?: 12;
    return sprintf('%d:%02d %s', $h12, $m, $ampm);
}

function timeToSeconds($t) {
    if (!$t) return -1;
    $parts = explode(':', $t);
    return (int)$parts[0] * 3600 + (int)$parts[1] * 60 + (isset($parts[2]) ? (int)$parts[2] : 0);
}

// ================= GET ATTENDANCE SETTINGS =================
function getSettings($conn, $company_id, $date) {
    $stmt = $conn->prepare("SELECT * FROM attendance_settings WHERE company_id=? AND date=? LIMIT 1");
    $stmt->bind_param("is", $company_id, $date);
    $stmt->execute();
    $s = $stmt->get_result()->fetch_assoc();
    if (!$s) {
        $stmt = $conn->prepare("SELECT * FROM attendance_settings WHERE company_id=? AND is_auto=1 AND date<=? ORDER BY date DESC LIMIT 1");
        $stmt->bind_param("is", $company_id, $date);
        $stmt->execute();
        $s = $stmt->get_result()->fetch_assoc();
    }
    return $s;
}

// ================= SKIP DUTY HELPERS =================
// Determine if AM or PM duty is skipped (all 4 fields for that period are null/empty)
function isAmSkipped($setting) {
    if (!$setting) return false;
    return (
        empty($setting['am_time_in_start'])  &&
        empty($setting['am_time_in_end'])    &&
        empty($setting['am_time_out_start']) &&
        empty($setting['am_time_out_end'])
    );
}

function isPmSkipped($setting) {
    if (!$setting) return false;
    return (
        empty($setting['pm_time_in_start'])  &&
        empty($setting['pm_time_in_end'])    &&
        empty($setting['pm_time_out_start']) &&
        empty($setting['pm_time_out_end'])
    );
}

// ================= LATE REQUEST WINDOW HELPER =================
function getLateRequestWindowStatus($type, $setting, $current_time) {
    if (!$setting) return 'permanently_missed';

    // If this type's duty is skipped, never open a late request window
    $amSkipped = isAmSkipped($setting);
    $pmSkipped = isPmSkipped($setting);
    if (in_array($type, ['am_time_in','am_time_out']) && $amSkipped) return 'permanently_missed';
    if (in_array($type, ['pm_time_in','pm_time_out']) && $pmSkipped) return 'permanently_missed';

    $endKeys = [
        'am_time_in'  => 'am_time_in_end',
        'am_time_out' => 'am_time_out_end',
        'pm_time_in'  => 'pm_time_in_end',
        'pm_time_out' => 'pm_time_out_end',
    ];
    $nextOpenKeys = [
        'am_time_in'  => 'am_time_out_start',
        'am_time_out' => 'pm_time_in_start',
        'pm_time_in'  => 'pm_time_out_start',
        'pm_time_out' => null,
    ];

    $endSec      = timeToSeconds($setting[$endKeys[$type]] ?? '');
    $nowSec      = timeToSeconds($current_time);
    $nextKey     = $nextOpenKeys[$type];
    $nextOpenSec = $nextKey ? timeToSeconds($setting[$nextKey] ?? '') : -1;

    if ($endSec >= 0 && $nowSec <= $endSec) return 'not_yet';

    if ($type === 'pm_time_out') {
        $pmOutEndSec = timeToSeconds($setting['pm_time_out_end'] ?? '');
        if ($pmOutEndSec >= 0) {
            $lateWindowEndSec = $pmOutEndSec + 3600;
            if ($nowSec <= $lateWindowEndSec) return 'open';
            return 'permanently_missed';
        }
        return 'permanently_missed';
    }

    if ($nextOpenSec >= 0 && $nowSec >= $nextOpenSec) return 'permanently_missed';
    if ($nextKey === null) return 'permanently_missed';
    return 'open';
}

// ================= AUTO-MISSED CHECK & MARKING =================
function autoMarkMissed($conn, $user_id, $company_id, $date, $current_time) {
    if ((int)date('w', strtotime($date)) === 0 || (int)date('w', strtotime($date)) === 6) return [];

    $setting = getSettings($conn, $company_id, $date);
    if (!$setting) return [];

    $amSkipped = isAmSkipped($setting);
    $pmSkipped = isPmSkipped($setting);

    $stmt = $conn->prepare("SELECT * FROM attendance_logs WHERE user_id=? AND date=? AND company_id=?");
    $stmt->bind_param("isi", $user_id, $date, $company_id);
    $stmt->execute();
    $log = $stmt->get_result()->fetch_assoc();

    $now_sec = timeToSeconds($current_time);

    $windows = [
        'am_time_in'  => ['col' => 'am_time_in',  'end' => $setting['am_time_in_end']],
        'am_time_out' => ['col' => 'am_time_out',  'end' => $setting['am_time_out_end']],
        'pm_time_in'  => ['col' => 'pm_time_in',   'end' => $setting['pm_time_in_end']],
        'pm_time_out' => ['col' => 'pm_time_out',  'end' => $setting['pm_time_out_end']],
    ];

    $changes = [];
    foreach ($windows as $type => $info) {
        // Skip auto-marking missed if duty period is skipped
        if (in_array($type, ['am_time_in','am_time_out']) && $amSkipped) continue;
        if (in_array($type, ['pm_time_in','pm_time_out']) && $pmSkipped) continue;

        if (!$info['end']) continue;

        $col            = $info['col'];
        $col_val        = $log[$col] ?? null;
        $already_set    = ($col_val !== null && $col_val !== '');
        $already_missed = ($col_val === 'missed');

        if ($type === 'pm_time_out') {
            $end_sec = timeToSeconds($setting['pm_time_out_end'] ?? '');
            if ($end_sec < 0) continue;
            $late_window_end = $end_sec + 3600;
            if ($now_sec > $late_window_end && !$already_set && !$already_missed) {
                $changes[$col] = 'missed';
            }
            continue;
        }

        $end_sec = timeToSeconds($info['end']);
        if ($end_sec < 0) continue;

        if ($now_sec > $end_sec && !$already_set && !$already_missed) {
            $changes[$col] = 'missed';
        }
    }

    if (empty($changes)) return [];

    if (!$log) {
        $am_ti = $changes['am_time_in']  ?? null;
        $am_to = $changes['am_time_out'] ?? null;
        $pm_ti = $changes['pm_time_in']  ?? null;
        $pm_to = $changes['pm_time_out'] ?? null;
        if ($am_ti || $am_to || $pm_ti || $pm_to) {
            $ins = $conn->prepare(
                "INSERT INTO attendance_logs
                    (user_id, company_id, date, am_time_in, am_time_out, pm_time_in, pm_time_out)
                 VALUES (?,?,?,?,?,?,?)
                 ON DUPLICATE KEY UPDATE
                    am_time_in  = IF(am_time_in  IS NULL OR am_time_in  = '', VALUES(am_time_in),  am_time_in),
                    am_time_out = IF(am_time_out IS NULL OR am_time_out = '', VALUES(am_time_out), am_time_out),
                    pm_time_in  = IF(pm_time_in  IS NULL OR pm_time_in  = '', VALUES(pm_time_in),  pm_time_in),
                    pm_time_out = IF(pm_time_out IS NULL OR pm_time_out = '', VALUES(pm_time_out), pm_time_out)"
            );
            $ins->bind_param("iisssss", $user_id, $company_id, $date, $am_ti, $am_to, $pm_ti, $pm_to);
            $ins->execute();

            $stmt2 = $conn->prepare("SELECT * FROM attendance_logs WHERE user_id=? AND date=? AND company_id=?");
            $stmt2->bind_param("isi", $user_id, $date, $company_id);
            $stmt2->execute();
            $log = $stmt2->get_result()->fetch_assoc();
        }
    } else {
        $setParts = [];
        $params   = [];
        $types    = '';
        foreach ($changes as $col => $val) {
            $currentVal = $log[$col] ?? null;
            if ($currentVal === null || $currentVal === '') {
                $setParts[] = "{$col}=?";
                $params[]   = $val;
                $types     .= 's';
            }
        }
        if (!empty($setParts)) {
            $params[] = $user_id;    $types .= 'i';
            $params[] = $date;       $types .= 's';
            $params[] = $company_id; $types .= 'i';
            $sql = "UPDATE attendance_logs SET " . implode(', ', $setParts)
                 . " WHERE user_id=? AND date=? AND company_id=?";
            $upd = $conn->prepare($sql);
            $upd->bind_param($types, ...$params);
            $upd->execute();
        }
    }

    return array_keys($changes);
}

$newlyMissed = autoMarkMissed($conn, $user_id, $company_id, $date, $current_time);

// ================= AJAX: GET HISTORY LOG =================
if ($_SERVER['REQUEST_METHOD'] === 'GET' && isset($_GET['action']) && $_GET['action'] === 'get_history') {
    header('Content-Type: application/json');
    $res = $conn->prepare("
        SELECT date, am_time_in, am_time_out, pm_time_in, pm_time_out
        FROM attendance_logs
        WHERE user_id=? AND company_id=?
        ORDER BY date DESC
    ");
    $res->bind_param("ii", $user_id, $company_id);
    $res->execute();
    $rows = $res->get_result()->fetch_all(MYSQLI_ASSOC);
    $grouped = [];
    foreach ($rows as $row) {
        $month_key = date("F Y", strtotime($row['date']));
        $day_label = date("F j, l", strtotime($row['date']));
        $is_wknd   = in_array(date('w', strtotime($row['date'])), ['0','6']);
        $fmtOrMissed = function($v) {
            if ($v === 'missed') return 'MISSED';
            return fmt12php($v);
        };
        $grouped[$month_key][] = [
            'day_label'      => $day_label,
            'is_weekend'     => $is_wknd,
            'am_time_in_12'  => $fmtOrMissed($row['am_time_in']),
            'am_time_out_12' => $fmtOrMissed($row['am_time_out']),
            'pm_time_in_12'  => $fmtOrMissed($row['pm_time_in']),
            'pm_time_out_12' => $fmtOrMissed($row['pm_time_out']),
        ];
    }
    echo json_encode(['success' => true, 'history' => $grouped]);
    exit;
}

// ================= AJAX: GET MONTHLY SUMMARY =================
if ($_SERVER['REQUEST_METHOD'] === 'GET' && isset($_GET['action']) && $_GET['action'] === 'get_monthly_summary') {
    header('Content-Type: application/json');
    $req_month = $_GET['month'] ?? date("Y-m");
    if (!preg_match('/^\d{4}-\d{2}$/', $req_month)) $req_month = date("Y-m");
    $start = $req_month . "-01";
    $end   = date("Y-m-t", strtotime($start));
    $today = date("Y-m-d");
    $ojt_stmt = $conn->prepare("SELECT MIN(date) AS ojt_start FROM attendance_settings WHERE company_id=?");
    $ojt_stmt->bind_param("i", $company_id);
    $ojt_stmt->execute();
    $ojt_row   = $ojt_stmt->get_result()->fetch_assoc();
    $ojt_start = $ojt_row['ojt_start'] ?? $today;
    $res = $conn->prepare("
        SELECT date, am_time_in, am_time_out, pm_time_in, pm_time_out
        FROM attendance_logs
        WHERE user_id=? AND company_id=? AND date BETWEEN ? AND ?
        ORDER BY date ASC
    ");
    $res->bind_param("iiss", $user_id, $company_id, $start, $end);
    $res->execute();
    $rows = $res->get_result()->fetch_all(MYSQLI_ASSOC);
    $log_map = [];
    foreach ($rows as $row) {
        $amIn  = $row['am_time_in'];
        $amOut = $row['am_time_out'];
        $pmIn  = $row['pm_time_in'];
        $pmOut = $row['pm_time_out'];
        $isMissed = fn($v) => ($v === 'missed');
        $hasVal   = fn($v) => ($v !== null && $v !== '' && $v !== 'missed');

        $amDone = $hasVal($amIn) && $hasVal($amOut);
        $pmDone = $hasVal($pmIn) && $hasVal($pmOut);
        $anyIn  = $hasVal($amIn) || $hasVal($pmIn);
        $anyMissed = $isMissed($amIn) || $isMissed($amOut) || $isMissed($pmIn) || $isMissed($pmOut);

        if ($amDone && $pmDone)   $status = 'P';
        elseif ($anyIn)           $status = 'I';
        elseif ($anyMissed)       $status = 'A';
        else                      $status = 'A';

        $fmtOrMissed = fn($v) => ($v === 'missed' ? 'MISSED' : fmt12php($v));
        $log_map[$row['date']] = [
            'status'         => $status,
            'am_time_in_12'  => $fmtOrMissed($amIn),
            'am_time_out_12' => $fmtOrMissed($amOut),
            'pm_time_in_12'  => $fmtOrMissed($pmIn),
            'pm_time_out_12' => $fmtOrMissed($pmOut),
        ];
    }
    $days = [];
    $d = strtotime($start);
    while ($d <= strtotime($end)) {
        $day_str = date("Y-m-d", $d);
        $dow     = (int)date('w', $d);
        $is_wknd = ($dow === 0 || $dow === 6);
        $entry   = $log_map[$day_str] ?? null;
        if ($day_str < $ojt_start)   $status = 'before_start';
        elseif ($is_wknd)            $status = 'dayoff';
        elseif ($day_str > $today)   $status = 'future';
        elseif (!$entry)             $status = 'A';
        else                         $status = $entry['status'];
        $days[] = [
            'date'           => $day_str,
            'day_num'        => (int)date("j", $d),
            'day_name'       => date("D", $d),
            'is_weekend'     => $is_wknd,
            'status'         => $status,
            'am_time_in_12'  => $entry['am_time_in_12']  ?? null,
            'am_time_out_12' => $entry['am_time_out_12'] ?? null,
            'pm_time_in_12'  => $entry['pm_time_in_12']  ?? null,
            'pm_time_out_12' => $entry['pm_time_out_12'] ?? null,
        ];
        $d = strtotime("+1 day", $d);
    }
    $present    = count(array_filter($days, fn($d) => $d['status'] === 'P'));
    $incomplete = count(array_filter($days, fn($d) => $d['status'] === 'I'));
    $absent     = count(array_filter($days, fn($d) => $d['status'] === 'A'));
    $dayoff     = count(array_filter($days, fn($d) => $d['status'] === 'dayoff'));
    echo json_encode([
        'success'     => true,
        'month'       => $req_month,
        'month_label' => date("F Y", strtotime($start)),
        'ojt_start'   => $ojt_start,
        'days'        => $days,
        'stats'       => compact('present','incomplete','absent','dayoff'),
    ]);
    exit;
}

// ================= AJAX: GET ATTENDANCE STATUS =================
if ($_SERVER['REQUEST_METHOD'] === 'GET' && isset($_GET['action']) && $_GET['action'] === 'get_status') {
    autoMarkMissed($conn, $user_id, $company_id, $date, date("H:i:s"));

    header('Content-Type: application/json');
    $res = $conn->prepare("
        SELECT am_time_in, am_time_out, pm_time_in, pm_time_out,
               am_time_in_photo, am_time_out_photo, pm_time_in_photo, pm_time_out_photo
        FROM attendance_logs
        WHERE user_id=? AND date=? AND company_id=?
    ");
    $res->bind_param("isi", $user_id, $date, $company_id);
    $res->execute();
    $row = $res->get_result()->fetch_assoc();
    $payload = ['success' => true, 'attendance' => null];
    $fmtOrMissed = fn($v) => ($v === 'missed' ? 'MISSED' : fmt12php($v));
    if ($row) {
        $payload['attendance'] = [
            'am_time_in'            => $fmtOrMissed($row['am_time_in']),
            'am_time_out'           => $fmtOrMissed($row['am_time_out']),
            'pm_time_in'            => $fmtOrMissed($row['pm_time_in']),
            'pm_time_out'           => $fmtOrMissed($row['pm_time_out']),
            'am_time_in_photo_b64'  => $row['am_time_in_photo']  ? base64_encode($row['am_time_in_photo'])  : null,
            'am_time_out_photo_b64' => $row['am_time_out_photo'] ? base64_encode($row['am_time_out_photo']) : null,
            'pm_time_in_photo_b64'  => $row['pm_time_in_photo']  ? base64_encode($row['pm_time_in_photo'])  : null,
            'pm_time_out_photo_b64' => $row['pm_time_out_photo'] ? base64_encode($row['pm_time_out_photo']) : null,
            'raw_am_in'  => $row['am_time_in'],
            'raw_am_out' => $row['am_time_out'],
            'raw_pm_in'  => $row['pm_time_in'],
            'raw_pm_out' => $row['pm_time_out'],
        ];
    }

    $setting = getSettings($conn, $company_id, $date);
    $payload['settings'] = $setting ? [
        'am_time_in_start'  => $setting['am_time_in_start'],
        'am_time_in_end'    => $setting['am_time_in_end'],
        'am_time_out_start' => $setting['am_time_out_start'],
        'am_time_out_end'   => $setting['am_time_out_end'],
        'pm_time_in_start'  => $setting['pm_time_in_start'],
        'pm_time_in_end'    => $setting['pm_time_in_end'],
        'pm_time_out_start' => $setting['pm_time_out_start'],
        'pm_time_out_end'   => $setting['pm_time_out_end'],
    ] : null;

    // Include skip flags
    $payload['am_skipped'] = $setting ? isAmSkipped($setting) : false;
    $payload['pm_skipped'] = $setting ? isPmSkipped($setting) : false;

    $lrWindows = [];
    if ($setting) {
        foreach (['am_time_in','am_time_out','pm_time_in','pm_time_out'] as $t) {
            $lrWindows[$t] = getLateRequestWindowStatus($t, $setting, date("H:i:s"));
        }
    }
    $payload['late_request_windows'] = $lrWindows;

    $lrStmt = $conn->prepare("SELECT type, status FROM late_requests WHERE student_id=? AND company_id=? AND date=?");
    $lrStmt->bind_param("iis", $user_id, $company_id, $date);
    $lrStmt->execute();
    $lrRows = $lrStmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $payload['late_requests'] = $lrRows;

    $att    = $payload['attendance'];
    $active = 'done';

    // Build order of steps based on what's not skipped
    $amSkippedAjax = $payload['am_skipped'];
    $pmSkippedAjax = $payload['pm_skipped'];
    $activeOrder = [];
    if (!$amSkippedAjax) { $activeOrder[] = 'am_time_in'; $activeOrder[] = 'am_time_out'; }
    if (!$pmSkippedAjax) { $activeOrder[] = 'pm_time_in'; $activeOrder[] = 'pm_time_out'; }

    if ($att) {
        $rawMap = [
            'am_time_in'  => $row['am_time_in']  ?? null,
            'am_time_out' => $row['am_time_out'] ?? null,
            'pm_time_in'  => $row['pm_time_in']  ?? null,
            'pm_time_out' => $row['pm_time_out'] ?? null,
        ];
        foreach ($activeOrder as $type) {
            $val      = $rawMap[$type];
            $isDone   = ($val !== null && $val !== '' && $val !== 'missed');
            $isMissed = ($val === 'missed');
            if ($isDone)   continue;
            if ($isMissed) continue;
            $active = $type;
            break;
        }
    } else {
        $active = !empty($activeOrder) ? $activeOrder[0] : 'done';
    }
    $payload['active_step'] = $active;

    $_SESSION['attendance_token'] = bin2hex(random_bytes(16));
    $payload['token'] = $_SESSION['attendance_token'];
    echo json_encode($payload);
    exit;
}

// ================= AJAX: TRIGGER AUTO-MISS =================
if ($_SERVER['REQUEST_METHOD'] === 'GET' && isset($_GET['action']) && $_GET['action'] === 'trigger_auto_miss') {
    header('Content-Type: application/json');
    if ($is_weekend) {
        echo json_encode(['success' => true, 'newly_missed' => []]);
        exit;
    }
    $newly = autoMarkMissed($conn, $user_id, $company_id, $date, date("H:i:s"));
    $setting = getSettings($conn, $company_id, $date);
    $lrWindows = [];
    if ($setting) {
        foreach (['am_time_in','am_time_out','pm_time_in','pm_time_out'] as $t) {
            $lrWindows[$t] = getLateRequestWindowStatus($t, $setting, date("H:i:s"));
        }
    }
    echo json_encode([
        'success'              => true,
        'newly_missed'         => $newly,
        'late_request_windows' => $lrWindows,
    ]);
    exit;
}

// ================= AJAX: DEBUG AUTO-MISS =================
if ($_SERVER['REQUEST_METHOD'] === 'GET' && isset($_GET['action']) && $_GET['action'] === 'debug_auto_miss') {
    header('Content-Type: application/json');

    $debug = [];
    $debug['timestamp']    = date("Y-m-d H:i:s");
    $debug['user_id']      = $user_id;
    $debug['company_id']   = $company_id;
    $debug['date']         = $date;
    $debug['current_time'] = $current_time;
    $debug['is_weekend']   = $is_weekend;
    $debug['day_of_week']  = $day_of_week;

    $s = $conn->prepare("SELECT * FROM attendance_logs WHERE user_id=? AND date=? AND company_id=?");
    $s->bind_param("isi", $user_id, $date, $company_id);
    $s->execute();
    $debug['attendance_log_before'] = $s->get_result()->fetch_assoc();

    $setting = getSettings($conn, $company_id, $date);
    $debug['settings_found'] = !!$setting;
    $debug['am_skipped'] = $setting ? isAmSkipped($setting) : null;
    $debug['pm_skipped'] = $setting ? isPmSkipped($setting) : null;
    if ($setting) {
        $debug['settings'] = [
            'am_time_in_start'  => $setting['am_time_in_start'],
            'am_time_in_end'    => $setting['am_time_in_end'],
            'am_time_out_start' => $setting['am_time_out_start'],
            'am_time_out_end'   => $setting['am_time_out_end'],
            'pm_time_in_start'  => $setting['pm_time_in_start'],
            'pm_time_in_end'    => $setting['pm_time_in_end'],
            'pm_time_out_start' => $setting['pm_time_out_start'],
            'pm_time_out_end'   => $setting['pm_time_out_end'],
        ];

        $now_sec = timeToSeconds($current_time);
        $debug['now_seconds'] = $now_sec;
        $debug['now_hhmm']    = sprintf('%02d:%02d', floor($now_sec/3600), floor(($now_sec%3600)/60));

        foreach (['am_time_in','am_time_out','pm_time_in','pm_time_out'] as $t) {
            $endKey  = $t . '_end';
            $endStr  = $setting[$endKey] ?? null;
            $endSec  = timeToSeconds($endStr);

            $windowEntry = [
                'end_time'         => $endStr,
                'end_seconds'      => $endSec,
                'now_past_end'     => ($endSec >= 0 && $now_sec > $endSec),
                'lr_window_status' => getLateRequestWindowStatus($t, $setting, $current_time),
            ];

            if ($t === 'pm_time_out' && $endSec >= 0) {
                $lateEnd = $endSec + 3600;
                $windowEntry['late_window_end_seconds'] = $lateEnd;
                $windowEntry['late_window_end_hhmm']    = sprintf('%02d:%02d', floor($lateEnd/3600), floor(($lateEnd%3600)/60));
                $windowEntry['now_past_late_window']    = ($now_sec > $lateEnd);
            }

            $debug['windows'][$t] = $windowEntry;
        }
    } else {
        $debug['settings_error'] = 'No settings found for company_id=' . $company_id . ' on date=' . $date;
    }

    $lrs = $conn->prepare("SELECT type, status, created_at FROM late_requests WHERE student_id=? AND company_id=? AND date=?");
    $lrs->bind_param("iis", $user_id, $company_id, $date);
    $lrs->execute();
    $debug['late_requests'] = $lrs->get_result()->fetch_all(MYSQLI_ASSOC);

    $debug['mysqli_error_before_automark'] = $conn->error ?: null;
    $newly = autoMarkMissed($conn, $user_id, $company_id, $date, $current_time);
    $debug['autoMarkMissed_returned']      = $newly;
    $debug['mysqli_error_after_automark']  = $conn->error ?: null;

    $s2 = $conn->prepare("SELECT * FROM attendance_logs WHERE user_id=? AND date=? AND company_id=?");
    $s2->bind_param("isi", $user_id, $date, $company_id);
    $s2->execute();
    $debug['attendance_log_after'] = $s2->get_result()->fetch_assoc();

    $before = $debug['attendance_log_before'];
    $after  = $debug['attendance_log_after'];
    $diff   = [];
    if ($before && $after) {
        foreach (['am_time_in','am_time_out','pm_time_in','pm_time_out'] as $col) {
            $bv = $before[$col] ?? null;
            $av = $after[$col]  ?? null;
            if ($bv !== $av) {
                $diff[$col] = ['before' => $bv, 'after' => $av];
            }
        }
    } elseif (!$before && $after) {
        $diff['_new_row_inserted'] = true;
        foreach (['am_time_in','am_time_out','pm_time_in','pm_time_out'] as $col) {
            $diff[$col] = ['before' => null, 'after' => $after[$col] ?? null];
        }
    } elseif (!$before && !$after) {
        $diff['_note'] = 'No row before or after — either no changes needed or INSERT failed.';
    }
    $debug['diff'] = $diff;

    $diag = [];
    if (!$setting) {
        $diag[] = 'FAIL: No attendance settings found. autoMarkMissed cannot run without settings.';
    }
    if ($before === null && empty($newly)) {
        $diag[] = 'INFO: No attendance log row exists yet and no windows are past their end time, OR all windows already handled.';
    }
    if (!empty($newly) && $after === null) {
        $diag[] = 'CRITICAL: autoMarkMissed returned changes but no row exists after — INSERT likely failed. Check mysqli_error_after_automark.';
    }
    if (!empty($debug['mysqli_error_after_automark'])) {
        $diag[] = 'MYSQL ERROR: ' . $debug['mysqli_error_after_automark'];
    }
    if (empty($newly) && $before) {
        $allHandled = true;
        foreach (['am_time_in','am_time_out','pm_time_in','pm_time_out'] as $col) {
            $v = $before[$col] ?? null;
            if ($v === null || $v === '') { $allHandled = false; break; }
        }
        if ($allHandled) {
            $diag[] = 'INFO: All slots already have values (recorded or missed). Nothing to mark.';
        }
    }
    if (empty($diag)) {
        $diag[] = 'OK: No obvious issues detected.';
    }
    $debug['diagnosis'] = $diag;

    echo json_encode($debug, JSON_PRETTY_PRINT);
    exit;
}

// ================= AJAX: SUBMIT LATE REQUEST =================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'submit_late_request') {
    header('Content-Type: application/json');

    if ($is_weekend) {
        echo json_encode(['success' => false, 'message' => 'No attendance on weekends.']);
        exit;
    }

    $type   = $_POST['type']   ?? '';
    $reason = trim($_POST['reason'] ?? '');
    $image  = $_POST['image']  ?? '';

    $valid_types = ['am_time_in','am_time_out','pm_time_in','pm_time_out'];
    if (!in_array($type, $valid_types) || !$reason) {
        echo json_encode(['success' => false, 'message' => 'Invalid request. Provide type and reason.']);
        exit;
    }

    $setting = getSettings($conn, $company_id, $date);
    if (!$setting) {
        echo json_encode(['success' => false, 'message' => 'No attendance settings configured.']);
        exit;
    }

    // Block late requests for skipped duty periods
    if (in_array($type, ['am_time_in','am_time_out']) && isAmSkipped($setting)) {
        echo json_encode(['success' => false, 'message' => 'AM duty is not required for your company.']);
        exit;
    }
    if (in_array($type, ['pm_time_in','pm_time_out']) && isPmSkipped($setting)) {
        echo json_encode(['success' => false, 'message' => 'PM duty is not required for your company.']);
        exit;
    }

    $windowStatus = getLateRequestWindowStatus($type, $setting, $current_time);
    if ($windowStatus === 'not_yet') {
        $typeLabels = ['am_time_in'=>'AM Sign In','am_time_out'=>'AM Sign Out','pm_time_in'=>'PM Sign In','pm_time_out'=>'PM Sign Out'];
        $endKeys    = ['am_time_in'=>'am_time_in_end','am_time_out'=>'am_time_out_end','pm_time_in'=>'pm_time_in_end','pm_time_out'=>'pm_time_out_end'];
        $closesAt   = fmt12php($setting[$endKeys[$type]] ?? '') ?? 'the close time';
        echo json_encode(['success' => false, 'message' => "The late request for {$typeLabels[$type]} is only available after {$closesAt}."]);
        exit;
    }
    if ($windowStatus === 'permanently_missed') {
        if ($type === 'pm_time_out') {
            echo json_encode(['success'=>false,'message'=>'The 1-hour late request window for PM Sign Out has expired. This entry has been permanently marked as missed.']);
        } else {
            echo json_encode(['success'=>false,'message'=>'The window for submitting a late request has expired. This entry has been permanently marked as missed.']);
        }
        exit;
    }

    $chk = $conn->prepare("SELECT id, status FROM late_requests WHERE student_id=? AND company_id=? AND date=? AND type=?");
    $chk->bind_param("iiss", $user_id, $company_id, $date, $type);
    $chk->execute();
    $existing = $chk->get_result()->fetch_assoc();
    if ($existing) {
        $msg = $existing['status'] === 'approved'
            ? 'This request has already been approved.'
            : 'You already have a pending request for this entry.';
        echo json_encode(['success' => false, 'message' => $msg]);
        exit;
    }

    $logStmt = $conn->prepare("SELECT {$type} AS val FROM attendance_logs WHERE user_id=? AND date=? AND company_id=?");
    $logStmt->bind_param("isi", $user_id, $date, $company_id);
    $logStmt->execute();
    $logRow  = $logStmt->get_result()->fetch_assoc();
    $slotVal = $logRow['val'] ?? null;

    $slotHasValidTime = ($slotVal !== null && $slotVal !== '' && $slotVal !== 'missed');
    if ($slotHasValidTime) {
        echo json_encode(['success' => false, 'message' => 'You have already recorded this entry. No late request needed.']);
        exit;
    }

    $image_data = null;
    if ($image) {
        $image = preg_replace('/^data:image\/[a-z]+;base64,/i', '', $image);
        $image = str_replace(' ', '+', $image);
        $image = preg_replace('/\s+/', '', $image);
        $image_data = base64_decode($image, true);
        if ($image_data === false || strlen($image_data) < 100) $image_data = null;
    }

    $statusPending = 'pending';
    $null = null;
    $ins = $conn->prepare(
        "INSERT INTO late_requests (student_id, company_id, date, type, reason, photo, status, created_at)
        VALUES (?,?,?,?,?,?,?,NOW())"
    );
    $ins->bind_param("iisssbs", $user_id, $company_id, $date, $type, $reason, $null, $statusPending);
    if ($image_data) {
        $ins->send_long_data(5, $image_data);
    }
    $ins->execute();

    echo json_encode(['success' => true, 'message' => 'Late request submitted. Waiting for company approval.']);
    exit;
}

// ================= HANDLE POST SUBMISSION (AJAX) =================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !isset($_POST['action'])) {
    header('Content-Type: application/json');
    if ($is_weekend) {
        echo json_encode(['success' => false, 'message' => "Today is {$weekend_name}. No attendance required."]);
        exit;
    }
    if (!isset($_POST['type'], $_POST['image'], $_POST['token'])) {
        echo json_encode(['success' => false, 'message' => 'Invalid submission.']);
        exit;
    }
    if (!isset($_SESSION['attendance_token']) || $_POST['token'] !== $_SESSION['attendance_token']) {
        echo json_encode(['success' => false, 'message' => 'Invalid or expired token. Please refresh.']);
        exit;
    }
    $type        = $_POST['type'];
    $valid_types = ['am_time_in','am_time_out','pm_time_in','pm_time_out'];
    if (!in_array($type, $valid_types)) {
        echo json_encode(['success' => false, 'message' => 'Invalid attendance type.']);
        exit;
    }

    // Block submission for skipped duty periods
    $submissionSetting = getSettings($conn, $company_id, $date);
    if ($submissionSetting) {
        if (in_array($type, ['am_time_in','am_time_out']) && isAmSkipped($submissionSetting)) {
            echo json_encode(['success' => false, 'message' => 'AM duty is not required for your company today.']);
            exit;
        }
        if (in_array($type, ['pm_time_in','pm_time_out']) && isPmSkipped($submissionSetting)) {
            echo json_encode(['success' => false, 'message' => 'PM duty is not required for your company today.']);
            exit;
        }
    }

    $time             = date("Y-m-d H:i");
    $current_time_chk = date("H:i:s");
    $check = $conn->prepare("SELECT * FROM attendance_logs WHERE user_id=? AND date=? AND company_id=?");
    $check->bind_param("isi", $user_id, $date, $company_id);
    $check->execute();
    $data = $check->get_result()->fetch_assoc();

    $hasLateRequestFor = function($prereqType) use ($conn, $user_id, $company_id, $date) {
        $chk = $conn->prepare(
            "SELECT id FROM late_requests
             WHERE student_id=? AND company_id=? AND date=? AND type=?
               AND status IN ('pending','approved')
             LIMIT 1"
        );
        $chk->bind_param("iiss", $user_id, $company_id, $date, $prereqType);
        $chk->execute();
        return (bool)$chk->get_result()->fetch_assoc();
    };

    switch ($type) {
        case 'am_time_in':
            if ($data && $data['am_time_in'] && $data['am_time_in'] !== 'missed') {
                echo json_encode(['success'=>false,'message'=>'Already signed in for AM duty today.']); exit;
            }
            break;

        case 'am_time_out':
            $amInVal      = $data['am_time_in'] ?? null;
            $amInIsMissed = ($amInVal === 'missed');
            $amInHasTime  = ($amInVal !== null && $amInVal !== '' && !$amInIsMissed);
            if (!$amInHasTime && !$amInIsMissed && !$hasLateRequestFor('am_time_in')) {
                echo json_encode(['success'=>false,'message'=>'You must sign in for AM duty first.']); exit;
            }
            if ($data && $data['am_time_out'] && $data['am_time_out'] !== 'missed') {
                echo json_encode(['success'=>false,'message'=>'Already signed out for AM duty today.']); exit;
            }
            break;

        case 'pm_time_in':
            if ($data && $data['pm_time_in'] && $data['pm_time_in'] !== 'missed') {
                echo json_encode(['success'=>false,'message'=>'Already signed in for PM duty today.']); exit;
            }
            break;

        case 'pm_time_out':
            $pmInVal      = $data['pm_time_in'] ?? null;
            $pmInIsMissed = ($pmInVal === 'missed');
            $pmInHasTime  = ($pmInVal !== null && $pmInVal !== '' && !$pmInIsMissed);
            if (!$pmInHasTime && !$pmInIsMissed && !$hasLateRequestFor('pm_time_in')) {
                echo json_encode(['success'=>false,'message'=>'You must sign in for PM duty first.']); exit;
            }
            if ($data && $data['pm_time_out'] && $data['pm_time_out'] !== 'missed') {
                echo json_encode(['success'=>false,'message'=>'Already signed out for PM duty today.']); exit;
            }
            break;
    }

    $setting = getSettings($conn, $company_id, $date);
    if (!$setting) {
        echo json_encode(['success'=>false,'message'=>'No attendance settings found. Contact your company.']);
        exit;
    }
    $windows = [
        'am_time_in'  => [$setting['am_time_in_start'],  $setting['am_time_in_end'],  'AM Sign In'],
        'am_time_out' => [$setting['am_time_out_start'], $setting['am_time_out_end'], 'AM Sign Out'],
        'pm_time_in'  => [$setting['pm_time_in_start'],  $setting['pm_time_in_end'],  'PM Sign In'],
        'pm_time_out' => [$setting['pm_time_out_start'], $setting['pm_time_out_end'], 'PM Sign Out'],
    ];
    [$win_start, $win_end, $win_label] = $windows[$type];
    if ($win_start && $win_end) {
        if ($current_time_chk < $win_start || $current_time_chk > $win_end) {
            $msg = "{$win_label} is only allowed between " . fmt12php($win_start) . " and " . fmt12php($win_end) . ".";
            echo json_encode(['success'=>false,'message'=>$msg]);
            exit;
        }
    }
    $image      = $_POST['image'];
    $image      = preg_replace('/^data:image\/[a-z]+;base64,/i', '', $image);
    $image      = str_replace(' ', '+', $image);
    $image      = preg_replace('/\s+/', '', $image);
    $image_data = base64_decode($image, true);
    if ($image_data === false || strlen($image_data) < 100) {
        echo json_encode(['success'=>false,'message'=>'Image decoding failed. Please try again.']);
        exit;
    }
    $col_map = [
        'am_time_in'  => ['am_time_in',  'am_time_in_photo'],
        'am_time_out' => ['am_time_out', 'am_time_out_photo'],
        'pm_time_in'  => ['pm_time_in',  'pm_time_in_photo'],
        'pm_time_out' => ['pm_time_out', 'pm_time_out_photo'],
    ];
    [$time_col, $photo_col] = $col_map[$type];
    if (!$data) {
        $stmt = $conn->prepare("INSERT INTO attendance_logs (user_id, company_id, date, {$time_col}, {$photo_col}) VALUES (?, ?, ?, ?, ?) ON DUPLICATE KEY UPDATE {$time_col}=VALUES({$time_col}), {$photo_col}=VALUES({$photo_col})");
        $null = NULL;
        $stmt->bind_param("iissb", $user_id, $company_id, $date, $time, $null);
        $stmt->send_long_data(4, $image_data);
        $stmt->execute();
    } else {
        $stmt = $conn->prepare("UPDATE attendance_logs SET {$time_col}=?, {$photo_col}=? WHERE user_id=? AND date=? AND company_id=?");
        $null = NULL;
        $stmt->bind_param("sbisi", $time, $null, $user_id, $date, $company_id);
        $stmt->send_long_data(1, $image_data);
        $stmt->execute();
    }
    $_SESSION['attendance_token'] = bin2hex(random_bytes(16));
    $labels = ['am_time_in'=>'AM Sign In','am_time_out'=>'AM Sign Out','pm_time_in'=>'PM Sign In','pm_time_out'=>'PM Sign Out'];
    echo json_encode([
        'success' => true,
        'message' => $labels[$type] . " recorded at " . date("g:i A"),
        'token'   => $_SESSION['attendance_token'],
    ]);
    exit;
}

// ================= GET TODAY'S ATTENDANCE FOR INITIAL RENDER =================
$res = $conn->prepare("
    SELECT am_time_in, am_time_out, am_time_in_photo, am_time_out_photo,
           pm_time_in, pm_time_out, pm_time_in_photo, pm_time_out_photo
    FROM attendance_logs WHERE user_id=? AND date=? AND company_id=?
");
$res->bind_param("isi", $user_id, $date, $company_id);
$res->execute();
$attendance = $res->get_result()->fetch_assoc();

// ================= FETCH FULL NAME INCLUDING MIDDLE NAME =================
$name_stmt = $conn->prepare("SELECT first_name, middle_name, last_name FROM users WHERE id=? LIMIT 1");
$name_stmt->bind_param("i", $user_id);
$name_stmt->execute();
$student_name = $name_stmt->get_result()->fetch_assoc();

$display_name = 'Student';
$full_name    = 'Student';
if ($student_name) {
    $display_name = htmlspecialchars(
        trim($student_name['first_name'] . ' ' . $student_name['last_name'])
    );
    $full_name = trim(
        $student_name['first_name'] . ' ' .
        (!empty($student_name['middle_name']) ? $student_name['middle_name'] . ' ' : '') .
        $student_name['last_name']
    );
}

// ================= GET TODAY'S SETTINGS & SKIP FLAGS =================
$todaySettings = getSettings($conn, $company_id, $date);
$am_skipped    = $todaySettings ? isAmSkipped($todaySettings) : false;
$pm_skipped    = $todaySettings ? isPmSkipped($todaySettings) : false;

$am_in_done  = !empty($attendance['am_time_in'])  && $attendance['am_time_in']  !== 'missed';
$am_out_done = !empty($attendance['am_time_out']) && $attendance['am_time_out'] !== 'missed';
$pm_in_done  = !empty($attendance['pm_time_in'])  && $attendance['pm_time_in']  !== 'missed';
$pm_out_done = !empty($attendance['pm_time_out']) && $attendance['pm_time_out'] !== 'missed';

$am_in_missed  = ($attendance['am_time_in']  ?? '') === 'missed';
$am_out_missed = ($attendance['am_time_out'] ?? '') === 'missed';
$pm_in_missed  = ($attendance['pm_time_in']  ?? '') === 'missed';
$pm_out_missed = ($attendance['pm_time_out'] ?? '') === 'missed';

$lrStmt = $conn->prepare("SELECT type, status FROM late_requests WHERE student_id=? AND company_id=? AND date=?");
$lrStmt->bind_param("iis", $user_id, $company_id, $date);
$lrStmt->execute();
$pending_requests = [];
foreach ($lrStmt->get_result()->fetch_all(MYSQLI_ASSOC) as $lr) {
    $pending_requests[$lr['type']] = $lr['status'];
}

$lrWindowStatuses = [];
foreach (['am_time_in','am_time_out','pm_time_in','pm_time_out'] as $t) {
    $lrWindowStatuses[$t] = $todaySettings
        ? getLateRequestWindowStatus($t, $todaySettings, $current_time)
        : 'permanently_missed';
}

// ================= ACTIVE STEP (respects skip flags) =================
function get_active_step($am_in, $am_out, $pm_in, $pm_out,
                          $am_in_missed, $am_out_missed, $pm_in_missed, $pm_out_missed,
                          $lrWindowStatuses = [], $pending_requests = [],
                          $am_skipped = false, $pm_skipped = false) {
    $steps = [
        'am_time_in'  => [$am_in,  $am_in_missed,  $am_skipped],
        'am_time_out' => [$am_out, $am_out_missed, $am_skipped],
        'pm_time_in'  => [$pm_in,  $pm_in_missed,  $pm_skipped],
        'pm_time_out' => [$pm_out, $pm_out_missed, $pm_skipped],
    ];
    foreach ($steps as $type => [$done, $missed, $skipped]) {
        if ($skipped) continue; // skip this step entirely
        if ($done)    continue;
        if ($missed)  continue;
        return $type;
    }
    return 'done';
}

$active_step = get_active_step(
    $am_in_done, $am_out_done, $pm_in_done, $pm_out_done,
    $am_in_missed, $am_out_missed, $pm_in_missed, $pm_out_missed,
    $lrWindowStatuses,
    $pending_requests,
    $am_skipped,
    $pm_skipped
);
if ($is_weekend) $active_step = 'weekend';

// ================= SIDEBAR ATTENDANCE BADGE =================
function getAttendanceBadgeInfo($todaySettings, $attendance, $current_time, $is_weekend, $am_skipped = false, $pm_skipped = false) {
    if ($is_weekend || !$todaySettings) return null;

    $windows = [
        'am_time_in'  => ['label' => 'AM Duty Sign In',  'start_key' => 'am_time_in_start',  'end_key' => 'am_time_in_end',  'is_am' => true],
        'am_time_out' => ['label' => 'AM Duty Sign Out', 'start_key' => 'am_time_out_start', 'end_key' => 'am_time_out_end', 'is_am' => true],
        'pm_time_in'  => ['label' => 'PM Duty Sign In',  'start_key' => 'pm_time_in_start',  'end_key' => 'pm_time_in_end',  'is_am' => false],
        'pm_time_out' => ['label' => 'PM Duty Sign Out', 'start_key' => 'pm_time_out_start', 'end_key' => 'pm_time_out_end', 'is_am' => false],
    ];

    $col_map = [
        'am_time_in'  => 'am_time_in',
        'am_time_out' => 'am_time_out',
        'pm_time_in'  => 'pm_time_in',
        'pm_time_out' => 'pm_time_out',
    ];

    $now_sec = timeToSeconds($current_time);

    foreach ($windows as $type => $info) {
        // Skip badge for skipped duty periods
        if ($info['is_am'] && $am_skipped) continue;
        if (!$info['is_am'] && $pm_skipped) continue;

        $start = $todaySettings[$info['start_key']] ?? null;
        $end   = $todaySettings[$info['end_key']]   ?? null;
        if (!$start || !$end) continue;

        $start_sec = timeToSeconds($start);
        $end_sec   = timeToSeconds($end);

        if ($now_sec >= $start_sec && $now_sec <= $end_sec) {
            $col = $col_map[$type];
            $val = $attendance[$col] ?? null;
            $already_done = ($val !== null && $val !== '' && $val !== 'missed');
            if (!$already_done) {
                return [
                    'type'       => $type,
                    'label'      => $info['label'],
                    'start_fmt'  => fmt12php($start),
                    'end_fmt'    => fmt12php($end),
                    'start_time' => $start,
                    'end_time'   => $end,
                ];
            }
        }
    }

    // PM Sign Out late window (only if PM not skipped)
    if (!$pm_skipped) {
        $pmOutEnd = $todaySettings['pm_time_out_end'] ?? null;
        if ($pmOutEnd) {
            $pmOutEndSec   = timeToSeconds($pmOutEnd);
            $lateWindowEnd = $pmOutEndSec + 3600;
            if ($now_sec > $pmOutEndSec && $now_sec <= $lateWindowEnd) {
                $pmOutVal    = $attendance['pm_time_out'] ?? null;
                $alreadyDone = ($pmOutVal !== null && $pmOutVal !== '' && $pmOutVal !== 'missed');
                if (!$alreadyDone) {
                    $lateWindowEndH   = floor($lateWindowEnd / 3600);
                    $lateWindowEndM   = floor(($lateWindowEnd % 3600) / 60);
                    $lateWindowEndStr = sprintf('%02d:%02d:00', $lateWindowEndH, $lateWindowEndM);
                    return [
                        'type'           => 'pm_time_out_late',
                        'label'          => 'PM Sign Out Late Request',
                        'start_fmt'      => fmt12php($pmOutEnd) . ' (missed)',
                        'end_fmt'        => fmt12php($lateWindowEndStr) . ' (deadline)',
                        'start_time'     => $pmOutEnd,
                        'end_time'       => $lateWindowEndStr,
                        'is_late_window' => true,
                    ];
                }
            }
        }
    }

    return null;
}

$attendance_badge_info = getAttendanceBadgeInfo($todaySettings, $attendance ?? [], $current_time, $is_weekend, $am_skipped, $pm_skipped);
$show_attendance_badge = ($attendance_badge_info !== null);

/* ── All-done flag for ANB (respects skip flags) ── */
$_att_all_done = (
    ($am_skipped || ($am_in_done && $am_out_done)) &&
    ($pm_skipped || ($pm_in_done && $pm_out_done))
);

function missedNoticeHtml($type, $pending_requests, $lrWindowStatuses) {
    $typeLabels = ['am_time_in'=>'AM Sign In','am_time_out'=>'AM Sign Out','pm_time_in'=>'PM Sign In','pm_time_out'=>'PM Sign Out'];
    $label     = $typeLabels[$type];
    $status    = $pending_requests[$type] ?? null;
    $winStatus = $lrWindowStatuses[$type] ?? 'permanently_missed';

    if ($status === 'pending') {
        $actionHtml = '<div class="late-req-pending"><i class="fas fa-hourglass-half"></i> Request pending — waiting for company approval</div>';
    } elseif ($status === 'approved') {
        $actionHtml = '<div class="late-req-pending" style="background:#f0fff4;border-color:#EAF3EA;color:#2C5A2C;"> Request approved</div>';
    } elseif ($winStatus === 'open') {
        $actionHtml = '<button class="late-req-btn" onclick="openLateReqModal(\'' . $type . '\')"> Submit Late Request</button>';
    } else {
        $actionHtml = '<div class="late-req-expired"> Late request window has closed. This entry is permanently missed.</div>';
    }

    return '
    <div class="missed-notice">
        <div class="mn-header"> Missed: ' . $label . '</div>
        <div class="mn-body">
            The time window has closed and your ' . $label . ' was not recorded.' .
            ($winStatus === 'open'
                ? ' You can still submit a late request before the next action opens.'
                : ($status === 'pending' || $status === 'approved'
                    ? ''
                    : ' The late request window has also passed.'
                  )
            ) . '
        </div>
        ' . $actionHtml . '
    </div>';
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Attendance — <?= $display_name ?></title>
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
<link href="https://fonts.googleapis.com/css2?family=DM+Sans:wght@400;500;600;700&family=DM+Mono:wght@400;500&display=swap" rel="stylesheet">
<style>
/* ── Design tokens (shared with student_profile.php) ── */
:root {
            /* Field Ops Grid palette (same values as AccomForm.php) */
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

    --neust-maroon: #07145fe5;
    --neust-gold:   #FFD700;
    --neust-active: #1B2A4A;
}

*, *::before, *::after {
    box-sizing: border-box;
}

body {
    font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
    background: #EEF1F6;
    min-height: 100vh;
    color: #1B2A4A;
    margin: 0;
}

/* ══════════════════════════════════════════
   SIDEBAR — canonical shared definition
══════════════════════════════════════════ */
.sidebar {
            width: 260px;
            background: var(--neust-maroon);
            height: 100vh;
            position: fixed;
            display: flex;
            flex-direction: column;
            transition: width 0.3s ease;
            z-index: 1000;
            box-shadow: 4px 0 10px rgba(0,0,0,0.1);
            top: 0; left: 0;
        }

.sidebar.collapsed { width: 80px; }

.sidebar-header {
            padding: 20px;
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
            min-width: 0;
        }

.sidebar-user-name {
            color: var(--neust-gold);
            font-size: 18px;
            font-weight: bold;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
            line-height: 1.3;
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
        }

.sidebar-user-role {
            color: rgba(255,255,255,0.55);
            font-size: 11px;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.8px;
            margin-top: 3px;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }

.sidebar.collapsed .sidebar-user-info {
            opacity: 0;
            width: 0;
            overflow: hidden;
        }

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
        }

.sidebar a i {
            width: 30px;
            font-size: 18px;
            margin-right: 15px;
            text-align: center;
            flex-shrink: 0;
        }

.sidebar.collapsed .link-text { display: none; }

.sidebar.collapsed a i { margin-right: 0; }

.sidebar a:hover:not(.active) {
    background: rgba(255,255,255,0.07);
    color: white;
}

.sidebar a.active {
            background: var(--neust-active);
            color: white;
            border-left: 4px solid var(--neust-gold);
        }

.sidebar-badge {
    background: #A02A2A;
    color: white;
    border-radius: 50%;
    width: 18px;
    height: 18px;
    font-size: 10px;
    font-weight: 700;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    position: absolute;
    right: 18px;
    top: 50%;
    transform: translateY(-50%);
}

.sidebar-badge-att {
            background: #d97706; color: white; border-radius: 50%;
            width: 18px; height: 18px; font-size: 10px; font-weight: 700;
            display: inline-flex; align-items: center; justify-content: center;
            position: absolute; right: 18px; top: 50%; transform: translateY(-50%);
            animation: badge-pulse-att 2s ease-in-out infinite;
        }

@keyframes badge-pulse-att {
    0%, 100% { box-shadow: 0 0 0 0 rgba(217,119,6,0.55); }
    50%       { box-shadow: 0 0 0 6px rgba(217,119,6,0); }
}

.sidebar-badge-journal {
            background: #f59e0b; color: #1c1917; border-radius: 50%;
            min-width: 18px; height: 18px; font-size: 10px; font-weight: 800;
            display: inline-flex; align-items: center; justify-content: center;
            position: absolute; right: 18px; top: 50%; transform: translateY(-50%);
            padding: 0 3px; animation: badge-pulse-journal 2.4s ease-in-out infinite;
        }

@keyframes badge-pulse-journal {
    0%, 100% { box-shadow: 0 0 0 0 rgba(245,158,11,0.5); }
    50%       { box-shadow: 0 0 0 5px rgba(245,158,11,0); }
}

.logout-link { margin-top: auto; padding: 20px; border-top: 1px solid rgba(255,255,255,0.1); }

.logout-link a {
            border: 1px solid var(--neust-gold); color: var(--neust-gold);
            border-radius: 6px; justify-content: center; padding: 10px;
            display: flex; align-items: center; text-decoration: none;
            font-size: 14px; transition: background 0.2s;
        }

.logout-link a:hover { background: rgba(255,215,0,0.08); }

.toggle-btn {
            background: transparent; border: none; color: white;
            cursor: pointer; font-size: 20px; outline: none; flex-shrink: 0;
        }

/* ══════════════════════════════════════════
   NAVBAR
══════════════════════════════════════════ */
.navbar {
    background: var(--neust-maroon);
    padding: 10px 30px;
    display: flex;
    align-items: center;
    color: white;
    height: 60px;
    flex-shrink: 0;
    box-shadow: none;
    position: relative;
    z-index: 99;
}

.navbar img {
    height: 40px;
    margin-right: 14px;
    flex-shrink: 0;
}

.navbar-brand {
    display: flex;
    flex-direction: column;
    gap: 0;
}

.navbar-brand .navbar-title {
    font-weight: 700;
    font-size: 16px;
    line-height: 1.3;
    color: #ffffff;
    font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
}

.navbar-brand .navbar-subtitle {
    font-size: 11px;
    color: var(--neust-gold);
    line-height: 1.3;
    font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
}

/* ══════════════════════════════════════════
   ATTENDANCE NOTIFICATION BAR
══════════════════════════════════════════ */
#att-notif-bar {
            position: fixed;
            top: 60px;
            left: 50%;
            transform: translateX(-50%) translateY(-120%);
            visibility: hidden;
            opacity: 0;
            width: calc(100% - 300px);
            max-width: 820px;
            background: var(--grid-navy);
            border-radius: 0;
            border: 1px solid #55668C;
            border-top: none;
            box-shadow: 0 8px 24px rgba(27,42,74,0.30);
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
            width: 34px; height: 34px; border-radius: 0;
            background: var(--grid-amber-bg);
            display: flex; align-items: center; justify-content: center;
            flex-shrink: 0;
        }

.anb-icon i { font-size: 16px; color: var(--grid-amber); }

.anb-pulse {
            width: 8px; height: 8px; border-radius: 50%;
            background: #F7C600; flex-shrink: 0;
            animation: anb-blink 1.4s ease-in-out infinite;
        }

@keyframes anb-blink {
    0%, 100% { opacity: 1; }
    50%       { opacity: .2; }
}

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
            color: #ffffff;
            text-transform: uppercase;
            letter-spacing: 0.4px;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }

.anb-window {
            font-size: 11px;
            color: #E3E8F1;
            opacity: .75;
            margin-top: 1px;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }

.anb-divider { width: 1px; height: 26px; background: rgba(255,255,255,.18); flex-shrink: 0; }

.anb-countdown {
            font-size: 11px;
            font-weight: 700;
            color: #F7C600;
            white-space: nowrap;
            background: rgba(247,198,0,.10);
            border-radius: 0;
            padding: 3px 11px;
            border: 1px solid rgba(247,198,0,.35);
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            font-variant-numeric: tabular-nums;
            flex-shrink: 0;
            min-width: 100px;
            text-align: center;
        }

.anb-btn {
            background: #F7C600; color: var(--grid-navy); border: 1px solid #F7C600;
            border-radius: 0; padding: 7px 15px; font-size: 11px; font-weight: 700;
            text-transform: uppercase; letter-spacing: 0.4px;
            font-family: inherit; white-space: nowrap; flex-shrink: 0;
            transition: opacity .15s; cursor: pointer;
        }

.anb-btn:hover { opacity: .88; }

.anb-close {
            background: rgba(255,255,255,.10); border: 1px solid rgba(255,255,255,.18);
            color: #E3E8F1; width: 26px; height: 26px;
            border-radius: 0; font-size: 13px;
            display: flex; align-items: center; justify-content: center;
            flex-shrink: 0; transition: background .15s; cursor: pointer;
        }

.anb-close:hover { background: rgba(255,255,255,.22); color: #ffffff; }

.anb-progress {
            position: absolute; bottom: 0; left: 0;
            height: 2px; background: #F7C600; border-radius: 0;
            pointer-events: none;
        }

/* ══════════════════════════════════════════
   MAIN LAYOUT
══════════════════════════════════════════ */
.main-content {
    margin-left: 260px;
    width: calc(100% - 260px);
    transition: margin-left 0.3s, width 0.3s;
    display: flex;
    flex-direction: column;
    min-height: 100vh;
}

/* ══════════════════════════════════════════
   PAGE WRAP
══════════════════════════════════════════ */
.page-wrap {
    flex: 1;
    max-width: 480px;
    margin: 0 auto;
    padding: 20px 16px 100px;
    width: 100%;
}

/* ══════════════════════════════════════════
   ATTENDANCE HEADER
══════════════════════════════════════════ */
.att-header {
    display: flex;
    align-items: center;
    justify-content: space-between;
    margin-bottom: 20px;
}

.att-header-left h1 {
    font-size: 20px;
    font-weight: 700;
    color: #1B2A4A;
    line-height: 1.2;
}

.att-header-left p {
    font-size: 12px;
    color: #5A6272;
    margin-top: 2px;
    font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
}

.att-header-right {
    display: flex;
    align-items: center;
    gap: 8px;
}

.dashboard-link {
    background: #1B2A4A;
    color: #fff;
    text-decoration: none;
    border-radius: 0;
    padding: 8px 14px;
    font-size: 12px;
    font-weight: 600;
    display: flex;
    align-items: center;
    gap: 5px;
    transition: background .2s;
}

.dashboard-link:hover {
    background: #2d3748;
}

.history-btn {
    background: #2d3748;
    color: #fff;
    border: none;
    border-radius: 0;
    padding: 8px 14px;
    font-size: 12px;
    font-weight: 600;
    font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
    display: flex;
    align-items: center;
    gap: 5px;
    cursor: pointer;
    transition: background .2s;
    text-decoration: none;
    white-space: nowrap;
}

.history-btn:hover {
    background: #5A6272;
}

/* ══════════════════════════════════════════
   CAMERA & ATTENDANCE UI
══════════════════════════════════════════ */
.camera-card {
    background: #1B2A4A;
    border-radius: 0;
    overflow: hidden;
    margin-bottom: 18px;
    position: relative;
    aspect-ratio: 4/3;
    box-shadow: none;
}

.camera-card video {
    width: 100%;
    height: 100%;
    object-fit: cover;
    display: block;
}

.camera-overlay {
    position: absolute;
    inset: 0;
    pointer-events: none;
}

.camera-overlay::before,
.camera-overlay::after {
    content: '';
    position: absolute;
    width: 28px;
    height: 28px;
    border-color: rgba(255,255,255,.55);
    border-style: solid;
}

.camera-overlay::before {
    top: 16px;
    left: 16px;
    border-width: 2px 0 0 2px;
    border-radius: 0;
}

.camera-overlay::after {
    bottom: 16px;
    right: 16px;
    border-width: 0 2px 2px 0;
    border-radius: 0;
}

.camera-date-badge {
    position: absolute;
    bottom: 14px;
    left: 16px;
    background: rgba(0,0,0,.5);
    color: #fff;
    font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
    font-size: 11px;
    padding: 4px 10px;
    border-radius: 0;
    backdrop-filter: blur(8px);
}

.weekend-banner {
    background: var(--grid-navy);
    color: #fff;
    border-radius: 0;
    padding: 24px 20px;
    text-align: center;
    margin-bottom: 18px;
}

.weekend-banner .wb-icon {
    font-size: 40px;
    display: block;
    margin-bottom: 10px;
}

.weekend-banner h3 {
    font-size: 17px;
    font-weight: 700;
}

.weekend-banner p {
    font-size: 13px;
    opacity: .8;
    margin-top: 4px;
}

/* ── Skipped duty notice banner ── */
.skipped-duty-notice {
    background: #E7ECF7;
    border: 1px solid var(--grid-border);
    border-radius: 0;
    padding: 16px 18px;
    margin-bottom: 14px;
    display: flex;
    align-items: flex-start;
    gap: 12px;
}

.skipped-duty-notice .sdn-icon {
    font-size: 22px;
    line-height: 1;
    flex-shrink: 0;
    margin-top: 1px;
}

.skipped-duty-notice .sdn-text h4 {
    font-size: 13px;
    font-weight: 700;
    color: #1B2A4A;
    margin: 0 0 3px;
}

.skipped-duty-notice .sdn-text p {
    font-size: 12px;
    color: #1B2A4A;
    margin: 0;
    line-height: 1.5;
}

.step-card {
    background: #fff;
    border-radius: 0;
    padding: 20px;
    margin-bottom: 18px;
    box-shadow: none;
}

.step-label {
    font-size: 11px;
    font-weight: 700;
    letter-spacing: .08em;
    text-transform: uppercase;
    color: #8A93A6;
    margin-bottom: 14px;
    display: flex;
    align-items: center;
    gap: 6px;
}

.step-label::before {
    content: '';
    width: 6px;
    height: 6px;
    border-radius: 50%;
    background: #2C5A2C;
    flex-shrink: 0;
    animation: pulse-dot 1.6s ease-in-out infinite;
}

@keyframes pulse-dot {
    0%, 100% { opacity: 1; transform: scale(1); }
    50%       { opacity: .4; transform: scale(1.5); }
}

.action-btn {
    width: 100%;
    padding: 16px;
    border: none;
    border-radius: 0;
    font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
    font-size: 16px;
    font-weight: 700;
    cursor: pointer;
    display: flex;
    align-items: center;
    justify-content: center;
    gap: 10px;
    transition: transform .15s, box-shadow .15s, opacity .2s;
    position: relative;
    overflow: hidden;
}

.action-btn:active {
    transform: scale(.98);
}

.action-btn.am-in {
    background: var(--grid-amber);
    color: #fff;
    box-shadow: none;
}

.action-btn.am-out {
    background: #A02A2A;
    color: #fff;
    box-shadow: none;
}

.action-btn.pm-in {
    background: var(--grid-navy);
    color: #fff;
    box-shadow: none;
}

.action-btn.pm-out {
    background: var(--grid-green);
    color: #fff;
    box-shadow: none;
}

.action-btn.loading {
    opacity: .7;
    pointer-events: none;
}

.action-btn .btn-spinner {
    width: 18px;
    height: 18px;
    border: 2.5px solid rgba(255,255,255,.4);
    border-top-color: #fff;
    border-radius: 50%;
    animation: spin .6s linear infinite;
    display: none;
}

.action-btn.loading .btn-spinner {
    display: block;
}

.action-btn.loading .btn-text {
    display: none;
}

@keyframes spin {
    to { transform: rotate(360deg); }
}

.missed-notice {
    background: #F7E9E9;
    border: 1.5px solid #E3BCBC;
    border-radius: 0;
    padding: 14px 16px;
    margin-bottom: 12px;
}

.missed-notice .mn-header {
    font-size: 13px;
    font-weight: 700;
    color: #A02A2A;
    margin-bottom: 6px;
    display: flex;
    align-items: center;
    gap: 6px;
}

.missed-notice .mn-body {
    font-size: 12px;
    color: #A0850A;
    line-height: 1.5;
    margin-bottom: 10px;
}

.late-req-btn {
    width: 100%;
    padding: 10px;
    border: none;
    border-radius: 0;
    background: #A02A2A;
    color: #fff;
    font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
    font-size: 13px;
    font-weight: 700;
    cursor: pointer;
    transition: background .2s;
    display: flex;
    align-items: center;
    justify-content: center;
    gap: 6px;
}

.late-req-btn:hover {
    background: #A02A2A;
}

.late-req-btn:disabled {
    opacity: .5;
    cursor: not-allowed;
}

.late-req-pending {
    display: inline-flex;
    align-items: center;
    gap: 5px;
    background: #FAF3DC;
    border: 1px solid #E6D9A8;
    border-radius: 0;
    padding: 6px 12px;
    font-size: 12px;
    color: #A0850A;
    font-weight: 600;
    margin-top: 4px;
}

.late-req-expired {
    display: flex;
    align-items: center;
    gap: 6px;
    background: #F7E9E9;
    border: 1px solid #E3BCBC;
    border-radius: 0;
    padding: 8px 12px;
    font-size: 12px;
    color: #6E1C1C;
    font-weight: 600;
    margin-top: 4px;
}

.lr-countdown {
    display: flex;
    align-items: center;
    gap: 6px;
    background: #FAF3DC;
    border: 1px solid #E6D9A8;
    border-radius: 0;
    padding: 7px 12px;
    font-size: 12px;
    color: #A0850A;
    font-weight: 600;
    margin-bottom: 10px;
}

.lr-countdown .lr-countdown-time {
    font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
    font-size: 13px;
    color: #A0850A;
}

.done-card {
    background: var(--grid-green-bg);
    border: 1px solid #BFE0BF;
    border-radius: 0;
    padding: 22px 20px;
    text-align: center;
    margin-bottom: 18px;
}

.done-card .done-icon {
    font-size: 36px;
    display: block;
    margin-bottom: 8px;
}

.done-card h3 {
    font-size: 16px;
    font-weight: 700;
    color: #2C5A2C;
}

.done-card p {
    font-size: 13px;
    color: #2C5A2C;
    margin-top: 4px;
}

.progress-track {
    background: #fff;
    border-radius: 0;
    padding: 16px 18px;
    margin-bottom: 18px;
    box-shadow: none;
}

.progress-track-title {
    font-size: 11px;
    font-weight: 700;
    text-transform: uppercase;
    letter-spacing: .07em;
    color: #8A93A6;
    margin-bottom: 14px;
}

.track-steps {
    display: flex;
    align-items: center;
}

.track-step {
    flex: 1;
    display: flex;
    flex-direction: column;
    align-items: center;
    position: relative;
}

.track-step:not(:last-child)::after {
    content: '';
    position: absolute;
    top: 14px;
    left: calc(50% + 14px);
    right: calc(-50% + 14px);
    height: 2px;
    background: #DCE1EC;
    z-index: 0;
}

.track-step:not(:last-child).done-step::after {
    background: #2C5A2C;
}

.step-circle {
    width: 28px;
    height: 28px;
    border-radius: 50%;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 11px;
    font-weight: 700;
    position: relative;
    z-index: 1;
    transition: all .3s;
}

.step-circle.done         { background: #2C5A2C; color: #fff; }
.step-circle.active       { background: #1B2A4A; color: #fff; box-shadow: 0 0 0 3px rgba(26,32,44,.15); }
.step-circle.todo         { background: #DCE1EC; color: #8A93A6; }
.step-circle.missed       { background: #A02A2A; color: #fff; }
.step-circle.perm-missed  { background: #6E1C1C; color: #fff; }
.step-circle.pending-late { background: #A0850A; color: #fff; }
.step-circle.skipped      { background: #DCE1EC; color: #8A93A6; }

.track-step-label {
    font-size: 9.5px;
    font-weight: 600;
    color: #8A93A6;
    margin-top: 5px;
    text-align: center;
    line-height: 1.2;
}

.track-step.done-step        .track-step-label { color: #2C5A2C; }
.track-step.active-step      .track-step-label { color: #1B2A4A; }
.track-step.missed-step      .track-step-label { color: #A02A2A; }
.track-step.perm-missed-step .track-step-label { color: #6E1C1C; }
.track-step.pending-late-step .track-step-label { color: #A0850A; }
.track-step.skipped-step     .track-step-label { color: #cbd5e0; }

.log-card {
    background: #fff;
    border-radius: 0;
    padding: 16px 18px;
    margin-bottom: 18px;
    box-shadow: none;
}

.log-card-title {
    font-size: 11px;
    font-weight: 700;
    text-transform: uppercase;
    letter-spacing: .07em;
    color: #8A93A6;
    margin-bottom: 12px;
}

.log-duty {
    margin-bottom: 12px;
    padding-bottom: 12px;
    border-bottom: 1px solid #DCE1EC;
}

.log-duty:last-child {
    margin-bottom: 0;
    padding-bottom: 0;
    border-bottom: none;
}

.log-duty-label {
    font-size: 12px;
    font-weight: 700;
    color: #5A6272;
    margin-bottom: 8px;
    display: flex;
    align-items: center;
    gap: 6px;
}

.log-rows {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 8px;
}

.log-item {
    background: #F3F5F9;
    border-radius: 0;
    padding: 8px 10px;
    display: flex;
    gap: 8px;
    align-items: flex-start;
}

.log-item img {
    width: 44px;
    height: 44px;
    border-radius: 0;
    object-fit: cover;
    flex-shrink: 0;
}

.log-item-info {
    min-width: 0;
}

.log-item-info .li-label {
    font-size: 9px;
    font-weight: 700;
    text-transform: uppercase;
    letter-spacing: .05em;
    color: #8A93A6;
}

.log-item-info .li-time {
    font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
    font-size: 12px;
    font-weight: 500;
    color: #2d3748;
    margin-top: 1px;
}

.log-item-info .li-time.empty   { color: #cbd5e0; font-style: italic; }
.log-item-info .li-time.missed  { color: #A02A2A; font-weight: 700; }
.log-item-info .li-time.pending { color: #A0850A; font-weight: 700; }
.log-item-info .li-time.skipped { color: #8A93A6; font-style: italic; }

/* Skipped duty log block */
.log-duty-skipped {
    background: #F3F5F9;
    border: 1.5px dashed #cbd5e0;
    border-radius: 0;
    padding: 12px 14px;
    display: flex;
    align-items: center;
    gap: 10px;
    color: #8A93A6;
    font-size: 12px;
    font-weight: 600;
}

.log-duty-skipped i {
    font-size: 16px;
    color: #cbd5e0;
}

#toast {
    position: fixed;
    bottom: 24px;
    left: 50%;
    transform: translateX(-50%) translateY(80px);
    background: #1B2A4A;
    color: #fff;
    padding: 12px 22px;
    border-radius: 0;
    font-size: 14px;
    font-weight: 500;
    box-shadow: 0 6px 24px rgba(0,0,0,.2);
    opacity: 0;
    transition: opacity .3s, transform .3s;
    z-index: 9999;
    text-align: center;
    max-width: 340px;
    pointer-events: none;
}

#toast.show    { opacity: 1; transform: translateX(-50%) translateY(0); }
#toast.success { background: #2C5A2C; }
#toast.error   { background: #A02A2A; }
#toast.warning { background: #A0850A; }

/* History Drawer */
#history-overlay {
    position: fixed;
    inset: 0;
    background: rgba(0,0,0,.45);
    z-index: 1100;
    opacity: 0;
    pointer-events: none;
    transition: opacity .28s;
}

#history-overlay.open {
    opacity: 1;
    pointer-events: all;
}

#history-drawer {
    position: fixed;
    top: 0;
    right: -440px;
    width: min(440px,100vw);
    height: 100vh;
    background: #fff;
    z-index: 1200;
    display: flex;
    flex-direction: column;
    box-shadow: -4px 0 28px rgba(0,0,0,.15);
    transition: right .3s cubic-bezier(.4,0,.2,1);
    font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
}

#history-drawer.open {
    right: 0;
}

.drawer-header {
    background: #1B2A4A;
    color: #fff;
    padding: 18px 20px;
    display: flex;
    align-items: center;
    justify-content: space-between;
    flex-shrink: 0;
}

.drawer-header h3 {
    margin: 0;
    font-size: 16px;
    font-weight: 600;
}

.drawer-close {
    background: rgba(255,255,255,.12);
    border: none;
    color: #fff;
    width: 30px;
    height: 30px;
    border-radius: 50%;
    cursor: pointer;
    font-size: 16px;
    display: flex;
    align-items: center;
    justify-content: center;
    transition: background .2s;
}

.drawer-close:hover {
    background: rgba(255,255,255,.25);
}

.drawer-body {
    flex: 1;
    overflow-y: auto;
    padding: 0;
}

.drawer-state {
    display: flex;
    flex-direction: column;
    align-items: center;
    justify-content: center;
    padding: 60px 20px;
    color: #8A93A6;
    gap: 12px;
    font-size: 14px;
}

.drawer-spinner {
    width: 28px;
    height: 28px;
    border: 3px solid #DCE1EC;
    border-top-color: #1B2A4A;
    border-radius: 50%;
    animation: spin .7s linear infinite;
}

.hist-month-header {
    position: sticky;
    top: 0;
    background: #F3F5F9;
    color: #5A6272;
    font-size: 11px;
    font-weight: 700;
    letter-spacing: .08em;
    text-transform: uppercase;
    padding: 8px 18px;
    z-index: 2;
    border-bottom: 1px solid #DCE1EC;
}

.hist-day {
    padding: 12px 18px;
    border-bottom: 1px solid #F3F5F9;
}

.hist-day.is-dayoff {
    background: #EFEBF7;
}

.hist-day-label {
    font-size: 13px;
    font-weight: 600;
    color: #2d3748;
    margin-bottom: 8px;
}

.dayoff-chip {
    background: #EFEBF7;
    color: #5B4A8A;
    border-radius: 0;
    padding: 4px 12px;
    font-size: 12px;
    font-weight: 600;
    display: inline-block;
}

.hist-duty-row {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 6px;
}

.hist-duty-block {
    background: #F3F5F9;
    border-radius: 0;
    padding: 8px 10px;
    font-size: 12px;
    border: 1px solid var(--grid-border-soft);
}

.hist-duty-block.pm {
    border-color: #BFE0BF;
}

.hist-duty-block strong {
    display: block;
    font-size: 10px;
    color: #8A93A6;
    margin-bottom: 3px;
    text-transform: uppercase;
}

.hist-time         { color: #1B2A4A; font-weight: 600; font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif; }
.hist-time.missing { color: #cbd5e0; font-style: italic; }
.hist-time.missed  { color: #A02A2A; font-weight: 700; }

/* Late Request Modal */
#lateReqOverlay {
    display: none;
    position: fixed;
    inset: 0;
    background: rgba(0,0,0,.55);
    z-index: 5000;
    align-items: center;
    justify-content: center;
    backdrop-filter: blur(4px);
}

#lateReqOverlay.open {
    display: flex;
}

#lateReqBox {
    background: #fff;
    border-radius: 0;
    width: 460px;
    max-width: 96vw;
    box-shadow: none;
    overflow: hidden;
    animation: lr-in .3s cubic-bezier(.34,1.56,.64,1);
}

@keyframes lr-in {
    from { opacity: 0; transform: scale(.86) translateY(20px); }
    to   { opacity: 1; transform: scale(1) translateY(0); }
}

.lr-header {
    background: var(--grid-red);
    color: #fff;
    padding: 20px 22px 14px;
}

.lr-header .lr-icon {
    font-size: 30px;
    display: block;
    margin-bottom: 6px;
}

.lr-header h3 {
    margin: 0;
    font-size: 17px;
    font-weight: 700;
}

.lr-header p {
    margin: 4px 0 0;
    font-size: 12px;
    opacity: .85;
}

.lr-body {
    padding: 18px 22px;
}

.lr-type-label {
    font-size: 12px;
    font-weight: 700;
    color: #5A6272;
    margin-bottom: 4px;
    text-transform: uppercase;
    letter-spacing: .05em;
}

.lr-type-val {
    font-size: 14px;
    font-weight: 700;
    color: #A02A2A;
    margin-bottom: 14px;
}

.lr-label {
    font-size: 12px;
    font-weight: 700;
    color: #5A6272;
    margin-bottom: 5px;
    display: block;
}

.lr-textarea {
    width: 100%;
    border: 1.5px solid #DCE1EC;
    border-radius: 0;
    padding: 10px 12px;
    font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
    font-size: 13px;
    color: #2d3748;
    resize: vertical;
    min-height: 90px;
    transition: border-color .2s;
}

.lr-textarea:focus {
    outline: none;
    border-color: #A02A2A;
}

.lr-window-warn {
    display: flex;
    align-items: center;
    gap: 8px;
    background: #FAF3DC;
    border: 1px solid #E6D9A8;
    border-radius: 0;
    padding: 10px 14px;
    margin-bottom: 14px;
    font-size: 12px;
    color: #A0850A;
    font-weight: 500;
    line-height: 1.5;
}

.lr-window-warn strong {
    color: #A0850A;
    font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
}

.lr-camera-wrap {
    position: relative;
    width: 100%;
    aspect-ratio: 16/9;
    background: #1B2A4A;
    border-radius: 0;
    overflow: hidden;
    margin-bottom: 14px;
}

.lr-camera-wrap video {
    width: 100%;
    height: 100%;
    object-fit: cover;
    display: block;
}

.lr-camera-label {
    position: absolute;
    bottom: 8px;
    left: 50%;
    transform: translateX(-50%);
    background: rgba(0,0,0,.55);
    color: #fff;
    font-size: 10px;
    font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
    padding: 3px 10px;
    border-radius: 0;
    white-space: nowrap;
    backdrop-filter: blur(6px);
}

.lr-camera-corner {
    position: absolute;
    width: 20px;
    height: 20px;
    border-color: rgba(255,255,255,.5);
    border-style: solid;
    pointer-events: none;
}

.lr-camera-corner.tl {
    top: 8px;
    left: 8px;
    border-width: 2px 0 0 2px;
    border-radius: 0;
}

.lr-camera-corner.br {
    bottom: 8px;
    right: 8px;
    border-width: 0 2px 2px 0;
    border-radius: 0;
}

.lr-photo-preview {
    margin-top: 10px;
}

.lr-photo-preview img {
    width: 80px;
    height: 80px;
    border-radius: 0;
    object-fit: cover;
    display: none;
}

.lr-footer {
    padding: 0 22px 20px;
    display: flex;
    justify-content: flex-end;
    gap: 10px;
}

.lr-btn-cancel {
    padding: 10px 20px;
    background: #F3F5F9;
    border: 1.5px solid #DCE1EC;
    border-radius: 0;
    font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
    font-size: 13px;
    font-weight: 600;
    color: #5A6272;
    cursor: pointer;
    transition: all .18s;
}

.lr-btn-cancel:hover {
    background: #DCE1EC;
}

.lr-btn-submit {
    padding: 10px 22px;
    background: #A02A2A;
    border: none;
    border-radius: 0;
    font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
    font-size: 14px;
    font-weight: 700;
    color: #fff;
    cursor: pointer;
    transition: all .18s;
}

.lr-btn-submit:hover {
    background: #A02A2A;
}

.lr-btn-submit.loading {
    opacity: .6;
    pointer-events: none;
}

.lr-pending-badge {
    display: inline-flex;
    align-items: center;
    gap: 4px;
    background: #FAF3DC;
    border: 1px solid #E6D9A8;
    border-radius: 0;
    padding: 3px 10px;
    font-size: 10px;
    font-weight: 700;
    color: #A0850A;
    margin-top: 3px;
}
        /* ══ Field Ops Grid (AccomForm.php) — shared additions ══
           Responsive attendance bar + visible keyboard focus + reduced
           motion, exactly as AccomForm.php defines them. */
        @media (max-width: 768px) {
            #att-notif-bar,
            #att-notif-bar.sidebar-collapsed {
                left: 50% !important;
                width: calc(100% - 20px) !important;
                max-width: none !important;
            }
        }
        .sidebar.collapsed .logout-link a { border-color: transparent; }
        .anb-btn:focus-visible, .anb-close:focus-visible, .ndm-close-btn:focus-visible,
        .toggle-btn:focus-visible { outline: 2px solid #F7C600; outline-offset: 2px; }
        @media (prefers-reduced-motion: reduce) {
            .ndm-box, .anb-pulse, .sidebar-badge-att, .sidebar-badge-journal { animation: none; }
        }
/* ══ Field Ops Grid (AccomForm.php) — page typography ══
   Square corners, thin slate borders instead of soft shadows, navy
   actions, small uppercase labels, flat status colours, no emoji.
   Only the look changes; every class/id the scripts use is kept. */
body { background: var(--grid-bg); color: #2d3748; font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif; }
.att-header { border-bottom: 1px solid var(--grid-border); padding-bottom: 12px; }
.att-header-left h1 { color: var(--grid-navy); text-transform: uppercase; letter-spacing: 0.6px; font-size: 18px; }
.att-header-left p { color: var(--grid-muted); font-variant-numeric: tabular-nums; }
.dashboard-link, .history-btn {
    background: #fff; color: var(--grid-navy); border: 1px solid var(--grid-border);
    font-size: 11px; text-transform: uppercase; letter-spacing: 0.4px;
}
.dashboard-link:hover, .history-btn:hover { background: #f3f4f7; }
.camera-card { border: 1px solid var(--grid-navy); }
.camera-date-badge { border-radius: 0; font-variant-numeric: tabular-nums; }
.step-card, .progress-track, .log-card { border: 1px solid var(--grid-border); }
.step-label, .progress-track-title, .log-card-title { color: var(--grid-navy); letter-spacing: 0.5px; }
.step-label::before { background: var(--grid-green); }
.action-btn { font-size: 13px; text-transform: uppercase; letter-spacing: 0.6px; }
.action-btn:focus-visible, .late-req-btn:focus-visible, .lr-btn-submit:focus-visible,
.lr-btn-cancel:focus-visible, .dashboard-link:focus-visible, .history-btn:focus-visible,
.drawer-close:focus-visible { outline: 2px solid var(--grid-navy); outline-offset: 2px; }
.late-req-btn, .lr-btn-submit, .lr-btn-cancel { font-size: 12px; text-transform: uppercase; letter-spacing: 0.4px; }
.missed-notice { border: 1px solid #E3BCBC; }
.missed-notice .mn-header { text-transform: uppercase; letter-spacing: 0.3px; font-size: 12px; }
.late-req-pending, .late-req-expired, .lr-countdown, .lr-window-warn, .lr-pending-badge { border-width: 1px; }
.weekend-banner { border: 1px solid var(--grid-navy); }
.weekend-banner .wb-icon { color: #F7C600; font-size: 34px; }
.weekend-banner h3, .done-card h3 { text-transform: uppercase; letter-spacing: 0.4px; font-size: 15px; }
.done-card .done-icon { color: var(--grid-green); font-size: 34px; }
.skipped-duty-notice .sdn-icon { color: var(--grid-navy); font-size: 18px; }
.skipped-duty-notice .sdn-text h4 { color: var(--grid-navy); text-transform: uppercase; letter-spacing: 0.3px; font-size: 12px; }
.step-circle.done { background: var(--grid-green); }
.step-circle.active { background: var(--grid-navy); box-shadow: 0 0 0 3px rgba(27,42,74,.15); }
.step-circle.todo { background: #DCE1EC; color: var(--grid-muted); }
.step-circle.pending-late { background: var(--grid-amber); }
.step-circle.missed { background: var(--grid-red); }
.step-circle i { font-size: 11px; }
.track-step:not(:last-child).done-step::after { background: var(--grid-green); }
.track-step.done-step .track-step-label { color: var(--grid-green); }
.track-step.active-step .track-step-label { color: var(--grid-navy); }
.track-step.missed-step .track-step-label { color: var(--grid-red); }
.log-duty { border-bottom: 1px solid var(--grid-border-soft); }
.log-duty-label { color: var(--grid-navy); text-transform: uppercase; letter-spacing: 0.3px; font-size: 11px; }
.log-item { border: 1px solid var(--grid-border-soft); }
.log-item-info .li-time, .hist-time, .lr-countdown .lr-countdown-time, .lr-window-warn strong { font-variant-numeric: tabular-nums; }
.log-duty-skipped { border: 1px dashed var(--grid-border); }
#toast { border: 1px solid #55668C; }
.drawer-header { background: var(--grid-navy); }
.drawer-header h3 { text-transform: uppercase; letter-spacing: 0.4px; font-size: 13px; }
.drawer-close { border-radius: 0; border: 1px solid rgba(255,255,255,0.25); background: transparent; }
.drawer-state span:first-child i { font-size: 26px; color: var(--grid-border); }
.hist-month-header { color: var(--grid-navy); border-bottom: 1px solid var(--grid-border); }
.hist-day-label { color: var(--grid-navy); }
.dayoff-chip { border: 1px solid #D5CCE8; text-transform: uppercase; letter-spacing: 0.3px; font-size: 11px; }
#lateReqBox { border: 1px solid var(--grid-border); }
.lr-header h3 { text-transform: uppercase; letter-spacing: 0.4px; font-size: 15px; }
.lr-header .lr-icon { font-size: 24px; }
.lr-type-val { color: var(--grid-red); }
.lr-textarea { border: 1px solid var(--grid-border); }
.lr-textarea:focus { border-color: var(--grid-navy); box-shadow: 0 0 0 3px rgba(27,42,74,0.08); }
@media (prefers-reduced-motion: reduce) {
    .step-label::before, #lateReqBox { animation: none; }
}

/* ══ Bold pass: stronger buttons, richer status colours, clearer card hierarchy ══
   Appended override block. Colours/weights only; no selectors, markup or
   scripts were changed. Contrast of every text/background pair is >= 4.5:1. */
:root {
    --grid-navy-deep: #12203F;
    --grid-blue: #1F3C88;
    --grid-blue-hover: #2A4DAA;
    --grid-gold: #F2B705;
    --grid-gold-hover: #FFC929;
    --grid-green-strong: #1E7A3A;
    --grid-red-strong: #C0272D;
    --grid-amber-strong: #B26A00;
}

/* Header buttons: solid and clearly clickable instead of ghost outlines */
.dashboard-link, .history-btn {
    font-size: 12px; font-weight: 700; padding: 9px 16px;
    border: 1px solid transparent; box-shadow: 0 2px 0 rgba(18,32,63,.25);
    transition: background .18s, transform .12s, box-shadow .12s;
}
.history-btn { background: var(--grid-navy); color: #fff; }
.history-btn:hover { background: var(--grid-blue); }
.dashboard-link { background: var(--grid-gold); color: var(--grid-navy-deep); }
.dashboard-link:hover { background: var(--grid-gold-hover); }
.dashboard-link:active, .history-btn:active { transform: translateY(1px); box-shadow: 0 1px 0 rgba(18,32,63,.25); }

/* Page title gets a gold marker so the page has a strong anchor */
.att-header { border-bottom: 2px solid var(--grid-navy); }
.att-header-left h1 { border-left: 4px solid var(--grid-gold); padding-left: 10px; color: var(--grid-navy-deep); }

/* Cards: navy top rule + soft lift so panels stand off the background */
.step-card, .progress-track, .log-card {
    border: 1px solid var(--grid-border); border-top: 3px solid var(--grid-navy);
    box-shadow: 0 2px 8px rgba(27,42,74,.08);
}
.camera-card { border: 2px solid var(--grid-navy); box-shadow: 0 4px 14px rgba(27,42,74,.22); }
.camera-date-badge { background: var(--grid-navy-deep); border-left: 3px solid var(--grid-gold); }
.step-label, .progress-track-title, .log-card-title { color: var(--grid-navy-deep); font-weight: 800; }
.log-duty-label { color: var(--grid-blue); font-weight: 800; }
.log-item { background: #F6F8FC; border: 1px solid var(--grid-border); border-left: 3px solid var(--grid-blue); }
.log-item-info .li-label { color: var(--grid-muted); font-weight: 700; }
.log-item-info .li-time { color: var(--grid-navy-deep); font-weight: 700; }
.log-item-info .li-time.empty { color: #8A93A6; }
.log-item-info .li-time.pending { color: var(--grid-amber-strong); }

/* Primary action buttons: saturated fills, depth edge, clear hover/focus */
.action-btn {
    font-weight: 800; font-size: 14px; padding: 17px; color: #fff;
    border-bottom: 4px solid rgba(0,0,0,.28); box-shadow: 0 4px 12px rgba(27,42,74,.25);
    transition: transform .12s, box-shadow .15s, filter .15s, opacity .2s;
}
.action-btn:hover { filter: brightness(1.1); box-shadow: 0 6px 16px rgba(27,42,74,.32); }
.action-btn:active { transform: translateY(2px); border-bottom-width: 2px; box-shadow: none; }
/* Consistent scheme: sign in = blue, sign out = yellow, red is reserved for late requests */
.action-btn.am-in, .action-btn.pm-in   { background: var(--grid-blue); color: #fff; }
.action-btn.am-out, .action-btn.pm-out { background: var(--grid-gold); color: var(--grid-navy-deep); }
.action-btn:focus-visible { outline: 3px solid var(--grid-gold); outline-offset: 2px; }
.action-btn.am-out .btn-spinner, .action-btn.pm-out .btn-spinner { border-color: rgba(18,32,63,.3); border-top-color: var(--grid-navy-deep); }

/* Late-request buttons */
.late-req-btn, .lr-btn-submit {
    background: var(--grid-red-strong); font-weight: 800;
    border-bottom: 3px solid rgba(0,0,0,.25); box-shadow: 0 2px 6px rgba(160,42,42,.25);
}
.late-req-btn:hover, .lr-btn-submit:hover { background: #A81F25; }
.lr-btn-cancel { background: #fff; border: 2px solid var(--grid-navy); color: var(--grid-navy); font-weight: 800; }
.lr-btn-cancel:hover { background: var(--grid-navy); color: #fff; }

/* Progress steps */
.step-circle.done { background: var(--grid-green-strong); }
.step-circle.active { background: var(--grid-blue); box-shadow: 0 0 0 4px rgba(31,60,136,.25); }
.step-circle.todo { background: #C9D1E3; color: var(--grid-navy); }
.step-circle.pending-late { background: var(--grid-amber-strong); }
.step-circle.missed { background: var(--grid-red-strong); }
.track-step:not(:last-child).done-step::after { background: var(--grid-green-strong); }
.track-step.done-step .track-step-label { color: var(--grid-green-strong); font-weight: 800; }
.track-step.active-step .track-step-label { color: var(--grid-blue); font-weight: 800; }
.track-step.missed-step .track-step-label { color: var(--grid-red-strong); font-weight: 800; }
.step-label::before { background: var(--grid-green-strong); }

/* Status chips and notices */
.late-req-pending, .lr-countdown, .lr-pending-badge { background: #FFF1C2; border: 1px solid #E0B83A; color: #7A4B00; font-weight: 700; }
.late-req-expired, .missed-notice { background: #FBE3E3; border: 1px solid #D98A8A; color: #8E1B20; }
.missed-notice .mn-header { color: #8E1B20; }
.missed-notice .mn-body { color: #7A4B00; }
.skipped-duty-notice { background: #DCE6FA; border: 1px solid #9DB2E0; border-left: 4px solid var(--grid-blue); }
.skipped-duty-notice .sdn-icon, .skipped-duty-notice .sdn-text h4 { color: var(--grid-blue); }
.weekend-banner { background: var(--grid-navy-deep); border: 2px solid var(--grid-gold); }
.done-card .done-icon { color: var(--grid-green-strong); }
.hist-time.missed { color: var(--grid-red-strong); }
.drawer-header { background: var(--grid-navy-deep); border-bottom: 3px solid var(--grid-gold); }
#toast.success { background: var(--grid-green-strong); }
#toast.error { background: var(--grid-red-strong); }
#toast.warning { background: var(--grid-amber-strong); }

/* ══ Small-screen layout ══
   The page never had a mobile layout: the fixed 260px sidebar squeezed the
   content into a thin strip. On narrow screens the content now gets the
   full remaining width and the header stacks. (The sidebar itself is
   collapsed on load by the script at the end of the page.) */
@media (max-width: 768px) {
    .page-wrap { padding: 14px 12px 80px; max-width: none; }
    .att-header { flex-wrap: wrap; gap: 10px; }
    .att-header-right { width: 100%; }
    .att-header-right .history-btn, .att-header-right .dashboard-link { flex: 1; justify-content: center; }
    .step-card, .progress-track, .log-card { padding: 14px; }
    .action-btn { font-size: 13px; padding: 15px; }
    .navbar-brand .navbar-subtitle { display: none; }
    #history-drawer { width: 100%; max-width: 100%; }
    #lateReqBox { width: calc(100% - 24px); max-width: 440px; }
}

/* ══ Popup notification (same as company_list.php's .cv-top-toast) ══
   Square navy bar with a slate frame at the TOP of the page, status icon,
   fades out by itself, several stack downward (newest below). It never
   blocks the page (pointer-events:none). showToast() renders into it. */
.cv-top-toast {
    position: fixed; top: 30px; left: 50%; transform: translateX(-50%);
    background: #1B2A4A; color: #E3E8F1;
    border: 1px solid #55668C; border-radius: 0;
    padding: 14px 20px;
    box-shadow: 0 8px 24px rgba(27,42,74,0.30);
    display: flex; align-items: center; gap: 12px;
    font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
    font-size: 12.5px; line-height: 1.45;
    z-index: 10020; width: max-content; max-width: min(440px, calc(100vw - 24px));
    opacity: 0; transition: opacity 0.35s, top 0.3s ease;
    pointer-events: none;
}
.cv-top-toast.show { opacity: 1; }
.cv-top-toast i { color: #8FD18F; font-size: 18px; flex-shrink: 0; }
.cv-top-toast strong { color: #ffffff; font-weight: 700; }
.cv-top-toast.is-error i { color: #f87171; }
.cv-top-toast.is-warning i { color: #F7C600; }
@media (prefers-reduced-motion: reduce) { .cv-top-toast { transition: none; } }

/* ══ Fit-to-screen desktop layout ══
   Screens >= 1024px wide: header across the top, camera on the left, the
   action / progress / log cards stacked on the right, so the whole page
   fits the window. Layout only: no markup, IDs, classes or scripts changed.
   Phones/tablets keep the stacked, scrollable layout above. */
@media (min-width: 1024px) {
    .page-wrap {
        max-width: 1180px; padding: 14px 24px 16px;
        display: grid; column-gap: 18px; row-gap: 0;
        grid-template-columns: minmax(0, 1.05fr) minmax(0, 1fr);
        /* row 1 header, rows 2-8 right-hand cards (empty rows take no space), row 9 = log */
        grid-template-rows: auto repeat(7, auto) minmax(0, 1fr);
        align-content: start;
    }
    .att-header { grid-column: 1 / -1; margin-bottom: 12px; padding-bottom: 8px; }
    .camera-card {
        /* 4:3 at least; stretches down to the log card's bottom edge when the
           right-hand cards are taller, so both columns always end together. */
        grid-column: 1; grid-row: 2 / span 8; align-self: stretch;
        width: 100%; margin: 0; aspect-ratio: 4 / 3;
    }
    .page-wrap > .step-card,
    .page-wrap > .done-card,
    .page-wrap > .weekend-banner { grid-column: 2; margin: 0 0 12px; padding: 14px 16px; }
    .skipped-duty-notice { grid-column: 2; margin: 0 0 12px; padding: 10px 14px; }
    .progress-track { grid-column: 2; margin: 0 0 12px; padding: 12px 16px; }
    .log-card { grid-column: 2; grid-row: 9; align-self: stretch; max-height: 100%; overflow-y: auto; margin: 0; padding: 12px 16px; }
    .step-label { margin-bottom: 8px; }
    .action-btn { padding: 14px; }
}
/* Tall-enough windows: lock the page to the screen. The page-wrap scrolls
   by itself as a safety net if a busy state (missed notice, skipped-duty
   notices) is ever taller than the window, so nothing is unreachable. */
@media (min-width: 1024px) and (min-height: 620px) {
    .main-content { height: 100vh; overflow: hidden; }
    .page-wrap { flex: 0 1 auto; min-height: 0; overflow-y: auto; }   /* as tall as its content, never taller than the window */
}

/* Keep the four progress dots on one line even when a step carries the
   "Pending" badge (late request submitted): without this the taller step
   pushed its own dot up and bent the connector line. Steps that have no
   badge are the same height, so they are unaffected. */
.track-steps { align-items: flex-start; }

/* ══ Submit Late Request popup: layout of the "Add New Student" form (admin_student_list.php) ══
   Same pieces: overlay, square white box with a slate border, compact header
   (icon + uppercase title + close x), 3-column field grid, small uppercase
   labels, hint line with an info icon, and a footer pinned to the bottom with
   a top rule. Box scrolls inside itself on short screens and keeps a margin
   above and below. Only the look/layout changed: every id the scripts use
   (lr-type-display, lr-window-warn, lr-deadline-time, lr-video-preview,
   lr-reason, lr-photo-wrap, lr-submit-btn) is kept. */
#lateReqOverlay { background: rgba(0,0,0,0.5); backdrop-filter: none; padding: 20px 0; box-sizing: border-box; }
#lateReqBox {
    width: 860px; max-width: 94%; max-height: calc(100vh - 40px); max-height: calc(100dvh - 40px);
    overflow-y: auto; box-sizing: border-box; padding: 16px 24px 0;
    border: 1px solid var(--grid-border); border-radius: 0; box-shadow: none;
    animation: lrPop .3s ease;
}
@keyframes lrPop { from { transform: scale(0.9); opacity: 0; } to { transform: scale(1); opacity: 1; } }
#lateReqBox .lr-header {
    background: transparent; color: var(--grid-navy); padding: 0 0 8px; margin-bottom: 12px;
    display: flex; align-items: center; justify-content: space-between;
    border-bottom: 1px solid var(--grid-border);
}
#lateReqBox .lr-header h3 { margin: 0; font-size: 15px; text-transform: uppercase; letter-spacing: 0.4px; color: var(--grid-navy); font-weight: 700; }
#lateReqBox .lr-header .lr-icon { display: inline; font-size: 15px; margin: 0 4px 0 0; color: var(--grid-red); }
#lateReqBox .lr-close { background: none; border: none; font-size: 24px; line-height: 1; cursor: pointer; color: var(--grid-muted); padding: 0 4px; transition: color .2s; }
#lateReqBox .lr-close:hover { color: var(--grid-navy); }
#lateReqBox .lr-close:focus-visible { outline: 2px solid var(--grid-navy); outline-offset: 2px; }
#lateReqBox .lr-body { padding: 0; }
#lateReqBox .lr-grid { display: grid; grid-template-columns: repeat(3, 1fr); column-gap: 14px; row-gap: 0; align-items: start; }
#lateReqBox .lr-group { margin-bottom: 9px; min-width: 0; }
#lateReqBox .lr-span-2 { grid-column: span 2; }
#lateReqBox .lr-type-label, #lateReqBox .lr-label {
    display: block; font-size: 12px; font-weight: 600; color: #1e293b; margin-bottom: 4px;
    text-transform: none; letter-spacing: 0;
}
#lateReqBox .lr-type-val {
    margin: 0; padding: 7px 10px; font-size: 13px; font-weight: 700; color: var(--grid-red);
    background: #F3F5F9; border: 1px solid var(--grid-border); border-radius: 0; min-height: 34px; box-sizing: border-box;
}
#lateReqBox .lr-window-warn { margin: 0; padding: 7px 10px; min-height: 34px; box-sizing: border-box; font-size: 12px; align-items: center; flex-wrap: wrap; column-gap: 4px; }
/* Enlarged camera: two of the three columns (the reason box takes the third and matches its height).
   Height is capped by the window so the popup stays on screen; the video simply crops to fit. */
#lateReqBox .lr-camera-wrap { aspect-ratio: 4 / 3; max-height: max(220px, calc(100vh - 340px)); width: 100%; margin-bottom: 0; border: 1px solid var(--grid-border); }
#lateReqBox .lr-camera-label { font-size: 11px; padding: 4px 12px; }
#lateReqBox .lr-camera-corner { width: 26px; height: 26px; }
#lateReqBox .lr-reason-group { align-self: stretch; }
#lateReqBox .lr-photo-preview { margin-top: 6px; }
#lateReqBox .lr-reason-group { display: flex; flex-direction: column; }
#lateReqBox .lr-textarea {
    width: 100%; box-sizing: border-box; min-height: 150px; flex: 1 1 auto; padding: 7px 10px; font-size: 13px;
    border: 1px solid var(--grid-border); border-radius: 0; resize: vertical; transition: all .2s;
}
#lateReqBox .lr-textarea:focus { outline: none; border-color: var(--grid-navy); box-shadow: 0 0 0 3px rgba(27,42,74,0.08); }
#lateReqBox .lr-help { font-size: 10.5px; color: var(--grid-muted); margin-top: 3px; line-height: 1.35; }
#lateReqBox .lr-footer {
    padding: 10px 0 12px; margin-top: 4px; gap: 12px; justify-content: flex-end;
    position: sticky; bottom: 0; background: #fff; border-top: 1px solid var(--grid-border); z-index: 2;
}
#lateReqBox .lr-btn-cancel, #lateReqBox .lr-btn-submit {
    padding: 10px 24px; border-radius: 0; font-size: 12px; font-weight: 600;
    text-transform: uppercase; letter-spacing: 0.3px; transition: opacity .2s, background .2s;
}
#lateReqBox .lr-btn-cancel { background: #fff; color: var(--grid-navy); border: 1px solid var(--grid-border); box-shadow: none; }
#lateReqBox .lr-btn-cancel:hover { background: #f3f4f7; color: var(--grid-navy); }
#lateReqBox .lr-btn-submit { background: var(--grid-red-strong); color: #fff; border: none; border-bottom: 3px solid rgba(0,0,0,.25); }
#lateReqBox .lr-btn-submit:hover { background: #A81F25; }
@media (max-width: 900px) {
    #lateReqBox .lr-grid { grid-template-columns: 1fr 1fr; }
    #lateReqBox .lr-span-2 { grid-column: 1 / -1; }
}
@media (max-width: 640px) {
    #lateReqBox { padding: 14px 14px 0; }
    #lateReqBox .lr-grid { grid-template-columns: 1fr; }
    #lateReqBox .lr-span-2 { grid-column: auto; }
    #lateReqBox .lr-textarea { min-height: 110px; }
    #lateReqBox .lr-footer button { flex: 1; }
}
@media (prefers-reduced-motion: reduce) { #lateReqBox { animation: none; } }
    </style>
</head>
<body>

<!-- History Drawer -->
<div id="history-overlay" onclick="closeHistoryDrawer()"></div>
<div id="history-drawer">
    <div class="drawer-header">
        <h3> Attendance History</h3>
        <button class="drawer-close" onclick="closeHistoryDrawer()" aria-label="Close history"><i class="fas fa-xmark"></i></button>
    </div>
    <div class="drawer-body" id="history-body">
        <div class="drawer-state"><div class="drawer-spinner"></div><span>Loading…</span></div>
    </div>
</div>

<!-- Late Request Modal -->
<div id="lateReqOverlay">
    <div id="lateReqBox" role="dialog" aria-modal="true" aria-labelledby="lr-title">
        <div class="lr-header">
            <h3 id="lr-title"><span class="lr-icon"><i class="fas fa-clock-rotate-left"></i></span> Submit Late Request</h3>
            <button type="button" class="lr-close" onclick="closeLateReqModal()" aria-label="Close">&times;</button>
        </div>
        <div class="lr-body">
            <div class="lr-grid">
                <div class="lr-group">
                    <div class="lr-type-label">Entry Type</div>
                    <div class="lr-type-val" id="lr-type-display">—</div>
                </div>
                <div class="lr-group lr-span-2">
                    <div class="lr-type-label">Deadline</div>
                    <div class="lr-window-warn" id="lr-window-warn" style="display:none;">
                         Submit before <strong id="lr-deadline-time">—</strong> — after that this entry is permanently missed.
                    </div>
                </div>
                <div class="lr-group lr-span-2 lr-photo-group">
                    <div class="lr-type-label">Photo</div>
                    <div class="lr-camera-wrap">
                        <video id="lr-video-preview" autoplay playsinline muted></video>
                        <div class="lr-camera-corner tl"></div>
                        <div class="lr-camera-corner br"></div>
                        <div class="lr-camera-label"><i class="fas fa-camera"></i> Photo will be captured on submit</div>
                    </div>
                    <div class="lr-photo-preview" id="lr-photo-wrap"></div>
                </div>
                <div class="lr-group lr-reason-group">
                    <label class="lr-label" for="lr-reason">Reason <span style="color:#A02A2A">*</span></label>
                    <textarea class="lr-textarea" id="lr-reason" placeholder="Describe what happened and why you were unable to sign in/out on time…"></textarea>
                    <div class="lr-help"><i class="fas fa-circle-info"></i> Explain why you missed this entry. Your company will review it.</div>
                </div>
            </div>
        </div>
        <div class="lr-footer">
            <button class="lr-btn-cancel" onclick="closeLateReqModal()">Cancel</button>
            <button class="lr-btn-submit" id="lr-submit-btn" onclick="submitLateRequest()"> Submit Request</button>
        </div>
    </div>
</div>

<!-- Attendance Notification Bar -->
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
    <button class="anb-btn" id="anb-action-btn" onclick="scrollToActionBtn()">Sign now</button>
    <button class="anb-close" id="anb-close-btn" type="button" aria-label="Dismiss notification"><i class="fas fa-xmark"></i></button>
    <div id="anb-progress" class="anb-progress" style="width:100%;"></div>
</div>

<canvas id="canvas" style="display:none;"></canvas>
<input type="hidden" id="csrf-token" value="<?= htmlspecialchars($_SESSION['attendance_token']) ?>">

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
        <a href="student_attendance.php" class="active">
            <i class="fas fa-calendar-check"></i>
            <span class="link-text">Attendance</span>
            <?php if ($show_attendance_badge): ?>
                <span class="sidebar-badge-att">!</span>
            <?php endif; ?>
        </a>
        <a href="student_report.php">
            <i class="fas fa-chart-bar"></i>
            <span class="link-text">Reports</span>
            <span class="sidebar-badge-journal" id="journalEmptyBadge" style="display:none;"></span>
        </a>
        <a href="student_dashboard.php">
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

<!-- ══ MAIN CONTENT ══ -->
<div class="main-content" id="mainContent">

    <nav class="navbar">
        <img src="logo.webp" alt="NEUST Logo">
        <div class="navbar-brand">
            <div class="navbar-title">NEUST Atate Campus</div>
            <div class="navbar-subtitle">Web-Based Smart OJT Monitoring and Supervision Analytics System</div>
        </div>
    </nav>

    <div class="page-wrap">

        <!-- HEADER -->
        <div class="att-header">
            <div class="att-header-left">
                <h1>Attendance</h1>
                <p id="live-date"><?= date("D, M j · g:i A") ?></p>
            </div>
            <div class="att-header-right">
                <button class="history-btn" onclick="openHistoryDrawer()">
                    <i class="fas fa-history"></i> History
                </button>
                <a href="student_dashboard.php" class="dashboard-link">
                    <i class="fas fa-tachometer-alt"></i> Dashboard
                </a>
            </div>
        </div>

        <div class="camera-card">
            <video id="video" autoplay playsinline muted></video>
            <div class="camera-overlay"></div>
            <div class="camera-date-badge" id="cam-badge"><?= date("Y-m-d") ?></div>
        </div>

        <?php if ($is_weekend): ?>
        <div class="weekend-banner">
            <span class="wb-icon"><i class="fas fa-umbrella-beach"></i></span>
            <h3>Today is <?= $weekend_name ?> — Day Off!</h3>
            <p>No attendance entry required on weekends.</p>
        </div>

        <?php elseif ($active_step === 'done'): ?>
        <?php
        // Collect only missed steps that are NOT skipped
        $missedForDone = [];
        foreach (['am_time_in','am_time_out','pm_time_in','pm_time_out'] as $_mt) {
            $skippedFlags2 = [
                'am_time_in'  => $am_skipped,
                'am_time_out' => $am_skipped,
                'pm_time_in'  => $pm_skipped,
                'pm_time_out' => $pm_skipped,
            ];
            if ($skippedFlags2[$_mt]) continue; // skip skipped duties
            $missedFlags2 = ['am_time_in'=>$am_in_missed,'am_time_out'=>$am_out_missed,'pm_time_in'=>$pm_in_missed,'pm_time_out'=>$pm_out_missed];
            if ($missedFlags2[$_mt] ?? false) $missedForDone[] = $_mt;
        }
        ?>
        <?php if (!empty($missedForDone)): ?>
        <div class="step-card" id="main-step-card">
            <?php foreach ($missedForDone as $_mt): ?>
                <?= missedNoticeHtml($_mt, $pending_requests, $lrWindowStatuses) ?>
            <?php endforeach; ?>
        </div>
        <?php else: ?>
        <div class="done-card" id="main-step-card">
            <span class="done-icon"><i class="fas fa-circle-check"></i></span>
            <h3>All attendance recorded!</h3>
            <p>You've completed all sign-in and sign-out for today.</p>
        </div>
        <?php endif; ?>

        <?php else: ?>
        <?php
        // Show missed notices for non-active, non-skipped missed steps
        $missedStepsToShow = [];
        foreach (['am_time_in','am_time_out','pm_time_in','pm_time_out'] as $_mt) {
            $skippedFlags3 = [
                'am_time_in'  => $am_skipped,
                'am_time_out' => $am_skipped,
                'pm_time_in'  => $pm_skipped,
                'pm_time_out' => $pm_skipped,
            ];
            if ($skippedFlags3[$_mt]) continue;
            $missedFlags2 = ['am_time_in'=>$am_in_missed,'am_time_out'=>$am_out_missed,'pm_time_in'=>$pm_in_missed,'pm_time_out'=>$pm_out_missed];
            if (($_mt !== $active_step) && ($missedFlags2[$_mt] ?? false)) {
                $missedStepsToShow[] = $_mt;
            }
        }
        $btn_meta = [
            'am_time_in'  => ['AM Sign In',   'am-in'],
            'am_time_out' => ['AM Sign Out',  'am-out'],
            'pm_time_in'  => ['PM Sign In',   'pm-in'],
            'pm_time_out' => ['PM Sign Out',  'pm-out'],
        ];
        [$btn_label, $btn_cls] = $btn_meta[$active_step];
        ?>
        <div class="step-card" id="main-step-card">
            <div class="step-label">Next action</div>
            <button id="main-action-btn"
                    class="action-btn <?= $btn_cls ?>"
                    onclick="capture('<?= $active_step ?>')">
                <span class="btn-text"><?= $btn_label ?></span>
                <div class="btn-spinner"></div>
            </button>
            <?php foreach ($missedStepsToShow as $_mt): ?>
                <?= missedNoticeHtml($_mt, $pending_requests, $lrWindowStatuses) ?>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>

        <!-- Skipped duty notices (informational) -->
        <?php if (!$is_weekend && $todaySettings): ?>
            <?php if ($am_skipped): ?>
            <div class="skipped-duty-notice">
                <span class="sdn-icon"><i class="fas fa-circle-info"></i></span>
                <div class="sdn-text">
                    <h4>AM Duty Not Required</h4>
                    <p>Your company has not scheduled AM duty attendance for today. Only PM duty is required.</p>
                </div>
            </div>
            <?php endif; ?>
            <?php if ($pm_skipped): ?>
            <div class="skipped-duty-notice">
                <span class="sdn-icon"><i class="fas fa-circle-info"></i></span>
                <div class="sdn-text">
                    <h4>PM Duty Not Required</h4>
                    <p>Your company has not scheduled PM duty attendance for today. Only AM duty is required.</p>
                </div>
            </div>
            <?php endif; ?>
        <?php endif; ?>

        <!-- Progress tracker -->
        <div class="progress-track">
            <div class="progress-track-title">Today's progress</div>
            <div class="track-steps" id="track-steps">
                <?php
                $steps = [
                    ['am_time_in',  'AM\nIn'],
                    ['am_time_out', 'AM\nOut'],
                    ['pm_time_in',  'PM\nIn'],
                    ['pm_time_out', 'PM\nOut'],
                ];
                $done_flags   = ['am_time_in'=>$am_in_done,'am_time_out'=>$am_out_done,'pm_time_in'=>$pm_in_done,'pm_time_out'=>$pm_out_done];
                $missed_flags = ['am_time_in'=>$am_in_missed,'am_time_out'=>$am_out_missed,'pm_time_in'=>$pm_in_missed,'pm_time_out'=>$pm_out_missed];
                $skipped_map  = ['am_time_in'=>$am_skipped,'am_time_out'=>$am_skipped,'pm_time_in'=>$pm_skipped,'pm_time_out'=>$pm_skipped];
                foreach ($steps as $i => [$key, $lbl]):
                    $is_done       = $done_flags[$key];
                    $is_missed     = $missed_flags[$key];
                    $is_step_skip  = $skipped_map[$key];
                    $is_pending_late = isset($pending_requests[$key]) && $pending_requests[$key] === 'pending';
                    $is_perm_missed  = $is_missed && !$is_pending_late && ($lrWindowStatuses[$key] === 'permanently_missed');
                    $is_active = ($active_step === $key);

                    if ($is_step_skip) {
                        $step_cls='skipped-step'; $circ_cls='skipped'; $circ_txt='—';
                    } elseif ($is_done) {
                        $step_cls='done-step'; $circ_cls='done'; $circ_txt='<i class="fas fa-check"></i>';
                    } elseif ($is_missed && $is_pending_late) {
                        $step_cls='pending-late-step'; $circ_cls='pending-late'; $circ_txt='<i class="fas fa-hourglass-half"></i>';
                    } elseif ($is_perm_missed) {
                        $step_cls='perm-missed-step'; $circ_cls='perm-missed'; $circ_txt='<i class="fas fa-xmark"></i>';
                    } elseif ($is_missed) {
                        $step_cls='missed-step'; $circ_cls='missed'; $circ_txt='!';
                    } elseif ($is_active) {
                        $step_cls='active-step'; $circ_cls='active'; $circ_txt=($i+1);
                    } else {
                        $step_cls=''; $circ_cls='todo'; $circ_txt=($i+1);
                    }
                    $pendingBadge = ($is_pending_late) ? '<div class="lr-pending-badge"><i class="fas fa-hourglass-half"></i> Pending</div>' : '';
                    $skipLabel    = $is_step_skip ? '<div style="font-size:9px;color:#cbd5e0;margin-top:2px;">Skipped</div>' : '';
                ?>
                <div class="track-step <?= $step_cls ?>" id="track-<?= $key ?>">
                    <div class="step-circle <?= $circ_cls ?>"><?= $circ_txt ?></div>
                    <div class="track-step-label"><?= nl2br($lbl) ?></div>
                    <?= $pendingBadge ?>
                    <?= $skipLabel ?>
                </div>
                <?php endforeach; ?>
            </div>
        </div>

        <!-- Today's log -->
        <div class="log-card" id="attendance-log">
            <?php render_log_html($attendance, $pending_requests, $am_skipped, $pm_skipped); ?>
        </div>
    </div>
</div>

<div id="toast"></div>

<?php
function render_log_html($att, $pending_requests = [], $am_skipped = false, $pm_skipped = false) {
    $fmtOrMissed = function($v) {
        if (!$v) return null;
        if ($v === 'missed') return 'MISSED';
        if (strpos($v,' ')!==false) $v = explode(' ',$v)[1];
        $p = explode(':',$v); $h=(int)$p[0]; $m=(int)$p[1];
        return sprintf('%d:%02d %s', $h%12?:12, $m, $h>=12?'PM':'AM');
    };
    $fields = [
        'AM' => [
            'in'  => ['label'=>'Sign In',  'key'=>'am_time_in',  'time'=>$fmtOrMissed($att['am_time_in']??null),  'photo'=>$att['am_time_in_photo']??null,  'raw'=>$att['am_time_in']??null],
            'out' => ['label'=>'Sign Out', 'key'=>'am_time_out', 'time'=>$fmtOrMissed($att['am_time_out']??null), 'photo'=>$att['am_time_out_photo']??null, 'raw'=>$att['am_time_out']??null],
            'skipped' => $am_skipped,
        ],
        'PM' => [
            'in'  => ['label'=>'Sign In',  'key'=>'pm_time_in',  'time'=>$fmtOrMissed($att['pm_time_in']??null),  'photo'=>$att['pm_time_in_photo']??null,  'raw'=>$att['pm_time_in']??null],
            'out' => ['label'=>'Sign Out', 'key'=>'pm_time_out', 'time'=>$fmtOrMissed($att['pm_time_out']??null), 'photo'=>$att['pm_time_out_photo']??null, 'raw'=>$att['pm_time_out']??null],
            'skipped' => $pm_skipped,
        ],
    ];
    echo '<div class="log-card-title">Today\'s log</div>';
    foreach ($fields as $period => $items) {
        $icon = $period === 'AM' ? '' : '';
        $isSkipped = $items['skipped'];
        echo "<div class=\"log-duty\">";
        echo "<div class=\"log-duty-label\">{$icon} {$period} Duty</div>";
        if ($isSkipped) {
            echo "<div class=\"log-duty-skipped\"><i class=\"fas fa-minus-circle\"></i> {$period} duty not required by your company today</div>";
        } else {
            echo "<div class=\"log-rows\">";
            foreach (['in','out'] as $slot) {
                $item = $items[$slot];
                $isMiss  = ($item['raw'] === 'missed');
                $isPendingLate     = isset($pending_requests[$item['key']]) && $pending_requests[$item['key']] === 'pending';
                $hasPendingLateRequest = isset($pending_requests[$item['key']]) &&
                                         in_array($pending_requests[$item['key']], ['pending', 'approved']);
                echo "<div class=\"log-item\">";
                if ($item['photo'] && !$isMiss) {
                    echo "<img src=\"data:image/png;base64,".base64_encode($item['photo'])."\" alt=\"photo\">";
                }
                echo "<div class=\"log-item-info\">";
                echo "<div class=\"li-label\">{$item['label']}</div>";
                $t = $item['time'];
                $cls = '';
                $display = $t ?? '—';
                if ($isMiss && $isPendingLate) {
                    $cls = ' pending';
                    $display = 'MISSED (pending)';
                } elseif ($isMiss) {
                    $cls = ' missed';
                } elseif ($hasPendingLateRequest && !$t) {
                    $cls = ' pending';
                    $lrStatus = $pending_requests[$item['key']];
                    $display = ($lrStatus === 'approved') ? 'MISSED (approved)' : 'MISSED (pending)';
                } elseif (!$t) {
                    $cls = ' empty';
                }
                echo "<div class=\"li-time{$cls}\">" . htmlspecialchars($display) . "</div>";
                echo "</div></div>";
            }
            echo "</div>";
        }
        echo "</div>";
    }
}
?>

<script>
/* ══════════════════════════════════════════════════════════════
   PHP DATA to JS
══════════════════════════════════════════════════════════════ */
const ACTIVE_STEP_INIT   = <?= json_encode($active_step) ?>;
const IS_WEEKEND         = <?= $is_weekend ? 'true' : 'false' ?>;
const PENDING_REQUESTS   = <?= json_encode($pending_requests) ?>;
const ATT_BADGE_INFO     = <?= json_encode($attendance_badge_info) ?>;
const ANB_IS_ALL_DONE    = <?= json_encode((bool)$_att_all_done) ?>;
const TODAY_SETTINGS     = <?= json_encode($todaySettings ? [
    'am_time_in_start'  => $todaySettings['am_time_in_start'],
    'am_time_in_end'    => $todaySettings['am_time_in_end'],
    'am_time_out_start' => $todaySettings['am_time_out_start'],
    'am_time_out_end'   => $todaySettings['am_time_out_end'],
    'pm_time_in_start'  => $todaySettings['pm_time_in_start'],
    'pm_time_in_end'    => $todaySettings['pm_time_in_end'],
    'pm_time_out_start' => $todaySettings['pm_time_out_start'],
    'pm_time_out_end'   => $todaySettings['pm_time_out_end'],
] : null) ?>;

/* ── Skip flags from server ── */
const AM_SKIPPED = <?= json_encode($am_skipped) ?>;
const PM_SKIPPED = <?= json_encode($pm_skipped) ?>;

const INIT_MISSED = <?= json_encode([
    'am_time_in'  => $am_in_missed,
    'am_time_out' => $am_out_missed,
    'pm_time_in'  => $pm_in_missed,
    'pm_time_out' => $pm_out_missed,
]) ?>;
const INIT_DONE = <?= json_encode([
    'am_time_in'  => $am_in_done,
    'am_time_out' => $am_out_done,
    'pm_time_in'  => $pm_in_done,
    'pm_time_out' => $pm_out_done,
]) ?>;
const INIT_LR_WINDOW_STATUSES = <?= json_encode($lrWindowStatuses) ?>;

const TYPE_LABELS = {
    am_time_in:  'AM Sign In',
    am_time_out: 'AM Sign Out',
    pm_time_in:  'PM Sign In',
    pm_time_out: 'PM Sign Out',
};
const BTN_META = {
    am_time_in:  { label: 'AM Sign In',  cls: 'am-in'  },
    am_time_out: { label: 'AM Sign Out', cls: 'am-out' },
    pm_time_in:  { label: 'PM Sign In',  cls: 'pm-in'  },
    pm_time_out: { label: 'PM Sign Out', cls: 'pm-out' },
};
const ORDER = ['am_time_in','am_time_out','pm_time_in','pm_time_out'];

// Active order respects skip flags
const ACTIVE_ORDER = ORDER.filter(t => {
    if (t === 'am_time_in'  || t === 'am_time_out') return !AM_SKIPPED;
    if (t === 'pm_time_in'  || t === 'pm_time_out') return !PM_SKIPPED;
    return true;
});

const NEXT_OPEN_KEY = {
    am_time_in:  'am_time_out_start',
    am_time_out: 'pm_time_in_start',
    pm_time_in:  'pm_time_out_start',
    pm_time_out: null,
};

let activeStep = ACTIVE_STEP_INIT;
let lateReqType = null;
let lrWindowCheckInterval = null;

/* ── Sidebar toggle ── */
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
    document.getElementById('att-notif-bar')
            .classList.toggle('sidebar-collapsed', sidebar.classList.contains('collapsed'));
});

/* ── Small screens: start with the sidebar collapsed (reuses the toggle above) ── */
if (window.matchMedia && window.matchMedia('(max-width: 768px)').matches) {
    toggleBtn.click();
}

/* ── Journal empty-section badge ── */
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

/* ── live clock ── */
function tickClock() {
    const now = new Date();
    const days = ['Sun','Mon','Tue','Wed','Thu','Fri','Sat'];
    const mons = ['Jan','Feb','Mar','Apr','May','Jun','Jul','Aug','Sep','Oct','Nov','Dec'];
    let h = now.getHours(), m = now.getMinutes(), ampm = h>=12?'PM':'AM';
    h = h%12||12;
    document.getElementById('live-date').textContent =
        `${days[now.getDay()]}, ${mons[now.getMonth()]} ${now.getDate()} · ${h}:${String(m).padStart(2,'0')} ${ampm}`;
}
tickClock(); setInterval(tickClock, 10000);

/* ── camera ── */
let cameraStream = null;
navigator.mediaDevices.getUserMedia({video:{facingMode:'user'}})
    .then(s => {
        cameraStream = s;
        document.getElementById('video').srcObject = s;
        const lrVid = document.getElementById('lr-video-preview');
        if (lrVid) lrVid.srcObject = s;
    })
    .catch(() => showToast('Camera access denied.', 'error'));

/* ── toast ── */
let toastTimer;
function showToast(msg, type='') {
    const text = String(msg == null ? '' : msg).trim();
    if (!text) return;
    try {
        const kind  = (type === 'success' || type === 'error' || type === 'warning') ? type : 'info';
        const icons = { success: 'fa-circle-check', error: 'fa-circle-exclamation', warning: 'fa-triangle-exclamation', info: 'fa-circle-info' };

        /* The same message already on screen is refreshed, not stacked again. */
        const dupe = Array.from(document.querySelectorAll('.cv-top-toast')).find(el => el.dataset.msg === text);
        if (dupe) { clearTimeout(dupe._t); dupe._t = setTimeout(() => cvHideTopToast(dupe), 4200); return; }

        const div = document.createElement('div');
        div.className = 'cv-top-toast' + (kind === 'error' ? ' is-error' : kind === 'warning' ? ' is-warning' : '');
        div.dataset.msg = text;
        div.setAttribute('role', kind === 'error' ? 'alert' : 'status');
        const icon = document.createElement('i');
        icon.className = 'fas ' + icons[kind];
        const span = document.createElement('span');
        span.textContent = text;            /* textContent: server messages are never parsed as HTML */
        div.appendChild(icon);
        div.appendChild(span);
        document.body.appendChild(div);
        cvLayoutTopToasts();
        requestAnimationFrame(() => div.classList.add('show'));
        div._t = setTimeout(() => cvHideTopToast(div), 4200);
    } catch (e) {
        /* Fallback: the original bottom toast. */
        const t = document.getElementById('toast');
        if (!t) return;
        t.textContent = text;
        t.className = 'show ' + type;
        clearTimeout(toastTimer);
        toastTimer = setTimeout(() => { t.className = ''; }, 4200);
    }
}
function cvHideTopToast(div) {
    if (!div || !div.parentNode) return;
    div.classList.remove('show');
    setTimeout(() => { if (div.parentNode) div.parentNode.removeChild(div); cvLayoutTopToasts(); }, 400);
}
function cvLayoutTopToasts() {
    let top = 30;
    document.querySelectorAll('.cv-top-toast').forEach(el => {
        el.style.top = top + 'px';
        top += el.offsetHeight + 12;
    });
}

/* ── time helpers ── */
function timeStrToSec(t) {
    if (!t) return -1;
    const p = t.split(':');
    return parseInt(p[0])*3600 + parseInt(p[1])*60 + (p[2] ? parseInt(p[2]) : 0);
}
function nowSec() {
    const n = new Date();
    return n.getHours()*3600 + n.getMinutes()*60 + n.getSeconds();
}
function fmt12(timeStr) {
    if (!timeStr) return null;
    const p = timeStr.split(':');
    let h = parseInt(p[0]), m = parseInt(p[1]);
    const ampm = h >= 12 ? 'PM' : 'AM';
    h = h % 12 || 12;
    return `${h}:${String(m).padStart(2,'0')} ${ampm}`;
}

// Check if a type belongs to a skipped duty period
function isTypeSkipped(type) {
    if (type === 'am_time_in' || type === 'am_time_out') return AM_SKIPPED;
    if (type === 'pm_time_in' || type === 'pm_time_out') return PM_SKIPPED;
    return false;
}

function getLateRequestWindowStatus(type) {
    if (!TODAY_SETTINGS) return 'permanently_missed';
    if (isTypeSkipped(type)) return 'permanently_missed';
    const endSec = timeStrToSec(TODAY_SETTINGS[type + '_end']);
    const now    = nowSec();
    if (endSec >= 0 && now <= endSec) return 'not_yet';
    if (type === 'pm_time_out') {
        const pmOutEndSec = timeStrToSec(TODAY_SETTINGS['pm_time_out_end']);
        if (pmOutEndSec >= 0) {
            const lateWindowEndSec = pmOutEndSec + 3600;
            if (now <= lateWindowEndSec) return 'open';
        }
        return 'permanently_missed';
    }
    const nextKey     = NEXT_OPEN_KEY[type];
    const nextOpenSec = nextKey ? timeStrToSec(TODAY_SETTINGS[nextKey]) : -1;
    if (nextOpenSec >= 0 && now >= nextOpenSec) return 'permanently_missed';
    if (nextKey === null) return 'permanently_missed';
    return 'open';
}

function windowIsClosed(type) {
    if (!TODAY_SETTINGS) return false;
    const endSec = timeStrToSec(TODAY_SETTINGS[type + '_end']);
    if (endSec < 0) return false;
    return nowSec() > endSec;
}

/* ── capture ── */
function capture(type) {
    if (IS_WEEKEND) { showToast('Today is a weekend. No attendance required.','error'); return; }
    if (isTypeSkipped(type)) {
        showToast('This duty period is not required for your company today.', 'warning');
        return;
    }
    if (windowIsClosed(type)) {
        showToast('The time window has closed. Submit a late request instead.', 'warning');
        openLateReqModal(type);
        return;
    }
    const canvas = document.getElementById('canvas');
    const video  = document.getElementById('video');
    const btn    = document.getElementById('main-action-btn');
    canvas.width  = video.videoWidth  || 640;
    canvas.height = video.videoHeight || 480;
    canvas.getContext('2d').drawImage(video, 0, 0);
    const imageData = canvas.toDataURL('image/png');
    if (btn) btn.classList.add('loading');
    const fd = new FormData();
    fd.append('image', imageData);
    fd.append('type',  type);
    fd.append('token', document.getElementById('csrf-token').value);
    fetch(window.location.href, {
        method: 'POST',
        headers: {'X-Requested-With':'XMLHttpRequest'},
        body: fd,
    })
    .then(r => r.json())
    .then(data => {
        if (btn) btn.classList.remove('loading');
        if (data.token) document.getElementById('csrf-token').value = data.token;
        if (data.success) {
            showToast(data.message, 'success');
            markStepDone(type);
            refreshLog();
        } else {
            showToast(data.message || 'Something went wrong.', 'error');
        }
    })
    .catch(() => {
        if (btn) btn.classList.remove('loading');
        showToast('Network error. Please try again.', 'error');
    });
}

/* ── Central function to mark a step done and advance ── */
function markStepDone(type) {
    if (isTypeSkipped(type)) return; // never mark a skipped step
    const trackEl = document.getElementById('track-' + type);
    if (trackEl) {
        trackEl.className = 'track-step done-step';
        const circ = trackEl.querySelector('.step-circle');
        if (circ) { circ.className = 'step-circle done'; circ.innerHTML = '<i class="fas fa-check"></i>'; }
        const badge = trackEl.querySelector('.lr-pending-badge');
        if (badge) badge.remove();
    }
    advanceActiveStep();
}

/* ── Central function to mark a step missed and advance ── */
function markStepMissed(type, isPermanent, isPending) {
    if (isTypeSkipped(type)) return; // never mark a skipped step missed
    const trackEl = document.getElementById('track-' + type);
    if (trackEl) {
        if (isPending) {
            trackEl.className = 'track-step pending-late-step';
            const c = trackEl.querySelector('.step-circle');
            if (c) { c.className = 'step-circle pending-late'; c.innerHTML = '<i class="fas fa-hourglass-half"></i>'; }
            if (!trackEl.querySelector('.lr-pending-badge')) {
                const badge = document.createElement('div');
                badge.className = 'lr-pending-badge';
                badge.innerHTML = '<i class="fas fa-hourglass-half"></i> Pending';
                trackEl.appendChild(badge);
            }
        } else if (isPermanent) {
            trackEl.className = 'track-step perm-missed-step';
            const c = trackEl.querySelector('.step-circle');
            if (c) { c.className = 'step-circle perm-missed'; c.innerHTML = '<i class="fas fa-xmark"></i>'; }
            const badge = trackEl.querySelector('.lr-pending-badge');
            if (badge) badge.remove();
        } else {
            trackEl.className = 'track-step missed-step';
            const c = trackEl.querySelector('.step-circle');
            if (c) { c.className = 'step-circle missed'; c.textContent = '!'; }
            const badge = trackEl.querySelector('.lr-pending-badge');
            if (badge) badge.remove();
        }
    }
    advanceActiveStep();
}

function advanceActiveStep() {
    let nextActive = null;

    for (const type of ACTIVE_ORDER) { // uses filtered order, respects skips
        const el = document.getElementById('track-' + type);
        if (!el) continue;
        if (el.classList.contains('done-step'))         continue;
        if (el.classList.contains('perm-missed-step'))  continue;
        if (el.classList.contains('pending-late-step')) continue;
        if (el.classList.contains('missed-step'))       continue;
        if (el.classList.contains('skipped-step'))      continue;
        nextActive = type;
        break;
    }

    activeStep = nextActive || 'done';

    const stepCard = document.getElementById('main-step-card');
    if (!stepCard) return;

    // Only show missed notices for non-skipped steps
    const openMissed = ACTIVE_ORDER.filter(type => {
        const el = document.getElementById('track-' + type);
        return el && el.classList.contains('missed-step') && !isTypeSkipped(type);
    });

    if (activeStep === 'done') {
        if (openMissed.length > 0) {
            let html = '';
            openMissed.forEach(type => { html += buildMissedNoticeHtml(type); });
            stepCard.innerHTML = html;
            stepCard.className = 'step-card';
        } else {
            const alreadyDone = stepCard.classList.contains('done-card-inner');
            if (!alreadyDone) {
                stepCard.className = 'done-card';
                stepCard.innerHTML = `
                    <span class="done-icon"><i class="fas fa-circle-check"></i></span>
                    <h3>All attendance recorded!</h3>
                    <p>You've completed all sign-in and sign-out for today.</p>`;
            }
        }
    } else {
        const meta = BTN_META[activeStep];
        let html = `
        <div class="step-label">Next action</div>
        <button id="main-action-btn" class="action-btn ${meta.cls}" onclick="capture('${activeStep}')">
            <span class="btn-text">${meta.label}</span>
            <div class="btn-spinner"></div>
        </button>`;
        openMissed.forEach(type => { html += buildMissedNoticeHtml(type); });
        stepCard.innerHTML = html;
        stepCard.className = 'step-card';
    }
}

function buildMissedNoticeHtml(type, winStatusOverride) {
    if (isTypeSkipped(type)) return ''; // don't render missed notice for skipped types
    const label     = TYPE_LABELS[type];
    const status    = PENDING_REQUESTS[type] || null;
    const winStatus = (winStatusOverride !== undefined) ? winStatusOverride : getLateRequestWindowStatus(type);
    let actionHtml  = '';
    if (status === 'pending') {
        actionHtml = `<div class="late-req-pending"> Request pending — waiting for company approval</div>`;
    } else if (status === 'approved') {
        actionHtml = `<div class="late-req-pending" style="background:#f0fff4;border-color:#EAF3EA;color:#2C5A2C;"> Request approved</div>`;
    } else if (winStatus === 'open') {
        actionHtml = `<button class="late-req-btn" onclick="openLateReqModal('${type}')"> Submit Late Request</button>`;
    } else {
        actionHtml = `<div class="late-req-expired"> Late request window has closed. This entry is permanently missed.</div>`;
    }
    const bodyMsg = winStatus === 'open'
        ? 'The time window has closed. You can still submit a late request before the next action opens.'
        : (status === 'pending' || status === 'approved'
            ? 'The time window has closed and your ' + label + ' was not recorded.'
            : 'The time window has closed and the late request window has also passed.');
    return `
    <div class="missed-notice">
        <div class="mn-header"> Missed: ${label}</div>
        <div class="mn-body">${bodyMsg}</div>
        ${actionHtml}
    </div>`;
}

/* ── refresh log panel + sync tracker from server ── */
function refreshLog() {
    fetch(window.location.href + '?action=get_status', {
        headers: {'X-Requested-With':'XMLHttpRequest'}, cache: 'no-store',
    })
    .then(r => r.json())
    .then(data => {
        if (!data.success) return;
        if (data.token) document.getElementById('csrf-token').value = data.token;
        const att = data.attendance;
        const lr  = data.late_requests || [];

        if (att) {
            const rawMap = {
                am_time_in:  att.raw_am_in,
                am_time_out: att.raw_am_out,
                pm_time_in:  att.raw_pm_in,
                pm_time_out: att.raw_pm_out,
            };

            ACTIVE_ORDER.forEach(type => { // only iterate non-skipped types
                const raw     = rawMap[type];
                const trackEl = document.getElementById('track-' + type);
                if (!trackEl) return;

                const alreadyHandled = ['done-step','missed-step','perm-missed-step','pending-late-step']
                    .some(cls => trackEl.classList.contains(cls));

                const isDone = (raw !== null && raw !== '' && raw !== 'missed');
                if (isDone && !trackEl.classList.contains('done-step')) {
                    markStepDone(type);
                    return;
                }

                if (raw === 'missed' && !alreadyHandled) {
                    const winStatus   = data.late_request_windows ? data.late_request_windows[type] : 'permanently_missed';
                    const isPending   = lr.find(r => r.type === type && r.status === 'pending');
                    const isPermanent = (winStatus === 'permanently_missed');
                    markStepMissed(type, isPermanent, !!isPending);
                    PENDING_REQUESTS[type] = isPending ? 'pending' : (PENDING_REQUESTS[type] || null);
                }
            });
        }

        if (att) {
            const isMissedVal = v => (v === 'MISSED');
            function logItem(photob64, label, time12, typeKey) {
                if (isTypeSkipped(typeKey)) {
                    return `<div class="log-item"><div class="log-item-info"><div class="li-label">${label}</div><div class="li-time skipped">Not required</div></div></div>`;
                }
                const hasPhoto = (photob64 && !isMissedVal(time12))
                    ? `<img src="data:image/png;base64,${photob64}" style="width:44px;height:44px;border-radius:0;object-fit:cover;flex-shrink:0;">`
                    : '';
                let timeCls = '';
                let displayTime = time12 || '—';
                const lrEntry       = lr.find(r => r.type === typeKey);
                const lrStatus      = lrEntry ? lrEntry.status : null;
                const isPendingLate = (lrStatus === 'pending');
                const isApprovedLate = (lrStatus === 'approved');

                if (isMissedVal(time12) && isPendingLate)       { timeCls = ' pending'; displayTime = 'MISSED (pending)'; }
                else if (isMissedVal(time12) && isApprovedLate) { timeCls = ' pending'; displayTime = 'MISSED (approved)'; }
                else if (isMissedVal(time12))                   { timeCls = ' missed'; }
                else if (!time12 && isPendingLate)              { timeCls = ' pending'; displayTime = 'MISSED (pending)'; }
                else if (!time12 && isApprovedLate)             { timeCls = ' pending'; displayTime = 'MISSED (approved)'; }
                else if (!time12)                               { timeCls = ' empty'; }

                return `<div class="log-item">${hasPhoto}
                    <div class="log-item-info">
                        <div class="li-label">${label}</div>
                        <div class="li-time${timeCls}">${displayTime}</div>
                    </div></div>`;
            }

            function buildDutySection(period, inKey, outKey, inTime, inPhoto, outTime, outPhoto) {
                const isSkipped = period === 'AM' ? AM_SKIPPED : PM_SKIPPED;
                const icon = period === 'AM' ? '' : '';
                if (isSkipped) {
                    return `<div class="log-duty">
                        <div class="log-duty-label">${icon} ${period} Duty</div>
                        <div class="log-duty-skipped"><i class="fas fa-minus-circle"></i> ${period} duty not required by your company today</div>
                    </div>`;
                }
                return `<div class="log-duty">
                    <div class="log-duty-label">${icon} ${period} Duty</div>
                    <div class="log-rows">
                        ${logItem(inPhoto,  'Sign In',  inTime,  inKey)}
                        ${logItem(outPhoto, 'Sign Out', outTime, outKey)}
                    </div>
                </div>`;
            }

            document.getElementById('attendance-log').innerHTML = `
            <div class="log-card-title">Today's log</div>
            ${buildDutySection('AM','am_time_in','am_time_out', att.am_time_in, att.am_time_in_photo_b64, att.am_time_out, att.am_time_out_photo_b64)}
            ${buildDutySection('PM','pm_time_in','pm_time_out', att.pm_time_in, att.pm_time_in_photo_b64, att.pm_time_out, att.pm_time_out_photo_b64)}`;
        }
    })
    .catch(() => {});
}

setInterval(refreshLog, 15000);

/* ══ Auto-miss interval — only for non-skipped types ══ */
const _autoMissTriggered = {};

setInterval(() => {
    if (IS_WEEKEND || activeStep === 'weekend') return;
    if (!TODAY_SETTINGS) return;

    const endKeys = {
        am_time_in:  'am_time_in_end',
        am_time_out: 'am_time_out_end',
        pm_time_in:  'pm_time_in_end',
        pm_time_out: 'pm_time_out_end',
    };

    const closedTypes = [];
    for (const type of ACTIVE_ORDER) { // only check non-skipped types
        if (_autoMissTriggered[type]) continue;

        const trackEl = document.getElementById('track-' + type);
        if (!trackEl) continue;
        if (trackEl.classList.contains('done-step'))         continue;
        if (trackEl.classList.contains('perm-missed-step'))  continue;
        if (trackEl.classList.contains('pending-late-step')) continue;
        if (trackEl.classList.contains('missed-step'))       continue;
        if (trackEl.classList.contains('skipped-step'))      continue;

        const endStr = TODAY_SETTINGS[endKeys[type]];
        if (!endStr) continue;
        const endSec = timeStrToSec(endStr);
        if (endSec < 0) continue;

        if (type === 'pm_time_out') {
            const lateWindowEndSec = endSec + 3600;
            if (nowSec() <= lateWindowEndSec) continue;
        } else {
            if (nowSec() <= endSec) continue;
        }

        closedTypes.push(type);
    }

    if (closedTypes.length === 0) return;

    closedTypes.forEach(t => { _autoMissTriggered[t] = true; });

    fetch(window.location.href + '?action=trigger_auto_miss', {
        headers: {'X-Requested-With': 'XMLHttpRequest'},
        cache: 'no-store',
    })
    .then(r => r.json())
    .then(data => {
        if (!data.success) {
            closedTypes.forEach(t => { delete _autoMissTriggered[t]; });
            return;
        }
        const serverLrWindows = data.late_request_windows || {};
        closedTypes.forEach(type => {
            const winStatus   = serverLrWindows[type] || 'permanently_missed';
            const isPermanent = (winStatus === 'permanently_missed');
            const isPending   = !!(PENDING_REQUESTS[type] === 'pending');
            markStepMissed(type, isPermanent, isPending);
            updateLogItemMissed(type);
        });
        advanceActiveStep();
    })
    .catch(() => {
        closedTypes.forEach(t => { delete _autoMissTriggered[t]; });
        closedTypes.forEach(type => {
            const winStatus   = getLateRequestWindowStatus(type);
            const isPermanent = (winStatus === 'permanently_missed');
            markStepMissed(type, isPermanent, false);
            updateLogItemMissed(type);
        });
        advanceActiveStep();
    });
}, 5000);

/* ── PM Sign Out late-window seeder (only if PM not skipped) ── */
setInterval(() => {
    if (IS_WEEKEND || !TODAY_SETTINGS || PM_SKIPPED) return;
    const pmOutEndStr = TODAY_SETTINGS['pm_time_out_end'];
    if (!pmOutEndStr) return;

    const pmOutEndSec   = timeStrToSec(pmOutEndStr);
    const lateWindowEnd = pmOutEndSec + 3600;
    const ns            = nowSec();

    if (ns <= pmOutEndSec || ns > lateWindowEnd) return;

    const trackEl = document.getElementById('track-pm_time_out');
    if (!trackEl) return;

    if (trackEl.classList.contains('done-step'))         return;
    if (trackEl.classList.contains('perm-missed-step'))  return;
    if (trackEl.classList.contains('pending-late-step')) return;
    if (trackEl.classList.contains('skipped-step'))      return;

    if (!trackEl.classList.contains('missed-step')) {
        trackEl.className = 'track-step missed-step';
        const c = trackEl.querySelector('.step-circle');
        if (c) { c.className = 'step-circle missed'; c.textContent = '!'; }
        const badge = trackEl.querySelector('.lr-pending-badge');
        if (badge) badge.remove();
        advanceActiveStep();
        updateLogItemMissed('pm_time_out');
    }
}, 5000);

function updateLogItemMissed(type) {
    if (isTypeSkipped(type)) return; // don't update skipped types
    const logCard = document.getElementById('attendance-log');
    if (!logCard) return;
    const logItems = logCard.querySelectorAll('.log-item');
    const idx = ACTIVE_ORDER.indexOf(type);
    if (idx < 0 || idx >= logItems.length) return;
    const timeEl = logItems[idx].querySelector('.li-time');
    if (!timeEl) return;
    const currentText = timeEl.textContent.trim();
    if (currentText !== '—' && !currentText.startsWith('MISSED')) return;
    timeEl.className = 'li-time missed';
    timeEl.textContent = 'MISSED';
}

function updateLogItemPending(type) {
    if (isTypeSkipped(type)) return;
    const logCard = document.getElementById('attendance-log');
    if (!logCard) return;
    const logItems = logCard.querySelectorAll('.log-item');
    const idx = ACTIVE_ORDER.indexOf(type);
    if (idx < 0 || idx >= logItems.length) return;
    const timeEl = logItems[idx].querySelector('.li-time');
    if (!timeEl) return;
    const currentText = timeEl.textContent.trim();
    const isAlreadyRecorded = (currentText !== '—' && !currentText.startsWith('MISSED'));
    if (isAlreadyRecorded) return;
    timeEl.className = 'li-time pending';
    timeEl.textContent = 'MISSED (pending)';
}

/* ══ LATE REQUEST MODAL ══ */
function openLateReqModal(type) {
    if (isTypeSkipped(type)) {
        showToast('This duty period is not required for your company.', 'warning');
        return;
    }
    const winStatus = getLateRequestWindowStatus(type);
    if (winStatus === 'not_yet') {
        showToast('The late request is not available yet. Submit after the window closes.', 'warning');
        return;
    }
    if (winStatus === 'permanently_missed') {
        showToast('The late request window has closed. This entry is permanently missed.', 'error');
        return;
    }

    lateReqType = type;
    document.getElementById('lr-type-display').textContent = TYPE_LABELS[type] || type;
    document.getElementById('lr-reason').value = '';

    const warnEl = document.getElementById('lr-window-warn');
    const dlEl   = document.getElementById('lr-deadline-time');
    if (type === 'pm_time_out' && TODAY_SETTINGS && TODAY_SETTINGS['pm_time_out_end']) {
        const pmEndSec    = timeStrToSec(TODAY_SETTINGS['pm_time_out_end']);
        const deadlineSec = pmEndSec + 3600;
        const deadlineH   = Math.floor(deadlineSec / 3600);
        const deadlineM   = Math.floor((deadlineSec % 3600) / 60);
        const ampm = deadlineH >= 12 ? 'PM' : 'AM';
        const h12  = deadlineH % 12 || 12;
        dlEl.textContent = `${h12}:${String(deadlineM).padStart(2,'0')} ${ampm}`;
        warnEl.style.display = 'flex';
    } else {
        const nextKey = NEXT_OPEN_KEY[type];
        if (nextKey && TODAY_SETTINGS && TODAY_SETTINGS[nextKey]) {
            dlEl.textContent = fmt12(TODAY_SETTINGS[nextKey]);
            warnEl.style.display = 'flex';
        } else {
            warnEl.style.display = 'none';
        }
    }

    const lrVid = document.getElementById('lr-video-preview');
    if (lrVid && cameraStream) lrVid.srcObject = cameraStream;

    document.getElementById('lateReqOverlay').classList.add('open');

    if (lrWindowCheckInterval) clearInterval(lrWindowCheckInterval);
    lrWindowCheckInterval = setInterval(() => {
        const currentStatus = getLateRequestWindowStatus(lateReqType);
        if (currentStatus === 'permanently_missed') {
            closeLateReqModal();
            showToast('The late request window has just closed. This entry is permanently missed.', 'error');
            const trackEl = document.getElementById('track-' + lateReqType);
            if (trackEl && !trackEl.classList.contains('pending-late-step')) {
                markStepMissed(lateReqType, true, false);
            }
        }
    }, 10000);
}

function closeLateReqModal() {
    document.getElementById('lateReqOverlay').classList.remove('open');
    lateReqType = null;
    if (lrWindowCheckInterval) { clearInterval(lrWindowCheckInterval); lrWindowCheckInterval = null; }
}
document.getElementById('lateReqOverlay').addEventListener('click', function(e){ if(e.target===this) closeLateReqModal(); });

function submitLateRequest() {
    if (!lateReqType) return;
    if (isTypeSkipped(lateReqType)) {
        showToast('This duty period is not required for your company.', 'warning');
        closeLateReqModal();
        return;
    }
    const winStatus = getLateRequestWindowStatus(lateReqType);
    if (winStatus !== 'open') {
        showToast('The late request window has closed. This entry is permanently missed.', 'error');
        closeLateReqModal();
        return;
    }
    const reason = document.getElementById('lr-reason').value.trim();
    if (!reason) { showToast('Please enter a reason.', 'warning'); return; }

    const canvas  = document.getElementById('canvas');
    const lrVid   = document.getElementById('lr-video-preview');
    const srcVid  = (lrVid && lrVid.videoWidth > 0) ? lrVid : document.getElementById('video');
    canvas.width  = srcVid.videoWidth  || 640;
    canvas.height = srcVid.videoHeight || 480;
    canvas.getContext('2d').drawImage(srcVid, 0, 0);
    const imageData = canvas.toDataURL('image/png');

    const btn = document.getElementById('lr-submit-btn');
    btn.classList.add('loading');
    btn.textContent = 'Submitting…';

    const fd = new FormData();
    fd.append('action', 'submit_late_request');
    fd.append('type',   lateReqType);
    fd.append('reason', reason);
    fd.append('image',  imageData);

    const submittedType = lateReqType;
    fetch(window.location.href, {
        method: 'POST',
        headers: {'X-Requested-With':'XMLHttpRequest'},
        body: fd,
    })
    .then(r => r.json())
    .then(data => {
        btn.classList.remove('loading');
        btn.textContent = ' Submit Request';
        closeLateReqModal();

        if (data.success) {
            showToast(' Late request submitted. Awaiting approval.', 'success');
            PENDING_REQUESTS[submittedType] = 'pending';
            markStepMissed(submittedType, false, true);
            updateLogItemPending(submittedType);
            refreshLog();
        } else {
            showToast(data.message || 'Failed to submit.', 'error');
        }
    })
    .catch(() => {
        btn.classList.remove('loading');
        btn.textContent = ' Submit Request';
        showToast('Network error. Please try again.', 'error');
    });
}

/* ── history drawer ── */
let historyLoaded = false;
function openHistoryDrawer() {
    document.getElementById('history-drawer').classList.add('open');
    document.getElementById('history-overlay').classList.add('open');
    document.body.style.overflow = 'hidden';
    if (!historyLoaded) fetchHistory();
}
function closeHistoryDrawer() {
    document.getElementById('history-drawer').classList.remove('open');
    document.getElementById('history-overlay').classList.remove('open');
    document.body.style.overflow = '';
}
document.addEventListener('keydown', e => { if (e.key==='Escape') { closeHistoryDrawer(); closeLateReqModal(); } });

function fetchHistory() {
    const body = document.getElementById('history-body');
    body.innerHTML = `<div class="drawer-state"><div class="drawer-spinner"></div><span>Loading history…</span></div>`;
    fetch(window.location.href + '?action=get_history', {
        headers: {'X-Requested-With':'XMLHttpRequest'}, cache: 'no-store',
    })
    .then(r => r.json())
    .then(data => {
        if (!data.success || !Object.keys(data.history).length) {
            body.innerHTML = `<div class="drawer-state"><span><i class="fas fa-inbox"></i></span><span>No entries yet.</span></div>`;
            return;
        }
        let html = '';
        Object.entries(data.history).forEach(([month, entries]) => {
            html += `<div><div class="hist-month-header">${month}</div>`;
            entries.forEach(e => {
                const dc = e.is_weekend ? ' is-dayoff' : '';
                html += `<div class="hist-day${dc}">
                    <div class="hist-day-label">${e.day_label}</div>`;
                if (e.is_weekend) {
                    html += `<span class="dayoff-chip"><i class="fas fa-mug-hot"></i> Day Off</span>`;
                } else {
                    const fmt = (t) => {
                        if (!t) return `<span class="hist-time missing">—</span>`;
                        if (t === 'MISSED') return `<span class="hist-time missed">MISSED</span>`;
                        return `<span class="hist-time">${t}</span>`;
                    };
                    html += `<div class="hist-duty-row">
                        <div class="hist-duty-block am"><strong> AM</strong>In: ${fmt(e.am_time_in_12)}<br>Out: ${fmt(e.am_time_out_12)}</div>
                        <div class="hist-duty-block pm"><strong> PM</strong>In: ${fmt(e.pm_time_in_12)}<br>Out: ${fmt(e.pm_time_out_12)}</div>
                    </div>`;
                }
                html += `</div>`;
            });
            html += `</div>`;
        });
        body.innerHTML = html;
        historyLoaded = true;
    })
    .catch(() => {
        body.innerHTML = `<div class="drawer-state"><span></span><span>Failed to load history.</span></div>`;
    });
}

/* ══════════════════════════════════════════════════════════════
   ATTENDANCE NOTIFICATION BAR — v3
══════════════════════════════════════════════════════════════ */

const ANB_BADGE_INFO     = ATT_BADGE_INFO;
const ANB_TODAY_SETTINGS = TODAY_SETTINGS;
const ANB_IS_WEEKEND     = IS_WEEKEND;
const ANB_ORDER          = ACTIVE_ORDER; // respects skip flags

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

function _anbHide(type) {
    if (type) _anb.dismissedWindows.add(type);
    _anb.currentType = '';

    if (_anb.tickInterval  !== null) { clearInterval(_anb.tickInterval);  _anb.tickInterval  = null; }
    if (_anb.autoHideTimer !== null) { clearTimeout(_anb.autoHideTimer);  _anb.autoHideTimer = null; }
    if (_anb.rafId         !== null) { cancelAnimationFrame(_anb.rafId);  _anb.rafId         = null; }

    document.getElementById('att-notif-bar').classList.remove('anb-visible');

    const prog = document.getElementById('anb-progress');
    if (prog) {
        prog.style.transition = 'none';
        prog.style.width = '0%';
        requestAnimationFrame(() => { prog.style.transition = ''; });
    }
}

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

    const anbActionBtn = document.getElementById('anb-action-btn');
    if (info.is_late_window) {
        anbActionBtn.textContent = 'Request now';
        anbActionBtn.onclick = function() { _anbHide(_anb.currentType); openLateReqModal('pm_time_out'); };
    } else {
        anbActionBtn.textContent = 'Sign now';
        anbActionBtn.onclick = function() { scrollToActionBtn(); };
    }

    document.getElementById('att-notif-bar')
            .classList.toggle('sidebar-collapsed', sidebar.classList.contains('collapsed'));

    if (_anb.tickInterval  !== null) { clearInterval(_anb.tickInterval);  _anb.tickInterval  = null; }
    if (_anb.autoHideTimer !== null) { clearTimeout(_anb.autoHideTimer);  _anb.autoHideTimer = null; }
    if (_anb.rafId         !== null) { cancelAnimationFrame(_anb.rafId);  _anb.rafId         = null; }

    const prog = document.getElementById('anb-progress');
    if (prog) {
        prog.style.transition = 'none';
        prog.style.width = '100%';
        void prog.offsetWidth;
    }
    const duration = _anb.autoHideDuration;
    const startTs  = _anb.showStartTs;

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
            m + 'm ' + String(s).padStart(2,'0') + 's left';
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

document.getElementById('anb-close-btn').addEventListener('click', function(e) {
    e.stopPropagation();
    _anbHide(_anb.currentType);
});

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

    for (const type of ANB_ORDER) { // only check non-skipped types
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
            label:      def.label,
            start_fmt:  _anbFmt12(startStr),
            end_fmt:    _anbFmt12(endStr),
            start_time: startStr,
            end_time:   endStr,
        });
        return;
    }

    // PM late window (only if PM not skipped)
    if (_anbPmLateShown || PM_SKIPPED) return;
    const pmOutEndStr = ANB_TODAY_SETTINGS['pm_time_out_end'];
    if (!pmOutEndStr) return;
    const pmOutEndSec   = _anbTimeToSec(pmOutEndStr);
    const lateWindowEnd = pmOutEndSec + 3600;
    if (ns <= pmOutEndSec || ns > lateWindowEnd) return;

    const trackEl = document.getElementById('track-pm_time_out');
    if (!trackEl) return;
    if (trackEl.classList.contains('done-step'))         return;
    if (trackEl.classList.contains('pending-late-step')) return;
    if (trackEl.classList.contains('perm-missed-step'))  return;
    if (trackEl.classList.contains('skipped-step'))      return;

    const lateWindowEndH   = Math.floor(lateWindowEnd / 3600);
    const lateWindowEndM   = Math.floor((lateWindowEnd % 3600) / 60);
    const lateWindowEndStr = `${String(lateWindowEndH).padStart(2,'0')}:${String(lateWindowEndM).padStart(2,'0')}:00`;

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

(function() {
    const dow = new Date().getDay();
    if (ANB_BADGE_INFO && !ANB_IS_WEEKEND && dow !== 0 && dow !== 6 && !ANB_IS_ALL_DONE) {
        setTimeout(function() { _anbShow(ANB_BADGE_INFO); }, 800);
    }
})();

setInterval(_anbWatch, 30000);

function scrollToActionBtn() {
    const btn = document.getElementById('main-action-btn');
    if (btn) {
        btn.scrollIntoView({ behavior: 'smooth', block: 'center' });
        const origShadow = btn.style.boxShadow;
        btn.style.boxShadow = '0 0 0 4px rgba(217,119,6,0.45)';
        setTimeout(() => { btn.style.boxShadow = origShadow; }, 2000);
    }
}

/* ══ DOM-READY RECONCILIATION ══ */
document.addEventListener('DOMContentLoaded', () => {
    if (IS_WEEKEND) return;

    ORDER.forEach(type => {
        // Mark skipped steps visually
        if (isTypeSkipped(type)) {
            const trackEl = document.getElementById('track-' + type);
            if (trackEl && !trackEl.classList.contains('skipped-step')) {
                trackEl.className = 'track-step skipped-step';
                const c = trackEl.querySelector('.step-circle');
                if (c) { c.className = 'step-circle skipped'; c.textContent = '—'; }
            }
            return;
        }

        if (INIT_MISSED[type]) {
            const winStatus   = INIT_LR_WINDOW_STATUSES[type] || 'permanently_missed';
            const isPending   = !!(PENDING_REQUESTS[type] === 'pending' || PENDING_REQUESTS[type] === 'approved');
            const isPermanent = (winStatus === 'permanently_missed');
            const trackEl = document.getElementById('track-' + type);
            if (trackEl) {
                const alreadyCorrect =
                    (isPending   && trackEl.classList.contains('pending-late-step')) ||
                    (isPermanent && trackEl.classList.contains('perm-missed-step'))  ||
                    (!isPending && !isPermanent && trackEl.classList.contains('missed-step'));
                if (!alreadyCorrect) {
                    if (isPending) {
                        trackEl.className = 'track-step pending-late-step';
                        const c = trackEl.querySelector('.step-circle');
                        if (c) { c.className = 'step-circle pending-late'; c.innerHTML = '<i class="fas fa-hourglass-half"></i>'; }
                    } else if (isPermanent) {
                        trackEl.className = 'track-step perm-missed-step';
                        const c = trackEl.querySelector('.step-circle');
                        if (c) { c.className = 'step-circle perm-missed'; c.innerHTML = '<i class="fas fa-xmark"></i>'; }
                    } else {
                        trackEl.className = 'track-step missed-step';
                        const c = trackEl.querySelector('.step-circle');
                        if (c) { c.className = 'step-circle missed'; c.textContent = '!'; }
                    }
                }
            }
        }
    });

    advanceActiveStep();
});

/* ══ DEBUG HELPER ══ */
function debugAutoMiss() {
    console.log('[DEBUG] Fetching debug_auto_miss...');
    fetch(window.location.href + '?action=debug_auto_miss', { cache: 'no-store' })
        .then(r => r.json())
        .then(d => {
            console.group('[DEBUG] Auto-Miss Diagnostic Report');
            console.log('Timestamp:',      d.timestamp);
            console.log('Date:',           d.date);
            console.log('Current time:',   d.current_time);
            console.log('Now (seconds):',  d.now_seconds, 'to', d.now_hhmm);
            console.log('Is weekend:',     d.is_weekend);
            console.log('AM Skipped:',     d.am_skipped);
            console.log('PM Skipped:',     d.pm_skipped);
            console.log('Settings found:', d.settings_found);
            if (d.settings) {
                console.group('Settings');
                Object.entries(d.settings).forEach(([k,v]) => console.log(k + ':', v));
                console.groupEnd();
            }
            if (d.windows) {
                console.group('Window Analysis');
                Object.entries(d.windows).forEach(([type, w]) => {
                    const status = w.now_past_end ? 'PAST END' : 'still open';
                    console.log(type + ':', status, '| end:', w.end_time, '| lr_status:', w.lr_window_status);
                    if (w.late_window_end_hhmm) console.log('  └ pm_time_out late window ends:', w.late_window_end_hhmm, '| past:', w.now_past_late_window);
                });
                console.groupEnd();
            }
            console.log('Log BEFORE autoMarkMissed:', d.attendance_log_before);
            console.log('autoMarkMissed returned:', d.autoMarkMissed_returned);
            console.log('Log AFTER autoMarkMissed:', d.attendance_log_after);
            console.log('Diff:', d.diff);
            console.log('MySQL error:', d.mysqli_error_after_automark || 'none');
            console.group('Diagnosis');
            (d.diagnosis || []).forEach(msg => console.log(msg));
            console.groupEnd();
            console.groupEnd();
        })
        .catch(err => console.error('[DEBUG] Failed:', err));
}
</script>
</body>
</html>