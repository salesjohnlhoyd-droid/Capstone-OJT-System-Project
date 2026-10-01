<?php
session_start();
include "db.php";

/* ================= SESSION CHECK ================= */
if (!isset($_SESSION['user_id']) || $_SESSION['role'] != "student") {
    header("Location: login.php");
    exit;
}

$user_id = $_SESSION['user_id'];

/* ADJUSTMENT: Company List sidebar indicator — initial count of endorsement letters that need the
   student's attention (same rule as company_list.php). Fails open to 0 (e.g. table not created yet). */
$endo_attention_count = 0;
try {
    $_endo_q = $conn->prepare("SELECT validation_status, student_viewed FROM endorsement_letters WHERE student_id = ?");
    if ($_endo_q) {
        $_endo_q->bind_param("i", $user_id);
        $_endo_q->execute();
        $_endo_r = $_endo_q->get_result();
        while ($_endo_row = $_endo_r->fetch_assoc()) {
            $_endo_st = $_endo_row['validation_status'] ?: 'Awaiting Upload';
            if (!$_endo_row['student_viewed'] || in_array($_endo_st, ['Awaiting Upload', 'Rejected'], true)) $endo_attention_count++;
        }
        $_endo_q->close();
    }
} catch (\Throwable $e) { $endo_attention_count = 0; }
date_default_timezone_set("Asia/Manila");

/* ============================================================
   AJAX ENDPOINT — returns live requirement statuses as JSON
   ------------------------------------------------------------
   ADJUSTMENT: powers the live sidebar lock/unlock activation
   further down this page (see activateVerifiedSidebar() /
   deactivateVerifiedSidebar() in the <script> block), mirroring
   the exact same polling pattern already used by company_list.php
   and AccomForm.php. This keeps this page's sidebar in sync with
   those two — it unlocks (or re-locks) the instant the
   administrator verifies (or reverts) the student's last
   remaining requirement, without needing a manual reload.
   URL: student_profile.php?poll_status=1
   ============================================================ */
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
        $ps = $conn->prepare("SELECT status FROM requirements WHERE user_id=? AND requirement_type=? LIMIT 1");
        $ps->bind_param("is", $user_id, $k);
        $ps->execute();
        $pr = $ps->get_result()->fetch_assoc();
        $ps->close();
        $out[$k] = [
            'status' => $pr['status'] ?? 'Pending',
        ];
    }
    echo json_encode($out);
    exit;
}

/* ================= REQUIREMENT VERIFICATION GATE (SIDEBAR SYNC) =================
   ADJUSTMENT: mirrors the exact same $all_verified gate already
   computed on company_list.php / AccomForm.php, so this page's
   sidebar (My Profile / Attendance / Reports / Dashboard visibility,
   plus the lock notice) stays in sync with those pages instead of
   always showing every link regardless of requirement-verification
   status. Nothing about the actual requirement data or any other
   page logic is changed — this only reads the same `requirements`
   table those other pages already read. */
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

$all_verified = true;
foreach ($required_types as $_rt) {
    $_rt_stmt = $conn->prepare("SELECT status FROM requirements WHERE user_id=? AND requirement_type=? LIMIT 1");
    $_rt_stmt->bind_param("is", $user_id, $_rt);
    $_rt_stmt->execute();
    $_rt_row = $_rt_stmt->get_result()->fetch_assoc();
    $_rt_stmt->close();
    if (($_rt_row['status'] ?? '') !== 'Verified') {
        $all_verified = false;
        break;
    }
}

// ================= ATTENDANCE SIDEBAR BADGE =================
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

        // Check all four standard windows for a badge
        $_att_am_in_done  = $_att_log && ($_att_log['am_time_in']  !== null && $_att_log['am_time_in']  !== '' && $_att_log['am_time_in']  !== 'missed');
        $_att_am_out_done = $_att_log && ($_att_log['am_time_out'] !== null && $_att_log['am_time_out'] !== '' && $_att_log['am_time_out'] !== 'missed');
        $_att_pm_in_done  = $_att_log && ($_att_log['pm_time_in']  !== null && $_att_log['pm_time_in']  !== '' && $_att_log['pm_time_in']  !== 'missed');
        $_att_pm_out_done = $_att_log && ($_att_log['pm_time_out'] !== null && $_att_log['pm_time_out'] !== '' && $_att_log['pm_time_out'] !== 'missed');

        $_att_all_done = $_att_am_in_done && $_att_am_out_done && $_att_pm_in_done && $_att_pm_out_done;

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
                $col_map = [
                    'am_time_in'  => 'am_time_in',
                    'am_time_out' => 'am_time_out',
                    'pm_time_in'  => 'pm_time_in',
                    'pm_time_out' => 'pm_time_out',
                ];
                $col = $col_map[$type];
                $val = (is_array($_att_log) && array_key_exists($col, $_att_log))
                    ? $_att_log[$col]
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

        // Pass 2: check PM Sign Out 1-hour late window (if no normal badge found yet)
        if (!$attendance_badge_info && !$_att_all_done) {
            $_att_pm_out_end = $_att_setting['pm_time_out_end'] ?? null;
            if ($_att_pm_out_end) {
                $_att_pm_out_end_sec   = $timeToSec($_att_pm_out_end);
                $_att_late_window_end  = $_att_pm_out_end_sec + 3600;

                if ($_att_now_sec > $_att_pm_out_end_sec && $_att_now_sec <= $_att_late_window_end) {
                    $_att_pm_out_val    = (is_array($_att_log) && isset($_att_log['pm_time_out'])) ? $_att_log['pm_time_out'] : null;
                    $_att_pm_out_done2  = ($_att_pm_out_val !== null && $_att_pm_out_val !== '' && $_att_pm_out_val !== 'missed');

                    if (!$_att_pm_out_done2) {
                        $lateWindowEndH   = floor($_att_late_window_end / 3600);
                        $lateWindowEndM   = floor(($_att_late_window_end % 3600) / 60);
                        $lateWindowEndStr = sprintf('%02d:%02d:00', $lateWindowEndH, $lateWindowEndM);

                        $att_sidebar_badge     = true;
                        $attendance_badge_info = [
                            'type'           => 'pm_time_out_late',
                            'label'          => 'PM Sign Out Late Request',
                            'start_fmt'      => $fmt12att($_att_pm_out_end) . ' (missed)',
                            'end_fmt'        => $fmt12att($lateWindowEndStr) . ' (deadline)',
                            'start_time'     => $_att_pm_out_end,
                            'end_time'       => $lateWindowEndStr,
                            'is_late_window' => true,
                        ];
                    }
                }
            }
        }
    }
}

/* ================= FETCH STUDENT INFO =================
   NOTE: skill1/skill2/skill3/exp1/exp2 are no longer selected here.
   Skills and experience now live entirely in the normalized
   student_skills / student_experience tables (see below) — this
   query only needs what's still actually stored on
   student_information, i.e. the photo. */
