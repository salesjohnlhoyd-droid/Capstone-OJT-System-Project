<?php
session_start();
include "db.php";

/* ── Load the SIT form builder from its own file ── */
require_once 'SIT_form_builder.php';

/* ── Load the WAIVER form builder from its own file ── */
require_once 'WAIVER_form_builder.php';

/* ── Load the CONTRACT form builder from its own file ── */
require_once 'CONTRACT_form_builder.php';

/* ── Load the ACCOM form builder from its own file ── */
require_once 'ACCOM_form_builder.php';
/* ================= SESSION CHECK ================= */
if (!isset($_SESSION['user_id']) || $_SESSION['role'] != "student") {
    header("Location: login.php");
    exit;
}
/* ============================================================
   ENSURE 'college' COLUMN EXISTS ON student_information
   ------------------------------------------------------------
   NEW: lazily adds the `college` column to student_information the
   first time it's needed (mirroring the same lazy "ALTER TABLE ...
   ADD COLUMN" pattern already used elsewhere in this system, e.g.
   company_reports.php's ensureEvalRatingColumns()/columnExists()),
   so this page never SQL-errors on an installation where the
   column hasn't been created yet, and company_reports.php's
   getStudentAccomInfo() (which already probes for a `college`
   column via its own columnExists() lookup) has a real column to
   find and pre-fill the Training Plan's "College" field from.
   ============================================================ */
function ensureCollegeColumn(mysqli $conn): void {
    static $checked = false;
    if ($checked) return;
    $checked = true;
    $col_check = $conn->query("SHOW COLUMNS FROM student_information LIKE 'college'");
    if ($col_check && $col_check->num_rows === 0) {
        $conn->query("ALTER TABLE student_information ADD COLUMN college VARCHAR(255) NULL AFTER major");
    }
}
ensureCollegeColumn($conn);

/* ============================================================
   COMPANY DATA FETCH — CONFIG & HELPERS
   ------------------------------------------------------------
   FIX: "Preference for Placement" fields were showing up empty
   after a student got registered/deployed to a company. The old
   code tried to sync the assigned company's info into
   `company_information` keyed by the STUDENT's user_id, and then
   read it back using that same (wrong) key — so it could never
   find a matching row, because nothing is ever stored under a
   student's own user_id in that table.

   The real `company_information` table is actually the COMPANY's
   own profile record — it's keyed by the COMPANY's user_id (the
   same id already stored on ojt_assignments.company_id), exactly
   like it's already read successfully in student_profile.php:

       ojt_assignments.company_id
           -> users.id
           -> company_information.user_id  (company name, address,
                                             telephone, contact person,
                                             position — all in this
                                             one table)

   So instead of writing a mirrored/stale copy under the student's
   own user_id, we now read the company's real profile directly
   using its own user_id (the assigned $_reg_company_id), the same
   way student_profile.php already does successfully. This page
   never writes to the company's own profile data — it's read-only
   here, same as it is on student_profile.php.
   ============================================================ */

/**
 * Splits a single combined "First Middle Last" name string into
 * [first, middle, last] parts. Same first/last-anchored splitting
 * pattern already used elsewhere in this file for guardian_other.
 */
function splitFullNameParts(string $full): array {
    $parts = array_values(array_filter(explode(' ', trim($full))));
    if (empty($parts)) return ['', '', ''];
    $first  = $parts[0];
    $last   = count($parts) >= 2 ? array_pop($parts) : '';
    array_shift($parts); // remove first from remaining
    $middle = implode(' ', $parts);
    return [$first, $middle, $last];
}

/**
 * Reads the assigned company's real profile info (company name,
 * address, telephone, contact person, position) directly from
 * `company_information`, keyed by the COMPANY's own user_id — i.e.
 * ojt_assignments.company_id — matching the join student_profile.php
 * already uses for this same table. Telephone is read from
 * company_information's own `telephone` column (its real schema
 * already has one), so no extra table join is needed for it. Returns
 * null if the table has no data for that company, so callers can
 * fall back to the legacy `companies` table row already joined via
 * ojt_assignments elsewhere in this file.
 */
