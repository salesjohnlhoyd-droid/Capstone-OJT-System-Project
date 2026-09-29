<?php
session_start();
include "db.php";
require_once 'EVAL_form_builder.php';

if (!isset($_SESSION['user_id']) || $_SESSION['role'] != "company") {
    die("Access denied.");
}

$company_id = $_SESSION['user_id'];

$company_name = '';
$company_address = '';
$company_telephone = '';
$stmt_company = $conn->prepare("SELECT company, company_address, telephone FROM company_information WHERE user_id = ?");
$stmt_company->bind_param("i", $company_id);
$stmt_company->execute();
$result_company = $stmt_company->get_result();
if ($row_company = $result_company->fetch_assoc()) {
    $company_name      = $row_company['company'];
    $company_address   = trim((string)($row_company['company_address'] ?? ''));
    $company_telephone = trim((string)($row_company['telephone'] ?? ''));
}
$stmt_company->close();

// Fetch company rep display name (for sidebar header, like attendance_management supervisor name)
$company_rep_display = 'Company';
$stmt_crep = $conn->prepare("SELECT first_name, middle_name, last_name FROM users WHERE id=? LIMIT 1");
$stmt_crep->bind_param("i", $company_id);
$stmt_crep->execute();
$crep_row = $stmt_crep->get_result()->fetch_assoc();
$stmt_crep->close();
if ($crep_row) {
    $crep_mn = trim($crep_row['middle_name'] ?? '');
    $company_rep_display = trim(
        ($crep_row['first_name'] ?? '') .
        ($crep_mn ? ' ' . $crep_mn : '') .
        ' ' . ($crep_row['last_name'] ?? '')
    );
    if (!$company_rep_display) $company_rep_display = 'Company';
}

$ojt_start_date = '';
$stmt_ojt_start = $conn->prepare("SELECT MIN(date) as start_date FROM attendance_settings WHERE company_id = ?");
$stmt_ojt_start->bind_param("i", $company_id);
$stmt_ojt_start->execute();
$ojt_start_row = $stmt_ojt_start->get_result()->fetch_assoc();
$ojt_start_date = $ojt_start_row['start_date'] ?? '';
$stmt_ojt_start->close();

$pending_lr_count = 0;
$stmt_plr = $conn->prepare("SELECT COUNT(*) as total FROM late_requests WHERE company_id=? AND status='pending'");
$stmt_plr->bind_param("i", $company_id);
$stmt_plr->execute();
$res_plr = $stmt_plr->get_result()->fetch_assoc();
$pending_lr_count = $res_plr['total'] ?? 0;
$stmt_plr->close();

$inbox_count = 0;
$stmt_inbox = $conn->prepare("SELECT COUNT(*) as total FROM ojt_applications WHERE company_id=? AND phase='pending'");
$stmt_inbox->bind_param("i", $company_id);
$stmt_inbox->execute();
$res_inbox = $stmt_inbox->get_result()->fetch_assoc();
$inbox_count = $res_inbox['total'] ?? 0;
$stmt_inbox->close();

/* NOTE: Company grading of weekly reports has been removed entirely,
   so the previous "ungraded reports" counter/query that fed the
   sidebar badge on the Company Reports link has been removed. */

function blobIsHTML(string $blob): bool {
    if (strlen($blob) >= 2 && substr($blob, 0, 2) === 'PK') return false;
    $head = ltrim(substr($blob, 0, 100));
    return (stripos($head, '<!doctype') === 0 || stripos($head, '<html') === 0);
}

function blobIsPDF(string $blob): bool {
    return substr($blob, 0, 4) === '%PDF';
}

