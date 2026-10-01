<?php
session_start();
include "db.php";

/* ================= SESSION CHECK ================= */
if (!isset($_SESSION['user_id']) || $_SESSION['role'] != "student") {
    header("Location: login.php");
    exit;
}

$user_id = $_SESSION['user_id'];
date_default_timezone_set("Asia/Manila");

/* ============================================================
   AJAX ENDPOINT — returns live requirement statuses as JSON
   ------------------------------------------------------------
   ADJUSTMENT: powers the live sidebar lock/unlock activation
   further down this page (see activateVerifiedSidebar() /
   deactivateVerifiedSidebar() in the <script> block), mirroring
   the same lightweight polling pattern already used by
   AccomForm.php's own `?poll_status=1` endpoint. This lets the
   sidebar unlock (or re-lock) the instant the administrator
   verifies (or reverts) the student's last remaining requirement,
   without the student needing to manually reload this page.

   Only the `status` field is needed here (the sidebar gate only
   cares about Verified/not-Verified), so this endpoint stays
   intentionally smaller than AccomForm.php's version, which also
   needs file_name/remark to live-update its requirement cards —
   a feature that belongs to AccomForm.php only and is left
   untouched here.
   URL: company_list.php?poll_status=1
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
            // ADJUSTMENT (apply gate): additive flag only — lets the new
            // "Requirements Not Yet Verified" popup tell a requirement that
            // was never uploaded ("Not Submitted") apart from one that is
            // uploaded but still awaiting review ("Pending"). The existing
            // sidebar polling only reads `status`, so it is unaffected.
            'has_row' => (bool)$pr,
        ];
    }
    echo json_encode($out);
    exit;
}

/* ============================================================
   ADJUSTMENT: LIVE APPLICATION STATE — where the application is
   ------------------------------------------------------------
   Lets this page notice, without a reload, when an application
   moves between stages or the student gets registered:
     • "admin"   — waiting in admin_application_approvals
     • "company" — approved by the admin, now in ojt_applications
                   (phase = 'pending') for the company to validate
     • registered — the company accepted (ojt_assignments)
   Same data the page itself is rendered from (see
   $pending_applications / $current_company_id below).
   URL: company_list.php?poll_application=1
   ============================================================ */