function fetchCompanyInformation(mysqli $conn, int $company_user_id): ?array {
    if ($company_user_id <= 0) return null;

    $stmt = $conn->prepare("
        SELECT ci.company                AS company_name,
               ci.company_address        AS company_address,
               ci.telephone              AS telephone,
               ci.contact_first_name     AS contact_first_name,
               ci.contact_middle_initial AS contact_middle_initial,
               ci.contact_last_name      AS contact_last_name,
               ci.position               AS position
        FROM users u
        LEFT JOIN company_information ci ON ci.user_id = u.id
        WHERE u.id = ?
        LIMIT 1
    ");
    if (!$stmt) return null;
    $stmt->bind_param("i", $company_user_id);
    $stmt->execute();
    $res = $stmt->get_result();
    $row = $res ? $res->fetch_assoc() : null;
    $stmt->close();
    if (!$row) return null;

    $has_any = !empty($row['company_name']) || !empty($row['company_address'])
        || !empty($row['telephone']) || !empty($row['contact_first_name'])
        || !empty($row['contact_last_name']) || !empty($row['position']);
    if (!$has_any) return null;

    $row['contact_person'] = trim(
        ($row['contact_first_name']     ? $row['contact_first_name']     . ' ' : '') .
        ($row['contact_middle_initial'] ? $row['contact_middle_initial'] . ' ' : '') .
        ($row['contact_last_name']      ?? '')
    );

    return $row;
}

if ($_SESSION['role'] != "student") {
    header("Location: login.php");
    exit;
}

$user_id = $_SESSION['user_id'];
date_default_timezone_set("Asia/Manila");

/* ============================================================
 * AJAX ENDPOINT — returns live requirement statuses as JSON
 * Called every few seconds by the front-end polling loop.
 * URL: AccomForm.php?poll_status=1
 * ============================================================ */
if (isset($_GET['poll_status'])) {
    header('Content-Type: application/json');
    $keys = [
        'cert_registration',
        'certificate_pdos',
        'ojt_sheet',
        'application_sit',
        'waiver_form',
        'student_contract',
        'psych_result',
        'medical_result',
    ];
    $out = [];
    foreach ($keys as $k) {
        $ps = $conn->prepare("SELECT status, remark, file_name FROM requirements WHERE user_id=? AND requirement_type=? LIMIT 1");
        $ps->bind_param("is", $user_id, $k);
        $ps->execute();
        $pr = $ps->get_result()->fetch_assoc();
        $ps->close();
        $has_file = !empty($pr['file_name']);
        $is_pdf   = false;
        if ($has_file) {
            $raw = $pr['file_name'];
            $is_pdf = (substr($raw,0,4) === '%PDF'
                    || substr($raw,0,4) === "\x25\x50\x44\x46"
                    || strpos(substr($raw,0,8),'PDF') !== false);
        }
        $img_src = '';
        if ($has_file && !$is_pdf) {
            $img_src = 'data:image/jpeg;base64,' . base64_encode($pr['file_name']);
        }
        $out[$k] = [
            'status'   => $pr['status']  ?? 'Pending',
            'remark'   => $pr['remark']  ?? '',
            'has_file' => $has_file,
            'is_pdf'   => $is_pdf,
            'img_src'  => $img_src,
        ];
    }
    echo json_encode($out);
    exit;
}

if (isset($_GET['accom_debug'])) {

    $dbg = $conn->prepare("
        SELECT requirement_type, status, uploaded_at
        FROM requirements
        WHERE user_id = ?
        ORDER BY requirement_type
    ");
    $dbg->bind_param("i", $user_id);
    $dbg->execute();
    $dbg_rows = $dbg->get_result()->fetch_all(MYSQLI_ASSOC);
    $dbg->close();

    $current_map = [
        'req_cert_registration'  => 'cert_registration',
        'req_cert_participation' => 'certificate_pdos',
        'req_ojt_program'        => 'ojt_sheet',
        'req_application_sit'    => 'application_sit',
        'req_waiver'             => 'waiver_form',
        'req_contract'           => 'student_contract',
        'req_psych'              => 'psych_result',
        'req_medical'            => 'medical_result',
    ];

    echo '<!DOCTYPE html><html><head>
    <meta charset="UTF-8">
    <style>
        body { font-family: monospace; padding: 30px; background: #0f172a; color: #e2e8f0; }
        h2   { color: #fbbf24; margin-bottom: 6px; }
        p    { color: #94a3b8; margin-bottom: 20px; font-size: 13px; }
        table { border-collapse: collapse; width: 100%; margin-bottom: 30px; }
        th   { background: #1e3a5f; color: #93c5fd; padding: 8px 12px; text-align: left; font-size: 12px; }
        td   { padding: 7px 12px; border-bottom: 1px solid #1e293b; font-size: 13px; }
        tr:nth-child(even) td { background: #1e293b; }
        .match   { color: #4ade80; font-weight: bold; }
        .no-match{ color: #f87171; font-weight: bold; }
        .key-col { color: #c084fc; }
        .val-col { color: #fbbf24; }
        .db-val  { color: #34d399; }
    </style>
    </head><body>';

    echo '<h2>ACTUAL requirement_type values in DB for user_id = ' . (int)$user_id . '</h2>';
    echo '<p>These are the REAL strings stored in the requirements table. Your $_accom_req_map must use exactly these strings.</p>';
    echo '<table><tr><th>requirement_type (exact DB value)</th><th>status</th><th>uploaded_at</th></tr>';
    if (empty($dbg_rows)) {
        echo '<tr><td colspan="3" style="color:#f87171;">No rows found for this user_id in requirements table.</td></tr>';
    }
    foreach ($dbg_rows as $r) {
        echo '<tr>
            <td class="db-val">' . htmlspecialchars($r['requirement_type']) . '</td>
            <td>' . htmlspecialchars($r['status'] ?? '—') . '</td>
            <td>' . htmlspecialchars($r['uploaded_at'] ?? '—') . '</td>
        </tr>';
    }
    echo '</table>';

    echo '<h2>Current $_accom_req_map — what the code is querying</h2>';
    echo '<p>Green = DB has a matching row. Red = no match found in DB (this is your bug).</p>';
    $db_types = array_column($dbg_rows, 'requirement_type');
    echo '<table><tr><th>$accom_data key</th><th>queries requirement_type</th><th>found in DB?</th></tr>';
    foreach ($current_map as $accom_key => $req_type) {
        $found = in_array($req_type, $db_types);
        echo '<tr>
            <td class="key-col">' . htmlspecialchars($accom_key) . '</td>
            <td class="val-col">' . htmlspecialchars($req_type) . '</td>
            <td class="' . ($found ? 'match' : 'no-match') . '">' . ($found ? 'YES' : 'NO — fix this key') . '</td>
        </tr>';
    }
    echo '</table>';

    echo '<p style="color:#94a3b8; font-size:12px;">Remove this debug block once fixed. Access via: AccomForm.php?accom_debug=1</p>';
    echo '</body></html>';
    exit;
}


/* ================= HANDLE PROFILE INFO SAVE ================= */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_profile_info'])) {
    $age           = trim($_POST['age']           ?? '');
    $sex           = trim($_POST['sex']           ?? '');
    $civil_status  = trim($_POST['civil_status']  ?? '');
    $religion      = trim($_POST['religion']      ?? '');
    $home_address  = trim($_POST['home_address']  ?? '');
    $major         = trim($_POST['major']         ?? '');
    $college       = trim($_POST['college']       ?? '');
    $year_section  = trim($_POST['year_section']  ?? '');
    $day_sched     = trim($_POST['day_sched']     ?? '');
    $evening_sched = trim($_POST['evening_sched'] ?? '');

    $ojt_coordinator_first  = trim($_POST['ojt_coordinator_first']  ?? '');
    $ojt_coordinator_middle = trim($_POST['ojt_coordinator_middle'] ?? '');
    $ojt_coordinator_last   = trim($_POST['ojt_coordinator_last']   ?? '');

    $mobile_no         = trim($_POST['mobile_no']         ?? '');
    $mother_first      = trim($_POST['mother_first']      ?? '');
    $mother_middle     = trim($_POST['mother_middle']     ?? '');
    $mother_last       = trim($_POST['mother_last']       ?? '');
    $father_first      = trim($_POST['father_first']      ?? '');
    $father_middle     = trim($_POST['father_middle']     ?? '');
    $father_last       = trim($_POST['father_last']       ?? '');
    $guardian_type     = trim($_POST['guardian_type']     ?? '');
    $guardian_other    = trim($_POST['guardian_other']    ?? '');
    $guardian_no       = trim($_POST['guardian_no']       ?? '');

    $pref_company_name    = trim($_POST['company_name']    ?? '');
    $pref_company_address = trim($_POST['company_address'] ?? '');
    $pref_telephone       = trim($_POST['telephone']       ?? '');
    $pref_contact_person_first  = trim($_POST['contact_person_first']  ?? '');
    $pref_contact_person_middle = trim($_POST['contact_person_middle'] ?? '');
    $pref_contact_person_last   = trim($_POST['contact_person_last']   ?? '');
    $pref_position       = trim($_POST['position']        ?? '');

    $guardian_other_first  = trim($_POST['guardian_other_first']  ?? '');
    $guardian_other_middle = trim($_POST['guardian_other_middle'] ?? '');
    $guardian_other_last   = trim($_POST['guardian_other_last']   ?? '');

    if ($guardian_type === 'Other') {
        $parts = array_filter([$guardian_other_first, $guardian_other_middle, $guardian_other_last]);
        $guardian_other = implode(' ', $parts);
    } else {
        $guardian_other = '';
    }

    $has_new_photo = false;
    $photo_data    = null;

    /* ============================================================
       ADJUSTMENT: JPEG-ONLY UPLOAD VALIDATION (2x2 Photo)
       ------------------------------------------------------------
       Only "image/jpeg" is now accepted server-side for the 2x2
       photo upload (previously JPEG/PNG/GIF/WEBP were all allowed).
       This mirrors the same JPEG-only restriction now enforced on
       the client side (accept attribute + JS ALLOWED_PHOTO_TYPES
       further below) so users get consistent validation whether or
       not JavaScript runs.
       ============================================================ */
    if (isset($_FILES['student_photo']) && $_FILES['student_photo']['error'] === UPLOAD_ERR_OK) {
        $allowed_photo_types = ['image/jpeg'];
        $photo_mime = mime_content_type($_FILES['student_photo']['tmp_name']);
        if (in_array($photo_mime, $allowed_photo_types) && $_FILES['student_photo']['size'] <= 5 * 1024 * 1024) {
            $photo_data    = file_get_contents($_FILES['student_photo']['tmp_name']);
            $has_new_photo = ($photo_data !== false && strlen($photo_data) > 0);
        }
    }

    $chk = $conn->prepare("SELECT id FROM student_information WHERE user_id=? LIMIT 1");
    $chk->bind_param("i", $user_id);
    $chk->execute();
    $chk->store_result();
    $exists = $chk->num_rows > 0;
    $chk->close();

    if ($exists) {
        if ($has_new_photo) {
            $photo_status_new = 'Pending';
            $upd = $conn->prepare("
                UPDATE student_information
                SET age=?, sex=?, civil_status=?, religion=?, home_address=?,
                    major=?, college=?, year_section=?, day_sched=?, evening_sched=?,
                    ojt_coordinator_first=?, ojt_coordinator_middle=?, ojt_coordinator_last=?,
                    mobile_no=?,
                    mother_first=?, mother_middle=?, mother_last=?,
                    father_first=?, father_middle=?, father_last=?,
                    guardian_type=?, guardian_other=?, guardian_no=?,
                    pref_company_name=?, pref_company_address=?, pref_telephone=?,
                    pref_contact_person_first=?, pref_contact_person_middle=?, pref_contact_person_last=?,
                    pref_position=?,
                    student_photo=?, photo_status=?, photo_remark=NULL
                WHERE user_id=?
            ");
            $null = NULL;
            /* 30 string fields (age..pref_position, including college) + student_photo(blob) + photo_status(string) + user_id(int) */
            $types_upd_photo = str_repeat("s", 30) . "bsi";
            $upd->bind_param(
                $types_upd_photo,
                $age, $sex, $civil_status, $religion, $home_address,
                $major, $college, $year_section, $day_sched, $evening_sched,
                $ojt_coordinator_first, $ojt_coordinator_middle, $ojt_coordinator_last,
                $mobile_no,
                $mother_first, $mother_middle, $mother_last,
                $father_first, $father_middle, $father_last,
                $guardian_type, $guardian_other, $guardian_no,
                $pref_company_name, $pref_company_address, $pref_telephone,
                $pref_contact_person_first, $pref_contact_person_middle, $pref_contact_person_last,
                $pref_position,
                $null, $photo_status_new,
                $user_id
            );
            $upd->send_long_data(30, $photo_data);
            $upd->execute();
            $upd->close();
        } else {
            $upd = $conn->prepare("
                UPDATE student_information
                SET age=?, sex=?, civil_status=?, religion=?, home_address=?,
                    major=?, college=?, year_section=?, day_sched=?, evening_sched=?,
                    ojt_coordinator_first=?, ojt_coordinator_middle=?, ojt_coordinator_last=?,
                    mobile_no=?,
                    mother_first=?, mother_middle=?, mother_last=?,
                    father_first=?, father_middle=?, father_last=?,
                    guardian_type=?, guardian_other=?, guardian_no=?,
                    pref_company_name=?, pref_company_address=?, pref_telephone=?,
                    pref_contact_person_first=?, pref_contact_person_middle=?, pref_contact_person_last=?,
                    pref_position=?
                WHERE user_id=?
            ");
            /* 30 string fields (age..pref_position, including college) + user_id(int) */
            $types_upd_nophoto = str_repeat("s", 30) . "i";
            $upd->bind_param(
                $types_upd_nophoto,
                $age, $sex, $civil_status, $religion, $home_address,
                $major, $college, $year_section, $day_sched, $evening_sched,
                $ojt_coordinator_first, $ojt_coordinator_middle, $ojt_coordinator_last,
                $mobile_no,
                $mother_first, $mother_middle, $mother_last,
                $father_first, $father_middle, $father_last,
                $guardian_type, $guardian_other, $guardian_no,
                $pref_company_name, $pref_company_address, $pref_telephone,
                $pref_contact_person_first, $pref_contact_person_middle, $pref_contact_person_last,
                $pref_position,
                $user_id
            );
            $upd->execute();
            $upd->close();
        }
    } else {
        if ($has_new_photo) {
            $photo_status_new = 'Pending';
            $ins = $conn->prepare("
                INSERT INTO student_information
                    (user_id, age, sex, civil_status, religion, home_address,
                     major, college, year_section, day_sched, evening_sched,
                     ojt_coordinator_first, ojt_coordinator_middle, ojt_coordinator_last,
                     mobile_no,
                     mother_first, mother_middle, mother_last,
                     father_first, father_middle, father_last,
                     guardian_type, guardian_other, guardian_no,
                     pref_company_name, pref_company_address, pref_telephone,
                     pref_contact_person_first, pref_contact_person_middle, pref_contact_person_last,
                     pref_position,
                     student_photo, photo_status)
                VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)
            ");
            $null = NULL;
            /* user_id(int) + 30 string fields (age..pref_position, including college) + student_photo(blob) + photo_status(string) */
            $types_ins_photo = "i" . str_repeat("s", 30) . "bs";
            $ins->bind_param(
                $types_ins_photo,
                $user_id,
                $age, $sex, $civil_status, $religion, $home_address,
                $major, $college, $year_section, $day_sched, $evening_sched,
                $ojt_coordinator_first, $ojt_coordinator_middle, $ojt_coordinator_last,
                $mobile_no,
                $mother_first, $mother_middle, $mother_last,
                $father_first, $father_middle, $father_last,
                $guardian_type, $guardian_other, $guardian_no,
                $pref_company_name, $pref_company_address, $pref_telephone,
                $pref_contact_person_first, $pref_contact_person_middle, $pref_contact_person_last,
                $pref_position,
                $null, $photo_status_new
            );
            $ins->send_long_data(31, $photo_data);
            $ins->execute();
            $ins->close();
        } else {
            $ins = $conn->prepare("
                INSERT INTO student_information
                    (user_id, age, sex, civil_status, religion, home_address,
                     major, college, year_section, day_sched, evening_sched,
                     ojt_coordinator_first, ojt_coordinator_middle, ojt_coordinator_last,
                     mobile_no,
                     mother_first, mother_middle, mother_last,
                     father_first, father_middle, father_last,
                     guardian_type, guardian_other, guardian_no,
                     pref_company_name, pref_company_address, pref_telephone,
                     pref_contact_person_first, pref_contact_person_middle, pref_contact_person_last,
                     pref_position)
                VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)
            ");
            /* user_id(int) + 30 string fields (age..pref_position, including college) */
            $types_ins_nophoto = "i" . str_repeat("s", 30);
            $ins->bind_param(
                $types_ins_nophoto,
                $user_id,
                $age, $sex, $civil_status, $religion, $home_address,
                $major, $college, $year_section, $day_sched, $evening_sched,
                $ojt_coordinator_first, $ojt_coordinator_middle, $ojt_coordinator_last,
                $mobile_no,
                $mother_first, $mother_middle, $mother_last,
                $father_first, $father_middle, $father_last,
                $guardian_type, $guardian_other, $guardian_no,
                $pref_company_name, $pref_company_address, $pref_telephone,
                $pref_contact_person_first, $pref_contact_person_middle, $pref_contact_person_last,
                $pref_position
            );
            $ins->execute();
            $ins->close();
        }
    }

    $company_name    = $pref_company_name;
    $company_address = $pref_company_address;
    $telephone       = $pref_telephone;
    $contact_person  = trim($pref_contact_person_first . ' ' . ($pref_contact_person_middle ? $pref_contact_person_middle . ' ' : '') . $pref_contact_person_last);
    $position        = $pref_position;

    $ca = $conn->prepare("SELECT company_id FROM ojt_assignments WHERE student_id=? LIMIT 1");
    $ca->bind_param("i", $user_id);
    $ca->execute();
    $ca->store_result();
    $ca->bind_result($cid);
    $ca->fetch();
    $ca->close();

    if (!empty($cid)) {
        $cu = $conn->prepare("
            UPDATE companies
            SET company_name=?, company_address=?, telephone=?, contact_person=?, position=?
            WHERE id=?
        ");
        $cu->bind_param("sssssi", $company_name, $company_address, $telephone, $contact_person, $position, $cid);
        $cu->execute();
        $cu->close();

        /* NOTE: company_information holds the assigned company's OWN
           profile data (managed from the company's side of the system,
           keyed by that company's user_id — see fetchCompanyInformation()
           above / student_profile.php). This student-side page only
           ever reads it for display; it never writes to it, so there's
           nothing to sync here. */
    }

    /* ============================================================
       ADJUSTMENT: INSTANT UI UPDATE (NO FULL PAGE RELOAD)
       ------------------------------------------------------------
       When this form is submitted via the page's AJAX handler (see
       the profileInfoForm submit listener further down in the
       <script> block), the request is flagged either via the
       X-Requested-With header or the "ajax_request" POST field.
       In that case we respond with a small JSON payload instead of
       doing the old full-page redirect, so the front-end can update
       the 2x2 photo status/preview and show the "Information Saved"
       confirmation immediately, without the user needing to reload
       or navigate away from the page.

       Non-AJAX submissions (e.g. JavaScript disabled) fall back to
       the original behavior — a redirect back to AccomForm.php with
       ?msg=profile_saved — exactly as before, so nothing existing is
       broken for that path.
       ============================================================ */
    $_is_ajax_request = (
        (isset($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest')
        || (isset($_POST['ajax_request']) && $_POST['ajax_request'] === '1')
    );

    if ($_is_ajax_request) {
        header('Content-Type: application/json');
        echo json_encode([
            'success'       => true,
            'has_new_photo' => $has_new_photo,
        ]);
        exit;
    }

    header("Location: AccomForm.php?msg=profile_saved");
    exit;
}

/* ================= CHECK IF ALL REQUIREMENTS VERIFIED ================= */
$all_verified = false;

$required_types = [
    'cert_registration',
    'certificate_pdos',
    'ojt_sheet',
    'application_sit',
    'waiver_form',
    'student_contract',
    'psych_result',
    'medical_result',
];

$required_count = count($required_types); // Always 8

$stmt = $conn->prepare("
    SELECT COUNT(*) as verified_count
    FROM requirements
    WHERE user_id = ?
      AND requirement_type IN ('cert_registration','certificate_pdos','ojt_sheet',
                               'application_sit','waiver_form','student_contract',
                               'psych_result','medical_result')
      AND status = 'Verified'
");
$stmt->bind_param("i", $user_id);
$stmt->execute();
$res  = $stmt->get_result();
$data = $res->fetch_assoc();
$stmt->close();

// Only unlock when ALL 8 specific requirement types are verified
if ($data && (int)$data['verified_count'] === $required_count) {
    $all_verified = true;
}

/* ================= FETCH DEPLOY STATUS FOR SIDEBAR GATE ================= */
$deploy_stmt = $conn->prepare("SELECT deploy_status FROM users WHERE id = ?");
$deploy_stmt->bind_param("i", $user_id);
$deploy_stmt->execute();
$deploy_stmt->bind_result($_deploy_status);
$deploy_stmt->fetch();
$deploy_stmt->close();
$is_deployed = ($_deploy_status === 'Deployed');

// ================= ATTENDANCE SIDEBAR BADGE + BAR INFO =================
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

        $_att_all_done = ($_att_log && is_array($_att_log)
            && ($_att_log['am_time_in']  !== null && $_att_log['am_time_in']  !== '' && $_att_log['am_time_in']  !== 'missed')
            && ($_att_log['am_time_out'] !== null && $_att_log['am_time_out'] !== '' && $_att_log['am_time_out'] !== 'missed')
            && ($_att_log['pm_time_in']  !== null && $_att_log['pm_time_in']  !== '' && $_att_log['pm_time_in']  !== 'missed')
            && ($_att_log['pm_time_out'] !== null && $_att_log['pm_time_out'] !== '' && $_att_log['pm_time_out'] !== 'missed'));

        $_att_windows = [
            'am_time_in'  => ['label' => 'AM Duty Sign In',  'start' => $_att_setting['am_time_in_start'],  'end' => $_att_setting['am_time_in_end']],
            'am_time_out' => ['label' => 'AM Duty Sign Out', 'start' => $_att_setting['am_time_out_start'], 'end' => $_att_setting['am_time_out_end']],
            'pm_time_in'  => ['label' => 'PM Duty Sign In',  'start' => $_att_setting['pm_time_in_start'],  'end' => $_att_setting['pm_time_in_end']],
            'pm_time_out' => ['label' => 'PM Duty Sign Out', 'start' => $_att_setting['pm_time_out_start'], 'end' => $_att_setting['pm_time_out_end']],
        ];

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
                        'type'       => $type,
                        'label'      => $winfo['label'],
                        'start_fmt'  => $fmt12att($winfo['start']),
                        'end_fmt'    => $fmt12att($winfo['end']),
                        'start_time' => $winfo['start'],
                        'end_time'   => $winfo['end'],
                    ];
                    break;
                }
            }
        }

        /* ── FIX: Also check PM Sign Out late-window for sidebar badge ── */
        if (!$attendance_badge_info && !$_att_all_done) {
            $pm_out_end_str = $_att_setting['pm_time_out_end'] ?? null;
            if ($pm_out_end_str) {
                $pm_out_end_sec   = $timeToSec($pm_out_end_str);
                $late_window_end  = $pm_out_end_sec + 3600;
                if ($_att_now_sec > $pm_out_end_sec && $_att_now_sec <= $late_window_end) {
                    $pm_out_val = (is_array($_att_log) && array_key_exists('pm_time_out', $_att_log))
                        ? $_att_log['pm_time_out']
                        : null;
                    $pm_already_done = ($pm_out_val !== null && $pm_out_val !== '' && $pm_out_val !== 'missed');
                    if (!$pm_already_done) {
                        $late_h   = floor($late_window_end / 3600);
                        $late_m   = floor(($late_window_end % 3600) / 60);
                        $late_end_str = sprintf('%02d:%02d:00', $late_h, $late_m);
                        $att_sidebar_badge     = true;
                        $attendance_badge_info = [
                            'type'           => 'pm_time_out_late',
                            'label'          => 'PM Sign Out Late Request',
                            'start_fmt'      => $fmt12att($pm_out_end_str) . ' (missed)',
                            'end_fmt'        => $fmt12att($late_end_str) . ' (deadline)',
                            'start_time'     => $pm_out_end_str,
                            'end_time'       => $late_end_str,
                            'is_late_window' => true,
                        ];
                    }
                }
            }
        }
    }
}

/* ================= DATA FETCHING ================= */
$stmt = $conn->prepare("SELECT first_name, middle_name, last_name, course FROM users WHERE id=?");
$stmt->bind_param("i", $user_id);
$stmt->execute();
$stmt->bind_result($first, $middle, $last, $course);
$stmt->fetch();
$stmt->close();

/*
 * FIX 1: Build full name correctly, including middle name.
 * Trim handles cases where middle_name is NULL or empty string.
 */
$fullname = trim(
    ($first  ? $first  . ' ' : '') .
    ($middle ? $middle . ' ' : '') .
    ($last   ? $last         : '')
);

$stmt = $conn->prepare("SELECT student_photo, photo_status, photo_remark FROM student_information WHERE user_id=?");
$stmt->bind_param("i", $user_id);
$stmt->execute();
$stmt->store_result();
$photo = null; $photo_status = null; $photo_remark = null;
$stmt->bind_result($photo, $photo_status, $photo_remark);
$stmt->fetch();
$stmt->close();

/* ================= FETCH EXTENDED STUDENT DATA ================= */
$sit_extra = [];
$stmt_si = $conn->prepare("
    SELECT age, sex, civil_status, religion, home_address,
           major, college, year_section, day_sched, evening_sched,
           ojt_coordinator_first, ojt_coordinator_middle, ojt_coordinator_last,
           mobile_no,
           mother_first, mother_middle, mother_last,
           father_first, father_middle, father_last,
           guardian_type, guardian_other, guardian_no,
           pref_company_name, pref_company_address, pref_telephone,
           pref_contact_person_first, pref_contact_person_middle, pref_contact_person_last,
           pref_position
    FROM student_information
    WHERE user_id = ?
    LIMIT 1
");
if ($stmt_si) {
    $stmt_si->bind_param("i", $user_id);
    $stmt_si->execute();
    $res_si = $stmt_si->get_result();
    if ($res_si) {
        $row_si = $res_si->fetch_assoc();
        if ($row_si) $sit_extra = $row_si;
    }
    $stmt_si->close();
}

$ojt_coordinator_first_val  = $sit_extra['ojt_coordinator_first']  ?? '';
$ojt_coordinator_middle_val = $sit_extra['ojt_coordinator_middle'] ?? '';
$ojt_coordinator_last_val   = $sit_extra['ojt_coordinator_last']   ?? '';

$ojt_coordinator_full = trim(
    ($ojt_coordinator_first_val  ? $ojt_coordinator_first_val  . ' ' : '') .
    ($ojt_coordinator_middle_val ? $ojt_coordinator_middle_val . ' ' : '') .
    $ojt_coordinator_last_val
);

/* ============================================================
   Detect registration/deployment via ojt_assignments, and read the
   assigned company's real profile info.
   ------------------------------------------------------------
   `oa.company_id` is selected alongside the companies join below —
   it tells us whether an assignment exists, lets us read the
   `companies` row as a fallback source, AND is the correct key
   (the company's own user_id) for reading company_information
   further below, matching student_profile.php.
   ============================================================ */
$sit_company = [];
$_reg_company_id = null;
$stmt_co = $conn->prepare("
    SELECT oa.company_id AS company_id,
           c.company_name, c.company_address, c.telephone, c.contact_person, c.position
    FROM ojt_assignments oa
    LEFT JOIN companies c ON c.id = oa.company_id
    WHERE oa.student_id = ?
    LIMIT 1
");
if ($stmt_co) {
    $stmt_co->bind_param("i", $user_id);
    $stmt_co->execute();
    $res_co = $stmt_co->get_result();
    if ($res_co) {
        $row_co = $res_co->fetch_assoc();
        if ($row_co) {
            $_reg_company_id = $row_co['company_id'] ?? null;
            unset($row_co['company_id']);
            $sit_company = $row_co;
        }
    }
    $stmt_co->close();
}

/* Read the assigned company's real profile info (company_information),
   keyed by the COMPANY's own user_id ($_reg_company_id, i.e.
   ojt_assignments.company_id) — the same key student_profile.php
   already uses successfully for this table. Falls back silently to
   $sit_company (the `companies` table row already joined above) if
   the company hasn't filled out that profile yet. */
$_company_info_row = null;
if (!empty($_reg_company_id)) {
    $_company_info_row = fetchCompanyInformation($conn, (int)$_reg_company_id);
}
/* Preferred source for the "Preference for Placement" display: the
   freshly-fetched company_information row when available, otherwise
   the `companies` row already joined above. */
$_pref_source = $_company_info_row ?: $sit_company;

$contact_person_first_val  = $sit_extra['pref_contact_person_first']  ?? '';
$contact_person_middle_val = $sit_extra['pref_contact_person_middle'] ?? '';
$contact_person_last_val   = $sit_extra['pref_contact_person_last']   ?? '';

$contact_person_full = trim(
    ($contact_person_first_val  ? $contact_person_first_val  . ' ' : '') .
    ($contact_person_middle_val ? $contact_person_middle_val . ' ' : '') .
    $contact_person_last_val
);

if (empty($sit_company['company_name']) && !empty($sit_extra['pref_company_name'])) {
    $sit_company = [
        'company_name'    => $sit_extra['pref_company_name']    ?? '',
        'company_address' => $sit_extra['pref_company_address'] ?? '',
        'telephone'       => $sit_extra['pref_telephone']       ?? '',
        'contact_person'  => $contact_person_full,
        'position'        => $sit_extra['pref_position']        ?? '',
    ];
}

$has_ojt_assignment = !empty($_reg_company_id) || !empty($sit_company['company_name']);

/* ============================================================
   PREFERENCE FOR PLACEMENT — DISPLAY VALUES
   ------------------------------------------------------------
   The "Preference for Placement" fields on the Student Info tab
   must reflect the company the student is actually registered /
   assigned to. They prefer the freshly-fetched
   `company_information` row ($_pref_source) — falling back to the
   `companies` row joined via ojt_assignments ($sit_company) if
   company_information has no data yet — and only fall back further
   to the student's typed placement preference when no assignment
   exists at all. Whenever an assignment exists ($has_ojt_assignment),
   these fields are also rendered as locked/read-only inputs further
   down in the HTML (same treatment as the student's name fields),
   since at that point this is the student's official, confirmed
   company information rather than a free-text preference.
   ============================================================ */
$pref_display_company_name    = !empty($_pref_source['company_name'])
    ? $_pref_source['company_name']
    : ($sit_extra['pref_company_name'] ?? '');
$pref_display_company_address = !empty($_pref_source['company_address'])
    ? $_pref_source['company_address']
    : ($sit_extra['pref_company_address'] ?? '');
$pref_display_telephone       = !empty($_pref_source['telephone'])
    ? $_pref_source['telephone']
    : ($sit_extra['pref_telephone'] ?? '');
$pref_display_position        = !empty($_pref_source['position'])
    ? $_pref_source['position']
    : ($sit_extra['pref_position'] ?? '');

if ($has_ojt_assignment && !empty($_pref_source['contact_person'])) {
    /* Registered company's contact person is stored as a single combined
       name (recombined from the first/middle/last columns when the
       source is company_information, or already combined when the
       source is `companies`), so split it the same way guardian_other
       is split further below, to populate the first/middle/last inputs. */
    $_pdc_parts = array_values(array_filter(explode(' ', $_pref_source['contact_person'])));
    $pref_display_contact_first  = $_pdc_parts[0] ?? '';
    $pref_display_contact_last   = count($_pdc_parts) >= 2 ? array_pop($_pdc_parts) : '';
    $pref_display_contact_middle = count($_pdc_parts) > 1 ? implode(' ', array_slice($_pdc_parts, 1)) : '';
} else {
    $pref_display_contact_first  = $contact_person_first_val;
    $pref_display_contact_middle = $contact_person_middle_val;
    $pref_display_contact_last   = $contact_person_last_val;
}

/* whether the Preference for Placement fields should be locked
   (read-only, like the student's name fields) because the student is
   already registered/deployed to an official company. */
$lock_preference_fields = $has_ojt_assignment;

$sit_data = [
    'last_name'       => $last   ?? '',
    'first_name'      => $first  ?? '',
    'middle_name'     => $middle ?? '',
    'age'             => $sit_extra['age']             ?? '',
    'sex'             => $sit_extra['sex']             ?? '',
    'civil_status'    => $sit_extra['civil_status']    ?? '',
    'religion'        => $sit_extra['religion']        ?? '',
    'home_address'    => $sit_extra['home_address']    ?? '',
    'mobile_no'       => $sit_extra['mobile_no']       ?? '',
    'mother_first'    => $sit_extra['mother_first']    ?? '',
    'mother_middle'   => $sit_extra['mother_middle']   ?? '',
    'mother_last'     => $sit_extra['mother_last']     ?? '',
    'father_first'    => $sit_extra['father_first']    ?? '',
    'father_middle'   => $sit_extra['father_middle']   ?? '',
    'father_last'     => $sit_extra['father_last']     ?? '',
    'guardian_type'   => $sit_extra['guardian_type']   ?? '',
    'guardian_other'  => $sit_extra['guardian_other']  ?? '',
    'course'               => $course                         ?? '',
    'major'                => $sit_extra['major']             ?? '',
    'college'              => $sit_extra['college']           ?? '',
    'year_section'         => $sit_extra['year_section']      ?? '',
    'day_sched'            => $sit_extra['day_sched']         ?? '',
    'evening_sched'        => $sit_extra['evening_sched']     ?? '',
    'ojt_coordinator_first'  => $ojt_coordinator_first_val,
    'ojt_coordinator_middle' => $ojt_coordinator_middle_val,
    'ojt_coordinator_last'   => $ojt_coordinator_last_val,
    'company'         => $sit_company['company_name']    ?? '',
    'company_address' => $sit_company['company_address'] ?? '',
    'telephone'       => $sit_company['telephone']       ?? '',
    'contact_person'  => $sit_company['contact_person']  ?? '',
    'position'        => $sit_company['position']        ?? '',
];

/* ================= BUILD WAIVER FORM DATA ================= */
$waiver_data = [
    'first_name'    => $first  ?? '',
    'middle_name'   => $middle ?? '',
    'last_name'     => $last   ?? '',
    'ojt_hours'     => '',
    'ojt_start'     => '',
    'ojt_end'       => '',
    'company'       => $sit_company['company_name']    ?? '',
    'course'        => $course                         ?? '',
    'major'         => $sit_extra['major']             ?? '',
    'home_address'  => $sit_extra['home_address']      ?? '',
    'telephone'     => $sit_company['telephone']       ?? '',
    'mobile'        => $sit_extra['mobile_no']         ?? '',
    'mother_first'  => $sit_extra['mother_first']  ?? '',
    'mother_middle' => $sit_extra['mother_middle'] ?? '',
    'mother_last'   => $sit_extra['mother_last']   ?? '',
    'father_first'  => $sit_extra['father_first']  ?? '',
    'father_middle' => $sit_extra['father_middle'] ?? '',
    'father_last'   => $sit_extra['father_last']   ?? '',
    'guardian_type' => $sit_extra['guardian_type'] ?? '',
    'guardian_other'=> $sit_extra['guardian_other']?? '',
];

/* ================= BUILD CONTRACT FORM DATA ================= */
$contract_data = [
    'first_name'            => $first  ?? '',
    'middle_name'           => $middle ?? '',
    'last_name'             => $last   ?? '',
    'company'               => $sit_company['company_name'] ?? '',
    'signed_day'            => '',
    'signed_month'          => '',
    'signed_year'           => '',
    'signed_place'          => '',
    'res_cert_no'           => '',
    'issued_at'             => '',
    'issued_on'             => '',
    'mother_first'          => $sit_extra['mother_first']  ?? '',
    'mother_middle'         => $sit_extra['mother_middle'] ?? '',
    'mother_last'           => $sit_extra['mother_last']   ?? '',
    'father_first'          => $sit_extra['father_first']  ?? '',
    'father_middle'         => $sit_extra['father_middle'] ?? '',
    'father_last'           => $sit_extra['father_last']   ?? '',
    'guardian_type'         => $sit_extra['guardian_type'] ?? '',
    'guardian_other'        => $sit_extra['guardian_other']?? '',
    'school_representative' => $ojt_coordinator_full,
];

/* ================= BUILD ACCOMPLISHMENT FORM DATA ================= */
$_accom_req_map = [
    'req_cert_registration'  => 'cert_registration',
    'req_cert_participation' => 'certificate_pdos',
    'req_ojt_program'        => 'ojt_sheet',
    'req_application_sit'    => 'application_sit',
    'req_waiver'             => 'waiver_form',
    'req_contract'           => 'student_contract',
    'req_psych'              => 'psych_result',
    'req_medical'            => 'medical_result',
];

$_accom_rows = [];
foreach ($_accom_req_map as $accom_key => $req_type) {
    $rs = $conn->prepare("SELECT uploaded_at, remark, status FROM requirements WHERE user_id=? AND requirement_type=? LIMIT 1");
    $rs->bind_param("is", $user_id, $req_type);
    $rs->execute();
    $rr = $rs->get_result()->fetch_assoc();
    $rs->close();
    $_accom_rows[$accom_key] = $rr ?? null;
}

$_accom_photo_src = '';
if (!empty($photo)) {
    $_accom_photo_src = 'data:image/jpeg;base64,' . base64_encode($photo);
}

$accom_data = [
    'first_name'           => $first  ?? '',
    'middle_name'          => $middle ?? '',
    'last_name'            => $last   ?? '',
    'course'               => $course ?? '',
    'year_section'         => $sit_extra['year_section']         ?? '',
    'company'              => $sit_company['company_name']        ?? '',
    'company_address'      => $sit_company['company_address']     ?? '',
    'company_telephone'    => $sit_company['telephone']           ?? '',
    'pre_contact_person_first'  => $contact_person_first_val,
    'pre_contact_person_middle' => $contact_person_middle_val,
    'pre_contact_person_last'   => $contact_person_last_val,
    'contact_position'          => $sit_company['position']       ?? '',
    'req_cert_registration'  => [
        'uploaded_at' => $_accom_rows['req_cert_registration']['uploaded_at']  ?? '',
        'remark'      => $_accom_rows['req_cert_registration']['remark']        ?? '',
        'status'      => $_accom_rows['req_cert_registration']['status']        ?? '',
    ],
    'req_cert_participation' => [
        'uploaded_at' => $_accom_rows['req_cert_participation']['uploaded_at'] ?? '',
        'remark'      => $_accom_rows['req_cert_participation']['remark']       ?? '',
        'status'      => $_accom_rows['req_cert_participation']['status']       ?? '',
    ],
    'req_ojt_program'        => [
        'uploaded_at' => $_accom_rows['req_ojt_program']['uploaded_at']        ?? '',
        'remark'      => $_accom_rows['req_ojt_program']['remark']              ?? '',
        'status'      => $_accom_rows['req_ojt_program']['status']              ?? '',
    ],
    'req_application_sit'    => [
        'uploaded_at' => $_accom_rows['req_application_sit']['uploaded_at']    ?? '',
        'remark'      => $_accom_rows['req_application_sit']['remark']          ?? '',
        'status'      => $_accom_rows['req_application_sit']['status']          ?? '',
    ],
    'req_waiver'             => [
        'uploaded_at' => $_accom_rows['req_waiver']['uploaded_at']             ?? '',
        'remark'      => $_accom_rows['req_waiver']['remark']                   ?? '',
        'status'      => $_accom_rows['req_waiver']['status']                   ?? '',
    ],
    'req_contract'           => [
        'uploaded_at' => $_accom_rows['req_contract']['uploaded_at']           ?? '',
        'remark'      => $_accom_rows['req_contract']['remark']                 ?? '',
        'status'      => $_accom_rows['req_contract']['status']                 ?? '',
    ],
    'req_psych'              => [
        'uploaded_at' => $_accom_rows['req_psych']['uploaded_at']              ?? '',
        'remark'      => $_accom_rows['req_psych']['remark']                    ?? '',
        'status'      => $_accom_rows['req_psych']['status']                    ?? '',
    ],
    'req_medical'            => [
        'uploaded_at' => $_accom_rows['req_medical']['uploaded_at']            ?? '',
        'remark'      => $_accom_rows['req_medical']['remark']                  ?? '',
        'status'      => $_accom_rows['req_medical']['status']                  ?? '',
    ],
    'ojt_coordinator_first'  => $ojt_coordinator_first_val,
    'ojt_coordinator_middle' => $ojt_coordinator_middle_val,
    'ojt_coordinator_last'   => $ojt_coordinator_last_val,
    'general_remarks'        => '',
    'date_signed'            => '',
    'photo_src'              => $_accom_photo_src,
];

$guardian_other_stored = $sit_extra['guardian_other'] ?? '';
$_go_parts = $guardian_other_stored !== ''
    ? array_values(array_filter(explode(' ', $guardian_other_stored)))
    : [];
$guardian_other_first_val  = $_go_parts[0] ?? '';
$guardian_other_last_val   = count($_go_parts) >= 2 ? array_pop($_go_parts) : '';
$guardian_other_middle_val = count($_go_parts) > 1
    ? implode(' ', array_slice($_go_parts, 1))
    : '';

/* ── If called with ?sit_preview=1, output only the SIT form HTML ── */
if (isset($_GET['sit_preview'])) {
    echo buildSITFormHTML($sit_data);
    exit;
}

if (isset($_GET['sit_print'])) {
    $html = buildSITFormHTML($sit_data);
    $print_script = '<script>
(function() {
    function doPrint() {
        window.focus();
        window.print();
    }
    if (document.readyState === "complete") {
        setTimeout(doPrint, 600);
    } else {
        window.addEventListener("load", function() { setTimeout(doPrint, 600); });
    }
})();
</script>';
    $html = str_replace('</body>', $print_script . '</body>', $html);
    echo $html;
    exit;
}

if (isset($_GET['waiver_preview'])) {
    echo buildWAIVERFormHTML($waiver_data);
    exit;
}

if (isset($_GET['waiver_print'])) {
    $html = buildWAIVERFormHTML($waiver_data);
    $print_script = '<script>
(function() {
    function doPrint() {
        window.focus();
        window.print();
    }
    if (document.readyState === "complete") {
        setTimeout(doPrint, 600);
    } else {
        window.addEventListener("load", function() { setTimeout(doPrint, 600); });
    }
})();
</script>';
    $html = str_replace('</body>', $print_script . '</body>', $html);
    echo $html;
    exit;
}

if (isset($_GET['contract_preview'])) {
    echo buildCONTRACTFormHTML($contract_data);
    exit;
}

if (isset($_GET['contract_print'])) {
    $html = buildCONTRACTFormHTML($contract_data);
    $print_script = '<script>
(function() {
    function doPrint() {
        window.focus();
        window.print();
    }
    if (document.readyState === "complete") {
        setTimeout(doPrint, 600);
    } else {
        window.addEventListener("load", function() { setTimeout(doPrint, 600); });
    }
})();
</script>';
    $html = str_replace('</body>', $print_script . '</body>', $html);
    echo $html;
    exit;
}

if (isset($_GET['accom_preview'])) {
    echo buildACCOMFormHTML($accom_data);
    exit;
}

$initial_tab = 'documents-page';
if (isset($_GET['msg']) && $_GET['msg'] === 'profile_saved') {
    $initial_tab = 'digital-page';
}

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Digital Requirements Submission</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=DM+Sans:wght@400;500;600;700&family=DM+Mono:wght@400;500&display=swap" rel="stylesheet">
    <style>
        /* ══════════════════════════════════════════════════════════════════
           UPDATED (design adjustment): this page now uses the same
           "Field Ops Grid" design as admin_student_list.php — square corners,
           thin slate borders instead of soft shadows, navy (#1B2A4A) as the
           action colour, small uppercase labels, flat status colours and no
           emoji. The coloured left bar that used to sit on the form's
           sub-section titles was removed. ONLY the look changed: every class
           name, id, open/show state and layout rule that the scripts below
           rely on is kept exactly as it was.
           ══════════════════════════════════════════════════════════════════ */
        :root {
            --neust-maroon: #07145fe5;
            --neust-gold: #FFD700;
            --neust-active: #1a237e;
            --primary: #1B2A4A;
            --bg: #EEF1F6;
            --white: #ffffff;
            --text: #2d3748;
            --pending: #A0850A;
            --denied: #A02A2A;
            --approved: #2C5A2C;

            /* Field Ops Grid palette (same values as admin_student_list.php) */
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

        * { box-sizing: border-box; margin: 0; padding: 0; }

        body {
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            background: var(--grid-bg);
            color: var(--text);
            display: flex;
            min-height: 100vh;
        }

        /* ══ SIDEBAR (same look as admin_student_list.php) ══ */
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

        .sidebar a:hover:not(.active) { background: rgba(255,255,255,0.05); color: white; }
        .sidebar a.active {
            background: var(--neust-active);
            color: white;
            border-left: 4px solid var(--neust-gold);
        }

        .sidebar a.nav-locked { cursor: not-allowed; opacity: 0.55; }
        .sidebar a.nav-locked:hover { background: rgba(255,255,255,0.04); color: #cbd5e0; }
        .nav-lock-icon {
            font-size: 11px; color: var(--neust-gold); position: absolute;
            right: 22px; top: 50%; transform: translateY(-50%); opacity: 0.85;
        }
        .sidebar.collapsed .nav-lock-icon { display: none; }

        .sidebar-lock-notice {
            padding: 16px 20px 4px;
        }
        .sidebar-lock-notice-inner {
            display: flex; align-items: flex-start; gap: 10px;
            background: rgba(255,255,255,0.06); border: 1px solid rgba(255,255,255,0.14);
            border-radius: 0; padding: 10px 12px;
        }
        .sidebar-lock-notice i { font-size: 14px; color: var(--neust-gold); margin-top: 1px; flex-shrink: 0; }
        .sidebar-lock-notice p { font-size: 11px; color: rgba(255,255,255,0.65); line-height: 1.5; margin: 0; }
        .sidebar.collapsed .sidebar-lock-notice { display: none; }

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
        .sidebar.collapsed .logout-link a { border-color: transparent; }
        .logout-link a:hover { background: rgba(255,215,0,0.08); }

        .toggle-btn {
            background: transparent; border: none; color: white;
            cursor: pointer; font-size: 20px; outline: none; flex-shrink: 0;
        }

        /* ══ MAIN CONTENT ══ */
        .main-content {
            margin-left: 260px; width: calc(100% - 260px);
            transition: margin-left 0.3s, width 0.3s;
            display: flex; flex-direction: column; min-height: 100vh;
        }

        /* ══ NAVBAR ══ */
        .navbar {
            background: var(--neust-maroon); padding: 10px 30px;
            display: flex; align-items: center; color: white;
            height: 60px; flex-shrink: 0;
            position: relative; z-index: 99;
        }
        .navbar img { height: 40px; margin-right: 14px; }
        .navbar-title { font-weight: bold; font-size: 15px; letter-spacing: 0.5px; }

        /* ══ ATTENDANCE NOTIFICATION BAR (same square navy look as the
           admin pages' top notification popup) ══ */
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

        @media (max-width: 768px) {
            #att-notif-bar,
            #att-notif-bar.sidebar-collapsed {
                left: 50% !important;
                width: calc(100% - 20px) !important;
                max-width: none !important;
            }
        }

        /* ══ CONTENT AREA ══ */
        .main-wrapper { flex: 1; padding: 30px; background: var(--grid-bg); }

        .card {
            background: var(--white); width: 100%; max-width: 1100px;
            padding: 32px; border-radius: 0;
            border: 1px solid var(--grid-border);
            box-shadow: none; margin: 0 auto;
        }
        h2 {
            margin-top: 0; color: var(--grid-navy); border-bottom: 1px solid var(--grid-border);
            padding-bottom: 10px; text-transform: uppercase; letter-spacing: 0.6px; font-size: 20px;
        }
        h3 {
            font-size: 14px; color: var(--grid-navy); margin-top: 30px; margin-bottom: 15px;
            display: flex; align-items: center; gap: 10px;
            text-transform: uppercase; letter-spacing: 0.4px; font-weight: 700;
        }

        /* Page switcher — square tabs, same button language as the
           admin toolbar (navy = selected, white with slate border = not) */
        .page-switcher {
            display: flex;
            gap: 8px;
            margin-bottom: 30px;
            border-bottom: 1px solid var(--grid-border);
            padding-bottom: 14px;
            flex-wrap: wrap;
            align-items: center;
        }
        .switch-page-btn {
            background: #fff;
            border: 1px solid var(--grid-border);
            padding: 10px 18px;
            font-size: 12px;
            font-weight: 600;
            color: var(--grid-navy);
            cursor: pointer;
            border-radius: 0;
            text-transform: uppercase;
            letter-spacing: 0.4px;
            transition: background 0.2s, color 0.2s, opacity 0.2s;
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            display: inline-flex;
            align-items: center;
        }
        .switch-page-btn i { margin-right: 8px; }
        .switch-page-btn.active {
            background: var(--grid-navy);
            border-color: var(--grid-navy);
            color: white;
            box-shadow: none;
        }
        .switch-page-btn:not(.active):hover {
            background: #f3f4f7;
            color: var(--grid-navy);
        }
        .switch-page-btn:focus-visible { outline: 2px solid var(--grid-navy); outline-offset: 2px; }
        .page-content { display: none; animation: fadeIn 0.25s ease-out; }
        .page-content.active-page { display: block; }
        @keyframes fadeIn {
            from { opacity: 0; transform: translateY(6px); }
            to   { opacity: 1; transform: translateY(0); }
        }

        /* Accomplishment Form button — styled like the admin
           "Export to Excel" / "Import Students" toolbar buttons */
        .accom-preview-btn {
            display: inline-flex;
            align-items: center;
            gap: 10px;
            background: #fff;
            border: 1px solid var(--grid-border);
            padding: 7px 14px 7px 8px;
            border-radius: 0;
            cursor: pointer;
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            text-decoration: none;
            margin-left: auto;
            transition: background 0.2s;
            flex-shrink: 0;
        }
        .accom-preview-btn:hover { background: #f3f4f7; box-shadow: none; }
        .accom-preview-btn:active { box-shadow: none; }
        .accom-preview-btn:focus-visible { outline: 2px solid var(--grid-navy); outline-offset: 2px; }
        .accom-btn-icon-block {
            width: 30px; height: 30px; border-radius: 0;
            background: var(--grid-navy);
            display: flex; align-items: center; justify-content: center;
            flex-shrink: 0; transition: opacity 0.2s;
        }
        .accom-preview-btn:hover .accom-btn-icon-block { background: var(--grid-navy); opacity: 0.9; }
        .accom-btn-icon-block i { font-size: 14px; color: #fff; }
        .accom-btn-text {
            display: flex; flex-direction: column; align-items: flex-start; gap: 1px;
        }
        .accom-btn-text strong {
            font-size: 12px; font-weight: 700; color: var(--grid-navy);
            text-transform: uppercase; letter-spacing: 0.4px;
            line-height: 1.2; white-space: nowrap;
        }
        .accom-btn-text span {
            font-size: 11px; color: var(--grid-muted); line-height: 1.2; white-space: nowrap;
        }
        .accom-btn-chevron { font-size: 12px; color: var(--grid-muted); margin-left: 2px; flex-shrink: 0; }

        .profile-header {
            display: flex; gap: 30px; background: #fff; padding: 20px;
            border-radius: 0; border: 1px solid var(--grid-border); margin-bottom: 30px;
        }
        .profile-header h3 { margin-top: 0; }
        .student-details { flex: 2; }
        .photo-section { flex: 1; text-align: center; border-left: 1px solid var(--grid-border-soft); padding-left: 30px; }
        .photo-section h3 { justify-content: center; }

        input:not([type="file"]) {
            width: 100%; padding: 10px 12px; margin-bottom: 15px; border: 1px solid var(--grid-border);
            border-radius: 0; box-sizing: border-box; font-size: 13.5px; color: var(--text);
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            transition: border-color 0.2s, box-shadow 0.2s;
        }
        input:not([type="file"]):focus {
            outline: none; border-color: var(--grid-navy); box-shadow: 0 0 0 3px rgba(27,42,74,0.08);
        }
        input[readonly] { background: #F3F5F9; color: var(--grid-muted); cursor: not-allowed; }

        /* Form sections — flat white panels with a slate border */
        .profile-info-section {
            background: #fff; border: 1px solid var(--grid-border); border-radius: 0;
            padding: 24px 28px 28px; margin-bottom: 30px;
        }
        .profile-info-section h3 {
            margin-top: 0; font-size: 14px; color: var(--grid-navy);
            border-bottom: 1px solid var(--grid-border); padding-bottom: 12px; margin-bottom: 20px;
            display: flex; align-items: center; gap: 10px;
            text-transform: uppercase; letter-spacing: 0.4px;
        }
        .profile-info-section h3 .sec-badge {
            background: var(--grid-navy); color: #fff;
            min-width: 26px; height: 24px; padding: 0 6px; border-radius: 0;
            display: inline-flex; align-items: center; justify-content: center;
            font-size: 11px; font-weight: 700; flex-shrink: 0; letter-spacing: 0.3px;
        }

        .pinfo-grid { display: grid; gap: 14px 20px; }
        .pinfo-grid.cols-2 { grid-template-columns: 1fr 1fr; }
        .pinfo-grid.cols-3 { grid-template-columns: 1fr 1fr 1fr; }
        .pinfo-grid.cols-4 { grid-template-columns: 1fr 1fr 1fr 1fr; }
        .pinfo-grid.cols-name { grid-template-columns: 1fr 1fr 1fr; }
        .pinfo-grid .full  { grid-column: 1 / -1; }

        .pinfo-field { display: flex; flex-direction: column; gap: 6px; }
        .pinfo-field label {
            font-size: 11px; font-weight: 600; color: var(--grid-navy);
            text-transform: uppercase; letter-spacing: 0.5px;
        }
        .pinfo-field input:not([type="file"]),
        .pinfo-field select,
        .pinfo-field textarea {
            width: 100%; padding: 9px 12px; border: 1px solid var(--grid-border);
            border-radius: 0; font-size: 13.5px; color: var(--text);
            background: #fff; transition: border-color 0.2s, box-shadow 0.2s;
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif; margin-bottom: 0;
        }
        .pinfo-field input:focus,
        .pinfo-field select:focus,
        .pinfo-field textarea:focus {
            outline: none; border-color: var(--grid-navy);
            box-shadow: 0 0 0 3px rgba(27,42,74,0.08);
        }
        .pinfo-field input[type="text"]:not([readonly]),
        .pinfo-field input[type="number"]:not([readonly]),
        .pinfo-field textarea { text-transform: capitalize; }
        .pinfo-field input[readonly] { background: #F3F5F9; color: var(--grid-muted); cursor: not-allowed; }
        .pinfo-field textarea[readonly] { background: #F3F5F9; color: var(--grid-muted); cursor: not-allowed; }
        .pinfo-field textarea { resize: vertical; min-height: 72px; line-height: 1.5; }
        .pinfo-field select {
            appearance: none; -webkit-appearance: none;
            background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='12' height='12' viewBox='0 0 12 12'%3E%3Cpath fill='%231B2A4A' d='M6 8L1 3h10z'/%3E%3C/svg%3E");
            background-repeat: no-repeat; background-position: right 12px center;
            padding-right: 32px; cursor: pointer;
        }

        /* Sub-section titles (Mother's Name, Father's Name, Guardian, ...)
           UPDATED: the coloured left bar was removed — a plain uppercase
           navy label with a thin rule underneath, like the admin page. */
        .pinfo-subsection-title {
            font-size: 11px; font-weight: 700; color: var(--grid-navy);
            text-transform: uppercase; letter-spacing: 0.5px;
            margin-bottom: 12px; margin-top: 4px;
            padding: 0 0 6px 0; border-left: none;
            border-bottom: 1px solid var(--grid-border-soft);
        }

        .opt-label {
            font-size: 10px; font-weight: 400; color: var(--grid-muted);
            text-transform: none; letter-spacing: 0; margin-left: 4px;
        }

        .pinfo-save-btn {
            display: inline-flex; align-items: center; gap: 8px;
            background: var(--grid-navy); color: #fff;
            border: 1px solid var(--grid-navy); border-radius: 0; padding: 10px 24px;
            font-size: 12px; font-weight: 600; cursor: pointer;
            text-transform: uppercase; letter-spacing: 0.4px;
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            transition: opacity 0.2s; margin-top: 18px;
        }
        .pinfo-save-btn:hover { opacity: 0.9; transform: none; }
        .pinfo-save-btn:disabled { opacity: 0.65; cursor: not-allowed; transform: none; }
        .pinfo-save-btn:focus-visible { outline: 2px solid var(--grid-navy); outline-offset: 2px; }

        /* Notes — same flat alert style as the admin page's .alert */
        .pinfo-company-note {
            display: flex; align-items: flex-start; gap: 8px;
            background: var(--grid-amber-bg); border: 1px solid #E6D9A8;
            border-radius: 0; padding: 10px 14px;
            font-size: 12px; color: #6E5B07; margin-bottom: 16px; line-height: 1.5;
        }
        .pinfo-company-note i { margin-top: 2px; flex-shrink: 0; color: var(--grid-amber); }

        .pinfo-company-note.locked-note {
            background: #E7ECF7; border-color: var(--grid-border); color: var(--grid-navy);
        }
        .pinfo-company-note.locked-note i { color: var(--grid-navy); }

        .photo-upload-wrap {
            display: flex; align-items: flex-start; gap: 20px;
            background: var(--grid-bg); border: 1px solid var(--grid-border);
            border-radius: 0; padding: 18px; margin-bottom: 20px;
        }
        .photo-upload-preview-area { flex-shrink: 0; text-align: center; }
        .photo-upload-preview-area img {
            width: 100px; height: 100px; object-fit: cover;
            border-radius: 0; border: 1px solid var(--grid-border);
            display: block; margin-bottom: 6px; cursor: pointer;
        }
        .photo-upload-preview-area .photo-placeholder {
            width: 100px; height: 100px; border-radius: 0;
            background: #fff; border: 1px dashed var(--grid-border);
            display: flex; flex-direction: column;
            align-items: center; justify-content: center; gap: 5px;
            color: var(--grid-muted); font-size: 11px; font-weight: 600;
            text-transform: uppercase; letter-spacing: 0.3px;
        }
        .photo-upload-preview-area .photo-placeholder i { font-size: 26px; color: var(--grid-border); }
        .photo-upload-info { flex: 1; }
        .photo-upload-info .photo-status-row { display: flex; align-items: center; gap: 8px; margin-bottom: 8px; }
        .photo-upload-info label.photo-upload-label {
            font-size: 11px; font-weight: 600; color: var(--grid-navy);
            text-transform: uppercase; letter-spacing: 0.5px;
            display: block; margin-bottom: 8px;
        }
        .photo-upload-info input[type="file"] {
            width: 100%; font-size: 12px;
            border: 1px dashed var(--grid-border); border-radius: 0;
            padding: 8px 10px; background: #fff; cursor: pointer;
        }
        .photo-upload-info .photo-hint { font-size: 11px; color: var(--grid-muted); margin-top: 6px; line-height: 1.5; }
        .photo-remark-badge-inline {
            display: inline-flex; align-items: flex-start; gap: 5px;
            background: var(--grid-red-bg); border: 1px solid #e3bcbc; color: var(--grid-red);
            padding: 6px 10px; border-radius: 0; font-size: 11px; font-weight: 600;
            margin-top: 6px; text-align: left; line-height: 1.4; width: 100%;
        }
        .reupload-label-inline {
            display: inline-flex; align-items: center; gap: 4px;
            font-size: 11px; color: var(--grid-red); font-weight: 700; margin-bottom: 5px;
            text-transform: uppercase; letter-spacing: 0.3px;
        }

        /* ══ REQUIREMENT CARDS ══ */
        .requirements-container {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(200px, 1fr));
            gap: 16px;
            margin-top: 20px;
        }

        .req-card-e {
            background: #fff;
            border: 1px solid var(--grid-border);
            border-radius: 0;
            overflow: visible;
            display: flex;
            flex-direction: column;
            transition: border-color 0.2s, background 0.2s;
            position: relative;
        }
        .req-card-e:hover {
            border-color: var(--grid-navy);
            box-shadow: none;
            transform: none;
        }

        .req-card-e .rce-header {
            padding: 8px 10px;
            display: flex;
            align-items: center;
            gap: 6px;
            border-radius: 0;
            border-bottom: 1px solid var(--grid-border-soft);
        }
        .rce-header.hdr-pending  { background: var(--grid-amber-bg); }
        .rce-header.hdr-verified { background: var(--grid-green-bg); }
        .rce-header.hdr-denied   { background: var(--grid-red-bg); }

        .rce-header .rce-status-icon { font-size: 12px; }
        .rce-header.hdr-pending  .rce-status-icon { color: var(--grid-amber); }
        .rce-header.hdr-verified .rce-status-icon { color: var(--grid-green); }
        .rce-header.hdr-denied   .rce-status-icon { color: var(--grid-red); }

        .rce-header .rce-status-label {
            font-size: 10px;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            flex: 1;
        }
        .rce-header.hdr-pending  .rce-status-label { color: var(--grid-amber); }
        .rce-header.hdr-verified .rce-status-label { color: var(--grid-green); }
        .rce-header.hdr-denied   .rce-status-label { color: var(--grid-red); }

        .rce-info-btn {
            width: 22px; height: 22px; border-radius: 0;
            border: 1px solid var(--grid-border);
            background: #fff;
            display: flex; align-items: center; justify-content: center;
            cursor: pointer; font-size: 12px; color: var(--grid-navy);
            flex-shrink: 0; transition: background 0.15s, color 0.15s, border-color 0.15s;
            line-height: 1;
        }
        .rce-info-btn:hover,
        .rce-info-btn.info-active {
            background: var(--grid-navy); color: #fff; border-color: var(--grid-navy);
        }
        .rce-info-btn:focus-visible { outline: 2px solid var(--grid-navy); outline-offset: 2px; }

        .req-card-e .rce-body {
            padding: 14px 14px 16px;
            display: flex; flex-direction: column; gap: 8px; flex: 1;
        }
        .req-card-e .rce-title {
            font-size: 12px; font-weight: 700; color: var(--grid-navy); line-height: 1.35;
            text-transform: uppercase; letter-spacing: 0.3px;
        }

        .rce-bottom-action-row {
            display: flex; align-items: center; gap: 6px;
            margin-top: auto; padding-top: 4px; flex-wrap: nowrap;
        }

        .rce-preview-eye-btn {
            display: inline-flex; align-items: center; justify-content: center;
            background: #fff; color: var(--grid-navy); border: 1px solid var(--grid-border);
            border-radius: 0; width: 30px; height: 30px; font-size: 13px;
            cursor: pointer; flex-shrink: 0;
            transition: background 0.2s, color 0.2s;
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
        }
        .rce-preview-eye-btn:hover { background: var(--grid-navy); color: #fff; opacity: 1; transform: none; }
        .rce-preview-eye-btn.eye-hidden { display: none; }

        .rce-upload-icon { font-size: 12px; color: var(--grid-navy); flex-shrink: 0; }
        .rce-upload-label {
            font-size: 11px; color: var(--grid-navy); font-weight: 600;
            flex: 1; white-space: nowrap; overflow: hidden; text-overflow: ellipsis;
        }

        .rce-file-btn {
            display: inline-flex; align-items: center; gap: 5px;
            background: var(--grid-navy); color: #fff; border: 1px solid var(--grid-navy);
            border-radius: 0; padding: 6px 10px; font-size: 11px; font-weight: 600;
            text-transform: uppercase; letter-spacing: 0.3px;
            cursor: pointer; font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            transition: opacity 0.2s; white-space: nowrap; flex-shrink: 0;
        }
        .rce-file-btn:hover { opacity: 0.9; }
        .rce-file-input-hidden {
            position: absolute; width: 1px; height: 1px; opacity: 0; pointer-events: none;
        }

        .rce-upload-row {
            display: flex; align-items: center; gap: 6px;
            margin-top: auto; padding-top: 4px;
        }

        .req-card-e .rce-remark {
            display: flex; align-items: flex-start; gap: 5px;
            background: var(--grid-red-bg); border: 1px solid #e3bcbc;
            border-radius: 0; padding: 6px 8px;
            font-size: 11px; color: var(--grid-red); font-weight: 600; line-height: 1.4; margin-top: 2px;
        }
        .req-card-e .rce-remark i { margin-top: 1px; flex-shrink: 0; }
        .req-card-e .rce-reupload {
            font-size: 11px; color: var(--grid-red); font-weight: 700;
            text-transform: uppercase; letter-spacing: 0.3px;
            display: flex; align-items: center; gap: 4px; margin-top: 2px;
        }

        .rce-preview-container { text-align: center; }
        .rce-preview-img {
            width: 90px; height: 90px; object-fit: cover;
            border-radius: 0; margin-bottom: 6px;
            border: 1px solid var(--grid-border); cursor: pointer;
            display: block; margin-left: auto; margin-right: auto;
        }
        .rce-pdf-thumb {
            width: 90px; height: 90px;
            background: var(--grid-red-bg); border: 1px solid #e3bcbc;
            border-radius: 0;
            display: flex; flex-direction: column;
            align-items: center; justify-content: center;
            gap: 4px; margin: 0 auto 6px; cursor: default;
        }
        .rce-pdf-thumb i { font-size: 26px; color: var(--grid-red); }
        .rce-pdf-thumb span { font-size: 10px; color: var(--grid-red); font-weight: 700; letter-spacing: 0.4px; }

        @keyframes rce-status-flash {
            0%   { outline: 2px solid transparent; }
            25%  { outline: 2px solid #F7C600; box-shadow: 0 0 0 5px rgba(247,198,0,0.35); }
            75%  { outline: 2px solid #F7C600; box-shadow: 0 0 0 5px rgba(247,198,0,0.35); }
            100% { outline: 2px solid transparent; box-shadow: none; }
        }
        .rce-status-updated { animation: rce-status-flash 1.4s ease-in-out; }

        /* ══ "How to submit this form" popup ══ */
        .rce-info-overlay {
            display: none; position: fixed; inset: 0; z-index: 50000;
            background: rgba(27, 42, 74, 0.45);
            align-items: center; justify-content: center; padding: 20px;
        }
        .rce-info-overlay.popup-open { display: flex; }
        @keyframes info-overlay-pop {
            from { opacity: 0; transform: scale(0.9); }
            to   { opacity: 1; transform: scale(1); }
        }
        .rce-info-modal {
            background: #fff; border-radius: 0; border: 1px solid var(--grid-border);
            width: 100%; max-width: 680px; max-height: 90vh; overflow-y: auto;
            box-shadow: 0 20px 60px rgba(0,0,0,0.25);
            animation: info-overlay-pop 0.3s ease both;
        }
        .rip-head {
            background: var(--grid-navy); padding: 16px 20px;
            display: flex; align-items: center; gap: 12px;
            border-radius: 0; position: sticky; top: 0; z-index: 1;
        }
        .rip-head-icon {
            width: 34px; height: 34px; border-radius: 0;
            background: rgba(255,255,255,0.12);
            display: flex; align-items: center; justify-content: center;
            font-size: 16px; color: #F7C600; flex-shrink: 0;
        }
        .rip-head-text { flex: 1; min-width: 0; }
        .rip-head-text strong {
            display: block; font-size: 14px; color: #fff; font-weight: 700; line-height: 1.2;
            text-transform: uppercase; letter-spacing: 0.4px;
        }
        .rip-head-text span   { display: block; font-size: 12px; color: rgba(255,255,255,0.72); margin-top: 3px; }
        .rip-close-btn {
            width: 30px; height: 30px; border-radius: 0;
            background: transparent; border: 1px solid rgba(255,255,255,0.25); cursor: pointer;
            display: flex; align-items: center; justify-content: center;
            font-size: 14px; color: rgba(255,255,255,0.85);
            transition: background 0.15s; flex-shrink: 0;
        }
        .rip-close-btn:hover { background: rgba(255,255,255,0.14); color: #fff; }
        .rip-close-btn:focus-visible { outline: 2px solid #F7C600; outline-offset: 2px; }
        .rip-body { padding: 20px 22px 22px; display: flex; flex-direction: column; gap: 0; }
        .rip-steps-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 14px 18px; margin-bottom: 16px; }
        .rip-step {
            display: flex; gap: 10px; align-items: flex-start;
            background: var(--grid-bg); border: 1px solid var(--grid-border);
            border-radius: 0; padding: 12px 13px;
        }
        .rip-step-num {
            width: 24px; height: 24px; border-radius: 0;
            background: var(--grid-navy); color: #fff;
            display: flex; align-items: center; justify-content: center;
            font-size: 12px; font-weight: 700; flex-shrink: 0; margin-top: 1px;
        }
        .rip-step-content { flex: 1; min-width: 0; }
        .rip-step-title {
            font-size: 12px; font-weight: 700; color: var(--grid-navy);
            text-transform: uppercase; letter-spacing: 0.3px;
            display: flex; align-items: center; gap: 6px; margin-bottom: 5px;
        }
        .rip-step-title i { font-size: 12px; color: var(--grid-navy); }
        .rip-step-desc { font-size: 12.5px; color: #475569; line-height: 1.6; }
        .rip-warning {
            background: var(--grid-amber-bg); border: 1px solid #E6D9A8;
            border-radius: 0; padding: 12px 14px;
            display: flex; gap: 10px; align-items: flex-start; margin-top: 2px;
        }
        .rip-warning i { font-size: 14px; color: var(--grid-amber); flex-shrink: 0; margin-top: 2px; }
        .rip-warning p { font-size: 13px; color: #6E5B07; line-height: 1.55; margin: 0; }

        /* ══ STATUS TAGS (flat, square — same colours as the admin badges) ══ */
        .status {
            display: inline-block; padding: 4px 10px; border-radius: 0; border: 1px solid;
            font-size: 11px; font-weight: 700; text-transform: uppercase; letter-spacing: 0.4px; margin-bottom: 10px;
        }
        .status.pending  { background: var(--grid-amber-bg); color: var(--grid-amber); border-color: #E6D9A8; }
        .status.denied   { background: var(--grid-red-bg);   color: var(--grid-red);   border-color: #e3bcbc; }
        .status.approved { background: var(--grid-green-bg); color: var(--grid-green); border-color: #bfe0bf; }
        .status.verified { background: var(--grid-green-bg); color: var(--grid-green); border-color: #bfe0bf; }

        .remark-badge {
            display: inline-flex; align-items: center; gap: 5px;
            background: var(--grid-red-bg); border: 1px solid #e3bcbc; color: var(--grid-red);
            padding: 5px 10px; border-radius: 0; font-size: 11px; font-weight: 600;
            margin-top: 6px; text-align: left; line-height: 1.4;
        }
        .photo-remark-badge {
            display: inline-flex; align-items: flex-start; gap: 5px;
            background: var(--grid-red-bg); border: 1px solid #e3bcbc; color: var(--grid-red);
            padding: 6px 10px; border-radius: 0; font-size: 12px; font-weight: 600;
            margin-top: 8px; text-align: left; line-height: 1.4;
        }
        .reupload-label {
            display: block; font-size: 11px; color: var(--grid-red); font-weight: 700; margin-bottom: 5px;
            text-transform: uppercase; letter-spacing: 0.3px;
        }

        .preview { width: 100px; height: 100px; object-fit: cover; border-radius: 0; margin-bottom: 10px; border: 1px solid var(--grid-border); cursor: pointer; }

        button { cursor: pointer; border: none; border-radius: 0; font-weight: bold; transition: 0.3s; }
        .submit-all {
            background: var(--grid-navy); color: white; width: 100%; padding: 15px;
            font-size: 13px; font-weight: 600; text-transform: uppercase; letter-spacing: 0.6px;
            margin-top: 36px; box-shadow: none; border: 1px solid var(--grid-navy);
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
        }
        .submit-all:focus-visible { outline: 2px solid var(--grid-navy); outline-offset: 2px; }
        button:hover { opacity: 0.9; }

        /* ══════════════════════════════════════════════
           FULL-SCREEN DOCUMENT PREVIEW (SIT / Waiver / Contract)
           Behaviour unchanged (open/close/preview URL) — only the
           toolbar was restyled to the square navy look.
           ══════════════════════════════════════════════ */
        .fullscreen-doc-overlay {
            display: none; position: fixed; inset: 0; z-index: 20000;
            background: rgba(27,42,74,0.72);
            flex-direction: column; overflow: hidden;
        }
        .fullscreen-doc-overlay.open { display: flex; }

        .fullscreen-doc-toolbar {
            background: var(--grid-navy);
            padding: 0.6rem 1.5rem;
            display: flex; align-items: center; justify-content: space-between;
            flex-shrink: 0; gap: 1rem; flex-wrap: wrap;
            border-bottom: 1px solid #55668C;
        }
        .fullscreen-doc-toolbar-left {
            display: flex; align-items: center; gap: 12px; min-width: 0;
        }
        .fullscreen-doc-toolbar-left i.fdt-icon {
            font-size: 18px; color: #F7C600; flex-shrink: 0;
        }
        .fullscreen-doc-title-group { min-width: 0; }
        .fullscreen-doc-title {
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            font-size: 13px; font-weight: 700; color: #fff;
            text-transform: uppercase; letter-spacing: 0.5px;
            white-space: nowrap; overflow: hidden; text-overflow: ellipsis;
        }
        .fullscreen-doc-subtitle {
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            font-size: 11px; color: rgba(255,255,255,0.65); margin-top: 1px;
        }
        .fullscreen-doc-toolbar-right {
            display: flex; align-items: center; gap: 8px; flex-shrink: 0;
        }
        .fs-tbtn {
            display: inline-flex; align-items: center; gap: 0.4rem;
            padding: 8px 16px; border-radius: 0;
            font-size: 12px; font-weight: 600; cursor: pointer;
            text-transform: uppercase; letter-spacing: 0.4px;
            border: 1px solid transparent; font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            transition: opacity 0.15s, background 0.15s;
        }
        .fs-tbtn-primary { background: #fff; color: var(--grid-navy); border-color: #fff; }
        .fs-tbtn-primary:hover { opacity: 0.88; }
        .fs-tbtn-close {
            background: transparent; color: #fff;
            border: 1px solid rgba(255,255,255,0.35);
        }
        .fs-tbtn-close:hover { background: rgba(255,255,255,0.14); color: #fff; }
        .fs-tbtn:focus-visible { outline: 2px solid #F7C600; outline-offset: 2px; }

        .fullscreen-doc-body {
            flex: 1; min-height: 0; background: var(--grid-bg);
            display: flex;
        }
        .fullscreen-doc-body iframe {
            flex: 1; width: 100%; height: 100%; border: none;
            display: block; background: #fff;
        }

        @media (max-width: 768px) {
            .fullscreen-doc-toolbar { padding: 0.55rem 1rem; }
            .fullscreen-doc-subtitle { display: none; }
            .fs-tbtn span { display: none; }
            .fs-tbtn { padding: 8px 10px; }
        }

        #sitPreviewModal,
        #waiverPreviewModal,
        #contractPreviewModal {
            display: none; position: fixed; inset: 0; z-index: 20000;
            flex-direction: column;
        }
        #sitPreviewModal.open,
        #waiverPreviewModal.open,
        #contractPreviewModal.open { display: flex; }

        #imagePreviewModal {
            display:none; position:fixed; top:0; left:0; width:100%; height:100%;
            background:rgba(27,42,74,0.9); justify-content:center; align-items:center; z-index:10000;
        }

        /* ══ "Page Not Accessible" popup — same box as the admin modals ══ */
        #not-deployed-modal {
            display: none; position: fixed; inset: 0; z-index: 99999;
            background: rgba(0,0,0,0.5);
            align-items: center; justify-content: center;
        }
        #not-deployed-modal.show { display: flex; }
        .ndm-box {
            background: #fff; border-radius: 0; border: 1px solid var(--grid-border);
            padding: 32px; max-width: 420px; width: calc(100% - 40px);
            text-align: center;
            animation: ndm-pop 0.3s ease both;
        }
        @keyframes ndm-pop {
            from { opacity:0; transform:scale(0.9); }
            to   { opacity:1; transform:scale(1); }
        }
        .ndm-icon {
            width: auto; height: auto; border-radius: 0;
            background: none; border: none;
            display: flex; align-items: center; justify-content: center;
            font-size: 48px; color: var(--grid-amber); margin: 0 auto 16px;
        }
        .ndm-title {
            font-size: 18px; font-weight: 700; color: #1e293b; margin-bottom: 12px;
            text-transform: uppercase; letter-spacing: 0.3px;
        }
        .ndm-message { font-size: 14px; color: var(--grid-muted); line-height: 1.6; margin-bottom: 24px; }
        .ndm-page-name {
            display: inline-block; background: var(--grid-bg); border: 1px solid var(--grid-border);
            border-radius: 0; padding: 4px 12px; font-weight: 700;
            color: var(--grid-navy); font-size: 12px; margin-bottom: 14px;
            text-transform: uppercase; letter-spacing: 0.4px;
        }
        .ndm-status-badge {
            display: flex; align-items: center; justify-content: center; gap: 6px;
            width: fit-content; margin-left: auto; margin-right: auto;
            background: var(--grid-amber-bg); border: 1px solid #E6D9A8;
            border-radius: 0; padding: 5px 14px;
            font-size: 11px; font-weight: 700; color: var(--grid-amber); margin-bottom: 20px;
            text-transform: uppercase; letter-spacing: 0.4px;
        }
        .ndm-status-dot { width: 8px; height: 8px; border-radius: 50%; background: var(--grid-amber); flex-shrink: 0; }
        .ndm-close-btn {
            background: var(--grid-navy); color: white; border: 1px solid var(--grid-navy);
            border-radius: 0; padding: 10px 24px; font-size: 12px; font-weight: 600;
            text-transform: uppercase; letter-spacing: 0.4px;
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif; cursor: pointer; transition: opacity 0.2s; width: 100%;
        }
        .ndm-close-btn:hover { opacity: 0.88; }
        .ndm-hint { font-size: 11.5px; color: var(--grid-muted); margin-top: 12px; }

        /* ══ Notification popups (verified / submitted / saved / status /
           validation) — same square modal as admin_student_list.php ══ */
        .notif-modal-overlay {
            display: none; position: fixed; top: 0; left: 0;
            width: 100%; height: 100%; background: rgba(0,0,0,0.5);
            justify-content: center; align-items: center; z-index: 9999;
            animation: fadeInModal 0.25s ease;
        }
        @keyframes fadeInModal { from { opacity:0; } to { opacity:1; } }
        .notif-modal-box {
            background: white; padding: 32px; border-radius: 0; border: 1px solid var(--grid-border);
            width: 420px; max-width: 92%; text-align: center;
            box-shadow: none;
            animation: popIn 0.3s ease;
        }
        @keyframes popIn { from { transform:scale(0.9); opacity:0; } to { transform:scale(1); opacity:1; } }
        .notif-modal-icon  { font-size: 48px; margin-bottom: 16px; display: block; color: var(--grid-green); }
        .notif-modal-icon:empty { display: none; }
        .notif-modal-icon.icon-denied  { color: var(--grid-red); }
        .notif-modal-icon.icon-pending { color: var(--grid-amber); }
        .notif-modal-title {
            font-size: 18px; font-weight: 700; color: #1e293b; margin: 0 0 8px;
            text-transform: uppercase; letter-spacing: 0.3px;
        }
        .notif-modal-msg   { color: var(--grid-muted); font-size: 14px; margin: 0 0 24px; line-height: 1.6; }
        .notif-modal-btn   {
            background: var(--grid-navy); color: #fff;
            border: 1px solid var(--grid-navy); padding: 10px 28px; border-radius: 0;
            font-weight: 600; font-size: 12px; cursor: pointer; transition: opacity 0.2s;
            text-transform: uppercase; letter-spacing: 0.4px;
        }
        .notif-modal-btn:hover { opacity: 0.88; }
        .notif-modal-btn:focus-visible { outline: 2px solid var(--grid-navy); outline-offset: 2px; }
        .notif-modal-box.error-modal .notif-modal-icon  { color: var(--grid-red); }
        .notif-modal-box.error-modal .notif-modal-title { color: var(--grid-red); }
        .notif-modal-box.error-modal .notif-modal-btn   { background: var(--grid-red); border-color: var(--grid-red); color: white; }
        .error-detail-list {
            text-align: left; background: var(--grid-red-bg); border: 1px solid #e3bcbc;
            border-radius: 0; padding: 12px 16px 12px 32px; margin-bottom: 18px;
            font-size: 13px; color: var(--grid-red); line-height: 1.8;
        }
        .error-detail-list li { margin-bottom: 2px; }

        #guardianOtherWrap { display: none; grid-column: 1 / -1; }
        #guardianOtherWrap.show {
            display: grid; grid-template-columns: 1fr 1fr 1fr; gap: 14px 20px;
        }

        @media (max-width: 768px) {
            .pinfo-grid.cols-2,
            .pinfo-grid.cols-3,
            .pinfo-grid.cols-4,
            .pinfo-grid.cols-name { grid-template-columns: 1fr; }
            .pinfo-grid .full  { grid-column: auto; }
            #guardianOtherWrap.show { grid-template-columns: 1fr; }
            .photo-upload-wrap { flex-direction: column; }
            .requirements-container { grid-template-columns: repeat(auto-fill, minmax(160px, 1fr)); }
            .rip-steps-grid { grid-template-columns: 1fr; }
            .rce-info-modal { max-width: 100%; }
            .rce-bottom-action-row { flex-wrap: wrap; }
            .accom-preview-btn { margin-left: 0; }
            .accom-btn-text span { display: none; }
            .profile-header { flex-direction: column; }
            .photo-section { border-left: none; padding-left: 0; border-top: 1px solid var(--grid-border-soft); padding-top: 20px; }
            .switch-page-btn { flex: 1; justify-content: center; }
        }

        @media (prefers-reduced-motion: reduce) {
            .page-content, .rce-info-modal, .ndm-box, .notif-modal-box, .notif-modal-overlay { animation: none; }
        }
    </style>
    <?php echo accom_modal_css(); ?>
</head>
<body>

<div id="sidebar" class="sidebar">
    <div class="sidebar-header">
        <div class="sidebar-user-info">
            <span class="sidebar-user-name"><?php echo htmlspecialchars($fullname); ?></span>
            <span class="sidebar-user-role">OJT Trainee</span>
        </div>
        <button id="toggleBtn" class="toggle-btn"><i class="fas fa-bars"></i></button>
    </div>

    <?php $current_page = basename($_SERVER['PHP_SELF']); ?>

    <?php if (!$all_verified): ?>
    <div class="sidebar-lock-notice">
        <div class="sidebar-lock-notice-inner">
            <i class="fas fa-lock"></i>
            <p>Some pages are locked until all requirements are verified by the administrator.</p>
        </div>
    </div>
    <?php endif; ?>

    <div class="sidebar-links" id="sidebarLinksContainer">
        <!-- ADJUSTMENT: "My Profile" is now ALWAYS shown, regardless of
             $all_verified. Previously this link was wrapped in
             `if ($all_verified)`, which hid student_profile.php from the
             sidebar until every one of the 8 requirement types was
             Verified by the administrator. Per updated requirements this
             link is unlocked unconditionally — the Attendance / Reports /
             Dashboard links directly below remain governed by the exact
             same $all_verified (and, further, $is_deployed) gates as
             before; nothing about those was touched. -->
        <a href="student_profile.php" class="<?= $current_page == 'student_profile.php' ? 'active' : '' ?>">
            <i class="fas fa-user-circle"></i>
            <span class="link-text">My Profile</span>
        </a>

        <a href="company_list.php" class="<?= $current_page == 'company_list.php' ? 'active' : '' ?>">
            <i class="fas fa-building"></i>
            <span class="link-text">Company List</span>
        </a>

        <a href="AccomForm.php" class="<?= $current_page == 'AccomForm.php' ? 'active' : '' ?>">
            <i class="fas fa-file-contract"></i>
            <span class="link-text">Requirements</span>
        </a>

        <?php if ($all_verified): ?>
            <?php if ($is_deployed): ?>
            <a href="student_attendance.php" data-nav-key="attendance" class="<?= $current_page == 'student_attendance.php' ? 'active' : '' ?>">
                <i class="fas fa-calendar-check"></i>
                <span class="link-text">Attendance</span>
                <?php if (!empty($att_sidebar_badge)): ?>
                    <span class="sidebar-badge-att">!</span>
                <?php endif; ?>
            </a>
            <a href="student_report.php" data-nav-key="report" class="<?= $current_page == 'student_report.php' ? 'active' : '' ?>">
                <i class="fas fa-chart-bar"></i>
                <span class="link-text">Reports</span>
                <span class="sidebar-badge-journal" id="journalEmptyBadge" style="display:none;"></span>
            </a>
            <a href="student_dashboard.php" data-nav-key="dashboard" class="<?= $current_page == 'student_dashboard.php' ? 'active' : '' ?>">
                <i class="fas fa-tachometer-alt"></i>
                <span class="link-text">Dashboard</span>
            </a>
            <?php else: ?>
            <a href="#" class="nav-locked" data-nav-key="attendance" onclick="showNotDeployedModal('Attendance', event)">
                <i class="fas fa-calendar-check"></i>
                <span class="link-text">Attendance</span>
                <i class="fas fa-lock nav-lock-icon"></i>
            </a>
            <a href="#" class="nav-locked" data-nav-key="report" onclick="showNotDeployedModal('Reports', event)">
                <i class="fas fa-chart-bar"></i>
                <span class="link-text">Reports</span>
                <i class="fas fa-lock nav-lock-icon"></i>
            </a>
            <a href="#" class="nav-locked" data-nav-key="dashboard" onclick="showNotDeployedModal('Dashboard', event)">
                <i class="fas fa-tachometer-alt"></i>
                <span class="link-text">Dashboard</span>
                <i class="fas fa-lock nav-lock-icon"></i>
            </a>
            <?php endif; ?>
        <?php endif; ?>
    </div>

    <div class="logout-link">
        <a href="login.php">
            <i class="fas fa-sign-out-alt"></i>
            <span class="link-text" style="margin-left:10px;">Logout</span>
        </a>
    </div>
</div>

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

<div id="not-deployed-modal">
    <div class="ndm-box">
        <div class="ndm-icon"><i class="fas fa-lock"></i></div>
        <div class="ndm-title">Page Not Accessible</div>
        <div class="ndm-page-name" id="ndm-page-label">—</div>
        <div class="ndm-status-badge">
            <span class="ndm-status-dot"></span>
            Status: Not Yet Deployed
        </div>
        <div class="ndm-message">
            You need to be <strong>deployed to a company</strong> before you can access this page. Please send your application first and wait to be assigned to a partner company.
        </div>
        <button class="ndm-close-btn" onclick="closeNotDeployedModal()">Got it</button>
        <div class="ndm-hint">Contact your OJT coordinator for deployment updates.</div>
    </div>
</div>

<div id="imagePreviewModal" onclick="this.style.display='none'">
    <img id="previewImage" style="max-width:80%; max-height:80%; border-radius:0; border:1px solid #C3CADA; box-shadow:0 0 20px rgba(0,0,0,0.5);">
</div>

<!-- ══════════════════════════════════════════════════════════════
     FULL-SCREEN DOCUMENT PREVIEWS (SIT / Waiver / Contract)
     ------------------------------------------------------------
     Full-screen toolbar+document layout matching the OJT/Internship
     Training Plan preview on company_reports.php. Same preview URLs
     (sit_preview=1 / waiver_preview=1 / contract_preview=1) are
     loaded into the iframe exactly as before. Per updated
     requirements, the Print action has been removed from each
     toolbar — only Close remains.
     ══════════════════════════════════════════════════════════════ -->
<div id="sitPreviewModal" class="fullscreen-doc-overlay">
    <div class="fullscreen-doc-toolbar">
        <div class="fullscreen-doc-toolbar-left">
            <i class="fas fa-file-signature fdt-icon"></i>
            <div class="fullscreen-doc-title-group">
                <div class="fullscreen-doc-title">Application SIT</div>
                <div class="fullscreen-doc-subtitle">Preview</div>
            </div>
        </div>
        <div class="fullscreen-doc-toolbar-right">
            <button class="fs-tbtn fs-tbtn-close" onclick="closeSITModal()"><i class="fas fa-times"></i> <span>Close</span></button>
        </div>
    </div>
    <div class="fullscreen-doc-body">
        <iframe id="sitPreviewIframe" src="" title="SIT Application Form"></iframe>
    </div>
</div>

<div id="waiverPreviewModal" class="fullscreen-doc-overlay">
    <div class="fullscreen-doc-toolbar">
        <div class="fullscreen-doc-toolbar-left">
            <i class="fas fa-shield-alt fdt-icon"></i>
            <div class="fullscreen-doc-title-group">
                <div class="fullscreen-doc-title">Waiver and Permission Form</div>
                <div class="fullscreen-doc-subtitle">Preview</div>
            </div>
        </div>
        <div class="fullscreen-doc-toolbar-right">
            <button class="fs-tbtn fs-tbtn-close" onclick="closeWAIVERModal()"><i class="fas fa-times"></i> <span>Close</span></button>
        </div>
    </div>
    <div class="fullscreen-doc-body">
        <iframe id="waiverPreviewIframe" src="" title="Waiver and Permission Form"></iframe>
    </div>
</div>

<div id="contractPreviewModal" class="fullscreen-doc-overlay">
    <div class="fullscreen-doc-toolbar">
        <div class="fullscreen-doc-toolbar-left">
            <i class="fas fa-file-contract fdt-icon"></i>
            <div class="fullscreen-doc-title-group">
                <div class="fullscreen-doc-title">Student/University Contract</div>
                <div class="fullscreen-doc-subtitle">Preview</div>
            </div>
        </div>
        <div class="fullscreen-doc-toolbar-right">
            <button class="fs-tbtn fs-tbtn-close" onclick="closeCONTRACTModal()"><i class="fas fa-times"></i> <span>Close</span></button>
        </div>
    </div>
    <div class="fullscreen-doc-body">
        <iframe id="contractPreviewIframe" src="" title="Student/University Contract"></iframe>
    </div>
</div>

<div id="verifiedModal" class="notif-modal-overlay">
    <div class="notif-modal-box">
        <span class="notif-modal-icon"><i class="fas fa-check-circle"></i></span>
        <p class="notif-modal-title">All Requirements Verified!</p>
        <p class="notif-modal-msg">Your requirements have been fully verified by the administrator. You now have full access to all features of the system.</p>
        <button id="closeVerifiedModal" class="notif-modal-btn">Great, Thanks!</button>
    </div>
</div>

<div id="validationModal" class="notif-modal-overlay">
    <div class="notif-modal-box error-modal">
        <span class="notif-modal-icon"><i class="fas fa-exclamation-circle"></i></span>
        <p class="notif-modal-title" id="validationModalTitle">Invalid File</p>
        <p class="notif-modal-msg" id="validationModalMsg">Please check the file(s) and try again.</p>
        <ul class="error-detail-list" id="validationErrorList" style="display:none;"></ul>
        <button id="closeValidationModal" class="notif-modal-btn">OK, Fix It</button>
    </div>
</div>

<div id="submittedModal" class="notif-modal-overlay">
    <div class="notif-modal-box">
        <span class="notif-modal-icon"><i class="fas fa-check-circle"></i></span>
        <p class="notif-modal-title">Submission Successful!</p>
        <p class="notif-modal-msg">Your requirements and documents have been submitted successfully. Please wait for the administrator to review them.</p>
        <button id="closeSubmittedModal" class="notif-modal-btn">OK, Got it!</button>
    </div>
</div>

<div id="profileSavedModal" class="notif-modal-overlay">
    <div class="notif-modal-box">
        <span class="notif-modal-icon"><i class="fas fa-check-circle"></i></span>
        <p class="notif-modal-title">Information Saved!</p>
        <p class="notif-modal-msg">Your personal, academic, and placement information has been saved successfully.</p>
        <button id="closeProfileSavedModal" class="notif-modal-btn">OK, Got it!</button>
    </div>
</div>

<div id="statusChangedModal" class="notif-modal-overlay">
    <div class="notif-modal-box">
        <span class="notif-modal-icon" id="statusChangedIcon"></span>
        <p class="notif-modal-title" id="statusChangedTitle">Requirement Status Updated</p>
        <p class="notif-modal-msg" id="statusChangedMsg">A requirement status has been updated by the administrator.</p>
        <button id="closeStatusChangedModal" class="notif-modal-btn">OK</button>
    </div>
</div>

<div class="main-content" id="mainContent">

    <nav class="navbar">
        <img src="logo.webp" style="height:40px;margin-right:15px;">
        <div>
            <div style="font-weight:bold; font-size:16px;">NEUST Atate Campus</div>
            <div style="font-size:11px; color:var(--neust-gold);">Web-Based Smart OJT Monitoring and Supervision Analytics System</div>
        </div>
    </nav>

    <div class="main-wrapper">
        <div class="card">

            <div class="page-switcher">
                <button class="switch-page-btn" data-page="digital-page">
                    <i class="fas fa-laptop-code"></i> Student Info
                </button>
                <button class="switch-page-btn" data-page="documents-page">
                    <i class="fas fa-folder-open"></i> Documentary Requirements
                </button>
                <?php echo accom_preview_button_custom(); ?>
            </div>

            <div id="digital-page" class="page-content">

                <form method="POST" action="AccomForm.php" id="profileInfoForm" enctype="multipart/form-data">
                    <input type="hidden" name="save_profile_info" value="1">
                    <input type="hidden" name="ajax_request" id="ajaxRequestFlag" value="1">

                    <div class="profile-header">
                        <div class="student-details">
                            <h3>Student Information</h3>
                            <input type="text" value="<?= htmlspecialchars($fullname) ?>" readonly>
                            <input type="text" value="<?= htmlspecialchars($course) ?>"   readonly>
                        </div>

                        <div class="photo-section">
                            <h3>2x2 Photo</h3>
                            <?php $photo_display = $photo_status ?? 'Pending'; ?>

                            <?php if ($photo): ?>
                                <img src="data:image/jpeg;base64,<?= base64_encode($photo) ?>" class="preview" onclick="openPreview(this.src)">
                            <?php endif; ?>
                            <br>
                            <span class="status <?= strtolower($photo_display) ?>"><?= $photo_display ?></span>

                            <?php if ($photo_display === 'Denied' && !empty($photo_remark)): ?>
                                <div class="photo-remark-badge">
                                    <i class="fas fa-exclamation-circle" style="margin-top:2px; flex-shrink:0;"></i>
                                    Reason: <?= htmlspecialchars($photo_remark) ?>
                                </div>
                            <?php endif; ?>

                            <?php if (!$photo || $photo_display === "Denied"): ?>
                                <?php if ($photo_display === "Denied"): ?>
                                    <span class="reupload-label" style="margin-top:8px;">
                                        <i class="fas fa-redo"></i> Re-upload required
                                    </span>
                                <?php endif; ?>
                                <div class="rce-file-input-wrap" style="margin-top:8px; justify-content:center;">
                                    <label class="rce-file-btn" id="profilePhotoBtnLabel" for="profilePhotoInput">
                                        <i class="fas fa-upload"></i>
                                        <span id="profilePhotoBtnText">Choose Photo</span>
                                    </label>
                                    <input type="file" name="student_photo" accept="image/jpeg"
                                           data-label="2x2 Photo" id="profilePhotoInput"
                                           class="rce-file-input-hidden"
                                           onchange="handleProfilePhotoChange(this)">
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>

                    <div class="profile-info-section">
                        <h3>
                            <span class="sec-badge">I</span>
                            Personal Data
                        </h3>

                        <div class="pinfo-grid cols-3" style="margin-bottom:14px;">
                            <div class="pinfo-field">
                                <label>Last Name</label>
                                <input type="text" value="<?= htmlspecialchars($last ?? '') ?>" readonly>
                            </div>
                            <div class="pinfo-field">
                                <label>First Name</label>
                                <input type="text" value="<?= htmlspecialchars($first ?? '') ?>" readonly>
                            </div>
                            <div class="pinfo-field">
                                <label>Middle Name</label>
                                <input type="text" value="<?= htmlspecialchars($middle ?? '') ?>" readonly>
                            </div>
                        </div>

                        <div class="pinfo-grid cols-4" style="margin-bottom:14px;">
                            <div class="pinfo-field">
                                <label>Age</label>
                                <input type="number" name="age" min="1" max="120"
                                       value="<?= htmlspecialchars($sit_extra['age'] ?? '') ?>"
                                       placeholder="e.g. 21">
                            </div>
                            <div class="pinfo-field">
                                <label>Sex</label>
                                <select name="sex">
                                    <option value="">— Select —</option>
                                    <?php foreach (['Male','Female'] as $opt): ?>
                                    <option value="<?= $opt ?>" <?= ($sit_extra['sex'] ?? '') === $opt ? 'selected' : '' ?>><?= $opt ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="pinfo-field">
                                <label>Civil Status</label>
                                <select name="civil_status">
                                    <option value="">— Select —</option>
                                    <?php foreach (['Single','Married','Widowed','Separated'] as $opt): ?>
                                    <option value="<?= $opt ?>" <?= ($sit_extra['civil_status'] ?? '') === $opt ? 'selected' : '' ?>><?= $opt ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="pinfo-field">
                                <label>Religion</label>
                                <input type="text" name="religion"
                                       value="<?= htmlspecialchars($sit_extra['religion'] ?? '') ?>"
                                       placeholder="e.g. Roman Catholic">
                            </div>
                        </div>

                        <div class="pinfo-grid cols-2" style="margin-bottom:14px;">
                            <div class="pinfo-field">
                                <label>Mobile Number</label>
                                <input type="text" name="mobile_no" id="mobileNoInput"
                                       inputmode="numeric" pattern="[0-9]*" maxlength="15"
                                       value="<?= htmlspecialchars($sit_extra['mobile_no'] ?? '') ?>"
                                       placeholder="e.g. 09XX-XXX-XXXX">
                            </div>
                        </div>

                        <div class="pinfo-grid" style="margin-bottom:20px;">
                            <div class="pinfo-field full">
                                <label>Home Address</label>
                                <textarea name="home_address" placeholder="Complete home address"><?= htmlspecialchars($sit_extra['home_address'] ?? '') ?></textarea>
                            </div>
                        </div>

                        <div class="pinfo-subsection-title" style="margin-bottom:10px;">Mother's Name</div>
                        <div class="pinfo-grid cols-3" style="margin-bottom:14px;">
                            <div class="pinfo-field">
                                <label>First Name</label>
                                <input type="text" name="mother_first"
                                       value="<?= htmlspecialchars($sit_extra['mother_first'] ?? '') ?>"
                                       placeholder="Mother's first name">
                            </div>
                            <div class="pinfo-field">
                                <label>Middle Name <span class="opt-label">(optional)</span></label>
                                <input type="text" name="mother_middle"
                                       value="<?= htmlspecialchars($sit_extra['mother_middle'] ?? '') ?>"
                                       placeholder="Mother's middle name">
                            </div>
                            <div class="pinfo-field">
                                <label>Last Name</label>
                                <input type="text" name="mother_last"
                                       value="<?= htmlspecialchars($sit_extra['mother_last'] ?? '') ?>"
                                       placeholder="Mother's last name">
                            </div>
                        </div>

                        <div class="pinfo-subsection-title" style="margin-bottom:10px;">Father's Name</div>
                        <div class="pinfo-grid cols-3" style="margin-bottom:14px;">
                            <div class="pinfo-field">
                                <label>First Name</label>
                                <input type="text" name="father_first"
                                       value="<?= htmlspecialchars($sit_extra['father_first'] ?? '') ?>"
                                       placeholder="Father's first name">
                            </div>
                            <div class="pinfo-field">
                                <label>Middle Name <span class="opt-label">(optional)</span></label>
                                <input type="text" name="father_middle"
                                       value="<?= htmlspecialchars($sit_extra['father_middle'] ?? '') ?>"
                                       placeholder="Father's middle name">
                            </div>
                            <div class="pinfo-field">
                                <label>Last Name</label>
                                <input type="text" name="father_last"
                                       value="<?= htmlspecialchars($sit_extra['father_last'] ?? '') ?>"
                                       placeholder="Father's last name">
                            </div>
                        </div>

                        <div class="pinfo-subsection-title" style="margin-bottom:10px;">Guardian</div>
                        <div class="pinfo-grid cols-3" style="margin-bottom:0;">
                            <div class="pinfo-field">
                                <label>Guardian</label>
                                <select name="guardian_type" id="guardianTypeSelect" onchange="handleGuardianChange(this.value)">
                                    <option value="">— Select Guardian —</option>
                                    <option value="Mother" <?= ($sit_extra['guardian_type'] ?? '') === 'Mother' ? 'selected' : '' ?>>Mother</option>
                                    <option value="Father" <?= ($sit_extra['guardian_type'] ?? '') === 'Father' ? 'selected' : '' ?>>Father</option>
                                    <option value="Other"  <?= ($sit_extra['guardian_type'] ?? '') === 'Other'  ? 'selected' : '' ?>>Other</option>
                                </select>
                            </div>

                            <div class="pinfo-field">
                                <label>Guardian No.</label>
                                <input type="text" name="guardian_no" id="guardianNoInput"
                                       inputmode="numeric" pattern="[0-9]*" maxlength="15"
                                       value="<?= htmlspecialchars($sit_extra['guardian_no'] ?? '') ?>"
                                       placeholder="e.g. 09XX-XXX-XXXX">
                            </div>

                            <div id="guardianOtherWrap" class="<?= ($sit_extra['guardian_type'] ?? '') === 'Other' ? 'show' : '' ?>">
                                <div class="pinfo-field">
                                    <label>First Name</label>
                                    <input type="text" name="guardian_other_first" id="guardianOtherFirst"
                                           value="<?= htmlspecialchars($guardian_other_first_val) ?>"
                                           placeholder="Guardian's first name">
                                </div>
                                <div class="pinfo-field">
                                    <label>Middle Name <span class="opt-label">(optional)</span></label>
                                    <input type="text" name="guardian_other_middle" id="guardianOtherMiddle"
                                           value="<?= htmlspecialchars($guardian_other_middle_val) ?>"
                                           placeholder="Guardian's middle name">
                                </div>
                                <div class="pinfo-field">
                                    <label>Last Name</label>
                                    <input type="text" name="guardian_other_last" id="guardianOtherLast"
                                           value="<?= htmlspecialchars($guardian_other_last_val) ?>"
                                           placeholder="Guardian's last name">
                                </div>
                            </div>
                        </div>
                    </div>

                    <div class="profile-info-section">
                        <h3>
                            <span class="sec-badge">II</span>
                            Academic Data
                        </h3>

                        <div class="pinfo-grid cols-3" style="margin-bottom:14px;">
                            <div class="pinfo-field">
                                <label>Course</label>
                                <input type="text" value="<?= htmlspecialchars($course ?? '') ?>" readonly>
                            </div>
                            <div class="pinfo-field">
                                <label>College</label>
                                <input type="text" name="college"
                                       value="<?= htmlspecialchars($sit_extra['college'] ?? '') ?>"
                                       placeholder="e.g. College of Information and Communications Technology">
                            </div>
                            <div class="pinfo-field">
                                <label>Major</label>
                                <input type="text" name="major"
                                       value="<?= htmlspecialchars($sit_extra['major'] ?? '') ?>"
                                       placeholder="e.g. Computer Science">
                            </div>
                        </div>

                        <div class="pinfo-grid cols-3" style="margin-bottom:14px;">
                            <div class="pinfo-field">
                                <label>Year and Section</label>
                                <input type="text" name="year_section"
                                       value="<?= htmlspecialchars($sit_extra['year_section'] ?? '') ?>"
                                       placeholder="e.g. 4-A">
                            </div>
                            <div class="pinfo-field">
                                <label>Day Schedule</label>
                                <input type="text" name="day_sched"
                                       value="<?= htmlspecialchars($sit_extra['day_sched'] ?? '') ?>"
                                       placeholder="e.g. MWF">
                            </div>
                            <div class="pinfo-field">
                                <label>Evening Schedule</label>
                                <input type="text" name="evening_sched"
                                       value="<?= htmlspecialchars($sit_extra['evening_sched'] ?? '') ?>"
                                       placeholder="e.g. TTh">
                            </div>
                        </div>

                        <div class="pinfo-subsection-title" style="margin-bottom:10px;">OJT Coordinator</div>
                        <div class="pinfo-grid cols-name" style="margin-bottom:0;">
                            <div class="pinfo-field">
                                <label>First Name</label>
                                <input type="text" name="ojt_coordinator_first"
                                       value="<?= htmlspecialchars($ojt_coordinator_first_val) ?>"
                                       placeholder="First name">
                            </div>
                            <div class="pinfo-field">
                                <label>Middle Name <span class="opt-label">(optional)</span></label>
                                <input type="text" name="ojt_coordinator_middle"
                                       value="<?= htmlspecialchars($ojt_coordinator_middle_val) ?>"
                                       placeholder="Middle name">
                            </div>
                            <div class="pinfo-field">
                                <label>Last Name</label>
                                <input type="text" name="ojt_coordinator_last"
                                       value="<?= htmlspecialchars($ojt_coordinator_last_val) ?>"
                                       placeholder="Last name">
                            </div>
                        </div>
                    </div>

                    <div class="profile-info-section">
                        <h3>
                            <span class="sec-badge">III</span>
                            Preference for Placement
                        </h3>

                        <?php if ($has_ojt_assignment): ?>
                        <div class="pinfo-company-note locked-note">
                            <i class="fas fa-lock"></i>
                            <span>You are already registered/deployed to a company. These fields are auto-filled from your official company record and are locked (like your name fields) — they can only be changed by the administrator or OJT coordinator.</span>
                        </div>
                        <?php else: ?>
                        <div class="pinfo-company-note">
                            <i class="fas fa-info-circle"></i>
                            <span>Fill in your preferred company details below. These will be saved to your profile and used in your SIT Application form.</span>
                        </div>
                        <?php endif; ?>

                        <div class="pinfo-grid" style="margin-bottom:14px;">
                            <div class="pinfo-field full">
                                <label>Company Name</label>
                                <input type="text" name="company_name"
                                       value="<?= htmlspecialchars($pref_display_company_name) ?>"
                                       placeholder="Full company name"
                                       <?= $lock_preference_fields ? 'readonly' : '' ?>>
                            </div>
                        </div>

                        <div class="pinfo-grid" style="margin-bottom:14px;">
                            <div class="pinfo-field full">
                                <label>Company Address</label>
                                <textarea name="company_address"
                                          placeholder="Complete company address"
                                          <?= $lock_preference_fields ? 'readonly' : '' ?>><?= htmlspecialchars($pref_display_company_address) ?></textarea>
                            </div>
                        </div>

                        <div class="pinfo-grid cols-3">
                            <div class="pinfo-field">
                                <label>Telephone Number</label>
                                <input type="text" name="telephone"
                                       value="<?= htmlspecialchars($pref_display_telephone) ?>"
                                       placeholder="e.g. (044) 123-4567"
                                       <?= $lock_preference_fields ? 'readonly' : '' ?>>
                            </div>
                            <div class="pinfo-field full" style="grid-column: span 2;">
                                <div class="pinfo-subsection-title" style="margin-bottom:6px;">Contact Person</div>
                                <div class="pinfo-grid cols-name" style="margin-top:0;">
                                    <div class="pinfo-field">
                                        <label>First Name</label>
                                        <input type="text" name="contact_person_first"
                                               value="<?= htmlspecialchars($pref_display_contact_first) ?>"
                                               placeholder="First name"
                                               <?= $lock_preference_fields ? 'readonly' : '' ?>>
                                    </div>
                                    <div class="pinfo-field">
                                        <label>Middle Name <span class="opt-label">(optional)</span></label>
                                        <input type="text" name="contact_person_middle"
                                               value="<?= htmlspecialchars($pref_display_contact_middle) ?>"
                                               placeholder="Middle name"
                                               <?= $lock_preference_fields ? 'readonly' : '' ?>>
                                    </div>
                                    <div class="pinfo-field">
                                        <label>Last Name</label>
                                        <input type="text" name="contact_person_last"
                                               value="<?= htmlspecialchars($pref_display_contact_last) ?>"
                                               placeholder="Last name"
                                               <?= $lock_preference_fields ? 'readonly' : '' ?>>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <div class="pinfo-grid" style="margin-top:10px;">
                            <div class="pinfo-field">
                                <label>Position / Department</label>
                                <input type="text" name="position"
                                       value="<?= htmlspecialchars($pref_display_position) ?>"
                                       placeholder="e.g. IT Department"
                                       <?= $lock_preference_fields ? 'readonly' : '' ?>>
                            </div>
                        </div>

                        <div style="text-align:right;">
                            <button type="submit" class="pinfo-save-btn">
                                <i class="fas fa-save"></i> Save Information
                            </button>
                        </div>
                    </div>
                </form>
            </div>

            <div id="documents-page" class="page-content">
                <form id="requirementsForm" method="POST" action="submit_requirements.php" enctype="multipart/form-data">
                    <h3>Documentary Requirements</h3>
                    <div class="requirements-container" id="requirementsContainer">
                    <?php
                    $req_icons = [
                        'cert_registration' => 'fas fa-file-alt',
                        'certificate_pdos'  => 'fas fa-certificate',
                        'ojt_sheet'         => 'fas fa-clipboard-list',
                        'application_sit'   => 'fas fa-file-signature',
                        'waiver_form'       => 'fas fa-shield-alt',
                        'student_contract'  => 'fas fa-file-contract',
                        'psych_result'      => 'fas fa-brain',
                        'medical_result'    => 'fas fa-heartbeat',
                    ];

                    $reqs = [
                        "cert_registration" => "Certification of Registration",
                        "certificate_pdos"  => "PDOS Certificate",
                        "ojt_sheet"         => "OJT Sheet",
                        "application_sit"   => "Application SIT",
                        "waiver_form"       => "Waiver Form",
                        "student_contract"  => "Student Contract",
                        "psych_result"      => "Psych Result",
                        "medical_result"    => "Medical Result",
                    ];

                    $special_keys = ['application_sit', 'waiver_form', 'student_contract'];

                    foreach ($reqs as $key => $label):
                        $stmt = $conn->prepare("SELECT file_name, status, remark FROM requirements WHERE user_id=? AND requirement_type=?");
                        $stmt->bind_param("is", $user_id, $key);
                        $stmt->execute();
                        $result = $stmt->get_result();
                        $row    = $result->fetch_assoc();
                        $stmt->close();

                        $file   = $row['file_name'] ?? null;
                        $status = $row['status']    ?? 'Pending';
                        $remark = $row['remark']    ?? null;
                        $is_special = in_array($key, $special_keys);

                        $hdr_cls = 'hdr-pending';
                        $status_icon = 'fas fa-clock';
                        $status_icon_lbl = 'Pending';
                        if ($status === 'Verified') {
                            $hdr_cls      = 'hdr-verified';
                            $status_icon  = 'fas fa-check-circle';
                            $status_icon_lbl = 'Verified';
                        } elseif ($status === 'Denied') {
                            $hdr_cls      = 'hdr-denied';
                            $status_icon  = 'fas fa-times-circle';
                            $status_icon_lbl = 'Denied';
                        }

                        $is_pdf_file = false;
                        if ($file) {
                            $is_pdf_file = (substr($file, 0, 4) === '%PDF'
                                         || substr($file, 0, 4) === "\x25\x50\x44\x46"
                                         || strpos(substr($file, 0, 8), 'PDF') !== false);
                        }

                        $eye_hidden = $is_special && $file && $status !== 'Denied';

                        $popup_subtitle = '';
                        $popup_steps    = [];
                        $popup_warning  = '';
                        if ($key === 'application_sit') {
                            $popup_subtitle = 'Application SIT — follow these steps';
                            $popup_steps = [
                                ['icon'=>'fas fa-eye',        'title'=>'Preview the form first',      'desc'=>'Click the eye button below, then choose Preview Form. Review all details before printing or saving.'],
                                ['icon'=>'fas fa-user-check', 'title'=>'Verify your information',     'desc'=>'All fields are auto-filled from your student profile. Double-check your name, course, company, and OJT coordinator before saving as PDF to avoid errors.'],
                                ['icon'=>'fas fa-print',      'title'=>'Print and sign',              'desc'=>'Save as PDF or print the form, then affix your signature where required.'],
                                ['icon'=>'fas fa-upload',     'title'=>'Upload the signed copy',      'desc'=>'Scan or photograph the signed form and upload it using the file input below.'],
                            ];
                            $popup_warning = 'Make sure your signature is clearly visible on the uploaded file. Submissions without a signature will be denied.';
                        } elseif ($key === 'waiver_form') {
                            $popup_subtitle = 'Waiver Form — follow these steps';
                            $popup_steps = [
                                ['icon'=>'fas fa-eye',        'title'=>'Preview the form first',      'desc'=>'Click the eye button below, then choose Preview Form. Review all pre-filled details carefully.'],
                                ['icon'=>'fas fa-user-check', 'title'=>'Verify your information',     'desc'=>'Your name, company, OJT details, and parent or guardian names are filled from your student profile. Correct them in Student Info if anything is wrong before saving.'],
                                ['icon'=>'fas fa-print',      'title'=>'Print and have it signed',    'desc'=>'Save as PDF or print, then have your parent or guardian sign the form on their respective signature lines.'],
                                ['icon'=>'fas fa-upload',     'title'=>'Upload the signed copy',      'desc'=>'Scan or photograph the signed waiver and upload it using the file input below.'],
                            ];
                            $popup_warning = 'The parent or guardian signature must be present and legible. Unsigned or illegible uploads will be denied.';
                        } elseif ($key === 'student_contract') {
                            $popup_subtitle = 'Student Contract — follow these steps';
                            $popup_steps = [
                                ['icon'=>'fas fa-eye',        'title'=>'Preview the form first',      'desc'=>'Click the eye button below, then choose Preview Form. Review all parties, dates, and details before proceeding.'],
                                ['icon'=>'fas fa-user-check', 'title'=>'Verify your information',     'desc'=>'Your name, company, guardian, and OJT coordinator details are drawn from your student profile. Go to Student Info to correct any errors before saving as PDF.'],
                                ['icon'=>'fas fa-print',      'title'=>'Print and sign all parties',  'desc'=>'Save as PDF or print. The student, parent or guardian, and OJT coordinator must all sign their respective lines.'],
                                ['icon'=>'fas fa-upload',     'title'=>'Upload the signed copy',      'desc'=>'Scan or photograph the fully signed contract and upload it using the file input below.'],
                            ];
                            $popup_warning = 'All required signatures must be present. Contracts with missing signatures from any party will be denied.';
                        }
                    ?>

                    <div class="req-card-e"
                         id="reqCard_<?= htmlspecialchars($key) ?>"
                         data-req-type="<?= htmlspecialchars($key) ?>"
                         data-status="<?= htmlspecialchars($status) ?>">

                        <div class="rce-header <?= $hdr_cls ?>" id="rceHeader_<?= htmlspecialchars($key) ?>">
                            <i class="<?= $status_icon ?> rce-status-icon" id="rceStatusIcon_<?= htmlspecialchars($key) ?>"></i>
                            <span class="rce-status-label" id="rceStatusLabel_<?= htmlspecialchars($key) ?>"><?= $status_icon_lbl ?></span>
                            <?php if ($is_special): ?>
                            <button type="button"
                                    class="rce-info-btn"
                                    id="infoBtn_<?= htmlspecialchars($key) ?>"
                                    onclick="toggleInfoPopup('<?= htmlspecialchars($key) ?>', event)"
                                    aria-label="How to submit <?= htmlspecialchars($label) ?>">
                                <i class="fas fa-info-circle"></i>
                            </button>
                            <?php endif; ?>
                        </div>

                        <div class="rce-body" id="rceBody_<?= htmlspecialchars($key) ?>">

                            <div class="rce-title"><?= htmlspecialchars($label) ?></div>

                            <?php if ($file): ?>
                            <div class="rce-preview-container" id="rcePreview_<?= htmlspecialchars($key) ?>">
                                <?php if ($is_pdf_file): ?>
                                    <div class="rce-pdf-thumb">
                                        <i class="fas fa-file-pdf"></i>
                                        <span>PDF</span>
                                    </div>
                                <?php else: ?>
                                    <img src="data:image/jpeg;base64,<?= base64_encode($file) ?>"
                                         class="rce-preview-img"
                                         onclick="openPreview(this.src)"
                                         title="Click to view full size">
                                <?php endif; ?>
                            </div>
                            <?php else: ?>
                            <div class="rce-preview-container" id="rcePreview_<?= htmlspecialchars($key) ?>" style="display:none;"></div>
                            <?php endif; ?>

                            <div class="rce-remark" id="rceRemark_<?= htmlspecialchars($key) ?>" <?= ($status !== 'Denied' || empty($remark)) ? 'style="display:none;"' : '' ?>>
                                <i class="fas fa-exclamation-circle"></i>
                                <span class="rce-remark-text">Reason: <?= htmlspecialchars($remark ?? '') ?></span>
                            </div>

                            <div class="rce-reupload" id="rceReupload_<?= htmlspecialchars($key) ?>" <?= $status !== 'Denied' ? 'style="display:none;"' : '' ?>>
                                <i class="fas fa-redo"></i> Re-upload required
                            </div>

                            <?php if ($is_special): ?>
                            <div class="rce-bottom-action-row" id="rceUploadRow_<?= htmlspecialchars($key) ?>">

                                <span id="rceUploadControls_<?= htmlspecialchars($key) ?>"
                                      style="display:<?= (!$file || $status === 'Denied') ? 'contents' : 'none' ?>;">
                                    <i class="fas fa-upload rce-upload-icon"></i>
                                    <label class="rce-upload-label" for="fileInput_<?= htmlspecialchars($key) ?>">Upload file</label>
                                    <label class="rce-file-btn"
                                           id="fileBtnLabel_<?= htmlspecialchars($key) ?>"
                                           for="fileInput_<?= htmlspecialchars($key) ?>">
                                        <i class="fas fa-folder-open"></i>
                                        <span id="fileBtnText_<?= htmlspecialchars($key) ?>">Choose</span>
                                    </label>
                                    <input type="file"
                                           name="<?= htmlspecialchars($key) ?>"
                                           id="fileInput_<?= htmlspecialchars($key) ?>"
                                           class="rce-file-input-hidden"
                                           data-label="<?= htmlspecialchars($label) ?>"
                                           data-req-type="<?= htmlspecialchars($key) ?>"
                                           accept="image/jpeg"
                                           onchange="handleReqFileChange(this, '<?= htmlspecialchars($key) ?>')">
                                </span>

                                <?php if ($key === 'application_sit'): ?>
                                <button type="button"
                                        class="rce-preview-eye-btn<?= $eye_hidden ? ' eye-hidden' : '' ?>"
                                        id="eyeBtn_<?= htmlspecialchars($key) ?>"
                                        onclick="openSITModal()"
                                        title="Preview Application SIT Form">
                                    <i class="fas fa-eye"></i>
                                </button>
                                <?php elseif ($key === 'waiver_form'): ?>
                                <button type="button"
                                        class="rce-preview-eye-btn<?= $eye_hidden ? ' eye-hidden' : '' ?>"
                                        id="eyeBtn_<?= htmlspecialchars($key) ?>"
                                        onclick="openWAIVERModal()"
                                        title="Preview Waiver Form">
                                    <i class="fas fa-eye"></i>
                                </button>
                                <?php elseif ($key === 'student_contract'): ?>
                                <button type="button"
                                        class="rce-preview-eye-btn<?= $eye_hidden ? ' eye-hidden' : '' ?>"
                                        id="eyeBtn_<?= htmlspecialchars($key) ?>"
                                        onclick="openCONTRACTModal()"
                                        title="Preview Student Contract">
                                    <i class="fas fa-eye"></i>
                                </button>
                                <?php endif; ?>
                            </div>

                            <?php else: ?>
                            <div class="rce-upload-row" id="rceUploadRow_<?= htmlspecialchars($key) ?>"
                                 style="display:<?= (!$file || $status === 'Denied') ? 'flex' : 'none' ?>;">
                                <i class="fas fa-upload rce-upload-icon"></i>
                                <label class="rce-upload-label" for="fileInput_<?= htmlspecialchars($key) ?>">Upload file</label>
                                <label class="rce-file-btn"
                                       id="fileBtnLabel_<?= htmlspecialchars($key) ?>"
                                       for="fileInput_<?= htmlspecialchars($key) ?>">
                                    <i class="fas fa-folder-open"></i>
                                    <span id="fileBtnText_<?= htmlspecialchars($key) ?>">Choose</span>
                                </label>
                                <input type="file"
                                       name="<?= htmlspecialchars($key) ?>"
                                       id="fileInput_<?= htmlspecialchars($key) ?>"
                                       class="rce-file-input-hidden"
                                       data-label="<?= htmlspecialchars($label) ?>"
                                       data-req-type="<?= htmlspecialchars($key) ?>"
                                       accept="image/jpeg"
                                       onchange="handleReqFileChange(this, '<?= htmlspecialchars($key) ?>')">
                            </div>
                            <?php endif; ?>

                        </div>

                    </div>

                    <?php if ($is_special): ?>
                    <div class="rce-info-overlay" id="infoPopup_<?= htmlspecialchars($key) ?>" onclick="handleInfoOverlayClick(event, '<?= htmlspecialchars($key) ?>')">
                        <div class="rce-info-modal">
                            <div class="rip-head">
                                <div class="rip-head-icon">
                                    <i class="fas fa-info-circle"></i>
                                </div>
                                <div class="rip-head-text">
                                    <strong>How to submit this form</strong>
                                    <span><?= htmlspecialchars($popup_subtitle) ?></span>
                                </div>
                                <button type="button" class="rip-close-btn"
                                        onclick="closeInfoPopup('<?= htmlspecialchars($key) ?>')"
                                        aria-label="Close">
                                    <i class="fas fa-times"></i>
                                </button>
                            </div>
                            <div class="rip-body">
                                <div class="rip-steps-grid">
                                    <?php foreach ($popup_steps as $i => $step): ?>
                                    <div class="rip-step">
                                        <div class="rip-step-num"><?= $i + 1 ?></div>
                                        <div class="rip-step-content">
                                            <div class="rip-step-title">
                                                <i class="<?= $step['icon'] ?>"></i>
                                                <?= htmlspecialchars($step['title']) ?>
                                            </div>
                                            <div class="rip-step-desc"><?= htmlspecialchars($step['desc']) ?></div>
                                        </div>
                                    </div>
                                    <?php endforeach; ?>
                                </div>
                                <?php if ($popup_warning): ?>
                                <div class="rip-warning">
                                    <i class="fas fa-exclamation-triangle"></i>
                                    <p><?= htmlspecialchars($popup_warning) ?></p>
                                </div>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                    <?php endif; ?>

                    <?php endforeach; ?>
                    </div>

                    <button type="submit" name="submit_all" value="1" class="submit-all">Submit All Requirements</button>
                </form>
            </div>
        </div>
    </div>
</div>

<?php echo accom_modal_html($accom_data); ?>

<?php
function accom_preview_button_custom() {
    return '
    <button type="button"
            class="accom-preview-btn"
            onclick="if(typeof accomOpenPreview===\'function\')accomOpenPreview();else if(typeof window.accomOpenPreview===\'function\')window.accomOpenPreview();"
            aria-label="Preview Accomplishment Form">
        <span class="accom-btn-icon-block" aria-hidden="true">
            <i class="fas fa-clipboard-list"></i>
        </span>
        <span class="accom-btn-text">
            <strong>Accomplishment Form</strong>
            <span>Preview &amp; print</span>
        </span>
        <i class="fas fa-chevron-right accom-btn-chevron" aria-hidden="true"></i>
    </button>';
}
?>

<script>
const sidebar   = document.getElementById('sidebar');
const toggleBtn = document.getElementById('toggleBtn');

toggleBtn.addEventListener('click', function() {
    sidebar.classList.toggle('collapsed');
    var mc = document.getElementById('mainContent');
    mc.style.marginLeft = sidebar.classList.contains('collapsed') ? '80px'  : '260px';
    mc.style.width      = sidebar.classList.contains('collapsed') ? 'calc(100% - 80px)' : 'calc(100% - 260px)';

    document.getElementById('att-notif-bar')
            .classList.toggle('sidebar-collapsed', sidebar.classList.contains('collapsed'));
});

const TAB_STORAGE_KEY = 'accomFormActiveTab';
const PHP_INITIAL_TAB = <?= json_encode($initial_tab) ?>;

function switchPage(pageId) {
    document.querySelectorAll('.page-content').forEach(function(p) {
        p.classList.remove('active-page');
    });
    document.querySelectorAll('.switch-page-btn').forEach(function(b) {
        b.classList.remove('active');
    });
    var activePage = document.getElementById(pageId);
    if (activePage) activePage.classList.add('active-page');
    var activeBtn  = document.querySelector('.switch-page-btn[data-page="' + pageId + '"]');
    if (activeBtn)  activeBtn.classList.add('active');
}

document.querySelectorAll('.switch-page-btn').forEach(function(btn) {
    btn.addEventListener('click', function() {
        var pageId = this.getAttribute('data-page');
        if (pageId) {
            switchPage(pageId);
            try { sessionStorage.setItem(TAB_STORAGE_KEY, pageId); } catch(e) {}
        }
    });
});

(function() {
    var targetTab = PHP_INITIAL_TAB;
    try { sessionStorage.setItem(TAB_STORAGE_KEY, targetTab); } catch(e) {}
    switchPage(targetTab);
})();

function openPreview(src) {
    document.getElementById("previewImage").src = src;
    document.getElementById("imagePreviewModal").style.display = "flex";
}

function handleProfilePhotoChange(input) {
    if (!input.files || !input.files.length) return;
    if (!validateFileInput(input)) return;
    var btnText = document.getElementById('profilePhotoBtnText');
    if (btnText) { btnText.textContent = 'Re-upload'; }
    var btnLabel = document.getElementById('profilePhotoBtnLabel');
    if (btnLabel) {
        var icon = btnLabel.querySelector('i');
        if (icon) { icon.className = 'fas fa-redo'; }
    }
    previewProfilePhoto(input);
}

function handleReqFileChange(input, key) {
    if (!input.files || !input.files.length) return;
    if (!validateFileInput(input)) return;
    var btnText = document.getElementById('fileBtnText_' + key);
    if (btnText) { btnText.textContent = 'Re-upload'; }
    var btnLabel = document.getElementById('fileBtnLabel_' + key);
    if (btnLabel) {
        var icon = btnLabel.querySelector('i');
        if (icon) { icon.className = 'fas fa-redo'; }
    }
    previewRequirementFile(input);
}

function previewProfilePhoto(input) {
    if (!input.files || !input.files[0]) return;
    var file = input.files[0];
    var allowed = ['image/jpeg'];
    if (!allowed.includes(file.type)) return;
    var reader = new FileReader();
    reader.onload = function(e) {
        var photoSection = input.closest('.photo-section');
        if (!photoSection) return;
        var previewImg = photoSection.querySelector('img.preview');
        if (!previewImg) {
            previewImg = document.createElement('img');
            previewImg.className = 'preview';
            previewImg.onclick = function() { openPreview(this.src); };
            var br = photoSection.querySelector('br');
            if (br) photoSection.insertBefore(previewImg, br);
            else photoSection.insertBefore(previewImg, photoSection.firstChild);
        }
        previewImg.src = e.target.result;
    };
    reader.readAsDataURL(file);
}

function previewRequirementFile(input) {
    if (!input.files || !input.files[0]) return;
    var file = input.files[0];
    var key  = input.dataset.reqType || '';
    var previewContainer = key
        ? document.getElementById('rcePreview_' + key)
        : input.closest('.req-card-e') && input.closest('.req-card-e').querySelector('.rce-preview-container');

    if (!previewContainer) {
        previewContainer = document.createElement('div');
        previewContainer.className = 'rce-preview-container';
        var rceBody  = input.closest('.req-card-e') && input.closest('.req-card-e').querySelector('.rce-body');
        var fileWrap = input.closest('.rce-bottom-action-row') || input.closest('.rce-upload-row');
        if (rceBody && fileWrap) rceBody.insertBefore(previewContainer, fileWrap);
        else if (rceBody) rceBody.appendChild(previewContainer);
    }

    previewContainer.innerHTML = '';
    previewContainer.style.display = '';

    if (file.type.startsWith('image/')) {
        var reader = new FileReader();
        reader.onload = function(e) {
            var img = document.createElement('img');
            img.src = e.target.result;
            img.className = 'rce-preview-img';
            img.onclick = function() { openPreview(this.src); };
            img.title = 'Click to view full size';
            previewContainer.appendChild(img);
        };
        reader.readAsDataURL(file);
    } else if (file.type === 'application/pdf') {
        var pdfDiv = document.createElement('div');
        pdfDiv.className = 'rce-pdf-thumb';
        pdfDiv.innerHTML = '<i class="fas fa-file-pdf"></i><span>PDF</span>';
        previewContainer.appendChild(pdfDiv);
    }
}

function handleGuardianChange(val) {
    var wrap = document.getElementById('guardianOtherWrap');
    if (val === 'Other') {
        wrap.classList.add('show');
    } else {
        wrap.classList.remove('show');
        document.getElementById('guardianOtherFirst').value  = '';
        document.getElementById('guardianOtherMiddle').value = '';
        document.getElementById('guardianOtherLast').value   = '';
    }
}

(function() {
    function stripNonDigits(el) {
        var pos     = el.selectionStart;
        var before  = el.value;
        var cleaned = before.replace(/[^0-9]/g, '');
        if (cleaned !== before) {
            var removedBeforeCursor =
                before.slice(0, pos).length - before.slice(0, pos).replace(/[^0-9]/g, '').length;
            el.value = cleaned;
            var newPos = Math.max(0, pos - removedBeforeCursor);
            el.setSelectionRange(newPos, newPos);
        }
    }

    function attachDigitOnly(el) {
        if (!el || el.dataset.digitOnlyBound) return;
        el.dataset.digitOnlyBound = '1';

        el.addEventListener('keypress', function(e) {
            if (e.key && e.key.length === 1 && !/[0-9]/.test(e.key)) {
                e.preventDefault();
            }
        });
        el.addEventListener('input', function() { stripNonDigits(this); });
        el.addEventListener('paste', function() {
            var el2 = this;
            setTimeout(function() { stripNonDigits(el2); }, 0);
        });
    }

    document.addEventListener('DOMContentLoaded', function() {
        document.querySelectorAll('input[name="mobile_no"], input[name="guardian_no"]')
                .forEach(attachDigitOnly);
    });
})();

var _currentInfoPopup = null;

function toggleInfoPopup(key, event) {
    event.stopPropagation();
    if (_currentInfoPopup && _currentInfoPopup !== key) {
        closeInfoPopup(_currentInfoPopup);
    }
    var overlay = document.getElementById('infoPopup_' + key);
    var btn     = document.getElementById('infoBtn_' + key);
    if (!overlay) return;
    if (overlay.classList.contains('popup-open')) {
        closeInfoPopup(key);
    } else {
        overlay.classList.add('popup-open');
        document.body.style.overflow = 'hidden';
        if (btn) btn.classList.add('info-active');
        _currentInfoPopup = key;
    }
}

function closeInfoPopup(key) {
    var overlay = document.getElementById('infoPopup_' + key);
    var btn     = document.getElementById('infoBtn_' + key);
    if (overlay) overlay.classList.remove('popup-open');
    if (btn)     btn.classList.remove('info-active');
    if (_currentInfoPopup === key) {
        _currentInfoPopup = null;
        document.body.style.overflow = '';
    }
}

function handleInfoOverlayClick(event, key) {
    if (event.target === event.currentTarget) {
        closeInfoPopup(key);
    }
}

/* ══════════════════════════════════════════════════════════════
   FULL-SCREEN DOCUMENT PREVIEW HELPERS (SIT / Waiver / Contract)
   ------------------------------------------------------------
   Open/close logic is unchanged from before (same iframe lazy-load
   via data-loaded, same preview URLs, same overlay class toggling).
   The Print helper (printFullscreenDoc) has been removed along with
   the Print buttons, since printing is no longer offered from these
   toolbars.
   ══════════════════════════════════════════════════════════════ */

var SIT_PREVIEW_URL = 'AccomForm.php?sit_preview=1';

function openSITModal() {
    var modal  = document.getElementById('sitPreviewModal');
    var iframe = document.getElementById('sitPreviewIframe');
    if (!iframe.dataset.loaded) { iframe.src = SIT_PREVIEW_URL; iframe.dataset.loaded = '1'; }
    modal.classList.add('open');
    document.body.style.overflow = 'hidden';
}
function closeSITModal() {
    document.getElementById('sitPreviewModal').classList.remove('open');
    document.body.style.overflow = '';
}
document.getElementById('sitPreviewModal').addEventListener('click', function(e) {
    if (e.target === this) closeSITModal();
});

var WAIVER_PREVIEW_URL = 'AccomForm.php?waiver_preview=1';

function openWAIVERModal() {
    var modal  = document.getElementById('waiverPreviewModal');
    var iframe = document.getElementById('waiverPreviewIframe');
    if (!iframe.dataset.loaded) { iframe.src = WAIVER_PREVIEW_URL; iframe.dataset.loaded = '1'; }
    modal.classList.add('open');
    document.body.style.overflow = 'hidden';
}
function closeWAIVERModal() {
    document.getElementById('waiverPreviewModal').classList.remove('open');
    document.body.style.overflow = '';
}
document.getElementById('waiverPreviewModal').addEventListener('click', function(e) {
    if (e.target === this) closeWAIVERModal();
});

var CONTRACT_PREVIEW_URL = 'AccomForm.php?contract_preview=1';

function openCONTRACTModal() {
    var modal  = document.getElementById('contractPreviewModal');
    var iframe = document.getElementById('contractPreviewIframe');
    if (!iframe.dataset.loaded) { iframe.src = CONTRACT_PREVIEW_URL; iframe.dataset.loaded = '1'; }
    modal.classList.add('open');
    document.body.style.overflow = 'hidden';
}
function closeCONTRACTModal() {
    document.getElementById('contractPreviewModal').classList.remove('open');
    document.body.style.overflow = '';
}
document.getElementById('contractPreviewModal').addEventListener('click', function(e) {
    if (e.target === this) closeCONTRACTModal();
});

function showNotDeployedModal(pageName, event) {
    if (event) event.preventDefault();
    document.getElementById('ndm-page-label').textContent = pageName;
    document.getElementById('not-deployed-modal').classList.add('show');
}
function closeNotDeployedModal() {
    document.getElementById('not-deployed-modal').classList.remove('show');
}
document.getElementById('not-deployed-modal').addEventListener('click', function(e) {
    if (e.target === this) closeNotDeployedModal();
});

document.addEventListener('keydown', function(e) {
    if (e.key === 'Escape') {
        closeSITModal();
        closeWAIVERModal();
        closeCONTRACTModal();
        closeNotDeployedModal();
        if (_currentInfoPopup) closeInfoPopup(_currentInfoPopup);
        if (typeof accomClosePreview === 'function') accomClosePreview();
        _anbHide(_anb.currentType);
    }
});

/* ============================================================
   ADJUSTMENT: JOURNAL "EMPTY ENTRY" BADGE — MADE RE-ATTACHABLE
   ------------------------------------------------------------
   Previously this whole block lived inside a self-invoking
   function that captured `document.getElementById('journalEmptyBadge')`
   ONCE and permanently bailed out if it wasn't found (which is the
   normal case when the sidebar's Reports link doesn't exist yet
   because requirements aren't fully verified). refreshJournalBadge()
   now re-queries the badge element every time it runs, and is a
   plain top-level function (not hidden inside an IIFE), so
   activateVerifiedSidebar() (below) can call it again right after
   the Reports link — and its badge span — are inserted into the
   sidebar for the first time. Nothing about the badge's own
   behavior (localStorage key, refresh interval, storage event) has
   changed.
   ============================================================ */
var JOURNAL_BADGE_LS_KEY = <?= json_encode('ojt_journal_empty_count_' . $user_id) ?>;
function refreshJournalBadge() {
    var badge = document.getElementById('journalEmptyBadge');
    if (!badge) return;
    var count = 0;
    try { var raw = localStorage.getItem(JOURNAL_BADGE_LS_KEY); count = raw !== null ? parseInt(raw, 10) || 0 : 0; } catch(e) {}
    if (count > 0) { badge.textContent = count; badge.style.display = 'inline-flex'; }
    else { badge.style.display = 'none'; badge.textContent = ''; }
}
refreshJournalBadge();
setInterval(refreshJournalBadge, 10000);
window.addEventListener('storage', function(e) { if (e.key === JOURNAL_BADGE_LS_KEY) refreshJournalBadge(); });

/* ============================================================
   ADJUSTMENT: INSTANT SIDEBAR ACTIVATION ON FULL VERIFICATION
   ------------------------------------------------------------
   Previously, the "Attendance" / "Reports" / "Dashboard" sidebar
   links (and the lock notice) were only decided once, server-side,
   from `$all_verified` at the moment the page was rendered. If the
   administrator verified the student's last remaining requirement
   while the student already had this page open, the sidebar stayed
   locked/incomplete until a manual page refresh.

   This block hooks into the requirement-status polling loop
   further below (the same `AccomForm.php?poll_status=1` polling
   that already drives the live requirement-card updates and the
   "Requirement Status Updated" popup) so that the moment ALL 8
   requirement types come back as "Verified", the sidebar is
   unlocked immediately in-place — no reload needed:
     - the "Some pages are locked..." notice is removed
     - "Attendance" / "Reports" / "Dashboard" are inserted, either
       as fully active links (if the student is already deployed,
       mirroring the exact PHP branch used at initial render) or
       as the same "not deployed yet" locked placeholders PHP would
       have rendered (deployment is a separate, unrelated gate and
       is left untouched by this feature)
     - the journal "empty entries" badge is re-initialized for the
       newly-inserted Reports link

   ADJUSTMENT: "My Profile" is now always rendered server-side
   (see the sidebar markup above), so this function no longer needs
   to insert it — it is simply never removed in the first place.

   Nothing here touches how `$all_verified` / `$is_deployed` are
   computed server-side, nor any other existing sidebar/requirement
   logic — it only adds a live client-side mirror of the same PHP
   branch that already exists above in the sidebar markup.
   ============================================================ */
const IS_DEPLOYED_FLAG          = <?= json_encode($is_deployed) ?>;
const INITIAL_ATT_SIDEBAR_BADGE = <?= json_encode((bool)$att_sidebar_badge) ?>;
let   sidebarVerifiedActivated  = <?= json_encode($all_verified) ?>;

function activateVerifiedSidebar() {
    if (sidebarVerifiedActivated) return;
    sidebarVerifiedActivated = true;

    var lockNotice = document.querySelector('.sidebar-lock-notice');
    if (lockNotice) { lockNotice.remove(); }

    var linksContainer = document.getElementById('sidebarLinksContainer');
    if (!linksContainer) return;

    if (!linksContainer.querySelector('[data-nav-key="attendance"]')) {
        var holder = document.createElement('div');

        if (IS_DEPLOYED_FLAG) {
            holder.innerHTML =
                '<a href="student_attendance.php" data-nav-key="attendance">' +
                    '<i class="fas fa-calendar-check"></i>' +
                    '<span class="link-text">Attendance</span>' +
                    (INITIAL_ATT_SIDEBAR_BADGE ? '<span class="sidebar-badge-att">!</span>' : '') +
                '</a>' +
                '<a href="student_report.php" data-nav-key="report">' +
                    '<i class="fas fa-chart-bar"></i>' +
                    '<span class="link-text">Reports</span>' +
                    '<span class="sidebar-badge-journal" id="journalEmptyBadge" style="display:none;"></span>' +
                '</a>' +
                '<a href="student_dashboard.php" data-nav-key="dashboard">' +
                    '<i class="fas fa-tachometer-alt"></i>' +
                    '<span class="link-text">Dashboard</span>' +
                '</a>';
        } else {
            holder.innerHTML =
                '<a href="#" class="nav-locked" data-nav-key="attendance" onclick="showNotDeployedModal(\'Attendance\', event)">' +
                    '<i class="fas fa-calendar-check"></i>' +
                    '<span class="link-text">Attendance</span>' +
                    '<i class="fas fa-lock nav-lock-icon"></i>' +
                '</a>' +
                '<a href="#" class="nav-locked" data-nav-key="report" onclick="showNotDeployedModal(\'Reports\', event)">' +
                    '<i class="fas fa-chart-bar"></i>' +
                    '<span class="link-text">Reports</span>' +
                    '<i class="fas fa-lock nav-lock-icon"></i>' +
                '</a>' +
                '<a href="#" class="nav-locked" data-nav-key="dashboard" onclick="showNotDeployedModal(\'Dashboard\', event)">' +
                    '<i class="fas fa-tachometer-alt"></i>' +
                    '<span class="link-text">Dashboard</span>' +
                    '<i class="fas fa-lock nav-lock-icon"></i>' +
                '</a>';
        }

        while (holder.firstChild) {
            linksContainer.appendChild(holder.firstChild);
        }
    }

    refreshJournalBadge();
}

/* ============================================================
   ADJUSTMENT: LIVE SIDEBAR RE-LOCK WHEN VERIFICATION IS LOST
   ------------------------------------------------------------
   Mirror of activateVerifiedSidebar() above, but for the reverse
   transition: if a requirement that was previously part of a fully
   "Verified" set gets reverted (status changes back to "Pending" or
   "Denied") by the administrator WHILE the student already has this
   page open — and unlocked — the sidebar must lock again immediately,
   without needing a manual page refresh. Previously this transition
   was never handled client-side, so a student who had already been
   granted access to "Attendance" / "Reports" / "Dashboard" kept
   seeing (and could keep navigating to) those links even after their
   verified status was revoked, until they reloaded the page.

   This performs the exact inverse of activateVerifiedSidebar():
     - re-inserts the "Some pages are locked..." notice (only if it
       isn't already present), in the same position PHP originally
       rendered it — directly above the sidebar-links container
     - removes the "Attendance" / "Reports" / "Dashboard" links,
       exactly matching the PHP condition that only ever renders
       them inside `if ($all_verified)`
     - resets `sidebarVerifiedActivated` back to false so that
       activateVerifiedSidebar() will correctly re-run and rebuild
       these links again the next time all requirements become
       Verified

   ADJUSTMENT: "My Profile" is now always rendered and is NOT tied
   to `$all_verified` at all, so this function no longer removes it
   — the link stays visible/accessible even if verification is lost,
   matching the updated requirement to unlock it unconditionally.

   Nothing about `$all_verified` / `$is_deployed` server-side
   computation, nor any other existing sidebar/requirement logic, is
   touched — this only adds the missing client-side mirror of the
   "not all verified" branch of the same PHP sidebar markup.
   ============================================================ */
function deactivateVerifiedSidebar() {
    if (!sidebarVerifiedActivated) return;
    sidebarVerifiedActivated = false;

    var linksContainer = document.getElementById('sidebarLinksContainer');

    if (!document.querySelector('.sidebar-lock-notice') && linksContainer && linksContainer.parentNode) {
        var lockNotice = document.createElement('div');
        lockNotice.className = 'sidebar-lock-notice';
        lockNotice.innerHTML =
            '<div class="sidebar-lock-notice-inner">' +
                '<i class="fas fa-lock"></i>' +
                '<p>Some pages are locked until all requirements are verified by the administrator.</p>' +
            '</div>';
        linksContainer.parentNode.insertBefore(lockNotice, linksContainer);
    }

    if (linksContainer) {
        ['attendance', 'report', 'dashboard'].forEach(function(navKey) {
            var link = linksContainer.querySelector('[data-nav-key="' + navKey + '"]');
            if (link) { link.remove(); }
        });
    }
}

<?php if ($all_verified): ?>
document.addEventListener('DOMContentLoaded', function() {
    <?php if (!isset($_GET['msg']) || $_GET['msg'] !== 'submitted'): ?>
    var verifiedModal = document.getElementById('verifiedModal');
    verifiedModal.style.display = 'flex';
    var hideVerified = function() { verifiedModal.style.display = 'none'; };
    setTimeout(hideVerified, 5000);
    document.getElementById('closeVerifiedModal').addEventListener('click', hideVerified);
    verifiedModal.addEventListener('click', function(e) { if (e.target === this) hideVerified(); });
    <?php endif; ?>
});
<?php endif; ?>

<?php if (isset($_GET['msg']) && $_GET['msg'] === 'submitted'): ?>
document.addEventListener('DOMContentLoaded', function() {
    var subModal = document.getElementById('submittedModal');
    subModal.style.display = 'flex';
    if (window.history.replaceState) window.history.replaceState({}, document.title, window.location.pathname);
});
document.getElementById('closeSubmittedModal').addEventListener('click', function(){
    hideNotifModal('submittedModal');
});
<?php endif; ?>

<?php if (isset($_GET['msg']) && $_GET['msg'] === 'profile_saved'): ?>
document.addEventListener('DOMContentLoaded', function() {
    var psModal = document.getElementById('profileSavedModal');
    psModal.style.display = 'flex';
    if (window.history.replaceState) window.history.replaceState({}, document.title, window.location.pathname);
});
<?php endif; ?>

function showNotifModal(modalId) { document.getElementById(modalId).style.display = 'flex'; }
function hideNotifModal(modalId) { document.getElementById(modalId).style.display = 'none'; }

/* ============================================================
   ADJUSTMENT: JPEG-ONLY VALIDATION (CLIENT SIDE)
   ------------------------------------------------------------
   Both the 2x2 photo upload and every requirement upload now
   accept ONLY "image/jpeg" files. This mirrors the accept="image/jpeg"
   attributes on the file inputs above and the server-side check in
   the save_profile_info POST handler. Anything else (PNG, GIF, WEBP,
   PDF, etc.) is rejected immediately with a clear message, before it
   is even sent to the server.
   ============================================================ */
var MAX_FILE_SIZE = 5 * 1024 * 1024;
var ALLOWED_TYPES = ['image/jpeg'];
var ALLOWED_PHOTO_TYPES = ['image/jpeg'];

function showValidationError(title, message, errorList) {
    document.getElementById('validationModalTitle').textContent = title;
    document.getElementById('validationModalMsg').textContent   = message;
    var listEl = document.getElementById('validationErrorList');
    if (errorList && errorList.length > 0) {
        listEl.innerHTML = errorList.map(function(e) { return '<li>' + e + '</li>'; }).join('');
        listEl.style.display = 'block';
    } else {
        listEl.innerHTML = '';
        listEl.style.display = 'none';
    }
    showNotifModal('validationModal');
}

function validateFileInput(input) {
    var file = input.files[0];
    if (!file) return true;
    var label = input.dataset.label || input.name || 'File';
    var allowedTypes = input.id === 'profilePhotoInput' ? ALLOWED_PHOTO_TYPES : ALLOWED_TYPES;
    if (!allowedTypes.includes(file.type)) {
        showValidationError('Invalid File Type',
            input.id === 'profilePhotoInput'
                ? 'Only JPEG (JPG) image files are allowed for the 2x2 photo.'
                : 'Only JPEG (JPG) image files are allowed for this requirement.',
            ['"' + label + '" — uploaded file type (' + (file.type || 'unknown') + ') is not supported. Please upload a JPEG (.jpg/.jpeg) file.']);
        input.value = '';
        return false;
    }
    if (file.size > MAX_FILE_SIZE) {
        var sizeMB = (file.size / (1024 * 1024)).toFixed(2);
        showValidationError('File Too Large', 'Each file must be 5 MB or smaller.',
            ['"' + label + '" is ' + sizeMB + ' MB — please compress or choose a smaller file.']);
        input.value = '';
        return false;
    }
    return true;
}

document.addEventListener('DOMContentLoaded', function() {
    var form = document.getElementById('requirementsForm');
    if (!form) return;
    form.addEventListener('submit', function(e) {
        var fileInputs = form.querySelectorAll('input[type="file"].rce-file-input-hidden');
        var errors = [];
        fileInputs.forEach(function(input) {
            var file = input.files[0];
            if (!file) return;
            var label = input.dataset.label || input.name || 'File';
            if (!ALLOWED_TYPES.includes(file.type)) {
                errors.push('"' + label + '" — invalid file type (' + (file.type || 'unknown') + '). Only JPEG is allowed.');
            } else if (file.size > MAX_FILE_SIZE) {
                var sizeMB = (file.size / (1024 * 1024)).toFixed(2);
                errors.push('"' + label + '" — file is ' + sizeMB + ' MB, exceeds the 5 MB limit.');
            }
        });
        if (errors.length > 0) {
            e.preventDefault();
            showValidationError('Please Fix the Following', 'Some files could not be submitted. See details below:', errors);
        }
    });
});

document.addEventListener('DOMContentLoaded', function() {
    var capFields = document.querySelectorAll(
        '.pinfo-field input[type="text"]:not([readonly]), ' +
        '.pinfo-field input[type="number"]:not([readonly]), ' +
        '.pinfo-field textarea:not([readonly])'
    );
    capFields.forEach(function(el) {
        el.addEventListener('input', function() {
            var pos    = this.selectionStart;
            var val    = this.value;
            var capped = val.charAt(0).toUpperCase() + val.slice(1);
            if (capped !== val) { this.value = capped; this.setSelectionRange(pos, pos); }
        });
        el.addEventListener('blur', function() {
            if (this.value.length > 0) {
                this.value = this.value.charAt(0).toUpperCase() + this.value.slice(1);
            }
        });
    });
});

/* ============================================================
   ADJUSTMENT: INSTANT UI UPDATE — PROFILE INFO SAVE VIA AJAX
   ------------------------------------------------------------
   Submitting "Save Information" no longer triggers a full page
   reload. The form is intercepted here, sent to AccomForm.php via
   fetch() (the PHP handler detects the AJAX request and returns
   JSON — see the save_profile_info block near the top of this
   file), and the relevant bits of the page (photo status/preview,
   the "Information Saved" confirmation) are updated immediately in
   place. All typed field values remain exactly as entered since the
   page never reloads.

   If JavaScript is unavailable, the form still works exactly as
   before: it posts normally and the page redirects back with
   ?msg=profile_saved.
   ============================================================ */
function updatePhotoSectionAfterSave(hasNewPhoto, newStatus) {
    if (!hasNewPhoto) return;

    var statusEl = document.querySelector('.photo-section .status');
    if (statusEl) {
        statusEl.className = 'status ' + newStatus.toLowerCase();
        statusEl.textContent = newStatus;
    }

    // A newly-uploaded photo is always "Pending" (never "Denied"),
    // so the upload controls and any denied-state messaging should
    // hide immediately, matching the PHP condition
    // `(!$photo || $photo_display === "Denied")` used on page load.
    var uploadWrap = document.querySelector('.photo-section .rce-file-input-wrap');
    if (uploadWrap) { uploadWrap.style.display = 'none'; }

    var reuploadLabel = document.querySelector('.photo-section .reupload-label');
    if (reuploadLabel) { reuploadLabel.style.display = 'none'; }

    var remarkBadge = document.querySelector('.photo-section .photo-remark-badge');
    if (remarkBadge) { remarkBadge.style.display = 'none'; }
}

document.addEventListener('DOMContentLoaded', function() {
    var profileForm = document.getElementById('profileInfoForm');
    if (!profileForm) return;

    profileForm.addEventListener('submit', function(e) {
        e.preventDefault();

        var formData = new FormData(profileForm);
        var submitBtn = profileForm.querySelector('.pinfo-save-btn');
        var originalBtnHtml = submitBtn ? submitBtn.innerHTML : '';

        if (submitBtn) {
            submitBtn.disabled = true;
            submitBtn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Saving...';
        }

        fetch('AccomForm.php', {
            method: 'POST',
            body: formData,
            credentials: 'same-origin',
            headers: { 'X-Requested-With': 'XMLHttpRequest' }
        })
        .then(function(response) {
            if (!response.ok) throw new Error('Request failed with status ' + response.status);
            return response.json();
        })
        .then(function(data) {
            if (submitBtn) {
                submitBtn.disabled = false;
                submitBtn.innerHTML = originalBtnHtml;
            }
            if (data && data.success) {
                updatePhotoSectionAfterSave(!!data.has_new_photo, 'Pending');
                showNotifModal('profileSavedModal');
            } else {
                showValidationError('Save Failed', 'We couldn\'t save your information. Please try again.', []);
            }
        })
        .catch(function() {
            if (submitBtn) {
                submitBtn.disabled = false;
                submitBtn.innerHTML = originalBtnHtml;
            }
            showValidationError('Network Error', 'Could not reach the server while saving. Please check your connection and try again.', []);
        });
    });
});

document.getElementById('closeVerifiedModal').addEventListener('click',    function() { hideNotifModal('verifiedModal'); });
document.getElementById('closeValidationModal').addEventListener('click',  function() { hideNotifModal('validationModal'); });
document.getElementById('closeProfileSavedModal').addEventListener('click',function() { hideNotifModal('profileSavedModal'); });
document.getElementById('closeStatusChangedModal').addEventListener('click',function() { hideNotifModal('statusChangedModal'); });

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
    var p = t.split(':');
    return parseInt(p[0],10)*3600 + parseInt(p[1],10)*60 + (p[2] ? parseInt(p[2],10) : 0);
}
function _anbNowSec() {
    var n = new Date();
    return n.getHours()*3600 + n.getMinutes()*60 + n.getSeconds();
}
function _anbFmt12(t) {
    if (!t) return '—';
    var p = t.split(':'); var h = parseInt(p[0],10), m = parseInt(p[1],10);
    var ap = h >= 12 ? 'PM' : 'AM'; h = h%12||12;
    return h + ':' + String(m).padStart(2,'0') + ' ' + ap;
}

function _anbHide(type) {
    if (type) _anb.dismissedWindows.add(type);
    _anb.currentType = '';

    if (_anb.tickInterval  !== null) { clearInterval(_anb.tickInterval);  _anb.tickInterval  = null; }
    if (_anb.autoHideTimer !== null) { clearTimeout(_anb.autoHideTimer);  _anb.autoHideTimer = null; }
    if (_anb.rafId         !== null) { cancelAnimationFrame(_anb.rafId);  _anb.rafId         = null; }

    document.getElementById('att-notif-bar').classList.remove('anb-visible');

    var prog = document.getElementById('anb-progress');
    if (prog) {
        prog.style.transition = 'none';
        prog.style.width = '0%';
        requestAnimationFrame(function() { prog.style.transition = ''; });
    }
}

function _anbShow(info) {
    if (!info) return;
    var dow = new Date().getDay();
    if (ANB_IS_WEEKEND || dow === 0 || dow === 6 || ANB_IS_ALL_DONE) return;
    if (_anb.dismissedWindows.has(info.type)) return;

    _anb.currentType = info.type;
    _anb.showStartTs = performance.now();

    document.getElementById('anb-label').textContent  = info.label + ' is open';
    document.getElementById('anb-window').textContent = 'Window: ' + info.start_fmt + ' \u2013 ' + info.end_fmt;
    document.getElementById('anb-countdown').textContent = 'Calculating...';

    var anbActionBtn = document.getElementById('anb-action-btn');
    if (info.is_late_window) {
        anbActionBtn.textContent = 'Request now';
    } else {
        anbActionBtn.textContent = 'Sign now';
    }
    anbActionBtn.onclick = function() {
        window.location.href = 'student_attendance.php';
    };

    document.getElementById('att-notif-bar')
            .classList.toggle('sidebar-collapsed', sidebar.classList.contains('collapsed'));

    if (_anb.tickInterval  !== null) { clearInterval(_anb.tickInterval);  _anb.tickInterval  = null; }
    if (_anb.autoHideTimer !== null) { clearTimeout(_anb.autoHideTimer);  _anb.autoHideTimer = null; }
    if (_anb.rafId         !== null) { cancelAnimationFrame(_anb.rafId);  _anb.rafId         = null; }

    var prog     = document.getElementById('anb-progress');
    var duration = _anb.autoHideDuration;
    var startTs  = _anb.showStartTs;

    if (prog) {
        prog.style.transition = 'none';
        prog.style.width = '100%';
        void prog.offsetWidth;
    }

    function rafTick(now) {
        var elapsed = now - startTs;
        var pct = Math.max(0, 100 - (elapsed / duration) * 100);
        if (prog) prog.style.width = pct + '%';
        if (pct > 0) {
            _anb.rafId = requestAnimationFrame(rafTick);
        } else {
            _anb.rafId = null;
            _anbHide(_anb.currentType);
        }
    }
    _anb.rafId = requestAnimationFrame(rafTick);

    var endSec = _anbTimeToSec(info.end_time);

    function tick() {
        var rem = endSec - _anbNowSec();
        if (rem <= 0) { _anbHide(_anb.currentType); return; }
        var m = Math.floor(rem / 60), s = rem % 60;
        document.getElementById('anb-countdown').textContent =
            '\u23F3 ' + m + 'm ' + String(s).padStart(2,'0') + 's left';
    }
    tick();
    _anb.tickInterval = setInterval(tick, 1000);

    var capturedType = info.type;
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

var _anbPmLateShown = false;

function _anbWatch() {
    var dow = new Date().getDay();
    if (ANB_IS_WEEKEND || dow === 0 || dow === 6 || !ANB_TODAY_SETTINGS || ANB_IS_ALL_DONE) return;

    var ns = _anbNowSec();
    var DEFS = {
        am_time_in:  { label: 'AM Duty Sign In',  startKey: 'am_time_in_start',  endKey: 'am_time_in_end'  },
        am_time_out: { label: 'AM Duty Sign Out', startKey: 'am_time_out_start', endKey: 'am_time_out_end' },
        pm_time_in:  { label: 'PM Duty Sign In',  startKey: 'pm_time_in_start',  endKey: 'pm_time_in_end'  },
        pm_time_out: { label: 'PM Duty Sign Out', startKey: 'pm_time_out_start', endKey: 'pm_time_out_end' },
    };

    for (var i = 0; i < ANB_ORDER.length; i++) {
        var type     = ANB_ORDER[i];
        var def      = DEFS[type];
        var startStr = ANB_TODAY_SETTINGS[def.startKey];
        var endStr   = ANB_TODAY_SETTINGS[def.endKey];
        if (!startStr || !endStr) continue;
        var s = _anbTimeToSec(startStr), e = _anbTimeToSec(endStr);
        if (ns < s || ns > e)                    continue;
        if (_anb.dismissedWindows.has(type))     continue;
        if (_anb.shownWindows.has(type))         continue;

        _anb.shownWindows.add(type);
        _anbShow({
            type:           type,
            label:          def.label,
            start_fmt:      _anbFmt12(startStr),
            end_fmt:        _anbFmt12(endStr),
            start_time:     startStr,
            end_time:       endStr,
            is_late_window: false,
        });
        return;
    }

    if (_anbPmLateShown) return;
    var pmOutEndStr = ANB_TODAY_SETTINGS['pm_time_out_end'];
    if (!pmOutEndStr) return;
    var pmOutEndSec   = _anbTimeToSec(pmOutEndStr);
    var lateWindowEnd = pmOutEndSec + 3600;
    if (ns <= pmOutEndSec || ns > lateWindowEnd) return;
    if (_anb.dismissedWindows.has('pm_time_out_late')) return;

    var lateWindowEndH   = Math.floor(lateWindowEnd / 3600);
    var lateWindowEndM   = Math.floor((lateWindowEnd % 3600) / 60);
    var lateWindowEndStr = String(lateWindowEndH).padStart(2,'0') + ':' +
                           String(lateWindowEndM).padStart(2,'0') + ':00';

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
    var dow = new Date().getDay();
    if (ANB_BADGE_INFO && !ANB_IS_WEEKEND && dow !== 0 && dow !== 6 && !ANB_IS_ALL_DONE) {
        setTimeout(function() { _anbShow(ANB_BADGE_INFO); }, 800);
    }
})();

setInterval(_anbWatch, 30000);

(function() {

    var REQ_LABELS = {
        'cert_registration': 'Certification of Registration',
        'certificate_pdos':  'PDOS Certificate',
        'ojt_sheet':         'OJT Sheet',
        'application_sit':   'Application SIT',
        'waiver_form':       'Waiver Form',
        'student_contract':  'Student Contract',
        'psych_result':      'Psych Result',
        'medical_result':    'Medical Result',
    };

    var SPECIAL_KEYS = ['application_sit', 'waiver_form', 'student_contract'];

    var _lastStatus = {};
    document.querySelectorAll('.req-card-e[data-req-type]').forEach(function(card) {
        var k = card.getAttribute('data-req-type');
        _lastStatus[k] = card.getAttribute('data-status') || 'Pending';
    });

    function applyCardStatus(key, info) {
        var card = document.getElementById('reqCard_' + key);
        if (!card) return;

        var status   = info.status   || 'Pending';
        var remark   = info.remark   || '';
        var hasFile  = !!info.has_file;
        var isPdf    = !!info.is_pdf;
        var imgSrc   = info.img_src  || '';
        var isSpecial = SPECIAL_KEYS.indexOf(key) !== -1;

        var hdr = document.getElementById('rceHeader_' + key);
        if (hdr) {
            hdr.className = 'rce-header ' + (
                status === 'Verified' ? 'hdr-verified' :
                status === 'Denied'   ? 'hdr-denied'   : 'hdr-pending'
            );
        }

        var iconEl = document.getElementById('rceStatusIcon_' + key);
        if (iconEl) {
            iconEl.className = (
                status === 'Verified' ? 'fas fa-check-circle' :
                status === 'Denied'   ? 'fas fa-times-circle' : 'fas fa-clock'
            ) + ' rce-status-icon';
        }

        var labelEl = document.getElementById('rceStatusLabel_' + key);
        if (labelEl) { labelEl.textContent = status; }

        var previewEl = document.getElementById('rcePreview_' + key);
        if (previewEl) {
            if (hasFile) {
                previewEl.style.display = '';
                if (isPdf) {
                    previewEl.innerHTML = '<div class="rce-pdf-thumb"><i class="fas fa-file-pdf"></i><span>PDF</span></div>';
                } else if (imgSrc) {
                    previewEl.innerHTML = '<img src="' + imgSrc + '" class="rce-preview-img" onclick="openPreview(this.src)" title="Click to view full size">';
                }
            } else {
                previewEl.style.display = 'none';
                previewEl.innerHTML = '';
            }
        }

        var remarkEl = document.getElementById('rceRemark_' + key);
        if (remarkEl) {
            if (status === 'Denied' && remark) {
                remarkEl.style.display = '';
                var remarkText = remarkEl.querySelector('.rce-remark-text');
                if (remarkText) { remarkText.textContent = 'Reason: ' + remark; }
                else { remarkEl.innerHTML = '<i class="fas fa-exclamation-circle"></i><span class="rce-remark-text">Reason: ' + remark + '</span>'; }
            } else {
                remarkEl.style.display = 'none';
            }
        }

        var reuploadEl = document.getElementById('rceReupload_' + key);
        if (reuploadEl) {
            reuploadEl.style.display = status === 'Denied' ? '' : 'none';
        }

        var uploadRow = document.getElementById('rceUploadRow_' + key);
        if (uploadRow) {
            if (isSpecial) {
                var controls  = document.getElementById('rceUploadControls_' + key);
                var needsUpload = !hasFile || status === 'Denied';
                if (controls) { controls.style.display = needsUpload ? 'contents' : 'none'; }

                var eyeBtn = document.getElementById('eyeBtn_' + key);
                if (eyeBtn) {
                    if (hasFile && status !== 'Denied') {
                        eyeBtn.classList.add('eye-hidden');
                    } else {
                        eyeBtn.classList.remove('eye-hidden');
                    }
                }
            } else {
                uploadRow.style.display = (!hasFile || status === 'Denied') ? 'flex' : 'none';
            }
        }

        card.setAttribute('data-status', status);

        card.classList.remove('rce-status-updated');
        void card.offsetWidth;
        card.classList.add('rce-status-updated');
        setTimeout(function() { card.classList.remove('rce-status-updated'); }, 1500);
    }

    function notifyStatusChange(key, newStatus, remark) {
        var label = REQ_LABELS[key] || key;
        /* ADJUSTMENT: encouraging, informative wording; no icon on top and no redundant
           "<Requirement> — <Status>" title (the requirement name is already in the message). */
        var title, msg, btn;
        if (newStatus === 'Verified') {
            title = 'Great Job!';
            msg   = 'Your "' + label + '" has been verified by the administrator. One step closer to completing your requirements!';
            btn   = 'Great, Thanks!';
        } else if (newStatus === 'Denied') {
            title = "Let's Fix This Together";
            msg   = 'Your "' + label + '" needs a quick update before it can be verified. '
                  + (remark ? 'Administrator\'s note: "' + remark + '". ' : 'Please check the remark on the card. ')
                  + 'Re-upload the corrected file and you\'ll be back on track!';
            btn   = 'Got It, I\'ll Re-upload';
        } else {
            title = 'Under Review';
            msg   = 'Your "' + label + '" is now back in the review queue. The administrator will check it soon — no action is needed from you right now.';
            btn   = 'OK, Got It';
        }

        /* every lookup is guarded so a missing element can never break the status polling */
        var statusIconEl = document.getElementById('statusChangedIcon');
        if (statusIconEl) { statusIconEl.className = 'notif-modal-icon'; statusIconEl.innerHTML = ''; }
        var titleEl = document.getElementById('statusChangedTitle');
        var msgEl   = document.getElementById('statusChangedMsg');
        var btnEl   = document.getElementById('closeStatusChangedModal');
        if (titleEl) titleEl.textContent = title;
        if (msgEl)   msgEl.textContent   = msg;
        if (btnEl)   btnEl.textContent   = btn;
        showNotifModal('statusChangedModal');
    }

    function pollStatuses() {
        fetch('AccomForm.php?poll_status=1', { credentials: 'same-origin' })
            .then(function(r) { return r.ok ? r.json() : null; })
            .then(function(data) {
                if (!data) return;

                var allVerifiedNow = true;

                Object.keys(data).forEach(function(key) {
                    var info      = data[key];
                    var newStatus = info.status || 'Pending';
                    var oldStatus = _lastStatus[key] || 'Pending';

                    if (newStatus !== oldStatus) {
                        applyCardStatus(key, info);
                        notifyStatusChange(key, newStatus, info.remark || '');
                        _lastStatus[key] = newStatus;
                    }

                    if (newStatus !== 'Verified') { allVerifiedNow = false; }
                });

                /* ── Live sidebar unlock the instant all 8 requirement
                   types come back Verified — see activateVerifiedSidebar()
                   above for full details. Live sidebar RE-LOCK the
                   instant any of them fall out of "Verified" again
                   (e.g. admin reverts a requirement back to Pending or
                   Denied) — see deactivateVerifiedSidebar() above. ── */
                if (allVerifiedNow && !sidebarVerifiedActivated) {
                    activateVerifiedSidebar();
                    showNotifModal('verifiedModal');
                } else if (!allVerifiedNow && sidebarVerifiedActivated) {
                    deactivateVerifiedSidebar();
                }
            })
            .catch(function() { /* silent — network blip, retry next interval */ });
    }

    setTimeout(function() {
        pollStatuses();
        setInterval(pollStatuses, 7000);
    }, 3000);

})();
</script>

</body>
</html>