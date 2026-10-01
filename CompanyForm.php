<?php
session_start(); // START SESSION FIRST

if (!isset($_SESSION['role']) || $_SESSION['role'] != "company") {
    header("Location: login.php");
    exit;
}

// ... rest of your code


include "db.php";

// Ensure MOA workflow columns exist on company_requirements (same columns the admin panel uses)
$conn->query("ALTER TABLE company_requirements ADD COLUMN IF NOT EXISTS moa_workflow_stage VARCHAR(30) DEFAULT 'pending'");
$conn->query("ALTER TABLE company_requirements ADD COLUMN IF NOT EXISTS moa_admin_comment TEXT NULL");
$conn->query("ALTER TABLE company_requirements ADD COLUMN IF NOT EXISTS moa_schedule_datetime DATETIME NULL");

// ════════════════════════════════════════════════════════════════════════
//  NEW (this adjustment) — Load MOA_form_builder.php, the SAME builder
//  moa_request.php and company_register.php's own live "Preview MOA"
//  button use, so the "Preview MOA" action added to the MOA Initial
//  Creation panel below (see $moa_needs_initial_creation) renders an
//  identical-looking preview. This is purely additive — it does not
//  replace or change regBuildMOAStaticHTMLForDompdf() further down in
//  this file (copied from company_register.php earlier), which remains
//  exactly as it was and is still what both the Preview endpoint's
//  fallback AND the actual MOA creation/regeneration use.
// ════════════════════════════════════════════════════════════════════════
if (file_exists(__DIR__ . '/MOA_form_builder.php')) {
    require_once __DIR__ . '/MOA_form_builder.php';
}

// ════════════════════════════════════════════════════════════════════════
//  NEW (this adjustment) — "Preview MOA" endpoint, for the MOA Initial
//  Creation panel (a company added via admin_company_list.php's manual
//  "Add Company" form or XLSX import, with request_type "New", whose MOA
//  hasn't been created yet — see $moa_needs_initial_creation further
//  down). Lets the company see what their MOA will actually look like,
//  built live from whatever they've currently typed into the unlocked
//  fields, BEFORE they commit to clicking "Create MOA".
//
//  Called via a hidden-form POST into a named target iframe inside the
//  #moaCreatePreviewModal modal (see openMoaCreatePreview() near the
//  bottom of this file) — the exact same technique
//  company_register.php's own Step 2 "Preview MOA" button already uses
//  for the same reason (no fetch/AJAX/CORS plumbing needed for streaming
//  an HTML document into an iframe).
//
//  Nothing is read from or written to the database here beyond the
//  session/login check already enforced at the very top of this file —
//  this only renders the MOA preview HTML from the posted values and
//  exits immediately, mirroring company_register.php's own
//  preview_new_moa endpoint (including its buildMOAFormHTML() /
//  regBuildMOAStaticHTMLForDompdf() fallback pattern) line-for-line.
// ════════════════════════════════════════════════════════════════════════
if (isset($_GET['preview_new_moa'])) {
    $pFirst          = trim($_POST['contact_first_name'] ?? '');
    $pMiddle         = trim($_POST['contact_middle_initial'] ?? '');
    $pLast           = trim($_POST['contact_last_name']  ?? '');
    $pCompanyName    = trim($_POST['company']            ?? '');
    $pCompanyAddress = trim($_POST['company_address']    ?? '');
    $pPosition       = trim($_POST['position']           ?? '');
    $pCompanyProfile = trim($_POST['company_profile']    ?? '');

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
        // reuse this file's own Dompdf-safe static builder (copied earlier
        // from company_register.php; PHP registers top-level function
        // declarations at compile time, so calling it here before its
        // textual position in the file is safe) so the Preview button
        // still renders something instead of failing silently.
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

// ════════════════════════════════════════════════════════════════════════
//  NEW — CLASSIFICATION-BASED COMPLIANCE REQUIREMENTS INTEGRATION
//  ──────────────────────────────────────────────────────────────────────
//  These are the EXACT SAME requirement keys/labels and upload rules
//  (JPG/PNG/PDF, 5MB max) used in company_register.php's Step 3
//  ("Classification & Docs"), so a company logging in here sees the same
//  checklist of compliance documents it was asked about at registration,
//  and can view status / upload missing items / resubmit denied ones —
//  without disturbing anything else already on this page (MOA workflow,
//  profile popup, sidebar, etc. are all untouched).
//
//  FIX (this update) — "Private company checklist only shows 6 items
//  instead of 10, and doesn't match company_register.php":
//  ──────────────────────────────────────────────────────────────────────
//  $private_reqs and $public_reqs below are now kept byte-for-byte
//  identical to the arrays of the same name in company_register.php's
//  Step 3 ("Classification & Docs") — same keys, same order, same
//  labels, ten private-company items and three public-company items.
//  This is now the single authoritative definition this page uses to
//  build the checklist; there is no other place in this file that
//  filters, slices, or otherwise reduces this list before it's rendered
//  further down (the display loop simply does
//  `foreach ($reqDefsForType as $reqKey => $reqLabel)` over the FULL
//  array), so every classification always shows its complete set of
//  required documents.
//
//  FIX (this update) — "Existing" vs "New" MOA request-type companies
//  must see the SAME compliance checklist:
//  ──────────────────────────────────────────────────────────────────────
//  $reqDefsForType (computed further below, right after
//  $moa_request_type/$moa_request_type_norm are resolved) is looked up
//  purely from $current_type (the company's Private/Public
//  classification) — it never reads $moa_request_type or
//  $moa_request_type_norm at all. $moa_request_type only ever controls
//  whether the separate "MOA Document Status" section is shown
//  ($showMoaSection, further below) — it has no bearing on which
//  compliance documents are required or displayed. That means a Private
//  company sees all 10 private-classification items, and a Public
//  company sees all 3 public-classification items, REGARDLESS of
//  whether their MOA request_type on file is "New" or "Existing".
//
//  UPDATE (this revision) — see the "NEW — MOA REQUIREMENT UPLOAD" block
//  further below: for "Existing" request-type companies ONLY, one extra
//  MOA upload item is appended onto the END of $reqDefsForType (after it
//  is first computed from $current_type). This appending happens AFTER
//  $moa_request_type_norm is resolved, and does not remove, reorder, or
//  otherwise touch any of the classification items already described
//  above — it is purely additive.
// ════════════════════════════════════════════════════════════════════════
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

// ════════════════════════════════════════════════════════════════════════
//  NEW — MOA REQUIREMENT UPLOAD (for "Existing" MOA request-type companies)
//  ──────────────────────────────────────────────────────────────────────
//  Companies with request_type = "Existing" don't go through the auto-
//  generated MOA drafting/review workflow shown in the "MOA Document
//  Status" section further down (that section is gated to
//  request_type = "New" only — see $showMoaSection). "Existing" companies
//  still need a way to submit their MOA document (e.g. proof of their
//  existing signed MOA) for the administrator to keep on file, so ONE
//  extra upload item is appended onto $reqDefsForType — once
//  $moa_request_type_norm is resolved below — for BOTH classifications
//  (Private and Public) whenever request_type is "Existing". This is
//  completely separate from the "MOA Document Status" workflow section:
//  it is a plain upload item that reuses the same Compliance Requirements
//  upload/validate/save/preview code paths as every other item, and it
//  is stored under its own dedicated requirement_type key
//  ("moa_existing_upload") so it can never collide with the 'moa' /
//  'moa_document' keys used by the MOA Document Status section's
//  stream_own_moa handler.
// ════════════════════════════════════════════════════════════════════════
$moa_existing_reqs = [
    "moa_existing_upload" => "MOA Document (Existing Partnership)",
];

$reqMaxFileSizeMB = 5;
$reqAllowedMimes  = ['image/jpeg', 'image/png', 'application/pdf'];

/**
 * ADJUSTMENT: every picture format is accepted for the compliance requirements
 * (JPG, PNG, GIF, WEBP, BMP, ...), not just JPG / PNG. Formats other than JPG /
 * PNG are converted to PNG on upload so the administrator's review pages and
 * every browser keep showing them like any other saved picture. Returns the
 * bytes to store, or null when the picture cannot be decoded by the server.
 */
function cfNormalizePictureBytes(string $tmpPath, string $mime): ?string
{
    if ($mime === 'image/jpeg' || $mime === 'image/png') {
        $raw = @file_get_contents($tmpPath);
        return ($raw === false || $raw === '') ? null : $raw;
    }
    $raw = @file_get_contents($tmpPath);
    $img = ($raw !== false && $raw !== '') ? @imagecreatefromstring($raw) : false;
    if (!$img) return null;
    imagealphablending($img, false);
    imagesavealpha($img, true);
    ob_start();
    imagepng($img);
    $png = ob_get_clean();
    imagedestroy($img);
    return ($png === false || $png === '') ? null : $png;
}

/* ADJUSTMENT (action loading page): what changed in the company's information.
   cfSnapshotInfo() reads the stored row (SELECT * so a column that does not exist yet is simply absent);
   cfDiffInfoAreas() compares a "before" and "after" snapshot and returns the AREAS that changed, named like
   the Company Information page: [['title' => 'Contact Person', 'fields' => ['Position', ...]], ...]. */
function cfSnapshotInfo(mysqli $conn, int $uid): array
{
    $row = [];
    $q = $conn->prepare("SELECT * FROM company_information WHERE user_id=? LIMIT 1");
    if ($q) {
        $q->bind_param("i", $uid);
        $q->execute();
        $res = $q->get_result();
        $row = $res ? ($res->fetch_assoc() ?: []) : [];
        $q->close();
    }
    return $row;
}

function cfDiffInfoAreas(array $before, array $after): array
{
    $map = [
        'Contact Person' => [
            'contact_first_name'     => 'Contact First Name',
            'contact_middle_initial' => 'Contact Middle Name',
            'contact_last_name'      => 'Contact Last Name',
            'position'               => 'Position',
            'telephone'              => 'Telephone / Contact Number',
        ],
        'Company Details' => [
            'company'                => 'Company Name',
            'company_address'        => 'Complete Office Address',
            'company_profile'        => 'Company Profile',
        ],
    ];
    $areas = [];
    foreach ($map as $title => $cols) {
        $changed = [];
        foreach ($cols as $col => $label) {
            if (trim((string)($before[$col] ?? '')) !== trim((string)($after[$col] ?? ''))) $changed[] = $label;
        }
        if ($changed) $areas[] = ['title' => $title, 'fields' => $changed];
    }
    return $areas;
}

/**
 * Validate ALL files submitted for one compliance-requirement field
 * ($_FILES[$fieldName][] — now a multi-file input), mirroring the exact
 * same rules and array-handling technique as company_register.php's
 * regValidateRequirementUploads(): same mime whitelist / size cap, the
 * same array_values() re-indexing so every submitted file is walked (not
 * just the first), and the same expected-vs-actual file count safety net
 * that catches a request silently losing files in transit (e.g. PHP's
 * max_file_uploads limit truncating things before this script even runs).
 *
 * FIX (this update): this REPLACES the old single-file
 * cfValidateRequirementUpload(), which only ever looked at
 * $_FILES[$fieldName] directly (not as an array) and could therefore only
 * ever validate ONE file per requirement — the compliance file input
 * itself didn't have the `multiple` attribute either, so a company could
 * never submit more than one file per requirement from THIS page in the
 * first place. Both are fixed together here.
 *
 * Returns ['ok'=>bool, 'error'=>string, 'bytes'=>string[]].
 * 'bytes' is an empty array (with ok=true) when nothing was submitted for
 * this field — that's not an error here, since resubmission is per-item
 * and most items are left untouched on any given submit.
 */
function cfValidateRequirementUploadsMulti(string $fieldName, string $label, int $maxFileSizeMB, array $allowedMimes): array
{
    if (!isset($_FILES[$fieldName]) || !isset($_FILES[$fieldName]['name']) || !is_array($_FILES[$fieldName]['name'])) {
        return ['ok' => true, 'error' => '', 'bytes' => []];
    }

    // Re-index every parallel array defensively — same fix already applied
    // in company_register.php's regValidateRequirementUploads().
    $namesArr = array_values($_FILES[$fieldName]['name']);
    $tmpNames = array_values($_FILES[$fieldName]['tmp_name']);
    $errors   = array_values($_FILES[$fieldName]['error']);
    $sizes    = array_values($_FILES[$fieldName]['size']);

    $submittedIndexes = [];
    foreach ($errors as $idx => $errCode) {
        if ($errCode !== UPLOAD_ERR_NO_FILE) {
            $submittedIndexes[] = $idx;
        }
    }

    if (empty($submittedIndexes)) {
        // Nothing selected for this item on this submit — not an error,
        // this item is simply left untouched (see the caller).
        return ['ok' => true, 'error' => '', 'bytes' => []];
    }

    $maxFileSize = $maxFileSizeMB * 1024 * 1024;

    // Same expected-vs-actual file count safety net as
    // company_register.php — see that file's regValidateRequirementUploads()
    // docblock for the full explanation of what this catches and why.
    $expectedCountRaw = $_POST[$fieldName . '_expected_count'] ?? null;
    if ($expectedCountRaw !== null && is_numeric($expectedCountRaw)) {
        $expectedCount = (int) $expectedCountRaw;
        $actualCount   = count($submittedIndexes);
        if ($expectedCount > 0 && $actualCount < $expectedCount) {
            error_log('[CF REQ VALIDATE] FILE COUNT MISMATCH for "' . $fieldName . '": browser selected '
                . $expectedCount . ' file(s) but the server only received ' . $actualCount . '.');
            return [
                'ok' => false,
                'error' => "You selected $expectedCount file(s) for \"$label\" but only $actualCount reached the server. "
                         . "This usually means a server upload limit (max_file_uploads in php.ini) was exceeded. "
                         . "Please try uploading fewer files at once for this document, or ask the administrator to raise that limit.",
                'bytes' => [],
            ];
        }
    }

    // ADJUSTMENT: PDF upload limit — same rule as company_register.php's
    // regValidateRequirementUploads(): a requirement accepts exactly ONE PDF,
    // and a PDF cannot be mixed with pictures in the same selection.
    // (Also enforced client-side with a popup; this is the server backstop.)
    $cfPdfCount = 0; $cfOtherCount = 0;
    foreach ($submittedIndexes as $idx) {
        if ($errors[$idx] !== UPLOAD_ERR_OK) continue;
        if (@mime_content_type($tmpNames[$idx]) === 'application/pdf') $cfPdfCount++; else $cfOtherCount++;
    }
    if ($cfPdfCount > 1) {
        return [
            'ok' => false,
            'error' => "Only one PDF file can be selected for \"$label\". Please choose a single PDF file, or switch to picture files if you need to upload multiple files for this document.",
            'bytes' => [],
        ];
    }
    if ($cfPdfCount === 1 && $cfOtherCount > 0) {
        return [
            'ok' => false,
            'error' => "Please select files of the same format only for \"$label\" — either all pictures or a single PDF, not a mix of both.",
            'bytes' => [],
        ];
    }

    $filesForKey = [];
    foreach ($submittedIndexes as $idx) {
        $thisFileName = $namesArr[$idx] ?? 'a file';

        if ($errors[$idx] !== UPLOAD_ERR_OK) {
            return ['ok' => false, 'error' => "There was a problem uploading \"$label\" ($thisFileName). Please try again.", 'bytes' => []];
        }

        $tmp  = $tmpNames[$idx];
        $size = $sizes[$idx];

        if ($size > $maxFileSize) {
            return ['ok' => false, 'error' => "\"$label\" ($thisFileName) exceeds the {$maxFileSizeMB}MB limit.", 'bytes' => []];
        }

        $mime = @mime_content_type($tmp);
        $isPicture = $mime && strpos($mime, 'image/') === 0; // ADJUSTMENT: any picture format is accepted
        if (!$mime || (!$isPicture && !in_array($mime, $allowedMimes, true))) {
            return ['ok' => false, 'error' => "\"$label\" ($thisFileName) must be a PDF or a picture file (JPG, PNG, GIF, WEBP, BMP, ...).", 'bytes' => []];
        }

        if ($isPicture) {
            $bytes = cfNormalizePictureBytes($tmp, $mime);
            if ($bytes === null) {
                return ['ok' => false, 'error' => "\"$label\" ($thisFileName) is in a picture format this server cannot read. Please convert it to JPG or PNG and try again.", 'bytes' => []];
            }
        } else {
            $bytes = @file_get_contents($tmp);
        }
        if ($bytes === false || $bytes === '') {
            return ['ok' => false, 'error' => "Failed to read \"$label\" ($thisFileName). Please try again.", 'bytes' => []];
        }

        $filesForKey[] = $bytes;
    }

    return ['ok' => true, 'error' => '', 'bytes' => $filesForKey];
}

/**
 * Replace every saved file for one requirement_type with a fresh set —
 * deletes all existing company_requirements rows for (user_id,
 * requirement_type), then inserts each new file as its own row, using the
 * same reliable direct "s"-type blob bind + affected-rows + blob-length
 * verification technique established in company_register.php's
 * regSaveCompanyRequirements(). Called only for requirement keys that
 * actually had new file(s) submitted (see the POST handler below) — items
 * left blank on a given submit are never touched, so their existing saved
 * file(s) and status are preserved exactly as before.
 *
 * FIX (this update): this REPLACES the old single-row
 * cfUpsertCompanyRequirement(), which (a) could only ever hold one file
 * per requirement_type — a resubmission with a NEW single file would
 * silently leave any OLDER extra rows (e.g. originally saved as multiple
 * files during registration) orphaned in the table untouched, and (b)
 * used the same send_long_data()-without-checking-the-return-value blob
 * technique that caused the "PDF not saved as blob" bug fixed earlier in
 * company_register.php. Replacing here fixes both at once, and keeps this
 * file's save behavior consistent with company_register.php's.
 *
 * A resubmission always resets status back to Pending (with no remark),
 * so it re-enters the admin's review queue — same behavior as before.
 */
function cfReplaceCompanyRequirementFiles(\mysqli $conn, int $userId, string $requirementType, array $fileBytesList): bool
{
    if (empty($fileBytesList)) return true; // nothing to do — caller already guards this, but stay defensive

    try {
        $stmt_del = $conn->prepare("DELETE FROM company_requirements WHERE user_id=? AND requirement_type=?");
        if (!$stmt_del) {
            error_log('[CF REQ REPLACE] prepare(delete) failed for "' . $requirementType . '": ' . $conn->error);
            return false;
        }
        $stmt_del->bind_param("is", $userId, $requirementType);
        $stmt_del->execute();
        $stmt_del->close();
    } catch (\Throwable $e) {
        error_log('[CF REQ REPLACE] Exception during delete for "' . $requirementType . '": ' . $e->getMessage());
        return false;
    }

    $allOk = true;
    foreach ($fileBytesList as $bytes) {
        if (!is_string($bytes) || $bytes === '') {
            error_log('[CF REQ REPLACE] Skipping empty file bytes for "' . $requirementType . '"');
            $allOk = false;
            continue;
        }

        try {
            // ADJUSTMENT (this update): moa_workflow_stage is explicitly saved as
            // NULL here. That column has DEFAULT 'pending' (see the ALTER at the
            // top of this file), so leaving it out made every uploaded compliance
            // requirement row get 'pending' — only the MOA document row
            // (moa_document / moa) is supposed to carry a workflow stage.
            $stmt = $conn->prepare("INSERT INTO company_requirements (user_id, requirement_type, file_name, status, moa_workflow_stage) VALUES (?, ?, ?, 'Pending', NULL)");
            if (!$stmt) {
                error_log('[CF REQ REPLACE] prepare(insert) failed for "' . $requirementType . '": ' . $conn->error);
                $allOk = false;
                continue;
            }

            // Blob bound directly as "s" (string), not "b" — same fix as
            // company_register.php's regSaveCompanyRequirements(): binding
            // "b" without a matching send_long_data() call silently saves
            // an EMPTY blob, which was the root cause of files appearing
            // to "not save" there.
            $stmt->bind_param("iss", $userId, $requirementType, $bytes);

            if ($stmt->execute()) {
                if ($stmt->affected_rows > 0) {
                    $insertedId = (int) $conn->insert_id;
                    $stmt->close();

                    // Same defensive blob-length verification as
                    // company_register.php.
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
                            error_log('[CF REQ REPLACE] BLOB LENGTH MISMATCH for "' . $requirementType . '" (row id ' . $insertedId . '): expected=' . $expectedLen . ' actual=' . $actualLen);
                            $allOk = false;
                        }
                    }
                } else {
                    error_log('[CF REQ REPLACE] INSERT for "' . $requirementType . '" reported 0 affected_rows');
                    $allOk = false;
                    $stmt->close();
                }
            } else {
                error_log('[CF REQ REPLACE] INSERT failed for "' . $requirementType . '": ' . $stmt->error);
                $allOk = false;
                $stmt->close();
            }
        } catch (\Throwable $e) {
            error_log('[CF REQ REPLACE] Exception while saving a file for "' . $requirementType . '": ' . $e->getMessage());
            $allOk = false;
        }
    }

    return $allOk;
}

/**
 * NEW (this adjustment) — company_validation.php now calls a requirement the admin
 * turned down "Rejected" (it used to write "Denied"), removes its file(s) and stores
 * the admin's remark, which is free text now (it used to be one of a fixed list).
 * Rows saved before that change still say "Denied" — both mean exactly the same
 * thing here, so every place below that used to test for 'Denied' calls this
 * instead and old and new rows behave identically.
 */
function cfIsRejectedStatus($status): bool
{
    return $status === 'Rejected' || $status === 'Denied';
}

/* ════════════════════════════════════════════════════════════════════════
   NEW (this adjustment) — MOA REVISION COMPLIANCE
   ──────────────────────────────────────────────────────────────────────
   Lets a company comply with a "Flag for Revision" the administrator sent
   from company_validation.php's New MOA table (moa_needs_revision /
   moa_flagged_fields / moa_revision_comment on company_requirements —
   see the "MOA REVISION COMPLIANCE" block further down for the on-page
   panel, and the submit_moa_revision POST handler below for the save
   flow). When the company fills in and submits the flagged field(s):
     1. Just those company_information column(s) are updated.
     2. The MOA PDF is immediately recreated from the now-complete data
        and saved back into company_requirements as the company's current
        Pending MOA — "returning" the freshly created MOA to them.
     3. The revision flag is cleared, so the row goes back to a plain
        "Pending for Review" state in the admin's New MOA table, ready
        for re-review.

   regBuildMOAStaticHTMLForDompdf() and regGenerateMoaPdfBytes() below are
   copied verbatim from company_register.php (same global namespace, no
   adjustment needed) — the exact same functions company_validation.php's
   own admin-side regeneration already uses, so a MOA rebuilt from either
   page looks identical.
   ════════════════════════════════════════════════════════════════════════ */

/**
 * Build a static, Dompdf-safe HTML version of a "Request New MOA"
 * document. This mirrors buildMOAStaticHTMLForDompdf() in moa_request.php
 * exactly (same letterhead, section structure, numbered lists, signing
 * block, witness block, acknowledgment, and repeating footer) so a MOA
 * generated/previewed at registration looks identical to one generated
 * later through the normal moa_request.php flow.
 *
 * (Copied verbatim from company_register.php's regBuildMOAStaticHTMLForDompdf()
 * — see the block comment above for why.)
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
 * Render regBuildMOAStaticHTMLForDompdf()'s HTML to PDF bytes via Dompdf,
 * probing a few common autoload locations first (mirrors
 * company_register.php's own resilient autoload search exactly). Returns
 * null (non-fatally) if Dompdf isn't available or rendering fails.
 *
 * (Copied verbatim from company_register.php's regGenerateMoaPdfBytes().)
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


/* ================= HELPER: RESOLVE THE MOA DOCUMENT ROW'S ACTUAL KEY =================
   Mirrors company_validation.php's own resolveMoaRequirementType() exactly
   (same IN ('moa_document','moa') + ORDER BY id DESC LIMIT 1 resolution),
   so this page and the admin panel always agree on which row is "the"
   current MOA document for a company. ================================ */
function cfResolveMoaRequirementType($conn, $user_id) {
    if (!$user_id) return 'moa_document';
    $q = $conn->prepare("SELECT requirement_type FROM company_requirements WHERE user_id=? AND requirement_type IN ('moa_document','moa') ORDER BY id DESC LIMIT 1");
    if (!$q) return 'moa_document';
    $q->bind_param("i", $user_id);
    $q->execute();
    $r = $q->get_result()->fetch_assoc();
    $q->close();
    return $r['requirement_type'] ?? 'moa_document';
}

/* ================= HELPER: SAVE A REGENERATED MOA BLOB =================
   Saves freshly-regenerated PDF bytes as the company's current Pending
   MOA document, without touching moa_workflow_stage (it's already
   'pending' — the merged "Pending for Review" stage — the whole time a
   revision is outstanding, so no change is needed there). Mirrors
   company_validation.php's own saveRegeneratedMoaBlob() exactly.
   ================================ */
function cfSaveRegeneratedMoaBlob($conn, $user_id, $pdfBytes) {
    if (empty($pdfBytes) || !$user_id) return false;

    $moaType = cfResolveMoaRequirementType($conn, $user_id);
    $chk = $conn->prepare("SELECT id FROM company_requirements WHERE user_id=? AND requirement_type=?");
    if (!$chk) return false;
    $chk->bind_param("is", $user_id, $moaType); $chk->execute();
    $exists = $chk->get_result()->fetch_assoc(); $chk->close();

    if ($exists) {
        $stmt = $conn->prepare("UPDATE company_requirements SET requirement_type='moa_document', file_name=?, status='Pending' WHERE user_id=? AND requirement_type=?");
        if (!$stmt) return false;
        $null_blob = null;
        $stmt->bind_param("bis", $null_blob, $user_id, $moaType);
        $stmt->send_long_data(0, $pdfBytes);
        $ok = $stmt->execute();
        $stmt->close();
    } else {
        $stmt = $conn->prepare("INSERT INTO company_requirements (user_id, requirement_type, file_name, status, moa_workflow_stage) VALUES (?, 'moa_document', ?, 'Pending', 'pending')");
        if (!$stmt) return false;
        $null_blob = null;
        $stmt->bind_param("ib", $user_id, $null_blob);
        $stmt->send_long_data(1, $pdfBytes);
        $ok = $stmt->execute();
        $stmt->close();
    }
    return (bool)$ok;
}

/* ================= HELPER: RECREATE & RETURN THE MOA (COMPANY-SIDE) =================
   Rebuilds the MOA PDF from this company's CURRENT company_information
   data (i.e. after the flagged field(s) have just been updated) and
   saves it back as their working MOA document. Non-fatal on failure
   (e.g. Dompdf unavailable) — logged via error_log(), same tolerance
   company_register.php's own STEP 2d already has for this exact
   scenario, so a Dompdf hiccup never blocks the rest of the revision
   submit (the company's updated info is still saved either way).
   ================================ */
function cfRegenerateAndSaveMoa($conn, $user_id) {
    if (!$user_id) return false;

    $ciq = $conn->prepare("SELECT company, company_profile, company_address, position, contact_first_name, contact_middle_initial, contact_last_name FROM company_information WHERE user_id=?");
    if (!$ciq) { error_log('[CF MOA REGEN] prepare failed: ' . $conn->error); return false; }
    $ciq->bind_param("i", $user_id); $ciq->execute();
    $ci = $ciq->get_result()->fetch_assoc(); $ciq->close();
    if (!$ci) { error_log('[CF MOA REGEN] no company_information row for user_id=' . $user_id); return false; }

    $repFullName = preg_replace('/\s+/', ' ', trim(
        ($ci['contact_first_name'] ?? '') . ' ' . ($ci['contact_middle_initial'] ?? '') . ' ' . ($ci['contact_last_name'] ?? '')
    ));

    try {
        $staticHtml = regBuildMOAStaticHTMLForDompdf([
            'moa_number'              => '',
            'moa_year'                => date('Y'),
            'company_name'            => $ci['company'] ?? '',
            'company_description'     => $ci['company_profile'] ?? '',
            'company_address'         => $ci['company_address'] ?? '',
            'representative_name'     => $repFullName,
            'representative_position' => $ci['position'] ?? '',
            'signing_date'            => '',
            'signing_place'           => '',
            'notary_city'             => '',
            'company_id'              => '',
        ]);

        $pdfBytes = regGenerateMoaPdfBytes($staticHtml);
        if ($pdfBytes === null || strlen($pdfBytes) === 0) {
            error_log('[CF MOA REGEN] PDF generation failed for user_id=' . $user_id);
            return false;
        }

        return cfSaveRegeneratedMoaBlob($conn, $user_id, $pdfBytes);
    } catch (\Throwable $e) {
        error_log('[CF MOA REGEN] Exception for user_id=' . $user_id . ': ' . $e->getMessage());
        return false;
    }
}


if (basename($_SERVER['PHP_SELF']) == 'CompanyForm.php') $pageTitle = "Company Requirements";
if (basename($_SERVER['PHP_SELF']) == 'add_ojt_student.php') $pageTitle = "OJT Student Management";
/* ================= GET USER ID FROM SESSION ================= */
if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit;
}
$user_id = $_SESSION['user_id'];

// Fetch pending late requests count (sidebar badge)
$pending_lr_count = 0;
$stmt_plr = $conn->prepare("SELECT COUNT(*) as total FROM late_requests WHERE company_id=? AND status='pending'");
$stmt_plr->bind_param("i", $user_id);
$stmt_plr->execute();
$res_plr = $stmt_plr->get_result()->fetch_assoc();
$pending_lr_count = $res_plr['total'] ?? 0;
$stmt_plr->close();
// ================= FETCH INBOX COUNT (ADDED ONLY) =================
$inbox_count = 0;
$stmt_inbox = $conn->prepare("SELECT COUNT(*) as total FROM ojt_applications WHERE company_id=? AND phase='pending'");
$stmt_inbox->bind_param("i", $user_id);
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
$stmt_ungraded->bind_param("i", $user_id);
$stmt_ungraded->execute();
$res_ungraded = $stmt_ungraded->get_result()->fetch_assoc();
$ungraded_count = $res_ungraded['total'] ?? 0;
$stmt_ungraded->close();
/* ================= GET USER EMAIL ================= */
$stmt_email = $conn->prepare("SELECT email FROM users WHERE id=?");
$stmt_email->bind_param("i", $user_id);
$stmt_email->execute();
$result_email = $stmt_email->get_result();
$row_email = $result_email->fetch_assoc();
$stmt_email->close();
$user_email = $row_email['email'] ?? '';

/* ================= GET USER FULL NAME ================= */
$stmt_name = $conn->prepare("SELECT first_name, middle_name, last_name FROM users WHERE id=?");
$stmt_name->bind_param("i", $user_id);
$stmt_name->execute();
$result_name = $stmt_name->get_result();
$row_name = $result_name->fetch_assoc();
$stmt_name->close();
$full_name = trim(($row_name['first_name'] ?? '') . ' ' . ($row_name['middle_name'] ?? '') . ' ' . ($row_name['last_name'] ?? ''));

/* ================= GET COMPANY INFO ================= */
$stmt = $conn->prepare("SELECT * FROM company_information WHERE user_id=?");
$stmt->bind_param("i", $user_id);
$stmt->execute();
$result = $stmt->get_result();
$row = $result->fetch_assoc();
$stmt->close();

/* ================= INSERT DEFAULT RECORD IF NONE EXISTS ================= */
if (!$row) {
    $stmt_type = $conn->prepare("SELECT company_type, first_name, middle_name, last_name FROM users WHERE id=?");
    $stmt_type->bind_param("i", $user_id);
    $stmt_type->execute();
    $result_type = $stmt_type->get_result();
    $row_type = $result_type->fetch_assoc();
    $stmt_type->close();

    $default_type = strtolower($row_type['company_type'] ?? 'private');

    $contact_first_name = $row_type['first_name'] ?? '';
    $contact_middle_initial = !empty($row_type['middle_name']) ? $row_type['middle_name'] : 'N/A';
    $contact_last_name = $row_type['last_name'] ?? '';

    $stmt = $conn->prepare("
        INSERT INTO company_information
        (user_id, company, company_address, telephone, contact_first_name, contact_middle_initial, contact_last_name, position, company_type)
        VALUES (?, '', '', '', ?, ?, ?, '', ?)
    ");
    $stmt->bind_param(
        "issss",
        $user_id,
        $contact_first_name,
        $contact_middle_initial,
        $contact_last_name,
        $default_type
    );
    $stmt->execute();
    $stmt->close();

    $stmt = $conn->prepare("SELECT * FROM company_information WHERE user_id=?");
    $stmt->bind_param("i", $user_id);
    $stmt->execute();
    $result = $stmt->get_result();
    $row = $result->fetch_assoc();
    $stmt->close();
}

/* ================= ASSIGN VARIABLES ================= */
$company         = $row['company']         ?? '';
$company_address = $row['company_address'] ?? '';
$telephone       = $row['telephone']       ?? '';
$position        = $row['position']        ?? '';
$current_type    = strtolower($row['company_type'] ?? 'private');

// NEW: Company Profile / Brief Description — the free-text description the
// company entered during registration (company_register.php's Step 2),
// stored in company_information.company_profile alongside the other
// company details already read above. This was previously never displayed
// anywhere on this page — only the SEPARATE "Company Profile" compliance
// DOCUMENT upload item (in $private_reqs further up) was shown, which is a
// different thing entirely (an uploaded file, not this text description).
$company_profile = $row['company_profile'] ?? '';

// Always fetch contact name AND company_type from users table — the authoritative source.
//
// FIX (company_type detection): company_information.company_type is only ever written
// ONCE, at the moment the company_information row is first created (see the
// "INSERT DEFAULT RECORD IF NONE EXISTS" block above, which just copies whatever
// users.company_type was at that time). If a company's classification is changed
// afterwards (e.g. by an administrator, updating users.company_type), this page used
// to keep showing the old, stale classification and the wrong Compliance Requirements
// checklist (Private vs Public) forever, since $current_type was only ever read from
// company_information. Pulling company_type fresh from users on every load — the same
// way the contact name below is already refreshed from users on every load — keeps
// $current_type (and therefore the checklist, banner text, etc.) accurate at all times.
//
// FIX (this update) — request_type is now ALSO read here, in this same query, since
// it's needed to correctly resolve $moa_request_type further below (see that block's
// docblock for the full explanation of the bug this fixes).
$stmt_uname = $conn->prepare("SELECT first_name, middle_name, last_name, company_type, request_type FROM users WHERE id=?");
$stmt_uname->bind_param("i", $user_id);
$stmt_uname->execute();
$row_uname = $stmt_uname->get_result()->fetch_assoc();
$stmt_uname->close();

$contact_first  = $row_uname['first_name'] ?? '';
$contact_middle = !empty($row_uname['middle_name']) ? $row_uname['middle_name'] : 'N/A';
$contact_last   = $row_uname['last_name']  ?? '';

// Prefer the authoritative users.company_type when it's set; otherwise fall back to
// the value already read from company_information above (so nothing breaks if, for
// some reason, users.company_type is empty/null).
if (!empty($row_uname['company_type'])) {
    $current_type = strtolower($row_uname['company_type']);
}

// FIX (this update): users.request_type — the fixed "New"/"Existing" MOA
// request type company_register.php's registration flow writes directly
// onto the users row (that flow never creates a moa_requests row at all) —
// is captured here for use by the $moa_request_type resolution block
// further below.
$users_request_type = $row_uname['request_type'] ?? null;

/* ================= COMPLIANCE REQUIREMENTS: DETERMINE ACTIVE SET =================
   Picks the private/public checklist based on this company's classification
   (the same $current_type already computed above).

   IMPORTANT (this update): this lookup is deliberately based ONLY on
   $current_type (Private vs Public). It must NEVER be filtered, sliced,
   or otherwise reduced based on $moa_request_type / $moa_request_type_norm
   / $showMoaSection (resolved further below) — those control the
   separate "MOA Document Status" section only. A Private company always
   gets the full 10-item $private_reqs checklist, and a Public company
   always gets the full 3-item $public_reqs checklist, whether their MOA
   request_type on file is "New" or "Existing".

   NOTE: further below, once $moa_request_type_norm is resolved, ONE
   additional MOA upload item (see $moa_existing_reqs above) is APPENDED
   onto the end of $reqDefsForType for "Existing" request-type companies
   only. That append is strictly additive — it never removes, reorders,
   or otherwise reduces any of the classification items assigned here. */
$reqDefsForType = $all_company_reqs[$current_type] ?? $private_reqs;

// ════════════════════════════════════════════════════════════════════════
//  FIX (earlier update) — "MOA fields not showing" for request_type = "New"
//  companies:
//
//  This used to look ONLY at the moa_requests table to figure out a
//  company's MOA request_type. But companies created through
//  company_register.php's registration flow never write a row into
//  moa_requests at all — that flow stores a fixed request_type value
//  directly on the users row instead (see $users_request_type above), and
//  auto-generates/stores the MOA PDF straight into company_requirements
//  (requirement_type = 'moa') rather than going through moa_requests. For
//  those companies the old moa_requests-only lookup always came back
//  empty, so $moa_request_type_norm was never "new" — which is exactly
//  why the "MOA Document Status" section stayed hidden ($showMoaSection
//  below).
//
//  The authoritative users.request_type value is now checked FIRST. The
//  moa_requests table lookup is kept as a fallback, used only when
//  users.request_type is empty — i.e. for companies that went through the
//  separate, post-login moa_request.php flow instead (which DOES use
//  moa_requests and does not set users.request_type), so that flow's
//  behavior here is completely unchanged.
// ════════════════════════════════════════════════════════════════════════
$moa_request_type = null;
if (!empty($users_request_type)) {
    $moa_request_type = $users_request_type;
} else {
    $res_mrq = @$conn->query("SELECT request_type FROM moa_requests WHERE user_id=" . (int)$user_id . " ORDER BY id DESC LIMIT 1");
    if ($res_mrq && $res_mrq->num_rows > 0) {
        $moa_request_type = $res_mrq->fetch_assoc()['request_type'] ?? null;
    }
}

// NOTE: comparisons against $moa_request_type are done case-insensitively
// (via strtolower) below, since the value stored may be saved as
// "New"/"Existing" (capitalized) depending on where it was written from,
// and we don't want the "new" vs "existing" checks below to silently fail
// to match just because of letter casing.
//
// FIX (this revision) — the MOA upload item was not appearing for
// "Existing" companies because the comparisons below used a STRICT
// equality check ($moa_request_type_norm === 'existing' / === 'new').
// A strict check silently fails (no error, item just never appears) if
// the stored value has leading/trailing whitespace (e.g. "Existing ")
// or is phrased slightly differently than the bare word (e.g.
// "Existing Partner", "Existing MOA", "Existing Company"). trim() now
// strips stray whitespace, and the "existing"/"new" checks further below
// use str_contains() instead of === so any stored value that CONTAINS
// the word "existing" (or "new") still matches, regardless of extra
// wording around it.
$moa_request_type_norm = strtolower(trim((string)$moa_request_type));

// ════════════════════════════════════════════════════════════════════════
//  NEW (this adjustment) — EMPTY-FIELD AUTO-UNLOCK for "Existing"
//  request-type companies (Public AND Private classifications alike)
//  ──────────────────────────────────────────────────────────────────────
//  A company added via admin_company_list.php's manual "Add Company" form
//  or XLSX import with Company Status = "Existing" can be created with
//  some of its profile fields (company / company_address / contact name /
//  position / telephone / company_profile) left blank — that flow does
//  not require every field the way company_register.php's own
//  registration form does. Previously every field on this page was
//  permanently readonly regardless of request_type, so a company in this
//  situation had no way to fill in whatever was missing.
//
//  $moa_is_existing_request is simply the same "existing" check already
//  used immediately below (for the additive MOA upload item) and for
//  $showMoaSection just below that, pulled out into its own named flag so
//  it can be reused, further down, by BOTH the profile-field rendering
//  (see $cfFieldLock() near the "locked" HTML branch) and its matching
//  submit_compliance_docs save logic — without duplicating or altering
//  the request_type parsing/normalization above in any way.
//
//  IMPORTANT: this is a PER-FIELD unlock — a field only ever unlocks
//  while it is still empty on file (checked again, individually, at both
//  render time and save time). A field that already has a value keeps
//  behaving exactly as it always has: readonly, grey background, no
//  change whatsoever. This is unrelated to, and does not alter, how
//  $moa_needs_initial_creation (the separate ALL-fields-unlocked-together
//  "MOA Initial Creation" flow, "New" request-type companies only,
//  defined further below) behaves.
// ════════════════════════════════════════════════════════════════════════
$moa_is_existing_request = ($moa_request_type_norm !== '' && str_contains($moa_request_type_norm, 'existing'));

// TEMP DIAGNOSTIC (safe to delete once confirmed working): the raw and
// normalized request_type values are emitted as an HTML comment near the
// top of <body> further below, and as a visible debug strip when this
// page is loaded with ?debug_moa=1. Use either to confirm exactly what
// value is being read for a company that should be "Existing" but isn't
// showing the MOA upload item — if the printed value doesn't contain the
// word "existing" at all, the value isn't being written/read from
// users.request_type (or moa_requests.request_type) the way this page
// expects, and the fix needs to target wherever that value actually
// comes from instead.
$moa_request_type_debug_raw = $moa_request_type;

// ════════════════════════════════════════════════════════════════════════
//  NEW — append the MOA requirement upload item (see $moa_existing_reqs
//  above) onto $reqDefsForType, ONLY for "Existing" request-type
//  companies, for BOTH classifications (Private and Public). This runs
//  AFTER $reqDefsForType is first assigned (purely from $current_type,
//  above) and AFTER $moa_request_type_norm is resolved, so it can only
//  ever ADD the one extra item onto the end of whichever full
//  classification checklist the company already has — it never removes,
//  reorders, or filters anything that was already there. "New"
//  request-type companies are unaffected: $reqDefsForType stays exactly
//  as computed above for them, and they continue to submit their MOA
//  document through the separate "MOA Document Status" workflow section
//  further down (gated by $showMoaSection) instead.
// ════════════════════════════════════════════════════════════════════════
if ($moa_request_type_norm !== '' && str_contains($moa_request_type_norm, 'existing')) {
    $reqDefsForType = $reqDefsForType + $moa_existing_reqs;
}

// ── ADJUSTMENT (this update): "Authority to Sign MOA" is no longer
// hidden for "Request New MOA" (request_type = "New") companies. It is
// now a required compliance document for BOTH classifications regardless
// of MOA request type, displayed and validated exactly like every other
// item in $reqDefsForType — no special-casing anywhere on this page
// anymore. (The previous $hideAuthorityMoaReq flag that skipped
// "authority_moa" / "authority_moa_public" for "New" companies has been
// removed entirely, both in the display loop further down and in the
// submit_compliance_docs POST handler below.)
//
// This same reasoning is why $reqDefsForType above is computed purely
// from $current_type: "New" and "Existing" companies of the same
// classification must see and be required to submit the exact same
// compliance checklist — there is no branch anywhere on this page that
// narrows $reqDefsForType (or the loop that renders it further down)
// based on $moa_request_type_norm. (The one exception is the additive
// MOA-upload append immediately above, which only ever ADDS an item for
// "Existing" companies — it does not narrow anything.)

// ── ADJUSTMENT: MOA Document Status section visibility ──
// The "MOA Document Status" block (workflow stepper, preview, etc.) is only
// relevant to companies that requested a brand-new MOA at registration.
// Companies with an "Existing" MOA request type don't have a fresh MOA
// document being drafted/reviewed through this workflow, so the section is
// hidden for them and shown only when request_type is "New".
//
// NOTE: this flag ONLY gates the separate "MOA Document Status" section
// below (see $showMoaSection usage further down in the HTML). It has no
// effect whatsoever on $reqDefsForType / the Compliance Requirements
// checklist above — that checklist (plus the additive MOA upload item
// for "Existing" companies) is always shown in full for both "New" and
// "Existing" companies.
$showMoaSection = ($moa_request_type_norm !== '' && str_contains($moa_request_type_norm, 'new'));

/* ================= CHECK IF COMPANY PROFILE EXISTS ================= */
$stmt_profile = $conn->prepare("SELECT id FROM company_profile WHERE user_id=?");
$stmt_profile->bind_param("i", $user_id);
$stmt_profile->execute();
$result_profile = $stmt_profile->get_result();
$row_profile = $result_profile->fetch_assoc();
$stmt_profile->close();

$show_profile_popup = !$row_profile; // true if no row exists

/* ================= STREAM OWN COMPLIANCE REQUIREMENT DOCUMENT (SCOPED TO LOGGED-IN COMPANY ONLY) =================
   Companion to STREAM OWN MOA DOCUMENT below, but for the
   classification-based compliance checklist items (company_profile,
   mayors_permit, authority_moa, etc). Scoped the same way: only the
   logged-in company's own row(s), and only for a requirement_type that
   belongs to the known private/public checklist.

   FIX (this update): now accepts an optional &file_id=<id> so a SPECIFIC
   file can be streamed when a requirement has more than one saved file
   (the "overlaying card" display further down needs this to page through
   every file in the stack). file_id is still cross-checked against
   user_id AND requirement_type, so a company can never stream another
   company's file, or a file that doesn't actually belong to the
   requirement key it claims to. Omitting file_id keeps the previous
   behavior (streams the first file for that requirement) for backward
   compatibility with any existing single-file usage.

   UPDATE (this revision): $allowedReqKeys now also includes the keys of
   $moa_existing_reqs (currently just "moa_existing_upload"), so the new
   MOA requirement-upload item for "Existing" companies can be streamed
   back and previewed the exact same way as every other compliance item. */
if (isset($_GET['stream_own_requirement'])) {
    $reqKeyParam = (string)$_GET['stream_own_requirement'];
    $allowedReqKeys = array_merge(array_keys($private_reqs), array_keys($public_reqs), array_keys($moa_existing_reqs));

    if (!in_array($reqKeyParam, $allowedReqKeys, true)) {
        http_response_code(404);
        echo "Invalid document.";
        exit;
    }

    $fileIdParam = isset($_GET['file_id']) ? (int) $_GET['file_id'] : 0;

    if ($fileIdParam > 0) {
        $stmt = $conn->prepare("SELECT file_name FROM company_requirements WHERE id=? AND user_id=? AND requirement_type=?");
        $stmt->bind_param("iis", $fileIdParam, $user_id, $reqKeyParam);
    } else {
        $stmt = $conn->prepare("SELECT file_name FROM company_requirements WHERE user_id=? AND requirement_type=? ORDER BY id ASC LIMIT 1");
        $stmt->bind_param("is", $user_id, $reqKeyParam);
    }
    $stmt->execute();
    $res_req_doc = $stmt->get_result();
    $row_req_doc = $res_req_doc->fetch_assoc();
    $stmt->close();

    if (empty($row_req_doc) || empty($row_req_doc['file_name'])) {
        http_response_code(404);
        echo "No document found.";
        exit;
    }

    $blob = $row_req_doc['file_name'];
    $finfo = new finfo(FILEINFO_MIME_TYPE);
    $mime = $finfo->buffer($blob);
    if (!$mime || $mime === 'application/octet-stream') $mime = 'application/pdf';

    header('Content-Type: ' . $mime);
    header('Content-Disposition: inline; filename="' . preg_replace('/[^a-zA-Z0-9_-]/', '_', $reqKeyParam) . '_' . $user_id . ($fileIdParam > 0 ? '_' . $fileIdParam : '') . '"');
    header('Content-Length: ' . strlen($blob));
    header('Cache-Control: private, max-age=300');
    echo $blob;
    exit;
}

/* ================= STREAM OWN MOA DOCUMENT (SCOPED TO LOGGED-IN COMPANY ONLY) ================= */
if (isset($_GET['stream_own_moa'])) {
    // FIX (this update): also match requirement_type = 'moa' — the key
    // company_register.php's registration flow uses when it auto-generates
    // and saves a brand-new MOA PDF straight into company_requirements for
    // a "Request New MOA" company (see the $moa_request_type fix note
    // above). The legacy 'moa_document' key, written by the separate
    // post-login moa_request.php acceptance flow, is still matched exactly
    // as before — ORDER BY id DESC LIMIT 1 simply picks whichever one of
    // the two actually exists for this company (in practice only one ever
    // will), so existing behavior for the moa_request.php flow is
    // completely unaffected.
    $stmt = $conn->prepare("SELECT file_name FROM company_requirements WHERE user_id=? AND requirement_type IN ('moa_document','moa') ORDER BY id DESC LIMIT 1");
    $stmt->bind_param("i", $user_id);
    $stmt->execute();
    $res_moa = $stmt->get_result();
    $row_moa = $res_moa->fetch_assoc();
    $stmt->close();

    if (empty($row_moa) || empty($row_moa['file_name'])) {
        http_response_code(404);
        echo "No MOA document found.";
        exit;
    }

    $blob = $row_moa['file_name'];
    $finfo = new finfo(FILEINFO_MIME_TYPE);
    $mime = $finfo->buffer($blob);
    if (!$mime || $mime === 'application/octet-stream') $mime = 'application/pdf';

    header('Content-Type: ' . $mime);
    header('Content-Disposition: inline; filename="MOA_Document_' . $user_id . '"');
    header('Content-Length: ' . strlen($blob));
    header('Cache-Control: private, max-age=300');
    echo $blob;
    exit;
}

/* ================= AJAX: SUBMIT MOA REVISION (COMPLY WITH A "FLAG FOR REVISION") =================
   NEW (this adjustment) — the save flow for the "MOA REVISION COMPLIANCE"
   panel shown further down when moa_needs_revision is true. Called via
   fetch() (not a native form submit — see the panel's JS), since it needs
   to sit inside the page's existing single big <form> without nesting a
   second <form> inside it.

   The flagged-field LIST is always re-read fresh from the DB here (never
   trusted from the client), so a company can only ever update exactly the
   field(s) the administrator actually flagged — nothing else. Every
   flagged field must be non-empty to proceed (partial compliance isn't
   allowed, mirroring the admin-side "flag at least one section" rule in
   spirit). On success: the field(s) are saved, the MOA is regenerated and
   returned via cfRegenerateAndSaveMoa(), and the revision flag is cleared
   — the row simply resumes as a plain "Pending for Review" MOA in the
   admin's New MOA table, ready for re-review. Regeneration failure is
   non-fatal (matches company_register.php's own tolerance for a Dompdf
   hiccup) — the company's corrected info is saved either way, and the
   response says so. ================================ */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['submit_moa_revision'])) {
    header('Content-Type: application/json');

    $conn->query("ALTER TABLE company_requirements ADD COLUMN IF NOT EXISTS moa_needs_revision TINYINT(1) NOT NULL DEFAULT 0");
    $conn->query("ALTER TABLE company_requirements ADD COLUMN IF NOT EXISTS moa_flagged_fields TEXT NULL");
    $conn->query("ALTER TABLE company_requirements ADD COLUMN IF NOT EXISTS moa_revision_comment TEXT NULL");
    $conn->query("ALTER TABLE company_information ADD COLUMN IF NOT EXISTS company_profile TEXT NULL AFTER company_address");

    $moaTypeNow = cfResolveMoaRequirementType($conn, $user_id);
    $chk = $conn->prepare("SELECT moa_needs_revision, moa_flagged_fields FROM company_requirements WHERE user_id=? AND requirement_type=?");
    $chk->bind_param("is", $user_id, $moaTypeNow); $chk->execute();
    $chkRow = $chk->get_result()->fetch_assoc(); $chk->close();

    if (!$chkRow || empty($chkRow['moa_needs_revision'])) {
        echo json_encode(['success' => false, 'message' => 'No MOA revision is currently pending for your account.']);
        exit;
    }

    $flaggedFieldsList = [];
    if (!empty($chkRow['moa_flagged_fields'])) {
        $decoded = json_decode($chkRow['moa_flagged_fields'], true);
        if (is_array($decoded)) $flaggedFieldsList = $decoded;
    }

    // Same key => company_information column map used to build the panel
    // further down. "moa_document" (flagging the uploaded file itself) has
    // no text-field entry here and is simply skipped — that scenario is
    // outside the scope of this text-field revision panel.
    $fieldColumnMap = [
        'company_name'        => 'company',
        'company_profile'     => 'company_profile',
        'company_address'     => 'company_address',
        'position'            => 'position',
        'contact_first_name'  => 'contact_first_name',
        'contact_middle_name' => 'contact_middle_initial',
        'contact_last_name'   => 'contact_last_name',
        'telephone'           => 'telephone',
    ];

    $updates = [];   // column => new value
    $missing = [];   // flag keys the company left blank
    foreach ($flaggedFieldsList as $flagKey) {
        $col = $fieldColumnMap[$flagKey] ?? null;
        if (!$col) continue;
        $val = trim((string)($_POST['moa_rev_' . $flagKey] ?? ''));
        if ($val === '') { $missing[] = $flagKey; continue; }
        $updates[$col] = $val;
    }

    if (!empty($missing)) {
        echo json_encode(['success' => false, 'message' => 'Please fill in every flagged field before submitting.']);
        exit;
    }
    if (empty($updates)) {
        echo json_encode(['success' => false, 'message' => 'Nothing to update.']);
        exit;
    }

    foreach ($updates as $col => $val) {
        $stmt = $conn->prepare("UPDATE company_information SET `$col`=? WHERE user_id=?");
        if ($stmt) {
            $stmt->bind_param("si", $val, $user_id);
            $stmt->execute();
            $stmt->close();
        }
    }

    // Recreate the MOA from the now-updated data and return it as the
    // company's current Pending document.
    $regenerated = cfRegenerateAndSaveMoa($conn, $user_id);

    // Clear the revision flag regardless of whether regeneration
    // succeeded — the corrected info is saved either way, and the row
    // should resume as a plain "Pending for Review" MOA either way
    // (mirrors company_register.php's own non-fatal Dompdf tolerance).
    //
    // ── NEW (this adjustment): also marks moa_pending_admin_notice=
    // 'revision_complied' — company_validation.php's own admin-side
    // notification detection (see detectAndNotifyComplianceEvents() there)
    // picks this up on its next pass and surfaces it in the MOA Requests
    // inbox as a "Revision Complied" notification, then clears this
    // marker again, exactly the same handoff pattern that page's other
    // auto-detection functions already use.
    $moaTypeAfter = cfResolveMoaRequirementType($conn, $user_id);
    $conn->query("ALTER TABLE company_requirements ADD COLUMN IF NOT EXISTS moa_pending_admin_notice VARCHAR(30) NULL");
    $clearStmt = $conn->prepare("UPDATE company_requirements SET requirement_type='moa_document', moa_needs_revision=0, moa_flagged_fields=NULL, moa_revision_comment=NULL, status='Pending', moa_pending_admin_notice='revision_complied' WHERE user_id=? AND requirement_type=?");
    if ($clearStmt) {
        $clearStmt->bind_param("is", $user_id, $moaTypeAfter);
        $clearStmt->execute();
        $clearStmt->close();
    }

    echo json_encode([
        'success'      => true,
        'regenerated'  => $regenerated,
        'message'      => $regenerated
            ? 'Thank you! Your MOA has been updated and resubmitted for review.'
            : 'Your information was saved and resubmitted for review, but the MOA document could not be automatically regenerated — the administrator will follow up if anything further is needed.'
    ]);
    exit;
}

/* ================= AJAX: RESPOND TO A SIGNING SCHEDULE (AGREE / DECLINE) =================
   NEW (this adjustment) — the company representative's side of the
   "SIGNING SCHEDULE CONFIRMATION" flow. When the administrator sets or
   resets a signing schedule on company_validation.php, it comes here as
   moa_schedule_status='pending_confirmation' — this page shows it and the
   representative must explicitly respond before it's treated as final:
     - action=agree: locks it in as moa_schedule_status='confirmed'. Shown
       to the admin as a plain "confirmed" note on their side, no further
       action needed from either side.
     - action=decline: requires a reason AND an alternative date/time
       (never in the past — enforced the same way as the admin's own
       calendar picker), saved as moa_schedule_status='declined' plus
       moa_schedule_decline_reason / moa_proposed_datetime. The admin then
       sees the reason and can adopt the proposed date/time in one click
       (see company_validation.php's "Accept Proposed Schedule" action) or
       set an entirely different schedule instead (which resets this back
       to 'pending_confirmation' for another round).
   Re-verifies server-side that a schedule is actually awaiting a response
   before accepting either action — never trusts the page state alone,
   since the admin could have (re)scheduled again in the meantime.
   ================================ */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['submit_moa_schedule_response'])) {
    header('Content-Type: application/json');

    $conn->query("ALTER TABLE company_requirements ADD COLUMN IF NOT EXISTS moa_schedule_status VARCHAR(20) NULL");
    $conn->query("ALTER TABLE company_requirements ADD COLUMN IF NOT EXISTS moa_schedule_decline_reason TEXT NULL");
    $conn->query("ALTER TABLE company_requirements ADD COLUMN IF NOT EXISTS moa_proposed_datetime DATETIME NULL");
    // ── NEW (this adjustment): moa_pending_admin_notice marks this row for
    // company_validation.php's own admin-side notification detection (see
    // detectAndNotifyComplianceEvents() there) to pick up on its next pass
    // — surfaced in the MOA Requests inbox as a "Schedule Agreed" or
    // "Schedule Change Proposed" notification depending on which action
    // this was, then cleared again automatically. Same handoff pattern
    // that page's other auto-detection functions already use.
    $conn->query("ALTER TABLE company_requirements ADD COLUMN IF NOT EXISTS moa_pending_admin_notice VARCHAR(30) NULL");

    $action = trim($_POST['action'] ?? '');
    $moaType = cfResolveMoaRequirementType($conn, $user_id);

    $chk = $conn->prepare("SELECT moa_workflow_stage, status FROM company_requirements WHERE user_id=? AND requirement_type=?");
    $chk->bind_param("is", $user_id, $moaType); $chk->execute();
    $chkRow = $chk->get_result()->fetch_assoc(); $chk->close();

    if (!$chkRow || ($chkRow['moa_workflow_stage'] ?? '') !== 'scheduled' || ($chkRow['status'] ?? '') === 'Verified') {
        echo json_encode(['success' => false, 'message' => 'There is no signing schedule currently awaiting your response.']);
        exit;
    }

    if ($action === 'agree') {
        $upd = $conn->prepare("UPDATE company_requirements SET moa_schedule_status='confirmed', moa_schedule_decline_reason=NULL, moa_proposed_datetime=NULL, moa_pending_admin_notice='schedule_agreed' WHERE user_id=? AND requirement_type=?");
        $upd->bind_param("is", $user_id, $moaType); $upd->execute(); $upd->close();
        echo json_encode(['success' => true, 'status' => 'confirmed', 'message' => 'Thank you! Your confirmation has been recorded.']);
        exit;
    }

    if ($action === 'decline') {
        $reason      = trim($_POST['reason'] ?? '');
        $proposeDate = trim($_POST['propose_date'] ?? '');
        $proposeTime = trim($_POST['propose_time'] ?? '');

        if ($reason === '') {
            echo json_encode(['success' => false, 'message' => "Please tell us why you're not available at this time."]);
            exit;
        }
        if ($proposeDate === '' || $proposeTime === '') {
            echo json_encode(['success' => false, 'message' => 'Please propose an alternative date and time.']);
            exit;
        }

        $proposedDateTime = $proposeDate . ' ' . $proposeTime . ':00';
        if (!strtotime($proposedDateTime)) {
            echo json_encode(['success' => false, 'message' => 'Invalid proposed date/time.']);
            exit;
        }
        // The proposed date can't be in the past either — mirrors the
        // admin's own calendar picker rule.
        if (strtotime($proposedDateTime) < strtotime(date('Y-m-d 00:00:00'))) {
            echo json_encode(['success' => false, 'message' => 'Please propose a date that is not in the past.']);
            exit;
        }

        $upd = $conn->prepare("UPDATE company_requirements SET moa_schedule_status='declined', moa_schedule_decline_reason=?, moa_proposed_datetime=?, moa_pending_admin_notice='schedule_declined' WHERE user_id=? AND requirement_type=?");
        $upd->bind_param("ssis", $reason, $proposedDateTime, $user_id, $moaType); $upd->execute(); $upd->close();
        echo json_encode(['success' => true, 'status' => 'declined', 'message' => 'Your reason and proposed schedule have been sent to the administrator for review.']);
        exit;
    }

    echo json_encode(['success' => false, 'message' => 'Invalid request.']);
    exit;
}

/* ================= AJAX: SUBMIT MOA INITIAL CREATION =================
   NEW (this adjustment) — the save flow for the "Create MOA" action shown
   when $moa_needs_initial_creation is true (a company added via
   admin_company_list.php's manual "Add Company" form or XLSX import,
   whose MOA was never auto-generated — see that flag's docblock above
   $moaRevisionFieldMeta for the full explanation). Saves every field the
   company just filled in to company_information, then generates and
   saves their first MOA via cfRegenerateAndSaveMoa() — the exact same
   function the MOA Revision Compliance flow above already uses.

   Re-validates server-side that this company is actually eligible
   (request_type "New" AND no MOA document yet) before doing anything, so
   this can never be used to silently rewrite an existing company's
   locked profile — the fields it's allowed to touch only unlock in the
   first place under that same condition. Every field is required (unlike
   the revision panel, this is a first-time fill-in, not a partial
   correction) except Contact Middle Name, matching the "N/A" convention
   already used elsewhere on this page for a company with no middle
   name. ================================ */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['submit_moa_creation'])) {
    header('Content-Type: application/json');

    $conn->query("ALTER TABLE company_information ADD COLUMN IF NOT EXISTS company_profile TEXT NULL AFTER company_address");

    // Re-check eligibility fresh, server-side — mirrors $moa_needs_initial_creation.
    $existingMoaType = cfResolveMoaRequirementType($conn, $user_id);
    $existingChk = $conn->prepare("SELECT file_name FROM company_requirements WHERE user_id=? AND requirement_type=?");
    $existingChk->bind_param("is", $user_id, $existingMoaType); $existingChk->execute();
    $existingRow = $existingChk->get_result()->fetch_assoc(); $existingChk->close();

    if (!empty($existingRow['file_name'])) {
        echo json_encode(['success' => false, 'message' => 'An MOA document already exists for your account.']);
        exit;
    }
    if (!$showMoaSection) {
        echo json_encode(['success' => false, 'message' => 'MOA creation is not available for your account type.']);
        exit;
    }

    $companyVal       = trim((string)($_POST['company'] ?? ''));
    $companyAddrVal   = trim((string)($_POST['company_address'] ?? ''));
    $contactFirstVal  = trim((string)($_POST['contact_first_name'] ?? ''));
    $contactLastVal   = trim((string)($_POST['contact_last_name'] ?? ''));
    $contactMiddleVal = trim((string)($_POST['contact_middle_initial'] ?? ''));
    $positionVal      = trim((string)($_POST['position'] ?? ''));
    $telephoneVal     = trim((string)($_POST['telephone'] ?? ''));
    $companyProfileVal = trim((string)($_POST['company_profile'] ?? ''));

    if ($contactMiddleVal === '') $contactMiddleVal = 'N/A';

    if ($companyVal === '' || $companyAddrVal === '' || $contactFirstVal === '' || $contactLastVal === ''
        || $positionVal === '' || $telephoneVal === '' || $companyProfileVal === '') {
        echo json_encode(['success' => false, 'message' => 'Please fill in every field before creating your MOA.']);
        exit;
    }

    $updStmt = $conn->prepare("UPDATE company_information
        SET company=?, company_address=?, contact_first_name=?, contact_last_name=?,
            contact_middle_initial=?, position=?, telephone=?, company_profile=?
        WHERE user_id=?");
    if (!$updStmt) {
        echo json_encode(['success' => false, 'message' => 'Database error. Please try again.']);
        exit;
    }
    $updStmt->bind_param(
        "ssssssssi",
        $companyVal, $companyAddrVal, $contactFirstVal, $contactLastVal,
        $contactMiddleVal, $positionVal, $telephoneVal, $companyProfileVal, $user_id
    );
    $updStmt->execute();
    $updStmt->close();

    $created = cfRegenerateAndSaveMoa($conn, $user_id);

    if (!$created) {
        echo json_encode([
            'success' => true,
            'created' => false,
            'message' => 'Your information was saved, but the MOA document could not be automatically generated. Please try again in a moment, or contact the administrator if this continues.'
        ]);
        exit;
    }

    echo json_encode([
        'success' => true,
        'created' => true,
        'message' => 'Your MOA has been created and submitted for review.'
    ]);
    exit;
}

/* ADJUSTMENT (action loading page): when the files are bigger than the server's post_max_size, PHP drops the
   whole request (empty $_POST and $_FILES) and the page would just come back unchanged. For the background
   (AJAX) submit, say so in a JSON answer instead of letting the loading screen end without a result. */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && empty($_POST) && empty($_FILES)
    && (int) ($_SERVER['CONTENT_LENGTH'] ?? 0) > 0
    && isset($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest') {
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'errors' => ['The selected files are larger than the server allows in one submission (limit ' . ini_get('post_max_size') . '). Please select fewer or smaller files and try again.']]);
    exit;
}

/* ================= SUBMIT / RESUBMIT COMPLIANCE REQUIREMENT DOCUMENTS =================
   NEW — integrates the classification-based compliance checklist from
   company_register.php's Step 3 ("Classification & Docs") into this page.
   A company can upload any missing item, or replace/resubmit an item that
   was Denied, using the exact same requirement keys, labels, and upload
   rules as company_register.php, so requirement_type values line up with
   what the admin panel already expects. Items left blank on this submit
   are simply left untouched (no accidental overwrite/removal).

   UPDATE (this revision): since $reqDefsForType already includes the
   additive "moa_existing_upload" item for "Existing" request-type
   companies (see above), this loop automatically validates and saves
   that item too — no separate handler needed. */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['submit_compliance_docs'])) {
    $uploadErrors = [];
    $filesToSave  = [];

    // ADJUSTMENT (action loading page): the page sends this form in the background (XMLHttpRequest) so its
    // loading screen can show progress and name what was updated. Those requests get a JSON answer; a normal
    // browser post keeps the redirects below exactly as before.
    $cfAjax = isset($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest';
    if ($cfAjax) {
        header('Content-Type: application/json');
        set_exception_handler(function ($e) {
            error_log('CompanyForm submit_compliance_docs: ' . $e->getMessage());
            echo json_encode(['success' => false, 'errors' => ['The server could not save your changes right now. Please try again in a moment.']]);
            exit;
        });
    }
    $cfInfoBefore = cfSnapshotInfo($conn, (int) $user_id);

    // ── ADJUSTMENT (this update): save the unlocked profile fields for
    // "Existing" request-type companies. Only runs for those companies
    // ($moa_is_existing_request, re-derived server-side above — never
    // trusted from the client), only for fields actually posted, and a
    // posted blank never wipes a value already on file (Contact Middle
    // Name falls back to the page's usual "N/A" convention). Contact name
    // is also mirrored onto the users row, since that is the source this
    // page reads the contact name from on every load.
    $cfProfileSaved = false;
    if ($moa_is_existing_request) {
        $conn->query("ALTER TABLE company_information ADD COLUMN IF NOT EXISTS company_profile TEXT NULL AFTER company_address");

        $cfEditableCols = [
            'company'                => 'company',
            'company_address'        => 'company_address',
            'contact_first_name'     => 'contact_first_name',
            'contact_middle_initial' => 'contact_middle_initial',
            'contact_last_name'      => 'contact_last_name',
            'position'               => 'position',
            'telephone'              => 'telephone',
            'company_profile'        => 'company_profile',
        ];
        $cfSetParts = [];
        $cfSetVals  = [];
        foreach ($cfEditableCols as $postKey => $col) {
            if (!isset($_POST[$postKey])) continue;
            $val = trim((string) $_POST[$postKey]);
            if ($postKey === 'contact_middle_initial' && $val === '') $val = 'N/A';
            if ($val === '') continue;
            $cfSetParts[] = "`$col`=?";
            $cfSetVals[]  = $val;
        }
        if (!empty($cfSetParts)) {
            $cfUpd = $conn->prepare("UPDATE company_information SET " . implode(', ', $cfSetParts) . " WHERE user_id=?");
            if ($cfUpd) {
                $cfTypes  = str_repeat('s', count($cfSetVals)) . 'i';
                $cfParams = array_merge($cfSetVals, [$user_id]);
                $cfUpd->bind_param($cfTypes, ...$cfParams);
                if ($cfUpd->execute()) $cfProfileSaved = true;
                $cfUpd->close();
            }
        }

        $cfUserMap = ['contact_first_name' => 'first_name', 'contact_middle_initial' => 'middle_name', 'contact_last_name' => 'last_name'];
        $cfUserParts = [];
        $cfUserVals  = [];
        foreach ($cfUserMap as $postKey => $col) {
            if (!isset($_POST[$postKey])) continue;
            $val = trim((string) $_POST[$postKey]);
            if ($postKey === 'contact_middle_initial') {
                if (strcasecmp($val, 'N/A') === 0) $val = '';
            } elseif ($val === '') {
                continue;
            }
            $cfUserParts[] = "`$col`=?";
            $cfUserVals[]  = $val;
        }
        if (!empty($cfUserParts)) {
            $cfUpdU = $conn->prepare("UPDATE users SET " . implode(', ', $cfUserParts) . " WHERE id=?");
            if ($cfUpdU) {
                $cfTypesU  = str_repeat('s', count($cfUserVals)) . 'i';
                $cfParamsU = array_merge($cfUserVals, [$user_id]);
                $cfUpdU->bind_param($cfTypesU, ...$cfParamsU);
                if ($cfUpdU->execute()) $cfProfileSaved = true;
                $cfUpdU->close();
            }
        }
    } else {
        // ── NEW (this adjustment): fields that are NOT printed on the MOA
        // (Telephone / Contact Number and Company Profile / Brief Description)
        // are editable for every company once their MOA exists — see
        // $cfNonMoaFieldLock in the form below. Only these two columns are
        // ever written here; every field that IS on the MOA (company name,
        // address, contact name, position) stays locked and is never read
        // from this post. Same rules as the "Existing" branch above: only
        // fields actually posted, and a posted blank never wipes a value
        // already on file.
        $conn->query("ALTER TABLE company_information ADD COLUMN IF NOT EXISTS company_profile TEXT NULL AFTER company_address");
        $cfNonMoaCols = ['telephone' => 'telephone', 'company_profile' => 'company_profile'];
        $cfNmParts = [];
        $cfNmVals  = [];
        foreach ($cfNonMoaCols as $postKey => $col) {
            if (!isset($_POST[$postKey])) continue;
            $val = trim((string) $_POST[$postKey]);
            if ($val === '') continue;
            $cfNmParts[] = "`$col`=?";
            $cfNmVals[]  = $val;
        }
        if (!empty($cfNmParts)) {
            $cfNmUpd = $conn->prepare("UPDATE company_information SET " . implode(', ', $cfNmParts) . " WHERE user_id=?");
            if ($cfNmUpd) {
                $cfNmTypes  = str_repeat('s', count($cfNmVals)) . 'i';
                $cfNmParams = array_merge($cfNmVals, [$user_id]);
                $cfNmUpd->bind_param($cfNmTypes, ...$cfNmParams);
                if ($cfNmUpd->execute()) $cfProfileSaved = true;
                $cfNmUpd->close();
            }
        }
    }

    // FIX (this update): uses cfValidateRequirementUploadsMulti() — the
    // compliance file inputs now accept multiple files per requirement
    // (name="req_<key>[]"), matching company_register.php's Step 3
    // behavior, instead of the old single-file-only input.
    //
    // ADJUSTMENT (this update): "Authority to Sign MOA" is no longer
    // skipped here for "Request New MOA" companies — it is now validated
    // and saved for every requirement key in $reqDefsForType, exactly like
    // every other document, regardless of MOA request type. $reqDefsForType
    // itself is always the FULL classification checklist (see its
    // computation above), so this loop always walks every required item.
    foreach ($reqDefsForType as $reqKey => $reqLabel) {
        $validation = cfValidateRequirementUploadsMulti('req_' . $reqKey, $reqLabel, $reqMaxFileSizeMB, $reqAllowedMimes);
        if (!$validation['ok']) {
            $uploadErrors[] = $validation['error'];
            continue;
        }
        if (!empty($validation['bytes'])) {
            $filesToSave[$reqKey] = $validation['bytes'];
        }
    }

    $cfInfoAreas = $cfProfileSaved ? cfDiffInfoAreas($cfInfoBefore, cfSnapshotInfo($conn, (int) $user_id)) : [];

    if (!empty($uploadErrors)) {
        if ($cfAjax) {
            // information already saved above stays saved; tell the page which areas, so nothing is silently lost
            echo json_encode(['success' => false, 'errors' => array_values($uploadErrors), 'areas' => $cfInfoAreas]);
            exit;
        }
        $_SESSION['compliance_upload_errors'] = $uploadErrors;
        header("Location: CompanyForm.php?msg=upload_error");
        exit;
    }

    if (!empty($filesToSave)) {
        // FIX (this update): uses cfReplaceCompanyRequirementFiles() —
        // replaces the entire saved set for a requirement (delete-then-
        // reinsert-each-file) instead of the old single-row upsert, so a
        // resubmission with multiple files ends up with exactly that many
        // rows (no orphaned old rows, no silent single-file cap).
        $cfUploaded = [];
        foreach ($filesToSave as $reqKey => $bytesList) {
            cfReplaceCompanyRequirementFiles($conn, $user_id, $reqKey, $bytesList);
            $cfUploaded[] = ['label' => (string) ($reqDefsForType[$reqKey] ?? $reqKey), 'count' => is_array($bytesList) ? count($bytesList) : 1];
        }
        if ($cfAjax) {
            echo json_encode(['success' => true, 'areas' => $cfInfoAreas, 'uploaded' => $cfUploaded]);
            exit;
        }
        header("Location: CompanyForm.php?msg=submitted");
        exit;
    }

    // ADJUSTMENT (this update): no files, but an "Existing" company saved
    // its unlocked profile fields — show the normal success modal.
    if ($cfProfileSaved) {
        if ($cfAjax) {
            echo json_encode(['success' => true, 'areas' => $cfInfoAreas, 'uploaded' => []]);
            exit;
        }
        header("Location: CompanyForm.php?msg=submitted");
        exit;
    }

    // Nothing was selected at all — just reload quietly.
    if ($cfAjax) {
        echo json_encode(['success' => true, 'areas' => [], 'uploaded' => []]);
        exit;
    }
    header("Location: CompanyForm.php");
    exit;
}

/* ================= SAVE COMPANY PROFILE ================= */
if (isset($_POST['email']) && isset($_POST['facebook_link'])) {
    header('Content-Type: application/json');
    $email = $_POST['email'] ?? '';
    $telephone = $_POST['telephone'] ?? '';
    $facebook = $_POST['facebook_link'] ?? '';
    $map = $_POST['google_map_link'] ?? '';

    if (!filter_var($facebook, FILTER_VALIDATE_URL) || !filter_var($map, FILTER_VALIDATE_URL)) {
        echo json_encode(["status"=>"error"]);
        exit;
    }

    $stmt = $conn->prepare("
        INSERT INTO company_profile
        (user_id,email,telephone,facebook_link,google_map_link)
        VALUES (?,?,?,?,?)
    ");
    $stmt->bind_param("issss", $user_id, $email, $telephone, $facebook, $map);
    $stmt->execute();
    $stmt->close();
    echo json_encode(["status"=>"success"]);
    exit;
}

/* ================= FETCH MOA DOCUMENT REQUIREMENT (ONLY REQUIREMENT TRACKED NOW) ================= */
$moa_file     = null;
$moa_status   = 'Pending';
$moa_remark   = '';
$moa_stage    = 'pending';
$moa_schedule = null;
// ── NEW (this adjustment): lets the company see + comply with a
// "Flag for Revision" the administrator sent from company_validation.php
// (see moa_needs_revision / moa_flagged_fields / moa_revision_comment on
// company_requirements). See the "MOA REVISION COMPLIANCE" block further
// down for how these three drive the on-page revision panel.
$moa_needs_revision  = false;
$moa_flagged_fields  = [];
$moa_revision_comment = '';
// ── NEW (this adjustment): lets the company respond to a signing
// schedule the administrator set — see the "SIGNING SCHEDULE
// CONFIRMATION" block further down for the full agree/decline flow.
// ── UPDATED (this adjustment): added 'confirmed_by_admin' — distinct
// from 'confirmed' (the company clicking "I Agree" themselves); this one
// means the ADMIN accepted the company's own counter-proposal after a
// decline, so it's shown with different, accurately-credited wording.
$moa_schedule_status  = null;   // null/'pending_confirmation' | 'confirmed' | 'confirmed_by_admin' | 'declined'
$moa_schedule_decline_reason = '';
$moa_proposed_datetime = null;

// FIX (this update): match requirement_type IN ('moa_document','moa') —
// see the fix note above STREAM OWN MOA DOCUMENT for the full explanation.
// 'moa_document' is the legacy key written by the post-login
// moa_request.php acceptance flow; 'moa' is the key company_register.php's
// registration flow uses when it auto-generates a brand-new MOA PDF for a
// "Request New MOA" company. ORDER BY id DESC LIMIT 1 simply picks
// whichever one actually exists for this company.
//
// ── UPDATED (this adjustment): also guards + selects moa_needs_revision /
// moa_flagged_fields / moa_revision_comment — the same columns
// company_validation.php's "Flag for Revision" action (in the admin's New
// MOA table) writes when it flags this company's MOA — and now
// moa_schedule_status / moa_schedule_decline_reason / moa_proposed_datetime
// too, which that same admin page writes whenever it (re)sets a signing
// schedule or accepts a proposed alternative.
$conn->query("ALTER TABLE company_requirements ADD COLUMN IF NOT EXISTS moa_needs_revision TINYINT(1) NOT NULL DEFAULT 0");
$conn->query("ALTER TABLE company_requirements ADD COLUMN IF NOT EXISTS moa_flagged_fields TEXT NULL");
$conn->query("ALTER TABLE company_requirements ADD COLUMN IF NOT EXISTS moa_revision_comment TEXT NULL");
$conn->query("ALTER TABLE company_requirements ADD COLUMN IF NOT EXISTS moa_schedule_status VARCHAR(20) NULL");
$conn->query("ALTER TABLE company_requirements ADD COLUMN IF NOT EXISTS moa_schedule_decline_reason TEXT NULL");
$conn->query("ALTER TABLE company_requirements ADD COLUMN IF NOT EXISTS moa_proposed_datetime DATETIME NULL");

// ADJUSTMENT (this update): clears the stray 'pending' moa_workflow_stage that
// earlier compliance-requirement uploads picked up from the column DEFAULT.
// Scoped to THIS logged-in company only, and never touches the MOA document
// row itself (moa_document / moa), whose workflow stage is left exactly as is.
$cfStageCleanup = $conn->prepare("UPDATE company_requirements SET moa_workflow_stage=NULL WHERE user_id=? AND requirement_type NOT IN ('moa_document','moa') AND moa_workflow_stage IS NOT NULL");
if ($cfStageCleanup) {
    $cfStageCleanup->bind_param("i", $user_id);
    $cfStageCleanup->execute();
    $cfStageCleanup->close();
}

/* ═══════════════════════════════════════════════════════════════════════
   NEW (this adjustment) — LIVE UPDATES (no manual reload)
   ───────────────────────────────────────────────────────────────────────
   The MOA Document Status card, the Compliance Requirements cards and the
   sidebar lock now refresh themselves in place when the administrator
   changes something (verifies / rejects / flags a document, schedules a
   signing, etc.), so the company never has to reload the page.

   How it works:
     • cfLiveSnapshotFingerprint() — ONE small hash of the raw data that
       drives those sections (requirement + MOA rows WITHOUT their file
       blobs — only LENGTH(file_name) — plus the users / company_information
       fields and the latest moa_requests row). It is cheap, so the browser
       can ask for it every few seconds.
     • GET CompanyForm.php?cf_live_poll=1 returns that hash as JSON. This
       runs here on purpose — after the column-creating ALTERs above and
       BEFORE the heavy blob queries below — so a poll never loads any file.
     • The hash is also embedded in the page (#cfLiveState) as the baseline.
       When the polled hash differs, the browser re-fetches this page,
       compares each region's data-live-sig with the live DOM, and swaps
       ONLY the regions that actually changed (see the "LIVE UPDATES"
       script at the bottom of the file).
   Nothing is written to the database by any of this. */
function cfLiveRowsSig(array $rows): string
{
    $sig = [];
    foreach ($rows as $r) {
        $sig[] = [
            (int) ($r['id'] ?? 0),
            (string) ($r['status'] ?? ''),
            (string) ($r['remark'] ?? ''),
            !empty($r['file_name']) ? strlen((string) $r['file_name']) : 0,
        ];
    }
    return md5(json_encode($sig));
}

function cfLiveSnapshotFingerprint(mysqli $conn, int $uid): string
{
    $snap = ['req' => [], 'user' => null, 'info' => null, 'mreq' => null];

    $q = $conn->prepare("SELECT id, requirement_type, status, remark, LENGTH(file_name) AS flen,
            moa_workflow_stage, moa_admin_comment, moa_schedule_datetime, moa_needs_revision,
            moa_flagged_fields, moa_revision_comment, moa_schedule_status,
            moa_schedule_decline_reason, moa_proposed_datetime
        FROM company_requirements WHERE user_id=? ORDER BY id ASC");
    if ($q) {
        $q->bind_param("i", $uid);
        $q->execute();
        $res = $q->get_result();
        while ($r = $res->fetch_assoc()) { $snap['req'][] = $r; }
        $q->close();
    }

    $q = $conn->prepare("SELECT first_name, middle_name, last_name, company_type, request_type FROM users WHERE id=?");
    if ($q) {
        $q->bind_param("i", $uid);
        $q->execute();
        $snap['user'] = $q->get_result()->fetch_assoc();
        $q->close();
    }

    $q = $conn->prepare("SELECT * FROM company_information WHERE user_id=?");
    if ($q) {
        $q->bind_param("i", $uid);
        $q->execute();
        $snap['info'] = $q->get_result()->fetch_assoc();
        $q->close();
    }

    $resM = @$conn->query("SELECT request_type FROM moa_requests WHERE user_id=" . (int) $uid . " ORDER BY id DESC LIMIT 1");
    if ($resM) {
        $snap['mreq'] = $resM->fetch_assoc();
    }

    return md5(json_encode($snap));
}

if (isset($_GET['cf_live_poll'])) {
    // Read-only request: release the session lock right away so polling
    // never queues behind (or blocks) the company's other page requests.
    if (session_status() === PHP_SESSION_ACTIVE) { session_write_close(); }
    while (ob_get_level() > 0) { ob_end_clean(); }
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
    echo json_encode(['ok' => true, 'raw' => cfLiveSnapshotFingerprint($conn, (int) $user_id)]);
    exit;
}
$cfLiveBaseline = cfLiveSnapshotFingerprint($conn, (int) $user_id);

$stmt_moa = $conn->prepare("
    SELECT file_name, status, remark, moa_workflow_stage, moa_admin_comment, moa_schedule_datetime,
           moa_needs_revision, moa_flagged_fields, moa_revision_comment,
           moa_schedule_status, moa_schedule_decline_reason, moa_proposed_datetime
    FROM company_requirements
    WHERE user_id=? AND requirement_type IN ('moa_document','moa')
    ORDER BY id DESC
    LIMIT 1
");
$stmt_moa->bind_param("i", $user_id);
$stmt_moa->execute();
$res_moa_row = $stmt_moa->get_result();
$moaRow = $res_moa_row->fetch_assoc();
$stmt_moa->close();

if ($moaRow) {
    $moa_file     = $moaRow['file_name'] ?? null;
    $moa_status   = $moaRow['status'] ?? 'Pending';
    $moa_remark   = $moaRow['remark'] ?? '';
    $moa_stage    = $moaRow['moa_workflow_stage'] ?? 'pending';
    $moa_schedule = $moaRow['moa_schedule_datetime'] ?? null;

    $moa_needs_revision   = !empty($moaRow['moa_needs_revision']);
    $moa_revision_comment = $moaRow['moa_revision_comment'] ?? '';
    if ($moa_needs_revision && !empty($moaRow['moa_flagged_fields'])) {
        $decodedFlags = json_decode($moaRow['moa_flagged_fields'], true);
        if (is_array($decodedFlags)) $moa_flagged_fields = $decodedFlags;
    }

    $moa_schedule_status         = $moaRow['moa_schedule_status'] ?? null;
    $moa_schedule_decline_reason = $moaRow['moa_schedule_decline_reason'] ?? '';
    $moa_proposed_datetime       = $moaRow['moa_proposed_datetime'] ?? null;
}

// ── NEW (this adjustment): fast "is this field key currently flagged?"
// lookup, used further down to hide the top Company Info section's
// read-only field for exactly the flagged field(s) — the MOA Revision
// Compliance panel inside "MOA Document Status" already shows an
// editable input for that same field, so showing both is redundant (and,
// since the value was just cleared, confusingly shows as blank up top).
// The hidden top field reappears automatically once the revision is
// resolved (moa_needs_revision goes back to false and $moa_flagged_fields
// is empty again on the next page load).
$moaFlaggedFieldSet = array_flip($moa_flagged_fields);

// ════════════════════════════════════════════════════════════════════════
//  NEW (this adjustment) — field metadata for the MOA revision panel.
//  Maps each flaggable field key (the same keys company_validation.php's
//  admin-side flag checklist uses) to: its on-page label, input type, the
//  company_information COLUMN it actually lives in (note
//  "contact_middle_name" → "contact_middle_initial", a naming mismatch
//  carried over from company_information's own schema), and its CURRENT
//  value — pulled straight from $row (already fetched above via
//  `SELECT * FROM company_information WHERE user_id=?`), so a field the
//  administrator just cleared shows up here as blank, prompting the
//  company to fill it back in. "moa_document" (flagging the uploaded file
//  itself, not a text column) has no entry here and is simply skipped
//  wherever this map is used — that scenario is handled by re-uploading
//  a document elsewhere on this page, not by this text-field panel.
// ════════════════════════════════════════════════════════════════════════
$moaRevisionFieldMeta = [
    'company_name'        => ['label' => 'Company Name',                       'type' => 'text',     'column' => 'company',                'currentValue' => $row['company']                ?? ''],
    'company_profile'     => ['label' => 'Company Profile / Brief Description', 'type' => 'textarea', 'column' => 'company_profile',        'currentValue' => $row['company_profile']        ?? ''],
    'company_address'     => ['label' => 'Complete Office Address',            'type' => 'text',     'column' => 'company_address',        'currentValue' => $row['company_address']        ?? ''],
    'position'            => ['label' => 'Position / Designation',             'type' => 'text',     'column' => 'position',               'currentValue' => $row['position']               ?? ''],
    'contact_first_name'  => ['label' => 'Contact First Name',                 'type' => 'text',     'column' => 'contact_first_name',     'currentValue' => $row['contact_first_name']     ?? ''],
    'contact_middle_name' => ['label' => 'Contact Middle Name',                'type' => 'text',     'column' => 'contact_middle_initial', 'currentValue' => $row['contact_middle_initial'] ?? ''],
    'contact_last_name'   => ['label' => 'Contact Last Name',                  'type' => 'text',     'column' => 'contact_last_name',      'currentValue' => $row['contact_last_name']      ?? ''],
    'telephone'           => ['label' => 'Telephone / Contact Number',         'type' => 'text',     'column' => 'telephone',              'currentValue' => $row['telephone']              ?? ''],
];

// ════════════════════════════════════════════════════════════════════════
//  NEW (this adjustment) — MOA INITIAL CREATION (for companies added via
//  admin_company_list.php's manual "Add Company" form or XLSX import)
//  ──────────────────────────────────────────────────────────────────────
//  A company registered the normal way through company_register.php
//  always has its MOA auto-generated for it during registration (see
//  regSaveGeneratedMoaToRequirements() there), so $moa_file is never
//  empty for a "New" request-type company that came from that flow.
//
//  A company added via admin_company_list.php's manual/import path is
//  different: attempt_create_company_account() there creates the
//  users + company_information rows (company/address/telephone/contact/
//  position — but NOT company_profile, and NOT an MOA document) and
//  stops — nothing ever auto-generates an MOA for a "New" request-type
//  company added this way. Previously every field on this page was
//  permanently readonly, so a company in this situation had no way to
//  supply the missing info or create their MOA at all.
//
//  $moa_needs_initial_creation is true exactly for that gap: request_type
//  is "New" (same $showMoaSection condition) AND no MOA document exists
//  yet for this company. When true, the company/company_address/contact/
//  position/telephone/company_profile fields further down are unlocked
//  (editable) instead of readonly, and a "Create MOA" action appears
//  (see submitMoaCreation() / the submit_moa_creation handler above) that
//  saves whatever the company enters and generates their MOA from it —
//  using the exact same cfRegenerateAndSaveMoa()/regBuildMOAStaticHTMLForDompdf()
//  machinery the MOA Revision Compliance panel already uses. The instant
//  that succeeds, $moa_file is populated and this flag naturally goes
//  false again on the next page load, so the fields lock right back down
//  — no separate "lock" step needed anywhere else.
//
//  This applies identically to BOTH company classifications (Private and
//  Public) — the condition is purely request_type + whether an MOA
//  exists yet, never $current_type.
// ════════════════════════════════════════════════════════════════════════
$moa_needs_initial_creation = ($showMoaSection && empty($moa_file));

// ════════════════════════════════════════════════════════════════════════
//  NEW (this adjustment) — per-field helper for the "Existing" empty-field
//  auto-unlock described above $moa_is_existing_request's docblock.
//  ──────────────────────────────────────────────────────────────────────
//  cfIsFieldEmpty(): a single, shared definition of "empty" for these
//  profile fields — trims whitespace, and (only for Contact Middle Name)
//  also treats the page's existing 'N/A' convention as empty, matching
//  how $contact_middle is already defaulted to 'N/A' elsewhere on this
//  page whenever no middle name is on file.
//
//  cfFieldLockFor($value): given one field's current on-file value,
//  returns ['attr' => ..., 'style' => ...] — either the normal locked
//  ('readonly', grey background) pair, unchanged from every other
//  company, OR ('', '') to render it as a normal, editable input, WHEN
//  AND ONLY WHEN this is an "Existing" request-type company AND that
//  specific field is currently empty. This never touches
//  $moa_needs_initial_creation or $cfFieldLockAttr/$cfFieldLockStyle
//  themselves (defined separately, further down, right before they're
//  first used) — it is a parallel, additive mechanism used only inside
//  the "locked" (else) HTML branch, which is the only branch an
//  "Existing" request-type company (where $showMoaSection is always
//  false, so $moa_needs_initial_creation is always false) ever reaches.
// ════════════════════════════════════════════════════════════════════════
function cfIsFieldEmpty($value, $treatNAasEmpty = false) {
    $v = trim((string) $value);
    if ($treatNAasEmpty && strcasecmp($v, 'N/A') === 0) return true;
    return ($v === '');
}
// ADJUSTMENT (this update): for an "Existing" request-type company EVERY
// profile field is now unlocked (editable), not only the ones that are
// still empty. Every other company is unaffected — they still get the
// normal locked pair exactly as before. Signature kept unchanged.
function cfFieldLockFor($value, $isExistingRequest, $normalAttr, $normalStyle, $treatNAasEmpty = false) {
    if ($isExistingRequest) {
        return ['attr' => '', 'style' => ''];
    }
    return ['attr' => $normalAttr, 'style' => $normalStyle];
}

/* ================= FETCH COMPLIANCE REQUIREMENT STATUSES (FOR DISPLAY) =================
   One query that grabs every company_requirements row for this company
   (including moa_document/moa, which are simply ignored here since they
   already have their own dedicated section above), grouped by
   requirement_type so the checklist below can look each item up cheaply.

   FIX (this update): "multiple JPEGs uploaded for one requirement only
   ever shows one" — company_register.php already correctly saves every
   uploaded file as its OWN row in company_requirements (all sharing the
   same requirement_type), but this used to do
   `$company_requirement_rows[$r_req['requirement_type']] = $r_req;` —
   using requirement_type as the array key meant each new row OVERWROTE
   the previous one, so only the last-fetched row for a given
   requirement_type ever survived here. The files were never actually
   missing from the database; they just weren't being fetched. Each
   requirement_type now maps to an ARRAY of every row saved under it
   (`$company_requirement_rows[$type][] = $r_req;`), and the display loop
   below renders all of them together as a single "stacked card" entry
   when there's more than one — mirroring the same overlaying-card visual
   already used for a fresh multi-file selection in company_register.php's
   Step 3. */
$company_requirement_rows = [];
$stmt_all_reqs = $conn->prepare("SELECT id, requirement_type, status, remark, file_name FROM company_requirements WHERE user_id=? ORDER BY id ASC");
$stmt_all_reqs->bind_param("i", $user_id);
$stmt_all_reqs->execute();
$res_all_reqs = $stmt_all_reqs->get_result();
while ($r_req = $res_all_reqs->fetch_assoc()) {
    $company_requirement_rows[$r_req['requirement_type']][] = $r_req;
}
$stmt_all_reqs->close();

$compliance_upload_errors = $_SESSION['compliance_upload_errors'] ?? [];
unset($_SESSION['compliance_upload_errors']);

/* ================= DETECT MOA FILE TYPE (FOR REVIEW BUTTON / PREVIEW MODAL) =================
   Computed here (once, up front) so the preview modal's JS (opened by clicking the MOA
   thumbnail — the old "Review" button has been removed) knows whether to
   render the document as a PDF (iframe) or an image (<img>), without having
   to duplicate the finfo lookup logic in multiple places. This does not
   remove or alter the existing finfo lookup further below inside the
   moa-status-card block ($isPdfMoa) — that logic is left completely intact. */
$moa_is_pdf = false;
if ($moa_file) {
    $finfo_moa_check = new finfo(FILEINFO_MIME_TYPE);
    $mime_moa_check  = $finfo_moa_check->buffer($moa_file);
    $moa_is_pdf = (strpos($mime_moa_check, 'pdf') !== false || strpos($mime_moa_check, 'octet') !== false);
}

/* ================= CHECK IF ALL REQUIREMENTS VERIFIED (MOA DOCUMENT + COMPLIANCE CHECKLIST) =================
   FIX (this revision): $all_verified used to look at the MOA document ONLY, so the moment the
   administrator verified the MOA the page declared "All Requirements Verified!" (popup on load and
   via the live-update script) even though the Compliance Requirements checklist still had items
   that were Pending / Denied / not yet uploaded.

   It now requires BOTH:
     • $moa_doc_verified          — the MOA document itself is Verified (the original condition,
                                    unchanged), AND
     • $compliance_all_verified   — every item in $reqDefsForType (the whole Compliance Requirements
                                    checklist) has at least one saved row and EVERY row is Verified —
                                    the exact same rule the Compliance status summary strip and the
                                    sidebar lock gate below already use. */
$moa_doc_verified = ($moa_status === 'Verified' && !empty($moa_file));

$compliance_all_verified = true;
foreach ($reqDefsForType as $avKey => $avLabel) {
    $avRows = $company_requirement_rows[$avKey] ?? [];
    if (empty($avRows)) { $compliance_all_verified = false; break; }
    foreach ($avRows as $avRow) {
        if (($avRow['status'] ?? 'Pending') !== 'Verified') { $compliance_all_verified = false; break 2; }
    }
}

$all_verified = ($moa_doc_verified && $compliance_all_verified);

/* ================= SIDEBAR LOCK GATE (ported from AccomForm.php) =================
   AccomForm.php keeps its Attendance / Reports / Dashboard sidebar links hidden — and
   shows a "Some pages are locked…" notice — until EVERY requirement is Verified by the
   administrator. This is the same gate for the company sidebar.

   "Fully verified" here means:
     • every item in $reqDefsForType (the whole Compliance Requirements checklist —
       including the "MOA Document (Existing Partnership)" upload for "Existing"
       companies) has at least one saved row and EVERY row is Verified — the exact
       same rule the Compliance status summary strip below uses; AND
     • for "New" request-type companies (the MOA Document Status workflow is shown),
       the MOA document itself is Verified ($moa_doc_verified above).
   $all_verified (now MOA document + the whole checklist — see the fix above) drives the
   "All Requirements Verified!" popup; the MOA-only check used by this gate is
   $moa_doc_verified, so the sidebar lock behaves exactly as before. */
$sidebar_unlocked = true;
foreach ($reqDefsForType as $sgKey => $sgLabel) {
    $sgRows = $company_requirement_rows[$sgKey] ?? [];
    if (empty($sgRows)) { $sidebar_unlocked = false; break; }
    foreach ($sgRows as $sgRow) {
        if (($sgRow['status'] ?? 'Pending') !== 'Verified') { $sidebar_unlocked = false; break 2; }
    }
}
if ($sidebar_unlocked && $showMoaSection && !$moa_doc_verified) {
    $sidebar_unlocked = false;
}

/* ================= LIVE UPDATES — region signatures (see cfLiveSnapshotFingerprint above) =================
   Each live region carries a data-live-sig built from exactly what it renders. The browser compares the
   signature in the live DOM with the one in a freshly fetched copy of this page, and swaps a region only
   when they differ. $cfLiveState is embedded in the page as #cfLiveState. */
$cfInfoSig = md5(json_encode([
    $company, $company_address, $telephone, $position, $company_profile,
    $contact_first, $contact_middle, $contact_last, array_values($moa_flagged_fields),
]));
$cfMoaSig = md5(json_encode([
    !empty($moa_file) ? md5((string) $moa_file) : '',
    $moa_status, $moa_remark, $moa_stage,
    (string) ($moaRow['moa_admin_comment'] ?? ''),
    $moa_schedule, $moa_schedule_status, $moa_schedule_decline_reason, $moa_proposed_datetime,
    (bool) $moa_needs_revision, array_values($moa_flagged_fields), $moa_revision_comment,
    $cfInfoSig,
]));
$cfSidebarSig = md5(json_encode([(bool) $sidebar_unlocked, (int) $inbox_count, (int) $pending_lr_count, (int) $ungraded_count]));
$cfLiveState = [
    // Anything that changes the page's STRUCTURE (classification -> different checklist, MOA section
    // appearing/disappearing, MOA file type) can't be patched in place, so it falls back to a reload.
    'struct'       => md5(json_encode([
        $current_type, array_keys($reqDefsForType), (bool) $showMoaSection,
        (bool) $moa_needs_initial_creation, !empty($moa_file), (bool) $moa_is_pdf,
    ])),
    'all_verified' => (bool) $all_verified,
    'raw'          => $cfLiveBaseline,
];
?>


<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= $pageTitle ?></title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        /* --- EXACT CSS FROM YOUR ADMIN SYSTEM --- */
        :root {
            --neust-maroon: #07145fe5;
            --neust-gold: #FFD700;
            --bg: #fcfaf7;
            --text: #2d1b1b;
            --white: #ffffff;
            --sidebar-active: #1a237e;
        }

        body { 
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif; 
            background: var(--bg); 
            margin: 0; 
            display: flex; 
            color: var(--text); 
            min-height: 100vh; 
        }

        /* --- SIDEBAR --- */
        .sidebar { 
            width: 260px; 
            background: var(--neust-maroon); 
            height: 100vh; 
            position: fixed; 
            display: flex; 
            flex-direction: column; 
            transition: all 0.3s ease; 
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
        }
        .sidebar-header h2 { 
            color: var(--neust-gold); 
            margin: 0; 
            font-size: 20px; 
            font-weight: bold; 
            white-space: nowrap; 
            overflow: hidden; 
        }
        .sidebar.collapsed .sidebar-header h2 { opacity: 0; width: 0; }
        
        .sidebar-links { flex: 1; display: flex; flex-direction: column; padding: 10px 0; }
        .sidebar a { 
            padding: 15px 25px; 
            color: #cbd5e0; 
            text-decoration: none; 
            font-size: 14px; 
            display: flex; 
            align-items: center; 
            transition: 0.2s; 
            white-space: nowrap; 
        }
        .sidebar a i { width: 30px; font-size: 18px; margin-right: 15px; text-align: center; }
        .sidebar.collapsed .link-text { display: none; }
        .sidebar.collapsed a i { margin-right: 0; }
        
        .sidebar a:hover { color: white; background: rgba(255,255,255,0.05); }
        .sidebar a.active { 
            background: var(--sidebar-active); 
            color: white; 
            border-left: 4px solid var(--neust-gold); 
        }

        /* Bottom Logout Style */
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
        }

        .toggle-btn { background: transparent; border: none; color: white; cursor: pointer; font-size: 20px; outline: none; }

        /* --- CONTENT AREA --- */
        .main-content { margin-left: 260px; width: calc(100% - 260px); transition: 0.3s; min-height: 100vh; }
        .sidebar.collapsed + .main-content { margin-left: 80px; width: calc(100% - 80px); }

        /* ── SIDEBAR LOCKED STATE (requirements not yet verified) ── */
        .sidebar-links.locked { pointer-events: none; opacity: 0; height: 0; overflow: hidden; padding: 0; }

        /* ADJUSTMENT (this revision): lock notice restyled to match AccomForm.php's — a compact
           boxed notice under the sidebar header (hidden when the sidebar is collapsed) instead
           of a full-height centered block, since the always-available links now sit below it. */
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
        
        .navbar {
            background: var(--neust-maroon);
            padding: 10px 30px;
            display: flex;
            align-items: center;
            color: white;
            height: 60px;
            flex-shrink: 0;
            box-shadow: 0 2px 8px rgba(0,0,0,0.15);
        }
        .logo-section { display: flex; align-items: center; gap: 12px; }
        .university-logo { height: 40px; }

        .container { padding: 30px; max-width: 900px; margin: 0 auto; }
        
        /* Form Card Styling */
        .form-card { 
            background: var(--white); 
            padding: 35px; 
            border-radius: 12px; 
            box-shadow: 0 4px 20px rgba(0,0,0,0.08); 
            position: relative; /* ADJUSTMENT (this revision): anchors the new .cf-info-btn */
        }
        .form-card h2 { 
            color: var(--neust-maroon); 
            margin-top: 0; 
            font-size: 22px; 
            border-bottom: 2px solid var(--neust-gold); 
            padding-bottom: 12px; 
            margin-bottom: 30px; 
        }
        /* ADJUSTMENT (this revision): "i" info button replacing the removed title label
           and the two notification banners that used to sit above the form. Positioned
           top-right of the form card; opens #cfInfoModal on click. */
        .cf-info-btn {
            position: absolute;
            top: 18px;
            right: 18px;
            width: 34px;
            height: 34px;
            border-radius: 50%;
            border: 1px solid #e2e8f0;
            background: #fff9e6;
            color: var(--neust-maroon);
            font-size: 17px;
            cursor: pointer;
            display: flex;
            align-items: center;
            justify-content: center;
            transition: background 0.15s, transform 0.15s;
            z-index: 5;
        }
        .cf-info-btn:hover { background: #ffefc2; transform: scale(1.05); }
        
        .input-row { display: grid; grid-template-columns: 1fr 1fr; gap: 20px; margin-bottom: 15px; }
        /* NEW (layout adjustment): 3-column row, used for the locked-state
           Contact Full Name / Position / Telephone row so it matches the
           requested reference layout. Falls back to stacking on small
           screens so nothing overflows on mobile. */
        .input-row-3 { display: grid; grid-template-columns: 1fr 1fr 1fr; gap: 20px; margin-bottom: 15px; }
        @media (max-width: 760px) {
            .input-row-3 { grid-template-columns: 1fr; }
        }
        .form-group { margin-bottom: 20px; }
        .form-group label { display: block; font-size: 13px; font-weight: 600; margin-bottom: 8px; color: #4a5568; }
        
        input[type="text"], input[type="tel"] {
            width: 100%; 
            padding: 12px; 
            border: 1px solid #e2e8f0; 
            border-radius: 8px; 
            font-size: 14px; 
            box-sizing: border-box;
            background: #f8fafc;
        }

        .type-banner {
            background: #fff9e6; 
            color: #856404; 
            padding: 15px; 
            border-radius: 8px; 
            font-size: 14px; 
            margin-bottom: 25px; 
            border-left: 5px solid var(--neust-gold);
            display: flex;
            align-items: center;
            gap: 10px;
        }

        /* ================================================================
           MODAL — ONLY CHANGE: allows scrolling so popup moves with scroll
           All other modal styles below are identical to original.
           ================================================================ */
        .modal {
            display: none;
            position: fixed;
            top: 0; left: 0;
            width: 100%; height: 100%;
            background: rgba(0,0,0,0.55);
            justify-content: center;
            align-items: flex-start;          /* changed from center → popup starts at top */
            z-index: 9999;
            backdrop-filter: blur(4px);
            animation: fadeInModal 0.25s ease;
            overflow-y: auto;                 /* enables scrolling inside the overlay */
            padding: 40px 0;                  /* breathing room top & bottom */
            box-sizing: border-box;
        }
        @keyframes fadeInModal { from { opacity:0; } to { opacity:1; } }

        .modal-content {
            background: white;
            padding: 36px 32px;
            border-radius: 16px;
            width: 440px;
            max-width: 92%;
            text-align: center;
            box-shadow: 0 20px 60px rgba(0,0,0,0.2);
            animation: popIn 0.3s cubic-bezier(0.34,1.56,0.64,1);
            margin: auto;                     /* keeps it horizontally centered */
            position: relative;               /* needed for stacking context */
        }
        @keyframes popIn { from { transform:scale(0.85); opacity:0; } to { transform:scale(1); opacity:1; } }
        /* ================================================================ */

        /* ── PROFILE MODAL SPECIFIC ── */
        .modal-content h3 {
            margin: 0 0 6px;
            color: var(--neust-maroon);
            font-size: 18px;
            font-weight: 700;
        }
        .modal-content .modal-subtitle {
            color: #718096;
            font-size: 13px;
            margin: 0 0 22px;
        }
        .profile-field {
            text-align: left;
            margin-bottom: 14px;
        }
        .profile-field label {
            display: block;
            font-size: 11px;
            font-weight: 700;
            color: #4a5568;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            margin-bottom: 5px;
        }
        .profile-field input {
            width: 100%;
            padding: 11px 13px;
            border: 1px solid #e2e8f0;
            border-radius: 8px;
            font-size: 13px;
            box-sizing: border-box;
            background: #f8fafc;
            color: var(--text);
            transition: border-color 0.2s;
        }
        .profile-field input:focus {
            outline: none;
            border-color: var(--neust-maroon);
            background: #fff;
        }
        .profile-field input[readonly] {
            background: #f0f2f5;
            color: #888;
            cursor: not-allowed;
        }
        .modal-btn {
            width: 100%;
            padding: 13px;
            background: var(--neust-maroon);
            color: var(--neust-gold);
            border: none;
            border-radius: 10px;
            font-size: 14px;
            font-weight: 700;
            cursor: pointer;
            margin-top: 8px;
            transition: opacity 0.2s;
        }
        .modal-btn:hover { opacity: 0.88; }

        /* ═══════════════════════════════════════════════════════════
           ADJUSTMENT (this revision) — COMPANY CONTACT PROFILE popup,
           restyled after admin_company_list.php's manual "Add New
           Company" modal (flat bordered box, uppercase header with a
           rule under it, compact 3-column grid, sticky action bar,
           2 columns on medium screens / 1 on small). Everything is
           scoped to #profileModal, so no other popup is affected.
           ═══════════════════════════════════════════════════════════ */
        @keyframes cfProfileModalPop { from { transform: scale(0.9); opacity: 0; } to { transform: scale(1); opacity: 1; } }
        #profileModal {
            --pf-navy: #1B2A4A;
            --pf-border: #C3CADA;
            --pf-muted: #5A6272;
            --pf-red: #A02A2A;
            --pf-bg: #EEF1F6;
            background: rgba(0,0,0,0.5);
            backdrop-filter: none;
            align-items: center;
            padding: 20px 0;
        }
        #profileModal .modal-content {
            width: 860px;
            max-width: 94%;
            max-height: calc(100vh - 40px);
            max-height: calc(100dvh - 40px);
            overflow-y: auto;
            padding: 16px 24px 0 24px;
            box-sizing: border-box;
            background: #ffffff;
            border: 1px solid var(--pf-border);
            border-radius: 0;
            box-shadow: none;
            text-align: left;
            animation: cfProfileModalPop 0.3s ease;
        }
        #profileModal .profile-modal-header {
            margin-bottom: 12px;
            padding-bottom: 8px;
            border-bottom: 1px solid var(--pf-border);
        }
        #profileModal .modal-content h3 {
            margin: 0;
            color: var(--pf-navy);
            font-size: 15px;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.4px;
        }
        #profileModal .modal-content .modal-subtitle {
            margin: 4px 0 0;
            font-size: 12px;
            color: var(--pf-muted);
            text-align: left;
        }
        #profileModal .profile-grid {
            display: grid;
            grid-template-columns: repeat(3, 1fr);
            column-gap: 14px;
            row-gap: 0;
            align-items: start;
        }
        #profileModal .profile-grid .span-2 { grid-column: span 2; }
        #profileModal .profile-grid .span-3 { grid-column: 1 / -1; }
        #profileModal .profile-field { margin-bottom: 9px; min-width: 0; }
        #profileModal .profile-field label {
            font-size: 12px;
            font-weight: 600;
            color: #1e293b;
            text-transform: none;
            letter-spacing: 0;
            margin-bottom: 4px;
        }
        #profileModal .profile-field label .required { color: var(--pf-red); }
        #profileModal .profile-field input {
            padding: 7px 10px;
            border: 1px solid var(--pf-border);
            border-radius: 0;
            font-size: 13px;
            background: #ffffff;
        }
        #profileModal .profile-field input:focus { border-color: var(--pf-navy); background: #ffffff; }
        #profileModal .profile-field input[readonly] {
            background: var(--pf-bg);
            color: var(--pf-muted);
            cursor: not-allowed;
        }
        /* the step-by-step help guides now sit in a wide field, so keep their screenshots a sensible size */
        #profileModal .help-step-img { max-width: 460px; }
        #profileModal .profile-modal-actions {
            position: sticky;
            bottom: 0;
            z-index: 2;
            display: flex;
            justify-content: flex-end;
            gap: 12px;
            background: #ffffff;
            margin-top: 4px;
            padding: 10px 0 12px 0;
            border-top: 1px solid var(--pf-border);
        }
        #profileModal .profile-modal-actions .modal-btn {
            width: auto;
            margin-top: 0;
            padding: 10px 24px;
            background: var(--pf-navy);
            color: #ffffff;
            border-radius: 0;
            font-size: 12px;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 0.3px;
        }
        @media (max-width: 900px) {
            #profileModal .profile-grid { grid-template-columns: 1fr 1fr; }
        }
        @media (max-width: 640px) {
            #profileModal .modal-content { padding: 14px 14px 0 14px; }
            #profileModal .profile-grid { grid-template-columns: 1fr; }
            #profileModal .profile-grid .span-2,
            #profileModal .profile-grid .span-3 { grid-column: auto; }
        }

        /* ── NOTIFICATION MODALS (verified / submitted) ── */
        .notif-modal-icon {
            font-size: 52px;
            margin-bottom: 14px;
            display: block;
        }
        .notif-modal-title {
            font-size: 20px;
            font-weight: 700;
            color: var(--neust-maroon);
            margin: 0 0 8px;
        }
        .notif-modal-msg {
            color: #718096;
            font-size: 14px;
            margin: 0 0 24px;
            line-height: 1.6;
        }
        .notif-modal-btn {
            background: var(--neust-maroon);
            color: var(--neust-gold);
            border: none;
            padding: 12px 36px;
            border-radius: 10px;
            font-weight: 700;
            font-size: 14px;
            cursor: pointer;
            transition: opacity 0.2s;
        }
        .notif-modal-btn:hover { opacity: 0.88; }

        /* ── WARNING MODAL (outside-click) ── */
        .warn-modal-icon { font-size: 46px; margin-bottom: 12px; display: block; }
        .warn-modal-title { font-size: 17px; font-weight: 700; color: #b45309; margin: 0 0 8px; }
        .warn-modal-msg   { color: #718096; font-size: 13px; margin: 0 0 22px; line-height: 1.6; }
        .warn-modal-btn   {
            background: #b45309; color: white; border: none;
            padding: 11px 32px; border-radius: 10px; font-weight: 700;
            font-size: 14px; cursor: pointer; transition: opacity 0.2s;
        }
        .warn-modal-btn:hover { opacity: 0.85; }

        /* ── HELP GUIDE PANEL ── */
        .help-toggle-btn {
            display: inline-flex; align-items: center; gap: 6px;
            background: none; border: none; color: #2563eb;
            font-size: 12px; font-weight: 600; cursor: pointer;
            padding: 4px 0; margin-top: 4px; text-decoration: underline;
            text-underline-offset: 2px;
        }
        .help-toggle-btn:hover { color: #1d4ed8; }

        .help-panel {
            display: none;
            background: #f8faff;
            border: 1px solid #bfdbfe;
            border-radius: 10px;
            padding: 16px 18px;
            margin-top: 10px;
            font-size: 13px;
            color: #1e3a5f;
        }
        .help-panel.open { display: block; animation: slideDown 0.25s ease; }
        @keyframes slideDown { from { opacity:0; transform:translateY(-6px); } to { opacity:1; transform:translateY(0); } }

        .help-panel h5 {
            margin: 0 0 12px;
            font-size: 13px;
            font-weight: 700;
            color: #1d4ed8;
            display: flex; align-items: center; gap: 6px;
        }
        .help-step {
            display: flex; gap: 10px; align-items: flex-start;
            margin-bottom: 10px;
        }
        .help-step-num {
            background: #2563eb; color: white;
            border-radius: 50%; width: 22px; height: 22px; min-width: 22px;
            display: flex; align-items: center; justify-content: center;
            font-size: 11px; font-weight: 700; margin-top: 1px;
        }
        .help-step-text { flex: 1; line-height: 1.5; }
        .help-step-img {
            width: 100%; border-radius: 6px; margin-top: 6px;
            border: 1px solid #bfdbfe; display: block;
        }
        .help-redirect-box {
            margin-top: 14px; padding: 12px 14px;
            background: #eff6ff; border: 1px solid #bfdbfe;
            border-radius: 8px; text-align: center;
        }
        .help-redirect-box p {
            margin: 0 0 8px; font-size: 12px; color: #374151; font-weight: 600;
        }
        .help-redirect-btn {
            background: #2563eb; color: white; border: none;
            padding: 8px 20px; border-radius: 7px; font-size: 12px;
            font-weight: 700; cursor: pointer; transition: opacity 0.2s;
            display: inline-flex; align-items: center; gap: 6px;
        }
        .help-redirect-btn:hover { opacity: 0.85; }

        /* ── REDIRECT CONFIRMATION MODAL ── */
        .redirect-modal-icon { font-size: 44px; margin-bottom: 12px; display: block; }
        .redirect-modal-title { font-size: 17px; font-weight: 700; color: #1d4ed8; margin: 0 0 8px; }
        .redirect-modal-msg { color: #718096; font-size: 13px; margin: 0 0 22px; line-height: 1.6; }
        .redirect-modal-actions { display: flex; gap: 10px; justify-content: center; }
        .redirect-modal-go {
            background: #2563eb; color: white; border: none;
            padding: 11px 28px; border-radius: 10px; font-weight: 700;
            font-size: 13px; cursor: pointer; transition: opacity 0.2s;
            display: inline-flex; align-items: center; gap: 6px;
        }
        .redirect-modal-go:hover { opacity: 0.85; }
        .redirect-modal-cancel {
            background: #f1f5f9; color: #475569; border: none;
            padding: 11px 28px; border-radius: 10px; font-weight: 700;
            font-size: 13px; cursor: pointer; transition: opacity 0.2s;
        }
        .redirect-modal-cancel:hover { opacity: 0.75; }

        /* Document Upload Boxes */
        .req-item { 
            background: #ffffff; 
            border: 1px solid #edf2f7; 
            border-radius: 10px; 
            padding: 20px; 
            margin-bottom: 15px; 
            display: flex; 
            justify-content: space-between; 
            align-items: center;
            transition: 0.2s;
        }
        .req-item:hover { border-color: var(--neust-maroon); box-shadow: 0 2px 8px rgba(0,0,0,0.05); }
        .req-info { font-weight: 600; font-size: 14px; color: #2d3748; }

        /* ── REMARK BADGE ── */
        .remark-badge {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            background: #fef2f2;
            border: 1px solid #fecaca;
            color: #dc2626;
            padding: 5px 10px;
            border-radius: 6px;
            font-size: 12px;
            font-weight: 600;
            margin-top: 6px;
        }
        .remark-badge i { font-size: 12px; }

        /* ── STATUS BADGE ── */
        .status-badge {
            display: inline-flex;
            align-items: center;
            gap: 5px;
            padding: 4px 10px;
            border-radius: 20px;
            font-size: 12px;
            font-weight: 700;
            margin-bottom: 4px;
        }
        .status-badge.verified  { background: #dcfce7; color: #166534; }
        .status-badge.pending   { background: #fef9c3; color: #854d0e; }
        .status-badge.denied    { background: #fef2f2; color: #dc2626; }
        .status-badge.not-submitted { background: #e5e7eb; color: #4b5563; }

        /* ══════════════════════════════════════════════════════════════
           NEW (this adjustment) — .req-status-combined. Replaces the old
           three separate, stacked pieces on a compliance requirement card
           (the .status-badge pill, a .remark-badge for the denial reason,
           and a standalone "Action required" pill) with ONE single info
           box that shows the status, the reason, and the reminder
           together — built from the exact same underlying values, just
           presented as one unit instead of three. Color keys off the same
           status modifier classes as .status-badge (verified/pending/
           denied/not-submitted) for visual consistency with the rest of
           the page. ══════════════════════════════════════════════════════════════ */
        .req-status-combined {
            display: inline-flex;
            flex-direction: column;
            align-items: flex-start;
            gap: 4px;
            padding: 8px 12px;
            border-radius: 10px;
            font-size: 12px;
            max-width: 100%;
            box-sizing: border-box;
        }
        .req-status-combined-top {
            display: flex;
            align-items: center;
            gap: 6px;
            font-weight: 700;
            font-size: 12.5px;
        }
        .req-status-combined-detail {
            font-weight: 600;
            font-size: 11.5px;
            opacity: 0.9;
            line-height: 1.4;
        }
        .req-status-combined.verified      { background: #dcfce7; color: #166534; }
        .req-status-combined.pending       { background: #fef9c3; color: #854d0e; }
        .req-status-combined.denied        { background: #fef2f2; color: #dc2626; }
        .req-status-combined.not-submitted { background: #e5e7eb; color: #4b5563; }
        /* NEW (this adjustment): the admin's remark is free text now, so a long one wraps instead of overflowing the card */
        .req-status-combined-detail { overflow-wrap: anywhere; }

        .btn-submit, .submit-all {
            background: var(--neust-maroon); 
            color: white; 
            border: none; 
            padding: 15px; 
            border-radius: 8px; 
            width: 100%; 
            cursor: pointer; 
            font-weight: bold; 
            font-size: 16px;
            margin-top: 20px;
            transition: background 0.3s;
        }
        .btn-submit:hover, .submit-all:hover { background: var(--sidebar-active); }
        /* ── SIDEBAR BADGE ── */
        .sidebar a { position: relative; }

        .sidebar-badge {
            background: #dc2626;
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
        .sidebar-badge-ungraded {
            background: #d97706;
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
        }.sidebar-badge-late {
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
            animation: badge-pulse-late 2s ease-in-out infinite;
        }
        @keyframes badge-pulse-late {
            0%,100% { box-shadow: 0 0 0 0 rgba(217,119,6,0.55); }
            50%      { box-shadow: 0 0 0 6px rgba(217,119,6,0); }
        }

        /* ═══════════════════════════════════════════════════
           MOA DOCUMENT STATUS SECTION (NEW — replaces the
           multi-requirement upload boxes; mirrors the admin
           panel's in-table MOA workflow visuals)
           ═══════════════════════════════════════════════════ */
        .moa-status-card {
            background: #faf5ff;
            border: 2px solid #c4b5fd;
            border-radius: 12px;
            padding: 18px;
        }
        .moa-preview-row {
            display: flex;
            gap: 15px;
            align-items: flex-start;
            margin-bottom: 14px;
        }
        .moa-thumb-wrap {
            width: 56px; height: 56px; border-radius: 6px;
            background: #ede9fe; display: flex; align-items: center; justify-content: center;
            cursor: pointer; border: 1px solid #c4b5fd; transition: background 0.15s; flex-shrink: 0;
        }
        .moa-thumb-wrap:hover { background: #ddd6fe; }
        .moa-thumb-wrap i { font-size: 24px; color: #7c3aed; }
        .moa-thumb-img {
            width: 56px; height: 56px; border-radius: 6px; object-fit: cover;
            cursor: pointer; border: 1px solid #c4b5fd; flex-shrink: 0;
        }

        /* ADJUSTMENT (this revision) — MOA Document Status top row.
           DEFAULT (signing schedule already answered — "You confirmed this
           signing schedule", "The administrator accepted your proposed
           schedule", "You proposed a different schedule"): the MOA Document
           display (thumbnail + name + hint) sits horizontally on the LEFT
           and the schedule panel fills the rest of the SAME row, with both
           vertically centered on one shared horizontal line. Wraps to a
           stacked layout on narrow screens.
           .moa-top-row--stacked (only while the schedule is still awaiting
           the company's response — "Signing Schedule Proposed" + the agree /
           not-available form): the MOA Document sits at the TOP and the
           panel is stacked directly BELOW it at full card width. */
        .moa-top-row {
            display: flex;
            align-items: center; /* MOA Document is vertically centered on the same horizontal line as the schedule panel */
            justify-content: flex-start;
            gap: 16px;
            flex-wrap: wrap;
            margin-bottom: 14px;
        }
        .moa-top-row .moa-preview-row {
            margin-bottom: 0;
            align-items: center;
            flex: 0 1 auto;
            min-width: 0;
        }
        .moa-top-row .moa-sched-panel {
            margin: 0;
            flex: 1 1 320px;
            min-width: 0;
            box-sizing: border-box;
        }
        /* ADJUSTMENT (this revision): once the MOA is Verified, the
           "MOA Document Verified — Signing Scheduled ✓ (date)" box sits
           in the SAME row as the MOA Document (to its right, vertically
           centered on the same horizontal line) instead of underneath it. */
        .moa-top-row .verified-lock {
            margin: 0;
            flex: 1 1 320px;
            min-width: 0;
            box-sizing: border-box;
        }
        @media (max-width: 640px) {
            .moa-top-row .moa-sched-panel,
            .moa-top-row .verified-lock {
                flex-basis: 100%;
                max-width: 100%;
            }
        }
        .moa-top-row.moa-top-row--stacked {
            flex-direction: column;
            flex-wrap: nowrap;
            align-items: stretch;
        }
        .moa-top-row.moa-top-row--stacked .moa-preview-row {
            flex: 0 0 auto;
        }
        .moa-top-row.moa-top-row--stacked .moa-sched-panel {
            flex: 0 0 auto; /* column direction: never let a flex-basis turn into a fixed height */
            width: 100%;
            max-width: 100%;
        }
        /* Hint shown BELOW the MOA Document name in place of the removed Review button. */
        .moa-click-hint {
            display: block;
            margin-top: 2px;
            font-size: 12.5px;
            font-weight: 500;
            font-style: italic;
            color: #6d28d9;
            line-height: 1.3;
        }
        .moa-click-hint:not(:last-child) { margin-bottom: 6px; } /* only when a badge / remark / lock box follows it */

        /* MOA in-table style stepper (view-only for the company) */
        .moa-tbl-stepper { display: flex; align-items: center; gap: 0; background: #ffffff; border: 1px solid #e2e8f0; border-radius: 10px; padding: 14px 16px; overflow-x: auto; margin-top: 4px; }
        .moa-tbl-step { display: flex; flex-direction: column; align-items: center; gap: 4px; flex: 1; position: relative; min-width: 70px; }
        .moa-tbl-step:not(:last-child)::after { content:''; position:absolute; right:-50%; top:15px; width:100%; height:2px; background:#e2e8f0; z-index:0; }
        .moa-tbl-step.done:not(:last-child)::after, .moa-tbl-step.active:not(:last-child)::after { background:#a5b4fc; }
        .moa-tbl-dot { width:32px; height:32px; border-radius:50%; border:2px solid #e2e8f0; background:white; display:flex; align-items:center; justify-content:center; font-size:12px; color:#94a3b8; font-weight:700; z-index:1; position:relative; transition:all 0.2s; }
        .moa-tbl-step.done   .moa-tbl-dot { border-color:#22c55e; background:#22c55e; color:white; }
        .moa-tbl-step.active .moa-tbl-dot { border-color:var(--neust-maroon); background:var(--neust-maroon); color:var(--neust-gold); box-shadow:0 0 0 3px rgba(7,20,95,0.15); }
        .moa-tbl-label { font-size:10px; font-weight:700; text-transform:uppercase; letter-spacing:0.3px; color:#94a3b8; text-align:center; line-height:1.2; }
        .moa-tbl-step.done   .moa-tbl-label { color:#22c55e; }
        .moa-tbl-step.active .moa-tbl-label { color:var(--neust-maroon); }

        .verified-lock { display:flex; align-items:center; gap:8px; background:#f0f9ff; border:1px solid #bae6fd; color:#0369a1; padding:9px 12px; border-radius:6px; font-size:12px; font-weight:600; }
        /* CHANGED (card redesign): inside a compliance requirement card the
           verified badge spans the card and is centered, matching the
           full-width dashed upload area it replaces. Scoped so the MOA
           Document Status section's verified-lock is untouched. */
        .compliance-req-item .verified-lock {
            justify-content: center;
            text-align: center;
            border-radius: 12px;
            padding: 16px 18px;
            min-height: 66px;
            font-size: 13px;
            box-sizing: border-box;
        }
        .verified-lock i { font-size:13px; }
        .req-awaiting-ui { display:flex; align-items:center; gap:8px; background:#fff7ed; border:1px solid #fed7aa; color:#9a3412; padding:12px 14px; border-radius:6px; font-size:13px; font-weight:600; }

        /* ═══════════════════════════════════════════════════
           NEW (this adjustment) — MOA REVISION COMPLIANCE PANEL
           Shown inside .moa-status-card when the administrator has
           flagged the MOA for revision (moa_needs_revision).
           ═══════════════════════════════════════════════════ */
        .moa-revision-panel {
            background: #fef2f2;
            border: 1.5px solid #fecaca;
            border-radius: 10px;
            padding: 16px 18px;
            margin: 4px 0 14px;
        }
        .moa-revision-header {
            font-size: 14px;
            font-weight: 800;
            color: #991b1b;
            display: flex;
            align-items: center;
            gap: 8px;
            margin-bottom: 6px;
        }
        .moa-revision-note {
            font-size: 13px;
            color: #7f1d1d;
            font-style: italic;
            margin: 0 0 8px;
            line-height: 1.5;
        }
        .moa-revision-hint {
            font-size: 12.5px;
            color: #9a3412;
            margin: 0 0 14px;
            line-height: 1.5;
        }
        .moa-revision-fields .form-group { margin-bottom: 12px; }
        .moa-revision-fields .form-group:last-child { margin-bottom: 0; }
        .moa-revision-input {
            width: 100%;
            padding: 11px 12px;
            border: 1.5px solid #f87171;
            border-radius: 8px;
            font-size: 14px;
            box-sizing: border-box;
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            background: #ffffff;
            resize: vertical;
        }
        .moa-revision-input:focus { outline: none; border-color: #dc2626; box-shadow: 0 0 0 3px rgba(220,38,38,0.12); }

        /* ── NEW (layout adjustment) — MOA REVISION PANEL LAYOUT ─────────────
           Makes the flagged-field inputs in the revision panel follow the SAME
           row structure as the company info section above (Contact name(s) /
           Position / Telephone, then Company Name / Complete Office Address,
           then Company Profile full-width), instead of one long single column.
           Only the rows/columns are new — every input keeps its own id, class
           and value, so submitMoaRevision() and the server handler are
           untouched. The column count per row is set inline (--moa-cols) so a
           row with fewer flagged fields simply spans the full width. */
        .moa-revision-grid {
            display: grid;
            grid-template-columns: repeat(var(--moa-cols, 1), minmax(0, 1fr));
            gap: 12px 20px;
            margin-bottom: 12px;
        }
        .moa-revision-fields > .moa-revision-grid:last-of-type { margin-bottom: 0; }
        .moa-revision-fields .moa-revision-grid .form-group { margin-bottom: 0; }
        @media (max-width: 760px) {
            .moa-revision-grid { grid-template-columns: 1fr; }
        }
        /* The text inputs in this panel already render with the global
           input[type="text"] look (light-gray fill, thin border) because that
           selector out-ranks .moa-revision-input; the textarea isn't matched
           by it, so it alone showed the red border / white fill. Give it the
           same look so every field in the panel matches. Scoped to
           .moa-revision-fields so the signing-schedule panel's own
           .moa-revision-input fields are unaffected. Red focus ring is kept. */
        .moa-revision-fields textarea.moa-revision-input {
            padding: 12px;
            border: 1px solid #e2e8f0;
            background: #f8fafc;
        }
        .moa-revision-fields textarea.moa-revision-input:focus {
            border-color: #dc2626;
            box-shadow: 0 0 0 3px rgba(220,38,38,0.12);
        }
        .moa-revision-feedback {
            font-size: 12.5px;
            font-weight: 600;
            border-radius: 7px;
            padding: 0;
            margin: 0;
            display: none;
        }
        .moa-revision-feedback.show { display: block; padding: 9px 12px; margin: 12px 0 0; }
        .moa-revision-feedback.error { background: #fee2e2; color: #991b1b; border: 1px solid #fca5a5; }
        .moa-revision-feedback.success { background: #dcfce7; color: #166534; border: 1px solid #86efac; }
        .moa-revision-submit-btn {
            margin-top: 14px;
            background: #dc2626;
            color: #fff;
            border: none;
            padding: 11px 22px;
            border-radius: 8px;
            font-size: 13.5px;
            font-weight: 700;
            cursor: pointer;
            display: inline-flex;
            align-items: center;
            gap: 8px;
            transition: opacity 0.15s, transform 0.1s;
        }
        .moa-revision-submit-btn:hover { opacity: 0.88; transform: translateY(-1px); }
        .moa-revision-submit-btn:disabled { opacity: 0.55; cursor: not-allowed; transform: none; }

        /* ═══════════════════════════════════════════════════
           NEW (this adjustment) — SIGNING SCHEDULE CONFIRMATION panel
           (agree / decline + propose an alternative). Shares
           .moa-revision-input / .moa-revision-feedback / .moa-revision-spinner
           (defined above) for the textarea/date/time fields and the
           status message.
           ═══════════════════════════════════════════════════ */
        .moa-sched-panel {
            background: #f5f3ff;
            border: 1.5px solid #ddd6fe;
            border-radius: 10px;
            padding: 16px 18px;
            margin: 4px 0 14px;
        }
        .moa-sched-panel-header {
            font-size: 14px;
            font-weight: 800;
            display: flex;
            align-items: center;
            gap: 8px;
            margin-bottom: 8px;
        }
        .moa-sched-panel-header.pending { color: #854d0e; }
        .moa-sched-panel-header.confirmed { color: #166534; }
        .moa-sched-panel-header.declined { color: #991b1b; }
        .moa-sched-panel-datetime {
            font-size: 15px;
            font-weight: 700;
            color: #4c1d95;
            margin: 0 0 10px;
        }
        .moa-sched-panel-datetime.moa-sched-strike {
            font-size: 13px;
            font-weight: 600;
            color: #7f1d1d;
            text-decoration: line-through;
            opacity: 0.75;
        }
        .moa-sched-panel-note {
            font-size: 13px;
            color: #581c87;
            margin: 0 0 8px;
            line-height: 1.5;
        }
        .moa-sched-panel-hint {
            font-size: 12.5px;
            color: #6d28d9;
            margin: 0 0 14px;
            line-height: 1.5;
        }
        .moa-sched-field-label {
            display: block;
            font-size: 12px;
            font-weight: 700;
            color: #5b21b6;
            text-transform: uppercase;
            letter-spacing: 0.3px;
            margin-bottom: 6px;
        }
        .moa-sched-panel-actions {
            display: flex;
            gap: 10px;
            flex-wrap: wrap;
        }
        .moa-sched-agree-btn,
        .moa-sched-decline-btn,
        .moa-sched-cancel-btn {
            border: none;
            padding: 11px 22px;
            border-radius: 8px;
            font-size: 13.5px;
            font-weight: 700;
            cursor: pointer;
            display: inline-flex;
            align-items: center;
            gap: 8px;
            transition: opacity 0.15s, transform 0.1s;
        }
        .moa-sched-agree-btn:hover,
        .moa-sched-decline-btn:hover,
        .moa-sched-cancel-btn:hover { opacity: 0.88; transform: translateY(-1px); }
        .moa-sched-agree-btn:disabled,
        .moa-sched-decline-btn:disabled { opacity: 0.55; cursor: not-allowed; transform: none; }
        .moa-sched-agree-btn { background: #16a34a; color: #fff; }
        .moa-sched-decline-btn { background: #dc2626; color: #fff; }
        .moa-sched-cancel-btn { background: #e5e7eb; color: #374151; }
        .moa-revision-spinner {
            display: inline-block;
            width: 13px; height: 13px;
            border: 2px solid rgba(255,255,255,0.4);
            border-top-color: #fff;
            border-radius: 50%;
            animation: moaRevSpin 0.7s linear infinite;
        }
        @keyframes moaRevSpin { to { transform: rotate(360deg); } }

        /* ═══════════════════════════════════════════════════
           NEW (this adjustment) — MOA INITIAL CREATION
           (unlocked-fields banner + Create MOA button), shown for
           companies added via admin_company_list.php's manual/import
           flow whose MOA hasn't been created yet. Reuses
           .moa-revision-feedback (defined above) for its status message.
           ═══════════════════════════════════════════════════ */
        .moa-creation-banner {
            display: flex;
            align-items: center;
            gap: 10px;
            background: #eff6ff;
            border: 1.5px solid #93c5fd;
            color: #1e40af;
            padding: 12px 14px;
            border-radius: 8px;
            font-size: 13px;
            font-weight: 600;
            margin-bottom: 18px;
            line-height: 1.5;
        }
        .moa-creation-banner i { font-size: 15px; flex-shrink: 0; }
        .moa-creation-actions { margin-top: 4px; margin-bottom: 30px; }
        .moa-creation-submit-btn {
            background: var(--neust-maroon);
            color: var(--neust-gold);
            border: none;
            padding: 12px 24px;
            border-radius: 8px;
            font-size: 14px;
            font-weight: 700;
            cursor: pointer;
            display: inline-flex;
            align-items: center;
            gap: 8px;
            transition: opacity 0.15s, transform 0.1s;
        }
        .moa-creation-submit-btn:hover { opacity: 0.88; transform: translateY(-1px); }
        .moa-creation-submit-btn:disabled { opacity: 0.55; cursor: not-allowed; transform: none; }
        .moa-creation-preview-btn {
            background: #ffffff;
            color: var(--neust-maroon);
            border: 1.5px solid var(--neust-maroon);
            padding: 12px 24px;
            border-radius: 8px;
            font-size: 14px;
            font-weight: 700;
            cursor: pointer;
            display: inline-flex;
            align-items: center;
            gap: 8px;
            margin-right: 10px;
            transition: opacity 0.15s, transform 0.1s, background 0.15s;
        }
        .moa-creation-preview-btn:hover { background: #f5f3ff; transform: translateY(-1px); }

        /* ═══════════════════════════════════════════════════
           MOA DOCUMENT PREVIEW MODAL (REDESIGNED — full-bleed
           dark viewer mirroring the document preview design
           used in moa_request.php's uploaded-file viewer /
           the admin blob-preview modal: a dark slate top bar
           spanning the full width with a gold document icon +
           filename, and an edge-to-edge viewing surface that
           fills the rest of the screen beneath it.)
           ═══════════════════════════════════════════════════ */
        .moa-doc-modal {
            display: none;
            position: fixed;
            inset: 0;
            box-sizing: border-box;
            background: #0f172a;
            z-index: 10003;
            flex-direction: column;
            overflow: hidden; /* prevents a second, outer scrollbar — only the
                                  document viewer beneath scrolls, if at all */
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
            overflow: hidden; /* the iframe/image below handles its own scrolling */
        }
        .moa-doc-modal-viewer iframe {
            width: 100%;
            height: 100%;
            border: none;
            display: block;
            background: #ffffff;
        }
        .moa-doc-modal-viewer.image-mode {
            background: #000000;
            align-items: center;
            justify-content: center;
            padding: 26px;
        }
        .moa-doc-modal-image {
            max-width: 100%;
            max-height: 100%;
            border-radius: 6px;
            box-shadow: 0 8px 40px rgba(0,0,0,0.6);
            display: block;
            margin: auto;
        }

        /* ═══════════════════════════════════════════════════
           COMPLIANCE REQUIREMENTS SECTION (NEW — integrates the
           classification-based document checklist from
           company_register.php's Step 3 "Classification & Docs")
           ═══════════════════════════════════════════════════ */
        .compliance-status-card {
            background: #f8fafc;
            border: 2px solid #cbd5e1;
            border-radius: 12px;
            padding: 18px;
        }
        /* ══════════════════════════════════════════════════════════════
           ADJUSTMENT (this revision) — REQUESTED REQUIREMENT-CARD
           REDESIGN: each compliance requirement is now rendered as a
           tall, vertically-stacked, centered card (large rounded file
           preview on top → requirement name → status pill → full-width
           dashed upload area), matching the supplied mock-up.

           This is a PRESENTATION-ONLY change. Every element id, class
           hook, data-attribute, input name, PHP branch, upload/validate/
           save path, status computation, remark display and preview-modal
           wiring is preserved exactly as it was — only the geometry,
           spacing and colours of the card change.
           ══════════════════════════════════════════════════════════════ */
        .compliance-req-item {
            background: #ffffff;
            border: 1px solid #dbe7f6;
            border-radius: 18px;
            padding: 24px 20px 20px;
            margin-bottom: 14px;
            box-shadow: 0 2px 10px rgba(15, 23, 42, 0.04);
            transition: border-color 0.2s, box-shadow 0.2s;
        }
        .compliance-req-item:hover {
            border-color: #bcd6f2;
            box-shadow: 0 6px 18px rgba(15, 23, 42, 0.08);
        }
        .compliance-req-item:last-child { margin-bottom: 0; }

        /* ══════════════════════════════════════════════════════════════
           NEW (this revision) — STATUS-AWARE CARD STYLING.

           Each .compliance-req-item now carries a data-req-status
           attribute ("verified" / "pending" / "denied" / "not-submitted"),
           written by PHP from the SAME $badgeClass value that already
           drives the status pill inside the card — so it is always exactly
           in sync with the real, server-computed status and needs no extra
           query or logic. These selectors simply tint the card's border /
           background accent to match that status the instant the page
           renders, with no JavaScript required for the colours themselves.

           This is presentation-only and purely additive: the base
           .compliance-req-item rules above still apply to every card, and
           nothing here changes the markup, ids, data attributes, inputs,
           upload/validate/save paths, status computation or remark display.
           ══════════════════════════════════════════════════════════════ */
        .compliance-req-item[data-req-status="denied"] {
            border-color: #d8dde5;
            background: #ffffff;
        }
        .compliance-req-item[data-req-status="denied"]:hover {
            border-color: #bcd6f2;
            box-shadow: 0 6px 18px rgba(15, 23, 42, 0.08);
        }
        .compliance-req-item[data-req-status="pending"] {
            border-color: #e2e8f0;
            background: #ffffff;
        }
        .compliance-req-item[data-req-status="pending"]:hover {
            border-color: #bcd6f2;
            box-shadow: 0 6px 18px rgba(15, 23, 42, 0.08);
        }
        .compliance-req-item[data-req-status="verified"] {
            border-color: #e2e8f0;
            background: #ffffff;
        }
        .compliance-req-item[data-req-status="verified"]:hover {
            border-color: #bcd6f2;
            box-shadow: 0 6px 18px rgba(15, 23, 42, 0.08);
        }
        .compliance-req-item[data-req-status="not-submitted"] {
            border-color: #e2e8f0;
            background: #ffffff;
        }

        /* Status-matched accents for the pieces INSIDE a card. Scoped to
           the status attribute so no other card (or any other section of
           the page) is affected. */
        .compliance-req-item[data-req-status="denied"] .req-file-drop {
            border-color: #cbd5e1;
            background: #f8fafc;
        }
        .compliance-req-item[data-req-status="denied"] .req-file-drop:hover {
            border-color: var(--neust-maroon);
            background: #f2f3fb;
        }
        .compliance-req-item[data-req-status="denied"] .req-file-icon { color: #475569; }
        .compliance-req-item[data-req-status="denied"] .req-file-text { color: #334155; }
        .compliance-req-item[data-req-status="denied"] .req-thumb-img,
        .compliance-req-item[data-req-status="denied"] .req-thumb-wrap { border-color: #cbd5e1; }

        .compliance-req-item[data-req-status="pending"] .req-thumb-img,
        .compliance-req-item[data-req-status="pending"] .req-thumb-wrap { border-color: #cbd5e1; }

        .compliance-req-item[data-req-status="verified"] .req-thumb-img,
        .compliance-req-item[data-req-status="verified"] .req-thumb-wrap { border-color: #cbd5e1; }

        /* A card whose selection has been staged by the company in this
           visit (class added by JS on file-select, removed when cleared)
           reads as "ready to submit" regardless of its stored status. */
        .compliance-req-item.req-staged {
            border-color: #bcd6f2 !important;
            background: #f8fafc !important;
            box-shadow: 0 6px 18px rgba(15, 23, 42, 0.08) !important;
        }
        .compliance-req-item.req-staged .req-file-drop {
            border-color: var(--neust-maroon);
            background: #f2f3fb;
        }
        .compliance-req-item.req-staged .req-file-icon,
        .compliance-req-item.req-staged .req-file-text { color: var(--neust-maroon); }

        /* ── UPDATED (this adjustment): the old standalone "Action required"
           pill (.req-action-required) is retired — that reminder now lives
           inside the combined .req-status-combined box below instead of as
           its own separate CSS-toggled element. See .req-status-combined
           further down (near .status-badge) for the new single-box styling
           that replaces this, .remark-badge, and the status-badge pill
           together. -->

        /* NEW (this revision) — live status summary strip rendered above the
           requirement grid from the PHP status-detection pass. Its own
           self-contained classes, so nothing else on the page is affected. */
        .req-status-summary {
            display: flex;
            flex-wrap: wrap;
            gap: 8px;
            margin: 0 0 14px;
        }
        .req-summary-chip {
            display: inline-flex;
            align-items: center;
            gap: 7px;
            padding: 8px 15px;
            border-radius: 999px;
            font-size: 12.5px;
            font-weight: 700;
            border: 1px solid transparent;
        }
        .req-summary-chip i { font-size: 12.5px; }
        .req-summary-chip.verified      { background: #f1f5f9; border-color: #dbe3ec; color: #166534; }
        .req-summary-chip.pending       { background: #f1f5f9; border-color: #dbe3ec; color: #854d0e; }
        .req-summary-chip.denied        { background: #f1f5f9; border-color: #dbe3ec; color: #b91c1c; }
        .req-summary-chip.not-submitted { background: #f1f5f9; border-color: #dbe3ec; color: #475569; }
        .req-status-summary.all-verified .req-summary-chip { padding: 10px 18px; font-size: 13px; }

        /* ── NEW: two-requirements-per-row grid layout for the
           Compliance Requirements list, matching the compact grid
           arrangement used for the equivalent "Classification & Docs"
           step in company_register.php (.req-grid there). Wraps the
           existing .compliance-req-item cards two-per-row on wider
           screens and falls back to a single column on narrow/mobile
           screens, exactly mirroring company_register.php's own
           breakpoint. Purely a layout change — every card's internal
           markup, status badges, previews, and upload controls are
           completely untouched. ── */
        .req-grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            column-gap: 18px;
            row-gap: 4px;
            align-items: stretch; /* CHANGED: equal-height requirement cards per row */
        }
        @media (max-width: 560px) {
            .req-grid { grid-template-columns: 1fr; }
        }
        .req-grid .compliance-req-item {
            min-width: 0; /* allow long labels/filenames to ellipsis instead of overflowing their column */
        }
        .req-grid .compliance-req-item:last-child { margin-bottom: 14px; }

        /* ══════════════════════════════════════════════════════════════
           ADJUSTMENT (this revision) — REQUESTED LAYOUT CHANGE ONLY:
           "make the file display big matching the button for
           upload/reupload button... aligning with the file name, file
           display, file status & remarks, and button now centered
           vertically."

           This changes ONLY the geometry/alignment of the compliance
           requirement row (thumbnail size, stack size, and vertical
           centering of thumbnail / text block / action button). It does
           NOT touch: markup structure needed for existing JS hooks
           (.creq-preview-trigger, data-req-key, data-req-files,
           data-req-label, #reqFileInput_<key>, #reqCount_c_<key>,
           #reqDrop_c_<key>, #reqFileText_<key>), upload/validation/save
           PHP logic, status computation, remark display, or any other
           section of the page (MOA status card, sidebar, modals, etc).
           ══════════════════════════════════════════════════════════════ */
        /* CHANGED (card redesign): the three parts of a requirement —
           file display, name/status/remarks, and the action button — are
           now stacked vertically and centered, instead of sitting as
           three side-by-side columns. The element order in the HTML is
           unchanged, so all existing JS hooks still resolve identically. */
        .compliance-req-top {
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: flex-start;
            text-align: center;
            gap: 16px;
        }
        /* NEW — wraps the file preview slot (thumbnail / stack / empty
           placeholder / live local-file preview) so it can be swapped out
           as one unit by JS the moment the user picks a new file, without
           touching the name/status/action blocks around it. */
        .req-preview-slot {
            display: flex;
            align-items: center;
            justify-content: center;
            width: 100%;
        }
        /* Info block (name, status pill, remark) — now a centered column
           directly under the file preview. */
        .compliance-req-info {
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            text-align: center;
            width: 100%;
            min-width: 0;
            gap: 10px;
        }
        /* Action block (upload/reupload area OR the verified-lock badge)
           now spans the full width of the card, at the bottom. */
        .compliance-req-action {
            display: flex;
            align-items: stretch;
            justify-content: center;
            width: 100%;
            flex-shrink: 0;
        }
        .compliance-req-action .req-file-drop,
        .compliance-req-action .verified-lock { width: 100%; }

        /* Requirement name — larger, bolder, deep navy, centered.
           CHANGED (this revision): it now sits at the TOP of the card as a
           full-width heading above the file preview. */
        .compliance-req-item .req-info {
            font-size: 19px;
            font-weight: 800;
            color: #17325c;
            line-height: 1.3;
            letter-spacing: -0.2px;
            word-break: break-word;
        }
        .compliance-req-top > .req-info {
            width: 100%;
            margin-bottom: -2px; /* pulls the preview slightly closer to the title */
        }
        /* Status pill — enlarged to match the mock-up. Scoped to the
           compliance card so the MOA Document Status badge elsewhere on
           this page keeps its original size. */
        .compliance-req-item .status-badge {
            font-size: 15px;
            font-weight: 800;
            padding: 9px 26px;
            border-radius: 999px;
            margin-bottom: 0;
            gap: 8px;
        }
        .compliance-req-item .status-badge i { font-size: 15px; }
        .compliance-req-item .remark-badge { max-width: 100%; text-align: left; }

        /* ── NEW (this adjustment) — enlarged .req-status-combined sizing
           for the compliance card context, matching the same scale the
           old .status-badge pill used to have here (see immediately
           above); the MOA Document Status section's own badge is
           untouched since it never uses .req-status-combined. */
        .compliance-req-item .req-status-combined {
            font-size: 15px;
            padding: 12px 22px;
            border-radius: 16px;
            gap: 6px;
        }
        .compliance-req-item .req-status-combined-top {
            font-size: 15px;
            font-weight: 800;
            gap: 8px;
        }
        .compliance-req-item .req-status-combined-top i { font-size: 15px; }
        .compliance-req-item .req-status-combined-detail {
            font-size: 12.5px;
            max-width: 100%;
            text-align: left;
        }

        /* NEW — small helper note shown in place of the status/remark
           block while a freshly-chosen file is staged for this
           requirement (i.e. after the Denied badge + remark have been
           auto-hidden because the company picked a replacement file). */
        .req-staged-note {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            background: #eff6ff;
            border: 1px solid #bfdbfe;
            color: #1d4ed8;
            padding: 6px 14px;
            border-radius: 999px;
            font-size: 12px;
            font-weight: 700;
        }
        .req-staged-note i { font-size: 12px; }

        .req-file-drop {
            border: 2px dashed #a9c7ea;
            border-radius: 12px;
            padding: 16px 18px;
            text-align: center;
            cursor: pointer;
            transition: 0.2s;
            background: #f7faff;
            display: flex;
            align-items: center;
            gap: 12px;
            justify-content: center;
            min-height: 66px;
            box-sizing: border-box;
            margin-top: 0;
        }
        .req-file-drop:hover { border-color: var(--neust-maroon); background: #f2f3fb; }
        .req-file-drop.has-file { border-color: #22c55e; background: #ecfdf5; }
        .req-file-icon { font-size: 20px; color: #17325c; line-height: 1; }
        .req-file-text {
            font-size: 15px;
            color: #17325c;
            font-weight: 700;
            overflow: hidden;
            text-overflow: ellipsis;
            white-space: nowrap;
            max-width: 100%;
        }
        /* CHANGED (card redesign): the single-file preview is now a large
           rounded square sitting at the top of the card. */
        .req-thumb-wrap {
            width: 150px; height: 150px; border-radius: 16px;
            background: #ede9fe; display: flex; align-items: center; justify-content: center;
            cursor: pointer; border: 2px solid #c9ddf5; flex-shrink: 0;
            box-shadow: 0 2px 8px rgba(15, 23, 42, 0.06);
            transition: transform 0.15s, box-shadow 0.15s;
        }
        .req-thumb-wrap:hover { background: #ddd6fe; transform: translateY(-2px); box-shadow: 0 6px 16px rgba(15,23,42,0.12); }
        .req-thumb-wrap i { font-size: 52px; color: #7c3aed; }
        .req-thumb-img {
            width: 150px; height: 150px; border-radius: 16px; object-fit: cover;
            cursor: pointer; border: 2px solid #c9ddf5; flex-shrink: 0;
            background: #f1f5f9;
            box-shadow: 0 2px 8px rgba(15, 23, 42, 0.06);
            transition: transform 0.15s, box-shadow 0.15s;
        }
        .req-thumb-img:hover { transform: translateY(-2px); box-shadow: 0 6px 16px rgba(15,23,42,0.12); }

        /* NEW (card redesign) — placeholder tile shown in the same slot
           when a requirement has no saved file yet, so every card in the
           grid keeps the same shape/height. It is purely decorative: it
           carries no preview trigger, no data attributes and no inputs. */
        .req-thumb-empty {
            width: 150px; height: 150px; border-radius: 16px;
            background: #f8fafc; border: 2px dashed #cbd5e1;
            display: flex; flex-direction: column; align-items: center; justify-content: center;
            gap: 8px; flex-shrink: 0; color: #94a3b8;
        }
        .req-thumb-empty i { font-size: 42px; color: #b6c4d6; }
        /* Variant shown when a saved file exists in the database but can no
           longer be previewed (see the <img> onerror fallback in the card
           markup further down). Same tile, different icon/wording. */
        .req-thumb-empty i.fa-file-circle-xmark { color: #cbb2b2; }
        .req-thumb-empty span { font-size: 11px; font-weight: 700; letter-spacing: 0.3px; text-transform: uppercase; }

        /* ══════════════════════════════════════════════════════════════
           ADJUSTMENT (this revision) — PENDING / REJECTED DISPLAY, ported
           from the admin panel's requirement cards in
           company_validation.php (.cv-card-ribbon / .cv-rb /
           .cv-rej-placeholder / .cv-card-remark):
             • the status is a small pill pinned to the TOP-RIGHT corner of
               the file preview (yellow "Pending" / red "Rejected" with a
               ban icon) instead of a separate status box under it;
             • a Rejected requirement shows the pink dashed "Rejected —
               Awaiting re-upload" placeholder instead of the old "File
               removed" tile;
             • the admin's rejection remark sits in its own pink
               "Remark:" box under the preview.
           Verified / Not Submitted cards are untouched.
           ══════════════════════════════════════════════════════════════ */
        .req-preview-wrap {
            position: relative;
            display: flex;
            align-items: center;
            justify-content: center;
            max-width: 100%;
        }
        .req-preview-wrap .req-preview-slot { width: auto; }
        .req-card-ribbon {
            position: absolute;
            top: 8px;
            right: 8px;
            z-index: 6;
            display: flex;
            gap: 6px;
            pointer-events: none; /* never blocks a click on the file underneath */
        }
        .req-rb {
            display: inline-flex;
            align-items: center;
            gap: 4px;
            font-size: 12px;
            font-weight: 700;
            padding: 4px 11px;
            border-radius: 999px;
            white-space: nowrap;
            box-shadow: 0 1px 2px rgba(0,0,0,0.12);
        }
        .req-rb-pending  { background: #fef9c3; color: #854d0e; }
        .req-rb-rejected { background: #fee2e2; color: #991b1b; }
        /* Rejected requirement (no file left): the preview area is a full-width
           grey panel holding the pink dashed "Awaiting re-upload" placeholder, with
           the "Rejected" pill on the PANEL's top-right corner — laid out like the
           admin card in company_validation.php. The word "Rejected" is shown once
           (the pill), not repeated inside the placeholder. */
        .req-preview-wrap.req-preview-wrap--rejected {
            width: 100%;
            height: 150px;
            box-sizing: border-box;
            padding: 14px;
            background: #eef1f6;
            border-radius: 16px;
        }
        .req-preview-wrap--rejected .req-preview-slot { width: 100%; height: 100%; }
        .req-preview-wrap--rejected .req-card-ribbon { top: 10px; right: 10px; }
        /* once the company stages a replacement file the panel drops back to the normal thumbnail look */
        .compliance-req-item.req-staged .req-preview-wrap.req-preview-wrap--rejected {
            width: auto;
            height: auto;
            padding: 0;
            background: transparent;
            border-radius: 0;
        }
        .compliance-req-item.req-staged .req-preview-wrap--rejected .req-preview-slot { width: auto; height: auto; }
        .req-rej-placeholder {
            width: 100%; height: 100%; border-radius: 12px;
            box-sizing: border-box; flex-shrink: 0;
            background: #fef2f2; border: 1px dashed #fca5a5;
            display: flex; flex-direction: column; align-items: center; justify-content: center;
            gap: 8px; color: #b91c1c;
            font-size: 14px; font-weight: 600; text-align: center; line-height: 1.4;
        }
        .req-rej-placeholder i { font-size: 32px; }
        .req-card-remark {
            display: flex;
            align-items: flex-start;
            gap: 7px;
            width: 100%;
            box-sizing: border-box;
            padding: 9px 14px;
            background: #fef2f2;
            border: 1px solid #fecaca;
            border-radius: 12px;
            color: #991b1b;
            font-size: 13px;
            line-height: 1.4;
            text-align: left;
            overflow-wrap: anywhere;
        }
        .req-card-remark i { margin-top: 2px; flex-shrink: 0; }
        /* a long remark scrolls inside its box (about four lines visible) instead of stretching the card */
        .req-card-remark > span {
            flex: 1; min-width: 0;
            max-height: 5.6em; overflow-y: auto; padding-right: 4px;
            scrollbar-width: thin; scrollbar-color: #fca5a5 transparent;
        }
        .req-card-remark b { font-weight: 700; }

        /* NEW — visual tag used to mark the additive MOA requirement
           upload item (for "Existing" companies) so it's easy to tell
           apart from the classification checklist items in the same
           list, without altering the shared .compliance-req-item markup
           or any other item's appearance. */
        .req-moa-tag {
            display: inline-block;
            background: #ede9fe;
            color: #6d28d9;
            font-size: 10px;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.4px;
            padding: 2px 8px;
            border-radius: 999px;
            margin-left: 8px;
            vertical-align: middle;
        }

        /* ═══════════════════════════════════════════════════
           NEW — OVERLAYING "STACKED CARD" for a compliance
           requirement with 2+ saved files, mirroring the same
           layered photo-stack visual already used for a fresh
           multi-file selection in company_register.php's Step 3
           (ADJUSTMENT: enlarged proportionally — from 58px/44px to
           76px/58px — to match the enlarged single-file thumbnail
           and upload-button height above, per the same "file display
           should match the button size" request).
           ═══════════════════════════════════════════════════ */
        .req-file-stack-wrap { cursor: pointer; flex-shrink: 0; text-align: center; }
        .req-file-stack {
            position: relative;
            width: 150px;
            height: 150px;
            margin: 0 auto 4px;
        }
        .req-file-stack .req-stack-layer {
            position: absolute;
            top: 13px; left: 13px;
            width: 124px; height: 124px;
            border-radius: 16px;
            border: 2px solid #c9ddf5;
            background-color: #ffffff;
            box-shadow: 0 2px 5px rgba(0,0,0,0.12);
            background-size: cover;
            background-position: center;
            transition: transform 0.15s;
        }
        .req-file-stack .req-stack-layer.layer-1 { transform: rotate(0deg) translate(0, 0); z-index: 3; }
        .req-file-stack .req-stack-layer.layer-2 { transform: rotate(7deg) translate(5px, 3px); z-index: 2; }
        .req-file-stack .req-stack-layer.layer-3 { transform: rotate(-9deg) translate(-5px, 4px); z-index: 1; }
        .req-file-stack-wrap:hover .req-stack-layer.layer-1 { transform: rotate(0deg) translate(0, -2px); }
        .req-file-stack-wrap:hover .req-stack-layer.layer-2 { transform: rotate(9deg) translate(6px, 0px); }
        .req-file-stack-wrap:hover .req-stack-layer.layer-3 { transform: rotate(-11deg) translate(-6px, 1px); }
        .req-stack-layer.req-stack-layer-pdf {
            display: flex; align-items: center; justify-content: center;
            background-color: #fef2f2; border-color: #fca5a5;
        }
        .req-stack-layer.req-stack-layer-pdf i { font-size: 46px; color: #dc2626; }
        /* Stack layer whose file could not be loaded — rendered as a neutral
           dashed tile instead of a blank white one (set by the probe JS). */
        .req-stack-layer.req-stack-layer-missing {
            background-color: #f8fafc;
            border-style: dashed;
            border-color: #cbd5e1;
            display: flex; align-items: center; justify-content: center;
        }
        .req-stack-layer.req-stack-layer-missing::after {
            content: '\f15b';
            font-family: 'Font Awesome 6 Free';
            font-weight: 900;
            font-size: 34px;
            color: #c3cedd;
        }
        .req-file-stack .req-stack-count-badge {
            position: absolute;
            bottom: 4px; right: 4px;
            background: var(--neust-maroon);
            color: var(--neust-gold);
            font-size: 11px; font-weight: 700;
            border-radius: 999px;
            min-width: 22px; height: 22px;
            display: flex; align-items: center; justify-content: center;
            padding: 0 6px;
            border: 2px solid #ffffff;
            z-index: 4;
        }
        .req-file-stack-label {
            font-size: 11px; color: #64748b; font-weight: 700;
        }

        /* NEW — prev/next navigation for paging through multiple files
           inside the compliance document preview modal. */
        .req-preview-nav-btn {
            position: absolute;
            top: 50%;
            transform: translateY(-50%);
            background: rgba(15,23,42,0.55);
            color: #ffffff;
            border: none;
            width: 40px; height: 40px;
            border-radius: 50%;
            font-size: 15px;
            cursor: pointer;
            display: flex; align-items: center; justify-content: center;
            transition: background 0.15s;
            z-index: 5;
        }
        .req-preview-nav-btn:hover { background: rgba(15,23,42,0.82); }
        .req-preview-nav-prev { left: 16px; }
        .req-preview-nav-next { right: 16px; }

        /* ══════════════════════════════════════════════════════════
           NEW (this adjustment) — global loading overlay, copied
           faithfully from admin_company_list.php's own
           #globalLoadingOverlay (same markup/CSS/JS pattern) so this
           company-facing page gets the same full-page "processing"
           popup during page load and while an in-page action (MOA
           revision submit, MOA creation submit) is being processed.
           Visible by default (covers the very first paint) and fades
           out automatically once the page finishes loading, or once
           every in-flight action that asked for it has completed — see
           showGlobalLoading()/hideGlobalLoading() further down.
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
            border-top-color: var(--neust-maroon, #1B2A4A);
            animation: globalLoadingSpin 0.85s linear infinite;
        }
        .global-loading-text {
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            font-size: 13px;
            font-weight: 700;
            color: var(--neust-maroon, #1B2A4A);
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

        /* ══════════════════════════════════════════════════════════
           ADJUSTMENT (action loading page) — success state of the full-page loader.
           Same look and wording pattern as admin_student_list.php's loading page
           (spinner → green check + message), plus a list of the AREAS that were
           updated. Used by showGlobalSuccess() in the script below.
           ══════════════════════════════════════════════════════════ */
        .global-loading-success { display: none; flex-direction: column; align-items: center; gap: 10px; text-align: center; max-width: 460px; width: calc(100vw - 40px); padding: 0 20px; box-sizing: border-box; }
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
        .gls-message { font-size: 13px; color: var(--grid-muted, #5B6478); line-height: 1.5; }
        .gls-message:empty { display: none; }
        .gls-areas { display: flex; flex-direction: column; gap: 8px; width: 100%; max-height: 34vh; overflow-y: auto; text-align: left; margin-top: 2px; }
        .gls-areas:empty { display: none; }
        .gls-area { background: #fff; border: 1px solid var(--grid-border-soft, #DCE1EC); border-left: 3px solid var(--grid-green, #2C5A2C); padding: 8px 12px; }
        .gls-area-title { font-size: 11px; font-weight: 700; color: var(--grid-navy, #1B2A4A); text-transform: uppercase; letter-spacing: 0.5px; }
        .gls-area-fields { display: flex; flex-wrap: wrap; gap: 5px; margin-top: 6px; }
        .gls-chip { font-size: 11px; font-weight: 600; color: var(--grid-green, #2C5A2C); background: var(--grid-green-bg, #EAF3EA); padding: 2px 8px; border-radius: 2px; }
        .gls-warn { width: 100%; box-sizing: border-box; text-align: left; font-size: 12px; line-height: 1.45; color: var(--grid-amber, #A0850A); background: var(--grid-amber-bg, #FAF3DC); border-left: 3px solid var(--grid-amber, #A0850A); padding: 8px 12px; }
        .gls-warn:empty { display: none; }
        .gls-sub { font-size: 11px; color: var(--grid-muted, #5B6478); opacity: .8; display: flex; align-items: center; gap: 6px; }
        .gls-sub:empty { display: none; }
        .gls-continue {
            margin-top: 4px; padding: 10px 28px; border-radius: 0; font-weight: 600; cursor: pointer;
            border: 1px solid var(--grid-navy, #1B2A4A); background: var(--grid-navy, #1B2A4A); color: #fff;
            text-transform: uppercase; letter-spacing: 0.5px; font-size: 12px; font-family: inherit;
        }
        .gls-continue:hover { background: #fff; color: var(--grid-navy, #1B2A4A); }
        @keyframes glsCheckPop { from { transform: scale(0.3); opacity: 0; } to { transform: scale(1); opacity: 1; } }

        /* ═══════════════════════════════════════════════════
           NEW (this adjustment) — LIVE UPDATES: brief highlight on a
           card that was just refreshed, a small "updated" toast, and
           the fallback banner shown only when a change can't be applied
           in place while the company is mid-edit.
           ═══════════════════════════════════════════════════ */
        @keyframes cfLiveFlash {
            0%   { box-shadow: 0 0 0 0 rgba(37, 99, 235, 0.45); }
            100% { box-shadow: 0 0 0 14px rgba(37, 99, 235, 0); }
        }
        .cf-live-flash { animation: cfLiveFlash 1.4s ease-out 1; }
        .cf-live-toast {
            position: fixed;
            right: 24px;
            bottom: 24px;
            z-index: 20000;
            display: flex;
            align-items: center;
            gap: 10px;
            max-width: 340px;
            padding: 12px 18px;
            border-radius: 12px;
            background: var(--neust-maroon);
            color: #ffffff;
            font-size: 13px;
            font-weight: 600;
            line-height: 1.4;
            box-shadow: 0 8px 24px rgba(15, 23, 42, 0.28);
            opacity: 0;
            transform: translateY(12px);
            pointer-events: none;
            transition: opacity 0.25s, transform 0.25s;
        }
        .cf-live-toast i { color: var(--neust-gold); font-size: 15px; flex-shrink: 0; }
        .cf-live-toast.show { opacity: 1; transform: translateY(0); }
        .cf-live-banner {
            position: fixed;
            left: 50%;
            bottom: 24px;
            transform: translateX(-50%);
            z-index: 20000;
            display: flex;
            align-items: center;
            gap: 12px;
            max-width: calc(100% - 32px);
            padding: 12px 18px;
            border-radius: 12px;
            background: #1e293b;
            color: #ffffff;
            font-size: 13px;
            font-weight: 600;
            box-shadow: 0 8px 24px rgba(15, 23, 42, 0.32);
        }
        .cf-live-banner i { color: var(--neust-gold); }
        .cf-live-banner button {
            background: var(--neust-gold);
            color: #1e293b;
            border: none;
            border-radius: 8px;
            padding: 7px 14px;
            font-size: 12.5px;
            font-weight: 700;
            cursor: pointer;
        }

        /* ══════════════════════════════════════════════════════════════
           ADJUSTMENT: REQUIREMENT CARD DESIGN — MATCHED TO AccomForm.php
           ------------------------------------------------------------
           The compliance requirement cards now use the same design as the
           Documentary Requirements cards in AccomForm.php: a flat gallery
           card with the document preview on top and the status pill in its
           top-right corner, a dashed "No file yet" / pink "Rejected" panel
           when there is nothing to show, then the requirement name, the
           rejection remark and a compact upload row underneath, plus the
           "N requirements · N verified …" summary line with a progress bar.
           Presentation only — every id, class, data attribute, input name,
           PHP branch and script hook the page uses is unchanged.
           ══════════════════════════════════════════════════════════════ */
        .cf-req-summary { display: flex; align-items: center; justify-content: space-between; flex-wrap: wrap; gap: 10px 16px; margin: 0 0 14px; }
        .cf-req-summary-text { font-size: 13px; color: #3E4963; }
        .cf-req-summary-text b { color: var(--neust-maroon); }
        .cf-progress { display: flex; align-items: center; gap: 10px; }
        .cf-progress-bar { width: 120px; height: 8px; background: #C9D3E6; overflow: hidden; }
        .cf-progress-fill { height: 8px; background: #2C5A2C; transition: width 0.3s ease; }
        .cf-progress-pct { font-size: 12px; color: #3E4963; white-space: nowrap; }

        .req-grid { grid-template-columns: repeat(auto-fill, minmax(190px, 1fr)); column-gap: 14px; row-gap: 14px; }
        .req-grid .compliance-req-item,
        .req-grid .compliance-req-item[data-req-status],
        .req-grid .compliance-req-item[data-req-status]:hover,
        .req-grid .compliance-req-item:hover {
            padding: 0; margin-bottom: 0; border: 1px solid #A3AFC7; border-radius: 0;
            background: #ffffff; box-shadow: 0 1px 3px rgba(27,42,74,0.16);
            overflow: hidden; display: flex; flex-direction: column;
        }
        .req-grid .compliance-req-item:last-child { margin-bottom: 0; }
        .req-grid .compliance-req-top { flex: 1; align-items: stretch; text-align: left; gap: 0; }

        /* order inside a card: preview → name → remark / notes → upload row (markup order is unchanged) */
        .compliance-req-top > .req-preview-wrap    { order: 0; }
        .compliance-req-top > .req-info            { order: 1; }
        .compliance-req-top > .compliance-req-info { order: 2; }
        .compliance-req-top > .compliance-req-action { order: 3; }

        .req-grid .compliance-req-item .req-info { font-size: 13.5px; font-weight: 700; color: #1B2A4A; letter-spacing: 0; line-height: 1.35; padding: 12px 12px 0; margin: 0; width: auto; }
        .req-grid .compliance-req-top > .req-info { margin-bottom: 0; }
        .req-grid .compliance-req-info { align-items: stretch; text-align: left; padding: 8px 12px 0; gap: 8px; width: auto; }
        .req-grid .compliance-req-action { padding: 8px 12px 12px; margin-top: auto; width: auto; }

        /* preview area — grey panel, picture fills it, status pill in the corner */
        .req-grid .req-preview-wrap,
        .req-grid .req-preview-wrap.req-preview-wrap--rejected,
        .req-grid .compliance-req-item.req-staged .req-preview-wrap.req-preview-wrap--rejected {
            position: relative; width: 100%; max-width: none; height: auto; min-height: 176px; flex: 1 0 176px;
            box-sizing: border-box; padding: 0; background: #E4EAF4; border-radius: 0;
            display: flex; align-items: center; justify-content: center;
        }
        .req-grid .req-preview-wrap .req-preview-slot,
        .req-grid .req-preview-wrap--rejected .req-preview-slot,
        .req-grid .compliance-req-item.req-staged .req-preview-wrap--rejected .req-preview-slot {
            position: absolute; inset: 0; width: auto; height: auto;
            display: flex; align-items: center; justify-content: center;
        }
        .req-grid .req-thumb-img,
        .req-grid .req-thumb-img:hover {
            position: absolute; top: 0; left: 0; width: 100%; height: 100%; margin: 0;
            border: none; border-radius: 0; object-fit: cover; object-position: top center;
            box-shadow: none; transform: none; background: transparent;
        }
        .req-grid .req-thumb-wrap { width: 110px; height: 110px; border-radius: 0; border: 1px solid #A3AFC7; box-shadow: none; }
        .req-grid .req-thumb-wrap:hover { transform: none; box-shadow: none; }
        .req-grid .compliance-req-item .req-thumb-img,
        .req-grid .compliance-req-item .req-thumb-wrap { border-color: #A3AFC7; }
        .req-grid .compliance-req-item .req-thumb-img { border: none; }

        .req-grid .req-thumb-empty,
        .req-grid .req-rej-placeholder {
            position: absolute; top: 14px; right: 14px; bottom: 14px; left: 14px; width: auto; height: auto;
            box-sizing: border-box; border-radius: 0; gap: 6px; flex-shrink: 1;
            font-size: 12px; font-weight: 600; line-height: 1.4; text-align: center;
        }
        .req-grid .req-thumb-empty { background: transparent; border: 1px dashed #A3AFC7; color: #3E4963; }
        .req-grid .req-thumb-empty i, .req-grid .req-thumb-empty i.fa-file-circle-xmark { font-size: 20px; color: #3E4963; }
        .req-grid .req-thumb-empty span { font-size: 12px; font-weight: 600; letter-spacing: 0; text-transform: none; }
        .req-grid .req-rej-placeholder { background: #F2D5D1; border: 1px dashed #D49A94; color: #A02A2A; gap: 8px; }
        .req-grid .req-rej-placeholder i { font-size: 24px; }

        /* status pill — every state (Verified / Pending / Rejected / Not Submitted) */
        .req-grid .req-card-ribbon { top: 10px; right: 10px; }
        .req-grid .req-rb { font-size: 11px; padding: 3px 9px; border-radius: 0; }
        .req-rb-verified { background: #D9E8D2; color: #2C5A2C; }
        .req-rb-pending  { background: #F3E7B5; color: #7A5A0B; }
        .req-rb-awaiting { background: #E4EAF4; color: #3E4963; }
        .req-rb-rejected { background: #F2D5D1; color: #A02A2A; }

        /* remark + staged note */
        .req-grid .req-card-remark { background: #F2D5D1; border: 1px solid #D49A94; border-radius: 0; color: #A02A2A; font-size: 12px; padding: 7px 10px; }
        .req-grid .req-staged-note { border-radius: 0; }

        /* upload row: [icon] label [Choose] — same as AccomForm's requirement cards */
        .req-grid .req-file-drop {
            border: 1px solid #A3AFC7; border-radius: 0; background: #ffffff;
            padding: 6px 6px 6px 10px; min-height: 0; gap: 6px; justify-content: flex-start; text-align: left;
        }
        .req-grid .req-file-drop::after {
            content: 'Choose'; margin-left: auto; flex-shrink: 0; white-space: nowrap;
            background: var(--neust-maroon); color: #ffffff; font-size: 11px; font-weight: 600;
            text-transform: uppercase; letter-spacing: 0.3px; padding: 6px 10px;
        }
        .req-grid .req-file-icon { font-size: 12px; color: #1B2A4A; }
        .req-grid .req-file-text { font-size: 11px; font-weight: 600; color: #1B2A4A; flex: 1; min-width: 0; }
        /* rejected requirement: the button label reads "Re-upload required" in red (until a replacement is chosen) */
        .req-grid .compliance-req-item[data-req-status="denied"] .req-file-text { color: #A02A2A; }
        .req-grid .compliance-req-item.req-staged .req-file-text { color: #1B2A4A; }
        .req-grid .req-file-drop.has-file { border-color: #2C5A2C; background: #EAF3EA; }
        .req-grid .verified-lock { border-radius: 0; font-size: 12px; padding: 8px 10px; }

        /* several saved / selected files — the layered card stack, squared like the rest */
        .req-grid .req-file-stack .req-stack-layer { border-radius: 0; border-color: #C3CADA; }


        /* ══ ADJUSTMENT: "Only One PDF Allowed" / "Mixed File Formats" popup — same design as AccomForm.php's ══ */
        .cf-pdf-overlay {
            display: none; position: fixed; top: 0; left: 0; width: 100%; height: 100%;
            background: rgba(0,0,0,0.5); justify-content: center; align-items: center; z-index: 10000;
            animation: fadeInModal 0.25s ease;
        }
        .cf-pdf-box {
            background: #ffffff; padding: 32px; border-radius: 0; border: 1px solid #dcdfe6;
            width: 420px; max-width: 92%; text-align: center; box-shadow: none;
            animation: popIn 0.3s ease;
        }
        .cf-pdf-title { font-size: 18px; font-weight: 700; color: #1e293b; margin: 0 0 8px; text-transform: uppercase; letter-spacing: 0.3px; }
        .cf-pdf-msg   { color: #5A6272; font-size: 14px; margin: 0 0 24px; line-height: 1.6; }
        .cf-pdf-list  {
            text-align: left; background: #EEF1F6; border: 1px solid #C3CADA; border-radius: 0;
            padding: 12px 16px 12px 32px; margin: 0 0 18px; font-size: 13px; color: #1B2A4A; line-height: 1.8;
        }
        .cf-pdf-btn {
            background: var(--neust-maroon); color: #ffffff; border: 1px solid var(--neust-maroon);
            padding: 10px 28px; border-radius: 0; font-weight: 600; font-size: 12px; cursor: pointer;
            text-transform: uppercase; letter-spacing: 0.4px; transition: opacity 0.2s; font-family: inherit;
        }
        .cf-pdf-btn:hover { opacity: 0.88; }
        .cf-pdf-btn:focus-visible { outline: 2px solid var(--neust-maroon); outline-offset: 2px; }
        /* ADJUSTMENT: small helper line under the file list of the file-size popup */
        .cf-pdf-list li.cf-pdf-tip { list-style: none; margin-left: -16px; margin-top: 4px; font-size: 12px; color: #5A6272; line-height: 1.5; }

        /* ══ ADJUSTMENT: TWO-PAGE LAYOUT (same pattern as AccomForm.php) ══
           "Company Information" (the input fields + their own Save button) and
           "Requirements" (MOA Document Status + Compliance Requirements + their
           own Submit button) are now separate pages switched by square tabs.
           Both pages still live inside the page's one <form>, so every field,
           name, id, handler and script is exactly as before. */
        .cf-page-switcher {
            display: flex; gap: 8px; margin-bottom: 30px;
            border-bottom: 1px solid #dcdfe6; padding-bottom: 14px;
            padding-right: 44px; /* room for the "i" info button */
            flex-wrap: wrap; align-items: center;
        }
        .cf-switch-page-btn {
            background: #fff; border: 1px solid #dcdfe6; padding: 10px 18px;
            font-size: 12px; font-weight: 600; color: var(--neust-maroon);
            cursor: pointer; border-radius: 0; text-transform: uppercase; letter-spacing: 0.4px;
            transition: background 0.2s, color 0.2s, opacity 0.2s;
            font-family: inherit; display: inline-flex; align-items: center;
        }
        .cf-switch-page-btn i { margin-right: 8px; }
        .cf-switch-page-btn.active { background: var(--neust-maroon); border-color: var(--neust-maroon); color: #fff; }
        .cf-switch-page-btn:not(.active):hover { background: #f3f4f7; color: var(--neust-maroon); }
        .cf-switch-page-btn:focus-visible { outline: 2px solid var(--neust-maroon); outline-offset: 2px; }
        .cf-page-content { display: none; animation: cfPageFade 0.25s ease-out; }
        .cf-page-content.cf-active-page { display: block; }
        @keyframes cfPageFade { from { opacity: 0; transform: translateY(6px); } to { opacity: 1; transform: translateY(0); } }
        .cf-info-save-row { margin-top: 24px; }
        @media (max-width: 768px) { .cf-switch-page-btn { flex: 1; justify-content: center; } }
        @media (prefers-reduced-motion: reduce) { .cf-page-content { animation: none; } }

    </style>
</head>
<body>

<!-- ══════════════════════════════════════════════════════════
     NEW (this adjustment): global loading/processing popup — same
     markup as admin_company_list.php's #globalLoadingOverlay. Visible
     by default (so it covers the page while assets are still loading),
     then hidden by JS once the window finishes loading. Also reused
     (shown/hidden) around the MOA revision/creation submit actions
     further down, so the company always sees a clear "processing"
     indicator that disappears automatically once the action completes.
     ══════════════════════════════════════════════════════════ -->
<div id="globalLoadingOverlay">
    <div class="global-loading-box">
        <div class="global-loading-spinner"></div>
        <div class="global-loading-text">
            <span id="globalLoadingLabel">Loading</span>
            <span class="global-loading-dots"><span>.</span><span>.</span><span>.</span></span>
        </div>
        <!-- ADJUSTMENT (action loading page): success state — check icon + message + the AREAS that were updated
             (same pattern as admin_student_list.php's success loading page). Shown by showGlobalSuccess(). -->
        <div class="global-loading-success" id="globalLoadingSuccess" role="status" aria-live="polite">
            <div class="gls-check"><i class="fas fa-check"></i></div>
            <div class="gls-title" id="globalLoadingSuccessTitle">Success</div>
            <div class="gls-message" id="globalLoadingSuccessMsg"></div>
            <div class="gls-areas" id="globalLoadingSuccessAreas"></div>
            <div class="gls-warn" id="globalLoadingSuccessWarn"></div>
            <div class="gls-sub" id="globalLoadingSuccessSub"></div>
            <button type="button" class="gls-continue" id="globalLoadingContinueBtn">Continue</button>
        </div>
    </div>
</div>


<!-- TEMP DIAGNOSTIC (safe to delete once the MOA upload item is confirmed
     showing correctly for "Existing" companies of both classifications):
     raw and normalized request_type values for THIS logged-in company. -->
<!-- moa_request_type raw="<?= htmlspecialchars(var_export($moa_request_type_debug_raw, true)) ?>" norm="<?= htmlspecialchars($moa_request_type_norm) ?>" current_type="<?= htmlspecialchars($current_type) ?>" showMoaSection="<?= $showMoaSection ? 'true' : 'false' ?>" moaUploadItemAppended="<?= array_key_exists('moa_existing_upload', $reqDefsForType) ? 'true' : 'false' ?>" -->
<?php if (isset($_GET['debug_moa'])): ?>
<div style="background:#111827;color:#facc15;font:12px/1.6 monospace;padding:10px 16px;position:relative;z-index:20000;">
    DEBUG &mdash;
    request_type raw: <?= htmlspecialchars(var_export($moa_request_type_debug_raw, true)) ?> |
    normalized: "<?= htmlspecialchars($moa_request_type_norm) ?>" |
    current_type: "<?= htmlspecialchars($current_type) ?>" |
    showMoaSection: <?= $showMoaSection ? 'true' : 'false' ?> |
    moa_existing_upload appended: <?= array_key_exists('moa_existing_upload', $reqDefsForType) ? 'true' : 'false' ?> |
    reqDefsForType keys: <?= htmlspecialchars(implode(', ', array_keys($reqDefsForType))) ?>
</div>
<?php endif; ?>

<div id="imagePreviewModal" style="
    display:none;
    position:fixed;
    top:0;
    left:0;
    width:100%;
    height:100%;
    background:rgba(0,0,0,0.85);
    justify-content:center;
    align-items:center;
    z-index:9999;
">
    <span onclick="closePreview()" style="
        position:absolute;
        top:20px;
        right:40px;
        font-size:45px;
        color:white;
        cursor:pointer;
        font-weight:bold;
    ">&times;</span>
    <img id="previewImage" style="
        max-width:90%;
        max-height:90%;
        border-radius:12px;
        box-shadow:0 0 25px rgba(0,0,0,0.8);
    ">
</div>

<!-- MOA DOCUMENT PREVIEW MODAL (REDESIGNED — full-bleed dark viewer
     mirroring the document preview design used in moa_request.php's
     uploaded-file viewer / the admin blob-preview modal) -->
<div id="moaDocPreviewModal" class="moa-doc-modal">
    <div class="moa-doc-modal-bar">
        <div class="moa-doc-modal-title">
            <i class="fas fa-file-signature moa-doc-modal-icon"></i>
            <span>MOA Document &mdash;</span>
            <span class="moa-doc-modal-name" id="moaDocPreviewName">MOA Document</span>
        </div>
        <button onclick="closeOwnMoaPreview()" class="moa-doc-modal-close" title="Close">&times;</button>
    </div>
    <div class="moa-doc-modal-viewer" id="moaDocPreviewViewerWrap"></div>
</div>

<!-- COMPLIANCE REQUIREMENT DOCUMENT PREVIEW MODAL (mirrors the MOA
     document preview modal design above, reused for any classification
     compliance document — now supports paging through multiple files via
     the counter span and prev/next arrows added by JS below). -->
<div id="reqDocPreviewModal" class="moa-doc-modal">
    <div class="moa-doc-modal-bar">
        <div class="moa-doc-modal-title">
            <i class="fas fa-file-alt moa-doc-modal-icon" id="reqDocPreviewIcon"></i>
            <span id="reqDocPreviewName">Document</span>
            <span id="reqDocPreviewCounter" style="color:#94a3b8;font-size:12px;font-weight:600;"></span>
        </div>
        <button onclick="closeReqDocPreview()" class="moa-doc-modal-close" title="Close">&times;</button>
    </div>
    <div class="moa-doc-modal-viewer" id="reqDocPreviewViewerWrap" style="position:relative;"></div>
</div>

<!-- ── NEW (this adjustment) — "Preview MOA" modal for the MOA Initial
     Creation panel, built live (server-side, via the preview_new_moa
     endpoint near the top of this file) from whatever the company has
     currently typed into the unlocked fields. Reuses the exact same
     .moa-doc-modal / .moa-doc-modal-bar / .moa-doc-modal-viewer chrome as
     every other preview modal on this page, mirroring
     company_register.php's own "Preview MOA" modal for its Step 2
     "Request New MOA" flow. The target iframe is what a hidden form
     (built in openMoaCreatePreview() further down) POSTs into — the
     classic "submit a form into a named iframe" technique, so no fetch/
     AJAX/CORS plumbing is needed. ── -->
<div id="moaCreatePreviewModal" class="moa-doc-modal">
    <div class="moa-doc-modal-bar">
        <div class="moa-doc-modal-title">
            <i class="fas fa-file-contract moa-doc-modal-icon"></i>
            <span>MOA Preview</span>
        </div>
        <button type="button" onclick="closeMoaCreatePreview()" class="moa-doc-modal-close" title="Close">&times;</button>
    </div>
    <div class="moa-doc-modal-viewer">
        <iframe name="moaCreatePreviewTargetFrame" id="moaCreatePreviewTargetFrame" title="MOA Preview"></iframe>
    </div>
</div>

<!-- COMPLIANCE DOCUMENT UPLOAD ERROR POPUP (NEW) -->
<div id="complianceErrorModal" class="modal" style="z-index:10000;">
    <div class="modal-content">
        <?php /* ADJUSTMENT (this revision): emoji icon removed from this popup. */ ?>
        <?php /* ADJUSTMENT: uses the page's standard popup look (.notif-modal-title / -msg / -btn, same as the "Submission Successful" popup) instead of the amber warning style. */ ?>
        <p class="notif-modal-title">Upload Problem</p>
        <p class="notif-modal-msg" id="complianceErrorMsg">Some documents could not be uploaded.</p>
        <button id="closeComplianceErrorModal" class="notif-modal-btn">OK, I'll fix it</button>
    </div>
</div>
   
<!-- ADJUSTMENT: PDF-limit popup ("Only One PDF Allowed" / "Mixed File Formats Not Allowed") — same design as AccomForm.php's -->
<div id="cfPdfLimitModal" class="cf-pdf-overlay">
    <div class="cf-pdf-box">
        <p class="cf-pdf-title" id="cfPdfLimitTitle">Only One PDF Allowed</p>
        <p class="cf-pdf-msg" id="cfPdfLimitMsg"></p>
        <ul class="cf-pdf-list" id="cfPdfLimitList"></ul>
        <button type="button" id="closeCfPdfLimit" class="cf-pdf-btn">OK, Fix It</button>
    </div>
</div>

<!-- ADJUSTMENT: requirement-status popup ("Great Job!" / "Let's Fix This Together" / "Under Review") — same design and wording
     as AccomForm.php's status popup. Reuses the page's .cf-pdf-* square popup box; filled and shown by the live-updates script. -->
<div id="cfStatusChangedModal" class="cf-pdf-overlay" role="dialog" aria-modal="true" aria-labelledby="cfStatusChangedTitle">
    <div class="cf-pdf-box">
        <p class="cf-pdf-title" id="cfStatusChangedTitle">Requirement Status Updated</p>
        <p class="cf-pdf-msg" id="cfStatusChangedMsg">A requirement status has been updated by the administrator.</p>
        <button type="button" id="closeCfStatusChanged" class="cf-pdf-btn">OK</button>
    </div>
</div>

<!-- ADJUSTMENT (this revision): popup opened by the new "i" info button in the
     top-right corner of the form card. Holds the Classification notice and, only
     when applicable, the MOA-creation notice — same conditional logic as before,
     just relocated here instead of being shown inline on the page. Rendered
     unconditionally (unlike the profile popup below it) so the info button always
     works regardless of profile-completion state. -->
<div id="cfInfoModal" class="modal">
    <div class="modal-content" style="text-align:left; width:440px;">
        <button type="button" onclick="document.getElementById('cfInfoModal').style.display='none'" title="Close" style="position:absolute; top:14px; right:14px; width:30px; height:30px; border-radius:50%; border:1px solid #e2e8f0; background:#f8fafc; color:#4a5568; font-size:18px; line-height:1; cursor:pointer; display:flex; align-items:center; justify-content:center;">&times;</button>
        <div class="type-banner" style="margin-bottom: <?= $moa_needs_initial_creation ? '15px' : '0' ?>;">
            <i class="fas fa-info-circle"></i>
            <span><strong>Classification:</strong> <?= ucfirst($current_type) ?> (Controlled by Administrator)</span>
        </div>
        <?php if ($moa_needs_initial_creation): ?>
        <div class="moa-creation-banner" style="margin-bottom:0;">
            <i class="fas fa-unlock"></i>
            <span>Your MOA hasn't been created yet. Please fill in the fields below, then click <strong>Create MOA</strong> at the bottom of this section.</span>
        </div>
        <?php endif; ?>
    </div>
</div>

<!-- PROFILE POPUP FORM -->
    <?php if($show_profile_popup): ?>
        <div id="profileModal" class="modal">
            <div class="modal-content">
                <!-- ADJUSTMENT (this revision): layout/design ported from admin_company_list.php's manual
                     "Add New Company" modal — flat bordered box, uppercase title with a building icon (the
                     emoji is gone), compact 3-column grid, and a sticky action bar. There is deliberately NO
                     close button: this popup must still be completed (outside clicks still show the
                     "Profile Required" warning). Every id / name / handler below is unchanged. -->
                <div class="profile-modal-header">
                    <h3><i class="fas fa-building"></i> Company Contact Profile</h3>
                    <p class="modal-subtitle">Please complete your profile </p>
                </div>
                <form id="profileForm">
                    <div class="profile-grid">
                    <div class="profile-field">
                        <label>Full Name</label>
                        <input type="text" name="full_name" value="<?= htmlspecialchars($full_name) ?>" readonly>
                    </div>
                    <div class="profile-field span-2">
                        <label>Company Name</label>
                        <input type="text" name="company_name" value="<?= htmlspecialchars($company) ?>" readonly>
                    </div>
                    <div class="profile-field span-2">
                        <label>Email Address</label>
                        <input type="email" name="email" value="<?= htmlspecialchars($user_email) ?>" readonly>
                    </div>
                    <div class="profile-field">
                        <label>Telephone / Contact Number</label>
                        <input type="tel" name="telephone" value="<?= htmlspecialchars($telephone) ?>" readonly>
                    </div>
                    <div class="profile-field span-3">
                        <label>Facebook Page Link <span class="required">*</span></label>
                        <input type="url" name="facebook_link" placeholder="https://facebook.com/yourpage" required>
                        <button type="button" class="help-toggle-btn" onclick="toggleHelp('help-fb')">
                            <i class="fas fa-question-circle"></i> How to get your Facebook Page Link
                        </button>
                        <div class="help-panel" id="help-fb">
                            <h5><i class="fas fa-facebook" style="color:#1877f2;"></i> Getting Your Facebook Page Link</h5>
                            <div class="help-step">
                                <div class="help-step-num">1</div>
                                <div class="help-step-text">
                                    Go to <strong>facebook.com</strong> and log in to your account.
                                    <img src="guide_images/fb_step1.jpg" class="help-step-img" alt="Step 1" onerror="this.style.display='none'">
                                </div>
                            </div>
                            <div class="help-step">
                                <div class="help-step-num">2</div>
                                <div class="help-step-text">
                                    Navigate to your <strong>company's Facebook Page</strong> (not your personal profile).
                                    <img src="guide_images/fb_step2.jpg" class="help-step-img" alt="Step 2" onerror="this.style.display='none'">
                                </div>
                            </div>
                            <div class="help-step">
                                <div class="help-step-num">3</div>
                                <div class="help-step-text">
                                    Look at the <strong>URL bar</strong> in your browser. Copy the full link — it looks like: <code style="background:#e0e7ff;padding:2px 5px;border-radius:4px;">https://www.facebook.com/YourCompanyPage</code>
                                    <img src="guide_images/fb_step3.jpg" class="help-step-img" alt="Step 3" onerror="this.style.display='none'">
                                </div>
                            </div>
                            <div class="help-step">
                                <div class="help-step-num">4</div>
                                <div class="help-step-text">
                                    Paste that copied URL into the <strong>Facebook Page Link</strong> field above.
                                    <img src="guide_images/fb_step4.jpg" class="help-step-img" alt="Step 4" onerror="this.style.display='none'">
                                </div>
                            </div>
                            <div class="help-redirect-box">
                                <p>Ready to find your Facebook Page?</p>
                                <button type="button" class="help-redirect-btn" onclick="goToFacebook()">
                                    <i class="fas fa-external-link-alt"></i> Go to Facebook
                                </button>
                            </div>
                        </div>
                    </div>
                    <div class="profile-field span-3">
                        <label>Google Maps Embed Link <span class="required">*</span></label>
                        <input type="text" id="google_map_link" name="google_map_link" placeholder="Paste iframe src or embed URL" required>
                        <button type="button" class="help-toggle-btn" onclick="toggleHelp('help-gmap')">
                            <i class="fas fa-question-circle"></i> How to get your Google Maps Embed Link
                        </button>
                        <div class="help-panel" id="help-gmap">
                            <h5><i class="fas fa-map-marker-alt" style="color:#ea4335;"></i> Getting Your Google Maps Embed Link</h5>
                            <div class="help-step">
                                <div class="help-step-num">1</div>
                                <div class="help-step-text">
                                    Go to <strong>Google Maps</strong> (maps.google.com) and search for your company's location.
                                    <img src="guide_images/gmap_step1.jpg" class="help-step-img" alt="Step 1" onerror="this.style.display='none'">
                                </div>
                            </div>
                            <div class="help-step">
                                <div class="help-step-num">2</div>
                                <div class="help-step-text">
                                    Click the <strong>Share</strong> button (the icon that looks like an arrow pointing out of a box).
                                    <img src="guide_images/gmap_step2.jpg" class="help-step-img" alt="Step 2" onerror="this.style.display='none'">
                                </div>
                            </div>
                            <div class="help-step">
                                <div class="help-step-num">3</div>
                                <div class="help-step-text">
                                    In the Share dialog, click the <strong>"Embed a map"</strong> tab.
                                    <img src="guide_images/gmap_step3.jpg" class="help-step-img" alt="Step 3" onerror="this.style.display='none'">
                                </div>
                            </div>
                            <div class="help-step">
                                <div class="help-step-num">4</div>
                                <div class="help-step-text">
                                    Click <strong>"Copy HTML"</strong>. You'll get an <code style="background:#fce8e8;padding:2px 5px;border-radius:4px;">&lt;iframe src="..."&gt;</code> code. Paste the entire code or just the URL from the <code>src="..."</code> part into the field above.
                                    <img src="guide_images/gmap_step4.jpg" class="help-step-img" alt="Step 4" onerror="this.style.display='none'">
                                </div>
                            </div>
                            <div class="help-redirect-box">
                                <p>Ready to find your company on Google Maps?</p>
                                <button type="button" class="help-redirect-btn" onclick="goToGoogleMaps()">
                                    <i class="fas fa-external-link-alt"></i> Go to Google Maps
                                </button>
                            </div>
                        </div>
                    </div>
                    </div><!-- /.profile-grid -->
                    <div class="profile-modal-actions">
                        <button type="submit" class="modal-btn">Save Profile</button>
                    </div>
                </form>
            </div>
        </div>
    <?php endif; ?>
    <!-- VERIFIED REQUIREMENTS POPUP -->
    <div id="verifiedModal" class="modal">
        <div class="modal-content">
            <?php /* ADJUSTMENT (this revision): emoji icon removed from this popup. */ ?>
            <p class="notif-modal-title">All Requirements Verified!</p>
            <p class="notif-modal-msg">Your company requirements have been fully verified by the administrator. No further action is needed.</p>
            <button id="closeVerifiedModal" class="notif-modal-btn">Great, Thanks!</button>
        </div>
    </div>

    <!-- SUBMISSION SUCCESS POPUP -->
    <div id="submittedModal" class="modal">
        <div class="modal-content">
            <?php /* ADJUSTMENT (this revision): the emoji icon that used to sit above the title of this popup has been removed. */ ?>
            <p class="notif-modal-title">Submission Successful!</p>
            <p class="notif-modal-msg">Your company information has been updated successfully.</p>
            <button id="closeSubmittedModal" class="notif-modal-btn">OK, Got it!</button>
        </div>
    </div>

    <!-- OUTSIDE-CLICK WARNING POPUP -->
    <div id="warnModal" class="modal" style="z-index:10000;">
        <div class="modal-content">
            <?php /* ADJUSTMENT (this revision): emoji icon removed from this popup. */ ?>
            <p class="warn-modal-title">Profile Required</p>
            <p class="warn-modal-msg">Please complete your company profile before continuing. Fill in your Facebook Page Link and Google Maps Embed Link to proceed.</p>
            <button id="closeWarnModal" class="warn-modal-btn">OK, I'll complete it</button>
        </div>
    </div>

    <!-- REDIRECT CONFIRMATION POPUP -->
    <div id="redirectModal" class="modal" style="z-index:10001;">
        <div class="modal-content">
            <?php /* ADJUSTMENT (this revision): emoji icon removed from this popup (showRedirectModal() below no longer needs it). */ ?>
            <p class="redirect-modal-title" id="redirectModalTitle">You're leaving this page</p>
            <p class="redirect-modal-msg" id="redirectModalMsg">You will be redirected in a new tab. Your form data will be preserved when you return.</p>
            <div class="redirect-modal-actions">
                <button class="redirect-modal-cancel" id="redirectModalCancel">Cancel</button>
                <button class="redirect-modal-go" id="redirectModalGo">
                    <i class="fas fa-external-link-alt"></i> Continue
                </button>
            </div>
        </div>
    </div>

    <!-- PROFILE SAVED SUCCESS POPUP -->
    <div id="profileSavedModal" class="modal" style="z-index:10002;">
        <div class="modal-content">
            <?php /* ADJUSTMENT (this revision): emoji icon removed from this popup. */ ?>
            <p class="notif-modal-title">Profile Saved!</p>
            <p class="notif-modal-msg">Your company contact profile has been saved successfully. You will be redirected to your Profile page now.</p>
            <button id="profileSavedGoBtn" class="notif-modal-btn">
                <i class="fas fa-arrow-right" style="margin-right:6px;"></i>Go to Profile
            </button>
        </div>
    </div>

<div id="sidebar" class="sidebar">
    <div class="sidebar-header">
        <h2 id="sidebarTitle">Requirements</h2>
        <button id="toggleBtn" class="toggle-btn"><i class="fas fa-bars"></i></button>
    </div>

    <?php
    // ════════════════════════════════════════════════════════════════
    // SIDEBAR LOCK — same logic as AccomForm.php.
    //
    // Until every requirement is Verified ($sidebar_unlocked, computed
    // near the top of this file):
    //   • a "Some pages are locked…" notice is shown under the header, and
    //   • only "Requirements" stays available — every other link
    //     (My Profile, OJT Student List, Attendance Management, Company
    //     Reports) is not rendered at all.
    // Once everything is Verified the notice disappears and the full
    // sidebar shows, exactly as before. (This replaces the old temporary
    // `if (true)` override that showed every link regardless.)
    // ════════════════════════════════════════════════════════════════
    ?>
    <!-- LIVE UPDATES: the lock notice + links are one swappable region (display:contents keeps the sidebar layout identical). -->
    <div id="cfSidebarLockRegion" data-live-sig="<?= $cfSidebarSig ?>" style="display:contents;">
    <?php if (!$sidebar_unlocked): ?>
    <div class="sidebar-lock-notice">
        <div class="sidebar-lock-notice-inner">
            <i class="fas fa-lock"></i>
            <p>Some pages are locked until all requirements are verified by the administrator.</p>
        </div>
    </div>
    <?php endif; ?>

    <div class="sidebar-links" id="sidebarLinksContainer">
        <?php if ($sidebar_unlocked): ?>
        <a href="Profile.php"><i class="fas fa-user-circle"></i><span class="link-text">My Profile</span></a>
        <a href="add_ojt_student.php">
            <i class="fas fa-user-graduate"></i>
            <span class="link-text">OJT Student List</span>
            <?php if ($inbox_count > 0): ?>
                <span class="sidebar-badge"><?= $inbox_count ?></span>
            <?php endif; ?>
        </a>
        <?php endif; ?>

        <a href="CompanyForm.php" class="active"><i class="fas fa-file-contract"></i><span class="link-text">Requirements</span></a>
        <?php if ($sidebar_unlocked): ?>
        <a href="attendance_management.php"><i class="fas fa-building"></i>
            <span class="link-text">Attendance Management</span>
            <?php if ($pending_lr_count > 0): ?>
                <span class="sidebar-badge-late"><?= $pending_lr_count ?></span>
            <?php endif; ?>
        </a>
        <a href="company_reports.php">
            <i class="fas fa-chart-bar"></i>
            <span class="link-text">Company Reports</span>
            <?php if ($ungraded_count > 0): ?>
                <span class="sidebar-badge-ungraded"><?= $ungraded_count ?></span>
            <?php endif; ?>
        </a>
        <?php endif; ?>
    </div>
    </div><!-- /#cfSidebarLockRegion -->

    <div class="logout-link">
        <a href="login.php">
            <i class="fas fa-sign-out-alt"></i>
            <span class="link-text">Logout</span>
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
    </nav>

    <div class="container">
        <div class="form-card">
            <!-- ADJUSTMENT (this revision): the "Company Requirements Submission" title label
                 has been removed entirely per request. The Classification banner and the
                 MOA-creation notice (further below) are no longer shown inline — both are now
                 surfaced through the "i" info button in the top-right corner of this card,
                 which opens the #cfInfoModal popup defined right after this form. Nothing else
                 about the surrounding markup, logic, or styling has changed. -->
            <button type="button" class="cf-info-btn" onclick="document.getElementById('cfInfoModal').style.display='flex'" title="Form Information" aria-label="Form Information">
                <i class="fas fa-info-circle"></i>
            </button>

            <form action="" method="POST" enctype="multipart/form-data" id="cfMainForm">

                <?php
                // ADJUSTMENT: which page opens first — the Requirements page when the administrator
                // flagged the MOA for revision (that panel lives there), otherwise Company Information.
                $cfInitialPage = !empty($moa_needs_revision) ? 'cf-requirements-page' : 'cf-info-page';
                ?>
                <div class="cf-page-switcher">
                    <button type="button" class="cf-switch-page-btn" data-page="cf-info-page">
                        <i class="fas fa-building"></i> Company Information
                    </button>
                    <button type="button" class="cf-switch-page-btn" data-page="cf-requirements-page">
                        <i class="fas fa-clipboard-check"></i> Requirements
                    </button>
                </div>

                <div id="cf-info-page" class="cf-page-content">

                <?php
                // ── NEW (this adjustment): computed once, used by every
                // field below — see $moa_needs_initial_creation's docblock
                // above (near $moaRevisionFieldMeta) for the full
                // explanation of when/why these fields unlock. When false
                // (the normal case for every existing company), every
                // field renders exactly as it always has — readonly, grey
                // background — with no behavior change whatsoever.
                $cfFieldLockAttr  = $moa_needs_initial_creation ? '' : 'readonly';
                $cfFieldLockStyle = $moa_needs_initial_creation ? '' : 'background:#f0f2f5;color:#555;cursor:not-allowed;';
                ?>

                <?php
                // ── NEW (this adjustment): for "Existing" request-type
                // companies, tell them, up front, if any of their profile
                // fields are still blank and can now be filled in directly
                // below (unlocked per-field — see cfFieldLockFor() above).
                // Purely informational; computed here so it can run before
                // the fields themselves are rendered further down. Never
                // shown for "New" request-type companies ($moa_is_existing_request
                // is only ever true for "Existing"), and never shown once
                // every field already has a value on file.
                $cfExistingHasEmptyFields = $moa_is_existing_request && (
                    cfIsFieldEmpty($company) || cfIsFieldEmpty($company_address) ||
                    cfIsFieldEmpty($contact_first) || cfIsFieldEmpty($contact_last) ||
                    cfIsFieldEmpty($contact_middle, true) || cfIsFieldEmpty($position) ||
                    cfIsFieldEmpty($telephone) || cfIsFieldEmpty($company_profile)
                );
                ?>
                <?php if ($cfExistingHasEmptyFields): ?>
                <div class="moa-creation-banner">
                    <i class="fas fa-unlock"></i>
                    <span>Some of your company profile fields are still blank. Any blank field below is now unlocked — please fill it in, then click <strong>Save Changes</strong> at the bottom of this page to save it.</span>
                </div>
                <?php elseif ($moa_is_existing_request): ?>
                <?php // ADJUSTMENT (this update): every field is unlocked for "Existing" companies. ?>
                <div class="moa-creation-banner">
                    <i class="fas fa-unlock"></i>
                    <span>Your company profile fields are unlocked. You can update them below, then click <strong>Save Changes</strong> at the bottom of this page to save your changes.</span>
                </div>
                <?php elseif (!$moa_needs_initial_creation): ?>
                <?php // NEW (this adjustment): fields not printed on the MOA are editable — see $cfNonMoaFieldLock below. ?>
                <div class="moa-creation-banner">
                    <i class="fas fa-unlock"></i>
                    <span>Fields that appear on your MOA are locked. <strong>Telephone / Contact Number</strong> and <strong>Company Profile / Brief Description</strong> are not part of the MOA, so you can update them below, then click <strong>Save Changes</strong> at the bottom of this page to save your changes.</span>
                </div>
                <?php endif; ?>

                <?php // ── NEW (this adjustment): each field below is now wrapped in
                // `!isset($moaFlaggedFieldSet[...])` — when the administrator has
                // flagged that specific field for revision, this top read-only copy
                // is hidden entirely instead of sitting there confusingly blank,
                // since the MOA Revision Compliance panel inside "MOA Document
                // Status" further down already shows an editable input for that
                // exact field. It reappears here automatically the moment the
                // revision is resolved (the next page load after a successful
                // submit, once moa_needs_revision/moa_flagged_fields are cleared). ?>

                <?php
                $cfShowContactFirst   = !isset($moaFlaggedFieldSet['contact_first_name']);
                $cfShowContactLast    = !isset($moaFlaggedFieldSet['contact_last_name']);
                $cfShowContactMiddle  = !isset($moaFlaggedFieldSet['contact_middle_name']);
                $cfShowCompanyName    = !isset($moaFlaggedFieldSet['company_name']);
                $cfShowCompanyAddress = !isset($moaFlaggedFieldSet['company_address']);
                $cfShowPosition       = !isset($moaFlaggedFieldSet['position']);
                $cfShowTelephone      = !isset($moaFlaggedFieldSet['telephone']);
                $cfShowCompanyProfile = !isset($moaFlaggedFieldSet['company_profile']);

                // NEW (layout adjustment): combined "Contact Full Name" value,
                // for DISPLAY only, used in the locked (read-only) state below
                // to match the requested reference layout. The three real
                // contact_first_name / contact_middle_initial / contact_last_name
                // fields still exist underneath (as hidden inputs, same IDs/
                // names as always) and are what actually gets submitted/read by
                // JS (submitMoaCreation(), openMoaCreatePreview()) — so no
                // database column, POST field name, or script changes at all.
                // The middle name is left out of the merged text (same as it's
                // left out of the page entirely) whenever it's individually
                // flagged for revision.
                $cfContactFullName = trim(
                    $contact_first . ' ' .
                    (($cfShowContactMiddle && $contact_middle !== 'N/A') ? $contact_middle . ' ' : '') .
                    $contact_last
                );
                ?>

                <?php if ($moa_needs_initial_creation): ?>
                <!-- ── UPDATED (this adjustment) — editable "MOA Initial
                     Creation" state now uses the SAME row layout/grouping as
                     the reference layout further below (the one used once
                     every section is flagged for revision on an "Existing"
                     company): Contact First / Middle / Last / Position /
                     Telephone together in one row, then Company Name /
                     Complete Office Address together in a second row, then
                     Company Profile full-width — using the same
                     .moa-revision-fields / .moa-revision-grid classes and
                     --moa-cols column rule (up to 3 columns; 4 fields split
                     2 + 2) as that reference layout, so both states look and
                     space identically.
                     First / Middle / Last Name remain separate,
                     individually-editable inputs — exactly as before (no
                     merge into a read-only "Contact Full Name" here, since
                     the company is still typing these in for the very first
                     time). This is purely a visual rearrangement of the
                     exact same fields/values/ids/names that were already
                     here — nothing removed, renamed, or changed in what
                     gets submitted. -->
                <?php
                $cfInitRevColsFor = function ($n) { return ($n <= 3) ? max(1, $n) : (($n === 4) ? 2 : 3); };
                $cfInitRow1Count = (int) $cfShowContactFirst + (int) $cfShowContactMiddle + (int) $cfShowContactLast + (int) $cfShowPosition + (int) $cfShowTelephone;
                $cfInitRow2Count = (int) $cfShowCompanyName + (int) $cfShowCompanyAddress;
                $cfInitRow1Open = '<div class="moa-revision-grid" style="--moa-cols:' . (int) $cfInitRevColsFor($cfInitRow1Count) . ';">';
                $cfInitRow2Open = '<div class="moa-revision-grid" style="--moa-cols:' . (int) $cfInitRevColsFor($cfInitRow2Count) . ';">';
                ?>

                <div class="moa-revision-fields">

                <?php if ($cfShowContactFirst || $cfShowContactMiddle || $cfShowContactLast || $cfShowPosition || $cfShowTelephone): ?>
                <?= $cfInitRow1Open ?>
                    <?php if ($cfShowContactFirst): ?>
                    <div class="form-group">
                        <label>Contact First Name</label>
                        <input type="text" id="cfField_contact_first_name" name="contact_first_name" value="<?= htmlspecialchars($contact_first) ?>" <?= $cfFieldLockAttr ?> style="<?= $cfFieldLockStyle ?>">
                    </div>
                    <?php endif; ?>
                    <?php if ($cfShowContactMiddle): ?>
                    <div class="form-group">
                        <label>Contact Middle Name</label>
                        <input type="text" id="cfField_contact_middle_initial" name="contact_middle_initial"
                            value="<?= htmlspecialchars($contact_middle) ?>"
                            <?= $cfFieldLockAttr ?> style="<?= $cfFieldLockStyle ?>">
                    </div>
                    <?php endif; ?>
                    <?php if ($cfShowContactLast): ?>
                    <div class="form-group">
                        <label>Contact Last Name</label>
                        <input type="text" id="cfField_contact_last_name" name="contact_last_name" value="<?= htmlspecialchars($contact_last) ?>" <?= $cfFieldLockAttr ?> style="<?= $cfFieldLockStyle ?>">
                    </div>
                    <?php endif; ?>
                    <?php if ($cfShowPosition): ?>
                    <div class="form-group">
                        <label>Position / Designation</label>
                        <input type="text" id="cfField_position" name="position" value="<?= htmlspecialchars($position) ?>" placeholder="e.g. HR Manager, Training Officer" <?= $cfFieldLockAttr ?> style="<?= $cfFieldLockStyle ?>">
                    </div>
                    <?php endif; ?>
                    <?php if ($cfShowTelephone): ?>
                    <div class="form-group">
                        <label>Telephone / Contact Number</label>
                        <input type="tel" id="cfField_telephone" name="telephone" value="<?= htmlspecialchars($telephone) ?>" <?= $cfFieldLockAttr ?> style="<?= $cfFieldLockStyle ?>">
                    </div>
                    <?php endif; ?>
                </div>
                <?php endif; ?>

                <?php if ($cfShowCompanyName || $cfShowCompanyAddress): ?>
                <?= $cfInitRow2Open ?>
                    <?php if ($cfShowCompanyName): ?>
                    <div class="form-group">
                        <label>Company Name</label>
                        <input type="text" id="cfField_company" name="company" value="<?= htmlspecialchars($company) ?>" <?= $cfFieldLockAttr ?> style="<?= $cfFieldLockStyle ?>">
                    </div>
                    <?php endif; ?>
                    <?php if ($cfShowCompanyAddress): ?>
                    <div class="form-group">
                        <label>Complete Office Address</label>
                        <input type="text" id="cfField_company_address" name="company_address" value="<?= htmlspecialchars($company_address) ?>" <?= $cfFieldLockAttr ?> style="<?= $cfFieldLockStyle ?>">
                    </div>
                    <?php endif; ?>
                </div>
                <?php endif; ?>

                <!-- NEW: Company Profile / Brief Description — auto-filled from
                     company_information.company_profile (the text the company
                     typed at registration). Read-only, matching every other
                     field in this section, since it's the company's official
                     on-file description rather than something editable here.
                     ── UPDATED (this adjustment): unlocked, same as every
                     field above, while $moa_needs_initial_creation is true;
                     hidden entirely while flagged for revision (see above). -->
                <?php if ($cfShowCompanyProfile): ?>
                <div class="moa-revision-grid" style="--moa-cols:1;">
                <div class="form-group">
                    <label>Company Profile / Brief Description</label>
                    <textarea id="cfField_company_profile" name="company_profile" <?= $cfFieldLockAttr ?> rows="4"
                        style="width:100%;padding:12px;border:1px solid #e2e8f0;border-radius:8px;font-size:14px;box-sizing:border-box;<?= $cfFieldLockStyle ?>font-family:'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;resize:vertical;"
                    ><?= htmlspecialchars($company_profile) ?></textarea>
                </div>
                </div>
                <?php endif; ?>

                </div><!-- /.moa-revision-fields -->

                <?php else: ?>
                <!-- LIVE UPDATES: the read-only company info is one swappable region (a field the administrator flags for revision hides here). -->
                <div id="cfCompanyInfoRegion" data-live-sig="<?= $cfInfoSig ?>" style="display:contents;">
                <!-- ── NEW (layout adjustment): locked/read-only state ────────
                     Reference-layout row order: Contact Full Name / Position /
                     Telephone, then Company Name / Complete Office Address,
                     then Company Profile full-width. This is purely a visual
                     rearrangement of the exact same fields/values that were
                     already on this page — no field was removed, renamed, or
                     changed in what it submits. -->

                <?php
                // ── NEW (this adjustment): per-field lock/unlock pairs for
                // this "locked" branch — see cfFieldLockFor()'s docblock
                // above ($moa_needs_initial_creation) for the full
                // explanation. For every company that is NOT an "Existing"
                // request-type company (i.e. $moa_is_existing_request is
                // false), every one of these resolves to exactly
                // ['attr' => $cfFieldLockAttr, 'style' => $cfFieldLockStyle]
                // — identical to what this branch always rendered — so
                // nothing changes for them.
                $cfLockCompany         = cfFieldLockFor($company,         $moa_is_existing_request, $cfFieldLockAttr, $cfFieldLockStyle);
                $cfLockCompanyAddress  = cfFieldLockFor($company_address, $moa_is_existing_request, $cfFieldLockAttr, $cfFieldLockStyle);
                $cfLockContactFirst    = cfFieldLockFor($contact_first,   $moa_is_existing_request, $cfFieldLockAttr, $cfFieldLockStyle);
                $cfLockContactLast     = cfFieldLockFor($contact_last,    $moa_is_existing_request, $cfFieldLockAttr, $cfFieldLockStyle);
                $cfLockContactMiddle   = cfFieldLockFor($contact_middle,  $moa_is_existing_request, $cfFieldLockAttr, $cfFieldLockStyle, true);
                $cfLockPosition        = cfFieldLockFor($position,        $moa_is_existing_request, $cfFieldLockAttr, $cfFieldLockStyle);
                $cfLockTelephone       = cfFieldLockFor($telephone,       $moa_is_existing_request, $cfFieldLockAttr, $cfFieldLockStyle);
                $cfLockCompanyProfile  = cfFieldLockFor($company_profile, $moa_is_existing_request, $cfFieldLockAttr, $cfFieldLockStyle);

                // ── NEW (this adjustment): Telephone / Contact Number and Company
                // Profile / Brief Description are NOT printed on the MOA, so they
                // are editable for every company (not only "Existing" ones). Every
                // field that IS on the MOA (company name, office address, contact
                // name, position) keeps the lock pair computed above. Saved by the
                // submit_compliance_docs handler (see its non-MOA branch).
                $cfNonMoaFieldLock    = ['attr' => '', 'style' => ''];
                $cfLockTelephone      = $cfNonMoaFieldLock;
                $cfLockCompanyProfile = $cfNonMoaFieldLock;

                // The merged "Contact Full Name" readonly display only makes sense
                // once BOTH halves actually have a value to show — if either half is
                // still empty on an "Existing" company (eligible to unlock), fall
                // back to the existing single-field branch below instead (the exact
                // same branch already used whenever only one of First/Last is
                // shown/unflagged), so the company gets real, individually-editable
                // First/Last inputs rather than an empty, non-interactive merged field.
                // ADJUSTMENT (this update): an "Existing" company always gets the separate,
                // editable First / Middle / Last inputs (every field is unlocked for them
                // now), instead of the merged read-only "Contact Full Name" display.
                $cfContactBothEmptyEligible = $moa_is_existing_request;

                // ADJUSTMENT (this update): when the separate name inputs are shown
                // (the else-branch below), Contact Middle Name is now rendered INSIDE
                // the same row, between Contact First Name and Contact Last Name,
                // instead of on its own full-width row underneath. Only needs the row
                // itself to be rendered (its own if-condition just below); otherwise
                // the standalone middle-name block further down is still used.
                $cfMiddleInline = $cfShowContactMiddle
                    && !($cfShowContactFirst && $cfShowContactLast && !$cfContactBothEmptyEligible)
                    && ($cfShowContactFirst || $cfShowContactLast || $cfShowPosition || $cfShowTelephone);

                // ADJUSTMENT (this update): for "Existing" request-type companies (all
                // fields unlocked), lay the fields out EXACTLY like the MOA Revision
                // panel does when every section is flagged — same rows (Contact First /
                // Middle / Last / Position / Telephone, then Company Name / Complete
                // Office Address, then Company Profile), same .moa-revision-fields /
                // .moa-revision-grid classes, same --moa-cols column rule (up to 3
                // columns; 4 fields split 2 + 2) and same tighter spacing. Every input
                // keeps its own id / name / value / lock attributes, so saving and the
                // Preview/Create MOA scripts are untouched. Every other company keeps
                // the original .input-row-3 / .input-row layout unchanged.
                $cfUseRevLayout = $moa_is_existing_request;
                $cfRevColsFor = function ($n) { return ($n <= 3) ? max(1, $n) : (($n === 4) ? 2 : 3); };
                $cfRow1Count = 0;
                if ($cfShowContactFirst && $cfShowContactLast && !$cfContactBothEmptyEligible) {
                    $cfRow1Count++;
                } else {
                    $cfRow1Count += (int) $cfShowContactFirst + (int) $cfMiddleInline + (int) $cfShowContactLast;
                }
                $cfRow1Count += (int) $cfShowPosition + (int) $cfShowTelephone;
                $cfRow2Count = (int) $cfShowCompanyName + (int) $cfShowCompanyAddress;
                $cfRow1Open = $cfUseRevLayout
                    ? '<div class="moa-revision-grid" style="--moa-cols:' . (int) $cfRevColsFor($cfRow1Count) . ';">'
                    : '<div class="input-row-3">';
                $cfRow2Open = $cfUseRevLayout
                    ? '<div class="moa-revision-grid" style="--moa-cols:' . (int) $cfRevColsFor($cfRow2Count) . ';">'
                    : '<div class="input-row">';
                ?>

                <?php if ($cfUseRevLayout): ?><div class="moa-revision-fields"><?php endif; ?>

                <?php if ($cfShowContactFirst || $cfShowContactLast || $cfShowPosition || $cfShowTelephone): ?>
                <?= $cfRow1Open ?>
                    <?php if ($cfShowContactFirst && $cfShowContactLast && !$cfContactBothEmptyEligible): ?>
                    <div class="form-group">
                        <label>Contact Full Name</label>
                        <input type="text" value="<?= htmlspecialchars($cfContactFullName) ?>" readonly style="<?= $cfFieldLockStyle ?>">
                        <input type="hidden" id="cfField_contact_first_name" name="contact_first_name" value="<?= htmlspecialchars($contact_first) ?>">
                        <?php if ($cfShowContactMiddle): ?>
                        <input type="hidden" id="cfField_contact_middle_initial" name="contact_middle_initial" value="<?= htmlspecialchars($contact_middle) ?>">
                        <?php endif; ?>
                        <input type="hidden" id="cfField_contact_last_name" name="contact_last_name" value="<?= htmlspecialchars($contact_last) ?>">
                    </div>
                    <?php else: ?>
                        <?php // Only one of First/Last is on file / unflagged — the merge
                        // above needs both halves, so fall back to showing whichever
                        // single name field remains, same as the page always did.
                        // (NEW: also reached, with BOTH shown, whenever either half is
                        // still empty on an "Existing" company — see
                        // $cfContactBothEmptyEligible above — so each half can unlock
                        // individually instead of hiding inside the merged field.) ?>
                        <?php if ($cfShowContactFirst): ?>
                        <div class="form-group">
                            <label>Contact First Name</label>
                            <input type="text" id="cfField_contact_first_name" name="contact_first_name" value="<?= htmlspecialchars($contact_first) ?>" <?= $cfLockContactFirst['attr'] ?> style="<?= $cfLockContactFirst['style'] ?>">
                        </div>
                        <?php endif; ?>
                        <?php if ($cfMiddleInline): ?>
                        <?php // ADJUSTMENT (this update): Middle Name between First and Last. ?>
                        <div class="form-group">
                            <label>Contact Middle Name</label>
                            <input type="text" id="cfField_contact_middle_initial" name="contact_middle_initial" value="<?= htmlspecialchars($contact_middle) ?>" <?= $cfLockContactMiddle['attr'] ?> style="<?= $cfLockContactMiddle['style'] ?>">
                        </div>
                        <?php endif; ?>
                        <?php if ($cfShowContactLast): ?>
                        <div class="form-group">
                            <label>Contact Last Name</label>
                            <input type="text" id="cfField_contact_last_name" name="contact_last_name" value="<?= htmlspecialchars($contact_last) ?>" <?= $cfLockContactLast['attr'] ?> style="<?= $cfLockContactLast['style'] ?>">
                        </div>
                        <?php endif; ?>
                    <?php endif; ?>

                    <?php if ($cfShowPosition): ?>
                    <div class="form-group">
                        <label>Position / Designation</label>
                        <input type="text" id="cfField_position" name="position" value="<?= htmlspecialchars($position) ?>" placeholder="e.g. HR Manager, Training Officer" <?= $cfLockPosition['attr'] ?> style="<?= $cfLockPosition['style'] ?>">
                    </div>
                    <?php endif; ?>

                    <?php if ($cfShowTelephone): ?>
                    <div class="form-group">
                        <label>Telephone / Contact Number</label>
                        <input type="tel" id="cfField_telephone" name="telephone" value="<?= htmlspecialchars($telephone) ?>" <?= $cfLockTelephone['attr'] ?> style="<?= $cfLockTelephone['style'] ?>">
                    </div>
                    <?php endif; ?>
                </div>
                <?php endif; ?>

                <?php // Contact Middle Name has no cell of its own in the locked
                // state (it's folded into "Contact Full Name" above), but if it's
                // individually flagged for revision while First/Last are NOT, the
                // merge branch above never runs, so it needs its own field here —
                // exactly like every other individually-flagged field on this page.
                // (NEW: also shown whenever the merge branch is skipped because
                // $cfContactBothEmptyEligible is true — see above.) ?>
                <?php // ADJUSTMENT (this update): skipped when the middle name was already
                // rendered inline between First and Last above ($cfMiddleInline). ?>
                <?php if ($cfShowContactMiddle && !($cfShowContactFirst && $cfShowContactLast && !$cfContactBothEmptyEligible) && !$cfMiddleInline): ?>
                <div class="form-group">
                    <label>Contact Middle Name</label>
                    <input type="text" id="cfField_contact_middle_initial" name="contact_middle_initial" value="<?= htmlspecialchars($contact_middle) ?>" <?= $cfLockContactMiddle['attr'] ?> style="<?= $cfLockContactMiddle['style'] ?>">
                </div>
                <?php endif; ?>

                <?php if ($cfShowCompanyName || $cfShowCompanyAddress): ?>
                <?= $cfRow2Open ?>
                    <?php if ($cfShowCompanyName): ?>
                    <div class="form-group">
                        <label>Company Name</label>
                        <input type="text" id="cfField_company" name="company" value="<?= htmlspecialchars($company) ?>" <?= $cfLockCompany['attr'] ?> style="<?= $cfLockCompany['style'] ?>">
                    </div>
                    <?php endif; ?>
                    <?php if ($cfShowCompanyAddress): ?>
                    <div class="form-group">
                        <label>Complete Office Address</label>
                        <input type="text" id="cfField_company_address" name="company_address" value="<?= htmlspecialchars($company_address) ?>" <?= $cfLockCompanyAddress['attr'] ?> style="<?= $cfLockCompanyAddress['style'] ?>">
                    </div>
                    <?php endif; ?>
                </div>
                <?php endif; ?>

                <!-- NEW: Company Profile / Brief Description — auto-filled from
                     company_information.company_profile (the text the company
                     typed at registration). Read-only, matching every other
                     field in this section, since it's the company's official
                     on-file description rather than something editable here.
                     Hidden entirely while flagged for revision (see above).
                     (NEW this adjustment: unlocks, same as every other field
                     above, while empty on an "Existing" company.) -->
                <?php if ($cfShowCompanyProfile): ?>
                <?php if ($cfUseRevLayout): ?><div class="moa-revision-grid" style="--moa-cols:1;"><?php endif; ?>
                <div class="form-group">
                    <label>Company Profile / Brief Description</label>
                    <textarea id="cfField_company_profile" name="company_profile" <?= $cfLockCompanyProfile['attr'] ?> rows="4"
                        style="width:100%;padding:12px;border:1px solid #e2e8f0;border-radius:8px;font-size:14px;box-sizing:border-box;<?= $cfLockCompanyProfile['style'] ?>font-family:'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;resize:vertical;"
                    ><?= htmlspecialchars($company_profile) ?></textarea>
                </div>
                <?php if ($cfUseRevLayout): ?></div><?php endif; ?>
                <?php endif; ?>

                <?php if ($cfUseRevLayout): ?></div><!-- /.moa-revision-fields --><?php endif; ?>

                </div><!-- /#cfCompanyInfoRegion -->
                <?php endif; ?>

                <?php if ($moa_needs_initial_creation): ?>

                <!-- ═══════════════════════════════════════════════
                     NEW (this adjustment) — CREATE MOA action. Saves the
                     fields above (all required) and generates the MOA
                     from them — see submitMoaCreation() and the
                     submit_moa_creation handler near the top of this
                     file. Deliberately a plain type="button" + fetch()
                     rather than this page's native form submit, so it can
                     run independently of submit_compliance_docs (the file
                     upload submit further down) without interfering with
                     it. On success the page reloads: $moa_file is then
                     populated, $moa_needs_initial_creation naturally goes
                     false, and every field above locks back down exactly
                     like it does for any other company.

                     NEW (this adjustment) — PREVIEW MOA action, placed
                     alongside it: lets the company see what their MOA
                     will actually look like, built live from whatever
                     they've currently typed above, before committing to
                     "Create MOA" — see openMoaCreatePreview() and the
                     #moaCreatePreviewModal modal further down. Mirrors
                     company_register.php's own Step 2 "Preview MOA"
                     button exactly (same hidden-form-into-iframe
                     technique, same preview_new_moa endpoint pattern,
                     now added near the top of this file).
                     ═══════════════════════════════════════════════ -->
                <div class="moa-creation-actions">
                    <div id="moaCreationFeedback" class="moa-revision-feedback"></div>
                    <button type="button" class="moa-creation-preview-btn" onclick="openMoaCreatePreview()">
                        <i class="fas fa-eye"></i> Preview MOA
                    </button>
                    <button type="button" class="moa-creation-submit-btn" id="moaCreationSubmitBtn" onclick="submitMoaCreation()">
                        <span class="moa-revision-spinner" id="moaCreationSpinner" style="display:none;"></span>
                        <i class="fas fa-file-signature"></i> Create MOA
                    </button>
                </div>
                <?php endif; ?>

                <?php if (!$moa_needs_initial_creation): ?>
                <?php // ADJUSTMENT: the info page's own submit button — saves the editable profile fields (same submit_compliance_docs handler as before). ?>
                <div class="cf-info-save-row">
                    <button type="submit" name="submit_compliance_docs" value="1" class="submit-all">
                        <i class="fas fa-save" style="margin-right:8px;"></i>Save Changes
                    </button>
                </div>
                <?php endif; ?>

                </div><!-- /#cf-info-page -->

                <div id="cf-requirements-page" class="cf-page-content">

                <?php if ($showMoaSection): ?>
                <!-- ═══════════════════════════════════════════════
                     ADJUSTMENT: The entire "MOA Document Status"
                     section (heading + moa-status-card) is only
                     rendered when this company's latest MOA request
                     has request_type = "New". Companies whose latest
                     request_type is "Existing" (or who have no MOA
                     request on file) never see this block at all.
                     This gate applies ONLY to this MOA workflow
                     section — it has no bearing on the Compliance
                     Requirements checklist further below, which is
                     always shown in full for both "New" and
                     "Existing" companies.
                     ═══════════════════════════════════════════════ -->
                <h3 style="color: var(--neust-maroon); font-size: 16px; margin-top: 40px; margin-bottom: 20px; display:flex; align-items:center; justify-content:space-between; gap:12px; flex-wrap:wrap;">
                    <span><i class="fas fa-file-signature" style="margin-right: 10px;"></i>MOA Document Status</span>
                    <?php /* ADJUSTMENT (this revision): the Review button is removed entirely — the MOA Document row below now shows a "click the PDF" hint instead. */ ?>
                </h3>

                <!-- ═══════════════════════════════════════════════
                     MOA DOCUMENT — preview + workflow status
                     (replaces the old multi-requirement upload list)
                     ═══════════════════════════════════════════════ -->
                <div class="moa-status-card" id="cfMoaStatusCard" data-live-sig="<?= $cfMoaSig ?>">
                    <?php if (!$moa_file): ?>
                        <div class="req-awaiting-ui">
                            <i class="fas fa-hourglass-half"></i>
                            No MOA document on file yet. Please submit your MOA request so the administrator can review it here.
                        </div>
                    <?php else: ?>
                        <!-- ADJUSTMENT (this revision): MOA Document display and the signing schedule
                             panel share one horizontal row (vertically aligned with each other) once
                             the schedule has been answered (confirmed / accepted by admin / declined).
                             While the schedule is still awaiting the company's response (the "Signing
                             Schedule Proposed" panel with the agree / not-available form) the row is
                             stacked instead: MOA Document on top, the panel directly below it. -->
                        <?php
                        // ADJUSTMENT (this revision): the stacked layout only applies when the schedule
                        // panel is actually rendered (same condition as the panel below) AND is still
                        // awaiting the company's response. A Verified MOA has no panel — its verified
                        // box sits beside the MOA Document on one row instead.
                        $moaSchedPanelShown       = ($moa_stage === 'scheduled' && $moa_status !== 'Verified' && !empty($moa_schedule));
                        $moaSchedAwaitingResponse = $moaSchedPanelShown && !in_array($moa_schedule_status, ['confirmed', 'confirmed_by_admin', 'declined'], true);
                        ?>
                        <div class="moa-top-row<?= $moaSchedAwaitingResponse ? ' moa-top-row--stacked' : '' ?>">
                        <div class="moa-preview-row">
                            <?php
                            $finfo_moa = new finfo(FILEINFO_MIME_TYPE);
                            $mime_moa  = $finfo_moa->buffer($moa_file);
                            $isPdfMoa  = (strpos($mime_moa, 'pdf') !== false || strpos($mime_moa, 'octet') !== false);
                            ?>
                            <?php if ($isPdfMoa): ?>
                                <div class="moa-thumb-wrap" onclick="openMoaReview()" title="Preview MOA Document">
                                    <i class="fas fa-file-pdf"></i>
                                </div>
                            <?php else: ?>
                                <img class="moa-thumb-img" src="CompanyForm.php?stream_own_moa=1" onclick="openMoaReview()" title="Preview MOA Document">
                            <?php endif; ?>

                            <?php /* ADJUSTMENT (this revision): the status badge below "MOA Document" is now hidden for "Pending" AND "Verified" — a Verified MOA already shows the "MOA Document Verified — Signing Scheduled ✓" box beside it, so the extra rounded-check "Verified" badge was redundant. */ ?>
                            <?php $moaHidePendingBadge = (strcasecmp((string) $moa_status, 'Pending') === 0 || strcasecmp((string) $moa_status, 'Verified') === 0); ?>
                            <div style="flex:0 1 auto;min-width:0;">
                                <div class="req-info" style="margin-bottom:<?= $moaHidePendingBadge ? '0' : '6px' ?>;">MOA Document</div>
                                <!-- ADJUSTMENT (this revision): the Review MOA Document button is removed
                                     entirely; this hint replaces it and now sits directly BELOW the
                                     "MOA Document" name. The PDF thumbnail (and the image thumbnail for
                                     non-PDF files) already opens the preview on click. -->
                                <span class="moa-click-hint">(Clicked the PDF to view the MOA)</span>
                                <?php if (!$moaHidePendingBadge): /* ADJUSTMENT (this revision): the "Pending" and "Verified" displays below "MOA Document" are removed */ ?>
                                <span class="status-badge <?= strtolower($moa_status) ?>">
                                    <?php if($moa_status === 'Verified'): ?>
                                        <i class="fas fa-check-circle"></i>
                                    <?php elseif($moa_status === 'Denied'): ?>
                                        <i class="fas fa-times-circle"></i>
                                    <?php else: ?>
                                        <i class="fas fa-clock"></i>
                                    <?php endif; ?>
                                    <?= htmlspecialchars($moa_status) ?>
                                </span>
                                <?php endif; ?>

                                <?php if($moa_status === 'Denied' && !empty($moa_remark)): ?>
                                    <div class="remark-badge">
                                        <i class="fas fa-exclamation-circle"></i>
                                        Reason: <?= htmlspecialchars($moa_remark) ?>
                                    </div>
                                <?php endif; ?>
                            </div>
                        </div>

                        <?php if($moa_status === 'Verified'): ?>
                        <!-- ADJUSTMENT (this revision): moved out of the MOA Document text column so it
                             is horizontally aligned with the MOA Document (same row, to its right). -->
                        <div class="verified-lock">
                            <i class="fas fa-check-circle"></i> MOA Document Verified — Signing Scheduled ✓
                            <?php if(!empty($moa_schedule)): ?>
                                <strong style="margin-left:4px;"><?= date('M d, Y g:i A', strtotime($moa_schedule)) ?></strong>
                            <?php endif; ?>
                        </div>
                        <?php endif; ?>

                        <?php if ($moa_stage === 'scheduled' && $moa_status !== 'Verified' && !empty($moa_schedule)): ?>
                        <!-- ═══════════════════════════════════════════════
                             NEW (this adjustment) — SIGNING SCHEDULE
                             CONFIRMATION. The administrator proposed a
                             signing date/time on their end
                             (company_validation.php); the representative
                             must explicitly agree to it — or decline with a
                             reason and propose a different date/time
                             instead — before it's treated as final. See
                             respondToSchedule() below and the
                             submit_moa_schedule_response handler near the
                             top of this file. Same deliberately-not-nested
                             <form> approach as the MOA Revision Compliance
                             panel above (plain buttons + fetch()). -->
                        <div class="moa-sched-panel" id="moaSchedPanel">
                            <?php if ($moa_schedule_status === 'confirmed'): ?>
                                <div class="moa-sched-panel-header confirmed">
                                    You confirmed this signing schedule
                                </div>
                                <p class="moa-sched-panel-datetime"><?= date('l, F j, Y \a\t g:i A', strtotime($moa_schedule)) ?></p>

                            <?php elseif ($moa_schedule_status === 'confirmed_by_admin'): ?>
                                <!-- ── NEW (this adjustment): distinct from the company clicking "I
                                     Agree" above — this is the administrator having accepted the
                                     date/time the company itself proposed after declining the
                                     original schedule. Showing "You confirmed this signing
                                     schedule" here would be inaccurate, since the company didn't
                                     take a confirming action on THIS particular date — the admin
                                     did, by adopting their suggestion. -->
                                <div class="moa-sched-panel-header confirmed">
                                    The administrator accepted your proposed schedule
                                </div>
                                <p class="moa-sched-panel-datetime"><?= date('l, F j, Y \a\t g:i A', strtotime($moa_schedule)) ?></p>

                            <?php elseif ($moa_schedule_status === 'declined'): ?>
                                <div class="moa-sched-panel-header declined">
                                    <i class="fas fa-calendar-times"></i> You proposed a different schedule
                                </div>
                                <p class="moa-sched-panel-datetime moa-sched-strike">Originally proposed: <?= date('M d, Y g:i A', strtotime($moa_schedule)) ?></p>
                                <?php if (!empty($moa_schedule_decline_reason)): ?>
                                <p class="moa-sched-panel-note">Your reason: "<?= htmlspecialchars($moa_schedule_decline_reason) ?>"</p>
                                <?php endif; ?>
                                <?php if (!empty($moa_proposed_datetime)): ?>
                                <p class="moa-sched-panel-note">Your proposed schedule: <strong><?= date('M d, Y g:i A', strtotime($moa_proposed_datetime)) ?></strong></p>
                                <?php endif; ?>
                                <p class="moa-sched-panel-hint">Waiting for the administrator to review your proposed schedule.</p>

                            <?php else: // NULL or 'pending_confirmation' ?>
                                <div class="moa-sched-panel-header pending">
                                    <i class="fas fa-calendar-check"></i> Signing Schedule Proposed
                                </div>
                                <p class="moa-sched-panel-datetime"><?= date('l, F j, Y \a\t g:i A', strtotime($moa_schedule)) ?></p>
                                <p class="moa-sched-panel-hint">The administrator proposed this date and time for signing the MOA. Please let us know if you're available.</p>

                                <div id="moaSchedResponseFeedback" class="moa-revision-feedback"></div>

                                <div class="moa-sched-panel-actions" id="moaSchedInitialActions">
                                    <button type="button" class="moa-sched-agree-btn" id="moaSchedAgreeBtn" onclick="respondToSchedule('agree')">
                                        <span class="moa-revision-spinner" id="moaSchedAgreeSpinner" style="display:none;"></span>
                                        <i class="fas fa-check"></i> I Agree
                                    </button>
                                    <button type="button" class="moa-sched-decline-btn" onclick="showScheduleDeclineForm()">
                                        <i class="fas fa-times"></i> I'm Not Available
                                    </button>
                                </div>

                                <!-- Reason + counter-proposal form — hidden until "I'm Not Available" is clicked -->
                                <div id="moaSchedDeclineForm" style="display:none;">
                                    <label class="moa-sched-field-label">Why aren't you available at this time?</label>
                                    <textarea id="moaSchedDeclineReason" class="moa-revision-input" rows="3" placeholder="e.g. Our representative will be out of town that week."></textarea>

                                    <label class="moa-sched-field-label" style="margin-top:12px;">Propose a different date</label>
                                    <input type="date" id="moaSchedProposeDate" class="moa-revision-input">

                                    <label class="moa-sched-field-label" style="margin-top:12px;">Propose a different time</label>
                                    <input type="time" id="moaSchedProposeTime" class="moa-revision-input">

                                    <div class="moa-sched-panel-actions" style="margin-top:14px;">
                                        <button type="button" class="moa-sched-cancel-btn" onclick="hideScheduleDeclineForm()">Cancel</button>
                                        <button type="button" class="moa-sched-decline-btn" id="moaSchedDeclineSubmitBtn" onclick="respondToSchedule('decline')">
                                            <span class="moa-revision-spinner" id="moaSchedDeclineSpinner" style="display:none;"></span>
                                            <i class="fas fa-paper-plane"></i> Submit
                                        </button>
                                    </div>
                                </div>
                            <?php endif; ?>
                        </div>
                        <?php endif; ?>
                        </div><!-- /.moa-top-row -->

                        <?php if ($moa_needs_revision): ?>
                        <!-- ═══════════════════════════════════════════════
                             NEW (this adjustment) — MOA REVISION COMPLIANCE
                             PANEL. Shown only when the administrator has
                             flagged this MOA for revision. Only the exact
                             field(s) that were flagged are editable here
                             (built from $moa_flagged_fields +
                             $moaRevisionFieldMeta, both computed above);
                             submitting saves them, regenerates the MOA, and
                             clears the flag — see submitMoaRevision() below
                             and the submit_moa_revision handler near the
                             top of this file. Deliberately NOT a nested
                             <form> (this whole section already sits inside
                             the page's one big <form>) — the Submit button
                             is a plain type="button" that POSTs via
                             fetch() instead.
                             ═══════════════════════════════════════════════ -->
                        <div class="moa-revision-panel" id="moaRevisionPanel">
                            <div class="moa-revision-header">
                                <i class="fas fa-flag"></i> Your MOA needs revision
                            </div>
                            <?php if (!empty($moa_revision_comment)): ?>
                            <p class="moa-revision-note">"<?= htmlspecialchars($moa_revision_comment) ?>"</p>
                            <?php endif; ?>
                            <p class="moa-revision-hint">
                                Please fill in the section(s) below, then submit — your MOA will be
                                automatically updated and resent for review.
                            </p>

                            <div class="moa-revision-fields">
                                <?php
                                // ── NEW (layout adjustment): the flagged fields are now laid out in
                                // the same rows as the company info section above instead of one
                                // stacked column — Contact name(s) / Position / Telephone, then
                                // Company Name / Complete Office Address, then Company Profile.
                                // Which fields appear is unchanged: exactly the ones the
                                // administrator flagged ($moaFlaggedFieldSet), each with the same
                                // id / class / value / placeholder as before. A flagged key with
                                // no text field (e.g. "moa_document") is still skipped.
                                $moaRevisionRows = [
                                    ['contact_first_name', 'contact_middle_name', 'contact_last_name', 'position', 'telephone'],
                                    ['company_name', 'company_address'],
                                    ['company_profile'],
                                ];
                                // Safety net: any flaggable field that has metadata but isn't in
                                // the layout above still gets rendered (own full-width row).
                                $moaRevisionLaidOut = [];
                                foreach ($moaRevisionRows as $moaRevisionRowKeys) {
                                    foreach ($moaRevisionRowKeys as $moaRevKey) $moaRevisionLaidOut[$moaRevKey] = true;
                                }
                                $moaRevisionExtraKeys = [];
                                foreach ($moa_flagged_fields as $moaRevKey) {
                                    if (isset($moaRevisionFieldMeta[$moaRevKey]) && !isset($moaRevisionLaidOut[$moaRevKey])) {
                                        $moaRevisionExtraKeys[] = $moaRevKey;
                                    }
                                }
                                if ($moaRevisionExtraKeys) $moaRevisionRows[] = $moaRevisionExtraKeys;

                                $moaRevisionHasFields = false;
                                foreach ($moaRevisionRows as $moaRevisionRowKeys):
                                    $moaRevisionRowFields = [];
                                    foreach ($moaRevisionRowKeys as $moaRevKey) {
                                        if (isset($moaFlaggedFieldSet[$moaRevKey]) && isset($moaRevisionFieldMeta[$moaRevKey])) {
                                            $moaRevisionRowFields[$moaRevKey] = $moaRevisionFieldMeta[$moaRevKey];
                                        }
                                    }
                                    if (!$moaRevisionRowFields) continue;
                                    $moaRevisionHasFields = true;
                                    // Up to 3 columns (matches the 3-column row above); 4 fields
                                    // split 2 + 2 so there's never a lone orphan cell.
                                    $moaRevisionCount = count($moaRevisionRowFields);
                                    $moaRevisionCols  = ($moaRevisionCount <= 3) ? $moaRevisionCount : (($moaRevisionCount === 4) ? 2 : 3);
                                ?>
                                <div class="moa-revision-grid" style="--moa-cols:<?= (int) $moaRevisionCols ?>;">
                                    <?php foreach ($moaRevisionRowFields as $flagKey => $fieldMeta): ?>
                                    <div class="form-group">
                                        <label><?= htmlspecialchars($fieldMeta['label']) ?></label>
                                        <?php if ($fieldMeta['type'] === 'textarea'): ?>
                                            <textarea id="moaRev_<?= htmlspecialchars($flagKey) ?>" rows="4" class="moa-revision-input"
                                                placeholder="Enter <?= htmlspecialchars(strtolower($fieldMeta['label'])) ?>"
                                            ><?= htmlspecialchars($fieldMeta['currentValue']) ?></textarea>
                                        <?php else: ?>
                                            <input type="text" id="moaRev_<?= htmlspecialchars($flagKey) ?>" class="moa-revision-input"
                                                value="<?= htmlspecialchars($fieldMeta['currentValue']) ?>"
                                                placeholder="Enter <?= htmlspecialchars(strtolower($fieldMeta['label'])) ?>">
                                        <?php endif; ?>
                                    </div>
                                    <?php endforeach; ?>
                                </div>
                                <?php endforeach; ?>

                                <?php if (!$moaRevisionHasFields): ?>
                                <p class="moa-revision-hint">
                                    The flagged section requires a new document upload rather than a
                                    text update. Please contact the administrator for next steps.
                                </p>
                                <?php endif; ?>
                            </div>

                            <div id="moaRevisionFeedback" class="moa-revision-feedback"></div>

                            <?php if ($moaRevisionHasFields): ?>
                            <button type="button" class="moa-revision-submit-btn" id="moaRevisionSubmitBtn" onclick="submitMoaRevision()">
                                <span class="moa-revision-spinner" id="moaRevisionSpinner" style="display:none;"></span>
                                <i class="fas fa-paper-plane"></i> Submit &amp; Update MOA
                            </button>
                            <?php endif; ?>
                        </div>
                        <?php endif; ?>

                        <?php if($moa_status !== 'Verified'): ?>
                        <!-- Workflow stepper — mirrors the admin's in-table MOA workflow (view only)
                             ── UPDATED (this adjustment): synced with company_validation.php's merged
                             "Pending for Review" stage (the old separate "Pending"/"Reviewing" steps
                             are now one step) — 3 steps instead of 4, matching the admin panel's
                             current stepper exactly. A row whose stored moa_workflow_stage is still
                             the legacy 'reviewing' value (written before that merge) is normalized to
                             'pending' here too, so it still lands on the correct single step instead
                             of falling through to no active step at all. -->
                        <div class="moa-tbl-stepper">
                            <?php
                            $moa_stage_display = ($moa_stage === 'reviewing') ? 'pending' : $moa_stage;
                            $tblSteps = [
                                ['key'=>'pending',   'label'=>'Pending for Review', 'icon'=>'1'],
                                ['key'=>'approved',  'label'=>'Approved',            'icon'=>'2'],
                                ['key'=>'scheduled', 'label'=>'Sched. Signing',      'icon'=>'✔'],
                            ];
                            $currentIdx = array_search($moa_stage_display, array_column($tblSteps, 'key'));
                            if ($currentIdx === false) $currentIdx = 0;
                            foreach ($tblSteps as $si => $s):
                                $cls = '';
                                if ($si < $currentIdx) $cls = 'done';
                                elseif ($si === $currentIdx) $cls = 'active';
                                $dotContent = ($cls === 'done') ? '<i class="fas fa-check" style="font-size:11px;"></i>' : htmlspecialchars($s['icon']);
                            ?>
                            <div class="moa-tbl-step <?= $cls ?>">
                                <div class="moa-tbl-dot"><?= $dotContent ?></div>
                                <div class="moa-tbl-label"><?= htmlspecialchars($s['label']) ?></div>
                            </div>
                            <?php endforeach; ?>
                        </div>
                        <?php endif; ?>
                    <?php endif; ?>
                </div>
                <?php endif; // end $showMoaSection ?>


                <!-- ═══════════════════════════════════════════════
                     COMPLIANCE REQUIREMENTS (NEW) — classification-based
                     checklist integrated from company_register.php's
                     Step 3 "Classification & Docs". Lets the company view
                     status and upload/resubmit each required document for
                     its current classification, without needing to go
                     through registration again.

                     ADJUSTMENT (this update): every item in
                     $reqDefsForType — including "Authority to Sign MOA"
                     — is now always rendered here, regardless of this
                     company's MOA request_type. It is no longer skipped
                     for "Request New MOA" companies.

                     FIX (this update): $reqDefsForType is computed ABOVE
                     purely from $current_type (Private vs Public) and is
                     never filtered by $moa_request_type_norm anywhere on
                     this page, so a Private company always sees the full
                     10-item checklist (Company Profile, Vision and
                     Mission, Mayor's Permit, SEC Registration, DTI
                     Certificate, CDA Certificate, BIR Tax Clearance, OHS
                     Plan, Training Supervisor CV, Authority to Sign MOA)
                     and a Public company always sees the full 3-item
                     checklist — identical to company_register.php's Step
                     3 — whether their MOA request_type on file is "New"
                     or "Existing".

                     NEW (this revision): for "Existing" request-type
                     companies ONLY, one extra item — "MOA Document
                     (Existing Partnership)", tagged with the "MOA
                     UPLOAD" badge below — is appended onto the end of
                     this same list (see $moa_existing_reqs / the append
                     onto $reqDefsForType earlier in this file). It is
                     rendered, validated, and saved through the exact
                     same code path as every other item here; the tag is
                     purely a visual label to distinguish it, and it does
                     NOT belong to, or affect, the "MOA Document Status"
                     workflow section above, which stays reserved for
                     "New" request-type companies only.

                     ADJUSTMENT (this revision) — LAYOUT ONLY: within each
                     .compliance-req-item row below, the file display
                     (thumbnail / stacked card), the name+status+remarks
                     block, and the upload/reupload button (or the
                     verified-lock badge) are now grouped as three
                     vertically-centered flex columns via
                     .compliance-req-top / .compliance-req-info /
                     .compliance-req-action (see the CSS above). No IDs,
                     data attributes, input names, or PHP logic used by
                     the upload/preview JS below were changed — only
                     where each existing element sits in the row.

                     ADJUSTMENT (this revision) — LIVE FILE PREVIEW +
                     AUTO-HIDE DENIED STATUS ON RESELECTION: the file
                     preview area (thumbnail / stack / empty placeholder)
                     is now wrapped in a single #reqPreviewSlot_<key>
                     container, and the status badge / remark badge each
                     carry their own id (#reqStatusBadge_<key> /
                     #reqRemarkBadge_<key>). The moment the company picks
                     a new file for a requirement, JS below swaps that
                     slot's contents for a live, in-browser preview of the
                     just-selected file (an actual image thumbnail, or a
                     PDF/document icon tile) instead of leaving the old
                     server-side preview showing.

                     FIX (this revision) — TWO ADJUSTMENTS REQUESTED:
                       1) The "New file selected — ready to resubmit"
                          staged note, and the Denied badge/remark
                          auto-hide, now only ever trigger for a
                          requirement whose ORIGINAL server-rendered
                          status was "Denied". A requirement that has no
                          file yet ("Not Submitted") or is "Pending" no
                          longer shows the staged note when a file is
                          selected for it — only a previously-Denied item
                          does, since that's the only case where hiding a
                          stale Denied badge/remark in favor of the note
                          actually applies. See the wasDenied check in the
                          'compliance-file-input' change handler in the
                          script below.
                       2) The upload/replace drop-zone's label
                          ("Click to upload" / "Click to replace file(s)")
                          is no longer hidden once file(s) are selected —
                          it previously disappeared, leaving only the
                          upload icon showing. It now stays visible and
                          updates to reflect that file(s) were chosen.
                     Selecting nothing (or clearing the selection) restores
                     the original server-rendered preview, status/remark,
                     and drop-zone label exactly as they were. This is
                     purely a client-side, pre-submit convenience — the
                     PHP upload/validate/save logic, requirement keys, and
                     saved statuses in the database are completely
                     unaffected until the form is actually submitted.
                     ═══════════════════════════════════════════════ -->
                <h3 style="color: var(--neust-maroon); font-size: 16px; margin-top: 40px; margin-bottom: 4px;">
                    <i class="fas fa-clipboard-check" style="margin-right: 10px;"></i>Compliance Requirements (<?= htmlspecialchars(ucfirst($current_type)) ?>)
                </h3>
                <p style="font-size:12px;color:#718096;margin:0 0 16px;">
                    Accepted: PDF or any picture format (JPG, PNG, GIF, WEBP, BMP, ...) &middot; picture uploads support selecting multiple files, but PDF uploads are limited to one file &middot; Max <?= (int)$reqMaxFileSizeMB ?>MB each. Uploading a new file for an item resubmits it for review.
                    <?php if ($moa_request_type_norm === 'existing'): ?>
                        Since your MOA request type is <strong>Existing</strong>, please also upload your MOA document below.
                    <?php endif; ?>
                </p>

                <?php
                // ════════════════════════════════════════════════════════
                //  NEW (this revision) — COMPLIANCE STATUS DETECTION PASS
                //  ──────────────────────────────────────────────────────
                //  A read-only pre-pass over the exact same data the cards
                //  below already use ($reqDefsForType + the
                //  $company_requirement_rows fetched earlier), applying the
                //  IDENTICAL aggregation rule used inside the card loop
                //  (Denied wins, then Pending, and Verified only when every
                //  saved file for that requirement is verified). It simply
                //  counts how many requirements fall into each state so the
                //  summary strip below can reflect the real, current status
                //  the moment the page loads.
                //
                //  This touches nothing: it writes only to its own new
                //  $reqStatusCounts variable, runs before the card loop,
                //  and the card loop still computes its own $reqStatus per
                //  item exactly as before.
                // ════════════════════════════════════════════════════════
                $reqStatusCounts = ['verified' => 0, 'pending' => 0, 'denied' => 0, 'not-submitted' => 0];
                foreach ($reqDefsForType as $sKey => $sLabel) {
                    $sRows = $company_requirement_rows[$sKey] ?? [];
                    if (empty($sRows)) { $reqStatusCounts['not-submitted']++; continue; }
                    $sDenied = false; $sNonVerified = false;
                    foreach ($sRows as $sr) {
                        $sSt = $sr['status'] ?? 'Pending';
                        if (cfIsRejectedStatus($sSt)) $sDenied = true;
                        if ($sSt !== 'Verified') $sNonVerified = true;
                    }
                    if ($sDenied) $reqStatusCounts['denied']++;
                    elseif ($sNonVerified) $reqStatusCounts['pending']++;
                    else $reqStatusCounts['verified']++;
                }
                $reqTotalCount = count($reqDefsForType);
                ?>

                <div id="cfReqSummaryRegion" data-live-sig="<?= md5(json_encode([$reqStatusCounts, $reqTotalCount])) ?>" style="display:contents;"><!-- LIVE UPDATES region -->
                <?php if ($reqTotalCount > 0): ?>
                <!-- NEW (this revision): live status summary strip. Rendered straight
                     from the detection pass above, so it always matches the status
                     pills on the cards below. Only the states that actually occur are
                     shown, and the "all verified" banner replaces the counts entirely
                     once every requirement is verified. -->
                <?php
                // ADJUSTMENT: summary line + progress bar (same as AccomForm.php's requirement cards)
                $cfSumPct = $reqTotalCount ? (int) round($reqStatusCounts['verified'] / $reqTotalCount * 100) : 0;
                ?>
                <div class="cf-req-summary">
                    <span class="cf-req-summary-text">
                    <?php if ($reqStatusCounts['verified'] === $reqTotalCount): ?>
                        All <b><?= (int) $reqTotalCount ?></b> compliance documents verified &mdash; nothing further to submit.
                    <?php else: ?>
                        <b><?= (int) $reqTotalCount ?></b> requirements &middot;
                        <b><?= (int) $reqStatusCounts['verified'] ?></b> verified &middot;
                        <b><?= (int) $reqStatusCounts['pending'] ?></b> pending
                        <?php if ($reqStatusCounts['denied'] > 0): ?> &middot; <b><?= (int) $reqStatusCounts['denied'] ?></b> rejected &mdash; needs re-upload<?php endif; ?>
                        &middot; <b><?= (int) $reqStatusCounts['not-submitted'] ?></b> awaiting your submission
                    <?php endif; ?>
                    </span>
                    <div class="cf-progress">
                        <div class="cf-progress-bar"><div class="cf-progress-fill" style="width:<?= $cfSumPct ?>%;"></div></div>
                        <span class="cf-progress-pct"><?= $cfSumPct ?>% verified</span>
                    </div>
                </div>
                <?php endif; ?>
                </div><!-- /#cfReqSummaryRegion -->

                <div class="compliance-status-card">
                    <div class="req-grid">
                    <?php
                    $anyComplianceItemShown = false;
                    foreach ($reqDefsForType as $reqKey => $reqLabel):
                        $anyComplianceItemShown = true;
                        $isMoaExistingItem = array_key_exists($reqKey, $moa_existing_reqs);

                        // FIX (this update): $rowsForKey is now the FULL
                        // ARRAY of every company_requirements row saved
                        // under this requirement_type (see the fetch query
                        // above), not just one. A requirement with 2+ saved
                        // files renders as a single overlaying "stacked
                        // card" entry further down, mirroring the same
                        // visual already used for a fresh multi-file
                        // selection in company_register.php's Step 3.
                        $reqAllRows   = $company_requirement_rows[$reqKey] ?? [];
                        // UPDATED (this adjustment): when the admin REJECTS a requirement its file(s) are removed but the
                        // rows stay (status "Rejected" + the admin's remark), so that the company can see why. Only rows
                        // that still hold a file are files — for the count, the stacked card and the preview — while the
                        // status below is still worked out from EVERY row. Before, those emptied rows were counted as
                        // files, so a rejected requirement kept showing "N files" / a broken "Preview unavailable" tile.
                        $rowsForKey   = array_values(array_filter($reqAllRows, function ($rrf) { return !empty($rrf['file_name']); }));
                        $reqFileCount = count($rowsForKey);
                        $reqHasFile   = $reqFileCount > 0;

                        // Aggregate status across every file saved for this
                        // requirement: Denied takes priority (it needs the
                        // company's attention), then Pending, and only
                        // Verified when EVERY file for this requirement has
                        // individually been verified.
                        $reqStatus = null;
                        $reqRemark = '';
                        if (!empty($reqAllRows)) {
                            $hasDenied = false; $hasNonVerified = false;
                            foreach ($reqAllRows as $rr) {
                                $st = $rr['status'] ?? 'Pending';
                                if (cfIsRejectedStatus($st)) {
                                    $hasDenied = true;
                                    if (empty($reqRemark) && !empty($rr['remark'])) $reqRemark = $rr['remark'];
                                }
                                if ($st !== 'Verified') $hasNonVerified = true;
                            }
                            if ($hasDenied) $reqStatus = 'Rejected';
                            elseif ($hasNonVerified) $reqStatus = 'Pending';
                            else $reqStatus = 'Verified';
                        }

                        // Per-file metadata (id + whether it's a PDF) for
                        // the overlaying card / preview modal below.
                        $reqFileMetaList = [];
                        foreach ($rowsForKey as $rr) {
                            $isPdfRow = false;
                            if (!empty($rr['file_name'])) {
                                $finfo_rr = new finfo(FILEINFO_MIME_TYPE);
                                $mime_rr  = $finfo_rr->buffer($rr['file_name']);
                                $isPdfRow = (strpos($mime_rr, 'pdf') !== false || strpos($mime_rr, 'octet') !== false);
                            }
                            $reqFileMetaList[] = ['id' => (int) $rr['id'], 'isPdf' => $isPdfRow];
                        }
                        $reqFileMetaJson = htmlspecialchars(json_encode($reqFileMetaList), ENT_QUOTES);

                        // The internal token stays 'denied' (the status-aware CSS and JS below key on it); only the LABEL shown to the company changed.
                        $badgeClass = ($reqStatus === 'Rejected') ? 'denied' : ($reqStatus ? strtolower($reqStatus) : 'not-submitted');
                        $badgeLabel = $reqStatus ?: 'Not Submitted';
                        $cfCardSig  = cfLiveRowsSig($reqAllRows); // LIVE UPDATES: changes whenever this requirement's rows change
                    ?>
                    <!-- NEW (this revision) — STATUS DETECTION HOOKS.
                         The card now carries the requirement's real,
                         server-computed status (the very same $badgeClass /
                         $badgeLabel values that already render the status
                         pill inside it), plus whether it currently has any
                         saved file and how many. The status-aware CSS above
                         keys off data-req-status directly, so the card's
                         appearance updates the moment the page renders —
                         no click, no extra request. The script at the
                         bottom reads the same attributes to apply the
                         behavioural parts (drop-zone wording, the "Action
                         required" flag, and the Denied-only staged-note
                         rule). Purely additive attributes: no existing id,
                         class, input name, PHP branch or logic changed. -->
                    <div class="compliance-req-item"
                         data-req-status="<?= htmlspecialchars($badgeClass, ENT_QUOTES) ?>"
                         data-req-status-label="<?= htmlspecialchars($badgeLabel, ENT_QUOTES) ?>"
                         data-req-key="<?= htmlspecialchars($reqKey, ENT_QUOTES) ?>"
                         data-req-has-file="<?= $reqHasFile ? '1' : '0' ?>"
                         data-req-file-count="<?= (int) $reqFileCount ?>"
                         data-live-sig="<?= htmlspecialchars($cfCardSig, ENT_QUOTES) ?>">
                        <div class="compliance-req-top">
                            <!-- ADJUSTMENT (this revision): the requirement name is now the
                                 FIRST element in the card, above the file preview. Same
                                 markup, same .req-info class and same $reqLabel / MOA-tag
                                 output as before — only its position in the card moved. -->
                            <div class="req-info">
                                <?= htmlspecialchars($reqLabel) ?>
                                <?php if ($isMoaExistingItem): ?>
                                    <span class="req-moa-tag">MOA Upload</span>
                                <?php endif; ?>
                            </div>

                            <!-- NEW: every file-preview variant (single thumb/PDF tile, the
                                 multi-file stack, or the empty placeholder) now lives inside
                                 this single wrapper so JS can swap it out as one unit the
                                 instant the company selects a new/replacement file. -->
                            <?php
                            // ADJUSTMENT (this revision): Pending / Rejected use the admin panel's display —
                            // status pill on the preview's top-right corner (see .req-card-ribbon CSS).
                            $reqUsesRibbon = true; // ADJUSTMENT: the status pill is now shown for every state (Verified / Pending / Rejected / Not Submitted), like AccomForm.php
                            $reqRejPanel   = ($reqStatus === 'Rejected' && !$reqHasFile); // preview is the "Awaiting re-upload" placeholder
                            ?>
                            <div class="req-preview-wrap<?= $reqRejPanel ? ' req-preview-wrap--rejected' : '' ?>">
                            <div class="req-preview-slot" id="reqPreviewSlot_<?= htmlspecialchars($reqKey) ?>" data-req-key="<?= htmlspecialchars($reqKey, ENT_QUOTES) ?>">
                            <?php if ($reqHasFile && $reqFileCount === 1): ?>
                                <!-- Single saved file — same plain thumbnail / PDF badge as before,
                                     now enlarged (see .req-thumb-wrap / .req-thumb-img CSS) to match
                                     the height of the upload/reupload button. -->
                                <?php if ($reqFileMetaList[0]['isPdf']): ?>
                                    <div class="req-thumb-wrap creq-preview-trigger" data-req-key="<?= htmlspecialchars($reqKey, ENT_QUOTES) ?>" data-req-files="<?= $reqFileMetaJson ?>" data-req-label="<?= htmlspecialchars($reqLabel, ENT_QUOTES) ?>" title="Preview <?= htmlspecialchars($reqLabel) ?>">
                                        <i class="fas fa-file-pdf"></i>
                                    </div>
                                <?php else: ?>
                                    <!-- ADJUSTMENT (this revision): if this file can no longer be streamed
                                         back (e.g. the admin removed the blob when denying it, or the stored
                                         bytes aren't a renderable image), the browser would otherwise show a
                                         broken-image icon with the alt/title text spilling across the tile.
                                         The inline onerror below swaps the <img> for the SAME neutral
                                         placeholder tile used by a requirement with no file yet, so the card
                                         keeps its shape. It is self-contained (no helper function, so it works
                                         even if the error fires before the page's scripts run) and touches
                                         nothing else — the status badge, denial reason and upload area are
                                         all still rendered exactly as before. -->
                                    <img class="req-thumb-img creq-preview-trigger" data-req-key="<?= htmlspecialchars($reqKey, ENT_QUOTES) ?>" data-req-files="<?= $reqFileMetaJson ?>" data-req-label="<?= htmlspecialchars($reqLabel, ENT_QUOTES) ?>"
                                        src="CompanyForm.php?stream_own_requirement=<?= urlencode($reqKey) ?>&file_id=<?= (int) $reqFileMetaList[0]['id'] ?>"
                                        alt=""
                                        onerror="this.onerror=null;var d=document.createElement('div');d.className='req-thumb-empty';d.setAttribute('aria-hidden','true');var ic=document.createElement('i');ic.className='fas fa-file-circle-xmark';var sp=document.createElement('span');sp.textContent='Preview unavailable';d.appendChild(ic);d.appendChild(sp);if(this.parentNode){this.parentNode.replaceChild(d,this);}"
                                        title="Preview <?= htmlspecialchars($reqLabel) ?>">
                                <?php endif; ?>
                            <?php elseif ($reqHasFile && $reqFileCount > 1): ?>
                                <!-- FIX (this update): 2+ saved files for this requirement — show as one
                                     overlaying "stacked card" entry instead of only ever displaying the
                                     last-fetched row. Click opens the preview modal, which can page
                                     through every file via prev/next (see the JS further down).
                                     ADJUSTMENT (this revision): stack enlarged (see .req-file-stack CSS)
                                     to match the enlarged single-file thumbnail / button height. -->
                                <div class="req-file-stack-wrap creq-preview-trigger" data-req-key="<?= htmlspecialchars($reqKey, ENT_QUOTES) ?>" data-req-files="<?= $reqFileMetaJson ?>" data-req-label="<?= htmlspecialchars($reqLabel, ENT_QUOTES) ?>" title="Preview all <?= (int) $reqFileCount ?> files for <?= htmlspecialchars($reqLabel) ?>">
                                    <div class="req-file-stack">
                                        <?php
                                        $layerCount = min(3, $reqFileCount);
                                        for ($li = $layerCount - 1; $li >= 0; $li--):
                                            $layerMeta = $reqFileMetaList[$li];
                                        ?>
                                        <?php if ($layerMeta['isPdf']): ?>
                                            <div class="req-stack-layer req-stack-layer-pdf layer-<?= $li + 1 ?>"><i class="fas fa-file-pdf"></i></div>
                                        <?php else: ?>
                                            <div class="req-stack-layer layer-<?= $li + 1 ?>" style="background-image:url('CompanyForm.php?stream_own_requirement=<?= urlencode($reqKey) ?>&file_id=<?= (int) $layerMeta['id'] ?>');"></div>
                                        <?php endif; ?>
                                        <?php endfor; ?>
                                        <span class="req-stack-count-badge"><?= (int) $reqFileCount ?></span>
                                    </div>
                                    <div class="req-file-stack-label"><?= (int) $reqFileCount ?> files</div>
                                </div>
                            <?php else: ?>
                                <!-- ADJUSTMENT (this revision): nothing has been uploaded for this
                                     requirement yet, so the preview slot shows a neutral placeholder
                                     tile instead of collapsing. This keeps every card in the grid the
                                     same shape, exactly as in the supplied design. It is decorative
                                     only — no preview trigger, no data attributes, no inputs, and it
                                     never renders once a file exists. -->
                                <?php if ($reqStatus === 'Rejected'): ?>
                                <!-- ADJUSTMENT (this revision): a rejected requirement's file was removed by the admin —
                                     shown with the same pink dashed "Rejected / Awaiting re-upload" placeholder the
                                     admin panel uses (company_validation.php's .cv-rej-placeholder). -->
                                <div class="req-rej-placeholder" aria-hidden="true">
                                    <i class="fas fa-file-circle-xmark"></i>
                                    <span>Awaiting re-upload</span>
                                </div>
                                <?php else: ?>
                                <div class="req-thumb-empty" aria-hidden="true">
                                    <i class="fas fa-hourglass-half"></i>
                                    <span>No file yet</span>
                                </div>
                                <?php endif; ?>
                            <?php endif; ?>
                            </div>
                            <?php if ($reqUsesRibbon): ?>
                            <!-- ADJUSTMENT (this revision): the status pill on the preview's top-right corner. It keeps the
                                 same id (reqStatusBadge_<key>) and status class the staging JS below already targets. -->
                            <div class="req-card-ribbon <?= htmlspecialchars($badgeClass) ?>" id="reqStatusBadge_<?= htmlspecialchars($reqKey) ?>">
                                <?php if ($reqStatus === 'Rejected'): ?>
                                    <span class="req-rb req-rb-rejected"><i class="fas fa-ban"></i> <?= htmlspecialchars($badgeLabel) ?></span>
                                <?php elseif ($reqStatus === 'Verified'): ?>
                                    <span class="req-rb req-rb-verified"><i class="fas fa-check"></i> <?= htmlspecialchars($badgeLabel) ?></span>
                                <?php elseif ($reqStatus): ?>
                                    <span class="req-rb req-rb-pending"><?= htmlspecialchars($badgeLabel) ?></span>
                                <?php else: ?>
                                    <span class="req-rb req-rb-awaiting"><?= htmlspecialchars($badgeLabel) ?></span>
                                <?php endif; ?>
                            </div>
                            <?php endif; ?>
                            </div><!-- /.req-preview-wrap -->

                            <!-- ADJUSTMENT (this revision): wrapped in .compliance-req-info so the
                                 name / status block is its own vertically-centered flex column
                                 between the file display and the action button.

                                 ── UPDATED (this adjustment): status, the denial remark, and the
                                 "action required" reminder used to be three separate stacked
                                 elements (a status-badge pill, a remark-badge, and a CSS-only
                                 "req-action-required" flag) — they are now ONE combined info box
                                 (.req-status-combined) built from the exact same $badgeClass /
                                 $badgeLabel / $reqRemark values as before, just presented together.
                                 It keeps the same id (reqStatusBadge_<key>) the staging JS below
                                 already hides on file-select, so that behavior is unchanged; the
                                 separate remark-badge id and the CSS-only "req-action-required"
                                 flag are retired since everything now lives in this one element. -->
                            <?php /* ADJUSTMENT (this revision): a Verified requirement no longer shows the green "Verified" status box above the
                                      "Verified — no further action needed" lock — the info block is hidden entirely so it leaves no empty gap. */ ?>
                            <?php /* ADJUSTMENT (this revision): a Pending requirement now shows only the corner pill above, so its info block is hidden too (no empty gap). A Rejected one keeps this block for the "Remark:" box below. */ ?>
                            <div class="compliance-req-info"<?= ($reqStatus === 'Verified' || $reqStatus === 'Pending' || !$reqStatus) ? ' style="display:none;"' : '' ?>>
                                <?php if ($reqStatus === 'Rejected'): ?>
                                <!-- ADJUSTMENT (this revision): the admin's rejection remark in its own pink "Remark:" box
                                     (company_validation.php's .cv-card-remark). Same $reqRemark value as before; hidden by JS
                                     the moment a replacement file is staged, together with the pill. -->
                                <div class="req-card-remark" id="reqRemarkBox_<?= htmlspecialchars($reqKey) ?>"><i class="fas fa-comment-dots"></i><span><b>Remark:</b> <span class="req-card-remark-text"><?= htmlspecialchars(((string) $reqRemark) !== '' ? (string) $reqRemark : '—') ?></span></span></div>
                                <?php endif; ?>
                                <?php if (!$reqUsesRibbon && $reqStatus !== 'Verified'): ?>
                                <div class="req-status-combined <?= htmlspecialchars($badgeClass) ?>" id="reqStatusBadge_<?= htmlspecialchars($reqKey) ?>">
                                    <div class="req-status-combined-top">
                                        <?php if ($reqStatus === 'Verified'): ?>
                                            <i class="fas fa-check-circle"></i>
                                        <?php elseif ($reqStatus === 'Rejected'): ?>
                                            <i class="fas fa-times-circle"></i>
                                        <?php elseif ($reqStatus): ?>
                                            <i class="fas fa-clock"></i>
                                        <?php else: ?>
                                            <i class="fas fa-minus-circle"></i>
                                        <?php endif; ?>
                                        <span><?= htmlspecialchars($badgeLabel) ?></span>
                                    </div>
                                    <?php if ($reqStatus === 'Rejected'): ?>
                                        <?php if (!empty($reqRemark)): ?>
                                        <div class="req-status-combined-detail">Reason: <?= htmlspecialchars($reqRemark) ?></div>
                                        <?php endif; ?>
                                        <div class="req-status-combined-detail">Please re-upload this document.</div>
                                    <?php endif; ?>
                                </div>
                                <?php endif; ?>

                                <!-- NEW: hidden-by-default note shown by JS in place of the combined
                                     status box above the instant a replacement file is staged for
                                     THIS item — but only when this item was originally Denied (see
                                     the wasDenied check in the change handler in the script below). -->
                                <div class="req-staged-note" id="reqStagedNote_<?= htmlspecialchars($reqKey) ?>" style="display:none;">
                                    <i class="fas fa-rotate"></i> New file selected — ready to resubmit
                                </div>
                            </div>

                            <!-- ADJUSTMENT (this revision): the verified-lock badge / upload button
                                 is now its own .compliance-req-action flex column, vertically
                                 centered beside the file display and info block above, instead of
                                 being stacked underneath the status badge inside the info column. -->
                            <div class="compliance-req-action">
                                <?php if ($reqStatus === 'Verified'): ?>
                                    <div class="verified-lock">
                                        <i class="fas fa-check-circle"></i> Verified — no further action needed
                                    </div>
                                <?php else: ?>
                                    <div class="req-file-drop" id="reqDrop_c_<?= htmlspecialchars($reqKey) ?>" onclick="document.getElementById('reqFileInput_<?= htmlspecialchars($reqKey) ?>').click()">
                                        <span class="req-file-icon"><i class="fas fa-upload"></i></span>
                                        <span class="req-file-text" id="reqFileText_<?= htmlspecialchars($reqKey) ?>">
                                            <?= ($reqStatus === 'Rejected') ? 'Re-upload' : ($reqHasFile ? 'Click to replace file(s)' : 'Click to upload') ?>
                                        </span>
                                    </div>
                                    <!-- FIX (this update): now accepts multiple files (name="req_<key>[]"),
                                         matching company_register.php's Step 3 requirement uploads. A hidden
                                         "_expected_count" field (kept in sync by JS below) lets the server
                                         detect and report a request that silently loses files in transit. -->
                                    <input type="file" id="reqFileInput_<?= htmlspecialchars($reqKey) ?>" name="req_<?= htmlspecialchars($reqKey) ?>[]" multiple
                                        class="compliance-file-input" data-label="<?= htmlspecialchars($reqLabel) ?>" data-req-key="<?= htmlspecialchars($reqKey, ENT_QUOTES) ?>"
                                        accept="image/*,.pdf,application/pdf" style="display:none;">
                                    <input type="hidden" id="reqCount_c_<?= htmlspecialchars($reqKey) ?>" name="req_<?= htmlspecialchars($reqKey) ?>_expected_count" value="0">
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                    <?php endforeach; ?>
                    </div>

                    <?php if ($anyComplianceItemShown): ?>
                        <button type="submit" name="submit_compliance_docs" value="1" class="submit-all">
                            <i class="fas fa-paper-plane" style="margin-right:8px;"></i>Submit Requirement Documents
                        </button>
                    <?php else: ?>
                        <div class="req-awaiting-ui">
                            <i class="fas fa-info-circle"></i>
                            No compliance documents are currently required for your classification.
                        </div>
                        <?php // ADJUSTMENT: the "Save Changes" button for the editable profile fields now lives on the Company Information page. ?>
                    <?php endif; ?>
                </div>

                </div><!-- /#cf-requirements-page -->

            </form>
        </div>
    </div>
</div>

<script>
/* ADJUSTMENT: page switcher (Company Information / Requirements) — same behaviour as AccomForm.php */
(function () {
    var KEY = 'companyFormActiveTab';
    var initial = <?= json_encode($cfInitialPage) ?>;
    var params = new URLSearchParams(window.location.search);
    var saved = null;
    try { saved = sessionStorage.getItem(KEY); } catch (e) {}
    /* an upload error belongs to the Requirements page; a revision flag opens it too */
    var target = (params.get('msg') === 'upload_error') ? 'cf-requirements-page'
               : (initial === 'cf-requirements-page' ? initial : (saved || initial));
    function switchPage(id) {
        if (!document.getElementById(id)) id = 'cf-info-page';
        document.querySelectorAll('.cf-page-content').forEach(function (p) { p.classList.remove('cf-active-page'); });
        document.querySelectorAll('.cf-switch-page-btn').forEach(function (b) { b.classList.remove('active'); });
        var pg = document.getElementById(id); if (pg) pg.classList.add('cf-active-page');
        var bt = document.querySelector('.cf-switch-page-btn[data-page="' + id + '"]'); if (bt) bt.classList.add('active');
    }
    document.querySelectorAll('.cf-switch-page-btn').forEach(function (btn) {
        btn.addEventListener('click', function () {
            var id = this.getAttribute('data-page');
            switchPage(id);
            try { sessionStorage.setItem(KEY, id); } catch (e) {}
        });
    });
    switchPage(target);
})();
</script>

<!-- LIVE UPDATES: baseline state for the auto-refresh script at the bottom of this file (not executed — JSON only). -->
<script type="application/json" id="cfLiveState"><?= json_encode($cfLiveState, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?></script>

<script>
    /* ══════════════════════════════════════════════════════════
       NEW (this adjustment): global loading/processing overlay controls
       — copied faithfully from admin_company_list.php's own
       showGlobalLoading()/hideGlobalLoading() (same usage-counter
       pattern, so overlapping calls can never hide it prematurely).
       showGlobalLoading(label) reveals the popup with an optional custom
       label ("Loading…", "Saving…", "Creating MOA…", etc.).
       hideGlobalLoading() fades it out once every in-flight operation
       that asked for it has actually finished.
       ══════════════════════════════════════════════════════════ */
    let globalLoadingActiveCount = 0;
    const globalLoadingOverlay = document.getElementById('globalLoadingOverlay');
    const globalLoadingLabel = document.getElementById('globalLoadingLabel');

    function showGlobalLoading(label) {
        globalLoadingActiveCount++;
        if (globalLoadingLabel) globalLoadingLabel.textContent = label || 'Loading';
        if (globalLoadingOverlay) {
            globalLoadingOverlay.classList.remove('success-state');   // ADJUSTMENT (action loading page): a new action starts as a spinner again
            globalLoadingOverlay.classList.remove('hidden');
        }
    }

    function hideGlobalLoading() {
        globalLoadingActiveCount = Math.max(0, globalLoadingActiveCount - 1);
        if (globalLoadingActiveCount === 0 && globalLoadingOverlay) {
            globalLoadingOverlay.classList.add('hidden');
        }
    }

    /* ADJUSTMENT (action loading page): success state of the same full-page loader (check + message + the areas
       that were updated), plus a safe JSON reader. See the .gls-* styles above. */
    let globalSuccessTimer = null;   // ADJUSTMENT (action loading page)
    function setGlobalLoadingLabel(label) {
        if (globalLoadingLabel) globalLoadingLabel.textContent = label || 'Loading';
    }

    /* opts: areas [{title, fields[]}] – what was updated · warnings [text] · sub – small line under the message
             button (false hides "Continue") · autoCloseMs · keepOpen (leave the screen up, the page is about to reload)
             onDone() – called once when the screen is closed (or, with keepOpen, when the time is up) */
    function showGlobalSuccess(title, message, opts) {
        opts = opts || {};
        var byId = function (id) { return document.getElementById(id); };
        var t = byId('globalLoadingSuccessTitle'), m = byId('globalLoadingSuccessMsg'), list = byId('globalLoadingSuccessAreas'),
            warn = byId('globalLoadingSuccessWarn'), sub = byId('globalLoadingSuccessSub'), btn = byId('globalLoadingContinueBtn');
        if (!globalLoadingOverlay || !t || !m || !list || !warn || !sub || !btn) {   /* markup missing: never leave an action unreported */
            if (globalLoadingActiveCount > 0) hideGlobalLoading();
            window.alert((title || 'Done') + (message ? '\n\n' + message : ''));
            if (opts.onDone) opts.onDone();
            return;
        }
        if (globalLoadingActiveCount === 0) globalLoadingActiveCount = 1;
        if (globalSuccessTimer) { clearTimeout(globalSuccessTimer); globalSuccessTimer = null; }

        t.textContent = title || 'Success';
        m.textContent = message || '';
        list.textContent = '';
        (opts.areas || []).forEach(function (a) {
            var box = document.createElement('div');  box.className = 'gls-area';
            var h   = document.createElement('div');  h.className   = 'gls-area-title';  h.textContent = a.title || '';
            var f   = document.createElement('div');  f.className   = 'gls-area-fields';
            (a.fields || []).forEach(function (x) {
                var c = document.createElement('span'); c.className = 'gls-chip'; c.textContent = x; f.appendChild(c);
            });
            box.appendChild(h); box.appendChild(f); list.appendChild(box);
        });
        warn.textContent = (opts.warnings || []).join(' ');
        sub.textContent = '';
        if (opts.sub) {
            var ic = document.createElement('i'); ic.className = 'fas fa-sync-alt fa-spin';
            sub.appendChild(ic); sub.appendChild(document.createTextNode(' ' + opts.sub));
        }
        btn.style.display = (opts.button === false) ? 'none' : '';

        globalLoadingOverlay.classList.add('success-state');
        globalLoadingOverlay.classList.remove('hidden');

        var finished = false;
        function finish() {
            if (finished) return;
            finished = true;
            if (globalSuccessTimer) { clearTimeout(globalSuccessTimer); globalSuccessTimer = null; }
            if (!opts.keepOpen) {
                hideGlobalLoading();
                setTimeout(function () {
                    if (globalLoadingOverlay.classList.contains('hidden')) globalLoadingOverlay.classList.remove('success-state');
                }, 400);
            }
            if (opts.onDone) opts.onDone();
        }
        btn.onclick = finish;
        globalSuccessTimer = setTimeout(finish, opts.autoCloseMs || 4500);
        if (btn.style.display !== 'none') { try { btn.focus(); } catch (e) {} }
    }

    /* Reads a fetch() response as JSON without throwing on a PHP warning / error page (then returns null). */
    function parseJsonSafe(text) {
        try { return JSON.parse(text); } catch (e) { return null; }
    }


    /* The overlay is visible by default (see CSS) so it covers the very
       first paint while page assets are still loading. As soon as the
       window has fully finished loading, it fades away on its own. */
    /* ADJUSTMENT (action loading page): only the INITIAL page-load cover is finished here. The 'load' event and the
       4-second safety net below used to force the counter to 0 and hide the overlay unconditionally, which would hide
       an action's loading page (Saving / Creating MOA / Submitting) that had been started in the meantime. */
    let initialPageLoadPending = true;
    function finishInitialPageLoad() {
        if (!initialPageLoadPending) return;
        initialPageLoadPending = false;
        if (globalLoadingActiveCount === 0 && globalLoadingOverlay) globalLoadingOverlay.classList.add('hidden');
    }
    window.addEventListener('load', finishInitialPageLoad);
    /* Safety net: if for any reason the 'load' event is delayed (slow
       third-party assets like the Font Awesome CDN), don't leave the
       company staring at the popup forever — hide it after a short
       ceiling too. */
    setTimeout(finishInitialPageLoad, 4000);

    // Sidebar Toggle Script
    const sidebar = document.getElementById('sidebar');
    const toggleBtn = document.getElementById('toggleBtn');

    toggleBtn.addEventListener('click', () => {
        sidebar.classList.toggle('collapsed');
    });
    // IMAGE PREVIEW
    function openPreview(src) {
        document.getElementById("previewImage").src = src;
        document.getElementById("imagePreviewModal").style.display = "flex";
    }
    function closePreview() {
        document.getElementById("imagePreviewModal").style.display = "none";
    }
    document.getElementById("imagePreviewModal").addEventListener("click", function(e){
        if(e.target === this){ closePreview(); }
    });

    // MOA DOCUMENT PREVIEW MODAL (redesigned full-bleed dark viewer,
    // mirroring the document viewer design used in moa_request.php)
    const moaIsPdfDoc = <?= $moa_is_pdf ? 'true' : 'false' ?>;
    function openMoaReview() {
        const modal      = document.getElementById('moaDocPreviewModal');
        const viewerWrap = document.getElementById('moaDocPreviewViewerWrap');
        const nameLabel  = document.getElementById('moaDocPreviewName');
        if (nameLabel) nameLabel.textContent = 'MOA Document';

        viewerWrap.innerHTML = '';
        viewerWrap.classList.remove('image-mode');

        if (moaIsPdfDoc) {
            const iframe = document.createElement('iframe');
            iframe.id = 'moaDocPreviewFrame';
            iframe.title = 'MOA Document Preview';
            iframe.src = 'CompanyForm.php?stream_own_moa=1';
            viewerWrap.appendChild(iframe);
        } else {
            viewerWrap.classList.add('image-mode');
            const img = document.createElement('img');
            img.className = 'moa-doc-modal-image';
            img.src = 'CompanyForm.php?stream_own_moa=1';
            img.alt = 'MOA Document Preview';
            viewerWrap.appendChild(img);
        }

        modal.style.display = 'flex';
        document.body.style.overflow = 'hidden'; // lock background page scroll so
                                                   // only the document viewer scrolls
    }
    // Kept so any existing references to the previous function name still work
    function openOwnMoaPreview() { openMoaReview(); }
    function closeOwnMoaPreview() {
        const modal      = document.getElementById('moaDocPreviewModal');
        const viewerWrap = document.getElementById('moaDocPreviewViewerWrap');
        modal.style.display = 'none';
        viewerWrap.innerHTML = '';
        document.body.style.overflow = ''; // restore normal page scrolling
    }
    document.getElementById('moaDocPreviewModal').addEventListener('click', function(e){
        if (e.target === this) closeOwnMoaPreview();
    });

    // ── NEW (this adjustment): SIGNING SCHEDULE CONFIRMATION — the
    // company representative's response to a schedule the admin proposed.
    // Same fetch()-based pattern as the MOA Revision Compliance panel
    // below (this panel also sits inside the page's one big <form>).
    function showScheduleDeclineForm(){
        const initial = document.getElementById('moaSchedInitialActions');
        const form = document.getElementById('moaSchedDeclineForm');
        if (initial) initial.style.display = 'none';
        if (form) form.style.display = 'block';
        // The proposed date can't be in the past either — same rule the
        // admin's own calendar picker enforces.
        const dateInput = document.getElementById('moaSchedProposeDate');
        if (dateInput) {
            const today = new Date();
            dateInput.min = today.getFullYear() + '-' + String(today.getMonth()+1).padStart(2,'0') + '-' + String(today.getDate()).padStart(2,'0');
        }
    }
    function hideScheduleDeclineForm(){
        const initial = document.getElementById('moaSchedInitialActions');
        const form = document.getElementById('moaSchedDeclineForm');
        if (form) form.style.display = 'none';
        if (initial) initial.style.display = 'flex';
    }
    function respondToSchedule(action){
        const feedback = document.getElementById('moaSchedResponseFeedback');
        if (feedback) { feedback.classList.remove('show', 'error', 'success'); feedback.textContent = ''; }

        const fd = new FormData();
        fd.append('submit_moa_schedule_response', '1');
        fd.append('action', action);

        let agreeBtn = null, agreeSpinner = null, declineBtn = null, declineSpinner = null;

        if (action === 'agree') {
            agreeBtn = document.getElementById('moaSchedAgreeBtn');
            agreeSpinner = document.getElementById('moaSchedAgreeSpinner');
            if (agreeBtn) agreeBtn.disabled = true;
            if (agreeSpinner) agreeSpinner.style.display = 'inline-block';
        } else if (action === 'decline') {
            const reason = document.getElementById('moaSchedDeclineReason').value.trim();
            const proposeDate = document.getElementById('moaSchedProposeDate').value;
            const proposeTime = document.getElementById('moaSchedProposeTime').value;

            if (!reason) {
                if (feedback) { feedback.textContent = "Please tell us why you're not available at this time."; feedback.classList.add('show', 'error'); }
                return;
            }
            if (!proposeDate || !proposeTime) {
                if (feedback) { feedback.textContent = 'Please propose an alternative date and time.'; feedback.classList.add('show', 'error'); }
                return;
            }
            fd.append('reason', reason);
            fd.append('propose_date', proposeDate);
            fd.append('propose_time', proposeTime);

            declineBtn = document.getElementById('moaSchedDeclineSubmitBtn');
            declineSpinner = document.getElementById('moaSchedDeclineSpinner');
            if (declineBtn) declineBtn.disabled = true;
            if (declineSpinner) declineSpinner.style.display = 'inline-block';
        } else {
            return;
        }

        if (typeof showGlobalLoading === 'function') showGlobalLoading(action === 'agree' ? 'Confirming' : 'Sending');

        fetch('CompanyForm.php', { method: 'POST', body: fd })
            .then(function(r) { return r.json(); })
            .then(function(data) {
                if (typeof hideGlobalLoading === 'function') hideGlobalLoading();
                if (!data.success) {
                    if (agreeBtn) agreeBtn.disabled = false;
                    if (agreeSpinner) agreeSpinner.style.display = 'none';
                    if (declineBtn) declineBtn.disabled = false;
                    if (declineSpinner) declineSpinner.style.display = 'none';
                    if (feedback) { feedback.textContent = data.message || 'Something went wrong. Please try again.'; feedback.classList.add('show', 'error'); }
                    return;
                }
                if (feedback) { feedback.textContent = data.message || 'Your response has been recorded.'; feedback.classList.add('show', 'success'); }
                setTimeout(function() { window.location.href = 'CompanyForm.php'; }, 1400);
            })
            .catch(function() {
                if (typeof hideGlobalLoading === 'function') hideGlobalLoading();
                if (agreeBtn) agreeBtn.disabled = false;
                if (agreeSpinner) agreeSpinner.style.display = 'none';
                if (declineBtn) declineBtn.disabled = false;
                if (declineSpinner) declineSpinner.style.display = 'none';
                if (feedback) { feedback.textContent = 'Network error — please try again.'; feedback.classList.add('show', 'error'); }
            });
    }

    // ── NEW (this adjustment): MOA REVISION COMPLIANCE — submits just the
    // flagged field(s) shown in #moaRevisionPanel via fetch() (deliberately
    // NOT a native form submit, since this panel sits inside the page's one
    // big <form> and nested <form> elements aren't valid HTML). On success,
    // reloads the page so the panel disappears and the MOA Document Status
    // section reflects the freshly regenerated document.
    //
    // ── FIX (this adjustment) — "Please fill in every flagged field"
    // showing even after the field was filled in: this used to read a
    // SEPARATE, PHP-computed id list (MOA_REVISION_FIELD_IDS) and look each
    // one up by id. If that list and what's actually rendered in the panel
    // ever fell out of sync for any reason, getElementById() would silently
    // return null for a field, the loop would just `return` past it without
    // flagging it as empty OR appending it to the form data — so the
    // client-side check would pass, but the field's value would never
    // actually be sent, and the server (which re-validates independently)
    // would then correctly report it as missing. Reading every
    // .moa-revision-input element straight out of the DOM instead removes
    // that indirection entirely: whatever is actually on the page is
    // exactly what gets checked and submitted, with no separate list that
    // could ever drift out of sync.
    function submitMoaRevision() {
        const btn = document.getElementById('moaRevisionSubmitBtn');
        const spinner = document.getElementById('moaRevisionSpinner');
        const feedback = document.getElementById('moaRevisionFeedback');

        feedback.classList.remove('show', 'error', 'success');
        feedback.textContent = '';

        const fd = new FormData();
        fd.append('submit_moa_revision', '1');

        let hasEmpty = false;
        document.querySelectorAll('#moaRevisionPanel .moa-revision-input').forEach(function(el) {
            const key = (el.id || '').replace(/^moaRev_/, '');
            if (!key) return;
            const val = el.value.trim();
            if (val === '') hasEmpty = true;
            fd.append('moa_rev_' + key, val);
        });

        if (hasEmpty) {
            feedback.textContent = 'Please fill in every flagged field before submitting.';
            feedback.classList.add('show', 'error');
            return;
        }

        if (btn) btn.disabled = true;
        if (spinner) spinner.style.display = 'inline-block';
        showGlobalLoading('Updating MOA');

        fetch('CompanyForm.php', { method: 'POST', body: fd })
            .then(function(r) { return r.text(); })
            .then(function(text) {
                // ADJUSTMENT (action loading page): a PHP warning / error page instead of JSON is reported, not swallowed
                const data = parseJsonSafe(text);
                if (!data) throw new Error('bad response');
                if (!data.success) {
                    hideGlobalLoading();
                    if (btn) btn.disabled = false;
                    if (spinner) spinner.style.display = 'none';
                    feedback.textContent = data.message || 'Something went wrong. Please try again.';
                    feedback.classList.add('show', 'error');
                    return;
                }
                feedback.textContent = data.message || 'Your MOA has been updated and resubmitted for review.';
                feedback.classList.add('show', 'success');
                // ADJUSTMENT (action loading page): confirmation naming the corrected fields
                const keyMap = { company_name: 'company', company_profile: 'company_profile', company_address: 'company_address', position: 'position',
                                 contact_first_name: 'contact_first_name', contact_middle_name: 'contact_middle_initial', contact_last_name: 'contact_last_name', telephone: 'telephone' };
                const sent = [];
                document.querySelectorAll('#moaRevisionPanel .moa-revision-input').forEach(function(el) {
                    const k = (el.id || '').replace(/^moaRev_/, '');
                    if (keyMap[k]) sent.push(keyMap[k]);
                });
                const areas = cfAreasFromFields(sent);
                const regenerated = data.regenerated !== false;
                if (regenerated) areas.push({ title: 'MOA Document', fields: ['Regenerated and resubmitted for review'] });
                showGlobalSuccess(
                    'MOA Updated',
                    regenerated ? 'Your MOA has been updated and resubmitted for review.' : 'Your corrections were saved and resubmitted for review.',
                    {
                        areas: areas,
                        warnings: regenerated ? [] : [data.message || 'The MOA document could not be regenerated automatically — the administrator will follow up if needed.'],
                        sub: 'Refreshing the page...', button: false, keepOpen: true, autoCloseMs: regenerated ? 2800 : 4500,
                        onDone: function() { window.location.replace('CompanyForm.php'); }
                    }
                );
            })
            .catch(function(err) {
                hideGlobalLoading();
                if (btn) btn.disabled = false;
                if (spinner) spinner.style.display = 'none';
                feedback.textContent = 'Network error — please try again.';
                feedback.classList.add('show', 'error');
            });
    }

    // ── NEW (this adjustment): MOA INITIAL CREATION — saves the unlocked
    // company-info fields and generates the company's first MOA from them
    // (see submit_moa_creation near the top of this file). Same fetch()
    // pattern as submitMoaRevision() above, for the same reason (avoids
    // nesting a second <form> inside the page's existing one).
    const MOA_CREATION_FIELD_IDS = [
        'company', 'company_address', 'contact_first_name', 'contact_last_name',
        'contact_middle_initial', 'position', 'telephone', 'company_profile'
    ];

    /* ADJUSTMENT (action loading page): turns saved Company Information field keys into the AREAS shown on the
       success loading page — same grouping as the server's cfDiffInfoAreas(). */
    const CF_AREA_FIELDS = {
        'Contact Person':  { contact_first_name: 'Contact First Name', contact_middle_initial: 'Contact Middle Name', contact_last_name: 'Contact Last Name', position: 'Position', telephone: 'Telephone / Contact Number' },
        'Company Details': { company: 'Company Name', company_address: 'Complete Office Address', company_profile: 'Company Profile' }
    };
    function cfAreasFromFields(keys) {
        const areas = [];
        Object.keys(CF_AREA_FIELDS).forEach(function(title) {
            const fields = [];
            Object.keys(CF_AREA_FIELDS[title]).forEach(function(k) { if (keys.indexOf(k) !== -1) fields.push(CF_AREA_FIELDS[title][k]); });
            if (fields.length) areas.push({ title: title, fields: fields });
        });
        return areas;
    }

    function submitMoaCreation() {
        const btn = document.getElementById('moaCreationSubmitBtn');
        const spinner = document.getElementById('moaCreationSpinner');
        const feedback = document.getElementById('moaCreationFeedback');

        feedback.classList.remove('show', 'error', 'success');
        feedback.textContent = '';

        const fd = new FormData();
        fd.append('submit_moa_creation', '1');

        let hasEmpty = false;
        MOA_CREATION_FIELD_IDS.forEach(function(key) {
            const el = document.getElementById('cfField_' + key);
            if (!el) return;
            const val = el.value.trim();
            // Contact Middle Name is the one optional field, matching the
            // "N/A" fallback already used elsewhere on this page for it.
            if (val === '' && key !== 'contact_middle_initial') hasEmpty = true;
            fd.append(key, val);
        });

        if (hasEmpty) {
            feedback.textContent = 'Please fill in every field before creating your MOA.';
            feedback.classList.add('show', 'error');
            return;
        }

        if (btn) btn.disabled = true;
        if (spinner) spinner.style.display = 'inline-block';
        showGlobalLoading('Creating MOA');

        fetch('CompanyForm.php', { method: 'POST', body: fd })
            .then(function(r) { return r.text(); })
            .then(function(text) {
                // ADJUSTMENT (action loading page): a PHP warning / error page instead of JSON is reported, not swallowed
                const data = parseJsonSafe(text);
                if (!data) throw new Error('bad response');
                if (!data.success) {
                    hideGlobalLoading();
                    if (btn) btn.disabled = false;
                    if (spinner) spinner.style.display = 'none';
                    feedback.textContent = data.message || 'Something went wrong. Please try again.';
                    feedback.classList.add('show', 'error');
                    return;
                }
                feedback.textContent = data.message || 'Your MOA has been created and submitted for review.';
                feedback.classList.add('show', 'success');
                // ADJUSTMENT (action loading page): the loading screen turns into the confirmation, naming what was saved
                const created = data.created !== false;
                const areas = cfAreasFromFields(MOA_CREATION_FIELD_IDS.map(function(key) {
                    const el = document.getElementById('cfField_' + key);
                    return (el && (el.value.trim() !== '' || key === 'contact_middle_initial')) ? key : null;
                }).filter(Boolean));
                if (created) areas.push({ title: 'MOA Document', fields: ['Generated and submitted for review'] });
                showGlobalSuccess(
                    created ? 'MOA Created' : 'Information Saved',
                    created ? 'Your MOA has been created and submitted for review.' : '',
                    {
                        areas: areas,
                        warnings: created ? [] : [data.message || 'The MOA document could not be generated automatically. Please try again in a moment.'],
                        sub: 'Refreshing the page...', button: false, keepOpen: true, autoCloseMs: created ? 2800 : 4500,
                        onDone: function() { window.location.replace('CompanyForm.php'); }
                    }
                );
            })
            .catch(function(err) {
                hideGlobalLoading();
                if (btn) btn.disabled = false;
                if (spinner) spinner.style.display = 'none';
                feedback.textContent = 'Network error — please try again.';
                feedback.classList.add('show', 'error');
            });
    }

    // ── NEW (this adjustment): "Preview MOA" for the MOA Initial Creation
    // panel — mirrors company_register.php's own openNewMoaPreview()
    // exactly (same field validation, same hidden-form-into-named-iframe
    // technique), just reading from this page's cfField_* inputs instead
    // of company_register.php's Step 1/Step 2 fields, and posting to this
    // file's own preview_new_moa endpoint (added near the top of this
    // file) instead.
    function openMoaCreatePreview() {
        var companyField   = document.getElementById('cfField_company');
        var addressField   = document.getElementById('cfField_company_address');
        var firstField     = document.getElementById('cfField_contact_first_name');
        var lastField      = document.getElementById('cfField_contact_last_name');
        var middleField    = document.getElementById('cfField_contact_middle_initial');
        var positionField  = document.getElementById('cfField_position');
        var profileField   = document.getElementById('cfField_company_profile');

        var companyName    = companyField  ? companyField.value.trim()  : '';
        var companyAddress = addressField  ? addressField.value.trim()  : '';
        var first          = firstField    ? firstField.value.trim()    : '';
        var last            = lastField    ? lastField.value.trim()     : '';
        var position        = positionField ? positionField.value.trim() : '';
        var companyProfile  = profileField  ? profileField.value.trim()  : '';

        if (!companyName || !companyAddress || !first || !last || !position || !companyProfile) {
            const feedback = document.getElementById('moaCreationFeedback');
            if (feedback) {
                feedback.textContent = 'Please fill in every field above before previewing your MOA.';
                feedback.classList.remove('success');
                feedback.classList.add('show', 'error');
            }
            return;
        }

        var fieldsToPost = {
            company: companyName,
            company_address: companyAddress,
            contact_first_name: first,
            contact_middle_initial: middleField ? middleField.value : '',
            contact_last_name: last,
            position: position,
            company_profile: companyProfile
        };

        var tempForm = document.createElement('form');
        tempForm.method = 'POST';
        tempForm.action = 'CompanyForm.php?preview_new_moa=1';
        tempForm.target = 'moaCreatePreviewTargetFrame';
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

        var modal = document.getElementById('moaCreatePreviewModal');
        if (modal) {
            modal.style.display = 'flex';
            document.body.style.overflow = 'hidden';
        }
    }

    function closeMoaCreatePreview() {
        var modal = document.getElementById('moaCreatePreviewModal');
        if (!modal) return;
        modal.style.display = 'none';
        document.body.style.overflow = '';
    }

    document.getElementById('moaCreatePreviewModal').addEventListener('click', function(e) {
        if (e.target === this) closeMoaCreatePreview();
    });

    // COMPLIANCE REQUIREMENT DOCUMENT PREVIEW MODAL
    // FIX (this update): now supports paging through MULTIPLE saved files
    // for one requirement (the "overlaying stacked card" case), instead of
    // only ever showing a single file. Triggered via .creq-preview-trigger
    // elements carrying data-req-key / data-req-files (a JSON array of
    // {id, isPdf}) / data-req-label, rather than inline onclick handlers,
    // so the file list doesn't need risky manual JS-string escaping.
    var creqPreviewKey   = null;
    var creqPreviewFiles = [];
    var creqPreviewIndex = 0;
    var creqPreviewLabel = '';

    // FIX (this update) — LOCAL (NOT-YET-SUBMITTED) FILE PREVIEW SUPPORT.
    // The modal above was originally only ever fed files.id metadata for
    // files already saved server-side (streamed back via
    // CompanyForm.php?stream_own_requirement=...&file_id=...). A file the
    // company has just picked in the file dialog — but not yet submitted —
    // has no server file_id at all, so it could never be shown this way.
    // creqPreviewIsLocal flags which mode the modal is currently in, and
    // creqPreviewLocalObjectUrls tracks the blob: URLs created for that
    // local preview so they can be revoked (avoiding a memory leak) once
    // the modal is closed or a different requirement's preview opens.
    var creqPreviewIsLocal = false;
    var creqPreviewLocalObjectUrls = [];

    function revokeCreqPreviewLocalObjectUrls() {
        creqPreviewLocalObjectUrls.forEach(function(u){ try { URL.revokeObjectURL(u); } catch (e) {} });
        creqPreviewLocalObjectUrls = [];
    }

    function openReqDocPreview(reqKey, filesMeta, label) {
        revokeCreqPreviewLocalObjectUrls();
        creqPreviewIsLocal = false;
        creqPreviewKey   = reqKey;
        creqPreviewFiles = filesMeta || [];
        creqPreviewIndex = 0;
        creqPreviewLabel = label || 'Document';
        if (!creqPreviewFiles.length) return;

        renderReqDocPreview();

        const modal = document.getElementById('reqDocPreviewModal');
        modal.style.display = 'flex';
        document.body.style.overflow = 'hidden';
    }

    // NEW (this update) — opens the SAME preview modal, in the same
    // paged/stacked way, for file(s) the company has just selected locally
    // and hasn't submitted yet (used by renderReqLocalPreview() below).
    // Each entry is rendered from an in-browser blob: URL built from the
    // actual File object instead of a server stream URL.
    function openReqDocPreviewLocal(reqKey, files, label) {
        revokeCreqPreviewLocalObjectUrls();
        creqPreviewIsLocal = true;
        creqPreviewKey   = reqKey;
        creqPreviewFiles = (files || []).map(function(f){ return { file: f, isPdf: reqFileIsPdf(f), name: f.name }; });
        creqPreviewIndex = 0;
        creqPreviewLabel = label || 'Document';
        if (!creqPreviewFiles.length) return;

        renderReqDocPreview();

        const modal = document.getElementById('reqDocPreviewModal');
        modal.style.display = 'flex';
        document.body.style.overflow = 'hidden';
    }

    function renderReqDocPreview() {
        const meta = creqPreviewFiles[creqPreviewIndex];
        if (!meta) return;

        const viewerWrap = document.getElementById('reqDocPreviewViewerWrap');
        const nameLabel  = document.getElementById('reqDocPreviewName');
        const iconEl     = document.getElementById('reqDocPreviewIcon');
        const counterEl  = document.getElementById('reqDocPreviewCounter');

        if (nameLabel) nameLabel.textContent = creqPreviewIsLocal && meta.name ? meta.name : creqPreviewLabel;
        viewerWrap.innerHTML = '';
        viewerWrap.classList.remove('image-mode');

        var src, isPdf;
        if (creqPreviewIsLocal) {
            isPdf = !!meta.isPdf;
            src = URL.createObjectURL(meta.file);
            creqPreviewLocalObjectUrls.push(src);
        } else {
            isPdf = !!meta.isPdf;
            src = 'CompanyForm.php?stream_own_requirement=' + encodeURIComponent(creqPreviewKey) + '&file_id=' + encodeURIComponent(meta.id);
        }

        if (isPdf) {
            if (iconEl) iconEl.className = 'fas fa-file-pdf moa-doc-modal-icon';
            const iframe = document.createElement('iframe');
            iframe.title = creqPreviewLabel;
            iframe.src = src;
            viewerWrap.appendChild(iframe);
        } else {
            if (iconEl) iconEl.className = 'fas fa-image moa-doc-modal-icon';
            viewerWrap.classList.add('image-mode');
            const img = document.createElement('img');
            img.className = 'moa-doc-modal-image';
            img.src = src;
            img.alt = creqPreviewLabel;
            viewerWrap.appendChild(img);
        }

        if (creqPreviewFiles.length > 1) {
            const prevBtn = document.createElement('button');
            prevBtn.type = 'button';
            prevBtn.className = 'req-preview-nav-btn req-preview-nav-prev';
            prevBtn.innerHTML = '<i class="fas fa-chevron-left"></i>';
            prevBtn.addEventListener('click', function(e){
                e.stopPropagation();
                creqPreviewIndex = (creqPreviewIndex - 1 + creqPreviewFiles.length) % creqPreviewFiles.length;
                renderReqDocPreview();
            });

            const nextBtn = document.createElement('button');
            nextBtn.type = 'button';
            nextBtn.className = 'req-preview-nav-btn req-preview-nav-next';
            nextBtn.innerHTML = '<i class="fas fa-chevron-right"></i>';
            nextBtn.addEventListener('click', function(e){
                e.stopPropagation();
                creqPreviewIndex = (creqPreviewIndex + 1) % creqPreviewFiles.length;
                renderReqDocPreview();
            });

            viewerWrap.appendChild(prevBtn);
            viewerWrap.appendChild(nextBtn);
        }

        if (counterEl) counterEl.textContent = creqPreviewFiles.length > 1
            ? (creqPreviewIndex + 1) + ' / ' + creqPreviewFiles.length
            : '';
    }

    function closeReqDocPreview() {
        const modal      = document.getElementById('reqDocPreviewModal');
        const viewerWrap = document.getElementById('reqDocPreviewViewerWrap');
        modal.style.display = 'none';
        viewerWrap.innerHTML = '';
        document.body.style.overflow = '';
        // FIX (this update): release any blob: URLs created for a local
        // (not-yet-submitted) file preview so they don't leak.
        if (creqPreviewIsLocal) {
            revokeCreqPreviewLocalObjectUrls();
            creqPreviewIsLocal = false;
        }
    }
    document.getElementById('reqDocPreviewModal').addEventListener('click', function(e){
        if (e.target === this) closeReqDocPreview();
    });

    // NEW: (re)binds the "click to open preview modal" behavior onto every
    // .creq-preview-trigger element found inside the given container. Used
    // both for the initial page load and to re-wire the trigger after a
    // requirement's preview slot is restored to its original server-
    // rendered markup (see restoreReqPreviewSlot() below), since replacing
    // innerHTML drops any previously-attached listeners on those nodes.
    function bindReqPreviewTriggers(container) {
        container.querySelectorAll('.creq-preview-trigger').forEach(function(el){
            el.addEventListener('click', function(){
                var filesMeta = [];
                try { filesMeta = JSON.parse(el.getAttribute('data-req-files') || '[]'); } catch (e) { filesMeta = []; }
                var reqKey = el.getAttribute('data-req-key');
                var label  = el.getAttribute('data-req-label') || 'Document';
                openReqDocPreview(reqKey, filesMeta, label);
            });
        });
    }

    // ADJUSTMENT (this revision) — BROKEN-PREVIEW FALLBACK FOR THE
    // MULTI-FILE STACK. The stack's layers use background-image, which
    // fails silently (blank white tile) when a file can no longer be
    // streamed back — e.g. after an admin denial removed the stored blob.
    // Each layer's URL is probed here and, if it can't load, the layer is
    // given .req-stack-layer-missing so it reads as an explicit
    // "unavailable" tile instead of a blank one. Purely visual: the stack
    // markup, its data attributes and its preview-modal click handler
    // below are all unchanged. Wrapped in a function so it can also be
    // re-run after a preview slot is restored to its original markup.
    function probeReqStackLayers(container) {
        container.querySelectorAll('.req-file-stack .req-stack-layer').forEach(function(layer){
            var bg = layer.style.backgroundImage || '';
            var m  = bg.match(/url\(['"]?([^'")]+)['"]?\)/);
            if (!m) return;
            var probe = new Image();
            probe.onerror = function(){
                layer.classList.add('req-stack-layer-missing');
                layer.style.backgroundImage = 'none';
            };
            probe.src = m[1];
        });
    }

    // Initial binding for every compliance requirement card already on the
    // page (both the preview trigger click handlers and the broken-image
    // fallback probe for multi-file stacks).
    probeReqStackLayers(document);
    bindReqPreviewTriggers(document);

    // ═══════════════════════════════════════════════════════════════════
    // LIVE LOCAL FILE PREVIEW + AUTO-HIDE DENIED STATUS ON RESELECT
    // (FIXED — this revision)
    // ═══════════════════════════════════════════════════════════════════
    // The instant a company picks (or replaces) file(s) for a compliance
    // requirement, this:
    //   1) Renders an actual in-browser preview of the just-selected
    //      file(s) — a real image thumbnail for images, or a document icon
    //      tile for PDFs/other files — directly in that requirement's
    //      preview slot, instead of leaving the old server-rendered
    //      preview (or a generic "click to upload" box) showing.
    //   2) ONLY when that requirement's ORIGINAL status was "Denied":
    //      hides the Denied status pill and remark ("Reason: ...") for the
    //      remainder of this visit, since the company is in the middle of
    //      replacing the rejected file, and shows a small "New file
    //      selected — ready to resubmit" note in their place. A
    //      requirement that had no file yet ("Not Submitted") or was
    //      "Pending"/"Verified" keeps its badge exactly as-is and never
    //      shows this note.
    //   3) Keeps the upload/replace box's text label visible at all times
    //      (it used to be hidden the moment file(s) were chosen, leaving
    //      only the icon showing) and updates it to reflect that file(s)
    //      are staged for upload.
    // Clearing the selection (0 files) restores everything — preview,
    // status badge, remark, and drop-zone label — back to exactly what the
    // server rendered on page load. Nothing here touches the PHP
    // upload/validate/save logic; it only affects what's shown in the
    // browser before the form is submitted.

    // Keeps a pristine copy of each requirement's original preview slot
    // markup (captured once, before any selection happens) so it can be
    // restored exactly if the company clears their file selection.
    var reqPreviewSlotOriginals = {};
    document.querySelectorAll('.req-preview-slot').forEach(function(slot){
        var key = slot.getAttribute('data-req-key');
        if (key) reqPreviewSlotOriginals[key] = slot.innerHTML;
    });

    // Tracks object URLs created for live local previews so they can be
    // revoked when replaced, avoiding a slow memory leak if the company
    // changes their selection several times before submitting.
    var reqLocalPreviewUrls = {};
    function revokeReqLocalPreviewUrls(reqKey) {
        var urls = reqLocalPreviewUrls[reqKey];
        if (!urls) return;
        urls.forEach(function(u){ try { URL.revokeObjectURL(u); } catch (e) {} });
        reqLocalPreviewUrls[reqKey] = [];
    }

    function reqFileIsImage(file) {
        return !!(file && file.type && file.type.indexOf('image/') === 0);
    }
    function reqFileIsPdf(file) {
        return !!(file && (file.type === 'application/pdf' || /\.pdf$/i.test(file.name || '')));
    }

    // Builds and injects a live preview of the freshly-selected file(s)
    // into the requirement's preview slot — a single enlarged thumbnail
    // for one file, or the same overlaying "stack" visual used for saved
    // multi-file requirements when more than one file is selected at once.
    function renderReqLocalPreview(reqKey, files) {
        var slot = document.getElementById('reqPreviewSlot_' + reqKey);
        if (!slot) return;

        revokeReqLocalPreviewUrls(reqKey);
        reqLocalPreviewUrls[reqKey] = [];
        slot.innerHTML = '';

        if (!files.length) return;

        // FIX (this update) — "preview won't open right after selecting
        // multiple files, but works fine after submit/reload":
        // ──────────────────────────────────────────────────────────────
        // The live local preview elements built below never carried the
        // .creq-preview-trigger class/data attributes (those only exist on
        // the server-rendered markup, and that markup's data-req-files
        // JSON only ever contains {id, isPdf} for files already saved to
        // the database — a just-picked local file has no id yet). So a
        // click here previously did nothing at all. Each preview element
        // now gets its own click handler that opens the SAME preview
        // modal via openReqDocPreviewLocal(), which reads straight from
        // the in-memory File objects (blob: URLs) instead of the
        // stream_own_requirement endpoint. This applies whether one file
        // or several were selected — a single file's tile is just as
        // clickable as the multi-file stack.
        var previewLabel = (function(){
            var triggerEl = document.querySelector('.creq-preview-trigger[data-req-key="' + reqKey + '"]');
            return (triggerEl && triggerEl.getAttribute('data-req-label')) || reqKey;
        })();

        if (files.length === 1) {
            var file = files[0];
            if (reqFileIsImage(file)) {
                var url = URL.createObjectURL(file);
                reqLocalPreviewUrls[reqKey].push(url);
                var img = document.createElement('img');
                img.className = 'req-thumb-img';
                img.style.cursor = 'pointer';
                img.src = url;
                img.alt = file.name;
                img.title = file.name + ' (selected — not yet submitted, click to preview)';
                img.addEventListener('click', function(){ openReqDocPreviewLocal(reqKey, files, previewLabel); });
                slot.appendChild(img);
            } else {
                var wrap = document.createElement('div');
                wrap.className = 'req-thumb-wrap';
                wrap.title = file.name + ' (selected — not yet submitted, click to preview)';
                var icon = document.createElement('i');
                icon.className = reqFileIsPdf(file) ? 'fas fa-file-pdf' : 'fas fa-file-lines';
                wrap.appendChild(icon);
                wrap.addEventListener('click', function(){ openReqDocPreviewLocal(reqKey, files, previewLabel); });
                slot.appendChild(wrap);
            }
        } else {
            var stackWrap = document.createElement('div');
            stackWrap.className = 'req-file-stack-wrap';
            stackWrap.title = files.length + ' files selected — not yet submitted, click to preview';
            stackWrap.addEventListener('click', function(){ openReqDocPreviewLocal(reqKey, files, previewLabel); });

            var stack = document.createElement('div');
            stack.className = 'req-file-stack';

            var layerCount = Math.min(3, files.length);
            for (var li = layerCount - 1; li >= 0; li--) {
                var f = files[li];
                var layer = document.createElement('div');
                layer.className = 'req-stack-layer layer-' + (li + 1);
                if (reqFileIsImage(f)) {
                    var lurl = URL.createObjectURL(f);
                    reqLocalPreviewUrls[reqKey].push(lurl);
                    layer.style.backgroundImage = "url('" + lurl + "')";
                } else {
                    layer.classList.add('req-stack-layer-pdf');
                    var licon = document.createElement('i');
                    licon.className = 'fas fa-file-pdf';
                    layer.appendChild(licon);
                }
                stack.appendChild(layer);
            }

            var countBadge = document.createElement('span');
            countBadge.className = 'req-stack-count-badge';
            countBadge.textContent = String(files.length);
            stack.appendChild(countBadge);

            stackWrap.appendChild(stack);

            var stackLabel = document.createElement('div');
            stackLabel.className = 'req-file-stack-label';
            stackLabel.textContent = files.length + ' files';
            stackWrap.appendChild(stackLabel);

            slot.appendChild(stackWrap);
        }
    }

    // Restores a requirement's preview slot to exactly what the server
    // rendered on page load (used when the company clears their
    // selection), and re-binds the preview-modal click handler / broken-
    // image probe onto the restored markup since a fresh innerHTML swap
    // drops any previously-attached listeners.
    function restoreReqPreviewSlot(reqKey) {
        var slot = document.getElementById('reqPreviewSlot_' + reqKey);
        if (!slot) return;
        revokeReqLocalPreviewUrls(reqKey);
        if (Object.prototype.hasOwnProperty.call(reqPreviewSlotOriginals, reqKey)) {
            slot.innerHTML = reqPreviewSlotOriginals[reqKey];
            bindReqPreviewTriggers(slot);
            probeReqStackLayers(slot);
        }
    }

    // ═══════════════════════════════════════════════════════════════════
    // NEW (this revision) — ON-LOAD COMPLIANCE STATUS DETECTION
    // ═══════════════════════════════════════════════════════════════════
    // Every requirement card now carries its real, server-computed status
    // in data-req-status ("verified" / "pending" / "denied" /
    // "not-submitted") — see the card markup above. This pass runs
    // immediately when the page loads, reads that attribute off each card,
    // and activates the matching UI straight away, with no click or extra
    // request needed:
    //
    //   denied        → drop-zone wording becomes an explicit re-upload
    //                   call to action, and the "Action required" flag is
    //                   shown (the flag itself is CSS-driven off the same
    //                   attribute, so it is already visible before this
    //                   script even runs — this pass only sets the
    //                   wording and the accessible title).
    //   not-submitted → drop-zone wording confirms nothing is on file yet.
    //   pending       → drop-zone wording notes it is under review and can
    //                   still be replaced.
    //   verified      → nothing to do; that card renders the locked
    //                   "Verified" badge instead of an upload area.
    //
    // The card colours/accents for each status are handled purely by the
    // status-aware CSS further up, so they apply the instant the HTML is
    // parsed. This function is idempotent and is also re-run after a
    // selection is cleared, so a card always falls back to the correct
    // wording for its true stored status.
    //
    // Nothing here writes to the database, changes any requirement key,
    // input name, or the upload/validate/save path — it only decides what
    // the already-rendered card says.
    function applyReqStatusUI(card) {
        if (!card) return;
        var status  = card.getAttribute('data-req-status') || 'not-submitted';
        var hasFile = card.getAttribute('data-req-has-file') === '1';
        var count   = parseInt(card.getAttribute('data-req-file-count') || '0', 10) || 0;
        var textEl  = card.querySelector('.req-file-text');
        var dropEl  = card.querySelector('.req-file-drop');

        // A verified requirement renders a locked badge instead of an
        // upload area, so there is simply nothing to word here.
        if (!textEl || !dropEl) return;

        var label, title;
        if (status === 'denied') {
            label = 'Re-upload';
            title = 'This document was rejected. Please upload a replacement.';
        } else if (status === 'pending') {
            label = hasFile
                ? (count > 1 ? 'Under review — click to replace file(s)' : 'Under review — click to replace')
                : 'Click to upload';
            title = 'This document is awaiting review. You can still replace it.';
        } else if (hasFile) {
            label = 'Click to replace file(s)';
            title = '';
        } else {
            label = 'Click to upload';
            title = 'No document on file yet for this requirement.';
        }

        textEl.textContent = label;
        textEl.style.display = '';
        if (title) dropEl.setAttribute('title', title); else dropEl.removeAttribute('title');
    }

    // Run the detection pass across every compliance requirement card on
    // the page as soon as it loads.
    function applyAllReqStatusUI() {
        document.querySelectorAll('.compliance-req-item[data-req-status]').forEach(function(card){
            // Skip a card whose selection the company has already staged in
            // this visit — its wording is owned by the change handler below.
            if (card.classList.contains('req-staged')) return;
            applyReqStatusUI(card);
        });
    }
    applyAllReqStatusUI();

    // COMPLIANCE REQUIREMENT FILE INPUT — multi-file labeling + expected-count tracking
    // FIX (this update): the compliance file inputs now accept multiple
    // files per requirement (matching company_register.php's Step 3), so
    // this shows a proper "N file(s) selected" summary instead of only
    // ever naming a single file, and keeps each requirement's hidden
    // "_expected_count" field in sync so the server can detect a request
    // that silently loses files in transit (see
    // cfValidateRequirementUploadsMulti() in CompanyForm.php).
    //
    // FIX (this revision) — see the two numbered adjustments in the
    // docblock above the Compliance Requirements section in the HTML:
    //   1) the "ready to resubmit" staged note + Denied badge/remark
    //      auto-hide now only fire when this requirement's ORIGINAL
    //      status was "Denied" (checked via wasDenied below, read off the
    //      status badge's CSS class, which PHP sets once at render time
    //      and this script never alters).
    //   2) the drop-zone's text label is no longer hidden once file(s)
    //      are chosen — it stays visible and is updated instead.
    // LIVE UPDATES: the per-input handler below is now a named function so a requirement card that is
    // refreshed in place (see the "LIVE UPDATES" script at the bottom) can re-bind its new file input.
    // The handler body itself is unchanged; it still runs for every input on page load (see the
    // forEach call right after the function).
    // ADJUSTMENT: PDF upload limit (same rule and popup wording as company_register.php) —
    // a requirement accepts ONE PDF at most, and a PDF cannot be mixed with pictures.
    // Shown in the page's existing #complianceErrorModal popup; a rejected selection is cleared.
    function cfShowUploadPopup(title, message) {
        var modal = document.getElementById('complianceErrorModal');
        if (!modal) { window.alert(message); return; }
        var ttl = modal.querySelector('.notif-modal-title');
        if (ttl) ttl.textContent = title;
        var msgEl = document.getElementById('complianceErrorMsg');
        if (msgEl) msgEl.textContent = message;
        modal.style.display = 'flex';
    }
    function cfShowPdfLimitPopup(title, message, label) {
        var modal = document.getElementById('cfPdfLimitModal');
        if (!modal) { window.alert(message); return; }
        document.getElementById('cfPdfLimitTitle').textContent = title;
        document.getElementById('cfPdfLimitMsg').textContent = message;
        var okBtn = document.getElementById('closeCfPdfLimit');
        if (okBtn) okBtn.textContent = 'OK, Fix It'; // default label — the file-size popup below overrides it
        var list = document.getElementById('cfPdfLimitList');
        list.innerHTML = '';
        if (label) { var li = document.createElement('li'); li.textContent = '"' + label + '"'; list.appendChild(li); }
        list.style.display = label ? 'block' : 'none';
        modal.style.display = 'flex';
    }

    // ADJUSTMENT: FILE-SIZE POPUP — same wording and design as AccomForm.php's "Almost There!" popup, shown in the
    // page's neutral .cf-pdf-* popup. The limit comes from PHP ($reqMaxFileSizeMB) so the message always matches the
    // server-side check in cfValidateRequirementUploadsMulti().
    var CF_MAX_FILE_MB = <?= max(1, (int) $reqMaxFileSizeMB) ?>;
    function cfFormatMB(bytes) {
        var n = Number(bytes);
        return (isFinite(n) && n >= 0) ? ((n / (1024 * 1024)).toFixed(2) + ' MB') : 'over the limit';
    }
    // oversized: [{name, size}] — size may be null/undefined when only the server reported the problem
    function cfShowFileSizePopup(label, oversized) {
        var modal = document.getElementById('cfPdfLimitModal');
        var limit = CF_MAX_FILE_MB;
        var many  = oversized.length > 1;
        var msg   = (many ? 'Some files for "' : 'Your file for "') + label + '" ' + (many ? 'are' : 'is') + ' a little larger than the ' + limit + ' MB upload limit. '
                  + 'A quick compress or a lower-resolution photo will do the trick — try again with a smaller file and you\'ll be all set!';
        if (!modal) { window.alert(msg); return; }
        try {
            document.getElementById('cfPdfLimitTitle').textContent = 'Almost There!';
            document.getElementById('cfPdfLimitMsg').textContent = msg;
            var okBtn = document.getElementById('closeCfPdfLimit');
            if (okBtn) okBtn.textContent = 'OK, I\'ll Try Again';
            var list = document.getElementById('cfPdfLimitList');
            list.innerHTML = '';
            oversized.slice(0, 5).forEach(function (f) {
                var li = document.createElement('li');
                var b = document.createElement('strong');
                b.textContent = f.name || 'File';
                li.appendChild(b);
                li.appendChild(document.createTextNode(' — ' + (f.size != null ? cfFormatMB(f.size) + ' ' : '') + '(limit: ' + limit + ' MB)'));
                list.appendChild(li);
            });
            if (oversized.length > 5) {
                var more = document.createElement('li');
                more.textContent = '…and ' + (oversized.length - 5) + ' more';
                list.appendChild(more);
            }
            var tip = document.createElement('li');
            tip.className = 'cf-pdf-tip';
            tip.textContent = 'Tip: take the picture in a lower quality setting, or use any free image compressor.';
            list.appendChild(tip);
            list.style.display = 'block';
            modal.style.display = 'flex';
        } catch (e) {
            window.alert('Each file must be ' + limit + ' MB or smaller.'); // last-resort fallback — an oversized file is never silently accepted
        }
    }
    function cfSelectionExceedsSize(input) {
        var files = input.files ? Array.prototype.slice.call(input.files) : [];
        var maxBytes = CF_MAX_FILE_MB * 1024 * 1024;
        var big = files.filter(function (f) { return f && f.size > maxBytes; });
        if (!big.length) return false;
        cfShowFileSizePopup(input.getAttribute('data-label') || 'this document', big);
        return true;
    }
    (function () {
        var m = document.getElementById('cfPdfLimitModal');
        var b = document.getElementById('closeCfPdfLimit');
        if (b) b.addEventListener('click', function () { m.style.display = 'none'; });
        if (m) m.addEventListener('click', function (e) { if (e.target === m) m.style.display = 'none'; });
        document.addEventListener('keydown', function (e) { if (e.key === 'Escape' && m) m.style.display = 'none'; });
    })();
    function cfSelectionBreaksPdfLimit(input) {
        var files = input.files ? Array.prototype.slice.call(input.files) : [];
        var pdfs = files.filter(function(f){ return f.type === 'application/pdf' || /\.pdf$/i.test(f.name || ''); }).length;
        if (pdfs > 1) {
            cfShowPdfLimitPopup('Only One PDF Allowed', 'Only one PDF file can be selected for this document. Please choose a single PDF file, or switch to picture files if you need to upload multiple files.', input.getAttribute('data-label'));
            return true;
        }
        if (pdfs === 1 && files.length > 1) {
            cfShowPdfLimitPopup('Mixed File Formats Not Allowed', 'Please select files of the same format only — either all pictures or a single PDF, not a mix of both, for this document.', input.getAttribute('data-label'));
            return true;
        }
        return false;
    }

    function bindComplianceFileInput(input){
        input.addEventListener('change', function(){
            // ADJUSTMENT: the file-size check runs right after the PDF-limit check (only one popup is ever shown at a time)
            if (cfSelectionBreaksPdfLimit(input) || cfSelectionExceedsSize(input)) { input.value = ''; } // rejected selection is cleared; the handler below then restores the card
            const wrap      = input.closest('.compliance-req-item');
            const textEl    = wrap ? wrap.querySelector('.req-file-text') : null;
            const dropEl    = wrap ? wrap.querySelector('.req-file-drop') : null;
            const reqKey    = input.getAttribute('data-req-key') || '';
            const countField = document.getElementById('reqCount_c_' + reqKey);
            const statusBadge = document.getElementById('reqStatusBadge_' + reqKey);
            const stagedNote   = document.getElementById('reqStagedNote_' + reqKey);
            const remarkBox    = document.getElementById('reqRemarkBox_' + reqKey); // rejected-card "Remark:" box

            // Only a requirement whose ORIGINAL server-rendered status was
            // "Denied" should ever have its badge/remark auto-hidden or
            // show the "ready to resubmit" note.
            //
            // UPDATED (this revision): this now reads the card's
            // data-req-status attribute — the single status value written
            // once by PHP and used by the status-aware CSS and the on-load
            // detection pass above — instead of inspecting the badge's
            // class. It is the same underlying value, but reading it from
            // one authoritative place keeps every status-driven behaviour
            // on this page in sync. The badge class check is kept as a
            // fallback so nothing breaks if the attribute is ever absent.
            const cardStatus = wrap ? (wrap.getAttribute('data-req-status') || '') : '';
            const wasDenied = cardStatus
                ? (cardStatus === 'denied')
                : !!(statusBadge && statusBadge.classList.contains('denied'));

            const fileCount = (input.files && input.files.length) ? input.files.length : 0;
            if (countField) countField.value = fileCount;

            if (fileCount > 0) {
                // FIX: keep the label visible and update its text to show
                // that file(s) are staged, instead of hiding it — the
                // upload/replace box should never be left with just an
                // icon and no text.
                if (textEl) {
                    textEl.textContent = fileCount > 1
                        ? (fileCount + ' file(s) selected — click to replace')
                        : 'Click to replace file(s)';
                    textEl.style.display = '';
                }
                if (dropEl) dropEl.classList.add('has-file');

                // NEW (this revision): flag the whole card as "staged" so the
                // status-aware CSS switches it from its stored-status accent
                // (e.g. the denied red tint / "Action required" flag) to the
                // neutral "ready to submit" look for the rest of this visit.
                // The card's data-req-status attribute is deliberately left
                // untouched, so the true stored status is never lost and is
                // restored exactly if the selection is cleared below.
                if (wrap) wrap.classList.add('req-staged');

                // Show a live preview of the file(s) just picked, instead of
                // the old server-rendered preview (or "no file yet" tile).
                renderReqLocalPreview(reqKey, Array.from(input.files));

                // FIX: only auto-hide the Denied status + remark, and only
                // show the "ready to resubmit" note, when this requirement
                // was ACTUALLY Denied originally. A requirement with no
                // file yet, or Pending/Verified, keeps its badge untouched
                // and never shows the staged note.
                if (wasDenied) {
                    if (statusBadge) statusBadge.style.display = 'none';
                    if (remarkBox) remarkBox.style.display = 'none';
                    if (stagedNote) stagedNote.style.display = 'inline-flex';
                }
            } else {
                if (dropEl) dropEl.classList.remove('has-file');

                // NEW (this revision): drop the "staged" flag and re-run the
                // status detection pass for THIS card only, so its wording
                // and accent snap straight back to whatever its true stored
                // status is (denied / pending / not-submitted) rather than a
                // hardcoded "Click to upload".
                if (wrap) {
                    wrap.classList.remove('req-staged');
                    applyReqStatusUI(wrap);
                } else if (textEl) {
                    textEl.textContent = 'Click to upload';
                    textEl.style.display = '';
                }

                // Selection cleared — restore the original preview and the
                // original combined status box exactly as the server
                // rendered it.
                restoreReqPreviewSlot(reqKey);
                if (statusBadge) statusBadge.style.display = '';
                if (remarkBox) remarkBox.style.display = '';
                if (stagedNote) stagedNote.style.display = 'none';
            }
        });
    }
    document.querySelectorAll('.compliance-file-input').forEach(bindComplianceFileInput);

    /* ══════════════════════════════════════════════════════════
       ADJUSTMENT (action loading page) — SAVE CHANGES / SUBMIT REQUIREMENT DOCUMENTS
       The page's one big form is sent in the background (same URL, same fields) so the
       full-page loading screen can show upload progress, then name the AREAS that were
       updated and the requirements that were submitted, then refresh the page. The server
       answers JSON only to this background request (see submit_compliance_docs) — a normal
       browser post, if this script cannot run, keeps working exactly as before.
       Problems close the loading screen and use the page's own popups; the selected files
       stay selected so the company can simply try again.
       ══════════════════════════════════════════════════════════ */
    function cfReportUploadErrors(errs) {
        errs = Array.isArray(errs) ? errs : [];
        try {
            const sizeRe = /^"(.*)" \((.*)\) exceeds the \d+MB limit\.$/;
            if (errs.length && typeof cfShowFileSizePopup === 'function' && errs.every(e => sizeRe.test(String(e)))) {
                const parsed = errs.map(e => sizeRe.exec(String(e)));
                const sameLabel = parsed.every(m => m[1] === parsed[0][1]);
                cfShowFileSizePopup(sameLabel ? parsed[0][1] : 'some requirements',
                    parsed.map(m => ({ name: sameLabel ? m[2] : (m[1] + ' — ' + m[2]), size: null })));
                return;
            }
        } catch (ex) { /* fall through to the general popup */ }
        const errTitle = document.querySelector('#complianceErrorModal .notif-modal-title');
        if (errTitle) errTitle.textContent = 'Upload Problem';
        const msgEl = document.getElementById('complianceErrorMsg');
        if (msgEl) {
            msgEl.textContent = '';
            const lines = errs.length ? errs : ['Some documents could not be uploaded. Please check the file type and size and try again.'];
            lines.forEach(function(line, i) {
                if (i) msgEl.appendChild(document.createElement('br'));
                msgEl.appendChild(document.createTextNode('\u2022 ' + line));
            });
        }
        const modal = document.getElementById('complianceErrorModal');
        if (modal) modal.style.display = 'flex'; else window.alert(errs.join('\n'));
    }

    (function() {
        const mainForm = document.getElementById('cfMainForm');
        if (!mainForm || !window.FormData || !window.XMLHttpRequest) return;   // old browser: the normal submit still works
        let busy = false;

        mainForm.addEventListener('submit', function(e) {
            if (e.defaultPrevented) return;
            const sb = e.submitter;
            if (!sb || sb.name !== 'submit_compliance_docs') return;   // only the page's own Save / Submit buttons
            e.preventDefault();
            if (busy) return;

            const isInfoSave = !!sb.closest('.cf-info-save-row');
            const fd = new FormData(mainForm);
            fd.append(sb.name, sb.value);   // a script-built FormData leaves the clicked button out; the server looks for it

            let fileCount = 0;
            mainForm.querySelectorAll('input.compliance-file-input').forEach(function(i) { if (i.files && i.files.length) fileCount++; });

            function fail(errs, areas) {
                busy = false;
                sb.disabled = false;
                hideGlobalLoading();
                cfReportUploadErrors(errs);
                if (areas && areas.length) {
                    // the information part was saved before the upload problem — say so, never silently
                    const msgEl = document.getElementById('complianceErrorMsg');
                    if (msgEl) {
                        msgEl.appendChild(document.createElement('br'));
                        msgEl.appendChild(document.createTextNode('Your company information changes were saved (' +
                            areas.map(function(a) { return a.title; }).join(', ') + ').'));
                    }
                }
            }

            busy = true;
            sb.disabled = true;
            showGlobalLoading(isInfoSave && !fileCount ? 'Saving changes' : (fileCount ? 'Submitting requirements' : 'Saving changes'));

            const xhr = new XMLHttpRequest();
            xhr.open('POST', mainForm.getAttribute('action') || window.location.pathname);
            xhr.setRequestHeader('X-Requested-With', 'XMLHttpRequest');
            xhr.timeout = 10 * 60 * 1000;
            xhr.upload.onprogress = function(ev) {
                if (!ev.lengthComputable || !fileCount) return;
                const pct = Math.round(ev.loaded / ev.total * 100);
                setGlobalLoadingLabel(pct < 100 ? 'Uploading requirements ' + pct + '%' : 'Saving requirements');
            };
            xhr.onload = function() {
                const data = parseJsonSafe(xhr.responseText);
                if (xhr.status === 200 && data && data.success) {
                    const areas = Array.isArray(data.areas) ? data.areas.slice() : [];
                    const uploaded = Array.isArray(data.uploaded) ? data.uploaded : [];
                    if (uploaded.length) {
                        areas.push({ title: 'Compliance Requirements', fields: uploaded.map(function(u) {
                            return u.count > 1 ? u.label + ' (' + u.count + ' files)' : u.label;
                        }) });
                    }
                    if (!areas.length) {
                        // nothing was changed and nothing selected — same quiet outcome as before, now explained
                        showGlobalSuccess('No Changes', 'Nothing was changed — your information and documents are already up to date.', { autoCloseMs: 3500 });
                        busy = false; sb.disabled = false;
                        return;
                    }
                    const title = (uploaded.length && data.areas && data.areas.length) ? 'Changes Saved'
                                : (uploaded.length ? 'Requirements Submitted' : 'Information Updated');
                    const msg = uploaded.length
                        ? (uploaded.length + (uploaded.length === 1 ? ' requirement was' : ' requirements were') + ' sent for review' + (data.areas && data.areas.length ? ' and your information was updated:' : ':'))
                        : 'The following areas were updated:';
                    showGlobalSuccess(title, msg, {
                        areas: areas, sub: 'Refreshing the page...', button: false, keepOpen: true, autoCloseMs: 2800,
                        onDone: function() { window.location.replace('CompanyForm.php'); }
                    });
                    return;
                }
                if (data && Array.isArray(data.errors)) { fail(data.errors, data.areas); return; }
                if (xhr.status === 401 || /login\.php/i.test(xhr.responseURL || '')) { fail(['Your session has expired. Please log in again, then submit.']); return; }
                fail(['The server could not save your changes. Please try again in a moment.']);
            };
            xhr.onerror   = function() { fail(['Could not reach the server. Please check your connection and try again.']); };
            xhr.ontimeout = function() { fail(['Submitting took too long. Please check your connection and try again, or choose smaller files.']); };
            xhr.send(fd);
        });
    })();

    // A page restored from the browser's back/forward cache keeps its old state — never show a left-over loading screen.
    window.addEventListener('pageshow', function(e) {
        if (!e.persisted) return;
        globalLoadingActiveCount = 0;
        if (globalLoadingOverlay) { globalLoadingOverlay.classList.add('hidden'); globalLoadingOverlay.classList.remove('success-state'); }
        mainFormReenable();
    });
    function mainFormReenable() {
        document.querySelectorAll('#cfMainForm button[name="submit_compliance_docs"]').forEach(function(b) { b.disabled = false; });
    }

    // PROFILE FORM SUBMISSION
    const profileForm = document.getElementById("profileForm");
    if(profileForm){
        profileForm.addEventListener("submit", function(e){
            e.preventDefault();
            const input = document.getElementById("google_map_link").value.trim();
            let src = "";

            if(input.startsWith("<iframe")){
                const match = input.match(/src="([^"]+)"/);
                if(match && match[1]) src = match[1];
                else { alert("Invalid iframe!"); return; }
            } else if(input.startsWith("https://www.google.com/maps/embed?pb=")) src = input;
            else { alert("Please paste a valid Google Maps embed link or iframe."); return; }

            if(!src.startsWith("https://www.google.com/maps/embed?pb=")) {
                alert("Invalid Google Maps embed link!"); return;
            }
            document.getElementById("google_map_link").value = src;

            // ADJUSTMENT (action loading page): loading screen while the profile is saved, then the areas that were saved
            const profileFields = [['email', 'Contact Email'], ['telephone', 'Telephone'], ['facebook_link', 'Facebook Page'], ['google_map_link', 'Google Maps Location']];
            const savedFields = profileFields.filter(function(f) { return this.elements[f[0]] && String(this.elements[f[0]].value || '').trim() !== ''; }, this).map(function(f) { return f[1]; });
            showGlobalLoading('Saving profile');

            fetch("CompanyForm.php", { method:"POST", body:new FormData(this) })
                .then(res=>res.text())
                .then(text=>{
                    const data = parseJsonSafe(text) || {};
                    if(data.status==="success"){
                        showGlobalSuccess('Profile Saved', 'Your company contact profile was saved:', {
                            areas: [{ title: 'Company Contact Profile', fields: savedFields }],
                            autoCloseMs: 2600,
                            onDone: function() {
                                document.getElementById('profileModal').style.display = 'none';
                                document.getElementById('profileSavedModal').style.display = 'flex';
                                // Auto-redirect after 3s, or on button click
                                const goBtn = document.getElementById('profileSavedGoBtn');
                                let redirectTimer = setTimeout(() => { window.location = 'Profile.php'; }, 3000);
                                goBtn.addEventListener('click', () => {
                                    clearTimeout(redirectTimer);
                                    window.location = 'Profile.php';
                                });
                            }
                        });
                    } else {
                        hideGlobalLoading();
                        alert("Failed to save profile. Please try again.");
                    }
                })
                .catch(function() {
                    hideGlobalLoading();
                    alert("Could not reach the server while saving your profile. Please check your connection and try again.");
                });
        });
    }

    // SHOW PROFILE MODAL PERSISTENTLY UNTIL SUBMIT
    function showProfileModal() {
        const profileModal = document.getElementById('profileModal');
        if(!profileModal) return;
        profileModal.style.display = 'flex';
        // Use a named handler so we don't stack duplicates on repeated calls
        if (!profileModal._outsideClickBound) {
            profileModal._outsideClickBound = true;
            profileModal.addEventListener('click', function(e){
                if (e.target === this) {
                    document.getElementById('warnModal').style.display = 'flex';
                }
            });
        }
    }

    // Close warning modal — return focus to profile modal
    document.addEventListener('DOMContentLoaded', () => {
        const closeWarn = document.getElementById('closeWarnModal');
        if (closeWarn) {
            closeWarn.addEventListener('click', () => {
                document.getElementById('warnModal').style.display = 'none';
            });
        }
        const closeComplianceError = document.getElementById('closeComplianceErrorModal');
        if (closeComplianceError) {
            closeComplianceError.addEventListener('click', () => {
                document.getElementById('complianceErrorModal').style.display = 'none';
            });
        }
    });

    // Help panel toggle
    function toggleHelp(id) {
        const panel = document.getElementById(id);
        if (panel) panel.classList.toggle('open');
    }

    // ── REDIRECT CONFIRMATION MODAL LOGIC ──────────────────────────────────
    let _redirectUrl = '';

    function showRedirectModal(url, icon, title, msg) {
        _redirectUrl = url;
        const redirectIconEl = document.getElementById('redirectModalIcon'); // emoji icon removed from this popup — kept null-safe
        if (redirectIconEl) redirectIconEl.textContent = icon;
        document.getElementById('redirectModalTitle').textContent = title;
        document.getElementById('redirectModalMsg').textContent   = msg;
        document.getElementById('redirectModal').style.display   = 'flex';
    }

    document.addEventListener('DOMContentLoaded', () => {
        document.getElementById('redirectModalGo').addEventListener('click', () => {
            document.getElementById('redirectModal').style.display = 'none';
            window.open(_redirectUrl, '_blank');
            _redirectUrl = '';
        });
        document.getElementById('redirectModalCancel').addEventListener('click', () => {
            document.getElementById('redirectModal').style.display = 'none';
            _redirectUrl = '';
        });
    });

    function goToFacebook() {
        showRedirectModal(
            'https://www.facebook.com',
            '',
            'Go to Facebook?',
            'You will be redirected to Facebook in a new tab. Your form data will be preserved when you return here.'
        );
    }
    function goToGoogleMaps() {
        showRedirectModal(
            'https://maps.google.com',
            '',
            'Go to Google Maps?',
            'You will be redirected to Google Maps in a new tab. Your form data will be preserved when you return here.'
        );
    }

    // SHOW SUBMISSION SUCCESS MODAL on ?msg=submitted
    <?php if (isset($_GET['msg']) && $_GET['msg'] === 'submitted'): ?>
    document.addEventListener('DOMContentLoaded', () => {
        const subModal = document.getElementById('submittedModal');
        subModal.style.display = 'flex';
        // Clean URL so refresh doesn't re-trigger
        if (window.history.replaceState) window.history.replaceState({}, document.title, window.location.pathname);
    });
    document.getElementById('closeSubmittedModal').addEventListener('click', function(){
        document.getElementById('submittedModal').style.display = 'none';
    });
    <?php endif; ?>

    // SHOW COMPLIANCE UPLOAD ERROR MODAL on ?msg=upload_error (NEW)
    <?php if (isset($_GET['msg']) && $_GET['msg'] === 'upload_error'): ?>
    document.addEventListener('DOMContentLoaded', () => {
        const errs = <?= json_encode(array_values($compliance_upload_errors)) ?>;
        // ADJUSTMENT: when EVERY reported problem is a file-size one (the server-side backstop for the client check),
        // show the same friendly size popup as the live check. Anything else keeps the original "Upload Problem" popup.
        try {
            const sizeRe = /^"(.*)" \((.*)\) exceeds the \d+MB limit\.$/;
            if (errs.length && typeof cfShowFileSizePopup === 'function' && errs.every(e => sizeRe.test(String(e)))) {
                const parsed = errs.map(e => sizeRe.exec(String(e)));
                const sameLabel = parsed.every(m => m[1] === parsed[0][1]);
                cfShowFileSizePopup(sameLabel ? parsed[0][1] : 'some requirements',
                    parsed.map(m => ({ name: sameLabel ? m[2] : (m[1] + ' — ' + m[2]), size: null })));
                if (window.history.replaceState) window.history.replaceState({}, document.title, window.location.pathname);
                return;
            }
        } catch (ex) { /* fall through to the original popup below */ }
        const errTitle = document.querySelector('#complianceErrorModal .notif-modal-title');
        if (errTitle) errTitle.textContent = 'Upload Problem';
        const msgEl = document.getElementById('complianceErrorMsg');
        if (msgEl) {
            msgEl.innerHTML = errs.length
                ? errs.map(e => '&bull; ' + e).join('<br>')
                : 'Some documents could not be uploaded. Please check the file type and size and try again.';
        }
        document.getElementById('complianceErrorModal').style.display = 'flex';
        if (window.history.replaceState) window.history.replaceState({}, document.title, window.location.pathname);
    });
    <?php endif; ?>

    // SHOW VERIFIED MODAL IF ALL VERIFIED
    <?php if ($all_verified): ?>
    document.addEventListener('DOMContentLoaded', () => {
        // Don't show verified modal if submission modal is already showing
        <?php if (!isset($_GET['msg']) || $_GET['msg'] !== 'submitted'): ?>
        const verifiedModal = document.getElementById('verifiedModal');
        verifiedModal.style.display = 'flex';

        const hideVerified = () => {
            verifiedModal.style.display = 'none';
            <?php if ($show_profile_popup): ?>
                showProfileModal();
            <?php endif; ?>
        };

        setTimeout(hideVerified, 5000);
        document.getElementById('closeVerifiedModal').addEventListener('click', hideVerified);
        <?php endif; ?>
    });
    <?php endif; ?>
</script>

<script>
/* ═══════════════════════════════════════════════════════════════════════
   NEW (this adjustment) — LIVE UPDATES (no manual reload)
   ───────────────────────────────────────────────────────────────────────
   Every few seconds this asks the server for a tiny fingerprint of the
   company's MOA + requirement data (GET CompanyForm.php?cf_live_poll=1).
   When it changes, the page is fetched once in the background and ONLY the
   regions whose data-live-sig changed are swapped in place:
     • #cfMoaStatusCard        — MOA status, stepper, signing-schedule panel,
                                 revision panel (anything the company has
                                 typed there is kept)
     • .compliance-req-item    — each requirement card (pill, remark,
                                 placeholder, upload area) — a card the
                                 company has staged a new file on is left
                                 alone until they finish
     • #cfReqSummaryRegion     — the "N awaiting review / N verified" chips
     • #cfSidebarLockRegion    — the sidebar lock notice + links
     • #cfCompanyInfoRegion    — the read-only company info (flagged fields)
   A structural change that can't be patched in place (e.g. the
   administrator changes the company classification) reloads the page —
   immediately when the company isn't mid-edit, otherwise via a small
   "Refresh now" banner. Nothing here writes to the database.
   ═══════════════════════════════════════════════════════════════════════ */
(function () {
    'use strict';

    var stateEl = document.getElementById('cfLiveState');
    if (!stateEl || !window.fetch || !window.DOMParser) return;

    var state;
    try { state = JSON.parse(stateEl.textContent || '{}'); } catch (e) { return; }
    if (!state || !state.raw) return;

    var POLL_MS    = 6000;    // how often the fingerprint is checked
    var BACKOFF_MS = 30000;   // wait before retrying a refresh that had to be deferred
    var MAX_FAILS  = 5;       // give up quietly after this many failed requests in a row

    var lastRaw       = state.raw;
    var needsRefresh  = false;
    var inFlight      = false;
    var retryAt       = 0;
    var failures      = 0;
    var stopped       = false;
    var structPending = false;
    var bannerShown   = false;
    var toastTimer    = null;

    function sigOf(el) { return el ? (el.getAttribute('data-live-sig') || '') : ''; }

    function anyModalOpen() {
        var ids = ['profileModal', 'warnModal', 'redirectModal', 'profileSavedModal', 'submittedModal',
                   'verifiedModal', 'complianceErrorModal', 'reqDocPreviewModal', 'moaDocPreviewModal',
                   'imagePreviewModal', 'moaCreatePreviewModal'];
        for (var i = 0; i < ids.length; i++) {
            var el = document.getElementById(ids[i]);
            if (el && window.getComputedStyle(el).display !== 'none') return true;
        }
        return false;
    }

    function cardIsStaged(card) {
        if (!card) return false;
        if (card.classList.contains('req-staged')) return true;
        var inp = card.querySelector('.compliance-file-input');
        return !!(inp && inp.files && inp.files.length);
    }

    function userIsMidEdit() {
        if (document.querySelector('.compliance-req-item.req-staged')) return true;
        var ae = document.activeElement;
        return !!(ae && /^(INPUT|TEXTAREA|SELECT)$/.test(ae.tagName) &&
                  ae.type !== 'button' && ae.type !== 'submit' && ae.type !== 'checkbox' && ae.type !== 'radio');
    }

    function flash(el) {
        if (!el) return;
        el.classList.remove('cf-live-flash');
        void el.offsetWidth;
        el.classList.add('cf-live-flash');
        setTimeout(function () { el.classList.remove('cf-live-flash'); }, 1800);
    }

    function toast(msg) {
        var el = document.getElementById('cfLiveToast');
        if (!el) {
            el = document.createElement('div');
            el.id = 'cfLiveToast';
            el.className = 'cf-live-toast';
            el.setAttribute('role', 'status');
            document.body.appendChild(el);
        }
        el.innerHTML = '<i class="fas fa-rotate"></i><span></span>';
        el.lastChild.textContent = msg;
        void el.offsetWidth;
        el.classList.add('show');
        clearTimeout(toastTimer);
        toastTimer = setTimeout(function () { el.classList.remove('show'); }, 4500);
    }

    function showRefreshBanner() {
        if (bannerShown) return;
        bannerShown = true;
        var b = document.createElement('div');
        b.className = 'cf-live-banner';
        b.innerHTML = '<i class="fas fa-circle-info"></i><span>This page was updated by the administrator.</span>' +
                      '<button type="button">Refresh now</button>';
        b.querySelector('button').addEventListener('click', function () { window.location.reload(); });
        document.body.appendChild(b);
    }

    function handleStructural() {
        structPending = true;
        if (!userIsMidEdit() && !anyModalOpen()) { window.location.reload(); return; }
        showRefreshBanner();
    }

    // Same document-relative URLs are reused by the streaming endpoints, so add a cache-buster to any
    // preview image inside a region we are about to insert (done on the inert parsed copy, before import).
    function bustImages(root) {
        var stamp = Date.now();
        root.querySelectorAll('img[src*="stream_own_moa"], img[src*="stream_own_requirement"]').forEach(function (img) {
            var src = img.getAttribute('src') || '';
            img.setAttribute('src', src + (src.indexOf('?') === -1 ? '?' : '&') + '_lv=' + stamp);
        });
    }

    // Generic region swap (sidebar, summary chips, read-only company info).
    function swapRegion(doc, id) {
        var oldEl = document.getElementById(id);
        var newEl = doc.getElementById(id);
        if (!oldEl || !newEl) return false;
        if (sigOf(oldEl) === sigOf(newEl)) return false;
        oldEl.replaceWith(document.importNode(newEl, true));
        return true;
    }

    // MOA Document Status card — keeps anything the company has typed / opened inside it.
    function swapMoaCard(doc) {
        var oldEl = document.getElementById('cfMoaStatusCard');
        var newEl = doc.getElementById('cfMoaStatusCard');
        if (!oldEl || !newEl) return 'none';
        if (sigOf(oldEl) === sigOf(newEl)) return 'same';
        // The company's own response is being sent / just succeeded (the page reloads itself a moment
        // later) — don't swap the message away underneath them.
        if (oldEl.querySelector('.moa-revision-feedback.show')) return 'deferred';

        var typed = {};
        oldEl.querySelectorAll('input[id], textarea[id]').forEach(function (el) {
            if (el.value !== el.defaultValue) typed[el.id] = el.value;   // only what the company actually entered
        });
        var declineForm = oldEl.querySelector('#moaSchedDeclineForm');
        var declineOpen = !!(declineForm && declineForm.style.display === 'block');
        var ae = document.activeElement;
        var focusId = (ae && oldEl.contains(ae) && ae.id) ? ae.id : '';
        var selStart = null, selEnd = null;
        try { if (focusId) { selStart = ae.selectionStart; selEnd = ae.selectionEnd; } } catch (e) {}

        bustImages(newEl);
        var fresh = document.importNode(newEl, true);
        oldEl.replaceWith(fresh);

        Object.keys(typed).forEach(function (id) {
            var el = fresh.querySelector('#' + id);
            if (el && !el.disabled) el.value = typed[id];
        });
        if (declineOpen && fresh.querySelector('#moaSchedDeclineForm') && typeof showScheduleDeclineForm === 'function') {
            showScheduleDeclineForm();
        }
        if (focusId) {
            var f = fresh.querySelector('#' + focusId);
            if (f) { f.focus(); try { if (selStart !== null) f.setSelectionRange(selStart, selEnd); } catch (e) {} }
        }
        flash(fresh);
        return 'swapped';
    }

    // Re-attach the page's existing behaviours to a freshly inserted requirement card.
    function rewireCard(card, key) {
        try {
            var slot = card.querySelector('.req-preview-slot');
            if (slot && typeof reqPreviewSlotOriginals !== 'undefined') reqPreviewSlotOriginals[key] = slot.innerHTML;
            if (typeof bindReqPreviewTriggers === 'function') bindReqPreviewTriggers(card);
            if (typeof probeReqStackLayers === 'function') probeReqStackLayers(card);
            if (typeof bindComplianceFileInput === 'function') {
                card.querySelectorAll('.compliance-file-input').forEach(bindComplianceFileInput);
            }
            if (typeof applyReqStatusUI === 'function') applyReqStatusUI(card);
        } catch (e) { /* the card is still correct server-rendered markup */ }
    }

    /* ADJUSTMENT: REQUIREMENT-STATUS POPUP — same design and wording as AccomForm.php's status popup. When the live refresh
       swaps in a requirement card whose status the administrator changed, a popup explains it. Several changes in one
       refresh are queued and shown one after another. Every lookup is guarded so a missing element can never break the
       live updates. */
    var statusQueue = [];
    var statusShowing = false;

    function cardLabel(card) {
        try {
            var info = card.querySelector('.req-info');
            if (info) {
                var c = info.cloneNode(true);
                var tag = c.querySelector('.req-moa-tag');
                if (tag) tag.remove();
                var t = (c.textContent || '').replace(/\s+/g, ' ').trim();
                if (t) return t;
            }
        } catch (e) {}
        return card.getAttribute('data-req-key') || 'requirement';
    }

    function statusPopupContent(label, newStatus, remark) {
        if (newStatus === 'verified') {
            return { title: 'Great Job!',
                     msg: 'Your "' + label + '" has been verified by the administrator. One step closer to completing your requirements!',
                     btn: 'Great, Thanks!' };
        }
        if (newStatus === 'denied') {
            return { title: "Let's Fix This Together",
                     msg: 'Your "' + label + '" needs a quick update before it can be verified. '
                        + (remark ? 'Administrator\'s note: "' + remark + '". ' : 'Please check the remark on the card. ')
                        + 'Re-upload the corrected file and you\'ll be back on track!',
                     btn: 'Got It, I\'ll Re-upload' };
        }
        return { title: 'Under Review',
                 msg: 'Your "' + label + '" is now back in the review queue. The administrator will check it soon — no action is needed from you right now.',
                 btn: 'OK, Got It' };
    }

    function showNextStatusPopup() {
        var modal = document.getElementById('cfStatusChangedModal');
        if (!modal) { statusQueue = []; statusShowing = false; return; }
        var item = statusQueue.shift();
        if (!item) { statusShowing = false; modal.style.display = 'none'; return; }
        statusShowing = true;
        var c = statusPopupContent(item.label, item.status, item.remark);
        var t = document.getElementById('cfStatusChangedTitle');
        var m = document.getElementById('cfStatusChangedMsg');
        var b = document.getElementById('closeCfStatusChanged');
        if (t) t.textContent = c.title;
        if (m) m.textContent = c.msg;
        if (b) b.textContent = c.btn;
        modal.style.display = 'flex';
    }

    (function bindStatusPopupClose() {
        var modal = document.getElementById('cfStatusChangedModal');
        var btn   = document.getElementById('closeCfStatusChanged');
        if (btn) btn.addEventListener('click', showNextStatusPopup);
        if (modal) modal.addEventListener('click', function (e) { if (e.target === modal) showNextStatusPopup(); });
        document.addEventListener('keydown', function (e) {
            if (e.key === 'Escape' && modal && window.getComputedStyle(modal).display !== 'none') showNextStatusPopup();
        });
    })();

    // Only changes that matter to the company are announced: verified, rejected, or sent back to review by the administrator.
    // (Nothing-on-file -> Pending is the company's own upload from another tab/device, so it is not announced.)
    function queueStatusChange(oldCard, newCard) {
        try {
            var oldSt = oldCard.getAttribute('data-req-status') || 'not-submitted';
            var newSt = newCard.getAttribute('data-req-status') || 'not-submitted';
            if (oldSt === newSt) return;
            var announce = (newSt === 'verified') || (newSt === 'denied') ||
                           (newSt === 'pending' && (oldSt === 'verified' || oldSt === 'denied'));
            if (!announce) return;
            var remarkEl = newCard.querySelector('.req-card-remark-text');
            var remark = remarkEl ? (remarkEl.textContent || '').trim() : '';
            if (remark === '—') remark = '';
            statusQueue.push({ label: cardLabel(newCard), status: newSt, remark: remark });
        } catch (e) { /* never block the live refresh */ }
    }

    function flushStatusPopups() {
        if (statusQueue.length && !statusShowing) showNextStatusPopup();
    }

    function showVerifiedPopup() {
        var vm = document.getElementById('verifiedModal');
        if (!vm || anyModalOpen()) return;
        vm.style.display = 'flex';
        var hide = function () { vm.style.display = 'none'; };
        var btn = document.getElementById('closeVerifiedModal');
        if (btn && !btn.getAttribute('data-live-bound')) {
            btn.setAttribute('data-live-bound', '1');
            btn.addEventListener('click', hide);
        }
        setTimeout(hide, 5000);
    }

    function applyFresh(doc, fresh) {
        // A change that alters the page's structure can't be patched in place.
        if (fresh.struct !== state.struct) { needsRefresh = false; handleStructural(); return; }

        var deferred = false;
        var did = { moa: false, req: false };

        swapRegion(doc, 'cfSidebarLockRegion');
        swapRegion(doc, 'cfCompanyInfoRegion');

        var m = swapMoaCard(doc);
        if (m === 'swapped') did.moa = true;
        else if (m === 'deferred') deferred = true;

        var becameAllVerified = !!(fresh.all_verified && !state.all_verified); // the "All Requirements Verified!" popup covers that moment
        document.querySelectorAll('.compliance-req-item[data-req-key]').forEach(function (oldCard) {
            var key = oldCard.getAttribute('data-req-key');
            var newCard = doc.querySelector('.compliance-req-item[data-req-key="' + key + '"]');
            if (!newCard || sigOf(oldCard) === sigOf(newCard)) return;
            // The company has picked a new file for this requirement but not submitted it yet — leave
            // their selection alone; it is refreshed on a later pass once they are done.
            if (cardIsStaged(oldCard)) { deferred = true; return; }
            bustImages(newCard);
            if (!becameAllVerified) queueStatusChange(oldCard, newCard);
            var inserted = document.importNode(newCard, true);
            oldCard.replaceWith(inserted);
            rewireCard(inserted, key);
            flash(inserted);
            did.req = true;
        });

        swapRegion(doc, 'cfReqSummaryRegion');

        if (becameAllVerified) { // a status popup still open from earlier gives way to the "All Requirements Verified!" popup
            statusQueue = []; statusShowing = false;
            var sm = document.getElementById('cfStatusChangedModal');
            if (sm) sm.style.display = 'none';
        }
        flushStatusPopups();
        if (fresh.all_verified && !state.all_verified) showVerifiedPopup();
        state.all_verified = !!fresh.all_verified;

        if (did.moa && did.req) toast('Your MOA and requirements were just updated.');
        else if (did.moa)       toast('Your MOA status was just updated.');
        else if (did.req)       toast('Your requirement status was just updated.');

        needsRefresh = deferred;
        retryAt = deferred ? (Date.now() + BACKOFF_MS) : 0;   // back off only while something is still deferred
    }

    function refresh() {
        if (inFlight || stopped) return;
        if (typeof globalLoadingActiveCount !== 'undefined' && globalLoadingActiveCount > 0) return; // an action is in progress
        if (Date.now() < retryAt) return;

        inFlight = true;
        fetch('CompanyForm.php', { credentials: 'same-origin', cache: 'no-store' })
            .then(function (r) { if (!r.ok) throw new Error('http ' + r.status); return r.text(); })
            .then(function (html) {
                var doc = new DOMParser().parseFromString(html, 'text/html');
                var stEl = doc.getElementById('cfLiveState');
                if (!stEl) throw new Error('no state (session expired?)');
                applyFresh(doc, JSON.parse(stEl.textContent || '{}'));
                failures = 0;
            })
            .catch(function () {
                failures++;
                if (failures >= MAX_FAILS) stopped = true;
            })
            .then(function () { inFlight = false; });
    }

    function tick() {
        if (stopped || document.hidden) return;
        if (structPending) {
            if (!userIsMidEdit() && !anyModalOpen()) window.location.reload();
            return;
        }
        if (inFlight) return;
        if (needsRefresh) { refresh(); return; }

        inFlight = true;
        fetch('CompanyForm.php?cf_live_poll=1&_=' + Date.now(), {
            credentials: 'same-origin',
            cache: 'no-store',
            headers: { 'Accept': 'application/json' }
        })
            .then(function (r) {
                var ct = r.headers.get('content-type') || '';
                if (!r.ok || ct.indexOf('application/json') === -1) throw new Error('bad poll response');
                return r.json();
            })
            .then(function (d) {
                inFlight = false;
                failures = 0;
                if (d && d.ok && d.raw && d.raw !== lastRaw) {
                    lastRaw = d.raw;
                    needsRefresh = true;
                    refresh();
                }
            })
            .catch(function () {
                inFlight = false;
                failures++;
                if (failures >= MAX_FAILS) stopped = true;
            });
    }

    setInterval(tick, POLL_MS);
    document.addEventListener('visibilitychange', function () { if (!document.hidden) tick(); });
    window.addEventListener('online', tick);
})();
</script>

</body>
</html>