function clAppLiveState($conn, $user_id) {
    $pending = [];
    try {
        $q = $conn->prepare("SELECT company_id FROM admin_application_approvals WHERE student_id = ?");
        $q->bind_param("i", $user_id);
        $q->execute();
        $r = $q->get_result();
        while ($row = $r->fetch_assoc()) $pending[(string)(int)$row['company_id']] = 'admin';
        $q->close();
    } catch (\Throwable $e) {}
    try {
        $q = $conn->prepare("SELECT company_id FROM ojt_applications WHERE student_id = ? AND phase = 'pending'");
        $q->bind_param("i", $user_id);
        $q->execute();
        $r = $q->get_result();
        while ($row = $r->fetch_assoc()) $pending[(string)(int)$row['company_id']] = 'company';
        $q->close();
    } catch (\Throwable $e) {}
    $registered = null;
    try {
        $q = $conn->prepare("SELECT company_id FROM ojt_assignments WHERE student_id = ? LIMIT 1");
        $q->bind_param("i", $user_id);
        $q->execute();
        $row = $q->get_result()->fetch_assoc();
        $q->close();
        if ($row) $registered = (int)$row['company_id'];
    } catch (\Throwable $e) {}

    // Display names for the companies involved (same rule as the company rows).
    $names = [];
    $ids = array_map('intval', array_keys($pending));
    if ($registered) $ids[] = $registered;
    $ids = array_values(array_unique($ids));
    if ($ids) {
        try {
            $in = implode(',', $ids);
            $r = $conn->query("SELECT u.id, u.first_name, u.last_name, ci.company FROM users u
                               LEFT JOIN company_information ci ON ci.user_id = u.id WHERE u.id IN ($in)");
            while ($row = $r->fetch_assoc()) {
                $names[(string)(int)$row['id']] = !empty($row['company']) ? $row['company'] : trim($row['first_name'] . ' ' . $row['last_name']);
            }
        } catch (\Throwable $e) {}
    }
    return ['pending' => (object)$pending, 'registered' => $registered, 'names' => (object)$names];
}

if (isset($_GET['poll_application'])) {
    header('Content-Type: application/json');
    header('Cache-Control: no-store');
    echo json_encode(clAppLiveState($conn, $user_id));
    exit;
}

/* ============================================================
   NEW (endorsement flow): ENDORSEMENT LETTER INBOX
   ------------------------------------------------------------
   Once the administrator allows an application (administrator.php),
   an Endorsement Letter (ENDORSEMENT_form_builder.php) is issued to
   the student. It arrives in the Inbox on this page, where the
   student can:
     • open it full screen (same document-viewer look as the Digital
       Resume), print it, or save it as a PDF;
     • upload the signed letter (image or PDF) for the company to
       validate on add_ojt_student.php.
   Verified → the student is registered to the company.
   Rejected → the company's remarks appear here; re-upload allowed.
   ============================================================ */
require_once __DIR__ . '/ENDORSEMENT_form_builder.php';

if (!function_exists('ensureEndorsementLettersTable')) {
    function ensureEndorsementLettersTable($conn) {
        try {
            $conn->query("CREATE TABLE IF NOT EXISTS endorsement_letters (
                id INT AUTO_INCREMENT PRIMARY KEY,
                student_id INT NOT NULL,
                company_id INT NOT NULL,
                letter_data LONGTEXT NULL,
                sent_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                student_viewed TINYINT(1) NOT NULL DEFAULT 0,
                uploaded_file LONGBLOB NULL,
                uploaded_mime VARCHAR(100) NULL,
                uploaded_name VARCHAR(255) NULL,
                uploaded_at DATETIME NULL,
                validation_status VARCHAR(20) NOT NULL DEFAULT 'Awaiting Upload',
                validation_remark TEXT NULL,
                validated_at DATETIME NULL,
                UNIQUE KEY unique_endorsement (student_id, company_id)
            )");
        } catch (\Throwable $e) { /* never break the page over this */ }
    }
}
ensureEndorsementLettersTable($conn);
// ADJUSTMENT: batch letters (admin_monitoring_dashboard.php) — students applied together share one
// letter and one signed upload (endorsement_letters.batch_id).
try {
    $bc = $conn->query("SHOW COLUMNS FROM endorsement_letters LIKE 'batch_id'");
    if ($bc && $bc->num_rows === 0) $conn->query("ALTER TABLE endorsement_letters ADD COLUMN batch_id VARCHAR(40) NULL");
} catch (\Throwable $e) {}

const ENDO_MAX_UPLOAD_BYTES = 8 * 1024 * 1024; // 8 MB
const ENDO_ALLOWED_MIMES    = ['image/jpeg', 'image/png', 'image/webp', 'application/pdf'];

/* ADJUSTMENT: the other students who share this batch letter (names only). */
function endoBatchMates($conn, $batchId, $companyId, $userId) {
    if ((string)$batchId === '') return [];
    $out = [];
    try {
        $q = $conn->prepare("SELECT u.first_name, u.last_name FROM endorsement_letters el INNER JOIN users u ON u.id = el.student_id
                             WHERE el.batch_id = ? AND el.company_id = ? AND el.student_id <> ? ORDER BY u.first_name, u.last_name");
        $q->bind_param("sii", $batchId, $companyId, $userId);
        $q->execute();
        $res = $q->get_result();
        while ($m = $res->fetch_assoc()) $out[] = trim($m['first_name'] . ' ' . $m['last_name']);
        $q->close();
    } catch (\Throwable $e) {}
    return $out;
}

/* Letters issued to this student, newest first (no file blobs). */
function fetchStudentEndorsements($conn, $user_id) {
    $out = [];
    $q = $conn->prepare("
        SELECT el.id, el.company_id, el.sent_at, el.student_viewed, el.uploaded_at, el.uploaded_name,
               el.uploaded_mime, el.validation_status, el.validation_remark, el.validated_at, el.batch_id,
               (el.uploaded_file IS NOT NULL) AS has_upload,
               ci.company AS company_name, u.first_name, u.last_name
        FROM endorsement_letters el
        LEFT JOIN company_information ci ON ci.user_id = el.company_id
        LEFT JOIN users u ON u.id = el.company_id
        WHERE el.student_id = ?
        ORDER BY el.sent_at DESC, el.id DESC
    ");
    $q->bind_param("i", $user_id);
    $q->execute();
    $res = $q->get_result();
    while ($r = $res->fetch_assoc()) {
        $cname = !empty($r['company_name']) ? $r['company_name'] : trim(($r['first_name'] ?? '') . ' ' . ($r['last_name'] ?? ''));
        $out[] = [
            'id'            => (int)$r['id'],
            'company_id'    => (int)$r['company_id'],
            'company_name'  => $cname !== '' ? $cname : 'Company',
            'sent_at'       => $r['sent_at'] ? date('M j, Y g:i A', strtotime($r['sent_at'])) : '',
            'viewed'        => (bool)$r['student_viewed'],
            'status'        => $r['validation_status'] ?: 'Awaiting Upload',
            'remark'        => $r['validation_remark'] ?? '',
            'has_upload'    => (bool)$r['has_upload'],
            'uploaded_at'   => $r['uploaded_at'] ? date('M j, Y g:i A', strtotime($r['uploaded_at'])) : '',
            'uploaded_name' => $r['uploaded_name'] ?? '',
            'uploaded_mime' => $r['uploaded_mime'] ?? '', // ADJUSTMENT: image thumbnail vs PDF tile in the inbox gallery
            'batch_with'    => endoBatchMates($conn, $r['batch_id'] ?? '', (int)$r['company_id'], $user_id), // ADJUSTMENT: shared batch letter
            'validated_at'  => $r['validated_at'] ? date('M j, Y', strtotime($r['validated_at'])) : '',
        ];
    }
    $q->close();
    return $out;
}

/* Items that need the student's attention: unread letters, or letters still to (re-)upload. */
function endorsementAttentionCount(array $letters) {
    $n = 0;
    foreach ($letters as $l) {
        if (!$l['viewed'] || in_array($l['status'], ['Awaiting Upload', 'Rejected'], true)) $n++;
    }
    return $n;
}

/* ── GET: inbox list (JSON) ── */
if (isset($_GET['endorsement_inbox'])) {
    header('Content-Type: application/json');
    $letters = fetchStudentEndorsements($conn, $user_id);
    echo json_encode(['success' => true, 'letters' => $letters, 'attention' => endorsementAttentionCount($letters)]);
    exit;
}

/* ── GET: the letter itself (full HTML). Opening it marks it as read. ──
   With &embed=1 a small helper is injected so the parent page's
   "Save as PDF" button can render each A4 page straight to a PDF file. */
if (isset($_GET['endorsement_letter'])) {
    $eid = intval($_GET['endorsement_letter']);
    $q = $conn->prepare("SELECT letter_data FROM endorsement_letters WHERE id = ? AND student_id = ?");
    $q->bind_param("ii", $eid, $user_id);
    $q->execute();
    $row = $q->get_result()->fetch_assoc();
    $q->close();
    if (!$row) { http_response_code(404); echo 'Letter not found.'; exit; }

    $mv = $conn->prepare("UPDATE endorsement_letters SET student_viewed = 1 WHERE id = ? AND student_id = ?");
    $mv->bind_param("ii", $eid, $user_id);
    $mv->execute();
    $mv->close();

    $letter = json_decode($row['letter_data'] ?? '{}', true);
    $html   = buildEndorsementFormHTML(is_array($letter) ? $letter : []);

    if (!empty($_GET['embed'])) {
        $helper = <<<'JS'
<script>
/* Injected by company_list.php — PDF export of the rendered A4 pages. */
(function () {
    function load(src) {
        return new Promise(function (resolve, reject) {
            var s = document.createElement('script');
            s.src = src; s.onload = resolve; s.onerror = reject;
            document.head.appendChild(s);
        });
    }
    window.endoSavePdf = function (fileName) {
        var libs = Promise.resolve();
        if (!window.html2canvas) libs = libs.then(function () { return load('https://cdnjs.cloudflare.com/ajax/libs/html2canvas/1.4.1/html2canvas.min.js'); });
        if (!window.jspdf)       libs = libs.then(function () { return load('https://cdnjs.cloudflare.com/ajax/libs/jspdf/2.5.1/jspdf.umd.min.js'); });
        return libs.then(function () { return document.fonts.ready; }).then(function () {
            var pages = document.querySelectorAll('#rendering-preview-root .doc-paper');
            if (!pages.length) throw new Error('Letter not rendered yet');
            var pdf = new window.jspdf.jsPDF({ orientation: 'p', unit: 'mm', format: 'a4' });
            var chain = Promise.resolve();
            Array.prototype.forEach.call(pages, function (page, i) {
                chain = chain.then(function () {
                    return window.html2canvas(page, { scale: 2, useCORS: true, backgroundColor: '#ffffff' });
                }).then(function (canvas) {
                    if (i > 0) pdf.addPage();
                    pdf.addImage(canvas.toDataURL('image/jpeg', 0.95), 'JPEG', 0, 0, 210, 297);
                });
            });
            return chain.then(function () { pdf.save(fileName || 'Endorsement_Letter.pdf'); });
        });
    };
})();
</script>
JS;
        $pos = strripos($html, '</body>');
        $html = ($pos !== false) ? substr($html, 0, $pos) . $helper . substr($html, $pos) : $html . $helper;
    }
    header('Content-Type: text/html; charset=UTF-8');
    echo $html;
    exit;
}

/* ── GET: the student's own uploaded copy ── */
if (isset($_GET['view_my_endorsement_upload'])) {
    $eid = intval($_GET['view_my_endorsement_upload']);
    $q = $conn->prepare("SELECT uploaded_file, uploaded_mime, uploaded_name FROM endorsement_letters WHERE id = ? AND student_id = ?");
    $q->bind_param("ii", $eid, $user_id);
    $q->execute();
    $f = $q->get_result()->fetch_assoc();
    $q->close();
    if (!$f || $f['uploaded_file'] === null) { http_response_code(404); echo 'File not found.'; exit; }
    $name = preg_replace('/[^A-Za-z0-9._-]+/', '_', $f['uploaded_name'] ?: 'endorsement_letter');
    header('Content-Type: ' . ($f['uploaded_mime'] ?: 'application/octet-stream'));
    header('Content-Disposition: inline; filename="' . $name . '"');
    header('X-Content-Type-Options: nosniff');
    header('Content-Length: ' . strlen($f['uploaded_file']));
    echo $f['uploaded_file'];
    exit;
}

/* ── POST (AJAX): upload the signed endorsement letter ── */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['upload_endorsement'])) {
    header('Content-Type: application/json');
    $eid = intval($_POST['endorsement_id'] ?? 0);

    $q = $conn->prepare("SELECT id, validation_status FROM endorsement_letters WHERE id = ? AND student_id = ?");
    $q->bind_param("ii", $eid, $user_id);
    $q->execute();
    $letterRow = $q->get_result()->fetch_assoc();
    $q->close();
    if (!$letterRow) {
        echo json_encode(['success' => false, 'message' => 'Endorsement letter not found.']);
        exit;
    }
    if ($letterRow['validation_status'] === 'Verified') {
        echo json_encode(['success' => false, 'message' => 'This letter has already been verified by the company.']);
        exit;
    }

    $file = $_FILES['endorsement_file'] ?? null;
    if (!$file || ($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
        $err = $file['error'] ?? UPLOAD_ERR_NO_FILE;
        $msg = in_array($err, [UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE], true)
            ? 'The file is too large for the server. Please upload a smaller file.'
            : 'Please choose a file to upload.';
        echo json_encode(['success' => false, 'message' => $msg]);
        exit;
    }
    if ($file['size'] <= 0 || $file['size'] > ENDO_MAX_UPLOAD_BYTES) {
        echo json_encode(['success' => false, 'message' => 'The file must be smaller than 8 MB.']);
        exit;
    }

    $mime = '';
    if (function_exists('finfo_open')) {
        $fi = finfo_open(FILEINFO_MIME_TYPE);
        $mime = $fi ? (string)finfo_file($fi, $file['tmp_name']) : '';
        if ($fi) finfo_close($fi);
    } elseif (function_exists('mime_content_type')) {
        $mime = (string)mime_content_type($file['tmp_name']);
    }
    if ($mime === '') {
        $ext  = strtolower(pathinfo($file['name'] ?? '', PATHINFO_EXTENSION));
        $mime = ['jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'png' => 'image/png', 'webp' => 'image/webp', 'pdf' => 'application/pdf'][$ext] ?? '';
    }
    if (!in_array($mime, ENDO_ALLOWED_MIMES, true)) {
        echo json_encode(['success' => false, 'message' => 'Only JPG, PNG, WEBP or PDF files are accepted.']);
        exit;
    }

    $data  = file_get_contents($file['tmp_name']);
    $oname = basename((string)($file['name'] ?? 'endorsement_letter'));
    $oname = function_exists('mb_substr') ? mb_substr($oname, 0, 200) : substr($oname, 0, 200);

    $up = $conn->prepare("
        UPDATE endorsement_letters
        SET uploaded_file = ?, uploaded_mime = ?, uploaded_name = ?, uploaded_at = NOW(),
            validation_status = 'Pending', validation_remark = NULL, validated_at = NULL, student_viewed = 1
        WHERE id = ? AND student_id = ? AND validation_status <> 'Verified'
    ");
    $null = null;
    $up->bind_param("bssii", $null, $mime, $oname, $eid, $user_id);
    foreach (str_split($data, 1024 * 1024) as $chunk) { // 1 MB chunks
        $up->send_long_data(0, $chunk);
    }
    $ok = $up->execute();
    $up->close();

    /* ADJUSTMENT: a batch letter is shared — the signed upload counts for every student in the
       batch (copied inside the database; students already accepted are left untouched). */
    $sharedWith = 0;
    if ($ok) {
        try {
            $cp = $conn->prepare("UPDATE endorsement_letters t INNER JOIN endorsement_letters s ON s.id = ?
                SET t.uploaded_file = s.uploaded_file, t.uploaded_mime = s.uploaded_mime, t.uploaded_name = s.uploaded_name,
                    t.uploaded_at = s.uploaded_at, t.validation_status = 'Pending', t.validation_remark = NULL, t.validated_at = NULL
                WHERE s.batch_id IS NOT NULL AND s.batch_id <> '' AND t.batch_id = s.batch_id AND t.company_id = s.company_id
                  AND t.id <> s.id AND t.validation_status <> 'Verified'");
            $cp->bind_param("i", $eid);
            $cp->execute();
            $sharedWith = $cp->affected_rows;
            $cp->close();
        } catch (\Throwable $e) {}
    }

    echo json_encode([
        'success' => (bool)$ok,
        'message' => $ok ? 'Endorsement letter uploaded. The company will validate it.' . ($sharedWith > 0 ? ' It was shared with the other students in your batch.' : '') : 'Upload failed. Please try again.',
    ]);
    exit;
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

        $_att_all_done = $_att_log && is_array($_att_log)
            && ($_att_log['am_time_in']  !== null && $_att_log['am_time_in']  !== '' && $_att_log['am_time_in']  !== 'missed')
            && ($_att_log['am_time_out'] !== null && $_att_log['am_time_out'] !== '' && $_att_log['am_time_out'] !== 'missed')
            && ($_att_log['pm_time_in']  !== null && $_att_log['pm_time_in']  !== '' && $_att_log['pm_time_in']  !== 'missed')
            && ($_att_log['pm_time_out'] !== null && $_att_log['pm_time_out'] !== '' && $_att_log['pm_time_out'] !== 'missed');

        // ── Normal attendance windows ──
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

        // ── FIX: PM Sign Out late-window badge (mirrors student_attendance.php) ──
        if (!$attendance_badge_info && !$_att_all_done) {
            $_att_pm_out_end = $_att_setting['pm_time_out_end'] ?? null;
            if ($_att_pm_out_end) {
                $_att_pm_out_end_sec   = $timeToSec($_att_pm_out_end);
                $_att_late_window_end  = $_att_pm_out_end_sec + 3600;

                if ($_att_now_sec > $_att_pm_out_end_sec && $_att_now_sec <= $_att_late_window_end) {
                    $_att_pm_out_val  = (is_array($_att_log) && array_key_exists('pm_time_out', $_att_log))
                        ? $_att_log['pm_time_out']
                        : null;
                    $_att_pm_out_done = ($_att_pm_out_val !== null && $_att_pm_out_val !== '' && $_att_pm_out_val !== 'missed');

                    if (!$_att_pm_out_done) {
                        $_att_late_window_end_h   = floor($_att_late_window_end / 3600);
                        $_att_late_window_end_m   = floor(($_att_late_window_end % 3600) / 60);
                        $_att_late_window_end_str = sprintf('%02d:%02d:00', $_att_late_window_end_h, $_att_late_window_end_m);

                        $att_sidebar_badge     = true;
                        $attendance_badge_info = [
                            'type'           => 'pm_time_out_late',
                            'label'          => 'PM Sign Out Late Request',
                            'start_fmt'      => $fmt12att($_att_pm_out_end) . ' (missed)',
                            'end_fmt'        => $fmt12att($_att_late_window_end_str) . ' (deadline)',
                            'start_time'     => $_att_pm_out_end,
                            'end_time'       => $_att_late_window_end_str,
                            'is_late_window' => true,
                        ];
                    }
                }
            }
        }
    }
}

/* ================= FETCH STUDENT INFO =================
   ADJUSTMENT: skill1/skill2/skill3/exp1/exp2 are no longer stored on
   student_information — they now live entirely in the normalized
   student_skills / student_experience tables (see below), mirroring
   the same change already made in student_profile.php. This query
   now only pulls what's still actually stored on student_information,
   i.e. the photo. */
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

// FIX: Include middle_name in full_name for sidebar display
$full_name = trim(
    ($student['first_name']  ?? '') . ' ' .
    (!empty($student['middle_name']) ? $student['middle_name'] . ' ' : '') .
    ($student['last_name']   ?? '')
);

/* ================= DEPLOY STATUS FLAG (for JS sidebar gate) ================= */
$is_deployed = ($student['deploy_status'] === 'Deployed');

/* ============================================================
   SKILL / EXPERIENCE ENTRY LISTS (for the Digital Resume preview)
   ------------------------------------------------------------
   ADJUSTMENT: Skills and experience now live entirely in two
   normalized, one-row-per-entry tables — matching the schema
   introduced in student_profile.php — instead of the old
   skill1/skill2/skill3 + exp1/exp2 delimited-string columns on
   student_information:
       student_skills(id, user_id, entry_text, sort_order, created_at)
       student_experience(id, user_id, entry_text, sort_order, created_at)

   ensure_resume_tables() / fetch_entries() are the exact same
   helpers used in student_profile.php, so this page reads the
   identical, already-ordered list of entries that the student sees
   and edits there — no more separate delimiter-parsing logic is
   needed here. */
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

// Plain, ordered string lists — used directly by the Digital Resume
// preview below (each entry rendered as its own card).
$skill_entries = array_map(fn($r) => $r['entry_text'], $skill_rows);
$exp_entries   = array_map(fn($r) => $r['entry_text'], $exp_rows);

/* ============================================================
   LEGACY-COMPATIBLE SKILL/EXP STRINGS (for admin_application_approvals)
   ------------------------------------------------------------
   ADJUSTMENT: admin_application_approvals still stores applications
   using the older skill1/skill2/skill3/exp1/exp2 columns (unchanged
   by the student_profile.php migration), so when a student applies
   below we still need to hand that table values in that shape.
   These are derived from the new $skill_entries / $exp_entries lists:
     skill1 = 1st skill entry
     skill2 = 2nd skill entry
     skill3 = any further skill entries, joined with the same Unit
              Separator delimiter used previously
     exp1   = 1st experience entry
     exp2   = any further experience entries, joined with the same
              Record Separator delimiter used previously
   This keeps the Apply flow (and anything downstream still reading
   admin_application_approvals in the old format) working exactly as
   before, without requiring any schema change to that table. */
$SKILL_ENTRY_DELIM = "\x1F"; // Unit Separator — matches student_profile.php
$EXP_ENTRY_DELIM    = "\x1E"; // Record Separator — matches student_profile.php

$legacy_skill1 = $skill_entries[0] ?? '';
$legacy_skill2 = $skill_entries[1] ?? '';
$legacy_skill3 = (count($skill_entries) > 2)
    ? implode($SKILL_ENTRY_DELIM, array_slice($skill_entries, 2))
    : '';

$legacy_exp1 = $exp_entries[0] ?? '';
$legacy_exp2 = (count($exp_entries) > 1)
    ? implode($EXP_ENTRY_DELIM, array_slice($exp_entries, 1))
    : '';

/* ================= FETCH STUDENT REQUIREMENTS ================= */
$reqLabels = [
    "cert_registration"  => "Certification of Registration",
    "certificate_pdos"   => "Certificate of Participation (PDOS)",
    "ojt_sheet"          => "OJT Program & Information Sheet",
    "application_sit"    => "Application for Supervised Industrial Training",
    "waiver_form"        => "Waiver and Permission Form",
    "student_contract"   => "Student/University Contract",
    "psych_result"       => "Psych Test Result",
    "medical_result"     => "Medical Result",
];

$studentReqs = [];
$reqStmt = $conn->prepare("SELECT requirement_type, file_name, status, remark FROM requirements WHERE user_id = ?");
$reqStmt->bind_param("i", $user_id);
$reqStmt->execute();
$reqResult = $reqStmt->get_result();
while ($reqRow = $reqResult->fetch_assoc()) {
    $studentReqs[$reqRow['requirement_type']] = $reqRow;
}
$reqStmt->close();

/* ============================================================
   SIDEBAR LOCK GATE — "all requirements verified" flag
   ------------------------------------------------------------
   Ported from AccomForm.php: the student's sidebar on that page
   hides "My Profile" (and, further below, gates Attendance/Reports/
   Dashboard) until ALL 8 requirement types below are Verified by
   the administrator. This page now applies the exact same gate to
   its own sidebar, using the $studentReqs data already fetched
   above (no extra query needed, since it already holds every
   requirement row — including status — for this student).
   ============================================================ */
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
    if (($studentReqs[$_rt]['status'] ?? '') !== 'Verified') {
        $all_verified = false;
        break;
    }
}

/* ============================================================
   ADJUSTMENT: APPLY GATE — requirement status snapshot
   ------------------------------------------------------------
   A student may only apply to a company once ALL 8 requirement
   types above are Verified (the same $all_verified flag already
   used by the sidebar gate). $req_gate_statuses maps every
   required type to a display status ("Verified", "Pending",
   "Denied", ... or "Not Submitted" when no row exists yet). It
   feeds the new #reqUnverifiedModal popup, which lists exactly
   which requirements are still holding the student back. The JS
   side keeps this map live via the existing ?poll_status=1 loop.
   ============================================================ */
$req_gate_statuses = [];
foreach ($required_types as $_rt) {
    $req_gate_statuses[$_rt] = isset($studentReqs[$_rt])
        ? (($studentReqs[$_rt]['status'] ?? '') !== '' ? $studentReqs[$_rt]['status'] : 'Pending')
        : 'Not Submitted';
}

/* ================= GET CURRENT COMPANY ================= */
$current_company_id = null;

$checkCompany = $conn->prepare("SELECT company_id FROM ojt_assignments WHERE student_id = ?");
$checkCompany->bind_param("i", $user_id);
$checkCompany->execute();
$resCompany = $checkCompany->get_result();

if ($rowCompany = $resCompany->fetch_assoc()) {
    $current_company_id = $rowCompany['company_id'];
}

$already_registered = ($current_company_id !== null);

/* ============================================================
   ADJUSTMENT: PREFERRED-PLACEMENT MISMATCH → REPLACE + REMOVE SIT
   ------------------------------------------------------------
   The Application SIT (requirement type 'application_sit') is built
   from the student's "Preference for Placement" (student_information
   .pref_* — saved on AccomForm.php), so a signed SIT that names a
   different company than the one being applied to is no longer valid.

   When the student clicks Apply, the company's own data
   (company_information) is compared with that saved preference. If
   they do not match, the student is asked to confirm; on confirmation:
     1) the preferred placement is replaced with the company's data;
     2) the Application SIT row is deleted from `requirements`
        (users.validation_status falls back to 'Pending');
     3) the verified SIT file(s) administrator.php saved into the
        student's uploads/<First_Middle_Last>/ folder are deleted
        (same folder / file-name rule as administrator.php).
   The student then re-submits an Application SIT for the new
   company from AccomForm.php. Nothing here runs unless a mismatch
   is detected, so the normal apply flow is untouched.
   ============================================================ */
function clPlacementNorm($s) {
    return preg_replace('/[^a-z0-9]/', '', strtolower(trim((string)$s)));
}

/* The target company's data, same name rule as the company rows
   (company_information.company, else the account's own name). */
function clCompanyPlacementData(mysqli $conn, int $company_id): ?array {
    if ($company_id <= 0) return null;
    try {
        $q = $conn->prepare("SELECT u.first_name, u.last_name, ci.company, ci.company_address, ci.telephone,
                                    ci.contact_first_name, ci.contact_middle_initial, ci.contact_last_name, ci.position
                             FROM users u LEFT JOIN company_information ci ON ci.user_id = u.id
                             WHERE u.id = ? LIMIT 1");
        if (!$q) return null;
        $q->bind_param("i", $company_id);
        $q->execute();
        $r = $q->get_result()->fetch_assoc();
        $q->close();
    } catch (\Throwable $e) { return null; }
    if (!$r) return null;
    $name = trim((string)($r['company'] ?? ''));
    if ($name === '') $name = trim(($r['first_name'] ?? '') . ' ' . ($r['last_name'] ?? ''));
    if ($name === '') return null;
    return [
        'name'    => $name,
        'address' => trim((string)($r['company_address'] ?? '')),
        'phone'   => trim((string)($r['telephone'] ?? '')),
        'c_first' => trim((string)($r['contact_first_name'] ?? '')),
        'c_mid'   => trim((string)($r['contact_middle_initial'] ?? '')),
        'c_last'  => trim((string)($r['contact_last_name'] ?? '')),
        'position'=> trim((string)($r['position'] ?? '')),
    ];
}

/* Returns ['pref' => [...], 'company' => [...]] when the saved preferred
   placement differs from the company being applied to, otherwise null
   (no preference saved, company data unavailable, or they match). */
function clPlacementMismatch(mysqli $conn, int $user_id, int $company_id): ?array {
    $company = clCompanyPlacementData($conn, $company_id);
    if (!$company) return null;
    try {
        $q = $conn->prepare("SELECT pref_company_name, pref_company_address FROM student_information WHERE user_id = ? LIMIT 1");
        if (!$q) return null;
        $q->bind_param("i", $user_id);
        $q->execute();
        $p = $q->get_result()->fetch_assoc();
        $q->close();
    } catch (\Throwable $e) { return null; }
    if (!$p) return null;
    $prefName = trim((string)($p['pref_company_name'] ?? ''));
    $prefAddr = trim((string)($p['pref_company_address'] ?? ''));
    if ($prefName === '') return null; // nothing saved → nothing to conflict with

    $nameDiffers = clPlacementNorm($prefName) !== clPlacementNorm($company['name']);
    $addrDiffers = ($prefAddr !== '' && $company['address'] !== ''
                    && clPlacementNorm($prefAddr) !== clPlacementNorm($company['address']));
    if (!$nameDiffers && !$addrDiffers) return null;

    return ['pref' => ['name' => $prefName, 'address' => $prefAddr], 'company' => $company];
}

/* Deletes the verified Application SIT copies from the student's upload
   folder. Returns [deleted_count, failed_count]. */
function clRemoveSitUploadFiles(mysqli $conn, int $user_id, string $label): array {
    $deleted = 0; $failed = 0;
    try {
        $uq = $conn->prepare("SELECT first_name, middle_name, last_name FROM users WHERE id = ?");
        $uq->bind_param("i", $user_id);
        $uq->execute();
        $ud = $uq->get_result()->fetch_assoc();
        $uq->close();
    } catch (\Throwable $e) { return [0, 1]; }
    if (!$ud) return [0, 0];

    // Same folder rule as administrator.php (ajax_update_requirement).
    $safeFirst  = preg_replace("/[^a-zA-Z0-9]/", "_", (string)$ud['first_name']);
    $safeMiddle = preg_replace("/[^a-zA-Z0-9]/", "_", (string)$ud['middle_name']);
    $safeLast   = preg_replace("/[^a-zA-Z0-9]/", "_", (string)$ud['last_name']);
    $folder = !empty($safeMiddle) ? $safeFirst . "_" . $safeMiddle . "_" . $safeLast : $safeFirst . "_" . $safeLast;

    $base = realpath(__DIR__ . '/uploads');
    $dir  = realpath(__DIR__ . '/uploads/' . $folder);
    if ($base === false || $dir === false || !is_dir($dir)) return [0, 0];
    if (strpos($dir, $base . DIRECTORY_SEPARATOR) !== 0) return [0, 0]; // never leave uploads/

    // administrator.php names the copy "<safe label>_verified_<time>.jpg"
    // (its label's capitalisation differs between places → case-insensitive).
    $prefix = preg_replace("/[^a-zA-Z0-9]/", "_", $label) . "_verified_";
    $items = @scandir($dir);
    if ($items === false) return [0, 1];
    foreach ($items as $f) {
        if ($f === '.' || $f === '..') continue;
        if (stripos($f, $prefix) !== 0) continue;
        $full = $dir . DIRECTORY_SEPARATOR . $f;
        if (!is_file($full)) continue;
        if (@unlink($full)) $deleted++; else $failed++;
    }
    return [$deleted, $failed];
}

/* Replaces the preferred placement with the company's data and removes
   the Application SIT (DB row + upload-folder copies). Returns
   ['ok' => bool, 'message' => string]. */
function clReplacePlacementAndRemoveSit(mysqli $conn, int $user_id, array $company, string $sitLabel): array {
    $contact = trim($company['c_first'] . ' ' . $company['c_mid'] . ' ' . $company['c_last']);
    try {
        $conn->begin_transaction();

        $up = $conn->prepare("UPDATE student_information
                              SET pref_company_name = ?, pref_company_address = ?, pref_telephone = ?,
                                  pref_contact_person_first = ?, pref_contact_person_middle = ?, pref_contact_person_last = ?,
                                  pref_position = ?
                              WHERE user_id = ?");
        $up->bind_param("sssssssi", $company['name'], $company['address'], $company['phone'],
                        $company['c_first'], $company['c_mid'], $company['c_last'], $company['position'], $user_id);
        $up->execute();
        $up->close();

        $del = $conn->prepare("DELETE FROM requirements WHERE user_id = ? AND requirement_type = 'application_sit'");
        $del->bind_param("i", $user_id);
        $del->execute();
        $del->close();

        // The student is no longer fully verified (same value administrator.php's recompute writes).
        try {
            $vs = $conn->prepare("UPDATE users SET validation_status = 'Pending' WHERE id = ?");
            if ($vs) { $vs->bind_param("i", $user_id); $vs->execute(); $vs->close(); }
        } catch (\Throwable $e) { /* column may not exist yet — not fatal */ }

        $conn->commit();
    } catch (\Throwable $e) {
        try { $conn->rollback(); } catch (\Throwable $e2) {}
        return ['ok' => false, 'message' => 'We could not update your preferred placement. Please try again.'];
    }

    // Files are removed only after the DB change is safely committed.
    [$deleted, $failed] = clRemoveSitUploadFiles($conn, $user_id, $sitLabel);

    $msg = 'Your preferred placement was updated to ' . $company['name'] . ' and your Application SIT was removed. '
         . 'Please submit a new Application SIT for this company from your Requirements page, then apply again.';
    if ($failed > 0) $msg .= ' (Some old SIT files could not be deleted from the server; please inform the administrator.)';
    return ['ok' => true, 'message' => $msg];
}

/* ================= HANDLE APPLY ================= */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['apply'])) {

    if ($already_registered) {
        $apply_blocked = true;
    } elseif (!$all_verified) {
        /* ADJUSTMENT: server-side half of the apply gate. The Apply
           buttons already stop the click client-side and open
           #reqUnverifiedModal, but this guard makes sure a crafted /
           stale POST can never create an application while any of the
           8 requirements is still not Verified. The popup is re-opened
           on page load via $requirements_unverified (see <script>). */
        $requirements_unverified = true;
    } elseif (
        empty($legacy_skill1) ||
        empty($legacy_skill2) ||
        empty($legacy_exp1) ||
        empty($student['student_photo'])
    ) {
        /* ADJUSTMENT: "complete profile" now means at least 2 skill
           entries + 1 experience entry (same minimums as before) +
           a photo, checked against the new student_skills /
           student_experience tables via $legacy_skill1/$legacy_skill2/
           $legacy_exp1 derived above — instead of the old
           $student['skill1'] / ['skill2'] / ['exp1'] columns. */
        $incomplete_profile = true;
    } else {

        $company_id = intval($_POST['company_id']);

        $check = $conn->prepare("SELECT id FROM admin_application_approvals WHERE student_id = ? AND company_id = ?");
        $check->bind_param("ii", $user_id, $company_id);
        $check->execute();
        $exists = $check->get_result();

        /* ADJUSTMENT: preferred placement vs. this company's data. Only
           looked at for a brand-new application (an existing one still
           just reports "already applied"). */
        $placement_mismatch_data = ($exists->num_rows == 0) ? clPlacementMismatch($conn, (int)$user_id, $company_id) : null;

        if ($placement_mismatch_data !== null && empty($_POST['confirm_replace_placement'])) {
            // Mismatch found → ask first (popup #placementMismatchModal); nothing is changed yet.
            $placement_mismatch = $placement_mismatch_data;
            $placement_mismatch['company_id'] = $company_id;
        } elseif ($placement_mismatch_data !== null) {
            // Student confirmed → replace the preference and remove the Application SIT.
            $replaceRes = clReplacePlacementAndRemoveSit($conn, (int)$user_id, $placement_mismatch_data['company'], $reqLabels['application_sit']);
            if ($replaceRes['ok']) {
                $success = $replaceRes['message'];
                // Keep this request's already-computed gate state in sync (SIT is gone).
                unset($studentReqs['application_sit']);
                $all_verified = false;
                $req_gate_statuses['application_sit'] = 'Not Submitted';
            } else {
                $error = $replaceRes['message'];
            }
        } elseif ($exists->num_rows == 0) {

            $student_email = $student['email'];

            $stmt = $conn->prepare("
                INSERT INTO admin_application_approvals 
                (student_id, company_id, skill1, skill2, skill3, exp1, exp2)
                VALUES (?, ?, ?, ?, ?, ?, ?)
                ON DUPLICATE KEY UPDATE 
                    skill1=VALUES(skill1), skill2=VALUES(skill2), skill3=VALUES(skill3),
                    exp1=VALUES(exp1), exp2=VALUES(exp2), submitted_at=CURRENT_TIMESTAMP
            ");

            $stmt->bind_param(
                "iisssss",
                $user_id,
                $company_id,
                $legacy_skill1,
                $legacy_skill2,
                $legacy_skill3,
                $legacy_exp1,
                $legacy_exp2
            );

            $stmt->execute();

            $new_application_id = $stmt->insert_id;

            if ($new_application_id && !empty($studentReqs)) {
                $req_insert = $conn->prepare("
                    INSERT INTO application_requirements
                        (application_id, student_id, company_id, requirement_type, file_name, status, remark)
                    VALUES (?, ?, ?, ?, ?, ?, ?)
                    ON DUPLICATE KEY UPDATE
                        file_name=VALUES(file_name),
                        status=VALUES(status),
                        remark=VALUES(remark)
                ");

                foreach ($studentReqs as $req_type => $req_data) {
                    $req_file   = $req_data['file_name'] ?? null;
                    $req_status = $req_data['status']    ?? 'Not Submitted';
                    $req_remark = $req_data['remark']    ?? null;

                    $req_insert->bind_param(
                        "iiissss",
                        $new_application_id,
                        $user_id,
                        $company_id,
                        $req_type,
                        $req_file,
                        $req_status,
                        $req_remark
                    );
                    $req_insert->execute();
                }

                $req_insert->close();
            }

            $success = "Application submitted! Please wait for admin approval.";
        } else {
            $error = "You already applied to this company.";
        }
    }
}

/* ================= HANDLE CANCEL REQUEST =================
   ADJUSTMENT: lets a student withdraw an application at EITHER stage
   of the two-step review process:

     1) Still a fresh row in admin_application_approvals, awaiting the
        administrator's approval — same as before.
     2) Already approved by the administrator and moved into
        ojt_applications (phase='pending'), i.e. now sitting inside
        the COMPANY's own inbox on add_ojt_student.php, awaiting that
        company's Accept/Reject decision. Previously, once an
        application reached this second stage, a student had no way
        to cancel it themselves — this delete closes that gap.

   Both DELETE statements below always run: whichever table actually
   still holds a row for this student+company is the one that gets
   cleaned up (a given application can only ever be sitting in ONE of
   the two tables at a time, since the admin-approval step moves the
   row rather than copying it), so this single handler transparently
   covers cancellation from either stage without needing to first
   look up which stage it's in. Deleting the admin_application_approvals
   row (stage 1) removes the exact same row administrator.php's
   Application Request inbox reads from, so a stage-1 cancellation
   disappears from the admin's queue automatically the next time that
   page loads or polls. Deleting the ojt_applications row (stage 2)
   likewise removes it from add_ojt_student.php's own inbox — the
   next time that page loads or its own polling runs
   (check_new_applications), the cancelled request is simply gone,
   with no separate change needed on either file.

   The matching requirement snapshot rows for that application (in
   application_requirements, keyed by student_id + company_id
   regardless of stage) are cleaned up too either way, so no orphaned
   snapshot data is left behind for an application that no longer
   exists. This does not touch $already_registered / ojt_assignments
   or any other existing logic on this page — a company that has
   already ACCEPTED the student (a live ojt_assignments row) is a
   different state entirely and is not affected by this handler. */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['cancel_request'])) {
    $cancel_company_id = intval($_POST['company_id']);
    $cancelled = false;

    // Stage 1 — still awaiting admin approval.
    $cancelStmt1 = $conn->prepare("DELETE FROM admin_application_approvals WHERE student_id = ? AND company_id = ?");
    $cancelStmt1->bind_param("ii", $user_id, $cancel_company_id);
    $cancelStmt1->execute();
    if ($cancelStmt1->affected_rows > 0) {
        $cancelled = true;
    }
    $cancelStmt1->close();

    // Stage 2 — admin-approved, now sitting in the company's own
    // inbox (add_ojt_student.php) awaiting that company's decision.
    $cancelStmt2 = $conn->prepare("DELETE FROM ojt_applications WHERE student_id = ? AND company_id = ? AND phase = 'pending'");
    $cancelStmt2->bind_param("ii", $user_id, $cancel_company_id);
    $cancelStmt2->execute();
    if ($cancelStmt2->affected_rows > 0) {
        $cancelled = true;
    }
    $cancelStmt2->close();

    if ($cancelled) {
        $cleanupStmt = $conn->prepare("DELETE FROM application_requirements WHERE student_id = ? AND company_id = ?");
        $cleanupStmt->bind_param("ii", $user_id, $cancel_company_id);
        $cleanupStmt->execute();
        $cleanupStmt->close();

        // NEW (endorsement flow): the letter belongs to the cancelled application.
        $endoCleanup = $conn->prepare("DELETE FROM endorsement_letters WHERE student_id = ? AND company_id = ? AND validation_status <> 'Verified'");
        $endoCleanup->bind_param("ii", $user_id, $cancel_company_id);
        $endoCleanup->execute();
        $endoCleanup->close();

        $success = "Your application request has been cancelled.";
    } else {
        $error = "No pending application request was found for that company.";
    }
}

/* ================= FETCH PENDING APPLICATION(S) =================
   ADJUSTMENT: powers the Apply → Cancel Request button swap in the
   company list below. $pending_applications maps company_id => a
   small ['stage' => ...] array describing exactly where that
   application currently sits in the two-step review process:

     'admin_review'   → still a fresh row in admin_application_approvals,
                         awaiting the administrator's approval.
     'company_review' → the administrator has already approved it and
                         moved it into ojt_applications (phase='pending'),
                         so it now sits in the COMPANY's own inbox
                         (add_ojt_student.php) awaiting Accept/Reject.

   Both tables are checked (a given company_id can only ever have a
   row in one of them at a time, since the admin-approval step moves
   the row rather than copying it), so the student's Apply/Cancel UI
   stays accurate across the whole lifecycle of a single application —
   not just its first stage. $has_pending_application is true if the
   student has ANY such pending application, in either stage (used to
   block applying to a *different* company while one is already
   pending, mirroring the existing $already_registered guard used for
   active OJT placements). This is queried fresh here — after the
   cancel-request handler above runs — so a just-cancelled request is
   reflected immediately, without needing a page reload. */
$pendingStageLabels = [
    'admin_review'   => 'Waiting for Admin Approval',
    'company_review' => 'Waiting for Company to Accept',
    // NEW (endorsement flow)
    'endorsement_upload'   => 'Endorsement letter received — upload the signed letter from your Inbox',
    'endorsement_review'   => 'Endorsement letter uploaded — waiting for company validation',
    'endorsement_rejected' => 'Endorsement letter rejected — see remarks in your Inbox and re-upload',
];

$pending_applications = [];

$pendingAppStmt = $conn->prepare("SELECT company_id FROM admin_application_approvals WHERE student_id = ?");
$pendingAppStmt->bind_param("i", $user_id);
$pendingAppStmt->execute();
$pendingAppResult = $pendingAppStmt->get_result();
while ($pendingRow = $pendingAppResult->fetch_assoc()) {
    $pending_applications[$pendingRow['company_id']] = ['stage' => 'admin_review'];
}
$pendingAppStmt->close();

/* ADJUSTMENT: also check ojt_applications — once the admin approves
   an application, it is moved out of admin_application_approvals and
   into this table (phase='pending') for the company to review (see
   add_ojt_student.php). Without this second check, an application
   that had already cleared admin review would vanish from
   $pending_applications entirely — silently letting the student
   re-apply, and hiding the Cancel Request option — the moment it
   reached the company's inbox. */
$pendingCompanyStmt = $conn->prepare("SELECT company_id FROM ojt_applications WHERE student_id = ? AND phase = 'pending'");
$pendingCompanyStmt->bind_param("i", $user_id);
$pendingCompanyStmt->execute();
$pendingCompanyResult = $pendingCompanyStmt->get_result();
while ($pendingRow = $pendingCompanyResult->fetch_assoc()) {
    $pending_applications[$pendingRow['company_id']] = ['stage' => 'company_review'];
}
$pendingCompanyStmt->close();

/* NEW (endorsement flow): refine the company-review stage using the endorsement letter state. */
$endorsement_letters = fetchStudentEndorsements($conn, $user_id);
$endo_attention_count = endorsementAttentionCount($endorsement_letters);
foreach ($endorsement_letters as $_el) {
    $cid = $_el['company_id'];
    if (isset($pending_applications[$cid]) && $pending_applications[$cid]['stage'] === 'company_review') {
        $pending_applications[$cid]['stage'] = match ($_el['status']) {
            'Pending'  => 'endorsement_review',
            'Rejected' => 'endorsement_rejected',
            'Verified' => 'company_review',
            default    => 'endorsement_upload',
        };
    }
}

$has_pending_application = !empty($pending_applications);

/* ================= FETCH VERIFIED COMPANIES ================= */
/* FIX: added ci.company AS company_name so the display uses the company's
   registered company name (same source of truth as student_profile.php's
   Company Details card) instead of only the contact person's first/last name. */
/* ADJUSTMENT: Company Profile / Brief Description — the same free-text
   description CompanyForm.php stores in company_information.company_profile
   (label "Company Profile / Brief Description"). CompanyForm.php creates
   that column on demand, so it may not exist yet on a fresh database;
   we check for it first (and try to add it exactly the way
   CompanyForm.php does) so this listing never errors out. If it still
   can't be found, NULL is selected in its place and the details pane
   simply shows the "no description yet" placeholder. */
$has_company_profile_col = false;
try {
    $cpColRes = $conn->query("SHOW COLUMNS FROM company_information LIKE 'company_profile'");
    $has_company_profile_col = ($cpColRes && $cpColRes->num_rows > 0);
    if (!$has_company_profile_col) {
        $conn->query("ALTER TABLE company_information ADD COLUMN company_profile TEXT NULL AFTER company_address");
        $cpColRes = $conn->query("SHOW COLUMNS FROM company_information LIKE 'company_profile'");
        $has_company_profile_col = ($cpColRes && $cpColRes->num_rows > 0);
    }
} catch (Throwable $e) {
    $has_company_profile_col = false;
}
$company_profile_select = $has_company_profile_col ? "ci.company_profile" : "NULL";

/* ADJUSTMENT (error handling): mysqli runs in STRICT report mode (see db.php), so a failed query would
   throw and white-screen the whole page. The list query is now guarded: on failure the page still renders
   (sidebar, navbar, inbox, popups) and shows a friendly message in the card instead of the company rows. */
$companies          = null;
$company_load_error = false;
try {
    $companies = $conn->query("
    SELECT 
        u.id, 
        u.first_name, 
        u.last_name,
        ci.company AS company_name,
        {$company_profile_select} AS company_profile,
        ci.contact_first_name,
        ci.contact_middle_initial,
        ci.contact_last_name,
        cp.telephone, 
        cp.google_map_link
    FROM users u
    LEFT JOIN company_information ci ON ci.user_id = u.id
    LEFT JOIN company_profile cp ON cp.user_id = u.id
    WHERE u.role = 'company' 
    AND u.company_validation_status = 'verified'
");
    if (!$companies) $company_load_error = true;
} catch (Throwable $e) {
    $companies          = null;
    $company_load_error = true;
    error_log('company_list.php: company query failed: ' . $e->getMessage());
}
$company_total = ($companies instanceof mysqli_result) ? (int)$companies->num_rows : 0;
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Company List</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Lora:ital,wght@0,400;0,500;0,600;1,400&family=DM+Sans:wght@300;400;500;600&family=DM+Mono:wght@400;500&display=swap" rel="stylesheet">
    <style>
        /* ── Design tokens ── */
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

            /* Legacy aliases */
            --neust-maroon: #07145fe5;
            --neust-gold:   #FFD700;
            --neust-active: #1a237e;
            --bg:           #f0efe9;
            --white:        #ffffff;
            --text:         #1a1a2e;
        }

        * { box-sizing: border-box; margin: 0; padding: 0; }

        body {
            font-family: 'DM Sans', 'Segoe UI', sans-serif;
            background: var(--surface-warm);
            color: var(--ink);
            display: flex;
            min-height: 100vh;
            line-height: 1.6;
        }

        /* ══════════════════════════════════════════
           SIDEBAR — exact match of student_attendance.php
        ══════════════════════════════════════════ */
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
            box-shadow: 4px 0 10px rgba(0,0,0,0.1);
        }
        .sidebar.collapsed { width: 80px; }

        /* Header — name + role label (matches student_attendance.php) */
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

        .sidebar-links {
            flex: 1;
            display: flex;
            flex-direction: column;
            padding: 10px 0;
            overflow: hidden;
        }

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

        .sidebar a:hover:not(.active):not(.nav-locked) { background: rgba(255,255,255,0.07); color: white; }
        .sidebar a.active {
            background: var(--neust-active);
            color: white;
            border-left: 4px solid var(--neust-gold);
        }

        /* ── Locked sidebar links (not deployed) ── */
        .sidebar a.nav-locked {
            cursor: not-allowed;
            opacity: 0.55;
        }
        .sidebar a.nav-locked:hover {
            background: rgba(255,255,255,0.04);
            color: #cbd5e0;
        }
        .nav-lock-icon {
            font-size: 11px;
            color: #fbbf24;
            position: absolute;
            right: 22px;
            top: 50%;
            transform: translateY(-50%);
            opacity: 0.85;
        }
        .sidebar.collapsed .nav-lock-icon { display: none; }

        /* ── Sidebar lock notice (not all requirements verified yet) —
           ported from AccomForm.php ── */
        .sidebar-lock-notice {
            padding: 16px 20px 4px;
        }
        .sidebar-lock-notice-inner {
            display: flex; align-items: flex-start; gap: 10px;
            background: rgba(255,255,255,0.06); border: 1px solid rgba(255,255,255,0.1);
            border-radius: 8px; padding: 10px 12px;
        }
        .sidebar-lock-notice i { font-size: 15px; color: var(--neust-gold); margin-top: 1px; flex-shrink: 0; }
        .sidebar-lock-notice p { font-size: 11px; color: rgba(255,255,255,0.65); line-height: 1.5; margin: 0; }
        .sidebar.collapsed .sidebar-lock-notice { display: none; }

        /* ── Attendance sidebar badge (amber/pulsing) — matches student_attendance.php ── */
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

        /* ── Journal badge — matches student_attendance.php ── */
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
            border: 1px solid var(--neust-gold);
            color: var(--neust-gold);
            border-radius: 6px;
            justify-content: center;
            padding: 10px;
            display: flex;
            align-items: center;
            text-decoration: none;
            font-size: 14px;
            transition: background 0.2s;
        }
        .logout-link a:hover { background: rgba(255,215,0,0.08); }

        .toggle-btn {
            background: transparent;
            border: none;
            color: white;
            cursor: pointer;
            font-size: 20px;
            outline: none;
            flex-shrink: 0;
        }

        /* ══════════════════════════════════════════
           ATTENDANCE NOTIFICATION BAR — v3
           Hidden:  visibility:hidden + opacity:0 + translateY(-120%)
           Visible: .anb-visible restores all three.
           overflow:hidden keeps progress bar inside rounded corners.

           TIMER OVERLAP FIX:
           · overflow:hidden on bar itself (clips progress bar neatly)
           · .anb-content uses flex-wrap:nowrap so countdown never wraps
           · .anb-text-group has min-width:0 + flex-direction:column so it
             shrinks properly instead of pushing the countdown off-screen
           · .anb-countdown has min-width:100px + text-align:center so the
             pill never changes width as the digit count changes (no jitter)
           · .anb-progress has pointer-events:none so it doesn't steal clicks
        ══════════════════════════════ */
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
            overflow: hidden;  /* keeps progress bar inside rounded corners */
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

        /* Content area — never wraps; text group shrinks, countdown stays fixed */
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
            min-width: 0;        /* allows text to shrink & ellipsis */
            flex-shrink: 1;
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
        /* Countdown pill — fixed width prevents layout shift as digits change */
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
            min-width: 100px;      /* prevents width jitter as digit count changes */
            text-align: center;
        }
        .anb-btn {
            background: #EF9F27;
            color: #412402;
            border: none;
            border-radius: 7px;
            padding: 7px 15px;
            font-size: 11px; font-weight: 700;
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

        /* Progress bar — absolutely positioned at bottom of bar */
        .anb-progress {
            position: absolute;
            bottom: 0; left: 0;
            height: 2px;
            background: #EF9F27;
            border-radius: 0 0 0 12px;
            pointer-events: none;  /* never intercepts clicks */
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
            background: var(--maroon);
            padding: 10px 30px;
            display: flex;
            align-items: center;
            color: white;
            height: 60px;
            flex-shrink: 0;
            box-shadow: 0 2px 8px rgba(0,0,0,0.15);
            position: relative;
            z-index: 99;
        }
        .navbar img { height: 40px; margin-right: 14px; }

        /* ══ PAGE CONTENT ══ */
        .page-inner { flex: 1; padding: 30px; max-width: 1000px; width: 100%; margin: 0 auto; }

        .page-inner h2 {
            color: var(--maroon);
            font-size: 20px;
            margin-bottom: 20px;
            padding-bottom: 10px;
            border-bottom: 2px solid var(--gold);
            font-family: 'Lora', serif;
        }

        .msg {
            padding: 12px 16px;
            margin-bottom: 16px;
            border-radius: 8px;
            font-size: 14px;
            font-weight: 600;
        }
        .success { background: #d1fae5; color: #065f46; }
        .error   { background: #fee2e2; color: #991b1b; }

        .company-row {
            background: var(--white);
            border-radius: 12px;
            margin-bottom: 14px;
            box-shadow: var(--shadow-card);
            overflow: hidden;
            border: 1px solid var(--border);
            transition: box-shadow 0.2s;
        }
        .company-row:hover { box-shadow: var(--shadow-lift); }

        .company-summary {
            padding: 18px 22px;
            display: flex;
            justify-content: space-between;
            align-items: center;
            cursor: pointer;
            user-select: none;
        }
        .company-summary .company-name {
            font-weight: 700;
            font-size: 15px;
            color: var(--maroon);
            display: flex;
            align-items: center;
            gap: 8px;
        }
        .company-summary .company-name i { color: var(--gold); font-size: 16px; }
        .company-summary .summary-right {
            display: flex;
            align-items: center;
            gap: 10px;
            font-size: 13px;
            color: #6b7280;
        }
        .company-summary .summary-right .badge-current {
            background: #dcfce7;
            color: #166534;
            border-radius: 20px;
            padding: 3px 12px;
            font-size: 12px;
            font-weight: 700;
        }
        .chevron { font-size: 11px; color: #9ca3af; transition: transform 0.25s; }

        /* ══════════════════════════════════════════════════════════
           ADJUSTMENT: PAGE LOAD / PROCESSING overlay — same as
           administrator.php's #globalLoadingOverlay: visible by default
           (covers the very first paint) and fades out once the page has
           finished loading; also shown while applying, cancelling, and
           refreshing the company panel list.
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
        #globalLoadingOverlay.gl-instant { transition: none; }
        .global-loading-box { display: flex; flex-direction: column; align-items: center; gap: 16px; animation: globalLoadingPop 0.35s ease; }
        .global-loading-spinner { width: 54px; height: 54px; border-radius: 50%; border: 5px solid #A3AFC7; border-top-color: #1B2A4A; animation: globalLoadingSpin 0.85s linear infinite; }
        .global-loading-text { font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif; font-size: 13px; font-weight: 700; color: #1B2A4A; text-transform: uppercase; letter-spacing: 0.6px; display: flex; align-items: center; gap: 8px; }
        .global-loading-dots span { animation: globalLoadingDots 1.2s infinite; opacity: 0; }
        .global-loading-dots span:nth-child(2) { animation-delay: 0.2s; }
        .global-loading-dots span:nth-child(3) { animation-delay: 0.4s; }
        @keyframes globalLoadingSpin { to { transform: rotate(360deg); } }
        @keyframes globalLoadingPop { from { transform: scale(0.9); opacity: 0; } to { transform: scale(1); opacity: 1; } }
        @keyframes globalLoadingDots { 0%, 20% { opacity: 0; } 50% { opacity: 1; } 100% { opacity: 0; } }

        /* ══════════════════════════════════════════════════════════════════
           ADJUSTMENT: POPUP NOTIFICATION — same as administrator.php's
           requirement / application popup (.cv-top-toast): square navy bar
           with a slate frame, green icon and the company name in bold white,
           shown at the TOP of the page, fading out by itself. Several stack
           downward (newest below). pointer-events:none — it never blocks the page.
           ══════════════════════════════════════════════════════════════════ */
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
        .cv-top-toast.is-error i { color: #f87171; } /* ADJUSTMENT: an error gets a red icon, same popup otherwise */

        /* ADJUSTMENT: application status in the company panel — same pill as the inbox used */
        .company-summary .summary-right .app-stage-chip {
            display: inline-flex; align-items: center; gap: 5px;
            border-radius: 20px; padding: 3px 12px; font-size: 12px; font-weight: 700; white-space: nowrap;
        }
        .app-stage-chip.awaiting { background: #fef9c3; color: #854d0e; }
        .app-stage-chip.pending  { background: #dbeafe; color: #1e40af; }
        .pending-request-badge.app-stage-awaiting { background: #fef9c3; color: #854d0e; }
        .pending-request-badge.app-stage-pending  { background: #dbeafe; color: #1e40af; }

        .toggle-input { display: none; }
        .toggle-input:checked ~ .details-pane { display: block; }
        .toggle-input:checked ~ label .chevron { transform: rotate(180deg); }

        .details-pane {
            display: none;
            padding: 20px 22px;
            border-top: 1px solid #f0f0f0;
            background: #fafafa;
        }
        .details-pane p { font-size: 14px; margin-bottom: 8px; }
        .details-pane p b { color: #4a5568; }

        /* ══ ADJUSTMENT: COMPANY PROFILE / BRIEF DESCRIPTION ══
           Shown at the top of each company's details pane. Content comes
           from company_information.company_profile (the same field
           CompanyForm.php labels "Company Profile / Brief Description").
           Scoped to its own classes so no existing .details-pane rule
           changes. */
        .company-profile-box {
            background: #ffffff;
            border: 1px solid var(--border);
            border-radius: var(--radius-md);
            padding: 14px 16px;
            margin: 0 0 14px 0;
        }
        /* ADJUSTMENT (this revision): Supervisor + Contact now live inside
           this same section, above the profile text. The left color block
           line (border-left) was removed; the box keeps its plain 1px border. */
        .company-profile-box .cpb-info p { margin-bottom: 6px; }
        .company-profile-box .cpb-info p:last-child { margin-bottom: 0; }
        .company-profile-box .cpb-divider {
            height: 1px;
            background: var(--border-light);
            margin: 12px 0;
        }
        .company-profile-box .cpb-title {
            display: flex;
            align-items: center;
            gap: 8px;
            font-size: 12px;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.06em;
            color: var(--maroon);
            margin-bottom: 8px;
        }
        .company-profile-box .cpb-text {
            font-size: 14px;
            color: var(--ink-muted);
            line-height: 1.65;
            white-space: normal;
            word-wrap: break-word;
            overflow-wrap: anywhere;
            margin: 0;
        }
        .company-profile-box .cpb-empty {
            font-size: 13px;
            color: var(--ink-faint);
            font-style: italic;
            margin: 0;
        }

        .map iframe {
            width: 100%; height: 240px;
            border: 0; border-radius: 10px;
            margin: 12px 0;
        }

        /* ══════════════════════════════════════════════════════════════
           APPLICATION DOCUMENT — LETTERHEAD / A4 PAGES
           ------------------------------------------------------------
           ADJUSTMENT: the "Your Application — Digital Resume" preview
           and the "OJT Requirements" grid are now rendered as A4-sized
           letterhead "paper" pages, mirroring the design of
           administrator.php's Full View "Application Review" document
           (its .fv-doc-paper / .fv-letterhead / .fv-title-band /
           .fv-form-body / .fv-footer-band classes) — same seal +
           university letterhead header, same serif document font, same
           footer band — instead of the previous plain rounded-card
           layout. Classes are prefixed "dr-" (Digital Resume) here so
           nothing from the old .resume- / .requirements- / .req-doc-
           styling (fully replaced below) collides with anything else
           on this page.

           ADJUSTMENT (this revision): the resume content (photo, Full
           Name/Email/Course, Skills, Experience) and the OJT
           Requirements document table are no longer hard-split into a
           fixed 2-page layout — a student with a lot of skill/
           experience entries could have content cut off past the
           bottom of the physical A4 page. They now live together in a
           single hidden ".dr-raw-source" per company (see markup
           further below), and a JS pagination engine (in the <script>
           block; see "DIGITAL RESUME / DOCUMENTS — DYNAMIC A4
           PAGINATION ENGINE") measures that content against the real
           page height — the exact same header-repeats-on-continuation
           approach weekly_report_form_builder.php already uses for its
           own multi-page reports — and builds however many ".dr-paper"
           pages the content actually needs into ".dr-pages-wrap": one
           page for a short resume, three or four for a long one.
           No PHP data source, requirement/status logic, or existing
           JS behavior (openReqPreview) changed — only how that markup
           is laid out across pages.

           ADJUSTMENT (latest revision): two further fixes —
           1) The pagination engine's height-measurement sandbox was
              letting each block's top/bottom margin "collapse away"
              (a normal CSS behavior for a plain wrapper div), which
              quietly under-counted how tall each block really is.
              That under-count let real content run past the fixed
              1123px page height and get sliced off by .dr-paper's
              overflow:hidden — this is what produced the cut-off /
              garbled-looking header & footer text in the rendered
              pages. Fixed purely in JS (see drMeasureContentHeight /
              drMeasureFullWidthHeight in the <script> block) by
              giving the measurement sandbox its own block formatting
              context so margins are measured in full, plus a larger
              safety buffer.
           2) The "OJT Requirements" (Submitted Documents) section is
              now always rendered on its own single, dedicated page —
              vertically centered — completely independent from
              however many pages the Skills/Experience content needs.
              A student with very little skill/experience content still
              gets a 2-page document (1 resume page + 1 documents
              page); a student with a lot of entries gets more resume
              pages, but the documents page never shifts, grows, or
              splits because of that. New ".dr-doc-page-body" /
              ".dr-doc-page-fallback" rules below support that; no
              existing rule was altered. ══ */
        :root { --dr-rule: #c8cfe8; }

        .dr-pages-wrap {
            display: flex;
            flex-direction: column;
            align-items: center;
            gap: 22px;
            margin: 14px 0 10px;
            padding: 20px 14px;
            background: #d8dde8;
            border-radius: 12px;
        }
        .dr-paper {
            background: #fff;
            width: 794px;
            max-width: 100%;
            height: 1123px;
            border: 1px solid #b0b8cc;
            box-shadow: 0 4px 24px rgba(0,0,0,.14);
            font-family: "Times New Roman","Crimson Pro",Times,serif;
            color: #1a1a1a;
            display: flex;
            flex-direction: column;
            overflow: hidden;
        }
        /* ── Hidden measurement scaffold for the auto-pagination engine ──
           Mirrors weekly_report_form_builder.php's #header-clone /
           #footer-clone / #raw-source-data: these never render visibly.
           The JS engine (drPartitionResume() / drRenderPages(), in the
           <script> block below) clones their contents into an
           off-screen sandbox to measure real, font-aware heights, then
           assembles as many .dr-paper pages as the content actually
           needs per company. Because measurement works off a clone,
           this works correctly even while the company's accordion is
           still collapsed. ── */
        .dr-header-clone,
        .dr-footer-clone,
        .dr-raw-source {
            display: none !important;
            position: fixed !important;
            top: 0 !important; left: -9999px !important;
            width: 0 !important; height: 0 !important;
            overflow: hidden !important;
            pointer-events: none !important;
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
        .dr-title-band {
            background: #f4f5fb;
            border-bottom: 1.5px solid var(--dr-rule);
            padding: 8px 24px 7px;
            text-align: center;
            flex-shrink: 0;
        }
        .dr-title-band h1 {
            font-family: 'DM Sans', sans-serif;
            font-size: 16px; font-weight: 700; color: var(--maroon);
            letter-spacing: .035em; text-transform: uppercase;
        }
        .dr-form-meta { margin-top: 3px; font-family: 'Courier New', monospace; font-size: 7.5px; color: #999; }
        /* ADJUSTMENT: min-height:0 + overflow:hidden fix the real cause of the
           clipped footer on page 1. .dr-paper is a fixed-height (1123px) flex
           column of header/body/footer; by default a flex item's min-height is
           "auto", which means the browser refuses to size .dr-form-body smaller
           than its own content. So the instant the body's real content is even
           slightly taller than the space budgeted for it (font rendering
           rounding, a slightly longer line, etc.), the whole column grows past
           1123px and .dr-paper's own overflow:hidden clips whatever falls off
           the bottom — which is the footer band. Pinning min-height:0 forces
           the body to stay exactly within its flex-allocated space (so the
           footer can never be pushed off-page), and overflow:hidden on the
           body itself means any leftover sliver of content clips silently
           inside the body instead of visually colliding with the footer. The
           JS pagination engine already keeps content within budget, so this
           is a hard safety net, not the primary sizing mechanism. */
        .dr-form-body { padding: 18px 28px 20px; flex: 1; min-height: 0; overflow: hidden; }

        /* ── Submitted Documents dedicated page ──
           Applied only to the always-single, always-last "OJT
           Requirements" page built by drRenderPages(). Vertically
           centers the title + table + note as one visual block inside
           the page instead of leaving it pinned to the top, so the
           page reads as an intentional, self-contained document
           rather than leftover flow from the resume pages. If the
           content is ever tall enough that centering it would risk
           pushing it past the usable page height, drRenderPages()
           swaps in .dr-doc-page-fallback instead (plain top-aligned
           flow, same as every other page) so nothing is ever visually
           clipped. */
        .dr-doc-page-body {
            display: flex;
            flex-direction: column;
            justify-content: center;
        }
        .dr-doc-page-fallback {
            display: flex;
            flex-direction: column;
            justify-content: flex-start;
        }
        .dr-doc-page-inner { width: 100%; }

        .dr-section-title {
            font-size: 13px; font-weight: 700; color: #1a1a1a;
            margin: 16px 0 8px; border-bottom: 1px solid #e5e7eb; padding-bottom: 4px;
        }
        .dr-section-title:first-child { margin-top: 0; }
        .dr-footer-band {
            background: #f0f2f8; border-top: 1.5px solid var(--maroon);
            padding: 5px 24px; display: flex; justify-content: space-between;
            font-family: 'Courier New', monospace; font-size: 7.5px; color: #888;
            letter-spacing: .07em; flex-shrink: 0;
        }

        /* Applicant strip — photo + stacked Full Name/Email/Course, mirroring
           the fv-resume-mirror-info treatment used in administrator.php ── */
        .dr-applicant-strip { display: flex; align-items: center; gap: 16px; margin-bottom: 4px; }
        .dr-avatar {
            width: 70px; height: 70px; border-radius: 50%;
            background: var(--surface-soft);
            display: flex; align-items: center; justify-content: center;
            overflow: hidden; flex-shrink: 0; border: 2.5px solid var(--gold);
            color: var(--ink-faint); font-size: 1.6rem;
        }
        .dr-avatar img { width: 100%; height: 100%; object-fit: cover; display: block; }
        .dr-resume-mirror-info { margin-bottom: 2px; }
        .dr-resume-mirror-info p {
            font-family: 'DM Sans', sans-serif;
            font-size: 12.5px; color: #4b5563;
            margin: 0 0 3px; line-height: 1.5;
        }
        .dr-resume-mirror-info p b { color: #1a1a2e; font-weight: 700; margin-right: 4px; }
        .dr-applicant-badges { display: flex; flex-wrap: wrap; gap: 6px; margin-top: 7px; }
        .dr-badge-pill {
            display: inline-flex; align-items: center; gap: 5px;
            font-family: 'DM Sans', sans-serif; font-size: 11px; font-weight: 700;
            padding: 3px 10px; border-radius: 20px;
        }
        .dr-badge-company { background: #d1fae5; color: #166534; }

        /* Skill / Experience entry boxes — mirrors admin's fv-entry-box
           (numbered "Skill N" / "Experience N" locked-field look) ── */
        .dr-entries-col { display: flex; flex-direction: column; gap: 10px; }
        .dr-entry-item { margin-bottom: 0; }
        .dr-entry-item + .dr-entry-item { margin-top: 10px; }
        .dr-field-label {
            font-family: 'DM Sans', sans-serif; font-size: 10.5px; font-weight: 700;
            color: #6b7280; text-transform: uppercase; letter-spacing: 0.04em;
            margin-bottom: 4px;
        }
        .dr-entry-box {
            font-family: 'DM Sans', sans-serif;
            font-size: 12.5px; color: #374151; line-height: 1.6;
            background: #f4f5fb; border: 1.5px solid var(--dr-rule);
            border-radius: 10px; padding: 10px 14px;
            white-space: pre-wrap; word-break: break-word;
        }
        .dr-empty-note { font-family: 'DM Sans', sans-serif; font-size: 12px; color: #a0aec0; font-style: italic; }

        /* Submitted Documents table, mirrors admin's fv-doc-table ── */
        .dr-doc-table { width: 100%; border-collapse: collapse; border: 1px solid #000; font-size: 12px; margin-top: 4px; }
        .dr-doc-table td { border: 1px solid #000; padding: 7px 10px; vertical-align: middle; font-family: 'DM Sans', sans-serif; }
        .dr-doc-table .dr-doc-th td { font-weight: 700; background: #f4f5fb; text-align: center; }
        .dr-doc-thumb {
            width: 38px; height: 38px; border-radius: 6px; overflow: hidden;
            flex-shrink: 0; border: 1px solid #ddd; display: inline-flex;
        }
        .dr-doc-thumb.clickable { cursor: pointer; }
        .dr-doc-thumb img { width: 100%; height: 100%; object-fit: cover; display: block; }
        .dr-doc-nothumb {
            width: 38px; height: 38px; border-radius: 6px; background: #f3f4f6;
            display: inline-flex; align-items: center; justify-content: center;
        }
        .dr-doc-row-name { display: flex; align-items: center; gap: 10px; }
        .dr-doc-label-text { font-size: 12.5px; }
        .dr-status-chip { font-size: 10px; font-weight: 700; padding: 3px 9px; border-radius: 8px; white-space: nowrap; display: inline-block; }
        .dr-status-chip.verified { background: #dcfce7; color: #166534; }
        .dr-status-chip.denied   { background: #fee2e2; color: #991b1b; }
        .dr-status-chip.pending  { background: #fef9c3; color: #854d0e; }
        .dr-status-chip.none     { background: #f3f4f6; color: #6b7280; }
        .dr-doc-remark-row td { background: #fff7f7; }
        .dr-doc-remark { font-size: 10.5px; color: #991b1b; font-family: 'DM Sans', sans-serif; }
        .dr-req-note {
            margin-top: 14px; font-size: 12px; color: #92400e; background: #fef3c7;
            border-radius: 6px; padding: 8px 12px; line-height: 1.5;
            font-family: 'DM Sans', sans-serif;
        }
        .dr-req-note i { margin-right: 4px; }

        @media (max-width: 840px) {
            .dr-paper { width: 100%; min-height: 0; }
        }

        .btn-apply {
            background: var(--maroon);
            color: white;
            border: none;
            padding: 10px 22px;
            border-radius: 8px;
            cursor: pointer;
            font-size: 14px;
            font-weight: 600;
            font-family: inherit;
            transition: opacity 0.2s;
            margin-top: 8px;
        }
        .btn-apply:hover { opacity: 0.88; }

        .registered-badge {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            background: #d1fae5;
            color: #065f46;
            border-radius: 8px;
            padding: 8px 14px;
            font-size: 14px;
            font-weight: 700;
            margin-top: 8px;
        }

        /* ── ADJUSTMENT: Pending-application badge + Cancel Request button ──
           Shown on a company row when the student already has an
           application (a row in admin_application_approvals) awaiting
           admin review at THAT company. Styled to sit alongside the
           existing .btn-apply / .registered-badge look (amber = "in
           progress", matching the amber tones already used elsewhere on
           this page for the sidebar-lock notice and requirement chips)
           without altering either of those existing rules. */
        .pending-request-badge {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            background: #fef3c7;
            color: #92400e;
            border-radius: 8px;
            padding: 8px 14px;
            font-size: 13px;
            font-weight: 700;
            margin-top: 8px;
            margin-bottom: 8px;
        }
        .btn-cancel-request {
            background: #fff1f1;
            color: #dc2626;
            border: 1.5px solid #fecaca;
            padding: 10px 22px;
            border-radius: 8px;
            cursor: pointer;
            font-size: 14px;
            font-weight: 600;
            font-family: inherit;
            transition: all 0.2s;
            margin-top: 4px;
            display: inline-flex;
            align-items: center;
            gap: 6px;
        }
        .btn-cancel-request:hover { background: #dc2626; color: #fff; }

        /* ══════════════════════════════════════════════════════════════
           ADJUSTMENT (this revision): "mini" Cancel Request button shown
           directly in the company summary row (collapsed / not-expanded
           state) for a company the student has a pending application
           with — so the student can cancel without needing to open
           "View Details" first. The moment the row is expanded, this
           mini button is hidden via the .toggle-input:checked rule
           below, and the original full-size .btn-cancel-request inside
           .details-pane (unchanged) becomes the visible one instead —
           i.e. the control "moves back" to its original place while
           details are open. Both buttons open the SAME confirmation
           popup (#cancelConfirmModal, see markup + JS further down)
           and submit the SAME per-company hidden form, so there is only
           ever one code path that actually cancels a request. ══════ */
        .btn-cancel-request-mini {
            background: #fff1f1;
            color: #dc2626;
            border: 1.5px solid #fecaca;
            padding: 5px 12px;
            border-radius: 20px;
            cursor: pointer;
            font-size: 11.5px;
            font-weight: 700;
            font-family: inherit;
            transition: all 0.2s;
            display: inline-flex;
            align-items: center;
            gap: 5px;
            white-space: nowrap;
        }
        .btn-cancel-request-mini:hover { background: #dc2626; color: #fff; }
        .btn-cancel-request-mini i { font-size: 11px; }

        /* ══════════════════════════════════════════════════════════════
           ADJUSTMENT (this revision): "mini" Apply button — mirrors the
           .btn-cancel-request-mini pattern above, but for Apply. Shown
           directly in the collapsed company summary row for a company
           the student can (or is currently blocked from) applying to,
           so applying doesn't require opening "View Details" first.
           Hidden the moment the row is expanded (see the
           .toggle-input:checked ~ label .btn-apply-mini rule below),
           at which point the original full-size .btn-apply inside
           .details-pane (unchanged) takes over — same "moves back to
           its original place" behavior as the Cancel Request mini
           button. Both the mini and full-size buttons trigger the
           exact same action (direct submit of the per-company
           applyForm_<id>, or opening the same blocking modal), so
           there is only ever one code path that actually applies. ══ */
        .btn-apply-mini {
            background: var(--maroon);
            color: #fff;
            border: 1.5px solid var(--maroon);
            padding: 5px 12px;
            border-radius: 20px;
            cursor: pointer;
            font-size: 11.5px;
            font-weight: 700;
            font-family: inherit;
            transition: all 0.2s;
            display: inline-flex;
            align-items: center;
            gap: 5px;
            white-space: nowrap;
        }
        .btn-apply-mini:hover { opacity: 0.85; }
        .btn-apply-mini i { font-size: 11px; }

        /* ══════════════════════════════════════════════════════════════
           ADJUSTMENT: "View Digital Resume" mini button — sits in the
           company summary row (top-right area, alongside the Apply /
           Cancel Request / status badges) and is intentionally NOT
           covered by the .toggle-input:checked hide rule below, since
           it is fully independent of the accordion / .details-pane —
           it now lives outside "company details" entirely and opens
           the shared #digitalResumeModal instead. It stays visible
           whether the row is collapsed or expanded. ══ */
        .btn-view-resume-mini {
            background: #eef2ff;
            color: #1a237e;
            border: 1.5px solid #c7d2fe;
            padding: 5px 12px;
            border-radius: 20px;
            cursor: pointer;
            font-size: 11.5px;
            font-weight: 700;
            font-family: inherit;
            transition: all 0.2s;
            display: inline-flex;
            align-items: center;
            gap: 5px;
            white-space: nowrap;
        }
        .btn-view-resume-mini:hover { background: #1a237e; color: #fff; border-color: #1a237e; }
        .btn-view-resume-mini i { font-size: 11px; }

        /* Hide the mini cancel / apply buttons once the row is expanded —
           the details pane's own Cancel Request / Apply button (the
           "original place") takes over from there. The View Digital
           Resume mini button is deliberately excluded from this rule
           (see comment above). */
        .toggle-input:checked ~ label .btn-cancel-request-mini,
        .toggle-input:checked ~ label .btn-apply-mini,
        .toggle-input:checked ~ label .app-stage-chip { /* ADJUSTMENT: the details pane shows the status while expanded */
            display: none;
        }

        /* Hidden off-screen store for each company's Digital Resume
           pages/raw-source markup — lives outside .details-pane now
           (see markup below) and is only ever revealed by being moved
           into #digitalResumeModal via JS. */
        .dr-store { display: none; }

        /* ══ POPUP MODAL — shared base ══ */
        .popup-modal {
            display: none;
            position: fixed;
            z-index: 9999;
            left: 0; top: 0;
            width: 100%; height: 100%;
            background: rgba(0,0,0,0.55);
            justify-content: center;
            align-items: center;
            backdrop-filter: blur(4px);
        }
        .popup-content {
            background: white;
            padding: 32px 28px;
            border-radius: 14px;
            text-align: center;
            width: 360px;
            max-width: 92%;
            box-shadow: 0 20px 60px rgba(0,0,0,0.2);
            animation: popIn 0.3s cubic-bezier(0.34,1.56,0.64,1);
        }
        @keyframes popIn {
            from { opacity: 0; transform: scale(0.88); }
            to   { opacity: 1; transform: scale(1); }
        }
        .popup-content h3 { margin-top: 0; margin-bottom: 10px; color: var(--maroon); font-size: 18px; font-family: 'Lora', serif; }
        .popup-content p  { font-size: 14px; color: #6b7280; margin-bottom: 20px; }
        .popup-content button {
            background: var(--maroon);
            color: white;
            padding: 11px 24px;
            border: none;
            border-radius: 8px;
            cursor: pointer;
            font-size: 14px;
            font-weight: 600;
            font-family: inherit;
            transition: opacity 0.2s;
        }
        .popup-content button:hover { opacity: 0.88; }

        /* ══ ALREADY-REGISTERED POPUP ══ */
        #registeredModal .popup-content { border-top: 5px solid #d97706; }
        #registeredModal .popup-content h3 { color: #92400e; }
        #registeredModal .popup-icon { font-size: 42px; margin-bottom: 12px; display: block; }
        #registeredModal .popup-content button { background: #d97706; }

        /* ══ PENDING-APPLICATION-BLOCKS-APPLY POPUP ══
           ADJUSTMENT: shown when the student clicks Apply on a company
           OTHER than the one they already have a pending request with —
           mirrors #registeredModal's styling exactly, just a distinct
           id/message so the copy is accurate to the actual guard. */
        #pendingBlockedModal .popup-content { border-top: 5px solid #d97706; }
        #pendingBlockedModal .popup-content h3 { color: #92400e; }
        #pendingBlockedModal .popup-icon { font-size: 42px; margin-bottom: 12px; display: block; }
        #pendingBlockedModal .popup-content button { background: #d97706; }

        /* ══ CANCEL-REQUEST CONFIRMATION POPUP ══
           ADJUSTMENT (this revision): replaces the old browser-native
           confirm() dialog that used to guard the Cancel Request form
           submission with a proper in-app popup, consistent with every
           other modal on this page (#registeredModal, #pendingBlockedModal,
           etc). Used by BOTH the new summary-row mini Cancel button and
           the original details-pane Cancel Request button — see
           openCancelConfirm() / submitCancelConfirm() in the <script>
           block further down. */
        #cancelConfirmModal .popup-content { border-top: 5px solid #dc2626; }
        #cancelConfirmModal .popup-content h3 { color: #991b1b; }
        #cancelConfirmModal .popup-icon { font-size: 42px; margin-bottom: 12px; display: block; }
        #cancelConfirmModal .ccm-actions { display: flex; gap: 10px; justify-content: center; }
        #cancelConfirmModal .ccm-actions button { flex: 1; }
        #cancelConfirmModal .ccm-btn-keep { background: #e5e7eb !important; color: #374151 !important; }
        #cancelConfirmModal .ccm-btn-confirm { background: #dc2626 !important; }

        /* ══ ADJUSTMENT: PREFERRED-PLACEMENT MISMATCH POPUP ══ */
        #placementMismatchModal .popup-content { border-top: 5px solid #d97706; width: 460px; }
        #placementMismatchModal .popup-content h3 { color: #92400e; }
        #placementMismatchModal .popup-icon { font-size: 42px; margin-bottom: 12px; display: block; }
        #placementMismatchModal .popup-content p { margin-bottom: 14px; }
        #placementMismatchModal .rum-list { list-style: none; padding: 0; margin: 0 0 14px; text-align: left; }
        #placementMismatchModal .rum-list li { padding: 8px 10px; border-bottom: 1px solid #eee; font-size: 14px; }
        #placementMismatchModal .rum-list li:last-child { border-bottom: none; }
        #placementMismatchModal .rum-actions { display: flex; gap: 10px; justify-content: center; }
        #placementMismatchModal .rum-actions button { flex: 1; }
        #placementMismatchModal .rum-btn-close { background: #e5e7eb !important; color: #374151 !important; }
        #placementMismatchModal .rum-btn-go { background: #dc2626 !important; }

        /* ══ ADJUSTMENT: REQUIREMENTS-NOT-VERIFIED POPUP ══
           Shown when the student clicks Apply (mini or full-size) while
           any of the 8 requirements is not yet Verified. Built on the
           same shared .popup-modal / .popup-content base as every other
           modal on this page; only this id's own rules are added. */
        #reqUnverifiedModal .popup-content { border-top: 5px solid #dc2626; width: 440px; }
        #reqUnverifiedModal .popup-content h3 { color: #991b1b; }
        #reqUnverifiedModal .popup-icon { font-size: 42px; margin-bottom: 12px; display: block; }
        #reqUnverifiedModal .popup-content p { margin-bottom: 14px; }
        #reqUnverifiedModal .rum-list {
            list-style: none;
            text-align: left;
            margin: 0 0 18px 0;
            padding: 0;
            max-height: 240px;
            overflow-y: auto;
            border: 1px solid var(--border);
            border-radius: var(--radius-md);
        }
        #reqUnverifiedModal .rum-list li {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 10px;
            padding: 9px 12px;
            font-size: 13px;
            color: var(--ink);
            border-bottom: 1px solid var(--border-light);
        }
        #reqUnverifiedModal .rum-list li:last-child { border-bottom: none; }
        #reqUnverifiedModal .rum-chip {
            flex-shrink: 0;
            font-size: 11px;
            font-weight: 700;
            border-radius: 20px;
            padding: 2px 10px;
            white-space: nowrap;
        }
        #reqUnverifiedModal .rum-chip.st-pending   { background: #fef3c7; color: #92400e; }
        #reqUnverifiedModal .rum-chip.st-denied    { background: #fee2e2; color: #991b1b; }
        #reqUnverifiedModal .rum-chip.st-missing   { background: #e5e7eb; color: #374151; }
        #reqUnverifiedModal .rum-actions { display: flex; gap: 10px; justify-content: center; }
        #reqUnverifiedModal .rum-actions button { flex: 1; }
        #reqUnverifiedModal .rum-btn-close { background: #e5e7eb !important; color: #374151 !important; }
        #reqUnverifiedModal .rum-btn-go { background: #dc2626 !important; }

        /* ══ REQUIREMENT PREVIEW MODAL ══ */
        #reqPreviewModal {
            display: none;
            position: fixed;
            inset: 0;
            background: rgba(0,0,0,0.88);
            z-index: 99999;
            justify-content: center;
            align-items: center;
            backdrop-filter: blur(4px);
        }
        #reqPreviewModal img {
            max-width: 88vw; max-height: 88vh;
            border-radius: 6px;
            box-shadow: 0 8px 40px rgba(0,0,0,0.5);
        }
        #reqPreviewClose {
            position: absolute; top: 20px; right: 36px;
            font-size: 38px; color: white; cursor: pointer;
            line-height: 1; opacity: 0.75; transition: opacity 0.2s;
        }
        #reqPreviewClose:hover { opacity: 1; }

        /* ══ NOT-DEPLOYED MODAL ══ */
        #not-deployed-modal {
            display: none;
            position: fixed;
            inset: 0;
            z-index: 99999;
            background: rgba(7, 20, 95, 0.55);
            backdrop-filter: blur(3px);
            align-items: center;
            justify-content: center;
        }
        #not-deployed-modal.show { display: flex; }
        .ndm-box {
            background: #fff;
            border-radius: 18px;
            padding: 36px 32px 28px;
            max-width: 400px;
            width: calc(100% - 40px);
            box-shadow: 0 20px 60px rgba(7,20,95,0.22), 0 4px 16px rgba(0,0,0,0.10);
            text-align: center;
            animation: ndm-pop 0.32s cubic-bezier(.34,1.56,.64,1) both;
        }
        @keyframes ndm-pop {
            from { opacity: 0; transform: scale(0.88) translateY(18px); }
            to   { opacity: 1; transform: scale(1)    translateY(0); }
        }
        .ndm-icon {
            width: 68px; height: 68px;
            border-radius: 50%;
            background: linear-gradient(135deg, #fef3c7, #fde68a);
            border: 3px solid #f59e0b;
            display: flex; align-items: center; justify-content: center;
            font-size: 30px;
            margin: 0 auto 18px;
        }
        .ndm-title { font-size: 18px; font-weight: 800; color: var(--maroon); margin-bottom: 10px; font-family: 'Lora', serif; }
        .ndm-message { font-size: 13.5px; color: #4b5563; line-height: 1.65; margin-bottom: 22px; }
        .ndm-page-name {
            display: inline-block;
            background: #f0f4f8; border: 1.5px solid #e2e8f0;
            border-radius: 8px; padding: 3px 12px;
            font-weight: 700; color: var(--maroon); font-size: 13px; margin-bottom: 18px;
        }
        .ndm-status-badge {
            display: inline-flex; align-items: center; gap: 6px;
            background: #fff7ed; border: 1.5px solid #fed7aa;
            border-radius: 20px; padding: 5px 14px;
            font-size: 12px; font-weight: 700; color: #c2410c; margin-bottom: 22px;
        }
        .ndm-status-dot { width: 8px; height: 8px; border-radius: 50%; background: #f97316; flex-shrink: 0; }
        .ndm-close-btn {
            background: var(--maroon); color: white; border: none;
            border-radius: 10px; padding: 11px 32px;
            font-size: 14px; font-weight: 700; font-family: 'DM Sans', sans-serif;
            cursor: pointer; transition: opacity 0.2s; width: 100%;
        }
        .ndm-close-btn:hover { opacity: 0.88; }
        .ndm-hint { font-size: 11.5px; color: #9ca3af; margin-top: 12px; }

        /* ══════════════════════════════════════════════════════════════
           ADJUSTMENT: DIGITAL RESUME MODAL — restyled to match the
           full-screen "document viewer" look of administrator.php's
           Full View Application Review modal (#appFullViewOverlay /
           .fv-doc-toolbar / .fv-doc-canvas): a full-bleed dark backdrop,
           a navy toolbar pinned to the top of the scrollable viewport
           with the title on the left and a text "Close" button on the
           right, and a padded gray canvas below it that centers the
           document content — instead of the previous rounded, centered
           floating panel. Only this shell (overlay/toolbar/canvas)
           changed; #drmScroll is still where each company's existing,
           unmodified ".dr-pages-wrap" markup gets moved into on open
           (see openDigitalResumeModal() in the <script> block further
           down) — the multi-page A4 pagination engine itself
           (drRenderPages, drPartitionResume, etc.) is completely
           untouched, only WHERE its output is displayed changed. ══ */
        #digitalResumeModal,
        #digitalResumeModal *,
        #digitalResumeModal *::before,
        #digitalResumeModal *::after {
            box-sizing: border-box;
        }
        .drm-modal {
            background: rgba(0,0,0,0.72);
            padding: 0;
            overflow-y: auto;
            flex-direction: column;
            align-items: stretch;
            justify-content: flex-start;
        }
        .drm-toolbar {
            background: var(--maroon);
            padding: 0.55rem 1.5rem;
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 12px;
            flex-wrap: wrap;
            position: sticky;
            top: 0;
            z-index: 200;
            box-shadow: 0 2px 10px rgba(0,0,0,.35);
        }
        .drm-toolbar-left { display: flex; align-items: center; gap: 10px; min-width: 0; }
        .drm-toolbar-left i { color: var(--gold); flex-shrink: 0; }
        .drm-toolbar-title {
            font-family: 'Lora', serif;
            font-size: 0.85rem;
            font-weight: 700;
            color: #fff;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }
        .drm-toolbar-right { display: flex; align-items: center; gap: 8px; flex-shrink: 0; }
        .drm-tbtn-close {
            background: rgba(255,255,255,0.14);
            color: rgba(255,255,255,0.9);
            border: 1px solid rgba(255,255,255,0.28);
            padding: 7px 16px;
            border-radius: 6px;
            font-size: 12.5px;
            font-weight: 600;
            cursor: pointer;
            transition: background 0.15s;
            display: inline-flex;
            align-items: center;
            gap: 6px;
        }
        .drm-tbtn-close:hover { background: rgba(255,255,255,0.26); color: #fff; }

        .drm-canvas {
            background: #d8dde8;
            padding: 24px 16px 40px;
            min-height: calc(100vh - 54px);
            display: flex;
            flex-direction: column;
            align-items: center;
        }
        .drm-canvas-inner {
            width: 794px;
            max-width: 100%;
            margin: 0 auto;
        }

        /* ══════════════════════════════════════════════════════════════
           NEW (endorsement flow): INBOX BUTTON + DRAWER + LETTER VIEWER
           ══════════════════════════════════════════════════════════════ */
        .navbar-right { margin-left: auto; display: flex; align-items: center; gap: 14px; }
        #endoInboxBtn {
            position: relative; display: inline-flex; align-items: center; justify-content: center;
            width: 42px; height: 42px; border-radius: 50%;
            background: rgba(255,255,255,0.15); color: #fff;
            border: 2px solid rgba(255,255,255,0.3); cursor: pointer; font-size: 18px;
            transition: background 0.2s;
        }
        #endoInboxBtn:hover { background: rgba(255,255,255,0.25); }
        #endoInboxBtn.pulse { animation: endo-pulse 1.4s ease-in-out 3; }
        @keyframes endo-pulse {
            0%,100% { box-shadow: 0 0 0 0 rgba(255,215,0,0.6); }
            50%     { box-shadow: 0 0 0 9px rgba(255,215,0,0); }
        }
        #endoInboxBadge {
            position: absolute; top: -5px; right: -5px;
            background: #dc2626; color: #fff; border-radius: 50%;
            min-width: 18px; height: 18px; font-size: 10px; font-weight: 700;
            display: none; align-items: center; justify-content: center;
            border: 2px solid var(--maroon); padding: 0 3px;
        }

        #endoInboxOverlay {
            display: none; position: fixed; inset: 0; background: rgba(0,0,0,0.45);
            z-index: 9990; justify-content: flex-end; align-items: stretch;
        }
        #endoInboxDrawer {
            background: #fff; width: 480px; max-width: 97vw; display: flex; flex-direction: column;
            box-shadow: -8px 0 32px rgba(0,0,0,0.18); animation: endoSlideIn 0.3s ease;
            font-family: 'DM Sans', sans-serif;
        }
        @keyframes endoSlideIn { from { transform: translateX(100%); } to { transform: translateX(0); } }
        .endo-inbox-head {
            padding: 18px 22px; background: var(--maroon); display: flex; align-items: center;
            justify-content: space-between; flex-shrink: 0;
        }
        .endo-inbox-head h3 { margin: 0; color: var(--gold); font-size: 15px; display: flex; align-items: center; gap: 10px; font-family: 'Lora', serif; }
        .endo-inbox-close { background: none; border: none; color: rgba(255,255,255,0.75); font-size: 22px; cursor: pointer; line-height: 1; }
        .endo-inbox-close:hover { color: #fff; }
        #endoInboxBody { overflow-y: auto; flex: 1; padding: 18px 20px; background: #fafaf8; }
        .endo-empty { text-align: center; color: #a0aec0; padding: 50px 20px; font-size: 13px; }
        .endo-empty i { font-size: 40px; display: block; margin-bottom: 14px; color: #cbd5e0; }

        .endo-card {
            background: #fff; border: 1px solid var(--border); border-radius: 12px;
            padding: 15px 16px; margin-bottom: 13px; position: relative;
        }
        .endo-card.unread { border-color: #c7d2fe; box-shadow: 0 0 0 3px rgba(99,102,241,0.08); }
        .endo-card-top { display: flex; gap: 12px; align-items: flex-start; }
        .endo-card-icon {
            width: 40px; height: 40px; border-radius: 10px; background: #eef2ff; color: var(--maroon);
            display: flex; align-items: center; justify-content: center; font-size: 17px; flex-shrink: 0;
        }
        .endo-card-title { font-weight: 700; font-size: 14px; color: var(--ink); line-height: 1.3; }
        .endo-card-sub { font-size: 11.5px; color: var(--ink-faint); margin-top: 2px; }
        .endo-new-pill { position: absolute; top: 12px; right: 12px; background: #dc2626; color: #fff; font-size: 9.5px; font-weight: 800; letter-spacing: .06em; padding: 2px 7px; border-radius: 10px; }
        .endo-status {
            display: inline-flex; align-items: center; gap: 5px; margin-top: 10px;
            font-size: 11px; font-weight: 700; padding: 4px 10px; border-radius: 20px;
        }
        .endo-status.awaiting { background: #fef9c3; color: #854d0e; }
        .endo-status.pending  { background: #dbeafe; color: #1e40af; }
        .endo-status.verified { background: #dcfce7; color: #166534; }
        .endo-status.rejected { background: #fee2e2; color: #991b1b; }
        .endo-help { font-size: 12px; color: var(--ink-muted); margin-top: 8px; line-height: 1.5; }

        /* ══════════════════════════════════════════════════════════════
           ADJUSTMENT: letter cards use the administrator's requirement
           display (administrator.php .cv-gallery / .cv-req-card): square
           card, file preview on top (thumbnail or "No file yet"), rejected
           placeholder + red Remark box, square full-width controls.
           ══════════════════════════════════════════════════════════════ */
        .cv-gallery { display: grid; grid-template-columns: repeat(auto-fit, minmax(190px, 1fr)); gap: 14px; }
        .cv-gallery .req-item { display: flex; flex-direction: column; align-items: stretch; gap: 0; padding: 0; margin-bottom: 0; border: 1px solid #A3AFC7; border-radius: 0; overflow: hidden; background: #ffffff; box-shadow: 0 1px 3px rgba(27,42,74,0.16); text-align: left; position: relative; }
        .cv-gallery .req-item.unread { border-color: #1B2A4A; box-shadow: 0 0 0 2px rgba(27,42,74,0.12); }
        .cv-card-preview { position: relative; flex: 1 0 132px; min-height: 132px; background: #E4EAF4; display: flex; align-items: center; justify-content: center; }
        .cv-gallery .cv-card-preview img.cv-thumb-img { position: absolute; top: 0; left: 0; width: 100%; height: 100%; margin: 0; border: none; border-radius: 0; object-fit: cover; object-position: top center; display: block; cursor: pointer; }
        .cv-no-file { position: absolute; top: 14px; right: 14px; bottom: 14px; left: 14px; border: 1px dashed #A3AFC7; border-radius: 0; display: flex; flex-direction: column; align-items: center; justify-content: center; gap: 6px; color: #3E4963; font-size: 12px; font-weight: 600; text-decoration: none; }
        .cv-no-file i { font-size: 20px; }
        a.cv-no-file:hover { background: #d7dfec; }
        .cv-rej-placeholder { display: none; position: absolute; top: 14px; right: 14px; bottom: 14px; left: 14px; box-sizing: border-box; border: 1px dashed #D49A94; border-radius: 0; background: #F2D5D1; flex-direction: column; align-items: center; justify-content: center; gap: 8px; color: #A02A2A; font-size: 12px; font-weight: 600; text-align: center; line-height: 1.4; }
        .cv-rej-placeholder i { font-size: 24px; }
        .cv-req-card[data-rejected="1"] .cv-rej-placeholder { display: flex; }
        .cv-req-card[data-rejected="1"] .cv-card-preview > :not(.cv-rej-placeholder):not(.endo-new-pill) { display: none !important; }
        .cv-card-body { padding: 12px; display: flex; flex-direction: column; gap: 8px; flex: 0 0 auto; }
        .cv-card-label { font-size: 13.5px; font-weight: 700; color: #1B2A4A; line-height: 1.3; }
        .cv-card-body .endo-card-sub { margin-top: -4px; }
        .cv-card-body .endo-help { margin-top: 0; }
        .endo-batch-note { font-size: 11.5px; color: #1e40af; background: #eff6ff; border: 1px solid #bfdbfe; padding: 6px 9px; line-height: 1.4; } /* ADJUSTMENT: shared batch letter */
        .endo-batch-note i { margin-right: 4px; }
        .cv-card-remark { display: none; align-items: flex-start; gap: 7px; padding: 7px 10px; background: #F2D5D1; border: 1px solid #D49A94; border-radius: 0; color: #A02A2A; font-size: 12px; line-height: 1.4; overflow-wrap: anywhere; }
        .cv-card-remark i { margin-top: 2px; flex-shrink: 0; }
        .cv-card-remark > span { flex: 1; min-width: 0; max-height: 5.6em; overflow-y: auto; padding-right: 4px; }
        .cv-card-remark b { font-weight: 700; }
        .cv-req-card[data-rejected="1"] .cv-card-remark { display: flex; }
        .cv-card-body .endo-upload-line { margin-top: 0; }
        .cv-card-body .endo-card-actions { flex-direction: column; gap: 8px; margin-top: 2px; }
        .cv-card-body .endo-act { width: 100%; height: 38px; box-sizing: border-box; justify-content: center; border-radius: 0; font-size: 13px; }
        .cv-card-body .endo-act.primary { background: #1B2A4A; }
        .cv-card-body .endo-act.primary:hover { background: #2a3d66; }
        .cv-card-body .endo-upload-hint { margin-top: -2px; }
        .cv-card-preview .endo-new-pill { top: 10px; left: 10px; right: auto; border-radius: 0; z-index: 6; }

        /* ADJUSTMENT: "view" eye icon button on the top-right of the uploaded file */
        .cv-view-btn {
            position: absolute; top: 10px; right: 10px; z-index: 7;
            width: 32px; height: 32px; padding: 0; border-radius: 0;
            display: inline-flex; align-items: center; justify-content: center;
            background: #ffffff; color: #1B2A4A; border: 1px solid #A3AFC7;
            box-shadow: 0 1px 3px rgba(27,42,74,0.25); cursor: pointer; font-size: 13px;
        }
        .cv-view-btn:hover { background: #1B2A4A; color: #ffffff; border-color: #1B2A4A; }
        .cv-gallery .cv-req-card[data-rejected="1"] .cv-card-preview > .cv-view-btn.cv-view-btn { display: inline-flex !important; } /* still view the rejected upload (beats the hide rule's specificity) */
        .cv-card-preview .cv-thumb-img, .cv-card-preview .cv-no-file.is-file { cursor: zoom-in; }
        button.cv-no-file { background: transparent; font-family: inherit; cursor: zoom-in; }
        button.cv-no-file:hover { background: #d7dfec; }

        /* ADJUSTMENT: in-page full-screen viewer body */
        #endoFileModal { z-index: 10000; }
        .endo-file-canvas { justify-content: flex-start; }
        .endo-file-canvas img { display: block; max-width: 100%; height: auto; margin: 0 auto; background: #ffffff; box-shadow: 0 4px 24px rgba(0,0,0,0.25); }
        .endo-file-canvas iframe { display: block; width: 100%; max-width: 960px; height: calc(100vh - 118px); border: none; background: #ffffff; box-shadow: 0 4px 24px rgba(0,0,0,0.25); }
        .endo-remark-box { margin-top: 9px; background: #fef2f2; border: 1px solid #fecaca; border-radius: 8px; padding: 9px 11px; font-size: 12.5px; color: #7f1d1d; line-height: 1.5; }
        .endo-remark-box b { color: #991b1b; display: block; margin-bottom: 2px; font-size: 11px; text-transform: uppercase; letter-spacing: .04em; }
        .endo-upload-line { margin-top: 8px; font-size: 11.5px; color: var(--ink-muted); display: flex; align-items: center; gap: 6px; flex-wrap: wrap; }
        .endo-upload-line a { color: #1d4ed8; font-weight: 600; text-decoration: none; }
        .endo-upload-line a:hover { text-decoration: underline; }
        .endo-card-actions { display: flex; gap: 8px; margin-top: 12px; flex-wrap: wrap; }
        .endo-act {
            border-radius: 8px; font-size: 12px; font-weight: 700; padding: 8px 14px; cursor: pointer;
            display: inline-flex; align-items: center; gap: 6px; border: 1px solid transparent;
            font-family: 'DM Sans', sans-serif; transition: all 0.2s;
        }
        .endo-act:disabled { opacity: 0.5; cursor: not-allowed; }
        .endo-act.primary { background: var(--maroon); color: #fff; }
        .endo-act.primary:hover { background: var(--active-nav); }
        .endo-act.upload { background: #16a34a; color: #fff; }
        .endo-act.upload:hover:not(:disabled) { opacity: 0.88; }
        .endo-act.ghost { background: #fff; color: var(--ink-muted); border-color: var(--border); }
        .endo-act.ghost:hover { background: var(--surface-soft); }
        .endo-upload-hint { font-size: 10.5px; color: var(--ink-faint); margin-top: 6px; }

        /* Letter viewer — reuses the Digital Resume modal shell (.drm-modal / .drm-toolbar / .drm-canvas) */
        #endoLetterModal { z-index: 10000; }
        #endoLetterModal .drm-canvas-inner { overflow-x: auto; }
        #endoLetterFrame { width: 794px; min-width: 794px; height: 1200px; border: none; display: block; background: #d8dde8; }
        .drm-tbtn-close.endo-pdf { background: #b8860b; border-color: #b8860b; color: #fff; }
        .drm-tbtn-close.endo-pdf:hover { background: #9a7009; }
        .drm-tbtn-close:disabled { opacity: .6; cursor: wait; }

        #endoToast {
            position: fixed; bottom: 30px; left: 50%; transform: translateX(-50%) translateY(90px);
            background: #1e293b; color: #fff; padding: 13px 20px; border-radius: 12px;
            font-family: 'DM Sans', sans-serif; font-size: 13.5px; font-weight: 600;
            display: flex; align-items: center; gap: 10px; z-index: 10050; opacity: 0; max-width: 92vw;
            transition: transform .35s cubic-bezier(0.34,1.56,0.64,1), opacity .3s;
            box-shadow: 0 8px 30px rgba(0,0,0,0.25); border-left: 4px solid #16a34a;
        }
        #endoToast.show { transform: translateX(-50%) translateY(0); opacity: 1; }
        #endoToast.error { border-left-color: #dc2626; }
        #endoToast button { background: rgba(255,255,255,0.15); border: 1px solid rgba(255,255,255,0.3); color: #fff; border-radius: 6px; padding: 4px 10px; font-size: 12px; font-weight: 700; cursor: pointer; margin-left: 4px; }
        /* ══════════════════════════════════════════════════════════════════
           ADJUSTMENT (design): this page now uses the same "Field Ops Grid"
           design as AccomForm.php / admin_student_list.php — slate-blue page
           background, a bordered white card, square corners, thin slate
           borders instead of soft shadows, navy (#1B2A4A) as the action
           colour, small uppercase labels and flat status colours.
           ONLY the look changed: every class name, id, form, handler and
           open/close state that the markup and scripts rely on is kept as it
           was (this block simply layers over the earlier rules, exactly like
           the design-adjustment pass on AccomForm.php).
           ══════════════════════════════════════════════════════════════════ */
        :root {
            --primary:          #1B2A4A;
            --grid-bg:          #EEF1F6;
            --grid-navy:        #1B2A4A;
            --grid-border:      #C3CADA;
            --grid-border-soft: #DCE1EC;
            --grid-green:       #2C5A2C;
            --grid-green-bg:    #EAF3EA;
            --grid-red:         #A02A2A;
            --grid-red-bg:      #F7E9E9;
            --grid-amber:       #A0850A;
            --grid-amber-bg:    #FAF3DC;
            --grid-muted:       #5A6272;
            --text:             #2d3748;
        }
        body {
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            background: var(--grid-bg);
            color: var(--text);
            line-height: normal;
        }

        /* Sidebar + navbar — identical to AccomForm.php */
        .sidebar-header { padding: 20px; }
        .sidebar-user-info { min-width: 0; }
        .sidebar-user-name { font-size: 18px; font-weight: bold; font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif; }
        .sidebar-user-role { font-size: 11px; font-weight: 700; letter-spacing: 0.8px; margin-top: 3px; overflow: hidden; text-overflow: ellipsis; }
        .sidebar-lock-notice-inner { border-radius: 0; }
        .navbar { background: var(--neust-maroon); box-shadow: none; }

        /* Card + page heading */
        .main-wrapper { flex: 1; padding: 30px; background: var(--grid-bg); }
        .page-inner.card {
            flex: none;
            background: var(--white); width: 100%; max-width: 1100px;
            padding: 32px; margin: 0 auto;
            border-radius: 0; border: 1px solid var(--grid-border); box-shadow: none;
        }
        .page-inner h2 {
            margin: 0; padding: 0; border: none; font-family: inherit;
            color: var(--grid-navy); font-size: 20px;
            text-transform: uppercase; letter-spacing: 0.6px;
        }
        .cl-head {
            display: flex; align-items: center; justify-content: space-between; gap: 12px; flex-wrap: wrap;
            border-bottom: 1px solid var(--grid-border); padding-bottom: 10px; margin-bottom: 14px;
        }
        .cl-count {
            display: inline-flex; align-items: center; background: #fff; border: 1px solid var(--grid-border);
            padding: 5px 12px; font-size: 11px; font-weight: 700; color: var(--grid-navy);
            text-transform: uppercase; letter-spacing: 0.4px; white-space: nowrap;
        }
        .cl-sub { font-size: 13px; color: var(--grid-muted); margin: 0 0 20px; }

        /* Empty / error state */
        .cl-state {
            display: flex; flex-direction: column; align-items: center; text-align: center; gap: 8px;
            padding: 44px 20px; border: 1px dashed var(--grid-border); background: #F7F8FB; color: var(--grid-muted);
        }
        .cl-state i { font-size: 30px; color: #A3AFC7; }
        .cl-state strong { font-size: 13px; color: var(--grid-navy); text-transform: uppercase; letter-spacing: 0.4px; }
        .cl-state span { font-size: 13px; line-height: 1.5; max-width: 420px; }
        .cl-state-error { border-color: #D49A94; background: var(--grid-red-bg); }
        .cl-state-error i, .cl-state-error strong { color: var(--grid-red); }

        /* Company rows — flat panels with a slate border */
        .company-row { border-radius: 0; border: 1px solid var(--grid-border); box-shadow: none; margin-bottom: 12px; background: #fff; }
        .company-row:hover { box-shadow: none; border-color: var(--grid-navy); }
        .company-summary { padding: 12px 16px; transition: background 0.2s; }
        .company-summary:hover { background: #F3F4F7; }
        .toggle-input:checked ~ .company-summary { background: var(--grid-bg); border-bottom: 1px solid var(--grid-border); }
        .company-summary .company-name {
            gap: 12px; font-size: 13.5px; color: var(--grid-navy);
            text-transform: uppercase; letter-spacing: 0.4px; min-width: 0;
        }
        .company-summary .company-name i {
            width: 30px; height: 30px; flex-shrink: 0; border-radius: 0;
            background: var(--grid-navy); color: #fff; font-size: 14px;
            display: inline-flex; align-items: center; justify-content: center;
        }
        .company-summary .summary-right { color: var(--grid-muted); }
        .chevron { color: var(--grid-navy); }

        /* Status chips — flat, square */
        .company-summary .summary-right .badge-current,
        .company-summary .summary-right .app-stage-chip,
        .pending-request-badge,
        .registered-badge {
            border-radius: 0; border: 1px solid transparent;
            font-size: 11px; font-weight: 700; text-transform: uppercase; letter-spacing: 0.4px;
        }
        .company-summary .summary-right .badge-current { background: var(--grid-green-bg); color: var(--grid-green); border-color: #BFD8BF; padding: 4px 10px; }
        .company-summary .summary-right .app-stage-chip { padding: 4px 10px; }
        .app-stage-chip.awaiting, .pending-request-badge.app-stage-awaiting { background: var(--grid-amber-bg); color: var(--grid-amber); border-color: #E3D49A; }
        .app-stage-chip.pending,  .pending-request-badge.app-stage-pending  { background: #E4EAF4; color: var(--grid-navy); border-color: #A3AFC7; }
        .pending-request-badge { background: var(--grid-amber-bg); color: var(--grid-amber); border-color: #E3D49A; padding: 8px 14px; margin-top: 4px; }
        .registered-badge { background: var(--grid-green-bg); color: var(--grid-green); border-color: #BFD8BF; padding: 8px 14px; }

        /* Details pane */
        .details-pane { background: #F7F8FB; border-top: none; padding: 20px; }
        .details-pane p { color: var(--text); }
        .details-pane p b { color: var(--grid-navy); }
        .company-profile-box { background: #fff; border: 1px solid var(--grid-border); border-radius: 0; }
        .company-profile-box .cpb-info p b {
            font-size: 11px; text-transform: uppercase; letter-spacing: 0.5px; margin-right: 6px;
        }
        .company-profile-box .cpb-divider { background: var(--grid-border-soft); }
        .company-profile-box .cpb-title { color: var(--grid-navy); letter-spacing: 0.4px; }
        .company-profile-box .cpb-text { color: var(--text); }
        .company-profile-box .cpb-empty { color: var(--grid-muted); }
        .map iframe { border: 1px solid var(--grid-border); border-radius: 0; }

        /* Buttons — square, navy = primary, red = cancel */
        .btn-apply, .btn-apply-mini, .btn-cancel-request, .btn-cancel-request-mini {
            border-radius: 0; font-family: inherit; text-transform: uppercase; letter-spacing: 0.4px; font-weight: 600;
        }
        .btn-apply { background: var(--grid-navy); font-size: 12px; padding: 11px 22px; }
        .btn-apply-mini { background: var(--grid-navy); border: 1px solid var(--grid-navy); font-size: 11px; padding: 6px 12px; }
        .btn-cancel-request, .btn-cancel-request-mini {
            background: var(--grid-red-bg); color: var(--grid-red); border: 1px solid #D49A94;
        }
        .btn-cancel-request { font-size: 12px; padding: 10px 22px; }
        .btn-cancel-request-mini { font-size: 11px; padding: 6px 12px; }
        .btn-cancel-request:hover, .btn-cancel-request-mini:hover { background: var(--grid-red); border-color: var(--grid-red); color: #fff; }
        .btn-apply:focus-visible, .btn-apply-mini:focus-visible,
        .btn-cancel-request:focus-visible, .btn-cancel-request-mini:focus-visible,
        .company-summary:focus-visible { outline: 2px solid var(--grid-navy); outline-offset: 2px; }

        /* Popups (already-registered / pending / cancel / requirements / not-deployed) — same square modal as AccomForm.php */
        .popup-modal { background: rgba(0,0,0,0.5); backdrop-filter: none; }
        .popup-content {
            border-radius: 0; border: 1px solid var(--grid-border); box-shadow: none;
            padding: 32px; width: 420px; animation: popIn 0.3s ease;
        }
        .popup-content h3 {
            font-family: inherit; font-size: 18px; font-weight: 700; color: #1e293b;
            text-transform: uppercase; letter-spacing: 0.3px;
        }
        .popup-content p { color: var(--grid-muted); line-height: 1.6; }
        .popup-content button {
            background: var(--grid-navy); border: 1px solid var(--grid-navy); border-radius: 0;
            font-size: 12px; text-transform: uppercase; letter-spacing: 0.4px; padding: 10px 28px;
        }
        #registeredModal .popup-content, #pendingBlockedModal .popup-content,
        #cancelConfirmModal .popup-content, #reqUnverifiedModal .popup-content,
        #placementMismatchModal .popup-content { border-top: 1px solid var(--grid-border); }
        #registeredModal .popup-icon, #pendingBlockedModal .popup-icon,
        #placementMismatchModal .popup-icon { color: var(--grid-amber); }
        #cancelConfirmModal .popup-icon, #reqUnverifiedModal .popup-icon { color: var(--grid-red); }
        #registeredModal .popup-content h3, #pendingBlockedModal .popup-content h3,
        #placementMismatchModal .popup-content h3 { color: #1e293b; }
        #cancelConfirmModal .popup-content h3, #reqUnverifiedModal .popup-content h3 { color: var(--grid-red); }
        #registeredModal .popup-content button, #pendingBlockedModal .popup-content button { background: var(--grid-navy); }
        #cancelConfirmModal .ccm-btn-keep, #reqUnverifiedModal .rum-btn-close, #placementMismatchModal .rum-btn-close {
            background: #fff !important; color: var(--grid-navy) !important; border: 1px solid var(--grid-border) !important;
        }
        #cancelConfirmModal .ccm-btn-keep:hover, #reqUnverifiedModal .rum-btn-close:hover, #placementMismatchModal .rum-btn-close:hover { background: #F3F4F7 !important; opacity: 1; }
        #cancelConfirmModal .ccm-btn-confirm { background: var(--grid-red) !important; border-color: var(--grid-red) !important; }
        #reqUnverifiedModal .rum-btn-go, #placementMismatchModal .rum-btn-go { background: var(--grid-navy) !important; border-color: var(--grid-navy) !important; }
        #reqUnverifiedModal .rum-list { border: 1px solid var(--grid-border); border-radius: 0; }
        #reqUnverifiedModal .rum-list li { border-bottom: 1px solid var(--grid-border-soft); color: var(--text); }
        #reqUnverifiedModal .rum-chip {
            border-radius: 0; font-size: 10.5px; text-transform: uppercase; letter-spacing: 0.4px; border: 1px solid transparent;
        }
        #reqUnverifiedModal .rum-chip.st-pending { background: var(--grid-amber-bg); color: var(--grid-amber); border-color: #E3D49A; }
        #reqUnverifiedModal .rum-chip.st-denied  { background: var(--grid-red-bg); color: var(--grid-red); border-color: #D49A94; }
        #reqUnverifiedModal .rum-chip.st-missing { background: #F3F4F7; color: var(--grid-muted); border-color: var(--grid-border); }
        #placementMismatchModal .rum-list { border-radius: 0; }
        #placementMismatchModal .rum-list li { border-bottom: 1px solid var(--grid-border-soft); }
        #reqPreviewModal { backdrop-filter: none; }

        #not-deployed-modal { background: rgba(0,0,0,0.5); backdrop-filter: none; }
        .ndm-box { border-radius: 0; border: 1px solid var(--grid-border); box-shadow: none; padding: 32px; max-width: 420px; animation: ndm-pop 0.3s ease both; }
        .ndm-icon { border-radius: 0; background: var(--grid-amber-bg); border: 1px solid #E3D49A; color: var(--grid-amber); width: 64px; height: 64px; }
        .ndm-title { font-family: inherit; font-size: 18px; font-weight: 700; color: #1e293b; text-transform: uppercase; letter-spacing: 0.3px; }
        .ndm-message { color: var(--grid-muted); }
        .ndm-page-name { border-radius: 0; background: var(--grid-bg); border: 1px solid var(--grid-border); color: var(--grid-navy); }
        .ndm-status-badge { border-radius: 0; background: var(--grid-amber-bg); border: 1px solid #E3D49A; color: var(--grid-amber); text-transform: uppercase; letter-spacing: 0.4px; font-size: 11px; }
        .ndm-status-dot { background: var(--grid-amber); border-radius: 0; }
        .ndm-close-btn { background: var(--grid-navy); border-radius: 0; text-transform: uppercase; letter-spacing: 0.4px; font-size: 12px; font-family: inherit; }
        .ndm-hint { color: var(--grid-muted); }

        /* Inbox (endorsement letters) — button, drawer and cards */
        #endoInboxBtn { border-radius: 0; width: 40px; height: 40px; background: rgba(255,255,255,0.08); border: 1px solid rgba(255,255,255,0.3); }
        #endoInboxBtn:hover { background: rgba(255,255,255,0.18); }
        #endoInboxBadge { border-radius: 0; }
        #endoInboxDrawer { box-shadow: none; border-left: 1px solid var(--grid-border); font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif; }
        .endo-inbox-head { background: var(--grid-navy); }
        .endo-inbox-head h3 { font-family: inherit; text-transform: uppercase; letter-spacing: 0.4px; }
        #endoInboxBody { background: var(--grid-bg); }
        .endo-card { border-radius: 0; border: 1px solid var(--grid-border); }
        .endo-card.unread { border-color: var(--grid-navy); box-shadow: none; }
        .endo-card-icon { border-radius: 0; background: #E4EAF4; color: var(--grid-navy); }
        .endo-card-title { color: var(--grid-navy); }
        .endo-new-pill { border-radius: 0; }
        .endo-status { border-radius: 0; text-transform: uppercase; letter-spacing: 0.4px; font-size: 10.5px; border: 1px solid transparent; }
        .endo-status.awaiting { background: var(--grid-amber-bg); color: var(--grid-amber); border-color: #E3D49A; }
        .endo-status.pending  { background: #E4EAF4; color: var(--grid-navy); border-color: #A3AFC7; }
        .endo-status.verified { background: var(--grid-green-bg); color: var(--grid-green); border-color: #BFD8BF; }
        .endo-status.rejected { background: var(--grid-red-bg); color: var(--grid-red); border-color: #D49A94; }
        .endo-act { border-radius: 0; }
        .endo-act.primary { background: var(--grid-navy); }
        .endo-act.upload { background: var(--grid-green); }
        .endo-act.ghost { border-color: var(--grid-border); color: var(--grid-navy); }
        .endo-remark-box { border-radius: 0; background: var(--grid-red-bg); border-color: #D49A94; color: var(--grid-red); }
        .endo-remark-box b { color: var(--grid-red); }
        .endo-batch-note { border-radius: 0; }
        .endo-empty { color: var(--grid-muted); }
        .endo-empty i { color: #A3AFC7; }
        #endoToast { border-radius: 0; box-shadow: none; border: 1px solid #55668C; border-left-width: 4px; background: var(--grid-navy); font-family: inherit; }
        #endoToast button { border-radius: 0; }

        @media (max-width: 768px) {
            .main-wrapper { padding: 16px; }
            .page-inner.card { padding: 20px 16px; }
            .company-summary { flex-wrap: wrap; gap: 8px; }
            .company-summary .company-name { font-size: 12.5px; }
            .popup-content { padding: 24px 20px; }
        }
        @media (prefers-reduced-motion: reduce) {
            .popup-content, .ndm-box { animation: none; }
        }
    </style>
</head>
<body>
<!-- ══════════════════════════════════════════════════════════
     ADJUSTMENT: page-load / processing overlay — same markup and
     behaviour as administrator.php's #globalLoadingOverlay. Visible
     by default so it covers the page while it is still loading.
     ══════════════════════════════════════════════════════════ -->
<div id="globalLoadingOverlay">
    <div class="global-loading-box">
        <div class="global-loading-spinner"></div>
        <div class="global-loading-text">
            <span id="globalLoadingLabel">Loading</span>
            <span class="global-loading-dots"><span>.</span><span>.</span><span>.</span></span>
        </div>
    </div>
</div>
<script>
    /* Ported from administrator.php (same counter pattern, same timings):
       showGlobalLoading()/hideGlobalLoading() for in-page work, the first
       page load as its own token, and an instant overlay on reload / leave. */
    var globalLoadingActiveCount = 1;          // 1 = the initial page-load token
    var globalLoadingOverlay = document.getElementById('globalLoadingOverlay');
    var globalLoadingLabel   = document.getElementById('globalLoadingLabel');
    var GLOBAL_LOADING_MIN_MS       = 350;
    var GLOBAL_LOADING_SAFETY_MS    = 4000;
    var GLOBAL_LOADING_NAV_STUCK_MS = 15000;
    var globalLoadingStartedAt   = (window.performance && performance.now) ? performance.now() : 0;
    var globalLoadingInitialDone = false;
    var globalLoadingNavigating  = false;
    var globalLoadingNavTimer    = null;

    function globalLoadingPaint() {
        if (!globalLoadingOverlay) return;
        if (globalLoadingActiveCount > 0 || globalLoadingNavigating) {
            globalLoadingOverlay.classList.remove('hidden');
        } else {
            globalLoadingOverlay.classList.remove('gl-instant');
            globalLoadingOverlay.classList.add('hidden');
        }
    }
    function showGlobalLoading(label) {
        globalLoadingActiveCount++;
        if (globalLoadingLabel) globalLoadingLabel.textContent = label || 'Loading';
        globalLoadingPaint();
    }
    function hideGlobalLoading() {
        globalLoadingActiveCount = Math.max(0, globalLoadingActiveCount - 1);
        if (globalLoadingLabel && globalLoadingActiveCount === 0 && !globalLoadingNavigating) globalLoadingLabel.textContent = 'Loading';
        globalLoadingPaint();
    }
    function finishInitialGlobalLoading() {
        if (globalLoadingInitialDone) return;
        var now = (window.performance && performance.now) ? performance.now() : GLOBAL_LOADING_MIN_MS;
        var wait = Math.max(0, GLOBAL_LOADING_MIN_MS - (now - globalLoadingStartedAt));
        globalLoadingInitialDone = true;
        setTimeout(function () {
            globalLoadingActiveCount = Math.max(0, globalLoadingActiveCount - 1);
            if (globalLoadingLabel && globalLoadingActiveCount === 0) globalLoadingLabel.textContent = 'Loading';
            globalLoadingPaint();
        }, wait);
    }
    function startNavigationGlobalLoading(label) {
        globalLoadingNavigating = true;
        if (globalLoadingLabel) globalLoadingLabel.textContent = label || 'Loading';
        if (globalLoadingOverlay) globalLoadingOverlay.classList.add('gl-instant');
        globalLoadingPaint();
        clearTimeout(globalLoadingNavTimer);
        globalLoadingNavTimer = setTimeout(stopNavigationGlobalLoading, GLOBAL_LOADING_NAV_STUCK_MS);
    }
    function stopNavigationGlobalLoading() {
        clearTimeout(globalLoadingNavTimer);
        globalLoadingNavigating = false;
        if (globalLoadingLabel && globalLoadingActiveCount === 0) globalLoadingLabel.textContent = 'Loading';
        globalLoadingPaint();
    }
    if (document.readyState === 'complete') { finishInitialGlobalLoading(); }
    else { window.addEventListener('load', finishInitialGlobalLoading); }
    setTimeout(finishInitialGlobalLoading, GLOBAL_LOADING_SAFETY_MS);

    // Reload / leave / form submit — keeps a more specific label already set (e.g. "Submitting application").
    window.addEventListener('beforeunload', function () {
        if (!globalLoadingNavigating) startNavigationGlobalLoading('Loading');
    });
    // Same-tab links: show the overlay on click, before beforeunload even fires.
    document.addEventListener('click', function (e) {
        if (e.defaultPrevented || e.button !== 0 || e.metaKey || e.ctrlKey || e.shiftKey || e.altKey) return;
        var a = e.target && e.target.closest ? e.target.closest('a[href]') : null;
        if (!a) return;
        var href = a.getAttribute('href') || '';
        if (!href || href.charAt(0) === '#' || /^(javascript|mailto|tel|blob|data):/i.test(href)) return;
        if (a.hasAttribute('download')) return;
        if (a.target && a.target.toLowerCase() !== '_self') return;
        if (a.origin && a.origin !== window.location.origin) return;
        startNavigationGlobalLoading('Loading');
    });
    // Apply submitted with the details-pane button (native submit that passed the requirement gate).
    document.addEventListener('submit', function (e) {
        var f = e.target;
        if (e.defaultPrevented || !f || !f.id) return;
        if (f.id.indexOf('applyForm_') === 0)  startNavigationGlobalLoading('Submitting application');
        if (f.id.indexOf('cancelForm_') === 0) startNavigationGlobalLoading('Cancelling request');
    });
    // Back/Forward cache restore: the page did not reload, so re-sync the overlay.
    window.addEventListener('pageshow', function (e) {
        if (e.persisted) {
            globalLoadingInitialDone = true;
            globalLoadingActiveCount = 0;
            stopNavigationGlobalLoading();
        }
    });
</script>

<!-- ══════════════════════════════════════════════════════
     DIGITAL RESUME / DOCUMENTS — SHARED LETTERHEAD & FOOTER
     TEMPLATES
     ------------------------------------------------------------
     ADJUSTMENT: these are the single, shared header/footer
     "blueprint" the dynamic A4 pagination engine (see the
     <script> block near the end of this file) clones onto every
     .dr-paper page it builds for every company's accordion — the
     same role #header-clone / #footer-clone play in
     weekly_report_form_builder.php. They never render on screen
     (see the .dr-header-clone/.dr-footer-clone display:none rule
     above) — only their innerHTML is read by JS. ══ -->
<div class="dr-header-clone" id="drHeaderClone" style="display:none !important;">
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
        <h1>Student Application Document</h1>
        <div class="dr-form-meta">Student Application Document</div>
    </div>
</div>
<div class="dr-footer-clone" id="drFooterClone" style="display:none !important;">
    <div class="dr-footer-band">
        <span>NEUST&ndash;OJT&ndash;APPDOC</span>
        <span>Student Application Document</span>
    </div>
</div>

<!-- POPUP MODAL — incomplete profile -->
<div id="profileModal" class="popup-modal" style="display:none;">
    <div class="popup-content">
        <h3><i class="fas fa-triangle-exclamation" style="color:var(--grid-amber);"></i> Incomplete Profile</h3>
        <p>Please complete your student profile (skills, experience, and photo) before applying.</p>
        <button onclick="redirectProfile()">Go to Profile</button>
    </div>
</div>

<!-- POPUP MODAL — already registered to a company -->
<div id="registeredModal" class="popup-modal" style="display:none;">
    <div class="popup-content">
        <span class="popup-icon"></span>
        <h3>Already Registered</h3>
        <p>You are already registered to a company. You cannot apply to another company while you have an active OJT placement.</p>
        <button onclick="document.getElementById('registeredModal').style.display='none'">Got it</button>
    </div>
</div>

<!-- POPUP MODAL — pending application blocks applying to a different company
     ADJUSTMENT: new modal added alongside #registeredModal above, for the
     new guard that stops a student from applying to a second company while
     an earlier application is still awaiting admin review. Cancelling the
     pending request (see the Cancel Request button in each company's
     details pane) clears this guard. -->
<div id="pendingBlockedModal" class="popup-modal" style="display:none;">
    <div class="popup-content">
        <span class="popup-icon"></span>
        <h3>Application Pending</h3>
        <p>You already have an application request awaiting admin approval. Please cancel it first (in that company's details) if you'd like to apply elsewhere.</p>
        <button onclick="document.getElementById('pendingBlockedModal').style.display='none'">Got it</button>
    </div>
</div>

<!-- POPUP MODAL — cancel-request confirmation
     ADJUSTMENT: replaces the previous browser-native confirm() dialog
     used before submitting a Cancel Request. Shared by both the new
     summary-row mini Cancel button and the original details-pane
     Cancel Request button (see openCancelConfirm() / closeCancelConfirmModal()
     / submitCancelConfirm() in the <script> block further down). -->
<div id="cancelConfirmModal" class="popup-modal" style="display:none;">
    <div class="popup-content">
        <span class="popup-icon"><i class="fas fa-triangle-exclamation" style="color:var(--grid-red);"></i></span>
        <h3>Cancel Application Request?</h3>
        <p>This will withdraw your pending application request from this company. This action cannot be undone.</p>
        <div class="ccm-actions">
            <button type="button" class="ccm-btn-keep" onclick="closeCancelConfirmModal()">No, Keep It</button>
            <button type="button" class="ccm-btn-confirm" onclick="submitCancelConfirm()">Yes, Cancel It</button>
        </div>
    </div>
</div>

<!-- POPUP MODAL — requirements not yet verified
     ADJUSTMENT: shown when the student tries to Apply (mini summary-row
     button OR full-size details-pane button) while any of the 8
     requirements is not yet Verified by the administrator. #rumList is
     (re)built by openReqUnverifiedModal() from the live
     liveRequirementStatuses map (see <script>), so it always lists the
     requirements that are actually still outstanding. -->
<div id="reqUnverifiedModal" class="popup-modal" style="display:none;">
    <div class="popup-content">
        <span class="popup-icon"><i class="fas fa-clipboard-list" style="color:var(--grid-red);"></i></span>
        <h3>Requirements Not Yet Verified</h3>
        <p>You cannot apply to a company until <strong>all</strong> of your requirements are <strong>Verified</strong> by the administrator. <span id="rumCount"></span></p>
        <ul class="rum-list" id="rumList"></ul>
        <div class="rum-actions">
            <button type="button" class="rum-btn-close" onclick="closeReqUnverifiedModal()">Close</button>
            <button type="button" class="rum-btn-go" onclick="window.location.href='AccomForm.php'">Go to Requirements</button>
        </div>
    </div>
</div>

<!-- POPUP MODAL — preferred placement does not match the company
     ADJUSTMENT: shown after Apply when the student's saved Preference for
     Placement differs from the company's data. Confirming re-posts the
     same apply request with confirm_replace_placement=1 (see the apply
     handler): the preference is replaced and the Application SIT removed. -->
<?php if (!empty($placement_mismatch)): ?>
<div id="placementMismatchModal" class="popup-modal" style="display:none;">
    <div class="popup-content">
        <span class="popup-icon"><i class="fas fa-triangle-exclamation" style="color:var(--grid-amber);"></i></span>
        <h3>Preferred Placement Doesn't Match</h3>
        <p>Your saved preferred placement is different from the company you are applying to. Your <strong>Application SIT</strong> was made for the old preference, so it must be replaced.</p>
        <ul class="rum-list">
            <li><span><strong>Your preference:</strong><br><?= htmlspecialchars($placement_mismatch['pref']['name']) ?><?= $placement_mismatch['pref']['address'] !== '' ? '<br><small>' . htmlspecialchars($placement_mismatch['pref']['address']) . '</small>' : '' ?></span></li>
            <li><span><strong>This company:</strong><br><?= htmlspecialchars($placement_mismatch['company']['name']) ?><?= $placement_mismatch['company']['address'] !== '' ? '<br><small>' . htmlspecialchars($placement_mismatch['company']['address']) . '</small>' : '' ?></span></li>
        </ul>
        <p><strong>If you continue</strong>, your preferred placement will be replaced with this company's details and your uploaded Application SIT will be <strong>deleted</strong>. You will need to submit a new one before applying.</p>
        <form method="POST" class="rum-actions" onsubmit="startNavigationGlobalLoading('Updating placement');">
            <input type="hidden" name="apply" value="1">
            <input type="hidden" name="company_id" value="<?= (int)$placement_mismatch['company_id'] ?>">
            <input type="hidden" name="confirm_replace_placement" value="1">
            <button type="button" class="rum-btn-close" onclick="closePlacementMismatchModal()">Cancel</button>
            <button type="submit" class="rum-btn-go">Replace &amp; Remove SIT</button>
        </form>
    </div>
</div>
<?php endif; ?>

<!-- REQUIREMENT PREVIEW MODAL -->
<div id="reqPreviewModal" style="display:none;">
    <span id="reqPreviewClose" onclick="document.getElementById('reqPreviewModal').style.display='none'">×</span>
    <img id="reqPreviewImg" src="" alt="Requirement Preview">
</div>

<!-- ══════════════════════════════════════════════════════
     ADJUSTMENT: DIGITAL RESUME MODAL — opened by the "View Digital
     Resume" mini button in each company's summary row (now outside
     .details-pane entirely). #drmScroll is populated by JS at open
     time with the requested company's own, unmodified ".dr-pages-wrap"
     node (see openDigitalResumeModal() / closeDigitalResumeModal() in
     the <script> block further down). ══════════════════════════════ -->
<!-- ADJUSTMENT: #digitalResumeModal removed with the Digital Resume preview (see the company rows). -->

<!-- ══════════════════════════════════════════════════════
     NEW (endorsement flow): INBOX DRAWER — endorsement letters
     ══════════════════════════════════════════════════════ -->
<div id="endoInboxOverlay" onclick="if(event.target===this)closeEndoInbox()">
    <div id="endoInboxDrawer">
        <div class="endo-inbox-head">
            <h3><i class="fas fa-inbox"></i> Inbox &mdash; Endorsement Letters</h3>
            <button type="button" class="endo-inbox-close" onclick="closeEndoInbox()" aria-label="Close">&#x2715;</button>
        </div>
        <div id="endoInboxBody">
            <div class="endo-empty"><i class="fas fa-spinner fa-spin"></i>Loading…</div>
        </div>
    </div>
</div>
<input type="file" id="endoUploadInput" accept="image/jpeg,image/png,image/webp,application/pdf" style="display:none;">

<!-- NEW (endorsement flow): ENDORSEMENT LETTER — FULL SCREEN VIEWER (same shell as the Digital Resume) -->
<div id="endoLetterModal" class="popup-modal drm-modal" style="display:none;">
    <div class="drm-toolbar">
        <div class="drm-toolbar-left">
            <i class="fas fa-envelope-open-text"></i>
            <span class="drm-toolbar-title" id="endoLetterTitle">Endorsement Letter</span>
        </div>
        <div class="drm-toolbar-right">
            <button type="button" class="drm-tbtn-close" onclick="endoPrintLetter()"><i class="fas fa-print"></i> Print</button>
            <button type="button" class="drm-tbtn-close endo-pdf" id="endoPdfBtn" onclick="endoDownloadPdf()"><i class="fas fa-file-pdf"></i> Save as PDF</button>
            <button type="button" class="drm-tbtn-close" onclick="closeEndoLetterModal()"><i class="fas fa-times"></i> Close</button>
        </div>
    </div>
    <div class="drm-canvas">
        <div class="drm-canvas-inner">
            <iframe id="endoLetterFrame" title="Endorsement Letter" scrolling="no"></iframe>
        </div>
    </div>
</div>

<!-- ADJUSTMENT: UPLOADED LETTER — full-screen, in-page viewer (same shell as the Digital Resume
     and letter previews). Opened by the eye button / the thumbnail on each inbox card; no new tab. -->
<div id="endoFileModal" class="popup-modal drm-modal" style="display:none;">
    <div class="drm-toolbar">
        <div class="drm-toolbar-left">
            <i class="fas fa-file-signature"></i>
            <span class="drm-toolbar-title" id="endoFileTitle">Uploaded Letter</span>
        </div>
        <div class="drm-toolbar-right">
            <button type="button" class="drm-tbtn-close" onclick="closeEndoFileModal()"><i class="fas fa-times"></i> Close</button>
        </div>
    </div>
    <div class="drm-canvas endo-file-canvas" id="endoFileCanvas"></div>
</div>

<div id="endoToast"><i id="endoToastIcon" class="fas fa-check-circle" style="color:#4ade80;"></i> <span id="endoToastMsg"></span></div>

<!-- NOT-DEPLOYED MODAL -->
<div id="not-deployed-modal" style="display:none;">
    <div class="ndm-box">
        <div class="ndm-icon"><i class="fas fa-lock" style="color:var(--grid-amber);"></i></div>
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

<!-- ══ SIDEBAR ══ -->
<div id="sidebar" class="sidebar">
    <div class="sidebar-header">
        <!-- Full name includes middle name -->
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
             $all_verified — matching AccomForm.php's sidebar. Previously
             this link was wrapped in `if ($all_verified)`, which hid
             student_profile.php from the sidebar until every one of the
             8 requirement types was Verified by the administrator. The
             Attendance / Reports / Dashboard links directly below remain
             governed by the exact same $all_verified (and, further,
             $is_deployed) gates as before — nothing about those was
             touched. -->
        <a href="student_profile.php">
            <i class="fas fa-user-circle"></i>
            <span class="link-text">My Profile</span>
        </a>
        <a href="company_list.php" class="active">
            <i class="fas fa-building"></i>
            <span class="link-text">Company List</span>
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

<!-- ══════════════════════════════════════════════════════
     ATTENDANCE NOTIFICATION BAR — v3
     Timer overlap fixed: .anb-text-group + min-width:100px on countdown.
══════════════════════════════════════════════════════ -->
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
        <img src="logo.webp" style="height:40px;margin-right:15px;" alt="NEUST Logo">
        <div>
            <div style="font-weight:bold; font-size:16px;">NEUST Atate Campus</div>
            <div style="font-size:11px; color:var(--gold);">Web-Based Smart OJT Monitoring and Supervision Analytics System</div>
        </div>
        <!-- NEW (endorsement flow): Inbox — endorsement letters issued by the administrator -->
        <div class="navbar-right">
            <button type="button" id="endoInboxBtn" onclick="openEndoInbox()" title="Inbox — Endorsement Letters">
                <i class="fas fa-inbox"></i>
                <span id="endoInboxBadge"<?= $endo_attention_count > 0 ? ' style="display:flex;"' : '' ?>><?= $endo_attention_count > 0 ? (int)$endo_attention_count : '' ?></span>
            </button>
        </div>
    </nav>

    <!-- ADJUSTMENT (design): "Field Ops Grid" layout ported from AccomForm.php — the list now sits in the same
         slate-blue .main-wrapper + bordered .card (kept as .page-inner so refreshCompanyList() below still finds
         and live-refreshes it by that selector). Only the look changed; every id / class / handler is intact. -->
    <div class="main-wrapper">
    <div class="page-inner card">
        <div class="cl-head">
            <h2>Verified Companies</h2>
            <?php if ($company_total > 0): ?>
                <span class="cl-count"><?= $company_total ?> partner <?= $company_total === 1 ? 'company' : 'companies' ?></span>
            <?php endif; ?>
        </div>
        <?php if ($company_total > 0): ?>
            <p class="cl-sub">Select a company to view its details and send your application.</p>
        <?php endif; ?>

        <?php /* ADJUSTMENT: the success banners ("Application submitted! Please wait for admin
                 approval." / "Your application request has been cancelled.") are no longer shown —
                 the loading page ("Submitting application" / "Cancelling request") and the
                 company's status pill already show the result. Error messages below are unchanged. */ ?>

        <?php if (!empty($error)): ?>
            <!-- ADJUSTMENT: shown as a popup notification (administrator.php style), not a banner. -->
            <div id="clServerError" data-msg="<?= htmlspecialchars($error, ENT_QUOTES) ?>" hidden></div>
        <?php endif; ?>

        <?php while ($companies instanceof mysqli_result && ($row = $companies->fetch_assoc())):
            $supervisor_name =
                (!empty($row['contact_first_name']) ? $row['contact_first_name'] : '') .
                (!empty($row['contact_middle_initial']) ? ' ' . $row['contact_middle_initial'] . '.' : '') .
                (!empty($row['contact_last_name']) ? ' ' . $row['contact_last_name'] : '');
            $isCurrent = ($current_company_id == $row['id']);

            /* ADJUSTMENT: does this row correspond to the company the
               student already has a pending application to (in either
               admin_application_approvals OR ojt_applications — see
               the $pending_applications build-up above)? Used below to
               swap the Apply button for Cancel Request on that
               specific row. $pendingStageLabel resolves to accurate,
               stage-aware copy ("Waiting for Admin Approval" vs
               "Waiting for Company to Accept") instead of a single
               generic "Pending" string, so the student always knows
               exactly where their application currently stands. */
            $isPendingHere      = isset($pending_applications[$row['id']]);
            $pendingStage       = $pending_applications[$row['id']]['stage'] ?? 'admin_review';
            $pendingStageLabel  = $pendingStageLabels[$pendingStage] ?? 'Application pending review';
            /* ADJUSTMENT: the company panel shows the application's status the same way the
               endorsement-letter inbox showed it (same pill style): at the admin →
               "Waiting for the Approval"; at the company → "Under Company Validation". */
            $appAtAdmin    = ($pendingStage === 'admin_review');
            $appStageLabel = $appAtAdmin ? 'Waiting for the Approval' : 'Under Company Validation';
            $appStageClass = $appAtAdmin ? 'awaiting' : 'pending';
            $appStageIcon  = $appAtAdmin ? 'fa-hourglass-half' : 'fa-magnifying-glass';

            /* FIX: prefer the company's registered name (ci.company) — same
               fallback logic used in student_profile.php's Company Details
               card — instead of always falling back to the contact person's
               first/last name. */
            $company_display_name = !empty($row['company_name'])
                ? $row['company_name']
                : trim($row['first_name'] . " " . $row['last_name']);
        ?>

        <div class="company-row">
            <input type="checkbox" id="company_<?= $row['id'] ?>" class="toggle-input">

            <label for="company_<?= $row['id'] ?>" class="company-summary">
                <span class="company-name">
                    <i class="fas fa-building"></i>
                    <?= htmlspecialchars($company_display_name) ?>
                </span>
                <span class="summary-right">
                    <!-- ADJUSTMENT: the Digital Resume preview was removed from this page. The student's
                         resume is managed on student_profile.php, and applying still sends it (skills /
                         experience from student_skills / student_experience — see the apply handler). -->

                    <?php if ($isCurrent): ?>
                        <span class="badge-current"><i class="fas fa-check"></i> Your Company</span>
                    <?php elseif ($isPendingHere): ?>
                        <span class="app-stage-chip <?= $appStageClass ?>"><i class="fas <?= $appStageIcon ?>"></i> <?= $appStageLabel ?></span>
                        <!-- ══════════════════════════════════════════════
                             ADJUSTMENT: mini Cancel Request button, visible
                             directly in the collapsed summary row so the
                             student doesn't need to open "View Details"
                             first to cancel. event.preventDefault() +
                             event.stopPropagation() stop the click from
                             also toggling the row's checkbox (this control
                             sits inside the <label for="..."> that drives
                             the accordion). Hidden automatically once the
                             row is expanded (see the
                             .toggle-input:checked ~ label .btn-cancel-request-mini
                             CSS rule above) — the original full-size button
                             inside .details-pane takes over from there.
                             Label text changed from "Cancel" to "Cancel
                             Request" to match the full-size button's
                             wording. ══ -->
                        <button type="button"
                                class="btn-cancel-request-mini"
                                onclick="event.preventDefault(); event.stopPropagation(); openCancelConfirm(<?= (int)$row['id'] ?>);">
                            <i class="fas fa-times-circle"></i> Cancel Request
                        </button>
                    <?php else: ?>
                        <!-- ══════════════════════════════════════════════
                             ADJUSTMENT: mini Apply button, mirroring the
                             mini Cancel Request button's "moving button"
                             pattern above. Same guards as the full-size
                             Apply button in .details-pane below (blocked
                             by an active placement, blocked by a pending
                             application elsewhere, or a normal direct
                             apply) — each mini variant performs the exact
                             same action as its full-size counterpart.
                             Hidden once the row is expanded (see the
                             shared .toggle-input:checked rule above); the
                             full-size button inside .details-pane takes
                             over from there.

                             FIX: this button submits its target form via
                             JS (form.submit()), which — unlike a native
                             click on a <button type="submit" name="apply">
                             — does NOT include that button's name/value in
                             the POST body. Previously this meant the mini
                             Apply button silently did nothing server-side
                             (isset($_POST['apply']) was never true). The
                             matching applyForm_<id> below now carries its
                             own <input type="hidden" name="apply" value="1">,
                             so the "apply" flag travels with the form
                             regardless of whether it's submitted by a real
                             click or by this JS call. ══ -->
                        <?php if ($already_registered): ?>
                            <button type="button" class="btn-apply-mini"
                                    onclick="event.preventDefault(); event.stopPropagation(); document.getElementById('registeredModal').style.display='flex';">
                                <i class="fas fa-paper-plane"></i> Apply
                            </button>
                        <?php elseif ($has_pending_application): ?>
                            <button type="button" class="btn-apply-mini"
                                    onclick="event.preventDefault(); event.stopPropagation(); document.getElementById('pendingBlockedModal').style.display='flex';">
                                <i class="fas fa-paper-plane"></i> Apply
                            </button>
                        <?php else: ?>
                            <!-- ADJUSTMENT: guardApplyRequirements() opens
                                 #reqUnverifiedModal and stops here if any
                                 requirement is not yet Verified. -->
                            <button type="button" class="btn-apply-mini"
                                    onclick="event.preventDefault(); event.stopPropagation(); if (!guardApplyRequirements(event)) return; var f=document.getElementById('applyForm_<?= (int)$row['id'] ?>'); if(f) { startNavigationGlobalLoading('Submitting application'); f.submit(); }">
                                <i class="fas fa-paper-plane"></i> Apply
                            </button>
                        <?php endif; ?>
                    <?php endif; ?>

                    <i class="fas fa-chevron-down chevron"></i>
                </span>
            </label>


            <div class="details-pane">
                <!-- ADJUSTMENT: Company Profile / Brief Description
                     (company_information.company_profile — same field as
                     CompanyForm.php's "Company Profile / Brief Description").
                     ADJUSTMENT (this revision): Supervisor name, Contact
                     (telephone) and the Company Profile are now grouped in
                     this ONE section (order: Supervisor → Contact → divider →
                     Company Profile), with no left color block line. -->
                <div class="company-profile-box">
                    <div class="cpb-info">
                        <p><b>Supervisor:</b> <?= htmlspecialchars(trim($supervisor_name)) ?></p>
                        <p><b>Contact:</b> <?= htmlspecialchars($row['telephone']) ?></p>
                    </div>
                    <div class="cpb-divider"></div>
                    <div class="cpb-title"><i class="fas fa-info-circle"></i> Company Profile / Brief Description</div>
                    <?php if (trim((string)($row['company_profile'] ?? '')) !== ''): ?>
                        <p class="cpb-text"><?= nl2br(htmlspecialchars(trim($row['company_profile']))) ?></p>
                    <?php else: ?>
                        <p class="cpb-empty">This company has not provided a company profile yet.</p>
                    <?php endif; ?>
                </div>

                <?php if (!empty($row['google_map_link'])): ?>
                <div class="map">
                    <iframe src="<?= htmlspecialchars($row['google_map_link']) ?>" allowfullscreen loading="lazy"></iframe>
                </div>
                <?php endif; ?>

                <?php if ($isCurrent): ?>
                    <div class="registered-badge">
                        <i class="fas fa-check-circle"></i> Current company registered
                    </div>
                <?php elseif ($isPendingHere): ?>
                    <!-- ══════════════════════════════════════════════
                         ADJUSTMENT: student already sent an application
                         request to THIS company that the admin hasn't
                         acted on yet (a live row in
                         admin_application_approvals). The Apply button
                         becomes a Cancel Request button so the student
                         can withdraw it themselves — this deletes the
                         same row administrator.php's Application
                         Request inbox reads from, so the request
                         disappears from the admin's queue automatically
                         the next time that page polls/loads. ══

                         ADJUSTMENT (this revision): the button no longer
                         submits the form directly via a native confirm()
                         dialog — it now opens the shared
                         #cancelConfirmModal popup (openCancelConfirm()),
                         and the modal's "Yes, Cancel It" button performs
                         the actual submit (submitCancelConfirm()) using
                         this form's id. This is the "original place" the
                         mini summary-row Cancel button (see above) hands
                         control back to once the row is expanded. The
                         hidden cancel_request input keeps the POST
                         payload identical to before regardless of which
                         button triggered it. ══ -->
                    <div class="pending-request-badge app-stage-<?= $appStageClass ?>">
                        <i class="fas <?= $appStageIcon ?>"></i> <?= $appStageLabel ?>
                    </div>
                    <form method="POST" id="cancelForm_<?= (int)$row['id'] ?>">
                        <input type="hidden" name="company_id" value="<?= $row['id'] ?>">
                        <input type="hidden" name="cancel_request" value="1">
                        <button type="button" class="btn-cancel-request" onclick="openCancelConfirm(<?= (int)$row['id'] ?>)">
                            <i class="fas fa-times-circle"></i> Cancel Request
                        </button>
                    </form>
                <?php else: ?>
                    <?php if ($already_registered): ?>
                        <button type="button" class="btn-apply" onclick="document.getElementById('registeredModal').style.display='flex'">
                            <i class="fas fa-paper-plane"></i> Apply
                        </button>
                    <?php elseif ($has_pending_application): ?>
                        <!-- ADJUSTMENT: student has a pending application to a
                             DIFFERENT company already — block Apply here until
                             they cancel that one first (same guard pattern as
                             $already_registered above, just a different modal
                             with accurate messaging). -->
                        <button type="button" class="btn-apply" onclick="document.getElementById('pendingBlockedModal').style.display='flex'">
                            <i class="fas fa-paper-plane"></i> Apply
                        </button>
                    <?php else: ?>
                        <!-- ADJUSTMENT: given an id (applyForm_<id>) so the new mini
                             Apply button in the collapsed summary row above can
                             submit this exact same form directly, matching the
                             Cancel Request mini/full-size pairing pattern.

                             FIX: added <input type="hidden" name="apply" value="1">
                             so the "apply" flag is part of the form's own field
                             set, not just the submit button's name attribute.
                             This is what actually makes the mini Apply button
                             (which submits via form.submit() in JS, and so never
                             carries a clicked-button's name/value) work — without
                             this hidden field, $_POST['apply'] was never set when
                             the mini button was used, so the whole Apply flow
                             (profile-completeness check, insert into
                             admin_application_approvals, etc.) silently never ran.
                             The visible submit button no longer needs its own
                             name="apply" — the hidden field covers it for both
                             the full-size click and the mini JS submit path. -->
                        <!-- ADJUSTMENT: onsubmit gate — a native click on the
                             full-size Apply button is stopped (and
                             #reqUnverifiedModal shown) while any requirement
                             is not yet Verified. The mini button calls the
                             same guard itself before its JS form.submit(). -->
                        <form method="POST" id="applyForm_<?= (int)$row['id'] ?>" onsubmit="return guardApplyRequirements(event);">
                            <input type="hidden" name="company_id" value="<?= $row['id'] ?>">
                            <input type="hidden" name="apply" value="1">
                            <button type="submit" class="btn-apply">
                                <i class="fas fa-paper-plane"></i> Apply
                            </button>
                        </form>
                    <?php endif; ?>
                <?php endif; ?>
            </div>
        </div>

        <?php endwhile; ?>

        <?php if ($company_load_error): ?>
            <div class="cl-state cl-state-error">
                <i class="fas fa-triangle-exclamation"></i>
                <strong>Unable to load the company list</strong>
                <span>Something went wrong while loading the partner companies. Please refresh the page or try again in a moment.</span>
            </div>
        <?php elseif ($company_total === 0): ?>
            <div class="cl-state">
                <i class="fas fa-building-circle-xmark"></i>
                <strong>No verified companies yet</strong>
                <span>Partner companies will appear here once they have been verified by the administrator.</span>
            </div>
        <?php endif; ?>
    </div><!-- end .page-inner -->
    </div><!-- end .main-wrapper -->
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
        mc.style.width = 'calc(100% - 80px)';
    } else {
        mc.style.marginLeft = '260px';
        mc.style.width = 'calc(100% - 260px)';
    }
    /* Sync ANB bar width with collapsed state */
    document.getElementById('att-notif-bar')
            .classList.toggle('sidebar-collapsed', sidebar.classList.contains('collapsed'));
});

/* ── DEFENSIVE MODAL RESET ──
   Belt-and-suspenders: force every popup/modal closed the moment the
   DOM is ready, independent of whatever hid them via the stylesheet.
   The PHP-triggered "show" blocks further below (incomplete_profile /
   apply_blocked) still run afterward and open the one that's actually
   needed, same as before.
   ADJUSTMENT: added m5 for #pendingBlockedModal (the new "apply
   blocked by an existing pending application" popup), m6 for the
   #cancelConfirmModal (cancel-request confirmation popup), and m7 for
   the new #digitalResumeModal (Digital Resume viewer), so all are
   covered by the same reset as every other modal on this page. */
document.addEventListener("DOMContentLoaded", function() {
    var m1 = document.getElementById('profileModal');       if (m1) m1.style.display = 'none';
    var m2 = document.getElementById('registeredModal');    if (m2) m2.style.display = 'none';
    var m3 = document.getElementById('reqPreviewModal');    if (m3) m3.style.display = 'none';
    var m4 = document.getElementById('not-deployed-modal'); if (m4) { m4.style.display = 'none'; m4.classList.remove('show'); }
    var m5 = document.getElementById('pendingBlockedModal');if (m5) m5.style.display = 'none';
    var m6 = document.getElementById('cancelConfirmModal'); if (m6) m6.style.display = 'none';
    var m7 = document.getElementById('digitalResumeModal'); if (m7) m7.style.display = 'none';
    /* ADJUSTMENT: m8 — requirements-not-verified apply-gate popup */
    var m8 = document.getElementById('reqUnverifiedModal'); if (m8) m8.style.display = 'none';
});

/* ── PROFILE / REGISTERED POPUPS ── */
function redirectProfile() { window.location.href = "student_profile.php"; }

<?php if (!empty($incomplete_profile)): ?>
document.addEventListener("DOMContentLoaded", function() {
    document.getElementById("profileModal").style.display = "flex";
});
<?php endif; ?>

<?php if (!empty($apply_blocked)): ?>
document.addEventListener("DOMContentLoaded", function() {
    document.getElementById("registeredModal").style.display = "flex";
});
<?php endif; ?>

<?php if (!empty($placement_mismatch)): ?>
/* ADJUSTMENT: preferred placement ≠ company → ask before replacing it / removing the SIT. */
function closePlacementMismatchModal() {
    var m = document.getElementById('placementMismatchModal');
    if (m) m.style.display = 'none';
}
document.addEventListener("DOMContentLoaded", function() {
    var m = document.getElementById('placementMismatchModal');
    if (m) {
        m.style.display = 'flex';
        m.addEventListener('click', function(e) { if (e.target === m) closePlacementMismatchModal(); });
    }
});
document.addEventListener('keydown', function(e) { if (e.key === 'Escape') closePlacementMismatchModal(); });
<?php endif; ?>

<?php if (!empty($requirements_unverified)): ?>
/* ADJUSTMENT: server-side apply gate fired — re-show the popup. */
document.addEventListener("DOMContentLoaded", function() {
    openReqUnverifiedModal();
});
<?php endif; ?>

/* ── NOT-DEPLOYED MODAL ── */
function showNotDeployedModal(pageName, event) {
    if (event) event.preventDefault();
    document.getElementById('ndm-page-label').textContent = pageName;
    var m = document.getElementById('not-deployed-modal');
    m.classList.add('show');
    m.style.display = 'flex'; /* mirrors the .show class rule; belt-and-suspenders
                                  against the inline display:none safety net above */
}
function closeNotDeployedModal() {
    var m = document.getElementById('not-deployed-modal');
    m.classList.remove('show');
    m.style.display = 'none';
}
document.getElementById('not-deployed-modal').addEventListener('click', function(e) {
    if (e.target === this) closeNotDeployedModal();
});
document.addEventListener('keydown', function(e) {
    if (e.key === 'Escape') {
        document.getElementById('reqPreviewModal').style.display = 'none';
        closeNotDeployedModal();
        closeCancelConfirmModal();
        closeDigitalResumeModal();
        closeReqUnverifiedModal(); /* ADJUSTMENT: apply-gate popup */
    }
});

/* ══════════════════════════════════════════════════════════════
   CANCEL-REQUEST CONFIRMATION POPUP
   ------------------------------------------------------------
   ADJUSTMENT (this revision): replaces the previous
   `onsubmit="return confirm(...)"` native browser dialog on the
   Cancel Request form with a proper in-app popup
   (#cancelConfirmModal, markup further above), consistent with the
   rest of this page's modals.

   `pendingCancelCompanyId` tracks which company's cancel form
   (id="cancelForm_<id>") the popup is currently confirming for.
   Both the new mini Cancel button in the collapsed company summary
   row AND the original full-size Cancel Request button inside the
   expanded details pane call openCancelConfirm(companyId) — so
   there is exactly one confirmation flow and one submission path
   regardless of which button the student used. Cancelling the
   popup (or pressing Escape — see the keydown listener above) just
   clears the pending id and closes it; nothing is submitted unless
   "Yes, Cancel It" is clicked.
   ══════════════════════════════════════════════════════════════ */
let pendingCancelCompanyId = null;

function openCancelConfirm(companyId) {
    pendingCancelCompanyId = companyId;
    document.getElementById('cancelConfirmModal').style.display = 'flex';
}

function closeCancelConfirmModal() {
    pendingCancelCompanyId = null;
    var m = document.getElementById('cancelConfirmModal');
    if (m) m.style.display = 'none';
}

function submitCancelConfirm() {
    if (pendingCancelCompanyId === null) return;
    var form = document.getElementById('cancelForm_' + pendingCancelCompanyId);
    if (form) {
        startNavigationGlobalLoading('Cancelling request'); // ADJUSTMENT: admin-style loading page
        form.submit();
    }
    pendingCancelCompanyId = null;
}

/* Click outside the popup content also dismisses it, matching the
   backdrop-dismiss behavior already used by #not-deployed-modal. */
document.getElementById('cancelConfirmModal').addEventListener('click', function(e) {
    if (e.target === this) closeCancelConfirmModal();
});

/* ══════════════════════════════════════════════════════════════
   DIGITAL RESUME MODAL
   ------------------------------------------------------------
   ADJUSTMENT: the "View Digital Resume" mini button (in each
   company's summary row, outside .details-pane) calls
   openDigitalResumeModal(companyId, companyName). This moves that
   company's already-existing ".dr-pages-wrap" node (its home is
   the hidden "#drStore_<id>" container placed right after the
   summary row — see markup above) into the modal's #drmScroll
   area, makes it visible, and (re)builds its A4 pages the very
   first time via the existing, unmodified drRenderPages() engine
   further below (cheap / cached on any later open, since
   drBuiltPages[companyId] short-circuits once built). Closing the
   modal moves the node back to its original "#drStore_<id>" home
   and hides it again, so the DOM stays exactly as clean as before
   whether or not the resume was ever viewed. ══════════════════ */
function openDigitalResumeModal(companyId, companyName) {
    var nameEl = document.getElementById('drmCompanyName');
    if (nameEl) nameEl.textContent = companyName || '';

    var scrollArea = document.getElementById('drmScroll');
    var pagesWrap  = document.getElementById('drPages_' + companyId);

    if (scrollArea && pagesWrap) {
        pagesWrap.style.display = 'flex';
        scrollArea.appendChild(pagesWrap);
    }

    drRenderPages(companyId); // builds once, cached afterward — untouched logic

    var modal = document.getElementById('digitalResumeModal');
    if (modal) modal.style.display = 'flex';
    document.body.style.overflow = 'hidden';
}

function closeDigitalResumeModal() {
    var modal = document.getElementById('digitalResumeModal');
    if (modal) modal.style.display = 'none';
    document.body.style.overflow = '';

    var scrollArea = document.getElementById('drmScroll');
    if (scrollArea) {
        var pagesWrap = scrollArea.querySelector('.dr-pages-wrap');
        if (pagesWrap && pagesWrap.id && pagesWrap.id.indexOf('drPages_') === 0) {
            var companyId = pagesWrap.id.replace('drPages_', '');
            var home = document.getElementById('drStore_' + companyId);
            if (home) {
                pagesWrap.style.display = 'none';
                home.appendChild(pagesWrap);
            }
        }
        scrollArea.innerHTML = scrollArea.innerHTML; /* no-op guard kept intentionally minimal */
    }
}

// ADJUSTMENT: guarded — #digitalResumeModal was removed with the Digital Resume preview.
if (document.getElementById('digitalResumeModal')) document.getElementById('digitalResumeModal').addEventListener('click', function(e) {
    if (e.target === this) closeDigitalResumeModal();
});

/* ── JOURNAL BADGE (cross-page localStorage sync) ──
   ADJUSTMENT: converted from a self-contained IIFE into a top-level
   function (mirroring AccomForm.php) so it can be safely re-invoked
   by activateVerifiedSidebar() below, right after the Reports link
   — and its badge span — are (re)inserted into the sidebar. Nothing
   about the badge's own behavior (localStorage key, refresh interval,
   storage event) has changed. */
const JOURNAL_BADGE_LS_KEY = <?= json_encode('ojt_journal_empty_count_' . $user_id) ?>;
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
   ADJUSTMENT: APPLY GATE — "all requirements verified" check
   ------------------------------------------------------------
   The student cannot apply to any company until all 8 requirement
   types are Verified. guardApplyRequirements() is called by BOTH
   Apply buttons (mini summary-row button and the full-size button's
   form onsubmit). When the gate is closed it cancels the action and
   opens #reqUnverifiedModal listing the outstanding requirements.

   applyRequirementsVerified / liveRequirementStatuses start from the
   server-side $all_verified / $req_gate_statuses values and are kept
   live by the existing ?poll_status=1 polling loop below, so if the
   administrator verifies the last requirement while this page is
   open, Apply works immediately without a reload. The PHP apply
   handler enforces the same rule server-side as a backstop.
   The already-registered / pending-application guards are untouched
   and still take priority (their buttons never reach this gate).
   ============================================================ */
const REQ_GATE_LABELS  = <?= json_encode($reqLabels) ?>;
const REQ_GATE_TYPES   = <?= json_encode($required_types) ?>;
let   applyRequirementsVerified = <?= json_encode($all_verified) ?>;
let   liveRequirementStatuses   = <?= json_encode($req_gate_statuses) ?>;

function rumEscape(str) {
    return String(str == null ? '' : str)
        .replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;')
        .replace(/"/g, '&quot;').replace(/'/g, '&#39;');
}

function rumChipClass(status) {
    var s = String(status || '').toLowerCase();
    if (s === 'not submitted') return 'st-missing';
    if (s === 'denied' || s === 'rejected' || s === 'declined') return 'st-denied';
    return 'st-pending';
}

function renderReqUnverifiedList() {
    var list = document.getElementById('rumList');
    var countEl = document.getElementById('rumCount');
    if (!list) return;
    var html = '';
    var outstanding = 0;
    REQ_GATE_TYPES.forEach(function(type) {
        var status = liveRequirementStatuses[type] || 'Not Submitted';
        if (status === 'Verified') return;
        outstanding++;
        html += '<li><span>' + rumEscape(REQ_GATE_LABELS[type] || type) + '</span>' +
                '<span class="rum-chip ' + rumChipClass(status) + '">' + rumEscape(status) + '</span></li>';
    });
    list.innerHTML = html;
    list.style.display = outstanding ? '' : 'none';
    if (countEl) {
        countEl.textContent = outstanding
            ? (outstanding + ' requirement' + (outstanding === 1 ? ' is' : 's are') + ' still not verified:')
            : 'All requirements are now verified — you may apply.';
    }
}

function openReqUnverifiedModal() {
    renderReqUnverifiedList();
    var m = document.getElementById('reqUnverifiedModal');
    if (m) m.style.display = 'flex';
}

function closeReqUnverifiedModal() {
    var m = document.getElementById('reqUnverifiedModal');
    if (m) m.style.display = 'none';
}

function guardApplyRequirements(event) {
    if (applyRequirementsVerified) return true;
    if (event) { event.preventDefault(); event.stopPropagation(); }
    openReqUnverifiedModal();
    return false;
}

document.getElementById('reqUnverifiedModal').addEventListener('click', function(e) {
    if (e.target === this) closeReqUnverifiedModal();
});

/* ============================================================
   ADJUSTMENT: INSTANT SIDEBAR ACTIVATION / RE-LOCK ON LIVE
   VERIFICATION CHANGE — ported from AccomForm.php
   ------------------------------------------------------------
   Previously, the "My Profile" / "Attendance" / "Reports" /
   "Dashboard" sidebar links (and the lock notice) on this page
   were only decided once, server-side, from `$all_verified` at
   the moment the page was rendered. If the administrator verified
   (or reverted) the student's requirements while this page was
   already open, the sidebar stayed stale until a manual reload.

   This block polls the lightweight `company_list.php?poll_status=1`
   endpoint defined near the top of this file (mirroring
   AccomForm.php's own `?poll_status=1` polling loop) and, the
   instant all 8 requirement types come back "Verified", unlocks
   the sidebar in-place — no reload needed. If a previously-verified
   set later falls out of "Verified" (e.g. an admin reverts a
   requirement), the sidebar re-locks itself just as instantly.

   ADJUSTMENT: "My Profile" is now ALWAYS rendered server-side (see
   the sidebar markup above) and is no longer tied to
   `$all_verified` at all, so activateVerifiedSidebar() /
   deactivateVerifiedSidebar() below no longer insert or remove it —
   they only manage the Attendance / Reports / Dashboard links and
   the lock notice, matching AccomForm.php's equivalent functions.

   Nothing about `$all_verified` / `$is_deployed` server-side
   computation, nor any other existing sidebar/requirement logic on
   this page, is touched — this only adds a live client-side mirror
   of the same PHP branches already used in the sidebar markup above.
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

(function() {
    function pollSidebarVerificationStatus() {
        fetch('company_list.php?poll_status=1', { credentials: 'same-origin' })
            .then(function(r) { return r.ok ? r.json() : null; })
            .then(function(data) {
                if (!data) return;

                var allVerifiedNow = true;
                Object.keys(data).forEach(function(key) {
                    var status = (data[key] && data[key].status) || 'Pending';
                    if (status !== 'Verified') { allVerifiedNow = false; }
                });

                if (allVerifiedNow && !sidebarVerifiedActivated) {
                    activateVerifiedSidebar();
                } else if (!allVerifiedNow && sidebarVerifiedActivated) {
                    deactivateVerifiedSidebar();
                }

                /* ADJUSTMENT: keep the Apply gate in sync with the same
                   live statuses (see guardApplyRequirements()). */
                Object.keys(data).forEach(function(key) {
                    var d = data[key] || {};
                    liveRequirementStatuses[key] = (d.has_row === false)
                        ? 'Not Submitted'
                        : (d.status || 'Pending');
                });
                applyRequirementsVerified = allVerifiedNow;
                var rumModal = document.getElementById('reqUnverifiedModal');
                if (rumModal && rumModal.style.display === 'flex') {
                    renderReqUnverifiedList();
                }
            })
            .catch(function() { /* silent — network blip, retry next interval */ });
    }

    setTimeout(function() {
        pollSidebarVerificationStatus();
        setInterval(pollSidebarVerificationStatus, 7000);
    }, 3000);
})();

/* ── REQUIREMENT PREVIEW ── */
function openReqPreview(el) {
    const src = el.dataset.src;
    if (!src) return;
    document.getElementById('reqPreviewImg').src = src;
    document.getElementById('reqPreviewModal').style.display = 'flex';
}

/* ============================================================
   DIGITAL RESUME / DOCUMENTS — DYNAMIC A4 PAGINATION ENGINE
   ------------------------------------------------------------
   ADJUSTMENT (this revision — two fixes, nothing else touched):

   FIX 1 — footer/header "cut off" text:
   The previous measurement sandbox was a bare wrapper <div> with no
   layout containment. A plain wrapper like that lets a child block's
   TOP margin collapse straight through into the wrapper's own top
   edge (standard CSS margin-collapsing behavior for block boxes),
   which means `sandbox.scrollHeight` silently came out shorter than
   the block's real rendered height once it was actually placed in
   the live page flow. That silent under-count is exactly what let
   real content run past the fixed 1123px `.dr-paper` height and get
   sliced off by its `overflow:hidden` — producing the cut/garbled
   header & footer text from the screenshots. Fixed by giving each
   sandbox its own block-formatting context (`overflow:hidden`),
   which stops margins from collapsing out of the measurement, plus
   a larger fixed safety buffer (DR_SAFETY_BUFFER) for extra headroom.

   FIX 2 — Submitted Documents as its own dedicated, centered page:
   The OJT Requirements title/table/note block is no longer one of
   the blocks the resume paginator can spread across shared pages.
   It's now built completely independently, as exactly one extra
   `.dr-paper` appended after however many Skills/Experience pages
   the paginator produced, with its content vertically centered on
   that page. A student with very little skill/experience content
   still gets that trailing page as its own clean, centered
   "Submitted Documents" page; a student with a lot of entries gets
   more resume pages before it — the documents page itself never
   moves, grows, or splits because of that.

   No PHP data source, requirement/status logic, or existing JS
   behavior (openReqPreview, drGroupDocRows-era row grouping, etc.)
   changed in meaning — only how this same markup is measured and
   laid out across pages.

   ADJUSTMENT (this revision): the engine's containers
   (.dr-pages-wrap / .dr-raw-source) are now looked up inside each
   company's "#drStore_<id>" element instead of ".details-pane" —
   they were simply relocated outside the accordion in the markup
   above, so the ids used here are completely unchanged.
   ============================================================ */
const DR_PAGE_W = 794;
const DR_PAGE_H = 1123;
const DR_BODY_TOP_BOTTOM_PAD = 18 + 20; // matches .dr-form-body's top+bottom padding
const DR_SAFETY_BUFFER = 32;            // extra headroom so nothing ever grazes the footer
const drBuiltPages = {}; // companyId -> true once its pages have been rendered

function drMeasureContentHeight(el) {
    // Measures a clone of `el` at the same content width the real
    // .dr-form-body gives it (794px page minus 28px left/right padding).
    // overflow:hidden gives the sandbox its own block-formatting
    // context so the cloned block's top/bottom margins are measured
    // in full instead of collapsing into the sandbox's own edges —
    // see the FIX 1 note above.
    var sandbox = document.createElement('div');
    sandbox.style.cssText = 'position:fixed;top:0;left:-9999px;visibility:hidden;width:738px;overflow:hidden;';
    sandbox.appendChild(el.cloneNode(true));
    document.body.appendChild(sandbox);
    var h = sandbox.scrollHeight;
    document.body.removeChild(sandbox);
    return h;
}

function drMeasureFullWidthHeight(el) {
    var sandbox = document.createElement('div');
    sandbox.style.cssText = 'position:fixed;top:0;left:-9999px;visibility:hidden;width:' + DR_PAGE_W + 'px;overflow:hidden;';
    sandbox.appendChild(el.cloneNode(true));
    document.body.appendChild(sandbox);
    var h = sandbox.scrollHeight;
    document.body.removeChild(sandbox);
    return h;
}

function drAppendSectionBlocks(rawRoot, titleSelector, colSelector, blocks) {
    // Glues a section title together with whichever comes right after
    // it (its first entry, or its "No skills/experience listed" empty
    // note) into a single unit the paginator can never split across a
    // page break, so the title is never stranded alone at the bottom
    // of a page. Any further entries after that still flow normally,
    // each free to land on whichever page has room.
    var title = rawRoot.querySelector(titleSelector);
    if (!title) return;

    var col = rawRoot.querySelector(colSelector);
    var children = col ? Array.prototype.slice.call(col.children) : [];

    if (children.length > 0) {
        blocks.push({ type: 'glued', els: [title, children[0]] });
        for (var i = 1; i < children.length; i++) {
            blocks.push({ type: 'atomic', el: children[i] });
        }
    } else {
        blocks.push({ type: 'atomic', el: title });
    }
}

function drBuildResumeBlocks(rawRoot) {
    // Only the Digital Resume portion (applicant strip + Skills +
    // Experience) — the OJT Requirements section is handled entirely
    // separately by drBuildDocPageBody() below, per FIX 2.
    var blocks = [];

    var applicantStrip = rawRoot.querySelector('.dr-applicant-strip');
    if (applicantStrip) blocks.push({ type: 'atomic', el: applicantStrip });

    drAppendSectionBlocks(rawRoot, '.dr-skills-title', '.dr-skills-col', blocks);
    drAppendSectionBlocks(rawRoot, '.dr-exp-title', '.dr-exp-col', blocks);

    return blocks;
}

function drPartitionResume(rawRoot, usableH) {
    var blocks  = drBuildResumeBlocks(rawRoot);
    var pages   = [[]];
    var pageIdx = 0;
    var curH    = 0;

    function breakPageIfNeeded(h) {
        if (curH + h > usableH && pages[pageIdx].length > 0) {
            pageIdx++;
            pages[pageIdx] = [];
            curH = 0;
        }
    }

    blocks.forEach(function(block) {
        if (block.type === 'atomic') {
            var h = drMeasureContentHeight(block.el);
            breakPageIfNeeded(h);
            pages[pageIdx].push(block.el.cloneNode(true));
            curH += h;
            return;
        }

        if (block.type === 'glued') {
            var combinedWrap = document.createElement('div');
            block.els.forEach(function(e) { combinedWrap.appendChild(e.cloneNode(true)); });
            var gh = drMeasureContentHeight(combinedWrap);
            breakPageIfNeeded(gh);
            block.els.forEach(function(e) { pages[pageIdx].push(e.cloneNode(true)); });
            curH += gh;
        }
    });

    pages = pages.filter(function(p) { return p.length > 0; });
    if (pages.length === 0) pages = [[]];
    return pages;
}

function drBuildDocPageBody(rawRoot) {
    // The OJT Requirements title + table + note, cloned as one
    // self-contained unit for the dedicated Submitted Documents page.
    // Never split across pages — there are always exactly 8 fixed
    // requirement rows, so this comfortably fits a single A4 page.
    var wrap = document.createElement('div');
    wrap.className = 'dr-doc-page-inner';

    var docTitle = rawRoot.querySelector('.dr-doc-title');
    if (docTitle) wrap.appendChild(docTitle.cloneNode(true));

    var docTable = rawRoot.querySelector('.dr-doc-table');
    if (docTable) wrap.appendChild(docTable.cloneNode(true));

    var note = rawRoot.querySelector('.dr-req-note');
    if (note) wrap.appendChild(note.cloneNode(true));

    return wrap;
}

function drRenderPages(companyId) {
    if (drBuiltPages[companyId]) return; // cached (or already building) — skip
    drBuiltPages[companyId] = 'pending'; // claim immediately so a fast re-toggle can't double-build

    var wrap = document.getElementById('drPages_' + companyId);
    var raw  = document.getElementById('drRaw_'   + companyId);
    if (!wrap || !raw) { drBuiltPages[companyId] = false; return; }

    /* ADJUSTMENT: wait for every web font (DM Sans, etc.) used inside the
       resume/document text to finish loading before measuring anything.
       Measuring too early — while text is still rendered in a fallback
       font — under- or over-estimates each block's real height; once the
       real font swaps in, the text can grow just enough to push content
       past the page's fixed 1123px height, and since .dr-paper clips
       overflow, anything past that boundary silently disappears.
       document.fonts.ready guarantees the fonts actually used on the
       page are loaded before we measure, exactly like
       weekly_report_form_builder.php already does for its own
       auto-pagination. */
    document.fonts.ready.then(function() {
        if (drBuiltPages[companyId] === true) return; // built by another path meanwhile

        var headerClone = document.getElementById('drHeaderClone');
        var footerClone = document.getElementById('drFooterClone');

        var HDR_H    = drMeasureFullWidthHeight(headerClone) || 120;
        var FTR_H    = drMeasureFullWidthHeight(footerClone) || 24;
        var USABLE_H = DR_PAGE_H - HDR_H - FTR_H - DR_BODY_TOP_BOTTOM_PAD - DR_SAFETY_BUFFER;

        // Skills/Experience pages — grows or shrinks with entry count.
        var resumePages = drPartitionResume(raw, USABLE_H);

        // Submitted Documents — always exactly one dedicated page,
        // vertically centered, appended after every resume page.
        var docBody       = drBuildDocPageBody(raw);
        var docBodyHeight = drMeasureContentHeight(docBody);
        var docFits       = docBodyHeight <= USABLE_H;

        var totalPages = resumePages.length + 1;

        wrap.innerHTML = '';

        function buildPaper(pageNum, isDocPage, content) {
            var paper = document.createElement('div');
            paper.className = 'dr-paper';

            var hdr = document.createElement('div');
            hdr.innerHTML = headerClone.innerHTML;
            var metaEl = hdr.querySelector('.dr-form-meta');
            if (metaEl) metaEl.textContent = 'Student Application Document \u2014 Page ' + pageNum + ' of ' + totalPages;
            paper.appendChild(hdr);

            var body = document.createElement('div');
            body.className = 'dr-form-body' + (isDocPage ? (docFits ? ' dr-doc-page-body' : ' dr-doc-page-fallback') : '');
            if (isDocPage) {
                body.appendChild(content);
            } else {
                content.forEach(function(node) { body.appendChild(node); });
            }
            paper.appendChild(body);

            var ftr = document.createElement('div');
            ftr.innerHTML = footerClone.innerHTML;
            paper.appendChild(ftr);

            wrap.appendChild(paper);
        }

        resumePages.forEach(function(nodes, i) {
            buildPaper(i + 1, false, nodes);
        });
        buildPaper(totalPages, true, docBody);

        drBuiltPages[companyId] = true;
    });
}

/* ADJUSTMENT: the Digital Resume is now opened on demand via the
   "View Digital Resume" button (openDigitalResumeModal() above),
   which itself calls drRenderPages(companyId). The accordion
   toggle/expand listeners below are kept only as an optional,
   harmless pre-warm — building a company's pages in the background
   if its row happens to get expanded — but are no longer required
   for the resume to display, since the resume is no longer part of
   .details-pane at all. */
document.querySelectorAll('.company-row .toggle-input').forEach(function(input) {
    input.addEventListener('change', function() {
        if (this.checked) {
            var companyId = this.id.replace('company_', '');
            drRenderPages(companyId);
        }
    });
});

/* In case a company row is already expanded on load (e.g. the browser
   restored checkbox state on back/forward navigation), pre-warm its
   pages immediately too instead of waiting for a 'change' event that
   won't fire. Harmless / optional, per the note above. */
document.addEventListener('DOMContentLoaded', function() {
    document.querySelectorAll('.company-row .toggle-input:checked').forEach(function(input) {
        var companyId = input.id.replace('company_', '');
        drRenderPages(companyId);
    });
});

/* ══════════════════════════════════════════════════════════════
   ATTENDANCE NOTIFICATION BAR — v3
   Full port from student_attendance.php, including:
   - PM Sign Out 1-hour late window (Pass 2 in _anbWatch)
   - _anbPmLateShown guard (prevents duplicate late-window shows)
   - Late-window action button navigates to student_attendance.php
   - All dismiss paths go through _anbHide()
   - Countdown + RAF progress bar in lockstep
   - void prog.offsetWidth reflow so transition-none reset takes effect
══════════════════════════════════════════════════════════════ */

const ANB_BADGE_INFO     = <?= json_encode($attendance_badge_info) ?>;
const ANB_TODAY_SETTINGS = <?= json_encode($_att_today_settings) ?>;
const ANB_IS_WEEKEND     = <?= $_att_is_weekend ? 'true' : 'false' ?>;
const ANB_IS_ALL_DONE    = <?= json_encode((bool)$_att_all_done) ?>;
const ANB_ORDER          = ['am_time_in','am_time_out','pm_time_in','pm_time_out'];

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

/* ── Tiny helpers ── */
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

/**
 * _anbHide(type)
 * Single dismissal gate — every path goes through here.
 */
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

/**
 * _anbShow(info)
 * Shows the notification bar for the given attendance window.
 * For late-window entries, the action button navigates to
 * student_attendance.php so the student can submit the late request there.
 */
function _anbShow(info) {
    if (!info) return;
    const dow = new Date().getDay();
    if (ANB_IS_WEEKEND || dow === 0 || dow === 6 || ANB_IS_ALL_DONE) return;
    if (_anb.dismissedWindows.has(info.type)) return;

    _anb.currentType = info.type;
    _anb.showStartTs = performance.now();

    /* Populate text */
    document.getElementById('anb-label').textContent  = info.label + ' is open';
    document.getElementById('anb-window').textContent = 'Window: ' + info.start_fmt + ' \u2013 ' + info.end_fmt;
    document.getElementById('anb-countdown').textContent = 'Calculating...';

    /* Action button — both normal and late navigate to the attendance page */
    const anbActionBtn = document.getElementById('anb-action-btn');
    if (info.is_late_window) {
        anbActionBtn.textContent = 'Request now';
    } else {
        anbActionBtn.textContent = 'Sign now';
    }
    anbActionBtn.onclick = function() { window.location.href = 'student_attendance.php'; };

    /* Sync sidebar-collapsed width */
    document.getElementById('att-notif-bar')
            .classList.toggle('sidebar-collapsed', sidebar.classList.contains('collapsed'));

    /* Cancel any leftover async from a previous show */
    if (_anb.tickInterval  !== null) { clearInterval(_anb.tickInterval);  _anb.tickInterval  = null; }
    if (_anb.autoHideTimer !== null) { clearTimeout(_anb.autoHideTimer);  _anb.autoHideTimer = null; }
    if (_anb.rafId         !== null) { cancelAnimationFrame(_anb.rafId);  _anb.rafId         = null; }

    /* ── RAF-driven progress bar ── */
    const prog = document.getElementById('anb-progress');
    if (prog) {
        prog.style.transition = 'none';
        prog.style.width = '100%';
        void prog.offsetWidth; /* force reflow so transition-none takes effect */
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

    /* ── Countdown ticker ── */
    const endSec = _anbTimeToSec(info.end_time);

    function tick() {
        const rem = endSec - _anbNowSec();
        if (rem <= 0) { _anbHide(_anb.currentType); return; }
        const m = Math.floor(rem / 60), s = rem % 60;
        document.getElementById('anb-countdown').innerHTML =
            '<i class="fas fa-hourglass-half"></i> ' + m + 'm ' + String(s).padStart(2,'0') + 's left';
    }
    tick();
    _anb.tickInterval = setInterval(tick, 1000);

    /* ── Auto-hide after autoHideDuration ── */
    const capturedType = info.type;
    _anb.autoHideTimer = setTimeout(function() {
        _anb.autoHideTimer = null;
        _anbHide(capturedType);
    }, duration);

    /* Slide in */
    document.getElementById('att-notif-bar').classList.add('anb-visible');
}

/* Close button — reads type at click time */
document.getElementById('anb-close-btn').addEventListener('click', function(e) {
    e.stopPropagation();
    _anbHide(_anb.currentType);
});

/**
 * _anbWatch()
 * Polls every 30s for newly-opened windows not yet shown.
 * Pass 1: normal attendance windows.
 * Pass 2: PM Sign Out 1-hour late window.
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
        if (ns < s || ns > e)                continue;
        if (_anb.dismissedWindows.has(type)) continue;
        if (_anb.shownWindows.has(type))     continue;

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

    /* Pass 2 — PM Sign Out 1-hour late window */
    if (_anbPmLateShown) return;
    const pmOutEndStr = ANB_TODAY_SETTINGS['pm_time_out_end'];
    if (!pmOutEndStr) return;
    const pmOutEndSec   = _anbTimeToSec(pmOutEndStr);
    const lateWindowEnd = pmOutEndSec + 3600;
    if (ns <= pmOutEndSec || ns > lateWindowEnd) return;

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

/* ── Periodic watch for newly-opened windows (every 30s) ── */
setInterval(_anbWatch, 30000);

/* ══════════════════════════════════════════════════════════════════════
   NEW (endorsement flow): INBOX — ENDORSEMENT LETTERS
   ------------------------------------------------------------
   • Letters issued by the administrator after an application is allowed.
   • Open Letter  → full-screen viewer (Digital Resume shell) with Print
                    and Save as PDF.
   • Upload       → signed letter (JPG / PNG / WEBP / PDF, max 8 MB) goes
                    to the company for validation (add_ojt_student.php).
   • Polls every 20 s so new letters and the company's decision
     (Verified / Rejected with remarks) show up without a reload.
   ══════════════════════════════════════════════════════════════════════ */
var _endoLetters = <?= json_encode($endorsement_letters, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
var _endoKnownStatus = {};
_endoLetters.forEach(function (l) { _endoKnownStatus[l.id] = l.status; });
var _endoUploadTargetId = null;
var _endoCurrentLetter  = null;
var ENDO_MAX_BYTES = 8 * 1024 * 1024;
var ENDO_TYPES = ['image/jpeg', 'image/png', 'image/webp', 'application/pdf'];

function endoEsc(s) { return rumEscape(s == null ? '' : String(s)); }

function endoStatusMeta(status) {
    switch (status) {
        case 'Pending':  return { cls: 'pending',  icon: 'fa-magnifying-glass', label: 'Under Company Validation',
                                  help: 'Your signed letter was uploaded. The company is validating it.' };
        case 'Verified': return { cls: 'verified', icon: 'fa-circle-check',    label: 'Verified',
                                  help: 'The company verified your endorsement letter. You will be registered once the company accepts your application.' }; // ADJUSTMENT: accepting is now a separate company step
        case 'Rejected': return { cls: 'rejected', icon: 'fa-circle-xmark',    label: 'Rejected',
                                  help: 'Please correct the letter based on the remark above and upload it again.' }; // ADJUSTMENT: the Remark box now sits above this line
        default:         return { cls: 'awaiting', icon: 'fa-hourglass-half',  label: 'Awaiting Your Upload',
                                  help: 'Open the letter, print it or save it as PDF, have it signed, then upload the signed copy here.' };
    }
}

function endoAttention(letters) {
    return letters.filter(function (l) { return !l.viewed || l.status === 'Awaiting Upload' || l.status === 'Rejected'; }).length;
}

function updateEndoBadge(n) {
    var b = document.getElementById('endoInboxBadge');
    if (!b) return;
    b.textContent = n > 0 ? n : '';
    b.style.display = n > 0 ? 'flex' : 'none';
}

function renderEndoInbox() {
    var body = document.getElementById('endoInboxBody');
    if (!body) return;
    if (!_endoLetters.length) {
        body.innerHTML = '<div class="endo-empty"><i class="fas fa-inbox"></i>No endorsement letters yet.<br>Once the administrator approves your application, your letter will arrive here.</div>';
        return;
    }
    // ADJUSTMENT: cards in the administrator's requirement-display style (see the .cv-gallery CSS).
    body.innerHTML = '<div class="cv-gallery">' + _endoLetters.map(function (l) {
        var m = endoStatusMeta(l.status);
        var canUpload = l.status !== 'Verified';
        var uploadLabel = l.status === 'Rejected' ? 'Re-upload Signed Letter' : (l.has_upload ? 'Replace Upload' : 'Upload Signed Letter');
        var fileUrl = '?view_my_endorsement_upload=' + l.id;
        var isImage = /^image\//.test(l.uploaded_mime || '');
        // ADJUSTMENT: the uploaded file opens IN the page (full-screen viewer), never a new tab.
        var openFile = 'openEndoFile(' + l.id + ')';
        var preview = !l.has_upload
            ? '<div class="cv-no-file"><i class="fas fa-hourglass-half"></i><span>No file yet</span></div>'
            : (isImage
                ? '<img src="' + fileUrl + '" class="cv-thumb-img" alt="" title="View the uploaded letter" onclick="' + openFile + '">'
                : '<button type="button" class="cv-no-file is-file" title="View the uploaded letter" onclick="' + openFile + '"><i class="fas fa-file-pdf"></i><span>' + endoEsc(l.uploaded_name || 'Uploaded letter') + '</span></button>')
              + '<button type="button" class="cv-view-btn" title="View the uploaded letter" aria-label="View the uploaded letter" onclick="' + openFile + '"><i class="fas fa-eye"></i></button>';
        var state = !l.has_upload ? 'awaiting' : (l.status === 'Verified' ? 'verified' : 'pending');
        return '<div class="req-item cv-req-card' + (l.viewed ? '' : ' unread') + '" id="endoCard' + l.id + '" data-state="' + state + '" data-rejected="' + (l.status === 'Rejected' ? '1' : '0') + '">' +
            '<div class="cv-card-preview">' +
                preview +
                '<div class="cv-rej-placeholder"><i class="fas fa-file-circle-xmark"></i><span>Rejected<br>Upload a corrected letter</span></div>' +
                (l.viewed ? '' : '<span class="endo-new-pill">NEW</span>') +
            '</div>' +
            '<div class="cv-card-body">' +
                '<div class="cv-card-label">Endorsement Letter &mdash; ' + endoEsc(l.company_name) + '</div>' +
                '<div class="endo-card-sub">Issued by the OJT Administrator' + (l.sent_at ? ' &middot; ' + endoEsc(l.sent_at) : '') + '</div>' +
                ((l.batch_with && l.batch_with.length) ? '<div class="endo-batch-note"><i class="fas fa-users"></i> Batch letter \u2014 shared with ' + endoEsc(l.batch_with.join(', ')) + '. One signed upload covers everyone.</div>' : '') + // ADJUSTMENT
                '<div class="cv-card-remark"><i class="fas fa-comment-dots"></i><span><b>Remark:</b> ' + (l.remark ? endoEsc(l.remark).replace(/\n/g, '<br>') : '&mdash;') + '</span></div>' +
                '<div class="endo-help">' + m.help + '</div>' +
                (l.has_upload ? '<div class="endo-upload-line"><i class="fas fa-paperclip"></i> ' + endoEsc(l.uploaded_name || 'Uploaded file') +
                    (l.uploaded_at ? ' &middot; ' + endoEsc(l.uploaded_at) : '') + '</div>' : '') + // ADJUSTMENT: "View" link → eye button on the preview
                '<div class="endo-card-actions">' +
                    '<button type="button" class="endo-act primary" onclick="openEndoLetter(' + l.id + ')"><i class="fas fa-expand"></i> Open Letter</button>' +
                    (canUpload ? '<button type="button" class="endo-act upload" id="endoUpBtn' + l.id + '" onclick="endoPickUpload(' + l.id + ')"><i class="fas fa-upload"></i> ' + uploadLabel + '</button>' : '') +
                '</div>' +
                (canUpload ? '<div class="endo-upload-hint">Accepted: JPG, PNG, WEBP or PDF &middot; max 8 MB</div>' : '') +
            '</div>' +
        '</div>';
    }).join('') + '</div>';
}


/* ADJUSTMENT: full-screen, in-page viewer for the student's uploaded letter (image or PDF). */
function openEndoFile(id) {
    var l = _endoLetters.find(function (x) { return x.id === id; });
    var url = window.location.pathname + '?view_my_endorsement_upload=' + encodeURIComponent(id) + '&t=' + Date.now();
    var name = (l && l.uploaded_name) ? l.uploaded_name : 'Uploaded letter';
    document.getElementById('endoFileTitle').textContent = 'Uploaded Letter' + (l ? ' — ' + l.company_name : '') + ' · ' + name;
    var canvas = document.getElementById('endoFileCanvas');
    canvas.innerHTML = '';
    if (l && /^image\//.test(l.uploaded_mime || '')) {
        var img = document.createElement('img');
        img.src = url; img.alt = name;
        canvas.appendChild(img);
    } else {
        var fr = document.createElement('iframe');
        fr.src = url; fr.title = name;
        canvas.appendChild(fr);
    }
    document.getElementById('endoFileModal').style.display = 'flex';
    document.body.style.overflow = 'hidden';
}
function closeEndoFileModal() {
    var m = document.getElementById('endoFileModal');
    if (!m || m.style.display === 'none') return false;
    m.style.display = 'none';
    document.getElementById('endoFileCanvas').innerHTML = '';
    // keep scrolling locked while the inbox drawer stays open underneath
    if (document.getElementById('endoInboxOverlay').style.display !== 'flex') document.body.style.overflow = '';
    return true;
}
document.getElementById('endoFileModal').addEventListener('click', function (e) {
    if (e.target === this) closeEndoFileModal();
});

function openEndoInbox() {
    document.getElementById('endoInboxOverlay').style.display = 'flex';
    renderEndoInbox();
    loadEndoInbox();
}
function closeEndoInbox() {
    document.getElementById('endoInboxOverlay').style.display = 'none';
}

var _endoToastTimer = null;
function showEndoToast(msg, type, actionLabel, actionFn) {
    var t = document.getElementById('endoToast');
    var icon = document.getElementById('endoToastIcon');
    document.getElementById('endoToastMsg').textContent = msg;
    var old = t.querySelector('button'); if (old) old.remove();
    if (actionLabel && actionFn) {
        var b = document.createElement('button');
        b.type = 'button'; b.textContent = actionLabel; b.onclick = actionFn;
        t.appendChild(b);
    }
    t.classList.toggle('error', type === 'error');
    icon.className = type === 'error' ? 'fas fa-circle-exclamation' : 'fas fa-check-circle';
    icon.style.color = type === 'error' ? '#f87171' : '#4ade80';
    t.classList.add('show');
    clearTimeout(_endoToastTimer);
    _endoToastTimer = setTimeout(function () { t.classList.remove('show'); }, actionLabel ? 9000 : 4500);
}

function loadEndoInbox() {
    return fetch(window.location.pathname + '?endorsement_inbox=1', { cache: 'no-store' })
        .then(function (r) { return r.json(); })
        .then(function (res) {
            if (!res || !res.success) return;
            var letters = res.letters || [];
            var newOnes = [], verified = [], rejected = [];
            letters.forEach(function (l) {
                var prev = _endoKnownStatus[l.id];
                if (prev === undefined) newOnes.push(l);
                else if (prev !== l.status && l.status === 'Verified') verified.push(l);
                else if (prev !== l.status && l.status === 'Rejected') rejected.push(l);
                _endoKnownStatus[l.id] = l.status;
            });
            _endoLetters = letters;
            updateEndoBadge(res.attention || 0);
            if (document.getElementById('endoInboxOverlay').style.display === 'flex') renderEndoInbox();

            if (verified.length) {
                showEndoToast('Your endorsement letter for ' + verified[0].company_name + ' was verified.', 'success', // ADJUSTMENT: registration happens when the company accepts
                              'Refresh', function () { window.location.reload(); });
            } else if (rejected.length) {
                showEndoToast('Your endorsement letter for ' + rejected[0].company_name + ' was rejected. See the remarks in your Inbox.', 'error',
                              'Open Inbox', openEndoInbox);
            } else if (newOnes.length) {
                showEndoToast('New endorsement letter received from ' + newOnes[0].company_name + '.', 'success', 'Open Inbox', openEndoInbox);
                var btn = document.getElementById('endoInboxBtn');
                if (btn) { btn.classList.remove('pulse'); void btn.offsetWidth; btn.classList.add('pulse'); }
            }
        })
        .catch(function () { /* silent — retried on next interval */ });
}
setInterval(loadEndoInbox, 20000);

/* ── Full-screen letter viewer ── */
function endoResizeLetterFrame() {
    var frame = document.getElementById('endoLetterFrame');
    try {
        var doc = frame.contentDocument;
        if (doc && doc.documentElement) {
            var h = Math.max(doc.documentElement.scrollHeight, doc.body ? doc.body.scrollHeight : 0);
            if (h > 100) frame.style.height = h + 'px';
        }
    } catch (e) {}
}

function openEndoLetter(id) {
    var l = _endoLetters.find(function (x) { return x.id === id; });
    _endoCurrentLetter = l || { id: id, company_name: '' };
    document.getElementById('endoLetterTitle').textContent = 'Endorsement Letter' + (l ? ' — ' + l.company_name : '');
    var frame = document.getElementById('endoLetterFrame');
    frame.style.height = '1200px';
    frame.onload = function () {
        // The builder paginates once its web fonts load — re-measure a few times.
        [100, 400, 900, 1600, 2600].forEach(function (t) { setTimeout(endoResizeLetterFrame, t); });
    };
    frame.src = window.location.pathname + '?endorsement_letter=' + encodeURIComponent(id) + '&embed=1';
    document.getElementById('endoLetterModal').style.display = 'flex';
    document.body.style.overflow = 'hidden';

    // Opening marks it as read (server side) — reflect that immediately.
    if (l && !l.viewed) {
        l.viewed = true;
        updateEndoBadge(endoAttention(_endoLetters));
        renderEndoInbox();
    }
}

function closeEndoLetterModal() {
    var m = document.getElementById('endoLetterModal');
    if (!m || m.style.display === 'none') return;
    m.style.display = 'none';
    document.getElementById('endoLetterFrame').src = 'about:blank';
    document.body.style.overflow = '';
    _endoCurrentLetter = null;
}

function endoPrintLetter() {
    var frame = document.getElementById('endoLetterFrame');
    try { frame.contentWindow.focus(); frame.contentWindow.print(); }
    catch (e) { showEndoToast('Unable to open the print dialog. Please try again.', 'error'); }
}

function endoDownloadPdf() {
    var frame = document.getElementById('endoLetterFrame');
    var btn = document.getElementById('endoPdfBtn');
    var name = 'Endorsement_Letter' + (_endoCurrentLetter && _endoCurrentLetter.company_name
        ? '_' + _endoCurrentLetter.company_name.replace(/[^A-Za-z0-9]+/g, '_').replace(/^_+|_+$/g, '') : '') + '.pdf';
    var w = frame.contentWindow;
    if (!w || typeof w.endoSavePdf !== 'function') {
        showEndoToast('The letter is still loading — please try again in a moment.', 'error');
        return;
    }
    btn.disabled = true;
    btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Preparing PDF…';
    w.endoSavePdf(name)
        .then(function () { showEndoToast('PDF saved: ' + name, 'success'); })
        .catch(function () {
            // Fallback (e.g. PDF libraries blocked/offline): the browser's own print-to-PDF.
            showEndoToast('Choose "Save as PDF" as the printer in the dialog that opens.', 'success');
            endoPrintLetter();
        })
        .finally(function () {
            btn.disabled = false;
            btn.innerHTML = '<i class="fas fa-file-pdf"></i> Save as PDF';
        });
}

document.getElementById('endoLetterModal').addEventListener('click', function (e) {
    if (e.target === this) closeEndoLetterModal();
});

/* ── Upload the signed letter ── */
function endoPickUpload(id) {
    _endoUploadTargetId = id;
    var input = document.getElementById('endoUploadInput');
    input.value = '';
    input.click();
}

document.getElementById('endoUploadInput').addEventListener('change', function () {
    var file = this.files && this.files[0];
    var id = _endoUploadTargetId;
    if (!file || id === null) return;
    if (ENDO_TYPES.indexOf(file.type) === -1) { showEndoToast('Only JPG, PNG, WEBP or PDF files are accepted.', 'error'); return; }
    if (file.size > ENDO_MAX_BYTES)           { showEndoToast('The file must be smaller than 8 MB.', 'error'); return; }

    var btn = document.getElementById('endoUpBtn' + id);
    var oldHtml = btn ? btn.innerHTML : '';
    if (btn) { btn.disabled = true; btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Uploading…'; }

    var fd = new FormData();
    fd.append('upload_endorsement', '1');
    fd.append('endorsement_id', String(id));
    fd.append('endorsement_file', file);
    fetch(window.location.pathname, { method: 'POST', body: fd })
        .then(function (r) { return r.json(); })
        .then(function (res) {
            if (res && res.success) {
                showEndoToast(res.message || 'Endorsement letter uploaded.', 'success');
                _endoKnownStatus[id] = 'Pending';
                loadEndoInbox();
            } else {
                if (btn) { btn.disabled = false; btn.innerHTML = oldHtml; }
                showEndoToast((res && res.message) || 'Upload failed. Please try again.', 'error');
            }
        })
        .catch(function () {
            if (btn) { btn.disabled = false; btn.innerHTML = oldHtml; }
            showEndoToast('Network error. Please try again.', 'error');
        });
});

document.addEventListener('keydown', function (e) {
    if (e.key !== 'Escape') return;
    if (closeEndoFileModal()) return; // ADJUSTMENT: uploaded-file viewer sits on top
    var lm = document.getElementById('endoLetterModal');
    if (lm && lm.style.display !== 'none') { closeEndoLetterModal(); return; }
    closeEndoInbox();
});

/* Deep link: company_list.php?inbox=1 opens the Inbox directly. */
if (new URLSearchParams(window.location.search).get('inbox') === '1') {
    document.addEventListener('DOMContentLoaded', openEndoInbox);
}

/* ══════════════════════════════════════════════════════════════════════
   ADJUSTMENT: LIVE APPLICATION TRACKING + POPUP NOTIFICATIONS
   ------------------------------------------------------------
   Every 5 s the page asks ?poll_application=1 where the application is
   ("admin" / "company") and whether the student is registered. When that
   changes, a popup (administrator.php's .cv-top-toast style) explains
   what happened and the UI updates right away:
     • stage changes      → the company panel list is refreshed in place
                            (open panels stay open) behind the loading page
     • registration change → the page reloads (the sidebar's locked pages
                            change too); the popup is shown again after it
   ══════════════════════════════════════════════════════════════════════ */
var CL_LIVE_TOASTS_KEY = 'cl_live_toasts';

function clLayoutTopToasts() {
    var top = 30;
    document.querySelectorAll('.cv-top-toast').forEach(function (el) {
        el.style.top = top + 'px';
        top += el.offsetHeight + 12;
    });
}

// Same popup as administrator.php (cvShowTopToast / showStudentVerifiedToast).
function clShowTopToast(name, messageText, iconClass, isError) {
    var div = document.createElement('div');
    div.className = 'cv-top-toast' + (isError ? ' is-error' : '');
    div.setAttribute('role', 'status');
    div.innerHTML = '<i class="fas ' + rumEscape(iconClass || 'fa-circle-info') + '"></i><span>' +
        (name ? '<strong>' + rumEscape(name) + '</strong> ' : '') + rumEscape(messageText) + '</span>';
    document.body.appendChild(div);
    clLayoutTopToasts();
    requestAnimationFrame(function () { div.classList.add('show'); });
    setTimeout(function () {
        div.classList.remove('show');
        setTimeout(function () { div.remove(); clLayoutTopToasts(); }, 400);
    }, 7000);
}

(function () {
    var clState = <?= json_encode(clAppLiveState($conn, $user_id), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
    var clNames = Object.assign({}, clState.names || {});
    var clBusy  = false;

    function nameOf(id) { return clNames[String(id)] || 'The company'; }

    // What changed between two snapshots → list of popups.
    function describeChanges(prev, next) {
        var out = [];
        var prevReg = prev.registered ? String(prev.registered) : null;
        var nextReg = next.registered ? String(next.registered) : null;
        if (nextReg && nextReg !== prevReg) {
            out.push({ name: nameOf(nextReg), text: 'accepted your application \u2014 you are now registered as their OJT trainee.', icon: 'fa-circle-check' });
        }
        if (prevReg && prevReg !== nextReg) {
            out.push({ name: nameOf(prevReg), text: 'no longer has you registered as their OJT trainee.', icon: 'fa-circle-info' });
        }
        var pp = prev.pending || {}, np = next.pending || {};
        Object.keys(pp).forEach(function (id) {
            if (np[id] === pp[id]) return;
            if (!np[id]) {
                if (id === nextReg) return; // accepted — already announced above
                out.push(pp[id] === 'admin'
                    ? { name: nameOf(id), text: '\u2014 your application was not approved by the administrator.', icon: 'fa-circle-xmark' }
                    : { name: nameOf(id), text: 'did not accept your application.', icon: 'fa-circle-xmark' });
            } else if (pp[id] === 'admin' && np[id] === 'company') {
                out.push({ name: nameOf(id), text: '\u2014 the administrator approved your application. It is now Under Company Validation; your endorsement letter is in your Inbox.', icon: 'fa-envelope-circle-check' });
            }
        });
        Object.keys(np).forEach(function (id) {
            if (!pp[id]) out.push(np[id] === 'company'
                // ADJUSTMENT: applied by the administrator (monitoring dashboard) — straight to the company.
                ? { name: nameOf(id), text: '\u2014 the administrator applied you to this company. It is now Under Company Validation; your endorsement letter is in your Inbox.', icon: 'fa-envelope-circle-check' }
                : { name: nameOf(id), text: '\u2014 your application was sent and is Waiting for the Approval.', icon: 'fa-paper-plane' });
        });
        return out;
    }

    // Re-renders the company panel list from the server, keeping open panels open.
    function refreshCompanyList() {
        var open = Array.prototype.map.call(document.querySelectorAll('.company-row .toggle-input:checked'), function (i) { return i.id; });
        showGlobalLoading('Updating company list');
        return fetch(window.location.pathname, { credentials: 'same-origin', cache: 'no-store' })
            .then(function (r) { return r.text(); })
            .then(function (html) {
                var doc = new DOMParser().parseFromString(html, 'text/html');
                var fresh = doc.querySelector('.page-inner');
                var cur = document.querySelector('.page-inner');
                if (fresh && cur) {
                    cur.innerHTML = fresh.innerHTML;
                    open.forEach(function (id) { var t = document.getElementById(id); if (t) t.checked = true; });
                }
            })
            .catch(function () {})
            .finally(function () { hideGlobalLoading(); });
    }

    function pollApplicationState() {
        if (clBusy || document.hidden) return;
        clBusy = true;
        fetch('company_list.php?poll_application=1', { credentials: 'same-origin', cache: 'no-store' })
            .then(function (r) { return r.ok ? r.json() : null; })
            .then(function (next) {
                if (!next) return;
                Object.assign(clNames, next.names || {});
                var events = describeChanges(clState, next);
                var regChanged = String(clState.registered || '') !== String(next.registered || '');
                clState = next;
                if (!events.length) return;
                if (regChanged) {
                    // Sidebar pages (Attendance / Reports / Dashboard) depend on registration → reload.
                    try { sessionStorage.setItem(CL_LIVE_TOASTS_KEY, JSON.stringify(events)); } catch (e) {}
                    startNavigationGlobalLoading('Updating');
                    window.location.reload();
                    return;
                }
                return refreshCompanyList().then(function () {
                    events.forEach(function (ev) { clShowTopToast(ev.name, ev.text, ev.icon); });
                });
            })
            .catch(function () { /* silent — retried on the next tick */ })
            .finally(function () { clBusy = false; });
    }

    setInterval(pollApplicationState, 5000);
    document.addEventListener('visibilitychange', function () { if (!document.hidden) pollApplicationState(); });

    // ADJUSTMENT: a server error from the last Apply / Cancel (e.g. "You already applied to this
    // company.") is shown as a popup notification once the loading page has cleared.
    var srvErr = document.getElementById('clServerError');
    if (srvErr && srvErr.dataset.msg) {
        setTimeout(function () { clShowTopToast('', srvErr.dataset.msg, 'fa-circle-xmark', true); }, 450);
        srvErr.remove();
    }

    // Popups carried across a registration reload.
    try {
        var saved = JSON.parse(sessionStorage.getItem(CL_LIVE_TOASTS_KEY) || 'null');
        sessionStorage.removeItem(CL_LIVE_TOASTS_KEY);
        if (saved && saved.length) {
            setTimeout(function () { saved.forEach(function (ev) { clShowTopToast(ev.name, ev.text, ev.icon); }); }, 450);
        }
    } catch (e) {}
})();
</script>
</body>
</html>