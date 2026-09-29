<?php
/**
 * student_report.php — Weekly OJT Report
 * REDESIGNED: Option 3 — Vertical Timeline
 * All PHP logic is 100% preserved. Only CSS and HTML structure (below navbar) changed.
 * FIX: Attendance notification bar now correctly auto-hides after 10s and responds to close button.
 *
 * ADDED: CS Form 48 Daily Time Record generation (DTR builder integration).
 *   — ?dtr=1&month=YYYY-MM  : view DTR in iframe (AJAX)
 *   — ?dtrraw=1&month=YYYY-MM : render raw DTR HTML
 *   — ?dtrdl=1&month=YYYY-MM  : download DTR as HTML file
 *
 * UPDATED: DTR preview modal auto-loads when month is selected from dropdown.
 *          Preview and Download buttons removed from DTR modal.
 *
 * CHANGES:
 *   - Removed "View DTR" button from report history cards.
 *   - Removed all debug/diagnostics code (endpoint, panel, toolbar link).
 *   - REMOVED: Download button and download functionality from report history.
 *
 * FIXED (latest):
 *   - Attendance notification bar only shown Mon–Fri (both PHP + JS weekend guard).
 *   - Close button now correctly dismisses the bar and prevents re-appearance.
 *   - Auto-hide countdown (10 s) now properly dismisses the bar and prevents re-appearance.
 *   - Progress bar reaching zero also fully dismisses (adds type to dismissed set).
 *   - BUGFIX: Rewrote ANB JS to use a single global _anbState object; eliminated
 *     stale-closure and pointer-events issues; close button reads type at click time.
 *   - BUGFIX v2: Fixed bar "stacking at top" after dismiss — hidden state now uses
 *     visibility:hidden + opacity:0 instead of relying solely on translateY which
 *     was being overridden. Bar is fully removed from paint/interaction when hidden.
 *   - CHANGE: Sidebar title now shows student's full name (incl. middle name) with "OJT Trainee" label.
 *   - FIX: Middle name now fetched from users table and displayed in sidebar.
 *   - FIX: PM duty late request window notification now shows in ANB (ported from student_attendance.php).
 *   - FIX: ANB timer UI no longer overlaps the notification content — layout synced with student_profile.php.
 *   - FIX: Sidebar design matches student_attendance.php exactly.
 *   - ANB CSS/HTML synced exactly with student_profile.php (anb-content row + anb-text-group + anb-divider + anb-countdown).
 *
 * EMAIL UPDATE (MOVED):
 *   - Friday reminder email has been moved out of this file.
 *   - It is now handled entirely by send_friday_reminders.php (cron job).
 *   - Email template used: Option 2 — "Letter from Your Coordinator"
 *     warm, mentor-style personal HTML email with NEUST branding, serif typography,
 *     cream/ivory aesthetic, step-by-step how-to, motivational quote, and gold CTA.
 *
 * HISTORY PANEL UPDATE:
 *   - Report history now opens as a centered popup modal with split-pane layout
 *     (list on left, detail/grade view on right) instead of a slide-in drawer.
 *   - All existing history data, view-report, grading, and feedback logic preserved.
 *
 * VIEWER MODAL UPDATE:
 *   - Report viewer now uses iframe-mode (single scrollbar) matching company_reports.php.
 *   - No double-scrollbar; iframe fills the viewer box with flex layout.
 *
 * HISTORY SUMMARY CARDS UPDATE (this revision):
 *   - The Report History summary strip previously showed "Total Submitted /
 *     With Feedback / Needs Revision" counts. It now mirrors the weekly
 *     report compliance cards already used on company_reports.php's Student
 *     Library ("Total" expected / "Submitted" / "Not Submitted"), computed
 *     via the new computeWeeklyReportStats() helper below (same definition
 *     as the company-side version: Total = one report expected per week
 *     from the start of OJT — the company's earliest attendance_settings
 *     date — through today, Submitted = reports actually submitted
 *     excluding ones marked "Wrong Document", Missed = Total - Submitted
 *     floored at 0). Kept in sync on the 30s poll refresh as well.
 *   - The report detail view (right pane of Report History) no longer shows
 *     the "Pending Review"/status badge, and no longer repeats the week's
 *     date range as a heading above the preview — that date is already
 *     shown inside the report content itself, so showing it twice was
 *     redundant.
 *
 * STATUS LABEL REMOVAL (this revision):
 *   - The Report History LIST pane (left column) previously showed a
 *     colored status badge per report ("Pending Review", "Wrong Document",
 *     "Lack of Details", "Inaccurate", "Good") next to the week range and
 *     submission date. These labels have now been removed entirely from
 *     both the initial PHP-rendered list and the JS rebuildHistoryPanel()
 *     re-render (used by the 30s poll refresh), matching the detail pane
 *     which already dropped this badge in a previous revision. The
 *     underlying `remark` data itself is untouched and continues to power
 *     all other logic (resubmission gating, "Wrong Document" rejected
 *     message, feedback drawer, etc.) — only the visible status label in
 *     the history list was removed.
 *
 * REPORT PREVIEW ACTIONS UPDATE:
 *   - The Print / Save as PDF buttons that used to render INSIDE the
 *     report preview iframe (from weekly_report_form_builder.php's own
 *     toolbar) have been moved OUT of the iframe and into the Report
 *     History detail pane header, directly under/around the "Submitted
 *     <date>" line, centered. They now call the iframe's own
 *     window.print() / savePDF() functions from the parent page via
 *     histTriggerReportPrint()/histTriggerReportPDF(). The in-iframe
 *     toolbar itself is now hidden at the source (see
 *     weekly_report_form_builder.php's .wkr-toolbar rule) so it no
 *     longer appears twice. The existing top "Print" button in the
 *     hist-doc-toolbar (histPrintReport) is untouched and still works
 *     as before.
 *
 * TOOLBAR CONSOLIDATION UPDATE (this revision):
 *   - The extra "Print" button that used to sit in the top Report
 *     History toolbar (hist-doc-toolbar-right, next to "Feedback") has
 *     been removed entirely, since Print already exists per-report in
 *     the detail pane next to "Save as PDF". Its handler
 *     (histPrintReport()) and its now-unused CSS were removed with it.
 *   - The "Feedback" button has been moved out of the top toolbar and
 *     down into the per-report detail actions row, so it now sits
 *     aligned beside "Print" and "Save as PDF" directly under the
 *     "Submitted <date>" line. It still calls the same
 *     toggleFeedbackDrawer() function and still highlights
 *     (has-feedback style) when the report has coordinator feedback.
 *     The top toolbar now only shows "Close".
 *
 * TRAINING STATION FIELD UPDATE (this revision):
 *   - weekly_report_form_builder.php already had first-class support for
 *     a "Training Station" value in its info table (its own field,
 *     separate from Company), but nothing on this page ever collected or
 *     sent that value — so it always rendered blank ("—").
 *   - Added a new "Training Station" text input to the weekly report form
 *     (inside the <form>, above the day-by-day timeline) so the student
 *     can type it in before submitting. On submit, the value is read from
 *     $_POST['training_station'] and passed through to
 *     buildWeeklyReportHTML() as 'training_station', alongside the
 *     existing fields — no other field, key, or behavior in that call was
 *     changed.
 *   - The new input follows the same disabled/read-only rules as the day
 *     textareas (disabled once the week's report is already submitted),
 *     and participates in the existing local-storage draft save/load/
 *     autosave and "unsaved changes" tracking exactly like the day
 *     entries do, so nothing about the draft/unsaved-changes behavior for
 *     the rest of the form changed.
 */

ob_start();
date_default_timezone_set("Asia/Manila");
ini_set('display_errors', 0);
ini_set('log_errors', 1);
ini_set('error_log', __DIR__ . '/ojt_debug.log');
error_reporting(E_ALL);

session_start();
include "db.php";

$_builder_path = __DIR__ . '/weekly_report_form_builder.php';
if (!file_exists($_builder_path)) {
    while (ob_get_level() > 0) { ob_end_clean(); }
    http_response_code(500);
    echo '<!DOCTYPE html><html><head><meta charset="UTF-8"><title>Setup Error</title>'
       . '<style>body{font-family:sans-serif;background:#fef2f2;display:flex;align-items:center;'
       . 'justify-content:center;min-height:100vh;margin:0;}'
       . '.box{background:white;border-radius:14px;padding:36px 40px;max-width:520px;'
       . 'box-shadow:0 4px 24px rgba(0,0,0,.12);text-align:center;}'
       . 'h2{color:#dc2626;margin-bottom:12px;}p{color:#374151;line-height:1.6;font-size:.95rem;}'
       . 'code{background:#f3f4f6;padding:2px 8px;border-radius:5px;font-size:.88rem;}'
       . '</style></head><body><div class="box">'
       . '<h2>&#x26A0; Setup Error</h2>'
       . '<p>The file <code>weekly_report_form_builder.php</code> is missing from the server.</p>'
       . '<p>Please copy <code>weekly_report_form_builder.php</code> to:<br>'
       . '<code>' . htmlspecialchars(__DIR__) . '</code></p>'
       . '</div></body></html>';
    exit;
}
require_once $_builder_path;

/* ── Load CS Form 48 builder ── */
$_cs48_builder_path = __DIR__ . '/cs_form48_builder.php';
if (file_exists($_cs48_builder_path)) {
    require_once $_cs48_builder_path;
}

set_exception_handler(function(Throwable $e) {
    $isAjax = (
        (isset($_GET['ajax']) && $_GET['ajax'] == '1')
        || (isset($_GET['poll']) && $_GET['poll'] == '1')
        || (isset($_GET['view']) && $_GET['view'] == '1')
        || (isset($_GET['dtr'])  && $_GET['dtr']  == '1')
        || (!empty($_SERVER['HTTP_X_REQUESTED_WITH']) &&
            strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest')
    );
    $msg = '[' . get_class($e) . '] ' . $e->getMessage() . ' in ' . basename($e->getFile()) . ':' . $e->getLine();
    error_log('[OJT UNCAUGHT] ' . $msg . "\n" . $e->getTraceAsString());
    if ($isAjax) {
        while (ob_get_level() > 0) { $chunk = ob_get_clean(); if ($chunk) error_log('[OJT leaked] ' . substr($chunk,0,500)); }
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['success' => false, 'message' => 'Server error: ' . $e->getMessage(), '_exception_class' => get_class($e), '_exception_file' => basename($e->getFile()) . ':' . $e->getLine(), '_exception_trace' => substr($e->getTraceAsString(), 0, 2000)]);
        exit;
    }
    while (ob_get_level() > 0) { ob_end_clean(); }
    http_response_code(500);
    echo '<!DOCTYPE html><html><head><meta charset="UTF-8"><title>Server Error</title></head><body><h2>Server Error</h2><p>' . htmlspecialchars($e->getMessage()) . '</p></body></html>';
    exit;
});