function getStudentCoordinatorName(mysqli $conn, int $student_id): string {
    $stmt = $conn->prepare("
        SELECT
            COALESCE(ojt_coordinator_first,  '') AS first_name,
            COALESCE(ojt_coordinator_middle, '') AS middle_name,
            COALESCE(ojt_coordinator_last,   '') AS last_name
        FROM student_information
        WHERE user_id = ?
        LIMIT 1
    ");
    if (!$stmt) return '';
    $stmt->bind_param("i", $student_id);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if (!$row) return '';
    $first  = trim($row['first_name']);
    $middle = trim($row['middle_name']);
    $last   = trim($row['last_name']);
    $full = trim(
        ($first  !== '' ? $first  . ' ' : '') .
        ($middle !== '' ? $middle . ' ' : '') .
        $last
    );
    return $full;
}

/* ============================================================
   COLUMN-EXISTENCE HELPER
   ------------------------------------------------------------
   NEW: generic, cached helper used to safely probe whether a given
   column exists on a given table before selecting it. Used by
   getStudentAccomInfo() below to pull the College field without
   hard-failing if a particular installation's `student_information`
   table happens to name (or not have) that column differently —
   mirrors the same defensive "probe first" style already used
   elsewhere in this file (see ensureEvalRatingColumns() and
   getCompanyRepName()'s candidate_cols loop).
   ============================================================ */
function columnExists(mysqli $conn, string $table, string $column): bool {
    static $cache = [];
    $safeTable = preg_replace('/[^a-zA-Z0-9_]/', '', $table);
    $safeCol   = preg_replace('/[^a-zA-Z0-9_]/', '', $column);
    $key = $safeTable . '.' . $safeCol;
    if (isset($cache[$key])) return $cache[$key];
    $exists = false;
    $res = $conn->query("SHOW COLUMNS FROM `{$safeTable}` LIKE '{$safeCol}'");
    if ($res) {
        $exists = $res->num_rows > 0;
        $res->free();
    }
    $cache[$key] = $exists;
    return $exists;
}

/* ============================================================
   NAME NORMALIZATION HELPER (NEW)
   ------------------------------------------------------------
   NEW: used by getAdminSchoolInfoByCoordinatorName() below to make
   the coordinator-name-to-admin-account match resilient to small,
   everyday formatting differences between how a student typed their
   OJT Coordinator's name on their own form and how that same person's
   name is stored on their own admin account (e.g. a double space, a
   stray leading/trailing space, different letter casing, or the
   student having typed the coordinator's middle name into the wrong
   box). Collapses ALL internal whitespace runs down to a single
   space (not just trims the ends) and lowercases the result, so
   "Juan   Dela  Cruz" and "juan dela cruz" compare as identical.
   ============================================================ */
function tpNormalizeName(string $s): string {
    $s = trim($s);
    $s = preg_replace('/\s+/', ' ', $s);
    return mb_strtolower($s, 'UTF-8');
}

/* ============================================================
   NEW: FETCH SCHOOL INFORMATION FROM THE MATCHING ADMIN ACCOUNT
   ------------------------------------------------------------
   The "Create Admin Account" form on monitoring.php now collects
   School (campus branch), School Address, Subject, Contact No.,
   and Required No. of hours for each admin account (stored on the
   `admins` table's school / school_address / subject / contact_no /
   required_hours columns — see ensureAdminExtraColumns() in
   monitoring.php).

   The Training Plan's "School information" section previously tried
   to pull these same fields from `student_information` (which the
   student never actually fills in for School/Subject/Required
   hours/Coordinator telephone). Since the Training Plan already
   displays the student's OJT Coordinator name (resolved via
   getStudentCoordinatorName() from student_information's
   ojt_coordinator_first/middle/last columns), this looks up the
   admin account whose name matches that same OJT coordinator and
   pulls School / School address / Subject / Required no. of hours /
   Coordinator telephone no. from that admin's own record instead.

   FIX (this revision — blank School/Subject/etc. despite the admin
   account actually having that data on file): the previous version
   of this function matched purely in SQL with
   `LOWER(TRIM(first_name)) = LOWER(TRIM(?))` /
   `LOWER(TRIM(last_name)) = LOWER(TRIM(?))`. TRIM() in MySQL only
   strips *leading/trailing* whitespace — it does nothing about a
   double space typed in the middle of a name, and it offered no
   tolerance for the student having typed the coordinator's middle
   name into the "first name" portion of the OJT Coordinator field
   (a common real-world mismatch, since the two names are captured
   on two completely independent forms — the student's own profile
   vs. the admin's own account). Because the match is a hard SQL
   equality, any of those small formatting differences caused zero
   rows to come back, and every field was silently left blank (never
   a fatal error) even though the row existed in `admins` the whole
   time.

   Fix: pull the (small) list of admin accounts once and compare
   names in PHP using tpNormalizeName() (trims, collapses all
   internal whitespace runs, lowercases) against TWO candidate
   strings built from the student-typed coordinator name:
     1. "first last"        (ignoring whatever was typed as middle)
     2. "first middle last" (the full three-part name, if a middle
                              name was typed)
   and matches those against the admin's own equivalent two
   candidates ("first last" and "first middle last"). If any of the
   four combinations line up, that's treated as a match. This keeps
   the exact same intent (match the OJT Coordinator to their own
   admin account by name) while being tolerant of the everyday
   formatting/splitting differences described above — with no change
   to what is ultimately returned or how callers use it.
   ============================================================ */
function getAdminSchoolInfoByCoordinatorName(mysqli $conn, string $first, string $middle, string $last): array {
    $out = [
        'school_name'     => '',
        'school_address'  => '',
        'subject'         => '',
        'required_hours'  => '',
        'coordinator_tel' => '',
    ];

    $first  = trim($first);
    $middle = trim($middle);
    $last   = trim($last);
    if ($first === '' && $last === '') {
        return $out;
    }

    // Candidate strings built from the student-typed coordinator name.
    $coordFirstLast = tpNormalizeName($first . ' ' . $last);
    $coordFull      = tpNormalizeName($first . ($middle !== '' ? ' ' . $middle : '') . ' ' . $last);

    $res = $conn->query("SELECT school, school_address, subject, contact_no, required_hours, first_name, middle_name, last_name FROM admins");
    if (!$res) return $out;

    $matchRow = null;
    while ($row = $res->fetch_assoc()) {
        $aFirst  = trim((string)($row['first_name']  ?? ''));
        $aMiddle = trim((string)($row['middle_name'] ?? ''));
        $aLast   = trim((string)($row['last_name']   ?? ''));
        if ($aFirst === '' && $aLast === '') continue;

        $adminFirstLast = tpNormalizeName($aFirst . ' ' . $aLast);
        $adminFull      = tpNormalizeName($aFirst . ($aMiddle !== '' ? ' ' . $aMiddle : '') . ' ' . $aLast);

        if (
            $adminFirstLast === $coordFirstLast ||
            $adminFirstLast === $coordFull      ||
            $adminFull      === $coordFirstLast ||
            $adminFull      === $coordFull
        ) {
            $matchRow = $row;
            break;
        }
    }
    $res->free();

    if (!$matchRow) return $out;

    $out['school_name']     = trim((string)($matchRow['school']         ?? ''));
    $out['school_address']  = trim((string)($matchRow['school_address'] ?? ''));
    $out['subject']         = trim((string)($matchRow['subject']        ?? ''));
    $out['required_hours']  = trim((string)($matchRow['required_hours'] ?? ''));
    $out['coordinator_tel'] = trim((string)($matchRow['contact_no']     ?? ''));

    return $out;
}

/* ============================================================
   NEW: FETCH STUDENT ACCOMPLISHMENT-FORM (AccomForm.php) DATA
   ------------------------------------------------------------
   AccomForm.php (the student-facing "Digital Requirements
   Submission" page) already collects and persists Age, Sex, Home
   Address, Mobile No., and Guardian info (guardian_type /
   guardian_other/guardian_no, plus mother_* / father_* names) into
   the `student_information` table via its "save_profile_info"
   handler.

   The OJT/Internship Training Plan (Section: Personal information)
   asks the company rep to fill in the SAME data by hand — Age, Sex,
   Home address, Home telephone no., Parent/guardian, and Guardian
   telephone no. — even though the student already supplied it on
   AccomForm.php. This helper pulls that already-submitted data so
   the Training Plan's Personal information fields can be
   pre-filled automatically for the company rep.

   Guardian name resolution mirrors AccomForm.php's own guardian_type
   logic exactly:
     - guardian_type === 'Mother' -> use mother_first/middle/last
     - guardian_type === 'Father' -> use father_first/middle/last
     - guardian_type === 'Other'  -> use the already-combined
                                      guardian_other string
   (AccomForm.php builds guardian_other as
   trim(first . ' ' . middle . ' ' . last) at save time, so it is
   already a ready-to-use full name here.)

   Home telephone no. fallback: `student_information` has no
   dedicated "home telephone" column — the closest equivalent the
   student actually supplies is their Mobile Number (mobile_no).
   Per the requested behavior, if that number is not available
   (N/A / blank), the guardian's telephone number (guardian_no) is
   used instead so the field is never left without a contact number
   when one exists on file.

   UPDATED (School information source): School, School address,
   Subject, Required no. of hours, and Coordinator telephone no. are
   now pulled from the matching ADMIN account created via monitoring.php's
   "Create Admin Account" flow (see getAdminSchoolInfoByCoordinatorName()
   above), matched against the student's OJT Coordinator name — NOT
   from `student_information` anymore. College is the one exception:
   it continues to be pulled from `student_information` (populated by
   the student on AccomForm.php's Academic Data section, where the
   `college` column is guaranteed to exist via AccomForm.php's own
   ensureCollegeColumn() lazy migration), the same defensive
   "probe first" way as before via columnExists().
   ============================================================ */
function getStudentAccomInfo(mysqli $conn, int $student_id): array {
    $out = [
        'age'           => '',
        'sex'           => '',
        'home_address'  => '',
        'home_tel'      => '',
        'guardian_name' => '',
        'guardian_tel'  => '',
        /* School-information fields (see doc-comment above). */
        'school_name'      => '',
        'school_address'   => '',
        'college'          => '',
        'subject'          => '',
        'required_hours'   => '',
        'coordinator_tel'  => '',
    ];

    $stmt = $conn->prepare("
        SELECT age, sex, home_address, mobile_no,
               guardian_type, guardian_other, guardian_no,
               mother_first, mother_middle, mother_last,
               father_first, father_middle, father_last,
               ojt_coordinator_first, ojt_coordinator_middle, ojt_coordinator_last
        FROM student_information
        WHERE user_id = ?
        LIMIT 1
    ");
    if (!$stmt) return $out;
    $stmt->bind_param("i", $student_id);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if (!$row) return $out;

    $out['age']          = trim((string)($row['age'] ?? ''));
    $out['sex']           = trim((string)($row['sex'] ?? ''));
    $out['home_address']  = trim((string)($row['home_address'] ?? ''));

    $guardian_no          = trim((string)($row['guardian_no'] ?? ''));
    $out['guardian_tel']  = $guardian_no;

    /* Home telephone no.: prefer the student's own mobile number;
       fall back to the guardian's number if the student has none
       on file (N/A / blank), so the field isn't left empty when a
       usable contact number exists. */
    $mobile_no       = trim((string)($row['mobile_no'] ?? ''));
    $out['home_tel'] = $mobile_no !== '' ? $mobile_no : $guardian_no;

    $guardian_type = trim((string)($row['guardian_type'] ?? ''));
    if ($guardian_type === 'Mother') {
        $mm = trim((string)($row['mother_middle'] ?? ''));
        $out['guardian_name'] = trim(
            trim((string)($row['mother_first'] ?? '')) . ' ' .
            ($mm !== '' ? $mm . ' ' : '') .
            trim((string)($row['mother_last'] ?? ''))
        );
    } elseif ($guardian_type === 'Father') {
        $fm = trim((string)($row['father_middle'] ?? ''));
        $out['guardian_name'] = trim(
            trim((string)($row['father_first'] ?? '')) . ' ' .
            ($fm !== '' ? $fm . ' ' : '') .
            trim((string)($row['father_last'] ?? ''))
        );
    } elseif ($guardian_type === 'Other') {
        $out['guardian_name'] = trim((string)($row['guardian_other'] ?? ''));
    }

    /* UPDATED: School / School address / Subject / Required no. of
       hours / Coordinator telephone no. now come from the ADMIN
       account matching this student's OJT Coordinator name, instead
       of from student_information. */
    $coord_first  = trim((string)($row['ojt_coordinator_first']  ?? ''));
    $coord_middle = trim((string)($row['ojt_coordinator_middle'] ?? ''));
    $coord_last   = trim((string)($row['ojt_coordinator_last']   ?? ''));

    $admin_school_info = getAdminSchoolInfoByCoordinatorName($conn, $coord_first, $coord_middle, $coord_last);
    $out['school_name']      = $admin_school_info['school_name'];
    $out['school_address']   = $admin_school_info['school_address'];
    $out['subject']          = $admin_school_info['subject'];
    $out['required_hours']   = $admin_school_info['required_hours'];
    $out['coordinator_tel']  = $admin_school_info['coordinator_tel'];

    /* College: still pulled from `student_information` (populated by
       the student on AccomForm.php). Looked up via one or more
       candidate column names (checked with columnExists()) so this
       stays safe even if a particular installation names the column
       differently; if no candidate exists, the field is simply left
       blank (never a fatal error). */
    $school_field_candidates = [
        'college' => ['college'],
    ];

    $resolved_cols = []; // out-key => actual column name found on the table
    foreach ($school_field_candidates as $out_key => $candidates) {
        foreach ($candidates as $candidate_col) {
            if (columnExists($conn, 'student_information', $candidate_col)) {
                $resolved_cols[$out_key] = $candidate_col;
                break;
            }
        }
    }

    if (!empty($resolved_cols)) {
        $select_parts = [];
        foreach ($resolved_cols as $out_key => $col) {
            $select_parts[] = "`{$col}` AS `{$out_key}`";
        }
        $school_sql = "SELECT " . implode(', ', $select_parts) . " FROM student_information WHERE user_id = ? LIMIT 1";
        $stmt_school = $conn->prepare($school_sql);
        if ($stmt_school) {
            $stmt_school->bind_param("i", $student_id);
            $stmt_school->execute();
            $school_row = $stmt_school->get_result()->fetch_assoc();
            $stmt_school->close();
            if ($school_row) {
                foreach ($resolved_cols as $out_key => $col) {
                    $out[$out_key] = trim((string)($school_row[$out_key] ?? ''));
                }
            }
        }
    }

    return $out;
}

function getCompanyRepName(mysqli $conn, int $company_id, string $company_name): string {
    $stmt1 = $conn->prepare("
        SELECT
            COALESCE(first_name,  '') AS first_name,
            COALESCE(middle_name, '') AS middle_name,
            COALESCE(last_name,   '') AS last_name
        FROM users
        WHERE id = ?
        LIMIT 1
    ");
    if ($stmt1) {
        $stmt1->bind_param("i", $company_id);
        $stmt1->execute();
        $row1 = $stmt1->get_result()->fetch_assoc();
        $stmt1->close();
        if ($row1) {
            $first  = trim($row1['first_name']  ?? '');
            $middle = trim($row1['middle_name'] ?? '');
            $last   = trim($row1['last_name']   ?? '');
            $full = trim(
                ($first  !== '' ? $first  . ' ' : '') .
                ($middle !== '' ? $middle . ' ' : '') .
                $last
            );
            if ($full !== '') return $full;
        }
    }
    $candidate_cols = [
        'contact_person', 'representative', 'authorized_representative',
        'contact_name', 'company_representative',
    ];
    foreach ($candidate_cols as $col) {
        $result = @$conn->query(
            "SELECT `{$col}` AS rep_name FROM company_information WHERE user_id = {$company_id} LIMIT 1"
        );
        if ($result) {
            $row = $result->fetch_assoc();
            $result->free();
            if ($row && !empty(trim($row['rep_name'] ?? ''))) return trim($row['rep_name']);
        }
        $conn->errno;
    }
    return $company_name;
}

/* ============================================================
   ENSURE COMPETENCY RATING COLUMNS
   ------------------------------------------------------------
   NEW: The client already computes three summary numbers for
   every submitted OJT/Internship Training Plan — the General
   Competency Rating, the Specific Work Competency Rating, and the
   Overall Competency Rating (see tpUpdateTotals()/tpCollectRatings()
   in the browser script further down this file). Previously these
   three numbers only ever lived inside the transient PHP session
   copy of the ratings payload ($_SESSION['eval_scores_...']), which
   is unset as soon as the PDF/HTML blob is saved — so they were
   never actually persisted anywhere durable.

   This helper lazily adds three nullable DECIMAL columns to
   ojt_assignments (mirroring the existing lazy "ALTER TABLE ...
   ADD COLUMN eval_pdf_blob" pattern already used by the
   save_eval_pdf handler below) so the save_eval handler can store
   these three ratings permanently in the database.
   ============================================================ */
function ensureEvalRatingColumns(mysqli $conn): void {
    static $checked = false;
    if ($checked) return;
    $checked = true;

    $needed_columns = [
        'general_competency_rating'  => "DECIMAL(4,2) NULL",
        'specific_competency_rating' => "DECIMAL(4,2) NULL",
        'overall_competency_rating'  => "DECIMAL(4,2) NULL",
    ];
    foreach ($needed_columns as $col_name => $col_def) {
        $col_check = $conn->query("SHOW COLUMNS FROM ojt_assignments LIKE '{$col_name}'");
        if ($col_check && $col_check->num_rows === 0) {
            $conn->query("ALTER TABLE ojt_assignments ADD COLUMN `{$col_name}` {$col_def} AFTER eval_submitted_at");
        }
    }
}

/* ============================================================
   NEW: WEEKLY REPORT COMPLIANCE COUNTS (Total / Submitted / Missed)
   ------------------------------------------------------------
   Used by the student_library=1 handler below to power the three
   summary cards now shown at the top of the Student Library
   ("Total", "Submitted", "Not Submitted") — mirroring the visual
   pattern already used elsewhere in the system for compliance-style
   counters.

   Definitions (per the requested behavior):
     - Total     = number of weekly reports that SHOULD have been
                   submitted so far, counting one report per week
                   from the start of OJT (the company's earliest
                   attendance_settings date, same $ojt_start_date
                   already used elsewhere in this file for the
                   Training Plan's "Date OJT started" field) through
                   today's date, inclusive of the current week.
     - Submitted = number of reports the student has actually
                   submitted (the same set already returned in the
                   'reports' array below, i.e. excluding rows marked
                   remark = 'Wrong Document').
     - Missed    = Total - Submitted, floored at 0 so a student who
                   has submitted extra/duplicate reports for the same
                   week never shows a negative "missed" count.

   The OJT start date is normalized to the Monday of its own week
   before counting, since weekly reports are tracked against a
   Monday-start week (see the Mon–Fri `week_start`/`week_end` range
   already used by the download/view handlers above) — this keeps
   the week count consistent regardless of which weekday OJT
   actually began on.
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
   DOWNLOAD HANDLER
   ============================================================ */
if (isset($_GET['download']) && $_GET['download'] == '1') {
    $report_id = (int)($_GET['id'] ?? 0);
    $dl = $conn->prepare("
        SELECT r.report_blob, r.week_start, u.first_name, u.last_name
        FROM reports r
        JOIN ojt_assignments oa ON oa.student_id = r.user_id
        JOIN users u ON u.id = r.user_id
        WHERE r.id = ? AND r.company_id = ? AND oa.company_id = ?
    ");
    $dl->bind_param("iii", $report_id, $company_id, $company_id);
    $dl->execute();
    $dlrow = $dl->get_result()->fetch_assoc();
    if (!$dlrow || empty($dlrow['report_blob'])) die("File not found.");

    $student_full_name = ($dlrow['first_name'] ?? 'Student') . ' ' . ($dlrow['last_name'] ?? '');
    $week_start_date   = strtotime($dlrow['week_start']);
    $week_end_date     = strtotime('+4 days', $week_start_date);
    $week_range        = date("M d", $week_start_date) . ' - ' . date("M d, Y", $week_end_date);
    $blob = $dlrow['report_blob'];

    if (blobIsHTML($blob)) {
        $filename = preg_replace('/[\/\\\\:*?"<>|]/', '', $student_full_name . "_" . $week_range . ".html");
        header("Content-Type: text/html; charset=utf-8");
        header("Content-Disposition: attachment; filename=\"{$filename}\"");
        header("Content-Length: " . strlen($blob));
        header("Cache-Control: max-age=0");
        echo $blob;
    } else {
        $filename = preg_replace('/[\/\\\\:*?"<>|]/', '', $student_full_name . "_" . $week_range . ".xlsx");
        header("Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet");
        header("Content-Disposition: attachment; filename=\"{$filename}\"");
        header("Content-Length: " . strlen($blob));
        header("Cache-Control: max-age=0");
        echo $blob;
    }
    exit;
}

/* ============================================================
   VIEW HANDLER
   ============================================================ */
if (isset($_GET['view']) && $_GET['view'] == '1') {
    $report_id = (int)($_GET['id'] ?? 0);
    $dl = $conn->prepare("
        SELECT r.report_blob, r.week_start, u.first_name, u.last_name
        FROM reports r
        JOIN ojt_assignments oa ON oa.student_id = r.user_id
        JOIN users u ON u.id = r.user_id
        WHERE r.id = ? AND r.company_id = ? AND oa.company_id = ?
    ");
    $dl->bind_param("iii", $report_id, $company_id, $company_id);
    $dl->execute();
    $dlrow = $dl->get_result()->fetch_assoc();
    if (!$dlrow || empty($dlrow['report_blob'])) {
        header('Content-Type: application/json');
        echo json_encode(['error' => 'File not found.']);
        exit;
    }

    $blob         = $dlrow['report_blob'];
    $student_name = $dlrow['first_name'] . ' ' . $dlrow['last_name'];
    $week_label   = date("M d, Y", strtotime($dlrow['week_start']));

    if (blobIsHTML($blob)) {
        $iframe_src = 'company_reports.php?viewraw=1&id=' . $report_id;
        header('Content-Type: application/json');
        echo json_encode([
            'html'    => '<div style="text-align:center;padding:12px 0 0;"><iframe src="' . htmlspecialchars($iframe_src) . '" style="width:100%;height:640px;border:none;border-radius:8px;background:#fff;" title="Weekly Report"></iframe></div>',
            'week'    => $week_label,
            'student' => $student_name,
            'is_html' => true,
        ]);
    } else {
        require 'vendor/autoload.php';
        try {
            $tmpfile = tempnam(sys_get_temp_dir(), 'ojt_') . '.xlsx';
            file_put_contents($tmpfile, $blob);
            $reader      = \PhpOffice\PhpSpreadsheet\IOFactory::createReader('Xlsx');
            $reader->setReadDataOnly(true);
            $spreadsheet = $reader->load($tmpfile);
            unlink($tmpfile);

            $sheet   = $spreadsheet->getActiveSheet();
            $highRow = $sheet->getHighestRow();
            $highCol = $sheet->getHighestColumn();

            $html = '<table class="xl-table">';
            for ($r = 1; $r <= $highRow; $r++) {
                $html .= '<tr>';
                for ($c = 'A'; $c <= $highCol; $c++) {
                    $cell    = $sheet->getCell($c . $r);
                    $val     = nl2br(htmlspecialchars((string)$cell->getFormattedValue()));
                    $colspan = 1;
                    foreach ($sheet->getMergeCells() as $merge) {
                        [$tl, $br] = explode(':', $merge);
                        $tlCol = preg_replace('/\d/', '', $tl); $tlRow = (int)preg_replace('/\D/', '', $tl);
                        $brCol = preg_replace('/\d/', '', $br); $brRow = (int)preg_replace('/\D/', '', $br);
                        if ($tlCol === $c && $tlRow === $r) {
                            $span = 0; for ($mc = $tlCol; $mc <= $brCol; $mc++) $span++;
                            $colspan = $span; break;
                        }
                        if ($c >= $tlCol && $c <= $brCol && $r >= $tlRow && $r <= $brRow && !($c === $tlCol && $r === $tlRow)) { $val = null; break; }
                    }
                    if ($val === null) continue;
                    $html .= "<td colspan=\"{$colspan}\">{$val}<tr>";
                }
                $html .= '</tr>';
            }
            $html .= '</table>';

            header('Content-Type: application/json');
            echo json_encode(['html' => $html, 'week' => $week_label, 'student' => $student_name]);
        } catch (Throwable $e) {
            header('Content-Type: application/json');
            echo json_encode(['error' => 'Failed to render legacy report: ' . $e->getMessage()]);
        }
    }
    exit;
}

/* ============================================================
   VIEWRAW HANDLER
   ============================================================ */
if (isset($_GET['viewraw']) && $_GET['viewraw'] == '1') {
    $report_id = (int)($_GET['id'] ?? 0);
    $dl = $conn->prepare("
        SELECT r.report_blob
        FROM reports r
        JOIN ojt_assignments oa ON oa.student_id = r.user_id
        WHERE r.id = ? AND r.company_id = ? AND oa.company_id = ?
    ");
    $dl->bind_param("iii", $report_id, $company_id, $company_id);
    $dl->execute();
    $dlrow = $dl->get_result()->fetch_assoc();
    if (!$dlrow || empty($dlrow['report_blob'])) {
        http_response_code(404);
        echo '<p style="font-family:sans-serif;color:#ef4444;padding:20px;">Report not found.</p>';
        exit;
    }
    header('Content-Type: text/html; charset=utf-8');
    echo $dlrow['report_blob'];
    exit;
}

/* ============================================================
   STUDENT LIBRARY HANDLER
   ============================================================ */
if (isset($_GET['student_library']) && $_GET['student_library'] == '1') {
    $student_id = (int)($_GET['student_id'] ?? 0);

    $chk = $conn->prepare("SELECT student_id FROM ojt_assignments WHERE student_id=? AND company_id=? LIMIT 1");
    $chk->bind_param("ii", $student_id, $company_id);
    $chk->execute();
    if (!$chk->get_result()->fetch_assoc()) {
        header('Content-Type: application/json');
        echo json_encode(['error' => 'Access denied.']);
        exit;
    }
    $chk->close();

    $si = $conn->prepare("SELECT first_name, last_name, course FROM users WHERE id=? LIMIT 1");
    $si->bind_param("i", $student_id);
    $si->execute();
    $student_info = $si->get_result()->fetch_assoc();
    $si->close();

    /* NOTE: company_grade is no longer selected — company grading of
       weekly reports has been removed entirely. */
    $rq = $conn->prepare("
        SELECT
            r.id            AS report_id,
            r.week_start,
            r.submitted_at,
            r.remark,
            r.feedback,
            r.faculty_grade,
            (r.report_blob IS NOT NULL) AS has_blob
        FROM reports r
        WHERE r.user_id = ? AND r.company_id = ?
          AND (r.remark IS NULL OR r.remark != 'Wrong Document')
        ORDER BY r.week_start DESC
    ");
    $rq->bind_param("ii", $student_id, $company_id);
    $rq->execute();
    $all_reports = $rq->get_result()->fetch_all(MYSQLI_ASSOC);
    $rq->close();

    /* NEW: also pull the persisted General/Specific/Overall competency
       ratings alongside eval_submitted_at, now that ensureEvalRatingColumns()
       guarantees these columns exist on ojt_assignments. */
    ensureEvalRatingColumns($conn);
    $pe = $conn->prepare("
        SELECT eval_submitted_at, general_competency_rating, specific_competency_rating, overall_competency_rating
        FROM ojt_assignments
        WHERE student_id=? AND company_id=? LIMIT 1
    ");
    $pe->bind_param("ii", $student_id, $company_id);
    $pe->execute();
    $perow = $pe->get_result()->fetch_assoc();
    $pe->close();

    $eval_period = '';
    $period_stmt = $conn->prepare("
        SELECT MIN(week_start) AS first_week, MAX(week_start) AS last_week
        FROM reports
        WHERE user_id = ? AND company_id = ?
    ");
    $period_stmt->bind_param("ii", $student_id, $company_id);
    $period_stmt->execute();
    $period_row = $period_stmt->get_result()->fetch_assoc();
    $period_stmt->close();
    if ($period_row && $period_row['first_week'] && $period_row['last_week']) {
        $eval_period = date('F Y', strtotime($period_row['first_week']))
                     . ' - '
                     . date('F Y', strtotime($period_row['last_week']));
    }

    $school_representative = getStudentCoordinatorName($conn, $student_id);

    /* NEW: pull the student's Age / Sex / Home Address / Home Telephone
       (or Guardian No. fallback) / Guardian name / Guardian No., and now
       also School / School address / College / Subject / Required no. of
       hours / Coordinator telephone no. — already submitted / on file —
       so the Training Plan's Personal information AND School information
       sections can be pre-filled instead of left blank. */
    $accom_info = getStudentAccomInfo($conn, $student_id);

    /* NEW: weekly report compliance counters — Total (expected, one per
       week from OJT start to today), Submitted (count of $all_reports
       above), and Missed (Total - Submitted, floored at 0). Powers the
       three summary cards at the top of the Student Library. */
    $report_stats = computeWeeklyReportStats($ojt_start_date, count($all_reports));

    header('Content-Type: application/json');
    echo json_encode([
        'student'              => $student_info,
        'student_id'           => $student_id,
        'reports'              => $all_reports,
        'performance'          => $perow,
        'school_representative'=> $school_representative,
        'eval_period'          => $eval_period,
        'ojt_start_date'       => $ojt_start_date,
        'accom_info'           => $accom_info,
        'report_stats'         => $report_stats,
    ]);
    exit;
}

/* ============================================================
   PRINT EVAL HANDLER
   ------------------------------------------------------------
   NOTE: buildEvalFormHTML() (see EVAL_form_builder.php v1.1) was
   rebuilt around the NEUST-OJT-F013 "OJT/Internship Training Plan"
   layout — it now expects trainee/school/HTE info fields plus a
   `ratings` array (keyed by competency item, e.g. gen_* / spec_*),
   not the old 5-category eval_work_quality/eval_attitude/etc.
   score set. This handler now reads the new-format data that is
   stored in session by the "save_eval" handler below and maps it
   to the parameter names buildEvalFormHTML() actually consumes.

   This endpoint is also the single, authoritative source that BOTH
   the locked preview iframe (#evalBlobFrame, opened by
   openEvalModal()) and the Print/Save-as-PDF actions
   (evalPrintDoc()/evalSavePDF()) always read from. Once a Training
   Plan has been submitted and its blob persisted (via
   save_eval_pdf, below), this endpoint returns that exact saved
   blob — so anything fetched from here is guaranteed to be the
   same "carbon copy" of the filled-up evaluation, regardless of
   whether it's being shown on screen, printed, or exported as PDF.

   NEW: 'hte_address'/'hte_tel' now fall back to the company's own
   `company_address`/`telephone` (from company_information, fetched
   once near the top of this file into $company_address /
   $company_telephone) whenever the submitted session payload didn't
   carry a value for that field — mirroring the same
   "pre-fill-but-still-editable" pattern already used for the
   student's Personal information via getStudentAccomInfo().
   ============================================================ */
if (isset($_GET['print_eval']) && $_GET['print_eval'] == '1') {
    $student_id = (int)($_GET['student_id'] ?? 0);
    $auto_action_raw = trim($_GET['auto_action'] ?? '');
    $auto_action = in_array($auto_action_raw, ['pdf', 'print'], true) ? $auto_action_raw : '';

    $col_check = $conn->query("SHOW COLUMNS FROM ojt_assignments LIKE 'eval_pdf_blob'");
    $has_blob_col = $col_check && $col_check->num_rows > 0;

    if ($has_blob_col) {
        $blob_stmt = $conn->prepare("
            SELECT eval_pdf_blob, eval_submitted_at
            FROM ojt_assignments
            WHERE student_id = ? AND company_id = ?
            LIMIT 1
        ");
        $blob_stmt->bind_param("ii", $student_id, $company_id);
        $blob_stmt->execute();
        $blob_row = $blob_stmt->get_result()->fetch_assoc();
        $blob_stmt->close();

        if ($blob_row && !empty($blob_row['eval_submitted_at']) && !empty($blob_row['eval_pdf_blob'])) {
            $saved_blob = $blob_row['eval_pdf_blob'];
            if (!blobIsPDF($saved_blob) && blobIsHTML($saved_blob)) {
                header('Content-Type: text/html; charset=utf-8');
                echo $saved_blob;
                exit;
            }
        }
    }

    $session_key = 'eval_scores_' . $student_id . '_' . $company_id;
    $scores_from_session = $_SESSION[$session_key] ?? null;

    if (!$scores_from_session) {
        $chk = $conn->prepare("SELECT eval_submitted_at FROM ojt_assignments WHERE student_id=? AND company_id=? LIMIT 1");
        $chk->bind_param("ii", $student_id, $company_id);
        $chk->execute();
        $chkrow = $chk->get_result()->fetch_assoc();
        $chk->close();

        if (!$chkrow) { http_response_code(403); echo '<p style="font-family:sans-serif;color:#ef4444;padding:20px;">Access denied or evaluation not found.</p>'; exit; }
        if (empty($chkrow['eval_submitted_at'])) { http_response_code(400); echo '<p style="font-family:sans-serif;color:#ef4444;padding:20px;">This student has not been evaluated yet.</p>'; exit; }
        http_response_code(404);
        echo '<p style="font-family:sans-serif;color:#ef4444;padding:20px;">Evaluation form data not found. It may still be saving.</p>';
        exit;
    }

    /* New-format session payload: the individual competency
       rating/remarks pairs plus the editable trainee/school/HTE
       info fields captured at submission time. */
    $ratings_data = $scores_from_session['ratings'] ?? [];
    if (!is_array($ratings_data)) $ratings_data = [];
    $submitted_at = $scores_from_session['eval_submitted_at'] ?? '';

    $student_middle = '';
    $mid_stmt = $conn->prepare("SELECT middle_name FROM users WHERE id = ? LIMIT 1");
    $mid_stmt->bind_param("i", $student_id);
    $mid_stmt->execute();
    $mid_row = $mid_stmt->get_result()->fetch_assoc();
    $mid_stmt->close();
    if ($mid_row && !empty(trim($mid_row['middle_name'] ?? ''))) {
        $student_middle = trim($mid_row['middle_name']);
    }

    $si_stmt = $conn->prepare("SELECT first_name, last_name, course FROM users WHERE id=? LIMIT 1");
    $si_stmt->bind_param("i", $student_id);
    $si_stmt->execute();
    $si_row = $si_stmt->get_result()->fetch_assoc();
    $si_stmt->close();

    $company_rep = getCompanyRepName($conn, $company_id, $company_name);
    $school_rep  = getStudentCoordinatorName($conn, $student_id);
    if ($school_rep === '') $school_rep = 'Not Assigned';

    /* Kept for parity with the previous handler (harmless if unused
       by the current buildEvalFormHTML() template). */
    $period_stmt = $conn->prepare("
        SELECT MIN(week_start) AS first_week, MAX(week_start) AS last_week
        FROM reports
        WHERE user_id = ? AND company_id = ?
    ");
    $period_stmt->bind_param("ii", $student_id, $company_id);
    $period_stmt->execute();
    $period_row = $period_stmt->get_result()->fetch_assoc();
    $period_stmt->close();

    $eval_period = '';
    if ($period_row && $period_row['first_week'] && $period_row['last_week']) {
        $eval_period = date('F Y', strtotime($period_row['first_week']))
                     . ' - '
                     . date('F Y', strtotime($period_row['last_week']));
    }

    $trainee_name = trim(
        ($si_row['first_name'] ?? '') .
        ($student_middle !== '' ? ' ' . $student_middle : '') .
        ' ' . ($si_row['last_name'] ?? '')
    );

    $ojt_start_label = '';
    if (!empty($ojt_start_date)) {
        $ojt_start_label = date('F j, Y', strtotime($ojt_start_date));
    }

    /* HTE Address / HTE Telephone: use whatever was actually submitted
       with the Training Plan (session payload) first; if that field
       was left blank (or the session entry predates this field being
       collected), fall back to the company's own address/telephone on
       file in company_information so the printed/saved document still
       shows real contact details instead of blank lines. */
    $hte_address_out = $scores_from_session['hte_address'] ?? '';
    if ($hte_address_out === '') $hte_address_out = $company_address;
    $hte_tel_out = $scores_from_session['hte_tel'] ?? '';
    if ($hte_tel_out === '') $hte_tel_out = $company_telephone;

    echo buildEVALFormHTML([
        'trainee_name'     => $trainee_name,
        'trainee_age'      => $scores_from_session['trainee_age']     ?? '',
        'trainee_sex'      => $scores_from_session['trainee_sex']     ?? '',
        'home_address'     => $scores_from_session['home_address']    ?? '',
        'home_tel'         => $scores_from_session['home_tel']        ?? '',
        'guardian_name'    => $scores_from_session['guardian_name']   ?? '',
        'guardian_tel'     => $scores_from_session['guardian_tel']    ?? '',
        'school_name'      => $scores_from_session['school_name']     ?? '',
        'school_address'   => $scores_from_session['school_address']  ?? '',
        'college'          => $scores_from_session['college']         ?? '',
        'program'          => $si_row['course'] ?? '',
        'subject'          => $scores_from_session['subject']         ?? '',
        'required_hours'   => $scores_from_session['required_hours']  ?? '',
        'ojt_coordinator'  => $school_rep,
        'coordinator_tel'  => $scores_from_session['coordinator_tel'] ?? '',
        'hte_name'         => $company_name,
        'hte_address'      => $hte_address_out,
        'hte_tel'          => $hte_tel_out,
        'hte_trainor'      => $company_rep,
        'ojt_start_date'   => $ojt_start_label,
        'ratings'          => $ratings_data,
    ]);
    exit;
}

/* ============================================================
   PERFORMANCE EVALUATION SAVE (OJT/Internship Training Plan)
   ------------------------------------------------------------
   NOTE: This endpoint previously validated the old 5-category,
   0-100 point score set (eval_work_quality, eval_attitude,
   eval_attendance, eval_skills, eval_overall_behavior) and
   rejected submission unless their sum was > 0. The client-side
   form was migrated to the NEUST-OJT-F013 "OJT/Internship
   Training Plan" (General/Specific Competency checklist on a
   1.0-5.0 rating scale, plus editable student/school/HTE info
   fields), which posts a JSON `ratings` payload instead of the
   old flat score fields — so $_POST['eval_work_quality'] (etc.)
   was always empty/0, and submission always failed with
   "Please fill in at least one score before submitting." even
   when every competency had been rated.

   Fix: read and validate the actual `ratings` JSON payload (and
   the accompanying trainee/school/HTE info fields) that the form
   now sends, instead of the old, no-longer-posted score fields.

   NEW: In addition to locking the submission via eval_submitted_at,
   this handler now also extracts the three summary numbers the
   client already computes and sends inside the `ratings` payload —
   general_competency_rating, specific_competency_rating, and
   overall_competency_rating (see tpCollectRatings()/tpUpdateTotals()
   in the browser script below) — and persists them as their own
   columns on ojt_assignments via ensureEvalRatingColumns().
   ============================================================ */
if (isset($_GET['save_eval']) && $_GET['save_eval'] == '1' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    header('Content-Type: application/json');
    $student_id = (int)($_POST['student_id'] ?? 0);
    if ($student_id <= 0) { echo json_encode(['success' => false, 'message' => 'Invalid student ID.']); exit; }

    $chk = $conn->prepare("SELECT student_id, eval_submitted_at FROM ojt_assignments WHERE student_id=? AND company_id=? LIMIT 1");
    $chk->bind_param("ii", $student_id, $company_id);
    $chk->execute();
    $chkrow = $chk->get_result()->fetch_assoc();
    $chk->close();

    if (!$chkrow) { echo json_encode(['success' => false, 'message' => 'Student not found or access denied.']); exit; }
    if (!empty($chkrow['eval_submitted_at'])) { echo json_encode(['success' => false, 'message' => 'Evaluation already submitted and locked.']); exit; }

    /* Decode the competency ratings payload sent by tpCollectRatings()
       in the browser: { "<key>": { "rating": "1.0".."5.0", "remarks": "" }, ... } */
    $ratings_raw = $_POST['ratings'] ?? '';
    $ratings = json_decode($ratings_raw, true);
    if (!is_array($ratings)) $ratings = [];

    $clean_ratings = [];
    foreach ($ratings as $rkey => $rval) {
        if (!is_array($rval)) continue;
        $safe_key = preg_replace('/[^a-zA-Z0-9_]/', '', (string)$rkey);
        if ($safe_key === '') continue;
        $clean_ratings[$safe_key] = [
            'rating'  => trim((string)($rval['rating']  ?? '')),
            'remarks' => trim((string)($rval['remarks'] ?? '')),
        ];
    }

    /* EVERY General and Specific competency item must carry a rating —
       not just one — before the Training Plan can be accepted. This is
       the authoritative, server-side counterpart to the client-side
       tpMissingRatingCount() check in confirmEvalSubmit(): the browser
       check keeps the UI honest, but a request could reach this endpoint
       without it, so the item-by-item completeness is re-verified here.

       The category/item counts below mirror GENERAL_COMPETENCIES and
       SPECIFIC_COMPETENCIES exactly as defined in the client-side script
       further down this file (and in EVAL_form_builder.php's
       buildEvalFormHTML()). If that checklist is ever changed, these
       counts must be updated to match. */
    $expected_general_item_counts = [
        'punctuality'         => 4,  // PUNCTUALITY
        'dependability'       => 5,  // DEPENDABILITY
        'initiative'          => 3,  // INITIATIVE
        'appearance'          => 3,  // APPEARANCE
        'adaptability'        => 2,  // ADAPTABILITY
        'communication'       => 12, // COMMUNICATION
        'safety_and_security' => 6,  // SAFETY AND SECURITY
    ];
    $expected_specific_item_count = 15;

    $expected_item_keys = [];
    foreach ($expected_general_item_counts as $category_slug => $item_count) {
        for ($i = 0; $i < $item_count; $i++) {
            $expected_item_keys[] = 'gen_' . $category_slug . '_' . $i;
        }
    }
    for ($i = 0; $i < $expected_specific_item_count; $i++) {
        $expected_item_keys[] = 'spec_' . $i;
    }

    $missing_rating_count = 0;
    foreach ($expected_item_keys as $expected_key) {
        if (empty($clean_ratings[$expected_key]['rating'])) {
            $missing_rating_count++;
        }
    }

    if ($missing_rating_count > 0) {
        echo json_encode([
            'success' => false,
            'message' => 'Please rate all competency items before submitting. ('
                . $missing_rating_count . ' item' . ($missing_rating_count !== 1 ? 's' : '')
                . ' still need' . ($missing_rating_count === 1 ? 's' : '') . ' a rating.)',
        ]);
        exit;
    }

    $trainee_age     = trim($_POST['trainee_age']     ?? '');
    $trainee_sex     = trim($_POST['trainee_sex']     ?? '');
    $home_address    = trim($_POST['home_address']    ?? '');
    $home_tel        = trim($_POST['home_tel']        ?? '');
    $guardian_name   = trim($_POST['guardian_name']   ?? '');
    $guardian_tel    = trim($_POST['guardian_tel']    ?? '');
    $school_name     = trim($_POST['school_name']     ?? '');
    $school_address  = trim($_POST['school_address']  ?? '');
    $college         = trim($_POST['college']         ?? '');
    $subject         = trim($_POST['subject']         ?? '');
    $required_hours  = trim($_POST['required_hours']  ?? '');
    $coordinator_tel = trim($_POST['coordinator_tel'] ?? '');
    $hte_address     = trim($_POST['hte_address']     ?? '');
    $hte_tel         = trim($_POST['hte_tel']         ?? '');

    /* NEW: pull the three summary competency ratings out of the same
       $clean_ratings array — the client already sends them under the
       'general_competency_rating' / 'specific_competency_rating' /
       'overall_competency_rating' keys (see tpCollectRatings()) — so
       they can be persisted as real columns instead of only living in
       the transient session copy below. */
    $general_competency_rating = null;
    if (isset($clean_ratings['general_competency_rating']['rating']) && $clean_ratings['general_competency_rating']['rating'] !== '') {
        $general_competency_rating = (float)$clean_ratings['general_competency_rating']['rating'];
    }
    $specific_competency_rating = null;
    if (isset($clean_ratings['specific_competency_rating']['rating']) && $clean_ratings['specific_competency_rating']['rating'] !== '') {
        $specific_competency_rating = (float)$clean_ratings['specific_competency_rating']['rating'];
    }
    $overall_competency_rating = null;
    if (isset($clean_ratings['overall_competency_rating']['rating']) && $clean_ratings['overall_competency_rating']['rating'] !== '') {
        $overall_competency_rating = (float)$clean_ratings['overall_competency_rating']['rating'];
    }

    ensureEvalRatingColumns($conn);

    $upd = $conn->prepare("UPDATE ojt_assignments SET eval_submitted_at = NOW(), general_competency_rating = ?, specific_competency_rating = ?, overall_competency_rating = ? WHERE student_id=? AND company_id=?");
    $upd->bind_param("dddii", $general_competency_rating, $specific_competency_rating, $overall_competency_rating, $student_id, $company_id);
    $ok = $upd->execute();
    $affected = $upd->affected_rows;
    $upd->close();

    if (!$ok) { echo json_encode(['success' => false, 'message' => 'Database error: ' . $conn->error]); exit; }
    if ($affected === 0) { echo json_encode(['success' => false, 'message' => 'No rows updated. Please check the student assignment.']); exit; }

    $submitted_at_str = date('M d, Y g:i A');
    $session_key = 'eval_scores_' . $student_id . '_' . $company_id;
    $_SESSION[$session_key] = [
        'ratings'           => $clean_ratings,
        'trainee_age'       => $trainee_age,
        'trainee_sex'       => $trainee_sex,
        'home_address'      => $home_address,
        'home_tel'          => $home_tel,
        'guardian_name'     => $guardian_name,
        'guardian_tel'      => $guardian_tel,
        'school_name'       => $school_name,
        'school_address'    => $school_address,
        'college'           => $college,
        'subject'           => $subject,
        'required_hours'    => $required_hours,
        'coordinator_tel'   => $coordinator_tel,
        'hte_address'       => $hte_address,
        'hte_tel'           => $hte_tel,
        'eval_submitted_at' => $submitted_at_str,
    ];

    $school_rep_for_response  = getStudentCoordinatorName($conn, $student_id);
    $company_rep_for_response = getCompanyRepName($conn, $company_id, $company_name);

    echo json_encode([
        'success'                    => true,
        'message'                    => 'Overall Performance Evaluation submitted and locked.',
        'eval_submitted_at'          => $submitted_at_str,
        'student_id'                 => $student_id,
        'school_representative'      => $school_rep_for_response,
        'company_representative'     => $company_rep_for_response,
        'general_competency_rating'  => $general_competency_rating,
        'specific_competency_rating' => $specific_competency_rating,
        'overall_competency_rating'  => $overall_competency_rating,
        'trigger_pdf_save'           => true,
        'pdf_saved'                  => false,
        'pdf_message'                => 'Blob will be saved via client-side flow.',
    ]);
    exit;
}

/* ============================================================
   SAVE EVAL PDF BLOB
   ============================================================ */
if (isset($_GET['save_eval_pdf']) && $_GET['save_eval_pdf'] == '1' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    header('Content-Type: application/json');
    $student_id  = (int)($_POST['student_id'] ?? 0);
    $html_blob   = trim($_POST['html_blob']   ?? '');

    if ($student_id <= 0) { echo json_encode(['success' => false, 'message' => 'Missing student ID.']); exit; }
    if ($html_blob === '') { echo json_encode(['success' => false, 'message' => 'Missing HTML blob data.']); exit; }

    $chk = $conn->prepare("SELECT student_id, eval_submitted_at FROM ojt_assignments WHERE student_id = ? AND company_id = ? LIMIT 1");
    $chk->bind_param("ii", $student_id, $company_id);
    $chk->execute();
    $chkrow = $chk->get_result()->fetch_assoc();
    $chk->close();

    if (!$chkrow) { echo json_encode(['success' => false, 'message' => 'Access denied or student not found.']); exit; }
    if (empty($chkrow['eval_submitted_at'])) { echo json_encode(['success' => false, 'message' => 'Evaluation has not been submitted yet. Cannot save PDF.']); exit; }

    $html_check = ltrim(substr($html_blob, 0, 200));
    if (stripos($html_check, '<!doctype') === false && stripos($html_check, '<html') === false) {
        echo json_encode(['success' => false, 'message' => 'Received data does not appear to be valid HTML. Save aborted to prevent corruption.']);
        exit;
    }

    $col_check = $conn->query("SHOW COLUMNS FROM ojt_assignments LIKE 'eval_pdf_blob'");
    if ($col_check && $col_check->num_rows === 0) {
        $conn->query("ALTER TABLE ojt_assignments ADD COLUMN eval_pdf_blob LONGBLOB NULL AFTER eval_submitted_at");
    }

    $upd = $conn->prepare("UPDATE ojt_assignments SET eval_pdf_blob = ? WHERE student_id = ? AND company_id = ?");
    $upd->bind_param("sii", $html_blob, $student_id, $company_id);
    $ok = $upd->execute();
    $upd->close();

    if (!$ok) { echo json_encode(['success' => false, 'message' => 'Database error saving eval blob: ' . $conn->error]); exit; }

    $session_key = 'eval_scores_' . $student_id . '_' . $company_id;
    unset($_SESSION[$session_key]);

    echo json_encode(['success' => true, 'message' => 'Evaluation form saved successfully (html).', 'save_type' => 'html']);
    exit;
}

/* ============================================================
   AJAX COMMENT SAVE HANDLER
   (Company grading of weekly reports has been removed entirely.
   This handler now only persists the company's comment/feedback
   on a report — no company_grade column is read or written.)
   ============================================================ */
if (isset($_GET['save_comment']) && $_GET['save_comment'] == '1' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $report_id = (int)($_POST['report_id'] ?? 0);
    $feedback  = trim($_POST['feedback'] ?? '');

    $verify = $conn->prepare("
        SELECT r.id, r.submitted_at FROM reports r
        JOIN ojt_assignments oa ON oa.student_id = r.user_id
        WHERE r.id = ? AND r.company_id = ? AND oa.company_id = ?
    ");
    $verify->bind_param("iii", $report_id, $company_id, $company_id);
    $verify->execute();
    $vrow = $verify->get_result()->fetch_assoc();

    if (!$vrow) {
        header('Content-Type: application/json');
        echo json_encode(['success' => false, 'message' => 'Report not found or access denied.']);
        exit;
    }

    $upd = $conn->prepare("UPDATE reports SET feedback=? WHERE id=?");
    $upd->bind_param("si", $feedback, $report_id);
    $upd->execute();

    header('Content-Type: application/json');
    echo json_encode(['success' => true, 'message' => 'Comment saved successfully.']);
    exit;
}

/* ============================================================
   MAIN PAGE: Fetch all students
   (Grade-based aggregates — graded_reports / ungraded_reports /
   avg_grade — have been removed along with weekly-report grading.
   total_reports is kept.)
   ============================================================ */
$students_stmt = $conn->prepare("
    SELECT
        u.id            AS student_id,
        u.first_name,
        u.last_name,
        u.course,
        COUNT(r.id)                                              AS total_reports,
        MAX(r.submitted_at)                                      AS last_submitted_at,
        oa.eval_submitted_at
    FROM ojt_assignments oa
    JOIN users u ON oa.student_id = u.id
    LEFT JOIN reports r
        ON r.user_id = u.id
        AND r.company_id = oa.company_id
        AND (r.remark IS NULL OR r.remark != 'Wrong Document')
    WHERE oa.company_id = ?
    GROUP BY u.id, u.first_name, u.last_name, u.course, oa.eval_submitted_at
    ORDER BY u.first_name ASC
");
$students_stmt->bind_param("i", $company_id);
$students_stmt->execute();
$all_students = $students_stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$students_stmt->close();

$total_students     = count($all_students);

$_company_rep_name = getCompanyRepName($conn, $company_id, $company_name);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Company Reports</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Crimson+Pro:ital,wght@0,400;0,600;1,400&family=DM+Sans:opsz,wght@9..40,300;9..40,400;9..40,500;9..40,600;9..40,700&family=JetBrains+Mono:wght@400;500;600&display=swap" rel="stylesheet">
    <script src="https://cdnjs.cloudflare.com/ajax/libs/jspdf/2.5.1/jspdf.umd.min.js"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/html2canvas/1.4.1/html2canvas.min.js"></script>
    <style>
        :root {
            --neust-maroon: #07145fe5;
            --neust-gold:   #FFD700;
            --navy:   #07145f;
            --gold:   #c8a800;
            --accent: #1a56db;
            --green:  #0e9f6e;
            --red:    #dc2626;
            --amber:  #d97706;
            --rule:   #c8cfe8;
        }
        *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }
        body { font-family: 'DM Sans', 'Segoe UI', sans-serif; background: #f0f4f8; color: #2d3748; display: flex; min-height: 100vh; }

        /* ── SIDEBAR ── */
        .sidebar { width: 260px; background: var(--neust-maroon); height: 100vh; position: fixed; display: flex; flex-direction: column; transition: 0.3s; z-index: 1000; }
        .sidebar.collapsed { width: 80px; }

        /* Sidebar header — matches attendance_management style with name + role label */
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
        .logout-link a { border: 1px solid var(--neust-gold); color: var(--neust-gold); border-radius: 6px; justify-content: center; padding: 10px; text-decoration: none; display: flex; }
        .sidebar-badge { background: #dc2626; color: white; border-radius: 50%; width: 18px; height: 18px; font-size: 10px; font-weight: 700; display: inline-flex; align-items: center; justify-content: center; position: absolute; right: 18px; top: 50%; transform: translateY(-50%); }
        .sidebar-badge-late { background: #d97706; color: white; border-radius: 50%; width: 18px; height: 18px; font-size: 10px; font-weight: 700; display: inline-flex; align-items: center; justify-content: center; position: absolute; right: 18px; top: 50%; transform: translateY(-50%); animation: badge-pulse-late 2s ease-in-out infinite; }
        @keyframes badge-pulse-late { 0%,100% { box-shadow: 0 0 0 0 rgba(217,119,6,0.55); } 50% { box-shadow: 0 0 0 6px rgba(217,119,6,0); } }

        /* ── MAIN ── */
        .main-content { margin-left: 260px; width: calc(100% - 260px); transition: 0.3s; display: flex; flex-direction: column; min-height: 100vh; }
        .sidebar.collapsed ~ .main-content { margin-left: 80px; width: calc(100% - 80px); }
        .navbar { background: var(--neust-maroon); padding: 10px 30px; display: flex; align-items: center; color: white; height: 60px; flex-shrink: 0; }
        .page-inner { flex: 1; }

        /* ── CONTROLS ──
           RESTYLED (style ref #4, "Field ops grid"): sharp corners,
           bordered blocks, uppercase micro-labels — replaces the
           previous rounded/pill look. Still hosts the simple
           student-count badge to the left of the search bar. */
        .controls { max-width: 860px; margin: 24px auto 0; padding: 0 16px; display: flex; align-items: center; gap: 10px; flex-wrap: wrap; }
        .simple-count { display: inline-flex; align-items: center; gap: 7px; background: #fff; border: 1px solid #C3CADA; border-radius: 0; padding: 9px 16px; font-size: 11px; font-weight: 700; color: #1B2A4A; text-transform: uppercase; letter-spacing: 0.4px; white-space: nowrap; flex-shrink: 0; }
        .controls input[type="text"] { flex: 1; min-width: 200px; padding: 9px 14px; border: 1px solid #C3CADA; border-radius: 0; font-size: 0.88rem; background: #fff; font-family: inherit; color: #1B2A4A; }
        .controls input[type="text"]:focus { outline: none; border-color: #1B2A4A; }
        .controls input[type="text"]::placeholder { color: #8A93A8; }

        /* ── STUDENT CARDS ──
           RESTYLED (style ref #4, "Field ops grid"): sharp-cornered
           bordered blocks with a color-coded left border (green once
           the student has reports, neutral otherwise) and a plain
           colored status line instead of pill badges — mirrors the
           reference's bordered company table rows.
           UPDATED (this revision): the left-border submitted/not-
           submitted status coloring has been removed so the card's
           side border always stays the same neutral tone regardless
           of has-reports/no-reports state, and the hover state now
           matches admin_reports.php's "Field ops grid" .card:hover
           treatment exactly (light-blue tint, soft shadow, tinted
           border, subtle lift) instead of the previous plain
           background-only hover. No other card markup, data, or
           behavior is affected — only these two purely visual rules
           change. */
        .cards { max-width: 860px; margin: 16px auto 60px; padding: 0 16px; display: flex; flex-direction: column; gap: 10px; }
        .student-card { background: #fff; border: 1px solid #C3CADA; border-left: 3px solid #C3CADA; border-radius: 0; display: flex; align-items: center; justify-content: space-between; padding: 14px 20px; cursor: pointer; transition: background 0.15s, border-color 0.15s, box-shadow 0.15s, transform 0.15s; gap: 14px; flex-wrap: wrap; }
        .student-card:hover { background: #EAF0FA; box-shadow: 0 5px 16px rgba(27,42,74,0.26); border-color: #8CA2C9; transform: translateY(-1px); }
        .sc-info { flex: 1; min-width: 0; }
        .sc-name   { font-weight: 600; font-size: 0.93rem; color: #1B2A4A; }
        .sc-course { font-size: 0.68rem; color: #5A6272; margin-top: 4px; text-transform: uppercase; letter-spacing: 0.4px; font-weight: 600; }
        .sc-last   { font-size: 0.72rem; margin-top: 3px; font-weight: 600; }
        .student-card.has-reports .sc-last { color: #2C5A2C; }
        .student-card.no-reports  .sc-last { color: #A0850A; }
        .sc-badges { display: flex; align-items: center; gap: 8px; flex-shrink: 0; flex-wrap: wrap; justify-content: flex-end; }
        /* Open Library / Evaluate are now icon-only buttons with a
           hover tooltip (data-tooltip), in the same sharp-cornered,
           bordered style as the rest of this reworked list. */
        .icon-btn {
            position: relative;
            display: inline-flex; align-items: center; justify-content: center;
            width: 34px; height: 34px;
            background: #fff; border: 1px solid #C3CADA; border-radius: 0;
            color: #1B2A4A; font-size: 13px; cursor: pointer; flex-shrink: 0;
            transition: background 0.15s, border-color 0.15s, color 0.15s;
        }
        .icon-btn:hover { background: #1B2A4A; border-color: #1B2A4A; color: #fff; }
        .icon-btn.eval-done { color: #2C5A2C; border-color: #4A7A3A; }
        .icon-btn.eval-done:hover { background: #2C5A2C; border-color: #2C5A2C; color: #fff; }
        .icon-btn[data-tooltip]::after {
            content: attr(data-tooltip);
            position: absolute; bottom: calc(100% + 7px); left: 50%; transform: translateX(-50%);
            background: #1B2A4A; color: #fff; font-size: 10.5px; font-weight: 600;
            text-transform: uppercase; letter-spacing: 0.3px; padding: 5px 9px;
            white-space: nowrap; opacity: 0; pointer-events: none; transition: opacity 0.15s; z-index: 30;
        }
        .icon-btn[data-tooltip]::before {
            content: ''; position: absolute; bottom: 100%; left: 50%; transform: translateX(-50%);
            border: 5px solid transparent; border-top-color: #1B2A4A; margin-bottom: -3px;
            opacity: 0; pointer-events: none; transition: opacity 0.15s; z-index: 30;
        }
        .icon-btn[data-tooltip]:hover::after,
        .icon-btn[data-tooltip]:hover::before { opacity: 1; }
        .sc-arrow { font-size: 0.7rem; color: #8A93A8; }

        /* ── VIEWER MODAL ── */
        .viewer-overlay { display: none; position: fixed; inset: 0; background: rgba(0,0,0,0.6); z-index: 1300; align-items: center; justify-content: center; padding: 20px; }
        .viewer-overlay.open { display: flex; }
        .viewer-box { background: white; border-radius: 16px; width: 100%; max-width: 900px; max-height: 92vh; display: flex; flex-direction: column; box-shadow: 0 8px 40px rgba(0,0,0,0.25); overflow: hidden; }
        .viewer-header { padding: 16px 20px; background: #1a56db; color: white; display: flex; align-items: center; justify-content: space-between; flex-shrink: 0; }
        .viewer-header h3 { font-size: 0.97rem; font-weight: 700; }
        .viewer-close { background: none; border: none; color: white; font-size: 1.4rem; cursor: pointer; line-height: 1; }
        .viewer-body { overflow-y: auto; padding: 0; flex: 1; min-height: 0; }
        .viewer-body.iframe-mode { padding: 0; display: flex; flex-direction: column; }
        .viewer-body.iframe-mode iframe { flex: 1; border: none; width: 100%; min-height: 560px; display: block; }
        .viewer-body.table-mode { padding: 20px; }
        .viewer-loading { display: flex; align-items: center; justify-content: center; padding: 60px; color: #9ca3af; font-size: 0.9rem; gap: 10px; }
        .spinner { width: 22px; height: 22px; border: 3px solid #e5e7eb; border-top-color: #1a56db; border-radius: 50%; animation: spin 0.7s linear infinite; }
        @keyframes spin { to { transform: rotate(360deg); } }

        .xl-table { width: 100%; border-collapse: collapse; font-size: 0.82rem; font-family: 'Segoe UI', sans-serif; }
        .xl-table td { border: 1px solid #e5e7eb; padding: 8px 10px; vertical-align: top; line-height: 1.5; }
        .xl-table tr:first-child td  { background: #1a56db; color: white; font-weight: 700; font-size: 1rem; text-align: center; }
        .xl-table tr:nth-child(2) td { background: #0e9f6e; color: white; font-size: 0.82rem; text-align: center; font-style: italic; }
        .xl-table tr:nth-child(3) td { padding: 3px; background: #f9fafb; }
        .xl-table tr:nth-child(4) td { background: #374151; color: white; font-weight: 700; text-align: center; }
        .xl-table tr:nth-last-child(1) td { background: #f3f4f6; color: #6b7280; font-size: 0.75rem; text-align: center; font-style: italic; }
        .xl-table tr:nth-child(n+5):nth-last-child(n+2) td:first-child { background: #eff6ff; color: #1a56db; font-weight: 700; text-align: center; white-space: pre-line; }

        /* ── TOAST ── */
        .toast { position: fixed; bottom: 24px; left: 50%; transform: translateX(-50%); background: #111827; color: white; padding: 10px 20px; border-radius: 10px; font-size: 0.84rem; z-index: 3000; opacity: 0; transition: opacity 0.3s; pointer-events: none; white-space: nowrap; }
        .toast.show { opacity: 1; pointer-events: auto; }

        /* ══════════════════════════════════════════════
           STUDENT LIBRARY — FULL-SCREEN DOCUMENT VIEW
           (mirrors the eval-overlay toolbar + canvas pattern,
           with a left report list, a large report preview,
           and a collapsible comment drawer)
           ══════════════════════════════════════════════ */
        .lib-overlay { display: none; position: fixed; inset: 0; background: rgba(0,0,0,0.72); z-index: 1200; overflow: hidden; flex-direction: column; }
        .lib-overlay.open { display: flex; }

        .lib-doc-toolbar { background: var(--navy); padding: 0.55rem 1.5rem; display: flex; align-items: center; justify-content: space-between; flex-shrink: 0; box-shadow: 0 2px 10px rgba(0,0,0,0.35); gap: 1rem; flex-wrap: wrap; }
        .lib-doc-toolbar-left { display: flex; align-items: center; gap: 10px; min-width: 0; }
        .lib-doc-toolbar-title { font-family: 'DM Sans', sans-serif; font-size: 0.86rem; font-weight: 700; color: #fff; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
        .lib-doc-toolbar-course { font-family: 'DM Sans', sans-serif; font-size: 0.7rem; color: rgba(255,255,255,0.6); white-space: nowrap; }
        .lib-doc-toolbar-right { display: flex; align-items: center; gap: 8px; flex-shrink: 0; flex-wrap: wrap; }

        .lib-tbtn-comment { background: rgba(255,255,255,0.14); color: rgba(255,255,255,0.9); border: 1px solid rgba(255,255,255,0.28); }
        .lib-tbtn-comment:hover:not(:disabled) { background: rgba(255,255,255,0.24); color: #fff; }
        .lib-tbtn-comment:disabled { opacity: 0.5; cursor: not-allowed; }
        .lib-tbtn-comment.has-comment { background: rgba(217,119,6,0.28); border-color: rgba(217,119,6,0.55); color: #fde68a; }

        /* Summary strip (kept from the previous layout, now sits under the toolbar) */
        .lib-summary-bar {
            background: white;
            border-bottom: 1px solid #e5e7eb;
            padding: 8px 20px;
            display: flex; gap: 28px; flex-shrink: 0; flex-wrap: wrap;
        }
        .lib-stat { display: flex; flex-direction: column; gap: 1px; }
        .lib-stat-val { font-size: 1.05rem; font-weight: 800; color: #1e293b; line-height: 1; }
        .lib-stat-lbl { font-size: 0.62rem; color: #6b7280; text-transform: uppercase; letter-spacing: 0.06em; }

        /* ══ Weekly report compliance cards (Total / Submitted / Not
              Submitted) shown in the Student Library's summary strip.
              Matches the rounded, icon-badge "stat card" visual style
              (soft shadow, colored icon chip, big number, muted
              uppercase label) requested for this addition. ══ */
        .lib-stats-row { display: flex; gap: 12px; flex-wrap: wrap; flex: 1; }
        .lib-stat-card {
            flex: 1 1 150px;
            min-width: 140px;
            display: flex;
            align-items: center;
            gap: 12px;
            background: #fff;
            border: 1px solid #eef0f4;
            border-radius: 14px;
            padding: 10px 14px;
            box-shadow: 0 1px 3px rgba(0,0,0,0.05);
        }
        .lib-stat-icon {
            width: 38px; height: 38px;
            border-radius: 12px;
            display: flex; align-items: center; justify-content: center;
            font-size: 15px; flex-shrink: 0;
        }
        .lib-stat-icon.total     { background: #eef2ff; color: #4338ca; }
        .lib-stat-icon.submitted { background: #d1fae5; color: #059669; }
        .lib-stat-icon.missed    { background: #fee2e2; color: #dc2626; }
        .lib-stat-text { display: flex; flex-direction: column; min-width: 0; }
        .lib-stat-num { font-size: 1.35rem; font-weight: 800; line-height: 1.1; color: #1e293b; }
        .lib-stat-num.submitted-num { color: #059669; }
        .lib-stat-num.missed-num    { color: #dc2626; }
        .lib-stat-lbl2 {
            font-size: 0.62rem; font-weight: 700; color: #6b7280;
            text-transform: uppercase; letter-spacing: 0.06em; margin-top: 2px;
        }

        /* ── DOC BODY (fills remaining screen) ── */
        .lib-doc-body { flex: 1; min-height: 0; overflow: hidden; display: flex; }

        /* ── SPLIT PANE BODY ── */
        .lib-split-body {
            flex: 1;
            display: grid;
            grid-template-columns: 260px 1fr;
            min-height: 0;
            overflow: hidden;
        }

        /* Left: report list */
        .lib-list-pane {
            background: white;
            border-right: 1px solid #e5e7eb;
            overflow-y: auto;
            display: flex;
            flex-direction: column;
        }
        .lib-list-empty {
            padding: 40px 16px;
            text-align: center;
            color: #9ca3af;
            font-size: 0.82rem;
        }
        .lib-list-item {
            padding: 10px 12px;
            border-bottom: 1px solid #f1f3f6;
            cursor: pointer;
            transition: background 0.12s;
            display: flex;
            flex-direction: column;
            gap: 5px;
        }
        .lib-list-item:hover { background: #f5f7ff; }
        .lib-list-item.active {
            background: #eff4ff;
            border-left: 3px solid #1a56db;
            padding-left: 9px;
        }
        .lib-list-week  { font-size: 0.8rem; font-weight: 700; color: #1e293b; }
        .lib-list-sub   { font-size: 0.68rem; color: #9ca3af; }

        /* Right: detail pane (header + a large preview) */
        .lib-detail-pane {
            overflow-y: auto;
            padding: 0;
            display: flex;
            flex-direction: column;
            background: #eef1f8;
        }
        .lib-detail-empty {
            flex: 1;
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            color: #9ca3af;
            font-size: 0.85rem;
            gap: 10px;
            padding: 40px;
        }
        .lib-detail-empty i { font-size: 2rem; color: #c4b5fd; }

        /* ── DETAIL HEADER ── */
        .lib-detail-header {
            padding: 14px 20px 10px;
            border-bottom: 1px solid #e5e7eb;
            background: white;
            flex-shrink: 0;
        }
        .lib-detail-week  { font-size: 0.97rem; font-weight: 800; color: #1e293b; }
        .lib-detail-sub   { font-size: 0.72rem; color: #9ca3af; margin-top: 4px; }

        .lib-detail-body { padding: 16px 20px; display: flex; flex-direction: column; gap: 14px; flex: 1; min-height: 0; }

        /* ── REPORT PREVIEW (large, fills the detail pane) ── */
        .lib-preview-section { background: white; border-radius: 10px; border: 1px solid #e5e7eb; padding: 14px 16px; flex: 1; display: flex; flex-direction: column; min-height: 0; }
        .lib-preview-title { font-size: 0.72rem; font-weight: 700; text-transform: uppercase; letter-spacing: 0.07em; color: #6b7280; margin-bottom: 10px; flex-shrink: 0; }
        .lib-report-preview { border: 1px solid #e5e7eb; border-radius: 8px; overflow: hidden; background: #f9fafb; flex: 1; display: flex; min-height: 0; }
        .lib-report-preview-frame { width: 100%; height: 100%; min-height: 68vh; border: none; display: block; background: #fff; }
        .lib-report-preview-loading { display: flex; align-items: center; justify-content: center; gap: 10px; width: 100%; min-height: 360px; color: #9ca3af; font-size: 0.85rem; }
        .lib-report-preview-empty { display: flex; flex-direction: column; align-items: center; justify-content: center; gap: 8px; width: 100%; min-height: 200px; color: #9ca3af; font-size: 0.85rem; padding: 30px; text-align: center; }
        .lib-report-preview-empty i { font-size: 1.8rem; color: #d1d5db; }
        .lib-report-preview-table { padding: 14px; overflow-x: auto; overflow-y: auto; width: 100%; max-height: 68vh; }

        /* ══════════════════════════════════════════════
           COLLAPSIBLE COMMENT DRAWER
           (comment/feedback moved off the main preview so
           the report view can be bigger; slides in from the
           right, toggled from the toolbar's Comment button)
           ══════════════════════════════════════════════ */
        .lib-comment-drawer-backdrop { display: none; position: fixed; inset: 0; background: rgba(0,0,0,0.25); z-index: 1240; }
        .lib-comment-drawer-backdrop.show { display: block; }
        .lib-comment-drawer {
            position: fixed; top: 0; right: -400px; width: 380px; max-width: 92vw; height: 100vh;
            background: #f8f9fb; box-shadow: -6px 0 28px rgba(0,0,0,0.22);
            z-index: 1250; transition: right 0.25s ease;
            display: flex; flex-direction: column;
        }
        .lib-comment-drawer.open { right: 0; }
        .lib-comment-drawer-header {
            background: linear-gradient(135deg, #07145f, #1a56db);
            color: white; padding: 14px 18px;
            display: flex; align-items: center; justify-content: space-between;
            font-weight: 700; font-size: 0.88rem; flex-shrink: 0;
        }
        .lib-comment-drawer-header button {
            background: rgba(255,255,255,0.14); border: none; color: white;
            width: 28px; height: 28px; border-radius: 50%; font-size: 0.95rem;
            cursor: pointer; display: flex; align-items: center; justify-content: center;
            transition: background 0.15s;
        }
        .lib-comment-drawer-header button:hover { background: rgba(255,255,255,0.26); }
        .lib-comment-drawer-body { padding: 16px 18px; overflow-y: auto; flex: 1; }
        .lib-comment-drawer-empty { color: #9ca3af; font-size: 0.85rem; text-align: center; padding: 40px 10px; }

        /* Comment section content (rendered inside the drawer) */
        .lib-comment-section { background: white; border-radius: 10px; border: 1px solid #e5e7eb; padding: 14px 16px; }
        .lib-comment-title-row { display: flex; align-items: center; justify-content: space-between; gap: 8px; margin-bottom: 8px; }
        .lib-comment-title { font-size: 0.72rem; font-weight: 700; text-transform: uppercase; letter-spacing: 0.07em; color: #6b7280; margin-bottom: 0; }
        .lib-comment-fullview-btn {
            display: inline-flex; align-items: center; justify-content: center;
            width: 30px; height: 30px; border-radius: 50%;
            background: #eff6ff; color: #1a56db; border: 1.5px solid #bfdbfe;
            cursor: pointer; font-size: 0.82rem; flex-shrink: 0; transition: background 0.15s, border-color 0.15s;
        }
        .lib-comment-fullview-btn:hover { background: #dbeafe; border-color: #93c5fd; }
        .lib-comment-display { font-size: 0.82rem; color: #374151; background: #f9fafb; border-radius: 6px; padding: 8px 10px; line-height: 1.55; min-height: 36px; }
        .lib-comment-display.empty { color: #9ca3af; font-style: italic; }
        .lib-comment-ta {
            width: 100%; padding: 8px 10px;
            border: 1.5px solid #e5e7eb; border-radius: 8px;
            font-size: 0.82rem; font-family: inherit;
            background: #fafafa; resize: vertical; min-height: 100px;
            transition: border-color 0.2s;
        }
        .lib-comment-ta:focus { outline: none; border-color: #1a56db; background: white; }

        .lib-form-btns { display: flex; gap: 7px; align-items: center; flex-wrap: wrap; }
        .lib-btn-save {
            background: linear-gradient(135deg, #0e9f6e, #1a56db);
            color: white; border: none; border-radius: 8px;
            padding: 7px 18px; font-size: 0.82rem; font-weight: 600;
            cursor: pointer; transition: opacity 0.2s; font-family: inherit;
        }
        .lib-btn-save:hover:not(:disabled) { opacity: 0.88; }
        .lib-btn-save:disabled { background: #d1d5db; color: #9ca3af; cursor: not-allowed; }
        .lib-btn-comment-edit {
            background: #f0fdf4; color: #065f46;
            border: 1.5px solid #0e9f6e; border-radius: 8px;
            padding: 6px 12px; font-size: 0.76rem; font-weight: 600;
            cursor: pointer; font-family: inherit; transition: background 0.15s;
        }
        .lib-btn-comment-edit:hover { background: #dcfce7; }
        .lib-btn-comment-cancel {
            background: #f3f4f6; color: #374151;
            border: 1.5px solid #e5e7eb; border-radius: 7px;
            padding: 6px 12px; font-size: 0.76rem;
            cursor: pointer; font-family: inherit;
        }
        .save-feedback { font-size: 0.73rem; padding: 4px 9px; border-radius: 6px; display: none; font-weight: 600; }
        .save-feedback.success { background: #def7ec; color: #03543f; display: inline-block; }
        .save-feedback.error   { background: #fde8e8; color: #9b1c1c; display: inline-block; }

        /* ── PERFORMANCE EVALUATION MODAL ── */
        .eval-overlay { display: none; position: fixed; inset: 0; background: rgba(0,0,0,0.72); z-index: 1400; overflow-y: auto; padding: 0; }
        .eval-overlay.open { display: block; }
        .eval-doc-toolbar { background: var(--navy); padding: 0.55rem 1.5rem; display: flex; align-items: center; justify-content: space-between; position: sticky; top: 0; z-index: 200; box-shadow: 0 2px 10px rgba(0,0,0,0.35); gap: 1rem; flex-wrap: wrap; }
        .eval-doc-toolbar-left { display: flex; align-items: center; gap: 10px; min-width: 0; }
        .eval-doc-toolbar-title { font-family: 'DM Sans', sans-serif; font-size: 0.82rem; font-weight: 700; color: rgba(255,255,255,0.85); white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
        .eval-doc-toolbar-right { display: flex; align-items: center; gap: 8px; flex-shrink: 0; }
        .eval-tbtn { display: inline-flex; align-items: center; gap: 0.4rem; padding: 6px 18px; border-radius: 5px; font-size: 0.74rem; font-weight: 600; cursor: pointer; border: none; font-family: 'DM Sans', sans-serif; transition: all 0.15s; }
        .eval-tbtn-primary { background: #2563eb; color: #fff; }
        .eval-tbtn-primary:hover { background: #1d4ed8; }
        .eval-tbtn-primary:disabled { background: #4b6da8; cursor: not-allowed; opacity: 0.7; }
        .eval-tbtn-close { background: rgba(255,255,255,0.13); color: rgba(255,255,255,0.85); border: 1px solid rgba(255,255,255,0.2); }
        .eval-tbtn-close:hover { background: rgba(255,255,255,0.22); color: #fff; }
        .eval-tbtn-return { background: rgba(255,255,255,0.13); color: rgba(255,255,255,0.85); border: 1px solid rgba(255,255,255,0.2); }
        .eval-tbtn-return:hover { background: rgba(255,255,255,0.22); color: #fff; }
        .eval-pdf-status { display: none; padding: 8px 24px; font-family: 'JetBrains Mono', monospace; font-size: 0.74rem; text-align: center; flex-shrink: 0; }
        .eval-pdf-status.show       { display: block; }
        .eval-pdf-status.generating { background: #fef3c7; color: #92400e; border-top: 1px solid #fcd34d; }
        .eval-pdf-status.saving     { background: #eff6ff; color: #1e40af; border-top: 1px solid #bfdbfe; }
        .eval-pdf-status.success    { background: #d1fae5; color: #064e3b; border-top: 1px solid #6ee7b7; }
        .eval-pdf-status.error      { background: #fee2e2; color: #7f1d1d; border-top: 1px solid #fca5a5; }
        .eval-doc-canvas { background: #d8dde8; padding: 24px 16px 40px; min-height: calc(100vh - 46px); display: flex; flex-direction: column; align-items: center; }

        /* ══ Training Plan (NEUST-OJT-F013) editable form — same design
              family as Eval_form.php's .doc-outer/.doc-paper, extended
              with editable inputs while the evaluation is still open.
              The .doc-paper element is now a fixed A4-sized page
              (794×1123px @96dpi) so that long content automatically
              overflows into additional stacked .doc-paper "sheets"
              instead of growing one continuous page. ══ */
        .doc-outer { width: 794px; max-width: 100%; margin: 0 auto; display: flex; flex-direction: column; gap: 20px; }
        .doc-paper {
            background:#fff; border:1px solid #b0b8cc; box-shadow:0 4px 32px rgba(0,0,0,.22);
            width:794px; height:1123px; max-width:100%;
            font-family:"Times New Roman","Crimson Pro",Times,serif; color:#1a1a1a;
            display:flex; flex-direction:column; overflow:hidden; box-sizing:border-box;
        }
        .tp-letterhead { background: var(--navy); padding: 12px 24px; display:flex; align-items:center; gap:14px; border-bottom:3px solid var(--gold); flex-shrink:0; }
        .tp-lh-seal { width:58px; height:58px; border-radius:50%; display:flex; align-items:center; justify-content:center; flex-shrink:0; overflow:hidden; border:2px solid rgba(255,255,255,.25); }
        .tp-lh-seal img { width:100%; height:100%; object-fit:cover; border-radius:50%; display:block; }
        .tp-lh-text { color:#fff; flex:1; }
        .tp-lh-line1 { font-size:8.5px; letter-spacing:.18em; text-transform:uppercase; color:#aac4f0; margin-bottom:2px; font-family:'JetBrains Mono',monospace; }
        .tp-lh-line2 { font-size:15.5px; font-weight:700; line-height:1.25; text-transform:uppercase; letter-spacing:.01em; }
        .tp-lh-line3 { font-size:9.5px; color:#dbe6fb; margin-top:2px; }
        .tp-lh-line4 { font-size:9px; color:#aac4f0; margin-top:1px; }
        .tp-lh-line5 { font-size:8.5px; color:#aac4f0; margin-top:1px; font-style:italic; }
        .tp-title-band { background:#f4f5fb; border-bottom:1.5px solid var(--rule); padding:8px 24px 7px; text-align:center; flex-shrink:0; }
        .tp-title-band h1 { font-family:'DM Sans',sans-serif; font-size:17px; font-weight:700; color:var(--navy); letter-spacing:.035em; text-transform:uppercase; }
        .tp-form-meta { margin-top:3px; font-family:'JetBrains Mono',monospace; font-size:7.5px; color:#999; }
        .tp-footer-band { background:#f0f2f8; border-top:1.5px solid var(--navy); padding:4px 24px; display:flex; justify-content:space-between; font-family:'JetBrains Mono',monospace; font-size:7.5px; color:#888; letter-spacing:.07em; flex-shrink:0; margin-top:auto; }
        .tp-form-body { padding:14px 28px 18px; flex:1; display:flex; flex-direction:column; min-height:0; overflow:hidden; }
        .tp-section-title { font-size:13px; font-weight:700; color:#1a1a1a; margin:16px 0 6px; }
        .tp-section-title:first-child { margin-top:0; }
        .tp-body-text { font-size:12px; line-height:1.5; margin-bottom:8px; text-indent:24px; }
        .tp-body-list { margin:0 0 8px 44px; font-size:12px; line-height:1.5; }
        .tp-body-list li { margin-bottom:4px; }
        .tp-scale-table { width:60%; border-collapse:collapse; border:1px solid #000; margin:4px 0 10px; font-size:12px; }
        .tp-scale-table td { border:1px solid #000; padding:4px 10px; text-align:center; }
        .tp-comp-table { width:100%; border-collapse:collapse; border:1px solid #000; margin-bottom:4px; font-size:11.5px; table-layout:fixed; }
        .tp-comp-table col.tp-col-item { width:auto; }
        .tp-comp-table col.tp-col-rate { width:100px; }
        .tp-comp-table col.tp-col-remarks { width:170px; }
        .tp-comp-table td { border:1px solid #000; padding:5px 8px; vertical-align:top; }
        .tp-cat-tr .tp-cat-td { font-weight:700; background:#f4f5fb; }
        .tp-cat-tr .tp-cat-th-cell { font-weight:700; text-align:center; background:#f4f5fb; }
        .tp-item-tr .tp-item-td { padding-left:8px; }
        .tp-total-tr .tp-total-td { font-weight:700; text-align:right; padding-right:10px; background:#eef1fb; }
        .tp-total-tr td.tp-total-val { background:#eef1fb; }
        select.tp-rate-select {
            width:100%; height:26px; border:1px solid #c8cfe8; border-radius:4px; background:#fff;
            font-family:inherit; font-size:11.5px; color:#1a1a1a; text-align:center; outline:none; cursor:pointer;
            transition: border-color 0.2s, background 0.2s, box-shadow 0.2s;
        }
        select.tp-rate-select:focus { border-color:#1a56db; }
        select.tp-rate-select:disabled { background:#f3f4f6; color:#374151; cursor:not-allowed; }
        input.tp-remarks-input {
            width:100%; height:26px; border:1px solid #c8cfe8; border-radius:4px; background:#fff;
            font-family:inherit; font-size:11.5px; color:#1a1a1a; text-align:left; outline:none; padding:0 6px;
        }
        input.tp-remarks-input:focus { border-color:#1a56db; }
        input.tp-remarks-input:disabled { background:#f3f4f6; color:#374151; }
        .tp-total-readout { font-weight:700; font-size:12px; text-align:center; display:block; }
        .tp-overall-line { margin:14px 0 30px; text-align:center; font-size:13px; font-weight:700; }
        .tp-overall-line .tp-overall-val { display:inline-block; min-width:80px; border-bottom:1px solid #000; padding:0 6px; }
        .tp-sig-block { width:300px; margin:0 0 26px auto; text-align:center; }
        .tp-sig-block.tp-left { margin:0 auto 0 0; }
        /* ── Signature block: line sits between the person's NAME and
              their position, with the name directly touching (standing on)
              the line — the same visual treatment used by
              Eval_form_builder.php's static print/PDF rendering, so the
              on-screen editable preview and the saved/printed document
              match. ── */
        .tp-sig-name-wrap { padding-top:26px; }
        .tp-sig-block .tp-sig-name { font-size:12px; font-weight:700; line-height:1.2; }
        .tp-sig-line { border-bottom:1.5px solid #000; }
        .tp-sig-block .tp-sig-position { font-size:12px; font-weight:700; margin-top:2px; }
        .tp-sig-block .tp-sig-caption { font-size:11px; font-style:italic; margin-top:1px; }
        .tp-conforme-label { font-size:12px; font-weight:700; margin:10px 0 26px; }
        .tp-hint { font-size:0.68rem; color:#9ca3af; font-style:italic; margin:2px 0 14px; text-align:center; }
        .tp-page-tag { font-family:'JetBrains Mono',monospace; font-size:6.5px; color:#9aa3bd; text-align:right; padding:2px 24px 0; flex-shrink:0; }

        /* ══ Missing-rating highlight (used by the "See Missed Section"
              button on the incomplete-ratings popup). Applied to a
              competency row / its rating <select> when that item has
              no rating yet, and automatically removed the moment the
              item is rated. ══ */
        .tp-item-tr.tp-missing-row td {
            background: #fef2f2 !important;
            transition: background 0.25s ease;
        }
        select.tp-rate-select.tp-missing-highlight {
            border-color: #dc2626;
            background: #fef2f2;
            box-shadow: 0 0 0 2px rgba(220,38,38,0.18);
            animation: tp-missing-pulse 1.4s ease-in-out 2;
        }
        @keyframes tp-missing-pulse {
            0%, 100% { box-shadow: 0 0 0 2px rgba(220,38,38,0.18); }
            50%      { box-shadow: 0 0 0 5px rgba(220,38,38,0.30); }
        }

        /* ══ Student–Trainee Information — two-column form grid ══
              Replaces the old single-column tp-info-table with a
              modern label-above-field grid, grouped into three
              clearly separated sub-sections (Personal, School, HTE)
              with generous spacing so content no longer reads as
              compressed. Treated as one atomic block by the A4
              pagination engine (tpPaginateAndRender), same as before. ══ */
        .tp-form-grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            column-gap: 24px;
            row-gap: 14px;
            margin-bottom: 6px;
        }
        .tp-form-section-label {
            grid-column: 1 / -1;
            font-family: 'DM Sans', sans-serif;
            font-size: 11.5px;
            font-weight: 700;
            color: var(--navy);
            text-transform: uppercase;
            letter-spacing: 0.06em;
            border-bottom: 1.5px solid var(--rule);
            padding-bottom: 5px;
            margin-top: 16px;
        }
        .tp-form-section-label:first-child { margin-top: 0; }
        .tp-form-field {
            display: flex;
            flex-direction: column;
            gap: 4px;
            min-width: 0;
        }
        .tp-form-field.full { grid-column: 1 / -1; }
        .tp-form-field label {
            font-size: 10.5px;
            font-weight: 700;
            color: #6b7280;
            text-transform: uppercase;
            letter-spacing: 0.04em;
        }
        input.tp-form-input, select.tp-form-input {
            width: 100%;
            height: 30px;
            border: 1px solid #c8cfe8;
            border-radius: 6px;
            background: #fff;
            font-family: "Times New Roman","Crimson Pro",Times,serif;
            font-size: 12.5px;
            color: #1a1a1a;
            padding: 0 9px;
            outline: none;
            transition: border-color 0.15s, background 0.15s;
        }
        select.tp-form-input { cursor: pointer; }
        input.tp-form-input:focus, select.tp-form-input:focus { border-color:#1a56db; background:#f7f9ff; }
        input.tp-form-input:disabled, input.tp-form-input[readonly], select.tp-form-input:disabled {
            background: #f3f4f6; color: #374151; border-color: #d7dbe8;
        }
        /* ══ Inline field row — used to place several form fields
              (e.g. Name/Age/Sex, or OJT trainor/Telephone/Date OJT
              started) horizontally aligned on a single line within
              the two-column .tp-form-grid. Spans the full grid width
              (like .tp-form-field.full) and lays its own child
              .tp-form-field items out side by side with a flex row. ══ */
        .tp-form-field-row {
            grid-column: 1 / -1;
            display: flex;
            gap: 18px;
        }
        .tp-form-field-row .tp-form-field { flex: 1; min-width: 0; }

        .eval-blob-frame { width: 100%; border: none; display: block; min-height: 900px; background: #d8dde8; }

        /* ── PDF GENERATION OVERLAY ──
           Shared visual shell (.pdf-gen-overlay / .pdf-gen-box / etc.)
           used by BOTH the "Save as PDF" overlay (#pdfGenOverlay) and
           the dedicated "Print" overlay (#printGenOverlay) below, so
           each action gets its own distinct loading screen (different
           icon/title/message) instead of sharing one generic screen. */
        .pdf-gen-overlay { display: none; position: fixed; inset: 0; background: rgba(7,20,95,0.82); z-index: 9000; align-items: center; justify-content: center; flex-direction: column; gap: 18px; }
        .pdf-gen-overlay.show { display: flex; }
        .pdf-gen-box { background: white; border-radius: 14px; padding: 32px 40px; text-align: center; box-shadow: 0 8px 40px rgba(0,0,0,0.35); max-width: 340px; width: 90%; }
        .pdf-gen-icon { font-size: 2.4rem; margin-bottom: 12px; }
        .pdf-gen-title { font-size: 1rem; font-weight: 700; color: #07145f; margin-bottom: 6px; }
        .pdf-gen-msg { font-size: 0.84rem; color: #6b7280; line-height: 1.55; }
        .pdf-gen-spinner { width: 36px; height: 36px; border: 4px solid #e5e7eb; border-top-color: #1a56db; border-radius: 50%; animation: spin 0.8s linear infinite; margin: 14px auto 0; }
        /* Print overlay's spinner uses the green accent so it reads as
           visually distinct from the blue PDF-generation spinner. */
        #printGenOverlay .pdf-gen-spinner { border-top-color: #0e9f6e; }

        .eval-submit-bar { background: #f4f5fb; border-top: 1.5px solid var(--navy); padding: 12px 24px; display: flex; align-items: center; justify-content: space-between; gap: 10px; flex-shrink: 0; width:100%; border-radius: 0 0 12px 12px; box-shadow: 0 4px 20px rgba(0,0,0,.12); }
        .eval-submit-bar-note { font-family: 'JetBrains Mono', monospace; font-size: 7.5px; color: #9ca3af; }
        .eval-submit-btn { background: linear-gradient(135deg, var(--navy), var(--accent)); color: white; border: none; border-radius: 6px; padding: 9px 22px; font-family: 'DM Sans', sans-serif; font-size: 0.85rem; font-weight: 700; cursor: pointer; transition: opacity 0.2s; }
        .eval-submit-btn:hover:not(:disabled) { opacity: 0.88; }
        .eval-submit-btn:disabled { background: #d1d5db; color: #9ca3af; cursor: not-allowed; }
        .eval-submit-btn.loading  { opacity: 0.7; cursor: not-allowed; }

        /* ── MODALS ── */
        .modal { display: none; position: fixed; inset: 0; background: rgba(0,0,0,0.5); z-index: 1500; align-items: center; justify-content: center; }
        .modal.open { display: flex; }
        .modal-box { background: white; border-radius: 16px; padding: 28px; max-width: 380px; width: 90%; text-align: center; }
        .modal-box h3 { font-size: 1.1rem; margin-bottom: 10px; }
        .modal-box p  { font-size: 0.88rem; color: #6b7280; margin-bottom: 20px; }
        .modal-btns { display: flex; gap: 10px; justify-content: center; }
        .btn-confirm { background: #f05252; color: white; border: none; border-radius: 8px; padding: 9px 20px; font-size: 0.87rem; cursor: pointer; }
        .btn-cancel  { background: #f3f4f6; color: #374151; border: none; border-radius: 8px; padding: 9px 20px; font-size: 0.87rem; cursor: pointer; }

        .exceed-popup { position: fixed; top: 50%; left: 50%; transform: translate(-50%, -50%) scale(0.85); background: white; border-radius: 16px; padding: 24px 28px; max-width: 340px; width: 90%; text-align: center; z-index: 1600; box-shadow: 0 8px 40px rgba(0,0,0,0.25); opacity: 0; transition: opacity 0.2s, transform 0.2s; pointer-events: none; }
        .exceed-popup.show { opacity: 1; transform: translate(-50%, -50%) scale(1); pointer-events: auto; }
        .exceed-popup-icon { font-size: 2.4rem; margin-bottom: 10px; }
        .exceed-popup h3 { font-size: 1rem; font-weight: 700; color: #dc2626; margin-bottom: 6px; }
        .exceed-popup p  { font-size: 0.84rem; color: #6b7280; margin-bottom: 16px; line-height: 1.5; }
        .exceed-popup-btns { display: flex; gap: 10px; justify-content: center; flex-wrap: wrap; }
        .exceed-popup button { background: #dc2626; color: white; border: none; border-radius: 8px; padding: 8px 22px; font-size: 0.86rem; font-weight: 600; cursor: pointer; font-family: inherit; }
        .exceed-popup button.exceed-popup-btn-secondary { background: #f3f4f6; color: #374151; }
        .exceed-popup-backdrop { display: none; position: fixed; inset: 0; background: rgba(0,0,0,0.35); z-index: 1599; }
        .exceed-popup-backdrop.show { display: block; }

        .final-modal { display: none; position: fixed; inset: 0; background: rgba(0,0,0,0.55); z-index: 1500; align-items: center; justify-content: center; }
        .final-modal.open { display: flex; }
        .final-modal .modal-box h3 { color: #4c1d95; font-size: 1.05rem; }
        .final-modal .modal-box .total-big { font-size: 2rem; font-weight: 800; color: #4c1d95; margin: 10px 0 4px; }
        .final-modal .modal-box .total-sub { font-size: 0.8rem; color: #6b7280; margin-bottom: 16px; }
        .btn-confirm-eval { background: linear-gradient(135deg,#4c1d95,#1a56db); color: white; border: none; border-radius: 8px; padding: 9px 20px; font-size: 0.87rem; cursor: pointer; }

        @media (max-width: 840px) {
            .eval-doc-canvas { padding: 0; }
            .doc-paper  { width: 100%; height: auto; overflow: visible; box-shadow: none; border: none; }
            .lib-split-body  { grid-template-columns: 1fr; }
            .lib-list-pane   { max-height: 200px; border-right: none; border-bottom: 1px solid #e5e7eb; }
            .lib-comment-drawer { width: 100%; max-width: 100%; right: -100%; }
            .lib-doc-toolbar-right { gap: 6px; }
            .tp-form-grid { grid-template-columns: 1fr; }
            .tp-form-field-row { flex-direction: column; gap: 14px; }
            .lib-stats-row { flex-direction: column; }
        }

        @page { size: A4 portrait; margin: 0; }

        @media print {
            .eval-overlay, .lib-overlay, .viewer-overlay,
            .modal, .final-modal,
            .exceed-popup, .sidebar, .navbar,
            .controls, .toast, .pdf-gen-overlay,
            .lib-comment-drawer, .lib-comment-drawer-backdrop { display: none !important; }
            .eval-overlay.open { display: block !important; background: #fff !important; }
            .eval-doc-toolbar, .eval-pdf-status, .eval-submit-bar { display: none !important; }
            .eval-doc-canvas { background: #fff !important; padding: 0 !important; }
            /*
             * FIX (page 1 mixing with page 2 on Print):
             * .doc-outer is `display:flex; flex-direction:column;` in the
             * base (screen) stylesheet, purely to add a visual gap between
             * sheets on screen. Chrome's print engine does not reliably
             * honor page-break-after/break-after on flex children — the
             * same root cause already documented and fixed in
             * EVAL_form_builder.php's v1.14 changelog for the locked/
             * submitted Training Plan print path. That same fix was never
             * applied here for the *editable* (pre-submission) Training
             * Plan print path, so .doc-outer stayed a flex container in
             * print and its .doc-paper sheets could overlap/mix on the
             * same physical page instead of breaking cleanly. Switching
             * .doc-outer to block layout for print makes each .doc-paper
             * a normal block-level child again, so page-break-after
             * applies exactly as intended: one .doc-paper == one physical
             * page.
             */
            .doc-outer { display: block !important; margin: 0 !important; gap: 0 !important; width: 210mm !important; }
            .doc-paper {
                border: none !important; box-shadow: none !important;
                page-break-after: always !important; break-after: always !important;
                width: 210mm !important;
                /*
                 * FIX (white space around the printed page): the sheet's
                 * width was locked to 210mm (matching the @page size
                 * above) but its height was locked to 1123px — a
                 * DIFFERENT unit system. 1123px is only an approximation
                 * of A4's 297mm height at a *presumed* 96dpi; some
                 * browsers/print pipelines don't resolve the CSS
                 * px-to-mm conversion identically between the on-screen
                 * render and the print render, so the sheet could come
                 * out a hair shorter (or taller) than the literal
                 * physical page — leaving a visible white margin/gutter
                 * around the printed sheet instead of the sheet filling
                 * the page edge-to-edge. Locking BOTH dimensions to the
                 * SAME unit (mm), matching the physical @page size
                 * exactly, removes that unit-conversion rounding gap so
                 * the printed sheet always stretches to fill the full
                 * physical page with no white space on any side.
                 * overflow:hidden is kept so any sub-pixel rendering
                 * variance between the on-screen measurement pass (which
                 * still works in px, since the JS pagination below still
                 * computes/locks each sheet's on-screen height in px) and
                 * the print pass is clipped instead of bleeding into the
                 * following physical page. This mirrors the identical
                 * hardening already used by EVAL_form_builder.php's
                 * print rules for the locked Training Plan.
                 */
                height: 297mm !important; min-height: 297mm !important; max-height: 297mm !important;
                overflow: hidden !important;
            }
            .doc-paper:last-child { page-break-after: auto !important; break-after: auto !important; }
            /* .tp-form-body already receives an explicit inline pixel
               height from tpPaginateAndRender() (see the JS below), so
               this print-time overflow:hidden only guards against
               negligible sub-pixel variance rather than clipping real
               content. */
            .tp-form-body { overflow: hidden !important; }
        }
    </style>
</head>
<body>

<!-- PDF GENERATION PROGRESS OVERLAY (used by "Save as PDF") -->
<div class="pdf-gen-overlay" id="pdfGenOverlay">
    <div class="pdf-gen-box">
        <div class="pdf-gen-icon"><i class="fas fa-file-pdf" style="color:#1a56db;"></i></div>
        <div class="pdf-gen-title">Generating PDF...</div>
        <div class="pdf-gen-msg" id="pdfGenMsg">Rendering evaluation form, please wait.</div>
        <div class="pdf-gen-spinner"></div>
    </div>
</div>

<!-- PRINT PREPARATION PROGRESS OVERLAY (used by "Print")
     ────────────────────────────────────────────────────────────────
     NEW: kept visually distinct from the "Save as PDF" overlay above
     (different icon, accent color, title, and default message) so
     Print and Save as PDF each show their own dedicated loading
     screen instead of sharing the exact same one. -->
<div class="pdf-gen-overlay" id="printGenOverlay">
    <div class="pdf-gen-box">
        <div class="pdf-gen-icon"><i class="fas fa-print" style="color:#0e9f6e;"></i></div>
        <div class="pdf-gen-title">Preparing to Print...</div>
        <div class="pdf-gen-msg" id="printGenMsg">Rendering document for printing, please wait.</div>
        <div class="pdf-gen-spinner"></div>
    </div>
</div>

<!-- SIDEBAR -->
<div id="sidebar" class="sidebar">
    <div class="sidebar-header">
        <!-- Company rep name + role label (mirrors attendance_management supervisor display) -->
        <div class="sidebar-user-info">
            <span class="sidebar-user-name"><?= htmlspecialchars($company_rep_display) ?></span>
            <span class="sidebar-user-role">Supervisor</span>
        </div>
        <button id="toggleBtn" style="background:none;border:none;color:white;cursor:pointer;font-size:20px;outline:none;flex-shrink:0;"><i class="fas fa-bars"></i></button>
    </div>
    <div class="sidebar-links">
        <a href="Profile.php"><i class="fas fa-user-circle"></i><span class="link-text">My Profile</span></a>
        <a href="add_ojt_student.php">
            <i class="fas fa-user-graduate"></i>
            <span class="link-text">OJT Student List</span>
            <?php if ($inbox_count > 0): ?>
                <span class="sidebar-badge"><?= $inbox_count ?></span>
            <?php endif; ?>
        </a>
        <a href="CompanyForm.php"><i class="fas fa-file-contract"></i><span class="link-text">Requirements</span></a>
        <a href="attendance_management.php">
            <i class="fas fa-building"></i>
            <span class="link-text">Attendance Management</span>
            <?php if ($pending_lr_count > 0): ?>
                <span class="sidebar-badge-late"><?= $pending_lr_count ?></span>
            <?php endif; ?>
        </a>
        <a href="company_reports.php" class="active">
            <i class="fas fa-chart-bar"></i>
            <span class="link-text">Company Reports</span>
        </a>
    </div>
    <div class="logout-link">
        <a href="login.php"><i class="fas fa-sign-out-alt"></i><span class="link-text" style="margin-left:10px;">Logout</span></a>
    </div>
</div>

<!-- MAIN CONTENT -->
<div class="main-content">
    <nav class="navbar">
        <img src="logo.webp" style="height:40px;margin-right:15px;">
        <div>
            <div style="font-weight:bold;font-size:16px;">NEUST Atate Campus</div>
            <div style="font-size:11px;color:var(--neust-gold);">Web-Based Smart OJT Monitoring and Supervision Analytics System</div>
        </div>
    </nav>

    <div class="page-inner">

        <!-- CONTROLS (simple total-student count sits to the left of the search bar) -->
        <div class="controls">
            <span class="simple-count" id="simpleStudentCount"><i class="fas fa-users"></i>&nbsp;Total: <span id="statTotalStudents"><?= $total_students ?></span></span>
            <input type="text" id="searchInput" placeholder="Search student...">
        </div>

        <!-- STUDENT CARDS -->
        <div class="cards" id="cardsContainer">
            <?php foreach ($all_students as $s):
                $sid         = $s['student_id'];
                $hasReports  = $s['total_reports'] > 0;
                $cardClass   = $hasReports ? 'has-reports' : 'no-reports';
                $fullName    = htmlspecialchars($s['first_name'] . ' ' . $s['last_name']);
                $course      = htmlspecialchars($s['course'] ?? '');
                $courseJs    = htmlspecialchars(addslashes($s['course'] ?? ''));
                $lastSub     = $s['last_submitted_at']
                    ? 'Last submitted: ' . date("M d, Y", strtotime($s['last_submitted_at']))
                    : 'No reports yet';
                $evalDone       = !empty($s['eval_submitted_at']);
                $evalBtnClass   = 'icon-btn' . ($evalDone ? ' eval-done' : '');
                $evalBtnIcon    = $evalDone ? 'fa-check' : 'fa-star';
                $evalBtnLabel   = $evalDone ? 'View Training Plan' : 'Evaluate';
            ?>
            <div class="student-card <?= $cardClass ?> searchable"
                 id="scard-<?= $sid ?>"
                 onclick="openLibrary(<?= $sid ?>, '<?= $fullName ?>', '<?= $courseJs ?>')">
                <div class="sc-info" style="width:100%;">
                    <div style="display:flex;align-items:center;gap:14px;flex-wrap:wrap;">
                        <div style="flex:1;min-width:0;">
                            <div class="sc-name"><?= $fullName ?></div>
                            <div class="sc-course"><?= $course ?></div>
                            <div class="sc-last"><?= $lastSub ?></div>
                        </div>
                        <div class="sc-badges">
                            <button type="button" class="<?= $evalBtnClass ?>" id="sc-eval-btn-<?= $sid ?>"
                                    data-tooltip="<?= $evalBtnLabel ?>"
                                    onclick="event.stopPropagation(); openEvalDirect(<?= $sid ?>, '<?= $fullName ?>', '<?= $courseJs ?>')">
                                <i class="fas <?= $evalBtnIcon ?>"></i>
                            </button>
                            <button type="button" class="icon-btn" data-tooltip="Open Library"
                                    onclick="event.stopPropagation(); openLibrary(<?= $sid ?>, '<?= $fullName ?>', '<?= $courseJs ?>')">
                                <i class="fas fa-book-open"></i>
                            </button>
                            <span class="sc-arrow">›</span>
                        </div>
                    </div>
                </div>
            </div>
            <?php endforeach; ?>
        </div>

    </div><!-- /page-inner -->
</div><!-- /main-content -->

<!-- FINAL SUBMIT CONFIRM MODAL -->
<div class="final-modal" id="finalConfirmModal">
    <div class="modal-box">
        <h3>Submit Training Plan?</h3>
        <div class="total-big" id="finalTotalDisplay">-</div>
        <div class="total-sub">Overall Competency Rating</div>
        <p style="font-size:0.84rem;color:#6b7280;margin-bottom:20px;">
            This evaluation is <strong>permanent and cannot be edited</strong> once submitted.
        </p>
        <div class="modal-btns">
            <button class="btn-confirm-eval" id="finalConfirmYes">Yes, Submit and Lock</button>
            <button class="btn-cancel" onclick="closeFinalModal()">Cancel</button>
        </div>
    </div>
</div>

<!-- EXCEED LIMIT POPUP -->
<div class="exceed-popup-backdrop" id="exceedBackdrop" onclick="closeExceedPopup()"></div>
<div class="exceed-popup" id="exceedPopup">
    <div class="exceed-popup-icon"><i class="fas fa-ban" style="color:#dc2626;"></i></div>
    <h3 id="exceedPopupTitle">Incomplete Ratings</h3>
    <p id="exceedPopupMsg">Please rate all competency items before submitting the Training Plan.</p>
    <div class="exceed-popup-btns">
        <button id="exceedSeeMissedBtn" class="exceed-popup-btn-secondary" onclick="seeMissedSection()"><i class="fas fa-location-crosshairs"></i> See Missed Section</button>
        <button onclick="closeExceedPopup()">Got it</button>
    </div>
</div>

<!-- REPORT VIEWER MODAL (full view) -->
<div class="viewer-overlay" id="viewerOverlay" onclick="closeViewer(event)">
    <div class="viewer-box">
        <div class="viewer-header">
            <h3 id="viewerTitle">Report</h3>
            <button class="viewer-close" onclick="closeViewerDirect()">&#x2715;</button>
        </div>
        <div class="viewer-body" id="viewerBody">
            <div class="viewer-loading"><div class="spinner"></div> Loading report...</div>
        </div>
    </div>
</div>

<!-- STUDENT LIBRARY — FULL-SCREEN DOCUMENT VIEW (toolbar on top, document fills the screen,
     split list+preview layout inside, comment moved into a collapsible drawer) -->
<div class="lib-overlay" id="libOverlay">
    <div class="lib-doc-toolbar">
        <div class="lib-doc-toolbar-left">
            <i class="fas fa-book-open" style="color:rgba(255,255,255,0.7);"></i>
            <span class="lib-doc-toolbar-title" id="libStudentName">Student Name</span>
            <span class="lib-doc-toolbar-course" id="libStudentCourse"></span>
        </div>
        <div class="lib-doc-toolbar-right">
            <button class="eval-tbtn eval-tbtn-primary" id="libPrintBtn" onclick="libPrintReport()" disabled><i class="fas fa-print"></i> Print</button>
            <button class="eval-tbtn eval-tbtn-primary" id="libSavePDFBtn" onclick="libSaveReportPDF()" disabled><i class="fas fa-file-pdf"></i> Save as PDF</button>
            <button class="eval-tbtn lib-tbtn-comment" id="libCommentToggleBtn" onclick="toggleCommentDrawer()" disabled><i class="fas fa-comment-alt"></i> Comment</button>
            <button class="eval-tbtn eval-tbtn-close" onclick="closeLibrary()">&#x2715; Close</button>
        </div>
    </div>

    <!-- Summary bar — weekly report compliance cards: Total (expected,
         one report per week from OJT start to today), Submitted
         (actual submitted count), and Not Submitted (Missed). Company
         grading has been removed, so this bar no longer shows a grade
         stat — only these three report-compliance counters. -->
    <div class="lib-summary-bar">
        <div class="lib-stats-row">
            <div class="lib-stat-card">
                <div class="lib-stat-icon total"><i class="fas fa-calendar-week"></i></div>
                <div class="lib-stat-text">
                    <div class="lib-stat-num" id="libStatTotalVal">-</div>
                    <div class="lib-stat-lbl2">Total</div>
                </div>
            </div>
            <div class="lib-stat-card">
                <div class="lib-stat-icon submitted"><i class="fas fa-check"></i></div>
                <div class="lib-stat-text">
                    <div class="lib-stat-num submitted-num" id="libStatSubmittedVal">-</div>
                    <div class="lib-stat-lbl2">Submitted</div>
                </div>
            </div>
            <div class="lib-stat-card">
                <div class="lib-stat-icon missed"><i class="fas fa-xmark"></i></div>
                <div class="lib-stat-text">
                    <div class="lib-stat-num missed-num" id="libStatMissedVal">-</div>
                    <div class="lib-stat-lbl2">Not Submitted</div>
                </div>
            </div>
        </div>
    </div>

    <!-- Doc body: split list + preview -->
    <div class="lib-doc-body">
        <div class="lib-split-body" id="libSplitBody">
            <!-- Left: report list -->
            <div class="lib-list-pane" id="libListPane">
                <div class="lib-loading"><div class="spinner"></div> Loading...</div>
            </div>
            <!-- Right: large report preview -->
            <div class="lib-detail-pane" id="libDetailPane">
                <div class="lib-detail-empty" id="libDetailEmpty">
                    <i class="fas fa-hand-point-left"></i>
                    <span>Select a report from the list</span>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- COMMENT DRAWER (collapsible side panel, toggled from the library toolbar) -->
<div class="lib-comment-drawer-backdrop" id="libCommentDrawerBackdrop" onclick="toggleCommentDrawer(false)"></div>
<div class="lib-comment-drawer" id="libCommentDrawer">
    <div class="lib-comment-drawer-header">
        <span><i class="fas fa-comment-alt"></i> Report Comment</span>
        <button onclick="toggleCommentDrawer(false)"><i class="fas fa-times"></i></button>
    </div>
    <div class="lib-comment-drawer-body" id="libCommentDrawerBody">
        <div class="lib-comment-drawer-empty">Select a report to view or add a comment.</div>
    </div>
</div>

<!-- OJT/INTERNSHIP TRAINING PLAN MODAL (NEUST-OJT-F013) -->
<div id="evalOverlay" class="eval-overlay">
    <div class="eval-doc-toolbar">
        <div class="eval-doc-toolbar-left">
            <i class="fas fa-clipboard-list" style="color:rgba(255,255,255,0.7);"></i>
            <span class="eval-doc-toolbar-title" id="evalToolbarTitle">OJT/Internship Training Plan</span>
        </div>
        <div class="eval-doc-toolbar-right">
            <button class="eval-tbtn eval-tbtn-primary" id="evalPrintBtn" onclick="evalPrintDoc()" style="display:none;"><i class="fas fa-print"></i> Print</button>
            <button class="eval-tbtn eval-tbtn-primary" id="evalSavePDFBtn" onclick="evalSavePDF()" style="display:none;"><i class="fas fa-file-pdf"></i> Save as PDF</button>
            <button class="eval-tbtn eval-tbtn-close" onclick="closeEvalModal()"><i class="fas fa-times"></i> Close</button>
        </div>
    </div>
    <div class="eval-pdf-status" id="evalPdfStatus"></div>
    <div class="eval-doc-canvas" id="evalDocCanvas">
        <!-- Editable Training Plan form is injected here by renderEvalFormBody().
             When the evaluation is locked, this is replaced with an iframe
             pointing at print_eval=1 (which calls buildEVALFormHTML()).
             The editable version is paginated into fixed A4 .doc-paper
             "sheets" by tpPaginateAndRender(). -->
    </div>
</div>

<!-- TOAST -->
<div class="toast" id="toast"></div>

<iframe id="evalPdfHiddenFrame" style="position:fixed;left:-9999px;top:-9999px;width:900px;height:1200px;border:none;visibility:hidden;" tabindex="-1"></iframe>

<!-- HIDDEN PRINT FRAME
     ────────────────────────────────────────────────────────────────
     Used by evalPrintDoc()/openPrintImagesWindow()/printUrlInPage()
     so that printing the Training Plan always happens IN PLACE — the
     browser's native print dialog is triggered against this
     off-screen, always-attached iframe rather than a new browser tab
     or popup window (window.open). The user therefore never leaves
     the current page/tab when they click Print. -->
<iframe id="evalPrintHiddenFrame" style="position:fixed;left:-9999px;top:-9999px;width:900px;height:1200px;border:none;visibility:hidden;" tabindex="-1"></iframe>

<script>
/* ── SIDEBAR ── */
const sb = document.getElementById('sidebar');
document.getElementById('toggleBtn').addEventListener('click', () => {
    sb.classList.toggle('collapsed');
    const mc = document.querySelector('.main-content');
    mc.style.marginLeft = sb.classList.contains('collapsed') ? '80px' : '260px';
    mc.style.width      = sb.classList.contains('collapsed') ? 'calc(100% - 80px)' : 'calc(100% - 260px)';
});

/* ── STATE ── */
let _currentStudentId      = null;
let _currentStudentName    = '';
let _currentStudentCourse  = '';
let _currentEvalData       = null;
let _currentSchoolRep      = '';
let _currentOjtStartDate   = '';
let _allReports            = [];
let _activeReportId        = null;
/* NEW: weekly report compliance counters returned by the
   student_library=1 endpoint (see computeWeeklyReportStats() on the
   server) — { total_expected, submitted, missed } — used to populate
   the Total / Submitted / Not Submitted cards in the library summary
   bar via renderLibrarySummary(). */
let _currentReportStats    = null;
/* NEW: student data already submitted on AccomForm.php (Age, Sex, Home
   Address, Home Telephone no. [falls back to Guardian No. when the
   student has no telephone on file], Guardian name, Guardian No.), and
   now also School, School address, Subject, Required no. of hours, and
   Coordinator telephone no. pulled from the matching ADMIN account (see
   getAdminSchoolInfoByCoordinatorName()) plus College pulled from
   student_information on the server (see getStudentAccomInfo()) — used
   to pre-fill the Training Plan's Personal information AND School
   information sections instead of leaving them blank for the company
   rep to type from scratch. All of these pre-filled fields are rendered
   read-only (see renderEvalFormBody() below). */
let _currentAccomInfo       = {};
/* Keys (e.g. 'gen_punctuality_0', 'spec_3') for whichever competency
   items were missing a rating the last time the incomplete-ratings
   popup was shown — used by the "See Missed Section" button to know
   which rows to highlight. */
let _tpMissingKeys          = [];

var _COMPANY_REP_NAME = <?= json_encode($_company_rep_name) ?>;
var _COMPANY_NAME     = <?= json_encode($company_name) ?>;
/* NEW: the company's own Telephone no. and Address, pulled from
   company_information (see the $company_address / $company_telephone
   fetch near the top of this file). Used to pre-fill the Host
   Training Establishment (HTE) "Telephone no." and "Address" fields
   in the Training Plan form (renderEvalFormBody()) — same
   pre-filled-and-read-only treatment already used for the
   student's Personal information via _currentAccomInfo. */
var _COMPANY_TEL      = <?= json_encode($company_telephone) ?>;
var _COMPANY_ADDRESS  = <?= json_encode($company_address) ?>;

/* ── SEARCH FILTER ── */
function applyFilters() {
    const searchVal = document.getElementById('searchInput').value.toLowerCase();
    document.querySelectorAll('.searchable').forEach(card => {
        card.style.display = card.innerText.toLowerCase().includes(searchVal) ? '' : 'none';
    });
}
document.getElementById('searchInput').addEventListener('keyup', applyFilters);

/* ── OPEN LIBRARY ── */
function openLibrary(studentId, studentName, course) {
    _currentStudentId     = studentId;
    _currentStudentName   = studentName;
    _currentStudentCourse = course || '';
    _currentEvalData      = null;
    _currentSchoolRep     = '';
    _currentOjtStartDate  = '';
    _allReports           = [];
    _activeReportId       = null;
    _currentAccomInfo     = {};
    _currentReportStats   = null;

    document.getElementById('libStudentName').textContent   = studentName;
    document.getElementById('libStudentCourse').textContent = course || '';
    resetLibSummaryStats();

    document.getElementById('libListPane').innerHTML   = '<div class="lib-loading"><div class="spinner"></div> Loading...</div>';
    document.getElementById('libDetailPane').innerHTML = '<div class="lib-detail-empty"><i class="fas fa-hand-point-left"></i><span>Select a report from the list</span></div>';

    resetLibReportToolbar();
    toggleCommentDrawer(false);
    document.getElementById('libCommentDrawerBody').innerHTML = '<div class="lib-comment-drawer-empty">Select a report to view or add a comment.</div>';

    document.body.style.overflow = 'hidden';
    document.getElementById('libOverlay').classList.add('open');

    fetch('company_reports.php?student_library=1&student_id=' + studentId)
        .then(r => r.json())
        .then(data => {
            if (data.error) {
                document.getElementById('libListPane').innerHTML = '<div class="lib-list-empty">' + escHtml(data.error) + '</div>';
                return;
            }
            _currentEvalData     = data.performance || null;
            _currentSchoolRep    = data.school_representative || '';
            _currentOjtStartDate = data.ojt_start_date || '';
            _allReports          = data.reports || [];
            _currentAccomInfo    = data.accom_info || {};
            _currentReportStats  = data.report_stats || null;

            renderLibrarySummary(_allReports, _currentReportStats);
            renderListPane(_allReports);

            if (_allReports.length > 0) {
                selectReport(_allReports[0].report_id);
            }
        })
        .catch(() => {
            document.getElementById('libListPane').innerHTML = '<div class="lib-list-empty">Network error. Please try again.</div>';
        });
}

/* Resets the report-level toolbar controls (Print / Save PDF / Comment)
   to their default disabled state — used whenever the library is opened
   for a (new) student, before any report has been selected/loaded. */
function resetLibReportToolbar() {
    const printBtn   = document.getElementById('libPrintBtn');
    const pdfBtn     = document.getElementById('libSavePDFBtn');
    const commentBtn = document.getElementById('libCommentToggleBtn');
    if (printBtn)   printBtn.disabled = true;
    if (pdfBtn)     pdfBtn.disabled = true;
    if (commentBtn) { commentBtn.disabled = true; commentBtn.classList.remove('has-comment'); }
}

/* NEW: resets the Total / Submitted / Not Submitted summary cards back
   to their loading placeholder ('-') — used whenever the library is
   (re)opened for a (new) student, before the student_library=1 fetch
   resolves. */
function resetLibSummaryStats() {
    const totalEl     = document.getElementById('libStatTotalVal');
    const submittedEl = document.getElementById('libStatSubmittedVal');
    const missedEl     = document.getElementById('libStatMissedVal');
    if (totalEl)     totalEl.textContent     = '-';
    if (submittedEl) submittedEl.textContent = '-';
    if (missedEl)     missedEl.textContent   = '-';
}

/* ══════════════════════════════════════════════════════════════
   OPEN TRAINING PLAN DIRECTLY FROM THE STUDENT SUMMARY CARD
   Evaluate / View Training Plan now lives on the student summary card
   itself (instead of inside the report library toolbar). This sets
   the same global state openEvalModal() relies on, fetches the
   data it needs (performance/eval status, school rep, OJT start
   date, and the pre-fill accom info), then opens the Training Plan
   modal directly — without requiring the report library to be
   opened first.
   ══════════════════════════════════════════════════════════════ */
function openEvalDirect(studentId, studentName, course) {
    _currentStudentId     = studentId;
    _currentStudentName   = studentName;
    _currentStudentCourse = course || '';
    _currentEvalData      = null;
    _currentSchoolRep     = '';
    _currentOjtStartDate  = '';
    _currentAccomInfo     = {};

    fetch('company_reports.php?student_library=1&student_id=' + studentId)
        .then(r => r.json())
        .then(data => {
            if (data.error) { showToast(data.error); return; }
            _currentEvalData     = data.performance || null;
            _currentSchoolRep    = data.school_representative || '';
            _currentOjtStartDate = data.ojt_start_date || '';
            _currentAccomInfo    = data.accom_info || {};
            openEvalModal();
        })
        .catch(() => { showToast('Network error. Please try again.'); });
}

/* Populates the Total / Submitted / Not Submitted summary cards.
   `reports` is the student's report list (used as a fallback so the
   Submitted count is never wrong even if `stats` wasn't returned);
   `stats` is the { total_expected, submitted, missed } payload from
   computeWeeklyReportStats() on the server (see the student_library=1
   handler). Total = one report expected per week from the start of
   OJT through today; Missed = Total - Submitted, floored at 0. */
function renderLibrarySummary(reports, stats) {
    const submittedCount = (reports || []).length;
    const totalExpected  = (stats && typeof stats.total_expected === 'number')
        ? stats.total_expected
        : submittedCount;
    const missedCount    = (stats && typeof stats.missed === 'number')
        ? stats.missed
        : Math.max(0, totalExpected - submittedCount);

    const totalEl     = document.getElementById('libStatTotalVal');
    const submittedEl = document.getElementById('libStatSubmittedVal');
    const missedEl     = document.getElementById('libStatMissedVal');
    if (totalEl)     totalEl.textContent     = totalExpected;
    if (submittedEl) submittedEl.textContent = submittedCount;
    if (missedEl)     missedEl.textContent   = missedCount;
}

/* ── RENDER LEFT LIST ── */
function renderListPane(reports) {
    const pane = document.getElementById('libListPane');
    if (!reports || reports.length === 0) {
        pane.innerHTML = '<div class="lib-list-empty">No reports submitted yet.</div>';
        return;
    }
    let html = '';
    reports.forEach(rep => {
        const monTs  = new Date(rep.week_start + 'T00:00:00');
        const friTs  = new Date(rep.week_start + 'T00:00:00');
        friTs.setDate(friTs.getDate() + 4);
        const fmtDs  = d => d.toLocaleDateString('en-US', { month: 'short', day: '2-digit' });
        const fmtD   = d => d.toLocaleDateString('en-US', { month: 'short', day: '2-digit', year: 'numeric' });
        const weekRange = fmtDs(monTs) + ' - ' + fmtD(friTs);
        const subLabel = rep.submitted_at
            ? 'Submitted ' + new Date(rep.submitted_at.replace(' ','T')).toLocaleDateString('en-US',{month:'short',day:'2-digit'})
            : 'Not submitted';

        html += `<div class="lib-list-item" id="list-item-${rep.report_id}" onclick="selectReport(${rep.report_id})">
            <div class="lib-list-week">${weekRange}</div>
            <div class="lib-list-sub">${subLabel}</div>
        </div>`;
    });
    pane.innerHTML = html;
}

/* ══════════════════════════════════════════════════════════════
   SELECT REPORT & RENDER DETAIL PANE
   ── The right side shows a large inline report preview only.
      The comment/feedback UI now lives in the collapsible comment
      drawer (see renderCommentDrawer / toggleCommentDrawer), and
      the toolbar's Print / Save as PDF / Comment buttons are
      enabled or disabled based on whether this report has a file.
   ══════════════════════════════════════════════════════════════ */
function selectReport(reportId) {
    _activeReportId = reportId;

    document.querySelectorAll('.lib-list-item').forEach(el => el.classList.remove('active'));
    const listItem = document.getElementById('list-item-' + reportId);
    if (listItem) listItem.classList.add('active');

    const rep = _allReports.find(r => r.report_id == reportId);
    if (!rep) return;

    const hasBlob = !!rep.has_blob;

    const noFileNote = !hasBlob
        ? `<div style="font-size:0.75rem;color:#9ca3af;padding:2px 0;">No file attached to this report.</div>`
        : '';

    const previewHtml = hasBlob
        ? `<div class="lib-report-preview" id="report-preview-${rep.report_id}">
               <div class="lib-report-preview-loading"><div class="spinner"></div> Loading preview...</div>
           </div>`
        : `<div class="lib-report-preview">
               <div class="lib-report-preview-empty">
                   <i class="fas fa-file-circle-xmark"></i>
                   <span>No report file to preview.</span>
               </div>
           </div>`;

    const detailPane = document.getElementById('libDetailPane');
    detailPane.innerHTML = `
        <div class="lib-detail-body">
            ${noFileNote}
            <div class="lib-preview-section">
                <div class="lib-preview-title"><i class="fas fa-file-lines" style="color:#1a56db;margin-right:4px;"></i> Report Preview</div>
                ${previewHtml}
            </div>
        </div>`;

    if (hasBlob) loadReportPreview(rep.report_id);

    /* Toolbar: enable/disable Print & Save PDF based on whether
       this report has a file attached. */
    const printBtn = document.getElementById('libPrintBtn');
    const pdfBtn   = document.getElementById('libSavePDFBtn');
    if (printBtn) printBtn.disabled = !hasBlob;
    if (pdfBtn)   pdfBtn.disabled   = !hasBlob;

    /* Comment drawer: always available once a report is selected
       (a comment can be added even when there's no file). */
    const commentBtn = document.getElementById('libCommentToggleBtn');
    if (commentBtn) {
        commentBtn.disabled = false;
        commentBtn.classList.toggle('has-comment', !!(rep.feedback && rep.feedback.trim() !== ''));
    }
    renderCommentDrawer(rep);
}

/* ── INLINE REPORT PREVIEW (right pane) ──
   Reuses the existing view=1 JSON endpoint: html reports render in
   an inline iframe (viewraw=1), xlsx reports render as an inline
   table — the same content the full-view modal shows, just embedded
   directly in the (now much larger) detail pane. */
function loadReportPreview(reportId) {
    const container = document.getElementById('report-preview-' + reportId);
    if (!container) return;

    fetch('company_reports.php?view=1&id=' + reportId)
        .then(r => r.json())
        .then(data => {
            if (!document.body.contains(container)) return; // user navigated to another report meanwhile
            if (data.error) {
                container.innerHTML = '<div class="lib-report-preview-empty"><i class="fas fa-triangle-exclamation"></i><span>' + escHtml(data.error) + '</span></div>';
            } else if (data.is_html) {
                container.innerHTML = '<iframe class="lib-report-preview-frame" src="company_reports.php?viewraw=1&id=' + reportId + '" title="Report preview"></iframe>';
            } else {
                container.innerHTML = '<div class="lib-report-preview-table">' + data.html + '</div>';
            }
        })
        .catch(() => {
            if (document.body.contains(container)) {
                container.innerHTML = '<div class="lib-report-preview-empty"><i class="fas fa-triangle-exclamation"></i><span>Failed to load preview.</span></div>';
            }
        });
}

/* ══════════════════════════════════════════════════════════════
   COMMENT DRAWER
   Renders the comment/feedback UI (previously embedded next to the
   preview) into the collapsible side drawer, and toggles the drawer
   open/closed. The drawer content is rebuilt every time a different
   report is selected so it always reflects the active report.
   ══════════════════════════════════════════════════════════════ */
function renderCommentDrawer(rep) {
    const body = document.getElementById('libCommentDrawerBody');
    if (!body || !rep) return;

    const feedbackVal    = escHtml(rep.feedback || '');
    const commentDisplay = rep.feedback
        ? `<div class="lib-comment-display">${escHtml(rep.feedback).replace(/\n/g,'<br>')}</div>`
        : `<div class="lib-comment-display empty">No comment yet.</div>`;

    body.innerHTML = `
        <div class="lib-comment-section" id="comment-section-${rep.report_id}">
            <div class="lib-comment-title-row">
                <div class="lib-comment-title"><i class="fas fa-comment-alt" style="color:#6b7280;margin-right:4px;"></i> Comment <span style="font-size:0.65rem;color:#9ca3af;font-weight:400;text-transform:none;letter-spacing:0;">(editable anytime)</span></div>
            </div>
            <div id="comment-display-${rep.report_id}">${commentDisplay}</div>
            <textarea class="lib-comment-ta" id="comment-ta-${rep.report_id}"
                      placeholder="Write a comment or feedback..." style="display:none;">${feedbackVal}</textarea>
            <div class="lib-form-btns" id="comment-btns-${rep.report_id}" style="margin-top:7px;">
                <button class="lib-btn-comment-edit" id="comment-edit-btn-${rep.report_id}" onclick="enableCommentEdit(${rep.report_id})">
                    <i class="fas fa-pen"></i> Edit Comment
                </button>
                <button class="lib-btn-save" id="comment-save-btn-${rep.report_id}" style="display:none;"
                        onclick="saveCommentForReport(${rep.report_id}, ${_currentStudentId})">
                    <i class="fas fa-save"></i> Save Comment
                </button>
                <button class="lib-btn-comment-cancel" id="comment-cancel-btn-${rep.report_id}" style="display:none;"
                        onclick="cancelCommentEdit(${rep.report_id})">
                    Cancel
                </button>
            </div>
        </div>`;
}

/* Opens/closes the comment drawer. Pass true/false to force a state,
   or call with no arguments to toggle. */
function toggleCommentDrawer(forceState) {
    const drawer   = document.getElementById('libCommentDrawer');
    const backdrop = document.getElementById('libCommentDrawerBackdrop');
    if (!drawer) return;
    const shouldOpen = (typeof forceState === 'boolean') ? forceState : !drawer.classList.contains('open');
    drawer.classList.toggle('open', shouldOpen);
    if (backdrop) backdrop.classList.toggle('show', shouldOpen);
}

/* ── COMMENT EDIT ── */
function enableCommentEdit(reportId) {
    const display   = document.getElementById('comment-display-' + reportId);
    const ta        = document.getElementById('comment-ta-' + reportId);
    const editBtn   = document.getElementById('comment-edit-btn-' + reportId);
    const saveBtn   = document.getElementById('comment-save-btn-' + reportId);
    const cancelBtn = document.getElementById('comment-cancel-btn-' + reportId);
    if (display) display.style.display = 'none';
    if (ta)      { ta.style.display = ''; ta.focus(); }
    if (editBtn) editBtn.style.display = 'none';
    if (saveBtn) saveBtn.style.display = '';
    if (cancelBtn) cancelBtn.style.display = '';
}

function cancelCommentEdit(reportId) {
    const rep     = _allReports.find(r => r.report_id == reportId);
    const display = document.getElementById('comment-display-' + reportId);
    const ta      = document.getElementById('comment-ta-' + reportId);
    const editBtn = document.getElementById('comment-edit-btn-' + reportId);
    const saveBtn = document.getElementById('comment-save-btn-' + reportId);
    const cancelBtn = document.getElementById('comment-cancel-btn-' + reportId);
    if (ta) ta.value = rep ? (rep.feedback || '') : '';
    if (ta) ta.style.display = 'none';
    if (display) display.style.display = '';
    if (editBtn) editBtn.style.display = '';
    if (saveBtn) saveBtn.style.display = 'none';
    if (cancelBtn) cancelBtn.style.display = 'none';
}

/* ── SAVE COMMENT (grading removed — comment/feedback only) ── */
function saveCommentForReport(reportId, studentId) {
    const ta      = document.getElementById('comment-ta-' + reportId);
    const saveBtn = document.getElementById('comment-save-btn-' + reportId);
    if (!ta) return;
    const comment = ta.value.trim();

    if (saveBtn) { saveBtn.disabled = true; saveBtn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Saving...'; }

    const fd = new FormData();
    fd.append('report_id', reportId);
    fd.append('feedback', comment);

    fetch('company_reports.php?save_comment=1', { method: 'POST', body: fd })
        .then(r => r.json())
        .then(data => {
            if (data.success) {
                showToast('Comment saved.');
                const rep = _allReports.find(r => r.report_id == reportId);
                if (rep) rep.feedback = comment;

                const display = document.getElementById('comment-display-' + reportId);
                if (display) {
                    if (comment) {
                        display.className = 'lib-comment-display';
                        display.innerHTML = escHtml(comment).replace(/\n/g,'<br>');
                    } else {
                        display.className = 'lib-comment-display empty';
                        display.textContent = 'No comment yet.';
                    }
                    display.style.display = '';
                }
                if (ta) ta.style.display = 'none';
                const editBtn   = document.getElementById('comment-edit-btn-'   + reportId);
                const cancelBtn = document.getElementById('comment-cancel-btn-' + reportId);
                if (editBtn)   editBtn.style.display   = '';
                if (saveBtn)   { saveBtn.style.display = 'none'; saveBtn.disabled = false; saveBtn.innerHTML = '<i class="fas fa-save"></i> Save Comment'; }
                if (cancelBtn) cancelBtn.style.display = 'none';

                if (_activeReportId == reportId) {
                    const commentBtn = document.getElementById('libCommentToggleBtn');
                    if (commentBtn) commentBtn.classList.toggle('has-comment', !!comment);
                }
            } else {
                showToast(data.message || 'Save failed.');
                if (saveBtn) { saveBtn.disabled = false; saveBtn.innerHTML = '<i class="fas fa-save"></i> Save Comment'; }
            }
        })
        .catch(() => {
            showToast('Network error.');
            if (saveBtn) { saveBtn.disabled = false; saveBtn.innerHTML = '<i class="fas fa-save"></i> Save Comment'; }
        });
}

/* ── CLOSE LIBRARY ── */
function closeLibrary() {
    document.getElementById('libOverlay').classList.remove('open');
    toggleCommentDrawer(false);
    document.body.style.overflow = '';
}

/* ── FULL VIEW REPORT (kept for potential reuse; no longer linked
   from the comment drawer since the eye-icon button was removed) ── */
function viewReportDirect(id, weekLabel) {
    document.getElementById('viewerTitle').textContent = _currentStudentName + ' - ' + weekLabel;
    const viewerBody = document.getElementById('viewerBody');
    viewerBody.innerHTML = '<div class="viewer-loading"><div class="spinner"></div> Loading report...</div>';
    viewerBody.className = 'viewer-body';
    document.getElementById('viewerOverlay').classList.add('open');

    fetch('company_reports.php?view=1&id=' + id)
        .then(r => r.json())
        .then(data => {
            if (data.error) {
                viewerBody.className = 'viewer-body table-mode';
                viewerBody.innerHTML = '<p style="color:#f05252;padding:20px;">' + escHtml(data.error) + '</p>';
            } else if (data.is_html) {
                viewerBody.className = 'viewer-body iframe-mode';
                const iframe = document.createElement('iframe');
                iframe.src = 'company_reports.php?viewraw=1&id=' + id;
                iframe.title = 'Weekly Report';
                iframe.style.cssText = 'width:100%;height:100%;min-height:560px;border:none;display:block;';
                viewerBody.innerHTML = '';
                viewerBody.appendChild(iframe);
            } else {
                viewerBody.className = 'viewer-body table-mode';
                viewerBody.innerHTML = data.html;
            }
        })
        .catch(() => {
            viewerBody.className = 'viewer-body table-mode';
            viewerBody.innerHTML = '<p style="color:#f05252;padding:20px;">Failed to load report.</p>';
        });
}
function closeViewer(e)       { if (e.target === document.getElementById('viewerOverlay')) closeViewerDirect(); }
function closeViewerDirect()  { document.getElementById('viewerOverlay').classList.remove('open'); }

/* ══════════════════════════════════════════════════════════════
   LIBRARY TOOLBAR: PRINT & SAVE-AS-PDF FOR THE ACTIVE REPORT
   Mirrors the pattern used by the evaluation toolbar's Print /
   Save as PDF buttons (evalPrintDoc / evalSavePDF), but targets
   whichever report is currently shown in the preview pane.
   ══════════════════════════════════════════════════════════════ */
function libPrintReport() {
    if (!_activeReportId) return;
    const rep = _allReports.find(r => r.report_id == _activeReportId);
    if (!rep || !rep.has_blob) { showToast('No file to print.'); return; }

    const previewContainer = document.getElementById('report-preview-' + _activeReportId);
    if (!previewContainer) { showToast('Report preview not ready yet.'); return; }

    const iframe = previewContainer.querySelector('iframe');
    if (iframe) {
        const doPrint = function() {
            try {
                iframe.contentWindow.focus();
                iframe.contentWindow.print();
            } catch (e) {
                const url = 'company_reports.php?viewraw=1&id=' + _activeReportId;
                const w = window.open(url, '_blank');
                if (w) { w.onload = function() { w.focus(); w.print(); }; }
                else { alert('Pop-up blocked. Please allow pop-ups and try again.'); }
            }
        };
        if (iframe.contentDocument && iframe.contentDocument.readyState === 'complete') doPrint();
        else iframe.onload = doPrint;
    } else {
        // xlsx (table) preview — print via a temporary window
        const tableEl = previewContainer.querySelector('.lib-report-preview-table');
        const contentHtml = tableEl ? tableEl.innerHTML : previewContainer.innerHTML;
        const w = window.open('', '_blank');
        if (!w) { alert('Pop-up blocked. Please allow pop-ups and try again.'); return; }
        w.document.write('<html><head><title>Report</title><style>'
            + 'body{font-family:"Segoe UI",sans-serif;margin:20px;}'
            + '.xl-table{width:100%;border-collapse:collapse;font-size:0.82rem;}'
            + '.xl-table td{border:1px solid #e5e7eb;padding:8px 10px;vertical-align:top;line-height:1.5;}'
            + '.xl-table tr:first-child td{background:#1a56db;color:#fff;font-weight:700;font-size:1rem;text-align:center;}'
            + '.xl-table tr:nth-child(2) td{background:#0e9f6e;color:#fff;font-size:0.82rem;text-align:center;font-style:italic;}'
            + '.xl-table tr:nth-child(4) td{background:#374151;color:#fff;font-weight:700;text-align:center;}'
            + '</style></head><body>' + contentHtml + '</body></html>');
        w.document.close();
        w.onload = function() { w.focus(); w.print(); };
    }
}

function libSaveReportPDF() {
    if (!_activeReportId) return;
    const rep = _allReports.find(r => r.report_id == _activeReportId);
    if (!rep || !rep.has_blob) { showToast('No file to save.'); return; }

    const saveBtn = document.getElementById('libSavePDFBtn');
    if (saveBtn) { saveBtn.disabled = true; saveBtn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Generating...'; }

    const overlay = document.getElementById('pdfGenOverlay');
    const msgEl   = document.getElementById('pdfGenMsg');
    if (overlay) overlay.classList.add('show');
    if (msgEl)   msgEl.textContent = 'Loading report...';

    function resetBtn() {
        if (saveBtn) { saveBtn.disabled = false; saveBtn.innerHTML = '<i class="fas fa-file-pdf"></i> Save as PDF'; }
        if (overlay) overlay.classList.remove('show');
    }

    const previewContainer = document.getElementById('report-preview-' + _activeReportId);
    if (!previewContainer) { showToast('Report preview not ready yet.'); resetBtn(); return; }

    function buildAndSavePdf(canvas) {
        try {
            const jsPDF = window.jspdf ? window.jspdf.jsPDF : window.jsPDF;
            if (!jsPDF) throw new Error('jsPDF library not loaded.');
            const PAGE_W_MM = 210, PAGE_H_MM = 297, MIN_SLICE_PX = 4;
            const pxToMm = PAGE_W_MM / canvas.width;
            const pageHeightPx = PAGE_H_MM / pxToMm;
            const totalPages = Math.ceil(canvas.height / pageHeightPx);
            let pdf = null, pagesAdded = 0;
            for (let page = 0; page < totalPages; page++) {
                const srcY = Math.round(page * pageHeightPx);
                const srcH = Math.min(Math.round(pageHeightPx), canvas.height - srcY);
                if (srcH < MIN_SLICE_PX) continue;
                const sliceHeightMm = srcH * pxToMm;
                const isLastPage = (page === totalPages - 1) || (srcH < pageHeightPx - MIN_SLICE_PX);
                const thisPageH = isLastPage ? sliceHeightMm : PAGE_H_MM;
                if (pdf === null) {
                    pdf = new jsPDF({ orientation: 'portrait', unit: 'mm', format: [PAGE_W_MM, thisPageH], compress: true });
                } else { pdf.addPage([PAGE_W_MM, thisPageH]); }
                const sliceCanvas = document.createElement('canvas');
                sliceCanvas.width = canvas.width; sliceCanvas.height = srcH;
                const ctx = sliceCanvas.getContext('2d');
                ctx.fillStyle = '#ffffff'; ctx.fillRect(0, 0, sliceCanvas.width, sliceCanvas.height);
                ctx.drawImage(canvas, 0, srcY, canvas.width, srcH, 0, 0, canvas.width, srcH);
                pdf.addImage(sliceCanvas.toDataURL('image/png', 1.0), 'PNG', 0, 0, PAGE_W_MM, sliceHeightMm, '', 'FAST');
                pagesAdded++;
            }
            if (!pdf || pagesAdded === 0) throw new Error('No valid pages were generated.');
            const rep2 = _allReports.find(r => r.report_id == _activeReportId);
            const weekPart = rep2 ? rep2.week_start : '';
            const safeName = (_currentStudentName || 'Student').replace(/[\/\\:*?"<>|]/g, '').trim();
            pdf.save(safeName + '_Report_' + (weekPart || '') + '.pdf');
        } catch (err) {
            showToast('PDF build error: ' + err.message);
        }
        resetBtn();
    }

    function doCapture(el) {
        if (msgEl) msgEl.textContent = 'Rendering document...';
        html2canvas(el, { scale: 2, useCORS: true, allowTaint: true, backgroundColor: '#ffffff', logging: false })
            .then(buildAndSavePdf)
            .catch(function(err) {
                showToast('Render error: ' + err.message);
                resetBtn();
            });
    }

    const iframe = previewContainer.querySelector('iframe');
    if (iframe) {
        const capture = function() {
            try {
                const doc = iframe.contentDocument || iframe.contentWindow.document;
                if (!doc || !doc.body) throw new Error('Could not access report content.');
                doCapture(doc.body);
            } catch (err) {
                showToast('Could not read report content: ' + err.message);
                resetBtn();
            }
        };
        if (iframe.contentDocument && iframe.contentDocument.readyState === 'complete') capture();
        else iframe.onload = capture;
    } else {
        const tableEl = previewContainer.querySelector('.lib-report-preview-table') || previewContainer;
        doCapture(tableEl);
    }
}

/* ── REFRESH STUDENT CARD ──
   (Grading removed. Report-count/eval pills and the "Evaluated" strip
   have been removed from the student summary card entirely; this now
   only refreshes the card's has-reports/no-reports state and the
   Evaluate / View Training Plan button's label + state.) ── */
function refreshStudentCard(studentId) {
    fetch('company_reports.php?student_library=1&student_id=' + studentId)
        .then(r => r.json())
        .then(data => {
            if (data.error) return;
            const reports  = data.reports || [];
            const total    = reports.length;
            const pe       = data.performance || {};
            const evalDone = !!pe.eval_submitted_at;

            if (data.school_representative !== undefined) _currentSchoolRep = data.school_representative || '';
            if (data.ojt_start_date !== undefined) _currentOjtStartDate = data.ojt_start_date || '';
            if (data.accom_info !== undefined) _currentAccomInfo = data.accom_info || {};

            const card = document.getElementById('scard-' + studentId);
            if (!card) return;

            card.classList.remove('has-reports', 'no-reports');
            card.classList.add(total > 0 ? 'has-reports' : 'no-reports');

            const evalBtn = document.getElementById('sc-eval-btn-' + studentId);
            if (evalBtn) {
                evalBtn.classList.toggle('eval-done', evalDone);
                evalBtn.setAttribute('data-tooltip', evalDone ? 'View Training Plan' : 'Evaluate');
                evalBtn.innerHTML = evalDone
                    ? '<i class="fas fa-check"></i>'
                    : '<i class="fas fa-star"></i>';
            }
        })
        .catch(() => {});
}

/* ══════════════════════════════════════════════════════════════
   OJT/INTERNSHIP TRAINING PLAN (NEUST-OJT-F013)
   Mirrors the category/item structure of Eval_form.php's
   buildEvalFormHTML() exactly, so that rating keys line up between
   client (JS) and server (PHP) — 'gen_<category-slug>_<index>' for
   General Competencies, 'spec_<index>' for Specific Work Competencies.
   ══════════════════════════════════════════════════════════════ */
const RATING_SCALE = [
    { v: '1.0',  pct: '97–100', label: 'Excellent' },
    { v: '1.25', pct: '94–96',  label: 'Excellent' },
    { v: '1.5',  pct: '91–93',  label: 'Very Satisfactory' },
    { v: '1.75', pct: '88–90',  label: 'Very Satisfactory' },
    { v: '2.0',  pct: '85–87',  label: 'Very Satisfactory' },
    { v: '2.25', pct: '82–84',  label: 'Satisfactory' },
    { v: '2.5',  pct: '79–81',  label: 'Satisfactory' },
    { v: '2.75', pct: '76–78',  label: 'Satisfactory' },
    { v: '3.0',  pct: '75',     label: 'Passed' },
    { v: '5.0',  pct: 'below 75', label: 'Failed' },
];

const GENERAL_COMPETENCIES = {
    'PUNCTUALITY': [
        'Demonstrate punctuality in reporting for work.',
        'Notify the employer with any shift misses.',
        'Perform tasks in an accurate and timely manner.',
        'Return from meals and/or breaks on time.',
    ],
    'DEPENDABILITY': [
        'Accept responsibility on the job.',
        'Assume responsibility for own decisions and actions.',
        'Demonstrate ethical practices (i.e., honesty and integrity).',
        'Demonstrate ability to set priorities.',
        'Follow rules and regulations.',
    ],
    'INITIATIVE': [
        'Perform assigned duties without continuous supervision and directions.',
        'See what needs to be done and do it.',
        'Follow through and get all work completed.',
    ],
    'APPEARANCE': [
        'Exhibit good grooming.',
        'Demonstrate appropriate dress/attire for the job.',
        'Demonstrate personal hygiene and cleanliness.',
    ],
    'ADAPTABILITY': [
        'Demonstrate the ability to catch on quickly.',
        'Change focus easily and without complaint.',
    ],
    'COMMUNICATION': [
        'Read and comprehend written information.',
        'Use correct grammar.',
        'Communicate effectively with supervisor and customers.',
        'Use job-related terminology.',
        'Listen attentively.',
        'Write legibly.',
        'Follow written directions.',
        'Follow oral directions.',
        'Ask questions so that assigned tasks can be completed.',
        'Locate information in order to accomplish a task.',
        'Assist in training new employees.',
        'Communicate effectively with employer/co-workers.',
    ],
    'SAFETY AND SECURITY': [
        'Comply with safety and health rules.',
        'Select correct tools and equipment.',
        'Utilize equipment correctly.',
        'Use appropriate action during emergencies.',
        'Maintain clean work area.',
        'Maintain orderly work area.',
    ],
};

const SPECIFIC_COMPETENCIES = [
    'States a desire to produce or sell a top or better quality product or service',
    'Does personal research on how to provide a product or service',
    "Seeks information or asks questions to clarify a client's or a supplier's need",
    'Uses information or business tools to improve efficiency',
    'Pitches in with workers or works in their place to get the job done',
    'Responds flexibly to deal with changing priorities',
    'Persists in pursuing goals despite obstacles and setbacks',
    'Creates common purpose with colleagues through shared vision and values',
    'Writing communication abilities',
    'Interpersonal communication abilities',
    'Expresses confidence in own ability to complete a task or meet a challenge',
    'Seeks opportunities to work on teams as a means to develop experience, and knowledge',
    'Carefully weighs the priority of things to be done',
    'Quickly and effectively solves customer problems',
    'Approaches a complex task or problem by breaking it down into its component parts and considering each part in detail',
];

function tpCategorySlug(category) {
    return category.toLowerCase().replace(/[^a-z0-9]+/gi, '_');
}

/* ══════════════════════════════════════════════════════════════
   SHARED: WAIT FOR IFRAME PAGINATION TO FINISH
   ------------------------------------------------------------
   Both the locked-preview iframe used by openEvalModal() (src
   print_eval=1, rendered via buildEVALFormHTML()/Eval_form.php)
   and the hidden iframe used for PDF generation load a document
   that paginates itself client-side into fixed-size .doc-paper
   "sheets" and marks itself ready by adding a `tp-ready` class to
   its `.doc-outer` wrapper (see the pagination script embedded at
   the bottom of Eval_form.php). Printing or rasterizing the iframe
   before that class appears would only capture whatever partial/
   pre-pagination DOM happens to exist at that instant, producing a
   Print/PDF result that doesn't match the actual multi-sheet
   document (and therefore doesn't match the saved blob either,
   since the saved blob IS that same generated document). This
   helper polls briefly for `.doc-outer.tp-ready` before invoking a
   callback, with a bounded number of attempts so a genuinely broken
   iframe still resolves (falls through) instead of hanging forever.
   ══════════════════════════════════════════════════════════════ */
function tpWaitForIframeReady(iframeDoc, callback, attemptsLeft) {
    attemptsLeft = (typeof attemptsLeft === 'number') ? attemptsLeft : 50; // ~5s at 100ms intervals
    var outer = iframeDoc ? iframeDoc.querySelector('.doc-outer') : null;
    if (!outer || outer.classList.contains('tp-ready') || attemptsLeft <= 0) {
        callback();
        return;
    }
    setTimeout(function() { tpWaitForIframeReady(iframeDoc, callback, attemptsLeft - 1); }, 100);
}

/* ══════════════════════════════════════════════════════════════
   PRINT-IN-PLACE HELPER
   ------------------------------------------------------------
   FIX (no new tab/window on Print): Several fallback paths used by
   evalPrintDoc() previously called `window.open(url, '_blank')` to
   load the print_eval=1 document in a brand-new browser tab/window
   before printing it — meaning the user was taken away from
   company_reports.php (or a popup blocker silently prevented
   printing altogether) any time the primary rasterized-canvas print
   path could not be used.

   Fix: this helper loads the given URL into the always-present,
   off-screen `#evalPrintHiddenFrame` iframe (declared once near the
   bottom of the page body) instead of opening a new tab/window, and
   triggers the browser's native print dialog against that hidden
   iframe's contentWindow once its own client-side pagination has
   finished (tpWaitForIframeReady). The user's active tab/page never
   changes — only the native print dialog appears, exactly as
   requested.
   ══════════════════════════════════════════════════════════════ */
function printUrlInPage(url) {
    var frame = document.getElementById('evalPrintHiddenFrame');
    if (!frame) {
        // Extremely defensive fallback in case the hidden frame is ever
        // missing from the DOM — still avoids leaving the print entirely
        // unactionable, though this path should never normally run.
        var w = window.open(url, '_blank');
        if (w) { w.onload = function() { w.focus(); w.print(); }; }
        return;
    }
    frame.onload = function() {
        try {
            var fdoc = frame.contentDocument || frame.contentWindow.document;
            tpWaitForIframeReady(fdoc, function() {
                try { frame.contentWindow.focus(); frame.contentWindow.print(); }
                catch (e) { /* no-op — user can retry Print */ }
            });
        } catch (e) { /* no-op */ }
    };
    frame.src = url;
}

/* ══════════════════════════════════════════════════════════════
   PRINT (Training Plan) — RASTER-MATCH TECHNIQUE
   ------------------------------------------------------------
   FIX: Print previously relied on the browser's native print engine
   (`iframe.contentWindow.print()`) to re-lay-out the iframe's live
   HTML/CSS via `@media print` rules. That is a SEPARATE rendering
   pass from the one the user actually sees in the on-screen locked
   preview (#evalBlobFrame) — different engines can resolve fixed-
   height flex layouts, fonts, and pagination math slightly
   differently between the two passes (this is the exact class of
   problem already documented and hardened against elsewhere in this
   file, e.g. the tp-ready pagination gate and the @media print
   .doc-outer flex fix). The result: what actually printed could
   drift from what the preview showed, i.e. it did not perfectly
   mirror the evaluation preview.

   Fix: reuse the EXACT same technique evalSavePDF() already uses to
   guarantee a perfect match — rasterize every `.doc-paper` sheet
   with html2canvas (a literal screenshot of the on-screen preview
   DOM, not a re-flowed re-layout) and feed those images into a
   dedicated print-only surface, one full A4 image per physical page.
   Because Print and Save-as-PDF now both start from the same
   rasterized capture of the same `.doc-paper` sheets, whatever the
   user sees in the preview is pixel-for-pixel identical to both what
   gets printed and what gets saved as a PDF — true "carbon copy"
   parity across all three output paths.

   FIX (this revision): the rasterized images are now printed through
   an always-present, off-screen hidden iframe (see
   openPrintImagesWindow() and printUrlInPage() above) instead of a
   new browser tab/window, so Print always keeps the user on the
   current page — see the "PRINT-IN-PLACE HELPER" note above.

   NEW (loading-screen separation): Print now shows its OWN dedicated
   progress overlay (#printGenOverlay / #printGenMsg — see
   evalRasterizeForPrint() below) instead of reusing the "Save as
   PDF" overlay (#pdfGenOverlay / #pdfGenMsg), so each button gets a
   visually distinct loading screen. evalSavePDF() further below is
   untouched and still uses #pdfGenOverlay.
   ══════════════════════════════════════════════════════════════ */
function evalPrintDoc() {
    if (!_currentStudentId) return;

    var printBtn = document.getElementById('evalPrintBtn');
    var statusEl = document.getElementById('evalPdfStatus');
    function setStatus(msg, cls) { if (statusEl) { statusEl.textContent = msg; statusEl.className = 'eval-pdf-status show ' + cls; } }
    function clearStatus() { setTimeout(function() { if (statusEl) { statusEl.className = 'eval-pdf-status'; statusEl.textContent = ''; } }, 3500); }
    function resetBtn() { if (printBtn) { printBtn.disabled = false; printBtn.innerHTML = '<i class="fas fa-print"></i> Print'; } }
    if (printBtn) { printBtn.disabled = true; printBtn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Preparing...'; }

    var iframe = document.getElementById('evalBlobFrame');

    var renderWhenReady = function(iframeDoc) {
        tpWaitForIframeReady(iframeDoc, function() {
            evalRasterizeForPrint(iframeDoc, resetBtn, setStatus, clearStatus);
        });
    };

    if (iframe) {
        var goRender = function() {
            try {
                var iframeDoc = iframe.contentDocument || iframe.contentWindow.document;
                if (!iframeDoc || !iframeDoc.body) throw new Error('Could not access preview document.');
                renderWhenReady(iframeDoc);
            } catch (e) {
                // Fallback: print the same document in place via the hidden
                // print iframe — never a new tab/window (see printUrlInPage()).
                printUrlInPage('company_reports.php?print_eval=1&student_id=' + _currentStudentId);
                resetBtn();
            }
        };
        if (iframe.contentDocument && iframe.contentDocument.readyState === 'complete') goRender();
        else iframe.onload = function() { goRender(); };
    } else {
        // No locked preview iframe present (evaluation not yet locked/
        // loaded) — fall back to printing print_eval=1 in place via the
        // hidden print iframe, staying on the current page.
        printUrlInPage('company_reports.php?print_eval=1&student_id=' + _currentStudentId);
        resetBtn();
    }
}

/* Rasterizes every `.doc-paper` sheet found inside `iframeDoc` — the
   identical technique used by evalSavePDF()'s per-sheet html2canvas
   capture — and hands the resulting images to openPrintImagesWindow()
   so the printed output is a pixel-for-pixel match of the preview.

   NEW: uses the dedicated #printGenOverlay/#printGenMsg elements
   (instead of the "Save as PDF" flow's #pdfGenOverlay/#pdfGenMsg) so
   Print shows its own distinct loading screen — different icon,
   accent color, and default copy — while everything else about the
   rendering/rasterization logic itself is unchanged. */
function evalRasterizeForPrint(iframeDoc, resetBtn, setStatus, clearStatus) {
    var overlay = document.getElementById('printGenOverlay');
    var msgEl   = document.getElementById('printGenMsg');
    if (overlay) overlay.classList.add('show');
    if (msgEl) msgEl.textContent = 'Rendering document...';
    setStatus('Rendering document for printing...', 'generating');

    var paperEls = iframeDoc.querySelectorAll('.doc-paper');
    if (!paperEls || paperEls.length === 0) {
        var fallbackEl = iframeDoc.querySelector('.eval-doc-paper');
        paperEls = fallbackEl ? [fallbackEl] : [];
    } else {
        paperEls = Array.prototype.slice.call(paperEls);
    }

    if (paperEls.length === 0) {
        setStatus('Could not find document pages to print.', 'error'); clearStatus();
        if (overlay) overlay.classList.remove('show');
        if (typeof resetBtn === 'function') resetBtn();
        return;
    }

    var totalSheets = paperEls.length;
    var images = [];

    function renderSheet(idx) {
        if (idx >= totalSheets) {
            openPrintImagesWindow(images);
            setStatus('Print preview ready.', 'success'); clearStatus();
            if (overlay) overlay.classList.remove('show');
            if (typeof resetBtn === 'function') resetBtn();
            return;
        }

        if (msgEl) msgEl.textContent = 'Rendering page ' + (idx + 1) + ' of ' + totalSheets + '...';
        setStatus('Rendering page ' + (idx + 1) + ' of ' + totalSheets + ' for printing...', 'generating');

        var paperEl = paperEls[idx];
        var pr = paperEl.getBoundingClientRect();
        var pw = Math.round(pr.width)  || 794;
        var ph = Math.round(pr.height) || 1123;

        html2canvas(paperEl, {
            scale: 2, useCORS: true, allowTaint: true, backgroundColor: '#ffffff', logging: false,
            width: pw, height: ph, scrollX: 0, scrollY: 0,
            onclone: function(clonedDoc) {
                clonedDoc.body.style.overflow = 'visible';
                clonedDoc.documentElement.style.overflow = 'visible';
                var clonedPapers = clonedDoc.querySelectorAll('.doc-paper');
                for (var i = 0; i < clonedPapers.length; i++) {
                    clonedPapers[i].style.overflow = 'visible';
                    clonedPapers[i].style.pageBreakInside = 'avoid';
                }
            }
        }).then(function(canvas) {
            images.push(canvas.toDataURL('image/png', 1.0));
            renderSheet(idx + 1);
        }).catch(function(err) {
            setStatus('Render error: ' + err.message, 'error'); clearStatus();
            if (overlay) overlay.classList.remove('show');
            if (typeof resetBtn === 'function') resetBtn();
        });
    }

    renderSheet(0);
}

/* ══════════════════════════════════════════════════════════════
   PRINT SURFACE FOR RASTERIZED PAGES
   ------------------------------------------------------------
   FIX #1 (excessive white space on both sides of every printed
   page): the previous implementation displayed each page image at
   `width:210mm; height:297mm;` with `object-fit:contain` inside a
   flex-centered `.print-page` box. `object-fit:contain` only ever
   shrinks an image to fit fully inside its box while preserving its
   original aspect ratio — so if the rendering pipeline (the print
   engine's own DPI/rounding behavior, a fractional canvas size from
   html2canvas, etc.) resolved the image's intrinsic aspect ratio as
   even slightly different from the exact 210:297 target, `contain`
   would shrink the whole image to fit, leaving visible letterboxed
   gutters — most noticeably as vertical white bands down the left
   and right edges of the page, exactly matching the reported
   symptom.

   Fix: the image is now displayed with `width:100%; height:100%;
   display:block;` inside a `.print-page` that is set to the EXACT
   physical page size (210mm × 297mm) with zero margin/padding
   anywhere in the print document. Stretching to 100%/100% (rather
   than constraining by aspect ratio) guarantees the image always
   fills the entire physical page edge-to-edge, regardless of any
   sub-pixel rounding differences between how the source canvas was
   captured and how the print engine resolves millimeter-to-pixel
   conversion — any negligible stretch is visually imperceptible
   given how close the source `.doc-paper` sheets (794×1123px) already
   are to the true A4 ratio.

   FIX #2 (no new tab/window on Print): images are now printed via
   the same always-present, off-screen hidden iframe used by
   printUrlInPage() above (`#evalPrintHiddenFrame`) instead of
   `window.open()`. The browser's native print dialog is triggered
   against that hidden iframe's contentWindow, so the user's current
   tab/page never changes and no pop-up blocker can interfere.
   ══════════════════════════════════════════════════════════════ */
function openPrintImagesWindow(images) {
    if (!images || images.length === 0) {
        alert('Nothing to print — no pages were rendered.');
        return;
    }

    var frame = document.getElementById('evalPrintHiddenFrame');
    if (!frame) {
        alert('Print preparation failed — the print frame is unavailable.');
        return;
    }

    var pagesHtml = images.map(function(src) {
        return '<div class="print-page"><img src="' + src + '" alt="Training Plan page"></div>';
    }).join('');

    var html = '<!DOCTYPE html><html><head><meta charset="utf-8"><title>Print Training Plan</title><style>'
        + '@page { size: 210mm 297mm; margin: 0; }'
        + '* { box-sizing: border-box; }'
        + 'html, body { margin: 0 !important; padding: 0 !important; background: #fff; width: 210mm; }'
        + '.print-page { width: 210mm; height: 297mm; margin: 0; padding: 0; page-break-after: always; break-after: page; overflow: hidden; display: block; }'
        + '.print-page:last-child { page-break-after: auto; break-after: auto; }'
        + '.print-page img { display: block; width: 210mm; height: 297mm; margin: 0; padding: 0; border: 0; }'
        + '@media print { html, body { margin: 0 !important; padding: 0 !important; } }'
        + '</style></head><body>' + pagesHtml + '</body></html>';

    frame.onload = function() {
        try {
            frame.contentWindow.focus();
            // Small delay so every page image has finished decoding/painting
            // inside the iframe before the print dialog captures it.
            setTimeout(function() {
                try { frame.contentWindow.print(); } catch (e) { /* no-op */ }
            }, 150);
        } catch (e) { /* no-op */ }
    };

    var fdoc = frame.contentDocument || frame.contentWindow.document;
    fdoc.open();
    fdoc.write(html);
    fdoc.close();
}

/* ══════════════════════════════════════════════════════════════
   SAVE AS PDF (locked Training Plan)
   ------------------------------------------------------------
   FIX: Previously this only ever rendered the FIRST `.doc-paper`
   sheet (`iframeDoc.querySelector('.doc-paper')` returns just one
   match), then sliced that single sheet's image into ~A4-sized
   chunks by raw pixel height. Once the printed Training Plan spans
   more than one physical page (which it does as soon as the
   Student Info + Instructions + both competency tables + signatures
   no longer fit on a single 794×1123 sheet — see the pagination
   engine in Eval_form.php), every sheet after the first was silently
   dropped from the downloaded PDF, so it was NOT a carbon copy of
   the filled-up evaluation form the company actually sees in the
   preview (and that is stored as the saved HTML blob).

   Fix: capture EVERY `.doc-paper` sheet (`querySelectorAll`, not
   `querySelector`) and add each one as its own full A4 (210×297mm)
   PDF page, in document order. Because each `.doc-paper` is already
   laid out at a fixed 794×1123px (A4 @96dpi) size by the pagination
   engine, each captured sheet maps 1:1 onto one PDF page — no more
   guessing page boundaries by slicing pixel heights. This also now
   waits for the iframe's pagination to fully finish (tp-ready)
   before capturing anything, so the PDF always matches the same
   complete, multi-sheet layout shown in the locked preview and
   already persisted as the eval_pdf_blob.

   NOTE (loading-screen separation): this function is unchanged and
   continues to use the original "Save as PDF" overlay/status
   elements (#pdfGenOverlay / #pdfGenMsg) — see evalRasterizeForPrint()
   above for the new, separate Print overlay (#printGenOverlay /
   #printGenMsg).
   ══════════════════════════════════════════════════════════════ */
function evalSavePDF() {
    if (!_currentStudentId) return;
    var savePDFBtn = document.getElementById('evalSavePDFBtn');
    if (savePDFBtn) { savePDFBtn.disabled = true; savePDFBtn.textContent = 'Generating...'; }
    var statusEl = document.getElementById('evalPdfStatus');
    function setStatus(msg, cls) { statusEl.textContent = msg; statusEl.className = 'eval-pdf-status show ' + cls; }
    function clearStatus() { setTimeout(function() { statusEl.className = 'eval-pdf-status'; statusEl.textContent = ''; }, 3500); }
    function resetBtn() { if (savePDFBtn) { savePDFBtn.disabled = false; savePDFBtn.textContent = 'Save as PDF'; } }

    setStatus('Loading evaluation form...', 'generating');
    var overlay = document.getElementById('pdfGenOverlay');
    var msgEl   = document.getElementById('pdfGenMsg');
    if (overlay) overlay.classList.add('show');
    if (msgEl)   msgEl.textContent = 'Loading evaluation form...';

    var hiddenFrame = document.getElementById('evalPdfHiddenFrame');
    hiddenFrame.onload = null; hiddenFrame.onerror = null;
    hiddenFrame.onerror = function() {
        setStatus('Failed to load evaluation form.', 'error'); clearStatus();
        if (overlay) overlay.classList.remove('show'); resetBtn();
    };

    function finishWithError(msg) {
        setStatus(msg, 'error');
        if (overlay) overlay.classList.remove('show');
        resetBtn();
        hiddenFrame.style.width = '900px'; hiddenFrame.style.height = '1200px';
    }

    hiddenFrame.onload = function() {
        try {
            var iframeDoc = hiddenFrame.contentDocument || hiddenFrame.contentWindow.document;
            if (!iframeDoc || !iframeDoc.body) throw new Error('Could not access iframe document.');
            if (msgEl) msgEl.textContent = 'Rendering document...';
            setStatus('Rendering document...', 'generating');

            tpWaitForIframeReady(iframeDoc, function() {
                /* Capture EVERY sheet — a full, page-for-page carbon
                   copy of the paginated Training Plan — instead of
                   only the first. */
                var paperEls = iframeDoc.querySelectorAll('.doc-paper');
                if (!paperEls || paperEls.length === 0) {
                    var fallbackEl = iframeDoc.querySelector('.eval-doc-paper');
                    paperEls = fallbackEl ? [fallbackEl] : [iframeDoc.body];
                } else {
                    paperEls = Array.prototype.slice.call(paperEls);
                }

                var totalSheets = paperEls.length;
                if (totalSheets === 0) { finishWithError('PDF build error: no pages were generated.'); return; }

                var rect0  = paperEls[0].getBoundingClientRect();
                var sheetW = Math.round(rect0.width)  || paperEls[0].scrollWidth  || 794;
                var sheetH = Math.round(rect0.height) || paperEls[0].scrollHeight || 1123;

                hiddenFrame.style.width  = Math.max(920, sheetW + 40) + 'px';
                hiddenFrame.style.height = Math.max(sheetH + 60, 1200) + 'px';

                var jsPDF = window.jspdf ? window.jspdf.jsPDF : window.jsPDF;
                if (!jsPDF) { finishWithError('PDF build error: jsPDF library not loaded.'); return; }

                var PAGE_W_MM = 210, PAGE_H_MM = 297;
                var pdf = null;

                function renderSheet(idx) {
                    if (idx >= totalSheets) {
                        if (!pdf) { finishWithError('PDF build error: no pages were generated.'); return; }
                        setStatus('PDF downloaded successfully!', 'success'); clearStatus();
                        var safeName = (_currentStudentName || 'Student').replace(/[\/\\:*?"<>|]/g, '').trim();
                        pdf.save(safeName + '_TrainingPlan.pdf');
                        if (overlay) overlay.classList.remove('show'); resetBtn();
                        hiddenFrame.style.width = '900px'; hiddenFrame.style.height = '1200px';
                        return;
                    }

                    if (msgEl) msgEl.textContent = 'Rendering page ' + (idx + 1) + ' of ' + totalSheets + '...';
                    setStatus('Building PDF (page ' + (idx + 1) + ' of ' + totalSheets + ')...', 'saving');

                    var paperEl = paperEls[idx];
                    var pr = paperEl.getBoundingClientRect();
                    var pw = Math.round(pr.width)  || sheetW;
                    var ph = Math.round(pr.height) || sheetH;

                    html2canvas(paperEl, {
                        scale: 2, useCORS: true, allowTaint: true, backgroundColor: '#ffffff', logging: false,
                        width: pw, height: ph, scrollX: 0, scrollY: 0,
                        windowWidth: Math.max(920, sheetW + 40), windowHeight: Math.max(sheetH + 60, 1200),
                        onclone: function(clonedDoc) {
                            clonedDoc.body.style.overflow = 'visible';
                            clonedDoc.documentElement.style.overflow = 'visible';
                            var clonedPapers = clonedDoc.querySelectorAll('.doc-paper');
                            for (var i = 0; i < clonedPapers.length; i++) {
                                clonedPapers[i].style.overflow = 'visible';
                                clonedPapers[i].style.pageBreakInside = 'avoid';
                            }
                        }
                    }).then(function(canvas) {
                        var imgData = canvas.toDataURL('image/png', 1.0);
                        if (pdf === null) {
                            pdf = new jsPDF({ orientation: 'portrait', unit: 'mm', format: [PAGE_W_MM, PAGE_H_MM], compress: true });
                        } else {
                            pdf.addPage([PAGE_W_MM, PAGE_H_MM]);
                        }
                        /* Each .doc-paper is already exactly one fixed
                           A4-proportioned sheet, so the captured image is
                           stretched to fill the full page — no pixel-height
                           slicing/guessing needed, unlike the old
                           single-sheet implementation. */
                        pdf.addImage(imgData, 'PNG', 0, 0, PAGE_W_MM, PAGE_H_MM, '', 'FAST');
                        renderSheet(idx + 1);
                    }).catch(function(canvasErr) {
                        finishWithError('Render error: ' + canvasErr.message);
                    });
                }

                renderSheet(0);
            });
        } catch(e) {
            finishWithError('Error: ' + e.message);
        }
    };
    hiddenFrame.src = 'company_reports.php?print_eval=1&student_id=' + _currentStudentId;
}

/* ══════════════════════════════════════════════════════════════
   PERSIST THE LOCKED TRAINING PLAN AS THE CANONICAL "CARBON COPY"
   ------------------------------------------------------------
   FIX (this revision): Print and Save-as-PDF must always act on
   the exact same document that ends up permanently stored as
   eval_pdf_blob — never on the editable, in-progress form that was
   just submitted. Previously, submitEvaluation()'s success handler
   revealed the Print / Save-as-PDF toolbar buttons IMMEDIATELY on
   a successful save_eval=1 response, while the canvas was still
   showing the OLD editable renderEvalFormBody() markup (selects/
   inputs) — the swap to the #evalBlobFrame iframe (which is what
   print_eval=1, evalPrintDoc(), and evalSavePDF() all actually
   read from) only happens once this function finishes. If the
   company rep clicked Print/Save-as-PDF inside that window, they
   could get a mismatched render, and it wasn't guaranteed to line
   up with the eval_pdf_blob that gets written to the database
   moments later.

   Fix: the Print / Save-as-PDF buttons now stay hidden (see
   submitEvaluation() below, which no longer reveals them itself)
   until THIS function has swapped the canvas over to the
   #evalBlobFrame iframe pointed at print_eval=1 — the single
   source of truth that also backs the persisted eval_pdf_blob.
   Only once that iframe is in place (in showBlobInCanvas(), called
   on both the success and failure paths below, so the buttons are
   never left permanently hidden even if the blob save request
   itself fails) are Print/Save-as-PDF revealed — guaranteeing both
   actions can only ever operate on the exact same "carbon copy" of
   the filled-up evaluation that is shown in the locked preview and
   stored in the database.
   ══════════════════════════════════════════════════════════════ */
function triggerHtmlBlobSave(studentId) {
    var statusEl = document.getElementById('evalPdfStatus');
    var canvas   = document.getElementById('evalDocCanvas');
    var printBtn = document.getElementById('evalPrintBtn');
    var pdfBtn   = document.getElementById('evalSavePDFBtn');

    // Keep Print / Save-as-PDF hidden until the canvas is showing the
    // exact document (print_eval=1 iframe) that Print/Save-as-PDF will
    // themselves read from — see showBlobInCanvas() below.
    if (printBtn) printBtn.style.display = 'none';
    if (pdfBtn)   pdfBtn.style.display   = 'none';

    function setStatus(msg, cls) { statusEl.textContent = msg; statusEl.className = 'eval-pdf-status show ' + cls; }
    function clearStatus() { setTimeout(function() { statusEl.className = 'eval-pdf-status'; statusEl.textContent = ''; }, 4000); }
    function showBlobInCanvas() {
        var c = document.getElementById('evalDocCanvas');
        if (c) c.innerHTML = '<iframe class="eval-blob-frame" id="evalBlobFrame" src="company_reports.php?print_eval=1&student_id=' + studentId + '" title="Training Plan"></iframe>';

        // The canvas now points at the exact same print_eval=1 document
        // that backs the persisted eval_pdf_blob (or, in the rare case
        // the save above failed, the same session-based regeneration
        // print_eval=1 would fall back to) — safe to reveal Print/Save
        // as PDF now, since both act on this very iframe/URL.
        if (printBtn) printBtn.style.display = '';
        if (pdfBtn)   pdfBtn.style.display   = '';
    }

    setStatus('Saving training plan to database...', 'saving');
    if (canvas) {
        canvas.innerHTML = '<div style="display:flex;align-items:center;justify-content:center;min-height:400px;color:#fff;font-family:\'DM Sans\',sans-serif;gap:12px;"><div class="spinner" style="border-top-color:#fff;"></div><span>Saving training plan...</span></div>';
    }

    fetch('company_reports.php?print_eval=1&student_id=' + studentId, { credentials: 'same-origin' })
        .then(function(r) { if (!r.ok) throw new Error('HTTP ' + r.status); return r.text(); })
        .then(function(htmlText) {
            if (!htmlText || htmlText.trim().length < 100) throw new Error('Fetched HTML is empty or too short.');
            var head = htmlText.trimStart().substring(0, 200).toLowerCase();
            if (head.indexOf('<!doctype') === -1 && head.indexOf('<html') === -1) throw new Error('Fetched content does not appear to be HTML.');
            setStatus('Uploading to database...', 'saving');
            var fd = new FormData();
            fd.append('student_id', studentId);
            fd.append('html_blob', htmlText);
            return fetch('company_reports.php?save_eval_pdf=1', { method: 'POST', credentials: 'same-origin', body: fd });
        })
        .then(function(r) { if (!r.ok) throw new Error('HTTP ' + r.status); return r.json(); })
        .then(function(data) {
            if (data.success) { setStatus('Training plan saved successfully.', 'success'); clearStatus(); }
            else { setStatus('Could not save: ' + (data.message || 'Unknown error.'), 'error'); }
            showBlobInCanvas();
        })
        .catch(function(err) {
            setStatus('Save error: ' + err.message, 'error');
            showBlobInCanvas();
        });
}

/* ── EVAL MODAL ── */
function openEvalModal() {
    if (!_currentStudentId) return;
    var pe       = _currentEvalData || {};
    var isLocked = !!pe.eval_submitted_at;

    document.getElementById('evalToolbarTitle').textContent = (_currentStudentName || 'Student') + ' - OJT/Internship Training Plan';
    var statusEl = document.getElementById('evalPdfStatus');
    statusEl.className = 'eval-pdf-status'; statusEl.textContent = '';

    var canvas = document.getElementById('evalDocCanvas');

    if (isLocked) {
        document.getElementById('evalPrintBtn').style.display   = '';
        document.getElementById('evalSavePDFBtn').style.display = '';
        canvas.innerHTML = '<iframe class="eval-blob-frame" id="evalBlobFrame" src="company_reports.php?print_eval=1&student_id=' + _currentStudentId + '" title="Training Plan"></iframe>';
    } else {
        document.getElementById('evalPrintBtn').style.display   = 'none';
        document.getElementById('evalSavePDFBtn').style.display = 'none';
        renderEvalFormBody();
    }

    document.getElementById('evalOverlay').scrollTop = 0;
    document.getElementById('evalOverlay').classList.add('open');
    document.body.style.overflow = 'hidden';
}

/* ══════════════════════════════════════════════════════════════
   A4 PAGINATION ENGINE
   ------------------------------------------------------------
   v3 (this revision — fixes footer not sitting at the true bottom
   of a sheet, page 1 occasionally clipping a trailing row/section,
   and the final sheet's content not being vertically centered):

   ROOT CAUSE (footer / centering): earlier revisions pinned the
   footer to the bottom of each sheet with CSS alone — a flex
   column (`.doc-paper { display:flex; flex-direction:column }`)
   plus `.tp-footer-band { margin-top:auto }`. That depends on the
   BROWSER distributing the sheet's fixed 1123px height between
   `.tp-form-body` (flex:1) and the footer at render time. It is
   reliable on screen, but the exact same DOM re-rendered through a
   different pipeline (browser print, or html2canvas rasterization
   for "Save as PDF") does not always resolve that flex math
   identically — small differences in how each engine paints a
   fixed-height flex column with a hidden-overflow child are enough
   to leave the footer sitting just under the content instead of at
   the physical bottom of the page, and to leave the last sheet's
   centered block sitting high instead of centered.

   FIX: stop asking the browser to *distribute* space at render time
   and instead *compute it ourselves*, once, right after a sheet's
   real content has been measured. After every unit has been packed
   onto a sheet (so `.tp-form-body`'s natural content height is
   final and won't change again), each sheet's body is given an
   EXPLICIT pixel height:

       bodyHeight = PAGE_H - letterheadHeight - titlebandHeight - footerHeight

   instead of `flex:1`. Because `.tp-form-body`'s height is now a
   plain, explicit number (not something flexbox has to solve for),
   the footer that follows it in normal document flow always lands
   in exactly the same place — the true bottom of the fixed-height
   sheet — in every rendering context (on-screen preview, browser
   print, and html2canvas), and `justify-content:center` on that
   same explicitly-sized box reliably centers the last sheet's
   Overall Rating + signatures block within real left-over space
   instead of within a flex-computed box that could vary by engine.

   ROOT CAUSE ("page 1 not intact" — content clipped near a page
   boundary): the overflow test compared `scrollHeight` to
   `clientHeight` with only a 1px safety margin. Sub-pixel rounding
   differences between the offscreen measurement pass and the final
   paint (different engines can round fractional pixel heights up or
   down independently) could let a unit through that "just barely"
   fit during measurement but then clipped a hair of its own bottom
   border/padding once truly painted. FIX: the safety margin is
   widened to a few pixels so a boundary case is pushed to the next
   sheet instead of silently squeezing (and risking clipping) onto
   the current one.

   Everything else about this engine — building each page for real
   (not predicting), packing table rows one at a time, starting a
   fresh `<table>`/`<colgroup>` per sheet, and the
   hadContentBefore/hadRowsBefore guard that stops a borderline
   sub-pixel reading on a brand-new empty sheet from ever bouncing
   its very first unit onto an unnecessary extra page — is
   unchanged from the previous revision.
   ══════════════════════════════════════════════════════════════ */
function tpPaginateAndRender(canvas, blocks) {
    // Flatten the ordered list of content blocks into individual
    // "units" — atomic elements are kept whole, while table blocks are
    // expanded into a table-start marker, one unit per data row, and a
    // trailing total-row unit — mirroring exactly how
    // EVAL_form_builder.php's paginateTrainingPlan() flattens its own
    // .form-body children before packing them onto real sheets.
    var units = [];
    blocks.forEach(function(block) {
        if (block.table) {
            units.push({ type: 'table-start' });
            block.rows.forEach(function(rowEl) {
                units.push({ type: 'row', el: rowEl });
            });
            units.push({ type: 'total-row', totalLabel: block.totalLabel, totalId: block.totalId });
        } else {
            units.push({ type: 'atomic', el: block.el });
        }
    });

    var PAGE_H = 1123;

    // Real, attached-but-invisible staging area: visibility:hidden (not
    // display:none) keeps normal layout/measurement working exactly as
    // it would on screen, while nothing is actually painted.
    var stage = document.createElement('div');
    stage.style.cssText = 'position:fixed;top:0;left:-10000px;visibility:hidden;pointer-events:none;';
    document.body.appendChild(stage);

    function newSheet() {
        var paper = document.createElement('div');
        paper.className = 'doc-paper';
        var letterhead = tpBuildHeaderTemplate();
        paper.appendChild(letterhead);

        var body = document.createElement('div');
        body.className = 'tp-form-body';
        paper.appendChild(body);

        var footer = tpBuildFooterTemplate('');
        paper.appendChild(footer);

        stage.appendChild(paper);
        return { paper: paper, header: letterhead, body: body, footer: footer, tbody: null };
    }

    // True once the sheet's real content height (scrollHeight, which
    // reflects everything appended so far, clipped or not) exceeds the
    // fixed height the flex layout actually allocates it (clientHeight,
    // which stays constant regardless of content because of
    // `.tp-form-body { overflow:hidden; }`). This is a direct
    // measurement of the real, rendered page — not an estimate — so it
    // can't drift the way summing isolated per-row measurements can.
    // A few pixels of buffer are required (not just 1px) so a
    // borderline-fitting unit is pushed to the next sheet instead of
    // being accepted here and then clipped by a sub-pixel rounding
    // difference once the sheet is actually painted/printed/rasterized.
    var OVERFLOW_BUFFER_PX = 3;
    function overflows(sheet) {
        return sheet.body.scrollHeight > sheet.body.clientHeight + OVERFLOW_BUFFER_PX;
    }

    var sheets = [ newSheet() ];
    var cur = sheets[0];

    units.forEach(function(u) {
        if (u.type === 'atomic') {
            var hadContentBefore = cur.body.children.length > 0;
            cur.body.appendChild(u.el);
            if (hadContentBefore && overflows(cur)) {
                cur.body.removeChild(u.el);
                cur = newSheet();
                sheets.push(cur);
                cur.body.appendChild(u.el);
                // If this atomic block is taller than an entire empty
                // page on its own (very unlikely for this form's
                // content), it simply stays on its own sheet rather than
                // being split further or silently dropped.
            }
        } else if (u.type === 'table-start') {
            var t = tpEmptyCompTable();
            cur.tbody = t.tbody;
            cur.body.appendChild(t.table);
        } else if (u.type === 'row') {
            if (!cur.tbody) {
                var t2 = tpEmptyCompTable();
                cur.tbody = t2.tbody;
                cur.body.appendChild(t2.table);
            }
            var hadRowsBefore = cur.tbody.children.length > 0;
            cur.tbody.appendChild(u.el);
            if (hadRowsBefore && overflows(cur)) {
                cur.tbody.removeChild(u.el);
                cur = newSheet();
                sheets.push(cur);
                var t3 = tpEmptyCompTable();
                cur.tbody = t3.tbody;
                cur.body.appendChild(t3.table);
                cur.tbody.appendChild(u.el);
            }
        } else if (u.type === 'total-row') {
            if (!cur.tbody) {
                var t4 = tpEmptyCompTable();
                cur.tbody = t4.tbody;
                cur.body.appendChild(t4.table);
            }
            var totalRow = document.createElement('tr');
            totalRow.className = 'tp-total-tr';
            totalRow.innerHTML = '<td class="tp-total-td">' + u.totalLabel + '</td>'
                + '<td class="tp-total-val" colspan="2"><span class="tp-total-readout" id="' + u.totalId + '">&mdash;</span></td>';
            var hadRowsBeforeTotal = cur.tbody.children.length > 0;
            cur.tbody.appendChild(totalRow);
            if (hadRowsBeforeTotal && overflows(cur)) {
                cur.tbody.removeChild(totalRow);
                cur = newSheet();
                sheets.push(cur);
                var t5 = tpEmptyCompTable();
                cur.tbody = t5.tbody;
                cur.body.appendChild(t5.table);
                cur.tbody.appendChild(totalRow);
            }
        }
    });

    // ── FINALIZE ────────────────────────────────────────────────────
    // Now that every sheet's real content is final (no more units will
    // be added or removed), give each `.tp-form-body` an EXPLICIT pixel
    // height computed from the sheet's own real letterhead/footer
    // heights, instead of relying on `flex:1` to divide up the leftover
    // space at paint time. This makes the footer's position (and, on
    // the last sheet, the centered block's position) a fixed, computed
    // number rather than something that can be resolved slightly
    // differently by the screen renderer, the browser's print engine,
    // and html2canvas's rasterizer.
    var totalPages = sheets.length;
    sheets.forEach(function(sheet, idx) {
        var footerSpans = sheet.footer.querySelectorAll('span');
        if (footerSpans.length >= 2) {
            footerSpans[1].textContent = 'Rev. 00 (03.04.19)' + ' \u00b7 Page ' + (idx + 1) + ' of ' + totalPages;
        }

        var headerH = Math.ceil(sheet.header.getBoundingClientRect().height);
        var footerH = Math.ceil(sheet.footer.getBoundingClientRect().height);
        var bodyH   = PAGE_H - headerH - footerH;
        if (bodyH < 0) bodyH = 0;

        sheet.body.style.flex = 'none';
        sheet.body.style.height = bodyH + 'px';
        sheet.body.style.minHeight = bodyH + 'px';
        sheet.body.style.maxHeight = bodyH + 'px';

        if (idx === totalPages - 1) {
            sheet.body.style.justifyContent = 'center';
        }
    });

    canvas.innerHTML = '';
    var outer = document.createElement('div');
    outer.className = 'doc-outer';
    sheets.forEach(function(sheet) {
        outer.appendChild(sheet.paper); // appendChild reparents out of the hidden stage automatically
    });
    canvas.appendChild(outer);
    document.body.removeChild(stage);

    /* Submit control lives outside the printable/paginated sheets */
    var submitBar = document.createElement('div');
    submitBar.className = 'eval-submit-bar';
    submitBar.id = 'evalSubmitBar';
    submitBar.style.maxWidth = '794px';
    submitBar.style.margin = '0 auto';
    submitBar.innerHTML = '<div class="eval-submit-bar-note">All ratings are final once submitted.</div>'
        + '<button class="eval-submit-btn" id="evalSubmitBtn" onclick="confirmEvalSubmit()">Submit Training Plan</button>';
    canvas.appendChild(submitBar);
}

/* ── Student–Trainee Information field/grid builders (two-column form grid) ── */
function tpFieldBlock(labelText, fieldHtml, opts) {
    opts = opts || {};
    var cls = 'tp-form-field' + (opts.full ? ' full' : '');
    return '<div class="' + cls + '"><label>' + labelText + '</label>' + fieldHtml + '</div>';
}
function tpSectionLabel(text) {
    return '<div class="tp-form-section-label">' + text + '</div>';
}
/* Builds a full-width row (spans both grid columns) that lays two or
   more fields out side by side, horizontally aligned on one line.
   Each entry: { label, html, flex } — flex defaults to 1 (equal width);
   pass a larger flex (e.g. 2) to make a field wider than its siblings
   (used to make "Name" wider than "Age"/"Sex"). */
function tpFieldRow(fields) {
    var inner = fields.map(function(f) {
        var flexStyle = 'flex:' + (f.flex || 1) + ';min-width:0;';
        return '<div class="tp-form-field" style="' + flexStyle + '"><label>' + f.label + '</label>' + f.html + '</div>';
    }).join('');
    return '<div class="tp-form-field-row">' + inner + '</div>';
}
function tpInput(id, type) {
    return '<input type="' + (type || 'text') + '" class="tp-form-input" id="' + id + '" autocomplete="off">';
}
/* Like tpInput(), but pre-fills a starting value while keeping the
   field fully editable — used to pre-fill Age / Home Address / Home
   telephone no. / Guardian name / Guardian telephone no. from the data
   the student already submitted on AccomForm.php (via _currentAccomInfo),
   and now also HTE Telephone no. / Address from the company's own
   record on file (via _COMPANY_TEL / _COMPANY_ADDRESS) — without
   turning the field readonly (the company rep can still correct it
   before submitting the Training Plan).
   NOTE: kept for backward compatibility / potential future use, but is
   no longer called by renderEvalFormBody() for any pre-filled field —
   all fetched/pre-filled fields now use tpReadonlyField() instead so
   they render uneditable, matching Name/Program/OJT coordinator/HTE
   name/Date OJT started. */
function tpInputVal(id, value, type) {
    var v = (value === null || value === undefined) ? '' : String(value);
    return '<input type="' + (type || 'text') + '" class="tp-form-input" id="' + id + '" autocomplete="off" value="' + escHtml(v) + '">';
}
function tpReadonlyField(id, value) {
    return '<input type="text" class="tp-form-input" id="' + id + '" value="' + escHtml(value) + '" readonly tabindex="-1">';
}
function tpSelectSex(id) {
    return '<select class="tp-form-input" id="' + id + '"><option value="">&mdash;</option><option value="Male">Male</option><option value="Female">Female</option></select>';
}
/* Like tpSelectSex(), but pre-selects the option matching the
   student's already-submitted Sex value (if any) while remaining a
   normal, editable <select>.
   NOTE: kept for backward compatibility / potential future use, but is
   no longer called by renderEvalFormBody() — Sex is now rendered as a
   read-only field via tpReadonlyField() instead, consistent with the
   rest of the pre-filled fields. */
function tpSelectSexVal(id, value) {
    var v = value || '';
    function opt(val, label) {
        return '<option value="' + val + '"' + (v === val ? ' selected' : '') + '>' + label + '</option>';
    }
    return '<select class="tp-form-input" id="' + id + '"><option value=""' + (v === '' ? ' selected' : '') + '>&mdash;</option>'
        + opt('Male', 'Male') + opt('Female', 'Female') + '</select>';
}

/* Renders the fully editable OJT/Internship Training Plan (bio-data +
   General/Specific competency checklist) inside the letterhead doc
   container, matching the look of Eval_form.php's static print view,
   automatically flowing onto additional A4 sheets when content
   exceeds one page (see tpPaginateAndRender).

   The Student-Trainee Information section is a two-column, label-
   above-field form grid, split into three separate sub-section blocks
   (Personal / School / HTE) — each its own `.tp-form-grid` pushed as
   its own atomic pagination block — so that the pagination engine can
   flow a sub-section onto a fresh sheet whenever it doesn't fit on the
   current page, instead of it being silently clipped by the fixed-
   height .doc-paper.

   Layout adjustments within each sub-section:
     • Personal info : Name / Age / Sex are horizontally aligned on
                        one row (Name wider than Age/Sex), followed
                        by Home address ↔ Home telephone no., then
                        Parent/guardian ↔ Guardian telephone no.
                        Age, Sex, Home address, Home telephone no.,
                        Parent/guardian, and Guardian telephone no.
                        are pre-filled (via _currentAccomInfo, fetched
                        from AccomForm.php's saved student data — see
                        getStudentAccomInfo() server-side) and, like
                        Name, are rendered READ-ONLY.
     • School info   : School, School address, Subject, and Required
                        no. of hours, and Coordinator telephone no.
                        are now pre-filled from the matching ADMIN
                        account (see getAdminSchoolInfoByCoordinatorName()
                        server-side, matched against the student's OJT
                        Coordinator name). College is pre-filled from
                        the same _currentAccomInfo payload (see
                        getStudentAccomInfo() server-side, which still
                        resolves the `college` column on
                        student_information). All are, like Program and
                        OJT coordinator, rendered READ-ONLY.
     • HTE info      : OJT trainor/supervisor, Telephone no., and
                        Date OJT started are horizontally aligned on
                        one row placed ABOVE the HTE name, followed
                        by HTE name, then Address. Telephone no. and
                        Address are pre-filled from the company's own
                        `telephone` / `company_address` columns in
                        company_information (via _COMPANY_TEL /
                        _COMPANY_ADDRESS, fetched server-side near
                        the top of this file) and, like HTE name, are
                        rendered READ-ONLY.

   NEW: every pre-filled/fetched field across all three sub-sections
   is now rendered with tpReadonlyField() instead of the editable
   tpInputVal()/tpSelectSexVal() helpers, so the entire Student–Trainee
   Information section is uneditable and simply reflects the student's,
   admin's, and company's records on file — matching the fields (Name,
   Program, OJT coordinator, HTE name, Date OJT started) that were
   already read-only.
*/
function renderEvalFormBody() {
    var canvas = document.getElementById('evalDocCanvas');
    if (!canvas) return;

    var coRepName     = _COMPANY_REP_NAME || '';
    var schoolRepName = _currentSchoolRep || '';
    var ojtStartLabel = '';
    if (_currentOjtStartDate) {
        var d = new Date(_currentOjtStartDate + 'T00:00:00');
        var monthsArr = ['January','February','March','April','May','June','July','August','September','October','November','December'];
        ojtStartLabel = monthsArr[d.getMonth()] + ' ' + d.getDate() + ', ' + d.getFullYear();
    }

    /* Student data already submitted on AccomForm.php (Age, Sex, Home
       address, Home telephone no. [or Guardian No. fallback], Guardian
       name, Guardian telephone no., College), plus School, School
       address, Subject, Required no. of hours, and Coordinator
       telephone no. now pulled from the matching admin account — see
       getStudentAccomInfo() and getAdminSchoolInfoByCoordinatorName()
       and the 'accom_info' key returned by the student_library=1
       endpoint. */
    var accomInfo = _currentAccomInfo || {};

    var blocks = [];

    /* Block: section title + hint (kept small and atomic, always fits
       at the top of the first page). */
    var infoHeaderBlock = document.createElement('div');
    infoHeaderBlock.innerHTML =
          '<div class="tp-section-title">Student&ndash;Trainee Information</div>'
        + '<div class="tp-hint">Age, Sex, Home address, Home telephone no., Parent/guardian, Guardian telephone no., School, School address, College, Subject, Required no. of hours, Coordinator telephone no., HTE Telephone no., and HTE Address are pre-filled from the student\'s, admin\'s, and company\'s records on file and are read-only.</div>';
    blocks.push({ el: infoHeaderBlock });

    /* Block: Personal information form grid.
       Name / Age / Sex are horizontally aligned on a single row
       (Name given extra width via flex:2). Home address / Home
       telephone no. and Parent/guardian / Guardian telephone no.
       remain paired side by side below. Age, Sex, Home address,
       Home telephone no., Parent/guardian, and Guardian telephone
       no. are pre-filled from accomInfo and rendered read-only. */
    var personalInfoBlock = document.createElement('div');
    personalInfoBlock.innerHTML =
          '<div class="tp-form-grid">'
        +   tpSectionLabel('Personal information')
        +   tpFieldRow([
                { label: 'Name', html: tpReadonlyField('tp-trainee-name', _currentStudentName || ''), flex: 2 },
                { label: 'Age',  html: tpReadonlyField('tp-trainee-age', accomInfo.age || ''), flex: 1 },
                { label: 'Sex',  html: tpReadonlyField('tp-trainee-sex', accomInfo.sex || ''), flex: 1 }
            ])
        +   tpFieldBlock('Home address', tpReadonlyField('tp-home-address', accomInfo.home_address || ''))
        +   tpFieldBlock('Home telephone no.', tpReadonlyField('tp-home-tel', accomInfo.home_tel || ''))
        +   tpFieldBlock('Parent/guardian', tpReadonlyField('tp-guardian-name', accomInfo.guardian_name || ''))
        +   tpFieldBlock('Guardian telephone no.', tpReadonlyField('tp-guardian-tel', accomInfo.guardian_tel || ''))
        + '</div>';
    blocks.push({ el: personalInfoBlock });

    /* Block: School information form grid.
       School / School address and OJT coordinator / Coordinator
       telephone no. are paired (non-`full`) so each pair sits side
       by side in the two-column grid. Rendered as its own atomic
       pagination block, separate from Personal info, so it can flow
       to a new sheet on its own if needed.

       UPDATED: School, School address, Subject, and Required no. of
       hours, and Coordinator telephone no. are now pre-filled from
       the ADMIN account matching the student's OJT Coordinator (see
       getAdminSchoolInfoByCoordinatorName() server-side). College
       continues to come from accomInfo (student_information). All
       are rendered read-only, matching Program and OJT coordinator. */
    var schoolInfoBlock = document.createElement('div');
    schoolInfoBlock.style.marginTop = '16px';
    schoolInfoBlock.innerHTML =
          '<div class="tp-form-grid">'
        +   tpSectionLabel('School information')
        +   tpFieldBlock('School', tpReadonlyField('tp-school-name', accomInfo.school_name || ''))
        +   tpFieldBlock('School address', tpReadonlyField('tp-school-address', accomInfo.school_address || ''))
        +   tpFieldBlock('College', tpReadonlyField('tp-college', accomInfo.college || ''))
        +   tpFieldBlock('Program', tpReadonlyField('tp-program', _currentStudentCourse || ''))
        +   tpFieldBlock('Subject', tpReadonlyField('tp-subject', accomInfo.subject || ''))
        +   tpFieldBlock('Required no. of hours', tpReadonlyField('tp-required-hours', accomInfo.required_hours || ''))
        +   tpFieldBlock('OJT coordinator', tpReadonlyField('tp-coordinator', schoolRepName || 'Not Assigned'))
        +   tpFieldBlock('Coordinator telephone no.', tpReadonlyField('tp-coordinator-tel', accomInfo.coordinator_tel || ''))
        + '</div>';
    blocks.push({ el: schoolInfoBlock });

    /* Block: Host Training Establishment (HTE) form grid.
       OJT trainor/supervisor, Telephone no., and Date OJT started
       are horizontally aligned on one row placed ABOVE the HTE name,
       followed by HTE name, then Address. Rendered as its own atomic
       block — separate from the Personal/School blocks above — so
       the pagination engine can flow it onto a fresh sheet whenever
       it doesn't fit on the current page, and so it stays as early
       as possible (first page) given its reduced overall height.

       Telephone no. and Address are pre-filled from the company's
       own record in company_information (_COMPANY_TEL /
       _COMPANY_ADDRESS) and rendered read-only, matching HTE name. */
    var hteInfoBlock = document.createElement('div');
    hteInfoBlock.style.marginTop = '16px';
    hteInfoBlock.innerHTML =
          '<div class="tp-form-grid">'
        +   tpSectionLabel('Host training establishment (HTE)')
        +   tpFieldRow([
                { label: 'OJT trainor/supervisor', html: tpReadonlyField('tp-hte-trainor', coRepName || ''), flex: 1 },
                { label: 'Telephone no.',           html: tpReadonlyField('tp-hte-tel', _COMPANY_TEL || ''), flex: 1 },
                { label: 'Date OJT started',        html: tpReadonlyField('tp-ojt-start', ojtStartLabel || ''), flex: 1 }
            ])
        +   tpFieldBlock('HTE name', tpReadonlyField('tp-hte-name', _COMPANY_NAME || ''), {full:true})
        +   tpFieldBlock('Address', tpReadonlyField('tp-hte-address', _COMPANY_ADDRESS || ''), {full:true})
        + '</div>';
    blocks.push({ el: hteInfoBlock });

    /* Block: training-plan instructions + rating scale legend */
    var instrBlock = document.createElement('div');
    instrBlock.innerHTML =
          '<div class="tp-section-title">Training Plan Information and Instructions</div>'
        + '<p class="tp-body-text">On&ndash;the&ndash;Job Training (OJT) or Internship programs are course requirements that provide opportunities for a Student Trainee to apply the theories and ideas learned in the school but also enhanced the technical knowledge, skills and attitudes of students towards work necessary for satisfactory job performance. These training programs expose the trainees to work realities which will improve their competencies or skills and prepare them once they graduate.</p>'
        + '<p class="tp-body-text">This Training Plan provides Student&ndash;Trainee with actual workplace experience, exposure to various management styles, industrial and procedures of occupations in relation to his/her field of study. This plan outlines the specific competency or skill requirements for an HTE&ndash;based training program which need to be mentored, evaluated and monitored. It has two parts:</p>'
        + '<ul class="tp-body-list">'
        + '  <li>General Competencies which evaluate the trainee&rsquo;s values and attitudes toward work. This is equivalent to 40% of the trainee&rsquo;s overall competency.</li>'
        + '  <li>Specific Work Competencies which evaluate the trainee&rsquo;s technical knowledge and skills. The tasks are directly related to his/her field of specialization/study. This is equivalent to 60% of the trainee&rsquo;s overall competency.</li>'
        + '</ul>'
        + '<p class="tp-body-text" style="text-indent:0;"><strong>Competency Requirements:</strong> List of competencies/skills needed to perform the job to the standards specified and agreed by NEUST and the HTE. Skills should be stated as specifically and briefly as possible, identifying the skill to be learned.</p>'
        + '<p class="tp-body-text" style="text-indent:0;"><strong>Competency Rating:</strong> Used to assess the trainee&rsquo;s competency level during the training period and to document exceptional or skill deficiencies. Rating of 1.0 to 3.0 (100&ndash;75) is considered Passed. A rating of 5.0 (below 75) is considered Failed. Remarks highlight the trainee&rsquo;s exceptional skill or skill deficiencies.</p>'
        + '<div class="tp-section-title" style="margin-top:10px;">Competency Rating Scale:</div>'
        + '<table class="tp-scale-table">'
        + '  <tr><td>1.0 = 97&ndash;100</td><td>2.25 = 82&ndash;84</td></tr>'
        + '  <tr><td>1.25 = 94&ndash;96</td><td>2.5 = 79&ndash;81</td></tr>'
        + '  <tr><td>1.5 = 91&ndash;93</td><td>2.75 = 76&ndash;78</td></tr>'
        + '  <tr><td>1.75 = 88&ndash;90</td><td>3.0 = 75</td></tr>'
        + '  <tr><td>2.0 = 85&ndash;87</td><td>5.0 = below 75</td></tr>'
        + '</table>';
    blocks.push({ el: instrBlock });

    /* Block: General Competencies title + row-splittable table */
    blocks.push({ el: tpSectionTitleEl('General Competencies') });
    blocks.push({ table: true, rows: tpBuildGeneralRowEls(), totalLabel: 'General Competency Rating:', totalId: 'tpGeneralTotal' });

    /* Block: Specific Work Competencies title + row-splittable table */
    blocks.push({ el: tpSectionTitleEl('Specific Work Competencies') });
    blocks.push({ table: true, rows: tpBuildSpecificRowEls(), totalLabel: 'Specific Work Competency Rating:', totalId: 'tpSpecificTotal' });

    /* Block: overall rating + signatures
       ── Signature block layout: the signature LINE sits between the
          person's NAME and their POSITION/ROLE label, with the name
          rendered directly above (touching) the line so it visually
          "stands" on the line — matching the static/print rendering
          produced by Eval_form_builder.php's buildEvalFormHTML(),
          which uses the identical .sig-name-wrap / .sig-name /
          .sig-line / .sig-position structure. */
    var sigBlock = document.createElement('div');
    sigBlock.innerHTML =
          '<div class="tp-overall-line">OVERALL COMPETENCY RATING: <span class="tp-overall-val" id="tpOverallVal">&mdash;</span></div>'
        + '<div class="tp-sig-block">'
        + '  <div class="tp-sig-name-wrap"><div class="tp-sig-name">' + escHtml(coRepName || '') + '</div><div class="tp-sig-line"></div></div>'
        + '  <div class="tp-sig-position">HTE OJT Trainor/Supervisor</div>'
        + '  <div class="tp-sig-caption">(Signature over Printed Name)</div>'
        + '</div>'
        + '<div class="tp-conforme-label">Conforme:</div>'
        + '<div class="tp-sig-block tp-left">'
        + '  <div class="tp-sig-name-wrap"><div class="tp-sig-name">' + escHtml(_currentStudentName || '') + '</div><div class="tp-sig-line"></div></div>'
        + '  <div class="tp-sig-position">Student&ndash;Trainee</div>'
        + '  <div class="tp-sig-caption">(Signature over Printed Name)</div>'
        + '</div>';
    blocks.push({ el: sigBlock });

    /* ── Paginate onto fixed A4 sheets and render ── */
    tpPaginateAndRender(canvas, blocks);

    var submitBtn = document.getElementById('evalSubmitBtn');
    if (submitBtn) { submitBtn.disabled = false; submitBtn.classList.remove('loading'); submitBtn.textContent = 'Submit Training Plan'; }

    /* Fresh render — no rows should be flagged as missing yet since
       the popup hasn't been triggered for this render pass. */
    _tpMissingKeys = [];

    tpUpdateTotals();
}

function tpBuildHeaderTemplate() {
    var wrap = document.createElement('div');
    wrap.style.flexShrink = '0';
    wrap.innerHTML =
          '<div class="tp-letterhead">'
        + '  <div class="tp-lh-seal"><img src="logo.webp" alt="NEUST Seal"></div>'
        + '  <div class="tp-lh-text">'
        + '    <div class="tp-lh-line1">Republic of the Philippines</div>'
        + '    <div class="tp-lh-line2">Nueva Ecija University of Science and Technology</div>'
        + '    <div class="tp-lh-line3">On&ndash;the&ndash;Job Training and Career Development Center</div>'
        + '    <div class="tp-lh-line4">Cabanatuan City</div>'
        + '    <div class="tp-lh-line5">ISO 9001:2015 Certified</div>'
        + '  </div>'
        + '</div>'
        + '<div class="tp-title-band">'
        + '  <h1>OJT / Internship Training Plan</h1>'
        + '  <div class="tp-form-meta">Form No.: NEUST&ndash;OJT&ndash;F013</div>'
        + '</div>';
    return wrap;
}
function tpBuildFooterTemplate(pageLabel) {
    var footer = document.createElement('div');
    footer.className = 'tp-footer-band';
    footer.innerHTML = '<span>NEUST&ndash;OJT&ndash;F013</span><span>Rev. 00 (03.04.19)' + (pageLabel ? ' &middot; ' + escHtml(pageLabel) : '') + '</span>';
    return footer;
}
function tpSectionTitleEl(text) {
    var div = document.createElement('div');
    div.className = 'tp-section-title';
    div.textContent = text;
    return div;
}
function tpEmptyCompTable() {
    var table = document.createElement('table');
    table.className = 'tp-comp-table';
    table.innerHTML = '<colgroup><col class="tp-col-item"><col class="tp-col-rate"><col class="tp-col-remarks"></colgroup>';
    var tbody = document.createElement('tbody');
    table.appendChild(tbody);
    return { table: table, tbody: tbody };
}
function tpBuildGeneralRowEls() {
    var rows = [];
    Object.keys(GENERAL_COMPETENCIES).forEach(function(category) {
        var items = GENERAL_COMPETENCIES[category];
        var catRow = document.createElement('tr');
        catRow.className = 'tp-cat-tr';
        catRow.innerHTML = '<td class="tp-cat-td">' + escHtml(category) + '</td><td class="tp-cat-th-cell">Rating</td><td class="tp-cat-th-cell">Remarks</td>';
        rows.push(catRow);
        items.forEach(function(item, i) {
            var key = 'gen_' + tpCategorySlug(category) + '_' + i;
            var tr = document.createElement('tr');
            tr.className = 'tp-item-tr';
            tr.setAttribute('data-row-key', key);
            tr.innerHTML = '<td class="tp-item-td">' + escHtml(item) + '</td>'
                + '<td>' + tpRatingSelectHtml(key) + '</td>'
                + '<td>' + tpRemarksInputHtml(key) + '</td>';
            rows.push(tr);
        });
    });
    return rows;
}
function tpBuildSpecificRowEls() {
    var rows = [];
    SPECIFIC_COMPETENCIES.forEach(function(item, i) {
        var key = 'spec_' + i;
        var tr = document.createElement('tr');
        tr.className = 'tp-item-tr';
        tr.setAttribute('data-row-key', key);
        tr.innerHTML = '<td class="tp-item-td">' + escHtml(item) + '</td>'
            + '<td>' + tpRatingSelectHtml(key) + '</td>'
            + '<td>' + tpRemarksInputHtml(key) + '</td>';
        rows.push(tr);
    });
    return rows;
}

function tpRatingSelectHtml(key) {
    var opts = '<option value="">&mdash;</option>';
    RATING_SCALE.forEach(function(o) {
        opts += '<option value="' + o.v + '">' + o.v + '</option>';
    });
    return '<select class="tp-rate-select" id="rate-' + key + '" data-key="' + key + '" onchange="tpUpdateTotals(); tpClearMissingHighlight(this);">' + opts + '</select>';
}
function tpRemarksInputHtml(key) {
    return '<input type="text" class="tp-remarks-input" id="remarks-' + key + '" data-key="' + key + '" placeholder="Optional remarks" autocomplete="off">';
}

/* Collects every filled rating select and averages them (lower = better,
   matching the school's 1.0–5.0 scale). Returns null if none are filled. */
function tpAverageRatings(keys) {
    var vals = [];
    keys.forEach(function(key) {
        var sel = document.getElementById('rate-' + key);
        if (sel && sel.value !== '') vals.push(parseFloat(sel.value));
    });
    if (vals.length === 0) return null;
    var sum = vals.reduce(function(a,b){ return a+b; }, 0);
    return sum / vals.length;
}

function tpGeneralKeys() {
    var keys = [];
    Object.keys(GENERAL_COMPETENCIES).forEach(function(category) {
        GENERAL_COMPETENCIES[category].forEach(function(item, i) {
            keys.push('gen_' + tpCategorySlug(category) + '_' + i);
        });
    });
    return keys;
}
function tpSpecificKeys() {
    return SPECIFIC_COMPETENCIES.map(function(item, i) { return 'spec_' + i; });
}

function tpUpdateTotals() {
    var genAvg  = tpAverageRatings(tpGeneralKeys());
    var specAvg = tpAverageRatings(tpSpecificKeys());

    var genEl  = document.getElementById('tpGeneralTotal');
    var specEl = document.getElementById('tpSpecificTotal');
    var overallEl = document.getElementById('tpOverallVal');

    if (genEl)  genEl.textContent  = genAvg  !== null ? genAvg.toFixed(2)  : '—';
    if (specEl) specEl.textContent = specAvg !== null ? specAvg.toFixed(2) : '—';

    var overall = null;
    if (genAvg !== null && specAvg !== null) {
        overall = (genAvg * 0.4) + (specAvg * 0.6);
    } else if (genAvg !== null) {
        overall = genAvg;
    } else if (specAvg !== null) {
        overall = specAvg;
    }
    if (overallEl) overallEl.textContent = overall !== null ? overall.toFixed(2) : '—';

    return { genAvg: genAvg, specAvg: specAvg, overall: overall };
}

document.getElementById('evalOverlay').addEventListener('click', function(e) { if (e.target === this) closeEvalModal(); });
function closeEvalModal() { document.getElementById('evalOverlay').classList.remove('open'); document.body.style.overflow = ''; }

/* Returns an array of keys for every General + Specific competency item
   that does NOT yet have a rating selected. Used both to require that
   EVERY item is rated before submission is allowed, and to know exactly
   which rows to highlight for the "See Missed Section" button. */
function tpMissingRatingKeys() {
    var keys = tpGeneralKeys().concat(tpSpecificKeys());
    var missing = [];
    keys.forEach(function(key) {
        var sel = document.getElementById('rate-' + key);
        if (!sel || sel.value === '') missing.push(key);
    });
    return missing;
}

/* Kept for parity with the previous implementation — returns just the
   count, derived from tpMissingRatingKeys(). */
function tpMissingRatingCount() {
    return tpMissingRatingKeys().length;
}

/* ══════════════════════════════════════════════════════════════
   MISSING-RATING HIGHLIGHT
   Flags the rows/selects for the given competency keys so the user
   can visually locate exactly which items still need a rating, and
   scrolls the first one into view. The highlight on any given item
   disappears automatically the moment that item is rated (see
   tpClearMissingHighlight(), wired to each select's onchange).
   ══════════════════════════════════════════════════════════════ */
function tpHighlightMissingSections(keys) {
    if (!keys || keys.length === 0) return;
    var firstTarget = null;
    keys.forEach(function(key) {
        var sel = document.getElementById('rate-' + key);
        if (!sel) return;
        sel.classList.add('tp-missing-highlight');
        var row = sel.closest('tr');
        if (row) row.classList.add('tp-missing-row');
        if (!firstTarget) firstTarget = row || sel;
    });
    if (firstTarget && typeof firstTarget.scrollIntoView === 'function') {
        firstTarget.scrollIntoView({ behavior: 'smooth', block: 'center' });
    }
}

/* Removes the missing-rating highlight from a single rating <select>
   (and its row) the moment it receives a value. Also drops the key
   from the tracked _tpMissingKeys list so re-opening the popup (if it
   still finds other missing items) reflects the current state. */
function tpClearMissingHighlight(selectEl) {
    if (!selectEl) return;
    if (selectEl.value === '') return;
    selectEl.classList.remove('tp-missing-highlight');
    var row = selectEl.closest('tr');
    if (row) row.classList.remove('tp-missing-row');

    var key = selectEl.getAttribute('data-key');
    if (key && _tpMissingKeys && _tpMissingKeys.length) {
        var idx = _tpMissingKeys.indexOf(key);
        if (idx !== -1) _tpMissingKeys.splice(idx, 1);
    }
}

function showExceedPopup(missingCount, missingKeys) {
    _tpMissingKeys = Array.isArray(missingKeys) ? missingKeys.slice() : [];

    var msgEl = document.getElementById('exceedPopupMsg');
    if (msgEl) {
        msgEl.textContent = (typeof missingCount === 'number' && missingCount > 0)
            ? 'Please rate all competency items before submitting the Training Plan. ('
                + missingCount + ' item' + (missingCount !== 1 ? 's' : '') + ' still need'
                + (missingCount === 1 ? 's' : '') + ' a rating.)'
            : 'Please rate all competency items before submitting the Training Plan.';
    }

    var seeBtn = document.getElementById('exceedSeeMissedBtn');
    if (seeBtn) seeBtn.style.display = (_tpMissingKeys.length > 0) ? '' : 'none';

    document.getElementById('exceedBackdrop').classList.add('show');
    document.getElementById('exceedPopup').classList.add('show');
}
function closeExceedPopup() {
    document.getElementById('exceedBackdrop').classList.remove('show');
    document.getElementById('exceedPopup').classList.remove('show');
}

/* Triggered by the "See Missed Section" button on the incomplete-
   ratings popup: closes the popup, then highlights every still-
   missing competency row and scrolls to the first one so the user
   can find and fill it in immediately. */
function seeMissedSection() {
    var keys = (_tpMissingKeys && _tpMissingKeys.length) ? _tpMissingKeys : tpMissingRatingKeys();
    closeExceedPopup();
    tpHighlightMissingSections(keys);
}

function confirmEvalSubmit() {
    /* Every General and Specific competency item must be rated — not just
       one — before the Training Plan can be submitted. The popup now
       appears (and stays relevant) for ANY missing item, not only when
       the whole form is empty. */
    var missingKeys = tpMissingRatingKeys();
    if (missingKeys.length > 0) { showExceedPopup(missingKeys.length, missingKeys); return; }

    var totals = tpUpdateTotals();
    document.getElementById('finalTotalDisplay').textContent = totals.overall !== null ? totals.overall.toFixed(2) : '—';
    document.getElementById('finalConfirmModal').classList.add('open');
}
function closeFinalModal() { document.getElementById('finalConfirmModal').classList.remove('open'); }
document.getElementById('finalConfirmYes').onclick = function() { closeFinalModal(); submitEvaluation(); };

function tpCollectRatings() {
    var ratings = {};
    function collect(key) {
        var sel = document.getElementById('rate-' + key);
        var rem = document.getElementById('remarks-' + key);
        var rv  = sel ? sel.value : '';
        var mv  = rem ? rem.value.trim() : '';
        /* Always record every competency item, even if left blank, so the
           server can verify that ALL items were rated (not just some) —
           previously an unrated item was simply omitted from the payload,
           which made it impossible for the backend to tell "all rated"
           apart from "some rated". */
        ratings[key] = { rating: rv, remarks: mv };
    }
    tpGeneralKeys().forEach(collect);
    tpSpecificKeys().forEach(collect);

    var totals = tpUpdateTotals();
    if (totals.genAvg  !== null) ratings['general_competency_rating']  = { rating: totals.genAvg.toFixed(2),  remarks: '' };
    if (totals.specAvg !== null) ratings['specific_competency_rating'] = { rating: totals.specAvg.toFixed(2), remarks: '' };
    if (totals.overall !== null) ratings['overall_competency_rating']  = { rating: totals.overall.toFixed(2), remarks: '' };

    return ratings;
}

function submitEvaluation() {
    var submitBtn = document.getElementById('evalSubmitBtn');
    if (submitBtn) { submitBtn.disabled = true; submitBtn.classList.add('loading'); submitBtn.textContent = 'Submitting...'; }

    var ratings = tpCollectRatings();

    var formData = new FormData();
    formData.append('student_id', _currentStudentId);
    formData.append('ratings', JSON.stringify(ratings));

    var fieldIds = {
        trainee_age:      'tp-trainee-age',
        trainee_sex:      'tp-trainee-sex',
        home_address:     'tp-home-address',
        home_tel:         'tp-home-tel',
        guardian_name:    'tp-guardian-name',
        guardian_tel:     'tp-guardian-tel',
        school_name:      'tp-school-name',
        school_address:   'tp-school-address',
        college:          'tp-college',
        subject:          'tp-subject',
        required_hours:   'tp-required-hours',
        coordinator_tel:  'tp-coordinator-tel',
        hte_address:      'tp-hte-address',
        hte_tel:          'tp-hte-tel',
    };
    Object.keys(fieldIds).forEach(function(postKey) {
        var el = document.getElementById(fieldIds[postKey]);
        formData.append(postKey, el ? el.value.trim() : '');
    });

    fetch('company_reports.php?save_eval=1', { method: 'POST', body: formData })
        .then(function(r) { if (!r.ok) throw new Error('HTTP ' + r.status); return r.json(); })
        .then(function(data) {
            if (data.success) {
                _currentEvalData = {
                    eval_submitted_at: data.eval_submitted_at,
                    general_competency_rating: data.general_competency_rating,
                    specific_competency_rating: data.specific_competency_rating,
                    overall_competency_rating: data.overall_competency_rating
                };
                if (data.school_representative !== undefined) _currentSchoolRep = data.school_representative || '';

                /* NOTE (carbon-copy fix): Print / Save-as-PDF are
                   intentionally NOT revealed here anymore. They stay
                   hidden until triggerHtmlBlobSave() below finishes
                   swapping the canvas over to the print_eval=1 iframe
                   (#evalBlobFrame) — the same URL that backs the
                   persisted eval_pdf_blob — so both actions can only
                   ever operate on the exact saved "carbon copy" of
                   this Training Plan, never on a transitional render.
                   See triggerHtmlBlobSave()/showBlobInCanvas() above. */

                /* The Evaluate / View Training Plan button now lives on
                   the student summary card (not inside the report
                   library toolbar) — refreshStudentCard() below updates
                   its label/state to "View Training Plan". */
                refreshStudentCard(_currentStudentId);

                var t = document.getElementById('toast');
                t.innerHTML = escHtml(data.message) + ' &nbsp;<a href="company_reports.php?print_eval=1&student_id=' + _currentStudentId + '" target="_blank" style="color:#93c5fd;font-weight:700;text-decoration:underline;">Print</a>';
                t.classList.add('show');
                setTimeout(function() { t.classList.remove('show'); t.innerHTML = ''; }, 6000);

                triggerHtmlBlobSave(_currentStudentId);
            } else {
                showToast(data.message || 'Submission failed.');
                if (submitBtn) { submitBtn.disabled = false; submitBtn.classList.remove('loading'); submitBtn.textContent = 'Submit Training Plan'; }
            }
        })
        .catch(function(err) {
            showToast('Network error: ' + err.message);
            if (submitBtn) { submitBtn.disabled = false; submitBtn.classList.remove('loading'); submitBtn.textContent = 'Submit Training Plan'; }
        });
}

/* ── HELPERS ── */
function escHtml(str) {
    return String(str).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
}
function showToast(msg) {
    var t = document.getElementById('toast');
    t.innerHTML = escHtml(msg);
    t.classList.add('show');
    setTimeout(function() { t.classList.remove('show'); t.innerHTML = ''; }, 2800);
}
</script>
</body>
</html>