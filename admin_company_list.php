<?php
session_start();
// ── NEW (this adjustment): LOGOUT + BACK BUTTON — never let the browser keep a copy of this page.
// After the admin logs out, pressing the browser's Back arrow used to show this page again from the
// browser's memory. With these headers the browser always asks the server again, and the server
// (the session check below) sends a logged-out visitor to admin_login.php to sign in again.
// Only applied to the page itself (a top-level page load), not to files / images / AJAX it serves.
if (($_SERVER['HTTP_SEC_FETCH_DEST'] ?? 'document') === 'document' && !headers_sent()) {
    header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
    header('Pragma: no-cache');
    header('Expires: Sat, 01 Jan 2000 00:00:00 GMT');
}
include "db.php";

/* ════════════════════════════════════════════════════════════════════
   NEW: PHPMailer includes — needed for Phase 2 ("Create Account"),
   which emails each newly-created company its login password, exactly
   mirroring the email flow already used in company_register.php.
   NOTE: These classes are referenced below using their FULLY QUALIFIED
   names (\PHPMailer\PHPMailer\PHPMailer / \PHPMailer\PHPMailer\Exception)
   rather than a `use` import, specifically so the existing
   `catch (Exception $e)` block further down in the XLSX import handler
   (which must keep catching plain global \Exception from PhpSpreadsheet)
   is never accidentally shadowed/redirected to PHPMailer's Exception
   class. This is purely additive — nothing else on this page is
   affected by these requires.
   ════════════════════════════════════════════════════════════════════ */
require_once 'PHPMailer/src/PHPMailer.php';
require_once 'PHPMailer/src/SMTP.php';
require_once 'PHPMailer/src/Exception.php';

// Increase PHP limits for large XLSX imports (mirrors admin_student_list.php)
ini_set('upload_max_filesize', '100M');
ini_set('post_max_size', '100M');
ini_set('memory_limit', '256M');
ini_set('max_execution_time', 300);
ini_set('max_input_time', 300);

if (
    (!isset($_SESSION['admin_id']) && !isset($_SESSION['user_id'])) ||
    ($_SESSION['role'] ?? '') !== "admin"
) {
    header("Location: admin_login.php");
    exit;
}

// ============================================================================
// NEW (this adjustment): NO NOTIFICATIONS LEFT BEHIND BY DELETED ACCOUNTS
// ----------------------------------------------------------------------------
// When a student or company account is deleted (Student List, Company List or
// Manage Accounts), its notifications must disappear from the inboxes of
// administrator.php / company_validation.php and from every side-menu
// indicator. The company delete paths now remove them directly; this sweep also
// clears any that were already left behind (or come from any other path). It
// removes ONLY notification rows whose account no longer exists in `users`:
//   • company_requirement_upload_notifications  (Company Requirements inbox + badge)
//   • admin_application_approvals              (Application Requests inbox + badge)
//   • moa_requests                             (MOA notifications — rows tied to an account)
//   • email_recovery_requests, Pending only    (Manage Accounts indicator)
// Archiving never deletes an account, so archived students / companies are never
// touched. Runs at most once every 15 seconds per admin; fully guarded.
// ============================================================================
if (!function_exists('cv_sweep_orphan_notifications')) {
    function cv_sweep_orphan_notifications($conn) {
        $has = function ($t) use ($conn) {
            try { $r = $conn->query("SHOW TABLES LIKE '" . $conn->real_escape_string($t) . "'"); return $r && $r->num_rows > 0; }
            catch (\Throwable $e) { return false; }
        };
        $run = function ($sql) use ($conn) { try { $conn->query($sql); } catch (\Throwable $e) { /* never affects the page */ } };
        if (!$has('users')) return;
        if ($has('company_requirement_upload_notifications'))
            $run("DELETE n FROM company_requirement_upload_notifications n LEFT JOIN users u ON u.id = n.user_id WHERE u.id IS NULL");
        if ($has('admin_application_approvals'))
            $run("DELETE a FROM admin_application_approvals a LEFT JOIN users s ON s.id = a.student_id LEFT JOIN users c ON c.id = a.company_id WHERE s.id IS NULL OR c.id IS NULL");
        if ($has('moa_requests'))
            $run("DELETE m FROM moa_requests m LEFT JOIN users u ON u.id = m.user_id WHERE m.user_id > 0 AND u.id IS NULL");
        if ($has('email_recovery_requests'))
            $run("DELETE r FROM email_recovery_requests r WHERE r.status = 'Pending' AND NOT EXISTS (SELECT 1 FROM users u WHERE u.email = r.old_email OR u.email = r.new_email)");
    }
}
try {
    $cvSweepNow = time();
    if (!isset($_SESSION['cv_orphan_sweep_at']) || $cvSweepNow - (int)$_SESSION['cv_orphan_sweep_at'] >= 15) {
        $_SESSION['cv_orphan_sweep_at'] = $cvSweepNow;
        cv_sweep_orphan_notifications($conn);
    }
} catch (\Throwable $e) { /* never affects the page */ }

// ── NEW (this adjustment): EMAIL RECOVERY REQUESTS side-menu indicator ─────────
// Number of Pending rows in email_recovery_requests (the requests handled in
// Manage Accounts > Email Recovery Requests on monitoring.php). Shown as a badge
// on the "Manage Accounts" link, the same way the Student Requirements link
// shows the application-request count. Read-only and fully guarded — if the
// table does not exist yet it is simply 0.
$recovery_pending_count = 0;
try {
    $rpTbl = $conn->query("SHOW TABLES LIKE 'email_recovery_requests'");
    if ($rpTbl && $rpTbl->num_rows > 0) {
        $rpRes = $conn->query("SELECT COUNT(*) AS total FROM email_recovery_requests WHERE status = 'Pending'");
        if ($rpRes) $recovery_pending_count = (int)($rpRes->fetch_assoc()['total'] ?? 0);
    }
} catch (\Throwable $e) { $recovery_pending_count = 0; }

// ============================================================================
// NEW (this adjustment): ACTIVITY LOG — this page's actions are recorded in
// the Live Activity Log on monitoring.php (the same `activity_logs` table and
// the same columns monitoring.php already writes: action_type, performed_by,
// account_type, account_name, details, created_at).
// ----------------------------------------------------------------------------
// • Only actions that CHANGE something are recorded (see the rules list for
//   this page below); look-ups, polling, previews and exports never are.
// • The name of the student / company / item is read BEFORE the action runs
//   (so it is still available after a delete).
// • The entry is written only if the action actually SUCCEEDED: a JSON reply
//   with success:false, an error message / "…_message_type = error", an
//   error redirect or an HTTP error status means nothing is logged.
// • Account deletions on the Student / Company List were already logged by
//   those pages themselves and are not logged a second time.
// • Fully guarded: a logging problem can never break or change the action.
// ============================================================================
if (!function_exists('cv_alog_capture')) {
    function cv_alog_post($k) { return trim((string)($_POST[$k] ?? '')); }
    function cv_alog_is_post($k) { return ($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && isset($_POST[$k]); }
    function cv_alog_is_get_post($getKey) { return ($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && isset($_GET[$getKey]) && (string)$_GET[$getKey] === '1'; }
    function cv_alog_join_name($f, $m, $l) { return preg_replace('/\s+/', ' ', trim(trim((string)$f) . ' ' . trim((string)$m) . ' ' . trim((string)$l))); }
    function cv_alog_scalar($conn, $sql, $types = '', $params = []) {
        try {
            $st = $conn->prepare($sql);
            if (!$st) return null;
            if ($types !== '') $st->bind_param($types, ...$params);
            $st->execute();
            $res = $st->get_result();
            $row = $res ? $res->fetch_row() : null;
            $st->close();
            return $row ? $row[0] : null;
        } catch (\Throwable $e) { return null; }
    }
    function cv_alog_user_name($conn, $id) {
        $id = (int)$id; if ($id <= 0) return '';
        try {
            $st = $conn->prepare("SELECT first_name, middle_name, last_name FROM users WHERE id = ?");
            if (!$st) return '';
            $st->bind_param('i', $id); $st->execute();
            $r = $st->get_result()->fetch_assoc(); $st->close();
            return $r ? cv_alog_join_name($r['first_name'] ?? '', $r['middle_name'] ?? '', $r['last_name'] ?? '') : '';
        } catch (\Throwable $e) { return ''; }
    }
    function cv_alog_company_name($conn, $userId) {
        $n = (string)cv_alog_scalar($conn, "SELECT company FROM company_information WHERE user_id = ? LIMIT 1", 'i', [(int)$userId]);
        return $n !== '' ? $n : cv_alog_user_name($conn, $userId);
    }
    function cv_alog_list($names, $max = 3) {
        $names = array_values(array_filter(array_map('strval', $names), 'strlen'));
        if (!$names) return '';
        $shown = array_slice($names, 0, $max);
        $more = count($names) - count($shown);
        return implode(', ', $shown) . ($more > 0 ? " and $more more" : '');
    }
    function cv_alog_plural($n, $one, $many) { return $n . ' ' . ($n == 1 ? $one : $many); }
    function cv_alog_status_word($status) {
        $s = strtolower(trim((string)$status));
        $map = ['verified' => 'Verified', 'approved' => 'Approved', 'rejected' => 'Rejected', 'pending' => 'Set to Pending', 'complied' => 'Complied'];
        return $map[$s] ?? ($s !== '' ? ucwords($s ?? '') : 'Updated');
    }
    function cv_alog_performer($conn) {
        $n = trim((string)($GLOBALS['adminFullName'] ?? ''));
        if ($n !== '' && strcasecmp($n, 'Administrator') !== 0) return $n;
        $n = trim(($_SESSION['first_name'] ?? '') . ' ' . ($_SESSION['last_name'] ?? ''));
        $aid = (int)($_SESSION['admin_id'] ?? $_SESSION['user_id'] ?? 0);
        if ($aid > 0) {
            try {
                $st = $conn->prepare("SELECT first_name, middle_name, last_name FROM admins WHERE id = ?");
                if ($st) {
                    $st->bind_param('i', $aid); $st->execute();
                    $r = $st->get_result()->fetch_assoc(); $st->close();
                    if ($r) { $full = cv_alog_join_name($r['first_name'] ?? '', $r['middle_name'] ?? '', $r['last_name'] ?? ''); if ($full !== '') return $full; }
                }
            } catch (\Throwable $e) {}
        }
        return $n !== '' ? $n : 'Admin';
    }
    // Decide whether the finished request succeeded, from what it sent back.
    function cv_alog_succeeded($content, &$json) {
        $json = null;
        $code = function_exists('http_response_code') ? (int)http_response_code() : 200;
        if ($code >= 400) return false;
        foreach (headers_list() as $h) {
            if (stripos($h, 'Location:') === 0 && preg_match('/[?&](error|err|fail|failed|invalid)[^&]*=/i', $h)) return false;
        }
        $body = ltrim((string)$content, "\xEF\xBB\xBF \t\r\n");
        if ($body !== '' && ($body[0] === '{' || $body[0] === '[')) {
            $j = json_decode($body, true);
            if (is_array($j)) {
                $json = $j;
                if (array_key_exists('success', $j)) return (bool)$j['success'];
                if (array_key_exists('ok', $j)) return (bool)$j['ok'];
                if (!empty($j['error'])) return false;
                return true;
            }
        }
        if ($body !== '' && strlen($body) < 200 && preg_match('/^(invalid|error|fail|unauthori|forbidden|not allowed)/i', $body)) return false;
        foreach ($GLOBALS as $k => $v) {
            if (is_string($k) && substr($k, -13) === '_message_type' && $v === 'error') return false;
        }
        if (!empty($GLOBALS['error']) && is_string($GLOBALS['error'])) return false;
        return true;
    }
    function cv_alog_cut($s, $n) { $s = (string)$s; return function_exists('mb_substr') ? mb_substr($s, 0, $n) : substr($s, 0, $n); }
    function cv_alog_write($conn, $rec, $performer) {
        $st = $conn->prepare("INSERT INTO activity_logs (action_type, performed_by, account_type, account_name, details, created_at) VALUES (?, ?, ?, ?, ?, NOW())");
        if (!$st) return;
        $type = cv_alog_cut($rec[0], 100); $acct = cv_alog_cut($rec[1], 100);
        $name = cv_alog_cut($rec[2], 255); $det  = cv_alog_cut($rec[3], 1000); $performer = cv_alog_cut($performer, 255);
        $st->bind_param('sssss', $type, $performer, $acct, $name, $det);
        $st->execute(); $st->close();
    }
    // $rules: list of [matcher(): bool, builder($conn): ?[action_type, account_type, account_name, details], optional after($rec, $json): ?array]
    function cv_alog_capture($conn, $rules) {
        try {
            foreach ($rules as $rule) {
                if (!call_user_func($rule[0])) continue;
                $rec = call_user_func($rule[1], $conn);
                if (!is_array($rec)) return;
                $after = $rule[2] ?? null;
                ob_start();
                $level = ob_get_level();
                register_shutdown_function(function () use ($rec, $after, $level) {
                    try {
                        $content = null;
                        if (ob_get_level() >= $level) {
                            while (ob_get_level() > $level) ob_end_flush();   // same output, same order — just collected into this buffer first
                            $content = ob_get_contents();
                        }
                        $json = null;
                        if (!cv_alog_succeeded((string)$content, $json)) return;
                        if ($after) { $rec = call_user_func($after, $rec, $json); if (!is_array($rec)) return; }
                        $c = $GLOBALS['conn'] ?? null; $alive = false;
                        try { $alive = ($c instanceof \mysqli) && $c->query('SELECT 1'); } catch (\Throwable $e) { $alive = false; }
                        if (!$alive) { $c = (function () { $conn = null; include __DIR__ . '/db.php'; return $conn; })(); }
                        if (!($c instanceof \mysqli)) return;
                        cv_alog_write($c, $rec, cv_alog_performer($c));
                    } catch (\Throwable $e) { /* logging must never affect the page */ }
                });
                return;
            }
        } catch (\Throwable $e) { /* logging must never affect the page */ }
    }
}

cv_alog_capture($conn, [
    [function () { return cv_alog_is_post('add_company_manual'); }, function ($conn) {
        $n = cv_alog_post('company_name');
        return ['Company Added', 'Company', $n, "Added company $n via Company List"];
    }],
    [function () { return cv_alog_is_post('edit_company_import'); }, function ($conn) {
        $n = cv_alog_post('edit_company_name');
        if ($n === '') { $uid = (int)($_POST['edit_user_id'] ?? 0); $iid = (int)($_POST['edit_import_id'] ?? 0);
            $n = $uid > 0 ? cv_alog_company_name($conn, $uid) : (string)cv_alog_scalar($conn, "SELECT company FROM companies_import WHERE id = ?", 'i', [$iid]); }
        return ['Company Updated', 'Company', $n, "Updated the details of $n via Company List"];
    }],
    [function () { return cv_alog_is_post('update_registered_request_type'); }, function ($conn) {
        $n = cv_alog_company_name($conn, (int)($_POST['reg_request_type_user_id'] ?? 0));
        $v = cv_alog_post('reg_request_type_value');
        return ['Request Type Updated', 'Company', $n, "Set the request type of $n to " . ($v !== '' ? ucwords($v ?? '') : '—') . " via Company List"];
    }],
    [function () { return cv_alog_is_post('upload_admin_moa'); }, function ($conn) {
        $uid = (int)($_POST['admin_moa_user_id'] ?? 0); $iid = (int)($_POST['admin_moa_import_id'] ?? 0);
        $n = $uid > 0 ? cv_alog_company_name($conn, $uid) : (string)cv_alog_scalar($conn, "SELECT company FROM companies_import WHERE id = ?", 'i', [$iid]);
        return ['MOA Uploaded', 'Company', $n, "Uploaded an MOA for $n via Company List"];
    }],
    [function () { return cv_alog_is_post('delete_selected_companies') && !empty($_POST['company_ids']) && is_array($_POST['company_ids']); }, function ($conn) {
        // imported (not yet registered) company entries only — deleted company ACCOUNTS are already logged by this page
        $names = [];
        foreach ($_POST['company_ids'] as $raw) { $id = (int)$raw; if ($id > 0) $names[] = (string)cv_alog_scalar($conn, "SELECT company FROM companies_import WHERE id = ?", 'i', [$id]); }
        $c = count($names);
        return ['Companies Deleted', 'Company', cv_alog_list($names), "Deleted " . cv_alog_plural($c, 'imported company entry', 'imported company entries') . " (" . cv_alog_list($names) . ") via Company List"];
    }],
    [function () { return cv_alog_is_post('create_accounts_selected'); }, function ($conn) {
        $names = [];
        foreach ((array)($_POST['import_ids'] ?? []) as $raw) { $id = (int)$raw; if ($id > 0) $names[] = (string)cv_alog_scalar($conn, "SELECT company FROM companies_import WHERE id = ?", 'i', [$id]); }
        $c = count((array)($_POST['import_ids'] ?? []));
        return ['Company Accounts Created', 'Company', cv_alog_list($names) ?: cv_alog_plural($c, 'company', 'companies'), "Created company accounts for " . cv_alog_plural($c, 'selected company', 'selected companies') . (cv_alog_list($names) !== '' ? ' (' . cv_alog_list($names) . ')' : '') . " via Company List"];
    }],
    [function () { return ($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && isset($_FILES['company_import_file']) && !isset($_POST['detect_company_import_classifications']); }, function ($conn) {
        $f = (string)($_FILES['company_import_file']['name'] ?? 'spreadsheet');
        return ['Companies Imported', 'Company', $f, "Imported companies from $f via Company List"];
    }, function ($rec, $json) { $m = trim(strip_tags(str_replace('<br>', ' ', (string)($GLOBALS['company_import_message'] ?? '')))); if ($m !== '') $rec[3] .= ' — ' . $m; return $rec; }],
]);

/* ════════════════════════════════════════════════════════════════════
   STREAM MOA PDF — PDF ONLY.
   Reads the MOA blob straight from company_requirements
   (requirement_type='moa_document'), the exact table
   company_validation.php's transferMoaToRequirements() already writes
   into automatically the moment an admin accepts a MOA request (new OR
   existing). This endpoint refuses to render anything whose actual mime
   type isn't application/pdf — it does not trust the file extension.
   ════════════════════════════════════════════════════════════════════ */
if (isset($_GET['view_moa_pdf'])) {
    $user_id = (int) $_GET['view_moa_pdf'];
    if (!$user_id) { http_response_code(400); exit('Invalid company.'); }

    $stmt = $conn->prepare("SELECT file_name FROM company_requirements WHERE user_id=? AND requirement_type='moa_document'");
    $stmt->bind_param("i", $user_id);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (empty($row) || empty($row['file_name'])) {
        http_response_code(404);
        exit('No MOA document on file for this company.');
    }

    $blob  = $row['file_name'];
    $finfo = new finfo(FILEINFO_MIME_TYPE);
    $mime  = $finfo->buffer($blob);

    if ($mime !== 'application/pdf') {
        http_response_code(415);
        exit('This MOA record is not a PDF file. Only PDF documents can be previewed here.');
    }

    header('Content-Type: application/pdf');
    header('Content-Disposition: inline; filename="moa_document_' . $user_id . '.pdf"');
    header('Content-Length: ' . strlen($blob));
    header('Cache-Control: private, max-age=300');
    echo $blob;
    exit;
}

/* ════════════════════════════════════════════════════════════════════
   NEW (Existing Partnership MOA revision): STREAM the "MOA Document
   (Existing Partnership)" file (company_requirements.requirement_type =
   'moa_existing_upload') of a REGISTERED company whose request type is
   "Existing". This is the MOA an Existing company uploads on
   CompanyForm.php, so it is now opened from the table's MOA Document
   column (View / Download) instead of the Requirements viewer.
   The most recent submitted file is used. The real mime type is
   detected from the file (PDF or image). When an IMAGE is previewed
   (no &raw=1 / &download=1), a tiny HTML page is returned that shows
   it centered in the preview, instead of the browser pinning it to the
   top-left corner.
   ════════════════════════════════════════════════════════════════════ */
if (isset($_GET['view_moa_existing'])) {
    $user_id = (int) $_GET['view_moa_existing'];
    if (!$user_id) { http_response_code(400); exit('Invalid company.'); }

    $stmt = $conn->prepare("SELECT id, file_name FROM company_requirements
        WHERE user_id=? AND requirement_type='moa_existing_upload' AND file_name IS NOT NULL AND LENGTH(file_name) > 0
        ORDER BY id DESC LIMIT 1");
    $stmt->bind_param("i", $user_id);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (empty($row) || empty($row['file_name'])) {
        http_response_code(404);
        exit('No MOA Document (Existing Partnership) on file for this company.');
    }

    $blob  = $row['file_name'];
    $finfo = new finfo(FILEINFO_MIME_TYPE);
    $mime  = $finfo->buffer($blob);
    if (!$mime || $mime === 'application/octet-stream') $mime = 'application/pdf';

    $isDownload = isset($_GET['download']);
    $isRaw      = isset($_GET['raw']);

    if (strpos($mime, 'image/') === 0 && !$isDownload && !$isRaw) {
        $rawUrl = '?view_moa_existing=' . $user_id . '&raw=1';
        header('Content-Type: text/html; charset=UTF-8');
        echo '<!DOCTYPE html><html><head><meta charset="UTF-8"><title>MOA Document (Existing Partnership)</title>'
           . '<style>html,body{margin:0;height:100%;background:#fff;}body{display:flex;align-items:center;justify-content:center;padding:24px;box-sizing:border-box;}'
           . 'img{max-width:100%;max-height:100%;object-fit:contain;box-shadow:0 2px 14px rgba(27,42,74,0.12);}</style></head>'
           . '<body><img src="' . htmlspecialchars($rawUrl ?? '', ENT_QUOTES) . '" alt="MOA Document (Existing Partnership)"></body></html>';
        exit;
    }

    $extMap = ['application/pdf' => 'pdf', 'image/jpeg' => 'jpg', 'image/png' => 'png', 'image/gif' => 'gif', 'image/webp' => 'webp'];
    $ext = $extMap[$mime] ?? 'bin';
    header('Content-Type: ' . $mime);
    header('Content-Disposition: ' . ($isDownload ? 'attachment' : 'inline') . '; filename="moa_existing_partnership_' . $user_id . '.' . $ext . '"');
    header('Content-Length: ' . strlen($blob));
    header('Cache-Control: private, max-age=300');
    echo $blob;
    exit;
}

/* ════════════════════════════════════════════════════════════════════
   NEW: STREAM MOA PDF for IMPORTED / MANUALLY-ADDED companies.
   Mirrors view_moa_pdf above exactly, except the blob lives on the
   companies_import row itself (companies_import.moa_document), since
   these companies don't have a user account / company_requirements row
   yet. Only used for imported companies that were manually added with
   "Existing Company (has MOA)" selected and a PDF uploaded.
   ════════════════════════════════════════════════════════════════════ */
if (isset($_GET['view_moa_pdf_import'])) {
    $import_id = (int) $_GET['view_moa_pdf_import'];
    if (!$import_id) { http_response_code(400); exit('Invalid company.'); }

    $stmt = $conn->prepare("SELECT moa_document FROM companies_import WHERE id=? AND has_moa=1");
    $stmt->bind_param("i", $import_id);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (empty($row) || empty($row['moa_document'])) {
        http_response_code(404);
        exit('No MOA document on file for this company.');
    }

    $blob  = $row['moa_document'];
    $finfo = new finfo(FILEINFO_MIME_TYPE);
    $mime  = $finfo->buffer($blob);

    if ($mime !== 'application/pdf') {
        http_response_code(415);
        exit('This MOA record is not a PDF file. Only PDF documents can be previewed here.');
    }

    header('Content-Type: application/pdf');
    header('Content-Disposition: inline; filename="moa_document_import_' . $import_id . '.pdf"');
    header('Content-Length: ' . strlen($blob));
    header('Cache-Control: private, max-age=300');
    echo $blob;
    exit;
}

/* ════════════════════════════════════════════════════════════════════
   NEW: STREAM ADMIN COPY OF MOA PDF — for REGISTERED companies.
   This is a separate PDF the admin uploads themselves (independent of
   the company's own submitted MOA streamed by view_moa_pdf above),
   stored in company_requirements under requirement_type =
   'admin_moa_document'. Same PDF-only guard as every other MOA stream
   on this page — the actual mime type is checked, not the extension.
   ════════════════════════════════════════════════════════════════════ */
if (isset($_GET['view_admin_moa_pdf'])) {
    $user_id = (int) $_GET['view_admin_moa_pdf'];
    if (!$user_id) { http_response_code(400); exit('Invalid company.'); }

    $stmt = $conn->prepare("SELECT file_name FROM company_requirements WHERE user_id=? AND requirement_type='admin_moa_document'");
    $stmt->bind_param("i", $user_id);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (empty($row) || empty($row['file_name'])) {
        http_response_code(404);
        exit('No admin copy of the MOA document on file for this company.');
    }

    $blob  = $row['file_name'];
    $finfo = new finfo(FILEINFO_MIME_TYPE);
    $mime  = $finfo->buffer($blob);

    if ($mime !== 'application/pdf') {
        http_response_code(415);
        exit('This MOA record is not a PDF file. Only PDF documents can be previewed here.');
    }

    header('Content-Type: application/pdf');
    header('Content-Disposition: inline; filename="admin_moa_copy_' . $user_id . '.pdf"');
    header('Content-Length: ' . strlen($blob));
    header('Cache-Control: private, max-age=300');
    echo $blob;
    exit;
}

/* ════════════════════════════════════════════════════════════════════
   NEW: STREAM ADMIN COPY OF MOA PDF — for IMPORTED / MANUALLY-ADDED
   companies. Mirrors the registered-company version above exactly,
   except the blob lives on companies_import.admin_moa_document.
   ════════════════════════════════════════════════════════════════════ */
if (isset($_GET['view_admin_moa_pdf_import'])) {
    $import_id = (int) $_GET['view_admin_moa_pdf_import'];
    if (!$import_id) { http_response_code(400); exit('Invalid company.'); }

    $stmt = $conn->prepare("SELECT admin_moa_document FROM companies_import WHERE id=?");
    $stmt->bind_param("i", $import_id);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (empty($row) || empty($row['admin_moa_document'])) {
        http_response_code(404);
        exit('No admin copy of the MOA document on file for this company.');
    }

    $blob  = $row['admin_moa_document'];
    $finfo = new finfo(FILEINFO_MIME_TYPE);
    $mime  = $finfo->buffer($blob);

    if ($mime !== 'application/pdf') {
        http_response_code(415);
        exit('This MOA record is not a PDF file. Only PDF documents can be previewed here.');
    }

    header('Content-Type: application/pdf');
    header('Content-Disposition: inline; filename="admin_moa_copy_import_' . $import_id . '.pdf"');
    header('Content-Length: ' . strlen($blob));
    header('Cache-Control: private, max-age=300');
    echo $blob;
    exit;
}

/* ════════════════════════════════════════════════════════════════════
   NEW (Requirements column revision): STREAM a single compliance
   requirement FILE for a REGISTERED company — the read-only counterpart
   to company_validation.php's own stream_req_blob endpoint, used by the
   new "Requirements" column's "View Requirements" modal below. Streams
   straight from company_requirements by its own row id, always
   cross-checked against the given user_id so a file can only ever be
   opened via a company it actually belongs to.

   Deliberately excludes requirement_type IN ('moa_document','moa') —
   the MOA document already has its own dedicated column/endpoints
   (view_moa_pdf / view_admin_moa_pdf above); this endpoint only ever
   serves the OTHER compliance requirements company_validation.php's
   "Requirements" tab manages, mirroring that page's own logic 1:1
   without duplicating or touching its actual verify/deny workflow —
   this page only ever READS these files, never changes their status.

   Unlike the MOA endpoints above, a compliance requirement's file is
   not guaranteed to be a PDF (CompanyForm.php accepts images too), so
   the mime type is detected from the file itself (falling back to PDF
   only when detection genuinely can't tell) rather than being rejected
   outright when it isn't application/pdf — matching exactly how
   company_validation.php's own stream_req_blob already behaves for
   these same files.
   ════════════════════════════════════════════════════════════════════ */
if (isset($_GET['view_requirement_file'])) {
    $req_file_id = (int) $_GET['view_requirement_file'];
    $req_user_id = isset($_GET['req_user_id']) ? (int) $_GET['req_user_id'] : 0;
    if (!$req_file_id || !$req_user_id) { http_response_code(400); exit('Invalid request.'); }

    $stmt = $conn->prepare("SELECT file_name, requirement_type FROM company_requirements WHERE id=? AND user_id=? AND requirement_type NOT IN ('moa_document','moa')");
    $stmt->bind_param("ii", $req_file_id, $req_user_id);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (empty($row) || empty($row['file_name'])) {
        http_response_code(404);
        exit('No file found for this requirement.');
    }

    $blob  = $row['file_name'];
    $finfo = new finfo(FILEINFO_MIME_TYPE);
    $mime  = $finfo->buffer($blob);
    if (!$mime || $mime === 'application/octet-stream') $mime = 'application/pdf';

    header('Content-Type: ' . $mime);
    header('Content-Disposition: inline; filename="' . preg_replace('/[^a-zA-Z0-9_-]/', '_', (string) $row['requirement_type']) . '_' . $req_file_id . '"');
    header('Content-Length: ' . strlen($blob));
    header('Cache-Control: private, max-age=300');
    echo $blob;
    exit;
}

/* ════════════════════════════════════════════════════════════════════
   NEW: companies_import — a staging table for companies added either by
   manual entry or by XLSX import, mirroring the exact pattern used by
   students_import in admin_student_list.php. These are companies that do
   NOT yet have a user account (no row in `users`/`company_information`),
   so they are display-only entries in the list below — no validation
   status applies to them until the company itself registers and goes
   through the normal validation/MOA workflow. The only exception is the
   MOA document itself: when an admin manually adds a company that is
   already known to have a signed MOA, the PDF can be attached directly
   to the imported row (has_moa / moa_document) so it can still be
   previewed/downloaded here. Company name is the unique key so
   re-importing the same company updates its details instead of
   duplicating rows.

   NEW (this revision): a `request_type` column ('New' or 'Existing')
   is now tracked on imported rows too — either supplied as a column in
   the XLSX import file, or derived from the "Company Status" choice
   made in the manual Add Company form — so the Request Type filter/
   column in the table below also works for imported companies.

   NEW (Company Type revision): a `company_type` column ('Public' or
   'Private') is now tracked on imported rows as well — supplied either
   as a column in the XLSX import file, or chosen from the "Company
   Type" dropdown in the manual Add Company form / Edit Imported Company
   modal — so a new "Company Type" column now sits in the table between
   "Validation" and "Request Type" for both imported AND registered
   companies (registered companies carry this on the users table — see
   the ALTER TABLE for that table further below).

   IMPORTANT — file separation: the company's own MOA document
   (has_moa / moa_document) and the Admin Copy of MOA
   (admin_moa_document) are, and always have been, two entirely
   separate PDF blobs/columns:
     - moa_document       → historically populated ONLY from the "MOA
                             Document (PDF)" upload field on the manual
                             "Add Company" form. UPDATED (this revision):
                             that upload field has been removed from the
                             manual Add Company form entirely (see the
                             Company Status section below) — manually
                             added companies no longer carry a
                             moa_document/has_moa value from this form.
                             This column is still populated for
                             companies brought in via XLSX import (when
                             the import file supplies MOA data through
                             its own mechanism) and its Edit-modal
                             equivalent is intentionally absent — the
                             company's own MOA can never be replaced
                             from this screen.
     - admin_moa_document  → populated ONLY from the "Admin Copy of MOA"
                             upload/replace control that lives in the
                             table itself (Upload Copy / Replace button
                             in the "Admin Copy of MOA" column, or the
                             optional "Replace Admin Copy of MOA" field
                             in the Edit modal).
   This separation is preserved exactly as-is below.
   ════════════════════════════════════════════════════════════════════ */
$conn->query("CREATE TABLE IF NOT EXISTS companies_import (
    id INT AUTO_INCREMENT PRIMARY KEY,
    company VARCHAR(200) NOT NULL,
    company_address VARCHAR(300),
    telephone VARCHAR(50),
    contact_first_name VARCHAR(100),
    contact_middle_name VARCHAR(100),
    contact_last_name VARCHAR(100),
    position VARCHAR(150),
    request_type VARCHAR(20) NULL,
    has_moa TINYINT(1) NOT NULL DEFAULT 0,
    moa_document LONGBLOB NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY unique_company (company)
)");

/* Upgrade path for installs whose companies_import table was created
   before this revision: has_moa / moa_document / request_type are new.
   Each statement is wrapped so a missing/duplicate column never fatals
   the page.

   ════════════════════════════════════════════════════════════════════
   FIX (critical data-loss bug — Company Type appearing blank after
   export / after manually adding or importing a company):

   This block used to ALSO run:

       ALTER TABLE companies_import DROP COLUMN company_type

   unconditionally, on EVERY single page load — including every AJAX
   request this page handles (table search/filter/pagination refresh,
   Add Company, Delete, Edit, Admin MOA upload, etc. all POST/GET back
   to this exact same file). That DROP was originally written as a
   ONE-TIME migration step to remove an old, unrelated "company type"
   concept from a much earlier revision of this table (see the comment
   on the companies_import table above). But a brand-new `company_type`
   column (Public/Private) was later (re)introduced further below using
   that EXACT SAME column name. MySQL has no way to tell "the old,
   unrelated column" apart from "the new Public/Private column" — a
   DROP COLUMN company_type always drops whichever column is currently
   named that, no matter which "concept" it represents.

   The practical effect: on every request to this page, the DROP ran
   first and silently deleted the CURRENT company_type column (and
   every row's value in it), and then — a few lines further down in
   this same block — `ADD COLUMN company_type VARCHAR(20) NULL` ran
   again and recreated the column completely empty. This is exactly why
   the exported XLSX always showed a blank Company Type, and why a
   manually-added or imported company's Company Type appeared to
   "vanish" almost immediately — the very next request to this page
   (even just the automatic AJAX table refresh that fires right after
   saving) wiped the column back to empty again.

   The DROP COLUMN line has been removed entirely below. It was only
   ever meant to run once, a long time ago, and should never have been
   left running unconditionally on every load. Nothing else in this
   upgrade-path block has changed.
   ════════════════════════════════════════════════════════════════════ */
try { $conn->query("ALTER TABLE companies_import ADD COLUMN has_moa TINYINT(1) NOT NULL DEFAULT 0"); } catch (\Throwable $e) {}
try { $conn->query("ALTER TABLE companies_import ADD COLUMN moa_document LONGBLOB NULL"); } catch (\Throwable $e) {}
try { $conn->query("ALTER TABLE companies_import ADD COLUMN request_type VARCHAR(20) NULL"); } catch (\Throwable $e) {}
/* NEW: Email address for the company/contact, captured on both the
   manual Add Company form and the XLSX import. */
try { $conn->query("ALTER TABLE companies_import ADD COLUMN email VARCHAR(150) NULL"); } catch (\Throwable $e) {}
/* NEW: Admin Copy of MOA — a separate PDF an admin can attach on their
   own (independent of the company-submitted MOA above) for imported
   "Existing Company (has MOA)" rows, so admin staff can keep their own
   scanned/verified copy on file. */
try { $conn->query("ALTER TABLE companies_import ADD COLUMN admin_moa_document LONGBLOB NULL"); } catch (\Throwable $e) {}

/* ════════════════════════════════════════════════════════════════════
   NEW (Company Type revision): re-introduce `company_type` on
   companies_import — this time as a dedicated "Public" / "Private"
   classification, independent from the request_type ('New'/'Existing')
   column. This is intentionally a separate ALTER (not part of the
   DROP COLUMN company_type line that used to sit above, which only
   cleaned up an unrelated legacy column from an older revision of this
   table — see the FIX note above explaining why that DROP was removed)
   so it is always re-added on every install regardless of whether that
   older column ever existed.
   ════════════════════════════════════════════════════════════════════ */
try { $conn->query("ALTER TABLE companies_import ADD COLUMN company_type VARCHAR(20) NULL"); } catch (\Throwable $e) {}

/* NEW (Company Type revision): same "Public"/"Private" classification
   for REGISTERED companies. Kept on company_information for backward
   compatibility with any installs/rows that already carry a value
   here from an earlier revision of this page. */
try { $conn->query("ALTER TABLE company_information ADD COLUMN company_type VARCHAR(20) NULL"); } catch (\Throwable $e) {}

/* ════════════════════════════════════════════════════════════════════
   UPDATED (Company Type storage revision): Company Type for accounts
   created through the imported-company "Create Account" flow (Phase 2,
   further below) is now persisted on the `users` table instead of
   `company_information`. This ALTER is additive and independent of the
   company_information one above — it does not remove or migrate any
   existing company_information.company_type data, it simply gives the
   users table its own column so newly created accounts can carry their
   Company Type there going forward, per the updated requirement.
   ════════════════════════════════════════════════════════════════════ */
try { $conn->query("ALTER TABLE users ADD COLUMN company_type VARCHAR(20) NULL"); } catch (\Throwable $e) {}

/* ════════════════════════════════════════════════════════════════════
   NEW (Request Type storage revision): the Request Type ('New' /
   'Existing') captured on an imported/manually-added company — either
   from the XLSX import file's "Request Type" column, or from the
   "Company Status" choice made on the manual "Add Company" form / the
   Edit Imported Company modal — is now ALSO persisted on the `users`
   table the moment an account is created for that company (see
   attempt_create_company_account() below), mirroring exactly the
   Company Type storage revision immediately above it.

   This is purely additive: it does NOT change, remove, or replace the
   existing Request Type column/filter/badge already shown in the
   company list table for registered companies further down this file
   — that display continues to be driven by the most recent MOA request
   on record (moa_requests.request_type via the mr.request_type alias in
   $registeredSelect) exactly as before, since that reflects the
   company's current, possibly-updated MOA workflow status rather than
   the one-time value captured at import/add time. This new
   users.request_type column simply preserves that original import-time
   /manual-add-time Request Type value on the account record itself, per
   the requirement that it be saved to the users table.

   FIX (Request Type showing blank for accounts created via import /
   manual-add): see the $registeredSelect query further below — it now
   reads COALESCE(mr.request_type, u.request_type) instead of only
   mr.request_type. See that fix's own comment for the full
   explanation; this note is left here unchanged since the
   users.request_type column itself, and how it's populated, is
   unaffected by that display-side fix.
   ════════════════════════════════════════════════════════════════════ */
try { $conn->query("ALTER TABLE users ADD COLUMN request_type VARCHAR(20) NULL"); } catch (\Throwable $e) {}

/* ════════════════════════════════════════════════════════════════════
   NEW (this adjustment) — account_source distinguishes HOW a company's
   account was created: 'imported' (this file's own manual "Add Company"
   form, the Edit Imported Company modal, or an XLSX import — see
   attempt_create_company_account() below, which is the single place that
   ever writes 'imported' here) vs a company that signed itself up on
   company_register.php (which stamps 'self_registered' on its own
   INSERT). This is what $registeredSelect further down uses to decide
   who shows up in this company list and when: an imported company is
   visible right away regardless of validation status (they were already
   entered into this list on purpose), while a self-registered company
   only appears once they're fully Verified — see that query's own
   docblock for the complete explanation of both halves of that rule.
   ════════════════════════════════════════════════════════════════════ */
try { $conn->query("ALTER TABLE users ADD COLUMN account_source VARCHAR(20) NULL"); } catch (\Throwable $e) {}

/* NEW: same "Admin Copy of MOA" concept for REGISTERED companies. Since
   company_requirements already stores each company's own MOA under
   requirement_type='moa_document', the admin's own copy is stored in
   the exact same table/columns under a distinct requirement_type
   ('admin_moa_document') — no new table needed, and a unique key is
   added (if it doesn't already exist) so ON DUPLICATE KEY UPDATE can be
   used safely when the admin re-uploads/replaces their copy. */
try { $conn->query("ALTER TABLE company_requirements ADD UNIQUE KEY uniq_user_requirement (user_id, requirement_type)"); } catch (\Throwable $e) {}

/* ════════════════════════════════════════════════════════════════════
   NEW (this revision): shared helper — converts one companies_import
   row into a real company account (users + company_information rows),
   carries over any MOA/Admin MOA blobs into company_requirements, and
   emails the company its new password. This is the exact same logic
   that used to live only inline inside the create_accounts_selected
   AJAX handler further below; it has been extracted into a function so
   it can ALSO be called directly, server-side, right after a company is
   added through the manual "Add Company" form — per the requirement
   that account creation be automatic for manual adds too, not just
   XLSX imports.

   FIX (Company Type not saving to `users`): the imported row's
   Company Type is now re-validated here (trimmed and checked against
   the exact allowed values 'Public'/'Private') immediately before it is
   written to `users.company_type`, instead of being passed through
   as-is. This guarantees a stray value (extra whitespace, a blank
   string that isn't a real SQL NULL, an unexpected label, etc.) can
   never silently fail to persist — it either saves the correct
   Public/Private value or explicitly saves NULL, never something
   in-between that looks like it saved but didn't.

   NEW (Request Type storage revision): the imported row's Request Type
   is now re-validated the exact same way (trimmed and checked against
   the exact allowed values 'New'/'Existing') immediately before it is
   written to the new `users.request_type` column, mirroring the
   Company Type fix above line-for-line.

   create_accounts_selected() (further below) now simply loops the
   selected ids and calls this function per id, so its behavior/JSON
   response shape is completely unchanged from before.
   ════════════════════════════════════════════════════════════════════ */
function attempt_create_company_account($conn, $importId) {
    $result = [
        'outcome' => 'skipped', // 'created' | 'skipped' | 'failed'
        'company_label' => 'Entry #' . $importId,
        'detail' => null,
        'email_issue' => null,
    ];

    $rowStmt = $conn->prepare("SELECT * FROM companies_import WHERE id = ?");
    $rowStmt->bind_param("i", $importId);
    $rowStmt->execute();
    $impRow = $rowStmt->get_result()->fetch_assoc();
    $rowStmt->close();

    if (empty($impRow)) {
        $result['detail'] = "Entry #" . $importId . ": could not be found (it may have already been converted or deleted).";
        return $result;
    }

    $companyLabel = htmlspecialchars($impRow['company'] ?? ('Entry #' . $importId));
    $result['company_label'] = $companyLabel;

    $emailVal = trim($impRow['email'] ?? '');
    if ($emailVal === '' || !filter_var($emailVal, FILTER_VALIDATE_EMAIL)) {
        $result['detail'] = $companyLabel . ": missing or invalid email address, so no account could be created. Edit this entry to add a valid email, then try again.";
        return $result;
    }

    $dupCheck = $conn->prepare("SELECT id FROM users WHERE email = ?");
    $dupCheck->bind_param("s", $emailVal);
    $dupCheck->execute();
    $dupCheck->store_result();
    $alreadyRegistered = $dupCheck->num_rows > 0;
    $dupCheck->close();

    if ($alreadyRegistered) {
        $result['detail'] = $companyLabel . ": this email is already associated with an existing account, so a new one was not created.";
        return $result;
    }

    $conn->begin_transaction();
    $newUserId   = null;
    $plainPassword = null;
    try {
        $plainPassword  = (string) rand(100000, 999999);
        $hashedPassword = password_hash($plainPassword, PASSWORD_DEFAULT);
        $role           = 'company';
        $course         = '';
        $deploy_status  = '';
        $company_validation_status = null;

        $firstN  = $impRow['contact_first_name'];
        $middleN = $impRow['contact_middle_name'];
        $lastN   = $impRow['contact_last_name'];

        /* FIX (Company Type not saving): re-validate/normalize the
           imported row's Company Type right before it is written to
           users.company_type, instead of trusting it as-is. Anything
           that isn't exactly "Public" or "Private" (after trimming) is
           stored as NULL rather than silently dropped or mismatched. */
        $companyTypeForUser = trim((string)($impRow['company_type'] ?? ''));
        if (!in_array($companyTypeForUser, ['Public', 'Private'], true)) {
            $companyTypeForUser = null;
        }

        /* NEW (Request Type storage revision): same re-validate/
           normalize treatment as Company Type above — re-check the
           imported row's Request Type against the exact allowed values
           ('New'/'Existing', already normalized when the row was
           inserted/updated by the manual Add Company form, the Edit
           Imported Company modal, or the XLSX importer) immediately
           before it is written to users.request_type. Anything that
           isn't exactly "New" or "Existing" (after trimming) is stored
           as NULL rather than silently dropped or mismatched. */
        $requestTypeForUser = trim((string)($impRow['request_type'] ?? ''));
        if (!in_array($requestTypeForUser, ['New', 'Existing'], true)) {
            $requestTypeForUser = null;
        }

        // ── NEW (this adjustment): stamps this account as having come
        // from this file's own import/manual-add flow — see
        // account_source's own docblock further up for what this drives.
        $accountSource = 'imported';

        $uStmt = $conn->prepare("INSERT INTO users
            (first_name, middle_name, last_name, role, email, password, course, deploy_status, company_validation_status, company_type, request_type, account_source)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
        $uStmt->bind_param("ssssssssssss", $firstN, $middleN, $lastN, $role, $emailVal, $hashedPassword, $course, $deploy_status, $company_validation_status, $companyTypeForUser, $requestTypeForUser, $accountSource);
        if (!$uStmt->execute()) {
            $uErr = $uStmt->error;
            $uStmt->close();
            throw new \RuntimeException("could not create the login account (" . $uErr . ").");
        }
        $newUserId = $conn->insert_id;
        $uStmt->close();

        /* UPDATED (Company Type storage revision): company_type is no
           longer written into company_information for accounts created
           through this flow — it now lives on the users row created
           just above. Every other field here is unchanged. */
        $ciStmt = $conn->prepare("INSERT INTO company_information
            (user_id, company, company_address, telephone, contact_first_name, contact_middle_initial, contact_last_name, position)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
        $ciTypes = "i" . str_repeat("s", 7);
        $ciStmt->bind_param(
            $ciTypes,
            $newUserId,
            $impRow['company'],
            $impRow['company_address'],
            $impRow['telephone'],
            $firstN,
            $middleN,
            $lastN,
            $impRow['position']
        );
        if (!$ciStmt->execute()) {
            $ciErr = $ciStmt->error;
            $ciStmt->close();
            throw new \RuntimeException("the account was created but the company profile could not be saved (" . $ciErr . ").");
        }
        $ciStmt->close();

        /* Carry over the company's own MOA (if any) to
           company_requirements, the same table/column every other
           registered company's MOA already lives in. */
        if (!empty($impRow['has_moa']) && !empty($impRow['moa_document'])) {
            $reqTypeMoa = 'moa_document';
            $moaStmt = $conn->prepare("INSERT INTO company_requirements (user_id, requirement_type, file_name)
                VALUES (?, ?, ?)
                ON DUPLICATE KEY UPDATE file_name = VALUES(file_name)");
            $moaStmt->bind_param("iss", $newUserId, $reqTypeMoa, $impRow['moa_document']);
            $moaStmt->execute();
            $moaStmt->close();
        }

        /* Carry over the Admin Copy of MOA (if any) the same way. */
        if (!empty($impRow['admin_moa_document'])) {
            $reqTypeAdminMoa = 'admin_moa_document';
            $adminMoaStmt = $conn->prepare("INSERT INTO company_requirements (user_id, requirement_type, file_name)
                VALUES (?, ?, ?)
                ON DUPLICATE KEY UPDATE file_name = VALUES(file_name)");
            $adminMoaStmt->bind_param("iss", $newUserId, $reqTypeAdminMoa, $impRow['admin_moa_document']);
            $adminMoaStmt->execute();
            $adminMoaStmt->close();
        }

        // The imported/staging row has now become a real account — remove it
        // so the company only ever appears once (via the registered branch).
        $delImpStmt = $conn->prepare("DELETE FROM companies_import WHERE id = ?");
        $delImpStmt->bind_param("i", $importId);
        $delImpStmt->execute();
        $delImpStmt->close();

        $conn->commit();
        $result['outcome'] = 'created';

    } catch (\Throwable $e) {
        $conn->rollback();
        $result['outcome'] = 'failed';
        $result['detail'] = $companyLabel . ": " . htmlspecialchars($e->getMessage());
        return $result;
    }

    /* Best-effort password email — identical SMTP setup/body to
       company_register.php. A failed send does NOT undo the account
       that was just created/committed above; it is only reported back
       to the admin so they can relay the password manually if needed. */
    try {
        $mail = new \PHPMailer\PHPMailer\PHPMailer(true);
        $mail->isSMTP();
        $mail->Host       = 'smtp.gmail.com';
        $mail->SMTPAuth   = true;
        $mail->Username   = 'salesjohnlhoyd@gmail.com';
        $mail->Password   = 'qwufanprpmezotly';
        $mail->SMTPSecure = 'tls';
        $mail->Port       = 587;
        $mail->setFrom('salesjohnlhoyd@gmail.com', 'Atate On the Job Training System');
        $mail->addAddress($emailVal);
        $mail->isHTML(true);
        /* UPDATED (Branded email revision): Subject/Body now use the same
           branded format/design as login.php's automated emails (navy/gold
           "NEUST OJT Portal" header, status banner, highlighted code box,
           italic note, gray automated-message footer) via
           company_list_build_branded_email_template() below. The password,
           recipient and SMTP settings are unchanged. */
        $mail->Subject = 'Your Company Account Login Password - NEUST OJT Portal';
        $contactFirstForEmail = trim((string)($impRow['contact_first_name'] ?? ''));
        $companyNameForEmail  = trim((string)($impRow['company'] ?? ''));
        $greetingForEmail = $contactFirstForEmail !== ''
            ? "Hello, <strong>" . htmlspecialchars($contactFirstForEmail ?? '') . "</strong>!"
            : "Hello,";
        $accountBody = "
            <p style='margin:0 0 14px;color:#1e293b;font-size:15px;'>" . $greetingForEmail . "</p>
            <p style='margin:0 0 22px;color:#334155;font-size:14px;line-height:1.7;'>
                A company account" . ($companyNameForEmail !== '' ? " for <strong>" . htmlspecialchars($companyNameForEmail ?? '') . "</strong>" : "") . "
                has been created for you on the NEUST OJT Portal by the administrator.
                Use the password below together with your registered email
                (<strong>" . htmlspecialchars($emailVal ?? '') . "</strong>) to log in.
                For your security, please don't share this password with anyone.
            </p>

            <div style='background:#f8fafc;border:1px solid #e2e8f0;border-radius:12px;padding:22px;text-align:center;margin:0 0 22px;'>
                <p style='margin:0 0 8px;color:#64748b;font-size:11px;font-weight:700;letter-spacing:1.5px;text-transform:uppercase;'>Your Login Password</p>
                <p style='margin:0;color:#0038a8;font-size:32px;font-weight:800;letter-spacing:6px;'>" . htmlspecialchars($plainPassword ?? '') . "</p>
            </div>

            <p style='margin:0 0 4px;color:#64748b;font-size:13px;line-height:1.6;font-style:italic;'>
                Didn't expect this email? Please contact your school administrator right away so your account can be secured.
            </p>
        ";
        $mail->Body = company_list_build_branded_email_template(
            '#1e3a8a',
            '#eef2ff',
            '&#128274;',
            'Your Company Account Login Password',
            $accountBody
        );
        $mail->send();
    } catch (\Throwable $mailErr) {
        $result['email_issue'] = $companyLabel . ": account created successfully, but the password email could not be sent (SMTP error). Please relay the login details to the company directly.";
    }

    return $result;
}

/* ════════════════════════════════════════════════════════════════════
   NEW (Branded email revision): the same branded HTML email wrapper
   login.php uses (buildBrandedEmailTemplate()), copied here under its
   own name so it can never collide with that function. Gives the
   automated "company account created" email the exact same look as
   login.php's emails: navy gradient "NEUST OJT Portal" header with the
   gold title, a colored status banner, a white content card, and the
   light-gray "automated message" footer. Presentation only.
   ════════════════════════════════════════════════════════════════════ */
function company_list_build_branded_email_template($bannerColor, $bannerBg, $bannerIcon, $bannerText, $bodyHtml) {
    return "
    <div style=\"font-family:'Segoe UI',Arial,Helvetica,sans-serif;background:#eef1f8;padding:30px 10px;\">
      <div style=\"max-width:560px;margin:0 auto;background:#ffffff;border-radius:16px;overflow:hidden;box-shadow:0 6px 24px rgba(0,0,0,0.08);border:1px solid #e2e8f0;\">

        <!-- Header -->
        <div style=\"background:linear-gradient(135deg,#0a1454 0%,#132a8c 55%,#1a237e 100%);padding:30px 20px;text-align:center;\">
          <p style=\"margin:0 0 6px;color:#FFD700;font-size:22px;font-weight:800;letter-spacing:0.5px;\">NEUST OJT Portal</p>
          <p style=\"margin:0;color:rgba(255,255,255,0.75);font-size:13px;\">Atate Campus &mdash; On the Job Training System</p>
        </div>

        <!-- Status banner -->
        <div style=\"background:{$bannerBg};padding:16px 20px;text-align:center;border-bottom:1px solid rgba(0,0,0,0.06);\">
          <p style=\"margin:0;color:{$bannerColor};font-size:16px;font-weight:700;\">{$bannerIcon} {$bannerText}</p>
        </div>

        <!-- Body -->
        <div style=\"padding:32px 28px;\">
          {$bodyHtml}
        </div>

        <!-- Footer -->
        <div style=\"background:#f1f5f9;padding:16px 20px;text-align:center;\">
          <p style=\"margin:0;color:#94a3b8;font-size:11px;line-height:1.6;\">
            This is an automated message from the NEUST OJT Validation System.<br>Please do not reply to this email.
          </p>
        </div>

      </div>
    </div>
    ";
}

/* Runs attempt_create_company_account() for a batch of companies_import
   ids and returns the same created/skipped/failed breakdown the
   create_accounts_selected AJAX endpoint has always returned, so both
   the endpoint and any direct server-side caller (manual Add Company,
   below) share one exact, consistent account-creation code path and
   response shape. */
function run_company_account_creation_batch($conn, array $importIds) {
    $createdCount = 0;
    $skippedCount = 0;
    $failedCount  = 0;
    $skippedDetails = [];
    $failedDetails  = [];
    $createdWithEmailIssues = [];

    foreach ($importIds as $importId) {
        $res = attempt_create_company_account($conn, (int) $importId);
        if ($res['outcome'] === 'created') {
            $createdCount++;
            if (!empty($res['email_issue'])) $createdWithEmailIssues[] = $res['email_issue'];
        } elseif ($res['outcome'] === 'failed') {
            $failedCount++;
            if (!empty($res['detail'])) $failedDetails[] = $res['detail'];
        } else {
            $skippedCount++;
            if (!empty($res['detail'])) $skippedDetails[] = $res['detail'];
        }
    }

    return [
        'counts'  => ['created' => $createdCount, 'skipped' => $skippedCount, 'failed' => $failedCount],
        'details' => ['skipped' => $skippedDetails, 'failed' => $failedDetails, 'created_with_email_issues' => $createdWithEmailIssues],
    ];
}

/* ════════════════════════════════════════════════════════════════════
   NEW: Set/correct Request Type ('New' / 'Existing') directly on an
   ALREADY-REGISTERED company's users row.

   WHY THIS EXISTS: attempt_create_company_account() (above) only ever
   writes users.request_type at the moment an account is created through
   this page's "Add Company Manually" or XLSX Import flows. Companies
   that registered any other way (e.g. directly through
   company_register.php) — or that were converted into accounts before
   the users.request_type column/feature existed — never had this value
   written for them at all, so users.request_type is simply NULL for
   them (and stays NULL forever, since nothing on this page could ever
   set it after the fact). That in turn means:
     - This page's own Request Type column/filter shows "—" for them
       (COALESCE(mr.request_type, u.request_type) has nothing to fall
       back on when both sides are empty).
     - CompanyForm.php's "Existing"-only MOA requirement upload item
       (which reads the exact same users.request_type / moa_requests
       fallback) never appears for them either, even when the company
       genuinely has an existing MOA — because that fact was never
       recorded anywhere the system can read.

   This is a small, additive endpoint: it ONLY ever updates
   users.request_type for a company that is confirmed to be a
   registered company (role='company'), and only ever to exactly 'New'
   or 'Existing'. It doesn't touch companies_import, doesn't affect the
   Edit-imported-company flow, and doesn't change any other column. See
   the "Set Request Type" inline control rendered in the Request Type
   column below (only shown for registered rows that don't have a
   value yet) for where this is triggered from.
   ════════════════════════════════════════════════════════════════════ */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_registered_request_type'])) {
    header('Content-Type: application/json');

    $reg_request_type_user_id = isset($_POST['reg_request_type_user_id']) ? (int) $_POST['reg_request_type_user_id'] : 0;
    $reg_request_type_value   = trim($_POST['reg_request_type_value'] ?? '');

    if ($reg_request_type_user_id <= 0) {
        echo json_encode(['success' => false, 'message' => 'Invalid company reference.']);
        exit;
    }
    if (!in_array($reg_request_type_value, ['New', 'Existing'], true)) {
        echo json_encode(['success' => false, 'message' => 'Request Type must be either "New" or "Existing".']);
        exit;
    }

    // Confirm this is really a registered company account before writing anything.
    $checkUserStmt = $conn->prepare("SELECT id FROM users WHERE id = ? AND role = 'company'");
    $checkUserStmt->bind_param("i", $reg_request_type_user_id);
    $checkUserStmt->execute();
    $checkUserExists = $checkUserStmt->get_result()->num_rows > 0;
    $checkUserStmt->close();

    if (!$checkUserExists) {
        echo json_encode(['success' => false, 'message' => 'This company account could not be found.']);
        exit;
    }

    $updStmt = $conn->prepare("UPDATE users SET request_type = ? WHERE id = ? AND role = 'company'");
    $updStmt->bind_param("si", $reg_request_type_value, $reg_request_type_user_id);
    $ok = $updStmt->execute();
    $err = $updStmt->error;
    $updStmt->close();

    echo json_encode([
        'success' => (bool) $ok,
        'message' => $ok ? 'Request Type set successfully!' : ('Error updating Request Type: ' . $err),
    ]);
    exit;
}

/* ── NEW: Handle manual "Add Company" submission ──
   Adjustment: the "Company Status" choice (New Company / Existing
   Company) made in this form is always persisted as request_type
   ('New' / 'Existing') on the companies_import row, so it shows up in
   the Request Type column/filter for manually-added companies exactly
   the same way it does for XLSX-imported ones.

   UPDATED (this revision — MOA upload removed from manual add): the
   "Existing Company (has MOA)" choice used to also require uploading
   the company's own MOA document (PDF) directly in this form. That
   upload requirement/section has been removed entirely — it is no
   longer needed here. Company Status now ONLY controls the
   request_type value that gets stored; it no longer has any file-
   upload requirement attached to it. has_moa/moa_document are always
   stored as 0/NULL for companies added through this form (the columns
   themselves are untouched/still used for XLSX-imported rows and by
   the Admin Copy of MOA feature elsewhere on this page).

   NEW: this handler now also supports being called via fetch()/AJAX
   (identified by the X-Requested-With header the JS below sends) so the
   admin can add a company without a full page reload. When called this
   way it responds with JSON instead of redirecting. Non-JS submissions
   fall back to the original redirect-based behavior untouched.

   UPDATED (this revision): Email is now a REQUIRED field for the manual
   Add Company form (it used to be optional). The XLSX import's email
   column is intentionally left untouched/optional, since bulk-imported
   rows may come from spreadsheets that don't always have it — only the
   manual single-entry form now enforces it, matching the request.

   NEW (Company Type revision): "Company Type" (Public / Private) is now
   also a REQUIRED field on the manual Add Company form, stored on the
   imported row's new `company_type` column. This is a separate concept
   from Company Status (New/Existing) above — Company Status still only
   controls request_type.

   NEW (this revision — automatic account creation for manual adds): once
   the company is successfully saved to companies_import, a login account
   is now created for it automatically, right here in the same request,
   using the exact same attempt_create_company_account() logic the XLSX
   import's auto-account-creation already relies on. This mirrors the
   "no manual selection/click needed" behavior already in place for
   XLSX imports, applied to the single-entry manual form as well. The
   outcome (created / skipped / failed, plus any detail message) is
   folded into the JSON response for the AJAX path so the modal's
   confirmation toast reflects the real result, and the same id is also
   queued into $auto_create_account_import_ids for the non-JS fallback
   path so the page's existing auto-creation progress modal picks it up
   after the redirect, identical to how a fresh XLSX import is handled. */
$company_manual_add_message = '';
$company_manual_add_message_type = '';

/* ════════════════════════════════════════════════════════════════════
   NEW: companies_import ids resolved after a successful XLSX import OR
   a successful manual "Add Company" submission in THIS SAME REQUEST
   (see the import handler and the manual-add handler above/below).
   Stays an empty array on every other page load (plain filter/
   pagination loads, settings save, etc). When non-empty, the page
   automatically attempts to create login accounts for these companies
   as soon as it renders — the admin no longer needs to select these
   entries with the checkbox column and click a "Create Account" button
   themselves (that standalone toolbar button has been removed — see
   below — since this is now fully automatic for both import paths).
   ════════════════════════════════════════════════════════════════════ */
$auto_create_account_import_ids = [];

/* NEW (popup restore fix): companies that the XLSX import's "already
   registered" pre-check (see part 5 fix further below) skipped WITHOUT
   ever staging them into companies_import at all. Because these rows
   never get a companies_import id, they can never be handed to
   attempt_create_company_account() / run_company_account_creation_batch()
   like a normal import batch — but the admin should still see them
   accounted for in the "Creating Company Accounts" popup breakdown (as
   Skipped), exactly the way the older version of this page surfaced
   them, instead of only in the plain-text alert above the table. This
   array holds the human-readable skip messages so the popup can render
   them directly the moment the page loads after a successful import,
   with no create_accounts_selected round-trip needed for them. */
$auto_create_account_pre_skipped_details = [];

/* NEW: picks up the id queued by the manual Add Company form's non-JS
   (plain POST + redirect) fallback path, so the auto-account-creation
   progress modal still fires after a full page reload exactly like it
   does for a fresh XLSX import, even without JavaScript driving the
   AJAX add-company flow. */
if (isset($_GET['auto_create_import_id'])) {
    $autoCreateFromRedirect = (int) $_GET['auto_create_import_id'];
    if ($autoCreateFromRedirect > 0) {
        $auto_create_account_import_ids[] = $autoCreateFromRedirect;
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['add_company_manual'])) {
    $isAjaxAddCompany = (($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '') === 'XMLHttpRequest');

    $company_name    = trim($_POST['company_name'] ?? '');
    $company_address = trim($_POST['company_address'] ?? '');
    $telephone       = trim($_POST['telephone'] ?? '');
    $contact_first   = trim($_POST['contact_first_name'] ?? '');
    $contact_middle  = trim($_POST['contact_middle_name'] ?? '');
    $contact_last    = trim($_POST['contact_last_name'] ?? '');
    $position_val    = trim($_POST['position'] ?? '');
    $email_val       = trim($_POST['email'] ?? '');
    $company_type_val = trim($_POST['company_type'] ?? '');

    if ($position_val === 'other' && isset($_POST['custom_position']) && trim($_POST['custom_position']) !== '') {
        $position_val = trim($_POST['custom_position']);
    }

    /* Company Status still only decides the request_type value stored
       for this row ('New' or 'Existing') — it no longer has any file
       upload requirement tied to it (see UPDATED note above). has_moa /
       moa_blob are always 0 / null for companies added through this
       form. */
    $company_status = (isset($_POST['company_status']) && $_POST['company_status'] === 'existing') ? 'existing' : 'new';
    $request_type_manual = ($company_status === 'existing') ? 'Existing' : 'New';
    $has_moa  = 0;
    $moa_blob = null;

    /* NEW: id of the row just inserted, and the account-creation
       outcome for it (populated after a successful insert below), so
       both the AJAX JSON response and the non-JS redirect fallback can
       trigger/report automatic account creation. */
    $newly_added_import_id = null;
    $newly_added_account_result = null;

    $errors = [];
    if (empty($company_name))  $errors[] = "Company name is required.";
    if (empty($contact_first)) $errors[] = "Contact first name is required.";
    if (empty($contact_last))  $errors[] = "Contact last name is required.";

    /* UPDATED: Email is now required for the manual Add Company form. */
    if (empty($email_val)) {
        $errors[] = "Email is required.";
    } elseif (!filter_var($email_val, FILTER_VALIDATE_EMAIL)) {
        $errors[] = "Please enter a valid email address.";
    }

    /* NEW (Company Type revision): Company Type is required and must
       be exactly "Public" or "Private". */
    if (empty($company_type_val) || !in_array($company_type_val, ['Public', 'Private'], true)) {
        $errors[] = "Please select the Company Type (Public or Private).";
    }

    /* REMOVED (this revision): the manual Add Company form used to
       require an MOA document (PDF) upload whenever Company Status was
       set to "Existing Company (has MOA)". That upload requirement has
       been removed entirely — Company Status now only controls the
       request_type value above and no longer has any file associated
       with it from this form. */

    if (empty($errors)) {
        if (empty($contact_middle)) $contact_middle = null;

        $check_stmt = $conn->prepare("SELECT id FROM companies_import WHERE company = ?");
        $check_stmt->bind_param("s", $company_name);
        $check_stmt->execute();
        $check_result = $check_stmt->get_result();

        if ($check_result->num_rows > 0) {
            $company_manual_add_message = "Error: A company with this name already exists in the imported list!";
            $company_manual_add_message_type = "error";
        } else {
            if ($email_val === '') $email_val = null;

            /* NEW (Company Type revision): company_type added as its own
               column in the INSERT, right alongside request_type. */
            $stmt = $conn->prepare("INSERT INTO companies_import
                (company, company_address, telephone, contact_first_name, contact_middle_name, contact_last_name, position, email, request_type, company_type, has_moa, moa_document)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
            $addCompanyTypes = str_repeat('s', 10) . 'is';
            $stmt->bind_param($addCompanyTypes, $company_name, $company_address, $telephone, $contact_first, $contact_middle, $contact_last, $position_val, $email_val, $request_type_manual, $company_type_val, $has_moa, $moa_blob);

            if ($stmt->execute()) {
                $newly_added_import_id = $conn->insert_id;
                $company_manual_add_message = "Company added successfully!";
                $company_manual_add_message_type = "success";

                /* NEW: attempt to create the login account for this
                   company immediately — no admin selection/click
                   needed, mirroring the XLSX import's automatic
                   behavior. This runs regardless of AJAX vs. non-JS
                   submission, so the account is created either way;
                   only how the OUTCOME is surfaced to the admin differs
                   (JSON for AJAX, the redirect + auto-progress-modal
                   trigger below for the non-JS fallback). */
                if ($newly_added_import_id) {
                    $newly_added_account_result = attempt_create_company_account($conn, $newly_added_import_id);
                }

                if (!$isAjaxAddCompany) {
                    $redirectUrl = $_SERVER['PHP_SELF'] . "?company_added=success";
                    /* Only queue the auto-creation-progress modal via the
                       redirect if the account wasn't already fully and
                       cleanly created above (e.g. it was "skipped" for a
                       fixable reason like a missing email) — a plain
                       "created" outcome needs no further UI, the row is
                       already gone from companies_import. */
                    if ($newly_added_account_result && $newly_added_account_result['outcome'] !== 'created') {
                        $redirectUrl .= "&auto_create_import_id=" . $newly_added_import_id;
                    }
                    header("Location: " . $redirectUrl);
                    exit;
                }
            } else {
                $company_manual_add_message = "Error adding company: " . $stmt->error;
                $company_manual_add_message_type = "error";
            }
            $stmt->close();
        }
        $check_stmt->close();
    } else {
        $company_manual_add_message = implode("<br>", $errors);
        $company_manual_add_message_type = "error";
    }

    /* NEW: AJAX callers (the Add Company modal, submitted via fetch)
       get a JSON reply here instead of a full-page redirect/reload, so
       the table can be refreshed and a floating confirmation shown
       without leaving the page. The account-creation outcome (if the
       company was added successfully) is included so the client can
       decide whether to run the "Creating Company Accounts" progress
       modal (only needed when the account wasn't cleanly created
       outright — e.g. skipped for a missing email — so the admin knows
       to follow up; a clean "created" outcome needs no extra modal). */
    if ($isAjaxAddCompany) {
        header('Content-Type: application/json');
        echo json_encode([
            'success' => $company_manual_add_message_type === 'success',
            'message' => $company_manual_add_message,
            'import_id' => $newly_added_import_id,
            'account_outcome' => $newly_added_account_result['outcome'] ?? null,
            'account_detail' => $newly_added_account_result['detail'] ?? null,
        ]);
        exit;
    }
}

/* ── NEW: Handle XLSX import for companies (XLSX only, mirrors student import) ──
   NEW: an 8th column, Request Type (New/Existing), is now read from the
   file and stored alongside each imported row so imported companies also
   carry a Request Type value for the column/filter in the table below.
   Any blank or unrecognized value defaults to "New".

   NEW (Company Type revision): a "Company Type" (Public/Existing... no —
   Public/Private) column is now also recognized from the header row and
   stored alongside each imported row. Any blank or unrecognized value is
   left blank, the same lenient "optional data" approach already used for
   Address/Telephone/Position/Email — this column does NOT participate in
   the required-column check below (Company Name, Contact First Name,
   Contact Last Name remain the only required columns), so existing
   import files that don't have a Company Type column keep importing
   exactly as before, just without a Company Type value for those rows.

   FIX (this revision): the importer previously always read fields by a
   FIXED column position (0..8), so any spreadsheet whose columns were
   reordered, had an extra/missing column, or used a different column
   sequence than the exact original template silently shoved values into
   the WRONG database fields (e.g. an address ending up in Telephone, or
   a Position value ending up in Email) instead of failing loudly. The
   importer now reads the header row and matches each column BY NAME
   (case/spacing-insensitive) to the field it belongs to, so column order
   in the uploaded file no longer matters. If the header row is missing
   or doesn't contain a recognizable set of column names, the importer
   safely falls back to the exact original fixed column order below, so
   older import files that already worked continue to work unchanged.

   FIX (this revision, part 2): even when the header row IS recognized
   and used, a file that is missing one of the OPTIONAL named columns
   (e.g. no "Request Type" column, or no "Email" column, or a header
   label for an optional field that doesn't match any known alias) used
   to read that field via a raw $importColMap[...] array lookup that
   simply did not exist for that key. That triggered a PHP "Undefined
   array key" notice on every single row for that field, and — depending
   on server error-handling configuration — could escalate into an
   exception that aborts the whole import partway through (caught by the
   outer try/catch below), which is exactly the kind of failure that
   looks like "my data didn't land in the right columns" from the admin's
   side, even though the mapping logic itself was correct. Every field
   read below now goes through readImportCell(), a single small helper
   that safely returns an empty string whenever a column mapping is
   simply absent, instead of ever touching an undefined array key. This
   does not change WHICH column any field is read from — it only makes a
   genuinely-missing column resolve to a blank value instead of a
   warning/exception, so the per-column mapping above can always be
   trusted to either read the right cell or read nothing, never the
   wrong cell.

   FIX (this revision, part 3 — THE ACTUAL COLUMN-MISALIGNMENT BUG):
   the previous revision's safety net was exactly backwards. As soon as
   the header row failed to name all three REQUIRED fields (Company,
   Contact First Name, Contact Last Name) using one of the recognized
   aliases — for example because the admin re-uploaded a file exported
   from THIS SAME PAGE's "Export to Excel" button, whose header row
   reads "Company / Contact First Name / Contact Middle Name / Contact
   Last Name / Email / Telephone / Address / Validation Status / Company
   Type / Request Type / OJT Students / MOA on File / Admin Copy of MOA
   / Source" — the importer silently abandoned header matching entirely
   and instead grabbed each column strictly BY POSITION (0=company,
   1=address, 2=telephone, 3=first, 4=middle, 5=last, 6=position,
   7=request_type, 8=email). Since the export's column order is
   completely different from that hardcoded position list, every single
   field lands in the wrong database column — an address ends up as a
   first name, an email ends up in the telephone column, and so on —
   exactly the corruption reported. Worse, this happened silently: the
   import reported "success" with no indication anything was wrong.

   The importer no longer guesses blindly. It still recognizes header
   names regardless of column order (so a correctly-labeled file works
   no matter how its columns are arranged), but if the three required
   columns can't be confidently identified by name, the import now stops
   and reports exactly which required column(s) it couldn't find and
   what header names it recognizes — instead of silently writing data
   into the wrong fields. This guarantees every successful import always
   places data in the column it actually belongs to.

   FIX (this revision, part 4 — Company Type / Request Type not saving
   correctly when re-importing this page's own "Export to Excel" file):
   the export writes Request Type as the full label ("New Company" /
   "Existing Company"), but the importer previously only recognized the
   bare words "New"/"Existing" and silently defaulted anything else
   (including "Existing Company") to "New" — so re-importing an exported
   file quietly flipped every "Existing Company" row back to "New".
   Request Type matching is now tolerant of both the bare word and the
   full exported label (e.g. "Existing" and "Existing Company" both
   resolve to 'Existing'). Company Type matching gets the same
   tolerance for consistency, and — most importantly — the recognized
   value is now carried all the way through to the `users` table when an
   account is created (see attempt_create_company_account() above),
   which re-validates and writes it there directly instead of trusting
   an intermediate value that might never have been checked again.

   FIX (this revision, part 5 — re-imported / already-registered
   companies getting stuck as duplicate "Imported" rows instead of
   being skipped): this page's own "Export to Excel" output includes
   BOTH registered and imported companies. Re-importing that file (a
   scenario this importer has always explicitly tried to support — see
   part 3/4 above) used to blindly insert/update a companies_import row
   for EVERY row in the file, including rows for companies that are
   already fully registered (a real users/company_information account
   already exists for them). Since companies_import's unique key only
   guards against duplicates WITHIN companies_import, that already-
   registered company would get a brand-new, permanent "Imported" row
   sitting right next to its real "Registered" row in the table. The
   automatic account-creation pass that runs immediately after import
   would then correctly detect the duplicate email and report that
   entry as "skipped" — but a "skipped" entry is intentionally never
   deleted from companies_import (so admins can fix genuinely-fixable
   skips, like a missing email), so this particular kind of "skip" just
   sat there forever as a stray, permanently-stuck duplicate row. That
   is the entry that kept "still saving instead of being skipped".

   The importer now checks each row's company name AND email (whichever
   is present) against companies that are ALREADY REGISTERED (a real
   users/company_information account) BEFORE it ever touches
   companies_import. A match means this company doesn't need — and
   should never get — a staging row at all, so that row is skipped
   entirely (no INSERT, no UPDATE, nothing written to companies_import)
   and reported separately in the import summary as "already
   registered", distinct from a validation error. Rows that fail the
   required-field check keep behaving exactly as before (reported in
   $errorRows, which still causes the whole batch to roll back so nothing
   partially lands) — this new check only ever prevents a row from being
   staged when it demonstrably doesn't need to be, so it can never
   discard a row that was otherwise good.

   FIX (this revision, part 6 — "Creating Company Accounts" popup no
   longer appearing for these already-registered skips): because rows
   caught by the part 5 pre-check above are never staged into
   companies_import, they never receive a companies_import id — and the
   automatic account-creation pass that runs after a successful import
   only ever knows about ids it resolved for rows that WERE staged. So
   when an import consisted ENTIRELY of already-registered companies
   (as in a re-import of this page's own export), $auto_create_account_
   import_ids stayed empty and the "Creating Company Accounts" popup —
   which used to show a Created/Skipped/Failed breakdown for exactly
   this kind of result — silently never appeared, leaving only the
   plain-text alert above the table. These messages are now ALSO copied
   into $auto_create_account_pre_skipped_details (declared near the top
   of this file) so the popup still opens and reports them as Skipped,
   exactly like it used to before the part 5 pre-check existed — see
   where $skippedAlreadyRegisteredRows is used further below, and the
   matching JS changes to runAutoAccountCreation() near the bottom of
   this file.

   UPDATED (this revision — duplicate-display fix): the plain-text
   alert used to ALSO spell out every "already registered" skip
   row-by-row directly above the table (see the screenshot the admin
   reported). That list is exactly what the "Creating Company
   Accounts" popup already renders (via $auto_create_account_pre_skipped_details
   just above, fed into runAutoAccountCreation() in the script below),
   so listing it a second time in the plain alert was pure duplication.
   The plain alert now only reports the import counts; the full skipped
   breakdown is shown exclusively in the popup. See the
   "if (!empty($skippedAlreadyRegisteredRows))" block further below for
   the exact change — nothing about how $skippedAlreadyRegisteredRows
   itself is collected, or how it feeds the popup, was touched. */
$company_import_message = '';
$company_import_message_type = '';
/* NEW (Import/Export result screen revision): set to true only when the
   real XLSX import handler below actually runs on this request, so the
   page can show the full-screen Import Successful / Import Failed result
   screen after the post-import reload. Nothing else reads it. */
$company_import_attempted = false;

/* ════════════════════════════════════════════════════════════════════
   NEW (Company Type / Request Type import filter revision): "detect
   classifications" endpoint — called via AJAX (fetch) from the client
   the moment a file is chosen, BEFORE the real import below ever runs.
   It parses the uploaded XLSX read-only (nothing is written to the
   database here) and reports back every distinct Request Type
   ('New'/'Existing') and Company Type ('Public'/'Private'/'' for
   unspecified) value actually found among the file's data rows. The
   client then shows the admin a checkbox picker built from this list —
   see the "importClassificationModal" further down and its script —
   and only once the admin confirms a selection does the real import
   (the handler right after this one) actually get POSTed, now carrying
   that selection along as selected_request_types[] / 
   selected_company_types[] so only matching rows get imported (see the
   "classification filter" check inside the main import loop below).

   This mirrors the real importer's own header-matching logic (same
   $importHeaderAliases map, same leading-match tolerance for Request
   Type / Company Type values) so whatever this endpoint reports is
   guaranteed to match what the real import would actually do with the
   same file — it is kept as its own self-contained block (rather than
   a shared helper) specifically so this read-only detection step can
   never influence or accidentally break the real import's logic below.
   ════════════════════════════════════════════════════════════════════ */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['detect_company_import_classifications']) && isset($_FILES['company_import_file'])) {
    header('Content-Type: application/json');

    $file        = $_FILES['company_import_file'];
    $fileName    = $file['name'];
    $fileTmpName = $file['tmp_name'];
    $fileError   = $file['error'];
    $fileSize    = $file['size'];
    $fileExt     = strtolower(pathinfo($fileName, PATHINFO_EXTENSION));
    $maxFileSize = 100 * 1024 * 1024;

    if ($fileExt !== 'xlsx') {
        echo json_encode(['success' => false, 'message' => 'Only XLSX files are allowed.']);
        exit;
    }
    if ($fileSize > $maxFileSize) {
        echo json_encode(['success' => false, 'message' => 'File size exceeds the maximum allowed limit of 100MB.']);
        exit;
    }
    if ($fileError !== 0) {
        echo json_encode(['success' => false, 'message' => 'Error uploading file. Please try again.']);
        exit;
    }

    require 'vendor/autoload.php';
    try {
        $reader = \PhpOffice\PhpSpreadsheet\IOFactory::createReaderForFile($fileTmpName);
        $reader->setReadDataOnly(true);
        $spreadsheet = $reader->load($fileTmpName);
        $worksheet   = $spreadsheet->getActiveSheet();
        $detectRows  = $worksheet->toArray();
        $spreadsheet->disconnectWorksheets();
        unset($spreadsheet);

        $detectHeader = array_shift($detectRows);

        // Same normalization + alias map as the real importer below.
        $normalizeDetectHeader = function ($value) {
            $value = strtolower(trim((string) $value));
            $value = preg_replace('/[^a-z0-9]+/', ' ', $value);
            return trim($value);
        };
        $detectHeaderAliases = [
            'company name'                 => 'company',
            'company'                      => 'company',
            'company address'              => 'address',
            'address'                      => 'address',
            'telephone'                    => 'telephone',
            'telephone number'             => 'telephone',
            'phone'                        => 'telephone',
            'phone number'                 => 'telephone',
            'contact number'               => 'telephone',
            'contact no'                   => 'telephone',
            'mobile number'                => 'telephone',
            'mobile'                       => 'telephone',
            'contact first name'           => 'first',
            'first name'                   => 'first',
            'contact middle name'          => 'middle',
            'middle name'                  => 'middle',
            'contact last name'            => 'last',
            'last name'                    => 'last',
            'position'                     => 'position',
            'designation'                  => 'position',
            'request type'                 => 'request_type',
            'request type new existing'    => 'request_type',
            'company status'               => 'request_type',
            'email'                        => 'email',
            'email address'                => 'email',
            'company type'                 => 'company_type',
            'company type public private'  => 'company_type',
            'type'                         => 'company_type',
            'sector'                       => 'company_type',
            'public private'               => 'company_type',
        ];

        $detectColMap = [];
        if (is_array($detectHeader)) {
            foreach ($detectHeader as $colIdx => $colLabel) {
                $normalizedLabel = $normalizeDetectHeader($colLabel);
                if ($normalizedLabel !== '' && isset($detectHeaderAliases[$normalizedLabel]) && !isset($detectColMap[$detectHeaderAliases[$normalizedLabel]])) {
                    $detectColMap[$detectHeaderAliases[$normalizedLabel]] = $colIdx;
                }
            }
        }

        $detectHeaderMapUsable = isset($detectColMap['company']) && isset($detectColMap['first']) && isset($detectColMap['last']);
        if (!$detectHeaderMapUsable) {
            echo json_encode([
                'success' => false,
                'message' => "The file's header row is missing a recognizable column for Company Name, Contact First Name, and/or Contact Last Name. No data was imported.",
            ]);
            exit;
        }

        $readDetectCell = function (array $row, array $colMap, string $key) {
            if (!array_key_exists($key, $colMap)) return '';
            $colIdx = $colMap[$key];
            return array_key_exists($colIdx, $row) ? trim((string) $row[$colIdx]) : '';
        };

        $foundRequestTypes = []; // keys: 'New' / 'Existing'
        $foundCompanyTypes = []; // keys: 'Public' / 'Private' / '' (unspecified)
        $detectRowCount = 0;

        foreach ($detectRows as $row) {
            if (empty(array_filter($row))) continue;
            $companyNameCell = $readDetectCell($row, $detectColMap, 'company');
            $firstNameCell    = $readDetectCell($row, $detectColMap, 'first');
            $lastNameCell     = $readDetectCell($row, $detectColMap, 'last');
            if (empty($companyNameCell) || empty($firstNameCell) || empty($lastNameCell)) continue; // would be an error row in the real import — not a classification to offer

            $detectRowCount++;

            $requestTypeRaw = $readDetectCell($row, $detectColMap, 'request_type');
            $foundRequestTypes[(stripos(trim($requestTypeRaw), 'Existing') === 0) ? 'Existing' : 'New'] = true;

            $companyTypeRaw = trim($readDetectCell($row, $detectColMap, 'company_type'));
            if (stripos($companyTypeRaw, 'Public') === 0) {
                $foundCompanyTypes['Public'] = true;
            } elseif (stripos($companyTypeRaw, 'Private') === 0) {
                $foundCompanyTypes['Private'] = true;
            } else {
                $foundCompanyTypes[''] = true;
            }
        }

        echo json_encode([
            'success'       => true,
            'row_count'     => $detectRowCount,
            'request_types' => array_keys($foundRequestTypes),
            'company_types' => array_keys($foundCompanyTypes),
        ]);
        exit;
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'message' => 'Error reading file: ' . $e->getMessage()]);
        exit;
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_FILES['company_import_file']) && !isset($_POST['detect_company_import_classifications'])) {
    $company_import_attempted = true; // NEW (Import/Export result screen revision)
    $file        = $_FILES['company_import_file'];
    $fileName    = $file['name'];
    $fileTmpName = $file['tmp_name'];
    $fileError   = $file['error'];
    $fileSize    = $file['size'];

    $fileExt     = strtolower(pathinfo($fileName, PATHINFO_EXTENSION));
    $maxFileSize = 100 * 1024 * 1024;

    if ($fileExt !== 'xlsx') {
        $company_import_message = "Error: Only XLSX files are allowed.";
        $company_import_message_type = "error";
    } elseif ($fileSize > $maxFileSize) {
        $company_import_message = "Error: File size exceeds the maximum allowed limit of 100MB. Your file is " . round($fileSize / (1024 * 1024), 2) . "MB.";
        $company_import_message_type = "error";
    } elseif ($fileError !== 0) {
        $company_import_message = "Error uploading file. Please try again.";
        $company_import_message_type = "error";
    } else {
        require 'vendor/autoload.php';
        try {
            $reader = \PhpOffice\PhpSpreadsheet\IOFactory::createReaderForFile($fileTmpName);
            $reader->setReadDataOnly(true);
            $spreadsheet = $reader->load($fileTmpName);
            $worksheet   = $spreadsheet->getActiveSheet();
            $rows        = $worksheet->toArray();
            $spreadsheet->disconnectWorksheets();
            unset($spreadsheet);

            // Expected column order (used only for reference / as a
            // documentation aid — see below, this is NO LONGER used as
            // a blind positional fallback):
            // Company Name | Company Address | Telephone |
            // Contact First Name | Contact Middle Name | Contact Last Name | Position |
            // Request Type (New/Existing) | Email | Company Type (Public/Private)
            // (Column ORDER does not matter at all — every column is
            // matched strictly by its header name; see below.)
            $header        = array_shift($rows);
            $importedCount = 0;
            $updatedCount  = 0;
            $errorRows     = [];
            /* NEW (part 5 fix): rows that were deliberately NOT staged
               into companies_import because they already correspond to
               a fully registered company — kept separate from
               $errorRows so they never trigger a rollback of otherwise
               good rows; they are purely informational for the admin. */
            $skippedAlreadyRegisteredRows = [];

            /* NEW: tracks the company names successfully inserted/
               updated during this import, so we can resolve their
               companies_import ids after commit and automatically
               kick off account creation for them — see the
               auto-account-creation block right after the commit
               below. */
            $autoCreateAccountCompanyNames = [];

            /* ────────────────────────────────────────────────────────
               Build a header-name → column-index map so each field is
               read from whatever column actually carries that header in
               the uploaded file, instead of a hardcoded position.
               Recognizes common header variations (e.g. "Company Name"
               or just "Company") and ignores case, punctuation, and
               extra spacing. Broadened this revision to also recognize
               a few more common variants (contact/mobile number,
               company status, company type, etc.) so more real-world
               header rows are confidently recognized instead of falling
               through.
               ──────────────────────────────────────────────────────── */
            $normalizeImportHeader = function ($value) {
                $value = strtolower(trim((string) $value));
                $value = preg_replace('/[^a-z0-9]+/', ' ', $value);
                return trim($value);
            };

            $importHeaderAliases = [
                'company name'                 => 'company',
                'company'                      => 'company',
                'company address'              => 'address',
                'address'                      => 'address',
                'telephone'                    => 'telephone',
                'telephone number'             => 'telephone',
                'phone'                        => 'telephone',
                'phone number'                 => 'telephone',
                'contact number'               => 'telephone',
                'contact no'                   => 'telephone',
                'mobile number'                => 'telephone',
                'mobile'                       => 'telephone',
                'contact first name'           => 'first',
                'first name'                   => 'first',
                'contact middle name'          => 'middle',
                'middle name'                  => 'middle',
                'contact last name'            => 'last',
                'last name'                    => 'last',
                'position'                     => 'position',
                'designation'                  => 'position',
                'request type'                 => 'request_type',
                'request type new existing'    => 'request_type',
                'company status'               => 'request_type',
                'email'                        => 'email',
                'email address'                => 'email',
                /* NEW (Company Type revision) */
                'company type'                 => 'company_type',
                'company type public private'  => 'company_type',
                'type'                         => 'company_type',
                'sector'                       => 'company_type',
                'public private'               => 'company_type',
            ];

            $importColMap = [];
            if (is_array($header)) {
                foreach ($header as $colIdx => $colLabel) {
                    $normalizedLabel = $normalizeImportHeader($colLabel);
                    if ($normalizedLabel !== '' && isset($importHeaderAliases[$normalizedLabel]) && !isset($importColMap[$importHeaderAliases[$normalizedLabel]])) {
                        $importColMap[$importHeaderAliases[$normalizedLabel]] = $colIdx;
                    }
                }
            }

            /* FIX: the header row must identify Company Name, Contact
               First Name, and Contact Last Name (the three required
               fields) by name before the import is trusted at all. If
               it can't, the import is now REJECTED with a clear,
               specific error instead of silently falling back to a
               blind fixed column order — that blind fallback was the
               actual source of data landing in the wrong columns
               (e.g. re-importing this page's own "Export to Excel"
               output, whose column order doesn't match the old fixed
               assumption, used to scramble every field). Company Type
               is optional and is NOT part of this required check. */
            $importHeaderMapUsable = isset($importColMap['company']) && isset($importColMap['first']) && isset($importColMap['last']);

            if (!$importHeaderMapUsable) {
                $missingRequired = [];
                if (!isset($importColMap['company'])) $missingRequired[] = 'Company Name (e.g. "Company Name" or "Company")';
                if (!isset($importColMap['first']))   $missingRequired[] = 'Contact First Name (e.g. "Contact First Name" or "First Name")';
                if (!isset($importColMap['last']))    $missingRequired[] = 'Contact Last Name (e.g. "Contact Last Name" or "Last Name")';

                $company_import_message = "Import failed: the file's header row (first row) is missing a recognizable column for: "
                    . implode(', ', $missingRequired)
                    . ". Please make sure the first row of your XLSX file contains column headers with these exact names "
                    . "(column order does not matter). Expected headers: Company Name, Company Address, Telephone, "
                    . "Contact First Name, Contact Middle Name, Contact Last Name, Position, Request Type, Email, Company Type. "
                    . "No data was imported.";
                $company_import_message_type = "error";
            } else {

            /* Safe column reader — given a data row and the resolved
               header→column map, this returns the trimmed cell value
               for a field, or '' if that field simply has no mapped
               column (never a warning, never a wrong column — just an
               empty value for a field the file didn't provide). */
            $readImportCell = function (array $row, array $colMap, string $key) {
                if (!array_key_exists($key, $colMap)) return '';
                $colIdx = $colMap[$key];
                return array_key_exists($colIdx, $row) ? trim((string) $row[$colIdx]) : '';
            };

            /* ════════════════════════════════════════════════════════
               NEW (part 5 fix): pre-load the set of company names and
               emails that ALREADY belong to a fully registered company
               (a real users/company_information account), so every row
               in this import batch can be checked against it in memory
               instead of running a query per row. Matching is done
               case-insensitively / trimmed, mirroring how the rest of
               this page treats company names and emails elsewhere
               (e.g. the unique_company key, the users.email dup check
               in attempt_create_company_account()).
               ════════════════════════════════════════════════════════ */
            $existingRegisteredCompanyNames = [];
            $regNameRes = $conn->query("
                SELECT ci.company
                FROM company_information ci
                INNER JOIN users u ON u.id = ci.user_id
                WHERE u.role = 'company'
            ");
            if ($regNameRes) {
                while ($regNameRow = $regNameRes->fetch_assoc()) {
                    $key = mb_strtolower(trim((string) $regNameRow['company']));
                    if ($key !== '') $existingRegisteredCompanyNames[$key] = true;
                }
            }
            $existingRegisteredEmails = [];
            $regEmailRes = $conn->query("
                SELECT email FROM users
                WHERE role = 'company' AND email IS NOT NULL AND email <> ''
            ");
            if ($regEmailRes) {
                while ($regEmailRow = $regEmailRes->fetch_assoc()) {
                    $key = mb_strtolower(trim((string) $regEmailRow['email']));
                    if ($key !== '') $existingRegisteredEmails[$key] = true;
                }
            }

            /* ════════════════════════════════════════════════════════
               NEW (Company Type / Request Type import filter revision):
               the admin's classification choice from the
               "importClassificationModal" picker (populated from the
               detect endpoint above) arrives here as
               selected_request_types[] / selected_company_types[] —
               only rows whose Request Type / Company Type is in the
               admin's selection are actually imported; every other row
               is skipped (reported separately, see
               $skippedClassificationRows below) instead of being
               imported. Company Type uses the empty string '' as the
               key for "Unspecified" (a row with no/unrecognized Company
               Type value), matching the 'Unspecified' checkbox the
               modal offers whenever the file has such rows.

               Backward compatible: if these fields are simply absent
               from the POST at all (e.g. a very old bookmarked/replayed
               request, or a caller that never went through the
               classification picker), no filtering is applied and every
               row is considered a match — the exact behavior this page
               had before this revision. */
            $selectedRequestTypesPost = (isset($_POST['selected_request_types']) && is_array($_POST['selected_request_types']))
                ? $_POST['selected_request_types'] : null;
            $selectedCompanyTypesPost = (isset($_POST['selected_company_types']) && is_array($_POST['selected_company_types']))
                ? $_POST['selected_company_types'] : null;
            $classificationFilterActive = ($selectedRequestTypesPost !== null || $selectedCompanyTypesPost !== null);
            $selectedRequestTypesSet = $selectedRequestTypesPost !== null ? array_fill_keys($selectedRequestTypesPost, true) : null;
            $selectedCompanyTypesSet = $selectedCompanyTypesPost !== null ? array_fill_keys($selectedCompanyTypesPost, true) : null;
            /* NEW: rows skipped purely because their classification
               wasn't selected by the admin — kept separate from
               $errorRows (never causes a rollback) and from
               $skippedAlreadyRegisteredRows (a different reason
               entirely), so the import summary can report each reason
               distinctly. */
            $skippedClassificationRows = [];

            $conn->begin_transaction();
            $chunkSize   = 1000;
            $chunkedRows = array_chunk($rows, $chunkSize);

            foreach ($chunkedRows as $chunkIndex => $rowChunk) {
                foreach ($rowChunk as $index => $row) {
                    if (empty(array_filter($row))) continue;

                    $company_name    = $readImportCell($row, $importColMap, 'company');
                    $company_address = $readImportCell($row, $importColMap, 'address');
                    $telephone       = $readImportCell($row, $importColMap, 'telephone');
                    $contact_first   = $readImportCell($row, $importColMap, 'first');
                    $contact_middle  = $readImportCell($row, $importColMap, 'middle');
                    $contact_last    = $readImportCell($row, $importColMap, 'last');
                    $position_val    = $readImportCell($row, $importColMap, 'position');

                    /* FIX (part 4): recognize both the bare word
                       ("Existing") AND this page's own exported label
                       ("Existing Company") — previously only the bare
                       word matched, so re-importing an exported file
                       silently defaulted every "Existing Company" row
                       back to "New". A leading-match (case-insensitive)
                       check covers both forms without needing two
                       separate comparisons. */
                    $request_type_raw = $readImportCell($row, $importColMap, 'request_type');
                    if (stripos(trim($request_type_raw), 'Existing') === 0) {
                        $request_type_val = 'Existing';
                    } else {
                        // Blank, "New"/"New Company", or anything unrecognized defaults to "New".
                        $request_type_val = 'New';
                    }

                    /* NEW (Company Type revision): Public/Private, read
                       leniently — blank or unrecognized values are left
                       blank rather than failing the row, same approach
                       already used for address/telephone/position.
                       FIX (part 4): matched with the same tolerant
                       leading-match check as Request Type above, so a
                       value like "Private " (trailing space) or any
                       minor label variation still resolves correctly
                       instead of silently falling through to NULL. */
                    $company_type_raw = trim($readImportCell($row, $importColMap, 'company_type'));
                    if (stripos($company_type_raw, 'Public') === 0) {
                        $company_type_val = 'Public';
                    } elseif (stripos($company_type_raw, 'Private') === 0) {
                        $company_type_val = 'Private';
                    } else {
                        $company_type_val = null;
                    }

                    $email_val = $readImportCell($row, $importColMap, 'email');
                    if ($email_val !== '' && !filter_var($email_val, FILTER_VALIDATE_EMAIL)) {
                        // Invalid email in the sheet doesn't fail the whole row —
                        // it's simply left blank, same "be lenient on optional data"
                        // approach already used for address/telephone/position.
                        $email_val = '';
                    }

                    $globalRowNumber = ($chunkIndex * $chunkSize) + $index + 2;

                    if (empty($company_name) || empty($contact_first) || empty($contact_last)) {
                        $errorRows[] = "Row " . $globalRowNumber . ": Missing required data (Company Name, Contact First Name, Contact Last Name are required).";
                        continue;
                    }

                    /* ════════════════════════════════════════════════
                       NEW (Company Type / Request Type import filter
                       revision): skip this row entirely — no
                       companies_import write at all — if its Request
                       Type and/or Company Type isn't one the admin
                       selected in the classification picker. Checked
                       before the "already registered" pre-check below
                       so a row that fails both is reported once, under
                       the more specific, admin-driven reason. */
                    if ($classificationFilterActive) {
                        $reqTypeMatches = ($selectedRequestTypesSet === null) || isset($selectedRequestTypesSet[$request_type_val]);
                        $companyTypeKeyForMatch = $company_type_val === null ? '' : $company_type_val;
                        $companyTypeMatches = ($selectedCompanyTypesSet === null) || isset($selectedCompanyTypesSet[$companyTypeKeyForMatch]);
                        if (!$reqTypeMatches || !$companyTypeMatches) {
                            $companyTypeLabelForSkip = $company_type_val ?: 'Unspecified';
                            $skippedClassificationRows[] = "Row " . $globalRowNumber . ": \"" . htmlspecialchars($company_name ?? '') . "\" (" . htmlspecialchars($request_type_val ?? '') . " / " . htmlspecialchars($companyTypeLabelForSkip ?? '') . ") does not match the Company Type / Request Type you chose to import — skipped.";
                            continue;
                        }
                    }

                    /* ════════════════════════════════════════════════
                       FIX (part 5 — the actual "skip entry still saves"
                       bug): if this row's company name or email already
                       belongs to a FULLY REGISTERED company, it does not
                       need — and must never get — a companies_import
                       staging row. Previously this row would still be
                       inserted/updated into companies_import, and would
                       then only get caught later by the automatic
                       account-creation pass (as a "skipped — email
                       already registered" outcome), which does NOT
                       delete the row — leaving a permanent, duplicate
                       "Imported" entry sitting next to the real
                       "Registered" one. This check stops that staging
                       row from ever being written in the first place.
                       This is intentionally kept OUT of $errorRows so it
                       never causes the whole batch to roll back — it's
                       not a bad row, it's simply a row that doesn't need
                       to be staged. ════════════════════════════════════ */
                    $companyNameKey = mb_strtolower(trim($company_name));
                    $emailKeyForDupCheck = $email_val !== '' ? mb_strtolower(trim($email_val)) : '';

                    if ($companyNameKey !== '' && isset($existingRegisteredCompanyNames[$companyNameKey])) {
                        $skippedAlreadyRegisteredRows[] = "Row " . $globalRowNumber . ": \"" . htmlspecialchars($company_name ?? '') . "\" already has a registered company account — skipped instead of creating a duplicate imported entry.";
                        continue;
                    }
                    if ($emailKeyForDupCheck !== '' && isset($existingRegisteredEmails[$emailKeyForDupCheck])) {
                        $skippedAlreadyRegisteredRows[] = "Row " . $globalRowNumber . ": the email \"" . htmlspecialchars($email_val ?? '') . "\" already belongs to a registered company account — skipped instead of creating a duplicate imported entry.";
                        continue;
                    }

                    $stmt = $conn->prepare("INSERT INTO companies_import
                        (company, company_address, telephone, contact_first_name, contact_middle_name, contact_last_name, position, request_type, email, company_type)
                        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
                        ON DUPLICATE KEY UPDATE
                        company_address = VALUES(company_address),
                        telephone = VALUES(telephone),
                        contact_first_name = VALUES(contact_first_name),
                        contact_middle_name = VALUES(contact_middle_name),
                        contact_last_name = VALUES(contact_last_name),
                        position = VALUES(position),
                        request_type = VALUES(request_type),
                        email = VALUES(email),
                        company_type = VALUES(company_type)");

                    if (empty($contact_middle)) $contact_middle = null;
                    if ($email_val === '') $email_val = null;
                    $importRowTypes = str_repeat('s', 10);
                    $stmt->bind_param($importRowTypes, $company_name, $company_address, $telephone, $contact_first, $contact_middle, $contact_last, $position_val, $request_type_val, $email_val, $company_type_val);

                    if ($stmt->execute()) {
                        if ($stmt->affected_rows == 1) $importedCount++;
                        elseif ($stmt->affected_rows > 1) $updatedCount++;
                        /* NEW: track this company so we can look up its
                           companies_import id after commit and, per the
                           new auto-account-creation requirement, attempt
                           to create a login account for it automatically
                           right after a successful import — no manual
                           selection/"Create Account" click required. */
                        $autoCreateAccountCompanyNames[] = $company_name;
                    } else {
                        $errorRows[] = "Row " . $globalRowNumber . ": Database error - " . $stmt->error;
                    }
                    $stmt->close();
                }
            }

            if (empty($errorRows)) {
                $conn->commit();
                /* UPDATED (this revision — suppress the "0 new / 0
                   existing" noise): the plain-text success alert used to
                   be shown unconditionally after every successful import,
                   even when nothing was actually imported or updated
                   (e.g. re-importing a file consisting entirely of
                   already-registered companies). That produced a
                   confusing "Successfully imported 0 new companies and
                   updated 0 existing companies." message with no real
                   information for the admin. The alert now only appears
                   when at least one row was actually imported or
                   updated; when both counts are zero, no plain-text
                   alert is shown here at all — the "Creating Company
                   Accounts" popup (fed by
                   $auto_create_account_pre_skipped_details below) still
                   reports the already-registered skips exactly as
                   before, so no information is lost. */
                if ($importedCount > 0 || $updatedCount > 0) {
                    $company_import_message = "Successfully imported $importedCount new companies and updated $updatedCount existing companies.";
                    $company_import_message_type = "success";
                } else {
                    $company_import_message = '';
                    $company_import_message_type = '';
                }

                /* NEW (Company Type / Request Type import filter
                   revision): report how many rows were skipped because
                   their classification didn't match what the admin
                   selected in the picker, appended to whatever message
                   is already set above (or set fresh, as an informational
                   "success"-styled note, if nothing was imported/updated
                   at all but rows WERE filtered out — so the admin isn't
                   left wondering why a file that clearly had data
                   produced no visible result). */
                if (!empty($skippedClassificationRows)) {
                    $classificationSkipNote = count($skippedClassificationRows) . " row(s) were skipped because their Company Type / Request Type didn't match what you chose to import.";
                    $company_import_message = $company_import_message !== ''
                        ? $company_import_message . ' ' . $classificationSkipNote
                        : $classificationSkipNote;
                    if ($company_import_message_type === '') $company_import_message_type = 'success';
                }

                /* NEW (part 6 / popup restore fix): feed the exact same
                   already-registered skip messages into the popup's
                   data source. This is what makes the "Creating Company
                   Accounts" popup open and show these as Skipped even
                   though none of them were ever staged into
                   companies_import (so they have no id for
                   run_company_account_creation_batch() to process) —
                   see window.autoCreateAccountPreSkippedDetails and the
                   updated runAutoAccountCreation() near the bottom of
                   this file. */
                if (!empty($skippedAlreadyRegisteredRows)) {
                    $auto_create_account_pre_skipped_details = array_merge(
                        $auto_create_account_pre_skipped_details,
                        $skippedAlreadyRegisteredRows
                    );
                }

                /* ════════════════════════════════════════════════════
                   NEW: resolve the companies_import ids for every row
                   we just inserted/updated in this batch, so the page
                   can automatically attempt to create a login account
                   for each of them immediately after import — the
                   admin no longer needs to select these entries and
                   click "Create Account" themselves. The actual
                   account-creation call happens client-side (JS) right
                   after the page loads, reusing the exact same
                   run_company_account_creation_batch()/
                   attempt_create_company_account() logic used by the
                   manual Add Company flow above, so behavior/validation
                   stays identical either way.
                   ════════════════════════════════════════════════════ */
                if (!empty($autoCreateAccountCompanyNames)) {
                    $uniqueImportedNames = array_values(array_unique($autoCreateAccountCompanyNames));
                    $namePlaceholders = implode(',', array_fill(0, count($uniqueImportedNames), '?'));
                    $nameTypes = str_repeat('s', count($uniqueImportedNames));
                    $idLookupStmt = $conn->prepare("SELECT id FROM companies_import WHERE company IN ($namePlaceholders)");
                    $idLookupStmt->bind_param($nameTypes, ...$uniqueImportedNames);
                    $idLookupStmt->execute();
                    $idLookupRes = $idLookupStmt->get_result();
                    while ($idLookupRow = $idLookupRes->fetch_assoc()) {
                        $auto_create_account_import_ids[] = (int) $idLookupRow['id'];
                    }
                    $idLookupStmt->close();
                }
            } else {
                $conn->rollback();
                $company_import_message = "Import failed with errors:<br>" . implode("<br>", array_slice($errorRows, 0, 10));
                if (count($errorRows) > 10) $company_import_message .= "<br>...and " . (count($errorRows) - 10) . " more errors.";
                $company_import_message_type = "error";
            }

            } // end else ($importHeaderMapUsable)
        } catch (Exception $e) {
            $company_import_message = "Error processing file: " . $e->getMessage();
            $company_import_message_type = "error";
        }
    }
}

/* ════════════════════════════════════════════════════════════════════
   NEW (Delete revision): cascade-delete helper for REGISTERED company
   accounts — a direct port of monitoring.php's
   cascadeDeleteAccountRecords() (renamed so it can never collide with
   that function if both files are ever included together). Removes the
   account's rows from every other table that references it, so
   deleting a company from this list behaves EXACTLY like deleting it
   from monitoring.php ("Manage Accounts") — no orphaned data is left
   behind. Tables/columns are checked for existence first, and a
   verification pass throws if anything is left over, so the caller's
   transaction rolls everything back instead of leaving a half-deleted
   account.
   ════════════════════════════════════════════════════════════════════ */
function company_list_cascade_delete_account_records(mysqli $conn, int $id, string $email, string $role): void {
    if ($id <= 0) return;

    // table => columns that may hold this account's users.id
    $targets = [];
    if ($role === 'student' || $role === 'company') {
        // shared / activity
        $targets['reports']                     = ['user_id', 'company_id'];
        $targets['ojt_assignments']             = ['student_id', 'company_id'];
        $targets['admin_application_approvals'] = ['student_id', 'company_id'];
        $targets['moa_requests']                = ['user_id'];
        $targets['activity_logs']               = ['user_id'];
        // student side
        $targets['student_information']         = ['user_id'];
        $targets['student_skills']              = ['user_id'];
        $targets['student_experience']          = ['user_id'];
        $targets['requirements']                = ['user_id'];
        $targets['attendance_logs']             = ['user_id', 'company_id'];
        $targets['late_requests']               = ['student_id', 'company_id'];
        $targets['final_grades']                = ['student_id', 'company_id'];
        $targets['ojt_applications']            = ['student_id', 'company_id'];
        $targets['application_requirements']    = ['student_id', 'company_id'];
        $targets['ojt_reminder_log']            = ['user_id'];
        $targets['archived_students']           = ['user_id'];
        // company side
        $targets['company_information']         = ['user_id'];
        $targets['company_profile']             = ['user_id'];
        $targets['company_requirements']        = ['user_id'];
        $targets['company_requirement_upload_notifications'] = ['user_id'];   // NEW (this adjustment): Company Requirements inbox / side-menu notifications
        $targets['company_messages']            = ['company_id'];
        $targets['attendance_settings']         = ['company_id'];
        $targets['archived_companies']          = ['user_id'];
    }

    // Resolve which of the target tables/columns really exist in this DB.
    $existingCols = function (string $table) use ($conn): array {
        $cols = [];
        $stmt = $conn->prepare("SELECT COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?");
        if ($stmt) {
            $stmt->bind_param("s", $table);
            $stmt->execute();
            $stmt->bind_result($colName);
            while ($stmt->fetch()) $cols[] = $colName;
            $stmt->close();
        }
        return $cols;
    };

    $resolved = []; // table => WHERE clause
    foreach ($targets as $table => $wantedCols) {
        $have = $existingCols($table);
        if (empty($have)) continue; // table doesn't exist here
        $conds = [];
        foreach ($wantedCols as $col) {
            if (in_array($col, $have, true)) $conds[] = "`{$col}` = {$id}";
        }
        if (!empty($conds)) $resolved[$table] = implode(' OR ', $conds);
    }

    // Company chat messages are also keyed by the company's email address.
    $messageEmailCleanup = ($role === 'company' && $email !== '' && isset($resolved['company_messages']));

    // ── Delete pass ──
    foreach ($resolved as $table => $where) {
        if (!$conn->query("DELETE FROM `{$table}` WHERE {$where}")) {
            throw new \RuntimeException("Failed to delete records from {$table}: " . $conn->error);
        }
    }
    if ($messageEmailCleanup) {
        $stmt = $conn->prepare("DELETE FROM company_messages WHERE sender_email = ? OR receiver_email = ?");
        if (!$stmt) throw new \RuntimeException("Failed to prepare company_messages cleanup: " . $conn->error);
        $stmt->bind_param("ss", $email, $email);
        $stmt->execute();
        $stmt->close();
    }

    // Any pending/past email-recovery requests tied to this account's email.
    $recoveryExists = false;
    if ($email !== '') {
        $recoveryExists = !empty($existingCols('email_recovery_requests'));
        if ($recoveryExists) {
            $stmt = $conn->prepare("DELETE FROM email_recovery_requests WHERE old_email = ? OR new_email = ?");
            if (!$stmt) throw new \RuntimeException("Failed to prepare email_recovery_requests cleanup: " . $conn->error);
            $stmt->bind_param("ss", $email, $email);
            $stmt->execute();
            $stmt->close();
        }
    }

    // ── Verification pass: make sure nothing belonging to the account is left ──
    $leftover = [];
    foreach ($resolved as $table => $where) {
        $chk = $conn->query("SELECT COUNT(*) AS c FROM `{$table}` WHERE {$where}");
        if ($chk) {
            $row = $chk->fetch_assoc();
            if ((int)($row['c'] ?? 0) > 0) $leftover[] = $table;
        }
    }
    if ($messageEmailCleanup) {
        $stmt = $conn->prepare("SELECT COUNT(*) FROM company_messages WHERE sender_email = ? OR receiver_email = ?");
        if ($stmt) {
            $stmt->bind_param("ss", $email, $email);
            $stmt->execute();
            $stmt->bind_result($cnt);
            $stmt->fetch();
            $stmt->close();
            if ((int)$cnt > 0) $leftover[] = 'company_messages (by email)';
        }
    }
    if ($recoveryExists) {
        $stmt = $conn->prepare("SELECT COUNT(*) FROM email_recovery_requests WHERE old_email = ? OR new_email = ?");
        if ($stmt) {
            $stmt->bind_param("ss", $email, $email);
            $stmt->execute();
            $stmt->bind_result($cnt2);
            $stmt->fetch();
            $stmt->close();
            if ((int)$cnt2 > 0) $leftover[] = 'email_recovery_requests';
        }
    }
    if (!empty($leftover)) {
        throw new \RuntimeException("Records still remain in: " . implode(', ', $leftover));
    }
}

/* ════════════════════════════════════════════════════════════════════
   NEW (Delete revision): permanently deletes ONE registered company
   account, mirroring monitoring.php's ajax_delete_account handler
   step-for-step: look up the account, capture its details for the
   "Account Deleted" popup BEFORE anything is removed, run the cascade
   + the users-row delete inside ONE transaction (all-or-nothing),
   verify the row is really gone, then write an 'Account Deleted'
   activity_logs entry. Only ever touches a users row whose role is
   'company', so nothing else can be deleted through this path.
   ════════════════════════════════════════════════════════════════════ */
function company_list_delete_company_account(mysqli $conn, int $userId, string $performerName): array {
    $result = ['success' => false, 'company' => 'Account #' . $userId, 'message' => '', 'details' => []];
    if ($userId <= 0) {
        $result['message'] = 'Invalid company account reference.';
        return $result;
    }

    $lookup = $conn->prepare("SELECT u.first_name, u.middle_name, u.last_name, u.email, ci.company
        FROM users u
        LEFT JOIN company_information ci ON ci.user_id = u.id
        WHERE u.id = ? AND u.role = 'company'
        LIMIT 1");
    $lookup->bind_param("i", $userId);
    $lookup->execute();
    $acc = $lookup->get_result()->fetch_assoc();
    $lookup->close();

    if (!$acc) {
        $result['message'] = 'Account #' . $userId . ': this company account could not be found (it may have already been deleted).';
        return $result;
    }

    $accEmail    = (string)($acc['email'] ?? '');
    $accFullname = trim(($acc['first_name'] ?? '') . ' ' . (!empty($acc['middle_name']) ? $acc['middle_name'] . ' ' : '') . ($acc['last_name'] ?? ''));
    if ($accFullname === '') $accFullname = 'Unknown';
    $companyName = trim((string)($acc['company'] ?? ''));
    $result['company'] = $companyName !== '' ? $companyName : $accFullname;

    $details = [
        ['label' => 'Account Type', 'value' => 'Company (Registered Account)'],
        ['label' => 'Company Name', 'value' => $companyName !== '' ? $companyName : 'N/A'],
        ['label' => 'Contact Person', 'value' => $accFullname],
        ['label' => 'Email', 'value' => $accEmail !== '' ? $accEmail : 'N/A'],
        ['label' => 'Account ID', 'value' => '#' . $userId],
        ['label' => 'Deleted By', 'value' => $performerName],
        ['label' => 'Deleted On', 'value' => date('M d, Y h:i A')],
    ];

    try {
        $conn->begin_transaction();

        company_list_cascade_delete_account_records($conn, $userId, $accEmail, 'company');

        $delStmt = $conn->prepare("DELETE FROM users WHERE id = ? AND role = 'company'");
        $delStmt->bind_param("i", $userId);
        $delStmt->execute();
        $delStmt->close();

        $verifyStmt = $conn->prepare("SELECT COUNT(*) FROM users WHERE id = ?");
        $verifyStmt->bind_param("i", $userId);
        $verifyStmt->execute();
        $verifyStmt->bind_result($stillThere);
        $verifyStmt->fetch();
        $verifyStmt->close();
        if ((int)$stillThere > 0) {
            throw new \RuntimeException("Account row could not be removed from users.");
        }

        $conn->commit();
    } catch (\Throwable $e) {
        try { $conn->rollback(); } catch (\Throwable $ignored) {}
        error_log('[admin_company_list.php] delete company account failed: ' . $e->getMessage());
        $result['message'] = htmlspecialchars($result['company'] ?? '') . ': could not fully delete this account and its records, so nothing was deleted for it. Please try again.';
        return $result;
    }

    // Activity log entry — same shape monitoring.php writes. Best-effort:
    // a logging failure never undoes the (already committed) deletion.
    try {
        $roleLabel = 'Company';
        $logDetails = "Company account deleted for $accFullname" . ($companyName !== '' ? " ($companyName)" : '') . " via Company List";
        $logStmt = $conn->prepare("INSERT INTO activity_logs (action_type, performed_by, account_type, account_name, details, created_at)
            VALUES ('Account Deleted', ?, ?, ?, ?, NOW())");
        if ($logStmt) {
            $logStmt->bind_param("ssss", $performerName, $roleLabel, $accFullname, $logDetails);
            $logStmt->execute();
            $logStmt->close();
        }
    } catch (\Throwable $ignored) {}

    $result['success'] = true;
    $result['details'] = $details;
    return $result;
}

/* ════════════════════════════════════════════════════════════════════
   Handle "delete selected entries" — the checkbox-driven Delete action
   above the table. Mirrors the AJAX (fetch) pattern used by the Add
   Company modal above so the table refreshes in place without a full
   page reload.

   UPDATED (Delete revision): the Delete button now also deletes
   REGISTERED company ACCOUNTS, exactly like the Delete button on
   monitoring.php ("Manage Accounts"):
     - company_ids[]      → imported/manually-added entries
                            (companies_import rows) — deleted exactly as
                            before.
     - company_user_ids[] → registered company accounts (users.id) —
                            each one is permanently deleted together
                            with every related record, in its own
                            all-or-nothing transaction, via
                            company_list_delete_company_account() above.
   The JSON response now also carries a per-company breakdown
   ('deleted' / 'failed') so the page can show an "Account Deleted"
   notification popup listing exactly what was removed.
   ════════════════════════════════════════════════════════════════════ */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_selected_companies'])) {
    $isAjaxDeleteSelected = (($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '') === 'XMLHttpRequest');

    $selected_ids = [];
    if (isset($_POST['company_ids']) && is_array($_POST['company_ids'])) {
        foreach ($_POST['company_ids'] as $rawId) {
            $intId = (int) $rawId;
            if ($intId > 0) $selected_ids[] = $intId;
        }
    }
    $selected_ids = array_values(array_unique($selected_ids));

    /* NEW (Delete revision): registered company accounts selected for deletion. */
    $selected_user_ids = [];
    if (isset($_POST['company_user_ids']) && is_array($_POST['company_user_ids'])) {
        foreach ($_POST['company_user_ids'] as $rawId) {
            $intId = (int) $rawId;
            if ($intId > 0) $selected_user_ids[] = $intId;
        }
    }
    $selected_user_ids = array_values(array_unique($selected_user_ids));

    $delete_selected_message = '';
    $delete_selected_message_type = '';
    $deleted_entries = [];   // [{company, kind, details:[{label,value}]}]
    $failed_entries  = [];   // [string]

    $performer_name = trim(trim($_SESSION['first_name'] ?? '') . ' ' . trim($_SESSION['last_name'] ?? ''));
    if ($performer_name === '') $performer_name = 'Admin';

    if (empty($selected_ids) && empty($selected_user_ids)) {
        $delete_selected_message = "No entries were selected for deletion.";
        $delete_selected_message_type = "error";
    } else {
        $deletedImportCount  = 0;
        $deletedAccountCount = 0;

        /* ── Imported / manually-added entries (original logic) ── */
        if (!empty($selected_ids)) {
            $placeholders = implode(',', array_fill(0, count($selected_ids), '?'));
            $types = str_repeat('i', count($selected_ids));

            // Capture details first so the popup can show what was removed.
            $importDetailsById = [];
            $infoStmt = $conn->prepare("SELECT id, company, contact_first_name, contact_last_name, email FROM companies_import WHERE id IN ($placeholders)");
            if ($infoStmt) {
                $infoStmt->bind_param($types, ...$selected_ids);
                $infoStmt->execute();
                $infoRes = $infoStmt->get_result();
                while ($ir = $infoRes->fetch_assoc()) $importDetailsById[(int)$ir['id']] = $ir;
                $infoStmt->close();
            }

            $delStmt = $conn->prepare("DELETE FROM companies_import WHERE id IN ($placeholders)");
            $delStmt->bind_param($types, ...$selected_ids);
            if ($delStmt->execute()) {
                $deletedImportCount = $delStmt->affected_rows;
                if ($deletedImportCount > 0) {
                    foreach ($importDetailsById as $impId => $ir) {
                        $contact = trim(($ir['contact_first_name'] ?? '') . ' ' . ($ir['contact_last_name'] ?? ''));
                        $deleted_entries[] = [
                            'company' => $ir['company'],
                            'kind'    => 'imported',
                            'details' => [
                                ['label' => 'Account Type', 'value' => 'Imported Entry (no account yet)'],
                                ['label' => 'Company Name', 'value' => $ir['company']],
                                ['label' => 'Contact Person', 'value' => $contact !== '' ? $contact : 'N/A'],
                                ['label' => 'Email', 'value' => !empty($ir['email']) ? $ir['email'] : 'N/A'],
                                ['label' => 'Entry ID', 'value' => '#' . $impId],
                                ['label' => 'Deleted By', 'value' => $performer_name],
                                ['label' => 'Deleted On', 'value' => date('M d, Y h:i A')],
                            ],
                        ];
                    }
                } else {
                    $failed_entries[] = "The selected imported entries could not be found (they may have already been removed).";
                }
            } else {
                $failed_entries[] = "Error deleting selected imported entries: " . $delStmt->error;
            }
            $delStmt->close();
        }

        /* ── NEW: registered company accounts (monitoring.php-style delete) ── */
        foreach ($selected_user_ids as $delUserId) {
            $accRes = company_list_delete_company_account($conn, $delUserId, $performer_name);
            if ($accRes['success']) {
                $deletedAccountCount++;
                $deleted_entries[] = [
                    'company' => $accRes['company'],
                    'kind'    => 'registered',
                    'details' => $accRes['details'],
                ];
            } else {
                $failed_entries[] = $accRes['message'];
            }
        }

        $totalDeleted = $deletedImportCount + $deletedAccountCount;
        if ($totalDeleted > 0) {
            $parts = [];
            if ($deletedAccountCount > 0) $parts[] = $deletedAccountCount . " company account" . ($deletedAccountCount === 1 ? "" : "s");
            if ($deletedImportCount > 0)  $parts[] = $deletedImportCount . " imported entr" . ($deletedImportCount === 1 ? "y" : "ies");
            $delete_selected_message = "Successfully deleted " . implode(' and ', $parts) . ".";
            if (!empty($failed_entries)) {
                $delete_selected_message .= "<br>Some entries could not be deleted:<br>" . implode('<br>', $failed_entries);
            }
            $delete_selected_message_type = "success";
        } else {
            $delete_selected_message = !empty($failed_entries)
                ? implode('<br>', $failed_entries)
                : "The selected entries could not be found (they may have already been removed).";
            $delete_selected_message_type = "error";
        }
    }

    if ($isAjaxDeleteSelected) {
        header('Content-Type: application/json');
        echo json_encode([
            'success' => $delete_selected_message_type === 'success',
            'message' => $delete_selected_message,
            'deleted' => $deleted_entries,
            'failed'  => $failed_entries,
        ]);
        exit;
    } else {
        $company_import_message = $delete_selected_message;
        $company_import_message_type = $delete_selected_message_type;
    }
}

/* ════════════════════════════════════════════════════════════════════
   NEW (PHASE 2): "Create Account" — converts selected imported /
   manually-added companies (companies_import rows) into REAL company
   accounts, mirroring company_register.php's account-creation logic
   exactly. The actual per-company logic now lives in
   attempt_create_company_account() near the top of this file (shared
   with the manual Add Company flow's automatic account creation); this
   endpoint is a thin wrapper around run_company_account_creation_batch()
   for that shared logic.

   UPDATED (this revision): the dedicated toolbar "Create Account"
   button/selection-mode UI has been REMOVED from the page — account
   creation is now fully automatic for both XLSX imports and manual
   "Add Company" submissions (see both handlers above), so the admin
   never needs to select rows and trigger this by hand. This endpoint
   itself is intentionally KEPT and left otherwise unchanged: it is
   still what the automatic client-side flow calls (via fetch, see
   runAutoAccountCreation() in the script below) right after a
   successful XLSX import (batch) or a manual add whose account wasn't
   cleanly created outright (e.g. a fixable "skipped" reason), so
   nothing about its request/response contract changed — only the
   manual trigger button that used to call it directly was removed.

   This can run for one or many selected rows at once. Only ever called
   via fetch() (identified by the X-Requested-With header), and always
   responds with JSON: a created/skipped/failed breakdown (both as
   counts and as a detailed, per-company list), so a partial batch never
   silently fails and the caller can render an accurate summary either
   way.

   Counts/details are split into three explicit buckets so the UI can
   show "Successfully Created / Skipped / Failed":
     - created: the account was created (even if the password email
       itself could not be sent — that is reported separately, but the
       account still exists and still counts as created).
     - skipped: nothing was created because the row could not be
       processed for a "soft"/expected reason — missing or invalid
       email, the email is already registered to an existing account, or
       the row could no longer be found (e.g. it was already converted
       or deleted by someone else in the meantime).
     - failed: an unexpected database/technical error occurred while
       actually trying to create the account or its company profile.
   ════════════════════════════════════════════════════════════════════ */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['create_accounts_selected'])) {
    header('Content-Type: application/json');

    $selected_import_ids = [];
    if (isset($_POST['import_ids']) && is_array($_POST['import_ids'])) {
        foreach ($_POST['import_ids'] as $rawId) {
            $intId = (int) $rawId;
            if ($intId > 0) $selected_import_ids[] = $intId;
        }
    }
    $selected_import_ids = array_values(array_unique($selected_import_ids));

    if (empty($selected_import_ids)) {
        echo json_encode([
            'success' => false,
            'message' => 'No entries were selected for account creation.',
            'counts'  => ['created' => 0, 'skipped' => 0, 'failed' => 0],
            'details' => ['skipped' => [], 'failed' => [], 'created_with_email_issues' => []],
        ]);
        exit;
    }

    $batchResult = run_company_account_creation_batch($conn, $selected_import_ids);
    $createdCount = $batchResult['counts']['created'];
    $skippedCount = $batchResult['counts']['skipped'];
    $failedCount  = $batchResult['counts']['failed'];
    $skippedDetails = $batchResult['details']['skipped'];
    $failedDetails  = $batchResult['details']['failed'];
    $createdWithEmailIssues = $batchResult['details']['created_with_email_issues'];

    $success = $createdCount > 0;
    $messageParts = [];
    $messageParts[] = "Created: " . $createdCount . " | Skipped: " . $skippedCount . " | Failed: " . $failedCount . ".";
    if (!empty($skippedDetails)) {
        $messageParts[] = "Skipped:<br>" . implode('<br>', $skippedDetails);
    }
    if (!empty($failedDetails)) {
        $messageParts[] = "Failed:<br>" . implode('<br>', $failedDetails);
    }
    if (!empty($createdWithEmailIssues)) {
        $messageParts[] = "Created, but the password email could not be sent:<br>" . implode('<br>', $createdWithEmailIssues);
    }
    if (empty($messageParts)) {
        $messageParts[] = "No accounts were created.";
    }

    echo json_encode([
        'success' => $success,
        'message' => implode('<br><br>', $messageParts),
        'counts'  => [
            'created' => $createdCount,
            'skipped' => $skippedCount,
            'failed'  => $failedCount,
        ],
        'details' => [
            'skipped' => $skippedDetails,
            'failed' => $failedDetails,
            'created_with_email_issues' => $createdWithEmailIssues,
        ],
    ]);
    exit;
}

/* ════════════════════════════════════════════════════════════════════
   NEW: Handle "Admin Copy of MOA" upload — a PDF the admin attaches
   themselves for a company already known to have a signed MOA
   ("Existing Company" request type / has_moa=1), independent of the
   MOA document the company itself submitted. Works for both registered
   companies (stored in company_requirements, requirement_type =
   'admin_moa_document') and imported/manually-added companies (stored
   directly on companies_import.admin_moa_document). Always responds
   with JSON since this action is only ever triggered via fetch().

   This is, and remains, the ONLY place (together with the Edit modal's
   optional field) that ever writes to admin_moa_document /
   'admin_moa_document' requirement rows — it never touches
   moa_document / has_moa (the company's own MOA).
   ════════════════════════════════════════════════════════════════════ */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['upload_admin_moa'])) {
    header('Content-Type: application/json');

    $target_user_id   = isset($_POST['admin_moa_user_id']) ? (int) $_POST['admin_moa_user_id'] : 0;
    $target_import_id = isset($_POST['admin_moa_import_id']) ? (int) $_POST['admin_moa_import_id'] : 0;

    if (!isset($_FILES['admin_moa_file']) || $_FILES['admin_moa_file']['error'] !== 0 || empty($_FILES['admin_moa_file']['tmp_name'])) {
        echo json_encode(['success' => false, 'message' => 'Please choose a PDF file to upload.']);
        exit;
    }

    $adminMoaBlob = file_get_contents($_FILES['admin_moa_file']['tmp_name']);
    $finfoAdminMoa = new finfo(FILEINFO_MIME_TYPE);
    $adminMoaMime  = $finfoAdminMoa->buffer($adminMoaBlob);

    if ($adminMoaMime !== 'application/pdf') {
        echo json_encode(['success' => false, 'message' => 'The admin MOA copy must be a PDF file.']);
        exit;
    }

    if ($target_user_id > 0) {
        $reqType = 'admin_moa_document';
        $stmt = $conn->prepare("INSERT INTO company_requirements (user_id, requirement_type, file_name)
            VALUES (?, ?, ?)
            ON DUPLICATE KEY UPDATE file_name = VALUES(file_name)");
        $stmt->bind_param("iss", $target_user_id, $reqType, $adminMoaBlob);
        $ok = $stmt->execute();
        $err = $stmt->error;
        $stmt->close();
        echo json_encode(['success' => (bool)$ok, 'message' => $ok ? 'Admin copy of the MOA uploaded successfully!' : ('Error uploading file: ' . $err)]);
        exit;
    } elseif ($target_import_id > 0) {
        $stmt = $conn->prepare("UPDATE companies_import SET admin_moa_document = ? WHERE id = ?");
        $stmt->bind_param("si", $adminMoaBlob, $target_import_id);
        $ok = $stmt->execute();
        $err = $stmt->error;
        $stmt->close();
        echo json_encode(['success' => (bool)$ok, 'message' => $ok ? 'Admin copy of the MOA uploaded successfully!' : ('Error uploading file: ' . $err)]);
        exit;
    } else {
        echo json_encode(['success' => false, 'message' => 'Invalid company reference.']);
        exit;
    }
}

/* ════════════════════════════════════════════════════════════════════
   NEW (Requirements column revision): "View Requirements" — read-only
   AJAX endpoint backing the new "Requirements" column's modal for
   REGISTERED companies. Reports every compliance requirement the
   company has on file (i.e. everything in company_requirements for
   this user EXCEPT the MOA document, which keeps its own dedicated
   column), aggregated per requirement_type exactly the same way
   company_validation.php's own "Requirements" tab summary counts each
   card (see that file's $vsAgg / $vsRowsByType logic, which this
   mirrors): a type is 'Verified' once it has at least one submitted
   file and every submitted file is Verified, 'Awaiting' when it has no
   submitted file at all yet, otherwise 'Pending' (something is on file
   and still being reviewed, or was Rejected/Denied — company_
   validation.php is where an admin acts on any of that; this page only
   ever displays it).

   This is intentionally read-only in every sense: it never inserts,
   updates, or deletes a company_requirements row, and never touches
   status/remark/workflow fields — verifying, rejecting, or otherwise
   acting on a requirement stays exclusively on company_validation.php,
   completely untouched by this addition.
   ════════════════════════════════════════════════════════════════════ */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['ajax_fetch_requirements'])) {
    header('Content-Type: application/json');

    $req_uid = isset($_POST['user_id']) ? (int) $_POST['user_id'] : 0;
    if ($req_uid <= 0) {
        echo json_encode(['success' => false, 'message' => 'Invalid company.']);
        exit;
    }

    $chkStmt = $conn->prepare("SELECT id FROM users WHERE id = ? AND role = 'company'");
    $chkStmt->bind_param("i", $req_uid);
    $chkStmt->execute();
    $chkRow = $chkStmt->get_result()->fetch_assoc();
    $chkStmt->close();
    if (!$chkRow) {
        echo json_encode(['success' => false, 'message' => 'This company account could not be found.']);
        exit;
    }

    /* NEW (Existing requirement revision): also read the first few KB of
       each file so its real mime type can be reported to the viewer
       (images are then shown centered in an <img>, PDFs in the frame). */
    $reqStmt = $conn->prepare("SELECT id, requirement_type, status, (file_name IS NOT NULL AND LENGTH(file_name) > 0) AS has_file,
            SUBSTRING(file_name, 1, 8192) AS file_head
        FROM company_requirements
        WHERE user_id = ? AND requirement_type NOT IN ('moa_document','moa','moa_existing_upload')
        ORDER BY requirement_type ASC, id ASC");
    $reqStmt->bind_param("i", $req_uid);
    $reqStmt->execute();
    $reqRes = $reqStmt->get_result();

    // Fold potentially several rows per requirement_type (one row per
    // uploaded file, same shape as CompanyForm.php's multi-file inputs)
    // into a single aggregate per type — same approach as
    // company_validation.php's own $vsAgg.
    $byType = [];
    while ($row = $reqRes->fetch_assoc()) {
        $type = $row['requirement_type'];
        if (!isset($byType[$type])) {
            $byType[$type] = ['files' => [], 'notVerified' => 0];
        }
        if (!empty($row['has_file'])) {
            $fileMime = 'application/pdf';
            if (!empty($row['file_head'])) {
                $detectedMime = (new finfo(FILEINFO_MIME_TYPE))->buffer($row['file_head']);
                if ($detectedMime && $detectedMime !== 'application/octet-stream') $fileMime = $detectedMime;
            }
            $byType[$type]['files'][] = [
                'id'     => (int) $row['id'],
                'status' => $row['status'] ?: 'Pending',
                'mime'   => $fileMime,
            ];
            if (($row['status'] ?? '') !== 'Verified') {
                $byType[$type]['notVerified']++;
            }
        }
    }
    $reqStmt->close();

    /* ════════════════════════════════════════════════════════════════
       NEW (Existing requirement revision): the list now follows the SAME
       per-company checklist company_validation.php uses (see its
       $private_compliance_reqs / $public_compliance_reqs,
       resolveCompanyType() and isCompanyRequestTypeExisting()):
         - Private companies → the 10 private compliance items,
           Public companies  → the 3 public compliance items;
         - a company whose request type is ACTUALLY "Existing" also gets
           "MOA Document (Existing Partnership)" (moa_existing_upload).
       Checklist items the company hasn't uploaded yet are still listed
       (status "Awaiting", no file), in checklist order. Any other
       submitted requirement type not on that checklist is appended after
       it, exactly as before, so nothing that used to show disappears.
       Read-only — nothing is written.
       ════════════════════════════════════════════════════════════════ */
    $checklistOrder = company_list_requirement_checklist($conn, $req_uid);
    $orderedTypes = $checklistOrder;
    foreach (array_keys($byType) as $submittedType) {
        if (!in_array($submittedType, $orderedTypes, true)) $orderedTypes[] = $submittedType;
    }

    $requirements = [];
    foreach ($orderedTypes as $type) {
        $data = $byType[$type] ?? ['files' => [], 'notVerified' => 0];
        $fileCount = count($data['files']);
        if ($fileCount > 0 && $data['notVerified'] === 0) {
            $status = 'Verified';
        } elseif ($fileCount === 0) {
            $status = 'Awaiting';
        } else {
            $status = 'Pending';
        }
        $requirements[] = [
            'type'   => $type,
            'label'  => format_requirement_label($type),
            'status' => $status,
            'files'  => $data['files'],
        ];
    }

    echo json_encode(['success' => true, 'requirements' => $requirements]);
    exit;
}

/* ════════════════════════════════════════════════════════════════════
   NEW (cross-table sync revision): helpers used by the Edit handler
   below so that one admin edit is written to every table that stores
   the company's data (users, company_information, company_requirements,
   moa_requests, companies_import) instead of only companies_import.
   ════════════════════════════════════════════════════════════════════ */

/* A validation/conflict problem the admin can fix (as opposed to a raw
   database error) — its message is shown to the admin as-is. */
class CompanyEditConflictException extends \Exception {}

/* PHP mirror of sql_request_type_recognized(): 'New' / 'Existing' for any
   recognisable spelling, '' otherwise. */
function php_normalize_request_type($raw) {
    $v = strtolower(trim((string) $raw));
    if (strpos($v, 'new') === 0)   return 'New';
    if (strpos($v, 'exist') === 0) return 'Existing';
    return '';
}

/* When an existing moa_requests.request_type value has to be flipped
   (New <-> Existing), write the new value in the same "style" the row
   already uses (e.g. 'new' -> 'existing', 'New Company' -> 'Existing
   Company'), so whatever else reads that column keeps working. */
function match_request_type_style($currentRaw, $newType) {
    $current = trim((string) $currentRaw);
    $out = $newType;
    if (preg_match('/\s+company$/i', $current)) $out .= ' Company';
    if ($current !== '' && $current === strtolower($current)) {
        $out = strtolower($out);
    } elseif ($current !== '' && preg_match('/[A-Z]/', $current) && $current === strtoupper($current)) {
        $out = strtoupper($out);
    }
    return $out;
}

/* Runs "UPDATE <table> SET col = ?, ... WHERE <whereSql>" where every SET
   value is bound as a string and the single WHERE parameter is an int id.
   $table / column names / $whereSql are internal constants — never user
   input. Throws on any failure so the caller's transaction can roll back. */
function edit_sync_update_row($conn, $table, array $cols, $whereSql, $whereId) {
    $sets = [];
    $vals = [];
    foreach ($cols as $col => $val) {
        $sets[] = "`$col` = ?";
        $vals[] = $val;
    }
    $types  = str_repeat('s', count($vals)) . 'i';
    $vals[] = (int) $whereId;

    $stmt = $conn->prepare("UPDATE `$table` SET " . implode(', ', $sets) . " WHERE $whereSql");
    if (!$stmt) throw new \RuntimeException($conn->error);
    $stmt->bind_param($types, ...$vals);
    if (!$stmt->execute()) {
        $err = $stmt->error;
        $stmt->close();
        throw new \RuntimeException($err);
    }
    $stmt->close();
}

/* Finds the registered company account (users.id) that is the SAME company
   as an imported row — matched on BOTH email and company name (case/space
   insensitive) — so an imported row that merely shares a contact's email
   with a DIFFERENT company's account is never linked to (and therefore
   never overwrites) that other company. Returns 0 when there is no link. */
function find_linked_company_account($conn, $email, $companyName) {
    $email = trim((string) $email);
    $companyName = trim((string) $companyName);
    if ($email === '' || $companyName === '') return 0;

    $stmt = $conn->prepare("SELECT u.id
        FROM users u
        INNER JOIN company_information ci ON ci.user_id = u.id
        WHERE u.role = 'company'
          AND LOWER(TRIM(u.email)) = LOWER(?)
          AND LOWER(TRIM(ci.company)) = LOWER(?)
        LIMIT 1");
    if (!$stmt) return 0;
    $stmt->bind_param("ss", $email, $companyName);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    return $row ? (int) $row['id'] : 0;
}

/* Writes one admin edit to every table that holds a registered company's
   data. MUST be called inside a transaction (the Edit handler does).
   Returns the list of tables that were written to.

   $d keys: company, address, telephone, first, middle, last, position,
   email, company_type ('' = leave unchanged), request_type
   ('New'/'Existing', or null = leave unchanged).

   $oldReg (registered edits only): the company's pre-edit ['email',
   'company'], used to locate a leftover companies_import row for the
   same company when $syncStagingRow is true. */
function sync_company_edit_to_account($conn, $userId, $oldReg, array $d, $adminMoaBlob, $syncStagingRow) {
    $userId  = (int) $userId;
    $touched = [];

    // The login email must stay unique across all accounts.
    $emailStmt = $conn->prepare("SELECT id FROM users WHERE email = ? AND id <> ? LIMIT 1");
    $emailStmt->bind_param("si", $d['email'], $userId);
    $emailStmt->execute();
    $emailStmt->store_result();
    $emailTaken = $emailStmt->num_rows > 0;
    $emailStmt->close();
    if ($emailTaken) {
        throw new CompanyEditConflictException("This email is already used by another account.");
    }

    // 1) users — contact person, login email, Company Type, Request Type.
    $userCols = [
        'first_name'  => $d['first'],
        'middle_name' => $d['middle'],
        'last_name'   => $d['last'],
        'email'       => $d['email'],
    ];
    if ($d['company_type'] !== '' && $d['company_type'] !== null) $userCols['company_type'] = $d['company_type'];
    if ($d['request_type'] !== null)                              $userCols['request_type'] = $d['request_type'];
    edit_sync_update_row($conn, 'users', $userCols, "id = ? AND role = 'company'", $userId);
    $touched[] = 'users';

    // 2) company_information — the company profile.
    edit_sync_update_row($conn, 'company_information', [
        'company'                => $d['company'],
        'company_address'        => $d['address'],
        'telephone'              => $d['telephone'],
        'contact_first_name'     => $d['first'],
        'contact_middle_initial' => $d['middle'],
        'contact_last_name'      => $d['last'],
        'position'               => $d['position'],
    ], 'user_id = ?', $userId);
    $touched[] = 'company_information';

    // 3) company_requirements — Admin Copy of MOA, only if a new PDF was sent.
    if ($adminMoaBlob !== null) {
        $reqTypeAdminMoa = 'admin_moa_document';
        $moaStmt = $conn->prepare("INSERT INTO company_requirements (user_id, requirement_type, file_name)
            VALUES (?, ?, ?)
            ON DUPLICATE KEY UPDATE file_name = VALUES(file_name)");
        $moaStmt->bind_param("iss", $userId, $reqTypeAdminMoa, $adminMoaBlob);
        if (!$moaStmt->execute()) {
            $err = $moaStmt->error;
            $moaStmt->close();
            throw new \RuntimeException($err);
        }
        $moaStmt->close();
        $touched[] = 'company_requirements';
    }

    // 4) moa_requests — the company's most recent request (the same one
    //    the table's Request Type column reads), only if its type differs.
    if ($d['request_type'] !== null) {
        $mrStmt = $conn->prepare("SELECT id, request_type FROM moa_requests WHERE user_id = ? ORDER BY id DESC LIMIT 1");
        $mrStmt->bind_param("i", $userId);
        $mrStmt->execute();
        $latestMr = $mrStmt->get_result()->fetch_assoc();
        $mrStmt->close();
        if ($latestMr && php_normalize_request_type($latestMr['request_type']) !== $d['request_type']) {
            edit_sync_update_row($conn, 'moa_requests', [
                'request_type' => match_request_type_style($latestMr['request_type'], $d['request_type']),
            ], 'id = ?', (int) $latestMr['id']);
            $touched[] = 'moa_requests';
        }
    }

    // 5) companies_import — a leftover staging row for this same company
    //    (registered edits only; an imported edit has already updated its own row).
    if ($syncStagingRow && is_array($oldReg)) {
        $oldEmail   = trim((string) ($oldReg['email'] ?? ''));
        $oldCompany = trim((string) ($oldReg['company'] ?? ''));
        if ($oldEmail !== '' && $oldCompany !== '') {
            $stgStmt = $conn->prepare("SELECT id FROM companies_import
                WHERE LOWER(TRIM(email)) = LOWER(?) AND LOWER(TRIM(company)) = LOWER(?) LIMIT 1");
            $stgStmt->bind_param("ss", $oldEmail, $oldCompany);
            $stgStmt->execute();
            $stgRow = $stgStmt->get_result()->fetch_assoc();
            $stgStmt->close();

            if ($stgRow) {
                $stagingId = (int) $stgRow['id'];

                $dupStg = $conn->prepare("SELECT id FROM companies_import WHERE company = ? AND id <> ? LIMIT 1");
                $dupStg->bind_param("si", $d['company'], $stagingId);
                $dupStg->execute();
                $dupStg->store_result();
                $stgNameTaken = $dupStg->num_rows > 0;
                $dupStg->close();
                if ($stgNameTaken) {
                    throw new CompanyEditConflictException("Another imported company already uses this name.");
                }

                $stgCols = [
                    'company'            => $d['company'],
                    'company_address'    => $d['address'],
                    'telephone'          => $d['telephone'],
                    'contact_first_name' => $d['first'],
                    'contact_middle_name'=> $d['middle'],
                    'contact_last_name'  => $d['last'],
                    'position'           => $d['position'],
                    'email'              => $d['email'],
                ];
                if ($d['company_type'] !== '' && $d['company_type'] !== null) $stgCols['company_type'] = $d['company_type'];
                if ($d['request_type'] !== null)                              $stgCols['request_type'] = $d['request_type'];
                if ($adminMoaBlob !== null)                                   $stgCols['admin_moa_document'] = $adminMoaBlob;
                edit_sync_update_row($conn, 'companies_import', $stgCols, 'id = ?', $stagingId);
                $touched[] = 'companies_import';
            }
        }
    }

    return $touched;
}

/* ════════════════════════════════════════════════════════════════════
   NEW: Handle "Edit Imported Company" submission — lets an admin edit
   the details of a manually-added/imported company (company name,
   address, telephone, contact person, email, position, request type,
   and — NEW — Company Type) and, OPTIONALLY, replace that row's Admin
   Copy of MOA PDF in the very same request. This handler NEVER reads or
   writes has_moa / moa_document — the company's own submitted MOA
   Document is left completely untouched no matter what is submitted
   here; there is no form field for it at all, by design, so it can't be
   edited from this screen. Only ever called via fetch() from the Edit
   modal, so this always responds with JSON. Only applies to
   companies_import rows (imported/manually-added); registered companies
   are not editable from this screen.

   NEW (this revision): since the per-row "Edit" button/Actions column
   has been removed, this handler is now reached only via the toolbar
   "Edit" button's single-select checkbox flow — the request shape/
   validation/behavior below is completely unchanged aside from the
   added Company Type field.

   UPDATED (cross-table sync revision): an edit is no longer written to
   companies_import alone. It is now saved to every table that stores
   the company's data, all inside ONE transaction (all-or-nothing) —
   see sync_company_edit_to_account() above for the exact table list.
   REGISTERED companies (which live in users + company_information +
   company_requirements + moa_requests, not companies_import) can now be
   edited through this same handler too: the Edit modal sends
   edit_user_id instead of edit_import_id for them. They remain
   non-deletable from this screen.
   ════════════════════════════════════════════════════════════════════ */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['edit_company_import'])) {
    header('Content-Type: application/json');

    $edit_import_id = isset($_POST['edit_import_id']) ? (int) $_POST['edit_import_id'] : 0;
    /* NEW (cross-table sync revision): a REGISTERED company is identified
       by its users.id instead of a companies_import id. Exactly one of the
       two references is ever sent by the Edit modal. */
    $edit_user_id   = isset($_POST['edit_user_id']) ? (int) $_POST['edit_user_id'] : 0;
    $isRegisteredEdit = ($edit_import_id <= 0 && $edit_user_id > 0);
    if ($edit_import_id <= 0 && $edit_user_id <= 0) {
        echo json_encode(['success' => false, 'message' => 'Invalid company reference.']);
        exit;
    }

    $oldImportRow = null;   // pre-edit companies_import values (imported edit)
    $oldRegRow    = null;   // pre-edit users/company_information values (registered edit)

    if ($isRegisteredEdit) {
        // Confirm this is really a registered company account before doing anything else.
        $checkReg = $conn->prepare("SELECT u.id, u.email, ci.company
            FROM users u
            INNER JOIN company_information ci ON ci.user_id = u.id
            WHERE u.id = ? AND u.role = 'company'");
        $checkReg->bind_param("i", $edit_user_id);
        $checkReg->execute();
        $oldRegRow = $checkReg->get_result()->fetch_assoc();
        $checkReg->close();
        if (!$oldRegRow) {
            echo json_encode(['success' => false, 'message' => 'This company account could not be found.']);
            exit;
        }
    } else {
        // Confirm this imported row still exists before doing anything else.
        // (Also reads the row's current company/email so a linked account, if
        // one exists, can be located after the update — see below.)
        $checkRow = $conn->prepare("SELECT id, company, email FROM companies_import WHERE id = ?");
        $checkRow->bind_param("i", $edit_import_id);
        $checkRow->execute();
        $oldImportRow = $checkRow->get_result()->fetch_assoc();
        $checkRow->close();
        if (!$oldImportRow) {
            echo json_encode(['success' => false, 'message' => 'This imported company could not be found (it may have already been deleted).']);
            exit;
        }
    }

    $company_name    = trim($_POST['edit_company_name'] ?? '');
    $company_address = trim($_POST['edit_company_address'] ?? '');
    $telephone       = trim($_POST['edit_telephone'] ?? '');
    $contact_first   = trim($_POST['edit_contact_first_name'] ?? '');
    $contact_middle  = trim($_POST['edit_contact_middle_name'] ?? '');
    $contact_last    = trim($_POST['edit_contact_last_name'] ?? '');
    $position_val    = trim($_POST['edit_position'] ?? '');
    $email_val       = trim($_POST['edit_email'] ?? '');
    $company_type_edit = trim($_POST['edit_company_type'] ?? '');

    if ($position_val === 'other' && isset($_POST['edit_custom_position']) && trim($_POST['edit_custom_position']) !== '') {
        $position_val = trim($_POST['edit_custom_position']);
    }

    $company_status_edit = (isset($_POST['edit_company_status']) && $_POST['edit_company_status'] === 'existing') ? 'existing' : 'new';
    $request_type_edit = ($company_status_edit === 'existing') ? 'Existing' : 'New';
    /* NEW: true only when the admin actually picked New/Existing. An
       imported edit still defaults to 'New' exactly as before; a
       registered edit whose Request Type was blank and was left
       untouched simply doesn't write a request type anywhere. */
    $companyStatusPosted = isset($_POST['edit_company_status']) && in_array($_POST['edit_company_status'], ['new', 'existing'], true);

    $errors = [];
    if (empty($company_name))  $errors[] = "Company name is required.";
    if (empty($contact_first)) $errors[] = "Contact first name is required.";
    if (empty($contact_last))  $errors[] = "Contact last name is required.";
    if (empty($email_val)) {
        $errors[] = "Email is required.";
    } elseif (!filter_var($email_val, FILTER_VALIDATE_EMAIL)) {
        $errors[] = "Please enter a valid email address.";
    }

    /* NEW (Company Type revision): required, same as the manual Add
       Company form. (Registered accounts that never had a Company Type
       recorded may leave it blank — a blank value simply leaves
       users.company_type as it is; a chosen value must still be exactly
       Public/Private.) */
    if ($isRegisteredEdit) {
        if ($company_type_edit !== '' && !in_array($company_type_edit, ['Public', 'Private'], true)) {
            $errors[] = "Please select the Company Type (Public or Private).";
        }
    } elseif (empty($company_type_edit) || !in_array($company_type_edit, ['Public', 'Private'], true)) {
        $errors[] = "Please select the Company Type (Public or Private).";
    }

    // The unique_company key still applies — make sure the (possibly
    // renamed) company doesn't collide with a DIFFERENT imported row.
    if (empty($errors) && !$isRegisteredEdit) {
        $dupStmt = $conn->prepare("SELECT id FROM companies_import WHERE company = ? AND id != ?");
        $dupStmt->bind_param("si", $company_name, $edit_import_id);
        $dupStmt->execute();
        if ($dupStmt->get_result()->num_rows > 0) {
            $errors[] = "Another imported company already uses this name.";
        }
        $dupStmt->close();
    }

    if (!empty($errors)) {
        echo json_encode(['success' => false, 'message' => implode("<br>", $errors)]);
        exit;
    }

    if (empty($contact_middle)) $contact_middle = null;
    if ($email_val === '') $email_val = null;

    /* Optional: replace the Admin Copy of MOA PDF in this same request.
       If no file was chosen, the existing admin_moa_document (if any)
       is left completely untouched. Either way, has_moa / moa_document
       (the company's own submitted MOA) are never referenced here. */
    $hasNewAdminMoa = false;
    $adminMoaBlob = null;
    if (isset($_FILES['edit_admin_moa_file']) && $_FILES['edit_admin_moa_file']['error'] === 0 && !empty($_FILES['edit_admin_moa_file']['tmp_name'])) {
        $adminMoaBlob = file_get_contents($_FILES['edit_admin_moa_file']['tmp_name']);
        $finfoEditMoa = new finfo(FILEINFO_MIME_TYPE);
        $editMoaMime  = $finfoEditMoa->buffer($adminMoaBlob);
        if ($editMoaMime !== 'application/pdf') {
            echo json_encode(['success' => false, 'message' => 'The admin copy of the MOA must be a PDF file.']);
            exit;
        }
        $hasNewAdminMoa = true;
    }

    /* NEW (cross-table sync revision): everything the admin edited is now
       written to EVERY table that holds this company's data, inside one
       transaction so the tables can never end up half-updated — either
       all of them are saved, or (on any error/conflict) none are.

         Imported row  → companies_import (exactly as before), PLUS — only
                         when a real account for the very same company
                         (same email + same company name as the row had
                         BEFORE this edit) already exists — that account's
                         users / company_information / company_requirements
                         / latest moa_requests rows.
         Registered    → users, company_information, company_requirements
                         (Admin Copy of MOA, if replaced), the latest
                         moa_requests row (only if the request type
                         changed), and a leftover companies_import row for
                         the same company, if one exists.

       has_moa / moa_document (the company's OWN submitted MOA) are still
       never read or written here. */
    $syncData = [
        'company'      => $company_name,
        'address'      => $company_address,
        'telephone'    => $telephone,
        'first'        => $contact_first,
        'middle'       => $contact_middle,
        'last'         => $contact_last,
        'position'     => $position_val,
        'email'        => $email_val,
        'company_type' => $company_type_edit,
        'request_type' => $isRegisteredEdit ? ($companyStatusPosted ? $request_type_edit : null) : $request_type_edit,
    ];

    $conn->begin_transaction();
    try {
        $tablesTouched = [];

        if ($isRegisteredEdit) {
            $tablesTouched = sync_company_edit_to_account(
                $conn, $edit_user_id, $oldRegRow, $syncData,
                $hasNewAdminMoa ? $adminMoaBlob : null, true
            );
        } else {
            if ($hasNewAdminMoa) {
                $editTypes = str_repeat('s', 11) . 'i';
                $stmt = $conn->prepare("UPDATE companies_import SET
                    company = ?, company_address = ?, telephone = ?,
                    contact_first_name = ?, contact_middle_name = ?, contact_last_name = ?,
                    position = ?, email = ?, request_type = ?, company_type = ?, admin_moa_document = ?
                    WHERE id = ?");
                $stmt->bind_param($editTypes,
                    $company_name, $company_address, $telephone,
                    $contact_first, $contact_middle, $contact_last,
                    $position_val, $email_val, $request_type_edit, $company_type_edit, $adminMoaBlob,
                    $edit_import_id
                );
            } else {
                $editTypes = str_repeat('s', 10) . 'i';
                $stmt = $conn->prepare("UPDATE companies_import SET
                    company = ?, company_address = ?, telephone = ?,
                    contact_first_name = ?, contact_middle_name = ?, contact_last_name = ?,
                    position = ?, email = ?, request_type = ?, company_type = ?
                    WHERE id = ?");
                $stmt->bind_param($editTypes,
                    $company_name, $company_address, $telephone,
                    $contact_first, $contact_middle, $contact_last,
                    $position_val, $email_val, $request_type_edit, $company_type_edit,
                    $edit_import_id
                );
            }

            if (!$stmt->execute()) {
                $stmtErr = $stmt->error;
                $stmt->close();
                throw new \RuntimeException($stmtErr);
            }
            $stmt->close();

            // Keep the linked account (same company + same email, if any) in step.
            $linkedUserId = find_linked_company_account($conn, $oldImportRow['email'] ?? '', $oldImportRow['company'] ?? '');
            if ($linkedUserId > 0) {
                $tablesTouched = sync_company_edit_to_account(
                    $conn, $linkedUserId, null, $syncData,
                    $hasNewAdminMoa ? $adminMoaBlob : null, false
                );
            }
        }

        $conn->commit();

        $successMsg = 'Company details updated successfully!';
        if (!empty($tablesTouched)) {
            $successMsg .= ' Updated in: ' . implode(', ', $tablesTouched) . '.';
        }
        echo json_encode(['success' => true, 'message' => $successMsg]);
    } catch (CompanyEditConflictException $conflict) {
        $conn->rollback();
        echo json_encode(['success' => false, 'message' => $conflict->getMessage()]);
    } catch (\Throwable $editErr) {
        $conn->rollback();
        echo json_encode(['success' => false, 'message' => 'Error updating company: ' . $editErr->getMessage()]);
    }
    exit;
}

/* REMOVED: MOA Processing Settings (the moa_new_open / moa_existing_open
   switches, their save handler, and the "On Hold" flag they drove) have
   been removed from this page entirely. */

/* ── Sidebar name lookup (mirrors admin_student_list.php) ── */
$adminFullName = '';
$adminNameStmt = $conn->prepare("SELECT first_name, middle_name, last_name FROM admins WHERE id = ?");
if ($adminNameStmt) {
    $adminNameStmt->bind_param("i", $_SESSION['user_id']);
    $adminNameStmt->execute();
    $adminNameRow = $adminNameStmt->get_result()->fetch_assoc();
    $adminNameStmt->close();
    if ($adminNameRow) {
        $adminFullName = trim(($adminNameRow['first_name'] ?? '') . ' ' . ($adminNameRow['middle_name'] ?? '') . ' ' . ($adminNameRow['last_name'] ?? ''));
        $adminFullName = preg_replace('/\s+/', ' ', $adminFullName);
    }
}
// ── FIX (this adjustment): admin full name in the sidebar header. admin_login.php stores the
// logged-in admin's id in $_SESSION['admin_id'] (and removes 'user_id'), so the lookup above
// often found nobody and the header fell back to the first name only. If it came back empty,
// look the admin up again by $_SESSION['admin_id'] (then 'user_id') — the same rule
// administrator.php / system_setting.php use. Read-only and fully guarded; nothing else on
// the page uses these variables.
if ($adminFullName === '') {
    try {
        $adminNameFixId = (int)($_SESSION['admin_id'] ?? $_SESSION['user_id'] ?? 0);
        if ($adminNameFixId > 0) {
            $adminNameFixStmt = $conn->prepare("SELECT first_name, middle_name, last_name FROM admins WHERE id = ?");
            if ($adminNameFixStmt) {
                $adminNameFixStmt->bind_param("i", $adminNameFixId);
                $adminNameFixStmt->execute();
                $adminNameFixRow = $adminNameFixStmt->get_result()->fetch_assoc();
                $adminNameFixStmt->close();
                if ($adminNameFixRow) {
                    $adminFullName = preg_replace('/\s+/', ' ', trim(
                        ($adminNameFixRow['first_name'] ?? '') . ' ' .
                        ($adminNameFixRow['middle_name'] ?? '') . ' ' .
                        ($adminNameFixRow['last_name'] ?? '')
                    ));
                }
            }
        }
    } catch (\Throwable $e) { /* keep the existing fallbacks below */ }
}
if ($adminFullName === '') $adminFullName = trim(($_SESSION['first_name'] ?? '') . ' ' . ($_SESSION['last_name'] ?? ''));
if ($adminFullName === '') $adminFullName = 'Administrator';

/* ── Badges (mirrors admin_student_list.php / company_validation.php) ── */
$app_request_count_res = $conn->query("SELECT COUNT(*) as total FROM admin_application_approvals aaa_c INNER JOIN users aaa_u ON aaa_u.id = aaa_c.student_id");
$app_request_count = (int)(($app_request_count_res ? $app_request_count_res->fetch_assoc()['total'] : 0));

// ============================================================================
// NEW (this adjustment): STUDENT REQUIREMENT SUBMISSIONS — side-menu indicator.
// A student's new / re-uploaded requirement is recorded as a notification by
// administrator.php (cv_sru_detect()) and counts toward the Student Validation
// indicator next to the application requests — the same count administrator.php
// shows. Same helpers as administrator.php (created once per session, so every
// admin page can count them). Fully guarded: if anything fails the count simply
// stays the application requests only.
// ============================================================================
if (!function_exists('cv_sru_ensure')) {
    function cv_sru_ensure($conn) {
        if (!empty($_SESSION['cv_sru_ready'])) return true;
        try {
            $conn->query("CREATE TABLE IF NOT EXISTS student_requirement_upload_notifications (
                id INT AUTO_INCREMENT PRIMARY KEY, user_id INT NOT NULL, detail TEXT NULL,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP, updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                admin_viewed TINYINT(1) NOT NULL DEFAULT 0, KEY idx_sru_viewed (admin_viewed), KEY idx_sru_user (user_id))");
            $conn->query("CREATE TABLE IF NOT EXISTS student_requirement_upload_watch (
                user_id INT NOT NULL, requirement_type VARCHAR(100) NOT NULL, file_len BIGINT NOT NULL DEFAULT 0,
                PRIMARY KEY (user_id, requirement_type))");
            $conn->query("CREATE TABLE IF NOT EXISTS student_requirement_upload_meta (id TINYINT NOT NULL PRIMARY KEY, initialized TINYINT(1) NOT NULL DEFAULT 0)");
            $_SESSION['cv_sru_ready'] = 1;
            return true;
        } catch (\Throwable $e) { return false; }
    }
    // unviewed submissions of active (not archived) students
    function cv_sru_count($conn) {
        try {
            if (!cv_sru_ensure($conn)) return 0;
            $r = $conn->query("SELECT COUNT(*) AS total FROM student_requirement_upload_notifications n
                               INNER JOIN users u ON u.id = n.user_id WHERE n.admin_viewed = 0 AND COALESCE(u.is_archived, 0) = 0");
            $row = $r ? $r->fetch_assoc() : null;
            return (int)($row['total'] ?? 0);
        } catch (\Throwable $e) { return 0; }
    }
}
try { $app_request_count += cv_sru_count($conn); } catch (\Throwable $e) { /* indicator keeps the application-request count */ }

/* ── FIX (sidebar notification indicator): the "Company Requirements"
   badge used to count moa_requests rows with status='Pending'. That no
   longer matches what company_validation.php treats as a notification:
   every Pending MOA request is auto-ingested there right away (so that
   count was almost always 0 / out of sync), and its Notification Inbox
   now lists (a) un-viewed, transferred MOA notifications
   (moa_requests.admin_viewed=0 AND transferred=1) PLUS (b) un-viewed
   requirement-upload notifications
   (company_requirement_upload_notifications.admin_viewed=0).
   This helper uses exactly that same rule, so the number shown here is
   always the same number shown on company_validation.php's own sidebar
   badge and inbox bell. Every lookup is guarded — if a column/table
   has not been created yet (company_validation.php creates them on its
   first load), that part simply counts as 0 instead of breaking the
   page. Read-only: no DDL, no writes. ── */
if (!function_exists('aclCompanyValidationNotifCount')) {
    function aclCompanyValidationNotifCount($conn) {
        $total = 0;
        // (a) MOA notifications — same rule as company_validation.php's inbox
        try {
            $hasViewed = false; $hasTransferred = false;
            $colRes = $conn->query("SHOW COLUMNS FROM moa_requests");
            if ($colRes) {
                while ($colRow = $colRes->fetch_assoc()) {
                    if ($colRow['Field'] === 'admin_viewed') $hasViewed = true;
                    if ($colRow['Field'] === 'transferred')  $hasTransferred = true;
                }
            }
            if ($hasViewed && $hasTransferred) {
                $r = $conn->query("SELECT COUNT(*) AS total FROM moa_requests WHERE admin_viewed=0 AND transferred=1");
                $row = $r ? $r->fetch_assoc() : null;
                $total += (int)($row['total'] ?? 0);
            }
        } catch (\Throwable $e) { /* table not created yet — count as 0 */ }
        // (b) requirement-upload notifications — same as cvReqUploadUnviewedCount()
        try {
            $tblRes = $conn->query("SHOW TABLES LIKE 'company_requirement_upload_notifications'");
            if ($tblRes && $tblRes->num_rows > 0) {
                $r = $conn->query("SELECT COUNT(*) AS total FROM company_requirement_upload_notifications WHERE admin_viewed=0");
                $row = $r ? $r->fetch_assoc() : null;
                $total += (int)($row['total'] ?? 0);
            }
        } catch (\Throwable $e) { /* table not created yet — count as 0 */ }
        return $total;
    }
}
$moa_pending_count = aclCompanyValidationNotifCount($conn);

/* ── NEW (sidebar notification indicator): lightweight JSON endpoint the
   sidebar polls so the "Company Requirements" badge stays in step with
   company_validation.php without a page reload (mirrors that page's own
   live badge). Placed before any HTML output; read-only. ── */
if (isset($_GET['cv_sidebar_notif_count']) && $_GET['cv_sidebar_notif_count'] === '1') {
    header('Content-Type: application/json');
    echo json_encode(['count' => $moa_pending_count]);
    exit;
}

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

/* ── NEW: distinct Position list for the manual-add dropdown.
   Pulled from both registered companies (company_information) and
   already-imported companies (companies_import) so the dropdown reflects
   every position value already in use anywhere in the system. Also
   reused by the Edit Imported Company modal below. ── */
$position_options = [];
$posRes = $conn->query("
    SELECT DISTINCT position FROM (
        SELECT position FROM companies_import WHERE position IS NOT NULL AND position <> ''
        UNION
        SELECT position FROM company_information WHERE position IS NOT NULL AND position <> ''
    ) p
    ORDER BY position ASC
");
if ($posRes) {
    while ($prow = $posRes->fetch_assoc()) {
        $position_options[] = $prow['position'];
    }
}

/* ── Filters ── */
$search_term       = isset($_GET['search']) ? trim($_GET['search']) : '';
$status_filter      = isset($_GET['status']) ? trim($_GET['status']) : '';
$request_type_filter = isset($_GET['request_type']) ? trim($_GET['request_type']) : '';
/* NEW (Company Type filter revision): Public / Private.
   UPDATED: the "Unspecified" option has been removed from this filter. */
$company_type_filter = isset($_GET['company_type']) ? trim($_GET['company_type']) : '';
if (strtolower($company_type_filter) === 'public') {
    $company_type_filter = 'Public';
} elseif (strtolower($company_type_filter) === 'private') {
    $company_type_filter = 'Private';
} elseif ($company_type_filter !== '' && strtolower($company_type_filter) !== 'all') {
    $company_type_filter = ''; // unknown value → no filtering
}

/* FIX (Request Type "New" filter / column mismatch): accept the incoming
   filter value in any letter-case / "... Company" spelling and map it to
   the canonical 'New' / 'Existing' value the table now uses (see
   sql_request_type_normalized() below). The dropdown itself still sends
   exactly 'New' / 'Existing' / 'All', so this only matters for
   hand-typed or bookmarked URLs. */
if (in_array(strtolower($request_type_filter), ['new', 'new company'], true)) {
    $request_type_filter = 'New';
} elseif (in_array(strtolower($request_type_filter), ['existing', 'existing company'], true)) {
    $request_type_filter = 'Existing';
}

/* NEW (Status column revision): accept the old 'Verified'/'Pending'
   values too (in any case), mapping them to the new 'Active'/
   'Validating' labels — this keeps any hand-typed or bookmarked URL
   using the previous filter values working exactly as before, same
   graceful-synonym approach already used for Request Type above. The
   dropdown itself now sends exactly 'Active' / 'Validating' /
   'Inactive' / 'Not Registered' / 'All'. */
if (strtolower($status_filter) === 'verified') {
    $status_filter = 'Active';
} elseif (strtolower($status_filter) === 'pending') {
    $status_filter = 'Validating';
} elseif (strtolower($status_filter) === 'not registered') {
    /* UPDATED: the "Not Registered (Imported)" option has been removed from
       the Status filter, so an old link carrying it simply shows all rows. */
    $status_filter = '';
}

/* ════════════════════════════════════════════════════════════════════
   NEW: the company list is now built from a UNION of:
     (A) registered companies — the exact original query, untouched in
         its own logic (company_information + users + moa_requests)
     (B) imported/manually-added companies from companies_import, which
         don't have a user account yet, so validation columns are
         synthesized as "not applicable" placeholders — except has_moa
         (reflects a manually-attached MOA PDF if one exists) and
         request_type (reflects the XLSX column / manual Add Company
         "Company Status" choice, so the Request Type column/filter also
         works for imported rows).

   NEW (Company Type revision): both branches now also select
   `company_type` — for (A) it comes from the users table
   (u.company_type — see the Company Type storage revision note in the
   Phase 2 "Create Account" handler above, which now writes this value
   onto users instead of company_information); for (B) it comes
   straight from companies_import.company_type — so the new "Company
   Type" column in the table below works identically for both kinds of
   rows.

   FIX (Request Type showing blank for accounts created via import /
   manual-add "Create Account"): the table's Request Type column for
   REGISTERED companies used to read ONLY mr.request_type (the most
   recent moa_requests row). A company that was converted into a real
   account through the imported-company "Create Account" flow (Phase 2)
   never has a moa_requests row at all — it was never submitted through
   the actual MOA request workflow — so mr.request_type is always NULL
   for it, and Request Type showed blank in the table even though the
   value was correctly persisted on users.request_type at the moment
   the account was created (attempt_create_company_account() above).
   This is now COALESCE(mr.request_type, u.request_type): a company
   that DOES have an actual moa_requests entry keeps showing its live,
   most-current MOA workflow request type exactly as before (no change
   for that case); a company that doesn't have one yet — because it was
   created straight from an imported/manually-added row — now falls
   back to the request_type value that was captured and persisted at
   account-creation time, so the column is never blank for it.

   FIX (Request Type column blank for "New" rows while the New filter
   still finds them): the table's Request Type cell (and the On Hold,
   Admin Copy of MOA eligibility and Edit-modal logic that read the same
   value) compares the value in PHP with a strict === 'New' / 'Existing',
   but the filter compares it in SQL with `=`, which MySQL evaluates
   case-insensitively and ignoring trailing spaces. So a stored value
   such as 'new', 'NEW' or 'New ' — which is how the moa_requests side can
   legitimately store it — matched the filter, yet fell through the strict
   PHP comparison and rendered as a blank "—" badge. The reverse also
   happened: a value like 'New Company' matched neither the filter nor
   the display. On top of that, COALESCE(mr.request_type,
   u.request_type) only falls back to users.request_type when
   mr.request_type is NULL — an EMPTY-string mr.request_type shadowed a
   perfectly good u.request_type and made the row look blank/unfilterable.

   Request Type is now normalized ONCE, inside the query itself, by
   sql_request_type_normalized() below: any spelling of new / existing
   (any case, surrounding spaces, "New Company", "Existing Company", ...)
   becomes exactly 'New' / 'Existing', and a blank mr.request_type no
   longer hides users.request_type. Because moa_request_type is now
   canonical, the filter, the table badge, the On Hold tag, the Admin
   Copy of MOA eligibility, the Edit modal's data-request-type, and the
   XLSX export all agree with each other automatically. Values that are
   genuinely unrecognizable are kept as-is (they still render "—", exactly
   as before), and rows with no value anywhere still get the inline "Set
   Request Type" control.
   ════════════════════════════════════════════════════════════════════ */

/* Returns 'New' / 'Existing' for any recognisable spelling of the given
   column/expression, otherwise NULL. */
function sql_request_type_recognized($expr) {
    return "(CASE
                WHEN LOWER(TRIM($expr)) LIKE 'new%'   THEN 'New'
                WHEN LOWER(TRIM($expr)) LIKE 'exist%' THEN 'Existing'
                ELSE NULL
            END)";
}

/* Builds the single normalized Request Type expression from one or more
   candidate columns, in priority order: first any candidate holding a
   recognisable New/Existing value wins; if none does, the first
   non-blank raw value is kept unchanged (so nothing that used to show is
   ever dropped); otherwise NULL. */
function sql_request_type_normalized(array $exprs) {
    $parts = [];
    foreach ($exprs as $e) { $parts[] = sql_request_type_recognized($e); }
    foreach ($exprs as $e) { $parts[] = "NULLIF(TRIM($e), '')"; }
    return "COALESCE(" . implode(', ', $parts) . ")";
}

/* ════════════════════════════════════════════════════════════════════
   NEW (Status column revision): the table's "Validation" column is now
   labeled "Status" and shows three states instead of the raw
   Verified/Pending value, derived from data already available on this
   page:
     - 'Active'      — company_validation_status = 'Verified', i.e. every
                        requirement (including the MOA) has already been
                        reviewed and approved on company_validation.php.
     - 'Validating'  — not yet Verified, but the company HAS submitted
                        its MOA (has_moa = 1) — a requirement is on file
                        and is currently being processed for
                        verification.
     - 'Inactive'    — not yet Verified AND no MOA has been submitted at
                        all — the company has not passed (or even
                        started) any compliance requirement yet.
   Imported/manually-added companies (no user account yet) keep their
   own separate 'Not Registered' status exactly as before — that is a
   different concept (no account exists at all yet) and is unaffected
   by this revision.

   resolve_company_status() is the single PHP source of truth, used both
   when rendering the table and when building the XLSX export, so the
   two can never disagree. sql_company_status_expr() is its SQL mirror,
   used only by the Status filter's WHERE clause below.
   ════════════════════════════════════════════════════════════════════ */
function resolve_company_status($isImported, $validationStatus, $hasMoa) {
    if ($isImported) return 'Not Registered';
    if ($validationStatus === 'Verified') return 'Active';
    return $hasMoa ? 'Validating' : 'Inactive';
}

function sql_company_status_expr($validationStatusExpr, $hasMoaExpr) {
    return "(CASE
                WHEN $validationStatusExpr = 'Verified' THEN 'Active'
                WHEN $validationStatusExpr = 'Not Registered' THEN 'Not Registered'
                WHEN $hasMoaExpr = 1 THEN 'Validating'
                ELSE 'Inactive'
            END)";
}

/* ════════════════════════════════════════════════════════════════════
   NEW (Existing requirement revision): requirement labels / checklists
   copied from company_validation.php ($companyReqLabels,
   $private_compliance_reqs, $public_compliance_reqs) so the "View
   Requirements" viewer lists the same items, in the same order, with the
   same names as the Company Requirements page.
   ════════════════════════════════════════════════════════════════════ */
function company_list_requirement_labels() {
    return [
        "company_profile"          => "Company Profile",
        "vision_mission"           => "Vision and Mission",
        "mayors_permit"            => "Mayor's Permit",
        "sec_registration"         => "SEC Registration",
        "dti_registration"         => "DTI Certificate",
        "cda_registration"         => "CDA Certificate",
        "bir_clearance"            => "BIR Clearance",
        "ohs_plan"                 => "OHS Plan",
        "training_supervisor_cv"   => "Training Supervisor CV",
        "authority_moa"            => "Authority to Sign MOA",
        "authority_moa_public"     => "Authority to Sign MOA (Public)",
        "training_supervisor_pds"  => "Training Supervisor PDS",
        "legislative_charter"      => "Legislative Charter",
        "moa_document"             => "MOA Document",
        "moa_existing_upload"      => "MOA Document (Existing Partnership)",
    ];
}

/* Mirrors company_validation.php's resolveCompanyType(): company_information
   first, then users.company_type; defaults to private. */
function company_list_is_public_company($conn, $user_id) {
    $q = $conn->prepare("SELECT COALESCE(NULLIF(ci.company_type, ''), NULLIF(u.company_type, '')) AS company_type
        FROM users u LEFT JOIN company_information ci ON ci.user_id = u.id WHERE u.id = ?");
    if (!$q) return false;
    $q->bind_param("i", $user_id);
    $q->execute();
    $row = $q->get_result()->fetch_assoc();
    $q->close();
    return strtolower(trim((string)($row['company_type'] ?? ''))) === 'public';
}

/* Mirrors company_validation.php's isCompanyRequestTypeExisting(): true only
   when an ACTUAL "Existing" value is on record (users.request_type first,
   then the MOA row's company_requirements.request_type) — never a default. */
function company_list_is_request_type_existing($conn, $user_id) {
    $uq = $conn->prepare("SELECT request_type FROM users WHERE id = ?");
    if ($uq) {
        $uq->bind_param("i", $user_id);
        $uq->execute();
        $urow = $uq->get_result()->fetch_assoc();
        $uq->close();
        $rawUserType = trim((string)($urow['request_type'] ?? ''));
        if ($rawUserType !== '') return stripos($rawUserType, 'existing') !== false;
    }
    try {
        $rq = $conn->prepare("SELECT request_type FROM company_requirements WHERE user_id = ? AND requirement_type IN ('moa_document','moa') ORDER BY id DESC LIMIT 1");
        if ($rq) {
            $rq->bind_param("i", $user_id);
            $rq->execute();
            $rrow = $rq->get_result()->fetch_assoc();
            $rq->close();
            $rawReqType = trim((string)($rrow['request_type'] ?? ''));
            if ($rawReqType !== '') return stripos($rawReqType, 'existing') !== false;
        }
    } catch (\Throwable $e) { /* request_type column not created yet */ }
    return false;
}

/* The company's compliance checklist, exactly as company_validation.php
   builds it (the MOA document itself is excluded — it has its own column). */
function company_list_requirement_checklist($conn, $user_id) {
    $private = ["company_profile", "vision_mission", "mayors_permit", "sec_registration", "dti_registration",
                "cda_registration", "bir_clearance", "ohs_plan", "training_supervisor_cv", "authority_moa"];
    $public  = ["authority_moa_public", "training_supervisor_pds", "legislative_charter"];
    $list = company_list_is_public_company($conn, $user_id) ? $public : $private;
    /* UPDATED (Existing Partnership MOA revision): "MOA Document (Existing
       Partnership)" is no longer listed in the Requirements viewer — for an
       Existing-request-type company it now opens from the MOA Document
       column instead (see view_moa_existing), same as the MOA document of
       every other company. */
    return $list;
}

/* ════════════════════════════════════════════════════════════════════
   NEW (Requirements column revision): turns a raw company_requirements
   .requirement_type value (e.g. "business_permit", set by
   CompanyForm.php / company_validation.php — this page never defines
   the set of requirement types itself) into a readable label for the
   new "Requirements" column's modal, e.g. "Business Permit". Used only
   for display — the raw value is always what is actually matched
   against the database everywhere else on this page.
   ════════════════════════════════════════════════════════════════════ */
function format_requirement_label($type) {
    /* NEW (Existing requirement revision): use the exact labels
       company_validation.php shows ($companyReqLabels) whenever the type
       is a known one; unknown types keep the original generic formatting. */
    $knownLabels = company_list_requirement_labels();
    if (isset($knownLabels[(string) $type])) return $knownLabels[(string) $type];
    $label = str_replace(['_', '-'], ' ', (string) $type);
    $label = trim(preg_replace('/\s+/', ' ', $label));
    return $label !== '' ? ucwords($label ?? '') : 'Requirement';
}

$moaRequestJoin = "
    LEFT JOIN (
        SELECT m1.user_id, m1.request_type, m1.status, m1.submitted_at
        FROM moa_requests m1
        INNER JOIN (
            SELECT user_id, MAX(id) AS max_id FROM moa_requests GROUP BY user_id
        ) m2 ON m2.user_id = m1.user_id AND m2.max_id = m1.id
    ) mr ON mr.user_id = ci.user_id
";

/* NEW: how many OJT students each registered company is CURRENTLY
   accommodating — counted from ojt_assignments (the same table already
   joined against reports elsewhere on this page for the ungraded-report
   badge), grouped/counted per company so each company row gets one
   number. */
$ojtCountJoin = "
    LEFT JOIN (
        SELECT company_id, COUNT(DISTINCT student_id) AS ojt_count
        FROM ojt_assignments
        GROUP BY company_id
    ) oac ON oac.company_id = ci.user_id
";

/* NEW: Admin's own copy of the MOA (separate from the company-submitted
   MOA already tracked via cr/has_moa above), stored under
   requirement_type = 'admin_moa_document' in the same table. */
$adminMoaJoin = "
    LEFT JOIN company_requirements acr ON acr.user_id = ci.user_id AND acr.requirement_type = 'admin_moa_document'
";

/* FIX: normalized Request Type for each branch (see the FIX note above).
   Registered rows: the live MOA request's type first, then the value
   persisted on the users row. Imported rows: companies_import's value. */
$registeredRequestTypeSql = sql_request_type_normalized(['mr.request_type', 'u.request_type']);
$importedRequestTypeSql   = sql_request_type_normalized(['cimp.request_type']);

/* ════════════════════════════════════════════════════════════════════
   UPDATED (this adjustment) — refines the previous revision, which made
   EVERY registered company wait until they're Verified before showing up
   here — that was too strict: a company this file itself imported (or
   added manually) was ALREADY deliberately entered into this roster by
   the admin, so it should stay visible the whole time, exactly as
   before. The distinction that actually matters is HOW the account was
   created, tracked by users.account_source (see its own docblock further
   up, and attempt_create_company_account() below — the only place that
   ever writes 'imported' there):
     - account_source = 'imported' (this file's own manual "Add Company"
       form, the Edit Imported Company modal, or an XLSX import): always
       visible here, at any validation status — unchanged from how this
       list has always behaved for these companies.
     - anything else (in practice, 'self_registered' — a company that
       signed itself up on company_register.php, which stamps that value
       on its own INSERT): only appears here once their overall
       validation status is 'Verified' (every requirement, including the
       MOA, approved on company_validation.php). Before that, the account
       is fully real and usable (they can log in, submit their MOA,
       upload requirements, etc.) — they just don't show up in THIS
       roster yet; company_validation.php is where the admin manages them
       until there's nothing left to validate. The mirror image of this
       half of the rule lives in company_validation.php's own main
       $companies query, which stops showing a company there the moment
       they become Verified.

   This intentionally does NOT touch the "imported-but-not-yet-an-account"
   branch just below ($importedSelect / companies_import) — those rows
   ('Not Registered') are a different, earlier stage entirely (no
   users/company_information row exists for them at all yet); they keep
   appearing here exactly as they always have.

   ════════════════════════════════════════════════════════════════════
   UPDATED (Requirements column revision — "admin cannot view the
   requirement of old created account" fix): the "only show a
   self-registered company here once it's Verified" half of the rule
   above is REMOVED. It made sense back when this roster's only purpose
   was a finished-companies list — but now that this same table also
   carries the "Requirements" column (a live, read-only view into a
   company's company_requirements rows, regardless of where they are in
   the compliance process) and the three-state Status column (Inactive /
   Validating / Active), hiding every self-registered company until
   Verified made those two features useless for exactly the companies an
   admin would most want to check on: the ones STILL going through
   compliance ("old" accounts sitting at Inactive/Validating). Excluding
   Verified from this condition entirely (i.e. no longer gating on
   account_source or validation status at all) means every registered
   company — self-registered or admin-created, at any point in their
   compliance journey — now always appears here, exactly like an
   imported/admin-created company already did, so its Requirements can
   always be reviewed from this page. The mirror-image note on
   company_validation.php's own query (referenced above) describes that
   page's own, separate condition, which is untouched by this fix.
   ════════════════════════════════════════════════════════════════════ */
$registeredSelect = "
    SELECT ci.user_id AS user_id, ci.company AS company, ci.company_address AS company_address,
           ci.telephone AS telephone,
           ci.contact_first_name AS contact_first_name, ci.contact_middle_initial AS contact_middle_initial,
           ci.contact_last_name AS contact_last_name,
           ci.position AS position,
           u.company_type AS company_type,
           u.email AS email,
           u.company_validation_status AS company_validation_status,
           cr.status AS moa_status, cr.moa_workflow_stage AS moa_workflow_stage,
           (cr.file_name IS NOT NULL AND LENGTH(cr.file_name) > 0) AS has_moa,
           (acr.file_name IS NOT NULL AND LENGTH(acr.file_name) > 0) AS has_admin_moa,
           EXISTS(SELECT 1 FROM company_requirements cre
                  WHERE cre.user_id = ci.user_id AND cre.requirement_type = 'moa_existing_upload'
                    AND cre.file_name IS NOT NULL AND LENGTH(cre.file_name) > 0) AS has_moa_existing,
           $registeredRequestTypeSql AS moa_request_type,
           mr.status AS moa_request_status,
           COALESCE(oac.ojt_count, 0) AS ojt_count,
           'registered' AS source,
           NULL AS import_id
    FROM company_information ci
    INNER JOIN users u ON ci.user_id = u.id
    LEFT JOIN company_requirements cr ON cr.user_id = ci.user_id AND cr.requirement_type = 'moa_document'
    $moaRequestJoin
    $ojtCountJoin
    $adminMoaJoin
    WHERE ci.user_id IS NOT NULL AND u.role='company' AND u.co_is_archived=0
";

$importedSelect = "
    SELECT NULL AS user_id, cimp.company AS company, cimp.company_address AS company_address,
           cimp.telephone AS telephone,
           cimp.contact_first_name AS contact_first_name, cimp.contact_middle_name AS contact_middle_initial,
           cimp.contact_last_name AS contact_last_name,
           cimp.position AS position,
           cimp.company_type AS company_type,
           cimp.email AS email,
           'Not Registered' AS company_validation_status,
           NULL AS moa_status, NULL AS moa_workflow_stage,
           cimp.has_moa AS has_moa,
           (cimp.admin_moa_document IS NOT NULL AND LENGTH(cimp.admin_moa_document) > 0) AS has_admin_moa,
           0 AS has_moa_existing,
           $importedRequestTypeSql AS moa_request_type,
           NULL AS moa_request_status,
           0 AS ojt_count,
           'imported' AS source,
           cimp.id AS import_id
    FROM companies_import cimp
";

$unionSql = "($registeredSelect) UNION ALL ($importedSelect)";

/* ════════════════════════════════════════════════════════════════════
   FIX (View Requirements / View MOA buttons not opening for some
   companies): the company name used to be dropped into the inline
   onclick="" handler as '" . htmlspecialchars($name ?? '', ENT_QUOTES) . "'.
   Inside an HTML attribute the browser decodes &#039; back into a real
   apostrophe BEFORE the JavaScript is parsed, so any company name
   containing an apostrophe (e.g. "Juan's Trading") ended the JS string
   early and produced "Uncaught SyntaxError: missing ) after argument
   list" — the click handler never existed, so the modal never opened.
   Names without an apostrophe (most freshly created accounts) worked,
   which is why the problem looked tied to old accounts.

   js_string_attr() emits the name as a properly escaped JavaScript
   string literal (quotes, backslashes, apostrophes, newlines, < > & all
   escaped by json_encode) and then HTML-escapes that literal so it is
   safe inside a double-quoted attribute. Use it in place of the old
   quoted-htmlspecialchars pattern for any inline JS argument.
   ════════════════════════════════════════════════════════════════════ */
if (!function_exists('js_string_attr')) {
    function js_string_attr($value) {
        $json = json_encode(
            (string) $value,
            JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE
        );
        if ($json === false) $json = '""';
        return htmlspecialchars($json ?? '', ENT_QUOTES);
    }
}

/* ════════════════════════════════════════════════════════════════════
   NEW: XLSX EXPORT — mirrors the same PhpSpreadsheet export pattern
   used in admin_student_list.php (styled header row, alternating row
   fill, auto-sized columns, frozen header). Exports the FULL combined
   company list (registered + imported), ignoring the current search /
   status / request-type filters and pagination — same behavior as the
   student export, which always exports every students_import record
   regardless of what's currently filtered/shown on screen.

   UPDATED (this revision): the contact person is no longer exported as
   one combined "Contact Person" column — it is now split into three
   separate atomic columns (Contact First Name, Contact Middle Name,
   Contact Last Name) so each name part sits in its own column instead
   of being concatenated together.

   NEW (Company Type revision): a "Company Type" column is now exported
   between "Validation Status" and "Request Type", mirroring the same
   placement used in the on-screen table.

   FIX (this revision — leading zero stripped from Telephone column):
   Telephone numbers that start with "0" (e.g. "0917xxxxxxx") were being
   written to the sheet as a plain value, and Excel's default "General"
   number format auto-detects a purely-numeric-looking string and
   silently reinterprets it as a number — which drops the leading zero
   the moment the file is opened. The Telephone cell is now written with
   setCellValueExplicit(..., DataType::TYPE_STRING) so PhpSpreadsheet
   always stores it as a literal text value instead of letting Excel
   guess, and the entire Telephone column is additionally given an
   explicit "Text" (@) number format after all rows are written, as a
   second safeguard against Excel re-evaluating it as numeric. No other
   column or export behavior is affected.
   ════════════════════════════════════════════════════════════════════ */
if ((isset($_GET['export']) && $_GET['export'] === 'xlsx') || ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['export']) && $_POST['export'] === 'xlsx')) {
    /* NEW (Import/Export result screen revision): the Export button now
       downloads the file via fetch() so the page can tell whether the
       export succeeded or failed and show the matching result screen.
       For those AJAX requests only, any exception or fatal error during
       the export is reported back as a small JSON error instead of a raw
       PHP error page. A plain (non-AJAX) ?export=xlsx request behaves
       exactly as before. The export query, columns and styling below
       are unchanged. */
    if (isset($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest') {
        $aclExportSendJsonError = function ($msg) {
            while (ob_get_level() > 0) { ob_end_clean(); }
            if (!headers_sent()) {
                http_response_code(500);
                header('Content-Type: application/json');
                header_remove('Content-Disposition');
            }
            echo json_encode(['success' => false, 'message' => $msg]);
        };
        ini_set('display_errors', '0');
        ob_start();
        set_exception_handler(function ($e) use ($aclExportSendJsonError) {
            $aclExportSendJsonError('The Excel file could not be generated: ' . $e->getMessage());
            exit;
        });
        register_shutdown_function(function () use ($aclExportSendJsonError) {
            $err = error_get_last();
            if ($err && in_array($err['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR], true)) {
                $aclExportSendJsonError('The Excel file could not be generated because of a server error. Please try again.');
            }
        });
    }
    require 'vendor/autoload.php';

    /* ════════════════════════════════════════════════════════════════
       NEW (Export exclusion revision): the "Export to Excel" toolbar
       button now asks first whether any companies should be left OUT
       of the file (see exportChoiceModal / enterExportExcludeSelectionMode()
       in the script below). "None, Proceed With Export" sends the exact
       same plain GET ?export=xlsx request this page has always used —
       $excludeUserIds / $excludeImportIds simply stay empty and the
       query below behaves identically to before this revision. Choosing
       specific companies instead submits a POST carrying
       exclude_user_ids[] (registered companies, by users.id) and/or
       exclude_import_ids[] (imported companies, by companies_import.id)
       — whichever rows the admin checked — and only those are filtered
       out of the exported file; everything else about the export
       (column layout, styling, "ignore current filters/pagination")
       is completely unchanged. */
    $excludeUserIds = [];
    $excludeImportIds = [];
    if (isset($_POST['exclude_user_ids']) && is_array($_POST['exclude_user_ids'])) {
        foreach ($_POST['exclude_user_ids'] as $rawId) {
            $intId = (int) $rawId;
            if ($intId > 0) $excludeUserIds[] = $intId;
        }
    }
    if (isset($_POST['exclude_import_ids']) && is_array($_POST['exclude_import_ids'])) {
        foreach ($_POST['exclude_import_ids'] as $rawId) {
            $intId = (int) $rawId;
            if ($intId > 0) $excludeImportIds[] = $intId;
        }
    }

    $exportWhereParts = [];
    $exportParams = [];
    $exportTypes = '';
    if (!empty($excludeUserIds)) {
        $ph = implode(',', array_fill(0, count($excludeUserIds), '?'));
        $exportWhereParts[] = "NOT (ac.user_id IS NOT NULL AND ac.user_id IN ($ph))";
        foreach ($excludeUserIds as $eid) { $exportParams[] = $eid; $exportTypes .= 'i'; }
    }
    if (!empty($excludeImportIds)) {
        $ph = implode(',', array_fill(0, count($excludeImportIds), '?'));
        $exportWhereParts[] = "NOT (ac.import_id IS NOT NULL AND ac.import_id IN ($ph))";
        foreach ($excludeImportIds as $eid) { $exportParams[] = $eid; $exportTypes .= 'i'; }
    }
    $exportWhereSql = $exportWhereParts ? implode(' AND ', $exportWhereParts) : '1=1';

    $export_sql = "SELECT * FROM ($unionSql) ac WHERE $exportWhereSql ORDER BY ac.company ASC";
    if ($exportParams) {
        $export_stmt = $conn->prepare($export_sql);
        $export_stmt->bind_param($exportTypes, ...$exportParams);
        $export_stmt->execute();
        $export_result = $export_stmt->get_result();
    } else {
        $export_result = $conn->query($export_sql);
    }

    $wb = new \PhpOffice\PhpSpreadsheet\Spreadsheet();
    $ws = $wb->getActiveSheet();
    $ws->setTitle('Companies');

    $headerFill = [
        'fillType'   => \PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID,
        'startColor' => ['rgb' => '07145F'],
    ];
    $headerFont  = ['bold' => true, 'color' => ['rgb' => 'FFD700'], 'name' => 'Arial', 'size' => 11];
    $bodyFont    = ['name' => 'Arial', 'size' => 10];
    $centerAlign = ['horizontal' => \PhpOffice\PhpSpreadsheet\Style\Alignment::HORIZONTAL_CENTER, 'vertical' => \PhpOffice\PhpSpreadsheet\Style\Alignment::VERTICAL_CENTER, 'wrapText' => false];
    $leftAlign   = ['horizontal' => \PhpOffice\PhpSpreadsheet\Style\Alignment::HORIZONTAL_LEFT,   'vertical' => \PhpOffice\PhpSpreadsheet\Style\Alignment::VERTICAL_CENTER, 'wrapText' => false, 'indent' => 1];

    /* UPDATED: 'B' (Contact Person) split into 'B'/'C'/'D' atomic name
       columns; 'I' (NEW) is Company Type; every column after that
       shifts accordingly (Request Type J, OJT Students K).
       UPDATED: the "MOA on File", "Admin Copy of MOA" and "Source"
       columns are no longer exported. */
    $headers = [
        'A' => 'Company',
        'B' => 'Contact First Name',
        'C' => 'Contact Middle Name',
        'D' => 'Contact Last Name',
        'E' => 'Email',
        'F' => 'Telephone',
        'G' => 'Address',
        'H' => 'Status',
        'I' => 'Company Type',
        'J' => 'Request Type',
        'K' => 'OJT Students',
    ];
    foreach ($headers as $col => $label) {
        $cell = $col . '1';
        $ws->setCellValue($cell, $label);
        $ws->getStyle($cell)->getFont()->applyFromArray($headerFont);
        $ws->getStyle($cell)->getFill()->applyFromArray($headerFill);
        $ws->getStyle($cell)->getAlignment()->applyFromArray($centerAlign);
        $ws->getStyle($cell)->getBorders()->getBottom()->setBorderStyle(
            \PhpOffice\PhpSpreadsheet\Style\Border::BORDER_MEDIUM
        );
    }

    $ws->freezePane('A2');

    /* FIX (leading-zero telephone): pre-format the entire Telephone
       column as Text BEFORE any values are written into it. Setting the
       number format ahead of time (in addition to writing the values
       explicitly as strings below) is the most reliable way to stop
       Excel from ever re-evaluating "0917..." as the number 917... */
    $ws->getStyle('F:F')->getNumberFormat()->setFormatCode(\PhpOffice\PhpSpreadsheet\Style\NumberFormat::FORMAT_TEXT);

    $row = 2;
    while ($r = $export_result->fetch_assoc()) {
        $isImportedRow = ($r['source'] === 'imported');
        $reqTypeVal = $r['moa_request_type'] ?? '';
        $reqTypeLabelExport = $reqTypeVal === 'Existing' ? 'Existing Company' : ($reqTypeVal === 'New' ? 'New Company' : '—');

        $ws->setCellValue('A' . $row, $r['company']);
        $ws->setCellValue('B' . $row, $r['contact_first_name'] ?: '—');
        $ws->setCellValue('C' . $row, $r['contact_middle_initial'] ?: '—');
        $ws->setCellValue('D' . $row, $r['contact_last_name'] ?: '—');
        $ws->setCellValue('E' . $row, $r['email'] ?: '—');
        /* FIX (leading-zero telephone): write the telephone value as an
           EXPLICIT string type instead of a plain setCellValue(), so
           PhpSpreadsheet never lets Excel's "General" format guess that
           a value like "0917123456" is numeric and strip the leading
           zero. Combined with the column-wide Text format set above,
           the leading zero is preserved no matter how the file is later
           opened/re-saved. */
        $ws->setCellValueExplicit(
            'F' . $row,
            $r['telephone'] !== null && $r['telephone'] !== '' ? $r['telephone'] : '—',
            \PhpOffice\PhpSpreadsheet\Cell\DataType::TYPE_STRING
        );
        $ws->setCellValue('G' . $row, $r['company_address'] ?: '—');
        $ws->setCellValue('H' . $row, resolve_company_status($isImportedRow, $r['company_validation_status'] ?? null, !empty($r['has_moa'])));
        $ws->setCellValue('I' . $row, $r['company_type'] ?: '—');
        $ws->setCellValue('J' . $row, $reqTypeLabelExport);
        $ws->setCellValue('K' . $row, $isImportedRow ? 'N/A' : (int)($r['ojt_count'] ?? 0));

        $rowFill = ($row % 2 === 0)
            ? ['fillType' => \PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID, 'startColor' => ['rgb' => 'F5F3FF']]
            : ['fillType' => \PhpOffice\PhpSpreadsheet\Style\Fill::FILL_NONE];

        foreach (array_keys($headers) as $col) {
            $ws->getStyle($col . $row)->getFont()->applyFromArray($bodyFont);
            $ws->getStyle($col . $row)->getAlignment()->applyFromArray($leftAlign);
            if ($row % 2 === 0) {
                $ws->getStyle($col . $row)->getFill()->applyFromArray($rowFill);
            }
        }

        $row++;
    }

    /* FIX (leading-zero telephone): re-assert the Text format across
       exactly the data rows that were written (F2:F<last row>), as a
       final safeguard on top of the whole-column format set above. */
    if ($row > 2) {
        $ws->getStyle('F2:F' . ($row - 1))->getNumberFormat()->setFormatCode(\PhpOffice\PhpSpreadsheet\Style\NumberFormat::FORMAT_TEXT);
    }

    /* UPDATED: column width minimums adjusted for the new split-name
       columns (B/C/D), the new Company Type column (I), and the
       resulting shift of every column after them. */
    $colMins = [
        'A' => 28, 'B' => 18, 'C' => 18, 'D' => 18, 'E' => 32, 'F' => 18,
        'G' => 32, 'H' => 18, 'I' => 16, 'J' => 18, 'K' => 14,
    ];
    foreach (array_keys($headers) as $col) {
        $ws->getColumnDimension($col)->setAutoSize(true);
    }
    $ws->calculateColumnWidths();
    foreach ($colMins as $col => $minWidth) {
        $calculated = $ws->getColumnDimension($col)->getWidth();
        $final = max($minWidth, $calculated + 4);
        $ws->getColumnDimension($col)->setAutoSize(false);
        $ws->getColumnDimension($col)->setWidth($final);
    }

    $ws->getRowDimension(1)->setRowHeight(24);
    for ($i = 2; $i < $row; $i++) {
        $ws->getRowDimension($i)->setRowHeight(18);
    }

    $filename = 'companies_export_' . date('Y-m-d_His') . '.xlsx';
    header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('Cache-Control: max-age=0');

    $writer = \PhpOffice\PhpSpreadsheet\IOFactory::createWriter($wb, 'Xlsx');
    $writer->save('php://output');
    /* NEW (Import/Export result screen revision): flush the buffer opened
       above for AJAX export requests (no-op otherwise). */
    while (ob_get_level() > 0) { ob_end_flush(); }
    exit;
}
/* ── END EXPORT ── */

$where = [];
$params = []; $types = '';
if ($search_term !== '') {
    $where[] = "(ac.company LIKE ? OR ac.contact_first_name LIKE ? OR ac.contact_last_name LIKE ?)";
    $p = "%$search_term%"; $params[]=$p; $params[]=$p; $params[]=$p; $types.='sss';
}
if ($status_filter !== '' && $status_filter !== 'All') {
    $where[] = sql_company_status_expr('ac.company_validation_status', 'ac.has_moa') . " = ?";
    $params[] = $status_filter; $types .= 's';
}
if ($request_type_filter !== '' && $request_type_filter !== 'All') {
    $where[] = "ac.moa_request_type = ?";
    $params[] = $request_type_filter; $types .= 's';
}
/* NEW (Company Type filter revision): same Company Type value the table's
   "Company Type" column shows (users.company_type for registered rows,
   companies_import.company_type for imported rows). */
if ($company_type_filter !== '' && strtolower($company_type_filter) !== 'all') {
    $where[] = "TRIM(ac.company_type) = ?";
    $params[] = $company_type_filter; $types .= 's';
}
$whereSql = $where ? implode(' AND ', $where) : '1=1';

/* ── Pagination ── */
$records_per_page = 12;
$page = isset($_GET['page']) ? (int)$_GET['page'] : 1;
if ($page < 1) $page = 1;

$countSql = "SELECT COUNT(*) as total FROM ($unionSql) ac WHERE $whereSql";
$countStmt = $conn->prepare($countSql);
if ($params) $countStmt->bind_param($types, ...$params);
$countStmt->execute();
$total_companies = $countStmt->get_result()->fetch_assoc()['total'];
$total_pages = max(1, ceil($total_companies / $records_per_page));
if ($page > $total_pages) $page = $total_pages;
$offset = ($page - 1) * $records_per_page;

$sql = "
    SELECT * FROM ($unionSql) ac
    WHERE $whereSql
    ORDER BY ac.company ASC
    LIMIT ? OFFSET ?
";
$stmt = $conn->prepare($sql);
$allParams = array_merge($params, [$records_per_page, $offset]);
$allTypes  = $types . 'ii';
$stmt->bind_param($allTypes, ...$allParams);
$stmt->execute();
$companies = $stmt->get_result();

function build_url($params = []) {
    $current = $_GET;
    foreach ($params as $k => $v) { if ($v === '') unset($current[$k]); else $current[$k] = $v; }
    return '?' . http_build_query($current);
}

/* ════════════════════════════════════════════════════════════════════
   NEW: the entire table section (table / empty-state + pagination +
   pagination-info) is rendered once into a string via output buffering.
   This same string is used both for the normal full-page render AND for
   the AJAX partial-refresh requests fired by the search box, the status/
   request-type filters, and pagination clicks — so there is exactly one
   place that generates this markup and the no-reload filtering behavior
   can never drift out of sync with a full page load.

   NEW (this revision): a leading checkbox column still sits in front of
   every row. Only imported rows carry a value (their companies_import
   id) and can actually be checked — registered companies render a
   disabled checkbox since they have real user accounts and are not
   deletable/editable from this screen. A header "select all" checkbox
   toggles every checkable row currently on the page.

   UPDATED (this revision): the trailing "Actions" column has been
   removed entirely. The per-row Edit button is gone — editing an
   imported company is now done exclusively through the toolbar "Edit"
   button's single-select checkbox flow. To keep that flow working
   without a dedicated button, every current field value that used to
   live on the removed Edit button's data-* attributes now lives on the
   row's own checkbox (.row-select-checkbox) instead, so the toolbar
   Edit button can still populate the Edit modal instantly with no
   extra round-trip.

   NEW (Company Type revision): a "Company Type" column (Public /
   Private) now sits between "Validation" and "Request Type" for both
   registered and imported rows, and the checkbox's data-* attributes
   also now carry data-company-type so the toolbar Edit flow can
   populate the Edit modal's new Company Type field.

   UPDATED (this revision — Create Account removed): the standalone
   "Create Account" checkbox-selection mode has been removed from this
   page entirely (see the table-toolbar and script sections further
   down) since account creation now happens automatically right after a
   company is imported or manually added — there is no longer a
   multi-select "account" mode to support here. The checkbox column
   itself is unchanged and still serves the Delete and Edit flows.

   NEW (this revision — Set Request Type for registered companies):
   when a REGISTERED row's Request Type is blank (moa_request_type is
   empty — see attempt_create_company_account()/update_registered_
   request_type above for why this can legitimately happen for older
   or directly-registered accounts), the Request Type cell now renders
   a small inline "Set Request Type" dropdown instead of the plain "—"
   badge, so the admin can assign New/Existing to that company directly
   from this table. Selecting a value fires the
   update_registered_request_type handler above via fetch() and
   refreshes the table in place. This control is intentionally never
   shown for imported rows (they already have their own Request Type
   via the Edit modal) or for registered rows that already have a
   value (their existing badge/behavior is completely unchanged).
   ════════════════════════════════════════════════════════════════════ */
ob_start();
if ($total_companies == 0) {
?>
            <div class="empty-state">
                <i class="fas fa-building"></i>
                <h3>No Companies Found</h3>
                <p>Try adjusting your filters, or check back once companies register.</p>
            </div>
<?php
} else {
?>
            <div class="table-scroll-wrapper">
                <table class="company-table">
                    <thead>
                        <tr>
                            <th class="checkbox-cell" style="width:44px;text-align:center;"><input type="checkbox" id="selectAllCheckbox" title="Select all entries on this page"></th>
                            <th><i class="fas fa-building" style="margin-right:5px;"></i>Company</th>
                            <th><i class="fas fa-user" style="margin-right:5px;"></i>Contact Person</th>
                            <th><i class="fas fa-envelope" style="margin-right:5px;"></i>Email</th>
                            <th><i class="fas fa-phone" style="margin-right:5px;"></i>Telephone</th>
                            <th><i class="fas fa-map-marker-alt" style="margin-right:5px;"></i>Address</th>
                            <th><i class="fas fa-check-circle" style="margin-right:5px;"></i>Status</th>
                            <th><i class="fas fa-city" style="margin-right:5px;"></i>Company Type</th>
                            <th><i class="fas fa-random" style="margin-right:5px;"></i>Request Type</th>
                            <th><i class="fas fa-user-graduate" style="margin-right:5px;"></i>OJT Students</th>
                            <th><i class="fas fa-file-signature" style="margin-right:5px;"></i>MOA Document</th>
                            <th><i class="fas fa-folder-open" style="margin-right:5px;"></i>Requirements</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php while ($c = $companies->fetch_assoc()):
                            $uid = $c['user_id'];
                            $isImported = ($c['source'] === 'imported');
                            $hasMoa = !empty($c['has_moa']);
                            /* UPDATED (Status column revision): "Validation" is now
                               "Status", showing Active / Validating / Inactive for
                               registered companies (see resolve_company_status()
                               above) — imported rows keep their own separate "Not
                               Registered" status exactly as before. */
                            $statusValue = resolve_company_status($isImported, $c['company_validation_status'] ?? null, $hasMoa);
                            $validationClass = str_replace(' ', '', $statusValue);
                            $moaVerified = ($c['moa_status'] ?? '') === 'Verified';
                            /* UPDATED (Contact middle initial revision): the Contact
                               Person full name now includes the middle initial
                               between the first and last name (e.g. "Mathew S.
                               Sales"). contact_middle_initial comes from the
                               combined query (company_information.contact_middle_initial
                               for registered companies, companies_import.contact_middle_name
                               for imported ones), so a full middle name is shortened to
                               its first letter + "." and an empty one is simply skipped. */
                            $contactMiddleRaw = trim((string) ($c['contact_middle_initial'] ?? ''));
                            $contactMiddleInitial = $contactMiddleRaw !== ''
                                ? mb_strtoupper(mb_substr($contactMiddleRaw, 0, 1, 'UTF-8'), 'UTF-8') . '.'
                                : '';
                            $contactName = trim(preg_replace('/\s+/u', ' ', ($c['contact_first_name'] ?? '') . ' ' . $contactMiddleInitial . ' ' . ($c['contact_last_name'] ?? '')));

                            /* NEW (Company Type revision) */
                            $companyType = $c['company_type'] ?? '';
                            /* FIX (pale Company Type badge): some rows store the type as
                               "public" / "PRIVATE " (different letter case or extra spaces),
                               so the strict 'Public' / 'Private' check below failed and the
                               badge fell back to the grey "none" style even though a type
                               was shown. The value is now trimmed and matched without
                               regard to letter case, then written back in its canonical
                               form ('Public' / 'Private'), so the badge gets its proper
                               color and the row's data-company-type pre-selects the Edit
                               form's dropdown correctly. Any other value is left as-is. */
                            $companyTypeTrim = trim((string)$companyType);
                            if (strcasecmp($companyTypeTrim, 'Public') === 0) {
                                $companyType = 'Public';
                            } elseif (strcasecmp($companyTypeTrim, 'Private') === 0) {
                                $companyType = 'Private';
                            } else {
                                $companyType = $companyTypeTrim;
                            }
                            $companyTypeLabel = $companyType !== '' ? $companyType : '—';
                            $companyTypeClass = $companyType === 'Public' ? 'Public' : ($companyType === 'Private' ? 'Private' : 'none');

                            $reqType = $c['moa_request_type'] ?? '';
                            $reqTypeLabel = $reqType === 'New' ? 'New Company' : ($reqType === 'Existing' ? 'Existing Company' : '—');
                            $reqTypeClass = $reqType === 'New' ? 'New' : ($reqType === 'Existing' ? 'Existing' : 'none');

                            /* NEW: OJT Students count — only meaningful for registered
                               companies (imported companies have no account yet, so
                               they can't have any active OJT assignments). */
                            $ojtCount = $isImported ? 0 : (int)($c['ojt_count'] ?? 0);

                            /* NEW: Admin Copy of MOA is only offered for companies whose
                               MOA request type is "Existing" (i.e. the company is already
                               known to have a signed MOA) — same has-MOA condition used
                               for the company's own MOA Document column. */
                            $hasAdminMoa = !empty($c['has_admin_moa']);
                            $eligibleForAdminMoa = ($reqType === 'Existing') || $hasMoa;
                            $adminMoaRefId = $isImported ? (int)$c['import_id'] : (int)$uid;
                            $adminMoaRefType = $isImported ? 'import' : 'user';
                        ?>
                        <tr>
                            <td class="checkbox-cell" style="text-align:center;">
                                <?php if ($isImported): ?>
                                    <input type="checkbox" class="row-select-checkbox" value="<?= (int)$c['import_id'] ?>"
                                        data-import-id="<?= (int)$c['import_id'] ?>"
                                        data-company="<?= htmlspecialchars($c['company'] ?? '', ENT_QUOTES) ?>"
                                        data-address="<?= htmlspecialchars($c['company_address'] ?? '', ENT_QUOTES) ?>"
                                        data-telephone="<?= htmlspecialchars($c['telephone'] ?? '', ENT_QUOTES) ?>"
                                        data-first="<?= htmlspecialchars($c['contact_first_name'] ?? '', ENT_QUOTES) ?>"
                                        data-middle="<?= htmlspecialchars($c['contact_middle_initial'] ?? '', ENT_QUOTES) ?>"
                                        data-last="<?= htmlspecialchars($c['contact_last_name'] ?? '', ENT_QUOTES) ?>"
                                        data-email="<?= htmlspecialchars($c['email'] ?? '', ENT_QUOTES) ?>"
                                        data-position="<?= htmlspecialchars($c['position'] ?? '', ENT_QUOTES) ?>"
                                        data-request-type="<?= htmlspecialchars($reqType ?? '', ENT_QUOTES) ?>"
                                        data-company-type="<?= htmlspecialchars($companyType ?? '', ENT_QUOTES) ?>">
                                <?php else: ?>
                                    <?php /* UPDATED (cross-table sync revision): registered companies
                                            can be EDITED from this list (their changes are saved to
                                            users / company_information / company_requirements /
                                            moa_requests). UPDATED (Delete revision): they can now also
                                            be DELETED (the whole company account, like monitoring.php).
                                            Their checkbox is rendered disabled and is only enabled by
                                            the toolbar Edit / Delete / Export modes (see
                                            setRegisteredCheckboxesEnabled() in the script below). Its
                                            value stays empty — a delete identifies it by data-user-id. */ ?>
                                    <input type="checkbox" class="row-select-checkbox reg-row-select-checkbox" value="" disabled
                                        title="Registered company account — can be edited or deleted from this list"
                                        data-source="registered"
                                        data-user-id="<?= (int)$uid ?>"
                                        data-company="<?= htmlspecialchars($c['company'] ?? '', ENT_QUOTES) ?>"
                                        data-address="<?= htmlspecialchars($c['company_address'] ?? '', ENT_QUOTES) ?>"
                                        data-telephone="<?= htmlspecialchars($c['telephone'] ?? '', ENT_QUOTES) ?>"
                                        data-first="<?= htmlspecialchars($c['contact_first_name'] ?? '', ENT_QUOTES) ?>"
                                        data-middle="<?= htmlspecialchars($c['contact_middle_initial'] ?? '', ENT_QUOTES) ?>"
                                        data-last="<?= htmlspecialchars($c['contact_last_name'] ?? '', ENT_QUOTES) ?>"
                                        data-email="<?= htmlspecialchars($c['email'] ?? '', ENT_QUOTES) ?>"
                                        data-position="<?= htmlspecialchars($c['position'] ?? '', ENT_QUOTES) ?>"
                                        data-request-type="<?= htmlspecialchars($reqType ?? '', ENT_QUOTES) ?>"
                                        data-company-type="<?= htmlspecialchars($companyType ?? '', ENT_QUOTES) ?>">
                                <?php endif; ?>
                            </td>
                            <td>
                                <strong style="color:var(--grid-navy);"><?= htmlspecialchars($c['company'] ?? '') ?></strong>
                                <?php if ($isImported): ?><span class="import-tag" title="Added via manual entry or XLSX import — no account yet">Imported</span><?php endif; ?>
                            </td>
                            <td><?= htmlspecialchars($contactName ?: '—') ?><?= !empty($c['position']) ? ' <span style="color:#8891A0;">('.htmlspecialchars($c['position'] ?? '').')</span>' : '' ?></td>
                            <td><?= htmlspecialchars($c['email'] ?: '—') ?></td>
                            <td><?= htmlspecialchars($c['telephone'] ?: '—') ?></td>
                            <td><?= htmlspecialchars($c['company_address'] ?: '—') ?></td>
                            <td><span class="cc-badge status-col-badge <?= $validationClass ?>"><?= htmlspecialchars($statusValue ?? '') ?></span></td>
                            <td><span class="type-badge <?= $companyTypeClass ?>"><?= htmlspecialchars($companyTypeLabel ?? '') ?></span></td>
                            <td>
                                <?php /* NEW: for a registered company with no Request Type
                                        on record yet, offer an inline "Set Request Type"
                                        control instead of the plain "—" badge. Imported
                                        rows, and any registered row that already has a
                                        value, keep rendering the badge exactly as before. */ ?>
                                <?php if (!$isImported && $reqType === ''): ?>
                                    <select class="reg-request-type-select" data-user-id="<?= (int)$uid ?>" title="This company has no Request Type on record — set one here.">
                                        <option value="">Set Request Type…</option>
                                        <option value="New">New Company</option>
                                        <option value="Existing">Existing Company</option>
                                    </select>
                                <?php else: ?>
                                    <span class="req-badge <?= $reqTypeClass ?>"><?= htmlspecialchars($reqTypeLabel ?? '') ?></span>
                                <?php endif; ?>
                            </td>
                            <td style="text-align:center;">
                                <?php if ($isImported): ?>
                                    <span class="ojt-count-label ojt-count-na" title="Imported companies have no account yet, so they cannot have OJT assignments">—</span>
                                <?php else: ?>
                                    <span class="ojt-count-label <?= $ojtCount > 0 ? 'ojt-count-active' : 'ojt-count-zero' ?>"><i class="fas fa-user-graduate"></i> <?= $ojtCount ?></span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <?php if ($isImported): ?>
                                    <?php if ($hasMoa): ?>
                                        <div class="moa-cell-actions">
                                            <button type="button" class="cc-moa-btn view" onclick="openPdfModalImport(<?= (int)$c['import_id'] ?>, <?= js_string_attr($c['company']) ?>)"><i class="fas fa-eye"></i> View</button>
                                            <a class="cc-moa-btn download" href="?view_moa_pdf_import=<?= (int)$c['import_id'] ?>" target="_blank"><i class="fas fa-download"></i> Download</a>
                                        </div>
                                    <?php else: ?>
                                        <span class="moa-none-label"><i class="fas fa-minus-circle"></i> No MOA on file</span>
                                    <?php endif; ?>
                                <?php elseif (!empty($c['has_moa_existing'])): ?>
                                    <?php /* NEW (Existing Partnership MOA revision): an Existing-request-type
                                            company's "MOA Document (Existing Partnership)" upload opens here. */ ?>
                                    <div class="moa-cell-actions">
                                        <button type="button" class="cc-moa-btn view" onclick="openPdfModalExisting(<?= (int)$uid ?>, <?= js_string_attr($c['company']) ?>)"><i class="fas fa-eye"></i> View</button>
                                        <a class="cc-moa-btn download" href="?view_moa_existing=<?= (int)$uid ?>&amp;download=1" target="_blank"><i class="fas fa-download"></i> Download</a>
                                    </div>
                                <?php elseif ($hasMoa): ?>
                                    <div class="moa-cell-actions">
                                        <button type="button" class="cc-moa-btn view" onclick="openPdfModal(<?= $uid ?>, <?= js_string_attr($c['company']) ?>)"><i class="fas fa-eye"></i> View</button>
                                        <a class="cc-moa-btn download" href="?view_moa_pdf=<?= $uid ?>" target="_blank"><i class="fas fa-download"></i> Download</a>
                                    </div>
                                <?php else: ?>
                                    <span class="moa-none-label"><i class="fas fa-minus-circle"></i> No MOA on file</span>
                                <?php endif; ?>
                            </td>
                            <td style="text-align:center;">
                                <?php /* NEW (Requirements column revision): read-only "View
                                        Requirements" access for registered companies — opens
                                        the requirementsModal below, populated via the
                                        ajax_fetch_requirements endpoint. Imported companies
                                        have no account yet, so they cannot have submitted any
                                        compliance requirement (same reasoning already used for
                                        the OJT Students column). */ ?>
                                <?php if ($isImported): ?>
                                    <span class="ojt-count-label ojt-count-na" title="Imported companies have no account yet, so they have not submitted any requirements">—</span>
                                <?php else: ?>
                                    <button type="button" class="cc-moa-btn view" onclick="openRequirementsModal(<?= $uid ?>, <?= js_string_attr($c['company']) ?>)"><i class="fas fa-folder-open"></i> View Requirements</button>
                                <?php endif; ?>
                            </td>
                        </tr>
                        <?php endwhile; ?>
                    </tbody>
                </table>
            </div>

            <?php if ($total_pages > 1): ?>
            <div class="pagination">
                <?php if ($page > 1): ?><a href="<?= build_url(['page'=>$page-1]) ?>" data-page="<?= $page-1 ?>">&laquo; Prev</a><?php else: ?><span class="disabled">&laquo; Prev</span><?php endif; ?>
                <?php for ($i=1; $i<=$total_pages; $i++): ?>
                    <?php if ($i==$page): ?><span class="active"><?= $i ?></span><?php else: ?><a href="<?= build_url(['page'=>$i]) ?>" data-page="<?= $i ?>"><?= $i ?></a><?php endif; ?>
                <?php endfor; ?>
                <?php if ($page < $total_pages): ?><a href="<?= build_url(['page'=>$page+1]) ?>" data-page="<?= $page+1 ?>">Next &raquo;</a><?php else: ?><span class="disabled">Next &raquo;</span><?php endif; ?>
            </div>
            <?php endif; ?>
            <div class="pagination-info">Showing <?= $offset + 1 ?> – <?= min($offset + $records_per_page, $total_companies) ?> of <?= $total_companies ?> companies</div>
<?php
}
$table_section_html = ob_get_clean();

/* NEW: if this request came from the JS-driven search/filter/pagination
   (identified by ?ajax_table=1, sent with the X-Requested-With header),
   return just the rendered fragment as JSON and stop — no full HTML
   document, no page reload on the client. */
if (isset($_GET['ajax_table']) && $_GET['ajax_table'] === '1') {
    header('Content-Type: application/json');
    echo json_encode([
        'html'  => $table_section_html,
        'total' => (int) $total_companies,
    ]);
    exit;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>Company List</title>
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
<style>
:root {
    --neust-maroon: #07145fe5;
    --neust-gold: #FFD700;
    --bg: #fcfaf7;
    --text: #2d1b1b;

    /* ══════════════════════════════════════════════════════════
       NEW: "Field Ops Grid" content-area palette (design #4 from the
       style previews). Scoped to its own variables so the sidebar and
       navbar — which keep using --neust-maroon / --neust-gold above —
       are completely unaffected. Every content component below (import
       card, filter bar, toolbar, table, badges, modals, pagination)
       is restyled using these instead.
       ══════════════════════════════════════════════════════════ */
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
body { font-family:'Segoe UI',Tahoma,Geneva,Verdana,sans-serif; background:var(--bg); margin:0; display:flex; color:var(--text); min-height:100vh; }
.sidebar { width:260px; background:var(--neust-maroon); height:100vh; position:fixed; display:flex; flex-direction:column; z-index:1000; box-shadow:4px 0 10px rgba(0,0,0,0.1); }
.sidebar.collapsed { width:80px; }
.sidebar-header { padding:20px; display:flex; align-items:center; justify-content:space-between; border-bottom:1px solid rgba(255,255,255,0.1); }
.sidebar-header-titles { overflow:hidden; transition:0.3s; min-width:0; }
.sidebar-header h2 { color:var(--neust-gold); margin:0; font-size:18px; font-weight:bold; white-space:nowrap; overflow:hidden; text-overflow:ellipsis; }
.sidebar-role-label { display:block; color:rgba(255,255,255,0.55); font-size:11px; font-weight:700; text-transform:uppercase; letter-spacing:0.8px; margin-top:3px; }
.sidebar.collapsed .sidebar-header-titles { opacity:0; width:0; }
.sidebar-links { flex:1; display:flex; flex-direction:column; padding:10px 0; }
.sidebar a { padding:15px 25px; color:#cbd5e0; text-decoration:none; font-size:14px; display:flex; align-items:center; transition:0.2s; white-space:nowrap; position:relative; }
.sidebar a i { width:30px; font-size:18px; margin-right:15px; text-align:center; }
.sidebar.collapsed a i { margin-right:0; }
.sidebar.collapsed .link-text { display:none; }
.sidebar a:hover { color:white; background:rgba(255,255,255,0.05); }
.sidebar a.active { background:#1a237e; color:white; border-left:4px solid var(--neust-gold); }
.sidebar .logout-link { margin-top:auto; padding:20px; border-top:1px solid rgba(255,255,255,0.1); }
.sidebar .logout-link a { border:1px solid var(--neust-gold); color:var(--neust-gold); border-radius:6px; justify-content:center; padding:10px; }
.toggle-btn { background:transparent; border:none; color:white; cursor:pointer; font-size:20px; }
.sidebar-badge-app, .sidebar-badge-moa, .sidebar-badge-ungraded { color:white; border-radius:50%; width:18px; height:18px; font-size:10px; font-weight:700; display:inline-flex; align-items:center; justify-content:center; position:absolute; right:18px; top:50%; transform:translateY(-50%); }
.sidebar-badge-app { background:#dc2626; }
.sidebar-badge-moa { background:#ef4444; }
.sidebar-badge-ungraded { background:#d97706; }
.main-content { margin-left:260px; width:calc(100% - 260px); min-height:100vh; }
.sidebar.collapsed + .main-content { margin-left:80px; width:calc(100% - 80px); }
.navbar { background:var(--neust-maroon); padding:10px 30px; display:flex; justify-content:space-between; align-items:center; color:white; height:60px; }
.logo-section { display:flex; align-items:center; gap:12px; }
.university-logo { height:40px; }

/* ══════════════════════════════════════════════════════════
   NEW: "Field Ops Grid" content area (everything below the navbar).
   Background switched to the grid palette's off-white/blue tone; page
   heading restyled uppercase/navy to match design #4.
   ══════════════════════════════════════════════════════════ */
.container { padding:30px; max-width:1500px; margin:0 auto; background:var(--grid-bg); }
.container h2 { color:var(--grid-navy); text-transform:uppercase; letter-spacing:0.6px; font-size:20px; }
.container > p { color:var(--grid-muted) !important; }

.filter-bar { background:#fff; border:1px solid var(--grid-border); border-radius:0; padding:16px 20px; margin-bottom:25px; box-shadow:none; display:flex; flex-wrap:wrap; gap:32px; align-items:flex-end; }
.filter-group { flex:1; min-width:180px; }
.filter-group label { display:block; font-size:11px; font-weight:600; color:var(--grid-navy); margin-bottom:6px; text-transform:uppercase; letter-spacing:0.5px; }
.filter-group input, .filter-group select { width:100%; padding:9px 12px; border:1px solid var(--grid-border); border-radius:0; font-size:13px; background:#fff; color:var(--text); }

/* NEW: toolbar directly above the table — Add Company Manually, Edit,
   Delete Entry, and Export to Excel now live here, right where the
   list itself is, instead of only inside the import card above.
   Restyled flat/square with uppercase labels per design #4. */
/* FIX: "Total Companies" and the toolbar stay aligned on the SAME line at
   all times (sidebar expanded or collapsed) — flex-wrap:nowrap keeps them
   from ever splitting onto two rows. The toolbar itself (below) already
   has its own overflow-x:auto fallback, so if the sidebar-expanded width
   ever gets too tight to show all 5 buttons at full size, the toolbar
   scrolls sideways instead of squeezing the buttons or wrapping the row. */
.table-header-row { display:flex; flex-wrap:nowrap; justify-content:space-between; align-items:center; gap:14px 24px; margin-bottom:14px; }
/* FIX: buttons used to flex-wrap onto a second line once a label like "Edit
   Selected"/"Cancel" made the row too wide, leaving one button stranded and
   misaligned below the rest. Now the toolbar always stays on a single row —
   if it ever runs out of horizontal room it scrolls sideways instead of
   wrapping, the same way the company table below it already does. */
.table-toolbar { display:flex; flex-wrap:nowrap; gap:8px; margin-bottom:0; align-items:center; justify-content:flex-end; margin-left:auto; flex:1 1 auto; min-width:0; overflow-x:auto; overflow-y:hidden; scrollbar-width:thin; }
.table-toolbar::-webkit-scrollbar { height:6px; }
.table-toolbar::-webkit-scrollbar-thumb { background:var(--grid-border); }
/* FIX: keep "Total Companies" on the same horizontal line as the toolbar buttons instead of being pushed above them —
   the label never shrinks/wraps, and the toolbar buttons use slightly tighter side padding so the whole set fits beside it. */
.table-header-row .company-total-count { flex:0 0 auto; white-space:nowrap; }
.table-header-row .table-toolbar .add-company-btn,
.table-header-row .table-toolbar .edit-entry-btn,
.table-header-row .table-toolbar .delete-entry-btn,
.table-header-row .table-toolbar .cancel-selection-btn,
.table-header-row .table-toolbar .export-btn { padding:10px 13px; gap:7px; flex-shrink:0; white-space:nowrap; }
.delete-entry-btn { background:#fff; color:var(--grid-red); border:1px solid var(--grid-border); border-radius:0; padding:10px 18px; font-weight:600; cursor:pointer; transition:opacity 0.2s; display:inline-flex; align-items:center; gap:8px; font-size:12px; text-transform:uppercase; letter-spacing:0.4px; justify-content:center; }
.delete-entry-btn:disabled { color:#c79b9b; cursor:not-allowed; opacity:0.75; }
.delete-entry-btn:not(:disabled):hover { background:var(--grid-red-bg); }
.delete-entry-count { font-weight:800; }
.cancel-selection-btn { background:#fff; color:var(--grid-muted); border:1px solid var(--grid-border); border-radius:0; padding:10px 18px; font-weight:600; cursor:pointer; transition:opacity 0.2s; display:inline-flex; align-items:center; gap:8px; font-size:12px; text-transform:uppercase; letter-spacing:0.4px; justify-content:center; }
.cancel-selection-btn:hover { background:#f3f4f7; }

/* NEW: toolbar-level Edit button — mirrors the Delete button's visual
   weight/style so both actions feel consistent, using the flat/bordered
   design #4 language with the navy accent color so it clearly reads as
   "editing", not "deleting". */
.edit-entry-btn { background:#fff; color:var(--grid-navy); border:1px solid var(--grid-border); border-radius:0; padding:10px 18px; font-weight:600; cursor:pointer; transition:opacity 0.2s; display:inline-flex; align-items:center; gap:8px; font-size:12px; text-transform:uppercase; letter-spacing:0.4px; justify-content:center; }
.edit-entry-btn:disabled { color:#a9b0c2; cursor:not-allowed; opacity:0.75; }
.edit-entry-btn:not(:disabled):hover { background:#f3f4f7; }

/* NEW: Export to Excel button (flat bordered "chip" per design #4's
   Export element). */
.export-btn { background:#fff; color:var(--grid-navy); border:1px solid var(--grid-border); border-radius:0; padding:10px 18px; font-weight:600; cursor:pointer; transition:opacity 0.2s; display:inline-flex; align-items:center; gap:8px; font-size:12px; text-transform:uppercase; letter-spacing:0.4px; text-decoration:none; justify-content:center; }
.export-btn:hover { background:#f3f4f7; }

/* NEW: Add Company Manually toolbar button — primary navy fill, mirrors
   design #4's "Add company" button exactly. */
.add-company-btn { background:var(--grid-navy); color:#fff; border:none; border-radius:0; padding:10px 18px; font-weight:600; cursor:pointer; transition:opacity 0.2s; display:inline-flex; align-items:center; gap:8px; font-size:12px; text-transform:uppercase; letter-spacing:0.4px; justify-content:center; }
.add-company-btn:hover { opacity:0.9; }

/* NEW: the row-selection checkbox column is hidden by default and only
   revealed once the admin clicks "Delete" or "Edit" to enter selection
   mode, so the table stays clean until the admin actually wants to
   select something.
   FIX (this revision): visibility of this column is now driven by a
   single, simple class selector (".checkbox-cell") applied directly to
   the header cell and every row cell — not by ":nth-child(1)" combined
   with an ancestor ".selection-mode" class, which required two selectors
   to out-specificity each other correctly. Showing the column when
   selection mode is active is now done by JavaScript setting the
   element's inline "display" style directly (see attachCheckboxHandlers
   / enterDeleteSelectionMode / enterEditSelectionMode /
   exitSelectionMode below), and an inline style always wins over any
   stylesheet rule — so there is no specificity fight left that could
   ever hide the column when it should be visible (or vice versa). This
   rule below only supplies the *default* (closed) state before
   JavaScript runs, and as a fallback if JavaScript is unavailable. */
.checkbox-cell { display:none; }

/* FIX: make the checkbox itself an obvious, easy-to-hit target once the
   column is revealed, and make sure nothing can accidentally block
   pointer events on it. */
.company-table input[type="checkbox"] { cursor:pointer; width:17px; height:17px; vertical-align:middle; pointer-events:auto; position:relative; z-index:1; }
.company-table input[type="checkbox"]:disabled { cursor:not-allowed; }

/* NEW: the inline "Set Request Type" dropdown shown for registered
   companies with no Request Type on record yet — deliberately small
   and flat, matching the other badges/dropdowns in this table rather
   than looking like a full form control. */
.reg-request-type-select {
    font-size: 11px;
    font-weight: 600;
    padding: 4px 6px;
    border: 1px solid var(--grid-border);
    border-radius: 0;
    background: #fff;
    color: var(--grid-navy);
    cursor: pointer;
    max-width: 160px;
}
.reg-request-type-select:disabled { opacity: 0.6; cursor: not-allowed; }

/* NEW: total companies label, top-left above the table */
.company-total-count { display:block; text-align:left; margin:0; font-size:13px; font-weight:700; color:var(--grid-navy); text-transform:uppercase; letter-spacing:0.4px; }
.company-total-count i { margin-right:6px; }
.company-total-count strong { font-weight:800; }

.table-scroll-wrapper { overflow-x:auto; -webkit-overflow-scrolling:touch; border-radius:0; border:1px solid var(--grid-border); box-shadow:none; scrollbar-width:thin; scrollbar-color:var(--grid-navy) var(--grid-border); }
.table-scroll-wrapper::-webkit-scrollbar { height:8px; }
.table-scroll-wrapper::-webkit-scrollbar-track { background:var(--grid-border); }
.table-scroll-wrapper::-webkit-scrollbar-thumb { background:var(--grid-navy); border-radius:0; }
.table-scroll-wrapper::-webkit-scrollbar-thumb:hover { background:#0f1a30; }

/* NEW: table restyled to design #4's flat grid — square header cells,
   uppercase letter-spaced labels, thin row dividers, and a colored
   left-edge indicator strip on the company-name cell instead of rounded
   pill badges everywhere.
   NEW (Company Type revision): a "Company Type" column now sits between
   Validation (7) and Request Type (was 8, now 9) — every subsequent
   column's nth-child index shifts by one accordingly, and the table's
   min-width is bumped slightly to keep everything comfortably readable
   with the extra column. */
.company-table { width:100%; border-collapse:collapse; background:#fff; min-width:1900px; table-layout:auto; }
.company-table th { background:var(--grid-navy); color:#fff; padding:12px 16px; text-align:left; font-size:11px; font-weight:700; text-transform:uppercase; letter-spacing:0.4px; white-space:nowrap; }
.company-table td { padding:12px 16px; border-bottom:1px solid var(--grid-border-soft); color:#2d3748; font-size:13px; vertical-align:middle; }
.company-table tr:hover td { background:#F5F7FB; }
.company-table tr:last-child td { border-bottom:none; }
.company-table th:nth-child(1), .company-table td:nth-child(1) { width:44px; min-width:44px; text-align:center; }
.company-table th:nth-child(2), .company-table td:nth-child(2) { min-width:190px; border-left:3px solid transparent; }
.company-table th:nth-child(3), .company-table td:nth-child(3) { min-width:170px; white-space:nowrap; }
.company-table th:nth-child(4), .company-table td:nth-child(4) { min-width:190px; white-space:nowrap; }
.company-table th:nth-child(5), .company-table td:nth-child(5) { min-width:130px; white-space:nowrap; }
.company-table th:nth-child(6), .company-table td:nth-child(6) { min-width:220px; }
.company-table th:nth-child(7), .company-table td:nth-child(7) { min-width:120px; white-space:nowrap; }
.company-table th:nth-child(8), .company-table td:nth-child(8) { min-width:130px; white-space:nowrap; }
.company-table th:nth-child(9), .company-table td:nth-child(9) { min-width:150px; white-space:nowrap; }
.company-table th:nth-child(10), .company-table td:nth-child(10) { min-width:120px; white-space:nowrap; text-align:center; }
.company-table th:nth-child(11), .company-table td:nth-child(11) { min-width:230px; }
/* NEW (Requirements column revision): 12th column — "Requirements". */
.company-table th:nth-child(12), .company-table td:nth-child(12) { min-width:170px; white-space:nowrap; text-align:center; }

/* NEW: highlight a row while its checkbox is selected in Delete or Edit
   mode, so the admin can clearly see which entries are marked. */
.company-table tr.row-selected td { background:var(--grid-red-bg); }
.company-table tr.row-selected:hover td { background:#f1d9d9; }

/* NEW: distinct highlight color while in Edit selection mode, so it
   reads visually different from the red "marked for deletion" state. */
.company-table tr.row-selected-edit td { background:var(--grid-amber-bg); }
.company-table tr.row-selected-edit:hover td { background:#f5ecc7; }
/* NEW (Export exclusion revision): distinct highlight for a row checked
   to be LEFT OUT of the export — a light navy tint so it never reads as
   either "will be deleted" (red) or "being edited" (amber). */
.company-table tr.row-selected-export td { background:#E7ECF7; }
.company-table tr.row-selected-export:hover td { background:#d7deef; }
/* NEW: while a selection mode (Edit/Delete) is active, hint that the
   whole row is clickable to select it — not just the small checkbox.
   Interactive controls inside the row keep their own normal cursor. */
.company-table.selection-mode-active tbody tr { cursor:pointer; }
.company-table.selection-mode-active tbody tr a,
.company-table.selection-mode-active tbody tr button,
.company-table.selection-mode-active tbody tr select,
.company-table.selection-mode-active tbody tr label { cursor:auto; }

/* NEW: OJT Students count badge — flat colored text per design #4,
   no rounded pill background. */
.ojt-count-label { font-size:12px; font-weight:700; display:inline-flex; align-items:center; gap:6px; padding:0; border-radius:0; background:none; }
.ojt-count-label.ojt-count-active { color:var(--grid-navy); }
.ojt-count-label.ojt-count-zero { color:var(--grid-muted); }
.ojt-count-label.ojt-count-na { color:#9aa2b1; font-style:italic; background:none; padding:0; }

/* NEW: Admin Copy of MOA cell */
.admin-moa-cell { display:flex; flex-direction:column; gap:6px; }
.cc-moa-btn.upload, .cc-moa-btn.replace { background:#fff; color:var(--grid-navy); border:1px solid var(--grid-border); }

/* NEW: status badges restyled flat/colored-text with a small left
   accent bar, matching design #4's "Verified / Pending" treatment
   (colored text, no pill background) while keeping a light backing
   tint for scanability in a dense table. */
.cc-badge { font-size:11px; font-weight:700; padding:3px 10px 3px 8px; border-radius:0; white-space:nowrap; display:inline-block; text-transform:uppercase; letter-spacing:0.3px; border-left:3px solid transparent; }
.cc-badge.Verified { background:var(--grid-green-bg); color:var(--grid-green); border-left-color:var(--grid-green); }
.cc-badge.Pending  { background:var(--grid-amber-bg); color:var(--grid-amber); border-left-color:var(--grid-amber); }
/* NEW (Status column revision): Active/Validating/Inactive — the three
   states now shown in the renamed "Status" column. Active/Validating
   reuse the same green/amber treatment as the old Verified/Pending
   badges; Inactive gets its own neutral/red treatment since it means
   "nothing submitted yet". */
.cc-badge.Active     { background:var(--grid-green-bg); color:var(--grid-green); border-left-color:var(--grid-green); }
.cc-badge.Validating { background:var(--grid-amber-bg); color:var(--grid-amber); border-left-color:var(--grid-amber); }
.cc-badge.Inactive   { background:var(--grid-red-bg); color:var(--grid-red); border-left-color:var(--grid-red); }
.cc-badge.NotRegistered { background:#EDEFF3; color:var(--grid-muted); border-left-color:var(--grid-border); }
/* NEW (Requirements column revision): per-requirement status badges
   used inside the "View Requirements" modal — Verified/Pending reuse
   the badges already defined above; Awaiting (no file submitted yet)
   and Rejected (company_validation.php denied it) are new. */
.cc-badge.Awaiting { background:#EDEFF3; color:var(--grid-muted); border-left-color:var(--grid-border); }
.cc-badge.Rejected { background:var(--grid-red-bg); color:var(--grid-red); border-left-color:var(--grid-red); }
/* NEW (Status column plain-text revision): in the table's Status column
   only, the badge keeps its colored text (Active green, Validating amber,
   Inactive red, Not Registered gray) but no longer has the colored left
   side bar or the tinted background. The badges inside the "View
   Requirements" viewer are not affected. */
.cc-badge.status-col-badge { background:none; border-left:none; padding-left:0; padding-right:0; }

/* NEW (Requirements column revision): the "View Requirements" modal's
   per-requirement list, shown inside the shared .modal-box. */
.requirement-item { border:1px solid var(--grid-border); background:var(--grid-bg); padding:12px 14px; margin-bottom:10px; }
.requirement-item:last-child { margin-bottom:0; }
.requirement-item-header { display:flex; justify-content:space-between; align-items:center; gap:10px; }
.requirement-item-title { font-weight:700; color:var(--grid-navy); font-size:13px; }
.requirement-files { display:flex; flex-wrap:wrap; gap:8px; margin-top:10px; }

.req-badge { font-size:11px; font-weight:700; padding:3px 10px; border-radius:0; white-space:nowrap; display:inline-flex; align-items:center; gap:5px; text-transform:uppercase; letter-spacing:0.3px; border:1px solid var(--grid-border); background:#fff; }
.req-badge.New      { color:var(--grid-navy); border-color:var(--grid-navy); }
/* UPDATED (this adjustment): Request Type badges are all navy — "Existing Company"
   now matches "New Company". Blank ("—") keeps the grey "none" style. */
.req-badge.Existing { color:var(--grid-navy); border-color:var(--grid-navy); }
.req-badge.none     { color:#9aa2b1; }
.import-tag { font-size:10px; font-weight:800; color:#0f6e56; background:#e3f4ee; padding:2px 8px; border-radius:0; margin-left:6px; display:inline-block; text-transform:uppercase; letter-spacing:0.4px; }

/* NEW (Company Type revision): "Public"/"Private" badge, styled like
   .req-badge (flat, bordered, uppercase) but with its own distinct
   accent colors so it never gets visually confused with the Request
   Type badge sitting right next to it. */
.type-badge { font-size:11px; font-weight:700; padding:3px 10px; border-radius:0; white-space:nowrap; display:inline-flex; align-items:center; gap:5px; text-transform:uppercase; letter-spacing:0.3px; border:1px solid var(--grid-border); background:#fff; }
/* UPDATED (this adjustment): Company Type badges are all navy (#1B2A4A), the
   same as the Request Type badges — Public and Private alike. Blank types
   keep the grey "none" style. */
.type-badge.Public  { color:var(--grid-navy); border-color:var(--grid-navy); }
.type-badge.Private { color:var(--grid-navy); border-color:var(--grid-navy); }
.type-badge.none    { color:#9aa2b1; }

.moa-cell-actions { display:flex; flex-wrap:wrap; gap:6px; align-items:center; }
.cc-moa-btn { border:1px solid var(--grid-border); border-radius:0; padding:6px 12px; font-size:11px; font-weight:700; cursor:pointer; display:inline-flex; align-items:center; gap:5px; text-decoration:none; text-transform:uppercase; letter-spacing:0.3px; }
.cc-moa-btn.view { background:#fff; color:#5b3fa0; border-color:#5b3fa0; }
.cc-moa-btn.download { background:var(--grid-navy); color:#fff; border-color:var(--grid-navy); }
.moa-none-label { color:#9aa2b1; font-size:12px; font-style:italic; }

.empty-state { text-align:center; padding:60px 20px; color:var(--grid-muted); background:#fff; border:1px solid var(--grid-border); }
.empty-state i { font-size:48px; margin-bottom:16px; display:block; color:var(--grid-border); }

.pagination { display:flex; justify-content:center; align-items:center; gap:0; margin-top:20px; flex-wrap:wrap; border:1px solid var(--grid-border); width:fit-content; margin-left:auto; margin-right:auto; }
.pagination a, .pagination span { display:inline-flex; align-items:center; justify-content:center; min-width:40px; height:38px; padding:0 12px; border-radius:0; font-size:13px; font-weight:500; text-decoration:none; background:#fff; color:var(--grid-navy); border-right:1px solid var(--grid-border); }
.pagination a:last-child, .pagination span:last-child { border-right:none; }
.pagination a:hover { background:var(--grid-navy); color:#fff; }
.pagination .active { background:var(--grid-navy); color:#fff; }
.pagination .disabled { opacity:0.5; }
.pagination-info { text-align:center; margin-top:12px; font-size:12px; color:var(--grid-muted); text-transform:uppercase; letter-spacing:0.3px; }

/* NEW: no-reload table refresh transitions (search / filter / pagination / add company) */
#companyTableSection { transition: opacity 0.2s ease; }
#companyTableSection.table-loading { opacity:0.35; pointer-events:none; }
#companyTableSection.table-fade-in { animation: companyTableFadeIn 0.35s ease; }
@keyframes companyTableFadeIn { from { opacity:0; transform:translateY(6px); } to { opacity:1; transform:translateY(0); } }

/* NEW: floating confirmation/error toast used by the no-reload Add Company flow */
#floatingAlertContainer { position:fixed; top:20px; right:20px; z-index:10050; display:flex; flex-direction:column; gap:10px; max-width:320px; }
.floating-alert { background:#fff; padding:14px 18px; border-radius:0; border:1px solid var(--grid-border); box-shadow:0 6px 24px rgba(0,0,0,0.12); font-size:13px; font-weight:600; line-height:1.4; opacity:0; transform:translateX(50px); transition:opacity 0.3s ease, transform 0.3s ease; }
.floating-alert.show { opacity:1; transform:translateX(0); }
.floating-alert-success { border-left:3px solid var(--grid-green); color:var(--grid-green); }
.floating-alert-error { border-left:3px solid var(--grid-red); color:var(--grid-red); }

/* PDF preview modal */
#pdfModal { display:none; position:fixed; inset:0; background:rgba(0,0,0,0.88); z-index:9999; flex-direction:column; }
#pdfModal.open { display:flex; }
#pdfModalBar { background:var(--grid-navy); padding:12px 24px; display:flex; align-items:center; justify-content:space-between; }
#pdfModalTitle { color:white; font-size:14px; font-weight:700; display:flex; align-items:center; gap:10px; text-transform:uppercase; letter-spacing:0.3px; }
#pdfModalTitle i { color:#fff; }
.pdf-close-btn { background:var(--grid-red); color:white; border:none; border-radius:0; padding:8px 16px; font-weight:700; cursor:pointer; text-transform:uppercase; letter-spacing:0.3px; font-size:12px; }
#pdfModalFrame { flex:1; width:100%; border:none; background:white; }

@keyframes modalPop { from { transform:scale(0.9); opacity:0; } to { transform:scale(1); opacity:1; } }

.alert { padding:12px 20px; border-radius:0; margin-bottom:20px; border:1px solid; }
.alert-success { background:var(--grid-green-bg); color:var(--grid-green); border-color:#bfe0bf; }
.alert-error { background:var(--grid-red-bg); color:var(--grid-red); border-color:#e3bcbc; }

/* ── NEW: Import / Add Company card (mirrors admin_student_list.php's import card), restyled flat/bordered ── */
.import-card { background:#fff; border:1px solid var(--grid-border); border-radius:0; padding:22px; margin-bottom:25px; box-shadow:none; }
.import-card h3 { margin-top:0; color:var(--grid-navy); border-bottom:2px solid var(--grid-navy); padding-bottom:10px; display:inline-block; font-size:14px; text-transform:uppercase; letter-spacing:0.5px; }
.import-card-top { display:flex; justify-content:space-between; align-items:flex-start; flex-wrap:wrap; gap:16px; }
.file-upload-wrapper { position:relative; margin-bottom:16px; flex:1; min-width:280px; }
.file-upload-label { display:flex; flex-direction:column; align-items:center; justify-content:center; padding:32px 20px; background:var(--grid-bg); border:2px dashed var(--grid-border); border-radius:0; cursor:pointer; transition:all 0.3s ease; text-align:center; }
.file-upload-label.drag-over { border-color:var(--grid-navy); background:#e7ebf3; }
.file-upload-label:hover { border-color:var(--grid-navy); background:#e7ebf3; }
.file-upload-label i { font-size:40px; color:var(--grid-navy); margin-bottom:10px; }
.file-upload-label .upload-title { font-weight:600; color:#1e293b; margin-bottom:4px; }
.file-upload-label .upload-subtitle { font-size:12px; color:var(--grid-muted); }
.file-upload-label .file-name { margin-top:10px; font-size:13px; color:var(--grid-navy); font-weight:500; display:none; }
input[type="file"] { display:none; }
.btn-primary { padding:10px 24px; border:none; border-radius:0; font-weight:600; cursor:pointer; transition:opacity 0.2s; display:inline-flex; align-items:center; gap:8px; background:var(--grid-navy); color:white; text-transform:uppercase; letter-spacing:0.3px; font-size:12px; }
.btn-primary:hover { opacity:0.9; }
.import-side-actions { display:flex; flex-direction:column; gap:10px; min-width:220px; }

/* Add Company Modal / Edit Company Modal (shared classes), restyled flat/bordered */
.add-company-modal-overlay { display:none; position:fixed; inset:0; background:rgba(0,0,0,0.5); z-index:10003; justify-content:center; align-items:center; }
.add-company-modal-overlay.open { display:flex; }
.add-company-modal-box { background:#fff; border-radius:0; border:1px solid var(--grid-border); padding:32px; width:560px; max-width:90%; max-height:90vh; overflow-y:auto; animation:modalPop 0.3s ease; }
.add-company-modal-header { display:flex; justify-content:space-between; align-items:center; margin-bottom:24px; padding-bottom:16px; border-bottom:1px solid var(--grid-border); }
.add-company-modal-header h3 { margin:0; color:var(--grid-navy); font-size:16px; text-transform:uppercase; letter-spacing:0.4px; }
.close-add-company-btn { background:none; border:none; font-size:24px; cursor:pointer; color:var(--grid-muted); }
.form-group { margin-bottom:18px; }
.form-group label { display:block; font-weight:600; color:#1e293b; margin-bottom:8px; font-size:13px; }
.form-group label .required { color:var(--grid-red); }
.form-group input, .form-group select { width:100%; padding:10px 12px; border:1px solid var(--grid-border); border-radius:0; font-size:14px; box-sizing:border-box; }
.form-row { display:flex; gap:15px; }
.form-row .form-group { flex:1; }
.custom-position-group { display:none; margin-top:10px; }
.custom-position-group.show { display:block; }
.company-status-options { display:flex; gap:20px; margin-top:6px; flex-wrap:wrap; }
.company-status-options label { display:flex; align-items:center; gap:6px; font-weight:500; cursor:pointer; margin-bottom:0; }
.company-status-options input[type="radio"] { width:auto; }
/* NEW: MOA upload section (Edit modal's "Replace Admin Copy of MOA")
   fades/slides in instead of snapping open, so selecting "Existing
   Company (has MOA)" visibly reveals the PDF upload control (not just
   its label) with a smooth transition. UPDATED (this revision): this
   class is no longer used by the manual Add Company modal — that
   form's own MOA upload section has been removed entirely — but it
   remains in active use by the Edit Imported Company modal's "Replace
   Admin Copy of MOA" section below. */
.moa-upload-group { display:none; opacity:0; transform:translateY(-8px); transition:opacity 0.22s ease, transform 0.22s ease; }
.moa-upload-group.show { opacity:1; transform:translateY(0); }
.moa-file-upload-label { display:flex; align-items:center; gap:10px; padding:12px 14px; background:var(--grid-bg); border:2px dashed var(--grid-border); border-radius:0; cursor:pointer; transition:all 0.2s ease; }
.moa-file-upload-label:hover { border-color:var(--grid-navy); background:#e7ebf3; }
.moa-file-upload-label i { font-size:20px; color:var(--grid-navy); }
.moa-file-upload-label .moa-upload-text { font-size:13px; color:#475569; font-weight:600; }
.moa-file-selected-name { margin-top:8px; font-size:12px; color:var(--grid-navy); font-weight:600; display:none; }
.help-text { font-size:11px; color:var(--grid-muted); margin-top:5px; }
.modal-actions { display:flex; gap:12px; justify-content:flex-end; margin-top:24px; padding-top:16px; border-top:1px solid var(--grid-border); }
.btn-submit { background:var(--grid-navy); color:white; border:none; border-radius:0; padding:10px 24px; font-weight:600; cursor:pointer; text-transform:uppercase; letter-spacing:0.3px; font-size:12px; }
.btn-cancel-modal { background:#fff; color:var(--grid-navy); border:1px solid var(--grid-border); border-radius:0; padding:10px 24px; font-weight:600; cursor:pointer; text-transform:uppercase; letter-spacing:0.3px; font-size:12px; }

/* ── FIX: Add Company / Edit Company modals were stretched to the very
   top and bottom of the page (one long single-column stack of fields).
   Rules below are scoped to #addCompanyModal and #editCompanyModal ONLY
   (they share the .add-company-modal-* classes), so no other modal is
   affected. The form is now a compact 3-column grid with tight spacing,
   the box always leaves a margin above/below the screen edges, only the
   form area scrolls if the screen is really short, and the action
   buttons stay pinned at the bottom. 2 columns on medium screens, 1 on
   small screens. */
#addCompanyModal, #editCompanyModal { align-items:center; padding:20px 0; box-sizing:border-box; }
#addCompanyModal .add-company-modal-box, #editCompanyModal .add-company-modal-box { width:860px; max-width:94%; max-height:calc(100vh - 40px); max-height:calc(100dvh - 40px); padding:16px 24px 0 24px; box-sizing:border-box; }
#addCompanyModal .add-company-modal-header, #editCompanyModal .add-company-modal-header { margin-bottom:12px; padding-bottom:8px; }
#addCompanyModal .add-company-modal-header h3, #editCompanyModal .add-company-modal-header h3 { font-size:15px; }
#addCompanyModal .add-company-grid, #editCompanyModal .add-company-grid { display:grid; grid-template-columns:repeat(3, 1fr); column-gap:14px; row-gap:0; align-items:start; }
#addCompanyModal .add-company-grid .span-2, #editCompanyModal .add-company-grid .span-2 { grid-column:span 2; }
#addCompanyModal .add-company-grid .span-3, #editCompanyModal .add-company-grid .span-3 { grid-column:1 / -1; }
#addCompanyModal .form-group, #editCompanyModal .form-group { margin-bottom:9px; }
#addCompanyModal .form-group label, #editCompanyModal .form-group label { margin-bottom:4px; font-size:12px; }
#addCompanyModal .form-group input, #addCompanyModal .form-group select, #editCompanyModal .form-group input, #editCompanyModal .form-group select { padding:7px 10px; font-size:13px; }
#addCompanyModal .help-text, #editCompanyModal .help-text { font-size:10.5px; margin-top:3px; line-height:1.35; }
#addCompanyModal .company-status-options, #editCompanyModal .company-status-options { gap:4px 16px; margin-top:0; }
#addCompanyModal .company-status-options label, #editCompanyModal .company-status-options label { font-size:12px; }
#addCompanyModal .custom-position-group, #editCompanyModal .custom-position-group { margin-top:6px; }
#editCompanyModal .moa-file-upload-label { padding:8px 12px; }
#addCompanyModal .modal-actions, #editCompanyModal .modal-actions { position:sticky; bottom:0; background:#fff; margin-top:4px; padding:10px 0 12px 0; z-index:2; }
@media (max-width: 900px) {
    #addCompanyModal .add-company-grid, #editCompanyModal .add-company-grid { grid-template-columns:1fr 1fr; }
}
@media (max-width: 640px) {
    #addCompanyModal .add-company-modal-box, #editCompanyModal .add-company-modal-box { padding:14px 14px 0 14px; }
    #addCompanyModal .add-company-grid, #editCompanyModal .add-company-grid { grid-template-columns:1fr; }
    #addCompanyModal .add-company-grid .span-2, #editCompanyModal .add-company-grid .span-2,
    #addCompanyModal .add-company-grid .span-3, #editCompanyModal .add-company-grid .span-3 { grid-column:auto; }
}


/* Delete-selected confirmation modal (also reused, styled per-instance, by the Auto Account Creation Progress modal) */
.modal-overlay { display:none; position:fixed; inset:0; background:rgba(0,0,0,0.5); z-index:9999; justify-content:center; align-items:center; }
.modal-overlay.open { display:flex; }
.modal-box { background:#fff; border-radius:0; border:1px solid var(--grid-border); padding:32px; width:420px; max-width:90%; text-align:center; animation:modalPop 0.3s ease; }
.modal-icon { font-size:44px; color:var(--grid-red); margin-bottom:16px; }
.modal-title { font-size:18px; font-weight:700; color:#1e293b; margin-bottom:8px; text-transform:uppercase; letter-spacing:0.3px; }
.modal-message { color:var(--grid-muted); font-size:14px; margin-bottom:24px; line-height:1.6; text-align:left; }
.modal-actions { display:flex; gap:12px; justify-content:center; }
.modal-cancel, .modal-confirm { padding:10px 24px; border-radius:0; font-weight:600; cursor:pointer; border:1px solid var(--grid-border); transition:opacity 0.2s; text-transform:uppercase; letter-spacing:0.4px; font-size:12px; }
.modal-cancel { background:#fff; color:var(--grid-navy); }
.modal-confirm { background:var(--grid-red); color:white; border-color:var(--grid-red); }
.modal-cancel:hover, .modal-confirm:hover { opacity:0.85; }

/* NEW (Company Type / Request Type import filter revision): checkbox
   groups inside the "importClassificationModal" picker. */
.import-classification-group { margin-top:18px; }
.import-classification-group-label { font-size:11px; font-weight:700; color:var(--grid-navy); text-transform:uppercase; letter-spacing:0.4px; margin-bottom:8px; }
.import-classification-options { display:flex; flex-direction:column; gap:8px; }
.import-classification-option { display:flex; align-items:center; gap:8px; padding:8px 10px; border:1px solid var(--grid-border); background:var(--grid-bg); font-size:13px; color:#2d3748; cursor:pointer; }
.import-classification-option input[type="checkbox"] { width:15px; height:15px; cursor:pointer; flex-shrink:0; }

/* ══════════════════════════════════════════════════════════
   NEW: Auto Account Creation Progress modal — the "loading page" shown
   automatically right after a successful XLSX import (or a manual Add
   Company submission that needs follow-up) while accounts are being
   created for the newly imported/added companies, then swapped to show
   a Created / Skipped / Failed breakdown once the server responds.
   Reuses the same .modal-overlay/.modal-box shell as the Delete
   confirmation popup for visual consistency.
   ══════════════════════════════════════════════════════════ */
.auto-account-stat-grid { display:flex; gap:14px; justify-content:center; margin-bottom:18px; flex-wrap:wrap; }
.auto-account-stat { text-align:center; min-width:70px; }
.auto-account-stat-number { font-size:28px; font-weight:800; line-height:1; }
.auto-account-stat-label { font-size:11px; text-transform:uppercase; letter-spacing:0.4px; color:var(--grid-muted); margin-top:4px; }
.auto-account-stat-number.created { color:var(--grid-green); }
.auto-account-stat-number.skipped { color:var(--grid-amber); }
.auto-account-stat-number.failed { color:var(--grid-red); }
.auto-account-detail-block { text-align:left; margin-bottom:12px; }
.auto-account-detail-block strong { font-size:11px; text-transform:uppercase; letter-spacing:0.4px; display:block; margin-bottom:4px; }
.auto-account-detail-block ul { margin:0; padding-left:18px; font-size:12px; color:#475569; max-height:140px; overflow-y:auto; }
.auto-account-progress-text { text-align:center; color:var(--grid-muted); font-size:13px; margin-top:4px; }

/* ══════════════════════════════════════════════════════════
   NEW: Global loading overlay — a full-page popup with a spinner and
   short animated message shown ONLY while the page is first loading
   its assets, or while an in-page action is being processed (table
   refresh, add/edit/delete company, admin MOA upload, saving MOA
   processing settings, exporting, importing, or creating accounts).
   It fades out and disappears automatically as soon as
   loading/processing finishes. Purely additive — it does not alter any
   existing markup, handler, or business logic; it only wraps existing
   async operations with a show/hide call.
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
        /* NEW (loader sync fix — same as administrator.php): when the page is reloading / leaving, the
           overlay must appear on the very next paint — no 0.35s fade-in, because the browser may stop
           painting this page before a fade would finish. Added by the script only while leaving, and
           removed again when the overlay hides. */
        #globalLoadingOverlay.gl-instant { transition: none; }
.global-loading-box {
    display: flex;
    flex-direction: column;
    align-items: center;
    gap: 16px;
    animation: globalLoadingPop 0.35s ease;
}
.global-loading-spinner {
    width: 54px;
    height: 54px;
    border-radius: 50%;
    border: 5px solid var(--grid-border, #C3CADA);
    border-top-color: var(--grid-navy, #1B2A4A);
    animation: globalLoadingSpin 0.85s linear infinite;
}
.global-loading-text {
    font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
    font-size: 13px;
    font-weight: 700;
    color: var(--grid-navy, #1B2A4A);
    text-transform: uppercase;
    letter-spacing: 0.6px;
    display: flex;
    align-items: center;
    gap: 8px;
}
.global-loading-dots span {
    animation: globalLoadingDots 1.2s infinite;
    opacity: 0;
}
.global-loading-dots span:nth-child(2) { animation-delay: 0.2s; }
.global-loading-dots span:nth-child(3) { animation-delay: 0.4s; }
@keyframes globalLoadingSpin { to { transform: rotate(360deg); } }
@keyframes globalLoadingPop { from { transform: scale(0.9); opacity: 0; } to { transform: scale(1); opacity: 1; } }
@keyframes globalLoadingDots { 0%, 20% { opacity: 0; } 50% { opacity: 1; } 100% { opacity: 0; } }

/* NEW (Edit/Delete success loading page — same as admin_student_list.php):
   after a successful Edit or Delete the same full-page loader switches to
   a check icon + message of the action, instead of a popup notification,
   while the company list refreshes behind it. */
.global-loading-success { display: none; flex-direction: column; align-items: center; gap: 10px; text-align: center; max-width: 420px; padding: 0 20px; }
#globalLoadingOverlay.success-state .global-loading-spinner,
#globalLoadingOverlay.success-state .global-loading-text { display: none; }
#globalLoadingOverlay.success-state .global-loading-success { display: flex; }
.gls-check {
    width: 64px; height: 64px; border-radius: 50%;
    background: var(--grid-green, #2C5A2C); color: #fff;
    display: flex; align-items: center; justify-content: center;
    font-size: 30px; box-shadow: 0 0 0 8px var(--grid-green-bg, #EAF3EA);
    animation: glsCheckPop 0.45s cubic-bezier(.34,1.56,.64,1);
}
.gls-title {
    font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
    font-size: 15px; font-weight: 700; color: var(--grid-navy, #1B2A4A);
    text-transform: uppercase; letter-spacing: 0.6px; margin-top: 6px;
}
.gls-message { font-size: 13px; color: var(--grid-muted, #5B6478); }
.gls-sub { font-size: 11px; color: var(--grid-muted, #5B6478); opacity: .8; display: flex; align-items: center; gap: 6px; }
@keyframes glsCheckPop { from { transform: scale(0.3); opacity: 0; } to { transform: scale(1); opacity: 1; } }

/* ══════════════════════════════════════════════════════════
   NEW (Import/Export result screen revision): full-page result screen
   shown right after an export or import finishes — same backdrop and
   pop-in animation as the loading overlay above, but with a success
   (green check) or failure (red X) icon, a title, a short message and
   an OK button. Success screens close themselves after a few seconds;
   failure screens stay until the admin clicks OK. Sits one layer above
   #globalLoadingOverlay so it can never be hidden behind it.
   ══════════════════════════════════════════════════════════ */
#globalResultOverlay {
    position: fixed;
    inset: 0;
    z-index: 20001;
    display: flex;
    align-items: center;
    justify-content: center;
    padding: 20px;
    box-sizing: border-box;
    background: rgba(238, 241, 246, 0.92);
    opacity: 1;
    visibility: visible;
    transition: opacity 0.35s ease, visibility 0.35s ease;
}
#globalResultOverlay.hidden {
    opacity: 0;
    visibility: hidden;
    pointer-events: none;
}
.global-result-box {
    display: flex;
    flex-direction: column;
    align-items: center;
    gap: 14px;
    max-width: 460px;
    width: 100%;
    text-align: center;
    animation: globalLoadingPop 0.35s ease;
    font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
}
.global-result-icon {
    width: 64px;
    height: 64px;
    border-radius: 50%;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 28px;
    color: #fff;
}
#globalResultOverlay.is-success .global-result-icon { background: var(--grid-green, #2C5A2C); }
#globalResultOverlay.is-error .global-result-icon { background: var(--grid-red, #A02A2A); }
.global-result-title {
    font-size: 14px;
    font-weight: 700;
    text-transform: uppercase;
    letter-spacing: 0.6px;
    margin: 0;
}
#globalResultOverlay.is-success .global-result-title { color: var(--grid-green, #2C5A2C); }
#globalResultOverlay.is-error .global-result-title { color: var(--grid-red, #A02A2A); }
.global-result-message {
    font-size: 13px;
    line-height: 1.5;
    color: var(--grid-navy, #1B2A4A);
    margin: 0;
    white-space: pre-line;
    max-height: 40vh;
    overflow-y: auto;
    word-break: break-word;
}
.global-result-ok {
    padding: 10px 28px;
    border-radius: 0;
    font-weight: 600;
    cursor: pointer;
    border: 1px solid var(--grid-navy, #1B2A4A);
    background: var(--grid-navy, #1B2A4A);
    color: #fff;
    text-transform: uppercase;
    letter-spacing: 0.4px;
    font-size: 12px;
    transition: opacity 0.2s;
}
.global-result-ok:hover { opacity: 0.88; }

/* ══════════════════════════════════════════════════════════
   NEW (Full-screen Requirements Viewer revision): layout for the
   full-screen "View Requirements" preview — dark overlay and top bar
   match the existing MOA #pdfModal exactly; a white left panel lists
   every requirement (flat, bordered, uppercase labels per design #4).
   ══════════════════════════════════════════════════════════ */
#requirementsModal { display:none; position:fixed; inset:0; background:rgba(0,0,0,0.88); z-index:10000; flex-direction:column; }
#requirementsModal.open { display:flex; }
#reqViewerBar { background:var(--grid-navy); padding:12px 24px; display:flex; align-items:center; justify-content:space-between; gap:16px; flex-shrink:0; }
#reqViewerTitle { color:#fff; font-size:14px; font-weight:700; display:flex; align-items:center; gap:10px; text-transform:uppercase; letter-spacing:0.3px; min-width:0; flex-wrap:wrap; }
#reqViewerTitle .req-viewer-current { color:var(--neust-gold); }
#reqViewerTitle .req-viewer-current:not(:empty)::before { content:'/'; color:rgba(255,255,255,0.5); margin-right:10px; }
.req-viewer-bar-actions { display:flex; align-items:center; gap:10px; flex-shrink:0; }
#reqViewerBody { flex:1; display:flex; min-height:0; }
#reqViewerSidebar { width:300px; flex-shrink:0; background:#fff; border-right:1px solid var(--grid-border); overflow-y:auto; display:flex; flex-direction:column; }
.req-viewer-sidebar-head { padding:14px 16px; font-size:11px; font-weight:700; color:var(--grid-navy); text-transform:uppercase; letter-spacing:0.5px; border-bottom:2px solid var(--grid-navy); background:var(--grid-bg); position:sticky; top:0; z-index:1; }
.req-viewer-sidebar-head i { margin-right:6px; }
#reqViewerCount { color:var(--grid-muted); font-weight:600; }
.req-nav-item { border-bottom:1px solid var(--grid-border-soft); }
.req-nav-btn { width:100%; background:#fff; border:none; border-left:3px solid transparent; padding:12px 14px; text-align:left; cursor:pointer; display:flex; flex-direction:column; gap:6px; font-family:inherit; }
.req-nav-btn:hover { background:#F5F7FB; }
.req-nav-item.active > .req-nav-btn { background:#E7ECF7; border-left-color:var(--grid-navy); }
.req-nav-title { font-size:13px; font-weight:700; color:var(--grid-navy); }
.req-nav-meta { display:flex; align-items:center; gap:8px; flex-wrap:wrap; font-size:11px; color:var(--grid-muted); }
.req-nav-files { display:flex; flex-direction:column; padding:0 14px 10px 26px; gap:4px; }
.req-nav-file-btn { background:none; border:1px solid var(--grid-border); padding:5px 10px; font-size:11px; font-weight:700; color:#5b3fa0; text-transform:uppercase; letter-spacing:0.3px; text-align:left; cursor:pointer; display:flex; align-items:center; gap:6px; font-family:inherit; }
.req-nav-file-btn:hover { background:#F5F7FB; }
.req-nav-file-btn.active { background:#5b3fa0; color:#fff; border-color:#5b3fa0; }
.req-nav-empty { padding:18px 16px; font-size:13px; color:var(--grid-muted); }
#reqViewerStage { flex:1; position:relative; background:#fff; min-width:0; }
#reqViewerFrame { position:absolute; inset:0; width:100%; height:100%; border:none; background:#fff; display:none; }
/* NEW (centered preview revision): image requirements are shown in their own
   centered container (scaled to fit) instead of inside the frame, where the
   browser always pins an image to the top-left corner. */
#reqViewerImageWrap { position:absolute; inset:0; display:none; align-items:center; justify-content:center; padding:24px; box-sizing:border-box; background:#fff; overflow:auto; }
#reqViewerImage { max-width:100%; max-height:100%; object-fit:contain; display:block; margin:auto; box-shadow:0 2px 14px rgba(27,42,74,0.12); }
#reqViewerPlaceholder { position:absolute; inset:0; display:flex; flex-direction:column; align-items:center; justify-content:center; gap:14px; text-align:center; padding:30px; color:var(--grid-muted); background:var(--grid-bg); }
#reqViewerPlaceholder i.req-placeholder-icon { font-size:48px; color:var(--grid-border); }
#reqViewerPlaceholder p { margin:0; font-size:14px; max-width:420px; line-height:1.6; }
@media (max-width: 760px) {
    #reqViewerBody { flex-direction:column; }
    #reqViewerSidebar { width:100%; max-height:38vh; border-right:none; border-bottom:1px solid var(--grid-border); }
    #reqViewerBar { padding:10px 14px; }
}

/* ══════════════════════════════════════════════════════════
   NEW (Delete revision): details shown in the Delete confirmation
   popup and in the "Account Deleted" notification popup.
   ══════════════════════════════════════════════════════════ */
.delete-selected-list { text-align:left; margin:-8px 0 20px; max-height:220px; overflow-y:auto; border:1px solid var(--grid-border); }
.delete-selected-row { display:flex; justify-content:space-between; align-items:center; gap:10px; padding:8px 12px; font-size:13px; border-bottom:1px solid var(--grid-border-soft); }
.delete-selected-row:last-child { border-bottom:none; }
.delete-selected-row strong { color:var(--grid-navy); }
.delete-kind-tag { font-size:10px; font-weight:800; padding:2px 8px; text-transform:uppercase; letter-spacing:0.4px; white-space:nowrap; }
.delete-kind-tag.registered { color:var(--grid-red); background:var(--grid-red-bg); }
.delete-kind-tag.imported { color:#0f6e56; background:#e3f4ee; }
.delete-warning-note { text-align:left; font-size:12px; color:var(--grid-red); background:var(--grid-red-bg); border-left:3px solid var(--grid-red); padding:8px 12px; margin:-8px 0 20px; line-height:1.5; }
.acct-detail-card { text-align:left; border:1px solid var(--grid-border); margin-bottom:12px; }
.acct-detail-card-head { background:var(--grid-bg); padding:8px 12px; font-size:12px; font-weight:700; color:var(--grid-navy); text-transform:uppercase; letter-spacing:0.3px; border-bottom:1px solid var(--grid-border); display:flex; justify-content:space-between; gap:8px; align-items:center; }
.acct-detail-row { display:flex; gap:10px; padding:6px 12px; font-size:12px; border-bottom:1px solid var(--grid-border-soft); }
.acct-detail-row:last-child { border-bottom:none; }
.acct-detail-label { width:120px; flex-shrink:0; color:var(--grid-muted); font-weight:600; }
.acct-detail-value { color:#2d3748; word-break:break-word; }
.delete-failed-block { text-align:left; font-size:12px; color:var(--grid-red); background:var(--grid-red-bg); border-left:3px solid var(--grid-red); padding:8px 12px; margin-bottom:12px; line-height:1.5; }
</style>
<!-- ══════════════════════════════════════════════════════════════════════
     NEW (this adjustment) — COMPANY REQUIREMENTS NOTIFICATION POPUP (styles)
     Same popup as company_validation.php's notification toast (.cv-top-toast,
     final "navy bar" look): square navy bar, slate frame, green icon, the
     company name in bold white, shown at the TOP of the page and fading out by
     itself. pointer-events:none — it is a message, not a control.
     ══════════════════════════════════════════════════════════════════════ -->
<style>
    .cv-top-toast { position: fixed; top: 30px; left: 50%; transform: translateX(-50%); background: #1B2A4A; color: #E3E8F1; border: 1px solid #55668C; border-radius: 0; padding: 14px 20px; box-shadow: 0 8px 24px rgba(27,42,74,0.30); display: flex; align-items: center; gap: 12px; font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif; font-size: 12.5px; line-height: 1.45; z-index: 10020; max-width: 440px; opacity: 0; transition: opacity 0.35s, top 0.3s ease; pointer-events: none; }
    .cv-top-toast.show { opacity: 1; }
    .cv-top-toast i { color: #8FD18F; font-size: 18px; flex-shrink: 0; }
    .cv-top-toast strong { color: #ffffff; font-weight: 700; }
</style>
<!-- ══════════════════════════════════════════════════════════════════════
     UPDATED (this adjustment) — SMOOTH SIDE MENU OPEN / CLOSE + PAGE ADJUSTMENT
     ------------------------------------------------------------------------
     Why the menu list looked jerky before: on closing, every link was
     switched at once to "centered, no padding" while the menu was still
     260px wide, so the icons jumped to the middle and then drifted left as
     the menu shrank; the names vanished / reappeared instantly; and the
     links' padding animated on a different timer (0.2s) from the menu (0.3s).
     Now (on screens wider than 768px — phones keep their slide-in menu as is):
       • The icons NEVER move. In the 80px rail a 30px icon is centred at
         exactly 25px from the left — the same 25px the open menu uses — so the
         links keep one layout in both states and only the width changes.
       • The link names fade + slide in (lightly staggered, top to bottom) once
         the menu has opened far enough, and fade + slide out quickly the
         moment it starts closing — no popping, no text spilling over the page.
       • The admin name / "Administrator" label fade on the same timing.
       • The Logout button stays visible and morphs smoothly (see the
         Logout rules below) — it no longer fades out / blinks while moving.
       • The menu and the page next to it move together on one shared timing
         and ease-in-out curve (0.35s), so the page glides with the menu.
       • Reduced-motion system setting: near-instant, no animation.
     Only the animation changes: open / closed sizes, colours, icons, badges,
     the Logout button's look and every toggle button stay exactly as they were.
     A page that opens with the menu already closed is never animated.
     ══════════════════════════════════════════════════════════════════════ -->
<style>
    #sidebar.sidebar { transition: width 0.35s cubic-bezier(0.4, 0, 0.2, 1), transform 0.35s cubic-bezier(0.4, 0, 0.2, 1), box-shadow 0.35s ease; will-change: width; }
    #sidebar.sidebar a { white-space: nowrap; }
    html body .main-content { transition: margin-left 0.35s cubic-bezier(0.4, 0, 0.2, 1), width 0.35s cubic-bezier(0.4, 0, 0.2, 1); }
    /* only while the menu is moving (added / removed by the script at the end of the page) */
    #sidebar.sidebar.cv-sb-moving { overflow: hidden; }
    @media (min-width: 769px) {
        /* the links keep ONE layout in both states, so the icons stay perfectly still */
        #sidebar.sidebar .sidebar-links a { transition: background-color 0.2s ease, color 0.2s ease; }
        #sidebar.sidebar.collapsed .sidebar-links a { justify-content: flex-start; padding-left: 25px; padding-right: 25px; }
        #sidebar.sidebar.collapsed .sidebar-links a i { margin-right: 15px; }
        /* FIX (this adjustment) — icon "shaking" while the menu opens / closes:
           the icon box is a flex item, and flex items may shrink. In the narrow rail the
           (hidden) link name still takes room, so each icon's 30px box was squeezed down to
           the glyph's own width and the glyph re-centred inside it — a different amount per
           icon, changing on every frame of the width animation. Locking the box at 30px
           keeps every icon on the exact same spot in both states and during the move. */
        #sidebar.sidebar .sidebar-links a i { flex: 0 0 30px; width: 30px; min-width: 30px; }
        #sidebar.sidebar .sidebar-links a .link-text { flex: 0 0 auto; }
        /* link names: fade + slide instead of popping in / out */
        #sidebar.sidebar .sidebar-links a .link-text { display: inline-block; opacity: 1; visibility: visible; transform: translateX(0);
            transition: opacity 0.24s ease, transform 0.3s cubic-bezier(0.4, 0, 0.2, 1), visibility 0s linear 0s; }
        #sidebar.sidebar.collapsed .sidebar-links a .link-text { display: inline-block; opacity: 0; visibility: hidden; transform: translateX(-8px);
            transition: opacity 0.12s ease, transform 0.18s ease, visibility 0s linear 0.12s; transition-delay: 0s, 0s, 0.12s; }
        #sidebar.sidebar:not(.collapsed) .sidebar-links a:nth-child(1) .link-text { transition-delay: 0.10s; }
        #sidebar.sidebar:not(.collapsed) .sidebar-links a:nth-child(2) .link-text { transition-delay: 0.12s; }
        #sidebar.sidebar:not(.collapsed) .sidebar-links a:nth-child(3) .link-text { transition-delay: 0.14s; }
        #sidebar.sidebar:not(.collapsed) .sidebar-links a:nth-child(4) .link-text { transition-delay: 0.16s; }
        #sidebar.sidebar:not(.collapsed) .sidebar-links a:nth-child(5) .link-text { transition-delay: 0.18s; }
        #sidebar.sidebar:not(.collapsed) .sidebar-links a:nth-child(6) .link-text { transition-delay: 0.20s; }
        #sidebar.sidebar:not(.collapsed) .sidebar-links a:nth-child(7) .link-text { transition-delay: 0.22s; }
        #sidebar.sidebar:not(.collapsed) .sidebar-links a:nth-child(8) .link-text { transition-delay: 0.24s; }
        #sidebar.sidebar:not(.collapsed) .sidebar-links a:nth-child(9) .link-text { transition-delay: 0.26s; }
        #sidebar.sidebar:not(.collapsed) .sidebar-links a:nth-child(10) .link-text { transition-delay: 0.28s; }
        /* admin name + role label */
        #sidebar.sidebar .sidebar-header-titles { transition: opacity 0.24s ease 0.14s, width 0.35s cubic-bezier(0.4, 0, 0.2, 1); }
        #sidebar.sidebar.collapsed .sidebar-header-titles { transition: opacity 0.12s ease 0s, width 0.35s cubic-bezier(0.4, 0, 0.2, 1); }
        /* UPDATED (this adjustment) — Logout button: no more blinking. It used to fade out completely while the
           menu moved and pop back afterwards (invisible for about half of every open / close), and its label was
           switched off / on in one step (display:none), so the icon jumped. Now it stays visible the whole time and
           simply morphs: the "Logout" label narrows + fades, the icon glides to the centre, the border fades —
           ending in exactly the same open / closed look as before. */
        #sidebar.sidebar .logout-link a { transition: border-color 0.3s ease, background-color 0.2s ease, color 0.2s ease; }
        #sidebar.sidebar .logout-link a i { flex: 0 0 30px; width: 30px; min-width: 30px; transition: margin-right 0.35s cubic-bezier(0.4, 0, 0.2, 1); }
        #sidebar.sidebar.collapsed .logout-link a i { margin-right: 0; }
        #sidebar.sidebar .logout-link a .link-text { display: inline-block; max-width: 90px; opacity: 1; overflow: hidden; white-space: nowrap; vertical-align: middle;
            transition: max-width 0.35s cubic-bezier(0.4, 0, 0.2, 1), opacity 0.2s ease 0.15s; }
        #sidebar.sidebar.collapsed .logout-link a .link-text { display: inline-block; max-width: 0; opacity: 0;
            transition: max-width 0.35s cubic-bezier(0.4, 0, 0.2, 1), opacity 0.1s ease 0s; }
    }
    @media (prefers-reduced-motion: reduce) {
        #sidebar.sidebar, #sidebar.sidebar .sidebar-header-titles, html body .main-content,
        #sidebar.sidebar .sidebar-links a .link-text, #sidebar.sidebar .logout-link a { transition-duration: 0.01s !important; transition-delay: 0s !important; }
    }
</style>
<!-- NEW (this adjustment): LOGOUT CONFIRMATION POPUP — styles (same square navy look as the
     page's other confirmation dialogs, e.g. system_setting.php's). Used by the script at the end of the page. -->
<style>
    .cv-logout-overlay { position: fixed; inset: 0; z-index: 10050; display: flex; align-items: center; justify-content: center; padding: 20px;
        background: rgba(27, 42, 74, 0.45); opacity: 0; visibility: hidden; transition: opacity 0.2s ease, visibility 0.2s ease; }
    .cv-logout-overlay.show { opacity: 1; visibility: visible; }
    .cv-logout-box { background: #ffffff; width: 420px; max-width: 100%; border-top: 3px solid #1B2A4A; box-shadow: 0 20px 60px rgba(0, 0, 0, 0.25);
        padding: 26px 24px 22px; font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif; transform: translateY(8px); transition: transform 0.2s ease; }
    .cv-logout-overlay.show .cv-logout-box { transform: translateY(0); }
    .cv-logout-box h3 { margin: 0 0 10px; font-size: 16px; color: #1B2A4A; display: flex; align-items: center; gap: 10px; }
    .cv-logout-box h3 i { color: #1B2A4A; }
    .cv-logout-box p { margin: 0 0 22px; font-size: 13.5px; color: #4A5568; line-height: 1.6; }
    .cv-logout-actions { display: flex; justify-content: flex-end; gap: 8px; }
    .cv-logout-btn { display: inline-flex; align-items: center; gap: 8px; border: 1px solid #1B2A4A; cursor: pointer; font-family: inherit; font-size: 12px;
        font-weight: 600; letter-spacing: 0.4px; text-transform: uppercase; padding: 11px 18px; border-radius: 0; background: #1B2A4A; color: #ffffff; transition: opacity 0.2s ease; }
    .cv-logout-btn:hover { opacity: 0.88; }
    .cv-logout-btn:focus-visible { outline: 2px solid #F7C600; outline-offset: 2px; }
    .cv-logout-btn.ghost { background: #ffffff; color: #1B2A4A; border-color: #D5DBE6; }
    @media (prefers-reduced-motion: reduce) { .cv-logout-overlay, .cv-logout-box { transition: none; } }
</style>
<!-- NEW (this adjustment): BUTTON TOOLTIPS — same design as the "Apply Students" tooltip on
     admin_monitoring_dashboard.php (#amdFloatTip): square navy box, small bold uppercase white text,
     soft shadow, arrow pointing at the button. Used by the script at the end of the page. -->
<style>
    /* UPDATED (this adjustment): drawn above every popup / dialog (their backdrops go up to 10050) so a tooltip for a
       button INSIDE a popup (e.g. the Export popup's close button) is no longer hidden behind the popup */
    #cvBtnTip { position: fixed; z-index: 20010; background: #1B2A4A; color: #fff; font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif; font-size: 10.5px; font-weight: 600; line-height: 1.3; text-transform: uppercase; letter-spacing: 0.3px; padding: 6px 10px; border-radius: 0; white-space: nowrap; box-shadow: 0 4px 14px rgba(27,42,74,0.30); pointer-events: none; opacity: 0; transform: translateY(4px); transition: opacity .15s, transform .15s; }
    #cvBtnTip.show { opacity: 1; transform: translateY(0); }
    #cvBtnTip::after { content: ''; position: absolute; top: 100%; left: var(--arrow-x, 50%); transform: translateX(-50%); border: 6px solid transparent; border-top-color: #1B2A4A; }
    #cvBtnTip.below { transform: translateY(-4px); }
    #cvBtnTip.below.show { transform: translateY(0); }
    #cvBtnTip.below::after { top: auto; bottom: 100%; border-top-color: transparent; border-bottom-color: #1B2A4A; }
    @media (prefers-reduced-motion: reduce) { #cvBtnTip { transition: none; } }
</style>
<!-- NEW (this adjustment): CLICKABLE NOTIFICATION POPUPS — styles (used by the script at the end of the page) -->
<style>
    .cv-top-toast[data-cv-go] { pointer-events: auto; cursor: pointer; transition: opacity 0.35s, top 0.3s ease, background-color 0.15s ease; }
    .cv-top-toast[data-cv-go]:hover { background: #24375E; }
    .cv-top-toast[data-cv-go]:focus-visible { outline: 2px solid #F7C600; outline-offset: 2px; }
    .cv-top-toast .cv-toast-go { flex-shrink: 0; margin-left: 6px; color: #F7C600; font-size: 10.5px; font-weight: 700; text-transform: uppercase; letter-spacing: 0.4px; white-space: nowrap; }
    .cv-top-toast .cv-toast-go i { color: inherit; font-size: 9px; margin-left: 3px; }
    /* the item a popup led to is briefly highlighted when it is shown */
    .cv-go-highlight { outline: 2px solid #F7C600 !important; outline-offset: 2px; animation: cvGoFlash 2.6s ease; }
    @keyframes cvGoFlash { 0%, 55% { box-shadow: 0 0 0 5px rgba(247, 198, 0, 0.35); } 100% { box-shadow: 0 0 0 0 rgba(247, 198, 0, 0); } }
</style>
<!-- ══════════════════════════════════════════════════════════════════════
     NEW (this adjustment) — LOADING PAGE: SHOWN DIRECTLY, EVERY TIME
     ------------------------------------------------------------------------
     The loading page sometimes did not appear, because:
       • while the page was still loading, anything above this page's loading
         overlay in the HTML could be painted before the overlay existed;
       • many of the page's actions (saves, deletes, status changes …) sent
         their request without showing it — only some actions called
         showGlobalLoading();
       • reloads / redirects started by the page's own script (after an
         action) did not show it on every page.
     This block (in <head>, so it runs before anything else on the page):
       1. FIRST PAINT — the page opens under a loading screen with the same
          look as this page's loading overlay (spinner + "LOADING"), until the
          real #globalLoadingOverlay is in place; then that one simply takes
          over (it is already visible at that moment), so there is no gap.
       2. ACTIONS — any request that changes something (POST …) and starts
          right after a click / key press / form change shows the loading page
          straight away ("Processing"), for at least 350 ms so it never
          flickers, and hides it when the request is done. Background checks
          (notification polls, look-ups, previews, the dashboard chat, the
          commits that run after an undo window) never show it, and a request
          the page already covers with its own loading page is left to it.
       3. LEAVING BY SCRIPT — a reload / redirect started by the page shows the
          loading page too (released after a few seconds if it was really a
          file download, which never leaves the page).
     Self-contained: the page's own loading logic, labels and design are
     unchanged; fetch / XMLHttpRequest keep working exactly as before.
     ══════════════════════════════════════════════════════════════════════ -->
<style>
    /* the first-paint ring: exactly where the page's own spinner is; its size and look come from the shared ring rule below,
       and it carries on from the previous page's loading page (--cv-ring-delay). CLEAN-UP (audit): two rules merged into one. */
    html.cv-booting::before { content: ''; position: fixed; left: 50%; top: 50%; margin: -47.5px 0 0 -32px; z-index: 20002;
        animation: cvRingSpin 1s steps(12, end) infinite; animation-delay: var(--cv-ring-delay, 0s); }
    html.cv-booting::after { content: 'LOADING'; position: fixed; inset: 0; z-index: 20001; display: flex; align-items: center; justify-content: center;
        padding: 80px 20.7px 0 0; box-sizing: border-box; background: rgba(238, 241, 246, 0.92); color: #1B2A4A;   /* UPDATED (this adjustment): label exactly where the page's own label is */
        font: 700 13px 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif; letter-spacing: 0.6px; }
    /* NEW (this adjustment): ENHANCED LOADING RING — instead of one solid arc sweeping round, 12 rounded segments
       in the site's navy that fade from dark to light around the circle and tick round (like a classic activity
       indicator). Same 64 px footprint and position as before, so nothing else moves. Used by the first-paint
       cover AND by this page's own loading page, so both always look identical. */
    html.cv-booting::before,
    #globalLoadingOverlay .global-loading-spinner {
        width: 64px; height: 64px; border: 0; border-radius: 50%; box-sizing: border-box;
        background: conic-gradient(from 0deg, rgba(27,42,74,0.12) 0deg, rgba(27,42,74,0.35) 120deg, rgba(27,42,74,0.7) 240deg, #1B2A4A 330deg, #1B2A4A 360deg);
        -webkit-mask: radial-gradient(farthest-side, transparent calc(100% - 9px), #000 calc(100% - 8px)),
                      repeating-conic-gradient(from 5deg, #000 0deg 20deg, transparent 20deg 30deg);
        -webkit-mask-composite: source-in;
                mask: radial-gradient(farthest-side, transparent calc(100% - 9px), #000 calc(100% - 8px)),
                      repeating-conic-gradient(from 5deg, #000 0deg 20deg, transparent 20deg 30deg);
                mask-composite: intersect;
        will-change: transform;   /* FIX: the ring keeps turning on the compositor while this large page is busy loading (no pause) — same as administrator.php */
    }
    #globalLoadingOverlay .global-loading-spinner { animation: cvRingSpin 1s steps(12, end) infinite; }
    @keyframes cvRingSpin { to { transform: rotate(360deg); } }
    /* NEW (this adjustment): the animated dots after "LOADING", like the page's own loading page, so nothing changes
       when the page's loading page takes over */
    /* UPDATED (this adjustment): the three dots fade one after another exactly like the page's own dots
       (same 1.2 s cycle, 0.2 s apart), so they simply carry on when the page's loading page takes over */
    html.cv-booting body::before { content: '.'; position: fixed; left: calc(50% + 29.75px); top: calc(50% + 32.5px); z-index: 20003;
        font: 700 13px/15px 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif; letter-spacing: 0.6px; color: rgba(27,42,74,0);
        animation: cvBootDots 1.2s linear infinite; animation-delay: var(--cv-dots-delay, 0s); pointer-events: none; }
    @keyframes cvBootDots {
        0.0% { color: rgba(27,42,74,0.0); text-shadow: 4.23px 0 rgba(27,42,74,0.075), 8.47px 0 rgba(27,42,74,0.424); }
        2.5% { color: rgba(27,42,74,0.0); text-shadow: 4.23px 0 rgba(27,42,74,0.052), 8.47px 0 rgba(27,42,74,0.342); }
        5.0% { color: rgba(27,42,74,0.0); text-shadow: 4.23px 0 rgba(27,42,74,0.034), 8.47px 0 rgba(27,42,74,0.273); }
        7.5% { color: rgba(27,42,74,0.0); text-shadow: 4.23px 0 rgba(27,42,74,0.02), 8.47px 0 rgba(27,42,74,0.215); }
        10.0% { color: rgba(27,42,74,0.0); text-shadow: 4.23px 0 rgba(27,42,74,0.01), 8.47px 0 rgba(27,42,74,0.166); }
        12.5% { color: rgba(27,42,74,0.0); text-shadow: 4.23px 0 rgba(27,42,74,0.004), 8.47px 0 rgba(27,42,74,0.126); }
        15.0% { color: rgba(27,42,74,0.0); text-shadow: 4.23px 0 rgba(27,42,74,0.001), 8.47px 0 rgba(27,42,74,0.094); }
        17.5% { color: rgba(27,42,74,0.0); text-shadow: 4.23px 0 rgba(27,42,74,0.0), 8.47px 0 rgba(27,42,74,0.067); }
        20.0% { color: rgba(27,42,74,0.0); text-shadow: 4.23px 0 rgba(27,42,74,0.0), 8.47px 0 rgba(27,42,74,0.046); }
        22.5% { color: rgba(27,42,74,0.071); text-shadow: 4.23px 0 rgba(27,42,74,0.0), 8.47px 0 rgba(27,42,74,0.029); }
        25.0% { color: rgba(27,42,74,0.221); text-shadow: 4.23px 0 rgba(27,42,74,0.0), 8.47px 0 rgba(27,42,74,0.017); }
        27.5% { color: rgba(27,42,74,0.409); text-shadow: 4.23px 0 rgba(27,42,74,0.0), 8.47px 0 rgba(27,42,74,0.008); }
        30.0% { color: rgba(27,42,74,0.576); text-shadow: 4.23px 0 rgba(27,42,74,0.0), 8.47px 0 rgba(27,42,74,0.002); }
        32.5% { color: rgba(27,42,74,0.706); text-shadow: 4.23px 0 rgba(27,42,74,0.0), 8.47px 0 rgba(27,42,74,0.0); }
        35.0% { color: rgba(27,42,74,0.802); text-shadow: 4.23px 0 rgba(27,42,74,0.0), 8.47px 0 rgba(27,42,74,0.0); }
        37.5% { color: rgba(27,42,74,0.874); text-shadow: 4.23px 0 rgba(27,42,74,0.015), 8.47px 0 rgba(27,42,74,0.0); }
        40.0% { color: rgba(27,42,74,0.925); text-shadow: 4.23px 0 rgba(27,42,74,0.113), 8.47px 0 rgba(27,42,74,0.0); }
        42.5% { color: rgba(27,42,74,0.96); text-shadow: 4.23px 0 rgba(27,42,74,0.283), 8.47px 0 rgba(27,42,74,0.0); }
        45.0% { color: rgba(27,42,74,0.983); text-shadow: 4.23px 0 rgba(27,42,74,0.468), 8.47px 0 rgba(27,42,74,0.0); }
        47.5% { color: rgba(27,42,74,0.996); text-shadow: 4.23px 0 rgba(27,42,74,0.623), 8.47px 0 rgba(27,42,74,0.0); }
        50.0% { color: rgba(27,42,74,1.0); text-shadow: 4.23px 0 rgba(27,42,74,0.741), 8.47px 0 rgba(27,42,74,0.0); }
        52.5% { color: rgba(27,42,74,0.967); text-shadow: 4.23px 0 rgba(27,42,74,0.829), 8.47px 0 rgba(27,42,74,0.0); }
        55.0% { color: rgba(27,42,74,0.905); text-shadow: 4.23px 0 rgba(27,42,74,0.893), 8.47px 0 rgba(27,42,74,0.038); }
        57.5% { color: rgba(27,42,74,0.815); text-shadow: 4.23px 0 rgba(27,42,74,0.938), 8.47px 0 rgba(27,42,74,0.163); }
        60.0% { color: rgba(27,42,74,0.705); text-shadow: 4.23px 0 rgba(27,42,74,0.969), 8.47px 0 rgba(27,42,74,0.346); }
        62.5% { color: rgba(27,42,74,0.591); text-shadow: 4.23px 0 rgba(27,42,74,0.989), 8.47px 0 rgba(27,42,74,0.524); }
        65.0% { color: rgba(27,42,74,0.487); text-shadow: 4.23px 0 rgba(27,42,74,0.998), 8.47px 0 rgba(27,42,74,0.666); }
        67.5% { color: rgba(27,42,74,0.395); text-shadow: 4.23px 0 rgba(27,42,74,0.992), 8.47px 0 rgba(27,42,74,0.773); }
        70.0% { color: rgba(27,42,74,0.317); text-shadow: 4.23px 0 rgba(27,42,74,0.95), 8.47px 0 rgba(27,42,74,0.852); }
        72.5% { color: rgba(27,42,74,0.252); text-shadow: 4.23px 0 rgba(27,42,74,0.878), 8.47px 0 rgba(27,42,74,0.91); }
        75.0% { color: rgba(27,42,74,0.198); text-shadow: 4.23px 0 rgba(27,42,74,0.779), 8.47px 0 rgba(27,42,74,0.95); }
        77.5% { color: rgba(27,42,74,0.152); text-shadow: 4.23px 0 rgba(27,42,74,0.667), 8.47px 0 rgba(27,42,74,0.977); }
        80.0% { color: rgba(27,42,74,0.115); text-shadow: 4.23px 0 rgba(27,42,74,0.555), 8.47px 0 rgba(27,42,74,0.993); }
        82.5% { color: rgba(27,42,74,0.084); text-shadow: 4.23px 0 rgba(27,42,74,0.455), 8.47px 0 rgba(27,42,74,1.0); }
        85.0% { color: rgba(27,42,74,0.059); text-shadow: 4.23px 0 rgba(27,42,74,0.368), 8.47px 0 rgba(27,42,74,0.981); }
        87.5% { color: rgba(27,42,74,0.04); text-shadow: 4.23px 0 rgba(27,42,74,0.294), 8.47px 0 rgba(27,42,74,0.929); }
        90.0% { color: rgba(27,42,74,0.024); text-shadow: 4.23px 0 rgba(27,42,74,0.233), 8.47px 0 rgba(27,42,74,0.848); }
        92.5% { color: rgba(27,42,74,0.013); text-shadow: 4.23px 0 rgba(27,42,74,0.182), 8.47px 0 rgba(27,42,74,0.743); }
        95.0% { color: rgba(27,42,74,0.006); text-shadow: 4.23px 0 rgba(27,42,74,0.139), 8.47px 0 rgba(27,42,74,0.629); }
        97.5% { color: rgba(27,42,74,0.001); text-shadow: 4.23px 0 rgba(27,42,74,0.104), 8.47px 0 rgba(27,42,74,0.52); }
        100.0% { color: rgba(27,42,74,0.0); text-shadow: 4.23px 0 rgba(27,42,74,0.075), 8.47px 0 rgba(27,42,74,0.424); }
    }
</style>
<script>
(function () {
    'use strict';
    if (window._cvBusyReady) return;
    window._cvBusyReady = true;
    var root = document.documentElement;

    // ── 1) first paint: covered until this page's own loading overlay exists ──
    root.classList.add('cv-booting');
    function releaseBoot() { root.classList.remove('cv-booting'); }
    /* NEW (this adjustment): the loading animation no longer starts over midway. When the page's own loading page
       takes over from this cover, its spinner continues from the same angle, its dots continue in the same rhythm,
       and its pop-in is not replayed (it is already on screen). Only for this one hand-over, and only if the cover
       was actually painted; any later showing of the loading page (e.g. "Saving") animates exactly as before. */
    var cvBootStart = (window.performance && performance.now) ? performance.now() : Date.now();
    var cvCoverPainted = false;
    /* NEW (this adjustment): ONE loading page from the side-menu click / refresh until the new page is ready.
       The page being left saves the moment its loading page appeared (see "pagehide" below); this page picks it
       up and simply carries on from there — same ring position, same dots, no second pop-in, nothing drawn twice.
       Used once, only if recent (15 s); a first visit (nothing saved) behaves as before. */
    var CV_LOADER_KEY = 'cvLoaderEpoch', cvCarriedOver = false;
    var CV_PHASE_KEY = 'cvLoaderPhase', cvRingAt0 = null, cvDotsAt0 = null, cvPhaseReadAt = 0;   // NEW (loader sync fix)
    try {
        var cvEpoch = parseInt(sessionStorage.getItem(CV_LOADER_KEY) || '', 10);
        sessionStorage.removeItem(CV_LOADER_KEY);
        var cvSince = cvEpoch ? Date.now() - cvEpoch : -1;
        if (cvSince >= 0 && cvSince < 15000) {
            cvCarriedOver = true; cvCoverPainted = true;
            cvBootStart = cvBootStart - cvSince;
            root.style.setProperty('--cv-ring-delay', (-((cvSince / 1000) % 1)).toFixed(3) + 's');
            root.style.setProperty('--cv-dots-delay', (-((cvSince / 1000) % 1.2)).toFixed(3) + 's');
            /* NEW (loader sync fix): the previous page also handed over where its ring and dots REALLY were in their
               turn (read from the running animations — see "pagehide" below). The old way assumed the ring started
               turning the moment the loading page was shown, which is only true for the first loading page after a
               page opens; a loading page shown later (a link click) had its ring at any angle, so the next page
               continued from the wrong one — a visible jump. With the real positions it continues exactly. */
            try {
                var cvPh = JSON.parse(sessionStorage.getItem(CV_PHASE_KEY) || 'null');
                if (cvPh && typeof cvPh.ring === 'number' && typeof cvPh.dots === 'number' && Date.now() - cvPh.t >= 0 && Date.now() - cvPh.t < 15000) {
                    var cvGap = Date.now() - cvPh.t;
                    cvRingAt0 = (cvPh.ring + cvGap) / 1000; cvDotsAt0 = (cvPh.dots + cvGap) / 1000;
                    cvPhaseReadAt = (window.performance && performance.now) ? performance.now() : Date.now();
                    root.style.setProperty('--cv-ring-delay', (-(cvRingAt0 % 1)).toFixed(3) + 's');
                    root.style.setProperty('--cv-dots-delay', (-(cvDotsAt0 % 1.2)).toFixed(3) + 's');
                }
            } catch (e) {}
        }
        sessionStorage.removeItem(CV_PHASE_KEY);
    } catch (e) {}
    function cvLoaderStartedAt() { return Date.now() - (((window.performance && performance.now) ? performance.now() : Date.now()) - cvBootStart); }
    if (window.requestAnimationFrame) requestAnimationFrame(function () { requestAnimationFrame(function () { cvCoverPainted = root.classList.contains('cv-booting'); }); });
    function continueCoverAnimation(ov) {
        try {
            if (!cvCoverPainted || !ov || ov.classList.contains('hidden')) return;
            var now = (window.performance && performance.now) ? performance.now() : Date.now();
            var elapsed = (now - cvBootStart) / 1000;
            var elapsedRing = (cvRingAt0 !== null) ? cvRingAt0 + (now - cvPhaseReadAt) / 1000 : elapsed;   // NEW (loader sync fix): the real position, when handed over
            var elapsedDots = (cvDotsAt0 !== null) ? cvDotsAt0 + (now - cvPhaseReadAt) / 1000 : elapsed;
            var spinner = ov.querySelector('.global-loading-spinner');
            if (spinner) spinner.style.animationDelay = (-(elapsedRing % 1)).toFixed(3) + 's';   // UPDATED (this adjustment): the ring's 1 s turn
            var dots = ov.querySelectorAll('.global-loading-dots span');
            for (var i = 0; i < dots.length; i++) dots[i].style.animationDelay = (-((elapsedDots - i * 0.2) % 1.2 + 1.2) % 1.2).toFixed(3) + 's';
            var box = ov.querySelector('.global-loading-box');
            if (box) {
                box.style.animation = 'none';   // no second pop-in
                var restore = new MutationObserver(function () {   // later showings get their pop-in back, as before
                    if (ov.classList.contains('hidden')) {
                        restore.disconnect();
                        setTimeout(function () { box.style.animation = ''; if (spinner) spinner.style.animationDelay = ''; for (var j = 0; j < dots.length; j++) dots[j].style.animationDelay = ''; }, 400);
                    }
                });
                restore.observe(ov, { attributes: true, attributeFilter: ['class'] });
            }
        } catch (e) { /* never affects the page */ }
    }
    // UPDATED (this adjustment): the cover gives way the instant the page's loading page is in the page (before the
    // next frame is drawn), so the two are never drawn at the same time
    var cvHandedOver = false;
    function cvHandOver(ovEl) {
        if (cvHandedOver) return;
        cvHandedOver = true;
        continueCoverAnimation(ovEl);
        releaseBoot();
    }
    if (window.MutationObserver) {
        var cvOvWatch = new MutationObserver(function () {
            var o = document.getElementById('globalLoadingOverlay');
            if (o) { cvOvWatch.disconnect(); cvHandOver(o); }
        });
        cvOvWatch.observe(root, { childList: true, subtree: true });
        document.addEventListener('DOMContentLoaded', function () { cvOvWatch.disconnect(); });
    }
    (function waitForOverlay() {
        var ovEl = document.getElementById('globalLoadingOverlay');
        if (ovEl) { cvHandOver(ovEl); return; }
        if (document.readyState !== 'loading') { releaseBoot(); return; }   // page without an overlay: never keep it covered
        setTimeout(waitForOverlay, 16);
    })();
    document.addEventListener('DOMContentLoaded', function () { setTimeout(releaseBoot, 0); });
    window.addEventListener('pageshow', function (e) { if (e.persisted) releaseBoot(); });

    /* NEW (this adjustment): remember when this page's loading page appeared, and hand that moment to the next
       page when this one is left (side-menu link, refresh, redirect) while it is showing — so the next page
       carries on the same loading page instead of starting a second one. Downloads never leave the page, so
       they never hand anything over. */
    var cvShownSince = null;
    function cvWatchOverlay() {
        var ov = document.getElementById('globalLoadingOverlay');
        if (!ov) return;
        var mark = function () {
            var shown = !ov.classList.contains('hidden');
            if (shown && cvShownSince === null) cvShownSince = Date.now();
            if (!shown) cvShownSince = null;
        };
        if (!ov.classList.contains('hidden')) cvShownSince = cvLoaderStartedAt();   // the page's first loading page
        new MutationObserver(mark).observe(ov, { attributes: true, attributeFilter: ['class'] });
    }
    if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', cvWatchOverlay); else cvWatchOverlay();
    // NEW (loader sync fix): milliseconds into the current turn of an element's running CSS animation (null if unknown)
    function cvAnimPhase(el, period) {
        try {
            if (!el || !el.getAnimations) return null;
            var list = el.getAnimations();
            for (var i = 0; i < list.length; i++) {
                var a = list[i], ct = a.currentTime;
                if (typeof ct !== 'number' || !a.effect || !a.effect.getComputedTiming) continue;
                var delay = a.effect.getComputedTiming().delay || 0;
                return (((ct - delay) % period) + period) % period;
            }
        } catch (e) {}
        return null;
    }
    window.addEventListener('pagehide', function () {
        try {
            var ov = document.getElementById('globalLoadingOverlay');
            if (ov && !ov.classList.contains('hidden') && !ov.classList.contains('success-state')) {
                sessionStorage.setItem(CV_LOADER_KEY, String(cvShownSince !== null ? cvShownSince : Date.now()));
                // NEW (loader sync fix): where the ring and the dots really are in their turn right now
                var ringMs = cvAnimPhase(ov.querySelector('.global-loading-spinner'), 1000);
                var dotsMs = cvAnimPhase(ov.querySelector('.global-loading-dots span'), 1200);
                if (ringMs !== null && dotsMs !== null) sessionStorage.setItem(CV_PHASE_KEY, JSON.stringify({ t: Date.now(), ring: ringMs, dots: dotsMs }));
            }
        } catch (e) {}
    });

    // ── shared: this page's loading overlay ──
    var LABEL = 'Processing', MIN_MS = 350, SAFETY_MS = 30000;
    var pending = 0, shownAt = 0, weShowed = false, hideTimer = null, safetyTimer = null;
    function overlay() { return document.getElementById('globalLoadingOverlay'); }
    function label() { return document.getElementById('globalLoadingLabel'); }
    function showBusy() {
        var ov = overlay(); if (!ov) return;
        clearTimeout(hideTimer);
        if (!weShowed) {
            if (!ov.classList.contains('hidden')) return;          // the page is already showing it — leave it to the page
            weShowed = true; shownAt = Date.now();
            var l = label(); if (l) l.textContent = LABEL;
            ov.classList.remove('hidden');
        }
        clearTimeout(safetyTimer);
        safetyTimer = setTimeout(function () { pending = 0; hideBusy(true); }, SAFETY_MS);
    }
    function hideBusy(force) {
        if (!weShowed) return;
        if (!force && pending > 0) return;
        var wait = Math.max(0, MIN_MS - (Date.now() - shownAt));
        clearTimeout(hideTimer);
        hideTimer = setTimeout(function () {
            if (pending > 0 && !force) return;
            weShowed = false; clearTimeout(safetyTimer);
            var ov = overlay(), l = label();
            if (l && l.textContent !== LABEL) return;                // the page took the loading page over meanwhile — it hides it itself
            if (ov) ov.classList.add('hidden');
            if (l) l.textContent = 'Loading';
        }, wait);
    }

    // ── 2) which requests count as "doing something" ──
    var lastGesture = 0;
    ['pointerdown', 'click', 'submit', 'change'].forEach(function (t) { document.addEventListener(t, function () { lastGesture = Date.now(); }, true); });
    document.addEventListener('keydown', function (e) { if (e.key === 'Enter' || e.key === ' ') lastGesture = Date.now(); }, true);
    var QUIET = /^(ajax_fetch|ajax_poll|ajax_get|ajax_check|check_|detect_|amd_search|amd_course|amd_signatories|load_messages|poll|ajax_endorsement_preview|ajax_endorsement_defaults|ajax_moa_mark_notification_viewed|ajax_flush_emails|ajax_moa_flush_emails|ajax_commit_denied|ajax_confirm_send|ajax_moa_commit|cv_sidebar|sidebar_counts|backup_list)/;
    function keysOf(body) {
        var keys = [];
        try {
            if (!body) return keys;
            if (typeof FormData !== 'undefined' && body instanceof FormData) { body.forEach(function (v, k) { keys.push(k); }); return keys; }
            if (typeof URLSearchParams !== 'undefined' && body instanceof URLSearchParams) { body.forEach(function (v, k) { keys.push(k); }); return keys; }
            if (typeof body === 'string' && body.indexOf('=') !== -1 && body.charAt(0) !== '{') { body.split('&').forEach(function (p) { keys.push(decodeURIComponent(p.split('=')[0] || '')); }); return keys; }
        } catch (e) {}
        return keys;
    }
    function isAction(method, body) {
        if (String(method || 'GET').toUpperCase() === 'GET' || String(method).toUpperCase() === 'HEAD') return false;
        if (Date.now() - lastGesture > 1500) return false;                          // not started by the admin → background
        var keys = keysOf(body);
        if (keys.some(function (k) { return QUIET.test(k); })) return false;
        if (keys.indexOf('mode') !== -1 && keys.indexOf('company_id') !== -1 && keys.indexOf('amd_apply_students') === -1) return false;   // dashboard chat
        return true;
    }
    function track(promiseLike) {
        pending++; showBusy();
        var done = function () { pending = Math.max(0, pending - 1); hideBusy(false); };
        return promiseLike.then(function (r) { done(); return r; }, function (e) { done(); throw e; });
    }

    // fetch
    if (window.fetch) {
        var origFetch = window.fetch;
        window.fetch = function (input, init) {
            var p = origFetch.apply(this, arguments);
            try {
                var method = (init && init.method) || (input && typeof input === 'object' && input.method) || 'GET';
                if (isAction(method, init && init.body)) return track(p);
            } catch (e) {}
            return p;
        };
    }
    // XMLHttpRequest
    if (window.XMLHttpRequest) {
        var XP = window.XMLHttpRequest.prototype, origOpen = XP.open, origSend = XP.send;
        XP.open = function (method) { this._cvMethod = method; return origOpen.apply(this, arguments); };
        XP.send = function (body) {
            try {
                if (isAction(this._cvMethod, body)) {
                    var xhr = this, finished = false;
                    pending++; showBusy();
                    var end = function () { if (finished) return; finished = true; pending = Math.max(0, pending - 1); hideBusy(false); };
                    xhr.addEventListener('loadend', end);
                }
            } catch (e) {}
            return origSend.apply(this, arguments);
        };
    }

    /* NEW (loader sync fix): 'gl-instant' (no fade-in) is only for the moment of leaving; once the overlay is hidden
       again (navigation cancelled / a download) it is dropped, so later showings fade in as before — same as administrator.php. */
    document.addEventListener('DOMContentLoaded', function () {
        try {
            var ovw = overlay();
            if (!ovw || !window.MutationObserver) return;
            new MutationObserver(function () {
                if (ovw.classList.contains('hidden') && ovw.classList.contains('gl-instant')) ovw.classList.remove('gl-instant');
            }).observe(ovw, { attributes: true, attributeFilter: ['class'] });
        } catch (e) { /* never affects the page */ }
    });
    /* NEW (loader sync fix — same behaviour as administrator.php's "navigating" flag): once this page is on its way
       to another page (link click, form submit, reload), nothing may hide the loading page again until the next page
       has taken over. Before this, this page's own load handler / 4-second safety timer (and the "minimum time"
       logic) could hide it in the middle of a navigation started in the first seconds after opening the page, so the
       loading page vanished for a moment and then came back with the next page ("pauses, then continues"). A hide
       that happens while navigating is undone before the browser paints it. The flag releases itself after 7.5 s
       (navigation cancelled / a download), a moment before the existing 8 s release below. */
    var navActive = false, navTimer = null, navObs = null;
    function navAttach() {
        if (navObs || !window.MutationObserver) return;
        var ovn = overlay(); if (!ovn) return;
        navObs = new MutationObserver(function () {
            if (navActive && ovn.classList.contains('hidden')) { ovn.classList.add('gl-instant'); ovn.classList.remove('hidden'); }
        });
        navObs.observe(ovn, { attributes: true, attributeFilter: ['class'] });
    }
    function navStart() { try { navActive = true; navAttach(); clearTimeout(navTimer); navTimer = setTimeout(navEnd, 7500); } catch (e) {} }
    function navEnd() { navActive = false; clearTimeout(navTimer); }
    window.cvNavGate = { start: navStart, end: navEnd, active: function () { return navActive; } };
    window.addEventListener('pageshow', function (e) { if (e.persisted) navEnd(); });

    // ── 3) leaving by script (reload / redirect after an action) ──
    var lastFileClick = 0;
    var FILE_RE = /[?&][^=&]*(export|download|print|stream|pdf|preview|blob|file|csv)[^=&]*=|\.(pdf|xlsx?|csv|docx?|zip)(\?|$)/i;
    document.addEventListener('click', function (e) {
        var a = e.target && e.target.closest ? e.target.closest('a[href], button, input[type="submit"]') : null;
        if (!a) return;
        var href = a.getAttribute && (a.getAttribute('href') || a.getAttribute('formaction') || '');
        var form = a.form || (a.closest && a.closest('form'));
        var txt = (a.textContent || a.value || '').toLowerCase();
        if (a.hasAttribute && a.hasAttribute('download') || (href && FILE_RE.test(href)) || /export|download|print/.test(txt) ||
            (form && form.querySelector && form.querySelector('[name*="export"],[name*="download"]'))) lastFileClick = Date.now();
    }, true);
    window.addEventListener('beforeunload', function () {
        if (Date.now() - lastFileClick < 2000) return;                             // most likely a file download
        navStart();                                                                 // NEW (loader sync fix): leaving — keep the loading page up
        var ov = overlay(); if (!ov || !ov.classList.contains('hidden')) return;
        var l = label(); if (l) l.textContent = 'Loading';
        ov.classList.add('gl-instant');   // NEW (loader sync fix): appears on the very next paint, like administrator.php
        ov.classList.remove('hidden');
        setTimeout(function () { if (!document.hidden) { ov.classList.add('hidden'); } }, 8000);   // still here → it was a download
    });
})();
</script>
<!-- NEW (this adjustment): close (×) button on the "Export To Excel" choice popup — same look as this page's other close buttons -->
<style>
    #exportChoiceModal .modal-box { position: relative; }
    .export-choice-close { position: absolute; top: 10px; right: 14px; background: none; border: none; padding: 4px; font-size: 24px; line-height: 1;
        cursor: pointer; color: var(--grid-muted, #5A6272); transition: color 0.2s; font-family: inherit; }
    .export-choice-close:hover { color: var(--grid-navy, #1B2A4A); }
    .export-choice-close:focus-visible { outline: 2px solid var(--grid-navy, #1B2A4A); outline-offset: 2px; }
</style>
<!-- NEW (this adjustment): "Choose What To Import" — same design as the manual Add Student form: wide box that always
     fits the screen, compact header with a close (×) button, the two lists side by side, buttons pinned bottom-right. -->
<style>
    #importClassificationModal .modal-box { width: 860px !important; max-width: 94%; max-height: calc(100vh - 40px); max-height: calc(100dvh - 40px);
        padding: 16px 24px 0 24px; box-sizing: border-box; display: flex; flex-direction: column; overflow: hidden; text-align: left; }
    #importClassificationModal .import-picker-head { display: flex; justify-content: space-between; align-items: center; gap: 12px;
        border-bottom: 1px solid var(--grid-border); margin-bottom: 10px; padding-bottom: 8px; }
    #importClassificationModal .import-picker-head h3 { margin: 0; color: var(--grid-navy); font-size: 15px; text-transform: uppercase; letter-spacing: 0.4px; }
    #importClassificationModal .import-picker-close { background: none; border: none; font-size: 24px; line-height: 1; cursor: pointer; color: var(--grid-muted); transition: color 0.2s; padding: 0 2px; }
    #importClassificationModal .import-picker-close:hover { color: var(--grid-navy); }
    #importClassificationModal .modal-message { font-size: 12.5px; line-height: 1.5; margin: 0 0 4px; text-align: left; }
    #importClassificationModal .import-picker-grid { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); column-gap: 18px; align-items: start; min-height: 0; flex: 1 1 auto; overflow: hidden; }
    #importClassificationModal .import-classification-group { margin-top: 8px; min-width: 0; }
    #importClassificationModal .import-classification-options { gap: 6px; max-height: calc(100vh - 300px); max-height: calc(100dvh - 300px); overflow-y: auto; padding-right: 2px; }
    #importClassificationModal .import-classification-option { padding: 6px 10px; font-size: 12.5px; }
    #importClassificationModal .modal-actions { justify-content: flex-end; position: sticky; bottom: 0; background: #fff; margin-top: 10px !important;
        padding: 10px 0 12px 0; border-top: 1px solid var(--grid-border); z-index: 2; }
    @media (max-width: 760px) {
        #importClassificationModal .modal-box { padding: 14px 14px 0 14px; overflow-y: auto; }
        #importClassificationModal .import-picker-grid { grid-template-columns: 1fr; overflow: visible; }
        #importClassificationModal .import-classification-options { max-height: none; }
    }
</style>
<!-- NEW (this adjustment): button tooltips can now hold a short description, so they may wrap onto a second line -->
<style>
    #cvBtnTip { white-space: normal; max-width: 300px; text-align: center; }
</style>
</head>
<body>

<!-- ══════════════════════════════════════════════════════════
     NEW: Global loading/processing popup. Visible by default (so it
     covers the page while assets are still loading), then hidden by
     JS once the window finishes loading. Also reused (shown/hidden)
     around every AJAX action further down (table refresh, add/edit/
     delete company, admin MOA upload, settings save, import, export,
     create account) so the admin always sees a clear "processing"
     indicator that disappears automatically the moment the action
     completes.
     ══════════════════════════════════════════════════════════ -->
<div id="globalLoadingOverlay">
    <div class="global-loading-box">
        <div class="global-loading-spinner"></div>
        <div class="global-loading-text">
            <span id="globalLoadingLabel">Loading</span>
            <span class="global-loading-dots"><span>.</span><span>.</span><span>.</span></span>
        </div>
        <!-- NEW (Edit/Delete success loading page — same as admin_student_list.php): check icon + notification of the action -->
        <div class="global-loading-success" id="globalLoadingSuccess" role="status" aria-live="polite">
            <div class="gls-check"><i class="fas fa-check"></i></div>
            <div class="gls-title" id="globalLoadingSuccessTitle">Success</div>
            <div class="gls-message" id="globalLoadingSuccessMsg"></div>
            <div class="gls-sub"><i class="fas fa-sync-alt fa-spin"></i> Refreshing the list...</div>
        </div>
    </div>
</div>
<!-- ══════════════════════════════════════════════════════════════════════
     NEW (this adjustment) — LOADING PAGE: ALWAYS SHOWS WHEN A PAGE LOADS
     ------------------------------------------------------------------------
     The loading page sometimes did not appear at all:
       • on a fast load the page hid it the instant it finished loading —
         before the browser had even painted it, so it was never seen;
       • leaving this page did not show it at all, so while the next page was
         still being prepared there was nothing on screen to say it was loading.
     This guard (placed right after the loading page, so it runs as early as
     possible) makes it dependable without replacing any existing code:
       1. It is visible from the start of every page load, and stays up for
          at least a short moment (450 ms) so it is always actually seen. If
          the page's own code hides it sooner, the hide is simply held back
          until that moment has passed (unless the page shows it again).
       2. Leaving the page with a same-tab link or a normal form submit shows it
          straight away. File downloads, exports, previews, new-tab links and
          AJAX forms are skipped, with a 10 s safety in case a page never changes.
       3. Back / Forward (page restored from the browser cache) clears anything
          left over from leaving, so it is never stuck on screen.
     Uses the page's existing #globalLoadingOverlay / #globalLoadingLabel,
     so the design is unchanged. Everything it does is limited to the first
     moments of the page load and to leaving the page.
     ══════════════════════════════════════════════════════════════════════ -->
<script>
(function () {
    'use strict';
    var ov = document.getElementById('globalLoadingOverlay');
    if (!ov || window._cvPageLoaderGuard) return;
    window._cvPageLoaderGuard = true;

    var MIN_VISIBLE_MS = 450;         // shortest time the loading page is shown on a page load
    var OWN_LOAD_HIDE  = true;    // does this page already hide the loading page itself once loaded?
    var NAV_ON_CLICK   = true;    // show it when leaving the page via a link / form (if the page doesn't already)
    var NAV_STUCK_MS   = 10000;       // safety: if the page is still here after this, put things back
    var label = document.getElementById('globalLoadingLabel');
    var start = Date.now();
    var initialPhase = true, wantHide = false;

    function setHidden(h) { if (h) ov.classList.add('hidden'); else ov.classList.remove('hidden'); if (mo) mo.takeRecords(); }

    // 1) visible from the very start of the page load
    var mo = null;
    if (ov.classList.contains('hidden')) ov.classList.remove('hidden');
    if (window.MutationObserver) {
        mo = new MutationObserver(function () {
            if (!initialPhase) return;
            if (ov.classList.contains('hidden')) {
                if (Date.now() - start < MIN_VISIBLE_MS) { wantHide = true; setHidden(false); }
            } else {
                wantHide = false;   // the page showed it again (e.g. an action) — respect that
            }
        });
        mo.observe(ov, { attributes: true, attributeFilter: ['class'] });
    }
    function endInitialPhase() {
        if (!initialPhase) return;
        initialPhase = false;
        if (mo) mo.takeRecords();
        if (wantHide || !OWN_LOAD_HIDE) setHidden(true);
        if (mo) { mo.disconnect(); mo = null; }
    }
    function afterLoad() { setTimeout(endInitialPhase, Math.max(0, MIN_VISIBLE_MS - (Date.now() - start))); }
    if (document.readyState === 'complete') afterLoad(); else window.addEventListener('load', afterLoad);
    if (!OWN_LOAD_HIDE) setTimeout(endInitialPhase, 15000);   // safety if 'load' is held up by a slow asset

    // 2) leaving the page: show it the moment a same-tab link is clicked or a form is submitted
    var navShown = false, navWasHidden = false, navPrevLabel = null, navTimer = null;
    function navShow() {
        if (navShown) return;
        navShown = true;
        if (window.cvNavGate) window.cvNavGate.start();   // NEW (loader sync fix): nothing may hide the loading page while leaving
        navWasHidden = ov.classList.contains('hidden');
        if (navWasHidden) {
            if (label) { navPrevLabel = label.textContent; label.textContent = 'Loading'; }
            ov.classList.add('gl-instant');   // NEW (loader sync fix): no fade-in while leaving
            setHidden(false);
        }
        clearTimeout(navTimer);
        navTimer = setTimeout(navReset, NAV_STUCK_MS);
    }
    function navReset() {
        clearTimeout(navTimer);
        if (!navShown) return;
        navShown = false;
        if (window.cvNavGate) window.cvNavGate.end();
        if (navWasHidden) {
            setHidden(true);
            if (label && navPrevLabel !== null) label.textContent = navPrevLabel;
        }
    }
    // file / export / preview URLs download or open a file instead of leaving the page — skip them
    var FILE_RE  = /\.(pdf|xlsx?|csv|docx?|pptx?|zip|png|jpe?g|gif|webp|txt)$/i;
    var PARAM_RE = /[?&][^=&]*(export|download|print|stream|pdf|preview|blob|file)[^=&]*=/i;
    function isPageUrl(u) {
        if (u.origin !== window.location.origin || !/^https?:$/.test(u.protocol)) return false;
        if (FILE_RE.test(u.pathname) || PARAM_RE.test(u.search)) return false;
        return true;
    }
    if (NAV_ON_CLICK) {
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
            // decided after every other click handler has run, so links the page handles itself are left alone
            setTimeout(function () { if (!e.defaultPrevented) navShow(); }, 0);
        });
        document.addEventListener('submit', function (e) {
            var f = e.target;
            if (!f || f.tagName !== 'FORM') return;
            var t = (f.getAttribute('target') || '').toLowerCase();
            if (t && t !== '_self') return;
            var u; try { u = new URL(f.getAttribute('action') || window.location.href, window.location.href); } catch (x) { return; }
            if (!isPageUrl(u)) return;
            setTimeout(function () { if (!e.defaultPrevented) navShow(); }, 0);   // AJAX forms prevent the submit — left alone
        });
    }

    // 3) Back / Forward cache: the page did not reload, so clear what leaving it left behind
    window.addEventListener('pageshow', function (e) {
        if (!e.persisted) return;
        navReset();
        if (initialPhase) endInitialPhase();
    });
})();
</script>

<!-- NEW (Import/Export result screen revision): full-page result screen
     shown when an export or import finishes (successfully or not). Hidden
     by default; controlled by showGlobalResult()/hideGlobalResult() in the
     script below. -->
<div id="globalResultOverlay" class="hidden" role="alertdialog" aria-live="assertive" aria-labelledby="globalResultTitle" aria-describedby="globalResultMessage">
    <div class="global-result-box">
        <div class="global-result-icon"><i id="globalResultIcon" class="fas fa-check"></i></div>
        <p class="global-result-title" id="globalResultTitle"></p>
        <p class="global-result-message" id="globalResultMessage"></p>
        <button type="button" class="global-result-ok" id="globalResultOkBtn">OK</button>
    </div>
</div>

<div id="sidebar" class="sidebar">
    <!-- ══════════════════════════════════════════════════════════════
         NEW (this adjustment): SIDEBAR STATE SYNC (same as login.php /
         company_login.php / admin_login.php)
         ------------------------------------------------------------
         Keeps the sidebar collapsed / expanded exactly as the admin left
         it on the previous page, using the same shared localStorage key
         "neustSidebarCollapsed" the login pages use.
         • Restore — runs right here, as the sidebar is being built and
           before the page is first painted, so the page opens directly in
           the saved state with no flash of the other state. It adds this
           page's own .collapsed class, so this page's own collapsed styles
           (and its toggle button) take it from there unchanged.
         • Save — a watcher on the sidebar's class list stores the new
           state whenever the existing toggle button changes it, so none of
           the existing toggle code had to change.
         • Phones (768px and narrower) are left alone: there some pages use
           .collapsed to OPEN the slide-in menu, so a saved desktop
           preference is never applied or overwritten on a small screen.
    ══════════════════════════════════════════════════════════════ -->
    <script>
    (function () {
        var sb = document.getElementById('sidebar');
        if (!sb) return;
        var KEY = 'neustSidebarCollapsed';
        function isDesktop() { return !(window.matchMedia && window.matchMedia('(max-width: 768px)').matches); }
        try {
            if (isDesktop() && localStorage.getItem(KEY) === '1') sb.classList.add('collapsed');
        } catch (e) { /* localStorage unavailable — falls back to the default expanded state */ }
        if (window.MutationObserver) {
            new MutationObserver(function () {
                if (!isDesktop()) return;
                try { localStorage.setItem(KEY, sb.classList.contains('collapsed') ? '1' : '0'); } catch (e) { /* state just won't persist */ }
            }).observe(sb, { attributes: true, attributeFilter: ['class'] });
        }
    })();
    </script>
    <div class="sidebar-header">
        <div class="sidebar-header-titles">
            <h2><?= htmlspecialchars($adminFullName ?? '') ?></h2>
            <span class="sidebar-role-label">Administrator</span>
        </div>
        <button id="toggleBtn" class="toggle-btn"><i class="fas fa-bars"></i></button>
    </div>
    <div class="sidebar-links">
        <a href="admin_student_list.php"><i class="fas fa-users"></i><span class="link-text">Student List</span></a>
        <a href="admin_company_list.php" class="active"><i class="fas fa-address-book"></i><span class="link-text">Company List</span></a>
        <a href="course_offering.php"><i class="fas fa-book"></i><span class="link-text">Course Offering</span></a>
        <a href="administrator.php" style="position:relative;">
            <i class="fas fa-user-check"></i><span class="link-text">Student Requirements</span>
            <!-- UPDATED (this adjustment): always rendered with id="sidebarAppBadge" (hidden at 0), same as the other admin pages, so the live check below can
                 show / update / hide it without a reload — e.g. it now disappears the moment a student cancels their request. -->
            <span class="sidebar-badge-app" id="sidebarAppBadge"<?= $app_request_count > 0 ? '' : ' style="display:none"' ?>><?= $app_request_count ?></span>
        </a>
        <a href="company_validation.php" style="position:relative;">
            <i class="fas fa-building"></i><span class="link-text">Company Requirements</span>
            <span class="sidebar-badge-moa" id="sidebarMoaBadge"<?= $moa_pending_count > 0 ? '' : ' style="display:none"' ?>><?= $moa_pending_count ?></span>
        </a>
        <a href="monitoring.php" style="position:relative;"><i class="fas fa-users-cog"></i><span class="link-text">Manage Accounts</span><!-- NEW (this adjustment): Email Recovery Requests indicator — same badge look as the application-request badge --><span class="sidebar-badge-app sidebar-badge-recovery" id="sidebarRecoveryBadge"<?= $recovery_pending_count > 0 ? '' : ' style="display:none"' ?>><?= (int)$recovery_pending_count ?></span></a>
        <a href="admin_monitoring_dashboard.php">
            <i class="fas fa-chart-line"></i><span class="link-text">Monitoring Dashboard</span>
        </a>
        <a href="admin_final_grades.php"><i class="fas fa-graduation-cap"></i><span class="link-text">Final Grades</span></a>
        <a href="system_setting.php" ><i class="fas fa-gear"></i><span class="link-text">System Setting</span></a>
    </div>
    <div class="logout-link"><a href="admin_login.php?logout=1"><i class="fas fa-sign-out-alt"></i><span class="link-text">Logout</span></a></div>
</div>

<div class="main-content">
    <nav class="navbar">
        <div class="logo-section">
            <img src="logo.webp" class="university-logo" alt="NEUST Logo">
            <div>
                <div style="font-weight:bold;font-size:16px;">NEUST Atate Campus</div>
                <div style="font-size:11px;color:var(--neust-gold);">Web-Based Smart OJT Monitoring and Supervision Analytics System</div>
            </div>
        </div>
    </nav>

    <div class="container">
        <?php /* UPDATED (success-banner removal): the green success banners that
                 used to appear here ("Successfully imported N new companies and
                 updated N existing companies.", "MOA processing settings updated
                 successfully!", "Company added successfully!", etc.) have been
                 removed entirely. Only ERROR messages are still shown here, so a
                 failed import / add is never silent. The messages themselves are
                 still computed server-side exactly as before; they are simply no
                 longer displayed when they are success messages. */ ?>
        <?php if ($company_import_message !== '' && $company_import_message_type === 'error'): ?>
            <div class="alert alert-<?= $company_import_message_type ?>"><?= $company_import_message ?></div>
        <?php endif; ?>
        <?php if ($company_manual_add_message !== '' && $company_manual_add_message_type === 'error'): ?>
            <div class="alert alert-<?= $company_manual_add_message_type ?>"><?= $company_manual_add_message ?></div>
        <?php endif; ?>

        <!-- Filter Bar — placed above the toolbar, same order as admin_student_list.php (search bar + buttons first, then the action toolbar). -->
        <form class="filter-bar" method="GET" id="filterForm">
            <div class="filter-group">
                <label><i class="fas fa-search"></i> Search</label>
                <input type="text" name="search" id="searchInput" placeholder="Company or contact name..." value="<?= htmlspecialchars($search_term ?? '') ?>" autocomplete="off">
            </div>
            <div class="filter-group">
                <label><i class="fas fa-filter"></i> Status</label>
                <!-- ── UPDATED (Status column revision): "Verified"/
                     "Pending" replaced with the new three-state Status
                     (Active/Validating/Inactive) — see
                     resolve_company_status()/sql_company_status_expr()
                     above for exactly what each state means. "Not
                     Registered (Imported)" is unchanged. -->
                <select name="status" id="statusSelect">
                    <option value="All">All Status</option>
                    <option value="Active"     <?= $status_filter==='Active'?'selected':'' ?>>Active</option>
                    <option value="Validating" <?= $status_filter==='Validating'?'selected':'' ?>>Validating</option>
                    <option value="Inactive"   <?= $status_filter==='Inactive'?'selected':'' ?>>Inactive</option>
                </select>
            </div>
            <!-- NEW (Company Type filter revision) -->
            <div class="filter-group">
                <label><i class="fas fa-city"></i> Company Type</label>
                <select name="company_type" id="companyTypeFilterSelect">
                    <option value="All">All Company Types</option>
                    <option value="Public"      <?= $company_type_filter==='Public'?'selected':'' ?>>Public</option>
                    <option value="Private"     <?= $company_type_filter==='Private'?'selected':'' ?>>Private</option>
                </select>
            </div>
            <div class="filter-group">
                <label><i class="fas fa-random"></i> MOA Request Type</label>
                <select name="request_type" id="requestTypeSelect">
                    <option value="All">All Types</option>
                    <option value="New"      <?= $request_type_filter==='New'?'selected':'' ?>>New Company</option>
                    <option value="Existing" <?= $request_type_filter==='Existing'?'selected':'' ?>>Existing Company</option>
                </select>
            </div>
        </form>

        <!-- ══════════════════════════════════════════════════════════
             UPDATED: Table toolbar — Add Company Manually, Edit,
             Delete Entry, Export to Excel, and Import Companies all
             live in one place, right where the list is.

             UPDATED (this revision — Create Account removed): the
             standalone "Create Account" toolbar button has been
             removed entirely. Account creation is no longer a manual,
             selection-driven action anywhere on this page — it now
             happens automatically, right after a company is either
             imported via XLSX or added through the "Add Company
             Manually" form, using the exact same account-creation logic
             that used to live behind this button (see
             attempt_create_company_account() / the "Creating Company
             Accounts" progress modal further below). If an
             automatically-attempted account can't be created outright
             (e.g. a missing/invalid email), that is reported to the
             admin via the progress modal so they can fix the entry —
             at that point they can retry it any time simply by editing
             the entry (to correct the email) and re-adding/re-importing
             it, since creation is always attempted automatically.

             "Edit" is a toolbar-level action that mirrors "Delete"'s
             selection-mode UX — clicking it reveals the same checkbox
             column used by Delete, but only ONE imported entry can be
             checked/edited at a time. Clicking it again while exactly
             one entry is checked opens the Edit modal, populated
             directly from that row's checkbox data-* attributes (the
             Actions column / per-row Edit button has been removed —
             editing is now only available through this toolbar flow).

             The "Import Companies" button/form here is functionally
             identical to the removed import card: same field name
             (company_import_file), same POST handler further up in
             this file, same XLSX-only/100MB validation — only the
             trigger UI changed (a compact button instead of a large
             drag-and-drop dropzone). Clicking it opens the file picker
             directly; choosing a valid .xlsx file auto-submits the
             form, same as the old "Import Companies" button did.
             ══════════════════════════════════════════════════════════ -->
        <div class="table-header-row">
            <div class="company-total-count" id="companyTotalCount"><i class="fas fa-building"></i> Total Companies: <strong id="companyTotalCountValue"><?= (int) $total_companies ?></strong></div>
            <div class="table-toolbar">
            <button type="button" class="add-company-btn" id="addCompanyBtn"><i class="fas fa-plus-circle"></i> Add Company Manually</button>
            <button type="button" class="edit-entry-btn" id="editEntryBtn" title="Click to select one entry to edit">
                <i class="fas fa-edit"></i> Edit
            </button>
            <button type="button" class="delete-entry-btn" id="deleteEntryBtn" title="Click to select entries to delete">
                <i class="fas fa-trash-alt"></i> Delete
            </button>
            <button type="button" class="cancel-selection-btn" id="cancelSelectionBtn" style="display:none;">
                <i class="fas fa-times"></i> Cancel
            </button>
            <a href="?export=xlsx" class="export-btn" id="companyExportBtn">
                <i class="fas fa-file-excel"></i> Export to Excel
            </a>
            <!-- NEW (Export exclusion revision): shown only while the admin is
                 choosing which companies to leave OUT of the export (see
                 enterExportExcludeSelectionMode() in the script below);
                 replaces the plain Export button for the duration of that
                 selection, then reverts back to it once confirmed or
                 cancelled. -->
            <button type="button" class="export-btn" id="exportConfirmBtn" style="display:none;">
                <i class="fas fa-file-excel"></i> Export (Excluding 0)
            </button>
            <form method="POST" enctype="multipart/form-data" id="companyImportForm" style="display:contents;">
                <label for="company_import_file" class="export-btn" id="companyImportTriggerBtn" title="XLSX files only (up to 100MB) — Company Name, Address, Telephone, Contact First/Middle/Last Name, Position, Request Type (New/Existing), Email, Company Type (Public/Private). Column order doesn't matter as long as the header row uses these names. Accounts for newly imported companies are created automatically after the import finishes.">
                    <i class="fas fa-file-import"></i> <span id="companyImportBtnLabel">Import Companies</span>
                </label>
                <input type="file" name="company_import_file" id="company_import_file" accept=".xlsx" required style="display:none;">
            </form>
        </div>
        </div>

        <!-- ══════════════════════════════════════════════════════════
             NEW: table + pagination are wrapped in one container so the
             search box, the two filter dropdowns, and pagination clicks
             can all swap this container's contents via fetch() with a
             short fade/slide animation, instead of reloading the page.
             ══════════════════════════════════════════════════════════ -->
        <div id="companyTableSection"><?= $table_section_html ?></div>
    </div>
</div>

<!-- NEW: floating toast container for the no-reload Add Company / Delete Entry / Edit flows -->
<div id="floatingAlertContainer"></div>

<div id="pdfModal">
    <div id="pdfModalBar">
        <div id="pdfModalTitle"><i class="fas fa-file-pdf"></i> <span id="pdfModalTitleText"></span></div>
        <button class="pdf-close-btn" onclick="closePdfModal()"><i class="fas fa-times"></i> Close</button>
    </div>
    <iframe id="pdfModalFrame" title="MOA PDF Preview"></iframe>
</div>

<!-- ══════════════════════════════════════════════════════════
     NEW: Add Company Modal (manual entry, mirrors Add Student modal)
     Adjustments: Company Status radio (New / Existing) sets the
     request_type value stored for this row; Position is a dropdown of
     existing positions with a manual "Other" fallback; Company Type has
     been removed completely (NOTE: this refers to the OLD "company
     type" concept described earlier — the brand-new "Company Type"
     dropdown re-introduced by this revision, just below Position, is a
     separate Public/Private classification unrelated to that older
     removed field). Name/address/contact fields auto-capitalize the
     first letter of each word as the admin types, and the telephone
     field only accepts digits. The form now submits via fetch() (no
     page reload) and falls back to a normal POST if JavaScript is
     unavailable.

     UPDATED (this revision — MOA upload removed): the "MOA Document
     (PDF)" upload section that used to appear when "Existing Company
     (has MOA)" was selected has been removed entirely — it is no
     longer needed on this form. Selecting "Existing Company (has MOA)"
     now only affects the Request Type value stored for this company;
     no file upload is requested or required here at all. (The
     company's own moa_document/has_moa columns are simply left at
     0/NULL for companies added through this form — they remain in use
     elsewhere, e.g. for XLSX-imported rows and the separate Admin Copy
     of MOA feature in the table.)

     Email and Company Type are REQUIRED fields.

     NEW (this revision — automatic account creation): submitting this
     form now also automatically attempts to create the company's login
     account server-side, in the same request as the insert (see the
     add_company_manual handler). No extra step is needed here in the
     modal itself — the JS submit handler further below simply reacts to
     the account-creation outcome included in the JSON response.
     ══════════════════════════════════════════════════════════ -->
<div id="addCompanyModal" class="add-company-modal-overlay">
    <div class="add-company-modal-box">
        <div class="add-company-modal-header">
            <h3><i class="fas fa-building"></i> Add New Company</h3>
            <button class="close-add-company-btn" id="closeAddCompanyBtn">&times;</button>
        </div>
        <form method="POST" enctype="multipart/form-data" id="addCompanyForm">
            <div class="add-company-grid">
                <div class="form-group span-2">
                    <label>Company Name <span class="required">*</span></label>
                    <input type="text" name="company_name" class="cap-words-input" required placeholder="Enter company name">
                </div>
                <div class="form-group">
                    <label>Telephone</label>
                    <input type="text" name="telephone" id="telephoneInput" inputmode="numeric" placeholder="Numbers only">
                </div>
                <div class="form-group span-3">
                    <label>Company Address</label>
                    <input type="text" name="company_address" class="cap-words-input" placeholder="Enter company address">
                </div>
                <div class="form-group">
                    <label>Contact First Name <span class="required">*</span></label>
                    <input type="text" name="contact_first_name" class="cap-words-input" required placeholder="Enter first name">
                </div>
                <div class="form-group">
                    <label>Contact Middle Name</label>
                    <input type="text" name="contact_middle_name" class="cap-words-input" placeholder="Enter middle name (optional)">
                </div>
                <div class="form-group">
                    <label>Contact Last Name <span class="required">*</span></label>
                    <input type="text" name="contact_last_name" class="cap-words-input" required placeholder="Enter last name">
                </div>
                <div class="form-group span-2">
                    <label>Email <span class="required">*</span></label>
                    <input type="email" name="email" id="emailInput" required placeholder="Enter email address">
                </div>
                <div class="form-group">
                    <label>Position</label>
                    <select name="position" id="positionSelect">
                        <option value="">Select Position</option>
                        <?php foreach ($position_options as $popt): ?>
                            <option value="<?= htmlspecialchars($popt ?? '') ?>"><?= htmlspecialchars($popt ?? '') ?></option>
                        <?php endforeach; ?>
                        <option value="other">+ Other (Enter manually)</option>
                    </select>
                    <div id="customPositionGroup" class="custom-position-group">
                        <input type="text" name="custom_position" id="customPosition" class="cap-words-input" placeholder="Enter position">
                    </div>
                </div>
                <div class="form-group">
                    <label>Company Type <span class="required">*</span></label>
                    <select name="company_type" id="companyTypeSelect" required>
                        <option value="">Select Company Type</option>
                        <option value="Public">Public</option>
                        <option value="Private">Private</option>
                    </select>
                    <div class="help-text">Classifies the company as a Public or Private organization. Shown in the table between Status and Request Type.</div>
                </div>
                <div class="form-group span-2">
                    <label>Company Status <span class="required">*</span></label>
                    <div class="company-status-options">
                        <label><input type="radio" name="company_status" value="new" id="statusNewRadio" checked> New Company</label>
                        <label><input type="radio" name="company_status" value="existing" id="statusExistingRadio"> Existing Company (has MOA)</label>
                    </div>
                    <div class="help-text">Select "Existing Company" if this company already has a signed MOA on file. This sets the Request Type shown in the table.</div>
                </div>
            </div>
            <div class="modal-actions">
                <button type="button" class="btn-cancel-modal" id="cancelAddCompanyBtn">Cancel</button>
                <button type="submit" name="add_company_manual" class="btn-submit">Add Company</button>
            </div>
        </form>
    </div>
</div>

<!-- ══════════════════════════════════════════════════════════
     NEW: Edit Imported Company Modal — lets an admin correct the
     details of a manually-added/imported company (name, address,
     telephone, contact person, email, position, request type, and —
     NEW — Company Type) and, OPTIONALLY, replace that row's Admin
     Copy of MOA (PDF) in the same submission. The company's own
     submitted MOA Document is NEVER editable here — there is no
     upload control for it at all in this modal, by design, so it can
     never be touched from this screen.

     UPDATED (this revision): since the per-row Edit button/Actions
     column has been removed, this modal is now opened exclusively from
     the toolbar "Edit" button's single-select checkbox flow — the
     checked row's checkbox (which now carries the same data-* values
     the old per-row button used to carry) populates this modal. Submits
     via fetch() to the edit_company_import handler and refreshes the
     table in place on success.

     The "Replace Admin Copy of MOA (PDF)" section below is shown/hidden
     based on the selected Company Status — visible only for "Existing
     Company (has MOA)", hidden for "New Company" — since a "New
     Company" entry isn't eligible for an Admin Copy of MOA in the first
     place (mirrors the table's own eligibility rule).
     ══════════════════════════════════════════════════════════ -->
<div id="editCompanyModal" class="add-company-modal-overlay">
    <div class="add-company-modal-box">
        <div class="add-company-modal-header">
            <h3><i class="fas fa-edit"></i> <span id="editCompanyModalTitleText">Edit Imported Company</span></h3>
            <button class="close-add-company-btn" id="closeEditCompanyBtn">&times;</button>
        </div>
        <form method="POST" enctype="multipart/form-data" id="editCompanyForm">
            <input type="hidden" name="edit_import_id" id="editImportId" value="">
            <!-- NEW (cross-table sync revision): set instead of edit_import_id when a REGISTERED company is being edited -->
            <input type="hidden" name="edit_user_id" id="editUserId" value="">
            <div class="add-company-grid">
                <div class="form-group span-2">
                    <label>Company Name <span class="required">*</span></label>
                    <input type="text" name="edit_company_name" id="editCompanyName" class="cap-words-input" required placeholder="Enter company name">
                </div>
                <div class="form-group">
                    <label>Telephone</label>
                    <input type="text" name="edit_telephone" id="editTelephoneInput" inputmode="numeric" placeholder="Numbers only">
                </div>
                <div class="form-group span-3">
                    <label>Company Address</label>
                    <input type="text" name="edit_company_address" id="editCompanyAddress" class="cap-words-input" placeholder="Enter company address">
                </div>
                <div class="form-group">
                    <label>Contact First Name <span class="required">*</span></label>
                    <input type="text" name="edit_contact_first_name" id="editContactFirstName" class="cap-words-input" required placeholder="Enter first name">
                </div>
                <div class="form-group">
                    <label>Contact Middle Name</label>
                    <input type="text" name="edit_contact_middle_name" id="editContactMiddleName" class="cap-words-input" placeholder="Enter middle name (optional)">
                </div>
                <div class="form-group">
                    <label>Contact Last Name <span class="required">*</span></label>
                    <input type="text" name="edit_contact_last_name" id="editContactLastName" class="cap-words-input" required placeholder="Enter last name">
                </div>
                <div class="form-group span-2">
                    <label>Email <span class="required">*</span></label>
                    <input type="email" name="edit_email" id="editEmailInput" required placeholder="Enter email address">
                </div>
                <div class="form-group">
                    <label>Position</label>
                    <select name="edit_position" id="editPositionSelect">
                        <option value="">Select Position</option>
                        <?php foreach ($position_options as $popt): ?>
                            <option value="<?= htmlspecialchars($popt ?? '') ?>"><?= htmlspecialchars($popt ?? '') ?></option>
                        <?php endforeach; ?>
                        <option value="other">+ Other (Enter manually)</option>
                    </select>
                    <div id="editCustomPositionGroup" class="custom-position-group">
                        <input type="text" name="edit_custom_position" id="editCustomPosition" class="cap-words-input" placeholder="Enter position">
                    </div>
                </div>
                <div class="form-group">
                    <label>Company Type <span class="required">*</span></label>
                    <select name="edit_company_type" id="editCompanyTypeSelect" required>
                        <option value="">Select Company Type</option>
                        <option value="Public">Public</option>
                        <option value="Private">Private</option>
                    </select>
                    <div class="help-text">Classifies the company as a Public or Private organization. Shown in the table between Status and Request Type.</div>
                </div>
                <div class="form-group span-2">
                    <label>Company Status <span class="required">*</span></label>
                    <div class="company-status-options">
                        <label><input type="radio" name="edit_company_status" value="new" id="editStatusNewRadio"> New Company</label>
                        <label><input type="radio" name="edit_company_status" value="existing" id="editStatusExistingRadio"> Existing Company (has MOA)</label>
                    </div>
                    <div class="help-text">This only updates the Request Type label/filter for this row. It does not add, remove, or replace the company's own MOA document. The Admin Copy of MOA option below is only available when "Existing Company (has MOA)" is selected.</div>
                </div>
                <div class="form-group moa-upload-group span-3" id="editAdminMoaGroup">
                    <label>Replace Admin Copy of MOA (PDF) <span style="color:#94a3b8;font-weight:400;">— optional</span></label>
                    <label for="editAdminMoaFile" class="moa-file-upload-label" id="editAdminMoaFileLabel">
                        <i class="fas fa-file-pdf"></i>
                        <span class="moa-upload-text">Click to upload a new admin copy (PDF only)</span>
                    </label>
                    <input type="file" name="edit_admin_moa_file" id="editAdminMoaFile" accept=".pdf,application/pdf">
                    <div class="moa-file-selected-name" id="editAdminMoaFileSelectedName"></div>
                    <div class="help-text">Leave blank to keep the current admin copy unchanged. Only PDF files are accepted. The company's own submitted MOA Document cannot be edited from this screen.</div>
                </div>
            </div>
            <div class="modal-actions">
                <button type="button" class="btn-cancel-modal" id="cancelEditCompanyBtn">Cancel</button>
                <button type="submit" name="edit_company_import" class="btn-submit">Save Changes</button>
            </div>
        </form>
    </div>
</div>

<!-- ══════════════════════════════════════════════════════════
     NEW: Delete Entry (selected rows) confirmation modal — triggered
     by the "Delete Entry" button in the toolbar above the table once
     one or more imported rows are checked. Submits via fetch(), same
     no-reload pattern as Add Company, then refreshes the table. Clicking
     "Cancel" (or the backdrop) simply closes this popup and the delete
     action is never executed.
     ══════════════════════════════════════════════════════════ -->
<div id="deleteSelectedModal" class="modal-overlay">
    <div class="modal-box">
        <div class="modal-icon"><i class="fas fa-trash-alt"></i></div>
        <p class="modal-title">Confirm Deletion</p>
        <p class="modal-message" id="deleteSelectedMessage">Are you sure you want to delete the selected companies? This action cannot be undone.</p>
        <!-- NEW (Delete revision): lists the selected companies (registered accounts and imported entries) -->
        <div id="deleteSelectedDetails"></div>
        <div class="modal-actions">
            <button type="button" class="modal-cancel" id="deleteSelectedCancelBtn">Cancel</button>
            <button type="button" class="modal-confirm" id="deleteSelectedConfirmBtn">Yes, Delete</button>
        </div>
    </div>
</div>

<!-- ══════════════════════════════════════════════════════════
     NEW (Company Type / Request Type import filter revision): shown
     right after a file is chosen for "Import Companies", once the
     read-only "detect classifications" endpoint has reported back every
     distinct Request Type / Company Type it found in that file's data
     rows (see detect_company_import_classifications above and
     runImportClassificationDetection() in the script below). Every
     checkbox here starts checked (so an admin who just wants everything
     imported, same as before this revision, can simply click "Import
     Selected" with no changes) — unchecking a classification excludes
     every row of that classification from the import. The actual XLSX
     upload only happens once this modal is confirmed; Cancel discards
     the file selection entirely and nothing is uploaded.
     ══════════════════════════════════════════════════════════ -->
<div id="importClassificationModal" class="modal-overlay">
    <div class="modal-box"><!-- UPDATED (this adjustment): same design as the manual Add Student form (see the scoped styles) -->
        <!-- UPDATED: the funnel icon that used to sit above this title has been removed. -->
        <div class="import-picker-head">
            <h3><i class="fas fa-file-import"></i> Choose What To Import</h3>
            <button type="button" class="import-picker-close" id="importClassificationCloseBtn" aria-label="Close" data-cv-tip="Close">&times;</button>
        </div>
        <p class="modal-message" id="importClassificationSummary">This file contains the classifications below. Only the ones you keep checked will be imported — everything else in the file will be skipped.</p>
        <div class="import-picker-grid"><!-- NEW (this adjustment): the two lists side by side -->
        <div class="import-classification-group">
            <div class="import-classification-group-label"><i class="fas fa-random"></i> Request Type</div>
            <div id="importReqTypeOptions" class="import-classification-options"></div>
        </div>
        <div class="import-classification-group">
            <div class="import-classification-group-label"><i class="fas fa-city"></i> Company Type</div>
            <div id="importCompanyTypeOptions" class="import-classification-options"></div>
        </div>
        </div>
        <div class="modal-actions">
            <button type="button" class="modal-cancel" id="importClassificationCancelBtn">Cancel</button>
            <button type="button" class="modal-confirm" id="importClassificationConfirmBtn" style="background:var(--grid-navy); border-color:var(--grid-navy);">Import Selected</button>
        </div>
    </div>
</div>

<!-- ══════════════════════════════════════════════════════════
     NEW (Export exclusion revision): first step of "Export to Excel" —
     clicking the toolbar Export button now opens this choice instead of
     downloading immediately. "None, Proceed with Export" exports every
     company exactly as before (unfiltered, ignoring the current search/
     status/request-type filters, same as always). "Yes, Let Me Choose"
     instead turns on a selection mode over the table itself (reusing
     the same checkbox column already used by Edit/Delete) so the admin
     can check off specific companies to leave OUT of the file — see
     enterExportExcludeSelectionMode() in the script below.
     ══════════════════════════════════════════════════════════ -->
<div id="exportChoiceModal" class="modal-overlay">
    <div class="modal-box" style="width:440px;">
        <button type="button" class="export-choice-close" id="exportChoiceCloseBtn" aria-label="Close" data-cv-tip="Close">&times;</button><!-- NEW (this adjustment): cancels and closes this popup -->
        <div class="modal-icon" style="color:var(--grid-navy);"><i class="fas fa-file-excel"></i></div>
        <p class="modal-title">Export To Excel</p>
        <p class="modal-message" style="text-align:center;">Do you have any companies you'd like to exclude from this export?</p>
        <div class="modal-actions">
            <button type="button" class="modal-cancel" id="exportChoiceNoneBtn">None, Proceed With Export</button>
            <button type="button" class="modal-confirm" id="exportChoiceYesBtn" style="background:var(--grid-navy); border-color:var(--grid-navy);">Yes</button>
        </div>
    </div>
</div>

<!-- ══════════════════════════════════════════════════════════
     UPDATED (Full-screen Requirements Viewer revision): "View
     Requirements" no longer opens a small list popup that asks the
     admin which requirement to open first. Clicking it now opens this
     FULL-SCREEN preview straight away, automatically showing the first
     requirement that has a submitted file. Every requirement the
     company has on record is listed in the LEFT panel (with its status
     badge, and one sub-entry per file when a requirement has several
     files), so the admin can switch between requirements without ever
     leaving the preview. Still populated live via the same read-only
     ajax_fetch_requirements endpoint, and each file is still streamed
     through the same view_requirement_file endpoint — this page never
     verifies, rejects, or otherwise acts on a requirement; that stays
     exclusively on company_validation.php.
     ══════════════════════════════════════════════════════════ -->
<div id="requirementsModal">
    <div id="reqViewerBar">
        <div id="reqViewerTitle">
            <i class="fas fa-folder-open"></i>
            <span id="requirementsModalTitle">Requirements</span>
            <span id="reqViewerCurrentLabel" class="req-viewer-current"></span>
            <span id="reqViewerCurrentBadge"></span>
        </div>
        <div class="req-viewer-bar-actions">
            <button type="button" class="pdf-close-btn" id="requirementsModalCloseBtn"><i class="fas fa-times"></i> Close</button>
        </div>
    </div>
    <div id="reqViewerBody">
        <aside id="reqViewerSidebar">
            <div class="req-viewer-sidebar-head"><i class="fas fa-list-ul"></i> Requirements <span id="reqViewerCount"></span></div>
            <div id="requirementsModalBody"></div>
        </aside>
        <div id="reqViewerStage">
            <div id="reqViewerPlaceholder">
                <div class="global-loading-spinner"></div>
                <p>Loading requirements…</p>
            </div>
            <iframe id="reqViewerFrame" title="Requirement Preview"></iframe>
            <div id="reqViewerImageWrap"><img id="reqViewerImage" alt="Requirement Preview"></div>
        </div>
    </div>
</div>

<!-- ══════════════════════════════════════════════════════════
     NEW (Delete revision): "Account Deleted" notification popup —
     shown right after the Delete action finishes (mirrors the "Account
     Deleted" notification on monitoring.php). Lists every company that
     was removed together with its details (registered company accounts
     AND imported entries), plus anything that could not be deleted.
     ══════════════════════════════════════════════════════════ -->
<div id="deleteResultModal" class="modal-overlay">
    <div class="modal-box" style="width:520px; max-width:92%; max-height:86vh; overflow-y:auto;">
        <div class="modal-icon" id="deleteResultIcon"><i class="fas fa-check-circle"></i></div>
        <p class="modal-title" id="deleteResultTitle">Account Deleted</p>
        <p class="modal-message" id="deleteResultMessage" style="text-align:center;"></p>
        <div id="deleteResultBody"></div>
        <div class="modal-actions">
            <button type="button" class="modal-confirm" id="deleteResultOkBtn" style="background:var(--grid-navy); border-color:var(--grid-navy);">OK</button>
        </div>
    </div>
</div>

<!-- ══════════════════════════════════════════════════════════
     NEW: Auto Account Creation Progress modal — this is the "loading
     page" shown automatically right after a successful XLSX import, or
     a manual "Add Company" submission that needs follow-up, while the
     server attempts to create a login account for the newly imported/
     added compan(y/ies). No selection or button click is required to
     trigger this — see window.autoCreateAccountImportIds,
     window.autoCreateAccountPreSkippedDetails, and
     runAutoAccountCreation() in the script below. Starts as a spinner +
     "Creating accounts..." message, then swaps in place to a Created /
     Skipped / Failed breakdown (with a per-company detail list for
     anything that wasn't a clean success) once the server responds — or
     renders that breakdown immediately when the batch consists entirely
     of already-registered skips that were never staged for processing.
     ══════════════════════════════════════════════════════════ -->
<div id="autoAccountProgressModal" class="modal-overlay">
    <div class="modal-box" style="width:480px;">
        <div class="modal-icon" style="color:var(--grid-navy);"><i class="fas fa-user-plus"></i></div>
        <p class="modal-title">Creating Company Accounts</p>
        <div id="autoAccountProgressBody"></div>
        <div class="modal-actions">
            <button type="button" class="modal-confirm" id="autoAccountProgressCloseBtn" style="background:var(--grid-navy); border-color:var(--grid-navy); display:none;">Done</button>
        </div>
    </div>
</div>

<script>
/* ══════════════════════════════════════════════════════════
   NEW: companies_import ids resolved server-side for rows just
   imported/updated via XLSX (or a manual "Add Company" submission that
   needs follow-up) in THIS SAME REQUEST (empty array on every other
   page load — plain filter/pagination reloads, settings save, etc).
   When non-empty, the page automatically attempts to create login
   accounts for these companies as soon as it loads — no manual
   checkbox selection or button click required, since the standalone
   "Create Account" toolbar button has been removed entirely (account
   creation is now always automatic). See runAutoAccountCreation() near
   the bottom of this script.
   ══════════════════════════════════════════════════════════ */
window.autoCreateAccountImportIds = <?= json_encode(array_values($auto_create_account_import_ids)) ?>;

/* NEW (popup restore fix): companies that the XLSX import's "already
   registered" pre-check skipped WITHOUT ever staging them into
   companies_import (see $auto_create_account_pre_skipped_details in
   PHP above). These never receive a companies_import id, so they can't
   be sent to create_accounts_selected — but they are fed straight into
   the "Creating Company Accounts" popup below as pre-known Skipped
   entries so the admin still sees them broken out in that popup like
   the older version of this page did, instead of only in the plain
   text alert above the table. */
window.autoCreateAccountPreSkippedDetails = <?= json_encode(array_values($auto_create_account_pre_skipped_details)) ?>;

/* NEW (Import/Export result screen revision): outcome of the XLSX import
   that was just submitted (null when this page load was not an import).
   Read by the post-import result screen near the bottom of this script. */
window.companyImportResult = <?= $company_import_attempted
    ? json_encode(['type' => $company_import_message_type, 'message' => $company_import_message])
    : 'null' ?>;

document.getElementById('toggleBtn').addEventListener('click', () => document.getElementById('sidebar').classList.toggle('collapsed'));

/* ── NEW (sidebar notification indicator): keeps the "Company Requirements"
   badge live, using the same notification count company_validation.php
   shows (see aclCompanyValidationNotifCount() above). Hidden at 0. ── */
(function(){
    function pollCompanyValidationBadge(){
        fetch('admin_company_list.php?cv_sidebar_notif_count=1', {credentials:'same-origin'})
            .then(r => r.json())
            .then(data => {
                const badge = document.getElementById('sidebarMoaBadge');
                if (!badge) return;
                const count = parseInt(data.count, 10) || 0;
                badge.textContent = count;
                badge.style.display = count > 0 ? '' : 'none';
            })
            .catch(() => {});
    }
    setTimeout(() => { pollCompanyValidationBadge(); setInterval(pollCompanyValidationBadge, 15000); }, 5000);
})();

/* ══════════════════════════════════════════════════════════
   NEW: Global loading/processing overlay controls.
   showGlobalLoading(label) reveals the popup with an optional custom
   label ("Loading…", "Saving…", "Deleting…", "Creating accounts…",
   etc). hideGlobalLoading() fades it out. A small usage counter
   (globalLoadingActiveCount) makes sure the overlay only hides once
   every in-flight operation that asked for it has actually finished,
   so overlapping calls can never hide it prematurely.
   ══════════════════════════════════════════════════════════ */
let globalLoadingActiveCount = 0;
const globalLoadingOverlay = document.getElementById('globalLoadingOverlay');
const globalLoadingLabel = document.getElementById('globalLoadingLabel');

function showGlobalLoading(label) {
    globalLoadingActiveCount++;
    if (globalLoadingLabel) globalLoadingLabel.textContent = label || 'Loading';
    if (globalLoadingOverlay) globalLoadingOverlay.classList.remove('hidden');
}

function hideGlobalLoading() {
    globalLoadingActiveCount = Math.max(0, globalLoadingActiveCount - 1);
    if (globalLoadingActiveCount === 0 && globalLoadingOverlay) {
        globalLoadingOverlay.classList.add('hidden');
    }
}

/* ══════════════════════════════════════════════════════════
   NEW (Edit/Delete success loading page — same as admin_student_list.php):
   turns the page loader that is already showing ("Saving changes" /
   "Deleting entries") into a check icon + action message instead of a
   popup notification. This page refreshes its table in place (no full
   reload), so refreshFn (e.g. fetchCompanyTable) runs right away behind
   the success screen; after holdMs the screen fades out. Call it while
   the action's own showGlobalLoading() is still active — it releases
   that one usage itself, so the caller must NOT call hideGlobalLoading().
   If the table refresh is still running after holdMs, the loader simply
   falls back to its normal spinner until the refresh finishes.
   ══════════════════════════════════════════════════════════ */
function showGlobalSuccess(title, message, refreshFn, holdMs) {
    const t = document.getElementById('globalLoadingSuccessTitle');
    const m = document.getElementById('globalLoadingSuccessMsg');
    let plainText = '';
    try {
        const doc = new DOMParser().parseFromString(String(message || '').replace(/<br\s*\/?>/gi, ' '), 'text/html');
        plainText = (doc.body && doc.body.textContent) ? doc.body.textContent : '';
    } catch (e) { plainText = String(message || ''); }
    if (t) t.textContent = title || 'Success';
    if (m) m.textContent = plainText.replace(/\s+/g, ' ').trim();
    if (globalLoadingOverlay) {
        globalLoadingOverlay.classList.add('success-state');
        globalLoadingOverlay.classList.remove('hidden');
    }
    if (typeof refreshFn === 'function') refreshFn();
    setTimeout(function() {
        hideGlobalLoading();
        if (!globalLoadingOverlay) return;
        if (globalLoadingActiveCount > 0) {
            // Table still refreshing — show the normal spinner until it finishes.
            globalLoadingOverlay.classList.remove('success-state');
        } else {
            // Let the fade-out finish before switching the content back.
            setTimeout(function() {
                if (globalLoadingOverlay.classList.contains('hidden')) globalLoadingOverlay.classList.remove('success-state');
            }, 400);
        }
    }, holdMs || 1600);
}

/* ══════════════════════════════════════════════════════════
   NEW (Import/Export result screen revision): full-page result screen
   shown when an export or import finishes.
   showGlobalResult(type, title, message, autoHideMs)
     - type: 'success' (green check) or 'error' (red X)
     - autoHideMs: close automatically after this many ms (0 = stay
       open until the admin clicks OK).
   hideGlobalResult() closes it. Completely separate from the loading
   overlay's usage counter above, so it never interferes with it.
   ══════════════════════════════════════════════════════════ */
const globalResultOverlay = document.getElementById('globalResultOverlay');
const globalResultIcon = document.getElementById('globalResultIcon');
const globalResultTitle = document.getElementById('globalResultTitle');
const globalResultMessage = document.getElementById('globalResultMessage');
const globalResultOkBtn = document.getElementById('globalResultOkBtn');
let globalResultHideTimer = null;

function hideGlobalResult() {
    if (globalResultHideTimer) { clearTimeout(globalResultHideTimer); globalResultHideTimer = null; }
    if (globalResultOverlay) globalResultOverlay.classList.add('hidden');
}

function showGlobalResult(type, title, message, autoHideMs) {
    if (!globalResultOverlay) {
        // Fallback: should never happen, but never let a result go unreported.
        alert((title ? title + '\n\n' : '') + (message || ''));
        return;
    }
    const isSuccess = type === 'success';
    if (globalResultHideTimer) { clearTimeout(globalResultHideTimer); globalResultHideTimer = null; }
    globalResultOverlay.classList.toggle('is-success', isSuccess);
    globalResultOverlay.classList.toggle('is-error', !isSuccess);
    if (globalResultIcon) globalResultIcon.className = isSuccess ? 'fas fa-check' : 'fas fa-times';
    if (globalResultTitle) globalResultTitle.textContent = title || '';
    if (globalResultMessage) globalResultMessage.textContent = message || '';
    globalResultOverlay.classList.remove('hidden');
    if (globalResultOkBtn) { try { globalResultOkBtn.focus(); } catch (e) {} }
    if (autoHideMs && autoHideMs > 0) {
        globalResultHideTimer = setTimeout(hideGlobalResult, autoHideMs);
    }
}

/* Turns a server-built message that may contain simple HTML (e.g. the
   import's "<br>"-separated error list) into plain text, without ever
   executing or loading anything from it (DOMParser documents are inert). */
function globalResultPlainText(html) {
    if (!html) return '';
    try {
        const doc = new DOMParser().parseFromString(String(html).replace(/<br\s*\/?>/gi, '\n'), 'text/html');
        return (doc.body && doc.body.textContent) ? doc.body.textContent.trim() : '';
    } catch (e) {
        return String(html);
    }
}

if (globalResultOkBtn) globalResultOkBtn.addEventListener('click', hideGlobalResult);
document.addEventListener('keydown', function(e) {
    if (e.key === 'Escape' && globalResultOverlay && !globalResultOverlay.classList.contains('hidden')) hideGlobalResult();
});

/* The overlay is visible by default (see CSS) so it covers the very
   first paint while page assets are still loading. As soon as the
   window has fully finished loading, it fades away on its own. */
window.addEventListener('load', function() {
    globalLoadingActiveCount = 0;
    if (globalLoadingOverlay) globalLoadingOverlay.classList.add('hidden');
});
/* Safety net: if for any reason the 'load' event is delayed (slow
   third-party assets like the Font Awesome CDN), don't leave the admin
   staring at the popup forever — hide it after a short ceiling too. */
setTimeout(function() {
    globalLoadingActiveCount = 0;
    if (globalLoadingOverlay) globalLoadingOverlay.classList.add('hidden');
}, 4000);

function openPdfModal(userId, companyName) {
    document.getElementById('pdfModalTitleText').textContent = companyName + ' — MOA Document';
    document.getElementById('pdfModalFrame').src = '?view_moa_pdf=' + userId;
    document.getElementById('pdfModal').classList.add('open');
}
/* NEW (Existing Partnership MOA revision): same preview modal, for the
   "MOA Document (Existing Partnership)" of an Existing-request-type company. */
function openPdfModalExisting(userId, companyName) {
    document.getElementById('pdfModalTitleText').textContent = companyName + ' — MOA Document (Existing Partnership)';
    document.getElementById('pdfModalFrame').src = '?view_moa_existing=' + userId;
    document.getElementById('pdfModal').classList.add('open');
}
/* NEW: same preview modal, but for MOA PDFs attached to imported/manually-added companies */
function openPdfModalImport(importId, companyName) {
    document.getElementById('pdfModalTitleText').textContent = companyName + ' — MOA Document';
    document.getElementById('pdfModalFrame').src = '?view_moa_pdf_import=' + importId;
    document.getElementById('pdfModal').classList.add('open');
}
function closePdfModal() {
    document.getElementById('pdfModal').classList.remove('open');
    document.getElementById('pdfModalFrame').src = '';
}
document.addEventListener('keydown', e => { if (e.key === 'Escape') closePdfModal(); });

/* NEW: generic version of the preview modal opener, used by the Admin
   Copy of MOA "View" buttons (which stream from a different endpoint —
   view_admin_moa_pdf / view_admin_moa_pdf_import — depending on whether
   the row is a registered or imported company). */
function openPdfModalGeneric(src, companyName) {
    document.getElementById('pdfModalTitleText').textContent = companyName + ' — Admin Copy of MOA';
    document.getElementById('pdfModalFrame').src = src;
    document.getElementById('pdfModal').classList.add('open');
}

/* ══════════════════════════════════════════════════════════
   NEW (Requirements column revision): "View Requirements" modal — see
   requirementsModal above and ajax_fetch_requirements server-side.
   openRequirementFileModal() reuses the exact same #pdfModal/
   #pdfModalFrame preview surface as the MOA "View" buttons, just with
   its own title text (unlike openPdfModalGeneric() above, whose title
   suffix is hardcoded to "Admin Copy of MOA" for that specific
   feature) — so opening a requirement's file never mislabels it.
   ══════════════════════════════════════════════════════════ */
function openRequirementFileModal(src, title) {
    document.getElementById('pdfModalTitleText').textContent = title;
    document.getElementById('pdfModalFrame').src = src;
    document.getElementById('pdfModal').classList.add('open');
}

const requirementsModal = document.getElementById('requirementsModal');
const requirementsModalTitle = document.getElementById('requirementsModalTitle');
const requirementsModalBody = document.getElementById('requirementsModalBody');
const requirementsModalCloseBtn = document.getElementById('requirementsModalCloseBtn');
/* NEW (Full-screen Requirements Viewer revision) */
const reqViewerFrame = document.getElementById('reqViewerFrame');
const reqViewerPlaceholder = document.getElementById('reqViewerPlaceholder');
const reqViewerCurrentLabel = document.getElementById('reqViewerCurrentLabel');
const reqViewerCurrentBadge = document.getElementById('reqViewerCurrentBadge');
/* NEW (centered preview revision) */
const reqViewerImageWrap = document.getElementById('reqViewerImageWrap');
const reqViewerImage = document.getElementById('reqViewerImage');

function hideReqViewerImage() {
    if (reqViewerImageWrap) reqViewerImageWrap.style.display = 'none';
    if (reqViewerImage) reqViewerImage.removeAttribute('src');
}
const reqViewerCount = document.getElementById('reqViewerCount');

function requirementStatusBadgeClass(status) {
    if (status === 'Verified') return 'Verified';
    if (status === 'Pending')  return 'Pending';
    if (status === 'Rejected' || status === 'Denied') return 'Rejected';
    return 'Awaiting';
}

/* ══════════════════════════════════════════════════════════
   UPDATED (Full-screen Requirements Viewer revision): "View
   Requirements" now opens the full-screen preview immediately and
   auto-selects the first requirement that has a file — no more
   intermediate "which requirement do you want to open?" popup. The
   left panel (built by renderRequirementsList()) lists every
   requirement so the admin can switch between them in place.
   reqViewerState holds what is currently loaded/selected;
   reqViewerRequestToken guards against a slow response for a company
   the admin already closed/switched away from overwriting a newer one.
   ══════════════════════════════════════════════════════════ */
let reqViewerState = { userId: null, companyName: '', requirements: [], reqIdx: -1, fileIdx: -1 };
let reqViewerRequestToken = 0;

function setReqViewerPlaceholder(iconHtml, text) {
    if (!reqViewerPlaceholder) return;
    reqViewerPlaceholder.innerHTML = '';
    const iconWrap = document.createElement('div');
    iconWrap.innerHTML = iconHtml;
    while (iconWrap.firstChild) reqViewerPlaceholder.appendChild(iconWrap.firstChild);
    const p = document.createElement('p');
    p.textContent = text;
    reqViewerPlaceholder.appendChild(p);
    reqViewerPlaceholder.style.display = 'flex';
    if (reqViewerFrame) { reqViewerFrame.style.display = 'none'; reqViewerFrame.src = 'about:blank'; }
    hideReqViewerImage();
}

function setReqViewerHeader(label, status) {
    if (reqViewerCurrentLabel) reqViewerCurrentLabel.textContent = label || '';
    if (reqViewerCurrentBadge) {
        reqViewerCurrentBadge.innerHTML = '';
        if (status) {
            const badge = document.createElement('span');
            badge.className = 'cc-badge ' + requirementStatusBadgeClass(status);
            badge.textContent = status;
            reqViewerCurrentBadge.appendChild(badge);
        }
    }
}

function renderRequirementsList(requirements, userId, companyName) {
    if (!requirementsModalBody) return;
    requirementsModalBody.innerHTML = '';
    if (reqViewerCount) reqViewerCount.textContent = requirements && requirements.length ? '(' + requirements.length + ')' : '';

    if (!requirements || requirements.length === 0) {
        const p = document.createElement('div');
        p.className = 'req-nav-empty';
        p.textContent = 'This company has not submitted any compliance requirements yet.';
        requirementsModalBody.appendChild(p);
        return;
    }

    requirements.forEach(function(req, reqIdx) {
        const item = document.createElement('div');
        item.className = 'req-nav-item';
        item.dataset.reqIdx = reqIdx;

        const btn = document.createElement('button');
        btn.type = 'button';
        btn.className = 'req-nav-btn';

        const title = document.createElement('span');
        title.className = 'req-nav-title';
        title.textContent = req.label;

        const meta = document.createElement('span');
        meta.className = 'req-nav-meta';
        const badge = document.createElement('span');
        badge.className = 'cc-badge ' + requirementStatusBadgeClass(req.status);
        badge.textContent = req.status;
        const count = document.createElement('span');
        const fileCount = (req.files || []).length;
        count.textContent = fileCount === 0 ? 'No file submitted yet' : (fileCount + ' file' + (fileCount === 1 ? '' : 's'));
        meta.appendChild(badge);
        meta.appendChild(count);

        btn.appendChild(title);
        btn.appendChild(meta);
        btn.addEventListener('click', function() { selectRequirementInViewer(reqIdx, 0); });
        item.appendChild(btn);

        /* A requirement with several files gets one sub-entry per file. */
        if (fileCount > 1) {
            const filesWrap = document.createElement('div');
            filesWrap.className = 'req-nav-files';
            req.files.forEach(function(f, fileIdx) {
                const fb = document.createElement('button');
                fb.type = 'button';
                fb.className = 'req-nav-file-btn';
                fb.dataset.fileIdx = fileIdx;
                fb.innerHTML = '<i class="fas fa-file-alt"></i> ';
                fb.appendChild(document.createTextNode('File ' + (fileIdx + 1)));
                fb.addEventListener('click', function(e) {
                    e.stopPropagation();
                    selectRequirementInViewer(reqIdx, fileIdx);
                });
                filesWrap.appendChild(fb);
            });
            item.appendChild(filesWrap);
        }

        requirementsModalBody.appendChild(item);
    });
}

function selectRequirementInViewer(reqIdx, fileIdx) {
    const req = reqViewerState.requirements[reqIdx];
    if (!req) return;
    const files = req.files || [];
    if (fileIdx < 0 || fileIdx >= files.length) fileIdx = 0;
    reqViewerState.reqIdx = reqIdx;
    reqViewerState.fileIdx = files.length ? fileIdx : -1;

    /* Highlight the active requirement / file in the left panel. */
    if (requirementsModalBody) {
        requirementsModalBody.querySelectorAll('.req-nav-item').forEach(function(el) {
            const isActive = parseInt(el.dataset.reqIdx, 10) === reqIdx;
            el.classList.toggle('active', isActive);
            el.querySelectorAll('.req-nav-file-btn').forEach(function(fb) {
                fb.classList.toggle('active', isActive && parseInt(fb.dataset.fileIdx, 10) === fileIdx);
            });
            if (isActive && typeof el.scrollIntoView === 'function') el.scrollIntoView({ block: 'nearest' });
        });
    }

    const labelText = req.label + (files.length > 1 ? ' (File ' + (fileIdx + 1) + ' of ' + files.length + ')' : '');
    setReqViewerHeader(labelText, req.status);

    if (files.length === 0) {
        setReqViewerPlaceholder('<i class="fas fa-file-excel req-placeholder-icon"></i>', 'No file has been submitted yet for "' + req.label + '". Choose another requirement from the list on the left.');
        return;
    }

    const url = '?view_requirement_file=' + files[fileIdx].id + '&req_user_id=' + reqViewerState.userId;
    if (reqViewerPlaceholder) reqViewerPlaceholder.style.display = 'none';
    const isImage = String(files[fileIdx].mime || '').indexOf('image/') === 0;
    if (isImage && reqViewerImageWrap && reqViewerImage) {
        /* Images → centered, scaled-to-fit preview. */
        if (reqViewerFrame) { reqViewerFrame.style.display = 'none'; reqViewerFrame.src = 'about:blank'; }
        reqViewerImage.src = url;
        reqViewerImageWrap.style.display = 'flex';
    } else if (reqViewerFrame) {
        /* PDFs (and anything else) → the browser's own viewer, full size. */
        hideReqViewerImage();
        reqViewerFrame.style.display = 'block';
        reqViewerFrame.src = url;
    }
}

function openRequirementsModal(userId, companyName) {
    if (!requirementsModal || !requirementsModalBody) return;
    const token = ++reqViewerRequestToken;
    reqViewerState = { userId: userId, companyName: companyName, requirements: [], reqIdx: -1, fileIdx: -1 };

    if (requirementsModalTitle) requirementsModalTitle.textContent = companyName + ' — Requirements';
    setReqViewerHeader('', '');
    if (reqViewerCount) reqViewerCount.textContent = '';
    requirementsModalBody.innerHTML = '<div class="global-loading-spinner" style="margin:20px auto;"></div>';
    setReqViewerPlaceholder('<div class="global-loading-spinner"></div>', 'Loading requirements…');
    requirementsModal.classList.add('open');
    document.body.style.overflow = 'hidden';

    const formData = new FormData();
    formData.append('ajax_fetch_requirements', '1');
    formData.append('user_id', userId);

    fetch(window.location.pathname + window.location.search, {
        method: 'POST',
        headers: { 'X-Requested-With': 'XMLHttpRequest' },
        body: formData
    })
    .then(function(r) { return r.json(); })
    .then(function(data) {
        if (token !== reqViewerRequestToken) return; // viewer was closed / reopened meanwhile
        if (!data.success) {
            requirementsModalBody.innerHTML = '';
            const p = document.createElement('div');
            p.className = 'req-nav-empty';
            p.textContent = data.message || 'Could not load this company\'s requirements.';
            requirementsModalBody.appendChild(p);
            setReqViewerPlaceholder('<i class="fas fa-exclamation-circle req-placeholder-icon"></i>', data.message || 'Could not load this company\'s requirements.');
            return;
        }
        reqViewerState.requirements = data.requirements || [];
        renderRequirementsList(reqViewerState.requirements, userId, companyName);

        if (reqViewerState.requirements.length === 0) {
            setReqViewerPlaceholder('<i class="fas fa-folder-open req-placeholder-icon"></i>', 'This company has not submitted any compliance requirements yet.');
            return;
        }
        /* Open the first requirement that actually has a file straight away. */
        let firstWithFile = reqViewerState.requirements.findIndex(function(r) { return r.files && r.files.length > 0; });
        if (firstWithFile === -1) firstWithFile = 0;
        selectRequirementInViewer(firstWithFile, 0);
    })
    .catch(function() {
        if (token !== reqViewerRequestToken) return;
        requirementsModalBody.innerHTML = '';
        const p = document.createElement('div');
        p.className = 'req-nav-empty';
        p.textContent = 'Something went wrong while loading requirements. Please try again.';
        requirementsModalBody.appendChild(p);
        setReqViewerPlaceholder('<i class="fas fa-exclamation-circle req-placeholder-icon"></i>', 'Something went wrong while loading requirements. Please try again.');
    });
}

function closeRequirementsModal() {
    if (!requirementsModal) return;
    reqViewerRequestToken++;
    requirementsModal.classList.remove('open');
    document.body.style.overflow = '';
    if (reqViewerFrame) { reqViewerFrame.src = 'about:blank'; reqViewerFrame.style.display = 'none'; }
    hideReqViewerImage();
}

if (requirementsModalCloseBtn) {
    requirementsModalCloseBtn.addEventListener('click', closeRequirementsModal);
}

/* Keyboard: Esc closes the viewer; Up/Down arrows switch requirement. */
document.addEventListener('keydown', function(e) {
    if (!requirementsModal || !requirementsModal.classList.contains('open')) return;
    if (e.key === 'Escape') { closeRequirementsModal(); return; }
    const total = reqViewerState.requirements.length;
    if (!total) return;
    const tag = (e.target && e.target.tagName) || '';
    if (tag === 'INPUT' || tag === 'SELECT' || tag === 'TEXTAREA') return;
    if (e.key === 'ArrowDown') {
        e.preventDefault();
        selectRequirementInViewer(Math.min(total - 1, reqViewerState.reqIdx + 1), 0);
    } else if (e.key === 'ArrowUp') {
        e.preventDefault();
        selectRequirementInViewer(Math.max(0, reqViewerState.reqIdx - 1), 0);
    }
});

/* ── NEW: Add Company modal open/close ── */
const addCompanyBtn = document.getElementById('addCompanyBtn');
const addCompanyModal = document.getElementById('addCompanyModal');
const closeAddCompanyBtn = document.getElementById('closeAddCompanyBtn');
const cancelAddCompanyBtn = document.getElementById('cancelAddCompanyBtn');
const addCompanyForm = document.getElementById('addCompanyForm');
function openAddCompanyModal() { addCompanyModal.classList.add('open'); }
function closeAddCompanyModal() {
    addCompanyModal.classList.remove('open');
    if (addCompanyForm) addCompanyForm.reset();
    toggleCustomPositionInput();
}
if (addCompanyBtn) addCompanyBtn.addEventListener('click', openAddCompanyModal);
if (closeAddCompanyBtn) closeAddCompanyBtn.addEventListener('click', closeAddCompanyModal);
if (cancelAddCompanyBtn) cancelAddCompanyBtn.addEventListener('click', closeAddCompanyModal);
// UPDATED (this adjustment): clicking outside the Add Company form no longer closes it (and no longer clears what was typed) —
// it closes only with its × or Cancel button.

/* ── NEW: Position "other" toggle (replaces the removed Company Type toggle) ── */
const positionSelect = document.getElementById('positionSelect');
const customPositionGroup = document.getElementById('customPositionGroup');
const customPositionInput = document.getElementById('customPosition');
function toggleCustomPositionInput() {
    if (!positionSelect || !customPositionGroup) return;
    if (positionSelect.value === 'other') {
        customPositionGroup.classList.add('show');
        customPositionInput.setAttribute('required', 'required');
    } else {
        customPositionGroup.classList.remove('show');
        customPositionInput.removeAttribute('required');
        customPositionInput.value = '';
    }
}
if (positionSelect) positionSelect.addEventListener('change', toggleCustomPositionInput);

/* ══════════════════════════════════════════════════════════
   NEW: Edit Imported Company modal — non-table-dependent setup
   (position "other" toggle, telephone digit filter, admin-MOA file
   picker validation). The open/populate function is defined here; the
   toolbar Edit button flow that opens it lives further below, after
   companyTableSection exists, since it reads the checked checkbox's
   data-* attributes from that container.
   ══════════════════════════════════════════════════════════ */
const editCompanyModal = document.getElementById('editCompanyModal');
const closeEditCompanyBtn = document.getElementById('closeEditCompanyBtn');
const cancelEditCompanyBtn = document.getElementById('cancelEditCompanyBtn');
const editCompanyForm = document.getElementById('editCompanyForm');
const editImportId = document.getElementById('editImportId');
/* NEW (cross-table sync revision): registered-company edit reference + modal title */
const editUserId = document.getElementById('editUserId');
const editCompanyModalTitleText = document.getElementById('editCompanyModalTitleText');
const editCompanyName = document.getElementById('editCompanyName');
const editCompanyAddress = document.getElementById('editCompanyAddress');
const editTelephoneInput = document.getElementById('editTelephoneInput');
const editContactFirstName = document.getElementById('editContactFirstName');
const editContactMiddleName = document.getElementById('editContactMiddleName');
const editContactLastName = document.getElementById('editContactLastName');
const editEmailInput = document.getElementById('editEmailInput');
const editPositionSelect = document.getElementById('editPositionSelect');
const editCustomPositionGroup = document.getElementById('editCustomPositionGroup');
const editCustomPosition = document.getElementById('editCustomPosition');
/* NEW (Company Type revision) */
const editCompanyTypeSelect = document.getElementById('editCompanyTypeSelect');
const editStatusNewRadio = document.getElementById('editStatusNewRadio');
const editStatusExistingRadio = document.getElementById('editStatusExistingRadio');
const editAdminMoaFile = document.getElementById('editAdminMoaFile');
const editAdminMoaFileSelectedName = document.getElementById('editAdminMoaFileSelectedName');
/* NEW: the wrapper around the "Replace Admin Copy of MOA" field in the
   Edit modal — toggled based on Company Status, mirroring the Add
   modal's moaUploadGroup behavior (see toggleEditMoaUploadGroup below). */
const editAdminMoaGroup = document.getElementById('editAdminMoaGroup');

function toggleEditCustomPositionInput() {
    if (!editPositionSelect || !editCustomPositionGroup) return;
    if (editPositionSelect.value === 'other') {
        editCustomPositionGroup.classList.add('show');
        editCustomPosition.setAttribute('required', 'required');
    } else {
        editCustomPositionGroup.classList.remove('show');
        editCustomPosition.removeAttribute('required');
        editCustomPosition.value = '';
    }
}
if (editPositionSelect) editPositionSelect.addEventListener('change', toggleEditCustomPositionInput);

/* ══════════════════════════════════════════════════════════
   "Replace Admin Copy of MOA" only shows for "Existing Company (has
   MOA)" — this field writes only to admin_moa_document — it is never
   used for, and never touches, the company's own moa_document.
   ══════════════════════════════════════════════════════════ */
function toggleEditMoaUploadGroup() {
    if (!editAdminMoaGroup) return;
    if (editStatusExistingRadio && editStatusExistingRadio.checked) {
        editAdminMoaGroup.style.display = 'block';
        requestAnimationFrame(() => editAdminMoaGroup.classList.add('show'));
    } else {
        editAdminMoaGroup.classList.remove('show');
        if (editAdminMoaFile) editAdminMoaFile.value = '';
        if (editAdminMoaFileSelectedName) { editAdminMoaFileSelectedName.textContent = ''; editAdminMoaFileSelectedName.style.display = 'none'; }
        setTimeout(() => {
            if (!editStatusExistingRadio || !editStatusExistingRadio.checked) editAdminMoaGroup.style.display = 'none';
        }, 220);
    }
}
if (editStatusNewRadio) editStatusNewRadio.addEventListener('change', toggleEditMoaUploadGroup);
if (editStatusExistingRadio) editStatusExistingRadio.addEventListener('change', toggleEditMoaUploadGroup);

function closeEditCompanyModal() {
    if (editCompanyModal) editCompanyModal.classList.remove('open');
    if (editCompanyForm) editCompanyForm.reset();
    /* NEW: a hidden input keeps its value across form.reset(), so clear
       the registered-company reference explicitly (openEditCompanyModal
       also always overwrites it on the next open). */
    if (editUserId) editUserId.value = '';
    toggleEditCustomPositionInput();
    toggleEditMoaUploadGroup();
    if (editAdminMoaFileSelectedName) { editAdminMoaFileSelectedName.textContent = ''; editAdminMoaFileSelectedName.style.display = 'none'; }
}
if (closeEditCompanyBtn) closeEditCompanyBtn.addEventListener('click', closeEditCompanyModal);
if (cancelEditCompanyBtn) cancelEditCompanyBtn.addEventListener('click', closeEditCompanyModal);
/* ══════════════════════════════════════════════════════════
   The Edit modal is deliberately NOT closed by clicking its backdrop —
   only the explicit "×" button, the "Cancel" button, or a successful
   save (handled in the editCompanyForm submit handler below) will close
   it, so in-progress edits can never be lost by an accidental outside
   click (e.g. selecting/dragging text inside a field and releasing the
   mouse just outside the modal box). The Add Company modal's
   backdrop-click behavior is left exactly as it was.
   ══════════════════════════════════════════════════════════ */

if (editAdminMoaFile) {
    editAdminMoaFile.addEventListener('change', function() {
        if (this.files && this.files[0]) {
            const file = this.files[0];
            const ext = file.name.split('.').pop().toLowerCase();
            if (ext !== 'pdf') {
                alert('Only PDF files are accepted for the admin copy of the MOA.');
                this.value = '';
                if (editAdminMoaFileSelectedName) { editAdminMoaFileSelectedName.textContent = ''; editAdminMoaFileSelectedName.style.display = 'none'; }
                return;
            }
            if (editAdminMoaFileSelectedName) {
                editAdminMoaFileSelectedName.textContent = file.name;
                editAdminMoaFileSelectedName.style.display = 'block';
            }
        } else if (editAdminMoaFileSelectedName) {
            editAdminMoaFileSelectedName.textContent = '';
            editAdminMoaFileSelectedName.style.display = 'none';
        }
    });
}

if (editTelephoneInput) {
    editTelephoneInput.addEventListener('input', function() {
        const digitsOnly = this.value.replace(/[^0-9]/g, '');
        if (digitsOnly !== this.value) this.value = digitsOnly;
    });
    editTelephoneInput.addEventListener('keypress', function(e) {
        if (e.key.length === 1 && !/[0-9]/.test(e.key)) e.preventDefault();
    });
    editTelephoneInput.addEventListener('paste', function(e) {
        e.preventDefault();
        const pasted = (e.clipboardData || window.clipboardData).getData('text');
        this.value = (this.value + pasted).replace(/[^0-9]/g, '');
    });
}

/* UPDATED: openEditCompanyModal(source) now accepts ANY element that
   carries the same data-import-id / data-company / data-address / ...
   attributes the old per-row Edit button used to carry. Since the
   Actions column has been removed, the caller is now always the row's
   own .row-select-checkbox (see the toolbar Edit button flow further
   below) — but the function itself doesn't care what element it's
   given, only that it exposes getAttribute(), so nothing about its
   internal logic needed to change (aside from the new Company Type
   field populated below). */
function openEditCompanyModal(source) {
    if (!source || !editCompanyModal) return;
    /* NEW (cross-table sync revision): a registered company's checkbox
       carries data-user-id (and data-source="registered") instead of
       data-import-id. Exactly one of the two hidden references is filled. */
    const isRegisteredSource = source.getAttribute('data-source') === 'registered';
    if (editImportId) editImportId.value = isRegisteredSource ? '' : (source.getAttribute('data-import-id') || '');
    if (editUserId) editUserId.value = isRegisteredSource ? (source.getAttribute('data-user-id') || '') : '';
    if (editCompanyModalTitleText) editCompanyModalTitleText.textContent = isRegisteredSource ? 'Edit Company' : 'Edit Imported Company';
    if (editCompanyName) editCompanyName.value = source.getAttribute('data-company') || '';
    if (editCompanyAddress) editCompanyAddress.value = source.getAttribute('data-address') || '';
    if (editTelephoneInput) editTelephoneInput.value = (source.getAttribute('data-telephone') || '').replace(/[^0-9]/g, '');
    if (editContactFirstName) editContactFirstName.value = source.getAttribute('data-first') || '';
    if (editContactMiddleName) editContactMiddleName.value = source.getAttribute('data-middle') || '';
    if (editContactLastName) editContactLastName.value = source.getAttribute('data-last') || '';
    if (editEmailInput) editEmailInput.value = source.getAttribute('data-email') || '';

    const posVal = source.getAttribute('data-position') || '';
    if (editPositionSelect) {
        const matchingOption = Array.from(editPositionSelect.options).find(o => o.value === posVal);
        if (posVal && matchingOption) {
            editPositionSelect.value = posVal;
            toggleEditCustomPositionInput();
        } else if (posVal) {
            editPositionSelect.value = 'other';
            toggleEditCustomPositionInput();
            if (editCustomPosition) editCustomPosition.value = posVal;
        } else {
            editPositionSelect.value = '';
            toggleEditCustomPositionInput();
        }
    }

    /* NEW (Company Type revision) */
    if (editCompanyTypeSelect) {
        editCompanyTypeSelect.value = source.getAttribute('data-company-type') || '';
        /* Registered accounts that never had a Company Type recorded may
           leave it blank (the server then keeps it unchanged); imported
           rows keep the original required behavior. */
        editCompanyTypeSelect.required = !isRegisteredSource;
    }

    const reqTypeVal = source.getAttribute('data-request-type') || '';
    if (reqTypeVal === 'Existing' && editStatusExistingRadio) editStatusExistingRadio.checked = true;
    else if (isRegisteredSource && reqTypeVal !== 'New') {
        /* A registered company with NO Request Type on record: leave both
           radios unselected so simply saving other edits never silently
           assigns it one (the table's inline "Set Request Type" control
           remains available for that). */
        if (editStatusNewRadio) editStatusNewRadio.checked = false;
        if (editStatusExistingRadio) editStatusExistingRadio.checked = false;
    }
    else if (editStatusNewRadio) editStatusNewRadio.checked = true;
    /* NEW: sync the Admin Copy of MOA section's visibility with whatever
       status was just populated above, so it correctly shows only for
       "Existing Company (has MOA)" entries the instant the modal opens. */
    toggleEditMoaUploadGroup();

    if (editAdminMoaFile) editAdminMoaFile.value = '';
    if (editAdminMoaFileSelectedName) { editAdminMoaFileSelectedName.textContent = ''; editAdminMoaFileSelectedName.style.display = 'none'; }

    editCompanyModal.classList.add('open');
}

/* ══════════════════════════════════════════════════════════
   NEW: auto-capitalize the first letter of each word as the admin
   types into the company/contact/position text fields, and restrict
   the telephone field to digits only — typed letters simply never
   make it into the field's value.
   ══════════════════════════════════════════════════════════ */
function capitalizeWordsOnInput(e) {
    const input = e.target;
    const start = input.selectionStart;
    const end = input.selectionEnd;
    const val = input.value;
    const newVal = val.replace(/(^|\s)([a-z])/g, (m, boundary, letter) => boundary + letter.toUpperCase());
    if (newVal !== val) {
        input.value = newVal;
        input.setSelectionRange(start, end);
    }
}
document.querySelectorAll('.cap-words-input').forEach(el => {
    el.addEventListener('input', capitalizeWordsOnInput);
});

const telephoneInput = document.getElementById('telephoneInput');
if (telephoneInput) {
    telephoneInput.addEventListener('input', function() {
        const digitsOnly = this.value.replace(/[^0-9]/g, '');
        if (digitsOnly !== this.value) this.value = digitsOnly;
    });
    telephoneInput.addEventListener('keypress', function(e) {
        if (e.key.length === 1 && !/[0-9]/.test(e.key)) e.preventDefault();
    });
    telephoneInput.addEventListener('paste', function(e) {
        e.preventDefault();
        const pasted = (e.clipboardData || window.clipboardData).getData('text');
        this.value = (this.value + pasted).replace(/[^0-9]/g, '');
    });
}

/* ══════════════════════════════════════════════════════════
   NEW: floating toast used to confirm/report the result of adding a
   company, editing an imported company, or deleting selected entries,
   without a page reload.
   ══════════════════════════════════════════════════════════ */
function showFloatingAlert(message, type) {
    const container = document.getElementById('floatingAlertContainer');
    if (!container) return;
    const alertBox = document.createElement('div');
    alertBox.className = 'floating-alert floating-alert-' + (type === 'success' ? 'success' : 'error');
    alertBox.innerHTML = '<i class="fas fa-' + (type === 'success' ? 'check-circle' : 'exclamation-circle') + '"></i> ' + message;
    container.appendChild(alertBox);
    requestAnimationFrame(() => alertBox.classList.add('show'));
    setTimeout(() => {
        alertBox.classList.remove('show');
        setTimeout(() => alertBox.remove(), 300);
    }, 4000);
}

/* ══════════════════════════════════════════════════════════
   NEW: the Add Company form now submits via fetch() so successfully
   adding a company (or hitting a validation error) never reloads the
   page — the modal just closes (on success), a toast reports the
   result, and the table below refreshes itself in place. If JS fails
   to load for any reason, the form still has method="POST" and will
   fall back to the original full-page redirect behavior.

   NEW (automatic account creation): the server has already attempted
   to create the company's login account in the very same request (see
   the add_company_manual handler). If that attempt didn't cleanly
   succeed outright (data.account_outcome !== 'created' — e.g. it was
   "skipped" because the email was missing/invalid, or genuinely
   "failed"), the "Creating Company Accounts" progress modal is shown
   with that outcome so the admin sees exactly what happened and can
   follow up (e.g. edit the entry to fix the email). A clean "created"
   outcome needs no extra modal — the floating success toast is enough.
   ══════════════════════════════════════════════════════════ */
if (addCompanyForm) {
    addCompanyForm.addEventListener('submit', function(e) {
        e.preventDefault();
        const submitBtn = addCompanyForm.querySelector('.btn-submit');
        const originalBtnHtml = submitBtn ? submitBtn.innerHTML : '';
        if (submitBtn) {
            submitBtn.disabled = true;
            submitBtn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Adding...';
        }
        showGlobalLoading('Adding company');
        const formData = new FormData(addCompanyForm);
        /* FIX: new FormData(addCompanyForm) does NOT include the
           name/value of the <button type="submit" name="add_company_manual">
           because the button was never actually "clicked" as the form's
           submitter (the submit event was intercepted and prevented).
           Without this key present in the POST body, PHP's
           isset($_POST['add_company_manual']) check was always false,
           so the entire handler — and therefore the JSON response —
           was skipped, and the server fell through to rendering the
           full HTML page instead. That's why r.json() failed and the
           generic "Something went wrong" error always fired, for both
           New and Existing companies. Explicitly appending this flag
           guarantees the server sees exactly what it expects. */
        formData.append('add_company_manual', '1');
        fetch(window.location.pathname + window.location.search, {
            method: 'POST',
            headers: { 'X-Requested-With': 'XMLHttpRequest' },
            body: formData
        })
        .then(r => r.json())
        .then(data => {
            if (submitBtn) { submitBtn.disabled = false; submitBtn.innerHTML = originalBtnHtml; }
            /* UPDATED (Add success popup removal + success loading page —
               same as the Edit/Delete flows): the floating "Company added
               successfully!" notification is no longer shown. A successful
               add now turns the loader that is already showing ("Adding
               company") into a check icon + "Company Added" message while
               the table refreshes in place. Errors still show the red
               notification exactly as before.
               showGlobalSuccess() releases the "Adding company" loader
               usage itself, so hideGlobalLoading() is only called on the
               error path. */
            if (data.success) {
                closeAddCompanyModal();
                showGlobalSuccess('Company Added', data.message, function() {
                    fetchCompanyTable(1);
                });
                /* Only pop the progress modal when the automatic
                   account-creation attempt needed follow-up (skipped or
                   failed) — a clean "created" outcome is already fully
                   reflected by the success loading page above and the
                   row disappearing from the imported list. It is opened
                   only after the success loading page has finished
                   fading out so the two never overlap. */
                if (data.account_outcome && data.account_outcome !== 'created' && data.import_id) {
                    setTimeout(function() { runAutoAccountCreation([data.import_id]); }, 2100);
                }
            } else {
                hideGlobalLoading();
                showFloatingAlert(data.message, 'error');
            }
        })
        .catch(() => {
            if (submitBtn) { submitBtn.disabled = false; submitBtn.innerHTML = originalBtnHtml; }
            hideGlobalLoading();
            showFloatingAlert('Something went wrong while adding the company. Please try again.', 'error');
        });
    });
}

/* ══════════════════════════════════════════════════════════
   UPDATED (Company Type / Request Type import filter revision):
   choosing a file no longer auto-submits the import immediately. It
   first sends the file to the read-only "detect classifications"
   endpoint (detect_company_import_classifications, handled server-side
   right before the real import handler), which reports back every
   distinct Request Type / Company Type actually found in that file's
   data rows. Those are shown as checkboxes in the
   "importClassificationModal" picker — every box starts checked, so an
   admin who just wants the whole file imported (the exact previous
   behavior) can simply click "Import Selected" — and only once that's
   confirmed does the real import (same server handler/response/
   messaging as before, completely unchanged) actually get POSTed, now
   additionally carrying the admin's selection so only matching rows are
   imported (see the classification-filter check server-side). The
   automatic account-creation-after-import behavior
   (window.autoCreateAccountImportIds, window.autoCreateAccountPreSkippedDetails,
   runAutoAccountCreation()) is unaffected either way.
   ══════════════════════════════════════════════════════════ */
const companyFileInput = document.getElementById('company_import_file');
const companyImportForm = document.getElementById('companyImportForm');
const companyImportTriggerBtn = document.getElementById('companyImportTriggerBtn');
const companyImportBtnLabel = document.getElementById('companyImportBtnLabel');
const importClassificationModal = document.getElementById('importClassificationModal');
const importClassificationSummary = document.getElementById('importClassificationSummary');
const importReqTypeOptions = document.getElementById('importReqTypeOptions');
const importCompanyTypeOptions = document.getElementById('importCompanyTypeOptions');
const importClassificationCancelBtn = document.getElementById('importClassificationCancelBtn');
const importClassificationConfirmBtn = document.getElementById('importClassificationConfirmBtn');

const REQUEST_TYPE_LABELS = { 'New': 'New Company', 'Existing': 'Existing Company' };
const COMPANY_TYPE_LABELS = { 'Public': 'Public', 'Private': 'Private', '': 'Unspecified / Not Provided' };

function buildImportClassificationCheckboxes(container, values, labelMap, inputName) {
    if (!container) return;
    container.innerHTML = '';
    values.forEach(function(val) {
        const label = document.createElement('label');
        label.className = 'import-classification-option';
        const box = document.createElement('input');
        box.type = 'checkbox';
        box.checked = true;
        box.value = val;
        box.dataset.importClassification = inputName;
        const span = document.createElement('span');
        span.textContent = (labelMap[val] !== undefined) ? labelMap[val] : (val || 'Unspecified');
        label.appendChild(box);
        label.appendChild(span);
        container.appendChild(label);
    });
}

function resetImportClassificationPicker() {
    if (importClassificationModal) importClassificationModal.classList.remove('open');
    if (companyFileInput) companyFileInput.value = '';
}

function runImportClassificationDetection(file) {
    showGlobalLoading('Reading file');
    const formData = new FormData();
    formData.append('detect_company_import_classifications', '1');
    formData.append('company_import_file', file);

    fetch(window.location.pathname + window.location.search, {
        method: 'POST',
        headers: { 'X-Requested-With': 'XMLHttpRequest' },
        body: formData
    })
    .then(function(r) { return r.json(); })
    .then(function(data) {
        hideGlobalLoading();
        if (!data.success) {
            // UPDATED (Import/Export result screen revision): result screen instead of alert().
            showGlobalResult('error', 'Import Failed', globalResultPlainText(data.message) || 'Could not read the selected file.', 0);
            resetImportClassificationPicker();
            return;
        }
        buildImportClassificationCheckboxes(importReqTypeOptions, data.request_types || ['New'], REQUEST_TYPE_LABELS, 'request_type');
        buildImportClassificationCheckboxes(importCompanyTypeOptions, data.company_types || [''], COMPANY_TYPE_LABELS, 'company_type');
        if (importClassificationSummary) {
            const rowCount = data.row_count || 0;
            importClassificationSummary.textContent = 'This file has ' + rowCount + ' compan' + (rowCount === 1 ? 'y' : 'ies') + ' with the classifications below. Only the ones you keep checked will be imported — everything else in the file will be skipped.';
        }
        if (importClassificationModal) importClassificationModal.classList.add('open');
    })
    .catch(function() {
        hideGlobalLoading();
        // UPDATED (Import/Export result screen revision): result screen instead of alert().
        showGlobalResult('error', 'Import Failed', 'Something went wrong while reading the file. Please try again.', 0);
        resetImportClassificationPicker();
    });
}

if (companyFileInput && companyImportForm) {
    companyFileInput.addEventListener('change', function() {
        if (this.files && this.files[0]) {
            const file = this.files[0], ext = file.name.split('.').pop().toLowerCase(), sizeMB = (file.size/(1024*1024)).toFixed(2);
            /* UPDATED (Import/Export result screen revision): these two
               checks now report through the "Import Failed" result screen
               instead of a browser alert(); the checks themselves are
               unchanged. */
            if (ext !== 'xlsx') { showGlobalResult('error', 'Import Failed', 'Only XLSX files are allowed.', 0); this.value = ''; return; }
            if (file.size > 100*1024*1024) { showGlobalResult('error', 'Import Failed', 'Max file size is 100MB. Your file is ' + sizeMB + 'MB.', 0); this.value = ''; return; }
            runImportClassificationDetection(file);
        }
    });
}

// NEW (this adjustment): the header's × does exactly what Cancel does
const importClassificationCloseBtn = document.getElementById('importClassificationCloseBtn');
if (importClassificationCloseBtn && importClassificationCancelBtn) importClassificationCloseBtn.addEventListener('click', function() { importClassificationCancelBtn.click(); });
if (importClassificationCancelBtn) {
    importClassificationCancelBtn.addEventListener('click', function() {
        resetImportClassificationPicker();
    });
}

if (importClassificationConfirmBtn) {
    importClassificationConfirmBtn.addEventListener('click', function() {
        const checkedReqTypes = importReqTypeOptions ? Array.from(importReqTypeOptions.querySelectorAll('input[type="checkbox"]:checked')) : [];
        const checkedCompanyTypes = importCompanyTypeOptions ? Array.from(importCompanyTypeOptions.querySelectorAll('input[type="checkbox"]:checked')) : [];

        if (checkedReqTypes.length === 0 || checkedCompanyTypes.length === 0) {
            alert('Please keep at least one Request Type and one Company Type checked, or click Cancel to discard this import.');
            return;
        }

        // Clear any hidden classification inputs left over from a previous attempt.
        companyImportForm.querySelectorAll('.import-classification-hidden-input').forEach(function(el) { el.remove(); });

        checkedReqTypes.forEach(function(box) {
            const hidden = document.createElement('input');
            hidden.type = 'hidden';
            hidden.name = 'selected_request_types[]';
            hidden.value = box.value;
            hidden.className = 'import-classification-hidden-input';
            companyImportForm.appendChild(hidden);
        });
        checkedCompanyTypes.forEach(function(box) {
            const hidden = document.createElement('input');
            hidden.type = 'hidden';
            hidden.name = 'selected_company_types[]';
            hidden.value = box.value;
            hidden.className = 'import-classification-hidden-input';
            companyImportForm.appendChild(hidden);
        });

        if (importClassificationModal) importClassificationModal.classList.remove('open');
        if (companyImportForm.requestSubmit) companyImportForm.requestSubmit();
        else companyImportForm.submit();
    });
}

if (companyImportForm) {
    companyImportForm.addEventListener('submit', function() {
        if (companyImportTriggerBtn) { companyImportTriggerBtn.style.pointerEvents = 'none'; companyImportTriggerBtn.style.opacity = '0.7'; }
        if (companyImportBtnLabel) companyImportBtnLabel.textContent = 'Processing...';
        showGlobalLoading('Importing companies');
    });
}


/* ══════════════════════════════════════════════════════════
   UPDATED (Export exclusion revision): clicking "Export to Excel" no
   longer downloads immediately — it first opens exportChoiceModal
   asking whether any companies should be left out of the file.
     - "None, Proceed With Export" → exports everything, exactly the
       same plain ?export=xlsx request (and the same spinner
       feedback) this button always triggered before this revision —
       no floating-alert notification is shown.
     - "Yes" → closes the choice modal and turns on a checkbox
       selection mode over the table itself (see
       enterExportExcludeSelectionMode() further below) so the admin
       can pick specific companies to exclude; confirming that
       selection (the "Export (Excluding N)" button) runs the same
       download, just with those companies filtered out server-side.
   triggerExportDownload() is the single place that actually starts the
   download either way, so the spinner feedback always behaves
   identically regardless of which path was taken.
   ══════════════════════════════════════════════════════════ */
const exportChoiceModal = document.getElementById('exportChoiceModal');
const exportChoiceNoneBtn = document.getElementById('exportChoiceNoneBtn');
const exportChoiceYesBtn = document.getElementById('exportChoiceYesBtn');
const exportConfirmBtn = document.getElementById('exportConfirmBtn');
/* Outer-scope reference to the plain Export button, distinct from the
   locally-scoped one inside the click-binding IIFE below, so the
   selection-mode functions further down (enterExportExcludeSelectionMode()/
   exitSelectionMode()) can show/hide it too. */
const companyExportBtnEl = document.getElementById('companyExportBtn');

function triggerExportDownload(excludeUserIds, excludeImportIds) {
    const companyExportBtn = document.getElementById('companyExportBtn');
    const btnForFeedback = (activeSelectionMode === 'export' && exportConfirmBtn) ? exportConfirmBtn : companyExportBtn;
    const origHtml = btnForFeedback ? btnForFeedback.innerHTML : '';

    if (btnForFeedback) {
        btnForFeedback.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Preparing...';
        btnForFeedback.style.pointerEvents = 'none';
    }
    showGlobalLoading('Preparing export');
    /* UPDATED: the "Preparing your Excel file — download will start
       shortly." floating notification is no longer shown on export.
       showFloatingAlert() itself is untouched and still used elsewhere. */

    excludeUserIds = excludeUserIds || [];
    excludeImportIds = excludeImportIds || [];

    /* UPDATED (Import/Export result screen revision): the file is now
       fetched in the background instead of navigating to it, so the page
       knows whether the export actually succeeded. The request itself is
       the same as before — a plain GET ?export=xlsx when nothing is
       excluded, or a POST carrying export=xlsx plus exclude_user_ids[] /
       exclude_import_ids[] (never a long URL) when something is. The
       loading screen stays up until the file is ready, then either the
       "Export Successful" or the "Export Failed" result screen is shown.
       The button's inline spinner is restored when the request finishes
       instead of after a fixed 3.5s. */
    let fetchUrl = '?export=xlsx';
    const fetchOptions = { method: 'GET', headers: { 'X-Requested-With': 'XMLHttpRequest' }, credentials: 'same-origin' };

    if (excludeUserIds.length > 0 || excludeImportIds.length > 0) {
        const formData = new FormData();
        formData.append('export', 'xlsx');
        excludeUserIds.forEach(function(id) { formData.append('exclude_user_ids[]', id); });
        excludeImportIds.forEach(function(id) { formData.append('exclude_import_ids[]', id); });
        fetchUrl = window.location.pathname + window.location.search;
        fetchOptions.method = 'POST';
        fetchOptions.body = formData;
    }

    const excludedCount = excludeUserIds.length + excludeImportIds.length;

    function finishExportFeedback() {
        if (btnForFeedback) {
            btnForFeedback.innerHTML = origHtml;
            btnForFeedback.style.pointerEvents = '';
        }
        hideGlobalLoading();
    }

    fetch(fetchUrl, fetchOptions)
    .then(function(response) {
        const contentType = (response.headers.get('Content-Type') || '').toLowerCase();
        if (response.ok && contentType.indexOf('spreadsheetml') !== -1) {
            const disposition = response.headers.get('Content-Disposition') || '';
            const nameMatch = disposition.match(/filename\*?=(?:UTF-8'')?"?([^";]+)"?/i);
            const fileName = nameMatch ? decodeURIComponent(nameMatch[1]) : 'companies_export.xlsx';
            return response.blob().then(function(blob) {
                if (!blob || blob.size === 0) throw new Error('The server returned an empty file.');
                return { blob: blob, fileName: fileName };
            });
        }
        // Not a spreadsheet: read the server's JSON error if there is one.
        return response.text().then(function(text) {
            let msg = '';
            try { const data = JSON.parse(text); if (data && data.message) msg = data.message; } catch (e) {}
            if (!msg) {
                msg = response.ok
                    ? 'The server did not return an Excel file. Your session may have expired — please refresh the page and try again.'
                    : 'The server responded with an error (' + response.status + '). Please try again.';
            }
            throw new Error(msg);
        });
    })
    .then(function(file) {
        const blobUrl = URL.createObjectURL(file.blob);
        const link = document.createElement('a');
        link.href = blobUrl;
        link.download = file.fileName;
        link.style.display = 'none';
        document.body.appendChild(link);
        link.click();
        setTimeout(function() { URL.revokeObjectURL(blobUrl); link.remove(); }, 1000);

        finishExportFeedback();
        showGlobalResult(
            'success',
            'Export Successful',
            'Your Excel file "' + file.fileName + '" has been downloaded' +
                (excludedCount > 0 ? ' (' + excludedCount + ' compan' + (excludedCount === 1 ? 'y' : 'ies') + ' excluded).' : '.'),
            3000
        );
    })
    .catch(function(err) {
        finishExportFeedback();
        const detail = (err && err.message && err.message !== 'Failed to fetch')
            ? err.message
            : 'Could not reach the server. Please check your connection and try again.';
        showGlobalResult('error', 'Export Failed', detail, 0);
    });
}

(function() {
    const companyExportBtn = document.getElementById('companyExportBtn');
    if (!companyExportBtn) return;
    companyExportBtn.addEventListener('click', function(e) {
        e.preventDefault();
        if (exportChoiceModal) exportChoiceModal.classList.add('open');
    });
})();

if (exportChoiceNoneBtn) {
    exportChoiceNoneBtn.addEventListener('click', function() {
        if (exportChoiceModal) exportChoiceModal.classList.remove('open');
        triggerExportDownload([], []);
    });
}

if (exportChoiceYesBtn) {
    exportChoiceYesBtn.addEventListener('click', function() {
        if (exportChoiceModal) exportChoiceModal.classList.remove('open');
        enterExportExcludeSelectionMode();
    });
}

if (exportChoiceModal) {
    exportChoiceModal.addEventListener('click', function(e) {
        if (e.target === exportChoiceModal) exportChoiceModal.classList.remove('open');
    });
}
// NEW (this adjustment): the × button cancels the export choice and closes the popup — the same as clicking outside it
const exportChoiceCloseBtn = document.getElementById('exportChoiceCloseBtn');
if (exportChoiceCloseBtn) {
    exportChoiceCloseBtn.addEventListener('click', function() {
        if (exportChoiceModal) exportChoiceModal.classList.remove('open');
    });
}

/* ══════════════════════════════════════════════════════════
   NEW: no-reload search / filter / pagination.
   Typing in Search (debounced), changing either dropdown, clicking a
   pagination link, or clicking "Reset" now all fetch just the table
   fragment (?ajax_table=1&...) and swap #companyTableSection's content
   in with a short fade/slide animation — the rest of the page (filter
   bar, cards, sidebar) never reloads. Without JavaScript, the same
   filter-bar/pagination links still work exactly as before via normal
   GET requests and full page loads.
   ══════════════════════════════════════════════════════════ */
const companyTableSection = document.getElementById('companyTableSection');
const filterForm = document.getElementById('filterForm');
const searchInput = document.getElementById('searchInput');
const statusSelect = document.getElementById('statusSelect');
const requestTypeSelect = document.getElementById('requestTypeSelect');
/* NEW (Company Type filter revision) */
const companyTypeFilterSelect = document.getElementById('companyTypeFilterSelect');
let searchDebounceTimer;
let currentTablePage = 1;

function buildTableQuery(page) {
    const params = new URLSearchParams();
    if (searchInput && searchInput.value.trim() !== '') params.set('search', searchInput.value.trim());
    if (statusSelect && statusSelect.value !== 'All') params.set('status', statusSelect.value);
    if (requestTypeSelect && requestTypeSelect.value !== 'All') params.set('request_type', requestTypeSelect.value);
    if (companyTypeFilterSelect && companyTypeFilterSelect.value !== 'All') params.set('company_type', companyTypeFilterSelect.value);
    params.set('page', page || 1);
    params.set('ajax_table', '1');
    return params.toString();
}

function fetchCompanyTable(page) {
    if (!companyTableSection) return;
    currentTablePage = page || 1;
    companyTableSection.classList.add('table-loading');
    showGlobalLoading('Loading companies');
    const queryString = buildTableQuery(page);
    fetch('?' + queryString, { headers: { 'X-Requested-With': 'XMLHttpRequest' } })
        .then(r => r.json())
        .then(data => {
            companyTableSection.innerHTML = data.html;
            const totalCountEl = document.getElementById('companyTotalCountValue');
            if (totalCountEl && typeof data.total !== 'undefined') totalCountEl.textContent = data.total;
            companyTableSection.classList.remove('table-loading');
            companyTableSection.classList.add('table-fade-in');
            setTimeout(() => companyTableSection.classList.remove('table-fade-in'), 350);
            attachPaginationHandlers();
            attachCheckboxHandlers();
            bindRegRequestTypeSelects();
            hideGlobalLoading();
            const shareUrl = new URL(window.location);
            shareUrl.search = queryString.replace(/&?ajax_table=1/, '');
            window.history.replaceState({}, '', shareUrl);
        })
        .catch(() => {
            companyTableSection.classList.remove('table-loading');
            hideGlobalLoading();
        });
}

function attachPaginationHandlers() {
    if (!companyTableSection) return;
    companyTableSection.querySelectorAll('.pagination a[data-page]').forEach(a => {
        a.addEventListener('click', function(e) {
            e.preventDefault();
            fetchCompanyTable(parseInt(this.getAttribute('data-page'), 10) || 1);
            window.scrollTo({ top: 0, behavior: 'smooth' });
        });
    });
}
attachPaginationHandlers();

if (filterForm) {
    filterForm.addEventListener('submit', function(e) {
        e.preventDefault();
        clearTimeout(searchDebounceTimer);
        fetchCompanyTable(1);
    });
}
if (searchInput) {
    searchInput.addEventListener('input', function() {
        clearTimeout(searchDebounceTimer);
        searchDebounceTimer = setTimeout(() => fetchCompanyTable(1), 450);
    });
}
if (statusSelect) statusSelect.addEventListener('change', () => fetchCompanyTable(1));
if (requestTypeSelect) requestTypeSelect.addEventListener('change', () => fetchCompanyTable(1));
if (companyTypeFilterSelect) companyTypeFilterSelect.addEventListener('change', () => fetchCompanyTable(1));

/* ══════════════════════════════════════════════════════════
   NEW: "Set Request Type" — inline dropdown shown for REGISTERED
   companies whose Request Type is blank (see the PHP rendering above
   and the update_registered_request_type handler at the top of this
   file). Selecting "New Company" or "Existing Company" immediately
   sends that value to the server via fetch(), then refreshes the table
   in place so the dropdown is replaced by the normal badge (and, for
   an "Existing" selection, the row becomes eligible for "Admin Copy of
   MOA" on the next refresh, same as any other Existing-type company).

   Bound both directly (bindRegRequestTypeSelects(), re-run after every
   table refresh) and via a delegated fallback listener on the stable
   #companyTableSection container, mirroring the same double-binding
   safety net already used for the row-selection checkboxes elsewhere
   on this page.
   ══════════════════════════════════════════════════════════ */
function submitRegRequestType(selectEl) {
    if (!selectEl) return;
    const value = selectEl.value;
    if (value === '') return;

    const userId = selectEl.getAttribute('data-user-id');
    if (!userId) return;

    selectEl.disabled = true;
    showGlobalLoading('Setting request type');

    const formData = new FormData();
    formData.append('update_registered_request_type', '1');
    formData.append('reg_request_type_user_id', userId);
    formData.append('reg_request_type_value', value);

    fetch(window.location.pathname + window.location.search, {
        method: 'POST',
        headers: { 'X-Requested-With': 'XMLHttpRequest' },
        body: formData
    })
    .then(r => r.json())
    .then(data => {
        hideGlobalLoading();
        showFloatingAlert(data.message, data.success ? 'success' : 'error');
        if (data.success) {
            fetchCompanyTable(currentTablePage || 1);
        } else {
            selectEl.disabled = false;
            selectEl.value = '';
        }
    })
    .catch(() => {
        hideGlobalLoading();
        showFloatingAlert('Something went wrong while setting the Request Type. Please try again.', 'error');
        selectEl.disabled = false;
        selectEl.value = '';
    });
}

function bindRegRequestTypeSelects() {
    if (!companyTableSection) return;
    companyTableSection.querySelectorAll('.reg-request-type-select').forEach(sel => {
        sel.addEventListener('change', function() { submitRegRequestType(this); });
    });
}
bindRegRequestTypeSelects();

/* Delegated fallback — same safety-net pattern already used for the
   row-selection checkboxes below, bound once to the stable container
   so it keeps working through every table refresh without needing to
   be re-attached. */
if (companyTableSection) {
    companyTableSection.addEventListener('change', function(e) {
        const target = e.target;
        if (target && target.classList && target.classList.contains('reg-request-type-select')) {
            submitRegRequestType(target);
        }
    });
}

/* ══════════════════════════════════════════════════════════
   NEW: Admin Copy of MOA — upload / replace flow.
   Delegated on #companyTableSection (never destroyed, only its inner
   HTML is swapped by fetchCompanyTable), so it keeps working after
   every search/filter/pagination/add/delete/edit refresh without
   needing to be re-bound. Clicking "Upload Copy" / "Replace" opens the
   hidden file input for that specific row; picking a PDF immediately
   uploads it via fetch() and refreshes the table in place so the new
   View/Download/Replace buttons show up right away. This is, and
   remains, the ONLY in-table way to set admin_moa_document — it is
   completely independent of the company's own MOA Document column.
   ══════════════════════════════════════════════════════════ */
if (companyTableSection) {
    companyTableSection.addEventListener('click', function(e) {
        const btn = e.target.closest('.admin-moa-upload-btn');
        if (!btn) return;
        const cell = btn.closest('.admin-moa-cell');
        if (!cell) return;
        const fileInput = cell.querySelector('.admin-moa-file-input');
        if (fileInput) fileInput.click();
    });

    companyTableSection.addEventListener('change', function(e) {
        const fileInput = e.target.closest && e.target.closest('.admin-moa-file-input');
        if (!fileInput) return;
        const file = fileInput.files && fileInput.files[0];
        if (!file) return;

        const ext = file.name.split('.').pop().toLowerCase();
        if (ext !== 'pdf') {
            alert('Only PDF files are accepted for the admin copy of the MOA.');
            fileInput.value = '';
            return;
        }

        const cell = fileInput.closest('.admin-moa-cell');
        const refType = cell ? cell.getAttribute('data-ref-type') : null;
        const refId = cell ? cell.getAttribute('data-ref-id') : null;
        if (!refType || !refId) return;

        const formData = new FormData();
        formData.append('upload_admin_moa', '1');
        formData.append('admin_moa_file', file);
        if (refType === 'import') {
            formData.append('admin_moa_import_id', refId);
        } else {
            formData.append('admin_moa_user_id', refId);
        }

        cell.style.opacity = '0.5';
        showGlobalLoading('Uploading MOA copy');
        fetch(window.location.pathname + window.location.search, {
            method: 'POST',
            headers: { 'X-Requested-With': 'XMLHttpRequest' },
            body: formData
        })
        .then(r => r.json())
        .then(data => {
            hideGlobalLoading();
            showFloatingAlert(data.message, data.success ? 'success' : 'error');
            if (data.success) {
                fetchCompanyTable(currentTablePage || 1);
            } else {
                cell.style.opacity = '1';
            }
        })
        .catch(() => {
            hideGlobalLoading();
            showFloatingAlert('Something went wrong while uploading the admin MOA copy. Please try again.', 'error');
            cell.style.opacity = '1';
        });
    });
}

if (editCompanyForm) {
    editCompanyForm.addEventListener('submit', function(e) {
        e.preventDefault();
        const submitBtn = editCompanyForm.querySelector('.btn-submit');
        const originalBtnHtml = submitBtn ? submitBtn.innerHTML : '';
        if (submitBtn) {
            submitBtn.disabled = true;
            submitBtn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Saving...';
        }
        showGlobalLoading('Saving changes');
        const formData = new FormData(editCompanyForm);
        formData.append('edit_company_import', '1');
        fetch(window.location.pathname + window.location.search, {
            method: 'POST',
            headers: { 'X-Requested-With': 'XMLHttpRequest' },
            body: formData
        })
        .then(r => r.json())
        .then(data => {
            if (submitBtn) { submitBtn.disabled = false; submitBtn.innerHTML = originalBtnHtml; }
            /* UPDATED (Edit success popup removal + success loading page —
               same as admin_student_list.php): the "Company details updated
               successfully! Updated in: …" floating notification is no
               longer shown. A successful save now turns the loader into a
               check icon + "Changes Saved" message while the table
               refreshes in place. Errors still show the red notification
               exactly as before. */
            if (data.success) {
                closeEditCompanyModal();
                showGlobalSuccess('Changes Saved', 'Company details updated successfully.', function() {
                    fetchCompanyTable(currentTablePage || 1);
                });
            } else {
                hideGlobalLoading();
                showFloatingAlert(data.message, 'error');
            }
        })
        .catch(() => {
            if (submitBtn) { submitBtn.disabled = false; submitBtn.innerHTML = originalBtnHtml; }
            hideGlobalLoading();
            showFloatingAlert('Something went wrong while updating the company. Please try again.', 'error');
        });
    });
}

/* ══════════════════════════════════════════════════════════
   Delete ("Delete Entry") / Edit ("Edit" toolbar button) — shared
   selection-mode checkbox flow.

   UPDATED (this revision — Create Account removed): this used to
   support a THIRD mode ('account') for the standalone "Create Account"
   toolbar button. That button and mode have been removed entirely
   (account creation is now always automatic — see the add_company_manual
   and XLSX-import handlers, plus runAutoAccountCreation() further
   below), so this shared flow now only ever supports 'delete' and
   'edit'. Nothing about how Delete/Edit themselves behave has changed.

   1) Column visibility no longer depends on any CSS selector matching
      correctly. enterDeleteSelectionMode()/enterEditSelectionMode()/
      exitSelectionMode() set the "display" style DIRECTLY on every
      ".checkbox-cell" element (JS-set inline styles always win over
      stylesheet rules, so there is no specificity/selector logic left
      that could silently fail to reveal the column).

   2) Checkbox behavior is wired up TWO ways at once, so it cannot
      depend on any single mechanism working perfectly:
        a) DIRECT listeners are bound to the "select all" checkbox and
           to every row checkbox, every time the table is (re)rendered
           — on first page load AND after every search/filter/
           pagination/add/delete/edit refresh — via
           attachCheckboxHandlers().
        b) A DELEGATED listener is also bound once to the stable
           #companyTableSection container (which itself is never
           destroyed/recreated, only its contents are swapped), so
           clicks/changes on checkboxes are caught even if, for any
           reason, the direct binding above didn't attach in time.
      Both paths call the exact same sync functions, so triggering
      both for the same click is harmless — it just recomputes the
      same state twice.

   3) Selection state is always read LIVE from the DOM
      (getSelectedImportIds()), never mirrored into a separate JS
      variable, so the Delete/Edit button counts, the confirmation
      popups, and the actual requests can never disagree with what's
      visibly checked in the table.

   A single shared "activeSelectionMode" variable (null | 'delete' |
   'edit') drives which behavior is active. Delete keeps the original
   multi-select behavior. Edit uses the same checkbox column, but
   enforces single selection — checking one row's checkbox automatically
   unchecks any other checked row, and the header "select all" checkbox
   is disabled while in Edit mode since selecting every row at once
   makes no sense for a single-entry edit. Only one mode can be active
   at a time; entering one mode hides the other mode's toolbar button
   until "Cancel" (or a successful action) exits selection mode again.

   UPDATED (this revision): since the per-row Edit button / Actions
   column has been removed, the checked checkbox itself now IS the
   source of the row's editable data (data-import-id / data-company /
   etc., including the new data-company-type, live directly on
   .row-select-checkbox) — the toolbar Edit button's second click passes
   that checkbox straight into openEditCompanyModal(), with no extra DOM
   lookup for a button that no longer exists.
   ══════════════════════════════════════════════════════════ */
const deleteEntryBtn = document.getElementById('deleteEntryBtn');
const editEntryBtn = document.getElementById('editEntryBtn');
const cancelSelectionBtn = document.getElementById('cancelSelectionBtn');
const deleteSelectedModal = document.getElementById('deleteSelectedModal');
const deleteSelectedMessage = document.getElementById('deleteSelectedMessage');
const deleteSelectedCancelBtn = document.getElementById('deleteSelectedCancelBtn');
const deleteSelectedConfirmBtn = document.getElementById('deleteSelectedConfirmBtn');

/* null = no selection mode active, 'delete' = multi-select for Delete,
   'edit' = single-select for Edit, 'export' = multi-select for choosing
   which companies to leave OUT of an Excel export (NEW — Export
   exclusion revision). */
let activeSelectionMode = null;

/* NEW: highlights (or un-highlights) the <tr> a given row checkbox
   belongs to, based on whether that checkbox is currently checked, so
   the admin can clearly see at a glance which entries are marked. Uses
   a distinct highlight color depending on which mode is active (red
   for Delete, amber for Edit, navy for Export exclusion) so the modes
   never look identical. */
function updateRowHighlightForCheckbox(cb) {
    if (!cb) return;
    const tr = cb.closest('tr');
    if (!tr) return;
    const checked = !!cb.checked;
    if (activeSelectionMode === 'edit') {
        tr.classList.toggle('row-selected-edit', checked);
        tr.classList.remove('row-selected');
        tr.classList.remove('row-selected-export');
    } else if (activeSelectionMode === 'export') {
        tr.classList.toggle('row-selected-export', checked);
        tr.classList.remove('row-selected');
        tr.classList.remove('row-selected-edit');
    } else {
        tr.classList.toggle('row-selected', checked);
        tr.classList.remove('row-selected-edit');
        tr.classList.remove('row-selected-export');
    }
}

/* Always reads live from the DOM instead of a separately-tracked Set,
   so this can never fall out of sync with what's actually checked. */
function getSelectedImportIds() {
    if (!companyTableSection) return [];
    return Array.from(companyTableSection.querySelectorAll('.row-select-checkbox:checked')).map(cb => cb.value);
}

/* NEW (Export exclusion revision): unlike getSelectedImportIds() above
   (only ever meaningful for imported rows, whose checkbox VALUE is
   already the companies_import id), Export mode allows checking BOTH
   registered and imported rows — and a registered row's checkbox has no
   usable "value" (it is intentionally left blank; see the table markup
   above). This instead reads each checked box's data-* attributes
   directly, which both kinds of rows already carry, and sorts them into
   the two id lists the server-side export filter expects. */
function getSelectedExportExclusions() {
    const result = { userIds: [], importIds: [] };
    if (!companyTableSection) return result;
    companyTableSection.querySelectorAll('.row-select-checkbox:checked').forEach(cb => {
        if (cb.dataset.source === 'registered') {
            if (cb.dataset.userId) result.userIds.push(cb.dataset.userId);
        } else if (cb.dataset.importId) {
            result.importIds.push(cb.dataset.importId);
        }
    });
    return result;
}

function refreshDeleteButtonUI() {
    if (!deleteEntryBtn) return;
    if (activeSelectionMode !== 'delete') {
        deleteEntryBtn.disabled = false;
        deleteEntryBtn.innerHTML = '<i class="fas fa-trash-alt"></i> Delete';
        deleteEntryBtn.title = 'Click to select entries to delete';
        return;
    }
    const count = getSelectedImportIds().length;
    deleteEntryBtn.disabled = count === 0;
    deleteEntryBtn.innerHTML = '<i class="fas fa-trash-alt"></i> Delete' + (count > 0 ? ' (' + count + ')' : '');
    deleteEntryBtn.title = count > 0 ? 'Delete the selected entries' : 'Select one or more entries below';
}

/* NEW: mirrors refreshDeleteButtonUI(), but for the toolbar Edit
   button — since only one entry may ever be edited at a time, the
   button is only enabled once exactly one row is checked. */
function refreshEditButtonUI() {
    if (!editEntryBtn) return;
    if (activeSelectionMode !== 'edit') {
        editEntryBtn.disabled = false;
        editEntryBtn.innerHTML = '<i class="fas fa-edit"></i> Edit';
        editEntryBtn.title = 'Click to select one entry to edit';
        return;
    }
    const count = getSelectedImportIds().length;
    editEntryBtn.disabled = count !== 1;
    editEntryBtn.innerHTML = '<i class="fas fa-edit"></i> Edit' + (count === 1 ? ' Selected' : '');
    editEntryBtn.title = count === 1 ? 'Edit the selected entry' : 'Select exactly one entry below to edit';
}

/* NEW (Export exclusion revision): mirrors refreshDeleteButtonUI(), for
   the "Export (Excluding N)" button shown while choosing companies to
   leave out of the export. Unlike Delete/Edit, a count of 0 is still a
   perfectly valid, enabled state here — it just means "export
   everything", identical to clicking "None, Proceed With Export" would
   have. */
function refreshExportConfirmButtonUI() {
    if (!exportConfirmBtn) return;
    if (activeSelectionMode !== 'export') {
        exportConfirmBtn.innerHTML = '<i class="fas fa-file-excel"></i> Export (Excluding 0)';
        return;
    }
    const exclusions = getSelectedExportExclusions();
    const count = exclusions.userIds.length + exclusions.importIds.length;
    exportConfirmBtn.innerHTML = '<i class="fas fa-file-excel"></i> Export' + (count > 0 ? ' (Excluding ' + count + ')' : ' (Excluding None)');
    exportConfirmBtn.title = count > 0 ? 'Export every company except the ' + count + ' checked below' : 'No companies checked — this will export everything';
}

/* FIX: directly toggles the inline "display" style of every checkbox
   cell (header + each row) instead of relying on a CSS class name
   being matched by a selector somewhere in the stylesheet. This is
   the most reliable way to guarantee the column actually becomes
   visible/interactive when entering selection mode. */
function setCheckboxColumnVisible(visible) {
    if (!companyTableSection) return;
    companyTableSection.querySelectorAll('.checkbox-cell').forEach(cell => {
        cell.style.display = visible ? 'table-cell' : 'none';
    });
    /* NEW: lets the whole row be clicked to select it (see the delegated
       row-click listener further below) instead of requiring a precise
       click on the small checkbox. This class only drives a pointer
       cursor / row affordance via CSS — the actual click-to-select
       behavior is gated on activeSelectionMode in that listener too, so
       this is purely a visual hint, not a second source of truth. */
    companyTableSection.querySelectorAll('.company-table').forEach(table => {
        table.classList.toggle('selection-mode-active', visible);
    });
}

/* NEW: enables/disables the header "select all" checkbox. Disabled
   while in Edit mode, since only one row may ever be selected there —
   "select all" has no meaningful action to perform in that mode.
   Enabled for Delete's multi-select mode. */
function setSelectAllCheckboxEnabled(enabled) {
    if (!companyTableSection) return;
    const selectAllCb = companyTableSection.querySelector('#selectAllCheckbox');
    if (selectAllCb) selectAllCb.disabled = !enabled;
}

/* NEW (cross-table sync revision): registered companies' checkboxes
   (.reg-row-select-checkbox) are only selectable while a selection mode
   that supports them is active (Edit, Export exclusion, and — UPDATED
   (Delete revision) — Delete). With no mode active they are forced
   disabled and unchecked. */
function setRegisteredCheckboxesEnabled(enabled) {
    if (!companyTableSection) return;
    companyTableSection.querySelectorAll('.reg-row-select-checkbox').forEach(cb => {
        cb.disabled = !enabled;
        if (!enabled) {
            cb.checked = false;
            updateRowHighlightForCheckbox(cb);
        }
    });
}

function enterDeleteSelectionMode() {
    activeSelectionMode = 'delete';
    setCheckboxColumnVisible(true);
    setSelectAllCheckboxEnabled(true);
    /* UPDATED (Delete revision): registered company ACCOUNTS can now be
       selected for deletion too (deleted exactly like monitoring.php's
       Delete button — see company_list_delete_company_account()). */
    setRegisteredCheckboxesEnabled(true);
    if (editEntryBtn) editEntryBtn.style.display = 'none';
    if (cancelSelectionBtn) cancelSelectionBtn.style.display = 'inline-flex';
    refreshDeleteButtonUI();
    refreshEditButtonUI();
    refreshExportConfirmButtonUI();
}

function enterEditSelectionMode() {
    activeSelectionMode = 'edit';
    setCheckboxColumnVisible(true);
    setSelectAllCheckboxEnabled(false);
    setRegisteredCheckboxesEnabled(true);
    if (deleteEntryBtn) deleteEntryBtn.style.display = 'none';
    if (cancelSelectionBtn) cancelSelectionBtn.style.display = 'inline-flex';
    refreshDeleteButtonUI();
    refreshEditButtonUI();
    refreshExportConfirmButtonUI();
}

/* NEW (Export exclusion revision): turns on the same checkbox-selection
   UI as Delete/Edit, but — unlike either of those — over EVERY company
   on the page, registered and imported alike (setRegisteredCheckboxesEnabled(true)
   lifts the usual "registered rows are never selectable outside Edit
   mode" restriction for this mode too), since any company might be one
   the admin wants left out of the export file. Multi-select, like
   Delete — there's no reason only one company could ever be excluded. */
function enterExportExcludeSelectionMode() {
    activeSelectionMode = 'export';
    setCheckboxColumnVisible(true);
    setSelectAllCheckboxEnabled(true);
    setRegisteredCheckboxesEnabled(true);
    if (deleteEntryBtn) deleteEntryBtn.style.display = 'none';
    if (editEntryBtn) editEntryBtn.style.display = 'none';
    if (companyExportBtnEl) companyExportBtnEl.style.display = 'none';
    if (exportConfirmBtn) exportConfirmBtn.style.display = 'inline-flex';
    if (cancelSelectionBtn) cancelSelectionBtn.style.display = 'inline-flex';
    refreshDeleteButtonUI();
    refreshEditButtonUI();
    refreshExportConfirmButtonUI();
}

function exitSelectionMode() {
    activeSelectionMode = null;
    setCheckboxColumnVisible(false);
    setSelectAllCheckboxEnabled(true);
    if (companyTableSection) {
        companyTableSection.querySelectorAll('.row-select-checkbox').forEach(cb => {
            cb.checked = false;
            updateRowHighlightForCheckbox(cb);
        });
        const selectAllCb = companyTableSection.querySelector('#selectAllCheckbox');
        if (selectAllCb) selectAllCb.checked = false;
    }
    setRegisteredCheckboxesEnabled(false);
    if (deleteEntryBtn) deleteEntryBtn.style.display = 'inline-flex';
    if (editEntryBtn) editEntryBtn.style.display = 'inline-flex';
    if (companyExportBtnEl) companyExportBtnEl.style.display = 'inline-flex';
    if (exportConfirmBtn) exportConfirmBtn.style.display = 'none';
    if (cancelSelectionBtn) cancelSelectionBtn.style.display = 'none';
    refreshDeleteButtonUI();
    refreshEditButtonUI();
    refreshExportConfirmButtonUI();
}

/* Syncs the header "select all" checkbox with the current state of the
   row checkboxes (checked only when every row checkbox on the page is
   checked; meaningful in Delete mode and Export-exclude mode, the two
   multi-select modes), then recomputes the toolbar buttons' labels/
   state. Called after every individual row checkbox change, from either
   the direct listener or the delegated fallback listener below. */
function syncSelectAllCheckbox() {
    if (!companyTableSection) return;
    const selectAllCb = companyTableSection.querySelector('#selectAllCheckbox');
    const rowBoxes = companyTableSection.querySelectorAll('.row-select-checkbox:not(:disabled)');
    if (selectAllCb && (activeSelectionMode === 'delete' || activeSelectionMode === 'export')) {
        selectAllCb.checked = rowBoxes.length > 0 && Array.from(rowBoxes).every(cb => cb.checked);
    }
    refreshDeleteButtonUI();
    refreshEditButtonUI();
    refreshExportConfirmButtonUI();
}

/* Applies the "select all" checkbox's current checked state to every
   row checkbox on the page, then refreshes the toolbar buttons. Shared
   by both the direct listener and the delegated fallback listener.
   Meaningful in Delete mode and Export-exclude mode — the "select all"
   checkbox is disabled during Edit mode, so this is a no-op guard for
   safety. */
function applySelectAllToRows(selectAllCb) {
    if (!companyTableSection || !selectAllCb) return;
    if (activeSelectionMode !== 'delete' && activeSelectionMode !== 'export') return;
    const checked = selectAllCb.checked;
    companyTableSection.querySelectorAll('.row-select-checkbox:not(:disabled)').forEach(cb => {
        cb.checked = checked;
        updateRowHighlightForCheckbox(cb);
    });
    refreshDeleteButtonUI();
    refreshEditButtonUI();
    refreshExportConfirmButtonUI();
}

/* NEW: shared row-checkbox change handler for Delete and Edit modes.
   In Edit mode, checking a row automatically unchecks every other
   row's checkbox first, so only one entry can ever be selected at a
   time — enforcing the "only one entry can be checked and edited at a
   time" requirement directly at the source of the change. Delete
   allows multiple rows checked at once, so no such restriction applies
   to it. */
function handleRowCheckboxChange(cb) {
    if (!cb) return;
    if (activeSelectionMode === 'edit' && cb.checked && companyTableSection) {
        companyTableSection.querySelectorAll('.row-select-checkbox').forEach(other => {
            if (other !== cb && other.checked) {
                other.checked = false;
                updateRowHighlightForCheckbox(other);
            }
        });
    }
    updateRowHighlightForCheckbox(cb);
    syncSelectAllCheckbox();
}

/* DIRECT binding: attaches a listener straight onto the "select all"
   checkbox and every row checkbox currently in the DOM. */
function bindSelectAllCheckbox() {
    if (!companyTableSection) return;
    const selectAllCb = companyTableSection.querySelector('#selectAllCheckbox');
    if (!selectAllCb) return;
    selectAllCb.addEventListener('change', function() { applySelectAllToRows(selectAllCb); });
}

function bindRowCheckboxes() {
    if (!companyTableSection) return;
    companyTableSection.querySelectorAll('.row-select-checkbox').forEach(cb => {
        cb.addEventListener('change', function() { handleRowCheckboxChange(cb); });
    });
}

/* Called once on initial page load AND after every table refresh
   (search / filter / pagination / add / delete / edit). A freshly
   rendered table never has anything checked, so selection mode is
   reset to its default, closed state, and fresh direct listeners are
   attached to whatever checkboxes are now in the DOM. */
function attachCheckboxHandlers() {
    exitSelectionMode();
    bindSelectAllCheckbox();
    bindRowCheckboxes();
}

/* FIX: wire up the checkboxes rendered by PHP on the very first page
   load too — previously this only ever ran after an AJAX refresh, so
   the checkboxes on first load had no listeners bound to them at all. */
attachCheckboxHandlers();

/* FIX: DELEGATED fallback listener bound ONCE to the stable
   #companyTableSection container. Unlike the direct listeners above
   (which are re-attached to fresh checkbox elements every time the
   table's innerHTML is replaced), this listener is bound to the
   container itself — which is never replaced, only its contents — so
   it keeps working through every refresh without ever needing to be
   re-bound. It exists purely as a safety net: if a checkbox is ever
   toggled without its direct listener having attached in time, this
   still catches the change (via event bubbling) and runs the exact
   same sync logic, so the row/"select all"/Delete/Edit button states
   can never drift out of sync with each other. */
if (companyTableSection) {
    companyTableSection.addEventListener('change', function(e) {
        const target = e.target;
        if (!target) return;
        if (target.id === 'selectAllCheckbox') {
            applySelectAllToRows(target);
        } else if (target.classList && target.classList.contains('row-select-checkbox')) {
            handleRowCheckboxChange(target);
        }
    });
}

/* NEW: lets the admin click anywhere on a row to select/deselect it,
   instead of having to click precisely on the small checkbox — only
   while a selection mode (Edit or Delete) is actually active, so this
   has no effect at all on normal browsing of the table. Delegated onto
   the same stable #companyTableSection container as the change listener
   above, so it survives every table refresh without needing to be
   re-bound.

   Clicks on the checkbox itself, or on any other interactive control in
   the row (the MOA "View"/"Download" actions, the "Set Request Type"
   dropdown), are deliberately ignored here and left to their own normal
   click handling — this only reacts to clicks that land on otherwise
   "dead" space in the row (the company name, email, address cells,
   etc). Toggling reuses the exact same handleRowCheckboxChange() the
   checkbox's own change listener uses, so single-select-in-Edit-mode,
   row highlighting, and the Delete/Edit button counts all stay in
   sync automatically. */
if (companyTableSection) {
    companyTableSection.addEventListener('click', function(e) {
        if (!activeSelectionMode) return;
        if (e.target.closest('a, button, select, option, label, input')) return;
        const tr = e.target.closest('tr');
        if (!tr) return;
        const cb = tr.querySelector('.row-select-checkbox');
        if (!cb || cb.disabled) return;
        cb.checked = !cb.checked;
        handleRowCheckboxChange(cb);
    });
}

/* NEW (Delete revision): like getSelectedExportExclusions(), reads each
   checked box's data-* attributes so BOTH registered company accounts
   (by users.id) and imported entries (by companies_import.id) can be
   sent for deletion, together with a display label for the popups. */
function getSelectedDeleteTargets() {
    const result = { userIds: [], importIds: [], items: [] };
    if (!companyTableSection) return result;
    companyTableSection.querySelectorAll('.row-select-checkbox:checked').forEach(cb => {
        if (cb.dataset.source === 'registered') {
            if (cb.dataset.userId) {
                result.userIds.push(cb.dataset.userId);
                result.items.push({ company: cb.dataset.company || ('Account #' + cb.dataset.userId), kind: 'registered' });
            }
        } else if (cb.dataset.importId) {
            result.importIds.push(cb.dataset.importId);
            result.items.push({ company: cb.dataset.company || ('Entry #' + cb.dataset.importId), kind: 'imported' });
        }
    });
    return result;
}

function escapeHtmlText(value) {
    const div = document.createElement('div');
    div.textContent = value == null ? '' : String(value);
    return div.innerHTML;
}

if (deleteEntryBtn) {
    deleteEntryBtn.addEventListener('click', function() {
        if (activeSelectionMode !== 'delete') {
            enterDeleteSelectionMode();
            return;
        }
        const targets = getSelectedDeleteTargets();
        const count = targets.items.length;
        if (count === 0) return;

        const accountCount = targets.userIds.length;
        if (deleteSelectedMessage) {
            deleteSelectedMessage.innerHTML = 'Are you sure you want to permanently delete <strong>' + count + '</strong> selected compan' + (count === 1 ? 'y' : 'ies') + '? This action cannot be undone.';
        }
        /* NEW (Delete revision): list exactly what is about to be deleted,
           and warn clearly when real company ACCOUNTS are included. */
        const detailsEl = document.getElementById('deleteSelectedDetails');
        if (detailsEl) {
            let html = '';
            if (accountCount > 0) {
                html += '<div class="delete-warning-note"><i class="fas fa-exclamation-triangle"></i> <strong>' + accountCount + '</strong> registered company account' + (accountCount === 1 ? '' : 's') + ' will be permanently deleted together with all related records (login account, company profile, requirements, MOA requests, OJT assignments, reports, messages, etc.).</div>';
            }
            html += '<div class="delete-selected-list">';
            targets.items.forEach(item => {
                html += '<div class="delete-selected-row"><strong>' + escapeHtmlText(item.company) + '</strong>'
                     + '<span class="delete-kind-tag ' + item.kind + '">' + (item.kind === 'registered' ? 'Company Account' : 'Imported Entry') + '</span></div>';
            });
            html += '</div>';
            detailsEl.innerHTML = html;
        }
        if (deleteSelectedModal) deleteSelectedModal.classList.add('open');
    });
}

/* UPDATED: toolbar "Edit" button — first click enters Edit selection
   mode (reveals the checkbox column, single-select enforced). Second
   click, once exactly one imported row is checked, passes that row's
   checkbox (which now carries the same data-* values the removed
   per-row Edit button used to carry, including data-company-type)
   straight into openEditCompanyModal() — no button lookup needed
   anymore since the Actions column no longer exists. Selection mode is
   exited as soon as the modal opens. */
if (editEntryBtn) {
    editEntryBtn.addEventListener('click', function() {
        if (activeSelectionMode !== 'edit') {
            enterEditSelectionMode();
            return;
        }
        const selected = getSelectedImportIds();
        if (selected.length !== 1 || !companyTableSection) return;

        const checkedBox = companyTableSection.querySelector('.row-select-checkbox:checked');

        if (checkedBox) {
            openEditCompanyModal(checkedBox);
            exitSelectionMode();
        } else {
            showFloatingAlert('Could not find this entry\u2019s details. Please try again.', 'error');
        }
    });
}

if (cancelSelectionBtn) {
    cancelSelectionBtn.addEventListener('click', function() {
        exitSelectionMode();
    });
}

/* NEW (Export exclusion revision): toolbar "Export (Excluding N)"
   button — only ever visible while activeSelectionMode === 'export'
   (see enterExportExcludeSelectionMode() above). Clicking it starts the
   download with whatever is currently checked treated as EXCLUDED from
   the file (an empty selection simply exports everything, same as
   "None, Proceed With Export" would have), then returns the toolbar to
   its normal state. */
if (exportConfirmBtn) {
    exportConfirmBtn.addEventListener('click', function() {
        const exclusions = getSelectedExportExclusions();
        triggerExportDownload(exclusions.userIds, exclusions.importIds);
        exitSelectionMode();
    });
}

/* ══════════════════════════════════════════════════════════
   Delete — confirmation popup.
   Clicking "Delete" while one or more entries are selected opens this
   confirmation modal warning the admin about the action. Clicking
   "Yes, Delete" proceeds with the deletion (via fetch(), same
   no-reload pattern as Add Company, then refreshes the table and exits
   selection mode). Clicking "Cancel" or the backdrop simply closes the
   popup — nothing is deleted, no request is sent, and the current
   selection is left untouched.
   ══════════════════════════════════════════════════════════ */
/* NEW (Delete revision): "Account Deleted" notification popup, shown
   after the delete request finishes — mirrors monitoring.php's
   notification, listing each removed company with its details plus
   anything that could not be deleted. */
const deleteResultModal = document.getElementById('deleteResultModal');
const deleteResultOkBtn = document.getElementById('deleteResultOkBtn');

function showDeleteResultModal(data) {
    if (!deleteResultModal) { showFloatingAlert(data.message, data.success ? 'success' : 'error'); return; }
    const deleted = Array.isArray(data.deleted) ? data.deleted : [];
    const failed = Array.isArray(data.failed) ? data.failed : [];
    const iconEl = document.getElementById('deleteResultIcon');
    const titleEl = document.getElementById('deleteResultTitle');
    const msgEl = document.getElementById('deleteResultMessage');
    const bodyEl = document.getElementById('deleteResultBody');
    const hasAccounts = deleted.some(d => d.kind === 'registered');

    if (iconEl) {
        iconEl.innerHTML = data.success ? '<i class="fas fa-check-circle"></i>' : '<i class="fas fa-times-circle"></i>';
        iconEl.style.color = data.success ? 'var(--grid-green)' : 'var(--grid-red)';
    }
    if (titleEl) {
        titleEl.textContent = !data.success ? 'Delete Failed'
            : (hasAccounts ? (deleted.length === 1 ? 'Account Deleted' : 'Accounts Deleted') : (deleted.length === 1 ? 'Entry Deleted' : 'Entries Deleted'));
    }
    if (msgEl) {
        /* The summary line only — per-company failures are listed below. */
        const summary = data.success
            ? String(data.message || '').split('<br>')[0]
            : 'Nothing was deleted.';
        msgEl.innerHTML = summary;
    }
    if (bodyEl) {
        let html = '';
        deleted.forEach(d => {
            html += '<div class="acct-detail-card"><div class="acct-detail-card-head"><span>' + escapeHtmlText(d.company) + '</span>'
                 + '<span class="delete-kind-tag ' + (d.kind === 'registered' ? 'registered' : 'imported') + '">' + (d.kind === 'registered' ? 'Company Account' : 'Imported Entry') + '</span></div>';
            (d.details || []).forEach(row => {
                html += '<div class="acct-detail-row"><span class="acct-detail-label">' + escapeHtmlText(row.label) + '</span><span class="acct-detail-value">' + escapeHtmlText(row.value) + '</span></div>';
            });
            html += '</div>';
        });
        if (failed.length > 0) {
            html += '<div class="delete-failed-block"><strong>Could not be deleted:</strong><br>' + failed.join('<br>') + '</div>';
        } else if (!data.success && data.message) {
            html += '<div class="delete-failed-block">' + data.message + '</div>';
        }
        bodyEl.innerHTML = html;
    }
    deleteResultModal.classList.add('open');
}

if (deleteResultOkBtn) {
    deleteResultOkBtn.addEventListener('click', () => deleteResultModal.classList.remove('open'));
}
if (deleteResultModal) {
    deleteResultModal.addEventListener('click', e => { if (e.target === deleteResultModal) deleteResultModal.classList.remove('open'); });
}

if (deleteSelectedCancelBtn) {
    deleteSelectedCancelBtn.addEventListener('click', () => deleteSelectedModal.classList.remove('open'));
}
if (deleteSelectedModal) {
    deleteSelectedModal.addEventListener('click', e => { if (e.target === deleteSelectedModal) deleteSelectedModal.classList.remove('open'); });
}
if (deleteSelectedConfirmBtn) {
    deleteSelectedConfirmBtn.addEventListener('click', function() {
        const targets = getSelectedDeleteTargets();
        if (targets.items.length === 0) { if (deleteSelectedModal) deleteSelectedModal.classList.remove('open'); return; }
        const originalBtnHtml = deleteSelectedConfirmBtn.innerHTML;
        deleteSelectedConfirmBtn.disabled = true;
        deleteSelectedConfirmBtn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Deleting...';
        showGlobalLoading(targets.userIds.length > 0 ? 'Deleting accounts' : 'Deleting entries');
        const formData = new FormData();
        formData.append('delete_selected_companies', '1');
        targets.importIds.forEach(id => formData.append('company_ids[]', id));
        targets.userIds.forEach(id => formData.append('company_user_ids[]', id));
        fetch(window.location.pathname + window.location.search, {
            method: 'POST',
            headers: { 'X-Requested-With': 'XMLHttpRequest' },
            body: formData
        })
        .then(r => r.json())
        .then(data => {
            deleteSelectedConfirmBtn.disabled = false;
            deleteSelectedConfirmBtn.innerHTML = originalBtnHtml;
            if (deleteSelectedModal) deleteSelectedModal.classList.remove('open');
            /* UPDATED (Delete success loading page — same as
               admin_student_list.php): a successful delete now turns the
               loader into a check icon + action message (e.g. "Entries
               Deleted — 2 entries deleted.") while the table refreshes in
               place, instead of the "Account Deleted" popup. The popup
               (showDeleteResultModal) is still used when the delete fails,
               or when some of the selected entries could not be deleted,
               so failures are never hidden. */
            const deletedList = Array.isArray(data.deleted) ? data.deleted : [];
            const failedList = Array.isArray(data.failed) ? data.failed : [];
            if (data.success && failedList.length === 0) {
                const hasAccounts = deletedList.some(d => d.kind === 'registered');
                const successTitle = hasAccounts
                    ? (deletedList.length === 1 ? 'Account Deleted' : 'Accounts Deleted')
                    : (deletedList.length === 1 ? 'Entry Deleted' : 'Entries Deleted');
                exitSelectionMode();
                showGlobalSuccess(successTitle, String(data.message || '').split('<br>')[0], function() {
                    fetchCompanyTable(1);
                });
            } else {
                hideGlobalLoading();
                showDeleteResultModal(data);
                if (data.success) {
                    exitSelectionMode();
                    fetchCompanyTable(1);
                }
            }
        })
        .catch(() => {
            deleteSelectedConfirmBtn.disabled = false;
            deleteSelectedConfirmBtn.innerHTML = originalBtnHtml;
            if (deleteSelectedModal) deleteSelectedModal.classList.remove('open');
            hideGlobalLoading();
            showFloatingAlert('Something went wrong while deleting the selected entries. Please try again.', 'error');
        });
    });
}

/* ══════════════════════════════════════════════════════════
   NEW: Automatic account creation right after a successful XLSX
   import, or a manual "Add Company" submission that needs follow-up —
   the standalone "Create Account" toolbar button and its checkbox
   selection mode have been removed entirely; this is now the ONLY way
   accounts get created on this page.

   window.autoCreateAccountImportIds (set near the very top of this
   script from PHP) holds the companies_import ids resolved in THIS
   SAME page load right after a successful import, or queued by the
   manual-add non-JS fallback's redirect; it stays an empty array on
   every other load (plain filter/pagination reloads, settings save,
   etc.), so this never fires spuriously.

   window.autoCreateAccountPreSkippedDetails (also set near the top,
   from PHP) holds the human-readable skip messages for companies that
   the XLSX import's "already registered" pre-check kept out of
   companies_import entirely — they never received an id, so they can't
   be sent through create_accounts_selected, but they still need to
   show up in this popup's Skipped breakdown (see runAutoAccountCreation
   below for how the two lists are combined).

   The AJAX "Add Company" submit handler further above calls
   runAutoAccountCreation() directly with the single newly-added id
   whenever that company's automatic account-creation attempt needed
   follow-up (no pre-skipped details apply to that path).

   runAutoAccountCreation() shows the "Creating Company Accounts"
   progress modal, calls the create_accounts_selected endpoint (backed
   by the same attempt_create_company_account()/
   run_company_account_creation_batch() logic used by the automatic
   server-side attempt itself, so retrying here behaves identically) —
   or, if there are no ids to actually process (every entry in the batch
   was a pre-registered skip), renders the breakdown immediately without
   a network call at all — then swaps the modal's content to a
   Created / Skipped / Failed breakdown, with a detailed per-company
   list for anything that wasn't a clean success. The company table is
   refreshed in place afterward (when a fetch happened) so newly created
   (now "Registered") companies, and any still-imported leftovers, show
   up immediately.
   ══════════════════════════════════════════════════════════ */
const autoAccountProgressModal = document.getElementById('autoAccountProgressModal');
const autoAccountProgressBody = document.getElementById('autoAccountProgressBody');
const autoAccountProgressCloseBtn = document.getElementById('autoAccountProgressCloseBtn');

function renderAutoAccountProgressLoading(total) {
    if (!autoAccountProgressBody) return;
    autoAccountProgressBody.innerHTML =
        '<div class="global-loading-spinner" style="margin:0 auto 14px auto;"></div>' +
        '<p class="auto-account-progress-text">Creating login account' + (total === 1 ? '' : 's') + ' for ' + total + ' newly imported compan' + (total === 1 ? 'y' : 'ies') + '... please wait.</p>';
    if (autoAccountProgressCloseBtn) autoAccountProgressCloseBtn.style.display = 'none';
}

function renderAutoAccountDetailBlock(title, colorVar, items) {
    if (!items || items.length === 0) return '';
    const listItems = items.map(function(t) { return '<li>' + t + '</li>'; }).join('');
    return '<div class="auto-account-detail-block"><strong style="color:' + colorVar + ';">' + title + '</strong><ul>' + listItems + '</ul></div>';
}

function renderAutoAccountProgressResult(counts, details) {
    if (!autoAccountProgressBody) return;
    counts = counts || { created: 0, skipped: 0, failed: 0 };
    details = details || {};

    let html = '<div class="auto-account-stat-grid">';
    html += '<div class="auto-account-stat"><div class="auto-account-stat-number created">' + counts.created + '</div><div class="auto-account-stat-label">Created</div></div>';
    html += '<div class="auto-account-stat"><div class="auto-account-stat-number skipped">' + counts.skipped + '</div><div class="auto-account-stat-label">Skipped</div></div>';
    html += '<div class="auto-account-stat"><div class="auto-account-stat-number failed">' + counts.failed + '</div><div class="auto-account-stat-label">Failed</div></div>';
    html += '</div>';

    html += renderAutoAccountDetailBlock('Skipped', 'var(--grid-amber)', details.skipped);
    html += renderAutoAccountDetailBlock('Failed', 'var(--grid-red)', details.failed);
    html += renderAutoAccountDetailBlock('Created, but email not sent', 'var(--grid-navy)', details.created_with_email_issues);

    if (counts.created === 0 && counts.skipped === 0 && counts.failed === 0) {
        html += '<p class="auto-account-progress-text">No entries needed to be processed.</p>';
    }

    autoAccountProgressBody.innerHTML = html;
    if (autoAccountProgressCloseBtn) autoAccountProgressCloseBtn.style.display = 'inline-flex';
}

/* UPDATED (popup restore fix): now accepts an optional second argument,
   preSkippedDetails — the already-registered-company skip messages
   that were never staged into companies_import (see
   window.autoCreateAccountPreSkippedDetails above). These carry no
   companies_import id, so they can never be included in the
   create_accounts_selected request itself; instead they are folded
   into the SAME rendered breakdown as Skipped entries, either
   immediately (if there are no ids left to actually process) or after
   merging them into the real batch result (if there are). This is what
   restores the "Creating Company Accounts" popup for imports that
   consist entirely — or partly — of rows the "already registered"
   pre-check kept out of companies_import. */
function runAutoAccountCreation(importIds, preSkippedDetails) {
    importIds = importIds || [];
    preSkippedDetails = preSkippedDetails || [];

    if ((importIds.length === 0 && preSkippedDetails.length === 0) || !autoAccountProgressModal) return;

    autoAccountProgressModal.classList.add('open');

    /* FIX (popup restore): if EVERY entry in this batch is a
       pre-registered skip — i.e. no companies_import ids were resolved
       at all for this import — there is nothing to send to
       create_accounts_selected. Render the breakdown directly instead
       of firing a request with an empty id list, so the popup still
       opens and reports these as Skipped exactly like it used to
       before the "already registered" pre-check existed. */
    if (importIds.length === 0) {
        renderAutoAccountProgressResult(
            { created: 0, skipped: preSkippedDetails.length, failed: 0 },
            { skipped: preSkippedDetails, failed: [], created_with_email_issues: [] }
        );
        return;
    }

    renderAutoAccountProgressLoading(importIds.length);

    const formData = new FormData();
    formData.append('create_accounts_selected', '1');
    importIds.forEach(function(id) { formData.append('import_ids[]', id); });

    fetch(window.location.pathname + window.location.search, {
        method: 'POST',
        headers: { 'X-Requested-With': 'XMLHttpRequest' },
        body: formData
    })
    .then(function(r) { return r.json(); })
    .then(function(data) {
        const counts = data.counts || { created: 0, skipped: 0, failed: 0 };
        const details = data.details || { skipped: [], failed: [], created_with_email_issues: [] };
        /* FIX (popup restore): fold any pre-registered skips into this
           same breakdown so the admin sees ONE consistent popup for the
           whole import, instead of the real batch result appearing
           alone while the pre-registered skips are only ever mentioned
           in the plain-text alert above the table. */
        if (preSkippedDetails.length > 0) {
            counts.skipped = (counts.skipped || 0) + preSkippedDetails.length;
            details.skipped = (details.skipped || []).concat(preSkippedDetails);
        }
        renderAutoAccountProgressResult(counts, details);
        fetchCompanyTable(1);
    })
    .catch(function() {
        renderAutoAccountProgressResult(
            { created: 0, skipped: preSkippedDetails.length, failed: importIds.length },
            {
                skipped: preSkippedDetails,
                failed: ['Something went wrong while creating accounts automatically. You can retry by editing this entry and saving it again.'],
                created_with_email_issues: []
            }
        );
    });
}

if (autoAccountProgressCloseBtn) {
    autoAccountProgressCloseBtn.addEventListener('click', function() {
        if (autoAccountProgressModal) autoAccountProgressModal.classList.remove('open');
    });
}

/* ══════════════════════════════════════════════════════════
   NEW (Import/Export result screen revision): after the page reloads
   from an XLSX import, show the "Import Successful" / "Import Failed"
   result screen once the initial loading screen has cleared (window
   'load', or the same 4s safety ceiling the loading overlay uses).
   Success closes itself after a few seconds, revealing the "Creating
   Company Accounts" popup (which starts below exactly as before);
   failure stays until the admin clicks OK. The red error banner above
   the table is still shown as before.
   ══════════════════════════════════════════════════════════ */
(function() {
    const result = window.companyImportResult;
    if (!result) return;
    let shown = false;
    function showImportResult() {
        if (shown) return;
        shown = true;
        if (result.type === 'error') {
            showGlobalResult('error', 'Import Failed', globalResultPlainText(result.message) || 'The file could not be imported. Please try again.', 0);
        } else {
            showGlobalResult('success', 'Import Successful', globalResultPlainText(result.message) || 'Your file was processed successfully.', 3000);
        }
    }
    if (document.readyState === 'complete') showImportResult();
    else window.addEventListener('load', showImportResult);
    setTimeout(showImportResult, 4000);
})();

/* Kick off automatically, with no admin interaction required, whenever
   the page just finished a successful XLSX import (or picked up a
   queued manual-add follow-up id from the redirect fallback) — either
   because real companies_import ids were resolved, or because the
   import consisted (fully or partly) of already-registered companies
   that the "already registered" pre-check kept out of companies_import
   entirely. */
if (
    (window.autoCreateAccountImportIds && window.autoCreateAccountImportIds.length > 0) ||
    (window.autoCreateAccountPreSkippedDetails && window.autoCreateAccountPreSkippedDetails.length > 0)
) {
    /* UPDATED (this adjustment — no overlapping screens): after an XLSX import the "Import Successful"
       result screen and the "Creating Company Accounts" popup used to open at the same moment, the popup
       showing dimmed behind the result screen. Now they come one after the other: the popup opens as soon
       as the result screen has closed (after its few seconds, or when OK is clicked — an "Import Failed"
       screen waits for OK). Anything else that queues accounts (e.g. the manual-add redirect fallback,
       where there is no import result screen) starts right away as before. */
    const cvImportRes = window.companyImportResult;
    const cvResultOv  = document.getElementById('globalResultOverlay');
    if (cvImportRes && cvResultOv && window.MutationObserver) {
        let cvAccountsStarted = false, cvResultSeen = !cvResultOv.classList.contains('hidden');
        const cvStartAccounts = function() {
            if (cvAccountsStarted) return;
            cvAccountsStarted = true;
            cvResultWatch.disconnect();
            runAutoAccountCreation(window.autoCreateAccountImportIds, window.autoCreateAccountPreSkippedDetails);
        };
        const cvResultWatch = new MutationObserver(function() {
            if (!cvResultOv.classList.contains('hidden')) cvResultSeen = true;
            else if (cvResultSeen) cvStartAccounts();
        });
        cvResultWatch.observe(cvResultOv, { attributes: true, attributeFilter: ['class'] });
        setTimeout(cvStartAccounts, cvImportRes.type === 'error' ? 60000 : 10000);   // safety net: never left waiting
    } else {
        runAutoAccountCreation(window.autoCreateAccountImportIds, window.autoCreateAccountPreSkippedDetails);
    }
}
</script>
<!-- ══════════════════════════════════════════════════════════════════════
     NEW (this adjustment) — COMPANY REQUIREMENTS NOTIFICATION POPUP
     ------------------------------------------------------------------------
     This page already had the "Company Requirements" side-menu indicator;
     now it also shows the same POPUP company_validation.php shows whenever a
     new item lands in its Notification Inbox — for every notification type:
       • New Request           — "has a new MOA request"
       • Revision Complied     — "complied with the flagged MOA revision"
       • Schedule Agreed       — "agreed to the proposed signing schedule"
       • Schedule Proposed     — "proposed a different signing schedule"
       • Requirement Uploaded  — "uploaded new requirement document(s)"
     The list comes straight from company_validation.php's own
     ajax_fetch_moa_notifications endpoint (read-only, nothing is marked as
     viewed), so the popup text, icons and rules are exactly the same as there.
     Only notifications that appear AFTER the page loaded pop up (a baseline is
     taken first, like company_validation.php does). The ids already seen are
     kept in sessionStorage for a short while, so moving from one admin page to
     another neither repeats a popup nor misses one that arrived in between.
     The check pauses while the tab is hidden and catches up when it is shown.
     Self-contained: no existing function, poller or style is changed.
     ══════════════════════════════════════════════════════════════════════ -->
<script>
(function () {
    'use strict';
    if (window._cvCompanyNotifPopupReady) return;
    window._cvCompanyNotifPopupReady = true;

    var CV_NOTIF_ENDPOINT      = 'company_validation.php';
    var CV_NOTIF_POLL_MS       = 5000;    // UPDATED (this adjustment): checked every 5s (was 15s) so the popup / indicator follow right away
    var CV_NOTIF_FIRST_MS      = 0;       // UPDATED (this adjustment): first check right away (was 1.5s)
    var CV_NOTIF_TOAST_MS      = 7000;    // same lifetime as company_validation.php's popup
    var CV_NOTIF_STORE_KEY     = 'cvCompanyNotifKnownIds';
    var CV_NOTIF_STORE_FRESH   = 45000;
    var CV_NOTIF_BADGE_DISPLAY = '';   // same value this page's own badge poller uses

    // Same icons / wording as company_validation.php (MOA_NOTIF_TYPE_META + showMoaNewRequestToast()).
    var CV_NOTIF_ICONS = {
        new_request:          'fa-file-signature',
        revision_complied:    'fa-check-double',
        schedule_agreed:      'fa-calendar-check',
        schedule_declined:    'fa-calendar-day',
        requirement_uploaded: 'fa-file-arrow-up'
    };
    var CV_NOTIF_MESSAGES = {
        new_request:          'has a new MOA request',
        revision_complied:    'complied with the flagged MOA revision',
        schedule_agreed:      'agreed to the proposed signing schedule',
        schedule_declined:    'proposed a different signing schedule',
        requirement_uploaded: 'uploaded new requirement document(s)'
    };

    var knownIds = null;      // Set of notification ids already seen; null until the baseline exists
    var inFlight = false;

    function esc(s) {
        return String(s == null ? '' : s).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;').replace(/'/g, '&#39;');
    }

    function readStore() {
        try {
            var raw = sessionStorage.getItem(CV_NOTIF_STORE_KEY);
            if (!raw) return null;
            var o = JSON.parse(raw);
            if (!o || !Array.isArray(o.ids) || (Date.now() - (o.ts || 0)) > CV_NOTIF_STORE_FRESH) return null;
            return new Set(o.ids.map(Number));
        } catch (e) { return null; }
    }
    function writeStore() {
        if (!knownIds) return;
        try { sessionStorage.setItem(CV_NOTIF_STORE_KEY, JSON.stringify({ ids: Array.from(knownIds), ts: Date.now() })); } catch (e) {}
    }

    // Stacks every .cv-top-toast on the page (newest below), below the undo toast while it is showing.
    // Uses this page's own cvLayoutTopToasts() when it has one, so both kinds of popup share one stack.
    function layoutToasts() {
        if (typeof window.cvLayoutTopToasts === 'function') { window.cvLayoutTopToasts(); return; }
        var top = 30;
        var undo = document.getElementById('undoToast');
        if (undo && undo.classList.contains('show')) top = Math.max(top, undo.getBoundingClientRect().bottom + 12);
        document.querySelectorAll('.cv-top-toast').forEach(function (el) {
            el.style.top = top + 'px';
            top += el.offsetHeight + 12;
        });
    }
    (function () {
        if (typeof window.cvLayoutTopToasts === 'function') return;   // that page already re-lays out on undo changes
        var undo = document.getElementById('undoToast');
        if (undo && window.MutationObserver) new MutationObserver(layoutToasts).observe(undo, { attributes: true, attributeFilter: ['class'] });
    })();

    // The popup itself — same markup as company_validation.php's cvShowTopToast().
    function showNotifPopup(companyName, notifType, r) {   // UPDATED (this adjustment): r = the notification row (for the click-through)
        var type = CV_NOTIF_MESSAGES[notifType] ? notifType : 'new_request';
        var div = document.createElement('div');
        div.className = 'cv-top-toast';
        div.setAttribute('role', 'status');
        div.innerHTML = '<i class="fas ' + esc(CV_NOTIF_ICONS[type]) + '"></i><span><strong>' + esc(companyName || 'A company') + '</strong> ' + esc(CV_NOTIF_MESSAGES[type]) + ' \u2014 check the Notification Inbox.</span>';
        document.body.appendChild(div);
        if (r && window.cvTagToast) window.cvTagToast(div, 'notif:' + r.id + ':' + (r.user_id || 0) + ':' + (r.notif_type || 'new_request'));   // NEW (this adjustment): clickable
        layoutToasts();
        requestAnimationFrame(function () { div.classList.add('show'); });
        setTimeout(function () {
            div.classList.remove('show');
            setTimeout(function () { div.remove(); layoutToasts(); }, 400);
        }, CV_NOTIF_TOAST_MS);
    }

    function setMoaBadge(count) {
        var badge = document.getElementById('sidebarMoaBadge');
        if (!badge) return;
        count = parseInt(count, 10) || 0;
        badge.textContent = count;
        badge.style.display = count > 0 ? CV_NOTIF_BADGE_DISPLAY : 'none';
    }

    function pollCompanyNotifications() {
        if (inFlight || document.hidden) return;
        inFlight = true;
        var fd = new FormData();
        fd.append('ajax_fetch_moa_notifications', '1');
        fetch(CV_NOTIF_ENDPOINT, { method: 'POST', body: fd, credentials: 'same-origin', cache: 'no-store' })
            .then(function (r) { return r.json(); })
            .then(function (data) {
                inFlight = false;
                if (!data || !data.success) return;
                var rows = data.rows || [];
                var ids = new Set(rows.map(function (r) { return Number(r.id); }));
                if (knownIds === null) {
                    // First check on this page: use the ids another admin page saw moments ago, if any;
                    // otherwise everything already waiting is the baseline and nothing pops up.
                    var stored = readStore();
                    // UPDATED (this adjustment): the indicator is brought up to date on every check, not only when something new arrives
                    if (!stored) { knownIds = ids; writeStore(); setMoaBadge(data.pending_count != null ? data.pending_count : rows.length); return; }
                    knownIds = stored;
                }
                var fresh = rows.filter(function (r) { return !knownIds.has(Number(r.id)); });
                knownIds = ids;
                writeStore();
                // UPDATED (this adjustment): always refresh the indicator, so it also goes down / hides when a notification is cleared
                setMoaBadge(data.pending_count != null ? data.pending_count : rows.length);
                if (!fresh.length) return;
                fresh.forEach(function (r) { showNotifPopup(r.company_name, r.notif_type, r); });   // UPDATED (this adjustment): row passed for the click-through
            })
            .catch(function () { inFlight = false; });
    }

    setTimeout(function () {
        pollCompanyNotifications();
        setInterval(pollCompanyNotifications, CV_NOTIF_POLL_MS);
    }, CV_NOTIF_FIRST_MS);
    document.addEventListener('visibilitychange', function () {
        if (!document.hidden && knownIds !== null) pollCompanyNotifications();
    });
    // NEW (this adjustment): also re-check the moment the window regains focus or the page is restored with the Back button
    window.addEventListener('focus', function () { if (knownIds !== null) pollCompanyNotifications(); });
    window.addEventListener('pageshow', function (e) { if (e.persisted) pollCompanyNotifications(); });
    window.addEventListener('pagehide', writeStore);
})();
</script>
<!-- ══════════════════════════════════════════════════════════════════════
     NEW (this adjustment) — APPLICATION REQUEST POPUP NOTIFICATION
     ------------------------------------------------------------------------
     This page already had the "Student Requirements" side-menu indicator;
     now it also shows the same POPUP administrator.php shows whenever a new
     student application request arrives:
       "<Student> submitted a new application request for <Company>
        — check the Application Requests inbox."
     The list comes straight from administrator.php's own read-only
     ?app_request_list=1 endpoint (id, student name, company), so the popup
     text and rules are exactly the same as there. Only requests that appear
     AFTER the page loaded pop up (the requests already waiting are the
     baseline). The ids already seen are kept in sessionStorage for a short
     while, so moving from one admin page to another neither repeats a popup
     nor misses one that arrived in between. The check pauses while the tab is
     hidden and catches up when it is shown. It shares the same top-of-page
     stack (.cv-top-toast) as the Company Requirements popup, so the two never
     overlap. Self-contained: no existing function, poller or style is changed.
     ══════════════════════════════════════════════════════════════════════ -->
<script>
(function () {
    'use strict';
    if (window._cvAppRequestPopupReady) return;
    window._cvAppRequestPopupReady = true;

    var APP_REQ_ENDPOINT     = 'administrator.php?app_request_list=1';
    var APP_REQ_POLL_MS      = 3000;    // UPDATED (this adjustment): checked every 3s (was 15s) so the popup / indicator follow right away
    var APP_REQ_FIRST_MS     = 0;       // UPDATED (this adjustment): first check right away (was 2s)
    var APP_REQ_TOAST_MS     = 7000;    // same lifetime as administrator.php's popup
    var APP_REQ_STORE_KEY    = 'cvAppRequestKnownIds';
    var APP_REQ_STORE_FRESH  = 45000;

    var knownIds = null;      // Set of request ids (strings) already seen; null until the baseline exists
    var inFlight = false;

    function esc(s) {
        return String(s == null ? '' : s).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;').replace(/'/g, '&#39;');
    }

    function readStore() {
        try {
            var raw = sessionStorage.getItem(APP_REQ_STORE_KEY);
            if (!raw) return null;
            var o = JSON.parse(raw);
            if (!o || !Array.isArray(o.ids) || (Date.now() - (o.ts || 0)) > APP_REQ_STORE_FRESH) return null;
            return new Set(o.ids.map(String));
        } catch (e) { return null; }
    }
    function writeStore() {
        if (!knownIds) return;
        try { sessionStorage.setItem(APP_REQ_STORE_KEY, JSON.stringify({ ids: Array.from(knownIds), ts: Date.now() })); } catch (e) {}
    }

    // Stacks every .cv-top-toast on the page (newest below), below the undo toast while it is showing.
    // Uses this page's own cvLayoutTopToasts() when it has one, so every kind of popup shares one stack.
    function layoutToasts() {
        if (typeof window.cvLayoutTopToasts === 'function') { window.cvLayoutTopToasts(); return; }
        var top = 30;
        var undo = document.getElementById('undoToast');
        if (undo && undo.classList.contains('show')) top = Math.max(top, undo.getBoundingClientRect().bottom + 12);
        document.querySelectorAll('.cv-top-toast').forEach(function (el) {
            el.style.top = top + 'px';
            top += el.offsetHeight + 12;
        });
    }

    // The popup itself — same markup, icon and wording as administrator.php's notifyNewAppRequests() / cvShowTopToast().
    function showAppRequestPopup(app) {
        var messageText = 'submitted a new application request' + (app.company_name ? ' for ' + app.company_name : '');
        var div = document.createElement('div');
        div.className = 'cv-top-toast';
        div.setAttribute('role', 'status');
        div.innerHTML = '<i class="fas fa-envelope-open-text"></i><span><strong>' + esc(app.full_name || 'A student') + '</strong> ' + esc(messageText) + ' \u2014 check the Application Requests inbox.</span>';
        document.body.appendChild(div);
        if (window.cvTagToast && app && app.id != null) window.cvTagToast(div, 'app:' + app.id);   // NEW (this adjustment): clickable
        layoutToasts();
        requestAnimationFrame(function () { div.classList.add('show'); });
        setTimeout(function () {
            div.classList.remove('show');
            setTimeout(function () { div.remove(); layoutToasts(); }, 400);
        }, APP_REQ_TOAST_MS);
    }

    function setAppBadge(count) {
        var badge = document.getElementById('sidebarAppBadge');
        if (!badge) return;
        count = parseInt(count, 10) || 0;
        badge.textContent = count;
        badge.style.display = count > 0 ? '' : 'none';   // UPDATED (this adjustment): '' falls back to this page's .sidebar-badge-app rule (display:inline-flex)
    }

    function pollAppRequests() {
        if (inFlight || document.hidden) return;
        inFlight = true;
        fetch(APP_REQ_ENDPOINT, { credentials: 'same-origin', cache: 'no-store' })
            .then(function (r) { return r.json(); })
            .then(function (data) {
                inFlight = false;
                if (!data || !Array.isArray(data.rows)) return;
                var rows = data.rows;
                var ids = new Set(rows.map(function (r) { return String(r.id); }));
                if (knownIds === null) {
                    // First check on this page: use the ids another admin page saw moments ago, if any;
                    // otherwise every request already waiting is the baseline and nothing pops up.
                    var stored = readStore();
                    // UPDATED (this adjustment): the indicator is brought up to date on every check, not only when something new arrives
                    if (!stored) { knownIds = ids; writeStore(); setAppBadge(data.count != null ? data.count : rows.length); return; }
                    knownIds = stored;
                }
                var fresh = rows.filter(function (r) { return !knownIds.has(String(r.id)); });
                knownIds = ids;
                writeStore();
                // UPDATED (this adjustment): always refresh the indicator, so it goes down / hides the moment a student cancels a request
                setAppBadge(data.count != null ? data.count : rows.length);
                if (!fresh.length) return;
                fresh.forEach(showAppRequestPopup);
            })
            .catch(function () { inFlight = false; });
    }

    setTimeout(function () {
        pollAppRequests();
        setInterval(pollAppRequests, APP_REQ_POLL_MS);
    }, APP_REQ_FIRST_MS);
    document.addEventListener('visibilitychange', function () {
        if (!document.hidden && knownIds !== null) pollAppRequests();
    });
    // NEW (this adjustment): also re-check the moment the window regains focus or the page is restored with the Back button
    window.addEventListener('focus', function () { if (knownIds !== null) pollAppRequests(); });
    window.addEventListener('pageshow', function (e) { if (e.persisted) pollAppRequests(); });
    window.addEventListener('pagehide', writeStore);
})();
</script>
<!-- ══════════════════════════════════════════════════════════════════════
     NEW (this adjustment) — SIDE MENU TOOLTIPS WHILE THE MENU IS CLOSED
     ------------------------------------------------------------------------
     When the side menu is collapsed to its icon rail, the link names are
     hidden, so hovering (or keyboard-focusing) an icon now shows its name in
     a tooltip — the same design as the "Apply Students" tooltip on
     admin_monitoring_dashboard.php (#amdFloatTip): a square navy box, small
     bold uppercase white text, soft shadow and an arrow. It sits to the RIGHT
     of the icon, arrow pointing at it, so it never covers the other icons.
     If the link has a notification badge showing, its count is added, e.g.
     "Company Requirements (4)".
     It only appears while the link's own label is actually hidden, so it never
     shows with the menu open (or on phones, where the open menu shows the
     labels). ONE floating element, pointer-events:none, so it never blocks a
     click; it hides on click, scroll, and whenever the menu is opened/closed.
     Self-contained: no existing sidebar markup, style, or script is changed.
     ══════════════════════════════════════════════════════════════════════ -->
<style>
    #cvSideTip { position: fixed; z-index: 3000; background: #1B2A4A; color: #fff; font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif; font-size: 10.5px; font-weight: 600; line-height: 1.3; text-transform: uppercase; letter-spacing: 0.3px; padding: 6px 10px; border-radius: 0; white-space: nowrap; box-shadow: 0 4px 14px rgba(27,42,74,0.30); pointer-events: none; opacity: 0; transform: translateX(-4px); transition: opacity .15s, transform .15s; }
    #cvSideTip.show { opacity: 1; transform: translateX(0); }
    #cvSideTip::after { content: ''; position: absolute; right: 100%; top: 50%; transform: translateY(-50%); border: 6px solid transparent; border-right-color: #1B2A4A; }
</style>
<script>
(function () {
    'use strict';
    var sb = document.getElementById('sidebar');
    if (!sb || document.getElementById('cvSideTip')) return;

    var tip = document.createElement('div');
    tip.id = 'cvSideTip';
    tip.setAttribute('role', 'tooltip');
    document.body.appendChild(tip);
    var current = null;

    // the sidebar link under the pointer / focus, or null
    function linkFrom(el) {
        var a = el && el.closest ? el.closest('a') : null;
        return (a && sb.contains(a)) ? a : null;
    }
    // only while the link's own label is hidden (menu closed)
    function labelOf(a) {
        var lt = a.querySelector('.link-text');
        // UPDATED (this adjustment): the closed menu now hides a name by fading it (visibility:hidden) instead of display:none
        if (!lt) return '';
        var ltStyle = window.getComputedStyle(lt);
        if (ltStyle.display !== 'none' && ltStyle.visibility !== 'hidden') return '';
        var text = (lt.textContent || '').replace(/\s+/g, ' ').trim();
        var badge = a.querySelector('[class*="sidebar-badge"]');
        if (badge && window.getComputedStyle(badge).display !== 'none') {
            var n = (badge.textContent || '').trim();
            if (n && n !== '0') text += ' (' + n + ')';
        }
        return text;
    }
    function show(a) {
        var text = labelOf(a);
        if (!text) { hide(); return; }
        current = a;
        tip.textContent = text;
        var r = a.getBoundingClientRect();
        tip.style.left = '0px'; tip.style.top = '0px';
        var h = tip.offsetHeight;
        tip.style.left = (r.right + 10) + 'px';
        tip.style.top = Math.min(Math.max(8, r.top + r.height / 2 - h / 2), window.innerHeight - h - 8) + 'px';
        tip.classList.add('show');
    }
    function hide() { current = null; tip.classList.remove('show'); }

    document.addEventListener('mouseover', function (e) { var a = linkFrom(e.target); if (a) show(a); });
    document.addEventListener('mouseout', function (e) { var a = linkFrom(e.target); if (a && !a.contains(e.relatedTarget)) hide(); });
    document.addEventListener('focusin', function (e) { var a = linkFrom(e.target); if (a) show(a); else hide(); });
    document.addEventListener('focusout', hide);
    document.addEventListener('click', hide, true);
    window.addEventListener('scroll', hide, true);
    window.addEventListener('resize', hide);
    // opening / closing the menu (existing toggle button) hides it right away
    if (window.MutationObserver) new MutationObserver(hide).observe(sb, { attributes: true, attributeFilter: ['class'] });
})();
</script>
<!-- NEW (this adjustment) — SMOOTHER SIDE MENU OPEN / CLOSE (see the matching styles in <head>).
     Watches the side menu's existing .collapsed class (the toggle button's own code is unchanged) and,
     only while it is moving, marks it .cv-sb-moving (clips its contents) and, when opening,
     .cv-sb-opening (fades the link names in). Both are removed as soon as the move is over. -->
<script>
(function () {
    'use strict';
    var sb = document.getElementById('sidebar');
    if (!sb || !window.MutationObserver) return;
    var wasCollapsed = sb.classList.contains('collapsed');
    var timer = null;
    new MutationObserver(function () {
        var isCollapsed = sb.classList.contains('collapsed');
        if (isCollapsed === wasCollapsed) return;       // some other class changed — nothing to animate
        wasCollapsed = isCollapsed;
        clearTimeout(timer);
        sb.classList.remove('cv-sb-opening');
        void sb.offsetWidth;                            // restart the label fade if toggled quickly
        sb.classList.add('cv-sb-moving');
        if (!isCollapsed) sb.classList.add('cv-sb-opening');
        timer = setTimeout(function () { sb.classList.remove('cv-sb-moving', 'cv-sb-opening'); }, 420);
    }).observe(sb, { attributes: true, attributeFilter: ['class'] });
})();
</script>
<!-- NEW (this adjustment): LOGOUT + BACK BUTTON. If the browser shows this page from its back / forward
     memory (for example after the admin has logged out and pressed Back), reload it from the server instead,
     so a logged-out visitor is sent to admin_login.php and has to sign in again. The loading page covers the
     old view while it reloads. -->
<script>
window.addEventListener('pageshow', function (e) {
    if (!e.persisted) return;
    var ov = document.getElementById('globalLoadingOverlay');
    if (ov) ov.classList.remove('hidden');
    window.location.reload();
});
</script>
<!-- ══════════════════════════════════════════════════════════════════════
     NEW (this adjustment) — EMAIL RECOVERY REQUEST POPUP + SIDE-MENU INDICATOR
     ------------------------------------------------------------------------
     Same popup style as administrator.php's application-request notification
     (the navy .cv-top-toast bar at the top of the page):
       "<Name> submitted a new email recovery request (Student account)
        — check Email Recovery Requests in Manage Accounts."
     and the same kind of live side-menu badge, on the "Manage Accounts" link.
     The list comes from monitoring.php?recovery_request_list=1 (read-only:
     Pending requests only — nothing is accepted, rejected or changed).
     • Only requests that arrive AFTER the page loaded pop up (the ones already
       waiting are the baseline); ids already seen are kept briefly in
       sessionStorage so moving between admin pages neither repeats a popup
       nor misses one that arrived in between.
     • The badge is refreshed on every check, so it also goes down / hides as
       soon as a request is accepted or rejected.
     • On monitoring.php the existing Email Recovery badges on the floating
       button (#recoveryFabBadge / #fabMainBadge) are kept in step too, through
       the page's own updateFabBadge().
     • Checked right away, then every 5 s; pauses while the tab is hidden and
       catches up when it is shown again or the window regains focus.
     Self-contained: no existing function, poller or style is changed.
     ══════════════════════════════════════════════════════════════════════ -->
<script>
(function () {
    'use strict';
    if (window._cvRecoveryPopupReady) return;
    window._cvRecoveryPopupReady = true;

    var RC_ENDPOINT    = 'monitoring.php?recovery_request_list=1';
    var RC_POLL_MS     = 5000;
    var RC_TOAST_MS    = 7000;    // same lifetime as the application-request popup
    var RC_STORE_KEY   = 'cvRecoveryKnownIds';
    var RC_STORE_FRESH = 45000;

    var knownIds = null, inFlight = false;

    function esc(s) {
        return String(s == null ? '' : s).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;').replace(/'/g, '&#39;');
    }
    function readStore() {
        try {
            var o = JSON.parse(sessionStorage.getItem(RC_STORE_KEY) || 'null');
            if (!o || !Array.isArray(o.ids) || (Date.now() - (o.ts || 0)) > RC_STORE_FRESH) return null;
            return new Set(o.ids.map(String));
        } catch (e) { return null; }
    }
    function writeStore() {
        if (!knownIds) return;
        try { sessionStorage.setItem(RC_STORE_KEY, JSON.stringify({ ids: Array.from(knownIds), ts: Date.now() })); } catch (e) {}
    }

    // shares one top-of-page stack with every other .cv-top-toast popup
    function layoutToasts() {
        if (typeof window.cvLayoutTopToasts === 'function') { window.cvLayoutTopToasts(); return; }
        var top = 30;
        var undo = document.getElementById('undoToast');
        if (undo && undo.classList.contains('show')) top = Math.max(top, undo.getBoundingClientRect().bottom + 12);
        document.querySelectorAll('.cv-top-toast').forEach(function (el) { el.style.top = top + 'px'; top += el.offsetHeight + 12; });
    }

    // the popup — same markup / style as the application-request popup
    function showRecoveryPopup(r) {
        var msg = 'submitted a new email recovery request' + (r.account_type ? ' (' + r.account_type + ' account)' : '');
        var div = document.createElement('div');
        div.className = 'cv-top-toast';
        div.setAttribute('role', 'status');
        div.innerHTML = '<i class="fas fa-envelope"></i><span><strong>' + esc(r.full_name || 'Someone') + '</strong> ' + esc(msg) + ' \u2014 check Email Recovery Requests in Manage Accounts.</span>';
        document.body.appendChild(div);
        if (window.cvTagToast && r && r.id != null) window.cvTagToast(div, 'recovery:' + r.id);   // NEW (this adjustment): clickable
        layoutToasts();
        requestAnimationFrame(function () { div.classList.add('show'); });
        setTimeout(function () {
            div.classList.remove('show');
            setTimeout(function () { div.remove(); layoutToasts(); }, 400);
        }, RC_TOAST_MS);
    }

    function setIndicators(count) {
        count = parseInt(count, 10) || 0;
        var badge = document.getElementById('sidebarRecoveryBadge');
        if (badge) { badge.textContent = count; badge.style.display = count > 0 ? '' : 'none'; }
        // monitoring.php only: keep its floating-button recovery badges in step (its own function)
        if (typeof window.updateFabBadge === 'function' && typeof window._fabCount === 'number' && window._fabCount !== count) {
            window.updateFabBadge(count - window._fabCount);
        }
    }

    function poll() {
        if (inFlight || document.hidden) return;
        inFlight = true;
        fetch(RC_ENDPOINT, { credentials: 'same-origin', cache: 'no-store' })
            .then(function (r) { return r.json(); })
            .then(function (data) {
                inFlight = false;
                if (!data || !Array.isArray(data.rows)) return;
                var rows = data.rows, ids = new Set(rows.map(function (r) { return String(r.id); }));
                setIndicators(data.count != null ? data.count : rows.length);
                if (knownIds === null) {
                    var stored = readStore();
                    if (!stored) { knownIds = ids; writeStore(); return; }   // baseline: nothing pops up
                    knownIds = stored;
                }
                var fresh = rows.filter(function (r) { return !knownIds.has(String(r.id)); });
                knownIds = ids;
                writeStore();
                fresh.forEach(showRecoveryPopup);
            })
            .catch(function () { inFlight = false; });
    }

    poll();
    setInterval(poll, RC_POLL_MS);
    document.addEventListener('visibilitychange', function () { if (!document.hidden && knownIds !== null) poll(); });
    window.addEventListener('focus', function () { if (knownIds !== null) poll(); });
    window.addEventListener('pageshow', function (e) { if (e.persisted) poll(); });
    window.addEventListener('pagehide', writeStore);
})();
</script>
<!-- ══════════════════════════════════════════════════════════════════════
     NEW (this adjustment) — LOGOUT CONFIRMATION POPUP + "LOGGING OUT" LOADING PAGE
     ------------------------------------------------------------------------
     Clicking the sidebar "Logout" button no longer logs out straight away:
       • A confirmation popup ("Log Out") asks "Are you sure you want to Log out?
         You need to login again to access your Account." with Cancel / Log out.
         Cancel, the Esc key or a click outside the box closes it and nothing
         happens (the admin stays signed in, on the same page).
       • "Log out" shows this page's own loading page with the label
         "Logging out" and then goes to admin_login.php?logout=1 (unchanged).
     Ctrl / Cmd / middle-click on Logout are caught too, so a background tab
     can never log the admin out without confirming.
     The click is caught before any other click handler (capture phase), so the
     page's usual "Loading" overlay for link clicks does not appear behind the
     popup; while leaving, the label is kept as "Logging out".
     Self-contained: the Logout link, its href and every other behaviour are
     unchanged (without JavaScript the link still logs out as before).
     ══════════════════════════════════════════════════════════════════════ -->
<script>
(function () {
    'use strict';
    if (window._cvLogoutConfirmReady) return;
    window._cvLogoutConfirmReady = true;

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
        var ov = document.getElementById('globalLoadingOverlay');
        var label = document.getElementById('globalLoadingLabel');
        if (label) label.textContent = 'Logging out';
        if (ov) ov.classList.remove('hidden');
    }
    function hideLoggingOut() {
        var ov = document.getElementById('globalLoadingOverlay');
        var label = document.getElementById('globalLoadingLabel');
        if (ov) ov.classList.add('hidden');
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
    // Back / Forward restore of this page: start clean
    window.addEventListener('pageshow', function (e) {
        if (!e.persisted) return;
        loggingOut = false; clearTimeout(stuckTimer);
        overlay.classList.remove('show'); pendingHref = null;
    });
})();
</script>
<!-- ══════════════════════════════════════════════════════════════════════
     NEW (this adjustment) — BUTTON TOOLTIPS (every button on this page)
     Same design as the "Apply Students" tooltip on admin_monitoring_dashboard.php, shown ABOVE the
     button (or below it when there is no room above) with its arrow pointing at the button.
     Text: data-cv-tip → aria-label → title → the button's own text → for icon-only buttons, a
     name taken from its icon (trash → Delete, pen → Edit, × → Close, ...).
     • Buttons that already have their own tooltip (the dashboard's "Apply Students" .amd-tip and
       admin_reports.php's [data-tooltip] icon buttons) are left exactly as they are.
     • A button's own title="" is kept, but the browser's plain grey title bubble is held back while
       this tooltip shows, so two tooltips never appear together.
     • ONE floating element, pointer-events:none (never blocks a click); hides on click, scroll,
       resize and Esc. Keyboard users get it when they Tab to a button.
     Self-contained: no existing button, handler or style is changed.
     ══════════════════════════════════════════════════════════════════════ -->
<script>
(function () {
    'use strict';
    if (window._cvBtnTipReady) return;
    window._cvBtnTipReady = true;

    var SEL = 'button, input[type="button"], input[type="submit"], input[type="reset"], [role="button"], a[class*="btn"], a[class*="button"]';
    var ICONS = [   // more specific names first (e.g. "book-open" before "pen")
        ['book-open', 'Open'], ['eye-slash', 'Hide'], ['trash', 'Delete'], ['pen', 'Edit'], ['pencil', 'Edit'], ['edit', 'Edit'],
        ['xmark', 'Close'], ['fa-times', 'Close'], ['fa-close', 'Close'], ['fa-eye', 'View'], ['file-export', 'Export'], ['file-import', 'Import'],
        ['download', 'Download'], ['upload', 'Upload'], ['print', 'Print'], ['user-plus', 'Add'], ['fa-plus', 'Add'], ['magnifying-glass', 'Search'],
        ['search', 'Search'], ['filter', 'Filter'], ['sync', 'Refresh'], ['rotate', 'Refresh'], ['redo', 'Refresh'], ['undo', 'Undo'],
        ['box-archive', 'Archive'], ['archive', 'Archive'], ['paper-plane', 'Send'], ['fa-check', 'Confirm'], ['arrow-left', 'Back'],
        ['chevron-left', 'Previous'], ['chevron-right', 'Next'], ['angle-left', 'Previous'], ['angle-right', 'Next'], ['copy', 'Copy'],
        ['comment', 'Comment'], ['calendar', 'Pick a date'], ['bell', 'Notifications'], ['sign-out', 'Logout'], ['right-from-bracket', 'Logout'], ['bars', 'Menu']
    ];

    var tip = document.createElement('div');
    tip.id = 'cvBtnTip';
    tip.setAttribute('role', 'tooltip');
    document.body.appendChild(tip);
    var current = null;

    function eligible(el) {
        var b = el && el.closest ? el.closest(SEL) : null;
        if (!b || b === tip) return null;
        if (b.closest('.amd-tip') || b.closest('[data-tooltip]')) return null;   // already has its own tooltip
        return b;
    }
    function iconName(b) {
        var i = b.querySelector('i[class*="fa-"], svg[class*="fa-"]');
        var cls = i ? (i.getAttribute('class') || '') : '';
        for (var k = 0; k < ICONS.length; k++) { if (cls.indexOf(ICONS[k][0]) !== -1) return ICONS[k][1]; }
        return '';
    }
    /* ─────────────────────────────────────────────────────────────────────
       UPDATED (this adjustment): tooltips now say, in a few simple words,
       WHAT the button does — instead of repeating the button's own name.
       Order: a description written for that exact button → the button's own
       title / label when it says more than its name → the description for
       that kind of button (this page first, then the general list) → the
       old behaviour as a last resort. Counters are understood, e.g.
       "Delete (2)" / "Export (Excluding 3)".
       ───────────────────────────────────────────────────────────────────── */
    var CV_TIP_GENERAL = {
        'cancel': 'Close this without saving',
        'close': 'Close this window',
        'dismiss': 'Hide this message',
        'ok': 'Close this message',
        'ok, got it': 'Close this message',
        'done': 'Close this summary',
        'save': 'Save your changes',
        'save changes': 'Save your changes',
        'save all': 'Save every course you edited',
        'yes, delete': 'Delete for good — this cannot be undone',
        'delete': 'Remove this item',
        'remove': 'Remove this item',
        'confirm': 'Yes, go ahead',
        'continue': 'Go on to the next step',
        'back': 'Go back to the previous step',
        'next': 'Go to the next page',
        'prev': 'Go to the previous page',
        'previous': 'Go to the previous page',
        'next page': 'Go to the next page',
        'previous page': 'Go to the previous page',
        'next month': 'Show the next month',
        'previous month': 'Show the previous month',
        'export excel': 'Download this list as an Excel file',
        'export to excel': 'Download this list as an Excel file',
        'export filtered': 'Download only the rows that match your filters',
        'export': 'Download this list as a file',
        'export (excluding n)': 'Download the list without the entries you ticked',
        'none, proceed with export': 'Export everyone in the list',
        'archive batch': 'Move a finished batch to the archive',
        'unarchive batch': 'Bring an archived batch back',
        'unarchive': 'Bring this batch back to the active list',
        'yes, unarchive': 'Bring the batch back to the active list',
        'view archived batches': 'See the batches you archived',
        'view archived company batches': 'See the company batches you archived',
        'undo': 'Reverse your last action',
        'refresh': 'Load the latest list',
        'print': 'Print this page',
        'save as pdf': 'Download this as a PDF file',
        'select all': 'Tick every item in this list',
        'clear': 'Untick every item in this list',
        'import selected': 'Import only the groups you ticked',
        'cancel import': 'Stop — nothing from the file is added',
        'skip these students': 'Import the rest and leave these students out',
        'no, skip these students': 'Import the rest and leave these students out',
        'no, skip this student': 'Leave this student out',
        'skip this student': 'Leave this student out',
        'yes, add course': 'Add this course to Course Offering first',
        'add course offering': 'Save this course to Course Offering',
        'edit': 'Choose one entry, then change it',
        'edit selected': 'Open the entry you picked for editing',
        'edit (n)': 'Edit the entries you picked',
        'delete (n)': 'Delete the entries you picked',
        'view': 'Open the full details',
        'full view': 'Open the full application',
        'details': 'Show the full details',
        'company details': "Show the company's details",
        'view requirements': "See this company's requirements",
        'view pdf': 'Open the document',
        'view pdf (locked)': 'Open the flagged document (read only)',
        'send': 'Send your message',
        'open chat': 'Chat with this company',
        'preview letter': 'See the letter before sending it',
        'apply & send endorsement letter': 'Assign the students and email the letter',
        'approve moa': "Approve this company's MOA",
        'reject': 'Reject it and say what to fix',
        'accept': 'Accept this request',
        'send & request revision': 'Ask the company to fix the flagged items',
        'set signing schedule': 'Pick the MOA signing date and time',
        're-schedule': 'Change the MOA signing date',
        'accept proposed schedule': "Agree to the company's proposed date",
        'review moa': 'Check the MOA the company sent',
        'moa workflow': "Track each company's MOA progress",
        'notification inbox': 'See new company notifications',
        'requirements': "See the companies' requirements",
        'requirements /': "See the companies' requirements",
        'allow': "Approve this student's application",
        'allow application': "Approve this student's application",
        'deny': "Decline this student's application",
        'deny application': "Decline this student's application",
        'approve & send letter': 'Approve and email the endorsement letter',
        'application requests': 'See new student application requests',
        'update id': "Save the student's new ID number",
        'add student': 'Add a student',
        'remove student': 'Take this student off the list',
        'confirm & create admin': 'Create the new admin account',
        'verify credentials': 'Check the details before creating the account',
        'verify otp': 'Confirm the code sent by email',
        'clear history': 'Remove all finished recovery requests',
        'clear log': 'Remove every activity log entry',
        'confirm accept': 'Approve this email change',
        'confirm reject': 'Decline this email change',
        'accept request': 'Approve this email change request',
        'reject request': 'Decline this email change request',
        'history': 'Show requests already handled',
        'pending': 'Show requests waiting for you',
        'quick actions': 'Open shortcuts for common tasks',
        'attendance': 'Show the attendance records',
        'reports': 'Show the weekly reports',
        'comment': 'Leave feedback on this report',
        'edit comment': 'Change your feedback',
        'save comment': 'Save your feedback',
        'upload': 'Let the student see this grade',
        'unupload': 'Hide this grade from the student',
        'upload selected': 'Show the ticked grades to students',
        'unupload selected': 'Hide the ticked grades from students',
        'backup now': 'Make a copy of the database now',
        'add company manually': 'Open the form to add one company',
        'add company': 'Save this new company',
        'import companies': 'Add many companies from an Excel file',
        'import students': 'Add many students from an Excel file',
        'log out': 'Sign out of your account',
        'logout': 'Sign out of your account',
        'notifications': 'See new notifications',
        'search': 'Search the list',
        'filter': 'Narrow down the list',
        'menu': 'Open the menu'
    };
    var CV_TIP_PAGE = {
        'admin_student_list.php': {
            'delete': 'Choose students to remove', 'yes': 'Choose students to leave out of the export', 'done': 'Close this summary'
        },
        'admin_company_list.php': {
            'delete': 'Choose companies to remove', 'yes': 'Choose companies to leave out of the export', 'done': 'Close this summary'
        },
        'course_offering.php': {
            'delete': 'Choose courses to remove', 'next course': 'Go to the next course', 'previous course': 'Go to the previous course'
        },
        'company_validation.php': { 'done': 'Mark this MOA as completed', 'back': 'Go back to the list' },
        'admin_final_grades.php': { 'export': 'Download the grades as a file' },
        'system_setting.php': { 'delete': 'Delete this backup', 'refresh': 'Load the latest backup list' },
        'monitoring.php': { 'delete': 'Delete this account for good' }
    };
    var CV_TIP_EXACT = [   // [CSS selector, description] — for buttons whose words mean different things on the same page
        ['#toggleBtn', null],
        ['#addStudentBtn', 'Open the form to add one student'],
        ['#addStudentForm button[type="submit"]', 'Save this new student'],
        ['#addCourseOfferingBtn', 'Open the form to add a course'],
        ['#importAddCourseSubmitBtn', 'Save this course, then continue'],
        ['#courseOfferingSubmitBtn', 'Save this course'],
        ['#addCompanyBtn', 'Open the form to add one company'],
        ['#alogClearBtn', 'Remove every activity log entry'],
        ['#studentImportFilterCloseBtn', 'Stop — nothing from the file is added'],
        ['#importClassificationCloseBtn', 'Stop — nothing from the file is added'],
        ['#exportChoiceCloseBtn', 'Cancel the export'],
        ['#globalResultOkBtn', 'Close this message']
    ];
    var CV_TIP_PAGE_NAME = (window.location.pathname.split('/').pop() || '').toLowerCase();
    function cvTipKey(t) {
        return String(t || '').replace(/\s+/g, ' ').trim().toLowerCase()
            .replace(/^[\u2190\u2192\u2039\u203a\u00ab\u00bb\u00d7\u2715<>\s]+|[\u2190\u2192\u2039\u203a\u00ab\u00bb\u00d7\u2715<>\s]+$/g, '')
            .replace(/\d+/g, 'n');
    }
    function cvTipDescribe(key) {
        var page = CV_TIP_PAGE[CV_TIP_PAGE_NAME] || {};
        // try the name as it is, then without a trailing counter ("Pending 3", "Requirements 2 / 5")
        var keys = [key, String(key).replace(/(\s+n(\s*\/\s*n)?)+$/, '').replace(/\s*\/\s*$/, '').trim()];
        for (var k = 0; k < keys.length; k++) {
            if (Object.prototype.hasOwnProperty.call(page, keys[k])) return page[keys[k]];
            if (Object.prototype.hasOwnProperty.call(CV_TIP_GENERAL, keys[k])) return CV_TIP_GENERAL[keys[k]];
        }
        return '';
    }
    function labelOf(b) {
        if (b.id === 'toggleBtn') {
            var sb = document.getElementById('sidebar');
            return sb && sb.classList.contains('collapsed') ? 'Show the full menu' : 'Make the menu smaller';
        }
        for (var i = 0; i < CV_TIP_EXACT.length; i++) {
            try { if (CV_TIP_EXACT[i][1] && b.matches(CV_TIP_EXACT[i][0])) return CV_TIP_EXACT[i][1]; } catch (x) {}
        }
        var visible = (b.tagName === 'INPUT' ? (b.value || '') : (b.textContent || '')).replace(/\s+/g, ' ').trim();
        if (/^[\u00D7\u2715xX]$/.test(visible)) visible = 'Close';
        var own = (b.getAttribute('data-cv-tip') || b.getAttribute('aria-label') || b.getAttribute('data-cv-title') || b.getAttribute('title') || '').replace(/\s+/g, ' ').trim();
        // the button's own title / label, when it says more than the button's name (more than one word)
        if (own && own.indexOf(' ') !== -1 && cvTipKey(own) !== cvTipKey(visible) && own.length <= 160 && !cvTipDescribe(cvTipKey(own))) return own;
        var d = cvTipDescribe(cvTipKey(visible)) || cvTipDescribe(cvTipKey(own));
        if (!d && (!visible || /^[^A-Za-z0-9]+$/.test(visible))) d = cvTipDescribe(cvTipKey(iconName(b)));
        if (d) return d;
        // last resort — the old behaviour
        var t = own || visible;
        if (!t || /^[^A-Za-z0-9]+$/.test(t)) t = iconName(b);
        return t.length > 60 ? '' : t;     // long text (e.g. whole cards acting as buttons) gets no tooltip
    }
    function holdTitle(b) { var t = b.getAttribute('title'); if (t) { b.setAttribute('data-cv-title', t); b.removeAttribute('title'); } }
    function releaseTitle(b) { var t = b && b.getAttribute('data-cv-title'); if (t !== null && t !== undefined && b) { if (!b.hasAttribute('title')) b.setAttribute('title', t); b.removeAttribute('data-cv-title'); } }

    function show(b) {
        var text = labelOf(b);
        if (!text) { hide(); return; }
        if (current && current !== b) releaseTitle(current);
        current = b; holdTitle(b);
        tip.textContent = text;
        var r = b.getBoundingClientRect();
        if (!r.width && !r.height) { hide(); return; }
        tip.style.left = '0px'; tip.style.top = '0px';
        var w = tip.offsetWidth, h = tip.offsetHeight;
        var left = Math.min(Math.max(8, r.left + r.width / 2 - w / 2), window.innerWidth - w - 8);
        var below = r.top - h - 10 < 8;
        tip.classList.toggle('below', below);
        tip.style.left = left + 'px';
        tip.style.top = (below ? r.bottom + 10 : r.top - h - 10) + 'px';
        tip.style.setProperty('--arrow-x', (r.left + r.width / 2 - left) + 'px');   // arrow stays on the button even when nudged sideways
        tip.classList.add('show');
    }
    function hide() { tip.classList.remove('show'); if (current) releaseTitle(current); current = null; }

    document.addEventListener('mouseover', function (e) { var b = eligible(e.target); if (b) { if (b !== current) show(b); } });
    // after a scroll (which hides the tooltip) bring it back as soon as the pointer moves over the button again
    document.addEventListener('mousemove', function (e) { if (tip.classList.contains('show')) return; var b = eligible(e.target); if (b) show(b); }, { passive: true });
    document.addEventListener('mouseout', function (e) { var b = eligible(e.target); if (b && b === current && !b.contains(e.relatedTarget)) hide(); });
    document.addEventListener('focusin', function (e) {
        var b = eligible(e.target);
        var kb = false; try { kb = e.target.matches(':focus-visible'); } catch (x) { kb = false; }
        if (b && kb) show(b); else if (!b) hide();
    });
    document.addEventListener('focusout', function () { hide(); });
    document.addEventListener('click', hide, true);
    document.addEventListener('keydown', function (e) { if (e.key === 'Escape') hide(); });
    window.addEventListener('scroll', hide, true);
    window.addEventListener('resize', hide);
})();
</script>
<!-- ══════════════════════════════════════════════════════════════════════
     NEW (this adjustment) — CLICKABLE NOTIFICATION POPUPS
     ------------------------------------------------------------------------
     Every notification popup (the navy bar at the top of the page) can now be
     clicked — or reached with Tab and opened with Enter — and takes the admin
     straight to what it is about:
       • Company Requirements notification (new MOA request, revision complied,
         schedule agreed / proposed, requirement uploaded)
           → company_validation.php, running that page's own "View" action for
             the notification (the company's row / MOA review, or the
             Requirements tab for an upload) — exactly like its inbox button.
       • Application request → administrator.php: the Application Requests
         inbox opens and then that student's request (Full View).
       • Email recovery request → monitoring.php: the Email Recovery Requests
         drawer opens on Pending and scrolls to / highlights that request.
     On the page that owns the subject it happens right there; from any other
     page it goes to that page (with the loading page) and happens after load.
     The one-time link parameter is removed from the address bar first, so a
     refresh never repeats it. If the subject was already handled meanwhile,
     the inbox / drawer is simply opened. A small "View ›" marks the popups.
     Self-contained: no existing popup text, timing, poller or style changes.
     ══════════════════════════════════════════════════════════════════════ -->
<script>
(function () {
    'use strict';
    if (window._cvToastGoReady) return;
    window._cvToastGoReady = true;

    // ── marks a popup as clickable; spec = "notif:<id>:<companyUserId>:<type>" | "app:<id>" | "recovery:<id>"
    window.cvTagToast = function (el, spec) {
        if (!el || !spec || el.hasAttribute('data-cv-go')) return;
        el.setAttribute('data-cv-go', spec);
        el.setAttribute('role', 'link');
        el.setAttribute('tabindex', '0');
        el.setAttribute('aria-label', (el.textContent || '').replace(/\s+/g, ' ').trim() + ' — open');
        var hint = document.createElement('span');
        hint.className = 'cv-toast-go';
        hint.setAttribute('aria-hidden', 'true');
        hint.innerHTML = 'View <i class="fas fa-chevron-right"></i>';
        el.appendChild(hint);
    };

    function g(name) { try { return window[name] || (0, eval)('typeof ' + name + ' !== "undefined" ? ' + name + ' : undefined'); } catch (e) { return undefined; } }
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
        el.classList.remove('cv-go-highlight'); void el.offsetWidth; el.classList.add('cv-go-highlight');
        setTimeout(function () { el.classList.remove('cv-go-highlight'); }, 2800);
    }
    function goTo(url) {
        var ov = document.getElementById('globalLoadingOverlay'), label = document.getElementById('globalLoadingLabel');
        if (label) label.textContent = 'Loading';
        if (ov) ov.classList.remove('hidden');
        window.location.href = url;
    }

    // ── Company Requirements notification → company_validation.php's own "View" action ──
    function openNotif(id, uid, type) {
        var view = g('viewMoaNotification');
        if (typeof view !== 'function') {
            goTo('company_validation.php?open_notif=' + encodeURIComponent(id) + '&uid=' + encodeURIComponent(uid) + '&type=' + encodeURIComponent(type || 'new_request'));
            return;
        }
        var numId = parseInt(id, 10), numUid = parseInt(uid, 10);
        var list = function () { var l = g('_allMoaNotifications'); return Array.isArray(l) ? l : null; };
        var has = function () { var l = list(); return l && l.some(function (r) { return r.id === numId; }); };
        var run = function () {
            // already handled / not in the list any more: still go to the company, as the right kind of notification
            var l = list();
            if (l && !has()) l.push({ id: numId, user_id: numUid, notif_type: type || 'new_request', detail: [] });
            view(numId, numUid);
        };
        if (has()) { run(); return; }
        var load = g('loadMoaNotifications');
        if (typeof load === 'function') { try { load(); } catch (e) {} }
        waitFor(has, 4000).then(run);
    }

    // ── Application request → administrator.php: inbox, then that request's Full View ──
    function openApp(id) {
        var openInbox = g('openAppInbox'), fullView = g('openAppFullView');
        if (typeof openInbox !== 'function' || typeof fullView !== 'function') {
            goTo('administrator.php?open_app_request=' + encodeURIComponent(id));
            return;
        }
        var ov = document.getElementById('appRequestOverlay');
        if (!ov || ov.style.display !== 'flex') openInbox();
        waitFor(function () { var a = g('_fvAllApps'); return Array.isArray(a) && a.some(function (x) { return String(x.id) === String(id); }); }, 6000)
            .then(function (found) {
                if (found) { fullView(id); return; }
                // no longer pending (approved / denied / withdrawn meanwhile): the inbox stays open
            });
    }

    // ── Email recovery request → monitoring.php: drawer on Pending, that request highlighted ──
    function openRecovery(id) {
        var openDrawer = g('openRecoveryDrawer');
        if (typeof openDrawer !== 'function') { goTo('monitoring.php?open_recovery=' + encodeURIComponent(id)); return; }
        openDrawer();
        var sw = g('switchRecoveryTab'); if (typeof sw === 'function') sw('pending');
        var card = function () { return document.getElementById('reqCard' + id); };
        if (!card()) {
            var sync = g('cvSyncRecoveryCards');
            if (typeof sync === 'function') {
                fetch('monitoring.php?recovery_request_list=1', { credentials: 'same-origin', cache: 'no-store' })
                    .then(function (r) { return r.json(); }).then(function (d) { if (d && Array.isArray(d.rows)) sync(d.rows); }).catch(function () {});
            }
        }
        waitFor(card, 6000).then(function (c) {
            if (!c) return;
            var h = document.getElementById('reqHistoryItems');
            if (h && h.contains(c) && typeof sw === 'function') sw('history');   // already accepted / rejected
            setTimeout(function () { highlight(c); }, 250);
        });
    }

    // NEW (this adjustment): new requirement submission → administrator.php opens that student's requirements
    function openStudentUpload(id, uid) {
        var view = g('cvViewStudentUpload');
        if (typeof view === 'function') { view(parseInt(id, 10), parseInt(uid, 10)); return; }
        goTo('administrator.php?open_student_upload=' + encodeURIComponent(id) + '&uid=' + encodeURIComponent(uid));
    }

    function go(spec) {
        var p = String(spec || '').split(':');
        if (p[0] === 'studentupload') { openStudentUpload(p[1], p[2]); return; }
        if (p[0] === 'notif') openNotif(p[1], p[2], p[3]);
        else if (p[0] === 'app') openApp(p[1]);
        else if (p[0] === 'recovery') openRecovery(p[1]);
    }

    function activate(toast) {
        var spec = toast.getAttribute('data-cv-go');
        toast.classList.remove('show');
        setTimeout(function () { if (toast.parentNode) toast.parentNode.removeChild(toast); var lay = g('cvLayoutTopToasts'); if (typeof lay === 'function') lay(); }, 350);
        go(spec);
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

    // ── arriving from a popup on another page: do it here once the page has loaded ──
    var params = new URLSearchParams(window.location.search), spec = null;
    if (params.get('open_notif')) spec = 'notif:' + params.get('open_notif') + ':' + (params.get('uid') || 0) + ':' + (params.get('type') || 'new_request');
    else if (params.get('open_app_request')) spec = 'app:' + params.get('open_app_request');
    else if (params.get('open_recovery')) spec = 'recovery:' + params.get('open_recovery');
    else if (params.get('open_student_upload')) spec = 'studentupload:' + params.get('open_student_upload') + ':' + (params.get('uid') || 0);   // NEW (this adjustment)
    if (spec) {
        ['open_notif', 'uid', 'type', 'open_app_request', 'open_recovery', 'open_student_upload'].forEach(function (k) { params.delete(k); });
        if (window.history.replaceState) {
            var q = params.toString();
            window.history.replaceState({}, document.title, window.location.pathname + (q ? '?' + q : '') + window.location.hash);
        }
        var start = function () { setTimeout(function () { go(spec); }, 600); };
        if (document.readyState === 'complete') start(); else window.addEventListener('load', start);
    }
})();
</script>
<!-- ══════════════════════════════════════════════════════════════════════
     NEW (this adjustment) — NEW REQUIREMENT SUBMISSION POPUP + INDICATOR
     Same design and behaviour as the other popups on this page (and as
     company_validation.php's "uploaded new requirement document(s)" popup):
     checked right away and then every 4 s; submissions that were already
     waiting when the page opened do not pop up; each one pops up once (a
     further file merged into it pops up again, listing everything); clicking
     it opens that student's requirements (administrator.php). The Student Validation indicator is
     kept in step with the Inbox total (application requests + submissions).
     Ported from administrator.php: the submissions list, the detection and the
     indicator count all come from administrator.php?student_upload_list=1, so
     every admin page shows the same popups once, whichever page is open.
     ══════════════════════════════════════════════════════════════════════ -->
<script>
(function () {
    'use strict';
    if (window._cvStudentUploadPopupReady) return;
    window._cvStudentUploadPopupReady = true;
    var SRU_ENDPOINT    = 'administrator.php?student_upload_list=1';
    var SRU_POLL_MS     = 4000;
    var SRU_TOAST_MS    = 7000;
    var SRU_STORE_KEY   = 'cvStudentUploadKnown';
    var SRU_STORE_FRESH = 45000;
    var known = null, inFlight = false;
    function esc(s) { return String(s == null ? '' : s).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;').replace(/'/g, '&#39;'); }
    function sigOf(r) { return String(r.id) + '@' + String(r.sig || ''); }
    function readStore() {
        try {
            var o = JSON.parse(sessionStorage.getItem(SRU_STORE_KEY) || 'null');
            if (!o || !Array.isArray(o.ids) || (Date.now() - (o.ts || 0)) > SRU_STORE_FRESH) return null;
            return new Set(o.ids.map(String));
        } catch (e) { return null; }
    }
    function writeStore() { if (!known) return; try { sessionStorage.setItem(SRU_STORE_KEY, JSON.stringify({ ids: Array.from(known), ts: Date.now() })); } catch (e) {} }
    function layoutToasts() {
        if (typeof window.cvLayoutTopToasts === 'function') { window.cvLayoutTopToasts(); return; }
        var top = 30, undo = document.getElementById('undoToast');
        if (undo && undo.classList.contains('show')) top = Math.max(top, undo.getBoundingClientRect().bottom + 12);
        document.querySelectorAll('.cv-top-toast').forEach(function (el) { el.style.top = top + 'px'; top += el.offsetHeight + 12; });
    }
    function showPopup(r) {
        var labels = (r.items || []).map(function (i) { return i.label || i.key || ''; }).filter(Boolean);
        var what = labels.length === 1 ? 'a new requirement: ' + labels[0] : (labels.length + ' new requirements: ' + labels.join(', '));
        var div = document.createElement('div');
        div.className = 'cv-top-toast';
        div.setAttribute('role', 'status');
        if (r.kind === 'placement') {   // NEW (this adjustment): preferred placement replaced → the new Application SIT needs validation
            div.innerHTML = '<i class="fas fa-right-left"></i><span><strong>' + esc(r.full_name || 'A student') + '</strong> replaced the preferred placement \u2014 the new Application SIT needs validation.</span>';
        } else {
            div.innerHTML = '<i class="fas fa-file-arrow-up"></i><span><strong>' + esc(r.full_name || 'A student') + '</strong> submitted ' + esc(what) + ' \u2014 check the Application Requests inbox.</span>';
        }
        document.body.appendChild(div);
        if (window.cvTagToast) window.cvTagToast(div, 'studentupload:' + r.id + ':' + r.user_id);   // clickable
        layoutToasts();
        requestAnimationFrame(function () { div.classList.add('show'); });
        setTimeout(function () { div.classList.remove('show'); setTimeout(function () { div.remove(); layoutToasts(); }, 400); }, SRU_TOAST_MS);
    }
    function setBadge(count) {
        var badge = document.getElementById('sidebarAppBadge');
        if (!badge) return;
        count = parseInt(count, 10) || 0;
        badge.textContent = count;
        badge.style.display = count > 0 ? 'inline-flex' : 'none';
    }
    function poll() {
        if (inFlight || document.hidden) return;
        inFlight = true;
        fetch(SRU_ENDPOINT, { credentials: 'same-origin', cache: 'no-store' })
            .then(function (r) { return r.json(); })
            .then(function (d) {
                inFlight = false;
                if (!d || !d.success || !Array.isArray(d.rows)) return;
                var now = new Set(d.rows.map(sigOf));
                setBadge(d.count);
                if (typeof window.cvRenderStudentUploads === 'function') window.cvRenderStudentUploads(d.rows);   // administrator.php's Inbox, if open
                if (known === null) { known = readStore() || now; if (known === now) { writeStore(); return; } }
                var fresh = d.rows.filter(function (r) { return !known.has(sigOf(r)); });
                known = now; writeStore();
                fresh.forEach(showPopup);
            })
            .catch(function () { inFlight = false; });
    }
    setTimeout(function () { poll(); setInterval(poll, SRU_POLL_MS); }, 0);
    document.addEventListener('visibilitychange', function () { if (!document.hidden && known !== null) poll(); });
    window.addEventListener('focus', function () { if (known !== null) poll(); });
    window.addEventListener('pageshow', function (e) { if (e.persisted) poll(); });
    window.addEventListener('pagehide', writeStore);
})();
</script>
</body>
</html>