<?php
session_start();
include "db.php";

if (!isset($_SESSION['user_id']) || $_SESSION['role'] != "admin") {
    die("Access denied.");
}

$admin_id   = $_SESSION['user_id'];
$company_id = (int)($_GET['company_id'] ?? 0);
if (!$company_id) die("Invalid company.");

// ── Company info ──
$ci_stmt = $conn->prepare("
    SELECT ci.company, u.email
    FROM company_information ci
    JOIN users u ON u.id = ci.user_id
    WHERE ci.user_id = ?
");
$ci_stmt->bind_param("i", $company_id);
$ci_stmt->execute();
$ci_row = $ci_stmt->get_result()->fetch_assoc();
$company_name = $ci_row['company'] ?? 'Company';

/* ── SAVE WEIGHT SETTINGS (AJAX) ── */
if (isset($_GET['save_weights']) && $_GET['save_weights'] == '1' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $company_w = max(0, min(100, (int)($_POST['company_weight'] ?? 30)));
    $admin_w   = max(0, min(100, (int)($_POST['admin_weight']   ?? 70)));
    if ($company_w + $admin_w !== 100) {
        header('Content-Type: application/json');
        echo json_encode(['success' => false, 'message' => 'Weights must sum to 100.']);
        exit;
    }
    $_SESSION['grade_weight_company_' . $company_id] = $company_w;
    $_SESSION['grade_weight_admin_'   . $company_id] = $admin_w;
    header('Content-Type: application/json');
    echo json_encode(['success' => true, 'company_weight' => $company_w, 'admin_weight' => $admin_w]);
    exit;
}

/* ── SAVE ALL FINAL GRADES (AJAX) ── */
if (isset($_GET['save_all_grades']) && $_GET['save_all_grades'] == '1' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    header('Content-Type: application/json');

    $students_raw = $_POST['students'] ?? '';
    $w_company    = max(0, min(100, (int)($_POST['weight_company'] ?? 30)));
    $w_admin      = max(0, min(100, (int)($_POST['weight_admin']   ?? 70)));

    $students_arr = json_decode($students_raw, true);
    if (!is_array($students_arr) || empty($students_arr)) {
        echo json_encode(['success' => false, 'message' => 'No student data received.']);
        exit;
    }

    $saved = 0; $skipped = 0; $errors = [];

    $upsert = $conn->prepare("
        INSERT INTO final_grades
            (student_id, company_id, avg_company_grade, avg_admin_grade, avg_weighted_grade,
             weight_company, weight_admin, saved_by, saved_at)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, NOW())
        ON DUPLICATE KEY UPDATE
            avg_company_grade  = VALUES(avg_company_grade),
            avg_admin_grade    = VALUES(avg_admin_grade),
            avg_weighted_grade = VALUES(avg_weighted_grade),
            weight_company     = VALUES(weight_company),
            weight_admin       = VALUES(weight_admin),
            saved_by           = VALUES(saved_by),
            saved_at           = NOW()
    ");

    foreach ($students_arr as $row) {
        $sid          = (int)($row['student_id'] ?? 0);
        $avg_company  = ($row['avg_company']  !== null && $row['avg_company']  !== '') ? (float)$row['avg_company']  : null;
        $avg_admin    = ($row['avg_admin']    !== null && $row['avg_admin']    !== '') ? (float)$row['avg_admin']    : null;
        $avg_weighted = ($row['avg_weighted'] !== null && $row['avg_weighted'] !== '') ? (float)$row['avg_weighted'] : null;

        if (!$sid || $avg_weighted === null) { $skipped++; continue; }

        $upsert->bind_param("iidddiii",
            $sid, $company_id,
            $avg_company, $avg_admin, $avg_weighted,
            $w_company, $w_admin, $admin_id
        );
        if ($upsert->execute()) {
            $saved++;
        } else {
            $errors[] = "Student #$sid: " . $conn->error;
        }
    }
    $upsert->close();

    echo json_encode([
        'success' => true,
        'saved'   => $saved,
        'skipped' => $skipped,
        'errors'  => $errors,
    ]);
    exit;
}

/* ── LOAD WEIGHTS ── */
$weight_company = (int)($_SESSION['grade_weight_company_' . $company_id] ?? 30);
$weight_admin   = (int)($_SESSION['grade_weight_admin_'   . $company_id] ?? 70);

/* ── FETCH ALL STUDENTS + THEIR REPORTS ── */
$students_stmt = $conn->prepare("
    SELECT u.id AS student_id, u.first_name, u.last_name, u.course
    FROM ojt_assignments oa
    JOIN users u ON oa.student_id = u.id
    WHERE oa.company_id = ?
    ORDER BY u.first_name ASC
");
$students_stmt->bind_param("i", $company_id);
$students_stmt->execute();
$students = $students_stmt->get_result()->fetch_all(MYSQLI_ASSOC);

/* ── FETCH ALL SUBMITTED REPORTS FOR THIS COMPANY ── */
$reports_stmt = $conn->prepare("
    SELECT r.user_id, r.week_start, r.faculty_grade, r.company_grade, r.remark, r.submitted_at
    FROM reports r
    JOIN ojt_assignments oa ON oa.student_id = r.user_id AND oa.company_id = r.company_id
    WHERE r.company_id = ?
      AND (r.remark IS NULL OR r.remark != 'Wrong Document')
    ORDER BY r.week_start ASC
");
$reports_stmt->bind_param("i", $company_id);
$reports_stmt->execute();
$all_reports = $reports_stmt->get_result()->fetch_all(MYSQLI_ASSOC);

/* ── COLLECT ALL UNIQUE WEEKS ── */
$all_weeks = array_unique(array_column($all_reports, 'week_start'));
usort($all_weeks, fn($a, $b) => strtotime($a) - strtotime($b));

/* ── INDEX REPORTS BY student_id → week_start ── */
$report_map = [];
foreach ($all_reports as $r) {
    $report_map[$r['user_id']][$r['week_start']] = $r;
}

/* ── FETCH EXISTING FINAL GRADES FOR THIS COMPANY ── */
$fg_stmt = $conn->prepare("
    SELECT student_id, avg_company_grade, avg_admin_grade, avg_weighted_grade,
           weight_company, weight_admin, is_published, saved_at
    FROM final_grades
    WHERE company_id = ?
");
$fg_stmt->bind_param("i", $company_id);
$fg_stmt->execute();
$fg_rows = $fg_stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$fg_stmt->close();
$final_grades_map = [];
foreach ($fg_rows as $fg) {
    $final_grades_map[$fg['student_id']] = $fg;
}

/* ── BUILD STUDENT SUMMARY DATA ── */
$student_data = [];
foreach ($students as $s) {
    $sid = $s['student_id'];
    $weeks_detail = [];
    $total_company = 0; $count_company = 0;
    $total_admin   = 0; $count_admin   = 0;
    $total_weighted = 0; $count_weighted = 0;

    foreach ($all_weeks as $ws) {
        $rep = $report_map[$sid][$ws] ?? null;
        $cg = $rep ? $rep['company_grade'] : null;
        $ag = $rep ? $rep['faculty_grade'] : null;

        $weighted = null;
        if ($cg !== null && $ag !== null) {
            $weighted = round(($cg * $weight_company + $ag * $weight_admin) / 100, 2);
        }

        if ($cg !== null) { $total_company += $cg; $count_company++; }
        if ($ag !== null) { $total_admin   += $ag; $count_admin++;   }
        if ($weighted !== null) { $total_weighted += $weighted; $count_weighted++; }

        $weeks_detail[$ws] = [
            'company_grade' => $cg,
            'admin_grade'   => $ag,
            'weighted'      => $weighted,
            'submitted_at'  => $rep['submitted_at'] ?? null,
        ];
    }

    $avg_company  = $count_company  > 0 ? round($total_company  / $count_company,  2) : null;
    $avg_admin    = $count_admin    > 0 ? round($total_admin    / $count_admin,    2) : null;
    $avg_weighted = $count_weighted > 0 ? round($total_weighted / $count_weighted, 2) : null;

    $student_data[] = [
        'student_id'   => $sid,
        'name'         => $s['first_name'] . ' ' . $s['last_name'],
        'first_name'   => $s['first_name'],
        'last_name'    => $s['last_name'],
        'course'       => $s['course'] ?? '',
        'weeks'        => $weeks_detail,
        'avg_company'  => $avg_company,
        'avg_admin'    => $avg_admin,
        'avg_weighted' => $avg_weighted,
        'submitted'    => $count_company > 0 || $count_admin > 0,
        'saved_grade'  => $final_grades_map[$sid] ?? null,
    ];
}

/* ── CHECK IF ANY GRADES ALREADY SAVED FOR THIS COMPANY ── */
$any_already_saved = !empty($final_grades_map);
$saved_count_existing = count($final_grades_map);

/* ── COUNT STUDENTS WITH WEIGHTED GRADES (eligible) ── */
$eligible_count = count(array_filter($student_data, fn($s) => $s['avg_weighted'] !== null));

function weekLabel(string $ws): string {
    $mon = strtotime($ws);
    $fri = strtotime('+4 days', $mon);
    return date("M d", $mon) . "–" . date("d", $fri);
}

/* ── UNGRADED SIDEBAR BADGE ── */
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
$app_request_count_res = $conn->query("SELECT COUNT(*) as total FROM admin_application_approvals");
$app_request_count = (int)(($app_request_count_res ? $app_request_count_res->fetch_assoc()['total'] : 0));
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>Grade Summary — <?= htmlspecialchars($company_name) ?></title>
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
<link href="https://fonts.googleapis.com/css2?family=DM+Sans:wght@300;400;500;600;700&family=DM+Mono:wght@400;500&display=swap" rel="stylesheet">
<style>
:root {
    --neust-maroon: #07145fe5;
    --neust-gold: #FFD700;
    --bg: #f0f4f8;
    --surface: #ffffff;
    --border: #e5e7eb;
    --text-primary: #111827;
    --text-secondary: #6b7280;
    --text-muted: #9ca3af;
    --blue: #1a56db;
    --green: #0e9f6e;
    --amber: #d97706;
    --red: #f05252;
    --company-color: #7c3aed;
    --admin-color: #1a56db;
    --weighted-color: #0e9f6e;
    --shadow-sm: 0 1px 3px rgba(0,0,0,0.08), 0 1px 2px rgba(0,0,0,0.05);
    --shadow-md: 0 4px 12px rgba(0,0,0,0.1);
    --shadow-lg: 0 8px 32px rgba(0,0,0,0.14);
    --radius: 14px;
    --radius-sm: 8px;
    --radius-xs: 5px;
}
* { box-sizing: border-box; margin: 0; padding: 0; }
body {
    font-family: 'DM Sans', 'Segoe UI', sans-serif;
    background: var(--bg);
    color: var(--text-primary);
    display: flex;
    min-height: 100vh;
    line-height: 1.5;
}

/* ── SIDEBAR ── */
.sidebar { width:260px; background:var(--neust-maroon); height:100vh; position:fixed; display:flex; flex-direction:column; transition:0.3s; z-index:1000; }
.sidebar.collapsed { width:80px; }
.sidebar-header { padding:20px; display:flex; align-items:center; justify-content:space-between; border-bottom:1px solid rgba(255,255,255,0.1); }
.sidebar-header h2 { color:var(--neust-gold); margin:0; font-size:20px; white-space:nowrap; overflow:hidden; }
.sidebar.collapsed h2 { opacity:0; width:0; }
.sidebar-links { flex:1; padding:10px 0; }
.sidebar a { padding:15px 25px; color:#cbd5e0; text-decoration:none; font-size:14px; display:flex; align-items:center; position:relative; }
.sidebar a i { width:30px; font-size:18px; margin-right:15px; }
.sidebar.collapsed .link-text { display:none; }
.sidebar a.active { background:#1a237e; color:white; border-left:4px solid var(--neust-gold); }
.sidebar a:hover:not(.active) { background:rgba(255,255,255,0.07); }
.logout-link { margin-top:auto; padding:20px; border-top:1px solid rgba(255,255,255,0.1); }
.logout-link a { border:1px solid var(--neust-gold); color:var(--neust-gold); border-radius:6px; justify-content:center; padding:10px; text-decoration:none; display:flex; }
.sidebar-badge-ungraded { background:#d97706; color:white; border-radius:50%; width:18px; height:18px; font-size:10px; font-weight:700; display:inline-flex; align-items:center; justify-content:center; position:absolute; right:18px; top:50%; transform:translateY(-50%); }

/* ── LAYOUT ── */
.main-content { margin-left:260px; width:calc(100% - 260px); transition:0.3s; min-height:100vh; display:flex; flex-direction:column; }
.sidebar.collapsed ~ .main-content { margin-left:80px; width:calc(100% - 80px); }

/* ── PAGE HEADER ── */
.page-header {
    background: linear-gradient(135deg, #1a56db, #0e9f6e);
    color: white;
    padding: 18px 28px;
    display: flex;
    align-items: center;
    justify-content: space-between;
    position: sticky;
    top: 0;
    z-index: 100;
    box-shadow: 0 2px 10px rgba(0,0,0,0.15);
    flex-wrap: wrap;
    gap: 10px;
}
.page-header h2 { font-size: 1.1rem; font-weight: 700; }
.page-header-sub { font-size: 0.82rem; opacity: 0.85; margin-top: 3px; }
.page-header-right { display: flex; align-items: center; gap: 10px; flex-wrap: wrap; }
.back-btn {
    display: inline-flex; align-items: center; gap: 6px;
    background: rgba(255,255,255,0.18); border: 1.5px solid rgba(255,255,255,0.4);
    color: white; border-radius: 8px; padding: 7px 14px; font-size: 0.83rem;
    text-decoration: none; transition: background 0.2s;
}
.back-btn:hover { background: rgba(255,255,255,0.28); }

/* ── SETTINGS BUTTON ── */
.btn-settings {
    display: inline-flex; align-items: center; gap: 7px;
    background: rgba(255,255,255,0.15); border: 1.5px solid rgba(255,255,255,0.45);
    color: white; border-radius: 8px; padding: 7px 16px; font-size: 0.84rem;
    font-weight: 600; cursor: pointer; transition: all 0.2s; font-family: inherit;
    white-space: nowrap;
}
.btn-settings:hover { background: rgba(255,255,255,0.28); }

/* ── SAVE ALL BUTTON ── */
.btn-save-all {
    display: inline-flex; align-items: center; gap: 8px;
    background: rgba(255,255,255,0.95); border: none;
    color: #065f46; border-radius: 8px; padding: 8px 18px; font-size: 0.86rem;
    font-weight: 800; cursor: pointer; transition: all 0.2s; font-family: inherit;
    white-space: nowrap; box-shadow: 0 2px 8px rgba(0,0,0,0.18);
    letter-spacing: 0.01em;
}
.btn-save-all:hover:not(:disabled) { background: white; transform: translateY(-1px); box-shadow: 0 4px 14px rgba(0,0,0,0.22); }
.btn-save-all:disabled { opacity: 0.45; cursor: not-allowed; transform: none; }
.btn-save-all.already-saved {
    background: rgba(255,255,255,0.22); color: white;
    border: 1.5px solid rgba(255,255,255,0.55);
    box-shadow: none;
}
.btn-save-all.already-saved:hover:not(:disabled) {
    background: rgba(255,255,255,0.32); transform: translateY(-1px);
}

/* saved status pill in header */
.saved-status-pill {
    display: inline-flex; align-items: center; gap: 5px;
    background: rgba(110,231,183,0.22); border: 1.5px solid rgba(110,231,183,0.45);
    color: #d1fae5; border-radius: 20px; padding: 4px 12px;
    font-size: 0.74rem; font-weight: 700; white-space: nowrap;
}

/* ── WEIGHT DISPLAY PILLS ── */
.weight-pills { display: flex; align-items: center; gap: 6px; }
.weight-pill {
    display: inline-flex; align-items: center; gap: 4px;
    border-radius: 20px; padding: 4px 11px; font-size: 0.76rem;
    font-weight: 700; border: 1.5px solid; white-space: nowrap;
}
.weight-pill.company { background: rgba(124,58,237,0.18); border-color: rgba(196,181,253,0.5); color: #e9d5ff; }
.weight-pill.admin   { background: rgba(26,86,219,0.18); border-color: rgba(147,197,253,0.5); color: #bfdbfe; }

/* ── BODY CONTENT ── */
.content-wrap { max-width: 1100px; margin: 0 auto; padding: 26px 20px 60px; width: 100%; }

/* ── CONTROLS BAR ── */
.controls-bar {
    display: flex; align-items: center; gap: 12px; margin-bottom: 20px; flex-wrap: wrap;
}
.search-wrap { position: relative; flex: 1; min-width: 200px; max-width: 320px; }
.search-wrap i { position: absolute; left: 12px; top: 50%; transform: translateY(-50%); color: var(--text-muted); font-size: 0.85rem; }
.search-input {
    width: 100%; padding: 9px 12px 9px 34px;
    border: 1.5px solid var(--border); border-radius: var(--radius-sm);
    font-size: 0.87rem; font-family: inherit; background: var(--surface);
    transition: border-color 0.2s;
}
.search-input:focus { outline: none; border-color: var(--blue); }
.filter-select {
    padding: 9px 14px; border: 1.5px solid var(--border);
    border-radius: var(--radius-sm); font-size: 0.85rem; font-family: inherit;
    background: var(--surface); color: var(--text-primary); cursor: pointer;
}
.filter-select:focus { outline: none; border-color: var(--blue); }
.count-label { margin-left: auto; font-size: 0.82rem; color: var(--text-secondary); white-space: nowrap; }

/* ── LEGEND ── */
.legend-bar {
    display: flex; align-items: center; gap: 16px; margin-bottom: 18px;
    background: var(--surface); border-radius: var(--radius-sm);
    padding: 10px 16px; box-shadow: var(--shadow-sm); flex-wrap: wrap;
}
.legend-item { display: flex; align-items: center; gap: 6px; font-size: 0.78rem; font-weight: 600; }
.legend-dot { width: 10px; height: 10px; border-radius: 3px; flex-shrink: 0; }
.legend-label { color: var(--text-secondary); }

/* ── STUDENT CARDS ── */
.student-card {
    background: var(--surface);
    border-radius: var(--radius);
    box-shadow: var(--shadow-sm);
    margin-bottom: 14px;
    overflow: hidden;
    border: 1.5px solid var(--border);
    transition: box-shadow 0.2s;
}
.student-card:hover { box-shadow: var(--shadow-md); }

.card-header {
    display: flex; align-items: center; justify-content: space-between;
    padding: 15px 20px; cursor: pointer;
    border-left: 5px solid var(--weighted-color);
    transition: background 0.15s;
    gap: 12px;
}
.card-header:hover { background: #f9fafb; }
.card-header.no-data { border-left-color: var(--text-muted); }

.student-meta { flex: 1; min-width: 0; }
.student-name { font-size: 0.97rem; font-weight: 700; color: var(--text-primary); white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
.student-course { font-size: 0.74rem; color: var(--text-secondary); margin-top: 2px; }

.avg-chips { display: flex; align-items: center; gap: 8px; flex-shrink: 0; flex-wrap: wrap; justify-content: flex-end; }
.avg-chip {
    display: inline-flex; align-items: center; gap: 4px;
    border-radius: 20px; padding: 4px 11px;
    font-size: 0.76rem; font-weight: 700; white-space: nowrap;
}
.avg-chip.company-chip  { background: #f3e8ff; color: #6d28d9; border: 1.5px solid #ddd6fe; }
.avg-chip.admin-chip    { background: #dbeafe; color: #1e40af; border: 1.5px solid #bfdbfe; }
.avg-chip.weighted-chip { background: #d1fae5; color: #065f46; border: 1.5px solid #6ee7b7; }
.avg-chip.none-chip     { background: #f3f4f6; color: #9ca3af; border: 1.5px solid #e5e7eb; }

.expand-arrow { font-size: 0.7rem; color: var(--text-muted); transition: transform 0.25s; flex-shrink: 0; }
.expand-arrow.open { transform: rotate(180deg); }

/* ── CARD BODY ── */
.card-body { display: none; border-top: 1.5px solid var(--border); }
.card-body.open { display: block; }

.week-table-wrap { overflow-x: auto; }
.week-table {
    width: 100%; border-collapse: collapse;
    font-size: 0.8rem; min-width: 520px;
}
.week-table th {
    background: #f8fafc; color: var(--text-secondary);
    font-weight: 700; padding: 10px 14px; text-align: center;
    border-bottom: 2px solid var(--border); font-size: 0.73rem;
    text-transform: uppercase; letter-spacing: 0.04em; white-space: nowrap;
}
.week-table th.th-week { text-align: left; }
.week-table td {
    padding: 9px 14px; text-align: center;
    border-bottom: 1px solid #f1f5f9; font-family: 'DM Mono', monospace;
    font-size: 0.79rem;
}
.week-table td.td-week {
    text-align: left; font-family: 'DM Sans', sans-serif;
    font-weight: 600; font-size: 0.8rem; color: var(--text-primary);
    white-space: nowrap;
}
.week-table tbody tr:last-child td { border-bottom: none; }
.week-table tbody tr:hover td { background: #fafbfd; }

.grade-val { font-weight: 700; }
.grade-val.company-g { color: #6d28d9; }
.grade-val.admin-g   { color: #1e40af; }
.grade-val.weighted-g { color: #065f46; }
.grade-none { color: var(--text-muted); font-style: italic; font-family: 'DM Sans', sans-serif; font-size: 0.76rem; }

.bar-wrap { display: flex; align-items: center; gap: 8px; justify-content: center; }
.bar-bg { flex: 1; max-width: 72px; height: 5px; background: #e5e7eb; border-radius: 99px; overflow: hidden; }
.bar-fill { height: 100%; border-radius: 99px; background: linear-gradient(90deg, #0e9f6e, #1a56db); transition: width 0.5s; }

.week-table tfoot td {
    background: #f0fdf4; font-weight: 800; font-size: 0.82rem;
    border-top: 2px solid #6ee7b7; padding: 10px 14px; border-bottom: none;
}
.week-table tfoot td.td-week { font-size: 0.8rem; color: #065f46; font-family: 'DM Sans', sans-serif; }

.empty-row td { color: var(--text-muted); font-style: italic; text-align: center; padding: 20px; font-family: 'DM Sans', sans-serif; }

/* ── MODALS BASE ── */
.modal-overlay {
    display: none; position: fixed; inset: 0;
    background: rgba(0,0,0,0.5); z-index: 2000;
    align-items: center; justify-content: center; padding: 20px;
}
.modal-overlay.open { display: flex; }
.modal-box {
    background: var(--surface); border-radius: 18px;
    padding: 30px 28px; max-width: 420px; width: 100%;
    box-shadow: var(--shadow-lg); position: relative;
    animation: modalIn 0.22s cubic-bezier(.4,0,.2,1);
}
@keyframes modalIn {
    from { opacity:0; transform:scale(0.94) translateY(10px); }
    to   { opacity:1; transform:scale(1) translateY(0); }
}
.modal-title {
    font-size: 1.08rem; font-weight: 800; color: var(--text-primary);
    margin-bottom: 6px; display: flex; align-items: center; gap: 8px;
}
.modal-subtitle { font-size: 0.82rem; color: var(--text-secondary); margin-bottom: 22px; }
.modal-close {
    position: absolute; top: 14px; right: 16px;
    background: none; border: none; font-size: 1.3rem; cursor: pointer;
    color: var(--text-muted); line-height: 1;
}
.modal-close:hover { color: var(--red); }

/* ── WEIGHT SETTINGS MODAL ── */
.weight-row { display: flex; align-items: center; gap: 12px; margin-bottom: 14px; }
.weight-label {
    width: 110px; font-size: 0.85rem; font-weight: 700;
    display: flex; align-items: center; gap: 6px; flex-shrink: 0;
}
.weight-label .dot { width: 10px; height: 10px; border-radius: 50%; flex-shrink: 0; }
.weight-input-wrap { flex: 1; display: flex; align-items: center; gap: 8px; }
.weight-slider { flex: 1; -webkit-appearance: none; height: 5px; border-radius: 99px; outline: none; cursor: pointer; }
.weight-slider.company-slider { accent-color: #7c3aed; }
.weight-slider.admin-slider   { accent-color: #1a56db; }
.weight-number {
    width: 52px; text-align: center; padding: 5px 6px;
    border: 1.5px solid var(--border); border-radius: 6px;
    font-size: 0.87rem; font-family: 'DM Mono', monospace; font-weight: 700;
    color: var(--text-primary); background: #f9fafb; transition: border-color 0.2s;
}
.weight-number:focus { outline: none; border-color: var(--blue); background: white; }
.weight-pct { font-size: 0.82rem; font-weight: 700; color: var(--text-secondary); width: 16px; }
.weight-total-row {
    display: flex; align-items: center; justify-content: space-between;
    background: #f8fafc; border-radius: var(--radius-sm);
    padding: 9px 14px; margin: 6px 0 18px; font-size: 0.84rem;
}
.weight-total-val { font-family: 'DM Mono', monospace; font-weight: 800; font-size: 1rem; }
.weight-total-val.ok   { color: var(--green); }
.weight-total-val.bad  { color: var(--red); }
.weight-visual { height: 10px; border-radius: 99px; overflow: hidden; display: flex; margin-bottom: 18px; }
.wv-company { background: #7c3aed; transition: width 0.25s; }
.wv-admin   { background: #1a56db; transition: width 0.25s; }
.btn-primary {
    width: 100%; background: linear-gradient(135deg, #1a56db, #0e9f6e);
    color: white; border: none; border-radius: 10px; padding: 11px;
    font-size: 0.9rem; font-weight: 700; cursor: pointer; font-family: inherit;
    transition: opacity 0.2s;
}
.btn-primary:hover:not(:disabled) { opacity: 0.88; }
.btn-primary:disabled { background: #d1d5db; color: #9ca3af; cursor: not-allowed; }
.weight-save-msg { font-size: 0.78rem; text-align: center; margin-top: 10px; font-weight: 600; min-height: 18px; }
.weight-save-msg.success { color: var(--green); }
.weight-save-msg.error   { color: var(--red); }

/* ── EXTRACTION PROGRESS MODAL ── */
.extract-icon {
    width: 58px; height: 58px; border-radius: 50%;
    background: linear-gradient(135deg, #d1fae5, #6ee7b7);
    display: flex; align-items: center; justify-content: center;
    font-size: 1.6rem; margin: 0 auto 18px; border: 3px solid #6ee7b7;
}
.extract-icon.re-extract {
    background: linear-gradient(135deg, #fef9c3, #fde047);
    border-color: #fde047;
}
.progress-bar-wrap {
    background: #e5e7eb; border-radius: 99px; height: 10px;
    overflow: hidden; margin: 16px 0 10px;
}
.progress-bar-fill {
    height: 100%; border-radius: 99px;
    background: linear-gradient(90deg, #0e9f6e, #1a56db);
    width: 0%; transition: width 0.4s ease;
}
.progress-label {
    font-size: 0.78rem; color: var(--text-secondary); font-weight: 600;
    text-align: center; margin-bottom: 14px; min-height: 18px;
}
.extract-student-list {
    max-height: 180px; overflow-y: auto;
    border: 1.5px solid var(--border); border-radius: var(--radius-sm);
    margin-bottom: 16px;
}
.esl-item {
    display: flex; align-items: center; justify-content: space-between;
    padding: 8px 14px; font-size: 0.8rem;
    border-bottom: 1px solid #f1f5f9;
    transition: background 0.15s;
}
.esl-item:last-child { border-bottom: none; }
.esl-item.done   { background: #f0fdf4; }
.esl-item.skip   { background: #fafafa; opacity: 0.6; }
.esl-item.active { background: #eff6ff; }
.esl-name { font-weight: 600; color: var(--text-primary); }
.esl-status {
    font-size: 0.72rem; font-weight: 700; padding: 2px 8px;
    border-radius: 20px;
}
.esl-status.done   { background: #d1fae5; color: #065f46; }
.esl-status.skip   { background: #f3f4f6; color: #9ca3af; }
.esl-status.active { background: #dbeafe; color: #1e40af; }
.esl-status.wait   { background: #f9fafb; color: #d1d5db; }
.esl-grade { font-family: 'DM Mono', monospace; font-weight: 700; font-size: 0.8rem; color: #065f46; }

.extract-result {
    display: none;
    text-align: center; padding: 10px 0 4px;
}
.extract-result.show { display: block; }
.result-big {
    font-size: 2rem; font-weight: 900;
    font-family: 'DM Mono', monospace; color: #065f46;
    margin-bottom: 4px;
}
.result-sub { font-size: 0.82rem; color: var(--text-secondary); }

.btn-modal-close-ok {
    width: 100%; margin-top: 16px;
    background: linear-gradient(135deg, #065f46, #0e9f6e);
    color: white; border: none; border-radius: 10px; padding: 11px;
    font-size: 0.9rem; font-weight: 700; cursor: pointer; font-family: inherit;
    transition: opacity 0.2s; display: none;
}
.btn-modal-close-ok.show { display: block; }
.btn-modal-close-ok:hover { opacity: 0.88; }

/* ── RE-EXTRACT CONFIRM MODAL ── */
.reextract-warning {
    background: #fffbeb; border: 1.5px solid #fde68a;
    border-radius: var(--radius-sm); padding: 14px 16px;
    font-size: 0.83rem; color: #78350f; margin-bottom: 18px;
    display: flex; gap: 10px; align-items: flex-start;
    line-height: 1.55;
}
.reextract-warning i { flex-shrink: 0; margin-top: 2px; font-size: 1rem; color: #d97706; }
.reextract-stat-row {
    display: flex; gap: 12px; margin-bottom: 20px; flex-wrap: wrap;
}
.reextract-stat {
    flex: 1; min-width: 100px; background: #f8fafc;
    border: 1.5px solid var(--border); border-radius: var(--radius-sm);
    padding: 10px 14px; text-align: center;
}
.reextract-stat-val { font-size: 1.4rem; font-weight: 800; color: var(--text-primary); font-family: 'DM Mono', monospace; }
.reextract-stat-label { font-size: 0.7rem; color: var(--text-secondary); font-weight: 600; text-transform: uppercase; margin-top: 2px; }
.btn-row { display: flex; gap: 10px; }
.btn-cancel {
    flex: 1; background: #f3f4f6; color: var(--text-primary);
    border: 1.5px solid var(--border); border-radius: 10px; padding: 11px;
    font-size: 0.88rem; font-weight: 700; cursor: pointer; font-family: inherit;
    transition: background 0.2s;
}
.btn-cancel:hover { background: #e5e7eb; }
.btn-confirm-reextract {
    flex: 2; background: linear-gradient(135deg, #d97706, #b45309);
    color: white; border: none; border-radius: 10px; padding: 11px;
    font-size: 0.88rem; font-weight: 700; cursor: pointer; font-family: inherit;
    transition: opacity 0.2s;
}
.btn-confirm-reextract:hover { opacity: 0.88; }

/* ── TOAST ── */
.toast {
    position: fixed; bottom: 22px; left: 50%; transform: translateX(-50%);
    background: #111827; color: white; padding: 10px 22px; border-radius: 10px;
    font-size: 0.84rem; z-index: 3000; opacity: 0; transition: opacity 0.3s;
    pointer-events: none; white-space: nowrap;
}
.toast.show { opacity: 1; }

/* ── EMPTY STATE ── */
.page-empty {
    text-align: center; padding: 70px 20px;
    color: var(--text-muted); font-size: 0.95rem;
}
.page-empty i { font-size: 2.5rem; margin-bottom: 12px; display: block; opacity: 0.4; }

@media (max-width: 768px) {
    .avg-chip span.chip-label { display: none; }
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
</style>
</head>
<body>

<!-- ══════ SIDEBAR ══════ -->
<div id="sidebar" class="sidebar">
    <div class="sidebar-header">
        <h2>NEUST OJT</h2>
        <button id="toggleBtn" style="background:none;border:none;color:white;cursor:pointer;font-size:20px;"><i class="fas fa-bars"></i></button>
    </div>
    <div class="sidebar-links">
        <a href="admin_student_list.php"><i class="fas fa-users"></i><span class="link-text">Student List</span></a>
        <a href="administrator.php" style="position:relative;">
            <i class="fas fa-user-check"></i>
            <span class="link-text">Student Validation</span>
            <?php if ($app_request_count > 0): ?>
                <span class="sidebar-badge-app" id="sidebarAppBadge"><?= $app_request_count ?></span>
            <?php else: ?>
                <span class="sidebar-badge-app" id="sidebarAppBadge" style="display:none"><?= $app_request_count ?></span>
            <?php endif; ?>
        </a>
        <a href="monitoring.php"><i class="fas fa-users-cog"></i><span class="link-text">Manage Accounts</span></a>
        <a href="company_validation.php"><i class="fas fa-building"></i><span class="link-text">Company Requirements</span></a>
        <a href="admin_monitoring_dashboard.php" class="active">
            <i class="fas fa-chart-line"></i>
            <span class="link-text">Monitoring Dashboard</span>
            <?php if ($all_ungraded_count > 0): ?>
                <span class="sidebar-badge-ungraded"><?= $all_ungraded_count ?></span>
            <?php endif; ?>
        </a>
        <a href="admin_final_grades.php"><i class="fas fa-graduation-cap"></i><span class="link-text">Final Grades</span></a>
    </div>
    <div class="logout-link">
        <a href="login.php"><i class="fas fa-sign-out-alt"></i><span class="link-text">Logout</span></a>
    </div>
</div>

<!-- ══════ MAIN ══════ -->
<div class="main-content">

    <!-- PAGE HEADER -->
    <div class="page-header">
        <div>
            <h2><?= htmlspecialchars($company_name) ?> — Grade Summary</h2>
            <div class="page-header-sub">All student report grades &amp; weighted averages</div>
        </div>
        <div class="page-header-right">
            <!-- Weight pills -->
            <div class="weight-pills" id="headerWeightPills">
                <span class="weight-pill company"><span id="pillCompanyVal"><?= $weight_company ?></span>% Company</span>
                <span class="weight-pill admin"><span id="pillAdminVal"><?= $weight_admin ?></span>% Admin</span>
            </div>

            <!-- Saved status pill (shown when grades exist) -->
            <?php if ($any_already_saved): ?>
            <span class="saved-status-pill" id="savedStatusPill">
                <i class="fas fa-check-circle"></i>
                <?= $saved_count_existing ?> grade<?= $saved_count_existing != 1 ? 's' : '' ?> saved
            </span>
            <?php else: ?>
            <span class="saved-status-pill" id="savedStatusPill" style="display:none;">
                <i class="fas fa-check-circle"></i>
                <span id="savedStatusText">0 grades saved</span>
            </span>
            <?php endif; ?>

            <!-- Save All Grades button -->
            <button class="btn-save-all <?= $any_already_saved ? 'already-saved' : '' ?>"
                    id="btnSaveAll"
                    onclick="onSaveAllClick()"
                    <?= $eligible_count === 0 ? 'disabled title="No students have weighted grades yet."' : '' ?>>
                <?php if ($any_already_saved): ?>
                    <i class="fas fa-sync-alt"></i> Re-extract Final Grades
                <?php else: ?>
                    <i class="fas fa-file-export"></i> Save All Final Grades
                <?php endif; ?>
            </button>

            <!-- Grade Weights button -->
            <button class="btn-settings" onclick="openWeightModal()">
                <i class="fas fa-sliders-h"></i> Grade Weights
            </button>
            <a href="admin_reports.php?company_id=<?= $company_id ?>" class="back-btn">← Back to Reports</a>
        </div>
    </div>

    <!-- CONTENT -->
    <div class="content-wrap">

        <!-- Controls -->
        <div class="controls-bar">
            <div class="search-wrap">
                <i class="fas fa-search"></i>
                <input type="text" class="search-input" id="searchInput" placeholder="Search student…">
            </div>
            <select class="filter-select" id="filterSelect" onchange="applyFilters()">
                <option value="all">All Students</option>
                <option value="graded">Has Grades</option>
                <option value="none">No Grades Yet</option>
            </select>
            <span class="count-label" id="countLabel"><?= count($student_data) ?> student<?= count($student_data) != 1 ? 's' : '' ?></span>
        </div>

        <!-- Legend -->
        <div class="legend-bar">
            <span class="legend-item">
                <span class="legend-dot" style="background:#7c3aed;"></span>
                <span class="legend-label">Company Grade (<span id="legendCompanyPct"><?= $weight_company ?></span>%)</span>
            </span>
            <span class="legend-item">
                <span class="legend-dot" style="background:#1a56db;"></span>
                <span class="legend-label">Admin Grade (<span id="legendAdminPct"><?= $weight_admin ?></span>%)</span>
            </span>
            <span class="legend-item">
                <span class="legend-dot" style="background:#0e9f6e;"></span>
                <span class="legend-label">Weighted Average</span>
            </span>
            <span style="margin-left:auto;font-size:0.75rem;color:var(--text-muted);">
                Formula: (Company × <span id="legendFormulaC"><?= $weight_company ?></span>% + Admin × <span id="legendFormulaA"><?= $weight_admin ?></span>%) ÷ week count
            </span>
        </div>

        <!-- Student Cards -->
        <?php if (empty($student_data)): ?>
        <div class="page-empty">
            <i class="fas fa-users-slash"></i>
            No students assigned to this company yet.
        </div>
        <?php else: ?>
        <div id="cardsContainer">
        <?php foreach ($student_data as $idx => $s):
            $hasAny = $s['avg_company'] !== null || $s['avg_admin'] !== null;
        ?>
        <div class="student-card searchable<?= !$hasAny ? ' no-grades' : ' has-grades' ?>"
             id="sc-<?= $s['student_id'] ?>">

            <!-- HEADER -->
            <div class="card-header <?= !$hasAny ? 'no-data' : '' ?>" onclick="toggleStudentCard(<?= $s['student_id'] ?>)">
                <div class="student-meta">
                    <div class="student-name"><?= htmlspecialchars($s['name']) ?></div>
                    <div class="student-course"><?= htmlspecialchars($s['course']) ?></div>
                </div>

                <div class="avg-chips">
                    <?php if ($s['avg_company'] !== null): ?>
                    <span class="avg-chip company-chip"><span class="chip-label">Avg </span><?= number_format($s['avg_company'], 1) ?></span>
                    <?php else: ?>
                    <span class="avg-chip none-chip"> —</span>
                    <?php endif; ?>

                    <?php if ($s['avg_admin'] !== null): ?>
                    <span class="avg-chip admin-chip"><span class="chip-label">Avg </span><?= number_format($s['avg_admin'], 1) ?></span>
                    <?php else: ?>
                    <span class="avg-chip none-chip"> —</span>
                    <?php endif; ?>

                    <?php if ($s['avg_weighted'] !== null): ?>
                    <span class="avg-chip weighted-chip" id="wchip-<?= $s['student_id'] ?>">
                        <span class="chip-label">Final </span><?= number_format($s['avg_weighted'], 2) ?>
                    </span>
                    <?php else: ?>
                    <span class="avg-chip none-chip" id="wchip-<?= $s['student_id'] ?>"> —</span>
                    <?php endif; ?>
                </div>

                <span class="expand-arrow" id="arrow-sc-<?= $s['student_id'] ?>">▼</span>
            </div>

            <!-- BODY -->
            <div class="card-body" id="body-sc-<?= $s['student_id'] ?>">
                <div class="week-table-wrap">
                <?php if (empty($all_weeks)): ?>
                    <p style="padding:18px 20px;color:var(--text-muted);font-size:0.85rem;">No reports submitted yet for this company.</p>
                <?php else: ?>
                <table class="week-table">
                    <thead>
                        <tr>
                            <th class="th-week">Week</th>
                            <th style="color:#6d28d9;"> Company</th>
                            <th style="color:#1e40af;"> Admin</th>
                            <th style="color:#065f46;"> Weighted</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php
                    $sum_company = 0; $sum_admin = 0; $sum_weighted = 0;
                    $cnt_company = 0; $cnt_admin = 0; $cnt_weighted = 0;
                    foreach ($all_weeks as $ws):
                        $wd = $s['weeks'][$ws] ?? ['company_grade'=>null,'admin_grade'=>null,'weighted'=>null];
                        $cg = $wd['company_grade'];
                        $ag = $wd['admin_grade'];
                        $wg = $wd['weighted'];
                        if ($cg !== null) { $sum_company += $cg; $cnt_company++; }
                        if ($ag !== null) { $sum_admin   += $ag; $cnt_admin++;   }
                        if ($wg !== null) { $sum_weighted += $wg; $cnt_weighted++; }
                    ?>
                    <tr data-student="<?= $s['student_id'] ?>" data-week="<?= $ws ?>">
                        <td class="td-week"><?= weekLabel($ws) ?></td>
                        <td>
                            <?php if ($cg !== null): ?>
                                <span class="grade-val company-g"><?= $cg ?>/100</span>
                            <?php else: ?><span class="grade-none">—</span><?php endif; ?>
                        </td>
                        <td>
                            <?php if ($ag !== null): ?>
                                <span class="grade-val admin-g"><?= $ag ?>/100</span>
                            <?php else: ?><span class="grade-none">—</span><?php endif; ?>
                        </td>
                        <td class="weighted-cell" data-company="<?= $cg ?? '' ?>" data-admin="<?= $ag ?? '' ?>">
                            <?php if ($wg !== null): ?>
                            <div class="bar-wrap">
                                <span class="grade-val weighted-g"><?= number_format($wg, 1) ?></span>
                                <div class="bar-bg"><div class="bar-fill" style="width:<?= $wg ?>%"></div></div>
                            </div>
                            <?php else: ?><span class="grade-none">—</span><?php endif; ?>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                    </tbody>
                    <tfoot>
                        <tr>
                            <td class="td-week"> Average</td>
                            <td><?= $cnt_company > 0 ? '<span class="grade-val company-g">'.number_format($sum_company/$cnt_company,2).'</span>' : '<span class="grade-none">—</span>' ?></td>
                            <td><?= $cnt_admin   > 0 ? '<span class="grade-val admin-g">'.number_format($sum_admin/$cnt_admin,2).'</span>'     : '<span class="grade-none">—</span>' ?></td>
                            <td class="avg-weighted-cell" id="avgtd-<?= $s['student_id'] ?>">
                                <?php if ($cnt_weighted > 0): ?>
                                <div class="bar-wrap">
                                    <span class="grade-val weighted-g"><?= number_format($sum_weighted/$cnt_weighted,2) ?></span>
                                    <div class="bar-bg"><div class="bar-fill" style="width:<?= $sum_weighted/$cnt_weighted ?>%"></div></div>
                                </div>
                                <?php else: ?><span class="grade-none">—</span><?php endif; ?>
                            </td>
                        </tr>
                    </tfoot>
                </table>
                <?php endif; ?>
                </div>
            </div>
        </div>
        <?php endforeach; ?>
        </div>
        <?php endif; ?>

    </div><!-- end content-wrap -->
</div><!-- end main-content -->

<!-- ══════ WEIGHT SETTINGS MODAL ══════ -->
<div class="modal-overlay" id="weightModal">
    <div class="modal-box">
        <button class="modal-close" onclick="closeWeightModal()">✕</button>
        <div class="modal-title"><i class="fas fa-sliders-h" style="color:#1a56db;"></i> Grade Weight Settings</div>
        <div class="modal-subtitle">Adjust the contribution of each grade source. Both weights must add up to 100%.</div>
        <div class="weight-visual">
            <div class="wv-company" id="wvCompany" style="width:<?= $weight_company ?>%"></div>
            <div class="wv-admin"   id="wvAdmin"   style="width:<?= $weight_admin ?>%"></div>
        </div>
        <div class="weight-row">
            <div class="weight-label"><span class="dot" style="background:#7c3aed;"></span> Company</div>
            <div class="weight-input-wrap">
                <input type="range" min="0" max="100" step="1" class="weight-slider company-slider" id="sliderCompany" value="<?= $weight_company ?>" oninput="onSliderChange('company')">
                <input type="number" min="0" max="100" step="1" class="weight-number" id="numCompany" value="<?= $weight_company ?>" oninput="onNumChange('company')">
                <span class="weight-pct">%</span>
            </div>
        </div>
        <div class="weight-row">
            <div class="weight-label"><span class="dot" style="background:#1a56db;"></span> Admin</div>
            <div class="weight-input-wrap">
                <input type="range" min="0" max="100" step="1" class="weight-slider admin-slider" id="sliderAdmin" value="<?= $weight_admin ?>" oninput="onSliderChange('admin')">
                <input type="number" min="0" max="100" step="1" class="weight-number" id="numAdmin" value="<?= $weight_admin ?>" oninput="onNumChange('admin')">
                <span class="weight-pct">%</span>
            </div>
        </div>
        <div class="weight-total-row">
            <span style="font-weight:600;font-size:0.85rem;color:var(--text-secondary);">Total</span>
            <span class="weight-total-val ok" id="weightTotalVal"><?= $weight_company + $weight_admin ?>%</span>
        </div>
        <button class="btn-primary" id="btnSaveWeights" onclick="saveWeights()">
            <i class="fas fa-save"></i> Apply & Recalculate
        </button>
        <div class="weight-save-msg" id="weightSaveMsg"></div>
    </div>
</div>

<!-- ══════ RE-EXTRACT CONFIRM MODAL ══════ -->
<div class="modal-overlay" id="reExtractModal">
    <div class="modal-box">
        <button class="modal-close" onclick="closeReExtractModal()">✕</button>
        <div class="extract-icon re-extract">🔄</div>
        <div class="modal-title" style="justify-content:center;margin-bottom:4px;">Re-extract Final Grades?</div>
        <div class="modal-subtitle" style="text-align:center;margin-bottom:16px;">
            Final grades for this company have already been saved.
        </div>
        <div class="reextract-stat-row">
            <div class="reextract-stat">
                <div class="reextract-stat-val" id="reStatExisting"><?= $saved_count_existing ?></div>
                <div class="reextract-stat-label">Already Saved</div>
            </div>
            <div class="reextract-stat">
                <div class="reextract-stat-val" id="reStatEligible"><?= $eligible_count ?></div>
                <div class="reextract-stat-label">Eligible Now</div>
            </div>
        </div>
        <div class="reextract-warning">
            <i class="fas fa-exclamation-triangle"></i>
            <span>This will <strong>overwrite</strong> all previously saved final grades with the current weighted averages and weight settings. Published grades will remain visible to students but their values will be updated.</span>
        </div>
        <div class="btn-row">
            <button class="btn-cancel" onclick="closeReExtractModal()">
                <i class="fas fa-times"></i> No, Cancel
            </button>
            <button class="btn-confirm-reextract" onclick="confirmReExtract()">
                <i class="fas fa-sync-alt"></i> Yes, Re-extract
            </button>
        </div>
    </div>
</div>

<!-- ══════ EXTRACTION PROGRESS MODAL ══════ -->
<div class="modal-overlay" id="extractProgressModal">
    <div class="modal-box" style="max-width:460px;">
        <div class="extract-icon" id="extractIcon">📊</div>
        <div class="modal-title" style="justify-content:center;" id="extractTitle">Extracting Final Grades…</div>
        <div class="modal-subtitle" style="text-align:center;" id="extractSubtitle">
            Saving weighted averages for all eligible students.
        </div>

        <!-- Progress bar -->
        <div class="progress-bar-wrap">
            <div class="progress-bar-fill" id="extractProgressBar"></div>
        </div>
        <div class="progress-label" id="extractProgressLabel">Preparing…</div>

        <!-- Student list -->
        <div class="extract-student-list" id="extractStudentList">
            <!-- populated by JS -->
        </div>

        <!-- Result summary (shown after done) -->
        <div class="extract-result" id="extractResult">
            <div class="result-big" id="extractResultBig">—</div>
            <div class="result-sub" id="extractResultSub"></div>
        </div>

        <button class="btn-modal-close-ok" id="btnExtractDone" onclick="closeExtractModal()">
            <i class="fas fa-check"></i> Done
        </button>
    </div>
</div>

<div class="toast" id="toast"></div>

<script>
const COMPANY_ID   = <?= $company_id ?>;
let _wCompany      = <?= $weight_company ?>;
let _wAdmin        = <?= $weight_admin ?>;
let _alreadySaved  = <?= $any_already_saved ? 'true' : 'false' ?>;

const STUDENT_DATA = <?= json_encode(array_map(fn($s) => [
    'student_id'  => $s['student_id'],
    'name'        => $s['name'],
    'weeks'       => $s['weeks'],
    'avg_company' => $s['avg_company'],
    'avg_admin'   => $s['avg_admin'],
    'avg_weighted'=> $s['avg_weighted'],
], $student_data)) ?>;

/* ── SIDEBAR TOGGLE ── */
document.getElementById('toggleBtn').addEventListener('click', () => {
    const sb = document.getElementById('sidebar');
    sb.classList.toggle('collapsed');
    const mc = document.querySelector('.main-content');
    mc.style.marginLeft = sb.classList.contains('collapsed') ? '80px' : '260px';
    mc.style.width      = sb.classList.contains('collapsed') ? 'calc(100% - 80px)' : 'calc(100% - 260px)';
});

/* ── CARD TOGGLE ── */
function toggleStudentCard(sid) {
    const body  = document.getElementById('body-sc-' + sid);
    const arrow = document.getElementById('arrow-sc-' + sid);
    const open  = body.classList.contains('open');
    body.classList.toggle('open', !open);
    arrow.classList.toggle('open', !open);
    arrow.textContent = open ? '▼' : '▲';
}

/* ── SEARCH / FILTER ── */
document.getElementById('searchInput').addEventListener('keyup', applyFilters);
function applyFilters() {
    const q   = document.getElementById('searchInput').value.toLowerCase();
    const fil = document.getElementById('filterSelect').value;
    let shown = 0;
    document.querySelectorAll('.student-card').forEach(card => {
        const matchQ    = card.innerText.toLowerCase().includes(q);
        const hasGrades = card.classList.contains('has-grades');
        const matchF    = (fil === 'all') || (fil === 'graded' && hasGrades) || (fil === 'none' && !hasGrades);
        card.style.display = (matchQ && matchF) ? '' : 'none';
        if (matchQ && matchF) shown++;
    });
    document.getElementById('countLabel').textContent = shown + ' student' + (shown !== 1 ? 's' : '');
}

/* ── WEIGHT MODAL ── */
function openWeightModal() {
    document.getElementById('weightModal').classList.add('open');
    setModalValues(_wCompany, _wAdmin);
}
function closeWeightModal() {
    document.getElementById('weightModal').classList.remove('open');
    document.getElementById('weightSaveMsg').textContent = '';
    document.getElementById('weightSaveMsg').className = 'weight-save-msg';
}
document.getElementById('weightModal').addEventListener('click', e => {
    if (e.target === document.getElementById('weightModal')) closeWeightModal();
});

function setModalValues(c, a) {
    document.getElementById('sliderCompany').value = c;
    document.getElementById('numCompany').value    = c;
    document.getElementById('sliderAdmin').value   = a;
    document.getElementById('numAdmin').value      = a;
    updateWeightUI(c, a);
}
function onSliderChange(which) {
    let c = parseInt(document.getElementById('sliderCompany').value, 10) || 0;
    let a = parseInt(document.getElementById('sliderAdmin').value,   10) || 0;
    if (which === 'company') a = 100 - c; else c = 100 - a;
    c = Math.max(0, Math.min(100, c)); a = Math.max(0, Math.min(100, a));
    setModalValues(c, a);
}
function onNumChange(which) {
    let c = parseInt(document.getElementById('numCompany').value, 10) || 0;
    let a = parseInt(document.getElementById('numAdmin').value,   10) || 0;
    c = Math.max(0, Math.min(100, c)); a = Math.max(0, Math.min(100, a));
    updateWeightUI(c, a);
    document.getElementById('sliderCompany').value = c;
    document.getElementById('sliderAdmin').value   = a;
}
function updateWeightUI(c, a) {
    document.getElementById('numCompany').value = c;
    document.getElementById('numAdmin').value   = a;
    const total = c + a;
    const tel   = document.getElementById('weightTotalVal');
    tel.textContent = total + '%';
    tel.className   = 'weight-total-val ' + (total === 100 ? 'ok' : 'bad');
    document.getElementById('wvCompany').style.width = c + '%';
    document.getElementById('wvAdmin').style.width   = a + '%';
    document.getElementById('btnSaveWeights').disabled = (total !== 100);
}
function saveWeights() {
    const c = parseInt(document.getElementById('numCompany').value, 10) || 0;
    const a = parseInt(document.getElementById('numAdmin').value,   10) || 0;
    if (c + a !== 100) { showWeightMsg('Weights must sum to 100%.', 'error'); return; }
    const btn = document.getElementById('btnSaveWeights');
    btn.disabled = true;
    btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Saving…';
    const fd = new FormData();
    fd.append('company_weight', c);
    fd.append('admin_weight', a);
    fetch('admin_grade_summary.php?company_id=' + COMPANY_ID + '&save_weights=1', { method: 'POST', body: fd })
        .then(r => r.json())
        .then(data => {
            if (data.success) {
                _wCompany = data.company_weight;
                _wAdmin   = data.admin_weight;
                updateLegend();
                recalculateAll();
                showWeightMsg('✅ Weights saved & recalculated!', 'success');
                showToast('✅ Grade weights updated.');
                setTimeout(closeWeightModal, 1200);
            } else {
                showWeightMsg('❌ ' + (data.message || 'Save failed.'), 'error');
            }
        })
        .catch(() => showWeightMsg('❌ Network error.', 'error'))
        .finally(() => {
            btn.disabled = false;
            btn.innerHTML = '<i class="fas fa-save"></i> Apply & Recalculate';
        });
}
function showWeightMsg(msg, type) {
    const el = document.getElementById('weightSaveMsg');
    el.textContent = msg; el.className = 'weight-save-msg ' + type;
}

/* ── LEGEND UPDATE ── */
function updateLegend() {
    document.getElementById('legendCompanyPct').textContent = _wCompany;
    document.getElementById('legendAdminPct').textContent   = _wAdmin;
    document.getElementById('legendFormulaC').textContent   = _wCompany;
    document.getElementById('legendFormulaA').textContent   = _wAdmin;
    document.getElementById('pillCompanyVal').textContent   = _wCompany;
    document.getElementById('pillAdminVal').textContent     = _wAdmin;
}

/* ── CLIENT-SIDE RECALCULATION ── */
function recalculateAll() {
    STUDENT_DATA.forEach(s => {
        const sid = s.student_id;
        let sumW = 0, cntW = 0;
        document.querySelectorAll('[data-student="' + sid + '"]').forEach(row => {
            const week = row.dataset.week;
            const wd   = s.weeks[week] || {};
            const cg   = wd.company_grade;
            const ag   = wd.admin_grade;
            const cell = row.querySelector('.weighted-cell');
            if (!cell) return;
            if (cg !== null && cg !== undefined && ag !== null && ag !== undefined) {
                const wg = Math.round((cg * _wCompany + ag * _wAdmin) / 100 * 100) / 100;
                sumW += wg; cntW++;
                cell.innerHTML = `<div class="bar-wrap"><span class="grade-val weighted-g">${wg.toFixed(1)}</span><div class="bar-bg"><div class="bar-fill" style="width:${Math.min(100,wg)}%"></div></div></div>`;
            } else {
                cell.innerHTML = '<span class="grade-none">—</span>';
            }
        });
        const newAvg = cntW > 0 ? sumW / cntW : null;
        s.avg_weighted = newAvg;
        const avgTd = document.getElementById('avgtd-' + sid);
        if (avgTd) {
            if (newAvg !== null) {
                avgTd.innerHTML = `<div class="bar-wrap"><span class="grade-val weighted-g">${newAvg.toFixed(2)}</span><div class="bar-bg"><div class="bar-fill" style="width:${Math.min(100,newAvg)}%"></div></div></div>`;
            } else {
                avgTd.innerHTML = '<span class="grade-none">—</span>';
            }
        }
        const chip = document.getElementById('wchip-' + sid);
        if (chip) {
            if (newAvg !== null) {
                chip.className = 'avg-chip weighted-chip';
                chip.innerHTML = ' <span class="chip-label">Final </span>' + newAvg.toFixed(2);
            } else {
                chip.className = 'avg-chip none-chip';
                chip.innerHTML = ' —';
            }
        }
    });
    // Update eligible count display
    const eligible = STUDENT_DATA.filter(s => s.avg_weighted !== null).length;
    document.getElementById('reStatEligible').textContent = eligible;
    // Re-enable/disable save-all button
    const btn = document.getElementById('btnSaveAll');
    btn.disabled = (eligible === 0);
}

/* ══════════════════════════════════════
   SAVE ALL FLOW
══════════════════════════════════════ */
function onSaveAllClick() {
    if (_alreadySaved) {
        // Show re-extract confirmation modal
        document.getElementById('reExtractModal').classList.add('open');
    } else {
        // First time — go straight to extraction
        runExtraction();
    }
}

/* Re-extract modal */
function closeReExtractModal() {
    document.getElementById('reExtractModal').classList.remove('open');
}
document.getElementById('reExtractModal').addEventListener('click', e => {
    if (e.target === document.getElementById('reExtractModal')) closeReExtractModal();
});
function confirmReExtract() {
    closeReExtractModal();
    runExtraction();
}

/* Close extract progress modal */
function closeExtractModal() {
    document.getElementById('extractProgressModal').classList.remove('open');
}

/* ── EXTRACTION RUNNER ── */
async function runExtraction() {
    const eligible = STUDENT_DATA.filter(s => s.avg_weighted !== null);
    const skipped  = STUDENT_DATA.filter(s => s.avg_weighted === null);

    // Build the student list UI
    const listEl = document.getElementById('extractStudentList');
    listEl.innerHTML = '';
    const allStudents = [...eligible, ...skipped];
    allStudents.forEach(s => {
        const item = document.createElement('div');
        item.className = 'esl-item' + (s.avg_weighted === null ? ' skip' : '');
        item.id = 'eslitem-' + s.student_id;
        item.innerHTML = `
            <span class="esl-name">${escHtml(s.name)}</span>
            <div style="display:flex;align-items:center;gap:8px;">
                ${s.avg_weighted !== null ? `<span class="esl-grade">${s.avg_weighted.toFixed(2)}</span>` : ''}
                <span class="esl-status ${s.avg_weighted === null ? 'skip' : 'wait'}" id="eslst-${s.student_id}">
                    ${s.avg_weighted === null ? 'No grade' : 'Waiting'}
                </span>
            </div>`;
        listEl.appendChild(item);
    });

    // Reset progress UI
    document.getElementById('extractProgressBar').style.width = '0%';
    document.getElementById('extractProgressLabel').textContent = 'Preparing…';
    document.getElementById('extractResult').classList.remove('show');
    document.getElementById('btnExtractDone').classList.remove('show');
    document.getElementById('extractIcon').textContent = '📊';
    document.getElementById('extractTitle').textContent = 'Extracting Final Grades…';
    document.getElementById('extractSubtitle').textContent =
        `Saving ${eligible.length} eligible student${eligible.length !== 1 ? 's' : ''}…`;

    // Open progress modal
    document.getElementById('extractProgressModal').classList.add('open');

    // Animate progress briefly before sending
    await animateProgress(0, 20, 300);
    document.getElementById('extractProgressLabel').textContent = `Processing ${eligible.length} student${eligible.length !== 1 ? 's' : ''}…`;

    // Mark eligible as active
    eligible.forEach(s => {
        const item = document.getElementById('eslitem-' + s.student_id);
        const st   = document.getElementById('eslst-'   + s.student_id);
        if (item) item.className = 'esl-item active';
        if (st)   { st.className = 'esl-status active'; st.textContent = 'Saving…'; }
    });

    await animateProgress(20, 60, 500);

    // Build payload
    const payload = eligible.map(s => ({
        student_id:   s.student_id,
        avg_company:  s.avg_company  !== null ? s.avg_company  : '',
        avg_admin:    s.avg_admin    !== null ? s.avg_admin    : '',
        avg_weighted: s.avg_weighted,
    }));

    const fd = new FormData();
    fd.append('students',       JSON.stringify(payload));
    fd.append('weight_company', _wCompany);
    fd.append('weight_admin',   _wAdmin);

    let result;
    try {
        const resp = await fetch('admin_grade_summary.php?company_id=' + COMPANY_ID + '&save_all_grades=1', {
            method: 'POST', body: fd
        });
        result = await resp.json();
    } catch(e) {
        result = { success: false, message: 'Network error.' };
    }

    await animateProgress(60, 100, 400);

    if (result.success) {
        // Mark all eligible as done
        eligible.forEach(s => {
            const item = document.getElementById('eslitem-' + s.student_id);
            const st   = document.getElementById('eslst-'   + s.student_id);
            if (item) item.className = 'esl-item done';
            if (st)   { st.className = 'esl-status done'; st.textContent = '✓ Saved'; }
        });

        document.getElementById('extractProgressLabel').textContent = 'All done!';
        document.getElementById('extractIcon').textContent = '✅';
        document.getElementById('extractTitle').textContent = 'Extraction Complete!';
        document.getElementById('extractSubtitle').textContent = '';

        const saved   = result.saved   || 0;
        const skippedN= result.skipped || 0;
        document.getElementById('extractResultBig').textContent = saved + ' saved';
        document.getElementById('extractResultSub').textContent =
            (skippedN > 0 ? skippedN + ' student' + (skippedN !== 1 ? 's' : '') + ' skipped (no weighted grade).' : 'All eligible students processed.');
        document.getElementById('extractResult').classList.add('show');
        document.getElementById('btnExtractDone').classList.add('show');

        // Update header button + pill to reflect re-saved state
        _alreadySaved = true;
        const btn = document.getElementById('btnSaveAll');
        btn.className = 'btn-save-all already-saved';
        btn.innerHTML = '<i class="fas fa-sync-alt"></i> Re-extract Final Grades';

        const pill = document.getElementById('savedStatusPill');
        pill.style.display = '';
        const pillText = document.getElementById('savedStatusText');
        if (pillText) pillText.textContent = saved + ' grade' + (saved !== 1 ? 's' : '') + ' saved';
        else pill.innerHTML = '<i class="fas fa-check-circle"></i> ' + saved + ' grade' + (saved !== 1 ? 's' : '') + ' saved';

        showToast('✅ ' + saved + ' final grade' + (saved !== 1 ? 's' : '') + ' saved successfully.');
    } else {
        document.getElementById('extractIcon').textContent = '❌';
        document.getElementById('extractTitle').textContent = 'Extraction Failed';
        document.getElementById('extractSubtitle').textContent = result.message || 'An error occurred.';
        document.getElementById('extractProgressLabel').textContent = '';
        document.getElementById('btnExtractDone').classList.add('show');
        showToast('❌ ' + (result.message || 'Save failed.'));
    }
}

/* Smooth progress bar animation */
function animateProgress(from, to, durationMs) {
    return new Promise(resolve => {
        const bar   = document.getElementById('extractProgressBar');
        const steps = 20;
        const step  = (to - from) / steps;
        let current = from;
        let i = 0;
        const interval = setInterval(() => {
            current += step;
            bar.style.width = Math.min(current, 100) + '%';
            i++;
            if (i >= steps) { clearInterval(interval); resolve(); }
        }, durationMs / steps);
    });
}

function escHtml(str) {
    return String(str).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
}

/* ── TOAST ── */
function showToast(msg) {
    const t = document.getElementById('toast');
    t.textContent = msg;
    t.classList.add('show');
    setTimeout(() => t.classList.remove('show'), 2800);
}
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
            })
            .catch(() => {});
    }
    setTimeout(() => { pollAppBadge(); setInterval(pollAppBadge, 30000); }, 6000);
})();
</script>
</body>
</html>