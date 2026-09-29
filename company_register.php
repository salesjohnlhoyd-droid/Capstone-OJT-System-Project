<?php
ini_set('log_errors', 1);
ini_set('error_log', 'C:/xampp/tmp/php_errors.log');
session_start();

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

require 'PHPMailer/src/PHPMailer.php';
require 'PHPMailer/src/SMTP.php';
require 'PHPMailer/src/Exception.php';

include "db.php";

// ════════════════════════════════════════════════════════════════════════
//  Load MOA_form_builder.php (the SAME builder moa_request.php uses
//  for its on-screen "Request New MOA" preview) so this registration page
//  can offer an identical live "Preview MOA" button in Step 2, before the
//  account even exists. This does not replace or change
//  regBuildMOAStaticHTMLForDompdf() further down in this file — that
//  remains exactly as it was and is still what the Preview endpoint uses
//  as its fallback, AND (as of this update) is also what actually
//  generates the MOA document saved to company_requirements on successful
//  registration — see the STEP 2d block in the POST handler further down.
// ════════════════════════════════════════════════════════════════════════
if (file_exists(__DIR__ . '/MOA_form_builder.php')) {
    require_once __DIR__ . '/MOA_form_builder.php';
}

// ════════════════════════════════════════════════════════════════════════
//  "Preview MOA" endpoint (Step 2, "Request New MOA" flow only).
//  ──────────────────────────────────────────────────────────────────────
//  Called via a hidden form POST (see openNewMoaPreview() in the Step 2
//  script further down) that submits the company/contact fields the
//  company has typed so far into a hidden target iframe inside a modal.
//  No account exists yet at this point — nothing is read from or written
//  to the database here; this only renders the MOA preview HTML from the
//  posted values, using buildMOAFormHTML() from MOA_form_builder.php
//  (the exact same function moa_request.php uses for its own Step 3 /
//  status-view MOA preview), so the preview the company sees here looks
//  identical to what they would see later in moa_request.php.
//
//  Falls back to this file's own regBuildMOAStaticHTMLForDompdf() (already
//  defined below) if MOA_form_builder.php is unavailable for any reason,
//  so the Preview button still works either way.
//
//  This endpoint intentionally sits BEFORE the company_information /
//  company_requirements table-creation queries further down, since a
//  preview render needs none of that — it exits immediately after
//  streaming the HTML.
// ════════════════════════════════════════════════════════════════════════
if (isset($_GET['preview_new_moa'])) {
    $pFirst          = trim($_POST['first_name']      ?? '');
    $pMiddle         = trim($_POST['middle_name']     ?? '');
    $pLast           = trim($_POST['last_name']       ?? '');
    $pCompanyName    = trim($_POST['company_name']    ?? '');
    $pCompanyAddress = trim($_POST['company_address'] ?? '');
    $pPosition       = trim($_POST['position']        ?? '');
    $pCompanyProfile = trim($_POST['company_profile'] ?? '');

    $repFullName = preg_replace('/\s+/', ' ', trim("$pFirst $pMiddle $pLast"));
    $repPositionForPreview = $pPosition !== '' ? $pPosition : 'Manager/Head/Director';

    $previewHtml = '';
    if (function_exists('buildMOAFormHTML')) {
        $previewHtml = buildMOAFormHTML([
            'moa_number'              => '',
            'moa_year'                => date('Y'),
            'company_name'            => $pCompanyName,
            'company_description'     => $pCompanyProfile,
            'company_address'         => $pCompanyAddress,
            'contact_first_name'      => $pFirst,
            'contact_middle_name'     => $pMiddle,
            'contact_last_name'       => $pLast,
            'representative_name'     => $repFullName,
            'representative_position' => $repPositionForPreview,
            'signing_date'            => '',
            'signing_place'           => '',
            'notary_city'             => '',
            'company_id'              => '',
        ]);
    }

    if (empty($previewHtml)) {
        // Fallback: MOA_form_builder.php not found/loaded for some reason —
        // reuse this file's own Dompdf-safe static builder (defined further
        // down in this same file; PHP registers top-level function
        // declarations at compile time, so calling it here before its
        // textual position in the file is safe) so the Preview button still
        // renders something instead of failing silently.
        $previewHtml = regBuildMOAStaticHTMLForDompdf([
            'moa_number'              => '',
            'moa_year'                => date('Y'),
            'company_name'            => $pCompanyName,
            'company_description'     => $pCompanyProfile,
            'company_address'         => $pCompanyAddress,
            'representative_name'     => $repFullName,
            'representative_position' => $repPositionForPreview,
            'signing_date'            => '',
            'signing_place'           => '',
            'notary_city'             => '',
            'company_id'              => '',
        ]);
    }

    header('Content-Type: text/html; charset=utf-8');
    echo $previewHtml;
    exit();
}

// ── Popup state variables ─────────────────────────────────────────────────
$popup_type     = ''; // 'error' | 'success' | 'warning'
$popup_title    = '';
$popup_msg      = '';
$popup_redirect = ''; // URL to redirect to after OK click (empty = stay)

// ── which step of the multi-step form to return the user to if a
// server-side validation error occurs. Defaults to Step 1; each error
// branch below sets this to the step that actually contains the problem
// field, so a failed submission re-opens the form on the right page
// instead of forcing the company to click back through every step again.
$popup_step     = 1;

// ════════════════════════════════════════════════════════════════════════
//  COMPANY CLASSIFICATION (PRIVATE / PUBLIC) + COMPLIANCE
//  REQUIREMENTS INTEGRATION
//  ──────────────────────────────────────────────────────────────────────
//  CompanyForm.php asks every logged-in company to declare whether it is
//  a Private or Public/Government entity and then upload a different set
//  of compliance documents depending on that classification, storing each
//  document as a row in `company_requirements` (requirement_type + blob)
//  and the company's basic info in `company_information`.
//
//  This section collects that same classification + document set at
//  registration time, using the EXACT SAME requirement keys/labels and
//  the EXACT SAME upload rules (JPG/PDF, 5MB max) that CompanyForm.php's
//  saveCompanyRequirement() already enforces, so a company that registers
//  here sees the identical "Requirements" page state (per-document
//  Pending status) the moment they first log in — no schema or workflow
//  drift between the two files.
//
//  DATA ROUTING — on a successful submission:
//    - Account/name/credential/classification fields (first, middle, last
//      name, email, password, company type, and a fixed "New" request
//      type) are stored on the `users` row itself.
//    - Company detail fields (company name, address, telephone, position,
//      company profile) are stored in `company_information`.
//    - Every compliance document upload AND the auto-generated MOA
//      document (see the STEP 2d block in the POST handler further down)
//      are stored as rows in `company_requirements`.
//    No `moa_requests` row is ever created by this file — that table
//    belongs to the separate moa_request.php flow used after login.
// ════════════════════════════════════════════════════════════════════════
$conn->query("CREATE TABLE IF NOT EXISTS company_information (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    company VARCHAR(200) DEFAULT '',
    company_address VARCHAR(300) DEFAULT '',
    company_profile TEXT NULL,
    telephone VARCHAR(30) DEFAULT '',
    contact_first_name VARCHAR(100) DEFAULT '',
    contact_middle_initial VARCHAR(100) DEFAULT '',
    contact_last_name VARCHAR(100) DEFAULT '',
    position VARCHAR(150) DEFAULT '',
    company_type VARCHAR(20) DEFAULT 'private'
)");

// Guard for installations where company_information already existed
// before the company_profile column was added here.
$rCompanyProfileCol = $conn->query("SHOW COLUMNS FROM company_information LIKE 'company_profile'");
if ($rCompanyProfileCol && $rCompanyProfileCol->num_rows === 0) {
    $conn->query("ALTER TABLE company_information ADD COLUMN company_profile TEXT NULL AFTER company_address");
}

$conn->query("CREATE TABLE IF NOT EXISTS company_requirements (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    requirement_type VARCHAR(50) NOT NULL,
    file_name LONGBLOB NULL,
    status VARCHAR(20) DEFAULT 'Pending',
    remark TEXT NULL,
    submitted_at DATETIME DEFAULT CURRENT_TIMESTAMP
)");

// ════════════════════════════════════════════════════════════════════════
//  NEW (this update) — REMOVE ANY LEGACY UNIQUE CONSTRAINT THAT BLOCKS
//  MULTIPLE FILES PER REQUIREMENT.
//  ──────────────────────────────────────────────────────────────────────
//  The CREATE TABLE IF NOT EXISTS above is a NO-OP whenever
//  company_requirements already exists in the live database — which is
//  the normal case for an established installation. This means this file
//  has never actually verified the REAL, live constraints on that table;
//  it only guarantees the columns it explicitly ALTERs for below.
//
//  This was confirmed to be the real root cause of "I selected 3 files
//  for one requirement but only 1 got saved": if the table was originally
//  created elsewhere (an older script, or set up by hand) with a UNIQUE
//  KEY covering (user_id, requirement_type) — a natural-looking choice
//  for what looks like a "one document per requirement" design — then
//  the FIRST file for a requirement inserts fine, but the SECOND and
//  THIRD silently fail with a duplicate-key error at execute() time
//  (already logged and skipped past without aborting the rest of the
//  loop, per the earlier fix below) — landing exactly on "1 of 3 saved"
//  with no other symptom, for every multi-file requirement, for every
//  company, while single-file requirements (and different companies
//  sharing the same requirement_type) always looked completely normal.
//
//  This queries the table's actual live indexes and drops any UNIQUE
//  index whose columns are (or include) exactly user_id + requirement_type
//  together — the only index shape that could cause this — leaving any
//  OTHER index (e.g. a plain non-unique index for query performance, or
//  the PRIMARY KEY on id) completely untouched. Runs once per request but
//  is fully idempotent: once the constraint is gone, this simply finds
//  nothing to drop on every subsequent load.
// ════════════════════════════════════════════════════════════════════════
try {
    $resIdx = $conn->query("SHOW INDEX FROM company_requirements WHERE Non_unique = 0 AND Key_name != 'PRIMARY'");
    if ($resIdx) {
        $uniqueIndexColumns = []; // Key_name => [column names...]
        while ($idxRow = $resIdx->fetch_assoc()) {
            $uniqueIndexColumns[$idxRow['Key_name']][] = $idxRow['Column_name'];
        }
        foreach ($uniqueIndexColumns as $idxName => $cols) {
            $colsLower = array_map('strtolower', $cols);
            $hasUserId = in_array('user_id', $colsLower, true);
            $hasReqType = in_array('requirement_type', $colsLower, true);
            if ($hasUserId && $hasReqType) {
                error_log('[REG SCHEMA] Found legacy UNIQUE index "' . $idxName . '" on company_requirements('
                    . implode(',', $cols) . ') — this blocks saving more than one file per requirement per '
                    . 'company. Dropping it now so multi-file uploads (e.g. multiple JPEGs for one requirement) '
                    . 'can be saved correctly.');
                $dropOk = $conn->query("ALTER TABLE company_requirements DROP INDEX `" . $conn->real_escape_string($idxName) . "`");
                if (!$dropOk) {
                    error_log('[REG SCHEMA] Failed to drop index "' . $idxName . '": ' . $conn->error);
                }
            }
        }
    }
} catch (\Throwable $e) {
    error_log('[REG SCHEMA] Exception while checking/dropping legacy unique index on company_requirements: ' . $e->getMessage());
}

// CompanyForm.php reads users.company_type as the default classification
// the first time a company's company_information row is created, so make
// sure that column exists here too.
$rCompanyTypeCol = $conn->query("SHOW COLUMNS FROM users LIKE 'company_type'");
if ($rCompanyTypeCol && $rCompanyTypeCol->num_rows === 0) {
    $conn->query("ALTER TABLE users ADD COLUMN company_type VARCHAR(20) NULL AFTER role");
}

// ── request_type column on users: holds the fixed "New" value for every
// account created through this registration flow.
$rRequestTypeCol = $conn->query("SHOW COLUMNS FROM users LIKE 'request_type'");
if ($rRequestTypeCol && $rRequestTypeCol->num_rows === 0) {
    $conn->query("ALTER TABLE users ADD COLUMN request_type VARCHAR(20) NULL AFTER company_type");
}

// Same requirement keys + labels defined in CompanyForm.php ($private_reqs /
// $public_reqs) — kept identical so requirement_type values line up exactly
// with what the admin panel and CompanyForm.php already expect.
$private_reqs = [
    "company_profile"        => "Company Profile",
    "vision_mission"         => "Vision and Mission",
    "mayors_permit"          => "Mayor's Permit (LGU)",
    "sec_registration"       => "SEC Registration Certificate",
    "dti_registration"       => "DTI Certificate",
    "cda_registration"       => "CDA Certificate",
    "bir_clearance"          => "BIR Tax Clearance",
    "ohs_plan"               => "OHS Plan (DOLE)",
    "training_supervisor_cv" => "Training Supervisor CV",
    "authority_moa"          => "Authority to Sign MOA",
];

$public_reqs = [
    "authority_moa_public"    => "Authority to Sign MOA",
    "training_supervisor_pds" => "Training Supervisor PDS",
    "legislative_charter"     => "Legislative Charter / Legal Authority",
];

$all_company_reqs = [
    "private" => $private_reqs,
    "public"  => $public_reqs,
];

// Same upload rules as CompanyForm.php's saveCompanyRequirement(), except
// this registration page now only accepts JPG and PDF files (PNG has been
// dropped) — see the accept="" attributes and client-side validation on
// the Step 3 requirement file inputs further down.
$reqMaxFileSizeMB = 5;
$reqAllowedMimes  = ['image/jpeg', 'application/pdf'];

/**
 * Validate the compliance-requirement uploads (req_<key>[] file inputs)
 * for the given requirement set, mirroring the checks CompanyForm.php's
 * saveCompanyRequirement() performs (mime type + 5MB size cap), but done
 * up-front here so a bad/missing upload never leaves a half-finished
 * account behind.
 *
 * FIX (this update) — "multiple JPEGs selected for one requirement, only
 * one gets saved" persisted even after the previous array_values()
 * re-indexing fix. After exhaustively re-auditing this function and
 * regSaveCompanyRequirements() (which both already correctly walk every
 * file in a multi-file selection), the remaining explanation is something
 * that happens BEFORE this script ever runs: PHP's own `max_file_uploads`
 * ini directive (default 20) silently truncates $_FILES during multipart
 * parsing if the total number of files in one request is too high — and
 * this cannot be fixed from inside the script with ini_set(), because the
 * truncation has already happened by the time this code executes. Since
 * this can't be corrected in code, this function now instead DETECTS it:
 * the Step 3 file inputs are paired with a hidden
 * "req_<key>_expected_count" field (kept in sync client-side by
 * handleReqUploadChange()/renderReqUploadPreview() further down) recording
 * how many files the browser actually selected. If the number of files
 * that reach the server for a requirement is lower than that expected
 * count, registration is now blocked with a specific, actionable error
 * naming the exact document and the likely cause — instead of silently
 * completing with some of that requirement's files missing, which is what
 * happened before. When the counts match (the normal case), this adds no
 * behavior change at all.
 *
 * FIX (earlier update) — multi-JPEG selections for a single requirement
 * were sometimes only capturing the first file. Every array pulled out of
 * $_FILES[$fieldName] (name/tmp_name/error/size) is now explicitly
 * re-indexed with array_values() before being walked, so every submitted
 * slot for a requirement is visited in order regardless of how the
 * browser/PHP populated the array, and each file's own name is now
 * included in any validation error message so a problem with one file in
 * a multi-file selection is easy to identify. The actual save step
 * (regSaveCompanyRequirements() below) was the other half of this fix —
 * see its docblock for the full explanation.
 *
 * NEW (this update) — PDF uploads are now restricted to exactly ONE file
 * per requirement; JPEG uploads may still include multiple files, exactly
 * as before. Selecting more than one PDF for a requirement is rejected
 * with a specific error naming the document (see the check just before
 * the main per-file loop below), mirroring the same rule enforced
 * client-side (with an immediate popup) in handleReqUploadChange()
 * further down.
 *
 * Returns ['ok' => bool, 'error' => string, 'files' => [key => rawBytes[]]]
 */