$stmt = $conn->prepare("
    SELECT u.first_name, u.middle_name, u.last_name, u.email, u.course, u.deploy_status,
       si.student_photo
    FROM users u
    LEFT JOIN student_information si ON si.user_id = u.id
    WHERE u.id = ?
");
$stmt->bind_param("i", $user_id);
$stmt->execute();
$student = $stmt->get_result()->fetch_assoc();

/* FULL NAME — includes middle name when present */
$full_name = trim(
    $student['first_name'] . " " .
    (!empty($student['middle_name']) ? $student['middle_name'] . " " : "") .
    $student['last_name']
);

/* ================= DEPLOY STATUS FLAG (for JS sidebar gate) ================= */
$is_deployed = ($student['deploy_status'] === 'Deployed');

/* ================= UNIFIED SKILL / EXPERIENCE ENTRY LISTS (for the resume form) =================
   Skills and experience now live entirely in two normalized,
   one-row-per-entry tables — the old skill1/skill2/skill3 and
   exp1/exp2 columns on student_information (and the migration code
   that used to copy data out of them) have been removed now that
   every student's data has been carried over:
     student_skills(id, user_id, entry_text, sort_order, created_at)
     student_experience(id, user_id, entry_text, sort_order, created_at)

   Adding a skill = inserting a row (no schema change, ever — a
   student having 10 skills doesn't affect any other student's row
   or the table's columns). Deleting the 2nd of 3 skills = deleting
   that row and re-numbering the remaining two to sort_order 0,1, so
   the student is left with skill #1 and (former) skill #3 sitting in
   slots 1 and 2 — no gap — without ever touching table structure.

   ensure_resume_tables() creates these two tables if they don't
   exist yet (e.g. on a fresh environment), so no manual DB step is
   required. */

function ensure_resume_tables(mysqli $conn): void {
    $conn->query("
        CREATE TABLE IF NOT EXISTS student_skills (
            id INT UNSIGNED NOT NULL AUTO_INCREMENT,
            user_id INT UNSIGNED NOT NULL,
            entry_text TEXT NOT NULL,
            sort_order INT UNSIGNED NOT NULL DEFAULT 0,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY idx_user_sort (user_id, sort_order)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");
    $conn->query("
        CREATE TABLE IF NOT EXISTS student_experience (
            id INT UNSIGNED NOT NULL AUTO_INCREMENT,
            user_id INT UNSIGNED NOT NULL,
            entry_text TEXT NOT NULL,
            sort_order INT UNSIGNED NOT NULL DEFAULT 0,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY idx_user_sort (user_id, sort_order)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");
}

function fetch_entries(mysqli $conn, string $table, int $user_id): array {
    $q = $conn->prepare("SELECT id, entry_text FROM {$table} WHERE user_id=? ORDER BY sort_order ASC, id ASC");
    $q->bind_param("i", $user_id);
    $q->execute();
    $rows = $q->get_result()->fetch_all(MYSQLI_ASSOC);
    $q->close();
    return $rows; // each: ['id' => int, 'entry_text' => string]
}

ensure_resume_tables($conn);

$skill_rows = fetch_entries($conn, 'student_skills', $user_id);
$exp_rows   = fetch_entries($conn, 'student_experience', $user_id);

// Kept as plain string lists too, since a couple of read-only spots
// below (the "Current Skills & Experience" preview) only need the text.
$skill_entries = array_map(fn($r) => $r['entry_text'], $skill_rows);
$exp_entries   = array_map(fn($r) => $r['entry_text'], $exp_rows);

/* ================= SKILL / EXPERIENCE FIELD PLACEHOLDERS =================
   ADJUSTMENT: fields no longer show a numbered label like "Skill 1" /
   "Skill 2" / "Experience 1". Each textarea now shows a single, brief
   descriptive hint as its placeholder — visible ONLY as a default when
   the field has no entry yet (standard HTML placeholder behavior: the
   moment the student types (or a saved entry already has text), the
   placeholder simply doesn't show, and the entry text itself is what's
   displayed/saved). This applies identically to every skill/experience
   row regardless of position, so entries are no longer numbered at all. */
$SKILL_ENTRY_PLACEHOLDER = 'Briefly describe this skill (e.g., Adobe Photoshop, Team Leadership, Customer Service)';
$EXP_ENTRY_PLACEHOLDER   = 'Briefly describe this experience (e.g., role, company/organization, and key duties)';

/* ================= HANDLE SKILL & EXPERIENCE FORM SUBMISSION =================
   Every skill comes in as one ordered skill_entry[] array (same for
   exp_entry[]) from the form, regardless of whether it's a
   previously-saved entry or a freshly typed one. On save:
     1. ALL of this student's existing rows in student_skills /
        student_experience are deleted.
     2. The submitted list (blanks filtered out) is re-inserted in
        order, sort_order 0..n-1.
   Net effect: adding a skill just means a longer submitted list ->
   more rows, no schema change. Deleting the 2nd of 3 skills means the
   submitted list only has 2 items -> after the delete-then-reinsert,
   the student has exactly 2 rows at sort_order 0,1 — the old 3rd
   entry has shifted up into slot 2, with no gap and no leftover
   column/row for the deleted entry. This is the row-based equivalent
   of "the table still shows skill1, skill2 instead of skill1, skill3"
   that was asked for, achieved without ever altering table structure.

   Line endings are normalized (\r\n / \r -> \n) before saving, since
   browsers submit textarea line breaks as \r\n regardless of how they
   were typed (Enter or Shift+Enter) — normalizing keeps stored text
   consistent no matter which browser/OS the student used. */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_resume'])) {

    /* ══════════════════════════════════════════════════════════════
       DEBUG PANEL
       ------------------------------------------------------------
       Visit the page as student_profile.php?debug=1 and then submit
       the resume form (the form has no explicit "action", so it
       posts back to whatever URL — including ?debug=1 — you loaded
       it from). Instead of the usual silent redirect, you'll get a
       plain on-page report of exactly what happened: the raw POST
       data PHP received, the cleaned entry lists, how many rows were
       deleted vs (re)inserted for each table, and the exact mysqli
       error text if anything failed. Everything is also written to
       the PHP error log via error_log() regardless of ?debug=1, so
       it's visible server-side even without the on-page panel.
       This is purely additive — with no ?debug=1, behavior (including
       the always-on save-failure checks) is unchanged from before.
    ══════════════════════════════════════════════════════════════ */
    $DEBUG_MODE = isset($_GET['debug']) || isset($_POST['debug']);
    $debug_log  = [];
    $debug_log['1. REQUEST_METHOD']      = $_SERVER['REQUEST_METHOD'];
    $debug_log['2. update_resume isset'] = isset($_POST['update_resume']) ? 'yes' : 'no';
    $debug_log['3. raw $_POST']          = $_POST;
    $debug_log['4. session user_id']     = $user_id;

    $normalize_line_endings = fn($v) => str_replace(["\r\n", "\r"], "\n", $v);

    $skill_entries_in = (isset($_POST['skill_entry']) && is_array($_POST['skill_entry'])) ? $_POST['skill_entry'] : [];
    $skill_entries_in = array_values(array_filter(
        array_map(fn($v) => trim($normalize_line_endings($v)), $skill_entries_in),
        fn($v) => $v !== ''
    ));

    $exp_entries_in = (isset($_POST['exp_entry']) && is_array($_POST['exp_entry'])) ? $_POST['exp_entry'] : [];
    $exp_entries_in = array_values(array_filter(
        array_map(fn($v) => trim($normalize_line_endings($v)), $exp_entries_in),
        fn($v) => $v !== ''
    ));

    $debug_log['5. cleaned skill_entries_in'] = $skill_entries_in;
    $debug_log['6. cleaned exp_entries_in']   = $exp_entries_in;

    /* ── BUGFIX (carried over): silent save failures ──────────────
       Every DB call below is checked; on failure we stop and surface
       the real mysqli error instead of silently pretending the save
       succeeded. */
    $fatal = function(string $stage, string $error) use (&$debug_log, $DEBUG_MODE) {
        $debug_log['FATAL @ ' . $stage] = $error;
        error_log('[student_profile resume save] ' . print_r($debug_log, true));
        die($DEBUG_MODE
            ? '<pre style="background:#111;color:#0f0;padding:16px;white-space:pre-wrap;">' . htmlspecialchars(print_r($debug_log, true)) . '</pre>'
            : "Resume save failed ({$stage}): " . $error);
    };

    $conn->begin_transaction();

    // ── Skills: delete all existing rows for this student, then re-insert submitted list in order ──
    $del_skills = $conn->prepare("DELETE FROM student_skills WHERE user_id=?");
    if ($del_skills === false) { $conn->rollback(); $fatal('prepare(delete skills)', $conn->error); }
    $del_skills->bind_param("i", $user_id);
    if (!$del_skills->execute()) { $conn->rollback(); $fatal('execute(delete skills)', $del_skills->error); }
    $debug_log['7. skills deleted'] = $del_skills->affected_rows;
    $del_skills->close();

    if ($skill_entries_in) {
        $ins_skill = $conn->prepare("INSERT INTO student_skills (user_id, entry_text, sort_order) VALUES (?, ?, ?)");
        if ($ins_skill === false) { $conn->rollback(); $fatal('prepare(insert skills)', $conn->error); }
        foreach ($skill_entries_in as $i => $text) {
            $ins_skill->bind_param("isi", $user_id, $text, $i);
            if (!$ins_skill->execute()) { $conn->rollback(); $fatal('execute(insert skill #' . $i . ')', $ins_skill->error); }
        }
        $debug_log['8. skills inserted'] = count($skill_entries_in);
        $ins_skill->close();
    } else {
        $debug_log['8. skills inserted'] = 0;
    }

    // ── Experience: same delete-then-reinsert pattern ──
    $del_exp = $conn->prepare("DELETE FROM student_experience WHERE user_id=?");
    if ($del_exp === false) { $conn->rollback(); $fatal('prepare(delete experience)', $conn->error); }
    $del_exp->bind_param("i", $user_id);
    if (!$del_exp->execute()) { $conn->rollback(); $fatal('execute(delete experience)', $del_exp->error); }
    $debug_log['9. experience deleted'] = $del_exp->affected_rows;
    $del_exp->close();

    if ($exp_entries_in) {
        $ins_exp = $conn->prepare("INSERT INTO student_experience (user_id, entry_text, sort_order) VALUES (?, ?, ?)");
        if ($ins_exp === false) { $conn->rollback(); $fatal('prepare(insert experience)', $conn->error); }
        foreach ($exp_entries_in as $i => $text) {
            $ins_exp->bind_param("isi", $user_id, $text, $i);
            if (!$ins_exp->execute()) { $conn->rollback(); $fatal('execute(insert experience #' . $i . ')', $ins_exp->error); }
        }
        $debug_log['10. experience inserted'] = count($exp_entries_in);
        $ins_exp->close();
    } else {
        $debug_log['10. experience inserted'] = 0;
    }

    $conn->commit();

    $debug_log['11. RESULT'] = 'Save completed without a thrown DB error.';
    error_log('[student_profile resume save] ' . print_r($debug_log, true));

    if ($DEBUG_MODE) {
        // Re-read straight back from the DB (not from PHP variables) so you
        // can see, in black and white, whether the rows actually landed.
        $verify_skills = fetch_entries($conn, 'student_skills', $user_id);
        $verify_exp    = fetch_entries($conn, 'student_experience', $user_id);
        $debug_log['12. student_skills rows re-read from DB']     = $verify_skills;
        $debug_log['13. student_experience rows re-read from DB'] = $verify_exp;

        echo '<pre style="background:#111;color:#0f0;padding:16px;white-space:pre-wrap;font-family:monospace;font-size:13px;">';
        echo htmlspecialchars(print_r($debug_log, true));
        echo '</pre>';
        echo '<p style="font-family:sans-serif;"><a href="' . htmlspecialchars($_SERVER['PHP_SELF']) . '">&larr; Continue to profile page (without debug)</a></p>';
        exit;
    }

    header("Location: " . $_SERVER['PHP_SELF']);
    exit;
}

/* ================= FETCH COMPANY DETAILS IF DEPLOYED ================= */
$company = null;

if ($student['deploy_status'] === "Deployed") {
    $stmt = $conn->prepare("
        SELECT
            u.first_name, u.last_name,
            ci.company AS company_name,
            ci.contact_first_name,
            ci.contact_middle_initial,
            ci.contact_last_name,
            cp.telephone,
            cp.google_map_link
        FROM ojt_assignments oa
        INNER JOIN users u ON u.id = oa.company_id
        LEFT JOIN company_information ci ON ci.user_id = u.id
        LEFT JOIN company_profile cp ON cp.user_id = u.id
        WHERE oa.student_id = ?
        LIMIT 1
    ");
    $stmt->bind_param("i", $user_id);
    $stmt->execute();
    $company = $stmt->get_result()->fetch_assoc();

    if ($company) {
        $company['supervisor_name'] =
            $company['contact_first_name'] . " " .
            (!empty($company['contact_middle_name']) ? $company['contact_middle_name'] . " " : "") .
            $company['contact_last_name'];
    }
}

/* ================= FETCH STUDENT APPLICATION ================= */
$app = $conn->prepare("
    SELECT * FROM ojt_applications
    WHERE student_id = ?
    ORDER BY created_at DESC
    LIMIT 1
");
$app->bind_param("i", $user_id);
$app->execute();
$resume = $app->get_result()->fetch_assoc();

/* ══════════════════════════════════════════════
   FINAL GRADE (EVALUATION RATING) — NEW SOURCE OF TRUTH
   ------------------------------------------------------------
   Mirrors admin_final_grades.php: final grades are no longer read
   from the old `final_grades` table (avg_company_grade /
   avg_admin_grade / avg_weighted_grade on a 0–100 scale). They now
   come directly from `ojt_assignments`, which carries the General /
   Specific / Overall Competency Ratings (1.0–5.0 scale, LOWER is
   better) persisted by company_reports.php's save_eval=1 handler
   once a company submits and locks a student's OJT/Internship
   Training Plan evaluation. The row is only shown here once the
   admin has published it (is_published = 1), same gate as before.
   ============================================================ */
$final_grade = null;
if ($student['deploy_status'] === "Deployed") {
    $fg_stmt = $conn->prepare("
        SELECT oa.general_competency_rating, oa.specific_competency_rating, oa.overall_competency_rating,
               oa.eval_submitted_at, oa.is_published, oa.published_at,
               ci.company AS company_name
        FROM ojt_assignments oa
        LEFT JOIN company_information ci ON ci.user_id = oa.company_id
        WHERE oa.student_id = ?
          AND oa.is_published = 1
        LIMIT 1
    ");
    $fg_stmt->bind_param("i", $user_id);
    $fg_stmt->execute();
    $final_grade = $fg_stmt->get_result()->fetch_assoc();
    $fg_stmt->close();
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Student Profile</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Lora:ital,wght@0,400;0,500;0,600;1,400&family=DM+Sans:wght@300;400;500;600;700&family=DM+Mono:wght@400;500&display=swap" rel="stylesheet">
    <style>
        /* ── Design tokens ── */
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

            --maroon:       #1B2A4A;
            --gold:         #FFD700;
            --active-nav:   #1B2A4A;
            --ink:          #2d3748;
            --ink-muted:    #5A6272;
            --ink-faint:    #8A93A6;
            --surface:      #ffffff;
            --surface-soft: #F3F5F9;
            --surface-warm: #EEF1F6;
            --border:       #C3CADA;
            --border-light: #DCE1EC;
            --teal:         #2C5A2C;
            --teal-light:   #EAF3EA;
            --teal-dark:    #2C5A2C;
            --blue:         #1B2A4A;
            --blue-light:   #E7ECF7;
            --amber:        #A0850A;
            --amber-light:  #FAF3DC;
            --red:          #A02A2A;
            --red-light:    #F7E9E9;
            --radius-sm:    0;
            --radius-md:    0;
            --radius-lg:    0;
            --shadow-card:  none;
            --shadow-lift:  none;

            /* Legacy aliases */
            --neust-maroon: #07145fe5;
            --neust-gold:   #FFD700;
            --neust-active: #1B2A4A;
            --bg:           #EEF1F6;
            --white:        #ffffff;
            --text:         #2d3748;

            /* Digital Resume pagination rule color */
            --dr-rule:      #c8cfe8;
        }

        *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }

        body {
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            background: var(--surface-warm);
            color: var(--ink);
            display: flex;
            min-height: 100vh;
            line-height: 1.6;
        }

        /* ══════════════════════════════════════════
           SIDEBAR — matches student_attendance.php exactly
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

        /* Header — name + role label */
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

        /* ── Sidebar lock notice (not all requirements verified yet) —
           ADJUSTMENT: synced with company_list.php / AccomForm.php so
           this page shows the same lock messaging and hides/shows the
           same links based on $all_verified. ── */
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

        .sidebar a:hover:not(.active):not(.nav-locked) {
            background: rgba(255,255,255,0.07);
            color: white;
        }
        .sidebar a.active {
            background: var(--neust-active);
            color: white;
            border-left: 4px solid var(--neust-gold);
        }

        /* ── Locked sidebar links ── */
        .sidebar a.nav-locked { cursor: not-allowed; opacity: 0.55; }
        .sidebar a.nav-locked:hover { background: rgba(255,255,255,0.04); color: #cbd5e0; }
        .nav-lock-icon {
            font-size: 11px; color: var(--neust-gold); position: absolute;
            right: 22px; top: 50%; transform: translateY(-50%); opacity: 0.85;
        }
        .sidebar.collapsed .nav-lock-icon { display: none; }

        /* ── Attendance sidebar badge ── */
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

        /* ── Journal badge ── */
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


        /* ══════════════════════════════════════════════════════════════════
           ADJUSTMENT: Company List live indicator + popup — ported from
           company_list.php so every student page behaves the same.
           • .sidebar-badge-endo : RED count on the "Company List" link
             (endorsement letters that need the student's attention).
           • .cv-top-toast       : the popup shown when an application moves
             stage (administrator.php's navy popup bar).
           ══════════════════════════════════════════════════════════════════ */
        .sidebar-badge-endo {
            background: #dc2626; color: #ffffff; border-radius: 50%;
            min-width: 18px; height: 18px; font-size: 10px; font-weight: 800;
            display: none; align-items: center; justify-content: center;
            position: absolute; right: 18px; top: 50%; transform: translateY(-50%);
            padding: 0 3px; animation: badge-pulse-endo 2s ease-in-out infinite;
        }
        .sidebar-badge-endo.is-on { display: inline-flex; }
        @keyframes badge-pulse-endo {
            0%, 100% { box-shadow: 0 0 0 0 rgba(220,38,38,0.55); }
            50%       { box-shadow: 0 0 0 6px rgba(220,38,38,0); }
        }
        .sidebar.collapsed .sidebar-badge-endo { right: 14px; top: 10px; transform: none; }
        @media (prefers-reduced-motion: reduce) { .sidebar-badge-endo { animation: none; } }

        .cv-top-toast {
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
        .cv-top-toast.show { opacity: 1; }
        .cv-top-toast i { color: #8FD18F; font-size: 18px; flex-shrink: 0; }
        .cv-top-toast strong { color: #ffffff; font-weight: 700; }
        .cv-top-toast.is-error i { color: #f87171; }

        /* ADJUSTMENT: clickable popup (administrator.php's .cv-top-toast[data-cv-go] pattern) — a small "View ›"
           marks it; clicking it (or Enter / Space) opens what it is about: the Inbox (letter) or the company row. */
        .cv-top-toast[data-cv-go] { pointer-events: auto; cursor: pointer; transition: opacity 0.35s, top 0.3s ease, background-color 0.15s ease; }
        .cv-top-toast[data-cv-go]:hover { background: #24375E; }
        .cv-top-toast[data-cv-go]:focus-visible { outline: 2px solid #F7C600; outline-offset: 2px; }
        .cv-top-toast .cv-toast-go { flex-shrink: 0; margin-left: 6px; color: #F7C600; font-size: 10.5px; font-weight: 700; text-transform: uppercase; letter-spacing: 0.4px; white-space: nowrap; }
        .cv-top-toast .cv-toast-go i { color: inherit; font-size: 9px; margin-left: 3px; }
        .cv-go-highlight { outline: 2px solid #F7C600 !important; outline-offset: 2px; animation: cvGoFlash 2.6s ease; }
        @keyframes cvGoFlash { 0%, 55% { box-shadow: 0 0 0 5px rgba(247, 198, 0, 0.35); } 100% { box-shadow: 0 0 0 0 rgba(247, 198, 0, 0); } }

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
           ATTENDANCE NOTIFICATION BAR — v3
           Exactly matches student_attendance.php version.
           Hidden:  translateY(-120%) + visibility:hidden + opacity:0
           Visible: translateY(0) + visibility:visible + opacity:1
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

        /* ── ANB inner pieces ── */
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

        /* Content area — flex row, wraps on very narrow bars */
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
        /* Countdown pill — fixed width so it never causes layout shift */
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

        /* Progress bar — absolutely positioned at bottom of bar */
        .anb-progress {
            position: absolute; bottom: 0; left: 0;
            height: 2px; background: #F7C600; border-radius: 0;
            pointer-events: none;
        }

        /* ══ MAIN CONTENT ══ */
        .main-content {
            margin-left: 260px;
            width: calc(100% - 260px);
            transition: margin-left 0.3s, width 0.3s;
            display: flex;
            flex-direction: column;
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
            flex-shrink: 0;
            box-shadow: none;
            position: relative;
            z-index: 99;
        }
        .navbar img { height: 40px; margin-right: 14px; }

        /* ══ PAGE CONTENT ══ */
        .page-inner {
            flex: 1;
            padding: 30px;
            max-width: 900px;
            width: 100%;
            margin: 0 auto;
        }

        .card {
            background: var(--surface);
            padding: 24px 28px;
            border-radius: 0;
            margin-bottom: 20px;
            box-shadow: var(--shadow-card);
            border: 1px solid var(--border);
        }
        .card h2 {
            margin-top: 0;
            color: var(--maroon);
            font-size: 18px;
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            border-bottom: 2px solid var(--gold);
            padding-bottom: 10px;
            margin-bottom: 18px;
        }
        .card h3 {
            color: var(--maroon);
            font-size: 15px;
            margin: 18px 0 10px;
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            font-style: italic;
        }
        .card p { margin-bottom: 8px; font-size: 14px; line-height: 1.6; }
        .card p b { color: var(--ink-muted); }

        .photo {
            width: 120px; height: 120px;
            border-radius: 50%;
            object-fit: cover;
            border: 3px solid var(--border);
            display: block;
            margin: 12px 0;
        }

        .map iframe {
            width: 100%; height: 300px;
            border: 0; border-radius: 0;
            margin-top: 12px;
        }

        ul { padding-left: 20px; font-size: 14px; }
        ul li { margin-bottom: 4px; }

        input[type="text"], textarea {
            width: 100%;
            padding: 10px 12px;
            margin: 5px 0 12px;
            border-radius: 0;
            border: 1.5px solid var(--border);
            font-size: 14px;
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            background: var(--surface-soft);
            color: var(--ink);
            transition: border-color 0.2s, background 0.2s;
            box-sizing: border-box;
        }
        input[type="text"]:focus, textarea:focus {
            outline: none;
            border-color: var(--teal);
            background: var(--surface);
            box-shadow: 0 0 0 3px rgba(27,42,74,0.10);
        }
        textarea { resize: vertical; min-height: 72px; }

        button[type="submit"] {
            background: var(--grid-navy);
            color: white;
            padding: 11px 24px;
            border: none;
            border-radius: 0;
            cursor: pointer;
            font-size: 14px;
            font-weight: 600;
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            transition: opacity 0.2s, transform 0.15s;
        }
        button[type="submit"]:hover { opacity: 0.88; transform: none; }

        /* ══ NOT-DEPLOYED MODAL ══ */
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
            from { opacity: 0; transform: scale(0.88) translateY(18px); }
            to   { opacity: 1; transform: scale(1)    translateY(0); }
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

        /* ══════════════════════════════════════════════
           SECTION TAB SWITCHER (static bar)
           Student Profile (incl. Company Details) / Digital Resume
           are split into their own switchable panes instead of one
           long stacked scroll.

           UPDATED: this now matches the flat, in-flow "page switcher"
           bar style used on AccomForm.php's Documentary Requirements
           page — a plain bar with a bottom border and text-style
           buttons that sits normally in the document flow — instead
           of the previous pill-shaped bar that used position: sticky
           to float/pin itself to the top of the viewport as the user
           scrolled. It now simply scrolls with the rest of the page
           content, just like every other card on this page.
        ══════════════════════════════════════════════ */
        .page-switcher {
            display: flex;
            gap: 12px;
            max-width: 900px;
            margin: 0 auto 24px;
            border-bottom: 2px solid var(--border);
            padding-bottom: 12px;
            flex-wrap: wrap;
            align-items: center;
        }
        .switch-page-btn {
            background: none;
            border: none;
            padding: 10px 22px;
            font-size: 13.5px;
            font-weight: 600;
            color: var(--ink-muted);
            cursor: pointer;
            border-radius: 0;
            transition: background 0.2s, color 0.2s, transform 0.15s;
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            white-space: nowrap;
            display: inline-flex;
            align-items: center;
            gap: 8px;
        }
        .switch-page-btn i { font-size: 14px; }
        .switch-page-btn:hover:not(.active) { background: var(--surface-soft); color: var(--ink); }
        .switch-page-btn.active {
            background: var(--maroon);
            color: #fff;
            box-shadow: none;
        }
        .switch-page-btn.active i { color: var(--gold); }
        .switch-page-btn:active { transform: none; }
        .switch-page-btn .tab-dot {
            width: 7px; height: 7px;
            border-radius: 50%;
            background: #A0850A;
            margin-left: 2px;
            flex-shrink: 0;
        }

        .tab-pane { display: none; }
        .tab-pane.active {
            display: block;
            animation: tabFadeIn 0.25s ease;
        }
        @keyframes tabFadeIn {
            from { opacity: 0; transform: translateY(6px); }
            to   { opacity: 1; transform: translateY(0); }
        }

        /* Simple placeholder shown inside a tab when there's nothing to display yet */
        .tab-empty {
            text-align: center;
            padding: 50px 20px;
            color: var(--ink-faint);
        }
        .tab-empty i { font-size: 2.4rem; margin-bottom: 14px; display: block; opacity: 0.45; }
        .tab-empty h3 { font-size: 15px; color: var(--ink-muted); margin-bottom: 6px; font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif; }
        .tab-empty p { font-size: 13px; color: var(--ink-faint); }

        /* ══════════════════════════════════════════════
           FINAL GRADE — horizontal competency stat row
           Replaces the old circular badge + separate grid with
           three evenly-sized boxes (Overall / General / Specific)
           laid out side by side.
        ══════════════════════════════════════════════ */
        .fg-stats-row {
            display: flex;
            gap: 14px;
            flex-wrap: wrap;
            margin: 16px 0 6px;
        }
        .fg-stat-box {
            flex: 1 1 150px;
            border-radius: 0;
            border: 1.5px solid;
            padding: 14px 16px;
            text-align: center;
        }
        .fg-stat-label {
            font-size: 0.72rem;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.04em;
            margin-bottom: 6px;
        }
        .fg-stat-value {
            font-size: 1.6rem;
            font-weight: 900;
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            line-height: 1;
        }
        .fg-stat-max {
            font-size: 0.85rem;
            font-weight: 600;
            opacity: 0.65;
            margin-left: 2px;
        }
        .fg-stat-caption {
            font-size: 0.72rem;
            margin-top: 5px;
            font-weight: 600;
        }

        /* ══════════════════════════════════════════════
           DIGITAL RESUME — header row (legacy, kept for
           backward compatibility — no longer used now that the
           Digital Resume tab renders an A4-paginated letterhead
           document preview below, but left in place so nothing else
           that may reference these classes elsewhere breaks).
        ══════════════════════════════════════════════ */
        .resume-header-row {
            display: flex;
            align-items: center;
            gap: 24px;
            flex-wrap: wrap;
            margin-bottom: 20px;
            padding-bottom: 20px;
            border-bottom: 1px solid var(--border-light);
        }
        .resume-header-row .photo { margin: 0; flex-shrink: 0; }
        .photo-placeholder {
            display: flex;
            align-items: center;
            justify-content: center;
            background: var(--surface-soft);
            color: var(--ink-faint);
            font-size: 2.1rem;
        }
        .resume-header-info {
            display: flex;
            flex-direction: column;
            gap: 6px;
            min-width: 200px;
        }
        .resume-header-info p { margin: 0; }

        /* ══════════════════════════════════════════════
           DIGITAL RESUME — A4 PAGINATED DOCUMENT PREVIEW
           (unchanged — see original comments below)
        ══════════════════════════════════════════════ */
        .dr-header-clone,
        .dr-footer-clone {
            display: none !important;
            position: fixed !important;
            top: 0 !important; left: -9999px !important;
            width: 0 !important; height: 0 !important;
            overflow: hidden !important;
            pointer-events: none !important;
        }

        .dr-pagination-nav {
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 14px;
            margin: 0 0 14px;
        }
        .dr-nav-arrow {
            width: 34px; height: 34px;
            border-radius: 50%;
            border: 1.5px solid var(--maroon);
            background: #fff;
            color: var(--maroon);
            font-size: 13px;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            cursor: pointer;
            transition: background 0.15s, color 0.15s, transform 0.15s;
        }
        .dr-nav-arrow:hover:not(:disabled) { background: var(--maroon); color: #fff; transform: none; }
        .dr-nav-arrow:active:not(:disabled) { transform: translateY(0); }
        .dr-nav-arrow:disabled { opacity: 0.35; cursor: not-allowed; }
        .dr-page-indicator {
            font-size: 12.5px;
            font-weight: 700;
            color: var(--ink-muted);
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            min-width: 92px;
            text-align: center;
        }

        .dr-pages-wrap {
            display: flex;
            justify-content: center;
            margin: 0 0 10px;
            padding: 20px 14px;
            background: #d8dde8;
            border-radius: 0;
            position: relative;
        }
        .dr-paper {
            background: #fff;
            width: 794px;
            max-width: 100%;
            height: 1123px;
            border: 1px solid #C3CADA;
            box-shadow: none;
            font-family: "Times New Roman","Crimson Pro",Times,serif;
            color: #1a1a1a;
            display: flex;
            flex-direction: column;
            overflow: hidden;
        }
        .dr-paper.dr-page-inactive {
            visibility: hidden;
            position: absolute;
            top: 20px; left: 50%;
            transform: translateX(-50%);
        }
        .dr-paper.dr-page-active {
            visibility: visible;
            position: relative;
        }

        .dr-letterhead {
            background: var(--maroon);
            padding: 12px 24px;
            display: flex;
            align-items: center;
            gap: 14px;
            border-bottom: 3px solid var(--gold);
            flex-shrink: 0;
        }
        .dr-lh-seal {
            width: 54px; height: 54px;
            border-radius: 50%;
            display: flex; align-items: center; justify-content: center;
            flex-shrink: 0; overflow: hidden;
            border: 2px solid rgba(255,255,255,.25);
        }
        .dr-lh-seal img { width: 100%; height: 100%; object-fit: cover; border-radius: 50%; display: block; }
        .dr-lh-text { color: #fff; flex: 1; min-width: 0; }
        .dr-lh-line1 { font-size: 8.5px; letter-spacing: .18em; text-transform: uppercase; color: #d9c98a; margin-bottom: 2px; font-family: 'Courier New', monospace; }
        .dr-lh-line2 { font-size: 15px; font-weight: 700; line-height: 1.25; text-transform: uppercase; letter-spacing: .01em; }
        .dr-lh-line3 { font-size: 9.5px; color: #f1e8cf; margin-top: 2px; }
        .dr-lh-line4 { font-size: 9px; color: #d9c98a; margin-top: 1px; }

        .dr-title-band { background: #f4f5fb; border-bottom: 1.5px solid var(--dr-rule); padding: 8px 24px 7px; text-align: center; flex-shrink: 0; }
        .dr-title-band h1 { font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif; font-size: 16px; font-weight: 700; color: var(--maroon); letter-spacing: .035em; text-transform: uppercase; }
        .dr-form-meta { margin-top: 3px; font-family: 'Courier New', monospace; font-size: 7.5px; color: #999; }

        .dr-form-body { padding: 18px 28px 20px; flex: 1; min-height: 0; overflow: hidden; }

        .dr-applicant-strip {
            display: flex;
            align-items: center;
            gap: 18px;
            margin-bottom: 6px;
            padding-bottom: 16px;
            border-bottom: 1px solid #DCE1EC;
        }
        .dr-applicant-strip .photo { margin: 0; flex-shrink: 0; }
        .dr-avatar {
            width: 70px; height: 70px;
            border-radius: 50%;
            background: var(--surface-soft);
            display: flex; align-items: center; justify-content: center;
            overflow: hidden;
            flex-shrink: 0;
            border: 2.5px solid var(--gold);
            color: var(--ink-faint);
            font-size: 1.6rem;
        }
        .dr-avatar img { width: 100%; height: 100%; object-fit: cover; display: block; }
        .dr-resume-mirror-info p {
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            font-size: 12.5px; color: #5A6272;
            margin: 0 0 3px; line-height: 1.5;
        }
        .dr-resume-mirror-info p b { color: #2d3748; font-weight: 700; margin-right: 4px; }

        .dr-section-title {
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            font-size: 13px; font-weight: 700; color: #1a1a1a;
            margin: 16px 0 8px;
            border-bottom: 1px solid #DCE1EC;
            padding-bottom: 4px;
        }
        .dr-section-title:first-of-type { margin-top: 0; }

        .dr-footer-band {
            background: #f0f2f8;
            border-top: 1.5px solid var(--maroon);
            padding: 5px 24px;
            display: flex; justify-content: space-between;
            font-family: 'Courier New', monospace; font-size: 7.5px; color: #888;
            letter-spacing: .07em;
            flex-shrink: 0;
        }

        .dr-editor-toolbar {
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 12px;
            flex-wrap: wrap;
            max-width: 794px;
            margin: 0 auto 16px;
        }
        .add-field-btn-labeled {
            background: var(--teal);
            color: #fff;
            border: 1.5px solid var(--teal);
            border-radius: 0;
            padding: 9px 18px;
            font-size: 13px;
            font-weight: 600;
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            cursor: pointer;
            display: inline-flex;
            align-items: center;
            gap: 8px;
            transition: opacity 0.15s, transform 0.15s;
        }
        .add-field-btn-labeled:hover { opacity: 0.88; transform: none; }
        .add-field-btn-labeled:active { transform: translateY(0); }
        .add-field-btn-labeled.exp-btn { background: var(--blue); border-color: var(--blue); }

        .dr-submit-bar {
            background: #f4f5fb;
            border: 1.5px solid var(--dr-rule);
            border-top: 1.5px solid var(--maroon);
            border-radius: 0;
            padding: 14px 24px;
            display: flex; align-items: center; justify-content: space-between;
            gap: 10px; flex-wrap: wrap;
            max-width: 794px;
            margin: 0 auto;
        }
        .dr-submit-bar-note { font-family: 'Courier New', monospace; font-size: 7.5px; color: #8A93A6; }
        .dr-save-btn {
            background: var(--grid-navy);
            color: #fff; border: none;
            padding: 10px 22px; border-radius: 0;
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif; font-size: 13px; font-weight: 700;
            cursor: pointer; display: flex; align-items: center; gap: 7px;
            transition: opacity 0.2s, transform 0.15s;
        }
        .dr-save-btn:hover { opacity: 0.88; transform: none; }

        @media (max-width: 840px) {
            .dr-paper { width: 100%; }
        }

        @media (max-width: 640px) {
            .dr-applicant-strip { flex-direction: column; align-items: flex-start; text-align: left; }
            .dr-submit-bar { justify-content: center; text-align: center; }
        }

        .dr-entries-col { display: flex; flex-direction: column; gap: 10px; }
        .dr-entry-item { margin-bottom: 0; }

        .field-row { margin-bottom: 10px; }
        .dr-entries-col .field-row { margin-bottom: 0; }
        .autogrow-textarea {
            width: 100%;
            overflow: hidden;
            resize: none;
            min-height: 44px;
        }

        .dynamic-field-row { margin-bottom: 0; }

        .dr-entry-header {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 10px;
            margin-bottom: 4px;
        }

        .field-wrap {
            position: relative;
        }
        .field-wrap .autogrow-textarea {
            margin-bottom: 0;
        }

        .dr-field-label {
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            font-size: 10.5px;
            font-weight: 700;
            color: #5A6272;
            text-transform: uppercase;
            letter-spacing: 0.04em;
        }

        .field-actions {
            display: flex;
            align-items: center;
            gap: 6px;
            flex-shrink: 0;
        }
        .field-edit-btn,
        .field-remove-btn {
            flex-shrink: 0;
            width: 26px;
            height: 26px;
            border-radius: 0;
            font-size: 11px;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            cursor: pointer;
            transition: background 0.15s, transform 0.15s;
        }
        .field-edit-btn {
            border: 1.5px solid var(--blue);
            background: var(--blue-light);
            color: var(--blue);
        }
        .field-edit-btn:hover { background: #E7ECF7; transform: none; }
        .field-edit-btn:active { transform: translateY(0); }

        .field-remove-btn {
            border: 1.5px solid var(--red);
            background: var(--red-light);
            color: var(--red);
        }
        .field-remove-btn:hover { background: #E3BCBC; transform: none; }
        .field-remove-btn:active { transform: translateY(0); }
        .field-remove-btn:disabled {
            opacity: 0.35;
            cursor: not-allowed;
            transform: none !important;
        }
        .field-remove-btn:disabled:hover { background: var(--red-light); }

        .autogrow-textarea.field-locked {
            background: var(--surface-warm);
            color: var(--ink-muted);
            cursor: default;
        }

        .dr-paper .autogrow-textarea {
            font-family: "Times New Roman","Crimson Pro",Times,serif;
            font-size: 12.5px;
            line-height: 1.6;
            color: #2d3748;
            background: #f4f5fb;
            border: 1.5px solid var(--dr-rule);
            border-radius: 0;
            padding: 10px 14px;
            margin: 0;
        }
        .dr-paper .autogrow-textarea:focus {
            outline: none;
            border-color: var(--teal);
            background: #fff;
            box-shadow: 0 0 0 3px rgba(27,42,74,0.10);
        }
        .dr-paper .autogrow-textarea.field-locked {
            background: #f4f5fb;
            color: #2d3748;
            cursor: default;
        }

        @media (max-width: 640px) {
            .page-switcher {
                margin: 0 0 20px;
                border-bottom: 2px solid var(--border);
            }
            .switch-page-btn { flex: 1; min-width: 45%; justify-content: center; }
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
           Square corners, thin slate borders, navy actions, small
           uppercase labels. Only the look changes; every class, id and
           tab/pagination hook used by the scripts is kept as it was. */
        body { background: var(--grid-bg); color: #2d3748; }
        .card { border: 1px solid var(--grid-border); padding: 28px 32px; }
        .card h2 {
            color: var(--grid-navy); font-size: 18px;
            border-bottom: 1px solid var(--grid-border);
            text-transform: uppercase; letter-spacing: 0.6px;
        }
        .card h3 {
            color: var(--grid-navy); font-size: 13px; font-style: normal;
            text-transform: uppercase; letter-spacing: 0.4px;
        }
        .card p b { color: var(--grid-navy); }
        .photo { border: 1px solid var(--grid-border); }
        input[type="text"], textarea { border: 1px solid var(--grid-border); background: #fff; }
        input[type="text"]:focus, textarea:focus,
        .dr-paper .autogrow-textarea:focus {
            border-color: var(--grid-navy); box-shadow: 0 0 0 3px rgba(27,42,74,0.08);
        }
        button[type="submit"], .dr-save-btn, .add-field-btn-labeled {
            background: var(--grid-navy); border: 1px solid var(--grid-navy); color: #fff;
            font-size: 12px; text-transform: uppercase; letter-spacing: 0.4px;
        }
        .add-field-btn-labeled.exp-btn { background: #fff; color: var(--grid-navy); border-color: var(--grid-border); }
        .add-field-btn-labeled.exp-btn:hover { background: #f3f4f7; }
        button[type="submit"]:focus-visible, .dr-save-btn:focus-visible,
        .add-field-btn-labeled:focus-visible, .switch-page-btn:focus-visible,
        .dr-nav-arrow:focus-visible { outline: 2px solid var(--grid-navy); outline-offset: 2px; }
        .field-edit-btn  { border: 1px solid var(--grid-border); background: #fff; color: var(--grid-navy); }
        .field-edit-btn:hover { background: var(--grid-navy); color: #fff; }
        .field-remove-btn { border: 1px solid #E3BCBC; }
        .field-remove-btn:hover { background: var(--grid-red); color: #fff; }
        .field-remove-btn:disabled:hover { color: var(--red); }

        /* Section switcher — same square tabs as AccomForm.php */
        .page-switcher { gap: 8px; border-bottom: 1px solid var(--grid-border); padding-bottom: 14px; }
        .switch-page-btn {
            background: #fff; border: 1px solid var(--grid-border);
            padding: 10px 18px; font-size: 12px; color: var(--grid-navy);
            text-transform: uppercase; letter-spacing: 0.4px;
        }
        .switch-page-btn:hover:not(.active) { background: #f3f4f7; color: var(--grid-navy); }
        .switch-page-btn.active { background: var(--grid-navy); border-color: var(--grid-navy); color: #fff; box-shadow: none; }
        .switch-page-btn.active i { color: #F7C600; }
        .switch-page-btn:active { transform: none; }
        .switch-page-btn .tab-dot { background: var(--grid-amber); }

        .tab-empty h3 { color: var(--grid-navy); text-transform: uppercase; letter-spacing: 0.4px; font-size: 13px; }
        .fg-stat-box { border-width: 1px; }
        .fg-stat-label { letter-spacing: 0.5px; }
        .dr-nav-arrow { border: 1px solid var(--grid-navy); border-radius: 0; }
        .dr-nav-arrow:hover:not(:disabled) { transform: none; }
        .dr-page-indicator { color: var(--grid-navy); font-variant-numeric: tabular-nums; }
        .dr-submit-bar { border: 1px solid var(--grid-border); background: #fff; }
        .ndm-icon i { color: var(--grid-amber); }
        @media (prefers-reduced-motion: reduce) { .tab-pane.active { animation: none; } }
    </style>
</head>
<body>

<!-- ══ SIDEBAR ══ -->
<div id="sidebar" class="sidebar">
    <div class="sidebar-header">
        <!-- FIX #1: full_name already includes middle name (built in PHP above) -->
        <div class="sidebar-user-info">
            <span class="sidebar-user-name"><?php echo htmlspecialchars($full_name); ?></span>
            <span class="sidebar-user-role">OJT Trainee</span>
        </div>
        <button id="toggleBtn" class="toggle-btn"><i class="fas fa-bars"></i></button>
    </div>

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
             $all_verified — matching the same fix already applied to
             company_list.php / AccomForm.php. Previously this link was
             wrapped in `if ($all_verified)`, which hid this very page
             from its own sidebar until every one of the 8 requirement
             types was Verified by the administrator. The Attendance /
             Reports / Dashboard links directly below remain governed by
             the exact same $all_verified (and, further, $is_deployed)
             gates as before — nothing about those was touched. -->
        <a href="student_profile.php" class="active">
            <i class="fas fa-user-circle"></i>
            <span class="link-text">My Profile</span>
        </a>
        <a href="company_list.php">
            <i class="fas fa-building"></i>
            <span class="link-text">Company List</span>
            <!-- ADJUSTMENT: endorsement-letter indicator (same count as company_list.php's Inbox bell), kept live by the script before </body> -->
            <span class="sidebar-badge-endo<?= $endo_attention_count > 0 ? ' is-on' : '' ?>" id="endoSidebarBadge" role="status" aria-live="polite"
                  title="<?= $endo_attention_count > 0 ? 'You have endorsement letter(s) in your Inbox' : '' ?>"
                  aria-label="<?= $endo_attention_count > 0 ? (int)$endo_attention_count . ' endorsement letter notification(s)' : '' ?>"><?= $endo_attention_count > 0 ? (int)$endo_attention_count : '' ?></span>
        </a>
        <a href="AccomForm.php">
            <i class="fas fa-file-contract"></i>
            <span class="link-text">Requirements</span>
        </a>

        <?php if ($all_verified): ?>
            <?php if ($is_deployed): ?>
            <!-- DEPLOYED: normal links -->
            <a href="student_attendance.php" data-nav-key="attendance">
                <i class="fas fa-calendar-check"></i>
                <span class="link-text">Attendance</span>
                <?php if ($att_sidebar_badge): ?>
                    <span class="sidebar-badge-att">!</span>
                <?php endif; ?>
            </a>
            <a href="student_report.php" data-nav-key="report">
                <i class="fas fa-chart-bar"></i>
                <span class="link-text">Reports</span>
                <span class="sidebar-badge-journal" id="journalEmptyBadge" style="display:none;"></span>
            </a>
            <a href="student_dashboard.php" data-nav-key="dashboard">
                <i class="fas fa-tachometer-alt"></i>
                <span class="link-text">Dashboard</span>
            </a>
            <?php else: ?>
            <!-- NOT DEPLOYED: locked links -->
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

<!-- ══ NOT-DEPLOYED MODAL ══ -->
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

<!-- ══════════════════════════════════════════════════════════════
     ATTENDANCE NOTIFICATION BAR — v3
     FIX #2: PM late-window support added (matches student_attendance.php)
     FIX #3: Timer/countdown no longer overlaps — flexbox layout fixed
══════════════════════════════════════════════════════════════ -->
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
    <nav class="navbar">
        <img src="logo.webp" alt="NEUST Logo">
        <div>
            <div style="font-weight:bold;font-size:16px;">NEUST Atate Campus</div>
            <div style="font-size:11px;color:var(--neust-gold);">Web-Based Smart OJT Monitoring and Supervision Analytics System</div>
        </div>
    </nav>

    <div class="page-inner">

        <div class="page-switcher" role="tablist" aria-label="Profile sections">
            <button type="button" class="switch-page-btn active" data-tab="profile" onclick="switchProfileTab('profile')" role="tab" aria-selected="true">
                <i class="fas fa-id-card"></i> Profile &amp; Company
                <?php if (!$company): ?><span class="tab-dot" title="Not yet deployed"></span><?php endif; ?>
            </button>
            <button type="button" class="switch-page-btn" data-tab="resume" onclick="switchProfileTab('resume')" role="tab" aria-selected="false">
                <i class="fas fa-file-alt"></i> Digital Resume
            </button>
        </div>

        <!-- ══ TAB: STUDENT PROFILE + COMPANY DETAILS ══ -->
        <div class="tab-pane active" id="tab-profile" role="tabpanel">

        <!-- BASIC INFO -->
        <div class="card">
            <h2>Student Profile</h2>
            <p><b>Full Name:</b> <?= htmlspecialchars($full_name) ?></p>
            <p><b>Course:</b> <?= htmlspecialchars($student['course']) ?></p>
            <p><b>Status:</b> <?= htmlspecialchars($student['deploy_status']) ?></p>
        </div>

        <!-- FINAL GRADE (only shown when admin publishes it) -->
        <?php if ($final_grade && $final_grade['overall_competency_rating'] !== null): ?>
        <?php
            $fg_val = (float)$final_grade['overall_competency_rating'];

            if ($fg_val <= 1.25)      $fg_label = 'Excellent';
            elseif ($fg_val <= 2.0)   $fg_label = 'Very Satisfactory';
            elseif ($fg_val <= 2.75)  $fg_label = 'Satisfactory';
            elseif ($fg_val <= 3.0)   $fg_label = 'Passed';
            else                      $fg_label = 'Failed';

            if ($fg_val <= 2.0)      $fg_color = '#2C5A2C'; // Excellent / Very Satisfactory
            elseif ($fg_val <= 2.75) $fg_color = '#1B2A4A'; // Satisfactory
            elseif ($fg_val <= 3.0)  $fg_color = '#A0850A'; // Passed
            else                      $fg_color = '#A02A2A'; // Failed
        ?>
        <div class="card" id="final-grade-card" style="
            border: 1px solid <?= $fg_color ?>;
            background: #ffffff;
            position: relative; overflow: hidden;">

            <h2 style="color:<?= $fg_color ?>; border-bottom-color:<?= $fg_color ?>;">
                <i class="fas fa-graduation-cap" style="margin-right:8px;"></i>Final OJT Grade
            </h2>

            <div class="fg-stats-row">
                <div class="fg-stat-box" style="background:<?= $fg_color ?>14; border-color:<?= $fg_color ?>55;">
                    <div class="fg-stat-label" style="color:<?= $fg_color ?>;"><i class="fas fa-award"></i> Overall Competency</div>
                    <div class="fg-stat-value" style="color:<?= $fg_color ?>;">
                        <?= number_format($fg_val, 2) ?><span class="fg-stat-max">/ 5.00</span>
                    </div>
                    <div class="fg-stat-caption" style="color:<?= $fg_color ?>;"><?= htmlspecialchars($fg_label) ?></div>
                </div>

                <?php if ($final_grade['general_competency_rating'] !== null): ?>
                <div class="fg-stat-box" style="background:#EFEBF7; border-color:#D5CCE8;">
                    <div class="fg-stat-label" style="color:#5B4A8A;"><i class="fas fa-tasks"></i> General Competency</div>
                    <div class="fg-stat-value" style="color:#5B4A8A;">
                        <?= number_format($final_grade['general_competency_rating'], 2) ?>
                    </div>
                    <div class="fg-stat-caption" style="color:#5B4A8A;">Weight: 40%</div>
                </div>
                <?php endif; ?>

                <?php if ($final_grade['specific_competency_rating'] !== null): ?>
                <div class="fg-stat-box" style="background:#E7ECF7; border-color:#C3CADA;">
                    <div class="fg-stat-label" style="color:#1B2A4A;"><i class="fas fa-star"></i> Specific Competency</div>
                    <div class="fg-stat-value" style="color:#1B2A4A;">
                        <?= number_format($final_grade['specific_competency_rating'], 2) ?>
                    </div>
                    <div class="fg-stat-caption" style="color:#1B2A4A;">Weight: 60%</div>
                </div>
                <?php endif; ?>
            </div>

            <div style="font-size:0.75rem; color:#8A93A6; display:flex; align-items:center; gap:6px;">
                <i class="fas fa-check-circle" style="color:<?= $fg_color ?>;"></i>
                Released on <?= date('F d, Y', strtotime($final_grade['published_at'])) ?>
                <?php if (!empty($final_grade['company_name'])): ?>
                 &nbsp;·&nbsp; <?= htmlspecialchars($final_grade['company_name']) ?>
                <?php endif; ?>
            </div>
        </div>
        <?php endif; ?>

        <!-- COMPANY DETAILS (merged into the same section as requested) -->
        <?php if ($company): ?>
        <div class="card">
            <h2>Company Details</h2>
            <p><b>Company Name:</b> <?= htmlspecialchars(!empty($company['company_name']) ? $company['company_name'] : trim($company['first_name']." ".$company['last_name'])) ?></p>
            <p><b>Supervisor:</b> <?= htmlspecialchars($company['supervisor_name']) ?></p>
            <p><b>Contact Number:</b> <?= htmlspecialchars($company['telephone']) ?></p>
            <?php if (!empty($company['google_map_link'])): ?>
            <div class="map">
                <iframe src="<?= htmlspecialchars($company['google_map_link']) ?>" allowfullscreen loading="lazy"></iframe>
            </div>
            <?php endif; ?>
        </div>
        <?php else: ?>
        <div class="card">
            <h2>Company Details</h2>
            <div class="tab-empty">
                <i class="fas fa-building"></i>
                <h3>No Company Assigned Yet</h3>
                <p>Once you're deployed to a partner company, its details will appear here.</p>
            </div>
        </div>
        <?php endif; ?>

        </div><!-- end #tab-profile -->

        <!-- ══ TAB: DIGITAL RESUME ══ -->
        <div class="tab-pane" id="tab-resume" role="tabpanel">

        <div class="dr-header-clone" id="drHeaderClone">
            <div class="dr-letterhead">
                <div class="dr-lh-seal"><img src="logo.webp" alt="NEUST Seal"></div>
                <div class="dr-lh-text">
                    <div class="dr-lh-line1">Republic of the Philippines</div>
                    <div class="dr-lh-line2">Nueva Ecija University of Science and Technology</div>
                    <div class="dr-lh-line3">On&ndash;the&ndash;Job Training and Career Development Center</div>
                    <div class="dr-lh-line4">Atate Campus</div>
                </div>
            </div>
            <div class="dr-title-band">
                <h1>Student Digital Resume</h1>
                <div class="dr-form-meta">OJT Trainee Skills &amp; Experience Record</div>
            </div>
        </div>
        <div class="dr-footer-clone" id="drFooterClone">
            <div class="dr-footer-band">
                <span>NEUST&ndash;OJT&ndash;RESUME</span>
                <span>Digital Resume</span>
            </div>
        </div>

        <div class="dr-pagination-nav" id="drPagNav" style="display:none;">
            <button type="button" class="dr-nav-arrow" id="drPrevPageBtn" aria-label="Previous page"><i class="fas fa-chevron-left"></i></button>
            <span class="dr-page-indicator" id="drPageIndicator">Page 1 of 1</span>
            <button type="button" class="dr-nav-arrow" id="drNextPageBtn" aria-label="Next page"><i class="fas fa-chevron-right"></i></button>
        </div>

        <form method="POST" id="resumeForm">
            <div class="dr-pages-wrap" id="drPagesWrap">
                <div class="dr-paper dr-page-active" data-page-index="0">
                    <div class="dr-letterhead">
                        <div class="dr-lh-seal"><img src="logo.webp" alt="NEUST Seal"></div>
                        <div class="dr-lh-text">
                            <div class="dr-lh-line1">Republic of the Philippines</div>
                            <div class="dr-lh-line2">Nueva Ecija University of Science and Technology</div>
                            <div class="dr-lh-line3">On&ndash;the&ndash;Job Training and Career Development Center</div>
                            <div class="dr-lh-line4">Atate Campus</div>
                        </div>
                    </div>
                    <div class="dr-title-band">
                        <h1>Student Digital Resume</h1>
                        <div class="dr-form-meta">Page 1 of 1</div>
                    </div>
                    <div class="dr-form-body">

                        <div class="dr-applicant-strip" id="drApplicantStrip">
                            <div class="dr-avatar">
                                <?php if (!empty($student['student_photo'])): ?>
                                    <img src="data:image/jpeg;base64,<?= base64_encode($student['student_photo']) ?>" alt="Student Photo">
                                <?php else: ?>
                                    <i class="fas fa-user"></i>
                                <?php endif; ?>
                            </div>
                            <div style="flex:1; min-width:0;">
                                <div class="dr-resume-mirror-info">
                                    <p><b>Full Name:</b> <?= htmlspecialchars($full_name) ?></p>
                                    <p><b>Email:</b> <?= htmlspecialchars($student['email']) ?></p>
                                    <p><b>Course:</b> <?= htmlspecialchars($student['course']) ?></p>
                                </div>
                            </div>
                        </div>

                        <div class="dr-section-title" id="drSkillsTitle">Skills</div>
                        <div id="skillEntriesContainer" class="dr-entries-col">
                            <?php if (empty($skill_entries)): ?>
                                <div class="field-row dynamic-field-row dr-entry-item" data-group="skill">
                                    <div class="dr-entry-header">
                                        <div class="dr-field-label">Skill 1</div>
                                        <div class="field-actions">
                                            <button type="button" class="field-remove-btn" onclick="removeDynamicField(this,'skill','Skill')" aria-label="Remove skill"><i class="fas fa-times"></i></button>
                                        </div>
                                    </div>
                                    <div class="field-wrap">
                                        <textarea class="autogrow-textarea" name="skill_entry[]" placeholder="<?= htmlspecialchars($SKILL_ENTRY_PLACEHOLDER) ?>"></textarea>
                                    </div>
                                </div>
                            <?php else: ?>
                                <?php foreach ($skill_entries as $skill_idx => $skill_val): ?>
                                <div class="field-row dynamic-field-row dr-entry-item" data-group="skill">
                                    <div class="dr-entry-header">
                                        <div class="dr-field-label">Skill <?= $skill_idx + 1 ?></div>
                                        <div class="field-actions">
                                            <button type="button" class="field-edit-btn" onclick="toggleEditField(this)" aria-label="Edit skill" title="Edit"><i class="fas fa-pen"></i></button>
                                            <button type="button" class="field-remove-btn" onclick="removeDynamicField(this,'skill','Skill')" aria-label="Remove skill"><i class="fas fa-times"></i></button>
                                        </div>
                                    </div>
                                    <div class="field-wrap">
                                        <textarea class="autogrow-textarea field-locked" name="skill_entry[]" placeholder="<?= htmlspecialchars($SKILL_ENTRY_PLACEHOLDER) ?>" readonly><?= htmlspecialchars($skill_val) ?></textarea>
                                    </div>
                                </div>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </div>

                        <div class="dr-section-title" id="drExpTitle">Experience</div>
                        <div id="expEntriesContainer" class="dr-entries-col">
                            <?php if (empty($exp_entries)): ?>
                                <div class="field-row dynamic-field-row dr-entry-item" data-group="exp">
                                    <div class="dr-entry-header">
                                        <div class="dr-field-label">Experience 1</div>
                                        <div class="field-actions">
                                            <button type="button" class="field-remove-btn" onclick="removeDynamicField(this,'exp','Experience')" aria-label="Remove experience"><i class="fas fa-times"></i></button>
                                        </div>
                                    </div>
                                    <div class="field-wrap">
                                        <textarea class="autogrow-textarea" name="exp_entry[]" placeholder="<?= htmlspecialchars($EXP_ENTRY_PLACEHOLDER) ?>"></textarea>
                                    </div>
                                </div>
                            <?php else: ?>
                                <?php foreach ($exp_entries as $exp_idx => $exp_val): ?>
                                <div class="field-row dynamic-field-row dr-entry-item" data-group="exp">
                                    <div class="dr-entry-header">
                                        <div class="dr-field-label">Experience <?= $exp_idx + 1 ?></div>
                                        <div class="field-actions">
                                            <button type="button" class="field-edit-btn" onclick="toggleEditField(this)" aria-label="Edit experience" title="Edit"><i class="fas fa-pen"></i></button>
                                            <button type="button" class="field-remove-btn" onclick="removeDynamicField(this,'exp','Experience')" aria-label="Remove experience"><i class="fas fa-times"></i></button>
                                        </div>
                                    </div>
                                    <div class="field-wrap">
                                        <textarea class="autogrow-textarea field-locked" name="exp_entry[]" placeholder="<?= htmlspecialchars($EXP_ENTRY_PLACEHOLDER) ?>" readonly><?= htmlspecialchars($exp_val) ?></textarea>
                                    </div>
                                </div>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </div>

                    </div><!-- end .dr-form-body -->
                    <div class="dr-footer-band">
                        <span>NEUST&ndash;OJT&ndash;RESUME</span>
                        <span>Digital Resume</span>
                    </div>
                </div><!-- end .dr-paper -->
            </div><!-- end #drPagesWrap -->
        </form>

        <div class="dr-editor-toolbar">
            <button type="button" class="add-field-btn-labeled" onclick="addDynamicField('skill','skill_entry[]','Skill')">
                <i class="fas fa-plus"></i> Add Skill
            </button>
            <button type="button" class="add-field-btn-labeled exp-btn" onclick="addDynamicField('exp','exp_entry[]','Experience')">
                <i class="fas fa-plus"></i> Add Experience
            </button>
        </div>

        <div class="dr-submit-bar">
            <div class="dr-submit-bar-note">Review your entries above, then save your changes.</div>
            <button type="submit" form="resumeForm" name="update_resume" class="dr-save-btn">
                <i class="fas fa-save"></i> Update Resume
            </button>
        </div>

        </div><!-- end #tab-resume -->

    </div><!-- end .page-inner -->
</div><!-- end .main-content -->

<script>
/* ── SIDEBAR TOGGLE ── */
const sidebar   = document.getElementById('sidebar');
const toggleBtn = document.getElementById('toggleBtn');
toggleBtn.addEventListener('click', () => {
    sidebar.classList.toggle('collapsed');
    const mc = document.getElementById('mainContent');
    if (sidebar.classList.contains('collapsed')) {
        mc.style.marginLeft = '80px';
        mc.style.width      = 'calc(100% - 80px)';
    } else {
        mc.style.marginLeft = '260px';
        mc.style.width      = 'calc(100% - 260px)';
    }
    document.getElementById('att-notif-bar')
            .classList.toggle('sidebar-collapsed', sidebar.classList.contains('collapsed'));
});

function switchProfileTab(tab) {
    document.querySelectorAll('.switch-page-btn').forEach(btn => {
        const isActive = btn.dataset.tab === tab;
        btn.classList.toggle('active', isActive);
        btn.setAttribute('aria-selected', isActive ? 'true' : 'false');
    });
    document.querySelectorAll('.tab-pane').forEach(pane => {
        pane.classList.toggle('active', pane.id === 'tab-' + tab);
    });
    try { sessionStorage.setItem('ojt_profile_active_tab', tab); } catch (e) {}

    requestAnimationFrame(() => {
        const activePane = document.getElementById('tab-' + tab);
        if (!activePane) return;
        activePane.querySelectorAll('.autogrow-textarea').forEach(el => {
            if (typeof autoGrowTextarea === 'function') autoGrowTextarea(el);
        });
        if (tab === 'resume' && typeof drRepaginateResume === 'function') {
            drRepaginateResume();
        }
    });
}

(function() {
    let savedTab = null;
    try { savedTab = sessionStorage.getItem('ojt_profile_active_tab'); } catch (e) {}
    if (savedTab && document.getElementById('tab-' + savedTab)) {
        switchProfileTab(savedTab);
    }
})();

/* ══ NOT-DEPLOYED MODAL ══ */
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
    if (e.key === 'Escape') closeNotDeployedModal();
});

/* ── JOURNAL BADGE (cross-page localStorage sync) ──
   ADJUSTMENT: converted from a self-contained IIFE into a top-level
   function (mirroring AccomForm.php / company_list.php) so it can be
   safely re-invoked by activateVerifiedSidebar() below, right after
   the Reports link — and its badge span — are (re)inserted into the
   sidebar. Nothing about the badge's own behavior (localStorage key,
   refresh interval, storage event) has changed. */
const JOURNAL_BADGE_LS_KEY = <?= json_encode('ojt_journal_empty_count_' . $user_id) ?>;
function refreshJournalBadge() {
    const badge = document.getElementById('journalEmptyBadge');
    if (!badge) return;
    let count = 0;
    try {
        const raw = localStorage.getItem(JOURNAL_BADGE_LS_KEY);
        count = raw !== null ? parseInt(raw, 10) || 0 : 0;
    } catch(e) {}
    if (count > 0) { badge.textContent = count; badge.style.display = 'inline-flex'; }
    else           { badge.style.display = 'none'; badge.textContent = ''; }
}
refreshJournalBadge();
setInterval(refreshJournalBadge, 10000);
window.addEventListener('storage', e => { if (e.key === JOURNAL_BADGE_LS_KEY) refreshJournalBadge(); });

/* ============================================================
   ADJUSTMENT: INSTANT SIDEBAR ACTIVATION / RE-LOCK ON LIVE
   VERIFICATION CHANGE — synced with company_list.php / AccomForm.php
   ------------------------------------------------------------
   Polls this page's own lightweight `student_profile.php?poll_status=1`
   endpoint (defined near the top of this file) and, the instant all 8
   requirement types come back "Verified", unlocks the sidebar in-place
   — no reload needed. If a previously-verified set later falls out of
   "Verified" (e.g. an admin reverts a requirement), the sidebar
   re-locks itself just as instantly — matching the exact same live
   behavior already used on company_list.php and AccomForm.php, so all
   three pages' sidebars now stay in sync with each other without a
   manual reload on any of them.

   ADJUSTMENT: "My Profile" is now ALWAYS rendered server-side (see the
   sidebar markup above) and is no longer tied to `$all_verified` at
   all, so activateVerifiedSidebar() / deactivateVerifiedSidebar()
   below no longer insert or remove it — they only manage the
   Attendance / Reports / Dashboard links and the lock notice, matching
   company_list.php / AccomForm.php's equivalent functions.
   ============================================================ */
const IS_DEPLOYED_FLAG          = <?= json_encode($is_deployed) ?>;
const INITIAL_ATT_SIDEBAR_BADGE = <?= json_encode((bool)$att_sidebar_badge) ?>;
let   sidebarVerifiedActivated  = <?= json_encode($all_verified) ?>;

function activateVerifiedSidebar() {
    if (sidebarVerifiedActivated) return;
    sidebarVerifiedActivated = true;

    const lockNotice = document.querySelector('.sidebar-lock-notice');
    if (lockNotice) { lockNotice.remove(); }

    const linksContainer = document.getElementById('sidebarLinksContainer');
    if (!linksContainer) return;

    if (!linksContainer.querySelector('[data-nav-key="attendance"]')) {
        const holder = document.createElement('div');

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

function deactivateVerifiedSidebar() {
    if (!sidebarVerifiedActivated) return;
    sidebarVerifiedActivated = false;

    const linksContainer = document.getElementById('sidebarLinksContainer');

    if (!document.querySelector('.sidebar-lock-notice') && linksContainer && linksContainer.parentNode) {
        const lockNotice = document.createElement('div');
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
            const link = linksContainer.querySelector('[data-nav-key="' + navKey + '"]');
            if (link) { link.remove(); }
        });
    }
}

(function() {
    function pollSidebarVerificationStatus() {
        fetch('student_profile.php?poll_status=1', { credentials: 'same-origin' })
            .then(function(r) { return r.ok ? r.json() : null; })
            .then(function(data) {
                if (!data) return;

                let allVerifiedNow = true;
                Object.keys(data).forEach(function(key) {
                    const status = (data[key] && data[key].status) || 'Pending';
                    if (status !== 'Verified') { allVerifiedNow = false; }
                });

                if (allVerifiedNow && !sidebarVerifiedActivated) {
                    activateVerifiedSidebar();
                } else if (!allVerifiedNow && sidebarVerifiedActivated) {
                    deactivateVerifiedSidebar();
                }
            })
            .catch(function() { /* silent — network blip, retry next interval */ });
    }

    setTimeout(function() {
        pollSidebarVerificationStatus();
        setInterval(pollSidebarVerificationStatus, 7000);
    }, 3000);
})();

/* ══════════════════════════════════════════════
   SKILLS / EXPERIENCE — auto-growing fields, edit/remove buttons,
   and "add another" buttons  (unchanged from original)
══════════════════════════════════════════════ */
function autoGrowTextarea(el) {
    el.style.height = 'auto';
    el.style.height = el.scrollHeight + 'px';
}
document.querySelectorAll('.autogrow-textarea').forEach(el => {
    autoGrowTextarea(el);
    el.addEventListener('input', () => autoGrowTextarea(el));
});

const ENTRY_MIN_COUNT = {
    skill: 1,
    exp: 0
};

const ENTRY_DEFAULT_PLACEHOLDER = {
    skill: <?= json_encode($SKILL_ENTRY_PLACEHOLDER) ?>,
    exp:   <?= json_encode($EXP_ENTRY_PLACEHOLDER) ?>
};

function drGroupRows(group) {
    return Array.prototype.slice.call(document.querySelectorAll('.dynamic-field-row[data-group="' + group + '"]'));
}

function renumberEntries(group, prefix) {
    const placeholderText = ENTRY_DEFAULT_PLACEHOLDER[group] || '';
    drGroupRows(group).forEach((row, idx) => {
        const ta = row.querySelector('textarea');
        if (ta) ta.placeholder = placeholderText;
        const label = row.querySelector('.dr-field-label');
        if (label && prefix) label.textContent = prefix + ' ' + (idx + 1);
    });
}

function updateRemoveButtonsState(group) {
    const rows = drGroupRows(group);
    const min = ENTRY_MIN_COUNT[group] ?? 0;
    const disable = rows.length <= min;
    rows.forEach(row => {
        const btn = row.querySelector('.field-remove-btn');
        if (btn) btn.disabled = disable;
    });
}

function toggleEditField(btn) {
    const row = btn.closest('.dynamic-field-row');
    if (!row) return;
    const textarea = row.querySelector('.field-wrap textarea');
    if (!textarea) return;

    textarea.readOnly = false;
    textarea.classList.remove('field-locked');
    textarea.focus();

    const value = textarea.value;
    textarea.value = '';
    textarea.value = value;

    autoGrowTextarea(textarea);
    btn.remove();
}

function addDynamicField(group, inputName, prefix) {
    const resumePane = document.getElementById('tab-resume');
    if (resumePane && !resumePane.classList.contains('active')) {
        switchProfileTab('resume');
    }

    const rows = drGroupRows(group);
    const anchor = document.getElementById(group === 'skill' ? 'skillEntriesContainer' : 'expEntriesContainer');

    const row = document.createElement('div');
    row.className = 'field-row dynamic-field-row dr-entry-item';
    row.dataset.group = group;

    const header = document.createElement('div');
    header.className = 'dr-entry-header';

    const label = document.createElement('div');
    label.className = 'dr-field-label';
    label.textContent = prefix + ' ' + (rows.length + 1);

    const actions = document.createElement('div');
    actions.className = 'field-actions';

    const removeBtn = document.createElement('button');
    removeBtn.type = 'button';
    removeBtn.className = 'field-remove-btn';
    removeBtn.setAttribute('aria-label', 'Remove field');
    removeBtn.innerHTML = '<i class="fas fa-times"></i>';
    removeBtn.addEventListener('click', function() { removeDynamicField(removeBtn, group, prefix); });

    actions.appendChild(removeBtn);
    header.appendChild(label);
    header.appendChild(actions);

    const wrap = document.createElement('div');
    wrap.className = 'field-wrap';

    const textarea = document.createElement('textarea');
    textarea.className = 'autogrow-textarea';
    textarea.name = inputName;
    textarea.placeholder = ENTRY_DEFAULT_PLACEHOLDER[group] || prefix;

    wrap.appendChild(textarea);
    row.appendChild(header);
    row.appendChild(wrap);

    if (rows.length > 0) {
        rows[rows.length - 1].insertAdjacentElement('afterend', row);
    } else if (anchor) {
        anchor.appendChild(row);
    }

    autoGrowTextarea(textarea);
    textarea.addEventListener('input', () => autoGrowTextarea(textarea));

    renumberEntries(group, prefix);
    updateRemoveButtonsState(group);
    drRepaginateResume(function() {
        textarea.focus();
        if (typeof textarea.scrollIntoView === 'function') {
            textarea.scrollIntoView({ behavior: 'smooth', block: 'center' });
        }
    });
}

function removeDynamicField(btn, group, prefix) {
    const row = btn.closest('.dynamic-field-row');
    if (!row) return;

    const rows = drGroupRows(group);
    const min = ENTRY_MIN_COUNT[group] ?? 0;
    if (rows.length <= min) return;

    row.remove();
    renumberEntries(group, prefix);
    updateRemoveButtonsState(group);
    drRepaginateResume();
}

updateRemoveButtonsState('skill');
updateRemoveButtonsState('exp');

(function() {
    const resumeForm = document.getElementById('resumeForm');
    if (!resumeForm) return;
    resumeForm.addEventListener('submit', function(e) {
        const hasSkill = Array.from(document.querySelectorAll('textarea[name="skill_entry[]"]'))
            .some(t => t.value.trim() !== '');
        if (!hasSkill) {
            e.preventDefault();
            alert('Please enter at least one skill.');
        }
    });
})();

function revealField(rowId, btnId) {
    const row = document.getElementById(rowId);
    const btn = document.getElementById(btnId);
    if (row) {
        row.style.display = '';
        const field = row.querySelector('textarea, input');
        if (field) {
            field.focus();
            autoGrowTextarea(field);
        }
    }
    if (btn) btn.style.display = 'none';
}

/* ══════════════════════════════════════════════════════════════
   DIGITAL RESUME — A4 PAGINATION ENGINE (unchanged from original)
   ══════════════════════════════════════════════════════════════ */
const DR_PAGE_W         = 794;
const DR_PAGE_H         = 1123;
const DR_BODY_PAD_TB    = 18 + 20;
const DR_CONTENT_WIDTH  = DR_PAGE_W - (28 * 2);
const DR_BLOCK_GAP      = 10;
const DR_SAFETY_BUFFER  = 10;
let   drCurrentPageIndex = 0;

function drMeasureSandboxHeight(elements, width) {
    const w = width || DR_CONTENT_WIDTH;
    const sandbox = document.createElement('div');
    sandbox.style.cssText = 'position:fixed;top:0;left:-9999px;visibility:hidden;width:' + w + 'px;overflow:hidden;';
    elements.forEach(el => { if (el) sandbox.appendChild(el.cloneNode(true)); });
    document.body.appendChild(sandbox);
    const h = sandbox.scrollHeight;
    document.body.removeChild(sandbox);
    return h;
}

function drBuildResumeBlocks() {
    const blocks = [];

    const applicant = document.getElementById('drApplicantStrip');
    if (applicant) blocks.push({ el: applicant, glueWith: null });

    const skillsTitle = document.getElementById('drSkillsTitle');
    const skillRows   = drGroupRows('skill');
    if (skillsTitle) {
        if (skillRows.length > 0) {
            blocks.push({ el: skillsTitle, glueWith: skillRows[0] });
            for (let i = 1; i < skillRows.length; i++) blocks.push({ el: skillRows[i], glueWith: null });
        } else {
            blocks.push({ el: skillsTitle, glueWith: null });
        }
    }

    const expTitle = document.getElementById('drExpTitle');
    const expRows  = drGroupRows('exp');
    if (expTitle) {
        if (expRows.length > 0) {
            blocks.push({ el: expTitle, glueWith: expRows[0] });
            for (let i = 1; i < expRows.length; i++) blocks.push({ el: expRows[i], glueWith: null });
        } else {
            blocks.push({ el: expTitle, glueWith: null });
        }
    }

    return blocks;
}

function drMeasureHeaderFooter() {
    const headerTemplate = document.getElementById('drHeaderClone');
    const footerTemplate = document.getElementById('drFooterClone');
    const letterhead = headerTemplate ? headerTemplate.querySelector('.dr-letterhead') : null;
    const titleBand   = headerTemplate ? headerTemplate.querySelector('.dr-title-band')  : null;
    const footerBand  = footerTemplate ? footerTemplate.querySelector('.dr-footer-band') : null;

    const hdrH = (letterhead || titleBand)
        ? drMeasureSandboxHeight([letterhead, titleBand], DR_PAGE_W)
        : 120;
    const ftrH = footerBand
        ? drMeasureSandboxHeight([footerBand], DR_PAGE_W)
        : 24;

    return { hdrH, ftrH };
}

function drBuildPageShell(pageIndex, totalPages) {
    const paper = document.createElement('div');
    paper.className = 'dr-paper';
    paper.dataset.pageIndex = pageIndex;

    const hdrTemplate = document.getElementById('drHeaderClone');
    const hdr = hdrTemplate.cloneNode(true);
    hdr.removeAttribute('id');
    hdr.style.cssText = '';
    const metaEl = hdr.querySelector('.dr-form-meta');
    if (metaEl) metaEl.textContent = 'Page ' + (pageIndex + 1) + ' of ' + totalPages;
    while (hdr.firstChild) paper.appendChild(hdr.firstChild);

    const body = document.createElement('div');
    body.className = 'dr-form-body';
    paper.appendChild(body);

    const ftrTemplate = document.getElementById('drFooterClone');
    const ftr = ftrTemplate.cloneNode(true);
    ftr.removeAttribute('id');
    ftr.style.cssText = '';
    while (ftr.firstChild) paper.appendChild(ftr.firstChild);

    return { paper, body };
}

function drRepaginateResume(afterCallback) {
    const wrap = document.getElementById('drPagesWrap');
    if (!wrap) return;

    const runRepagination = function() {
        const blocks = drBuildResumeBlocks();
        if (blocks.length === 0) return;

        const { hdrH, ftrH } = drMeasureHeaderFooter();
        const usableH = DR_PAGE_H - hdrH - ftrH - DR_BODY_PAD_TB - DR_SAFETY_BUFFER;

        const heights = blocks.map(b => {
            return b.glueWith
                ? drMeasureSandboxHeight([b.el, b.glueWith])
                : drMeasureSandboxHeight([b.el]);
        });

        const pageAssign = [];
        let curPage = [];
        let curH = 0;
        blocks.forEach((b, i) => {
            const h = heights[i] + (curPage.length > 0 ? DR_BLOCK_GAP : 0);
            if (curH + h > usableH && curPage.length > 0) {
                pageAssign.push(curPage);
                curPage = [];
                curH = 0;
            }
            curPage.push(b);
            curH += h;
        });
        if (curPage.length > 0) pageAssign.push(curPage);
        if (pageAssign.length === 0) pageAssign.push([]);

        const totalPages = pageAssign.length;

        const newPages = [];
        pageAssign.forEach((pageBlocks, idx) => {
            const shell = drBuildPageShell(idx, totalPages);
            pageBlocks.forEach(b => {
                shell.body.appendChild(b.el);
                if (b.glueWith) shell.body.appendChild(b.glueWith);
            });
            newPages.push(shell.paper);
        });

        wrap.innerHTML = '';
        newPages.forEach(p => wrap.appendChild(p));

        if (drCurrentPageIndex >= totalPages) drCurrentPageIndex = totalPages - 1;
        if (drCurrentPageIndex < 0) drCurrentPageIndex = 0;
        drShowPage(drCurrentPageIndex);

        document.querySelectorAll('#drPagesWrap .autogrow-textarea').forEach(el => autoGrowTextarea(el));

        if (typeof afterCallback === 'function') afterCallback();
    };

    if (document.fonts && document.fonts.ready) {
        document.fonts.ready.then(runRepagination);
    } else {
        runRepagination();
    }
}

function drShowPage(index) {
    const pages = document.querySelectorAll('#drPagesWrap .dr-paper');
    if (pages.length === 0) return;
    if (index < 0) index = 0;
    if (index > pages.length - 1) index = pages.length - 1;
    drCurrentPageIndex = index;

    pages.forEach((p, i) => {
        p.classList.toggle('dr-page-active', i === index);
        p.classList.toggle('dr-page-inactive', i !== index);
    });

    const nav       = document.getElementById('drPagNav');
    const indicator = document.getElementById('drPageIndicator');
    if (indicator) indicator.textContent = 'Page ' + (index + 1) + ' of ' + pages.length;
    if (nav) nav.style.display = pages.length > 1 ? 'flex' : 'none';

    const prevBtn = document.getElementById('drPrevPageBtn');
    const nextBtn = document.getElementById('drNextPageBtn');
    if (prevBtn) prevBtn.disabled = (index === 0);
    if (nextBtn) nextBtn.disabled = (index === pages.length - 1);
}

(function() {
    const prevBtn = document.getElementById('drPrevPageBtn');
    const nextBtn = document.getElementById('drNextPageBtn');
    if (prevBtn) prevBtn.addEventListener('click', () => drShowPage(drCurrentPageIndex - 1));
    if (nextBtn) nextBtn.addEventListener('click', () => drShowPage(drCurrentPageIndex + 1));
})();

document.addEventListener('focusout', function(e) {
    if (e.target && e.target.classList && e.target.classList.contains('autogrow-textarea') && e.target.closest('#drPagesWrap')) {
        drRepaginateResume();
    }
});

window.addEventListener('resize', function() {
    clearTimeout(window._drResizeTimer);
    window._drResizeTimer = setTimeout(function() {
        const resumePane = document.getElementById('tab-resume');
        if (resumePane && resumePane.classList.contains('active')) drRepaginateResume();
    }, 300);
});

(function() {
    const runInitial = function() {
        const resumePane = document.getElementById('tab-resume');
        if (resumePane && resumePane.classList.contains('active')) drRepaginateResume();
    };
    if (document.fonts && document.fonts.ready) {
        document.fonts.ready.then(runInitial);
    } else {
        runInitial();
    }
})();

/* ══════════════════════════════════════════════════════════════
   ATTENDANCE NOTIFICATION BAR — v3 (unchanged from original)
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
    document.getElementById('anb-window').textContent = 'Window: ' + info.start_fmt + ' \u2013 ' + info.end_fmt;
    document.getElementById('anb-countdown').textContent = 'Calculating...';

    const anbActionBtn = document.getElementById('anb-action-btn');
    if (info.is_late_window) {
        anbActionBtn.textContent = 'Request now';
    } else {
        anbActionBtn.textContent = 'Sign now';
    }
    anbActionBtn.onclick = function() { window.location.href = 'student_attendance.php'; };

    document.getElementById('att-notif-bar')
            .classList.toggle('sidebar-collapsed', sidebar.classList.contains('collapsed'));

    if (_anb.tickInterval  !== null) { clearInterval(_anb.tickInterval);  _anb.tickInterval  = null; }
    if (_anb.autoHideTimer !== null) { clearTimeout(_anb.autoHideTimer);  _anb.autoHideTimer = null; }
    if (_anb.rafId         !== null) { cancelAnimationFrame(_anb.rafId);  _anb.rafId         = null; }

    const prog     = document.getElementById('anb-progress');
    const duration = _anb.autoHideDuration;
    const startTs  = _anb.showStartTs;

    if (prog) {
        prog.style.transition = 'none';
        prog.style.width = '100%';
        void prog.offsetWidth;
    }

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
        const m = Math.floor(rem / 60);
        const s = rem % 60;
        document.getElementById('anb-countdown').textContent =
            m + 'm ' + String(s).padStart(2, '0') + 's left';
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
        pm_time_out: { label: 'PM Duty Sign Out', startKey: 'pm_time_out_start', endKey: 'pm_time_out_end'  },
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

    if (_anbPmLateShown) return;
    const pmOutEndStr = ANB_TODAY_SETTINGS['pm_time_out_end'];
    if (!pmOutEndStr) return;

    const pmOutEndSec   = _anbTimeToSec(pmOutEndStr);
    const lateWindowEnd = pmOutEndSec + 3600;

    if (ns <= pmOutEndSec || ns > lateWindowEnd) return;
    if (_anb.dismissedWindows.has('pm_time_out_late')) return;

    const lateWindowEndH   = Math.floor(lateWindowEnd / 3600);
    const lateWindowEndM   = Math.floor((lateWindowEnd % 3600) / 60);
    const lateWindowEndStr = String(lateWindowEndH).padStart(2, '0') + ':' +
                             String(lateWindowEndM).padStart(2, '0') + ':00';

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
</script>
<script>
/* ══════════════════════════════════════════════════════════════════════
   ADJUSTMENT: LIVE APPLICATION POPUPS + COMPANY LIST SIDEBAR INDICATOR
   ------------------------------------------------------------
   Ported from company_list.php. Every 5 s this page asks
   company_list.php?poll_application=1 (the same endpoint company_list.php
   uses) where the student's application is ("admin" / "company" /
   registered) and how many endorsement letters need attention. When
   something changed, the same navy popup (.cv-top-toast) explains it, and
   the RED badge on the "Company List" link is updated.
   The last snapshot is shared between the student pages through
   localStorage, so a change is announced once, on whichever page the
   student happens to be on. Every failure is silent and retried next tick.
   ══════════════════════════════════════════════════════════════════════ */
(function () {
    var UID      = <?= json_encode((int)$user_id) ?>;
    var KEY      = 'cl_live_state_' + UID;
    var TTL_MS   = 12 * 60 * 60 * 1000;   // an older snapshot is only used as a new baseline
    var POLL_MS  = 5000;
    var badge    = document.getElementById('endoSidebarBadge');
    var mem      = null;                  // last snapshot seen by this page
    var names    = {};
    var busy     = false;

    function esc(s) {
        return String(s == null ? '' : s)
            .replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;').replace(/'/g, '&#39;');
    }
    function nameOf(id) { return names[String(id)] || 'The company'; }

    function layoutToasts() {
        var top = 30;
        document.querySelectorAll('.cv-top-toast').forEach(function (el) {
            el.style.top = top + 'px';
            top += el.offsetHeight + 12;
        });
    }
    function showToast(name, text, icon, go) {
        var div = document.createElement('div');
        div.className = 'cv-top-toast';
        div.setAttribute('role', 'status');
        div.innerHTML = '<i class="fas ' + esc(icon || 'fa-circle-info') + '"></i><span>' +
            (name ? '<strong>' + esc(name) + '</strong> ' : '') + esc(text) + '</span>';
        document.body.appendChild(div);
        tagToast(div, go);
        layoutToasts();
        requestAnimationFrame(function () { div.classList.add('show'); });
        setTimeout(function () {
            div.classList.remove('show');
            setTimeout(function () { div.remove(); layoutToasts(); }, 400);
        }, 7000);
    }

    // Clickable popup (administrator.php's data-cv-go pattern): opens the Inbox / the company on company_list.php.
    function tagToast(el, spec) {
        if (!el || !spec) return;
        el.setAttribute('data-cv-go', spec);
        el.setAttribute('role', 'link');
        el.setAttribute('tabindex', '0');
        el.setAttribute('aria-label', (el.textContent || '').replace(/\s+/g, ' ').trim() + ' \u2014 open');
        var hint = document.createElement('span');
        hint.className = 'cv-toast-go';
        hint.setAttribute('aria-hidden', 'true');
        hint.innerHTML = 'View <i class="fas fa-chevron-right"></i>';
        el.appendChild(hint);
    }
    function goSpecFor(ev) { return ev.inbox ? 'inbox' : (ev.id ? 'company:' + ev.id : ''); }
    function goTo(spec) {
        var p = String(spec || '').split(':');
        var url = null;
        if (p[0] === 'inbox') url = 'company_list.php?inbox=1';
        else if (p[0] === 'company' && /^\d+$/.test(p[1] || '')) url = 'company_list.php?open_company=' + p[1];
        if (url) window.location.href = url;
    }
    function activate(toast) {
        var spec = toast.getAttribute('data-cv-go');
        toast.classList.remove('show');
        setTimeout(function () { if (toast.parentNode) toast.parentNode.removeChild(toast); layoutToasts(); }, 350);
        goTo(spec);
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

    function setBadge(n) {
        if (!badge) return;
        n = parseInt(n, 10) || 0;
        badge.textContent = n > 0 ? String(n) : '';
        badge.classList.toggle('is-on', n > 0);
        badge.title = n > 0 ? 'You have endorsement letter(s) in your Inbox' : '';
        badge.setAttribute('aria-label', n > 0 ? n + ' endorsement letter notification(s)' : '');
    }

    function loadSaved() {
        try {
            var o = JSON.parse(localStorage.getItem(KEY) || 'null');
            if (o && o.s && o.s.pending && typeof o.t === 'number' && (Date.now() - o.t) < TTL_MS) return o;
        } catch (e) {}
        return null;
    }
    function save(state) {
        try { localStorage.setItem(KEY, JSON.stringify({ t: Date.now(), s: state, n: names })); } catch (e) {}
    }

    // What changed between two snapshots -> list of popups (same wording as company_list.php).
    function describe(prev, next) {
        var out = [];
        var prevReg = prev.registered ? String(prev.registered) : null;
        var nextReg = next.registered ? String(next.registered) : null;
        if (nextReg && nextReg !== prevReg) {
            out.push({ id: nextReg, name: nameOf(nextReg), text: 'accepted your application — you are now registered as their OJT trainee.', icon: 'fa-circle-check' });
        }
        if (prevReg && prevReg !== nextReg) {
            out.push({ id: prevReg, name: nameOf(prevReg), text: 'no longer has you registered as their OJT trainee.', icon: 'fa-circle-info' });
        }
        var pp = prev.pending || {}, np = next.pending || {};
        Object.keys(pp).forEach(function (id) {
            if (np[id] === pp[id]) return;
            if (!np[id]) {
                if (id === nextReg) return; // accepted — already announced above
                if (pp[id] === 'hold') { out.push({ id: id, name: nameOf(id), text: '— your on-hold application was cancelled.', icon: 'fa-circle-xmark' }); return; }
                out.push(pp[id] === 'admin'
                    ? { id: id, name: nameOf(id), text: '— your application was not approved by the administrator.', icon: 'fa-circle-xmark' }
                    : { id: id, name: nameOf(id), text: 'did not accept your application.', icon: 'fa-circle-xmark' });
            } else if (pp[id] === 'hold' && np[id] === 'admin') {
                out.push({ id: id, name: nameOf(id), text: '— your requirements are verified again, so your application was sent automatically and is Waiting for the Approval.', icon: 'fa-paper-plane' });
            } else if (pp[id] === 'admin' && np[id] === 'company') {
                out.push({ id: id, name: nameOf(id), text: '— the administrator approved your application. It is now Under Company Validation; your endorsement letter is in your Inbox (Company List).', icon: 'fa-envelope-circle-check', inbox: true });
            }
        });
        Object.keys(np).forEach(function (id) {
            if (!pp[id]) out.push(np[id] === 'hold'
                ? { id: id, name: nameOf(id), text: '— your application is On Hold until your new Application SIT is verified.', icon: 'fa-pause-circle' }
                : np[id] === 'company'
                ? { id: id, name: nameOf(id), text: '— the administrator applied you to this company. It is now Under Company Validation; your endorsement letter is in your Inbox (Company List).', icon: 'fa-envelope-circle-check', inbox: true }
                : { id: id, name: nameOf(id), text: '— your application was sent and is Waiting for the Approval.', icon: 'fa-paper-plane' });
        });
        return out;
    }

    function poll() {
        if (busy || document.hidden) return;
        busy = true;
        fetch('company_list.php?poll_application=1', { credentials: 'same-origin', cache: 'no-store' })
            .then(function (r) { return r.ok ? r.json() : null; })
            .then(function (next) {
                if (!next || typeof next !== 'object' || !next.pending) return;
                var saved = loadSaved();
                if (saved && saved.n) Object.assign(names, saved.n);
                Object.assign(names, next.names || {});
                var prev = saved ? saved.s : mem;   // the newest snapshot any page has seen
                mem = next;
                save(next);
                if (next.endo) setBadge(next.endo.attention);
                if (!prev) return;                 // first look: just a baseline
                describe(prev, next).forEach(function (ev) { showToast(ev.name, ev.text, ev.icon, goSpecFor(ev)); });
            })
            .catch(function () { /* silent — retried on the next tick */ })
            .finally(function () { busy = false; });
    }

    setTimeout(function () {
        poll();
        setInterval(poll, POLL_MS);
    }, 1500);
    document.addEventListener('visibilitychange', function () { if (!document.hidden) poll(); });
})();
</script>

</body>
</html>