register_shutdown_function(function() {
    $err = error_get_last();
    if (!$err || !in_array($err['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR])) return;
    $isAjax = ((isset($_GET['ajax']) && $_GET['ajax'] == '1') || (isset($_GET['poll']) && $_GET['poll'] == '1') || (!empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest'));
    $msg = 'PHP Fatal [' . $err['type'] . ']: ' . $err['message'] . ' in ' . basename($err['file']) . ':' . $err['line'];
    error_log('[OJT FATAL SHUTDOWN] ' . $msg);
    if ($isAjax) { while (ob_get_level() > 0) { ob_end_clean(); } header('Content-Type: application/json; charset=utf-8'); echo json_encode(['success' => false, 'message' => 'Fatal server error: ' . $err['message']]); return; }
    while (ob_get_level() > 0) { ob_end_clean(); }
    http_response_code(500);
    echo '<!DOCTYPE html><html><head><meta charset="UTF-8"><title>Fatal Error</title></head><body><h2>Fatal Error</h2><p>' . htmlspecialchars($msg) . '</p></body></html>';
});


// 1. Keep your working file paths here
require 'PHPMailer/src/Exception.php';
require 'PHPMailer/src/PHPMailer.php';
require 'PHPMailer/src/SMTP.php';
require 'vendor/autoload.php';

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

if (!isset($_SESSION['user_id']) || $_SESSION['role'] != "student") {
    while (ob_get_level() > 0) { ob_end_clean(); }
    die("Access denied.");
}

$user_id = $_SESSION['user_id'];

/* ============================================================ HELPER FUNCTIONS ============================================================ */
function fmtTime12($t) {
    if (!$t) return '-';
    $p = explode(':', $t); $h = (int)$p[0]; $m = (int)$p[1];
    $ampm = $h >= 12 ? 'PM' : 'AM'; $h12 = $h % 12 ?: 12;
    return sprintf('%d:%02d %s', $h12, $m, $ampm);
}
function hasRealValue($v) { return ($v !== null && $v !== '' && $v !== 'missed'); }
function sendJson(array $payload): void {
    $leaked = '';
    while (ob_get_level() > 0) { $chunk = ob_get_clean(); if ($chunk !== false) $leaked .= $chunk; }
    if ($leaked !== '') { $payload['_debug_leaked_output'] = substr($leaked, 0, 3000); error_log('[OJT sendJson] Leaked output: ' . substr($leaked, 0, 1000)); }
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($payload);
    exit;
}
function calcHoursFromLog($in_str, $out_str) {
    if (!hasRealValue($in_str) || !hasRealValue($out_str)) return 0;
    $strip = function($t) { return (strpos($t, ' ') !== false) ? explode(' ', $t)[1] : $t; };
    $diff = strtotime($strip($out_str)) - strtotime($strip($in_str));
    return $diff > 0 ? round($diff / 3600, 2) : 0;
}
function fetchStudentInfoFields($conn, $user_id) {
    $result = ['course' => '', 'coordinator_name' => ''];
    $u_stmt = $conn->prepare("SELECT course FROM users WHERE id = ? LIMIT 1");
    if ($u_stmt) { $u_stmt->bind_param("i", $user_id); $u_stmt->execute(); $u_row = $u_stmt->get_result()->fetch_assoc(); $u_stmt->close(); if ($u_row) $result['course'] = trim($u_row['course'] ?? ''); }
    $si_stmt = $conn->prepare("SELECT ojt_coordinator_first, ojt_coordinator_middle, ojt_coordinator_last FROM student_information WHERE user_id = ? LIMIT 1");
    if (!$si_stmt) return $result;
    $si_stmt->bind_param("i", $user_id); $si_stmt->execute(); $si_row = $si_stmt->get_result()->fetch_assoc(); $si_stmt->close();
    if (!$si_row) return $result;
    $coord_parts = array_filter([trim($si_row['ojt_coordinator_first'] ?? ''), trim($si_row['ojt_coordinator_middle'] ?? ''), trim($si_row['ojt_coordinator_last'] ?? '')], fn($p) => $p !== '');
    $result['coordinator_name'] = implode(' ', $coord_parts);
    return $result;
}
function fetchCompanyFields($conn, $user_id) {
    $result = ['company_name' => '', 'supervisor_name' => ''];
    $co_stmt = $conn->prepare("SELECT u.first_name AS account_first, u.last_name AS account_last, ci.company AS ci_company, ci.contact_first_name, ci.contact_middle_initial, ci.contact_last_name, cp.telephone FROM ojt_assignments oa INNER JOIN users u ON u.id = oa.company_id LEFT JOIN company_information ci ON ci.user_id = u.id LEFT JOIN company_profile cp ON cp.user_id = u.id WHERE oa.student_id = ? LIMIT 1");
    if (!$co_stmt) return $result;
    $co_stmt->bind_param("i", $user_id); $co_stmt->execute(); $co_row = $co_stmt->get_result()->fetch_assoc(); $co_stmt->close();
    if (!$co_row) return $result;
    $ci_company = trim($co_row['ci_company'] ?? '');
    $result['company_name'] = $ci_company !== '' ? $ci_company : trim($co_row['account_first'] . ' ' . $co_row['account_last']);
    $result['supervisor_name'] = trim($co_row['contact_first_name'] . (!empty($co_row['contact_middle_initial']) ? ' ' . $co_row['contact_middle_initial'] . '.' : '') . ' ' . $co_row['contact_last_name']);
    return $result;
}

/* ============================================================
   NEW: WEEKLY REPORT COMPLIANCE COUNTS (Total / Submitted / Missed)
   ------------------------------------------------------------
   Used to power the three summary cards now shown at the top of the
   Report History overlay ("Total", "Submitted", "Not Submitted") —
   mirrors the identical helper/definition already used by
   company_reports.php's Student Library (computeWeeklyReportStats()
   there), so the two views agree with each other.

   Definitions:
     - Total     = number of weekly reports that SHOULD have been
                   submitted so far, counting one report per week
                   from the start of OJT (the company's earliest
                   attendance_settings date) through today's date,
                   inclusive of the current week.
     - Submitted = number of reports the student has actually
                   submitted, excluding rows marked
                   remark = 'Wrong Document' (a rejected submission
                   awaiting resubmission isn't counted as submitted).
     - Missed    = Total - Submitted, floored at 0 so a student who
                   has submitted extra/duplicate reports for the same
                   week never shows a negative "missed" count.

   The OJT start date is normalized to the Monday of its own week
   before counting, since weekly reports are tracked against a
   Monday-start week — this keeps the week count consistent
   regardless of which weekday OJT actually began on.
   ============================================================ */
function computeWeeklyReportStats(string $ojt_start_date, int $submitted_count): array {
    $stats = [
        'total_expected' => 0,
        'submitted'      => $submitted_count,
        'missed'         => 0,
    ];

    if (empty($ojt_start_date)) {
        return $stats;
    }

    $start_ts = strtotime($ojt_start_date);
    $today_ts = strtotime(date('Y-m-d'));
    if ($start_ts === false || $today_ts < $start_ts) {
        return $stats;
    }

    // Normalize to the Monday of the OJT start date's own week.
    $start_dow    = (int)date('N', $start_ts); // 1 (Mon) .. 7 (Sun)
    $week_start_ts = strtotime('-' . ($start_dow - 1) . ' days', $start_ts);

    $days_elapsed = (int)floor(($today_ts - $week_start_ts) / 86400);
    $total_expected = (int)floor($days_elapsed / 7) + 1;

    $stats['total_expected'] = max(0, $total_expected);
    $stats['missed']         = max(0, $stats['total_expected'] - $submitted_count);

    return $stats;
}

/* ============================================================
   CS FORM 48 HELPER — builds $d array from attendance_logs
   ============================================================ */
function buildDTRData($conn, $user_id, $year, $month_num) {
    $stu_stmt = $conn->prepare("SELECT first_name, middle_name, last_name FROM users WHERE id = ? LIMIT 1");
    $stu_stmt->bind_param("i", $user_id); $stu_stmt->execute();
    $stu = $stu_stmt->get_result()->fetch_assoc(); $stu_stmt->close();
    if ($stu) {
        $name_parts = array_filter([
            trim($stu['first_name'] ?? ''),
            trim($stu['middle_name'] ?? ''),
            trim($stu['last_name'] ?? '')
        ], fn($p) => $p !== '');
        $employee_name = implode(' ', $name_parts);
    } else {
        $employee_name = '';
    }

    /* Official hours from attendance_settings */
    $ca_stmt = $conn->prepare("SELECT company_id FROM ojt_assignments WHERE student_id=? LIMIT 1");
    $ca_stmt->bind_param("i", $user_id); $ca_stmt->execute();
    $ca_row = $ca_stmt->get_result()->fetch_assoc(); $ca_stmt->close();
    $official_hours = '';
    if ($ca_row) {
        $company_id = (int)$ca_row['company_id'];
        $hs_stmt = $conn->prepare(
            "SELECT am_time_in_start, pm_time_out_end FROM attendance_settings " .
            "WHERE company_id=? AND YEAR(date)=? AND MONTH(date)=? ORDER BY date ASC LIMIT 1"
        );
        $hs_stmt->bind_param("iii", $company_id, $year, $month_num); $hs_stmt->execute();
        $hs_row = $hs_stmt->get_result()->fetch_assoc(); $hs_stmt->close();
        if ($hs_row && $hs_row['am_time_in_start']) {
            $start = fmtTime12($hs_row['am_time_in_start']);
            $end   = $hs_row['pm_time_out_end'] ? fmtTime12($hs_row['pm_time_out_end']) : '';
            $official_hours = $start . ($end ? '-' . $end : '');
        }
    }

    /* Attendance logs for the month */
    $month_start = sprintf('%04d-%02d-01', $year, $month_num);
    $days_in_month = cal_days_in_month(CAL_GREGORIAN, $month_num, $year);
    $month_end = sprintf('%04d-%02d-%02d', $year, $month_num, $days_in_month);

    $al_stmt = $conn->prepare(
        "SELECT date, am_time_in, am_time_out, pm_time_in, pm_time_out " .
        "FROM attendance_logs WHERE user_id=? AND date BETWEEN ? AND ? ORDER BY date ASC"
    );
    $al_stmt->bind_param("iss", $user_id, $month_start, $month_end); $al_stmt->execute();
    $al_res = $al_stmt->get_result();
    $att_map = [];
    while ($ar = $al_res->fetch_assoc()) {
        $att_map[$ar['date']] = $ar;
    }
    $al_stmt->close();

    /* Build per-day entries */
    $days = [];
    for ($d = 1; $d <= $days_in_month; $d++) {
        $date_str = sprintf('%04d-%02d-%02d', $year, $month_num, $d);
        $dow = (int)date('w', strtotime($date_str)); // 0=Sun, 6=Sat
        if ($dow === 0) { $days[$d] = ['remark' => 'SUNDAY'];  continue; }
        if ($dow === 6) { $days[$d] = ['remark' => 'SATURDAY']; continue; }

        $log = $att_map[$date_str] ?? null;
        if (!$log) {
            $days[$d] = ['am_in' => '', 'am_out' => '', 'pm_in' => '', 'pm_out' => '', 'remark' => ''];
            continue;
        }

        /* Format times — strip date prefix if datetime stored */
        $fmt = function($t) {
            if (!$t || $t === 'missed') return '';
            if (strpos($t, ' ') !== false) $t = explode(' ', $t)[1];
            $p = explode(':', $t); $h = (int)$p[0]; $m = (int)$p[1];
            return sprintf('%02d:%02d', $h, $m);
        };

        $days[$d] = [
            'am_in'  => $fmt($log['am_time_in']),
            'am_out' => $fmt($log['am_time_out']),
            'pm_in'  => $fmt($log['pm_time_in']),
            'pm_out' => $fmt($log['pm_time_out']),
            'remark' => '',
        ];
    }

    return [
        'employee_name'  => $employee_name,
        'month'          => strtoupper(date('F Y', mktime(0,0,0,$month_num,1,$year))),
        'year'           => $year,
        'month_num'      => $month_num,
        'official_hours' => $official_hours,
        'days'           => $days,
    ];
}

/* ============================================================ ATTENDANCE SIDEBAR BADGE ============================================================ */
$att_sidebar_badge = false; $attendance_badge_info = null; $_att_today_settings = null; $_att_is_weekend = false; $_att_all_done = false;
$_att_dow = (int)date('w'); $_att_is_weekend = ($_att_dow === 0 || $_att_dow === 6);
$_att_ca = $conn->prepare("SELECT company_id FROM ojt_assignments WHERE student_id=? LIMIT 1"); $_att_ca->bind_param("i", $user_id); $_att_ca->execute(); $_att_cr = $_att_ca->get_result()->fetch_assoc(); $_att_ca->close();
if ($_att_cr && !$_att_is_weekend) {
    $_att_company_id = $_att_cr['company_id']; $_att_date = date("Y-m-d"); $_att_now = date("H:i:s");
    $_att_ss = $conn->prepare("SELECT * FROM attendance_settings WHERE company_id=? AND date=? LIMIT 1"); $_att_ss->bind_param("is", $_att_company_id, $_att_date); $_att_ss->execute(); $_att_setting = $_att_ss->get_result()->fetch_assoc(); $_att_ss->close();
    if (!$_att_setting) { $_att_sf = $conn->prepare("SELECT * FROM attendance_settings WHERE company_id=? AND is_auto=1 AND date<=? ORDER BY date DESC LIMIT 1"); $_att_sf->bind_param("is", $_att_company_id, $_att_date); $_att_sf->execute(); $_att_setting = $_att_sf->get_result()->fetch_assoc(); $_att_sf->close(); }
    if ($_att_setting) {
        $_att_today_settings = ['am_time_in_start' => $_att_setting['am_time_in_start'], 'am_time_in_end' => $_att_setting['am_time_in_end'], 'am_time_out_start' => $_att_setting['am_time_out_start'], 'am_time_out_end' => $_att_setting['am_time_out_end'], 'pm_time_in_start' => $_att_setting['pm_time_in_start'], 'pm_time_in_end' => $_att_setting['pm_time_in_end'], 'pm_time_out_start' => $_att_setting['pm_time_out_start'], 'pm_time_out_end' => $_att_setting['pm_time_out_end']];
        $_att_log_s = $conn->prepare("SELECT am_time_in, am_time_out, pm_time_in, pm_time_out FROM attendance_logs WHERE user_id=? AND date=? AND company_id=?"); $_att_log_s->bind_param("isi", $user_id, $_att_date, $_att_company_id); $_att_log_s->execute(); $_att_log = $_att_log_s->get_result()->fetch_assoc(); $_att_log_s->close();
        $fmt12att = function($t) { if (!$t) return null; $parts = explode(':', $t); $h = (int)$parts[0]; $m = (int)$parts[1]; $ampm = $h >= 12 ? 'PM' : 'AM'; $h12 = $h % 12 ?: 12; return sprintf('%d:%02d %s', $h12, $m, $ampm); };
        $timeToSec = function($t) { if (!$t) return -1; $p = explode(':', $t); return (int)$p[0] * 3600 + (int)$p[1] * 60 + (isset($p[2]) ? (int)$p[2] : 0); };
        $_att_now_sec = $timeToSec($_att_now);

        $_att_all_done = ($_att_log && is_array($_att_log) && hasRealValue($_att_log['am_time_in']) && hasRealValue($_att_log['am_time_out']) && hasRealValue($_att_log['pm_time_in']) && hasRealValue($_att_log['pm_time_out']));

        /* Normal time windows */
        $_att_windows = ['am_time_in' => ['label' => 'AM Duty Sign In', 'start' => $_att_setting['am_time_in_start'], 'end' => $_att_setting['am_time_in_end']], 'am_time_out' => ['label' => 'AM Duty Sign Out', 'start' => $_att_setting['am_time_out_start'], 'end' => $_att_setting['am_time_out_end']], 'pm_time_in' => ['label' => 'PM Duty Sign In', 'start' => $_att_setting['pm_time_in_start'], 'end' => $_att_setting['pm_time_in_end']], 'pm_time_out' => ['label' => 'PM Duty Sign Out', 'start' => $_att_setting['pm_time_out_start'], 'end' => $_att_setting['pm_time_out_end']]];
        foreach ($_att_windows as $type => $winfo) {
            if (!$winfo['start'] || !$winfo['end']) continue;
            $start_sec = $timeToSec($winfo['start']); $end_sec = $timeToSec($winfo['end']);
            if ($_att_now_sec >= $start_sec && $_att_now_sec <= $end_sec) {
                $val = (is_array($_att_log) && array_key_exists($type, $_att_log)) ? $_att_log[$type] : null;
                $already_done = ($val !== null && $val !== '' && $val !== 'missed');
                if (!$already_done && !$_att_all_done) { $att_sidebar_badge = true; $attendance_badge_info = ['type' => $type, 'label' => $winfo['label'], 'start_fmt' => $fmt12att($winfo['start']), 'end_fmt' => $fmt12att($winfo['end']), 'start_time' => $winfo['start'], 'end_time' => $winfo['end']]; break; }
            }
        }

        /* PM Sign Out late request window (1 hour after pm_time_out_end) */
        if (!$attendance_badge_info && !$_att_all_done && $_att_setting['pm_time_out_end']) {
            $pmOutEndSec   = $timeToSec($_att_setting['pm_time_out_end']);
            $lateWindowEnd = $pmOutEndSec + 3600;
            if ($_att_now_sec > $pmOutEndSec && $_att_now_sec <= $lateWindowEnd) {
                $pmOutVal    = (is_array($_att_log) && isset($_att_log['pm_time_out'])) ? $_att_log['pm_time_out'] : null;
                $alreadyDone = ($pmOutVal !== null && $pmOutVal !== '' && $pmOutVal !== 'missed');
                if (!$alreadyDone) {
                    $lateWindowEndH   = floor($lateWindowEnd / 3600);
                    $lateWindowEndM   = floor(($lateWindowEnd % 3600) / 60);
                    $lateWindowEndStr = sprintf('%02d:%02d:00', $lateWindowEndH, $lateWindowEndM);
                    $att_sidebar_badge    = true;
                    $attendance_badge_info = [
                        'type'           => 'pm_time_out_late',
                        'label'          => 'PM Sign Out Late Request',
                        'start_fmt'      => $fmt12att($_att_setting['pm_time_out_end']) . ' (missed)',
                        'end_fmt'        => $fmt12att($lateWindowEndStr) . ' (deadline)',
                        'start_time'     => $_att_setting['pm_time_out_end'],
                        'end_time'       => $lateWindowEndStr,
                        'is_late_window' => true,
                    ];
                }
            }
        }
    }
}

/* ============================================================
   CS FORM 48 — VIEW (AJAX) handler
   ?dtr=1&month=YYYY-MM
   ============================================================ */
if (isset($_GET['dtr']) && $_GET['dtr'] == '1') {
    if (!function_exists('buildCSForm48HTML')) { sendJson(['error' => 'cs_form48_builder.php not found.']); }
    $month_param = $_GET['month'] ?? date('Y-m');
    [$y, $m] = explode('-', $month_param . '-01');
    $year      = (int)$y;
    $month_num = (int)$m;
    if ($year < 2000 || $year > 2100 || $month_num < 1 || $month_num > 12) {
        sendJson(['error' => 'Invalid month parameter.']);
    }
    $iframe_src = 'student_report.php?dtrraw=1&month=' . urlencode($month_param);
    $month_label_js = strtoupper(date('F Y', mktime(0,0,0,$month_num,1,$year)));
    sendJson([
        'html'        => '<div style="text-align:center;padding:12px 0 0;"><iframe src="' . htmlspecialchars($iframe_src) . '" style="width:100%;height:640px;border:none;border-radius:8px;background:#fff;" title="DTR ' . htmlspecialchars($month_label_js) . '"></iframe></div>',
        'month_label' => $month_label_js,
        'is_html'     => true,
    ]);
}

/* ============================================================
   CS FORM 48 — RAW HTML render
   ?dtrraw=1&month=YYYY-MM
   ============================================================ */
if (isset($_GET['dtrraw']) && $_GET['dtrraw'] == '1') {
    if (!function_exists('buildCSForm48HTML')) { while (ob_get_level() > 0) { ob_end_clean(); } http_response_code(404); echo '<p>Builder not found.</p>'; exit; }
    $month_param = $_GET['month'] ?? date('Y-m');
    [$y, $m] = explode('-', $month_param . '-01');
    $year      = (int)$y;
    $month_num = (int)$m;
    $dtr_data  = buildDTRData($conn, $user_id, $year, $month_num);
    $html = buildCSForm48HTML($dtr_data);
    while (ob_get_level() > 0) { ob_end_clean(); }
    header('Content-Type: text/html; charset=utf-8');
    echo $html;
    exit;
}

/* ============================================================
   CS FORM 48 — DOWNLOAD handler
   ?dtrdl=1&month=YYYY-MM
   ============================================================ */
if (isset($_GET['dtrdl']) && $_GET['dtrdl'] == '1') {
    if (!function_exists('buildCSForm48HTML')) { while (ob_get_level() > 0) { ob_end_clean(); } die("Builder not found."); }
    $month_param = $_GET['month'] ?? date('Y-m');
    [$y, $m] = explode('-', $month_param . '-01');
    $year      = (int)$y;
    $month_num = (int)$m;
    $dtr_data  = buildDTRData($conn, $user_id, $year, $month_num);
    $html = buildCSForm48HTML($dtr_data);
    $fname = preg_replace('/[\/\\\\:*?"<>|]/', '', ($dtr_data['employee_name'] ?: 'Student') . '_DTR_' . strtoupper(date('F_Y', mktime(0,0,0,$month_num,1,$year))) . '.html');
    while (ob_get_level() > 0) { ob_end_clean(); }
    header('Content-Type: text/html; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $fname . '"');
    header('Content-Length: ' . strlen($html));
    header('Cache-Control: max-age=0');
    echo $html;
    exit;
}

/* ============================================================
   FRIDAY REMINDER EMAIL — REMOVED FROM THIS FILE
   ============================================================ */

/* ============================================================
   DOWNLOAD HANDLER — REMOVED
   ============================================================ */

/* ============================================================ VIEW HANDLER ============================================================ */
if (isset($_GET['view']) && $_GET['view'] == '1') {
    $report_id = (int)($_GET['id'] ?? 0);
    $dl = $conn->prepare("SELECT report_blob, week_start FROM reports WHERE id = ? AND user_id = ?"); $dl->bind_param("ii", $report_id, $user_id); $dl->execute(); $dlrow = $dl->get_result()->fetch_assoc();
    if (!$dlrow || empty($dlrow['report_blob'])) { sendJson(['error' => 'File not found.']); }
    try {
        $blob = $dlrow['report_blob'];
        if (strlen($blob) >= 2 && substr($blob, 0, 2) === 'PK') { sendJson(['html' => '<div style="padding:24px;text-align:center;color:#6b7280;"><p>Legacy XLSX format. Use Download button.</p></div>', 'week' => date("M d, Y", strtotime($dlrow['week_start']))]); }
        $iframe_src = 'student_report.php?viewraw=1&id=' . $report_id;
        sendJson(['html' => $iframe_src, 'week' => date("M d, Y", strtotime($dlrow['week_start'])), 'is_html' => true, 'iframe_src' => $iframe_src]);
    } catch (Throwable $e) { sendJson(['error' => 'Failed to render report: ' . $e->getMessage()]); }
}

/* ============================================================ VIEWRAW HANDLER ============================================================ */
if (isset($_GET['viewraw']) && $_GET['viewraw'] == '1') {
    $report_id = (int)($_GET['id'] ?? 0);
    $dl = $conn->prepare("SELECT report_blob FROM reports WHERE id = ? AND user_id = ?"); $dl->bind_param("ii", $report_id, $user_id); $dl->execute(); $dlrow = $dl->get_result()->fetch_assoc();
    if (!$dlrow || empty($dlrow['report_blob'])) { while (ob_get_level() > 0) { ob_end_clean(); } http_response_code(404); echo '<p>Report not found.</p>'; exit; }
    while (ob_get_level() > 0) { ob_end_clean(); }
    header('Content-Type: text/html; charset=utf-8'); echo $dlrow['report_blob']; exit;
}

/* ============================================================ POLL HANDLER ============================================================ */
if (isset($_GET['poll']) && $_GET['poll'] == '1') {
    $week_start_p = date("Y-m-d", strtotime("monday this week"));
    $chk_p = $conn->prepare("SELECT id, remark FROM reports WHERE user_id = ? AND week_start = ?"); $chk_p->bind_param("is", $user_id, $week_start_p); $chk_p->execute(); $existing_p = $chk_p->get_result()->fetch_assoc();
    $hist_p = $conn->prepare("SELECT id, week_start, submitted_at, remark, feedback FROM reports WHERE user_id = ? ORDER BY submitted_at DESC"); $hist_p->bind_param("i", $user_id); $hist_p->execute(); $history_p = $hist_p->get_result()->fetch_all(MYSQLI_ASSOC);

    /* NEW: weekly report compliance stats (Total/Submitted/Missed) for the
       Report History summary cards, kept in sync with each poll refresh. */
    $poll_ca = $conn->prepare("SELECT company_id FROM ojt_assignments WHERE student_id=? LIMIT 1");
    $poll_ca->bind_param("i", $user_id); $poll_ca->execute();
    $poll_ca_row = $poll_ca->get_result()->fetch_assoc(); $poll_ca->close();
    $poll_ojt_start_date = '';
    if ($poll_ca_row) {
        $poll_company_id = (int)$poll_ca_row['company_id'];
        $poll_start_stmt = $conn->prepare("SELECT MIN(date) as start_date FROM attendance_settings WHERE company_id = ?");
        $poll_start_stmt->bind_param("i", $poll_company_id); $poll_start_stmt->execute();
        $poll_start_row = $poll_start_stmt->get_result()->fetch_assoc(); $poll_start_stmt->close();
        $poll_ojt_start_date = $poll_start_row['start_date'] ?? '';
    }
    $poll_submitted_count = count(array_filter($history_p, fn($r) => ($r['remark'] ?? '') !== 'Wrong Document'));
    $poll_report_stats = computeWeeklyReportStats($poll_ojt_start_date, $poll_submitted_count);

    sendJson(['existing' => $existing_p, 'history' => $history_p, 'is_friday' => (date('N') == 5), 'week_start' => $week_start_p, 'report_stats' => $poll_report_stats]);
}

/* ============================================================ POST HANDLER ============================================================ */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['submit_journal'])) {
    $is_ajax = ((isset($_GET['ajax']) && $_GET['ajax'] == '1') || (!empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest'));
    if (date('N') != 5) { if ($is_ajax) { sendJson(['success' => false, 'message' => 'Reports can only be submitted on Friday.']); } while (ob_get_level() > 0) { ob_end_clean(); } die("Reports can only be submitted on Friday."); }
    $week_start = $_POST['week_start'] ?? date("Y-m-d", strtotime("monday this week")); $company_id = (int)($_POST['company_id'] ?? 0);
    $chk = $conn->prepare("SELECT id, remark FROM reports WHERE user_id = ? AND week_start = ?"); $chk->bind_param("is", $user_id, $week_start); $chk->execute(); $existing_post = $chk->get_result()->fetch_assoc();
    if ($existing_post && $existing_post['remark'] != "Wrong Document") { if ($is_ajax) { sendJson(['success' => false, 'message' => 'Already submitted.']); } while (ob_get_level() > 0) { ob_end_clean(); } header("Location: student_report.php"); exit; }
    try {
        $si = $conn->prepare("SELECT first_name, middle_name, last_name FROM users WHERE id = ?"); $si->bind_param("i", $user_id); $si->execute(); $stu = $si->get_result()->fetch_assoc(); $si->close();
        if (!$stu) throw new RuntimeException('Student record not found.');
        $fn_parts = array_filter([trim($stu['first_name'] ?? ''), trim($stu['middle_name'] ?? ''), trim($stu['last_name'] ?? '')], fn($p) => $p !== '');
        $fn = implode(' ', $fn_parts);
        $student_info_fields = fetchStudentInfoFields($conn, $user_id); $course_val = $student_info_fields['course']; $coordinator_name_val = $student_info_fields['coordinator_name'];
        $week_end_val = date("Y-m-d", strtotime("friday this week"));
        $company_fields = fetchCompanyFields($conn, $user_id); $company_name_val = $company_fields['company_name']; $supervisor_name_val = $company_fields['supervisor_name'];
        /* NEW: Training Station — free-text value typed by the student in
           the "Training Station" field added to the report form. Trimmed
           and passed straight through to buildWeeklyReportHTML() below;
           the builder already knows how to render it (it falls back to
           an em-dash placeholder when empty, same as the other fields). */
        $training_station_val = trim($_POST['training_station'] ?? '');
        $aq = $conn->prepare("SELECT date, am_time_in, am_time_out, pm_time_in, pm_time_out FROM attendance_logs WHERE user_id = ? AND date BETWEEN ? AND ?"); $aq->bind_param("iss", $user_id, $week_start, $week_end_val); $aq->execute(); $att_map = []; $att_res = $aq->get_result(); while ($ar = $att_res->fetch_assoc()) { $att_map[$ar['date']] = $ar; } $aq->close();
        $day_labels_val = ['Monday','Tuesday','Wednesday','Thursday','Friday']; $days_arr = []; for ($i = 0; $i < 5; $i++) $days_arr[] = date("Y-m-d", strtotime("monday this week +{$i} days"));
        $days_data = []; $total_hours = 0;
        foreach ($day_labels_val as $idx => $lbl) {
            $date = $days_arr[$idx] ?? null; $xatt = $date ? ($att_map[$date] ?? null) : null;
            $xpres = $xatt && (hasRealValue($xatt['am_time_in']) || hasRealValue($xatt['am_time_out']) || hasRealValue($xatt['pm_time_in']) || hasRealValue($xatt['pm_time_out']));
            $day_hours = 0;
            if ($xpres && $xatt) { $am_h = calcHoursFromLog($xatt['am_time_in'], $xatt['am_time_out']); $pm_h = calcHoursFromLog($xatt['pm_time_in'], $xatt['pm_time_out']); $day_hours = round($am_h + $pm_h, 2); if ($day_hours > 0) $total_hours += $day_hours; }
            $day_key = strtolower($lbl); $tasks_txt = '';
            if ($xpres) $tasks_txt = trim($_POST["tasks_{$day_key}"] ?? '');
            if ($date) $days_data[$date] = ['present' => $xpres, 'tasks' => $tasks_txt, 'hours' => $day_hours > 0 ? $day_hours : 0];
        }
        $total_hours = round($total_hours, 2);
        $html_blob = buildWeeklyReportHTML(['student_name' => $fn, 'course' => $course_val, 'company_name' => $company_name_val, 'training_station' => $training_station_val, 'supervisor_name' => $supervisor_name_val, 'coordinator_name' => $coordinator_name_val, 'week_start' => $week_start, 'week_end' => $week_end_val, 'days' => $days_data, 'total_hours' => $total_hours]);
        if (empty($html_blob)) throw new RuntimeException('buildWeeklyReportHTML() returned empty output');
        $now = date("Y-m-d H:i:s");
        if ($existing_post && $existing_post['remark'] == "Wrong Document") {
            $upd = $conn->prepare("UPDATE reports SET report_blob=?, report_file=NULL, remark=NULL, feedback=NULL, company_grade=NULL, submitted_at=? WHERE id=?"); if (!$upd) throw new RuntimeException('DB prepare (UPDATE) failed: ' . $conn->error);
            $upd->bind_param("ssi", $html_blob, $now, $existing_post['id']); if (!$upd->execute()) throw new RuntimeException('DB execute (UPDATE) failed: ' . $upd->error); $upd->close();
        } else {
            $ins = $conn->prepare("INSERT INTO reports (user_id, company_id, week_start, report_blob, submitted_at) VALUES (?,?,?,?,?)"); if (!$ins) throw new RuntimeException('DB prepare (INSERT) failed: ' . $conn->error);
            $ins->bind_param("iisss", $user_id, $company_id, $week_start, $html_blob, $now); if (!$ins->execute()) throw new RuntimeException('DB execute (INSERT) failed: ' . $ins->error); $ins->close();
        }
        if ($is_ajax) { sendJson(['success' => true, 'message' => 'Your weekly report was submitted successfully!']); }
        while (ob_get_level() > 0) { ob_end_clean(); } header("Location: student_report.php?submitted=1"); exit;
    } catch (Throwable $submitEx) {
        error_log('[OJT Submit EXCEPTION] ' . $submitEx->getMessage());
        if ($is_ajax) { sendJson(['success' => false, 'message' => 'Submission failed due to a server error.', '_exception_class' => get_class($submitEx), '_exception_msg' => $submitEx->getMessage(), '_exception_file' => basename($submitEx->getFile()) . ':' . $submitEx->getLine(), '_exception_trace' => substr($submitEx->getTraceAsString(), 0, 3000)]); }
        while (ob_get_level() > 0) { ob_end_clean(); } die("Submission failed: " . htmlspecialchars($submitEx->getMessage()));
    }
}

/* ============================================================ GET — LOAD DATA ============================================================ */
/* FIX: Fetch middle_name as well so sidebar shows full name including middle name */
$stmt = $conn->prepare("SELECT first_name, middle_name, last_name FROM users WHERE id = ?"); $stmt->bind_param("i", $user_id); $stmt->execute(); $student = $stmt->get_result()->fetch_assoc(); $stmt->close();
if (!$student) { while (ob_get_level() > 0) { ob_end_clean(); } http_response_code(500); echo '<p>Account not found.</p>'; exit; }

/* Build full name with middle name */
$full_name_parts = array_filter([
    trim($student['first_name'] ?? ''),
    trim($student['middle_name'] ?? ''),
    trim($student['last_name'] ?? '')
], fn($p) => $p !== '');
$full_name = implode(' ', $full_name_parts);

$page_info_fields = fetchStudentInfoFields($conn, $user_id); $course = $page_info_fields['course'];
$stmt2 = $conn->prepare("SELECT company_id FROM ojt_assignments WHERE student_id = ?"); $stmt2->bind_param("i", $user_id); $stmt2->execute(); $res_company = $stmt2->get_result()->fetch_assoc(); $stmt2->close();

if (!$res_company) {
    while (ob_get_level() > 0) { ob_end_clean(); }
    ?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1">
    <title>No Company Assigned — OJT Report</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        *{box-sizing:border-box;margin:0;padding:0;}
        body{font-family:'Segoe UI',sans-serif;background:#f5f5f0;color:#2d3748;}
        :root{--maroon:#07145fe5;--gold:#FFD700;--active:#1a237e;}
        .sidebar{width:260px;background:var(--maroon);height:100vh;position:fixed;top:0;left:0;display:flex;flex-direction:column;z-index:1000;}
        .sidebar-header{padding:16px 20px;display:flex;align-items:center;justify-content:space-between;border-bottom:1px solid rgba(255,255,255,0.1);min-height:72px;}
        .sidebar-user-info{display:flex;flex-direction:column;gap:1px;overflow:hidden;max-width:180px;}
        .sidebar-user-name{color:var(--gold);font-size:14px;font-weight:700;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;line-height:1.3;}
        .sidebar-user-role{color:rgba(255,255,255,0.55);font-size:10px;font-weight:500;text-transform:uppercase;letter-spacing:0.08em;white-space:nowrap;}
        .sidebar-links{flex:1;display:flex;flex-direction:column;padding:10px 0;}
        .sidebar a{padding:15px 25px;color:#cbd5e0;text-decoration:none;font-size:14px;display:flex;align-items:center;transition:background .2s;}
        .sidebar a i{width:30px;font-size:18px;margin-right:15px;text-align:center;}
        .sidebar a:hover{background:rgba(255,255,255,0.07);color:white;}
        .sidebar a.active{background:var(--active);color:white;border-left:4px solid var(--gold);}
        .logout-link{margin-top:auto;padding:20px;border-top:1px solid rgba(255,255,255,0.1);}
        .logout-link a{border:1px solid var(--gold);color:var(--gold);border-radius:6px;justify-content:center;padding:10px;display:flex;align-items:center;text-decoration:none;}
        .toggle-btn{background:transparent;border:none;color:white;cursor:pointer;font-size:20px;}
        .main-content{margin-left:260px;width:calc(100% - 260px);display:flex;flex-direction:column;min-height:100vh;}
        .navbar{background:var(--maroon);padding:10px 30px;display:flex;align-items:center;color:white;height:60px;}
        .navbar img{height:40px;margin-right:14px;}
        .error-wrap{display:flex;align-items:center;justify-content:center;flex:1;padding:40px 20px;}
        .error-box{background:white;border-radius:20px;padding:48px 44px;max-width:540px;width:100%;text-align:center;box-shadow:0 4px 24px rgba(0,0,0,.08);}
        .error-icon{font-size:3.5rem;margin-bottom:18px;}
        .error-box h2{font-size:1.3rem;font-weight:700;color:#111827;margin-bottom:10px;}
        .error-box p{color:#6b7280;font-size:.92rem;line-height:1.65;margin-bottom:6px;}
        .error-box .hint{background:#fef3c7;border:1.5px solid #fcd34d;border-radius:10px;padding:12px 16px;margin:18px 0;color:#92400e;font-size:.85rem;text-align:left;}
        .btn-dash{display:inline-flex;align-items:center;gap:8px;background:#07145f;color:white;border:none;border-radius:10px;padding:12px 26px;font-size:.9rem;font-weight:700;text-decoration:none;margin-top:6px;}
    </style>
</head>
<body>
<div class="sidebar">
    <div class="sidebar-header">
        <div class="sidebar-user-info">
            <span class="sidebar-user-name"><?php echo htmlspecialchars($full_name); ?></span>
            <span class="sidebar-user-role">OJT Trainee</span>
        </div>
        <button class="toggle-btn"><i class="fas fa-bars"></i></button>
    </div>
    <div class="sidebar-links">
        <a href="student_profile.php"><i class="fas fa-user-circle"></i>My Profile</a>
        <a href="company_list.php"><i class="fas fa-building"></i>Company List</a>
        <a href="AccomForm.php"><i class="fas fa-file-contract"></i>Requirements</a>
        <a href="student_attendance.php"><i class="fas fa-calendar-check"></i>Attendance</a>
        <a href="student_report.php" class="active"><i class="fas fa-chart-bar"></i>Reports</a>
        <a href="student_dashboard.php"><i class="fas fa-tachometer-alt"></i>Dashboard</a>
    </div>
    <div class="logout-link"><a href="login.php"><i class="fas fa-sign-out-alt"></i><span style="margin-left:10px;">Logout</span></a></div>
</div>
<div class="main-content">
    <nav class="navbar"><img src="logo.webp" alt="NEUST Logo"><div><div style="font-weight:bold;font-size:16px;">NEUST Atate Campus</div><div style="font-size:11px;color:var(--gold);">Web-Based Smart OJT Monitoring and Supervision Analytics System</div></div></nav>
    <div class="error-wrap"><div class="error-box"><div class="error-icon"></div><h2>No Company Assigned</h2><p>Your account (<strong><?= htmlspecialchars($full_name) ?></strong>) does not have a company assignment yet.</p><div class="hint"><strong> What to do:</strong><br>Contact your OJT Coordinator to have your company assignment added.</div><a href="student_dashboard.php" class="btn-dash">🏠 Go to Dashboard</a></div></div>
</div>
</body>
</html>
    <?php
    exit;
}

$company_id = $res_company['company_id'];

/* NEW: OJT start date (company's earliest attendance_settings date) — used
   below (after $history is fetched) by computeWeeklyReportStats() to power
   the "Total / Submitted / Not Submitted" Report History summary cards. */
$ojt_start_stmt = $conn->prepare("SELECT MIN(date) as start_date FROM attendance_settings WHERE company_id = ?");
$ojt_start_stmt->bind_param("i", $company_id);
$ojt_start_stmt->execute();
$ojt_start_row = $ojt_start_stmt->get_result()->fetch_assoc();
$ojt_start_date = $ojt_start_row['start_date'] ?? '';
$ojt_start_stmt->close();

$week_start = date("Y-m-d", strtotime("monday this week"));
$week_end   = date("Y-m-d", strtotime("friday this week"));
$is_friday  = (date("N") == 5);
$day_labels = ['Monday','Tuesday','Wednesday','Thursday','Friday'];
$days = [];
for ($i = 0; $i < 5; $i++) $days[] = date("Y-m-d", strtotime("monday this week +{$i} days"));
$att_stmt = $conn->prepare("SELECT date, am_time_in, am_time_out, pm_time_in, pm_time_out FROM attendance_logs WHERE user_id = ? AND date BETWEEN ? AND ?");
$att_stmt->bind_param("iss", $user_id, $week_start, $week_end); $att_stmt->execute();
$attendance = []; $result = $att_stmt->get_result(); while ($row = $result->fetch_assoc()) { $attendance[$row['date']] = $row; } $att_stmt->close();
$check = $conn->prepare("SELECT id, remark FROM reports WHERE user_id = ? AND week_start = ?"); $check->bind_param("is", $user_id, $week_start); $check->execute(); $existing = $check->get_result()->fetch_assoc(); $check->close();
$disableSubmit = $existing && $existing['remark'] != "Wrong Document";
$flash = isset($_GET['submitted']) ? "Your weekly report was submitted successfully!" : '';
$hist = $conn->prepare("SELECT id, week_start, submitted_at, remark, feedback FROM reports WHERE user_id = ? ORDER BY submitted_at DESC"); $hist->bind_param("i", $user_id); $hist->execute(); $history = $hist->get_result()->fetch_all(MYSQLI_ASSOC); $hist->close();

/* NEW: weekly report compliance stats (Total expected / Submitted / Missed)
   for the Report History summary cards. "Submitted" excludes reports
   marked "Wrong Document" — matching the same definition used by
   company_reports.php's Student Library. */
$report_stats_submitted_count = count(array_filter($history, fn($r) => ($r['remark'] ?? '') !== 'Wrong Document'));
$report_stats = computeWeeklyReportStats($ojt_start_date, $report_stats_submitted_count);

$draft_key = "ojt_draft_{$user_id}_{$week_start}";
$ds_get = $conn->prepare("SELECT am_time_in_start, pm_time_out_start FROM attendance_settings WHERE company_id = ? AND date <= CURDATE() ORDER BY date DESC LIMIT 1"); $ds_get->bind_param("i", $company_id); $ds_get->execute(); $duty_get = $ds_get->get_result()->fetch_assoc(); $ds_get->close();
$duty_start = $duty_get ? fmtTime12($duty_get['am_time_in_start']) : '-'; $duty_end = $duty_get ? fmtTime12($duty_get['pm_time_out_start']) : '-';
$journal_empty_count = 0;
if (!$disableSubmit) { foreach ($days as $i => $date) { if (!isset($attendance[$date])) continue; $att_row = $attendance[$date]; $present = hasRealValue($att_row['am_time_in']) || hasRealValue($att_row['am_time_out']) || hasRealValue($att_row['pm_time_in']) || hasRealValue($att_row['pm_time_out']); if ($present) $journal_empty_count += 1; } }

/* ── Compute available DTR months (months that have attendance logs) ── */
$dtr_months_stmt = $conn->prepare(
    "SELECT DISTINCT DATE_FORMAT(date, '%Y-%m') AS ym, DATE_FORMAT(date, '%M %Y') AS label " .
    "FROM attendance_logs WHERE user_id=? ORDER BY ym DESC LIMIT 24"
);
$dtr_months_stmt->bind_param("i", $user_id); $dtr_months_stmt->execute();
$dtr_months_res = $dtr_months_stmt->get_result();
$dtr_months = [];
while ($dmr = $dtr_months_res->fetch_assoc()) { $dtr_months[] = $dmr; }
$dtr_months_stmt->close();

while (ob_get_level() > 0) { ob_end_clean(); }
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Weekly OJT Report</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Lora:ital,wght@0,400;0,500;0,600;1,400&family=DM+Sans:wght@300;400;500;600&display=swap" rel="stylesheet">
    <style>
        :root {
            --maroon:       #07145f;
            --gold:         #FFD700;
            --active-nav:   #1a237e;
            --ink:          #1a1a2e;
            --ink-muted:    #4a4a6a;
            --ink-faint:    #8888aa;
            --surface:      #ffffff;
            --surface-soft: #f7f6f3;
            --surface-warm: #f0efe9;
            --border:       #e4e2da;
            --border-light: #eeede8;
            --teal:         #0d8c6a;
            --teal-light:   #e1f5ee;
            --teal-dark:    #085041;
            --blue:         #185fa5;
            --blue-light:   #e6f1fb;
            --amber:        #b45309;
            --amber-light:  #fef3c7;
            --red:          #991b1b;
            --red-light:    #fee2e2;
            --radius-sm:    6px;
            --radius-md:    10px;
            --radius-lg:    16px;
            --shadow-card:  0 1px 3px rgba(0,0,0,.06), 0 4px 16px rgba(0,0,0,.04);
            --shadow-lift:  0 4px 20px rgba(0,0,0,.10);
        }
        * { box-sizing: border-box; margin: 0; padding: 0; }
        body { font-family: 'DM Sans', 'Segoe UI', sans-serif; background: var(--surface-warm); color: var(--ink); line-height: 1.6; }

        /* ══════════════════════════════════════════
           SIDEBAR
        ══════════════════════════════════════════ */
        .sidebar {
            width: 260px; background: var(--maroon); height: 100vh;
            position: fixed; top: 0; left: 0; display: flex; flex-direction: column;
            transition: width 0.3s ease; z-index: 1000;
            box-shadow: 4px 0 10px rgba(0,0,0,0.1);
        }
        .sidebar.collapsed { width: 80px; }
        .sidebar-header {
            padding: 16px 20px; display: flex; align-items: center;
            justify-content: space-between; border-bottom: 1px solid rgba(255,255,255,0.1);
            flex-shrink: 0; min-height: 72px;
        }
        .sidebar-user-info {
            display: flex; flex-direction: column; gap: 1px;
            overflow: hidden; transition: opacity 0.2s, width 0.3s; max-width: 180px;
        }
        .sidebar-user-name {
            color: var(--gold); font-size: 14px; font-weight: 700;
            white-space: nowrap; overflow: hidden; text-overflow: ellipsis;
            line-height: 1.3; font-family: 'DM Sans', sans-serif;
        }
        .sidebar-user-role {
            color: rgba(255,255,255,0.55); font-size: 10px; font-weight: 500;
            text-transform: uppercase; letter-spacing: 0.08em; white-space: nowrap;
        }
        .sidebar.collapsed .sidebar-user-info { opacity: 0; width: 0; overflow: hidden; }
        .sidebar-links { flex: 1; display: flex; flex-direction: column; padding: 10px 0; overflow: hidden; }
        .sidebar a {
            padding: 15px 25px; color: #cbd5e0; text-decoration: none; font-size: 14px;
            display: flex; align-items: center; transition: background 0.2s, color 0.2s;
            white-space: nowrap; position: relative;
        }
        .sidebar a i { width: 30px; font-size: 18px; margin-right: 15px; text-align: center; flex-shrink: 0; }
        .sidebar.collapsed .link-text { display: none; }
        .sidebar.collapsed a i { margin-right: 0; }
        .sidebar a:hover:not(.active) { background: rgba(255,255,255,0.07); color: white; }
        .sidebar a.active { background: var(--active-nav); color: white; border-left: 4px solid var(--gold); }
        .sidebar-badge-att {
            background: #d97706; color: white; border-radius: 50%; width: 18px; height: 18px;
            font-size: 10px; font-weight: 700; display: inline-flex; align-items: center;
            justify-content: center; position: absolute; right: 18px; top: 50%;
            transform: translateY(-50%); animation: badge-pulse-att 2s ease-in-out infinite;
        }
        @keyframes badge-pulse-att {
            0%,100%{box-shadow:0 0 0 0 rgba(217,119,6,.55);}
            50%{box-shadow:0 0 0 6px rgba(217,119,6,0);}
        }
        .sidebar-badge-journal {
            background: #f59e0b; color: #1c1917; border-radius: 50%; min-width: 18px; height: 18px;
            font-size: 10px; font-weight: 800; display: inline-flex; align-items: center;
            justify-content: center; position: absolute; right: 18px; top: 50%;
            transform: translateY(-50%); padding: 0 3px; animation: badge-pulse-journal 2.4s ease-in-out infinite;
        }
        @keyframes badge-pulse-journal {
            0%,100%{box-shadow:0 0 0 0 rgba(245,158,11,.5);}
            50%{box-shadow:0 0 0 5px rgba(245,158,11,0);}
        }
        .logout-link { margin-top: auto; padding: 20px; border-top: 1px solid rgba(255,255,255,0.1); }
        .logout-link a {
            border: 1px solid var(--gold); color: var(--gold); border-radius: 6px;
            justify-content: center; padding: 10px; display: flex; align-items: center;
            text-decoration: none; font-size: 14px; transition: background 0.2s;
        }
        .logout-link a:hover { background: rgba(255,215,0,0.08); }
        .toggle-btn { background: transparent; border: none; color: white; cursor: pointer; font-size: 20px; outline: none; flex-shrink: 0; }

        /* MAIN CONTENT */
        .main-content { margin-left: 260px; width: calc(100% - 260px); transition: margin-left 0.3s, width 0.3s; display: flex; flex-direction: column; min-height: 100vh; }

        /* NAVBAR */
        .navbar { background: var(--maroon); padding: 10px 30px; display: flex; align-items: center; color: white; height: 60px; flex-shrink: 0; box-shadow: 0 2px 8px rgba(0,0,0,0.15); position: relative; z-index: 99; }
        .navbar img { height: 40px; margin-right: 14px; }

        /* ══════════════════════════════════════════════════════
           ATTENDANCE NOTIFICATION BAR
        ══════════════════════════════════════════════════════ */
        #att-notif-bar {
            position: fixed; top: 60px; left: 50%;
            transform: translateX(-50%) translateY(-120%);
            visibility: hidden; opacity: 0;
            width: calc(100% - 300px); max-width: 820px;
            background: #07145f;
            border-radius: 0 0 12px 12px;
            border: 1px solid rgba(255,255,255,.12); border-top: none;
            padding: 10px 16px;
            display: flex; align-items: center; gap: 12px;
            transition: transform .4s cubic-bezier(.34,1.2,.64,1), opacity .3s ease, visibility 0s linear .4s;
            z-index: 2000; pointer-events: none; overflow: hidden;
        }
        #att-notif-bar.anb-visible {
            transform: translateX(-50%) translateY(0);
            visibility: visible; opacity: 1;
            transition: transform .4s cubic-bezier(.34,1.2,.64,1), opacity .3s ease, visibility 0s linear 0s;
            pointer-events: auto;
        }
        #att-notif-bar.sidebar-collapsed { width: calc(100% - 120px); }
        .anb-icon { width: 34px; height: 34px; border-radius: 8px; background: #FAEEDA; display: flex; align-items: center; justify-content: center; flex-shrink: 0; }
        .anb-icon i { font-size: 16px; color: #854F0B; }
        .anb-pulse { width: 8px; height: 8px; border-radius: 50%; background: #EF9F27; flex-shrink: 0; animation: anb-blink 1.4s ease-in-out infinite; }
        @keyframes anb-blink { 0%,100%{opacity:1} 50%{opacity:.2} }
        .anb-content { flex: 1; min-width: 0; display: flex; align-items: center; gap: 14px; flex-wrap: nowrap; overflow: hidden; }
        .anb-text-group { display: flex; flex-direction: column; min-width: 0; }
        .anb-label { font-size: 12px; font-weight: 700; color: #FAEEDA; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
        .anb-window { font-size: 11px; color: rgba(250,238,218,.65); margin-top: 1px; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
        .anb-divider { width: 1px; height: 26px; background: rgba(255,255,255,.18); flex-shrink: 0; }
        .anb-countdown { font-size: 11px; color: #FAC775; white-space: nowrap; background: rgba(250,199,117,.14); border-radius: 99px; padding: 3px 11px; border: 1px solid rgba(250,199,117,.28); font-family: 'DM Sans', monospace; font-variant-numeric: tabular-nums; flex-shrink: 0; min-width: 100px; text-align: center; }
        .anb-btn { background: #EF9F27; color: #412402; border: none; border-radius: 7px; padding: 7px 15px; font-size: 11px; font-weight: 700; font-family: inherit; white-space: nowrap; flex-shrink: 0; transition: background .15s; cursor: pointer; }
        .anb-btn:hover { background: #FAC775; }
        .anb-close { background: rgba(255,255,255,.12); border: none; color: rgba(250,238,218,.75); width: 26px; height: 26px; border-radius: 50%; font-size: 13px; display: flex; align-items: center; justify-content: center; flex-shrink: 0; transition: background .15s; cursor: pointer; }
        .anb-close:hover { background: rgba(255,255,255,.24); color: #FAEEDA; }
        .anb-progress { position: absolute; bottom: 0; left: 0; height: 2px; background: #EF9F27; border-radius: 0 0 0 12px; pointer-events: none; }

        /* TOOLBAR */
        .page-toolbar { background: var(--surface); border-bottom: 1px solid var(--border); padding: 10px 28px; display: flex; align-items: center; justify-content: flex-end; gap: 10px; position: sticky; top: 0; z-index: 100; box-shadow: 0 1px 6px rgba(0,0,0,0.06); }
        .history-btn { background: var(--maroon); color: white; border: none; border-radius: var(--radius-md); padding: 8px 16px; cursor: pointer; font-size: 0.83rem; font-family: inherit; font-weight: 600; display: flex; align-items: center; gap: 7px; transition: background 0.2s, transform 0.15s; }
        .history-btn:hover { background: var(--active-nav); transform: translateY(-1px); }
        .history-btn .badge { background: #f05252; border-radius: 50%; width: 19px; height: 19px; font-size: 0.68rem; display: flex; align-items: center; justify-content: center; font-weight: 800; }
        .dtr-btn { background: #065f46; color: white; border: none; border-radius: var(--radius-md); padding: 8px 16px; cursor: pointer; font-size: 0.83rem; font-family: inherit; font-weight: 600; display: flex; align-items: center; gap: 7px; transition: background 0.2s, transform 0.15s; }
        .dtr-btn:hover { background: #047857; transform: translateY(-1px); }

        /* FLASH */
        .flash { padding: 14px 28px 0; }
        .flash-msg { background: var(--teal-light); border: 1.5px solid var(--teal); border-radius: var(--radius-md); padding: 11px 16px; color: var(--teal-dark); font-size: 0.87rem; font-weight: 500; display: flex; align-items: center; gap: 10px; transition: opacity 0.6s; }
        .flash-msg::before { content: '✓'; font-weight: 700; font-size: 1rem; }

        /* CONTAINER */
        .container { max-width: 960px; margin: 24px auto 80px; padding: 0 28px; width: 100%; }

        /* WEEK CARD */
        .week-card { background: var(--surface); border: 1px solid var(--border); border-radius: var(--radius-lg); padding: 16px 22px; margin-bottom: 24px; display: flex; align-items: center; justify-content: space-between; box-shadow: var(--shadow-card); }
        .week-card-left { display: flex; align-items: center; gap: 14px; }
        .week-icon { width: 44px; height: 44px; background: var(--blue-light); border-radius: var(--radius-md); display: flex; align-items: center; justify-content: center; color: var(--blue); font-size: 1.2rem; flex-shrink: 0; }
        .week-label { font-size: 0.72rem; color: var(--ink-faint); text-transform: uppercase; letter-spacing: 0.06em; margin-bottom: 3px; }
        .week-date  { font-size: 1rem; font-weight: 600; color: var(--ink); font-family: 'Lora', serif; }
        .week-pill { font-size: 0.72rem; font-weight: 600; padding: 5px 14px; border-radius: 99px; letter-spacing: 0.02em; }
        .week-pill.open   { background: var(--teal-light); color: var(--teal-dark); border: 1px solid #9FE1CB; }
        .week-pill.locked { background: var(--amber-light); color: var(--amber); border: 1px solid #fcd34d; }
        .week-pill.done   { background: var(--blue-light);  color: var(--blue);  border: 1px solid #B5D4F4; }

        /* TRAINING STATION CARD (NEW) — mirrors the week-card visual
           language above; sits inside the report form so its value is
           submitted along with the day-by-day entries. */
        .training-card { background: var(--surface); border: 1px solid var(--border); border-radius: var(--radius-lg); padding: 16px 22px; margin-bottom: 24px; box-shadow: var(--shadow-card); }
        .training-card-label { display: flex; align-items: center; gap: 7px; font-size: 0.75rem; font-weight: 600; color: var(--ink-muted); text-transform: uppercase; letter-spacing: 0.05em; margin-bottom: 8px; }
        .training-card-label i { color: var(--blue); font-size: 0.78rem; }
        .training-input { width: 100%; border: 1.5px solid var(--border); border-radius: var(--radius-md); padding: 10px 14px; font-size: 0.9rem; font-family: 'DM Sans', sans-serif; background: var(--surface-soft); color: var(--ink); transition: border-color 0.2s, box-shadow 0.2s, background 0.2s; }
        .training-input:focus { outline: none; border-color: var(--teal); background: white; box-shadow: 0 0 0 3px rgba(13,140,106,0.10); }
        .training-input:disabled { background: var(--surface-warm); color: var(--ink-faint); cursor: not-allowed; border-style: dashed; }
        .training-input::placeholder { color: var(--ink-faint); font-style: italic; }

        /* TIMELINE */
        .timeline-wrap { position: relative; }
        .timeline-line { position: absolute; left: 17px; top: 12px; bottom: 12px; width: 2px; background: linear-gradient(to bottom, var(--teal) 0%, var(--border) 100%); border-radius: 99px; z-index: 0; }
        .tl-item { position: relative; display: flex; gap: 20px; padding-bottom: 18px; align-items: flex-start; }
        .tl-item:last-child { padding-bottom: 0; }
        .tl-dot { width: 36px; height: 36px; border-radius: 50%; flex-shrink: 0; display: flex; align-items: center; justify-content: center; font-size: 0.8rem; font-weight: 700; position: relative; z-index: 1; transition: transform 0.2s; }
        .tl-dot.present   { background: var(--teal); color: white; box-shadow: 0 0 0 4px var(--teal-light); }
        .tl-dot.absent    { background: var(--surface); color: var(--ink-faint); border: 2px solid var(--border); box-shadow: 0 0 0 3px var(--surface-warm); }
        .tl-dot.submitted { background: var(--blue); color: white; box-shadow: 0 0 0 4px var(--blue-light); }
        .tl-card { flex: 1; background: var(--surface); border: 1px solid var(--border); border-radius: var(--radius-lg); overflow: hidden; box-shadow: var(--shadow-card); transition: box-shadow 0.2s, transform 0.2s; min-width: 0; }
        .tl-card:hover { box-shadow: var(--shadow-lift); transform: translateY(-1px); }
        .tl-card.absent-card { opacity: 0.55; }
        .tl-card.absent-card:hover { transform: none; box-shadow: var(--shadow-card); }
        .tl-card-head { display: flex; align-items: center; justify-content: space-between; padding: 13px 20px; border-bottom: 1px solid var(--border-light); background: var(--surface-soft); }
        .tl-card-head-left { display: flex; flex-direction: column; gap: 2px; }
        .tl-card-head-right { display: flex; align-items: center; gap: 12px; }
        .tl-day-name { font-size: 1rem; font-weight: 600; color: var(--ink); font-family: 'Lora', serif; font-style: italic; }
        .tl-day-date { font-size: 0.72rem; color: var(--ink-faint); }
        .tl-shift-info { font-size: 0.72rem; color: var(--ink-faint); display: flex; align-items: center; gap: 4px; }
        .tl-shift-info i { font-size: 0.72rem; }
        .tl-att-badge { font-size: 0.68rem; font-weight: 700; padding: 3px 11px; border-radius: 99px; text-transform: uppercase; letter-spacing: 0.04em; }
        .tl-att-badge.present { background: var(--teal-light); color: var(--teal-dark); }
        .tl-att-badge.absent  { background: var(--surface-warm); color: var(--ink-faint); border: 1px solid var(--border); }
        .tl-att-badge.done    { background: var(--blue-light);   color: var(--blue); }
        .tl-card-body { padding: 16px 20px; }
        .field-label { display: flex; align-items: center; gap: 7px; font-size: 0.75rem; font-weight: 600; color: var(--ink-muted); text-transform: uppercase; letter-spacing: 0.05em; margin-bottom: 8px; }
        .field-label i { color: var(--blue); font-size: 0.78rem; }
        .tl-textarea { width: 100%; border: 1.5px solid var(--border); border-radius: var(--radius-md); padding: 12px 16px; font-size: 0.9rem; font-family: 'DM Sans', sans-serif; resize: vertical; min-height: 100px; background: var(--surface-soft); color: var(--ink); line-height: 1.65; transition: border-color 0.2s, box-shadow 0.2s, background 0.2s; }
        .tl-textarea:focus { outline: none; border-color: var(--teal); background: white; box-shadow: 0 0 0 3px rgba(13,140,106,0.10); }
        .tl-textarea:disabled { background: var(--surface-warm); color: var(--ink-faint); cursor: not-allowed; border-style: dashed; }
        .tl-textarea::placeholder { color: var(--ink-faint); font-style: italic; }
        .counter-row { display: flex; justify-content: space-between; font-size: 0.68rem; color: var(--ink-faint); margin-top: 6px; padding: 0 2px; }
        .counter-row span.warn { color: var(--amber); }
        .counter-row span.danger { color: var(--red); }
        .draft-row { display: flex; align-items: center; gap: 6px; font-size: 0.72rem; color: var(--ink-faint); font-style: italic; margin-top: 6px; }
        .draft-dot { width: 6px; height: 6px; border-radius: 50%; background: var(--amber); flex-shrink: 0; }
        .draft-dot.saved { background: var(--teal); animation: pulse-teal 2s ease-in-out; }
        @keyframes pulse-teal { 0%,100%{box-shadow:0 0 0 0 rgba(13,140,106,.4);} 50%{box-shadow:0 0 0 6px rgba(13,140,106,0);} }

        /* SUBMIT SECTION */
        .submit-section { margin-top: 24px; background: var(--surface); border: 1px solid var(--border); border-radius: var(--radius-lg); padding: 18px 22px; box-shadow: var(--shadow-card); display: flex; align-items: center; justify-content: space-between; flex-wrap: wrap; gap: 14px; }
        .submit-note { font-size: 0.83rem; color: var(--ink-muted); line-height: 1.55; }
        .submit-note strong { color: var(--ink); }
        .submitted-msg { display: flex; align-items: center; gap: 8px; background: var(--teal-light); border: 1.5px solid #9FE1CB; border-radius: var(--radius-md); padding: 9px 16px; color: var(--teal-dark); font-size: 0.85rem; font-weight: 600; }
        .submitted-msg::before { content: '✓'; font-size: 1rem; }
        .rejected-msg { display: flex; align-items: center; gap: 8px; background: var(--red-light); border: 1.5px solid #FCA5A5; border-radius: var(--radius-md); padding: 9px 16px; color: var(--red); font-size: 0.85rem; font-weight: 600; }
        .btn-row { display: flex; gap: 10px; align-items: center; flex-wrap: wrap; }
        .btn-save-draft { background: var(--surface); color: var(--blue); border: 1.5px solid var(--blue); border-radius: var(--radius-md); padding: 10px 20px; font-size: 0.87rem; font-weight: 600; cursor: pointer; font-family: inherit; transition: background 0.2s, transform 0.15s; }
        .btn-save-draft:hover { background: var(--blue-light); transform: translateY(-1px); }
        .btn-submit { background: linear-gradient(135deg, var(--teal), var(--blue)); color: white; border: none; border-radius: var(--radius-md); padding: 11px 26px; font-size: 0.9rem; font-weight: 700; cursor: pointer; font-family: inherit; transition: opacity 0.2s, transform 0.15s, box-shadow 0.2s; }
        .btn-submit:hover:not(:disabled) { opacity: 0.88; transform: translateY(-1px); box-shadow: 0 4px 14px rgba(13,140,106,0.3); }
        .btn-submit:disabled { background: #d1d5db; color: #9ca3af; cursor: not-allowed; }
        .btn-submit.loading { opacity: 0.7; cursor: not-allowed; }

        /* REFRESH INDICATOR */
        .refresh-indicator { position: fixed; bottom: 14px; right: 16px; background: rgba(26,26,46,0.82); color: white; border-radius: 20px; padding: 5px 13px; font-size: 0.72rem; display: flex; align-items: center; gap: 7px; opacity: 0; transition: opacity 0.4s; pointer-events: none; z-index: 500; }
        .refresh-indicator.show { opacity: 1; }
        .refresh-dot { width: 7px; height: 7px; border-radius: 50%; background: var(--teal); animation: pulse-dot 1s ease-in-out infinite; }
        @keyframes pulse-dot { 0%,100%{opacity:1;} 50%{opacity:0.3;} }

        /* ══════════════════════════════════════════════════════════════
           REPORT HISTORY — FULL-SCREEN DOCUMENT VIEW
           (mirrors company_reports.php's Student Library pattern:
           toolbar on top, summary cards, list pane + large inline
           report preview, and a collapsible read-only feedback
           drawer. Grading has been removed entirely — no grade fields
           anywhere in this section.)
        ══════════════════════════════════════════════════════════════ */
        .hist-overlay { display: none; position: fixed; inset: 0; background: rgba(0,0,0,0.72); z-index: 1200; overflow: hidden; flex-direction: column; }
        .hist-overlay.open { display: flex; }

        .hist-doc-toolbar { background: var(--maroon); padding: 0.6rem 1.5rem; display: flex; align-items: center; justify-content: space-between; flex-shrink: 0; box-shadow: 0 2px 10px rgba(0,0,0,0.3); gap: 1rem; flex-wrap: wrap; }
        .hist-doc-toolbar-left { display: flex; align-items: center; gap: 10px; min-width: 0; }
        .hist-doc-toolbar-left i { color: var(--gold); font-size: 1.05rem; flex-shrink: 0; }
        .hist-doc-toolbar-title { font-size: 0.92rem; font-weight: 700; color: #fff; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
        .hist-doc-toolbar-sub { font-size: 0.72rem; color: rgba(255,255,255,0.6); margin-top: 1px; }
        .hist-doc-toolbar-right { display: flex; align-items: center; gap: 8px; flex-shrink: 0; flex-wrap: wrap; }
        .hist-tbtn { display: inline-flex; align-items: center; gap: 0.4rem; padding: 8px 16px; border-radius: 8px; font-size: 0.8rem; font-weight: 600; cursor: pointer; border: none; font-family: inherit; transition: background 0.15s, opacity 0.15s; }
        .hist-tbtn-close { background: rgba(255,255,255,0.13); color: rgba(255,255,255,0.85); border: 1px solid rgba(255,255,255,0.2); }
        .hist-tbtn-close:hover { background: rgba(255,255,255,0.22); color: #fff; }

        /* Summary strip — compliance-style stat cards (no grading) */
        .hist-summary-bar { background: white; border-bottom: 1px solid var(--border); padding: 8px 20px; display: flex; gap: 28px; flex-shrink: 0; flex-wrap: wrap; }
        .hist-stats-row { display: flex; gap: 12px; flex-wrap: wrap; flex: 1; }
        .hist-stat-card { flex: 1 1 150px; min-width: 140px; display: flex; align-items: center; gap: 12px; background: #fff; border: 1px solid var(--border-light); border-radius: 14px; padding: 10px 14px; box-shadow: 0 1px 3px rgba(0,0,0,0.05); }
        .hist-stat-icon { width: 38px; height: 38px; border-radius: 12px; display: flex; align-items: center; justify-content: center; font-size: 15px; flex-shrink: 0; }
        .hist-stat-icon.total     { background: var(--blue-light); color: var(--blue); }
        .hist-stat-icon.feedback  { background: #fef3c7; color: #b45309; }
        .hist-stat-icon.flagged   { background: var(--red-light); color: var(--red); }
        .hist-stat-icon.submitted { background: #d1fae5; color: #059669; }
        .hist-stat-icon.missed    { background: var(--red-light); color: var(--red); }
        .hist-stat-text { display: flex; flex-direction: column; min-width: 0; }
        .hist-stat-num { font-size: 1.3rem; font-weight: 800; line-height: 1.1; color: var(--ink); }
        .hist-stat-num.feedback-num { color: #b45309; }
        .hist-stat-num.flagged-num  { color: var(--red); }
        .hist-stat-num.submitted-num { color: #059669; }
        .hist-stat-num.missed-num    { color: var(--red); }
        .hist-stat-lbl2 { font-size: 0.62rem; font-weight: 700; color: var(--ink-faint); text-transform: uppercase; letter-spacing: 0.06em; margin-top: 2px; }

        /* Doc body — split list + preview */
        .hist-doc-body { flex: 1; min-height: 0; overflow: hidden; display: flex; }
        .hist-split-body { flex: 1; display: grid; grid-template-columns: 260px 1fr; min-height: 0; overflow: hidden; }

        .hist-list-pane { background: white; border-right: 1px solid var(--border); overflow-y: auto; display: flex; flex-direction: column; }
        .hist-list-empty { padding: 40px 16px; text-align: center; color: var(--ink-faint); font-size: 0.82rem; }
        .hist-list-item { padding: 10px 12px; border-bottom: 1px solid var(--border-light); cursor: pointer; transition: background 0.12s; display: flex; flex-direction: column; gap: 5px; }
        .hist-list-item:hover { background: var(--surface-soft); }
        .hist-list-item.active { background: var(--blue-light); border-left: 3px solid var(--blue); padding-left: 9px; }
        .hist-list-week { font-size: 0.8rem; font-weight: 700; color: var(--ink); }
        .hist-list-sub  { font-size: 0.68rem; color: var(--ink-faint); }
        .hist-list-badge { display: inline-flex; align-items: center; font-size: 0.65rem; font-weight: 700; padding: 2px 8px; border-radius: 99px; width: fit-content; }
        .hist-list-badge.pending         { background: var(--blue-light); color: var(--blue); }
        .hist-list-badge.wrong-document  { background: var(--red-light); color: var(--red); }
        .hist-list-badge.lack-of-details { background: var(--amber-light); color: var(--amber); }
        .hist-list-badge.inaccurate      { background: #ede9fe; color: #5b21b6; }
        .hist-list-badge.good            { background: var(--teal-light); color: var(--teal-dark); }

        .hist-detail-pane { overflow-y: auto; padding: 0; display: flex; flex-direction: column; background: var(--surface-warm); }
        .hist-detail-empty { flex: 1; display: flex; flex-direction: column; align-items: center; justify-content: center; color: var(--ink-faint); font-size: 0.85rem; gap: 10px; padding: 40px; }
        .hist-detail-empty i { font-size: 2rem; color: #c4b5fd; }

        .hist-detail-header { padding: 14px 20px 10px; border-bottom: 1px solid var(--border); background: white; flex-shrink: 0; }
        .hist-detail-week { font-size: 0.97rem; font-weight: 800; color: var(--ink); }
        .hist-detail-sub  { font-size: 0.72rem; color: var(--ink-faint); margin-top: 4px; text-align: center; }
        .hist-detail-badges { display: flex; gap: 6px; margin-top: 8px; flex-wrap: wrap; align-items: center; }
        .hist-dpill { font-size: 0.72rem; font-weight: 700; padding: 3px 10px; border-radius: 99px; }
        .hist-dpill.pending         { background: var(--blue-light); color: var(--blue); }
        .hist-dpill.wrong-document  { background: var(--red-light); color: var(--red); }
        .hist-dpill.lack-of-details { background: var(--amber-light); color: var(--amber); }
        .hist-dpill.inaccurate      { background: #ede9fe; color: #5b21b6; }
        .hist-dpill.good            { background: var(--teal-light); color: var(--teal-dark); }

        /* Report action buttons (Print / Save as PDF / Feedback) — sit
           directly under the "Submitted <date>" line, centered. Feedback
           was moved here from the top toolbar so all three report-level
           actions are aligned together in one row. */
        .hist-detail-actions { display: flex; align-items: center; justify-content: center; gap: 10px; margin-top: 10px; flex-wrap: wrap; }
        .hist-report-action-btn { display: inline-flex; align-items: center; gap: 7px; padding: 8px 18px; border-radius: 8px; font-size: 0.8rem; font-weight: 700; cursor: pointer; border: none; font-family: inherit; transition: background 0.15s, opacity 0.15s, transform 0.15s; }
        .hist-report-action-print { background: #1a4a8a; color: #fff; }
        .hist-report-action-print:hover:not(:disabled) { background: #153c6f; transform: translateY(-1px); }
        .hist-report-action-pdf { background: #2563eb; color: #fff; }
        .hist-report-action-pdf:hover:not(:disabled) { background: #1d4ed8; transform: translateY(-1px); }
        .hist-report-action-feedback { background: var(--surface-soft); color: var(--ink-muted); border: 1.5px solid var(--border); }
        .hist-report-action-feedback:hover:not(:disabled) { background: #eceae3; transform: translateY(-1px); }
        .hist-report-action-feedback.has-feedback { background: var(--amber-light); border-color: #fcd34d; color: #92400e; }
        .hist-report-action-btn:disabled { opacity: 0.5; cursor: not-allowed; transform: none; }

        .hist-detail-body { padding: 16px 20px; display: flex; flex-direction: column; gap: 14px; flex: 1; min-height: 0; }
        .hist-preview-section { background: white; border-radius: 10px; border: 1px solid var(--border); padding: 14px 16px; flex: 1; display: flex; flex-direction: column; min-height: 0; }
        .hist-preview-title { font-size: 0.72rem; font-weight: 700; text-transform: uppercase; letter-spacing: 0.07em; color: var(--ink-faint); margin-bottom: 10px; flex-shrink: 0; }
        .hist-report-preview { border: 1px solid var(--border); border-radius: 8px; overflow: hidden; background: var(--surface-soft); flex: 1; display: flex; min-height: 0; }
        .hist-report-preview-frame { width: 100%; height: 100%; min-height: 60vh; border: none; display: block; background: #fff; }
        .hist-report-preview-loading { display: flex; align-items: center; justify-content: center; gap: 10px; width: 100%; min-height: 320px; color: var(--ink-faint); font-size: 0.85rem; }
        .hist-report-preview-empty { display: flex; flex-direction: column; align-items: center; justify-content: center; gap: 8px; width: 100%; min-height: 200px; color: var(--ink-faint); font-size: 0.85rem; padding: 30px; text-align: center; }
        .hist-report-preview-empty i { font-size: 1.8rem; color: #d1d5db; }
        .hist-spinner { width: 22px; height: 22px; border: 3px solid var(--border); border-top-color: var(--blue); border-radius: 50%; animation: hist-spin 0.7s linear infinite; }
        @keyframes hist-spin { to { transform: rotate(360deg); } }

        /* Feedback drawer (read-only — coordinator's feedback only) */
        .hist-comment-drawer-backdrop { display: none; position: fixed; inset: 0; background: rgba(0,0,0,0.25); z-index: 1240; }
        .hist-comment-drawer-backdrop.show { display: block; }
        .hist-comment-drawer {
            position: fixed; top: 0; right: -400px; width: 380px; max-width: 92vw; height: 100vh;
            background: var(--surface-soft); box-shadow: -6px 0 28px rgba(0,0,0,0.22);
            z-index: 1250; transition: right 0.25s ease;
            display: flex; flex-direction: column;
        }
        .hist-comment-drawer.open { right: 0; }
        .hist-comment-drawer-header {
            background: linear-gradient(135deg, var(--maroon), var(--active-nav));
            color: white; padding: 14px 18px;
            display: flex; align-items: center; justify-content: space-between;
            font-weight: 700; font-size: 0.88rem; flex-shrink: 0;
        }
        .hist-comment-drawer-header button {
            background: rgba(255,255,255,0.14); border: none; color: white;
            width: 28px; height: 28px; border-radius: 50%; font-size: 0.95rem;
            cursor: pointer; display: flex; align-items: center; justify-content: center;
            transition: background 0.15s;
        }
        .hist-comment-drawer-header button:hover { background: rgba(255,255,255,0.26); }
        .hist-comment-drawer-body { padding: 16px 18px; overflow-y: auto; flex: 1; }
        .hist-comment-drawer-empty { color: var(--ink-faint); font-size: 0.85rem; text-align: center; padding: 40px 10px; }
        .hist-comment-section { background: white; border-radius: 10px; border: 1px solid var(--border); padding: 14px 16px; }
        .hist-comment-title { font-size: 0.72rem; font-weight: 700; text-transform: uppercase; letter-spacing: 0.07em; color: var(--ink-faint); margin-bottom: 8px; }
        .hist-comment-display { font-size: 0.82rem; color: var(--ink-muted); background: var(--surface-soft); border-radius: 6px; padding: 8px 10px; line-height: 1.55; min-height: 36px; }
        .hist-comment-display.empty { color: var(--ink-faint); font-style: italic; }

        @media (max-width: 700px) {
            .hist-split-body { grid-template-columns: 1fr; }
            .hist-list-pane { max-height: 180px; border-right: none; border-bottom: 1px solid var(--border); }
            .hist-comment-drawer { width: 100%; max-width: 100%; right: -100%; }
            .hist-doc-toolbar-right { gap: 6px; }
            .hist-stats-row { flex-direction: column; }
        }

        /* DTR PREVIEW MODAL */
        #dtrPreviewModal {
            display: none; position: fixed; inset: 0; z-index: 20000;
            background: rgba(7,20,95,0.65); backdrop-filter: blur(4px);
            align-items: center; justify-content: center; padding: 16px;
        }
        #dtrPreviewModal.open { display: flex; }
        .dtr-modal-box {
            background: #f0f4f8; border-radius: 16px;
            width: calc(100% - 32px); max-width: 1100px;
            height: calc(100vh - 32px); max-height: 96vh;
            display: flex; flex-direction: column;
            box-shadow: 0 24px 80px rgba(7,20,95,0.28); overflow: hidden;
            animation: dtrModalPop 0.3s cubic-bezier(.34,1.56,.64,1) both;
        }
        @keyframes dtrModalPop {
            from { opacity:0; transform:scale(0.92) translateY(20px); }
            to   { opacity:1; transform:scale(1)    translateY(0); }
        }
        .dtr-modal-head {
            background: #065f46; padding: 13px 18px;
            display: flex; align-items: center; justify-content: space-between;
            gap: 12px; flex-shrink: 0; flex-wrap: wrap;
        }
        .dtr-modal-head-left { display: flex; align-items: center; gap: 10px; min-width: 0; }
        .dtr-mh-icon { font-size: 18px; flex-shrink: 0; color: #fff; }
        .dtr-mh-info strong { color:#fff; font-size:14px; display:block; line-height:1.2; }
        .dtr-mh-info span   { color:rgba(255,255,255,0.72); font-size:11px; display:block; margin-top:1px; }
        .dtr-modal-head-right { display: flex; gap: 8px; align-items: center; flex-wrap: wrap; }
        .dtr-month-select {
            border: 1.5px solid rgba(255,255,255,0.35); border-radius: 7px;
            padding: 6px 28px 6px 10px; font-size: 12px; font-family: inherit;
            background: rgba(255,255,255,0.15); color: #fff; cursor: pointer;
            font-weight: 600; min-width: 150px;
            appearance: none; -webkit-appearance: none;
            background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='10' height='10' viewBox='0 0 10 10'%3E%3Cpath fill='%23ffffff' d='M5 7L1 3h8z'/%3E%3C/svg%3E");
            background-repeat: no-repeat; background-position: right 10px center;
        }
        .dtr-month-select option { background: #065f46; color: #fff; }
        .dtr-month-select:focus { outline: none; box-shadow: 0 0 0 2px rgba(255,255,255,0.4); }
        .dtr-mh-btn {
            padding: 7px 14px; border-radius: 7px; font-size: 12px; font-weight: 700;
            cursor: pointer; border: none; font-family: inherit;
            display: inline-flex; align-items: center; gap: 5px; transition: opacity 0.2s;
            white-space: nowrap;
        }
        .dtr-mh-btn:hover { opacity: 0.88; }
        .dtr-mh-btn-close { background: rgba(255,255,255,0.13); color: #e2e8f0; border: 1px solid rgba(255,255,255,0.18); }
        .dtr-iframe-wrap { flex: 1; overflow: hidden; background: #eef1f8; position: relative; }
        #dtrPreviewIframe { width: 100%; height: 100%; border: none; display: block; min-height: 0; }
        .dtr-loading-overlay {
            position: absolute; inset: 0;
            display: flex; flex-direction: column; align-items: center; justify-content: center;
            background: #eef1f8; gap: 14px; font-size: 0.88rem;
            color: #4a5568; pointer-events: none; transition: opacity 0.3s;
        }
        .dtr-loading-overlay.hidden { opacity: 0; }
        .dtr-spinner { width: 36px; height: 36px; border: 4px solid #c6d4e3; border-top-color: #065f46; border-radius: 50%; animation: dtr-spin 0.75s linear infinite; }
        @keyframes dtr-spin { to { transform: rotate(360deg); } }

        /* SUBMISSION RESULT POPUP */
        .submit-popup-overlay { display: none; position: fixed; inset: 0; z-index: 4000; background: rgba(0,0,0,0.55); align-items: center; justify-content: center; padding: 20px; }
        .submit-popup-overlay.open { display: flex; }
        .submit-popup-box { background: white; border-radius: 20px; width: 100%; max-width: 480px; box-shadow: 0 16px 60px rgba(0,0,0,0.25); overflow: hidden; animation: popupSlideUp 0.28s cubic-bezier(.34,1.56,.64,1); }
        @keyframes popupSlideUp { from{transform:translateY(32px) scale(0.95);opacity:0;} to{transform:translateY(0) scale(1);opacity:1;} }
        .submit-popup-icon { display: flex; align-items: center; justify-content: center; padding: 32px 0 24px; font-size: 3.2rem; }
        .submit-popup-icon.success { background: var(--teal-light); }
        .submit-popup-icon.error   { background: var(--red-light); }
        .submit-popup-content { padding: 0 28px 28px; text-align: center; }
        .submit-popup-content h3 { font-size: 1.15rem; font-weight: 800; color: var(--ink); margin-bottom: 10px; font-family: 'Lora', serif; }
        .submit-popup-content p  { font-size: 0.9rem; color: var(--ink-muted); line-height: 1.65; margin-bottom: 16px; }
        .submit-popup-detail { background: var(--surface-soft); border: 1px solid var(--border); border-radius: var(--radius-md); padding: 10px 14px; margin-bottom: 16px; text-align: left; font-size: 0.78rem; color: var(--ink-faint); font-family: monospace; word-break: break-all; max-height: 120px; overflow-y: auto; display: none; }
        .submit-popup-detail.visible { display: block; }
        .btn-toggle-detail { background: none; border: none; color: var(--blue); font-size: 0.78rem; cursor: pointer; font-family: inherit; text-decoration: underline; margin-bottom: 16px; display: none; }
        .btn-toggle-detail.visible { display: inline-block; }
        .submit-popup-actions { display: flex; gap: 10px; }
        .btn-popup-ok { flex: 1; padding: 13px; background: linear-gradient(135deg, var(--teal), var(--blue)); color: white; border: none; border-radius: var(--radius-md); font-size: 0.95rem; font-weight: 700; cursor: pointer; font-family: inherit; }
        .btn-popup-ok:hover { opacity: 0.88; }
        .btn-popup-ok.error-btn { background: linear-gradient(135deg, #ef4444, #dc2626); }
        .btn-popup-retry { flex: 1; padding: 13px; background: white; color: var(--blue); border: 1.5px solid var(--blue); border-radius: var(--radius-md); font-size: 0.95rem; font-weight: 700; cursor: pointer; font-family: inherit; }
        .btn-popup-retry:hover { background: var(--blue-light); }

        /* SUBMIT CONFIRM MODAL */
        .confirm-overlay { display: none; position: fixed; inset: 0; z-index: 5000; background: rgba(0,0,0,0.58); align-items: center; justify-content: center; padding: 20px; }
        .confirm-overlay.open { display: flex; }
        .confirm-box { background: white; border-radius: 20px; width: 100%; max-width: 440px; box-shadow: 0 20px 64px rgba(0,0,0,0.28); overflow: hidden; animation: popupSlideUp 0.26s cubic-bezier(.34,1.56,.64,1); }
        .confirm-header { background: var(--maroon); padding: 22px 24px 18px; display: flex; flex-direction: column; gap: 6px; }
        .confirm-header-icon { font-size: 2rem; line-height: 1; }
        .confirm-header-title { font-size: 1.05rem; font-weight: 700; color: white; font-family: 'Lora', serif; }
        .confirm-body { padding: 18px 24px; }
        .confirm-body p { font-size: 0.88rem; color: var(--ink-muted); line-height: 1.65; margin-bottom: 12px; }
        .confirm-warning { background: var(--amber-light); border: 1.5px solid #fcd34d; border-radius: var(--radius-md); padding: 11px 14px; font-size: 0.83rem; color: #78350f; line-height: 1.55; display: flex; align-items: flex-start; gap: 8px; }
        .confirm-warning-icon { font-size: 1rem; flex-shrink: 0; margin-top: 1px; }
        .confirm-footer { display: flex; gap: 10px; padding: 0 24px 22px; }
        .btn-confirm-cancel { flex: 1; padding: 12px; background: var(--surface-soft); color: var(--ink-muted); border: 1.5px solid var(--border); border-radius: var(--radius-md); font-size: 0.9rem; font-weight: 600; cursor: pointer; font-family: inherit; transition: background 0.2s; }
        .btn-confirm-cancel:hover { background: #e5e7eb; }
        .btn-confirm-submit { flex: 1; padding: 12px; background: linear-gradient(135deg, var(--teal), var(--blue)); color: white; border: none; border-radius: var(--radius-md); font-size: 0.9rem; font-weight: 700; cursor: pointer; font-family: inherit; transition: opacity 0.2s, transform 0.15s; }
        .btn-confirm-submit:hover { opacity: 0.88; transform: translateY(-1px); }

        /* UNSAVED MODAL */
        .unsaved-overlay { display: none; position: fixed; inset: 0; z-index: 3000; background: rgba(0,0,0,0.55); align-items: center; justify-content: center; padding: 20px; }
        .unsaved-overlay.open { display: flex; }
        .unsaved-box { background: white; border-radius: 18px; width: 100%; max-width: 400px; box-shadow: 0 12px 48px rgba(0,0,0,0.22); overflow: hidden; animation: slideUp 0.22s ease; }
        @keyframes slideUp { from{transform:translateY(20px);opacity:0;} to{transform:translateY(0);opacity:1;} }
        .unsaved-icon { background: var(--amber-light); display: flex; align-items: center; justify-content: center; padding: 24px 0 20px; font-size: 2.4rem; }
        .unsaved-content { padding: 20px 24px 24px; text-align: center; }
        .unsaved-content h3 { font-size: 1.05rem; font-weight: 700; color: var(--ink); margin-bottom: 8px; font-family: 'Lora', serif; }
        .unsaved-content p  { font-size: 0.86rem; color: var(--ink-muted); line-height: 1.6; margin-bottom: 20px; }
        .unsaved-actions { display: flex; flex-direction: column; gap: 10px; }
        .btn-unsaved-save { background: linear-gradient(135deg, var(--teal), var(--blue)); color: white; border: none; border-radius: var(--radius-md); padding: 12px; font-size: 0.9rem; font-weight: 700; cursor: pointer; font-family: inherit; }
        .btn-unsaved-discard { background: var(--surface-soft); color: var(--ink-muted); border: 1.5px solid var(--border); border-radius: var(--radius-md); padding: 11px; font-size: 0.88rem; font-weight: 600; cursor: pointer; font-family: inherit; }
        .btn-unsaved-cancel { background: none; border: none; color: var(--ink-faint); font-size: 0.82rem; cursor: pointer; font-family: inherit; padding: 6px; text-decoration: underline; }
    </style>
</head>
<body>

<!-- SIDEBAR -->
<div id="sidebar" class="sidebar">
    <div class="sidebar-header">
        <div class="sidebar-user-info">
            <span class="sidebar-user-name" title="<?php echo htmlspecialchars($full_name); ?>"><?php echo htmlspecialchars($full_name); ?></span>
            <span class="sidebar-user-role">OJT Trainee</span>
        </div>
        <button id="toggleBtn" class="toggle-btn"><i class="fas fa-bars"></i></button>
    </div>
    <div class="sidebar-links">
        <a href="student_profile.php"><i class="fas fa-user-circle"></i><span class="link-text">My Profile</span></a>
        <a href="company_list.php"><i class="fas fa-building"></i><span class="link-text">Company List</span></a>
        <a href="AccomForm.php"><i class="fas fa-file-contract"></i><span class="link-text">Requirements</span></a>
        <a href="student_attendance.php">
            <i class="fas fa-calendar-check"></i><span class="link-text">Attendance</span>
            <?php if (!empty($att_sidebar_badge)): ?><span class="sidebar-badge-att">!</span><?php endif; ?>
        </a>
        <a href="student_report.php" class="active">
            <i class="fas fa-chart-bar"></i><span class="link-text">Reports</span>
            <?php if (!$disableSubmit): ?><span class="sidebar-badge-journal" id="journalEmptyBadge" style="display:none;"></span><?php endif; ?>
        </a>
        <a href="student_dashboard.php"><i class="fas fa-tachometer-alt"></i><span class="link-text">Dashboard</span></a>
    </div>
    <div class="logout-link">
        <a href="login.php"><i class="fas fa-sign-out-alt"></i><span class="link-text" style="margin-left:10px;">Logout</span></a>
    </div>
</div>

<!-- ATTENDANCE NOTIFICATION BAR -->
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

<!-- SUBMIT CONFIRM MODAL -->
<div class="confirm-overlay" id="confirmOverlay">
    <div class="confirm-box">
        <div class="confirm-header">
            <div class="confirm-header-icon"></div>
            <div class="confirm-header-title">Submit Weekly Report?</div>
        </div>
        <div class="confirm-body">
            <p>You are about to submit your report for this week. Please make sure all your entries are complete and accurate.</p>
            <div class="confirm-warning">
                <span class="confirm-warning-icon"></span>
                <span>This <strong>cannot be edited</strong> after submission. Review all entries before confirming.</span>
            </div>
        </div>
        <div class="confirm-footer">
            <button class="btn-confirm-cancel" id="confirmCancelBtn">Cancel</button>
            <button class="btn-confirm-submit" id="confirmSubmitBtn">Yes, Submit</button>
        </div>
    </div>
</div>

<!-- SUBMISSION RESULT POPUP -->
<div class="submit-popup-overlay" id="submitPopupOverlay">
    <div class="submit-popup-box" id="submitPopupBox">
        <div class="submit-popup-icon" id="submitPopupIcon"></div>
        <div class="submit-popup-content">
            <h3 id="submitPopupTitle">Report Submitted!</h3>
            <p id="submitPopupMessage">Your weekly report was submitted successfully!</p>
            <button class="btn-toggle-detail" id="submitPopupDetailToggle" onclick="togglePopupDetail()">Show technical details</button>
            <div class="submit-popup-detail" id="submitPopupDetail"></div>
            <div class="submit-popup-actions" id="submitPopupActions">
                <button class="btn-popup-ok" id="submitPopupOkBtn" onclick="closeSubmitPopup()">Done</button>
            </div>
        </div>
    </div>
</div>

<!-- MAIN CONTENT -->
<div class="main-content" id="mainContent">

    <nav class="navbar">
        <img src="logo.webp" style="height:40px;margin-right:15px;" alt="NEUST Logo">
        <div>
            <div style="font-weight:bold;font-size:16px;">NEUST Atate Campus</div>
            <div style="font-size:11px;color:var(--gold);">Web-Based Smart OJT Monitoring and Supervision Analytics System</div>
        </div>
    </nav>

    <!-- TOOLBAR -->
    <div class="page-toolbar">
        <button class="dtr-btn" id="btnOpenDTR" onclick="openDTRModal()">
            <i class="fas fa-calendar-alt"></i> View DTR (CS Form 48)
        </button>
        <button class="history-btn" onclick="openHistoryModal()">
            <i class="fas fa-history"></i> Report History
            <span class="badge" id="histBadge" <?php echo count($history) == 0 ? 'style="display:none"' : ''; ?>><?php echo count($history); ?></span>
        </button>
    </div>

    <!-- Flash -->
    <div class="flash" id="flashWrap" style="<?php echo $flash ? '' : 'display:none'; ?>">
        <div class="flash-msg" id="flashMsg"><?php echo $flash; ?></div>
    </div>

    <div class="container">

        <!-- Week card -->
        <div class="week-card">
            <div class="week-card-left">
                <div class="week-icon"><i class="fas fa-calendar-week"></i></div>
                <div>
                    <div class="week-label">Current Week</div>
                    <div class="week-date"><?php echo date("M d", strtotime($week_start)); ?> &ndash; <?php echo date("M d, Y", strtotime($week_end)); ?></div>
                </div>
            </div>
            <span class="week-pill <?php echo $disableSubmit ? 'done' : ($is_friday ? 'open' : 'locked'); ?>" id="weekPill">
                <?php echo $disableSubmit ? 'Submitted' : ($is_friday ? 'Open for Submission' : 'Submit on Friday'); ?>
            </span>
        </div>

        <!-- Report Form -->
        <form method="POST" action="student_report.php" id="journalForm">
            <input type="hidden" name="submit_journal" value="1">
            <input type="hidden" name="week_start"     value="<?php echo $week_start; ?>">
            <input type="hidden" name="company_id"     value="<?php echo $company_id; ?>">

            <!-- Training Station (NEW) — free-text value submitted along
                 with the report and rendered into the generated document's
                 "Training Station" field via buildWeeklyReportHTML(). -->
            <div class="training-card">
                <div class="training-card-label">
                    <i class="fas fa-map-marker-alt"></i>
                    Training Station
                </div>
                <input
                    type="text"
                    name="training_station"
                    id="trainingStationInput"
                    class="training-input"
                    placeholder="e.g. IT Department, 3rd Floor Admin Building"
                    autocomplete="off"
                    maxlength="150"
                    <?php echo $disableSubmit ? 'disabled' : ''; ?>>
            </div>

            <div class="timeline-wrap">
                <div class="timeline-line"></div>

                <?php foreach ($days as $i => $date):
                    $att        = $attendance[$date] ?? null;
                    $is_present = false;
                    if ($att) {
                        $is_present = hasRealValue($att['am_time_in'])
                                   || hasRealValue($att['am_time_out'])
                                   || hasRealValue($att['pm_time_in'])
                                   || hasRealValue($att['pm_time_out']);
                    }
                    $disabled   = (!$is_present || $disableSubmit) ? 'disabled' : '';
                    $day_key    = strtolower($day_labels[$i]);
                    $day_abbr   = strtoupper(substr($day_labels[$i], 0, 1));
                ?>
                <div class="tl-item">
                    <div class="tl-dot <?php echo $disableSubmit ? 'submitted' : ($is_present ? 'present' : 'absent'); ?>">
                        <?php if ($disableSubmit): ?>
                            <i class="fas fa-check" style="font-size:.7rem;"></i>
                        <?php elseif ($is_present): ?>
                            <?php echo $day_abbr; ?>
                        <?php else: ?>
                            <i class="fas fa-minus" style="font-size:.65rem;"></i>
                        <?php endif; ?>
                    </div>

                    <div class="tl-card <?php echo !$is_present ? 'absent-card' : ''; ?>">
                        <div class="tl-card-head">
                            <div class="tl-card-head-left">
                                <div class="tl-day-name"><?php echo $day_labels[$i]; ?></div>
                                <div class="tl-day-date"><?php echo date("F d, Y", strtotime($date)); ?></div>
                            </div>
                            <div class="tl-card-head-right">
                                <?php if ($is_present): ?>
                                    <div class="tl-shift-info">
                                        <i class="fas fa-clock"></i>
                                        <?php echo $duty_start; ?> &ndash; <?php echo $duty_end; ?>
                                    </div>
                                    <span class="tl-att-badge <?php echo $disableSubmit ? 'done' : 'present'; ?>">
                                        <?php echo $disableSubmit ? 'Done' : 'Present'; ?>
                                    </span>
                                <?php else: ?>
                                    <span class="tl-att-badge absent">Absent</span>
                                <?php endif; ?>
                            </div>
                        </div>

                        <div class="tl-card-body">
                            <div class="field-label">
                                <i class="fas fa-tasks"></i>
                                Tasks / Activities Done
                            </div>
                            <textarea
                                name="tasks_<?php echo $day_key; ?>"
                                id="tasks_<?php echo $day_key; ?>"
                                class="tl-textarea"
                                placeholder="<?php echo $is_present
                                    ? 'Describe the tasks and activities you accomplished today...'
                                    : 'No entry — absent this day'; ?>"
                                <?php echo $disabled; ?>></textarea>

                            <?php if ($is_present && !$disabled): ?>
                            <div class="counter-row">
                                <span><i class="fas fa-keyboard" style="font-size:.62rem;margin-right:3px;"></i><span id="char_count_<?php echo $day_key; ?>">0</span> chars</span>
                                <span><i class="fas fa-font" style="font-size:.62rem;margin-right:3px;"></i><span id="word_count_<?php echo $day_key; ?>">0</span> words</span>
                            </div>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>

            <!-- Submit Section -->
            <div class="submit-section" id="submitSection">
                <div>
                    <div class="submit-note" id="submitNote">
                        <?php if ($existing && $existing['remark'] == "Wrong Document"): ?>
                            &nbsp;
                        <?php elseif ($disableSubmit): ?>
                            <strong>Report already submitted</strong> for this week.
                        <?php elseif (!$is_friday): ?>
                            Report can only be submitted on <strong>Friday</strong>.
                        <?php else: ?>
                            Review your entries carefully. <strong>Cannot be edited after submission.</strong>
                        <?php endif; ?>
                    </div>
                    <?php if (!$disableSubmit): ?>
                    <div class="draft-row" id="draftStatus" style="margin-top:6px;">
                        <span class="draft-dot" id="draftDot"></span>
                        <span id="draftText">No draft saved</span>
                    </div>
                    <?php endif; ?>
                </div>
                <div class="btn-row" id="btnRow">
                    <?php if ($existing && $existing['remark'] == "Wrong Document"): ?>
                        <div class="rejected-msg">Rejected — please resubmit.</div>
                        <button type="button" class="btn-save-draft" onclick="saveDraft()">Save Draft</button>
                        <button type="submit" class="btn-submit" id="submitBtn">Resubmit Report</button>
                    <?php elseif ($disableSubmit): ?>
                        <div class="submitted-msg">Report submitted this week</div>
                    <?php else: ?>
                        <button type="button" class="btn-save-draft" onclick="saveDraft()">Save Draft</button>
                        <button type="submit" class="btn-submit" id="submitBtn" <?php echo !$is_friday ? 'disabled' : ''; ?>>Submit Weekly Report</button>
                    <?php endif; ?>
                </div>
            </div>
        </form>
    </div>
</div>

<!-- ══════════════════════════════════════════════════════════════
     REPORT HISTORY — FULL-SCREEN DOCUMENT VIEW (library style)
══════════════════════════════════════════════════════════════ -->
<div class="hist-overlay" id="histOverlay">
    <div class="hist-doc-toolbar">
        <div class="hist-doc-toolbar-left">
            <i class="fas fa-history"></i>
            <div>
                <div class="hist-doc-toolbar-title">Report History</div>
                <div class="hist-doc-toolbar-sub"><?php echo htmlspecialchars($full_name); ?></div>
            </div>
        </div>
        <div class="hist-doc-toolbar-right">
            <button class="hist-tbtn hist-tbtn-close" onclick="closeHistoryModalDirect()"><i class="fas fa-times"></i> Close</button>
        </div>
    </div>

    <!-- Summary bar — weekly report compliance cards: Total / Submitted / Not Submitted -->
    <div class="hist-summary-bar">
        <div class="hist-stats-row">
            <div class="hist-stat-card">
                <div class="hist-stat-icon total"><i class="fas fa-calendar-week"></i></div>
                <div class="hist-stat-text">
                    <div class="hist-stat-num" id="histStatTotalVal"><?php echo $report_stats['total_expected']; ?></div>
                    <div class="hist-stat-lbl2">Total</div>
                </div>
            </div>
            <div class="hist-stat-card">
                <div class="hist-stat-icon submitted"><i class="fas fa-check"></i></div>
                <div class="hist-stat-text">
                    <div class="hist-stat-num submitted-num" id="histStatSubmittedVal"><?php echo $report_stats['submitted']; ?></div>
                    <div class="hist-stat-lbl2">Submitted</div>
                </div>
            </div>
            <div class="hist-stat-card">
                <div class="hist-stat-icon missed"><i class="fas fa-xmark"></i></div>
                <div class="hist-stat-text">
                    <div class="hist-stat-num missed-num" id="histStatMissedVal"><?php echo $report_stats['missed']; ?></div>
                    <div class="hist-stat-lbl2">Not Submitted</div>
                </div>
            </div>
        </div>
    </div>

    <!-- Doc body: split list + preview -->
    <div class="hist-doc-body">
        <div class="hist-split-body" id="histSplitBody">
            <!-- Left: report list -->
            <div class="hist-list-pane" id="histListPane">
                <?php if (empty($history)): ?>
                    <div class="hist-list-empty">No reports submitted yet.</div>
                <?php else: foreach ($history as $idx => $rep):
                    $monTs    = strtotime($rep['week_start']);
                    $friTs    = strtotime('+4 days', $monTs);
                    $weekRange = date("M d", $monTs) . ' – ' . date("M d, Y", $friTs);
                ?>
                <div class="hist-list-item" id="hlist-<?php echo $rep['id']; ?>"
                     onclick="selectHistoryReport(<?php echo $rep['id']; ?>)">
                    <div class="hist-list-week"><?php echo $weekRange; ?></div>
                    <div class="hist-list-sub"><?php echo date("M d, Y", strtotime($rep['submitted_at'])); ?></div>
                </div>
                <?php endforeach; endif; ?>
            </div>

            <!-- Right: large report preview -->
            <div class="hist-detail-pane" id="histDetailPane">
                <div class="hist-detail-empty" id="histDetailEmpty">
                    <i class="fas fa-hand-point-left"></i>
                    <span>Select a report from the list</span>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- FEEDBACK DRAWER (read-only — coordinator's feedback only) -->
<div class="hist-comment-drawer-backdrop" id="histCommentDrawerBackdrop" onclick="toggleFeedbackDrawer(false)"></div>
<div class="hist-comment-drawer" id="histCommentDrawer">
    <div class="hist-comment-drawer-header">
        <span><i class="fas fa-comment-alt"></i> Coordinator Feedback</span>
        <button onclick="toggleFeedbackDrawer(false)"><i class="fas fa-times"></i></button>
    </div>
    <div class="hist-comment-drawer-body" id="histCommentDrawerBody">
        <div class="hist-comment-drawer-empty">Select a report to view its feedback.</div>
    </div>
</div>

<!-- DTR PREVIEW MODAL — CS Form 48 -->
<div id="dtrPreviewModal" onclick="closeDTRModalOnOverlay(event)">
    <div class="dtr-modal-box">
        <div class="dtr-modal-head">
            <div class="dtr-modal-head-left">
                <i class="fas fa-calendar-alt dtr-mh-icon"></i>
                <div class="dtr-mh-info">
                    <strong>Daily Time Record</strong>
                    <span>CS Form 48 &mdash; <span id="dtrModalMonthLabel">Select a month</span></span>
                </div>
            </div>
            <div class="dtr-modal-head-right">
                <select id="dtrMonthSelect" class="dtr-month-select" onchange="onDTRMonthChange()">
                    <?php if (empty($dtr_months)): ?>
                        <option value="<?php echo date('Y-m'); ?>"><?php echo date('F Y'); ?></option>
                    <?php else: foreach ($dtr_months as $dm): ?>
                        <option value="<?php echo htmlspecialchars($dm['ym']); ?>"><?php echo htmlspecialchars($dm['label']); ?></option>
                    <?php endforeach; endif; ?>
                </select>
                <button class="dtr-mh-btn dtr-mh-btn-close" onclick="closeDTRModal()">Close</button>
            </div>
        </div>
        <div class="dtr-iframe-wrap">
            <div class="dtr-loading-overlay" id="dtrLoadingOverlay">
                <div class="dtr-spinner"></div>
                <span>Loading attendance record...</span>
            </div>
            <iframe id="dtrPreviewIframe" src="" title="Daily Time Record CS Form 48"></iframe>
        </div>
    </div>
</div>

<!-- UNSAVED DRAFT MODAL -->
<div class="unsaved-overlay" id="unsavedOverlay">
    <div class="unsaved-box">
        <div class="unsaved-icon"></div>
        <div class="unsaved-content">
            <h3>You have unsaved entries!</h3>
            <p>You've typed in one or more report fields but haven't saved a draft yet. Save your progress before leaving?</p>
            <div class="unsaved-actions">
                <button class="btn-unsaved-save" onclick="unsavedSaveAndStay()">Save Draft &amp; Stay</button>
                <button class="btn-unsaved-discard" onclick="unsavedDiscard()">Leave Without Saving</button>
                <button class="btn-unsaved-cancel" onclick="unsavedCancel()">Cancel — go back</button>
            </div>
        </div>
    </div>
</div>

<div class="refresh-indicator" id="refreshIndicator">
    <span class="refresh-dot"></span> Syncing...
</div>

<script>
/* ══════════════════════════════════════════════════════════════
   PHP DATA → JS
══════════════════════════════════════════════════════════════ */
const INIT_HISTORY   = <?php echo json_encode($history); ?>;
const IS_SUBMITTED   = <?php echo $disableSubmit ? 'true' : 'false'; ?>;
const DRAFT_KEY      = <?php echo json_encode($draft_key); ?>;
const dayKeys        = ['monday','tuesday','wednesday','thursday','friday'];

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
    document.getElementById('att-notif-bar')
            .classList.toggle('sidebar-collapsed', sidebar.classList.contains('collapsed'));
});

/* ── DRAFT LOGIC ── */
function saveDraft() {
    const draft = {};
    dayKeys.forEach(d => { const t = document.getElementById('tasks_' + d); if (t && !t.disabled) draft['tasks_' + d] = t.value; });
    const tsInputSave = document.getElementById('trainingStationInput');
    if (tsInputSave && !tsInputSave.disabled) draft['training_station'] = tsInputSave.value;
    localStorage.setItem(DRAFT_KEY, JSON.stringify(draft));
    _isDirty = false;
    setDraftStatus('saved');
    showSubmitPopup('success', 'Draft Saved', 'Your report entries have been saved locally. You can continue editing anytime.', false, null);
}
function loadDraft() {
    if (IS_SUBMITTED) return;
    const raw = localStorage.getItem(DRAFT_KEY);
    if (!raw) return;
    try {
        const draft = JSON.parse(raw);
        dayKeys.forEach(d => { const t = document.getElementById('tasks_' + d); if (t && !t.disabled && draft['tasks_' + d] !== undefined) t.value = draft['tasks_' + d]; });
        const tsInputLoad = document.getElementById('trainingStationInput');
        if (tsInputLoad && !tsInputLoad.disabled && draft['training_station'] !== undefined) tsInputLoad.value = draft['training_station'];
        setDraftStatus('saved');
        dayKeys.forEach(d => { const t = document.getElementById('tasks_' + d); if (t && !t.disabled) updateCounters('tasks_' + d); });
    } catch(e) {}
}
function clearDraft() { localStorage.removeItem(DRAFT_KEY); }
function setDraftStatus(state) {
    const dot = document.getElementById('draftDot'); const text = document.getElementById('draftText');
    if (!dot || !text) return;
    if (state === 'saved') { dot.className = 'draft-dot saved'; text.textContent = 'Draft saved — ' + new Date().toLocaleTimeString([], {hour:'2-digit',minute:'2-digit'}); }
    else { dot.className = 'draft-dot'; text.textContent = 'Unsaved changes'; }
}
function updateCounters(textareaId) {
    const ta = document.getElementById(textareaId); if (!ta || ta.disabled) return;
    const text = ta.value; const charCount = text.length; const wordCount = text.trim() === '' ? 0 : text.trim().split(/\s+/).length;
    const dayPart = textareaId.replace('tasks_', '');
    const charSpan = document.getElementById('char_count_' + dayPart); const wordSpan = document.getElementById('word_count_' + dayPart);
    if (charSpan) { charSpan.textContent = charCount; charSpan.className = charCount > 500 ? 'danger' : charCount > 300 ? 'warn' : ''; }
    if (wordSpan) { wordSpan.textContent = wordCount; wordSpan.className = wordCount > 100 ? 'danger' : wordCount > 60 ? 'warn' : ''; }
}
function initCounters() { dayKeys.forEach(k => { const id = 'tasks_' + k; const ta = document.getElementById(id); if (ta && !ta.disabled) { ta.addEventListener('input', () => updateCounters(id)); updateCounters(id); } }); }

let _isDirty = false; let _allowNavigation = false;
document.querySelectorAll('.tl-textarea:not([disabled])').forEach(ta => { ta.addEventListener('input', () => { setDraftStatus('unsaved'); _isDirty = true; }); });
(function() {
    /* NEW: Training Station field participates in the same "unsaved
       changes" dirty-tracking as the day textareas above, so the
       existing draft/unsaved-changes prompts behave consistently. */
    const tsInputDirty = document.getElementById('trainingStationInput');
    if (tsInputDirty && !tsInputDirty.disabled) {
        tsInputDirty.addEventListener('input', () => { setDraftStatus('unsaved'); _isDirty = true; });
    }
})();
setInterval(() => { if (!IS_SUBMITTED && _isDirty) saveDraftSilent(); }, 60000);
function saveDraftSilent() { const draft = {}; dayKeys.forEach(d => { const t = document.getElementById('tasks_' + d); if (t && !t.disabled) draft['tasks_' + d] = t.value; }); const tsInputSilent = document.getElementById('trainingStationInput'); if (tsInputSilent && !tsInputSilent.disabled) draft['training_station'] = tsInputSilent.value; localStorage.setItem(DRAFT_KEY, JSON.stringify(draft)); _isDirty = false; setDraftStatus('saved'); }
loadDraft(); initCounters();

let _pendingNavUrl = null;
function hasUnsavedContent() { return !IS_SUBMITTED && _isDirty; }
function showUnsavedModal(targetUrl) { _pendingNavUrl = targetUrl || null; document.getElementById('unsavedOverlay').classList.add('open'); }
function hideUnsavedModal() { document.getElementById('unsavedOverlay').classList.remove('open'); }
function unsavedSaveAndStay() { saveDraftSilent(); hideUnsavedModal(); showSubmitPopup('success', 'Draft Saved', 'Your entries have been saved. You can continue editing.', false, null); }
function unsavedDiscard() { _allowNavigation = true; hideUnsavedModal(); if (_pendingNavUrl) window.location.href = _pendingNavUrl; else history.back(); }
function unsavedCancel() { _pendingNavUrl = null; hideUnsavedModal(); }
document.addEventListener('click', function(e) {
    const anchor = e.target.closest('a[href]'); if (!anchor) return;
    const href = anchor.getAttribute('href'); if (!href || href.startsWith('#') || href.startsWith('javascript')) return;
    if (href.includes('dtrdl=1')) return;
    if (!_allowNavigation && hasUnsavedContent()) { e.preventDefault(); showUnsavedModal(anchor.href); }
});
window.addEventListener('beforeunload', function(e) { if (!_allowNavigation && hasUnsavedContent()) { e.preventDefault(); e.returnValue = 'You have unsaved report entries. Leave without saving?'; return e.returnValue; } });

/* ── SUBMISSION POPUP ── */
function togglePopupDetail() { const d = document.getElementById('submitPopupDetail'); const b = document.getElementById('submitPopupDetailToggle'); d.classList.toggle('visible'); b.textContent = d.classList.contains('visible') ? 'Hide technical details' : 'Show technical details'; }
function showSubmitPopup(type, title, message, showRetry, errorDetail) {
    const overlay = document.getElementById('submitPopupOverlay'); const iconEl = document.getElementById('submitPopupIcon'); const titleEl = document.getElementById('submitPopupTitle'); const msgEl = document.getElementById('submitPopupMessage'); const actionsEl = document.getElementById('submitPopupActions'); const okBtn = document.getElementById('submitPopupOkBtn'); const detailEl = document.getElementById('submitPopupDetail'); const detailBtn = document.getElementById('submitPopupDetailToggle');
    iconEl.className = 'submit-popup-icon ' + type; iconEl.textContent = type === 'success' ? '' : ''; titleEl.textContent = title; msgEl.innerHTML = message;
    if (errorDetail) { detailEl.textContent = errorDetail; detailEl.classList.remove('visible'); detailBtn.classList.add('visible'); detailBtn.textContent = 'Show technical details'; } else { detailEl.classList.remove('visible'); detailBtn.classList.remove('visible'); }
    if (showRetry) {
        okBtn.className = 'btn-popup-ok error-btn'; okBtn.textContent = 'Close';
        const existingRetry = document.getElementById('submitPopupRetryBtn'); if (existingRetry) existingRetry.remove();
        const retryBtn = document.createElement('button'); retryBtn.className = 'btn-popup-retry'; retryBtn.id = 'submitPopupRetryBtn'; retryBtn.textContent = 'Try Again';
        retryBtn.onclick = function() { closeSubmitPopup(); const sb = document.getElementById('submitBtn'); if (sb) { sb.disabled = false; sb.classList.remove('loading'); sb.textContent = 'Submit Weekly Report'; } };
        actionsEl.insertBefore(retryBtn, okBtn);
    } else { okBtn.className = 'btn-popup-ok'; okBtn.textContent = 'Done'; const existing = document.getElementById('submitPopupRetryBtn'); if (existing) existing.remove(); }
    overlay.classList.add('open');
}
function closeSubmitPopup() { document.getElementById('submitPopupOverlay').classList.remove('open'); }
document.getElementById('submitPopupOverlay').addEventListener('click', function(e) { if (e.target === this) closeSubmitPopup(); });

/* ── SUBMIT CONFIRM MODAL ── */
let _confirmResolve = null;
let _confirmReject  = null;
function showConfirmModal() {
    return new Promise(function(resolve, reject) {
        _confirmResolve = resolve; _confirmReject = reject;
        document.getElementById('confirmOverlay').classList.add('open');
    });
}
function closeConfirmModal() { document.getElementById('confirmOverlay').classList.remove('open'); }
document.getElementById('confirmSubmitBtn').addEventListener('click', function() {
    closeConfirmModal();
    if (_confirmResolve) { _confirmResolve(); _confirmResolve = null; _confirmReject = null; }
});
document.getElementById('confirmCancelBtn').addEventListener('click', function() {
    closeConfirmModal();
    if (_confirmReject) { _confirmReject(); _confirmResolve = null; _confirmReject = null; }
});
document.getElementById('confirmOverlay').addEventListener('click', function(e) {
    if (e.target === this) { closeConfirmModal(); if (_confirmReject) { _confirmReject(); _confirmResolve = null; _confirmReject = null; } }
});

/* ══════════════════════════════════════════════════════════════
   REPORT HISTORY — FULL-SCREEN LIBRARY-STYLE VIEW
   (list pane + large inline report preview + read-only feedback
   drawer — mirrors company_reports.php's Student Library. No
   grading anywhere: grading has been removed entirely. Status
   labels — "Pending Review"/"Wrong Document"/etc. — have also been
   removed entirely from the list pane per a previous revision; the
   underlying remark data still drives everything else below. The
   top toolbar now only has "Close" — Print (top toolbar) was
   removed entirely and Feedback moved down into each report's
   detail actions row, alongside Print / Save as PDF.)
══════════════════════════════════════════════════════════════ */
let _historyData     = INIT_HISTORY.slice();
let _activeHistoryId = null;

function openHistoryModal() {
    document.getElementById('histOverlay').classList.add('open');
    document.body.style.overflow = 'hidden';
    if (_historyData.length > 0) {
        selectHistoryReport(_historyData[0].id);
    }
}

function closeHistoryModal(e) {
    if (e && e.target !== document.getElementById('histOverlay')) return;
    closeHistoryModalDirect();
}

function closeHistoryModalDirect() {
    document.getElementById('histOverlay').classList.remove('open');
    toggleFeedbackDrawer(false);
    document.body.style.overflow = '';
}

/* NEW: computes Total (expected)/Submitted/Missed straight from the
   in-memory history list — used as a client-side fallback whenever a
   fresh server-computed report_stats payload isn't available for a
   given render pass. */
function computeStatsFromHistory(history) {
    const submitted = (history || []).filter(r => (r.remark || '') !== 'Wrong Document').length;
    return { total_expected: submitted, submitted: submitted, missed: 0 };
}

function selectHistoryReport(reportId) {
    _activeHistoryId = reportId;

    document.querySelectorAll('.hist-list-item').forEach(el => el.classList.remove('active'));
    const listItem = document.getElementById('hlist-' + reportId);
    if (listItem) listItem.classList.add('active');

    const rep = _historyData.find(r => r.id == reportId);
    if (!rep) return;

    const subDate   = rep.submitted_at
        ? new Date(rep.submitted_at.replace(' ','T')).toLocaleDateString('en-US',{month:'short',day:'2-digit',year:'numeric'}) + ' '
          + new Date(rep.submitted_at.replace(' ','T')).toLocaleTimeString('en-US',{hour:'numeric',minute:'2-digit'})
        : '';
    const feedbackHasContent = !!(rep.feedback && rep.feedback.trim() !== '');

    /* NOTE: the status ("Pending Review"/etc.) badge and the week-range
       date heading previously shown at the top of this detail pane have
       been removed — the badge duplicated the same status already
       visible on the report's entry in the list pane on the left, and
       the date range duplicated the DATE already shown inside the
       actual report content in the preview below. Only the submission
       timestamp is kept here.

       The Print / Save as PDF / Feedback actions all live here now,
       directly beneath the submission timestamp and centered. Print
       and Save as PDF start disabled and are enabled once the iframe
       has finished loading the report (see loadReportPreview()).
       Feedback (moved down from the top toolbar) is available
       immediately, since it doesn't depend on the preview iframe. */
    const detailPane = document.getElementById('histDetailPane');
    detailPane.innerHTML = `
        <div class="hist-detail-header">
            <div class="hist-detail-sub">${subDate ? 'Submitted ' + subDate : ''}</div>
            <div class="hist-detail-actions">
                <button type="button" class="hist-report-action-btn hist-report-action-print" id="histReportPrintBtn-${rep.id}" onclick="histTriggerReportPrint(${rep.id})" disabled>
                    <i class="fas fa-print"></i> Print
                </button>
                <button type="button" class="hist-report-action-btn hist-report-action-pdf" id="histReportPdfBtn-${rep.id}" onclick="histTriggerReportPDF(${rep.id})" disabled>
                    <i class="fas fa-file-pdf"></i> Save as PDF
                </button>
                <button type="button" class="hist-report-action-btn hist-report-action-feedback${feedbackHasContent ? ' has-feedback' : ''}" id="histReportFeedbackBtn-${rep.id}" onclick="toggleFeedbackDrawer()">
                    <i class="fas fa-comment-alt"></i> Feedback
                </button>
            </div>
        </div>
        <div class="hist-detail-body">
            <div class="hist-preview-section">
                <div class="hist-preview-title"><i class="fas fa-file-lines" style="color:var(--blue);margin-right:4px;"></i> Report Preview</div>
                <div class="hist-report-preview" id="report-preview-${rep.id}">
                    <div class="hist-report-preview-loading"><div class="hist-spinner"></div> Loading preview...</div>
                </div>
            </div>
        </div>`;

    loadReportPreview(rep.id);

    renderFeedbackDrawer(rep);
}

/* ── INLINE REPORT PREVIEW (right pane) ──
   Reuses the existing view=1 JSON endpoint: html reports render in
   an inline iframe (viewraw=1); legacy xlsx blobs show the same
   "use Download" style message the endpoint already returns.
   Once the iframe finishes loading, the report-level Print / Save as
   PDF buttons (rendered in the detail header above) are enabled. */
function loadReportPreview(reportId) {
    const container = document.getElementById('report-preview-' + reportId);
    if (!container) return;

    const enableReportActions = function() {
        const pBtn = document.getElementById('histReportPrintBtn-' + reportId);
        const dBtn = document.getElementById('histReportPdfBtn-' + reportId);
        if (pBtn) pBtn.disabled = false;
        if (dBtn) dBtn.disabled = false;
    };
    const disableReportActions = function() {
        const pBtn = document.getElementById('histReportPrintBtn-' + reportId);
        const dBtn = document.getElementById('histReportPdfBtn-' + reportId);
        if (pBtn) pBtn.disabled = true;
        if (dBtn) dBtn.disabled = true;
    };

    disableReportActions();

    fetch('student_report.php?view=1&id=' + reportId)
        .then(r => r.json())
        .then(data => {
            if (!document.body.contains(container)) return; // user navigated to another report meanwhile
            if (data.error) {
                container.innerHTML = '<div class="hist-report-preview-empty"><i class="fas fa-triangle-exclamation"></i><span>' + escHtml(data.error) + '</span></div>';
            } else if (data.is_html && data.iframe_src) {
                container.innerHTML = '<iframe class="hist-report-preview-frame" src="' + data.iframe_src + '" title="Report preview"></iframe>';
                const iframeEl = container.querySelector('iframe');
                if (iframeEl) {
                    iframeEl.addEventListener('load', function() {
                        if (document.body.contains(container)) enableReportActions();
                    });
                }
            } else if (data.html) {
                container.innerHTML = '<div style="padding:16px;">' + data.html + '</div>';
            } else {
                container.innerHTML = '<div class="hist-report-preview-empty"><i class="fas fa-file-circle-xmark"></i><span>No content available.</span></div>';
            }
        })
        .catch(() => {
            if (document.body.contains(container)) {
                container.innerHTML = '<div class="hist-report-preview-empty"><i class="fas fa-triangle-exclamation"></i><span>Failed to load preview.</span></div>';
            }
        });
}

/* ── REPORT-LEVEL PRINT / SAVE AS PDF (moved from inside the iframe) ──
   These call straight into the loaded report document's own
   window.print() and savePDF() functions (both same-origin, so this
   is safe), since the in-document toolbar that used to expose these
   buttons has been hidden at the source
   (weekly_report_form_builder.php's .wkr-toolbar rule). */
function histTriggerReportPrint(reportId) {
    const previewContainer = document.getElementById('report-preview-' + reportId);
    if (!previewContainer) return;
    const iframe = previewContainer.querySelector('iframe');
    if (!iframe) { showSubmitPopup('error', 'Not Ready', 'Report preview is not ready yet.', false, null); return; }
    const doPrint = function() {
        try {
            iframe.contentWindow.focus();
            iframe.contentWindow.print();
        } catch (e) {
            showSubmitPopup('error', 'Print Failed', 'Unable to print this report.', false, null);
        }
    };
    if (iframe.contentDocument && iframe.contentDocument.readyState === 'complete') doPrint();
    else iframe.onload = doPrint;
}

function histTriggerReportPDF(reportId) {
    const previewContainer = document.getElementById('report-preview-' + reportId);
    if (!previewContainer) return;
    const iframe = previewContainer.querySelector('iframe');
    if (!iframe) { showSubmitPopup('error', 'Not Ready', 'Report preview is not ready yet.', false, null); return; }
    const doSave = function() {
        try {
            if (iframe.contentWindow && typeof iframe.contentWindow.savePDF === 'function') {
                iframe.contentWindow.savePDF();
            } else {
                showSubmitPopup('error', 'Not Available', 'PDF export is not available for this report.', false, null);
            }
        } catch (e) {
            showSubmitPopup('error', 'PDF Failed', 'Unable to generate a PDF for this report.', false, null);
        }
    };
    if (iframe.contentDocument && iframe.contentDocument.readyState === 'complete') doSave();
    else iframe.onload = doSave;
}

/* ── FEEDBACK DRAWER (read-only) ── */
function renderFeedbackDrawer(rep) {
    const body = document.getElementById('histCommentDrawerBody');
    if (!body || !rep) return;

    const feedbackDisplay = rep.feedback
        ? `<div class="hist-comment-display">${escHtml(rep.feedback).replace(/\n/g,'<br>')}</div>`
        : `<div class="hist-comment-display empty">No feedback from your coordinator yet.</div>`;

    body.innerHTML = `
        <div class="hist-comment-section">
            <div class="hist-comment-title"><i class="fas fa-comment-alt" style="color:var(--ink-faint);margin-right:4px;"></i> Feedback</div>
            ${feedbackDisplay}
        </div>`;
}

function toggleFeedbackDrawer(forceState) {
    const drawer   = document.getElementById('histCommentDrawer');
    const backdrop = document.getElementById('histCommentDrawerBackdrop');
    if (!drawer) return;
    const shouldOpen = (typeof forceState === 'boolean') ? forceState : !drawer.classList.contains('open');
    drawer.classList.toggle('open', shouldOpen);
    if (backdrop) backdrop.classList.toggle('show', shouldOpen);
}

/* Rebuilds the list pane + summary stats after a poll refresh.
   `stats` (Total expected / Submitted / Missed), when provided, comes
   from the server's computeWeeklyReportStats(); if it's not supplied
   for any reason, a client-side fallback is derived from `history`
   so the cards never show stale/blank values. */
function rebuildHistoryPanel(history, stats) {
    _historyData = history.slice();

    const listPane = document.getElementById('histListPane');
    if (!listPane) return;

    const resolvedStats = stats || computeStatsFromHistory(history);
    const submittedCount = (typeof resolvedStats.submitted === 'number')
        ? resolvedStats.submitted
        : history.filter(r => (r.remark || '') !== 'Wrong Document').length;
    const totalExpected  = (typeof resolvedStats.total_expected === 'number') ? resolvedStats.total_expected : submittedCount;
    const missedCount    = (typeof resolvedStats.missed === 'number') ? resolvedStats.missed : Math.max(0, totalExpected - submittedCount);

    const totalEl     = document.getElementById('histStatTotalVal');
    const submittedEl = document.getElementById('histStatSubmittedVal');
    const missedEl     = document.getElementById('histStatMissedVal');
    if (totalEl)     totalEl.textContent     = totalExpected;
    if (submittedEl) submittedEl.textContent = submittedCount;
    if (missedEl)     missedEl.textContent   = missedCount;

    const histBadge = document.getElementById('histBadge');
    if (histBadge) { histBadge.textContent = history.length; histBadge.style.display = history.length > 0 ? '' : 'none'; }

    if (history.length === 0) {
        listPane.innerHTML = '<div class="hist-list-empty">No reports submitted yet.</div>';
        document.getElementById('histDetailPane').innerHTML =
            '<div class="hist-detail-empty"><i class="fas fa-hand-point-left"></i><span>No reports yet.</span></div>';
        return;
    }

    let html = '';
    history.forEach(rep => {
        const monTs    = new Date(rep.week_start + 'T00:00:00');
        const friTs    = new Date(rep.week_start + 'T00:00:00');
        friTs.setDate(friTs.getDate() + 4);
        const fmtDs   = d => d.toLocaleDateString('en-US', { month: 'short', day: '2-digit' });
        const fmtD    = d => d.toLocaleDateString('en-US', { month: 'short', day: '2-digit', year: 'numeric' });
        const weekRange = fmtDs(monTs) + ' - ' + fmtD(friTs);
        const subLabel  = rep.submitted_at
            ? new Date(rep.submitted_at.replace(' ','T')).toLocaleDateString('en-US',{month:'short',day:'2-digit',year:'numeric'})
            : '';
        const isActive = rep.id == _activeHistoryId;
        html += `<div class="hist-list-item${isActive ? ' active' : ''}" id="hlist-${rep.id}" onclick="selectHistoryReport(${rep.id})">
            <div class="hist-list-week">${weekRange}</div>
            <div class="hist-list-sub">${subLabel}</div>
        </div>`;
    });
    listPane.innerHTML = html;

    if (_activeHistoryId !== null && history.find(r => r.id == _activeHistoryId)) {
        selectHistoryReport(_activeHistoryId);
    }
}

/* ── DTR ── */
function _getDTRSelectedMonth() {
    const sel = document.getElementById('dtrMonthSelect');
    return sel ? sel.value : '';
}
function _updateDTRMonthLabel() {
    const month = _getDTRSelectedMonth();
    const labelEl = document.getElementById('dtrModalMonthLabel');
    if (!labelEl || !month) return;
    const parts = month.split('-');
    const d = new Date(parseInt(parts[0]), parseInt(parts[1]) - 1, 1);
    labelEl.textContent = d.toLocaleDateString('en-US', {month: 'long', year: 'numeric'}).toUpperCase();
}
function _showDTRLoading() { const overlay = document.getElementById('dtrLoadingOverlay'); if (overlay) { overlay.classList.remove('hidden'); } }
function _hideDTRLoading() { const overlay = document.getElementById('dtrLoadingOverlay'); if (overlay) { overlay.classList.add('hidden'); } }
function loadDTRIntoModal() {
    const month = _getDTRSelectedMonth();
    if (!month) return;
    const iframe = document.getElementById('dtrPreviewIframe');
    _showDTRLoading();
    iframe.onload = null;
    iframe.onload = function() { _hideDTRLoading(); };
    const src = 'student_report.php?dtrraw=1&month=' + encodeURIComponent(month);
    iframe.src = src;
}
function openDTRModal() {
    const modal = document.getElementById('dtrPreviewModal');
    modal.classList.add('open');
    document.body.style.overflow = 'hidden';
    _updateDTRMonthLabel();
    loadDTRIntoModal();
}
function closeDTRModal() {
    document.getElementById('dtrPreviewModal').classList.remove('open');
    document.body.style.overflow = '';
    const iframe = document.getElementById('dtrPreviewIframe');
    if (iframe) { iframe.onload = null; iframe.src = ''; }
    _showDTRLoading();
}
function closeDTRModalOnOverlay(e) {
    if (e.target === document.getElementById('dtrPreviewModal')) { closeDTRModal(); }
}
function onDTRMonthChange() { _updateDTRMonthLabel(); loadDTRIntoModal(); }

/* ── FORM SUBMISSION ── */
document.getElementById('journalForm').addEventListener('submit', function(e) {
    e.preventDefault();
    const form = this;
    const active = form.querySelectorAll('textarea:not([disabled])'); let filled = false;
    active.forEach(t => { if (t.value.trim()) filled = true; });
    if (!filled) { showSubmitPopup('error', 'Empty Report', 'Please fill in at least one day entry before submitting.', false, null); return; }
    showConfirmModal().then(function() {
        const submitBtn = document.getElementById('submitBtn');
        if (submitBtn) { submitBtn.disabled = true; submitBtn.classList.add('loading'); submitBtn.textContent = 'Submitting...'; }
        const formData = new FormData(form);
        fetch('student_report.php?ajax=1', { method: 'POST', body: formData, headers: { 'X-Requested-With': 'XMLHttpRequest', 'Accept': 'application/json' } })
        .then(function(response) { const status = response.status; return response.text().then(text => ({ status, text })); })
        .then(function({ status, text: rawText }) {
            let data;
            try { data = JSON.parse(rawText); } catch(parseErr) {
                if (submitBtn) { submitBtn.disabled = false; submitBtn.classList.remove('loading'); submitBtn.textContent = 'Submit Weekly Report'; }
                showSubmitPopup('error', 'Server Error (HTTP ' + status + ')', 'The server returned an unexpected response.', true, 'HTTP ' + status + '\n' + parseErr.message + '\n\n' + rawText.substring(0, 500));
                return;
            }
            if (data.success) {
                _isDirty = false; _allowNavigation = true; clearDraft(); showFlash(data.message);
                showSubmitPopup('success', 'Report Submitted!', '<strong>Your weekly report has been submitted successfully!</strong><br><br>You can view your submission in the Report History.', false, null);
                silentPoll(true);
            } else {
                if (submitBtn) { submitBtn.disabled = false; submitBtn.classList.remove('loading'); submitBtn.textContent = 'Submit Weekly Report'; }
                const techDetail = [data._exception_class ? '[' + data._exception_class + '] ' + data._exception_msg : '', data._exception_file ? 'File: ' + data._exception_file : '', data._exception_trace ? 'Trace:\n' + data._exception_trace.substring(0, 800) : ''].filter(Boolean).join('\n');
                showSubmitPopup('error', 'Submission Failed', data.message || 'Your report could not be submitted. Please try again.', true, techDetail || null);
            }
        })
        .catch(function(err) {
            if (submitBtn) { submitBtn.disabled = false; submitBtn.classList.remove('loading'); submitBtn.textContent = 'Submit Weekly Report'; }
            showSubmitPopup('error', 'Connection Error', 'Could not reach the server. Please check your internet connection and try again.', true, 'Network error: ' + err.message);
        });
    }).catch(function() { /* user cancelled */ });
});

/* ── POLL ── */
let _lastHistoryJson = ''; let _pollBusy = false;
function silentPoll(force) {
    if (_pollBusy && !force) return; _pollBusy = true;
    const ind = document.getElementById('refreshIndicator'); ind.classList.add('show');
    fetch('student_report.php?poll=1').then(r => r.json()).then(data => { applyPollData(data); }).catch(() => {}).finally(() => { _pollBusy = false; setTimeout(() => ind.classList.remove('show'), 800); });
}
function applyPollData(data) {
    const histJson = JSON.stringify(data.history);
    if (histJson !== _lastHistoryJson) {
        _lastHistoryJson = histJson;
        rebuildHistoryPanel(data.history, data.report_stats);
    }
    if (data.existing && data.existing.remark !== 'Wrong Document' && !IS_SUBMITTED) {
        const pill = document.getElementById('weekPill'); if (pill) { pill.className = 'week-pill done'; pill.textContent = 'Submitted'; }
        const btnRow = document.getElementById('btnRow'); if (btnRow) btnRow.innerHTML = '<div class="submitted-msg">Report submitted this week</div>';
        const submitNote = document.getElementById('submitNote'); if (submitNote) submitNote.innerHTML = '<strong>Report already submitted</strong> for this week.';
    }
}

function escHtml(str) { return String(str).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;'); }
function formatDate(dateStr) { if (!dateStr) return ''; const d = new Date(dateStr + 'T00:00:00'); return d.toLocaleDateString('en-US', {month:'short',day:'2-digit',year:'numeric'}); }
function formatDateTime(dtStr) { if (!dtStr) return ''; const d = new Date(dtStr.replace(' ','T')); return d.toLocaleDateString('en-US',{month:'short',day:'2-digit',year:'numeric'}) + ' ' + d.toLocaleTimeString('en-US',{hour:'numeric',minute:'2-digit'}); }
function showFlash(msg) { const wrap = document.getElementById('flashWrap'); const el = document.getElementById('flashMsg'); if (!wrap || !el) return; el.textContent = msg; wrap.style.display = ''; el.style.opacity = '1'; setTimeout(() => { el.style.opacity = '0'; }, 4000); }
const flashEl = document.querySelector('.flash-msg'); if (flashEl && flashEl.textContent.trim()) { setTimeout(() => { flashEl.style.opacity = '0'; }, 4000); }
setInterval(silentPoll, 30000); setTimeout(() => silentPoll(true), 5000);

/* ── JOURNAL BADGE ── */
(function() {
    if (IS_SUBMITTED) return;
    const badge = document.getElementById('journalEmptyBadge');
    function countEmpty() { let c = 0; document.querySelectorAll('.tl-textarea:not([disabled])').forEach(ta => { if (!ta.value.trim()) c++; }); return c; }
    function update() { if (!badge) return; const count = countEmpty(); if (count > 0) { badge.textContent = count; badge.style.display = 'inline-flex'; } else { badge.style.display = 'none'; } }
    document.querySelectorAll('.tl-textarea:not([disabled])').forEach(ta => ta.addEventListener('input', update));
    setTimeout(update, 80); setInterval(update, 5000);
})();

/* ── ESC KEY closes modals ── */
document.addEventListener('keydown', function(e) {
    if (e.key === 'Escape') {
        closeDTRModal();
        closeHistoryModalDirect();
    }
});

/* ══════════════════════════════════════════════════════════════
   ATTENDANCE NOTIFICATION BAR
══════════════════════════════════════════════════════════════ */
const ANB_BADGE_INFO     = <?= json_encode($attendance_badge_info) ?>;
const ANB_TODAY_SETTINGS = <?= json_encode($_att_today_settings) ?>;
const ANB_IS_WEEKEND     = <?= $_att_is_weekend ? 'true' : 'false' ?>;
const ANB_IS_ALL_DONE    = <?= json_encode((bool)$_att_all_done) ?>;
const ANB_ORDER          = ['am_time_in','am_time_out','pm_time_in','pm_time_out'];

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
    return parseInt(p[0], 10) * 3600 + parseInt(p[1], 10) * 60 + (p[2] ? parseInt(p[2], 10) : 0);
}
function _anbNowSec() {
    const n = new Date();
    return n.getHours() * 3600 + n.getMinutes() * 60 + n.getSeconds();
}
function _anbFmt12(t) {
    if (!t) return '—';
    const p = t.split(':');
    let h = parseInt(p[0], 10), m = parseInt(p[1], 10);
    const ap = h >= 12 ? 'PM' : 'AM';
    h = h % 12 || 12;
    return h + ':' + String(m).padStart(2, '0') + ' ' + ap;
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
    document.getElementById('anb-window').textContent = 'Window: ' + info.start_fmt + ' – ' + info.end_fmt;
    document.getElementById('anb-countdown').textContent = 'Calculating...';
    const anbActionBtn = document.getElementById('anb-action-btn');
    if (info.is_late_window) { anbActionBtn.textContent = 'Request now'; }
    else { anbActionBtn.textContent = 'Sign now'; }
    anbActionBtn.onclick = function() { window.location.href = 'student_attendance.php'; };
    document.getElementById('att-notif-bar').classList.toggle('sidebar-collapsed', sidebar.classList.contains('collapsed'));
    if (_anb.tickInterval  !== null) { clearInterval(_anb.tickInterval);  _anb.tickInterval  = null; }
    if (_anb.autoHideTimer !== null) { clearTimeout(_anb.autoHideTimer);  _anb.autoHideTimer = null; }
    if (_anb.rafId         !== null) { cancelAnimationFrame(_anb.rafId);  _anb.rafId         = null; }
    const prog     = document.getElementById('anb-progress');
    const duration = _anb.autoHideDuration;
    const startTs  = _anb.showStartTs;
    if (prog) { prog.style.transition = 'none'; prog.style.width = '100%'; void prog.offsetWidth; }
    function rafTick(now) {
        const elapsed = now - startTs;
        const pct = Math.max(0, 100 - (elapsed / duration) * 100);
        if (prog) prog.style.width = pct + '%';
        if (pct > 0) { _anb.rafId = requestAnimationFrame(rafTick); }
        else { _anb.rafId = null; _anbHide(_anb.currentType); }
    }
    _anb.rafId = requestAnimationFrame(rafTick);
    const endSec = _anbTimeToSec(info.end_time);
    function tick() {
        const rem = endSec - _anbNowSec();
        if (rem <= 0) { _anbHide(_anb.currentType); return; }
        const m = Math.floor(rem / 60);
        const s = rem % 60;
        document.getElementById('anb-countdown').textContent = '⏳ ' + m + 'm ' + String(s).padStart(2, '0') + 's left';
    }
    tick();
    _anb.tickInterval = setInterval(tick, 1000);
    const capturedType = info.type;
    _anb.autoHideTimer = setTimeout(function() { _anb.autoHideTimer = null; _anbHide(capturedType); }, duration);
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
    for (const type of ANB_ORDER) {
        const def      = DEFS[type];
        const startStr = ANB_TODAY_SETTINGS[def.startKey];
        const endStr   = ANB_TODAY_SETTINGS[def.endKey];
        if (!startStr || !endStr) continue;
        const s = _anbTimeToSec(startStr);
        const e = _anbTimeToSec(endStr);
        if (ns < s || ns > e)                continue;
        if (_anb.dismissedWindows.has(type)) continue;
        if (_anb.shownWindows.has(type))     continue;
        _anb.shownWindows.add(type);
        _anbShow({ type, label: def.label, start_fmt: _anbFmt12(startStr), end_fmt: _anbFmt12(endStr), start_time: startStr, end_time: endStr, is_late_window: false });
        return;
    }
    if (_anbPmLateShown) return;
    const pmOutEndStr = ANB_TODAY_SETTINGS['pm_time_out_end'];
    if (!pmOutEndStr) return;
    const pmOutEndSec   = _anbTimeToSec(pmOutEndStr);
    const lateWindowEnd = pmOutEndSec + 3600;
    if (ns <= pmOutEndSec || ns > lateWindowEnd) return;
    if (_anb.dismissedWindows.has('pm_time_out_late')) return;
    const lateWindowEndH   = Math.floor(lateWindowEnd / 3600);
    const lateWindowEndM   = Math.floor((lateWindowEnd % 3600) / 60);
    const lateWindowEndStr = String(lateWindowEndH).padStart(2, '0') + ':' + String(lateWindowEndM).padStart(2, '0') + ':00';
    _anbPmLateShown = true;
    _anb.shownWindows.add('pm_time_out_late');
    _anbShow({ type: 'pm_time_out_late', label: 'PM Sign Out Late Request', start_fmt: _anbFmt12(pmOutEndStr) + ' (missed)', end_fmt: _anbFmt12(lateWindowEndStr) + ' (deadline)', start_time: pmOutEndStr, end_time: lateWindowEndStr, is_late_window: true });
}

(function() {
    const dow = new Date().getDay();
    if (ANB_BADGE_INFO && !ANB_IS_WEEKEND && dow !== 0 && dow !== 6 && !ANB_IS_ALL_DONE) {
        setTimeout(function() { _anbShow(ANB_BADGE_INFO); }, 800);
    }
})();
setInterval(_anbWatch, 30000);
</script>
</body>
</html>