function regValidateRequirementUploads(array $reqDefs, int $maxFileSizeMB, array $allowedMimes): array
{
    $maxFileSize = $maxFileSizeMB * 1024 * 1024;
    $collected   = [];

    foreach ($reqDefs as $key => $label) {
        $fieldName = 'req_' . $key;

        if (!isset($_FILES[$fieldName]) || !isset($_FILES[$fieldName]['name']) || !is_array($_FILES[$fieldName]['name'])) {
            return ['ok' => false, 'error' => "Please upload your \"$label\" document.", 'files' => []];
        }

        // FIX (this update): re-index every parallel array from $_FILES
        // defensively with array_values() so iteration below always walks
        // every submitted slot in order (0, 1, 2, ...) with no gaps —
        // protects against any case where PHP's array indices for a
        // multi-file input aren't perfectly sequential.
        $namesArr = array_values($_FILES[$fieldName]['name']);
        $tmpNames = array_values($_FILES[$fieldName]['tmp_name']);
        $errors   = array_values($_FILES[$fieldName]['error']);
        $sizes    = array_values($_FILES[$fieldName]['size']);

        // Ignore any empty file-input slots (UPLOAD_ERR_NO_FILE) — a
        // field that received the array structure but no actual file
        // selections still counts as "nothing uploaded".
        $submittedIndexes = [];
        foreach ($errors as $idx => $errCode) {
            if ($errCode !== UPLOAD_ERR_NO_FILE) {
                $submittedIndexes[] = $idx;
            }
        }

        if (empty($submittedIndexes)) {
            return ['ok' => false, 'error' => "Please upload your \"$label\" document.", 'files' => []];
        }

        // ════════════════════════════════════════════════════════════
        // NEW (this update) — expected-vs-actual file count check.
        // See the function docblock above for the full explanation of
        // why this exists. $expectedCountRaw is only present at all if
        // JavaScript ran and populated the hidden count field, so a
        // no-JS submission (which never sends this field) is completely
        // unaffected — this check is skipped entirely in that case.
        // ════════════════════════════════════════════════════════════
        $expectedCountRaw = $_POST[$fieldName . '_expected_count'] ?? null;
        if ($expectedCountRaw !== null && is_numeric($expectedCountRaw)) {
            $expectedCount = (int) $expectedCountRaw;
            $actualCount   = count($submittedIndexes);
            if ($expectedCount > 0 && $actualCount < $expectedCount) {
                error_log('[REG REQ VALIDATE] FILE COUNT MISMATCH for "' . $key . '": browser selected '
                    . $expectedCount . ' file(s) but the server only received ' . $actualCount . '.');
                return [
                    'ok' => false,
                    'error' => "You selected $expectedCount file(s) for \"$label\" but only $actualCount reached the server. "
                             . "This usually means a server upload limit (max_file_uploads in php.ini) was exceeded. "
                             . "Please try uploading fewer files at once for this document, or ask the administrator to raise that limit.",
                    'files' => [],
                ];
            }
        }

        // ════════════════════════════════════════════════════════════
        // NEW (this update) — PDF selections are restricted to exactly
        // ONE file per requirement; JPEG selections may still include
        // multiple files, unchanged. mime_content_type() is checked here
        // (before the main per-file loop below) purely to decide whether
        // this "too many PDFs" rule applies — every file is still fully
        // validated (size, readability, etc.) in the main loop below
        // regardless. This is a server-side backstop for the same rule
        // enforced client-side in handleReqUploadChange() further down
        // (which already blocks the selection with a popup before the
        // form is even submitted) — never trust client-side validation
        // alone, since it can be bypassed (JS disabled, direct POST, etc).
        // ════════════════════════════════════════════════════════════
        if (count($submittedIndexes) > 1) {
            $allPdf = true;
            foreach ($submittedIndexes as $idx) {
                $tmpCheck = $tmpNames[$idx];
                $mimeCheck = @mime_content_type($tmpCheck);
                if ($mimeCheck !== 'application/pdf') {
                    $allPdf = false;
                    break;
                }
            }
            if ($allPdf) {
                return [
                    'ok' => false,
                    'error' => "Only one PDF file can be selected for \"$label\". Please choose a single PDF file, or switch to JPG images if you need to upload multiple files for this document.",
                    'files' => [],
                ];
            }
        }

        $filesForKey = [];
        foreach ($submittedIndexes as $idx) {
            $thisFileName = $namesArr[$idx] ?? 'a file';

            if ($errors[$idx] !== UPLOAD_ERR_OK) {
                return ['ok' => false, 'error' => "There was a problem uploading \"$label\" ($thisFileName). Please try again.", 'files' => []];
            }

            $tmp  = $tmpNames[$idx];
            $size = $sizes[$idx];

            if ($size > $maxFileSize) {
                return ['ok' => false, 'error' => "\"$label\" ($thisFileName) exceeds the {$maxFileSizeMB}MB limit.", 'files' => []];
            }

            $mime = @mime_content_type($tmp);
            if (!$mime || !in_array($mime, $allowedMimes, true)) {
                return ['ok' => false, 'error' => "\"$label\" ($thisFileName) must be a JPG or PDF file.", 'files' => []];
            }

            $bytes = @file_get_contents($tmp);
            if ($bytes === false || $bytes === '') {
                return ['ok' => false, 'error' => "Failed to read \"$label\" ($thisFileName). Please try again.", 'files' => []];
            }

            $filesForKey[] = $bytes;
        }

        error_log('[REG REQ VALIDATE] requirement="' . $key . '" files_validated=' . count($filesForKey));
        $collected[$key] = $filesForKey;
    }

    return ['ok' => true, 'error' => '', 'files' => $collected];
}

/**
 * Insert the validated company_information row for a newly created
 * company account, using the exact column shape CompanyForm.php already
 * reads from (plus company_profile, stored here alongside the rest of
 * the company's details).
 */
function regSaveCompanyInformation(
    \mysqli $conn,
    int $userId,
    string $company,
    string $companyAddress,
    string $companyProfile,
    string $telephone,
    string $first,
    string $middle,
    string $last,
    string $position,
    string $companyType
): bool {
    $middleInitial = ($middle !== '') ? $middle : 'N/A';

    $stmt = $conn->prepare("
        INSERT INTO company_information
            (user_id, company, company_address, company_profile, telephone, contact_first_name, contact_middle_initial, contact_last_name, position, company_type)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
    ");
    if (!$stmt) {
        error_log('[REG COMPANY INFO] prepare failed: ' . $conn->error);
        return false;
    }
    $stmt->bind_param(
        "isssssssss",
        $userId, $company, $companyAddress, $companyProfile, $telephone,
        $first, $middleInitial, $last, $position, $companyType
    );
    $ok = $stmt->execute();
    if (!$ok) error_log('[REG COMPANY INFO] INSERT failed: ' . $stmt->error);
    $stmt->close();
    return $ok;
}

/**
 * Insert each validated compliance-requirement upload as a Pending row in
 * company_requirements.
 *
 * FIX (this update) — TWO separate bugs are fixed here:
 *
 * 1) "Multiple JPEG uploads for one requirement only saved one photo":
 *    The outer/inner loop structure itself already walked every file in
 *    $fileList, but as a defensive belt-and-suspenders fix (matching the
 *    array_values() re-indexing fix in regValidateRequirementUploads()
 *    above) $fileList is now explicitly re-indexed with array_values()
 *    before iterating, and every attempted/saved file is counted and
 *    logged, so any future regression here is immediately visible in the
 *    PHP error log instead of silently dropping files.
 *
 * 2) "Uploaded PDF file is not saved as a blob, leaving the entry
 *    missing/empty" (confirmed from live testing — rows were created but
 *    file_name came back empty for some requirements, and one
 *    requirement's row was missing entirely):
 *      - The blob is now bound as a plain "s" (string) bind_param
 *        argument, NOT "b". mysqli's "b" type tells the driver the value
 *        will be supplied via one or more send_long_data() calls —
 *        binding "b" directly (passing the full bytes to bind_param and
 *        never calling send_long_data(), which earlier revisions of this
 *        function did first via an unchecked send_long_data() call, and
 *        then via a direct "b" bind with no send_long_data() at all)
 *        leaves that parameter EMPTY at execute() time. The row still
 *        inserts fine (status/requirement_type are saved), but
 *        file_name ends up blank — exactly the symptom observed. PHP
 *        strings are byte-safe (not null-terminated), so a LONGBLOB
 *        column accepts binary data through a normal "s"-bound parameter
 *        with no chunked send_long_data() choreography needed.
 *      - Each file's insert is now also wrapped in its own try/catch.
 *        Depending on the mysqli driver's error-report mode (PHP 8.1+
 *        defaults to throwing a mysqli_sql_exception on statement errors
 *        instead of returning false), a problem on ONE file could
 *        previously throw an exception that was never caught inside this
 *        loop, unwinding up to the POST handler's outer try/catch and
 *        silently abandoning every requirement key not yet processed —
 *        this is why "Legislative Charter / Legal Authority" never got a
 *        row saved at all. Catching per-file means one bad file can
 *        never prevent any other file, for this requirement or any later
 *        one, from being attempted.
 *      - A defensive verification query (SELECT LENGTH(file_name)) now
 *        runs immediately after each successful insert to confirm the
 *        stored blob length matches what was written, logging a clear
 *        error if it ever doesn't, instead of a silently empty file.
 */
function regSaveCompanyRequirements(\mysqli $conn, int $userId, array $fileBytesByKey): bool
{
    $allOk          = true;
    $totalAttempted = 0;
    $totalSaved     = 0;

    foreach ($fileBytesByKey as $key => $fileList) {
        // Defensive re-index (see docblock above).
        $fileList = array_values((array) $fileList);

        foreach ($fileList as $bytes) {
            $totalAttempted++;

            if (!is_string($bytes) || $bytes === '') {
                error_log('[REG REQS] Skipping empty file bytes for requirement "' . $key . '"');
                $allOk = false;
                continue;
            }

            // FIX (this update) — TWO real bugs confirmed from live
            // testing (rows were created but file_name came back empty
            // for some requirements, and one requirement's row was
            // missing entirely):
            //
            // 1) BLOB BIND TYPE: mysqli's "b" bind_param type tells the
            //    driver the value will arrive via one or more
            //    send_long_data() calls. Binding a "b" parameter directly
            //    (passing the full byte string to bind_param and never
            //    calling send_long_data() at all, as the previous version
            //    of this function did) leaves that parameter EMPTY at
            //    execute() time — the row still inserts fine (status and
            //    requirement_type are saved), but file_name ends up blank.
            //    This is exactly the "row exists but file_name is empty"
            //    symptom observed for training_supervisor_pds and
            //    moa. The fix is to bind the blob as a plain "s"
            //    (string) parameter instead — PHP strings are byte-safe
            //    (not null-terminated), so a LONGBLOB column accepts
            //    binary data through a normal string-bound parameter with
            //    no chunked send_long_data() choreography needed at all.
            //
            // 2) ONE FILE'S FAILURE ABORTING THE WHOLE LOOP: depending on
            //    the mysqli driver's error-report mode (PHP 8.1+ defaults
            //    to throwing a mysqli_sql_exception on statement errors
            //    instead of just returning false), a problem on ONE file's
            //    prepare/bind/execute could throw an exception that was
            //    never caught inside this loop, unwinding all the way up
            //    to the POST handler's outer try/catch and silently
            //    abandoning every requirement key that hadn't been
            //    processed yet — exactly why "Legislative Charter / Legal
            //    Authority" never got a row saved at all. Each file's
            //    insert is now wrapped in its own try/catch so a failure
            //    on one file can never prevent any other file — for this
            //    requirement or any later one — from being attempted.
            try {
                $stmt = $conn->prepare("
                    INSERT INTO company_requirements (user_id, requirement_type, file_name, status)
                    VALUES (?, ?, ?, 'Pending')
                ");
                if (!$stmt) {
                    error_log('[REG REQS] prepare failed for "' . $key . '": ' . $conn->error);
                    $allOk = false;
                    continue;
                }

                // Blob bound as "s" (string), not "b" — see fix note (1) above.
                $stmt->bind_param("iss", $userId, $key, $bytes);

                if ($stmt->execute()) {
                    if ($stmt->affected_rows > 0) {
                        $insertedId = (int) $conn->insert_id;
                        $stmt->close();
                        $totalSaved++;

                        // Defensive verification: confirm the blob that
                        // actually landed in the row is the same length as
                        // what we tried to save, so any future storage
                        // problem is caught and logged immediately instead
                        // of leaving a silently empty/truncated file
                        // behind unnoticed.
                        $expectedLen = strlen($bytes);
                        $verify = $conn->prepare("SELECT LENGTH(file_name) AS len FROM company_requirements WHERE id = ?");
                        if ($verify) {
                            $verify->bind_param("i", $insertedId);
                            $verify->execute();
                            $verifyResult = $verify->get_result();
                            $verifyRow    = $verifyResult ? $verifyResult->fetch_assoc() : null;
                            $verify->close();
                            $actualLen = $verifyRow ? (int) $verifyRow['len'] : 0;
                            if ($actualLen !== $expectedLen) {
                                error_log('[REG REQS] BLOB LENGTH MISMATCH for "' . $key . '" (row id ' . $insertedId . '): expected=' . $expectedLen . ' actual=' . $actualLen);
                                $allOk = false;
                            }
                        }
                    } else {
                        error_log('[REG REQS] INSERT for "' . $key . '" reported 0 affected_rows');
                        $allOk = false;
                        $stmt->close();
                    }
                } else {
                    error_log('[REG REQS] INSERT failed for "' . $key . '": ' . $stmt->error);
                    $allOk = false;
                    $stmt->close();
                }
            } catch (\Throwable $e) {
                error_log('[REG REQS] Exception while saving "' . $key . '": ' . $e->getMessage()
                    . ' in ' . $e->getFile() . ':' . $e->getLine());
                $allOk = false;
                // Deliberately not rethrown — continue on to the next
                // file / requirement key instead of aborting the loop.
            }
        }
    }

    error_log('[REG REQS] Finished saving requirement uploads: attempted=' . $totalAttempted . ' saved=' . $totalSaved);

    return $allOk;
}

/**
 * NEW (this update) — save the auto-generated MOA document (built from
 * the company's own registration details, using the same
 * regBuildMOAStaticHTMLForDompdf() + regGenerateMoaPdfBytes() helpers
 * already defined below) as a Pending row in company_requirements,
 * instead of a moa_requests row (this registration flow does not use
 * that table). Uses the same reliable direct-blob-bind technique as
 * regSaveCompanyRequirements() above, under a dedicated requirement_type
 * key ("moa") that does not collide with any of the
 * private/public compliance-document keys.
 */
function regSaveGeneratedMoaToRequirements(\mysqli $conn, int $userId, string $pdfBytes): bool
{
    if ($pdfBytes === '') return false;

    // FIX (this update): same two bugs fixed here as in
    // regSaveCompanyRequirements() above —
    //   1) the blob is now bound as "s" (string), not "b". Binding "b"
    //      directly with no send_long_data() call (the previous
    //      implementation) leaves the parameter EMPTY at execute() time,
    //      which is exactly why the moa row was being created
    //      successfully but with an empty file_name.
    //   2) the whole operation is wrapped in try/catch so a thrown
    //      mysqli_sql_exception here can never propagate up and abort
    //      anything else in the POST handler.
    try {
        $stmt = $conn->prepare("
            INSERT INTO company_requirements (user_id, requirement_type, file_name, status)
            VALUES (?, 'moa', ?, 'Pending')
        ");
        if (!$stmt) {
            error_log('[REG GENERATED MOA] prepare failed: ' . $conn->error);
            return false;
        }

        $stmt->bind_param("is", $userId, $pdfBytes);
        $ok = $stmt->execute();

        if (!$ok) {
            error_log('[REG GENERATED MOA] INSERT failed: ' . $stmt->error);
            $stmt->close();
            return false;
        }
        if ($stmt->affected_rows === 0) {
            error_log('[REG GENERATED MOA] INSERT reported 0 affected_rows');
            $stmt->close();
            return false;
        }

        $insertedId = (int) $conn->insert_id;
        $stmt->close();

        // Same defensive blob-length verification as
        // regSaveCompanyRequirements() above.
        $expectedLen = strlen($pdfBytes);
        $verify = $conn->prepare("SELECT LENGTH(file_name) AS len FROM company_requirements WHERE id = ?");
        if ($verify) {
            $verify->bind_param("i", $insertedId);
            $verify->execute();
            $verifyResult = $verify->get_result();
            $verifyRow    = $verifyResult ? $verifyResult->fetch_assoc() : null;
            $verify->close();
            $actualLen = $verifyRow ? (int) $verifyRow['len'] : 0;
            if ($actualLen !== $expectedLen) {
                error_log('[REG GENERATED MOA] BLOB LENGTH MISMATCH (row id ' . $insertedId . '): expected=' . $expectedLen . ' actual=' . $actualLen);
                return false;
            }
        }

        error_log('[REG GENERATED MOA] Saved generated MOA PDF  bytes=' . $expectedLen . '  user_id=' . $userId);
        return true;
    } catch (\Throwable $e) {
        error_log('[REG GENERATED MOA] Exception while saving: ' . $e->getMessage()
            . ' in ' . $e->getFile() . ':' . $e->getLine());
        return false;
    }
}

// ════════════════════════════════════════════════════════════════════════
//  HELPER FUNCTIONS (adapted from moa_request.php so this page can render
//  the same MOA document, and — as of this update — actually generate and
//  store one on successful registration). These are local, self-contained
//  copies — company_register.php and moa_request.php are never included
//  in the same PHP request, so there is no risk of a function-
//  redeclaration collision between the two files.
//
//  regBuildMOAStaticHTMLForDompdf() is used both by the preview_new_moa
//  endpoint (fallback path) at the top of this file AND, as of this
//  update, by the STEP 2d block in the POST handler further down to
//  build the document that regGenerateMoaPdfBytes() renders to PDF and
//  regSaveGeneratedMoaToRequirements() (above) saves into
//  company_requirements. regSavePdfBlobToDb() is kept for reference/reuse
//  but is NOT used by this file — it targets a moa_requests.moa_pdf
//  column, a table this registration flow does not create or use.
// ════════════════════════════════════════════════════════════════════════

/**
 * Build a static, Dompdf-safe HTML version of a "Request New MOA"
 * document. This mirrors buildMOAStaticHTMLForDompdf() in moa_request.php
 * exactly (same letterhead, section structure, numbered lists, signing
 * block, witness block, acknowledgment, and repeating footer) so a MOA
 * generated/previewed at registration looks identical to one generated
 * later through the normal moa_request.php flow.
 */
function regBuildMOAStaticHTMLForDompdf(array $s): string
{
    $esc = fn($v) => htmlspecialchars(trim((string)($v ?? '')), ENT_QUOTES | ENT_HTML5, 'UTF-8');
    $dv  = fn($v, $fallback = '—') =>
        (trim((string)$v) !== '' && $v !== '___' && $v !== '202___') ? (string)$v : $fallback;

    $moa_number   = $dv($s['moa_number']             ?? '', '___');
    $moa_year     = $dv($s['moa_year']               ?? '', date('Y'));
    $company      = $dv($s['company_name']            ?? '', '[COMPANY NAME]');
    $company_desc = $dv($s['company_description']     ?? '', '[COMPANY PROFILE / BRIEF DESCRIPTION]');
    $company_addr = $dv($s['company_address']         ?? '', '[COMPANY ADDRESS]');
    $rep_name     = $dv($s['representative_name']     ?? '', '[NAME OF REPRESENTATIVE]');
    $rep_pos      = $dv($s['representative_position'] ?? '', 'Manager/Head/Director');
    $sign_date    = $dv($s['signing_date']            ?? '', '_____________________');
    $sign_place   = $dv($s['signing_place']            ?? '', '_____________________');
    $notary_city  = $dv($s['notary_city']              ?? '', '_____________________________');
    $company_id   = $dv($s['company_id']               ?? '', '________________');
    // UPDATED (MOA_form_builder.php sync): NEUST ID No. + witness name,
    // same optional keys/defaults as buildMOAFormHTML().
    $neust_id     = $dv($s['neust_id']                 ?? '', '228');
    $witness_name = trim((string)($s['witness_name']   ?? ''));

    $eCompany    = $esc($company);
    $eCompDesc   = $esc($company_desc);
    $eCompAddr   = $esc($company_addr);
    $eRepName    = $esc($rep_name);
    $eRepPos     = $esc($rep_pos);
    $eSignDate   = $esc($sign_date);
    $eSignPlace  = $esc($sign_place);
    $eNotaryCity = $esc($notary_city);
    $eCompanyId  = $esc($company_id);
    $eNeustId    = $esc($neust_id);
    $eWitness    = $witness_name !== '' ? $esc($witness_name) : 'RANDY M. BA&Ntilde;EZ, J.D.';
    $eMoaNo      = 'MOA No. ' . $esc($moa_number) . ', s.' . $esc($moa_year);
    $eYear       = $esc($moa_year);

    $FILL = 'font-family:serif;font-weight:700;color:#0d2545;'
          . 'border-bottom:1px solid #0d2545;padding:0 2pt;';
    // UPDATED: blank "______" placeholders already draw their own line, so
    // they get no border-bottom (avoids the double line on page 4).
    $FILL_BLANK = 'font-family:serif;font-weight:400;color:#1a2035;';
    $F = fn($v) => (strpos((string)$v, '__') === 0) ? $FILL_BLANK : $FILL;

    $LH = '
<table width="100%" cellpadding="0" cellspacing="0" border="0"
       style="background:#0d2545;border-bottom:2pt solid #b8860b;">
  <tr>
    <td width="50pt" style="padding:6pt 6pt 6pt 14pt;vertical-align:middle;">
      <img src="logo.webp" alt="NEUST" width="36" height="36"
           style="border-radius:18pt;display:block;
                  border:1.5pt solid rgba(255,255,255,0.25);">
    </td>
    <td style="padding:5pt 14pt 5pt 6pt;vertical-align:middle;">
      <div style="font-size:5.5pt;letter-spacing:0.18em;text-transform:uppercase;
                  color:#aac4f0;margin-bottom:1.5pt;font-family:monospace;">
        Republic of the Philippines
      </div>
      <div style="font-size:10.5pt;font-weight:700;text-transform:uppercase;
                  color:#ffffff;font-family:sans-serif;line-height:1.2;
                  letter-spacing:0.01em;">
        Nueva Ecija University of Science and Technology
      </div>
      <div style="font-size:6.5pt;color:#aac4f0;margin-top:1pt;
                  font-style:italic;font-family:sans-serif;">
        Cabanatuan City, Nueva Ecija
      </div>
    </td>
  </tr>
</table>
<table width="100%" cellpadding="0" cellspacing="0" border="0"
       style="background:#f5f6fa;border-bottom:1pt solid #d0d5e8;">
  <tr>
    <td style="padding:5pt 14pt 4pt;text-align:center;">
      <div style="font-size:5.5pt;letter-spacing:0.14em;text-transform:uppercase;
                  color:#5a6a8a;margin-bottom:1.5pt;font-family:monospace;">
        On-the-Job Training and Career Development Center
      </div>
      <div style="font-size:12pt;font-weight:700;text-transform:uppercase;
                  color:#0d2545;letter-spacing:0.03em;font-family:sans-serif;">
        Memorandum of Agreement
      </div>
      <div style="font-size:7.5pt;font-weight:600;color:#1a4a8a;
                  margin-top:2pt;font-family:monospace;">
        ' . $eMoaNo . '
      </div>
      <div style="font-size:5.5pt;color:#888;margin-top:2pt;font-family:monospace;">
        Form No.: NEUST-OJT-F005 &nbsp;&middot;&nbsp; Effectivity: 09.01.2026
      </div>
    </td>
  </tr>
</table>';

    $ST = fn($t) =>
        '<p style="font-family:sans-serif;font-size:9.5pt;font-weight:700;'
        . 'text-align:center;text-decoration:underline;text-transform:uppercase;'
        . 'letter-spacing:0.04em;margin:10pt 0 5pt;color:#0d2545;">'
        . $t . '</p>';

    $SS = fn($t) =>
        '<p style="font-weight:700;margin:7pt 0 4pt;font-size:9.5pt;font-family:serif;">'
        . $t . '</p>';

    $LI = fn($n, $t) =>
        '<tr>'
        . '<td width="16pt" valign="top"'
        . '    style="font-size:9.5pt;font-family:serif;font-weight:700;'
        .          'color:#0d2545;padding-bottom:3pt;padding-right:4pt;'
        .          'white-space:nowrap;">'
        . $n . '.</td>'
        . '<td valign="top"'
        . '    style="font-size:9.5pt;font-family:serif;line-height:1.65;'
        .          'text-align:justify;padding-bottom:3pt;">'
        . $t . '</td>'
        . '</tr>';

    $BODY_STYLE = 'font-family:serif;font-size:9.5pt;line-height:1.65;'
                . 'color:#1a2035;text-align:justify;'
                . 'padding:10pt 22pt 22pt 22pt;';

    $buildPage = function(string $bodyHTML, string $lh, bool $isLast = false) use ($BODY_STYLE): string {
        $breakStyle = $isLast ? '' : 'page-break-after:always;';
        return '
<div style="' . $breakStyle . 'background:#ffffff;">
  ' . $lh . '
  <div style="' . $BODY_STYLE . '">
    ' . $bodyHTML . '
  </div>
</div>';
    };

    $page1Body = '
<p style="font-weight:700;margin-bottom:8pt;font-size:9.5pt;font-family:serif;">
  KNOWN ALL MEN BY THESE PRESENTS:
</p>
<p style="margin-bottom:6pt;font-size:9.5pt;font-family:serif;">
  This <strong>Memorandum of Agreement</strong> made and entered by and between:
</p>
<p style="margin:6pt 0 6pt 18pt;text-indent:-18pt;padding-left:18pt;
          font-size:9.5pt;font-family:serif;">
  <span style="' . $FILL . '">' . $eCompany . '</span>,
  an entity duly licensed and registered establishment under the laws of the Philippines,
  with principal office address at
  <span style="' . $FILL . '">' . $eCompAddr . '</span>
  herein represented by its ' . $eRepPos . '
  <span style="' . $FILL . '">' . $eRepName . '</span>
  hereinafter referred to as the <strong><em>"TRAINING INSTITUTION"</em></strong>;
</p>
<p style="text-align:center;font-weight:700;margin:5pt 0;font-size:9.5pt;
          font-family:serif;">
  &ndash;AND&ndash;
</p>
<p style="margin:6pt 0 6pt 18pt;text-indent:-18pt;padding-left:18pt;
          font-size:9.5pt;font-family:serif;">
  <strong>NUEVA ECIJA UNIVERSITY OF SCIENCE AND TECHNOLOGY (NEUST)</strong>,
  a chartered state university in accordance with R.A. 8612, with office address at
  General Tinio Street, Cabanatuan City, Nueva Ecija 3100, represented by its
  University President, <strong>DR. RHODORA R. JUGO</strong>,
  hereinafter referred to as the <strong><em>"UNIVERSITY"</em></strong>
</p>
<p style="text-align:center;font-weight:700;font-style:italic;
          margin:8pt 0 4pt;font-size:9.5pt;font-family:serif;">
  &ndash;WITNESSETH: That&ndash;
</p>
<p style="margin-bottom:5pt;font-size:9.5pt;font-family:serif;">
  &nbsp;&nbsp;&nbsp;&nbsp;The UNIVERSITY has requested the TRAINING INSTITUTION to
  accommodate its students in the different field of discipline as TRAINEES under the
  On&ndash;the&ndash;Job Training (OJT) Program as required in the Board-approved
  curriculum they are enrolled in; and
</p>
<p style="margin-bottom:5pt;font-size:9.5pt;font-family:serif;">
  &nbsp;&nbsp;&nbsp;&nbsp;The TRAINING INSTITUTION has agreed to accommodate the TRAINEES
  for their On&ndash;the&ndash;Job Training (OJT), subject to the terms and conditions
  specified hereunder.
</p>
<p style="margin-bottom:8pt;font-size:9.5pt;font-family:serif;">
  &nbsp;&nbsp;&nbsp;&nbsp;NOW THEREFORE, for and in consideration of the foregoing premises
  and the mutual covenants set forth herein, the parties agree as follows:
</p>
' . $ST('Term') . '
<p style="margin-bottom:6pt;font-size:9.5pt;font-family:serif;">
  &nbsp;&nbsp;&nbsp;&nbsp;This Memorandum of Agreement shall take effect upon signing of
  both parties and shall continue to remain in full force and effect unless sooner revised
  or terminated by either party giving notice to the other at least six (6) months prior
  to the intended date of revision or termination. Such notice of termination will not
  interfere with the cooperative program currently underway. Such programs will be allowed
  to continue until their conclusion.
</p>';

    $page2Body = $ST('Duties and Obligations')
    . $SS('A. The UNIVERSITY')
    . '<table width="100%" cellpadding="0" cellspacing="0" border="0"
             style="margin-bottom:6pt;">'
    . $LI('1', 'To formulate local school practicum policies and guidelines on selection, placement, monitoring and assessment of the <strong>TRAINEES</strong>;')
    . $LI('2', 'To pre&ndash;qualify the <strong>TRAINEES</strong> in accordance with the school off campus training policies and requirements as specified in CMO No. 25, Series of 2015 and the requirements from the <strong>TRAINING INSTITUTION</strong>;')
    . $LI('3', 'To set the criteria on the selection of a Faculty Practicum who is academically qualified and will be responsible as Faculty SIPP Coordinator per program for all the aspects of the student internship programs including program implementation, monitoring and evaluation;')
    . $LI('4', 'To monitor, jointly with the <strong>TRAINING INSTITUTION</strong> and evaluate the performance of the TRAINEES based on the prescribed CMO No. 25, Series of 2015;')
    . $LI('6', 'To conduct general orientation for the <strong>TRAINEES</strong> and their parents/guardians;')
    . $LI('7', 'To conduct initial and regular visit of the <strong>TRAINING INSTITUTION</strong> premises to ensure the safety of the <strong>TRAINEES</strong>;')
    . $LI('8', 'To subject the student <strong>TRAINEE</strong> to institutional disciplinary policies for any violations of the guidelines set forth under CMO No. 23 series of 2009 after due investigations conducted in connection thereto; and')
    . $LI('9', 'To issue final grade to the student trainee based on the <strong>TRAINEE\'S</strong> performance evaluation upon completion of requirements on prescribed period and the concomitant Certificate of Appreciation of the completion of training of the student with the <strong>TRAINING INSTITUTION.</strong>')
    . '</table>'
    . $SS('B. The TRAINING INSTITUTION')
    . '<table width="100%" cellpadding="0" cellspacing="0" border="0"
             style="margin-bottom:4pt;">'
    . $LI('1',  'To facilitate the processing of the On-the-Job Training-related documents of the student trainees/interns in coordination with the <strong>UNIVERSITY;</strong>')
    . $LI('2',  'To provide Supervised Applied Learning Experiences for the student trainees in accordance with agreed Training Manual/Plan and schedule of activities;')
    . $LI('3',  'To assign a competent Training Supervisor responsible for the implementation of the relevant phases of the Training Plan;')
    . $LI('4',  'To provide safe and conducive working environment/venue for the Trainees which is free from any hazard and will bolster their confidence and develop their skills during the training;')
    . $LI('5',  'To allow the University through its duly authorized representative to visit the site where the training program will be held and regularly visit and monitor the same upon prior notice to the concerned office of the <strong>TRAINING INSTITUTION</strong> conducting the training;')
    . $LI('6',  'To comply with the specific provisions of the Labor Code of the Philippines and other pertinent laws in accepting Trainees under the OJT Program and afford the Trainees their respective rights under the said laws;')
    . $LI('7',  'To immediately inform the University through its authorized representative of any incident during the training program which would expose the Trainees of any harm or injury or violation of their rights;')
    . $LI('8',  'To immediately act on the complaints of the Trainees concerning the improper demeanor of their Trainor/s or any complaint concerning violation/s of their rights;')
    . $LI('9',  'To comply with the existing rules and regulations involving health protocols implemented by the University, National Government, IATF, Department of Health, CHED, Local Government Unit in the area and provide sufficient health facilities in favor of the TRAINEE. Any violation of such rules and regulations which compromise the safety of the TRAINEE shall be a ground for the termination of the On&ndash;the&ndash;job Training (OJT) Program;')
    . $LI('10', 'To conduct post training review and evaluation of the program and the performance of Trainees together with the <strong>UNIVERSITY</strong>; and')
    . $LI('11', 'To issue a <strong>Certificate of Completion</strong> of the <strong>TRAINEE</strong> one (1) week after the completion of the training.')
    . '</table>';

    $page3Body = $ST('Adjustment/s')
    . '<p style="margin-bottom:6pt;font-size:9.5pt;font-family:serif;">
  &nbsp;&nbsp;&nbsp;&nbsp;Both the <strong>TRAINING INSTITUTION</strong> and the
  <strong>UNIVERSITY</strong> can make the necessary amendments or changes on their
  duties and obligation to suit the prevailing condition and community quarantine
  in the area/s of trainee\'s assignment.
</p>'
    . $ST('Obligation of the Trainee')
    . '<p style="margin-bottom:5pt;font-size:9.5pt;font-family:serif;">
  &nbsp;&nbsp;&nbsp;&nbsp;The <strong>TRAINEE</strong> shall be liable to any damages he
  may cause through his fault or negligence such as breakage of
  <strong>TRAINING INSTITUTION</strong> properties after due notice and hearing in
  accordance with <strong>TRAINING INSTITUTION</strong> rules. The University shall
  ensure that the liable trainee shall fulfill its obligation. Otherwise, the University
  shall cover the unsettled liability of the trainee.
</p>
<p style="margin-bottom:5pt;font-size:9.5pt;font-family:serif;">
  &nbsp;&nbsp;&nbsp;&nbsp;The TRAINEES shall complete the Training Program within the
  Required OJT Hours.
</p>
<p style="margin-bottom:6pt;font-size:9.5pt;font-family:serif;">
  &nbsp;&nbsp;&nbsp;&nbsp;The <strong>TRAINEE</strong> shall strictly comply with the
  existing rules and regulations involving health protocols implemented by the
  <strong>TRAINING INSTITUTION</strong>, the University, National Government, IATF,
  Department of Health, CHED, Local Government Unit in the area. The trainee shall
  immediately report to the Company and the University any instance of violation or
  non-compliance with such rules and regulations. Any violation of such rules and
  regulations on the part of the Trainee shall be a ground for the termination of
  his/her On&ndash;the&ndash;job Training Program.
</p>'
    . $ST('Schedule')
    . '<p style="margin-bottom:5pt;font-size:9.5pt;font-family:serif;">
  &nbsp;&nbsp;&nbsp;&nbsp;The TRAINEE shall be observing the following training schedule:
</p>
<p style="margin-bottom:5pt;font-size:9.5pt;font-family:serif;">
  &nbsp;&nbsp;&nbsp;&nbsp;Mondays to Fridays Time: 8:00&ndash;5:00pm, without prejudice to
  a flexible and suitable schedule to be agreed upon by the
  <strong>TRAINING INSTITUTION</strong> and the <strong>UNIVERSITY</strong> which may
  include Saturdays and Sundays;
</p>
<p style="margin-bottom:5pt;font-size:9.5pt;font-family:serif;">
  &nbsp;&nbsp;&nbsp;&nbsp;The training hours shall not exceed eight (8) hours per day.
</p>
<p style="margin-bottom:6pt;font-size:9.5pt;font-family:serif;">
  &nbsp;&nbsp;&nbsp;&nbsp;The <strong>TRAINING INSTITUTION</strong> shall not require the
  TRAINEE to render overtime work or to report for training on legal (regular) or special
  holidays.
</p>'
    . $ST('Monitoring and Evaluation')
    . '<p style="margin-bottom:6pt;font-size:9.5pt;font-family:serif;">
  &nbsp;&nbsp;&nbsp;&nbsp;During the conduct of the Training Program, the faculty SIPP
  Coordinator and/or Director of the On&ndash;the&ndash;Job Training (OJT) and Career
  Development Centre of the UNIVERSITY shall monitor and evaluate the Trainees and will
  utilize standard procedures, instruments and methodologies such as observations, monthly
  reports, and interviews or conferences with the students.
</p>'
    . $ST('Pre-Termination')
    . '<p style="margin-bottom:8pt;font-size:9.5pt;font-family:serif;">
  &nbsp;&nbsp;&nbsp;&nbsp;Either of the parties upon written notice may pre-terminate the
  foregoing agreement in case of violation of either party of any provision of the
  foregoing Agreement. In case the violation is on the part of the
  <strong>TRAINING INSTITUTION</strong>, corresponding Certification shall be issued in
  favor of the Trainees despite the termination in accordance with the extent of the
  training undergone by them.
</p>';

    $page4Body =
    '<p style="margin-bottom:8pt;font-size:9.5pt;font-family:serif;">
  &nbsp;&nbsp;&nbsp;&nbsp;<strong>IN WITNESS WHEREOF,</strong> the parties have carefully
  read, fully understood and voluntarily agree, to the terms and conditions of this
  agreement, and have caused this agreement to be signed by their duty authorized
  representatives this
  <span style="' . $F($sign_date) . '">' . $eSignDate . '</span>
  hereat
  <span style="' . $F($sign_place) . '">' . $eSignPlace . '</span>.
</p>'
    . '<table width="100%" cellpadding="0" cellspacing="0" border="0"
             style="margin-top:10pt;">
  <tr>
    <td width="50%" valign="top" align="center" style="padding:0 6pt 0 0;">
      <p style="font-size:8.5pt;font-weight:700;text-transform:uppercase;
                letter-spacing:0.04em;color:#0d2545;font-family:sans-serif;
                margin-bottom:20pt;line-height:1.4;text-align:center;">
        For the:<br>Training Institution
      </p>
      <div style="border-bottom:1.5px solid #1a2035;width:90%;
                  margin:0 auto 3pt;"></div>
      <p style="font-weight:700;font-size:9.5pt;color:#0d2545;text-align:center;
                text-decoration:underline;font-family:serif;margin:0 0 2pt;">
        ' . $eRepName . '
      </p>
      <p style="font-size:8.5pt;color:#5a6a8a;font-family:sans-serif;
                text-align:center;margin:0;">
        ' . $eCompany . ' (' . $eRepPos . ')
      </p>
    </td>
    <td width="50%" valign="top" align="center" style="padding:0 0 0 6pt;">
      <p style="font-size:8.5pt;font-weight:700;text-transform:uppercase;
                letter-spacing:0.04em;color:#0d2545;font-family:sans-serif;
                margin-bottom:20pt;line-height:1.4;text-align:center;">
        For the:<br>University
      </p>
      <div style="border-bottom:1.5px solid #1a2035;width:90%;
                  margin:0 auto 3pt;"></div>
      <p style="font-weight:700;font-size:9.5pt;color:#0d2545;text-align:center;
                text-decoration:underline;font-family:serif;margin:0 0 2pt;">
        RHODORA R. JUGO, EdD
      </p>
      <p style="font-size:8.5pt;color:#5a6a8a;font-family:sans-serif;
                text-align:center;margin:0;">
        University President<br>NEUST
      </p>
    </td>
  </tr>
</table>'
    . '<div style="margin-top:10pt;text-align:center;">
  <p style="font-size:8.5pt;font-weight:600;font-family:sans-serif;
            margin-bottom:8pt;">
    Signed in the presence of:
  </p>
  <div style="border-bottom:1.5px solid #1a2035;width:45%;
              margin:14pt auto 3pt;"></div>
  <p style="font-weight:700;font-size:9.5pt;text-decoration:underline;
            color:#0d2545;font-family:serif;margin:0 0 2pt;text-align:center;">
    ' . $eWitness . '
  </p>
  <p style="font-size:8.5pt;color:#5a6a8a;font-family:sans-serif;
            text-align:center;margin:0;line-height:1.5;">
    Director, On-the-Job Training and Career Development Centre<br>
    Nueva Ecija University of Science and Technology
  </p>
</div>'
    . '
<p style="font-family:sans-serif;font-size:10pt;font-weight:700;
          text-align:center;text-decoration:underline;
          margin:16pt 0 8pt;letter-spacing:0.04em;color:#0d2545;">
  ACKNOWLEDGMENT
</p>
<p style="font-size:9pt;margin-bottom:2pt;font-family:serif;">
  REPUBLIC OF THE PHILIPPINES)
</p>
<p style="font-size:9pt;margin-bottom:2pt;font-family:serif;">
  <span style="' . $F($notary_city) . '">' . $eNotaryCity . '</span>
  &nbsp;&nbsp;&nbsp;&nbsp;) S.S.
</p>
<p style="margin:3pt 0 6pt 0;font-family:serif;font-size:9pt;">
  x----------------------------x
</p>
<p style="margin-bottom:5pt;font-size:9.5pt;font-family:serif;">
  <strong>BEFORE ME,</strong> a notary public duly authorized in the city named above,
  personally appeared:
</p>
<table width="100%" cellpadding="2" cellspacing="0" border="0"
       style="margin-bottom:6pt;font-size:9.5pt;font-family:serif;">
  <tr>
    <td width="55%" style="padding:2pt 4pt;">
      <span style="' . $FILL . '">' . $eRepName . '</span>
    </td>
    <td style="padding:2pt 4pt;">
      - ID No. <span style="' . $F($company_id) . '">' . $eCompanyId . '</span>
    </td>
  </tr>
  <tr>
    <td style="padding:2pt 4pt;">
      <strong>RHODORA R. JUGO, EdD</strong>
    </td>
    <td style="padding:2pt 4pt;">
      - NEUST ID No. <span style="' . $FILL . '">' . $eNeustId . '</span>
    </td>
  </tr>
</table>
<p style="margin-bottom:5pt;font-size:9.5pt;font-family:serif;">
  Who are personally known to me, through their competent evidence of identity as
  above-stated, to be the same persons described in the foregoing instrument
  consisting of <span class="ack-page-count">four (4)</span> pages including the page where this acknowledgement is
  written, who acknowledgment before me that their respective signatures on the
  instrument were voluntarily affixed by them for the purpose stated therein, and
  who declared to me that they have executed the instrument as their free and
  voluntary act and deed.
</p>
<p style="margin-bottom:10pt;font-size:9.5pt;font-family:serif;">
  <strong>WITNESS MY HAND AND SEAL</strong> this
  <span style="' . $F($sign_date) . '">' . $eSignDate . '</span>,
  hereat
  <span style="' . $F($sign_place) . '">' . $eSignPlace . '</span>.
</p>
<div style="font-size:9pt;font-family:serif;margin-top:8pt;line-height:1.9;">
  Doc. No._____<br>
  Page No._____<br>
  Book No.______<br>
  Series of ______
</div>';

    // UPDATED (MOA_form_builder.php sync): page 1 carries the
    // "Director, OJT-CDC" initial line — same position/size as the live
    // builder (--initials-lift: 240pt, --initials-right: 55pt, 150pt line),
    // measured from the top of the page footer band.
    $page1Body .= '
<div style="position:absolute;right:55pt;bottom:252pt;width:150pt;text-align:center;">
  <div style="border-bottom:1px solid #1a2035;height:14pt;margin-bottom:2pt;"></div>
  <div style="font-family:sans-serif;font-size:6.5pt;color:#5a6a8a;">Director, OJT-CDC</div>
</div>';
    $page1 = $buildPage($page1Body, $LH, false);
    $page2 = $buildPage($page2Body, $LH, false);
    $page3 = $buildPage($page3Body, $LH, false);
    $page4 = $buildPage($page4Body, $LH, true);

    return '<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>MOA &mdash; ' . $eCompany . '</title>
<style>
  @page { size:A4 portrait; margin:0; }
  * { box-sizing:border-box; margin:0; padding:0; }
  body { font-family:serif; background:#ffffff; color:#1a2035; }
  p { margin:0; padding:0; }
  strong { font-weight:700; }
  em { font-style:italic; }
  tr { page-break-inside:avoid; }
  #pdf-footer {
    position: fixed; bottom: 0; left: 0; right: 0;
    background: #f0f2f8; border-top: 1pt solid #0d2545;
    padding: 2.5pt 14pt; font-family: monospace; font-size: 5.5pt;
    color: #888; letter-spacing: 0.07em;
  }
  #pdf-footer table { width: 100%; border-collapse: collapse; }
  #pdf-footer .pg-cur:before { content: counter(page); }
</style>
</head>
<body>
<div id="pdf-footer">
  <table cellpadding="0" cellspacing="0" border="0">
    <tr>
      <td width="33%">NEUST-OJT-F005</td>
      <td width="34%" align="center">Page <span class="pg-cur"></span> of <span class="pg-tot">4</span></td>
      <td width="33%" align="right">Rev. 02 (09.01.2026)</td>
    </tr>
  </table>
</div>
' . $page1 . '
' . $page2 . '
' . $page3 . '
' . $page4 . '
</body>
</html>';
}

/**
 * Generate PDF bytes via Dompdf from static HTML. Mirrors
 * generateMoaPdfBytes() in moa_request.php exactly. As of this update,
 * called from the STEP 2d block in the POST handler below to build the
 * MOA PDF that gets saved into company_requirements.
 */
function regGenerateMoaPdfBytes(string $staticHtml): ?string
{
    $autoloaders = [
        __DIR__ . '/vendor/autoload.php',
        dirname(__DIR__) . '/vendor/autoload.php',
        __DIR__ . '/dompdf/autoload.inc.php',
        __DIR__ . '/libs/dompdf/autoload.inc.php',
    ];

    try {
        foreach ($autoloaders as $al) {
            if (file_exists($al)) {
                require_once $al;
                if (class_exists('Dompdf\Dompdf')) break;
            }
        }
    } catch (\Throwable $e) {
        error_log('[REG MOA PDF] Failed while loading an autoloader: ' . $e->getMessage());
    }

    if (!class_exists('Dompdf\Dompdf')) {
        error_log('[REG MOA PDF] Dompdf class not found.');
        return null;
    }

    try {
        $options = new \Dompdf\Options();
        $options->set('isRemoteEnabled', true);
        $options->set('isHtml5ParserEnabled', true);
        $options->set('defaultFont', 'serif');
        $options->set('isFontSubsettingEnabled', true);
        $options->set('chroot', __DIR__);

        $dompdf = new \Dompdf\Dompdf($options);
        $dompdf->loadHtml($staticHtml, 'UTF-8');
        $dompdf->setPaper('A4', 'portrait');
        $dompdf->render();

        // UPDATED (MOA_form_builder.php sync): the footer's "Page X of Y"
        // total and the Acknowledgment's "four (4) pages" wording are
        // written for the standard 4-page layout. If the document actually
        // rendered to a different number of pages (e.g. very long company
        // details), rewrite both to the real count and render once more.
        $pageCount = (int) $dompdf->getCanvas()->get_page_count();
        if ($pageCount > 0 && $pageCount !== 4) {
            $words = ['zero','one','two','three','four','five','six','seven','eight','nine','ten',
                      'eleven','twelve','thirteen','fourteen','fifteen','sixteen','seventeen',
                      'eighteen','nineteen','twenty'];
            $countText = ($words[$pageCount] ?? (string)$pageCount) . ' (' . $pageCount . ')';
            $syncedHtml = str_replace(
                ['<span class="pg-tot">4</span>', '<span class="ack-page-count">four (4)</span>'],
                ['<span class="pg-tot">' . $pageCount . '</span>', '<span class="ack-page-count">' . $countText . '</span>'],
                $staticHtml
            );
            $dompdf = new \Dompdf\Dompdf($options);
            $dompdf->loadHtml($syncedHtml, 'UTF-8');
            $dompdf->setPaper('A4', 'portrait');
            $dompdf->render();
        }

        $bytes = $dompdf->output();
        if (is_string($bytes) && strlen($bytes) > 500) {
            return $bytes;
        }
        return null;
    } catch (\Throwable $e) {
        error_log('[REG MOA PDF] Generation failed: ' . $e->getMessage()
            . ' in ' . $e->getFile() . ':' . $e->getLine());
        return null;
    }
}

/**
 * Save PDF bytes to a moa_requests.moa_pdf blob column. Kept for
 * reuse elsewhere; NOT used by this file — this registration flow never
 * creates or writes to a moa_requests row. The generated MOA is instead
 * saved via regSaveGeneratedMoaToRequirements() above.
 */
function regSavePdfBlobToDb(\mysqli $conn, int $recordId, string $pdfBytes): bool
{
    if (strlen($pdfBytes) === 0) return false;
    $escaped = $conn->real_escape_string($pdfBytes);
    $sql     = "UPDATE moa_requests SET moa_pdf = '$escaped' WHERE id = $recordId";
    if ($conn->query($sql)) return true;
    error_log('[REG MOA PDF] savePdfBlobToDb UPDATE FAILED: ' . $conn->error);
    return false;
}

if ($_SERVER["REQUEST_METHOD"] == "POST") {

    $first  = trim($_POST['first_name'] ?? '');
    $middle = trim($_POST['middle_name'] ?? '');
    $last   = trim($_POST['last_name'] ?? '');
    $email  = trim($_POST['email'] ?? '');

    // ── Request type: this registration flow only ever submits a
    // "Request New MOA" style application, so the request type stored on
    // the users row is always the fixed value "New" (not trusted from
    // the posted moa_type field), guaranteeing consistency even if the
    // form were tampered with.
    $requestType      = 'New';
    $company_name    = trim($_POST['company_name']    ?? '');
    $company_address = trim($_POST['company_address'] ?? '');
    $telephone       = trim($_POST['telephone']        ?? '');
    $position        = trim($_POST['position']         ?? '');
    $company_profile = trim($_POST['company_profile']  ?? '');

    // ── Company classification (private / public) ──────────────────────
    $company_type = strtolower(trim($_POST['company_type'] ?? ''));

    if (empty($first) || empty($last) || empty($email)
            || empty($company_name) || empty($company_address)
            || empty($telephone) || empty($position) || empty($company_profile)) {
        $popup_type  = 'error';
        $popup_title = 'Missing Fields';
        $popup_msg   = 'Please fill in all required fields before submitting.';
        // Send the company back to Step 1 if the account fields are the
        // problem, otherwise Step 2 (company / MOA fields).
        $popup_step  = (empty($first) || empty($last) || empty($email)) ? 1 : 2;
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $popup_type  = 'error';
        $popup_title = 'Invalid Email';
        $popup_msg   = 'Please enter a valid email address.';
        $popup_step  = 1;
    } elseif (!in_array($company_type, ['private', 'public'], true)) {
        $popup_type  = 'error';
        $popup_title = 'Company Classification Required';
        $popup_msg   = 'Please select whether your company is Private or Public/Government.';
        $popup_step  = 3;
    } else {

        // ══════════════════════════════════════════════════════════════════
        // STEP 1 – Check for an existing account with this email
        // ══════════════════════════════════════════════════════════════════
        $check = $conn->prepare("SELECT id FROM users WHERE email = ?");
        $check->bind_param("s", $email);
        $check->execute();
        $check->store_result();

        if ($check->num_rows > 0) {
            $popup_type  = 'error';
            $popup_title = 'Email Already Registered';
            $popup_msg   = 'This email address is already associated with an account. If this is not you, please contact the administrator.';
            $popup_step  = 1;
        } else {

            // ══════════════════════════════════════════════════════════
            // STEP 1b: validate the classification-specific
            // compliance-requirement uploads (same rules CompanyForm.php
            // uses: JPG/PDF, 5MB max), BEFORE the account is created,
            // so a bad/missing upload never leaves a half-finished account
            // behind.
            //
            // NOTE (this update): "Authority to Sign MOA" (key
            // "authority_moa" for Private, "authority_moa_public" for
            // Public/Government) is now a REQUIRED compliance document for
            // BOTH classifications, validated and collected the exact same
            // way as every other document in $reqDefsForType below —
            // nothing is unset/skipped here anymore.
            // ══════════════════════════════════════════════════════════
            $reqDefsForType = $all_company_reqs[$company_type] ?? $private_reqs;

            $reqValidation = regValidateRequirementUploads($reqDefsForType, $reqMaxFileSizeMB, $reqAllowedMimes);

            if (!$reqValidation['ok']) {
                $popup_type  = 'error';
                $popup_title = 'Requirement Document Missing';
                $popup_msg   = $reqValidation['error'];
                $popup_step  = 3;
            } else {

                // ══════════════════════════════════════════════════════════════
                // STEP 2 – Create the company account
                // The users row also stores company_type and the fixed
                // request_type ("New") value alongside the account/
                // credential fields.
                // ══════════════════════════════════════════════════════════════
                $password       = rand(100000, 999999);
                $hashedPassword = password_hash($password, PASSWORD_DEFAULT);
                $role           = "company";

                // The users table (see register.php) also has course / deploy_status /
                // company_validation_status columns used by the student flow — they are
                // not applicable here. Some of these columns are NOT NULL with no
                // default (e.g. 'course'), so we insert empty strings rather than NULL
                // to avoid a "Column cannot be null" error.
                $course                    = '';
                $deploy_status             = '';
                $company_validation_status = null;
                // ── NEW (this adjustment): stamps every account created
                // through this self-service registration flow as
                // 'self_registered' — the counterpart to admin_company_list.php's
                // own 'imported' marker (set there when an account is
                // created via that page's manual "Add Company" form or an
                // XLSX import instead). admin_company_list.php's own
                // company list uses this to decide who's visible there and
                // when: an imported company shows up right away regardless
                // of status, while a self-registered company (like every
                // account this flow creates) only appears there once
                // they're fully Verified on company_validation.php.
                $account_source = 'self_registered';
                $conn->query("ALTER TABLE users ADD COLUMN IF NOT EXISTS account_source VARCHAR(20) NULL");

                $stmt = $conn->prepare(
                    "INSERT INTO users
                     (first_name, middle_name, last_name, role, email, password, course, deploy_status, company_validation_status, company_type, request_type, account_source)
                     VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)"
                );
                $stmt->bind_param(
                    "ssssssssssss",
                    $first, $middle, $last, $role,
                    $email, $hashedPassword,
                    $course, $deploy_status, $company_validation_status, $company_type, $requestType, $account_source
                );

                if ($stmt->execute()) {
                    $newUserId = (int) $conn->insert_id;
                    $stmt->close();

                    // ══════════════════════════════════════════════════════
                    // STEP 2b: save company_information (company name,
                    // address, company profile, telephone, contact person,
                    // position, and classification) + the
                    // classification-specific compliance-requirement
                    // uploads gathered/validated in STEP 1b.
                    // ══════════════════════════════════════════════════════
                    $companyReqsSubmitted = false;
                    try {
                        $infoSaved = regSaveCompanyInformation(
                            $conn, $newUserId,
                            $company_name, $company_address, $company_profile, $telephone,
                            $first, $middle, $last, $position, $company_type
                        );
                        $reqsSaved = regSaveCompanyRequirements($conn, $newUserId, $reqValidation['files']);
                        $companyReqsSubmitted = $infoSaved && $reqsSaved;
                    } catch (\Throwable $e) {
                        error_log('[REG COMPANY REQS] Exception while saving company info/requirements: ' . $e->getMessage()
                            . ' in ' . $e->getFile() . ':' . $e->getLine());
                    }

                    // ══════════════════════════════════════════════════════
                    // NEW (this update) — DEFINITIVE POST-SAVE VERIFICATION.
                    // ──────────────────────────────────────────────────────
                    // Re-queries company_requirements directly (the actual
                    // source of truth, not PHP's in-memory idea of what it
                    // just saved) and counts, per requirement_type, exactly
                    // how many rows exist for this brand-new user — then
                    // compares that against how many files were validated
                    // for each requirement in STEP 1b. If a requirement was
                    // given N files but the database only shows M < N rows
                    // for it, that is surfaced explicitly, both in the PHP
                    // error log (for the administrator) AND directly in the
                    // success/warning popup and email (for the company) —
                    // instead of the registration silently reporting success
                    // while a requirement is quietly missing files. This
                    // turns "I selected multiple files but only one was
                    // saved" from an invisible, hard-to-diagnose problem
                    // into something the app itself reports immediately.
                    // ══════════════════════════════════════════════════════
                    $reqSaveMismatches = [];
                    try {
                        $expectedCounts = [];
                        foreach ($reqValidation['files'] as $vKey => $vFileList) {
                            $expectedCounts[$vKey] = count((array) $vFileList);
                        }

                        if (!empty($expectedCounts)) {
                            $stmt_verify = $conn->prepare(
                                "SELECT requirement_type, COUNT(*) AS actual_count
                                 FROM company_requirements
                                 WHERE user_id = ?
                                 GROUP BY requirement_type"
                            );
                            $stmt_verify->bind_param("i", $newUserId);
                            $stmt_verify->execute();
                            $res_verify = $stmt_verify->get_result();

                            $actualCounts = [];
                            while ($vr = $res_verify->fetch_assoc()) {
                                $actualCounts[$vr['requirement_type']] = (int) $vr['actual_count'];
                            }
                            $stmt_verify->close();

                            foreach ($expectedCounts as $vKey => $expectedN) {
                                $actualN = $actualCounts[$vKey] ?? 0;
                                $vLabel  = $reqDefsForType[$vKey] ?? $vKey;
                                error_log('[REG VERIFY] requirement="' . $vKey . '" expected=' . $expectedN . ' actual_in_db=' . $actualN);
                                if ($actualN < $expectedN) {
                                    $reqSaveMismatches[] = "\"$vLabel\": $actualN of $expectedN file(s) saved";
                                }
                            }

                            if (!empty($reqSaveMismatches)) {
                                error_log('[REG VERIFY] MISMATCH DETECTED for user_id=' . $newUserId . ' — '
                                    . implode('; ', $reqSaveMismatches));
                                // A verified mismatch always means the
                                // requirements section as a whole is treated
                                // as NOT fully submitted, regardless of what
                                // regSaveCompanyRequirements() itself
                                // reported — the direct database count is
                                // the ultimate authority here.
                                $companyReqsSubmitted = false;
                            }
                        }
                    } catch (\Throwable $e) {
                        error_log('[REG VERIFY] Exception while verifying saved requirement counts: ' . $e->getMessage()
                            . ' in ' . $e->getFile() . ':' . $e->getLine());
                    }

                    // ══════════════════════════════════════════════════════
                    // NEW — STEP 2d: auto-generate the MOA document from the
                    // submitted company details (the same "Request New MOA"
                    // document the company already saw via "Preview MOA" on
                    // Step 2), rendered to PDF via Dompdf, and saved as a
                    // Pending row in company_requirements (requirement_type
                    // = 'moa') — NOT in a moa_requests table,
                    // which this registration flow does not use. If Dompdf
                    // is unavailable or generation fails, this is skipped
                    // non-fatally (mirroring how moa_request.php itself
                    // treats a failed Dompdf render) and reflected only in
                    // the confirmation email/popup wording below — the
                    // account and its other data are unaffected either way.
                    // ══════════════════════════════════════════════════════
                    $moaGenerated = false;
                    try {
                        $repFullName = preg_replace('/\s+/', ' ', trim("$first $middle $last"));

                        $moaStaticHtml = regBuildMOAStaticHTMLForDompdf([
                            'moa_number'              => '',
                            'moa_year'                => date('Y'),
                            'company_name'            => $company_name,
                            'company_description'     => $company_profile,
                            'company_address'         => $company_address,
                            'representative_name'     => $repFullName,
                            'representative_position' => $position,
                            'signing_date'            => '',
                            'signing_place'           => '',
                            'notary_city'             => '',
                            'company_id'              => '',
                        ]);

                        $moaPdfBytes = regGenerateMoaPdfBytes($moaStaticHtml);
                        if ($moaPdfBytes !== null && strlen($moaPdfBytes) > 0) {
                            $moaGenerated = regSaveGeneratedMoaToRequirements($conn, $newUserId, $moaPdfBytes);
                        } else {
                            error_log('[REG MOA GEN] Dompdf unavailable or returned empty output — generated MOA not saved.');
                        }
                    } catch (\Throwable $e) {
                        error_log('[REG MOA GEN] Exception while generating/saving the MOA document: ' . $e->getMessage()
                            . ' in ' . $e->getFile() . ':' . $e->getLine());
                    }

                    // ══════════════════════════════════════════════════════
                    // STEP 3 – Email the 6-digit password (unchanged)
                    // ══════════════════════════════════════════════════════
                    $mail = new PHPMailer(true);
                    try {
                        $mail->isSMTP();
                        $mail->Host       = 'smtp.gmail.com';
                        $mail->SMTPAuth   = true;
                        $mail->Username   = 'salesjohnlhoyd@gmail.com';
                        $mail->Password   = 'qwufanprpmezotly';
                        $mail->SMTPSecure = 'tls';
                        $mail->Port       = 587;
                        $mail->setFrom('salesjohnlhoyd@gmail.com', 'Atate On the Job Training System');
                        $mail->addAddress($email);
                        $mail->isHTML(true);
                        $mail->Subject = "Your 6 Digit Login Password";
                        $mail->Body    = "
                            Hello <b>" . htmlspecialchars($first) . "</b>,<br><br>
                            Your company account has been created.<br>
                            Your login password is:<br>
                            <h2>$password</h2>
                            Use this password together with your registered email to log in."
                            . ($companyReqsSubmitted
                                ? "<br><br>Your company details and compliance documents (" . ucfirst($company_type) . " classification) have also been submitted and are now awaiting review by the administrator."
                                : "<br><br>Your account was created successfully, but your company details or compliance documents could not be saved. Please submit them again after logging in via the Requirements page."
                                  . (!empty($reqSaveMismatches) ? "<br>Specifically: " . htmlspecialchars(implode('; ', $reqSaveMismatches)) . "." : ""))
                            . ($moaGenerated
                                ? "<br><br>Your Memorandum of Agreement (MOA) has also been auto-generated from your registration details and is now awaiting review by the administrator."
                                : "<br><br>Your account was created successfully, but your MOA document could not be generated automatically. You can request one after logging in.")
                            . "
                        ";
                        $mail->send();

                        $popup_type     = 'success';
                        $popup_title    = 'Registration Successful!';
                        $popup_msg      = ($companyReqsSubmitted && $moaGenerated)
                            ? 'Your company account has been created, your company details and compliance documents have been submitted, and your MOA has been generated for review. Check your email for your 6-digit password.'
                            : 'Your company account has been created. Check your email for your 6-digit password. Some of your submissions could not be saved — please resubmit them after logging in.'
                              . (!empty($reqSaveMismatches) ? ' (' . implode('; ', $reqSaveMismatches) . ')' : '');
                        $popup_redirect = 'company_login.php';

                    } catch (Exception $e) {
                        $popup_type     = 'success';
                        $popup_title    = 'Registration Successful!';
                        $popup_msg      = 'Your company account has been created. However, the confirmation email could not be sent (SMTP error). Please contact the administrator for your password.';
                        $popup_redirect = 'company_login.php';
                    }

                } else {
                    $popup_type  = 'error';
                    $popup_title = 'Registration Failed';
                    $popup_msg   = 'An error occurred while creating your account. Please try again.';
                    $popup_step  = 1;
                    $stmt->close();
                }
            }
        }

        $check->close();
    }

    $conn->close();
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Company Registration | NEUST OJT Portal</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
    /* ═══════════════════════════════════════════════════════════════════
       DESIGN SYSTEM — matched to CompanyForm.php so the registration
       experience feels like one continuous product with the dashboard
       the company lands on right after signing up. Same palette, same
       card/typography conventions, same status/badge language.
       ═══════════════════════════════════════════════════════════════════ */
    :root {
        --neust-maroon: #07145fe5;
        --neust-gold: #FFD700;
        --bg: #fcfaf7;
        --text: #2d1b1b;
        --white: #ffffff;

        /* ── NEW: flat/navy design tokens, mirrored from the "Add Company"
           form in admin_company_list.php. Used ONLY by the registration
           form elements below (stepper, form card, fields, buttons,
           notices, classification cards, requirement uploads) per the
           requested adjustment — the top navbar keeps its existing
           maroon/gold branding untouched. ── */
        --grid-bg: #EEF1F6;
        --grid-navy: #1B2A4A;
        --grid-border: #C3CADA;
        --grid-red: #A02A2A;
        --grid-amber: #A0850A;
        --grid-muted: #5A6272;
    }

    * { box-sizing: border-box; }

    html, body {
        margin: 0;
        padding: 0;
        min-height: 100%;
    }

    body {
        font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
        background: var(--bg);
        color: var(--text);
        min-height: 100vh;
        display: flex;
        flex-direction: column;
    }

    /* ── TOP NAVBAR (same visual language as CompanyForm.php's .navbar) ── */
    .navbar {
        background: var(--neust-maroon);
        padding: 14px 30px;
        display: flex;
        align-items: center;
        color: white;
        flex-shrink: 0;
        box-shadow: 0 2px 8px rgba(0,0,0,0.15);
    }
    .logo-section { display: flex; align-items: center; gap: 12px; }
    .navbar img.university-logo { height: 44px; border-radius: 50%; }
    .navbar .brand-title { font-weight: bold; font-size: 16px; line-height: 1.3; }
    .navbar .brand-subtitle { font-size: 11px; color: var(--neust-gold); }

    /* ── PAGE CONTAINER ── */
    .reg-page-container {
        flex: 1;
        padding: 30px 20px 50px;
        display: flex;
        justify-content: center;
    }

    .reg-wrap { width: 100%; max-width: 720px; }

    /* ── STEP PROGRESS BAR — reskinned flat/navy/square-cornered to
       mirror the "Add Company" form's chrome in admin_company_list.php ── */
    .reg-stepper {
        display: flex;
        align-items: center;
        gap: 0;
        background: var(--white);
        border: 1px solid var(--grid-border);
        border-radius: 0;
        padding: 18px 20px;
        margin-bottom: 20px;
        box-shadow: none;
    }
    .reg-step-node {
        display: flex;
        flex-direction: column;
        align-items: center;
        gap: 6px;
        flex: 1;
        position: relative;
        min-width: 70px;
    }
    .reg-step-node:not(:last-child)::after {
        content: '';
        position: absolute;
        right: -50%;
        top: 18px;
        width: 100%;
        height: 2px;
        background: var(--grid-border);
        z-index: 0;
    }
    .reg-step-node.done:not(:last-child)::after,
    .reg-step-node.active:not(:last-child)::after { background: var(--grid-navy); }
    .reg-step-dot {
        width: 38px; height: 38px;
        border-radius: 50%;
        border: 2px solid var(--grid-border);
        background: white;
        display: flex; align-items: center; justify-content: center;
        font-size: 14px; color: var(--grid-muted); font-weight: 700;
        z-index: 1; position: relative; transition: all 0.2s;
    }
    .reg-step-node.done .reg-step-dot {
        border-color: #22c55e; background: #22c55e; color: white;
    }
    .reg-step-node.active .reg-step-dot {
        border-color: var(--grid-navy); background: var(--grid-navy); color: #fff;
        box-shadow: 0 0 0 4px rgba(27,42,74,0.15);
    }
    .reg-step-label {
        font-size: 11px; font-weight: 700; text-transform: uppercase;
        letter-spacing: 0.3px; color: var(--grid-muted); text-align: center; line-height: 1.3;
    }
    .reg-step-node.done .reg-step-label,
    .reg-step-node.active .reg-step-label { color: var(--grid-navy); }

    /* ── FORM CARD — flat/bordered, square corners, mirrors the
       "Add Company" modal box in admin_company_list.php ── */
    .form-card {
        background: var(--white);
        padding: 32px;
        border-radius: 0;
        border: 1px solid var(--grid-border);
        box-shadow: none;
    }
    .form-card h2 {
        color: var(--grid-navy);
        margin-top: 0;
        font-size: 16px;
        text-transform: uppercase;
        letter-spacing: 0.4px;
        border-bottom: 1px solid var(--grid-border);
        padding-bottom: 16px;
        margin-bottom: 8px;
    }
    .form-card .reg-step-subtitle {
        color: var(--grid-muted);
        font-size: 13px;
        margin: 0 0 22px;
    }

    /* ── FORM FIELDS — compact multi-column grid, mirrors the
       .add-company-grid layout (span-2 / span-3 helpers) used by the
       "Add Company" form in admin_company_list.php ── */
    .reg-grid { display: grid; grid-template-columns: repeat(3, 1fr); column-gap: 16px; row-gap: 0; align-items: start; }
    .reg-grid .span-2 { grid-column: span 2; }
    .reg-grid .span-3 { grid-column: 1 / -1; }
    @media (max-width: 640px) { .reg-grid { grid-template-columns: 1fr 1fr; } }
    @media (max-width: 480px) {
        .reg-grid { grid-template-columns: 1fr; }
        .reg-grid .span-2, .reg-grid .span-3 { grid-column: auto; }
    }

    .input-group { margin-bottom: 18px; text-align: left; }
    .input-group label {
        display: block;
        font-size: 13px;
        font-weight: 600;
        color: #1e293b;
        margin-bottom: 8px;
    }
    .input-group label .required { color: var(--grid-red); }

    input[type="text"],
    input[type="email"],
    input[type="tel"],
    select,
    textarea {
        width: 100%;
        padding: 10px 12px;
        border: 1px solid var(--grid-border);
        border-radius: 0;
        font-size: 14px;
        font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
        box-sizing: border-box;
        background: #ffffff;
        color: var(--text);
        outline: none;
        transition: border-color 0.2s, background 0.2s;
    }
    input[type="text"]:focus,
    input[type="email"]:focus,
    input[type="tel"]:focus,
    select:focus,
    textarea:focus {
        border-color: var(--grid-navy);
        background: #ffffff;
    }
    input::placeholder, textarea::placeholder { color: #a0aec0; }
    textarea { resize: vertical; min-height: 90px; }

    .btn-submit {
        background: var(--grid-navy);
        color: #ffffff;
        border: none;
        border-radius: 0;
        padding: 14px;
        width: 100%;
        cursor: pointer;
        font-weight: 600;
        font-size: 13px;
        text-transform: uppercase;
        letter-spacing: 0.3px;
        transition: opacity 0.2s;
    }
    .btn-submit:hover { opacity: 0.9; }

    .btn-secondary {
        background: #ffffff;
        color: var(--grid-navy);
        border: 1px solid var(--grid-border);
        border-radius: 0;
        padding: 14px;
        cursor: pointer;
        font-weight: 600;
        font-size: 13px;
        text-transform: uppercase;
        letter-spacing: 0.3px;
        transition: opacity 0.2s, background 0.2s;
    }
    .btn-secondary:hover { background: var(--grid-bg); }

    .reg-btn-row { display: flex; gap: 12px; margin-top: 24px; }
    .reg-btn-row .btn-secondary,
    .reg-btn-row .btn-submit { flex: 1; margin-top: 0; }

    .footer-text { margin-top: 20px; font-size: 13px; color: var(--grid-muted); text-align: center; }
    .footer-text a { color: var(--grid-navy); text-decoration: none; font-weight: 700; }

    /* ── STEP SECTIONS: only the active one is shown ── */
    .reg-step-section { display: none; }
    .reg-step-section.active-step { display: block; animation: regFadeIn 0.25s ease; }
    @keyframes regFadeIn { from { opacity:0; transform:translateY(6px);} to { opacity:1; transform:translateY(0);} }

    /* ── MOA / Company-type selector cards (recolored to the maroon/gold
         palette so they match CompanyForm's req-file-drop hover states,
         replacing the old blue #38bdf8 accent). Still used by the Step 3
         Private/Public classification cards. ── */
    .moa-type-row { display: flex; gap: 12px; margin-bottom: 20px; }
    @media (max-width: 480px) { .moa-type-row { flex-direction: column; } }
    .moa-type-card {
        flex: 1; border: 2px solid var(--grid-border); border-radius: 0;
        padding: 18px 10px; text-align: center; cursor: pointer;
        transition: all 0.2s; background: #ffffff; display: block;
    }
    .moa-type-card:hover { border-color: var(--grid-navy); background: var(--grid-bg); }
    .moa-type-card.selected { border-color: var(--grid-navy); background: var(--grid-bg); }
    .moa-type-card .moa-icon { font-size: 26px; display: block; margin-bottom: 6px; }
    .moa-type-card .moa-label { font-size: 12.5px; font-weight: 700; color: var(--text); }
    .moa-type-card .moa-sublabel { font-size: 10.5px; color: #718096; margin-top: 3px; display: block; }

    /* ── informational notice shown at the top of Step 2 now that
       "Already Have MOA" has been removed and this flow only ever
       requests a brand-new MOA. Tells the company that the fields below
       will be used to generate their MOA document, and reminds them to
       double-check everything before continuing. ── */
    .moa-generate-notice {
        display: flex;
        align-items: flex-start;
        gap: 12px;
        background: var(--grid-bg);
        border: 1px solid var(--grid-border);
        border-left: 4px solid var(--grid-navy);
        border-radius: 0;
        padding: 14px 16px;
        margin-bottom: 22px;
    }
    .moa-generate-notice .moa-generate-notice-icon {
        font-size: 18px;
        color: var(--grid-navy);
        flex-shrink: 0;
        margin-top: 2px;
    }
    .moa-generate-notice .moa-generate-notice-title {
        font-size: 13px;
        font-weight: 700;
        color: var(--grid-navy);
        margin: 0 0 4px;
    }
    .moa-generate-notice .moa-generate-notice-text {
        font-size: 12.5px;
        color: #4a5568;
        line-height: 1.55;
        margin: 0;
    }
    .moa-generate-notice .moa-generate-notice-reminder {
        display: inline-flex;
        align-items: center;
        gap: 6px;
        margin-top: 8px;
        font-size: 11.5px;
        font-weight: 700;
        color: #b45309;
        background: #fff7ed;
        border: 1px solid #fed7aa;
        border-radius: 6px;
        padding: 5px 9px;
    }

    /* ── client-side preview modal for the "Request New MOA"
       document (Step 2). Also reused as the shared modal chrome for any
       future document preview in this file. ── */
    .moa-doc-modal {
        display: none;
        position: fixed;
        inset: 0;
        box-sizing: border-box;
        background: #0f172a;
        z-index: 10003;
        flex-direction: column;
        overflow: hidden;
    }
    .moa-doc-modal-bar {
        width: 100%;
        box-sizing: border-box;
        background: #1e293b;
        padding: 14px 20px;
        display: flex;
        align-items: center;
        justify-content: space-between;
        gap: 16px;
        flex-shrink: 0;
        box-shadow: 0 2px 12px rgba(0,0,0,0.4);
    }
    .moa-doc-modal-title {
        color: #ffffff;
        font-size: 14px;
        font-weight: 700;
        display: flex;
        align-items: center;
        gap: 10px;
        overflow: hidden;
        white-space: nowrap;
        min-width: 0;
    }
    .moa-doc-modal-icon { color: var(--neust-gold); font-size: 16px; flex-shrink: 0; }
    .moa-doc-modal-name { color: var(--neust-gold); overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
    .moa-doc-modal-close {
        background: rgba(255,255,255,0.15);
        border: 1px solid rgba(255,255,255,0.25);
        color: #ffffff;
        font-size: 20px;
        font-weight: 400;
        width: 34px;
        height: 34px;
        border-radius: 50%;
        cursor: pointer;
        line-height: 1;
        flex-shrink: 0;
        display: flex;
        align-items: center;
        justify-content: center;
        transition: background 0.15s;
    }
    .moa-doc-modal-close:hover { background: rgba(255,255,255,0.28); }
    .moa-doc-modal-viewer {
        flex: 1;
        min-height: 0;
        background: #ffffff;
        display: flex;
        align-items: stretch;
        justify-content: stretch;
        overflow: hidden;
    }
    .moa-doc-modal-viewer iframe {
        width: 100%;
        height: 100%;
        border: none;
        display: block;
        background: #ffffff;
    }

    /* ── Company Classification + per-requirement upload styling ── */
    .req-upload-group { margin-bottom: 14px; }
    .req-file-drop {
        border: 2px dashed var(--grid-border); border-radius: 0;
        padding: 12px 10px; text-align: center; cursor: pointer;
        transition: 0.2s; background: var(--grid-bg);
        display: flex; align-items: center; gap: 8px; justify-content: center;
    }
    .req-file-drop:hover { border-color: var(--grid-navy); background: #e7ebf3; }
    .req-file-drop.has-file { border-color: #22c55e; background: #ecfdf5; }
    .req-file-icon { font-size: 16px; flex-shrink: 0; color: var(--grid-navy); }
    .req-file-text {
        font-size: 11.5px; color: #334155; font-weight: 600;
        overflow: hidden; text-overflow: ellipsis; white-space: nowrap;
    }
    #privateReqsSection, #publicReqsSection { display: none; }
    .req-note { font-size: 11px; color: #94a3b8; margin: -8px 0 14px; }

    /* ── compact two-per-row grid layout for the Step 3 requirement
       upload groups, so the "Classification & Docs" step reads as a
       short, scannable grid instead of one long vertical stack of
       full-width upload fields. Falls back to a single column on narrow
       screens so nothing gets cramped on mobile. ── */
    .req-grid {
        display: grid;
        grid-template-columns: 1fr 1fr;
        column-gap: 18px;
        row-gap: 4px;
        align-items: start;
    }
    @media (max-width: 560px) {
        .req-grid { grid-template-columns: 1fr; }
    }
    .req-grid .req-upload-group {
        margin-bottom: 16px;
        min-width: 0; /* allow filenames/labels to ellipsis instead of overflowing their column */
    }
    .req-grid .req-upload-group label {
        font-size: 11px;
        white-space: normal;
        line-height: 1.3;
    }

    /* ── multi-file preview gallery + "Re-select" control shown
       once one or more files have been chosen for a requirement upload.
       Mirrors the thumbnail/PDF-badge language already used for the
       preview zones elsewhere in this file, restyled to the local
       maroon/gold palette. ── */
    .req-file-preview-gallery {
        display: flex;
        flex-wrap: wrap;
        gap: 10px;
        margin-top: 10px;
        justify-content: center;
    }
    .req-preview-item {
        width: 88px;
        text-align: center;
        cursor: pointer;
    }
    .req-preview-item img {
        width: 88px; height: 88px; object-fit: cover; border-radius: 8px;
        border: 1.5px solid #cbd5e1; display: block; margin: 0 auto 4px;
        transition: border-color 0.15s, transform 0.15s;
    }
    .req-preview-item img:hover { border-color: var(--grid-navy); transform: translateY(-1px); }
    .req-preview-pdf {
        width: 88px; height: 88px; border-radius: 8px;
        background: #fef2f2; border: 1.5px solid #fca5a5;
        display: flex; flex-direction: column; align-items: center; justify-content: center;
        gap: 3px; margin: 0 auto 4px; transition: border-color 0.15s, transform 0.15s;
    }
    .req-preview-pdf:hover { border-color: var(--grid-navy); transform: translateY(-1px); }
    .req-preview-pdf i { font-size: 24px; color: #dc2626; }
    .req-preview-name {
        font-size: 9.5px; color: #64748b; white-space: nowrap; overflow: hidden;
        text-overflow: ellipsis; display: block; line-height: 1.3;
    }
    .req-preview-hint {
        font-size: 9px; color: #b45309; font-weight: 700; display: block;
        margin-top: 1px;
    }
    .req-reselect-btn {
        display: none;
        align-items: center; gap: 6px;
        background: #f1f5f9; color: var(--grid-navy); border: 1px solid var(--grid-border);
        border-radius: 8px; padding: 8px 14px; font-size: 11.5px; font-weight: 700;
        cursor: pointer; margin: 10px auto 0; width: fit-content;
        transition: background 0.15s, border-color 0.15s;
        font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
    }
    .req-reselect-btn:hover { background: #e2e8f0; border-color: var(--grid-navy); }

    /* ── layered "photo stack" card shown in place of a full
       thumbnail grid whenever a requirement has more than one file
       selected. Shows up to 3 slightly fanned-out layers with a count
       badge; clicking it opens the in-page preview modal, which lets the
       user page through every selected file with the prev/next arrows
       defined further below. Single-file selections keep the original
       plain thumbnail/PDF-badge look (.req-preview-item), unchanged. ── */
    .req-file-stack-wrap { text-align: center; }
    .req-file-stack {
        position: relative;
        width: 100px;
        height: 100px;
        margin: 4px auto 6px;
        cursor: pointer;
    }
    .req-file-stack .req-stack-layer {
        position: absolute;
        top: 6px; left: 6px;
        width: 88px; height: 88px;
        border-radius: 8px;
        border: 1.5px solid #cbd5e1;
        background: #ffffff;
        box-shadow: 0 2px 6px rgba(0,0,0,0.12);
        background-size: cover;
        background-position: center;
        transition: transform 0.15s;
    }
    .req-file-stack .req-stack-layer.layer-1 { transform: rotate(0deg) translate(0, 0); z-index: 3; }
    .req-file-stack .req-stack-layer.layer-2 { transform: rotate(7deg) translate(7px, 3px); z-index: 2; }
    .req-file-stack .req-stack-layer.layer-3 { transform: rotate(-9deg) translate(-7px, 4px); z-index: 1; }
    .req-file-stack:hover .req-stack-layer.layer-1 { transform: rotate(0deg) translate(0, -3px); }
    .req-file-stack:hover .req-stack-layer.layer-2 { transform: rotate(9deg) translate(9px, 0px); }
    .req-file-stack:hover .req-stack-layer.layer-3 { transform: rotate(-11deg) translate(-9px, 1px); }
    .req-stack-layer.req-stack-layer-pdf {
        display: flex; align-items: center; justify-content: center;
        background-color: #fef2f2; border-color: #fca5a5;
    }
    .req-stack-layer.req-stack-layer-pdf i { font-size: 26px; color: #dc2626; }
    .req-file-stack .req-stack-count-badge {
        position: absolute;
        bottom: -4px; right: -4px;
        background: var(--grid-navy);
        color: #ffffff;
        font-size: 10.5px; font-weight: 700;
        border-radius: 999px;
        min-width: 20px; height: 20px;
        display: flex; align-items: center; justify-content: center;
        padding: 0 5px;
        border: 2px solid #ffffff;
        z-index: 4;
    }
    .req-file-stack-label {
        font-size: 10.5px; color: #64748b; font-weight: 600;
        max-width: 150px; margin: 0 auto; white-space: nowrap;
        overflow: hidden; text-overflow: ellipsis;
    }
    .req-file-stack-hint {
        font-size: 9px; color: #b45309; font-weight: 700; display: block; margin-top: 1px;
    }

    /* ── prev/next navigation arrows for browsing multiple files
       inside the requirement-file preview modal. ── */
    .req-preview-nav-btn {
        position: absolute;
        top: 50%;
        transform: translateY(-50%);
        background: rgba(15,23,42,0.55);
        color: #ffffff;
        border: none;
        width: 42px; height: 42px;
        border-radius: 50%;
        font-size: 16px;
        cursor: pointer;
        display: flex; align-items: center; justify-content: center;
        transition: background 0.15s;
        z-index: 5;
    }
    .req-preview-nav-btn:hover { background: rgba(15,23,42,0.82); }
    .req-preview-nav-prev { left: 18px; }
    .req-preview-nav-next { right: 18px; }

    /* ── utility class that hides the Step 3 "Register" button
       until the company has picked a classification and attached every
       required document for it. Toggled purely client-side by
       regUpdateRegisterButtonState() (see the script block near the
       bottom of this file); the final safety-net validation on actual
       form submit is unchanged, so nothing about server-side handling
       is affected. ── */
    .reg-hidden { display: none !important; }

    /* ── inline preview viewer for chosen requirement files
       (images and PDFs). Reuses the .moa-doc-modal chrome so it looks
       and behaves consistently with the existing "Preview MOA" modal,
       but opens right here on the page instead of a new browser tab. ── */
    .moa-doc-modal-viewer img.req-preview-modal-img {
        max-width: 100%;
        max-height: 100%;
        margin: auto;
        display: block;
        object-fit: contain;
        background: #ffffff;
    }

    /* ── moa-section-heading reused for sub-section labels inside steps ── */
    .moa-section-heading {
        font-size: 13px; font-weight: 700; color: var(--grid-navy); text-transform: uppercase;
        letter-spacing: 0.5px; margin: 0 0 4px; display: flex; align-items: center; gap: 8px;
    }
    .moa-section-subheading { font-size: 12px; color: #718096; margin: 0 0 16px; }

    /* ── POPUP OVERLAY (unchanged structurally, recolored to the maroon
         accent so "success" matches the rest of the reskinned form) ── */
    #regPopupOverlay {
        display: none;
        position: fixed;
        inset: 0;
        background: rgba(0,0,0,0.55);
        z-index: 9999;
        justify-content: center;
        align-items: center;
        backdrop-filter: blur(4px);
        animation: rpFadeIn 0.25s ease;
    }
    @keyframes rpFadeIn { from{opacity:0;} to{opacity:1;} }

    #regPopupBox {
        background: white;
        border-radius: 20px;
        padding: 40px 36px;
        width: 340px;
        max-width: 92%;
        text-align: center;
        box-shadow: 0 20px 60px rgba(0,0,0,0.25);
        animation: rpPopIn 0.3s cubic-bezier(0.34,1.56,0.64,1);
    }
    @keyframes rpPopIn { from{transform:scale(0.85);opacity:0;} to{transform:scale(1);opacity:1;} }

    .rp-icon  { font-size: 52px; display: block; margin-bottom: 14px; }
    .rp-title { font-size: 18px; font-weight: 700; margin: 0 0 8px; }
    .rp-title.error   { color: #dc2626; }
    .rp-title.success { color: var(--grid-navy); }
    .rp-title.warning { color: #d97706; }
    .rp-msg   { font-size: 13px; color: #64748b; margin: 0 0 24px; line-height: 1.6; }

    .rp-btn {
        padding: 13px 36px;
        color: white;
        border: none;
        border-radius: 12px;
        font-weight: 700;
        font-size: 14px;
        cursor: pointer;
        transition: opacity 0.2s;
        font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
    }
    .rp-btn.error   { background: #dc2626; }
    .rp-btn.success { background: var(--grid-navy); }
    .rp-btn.warning { background: #d97706; }
    .rp-btn:hover   { opacity: 0.85; }

    /* ── lightweight client-side notify popup for file-upload validation ── */
    #moaNotifyOverlay {
        display: none;
        position: fixed;
        inset: 0;
        background: rgba(0,0,0,0.6);
        z-index: 10060;
        justify-content: center;
        align-items: center;
        backdrop-filter: blur(4px);
        animation: rpFadeIn 0.25s ease;
    }
    .moa-notify-box {
        background: #ffffff;
        width: 340px;
        max-width: 90%;
        border-radius: 16px;
        overflow: hidden;
        box-shadow: 0 20px 60px rgba(0,0,0,0.4);
        text-align: center;
    }
    .moa-notify-topbar {
        background: var(--grid-navy);
        border-bottom: 2px solid var(--grid-border);
        padding: 14px;
        display: flex; align-items: center; justify-content: center;
    }
    .moa-notify-icon { font-size: 20px; color: #ffffff; line-height: 1; }
    .moa-notify-body { padding: 26px 28px 28px; }
    .moa-notify-title { font-size: 16px; font-weight: 700; color: var(--grid-navy); margin: 0 0 8px; font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif; }
    .moa-notify-msg   { font-size: 13px; color: #5a6a8a; line-height: 1.6; margin: 0 0 22px; text-align: left; white-space: pre-line; }
    .moa-notify-btn {
        width: 100%; padding: 13px; background: var(--grid-navy); color: #ffffff;
        border: none; border-radius: 0; font-weight: 700; font-size: 14px;
        cursor: pointer; transition: 0.2s; font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
    }
    .moa-notify-btn:hover { opacity: 0.9; }

    /* ══════════════════════════════════════════════════════════
       NEW: Global loading overlay — same "loading page" used in
       admin_company_list.php (#globalLoadingOverlay). Shown by
       default so it covers the very first paint while page assets
       are still loading, then faded out automatically once the
       window finishes loading. Purely additive — does not alter any
       existing markup, handler, or business logic on this page.
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
    </style>
</head>
<body>

<!-- ══════════════════════════════════════════════════════════
     NEW: Global loading/processing popup, matching the one in
     admin_company_list.php. Visible by default (see CSS) so it
     covers the page while assets are still loading, then hidden by
     JS once the window finishes loading. Also reused (shown/hidden)
     around the form's own submit/preview actions further down so the
     company always sees a clear "processing" indicator that
     disappears automatically the moment the action completes.
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

<!-- ── POPUP NOTIFICATION (unchanged) ── -->
<div id="regPopupOverlay">
    <div id="regPopupBox">
        <span class="rp-icon" id="rpIcon"></span>
        <p class="rp-title" id="rpTitle"></p>
        <p class="rp-msg"   id="rpMsg"></p>
        <button class="rp-btn" id="rpBtn" onclick="rpClose()">OK</button>
    </div>
</div>

<!-- ── file-validation notify popup ── -->
<div id="moaNotifyOverlay">
    <div class="moa-notify-box">
        <div class="moa-notify-topbar"><span class="moa-notify-icon">&#9888;&#65039;</span></div>
        <div class="moa-notify-body">
            <p class="moa-notify-title" id="moaNotifyTitle">Notice</p>
            <p class="moa-notify-msg" id="moaNotifyMsg"></p>
            <button type="button" class="moa-notify-btn" onclick="document.getElementById('moaNotifyOverlay').style.display='none';">OK</button>
        </div>
    </div>
</div>

<!-- ── preview modal for the "Request New MOA" document, built live
     (server-side, via buildMOAFormHTML()/MOA_form_builder.php — see the
     preview_new_moa endpoint near the top of this file) from whatever the
     company has typed into Step 1/Step 2 so far. Uses the .moa-doc-modal /
     .moa-doc-modal-bar / .moa-doc-modal-viewer visual language defined
     above. The target iframe is what a hidden form (built in
     openNewMoaPreview() further down) POSTs into, the classic "submit a
     form into a named iframe" technique, so no fetch/AJAX/CORS plumbing
     is needed. ── -->
<div id="moaNewMoaPreviewModal" class="moa-doc-modal">
    <div class="moa-doc-modal-bar">
        <div class="moa-doc-modal-title">
            <i class="fas fa-file-contract moa-doc-modal-icon"></i>
            <span>MOA Preview</span>
        </div>
        <button type="button" onclick="closeNewMoaPreview()" class="moa-doc-modal-close" title="Close">&times;</button>
    </div>
    <div class="moa-doc-modal-viewer">
        <iframe name="moaNewMoaPreviewTargetFrame" id="moaNewMoaPreviewTargetFrame" title="MOA Preview"></iframe>
    </div>
</div>

<!-- ── inline preview modal for a chosen requirement upload (JPG or
     PDF) in Step 3. Replaces the previous "open in a new tab" behavior:
     clicking a thumbnail/PDF badge or a layered file-stack card in a
     requirement's preview gallery now opens the file right here on the
     page, using the same .moa-doc-modal / .moa-doc-modal-bar /
     .moa-doc-modal-viewer chrome as the "Preview MOA" modal above for
     visual consistency. Images render via <img>, PDFs render via
     <iframe> pointed at the file's local blob URL — no upload, network
     request, or new tab involved. When a requirement has more than one
     file selected, prev/next arrows (added by
     renderReqFilePreviewModal()) let the user page through every file
     without leaving the modal. ── -->
<div id="reqFilePreviewModal" class="moa-doc-modal">
    <div class="moa-doc-modal-bar">
        <div class="moa-doc-modal-title">
            <i class="fas fa-image moa-doc-modal-icon" id="reqFilePreviewIcon"></i>
            <span class="moa-doc-modal-name" id="reqFilePreviewName">Preview</span>
            <span id="reqFilePreviewCounter" style="color:#94a3b8;font-size:12px;font-weight:600;"></span>
        </div>
        <button type="button" onclick="closeReqFilePreview()" class="moa-doc-modal-close" title="Close">&times;</button>
    </div>
    <div class="moa-doc-modal-viewer" id="reqFilePreviewViewerWrap" style="position:relative;"></div>
</div>

<!-- ── NAVBAR (same visual language as CompanyForm.php) ── -->
<nav class="navbar">
    <div class="logo-section">
        <img src="logo.webp" alt="System Logo" class="university-logo">
        <div>
            <div class="brand-title">NEUST Atate Campus</div>
            <div class="brand-subtitle">Web-Based Smart OJT Monitoring and Supervision Analytics System</div>
        </div>
    </div>
</nav>

<div class="reg-page-container">
    <div class="reg-wrap">

        <!-- ═══════════════════════════════════════════════════════════
             STEP PROGRESS BAR
             ═══════════════════════════════════════════════════════════ -->
        <div class="reg-stepper">
            <div class="reg-step-node active" id="stepNode1" data-step="1">
                <div class="reg-step-dot" id="stepDot1">1</div>
                <div class="reg-step-label">Account Info</div>
            </div>
            <div class="reg-step-node" id="stepNode2" data-step="2">
                <div class="reg-step-dot" id="stepDot2">2</div>
                <div class="reg-step-label">MOA Requirement</div>
            </div>
            <div class="reg-step-node" id="stepNode3" data-step="3">
                <div class="reg-step-dot" id="stepDot3">3</div>
                <div class="reg-step-label">Classification &amp; Docs</div>
            </div>
        </div>

        <div class="form-card">
            <form action="company_register.php" method="POST" enctype="multipart/form-data" id="companyRegForm" onsubmit="return validateCompanyRegForm()">

                <!-- ═══════════════════════════════════════════════════
                     STEP 1 — ACCOUNT INFORMATION
                     ═══════════════════════════════════════════════════ -->
                <div class="reg-step-section active-step" id="regStep1" data-step="1">
                    <h2>Company Registration</h2>
                    <p class="reg-step-subtitle">Step 1 of 3 &mdash; Let's start with your account details.</p>

                    <div class="reg-grid">
                        <div class="input-group">
                            <label>First Name <span class="required">*</span></label>
                            <input type="text" name="first_name" class="cap-words-input" placeholder="Enter First Name"
                                value="<?= htmlspecialchars($_POST['first_name'] ?? '') ?>">
                        </div>

                        <div class="input-group">
                            <label>Middle Name (Optional)</label>
                            <input type="text" name="middle_name" class="cap-words-input" placeholder="Enter Middle Name"
                                value="<?= htmlspecialchars($_POST['middle_name'] ?? '') ?>">
                        </div>

                        <div class="input-group">
                            <label>Last Name <span class="required">*</span></label>
                            <input type="text" name="last_name" class="cap-words-input" placeholder="Enter Last Name"
                                value="<?= htmlspecialchars($_POST['last_name'] ?? '') ?>">
                        </div>

                        <div class="input-group span-3">
                            <label>Email <span class="required">*</span></label>
                            <input type="email" name="email" placeholder="email@example.com"
                                value="<?= htmlspecialchars($_POST['email'] ?? '') ?>">
                        </div>
                    </div>

                    <div class="reg-btn-row">
                        <button type="button" class="btn-submit" style="flex:1;" onclick="regNextStep(1)">Next: MOA Requirement <i class="fas fa-arrow-right"></i></button>
                    </div>

                    <p class="footer-text">
                        Already have an account?
                        <a href="company_login.php">Login</a>
                    </p>
                </div>

                <!-- ═══════════════════════════════════════════════════
                     STEP 2 — MOA REQUIREMENT + COMPANY DETAILS
                     ─────────────────────────────────────────────────
                     The "Already Have MOA" option has been removed
                     entirely. This registration flow now only ever
                     requests a brand-new MOA, so moa_type is fixed
                     to "new" via a hidden field below (used only to
                     drive the Preview MOA button — the account itself
                     stores its own fixed "New" request_type value on
                     the users table, and, on successful submission, the
                     MOA is auto-generated and saved as a Pending
                     company_requirements row — see the STEP 2d block in
                     the POST handler above). An informational notice
                     tells the company that the fields on this step will
                     be used to generate their MOA document, and reminds
                     them to double-check everything before continuing.
                     ═══════════════════════════════════════════════════ -->
                <div class="reg-step-section" id="regStep2" data-step="2">
                    <h2>Request New MOA</h2>
                    <p class="reg-step-subtitle">Step 2 of 3 &mdash; This information will be used to generate your Memorandum of Agreement (MOA).</p>

                    <!-- MOA requirement is fixed to "new" — the
                         "Already Have MOA" path has been removed from
                         this registration flow. -->
                    <input type="hidden" name="moa_type" value="new">

                    <div class="moa-generate-notice">
                        <span class="moa-generate-notice-icon"><i class="fas fa-file-signature"></i></span>
                        <div>
                            <p class="moa-generate-notice-title">These details will generate your MOA</p>
                            <p class="moa-generate-notice-text">
                                Everything you enter below &mdash; your company name, address, position, and
                                company profile &mdash; will be used exactly as written to generate your
                                Memorandum of Agreement (MOA) document once your account is created.
                            </p>
                            <span class="moa-generate-notice-reminder">
                                <i class="fas fa-triangle-exclamation"></i>
                                Please double-check all information below before proceeding.
                            </span>
                        </div>
                    </div>

                    <div class="reg-grid">
                        <div class="input-group span-2">
                            <label>Company Name <span class="required">*</span></label>
                            <input type="text" name="company_name" class="cap-words-input" placeholder="Enter company name"
                                value="<?= htmlspecialchars($_POST['company_name'] ?? '') ?>">
                        </div>
                        <div class="input-group">
                            <label>Telephone Number <span class="required">*</span></label>
                            <input type="tel" id="companyTelephoneInput" name="telephone" placeholder="Numbers only"
                                inputmode="numeric" pattern="[0-9]*" maxlength="20"
                                value="<?= htmlspecialchars($_POST['telephone'] ?? '') ?>">
                        </div>

                        <div class="input-group span-2">
                            <label>Company Address <span class="required">*</span></label>
                            <input type="text" name="company_address" class="cap-words-input" placeholder="Enter principal office address"
                                value="<?= htmlspecialchars($_POST['company_address'] ?? '') ?>">
                        </div>
                        <div class="input-group">
                            <label>Position <span class="required">*</span></label>
                            <input type="text" name="position" class="cap-words-input" placeholder="e.g. HR Manager"
                                value="<?= htmlspecialchars($_POST['position'] ?? '') ?>">
                        </div>

                        <div class="input-group span-3">
                            <label>Company Profile / Brief Description <span class="required">*</span></label>
                            <textarea name="company_profile" placeholder="Briefly describe the company"><?= htmlspecialchars($_POST['company_profile'] ?? '') ?></textarea>
                        </div>
                    </div>

                    <!-- "Preview MOA" — lets the company see the actual MOA
                         document — built the same way moa_request.php builds
                         its own live preview, via buildMOAFormHTML() from
                         MOA_form_builder.php — using whatever they've typed
                         into Step 1/Step 2 so far, before they ever create
                         an account or submit anything. Always available now
                         since this flow only ever requests a new MOA. -->
                    <div class="input-group" id="newMoaPreviewSection">
                        <button type="button" class="btn-secondary" onclick="openNewMoaPreview()">
                            <i class="fas fa-eye"></i> Preview MOA
                        </button>
                    </div>

                    <div class="reg-btn-row">
                        <button type="button" class="btn-secondary" onclick="regPrevStep(2)"><i class="fas fa-arrow-left"></i> Back</button>
                        <button type="button" class="btn-submit" onclick="regNextStep(2)">Next: Classification <i class="fas fa-arrow-right"></i></button>
                    </div>
                </div>

                <!-- ═══════════════════════════════════════════════════
                     STEP 3 — COMPANY CLASSIFICATION + COMPLIANCE DOCS
                     ─────────────────────────────────────────────────
                     NOTE (this update): "Authority to Sign MOA" is now
                     rendered and required exactly the same as every
                     other document in $private_reqs / $public_reqs — no
                     special-casing, no hidden class, no "Not required"
                     note. It is required for BOTH classifications.
                     ═══════════════════════════════════════════════════ -->
                <div class="reg-step-section" id="regStep3" data-step="3">
                    <h2>Classification &amp; Compliance</h2>
                    <p class="reg-step-subtitle">Step 3 of 3 &mdash; Select your company type, then upload the required documents for that classification.</p>

                    <?php $company_type_posted = $_POST['company_type'] ?? ''; ?>
                    <div class="moa-type-row">
                        <label class="moa-type-card" id="card-type-private">
                            <input type="radio" name="company_type" value="private" style="display:none;"
                                onchange="selectCompanyType('private')" <?= $company_type_posted === 'private' ? 'checked' : '' ?>>
                            <span class="moa-icon">&#127970;</span>
                            <span class="moa-label">Private Company</span>
                            <span class="moa-sublabel">Private-sector business or organization</span>
                        </label>
                        <label class="moa-type-card" id="card-type-public">
                            <input type="radio" name="company_type" value="public" style="display:none;"
                                onchange="selectCompanyType('public')" <?= $company_type_posted === 'public' ? 'checked' : '' ?>>
                            <span class="moa-icon">&#127963;&#65039;</span>
                            <span class="moa-label">Public / Government</span>
                            <span class="moa-sublabel">Government agency or public institution</span>
                        </label>
                    </div>

                    <div id="privateReqsSection">
                        <p class="req-note">Accepted: JPG or PDF only &middot; Max 5MB each &middot; JPG uploads support selecting multiple files, but PDF uploads are limited to one file &middot; all files for a document must be the same format</p>
                        <div class="req-grid">
                        <?php foreach ($private_reqs as $reqKey => $reqLabel): ?>
                            <div class="input-group req-upload-group" id="reqGroup_<?= $reqKey ?>">
                                <label><?= htmlspecialchars($reqLabel) ?> <span class="required">*</span></label>
                                <div class="req-file-drop" id="reqDrop_<?= $reqKey ?>" onclick="document.getElementById('reqFile_<?= $reqKey ?>').click()">
                                    <span class="req-file-icon">&#128196;</span>
                                    <span class="req-file-text">Click to upload</span>
                                </div>
                                <div class="req-file-preview-gallery" id="reqPreviewGallery_<?= $reqKey ?>" style="display:none;"></div>
                                <button type="button" class="req-reselect-btn" id="reqReselectBtn_<?= $reqKey ?>"
                                        onclick="document.getElementById('reqFile_<?= $reqKey ?>').click()">
                                    <i class="fas fa-redo"></i> Re-select File(s)
                                </button>
                                <input type="file" id="reqFile_<?= $reqKey ?>" name="req_<?= $reqKey ?>[]" class="req-file-input" multiple
                                    data-label="<?= htmlspecialchars($reqLabel) ?>"
                                    accept=".jpg,.jpeg,.pdf,image/jpeg,application/pdf" style="display:none;"
                                    onchange="handleReqUploadChange(this, '<?= $reqKey ?>')">
                                <input type="hidden" id="reqCount_<?= $reqKey ?>" name="req_<?= $reqKey ?>_expected_count" value="0">
                            </div>
                        <?php endforeach; ?>
                        </div>
                    </div>

                    <div id="publicReqsSection">
                        <p class="req-note">Accepted: JPG or PDF only &middot; Max 5MB each &middot; JPG uploads support selecting multiple files, but PDF uploads are limited to one file &middot; all files for a document must be the same format</p>
                        <div class="req-grid">
                        <?php foreach ($public_reqs as $reqKey => $reqLabel): ?>
                            <div class="input-group req-upload-group" id="reqGroup_<?= $reqKey ?>">
                                <label><?= htmlspecialchars($reqLabel) ?> <span class="required">*</span></label>
                                <div class="req-file-drop" id="reqDrop_<?= $reqKey ?>" onclick="document.getElementById('reqFile_<?= $reqKey ?>').click()">
                                    <span class="req-file-icon">&#128196;</span>
                                    <span class="req-file-text">Click to upload</span>
                                </div>
                                <div class="req-file-preview-gallery" id="reqPreviewGallery_<?= $reqKey ?>" style="display:none;"></div>
                                <button type="button" class="req-reselect-btn" id="reqReselectBtn_<?= $reqKey ?>"
                                        onclick="document.getElementById('reqFile_<?= $reqKey ?>').click()">
                                    <i class="fas fa-redo"></i> Re-select File(s)
                                </button>
                                <input type="file" id="reqFile_<?= $reqKey ?>" name="req_<?= $reqKey ?>[]" class="req-file-input" multiple
                                    data-label="<?= htmlspecialchars($reqLabel) ?>"
                                    accept=".jpg,.jpeg,.pdf,image/jpeg,application/pdf" style="display:none;"
                                    onchange="handleReqUploadChange(this, '<?= $reqKey ?>')">
                                <input type="hidden" id="reqCount_<?= $reqKey ?>" name="req_<?= $reqKey ?>_expected_count" value="0">
                            </div>
                        <?php endforeach; ?>
                        </div>
                    </div>

                    <div class="reg-btn-row">
                        <button type="button" class="btn-secondary" onclick="regPrevStep(3)"><i class="fas fa-arrow-left"></i> Back</button>
                        <button type="submit" class="btn-submit reg-hidden" id="regSubmitBtn"><i class="fas fa-check"></i> Register</button>
                    </div>
                </div>

            </form>
        </div>
    </div>
</div>

<script>
const rpType     = <?= json_encode($popup_type) ?>;
const rpTitle    = <?= json_encode($popup_title) ?>;
const rpMsg      = <?= json_encode($popup_msg) ?>;
const rpRedirect = <?= json_encode($popup_redirect) ?>;
const rpStep     = <?= json_encode($popup_step) ?>;

function rpClose() {
    document.getElementById('regPopupOverlay').style.display = 'none';
    if (rpRedirect) window.location.href = rpRedirect;
}

document.addEventListener('DOMContentLoaded', function () {
    if (!rpType) return;

    const iconMap = { error: '', success: '', warning: '' };

    document.getElementById('rpIcon').textContent  = iconMap[rpType] || '❕';
    document.getElementById('rpTitle').textContent = rpTitle;
    document.getElementById('rpTitle').className   = 'rp-title ' + rpType;
    document.getElementById('rpMsg').textContent   = rpMsg;
    document.getElementById('rpBtn').className     = 'rp-btn ' + rpType;

    document.getElementById('regPopupOverlay').style.display = 'flex';
});
</script>

<script>
/* ══════════════════════════════════════════════════════════
   NEW: Global loading/processing overlay controls — mirrors
   admin_company_list.php's showGlobalLoading()/hideGlobalLoading()
   pair exactly. showGlobalLoading(label) reveals the popup with an
   optional custom label ("Loading…", "Submitting…", etc.).
   hideGlobalLoading() fades it out. A small usage counter
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

/* The overlay is visible by default (see CSS) so it covers the very
   first paint while page assets are still loading. As soon as the
   window has fully finished loading, it fades away on its own. */
window.addEventListener('load', function() {
    globalLoadingActiveCount = 0;
    if (globalLoadingOverlay) globalLoadingOverlay.classList.add('hidden');
});
/* Safety net: if for any reason the 'load' event is delayed (slow
   third-party assets like the Font Awesome CDN), don't leave the
   company staring at the popup forever — hide it after a short
   ceiling too. */
setTimeout(function() {
    globalLoadingActiveCount = 0;
    if (globalLoadingOverlay) globalLoadingOverlay.classList.add('hidden');
}, 4000);

/* If the server round-tripped back to this page with a popup result
   (success/error/warning already handled above), make sure the
   loading overlay isn't left covering it. */
document.addEventListener('DOMContentLoaded', function() {
    if (rpType) {
        globalLoadingActiveCount = 0;
        if (globalLoadingOverlay) globalLoadingOverlay.classList.add('hidden');
    }
});
</script>

<script>
// ── Multi-step navigation ────────────────────────────────────────────
let regCurrentStep = 1;
const REG_TOTAL_STEPS = 3;

function regShowStep(step) {
    regCurrentStep = step;
    document.querySelectorAll('.reg-step-section').forEach(function(sec) {
        sec.classList.toggle('active-step', parseInt(sec.getAttribute('data-step'), 10) === step);
    });
    document.querySelectorAll('.reg-step-node').forEach(function(node) {
        const nodeStep = parseInt(node.getAttribute('data-step'), 10);
        node.classList.remove('done', 'active');
        if (nodeStep < step) node.classList.add('done');
        else if (nodeStep === step) node.classList.add('active');
    });
    document.querySelectorAll('.reg-step-dot').forEach(function(dot, idx) {
        const nodeStep = idx + 1;
        if (nodeStep < step) dot.innerHTML = '<i class="fas fa-check" style="font-size:13px;"></i>';
        else dot.textContent = nodeStep;
    });
    window.scrollTo({ top: 0, behavior: 'smooth' });
}

function regValidateStep1() {
    const first = document.querySelector('input[name="first_name"]').value.trim();
    const last  = document.querySelector('input[name="last_name"]').value.trim();
    const email = document.querySelector('input[name="email"]').value.trim();
    const emailPattern = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;

    if (!first || !last || !email) {
        moaShowNotify('Please fill in your first name, last name, and email before continuing.', 'Missing Fields');
        return false;
    }
    if (!emailPattern.test(email)) {
        moaShowNotify('Please enter a valid email address.', 'Invalid Email');
        return false;
    }
    return true;
}

function regValidateStep2() {
    // MOA requirement is now fixed to "Request New MOA" (see the hidden
    // moa_type field in Step 2) — the "Already Have MOA" choice and its
    // related upload requirement have been removed, so there is nothing
    // to validate for the MOA type itself anymore.

    const companyName    = document.querySelector('input[name="company_name"]').value.trim();
    const companyAddress = document.querySelector('input[name="company_address"]').value.trim();
    const telephone      = document.querySelector('input[name="telephone"]').value.trim();
    const position       = document.querySelector('input[name="position"]').value.trim();
    const companyProfile = document.querySelector('textarea[name="company_profile"]').value.trim();

    if (!companyName || !companyAddress || !telephone || !position || !companyProfile) {
        moaShowNotify('Please fill in all required company details before continuing.', 'Missing Fields');
        return false;
    }
    return true;
}

function regValidateStep3() {
    const companyTypeChecked = document.querySelector('input[name="company_type"]:checked');
    if (!companyTypeChecked) {
        moaShowNotify('Please select whether your company is Private or Public/Government.', 'Classification Required');
        return false;
    }

    const activeSection = companyTypeChecked.value === 'public'
        ? document.getElementById('publicReqsSection')
        : document.getElementById('privateReqsSection');

    if (activeSection) {
        const missingLabels = [];
        activeSection.querySelectorAll('.req-file-input').forEach(function(inp) {
            var group = inp.closest('.req-upload-group');
            // Skip fields that are currently hidden for any reason (none
            // are hidden by default anymore — every requirement in this
            // classification, including "Authority to Sign MOA", is
            // required and visible).
            if (group && group.offsetParent === null) return;
            if (!inp.files || inp.files.length === 0) {
                missingLabels.push(inp.getAttribute('data-label') || 'a required document');
            }
        });
        if (missingLabels.length > 0) {
            moaShowNotify('Please upload the following required document(s):\n- ' + missingLabels.join('\n- '), 'Missing Requirement Documents');
            return false;
        }
    }
    return true;
}

function regNextStep(fromStep) {
    let ok = true;
    if (fromStep === 1) ok = regValidateStep1();
    else if (fromStep === 2) ok = regValidateStep2();

    if (!ok) return;
    if (fromStep < REG_TOTAL_STEPS) regShowStep(fromStep + 1);
}

function regPrevStep(fromStep) {
    if (fromStep > 1) regShowStep(fromStep - 1);
}

// Final safety-net validation, still runs on actual form submit
// (covers Step 2 + Step 3 requirements even if someone bypasses the
// Next-button flow, e.g. by pressing Enter).
function validateCompanyRegForm() {
    if (!regValidateStep1()) { regShowStep(1); return false; }
    if (!regValidateStep2()) { regShowStep(2); return false; }
    if (!regValidateStep3()) { regShowStep(3); return false; }
    // NEW: show the global loading overlay while the registration
    // request is submitted and the server processes it (account
    // creation, requirement uploads, MOA generation, email sending).
    // The page navigates away on the resulting redirect/round-trip,
    // so no matching hideGlobalLoading() call is needed here.
    showGlobalLoading('Submitting registration');
    return true;
}

// If the server round-tripped with a validation error, reopen the form
// on the step that actually needs attention (values are already
// re-populated by PHP above).
document.addEventListener('DOMContentLoaded', function() {
    if (rpType === 'error' && rpStep >= 1 && rpStep <= REG_TOTAL_STEPS) {
        regShowStep(rpStep);
    } else {
        regShowStep(1);
    }
});
</script>

<script>
// ── MOA requirement UI behavior ─────────────────────────────────────────
function moaShowNotify(message, title) {
    var overlay = document.getElementById('moaNotifyOverlay');
    if (!overlay) { window.alert(message); return; }
    document.getElementById('moaNotifyTitle').textContent = title || 'Notice';
    document.getElementById('moaNotifyMsg').textContent   = message;
    overlay.style.display = 'flex';
}

// ── Company Classification UI behavior ────────────────────────────────────
function selectCompanyType(type) {
    document.getElementById('card-type-private').classList.toggle('selected', type === 'private');
    document.getElementById('card-type-public').classList.toggle('selected', type === 'public');

    var privSection = document.getElementById('privateReqsSection');
    var pubSection  = document.getElementById('publicReqsSection');

    if (type === 'private') {
        privSection.style.display = 'block';
        pubSection.style.display  = 'none';
    } else {
        privSection.style.display = 'none';
        pubSection.style.display  = 'block';
    }

    regUpdateRegisterButtonState();
}

// Restore classification selection + section visibility after a failed submission.
(function() {
    var postedCompanyType = <?= json_encode($company_type_posted ?? '') ?>;
    if (postedCompanyType === 'private' || postedCompanyType === 'public') {
        selectCompanyType(postedCompanyType);
    } else {
        regUpdateRegisterButtonState();
    }
})();

// ── Multi-file requirement upload: validate, preview, and toggle
// the "Re-select" control ─────────────────────────────────────────────
// Only JPG and PDF files (5MB max each) are accepted. JPG files can be
// selected in multiples at once (e.g. front/back of a document, or a
// multi-page scan split into separate images), in addition to a single
// PDF. Each requirement's selection must be entirely one format or the
// other — mixing JPG and PDF files within the same selection is rejected
// with a popup so the stored requirement stays consistent.
//
// ── DISPLAY ───────────────────────────────────────────────────────
// A single file still renders as the original plain thumbnail / PDF
// badge (.req-preview-item). Two or more files now render as one
// layered "photo stack" card (.req-file-stack) with a count badge,
// instead of a full grid of individual thumbnails — clicking the stack
// opens the in-page preview modal with prev/next navigation so the user
// can still review every file.
const REQ_UPLOAD_ALLOWED_TYPES = ['image/jpeg', 'application/pdf'];
const REQ_UPLOAD_MAX_FILE_SIZE = 5 * 1024 * 1024;

var reqFilePreviewData    = {};  // key -> [{url, type, name}, ...] for the modal to page through
var reqFileObjectUrls     = {};  // key -> [url, ...] created for this key, revoked on re-selection
var reqPreviewCurrentKey  = null;
var reqPreviewCurrentIndex = 0;

function handleReqUploadChange(input, key) {
    var files = Array.prototype.slice.call(input.files || []);

    if (files.length === 0) {
        renderReqUploadPreview(key, []);
        return;
    }

    var invalid = [];
    files.forEach(function(f) {
        if (REQ_UPLOAD_ALLOWED_TYPES.indexOf(f.type) === -1) {
            invalid.push(f.name + ' — only JPG or PDF files are allowed');
        } else if (f.size > REQ_UPLOAD_MAX_FILE_SIZE) {
            invalid.push(f.name + ' — exceeds the 5MB limit');
        }
    });

    if (invalid.length > 0) {
        moaShowNotify('Please fix the following file(s) and try again:\n- ' + invalid.join('\n- '), 'Invalid File(s)');
        input.value = '';
        renderReqUploadPreview(key, []);
        return;
    }

    // File-format consistency check: a single requirement upload
    // must be all JPG or all PDF, never a mix of both in the same
    // selection.
    var distinctTypes = files.reduce(function(acc, f) {
        if (acc.indexOf(f.type) === -1) acc.push(f.type);
        return acc;
    }, []);
    if (distinctTypes.length > 1) {
        moaShowNotify('Please select files of the same format only — either all JPG or all PDF, not a mix of both, for this document.', 'Mixed File Formats Not Allowed');
        input.value = '';
        renderReqUploadPreview(key, []);
        return;
    }

    // NEW (this update): PDF uploads are limited to exactly ONE file per
    // requirement — JPG uploads may still include multiple files, exactly
    // as before. This mirrors the same rule enforced server-side in
    // regValidateRequirementUploads() (see its docblock), so a bypassed
    // or disabled-JS submission is still rejected with a clear message
    // rather than silently keeping only one of the selected PDFs.
    if (distinctTypes.length === 1 && distinctTypes[0] === 'application/pdf' && files.length > 1) {
        moaShowNotify('Only one PDF file can be selected for this document. Please choose a single PDF file, or switch to JPG images if you need to upload multiple files.', 'Only One PDF Allowed');
        input.value = '';
        renderReqUploadPreview(key, []);
        return;
    }

    renderReqUploadPreview(key, files);
}

function renderReqUploadPreview(key, files) {
    var drop     = document.getElementById('reqDrop_' + key);
    var gallery  = document.getElementById('reqPreviewGallery_' + key);
    var reselect = document.getElementById('reqReselectBtn_' + key);
    var countField = document.getElementById('reqCount_' + key);
    // NEW: keep the hidden "expected count" field in sync with however many
    // files are currently selected for this requirement. The server
    // (regValidateRequirementUploads() in company_register.php) compares
    // this against how many files it actually receives, so a submission
    // that silently loses files somewhere between the browser and the
    // server (e.g. PHP's max_file_uploads limit truncating the request)
    // is caught and reported clearly instead of quietly saving only some
    // of the selected files.
    if (countField) countField.value = (files && files.length) ? files.length : 0;
    if (!gallery) return;

    // Revoke any object URLs created for this requirement's previous
    // selection before building fresh ones, to avoid leaking memory.
    if (reqFileObjectUrls[key]) {
        reqFileObjectUrls[key].forEach(function(u) { URL.revokeObjectURL(u); });
    }
    reqFileObjectUrls[key] = [];
    gallery.innerHTML = '';

    if (!files || files.length === 0) {
        gallery.style.display = 'none';
        if (drop)     drop.style.display = 'flex';
        if (reselect) reselect.style.display = 'none';
        reqFilePreviewData[key] = [];
        regUpdateRegisterButtonState();
        return;
    }

    var fileMeta = files.map(function(file) {
        var url = URL.createObjectURL(file);
        reqFileObjectUrls[key].push(url);
        return { url: url, type: file.type, name: file.name };
    });
    reqFilePreviewData[key] = fileMeta;

    if (fileMeta.length === 1) {
        // Single file — keep the original plain thumbnail / PDF-badge look.
        var meta = fileMeta[0];
        var item = document.createElement('div');
        item.className = 'req-preview-item';
        if (meta.type === 'application/pdf') {
            item.innerHTML =
                '<div class="req-preview-pdf"><i class="fas fa-file-pdf"></i></div>' +
                '<span class="req-preview-name">' + meta.name + '</span>' +
                '<span class="req-preview-hint">Click to preview PDF</span>';
        } else {
            item.innerHTML =
                '<img src="' + meta.url + '" alt="' + meta.name + '" title="Click to preview">' +
                '<span class="req-preview-name">' + meta.name + '</span>';
        }
        item.addEventListener('click', function() {
            openReqFilePreviewGallery(key, 0);
        });
        gallery.appendChild(item);
    } else {
        // Multiple files — show one layered "photo stack" card instead of
        // a full grid of thumbnails.
        var wrap = document.createElement('div');
        wrap.className = 'req-file-stack-wrap';

        var stack = document.createElement('div');
        stack.className = 'req-file-stack';

        var layerCount = Math.min(3, fileMeta.length);
        for (var i = layerCount - 1; i >= 0; i--) {
            var layerMeta = fileMeta[i];
            var layer = document.createElement('div');
            layer.className = 'req-stack-layer layer-' + (i + 1);
            if (layerMeta.type === 'application/pdf') {
                layer.classList.add('req-stack-layer-pdf');
                layer.innerHTML = '<i class="fas fa-file-pdf"></i>';
            } else {
                layer.style.backgroundImage = 'url(' + layerMeta.url + ')';
            }
            stack.appendChild(layer);
        }

        var badge = document.createElement('span');
        badge.className = 'req-stack-count-badge';
        badge.textContent = fileMeta.length;
        stack.appendChild(badge);

        stack.addEventListener('click', function() {
            openReqFilePreviewGallery(key, 0);
        });

        var label = document.createElement('div');
        label.className = 'req-file-stack-label';
        label.textContent = fileMeta.length + ' files selected';

        var hint = document.createElement('span');
        hint.className = 'req-file-stack-hint';
        hint.textContent = 'Click to preview';

        wrap.appendChild(stack);
        wrap.appendChild(label);
        wrap.appendChild(hint);
        gallery.appendChild(wrap);
    }

    gallery.style.display = 'flex';
    if (drop)     drop.style.display = 'none';
    if (reselect) reselect.style.display = 'flex';

    regUpdateRegisterButtonState();
}

// ── shows the Step 3 "Register" button only once the company has
// selected a classification (Private / Public) AND every required
// document for that classification currently has at least one file
// attached. "Authority to Sign MOA" is now included in this check for
// both classifications, exactly like every other requirement — there
// are no hidden/skipped fields in Step 3 anymore.
function regUpdateRegisterButtonState() {
    var btn = document.getElementById('regSubmitBtn');
    if (!btn) return;

    var companyTypeChecked = document.querySelector('input[name="company_type"]:checked');
    if (!companyTypeChecked) {
        btn.classList.add('reg-hidden');
        return;
    }

    var activeSection = companyTypeChecked.value === 'public'
        ? document.getElementById('publicReqsSection')
        : document.getElementById('privateReqsSection');

    var allFilled = true;
    if (activeSection) {
        activeSection.querySelectorAll('.req-file-input').forEach(function(inp) {
            var group = inp.closest('.req-upload-group');
            if (group && group.offsetParent === null) return;
            if (!inp.files || inp.files.length === 0) allFilled = false;
        });
    } else {
        allFilled = false;
    }

    btn.classList.toggle('reg-hidden', !allFilled);
}

// ── in-page preview for a requirement's chosen file(s), opened in
// the "reqFilePreviewModal" instead of a new browser tab. Images render
// via <img>, PDFs render via <iframe> pointed at the file's local blob
// URL — the same URL already created by renderReqUploadPreview() above
// via URL.createObjectURL(), so nothing is uploaded or fetched over the
// network to show the preview. When a requirement has more than one
// file selected, prev/next arrows let the user page through all of them
// without closing the modal.
function openReqFilePreviewGallery(key, index) {
    var data = reqFilePreviewData[key];
    if (!data || !data.length) return;
    if (index < 0) index = data.length - 1;
    if (index >= data.length) index = 0;

    reqPreviewCurrentKey   = key;
    reqPreviewCurrentIndex = index;

    renderReqFilePreviewModal();

    var modal = document.getElementById('reqFilePreviewModal');
    if (modal) {
        modal.style.display = 'flex';
        document.body.style.overflow = 'hidden';
    }
}

function renderReqFilePreviewModal() {
    var data = reqFilePreviewData[reqPreviewCurrentKey];
    if (!data || !data.length) return;
    var meta = data[reqPreviewCurrentIndex];

    var wrap      = document.getElementById('reqFilePreviewViewerWrap');
    var nameEl    = document.getElementById('reqFilePreviewName');
    var iconEl    = document.getElementById('reqFilePreviewIcon');
    var counterEl = document.getElementById('reqFilePreviewCounter');
    if (!wrap) return;

    wrap.innerHTML = '';

    if (meta.type === 'application/pdf') {
        if (iconEl) iconEl.className = 'fas fa-file-pdf moa-doc-modal-icon';
        var iframe = document.createElement('iframe');
        iframe.src = meta.url;
        iframe.title = meta.name || 'PDF Preview';
        wrap.appendChild(iframe);
    } else {
        if (iconEl) iconEl.className = 'fas fa-image moa-doc-modal-icon';
        var img = document.createElement('img');
        img.src = meta.url;
        img.alt = meta.name || 'Image Preview';
        img.className = 'req-preview-modal-img';
        wrap.appendChild(img);
    }

    if (data.length > 1) {
        var prevBtn = document.createElement('button');
        prevBtn.type = 'button';
        prevBtn.className = 'req-preview-nav-btn req-preview-nav-prev';
        prevBtn.innerHTML = '<i class="fas fa-chevron-left"></i>';
        prevBtn.addEventListener('click', function(e) {
            e.stopPropagation();
            reqPreviewCurrentIndex--;
            if (reqPreviewCurrentIndex < 0) reqPreviewCurrentIndex = data.length - 1;
            renderReqFilePreviewModal();
        });

        var nextBtn = document.createElement('button');
        nextBtn.type = 'button';
        nextBtn.className = 'req-preview-nav-btn req-preview-nav-next';
        nextBtn.innerHTML = '<i class="fas fa-chevron-right"></i>';
        nextBtn.addEventListener('click', function(e) {
            e.stopPropagation();
            reqPreviewCurrentIndex++;
            if (reqPreviewCurrentIndex >= data.length) reqPreviewCurrentIndex = 0;
            renderReqFilePreviewModal();
        });

        wrap.appendChild(prevBtn);
        wrap.appendChild(nextBtn);
    }

    if (nameEl)    nameEl.textContent    = meta.name || 'Preview';
    if (counterEl) counterEl.textContent = data.length > 1 ? (reqPreviewCurrentIndex + 1) + ' / ' + data.length : '';
}

function closeReqFilePreview() {
    var modal = document.getElementById('reqFilePreviewModal');
    if (!modal) return;
    modal.style.display = 'none';
    document.body.style.overflow = '';
    var wrap = document.getElementById('reqFilePreviewViewerWrap');
    if (wrap) wrap.innerHTML = '';
    reqPreviewCurrentKey   = null;
    reqPreviewCurrentIndex = 0;
}

document.addEventListener('DOMContentLoaded', function() {
    var reqFilePreviewModal = document.getElementById('reqFilePreviewModal');
    if (reqFilePreviewModal) {
        reqFilePreviewModal.addEventListener('click', function(e) {
            if (e.target === this) closeReqFilePreview();
        });
    }
});

// ── Auto-capitalize the first letter of each word as the company types
// into the name/company text fields, mirroring the "Add Company" form's
// cap-words-input behavior in admin_company_list.php. Purely cosmetic —
// does not affect validation or the values actually submitted otherwise. ──
(function() {
    function capitalizeWordsOnInput(e) {
        var input = e.target;
        var start = input.selectionStart;
        var end = input.selectionEnd;
        var val = input.value;
        var newVal = val.replace(/(^|\s)([a-z])/g, function(m, boundary, letter) { return boundary + letter.toUpperCase(); });
        if (newVal !== val) {
            input.value = newVal;
            input.setSelectionRange(start, end);
        }
    }
    document.querySelectorAll('.cap-words-input').forEach(function(el) {
        el.addEventListener('input', capitalizeWordsOnInput);
    });
})();

// ── Telephone: digits only ────────────────────────────────────────────────
(function() {
    var telField = document.getElementById('companyTelephoneInput');
    if (!telField) return;
    var allowedControlKeys = ['Backspace','Delete','Tab','Escape','Enter','ArrowLeft','ArrowRight','ArrowUp','ArrowDown','Home','End'];
    telField.addEventListener('keydown', function(e) {
        if (allowedControlKeys.indexOf(e.key) !== -1) return;
        if (e.ctrlKey || e.metaKey) return;
        if (/^[0-9]$/.test(e.key)) return;
        e.preventDefault();
    });
    telField.addEventListener('input', function() {
        var start = telField.selectionStart;
        var original = telField.value;
        var digitsOnly = original.replace(/[^0-9]/g, '');
        if (digitsOnly !== original) {
            var digitsBeforeCursor = original.slice(0, start).replace(/[^0-9]/g, '').length;
            telField.value = digitsOnly;
            telField.setSelectionRange(digitsBeforeCursor, digitsBeforeCursor);
        }
    });
})();
</script>

<script>
// ── "Preview MOA" (Request New MOA flow) ────────────────────────────────
// Opens the moaNewMoaPreviewModal and POSTs the company/contact fields the
// company has typed so far (Step 1 + Step 2) into the hidden target iframe
// inside that modal, using the classic "submit a form into a named iframe"
// technique — no fetch/AJAX/CORS plumbing required. The server-side
// preview_new_moa endpoint (see the top of this file) renders the MOA via
// buildMOAFormHTML() from MOA_form_builder.php, the exact same function
// moa_request.php uses for its own on-screen MOA preview, so what the
// company sees here matches what they'd see later on that page.
function openNewMoaPreview() {
    var firstField   = document.querySelector('input[name="first_name"]');
    var lastField    = document.querySelector('input[name="last_name"]');
    var middleField  = document.querySelector('input[name="middle_name"]');
    var companyNameField    = document.querySelector('input[name="company_name"]');
    var companyAddressField = document.querySelector('input[name="company_address"]');
    var positionField       = document.querySelector('input[name="position"]');
    var companyProfileField = document.querySelector('textarea[name="company_profile"]');

    var first          = firstField ? firstField.value.trim() : '';
    var last           = lastField ? lastField.value.trim() : '';
    var companyName    = companyNameField ? companyNameField.value.trim() : '';
    var companyAddress = companyAddressField ? companyAddressField.value.trim() : '';
    var position       = positionField ? positionField.value.trim() : '';
    var companyProfile = companyProfileField ? companyProfileField.value.trim() : '';

    if (!first || !last || !companyName || !companyAddress || !position || !companyProfile) {
        moaShowNotify('Please fill in your name and company details (Step 1 and Step 2) before previewing the MOA.', 'Missing Fields');
        return;
    }

    var fieldsToPost = {
        first_name: first,
        middle_name: middleField ? middleField.value : '',
        last_name: last,
        company_name: companyName,
        company_address: companyAddress,
        position: position,
        company_profile: companyProfile
    };

    var tempForm = document.createElement('form');
    tempForm.method = 'POST';
    tempForm.action = 'company_register.php?preview_new_moa=1';
    tempForm.target = 'moaNewMoaPreviewTargetFrame';
    tempForm.style.display = 'none';

    Object.keys(fieldsToPost).forEach(function(key) {
        var inp = document.createElement('input');
        inp.type = 'hidden';
        inp.name = key;
        inp.value = fieldsToPost[key];
        tempForm.appendChild(inp);
    });

    document.body.appendChild(tempForm);
    tempForm.submit();
    document.body.removeChild(tempForm);

    var modal = document.getElementById('moaNewMoaPreviewModal');
    if (modal) {
        modal.style.display = 'flex';
        document.body.style.overflow = 'hidden';
    }
}

function closeNewMoaPreview() {
    var modal = document.getElementById('moaNewMoaPreviewModal');
    if (!modal) return;
    modal.style.display = 'none';
    document.body.style.overflow = '';
}

document.addEventListener('DOMContentLoaded', function() {
    var newMoaPreviewModal = document.getElementById('moaNewMoaPreviewModal');
    if (newMoaPreviewModal) {
        newMoaPreviewModal.addEventListener('click', function(e) {
            if (e.target === this) closeNewMoaPreview();
        });
    }
});
</script>


</body>
</html>